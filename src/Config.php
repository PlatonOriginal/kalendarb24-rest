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
        public readonly string $appCode,
    ) {
    }

    /**
     * Переменные окружения (Docker: env_file), а без них — файл .env в корне приложения (виртуальный хостинг).
     * Переменная окружения важнее строки из файла.
     */
    public static function fromEnv(?string $envFile = null): self
    {
        $file = self::readEnvFile($envFile ?? dirname(__DIR__) . '/.env');
        $get = static function (string $name, string $default = '') use ($file): string {
            $value = getenv($name);
            if ($value === false || $value === '')
            {
                $value = $file[$name] ?? '';
            }

            return $value !== '' ? $value : $default;
        };

        return new self(
            $get('B24_CLIENT_ID'),
            $get('B24_CLIENT_SECRET'),
            rtrim($get('APP_URL', 'https://localhost:8443'), '/'),
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $get('DB_HOST', 'db'), $get('DB_NAME', 'kb24rest')),
            $get('DB_USER', 'kb24rest'),
            $get('DB_PASSWORD'),
            // На время проверки на одном аккаунте. Перед публикацией в Маркете выключить: ALLOW_SELF_APPROVAL=0
            $get('ALLOW_SELF_APPROVAL', '1') === '1',
            // Код приложения в ссылке /marketplace/view/<код>/: у локального приложения это client_id
            $get('APP_CODE', $get('B24_CLIENT_ID')),
        );
    }

    /**
     * Строки KEY=VALUE; пустые строки и # комментарии пропускаются, кавычки вокруг значения снимаются.
     *
     * @return array<string, string>
     */
    public static function readEnvFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path))
        {
            return [];
        }
        $values = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line)
        {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '='))
            {
                continue;
            }
            [$name, $value] = array_map('trim', explode('=', $line, 2));
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0])
            {
                $value = substr($value, 1, -1);
            }
            if (preg_match('/^[A-Z][A-Z0-9_]*$/', $name))
            {
                $values[$name] = $value;
            }
        }

        return $values;
    }
}
