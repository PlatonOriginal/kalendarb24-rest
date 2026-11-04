<?php
declare(strict_types=1);

namespace Kb24Rest\Vacation;

use Kb24Rest\Portal\CurrentUser;
use Kb24Rest\Portal\Portal;
use Kb24Rest\Storage\EntityStorage;
use Kb24Rest\Storage\Schema;
use Kb24Rest\Structure\StructureService;

/**
 * Роли вкладки «Доступ». Роль открывает вкладки приложения и задаёт, заявки каких отделов видит сотрудник.
 * Что сотрудник может сделать с заявкой, определяет его участие в ней (автор, согласующий, получатель результата),
 * а не роль. Названия ролей произвольные; в роль входят сотрудники и отделы (с подотделами).
 * Роль «Сотрудник» есть у всех. Если сотрудник в нескольких ролях, по каждому праву берётся более широкое.
 * Администратор портала может всё.
 *
 * Хранятся в портале, в kbaccess. Прежние роли из kbrole (директор, отдел кадров, кто настраивает маршруты)
 * при первом обращении переносятся в обычные роли с такими же названиями: это демо-данные для маршрута «По умолчанию».
 * Код LEGACY у них нужен заявкам и отменам, поданным до переноса и до маршрутов отзыва.
 */
final class RoleService
{
    public const DIRECTOR = 'director';
    public const HR = 'hr';
    public const ROUTE_EDITOR = 'route_editor';
    public const BASE = 'base';

    public const NONE = 'none';
    public const VIEW = 'view';
    public const EDIT = 'edit';
    private const LEVELS = [self::NONE => 0, self::VIEW => 1, self::EDIT => 2];

    /**
     * Вкладки, доступ к которым задаётся в ролях, и уровни, которые для них имеют смысл.
     * «Мои заявки», «На согласовании» и «На ознакомлении» открыты всем: там только заявки, в которых сотрудник участвует.
     * «Проверки» только администратору портала: они создают хранилища.
     */
    public const TABS = [
        'calendar' => ['name' => 'Календарь', 'levels' => [self::NONE, self::VIEW]],
        'dashboard' => ['name' => 'Дашборд', 'levels' => [self::NONE, self::VIEW]],
        'dashboardSpeed' => ['name' => 'Дашборд: скорость согласования', 'levels' => [self::NONE, self::VIEW]],
        'balances' => ['name' => 'Остатки', 'levels' => [self::NONE, self::VIEW, self::EDIT]],
        'routes' => ['name' => 'Маршруты', 'levels' => [self::NONE, self::VIEW, self::EDIT]],
        'settings' => ['name' => 'Настройки', 'levels' => [self::NONE, self::VIEW, self::EDIT]],
        'access' => ['name' => 'Доступ', 'levels' => [self::NONE, self::VIEW, self::EDIT]],
        // Не вкладка, а право в форме заявки: поле «Сотрудник» с выбором из отделов, видных по роли
        'onBehalf' => [
            'name' => 'Подача заявок за сотрудников',
            'levels' => [self::NONE, self::EDIT],
            'labels' => [self::NONE => 'Нет', self::EDIT => 'Да'],
            'hint' => 'Например, внести больничный за заболевшего сотрудника или зафиксировать его отгул. Сотрудник выбирается из отделов, которые видны по роли.',
        ],
    ];

    /** Чьи заявки видны, кроме своих и тех, где сотрудник участвует, от узкого к широкому */
    public const SCOPES = [
        'dept' => 'Свой отдел',
        'sub' => 'Свой отдел и подотделы',
        'all' => 'Вся компания',
    ];

    /** @var array<string, list<array<string, mixed>>> роли по member_id за время одного запроса */
    private array $cache = [];

    /** @var array<string, array<string, mixed>> права по member_id и сотруднику */
    private array $permissions = [];

    /** @var array<string, list<int>> сотрудники роли */
    private array $members = [];

