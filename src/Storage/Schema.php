<?php
declare(strict_types=1);

namespace Kb24Rest\Storage;

/**
 * Хранилища приложения в портале клиента (entity.*). Код хранилища не длиннее 13 символов:
 * портал добавляет к нему префикс APP_<client_id>_.
 * Сотрудники хранятся только как ID в портале, ФИО сюда не пишем.
 */
final class Schema
{
    public const REQUESTS = 'kbreq';
    public const HISTORY = 'kbhist';
    public const ROLES = 'kbrole';

    /**
     * @return array<string, array{name: string, properties: array<string, array{name: string, type: string}>}>
     */
    public static function storages(): array
    {
        return [
            self::REQUESTS => [
                'name' => 'Отпуска: заявки',
                'properties' => [
                    'USER_ID' => ['name' => 'Сотрудник (ID)', 'type' => 'N'],
                    'DEPT_ID' => ['name' => 'Отдел (ID)', 'type' => 'N'],
                    'KIND' => ['name' => 'Вид отпуска', 'type' => 'S'],
                    'DATE_FROM' => ['name' => 'Начало, Y-m-d', 'type' => 'S'],
                    'DATE_TO' => ['name' => 'Окончание, Y-m-d', 'type' => 'S'],
                    'DAYS' => ['name' => 'Дней', 'type' => 'N'],
                    'STATUS' => ['name' => 'Статус', 'type' => 'S'],
                    'APPROVER_ID' => ['name' => 'Текущий согласующий (ID)', 'type' => 'N'],
                    'STEP' => ['name' => 'Шаг маршрута', 'type' => 'N'],
                    'ROUTE' => ['name' => 'Согласующие (ID через запятую)', 'type' => 'S'],
                    'CAL_EVENT_ID' => ['name' => 'Событие в календаре сотрудника (ID)', 'type' => 'N'],
                    'CAL_ERROR' => ['name' => 'Почему событие не создано', 'type' => 'S'],
                ],
            ],
            self::HISTORY => [
                'name' => 'Отпуска: история согласования',
                'properties' => [
                    'REQ_ID' => ['name' => 'Заявка (ID)', 'type' => 'N'],
                    'ACTOR_ID' => ['name' => 'Кто (ID)', 'type' => 'N'],
                    'ACTION' => ['name' => 'Действие', 'type' => 'S'],
                    'STATUS_FROM' => ['name' => 'Статус до', 'type' => 'S'],
                    'STATUS_TO' => ['name' => 'Статус после', 'type' => 'S'],
                    'REASON' => ['name' => 'Причина отказа (код из списка)', 'type' => 'S'],
                ],
            ],
            self::ROLES => [
                'name' => 'Отпуска: роли',
                'properties' => [
                    'ROLE' => ['name' => 'Роль', 'type' => 'S'],
                    'USER_ID' => ['name' => 'Сотрудник (ID)', 'type' => 'N'],
                ],
            ],
        ];
    }

    /**
     * Меняется при любом изменении хранилищ или свойств: по нему сервер понимает, что портал нужно дополнить.
     */
    public static function version(): string
    {
        return substr(sha1(json_encode(self::storages(), JSON_UNESCAPED_UNICODE)), 0, 12);
    }
}
