<?php

declare(strict_types=1);

namespace App\Withdrawing\Tests\Service;

use App\Withdrawing\Entity\Withdrawal;
use App\Withdrawing\Enum\WithdrawalStatus;
use App\Withdrawing\RepositoryInterface\WithdrawalRepositoryInterface;
use App\Withdrawing\Service\WithdrawalApplicationService;
use App\Withdrawing\ServiceInterface\WithdrawalRailServiceInterface;
use App\Withdrawing\ServiceInterface\WithdrawalSourceServiceInterface;
use PHPUnit\Framework\TestCase;

final class WithdrawalApplicationServiceTest extends TestCase
{
    public function testLifecycleCrossesSourceAndRailBoundaries(): void
    {
        $source = new class () implements WithdrawalSourceServiceInterface {
            /** @var list<string> */
            public array $calls = [];
            public function supports(string $sourceType): bool
            {
                return 'wallet' === $sourceType;
            }
            public function reserve(string $sourceId, int $amountMinor, string $currency, string $idempotencyKey): string
            {
                $this->calls[] = 'reserve:'.$idempotencyKey;
                return 'reservation-1';
            }
            public function release(string $sourceId, string $reservationReference, string $idempotencyKey): void
            {
                $this->calls[] = 'release:'.$idempotencyKey;
            }
            public function finalize(string $sourceId, string $reservationReference, string $idempotencyKey): void
            {
                $this->calls[] = 'finalize:'.$idempotencyKey;
            }
            public function reverse(string $sourceId, string $reservationReference, string $idempotencyKey): void
            {
                $this->calls[] = 'reverse:'.$idempotencyKey;
            }
        };
        $rail = new class () implements WithdrawalRailServiceInterface {
            /** @var list<string> */
            public array $calls = [];
            public function supports(string $destinationReference): bool
            {
                return str_starts_with($destinationReference, 'paying:');
            }
            public function submit(string $destinationReference, int $amountMinor, string $currency, string $idempotencyKey): string
            {
                $this->calls[] = 'submit:'.$idempotencyKey;
                return 'rail-1';
            }
            public function compensateFailure(string $railReference, string $idempotencyKey): void
            {
                $this->calls[] = 'compensate-failure:'.$idempotencyKey;
            }
            public function reverse(string $railReference, string $idempotencyKey): void
            {
                $this->calls[] = 'reverse:'.$idempotencyKey;
            }
        };

        $repository = $this->repository();
        $service = new WithdrawalApplicationService($repository, [$source], [$rail]);

        $withdrawal = $service->request('wallet', 'wallet-1', 'vendor', 'vendor-1', 'paying:destination-1', 2500, 'usd', 'request-1');
        self::assertSame(WithdrawalStatus::Reserved, $withdrawal->status());
        self::assertSame('reservation-1', $withdrawal->sourceReference());
        self::assertSame(['reserve:request-1:source-reserve'], $source->calls);

        $service->begin($withdrawal);
        self::assertSame(WithdrawalStatus::Processing, $withdrawal->status());
        self::assertSame(['submit:request-1:rail-submit'], $rail->calls);

        $service->begin($withdrawal);
        self::assertSame(['submit:request-1:rail-submit'], $rail->calls);

        $service->succeed($withdrawal);
        self::assertSame(WithdrawalStatus::Succeeded, $withdrawal->status());
        self::assertSame([
            'reserve:request-1:source-reserve',
            'finalize:request-1:source-finalize',
        ], $source->calls);

        $service->succeed($withdrawal);
        self::assertCount(2, $source->calls);
    }

    public function testProcessingFailureCompensatesRailAndReleasesSource(): void
    {
        $source = $this->createMock(WithdrawalSourceServiceInterface::class);
        $source->method('supports')->willReturn(true);
        $source->expects(self::once())->method('release')->with('wallet-1', 'reservation-1', 'request-2:source-release');
        $rail = $this->createMock(WithdrawalRailServiceInterface::class);
        $rail->method('supports')->willReturn(true);
        $rail->expects(self::once())->method('compensateFailure')->with('rail-1', 'request-2:rail-failure-compensation');
        $withdrawal = $this->withdrawal('request-2');
        $withdrawal->reserve('reservation-1');
        $withdrawal->start('rail-1');
        $service = $this->service($source, $rail);

        $service->fail($withdrawal);
        $service->fail($withdrawal);

        self::assertSame(WithdrawalStatus::Failed, $withdrawal->status());
    }

