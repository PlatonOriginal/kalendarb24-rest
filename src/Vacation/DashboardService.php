<?php
declare(strict_types=1);

namespace Kb24Rest\Vacation;

use Kb24Rest\Domain\Analytics;
use Kb24Rest\Domain\DateRange;
use Kb24Rest\Domain\Status;
use Kb24Rest\Portal\CurrentUser;
use Kb24Rest\Storage\EntityStorage;
use Kb24Rest\Storage\Schema;
use Kb24Rest\Structure\StructureService;

/**
 * Дашборд за год, как в коробке: кто отдыхал, нагрузка по месяцам, скорость согласования.
 * Доступ — вкладки «Дашборд» и «Дашборд: скорость согласования» в ролях; отделы — по видимости ролей
 * (свой отдел, с подотделами, вся компания; руководитель — свои отделы с подотделами).
 * Больничных и прогулов в REST-приложении пока нет, поэтому этих разделов нет.
 * Сервер отдаёт только ID сотрудников: ФИО браузер берёт из портала сам.
 */
final class DashboardService
{
    public function __construct(
        private readonly EntityStorage $storage,
        private readonly StructureService $structure,
        private readonly RoleService $roles,
        private readonly ?BalanceService $balances = null,
        private readonly ?\DateTimeImmutable $now = null,
    ) {
    }

    public function build(CurrentUser $user, int $year): array
    {
        if (!$this->roles->can($user, 'dashboard'))
        {
            throw new VacationError('Нет доступа к вкладке «Дашборд»');
        }
        $now = $this->now ?? new \DateTimeImmutable();
        $today = $now->setTime(0, 0);
        $year = $year ?: (int)$today->format('Y');
        if ($year < 2000 || $year > 2100)
        {
            throw new VacationError('Неверный год');
        }
        $portal = $user->portal;
        $yearRange = new DateRange(new \DateTimeImmutable("$year-01-01"), new \DateTimeImmutable("$year-12-31"));

        $visible = $this->roles->visibleDepartments($user);
        $departments = [];
        foreach ($this->structure->orgChart($portal)->tree() as ['department' => $department, 'depth' => $depth])
        {
            if ($visible === null || in_array($department->id, $visible, true))
            {
                $departments[$department->id] = ['id' => $department->id, 'name' => $department->name, 'parentId' => $department->parentId, 'depth' => $depth, 'headcount' => 0];
            }
        }
        $users = array_filter($this->structure->activeUsers($portal), static fn(int $departmentId) => isset($departments[$departmentId]));
        foreach ($users as $departmentId)
        {
            $departments[$departmentId]['headcount']++;
        }

        $items = $this->storage->find($portal, Schema::REQUESTS);
        $all = array_map(RequestService::toRequest(...), $items);
        $vacations = [];
        $vacationDays = [];
        $absences = [];
        foreach ($all as $request)
        {
            if (!isset($users[$request['userId']]) || !Status::isActive($request['status']) || $request['dateFrom'] === '')
            {
                continue;
            }
            $range = DateRange::fromStrings($request['dateFrom'], $request['dateTo'] ?: $request['dateFrom']);
            if ($range->overlapDays($yearRange) === 0)
            {
                continue;
            }
            $absences[] = ['department' => $users[$request['userId']], 'user' => $request['userId'], 'range' => $range];
            if (Kinds::all()[$request['kind']]['deductsBalance'] ?? false)
            {
                $approved = in_array($request['status'], [Status::APPROVED, Status::CANCELLING], true);
                $vacations[$request['userId']][] = ['range' => $range, 'approved' => $approved];
                $vacationDays[$request['userId']] = ($vacationDays[$request['userId']] ?? 0) + $request['days'];
            }
        }

        $available = $this->balances?->availableFor($portal, $all) ?? [];
        $employees = [];
        foreach ($users as $userId => $departmentId)
        {
            $own = $vacations[$userId] ?? [];
            $employees[] = [
                'id' => $userId,
                'departmentId' => $departmentId,
                'rest' => Analytics::classifyRest($own, $today),
                'longPart' => Analytics::hasLongPart($own),
                'vacationDays' => $vacationDays[$userId] ?? 0,
                'available' => $available[$userId] ?? null,
            ];
        }
        $headcount = array_filter(array_column($departments, 'headcount', 'id'));
        $load = Analytics::monthlyLoad($headcount, $absences, $year);

        return [
            'year' => $year,
            'today' => $today->format('Y-m-d'),
            'departments' => array_values($departments),
            'employees' => $employees,
            'load' => [
                'departments' => array_map(static fn(array $months) => array_values($months), $load),
                'company' => array_values(Analytics::companyPeaks($absences, $year)),
                'headcount' => count($users),
            ],
            'speed' => $this->roles->can($user, 'dashboardSpeed') ? $this->speed($user, $items, $yearRange, $visible, $now) : null,
        ];
    }

