<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * One open membership per (PlanningTeam, User), no longer per (Planning,
 * User) — docs/decisions.md D160, relaxing D080.
 *
 * A surgeon may now be a member of several lines of the same Planning at
 * once (e.g. holder on the main line, reinforcement on a secondary line).
 * What stays forbidden is two open stints in the *same* team: the partial
 * unique index moves from (planning_id, user_id) to (planning_team_id,
 * user_id). The denormalized planning_id column and its composite FK
 * (D081) are kept: PlanningVoter and findIntersectingForPlanning() still
 * read it.
 *
 * No data change: every existing row already satisfies the new, weaker
 * rule. down() restores the D080 index and therefore fails if a User has
 * since joined two teams of one Planning — intended: rolling back must not
 * silently close someone's membership.
 */
final class Version20260928090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'One open membership per (planning team, user) instead of per (planning, user) (D160)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_planning_team_members_open_membership');
        $this->addSql('CREATE UNIQUE INDEX uniq_planning_team_members_open_team_membership ON planning_team_members (planning_team_id, user_id) WHERE (membership_end IS NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_planning_team_members_open_team_membership');
        $this->addSql('CREATE UNIQUE INDEX uniq_planning_team_members_open_membership ON planning_team_members (planning_id, user_id) WHERE (membership_end IS NULL)');
    }
}
