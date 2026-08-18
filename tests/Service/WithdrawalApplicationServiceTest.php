<?php

declare(strict_types=1);

namespace App\Withdrawing\Tests\Service;

use App\Withdrawing\Enum\WithdrawalStatus;
use App\Withdrawing\Service\WithdrawalApplicationService;
use App\Withdrawing\ServiceInterface\WithdrawalRailServiceInterface;
use App\Withdrawing\ServiceInterface\WithdrawalSourceServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

final class WithdrawalApplicationServiceTest extends TestCase
{
    public function testLifecycleCrossesSourceAndRailBoundaries(): void
    {
        $source = new class () implements WithdrawalSourceServiceInterface {
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

        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->method('wrapInTransaction')->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $service = new WithdrawalApplicationService($entityManager, [$source], [$rail]);

        $withdrawal = $service->request('wallet', 'wallet-1', 'vendor', 'vendor-1', 'paying:destination-1', 2500, 'usd', 'request-1');
        self::assertSame(WithdrawalStatus::Reserved, $withdrawal->status());
        self::assertSame('reservation-1', $withdrawal->sourceReference());
        self::assertSame(['reserve:request-1:source-reserve'], $source->calls);

        $service->begin($withdrawal);
        self::assertSame(WithdrawalStatus::Processing, $withdrawal->status());
        self::assertSame(['submit:request-1:rail-submit'], $rail->calls);

        $service->succeed($withdrawal);
        self::assertSame(WithdrawalStatus::Succeeded, $withdrawal->status());
        self::assertSame('finalize:request-1:source-finalize', $source->calls[1]);
    }
}
