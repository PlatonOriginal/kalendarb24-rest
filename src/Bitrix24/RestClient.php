<?php
declare(strict_types=1);

namespace Kb24Rest\Bitrix24;

use Kb24Rest\Portal\Portal;
use Kb24Rest\Portal\PortalStore;

/**
 * Вызовы REST от имени приложения (токен администратора, установившего приложение).
 * Истёкший токен обновляется один раз, новые токены сохраняются.
 */
final class RestClient
{
    /** @var array<string, Portal> портал с последними токенами после обновления, по member_id */
    private array $fresh = [];

    public function __construct(
        private readonly HttpTransport $http,
        private readonly OAuthClient $oauth,
        private readonly PortalStore $portals,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     * @return mixed поле result ответа
     */
    public function call(Portal $portal, string $method, array $params = []): mixed
    {
        return $this->callRaw($portal, $method, $params)['result'] ?? null;
    }

    /**
     * @return array<string, mixed> весь ответ, вместе с next и total
     */
    private function callRaw(Portal $portal, string $method, array $params): array
    {
        $portal = $this->fresh[$portal->memberId] ?? $portal;
        if ($portal->expiresAt <= time() + 60)
        {
            $portal = $this->refresh($portal);
        }

        $body = $this->post($portal, $method, $params);
        if (($body['error'] ?? '') === 'expired_token')
        {
            $portal = $this->refresh($portal);
            $body = $this->post($portal, $method, $params);
        }
        if (isset($body['error']))
        {
            throw RestException::fromBody($body);
        }

        return $body;
    }

    /**
     * Все страницы списочного метода (по 50 записей, параметр start).
     *
     * @param array<string, mixed> $params
     * @return list<mixed>
     */
    public function listAll(Portal $portal, string $method, array $params = []): array
    {
        $items = [];
        $start = 0;
        do
        {
            $body = $this->callRaw($portal, $method, $params + ['start' => $start]);
            $items = array_merge($items, (array)($body['result'] ?? []));
            $start = isset($body['next']) ? (int)$body['next'] : null;
        }
        while ($start !== null);

        return $items;
    }

    /**
     * Метод REST 3.0: адрес /rest/api/, тело JSON.
     * https://apidocs.bitrix24.com/api-reference/rest-v3/humanresources/index.html
     *
     * @param array<string, mixed> $params
     */
    public function callV3(Portal $portal, string $method, array $params = []): mixed
    {
        $portal = $this->fresh[$portal->memberId] ?? $portal;
        if ($portal->expiresAt <= time() + 60)
        {
            $portal = $this->refresh($portal);
        }
        $url = preg_replace('~/rest/$~', '/rest/api/', $portal->clientEndpoint) . $method;
        $body = $this->http->postJson($url, $params + ['auth' => $portal->accessToken])['body'];
        if (isset($body['error']))
        {
            throw RestException::fromBody($body);
        }

        return $body['result'] ?? null;
    }

    /**
     * Вызов с токеном пользователя, который открыл приложение. Адрес портала берём из БД, а не из запроса.
     *
     * @param array<string, mixed> $params
     */
    public function callAsUser(Portal $portal, string $userToken, string $method, array $params = []): mixed
    {
        $body = $this->http->post($portal->clientEndpoint . $method . '.json', $params + ['auth' => $userToken])['body'];
        if (isset($body['error']))
        {
            throw RestException::fromBody($body);
        }

        return $body['result'] ?? null;
    }

    private function post(Portal $portal, string $method, array $params): array
    {
        return $this->http->post($portal->clientEndpoint . $method . '.json', $params + ['auth' => $portal->accessToken])['body'];
    }

    private function refresh(Portal $portal): Portal
    {
        $tokens = $this->oauth->refresh($portal->refreshToken);
        $portal = $portal->withTokens($tokens);
        $this->portals->save($portal);

        return $this->fresh[$portal->memberId] = $portal;
    }
}
