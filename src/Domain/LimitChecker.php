<?php
declare(strict_types=1);

namespace Kb24Rest\Domain;

/**
 * Проверка двух лимитов отдела, как в коробке:
 * 1) в любой день отсутствуют не больше N сотрудников;
 * 2) два отсутствия перекрываются не больше чем на M дней.
 * Не зависит от REST, проверяется тестами.
 */
final class LimitChecker
{
    /**
     * @param int|null $maxConcurrent N, null без ограничения
     * @param int|null $maxOverlapDays M, null без ограничения
     */
    public function __construct(
        private readonly ?int $maxConcurrent,
        private readonly ?int $maxOverlapDays,
    ) {
    }

    /**
     * @param array<int, DateRange> $existing занятые слоты коллег: ID заявки => период
     * @return list<LimitViolation>
     */
    public function check(DateRange $requested, array $existing): array
    {
        $violations = [];

        if ($this->maxConcurrent !== null)
        {
            $days = [];
            foreach ($requested->eachDay() as $day)
            {
                if ($this->countOn($day, $existing) + 1 > $this->maxConcurrent)
                {
                    $days[] = $day;
                }
            }
            if ($days)
            {
                $violations[] = new LimitViolation(LimitViolation::CONCURRENT, $days);
            }
        }

        if ($this->maxOverlapDays !== null)
        {
            foreach ($existing as $requestId => $range)
            {
                $overlap = $requested->overlapDays($range);
                if ($overlap > $this->maxOverlapDays)
                {
                    $violations[] = new LimitViolation(LimitViolation::OVERLAP, [], $requestId, $overlap);
                }
            }
        }

        return $violations;
    }

    /**
     * Дни периода, на которые сотрудник уже не сможет подать заявку из-за лимита одновременных отсутствий.
     * Нужны календарю, чтобы закрасить «занято».
     *
     * @param array<int, DateRange> $existing
     * @return list<\DateTimeImmutable>
     */
    public function getFullDays(DateRange $period, array $existing): array
    {
        if ($this->maxConcurrent === null)
        {
            return [];
        }

        $full = [];
        foreach ($period->eachDay() as $day)
        {
            if ($this->countOn($day, $existing) >= $this->maxConcurrent)
            {
                $full[] = $day;
            }
        }

        return $full;
    }

    /**
     * @param array<int, DateRange> $existing
     */
    private function countOn(\DateTimeImmutable $day, array $existing): int
    {
        $count = 0;
        foreach ($existing as $range)
        {
            if ($range->contains($day))
            {
                $count++;
            }
        }

        return $count;
    }
}
