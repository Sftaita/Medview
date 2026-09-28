<?php

declare(strict_types=1);

namespace App\Tests\Deployment;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * docs/deployment.md §8 points 8-10 — the backend CI job runs on a clean
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

    /**
     * docs/decisions.md D152 — symfony/process was only installed through
     * require-dev: every test passed, and every generation failed in the
     * `--no-dev` production image. The check must see what that image sees.
     */
    public function testProductionDependencyJobChecksRuntimeRequirementsOnANoDevInstall(): void
    {
        $runs = $this->jobRuns('backend-prod-dependencies');

        $install = $this->firstStepContaining($runs, 'composer install --no-dev');
        $check = $this->firstStepContaining($runs, 'composer-require-checker.phar check composer.json');
        self::assertNotNull($install, 'The job must install production dependencies only.');
        self::assertNotNull($check, 'The job must run composer-require-checker.');
        self::assertLessThan($check, $install, 'The check must run against the --no-dev install.');
        self::assertStringContainsString('sha256sum -c', $runs[$check], 'The downloaded phar must be verified.');
    }

    public function testProductionImageJobRunsTheSolverSmokeInTheBuiltImage(): void
    {
        $runs = $this->jobRuns('backend-prod-image');

        $build = $this->firstStepContaining($runs, 'docker build -f backend/Dockerfile.prod');
        self::assertNotNull($build, 'The job must build the real production Dockerfile.');
        foreach (['Symfony\Component\Process\Process', 'import ortools', 'app:solver:smoke'] as $needle) {
            $step = $this->firstStepContaining($runs, $needle);
            self::assertNotNull($step, \sprintf('The job must check "%s" in the built image.', $needle));
            self::assertGreaterThan($build, $step);
            self::assertStringContainsString('medvue-backend:ci', $runs[$step], 'Checked in the built image, never on the runner.');
        }
    }

    public function testSymfonyProcessIsAnInstalledRuntimeDependency(): void
    {
        $backend = \dirname(__DIR__, 2);
        $require = json_decode((string) file_get_contents($backend.'/composer.json'), true, flags: \JSON_THROW_ON_ERROR)['require'];
        $packages = array_column(json_decode((string) file_get_contents($backend.'/composer.lock'), true, flags: \JSON_THROW_ON_ERROR)['packages'], 'name');

        self::assertArrayHasKey('symfony/process', $require, 'OrToolsPlanningSolver runs the solver through Symfony Process.');
        self::assertContains('symfony/process', $packages, 'A --no-dev install must contain symfony/process.');
    }

    /** @return list<string> */
    private function jobRuns(string $job): array
    {
        $workflow = \dirname(__DIR__, 3).'/.github/workflows/ci.yml';
        if (!is_file($workflow)) {
            self::markTestSkipped('.github/workflows/ci.yml is not reachable from this checkout (dev container).');
        }

        $jobs = Yaml::parseFile($workflow)['jobs'];
        self::assertArrayHasKey($job, $jobs);

        return array_map(static fn (array $step): string => (string) ($step['run'] ?? ''), $jobs[$job]['steps']);
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
