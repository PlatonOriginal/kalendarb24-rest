<?php
declare(strict_types=1);

namespace Kb24Rest\Tests;

use Kb24Rest\Domain\DateRange;
use Kb24Rest\Domain\WorkingDays;
use PHPUnit\Framework\TestCase;

final class WorkingDaysTest extends TestCase
{
    private function calendar(): WorkingDays
    {
        return new WorkingDays(['SA', 'SU'], WorkingDays::parseList('1.01,2.01,7.01,23.02,8.03,1.05,9.05,12.06,4.11'), ['31.12']);
    }

    public function testWeekendsAndHolidays(): void
    {
        $calendar = $this->calendar();

        self::assertFalse($calendar->isWorkingDay(new \DateTimeImmutable('2026-10-31'))); // суббота
        self::assertFalse($calendar->isWorkingDay(new \DateTimeImmutable('2026-11-04'))); // праздник
        self::assertTrue($calendar->isWorkingDay(new \DateTimeImmutable('2026-11-05')));
        self::assertTrue($calendar->isWorkingDay(new \DateTimeImmutable('2028-12-31'))); // воскресенье, но рабочее по исключению
    }

    public function testCountExcludesBothEnds(): void
    {
        $calendar = $this->calendar();

        // Вт 27.10 → Пн 02.11: между ними Ср, Чт, Пт
        self::assertSame(3, $calendar->countBetween(new \DateTimeImmutable('2026-10-27'), new \DateTimeImmutable('2026-11-02')));
        // Пн 02.11 → Пт 06.11: Вт, Чт (среда 04.11 праздник)
        self::assertSame(2, $calendar->countBetween(new \DateTimeImmutable('2026-11-02'), new \DateTimeImmutable('2026-11-06')));
        self::assertSame(0, $calendar->countBetween(new \DateTimeImmutable('2026-11-06'), new \DateTimeImmutable('2026-11-02')));
    }

    public function testVacationDaysSkipHolidays(): void
    {
        $calendar = $this->calendar();

        // 02.11–15.11: 14 календарных дней, 04.11 праздник, выходные считаются
        self::assertSame(13, $calendar->countVacationDays(DateRange::fromStrings('2026-11-02', '2026-11-15')));
        // Новогодние праздники не съедают отпуск, а 31.12 рабочий по исключению и считается
        self::assertSame(1, $calendar->countVacationDays(DateRange::fromStrings('2026-12-31', '2027-01-02')));
        self::assertSame(7, $calendar->countVacationDays(DateRange::fromStrings('2026-10-05', '2026-10-11')));
    }

    public function testParseList(): void
    {
        self::assertSame(['1.01', '23.02', '4.11'], WorkingDays::parseList('01.01, 23.2,4.11,,bad'));
    }
}
