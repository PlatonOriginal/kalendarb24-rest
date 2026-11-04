<?php
declare(strict_types=1);

namespace Kb24Rest\Tests;

use Kb24Rest\Bitrix24\OAuthClient;
use Kb24Rest\Bitrix24\RestClient;
use Kb24Rest\Domain\RoutePlan;
use Kb24Rest\Domain\Status;
use Kb24Rest\Portal\CurrentUser;
use Kb24Rest\Portal\Portal;
use Kb24Rest\Storage\EntityStorage;
use Kb24Rest\Structure\StructureService;
use Kb24Rest\Vacation\DashboardService;
use Kb24Rest\Vacation\Notifier;
use Kb24Rest\Vacation\RequestService;
use Kb24Rest\Vacation\RoleService;
use Kb24Rest\Vacation\RouteService;
use Kb24Rest\Vacation\Settings;
use Kb24Rest\Vacation\VacationError;
use Kb24Rest\Vacation\WatchService;
use Kb24Rest\Vacation\WorkCalendar;
use PHPUnit\Framework\TestCase;

/**
 * Маршруты согласования. Структура как в RequestServiceTest: директор 1, бухгалтерия 14 с руководителем 30,
 * продажи 5 без руководителя, ОК 40 и 41, юрист 50 в компании.
 */
final class RouteServiceTest extends TestCase
{
    private const DIRECTOR = 1;
    private const ACCOUNTING_HEAD = 30;
    private const ACCOUNTANT = 31;
    private const LAWYER = 50;
    private const SALES_MANAGER = 100;

    private FakePortal $http;
    private RoleService $roles;
    private RouteService $routes;
    private RequestService $service;
    private Notifier $notifier;
    private WatchService $watch;
    private DashboardService $dashboard;
    private Portal $portal;
    /** «Сегодня» для напоминаний: меняется в тестах напоминаний */
    private string $today = '2026-10-27';

    protected function setUp(): void
    {
        $this->http = new FakePortal(
            [
                ['ID' => '1', 'NAME' => 'Компания', 'UF_HEAD' => '1'],
                ['ID' => '5', 'NAME' => 'Продажи', 'PARENT' => '1'],
                ['ID' => '14', 'NAME' => 'Бухгалтерия', 'PARENT' => '1', 'UF_HEAD' => '30'],
            ],
            [1 => [1], 30 => [14], 31 => [14], 40 => [14], 41 => [14], 50 => [1], 100 => [5]],
        );
        $this->portal = new Portal(1, 'm1', 'test.bitrix24.ru', 'https://test.bitrix24.ru/rest/', 'a', 'r', time() + 3600, 1);
        // Демо-маршрут «Больничный» проверяется отдельно, остальным тестам он только сдвигает номера маршрутов
        $this->http->options = ['kind_routes_ready' => 1];
        $this->rebuild();
        $this->roles->set($this->portal, RoleService::DIRECTOR, [self::DIRECTOR]);
        $this->roles->set($this->portal, RoleService::HR, [40, 41]);
        $this->rebuild();
    }

    private function rebuild(bool $allowSelfApproval = false): void
    {
        $rest = new RestClient($this->http, new OAuthClient($this->http, 'id', 'secret'), new MemoryPortalStore());
        $storage = new EntityStorage($rest);
        $structure = new StructureService($rest);
        $settings = new Settings($rest);
        $this->roles = new RoleService($storage, $structure, $settings);
        $this->notifier = new Notifier($rest, 'local.app.code');
        $this->routes = new RouteService($storage, $this->roles, $structure, $settings);
        $this->watch = new WatchService($storage, $rest, $this->notifier, new \DateTimeImmutable($this->today));
        $this->service = new RequestService(
            $storage, $structure, $this->roles, new WorkCalendar($rest), new \DateTimeImmutable('2026-10-27'), $allowSelfApproval,
            $this->notifier, null, $settings, null, null, $this->routes, $this->watch,
        );
        $this->dashboard = new DashboardService($storage, $structure, $this->roles, null, new \DateTimeImmutable('2026-10-28 10:00:00+03:00'));
    }

    private function user(int $id, bool $isAdmin = false): CurrentUser
    {
        return new CurrentUser($this->portal, $id, $isAdmin);
    }

    private function admin(): CurrentUser
    {
        return $this->user(self::DIRECTOR, true);
    }

    private function step(string $name, string $kind, array $extra = []): array
    {
        return ['name' => $name, 'kind' => $kind] + $extra;
    }

    private function saveRoute(array $data): int
    {
        $id = $this->routes->save($this->admin(), $data + ['name' => 'Маршрут', 'active' => true])['id'];
        $this->rebuild();

        return $id;
    }

    private function approve(int $approverId, int $requestId): array
    {
        $this->rebuild();

        return $this->service->approve($this->user($approverId), $requestId);
    }

