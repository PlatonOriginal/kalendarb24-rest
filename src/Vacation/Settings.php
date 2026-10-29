<?php
declare(strict_types=1);

namespace Kb24Rest\Vacation;

use Kb24Rest\Bitrix24\RestClient;
use Kb24Rest\Portal\Portal;

/**
 * Настройки приложения в портале: app.option.get и app.option.set. Пишет сервер токеном администратора,
 * установившего приложение (метод доступен только администратору в контексте приложения).
 * https://apidocs.bitrix24.com/api-reference/common/settings/app-option-set.html
 */
final class Settings
{
    /** Как в коробке: max_cancel_working_days по умолчанию 3, календарь других отделов сотрудникам не виден (0) */
    public const DEFAULTS = ['max_cancel_working_days' => 3, 'employees_see_other_departments' => 0];

    /** Настройки «да или нет»: хранятся как 0 и 1 */
    public const FLAGS = ['employees_see_other_departments'];

    /** @var array<string, array<string, int>> */
    private array $cache = [];

    public function __construct(private readonly RestClient $rest)
    {
    }

    /**
     * @return array{max_cancel_working_days: int, employees_see_other_departments: int}
     */
    public function all(Portal $portal): array
    {
        if (!isset($this->cache[$portal->memberId]))
        {
            $stored = (array)$this->rest->call($portal, 'app.option.get');
            $values = self::DEFAULTS;
            foreach (self::DEFAULTS as $key => $default)
            {
                if (isset($stored[$key]) && $stored[$key] !== '')
                {
                    $values[$key] = in_array($key, self::FLAGS, true) ? (int)((int)$stored[$key] > 0) : max(0, (int)$stored[$key]);
                }
            }
            $this->cache[$portal->memberId] = $values;
        }

        return $this->cache[$portal->memberId];
    }

    /**
     * Сколько рабочих дней до начала отпуска должно оставаться, чтобы его можно было отменить.
     */
    public function maxCancelWorkingDays(Portal $portal): int
    {
        return $this->all($portal)['max_cancel_working_days'];
    }

    /**
     * Видят ли сотрудники календарь всех отделов, а не только своего и тех, которыми руководят.
     */
    public function employeesSeeOtherDepartments(Portal $portal): bool
    {
        return $this->all($portal)['employees_see_other_departments'] === 1;
    }

    public function setEmployeesSeeOtherDepartments(Portal $portal, bool $value): void
    {
        $this->rest->call($portal, 'app.option.set', ['options' => ['employees_see_other_departments' => $value ? 1 : 0]]);
        unset($this->cache[$portal->memberId]);
    }

    public function setMaxCancelWorkingDays(Portal $portal, int $days): void
    {
        if ($days < 0 || $days > 365)
        {
            throw new VacationError('Укажите число рабочих дней от 0 до 365');
        }
        $this->rest->call($portal, 'app.option.set', ['options' => ['max_cancel_working_days' => $days]]);
        unset($this->cache[$portal->memberId]);
    }
}
