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
     * @return list<int>
     */
    public function headChain(Portal $portal, OrgChart $chart, int $userId): array
    {
        return $chart->headChain($userId, fn(int $id): ?int => $this->departmentsOf($portal, $id)[0] ?? null);
    }

    /**
     * Для сравнения: руководители по новой структуре (humanresources, REST 3.0).
     * Нужен scope humanresources. Возвращает узлы с кодом доступа и ID руководителей.
     *
     * @return list<array{id: int, name: string, accessCode: string, parentId: ?int, heads: list<int>}>
     */
    public function humanResourcesHeads(Portal $portal, int $limit = 20): array
    {
        $list = (array)$this->rest->callV3($portal, 'humanresources.node.list', [
            'type' => 'DEPARTMENT',
            'select' => ['id', 'name', 'parentId', 'accessCode'],
            'pagination' => ['page' => 1, 'limit' => $limit],
        ]);

        $nodes = [];
        foreach ((array)($list['items'] ?? []) as $item)
        {
            $node = (array)$this->rest->callV3($portal, 'humanresources.node.get', [
                'id' => (int)$item['id'],
                'select' => ['id', 'members'],
            ]);
            $heads = [];
            foreach ((array)($node['item']['members'] ?? $node['members'] ?? []) as $member)
            {
                if (($member['role'] ?? '') === 'MEMBER_HEAD')
                {
                    $heads[] = (int)$member['userId'];
                }
            }
            $nodes[] = [
                'id' => (int)$item['id'],
                'name' => (string)($item['name'] ?? ''),
                'accessCode' => (string)($item['accessCode'] ?? ''),
                'parentId' => isset($item['parentId']) ? (int)$item['parentId'] : null,
                'heads' => $heads,
            ];
        }

        return $nodes;
    }
}
