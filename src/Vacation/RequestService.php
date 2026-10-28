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
    public function __construct(
        private readonly EntityStorage $storage,
        private readonly StructureService $structure,
        private readonly RoleService $roles,
        private readonly WorkCalendar $calendar,
        private readonly \DateTimeImmutable $today = new \DateTimeImmutable('today'),
        private readonly bool $allowSelfApproval = false,
        private readonly ?Notifier $notifier = null,
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

        $this->storage->update($portal, Schema::REQUESTS, $id, ['STATUS' => $next, 'APPROVER_ID' => $approverId]);
        $this->log($portal, $id, $user->userId, 'approve', $request['status'], $next);
        $request = $this->load($portal, $id);
        if ($next === Status::APPROVED)
        {
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
     * Карточка: заявка, история и доступные текущему сотруднику действия.
     */
    public function card(CurrentUser $user, int $id): array
    {
        $portal = $user->portal;
        $request = $this->load($portal, $id);
        $history = $this->history($portal, $id);
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

        return [
            'request' => $request,
            'history' => $history,
            'can' => [
                'approve' => $review && $request['approverId'] === $user->userId,
                'reject' => $review && $request['approverId'] === $user->userId,
                'withdraw' => $review && $request['userId'] === $user->userId,
            ],
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
            static fn(array $request) => in_array($request['status'], Status::REVIEW, true),
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
            Status::HR_REVIEW => $this->leastLoadedHr($portal, array_values(array_diff($roles['hr'], [$userId]))) ?? $roles['director'],
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
        return array_map(static function (array $item): array {
            $values = $item['PROPERTY_VALUES'] ?? [];

            return [
                'actorId' => (int)($values['ACTOR_ID'] ?? 0),
                'action' => (string)($values['ACTION'] ?? ''),
                'statusFrom' => (string)($values['STATUS_FROM'] ?? ''),
                'statusTo' => (string)($values['STATUS_TO'] ?? ''),
                'reason' => (string)($values['REASON'] ?? ''),
                'createdAt' => (string)($item['DATE_CREATE'] ?? ''),
            ];
        }, $this->storage->find($portal, Schema::HISTORY, ['PROPERTY_REQ_ID' => $requestId]));
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
            if ($entry['action'] === 'approve')
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
            'createdAt' => (string)($item['DATE_CREATE'] ?? ''),
        ];
    }
}
