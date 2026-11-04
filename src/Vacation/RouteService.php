<?php
declare(strict_types=1);

namespace Kb24Rest\Vacation;

use Kb24Rest\Domain\RoutePlan;
use Kb24Rest\Portal\CurrentUser;
use Kb24Rest\Portal\Portal;
use Kb24Rest\Storage\EntityStorage;
use Kb24Rest\Storage\Schema;
use Kb24Rest\Structure\StructureService;

/**
 * Настраиваемые маршруты согласования, как в коробке. Если ни один маршрут не подходит, работает маршрут
 * «По умолчанию» (демо: руководитель → роль «Отдел кадров» → роль «Директор»). Он настраивается так же, как остальные, только
 * действует на все виды и отделы, его нельзя выключить или удалить. Пока его не сохраняли, он не хранится в портале.
 * У маршрута есть список «Уведомить о результате»: кому сообщить об утверждении или отмене отсутствия,
 * с подтверждением ознакомления и, если нужно, задачей.
 * Маршрут — одна запись kbroute, шаги в ней одним JSON (в коробке это две таблицы). У шага постоянный id
 * внутри маршрута: по нему уже поданные заявки узнают, что шаг выключили.
 *
 * Маршруты отзыва (PURPOSE = cancel) — кто согласует отмену утверждённого отсутствия. Выбираются по тем же условиям,
 * но маршрута «По умолчанию» у них нет: если не подошёл ни один, отсутствие отменяется сразу. При первом обращении
 * создаётся демо-маршрут «руководитель → роль «Отдел кадров»», а получатели события «отменено» переезжают
 * из маршрутов согласования в маршруты отзыва с теми же условиями.
 */
final class RouteService
{
    public const KIND_HEAD = 'head';
    public const KIND_USER = 'user';
    /** Любой из участников роли вкладки «Доступ» */
    public const KIND_ROLE = 'role';
    /** Прежние шаги «Отдел кадров» и «Директор»: остаются только в снимках поданных заявок, при чтении маршрута становятся шагом «Роль» */
    public const KIND_HR = 'hr';
    public const KIND_DIRECTOR = 'director';
    public const KINDS = [self::KIND_HEAD, self::KIND_USER, self::KIND_ROLE];
    /** О чём уведомлять: отсутствие утверждено, утверждённое отсутствие отменено */
    public const EVENTS = ['approved', 'cancelled'];
    /** Задача получателю уведомления: не ставить, ставить сразу, спросить при ознакомлении */
    public const TASK_MODES = ['none', 'auto', 'ask'];
    public const MAX_WATCHERS = 10;
    public const PURPOSE_APPROVE = 'approve';
    public const PURPOSE_CANCEL = 'cancel';

    /** @var array<string, list<array<string, mixed>>> маршруты за время одного запроса, по порталу и назначению */
    private array $cache = [];

    public function __construct(
        private readonly EntityStorage $storage,
        private readonly RoleService $roles,
        private readonly StructureService $structure,
        private readonly ?Settings $settings = null,
    ) {
    }

    /**
     * Маршруты настраивают администратор и роли с правом изменять вкладку «Маршруты».
     */
    public function canEdit(CurrentUser $user): bool
    {
        return $this->roles->can($user, 'routes', RoleService::EDIT);
    }

    public function canView(CurrentUser $user): bool
    {
        return $this->roles->can($user, 'routes', RoleService::VIEW);
    }

    /**
     * Маршруты с шагами для вкладки «Маршруты».
     */
    public function list(CurrentUser $user): array
    {
        if (!$this->canView($user))
        {
            throw new VacationError('Нет доступа к вкладке «Маршруты»');
        }

        $departments = [];
        foreach ($this->structure->orgChart($user->portal)->tree() as ['department' => $department, 'depth' => $depth])
        {
            $departments[] = ['id' => $department->id, 'name' => $department->name, 'depth' => $depth];
        }

        return [
            'routes' => $this->all($user->portal),
            'cancelRoutes' => $this->cancelRoutes($user->portal),
            // Все отделы: тот, кто настраивает маршруты, видит их независимо от доступа к календарю
            'departments' => $departments,
            'roles' => array_map(static fn(array $role) => ['id' => $role['id'], 'name' => $role['name']], array_values(array_filter(
                $this->roles->all($user->portal),
                static fn(array $role) => !$role['base'],
            ))),
            'canEdit' => $this->canEdit($user),
            // Кому уходит заявка, если на шаге согласующего нет
            'fallbackRole' => $this->roles->byLegacy($user->portal, RoleService::HR)['name'] ?? '',
        ];
    }

