<?php
declare(strict_types=1);

namespace Kb24Rest\Vacation;

use Kb24Rest\Domain\ApprovalRoute;
use Kb24Rest\Domain\DateRange;
use Kb24Rest\Domain\Status;
use Kb24Rest\Portal\CurrentUser;
use Kb24Rest\Portal\Portal;
use Kb24Rest\Storage\EntityStorage;
use Kb24Rest\Storage\Schema;
use Kb24Rest\Structure\StructureService;

/**
 * Заявки и согласование по маршруту по умолчанию, как в коробке: руководитель → ОК → директор.
 * Все проверки здесь, на сервере: сотрудник не может изменить заявку в обход, данные в портале пишет только сервер.
 * Пока нет: настраиваемых маршрутов, заместителей, лимитов отделов и остатков (следующие блоки).
 */
final class RequestService
{
    public const CANCEL_HEAD = 'head_review';
    public const CANCEL_HR = 'hr_review';
    public const CANCEL_DONE = 'done';
    public const CANCEL_REFUSED = 'refused';

    public function __construct(
        private readonly EntityStorage $storage,
        private readonly StructureService $structure,
        private readonly RoleService $roles,
        private readonly WorkCalendar $calendar,
        private readonly \DateTimeImmutable $today = new \DateTimeImmutable('today'),
        private readonly bool $allowSelfApproval = false,
        private readonly ?Notifier $notifier = null,
        private readonly ?AbsenceCalendar $absence = null,
        private readonly ?Settings $settings = null,
    ) {
    }

    public function create(CurrentUser $user, string $kind, string $dateFrom, string $dateTo): array
    {
        $portal = $user->portal;
        if (!Kinds::exists($kind))
        {
            throw new VacationError('Не выбран вид отсутствия');
        }
        $range = $this->parseRange($dateFrom, $dateTo);
        if ($range->from < $this->today)
        {
            throw new VacationError('Нельзя подать заявку на прошедшие даты');
        }

        $departmentId = $this->structure->departmentsOf($portal, $user->userId)[0] ?? null;
        if ($departmentId === null)
        {
            throw new VacationError('Сотрудник не привязан к отделу в структуре компании');
        }
        foreach ($this->findRequests($portal, ['PROPERTY_USER_ID' => $user->userId]) as $existing)
        {
            if (Status::isActive($existing['status']) && $range->overlapDays($this->parseRange($existing['dateFrom'], $existing['dateTo'])) > 0)
            {
                throw new VacationError('На эти даты у вас уже есть заявка');
            }
        }

        $days = Kinds::all()[$kind]['deductsBalance']
            ? $this->calendar->workingDays($portal)->countVacationDays($range)
            : $range->getDays();

        $route = $this->route($portal, $user->userId, $departmentId);
        $status = $route->getFirstStatus();
        $approverId = $this->pickApprover($portal, $status, $user->userId, $departmentId);

        $id = $this->storage->add($portal, Schema::REQUESTS, $kind, [
            'USER_ID' => $user->userId,
            'DEPT_ID' => $departmentId,
            'KIND' => $kind,
            'DATE_FROM' => $range->from->format('Y-m-d'),
            'DATE_TO' => $range->to->format('Y-m-d'),
            'DAYS' => $days,
            'STATUS' => $status,
            'APPROVER_ID' => $approverId,
        ]);
        $this->log($portal, $id, $user->userId, 'create', null, $status);
        $request = $this->load($portal, $id);
        $this->notifier?->add($portal, 'review', $request, [$approverId], $user->userId);

        return $request;
    }

