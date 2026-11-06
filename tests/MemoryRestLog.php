<?php
declare(strict_types=1);

namespace Kb24Rest\Tests;

use Kb24Rest\Log\RestLog;

final class MemoryRestLog implements RestLog
{
    /** @var list<array{memberId: string, method: string, auth: string, status: int, error: string}> */
    public array $entries = [];

    public function record(string $memberId, string $method, string $authType, int $httpStatus, string $errorCode, int $durationMs): void
    {
        $this->entries[] = ['memberId' => $memberId, 'method' => $method, 'auth' => $authType, 'status' => $httpStatus, 'error' => $errorCode];
    }

    public function purge(?int $now = null): int
    {
        return 0;
    }

    public function deletePortal(string $memberId): void
    {
        $this->entries = array_values(array_filter($this->entries, static fn(array $e) => $e['memberId'] !== $memberId));
    }
}
