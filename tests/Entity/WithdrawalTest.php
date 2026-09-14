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
}
