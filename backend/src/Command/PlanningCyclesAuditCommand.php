<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\PlanningCycleAudit;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Read-only proof that the successive-periods migration loses nothing
 * (docs/planning-cycles.md §6.4). Typical use, on a disposable restore of a
 * production dump:
 *
 *   app:planning-cycles:audit --output=before.json     (before migrating)
 *   doctrine:migrations:migrate
 *   app:planning-cycles:audit --compare=before.json --output=after.json
 *
 * Fails (exit 1) on a blocking invariant violation, a disabled trigger, or —
 * with --compare — any removed or changed row, table, constraint, index or
 * trigger. Additions are listed. The report holds only counts, hashes and
 * schema definitions, never row content.
 */
#[AsCommand(name: 'app:planning-cycles:audit', description: 'Read-only integrity audit of the planning cycles (successive periods) migration: fingerprints, invariants, comparison with an earlier report.')]
final class PlanningCyclesAuditCommand extends Command
{
    public function __construct(private readonly PlanningCycleAudit $audit)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Write the full report (JSON) to this file.')
            ->addOption('compare', null, InputOption::VALUE_REQUIRED, 'Compare with a report written earlier by --output.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $report = $this->audit->report();
        $failed = false;

        $io->title('Planning cycles audit');
        $io->text(\sprintf('Migration version: %s — L1 schema: %s — L2 schema: %s', $report['migrationVersion'] ?? 'none', $report['l1Schema'] ? 'yes' : 'no', $report['l2Schema'] ? 'yes' : 'no'));
        if (null !== $report['lineLinks']) {
            $io->text(\sprintf('Line ↔ period links: %d (%s)', $report['lineLinks']['count'], $report['lineLinks']['fingerprint']));
        }
        $io->text(\sprintf('%d tables, %d rows, %d constraints, %d indexes, %d triggers', \count($report['tables']), array_sum(array_column($report['tables'], 'rows')), \count($report['constraints']), \count($report['indexes']), \count($report['triggers'])));

        if ([] !== $report['invariants']) {
            $io->section('Invariants');
            $rows = [];
            foreach ($report['invariants'] as $code => $invariant) {
                $rows[] = [$code, $invariant['count'], $invariant['blocking'] ? ($invariant['count'] > 0 ? 'FAIL' : 'ok') : 'info'];
            }
            $io->table(['Invariant', 'Count', 'Result'], $rows);
        }

        $violations = $this->audit->violations($report);
        if ([] !== $violations) {
            $failed = true;
            $io->error(array_merge(['Invariant violations:'], $violations));
        }

        $outputPath = $input->getOption('output');
        if (\is_string($outputPath)) {
            file_put_contents($outputPath, json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n");
            $io->text(\sprintf('Report written to %s', $outputPath));
        }

        $comparePath = $input->getOption('compare');
        if (\is_string($comparePath)) {
            $content = @file_get_contents($comparePath);
            if (false === $content) {
                $io->error(\sprintf('Cannot read %s', $comparePath));

                return Command::FAILURE;
            }
            /** @var array{tables: array<string, array{rows: int, fingerprint: string}>, constraints: array<string, string>, indexes: array<string, string>, triggers: array<string, string>} $before */
            $before = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
            $comparison = $this->audit->compare($before, $report);

            $io->section('Comparison with '.$comparePath);
            $io->listing([] === $comparison['additions'] ? ['(no additions)'] : $comparison['additions']);
            if ([] !== $comparison['planned']) {
                $io->text('Schema changes made on purpose by lot L2 (docs/decisions.md D187):');
                $io->listing($comparison['planned']);
            }
            if ([] === $comparison['differences']) {
                $io->success('Every table, row, constraint, index and trigger of the earlier report is unchanged (apart from the planned changes listed).');
            } else {
                $failed = true;
                $io->error(array_merge(['Differences:'], $comparison['differences']));
            }
        }

        if (!$failed) {
            $io->success('Audit passed.');
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
