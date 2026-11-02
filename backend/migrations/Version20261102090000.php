<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * docs/decisions.md D222 — "Générer les postes non pourvus" / "Générer cette
 * ligne" run as a third kind of PlanningJob, FILL, which names the lines it
 * was asked for.
 *
 * Additive only: one nullable column (NULL on every existing row) and the
 * kind CHECK widened to accept FILL. No existing row is read or changed.
 * down() refuses while a FILL job exists, rather than deleting it.
 */
final class Version20261102090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'PlanningJob FILL: the lines asked for (D222)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE planning_jobs ADD target_line_stable_ids JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE planning_jobs DROP CONSTRAINT chk_planning_jobs_kind');
        $this->addSql("ALTER TABLE planning_jobs ADD CONSTRAINT chk_planning_jobs_kind CHECK (kind IN ('GENERATE', 'COMPLETE', 'FILL'))");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            0 !== (int) $this->connection->fetchOne("SELECT COUNT(*) FROM planning_jobs WHERE kind = 'FILL'"),
            'FILL jobs exist: they would violate the narrowed kind CHECK. Never deleted by a migration.',
        );
        $this->addSql('ALTER TABLE planning_jobs DROP CONSTRAINT chk_planning_jobs_kind');
        $this->addSql("ALTER TABLE planning_jobs ADD CONSTRAINT chk_planning_jobs_kind CHECK (kind IN ('GENERATE', 'COMPLETE'))");
        $this->addSql('ALTER TABLE planning_jobs DROP target_line_stable_ids');
    }
}
