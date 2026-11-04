<?php
declare(strict_types=1);

namespace Kb24Rest\Structure;

use Kb24Rest\Bitrix24\RestClient;
use Kb24Rest\Portal\Portal;

/**
 * Структура компании через REST: department.get и user.get.
 * Из user.get берём только ID и UF_DEPARTMENT, ФИО на сервер не запрашиваем.
 */
final class StructureService
{
    /** @var array<int, list<int>> */
    private array $userDepartments = [];

    /** @var array<string, array<int, list<int>>> сотрудники отдела по member_id */
    private array $departmentUsers = [];

    /** @var array<string, OrgChart> структура за время одного запроса, по member_id */
    private array $charts = [];

    public function __construct(private readonly RestClient $rest)
    {
    }

    public function orgChart(Portal $portal): OrgChart
    {
        if (!isset($this->charts[$portal->memberId]))
        {
            $rows = $this->rest->listAll($portal, 'department.get');
            $this->charts[$portal->memberId] = new OrgChart(array_map(static fn(array $row) => Department::fromRest($row), $rows));
        }

        return $this->charts[$portal->memberId];
    }

    /**
     * @return list<int> отделы сотрудника, первый считаем основным
     */
    public function departmentsOf(Portal $portal, int $userId): array
    {
        if (!isset($this->userDepartments[$userId]))
        {
            $users = (array)$this->rest->call($portal, 'user.get', [
                'filter' => ['ID' => $userId],
                'select' => ['ID', 'UF_DEPARTMENT'],
            ]);
            $this->userDepartments[$userId] = array_values(array_map('intval', (array)($users[0]['UF_DEPARTMENT'] ?? [])));
        }

        return $this->userDepartments[$userId];
    }

    /**
     * Сотрудники отделов (только сами отделы, подотделы передаёт вызывающий). Только ID, без ФИО.
     * https://apidocs.bitrix24.com/api-reference/user/user-get.html
     *
     * @param list<int> $departmentIds
     * @return list<int>
     */
    public function usersIn(Portal $portal, array $departmentIds): array
    {
        $ids = [];
        foreach ($departmentIds as $departmentId)
        {
            if (!isset($this->departmentUsers[$portal->memberId][$departmentId]))
            {
                $rows = $this->rest->listAll($portal, 'user.get', [
                    'filter' => ['UF_DEPARTMENT' => $departmentId, 'ACTIVE' => true],
                    'select' => ['ID'],
                ]);
                $this->departmentUsers[$portal->memberId][$departmentId] = array_map(static fn(array $row) => (int)$row['ID'], $rows);
            }
            $ids = array_merge($ids, $this->departmentUsers[$portal->memberId][$departmentId]);
        }

        return array_values(array_unique($ids));
    }

    /**
     * Все активные сотрудники и их основной отдел (первый в UF_DEPARTMENT). Только ID, без ФИО.
     *
     * @return array<int, int> ID сотрудника => отдел
     */
    public function activeUsers(Portal $portal): array
    {
        $users = [];
        foreach ($this->rest->listAll($portal, 'user.get', ['filter' => ['ACTIVE' => true], 'select' => ['ID', 'UF_DEPARTMENT']]) as $row)
        {
            $departments = array_map('intval', (array)($row['UF_DEPARTMENT'] ?? []));
            if ($departments !== [])
            {
                $users[(int)$row['ID']] = $departments[0];
                $this->userDepartments[(int)$row['ID']] = $departments;
            }
        }

        return $users;
    }

    /**
     * @return list<int>
     */
    public function headChain(Portal $portal, OrgChart $chart, int $userId): array
    {
        return $chart->headChain($userId, fn(int $id): ?int => $this->departmentsOf($portal, $id)[0] ?? null);
    }
}
