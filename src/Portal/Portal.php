<?php
declare(strict_types=1);

namespace Kb24Rest\Portal;

use Kb24Rest\Bitrix24\TokenSet;

final class Portal
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $memberId,
        public readonly string $domain,
        public readonly string $clientEndpoint,
        public readonly string $accessToken,
        public readonly string $refreshToken,
        public readonly int $expiresAt,
        public readonly int $installedBy,
    ) {
    }

    public static function fromInstall(TokenSet $tokens, ?int $existingId = null): self
    {
        return new self(
            $existingId,
            $tokens->memberId,
            $tokens->domain,
            $tokens->clientEndpoint,
            $tokens->accessToken,
            $tokens->refreshToken,
            $tokens->expiresAt,
            $tokens->userId,
        );
    }

    public function withTokens(TokenSet $tokens): self
    {
        return new self(
            $this->id,
            $this->memberId,
            $tokens->domain,
            $tokens->clientEndpoint,
            $tokens->accessToken,
            $tokens->refreshToken,
            $tokens->expiresAt,
            $this->installedBy,
        );
    }

    public function withId(int $id): self
    {
        return new self($id, $this->memberId, $this->domain, $this->clientEndpoint, $this->accessToken, $this->refreshToken, $this->expiresAt, $this->installedBy);
    }
}
