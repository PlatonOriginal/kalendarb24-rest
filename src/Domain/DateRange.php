<?php
declare(strict_types=1);

namespace Kb24Rest\Domain;

/**
 * Период отсутствия в календарных днях, обе границы включительно.
 */
final class DateRange
{
    public readonly \DateTimeImmutable $from;
    public readonly \DateTimeImmutable $to;

    public function __construct(\DateTimeInterface $from, \DateTimeInterface $to)
    {
        $from = \DateTimeImmutable::createFromInterface($from)->setTime(0, 0);
        $to = \DateTimeImmutable::createFromInterface($to)->setTime(0, 0);
        if ($to < $from)
        {
            throw new \InvalidArgumentException('Дата окончания раньше даты начала');
        }

        $this->from = $from;
        $this->to = $to;
    }

    public static function fromStrings(string $from, string $to): self
    {
        return new self(new \DateTimeImmutable($from), new \DateTimeImmutable($to));
    }

    public function getDays(): int
    {
        return $this->from->diff($this->to)->days + 1;
    }

    public function contains(\DateTimeInterface $day): bool
    {
        $day = \DateTimeImmutable::createFromInterface($day)->setTime(0, 0);

        return $day >= $this->from && $day <= $this->to;
    }

    /**
     * Сколько дней два периода перекрываются (0, если не пересекаются).
     */
    public function overlapDays(self $other): int
    {
        $start = max($this->from, $other->from);
        $end = min($this->to, $other->to);

        return $end < $start ? 0 : $start->diff($end)->days + 1;
    }

    /**
     * @return \Generator<\DateTimeImmutable>
     */
    public function eachDay(): \Generator
    {
        for ($day = $this->from; $day <= $this->to; $day = $day->modify('+1 day'))
        {
            yield $day;
        }
    }
}
