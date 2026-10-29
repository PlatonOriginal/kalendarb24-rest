<?php
declare(strict_types=1);

namespace Kb24Rest\Tests;

use Kb24Rest\Bitrix24\OAuthClient;
use Kb24Rest\Bitrix24\RestClient;
use Kb24Rest\Http\App;
use Kb24Rest\Portal\UserResolver;
use Kb24Rest\Storage\EntityStorage;
use Kb24Rest\Structure\StructureService;
use Kb24Rest\Vacation\RequestService;
use Kb24Rest\Vacation\RoleService;
use Kb24Rest\Vacation\WorkCalendar;
use PHPUnit\Framework\TestCase;

final class AppTest extends TestCase
{
    private function app(FakeTransport $http, MemoryPortalStore $store, string $appUrl = ''): App
    {
        $oauth = new OAuthClient($http, 'id', 'secret');
        $rest = new RestClient($http, $oauth, $store);

        $structure = new StructureService($rest);
        $storage = new EntityStorage($rest);
        $roles = new RoleService($storage);

        return new App($store, $oauth, $rest, new UserResolver($store, $rest), $structure, $storage, $roles,
            new RequestService($storage, $structure, $roles, new WorkCalendar($rest)), dirname(__DIR__) . '/templates', $appUrl);
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
        ], ...EntityStorageTest::emptyPortalResponses()]);
        $store = new MemoryPortalStore();

        $html = $this->request($this->app($http, $store), '/install', ['REFRESH_ID' => 'rid', 'member_id' => 'forged']);

        self::assertStringContainsString('BX24.installFinish', $html);
        self::assertArrayHasKey('real-member', $store->portals);
        self::assertArrayNotHasKey('forged', $store->portals);
        self::assertSame(1, $store->portals['real-member']->installedBy);
        self::assertSame('real.bitrix24.ru', $store->portals['real-member']->domain);
        self::assertSame('https://real.bitrix24.ru/rest/entity.add.json', $http->requests[2]['url']);
    }

    public function testInstallIsNotFinishedWithoutEntityScope(): void
    {
        $http = new FakeTransport([[
            'access_token' => 'a', 'refresh_token' => 'r', 'expires' => time() + 3600,
            'member_id' => 'm1', 'client_endpoint' => 'https://real.bitrix24.ru/rest/', 'user_id' => 1,
        ], ['error' => 'insufficient_scope', 'error_description' => 'The request requires higher privileges than provided by the access token']]);

        $html = $this->request($this->app($http, new MemoryPortalStore()), '/install', ['REFRESH_ID' => 'rid']);

        self::assertStringNotContainsString('BX24.installFinish', $html);
        self::assertStringContainsString('entity', $html);
    }

    public function testStorageInfoIsForAdminsOnly(): void
    {
        $store = new MemoryPortalStore();
        $store->save(new \Kb24Rest\Portal\Portal(null, 'm1', 'test.bitrix24.ru', 'https://test.bitrix24.ru/rest/', 'a', 'r', time() + 3600, 1));
        $http = new FakeTransport([['result' => ['result' => ['me' => ['ID' => '7'], 'admin' => false]]]]);

        $json = $this->request($this->app($http, $store), '/api/storage', [], ['HTTP_X_B24_MEMBER' => 'm1', 'HTTP_X_B24_AUTH' => 'tok']);

        self::assertSame('forbidden', json_decode($json, true)['error']);
        self::assertCount(1, $http->requests, "только проверка пользователя, к хранилищам не обращались");
    }

    public function testApiMeReturnsOnlyIds(): void
    {
        $store = new MemoryPortalStore();
        $store->save(new \Kb24Rest\Portal\Portal(null, 'm1', 'test.bitrix24.ru', 'https://test.bitrix24.ru/rest/', 'a', 'r', time() + 3600, 1));
        $http = new FakeTransport([['result' => ['result' => ['me' => ['ID' => '7', 'NAME' => 'Иван', 'LAST_NAME' => 'Петров'], 'admin' => true]]]]);

        $json = $this->request($this->app($http, $store), '/api/me', [], ['HTTP_X_B24_MEMBER' => 'm1', 'HTTP_X_B24_AUTH' => 'tok']);

        self::assertSame(['userId' => 7, 'isAdmin' => true, 'domain' => 'test.bitrix24.ru', 'installedBy' => 1], json_decode($json, true));
        self::assertStringNotContainsString('Иван', $json);
    }

    public function testHrCompareTreatsMissingHeadOnBothSidesAsMatch(): void
    {
        $store = new MemoryPortalStore();
        $store->save(new \Kb24Rest\Portal\Portal(null, 'm1', 'test.bitrix24.ru', 'https://test.bitrix24.ru/rest/', 'a', 'r', time() + 3600, 1));
        $http = new FakeTransport([
            ['result' => ['result' => ['me' => ['ID' => '1'], 'admin' => true]]],
            ['result' => [['ID' => '1', 'NAME' => 'Компания', 'UF_HEAD' => '1'], ['ID' => '5', 'NAME' => 'Продажи', 'PARENT' => '1']]],
            ['result' => ['items' => [
                ['id' => 1, 'name' => 'Компания', 'parentId' => null, 'accessCode' => 'D1'],
                ['id' => 2, 'name' => 'Продажи', 'parentId' => 1, 'accessCode' => 'D5'],
            ]]],
            ['result' => ['item' => ['id' => 1, 'members' => [['userId' => 1, 'role' => 'MEMBER_HEAD']]]]],
            ['result' => ['item' => ['id' => 2, 'members' => [['userId' => 9, 'role' => 'MEMBER_EMPLOYEE']]]]],
        ]);

        $json = $this->request($this->app($http, $store), '/api/hr-compare', [], ['HTTP_X_B24_MEMBER' => 'm1', 'HTTP_X_B24_AUTH' => 'tok']);

        $nodes = json_decode($json, true)['nodes'];
        self::assertTrue($nodes[0]['headsMatch']);
        self::assertSame([], $nodes[1]['heads']);
        self::assertTrue($nodes[1]['headsMatch']);
    }

    public function testStorageProbeWritesUpdatesReadsAndDeletes(): void
    {
        $store = new MemoryPortalStore();
        $store->save(new \Kb24Rest\Portal\Portal(null, 'm1', 'test.bitrix24.ru', 'https://test.bitrix24.ru/rest/', 'a', 'r', time() + 3600, 1));
        $http = new FakeTransport([
            ['result' => ['result' => ['me' => ['ID' => '7'], 'admin' => false]]],
            ['result' => 55],
            ['result' => true],
            ['result' => [['ID' => '55', 'CREATED_BY' => '1', 'PROPERTY_VALUES' => ['KIND' => 'probe', 'STATUS' => 'probe_updated', 'USER_ID' => '7']]]],
            ['result' => true],
            ['result' => []],
            ['result' => []],
            ['result' => 60],
        ]);

        $json = $this->request($this->app($http, $store), '/api/storage/probe', [], [
            'HTTP_X_B24_MEMBER' => 'm1', 'HTTP_X_B24_AUTH' => 'tok', 'REQUEST_METHOD' => 'POST',
        ]);

        $data = json_decode($json, true);
        self::assertSame(55, $data['itemId']);
        self::assertSame(60, $data['canaryId']);
        self::assertSame(['ACTION' => 'canary', 'ACTOR_ID' => 1], $http->requests[7]['params']['PROPERTY_VALUES']);
        self::assertTrue($data['updateKeepsOtherProperties']);
        self::assertTrue($data['deleted']);
        self::assertSame(7, $http->requests[1]['params']['PROPERTY_VALUES']['USER_ID']);
        self::assertSame('a', $http->requests[1]['params']['auth']);
    }

    public function testOpeningAppByInstallerRenewsStoredTokens(): void
    {
        $store = new MemoryPortalStore();
        $store->save(new \Kb24Rest\Portal\Portal(null, 'm1', 'test.bitrix24.ru', 'https://test.bitrix24.ru/rest/', 'old', 'old-r', time() + 3600, 1));
        $store->setStorageVersion('m1', App::setupVersion(''));
        $http = new FakeTransport([[
            'access_token' => 'new', 'refresh_token' => 'new-r', 'expires' => time() + 3600,
            'member_id' => 'm1', 'client_endpoint' => 'https://test.bitrix24.ru/rest/', 'user_id' => 1,
        ]]);

        $html = $this->request($this->app($http, $store), '/app', ['REFRESH_ID' => 'rid', 'member_id' => 'm1']);

        self::assertStringContainsString('tab-checks', $html);
        self::assertStringContainsString('tab-calendar', $html);
        self::assertSame('new', $store->portals['m1']->accessToken);
        self::assertSame(1, $store->portals['m1']->installedBy);
    }

    public function testOpeningAppByOtherUserKeepsAppTokens(): void
    {
        $store = new MemoryPortalStore();
        $store->save(new \Kb24Rest\Portal\Portal(null, 'm1', 'test.bitrix24.ru', 'https://test.bitrix24.ru/rest/', 'old', 'old-r', time() + 3600, 1));
        $http = new FakeTransport([[
            'access_token' => 'user', 'refresh_token' => 'user-r', 'expires' => time() + 3600,
            'member_id' => 'm1', 'client_endpoint' => 'https://test.bitrix24.ru/rest/', 'user_id' => 7,
        ]]);

        $this->request($this->app($http, $store), '/app', ['REFRESH_ID' => 'rid']);

        self::assertSame('old', $store->portals['m1']->accessToken);
    }

    public function testOpeningAppByInstallerAddsStoragesOfNewVersion(): void
    {
        $store = new MemoryPortalStore();
        $store->save(new \Kb24Rest\Portal\Portal(null, 'm1', 'test.bitrix24.ru', 'https://test.bitrix24.ru/rest/', 'old', 'old-r', time() + 3600, 1));
        $store->setStorageVersion('m1', 'old-version');
        $http = new FakeTransport([[
            'access_token' => 'new', 'refresh_token' => 'new-r', 'expires' => time() + 3600,
            'member_id' => 'm1', 'client_endpoint' => 'https://test.bitrix24.ru/rest/', 'user_id' => 1,
        ], ...EntityStorageTest::emptyPortalResponses()]);

        $this->request($this->app($http, $store), '/app', ['REFRESH_ID' => 'rid']);

        self::assertSame(App::setupVersion(''), $store->storageVersion('m1'));
        self::assertSame('https://test.bitrix24.ru/rest/entity.add.json', $http->requests[2]['url']);
    }

    public function testNewTunnelAddressRebindsNotificationLinkHandler(): void
    {
        $store = new MemoryPortalStore();
        $store->save(new \Kb24Rest\Portal\Portal(null, 'm1', 'test.bitrix24.ru', 'https://test.bitrix24.ru/rest/', 'old', 'old-r', time() + 3600, 1));
        $store->setStorageVersion('m1', App::setupVersion('https://old.example.com'));
        $http = new FakeTransport([[
            'access_token' => 'new', 'refresh_token' => 'new-r', 'expires' => time() + 3600,
            'member_id' => 'm1', 'client_endpoint' => 'https://test.bitrix24.ru/rest/', 'user_id' => 1,
        ], ...EntityStorageTest::emptyPortalResponses(), ...[
            ['result' => [['id' => 3, 'placement' => 'REST_APP_URI', 'handler' => 'https://old.example.com/app']]],
            ['result' => ['count' => 1]],
            ['result' => true],
        ]]);

        $this->request($this->app($http, $store, 'https://new.example.com'), '/app', ['REFRESH_ID' => 'rid']);

        $calls = array_slice($http->requests, -3);
        self::assertSame('https://test.bitrix24.ru/rest/placement.unbind.json', $calls[1]['url']);
        self::assertSame('https://old.example.com/app', $calls[1]['params']['HANDLER']);
        self::assertSame('https://test.bitrix24.ru/rest/placement.bind.json', $calls[2]['url']);
        self::assertSame(['REST_APP_URI', 'https://new.example.com/app'], [$calls[2]['params']['PLACEMENT'], $calls[2]['params']['HANDLER']]);
        self::assertSame(App::setupVersion('https://new.example.com'), $store->storageVersion('m1'));
    }
}