    public function __construct(
        private readonly EntityStorage $storage,
        private readonly ?StructureService $structure = null,
        private readonly ?Settings $settings = null,
    ) {
    }

    /**
     * Все роли: «Сотрудник» первой, остальные по названию.
     *
     * @return list<array{id: int, name: string, legacy: string, base: bool, userIds: list<int>, departmentIds: list<int>, tabs: array<string, string>, scope: string}>
     */
    public function all(Portal $portal): array
    {
        if (!isset($this->cache[$portal->memberId]))
        {
            $this->remember($portal, $this->storage->find($portal, Schema::ACCESS));
        }

        return $this->cache[$portal->memberId];
    }

    /**
     * Записи kbaccess, уже прочитанные вместе с другими данными (EntityStorage::findMany): второй раз не запрашиваем.
     *
     * @param list<array<string, mixed>> $items
     */
    public function remember(Portal $portal, array $items): void
    {
        if ($items === [])
        {
            $items = $this->migrate($portal);
        }
        $roles = array_map(self::toRole(...), $items);
        usort($roles, static fn(array $a, array $b) => [!$a['base'], mb_strtolower($a['name']), $a['id']] <=> [!$b['base'], mb_strtolower($b['name']), $b['id']]);
        $this->cache[$portal->memberId] = $roles;
    }

    public function byId(Portal $portal, int $roleId): ?array
    {
        foreach ($this->all($portal) as $role)
        {
            if ($role['id'] === $roleId)
            {
                return $role;
            }
        }

        return null;
    }

    public function byLegacy(Portal $portal, string $code): ?array
    {
        foreach ($this->all($portal) as $role)
        {
            if ($role['legacy'] === $code)
            {
                return $role;
            }
        }

        return null;
    }

    /**
     * Сотрудники роли: выбранные поимённо и все из выбранных отделов с подотделами. У «Сотрудника» список не ведётся.
     *
     * @return list<int>
     */
    public function members(Portal $portal, array $role): array
    {
        $key = $portal->memberId . ':' . $role['id'];
        if (!isset($this->members[$key]))
        {
            $ids = $role['userIds'];
            if ($role['departmentIds'] !== [] && $this->structure !== null)
            {
                $chart = $this->structure->orgChart($portal);
                $departments = [];
                foreach ($role['departmentIds'] as $departmentId)
                {
                    $departments = array_merge($departments, $chart->withChildren($departmentId));
                }
                $ids = array_merge($ids, $this->structure->usersIn($portal, array_values(array_unique($departments))));
            }
            $this->members[$key] = array_values(array_unique($ids));
        }

        return $this->members[$key];
    }

    /**
     * Для прежнего кода: директор и отдел кадров — участники ролей, перенесённых из прежних настроек.
     * Нужны отмене заявок и маршрутам, поданным до появления шага «Роль».
     *
     * @return array{director: ?int, hr: list<int>}
     */
    public function roles(Portal $portal): array
    {
        $director = $this->byLegacy($portal, self::DIRECTOR);
        $hr = $this->byLegacy($portal, self::HR);

        return [
            'director' => $director !== null ? ($this->members($portal, $director)[0] ?? null) : null,
            'hr' => $hr !== null ? $this->members($portal, $hr) : [],
        ];
    }

    /**
     * Роли сотрудника, включая «Сотрудника».
     *
     * @return list<array<string, mixed>>
     */
    public function rolesOf(CurrentUser $user): array
    {
        $portal = $user->portal;
        $result = [];
        foreach ($this->all($portal) as $role)
        {
            if ($role['base'] || in_array($user->userId, $this->members($portal, $role), true))
            {
                $result[] = $role;
            }
        }

        return $result;
    }

