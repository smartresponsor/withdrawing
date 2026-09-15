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

        $legacyBusinessStatus = $table->hasColumn('status');
        $legacyObjectStatus = $table->hasColumn('object_status');
        $normalizedBusinessStatus = $table->hasColumn('withdrawal_status');

        if ($legacyBusinessStatus && $legacyObjectStatus && !$normalizedBusinessStatus) {
            $this->addSql('ALTER TABLE withdrawal_request RENAME COLUMN status TO withdrawal_status');
            $this->addSql('ALTER TABLE withdrawal_request RENAME COLUMN object_status TO status');
        } elseif ($legacyBusinessStatus && !$legacyObjectStatus && $normalizedBusinessStatus) {
            // Already normalized: status is Objecting state and withdrawal_status is business lifecycle state.
        } else {
            $this->abortIf(
                true,
                sprintf(
                    'Ambiguous withdrawal status normalization shape: status=%s, object_status=%s, withdrawal_status=%s.',
                    $legacyBusinessStatus ? 'yes' : 'no',
                    $legacyObjectStatus ? 'yes' : 'no',
                    $normalizedBusinessStatus ? 'yes' : 'no',
                ),
            );
        }

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
        ] as $legacy => $canonical) {
            $this->queueRenameRequired($table, $legacy, $canonical);
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
    private function queueRenameRequired(Table $table, string $from, string $to): void
    {
        if ($table->hasColumn($to)) {
            $this->abortIf($table->hasColumn($from), sprintf('Both %s and %s exist on %s; refusing ambiguous normalization.', $from, $to, self::TABLE));

            return;
        }

        $this->abortIf(!$table->hasColumn($from), sprintf('Required legacy column %s is missing from %s.', $from, self::TABLE));
        $this->addSql(sprintf('ALTER TABLE %s RENAME COLUMN %s TO %s', self::TABLE, $from, $to));
    }
}
