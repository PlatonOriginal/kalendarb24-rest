<?php
declare(strict_types=1);

namespace Kb24Rest\Vacation;

use Kb24Rest\Bitrix24\RestClient;
use Kb24Rest\Bitrix24\RestException;
use Kb24Rest\Http\AfterResponse;
use Kb24Rest\Portal\Portal;
use Kb24Rest\Storage\EntityStorage;
use Kb24Rest\Storage\Schema;

/**
 * После утверждения заявки, как в коробке: запись в «График отсутствий» и событие в календаре сотрудника.
 * https://apidocs.bitrix24.com/api-reference/lists/elements/lists-element-add.html
 * https://apidocs.bitrix24.com/api-reference/calendar/calendar-event/calendar-event-add.html
 *
 * График отсутствий — инфоблок absence типа structure. Список инфоблоков этого типа (lists.get) портал
 * не отдаёт (ACCESS_DENIED), а по коду absence чтение и запись работают, это проверено на портале.
 *
 * Событие пишется токеном приложения (администратор, установивший приложение). График показывает события
 * «Отсутствую» по создателю, то есть у администратора, поэтому событие ставим «Занят»: в графике остаётся
 * только запись сотрудника. Результат сохраняем в заявке (CAL_EVENT_ID, ABSENCE_ID, CAL_ERROR).
 */
final class AbsenceCalendar implements AfterResponse
{
    private const SECTION_NAME = 'Отпуска';
    private const CHART = ['IBLOCK_TYPE_ID' => 'structure', 'IBLOCK_CODE' => 'absence'];

    /** @var list<array{portal: Portal, request: array, action: string}> */
    private array $queue = [];

    public function __construct(
        private readonly RestClient $rest,
        private readonly EntityStorage $storage,
    ) {
    }

    public function add(Portal $portal, array $request): void
    {
        $this->queue[] = ['portal' => $portal, 'request' => $request, 'action' => 'add'];
    }

    /**
     * Удалить событие после отмены заявки, как Calendar::delete в коробке.
     * https://apidocs.bitrix24.com/api-reference/calendar/calendar-event/calendar-event-delete.html
     */
    public function remove(Portal $portal, array $request): void
    {
        if ($request['calEventId'] > 0 || $request['absenceId'] > 0)
        {
            $this->queue[] = ['portal' => $portal, 'request' => $request, 'action' => 'remove'];
        }
    }

    public function hasWork(): bool
    {
        return $this->queue !== [];
    }

    public function run(): void
    {
        $queue = $this->queue;
        $this->queue = [];
        foreach ($queue as ['portal' => $portal, 'request' => $request, 'action' => $action])
        {
            $result = $action === 'add' ? $this->addAll($portal, $request) : $this->removeAll($portal, $request);
            try
            {
                $this->storage->update($portal, Schema::REQUESTS, $request['id'], $result);
            }
            catch (RestException $e)
            {
                error_log('kb24 calendar: ' . $e->errorCode . ' ' . $e->getMessage());
            }
        }
    }

    /**
     * Создаёт то, чего у заявки ещё нет: при повторе после ошибки уже созданное не дублируется.
     */
    private function addAll(Portal $portal, array $request): array
    {
        $result = [];
        $errors = [];
        if ($request['absenceId'] === 0)
        {
            [$result['ABSENCE_ID'], $errors[]] = $this->createChartEntry($portal, $request);
        }
        if ($request['calEventId'] === 0)
        {
            [$result['CAL_EVENT_ID'], $errors[]] = $this->createEvent($portal, $request);
        }
        $result['CAL_ERROR'] = mb_substr(implode('; ', array_filter($errors)), 0, 250);

        return $result;
    }

    private function removeAll(Portal $portal, array $request): array
    {
        $result = [];
        $errors = [];
        foreach ([
            'ABSENCE_ID' => [$request['absenceId'], 'lists.element.delete', self::CHART + ['ELEMENT_ID' => $request['absenceId']], 'запись в графике'],
            'CAL_EVENT_ID' => [$request['calEventId'], 'calendar.event.delete', ['id' => $request['calEventId']], 'событие'],
        ] as $field => [$id, $method, $params, $what])
        {
            if ($id <= 0)
            {
                continue;
            }
            try
            {
                $this->rest->call($portal, $method, $params);
                $result[$field] = 0;
            }
            catch (RestException $e)
            {
                $errors[] = 'не удалось удалить ' . $what . ': ' . $e->getMessage();
            }
        }
        $result['CAL_ERROR'] = mb_substr(implode('; ', $errors), 0, 250);

        return $result;
    }

