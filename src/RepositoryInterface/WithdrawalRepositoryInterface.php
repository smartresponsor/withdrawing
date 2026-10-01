<?php

declare(strict_types=1);

namespace App\Withdrawing\RepositoryInterface;

use App\Withdrawing\Entity\Withdrawal;

interface WithdrawalRepositoryInterface
{
    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function transactional(callable $operation): mixed;

    public function findByIdempotencyKey(string $idempotencyKey): ?Withdrawal;

    public function add(Withdrawal $withdrawal): void;

    public function flush(): void;
}
