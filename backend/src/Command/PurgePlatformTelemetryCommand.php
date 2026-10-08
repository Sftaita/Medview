<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Admin\TelemetryRetention;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Applies the retention policy of the platform telemetry (docs/admin.md
 * §5.4): activity days after 400 days, technical errors after 90 days. The
 * audit log is never purged. Idempotent; scheduled daily by the host
 * crontab (docs/deployment.md §5 quinquies).
 */
#[AsCommand(name: 'app:platform:purge-telemetry', description: 'Delete platform activity days and technical errors past their retention period (never the audit log).')]
final class PurgePlatformTelemetryCommand extends Command
{
    public function __construct(private readonly TelemetryRetention $retention)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = $this->retention->purge();
        (new SymfonyStyle($input, $output))->success(\sprintf(
            '%d activity day(s) and %d technical error(s) purged.',
            $report['activityDays'],
            $report['technicalErrors'],
        ));

        return Command::SUCCESS;
    }
}
