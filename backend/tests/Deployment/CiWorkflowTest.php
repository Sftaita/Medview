<?php

declare(strict_types=1);

namespace App\Tests\Deployment;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * docs/deployment.md §8 points 8-9 — the backend CI job runs on a clean
 * checkout, where everything git-ignored is absent. Two prerequisites were
 * missing in turn and each kept the job red (and was invisible on any
 * machine that already had them): the `_test` database, and the JWT key pair
 * (`config/jwt/*.pem`). This pins that the workflow prepares both, in order,
 * before it runs the suite.
 */
final class CiWorkflowTest extends TestCase
{
    public function testBackendJobPreparesEveryGitIgnoredPrerequisiteBeforeTheTests(): void
    {
        $workflow = \dirname(__DIR__, 3).'/.github/workflows/ci.yml';
        if (!is_file($workflow)) {
            self::markTestSkipped('.github/workflows/ci.yml is not reachable from this checkout (dev container).');
        }

        $steps = Yaml::parseFile($workflow)['jobs']['backend']['steps'];
        $runs = array_map(static fn (array $step): string => (string) ($step['run'] ?? ''), $steps);

        $testStep = $this->firstStepContaining($runs, 'composer test');
        self::assertNotNull($testStep, 'The backend job must run the suite.');

        $prerequisites = [
            'doctrine:database:create --env=test' => 'the test kernel suffixes the database name with _test',
            'doctrine:migrations:migrate --env=test' => 'the test database needs the schema',
            'lexik:jwt:generate-keypair' => 'config/jwt/*.pem is git-ignored, a clean checkout has no key pair',
        ];
        foreach ($prerequisites as $command => $why) {
            $step = $this->firstStepContaining($runs, $command);
            self::assertNotNull($step, \sprintf('CI must run "%s" (%s).', $command, $why));
            self::assertLessThan($testStep, $step, \sprintf('"%s" must run before the tests.', $command));
        }
    }

    public function testJwtKeysAreGitIgnoredSoTheWorkflowMustGenerateThem(): void
    {
        $gitignore = \dirname(__DIR__, 2).'/.gitignore';

        self::assertStringContainsString('/config/jwt/*.pem', (string) file_get_contents($gitignore), 'If keys ever stop being ignored the CI step and D107 must be revisited together.');
    }

    /** @param list<string> $runs */
    private function firstStepContaining(array $runs, string $needle): ?int
    {
        foreach ($runs as $index => $run) {
            if (str_contains($run, $needle)) {
                return $index;
            }
        }

        return null;
    }
}
