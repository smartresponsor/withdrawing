<?php

declare(strict_types=1);

namespace App\Withdrawing\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260818044500WithdrawalSettlementEvent extends AbstractMigration
{
    private const string TABLE = 'withdrawal_settlement_event';

    public function getDescription(): string
    {
        return 'Create durable provider settlement event journal for withdrawal webhook idempotency and diagnostics.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Withdrawal settlement event journal is PostgreSQL-only.');
        if ($schema->hasTable(self::TABLE)) {
            $table = $schema->getTable(self::TABLE);
            foreach (['id', 'provider', 'provider_event_id', 'event_type', 'rail_reference', 'payload_hash', 'outcome', 'failure_code', 'failure_message', 'created_at', 'processed_at'] as $column) {
                $this->abortIf(!$table->hasColumn($column), sprintf('Existing %s is missing required column %s.', self::TABLE, $column));
            }
            $this->abortIf(!$table->hasIndex('uniq_withdrawal_settlement_provider_event'), 'Existing withdrawal_settlement_event must preserve provider event uniqueness.');

            return;
        }

        $table = $schema->createTable(self::TABLE);
        $table->addColumn('id', Types::GUID);
        $table->addColumn('provider', Types::STRING, ['length' => 32]);
        $table->addColumn('provider_event_id', Types::STRING, ['length' => 191]);
        $table->addColumn('event_type', Types::STRING, ['length' => 96]);
        $table->addColumn('rail_reference', Types::STRING, ['length' => 191, 'notnull' => false]);
        $table->addColumn('payload_hash', Types::STRING, ['length' => 64]);
        $table->addColumn('outcome', Types::STRING, ['length' => 32]);
        $table->addColumn('failure_code', Types::STRING, ['length' => 128, 'notnull' => false]);
        $table->addColumn('failure_message', Types::TEXT, ['notnull' => false]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('processed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());
        $table->addUniqueIndex(['provider', 'provider_event_id'], 'uniq_withdrawal_settlement_provider_event');
        $table->addIndex(['rail_reference'], 'idx_withdrawal_settlement_rail_reference');
        $table->addIndex(['outcome', 'created_at'], 'idx_withdrawal_settlement_outcome_created');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Removing the withdrawal settlement event journal would destroy provider audit history.');
    }
}