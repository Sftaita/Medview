<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Persist the UNSAT diagnostics of a solve (docs/decisions.md D130): until
 * now `UnsatReport` only ever existed in memory, serialized once into the
 * HTTP response of `POST /planning-generations/{id}/solve`, then lost. A
 * planning-result read view needs it durably, without ever recomputing a
 * causality after the fact — one nullable JSON column, additive only.
 */
final class Version20260923090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add planning_generations.diagnostics (persisted UnsatReport, D130)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE planning_generations ADD diagnostics JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE planning_generations DROP diagnostics');
    }
}
