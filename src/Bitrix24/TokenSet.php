<?php
declare(strict_types=1);

namespace Kb24Rest\Bitrix24;

/**
 * Ответ oauth.bitrix.info. Ему можно доверять: member_id и domain здесь подтверждает сервер Битрикс24,
 * а не браузер пользователя.
 */
final class TokenSet
{
    public function __construct(
        public readonly string $accessToken,
        public readonly string $refreshToken,
        public readonly int $expiresAt,
        public readonly string $memberId,
        public readonly string $domain,
        public readonly string $clientEndpoint,
        public readonly int $userId,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromOAuthResponse(array $body): self
    {
        return new self(
            (string)$body['access_token'],
            (string)$body['refresh_token'],
            (int)($body['expires'] ?? (time() + (int)($body['expires_in'] ?? 3600))),
            (string)$body['member_id'],
            (string)$body['domain'],
            (string)$body['client_endpoint'],
            (int)($body['user_id'] ?? 0),
        );
    }
}
