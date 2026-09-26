<?php

declare(strict_types=1);

namespace App\Withdrawing\Tests\Entity;

use App\Withdrawing\Entity\Withdrawal;
use App\Withdrawing\Enum\WithdrawalStatus;
use PHPUnit\Framework\TestCase;

final class WithdrawalTest extends TestCase
{
    public function testLifecycleFromPendingToSucceededAndReversed(): void
    {
        $withdrawal = new Withdrawal(
            'wallet',
            'wallet-123',
            'vendor',
            'vendor-456',
            'payment-destination-789',
            12500,
            'usd',
            'withdrawal-test-1',
        );

        self::assertSame(WithdrawalStatus::Pending, $withdrawal->status());
        self::assertNotSame('', $withdrawal->id()->toRfc4122());
        self::assertSame('wallet', $withdrawal->sourceType());
        self::assertSame('wallet-123', $withdrawal->sourceId());
        self::assertSame('vendor', $withdrawal->actorType());
        self::assertSame('vendor-456', $withdrawal->actorId());
        self::assertSame('payment-destination-789', $withdrawal->destinationReference());
        self::assertSame('withdrawal-test-1', $withdrawal->idempotencyKey());
        self::assertSame('USD', $withdrawal->currency());
        self::assertSame(12500, $withdrawal->amountMinor());
        self::assertNull($withdrawal->sourceReference());
        self::assertSame(1, $withdrawal->getObjectVersion());
        self::assertNull($withdrawal->getObjectEtag());

        $withdrawal->reserve('source-reservation-1');
        self::assertSame(WithdrawalStatus::Reserved, $withdrawal->status());

        $withdrawal->start('rail-123');
        self::assertSame(WithdrawalStatus::Processing, $withdrawal->status());
        self::assertSame('rail-123', $withdrawal->railReference());

        $withdrawal->succeed();
        self::assertSame(WithdrawalStatus::Succeeded, $withdrawal->status());

        $withdrawal->reverse();
        self::assertSame(WithdrawalStatus::Reversed, $withdrawal->status());
    }

    public function testCancelIsAllowedBeforeProcessing(): void
    {
        $withdrawal = new Withdrawal('commission', 'c-1', 'vendor', 'v-1', 'dest-1', 100, 'USD', 'withdrawal-test-2');
        $withdrawal->reserve('source-reservation-1');
        $withdrawal->cancel();

        self::assertSame(WithdrawalStatus::Cancelled, $withdrawal->status());
    }

    public function testProcessingWithdrawalCannotBeCancelled(): void
    {
        $withdrawal = new Withdrawal('wallet', 'w-1', 'vendor', 'v-1', 'dest-1', 100, 'USD', 'withdrawal-test-3');
        $withdrawal->reserve('source-reservation-1');
        $withdrawal->start('rail-1');

        $this->expectException(\LogicException::class);
        $withdrawal->cancel();
    }

    public function testAmountMustUsePositiveMinorUnits(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Withdrawal('wallet', 'w-1', 'vendor', 'v-1', 'dest-1', 0, 'USD', 'withdrawal-test-4');
    }

    public function testRequestIdentityMustNotBeBlank(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Withdrawal('', 'w-1', 'vendor', 'v-1', 'dest-1', 100, 'USD', 'withdrawal-test-5');
    }

