<?php
declare(strict_types=1);

namespace Kb24Rest\Bitrix24;

interface HttpTransport
{
    /**
     * @param array<string, mixed> $params
     * @return array{status: int, body: array<string, mixed>}
     */
    public function post(string $url, array $params): array;

    /**
     * POST с JSON-телом, нужен для методов REST 3.0 (/rest/api/).
     *
     * @param array<string, mixed> $params
     * @return array{status: int, body: array<string, mixed>}
     */
    public function postJson(string $url, array $params): array;

    /**
     * @param array<string, mixed> $query
     * @return array{status: int, body: array<string, mixed>}
     */
    public function get(string $url, array $query): array;
}
