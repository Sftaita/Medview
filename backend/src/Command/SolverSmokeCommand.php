<?php

declare(strict_types=1);

namespace App\Command;

use App\Solver\SolverSmokeCheck;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Solves a tiny in-memory problem through the real engine path
 * (SolverSmokeCheck, docs/decisions.md D152) — no database access. Run in CI
 * against the built production image and after every deployment in both
 * medvue-backend and medvue-worker (docs/deployment.md §2).
 */
#[AsCommand(name: 'app:solver:smoke', description: 'Solve a tiny in-memory planning problem with the real solver (PHP → Process → Python → OR-Tools), without touching the database.')]
final class SolverSmokeCommand extends Command
{
    public function __construct(
        private readonly SolverSmokeCheck $check,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $report = $this->check->run();
        $result = $report->result;

        $io->definitionList(
            ['Solver' => trim(($result->solverMetadata?->solverType ?? 'unknown').' '.($result->solverMetadata?->solverVersion ?? ''))],
            ['STRICT status' => $result->strictSolverStatus->value],
            ['Coverage' => $result->coverageStatus->value],
            ['Assignments' => (string) \count($result->assignments)],
            ['Duration' => \sprintf('%d ms', $result->solverMetadata?->solveDurationMs ?? 0)],
        );

        if (!$report->isSuccessful()) {
            $io->error(['Solver smoke test FAILED.', ...$report->failures]);

            return Command::FAILURE;
        }

        $io->success('Solver smoke test passed.');

        return Command::SUCCESS;
    }
}
