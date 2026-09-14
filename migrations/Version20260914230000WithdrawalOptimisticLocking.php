<?php

declare(strict_types=1);

namespace App\Withdrawing\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914230000WithdrawalOptimisticLocking extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the canonical Objecting optimistic-lock version and etag columns to withdrawal_request.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Withdrawing optimistic locking is PostgreSQL-only.');
        $this->abortIf(!$schema->hasTable('withdrawal_request'), 'withdrawal_request must exist before optimistic locking is enabled.');

        $table = $schema->getTable('withdrawal_request');
        $this->abortIf($table->hasColumn('version') || $table->hasColumn('etag'), 'withdrawal_request already contains version or etag; refusing ambiguous optimistic-lock migration.');

        $this->addSql('ALTER TABLE withdrawal_request ADD version INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE withdrawal_request ALTER COLUMN version DROP DEFAULT');
        $this->addSql('ALTER TABLE withdrawal_request ADD etag VARCHAR(128) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Withdrawing optimistic locking is PostgreSQL-only.');
        $this->addSql('ALTER TABLE withdrawal_request DROP COLUMN IF EXISTS etag');
        $this->addSql('ALTER TABLE withdrawal_request DROP COLUMN IF EXISTS version');
    }
}
