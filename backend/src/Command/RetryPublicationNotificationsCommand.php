<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\PublicationNotificationSender;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Retries the (re)publication emails that failed or never went out
 * (docs/decisions.md D172) — scheduled by the host crontab
 * (docs/deployment.md §5 quater). Harmless to run at any time or twice at
 * once: each email is claimed before being sent, a sent one is never sent
 * again, and each keeps its own frozen content and publication PDF.
 */
#[AsCommand(name: 'app:publication-notifications:retry', description: 'Retry the publication emails that failed or were never sent (at most once per recipient).')]
final class RetryPublicationNotificationsCommand extends Command
{
    public function __construct(
        private readonly PublicationNotificationSender $sender,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $report = $this->sender->retryDue();
        $io->success(\sprintf('%d email(s) retried — %d sent, %d failed.', $report['attempted'], $report['sent'], $report['failed']));

        return 0 === $report['failed'] ? Command::SUCCESS : Command::FAILURE;
    }
}
