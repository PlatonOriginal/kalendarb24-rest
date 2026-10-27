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
     * @return array<string, array{name: string, deductsBalance: bool}>
     */
    public static function all(): array
    {
        return [
            'vacation' => ['name' => 'Ежегодный оплачиваемый отпуск', 'deductsBalance' => true],
            'unpaid' => ['name' => 'Отпуск без сохранения заработной платы', 'deductsBalance' => false],
            'study' => ['name' => 'Учебный отпуск', 'deductsBalance' => false],
        ];
    }

    public static function exists(string $code): bool
    {
        return isset(self::all()[$code]);
    }
}
