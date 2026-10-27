<?php
declare(strict_types=1);

namespace Kb24Rest\Tests;

use Kb24Rest\Structure\Department;
use Kb24Rest\Structure\OrgChart;
use PHPUnit\Framework\TestCase;

final class OrgChartTest extends TestCase
{
    /**
     * Компания (1, директор 100) → Продажи (2, руководитель 200) → Регион (3, без руководителя).
     */
    private function chart(): OrgChart
    {
        return new OrgChart([
            new Department(1, 'Компания', null, 100),
            new Department(2, 'Продажи', 1, 200),
            new Department(3, 'Регион', 2, null),
        ]);
    }

    public function testEmployeeGetsHeadOfOwnDepartment(): void
    {
        self::assertSame(200, $this->chart()->nearestHead(201, 2));
    }

    public function testHeadGetsHeadOfParentDepartment(): void
    {
        self::assertSame(100, $this->chart()->nearestHead(200, 2));
    }

    public function testDepartmentWithoutHeadGoesUp(): void
    {
        self::assertSame(200, $this->chart()->nearestHead(301, 3));
    }

    public function testDirectorHasNoHead(): void
    {
        self::assertNull($this->chart()->nearestHead(100, 1));
    }

    public function testUnknownDepartment(): void
    {
        self::assertNull($this->chart()->nearestHead(1, 99));
    }

    public function testHeadChainGoesToDirector(): void
    {
        $departments = [301 => 3, 200 => 2, 100 => 1];

        self::assertSame([200, 100], $this->chart()->headChain(301, fn(int $id) => $departments[$id] ?? null));
    }

    public function testCycleInTreeDoesNotHang(): void
    {
        $chart = new OrgChart([new Department(1, 'A', 2, null), new Department(2, 'B', 1, null)]);

        self::assertNull($chart->nearestHead(5, 1));
    }
}
