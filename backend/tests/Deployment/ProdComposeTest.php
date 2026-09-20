<?php

declare(strict_types=1);

namespace App\Tests\Deployment;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * docs/decisions.md D107, docs/deployment.md — static guards on the
 * production deployment files, so persistence and secret-hygiene regressions
 * fail in CI instead of being discovered after a `docker compose up -d`
 * recreates a container.
 *
 * No kernel, no database: it only parses `docker-compose.prod.yml` (repo
 * root, one level above `backend/`) and the two ignore files. The compose
 * checks are skipped when the file is not reachable (the dev container only
 * bind-mounts `./backend`); CI checks out the whole repository, so it runs
 * there.
 */
final class ProdComposeTest extends TestCase
{
    private const JWT_KEYS_TARGET = '/app/config/jwt';
    private const POSTGRES_DATA_TARGET = '/var/lib/postgresql/data';

    public function testJwtKeysLiveInANamedPersistentVolume(): void
    {
        $this->assertServiceMountsNamedVolume('backend', self::JWT_KEYS_TARGET, 'medvue_jwt_keys');
    }

    public function testPostgresDataLivesInANamedPersistentVolume(): void
    {
        $this->assertServiceMountsNamedVolume('database', self::POSTGRES_DATA_TARGET, 'medvue_db_data');
    }

    public function testComposeFileNeverEmbedsKeyMaterialOrRealSecrets(): void
    {
        $this->requireComposeFile();
        $raw = (string) file_get_contents($this->composePath());

        self::assertDoesNotMatchRegularExpression('/BEGIN [A-Z ]*PRIVATE KEY/', $raw);
        self::assertDoesNotMatchRegularExpression('/^\s*(APP_SECRET|JWT_PASSPHRASE|POSTGRES_PASSWORD)\s*[:=]\s*[^$\s"\']/m', $raw);
    }

    public function testJwtKeysStayOutOfGitAndOutOfTheImageBuildContext(): void
    {
        $backend = \dirname(__DIR__, 2);

        self::assertContains('/config/jwt/*.pem', $this->ignoreEntries($backend.'/.gitignore'), 'backend/.gitignore must ignore the key pair.');

        $dockerignore = $this->ignoreEntries($backend.'/.dockerignore');
        self::assertContains('config/jwt/', $dockerignore, 'backend/.dockerignore must keep dev keys out of the prod image (COPY . .).');
        self::assertContains('.env.local', $dockerignore, 'backend/.dockerignore must keep local secret overrides out of the prod image.');
    }

    private function assertServiceMountsNamedVolume(string $service, string $target, string $volumeName): void
    {
        $this->requireComposeFile();
        $compose = Yaml::parseFile($this->composePath());

        $mounts = $compose['services'][$service]['volumes'] ?? [];
        $matching = array_values(array_filter(
            $mounts,
            static fn (mixed $mount): bool => \is_string($mount) && (explode(':', $mount)[1] ?? null) === $target,
        ));

        self::assertCount(1, $matching, \sprintf('Service "%s" must mount exactly one volume on %s.', $service, $target));

        [$source, , $options] = array_pad(explode(':', $matching[0]), 3, '');
        self::assertSame($volumeName, $source, 'Must be the named volume, never a bind mount into the checkout.');
        self::assertNotContains('ro', explode(',', $options), 'Mount must stay writable so keys/data can be created.');

        $declared = $compose['volumes'][$volumeName] ?? null;
        self::assertIsArray($declared, \sprintf('Volume "%s" must be declared at the top level.', $volumeName));
        self::assertSame($volumeName, $declared['name'] ?? null, 'Explicit volume name (no compose-project prefix).');
        self::assertArrayNotHasKey('external', $declared, 'Volume must be owned by this compose file.');
    }

    private function composePath(): string
    {
        return \dirname(__DIR__, 3).'/docker-compose.prod.yml';
    }

    private function requireComposeFile(): void
    {
        if (!is_file($this->composePath())) {
            self::markTestSkipped('docker-compose.prod.yml is not reachable from this checkout (dev container).');
        }
    }

    /** @return list<string> */
    private function ignoreEntries(string $file): array
    {
        self::assertFileExists($file);

        return array_values(array_filter(
            array_map('trim', file($file, \FILE_IGNORE_NEW_LINES) ?: []),
            static fn (string $line): bool => '' !== $line && !str_starts_with($line, '#'),
        ));
    }
}
