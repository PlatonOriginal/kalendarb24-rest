<?php
declare(strict_types=1);

namespace Kb24Rest\Vacation;

use Kb24Rest\Domain\Accrual;
use Kb24Rest\Domain\Status;
use Kb24Rest\Portal\CurrentUser;
use Kb24Rest\Portal\Portal;
use Kb24Rest\Storage\EntityStorage;
use Kb24Rest\Storage\Schema;

/**
 * Остаток ежегодного отпуска, как в коробке. Отдел кадров вносит остаток на дату и дату приёма; в каждую годовщину
 * приёма после этой даты добавляется годовая норма из настроек, а заявки с началом не раньше этой даты остаток уменьшают.
 * Если остаток сотруднику не внесён, ограничения нет.
 * Остаток и дата приёма хранятся одной записью kbbal на сотрудника (в коробке это две таблицы).
 */
final class BalanceService
{
    /** @var array<string, array<int, array<string, mixed>>> записи kbbal по сотруднику, за время одного запроса */
    private array $cache = [];

    public function __construct(
        private readonly EntityStorage $storage,
        private readonly RoleService $roles,
        private readonly ?Settings $settings = null,
        private readonly \DateTimeImmutable $today = new \DateTimeImmutable('today'),
    ) {
    }

    /**
     * Править остатки и даты приёма может отдел кадров и администратор.
     */
    public function canEdit(CurrentUser $user): bool
    {
        return $user->isAdmin || in_array($user->userId, $this->roles->roles($user->portal)['hr'], true);
    }

    /**
     * Смотреть остатки всех сотрудников: ещё и директор (бухгалтерия появится вместе со своей ролью).
     */
    public function canView(CurrentUser $user): bool
    {
        return $this->canEdit($user) || $user->userId === $this->roles->roles($user->portal)['director'];
    }

    /**
     * Остаток сотрудника на дату (по умолчанию сегодня), null если не внесён.
     *
     * @return array{days: float, asOf: string, comment: string, accrued: float, used: float, available: float, updatedBy: int, updatedAt: string}|null
     */
    public function get(Portal $portal, int $userId, ?\DateTimeImmutable $on = null): ?array
    {
        $record = $this->records($portal, $userId)[$userId] ?? null;
        if ($record === null || $record['days'] === null)
        {
            return null;
        }
        $requests = $this->storage->find($portal, Schema::REQUESTS, ['PROPERTY_USER_ID' => $userId]);

        return $this->compute($portal, $record, array_map(RequestService::toRequest(...), $requests), $on ?? $this->today);
    }

    /**
     * Все внесённые остатки и даты приёма. Сотрудников и их ФИО браузер берёт из портала сам.
     */
    public function list(CurrentUser $user): array
    {
        if (!$this->canView($user))
        {
            throw new VacationError('Остатки отпуска видят отдел кадров, директор и администратор');
        }
        $portal = $user->portal;
        $records = $this->records($portal);
        $byUser = [];
        if (array_filter($records, static fn(array $r) => $r['days'] !== null))
        {
            foreach ($this->storage->find($portal, Schema::REQUESTS) as $item)
            {
                $request = RequestService::toRequest($item);
                $byUser[$request['userId']][] = $request;
            }
        }

        $items = [];
        foreach ($records as $userId => $record)
        {
            $items[] = $this->item($portal, $record, $byUser[$userId] ?? []);
        }

        return ['items' => $items, 'canEdit' => $this->canEdit($user), 'annualDays' => $this->annualDays($portal)];
    }

    /**
     * Внести остаток и дату приёма. Пустой остаток снимает ограничение. $hireDate = null оставляет дату приёма как есть.
     */
    public function save(CurrentUser $user, int $userId, string $days, string $asOf, string $comment = '', ?string $hireDate = null): array
    {
        if (!$this->canEdit($user))
        {
            throw new VacationError('Остатки отпуска и даты приёма правит отдел кадров');
        }
        $this->write($user, $userId, $days, $asOf, $comment, $hireDate);
        $record = $this->records($user->portal, $userId)[$userId] ?? $this->emptyRecord($userId);
        $requests = $record['days'] !== null
            ? array_map(RequestService::toRequest(...), $this->storage->find($user->portal, Schema::REQUESTS, ['PROPERTY_USER_ID' => $userId]))
            : [];

        return $this->item($user->portal, $record, $requests);
    }

