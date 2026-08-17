<?php

declare(strict_types=1);

namespace App\Withdrawing\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\BinaryType;
use Doctrine\DBAL\Types\GuidType;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260817020500WithdrawalRequestAdoption extends AbstractMigration
{
    private const TABLE = 'withdrawal_request';

    public function getDescription(): string
    {
        return 'Adopt or create the source-agnostic Withdrawing withdrawal request schema.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Withdrawing host adoption is PostgreSQL-only.');
        if (!$schema->hasTable(self::TABLE)) {
            $this->createTable($schema);
            return;
        }
        $this->adopt($schema->getTable(self::TABLE));
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Removing withdrawal_request would destroy durable withdrawal history.');
    }

    private function createTable(Schema $schema): void
    {
        $table = $schema->createTable(self::TABLE);
        $table->addColumn('id', Types::GUID);
        $table->addColumn('source_type', Types::STRING, ['length' => 64]);
        $table->addColumn('source_id', Types::STRING, ['length' => 128]);
        $table->addColumn('actor_type', Types::STRING, ['length' => 64]);
        $table->addColumn('actor_id', Types::STRING, ['length' => 128]);
        $table->addColumn('destination_reference', Types::STRING, ['length' => 191]);
        $table->addColumn('amount_minor', Types::BIGINT);
        $table->addColumn('currency', Types::STRING, ['length' => 3]);
        $table->addColumn('idempotency_key', Types::STRING, ['length' => 128]);
        $table->addColumn('status', Types::STRING, ['length' => 255]);
        $table->addColumn('rail_reference', Types::STRING, ['length' => 191, 'notnull' => false]);
        $this->addObjecting($table);
        $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());
        $table->addUniqueIndex(['idempotency_key'], 'uniq_withdrawal_request_idempotency_key');
        $table->addUniqueIndex(['object_uuid'], 'uniq_withdrawing_request_object_uuid');
        $table->addUniqueIndex(['object_slug'], 'uniq_withdrawing_request_object_slug');
        $this->addIndexes($table);
    }

    private function adopt(Table $table): void
    {
        $required = ['id', 'source_type', 'source_id', 'actor_type', 'actor_id', 'destination_reference', 'amount_minor', 'currency', 'idempotency_key', 'status', 'rail_reference', 'object_uuid', 'object_slug', 'object_first_title', 'object_middle_title', 'object_last_title', 'object_created_at', 'object_modified_at', 'object_created_by', 'object_modified_by', 'object_active', 'object_enabled', 'object_status'];
        foreach ($required as $column) {
            $this->abortIf(!$table->hasColumn($column), sprintf('Existing %s is missing required column %s.', self::TABLE, $column));
        }
        $this->abortIf(!$table->getColumn('id')->getType() instanceof GuidType, 'Existing withdrawal_request.id must be GUID/UUID.');
        $objectUuid = $table->getColumn('object_uuid');
        $this->abortIf(!$objectUuid->getType() instanceof BinaryType || 16 !== $objectUuid->getLength(), 'Existing withdrawal_request.object_uuid must be fixed binary(16).');
        $objectSlug = $table->getColumn('object_slug');
        $this->abortIf(!$objectSlug->getType() instanceof StringType || 190 !== $objectSlug->getLength(), 'Existing withdrawal_request.object_slug must be varchar(190).');
        $this->abortIf(!$table->hasIndex('uniq_withdrawal_request_idempotency_key'), 'Existing withdrawal_request must preserve idempotency uniqueness.');
        $this->addIndexes($table);
    }

    private function addObjecting(Table $table): void
    {
        $table->addColumn('object_uuid', Types::BINARY, ['length' => 16, 'fixed' => true]);
        $table->addColumn('object_slug', Types::STRING, ['length' => 190]);
        $table->addColumn('object_first_title', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('object_middle_title', Types::TEXT, ['notnull' => false]);
        $table->addColumn('object_last_title', Types::TEXT, ['notnull' => false]);
        $table->addColumn('object_created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('object_modified_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('object_created_by', Types::STRING, ['length' => 190, 'notnull' => false]);
        $table->addColumn('object_modified_by', Types::STRING, ['length' => 190, 'notnull' => false]);
        $table->addColumn('object_active', Types::BOOLEAN, ['default' => true]);
        $table->addColumn('object_enabled', Types::BOOLEAN, ['default' => true]);
        $table->addColumn('object_status', Types::STRING, ['length' => 64, 'notnull' => false]);
    }

    private function addIndexes(Table $table): void
    {
        $indexes = [
            'idx_withdrawing_request_source_status' => ['source_type', 'source_id', 'status'],
            'idx_withdrawing_request_actor_status' => ['actor_type', 'actor_id', 'status'],
            'idx_withdrawing_request_status_created' => ['status', 'object_created_at'],
        ];
        foreach ($indexes as $name => $columns) {
            if (!$table->hasIndex($name)) {
                $table->addIndex($columns, $name);
            }
        }
    }
}
