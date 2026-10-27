<?php
declare(strict_types=1);

namespace Kb24Rest\Vacation;

/**
 * Причины отказа. Свободного текста нет, чтобы в данные не попадали ФИО и другие персональные данные.
 * Подробности согласующий и сотрудник обсуждают в чате Битрикс24, кнопка есть в карточке заявки.
 */
final class RejectReasons
{
    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return [
            'overlap' => 'Пересекается с отпусками коллег',
            'workload' => 'Высокая загрузка в эти даты',
            'other_dates' => 'Нужно перенести на другие даты',
            'balance' => 'Недостаточно дней отпуска',
            'other' => 'Другое, обсудим в чате',
        ];
    }

    public static function exists(string $code): bool
    {
        return isset(self::all()[$code]);
    }
}