    /**
     * Маршрут «По умолчанию» первым, за ним остальные по сортировке.
     *
     * @return list<array{id: int, isDefault: bool, name: string, active: bool, exclusive: bool, kinds: list<string>, departmentIds: list<int>, sort: int, steps: list<array<string, mixed>>, watchers: list<array<string, mixed>>, updatedBy: int, updatedAt: string}>
     */
    public function all(Portal $portal): array
    {
        $this->load($portal);

        return $this->cache[$portal->memberId][self::PURPOSE_APPROVE];
    }

    /**
     * Маршруты отзыва по сортировке; маршрута «По умолчанию» у них нет.
     *
     * @return list<array<string, mixed>>
     */
    public function cancelRoutes(Portal $portal): array
    {
        $this->load($portal);

        return $this->cache[$portal->memberId][self::PURPOSE_CANCEL];
    }

    private function load(Portal $portal): void
    {
        if (isset($this->cache[$portal->memberId]))
        {
            return;
        }
        $items = $this->storage->find($portal, Schema::ROUTES);
        if ($this->settings !== null && !$this->settings->cancelRoutesReady($portal))
        {
            $this->createCancelRoutes($portal, $items);
            $items = $this->storage->find($portal, Schema::ROUTES);
        }
        $byPurpose = [self::PURPOSE_APPROVE => [], self::PURPOSE_CANCEL => []];
        foreach ($items as $item)
        {
            $route = $this->upgrade($portal, self::toRoute($item));
            $byPurpose[$route['purpose']][] = $route;
        }
        foreach ($byPurpose as &$routes)
        {
            usort($routes, static fn(array $a, array $b) => [!$a['isDefault'], $a['sort'], $a['id']] <=> [!$b['isDefault'], $b['sort'], $b['id']]);
        }
        unset($routes);
        $approve = &$byPurpose[self::PURPOSE_APPROVE];
        if (!$approve || !$approve[0]['isDefault'])
        {
            array_unshift($approve, $this->builtInDefault($portal));
        }
        unset($approve);
        $this->cache[$portal->memberId] = $byPurpose;
    }

