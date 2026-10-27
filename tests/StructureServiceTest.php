<?php
declare(strict_types=1);

namespace Kb24Rest\Tests;

use Kb24Rest\Bitrix24\OAuthClient;
use Kb24Rest\Bitrix24\RestClient;
use Kb24Rest\Portal\Portal;
use Kb24Rest\Structure\StructureService;
use PHPUnit\Framework\TestCase;

final class StructureServiceTest extends TestCase
{
    private function service(FakeTransport $http): StructureService
    {
        return new StructureService(new RestClient($http, new OAuthClient($http, 'id', 'secret'), new MemoryPortalStore()));
    }

    private function portal(): Portal
    {
        return new Portal(1, 'm1', 'test.bitrix24.ru', 'https://test.bitrix24.ru/rest/', 'a', 'r', time() + 3600, 1);
    }

    public function testLoadsAllDepartmentPages(): void
    {
        $http = new FakeTransport([
            ['result' => [['ID' => '1', 'NAME' => 'Компания', 'UF_HEAD' => '100']], 'next' => 50, 'total' => 2],
            ['result' => [['ID' => '2', 'NAME' => 'Продажи', 'PARENT' => '1', 'UF_HEAD' => '0']], 'total' => 2],
        ]);

        $chart = $this->service($http)->orgChart($this->portal());

        self::assertSame(50, $http->requests[1]['params']['start']);
        self::assertSame(100, $chart->department(1)->headUserId);
        self::assertNull($chart->department(2)->headUserId);
        self::assertSame(1, $chart->department(2)->parentId);
    }

    public function testAsksOnlyIdAndDepartmentsOfUser(): void
    {
        $http = new FakeTransport([['result' => [['ID' => '7', 'UF_DEPARTMENT' => [3, 5]]]]]);

        $departments = $this->service($http)->departmentsOf($this->portal(), 7);

        self::assertSame([3, 5], $departments);
        self::assertSame(['ID', 'UF_DEPARTMENT'], $http->requests[0]['params']['select']);
    }

    public function testHumanResourcesUsesRestV3(): void
    {
        $http = new FakeTransport([
            ['result' => ['items' => [['id' => 4, 'name' => 'Продажи', 'parentId' => 1, 'accessCode' => 'DR2']]]],
            ['result' => ['item' => ['id' => 4, 'members' => [['userId' => 200, 'role' => 'MEMBER_HEAD'], ['userId' => 201, 'role' => 'MEMBER_EMPLOYEE']]]]],
        ]);

        $nodes = $this->service($http)->humanResourcesHeads($this->portal());

        self::assertSame('https://test.bitrix24.ru/rest/api/humanresources.node.list', $http->requests[0]['url']);
        self::assertSame('POST_JSON', $http->requests[0]['method']);
        self::assertSame([200], $nodes[0]['heads']);
        self::assertSame('DR2', $nodes[0]['accessCode']);
    }
}
