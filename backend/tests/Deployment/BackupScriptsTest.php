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

    public function testRestoreTestFailsHardOnSqlErrorsAndComparesConstraints(): void
    {
        $script = $this->script('medvue-restore-test.sh');

        // First real run: `contype || text` was a SQL error that psql swallowed, so
        // the constraint comparison was silently empty on both sides. Errors must be
        // fatal and every fingerprint section must be present.
        self::assertStringContainsString('ON_ERROR_STOP=1', $script);
        self::assertStringContainsString('contype::text', $script);
        self::assertStringContainsString("'^constraint '", $script, 'An empty constraint section must fail the run.');
    }

    /**
     * 2026-10-08, production: `medvue-restore-test.sh` exited 141 with no output
     * at all. Under `set -o pipefail`, `sort -rn | head -1` fails whenever `head`
     * closes the pipe before `sort` has finished writing — a race that became
     * likely once enough backups had accumulated. `sed -n` reads its whole input,
     * so the producer never gets SIGPIPE.
     */
    public function testProductionScriptsNeverPipeIntoHeadUnderPipefail(): void
    {
        $scripts = array_merge(glob($this->root.'/scripts/backup/*.sh') ?: [], glob($this->root.'/scripts/deploy/*.sh') ?: []);
        self::assertNotEmpty($scripts);

        foreach ($scripts as $path) {
            $code = implode("\n", array_filter(
                explode("\n", (string) file_get_contents($path)),
                static fn (string $line): bool => !str_starts_with(ltrim($line), '#'),
            ));
            if (!str_contains($code, 'pipefail')) {
                continue;
            }
            self::assertDoesNotMatchRegularExpression('/\|\s*head\b/', $code, basename($path).': use `sed -n 1p` instead of `| head` under pipefail.');
        }
    }

    /**
     * docs/backup.md §2 ter, docs/decisions.md D209: encrypted on the server
     * before transfer, with MedVue's own passphrase file, read by gpg only.
     */
    public function testOffsiteEncryptsBeforeTransferWithItsOwnPassphrase(): void
    {
        $script = $this->script('medvue-offsite.sh');
        $code = $this->withoutComments($script);

        self::assertStringContainsString('--symmetric --cipher-algo AES256', $code);
        self::assertStringContainsString('${MEDVUE_OFFSITE_PASSFILE:-$HOME/.medvue-backup-passphrase}', $code);
        self::assertStringContainsString('--passphrase-file "$PASSFILE"', $code, 'The passphrase reaches gpg through its file only, never a command line.');
        self::assertStringContainsString('!= "600"', $code, 'A passphrase file readable by others is refused.');
        self::assertStringContainsString('export GNUPGHOME="$work/gnupg"', $code, 'Never touches ~/.gnupg.');
        self::assertStringContainsString('umask 077', $code);
        self::assertDoesNotMatchRegularExpression('/JWT_PASSPHRASE|\.env\b/', $code, 'Never reuses the JWT passphrase, never reads .env.');
        self::assertDoesNotMatchRegularExpression('/(cat|echo|printf|tee|<)\s+"?\$PASSFILE/', $code, 'The passphrase is never printed or read by the script itself.');

        // The decrypted copy is proven equal to the original before anything leaves the server.
        self::assertMatchesRegularExpression('/gpg "\$\{gpg_args\[@\]\}" -d -- "\$dest\/\$f\.gpg" \| sha256sum/', $code);
    }

    /**
     * A local rotation must never delete anything remote, and the reserved
     * sets are kept forever: copies are immutable, nothing is synced, and the
     * only deletion targets daily/, after a fully verified run.
     */
    public function testOffsiteNeverSyncsAndOnlyEverPrunesDaily(): void
    {
        $code = $this->withoutComments($this->script('medvue-offsite.sh'));

        preg_match_all('/"\$RCLONE" copy [^\n]*/', $code, $copies);
        self::assertNotEmpty($copies[0]);
        foreach ($copies[0] as $copy) {
            self::assertStringContainsString('--immutable', $copy);
        }
        self::assertDoesNotMatchRegularExpression('/"\$RCLONE" (sync|move|delete|deletefile|rmdirs?|cleanup)\b/', $code);
        preg_match_all('/"\$RCLONE" purge [^\n]*/', $code, $purges);
        self::assertCount(1, $purges[0]);
        self::assertStringContainsString('"$RCLONE" purge "$REMOTE/daily/$s"', $purges[0][0]);
        self::assertStringContainsString('if [[ "$failures" -eq 0 ]]; then', $code, 'Retention only after a fully verified run.');
        self::assertDoesNotMatchRegularExpression('/\brm\s+-\w*\s+[^\n]*\$BACKUP_ROOT/', $code, 'Never deletes a local backup.');

        $fetch = $this->withoutComments($this->script('medvue-offsite-fetch.sh'));
        self::assertDoesNotMatchRegularExpression('/"\$RCLONE" (sync|move|delete|deletefile|purge|rmdirs?)\b/', $fetch, 'Recovery only reads the remote.');
        self::assertStringContainsString('sha256sum -c --quiet SHA256SUMS', $fetch);
    }

    /**
     * Runs scripts/backup/tests/medvue-offsite.test.sh: encryption, upload,
     * retention, blocked retention after a failure, fetch, tampering and a
     * wrong passphrase, against a local directory (real rclone when installed,
     * tests/fake-rclone.sh otherwise).
     */
    public function testOffsiteBehaviourOnAThrowawayTree(): void
    {
        if ('Windows' === \PHP_OS_FAMILY) {
            self::markTestSkipped('Needs a Linux shell (chmod 600, GNU find).');
        }
        foreach (['bash', 'gpg'] as $tool) {
            if ('' === trim((string) shell_exec('command -v '.$tool.' 2>/dev/null'))) {
                self::markTestSkipped("$tool is not available.");
            }
        }

        exec('bash '.escapeshellarg($this->root.'/scripts/backup/tests/medvue-offsite.test.sh').' 2>&1', $output, $status);

        self::assertSame(0, $status, implode("\n", $output));
        self::assertContains('RESULT: PASS', $output);
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