    public function testReservedCancellationReleasesSourceAndIsIdempotent(): void
    {
        $source = $this->createMock(WithdrawalSourceServiceInterface::class);
        $source->method('supports')->willReturn(true);
        $source->expects(self::once())->method('release')->with('wallet-1', 'reservation-1', 'request-3:source-release');
        $rail = $this->createStub(WithdrawalRailServiceInterface::class);
        $withdrawal = $this->withdrawal('request-3');
        $withdrawal->reserve('reservation-1');
        $service = $this->service($source, $rail);

        $service->cancel($withdrawal);
        $service->cancel($withdrawal);

        self::assertSame(WithdrawalStatus::Cancelled, $withdrawal->status());
    }

    public function testSucceededWithdrawalCanBeReversedExactlyOnce(): void
    {
        $source = $this->createMock(WithdrawalSourceServiceInterface::class);
        $source->method('supports')->willReturn(true);
        $source->expects(self::once())->method('reverse')->with('wallet-1', 'reservation-1', 'request-4:source-reverse');
        $rail = $this->createMock(WithdrawalRailServiceInterface::class);
        $rail->method('supports')->willReturn(true);
        $rail->expects(self::once())->method('reverse')->with('rail-1', 'request-4:rail-reverse');
        $withdrawal = $this->withdrawal('request-4');
        $withdrawal->reserve('reservation-1');
        $withdrawal->start('rail-1');
        $withdrawal->succeed();
        $service = $this->service($source, $rail);

        $service->reverse($withdrawal);
        $service->reverse($withdrawal);

        self::assertSame(WithdrawalStatus::Reversed, $withdrawal->status());
    }

    public function testMatchingReplayReturnsExistingWithdrawalWithoutNewReservation(): void
    {
        $existing = $this->withdrawal('request-5');
        $source = $this->createMock(WithdrawalSourceServiceInterface::class);
        $source->expects(self::never())->method('reserve');
        $service = $this->service($source, $this->createStub(WithdrawalRailServiceInterface::class), $existing);

        $actual = $service->request('wallet', 'wallet-1', 'vendor', 'vendor-1', 'paying:destination-1', 2500, 'usd', 'request-5');

        self::assertSame($existing, $actual);
    }

    public function testRequestLocksIdempotencyKeyBeforeCheckingForReplayOrReservingSource(): void
    {
        $calls = [];
        $repository = $this->createStub(WithdrawalRepositoryInterface::class);
        $repository->method('transactional')->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $repository->method('lockIdempotencyKey')->willReturnCallback(static function (string $idempotencyKey) use (&$calls): void {
            $calls[] = 'lock:'.$idempotencyKey;
        });
        $repository->method('findByIdempotencyKey')->willReturnCallback(static function (string $idempotencyKey) use (&$calls): ?Withdrawal {
            $calls[] = 'find:'.$idempotencyKey;

            return null;
        });
        $source = $this->createStub(WithdrawalSourceServiceInterface::class);
        $source->method('supports')->willReturn(true);
        $source->method('reserve')->willReturnCallback(static function (string $sourceId, int $amountMinor, string $currency, string $idempotencyKey) use (&$calls): string {
            $calls[] = 'reserve:'.$idempotencyKey;

            return 'reservation-serialized';
        });
        $service = new WithdrawalApplicationService(
            $repository,
            [$source],
            [$this->createStub(WithdrawalRailServiceInterface::class)],
        );

        $withdrawal = $service->request(
            'wallet',
            'wallet-1',
            'vendor',
            'vendor-1',
            'paying:destination-1',
            2500,
            'USD',
            'request-serialized',
        );

        self::assertSame(WithdrawalStatus::Reserved, $withdrawal->status());
        self::assertSame([
            'lock:request-serialized',
            'find:request-serialized',
            'reserve:request-serialized:source-reserve',
        ], $calls);
    }

    public function testMismatchedReplayIsRejected(): void
    {
        $existing = $this->withdrawal('request-6');
        $service = $this->service(
            $this->createStub(WithdrawalSourceServiceInterface::class),
            $this->createStub(WithdrawalRailServiceInterface::class),
            $existing,
        );

        $this->expectException(\DomainException::class);
        $service->request('wallet', 'wallet-1', 'vendor', 'vendor-1', 'paying:destination-1', 2600, 'USD', 'request-6');
    }