    public function testSourceAndRailReferencesMustNotBeBlank(): void
    {
        $withdrawal = new Withdrawal('wallet', 'w-1', 'vendor', 'v-1', 'dest-1', 100, 'USD', 'withdrawal-test-6');

        try {
            $withdrawal->reserve(' ');
            self::fail('Blank source reference must be rejected.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        $withdrawal->reserve('reservation-1');
        try {
            $withdrawal->start(' ');
            self::fail('Blank rail reference must be rejected.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    public function testFailureRejectsTerminalState(): void
    {
        $withdrawal = new Withdrawal('wallet', 'w-1', 'vendor', 'v-1', 'dest-1', 100, 'USD', 'withdrawal-test-7');
        $withdrawal->cancel();

        $this->expectException(\LogicException::class);
        $withdrawal->fail();
    }

    public function testTransitionRejectsUnexpectedStatus(): void
    {
        $withdrawal = new Withdrawal('wallet', 'w-1', 'vendor', 'v-1', 'dest-1', 100, 'USD', 'withdrawal-test-8');
        $withdrawal->reserve('reservation-1');

        $this->expectException(\LogicException::class);
        $withdrawal->reserve('reservation-2');
    }

    public function testStartRejectsUnexpectedStatus(): void
    {
        $withdrawal = new Withdrawal('wallet', 'w-1', 'vendor', 'v-1', 'dest-1', 100, 'USD', 'withdrawal-test-9');

        $this->expectException(\LogicException::class);
        $withdrawal->start('rail-1');
    }

    public function testSucceedRejectsUnexpectedStatus(): void
    {
        $withdrawal = new Withdrawal('wallet', 'w-1', 'vendor', 'v-1', 'dest-1', 100, 'USD', 'withdrawal-test-10');

        $this->expectException(\LogicException::class);
        $withdrawal->succeed();
    }

    public function testReverseRejectsUnexpectedStatus(): void
    {
        $withdrawal = new Withdrawal('wallet', 'w-1', 'vendor', 'v-1', 'dest-1', 100, 'USD', 'withdrawal-test-11');

        $this->expectException(\LogicException::class);
        $withdrawal->reverse();
    }

    public function testFailIsAllowedAcrossNonTerminalStates(): void
    {
        $pending = new Withdrawal('wallet', 'w-1', 'vendor', 'v-1', 'dest-1', 100, 'USD', 'withdrawal-test-12');
        $pending->fail();
        self::assertSame(WithdrawalStatus::Failed, $pending->status());

        $reserved = new Withdrawal('wallet', 'w-2', 'vendor', 'v-1', 'dest-1', 100, 'USD', 'withdrawal-test-13');
        $reserved->reserve('reservation-2');
        $reserved->fail();
        self::assertSame(WithdrawalStatus::Failed, $reserved->status());

        $processing = new Withdrawal('wallet', 'w-3', 'vendor', 'v-1', 'dest-1', 100, 'USD', 'withdrawal-test-14');
        $processing->reserve('reservation-3');
        $processing->start('rail-3');
        $processing->fail();
        self::assertSame(WithdrawalStatus::Failed, $processing->status());
    }

    public function testCurrencyMustBeIso4217AlphaCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Withdrawal('wallet', 'w-1', 'vendor', 'v-1', 'dest-1', 100, 'US', 'withdrawal-test-15');
    }

    public function testPersistenceBoundariesRejectOversizedRequestIdentityBeforeLifecycleWork(): void
    {
        foreach ([
            ['sourceType', str_repeat('s', 65)],
            ['sourceId', str_repeat('s', 129)],
            ['actorType', str_repeat('a', 65)],
            ['actorId', str_repeat('a', 129)],
            ['destinationReference', str_repeat('d', 192)],
            ['idempotencyKey', str_repeat('i', 129)],
        ] as [$field, $oversized]) {
            $arguments = [
                'sourceType' => 'wallet',
                'sourceId' => 'w-1',
                'actorType' => 'vendor',
                'actorId' => 'v-1',
                'destinationReference' => 'dest-1',
                'amountMinor' => 100,
                'currency' => 'USD',
                'idempotencyKey' => 'withdrawal-test-boundary',
            ];
            $arguments[$field] = $oversized;

            try {
                new Withdrawal(...$arguments);
                self::fail(sprintf('Oversized %s must be rejected.', $field));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testPersistenceBoundariesRejectOversizedLifecycleReferencesBeforeTransition(): void
    {
        $withdrawal = new Withdrawal('wallet', 'w-1', 'vendor', 'v-1', 'dest-1', 100, 'USD', 'withdrawal-test-reference-boundary');

        try {
            $withdrawal->reserve(str_repeat('r', 192));
            self::fail('Oversized source reference must be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertSame(WithdrawalStatus::Pending, $withdrawal->status());
        }

        $withdrawal->reserve('reservation-1');

        try {
            $withdrawal->start(str_repeat('r', 192));
            self::fail('Oversized rail reference must be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertSame(WithdrawalStatus::Reserved, $withdrawal->status());
        }
    }
}
