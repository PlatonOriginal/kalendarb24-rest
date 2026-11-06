<?php
declare(strict_types=1);

namespace Kb24Rest\Log;

/**
 * Журнал, который ничего не хранит: для тестов и кода, где журнал не подключён.
 */
final class NullRestLog implements RestLog
{
    public function record(string $memberId, string $method, string $authType, int $httpStatus, string $errorCode, int $durationMs): void
    {
    }

    public function purge(?int $now = null): int
    {
        return 0;
    }

    public function deletePortal(string $memberId): void
    {
    }
}
