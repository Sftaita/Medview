<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Team → PlanningTeam restructure (docs/decisions.md D079/D080): a Team
 * stops being a global, shareable entity and becomes exclusively owned by
 * one Planning. Replaces `teams`/`team_members` with `planning_teams`/
 * `planning_team_members`, repoints every table that referenced `teams`
 * to `planning_teams`, and replaces the app-wide "one open membership"
 * partial unique index with a per-Planning one on the denormalized
 * `planning_id` column of `planning_team_members`.
 *
 * Hand-pruned from the raw `doctrine:migrations:diff` output: every
 * composite foreign key and hand-written partial/exclusion index from
 * earlier "hardening" migrations (D051-style, not representable in ORM
 * attribute mapping) is left untouched here, because none of them
 * actually reference the `teams`/`team_members` tables directly — they
 * only pair a domain table's own `team_id`/`team_member_id` column
 * (unchanged by this migration) with another domain table's matching
 * column. `doctrine:migrations:diff` cannot tell those apart from a real
 * change and proposes dropping/recreating them regardless; this file
 * removes that noise, as every migration in this project has since D051.
 *
 * Dev/UAT data note: at the time this migration was written, `teams`
 * held 2 manually-created QA fixture rows and `plannings`/`planning_lines`
 * held 1 row each from a browser smoke test — no real user data. This
 * migration does not attempt to carry that data forward into
 * `planning_teams` (a fixture Team had no owning Planning to attribute it
 * to, which is now mandatory) — see the final report for how to recreate
 * it after this migration runs.
 */