    /**
     * Итоговые права: по каждой вкладке наибольший уровень из ролей сотрудника, видимость — самая широкая.
     *
     * @return array{tabs: array<string, string>, scope: string, roleIds: list<int>}
     */
    public function permissions(CurrentUser $user): array
    {
        $key = $user->portal->memberId . ':' . $user->userId . ($user->isAdmin ? ':admin' : '');
        if (!isset($this->permissions[$key]))
        {
            $this->permissions[$key] = self::combine($this->rolesOf($user), $user->isAdmin);
        }

        return $this->permissions[$key];
    }

    public function can(CurrentUser $user, string $tab, string $level = self::VIEW): bool
    {
        return self::LEVELS[$this->permissions($user)['tabs'][$tab] ?? self::NONE] >= self::LEVELS[$level];
    }

    public function scope(CurrentUser $user): string
    {
        return $this->permissions($user)['scope'];
    }

    /**
     * Отделы, заявки которых видны сотруднику (кроме своих и тех, где он участвует). null — все отделы.
     * Руководитель всегда видит отделы, которыми руководит, с подотделами.
     *
     * @return list<int>|null
     */
    public function visibleDepartments(CurrentUser $user): ?array
    {
        $scope = $this->scope($user);
        if ($scope === 'all' || $this->structure === null)
        {
            return $scope === 'all' ? null : [];
        }
        $portal = $user->portal;
        $chart = $this->structure->orgChart($portal);
        $ids = [];
        foreach ($this->structure->departmentsOf($portal, $user->userId) as $departmentId)
        {
            $ids = array_merge($ids, $scope === 'sub' ? $chart->withChildren($departmentId) : [$departmentId]);
        }
        foreach ($chart->headedBy($user->userId) as $departmentId)
        {
            $ids = array_merge($ids, $chart->withChildren($departmentId));
        }

        return array_values(array_unique($ids));
    }

    /**
     * Роли для вкладки «Доступ».
     */
    public function list(CurrentUser $user): array
    {
        $this->assert($user, self::VIEW);
        $portal = $user->portal;
        $departments = [];
        if ($this->structure !== null)
        {
            foreach ($this->structure->orgChart($portal)->tree() as ['department' => $department, 'depth' => $depth])
            {
                $departments[] = ['id' => $department->id, 'name' => $department->name, 'depth' => $depth];
            }
        }

        return [
            'roles' => $this->all($portal),
            'tabs' => self::TABS,
            'scopes' => self::SCOPES,
            'departments' => $departments,
            'canEdit' => $this->can($user, 'access', self::EDIT),
        ];
    }

    /**
     * Сохранить роль. id = 0 — новая.
     *
     * @param array<string, mixed> $data
     */
    public function save(CurrentUser $user, array $data): array
    {
        $this->assert($user, self::EDIT);
        $portal = $user->portal;
        $roleId = (int)($data['id'] ?? 0);
        $existing = $roleId > 0 ? $this->byId($portal, $roleId) : null;
        if ($roleId > 0 && $existing === null)
        {
            throw new VacationError('Роль не найдена');
        }
        $base = $existing['base'] ?? false;
        $name = mb_substr(trim((string)($data['name'] ?? '')), 0, 100);
        if ($name === '')
        {
            throw new VacationError('Укажите название роли');
        }
        foreach ($this->all($portal) as $role)
        {
            if ($role['id'] !== $roleId && mb_strtolower($role['name']) === mb_strtolower($name))
            {
                throw new VacationError('Роль «' . $role['name'] . '» уже есть: выберите другое название');
            }
        }
        $tabs = [];
        foreach (self::TABS as $tab => $meta)
        {
            $level = (string)(((array)($data['tabs'] ?? []))[$tab] ?? self::NONE);
            if (!in_array($level, $meta['levels'], true))
            {
                throw new VacationError('Вкладка «' . $meta['name'] . '»: выберите уровень доступа');
            }
            $tabs[$tab] = $level;
        }
        $scope = (string)($data['scope'] ?? 'dept');
        if (!isset(self::SCOPES[$scope]))
        {
            throw new VacationError('Выберите, заявки каких отделов видны');
        }
        // В «Сотрудника» входят все, поэтому состав у неё не задаётся
        $userIds = $base ? [] : self::ids($data['userIds'] ?? []);
        $departmentIds = $base ? [] : self::ids($data['departmentIds'] ?? []);
        if ($departmentIds !== [] && $this->structure !== null)
        {
            $chart = $this->structure->orgChart($portal);
            foreach ($departmentIds as $departmentId)
            {
                if ($chart->department($departmentId) === null)
                {
                    throw new VacationError('Отдел не найден в структуре компании');
                }
            }
        }
        $role = [
            'id' => $roleId,
            'name' => $name,
            'legacy' => $existing['legacy'] ?? '',
            'base' => $base,
            'userIds' => $userIds,
            'departmentIds' => $departmentIds,
            'tabs' => $tabs,
            'scope' => $scope,
        ];
        $this->assertKeepsAccess($user, $role);

        $values = self::toValues($role);
        if ($roleId > 0)
        {
            $this->storage->update($portal, Schema::ACCESS, $roleId, $values);
        }
        else
        {
            $roleId = $this->storage->add($portal, Schema::ACCESS, 'role', $values);
        }
        $this->forget($portal);

        return ['id' => $roleId, 'roles' => $this->all($portal)];
    }

