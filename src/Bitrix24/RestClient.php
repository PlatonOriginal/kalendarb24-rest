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

        return $portal;
    }
}
