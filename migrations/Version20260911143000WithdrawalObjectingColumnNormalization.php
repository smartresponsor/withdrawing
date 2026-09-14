<?php

declare(strict_types=1);

namespace App\Withdrawing\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;

final class Version20260911143000WithdrawalObjectingColumnNormalization extends AbstractMigration
{
    private const string TABLE = 'withdrawal_request';

    /**
     * Describes the data-preserving Objecting column normalization performed by this migration.
     */
    public function getDescription(): string
    {
        return 'Rename legacy object-prefixed system columns and separate the withdrawal lifecycle state from canonical Objecting status.';
    }

    /**
     * Renames legacy columns in place so existing values survive the Objecting field-pack normalization.
     */
    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Withdrawing Objecting normalization is PostgreSQL-only.');
        $this->abortIf(!$schema->hasTable(self::TABLE), 'withdrawal_request must exist before Objecting normalization.');

        $table = $schema->getTable(self::TABLE);
        $this->renameRequired($table, 'status', 'withdrawal_status');

        foreach ([
            'object_uuid' => 'uuid',
            'object_slug' => 'slug',
            'object_first_title' => 'first_title',
            'object_middle_title' => 'middle_title',
            'object_last_title' => 'last_title',
            'object_created_at' => 'created_at',
            'object_modified_at' => 'modified_at',
            'object_created_by' => 'created_by',
            'object_modified_by' => 'modified_by',
            'object_active' => 'active',
            'object_enabled' => 'enabled',
            'object_status' => 'status',
        ] as $legacy => $canonical) {
            $this->renameRequired($table, $legacy, $canonical);
        }
    }

    /**
     * Keeps the normalization irreversible because reversing it would restore a retired schema contract.
     */
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Objecting entity-native column normalization must not be rolled back to the retired object-prefixed schema.');
    }

    /**
     * Renames one required column and aborts rather than guessing when the source or target shape is ambiguous.
     */
    private function renameRequired(Table $table, string $from, string $to): void
    {
        if ($table->hasColumn($to)) {
            $this->abortIf($table->hasColumn($from), sprintf('Both %s and %s exist on %s; refusing ambiguous normalization.', $from, $to, self::TABLE));

            return;
        }

        $this->abortIf(!$table->hasColumn($from), sprintf('Required legacy column %s is missing from %s.', $from, self::TABLE));
        $table->renameColumn($from, $to);
    }
}
