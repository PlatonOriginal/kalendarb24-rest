<?php
declare(strict_types=1);

namespace Kb24Rest\Structure;

/**
 * Дерево отделов и поиск руководителя. Не зависит от REST, проверяется тестами.
 */
final class OrgChart
{
    /** @var array<int, Department> */
    private array $departments = [];

    /**
     * @param iterable<Department> $departments
     */
    public function __construct(iterable $departments)
    {
        foreach ($departments as $department)
        {
            $this->departments[$department->id] = $department;
        }
    }

    public function department(int $id): ?Department
    {
        return $this->departments[$id] ?? null;
    }

    /**
     * @return array<int, Department>
     */
    public function all(): array
    {
        return $this->departments;
    }

    /**
     * Ближайший руководитель сотрудника: руководитель его отдела, а если это он сам или руководителя нет,
     * то руководитель родительского отдела и так далее вверх. Для директора вернёт null.
     * Так же работал findNearestHeadByUserId в коробке.
     */
    public function nearestHead(int $userId, int $departmentId): ?int
    {
        $seen = [];
        $department = $this->department($departmentId);
        while ($department !== null && !isset($seen[$department->id]))
        {
            $seen[$department->id] = true;
            if ($department->headUserId !== null && $department->headUserId !== $userId)
            {
                return $department->headUserId;
            }
            $department = $department->parentId !== null ? $this->department($department->parentId) : null;
        }

        return null;
    }

    /**
     * Цепочка руководителей снизу вверх: руководитель, его руководитель и так далее.
     *
     * @param callable(int): ?int $primaryDepartmentOf основной отдел сотрудника
     * @return list<int>
     */
    public function headChain(int $userId, callable $primaryDepartmentOf, int $maxDepth = 10): array
    {
        $chain = [];
        $current = $userId;
        while (count($chain) < $maxDepth)
        {
            $departmentId = $primaryDepartmentOf($current);
            $head = $departmentId !== null ? $this->nearestHead($current, $departmentId) : null;
            if ($head === null || in_array($head, $chain, true) || $head === $userId)
            {
                break;
            }
            $chain[] = $head;
            $current = $head;
        }

        return $chain;
    }

    /**
     * Отдел и все его подотделы.
     *
     * @return list<int>
     */
    public function withChildren(int $departmentId): array
    {
        $ids = [$departmentId];
        for ($i = 0; $i < count($ids); $i++)
        {
            foreach ($this->departments as $department)
            {
                if ($department->parentId === $ids[$i] && !in_array($department->id, $ids, true))
                {
                    $ids[] = $department->id;
                }
            }
        }

        return $ids;
    }

    /**
     * Отделы, которыми руководит сотрудник.
     *
     * @return list<int>
     */
    public function headedBy(int $userId): array
    {
        $ids = [];
        foreach ($this->departments as $department)
        {
            if ($department->headUserId === $userId)
            {
                $ids[] = $department->id;
            }
        }

        return $ids;
    }

    /**
     * Отделы в порядке дерева с глубиной, для выпадающего списка: родитель, под ним его подотделы.
     *
     * @return list<array{department: Department, depth: int}>
     */
    public function tree(): array
    {
        $children = [];
        $roots = [];
        foreach ($this->departments as $department)
        {
            if ($department->parentId !== null && isset($this->departments[$department->parentId]))
            {
                $children[$department->parentId][] = $department;
            }
            else
            {
                $roots[] = $department;
            }
        }
        $result = [];
        $walk = static function (array $level, int $depth) use (&$walk, &$result, $children): void
        {
            foreach ($level as $department)
            {
                $result[] = ['department' => $department, 'depth' => $depth];
                $walk($children[$department->id] ?? [], $depth + 1);
            }
        };
        $walk($roots, 0);

        return $result;
    }
}
