<?php

declare(strict_types=1);

namespace App\Withdrawing\Service;

use App\Withdrawing\Entity\Withdrawal;
use App\Withdrawing\Enum\WithdrawalStatus;
use App\Withdrawing\RepositoryInterface\WithdrawalRepositoryInterface;
use App\Withdrawing\ServiceInterface\WithdrawalRailServiceInterface;
use App\Withdrawing\ServiceInterface\WithdrawalSourceServiceInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

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
        return $this->repository->transactional(function () use ($sourceType, $sourceId, $actorType, $actorId, $destinationReference, $amountMinor, $currency, $idempotencyKey): Withdrawal {
            $normalizedCurrency = strtoupper(trim($currency));
            $normalizedKey = trim($idempotencyKey);
            $existing = $this->repository->findByIdempotencyKey($normalizedKey);
            if ($existing instanceof Withdrawal) {
                $this->assertReplayMatches($existing, $sourceType, $sourceId, $actorType, $actorId, $destinationReference, $amountMinor, $normalizedCurrency);

                return $existing;
            }

            $withdrawal = new Withdrawal($sourceType, $sourceId, $actorType, $actorId, $destinationReference, $amountMinor, $normalizedCurrency, $normalizedKey);
            $source = $this->sourceFor($withdrawal->sourceType());
            $sourceReference = $source->reserve(
                $withdrawal->sourceId(),
                $withdrawal->amountMinor(),
                $withdrawal->currency(),
                $this->key($withdrawal, 'source-reserve'),
            );
            try {
                $withdrawal->reserve($sourceReference);
            } catch (\InvalidArgumentException $exception) {
                $source->release(
                    $withdrawal->sourceId(),
                    $sourceReference,
                    $this->key($withdrawal, 'source-release'),
                );

                throw $exception;
            }
            $this->repository->add($withdrawal);
            $this->repository->flush();

            return $withdrawal;
        });
    }

    public function begin(Withdrawal $withdrawal): void
    {
        if (WithdrawalStatus::Processing === $withdrawal->status()) {
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
        } catch (\InvalidArgumentException $exception) {
            $rail->compensateFailure(
                $railReference,
                $this->key($withdrawal, 'rail-failure-compensation'),
            );

            throw $exception;
        }
        $this->repository->flush();
    }

    public function succeed(Withdrawal $withdrawal): void
    {
        if (WithdrawalStatus::Succeeded === $withdrawal->status()) {
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

    public function fail(Withdrawal $withdrawal): void
    {
        if (WithdrawalStatus::Failed === $withdrawal->status()) {
            return;
        }
        if (WithdrawalStatus::Processing === $withdrawal->status()) {
            $railReference = $withdrawal->railReference() ?? throw new \LogicException('Processing withdrawal is missing its rail reference.');
            $this->railFor($withdrawal->destinationReference())->compensateFailure(
                $railReference,
                $this->key($withdrawal, 'rail-failure-compensation'),
            );
        }
        if (in_array($withdrawal->status(), [WithdrawalStatus::Reserved, WithdrawalStatus::Processing], true)) {
            $this->sourceFor($withdrawal->sourceType())->release(
                $withdrawal->sourceId(),
                $this->requiredSourceReference($withdrawal),
                $this->key($withdrawal, 'source-release'),
            );
        }
        $withdrawal->fail();
        $this->repository->flush();
    }

    public function cancel(Withdrawal $withdrawal): void
    {
        if (WithdrawalStatus::Cancelled === $withdrawal->status()) {
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

    public function reverse(Withdrawal $withdrawal): void
    {
        if (WithdrawalStatus::Reversed === $withdrawal->status()) {
            return;
        }
        if (WithdrawalStatus::Succeeded !== $withdrawal->status()) {
            throw new \LogicException('Only succeeded withdrawal can be reversed.');
        }
        $railReference = $withdrawal->railReference();
        if (null === $railReference) {
            throw new \LogicException('Succeeded withdrawal is missing its rail reference.');
        }

        $this->railFor($withdrawal->destinationReference())->reverse($railReference, $this->key($withdrawal, 'rail-reverse'));
        $this->sourceFor($withdrawal->sourceType())->reverse(
            $withdrawal->sourceId(),
            $this->requiredSourceReference($withdrawal),
            $this->key($withdrawal, 'source-reverse'),
        );
        $withdrawal->reverse();
        $this->repository->flush();
    }

    private function sourceFor(string $sourceType): WithdrawalSourceServiceInterface
    {
        foreach ($this->sources as $source) {
            if ($source->supports($sourceType)) {
                return $source;
            }
        }
        throw new \DomainException(sprintf('No withdrawal source supports %s.', $sourceType));
    }

    private function railFor(string $destinationReference): WithdrawalRailServiceInterface
    {
        foreach ($this->rails as $rail) {
            if ($rail->supports($destinationReference)) {
                return $rail;
            }
        }
        throw new \DomainException('No withdrawal rail supports the selected destination.');
    }

    private function requiredSourceReference(Withdrawal $withdrawal): string
    {
        return $withdrawal->sourceReference() ?? throw new \LogicException('Withdrawal source reference is missing.');
    }

    private function key(Withdrawal $withdrawal, string $operation): string
    {
        return $withdrawal->idempotencyKey().':'.$operation;
    }

    private function assertReplayMatches(Withdrawal $withdrawal, string $sourceType, string $sourceId, string $actorType, string $actorId, string $destinationReference, int $amountMinor, string $currency): void
    {
        if ($withdrawal->sourceType() !== trim($sourceType) || $withdrawal->sourceId() !== trim($sourceId) || $withdrawal->actorType() !== trim($actorType) || $withdrawal->actorId() !== trim($actorId) || $withdrawal->destinationReference() !== trim($destinationReference) || $withdrawal->amountMinor() !== $amountMinor || $withdrawal->currency() !== $currency) {
            throw new \DomainException('Withdrawal idempotency key is already bound to different request content.');
        }
    }
}
