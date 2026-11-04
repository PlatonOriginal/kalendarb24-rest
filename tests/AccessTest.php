<?php
declare(strict_types=1);

namespace Kb24Rest\Tests;

use Kb24Rest\Bitrix24\OAuthClient;
use Kb24Rest\Bitrix24\RestClient;
use Kb24Rest\Portal\CurrentUser;
use Kb24Rest\Portal\Portal;
use Kb24Rest\Storage\EntityStorage;
use Kb24Rest\Storage\Schema;
use Kb24Rest\Structure\StructureService;
use Kb24Rest\Vacation\CalendarService;
use Kb24Rest\Vacation\LimitService;
use Kb24Rest\Vacation\RequestService;
use Kb24Rest\Vacation\RoleService;
use Kb24Rest\Vacation\Settings;
use Kb24Rest\Vacation\VacationError;
use Kb24Rest\Vacation\WorkCalendar;
use PHPUnit\Framework\TestCase;

/**
 * Вкладка «Доступ». Компания 1 (руководитель 1): продажи 5 без руководителя с подотделом 6, бухгалтерия 14 (руководитель 30).
 * В продажах 100 и 200, в подотделе 6 — 300, в бухгалтерии 30, 31, 40.
 */
final class AccessTest extends TestCase
{
    private const NONE = ['calendar' => 'none', 'balances' => 'none', 'routes' => 'none', 'settings' => 'none', 'access' => 'none'];

    private FakePortal $http;
    private Portal $portal;
    private RoleService $roles;
    private CalendarService $calendar;
    private RequestService $requests;

    protected function setUp(): void
    {
        $this->http = new FakePortal(
            [
                ['ID' => '1', 'NAME' => 'Компания', 'UF_HEAD' => '1'],
                ['ID' => '5', 'NAME' => 'Продажи', 'PARENT' => '1'],
                ['ID' => '6', 'NAME' => 'Опт', 'PARENT' => '5'],
                ['ID' => '14', 'NAME' => 'Бухгалтерия', 'PARENT' => '1', 'UF_HEAD' => '30'],
            ],
            [1 => [1], 30 => [14], 31 => [14], 40 => [14], 100 => [5], 200 => [5], 300 => [6]],
        );
        $this->portal = new Portal(1, 'm1', 'test.bitrix24.ru', 'https://test.bitrix24.ru/rest/', 'a', 'r', time() + 3600, 1);
        $this->rebuild();
    }

    private function rebuild(): void
    {
        $rest = new RestClient($this->http, new OAuthClient($this->http, 'id', 'secret'), new MemoryPortalStore());
        $storage = new EntityStorage($rest);
        $structure = new StructureService($rest);
        $settings = new Settings($rest);
        $this->roles = new RoleService($storage, $structure, $settings);
        $this->calendar = new CalendarService($structure, $this->roles, new LimitService($storage, $structure), $settings);
        $this->requests = new RequestService($storage, $structure, $this->roles, new WorkCalendar($rest), new \DateTimeImmutable('2026-10-27'), true);
    }

    private function user(int $id, bool $isAdmin = false): CurrentUser
    {
        return new CurrentUser($this->portal, $id, $isAdmin);
    }

    private function saveRole(array $data): int
    {
        $id = $this->roles->save($this->user(1, true), $data + ['userIds' => [], 'departmentIds' => [], 'tabs' => self::NONE, 'scope' => 'dept'])['id'];
        $this->rebuild();

        return $id;
    }

    public function testOldRolesAndOptionMoveToAccessRoles(): void
    {
        $this->http->items[Schema::ROLES] = [
            7 => ['ID' => '7', 'PROPERTY_VALUES' => ['ROLE' => 'director', 'USER_ID' => '1']],
            8 => ['ID' => '8', 'PROPERTY_VALUES' => ['ROLE' => 'hr', 'USER_ID' => '40']],
            9 => ['ID' => '9', 'PROPERTY_VALUES' => ['ROLE' => 'route_editor', 'USER_ID' => '31']],
        ];
        $this->http->options = ['employees_see_other_departments' => '1'];

        $roles = $this->roles->all($this->portal);

        self::assertSame(['Сотрудник', 'Директор', 'Настройка маршрутов', 'Отдел кадров'], array_column($roles, 'name'));
        self::assertSame('all', $roles[0]['scope']);
        self::assertSame(['director' => 1, 'hr' => [40]], $this->roles->roles($this->portal));
        self::assertTrue($this->roles->can($this->user(40), 'balances', RoleService::EDIT));
        self::assertTrue($this->roles->can($this->user(1), 'balances', RoleService::VIEW));
        self::assertFalse($this->roles->can($this->user(1), 'balances', RoleService::EDIT));
        self::assertTrue($this->roles->can($this->user(31), 'routes', RoleService::EDIT));
        self::assertFalse($this->roles->can($this->user(100), 'routes'));
        self::assertTrue($this->roles->can($this->user(100), 'calendar'));

        // Второй раз не переносим
        $this->rebuild();
        self::assertCount(4, $this->roles->all($this->portal));
    }

