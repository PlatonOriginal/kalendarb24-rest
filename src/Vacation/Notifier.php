<?php
declare(strict_types=1);

namespace Kb24Rest\Vacation;

use Kb24Rest\Bitrix24\RestClient;
use Kb24Rest\Bitrix24\RestException;
use Kb24Rest\Domain\Status;
use Kb24Rest\Http\AfterResponse;
use Kb24Rest\Portal\Portal;

/**
 * Уведомления о заявках: im.notify.system.add со ссылкой, которая открывает карточку заявки.
 * https://apidocs.bitrix24.com/api-reference/chats/notifications/im-notify-system-add.html
 *
 * Ссылка вида /marketplace/view/<код приложения>/?params[req]=<ID> открывает приложение в слайдере
 * через встройку REST_APP_URI, параметры приходят в PLACEMENT_OPTIONS.
 * https://apidocs.bitrix24.com/api-reference/widgets/universal/app-url.html
 *
 * Уведомления копятся во время запроса и уходят одним вызовом batch после ответа браузеру (send),
 * чтобы не задерживать согласование. ФИО автора запрашивается только для текста и нигде не сохраняется.
 */
final class Notifier implements AfterResponse
{
    private const STEP = [
        Status::HEAD_REVIEW => 'у руководителя',
        Status::HR_REVIEW => 'в отделе кадров',
        Status::FINAL_APPROVAL => 'у директора',
    ];

    /** @var list<array{portal: Portal, event: string, request: array, to: list<int>, actorId: int, reason: string}> */
    private array $queue = [];

    /**
     * @param bool $notifyActor присылать уведомление и тому, кто сам сделал действие (проверка под одним аккаунтом)
     */
    public function __construct(
        private readonly RestClient $rest,
        private readonly string $appCode,
        private readonly bool $notifyActor = false,
    ) {
    }

    /**
     * @param string $event review (ждёт решения получателя), step, approved, rejected, withdrawn
     * @param list<int> $to
     */
    public function add(Portal $portal, string $event, array $request, array $to, int $actorId, string $reason = ''): void
    {
        $to = array_values(array_unique(array_filter($to, fn(int $id) => $id > 0 && ($this->notifyActor || $id !== $actorId))));
        if ($to !== [])
        {
            $this->queue[] = ['portal' => $portal, 'event' => $event, 'request' => $request, 'to' => $to, 'actorId' => $actorId, 'reason' => $reason];
        }
    }

    public function hasWork(): bool
    {
        return $this->queue !== [];
    }

    public function run(): void
    {
        $this->send();
    }

    /**
     * Отправить накопленное. Ошибка уведомления не отменяет уже сделанное действие, поэтому только пишется в лог.
     */
    public function send(): void
    {
        $queue = $this->queue;
        $this->queue = [];
        foreach ($this->groupByPortal($queue) as $items)
        {
            $portal = $items[0]['portal'];
            try
            {
                $names = $this->authorNames($portal, $items);
                $commands = [];
                foreach ($items as $item)
                {
                    foreach ($item['to'] as $userId)
                    {
                        $commands['n' . count($commands)] = 'im.notify.system.add?' . http_build_query(
                            $this->message($portal, $item, $userId, $names),
                        );
                    }
                }
                // В одном batch не больше 50 команд
                foreach (array_chunk($commands, 50, true) as $chunk)
                {
                    $result = $this->rest->call($portal, 'batch', ['halt' => 0, 'cmd' => $chunk]);
                    if (!empty($result['result_error']))
                    {
                        error_log('kb24 notify: ' . json_encode($result['result_error'], JSON_UNESCAPED_UNICODE));
                    }
                }
            }
            catch (RestException $e)
            {
                error_log('kb24 notify: ' . $e->errorCode . ' ' . $e->getMessage());
            }
        }
    }

    /**
     * @return list<array<string, mixed>> что лежит в очереди (для тестов)
     */
    public function pending(): array
    {
        return array_map(static fn(array $item) => [
            'event' => $item['event'],
            'requestId' => $item['request']['id'],
            'to' => $item['to'],
            'reason' => $item['reason'],
        ], $this->queue);
    }

    /**
     * @return array{MESSAGE: string, MESSAGE_OUT: string, USER_ID: int, TAG: string}
     */
    private function message(Portal $portal, array $item, int $userId, array $names): array
    {
        $request = $item['request'];
        $kind = Kinds::all()[$request['kind']]['name'] ?? $request['kind'];
        $what = $kind . ' ' . self::date($request['dateFrom']) . ' — ' . self::date($request['dateTo']) . ', дней: ' . $request['days'];
        $author = $names[$request['userId']] ?? 'Сотрудник';
        $reason = RejectReasons::all()[$item['reason']] ?? '';

        $text = match ($item['event'])
        {
            'review' => $author . ' просит согласовать: ' . $what,
            'step' => 'Ваша заявка согласована и теперь ' . (self::STEP[$request['status']] ?? 'на следующем шаге') . ': ' . $what,
            'approved' => 'Ваша заявка утверждена: ' . $what,
            'rejected' => ($userId === $request['userId'] ? 'Ваша заявка отклонена' : 'Заявка, которую вы согласовали, отклонена')
                . ($reason !== '' ? ' (' . $reason . ')' : '') . ': ' . ($userId === $request['userId'] ? '' : $author . ', ') . $what,
            'withdrawn' => 'Заявка отозвана автором: ' . $author . ', ' . $what,
        };
        // Скобки в params[req] кодируем: внутри [URL=...] нераскодированная «]» закрыла бы BB-код раньше времени
        $url = 'https://' . $portal->domain . '/marketplace/view/' . rawurlencode($this->appCode) . '/?params%5Breq%5D=' . $request['id'];

        return [
            'USER_ID' => $userId,
            'MESSAGE' => $text . '[BR][URL=' . $url . ']Открыть заявку № ' . $request['id'] . '[/URL]',
            'MESSAGE_OUT' => $text . "\n" . $url,
            // Новое уведомление по той же заявке заменяет прежнее у этого же получателя
            'TAG' => 'KB24_REQ_' . $request['id'] . '_' . $userId,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function authorNames(Portal $portal, array $items): array
    {
        $ids = [];
        foreach ($items as $item)
        {
            if (array_diff($item['to'], [$item['request']['userId']]) !== [])
            {
                $ids[] = $item['request']['userId'];
            }
        }
        if ($ids === [])
        {
            return [];
        }
        $names = [];
        foreach ((array)$this->rest->call($portal, 'user.get', ['filter' => ['ID' => array_values(array_unique($ids))]]) as $user)
        {
            $names[(int)$user['ID']] = trim(($user['LAST_NAME'] ?? '') . ' ' . ($user['NAME'] ?? '')) ?: 'Сотрудник';
        }

        return $names;
    }

    private function groupByPortal(array $queue): array
    {
        $groups = [];
        foreach ($queue as $item)
        {
            $groups[$item['portal']->memberId][] = $item;
        }

        return array_values($groups);
    }

    private static function date(string $value): string
    {
        $parts = explode('-', $value);

        return count($parts) === 3 ? $parts[2] . '.' . $parts[1] . '.' . $parts[0] : $value;
    }
}
