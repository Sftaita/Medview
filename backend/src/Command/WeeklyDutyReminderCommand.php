<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\WeeklyDutyReminderService;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Saturday's "Vos gardes de la semaine prochaine" run (docs/decisions.md
 * D146) — scheduled by the host crontab (docs/deployment.md), idempotent,
 * so running it twice is harmless. `--now` replays a given instant (tests,
 * a missed Saturday).
 */
#[AsCommand(name: 'app:duty-reminders:weekly', description: 'Email every person their duties of next week (published plannings only, nothing to people without duties).')]
final class WeeklyDutyReminderCommand extends Command
{
    public function __construct(
        private readonly WeeklyDutyReminderService $reminderService,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('now', null, InputOption::VALUE_REQUIRED, 'The instant to run as (any strtotime/ISO-8601 value), default: now.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $nowOption = $input->getOption('now');
        try {
            $now = \is_string($nowOption) ? new \DateTimeImmutable($nowOption) : $this->clock->now();
        } catch (\Exception) {
            $io->error('--now is not a valid date/time.');

            return Command::INVALID;
        }

        $report = $this->reminderService->run($now);
        $io->success(\sprintf(
            '%d planning(s) examined — %d reminder(s) sent, %d already sent, %d failed.',
            $report->planningCount,
            $report->sentCount,
            $report->alreadySentCount,
            $report->failedCount,
        ));

        return 0 === $report->failedCount ? Command::SUCCESS : Command::FAILURE;
    }
}
