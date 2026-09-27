<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\PlanningJobRecovery;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Manual run of PlanningJobRecovery (docs/decisions.md D149) — it also runs
 * by itself when the worker starts and whenever a job state is read.
 */
#[AsCommand(name: 'app:planning-jobs:recover', description: 'Fail planning jobs whose worker died (no heartbeat) or that no worker ever started.')]
final class RecoverPlanningJobsCommand extends Command
{
    public function __construct(
        private readonly PlanningJobRecovery $recovery,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        (new SymfonyStyle($input, $output))->success(\sprintf('%d abandoned planning job(s) marked FAILED.', $this->recovery->recover()));

        return Command::SUCCESS;
    }
}
