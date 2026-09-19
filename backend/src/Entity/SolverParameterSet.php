<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SolverParameterSetRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A versioned, immutable, system-wide set of real solver execution
 * parameters (docs/allocation-algorithm.md §14, docs/decisions.md D106) —
 * never a hidden constant inside `OrToolsPlanningSolver`. Append-only,
 * exactly like `PlanningRuleSet`'s own versioning discipline: a row is
 * never edited or deleted once created, and a `PlanningGeneration` records
 * the exact `$version` it solved against, so an old generation's recorded
 * parameters stay interpretable even after a later version is introduced.
 *
 * Deliberately narrow — only fields with a real consumer today:
 * `$timeoutSeconds` is threaded through to CP-SAT's own
 * `max_time_in_seconds` (per individual `Solve()` call, docs/planning-solver.md
 * §Timeouts — D093 is no longer true once this exists), `$numWorkers`
 * replaces the `numWorkers = 1` literal `CpSatPayloadBuilder` previously
 * hardcoded at three separate call sites. No `deterministicMode`/
 * `searchStrategy`/`preprocessingOptions` field exists — nothing in this
 * codebase reads them, and a decorative field here would be exactly the
 * kind of fabricated data this project refuses to add ahead of a real
 * need (CLAUDE.md).
 *
 * System-wide, not per-team: unlike `PlanningRuleSet` (a business policy an
 * equipe owns), the solve timeout/worker count is an operational/engineering
 * choice MedVue itself is the authority on — there is no external
 * "juridiction" to guess here (contrast with D036/D105's `LEGAL_MIN_REST`,
 * where MedVue has no authority at all). Choosing and documenting a value
 * is legitimate; inventing a legal duration is not.
 */
#[ORM\Entity(repositoryClass: SolverParameterSetRepository::class)]
#[ORM\Table(name: 'solver_parameter_sets')]
#[ORM\UniqueConstraint(name: 'uniq_solver_parameter_sets_stable_id', columns: ['stable_id'])]
#[ORM\UniqueConstraint(name: 'uniq_solver_parameter_sets_version', columns: ['version'])]
class SolverParameterSet
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid')]
    private Uuid $stableId;

    #[ORM\Column]
    private int $version;

    #[ORM\Column]
    private int $timeoutSeconds;

    #[ORM\Column]
    private int $numWorkers;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(int $version, int $timeoutSeconds, int $numWorkers)
    {
        if ($version < 1) {
            throw new \InvalidArgumentException('version must be >= 1.');
        }

        if ($timeoutSeconds <= 0) {
            throw new \InvalidArgumentException('timeoutSeconds must be strictly positive.');
        }

        if ($numWorkers <= 0) {
            throw new \InvalidArgumentException('numWorkers must be strictly positive.');
        }

        $this->stableId = Uuid::v7();
        $this->version = $version;
        $this->timeoutSeconds = $timeoutSeconds;
        $this->numWorkers = $numWorkers;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStableId(): Uuid
    {
        return $this->stableId;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getTimeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    public function getNumWorkers(): int
    {
        return $this->numWorkers;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
