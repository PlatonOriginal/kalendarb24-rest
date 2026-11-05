<?php
declare(strict_types=1);

namespace Kb24Rest\Tests;

use Kb24Rest\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($this->file, "# комментарий\n\nB24_CLIENT_ID=app.123\nB24_CLIENT_SECRET=\"s e c=ret\"\nAPP_URL=https://vacation.example.by/\nDB_HOST=localhost\nDB_NAME='kb'\nALLOW_SELF_APPROVAL=0\nbad line\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        putenv('DB_HOST');
    }

    public function testReadsEnvFileWhenEnvironmentIsEmpty(): void
    {
        $config = Config::fromEnv($this->file);
        self::assertSame('app.123', $config->clientId);
        self::assertSame('s e c=ret', $config->clientSecret);
        self::assertSame('https://vacation.example.by', $config->appUrl);
        self::assertSame('mysql:host=localhost;dbname=kb;charset=utf8mb4', $config->dbDsn);
        self::assertFalse($config->allowSelfApproval);
        self::assertSame('app.123', $config->appCode);
    }

    public function testEnvironmentWinsOverFile(): void
    {
        putenv('DB_HOST=db');
        self::assertStringContainsString('host=db;', Config::fromEnv($this->file)->dbDsn);
    }

    public function testMissingFileGivesDefaults(): void
    {
        self::assertSame('https://localhost:8443', Config::fromEnv('/nonexistent/.env')->appUrl);
    }
}
