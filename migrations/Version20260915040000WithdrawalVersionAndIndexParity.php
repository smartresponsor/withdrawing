<?php

declare(strict_types=1);

namespace App\Withdrawing\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260915040000WithdrawalVersionAndIndexParity extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align withdrawal optimistic-version default and Objecting identity index names with current ORM metadata.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Withdrawing schema parity migration is PostgreSQL-only.');
        $this->abortIf(!$schema->hasTable('withdrawal_request'), 'withdrawal_request must exist before schema parity normalization.');
        $this->abortIf(!$schema->getTable('withdrawal_request')->hasColumn('version'), 'withdrawal_request.version must exist before schema parity normalization.');

        $this->addSql('ALTER TABLE withdrawal_request ALTER COLUMN version SET DEFAULT 1');
        $this->addSql("DO $$ BEGIN IF to_regclass('public.uniq_withdrawing_request_object_uuid') IS NOT NULL AND to_regclass('public.uniq_5e56f6d7d17f50a6') IS NULL THEN ALTER INDEX uniq_withdrawing_request_object_uuid RENAME TO uniq_5e56f6d7d17f50a6; ELSIF to_regclass('public.uniq_withdrawing_request_object_uuid') IS NOT NULL AND to_regclass('public.uniq_5e56f6d7d17f50a6') IS NOT NULL THEN RAISE EXCEPTION 'Both source and target UUID indexes exist.'; END IF; END $$");
        $this->addSql("DO $$ BEGIN IF to_regclass('public.uniq_withdrawing_request_object_slug') IS NOT NULL AND to_regclass('public.uniq_5e56f6d7989d9b62') IS NULL THEN ALTER INDEX uniq_withdrawing_request_object_slug RENAME TO uniq_5e56f6d7989d9b62; ELSIF to_regclass('public.uniq_withdrawing_request_object_slug') IS NOT NULL AND to_regclass('public.uniq_5e56f6d7989d9b62') IS NOT NULL THEN RAISE EXCEPTION 'Both source and target slug indexes exist.'; END IF; END $$");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Current ORM identity index naming and version defaults are the canonical schema contract.');
    }
}
