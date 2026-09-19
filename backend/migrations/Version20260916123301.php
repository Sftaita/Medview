<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tightens the "at most one open TeamMember stint" invariant from
 * per-(Team, User) to per-User (docs/decisions.md D072, docs/planning.md
 * §6): a User may now only ever have one open membership at a time,
 * across every Team, not just within one Team. Hand-written, not
 * `doctrine:migrations:diff` output — this partial index is not
 * represented in Doctrine's ORM mapping (D051), same situation as the
 * index it replaces.
 *
 * Zero rows in team_members at the time this was written (confirmed via
 * `SELECT count(*) FROM team_members`), so no data conflicts with the
 * tighter constraint — nothing to migrate/backfill.
 */
final class Version20260916123301 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TeamMember: at most one open membership per User, across every Team (was per Team+User) — D072.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_team_members_open_membership');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_team_members_open_membership
              ON team_members (user_id) WHERE membership_end IS NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_team_members_open_membership');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_team_members_open_membership
              ON team_members (team_id, user_id) WHERE membership_end IS NULL
        SQL);
    }
}