    public function approve(CurrentUser $user, int $id): array
    {
        $portal = $user->portal;
        $request = $this->awaitingDecisionOf($user, $id);
        $route = $this->route($portal, $request['userId'], $request['deptId']);
        $next = $route->getNextStatus($request['status']);
        $approverId = $next === Status::APPROVED ? 0 : $this->pickApprover($portal, $next, $request['userId'], $request['deptId']);
        $approvedBy = [...$this->approvedBy($portal, $id), $user->userId];
        $this->log($portal, $id, $user->userId, 'approve', $request['status'], $next);

        // Дважды одну заявку никто не согласует: шаг того, кто уже согласовал её раньше, засчитывается сам.
        // Например, руководитель сотрудника ОК проверяет заявку и за ОК, если другого сотрудника ОК нет.
        while ($next !== Status::APPROVED && in_array($approverId, $approvedBy, true))
        {
            $skipped = $next;
            $skippedBy = $approverId;
            $next = $route->getNextStatus($skipped);
            $approverId = $next === Status::APPROVED ? 0 : $this->pickApprover($portal, $next, $request['userId'], $request['deptId']);
            $this->log($portal, $id, $skippedBy, 'auto_approve', $skipped, $next);
        }

        $this->storage->update($portal, Schema::REQUESTS, $id, ['STATUS' => $next, 'APPROVER_ID' => $approverId]);
        $request = $this->load($portal, $id);
        if ($next === Status::APPROVED)
        {
            $this->absence?->add($portal, $request);
            $this->notifier?->add($portal, 'approved', $request, [$request['userId']], $user->userId);
        }
        else
        {
            $this->notifier?->add($portal, 'review', $request, [$approverId], $user->userId);
            $this->notifier?->add($portal, 'step', $request, [$request['userId']], $user->userId);
        }

        return $request;
    }

    public function reject(CurrentUser $user, int $id, string $reason): array
    {
        if (!RejectReasons::exists($reason))
        {
            throw new VacationError('Выберите причину отказа');
        }
        $portal = $user->portal;
        $request = $this->awaitingDecisionOf($user, $id);

        $this->storage->update($portal, Schema::REQUESTS, $id, ['STATUS' => Status::REJECTED, 'APPROVER_ID' => 0]);
        $this->log($portal, $id, $user->userId, 'reject', $request['status'], Status::REJECTED, $reason);
        $approvedBy = $this->approvedBy($portal, $id);
        $request = $this->load($portal, $id);
        $this->notifier?->add($portal, 'rejected', $request, [$request['userId'], ...$approvedBy], $user->userId, $reason);

        return $request;
    }

    public function withdraw(CurrentUser $user, int $id): array
    {
        $portal = $user->portal;
        $request = $this->load($portal, $id);
        if ($request['userId'] !== $user->userId)
        {
            throw new VacationError('Отозвать заявку может только её автор');
        }
        if (!ApprovalRoute::canWithdraw($request['status']))
        {
            throw new VacationError('Заявка уже не ждёт решения');
        }

        $this->storage->update($portal, Schema::REQUESTS, $id, ['STATUS' => Status::WITHDRAWN, 'APPROVER_ID' => 0]);
        $this->log($portal, $id, $user->userId, 'withdraw', $request['status'], Status::WITHDRAWN);
        $approvedBy = $this->approvedBy($portal, $id);
        $notify = [$request['approverId'], ...$approvedBy];
        $request = $this->load($portal, $id);
        $this->notifier?->add($portal, 'withdrawn', $request, $notify, $user->userId);

        return $request;
    }

    /**
     * Отмена утверждённой заявки, как в коробке: сотрудник просит отменить не позже чем за N рабочих дней
     * до начала, отмену согласуют руководитель, затем ОК. Шага бухгалтерии пока нет: нет её роли.
     */
    public function startCancellation(CurrentUser $user, int $id, string $reason): array
    {
        $portal = $user->portal;
        $request = $this->load($portal, $id);
        $check = $this->whyNotCancellable($portal, $request, $user->userId);
        if ($check !== null)
        {
            throw new VacationError($check);
        }
        $reason = trim($reason);
        if ($reason === '')
        {
            throw new VacationError('Укажите причину отмены');
        }

        $approverId = $this->pickApprover($portal, Status::HEAD_REVIEW, $request['userId'], $request['deptId']);
        $this->storage->update($portal, Schema::REQUESTS, $id, [
            'STATUS' => Status::CANCELLING,
            'APPROVER_ID' => $approverId,
            'CANCEL_STEP' => self::CANCEL_HEAD,
            'CANCEL_REASON' => mb_substr($reason, 0, 500),
        ]);
        $this->log($portal, $id, $user->userId, 'cancel_start', Status::APPROVED, Status::CANCELLING, mb_substr($reason, 0, 500));
        $request = $this->load($portal, $id);
        $this->notifier?->add($portal, 'cancel_review', $request, [$approverId], $user->userId);

        return $request;
    }

