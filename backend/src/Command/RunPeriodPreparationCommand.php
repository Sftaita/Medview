<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\PeriodPreparationRunner;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs the announcements and reminders the period preparations planned, and
 * sends their emails (docs/decisions.md D210) — every 10 minutes from the
 * host crontab, under `flock -n` (docs/deployment.md §5 septies). Safe to run
 * at any time, even twice at once or after a long outage: everything is
 * checked again when it runs, each email is claimed before being sent, a
 * person never gets the same announcement or reminder twice, and when several
 * reminders of a period are overdue only the latest one goes out.
 */
#[AsCommand(name: 'app:period-preparation:run', description: 'Run the period announcements and reminders that are due, and send their emails (idempotent, never in parallel).')]
final class RunPeriodPreparationCommand extends Command
{
    public function __construct(
        private readonly PeriodPreparationRunner $runner,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $report = $this->runner->run($this->clock->now());
        $io->success(\sprintf(
            '%d announcement(s), %d reminder(s) run, %d skipped; emails: %d sent, %d failed, %d cancelled; %d error(s).',
            $report['announcements'],
            $report['reminders'],
            $report['skipped'],
            $report['sent'],
            $report['failed'],
            $report['cancelled'],
            $report['errors'],
        ));

        return 0 === $report['failed'] + $report['errors'] ? Command::SUCCESS : Command::FAILURE;
    }
}