    public function testRequestFollowsRouteStepsAndCardShowsThem(): void
    {
        $this->saveRoute(['steps' => [
            $this->step('Руководитель', 'head'),
            $this->step('Юрист', 'user', ['userId' => self::LAWYER]),
            $this->step('Директор', 'director'),
        ]]);

        $request = $this->service->create($this->user(self::ACCOUNTANT), 'vacation', '2026-11-09', '2026-11-13');
        self::assertSame(Status::ROUTE_REVIEW, $request['status']);
        self::assertSame(self::ACCOUNTING_HEAD, $request['approverId']);
        self::assertSame('Руководитель', $request['stepName']);

        $request = $this->approve(self::ACCOUNTING_HEAD, $request['id']);
        self::assertSame(self::LAWYER, $request['approverId']);
        self::assertSame('Юрист', $request['stepName']);
        $sent = array_map(static fn(array $n) => [$n['event'], $n['to']], $this->notifier->pending());
        self::assertSame([['review', [self::LAWYER]], ['step', [self::ACCOUNTANT]]], $sent);

        $card = $this->service->card($this->user(self::ACCOUNTANT), $request['id']);
        self::assertSame(['done', 'current', 'next'], array_column($card['route']['steps'], 'state'));
        self::assertSame('Руководитель', $card['history'][1]['stepName']);

        $request = $this->approve(self::LAWYER, $request['id']);
        $request = $this->approve(self::DIRECTOR, $request['id']);
        self::assertSame(Status::APPROVED, $request['status']);
        $card = $this->service->card($this->user(self::ACCOUNTANT), $request['id']);
        self::assertSame(['done', 'done', 'done'], array_column($card['route']['steps'], 'state'));
    }

    public function testDepartmentRouteWinsOverCommonAndKindOverAny(): void
    {
        $this->saveRoute(['name' => 'Общий', 'steps' => [$this->step('Директор', 'director')]]);
        $this->saveRoute(['name' => 'Бухгалтерия', 'departmentIds' => [14], 'steps' => [$this->step('Юрист', 'user', ['userId' => self::LAWYER])]]);
        $this->saveRoute(['name' => 'Бухгалтерия, без сохранения', 'departmentIds' => [14], 'kinds' => ['unpaid'], 'steps' => [$this->step('ОК', 'hr')]]);

        self::assertSame('Бухгалтерия', $this->routes->findFor($this->portal, 14, 'vacation')['name']);
        self::assertSame('Бухгалтерия, без сохранения', $this->routes->findFor($this->portal, 14, 'unpaid')['name']);
        self::assertSame('Общий', $this->routes->findFor($this->portal, 5, 'unpaid')['name']);
    }

    public function testExclusiveRouteForKindsIsTheOnlyOne(): void
    {
        $this->saveRoute(['name' => 'Учебный', 'exclusive' => true, 'kinds' => ['study'], 'steps' => [$this->step('ОК', 'hr')]]);
        $this->saveRoute(['name' => 'Бухгалтерия', 'departmentIds' => [14], 'steps' => [$this->step('Директор', 'director')]]);

        self::assertSame('Учебный', $this->routes->findFor($this->portal, 14, 'study')['name']);

        $this->expectException(VacationError::class);
        $this->saveRoute(['name' => 'Второй учебный', 'exclusive' => true, 'kinds' => ['study', 'unpaid'], 'steps' => [$this->step('ОК', 'hr')]]);
    }

    public function testWithoutMatchingRouteDefaultRouteWorks(): void
    {
        $this->saveRoute(['departmentIds' => [5], 'steps' => [$this->step('Директор', 'director')]]);

        $request = $this->service->create($this->user(self::ACCOUNTANT), 'vacation', '2026-11-09', '2026-11-13');
        self::assertSame([Status::ROUTE_REVIEW, 'Руководитель', self::ACCOUNTING_HEAD], [$request['status'], $request['stepName'], $request['approverId']]);

        // Как прежняя цепочка: руководитель, один сотрудник ОК, директор
        $request = $this->approve(self::ACCOUNTING_HEAD, $request['id']);
        self::assertSame('Отдел кадров', $request['stepName']);
        self::assertContains($request['approverId'], [40, 41]);
        $request = $this->approve($request['approverId'], $request['id']);
        self::assertSame([self::DIRECTOR, 'Директор'], [$request['approverId'], $request['stepName']]);
        self::assertSame(Status::APPROVED, $this->approve(self::DIRECTOR, $request['id'])['status']);
    }

