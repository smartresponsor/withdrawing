<?php

declare(strict_types=1);

namespace App\Withdrawing\Repository;

use App\Withdrawing\Entity\Withdrawal;
use App\Withdrawing\RepositoryInterface\WithdrawalRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class WithdrawalRepository implements WithdrawalRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function transactional(callable $operation): mixed
    {
        return $this->entityManager->wrapInTransaction(static fn (): mixed => $operation());
    }

    public function findByIdempotencyKey(string $idempotencyKey): ?Withdrawal
    {
        $withdrawal = $this->entityManager->getRepository(Withdrawal::class)->findOneBy([
            'idempotencyKey' => $idempotencyKey,
        ]);

        return $withdrawal instanceof Withdrawal ? $withdrawal : null;
    }

    public function add(Withdrawal $withdrawal): void
    {
        $this->entityManager->persist($withdrawal);
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
