<?php
declare(strict_types=1);

namespace Kb24Rest;

final class Config
{
    public function __construct(
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly string $appUrl,
        public readonly string $dbDsn,
        public readonly string $dbUser,
        public readonly string $dbPassword,
        public readonly bool $allowSelfApproval,
    ) {
    }

    public static function fromEnv(): self
    {
        $get = static fn(string $name, string $default = ''): string => (string)(getenv($name) ?: $default);

        return new self(
            $get('B24_CLIENT_ID'),
            $get('B24_CLIENT_SECRET'),
            rtrim($get('APP_URL', 'https://localhost:8443'), '/'),
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $get('DB_HOST', 'db'), $get('DB_NAME', 'kb24rest')),
            $get('DB_USER', 'kb24rest'),
            $get('DB_PASSWORD'),
            // На время проверки на одном аккаунте. Перед публикацией в Маркете выключить: ALLOW_SELF_APPROVAL=0
            $get('ALLOW_SELF_APPROVAL', '1') === '1',
        );
    }
}
