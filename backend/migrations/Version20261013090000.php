<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Successive periods of a planning, lot L3 (docs/planning-cycles.md §5.4,
 * docs/decisions.md D189): a frozen external commitment of a generation's
 * snapshot may now be a duty of ANOTHER period of the planning — its period
 * is kept with it, as values (stable id, number, dates), so that an
 * exclusion explains which neighbouring period it comes from.
 *
 * Strictly additive: four nullable columns, NULL on every existing row (a
 * duty of the same period, which is all a snapshot froze before L3). No row
 * is written. The previous release ignores them.
 *
 * down() drops them only while no snapshot holds a value in them: such a
 * value is part of a frozen snapshot (and of its hash), never dropped
 * silently — the previous release cannot generate a planning with several
 * periods anyway.
 */
final class Version20261013090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Planning cycles L3: the period of a frozen external commitment taken from another period (D189)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE planning_snapshot_external_commitments ADD source_cycle_stable_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE planning_snapshot_external_commitments ADD source_cycle_number INT DEFAULT NULL');
        $this->addSql('ALTER TABLE planning_snapshot_external_commitments ADD source_cycle_starts_at DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE planning_snapshot_external_commitments ADD source_cycle_ends_at DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
              IF EXISTS (SELECT 1 FROM planning_snapshot_external_commitments WHERE source_cycle_stable_id IS NOT NULL) THEN
                RAISE EXCEPTION 'A snapshot froze a commitment of another period: going back would alter a frozen snapshot. Restore a backup instead (docs/planning-cycles.md §6.1).';
              END IF;
            END $$
            SQL);
        $this->addSql('ALTER TABLE planning_snapshot_external_commitments DROP source_cycle_stable_id');
        $this->addSql('ALTER TABLE planning_snapshot_external_commitments DROP source_cycle_number');
        $this->addSql('ALTER TABLE planning_snapshot_external_commitments DROP source_cycle_starts_at');
        $this->addSql('ALTER TABLE planning_snapshot_external_commitments DROP source_cycle_ends_at');
    }
}
