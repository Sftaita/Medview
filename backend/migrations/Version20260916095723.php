<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Eligibility lot: freezes User::isActive() onto PlanningSnapshotMember
 * (docs/eligibility.md §USER_INACTIVE, D067) so EligibilityService never
 * has to fall back to a live read for a datum the snapshot should already
 * carry. Zero existing rows in planning_snapshot_members at the time this
 * was written, so the column is added directly NOT NULL — no
 * nullable-then-backfill dance needed (contrast with users.stable_id in
 * Version20260915212445).
 *
 * Pruned from the raw doctrine:migrations:diff output: the composite FKs/
 * EXCLUDE constraints/partial unique indexes from the planning-domain
 * hardening migration are not represented in Doctrine's ORM mapping by
 * design (D051) — diff always proposes dropping them as "unsynchronized",
 * and that must never be applied (same situation as Version20260916084920).
 */
final class Version20260916095723 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'PlanningSnapshotMember.active — freezes User::isActive() at snapshot time for USER_INACTIVE eligibility checks.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE planning_snapshot_members ADD active BOOLEAN NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE planning_snapshot_members DROP active');
    }
}
