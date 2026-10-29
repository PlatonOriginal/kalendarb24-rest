<?php
declare(strict_types=1);

namespace Kb24Rest\Vacation;

use Kb24Rest\Domain\DateRange;
use Kb24Rest\Domain\LimitChecker;
use Kb24Rest\Domain\LimitViolation;
use Kb24Rest\Domain\Status;
use Kb24Rest\Portal\Portal;
use Kb24Rest\Storage\EntityStorage;
use Kb24Rest\Storage\Schema;
use Kb24Rest\Structure\StructureService;

/**
 * Лимиты отделов, как в коробке: сколько сотрудников отдела могут отсутствовать в один день
 * и на сколько дней могут пересекаться два отсутствия.
 * Заявки отдела ищем по отделу, записанному в заявку при подаче (DEPT_ID): так не нужно запрашивать
 * всех сотрудников отдела на сервере. Если сотрудника перевели, его прошлые заявки остаются в старом отделе.
 */
final class LimitService
{
    /** @var array<string, array<int, array{id: int, maxConcurrent: ?int, maxOverlap: ?int, withSub: bool, withHead: bool}>> */
    private array $cache = [];

    public function __construct(
        private readonly EntityStorage $storage,
        private readonly StructureService $structure,
    ) {
    }

    /**
     * @return array<int, array{id: int, maxConcurrent: ?int, maxOverlap: ?int, withSub: bool, withHead: bool}> по ID отдела
     */
    public function all(Portal $portal): array
    {
        if (!isset($this->cache[$portal->memberId]))
        {
            $limits = [];
            foreach ($this->storage->find($portal, Schema::LIMITS) as $item)
            {
                $values = $item['PROPERTY_VALUES'] ?? [];
                $limits[(int)($values['DEPT_ID'] ?? 0)] = [
                    'id' => (int)$item['ID'],
                    'maxConcurrent' => self::number($values['MAX_CONCURRENT'] ?? ''),
                    'maxOverlap' => self::number($values['MAX_OVERLAP'] ?? ''),
                    'withSub' => ($values['WITH_SUB'] ?? '') === 'Y',
                    'withHead' => ($values['WITH_HEAD'] ?? '') === 'Y',
                ];
            }
            $this->cache[$portal->memberId] = $limits;
        }

        return $this->cache[$portal->memberId];
    }

    /**
     * @return array{maxConcurrent: ?int, maxOverlap: ?int, withSub: bool, withHead: bool}
     */
    public function limit(Portal $portal, int $departmentId): array
    {
        $limit = $this->all($portal)[$departmentId] ?? ['maxConcurrent' => null, 'maxOverlap' => null, 'withSub' => false, 'withHead' => false];
        unset($limit['id']);

        return $limit;
    }

    /**
     * Сохраняет лимит отдела. Без ограничений запись удаляется, чтобы хранилище не копило пустые строки.
     */
    public function save(Portal $portal, int $departmentId, ?int $maxConcurrent, ?int $maxOverlap, bool $withSub, bool $withHead): void
    {
        if ($this->structure->orgChart($portal)->department($departmentId) === null)
        {
            throw new VacationError('Отдел не найден в структуре компании');
        }
        if ($maxConcurrent !== null && ($maxConcurrent < 1 || $maxConcurrent > 1000))
        {
            throw new VacationError('Максимум отсутствующих в один день: число от 1');
        }
        if ($maxOverlap !== null && ($maxOverlap < 0 || $maxOverlap > 366))
        {
            throw new VacationError('Максимум дней пересечения: число от 0 до 366');
        }

        $existing = $this->all($portal)[$departmentId]['id'] ?? null;
        $values = [
            'DEPT_ID' => $departmentId,
            'MAX_CONCURRENT' => (string)$maxConcurrent,
            'MAX_OVERLAP' => (string)$maxOverlap,
            'WITH_SUB' => $withSub ? 'Y' : 'N',
            'WITH_HEAD' => $withHead ? 'Y' : 'N',
        ];
        if ($maxConcurrent === null && $maxOverlap === null)
        {
            if ($existing !== null)
            {
                $this->storage->delete($portal, Schema::LIMITS, $existing);
            }
        }
        elseif ($existing !== null)
        {
            $this->storage->update($portal, Schema::LIMITS, $existing, $values);
        }
        else
        {
            $this->storage->add($portal, Schema::LIMITS, 'dept' . $departmentId, $values);
        }
        unset($this->cache[$portal->memberId]);
    }

