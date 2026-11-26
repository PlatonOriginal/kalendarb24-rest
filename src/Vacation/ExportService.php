<?php
declare(strict_types=1);

namespace Kb24Rest\Vacation;

use Kb24Rest\Domain\DateRange;
use Kb24Rest\Domain\Status;
use Kb24Rest\Portal\CurrentUser;
use Kb24Rest\Portal\Portal;
use Kb24Rest\Storage\EntityStorage;
use Kb24Rest\Storage\Schema;

/**
 * Выгрузка графика отпусков для кадровой подшивки: заявки видимых отделов, пересекающиеся с периодом.
 * Файл собирает браузер, поэтому сервер отдаёт только ID сотрудников, отделов и согласующих —
 * ФИО и должности в приложении не хранятся и на сервер не попадают.
 * Кто согласовал и когда заявка утверждена — из истории kbhist.
 */
final class ExportService
{
    public function __construct(
        private readonly EntityStorage $storage,
        private readonly RoleService $roles,
    ) {
    }

    /**
     * Заявки для выгрузки. По умолчанию только утверждённые (с отменяющимися: до отмены они действуют),
     * с флагом $pending — и ещё не согласованные.
     *
     * @param list<int> $departmentIds отделы из фильтра вкладки; пусто — все видимые
     * @param list<string> $kinds виды отсутствия; пусто — все
     * @return array{items: list<array{id: int, userId: int, deptId: int, kind: string, status: string, dateFrom: string, dateTo: string, days: int, approvers: list<int>, approvedAt: string}>}
     */
    public function list(CurrentUser $user, string $scope, string $from, string $to, array $departmentIds, bool $pending, array $kinds, int $employeeId = 0): array
    {
        if (!in_array($scope, ['calendar', 'dashboard'], true) || !$this->roles->can($user, $scope))
        {
            throw new VacationError('Нет доступа к выгрузке: откройте вкладку, из которой она сделана');
        }
        $pattern = '/^\d{4}-\d{2}-\d{2}$/';
        try
        {
            $period = preg_match($pattern, $from) && preg_match($pattern, $to) ? DateRange::fromStrings($from, $to) : null;
        }
        catch (\Exception)
        {
            $period = null;
        }
        if ($period === null)
        {
            throw new VacationError('Неверный период');
        }
        if ($period->getDays() > 400)
        {
            throw new VacationError('Слишком длинный период');
        }

        $visible = $this->roles->visibleDepartments($user);
        $departments = $departmentIds !== []
            ? array_values(array_intersect($departmentIds, $visible ?? $departmentIds))
            : ($visible ?? []);
        if ($departmentIds !== [] && $departments === [])
        {
            throw new VacationError('Нет доступа к выгрузке по этим отделам');
        }

        $statuses = [Status::APPROVED, Status::CANCELLING];
        if ($pending)
        {
            $statuses = [...$statuses, ...Status::REVIEW];
        }
        $kinds = array_values(array_filter($kinds, Kinds::exists(...)));

        $items = [];
        foreach ($this->storage->find($user->portal, Schema::REQUESTS) as $item)
        {
            $request = RequestService::toRequest($item);
            if (($departments !== [] && !in_array($request['deptId'], $departments, true))
                || !in_array($request['status'], $statuses, true)
                || ($kinds !== [] && !in_array($request['kind'], $kinds, true))
                || ($employeeId !== 0 && $request['userId'] !== $employeeId)
                || $request['dateFrom'] === ''
                || $request['dateFrom'] > $period->to->format('Y-m-d')
                || ($request['dateTo'] ?: $request['dateFrom']) < $period->from->format('Y-m-d'))
            {
                continue;
            }
            $items[] = $request;
        }

        $history = $this->approvals($user->portal, array_column($items, 'id'));

        return ['items' => array_map(static fn(array $r) => [
            'id' => $r['id'],
            'userId' => $r['userId'],
            'deptId' => $r['deptId'],
            'kind' => $r['kind'],
            'status' => $r['status'],
            'dateFrom' => $r['dateFrom'],
            'dateTo' => $r['dateTo'],
            'days' => $r['days'],
            'approvers' => $history[$r['id']]['approvers'] ?? [],
            'approvedAt' => $history[$r['id']]['approvedAt'] ?? '',
        ], $items)];
    }

    /**
     * Согласующие и дата утверждения по каждой заявке: из истории kbhist.
     * Согласовали — те, кто согласовал шаг (approve, auto_approve), как в уведомлениях об отказе.
     * Утверждена — последний переход в статус «approved» (в том числе сразу при подаче).
     *
     * @param list<int> $requestIds
     * @return array<int, array{approvers: list<int>, approvedAt: string}> по ID заявки
     */
    private function approvals(Portal $portal, array $requestIds): array
    {
        $result = [];
        foreach ($this->storage->find($portal, Schema::HISTORY) as $item)
        {
            $values = $item['PROPERTY_VALUES'] ?? [];
            $requestId = (int)($values['REQ_ID'] ?? 0);
            if (!in_array($requestId, $requestIds, true))
            {
                continue;
            }
            $action = (string)($values['ACTION'] ?? '');
            if (in_array($action, ['approve', 'auto_approve'], true))
            {
                $approver = (int)($values['ACTOR_ID'] ?? 0);
                if ($approver && !in_array($approver, $result[$requestId]['approvers'] ?? [], true))
                {
                    $result[$requestId]['approvers'][] = $approver;
                }
            }
            if (($values['STATUS_TO'] ?? '') === Status::APPROVED && ($item['DATE_CREATE'] ?? '') !== '')
            {
                $result[$requestId]['approvedAt'] = substr((string)$item['DATE_CREATE'], 0, 10);
            }
        }

        return $result;
    }
}
