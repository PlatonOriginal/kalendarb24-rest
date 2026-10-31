<?php
declare(strict_types=1);

namespace Kb24Rest\Vacation;

use Kb24Rest\Domain\ApprovalRoute;
use Kb24Rest\Domain\DateRange;
use Kb24Rest\Domain\LimitViolation;
use Kb24Rest\Domain\RoutePlan;
use Kb24Rest\Domain\Status;
use Kb24Rest\Portal\CurrentUser;
use Kb24Rest\Portal\Portal;
use Kb24Rest\Storage\EntityStorage;
use Kb24Rest\Storage\Schema;
use Kb24Rest\Structure\StructureService;

/**
 * Заявки и согласование, как в коробке: по настроенному маршруту (вкладка «Маршруты»), а если ни один не подходит —
 * по цепочке по умолчанию: руководитель → ОК → директор.
 * Все проверки здесь, на сервере: сотрудник не может изменить заявку в обход, данные в портале пишет только сервер.
 * Пока нет заместителей.
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
        private readonly ?LimitService $limits = null,
        private readonly ?BalanceService $balances = null,
        private readonly ?RouteService $routes = null,
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
        $plan = $this->routes?->findFor($portal, $departmentId, $kind);
        $minDays = $plan !== null ? RoutePlan::minSubmitDays($plan['steps']) : ($this->settings?->minSubmitDays($portal) ?? 0);
        $exempt = !Kinds::all()[$kind]['paid'] && ($this->settings?->minSubmitExemptUnpaid($portal) ?? false);
        if (!$exempt && $range->from < $this->today->modify("+{$minDays} days"))
        {
            throw new VacationError(($plan !== null ? 'По маршруту «' . $plan['name'] . '» з' : 'З') . "аявку нужно подать не позже чем за {$minDays} дн. до начала");
        }
        foreach ($this->findRequests($portal, ['PROPERTY_USER_ID' => $user->userId]) as $existing)
        {
            if (Status::isActive($existing['status']) && $range->overlapDays($this->parseRange($existing['dateFrom'], $existing['dateTo'])) > 0)
            {
                throw new VacationError('На эти даты у вас уже есть заявка');
            }
        }
        if ($this->limits !== null && Kinds::all()[$kind]['countsInLimit'])
        {
            $violations = $this->limits->check($portal, $departmentId, $user->userId, $range);
            if ($violations !== [])
            {
                throw new VacationError(implode('. ', array_map(fn(LimitViolation $v) => $this->describeViolation($portal, $v), $violations)));
            }
        }

        $days = Kinds::all()[$kind]['deductsBalance']
            ? $this->calendar->workingDays($portal)->countVacationDays($range)
            : $range->getDays();

        if ($this->balances !== null && Kinds::all()[$kind]['deductsBalance'])
        {
            // Годовщины приёма до начала отпуска уже добавят дни к остатку
            $balance = $this->balances->get($portal, $user->userId, $range->from);
            if ($balance !== null && $days > $balance['available'] + 1e-9)
            {
                throw new VacationError(sprintf(
                    'Недостаточно дней отпуска: в заявке %d дн., к началу отпуска доступно %s дн. (остаток %s дн. на %s%s, из них %s дн. уже в других заявках)',
                    $days,
                    self::formatDays(max(0, $balance['available'])),
                    self::formatDays($balance['days']),
                    (new \DateTimeImmutable($balance['asOf']))->format('d.m.Y'),
                    $balance['accrued'] > 0 ? ', плюс ' . self::formatDays($balance['accrued']) . ' дн. за новый рабочий год' : '',
                    self::formatDays($balance['used']),
                ));
            }
        }

        $values = [
            'USER_ID' => $user->userId,
            'DEPT_ID' => $departmentId,
            'KIND' => $kind,
            'DATE_FROM' => $range->from->format('Y-m-d'),
            'DATE_TO' => $range->to->format('Y-m-d'),
            'DAYS' => $days,
        ];
        // Все шаги маршрута пропущены (например, единственный согласующий подаёт заявку сам): работает цепочка по умолчанию
        $step = $plan !== null ? $this->nextRouteStep($portal, $plan, 0, $user->userId, $departmentId, $this->allowSelfApproval ? [] : [$user->userId]) : null;
        if ($step !== null)
        {
            $id = $this->storage->add($portal, Schema::REQUESTS, $kind, $values + [
                'STATUS' => Status::ROUTE_REVIEW,
                'APPROVER_ID' => $step['approverId'],
                'ROUTE_ID' => $plan['id'],
                'STEP' => $step['index'],
                'ROUTE_DATA' => json_encode($plan, JSON_UNESCAPED_UNICODE),
            ]);
            $this->log($portal, $id, $user->userId, 'create', null, Status::ROUTE_REVIEW);
            $this->logReroute($portal, $id, $step);
            $approverId = $step['approverId'];
        }
        else
        {
            $status = $this->route($portal, $user->userId, $departmentId)->getFirstStatus();
            $approverId = $this->pickApprover($portal, $status, $user->userId, $departmentId);
            $id = $this->storage->add($portal, Schema::REQUESTS, $kind, $values + ['STATUS' => $status, 'APPROVER_ID' => $approverId]);
            $this->log($portal, $id, $user->userId, 'create', null, $status);
        }
        $request = $this->load($portal, $id);
        $this->notifier?->add($portal, 'review', $request, [$approverId], $user->userId);

        return $request;
    }

    public function approve(CurrentUser $user, int $id): array
    {
        $portal = $user->portal;
        $request = $this->awaitingDecisionOf($user, $id);
        if ($request['status'] === Status::ROUTE_REVIEW)
        {
            return $this->approveRouteStep($user, $request);
        }
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
        $blocked = $this->rejectBlocked($portal, $request);
        if ($blocked !== null)
        {
            throw new VacationError($blocked);
        }

        $this->storage->update($portal, Schema::REQUESTS, $id, ['STATUS' => Status::REJECTED, 'APPROVER_ID' => 0]);
        $this->log($portal, $id, $user->userId, 'reject', $request['status'], Status::REJECTED, $reason, self::stepNumber($request));
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
        if ($request['status'] !== Status::APPROVED || ($request['calEventId'] > 0 && $request['absenceId'] > 0))
        {
            throw new VacationError('Событие и запись в графике нужны только утверждённой заявке, у которой их ещё нет');
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

    public static function formatDays(float $days): string
    {
        return str_replace('.', ',', (string)round($days, 2));
    }

    /**
     * Сервер не знает ФИО: вместо имени коллеги пишем {user:ID}, браузер подставит имя.
     */
    private function describeViolation(Portal $portal, LimitViolation $violation): string
    {
        if ($violation->rule === LimitViolation::CONCURRENT)
        {
            $days = array_map(static fn(\DateTimeImmutable $day) => $day->format('d.m'), $violation->days);

            return 'В отделе исчерпан лимит одновременных отсутствий: ' . implode(', ', $days);
        }
        $other = $this->load($portal, (int)$violation->requestId);
        $dates = (new \DateTimeImmutable($other['dateFrom']))->format('d.m') . '–' . (new \DateTimeImmutable($other['dateTo']))->format('d.m');

        return "Пересечение с отсутствием {user:{$other['userId']}} ({$dates}) — {$violation->overlapDays} дн., больше допустимого";
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
        $plan = self::routeData($found['request'][0]);
        $history = array_map(self::toHistory(...), $found['history']);
        foreach ($history as &$entry)
        {
            $entry['stepName'] = $entry['step'] > 0 ? (string)($plan['steps'][$entry['step'] - 1]['name'] ?? '') : '';
        }
        unset($entry);
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
        $decides = $review && $request['approverId'] === $user->userId;
        $rejectBlocked = $decides ? $this->rejectBlocked($portal, $request, $plan) : null;

        return [
            'request' => $request,
            'history' => $history,
            'can' => [
                'approve' => $decides,
                'reject' => $decides && $rejectBlocked === null,
                'withdraw' => $review && $request['userId'] === $user->userId,
                'cancel' => $ownApproved && $cancelHint === null,
                'decideCancel' => $cancelling,
                // Ещё и заявки, утверждённые до записи в график: у них есть событие, но нет записи
                'retryCalendar' => $request['status'] === Status::APPROVED && ($request['calEventId'] === 0 || $request['absenceId'] === 0)
                    && ($request['calError'] !== '' || $request['calEventId'] > 0)
                    && ($request['userId'] === $user->userId || $user->isAdmin),
            ],
            'cancelHint' => (string)$cancelHint,
            'rejectBlocked' => (string)$rejectBlocked,
            'route' => $plan !== null ? $this->exportRoute($portal, $request, $plan, $history) : null,
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
        return self::toRequest($this->loadItem($portal, $id));
    }

    private function loadItem(Portal $portal, int $id): array
    {
        $items = $id > 0 ? $this->storage->find($portal, Schema::REQUESTS, ['ID' => $id]) : [];
        if ($items === [])
        {
            throw new VacationError('Заявка не найдена');
        }

        return $items[0];
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
            'step' => (int)($values['STEP'] ?? 0),
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

    /**
     * @param int $step шаг маршрута с 1, 0 — не по маршруту
     */
    private function log(Portal $portal, int $requestId, int $actorId, string $action, ?string $from, string $to, string $reason = '', int $step = 0): void
    {
        $this->storage->add($portal, Schema::HISTORY, $action, [
            'REQ_ID' => $requestId,
            'ACTOR_ID' => $actorId,
            'ACTION' => $action,
            'STATUS_FROM' => (string)$from,
            'STATUS_TO' => $to,
            'REASON' => $reason,
            'STEP' => $step,
        ]);
    }

    public static function toRequest(array $item): array
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
            'absenceId' => (int)($values['ABSENCE_ID'] ?? 0),
            'cancelStep' => (string)($values['CANCEL_STEP'] ?? ''),
            'cancelReason' => (string)($values['CANCEL_REASON'] ?? ''),
            'routeId' => (int)($values['ROUTE_ID'] ?? 0),
            'step' => (int)($values['STEP'] ?? 0),
            // Название текущего шага маршрута для статуса «На шаге «…»»
            'stepName' => ($values['STATUS'] ?? '') === Status::ROUTE_REVIEW
                ? (string)(self::routeData($item)['steps'][(int)($values['STEP'] ?? 0)]['name'] ?? '')
                : '',
            'createdAt' => (string)($item['DATE_CREATE'] ?? ''),
        ];
    }

    /**
     * Снимок маршрута, сделанный при подаче: по нему заявка идёт до конца, даже если маршрут потом изменили.
     *
     * @return array{id: int, name: string, steps: list<array<string, mixed>>}|null
     */
    private static function routeData(array $item): ?array
    {
        $data = json_decode((string)($item['PROPERTY_VALUES']['ROUTE_DATA'] ?? ''), true);

        return is_array($data) && isset($data['steps']) && is_array($data['steps']) ? $data : null;
    }

    /**
     * Согласование на шаге маршрута: следующий шаг или утверждение.
     */
    private function approveRouteStep(CurrentUser $user, array $request): array
    {
        $portal = $user->portal;
        $id = $request['id'];
        $plan = self::routeData($this->loadItem($portal, $id));
        if ($plan === null || !isset($plan['steps'][$request['step']]))
        {
            throw new VacationError('Маршрут заявки повреждён: обратитесь к администратору');
        }
        $approvedBy = [...$this->approvedBy($portal, $id), $user->userId];
        $skip = $this->allowSelfApproval ? $approvedBy : [$request['userId'], ...$approvedBy];
        $next = $this->nextRouteStep($portal, $plan, $request['step'] + 1, $request['userId'], $request['deptId'], $skip);

        if ($next === null)
        {
            $this->storage->update($portal, Schema::REQUESTS, $id, ['STATUS' => Status::APPROVED, 'APPROVER_ID' => 0]);
            $this->log($portal, $id, $user->userId, 'approve', Status::ROUTE_REVIEW, Status::APPROVED, '', self::stepNumber($request));
            $request = $this->load($portal, $id);
            $this->absence?->add($portal, $request);
            $this->notifier?->add($portal, 'approved', $request, [$request['userId']], $user->userId);

            return $request;
        }

        $this->storage->update($portal, Schema::REQUESTS, $id, ['APPROVER_ID' => $next['approverId'], 'STEP' => $next['index']]);
        $this->log($portal, $id, $user->userId, 'approve', Status::ROUTE_REVIEW, Status::ROUTE_REVIEW, '', self::stepNumber($request));
        $this->logReroute($portal, $id, $next);
        $request = $this->load($portal, $id);
        $this->notifier?->add($portal, 'review', $request, [$next['approverId']], $user->userId);
        $this->notifier?->add($portal, 'step', $request, [$request['userId']], $user->userId);

        return $request;
    }

    /**
     * Следующий шаг маршрута с согласующим, начиная с $from. Выключенные сейчас шаги пропускаются и в поданных раньше заявках.
     * Если на шаге никого не нашлось (у отдела нет руководителя, не выбран директор), заявка уходит в отдел кадров,
     * чтобы не зависнуть и не пройти без проверки.
     *
     * @param list<int> $skip автор и те, кто уже согласовал: их шаги засчитываются сами
     * @return array{index: int, approverId: int, rerouted: bool}|null null — шагов больше нет
     */
    private function nextRouteStep(Portal $portal, array $plan, int $from, int $userId, int $departmentId, array $skip): ?array
    {
        $flags = $this->routes?->activeFlags($portal, (int)$plan['id']) ?? [];
        $steps = array_map(
            static fn(array $step) => ['active' => ($step['active'] ?? true) && ($flags[$step['id'] ?? 0] ?? true)] + $step,
            $plan['steps'],
        );
        $next = RoutePlan::next($steps, $from, $skip, fn(array $step) => $this->stepApprover($portal, $step, $userId, $departmentId));
        if ($next === null)
        {
            return null;
        }
        $approverId = $next['approverId'] ?? $this->hrApprover($portal, $userId, $departmentId);
        if (!$approverId)
        {
            throw new VacationError('Не найден согласующий: проверьте структуру компании и роли в настройках');
        }

        return ['index' => $next['index'], 'approverId' => $approverId, 'rerouted' => $next['approverId'] === null];
    }

    private function stepApprover(Portal $portal, array $step, int $userId, int $departmentId): ?int
    {
        $approverId = match ($step['kind'] ?? '')
        {
            RouteService::KIND_HEAD => $this->structure->orgChart($portal)->nearestHead($userId, $departmentId),
            RouteService::KIND_HR => $this->hrApprover($portal, $userId, $departmentId),
            RouteService::KIND_DIRECTOR => $this->roles->roles($portal)['director'],
            default => (int)($step['userId'] ?? 0),
        };

        return $approverId ?: null;
    }

    /**
     * @param array{index: int, approverId: int, rerouted: bool} $step
     */
    private function logReroute(Portal $portal, int $requestId, array $step): void
    {
        if ($step['rerouted'])
        {
            $this->log($portal, $requestId, $step['approverId'], 'reroute', Status::ROUTE_REVIEW, Status::ROUTE_REVIEW, '', $step['index'] + 1);
        }
    }

    private static function stepNumber(array $request): int
    {
        return $request['status'] === Status::ROUTE_REVIEW ? $request['step'] + 1 : 0;
    }

    /**
     * Почему согласующий не может отклонить заявку на текущем шаге маршрута (шагу запрещено отклонять или срок прошёл).
     */
    private function rejectBlocked(Portal $portal, array $request, ?array $plan = null): ?string
    {
        if ($request['status'] !== Status::ROUTE_REVIEW)
        {
            return null;
        }
        $plan ??= self::routeData($this->loadItem($portal, $request['id']));
        $step = $plan['steps'][$request['step']] ?? null;

        return $step !== null
            ? RoutePlan::rejectBlocked($step + ['rejectDays' => 0, 'name' => ''], new \DateTimeImmutable($request['dateFrom']), $this->today)
            : null;
    }

    /**
     * Шаги маршрута из снимка при подаче: пройден, пропущен, текущий, впереди или на нём отказали.
     *
     * @param list<array<string, mixed>> $history
     */
    private function exportRoute(Portal $portal, array $request, array $plan, array $history): array
    {
        $byStep = [];
        foreach ($history as $entry)
        {
            if ($entry['step'] > 0 && in_array($entry['action'], ['approve', 'reject'], true))
            {
                $byStep[$entry['step'] - 1] = $entry;
            }
        }
        $flags = $this->routes?->activeFlags($portal, (int)$plan['id']) ?? [];
        $reviewing = $request['status'] === Status::ROUTE_REVIEW;
        $steps = [];
        foreach ($plan['steps'] as $index => $step)
        {
            $decided = $byStep[$index] ?? null;
            $state = match (true)
            {
                $decided !== null => $decided['action'] === 'reject' ? 'rejected' : 'done',
                $reviewing && $index === $request['step'] => 'current',
                $reviewing && $index > $request['step'] && ($flags[$step['id'] ?? 0] ?? true) => 'next',
                default => 'skipped',
            };
            $rejectDays = (int)($step['rejectDays'] ?? 0);
            $steps[] = [
                'name' => (string)($step['name'] ?? ''),
                'kind' => (string)($step['kind'] ?? ''),
                'userId' => (int)($step['userId'] ?? 0),
                'state' => $state,
                'actorId' => $decided['actorId'] ?? ($state === 'current' ? $request['approverId'] : 0),
                'rejectUntil' => $rejectDays > 0 ? (new \DateTimeImmutable($request['dateFrom']))->modify("-{$rejectDays} days")->format('Y-m-d') : '',
            ];
        }

        return ['name' => (string)($plan['name'] ?? ''), 'steps' => $steps];
    }
}
