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
 * «По умолчанию» (руководитель → отдел кадров → директор). Он настраивается так же, как остальные, только
 * действует на все виды и отделы, его нельзя выключить или удалить. Пока его не сохраняли, он не хранится в портале.
 * У маршрута есть список «Уведомить о результате»: кому сообщить об утверждении или отмене отсутствия,
 * с подтверждением ознакомления и, если нужно, задачей.
 * Маршрут — одна запись kbroute, шаги в ней одним JSON (в коробке это две таблицы). У шага постоянный id
 * внутри маршрута: по нему уже поданные заявки узнают, что шаг выключили.
 */
final class RouteService
{
    public const KIND_HEAD = 'head';
    public const KIND_USER = 'user';
    public const KIND_HR = 'hr';
    public const KIND_DIRECTOR = 'director';
    public const KINDS = [self::KIND_HEAD, self::KIND_USER, self::KIND_HR, self::KIND_DIRECTOR];
    /** О чём уведомлять: отсутствие утверждено, утверждённое отсутствие отменено */
    public const EVENTS = ['approved', 'cancelled'];
    /** Задача получателю уведомления: не ставить, ставить сразу, спросить при ознакомлении */
    public const TASK_MODES = ['none', 'auto', 'ask'];
    public const MAX_WATCHERS = 10;

    /** @var array<string, list<array<string, mixed>>> маршруты за время одного запроса */
    private array $cache = [];

    public function __construct(
        private readonly EntityStorage $storage,
        private readonly RoleService $roles,
        private readonly StructureService $structure,
        private readonly ?Settings $settings = null,
    ) {
    }

    /**
     * Маршруты настраивают администратор и сотрудники из списка на вкладке «Маршруты».
     */
    public function canEdit(CurrentUser $user): bool
    {
        return $user->isAdmin || in_array($user->userId, $this->roles->roles($user->portal)['routeEditors'], true);
    }

    /**
     * Маршруты с шагами для вкладки «Маршруты».
     */
    public function list(CurrentUser $user): array
    {
        $this->assertEditor($user);

        $departments = [];
        foreach ($this->structure->orgChart($user->portal)->tree() as ['department' => $department, 'depth' => $depth])
        {
            $departments[] = ['id' => $department->id, 'name' => $department->name, 'depth' => $depth];
        }

        return [
            'routes' => $this->all($user->portal),
            // Все отделы: тот, кто настраивает маршруты, видит их независимо от доступа к календарю
            'departments' => $departments,
            'editorIds' => $this->roles->roles($user->portal)['routeEditors'],
        ];
    }

    /**
     * Маршрут «По умолчанию» первым, за ним остальные по сортировке.
     *
     * @return list<array{id: int, isDefault: bool, name: string, active: bool, exclusive: bool, kinds: list<string>, departmentIds: list<int>, sort: int, steps: list<array<string, mixed>>, watchers: list<array<string, mixed>>, updatedBy: int, updatedAt: string}>
     */
    public function all(Portal $portal): array
    {
        if (!isset($this->cache[$portal->memberId]))
        {
            $routes = array_map(self::toRoute(...), $this->storage->find($portal, Schema::ROUTES));
            usort($routes, static fn(array $a, array $b) => [!$a['isDefault'], $a['sort'], $a['id']] <=> [!$b['isDefault'], $b['sort'], $b['id']]);
            if (!$routes || !$routes[0]['isDefault'])
            {
                array_unshift($routes, $this->builtInDefault($portal));
            }
            $this->cache[$portal->memberId] = $routes;
        }

        return $this->cache[$portal->memberId];
    }