    /**
     * Один раз на портал: демо-маршрут отзыва «руководитель → роль «Отдел кадров»» для всех видов и отделов
     * и перенос получателей события «отменено» из маршрутов согласования в маршруты отзыва с теми же условиями.
     *
     * @param list<array<string, mixed>> $items записи kbroute
     */
    private function createCancelRoutes(Portal $portal, array $items): void
    {
        $hr = $this->roles->byLegacy($portal, RoleService::HR);
        $steps = [['id' => 1, 'name' => 'Руководитель', 'kind' => self::KIND_HEAD, 'userId' => 0, 'roleId' => 0, 'submitDays' => 0, 'rejectDays' => 0, 'active' => true]];
        if ($hr !== null)
        {
            $steps[] = ['id' => 2, 'name' => 'Отдел кадров', 'kind' => self::KIND_ROLE, 'userId' => 0, 'roleId' => $hr['id'], 'submitDays' => 0, 'rejectDays' => 0, 'active' => true];
        }
        // Демо-маршрут первым: в списке он идёт до перенесённых
        $demoId = $this->storage->add($portal, Schema::ROUTES, 'route', self::toValues([
            'purpose' => self::PURPOSE_CANCEL, 'isDefault' => false, 'name' => 'Отзыв по умолчанию', 'active' => true, 'exclusive' => false,
            'kinds' => [], 'departmentIds' => [], 'steps' => $steps, 'watchers' => [],
        ], 0));
        foreach ($items as $item)
        {
            $route = self::toRoute($item);
            if ($route['purpose'] !== self::PURPOSE_APPROVE)
            {
                continue;
            }
            $kept = [];
            $moved = [];
            foreach ($route['watchers'] as $watcher)
            {
                $events = (array)($watcher['events'] ?? []);
                if (in_array('cancelled', $events, true))
                {
                    $row = $this->upgradeRow($portal, $watcher);
                    $moved[] = [
                        'kind' => (string)($row['kind'] ?? ''),
                        'userId' => (int)($row['userId'] ?? 0),
                        'roleId' => (int)($row['roleId'] ?? 0),
                        'events' => ['cancelled'],
                        'task' => (string)($row['task'] ?? 'none'),
                        'taskDays' => (int)($row['taskDays'] ?? 0),
                    ];
                }
                $events = array_values(array_diff($events, ['cancelled']));
                if ($events)
                {
                    $kept[] = ['events' => $events] + $watcher;
                }
            }
            if (!$moved)
            {
                continue;
            }
            $this->storage->update($portal, Schema::ROUTES, $route['id'], ['WATCHERS' => json_encode($kept, JSON_UNESCAPED_UNICODE)]);
            if ($route['isDefault'])
            {
                $this->storage->update($portal, Schema::ROUTES, $demoId, ['WATCHERS' => json_encode($moved, JSON_UNESCAPED_UNICODE)]);
                continue;
            }
            $this->storage->add($portal, Schema::ROUTES, 'route', self::toValues([
                'purpose' => self::PURPOSE_CANCEL, 'isDefault' => false, 'name' => mb_substr('Отзыв: ' . $route['name'], 0, 100),
                'active' => $route['active'], 'exclusive' => $route['exclusive'], 'kinds' => $route['kinds'], 'departmentIds' => $route['departmentIds'],
                'steps' => $steps, 'watchers' => $moved,
            ], 0));
        }
        $this->settings?->setCancelRoutesReady($portal);
    }

    private static function toValues(array $route, int $userId): array
    {
        return [
            'ROUTE_NAME' => $route['name'],
            'PURPOSE' => $route['purpose'],
            'IS_DEFAULT' => $route['isDefault'] ? 'Y' : 'N',
            'ACTIVE' => $route['active'] ? 'Y' : 'N',
            'EXCLUSIVE' => $route['exclusive'] ? 'Y' : 'N',
            'KINDS' => implode(',', $route['kinds']),
            'DEPTS' => implode(',', $route['departmentIds']),
            'SORT' => 100,
            'STEPS' => json_encode($route['steps'], JSON_UNESCAPED_UNICODE),
            'WATCHERS' => json_encode($route['watchers'], JSON_UNESCAPED_UNICODE),
            'UPDATED_BY' => $userId,
            'UPDATED_AT' => $userId ? (new \DateTimeImmutable())->format('Y-m-d H:i') : '',
        ];
    }

    /**
     * Маршрут «По умолчанию», пока его не сохраняли: демо-цепочка руководитель → роль «Отдел кадров» → роль «Директор»,
     * как в коробке. Срок подачи берётся из прежней настройки min_submit_days, чтобы не потерять её.
     */
    private function builtInDefault(Portal $portal): array
    {
        $step = static fn(int $id, string $name, string $kind) => [
            'id' => $id, 'name' => $name, 'kind' => $kind, 'userId' => 0, 'roleId' => 0, 'submitDays' => 0, 'rejectDays' => 0, 'active' => true,
        ];
        $steps = [$step(1, 'Руководитель', self::KIND_HEAD), $step(2, 'Отдел кадров', self::KIND_HR), $step(3, 'Директор', self::KIND_DIRECTOR)];
        $steps[0]['submitDays'] = $this->settings?->minSubmitDays($portal) ?? 0;

        return $this->upgrade($portal, [
            'id' => 0, 'purpose' => self::PURPOSE_APPROVE, 'isDefault' => true, 'name' => 'По умолчанию', 'active' => true, 'exclusive' => false, 'kinds' => [], 'departmentIds' => [],
            'sort' => 0, 'steps' => $steps, 'watchers' => [], 'updatedBy' => 0, 'updatedAt' => '',
        ]);
    }

