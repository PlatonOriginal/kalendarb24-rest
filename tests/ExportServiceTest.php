<?php
declare(strict_types=1);

namespace Kb24Rest\Tests;

use Kb24Rest\Bitrix24\OAuthClient;
use Kb24Rest\Bitrix24\RestClient;
use Kb24Rest\Domain\Status;
use Kb24Rest\Portal\CurrentUser;
use Kb24Rest\Portal\Portal;
use Kb24Rest\Storage\EntityStorage;
use Kb24Rest\Structure\StructureService;
use Kb24Rest\Vacation\AbsenceCalendar;
use Kb24Rest\Vacation\ExportService;
use Kb24Rest\Vacation\LimitService;
use Kb24Rest\Vacation\Notifier;
use Kb24Rest\Vacation\RequestService;
use Kb24Rest\Vacation\RouteService;
use Kb24Rest\Vacation\RoleService;
use Kb24Rest\Vacation\Settings;
use Kb24Rest\Vacation\VacationError;
use Kb24Rest\Vacation\WorkCalendar;
use PHPUnit\Framework\TestCase;

/**
 * Структура как в RequestServiceTest: компания 1, продажи 5, бухгалтерия 14 с руководителем 30.
 * ОК: 40 и 41. Выгрузка отдаёт только ID: ФИО браузер берёт из портала сам.
 */
final class ExportServiceTest extends TestCase
{
    private const HEAD = 30;
    private const ACCOUNTANT = 31;
    private const HR = 40;
    private const SALES_MANAGER = 100;

    private FakePortal $http;
    private RoleService $roles;
    private RequestService $requests;
    private ExportService $export;
    private Portal $portal;

    protected function setUp(): void
    {
        $this->http = new FakePortal(
            [
                ['ID' => '1', 'NAME' => 'Компания', 'UF_HEAD' => '1'],
                ['ID' => '5', 'NAME' => 'Продажи', 'PARENT' => '1'],
                ['ID' => '14', 'NAME' => 'Бухгалтерия', 'PARENT' => '1', 'UF_HEAD' => '30'],
            ],
            [1 => [1], 30 => [14], 31 => [14], 40 => [14], 100 => [5]],
        );
        $this->portal = new Portal(1, 'm1', 'test.bitrix24.ru', 'https://test.bitrix24.ru/rest/', 'a', 'r', time() + 3600, 1);
        $this->rebuild();
        $this->roles->set($this->portal, RoleService::DIRECTOR, [1]);
        $this->roles->set($this->portal, RoleService::HR, [self::HR]);
        $this->rebuild();
    }

    private function rebuild(): void
    {
        $rest = new RestClient($this->http, new OAuthClient($this->http, 'id', 'secret'), new MemoryPortalStore());
        $storage = new EntityStorage($rest);
        $structure = new StructureService($rest);
        $settings = new Settings($rest);
        $this->roles = new RoleService($storage, $structure, $settings);
        $routes = new RouteService($storage, $this->roles, $structure, $settings);
        $this->requests = new RequestService(
            $storage,
            $structure,
            $this->roles,
            new WorkCalendar($rest),
            new \DateTimeImmutable('2026-10-27'),
            false,
            new Notifier($rest, 'local.app.code'),
            new AbsenceCalendar($rest, $storage),
            $settings,
            new LimitService($storage, $structure),
            null,
            $routes,
        );
        $this->export = new ExportService($storage, $this->roles);
    }

    private function user(int $id, bool $isAdmin = false): CurrentUser
    {
        return new CurrentUser($this->portal, $id, $isAdmin);
    }

