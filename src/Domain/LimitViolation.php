<?php
declare(strict_types=1);

namespace Kb24Rest\Domain;

/**
 * Нарушение лимита отдела при подаче заявки (перенесено из коробки).
 */
final class LimitViolation
{
    public const CONCURRENT = 'concurrent';
    public const OVERLAP = 'overlap';

    /**
     * @param string $rule CONCURRENT или OVERLAP
     * @param list<\DateTimeImmutable> $days дни, в которые лимит превышен (для CONCURRENT)
     * @param int|null $requestId заявка коллеги, с которой перекрытие больше допустимого (для OVERLAP)
     */
    public function __construct(
        public readonly string $rule,
        public readonly array $days = [],
        public readonly ?int $requestId = null,
        public readonly int $overlapDays = 0,
    ) {
    }
}
