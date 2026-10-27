<?php
declare(strict_types=1);

namespace Kb24Rest\Tests;

use Kb24Rest\Bitrix24\OAuthClient;
use Kb24Rest\Bitrix24\RestClient;
use Kb24Rest\Bitrix24\RestException;
use Kb24Rest\Portal\Portal;
use Kb24Rest\Portal\UserResolver;
use PHPUnit\Framework\TestCase;

final class UserResolverTest extends TestCase
{
    private MemoryPortalStore $store;

    protected function setUp(): void
    {
        $this->store = new MemoryPortalStore();
        $this->store->save(new Portal(null, 'member1', 'test.bitrix24.ru', 'https://test.bitrix24.ru/rest/', 'app-access', 'app-refresh', time() + 3600, 1));
    }

    private function resolver(FakeTransport $http): UserResolver
    {
        return new UserResolver($this->store, new RestClient($http, new OAuthClient($http, 'id', 'secret'), $this->store));
    }

    public function testResolvesUserByTokenOnStoredEndpoint(): void
    {
        $http = new FakeTransport([['result' => ['ID' => '15', 'NAME' => 'Иван']], ['result' => false]]);

        $user = $this->resolver($http)->resolve('member1', 'user-token');

        self::assertSame(15, $user->userId);
        self::assertFalse($user->isAdmin);
        // Адрес портала из БД, токен пользователя, а не приложения
        self::assertSame('https://test.bitrix24.ru/rest/user.current.json', $http->requests[0]['url']);
        self::assertSame('user-token', $http->requests[0]['params']['auth']);
    }

    public function testRejectsUnknownPortal(): void
    {
        $this->expectException(RestException::class);
        $this->expectExceptionMessage('portal_not_installed');
        $this->resolver(new FakeTransport([]))->resolve('evil', 'user-token');
    }

    public function testRejectsInvalidToken(): void
    {
        $http = new FakeTransport([['error' => 'invalid_token', 'error_description' => 'The access token provided is invalid.']]);

        $this->expectException(RestException::class);
        $this->expectExceptionMessage('invalid_token');
        $this->resolver($http)->resolve('member1', 'forged');
    }
}
