<?php

declare(strict_types=1);

namespace App\Withdrawing\ServiceInterface;

/**
 * Defines the source-owned reservation operations required by the withdrawal lifecycle.
 */
interface WithdrawalSourceServiceInterface
{
    /** Determine whether this source service owns the supplied source discriminator. */
    public function supports(string $sourceType): bool;

    /** Reserve source value and return the source-owned reservation correlation reference. */
    public function reserve(string $sourceId, int $amountMinor, string $currency, string $idempotencyKey): string;

    /** Release a reservation when withdrawal processing stops before successful settlement. */
    public function release(string $sourceId, string $reservationReference, string $idempotencyKey): void;

    /** Finalize reserved source value after the external rail reports successful settlement. */
    public function finalize(string $sourceId, string $reservationReference, string $idempotencyKey): void;

    /** Reverse finalized source value during a post-settlement withdrawal reversal. */
    public function reverse(string $sourceId, string $reservationReference, string $idempotencyKey): void;
}