    private function approve(int $approverId, int $requestId): array
    {
        $this->rebuild();

        return $this->requests->approve($this->user($approverId), $requestId);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function export(CurrentUser $user, array $overrides = []): array
    {
        return $this->export->list(
            $user,
            (string)($overrides['scope'] ?? 'calendar'),
            (string)($overrides['from'] ?? '2026-11-01'),
            (string)($overrides['to'] ?? '2026-11-30'),
            (array)($overrides['departments'] ?? []),
            (bool)($overrides['pending'] ?? false),
            (array)($overrides['kinds'] ?? []),
            (int)($overrides['employee'] ?? 0),
        );
    }

    public function testExportsOnlyApprovedWithApproversAndDate(): void
    {
        $vacation = $this->requests->create($this->user(self::ACCOUNTANT), 'vacation', '2026-11-09', '2026-11-22');
        $this->approve(self::HEAD, $vacation['id']);
        $this->approve(self::HR, $vacation['id']);
        $this->approve(1, $vacation['id']);
        self::assertSame(Status::APPROVED, $this->requests->card($this->user(self::ACCOUNTANT), $vacation['id'])['request']['status']);
        $sick = $this->requests->create($this->user(self::SALES_MANAGER), 'sick', '2026-11-02', '2026-11-05');
        self::assertSame(Status::APPROVED, $sick['status']);

        $data = $this->export($this->user(self::HR), ['scope' => 'dashboard', 'from' => '2026-01-01', 'to' => '2026-12-31']);
        self::assertSame([$vacation['id'], $sick['id']], array_column($data['items'], 'id'));
        $byId = array_column($data['items'], null, 'id');

        // Заявку согласовали по шагам: руководитель, ОК и директор, дата — переход в «Утверждена»
        self::assertSame([self::HEAD, self::HR, 1], $byId[$vacation['id']]['approvers']);
        self::assertSame('2026-10-27', $byId[$vacation['id']]['approvedAt']);
        self::assertSame(14, $byId[$vacation['id']]['days']);
        // Больничный утверждён сразу: согласующих нет, дата утверждения есть
        self::assertSame([], $byId[$sick['id']]['approvers']);
        self::assertSame('2026-10-27', $byId[$sick['id']]['approvedAt']);
    }

    public function testPendingRequestsExportedOnlyWhenAsked(): void
    {
        $this->requests->create($this->user(self::ACCOUNTANT), 'vacation', '2026-11-09', '2026-11-22');

        self::assertSame([], $this->export($this->user(self::HEAD))['items']);

        $data = $this->export($this->user(self::HEAD), ['pending' => true]);
        self::assertSame([Status::ROUTE_REVIEW], array_column($data['items'], 'status'));
        self::assertSame([], $data['items'][0]['approvers']);
        self::assertSame('', $data['items'][0]['approvedAt']);
    }

    public function testCancelledAndRejectedNeverExported(): void
    {
        $request = $this->requests->create($this->user(self::ACCOUNTANT), 'vacation', '2026-11-09', '2026-11-22');
        $this->requests->withdraw($this->user(self::ACCOUNTANT), $request['id']);

        $data = $this->export($this->user(self::HEAD), ['pending' => true]);
        self::assertSame([], $data['items']);
    }

    public function testFiltersByDepartmentEmployeeKindAndPeriod(): void
    {
        $this->requests->create($this->user(self::ACCOUNTANT), 'vacation', '2026-11-09', '2026-11-22');
        $this->requests->create($this->user(self::SALES_MANAGER), 'sick', '2026-11-02', '2026-11-05');
        $this->requests->create($this->user(self::ACCOUNTANT), 'dayoff', '2026-12-01', '2026-12-01');

        $data = $this->export($this->user(self::HR), ['pending' => true]);
        self::assertSame(2, count($data['items']), 'ОК видит всю компанию: заявки за ноябрь');

        $data = $this->export($this->user(self::HEAD), ['pending' => true]);
        self::assertSame(['14'], array_map(static fn(array $r) => (string)$r['deptId'], $data['items']), 'руководитель бухгалтерии видит только свой отдел');

        $data = $this->export($this->user(self::HR), ['employee' => self::SALES_MANAGER]);
        self::assertSame(['sick'], array_column($data['items'], 'kind'));

        $data = $this->export($this->user(self::HR), ['from' => '2026-12-01', 'to' => '2026-12-31', 'pending' => true]);
        self::assertSame(['dayoff'], array_column($data['items'], 'kind'));

        $data = $this->export($this->user(self::HR), ['kinds' => ['vacation'], 'pending' => true]);
        self::assertSame(['vacation'], array_column($data['items'], 'kind'));
        self::assertSame(14, $data['items'][0]['days']);
    }

    public function testForeignDepartmentIsRejected(): void
    {
        $this->requests->create($this->user(self::SALES_MANAGER), 'sick', '2026-11-02', '2026-11-05');

        $this->expectException(VacationError::class);
        $this->expectExceptionMessage('Нет доступа к выгрузке');
        $this->export($this->user(self::HEAD), ['departments' => [5]]);
    }

    public function testDashboardExportNeedsDashboardAccess(): void
    {
        $this->expectException(VacationError::class);
        $this->expectExceptionMessage('Нет доступа к выгрузке');
        $this->export($this->user(self::ACCOUNTANT), ['scope' => 'dashboard']);
    }

    public function testInvalidPeriodIsRejected(): void
    {
        $this->expectException(VacationError::class);
        $this->expectExceptionMessage('Неверный период');
        $this->export($this->user(self::HEAD), ['from' => '27.10.2026', 'to' => '27.11.2026']);
    }
}
