<?php
declare(strict_types=1);

namespace Kb24Rest\Tests;

use Kb24Rest\Bitrix24\OAuthClient;
use Kb24Rest\Bitrix24\RestClient;
use Kb24Rest\Http\App;
use Kb24Rest\Portal\UserResolver;
use PHPUnit\Framework\TestCase;

final class AppTest extends TestCase
{
    private function app(FakeTransport $http, MemoryPortalStore $store): App
    {
        $oauth = new OAuthClient($http, 'id', 'secret');
        $rest = new RestClient($http, $oauth, $store);

        return new App($store, $oauth, $rest, new UserResolver($store, $rest), dirname(__DIR__) . '/templates');
    }

    private function request(App $app, string $path, array $post = [], array $server = []): string
    {
        ob_start();
        $app->handle($path, $post, $server);

        return (string)ob_get_clean();
    }

    public function testInstallTakesPortalFromOAuthServerNotFromRequest(): void
    {
        $http = new FakeTransport([[
            'access_token' => 'a', 'refresh_token' => 'r', 'expires' => time() + 3600,
            'member_id' => 'real-member', 'domain' => 'oauth.bitrix.info',
            'client_endpoint' => 'https://real.bitrix24.ru/rest/', 'user_id' => 1,
        ]]);
        $store = new MemoryPortalStore();

        $html = $this->request($this->app($http, $store), '/install', ['REFRESH_ID' => 'rid', 'member_id' => 'forged']);

        self::assertStringContainsString('BX24.installFinish', $html);
        self::assertArrayHasKey('real-member', $store->portals);
        self::assertArrayNotHasKey('forged', $store->portals);
        self::assertSame(1, $store->portals['real-member']->installedBy);
        self::assertSame('real.bitrix24.ru', $store->portals['real-member']->domain);
    }

    public function testApiMeReturnsOnlyIds(): void
    {
        $store = new MemoryPortalStore();
        $store->save(new \Kb24Rest\Portal\Portal(null, 'm1', 'test.bitrix24.ru', 'https://test.bitrix24.ru/rest/', 'a', 'r', time() + 3600, 1));
        $http = new FakeTransport([['result' => ['ID' => '7', 'NAME' => 'Иван', 'LAST_NAME' => 'Петров']], ['result' => true]]);

        $json = $this->request($this->app($http, $store), '/api/me', [], ['HTTP_X_B24_MEMBER' => 'm1', 'HTTP_X_B24_AUTH' => 'tok']);

        self::assertSame(['userId' => 7, 'isAdmin' => true, 'domain' => 'test.bitrix24.ru', 'installedBy' => 1], json_decode($json, true));
        self::assertStringNotContainsString('Иван', $json);
    }
}