final class Version20260917091305 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Team -> PlanningTeam: a team belongs to exactly one Planning; membership uniqueness becomes per-Planning, not app-wide.';
    }

    public function up(Schema $schema): void
    {
        // 0. Dev/UAT fixture data cleanup (see class docblock): the only
        // non-empty rows anywhere in this subgraph are 2 manually-created
        // Teams, 1 Planning and its 1 PlanningLine, and their 2
        // FairnessPeriod/PlanningPeriod rows from a browser smoke test —
        // no real user data exists yet. None of it can be sanely carried
        // forward (a fixture Team has no owning Planning to attribute it
        // to, which this migration makes mandatory), so it is deleted here
        // rather than left as orphaned rows that would violate the new
        // foreign keys below.
        $this->addSql('DELETE FROM planning_lines');
        $this->addSql('DELETE FROM planning_periods');
        $this->addSql('DELETE FROM fairness_periods');
        $this->addSql('DELETE FROM plannings');

        // 1. Drop every FK that currently points at teams/team_members —
        // required before either table can be dropped. The composite FKs
        // that also happen to touch a team_id/team_member_id column are
        // deliberately NOT touched here (see class docblock).
        $this->addSql('ALTER TABLE duty_types DROP CONSTRAINT fk_b292847b296cd8ae');
        $this->addSql('ALTER TABLE duty_patterns DROP CONSTRAINT fk_e0140393296cd8ae');
        $this->addSql('ALTER TABLE duty_pattern_components DROP CONSTRAINT fk_8a8e7e04296cd8ae');
        $this->addSql('ALTER TABLE duty_group_instances DROP CONSTRAINT fk_c44e5594296cd8ae');
        $this->addSql('ALTER TABLE duties DROP CONSTRAINT fk_6148fb50296cd8ae');
        $this->addSql('ALTER TABLE fairness_periods DROP CONSTRAINT fk_c7406aca296cd8ae');
        $this->addSql('ALTER TABLE planning_periods DROP CONSTRAINT fk_ba7ac549296cd8ae');
        $this->addSql('ALTER TABLE planning_rule_sets DROP CONSTRAINT fk_6f19c0a5296cd8ae');
        $this->addSql('ALTER TABLE planning_lines DROP CONSTRAINT fk_dead3609296cd8ae');
        $this->addSql('ALTER TABLE duty_assignments DROP CONSTRAINT fk_ef50800cc292cd19');
        $this->addSql('ALTER TABLE team_member_participation_periods DROP CONSTRAINT fk_a903dfe0c292cd19');
        $this->addSql('ALTER TABLE team_member_non_participation_periods DROP CONSTRAINT fk_non_participation_periods_team_member');

        // 2. Drop the old global tables entirely (dev/UAT fixtures only —
        // see class docblock).
        $this->addSql('DROP TABLE team_members');
        $this->addSql('DROP TABLE teams');

        // 3. Create planning_teams — a PlanningTeam always belongs to
        // exactly one Planning (docs/decisions.md D079). No slug/timezone
        // columns: slug had no purpose once a team can no longer be
        // browsed/addressed outside its Planning, and timezone is now
        // delegated to Planning::getTimezone() (see PlanningTeam entity).
        $this->addSql(<<<'SQL'
            CREATE TABLE planning_teams (
              id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
              stable_id UUID NOT NULL,
              name VARCHAR(150) NOT NULL,
              active BOOLEAN NOT NULL,
              created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              planning_id INT NOT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_planning_teams_stable_id ON planning_teams (stable_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_planning_teams_id_planning_id ON planning_teams (id, planning_id)');
        // Index/constraint names below match exactly what
        // `doctrine:migrations:diff` itself generates for this simple,
        // fully ORM-mapped relation, so a future diff run sees no drift.
        $this->addSql('CREATE INDEX IDX_76E98C73D865311 ON planning_teams (planning_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE planning_teams
            ADD CONSTRAINT FK_76E98C73D865311 FOREIGN KEY (planning_id) REFERENCES plannings (id) ON DELETE RESTRICT
        SQL);

        // 4. Create planning_team_members. $planning_id is a deliberate
        // denormalization of planning_team.planning_id (docs/decisions.md
        // D080) — see the composite FK added in step 6, which guarantees
        // it can never drift from the PlanningTeam's own Planning.
        $this->addSql(<<<'SQL'
            CREATE TABLE planning_team_members (
              id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
              stable_id UUID NOT NULL,
              role VARCHAR(20) NOT NULL,
              membership_start DATE NOT NULL,
              membership_end DATE DEFAULT NULL,
              created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              planning_team_id INT NOT NULL,
              planning_id INT NOT NULL,
              user_id INT NOT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_planning_team_members_stable_id ON planning_team_members (stable_id)');
        $this->addSql('CREATE INDEX idx_planning_team_members_planning_team_id ON planning_team_members (planning_team_id)');
        $this->addSql('CREATE INDEX idx_planning_team_members_planning_id ON planning_team_members (planning_id)');
        $this->addSql('CREATE INDEX idx_planning_team_members_user_id ON planning_team_members (user_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE planning_team_members
            ADD CONSTRAINT chk_planning_team_members_dates CHECK ((membership_end IS NULL) OR (membership_end > membership_start))
        SQL);
        // Names below match `doctrine:migrations:diff`'s own output for
        // these three simple, fully ORM-mapped relations.
        $this->addSql(<<<'SQL'
            ALTER TABLE planning_team_members
            ADD CONSTRAINT FK_A8236158F4018545 FOREIGN KEY (planning_team_id) REFERENCES planning_teams (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE planning_team_members
            ADD CONSTRAINT FK_A82361583D865311 FOREIGN KEY (planning_id) REFERENCES plannings (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE planning_team_members
            ADD CONSTRAINT FK_A8236158A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
        SQL);
        // Composite FK guaranteeing planning_id always matches this row's
        // planning_team's own planning_id — the same technique as D051,
        // requires the uniq_planning_teams_id_planning_id index above.
        $this->addSql(<<<'SQL'
            ALTER TABLE planning_team_members
            ADD CONSTRAINT fk_planning_team_members_team_planning FOREIGN KEY (planning_team_id, planning_id) REFERENCES planning_teams (id, planning_id)
        SQL);
        // Replaces the abandoned app-wide uniq_team_members_open_membership
        // (docs/decisions.md D072, replaced by D080): at most one open
        // membership per (Planning, User), never per (Team, User) alone —
        // a User may hold simultaneous open memberships in different
        // Plannings.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_planning_team_members_open_membership ON planning_team_members (planning_id, user_id)
            WHERE (membership_end IS NULL)
        SQL);

        // 5. Repoint every simple team_id FK from teams to planning_teams —
        // column names are unchanged (only DutyType/DutyPattern/
        // DutyPatternComponent/DutyGroupInstance/Duty/FairnessPeriod/
        // PlanningPeriod/PlanningRuleSet's PHP *type* changed, not their
        // property name — see docs/decisions.md D079).
        $this->addSql(<<<'SQL'
            ALTER TABLE duty_types ADD CONSTRAINT fk_b292847b296cd8ae FOREIGN KEY (team_id) REFERENCES planning_teams (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE duty_patterns ADD CONSTRAINT fk_e0140393296cd8ae FOREIGN KEY (team_id) REFERENCES planning_teams (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE duty_pattern_components ADD CONSTRAINT fk_8a8e7e04296cd8ae FOREIGN KEY (team_id) REFERENCES planning_teams (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE duty_group_instances ADD CONSTRAINT fk_c44e5594296cd8ae FOREIGN KEY (team_id) REFERENCES planning_teams (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE duties ADD CONSTRAINT fk_6148fb50296cd8ae FOREIGN KEY (team_id) REFERENCES planning_teams (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE fairness_periods ADD CONSTRAINT fk_c7406aca296cd8ae FOREIGN KEY (team_id) REFERENCES planning_teams (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE planning_periods ADD CONSTRAINT fk_ba7ac549296cd8ae FOREIGN KEY (team_id) REFERENCES planning_teams (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE planning_rule_sets ADD CONSTRAINT fk_6f19c0a5296cd8ae FOREIGN KEY (team_id) REFERENCES planning_teams (id) ON DELETE RESTRICT
        SQL);

        // 6. Repoint the simple team_member_id FKs from team_members to
        // planning_team_members.
        $this->addSql(<<<'SQL'
            ALTER TABLE duty_assignments ADD CONSTRAINT fk_ef50800cc292cd19 FOREIGN KEY (team_member_id) REFERENCES planning_team_members (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE team_member_participation_periods ADD CONSTRAINT fk_a903dfe0c292cd19 FOREIGN KEY (team_member_id) REFERENCES planning_team_members (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE team_member_non_participation_periods ADD CONSTRAINT fk_non_participation_periods_team_member FOREIGN KEY (team_member_id) REFERENCES planning_team_members (id) ON DELETE CASCADE
        SQL);

        // 7. planning_lines: PlanningLine::$team -> $planningTeam. v1 keeps
        // strictly 1 PlanningTeam = 1 PlanningLine, so the old
        // UNIQUE(planning_id, team_id) becomes UNIQUE(planning_team_id)
        // alone (docs/decisions.md D079) — a PlanningTeam can never be
        // reused across Plannings in the first place, so the planning_id
        // half of the old constraint is now redundant.
        $this->addSql('DROP INDEX idx_dead3609296cd8ae');
        $this->addSql('DROP INDEX uniq_planning_lines_planning_team');
        $this->addSql('ALTER TABLE planning_lines RENAME COLUMN team_id TO planning_team_id');
        $this->addSql(<<<'SQL'
            ALTER TABLE planning_lines ADD CONSTRAINT fk_dead3609f4018545 FOREIGN KEY (planning_team_id) REFERENCES planning_teams (id) ON DELETE RESTRICT
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_planning_lines_planning_team ON planning_lines (planning_team_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE planning_lines DROP CONSTRAINT fk_dead3609f4018545');
        $this->addSql('DROP INDEX uniq_planning_lines_planning_team');
        $this->addSql('ALTER TABLE planning_lines RENAME COLUMN planning_team_id TO team_id');
        $this->addSql('CREATE INDEX idx_dead3609296cd8ae ON planning_lines (team_id)');

        $this->addSql('ALTER TABLE duty_types DROP CONSTRAINT fk_b292847b296cd8ae');
        $this->addSql('ALTER TABLE duty_patterns DROP CONSTRAINT fk_e0140393296cd8ae');
        $this->addSql('ALTER TABLE duty_pattern_components DROP CONSTRAINT fk_8a8e7e04296cd8ae');
        $this->addSql('ALTER TABLE duty_group_instances DROP CONSTRAINT fk_c44e5594296cd8ae');
        $this->addSql('ALTER TABLE duties DROP CONSTRAINT fk_6148fb50296cd8ae');
        $this->addSql('ALTER TABLE fairness_periods DROP CONSTRAINT fk_c7406aca296cd8ae');
        $this->addSql('ALTER TABLE planning_periods DROP CONSTRAINT fk_ba7ac549296cd8ae');
        $this->addSql('ALTER TABLE planning_rule_sets DROP CONSTRAINT fk_6f19c0a5296cd8ae');
        $this->addSql('ALTER TABLE duty_assignments DROP CONSTRAINT fk_ef50800cc292cd19');
        $this->addSql('ALTER TABLE team_member_participation_periods DROP CONSTRAINT fk_a903dfe0c292cd19');
        $this->addSql('ALTER TABLE team_member_non_participation_periods DROP CONSTRAINT fk_non_participation_periods_team_member');

        $this->addSql('DROP TABLE planning_team_members');
        $this->addSql('DROP TABLE planning_teams');

        $this->addSql(<<<'SQL'
            CREATE TABLE teams (
              id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
              stable_id UUID NOT NULL,
              name VARCHAR(150) NOT NULL,
              slug VARCHAR(150) NOT NULL,
              timezone VARCHAR(64) NOT NULL,
              active BOOLEAN NOT NULL,
              created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_teams_stable_id ON teams (stable_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_teams_slug ON teams (slug)');

        $this->addSql(<<<'SQL'
            CREATE TABLE team_members (
              id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
              stable_id UUID NOT NULL,
              team_id INT NOT NULL,
              user_id INT NOT NULL,
              role VARCHAR(20) NOT NULL,
              membership_start DATE NOT NULL,
              membership_end DATE DEFAULT NULL,
              created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_team_members_stable_id ON team_members (stable_id)');
        $this->addSql('CREATE INDEX idx_team_members_team_id ON team_members (team_id)');
        $this->addSql('CREATE INDEX idx_team_members_user_id ON team_members (user_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE team_members ADD CONSTRAINT chk_team_members_dates CHECK ((membership_end IS NULL) OR (membership_end > membership_start))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_team_members_open_membership ON team_members (user_id) WHERE (membership_end IS NULL)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE team_members ADD CONSTRAINT fk_bad9a3c8296cd8ae FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE team_members ADD CONSTRAINT fk_bad9a3c8a76ed395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE duty_types ADD CONSTRAINT fk_b292847b296cd8ae FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE duty_patterns ADD CONSTRAINT fk_e0140393296cd8ae FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE duty_pattern_components ADD CONSTRAINT fk_8a8e7e04296cd8ae FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE duty_group_instances ADD CONSTRAINT fk_c44e5594296cd8ae FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE duties ADD CONSTRAINT fk_6148fb50296cd8ae FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE fairness_periods ADD CONSTRAINT fk_c7406aca296cd8ae FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE planning_periods ADD CONSTRAINT fk_ba7ac549296cd8ae FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE planning_rule_sets ADD CONSTRAINT fk_6f19c0a5296cd8ae FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE planning_lines ADD CONSTRAINT fk_dead3609296cd8ae FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE duty_assignments ADD CONSTRAINT fk_ef50800cc292cd19 FOREIGN KEY (team_member_id) REFERENCES team_members (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE team_member_participation_periods ADD CONSTRAINT fk_a903dfe0c292cd19 FOREIGN KEY (team_member_id) REFERENCES team_members (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE team_member_non_participation_periods ADD CONSTRAINT fk_non_participation_periods_team_member FOREIGN KEY (team_member_id) REFERENCES team_members (id) ON DELETE CASCADE
        SQL);

        $this->addSql('CREATE UNIQUE INDEX uniq_planning_lines_planning_team ON planning_lines (planning_id, team_id)');
    }
}
