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
use Kb24Rest\Vacation\Notifier;
use Kb24Rest\Vacation\RequestService;
use Kb24Rest\Vacation\RoleService;
use Kb24Rest\Vacation\RouteService;
use Kb24Rest\Vacation\Settings;
use Kb24Rest\Vacation\VacationError;
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
    private Portal $portal;

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
        $this->roles = new RoleService($storage);
        $this->notifier = new Notifier($rest, 'local.app.code');
        $this->routes = new RouteService($storage, $this->roles, $structure, $settings);
        $this->service = new RequestService(
            $storage, $structure, $this->roles, new WorkCalendar($rest), new \DateTimeImmutable('2026-10-27'), $allowSelfApproval,
            $this->notifier, null, $settings, null, null, $this->routes,
        );
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

    public function testWithoutRouteDefaultChainWorks(): void
    {
        $this->saveRoute(['departmentIds' => [5], 'steps' => [$this->step('Директор', 'director')]]);

        $request = $this->service->create($this->user(self::ACCOUNTANT), 'vacation', '2026-11-09', '2026-11-13');

        self::assertSame(Status::HEAD_REVIEW, $request['status']);
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
            self::assertSame('Заявку нужно подать не позже чем за 7 дн. до начала', $e->getMessage());
        }
        self::assertSame(Status::HEAD_REVIEW, $this->service->create($this->user(self::SALES_MANAGER), 'unpaid', '2026-10-30', '2026-10-30')['status']);
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

        $route = $this->routes->all($this->portal)[0];
        $route['steps'][1]['active'] = false;
        $this->saveRoute(['id' => $routeId] + $route);

        $request = $this->approve(self::ACCOUNTING_HEAD, $request['id']);
        self::assertSame(Status::APPROVED, $request['status']);
    }

    public function testOnlyAdminAndEditorsChangeRoutes(): void
    {
        try
        {
            $this->routes->list($this->user(self::LAWYER));
            self::fail('Маршруты увидел не тот, кто их настраивает');
        }
        catch (VacationError)
        {
        }
        $this->routes->saveEditors($this->admin(), [self::LAWYER]);
        $this->rebuild();

        self::assertSame([self::LAWYER], $this->routes->list($this->user(self::LAWYER))['editorIds']);
        $this->expectExceptionMessage('Себя из списка может убрать только администратор портала');
        $this->routes->saveEditors($this->user(self::LAWYER), []);
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
        $steps = $this->routes->all($this->portal)[0]['steps'];

        $this->saveRoute(['id' => $routeId, 'steps' => [$steps[1], $this->step('В', 'head'), $steps[0]]]);

        $ids = array_column($this->routes->all($this->portal)[0]['steps'], 'id', 'name');
        self::assertSame(['Б' => $steps[1]['id'], 'В' => 3, 'А' => $steps[0]['id']], $ids);
    }
}
