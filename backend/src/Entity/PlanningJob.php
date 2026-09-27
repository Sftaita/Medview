<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlanningJobRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One engine operation on a Planning, executed by the worker outside any
 * HTTP request (docs/decisions.md D149). It is the only source of truth
 * the frontend polls ("Génération en cours…", then the result) — a page
 * reload, another tab or another manager all find it through the API.
 *
 * At most one active (QUEUED or RUNNING) job per Planning: enforced by a
 * partial unique index, so a double click, two browsers or a completion
 * requested during a generation can never start two engine runs on the same
 * Planning — the second insert fails, whatever the frontend showed.
 *
 * $heartbeatAt is refreshed by the worker while it works (every few seconds
 * during the CP-SAT subprocess, and between the pipeline's steps). A RUNNING
 * job whose heartbeat stopped long ago lost its worker (killed, container
 * restarted) and is failed by PlanningJobRecovery — never merely because it
 * takes long.
 *
 * $failureDetail is internal (exception class and message) and never
 * serialized to the API; $failureCode is the stable, user-safe reason.
 *
 * No setter: every transition after creation (claim, heartbeat, success,
 * failure) is one conditional SQL UPDATE in PlanningJobStore — atomic
 * against a concurrent worker or recovery, and still possible when the
 * worker's EntityManager was closed by the very error being recorded.
 */
#[ORM\Entity(repositoryClass: PlanningJobRepository::class)]
#[ORM\Table(name: 'planning_jobs')]
#[ORM\UniqueConstraint(name: 'uniq_planning_jobs_stable_id', columns: ['stable_id'])]
#[ORM\Index(columns: ['planning_id', 'created_at'], name: 'idx_planning_jobs_planning')]
#[ORM\Index(columns: ['status'], name: 'idx_planning_jobs_status')]
class PlanningJob
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid')]
    private Uuid $stableId;

    #[ORM\ManyToOne(targetEntity: Planning::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Planning $planning;

    #[ORM\Column(length: 10, enumType: PlanningJobKind::class)]
    private PlanningJobKind $kind;

    #[ORM\Column(length: 10, enumType: PlanningJobStatus::class)]
    private PlanningJobStatus $status;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $requestedBy;

    /** GENERATE only: the rest policy chosen at launch (RestPolicyOptions::toArray()), replayed by the worker. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $restPolicy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $heartbeatAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /** What the operation did, per line — the same summary the synchronous endpoints used to return. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $outcome = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $failureCode = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $failureDetail = null;

    /**
     * @param array<string, mixed>|null $restPolicy
     */
    public function __construct(Planning $planning, PlanningJobKind $kind, User $requestedBy, ?array $restPolicy = null)
    {
        $this->stableId = Uuid::v7();
        $this->planning = $planning;
        $this->kind = $kind;
        $this->requestedBy = $requestedBy;
        $this->restPolicy = $restPolicy;
        $this->status = PlanningJobStatus::QUEUED;
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

    public function getPlanning(): Planning
    {
        return $this->planning;
    }

    public function getKind(): PlanningJobKind
    {
        return $this->kind;
    }

    public function getStatus(): PlanningJobStatus
    {
        return $this->status;
    }

    public function getRequestedBy(): User
    {
        return $this->requestedBy;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getRestPolicy(): ?array
    {
        return $this->restPolicy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getHeartbeatAt(): ?\DateTimeImmutable
    {
        return $this->heartbeatAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getOutcome(): ?array
    {
        return $this->outcome;
    }

    public function getFailureCode(): ?string
    {
        return $this->failureCode;
    }

    public function getFailureDetail(): ?string
    {
        return $this->failureDetail;
    }
}