    /**
     * Прежние шаги и получатели «Отдел кадров» и «Директор» — это роли, перенесённые из прежних настроек.
     */
    private function upgrade(Portal $portal, array $route): array
    {
        $route['steps'] = array_map(fn(array $row) => $this->upgradeRow($portal, $row), $route['steps']);
        $route['watchers'] = array_map(fn(array $row) => $this->upgradeRow($portal, $row), $route['watchers']);

        return $route;
    }

    private function upgradeRow(Portal $portal, array $row): array
    {
        $kind = (string)($row['kind'] ?? '');
        if ($kind === self::KIND_HR || $kind === self::KIND_DIRECTOR)
        {
            $role = $this->roles->byLegacy($portal, $kind === self::KIND_HR ? RoleService::HR : RoleService::DIRECTOR);
            $row['kind'] = self::KIND_ROLE;
            $row['roleId'] = $role['id'] ?? 0;
        }
        $row['roleId'] = (int)($row['roleId'] ?? 0);

        return $row;
    }

    /**
     * Названия маршрутов, где роль указана в шагах или среди получателей результата.
     *
     * @return list<string>
     */
    public function usingRole(Portal $portal, int $roleId): array
    {
        $names = [];
        foreach ([...$this->all($portal), ...$this->cancelRoutes($portal)] as $route)
        {
            foreach ([...$route['steps'], ...$route['watchers']] as $row)
            {
                if ($row['kind'] === self::KIND_ROLE && $row['roleId'] === $roleId)
                {
                    $names[] = $route['name'];
                    break;
                }
            }
        }

        return $names;
    }

    public function defaultRoute(Portal $portal): array
    {
        return $this->all($portal)[0];
    }

