<?php

declare(strict_types=1);

namespace App\Withdrawing\RepositoryInterface;

use App\Withdrawing\Entity\Withdrawal;

/**
 * Defines persistence operations required by withdrawal lifecycle orchestration.
 */
interface WithdrawalRepositoryInterface
{
    /**
     * Execute a withdrawal persistence operation inside one repository transaction.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function transactional(callable $operation): mixed;

    /** Serialize concurrent request creation for one idempotency key within the active transaction. */
    public function lockIdempotencyKey(string $idempotencyKey): void;

    /** Find the withdrawal bound to a normalized idempotency key when present. */
    public function findByIdempotencyKey(string $idempotencyKey): ?Withdrawal;

    /** Register a new withdrawal with the current persistence unit of work. */
    public function add(Withdrawal $withdrawal): void;

    /** Flush pending withdrawal persistence changes to durable storage. */
    public function flush(): void;
}
