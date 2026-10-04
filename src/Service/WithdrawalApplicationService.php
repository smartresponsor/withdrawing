<?php

declare(strict_types=1);

namespace App\Withdrawing\Service;

use App\Withdrawing\Entity\Withdrawal;
use App\Withdrawing\Enum\WithdrawalStatus;
use App\Withdrawing\RepositoryInterface\WithdrawalRepositoryInterface;
use App\Withdrawing\ServiceInterface\WithdrawalRailServiceInterface;
use App\Withdrawing\ServiceInterface\WithdrawalSourceServiceInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Coordinates withdrawal lifecycle transitions across source reservations and external rails.
 */
final readonly class WithdrawalApplicationService
{
    /**
     * @param iterable<WithdrawalSourceServiceInterface> $sources
     * @param iterable<WithdrawalRailServiceInterface>   $rails
     */
    public function __construct(
        private WithdrawalRepositoryInterface $repository,
        #[AutowireIterator('app.withdrawing.source')]
        private iterable $sources,
        #[AutowireIterator('app.withdrawing.rail')]
        private iterable $rails,
    ) {
    }

    /**
     * Create or replay a withdrawal request and reserve its source value atomically.
     */
    public function request(
        string $sourceType,
        string $sourceId,
        string $actorType,
        string $actorId,
        string $destinationReference,
        int $amountMinor,
        string $currency,
        string $idempotencyKey,
    ): Withdrawal {
        $normalizedCurrency = strtoupper(trim($currency));
        $normalizedKey = trim($idempotencyKey);
        $withdrawal = new Withdrawal($sourceType, $sourceId, $actorType, $actorId, $destinationReference, $amountMinor, $normalizedCurrency, $normalizedKey);

        $reservedSource = null;
        $reservedSourceId = null;
        $sourceReference = null;
        $sourceReleaseKey = null;

        try {
            return $this->repository->transactional(function () use ($withdrawal, $normalizedKey, &$reservedSource, &$reservedSourceId, &$sourceReference, &$sourceReleaseKey): Withdrawal {
                $this->repository->lockIdempotencyKey($normalizedKey);
                $existing = $this->repository->findByIdempotencyKey($normalizedKey);
                if ($existing instanceof Withdrawal) {
                    $this->assertReplayMatches($existing, $withdrawal);

                    return $existing;
                }
                $reservedSource = $this->sourceFor($withdrawal->sourceType());
                $reservedSourceId = $withdrawal->sourceId();
                $sourceReleaseKey = $this->key($withdrawal, 'source-release');
                $sourceReference = $reservedSource->reserve(
                    $withdrawal->sourceId(),
                    $withdrawal->amountMinor(),
                    $withdrawal->currency(),
                    $this->key($withdrawal, 'source-reserve'),
                );
                $withdrawal->reserve($sourceReference);
                $this->repository->add($withdrawal);
                $this->repository->flush();

                return $withdrawal;
            });
        } catch (\Throwable $exception) {
            if ($reservedSource instanceof WithdrawalSourceServiceInterface && null !== $reservedSourceId && null !== $sourceReference && null !== $sourceReleaseKey) {
                try {
                    $reservedSource->release($reservedSourceId, $sourceReference, $sourceReleaseKey);
                } catch (\Throwable $compensationException) {
                    throw new \RuntimeException(
                        sprintf('Withdrawal source compensation failed after local request failure: %s', $compensationException->getMessage()),
                        0,
                        $exception,
                    );
                }
            }

            throw $exception;
        }
    }

    /**
     * Submit a reserved withdrawal to the matching payment rail exactly once.
     */
    public function begin(Withdrawal $withdrawal): void
    {
        if (WithdrawalStatus::Processing === $withdrawal->status()) {
            $this->repository->flush();
            return;
        }
        if (WithdrawalStatus::Reserved !== $withdrawal->status()) {
            throw new \LogicException('Only reserved withdrawal can begin rail processing.');
        }

        $rail = $this->railFor($withdrawal->destinationReference());
        $railReference = $rail->submit(
            $withdrawal->destinationReference(),
            $withdrawal->amountMinor(),
            $withdrawal->currency(),
            $this->key($withdrawal, 'rail-submit'),
        );
        try {
            $withdrawal->start($railReference);
            $this->repository->flush();
        } catch (\Throwable $exception) {
            try {
                $rail->compensateFailure(
                    $railReference,
                    $this->key($withdrawal, 'rail-failure-compensation'),
                );
            } catch (\Throwable $compensationException) {
                if (WithdrawalStatus::Processing === $withdrawal->status()) {
                    try {
                        $this->repository->flush();
                    } catch (\Throwable $recoveryPersistenceException) {
                        throw new \RuntimeException(
                            sprintf(
                                'Withdrawal rail compensation failed after local begin failure: %s; processing correlation recovery persistence also failed: %s',
                                $compensationException->getMessage(),
                                $recoveryPersistenceException->getMessage(),
                            ),
                            0,
                            $exception,
                        );
                    }
                }

                throw new \RuntimeException(
                    sprintf('Withdrawal rail compensation failed after local begin failure: %s', $compensationException->getMessage()),
                    0,
                    $exception,
                );
            }

            if (WithdrawalStatus::Processing === $withdrawal->status()) {
                $withdrawal->restoreReservedAfterStartFailure();
            }

            throw $exception;
        }
    }

    /**
     * Finalize the reserved source value after confirmed rail settlement.
     */
    public function succeed(Withdrawal $withdrawal): void
    {
        if (WithdrawalStatus::Succeeded === $withdrawal->status()) {
            $this->repository->flush();
            return;
        }
        if (WithdrawalStatus::Processing !== $withdrawal->status()) {
            throw new \LogicException('Only processing withdrawal can succeed.');
        }

        $this->sourceFor($withdrawal->sourceType())->finalize(
            $withdrawal->sourceId(),
            $this->requiredSourceReference($withdrawal),
            $this->key($withdrawal, 'source-finalize'),
        );
        $withdrawal->succeed();
        $this->repository->flush();
    }

    /**
     * Fail the withdrawal and compensate rail or source reservations when required.
     */
    public function fail(Withdrawal $withdrawal): void
    {
        if (WithdrawalStatus::Failed === $withdrawal->status()) {
            $this->repository->flush();
            return;
        }
        $sourceReference = null;
        if (in_array($withdrawal->status(), [WithdrawalStatus::Reserved, WithdrawalStatus::Processing], true)) {
            $sourceReference = $this->requiredSourceReference($withdrawal);
        }
        if (WithdrawalStatus::Processing === $withdrawal->status()) {
            $railReference = $withdrawal->railReference() ?? throw new \LogicException('Processing withdrawal is missing its rail reference.');
            $this->railFor($withdrawal->destinationReference())->compensateFailure(
                $railReference,
                $this->key($withdrawal, 'rail-failure-compensation'),
            );
        }
        if (null !== $sourceReference) {
            $this->sourceFor($withdrawal->sourceType())->release(
                $withdrawal->sourceId(),
                $sourceReference,
                $this->key($withdrawal, 'source-release'),
            );
        }
        $withdrawal->fail();
        $this->repository->flush();
    }

    /**
     * Cancel a pre-processing withdrawal and release any existing source reservation.
     */
    public function cancel(Withdrawal $withdrawal): void
    {
        if (WithdrawalStatus::Cancelled === $withdrawal->status()) {
            $this->repository->flush();
            return;
        }
        if (WithdrawalStatus::Reserved === $withdrawal->status()) {
            $this->sourceFor($withdrawal->sourceType())->release(
                $withdrawal->sourceId(),
                $this->requiredSourceReference($withdrawal),
                $this->key($withdrawal, 'source-release'),
            );
        }
        $withdrawal->cancel();
        $this->repository->flush();
    }

    /**
     * Reverse a succeeded withdrawal across both rail and source boundaries.
     */
    public function reverse(Withdrawal $withdrawal): void
    {
        if (WithdrawalStatus::Reversed === $withdrawal->status()) {
            $this->repository->flush();
            return;
        }
        if (WithdrawalStatus::Succeeded !== $withdrawal->status()) {
            throw new \LogicException('Only succeeded withdrawal can be reversed.');
        }
        $railReference = $withdrawal->railReference();
        if (null === $railReference) {
            throw new \LogicException('Succeeded withdrawal is missing its rail reference.');
        }
        $sourceReference = $this->requiredSourceReference($withdrawal);

        $this->railFor($withdrawal->destinationReference())->reverse($railReference, $this->key($withdrawal, 'rail-reverse'));
        $this->sourceFor($withdrawal->sourceType())->reverse(
            $withdrawal->sourceId(),
            $sourceReference,
            $this->key($withdrawal, 'source-reverse'),
        );
        $withdrawal->reverse();
        $this->repository->flush();
    }

    private function sourceFor(string $sourceType): WithdrawalSourceServiceInterface
    {
        $match = null;
        foreach ($this->sources as $source) {
            if ($source->supports($sourceType)) {
                if ($match instanceof WithdrawalSourceServiceInterface) {
                    throw new \DomainException(sprintf('Multiple withdrawal sources support %s.', $sourceType));
                }
                $match = $source;
            }
        }
        if (!$match instanceof WithdrawalSourceServiceInterface) {
            throw new \DomainException(sprintf('No withdrawal source supports %s.', $sourceType));
        }

        return $match;
    }

    private function railFor(string $destinationReference): WithdrawalRailServiceInterface
    {
        $match = null;
        foreach ($this->rails as $rail) {
            if ($rail->supports($destinationReference)) {
                if ($match instanceof WithdrawalRailServiceInterface) {
                    throw new \DomainException('Multiple withdrawal rails support the selected destination.');
                }
                $match = $rail;
            }
        }
        if (!$match instanceof WithdrawalRailServiceInterface) {
            throw new \DomainException('No withdrawal rail supports the selected destination.');
        }

        return $match;
    }

    private function requiredSourceReference(Withdrawal $withdrawal): string
    {
        return $withdrawal->sourceReference() ?? throw new \LogicException('Withdrawal source reference is missing.');
    }

    private function key(Withdrawal $withdrawal, string $operation): string
    {
        return $withdrawal->idempotencyKey().':'.$operation;
    }

    private function assertReplayMatches(Withdrawal $withdrawal, Withdrawal $candidate): void
    {
        if ($withdrawal->sourceType() !== $candidate->sourceType() || $withdrawal->sourceId() !== $candidate->sourceId() || $withdrawal->actorType() !== $candidate->actorType() || $withdrawal->actorId() !== $candidate->actorId() || $withdrawal->destinationReference() !== $candidate->destinationReference() || $withdrawal->amountMinor() !== $candidate->amountMinor() || $withdrawal->currency() !== $candidate->currency()) {
            throw new \DomainException('Withdrawal idempotency key is already bound to different request content.');
        }
    }
}