    /**
     * Маршрут «По умолчанию», пока его не сохраняли: цепочка руководитель → ОК → директор, как в коробке.
     * Срок подачи берётся из прежней настройки min_submit_days, чтобы не потерять её.
     */
    private function builtInDefault(Portal $portal): array
    {
        $step = static fn(int $id, string $name, string $kind) => [
            'id' => $id, 'name' => $name, 'kind' => $kind, 'userId' => 0, 'submitDays' => 0, 'rejectDays' => 0, 'active' => true,
        ];
        $steps = [$step(1, 'Руководитель', self::KIND_HEAD), $step(2, 'Отдел кадров', self::KIND_HR), $step(3, 'Директор', self::KIND_DIRECTOR)];
        $steps[0]['submitDays'] = $this->settings?->minSubmitDays($portal) ?? 0;

        return [
            'id' => 0, 'isDefault' => true, 'name' => 'По умолчанию', 'active' => true, 'exclusive' => false, 'kinds' => [], 'departmentIds' => [],
            'sort' => 0, 'steps' => $steps, 'watchers' => [], 'updatedBy' => 0, 'updatedAt' => '',
        ];
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
        $isDefault = self::flag($data['isDefault'] ?? false);
        $existing = null;
        foreach ($this->all($portal) as $route)
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
        foreach (array_values((array)($data['steps'] ?? [])) as $number => $step)
        {
            $label = 'Шаг ' . ($number + 1) . ': ';
            $kind = (string)($step['kind'] ?? '');
            $userId = (int)($step['userId'] ?? 0);
            $name = mb_substr(trim((string)($step['name'] ?? '')), 0, 100);
            $submitDays = (int)($step['submitDays'] ?? 0);
            $rejectDays = (int)($step['rejectDays'] ?? 0);
            if ($name === '')
            {
                throw new VacationError($label . 'укажите название роли');
            }
            if (!in_array($kind, self::KINDS, true))
            {
                throw new VacationError($label . 'выберите, кто согласует');
            }
            if ($kind === self::KIND_USER && $userId <= 0)
            {
                throw new VacationError($label . 'выберите сотрудника');
            }
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
                'submitDays' => $submitDays,
                'rejectDays' => $rejectDays,
                'active' => self::flag($step['active'] ?? true),
            ];
        }
        if (!$steps || count($steps) > RoutePlan::MAX_STEPS)
        {
            throw new VacationError('В маршруте от 1 до ' . RoutePlan::MAX_STEPS . ' шагов');
        }
        $watchers = $this->watchers((array)($data['watchers'] ?? []));

        $conflict = $this->exclusiveConflict($portal, $route);
        if ($conflict !== null)
        {
            throw new VacationError('Для этих видов отсутствия уже закреплён маршрут «' . $conflict . '»: единственный вариант может быть только один');
        }

        $values = [
            'ROUTE_NAME' => $route['name'],
            'IS_DEFAULT' => $isDefault ? 'Y' : 'N',
            'ACTIVE' => $route['active'] ? 'Y' : 'N',
            'EXCLUSIVE' => $route['exclusive'] ? 'Y' : 'N',
            'KINDS' => implode(',', $route['kinds']),
            'DEPTS' => implode(',', $route['departmentIds']),
            'SORT' => 100,
            'STEPS' => json_encode($steps, JSON_UNESCAPED_UNICODE),
            'WATCHERS' => json_encode($watchers, JSON_UNESCAPED_UNICODE),
            'UPDATED_BY' => $user->userId,
            'UPDATED_AT' => (new \DateTimeImmutable())->format('Y-m-d H:i'),
        ];
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
        $found = array_values(array_filter($this->all($user->portal), static fn(array $route) => $route['id'] === $routeId && $routeId > 0));
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
     * @param list<int> $userIds
     */
    public function saveEditors(CurrentUser $user, array $userIds): array
    {
        $this->assertEditor($user);
        $ids = self::ids($userIds);
        // Без администратора нельзя исключить себя: иначе маршруты станет некому править
        if (!$user->isAdmin && !in_array($user->userId, $ids, true))
        {
            throw new VacationError('Себя из списка может убрать только администратор портала');
        }
        $this->roles->set($user->portal, RoleService::ROUTE_EDITOR, $ids);

        return ['editorIds' => $this->roles->roles($user->portal)['routeEditors']];
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
     * Включены ли сейчас шаги маршрута: выключенный после подачи шаг пропускается и в уже поданных заявках.
     *
     * @return array<int, bool> по id шага; есть только у шагов, которые ещё существуют
     */
    public function activeFlags(Portal $portal, int $routeId): array
    {
        foreach ($this->all($portal) as $route)
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
    private function watchers(array $rows): array
    {
        if (count($rows) > self::MAX_WATCHERS)
        {
            throw new VacationError('Уведомить о результате можно не больше ' . self::MAX_WATCHERS . ' получателей');
        }
        $watchers = [];
        foreach (array_values($rows) as $number => $row)
        {
            $label = 'Уведомление ' . ($number + 1) . ': ';
            $kind = (string)($row['kind'] ?? '');
            $userId = (int)($row['userId'] ?? 0);
            $events = array_values(array_intersect(self::EVENTS, array_map('strval', (array)($row['events'] ?? []))));
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
            if (!$events)
            {
                throw new VacationError($label . 'выберите, о чём уведомлять');
            }
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
            throw new VacationError('Маршруты настраивают администратор и сотрудники из списка на вкладке «Маршруты»');
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
        foreach ($this->all($portal) as $other)
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
