<?php
declare(strict_types=1);

namespace Kb24Rest\Tests;

use Kb24Rest\Bitrix24\OAuthClient;
use Kb24Rest\Bitrix24\RestClient;
use Kb24Rest\Bitrix24\RestException;
use Kb24Rest\Portal\Portal;
use PHPUnit\Framework\TestCase;

final class RestClientTest extends TestCase
{
    private function portal(int $expiresAt): Portal
    {
        return new Portal(1, 'member1', 'test.bitrix24.ru', 'https://test.bitrix24.ru/rest/', 'old-access', 'old-refresh', $expiresAt, 1);
    }

    private function tokens(): array
    {
        return [
            'access_token' => 'new-access',
            'refresh_token' => 'new-refresh',
            'expires' => time() + 3600,
            'member_id' => 'member1',
            'domain' => 'test.bitrix24.ru',
            'client_endpoint' => 'https://test.bitrix24.ru/rest/',
            'user_id' => 1,
        ];
    }

    public function testCallsMethodWithPortalToken(): void
    {
        $http = new FakeTransport([['result' => ['CODE' => 'local.1']]]);
        $store = new MemoryPortalStore();
        $client = new RestClient($http, new OAuthClient($http, 'id', 'secret'), $store);

        $result = $client->call($this->portal(time() + 3600), 'app.info');

        self::assertSame(['CODE' => 'local.1'], $result);
        self::assertSame('https://test.bitrix24.ru/rest/app.info.json', $http->requests[0]['url']);
        self::assertSame('old-access', $http->requests[0]['params']['auth']);
        self::assertSame([], $store->portals);
    }

    public function testRefreshesExpiredTokenOnceAndSavesIt(): void
    {
        $http = new FakeTransport([
            ['error' => 'expired_token', 'error_description' => 'The access token provided has expired.'],
            $this->tokens(),
            ['result' => true],
        ]);
        $store = new MemoryPortalStore();
        $client = new RestClient($http, new OAuthClient($http, 'id', 'secret'), $store);

        self::assertTrue($client->call($this->portal(time() + 3600), 'user.admin'));

        self::assertSame('https://oauth.bitrix.info/oauth/token/', $http->requests[1]['url']);
        self::assertSame('old-refresh', $http->requests[1]['params']['refresh_token']);
        self::assertSame('new-access', $http->requests[2]['params']['auth']);
        self::assertSame('new-refresh', $store->portals['member1']->refreshToken);
    }

    public function testRefreshesBeforeCallWhenTokenIsAboutToExpire(): void
    {
        $http = new FakeTransport([$this->tokens(), ['result' => 1]]);
        $client = new RestClient($http, new OAuthClient($http, 'id', 'secret'), new MemoryPortalStore());

        $client->call($this->portal(time() + 10), 'app.info');

        self::assertSame('GET', $http->requests[0]['method']);
        self::assertSame('new-access', $http->requests[1]['params']['auth']);
    }

    public function testThrowsRestError(): void
    {
        $http = new FakeTransport([['error' => 'ACCESS_DENIED', 'error_description' => 'Access denied']]);
        $client = new RestClient($http, new OAuthClient($http, 'id', 'secret'), new MemoryPortalStore());

        $this->expectException(RestException::class);
        $this->expectExceptionMessage('ACCESS_DENIED');
        $client->call($this->portal(time() + 3600), 'calendar.event.add');
    }

    public function testLogsEachCallWithoutParamsAndTokens(): void
    {
        $http = new FakeTransport([
            ['error' => 'expired_token'],
            $this->tokens(),
            ['result' => true],
            ['error' => 'ACCESS_DENIED'],
        ]);
        $log = new MemoryRestLog();
        $client = new RestClient($http, new OAuthClient($http, 'id', 'secret', $log), new MemoryPortalStore(), $log);

        $client->call($this->portal(time() + 3600), 'user.admin', ['ID' => 5]);
        try
        {
            $client->callAsUser($this->portal(time() + 3600), 'user-token', 'app.info');
        }
        catch (RestException)
        {
        }

        self::assertSame([
            ['memberId' => 'member1', 'method' => 'user.admin', 'auth' => 'app', 'status' => 200, 'error' => 'expired_token'],
            ['memberId' => 'member1', 'method' => 'oauth.token', 'auth' => 'app', 'status' => 200, 'error' => ''],
            ['memberId' => 'member1', 'method' => 'user.admin', 'auth' => 'app', 'status' => 200, 'error' => ''],
            ['memberId' => 'member1', 'method' => 'app.info', 'auth' => 'user', 'status' => 200, 'error' => 'ACCESS_DENIED'],
        ], $log->entries);
        $logged = json_encode($log->entries);
        foreach (['old-access', 'new-access', 'old-refresh', 'new-refresh', 'user-token', 'secret'] as $secret)
        {
            self::assertStringNotContainsString($secret, $logged);
        }
    }

    public function testLogsRestV3ErrorCode(): void
    {
        $http = new FakeTransport([['error' => ['code' => 'BITRIX_REST_V3_EXCEPTION', 'message' => 'x']]]);
        $log = new MemoryRestLog();
        $client = new RestClient($http, new OAuthClient($http, 'id', 'secret'), new MemoryPortalStore(), $log);

        try
        {
            $client->callV3($this->portal(time() + 3600), 'humanresources.department.list');
        }
        catch (RestException)
        {
        }

        self::assertSame('humanresources.department.list', $log->entries[0]['method']);
        self::assertSame('BITRIX_REST_V3_EXCEPTION', $log->entries[0]['error']);
    }

    public function testLogsNetworkFailureWithZeroStatus(): void
    {
        $http = new class implements \Kb24Rest\Bitrix24\HttpTransport {
            public function post(string $url, array $params): array
            {
                throw new RestException('http_error', 'timeout');
            }

            public function postJson(string $url, array $params): array
            {
                throw new RestException('http_error', 'timeout');
            }

            public function get(string $url, array $query): array
            {
                throw new RestException('http_error', 'timeout');
            }
        };
        $log = new MemoryRestLog();
        $client = new RestClient($http, new OAuthClient($http, 'id', 'secret', $log), new MemoryPortalStore(), $log);

        try
        {
            $client->call($this->portal(time() + 10), 'app.info');
        }
        catch (RestException)
        {
        }

        self::assertSame([['memberId' => '', 'method' => 'oauth.token', 'auth' => 'app', 'status' => 0, 'error' => 'http_error']], $log->entries);
    }
}
