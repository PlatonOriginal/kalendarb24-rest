<?php
declare(strict_types=1);

namespace Kb24Rest\Domain;

/**
 * Начисление ежегодного отпуска по рабочему году: норма целиком в каждую годовщину приёма. Не зависит от REST, перенесено из коробки.
 */
final class Accrual
{
    /**
     * Сколько годовщин приёма прошло после даты остатка и до даты включительно.
     */
    public static function countAnniversaries(\DateTimeInterface $hireDate, \DateTimeInterface $after, \DateTimeInterface $until): int
    {
        $hire = \DateTimeImmutable::createFromInterface($hireDate)->setTime(0, 0);
        $after = \DateTimeImmutable::createFromInterface($after)->setTime(0, 0);
        $until = \DateTimeImmutable::createFromInterface($until)->setTime(0, 0);

        $count = 0;
        for ($year = 1; ($anniversary = self::anniversary($hire, $year)) <= $until; $year++)
        {
            if ($anniversary > $after)
            {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Рабочий год, в который попадает дата: от последней годовщины приёма до дня перед следующей.
     *
     * @return array{0:\DateTimeImmutable, 1:\DateTimeImmutable}
     */
    public static function workingYear(\DateTimeInterface $hireDate, \DateTimeInterface $date): array
    {
        $hire = \DateTimeImmutable::createFromInterface($hireDate)->setTime(0, 0);
        $date = \DateTimeImmutable::createFromInterface($date)->setTime(0, 0);

        $year = 0;
        while (self::anniversary($hire, $year + 1) <= $date)
        {
            $year++;
        }

        return [self::anniversary($hire, $year), self::anniversary($hire, $year + 1)->modify('-1 day')];
    }

    /**
     * Годовщина; принятым 29 февраля в невисокосный год — 28 февраля.
     */
    private static function anniversary(\DateTimeImmutable $hire, int $years): \DateTimeImmutable
    {
        $year = (int)$hire->format('Y') + $years;
        $month = (int)$hire->format('n');
        $day = min((int)$hire->format('j'), (int)$hire->setDate($year, $month, 1)->format('t'));

        return $hire->setDate($year, $month, $day);
    }
}
