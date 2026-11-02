<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * docs/decisions.md D222 — "Générer les postes non pourvus" / "Générer cette
 * ligne" run as a third kind of PlanningJob, FILL, which names the lines it
 * was asked for; a generation that would replace a line's calendar must be
 * explicitly confirmed (replace_existing, recorded on its job); a member who
 * joined after a generation is appended to its snapshot when a completion
 * takes them into account (captured_late_at — the rows frozen at generation
 * time are never touched).
 *
 * Additive only: three columns, NULL or false on every existing row, and the
 * kind CHECK widened to accept FILL. No existing row is changed. down()
 * refuses while a FILL job or a late snapshot member exists, rather than
 * deleting them.
 */
final class Version20261102090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'PlanningJob FILL, confirmed regeneration, late snapshot members (D222)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE planning_jobs ADD target_line_stable_ids JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE planning_jobs ADD replace_existing BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE planning_snapshot_members ADD captured_late_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE planning_jobs DROP CONSTRAINT chk_planning_jobs_kind');
        $this->addSql("ALTER TABLE planning_jobs ADD CONSTRAINT chk_planning_jobs_kind CHECK (kind IN ('GENERATE', 'COMPLETE', 'FILL'))");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            0 !== (int) $this->connection->fetchOne("SELECT COUNT(*) FROM planning_jobs WHERE kind = 'FILL'"),
            'FILL jobs exist: they would violate the narrowed kind CHECK. Never deleted by a migration.',
        );
        $this->abortIf(
            0 !== (int) $this->connection->fetchOne('SELECT COUNT(*) FROM planning_snapshot_members WHERE captured_late_at IS NOT NULL'),
            'Late snapshot members exist: they could no longer be told apart from the members frozen with their snapshot.',
        );
        $this->addSql('ALTER TABLE planning_jobs DROP CONSTRAINT chk_planning_jobs_kind');
        $this->addSql("ALTER TABLE planning_jobs ADD CONSTRAINT chk_planning_jobs_kind CHECK (kind IN ('GENERATE', 'COMPLETE'))");
        $this->addSql('ALTER TABLE planning_snapshot_members DROP captured_late_at');
        $this->addSql('ALTER TABLE planning_jobs DROP replace_existing');
        $this->addSql('ALTER TABLE planning_jobs DROP target_line_stable_ids');
    }
}
