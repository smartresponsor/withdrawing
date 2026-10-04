<?php

declare(strict_types=1);

namespace App\Withdrawing\ServiceInterface;

/**
 * Defines the provider-neutral external rail operations required by Withdrawing.
 */
interface WithdrawalRailServiceInterface
{
    /** Determine whether this rail can resolve the supplied destination reference. */
    public function supports(string $destinationReference): bool;

    /** Submit the withdrawal to the external rail and return its correlation reference. */
    public function submit(string $destinationReference, int $amountMinor, string $currency, string $idempotencyKey): string;

    /** Compensate an external submission that cannot be committed to local lifecycle state. */
    public function compensateFailure(string $railReference, string $idempotencyKey): void;

    /** Reverse a previously succeeded external rail operation using a stable idempotency key. */
    public function reverse(string $railReference, string $idempotencyKey): void;
}
