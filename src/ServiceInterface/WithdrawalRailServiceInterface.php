<?php

declare(strict_types=1);

namespace App\Withdrawing\ServiceInterface;

interface WithdrawalRailServiceInterface
{
    public function submit(string $destinationReference, int $amountMinor, string $currency, string $idempotencyKey): string;

    public function reverse(string $railReference, string $idempotencyKey): void;
}
