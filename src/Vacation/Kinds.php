<?php
declare(strict_types=1);

namespace Kb24Rest\Vacation;

/**
 * Виды отсутствий. Сотрудник подаёт их сам, а с правом «Подача заявок за сотрудников» — и за других
 * (больничный за заболевшего, отгул по факту). Согласование задают маршруты, больничный по умолчанию утверждается сразу.
 * Настройка видов в интерфейсе позже, пока список фиксированный.
 */
final class Kinds
{
    /**
     * chart: как найти тип в «Графике отсутствий» (на тестовом портале: отпуск ежегодный, командировка, больничный,
     * отпуск декретный, отгул за свой счет, прогул, другое, персональные календари). REST отдаёт значения списка без XML_ID (в коробке VACATION,
     * LEAVEUNPAYED, OTHER), только названия, поэтому ищем по началу слов (с «=» — точное название), а если не нашли, берём «другое».
     * chartName: какое значение добавить в график при установке, если подходящего нет.
     * backdated: можно внести задним числом и без срока подачи; overlaps: может пересекаться с другими отсутствиями сотрудника.
     *
     * @return array<string, array{name: string, deductsBalance: bool, countsInLimit: bool, paid: bool, chart: list<string>, chartName: string, backdated: bool, overlaps: bool}>
     */
    public static function all(): array
    {
        $kind = static fn(string $name, bool $deducts, bool $limit, bool $paid, array $chart, string $chartName = '', bool $backdated = false, bool $overlaps = false) => [
            'name' => $name, 'deductsBalance' => $deducts, 'countsInLimit' => $limit, 'paid' => $paid,
            'chart' => $chart, 'chartName' => $chartName, 'backdated' => $backdated, 'overlaps' => $overlaps,
        ];

        return [
            'vacation' => $kind('Ежегодный оплачиваемый отпуск', true, true, true, ['ежегодн']),
            'unpaid' => $kind('Отпуск без сохранения заработной платы', false, true, false, ['за свой сч', 'без содерж', 'без сохран']),
            'study' => $kind('Учебный отпуск', false, true, true, ['учебн']),
            'dayoff' => $kind('Отгул', false, true, false, ['=отгул'], 'Отгул'),
            'sick' => $kind('Больничный', false, false, true, ['больничн'], 'Больничный', true, true),
        ];
    }

    public static function exists(string $code): bool
    {
        return isset(self::all()[$code]);
    }
}
