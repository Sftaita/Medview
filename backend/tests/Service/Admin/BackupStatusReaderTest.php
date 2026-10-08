<?php

declare(strict_types=1);

namespace App\Tests\Service\Admin;

use App\Service\Admin\BackupStatusReader;
use App\Service\Admin\UserAgentSummary;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Backups are only ever known through the status files the scripts write
 * (docs/admin.md §7): absent or unreadable means "unknown", never "ok".
 */
final class BackupStatusReaderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/medvue-ops-status-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->directory.'/*') ?: []);
        rmdir($this->directory);
    }

    public function testNoFileIsUnknown(): void
    {
        $reader = $this->reader();
        self::assertSame('unknown', $reader->backup()['status']);
        self::assertSame('unknown', $reader->restoreTest()['status']);
    }

    public function testARecentSuccessIsOk(): void
    {
        $this->write('backup.json', ['finishedAt' => '2026-12-10T03:46:12Z', 'result' => 'success', 'postgresDumpBytes' => 123456, 'latestMigration' => 'DoctrineMigrations\\Version20261008100000']);

        $status = $this->reader()->backup();
        self::assertSame('ok', $status['status']);
        self::assertSame('2026-12-10T03:46:12+00:00', $status['finishedAt']);
        self::assertSame(123456, $status['postgresDumpBytes']);
        self::assertSame('DoctrineMigrations\\Version20261008100000', $status['latestMigration']);
    }

    public function testAnOldSuccessIsAWarningAndAFailureAnError(): void
    {
        $this->write('backup.json', ['finishedAt' => '2026-12-08T03:46:12Z', 'result' => 'success']);
        self::assertSame('warning', $this->reader()->backup()['status']);

        $this->write('backup.json', ['finishedAt' => '2026-12-10T03:46:12Z', 'result' => 'failure']);
        self::assertSame('error', $this->reader()->backup()['status']);

        $this->write('restore-test.json', ['finishedAt' => '2026-10-01T10:00:00Z', 'result' => 'success']);
        self::assertSame('warning', $this->reader()->restoreTest()['status']);
        $this->write('restore-test.json', ['finishedAt' => '2026-12-01T10:00:00Z', 'result' => 'success']);
        self::assertSame('ok', $this->reader()->restoreTest()['status']);
    }

    public function testGarbageIsUnknownAndUnexpectedValuesAreDropped(): void
    {
        file_put_contents($this->directory.'/backup.json', 'not json');
        self::assertSame('unknown', $this->reader()->backup()['status']);

        $this->write('backup.json', ['finishedAt' => '2026-12-10T03:46:12Z', 'result' => 'maybe']);
        self::assertSame('unknown', $this->reader()->backup()['status']);

        $this->write('backup.json', ['finishedAt' => '2026-12-10T03:46:12Z', 'result' => 'success', 'postgresDumpBytes' => '<script>', 'latestMigration' => '<b>x</b>']);
        $status = $this->reader()->backup();
        self::assertNull($status['postgresDumpBytes']);
        self::assertNull($status['latestMigration']);
    }

    public function testUserAgentsAreReducedToBrowserAndSystem(): void
    {
        self::assertSame('Chrome · Windows', UserAgentSummary::describe('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36'));
        self::assertSame('Safari · iPhone', UserAgentSummary::describe('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1'));
        self::assertSame('Edge · Windows', UserAgentSummary::describe('Mozilla/5.0 (Windows NT 10.0) Chrome/129.0 Safari/537.36 Edg/129.0'));
        self::assertSame('Navigateur inconnu', UserAgentSummary::describe('curl/8.0'));
        self::assertSame('Appareil inconnu', UserAgentSummary::describe(null));
    }

    private function reader(): BackupStatusReader
    {
        return new BackupStatusReader($this->directory, new MockClock('2026-12-10 12:00:00 UTC'));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function write(string $file, array $data): void
    {
        file_put_contents($this->directory.'/'.$file, (string) json_encode($data));
    }
}
