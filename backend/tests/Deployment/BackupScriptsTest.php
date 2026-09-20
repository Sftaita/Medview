<?php

declare(strict_types=1);

namespace App\Tests\Deployment;

use PHPUnit\Framework\TestCase;

/**
 * docs/backup.md, docs/decisions.md D109 — static guarantees of the MedVue
 * backup mechanism that must survive future edits: no secret handling, no
 * write to production from the restore test, no interference with the other
 * applications' backups, and the documented rules (no `down -v`, migration
 * SQL reviewed before execution).
 */
final class BackupScriptsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = \dirname(__DIR__, 3);
        if (!is_dir($this->root.'/scripts/backup')) {
            self::markTestSkipped('scripts/backup is not reachable from this checkout (dev container).');
        }
    }

    public function testBackupCoversPostgresAndTheJwtVolumeWithoutAnyPassword(): void
    {
        $script = $this->script('medvue-backup.sh');

        self::assertStringContainsString('pg_dump', $script);
        self::assertStringContainsString('docker exec "$DB_CONTAINER" pg_dump', $script, 'Dump must run inside the DB container (local socket, no password).');
        self::assertStringContainsString('medvue_jwt_keys', $script);
        self::assertStringContainsString('-v "${JWT_VOLUME}:/data:ro"', $script, 'The key volume is mounted read-only.');
        self::assertStringContainsString('--network none', $script);
        self::assertStringContainsString('umask 077', $script);
        self::assertStringContainsString('flock -n', $script);
        self::assertStringContainsString('pg_restore --list', $script, 'A dump must be validated before it counts as a backup.');
        self::assertStringContainsString('sha256sum', $script);
        self::assertDoesNotMatchRegularExpression('/PGPASSWORD|POSTGRES_PASSWORD|JWT_PASSPHRASE|\.env\b/', $this->withoutComments($script), 'The script must never read or pass a secret; .env is deliberately not backed up next to the keys.');
    }

    public function testBackupDefaultsAreMedvueSpecificWithLocalRetention(): void
    {
        $script = $this->script('medvue-backup.sh');

        self::assertStringContainsString('${MEDVUE_BACKUP_ROOT:-/home/deploy/backups/medvue}', $script);
        self::assertStringContainsString('${MEDVUE_BACKUP_RETENTION_DAYS:-30}', $script);
        self::assertStringContainsString('${MEDVUE_BACKUP_MIN_KEEP:-7}', $script);

        foreach (['surgicalhub', 'mysql', 'medatwork', 'medclick', '/backups/uploads', 'rotate_backups'] as $foreign) {
            self::assertStringNotContainsStringIgnoringCase($foreign, $this->withoutComments($script), "Must not touch other applications' backups ($foreign).");
        }
    }

    public function testRestoreTestNeverWritesToProduction(): void
    {
        $script = $this->script('medvue-restore-test.sh');
        $code = $this->withoutComments($script);

        self::assertStringContainsString('--network none', $script);
        self::assertStringContainsString('--tmpfs', $script, 'Throwaway data directory lives in RAM.');
        self::assertStringContainsString('trap cleanup EXIT', $script);
        self::assertStringContainsString('docker rm -f "$name"', $script);
        self::assertStringNotContainsString('-p ', str_replace('psql -U', '', $code), 'No published port, no password flag.');

        foreach (explode("\n", $code) as $line) {
            $reportsOnly = 1 === preg_match('/^\s*(pass|fail|echo)\b/', $line);
            if (!$reportsOnly && str_contains($line, 'pg_restore') && !str_contains($line, '--list')) {
                self::assertStringContainsString('docker exec "$name"', $line, 'pg_restore may only ever target the throwaway container.');
            }
            self::assertDoesNotMatchRegularExpression('/docker\s+(volume\s+(rm|prune)|system\s+prune|compose\s+down)/', $line);
            self::assertDoesNotMatchRegularExpression('/\bDROP\b|\bTRUNCATE\b|\bDELETE\s+FROM\b/i', $line, 'Production is only ever read, by the fingerprint queries.');
        }
    }

    public function testDocumentationStatesTheProductionRules(): void
    {
        $backup = (string) file_get_contents($this->root.'/docs/backup.md');
        $deployment = (string) file_get_contents($this->root.'/docs/deployment.md');

        self::assertStringContainsString('docker compose down -v', $backup);
        self::assertStringContainsString('docker compose down -v', $deployment);
        self::assertMatchesRegularExpression('/interdit/i', $deployment);
        self::assertStringContainsString('--dry-run --write-sql', $deployment, 'Migration SQL must be produced without being executed, then reviewed.');
        self::assertStringContainsString('medvue-restore-test.sh', $backup);
        self::assertStringContainsString('medvue-backup.sh', $backup);
    }

    private function script(string $name): string
    {
        $path = $this->root.'/scripts/backup/'.$name;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function withoutComments(string $script): string
    {
        return implode("\n", array_filter(
            explode("\n", $script),
            static fn (string $line): bool => !str_starts_with(ltrim($line), '#'),
        ));
    }
}
