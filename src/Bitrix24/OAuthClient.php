<?php
declare(strict_types=1);

namespace Kb24Rest\Bitrix24;

/**
 * Обновление токенов через сервер авторизации Битрикс24.
 * https://apidocs.bitrix24.com/settings/oauth/auto-renewal.html
 */
final class OAuthClient
{
    private const TOKEN_URL = 'https://oauth.bitrix.info/oauth/token/';

    public function __construct(
        private readonly HttpTransport $http,
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {
    }

    public function refresh(string $refreshToken): TokenSet
    {
        $response = $this->http->get(self::TOKEN_URL, [
            'grant_type' => 'refresh_token',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $refreshToken,
        ]);
        $body = $response['body'];
        if (isset($body['error']) || empty($body['access_token']))
        {
            throw RestException::fromBody($body);
        }

        return TokenSet::fromOAuthResponse($body);
    }
}
