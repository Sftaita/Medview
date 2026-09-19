<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `PlanningGeneration.restPolicy` (docs/decisions.md D105) — LEGAL_MIN_REST/
 * TEAM_MIN_REST become per-generation options instead of a team-wide
 * default: `legal_min_rest_enabled`/`legal_min_rest_hours`/
 * `team_min_rest_enabled`/`team_min_rest_hours`, fixed once at creation,
 * never mutated afterwards. `*_hours` stays NULL exactly when its own
 * `*_enabled` is false (`App\Entity\RestPolicyOptions`'s own invariant,
 * enforced in PHP, not duplicated as a DB CHECK constraint here).
 *
 * Hand-pruned from the raw `doctrine:migrations:diff` output: every
 * composite foreign key and hand-written partial/exclusion index from
 * earlier "hardening" migrations (D051-style, not representable in ORM
 * attribute mapping) is left untouched here — none of them reference
 * `planning_generations` — `doctrine:migrations:diff` cannot tell those
 * apart from a real change and proposes dropping/recreating them
 * regardless; this file removes that noise, as every migration in this
 * project has since D051.
 *
 * Existing rows: both `*_enabled` columns backfill to `false` (matching
 * `RestPolicyOptions::none()`, the exact pre-Lot-6D.1 behavior — only
 * `CONFLICT` ever applied), so no historical generation's interpretation
 * changes.
 */
final class Version20260919053312 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'PlanningGeneration.restPolicy: LEGAL_MIN_REST/TEAM_MIN_REST become per-generation options, fixed at creation.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE planning_generations ADD legal_min_rest_enabled BOOLEAN NOT NULL DEFAULT false');
        $this->addSql('ALTER TABLE planning_generations ADD legal_min_rest_hours INT DEFAULT NULL');
        $this->addSql('ALTER TABLE planning_generations ADD team_min_rest_enabled BOOLEAN NOT NULL DEFAULT false');
        $this->addSql('ALTER TABLE planning_generations ADD team_min_rest_hours INT DEFAULT NULL');
        $this->addSql('ALTER TABLE planning_generations ALTER COLUMN legal_min_rest_enabled DROP DEFAULT');
        $this->addSql('ALTER TABLE planning_generations ALTER COLUMN team_min_rest_enabled DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE planning_generations DROP legal_min_rest_enabled');
        $this->addSql('ALTER TABLE planning_generations DROP legal_min_rest_hours');
        $this->addSql('ALTER TABLE planning_generations DROP team_min_rest_enabled');
        $this->addSql('ALTER TABLE planning_generations DROP team_min_rest_hours');
    }
}
