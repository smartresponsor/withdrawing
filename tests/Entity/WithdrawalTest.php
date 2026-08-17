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
        self::assertSame('USD', $withdrawal->currency());
        self::assertSame(12500, $withdrawal->amountMinor());

        $withdrawal->reserve();
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
        $withdrawal->reserve();
        $withdrawal->cancel();

        self::assertSame(WithdrawalStatus::Cancelled, $withdrawal->status());
    }

    public function testProcessingWithdrawalCannotBeCancelled(): void
    {
        $withdrawal = new Withdrawal('wallet', 'w-1', 'vendor', 'v-1', 'dest-1', 100, 'USD', 'withdrawal-test-3');
        $withdrawal->reserve();
        $withdrawal->start('rail-1');

        $this->expectException(\LogicException::class);
        $withdrawal->cancel();
    }

    public function testAmountMustUsePositiveMinorUnits(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Withdrawal('wallet', 'w-1', 'vendor', 'v-1', 'dest-1', 0, 'USD', 'withdrawal-test-4');
    }
}
