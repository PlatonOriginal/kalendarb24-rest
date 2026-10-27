<?php
declare(strict_types=1);

namespace Kb24Rest\Tests;

use Kb24Rest\Bitrix24\HttpTransport;

/**
 * Отдаёт заранее заданные ответы по очереди и запоминает запросы.
 */
final class FakeTransport implements HttpTransport
{
    /** @var list<array{method: string, url: string, params: array}> */
    public array $requests = [];

    /** @param list<array<string, mixed>> $responses */
    public function __construct(private array $responses)
    {
    }

    public function post(string $url, array $params): array
    {
        return $this->next('POST', $url, $params);
    }

    public function postJson(string $url, array $params): array
    {
        return $this->next('POST_JSON', $url, $params);
    }

    public function get(string $url, array $query): array
    {
        return $this->next('GET', $url, $query);
    }

    private function next(string $method, string $url, array $params): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'params' => $params];
        if ($this->responses === [])
        {
            throw new \LogicException("Неожиданный запрос $method $url");
        }

        return ['status' => 200, 'body' => array_shift($this->responses)];
    }
}
