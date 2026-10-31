<?php
declare(strict_types=1);

namespace Kb24Rest\Domain;

/**
 * Выбор маршрута для заявки и переход между его шагами. Перенесено из коробки, не зависит от REST.
 *
 * Маршрут: ['id', 'active' => bool, 'exclusive' => bool, 'kinds' => string[], 'departmentIds' => int[], 'sort'].
 * Пустой список видов или отделов значит «все».
 * Шаг: ['active' => bool, 'submitDays', 'rejectDays', 'name', ...].
 */
final class RoutePlan
{
    public const MAX_STEPS = 10;

    /**
     * Самый точный из подходящих маршрутов:
     * сначала закреплённый за видом как единственный, потом маршрут отдела (ближайшего к сотруднику) с видом,
     * отдела без вида, вида без отдела и, наконец, общий.
     *
     * @param list<array<string, mixed>> $routes
     * @param list<int> $departmentChain отдел сотрудника и его родители, от ближайшего к корню
     */
    public static function pick(array $routes, string $kind, array $departmentChain): ?array
    {
        $best = null;
        $bestKey = null;
        foreach ($routes as $route)
        {
            if (!$route['active'])
            {
                continue;
            }
            $kinds = $route['kinds'];
            if ($kinds && !in_array($kind, $kinds, true))
            {
                continue;
            }
            $distance = 0;
            if ($route['departmentIds'])
            {
                $distance = self::nearest($route['departmentIds'], $departmentChain);
                if ($distance === null)
                {
                    continue;
                }
            }

            $key = [
                $route['exclusive'] && $kinds ? 0 : 1,
                $route['departmentIds'] ? 0 : 1,
                $distance,
                $kinds ? 0 : 1,
                (int)($route['sort'] ?? 100),
                (int)$route['id'],
            ];
            if ($bestKey === null || $key < $bestKey)
            {
                $best = $route;
                $bestKey = $key;
            }
        }

        return $best;
    }

    /**
     * Следующий шаг, начиная с $from: выключенные шаги пропускаются, как и шаги, где согласующий — сам автор заявки
     * или тот, кто уже согласовал эту заявку раньше (в коробке — только на предыдущем шаге).
     *
     * @param list<array<string, mixed>> $steps
     * @param list<int> $skipApprovers автор и те, кто уже согласовал
     * @param callable(array): ?int $resolve согласующий шага или null, если его нет
     * @return array{index: int, approverId: ?int}|null null, если шагов больше нет
     */
    public static function next(array $steps, int $from, array $skipApprovers, callable $resolve): ?array
    {
        $count = count($steps);
        for ($index = max(0, $from); $index < $count; $index++)
        {
            if (!$steps[$index]['active'])
            {
                continue;
            }
            $approverId = $resolve($steps[$index]);
            if ($approverId !== null && in_array($approverId, $skipApprovers, true))
            {
                continue;
            }

            return ['index' => $index, 'approverId' => $approverId];
        }

        return null;
    }

    /**
     * За сколько дней до начала нужно подать заявку по маршруту: наибольший срок среди включённых шагов.
     *
     * @param list<array<string, mixed>> $steps
     */
    public static function minSubmitDays(array $steps): int
    {
        $days = 0;
        foreach ($steps as $step)
        {
            if ($step['active'])
            {
                $days = max($days, (int)($step['submitDays'] ?? 0));
            }
        }

        return $days;
    }

    /**
     * Может ли шаг отклонить заявку сегодня: до начала не меньше rejectDays дней.
     * Шагов «только согласует» нет: согласующий без права отказа ничего не решает.
     *
     * @return string|null причина, почему нельзя
     */
    public static function rejectBlocked(array $step, \DateTimeImmutable $start, \DateTimeImmutable $today): ?string
    {
        $days = (int)($step['rejectDays'] ?? 0);
        if ($days > 0 && $today > $start->modify("-{$days} days"))
        {
            return "Отклонить на шаге «{$step['name']}» можно не позже чем за {$days} дн. до начала";
        }

        return null;
    }

    /**
     * @param list<int> $departmentIds
     * @param list<int> $chain
     */
    private static function nearest(array $departmentIds, array $chain): ?int
    {
        foreach (array_values($chain) as $distance => $departmentId)
        {
            if (in_array($departmentId, $departmentIds, true))
            {
                return $distance;
            }
        }

        return null;
    }
}