    public function testDefaultRouteSkipsDirectorWhoApprovedAsHeadAndSecondHr(): void
    {
        // В продажах нет руководителя: шаг руководителя у директора, третий шаг пропускается
        $request = $this->service->create($this->user(self::SALES_MANAGER), 'vacation', '2026-11-09', '2026-11-13');
        $request = $this->approve(self::DIRECTOR, $request['id']);
        $request = $this->approve($request['approverId'], $request['id']);
        self::assertSame(Status::APPROVED, $request['status']);

        // Директор подаёт сам: руководителя нет, шаг уходит в ОК, и второй раз ОК не спрашивают
        $own = $this->service->create($this->user(self::DIRECTOR), 'vacation', '2026-11-16', '2026-11-20');
        self::assertContains($own['approverId'], [40, 41]);
        self::assertSame(Status::APPROVED, $this->approve($own['approverId'], $own['id'])['status']);
    }

    public function testDefaultRouteIsEditableButNotRemovable(): void
    {
        $default = $this->routes->all($this->portal)[0];
        self::assertTrue($default['isDefault']);
        $default['steps'][0]['submitDays'] = 3;
        $default['kinds'] = ['study'];
        $this->saveRoute($default);

        $saved = $this->routes->all($this->portal)[0];
        self::assertSame([true, [], 3], [$saved['isDefault'], $saved['kinds'], $saved['steps'][0]['submitDays']]);
        self::assertCount(1, $this->routes->all($this->portal));

        $this->expectExceptionMessage('Маршрут «По умолчанию» удалить нельзя');
        $this->routes->delete($this->admin(), $saved['id']);
    }

    public function testSubmitDeadlineComesFromRouteOrSettings(): void
    {
        $this->saveRoute(['name' => 'Заранее', 'departmentIds' => [14], 'steps' => [$this->step('Руководитель', 'head', ['submitDays' => 14])]]);
        try
        {
            $this->service->create($this->user(self::ACCOUNTANT), 'vacation', '2026-11-02', '2026-11-06');
            self::fail('Срок подачи по маршруту не проверен');
        }
        catch (VacationError $e)
        {
            self::assertSame('По маршруту «Заранее» заявку нужно подать не позже чем за 14 дн. до начала', $e->getMessage());
        }
        self::assertSame(Status::ROUTE_REVIEW, $this->service->create($this->user(self::ACCOUNTANT), 'vacation', '2026-11-10', '2026-11-12')['status']);

        // Без маршрута — срок из настроек; отпуск без сохранения можно освободить от срока
        $this->http->options = ['min_submit_days' => '7', 'min_submit_exempt_unpaid' => '1'];
        $this->rebuild();
        try
        {
            $this->service->create($this->user(self::SALES_MANAGER), 'vacation', '2026-10-30', '2026-10-30');
            self::fail('Срок подачи из настроек не проверен');
        }
        catch (VacationError $e)
        {
            self::assertSame('По маршруту «По умолчанию» заявку нужно подать не позже чем за 7 дн. до начала', $e->getMessage());
        }
        self::assertSame(Status::ROUTE_REVIEW, $this->service->create($this->user(self::SALES_MANAGER), 'unpaid', '2026-10-30', '2026-10-30')['status']);
    }

    public function testAuthorAndThoseWhoApprovedAreSkipped(): void
    {
        // Директор на двух шагах согласует один раз
        $this->saveRoute(['steps' => [
            $this->step('Руководитель', 'head'),
            $this->step('Директор', 'director'),
            $this->step('Юрист', 'user', ['userId' => self::LAWYER]),
            $this->step('Директор ещё раз', 'user', ['userId' => self::DIRECTOR]),
        ]]);

        $request = $this->service->create($this->user(self::ACCOUNTANT), 'vacation', '2026-11-09', '2026-11-13');
        $request = $this->approve(self::ACCOUNTING_HEAD, $request['id']);
        $request = $this->approve(self::DIRECTOR, $request['id']);
        $request = $this->approve(self::LAWYER, $request['id']);
        self::assertSame(Status::APPROVED, $request['status']);

        // Руководитель бухгалтерии подаёт сам: его руководитель — директор, и шаг «Директор» второй раз не спрашивают
        $own = $this->service->create($this->user(self::ACCOUNTING_HEAD), 'vacation', '2026-12-07', '2026-12-11');
        self::assertSame([self::DIRECTOR, 'Руководитель'], [$own['approverId'], $own['stepName']]);
        $own = $this->approve(self::DIRECTOR, $own['id']);
        self::assertSame(self::LAWYER, $own['approverId']);
        $own = $this->approve(self::LAWYER, $own['id']);
        self::assertSame(Status::APPROVED, $own['status']);
    }

    public function testStepWithoutApproverGoesToHr(): void
    {
        // У директора руководителя нет: шаг «Руководитель» уходит в ОК, это видно в истории
        $this->saveRoute(['steps' => [$this->step('Руководитель', 'head'), $this->step('Директор', 'director')]]);

        $request = $this->service->create($this->user(self::DIRECTOR), 'vacation', '2026-11-09', '2026-11-13');

        self::assertContains($request['approverId'], [40, 41]);
        self::assertSame('Руководитель', $request['stepName']);
        $card = $this->service->card($this->user(self::DIRECTOR), $request['id']);
        self::assertSame(['create', 'reroute'], array_column($card['history'], 'action'));
    }

