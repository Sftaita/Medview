<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\SurgicalHubLinkRepository;
use App\Service\SurgicalHub\SurgicalHubLeaveSyncService;
use App\Service\SurgicalHub\SurgicalHubSyncStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Periodic synchronisation of SurgicalHub leave (docs/surgicalhub-integration.md
 * §7.4, decision D7): every 30 minutes from the host crontab, under `flock`,
 * like `app:duty-swaps:maintain` (docs/deployment.md). Never in the Messenger
 * worker, which is busy with long OR-Tools solves.
 *
 * Every active association, never-synchronised ones first (so a new
 * association gets its initial synchronisation on the next pass). One
 * person's failure never stops the others, and never writes anything but
 * the failure on that association. Safe to run at any time, even twice at once
 * (each association is locked while it is reconciled).
 *
 * Safety stop (docs/surgicalhub-integration.md §9): a `404 link_not_found`
 * only suspends an association (nothing deleted), but several in one pass
 * look like a SurgicalHub restored from an older backup rather than real
 * dissociations (those answer `410`) — the pass stops at the second one,
 * says so loudly and exits ≠ 0; and as long as two associations or more are
 * suspended, the next passes call nothing and keep alerting, until someone
 * checks SurgicalHub and the owners associate again or dissociate.
 */
#[AsCommand(name: 'app:surgicalhub:sync', description: 'Synchronise the SurgicalHub leave of every associated account into MedVue calendars.')]
final class SurgicalHubSyncCommand extends Command
{
    /** Unknown associations in one pass beyond which it stops (a restored SurgicalHub, not real dissociations). */
    private const MAX_UNKNOWN_LINKS_PER_PASS = 1;

    public function __construct(
        private readonly SurgicalHubLinkRepository $linkRepository,
        private readonly SurgicalHubLeaveSyncService $syncService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $suspended = $this->linkRepository->countSuspended();
        if ($suspended > self::MAX_UNKNOWN_LINKS_PER_PASS) {
            $io->error(\sprintf(
                '%d associations are suspended (unknown to SurgicalHub): synchronisation halted, nothing deleted — check SurgicalHub first (docs/deployment.md §5 septies).',
                $suspended,
            ));

            return Command::FAILURE;
        }

        $counts = ['synced' => 0, 'failed' => 0, 'revoked' => 0, 'suspended' => 0, 'created' => 0, 'updated' => 0, 'removed' => 0];

        foreach ($this->linkRepository->findActiveForSync() as $link) {
            $outcome = $this->syncService->sync($link);
            match ($outcome->status) {
                SurgicalHubSyncStatus::SYNCED => ++$counts['synced'],
                SurgicalHubSyncStatus::FAILED => ++$counts['failed'],
                SurgicalHubSyncStatus::REVOKED => ++$counts['revoked'],
                SurgicalHubSyncStatus::SUSPENDED => ++$counts['suspended'],
                SurgicalHubSyncStatus::INACTIVE => null,
            };
            $counts['created'] += $outcome->created;
            $counts['updated'] += $outcome->updated;
            $counts['removed'] += $outcome->removed;
            if (null !== $outcome->error) {
                // The link's identifier and a short code only — never a response body.
                $io->warning(\sprintf('Association %s: %s', $link->getStableId(), $outcome->error->value));
            }
            if (SurgicalHubSyncStatus::SUSPENDED === $outcome->status) {
                $io->warning(\sprintf('Association %s: unknown to SurgicalHub (404), suspended — nothing deleted.', $link->getStableId()));
            }
            if ($counts['suspended'] > self::MAX_UNKNOWN_LINKS_PER_PASS) {
                $io->error(\sprintf(
                    'SurgicalHub does not know %d associations in this pass: possibly a restored backup. Pass stopped, nothing deleted — check SurgicalHub before anything else (docs/deployment.md §5 septies).',
                    $counts['suspended'],
                ));

                return Command::FAILURE;
            }
        }

        $io->success(\sprintf(
            '%d association(s) synchronised, %d failed, %d revoked by SurgicalHub, %d suspended; %d period(s) created, %d updated, %d removed.',
            $counts['synced'], $counts['failed'], $counts['revoked'], $counts['suspended'], $counts['created'], $counts['updated'], $counts['removed'],
        ));

        return 0 === $counts['failed'] + $counts['suspended'] ? Command::SUCCESS : Command::FAILURE;
    }
}
