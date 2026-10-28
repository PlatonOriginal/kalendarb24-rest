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
 * Событие «Отсутствую» в календаре сотрудника после утверждения заявки, как в коробке.
 * https://apidocs.bitrix24.com/api-reference/calendar/calendar-event/calendar-event-add.html
 *
 * Пишется токеном приложения (администратор, установивший приложение) в календарь автора заявки.
 * Может ли администратор писать в чужой календарь, документация не говорит: результат сохраняем
 * в заявке (CAL_EVENT_ID или CAL_ERROR) и показываем в карточке.
 */
final class AbsenceCalendar implements AfterResponse
{
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
        if ($request['calEventId'] > 0)
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
            $result = $action === 'add' ? $this->create($portal, $request) : $this->delete($portal, $request);
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

    private function delete(Portal $portal, array $request): array
    {
        try
        {
            $this->rest->call($portal, 'calendar.event.delete', ['id' => $request['calEventId']]);

            return ['CAL_EVENT_ID' => 0, 'CAL_ERROR' => ''];
        }
        catch (RestException $e)
        {
            return ['CAL_ERROR' => mb_substr('не удалось удалить событие: ' . $e->getMessage(), 0, 250)];
        }
    }

    private function create(Portal $portal, array $request): array
    {
        try
        {
            $eventId = (int)$this->rest->call($portal, 'calendar.event.add', [
                'type' => 'user',
                'ownerId' => $request['userId'],
                'name' => Kinds::all()[$request['kind']]['name'] ?? $request['kind'],
                'description' => 'Заявка № ' . $request['id'] . ' в приложении «Отпуска»',
                'from' => $request['dateFrom'],
                'to' => $request['dateTo'],
                'skip_time' => 'Y',
                'accessibility' => 'absent',
                'importance' => 'normal',
                'is_meeting' => 'N',
                'private_event' => 'N',
            ]);

            return $eventId > 0 ? ['CAL_EVENT_ID' => $eventId, 'CAL_ERROR' => ''] : ['CAL_ERROR' => 'портал не вернул ID события'];
        }
        catch (RestException $e)
        {
            return ['CAL_ERROR' => mb_substr($e->getMessage(), 0, 250)];
        }
    }
}