    public function testRejectIsBlockedTooCloseToStart(): void
    {
        $this->saveRoute(['steps' => [
            $this->step('Руководитель', 'head', ['rejectDays' => 3]),
            $this->step('Директор', 'director', ['rejectDays' => 10]),
        ]]);
        $request = $this->service->create($this->user(self::ACCOUNTANT), 'vacation', '2026-11-02', '2026-11-06');

        // До начала 6 дней: руководитель (срок 3 дн.) ещё может отклонить, директор (10 дн.) уже нет
        self::assertTrue($this->service->card($this->user(self::ACCOUNTING_HEAD), $request['id'])['can']['reject']);

        $request = $this->approve(self::ACCOUNTING_HEAD, $request['id']);
        $card = $this->service->card($this->user(self::DIRECTOR), $request['id']);
        self::assertFalse($card['can']['reject']);
        self::assertSame('Отклонить на шаге «Директор» можно не позже чем за 10 дн. до начала', $card['rejectBlocked']);
        try
        {
            $this->service->reject($this->user(self::DIRECTOR), $request['id'], array_key_first(\Kb24Rest\Vacation\RejectReasons::all()));
            self::fail('Отказ после срока не запрещён');
        }
        catch (VacationError $e)
        {
            self::assertSame('Отклонить на шаге «Директор» можно не позже чем за 10 дн. до начала', $e->getMessage());
        }
    }

    public function testRejectOnRouteStepIsShownOnThatStep(): void
    {
        $this->saveRoute(['steps' => [$this->step('Руководитель', 'head'), $this->step('Директор', 'director')]]);
        $request = $this->service->create($this->user(self::ACCOUNTANT), 'vacation', '2026-11-09', '2026-11-13');
        $request = $this->approve(self::ACCOUNTING_HEAD, $request['id']);

        $this->rebuild();
        $this->service->reject($this->user(self::DIRECTOR), $request['id'], array_key_first(\Kb24Rest\Vacation\RejectReasons::all()));

        $card = $this->service->card($this->user(self::ACCOUNTANT), $request['id']);
        self::assertSame(['done', 'rejected'], array_column($card['route']['steps'], 'state'));
    }

    public function testStepDisabledAfterSubmissionIsSkipped(): void
    {
        $routeId = $this->saveRoute(['steps' => [
            $this->step('Руководитель', 'head'),
            $this->step('Юрист', 'user', ['userId' => self::LAWYER]),
        ]]);
        $request = $this->service->create($this->user(self::ACCOUNTANT), 'vacation', '2026-11-09', '2026-11-13');

        $route = $this->routes->all($this->portal)[1];
        $route['steps'][1]['active'] = false;
        $this->saveRoute(['id' => $routeId] + $route);

        $request = $this->approve(self::ACCOUNTING_HEAD, $request['id']);
        self::assertSame(Status::APPROVED, $request['status']);
    }

    public function testRoutesTabFollowsAccessRoles(): void
    {
        try
        {
            $this->routes->list($this->user(self::LAWYER));
            self::fail('Маршруты увидел тот, кому вкладка не открыта');
        }
        catch (VacationError)
        {
        }
        $tabs = ['calendar' => 'view', 'balances' => 'none', 'routes' => 'view', 'settings' => 'none', 'access' => 'none'];
        $role = $this->roles->save($this->admin(), ['name' => 'Юристы', 'userIds' => [self::LAWYER], 'tabs' => $tabs, 'scope' => 'dept']);
        $this->rebuild();

        $list = $this->routes->list($this->user(self::LAWYER));
        self::assertFalse($list['canEdit']);
        self::assertContains('Юристы', array_column($list['roles'], 'name'));
        try
        {
            $this->routes->save($this->user(self::LAWYER), ['name' => 'Юр', 'steps' => [$this->step('Руководитель', 'head')]]);
            self::fail('Сохранил маршрут с правом только смотреть');
        }
        catch (VacationError)
        {
        }

        $this->roles->save($this->admin(), ['id' => $role['id'], 'name' => 'Юристы', 'userIds' => [self::LAWYER], 'tabs' => ['routes' => 'edit'] + $tabs, 'scope' => 'dept']);
        $this->rebuild();
        self::assertTrue($this->routes->list($this->user(self::LAWYER))['canEdit']);
        $this->expectExceptionMessage('указана в маршрутах');
        $this->saveRoute(['steps' => [['name' => 'Юрист', 'kind' => 'role', 'roleId' => $role['id']]]]);
        $this->rebuild();
        $this->roles->delete($this->admin(), $role['id'], $this->routes->usingRole($this->portal, $role['id']));
    }

