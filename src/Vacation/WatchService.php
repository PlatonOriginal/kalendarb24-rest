<?php
declare(strict_types=1);

namespace Kb24Rest\Vacation;

use Kb24Rest\Bitrix24\RestClient;
use Kb24Rest\Bitrix24\RestException;
use Kb24Rest\Domain\Status;
use Kb24Rest\Http\AfterResponse;
use Kb24Rest\Portal\CurrentUser;
use Kb24Rest\Portal\Portal;
use Kb24Rest\Storage\EntityStorage;
use Kb24Rest\Storage\Schema;

/**
 * «Уведомить о результате»: получатели из маршрута узнают, что отсутствие утверждено или отменено, и подтверждают
 * ознакомление в карточке заявки (в уведомление Битрикс24 кнопки не добавить, в нём ссылка на карточку).
 * Пока не подтвердили, каждый рабочий день до начала отсутствия приходит напоминание (bin/remind.php по расписанию).
 * Задача получателю ставится сразу или по его выбору при ознакомлении, срок — за N дней до начала.
 * Одна запись kback на получателя и событие.
 */
final class WatchService implements AfterResponse
{
    /** @var list<array{portal: Portal, request: array, record: array}> задачи, которые ставим после ответа браузеру */
    private array $tasks = [];

    public function __construct(
        private readonly EntityStorage $storage,
        private readonly RestClient $rest,
        private readonly ?Notifier $notifier = null,
        private readonly \DateTimeImmutable $today = new \DateTimeImmutable('today'),
    ) {
    }

    /**
     * @param list<array{userId: int, task: string, taskDays: int}> $recipients
     */
    public function start(Portal $portal, array $request, string $event, array $recipients): void
    {
        foreach ($recipients as $recipient)
        {
            $values = [
                'REQ_ID' => $request['id'],
                'USER_ID' => $recipient['userId'],
                'EVENT' => $event,
                'DATE_FROM' => $request['dateFrom'],
                'TASK_MODE' => $recipient['task'],
                'TASK_DAYS' => $recipient['taskDays'],
                'TASK_ID' => 0,
                'TASK_ERROR' => '',
                'ACK_AT' => '',
                // Сегодня уже уведомили: первое напоминание завтра
                'REMIND_AT' => $this->today->format('Y-m-d'),
            ];
            $id = $this->storage->add($portal, Schema::ACKS, $event, $values);
            // Уведомляем и того, кто сам утвердил: ознакомиться и поставить задачу нужно и ему
            $this->notifier?->add($portal, 'watch', $request, [$recipient['userId']], 0, $event);
            if ($recipient['task'] === 'auto')
            {
                $this->tasks[] = ['portal' => $portal, 'request' => $request, 'record' => self::toRecord(['ID' => $id, 'PROPERTY_VALUES' => $values])];
            }
        }
    }

    /**
     * Кто должен был ознакомиться с результатом заявки, кто уже ознакомился, какие задачи поставлены.
     *
     * @return list<array<string, mixed>>
     */
    public function forRequest(Portal $portal, int $requestId): array
    {
        return $this->records($this->storage->find($portal, Schema::ACKS, ['PROPERTY_REQ_ID' => $requestId]));
    }

    /**
     * Записи kback, прочитанные вместе с заявкой (карточка читает всё одним batch).
     *
     * @return list<array<string, mixed>>
     */
    public function records(array $items): array
    {
        return array_map(fn(array $item) => $this->withDeadline(self::toRecord($item)), $items);
    }

    /**
     * Заявки, с результатом которых сотрудник ещё не ознакомился.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingFor(CurrentUser $user): array
    {
        return array_values(array_filter(
            array_map(self::toRecord(...), $this->storage->find($user->portal, Schema::ACKS, ['PROPERTY_USER_ID' => $user->userId])),
            static fn(array $record) => $record['ackAt'] === '',
        ));
    }

    /**
     * Подтвердить ознакомление. При режиме «спросить» сотрудник может сразу поставить себе задачу со своим сроком.
     */
    public function acknowledge(CurrentUser $user, array $request, bool $task, string $deadline): array
    {
        $portal = $user->portal;
        $pending = array_values(array_filter(
            $this->forRequest($portal, $request['id']),
            static fn(array $record) => $record['userId'] === $user->userId && $record['ackAt'] === '',
        ));
        if (!$pending)
        {
            throw new VacationError('Подтверждать ознакомление по этой заявке не нужно');
        }
        foreach ($pending as $record)
        {
            $values = ['ACK_AT' => (new \DateTimeImmutable())->format('Y-m-d H:i')];
            if ($task && $record['taskMode'] === 'ask' && $record['taskId'] === 0)
            {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $deadline);
                if (!$date || $date->format('Y-m-d') !== $deadline || $date < $this->today)
                {
                    throw new VacationError('Срок задачи: укажите дату не раньше сегодняшней');
                }
                // Если портал отказал, ознакомление не засчитываем: сотрудник увидит причину и сможет повторить
                try
                {
                    $values['TASK_ID'] = $this->createTask($portal, $request, $record, $date);
                    $values['TASK_ERROR'] = '';
                }
                catch (RestException $e)
                {
                    throw new VacationError('Не удалось поставить задачу: ' . $e->getMessage());
                }
            }
            $this->storage->update($portal, Schema::ACKS, $record['id'], $values);
        }