    /**
     * Нарушения лимитов отдела новой заявкой сотрудника.
     *
     * @return list<LimitViolation>
     */
    public function check(Portal $portal, int $departmentId, int $userId, DateRange $range): array
    {
        $limit = $this->limit($portal, $departmentId);
        if ($limit['maxConcurrent'] === null && $limit['maxOverlap'] === null)
        {
            return [];
        }
        if (!$limit['withHead'] && $this->headOf($portal, $departmentId) === $userId)
        {
            return [];
        }

        return (new LimitChecker($limit['maxConcurrent'], $limit['maxOverlap']))
            ->check($range, $this->bookedRanges($portal, $departmentId, $range, $userId));
    }

    /**
     * Дни периода, когда лимит одновременных отсутствий отдела уже исчерпан.
     *
     * @return list<string> даты Y-m-d
     */
    public function fullDays(Portal $portal, int $departmentId, DateRange $period): array
    {
        $limit = $this->limit($portal, $departmentId);
        if ($limit['maxConcurrent'] === null)
        {
            return [];
        }

        return array_map(
            static fn(\DateTimeImmutable $day) => $day->format('Y-m-d'),
            (new LimitChecker($limit['maxConcurrent'], null))->getFullDays($period, $this->bookedRanges($portal, $departmentId, $period)),
        );
    }

    /**
     * Действующие заявки отделов, которые пересекаются с периодом.
     *
     * @param list<int> $departmentIds
     * @return list<array<string, mixed>>
     */
    public function departmentRequests(Portal $portal, array $departmentIds, DateRange $period): array
    {
        $found = [];
        foreach ($departmentIds as $departmentId)
        {
            foreach ($this->storage->find($portal, Schema::REQUESTS, ['PROPERTY_DEPT_ID' => $departmentId]) as $item)
            {
                $request = RequestService::toRequest($item);
                // Даты хранятся строками, поэтому период проверяем здесь, а не фильтром entity.item.get
                if (Status::isActive($request['status']) && $request['dateFrom'] <= $period->to->format('Y-m-d')
                    && $request['dateTo'] >= $period->from->format('Y-m-d'))
                {
                    $found[] = $request;
                }
            }
        }

        return $found;
    }

    public function headOf(Portal $portal, int $departmentId): ?int
    {
        return $this->structure->orgChart($portal)->department($departmentId)?->headUserId;
    }

    /**
     * Занятые слоты коллег, которые учитываются в лимитах отдела.
     *
     * @return array<int, DateRange> ID заявки => период
     */
    private function bookedRanges(Portal $portal, int $departmentId, DateRange $period, int $excludeUserId = 0): array
    {
        $limit = $this->limit($portal, $departmentId);
        $departments = $limit['withSub'] ? $this->structure->orgChart($portal)->withChildren($departmentId) : [$departmentId];
        $head = $limit['withHead'] ? null : $this->headOf($portal, $departmentId);

        $ranges = [];
        foreach ($this->departmentRequests($portal, $departments, $period) as $request)
        {
            if ($request['userId'] === $excludeUserId || $request['userId'] === $head || !(Kinds::all()[$request['kind']]['countsInLimit'] ?? false))
            {
                continue;
            }
            $ranges[$request['id']] = DateRange::fromStrings($request['dateFrom'], $request['dateTo']);
        }

        return $ranges;
    }

    private static function number(mixed $value): ?int
    {
        return is_numeric($value) ? (int)$value : null;
    }
}
