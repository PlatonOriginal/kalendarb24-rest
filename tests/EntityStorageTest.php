<?php
declare(strict_types=1);

namespace Kb24Rest\Tests;

use Kb24Rest\Bitrix24\OAuthClient;
use Kb24Rest\Bitrix24\RestClient;
use Kb24Rest\Portal\Portal;
use Kb24Rest\Storage\EntityStorage;
use Kb24Rest\Storage\Schema;
use PHPUnit\Framework\TestCase;

final class EntityStorageTest extends TestCase
{
    /**
     * Ответы портала, в котором хранилищ ещё нет: entity.get, затем entity.add и все свойства каждого хранилища.
     *
     * @return list<array<string, mixed>>
     */
    public static function emptyPortalResponses(): array
    {
        $responses = [['result' => []]];
        foreach (Schema::storages() as $storage)
        {
            $responses[] = ['result' => true];
            foreach ($storage['properties'] as $_)
            {
                $responses[] = ['result' => true];
            }
        }

        return $responses;
    }

    private function storage(FakeTransport $http): EntityStorage
    {
        return new EntityStorage(new RestClient($http, new OAuthClient($http, 'id', 'secret'), new MemoryPortalStore()));
    }

    private function portal(): Portal
    {
        return new Portal(1, 'm1', 'test.bitrix24.ru', 'https://test.bitrix24.ru/rest/', 'a', 'r', time() + 3600, 1);
    }

    public function testCreatesStoragesWithoutAccessSoOnlyCreatorManagesThem(): void
    {
        $http = new FakeTransport(self::emptyPortalResponses());

        $report = $this->storage($http)->ensure($this->portal());

        self::assertSame([Schema::REQUESTS, Schema::HISTORY], $report['createdStorages']);
        self::assertContains('kbreq.USER_ID', $report['addedProperties']);
        $add = $http->requests[1];
        self::assertSame('https://test.bitrix24.ru/rest/entity.add.json', $add['url']);
        self::assertArrayNotHasKey('ACCESS', $add['params']);
        foreach (Schema::storages() as $entity => $storage)
        {
            self::assertLessThanOrEqual(13, strlen($entity));
        }
    }

    public function testAddsOnlyMissingPropertiesToExistingStorages(): void
    {
        $properties = array_map(
            static fn(string $code) => ['PROPERTY' => $code, 'NAME' => $code, 'TYPE' => 'S'],
            array_keys(Schema::storages()[Schema::REQUESTS]['properties']),
        );
        $http = new FakeTransport([
            ['result' => [['ENTITY' => Schema::REQUESTS], ['ENTITY' => Schema::HISTORY]]],
            ['result' => $properties],
            ['result' => [['PROPERTY' => 'REQ_ID'], ['PROPERTY' => 'ACTOR_ID']]],
            ['result' => true],
        ]);

        $report = $this->storage($http)->ensure($this->portal());

        self::assertSame([], $report['createdStorages']);
        self::assertSame(['kbhist.ACTION'], $report['addedProperties']);
        self::assertSame('ACTION', $http->requests[3]['params']['PROPERTY']);
    }

    public function testFindPassesPropertyFilterAndReadsAllPages(): void
    {
        $http = new FakeTransport([
            ['result' => [['ID' => '1']], 'next' => 50],
            ['result' => [['ID' => '51']]],
        ]);

        $items = $this->storage($http)->find($this->portal(), Schema::REQUESTS, ['PROPERTY_USER_ID' => 7]);

        self::assertCount(2, $items);
        self::assertSame(['PROPERTY_USER_ID' => 7], $http->requests[0]['params']['FILTER']);
        self::assertSame(50, $http->requests[1]['params']['start']);
    }
}