    public function approveCancellation(CurrentUser $user, int $id): array
    {
        $portal = $user->portal;
        $request = $this->awaitingCancellationDecisionOf($user, $id);

        if ($request['cancelStep'] === self::CANCEL_HEAD)
        {
            $approverId = $this->hrApprover($portal, $request['userId'], $request['deptId']);
            if (!$approverId)
            {
                throw new VacationError('Не найден следующий согласующий отмены: проверьте роли в настройках');
            }
            if ($approverId === $user->userId)
            {
                // Руководитель проверяет отмену и за ОК: второй раз его не спрашиваем
                $this->log($portal, $id, $user->userId, 'cancel_approve', self::CANCEL_HEAD, self::CANCEL_HR);
                $this->log($portal, $id, $user->userId, 'cancel_auto_approve', self::CANCEL_HR, Status::CANCELLED);

                return $this->finishCancellation($user, $id);
            }
            $this->storage->update($portal, Schema::REQUESTS, $id, ['APPROVER_ID' => $approverId, 'CANCEL_STEP' => self::CANCEL_HR]);
            $this->log($portal, $id, $user->userId, 'cancel_approve', self::CANCEL_HEAD, self::CANCEL_HR);
            $request = $this->load($portal, $id);
            $this->notifier?->add($portal, 'cancel_review', $request, [$approverId], $user->userId);

            return $request;
        }

        $this->log($portal, $id, $user->userId, 'cancel_approve', self::CANCEL_HR, Status::CANCELLED);

        return $this->finishCancellation($user, $id);
    }

    private function finishCancellation(CurrentUser $user, int $id): array
    {
        $portal = $user->portal;
        $this->storage->update($portal, Schema::REQUESTS, $id, ['STATUS' => Status::CANCELLED, 'APPROVER_ID' => 0, 'CANCEL_STEP' => self::CANCEL_DONE]);
        $request = $this->load($portal, $id);
        $this->absence?->remove($portal, $request);
        $this->notifier?->add($portal, 'cancelled', $request, $this->participants($portal, $request), $user->userId);

        return $request;
    }

    public function refuseCancellation(CurrentUser $user, int $id, string $reason): array
    {
        $portal = $user->portal;
        $request = $this->awaitingCancellationDecisionOf($user, $id);
        $reason = trim($reason);
        if ($reason === '')
        {
            throw new VacationError('Укажите причину отказа в отмене');
        }

        $this->storage->update($portal, Schema::REQUESTS, $id, ['STATUS' => Status::APPROVED, 'APPROVER_ID' => 0, 'CANCEL_STEP' => self::CANCEL_REFUSED]);
        $this->log($portal, $id, $user->userId, 'cancel_refuse', $request['cancelStep'], Status::APPROVED, mb_substr($reason, 0, 500));
        $request = $this->load($portal, $id);
        $this->notifier?->add($portal, 'cancel_refused', $request, $this->participants($portal, $request), $user->userId, mb_substr($reason, 0, 500));

        return $request;
    }

