<?php
declare(strict_types=1);

namespace Kb24Rest\Domain;

/**
 * Последовательность шагов согласования заявки (прототип: одна попытка).
 *
 * Обычный маршрут: руководитель → ОК → директор.
 * Заявку директора проверяет только ОК.
 * Если ближайший руководитель инициатора и есть директор, второй раз директор не утверждает.
 */
final class ApprovalRoute
{
    public function __construct(
        private readonly bool $initiatorIsDirector,
        private readonly bool $headIsDirector,
    )
    {
    }

    public function getFirstStatus(): string
    {
        return $this->initiatorIsDirector ? Status::HR_REVIEW : Status::HEAD_REVIEW;
    }

    /**
     * Статус после согласования на текущем шаге.
     */
    public function getNextStatus(string $current): string
    {
        return match ($current)
        {
            Status::HEAD_REVIEW => Status::HR_REVIEW,
            Status::HR_REVIEW => ($this->initiatorIsDirector || $this->headIsDirector)
                ? Status::APPROVED
                : Status::FINAL_APPROVAL,
            Status::FINAL_APPROVAL => Status::APPROVED,
            default => throw new \LogicException("Заявку в статусе {$current} нельзя согласовать"),
        };
    }

    /**
     * Любой отказ закрывает заявку.
     */
    public static function canReject(string $current): bool
    {
        return in_array($current, Status::REVIEW, true);
    }

    /**
     * Отозвать можно до фиксации; после согласования руководителем участники получают уведомление.
     */
    public static function canWithdraw(string $current): bool
    {
        return self::canReject($current);
    }

    /**
     * Нужно ли уведомить пройденных участников при отзыве.
     */
    public static function withdrawNotifiesParticipants(string $current): bool
    {
        return $current !== Status::HEAD_REVIEW;
    }
}