    public function testRouteIsValidated(): void
    {
        foreach ([
            [['name' => '', 'steps' => [$this->step('Директор', 'director')]], 'Укажите название маршрута'],
            [['steps' => []], 'В маршруте от 1 до ' . RoutePlan::MAX_STEPS . ' шагов'],
            [['steps' => [$this->step('Кто-то', 'user')]], 'Шаг 1: выберите сотрудника'],
            [['steps' => [$this->step('Директор', 'director', ['submitDays' => 400])]], 'Шаг 1: сроки указываются в днях от 0 до 365'],
            [['departmentIds' => [999], 'steps' => [$this->step('Директор', 'director')]], 'Отдел не найден в структуре компании'],
        ] as [$data, $message])
        {
            try
            {
                $this->routes->save($this->admin(), $data + ['name' => 'Маршрут']);
                self::fail('Не проверено: ' . $message);
            }
            catch (VacationError $e)
            {
                self::assertSame($message, $e->getMessage());
            }
        }
    }

    public function testStepIdsStayStableWhenStepsMove(): void
    {
        $routeId = $this->saveRoute(['steps' => [$this->step('А', 'director'), $this->step('Б', 'hr')]]);
        $steps = $this->routes->all($this->portal)[1]['steps'];

        $this->saveRoute(['id' => $routeId, 'steps' => [$steps[1], $this->step('В', 'head'), $steps[0]]]);

        $ids = array_column($this->routes->all($this->portal)[1]['steps'], 'id', 'name');
        self::assertSame(['Б' => $steps[1]['id'], 'В' => 3, 'А' => $steps[0]['id']], $ids);
    }

    /**
     * Маршрут: руководитель и директор; уведомить юриста (сразу задача, за 3 дня) и ОК (спросить про задачу, за 5 дней).
     */
    private function approvedWithWatchers(array $watchers = []): array
    {
        $this->saveRoute(['steps' => [$this->step('Руководитель', 'head'), $this->step('Директор', 'director')], 'watchers' => $watchers ?: [
            ['kind' => 'user', 'userId' => self::LAWYER, 'events' => ['approved', 'cancelled'], 'task' => 'auto', 'taskDays' => 3],
            ['kind' => 'hr', 'events' => ['approved'], 'task' => 'ask', 'taskDays' => 5],
            // Автора не уведомляем, даже если он попал в список
            ['kind' => 'user', 'userId' => self::ACCOUNTANT, 'events' => ['approved'], 'task' => 'none'],
        ]]);
        $request = $this->service->create($this->user(self::ACCOUNTANT), 'vacation', '2026-11-09', '2026-11-13');
        $request = $this->approve(self::ACCOUNTING_HEAD, $request['id']);

        return $this->approve(self::DIRECTOR, $request['id']);
    }

    public function testWatchersAreNotifiedAndAutoTaskIsCreatedAfterResponse(): void
    {
        $request = $this->approvedWithWatchers();
        self::assertSame(Status::APPROVED, $request['status']);

        $watch = array_values(array_filter($this->notifier->pending(), static fn(array $n) => $n['event'] === 'watch'));
        // Из роли уведомляются все её участники
        self::assertSame([[self::LAWYER], [40], [41]], array_column($watch, 'to'));
        self::assertSame(['approved', 'approved', 'approved'], array_column($watch, 'reason'));

        self::assertSame([], $this->http->tasks, 'задачу ставим после ответа браузеру');
        $this->watch->run();
        self::assertCount(1, $this->http->tasks);
        $task = $this->http->tasks[0];
        self::assertSame([self::LAWYER, self::ACCOUNTANT, '2026-11-06T18:00:00'], [$task['RESPONSIBLE_ID'], $task['CREATED_BY'], $task['DEADLINE']]);
        self::assertSame('Отпуск: Фамилия31 Имя31, 09.11.2026–13.11.2026, ежегодный оплачиваемый отпуск', $task['TITLE']);

        $card = $this->service->card($this->user(self::LAWYER), $request['id']);
        self::assertSame(5001, $card['myAck']['taskId']);
        self::assertSame([self::LAWYER, 40, 41], array_column($card['acks'], 'userId'));
    }