    /**
     * Загрузка из CSV. Строки разбирает браузер, он же находит сотрудника по ФИО, если в строке нет ID.
     *
     * @param list<array{line?: int, userId?: int|string, hireDate?: string, days?: string, asOf?: string, comment?: string}> $rows
     * @return array{saved: int, errors: list<string>}
     */
    public function import(CurrentUser $user, array $rows): array
    {
        if (!$this->canEdit($user))
        {
            throw new VacationError('Остатки отпуска и даты приёма загружает отдел кадров');
        }
        if (count($rows) > 1000)
        {
            throw new VacationError('За один раз можно загрузить до 1000 строк');
        }
        $saved = 0;
        $errors = [];
        foreach ($rows as $row)
        {
            $line = (int)($row['line'] ?? 0);
            $userId = (int)($row['userId'] ?? 0);
            if ($userId <= 0)
            {
                $errors[] = "Строка {$line}: сотрудник не найден";
                continue;
            }
            try
            {
                $this->write(
                    $user,
                    $userId,
                    (string)($row['days'] ?? ''),
                    (string)($row['asOf'] ?? ''),
                    (string)($row['comment'] ?? ''),
                    array_key_exists('hireDate', $row) ? (string)$row['hireDate'] : null,
                );
                $saved++;
            }
            catch (VacationError $e)
            {
                $errors[] = "Строка {$line}: " . $e->getMessage();
            }
        }

        return ['saved' => $saved, 'errors' => $errors];
    }

    public function annualDays(Portal $portal): int
    {
        return $this->settings?->annualDays($portal) ?? Settings::DEFAULTS['annual_days'];
    }

    private function write(CurrentUser $user, int $userId, string $days, string $asOf, string $comment, ?string $hireDate): void
    {
        $portal = $user->portal;
        $record = $this->records($portal)[$userId] ?? $this->emptyRecord($userId);

        if ($hireDate !== null)
        {
            $hire = trim($hireDate) === '' ? null : self::parseDate($hireDate);
            if (trim($hireDate) !== '' && ($hire === null || $hire > $this->today))
            {
                throw new VacationError('Проверьте дату приёма на работу');
            }
            $record['hireDate'] = $hire?->format('Y-m-d') ?? '';
        }

        $days = trim(str_replace(',', '.', $days));
        if ($days === '')
        {
            $record['days'] = null;
            $record['asOf'] = '';
            $record['comment'] = '';
        }
        else
        {
            if (!is_numeric($days) || (float)$days < -365 || (float)$days > 365)
            {
                throw new VacationError('Остаток укажите числом дней от −365 до 365');
            }
            $date = trim($asOf) === '' ? $this->today : self::parseDate($asOf);
            if ($date === null)
            {
                throw new VacationError('Проверьте дату, на которую указан остаток');
            }
            $record['days'] = round((float)$days, 2);
            $record['asOf'] = $date->format('Y-m-d');
            $record['comment'] = mb_substr(trim($comment), 0, 255);
        }

        $values = [
            'USER_ID' => $userId,
            'HIRE_DATE' => $record['hireDate'],
            'DAYS' => $record['days'] === null ? '' : (string)$record['days'],
            'AS_OF' => $record['asOf'],
            'COMMENT' => $record['comment'],
            'UPDATED_BY' => $user->userId,
            'UPDATED_AT' => (new \DateTimeImmutable())->format('Y-m-d H:i'),
        ];
        // Ни остатка, ни даты приёма: запись не нужна
        if ($record['days'] === null && $record['hireDate'] === '')
        {
            if ($record['id'] > 0)
            {
                $this->storage->delete($portal, Schema::BALANCES, $record['id']);
            }
            unset($this->cache[$portal->memberId][$userId]);

            return;
        }
        if ($record['id'] > 0)
        {
            $this->storage->update($portal, Schema::BALANCES, $record['id'], $values);
        }
        else
        {
            $record['id'] = $this->storage->add($portal, Schema::BALANCES, 'user' . $userId, $values);
        }
        $record['updatedBy'] = $user->userId;
        $record['updatedAt'] = $values['UPDATED_AT'];
        if (isset($this->cache[$portal->memberId]))
        {
            $this->cache[$portal->memberId][$userId] = $record;
        }
    }

