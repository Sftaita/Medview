<?php

declare(strict_types=1);

namespace App\Tests;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * The SQL sent to the database, in order, while a test records it — to prove
 * an ordering no observable state shows, e.g. that the publication takes the
 * CalendarWriteLock before reading the calendar (docs/decisions.md D196): the
 * test's own transaction already holds that lock, so no concurrent session can
 * witness it. Off unless a test calls start(). Never in dev/prod.
 */
final class SqlRecorder implements Middleware
{
    /** @var list<string>|null */
    private static ?array $statements = null;

    public static function start(): void
    {
        self::$statements = [];
    }

    /**
     * @return list<string>
     */
    public static function stop(): array
    {
        $statements = self::$statements ?? [];
        self::$statements = null;

        return $statements;
    }

    public static function record(string $sql): void
    {
        if (null !== self::$statements) {
            self::$statements[] = $sql;
        }
    }

    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(array $params): Connection
            {
                return new class(parent::connect($params)) extends AbstractConnectionMiddleware {
                    public function prepare(string $sql): Statement
                    {
                        SqlRecorder::record($sql);

                        return parent::prepare($sql);
                    }

                    public function query(string $sql): Result
                    {
                        SqlRecorder::record($sql);

                        return parent::query($sql);
                    }

                    public function exec(string $sql): int|string
                    {
                        SqlRecorder::record($sql);

                        return parent::exec($sql);
                    }
                };
            }
        };
    }
}