    /**
     * Сохранить маршрут со всеми шагами. id = 0 — новый маршрут.
     */
    public function save(CurrentUser $user, array $data): array
    {
        $this->assertEditor($user);
        $portal = $user->portal;
        $routeId = (int)($data['id'] ?? 0);
        $cancel = ($data['purpose'] ?? '') === self::PURPOSE_CANCEL;
        $purpose = $cancel ? self::PURPOSE_CANCEL : self::PURPOSE_APPROVE;
        $isDefault = !$cancel && self::flag($data['isDefault'] ?? false);
        $existing = null;
        foreach ($cancel ? $this->cancelRoutes($portal) : $this->all($portal) as $route)
        {
            if ($isDefault ? $route['isDefault'] : $route['id'] === $routeId && !$route['isDefault'])
            {
                $existing = $route;
            }
        }
        if (!$isDefault && $routeId > 0 && $existing === null)
        {
            throw new VacationError('Маршрут не найден');
        }
        $routeId = $existing['id'] ?? 0;

        $route = [
            'id' => $routeId,
            'purpose' => $purpose,
            'isDefault' => $isDefault,
            'name' => mb_substr(trim((string)($data['name'] ?? '')), 0, 100),
            // «По умолчанию» действует всегда, на все виды и отделы
            'active' => $isDefault || self::flag($data['active'] ?? true),
            'exclusive' => !$isDefault && self::flag($data['exclusive'] ?? false),
            'kinds' => $isDefault ? [] : array_values(array_intersect(array_keys(Kinds::all()), array_map('strval', (array)($data['kinds'] ?? [])))),
            'departmentIds' => $isDefault ? [] : self::ids($data['departmentIds'] ?? []),
        ];
        if ($route['name'] === '')
        {
            throw new VacationError('Укажите название маршрута');
        }
        if ($route['exclusive'] && !$route['kinds'])
        {
            throw new VacationError('Единственным вариантом маршрут закрепляется за конкретными видами отсутствия: выберите их');
        }
        $chart = $this->structure->orgChart($portal);
        foreach ($route['departmentIds'] as $departmentId)
        {
            if ($chart->department($departmentId) === null)
            {
                throw new VacationError('Отдел не найден в структуре компании');
            }
        }

        $oldIds = array_column($existing['steps'] ?? [], 'id');
        $nextId = max([0, ...$oldIds]) + 1;
        $steps = [];
        foreach (array_values(array_filter((array)($data['steps'] ?? []), 'is_array')) as $number => $step)
        {
            $step = $this->upgradeRow($portal, $step);
            $label = 'Шаг ' . ($number + 1) . ': ';
            $kind = (string)($step['kind'] ?? '');
            $userId = (int)($step['userId'] ?? 0);
            $roleId = (int)($step['roleId'] ?? 0);
            $name = mb_substr(trim((string)($step['name'] ?? '')), 0, 100);
            // Срок отмены один для всех: настройка «за сколько рабочих дней», а не шаги маршрута отзыва
            $submitDays = $cancel ? 0 : (int)($step['submitDays'] ?? 0);
            $rejectDays = $cancel ? 0 : (int)($step['rejectDays'] ?? 0);
            if ($name === '')
            {
                throw new VacationError($label . 'укажите название шага');
            }
            if (!in_array($kind, self::KINDS, true))
            {
                throw new VacationError($label . 'выберите, кто согласует');
            }
            if ($kind === self::KIND_USER && $userId <= 0)
            {
                throw new VacationError($label . 'выберите сотрудника');
            }
            $this->assertRole($portal, $kind, $roleId, $label);
            if ($submitDays < 0 || $submitDays > 365 || $rejectDays < 0 || $rejectDays > 365)
            {
                throw new VacationError($label . 'сроки указываются в днях от 0 до 365');
            }
            $stepId = (int)($step['id'] ?? 0);
            $steps[] = [
                'id' => in_array($stepId, $oldIds, true) ? $stepId : $nextId++,
                'name' => $name,
                'kind' => $kind,
                'userId' => $kind === self::KIND_USER ? $userId : 0,
                'roleId' => $kind === self::KIND_ROLE ? $roleId : 0,
                'submitDays' => $submitDays,
                'rejectDays' => $rejectDays,
                'active' => self::flag($step['active'] ?? true),
            ];
        }
        if (!$steps || count($steps) > RoutePlan::MAX_STEPS)
        {
            throw new VacationError('В маршруте от 1 до ' . RoutePlan::MAX_STEPS . ' шагов');
        }
        $route['steps'] = $steps;
        $route['watchers'] = $this->watchers($portal, (array)($data['watchers'] ?? []), $cancel ? 'cancelled' : 'approved');

        $conflict = $this->exclusiveConflict($portal, $route);
        if ($conflict !== null)
        {
            throw new VacationError('Для этих видов отсутствия уже закреплён маршрут «' . $conflict . '»: единственный вариант может быть только один');
        }

        $values = self::toValues($route, $user->userId);
        if ($routeId > 0)
        {
            $this->storage->update($portal, Schema::ROUTES, $routeId, $values);
        }
        else
        {
            $routeId = $this->storage->add($portal, Schema::ROUTES, 'route', $values);
        }
        unset($this->cache[$portal->memberId]);

        return ['id' => $routeId];
    }

    /**
     * Поданные по маршруту заявки идут дальше по своему снимку шагов.
     */
    public function delete(CurrentUser $user, int $routeId): array
    {
        $this->assertEditor($user);
        $found = array_values(array_filter([...$this->all($user->portal), ...$this->cancelRoutes($user->portal)], static fn(array $route) => $route['id'] === $routeId && $routeId > 0));
        if (!$found)
        {
            throw new VacationError('Маршрут не найден');
        }
        if ($found[0]['isDefault'])
        {
            throw new VacationError('Маршрут «По умолчанию» удалить нельзя: он действует, когда не подошёл ни один другой');
        }
        $this->storage->delete($user->portal, Schema::ROUTES, $routeId);
        unset($this->cache[$user->portal->memberId]);

        return ['ok' => true];
    }

