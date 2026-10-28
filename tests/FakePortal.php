<?php
declare(strict_types=1);

namespace Kb24Rest\Tests;

use Kb24Rest\Bitrix24\HttpTransport;

/**
 * Портал в памяти для тестов сервисов: хранилища entity.*, структура и календарь.
 * Фильтр entity.item.get понимает только равенство по ID и PROPERTY_<код>, этого хватает сервисам.
 */
final class FakePortal implements HttpTransport
{
    /** @var array<string, array<int, array<string, mixed>>> */
    public array $items = [];

    /** @var list<string> вызванные методы */
    public array $calls = [];

    /** @var list<array<string, mixed>> параметры уведомлений, пришедших в batch */
    public array $notifications = [];

    /** @var list<array<string, mixed>> созданные события календаря */
    public array $events = [];

    /** Ошибка, которой портал ответит на calendar.event.add (например, нет прав на чужой календарь) */
    public ?array $calendarError = null;

    /** @var list<array<string, mixed>> обработчики встроек (placement.*) */
    public array $placements = [];

    private int $nextId = 1;

    /**
     * @param list<array<string, mixed>> $departments ответ department.get
     * @param array<int, list<int>> $userDepartments ID сотрудника => UF_DEPARTMENT
     */
    public function __construct(
        private readonly array $departments = [],
        private readonly array $userDepartments = [],
        private readonly array $calendarSettings = ['week_holidays' => ['SA', 'SU'], 'year_holidays' => '1.01,2.01,7.01,23.02,8.03,1.05,9.05,12.06,4.11', 'year_workdays' => ''],
    ) {
    }

    public function post(string $url, array $params): array
    {
        $method = (string)preg_replace('~^.*/rest/(.+)\.json$~', '$1', $url);
        $this->calls[] = $method;

        return ['status' => 200, 'body' => $this->handle($method, $params)];
    }

    public function postJson(string $url, array $params): array
    {
        throw new \LogicException('REST 3.0 в этом тесте не нужен');
    }

    public function get(string $url, array $query): array
    {
        throw new \LogicException('OAuth в этом тесте не нужен');
    }

    private function handle(string $method, array $params): array
    {
        $entity = (string)($params['ENTITY'] ?? '');

        return match ($method)
        {
            'department.get' => ['result' => $this->departments],
            'user.get' => ['result' => array_map(fn($id) => [
                'ID' => (string)$id,
                'NAME' => 'Имя' . $id,
                'LAST_NAME' => 'Фамилия' . $id,
                'UF_DEPARTMENT' => $this->userDepartments[(int)$id] ?? [],
            ], (array)$params['filter']['ID'])],
            'batch' => ['result' => $this->batch((array)$params['cmd'])],
            'calendar.event.add' => $this->calendarError ?? ['result' => 1000 + array_push($this->events, $params)],
            'placement.get' => ['result' => $this->placements],
            'placement.bind' => ['result' => (bool)($this->placements[] = ['placement' => $params['PLACEMENT'], 'handler' => $params['HANDLER']])],
            'placement.unbind' => ['result' => ['count' => count($this->placements) - count($this->placements = array_values(array_filter(
                $this->placements,
                static fn(array $p) => !($p['placement'] === $params['PLACEMENT'] && $p['handler'] === $params['HANDLER']),
            )))]],
            'calendar.settings.get' => ['result' => $this->calendarSettings],
            'entity.item.add' => ['result' => $this->add($entity, $params)],
            'entity.item.update' => ['result' => $this->update($entity, (int)$params['ID'], (array)($params['PROPERTY_VALUES'] ?? []))],
            'entity.item.delete' => ['result' => $this->delete($entity, (int)$params['ID'])],
            'entity.item.get' => ['result' => $this->find($entity, (array)($params['FILTER'] ?? []), (array)($params['SORT'] ?? []))],
            default => throw new \LogicException("Неожиданный метод $method"),
        };
    }

    private function batch(array $commands): array
    {
        $result = [];
        foreach ($commands as $key => $command)
        {
            [$method, $query] = explode('?', $command, 2) + [1 => ''];
            parse_str($query, $params);
            
            $result[$key] = match ($method)
            {
                'im.notify.system.add' => array_push($this->notifications, $params),
                'entity.item.get' => $this->find((string)$params['ENTITY'], (array)($params['FILTER'] ?? []), (array)($params['SORT'] ?? [])),
                default => throw new \LogicException("В batch неожиданный метод $method"),
            };
        }

        return ['result' => $result, 'result_error' => []];
    }

    private function add(string $entity, array $params): int
    {
        $id = $this->nextId++;
        $this->items[$entity][$id] = [
            'ID' => (string)$id,
            'NAME' => (string)($params['NAME'] ?? ''),
            'DATE_CREATE' => '2026-10-27T10:00:00+03:00',
            'PROPERTY_VALUES' => array_map('strval', (array)($params['PROPERTY_VALUES'] ?? [])),
        ];

        return $id;
    }

    private function update(string $entity, int $id, array $values): bool
    {
        // Портал меняет только переданные свойства, остальные сохраняет (проверено на портале в блоке 3)
        $this->items[$entity][$id]['PROPERTY_VALUES'] = array_map('strval', $values) + $this->items[$entity][$id]['PROPERTY_VALUES'];

        return true;
    }

    private function delete(string $entity, int $id): bool
    {
        unset($this->items[$entity][$id]);

        return true;
    }

    private function find(string $entity, array $filter, array $sort): array
    {
        $items = array_values(array_filter($this->items[$entity] ?? [], static function (array $item) use ($filter): bool {
            foreach ($filter as $key => $value)
            {
                $actual = $key === 'ID' ? $item['ID'] : ($item['PROPERTY_VALUES'][substr($key, strlen('PROPERTY_'))] ?? '');
                if ((string)$actual !== (string)$value)
                {
                    return false;
                }
            }

            return true;
        }));
        if (($sort['ID'] ?? 'ASC') === 'DESC')
        {
            $items = array_reverse($items);
        }

        return $items;
    }
}
