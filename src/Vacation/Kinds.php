<?php
declare(strict_types=1);

namespace Kb24Rest\Vacation;

/**
 * Виды отсутствий, которые сотрудник подаёт сам через согласование.
 * Больничный, прогул, командировку и отгул в часах вносит руководитель или ОК без согласования, это следующий блок.
 * Настройка видов в интерфейсе тоже позже, пока список как в коробке по умолчанию.
 */
final class Kinds
{
    /**
     * chart: как найти тип в «Графике отсутствий» (на тестовом портале: отпуск ежегодный, командировка, больничный,
     * отпуск декретный, отгул за свой счет, прогул, другое, персональные календари). REST отдаёт значения списка без XML_ID (в коробке VACATION,
     * LEAVEUNPAYED, OTHER), только названия, поэтому ищем по началу слов, а если не нашли, берём «другое».
     *
     * @return array<string, array{name: string, deductsBalance: bool, countsInLimit: bool, paid: bool, chart: list<string>}>
     */
    public static function all(): array
    {
        return [
            'vacation' => ['name' => 'Ежегодный оплачиваемый отпуск', 'deductsBalance' => true, 'countsInLimit' => true, 'paid' => true, 'chart' => ['ежегодн']],
            'unpaid' => ['name' => 'Отпуск без сохранения заработной платы', 'deductsBalance' => false, 'countsInLimit' => true, 'paid' => false, 'chart' => ['за свой сч', 'без содерж', 'без сохран']],
            'study' => ['name' => 'Учебный отпуск', 'deductsBalance' => false, 'countsInLimit' => true, 'paid' => true, 'chart' => ['учебн']],
        ];
    }

    public static function exists(string $code): bool
    {
        return isset(self::all()[$code]);
    }
}
