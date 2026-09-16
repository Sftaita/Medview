<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Integrity hardening for the planning domain socle (docs/planning-domain.md
 * "Contraintes d'intégrité"). Everything here is a database-level guarantee
 * on top of the base schema from the previous migration, on the principle
 * (explicitly requested for this lot) that the database should make
 * impossible states impossible rather than relying on PHP alone:
 *
 *  - EXCLUDE constraints (btree_gist) forbid overlapping date ranges for
 *    FairnessPeriod (per team) and TeamMemberParticipationPeriod (per
 *    team member) — no amount of application-level bugs can create one.
 *  - Partial unique indexes forbid more than one open TeamMember
 *    membership per (team, user), and more than one ACTIVE PlanningRuleSet
 *    per team.
 *  - Composite foreign keys forbid a child row from pointing at a parent
 *    belonging to a different Team, or a Duty from pointing at a
 *    DutyGroupInstance of a different PlanningPeriod — invariants a plain
 *    single-column foreign key or CHECK constraint cannot express.
 *  - CHECK constraints are added as defense in depth for date ordering and
 *    positivity rules already enforced in the entity constructors.
 */
final class Version20260915212500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Planning domain integrity hardening: exclusion constraints, partial unique indexes, composite foreign keys, CHECK constraints.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS btree_gist');

        // No two FairnessPeriods of the same team may overlap in time — a
        // duty must belong to exactly one equity ledger. FairnessPeriods
        // are only ever independently INSERTed (never rotated in place),
        // so an immediate (default) check is correct and simplest here.
        $this->addSql(<<<'SQL'
            ALTER TABLE fairness_periods ADD CONSTRAINT excl_fairness_periods_no_overlap
              EXCLUDE USING gist (team_id WITH =, daterange(starts_at, ends_at, '[)') WITH &&)
        SQL);

        // No two participation periods of the same team member may
        // overlap. DEFERRABLE INITIALLY DEFERRED: ParticipationPeriodService
        // closes the currently open segment (UPDATE) and opens the next
        // one (INSERT) within a single flush() — an immediate per-statement
        // check would see a momentarily-overlapping intermediate state
        // between those two statements and reject a perfectly legal
        // change. ParticipationPeriodService explicitly forces the check
        // right after flush() (SET CONSTRAINTS ... IMMEDIATE) so a genuine
        // violation still surfaces immediately rather than silently
        // waiting for an eventual COMMIT.
        $this->addSql(<<<'SQL'
            ALTER TABLE team_member_participation_periods ADD CONSTRAINT excl_participation_periods_no_overlap
              EXCLUDE USING gist (team_member_id WITH =, daterange(valid_from, valid_to, '[)') WITH &&)
              DEFERRABLE INITIALLY DEFERRED
        SQL);

        // At most one open (unended) membership per (team, user).
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_team_members_open_membership
              ON team_members (team_id, user_id) WHERE membership_end IS NULL
        SQL);

        // At most one ACTIVE PlanningRuleSet per team.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_rule_sets_active_per_team
              ON planning_rule_sets (team_id) WHERE status = 'ACTIVE'
        SQL);

        // Composite FKs: a PlanningPeriod's team must match its FairnessPeriod's team.
        $this->addSql(<<<'SQL'
            ALTER TABLE planning_periods ADD CONSTRAINT fk_planning_periods_fairness_period_team
              FOREIGN KEY (fairness_period_id, team_id) REFERENCES fairness_periods (id, team_id) NOT DEFERRABLE
        SQL);

        // A DutyGroupInstance's team must match both its PlanningPeriod's and its DutyPattern's team.
        $this->addSql(<<<'SQL'
            ALTER TABLE duty_group_instances ADD CONSTRAINT fk_duty_group_instances_planning_period_team
              FOREIGN KEY (planning_period_id, team_id) REFERENCES planning_periods (id, team_id) NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE duty_group_instances ADD CONSTRAINT fk_duty_group_instances_pattern_team
              FOREIGN KEY (pattern_id, team_id) REFERENCES duty_patterns (id, team_id) NOT DEFERRABLE
        SQL);

        // A DutyPatternComponent's team must match both its DutyPattern's and its DutyType's team.
        $this->addSql(<<<'SQL'
            ALTER TABLE duty_pattern_components ADD CONSTRAINT fk_pattern_components_pattern_team
              FOREIGN KEY (pattern_id, team_id) REFERENCES duty_patterns (id, team_id) NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE duty_pattern_components ADD CONSTRAINT fk_pattern_components_duty_type_team
              FOREIGN KEY (duty_type_id, team_id) REFERENCES duty_types (id, team_id) NOT DEFERRABLE
        SQL);

        // A Duty's team must match both its PlanningPeriod's and its DutyType's team, and —
        // the invariant explicitly required by this lot — a Duty's group instance (if any)
        // must belong to this exact same PlanningPeriod, never another one.
        $this->addSql(<<<'SQL'
            ALTER TABLE duties ADD CONSTRAINT fk_duties_planning_period_team
              FOREIGN KEY (planning_period_id, team_id) REFERENCES planning_periods (id, team_id) NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE duties ADD CONSTRAINT fk_duties_duty_type_team
              FOREIGN KEY (duty_type_id, team_id) REFERENCES duty_types (id, team_id) NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE duties ADD CONSTRAINT fk_duties_group_instance_planning_period
              FOREIGN KEY (group_instance_id, planning_period_id) REFERENCES duty_group_instances (id, planning_period_id) NOT DEFERRABLE
        SQL);

        // Defense-in-depth CHECK constraints (already validated in entity constructors).
        $this->addSql('ALTER TABLE fairness_periods ADD CONSTRAINT chk_fairness_periods_dates CHECK (ends_at > starts_at)');
        $this->addSql('ALTER TABLE planning_periods ADD CONSTRAINT chk_planning_periods_dates CHECK (ends_at > starts_at)');
        $this->addSql('ALTER TABLE team_members ADD CONSTRAINT chk_team_members_dates CHECK (membership_end IS NULL OR membership_end > membership_start)');
        $this->addSql('ALTER TABLE team_member_participation_periods ADD CONSTRAINT chk_participation_periods_dates CHECK (valid_to IS NULL OR valid_to > valid_from)');
        $this->addSql('ALTER TABLE team_member_participation_periods ADD CONSTRAINT chk_participation_factor_positive CHECK (participation_factor > 0)');
        $this->addSql('ALTER TABLE duties ADD CONSTRAINT chk_duties_dates CHECK (ends_at > starts_at)');
        $this->addSql('ALTER TABLE duty_types ADD CONSTRAINT chk_duty_types_workload_positive CHECK (workload_value > 0)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE duty_types DROP CONSTRAINT chk_duty_types_workload_positive');
        $this->addSql('ALTER TABLE duties DROP CONSTRAINT chk_duties_dates');
        $this->addSql('ALTER TABLE team_member_participation_periods DROP CONSTRAINT chk_participation_factor_positive');
        $this->addSql('ALTER TABLE team_member_participation_periods DROP CONSTRAINT chk_participation_periods_dates');
        $this->addSql('ALTER TABLE team_members DROP CONSTRAINT chk_team_members_dates');
        $this->addSql('ALTER TABLE planning_periods DROP CONSTRAINT chk_planning_periods_dates');
        $this->addSql('ALTER TABLE fairness_periods DROP CONSTRAINT chk_fairness_periods_dates');

        $this->addSql('ALTER TABLE duties DROP CONSTRAINT fk_duties_group_instance_planning_period');
        $this->addSql('ALTER TABLE duties DROP CONSTRAINT fk_duties_duty_type_team');
        $this->addSql('ALTER TABLE duties DROP CONSTRAINT fk_duties_planning_period_team');
        $this->addSql('ALTER TABLE duty_pattern_components DROP CONSTRAINT fk_pattern_components_duty_type_team');
        $this->addSql('ALTER TABLE duty_pattern_components DROP CONSTRAINT fk_pattern_components_pattern_team');
        $this->addSql('ALTER TABLE duty_group_instances DROP CONSTRAINT fk_duty_group_instances_pattern_team');
        $this->addSql('ALTER TABLE duty_group_instances DROP CONSTRAINT fk_duty_group_instances_planning_period_team');
        $this->addSql('ALTER TABLE planning_periods DROP CONSTRAINT fk_planning_periods_fairness_period_team');

        $this->addSql('DROP INDEX uniq_rule_sets_active_per_team');
        $this->addSql('DROP INDEX uniq_team_members_open_membership');
        $this->addSql('ALTER TABLE team_member_participation_periods DROP CONSTRAINT excl_participation_periods_no_overlap');
        $this->addSql('ALTER TABLE fairness_periods DROP CONSTRAINT excl_fairness_periods_no_overlap');
    }
}
