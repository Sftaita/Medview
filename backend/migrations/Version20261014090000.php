<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Successive periods of a planning, lot L4b (docs/decisions.md D195): the
 * scope a generation job was requested with — `unlocked_lines` (generate the
 * lines of the period that are not locked, keep the published ones) or NULL
 * (the historical behaviour) — replayed by the worker.
 *
 * Strictly additive: one nullable column, NULL on every existing row. No row
 * is written. The previous release ignores it.
 *
 * down() drops it only while no job holds a value: such a job records what
 * was actually asked for, never dropped silently.
 */
final class Version20261014090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Planning cycles L4b: the scope of a generation job (D195)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE planning_jobs ADD generation_scope VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
              IF EXISTS (SELECT 1 FROM planning_jobs WHERE generation_scope IS NOT NULL) THEN
                RAISE EXCEPTION 'A job was requested with a generation scope: going back would lose what was asked for. Restore a backup instead (docs/planning-cycles.md §6.1).';
              END IF;
            END $$
            SQL);
        $this->addSql('ALTER TABLE planning_jobs DROP generation_scope');
    }
}
