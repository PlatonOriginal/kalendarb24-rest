<?php
declare(strict_types=1);

namespace Kb24Rest\Domain;

/**
 * Расчёты для дашборда, как в коробке (lib/domain/analytics.php). Не зависит от Битрикс.
 */
final class Analytics
{
    public const REST_DONE = 'rested';
    public const REST_PLANNED = 'planned';
    public const REST_NONE = 'none';

    /** Минимальная часть ежегодного отпуска по ст. 125 ТК РФ. */
    public const LONG_PART_DAYS = 14;

    private const REVIEW_STATUSES = ['head_review', 'hr_review', 'final_approval', 'route_review'];
    private const DECISIONS = ['approve', 'reject'];

    /**
     * Отдыхал ли сотрудник в этом году.
     *
     * @param array<int, array{range:DateRange, approved:bool}> $vacations ежегодные отпуска сотрудника за год (активные заявки)
     */
    public static function classifyRest(array $vacations, \DateTimeImmutable $today): string
    {
        $planned = false;
        foreach ($vacations as $vacation)
        {
            if ($vacation['approved'] && $vacation['range']->from <= $today)
            {
                return self::REST_DONE;
            }
            $planned = true;
        }

        return $planned ? self::REST_PLANNED : self::REST_NONE;
    }

    /**
     * Есть ли среди отпусков года часть не меньше 14 календарных дней.
     *
     * @param array<int, array{range:DateRange, approved:bool}> $vacations
     */
    public static function hasLongPart(array $vacations): bool
    {
        foreach ($vacations as $vacation)
        {
            if ($vacation['range']->getDays() >= self::LONG_PART_DAYS)
            {
                return true;
            }
        }

        return false;
    }

    /**
     * Нагрузка по месяцам: сколько сотрудников отдела отсутствует одновременно в самый загруженный день месяца.
     *
     * @param array<int, int> $headcount отдел => число сотрудников
     * @param array<int, array{department:int, user:int, range:DateRange}> $absences
     * @return array<int, array<int, array{peak:int, present:int, date:?string}>> отдел => месяц (1–12) => пик
     */
    public static function monthlyLoad(array $headcount, array $absences, int $year): array
    {
        // день => отдел => сотрудники
        $byDay = [];
        $yearRange = new DateRange(new \DateTimeImmutable("$year-01-01"), new \DateTimeImmutable("$year-12-31"));
        foreach ($absences as $absence)
        {
            if (!isset($headcount[$absence['department']]) || $absence['range']->overlapDays($yearRange) === 0)
            {
                continue;
            }
            $from = max($absence['range']->from, $yearRange->from);
            $to = min($absence['range']->to, $yearRange->to);
            foreach ((new DateRange($from, $to))->eachDay() as $day)
            {
                $byDay[$day->format('Y-m-d')][$absence['department']][$absence['user']] = true;
            }
        }

        $result = [];
        foreach ($headcount as $department => $count)
        {
            for ($month = 1; $month <= 12; $month++)
            {
                $result[$department][$month] = ['peak' => 0, 'present' => $count, 'date' => null];
            }
        }
        ksort($byDay);
        foreach ($byDay as $date => $departments)
        {
            $month = (int)substr($date, 5, 2);
            foreach ($departments as $department => $users)
            {
                $absent = count($users);
                if ($absent > $result[$department][$month]['peak'])
                {
                    $result[$department][$month] = [
                        'peak' => $absent,
                        'present' => max(0, $headcount[$department] - $absent),
                        'date' => $date,
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * Пик одновременных отсутствий по компании в каждом месяце.
     *
     * @param array<int, array{department:int, user:int, range:DateRange}> $absences
     * @return array<int, array{peak:int, date:?string}> месяц (1–12) => пик
     */
    public static function companyPeaks(array $absences, int $year): array
    {
        $all = [];
        foreach ($absences as $absence)
        {
            $all[] = ['department' => 0, 'user' => $absence['user'], 'range' => $absence['range']];
        }
        $load = self::monthlyLoad([0 => PHP_INT_MAX], $all, $year)[0];

        return array_map(static fn(array $m) => ['peak' => $m['peak'], 'date' => $m['date']], $load);
    }

    /**
     * Шаги согласования из истории заявок: сколько заявка ждала решения на каждом шаге.
     *
     * @param array<int, array{REQUEST_ID:int, USER_ID:int, ACTION:string, STATUS_FROM:?string, STATUS_TO:?string, CREATED_AT:\DateTimeImmutable}> $history
     *     записи истории, отсортированные по заявке и времени
     * @return array<int, array{request:int, status:string, approver:?int, start:\DateTimeImmutable, end:?\DateTimeImmutable, hours:?float}>
     *     незавершённые шаги идут с end и approver = null
     */
    public static function approvalSteps(array $history): array
    {
        $steps = [];
        $open = [];
        foreach ($history as $row)
        {
            $requestId = (int)$row['REQUEST_ID'];
            $from = $row['STATUS_FROM'];
            $to = $row['STATUS_TO'];
            // Смена заместителя и т. п. не меняет шаг; на маршруте согласование ведёт из route_review в route_review
            if ($from === $to && !in_array($row['ACTION'], self::DECISIONS, true))
            {
                continue;
            }

            if (isset($open[$requestId]) && $open[$requestId]['status'] === $from)
            {
                $step = $open[$requestId];
                unset($open[$requestId]);
                if (in_array($row['ACTION'], self::DECISIONS, true))
                {
                    $step['approver'] = (int)$row['USER_ID'];
                    // Номер шага маршрута (с 1) из истории REST-приложения: по нему берётся название шага
                    $step['number'] = (int)($row['STEP'] ?? 0);
                    $step['end'] = $row['CREATED_AT'];
                    $step['hours'] = self::hoursBetween($step['start'], $step['end']);
                    $steps[] = $step;
                }
            }

            if (in_array($to, self::REVIEW_STATUSES, true))
            {
                $open[$requestId] = [
                    'request' => $requestId,
                    'status' => $to,
                    'approver' => null,
                    'start' => $row['CREATED_AT'],
                    'end' => null,
                    'hours' => null,
                    'number' => null,
                ];
            }
        }

        return array_merge($steps, array_values($open));
    }

    /**
     * @param float[] $values
     * @return array{count:int, avg:?float, median:?float, max:?float}
     */
    public static function summarize(array $values): array
    {
        $count = count($values);
        if ($count === 0)
        {
            return ['count' => 0, 'avg' => null, 'median' => null, 'max' => null];
        }

        sort($values);
        $middle = intdiv($count, 2);
        $median = $count % 2 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;

        return [
            'count' => $count,
            'avg' => array_sum($values) / $count,
            'median' => $median,
            'max' => end($values),
        ];
    }

    public static function hoursBetween(\DateTimeImmutable $start, \DateTimeImmutable $end): float
    {
        return max(0, $end->getTimestamp() - $start->getTimestamp()) / 3600;
    }
}