    /**
     * Запись в графике отсутствий, как AbsenceChart::add в коробке: сотрудник (свойство USER) и тип (ABSENCE_TYPE).
     * Свойства ищем по коду, ID свойств и значений на каждом портале свои.
     *
     * @return array{0: int, 1: string} ID записи и ошибка
     */
    private function createChartEntry(Portal $portal, array $request): array
    {
        try
        {
            $userField = $typeField = null;
            $typeValues = [];
            foreach ((array)$this->rest->call($portal, 'lists.field.get', self::CHART) as $id => $field)
            {
                $code = (string)($field['CODE'] ?? '');
                if ($code === 'USER')
                {
                    $userField = (string)$id;
                }
                elseif ($code === 'ABSENCE_TYPE')
                {
                    $typeField = (string)$id;
                    $typeValues = (array)($field['DISPLAY_VALUES_FORM'] ?? []);
                }
            }
            $typeId = $typeField === null ? null : self::chartType($typeValues, $request['kind']);
            if ($userField === null || $typeId === null)
            {
                return [0, 'в графике отсутствий нет поля сотрудника или подходящего типа отсутствия'];
            }
            $kind = Kinds::all()[$request['kind']]['name'] ?? $request['kind'];
            $id = (int)$this->rest->call($portal, 'lists.element.add', self::CHART + [
                'ELEMENT_CODE' => 'kb24_req_' . $request['id'],
                'FIELDS' => [
                    'NAME' => $kind,
                    'ACTIVE_FROM' => self::portalDate($request['dateFrom']),
                    'ACTIVE_TO' => self::portalDate($request['dateTo']),
                    $userField => $request['userId'],
                    $typeField => $typeId,
                ],
            ]);

            return $id > 0 ? [$id, ''] : [0, 'портал не вернул ID записи в графике'];
        }
        catch (RestException $e)
        {
            return [0, 'график отсутствий: ' . $e->getMessage()];
        }
    }

    /**
     * @param array<string|int, string> $values ID значения => название
     */
    private static function chartType(array $values, string $kind): ?string
    {
        foreach ([Kinds::all()[$kind]['chart'] ?? [], ['друг']] as $keywords)
        {
            foreach ($values as $id => $name)
            {
                foreach ($keywords as $keyword)
                {
                    if (str_contains(mb_strtolower((string)$name), $keyword))
                    {
                        return (string)$id;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Дата в формате портала, как Date::toString() в коробке. Тестовый портал русскоязычный, ДД.ММ.ГГГГ.
     */
    private static function portalDate(string $date): string
    {
        return (new \DateTimeImmutable($date))->format('d.m.Y');
    }

    /**
     * Календарь сотрудника, куда приложение может добавить событие. Без явной секции портал на портале
     * ответил «Доступ запрещен», поэтому ищем секцию автора с правом add (calendar.section.get возвращает PERM
     * для того, кто вызывает), а если такой нет, создаём ему календарь «Отпуска»: по документации
     * calendar.section.add администратор может создавать календари другим пользователям.
     * https://apidocs.bitrix24.com/api-reference/calendar/calendar-section-get.html
     * https://apidocs.bitrix24.com/api-reference/calendar/calendar-section-add.html
     */
    private function sectionFor(Portal $portal, int $userId): int
    {
        $sections = array_filter(
            (array)$this->rest->call($portal, 'calendar.section.get', ['type' => 'user', 'ownerId' => $userId]),
            static fn(array $section) => (int)($section['OWNER_ID'] ?? 0) === $userId,
        );
        // Календарь «Отпуска», который приложение создало раньше. PERM.add у него на портале false,
        // хотя событие в него добавляется: без этой проверки на каждую заявку создавался бы новый календарь
        foreach ($sections as $section)
        {
            if (($section['NAME'] ?? '') === self::SECTION_NAME)
            {
                return (int)$section['ID'];
            }
        }
        foreach ($sections as $section)
        {
            if (!empty($section['PERM']['add']))
            {
                return (int)$section['ID'];
            }
        }

        return (int)$this->rest->call($portal, 'calendar.section.add', [
            'type' => 'user',
            'ownerId' => $userId,
            'name' => self::SECTION_NAME,
        ]);
    }

    /**
     * @return array{0: int, 1: string} ID события и ошибка
     */
    private function createEvent(Portal $portal, array $request): array
    {
        try
        {
            $section = $this->sectionFor($portal, $request['userId']);
            if ($section <= 0)
            {
                return [0, 'не найден календарь сотрудника, куда приложение может добавить событие'];
            }
            $eventId = (int)$this->rest->call($portal, 'calendar.event.add', [
                'section' => $section,
                'auto_detect_section' => 'N',
                'type' => 'user',
                'ownerId' => $request['userId'],
                'name' => Kinds::all()[$request['kind']]['name'] ?? $request['kind'],
                'description' => 'Заявка № ' . $request['id'] . ' в приложении «Отпуска»',
                'from' => $request['dateFrom'],
                'to' => $request['dateTo'],
                'skip_time' => 'Y',
                'accessibility' => 'busy',
                'importance' => 'normal',
                'is_meeting' => 'N',
                'private_event' => 'N',
            ]);

            return $eventId > 0 ? [$eventId, ''] : [0, 'портал не вернул ID события'];
        }
        catch (RestException $e)
        {
            return [0, 'календарь: ' . $e->getMessage()];
        }
    }
}
