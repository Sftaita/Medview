<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\DutySwapNotificationSender;
use App\Service\DutySwapService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Housekeeping of the duty swaps (docs/duty-swaps.md §8, §12) — scheduled
 * by the host crontab next to `app:publication-notifications:retry`
 * (docs/deployment.md §5 quater):
 *
 * 1. closes, with their events, the open requests that can no longer
 *    conclude — their duty started (EXPIRED) or changed hands (OBSOLETE).
 *    The screens already do this for what they show; this catches the rest,
 *    so the history says when it happened even if nobody looked;
 * 2. retries the swap emails that failed or never went out — never in
 *    parallel, never again once SENT, each with its frozen content. Like the
 *    publication emails, an email accepted by SMTP just before the process
 *    died may be sent twice (possible duplicate rather than possible loss).
 *
 * Safe to run at any time, even twice at once.
 */
#[AsCommand(name: 'app:duty-swaps:maintain', description: 'Close the swap requests that can no longer conclude and retry the swap emails that failed or were never sent.')]
final class DutySwapMaintenanceCommand extends Command
{
    public function __construct(
        private readonly DutySwapService $swapService,
        private readonly DutySwapNotificationSender $sender,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $closed = $this->swapService->settleAllOpen();
        $report = $this->sender->retryDue();
        $io->success(\sprintf('%d swap request(s)/proposal(s) closed; %d email(s) retried — %d sent, %d failed.', $closed, $report['attempted'], $report['sent'], $report['failed']));

        return 0 === $report['failed'] ? Command::SUCCESS : Command::FAILURE;
    }
}
