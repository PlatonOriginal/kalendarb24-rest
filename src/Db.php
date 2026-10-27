<?php
declare(strict_types=1);

namespace Kb24Rest;

use PDO;

final class Db
{
    public static function connect(Config $config): PDO
    {
        return new PDO($config->dbDsn, $config->dbUser, $config->dbPassword, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /**
     * Применяет migrations/NNN_*.sql, которых ещё нет в schema_version.
     */
    public static function migrate(PDO $pdo, string $dir): void
    {
        $hasTable = (bool)$pdo->query("SHOW TABLES LIKE 'schema_version'")->fetch();
        $current = $hasTable ? (int)$pdo->query('SELECT MAX(version) FROM schema_version')->fetchColumn() : 0;

        $files = glob(rtrim($dir, '/') . '/*.sql') ?: [];
        sort($files);
        foreach ($files as $file)
        {
            $version = (int)basename($file);
            if ($version <= $current)
            {
                continue;
            }
            foreach (array_filter(array_map('trim', explode(';', (string)file_get_contents($file)))) as $statement)
            {
                $pdo->exec($statement);
            }
            $pdo->prepare('INSERT INTO schema_version (version) VALUES (?)')->execute([$version]);
        }
    }
}
