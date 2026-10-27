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
}