    public function testAcknowledgeWithOptionalTask(): void
    {
        $request = $this->approvedWithWatchers();
        $this->rebuild();
        $card = $this->service->card($this->user(40), $request['id']);
        self::assertSame(['ask', '2026-11-04'], [$card['myAck']['taskMode'], $card['myAck']['deadline']]);
        self::assertCount(1, $this->service->toAcknowledge($this->user(40)));

        try
        {
            $this->service->acknowledge($this->user(40), $request['id'], true, '2026-10-01');
            self::fail('Срок в прошлом принят');
        }
        catch (VacationError $e)
        {
            self::assertSame('Срок задачи: укажите дату не раньше сегодняшней', $e->getMessage());
        }
        $this->service->acknowledge($this->user(40), $request['id'], true, '2026-11-02');

        self::assertSame([40, '2026-11-02T18:00:00'], [$this->http->tasks[0]['RESPONSIBLE_ID'], $this->http->tasks[0]['DEADLINE']]);
        $card = $this->service->card($this->user(40), $request['id']);
        self::assertNull($card['myAck']);
        self::assertSame([], $this->service->toAcknowledge($this->user(40)));
        $this->expectExceptionMessage('Подтверждать ознакомление по этой заявке не нужно');
        $this->service->acknowledge($this->user(40), $request['id'], false, '');
    }

    public function testTaskRefusalKeepsAcknowledgementPending(): void
    {
        $request = $this->approvedWithWatchers();
        $this->http->taskError = ['error' => 'insufficient_scope', 'error_description' => 'нет права task'];
        $this->rebuild();
        try
        {
            $this->service->acknowledge($this->user(40), $request['id'], true, '2026-11-02');
            self::fail('Ошибка портала не показана');
        }
        catch (VacationError $e)
        {
            self::assertStringStartsWith('Не удалось поставить задачу', $e->getMessage());
        }
        self::assertNotNull($this->service->card($this->user(40), $request['id'])['myAck']);
        // Без задачи ознакомиться можно
        $this->service->acknowledge($this->user(40), $request['id'], false, '');
        self::assertNull($this->service->card($this->user(40), $request['id'])['myAck']);
    }

    public function testRemindersOnWorkingDaysBeforeStartUntilAcknowledged(): void
    {
        $request = $this->approvedWithWatchers();
        $this->watch->run();
        $this->rebuild();
        $this->service->acknowledge($this->user(self::LAWYER), $request['id'], false, '');

        $remind = function (string $day, bool $working = true): array {
            $this->today = $day;
            $this->rebuild();
            $this->watch->remind($this->portal, $working);

            return array_column(array_filter($this->notifier->pending(), static fn(array $n) => $n['event'] === 'remind'), 'to');
        };
        self::assertSame([], $remind('2026-10-27'), 'в день уведомления не напоминаем');
        self::assertSame([[40], [41]], $remind('2026-10-28'));
        self::assertSame([], $remind('2026-10-28'), 'повторный запуск в тот же день');
        self::assertSame([], $remind('2026-10-31', false), 'выходной');
        self::assertSame([], $remind('2026-11-09'), 'отсутствие уже началось');
    }

    private function cancelRouteId(string $name = 'Отзыв по умолчанию'): int
    {
        return array_column($this->routes->cancelRoutes($this->portal), 'id', 'name')[$name];
    }

    private function cancel(int $requestId): array
    {
        $this->rebuild();
        $request = $this->service->startCancellation($this->user(self::ACCOUNTANT), $requestId, 'Перенос');
        $this->rebuild();

        return $request;
    }

    public function testCancellationGoesByDemoCancelRouteAndNotifiesItsWatchers(): void
    {
        $request = $this->approvedWithWatchers();
        $demo = $this->routes->cancelRoutes($this->portal)[0];
        self::assertSame(['Руководитель', 'Отдел кадров'], array_column($demo['steps'], 'name'));
        $this->saveRoute(['purpose' => 'cancel', 'id' => $demo['id'], 'name' => $demo['name'], 'steps' => $demo['steps'],
            'watchers' => [['kind' => 'user', 'userId' => self::LAWYER, 'events' => ['approved'], 'task' => 'auto', 'taskDays' => 3]]]);

        $request = $this->cancel($request['id']);
        self::assertSame([Status::CANCELLING, self::ACCOUNTING_HEAD, 'Руководитель'], [$request['status'], $request['approverId'], $request['cancelStepName']]);
        $request = $this->service->approveCancellation($this->user(self::ACCOUNTING_HEAD), $request['id']);
        self::assertSame('Отдел кадров', $request['cancelStepName']);
        self::assertContains($request['approverId'], [40, 41]);
        $card = $this->service->card($this->user(self::ACCOUNTANT), $request['id']);
        self::assertSame(['done', 'current'], array_column($card['cancelRoute']['steps'], 'state'));
        $this->rebuild();
        $request = $this->service->approveCancellation($this->user($request['approverId']), $request['id']);
        self::assertSame(Status::CANCELLED, $request['status']);

        // Об отмене — получатели маршрута отзыва, а не маршрута согласования (там юрист был и на отмену)
        $watch = array_values(array_filter($this->notifier->pending(), static fn(array $n) => $n['event'] === 'watch' && $n['reason'] === 'cancelled'));
        self::assertSame([[self::LAWYER]], array_column($watch, 'to'));
        $this->watch->run();
        self::assertStringStartsWith('Отмена отпуска: ', end($this->http->tasks)['TITLE']);
        $history = $this->service->card($this->user(self::ACCOUNTANT), $request['id'])['history'];
        self::assertSame(['Руководитель', 'Отдел кадров'], array_column(array_filter($history, static fn(array $h) => $h['action'] === 'cancel_approve'), 'stepName'));
    }

