<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\PlatformAuditActorKind;
use App\Exception\AdminActionRefusedException;
use App\Repository\UserRepository;
use App\Service\Admin\PlatformAdminService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The bootstrap procedure of the platform administration (docs/admin.md §2,
 * docs/decisions.md D174): naming the first administrator, or recovering
 * when none is reachable, needs shell access to the server — never a public
 * sign-up, a default role or a request body.
 *
 *   php bin/console app:platform-admin grant someone@example.com
 *   php bin/console app:platform-admin revoke someone@example.com
 *   php bin/console app:platform-admin list
 *
 * The account must already exist and be active. Every change is written to
 * the audit log with actor kind CONSOLE. Revoking the last administrator is
 * refused, as from the interface.
 */
#[AsCommand(name: 'app:platform-admin', description: 'Grant, revoke or list the global platform administrator role (ROLE_PLATFORM_ADMIN).')]
final class PlatformAdminCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PlatformAdminService $platformAdmins,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'grant | revoke | list')
            ->addArgument('email', InputArgument::OPTIONAL, 'Email of an existing MedVue account (grant/revoke)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = (string) $input->getArgument('action');

        if ('list' === $action) {
            $admins = $this->users->findPlatformAdmins();
            if ([] === $admins) {
                $io->warning('No platform administrator.');

                return Command::SUCCESS;
            }
            $io->table(['Email', 'Name', 'Active'], array_map(
                static fn ($user): array => [$user->getEmail(), $user->getFirstName().' '.$user->getLastName(), $user->isActive() ? 'yes' : 'no'],
                $admins,
            ));

            return Command::SUCCESS;
        }

        if (!\in_array($action, ['grant', 'revoke'], true)) {
            $io->error('Unknown action. Use grant, revoke or list.');

            return Command::INVALID;
        }

        $email = (string) $input->getArgument('email');
        $user = '' === $email ? null : $this->users->findOneByEmail($email);
        if (null === $user) {
            $io->error('No MedVue account with this email. The person must register first.');

            return Command::FAILURE;
        }

        try {
            if ('grant' === $action) {
                $this->platformAdmins->grant($user, null, PlatformAuditActorKind::CONSOLE, ['via' => 'console']);
                $io->success(\sprintf('%s is now a platform administrator.', $user->getEmail()));
            } else {
                $this->platformAdmins->revoke($user, null, PlatformAuditActorKind::CONSOLE, ['via' => 'console']);
                $io->success(\sprintf('%s is no longer a platform administrator (effective on their next request).', $user->getEmail()));
            }
        } catch (AdminActionRefusedException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