    /**
     * Маршрут для заявки, снимок для kbreq.ROUTE_DATA: самый точный из настроенных, иначе «По умолчанию».
     * null — у маршрута все шаги выключены.
     *
     * @return array{id: int, name: string, steps: list<array<string, mixed>>, watchers: list<array<string, mixed>>}|null
     */
    public function findFor(Portal $portal, int $departmentId, string $kind): ?array
    {
        $routes = $this->all($portal);
        $route = RoutePlan::pick(array_slice($routes, 1), $kind, $this->departmentChain($portal, $departmentId)) ?? $routes[0];
        $steps = array_values(array_filter($route['steps'], static fn(array $step) => $step['active']));

        return $steps ? ['id' => $route['id'], 'name' => $route['name'], 'steps' => $steps, 'watchers' => $route['watchers']] : null;
    }

    /**
     * Маршрут отзыва для утверждённого отсутствия, снимок для kbreq.CANCEL_DATA. null — не подошёл ни один
     * или у подошедшего выключены все шаги: тогда отсутствие отменяется сразу.
     *
     * @return array{id: int, name: string, steps: list<array<string, mixed>>, watchers: list<array<string, mixed>>}|null
     */
    public function findCancelFor(Portal $portal, int $departmentId, string $kind): ?array
    {
        $route = RoutePlan::pick($this->cancelRoutes($portal), $kind, $this->departmentChain($portal, $departmentId));
        $steps = $route !== null ? array_values(array_filter($route['steps'], static fn(array $step) => $step['active'])) : [];

        return $steps ? ['id' => $route['id'], 'name' => $route['name'], 'steps' => $steps, 'watchers' => $route['watchers']] : null;
    }

    /**
     * Включены ли сейчас шаги маршрута: выключенный после подачи шаг пропускается и в уже поданных заявках.
     *
     * @return array<int, bool> по id шага; есть только у шагов, которые ещё существуют
     */
    public function activeFlags(Portal $portal, int $routeId): array
    {
        foreach ([...$this->all($portal), ...$this->cancelRoutes($portal)] as $route)
        {
            if ($route['id'] === $routeId)
            {
                return array_column($route['steps'], 'active', 'id');
            }
        }

        return [];
    }

    /**
     * Кого уведомить о результате: роль или сотрудник, о каких событиях, задача и её срок.
     *
     * @return list<array{kind: string, userId: int, events: list<string>, task: string, taskDays: int}>
     */
    private function watchers(Portal $portal, array $rows, string $event): array
    {
        if (count($rows) > self::MAX_WATCHERS)
        {
            throw new VacationError('Уведомить о результате можно не больше ' . self::MAX_WATCHERS . ' получателей');
        }
        $watchers = [];
        foreach (array_values(array_filter($rows, 'is_array')) as $number => $row)
        {
            $row = $this->upgradeRow($portal, $row);
            $label = 'Уведомление ' . ($number + 1) . ': ';
            $kind = (string)($row['kind'] ?? '');
            $userId = (int)($row['userId'] ?? 0);
            $roleId = (int)($row['roleId'] ?? 0);
            // Об утверждении сообщают маршруты согласования, об отмене — маршруты отзыва
            $events = [$event];
            $task = (string)($row['task'] ?? 'none');
            $taskDays = (int)($row['taskDays'] ?? 0);
            if (!in_array($kind, self::KINDS, true))
            {
                throw new VacationError($label . 'выберите, кого уведомить');
            }
            if ($kind === self::KIND_USER && $userId <= 0)
            {
                throw new VacationError($label . 'выберите сотрудника');
            }
            $this->assertRole($portal, $kind, $roleId, $label);
            if (!in_array($task, self::TASK_MODES, true))
            {
                throw new VacationError($label . 'выберите, ставить ли задачу');
            }
            if ($taskDays < 0 || $taskDays > 365)
            {
                throw new VacationError($label . 'срок задачи указывается в днях от 0 до 365');
            }
            $watchers[] = [
                'kind' => $kind,
                'userId' => $kind === self::KIND_USER ? $userId : 0,
                'roleId' => $kind === self::KIND_ROLE ? $roleId : 0,
                'events' => $events,
                'task' => $task,
                'taskDays' => $task === 'none' ? 0 : $taskDays,
            ];
        }

        return $watchers;
    }

