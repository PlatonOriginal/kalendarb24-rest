<?php
declare(strict_types=1);

namespace Kb24Rest\Log;

use Kb24Rest\Http\AfterResponse;
use PDO;

/**
 * Журнал в таблице rest_log. После ответа браузеру удаляет устаревшие записи, если в этом запросе
 * что-то писал; ещё раз очистку запускает bin/remind.php по расписанию.
 */
final class PdoRestLog implements RestLog, AfterResponse
{
    private bool $written = false;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function record(string $memberId, string $method, string $authType, int $httpStatus, string $errorCode, int $durationMs): void
    {
        try
        {
            $this->pdo->prepare(
                'INSERT INTO rest_log (created_at, member_id, method, auth_type, http_status, error_code, duration_ms)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                time(),
                mb_substr($memberId, 0, 64),
                mb_substr($method, 0, 128),
                $authType,
                max(0, min($httpStatus, 65535)),
                mb_substr($errorCode, 0, 64),
                max(0, $durationMs),
            ]);
            $this->written = true;
        }
        catch (\Throwable $e)
        {
            // Сбой журнала не должен ломать работу с порталом
            error_log('kb24 rest_log: ' . $e->getMessage());
        }
    }

    public function purge(?int $now = null): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM rest_log WHERE created_at < ?');
        $stmt->execute([($now ?? time()) - self::RETENTION]);

        return $stmt->rowCount();
    }

    public function deletePortal(string $memberId): void
    {
        $this->pdo->prepare('DELETE FROM rest_log WHERE member_id = ?')->execute([$memberId]);
    }

    public function hasWork(): bool
    {
        return $this->written;
    }

    public function run(): void
    {
        $this->written = false;
        try
        {
            $this->purge();
        }
        catch (\Throwable $e)
        {
            error_log('kb24 rest_log purge: ' . $e->getMessage());
        }
    }
}
