<?php
declare(strict_types=1);

namespace Kb24Rest\Vacation;

/**
 * Ошибка, которую нужно показать сотруднику как есть: неверные даты, нет согласующего и т. п.
 */
final class VacationError extends \RuntimeException
{
}
