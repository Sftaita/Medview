<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `duty_patterns.recurring` (docs/decisions.md D136) — distinct from
 * `active`: marks a pattern as belonging to a PlanningLine's weekly
 * recurring structure (`WeekStructureService`), so `WeeklyDutyCalendarService`
 * never re-anchors/re-materializes an unrelated one-off `active = true`
 * pattern (e.g. a manually-built test fixture group) onto every Monday of
 * a period. Existing rows default to `false` — nothing predating this lot
 * was ever meant to recur weekly.
 */
final class Version20260924100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add duty_patterns.recurring, distinct from active (D136)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE duty_patterns ADD recurring BOOLEAN NOT NULL DEFAULT false');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE duty_patterns DROP recurring');
    }
}
