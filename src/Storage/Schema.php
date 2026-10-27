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
                    'KIND' => ['name' => 'Вид отпуска', 'type' => 'S'],
                    'DATE_FROM' => ['name' => 'Начало, Y-m-d', 'type' => 'S'],
                    'DATE_TO' => ['name' => 'Окончание, Y-m-d', 'type' => 'S'],
                    'DAYS' => ['name' => 'Дней', 'type' => 'N'],
                    'STATUS' => ['name' => 'Статус', 'type' => 'S'],
                    'STEP' => ['name' => 'Шаг маршрута', 'type' => 'N'],
                    'ROUTE' => ['name' => 'Согласующие (ID через запятую)', 'type' => 'S'],
                ],
            ],
            self::HISTORY => [
                'name' => 'Отпуска: история согласования',
                'properties' => [
                    'REQ_ID' => ['name' => 'Заявка (ID)', 'type' => 'N'],
                    'ACTOR_ID' => ['name' => 'Кто (ID)', 'type' => 'N'],
                    'ACTION' => ['name' => 'Действие', 'type' => 'S'],
                ],
            ],
        ];
    }
}