    /**
     * Повторить запись события в календарь, если в прошлый раз портал отказал.
     */
    public function retryCalendar(CurrentUser $user, int $id): array
    {
        $portal = $user->portal;
        $request = $this->load($portal, $id);
        if ($request['userId'] !== $user->userId && !$user->isAdmin)
        {
            throw new VacationError('Повторить может автор заявки или администратор');
        }
        if ($request['status'] !== Status::APPROVED || $request['calEventId'] > 0)
        {
            throw new VacationError('Событие нужно только утверждённой заявке, у которой его ещё нет');
        }
        $this->storage->update($portal, Schema::REQUESTS, $id, ['CAL_ERROR' => '']);
        $request = $this->load($portal, $id);
        $this->absence?->add($portal, $request);

        return $request;
    }

    /**
     * @return string|null почему сотрудник не может отменить заявку
     */
    private function whyNotCancellable(Portal $portal, array $request, int $userId): ?string
    {
        if ($request['userId'] !== $userId)
        {
            return 'Отменить отсутствие может только сам сотрудник';
        }
        if ($request['status'] !== Status::APPROVED)
        {
            return 'Отменить можно только утверждённое отсутствие';
        }
        $minDays = $this->settings?->maxCancelWorkingDays($portal) ?? Settings::DEFAULTS['max_cancel_working_days'];
        $start = new \DateTimeImmutable($request['dateFrom']);
        if ($start <= $this->today || $this->calendar->workingDays($portal)->countBetween($this->today, $start) < $minDays)
        {
            return "Отменить можно не позже чем за {$minDays} раб. дн. до начала";
        }

        return null;
    }

    private function awaitingCancellationDecisionOf(CurrentUser $user, int $id): array
    {
        $request = $this->load($user->portal, $id);
        if ($request['status'] !== Status::CANCELLING)
        {
            throw new VacationError('Отмена уже не ждёт решения');
        }
        if ($request['approverId'] !== $user->userId)
        {
            throw new VacationError('Решение по отмене сейчас принимает другой сотрудник');
        }

        return $request;
    }

    /**
     * Автор и все, кто участвовал в заявке и её отмене (как getCancellationParticipants в коробке).
     *
     * @return list<int>
     */
    private function participants(Portal $portal, array $request): array
    {
        return array_values(array_unique(array_merge(
            [$request['userId']],
            array_column($this->history($portal, $request['id']), 'actorId'),
        )));
    }

