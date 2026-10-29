<?php
declare(strict_types=1);

namespace Kb24Rest\Vacation;

use Kb24Rest\Domain\DateRange;
use Kb24Rest\Portal\CurrentUser;
use Kb24Rest\Structure\StructureService;

/**
 * Календарь отдела, как в коробке: кто из отдела отсутствует и в какие дни лимит отдела исчерпан.
 * Видимость тоже как в коробке: директор, ОК и администратор видят все отделы, остальные свой отдел
 * и отделы, которыми руководят, если в настройках не разрешено видеть все.
 * Сотрудников отдела и их ФИО браузер получает сам через user.get, сервер отдаёт только ID.
 */
final class CalendarService
{
    public function __construct(
        private readonly StructureService $structure,
        private readonly RoleService $roles,
        private readonly LimitService $limits,
        private readonly ?Settings $settings = null,
    ) {
    }

    /**
     * Видимые отделы в порядке дерева и основной отдел сотрудника.
     *
     * @return array{myDepartment: ?int, departments: list<array{id: int, name: string, depth: int}>}
     */
    public function departments(CurrentUser $user): array
    {
        $visible = $this->visibleDepartmentIds($user);
        $departments = [];
        foreach ($this->structure->orgChart($user->portal)->tree() as ['department' => $department, 'depth' => $depth])
        {
            if (in_array($department->id, $visible, true))
            {
                $departments[] = ['id' => $department->id, 'name' => $department->name, 'depth' => $depth];
            }
        }

        return [
            'myDepartment' => $this->structure->departmentsOf($user->portal, $user->userId)[0] ?? null,
            'departments' => $departments,
        ];
    }

    public function get(CurrentUser $user, int $departmentId, string $from, string $to): array
    {
        if (!in_array($departmentId, $this->visibleDepartmentIds($user), true))
        {
            throw new VacationError('Нет доступа к календарю этого отдела');
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

        $portal = $user->portal;
        $requests = array_map(static fn(array $r) => [
            'id' => $r['id'],
            'userId' => $r['userId'],
            'kind' => $r['kind'],
            'status' => $r['status'],
            'dateFrom' => $r['dateFrom'],
            'dateTo' => $r['dateTo'],
        ], $this->limits->departmentRequests($portal, [$departmentId], $period));

        return [
            'departmentId' => $departmentId,
            'headId' => $this->limits->headOf($portal, $departmentId),
            'requests' => $requests,
            'fullDays' => $this->limits->fullDays($portal, $departmentId, $period),
            'limit' => $this->limits->limit($portal, $departmentId),
        ];
    }

    /**
     * @return list<int>
     */
    public function visibleDepartmentIds(CurrentUser $user): array
    {
        $portal = $user->portal;
        $chart = $this->structure->orgChart($portal);
        $roles = $this->roles->roles($portal);
        $wholeCompany = $user->isAdmin || $user->userId === $roles['director'] || in_array($user->userId, $roles['hr'], true)
            || ($this->settings?->employeesSeeOtherDepartments($portal) ?? false);
        if ($wholeCompany)
        {
            return array_keys($chart->all());
        }
        $own = $this->structure->departmentsOf($portal, $user->userId)[0] ?? null;

        return array_values(array_unique(array_merge($own !== null ? [$own] : [], $chart->headedBy($user->userId))));
    }
}
