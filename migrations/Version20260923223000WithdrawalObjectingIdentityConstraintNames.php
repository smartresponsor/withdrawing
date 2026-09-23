<?php

declare(strict_types=1);

namespace App\Withdrawing\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923223000WithdrawalObjectingIdentityConstraintNames extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace hash-derived withdrawal Objecting identity unique indexes with deterministic semantic names.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Withdrawing Objecting identity constraint migration supports PostgreSQL only.',
        );

        $this->renameIndex(
            'uniq_5e56f6d7d17f50a6',
            'uniq_withdrawal_request_uuid',
        );
        $this->renameIndex(
            'uniq_5e56f6d7989d9b62',
            'uniq_withdrawal_request_slug',
        );
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Deterministic Withdrawing Objecting identity constraint naming is intentionally irreversible.',
        );
    }

    private function renameIndex(string $legacy, string $canonical): void
    {
        $this->addSql(sprintf(
            <<<'SQL'
DO $$
BEGIN
    IF to_regclass('public.%1$s') IS NOT NULL AND to_regclass('public.%2$s') IS NULL THEN
        EXECUTE 'ALTER INDEX %1$s RENAME TO %2$s';
    ELSIF to_regclass('public.%1$s') IS NOT NULL AND to_regclass('public.%2$s') IS NOT NULL THEN
        RAISE EXCEPTION 'Both legacy index %1$s and canonical index %2$s exist; manual reconciliation is required.';
    END IF;
END
$$
SQL,
            $legacy,
            $canonical,
        ));
    }
}