    /**
     * Карточка: заявка, история и доступные текущему сотруднику действия.
     */
    public function card(CurrentUser $user, int $id): array
    {
        $portal = $user->portal;
        // Заявка, история и роли одним вызовом: карточку открывают по ссылке из уведомления, важна скорость
        $found = $id > 0 ? $this->storage->findMany($portal, [
            'request' => [Schema::REQUESTS, ['ID' => $id]],
            'history' => [Schema::HISTORY, ['PROPERTY_REQ_ID' => $id]],
            'roles' => [Schema::ROLES, []],
        ]) : ['request' => []];
        if ($found['request'] === [])
        {
            throw new VacationError('Заявка не найдена');
        }
        $request = self::toRequest($found['request'][0]);
        $history = array_map(self::toHistory(...), $found['history']);
        $this->roles->remember($portal, $found['roles']);
        $roles = $this->roles->roles($portal);
        $participants = array_merge([$request['userId'], $request['approverId']], array_column($history, 'actorId'));
        $visible = in_array($user->userId, $participants, true)
            || $user->userId === $roles['director']
            || in_array($user->userId, $roles['hr'], true)
            || $user->isAdmin;
        if (!$visible)
        {
            throw new VacationError('Заявка не найдена');
        }

        $review = in_array($request['status'], Status::REVIEW, true);
        $cancelling = $request['status'] === Status::CANCELLING && $request['approverId'] === $user->userId;
        // Автору утверждённой заявки: можно ли её отменить, а если нет, то почему
        $ownApproved = $request['userId'] === $user->userId && $request['status'] === Status::APPROVED;
        $cancelHint = $ownApproved ? $this->whyNotCancellable($portal, $request, $user->userId) : null;

        return [
            'request' => $request,
            'history' => $history,
            'can' => [
                'approve' => $review && $request['approverId'] === $user->userId,
                'reject' => $review && $request['approverId'] === $user->userId,
                'withdraw' => $review && $request['userId'] === $user->userId,
                'cancel' => $ownApproved && $cancelHint === null,
                'decideCancel' => $cancelling,
                'retryCalendar' => $request['status'] === Status::APPROVED && $request['calEventId'] === 0 && $request['calError'] !== ''
                    && ($request['userId'] === $user->userId || $user->isAdmin),
            ],
            'cancelHint' => (string)$cancelHint,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function mine(CurrentUser $user): array
    {
        return $this->findRequests($user->portal, ['PROPERTY_USER_ID' => $user->userId]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toApprove(CurrentUser $user): array
    {
        return array_values(array_filter(
            $this->findRequests($user->portal, ['PROPERTY_APPROVER_ID' => $user->userId]),
            static fn(array $request) => in_array($request['status'], [...Status::REVIEW, Status::CANCELLING], true),
        ));
    }

    private function awaitingDecisionOf(CurrentUser $user, int $id): array
    {
        $request = $this->load($user->portal, $id);
        if (!ApprovalRoute::canReject($request['status']))
        {
            throw new VacationError('Заявка уже не ждёт решения');
        }
        if ($request['approverId'] !== $user->userId)
        {
            throw new VacationError('Решение по заявке сейчас принимает другой сотрудник');
        }

        return $request;
    }

    private function route(Portal $portal, int $userId, int $departmentId): ApprovalRoute
    {
        $directorId = $this->roles->roles($portal)['director'];
        if ($directorId === null)
        {
            throw new VacationError('В настройках приложения не указан директор');
        }
        $headId = $this->structure->orgChart($portal)->nearestHead($userId, $departmentId) ?? $directorId;

        return new ApprovalRoute($userId === $directorId, $headId === $directorId);
    }

    private function pickApprover(Portal $portal, string $status, int $userId, int $departmentId): int
    {
        $roles = $this->roles->roles($portal);
        $approverId = match ($status)
        {
            Status::HEAD_REVIEW => $this->structure->orgChart($portal)->nearestHead($userId, $departmentId) ?? $roles['director'],
            Status::HR_REVIEW => $this->hrApprover($portal, $userId, $departmentId),
            Status::FINAL_APPROVAL => $roles['director'],
            default => null,
        };
        if (!$approverId)
        {
            throw new VacationError('Не найден согласующий: проверьте структуру компании и роли в настройках');
        }
        if ($approverId === $userId && !$this->allowSelfApproval)
        {
            // Например, директор подаёт заявку, а в ОК назначен только он сам
            throw new VacationError('Заявку некому согласовать, кроме вас самих: назначьте в отдел кадров другого сотрудника');
        }

        return $approverId;
    }

    /**
     * Кто проверяет заявку за отдел кадров. Свою заявку сотрудник ОК не проверяет: если других сотрудников ОК нет,
     * шаг достаётся его непосредственному руководителю, а без руководителя — директору.
     */
    private function hrApprover(Portal $portal, int $userId, int $departmentId): ?int
    {
        $roles = $this->roles->roles($portal);

        return $this->leastLoadedHr($portal, array_values(array_diff($roles['hr'], [$userId])))
            ?? $this->structure->orgChart($portal)->nearestHead($userId, $departmentId)
            ?? $roles['director'];
    }

    /**
     * Сотрудник ОК, у которого меньше всего заявок на проверке (так по умолчанию работает и коробка).
     *
     * @param list<int> $hr
     */
    private function leastLoadedHr(Portal $portal, array $hr): ?int
    {
        if ($hr === [])
        {
            return null;
        }
        $load = array_fill_keys($hr, 0);
        foreach ($this->findRequests($portal, ['PROPERTY_STATUS' => Status::HR_REVIEW]) as $request)
        {
            if (isset($load[$request['approverId']]))
            {
                $load[$request['approverId']]++;
            }
        }
        asort($load);

        return (int)array_key_first($load);
    }

    private function parseRange(string $from, string $to): DateRange
    {
        $pattern = '/^\d{4}-\d{2}-\d{2}$/';
        if (!preg_match($pattern, $from) || !preg_match($pattern, $to ?: $from))
        {
            throw new VacationError('Проверьте даты начала и окончания');
        }
        try
        {
            return DateRange::fromStrings($from, $to ?: $from);
        }
        catch (\Exception)
        {
            throw new VacationError('Проверьте даты начала и окончания');
        }
    }

    private function load(Portal $portal, int $id): array
    {
        $items = $id > 0 ? $this->storage->find($portal, Schema::REQUESTS, ['ID' => $id]) : [];
        if ($items === [])
        {
            throw new VacationError('Заявка не найдена');
        }

        return self::toRequest($items[0]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findRequests(Portal $portal, array $filter): array
    {
        return array_map(self::toRequest(...), $this->storage->find($portal, Schema::REQUESTS, $filter, ['ID' => 'DESC']));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function history(Portal $portal, int $requestId): array
    {
        return array_map(self::toHistory(...), $this->storage->find($portal, Schema::HISTORY, ['PROPERTY_REQ_ID' => $requestId]));
    }

    private static function toHistory(array $item): array
    {
        $values = $item['PROPERTY_VALUES'] ?? [];

        return [
            'actorId' => (int)($values['ACTOR_ID'] ?? 0),
            'action' => (string)($values['ACTION'] ?? ''),
            'statusFrom' => (string)($values['STATUS_FROM'] ?? ''),
            'statusTo' => (string)($values['STATUS_TO'] ?? ''),
            'reason' => (string)($values['REASON'] ?? ''),
            'createdAt' => (string)($item['DATE_CREATE'] ?? ''),
        ];
    }

    /**
     * Кто уже согласовал заявку: им сообщаем, если её потом отклонили или отозвали.
     *
     * @return list<int>
     */
    private function approvedBy(Portal $portal, int $requestId): array
    {
        $ids = [];
        foreach ($this->history($portal, $requestId) as $entry)
        {
            if ($entry['action'] === 'approve' || $entry['action'] === 'auto_approve')
            {
                $ids[] = $entry['actorId'];
            }
        }

        return $ids;
    }

    private function log(Portal $portal, int $requestId, int $actorId, string $action, ?string $from, string $to, string $reason = ''): void
    {
        $this->storage->add($portal, Schema::HISTORY, $action, [
            'REQ_ID' => $requestId,
            'ACTOR_ID' => $actorId,
            'ACTION' => $action,
            'STATUS_FROM' => (string)$from,
            'STATUS_TO' => $to,
            'REASON' => $reason,
        ]);
    }

    private static function toRequest(array $item): array
    {
        $values = $item['PROPERTY_VALUES'] ?? [];

        return [
            'id' => (int)$item['ID'],
            'userId' => (int)($values['USER_ID'] ?? 0),
            'deptId' => (int)($values['DEPT_ID'] ?? 0),
            'kind' => (string)($values['KIND'] ?? ''),
            'dateFrom' => (string)($values['DATE_FROM'] ?? ''),
            'dateTo' => (string)($values['DATE_TO'] ?? ''),
            'days' => (int)($values['DAYS'] ?? 0),
            'status' => (string)($values['STATUS'] ?? ''),
            'approverId' => (int)($values['APPROVER_ID'] ?? 0),
            'calEventId' => (int)($values['CAL_EVENT_ID'] ?? 0),
            'calError' => (string)($values['CAL_ERROR'] ?? ''),
            'cancelStep' => (string)($values['CANCEL_STEP'] ?? ''),
            'cancelReason' => (string)($values['CANCEL_REASON'] ?? ''),
            'createdAt' => (string)($item['DATE_CREATE'] ?? ''),
        ];
    }
}
