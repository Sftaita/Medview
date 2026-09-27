<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\PlanningJobRecovery;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;

/**
 * A worker that starts (after a deploy, a crash or a server reboot) first
 * fails the jobs its dead predecessor left RUNNING or never started
 * (docs/decisions.md D149) — the same rule PlanningJobRecovery applies on
 * every read of a Planning's job state.
 */
#[AsEventListener(event: WorkerStartedEvent::class)]
final class RecoverPlanningJobsOnWorkerStart
{
    public function __construct(
        private readonly PlanningJobRecovery $recovery,
    ) {
    }

    public function __invoke(WorkerStartedEvent $event): void
    {
        $this->recovery->recover();
    }
}