    /**
     * @param list<array<string, mixed>> $requests заявки сотрудника
     */
    private function item(Portal $portal, array $record, array $requests): array
    {
        return [
            'userId' => $record['userId'],
            'hireDate' => $record['hireDate'],
            'balance' => $record['days'] !== null ? $this->compute($portal, $record, $requests, $this->today) : null,
        ];
    }

    /**
     * DAYS и AS_OF как внесены, accrued — начислено в годовщины после AS_OF до даты $on, used — дни действующих заявок
     * на ежегодный отпуск с началом не раньше AS_OF, available = days + accrued − used.
     *
     * @param list<array<string, mixed>> $requests
     */
    private function compute(Portal $portal, array $record, array $requests, \DateTimeImmutable $on): array
    {
        $asOf = new \DateTimeImmutable($record['asOf']);
        $accrued = $record['hireDate'] !== ''
            ? (float)($this->annualDays($portal) * Accrual::countAnniversaries(new \DateTimeImmutable($record['hireDate']), $asOf, $on))
            : 0.0;
        $used = 0.0;
        foreach ($requests as $request)
        {
            if ($request['userId'] === $record['userId'] && Status::isActive($request['status'])
                && (Kinds::all()[$request['kind']]['deductsBalance'] ?? false) && $request['dateFrom'] >= $record['asOf'])
            {
                $used += (float)$request['days'];
            }
        }

        return [
            'days' => $record['days'],
            'asOf' => $record['asOf'],
            'comment' => $record['comment'],
            'accrued' => $accrued,
            'used' => $used,
            'available' => round($record['days'] + $accrued - $used, 2),
            'updatedBy' => $record['updatedBy'],
            'updatedAt' => $record['updatedAt'],
        ];
    }

    /**
     * @return array<int, array{id: int, userId: int, hireDate: string, days: ?float, asOf: string, comment: string, updatedBy: int, updatedAt: string}>
     */
    private function records(Portal $portal, ?int $userId = null): array
    {
        if (isset($this->cache[$portal->memberId]))
        {
            return $this->cache[$portal->memberId];
        }
        $records = [];
        foreach ($this->storage->find($portal, Schema::BALANCES, $userId !== null ? ['PROPERTY_USER_ID' => $userId] : []) as $item)
        {
            $values = $item['PROPERTY_VALUES'] ?? [];
            $id = (int)($values['USER_ID'] ?? 0);
            $days = (string)($values['DAYS'] ?? '');
            $records[$id] = [
                'id' => (int)$item['ID'],
                'userId' => $id,
                'hireDate' => (string)($values['HIRE_DATE'] ?? ''),
                'days' => is_numeric($days) ? (float)$days : null,
                'asOf' => (string)($values['AS_OF'] ?? ''),
                'comment' => (string)($values['COMMENT'] ?? ''),
                'updatedBy' => (int)($values['UPDATED_BY'] ?? 0),
                'updatedAt' => (string)($values['UPDATED_AT'] ?? ''),
            ];
        }
        // Кэшируем только полный список: выборка одного сотрудника другим не годится
        if ($userId === null)
        {
            $this->cache[$portal->memberId] = $records;
        }

        return $records;
    }

    private function emptyRecord(int $userId): array
    {
        return ['id' => 0, 'userId' => $userId, 'hireDate' => '', 'days' => null, 'asOf' => '', 'comment' => '', 'updatedBy' => 0, 'updatedAt' => ''];
    }

    /**
     * Даты из формы (Y-m-d) и из CSV (дд.мм.гггг, дд.мм.гг).
     */
    private static function parseDate(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        foreach (['!Y-m-d', '!d.m.Y', '!d.m.y'] as $format)
        {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            if ($date && $date->format(ltrim($format, '!')) === $value)
            {
                return $date;
            }
        }

        return null;
    }
}