    /**
     * Шаги согласования заявок, поданных в этом году: сколько заявка ждала решения на каждом шаге.
     *
     * @param list<array<string, mixed>> $items записи kbreq
     * @param list<int>|null $visible
     */
    private function speed(CurrentUser $user, array $items, DateRange $yearRange, ?array $visible, \DateTimeImmutable $now): array
    {
        $requests = [];
        $plans = [];
        foreach ($items as $item)
        {
            $request = RequestService::toRequest($item);
            $created = $request['createdAt'] !== '' ? new \DateTimeImmutable($request['createdAt']) : null;
            if ($created !== null && $created >= $yearRange->from && $created < $yearRange->to->modify('+1 day')
                && ($visible === null || in_array($request['deptId'], $visible, true)))
            {
                $requests[$request['id']] = $request;
                $plans[$request['id']] = RequestService::routeData($item);
            }
        }
        if (!$requests)
        {
            return ['steps' => []];
        }

        $history = [];
        foreach ($this->storage->find($user->portal, Schema::HISTORY) as $item)
        {
            $values = $item['PROPERTY_VALUES'] ?? [];
            $requestId = (int)($values['REQ_ID'] ?? 0);
            if (!isset($requests[$requestId]) || ($item['DATE_CREATE'] ?? '') === '')
            {
                continue;
            }
            $history[] = [
                'REQUEST_ID' => $requestId,
                'USER_ID' => (int)($values['ACTOR_ID'] ?? 0),
                'ACTION' => (string)($values['ACTION'] ?? ''),
                'STATUS_FROM' => (string)($values['STATUS_FROM'] ?? ''),
                'STATUS_TO' => (string)($values['STATUS_TO'] ?? ''),
                'STEP' => (int)($values['STEP'] ?? 0),
                'CREATED_AT' => new \DateTimeImmutable((string)$item['DATE_CREATE']),
                'ID' => (int)$item['ID'],
            ];
        }
        usort($history, static fn(array $a, array $b) => [$a['REQUEST_ID'], $a['ID']] <=> [$b['REQUEST_ID'], $b['ID']]);

        $steps = [];
        foreach (Analytics::approvalSteps($history) as $step)
        {
            $request = $requests[$step['request']];
            $open = $step['end'] === null;
            $approverId = $open ? $request['approverId'] : (int)$step['approver'];
            if (!$approverId || ($open && !in_array($request['status'], Status::REVIEW, true)))
            {
                continue;
            }
            $number = $open ? $request['step'] + 1 : (int)$step['number'];
            $name = $step['status'] === Status::ROUTE_REVIEW
                ? (string)($plans[$step['request']]['steps'][$number - 1]['name'] ?? '')
                : '';
            $steps[] = [
                'requestId' => $step['request'],
                'userId' => $request['userId'],
                'step' => $name !== '' ? $name : self::statusName($step['status']),
                'approverId' => $approverId,
                'departmentId' => $request['deptId'],
                'hours' => $open ? null : round((float)$step['hours'], 2),
                'waitingHours' => $open ? round(Analytics::hoursBetween($step['start'], $now), 2) : null,
            ];
        }

        return ['steps' => $steps];
    }

    private static function statusName(string $status): string
    {
        return match ($status)
        {
            Status::HEAD_REVIEW => 'Руководитель',
            Status::HR_REVIEW => 'Кадровая проверка',
            Status::FINAL_APPROVAL => 'Утверждение',
            default => 'Шаг маршрута',
        };
    }
}
