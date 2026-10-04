<?php

declare(strict_types=1);

namespace App\Withdrawing\Repository;

use App\Withdrawing\Entity\Withdrawal;
use App\Withdrawing\RepositoryInterface\WithdrawalRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Persists withdrawal aggregates and owns their Doctrine transaction boundary.
 */
final readonly class WithdrawalRepository implements WithdrawalRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** Execute the supplied persistence operation inside one Doctrine transaction. */
    public function transactional(callable $operation): mixed
    {
        return $this->entityManager->wrapInTransaction(static fn (): mixed => $operation());
    }

    /** Serialize one idempotency-key creation path for the lifetime of the current PostgreSQL transaction. */
    public function lockIdempotencyKey(string $idempotencyKey): void
    {
        $this->entityManager->getConnection()->executeQuery(
            'SELECT pg_advisory_xact_lock(hashtext(:scope), hashtext(:idempotency_key))',
            [
                'scope' => 'withdrawing.request',
                'idempotency_key' => $idempotencyKey,
            ],
        )->fetchOne();
    }

    /** Find an existing withdrawal by its normalized idempotency key. */
    public function findByIdempotencyKey(string $idempotencyKey): ?Withdrawal
    {
        $withdrawal = $this->entityManager->getRepository(Withdrawal::class)->findOneBy([
            'idempotencyKey' => $idempotencyKey,
        ]);

        return $withdrawal instanceof Withdrawal ? $withdrawal : null;
    }

    /** Register a new withdrawal with the active Doctrine unit of work. */
    public function add(Withdrawal $withdrawal): void
    {
        $this->entityManager->persist($withdrawal);
    }

    /** Flush pending withdrawal persistence changes to durable storage. */
    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