    /**
     * @param list<string> $routes названия маршрутов, где роль указана в шагах или получателях результата
     */
    public function delete(CurrentUser $user, int $roleId, array $routes = []): array
    {
        $this->assert($user, self::EDIT);
        $portal = $user->portal;
        $role = $this->byId($portal, $roleId);
        if ($role === null)
        {
            throw new VacationError('Роль не найдена');
        }
        if ($role['base'])
        {
            throw new VacationError('Роль «' . $role['name'] . '» есть у всех сотрудников, её нельзя удалить');
        }
        if ($routes !== [])
        {
            throw new VacationError('Роль «' . $role['name'] . '» указана в маршрутах: ' . implode(', ', array_map(static fn(string $n) => '«' . $n . '»', $routes)) . '. Сначала уберите её оттуда');
        }
        $this->assertKeepsAccess($user, null, $roleId);
        $this->storage->delete($portal, Schema::ACCESS, $roleId);
        $this->forget($portal);

        return ['roles' => $this->all($portal)];
    }

    /**
     * Для прежних тестов и переноса: заменить состав роли с кодом LEGACY целиком.
     *
     * @param list<int> $userIds
     */
    public function set(Portal $portal, string $code, array $userIds): void
    {
        if (!in_array($code, [self::DIRECTOR, self::HR, self::ROUTE_EDITOR], true))
        {
            throw new VacationError('Неизвестная роль');
        }
        $userIds = self::ids($userIds);
        if ($code === self::DIRECTOR && count($userIds) > 1)
        {
            throw new VacationError('Директор может быть только один');
        }
        $role = $this->byLegacy($portal, $code);
        if ($role === null)
        {
            $role = self::legacyRole($code, $userIds);
            $this->storage->add($portal, Schema::ACCESS, 'role', self::toValues($role));
        }
        else
        {
            $this->storage->update($portal, Schema::ACCESS, $role['id'], ['USERS' => implode(',', $userIds)]);
        }
        $this->forget($portal);
    }

    /**
     * Права из набора ролей.
     *
     * @param list<array<string, mixed>> $roles
     * @return array{tabs: array<string, string>, scope: string, roleIds: list<int>}
     */
    public static function combine(array $roles, bool $isAdmin): array
    {
        $tabs = array_fill_keys(array_keys(self::TABS), $isAdmin ? self::EDIT : self::NONE);
        $scopes = array_keys(self::SCOPES);
        $scope = $isAdmin ? 'all' : 'dept';
        foreach ($roles as $role)
        {
            foreach (self::TABS as $tab => $meta)
            {
                $level = (string)($role['tabs'][$tab] ?? self::NONE);
                if (in_array($level, $meta['levels'], true) && self::LEVELS[$level] > self::LEVELS[$tabs[$tab]])
                {
                    $tabs[$tab] = $level;
                }
            }
            if (array_search($role['scope'], $scopes, true) > array_search($scope, $scopes, true))
            {
                $scope = $role['scope'];
            }
        }

        return ['tabs' => $tabs, 'scope' => $scope, 'roleIds' => array_column($roles, 'id')];
    }

