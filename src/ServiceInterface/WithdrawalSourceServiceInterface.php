<?php

declare(strict_types=1);

namespace App\Withdrawing\ServiceInterface;

interface WithdrawalSourceServiceInterface
{
    public function supports(string $sourceType): bool;

    public function reserve(string $sourceId, int $amountMinor, string $currency, string $idempotencyKey): string;

    public function release(string $sourceId, string $reservationReference, string $idempotencyKey): void;

    public function finalize(string $sourceId, string $reservationReference, string $idempotencyKey): void;

    public function reverse(string $sourceId, string $reservationReference, string $idempotencyKey): void;
}
