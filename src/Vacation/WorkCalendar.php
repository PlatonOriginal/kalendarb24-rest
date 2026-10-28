<?php
declare(strict_types=1);

namespace Kb24Rest\Vacation;

use Kb24Rest\Bitrix24\RestClient;
use Kb24Rest\Domain\WorkingDays;
use Kb24Rest\Portal\Portal;

/**
 * Производственный календарь портала из calendar.settings.get.
 * https://apidocs.bitrix24.com/api-reference/calendar/calendar-settings-get.html
 */
final class WorkCalendar
{
    /** @var array<string, WorkingDays> */
    private array $cache = [];

    public function __construct(private readonly RestClient $rest)
    {
    }

    public function workingDays(Portal $portal): WorkingDays
    {
        if (!isset($this->cache[$portal->memberId]))
        {
            $settings = (array)$this->rest->call($portal, 'calendar.settings.get');
            $this->cache[$portal->memberId] = new WorkingDays(
                array_values((array)($settings['week_holidays'] ?? ['SA', 'SU'])),
                WorkingDays::parseList((string)($settings['year_holidays'] ?? '')),
                WorkingDays::parseList((string)($settings['year_workdays'] ?? '')),
            );
        }

        return $this->cache[$portal->memberId];
    }
}