    private function assert(CurrentUser $user, string $level): void
    {
        if (!$this->can($user, 'access', $level))
        {
            throw new VacationError($level === self::EDIT ? 'Роли настраивают администратор портала и роли с правом изменять «Доступ»' : 'Нет доступа к вкладке «Доступ»');
        }
    }

    /**
     * Кроме администратора портала, никто не может лишить себя права менять роли: иначе их станет некому настраивать.
     */
    private function assertKeepsAccess(CurrentUser $user, ?array $changed, int $deletedId = 0): void
    {
        if ($user->isAdmin)
        {
            return;
        }
        $portal = $user->portal;
        $roles = [];
        foreach ($this->all($portal) as $role)
        {
            if ($role['id'] === $deletedId || ($changed !== null && $role['id'] === $changed['id']))
            {
                continue;
            }
            if ($role['base'] || in_array($user->userId, $this->members($portal, $role), true))
            {
                $roles[] = $role;
            }
        }
        if ($changed !== null)
        {
            $changedMembers = $changed['userIds'];
            if ($changed['departmentIds'] !== [] && $this->structure !== null)
            {
                $chart = $this->structure->orgChart($portal);
                $departments = [];
                foreach ($changed['departmentIds'] as $departmentId)
                {
                    $departments = array_merge($departments, $chart->withChildren($departmentId));
                }
                $changedMembers = array_merge($changedMembers, $this->structure->usersIn($portal, array_values(array_unique($departments))));
            }
            if ($changed['base'] || in_array($user->userId, $changedMembers, true))
            {
                $roles[] = $changed;
            }
        }
        if (self::combine($roles, false)['tabs']['access'] !== self::EDIT)
        {
            throw new VacationError('Так вы лишитесь права настраивать роли. Это может сделать только администратор портала');
        }
    }

    private function forget(Portal $portal): void
    {
        unset($this->cache[$portal->memberId]);
        $prefix = $portal->memberId . ':';
        foreach ([&$this->permissions, &$this->members] as &$map)
        {
            foreach (array_keys($map) as $key)
            {
                if (str_starts_with((string)$key, $prefix))
                {
                    unset($map[$key]);
                }
            }
        }
    }

    /**
     * Первый запуск после обновления: роли из прежних настроек становятся обычными ролями.
     * «Видеть календари других отделов» из прежних настроек переходит в видимость роли «Сотрудник».
     *
     * @return list<array<string, mixed>> созданные записи kbaccess
     */
    private function migrate(Portal $portal): array
    {
        $old = [];
        foreach ($this->storage->find($portal, Schema::ROLES) as $item)
        {
            $values = $item['PROPERTY_VALUES'] ?? [];
            $old[(string)($values['ROLE'] ?? '')][] = (int)($values['USER_ID'] ?? 0);
        }
        $base = self::legacyRole(self::BASE, []);
        if ($this->settings?->employeesSeeOtherDepartments($portal))
        {
            $base['scope'] = 'all';
        }
        $roles = [$base, self::legacyRole(self::DIRECTOR, $old[self::DIRECTOR] ?? []), self::legacyRole(self::HR, $old[self::HR] ?? [])];
        if (!empty($old[self::ROUTE_EDITOR]))
        {
            $roles[] = self::legacyRole(self::ROUTE_EDITOR, $old[self::ROUTE_EDITOR]);
        }
        $items = [];
        foreach ($roles as $role)
        {
            $values = self::toValues($role);
            $id = $this->storage->add($portal, Schema::ACCESS, 'role', $values);
            $items[] = ['ID' => (string)$id, 'PROPERTY_VALUES' => array_map('strval', $values)];
        }

        return $items;
    }

