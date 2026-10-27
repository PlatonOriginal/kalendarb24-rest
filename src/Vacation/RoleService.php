<?php
declare(strict_types=1);

namespace Kb24Rest\Vacation;

use Kb24Rest\Portal\Portal;
use Kb24Rest\Storage\EntityStorage;
use Kb24Rest\Storage\Schema;

/**
 * Роли приложения: директор (один) и сотрудники отдела кадров (несколько).
 * Хранятся в портале, в хранилище kbrole: одна запись на пару «роль, сотрудник».
 */
final class RoleService
{
    public const DIRECTOR = 'director';
    public const HR = 'hr';

    /** @var array<string, array<string, list<int>>> */
    private array $cache = [];

    public function __construct(private readonly EntityStorage $storage)
    {
    }

    /**
     * @return array{director: ?int, hr: list<int>}
     */
    public function roles(Portal $portal): array
    {
        $byRole = $this->load($portal);

        return [
            'director' => $byRole[self::DIRECTOR][0] ?? null,
            'hr' => $byRole[self::HR] ?? [],
        ];
    }

    /**
     * Заменяет состав роли целиком.
     *
     * @param list<int> $userIds
     */
    public function set(Portal $portal, string $role, array $userIds): void
    {
        if (!in_array($role, [self::DIRECTOR, self::HR], true))
        {
            throw new VacationError('Неизвестная роль');
        }
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn(int $id) => $id > 0)));
        if ($role === self::DIRECTOR && count($userIds) > 1)
        {
            throw new VacationError('Директор может быть только один');
        }

        $current = $this->storage->find($portal, Schema::ROLES, ['PROPERTY_ROLE' => $role]);
        $kept = [];
        foreach ($current as $item)
        {
            $userId = (int)($item['PROPERTY_VALUES']['USER_ID'] ?? 0);
            if (in_array($userId, $userIds, true) && !in_array($userId, $kept, true))
            {
                $kept[] = $userId;
                continue;
            }
            $this->storage->delete($portal, Schema::ROLES, (int)$item['ID']);
        }
        foreach (array_diff($userIds, $kept) as $userId)
        {
            $this->storage->add($portal, Schema::ROLES, $role, ['ROLE' => $role, 'USER_ID' => $userId]);
        }
        unset($this->cache[$portal->memberId]);
    }

    /**
     * @return array<string, list<int>>
     */
    private function load(Portal $portal): array
    {
        if (!isset($this->cache[$portal->memberId]))
        {
            $byRole = [];
            foreach ($this->storage->find($portal, Schema::ROLES) as $item)
            {
                $values = $item['PROPERTY_VALUES'] ?? [];
                $byRole[(string)($values['ROLE'] ?? '')][] = (int)($values['USER_ID'] ?? 0);
            }
            $this->cache[$portal->memberId] = $byRole;
        }

        return $this->cache[$portal->memberId];
    }
}
