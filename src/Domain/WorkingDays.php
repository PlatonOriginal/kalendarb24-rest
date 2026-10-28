<?php
declare(strict_types=1);

namespace Kb24Rest\Domain;

/**
 * Рабочие дни по производственному календарю портала. Перенесено из коробочного модуля kalendarb24.vacation без изменений логики.
 */
final class WorkingDays
{
    private const WEEKDAY_CODES = ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'];

    /**
     * @param string[] $weekHolidays коды выходных дней недели: SA, SU
     * @param string[] $yearHolidays праздники в формате d.mm без года: 1.01, 23.02
     * @param string[] $yearWorkdays рабочие дни-исключения в том же формате
     */
    public function __construct(
        private readonly array $weekHolidays,
        private readonly array $yearHolidays,
        private readonly array $yearWorkdays = [],
    )
    {
    }

    public function isWorkingDay(\DateTimeInterface $day): bool
    {
        $key = $day->format('j.m');
        if (in_array($key, $this->yearWorkdays, true))
        {
            return true;
        }
        if (in_array($key, $this->yearHolidays, true))
        {
            return false;
        }

        return !in_array(self::WEEKDAY_CODES[(int)$day->format('w')], $this->weekHolidays, true);
    }

    /**
     * Рабочие дни строго между датами (обе границы не считаются).
     */
    public function countBetween(\DateTimeInterface $from, \DateTimeInterface $to): int
    {
        $day = \DateTimeImmutable::createFromInterface($from)->setTime(0, 0)->modify('+1 day');
        $end = \DateTimeImmutable::createFromInterface($to)->setTime(0, 0);
        $count = 0;
        for (; $day < $end; $day = $day->modify('+1 day'))
        {
            if ($this->isWorkingDay($day))
            {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Праздник из производственного календаря (выходные дни недели праздником не считаются).
     */
    public function isHoliday(\DateTimeInterface $day): bool
    {
        $key = $day->format('j.m');

        return in_array($key, $this->yearHolidays, true) && !in_array($key, $this->yearWorkdays, true);
    }

    /**
     * Дни ежегодного отпуска: календарные дни периода без нерабочих праздничных (ст. 120 ТК РФ).
     */
    public function countVacationDays(DateRange $range): int
    {
        $count = 0;
        foreach ($range->eachDay() as $day)
        {
            if (!$this->isHoliday($day))
            {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Нормализует «01.01» и «1.1» к виду «1.01».
     *
     * @return string[]
     */
    public static function parseList(string $list): array
    {
        $result = [];
        foreach (explode(',', $list) as $item)
        {
            $parts = explode('.', trim($item));
            if (count($parts) === 2 && (int)$parts[0] > 0 && (int)$parts[1] > 0)
            {
                $result[] = (int)$parts[0] . '.' . str_pad((string)(int)$parts[1], 2, '0', STR_PAD_LEFT);
            }
        }

        return $result;
    }
}