    public function testWithoutCancelRouteAbsenceIsCancelledAtOnceAndApproversAreNotified(): void
    {
        $request = $this->approvedWithWatchers();
        $this->routes->delete($this->admin(), $this->cancelRouteId());
        $this->rebuild();
        $request = $this->service->startCancellation($this->user(self::ACCOUNTANT), $request['id'], 'Перенос');

        self::assertSame(Status::CANCELLED, $request['status']);
        $cancelled = array_values(array_filter($this->notifier->pending(), static fn(array $n) => $n['event'] === 'cancelled'));
        self::assertEqualsCanonicalizing([self::ACCOUNTING_HEAD, self::DIRECTOR], $cancelled[0]['to'], 'согласовавшие заявку');
        self::assertSame([], array_values(array_filter($this->notifier->pending(), static fn(array $n) => $n['event'] === 'watch' && $n['reason'] === 'cancelled')));
    }

    public function testCancelRouteIsPickedByKindAndDepartment(): void
    {
        $this->saveRoute(['purpose' => 'cancel', 'name' => 'Отзыв бухгалтерии', 'departmentIds' => [14], 'steps' => [$this->step('Юрист', 'user', ['userId' => self::LAWYER])]]);
        $request = $this->approvedWithWatchers();
        $request = $this->cancel($request['id']);
        self::assertSame([self::LAWYER, 'Юрист'], [$request['approverId'], $request['cancelStepName']]);

        $this->rebuild();
        $request = $this->service->refuseCancellation($this->user(self::LAWYER), $request['id'], 'Некому заменить');
        self::assertSame(Status::APPROVED, $request['status']);
        $card = $this->service->card($this->user(self::ACCOUNTANT), $request['id']);
        self::assertSame(['rejected'], array_column($card['cancelRoute']['steps'], 'state'));
    }

    public function testOldCancelledWatchersMoveToCancelRoutes(): void
    {
        $this->http->items['kbroute'] = [
            7 => ['ID' => '7', 'PROPERTY_VALUES' => ['ROUTE_NAME' => 'Юристы', 'IS_DEFAULT' => 'N', 'ACTIVE' => 'Y', 'EXCLUSIVE' => 'N', 'KINDS' => 'study', 'DEPTS' => '14', 'SORT' => '100',
                'STEPS' => json_encode([['id' => 1, 'name' => 'Руководитель', 'kind' => 'head', 'active' => true]]),
                'WATCHERS' => json_encode([['kind' => 'user', 'userId' => self::LAWYER, 'events' => ['approved', 'cancelled'], 'task' => 'none', 'taskDays' => 0],
                    ['kind' => 'hr', 'events' => ['cancelled'], 'task' => 'ask', 'taskDays' => 2]])]],
        ];
        $this->rebuild();

        $cancel = $this->routes->cancelRoutes($this->portal);
        self::assertSame(['Отзыв по умолчанию', 'Отзыв: Юристы'], array_column($cancel, 'name'));
        $moved = $cancel[1];
        self::assertSame([['study'], [14]], [$moved['kinds'], $moved['departmentIds']]);
        self::assertSame([[self::LAWYER, 'user'], [0, 'role']], array_map(static fn(array $w) => [$w['userId'], $w['kind']], $moved['watchers']));
        self::assertSame([['cancelled'], ['cancelled']], array_column($moved['watchers'], 'events'));
        $approve = array_values(array_filter($this->routes->all($this->portal), static fn(array $r) => $r['id'] === 7))[0];
        self::assertSame([['approved']], array_column($approve['watchers'], 'events'));

        // Второй раз не переносим
        $this->rebuild();
        self::assertCount(2, $this->routes->cancelRoutes($this->portal));
    }

    public function testRoleInCancelRouteIsNotDeletedButLegacyHrIsWhenUnused(): void
    {
        $hr = $this->roles->byLegacy($this->portal, RoleService::HR);
        $this->routes->cancelRoutes($this->portal);
        try
        {
            $this->roles->delete($this->admin(), $hr['id'], $this->routes->usingRole($this->portal, $hr['id']));
            self::fail('Удалил роль из маршрута отзыва');
        }
        catch (VacationError $e)
        {
            self::assertStringContainsString('Отзыв по умолчанию', $e->getMessage());
        }
        $director = $this->roles->byLegacy($this->portal, RoleService::DIRECTOR);
        $this->saveRoute(['isDefault' => true, 'name' => 'По умолчанию', 'steps' => [$this->step('Руководитель', 'head')]]);
        $this->roles->delete($this->admin(), $director['id'], $this->routes->usingRole($this->portal, $director['id']));
        $this->rebuild();
        self::assertNull($this->roles->byId($this->portal, $director['id']));
    }

