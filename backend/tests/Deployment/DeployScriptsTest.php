<?php

declare(strict_types=1);

namespace App\Tests\Deployment;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * docs/deployment.md, docs/backup.md — every shell script shipped under
 * `scripts/` runs unattended on the production server (cron, deploy
 * checks). Static guards so a script that would break there, or that
 * would leak a secret, fails in CI instead.
 */
final class DeployScriptsTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function scripts(): iterable
    {
        $root = \dirname(__DIR__, 3).'/scripts';
        if (!is_dir($root)) {
            // An empty provider is a PHPUnit error, not a skip: hand the tests a
            // marker they turn into a skip (dev container only bind-mounts backend/).
            yield 'scripts/ not reachable' => [''];

            return;
        }

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ('sh' === $file->getExtension()) {
                yield substr($file->getPathname(), \strlen($root) + 1) => [$file->getPathname()];
            }
        }
    }

    public function testShellScriptsAreCoveredByTheLfPolicy(): void
    {
        $root = \dirname(__DIR__, 3);
        if (!is_dir($root.'/scripts')) {
            self::markTestSkipped('scripts/ is not reachable from this checkout (dev container).');
        }

        self::assertStringContainsString('*.sh text eol=lf', (string) file_get_contents($root.'/.gitattributes'));
        self::assertNotSame([], iterator_to_array(self::scripts()), 'scripts/ must contain at least one script.');
    }

    #[DataProvider('scripts')]
    public function testScriptIsStrictLfBashWithoutEmbeddedSecrets(string $path): void
    {
        $this->skipWhenUnreachable($path);
        $content = (string) file_get_contents($path);

        self::assertStringStartsWith("#!/usr/bin/env bash\n", $content, 'Portable shebang, LF line ending.');
        self::assertStringNotContainsString("\r", $content, 'No CR: a CRLF script breaks on Linux.');
        self::assertStringContainsString('set -euo pipefail', $content);

        foreach (explode("\n", $content) as $number => $line) {
            $code = trim($line);
            if ('' === $code || str_starts_with($code, '#')) {
                continue;
            }
            self::assertDoesNotMatchRegularExpression(
                '/(PASSWORD|PASSPHRASE|SECRET|TOKEN|PGPASSWORD)\s*=\s*[\'"]?[A-Za-z0-9+\/]{8,}/',
                $code,
                \sprintf('%s:%d must not embed a secret literal.', basename($path), $number + 1),
            );
            self::assertDoesNotMatchRegularExpression('/\b(down\s+-v|down\s+--volumes|volume\s+rm|volume\s+prune|system\s+prune)\b/', $code, 'Scripts must never delete Docker volumes.');
        }
    }

    #[DataProvider('scripts')]
    public function testScriptSyntaxIsValid(string $path): void
    {
        $this->skipWhenUnreachable($path);
        $locate = 'Windows' === \PHP_OS_FAMILY ? 'where bash 2>NUL' : 'command -v bash 2>/dev/null';
        $bash = trim(preg_split('/\R/', (string) shell_exec($locate))[0] ?? '');
        if ('' === $bash) {
            self::markTestSkipped('bash is not available.');
        }

        exec(escapeshellarg($bash).' -n '.escapeshellarg($path).' 2>&1', $output, $status);

        self::assertSame(0, $status, implode("\n", $output));
    }

    private function skipWhenUnreachable(string $path): void
    {
        if ('' === $path) {
            self::markTestSkipped('scripts/ is not reachable from this checkout (dev container).');
        }
    }
}
