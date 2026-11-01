<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * docs/decisions.md D218 — once a period has a preparation, its indicative
 * deadline is the preparation's, and nothing else. A preparation written
 * before Version20261101090000 has none, while the open collections of its
 * period may carry one set from the planning settings: it takes the latest
 * of them, so the deadline the participants were told stays the one shown.
 *
 * Only the new column is written (NULL → a date); no collection changes.
 * down() leaves it: the column itself goes with Version20261101090000.
 */
final class Version20261101090100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A period preparation takes the deadline its open collections already carry (D218)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE period_preparations pp
               SET declaration_deadline_on = c.deadline
              FROM (
                SELECT planning_cycle_id, MAX(deadline) AS deadline
                  FROM availability_collections
                 WHERE status = 'OPEN' AND deadline IS NOT NULL
                 GROUP BY planning_cycle_id
              ) c
             WHERE c.planning_cycle_id = pp.planning_cycle_id
               AND pp.declaration_deadline_on IS NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Nothing to undo: the column is dropped by Version20261101090000::down().
    }
}