    public function testUnsupportedSourceAndRailFailClosed(): void
    {
        $source = $this->createStub(WithdrawalSourceServiceInterface::class);
        $source->method('supports')->willReturn(false);
        $service = $this->service($source, $this->createStub(WithdrawalRailServiceInterface::class));

        try {
            $service->request('wallet', 'wallet-1', 'vendor', 'vendor-1', 'paying:destination-1', 2500, 'USD', 'request-7');
            self::fail('Unsupported withdrawal source must fail closed.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('source', $exception->getMessage());
        }

        $rail = $this->createStub(WithdrawalRailServiceInterface::class);
        $rail->method('supports')->willReturn(false);
        $withdrawal = $this->withdrawal('request-8');
        $withdrawal->reserve('reservation-1');

        $this->expectException(\DomainException::class);
        $this->service($this->createStub(WithdrawalSourceServiceInterface::class), $rail)->begin($withdrawal);
    }

    public function testInvalidReservedReferenceIsReleasedBeforeRequestFailureEscapes(): void
    {
        $source = $this->createMock(WithdrawalSourceServiceInterface::class);
        $source->method('supports')->willReturn(true);
        $invalidReference = str_repeat('r', 192);
        $source->expects(self::once())
            ->method('reserve')
            ->willReturn($invalidReference);
        $source->expects(self::once())
            ->method('release')
            ->with('wallet-1', $invalidReference, 'request-invalid-source-reference:source-release');

        $service = $this->service($source, $this->createStub(WithdrawalRailServiceInterface::class));

        $this->expectException(\InvalidArgumentException::class);
        $service->request(
            'wallet',
            'wallet-1',
            'vendor',
            'vendor-1',
            'paying:destination-1',
            2500,
            'USD',
            'request-invalid-source-reference',
        );
    }

    public function testPersistenceFailureReleasesReservedSourceBeforeRequestFailureEscapes(): void
    {
        $source = $this->createMock(WithdrawalSourceServiceInterface::class);
        $source->method('supports')->willReturn(true);
        $source->expects(self::once())
            ->method('reserve')
            ->willReturn('reservation-persistence-failure');
        $source->expects(self::once())
            ->method('release')
            ->with('wallet-1', 'reservation-persistence-failure', 'request-persistence-failure:source-release');

        $repository = $this->createStub(WithdrawalRepositoryInterface::class);
        $repository->method('transactional')->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $repository->method('findByIdempotencyKey')->willReturn(null);
        $repository->method('flush')->willThrowException(new \RuntimeException('Persistence failed.'));
        $service = new WithdrawalApplicationService(
            $repository,
            [$source],
            [$this->createStub(WithdrawalRailServiceInterface::class)],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Persistence failed.');
        $service->request(
            'wallet',
            'wallet-1',
            'vendor',
            'vendor-1',
            'paying:destination-1',
            2500,
            'USD',
            'request-persistence-failure',
        );
    }

    public function testTransactionCommitFailureReleasesReservedSourceBeforeRequestFailureEscapes(): void
    {
        $source = $this->createMock(WithdrawalSourceServiceInterface::class);
        $source->method('supports')->willReturn(true);
        $source->expects(self::once())
            ->method('reserve')
            ->with('wallet-1', 2500, 'USD', 'request-commit-failure:source-reserve')
            ->willReturn('reservation-commit-failure');
        $source->expects(self::once())
            ->method('release')
            ->with('wallet-1', 'reservation-commit-failure', 'request-commit-failure:source-release');

        $repository = $this->createStub(WithdrawalRepositoryInterface::class);
        $repository->method('findByIdempotencyKey')->willReturn(null);
        $repository->method('transactional')->willReturnCallback(static function (callable $callback): mixed {
            $callback();

            throw new \RuntimeException('Transaction commit failed.');
        });
        $service = new WithdrawalApplicationService(
            $repository,
            [$source],
            [$this->createStub(WithdrawalRailServiceInterface::class)],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Transaction commit failed.');
        $service->request(
            'wallet',
            'wallet-1',
            'vendor',
            'vendor-1',
            'paying:destination-1',
            2500,
            'USD',
            'request-commit-failure',
        );
    }

    public function testInvalidRailReferenceIsCompensatedBeforeBeginFailureEscapes(): void
    {
        $source = $this->createStub(WithdrawalSourceServiceInterface::class);
        $rail = $this->createMock(WithdrawalRailServiceInterface::class);
        $rail->method('supports')->willReturn(true);
        $invalidReference = str_repeat('r', 192);
        $rail->expects(self::once())
            ->method('submit')
            ->willReturn($invalidReference);
        $rail->expects(self::once())
            ->method('compensateFailure')
            ->with($invalidReference, 'request-invalid-rail-reference:rail-failure-compensation');

        $withdrawal = $this->withdrawal('request-invalid-rail-reference');
        $withdrawal->reserve('reservation-1');
        $service = $this->service($source, $rail);

        try {
            $service->begin($withdrawal);
            self::fail('Invalid rail reference must fail begin.');
        } catch (\InvalidArgumentException) {
            self::assertSame(WithdrawalStatus::Reserved, $withdrawal->status());
        }
    }

    public function testPersistenceFailureCompensatesSubmittedRailBeforeBeginFailureEscapes(): void
    {
        $source = $this->createStub(WithdrawalSourceServiceInterface::class);
        $rail = $this->createMock(WithdrawalRailServiceInterface::class);
        $rail->method('supports')->willReturn(true);
        $rail->expects(self::exactly(2))
            ->method('submit')
            ->with('paying:destination-1', 2500, 'USD', 'request-begin-persistence-failure:rail-submit')
            ->willReturnOnConsecutiveCalls('rail-persistence-failure', 'rail-retry');
        $rail->expects(self::once())
            ->method('compensateFailure')
            ->with('rail-persistence-failure', 'request-begin-persistence-failure:rail-failure-compensation');

        $repository = $this->createStub(WithdrawalRepositoryInterface::class);
        $flushCalls = 0;
        $repository->method('flush')->willReturnCallback(static function () use (&$flushCalls): void {
            ++$flushCalls;
            if (1 === $flushCalls) {
                throw new \RuntimeException('Begin persistence failed.');
            }
        });
        $withdrawal = $this->withdrawal('request-begin-persistence-failure');
        $withdrawal->reserve('reservation-1');
        $service = new WithdrawalApplicationService($repository, [$source], [$rail]);

        try {
            $service->begin($withdrawal);
            self::fail('The first begin attempt must surface its persistence failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Begin persistence failed.', $exception->getMessage());
            self::assertSame(WithdrawalStatus::Reserved, $withdrawal->status());
            self::assertNull($withdrawal->railReference());
        }

        $service->begin($withdrawal);

        self::assertSame(WithdrawalStatus::Processing, $withdrawal->status());
        self::assertSame('rail-retry', $withdrawal->railReference());
        self::assertSame(2, $flushCalls);
    }

    public function testBeginCompensationFailurePreservesLocalPersistenceFailureAsCause(): void
    {
        $source = $this->createStub(WithdrawalSourceServiceInterface::class);
        $rail = $this->createMock(WithdrawalRailServiceInterface::class);
        $rail->method('supports')->willReturn(true);
        $rail->method('submit')->willReturn('rail-compensation-failure');
        $rail->expects(self::once())
            ->method('compensateFailure')
            ->with('rail-compensation-failure', 'request-begin-compensation-failure:rail-failure-compensation')
            ->willThrowException(new \RuntimeException('Rail compensation failed.'));

        $repository = $this->createStub(WithdrawalRepositoryInterface::class);
        $repository->method('flush')->willThrowException(new \RuntimeException('Begin persistence failed.'));
        $withdrawal = $this->withdrawal('request-begin-compensation-failure');
        $withdrawal->reserve('reservation-1');
        $service = new WithdrawalApplicationService($repository, [$source], [$rail]);

        try {
            $service->begin($withdrawal);
            self::fail('Compensation failure must not hide the failed begin operation.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'Withdrawal rail compensation failed after local begin failure: Rail compensation failed.',
                $exception->getMessage(),
            );
            self::assertInstanceOf(\RuntimeException::class, $exception->getPrevious());
            self::assertSame('Begin persistence failed.', $exception->getPrevious()->getMessage());
        }
    }

    public function testAmbiguousSourceAndRailOwnershipFailsClosed(): void
    {
        $sourceA = $this->createStub(WithdrawalSourceServiceInterface::class);
        $sourceA->method('supports')->willReturn(true);
        $sourceB = $this->createStub(WithdrawalSourceServiceInterface::class);
        $sourceB->method('supports')->willReturn(true);
        $rail = $this->createStub(WithdrawalRailServiceInterface::class);

        try {
            (new WithdrawalApplicationService($this->repository(), [$sourceA, $sourceB], [$rail]))->request(
                'wallet',
                'wallet-1',
                'vendor',
                'vendor-1',
                'paying:destination-1',
                2500,
                'USD',
                'request-ambiguous-source',
            );
            self::fail('Ambiguous withdrawal source ownership must fail closed.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('Multiple withdrawal sources', $exception->getMessage());
        }

        $source = $this->createStub(WithdrawalSourceServiceInterface::class);
        $railA = $this->createStub(WithdrawalRailServiceInterface::class);
        $railA->method('supports')->willReturn(true);
        $railB = $this->createStub(WithdrawalRailServiceInterface::class);
        $railB->method('supports')->willReturn(true);
        $withdrawal = $this->withdrawal('request-ambiguous-rail');
        $withdrawal->reserve('reservation-1');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Multiple withdrawal rails');
        (new WithdrawalApplicationService($this->repository(), [$source], [$railA, $railB]))->begin($withdrawal);
    }

    public function testIdempotentLifecycleRetriesReflushExistingStateWithoutRepeatingExternalSideEffects(): void
    {
        $source = $this->createMock(WithdrawalSourceServiceInterface::class);
        $source->expects(self::never())->method('reserve');
        $source->expects(self::never())->method('release');
        $source->expects(self::never())->method('finalize');
        $source->expects(self::never())->method('reverse');
        $rail = $this->createMock(WithdrawalRailServiceInterface::class);
        $rail->expects(self::never())->method('submit');
        $rail->expects(self::never())->method('compensateFailure');
        $rail->expects(self::never())->method('reverse');

        $repository = $this->createMock(WithdrawalRepositoryInterface::class);
        $repository->expects(self::exactly(5))->method('flush');
        $service = new WithdrawalApplicationService($repository, [$source], [$rail]);

        $processing = $this->withdrawal('retry-processing');
        $processing->reserve('reservation-processing');
        $processing->start('rail-processing');
        $service->begin($processing);

        $succeeded = $this->withdrawal('retry-succeeded');
        $succeeded->reserve('reservation-succeeded');
        $succeeded->start('rail-succeeded');
        $succeeded->succeed();
        $service->succeed($succeeded);

        $failed = $this->withdrawal('retry-failed');
        $failed->fail();
        $service->fail($failed);

        $cancelled = $this->withdrawal('retry-cancelled');
        $cancelled->cancel();
        $service->cancel($cancelled);

        $reversed = $this->withdrawal('retry-reversed');
        $reversed->reserve('reservation-reversed');
        $reversed->start('rail-reversed');
        $reversed->succeed();
        $reversed->reverse();
        $service->reverse($reversed);
    }

    public function testInvalidApplicationTransitionsFailClosed(): void
    {
        $service = $this->service(
            $this->createStub(WithdrawalSourceServiceInterface::class),
            $this->createStub(WithdrawalRailServiceInterface::class),
        );

        foreach (['begin', 'succeed', 'reverse'] as $operation) {
            try {
                $service->{$operation}($this->withdrawal('invalid-'.$operation));
                self::fail(sprintf('%s must reject a pending withdrawal.', $operation));
            } catch (\LogicException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private function withdrawal(string $idempotencyKey): Withdrawal
    {
        return new Withdrawal('wallet', 'wallet-1', 'vendor', 'vendor-1', 'paying:destination-1', 2500, 'USD', $idempotencyKey);
    }

    private function service(
        WithdrawalSourceServiceInterface $source,
        WithdrawalRailServiceInterface $rail,
        ?Withdrawal $existing = null,
    ): WithdrawalApplicationService {
        return new WithdrawalApplicationService($this->repository($existing), [$source], [$rail]);
    }

    private function repository(?Withdrawal $existing = null): WithdrawalRepositoryInterface
    {
        $repository = $this->createStub(WithdrawalRepositoryInterface::class);
        $repository->method('transactional')->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $repository->method('findByIdempotencyKey')->willReturn($existing);

        return $repository;
    }
}
