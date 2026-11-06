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
        $roles = new RoleService($storage, $structure);

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

        self::assertStringContainsString('tab-dashboard', $html);
        self::assertStringNotContainsString('tab-checks', $html);
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
            ['result' => [
                ['event' => 'ONAPPUNINSTALL', 'handler' => 'https://old.example.com/event', 'auth_type' => '0', 'offline' => 0],
                ['event' => 'ONCRMLEADADD', 'handler' => 'https://other.example.com/', 'auth_type' => '0', 'offline' => 0],
            ]],
            ['result' => true],
            ['result' => true],
            ['result' => true],
        ]]);

        $this->request($this->app($http, $store, 'https://new.example.com'), '/app', ['REFRESH_ID' => 'rid']);

        $calls = array_slice($http->requests, -7, 3);
        self::assertSame('https://test.bitrix24.ru/rest/placement.unbind.json', $calls[1]['url']);
        self::assertSame('https://old.example.com/app', $calls[1]['params']['HANDLER']);
        self::assertSame('https://test.bitrix24.ru/rest/placement.bind.json', $calls[2]['url']);
        self::assertSame(['REST_APP_URI', 'https://new.example.com/app'], [$calls[2]['params']['PLACEMENT'], $calls[2]['params']['HANDLER']]);

        // События: старый адрес снимаем, ONAPPUPDATE и ONAPPUNINSTALL вешаем на новый
        $events = array_slice($http->requests, -4);
        self::assertSame('https://test.bitrix24.ru/rest/event.get.json', $events[0]['url']);
        self::assertSame('https://test.bitrix24.ru/rest/event.unbind.json', $events[1]['url']);
        self::assertSame(['ONAPPUNINSTALL', 'https://old.example.com/event'], [$events[1]['params']['event'], $events[1]['params']['handler']]);
        self::assertSame(['event.bind', 'ONAPPUPDATE', 'https://new.example.com/event'],
            [basename($events[2]['url'], '.json'), $events[2]['params']['event'], $events[2]['params']['handler']]);
        self::assertSame(['event.bind', 'ONAPPUNINSTALL', 'https://new.example.com/event'],
            [basename($events[3]['url'], '.json'), $events[3]['params']['event'], $events[3]['params']['handler']]);
        self::assertSame(App::setupVersion('https://new.example.com'), $store->storageVersion('m1'));
    }

    public function testBoundEventsAreNotBoundAgain(): void
    {
        $store = new MemoryPortalStore();
        $store->save(new \Kb24Rest\Portal\Portal(null, 'm1', 'test.bitrix24.ru', 'https://test.bitrix24.ru/rest/', 'old', 'old-r', time() + 3600, 1));
        $http = new FakeTransport([[
            'access_token' => 'new', 'refresh_token' => 'new-r', 'expires' => time() + 3600,
            'member_id' => 'm1', 'client_endpoint' => 'https://test.bitrix24.ru/rest/', 'user_id' => 1,
        ], ...EntityStorageTest::emptyPortalResponses(), ...[
            ['result' => [['placement' => 'REST_APP_URI', 'handler' => 'https://srv.example.com/app']]],
            ['result' => [
                ['event' => 'ONAPPUPDATE', 'handler' => 'https://srv.example.com/event', 'auth_type' => '0', 'offline' => 0],
                ['event' => 'ONAPPUNINSTALL', 'handler' => 'https://srv.example.com/event', 'auth_type' => '0', 'offline' => 0],
            ]],
        ]]);

        $this->request($this->app($http, $store, 'https://srv.example.com'), '/app', ['REFRESH_ID' => 'rid']);

        self::assertSame('https://test.bitrix24.ru/rest/event.get.json', end($http->requests)['url']);
        self::assertSame(App::setupVersion('https://srv.example.com'), $store->storageVersion('m1'));
    }

    private function installedPortal(MemoryPortalStore $store, ?string $applicationToken = 'app-token'): void
    {
        $store->save(new \Kb24Rest\Portal\Portal(null, 'm1', 'test.bitrix24.ru', 'https://test.bitrix24.ru/rest/', 'a', 'r', time() + 3600, 1));
        $store->setStorageVersion('m1', 'v');
        if ($applicationToken !== null)
        {
            $store->setApplicationToken('m1', $applicationToken);
        }
    }

    private function uninstall(string $applicationToken): array
    {
        return [
            'event' => 'ONAPPUNINSTALL',
            'data' => ['LANGUAGE_ID' => 'ru', 'CLEAN' => '0'],
            'ts' => (string)time(),
            'auth' => [
                'domain' => 'test.bitrix24.ru',
                'server_endpoint' => 'https://oauth.bitrix.info/rest/',
                'client_endpoint' => 'https://test.bitrix24.ru/rest/',
                'member_id' => 'm1',
                'application_token' => $applicationToken,
            ],
        ];
    }

    public function testUninstallWithStoredTokenDeletesPortal(): void
    {
        $store = new MemoryPortalStore();
        $this->installedPortal($store);
        $http = new FakeTransport([]);

        $json = $this->request($this->app($http, $store), '/event', $this->uninstall('app-token'), ['REQUEST_METHOD' => 'POST']);

        self::assertSame(['result' => true], json_decode($json, true));
        self::assertArrayNotHasKey('m1', $store->portals);
        self::assertSame([], $store->applicationTokens);
        self::assertSame([], $http->requests);
    }

    public function testForgedUninstallKeepsPortalWhileAppStillWorks(): void
    {
        $store = new MemoryPortalStore();
        $this->installedPortal($store);
        $http = new FakeTransport([['result' => ['ID' => 5, 'INSTALLED' => true]]]);

        $this->request($this->app($http, $store), '/event', $this->uninstall('forged'), ['REQUEST_METHOD' => 'POST']);

        self::assertSame(403, http_response_code());
        self::assertArrayHasKey('m1', $store->portals);
        self::assertSame('https://test.bitrix24.ru/rest/app.info.json', $http->requests[0]['url']);
    }

    public function testUninstallWithoutStoredTokenDeletesPortalWhenAppIsRevoked(): void
    {
        $store = new MemoryPortalStore();
        $this->installedPortal($store, null);
        $http = new FakeTransport([['error' => 'invalid_token', 'error_description' => 'The access token provided is invalid.']]);

        $this->request($this->app($http, $store), '/event', $this->uninstall('any'), ['REQUEST_METHOD' => 'POST']);

        self::assertArrayNotHasKey('m1', $store->portals);
    }

    public function testUninstallKeepsPortalOnNetworkError(): void
    {
        $store = new MemoryPortalStore();
        $this->installedPortal($store, null);
        $http = new FakeTransport([['error' => 'http_error', 'error_description' => 'timeout']]);

        $this->request($this->app($http, $store), '/event', $this->uninstall('any'), ['REQUEST_METHOD' => 'POST']);

        self::assertArrayHasKey('m1', $store->portals);
    }

    public function testUninstallOfUnknownPortalDoesNothing(): void
    {
        $store = new MemoryPortalStore();
        $http = new FakeTransport([]);

        $json = $this->request($this->app($http, $store), '/event', $this->uninstall('x'), ['REQUEST_METHOD' => 'POST']);

        self::assertSame(['result' => false], json_decode($json, true));
    }

    public function testAppUpdateStoresNewApplicationTokenAfterCheckingAccessToken(): void
    {
        $store = new MemoryPortalStore();
        $this->installedPortal($store, 'old-token');
        $http = new FakeTransport([['result' => ['ID' => 5]]]);
        $post = $this->uninstall('new-token');
        $post['event'] = 'ONAPPUPDATE';
        $post['auth']['access_token'] = 'event-access';
        // Адрес из события не используем
        $post['auth']['client_endpoint'] = 'https://evil.example.com/rest/';

        $this->request($this->app($http, $store), '/event', $post, ['REQUEST_METHOD' => 'POST']);

        self::assertSame('new-token', $store->applicationToken('m1'));
        self::assertSame('https://test.bitrix24.ru/rest/app.info.json', $http->requests[0]['url']);
        self::assertSame('event-access', $http->requests[0]['params']['auth']);
    }

    public function testAppUpdateWithInvalidAccessTokenIsRejected(): void
    {
        $store = new MemoryPortalStore();
        $this->installedPortal($store, 'old-token');
        $http = new FakeTransport([['error' => 'invalid_token']]);
        $post = $this->uninstall('new-token');
        $post['event'] = 'ONAPPUPDATE';
        $post['auth']['access_token'] = 'forged';

        $this->request($this->app($http, $store), '/event', $post, ['REQUEST_METHOD' => 'POST']);

        self::assertSame('old-token', $store->applicationToken('m1'));
    }
}
