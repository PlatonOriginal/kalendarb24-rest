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
     * chart: как найти тип в «Графике отсутствий». REST отдаёт значения списка без XML_ID (в коробке VACATION,
     * LEAVEUNPAYED, OTHER), только названия, поэтому ищем по началу слов, а если не нашли, берём «другое».
     *
     * @return array<string, array{name: string, deductsBalance: bool, chart: list<string>}>
     */
    public static function all(): array
    {
        return [
            'vacation' => ['name' => 'Ежегодный оплачиваемый отпуск', 'deductsBalance' => true, 'chart' => ['ежегодн']],
            'unpaid' => ['name' => 'Отпуск без сохранения заработной платы', 'deductsBalance' => false, 'chart' => ['без содерж', 'без сохран']],
            'study' => ['name' => 'Учебный отпуск', 'deductsBalance' => false, 'chart' => ['учебн']],
        ];
    }

    public static function exists(string $code): bool
    {
        return isset(self::all()[$code]);
    }
}
