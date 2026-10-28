<?php
declare(strict_types=1);

namespace Kb24Rest\Storage;

use Kb24Rest\Bitrix24\RestClient;
use Kb24Rest\Bitrix24\RestException;
use Kb24Rest\Portal\Portal;

/**
 * Данные приложения в entity.* портала. Пишет только сервер токеном приложения.
 * Права в entity.add не передаём: тогда управление (X) есть только у того, кто создал хранилище,
 * то есть у администратора, установившего приложение. Остальные сотрудники напрямую данные не видят.
 * https://apidocs.bitrix24.com/api-reference/entity/entities/entity-add.html
 */
final class EntityStorage
{
    public function __construct(private readonly RestClient $rest)
    {
    }

    /**
     * Создаёт недостающие хранилища и свойства. Повторный вызов ничего не меняет.
     *
     * @return array{createdStorages: list<string>, addedProperties: list<string>}
     */
    public function ensure(Portal $portal): array
    {
        $report = ['createdStorages' => [], 'addedProperties' => []];
        $existing = array_column((array)$this->rest->call($portal, 'entity.get'), 'ENTITY');
        foreach (Schema::storages() as $entity => $storage)
        {
            if (!in_array($entity, $existing, true))
            {
                $this->rest->call($portal, 'entity.add', ['ENTITY' => $entity, 'NAME' => $storage['name']]);
                $report['createdStorages'][] = $entity;
                $properties = [];
            }
            else
            {
                $properties = array_column((array)$this->rest->call($portal, 'entity.item.property.get', ['ENTITY' => $entity]), 'PROPERTY');
            }

            foreach ($storage['properties'] as $code => $property)
            {
                if (in_array($code, $properties, true))
                {
                    continue;
                }
                $this->rest->call($portal, 'entity.item.property.add', [
                    'ENTITY' => $entity,
                    'PROPERTY' => $code,
                    'NAME' => $property['name'],
                    'TYPE' => $property['type'],
                ]);
                $report['addedProperties'][] = $entity . '.' . $code;
            }
        }

        return $report;
    }

    /**
     * @return array<string, string> код доступа => уровень (R, W, X)
     */
    public function rights(Portal $portal, string $entity): array
    {
        return (array)$this->rest->call($portal, 'entity.rights', ['ENTITY' => $entity]);
    }

    /**
     * @param array<string, scalar> $properties
     */
    public function add(Portal $portal, string $entity, string $name, array $properties): int
    {
        return (int)$this->rest->call($portal, 'entity.item.add', [
            'ENTITY' => $entity,
            'NAME' => $name,
            'PROPERTY_VALUES' => $properties,
        ]);
    }

    /**
     * @param array<string, mixed> $filter свойства с префиксом PROPERTY_, например ['PROPERTY_USER_ID' => 7]
     * @return list<array<string, mixed>>
     */
    public function find(Portal $portal, string $entity, array $filter = [], array $sort = ['ID' => 'ASC']): array
    {
        return $this->rest->listAll($portal, 'entity.item.get', ['ENTITY' => $entity, 'SORT' => $sort, 'FILTER' => $filter]);
    }

    /**
     * Несколько выборок одним вызовом batch. Только первая страница (50 записей) каждой выборки:
     * для заявки по ID, её истории и ролей этого хватает.
     *
     * @param array<string, array{0: string, 1: array<string, mixed>}> $queries ключ => [хранилище, фильтр]
     * @return array<string, list<array<string, mixed>>>
     */
    public function findMany(Portal $portal, array $queries): array
    {
        $commands = [];
        foreach ($queries as $key => [$entity, $filter])
        {
            $commands[$key] = 'entity.item.get?' . http_build_query(['ENTITY' => $entity, 'SORT' => ['ID' => 'ASC'], 'FILTER' => $filter]);
        }
        $batch = (array)$this->rest->call($portal, 'batch', ['halt' => 1, 'cmd' => $commands]);
        if (!empty($batch['result_error']))
        {
            $error = reset($batch['result_error']);
            throw new RestException((string)($error['error'] ?? 'batch_error'), (string)($error['error_description'] ?? ''));
        }

        $found = [];
        foreach (array_keys($queries) as $key)
        {
            $found[$key] = array_values((array)($batch['result'][$key] ?? []));
        }

        return $found;
    }

    /**
     * @param array<string, scalar> $properties
     */
    public function update(Portal $portal, string $entity, int $id, array $properties): void
    {
        $this->rest->call($portal, 'entity.item.update', ['ENTITY' => $entity, 'ID' => $id, 'PROPERTY_VALUES' => $properties]);
    }

    public function delete(Portal $portal, string $entity, int $id): void
    {
        $this->rest->call($portal, 'entity.item.delete', ['ENTITY' => $entity, 'ID' => $id]);
    }
}
