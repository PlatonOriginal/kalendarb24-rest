<?php
declare(strict_types=1);

namespace Kb24Rest\Bitrix24;

use Kb24Rest\Log\NullRestLog;
use Kb24Rest\Log\RestLog;

/**
 * Обновление токенов через сервер авторизации Битрикс24.
 * https://apidocs.bitrix24.com/settings/oauth/auto-renewal.html
 * Запрос пишется в журнал как метод oauth.token; портал берём из ответа (при ошибке он неизвестен).
 */
final class OAuthClient
{
    private const TOKEN_URL = 'https://oauth.bitrix.info/oauth/token/';

    public function __construct(
        private readonly HttpTransport $http,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly RestLog $log = new NullRestLog(),
    ) {
    }

    public function refresh(string $refreshToken): TokenSet
    {
        $started = hrtime(true);
        try
        {
            $response = $this->http->get(self::TOKEN_URL, [
                'grant_type' => 'refresh_token',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'refresh_token' => $refreshToken,
            ]);
        }
        catch (RestException $e)
        {
            $this->log->record('', 'oauth.token', RestLog::AUTH_APP, 0, $e->errorCode, RestClient::elapsedMs($started));
            throw $e;
        }
        $body = $response['body'];
        $this->log->record((string)($body['member_id'] ?? ''), 'oauth.token', RestLog::AUTH_APP, $response['status'],
            RestClient::errorCode($body) ?: (empty($body['access_token']) ? 'no_access_token' : ''), RestClient::elapsedMs($started));
        if (isset($body['error']) || empty($body['access_token']))
        {
            throw RestException::fromBody($body);
        }

        return TokenSet::fromOAuthResponse($body);
    }
}
