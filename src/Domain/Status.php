<?php
declare(strict_types=1);

namespace Kb24Rest\Domain;

/**
 * Статусы заявки. Прототип: одна попытка, любой отказ закрывает заявку.
 */
final class Status
{
    public const HEAD_REVIEW = 'head_review';
    public const HR_REVIEW = 'hr_review';
    public const FINAL_APPROVAL = 'final_approval';
    /** На шаге настроенного маршрута, шаг — в REQUEST.ROUTE_STEP */
    public const ROUTE_REVIEW = 'route_review';
    public const APPROVED = 'approved';
    public const CANCELLING = 'cancelling';
    public const CANCELLED = 'cancelled';
    public const REJECTED = 'rejected';
    public const WITHDRAWN = 'withdrawn';
    public const AUTO_CANCELLED = 'auto_cancelled';

    /** Статусы, при которых заявка занимает слот в лимитах отдела. */
    public const ACTIVE = [
        self::HEAD_REVIEW,
        self::HR_REVIEW,
        self::FINAL_APPROVAL,
        self::ROUTE_REVIEW,
        self::APPROVED,
        self::CANCELLING,
    ];

    /** Статусы, при которых заявка ждёт решения согласующего. */
    public const REVIEW = [
        self::HEAD_REVIEW,
        self::HR_REVIEW,
        self::FINAL_APPROVAL,
        self::ROUTE_REVIEW,
    ];

    public static function getAll(): array
    {
        return [
            self::HEAD_REVIEW,
            self::HR_REVIEW,
            self::FINAL_APPROVAL,
            self::ROUTE_REVIEW,
            self::APPROVED,
            self::CANCELLING,
            self::CANCELLED,
            self::REJECTED,
            self::WITHDRAWN,
            self::AUTO_CANCELLED,
        ];
    }

    public static function isActive(string $status): bool
    {
        return in_array($status, self::ACTIVE, true);
    }
}