        return $this->forRequest($portal, $request['id']);
    }

    /**
     * Напомнить всем, кто не ознакомился: раз в день, только в рабочие дни и только до начала отсутствия.
     * Об утверждении не напоминаем, если отсутствие уже отменили, — о самой отмене будет своё уведомление.
     *
     * @return int сколько напоминаний поставлено в очередь
     */
    public function remind(Portal $portal, bool $workingDay): int
    {
        if (!$workingDay)
        {
            return 0;
        }
        $today = $this->today->format('Y-m-d');
        $due = array_filter(
            array_map(self::toRecord(...), $this->storage->find($portal, Schema::ACKS)),
            static fn(array $r) => $r['ackAt'] === '' && $r['remindAt'] < $today && $r['dateFrom'] > $today,
        );
        if (!$due)
        {
            return 0;
        }
        $requests = [];
        foreach ($this->storage->find($portal, Schema::REQUESTS) as $item)
        {
            $requests[(int)$item['ID']] = RequestService::toRequest($item);
        }
        $count = 0;
        foreach ($due as $record)
        {
            $request = $requests[$record['requestId']] ?? null;
            $stillActual = $request !== null && ($record['event'] === 'cancelled'
                ? $request['status'] === Status::CANCELLED
                : in_array($request['status'], [Status::APPROVED, Status::CANCELLING], true));
            if ($stillActual)
            {
                $this->notifier?->add($portal, 'remind', $request, [$record['userId']], 0, $record['event']);
                $count++;
            }
            $this->storage->update($portal, Schema::ACKS, $record['id'], ['REMIND_AT' => $today]);
        }

        return $count;
    }

    public function hasWork(): bool
    {
        return $this->tasks !== [];
    }

    /**
     * Задачи «ставить сразу». Ошибка портала сохраняется в записи и видна в карточке.
     */
    public function run(): void
    {
        $tasks = $this->tasks;
        $this->tasks = [];
        foreach ($tasks as ['portal' => $portal, 'request' => $request, 'record' => $record])
        {
            try
            {
                $taskId = $this->createTask($portal, $request, $record, new \DateTimeImmutable($this->withDeadline($record)['deadline']));
                $values = ['TASK_ID' => $taskId, 'TASK_ERROR' => ''];
            }
            catch (RestException $e)
            {
                $values = ['TASK_ERROR' => mb_substr($e->errorCode . ': ' . $e->getMessage(), 0, 250)];
            }
            try
            {
                $this->storage->update($portal, Schema::ACKS, $record['id'], $values);
            }
            catch (RestException $e)
            {
                error_log('kb24 task: ' . $e->errorCode . ' ' . $e->getMessage());
            }
        }
    }

    /**
     * Задача ответственному — получателю уведомления, постановщик — автор заявки.
     * ФИО автора нужно только для названия задачи и на сервере не сохраняется.
     * https://apidocs.bitrix24.com/api-reference/tasks/tasks-task-add.html
     */
    private function createTask(Portal $portal, array $request, array $record, \DateTimeImmutable $deadline): int
    {
        $users = (array)$this->rest->call($portal, 'user.get', ['filter' => ['ID' => $request['userId']]]);
        $author = trim(($users[0]['LAST_NAME'] ?? '') . ' ' . ($users[0]['NAME'] ?? '')) ?: 'сотрудник ' . $request['userId'];
        $kind = Kinds::all()[$request['kind']]['name'] ?? $request['kind'];
        $period = self::date($request['dateFrom']) . '–' . self::date($request['dateTo']);
        $title = ($record['event'] === 'cancelled' ? 'Отмена отпуска: ' : 'Отпуск: ') . $author . ', ' . $period . ', ' . mb_strtolower($kind);

        $result = (array)$this->rest->call($portal, 'tasks.task.add', ['fields' => [
            'TITLE' => $title,
            'DESCRIPTION' => $title . '. Заявка № ' . $request['id'] . ' в приложении «Отпуска».',
            'RESPONSIBLE_ID' => $record['userId'],
            'CREATED_BY' => $request['userId'],
            'DEADLINE' => $deadline->format('Y-m-d') . 'T18:00:00',
        ]]);

        return (int)($result['task']['id'] ?? 0);
    }

    /**
     * Срок задачи по умолчанию: за TASK_DAYS дней до начала, но не раньше сегодняшнего дня.
     */
    private function withDeadline(array $record): array
    {
        $deadline = (new \DateTimeImmutable($record['dateFrom']))->modify('-' . $record['taskDays'] . ' days');
        $record['deadline'] = max($deadline, $this->today)->format('Y-m-d');

        return $record;
    }

    private static function toRecord(array $item): array
    {
        $values = $item['PROPERTY_VALUES'] ?? [];

        return [
            'id' => (int)$item['ID'],
            'requestId' => (int)($values['REQ_ID'] ?? 0),
            'userId' => (int)($values['USER_ID'] ?? 0),
            'event' => (string)($values['EVENT'] ?? ''),
            'dateFrom' => (string)($values['DATE_FROM'] ?? ''),
            'taskMode' => (string)($values['TASK_MODE'] ?? 'none'),
            'taskDays' => (int)($values['TASK_DAYS'] ?? 0),
            'taskId' => (int)($values['TASK_ID'] ?? 0),
            'taskError' => (string)($values['TASK_ERROR'] ?? ''),
            'ackAt' => (string)($values['ACK_AT'] ?? ''),
            'remindAt' => (string)($values['REMIND_AT'] ?? ''),
        ];
    }

    private static function date(string $value): string
    {
        $parts = explode('-', $value);

        return count($parts) === 3 ? $parts[2] . '.' . $parts[1] . '.' . $parts[0] : $value;
    }
}
