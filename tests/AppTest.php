<?php
declare(strict_types=1);

namespace Kb24Rest\Tests;

use Kb24Rest\Bitrix24\OAuthClient;
use Kb24Rest\Bitrix24\RestClient;
use Kb24Rest\Http\App;
use Kb24Rest\Portal\UserResolver;
use Kb24Rest\Storage\EntityStorage;
use Kb24Rest\Structure\StructureService;
use PHPUnit\Framework\TestCase;

final class AppTest extends TestCase
{
    private function app(FakeTransport $http, MemoryPortalStore $store): App
    {
        $oauth = new OAuthClient($http, 'id', 'secret');
        $rest = new RestClient($http, $oauth, $store);

        return new App($store, $oauth, $rest, new UserResolver($store, $rest), new StructureService($rest), new EntityStorage($rest), dirname(__DIR__) . '/templates');
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
        $http = new FakeTransport([['result' => ['ID' => '7']], ['result' => false]]);

        $json = $this->request($this->app($http, $store), '/api/storage', [], ['HTTP_X_B24_MEMBER' => 'm1', 'HTTP_X_B24_AUTH' => 'tok']);

        self::assertSame('forbidden', json_decode($json, true)['error']);
        self::assertCount(2, $http->requests);
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

    public function testHrCompareTreatsMissingHeadOnBothSidesAsMatch(): void
    {
        $store = new MemoryPortalStore();
        $store->save(new \Kb24Rest\Portal\Portal(null, 'm1', 'test.bitrix24.ru', 'https://test.bitrix24.ru/rest/', 'a', 'r', time() + 3600, 1));
        $http = new FakeTransport([
            ['result' => ['ID' => '1']],
            ['result' => true],
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
            ['result' => ['ID' => '7']],
            ['result' => false],
            ['result' => 55],
            ['result' => true],
            ['result' => [['ID' => '55', 'CREATED_BY' => '1', 'PROPERTY_VALUES' => ['KIND' => 'probe', 'STATUS' => 'probe_updated', 'USER_ID' => '7']]]],
            ['result' => true],
            ['result' => []],
        ]);

        $json = $this->request($this->app($http, $store), '/api/storage/probe', [], [
            'HTTP_X_B24_MEMBER' => 'm1', 'HTTP_X_B24_AUTH' => 'tok', 'REQUEST_METHOD' => 'POST',
        ]);

        $data = json_decode($json, true);
        self::assertSame(55, $data['itemId']);
        self::assertTrue($data['updateKeepsOtherProperties']);
        self::assertTrue($data['deleted']);
        self::assertSame(7, $http->requests[2]['params']['PROPERTY_VALUES']['USER_ID']);
        self::assertSame('a', $http->requests[2]['params']['auth']);
    }
}