    /**
     * Демо-роли: такие же права, какие эти роли имели до вкладки «Доступ».
     */
    private static function legacyRole(string $code, array $userIds): array
    {
        $tabs = array_fill_keys(array_keys(self::TABS), self::NONE);
        $tabs['calendar'] = self::VIEW;
        [$name, $scope] = match ($code)
        {
            self::BASE => ['Сотрудник', 'dept'],
            self::DIRECTOR => ['Директор', 'all'],
            self::HR => ['Отдел кадров', 'all'],
            self::ROUTE_EDITOR => ['Настройка маршрутов', 'dept'],
        };
        // Дашборд как в коробке по умолчанию: директор и отдел кадров, скорость согласования только директор
        if ($code === self::DIRECTOR)
        {
            $tabs['balances'] = self::VIEW;
            $tabs['dashboard'] = self::VIEW;
            $tabs['dashboardSpeed'] = self::VIEW;
        }
        if ($code === self::HR)
        {
            $tabs['balances'] = self::EDIT;
            $tabs['dashboard'] = self::VIEW;
            $tabs['onBehalf'] = self::EDIT;
        }
        if ($code === self::ROUTE_EDITOR)
        {
            $tabs['routes'] = self::EDIT;
        }

        return [
            'id' => 0, 'name' => $name, 'legacy' => $code, 'base' => $code === self::BASE,
            'userIds' => self::ids($userIds), 'departmentIds' => [], 'tabs' => $tabs, 'scope' => $scope,
        ];
    }

    private static function toValues(array $role): array
    {
        return [
            'ROLE_NAME' => $role['name'],
            'LEGACY' => $role['legacy'],
            'USERS' => implode(',', $role['userIds']),
            'DEPTS' => implode(',', $role['departmentIds']),
            'TABS' => json_encode($role['tabs'], JSON_UNESCAPED_UNICODE),
            'SCOPE' => $role['scope'],
        ];
    }

    private static function toRole(array $item): array
    {
        $values = $item['PROPERTY_VALUES'] ?? [];
        $legacy = (string)($values['LEGACY'] ?? '');
        $stored = json_decode((string)($values['TABS'] ?? ''), true);
        // Вкладки, появившиеся после сохранения роли: у перенесённых ролей — как по умолчанию, у остальных скрыты
        $defaults = in_array($legacy, [self::BASE, self::DIRECTOR, self::HR, self::ROUTE_EDITOR], true) ? self::legacyRole($legacy, [])['tabs'] : [];
        $tabs = [];
        foreach (self::TABS as $tab => $meta)
        {
            $level = (string)(is_array($stored) ? ($stored[$tab] ?? $defaults[$tab] ?? self::NONE) : self::NONE);
            $tabs[$tab] = in_array($level, $meta['levels'], true) ? $level : self::NONE;
        }
        $scope = (string)($values['SCOPE'] ?? 'dept');

        return [
            'id' => (int)$item['ID'],
            'name' => (string)($values['ROLE_NAME'] ?? ''),
            'legacy' => $legacy,
            'base' => $legacy === self::BASE,
            'userIds' => self::ids(explode(',', (string)($values['USERS'] ?? ''))),
            'departmentIds' => self::ids(explode(',', (string)($values['DEPTS'] ?? ''))),
            'tabs' => $tabs,
            'scope' => isset(self::SCOPES[$scope]) ? $scope : 'dept',
        ];
    }

    /**
     * @return list<int>
     */
    private static function ids(mixed $values): array
    {
        return array_values(array_unique(array_filter(array_map('intval', (array)$values), static fn(int $id) => $id > 0)));
    }
}
