<?php

declare(strict_types=1);

namespace App\Withdrawing\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260817182000WithdrawalRailReferenceUnique extends AbstractMigration
{
    private const string INDEX = 'uniq_withdrawal_request_rail_reference';
    private const string TABLE = 'withdrawal_request';

    public function getDescription(): string
    {
        return 'Require non-null withdrawal rail references to be unique for deterministic payout settlement.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Withdrawing rail-reference hardening is PostgreSQL-only.');
        $this->abortIf(!$schema->hasTable(self::TABLE), 'withdrawal_request must exist before rail-reference hardening.');

        $table = $schema->getTable(self::TABLE);
        $this->abortIf(!$table->hasColumn('rail_reference'), 'withdrawal_request.rail_reference is required.');
        if ($table->hasIndex(self::INDEX)) {
            return;
        }

        $duplicate = $this->connection->fetchOne(
            'SELECT rail_reference FROM withdrawal_request WHERE rail_reference IS NOT NULL GROUP BY rail_reference HAVING COUNT(*) > 1 LIMIT 1',
        );
        $this->abortIf(false !== $duplicate, sprintf('Duplicate withdrawal rail reference "%s" prevents uniqueness hardening.', (string) $duplicate));

        $table->addUniqueIndex(['rail_reference'], self::INDEX);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Removing withdrawal rail-reference uniqueness would weaken payout settlement integrity.');
    }
}
