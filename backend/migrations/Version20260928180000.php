<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Conditional duties (docs/decisions.md D163):
 *
 * - `duties.coverage_source_id`: for a CONDITIONAL duty, the duty of the
 *   source line whose holder decides whether it is needed — an explicit,
 *   immutable relation (RESTRICT: a duty is never deleted anyway).
 * - CHECKs: demand_type is one of REQUIRED/OPTIONAL/CONDITIONAL; a duty
 *   has a coverage source if and only if it is CONDITIONAL; never itself.
 *   The rest (same Planning, another line, same day, source not
 *   conditional, source = the policy's source line) spans several rows or
 *   tables: guarded by the Duty constructor and DutyMaterializationService.
 *
 * No existing row changes: every existing duty is REQUIRED or OPTIONAL,
 * without coverage source.
 */
final class Version20260928180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Conditional duties and their explicit coverage source (D163)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE duties ADD coverage_source_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE duties ADD CONSTRAINT FK_6148FB50BC7881F5 FOREIGN KEY (coverage_source_id) REFERENCES duties (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_6148FB50BC7881F5 ON duties (coverage_source_id)');
        $this->addSql("ALTER TABLE duties ADD CONSTRAINT chk_duties_demand_type CHECK (demand_type IN ('REQUIRED', 'OPTIONAL', 'CONDITIONAL'))");
        $this->addSql("ALTER TABLE duties ADD CONSTRAINT chk_duties_coverage_source CHECK ((demand_type = 'CONDITIONAL') = (coverage_source_id IS NOT NULL))");
        $this->addSql('ALTER TABLE duties ADD CONSTRAINT chk_duties_coverage_source_not_self CHECK (coverage_source_id IS NULL OR coverage_source_id <> id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE duties DROP CONSTRAINT chk_duties_coverage_source_not_self');
        $this->addSql('ALTER TABLE duties DROP CONSTRAINT chk_duties_coverage_source');
        $this->addSql('ALTER TABLE duties DROP CONSTRAINT chk_duties_demand_type');
        $this->addSql('ALTER TABLE duties DROP CONSTRAINT FK_6148FB50BC7881F5');
        $this->addSql('DROP INDEX IDX_6148FB50BC7881F5');
        $this->addSql('ALTER TABLE duties DROP coverage_source_id');
    }
}