    public function testWatchersAreValidated(): void
    {
        $this->expectExceptionMessage('Уведомление 1: выберите, кого уведомить');
        $this->saveRoute(['steps' => [$this->step('Директор', 'director')], 'watchers' => [['kind' => '', 'events' => []]]]);
    }

    public function testDashboardFollowsRolesAndShowsSpeedToDirector(): void
    {
        $request = $this->service->create($this->user(self::ACCOUNTANT), 'vacation', '2026-11-09', '2026-11-13');
        $this->approve(self::ACCOUNTING_HEAD, $request['id']);
        $this->rebuild();

        $data = $this->dashboard->build($this->user(self::DIRECTOR), 2026);
        $employee = array_values(array_filter($data['employees'], static fn(array $e) => $e['id'] === self::ACCOUNTANT))[0];
        self::assertSame(['planned', false, 5], [$employee['rest'], $employee['longPart'], $employee['vacationDays']]);
        self::assertSame(1, $data['load']['company'][10]['peak'], 'ноябрь');
        self::assertSame(4, array_column($data['departments'], 'headcount', 'id')[14]);
        $steps = $data['speed']['steps'];
        self::assertSame(['Руководитель', self::ACCOUNTING_HEAD, 0.0], [$steps[0]['step'], $steps[0]['approverId'], $steps[0]['hours']]);
        self::assertSame('Отдел кадров', $steps[1]['step']);
        self::assertNull($steps[1]['hours']);
        self::assertSame(24.0, $steps[1]['waitingHours']);

        // Отдел кадров видит дашборд без скорости согласования, остальным он закрыт
        self::assertNull($this->dashboard->build($this->user(40), 2026)['speed']);
        $this->expectExceptionMessage('Нет доступа к вкладке «Дашборд»');
        $this->dashboard->build($this->user(self::SALES_MANAGER), 2026);
    }

    public function testSickLeaveFiledByHrIsApprovedAtOnceAndMayOverlapVacation(): void
    {
        $this->http->options['kind_routes_ready'] = 0;
        $this->rebuild();
        $sick = array_values(array_filter($this->routes->all($this->portal), static fn(array $r) => $r['name'] === 'Больничный'))[0];
        self::assertSame([true, true, ['sick']], [$sick['autoApprove'], $sick['exclusive'], $sick['kinds']]);

        $this->service->create($this->user(self::ACCOUNTANT), 'vacation', '2026-11-09', '2026-11-13');
        $this->rebuild();
        // Отдел кадров вносит больничный задним числом, он приходится и на отпуск
        $request = $this->service->create($this->user(40), 'sick', '2026-10-20', '2026-11-10', self::ACCOUNTANT);
        self::assertSame([Status::APPROVED, self::ACCOUNTANT, 40, 22], [$request['status'], $request['userId'], $request['filedBy'], $request['days']]);
        $filed = array_values(array_filter($this->notifier->pending(), static fn(array $n) => $n['event'] === 'filed'));
        self::assertSame([[self::ACCOUNTANT]], array_column($filed, 'to'));
        self::assertContains($request['id'], array_column($this->service->mine($this->user(40)), 'id'), 'поданная за другого видна в «Моих заявках»');

        $data = $this->dashboard->build($this->user(self::DIRECTOR), 2026);
        $employee = array_values(array_filter($data['employees'], static fn(array $e) => $e['id'] === self::ACCOUNTANT))[0];
        self::assertSame([22, 1, 0], [$employee['sickDays'], $employee['sickCases'], $employee['dayoffDays']]);
    }

    public function testOnlyRoleWithRightFilesForOthersAndDayoffIsApproved(): void
    {
        try
        {
            $this->service->create($this->user(self::ACCOUNTING_HEAD), 'dayoff', '2026-11-20', '2026-11-20', self::ACCOUNTANT);
            self::fail('Подал за другого без права');
        }
        catch (VacationError $e)
        {
            self::assertStringContainsString('Подача заявок за сотрудников', $e->getMessage());
        }
        $request = $this->service->create($this->user(40), 'dayoff', '2026-11-20', '2026-11-20', self::ACCOUNTANT);
        self::assertSame([Status::ROUTE_REVIEW, self::ACCOUNTING_HEAD], [$request['status'], $request['approverId']]);
        $this->rebuild();
        self::assertTrue($this->service->card($this->user(40), $request['id'])['can']['withdraw'], 'подавший может отозвать');
        $this->expectExceptionMessage('прошедшие даты');
        $this->service->create($this->user(self::ACCOUNTANT), 'dayoff', '2026-10-20', '2026-10-20');
    }
}
