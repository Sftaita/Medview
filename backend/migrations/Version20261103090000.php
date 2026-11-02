<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * docs/decisions.md D224 — a reinforcement line inherits its blocks from its
 * source line: a demand policy (and its copy frozen in a snapshot) records
 * where the line's structure comes from, and the patterns and duty types a
 * reinforcement line borrows from its source are marked with the source
 * pattern / type they mirror.
 *
 * Additive only: every existing policy and frozen policy is OWN (what it
 * was), every existing pattern and type mirrors nothing. No existing row is
 * changed. down() refuses while anything INHERITED exists, rather than
 * deleting it.
 */
final class Version20261103090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reinforcement lines inherit the blocks of their source line (D224)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE planning_line_demand_policies ADD structure_source VARCHAR(16) DEFAULT 'OWN' NOT NULL");
        $this->addSql("ALTER TABLE planning_line_demand_policies ADD CONSTRAINT chk_demand_policies_structure_source CHECK (structure_source IN ('OWN', 'INHERITED'))");
        $this->addSql("ALTER TABLE planning_snapshot_demand_policies ADD structure_source VARCHAR(16) DEFAULT 'OWN' NOT NULL");
        $this->addSql("ALTER TABLE planning_snapshot_demand_policies ADD CONSTRAINT chk_snapshot_demand_policies_structure_source CHECK (structure_source IN ('OWN', 'INHERITED'))");
        $this->addSql('ALTER TABLE duty_patterns ADD inherited_from_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE duty_patterns ADD CONSTRAINT fk_duty_patterns_inherited_from FOREIGN KEY (inherited_from_id) REFERENCES duty_patterns (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_duty_patterns_inherited_from ON duty_patterns (inherited_from_id)');
        $this->addSql('ALTER TABLE duty_types ADD inherited_from_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE duty_types ADD CONSTRAINT fk_duty_types_inherited_from FOREIGN KEY (inherited_from_id) REFERENCES duty_types (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_duty_types_inherited_from ON duty_types (inherited_from_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            (int) $this->connection->fetchOne("SELECT COUNT(*) FROM planning_line_demand_policies WHERE structure_source = 'INHERITED'") > 0
            || (int) $this->connection->fetchOne("SELECT COUNT(*) FROM planning_snapshot_demand_policies WHERE structure_source = 'INHERITED'") > 0,
            'A demand policy inherits its structure (D224): rolling back would change how its line is read.',
        );
        $this->abortIf(
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM duty_patterns WHERE inherited_from_id IS NOT NULL') > 0
            || (int) $this->connection->fetchOne('SELECT COUNT(*) FROM duty_types WHERE inherited_from_id IS NOT NULL') > 0,
            'A pattern or duty type mirrors a source line (D224): rolling back would lose that link.',
        );
        $this->addSql('DROP INDEX idx_duty_types_inherited_from');
        $this->addSql('ALTER TABLE duty_types DROP CONSTRAINT fk_duty_types_inherited_from');
        $this->addSql('ALTER TABLE duty_types DROP inherited_from_id');
        $this->addSql('DROP INDEX idx_duty_patterns_inherited_from');
        $this->addSql('ALTER TABLE duty_patterns DROP CONSTRAINT fk_duty_patterns_inherited_from');
        $this->addSql('ALTER TABLE duty_patterns DROP inherited_from_id');
        $this->addSql('ALTER TABLE planning_snapshot_demand_policies DROP CONSTRAINT chk_snapshot_demand_policies_structure_source');
        $this->addSql('ALTER TABLE planning_snapshot_demand_policies DROP structure_source');
        $this->addSql('ALTER TABLE planning_line_demand_policies DROP CONSTRAINT chk_demand_policies_structure_source');
        $this->addSql('ALTER TABLE planning_line_demand_policies DROP structure_source');
    }
}