    private function assertEditor(CurrentUser $user): void
    {
        if (!$this->canEdit($user))
        {
            throw new VacationError('Маршруты настраивают администратор портала и роли с правом изменять «Маршруты» на вкладке «Доступ»');
        }
    }

    private function assertRole(Portal $portal, string $kind, int $roleId, string $label): void
    {
        if ($kind !== self::KIND_ROLE)
        {
            return;
        }
        $role = $roleId > 0 ? $this->roles->byId($portal, $roleId) : null;
        if ($role === null || $role['base'])
        {
            throw new VacationError($label . 'выберите роль');
        }
    }

    /**
     * Отдел и все его родители, от ближайшего к корню.
     *
     * @return list<int>
     */
    private function departmentChain(Portal $portal, int $departmentId): array
    {
        $chart = $this->structure->orgChart($portal);
        $chain = [];
        $department = $chart->department($departmentId);
        while ($department !== null && !in_array($department->id, $chain, true))
        {
            $chain[] = $department->id;
            $department = $department->parentId !== null ? $chart->department($department->parentId) : null;
        }

        return $chain;
    }

    private function exclusiveConflict(Portal $portal, array $route): ?string
    {
        if (!$route['exclusive'] || !$route['active'])
        {
            return null;
        }
        foreach ($route['purpose'] === self::PURPOSE_CANCEL ? $this->cancelRoutes($portal) : $this->all($portal) as $other)
        {
            if ($other['id'] === $route['id'] || !$other['exclusive'] || !$other['active'])
            {
                continue;
            }
            $sameKinds = array_intersect($other['kinds'], $route['kinds']);
            $sameDepartments = !$other['departmentIds'] || !$route['departmentIds']
                || array_intersect($other['departmentIds'], $route['departmentIds']);
            if ($sameKinds && $sameDepartments)
            {
                return $other['name'];
            }
        }

        return null;
    }

    private static function toRoute(array $item): array
    {
        $values = $item['PROPERTY_VALUES'] ?? [];
        $steps = json_decode((string)($values['STEPS'] ?? ''), true);
        $watchers = json_decode((string)($values['WATCHERS'] ?? ''), true);

        return [
            'id' => (int)$item['ID'],
            'purpose' => ($values['PURPOSE'] ?? '') === self::PURPOSE_CANCEL ? self::PURPOSE_CANCEL : self::PURPOSE_APPROVE,
            'isDefault' => ($values['IS_DEFAULT'] ?? '') === 'Y',
            'name' => (string)($values['ROUTE_NAME'] ?? ''),
            'active' => ($values['ACTIVE'] ?? '') === 'Y',
            'exclusive' => ($values['EXCLUSIVE'] ?? '') === 'Y',
            'kinds' => array_values(array_filter(explode(',', (string)($values['KINDS'] ?? '')))),
            'departmentIds' => self::ids(explode(',', (string)($values['DEPTS'] ?? ''))),
            'sort' => (int)($values['SORT'] ?? 100),
            'steps' => is_array($steps) ? array_values($steps) : [],
            'watchers' => is_array($watchers) ? array_values($watchers) : [],
            'updatedBy' => (int)($values['UPDATED_BY'] ?? 0),
            'updatedAt' => (string)($values['UPDATED_AT'] ?? ''),
        ];
    }

    /**
     * @return list<int>
     */
    private static function ids(mixed $values): array
    {
        return array_values(array_unique(array_filter(array_map('intval', (array)$values), static fn(int $id) => $id > 0)));
    }

    private static function flag(mixed $value): bool
    {
        return $value === true || $value === 'Y' || $value === 'true' || $value === '1' || $value === 1;
    }
}