    public function testDepartmentMembersAndWidestRightWins(): void
    {
        $this->saveRole(['name' => 'Продажи', 'departmentIds' => [5], 'tabs' => ['balances' => 'view'] + self::NONE, 'scope' => 'sub']);
        $this->saveRole(['name' => 'Остатки', 'userIds' => [300], 'tabs' => ['balances' => 'edit', 'calendar' => 'view'] + self::NONE]);

        // 300 в подотделе продаж: входит и в «Продажи»
        $access = $this->roles->permissions($this->user(300));
        self::assertSame('edit', $access['tabs']['balances']);
        self::assertSame('view', $access['tabs']['calendar']);
        self::assertSame('sub', $access['scope']);
        self::assertSame('view', $this->roles->permissions($this->user(100))['tabs']['balances']);
        self::assertSame('none', $this->roles->permissions($this->user(31))['tabs']['balances']);
        self::assertSame('edit', $this->roles->permissions($this->user(31, true))['tabs']['access'], 'администратор может всё');
    }

    public function testCalendarAndCardsFollowScope(): void
    {
        $this->roles->set($this->portal, RoleService::DIRECTOR, [1]);
        $this->rebuild();
        $request = $this->requests->create($this->user(300), 'vacation', '2026-11-09', '2026-11-13');
        $this->rebuild();

        self::assertSame([5], $this->calendar->visibleDepartmentIds($this->user(100)));
        self::assertSame([14], $this->calendar->visibleDepartmentIds($this->user(31)));
        self::assertSame([1, 5, 6, 14], $this->calendar->visibleDepartmentIds($this->user(1)), 'руководитель видит отделы, которыми руководит, с подотделами');
        try
        {
            $this->requests->card($this->user(100), $request['id']);
            self::fail('Заявку подотдела увидел сотрудник без такой роли');
        }
        catch (VacationError)
        {
        }

        $base = $this->roles->all($this->portal)[0];
        $this->saveRole(['scope' => 'sub'] + $base);
        self::assertSame([5, 6], $this->calendar->visibleDepartmentIds($this->user(100)));
        self::assertSame($request['id'], $this->requests->card($this->user(100), $request['id'])['request']['id']);
        $card = $this->requests->card($this->user(100), $request['id']);
        self::assertFalse($card['can']['approve'], 'видеть заявку не значит её согласовывать');

        $this->saveRole(['tabs' => self::NONE] + $base);
        self::assertSame([], $this->calendar->visibleDepartmentIds($this->user(100)), 'без вкладки «Календарь» отделов нет');
    }

    public function testNobodyButAdminLosesAccessEditing(): void
    {
        $id = $this->saveRole(['name' => 'Доступ', 'userIds' => [31], 'tabs' => ['access' => 'edit'] + self::NONE]);
        $this->roles->save($this->user(31), ['id' => $id, 'name' => 'Доступ и маршруты', 'userIds' => [31, 40], 'tabs' => ['access' => 'edit', 'routes' => 'edit'] + self::NONE, 'scope' => 'dept']);
        $this->rebuild();
        try
        {
            $this->roles->save($this->user(31), ['id' => $id, 'name' => 'Доступ', 'userIds' => [40], 'tabs' => ['access' => 'edit'] + self::NONE, 'scope' => 'dept']);
            self::fail('Лишил себя права настраивать роли');
        }
        catch (VacationError $e)
        {
            self::assertStringContainsString('только администратор портала', $e->getMessage());
        }
        $this->expectExceptionMessage('Роли настраивают');
        $this->roles->save($this->user(100), ['name' => 'Своя', 'tabs' => self::NONE]);
    }

    public function testBaseAndLegacyRolesAreNotDeleted(): void
    {
        $roles = $this->roles->all($this->portal);
        foreach ([$roles[0], $this->roles->byLegacy($this->portal, RoleService::HR)] as $role)
        {
            try
            {
                $this->roles->delete($this->user(1, true), $role['id']);
                self::fail('Удалил роль «' . $role['name'] . '»');
            }
            catch (VacationError)
            {
            }
        }
        $id = $this->saveRole(['name' => 'Временная']);
        $this->roles->delete($this->user(1, true), $id);
        $this->rebuild();
        self::assertNull($this->roles->byId($this->portal, $id));

        $this->expectExceptionMessage('уже есть');
        $this->saveRole(['name' => 'отдел кадров']);
    }
}
