<?php
declare(strict_types=1);

namespace Kb24Rest\Tests;

use Kb24Rest\Log\PdoRestLog;
use Kb24Rest\Log\RestLog;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Таблица из migrations/005_rest_log.sql на SQLite в памяти (типы упрощены, запросы те же).
 */
final class PdoRestLogTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true))
        {
            self::markTestSkipped('Нет pdo_sqlite');
        }
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE rest_log (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at INTEGER NOT NULL,
            member_id TEXT NOT NULL, method TEXT NOT NULL, auth_type TEXT NOT NULL, http_status INTEGER NOT NULL,
            error_code TEXT NOT NULL DEFAULT \'\', duration_ms INTEGER NOT NULL)');
    }

    public function testPurgeKeepsOnlyLastThreeDays(): void
    {
        $log = new PdoRestLog($this->pdo);
        $log->record('m1', 'user.get', RestLog::AUTH_APP, 200, '', 15);
        $now = time();
        $this->pdo->exec('UPDATE rest_log SET created_at = ' . ($now - RestLog::RETENTION - 1));
        $log->record('m1', 'app.info', RestLog::AUTH_USER, 401, 'expired_token', 3);

        self::assertSame(1, $log->purge($now));
        self::assertSame(['app.info'], $this->pdo->query('SELECT method FROM rest_log')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testPurgesAfterResponseOnlyWhenSomethingWasWritten(): void
    {
        $log = new PdoRestLog($this->pdo);
        self::assertFalse($log->hasWork());

        $log->record('m1', 'user.get', RestLog::AUTH_APP, 200, '', 15);
        self::assertTrue($log->hasWork());
        $log->run();
        self::assertFalse($log->hasWork());
    }

    public function testDeletePortalRemovesOnlyItsEntries(): void
    {
        $log = new PdoRestLog($this->pdo);
        $log->record('m1', 'user.get', RestLog::AUTH_APP, 200, '', 1);
        $log->record('m2', 'user.get', RestLog::AUTH_APP, 200, '', 1);

        $log->deletePortal('m1');

        self::assertSame(['m2'], $this->pdo->query('SELECT member_id FROM rest_log')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testWriteFailureDoesNotBreakTheCall(): void
    {
        $this->pdo->exec('DROP TABLE rest_log');
        $previous = ini_set('error_log', '/dev/null');
        $log = new PdoRestLog($this->pdo);

        $log->record('m1', 'user.get', RestLog::AUTH_APP, 200, '', 1);
        ini_set('error_log', (string)$previous);

        self::assertFalse($log->hasWork());
    }
}
