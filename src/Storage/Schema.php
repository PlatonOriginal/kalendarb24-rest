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
    public const LIMITS = 'kblimit';
    public const BALANCES = 'kbbal';
    public const ROUTES = 'kbroute';

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
                    'STEP' => ['name' => 'Шаг маршрута (номер с 0)', 'type' => 'N'],
                    'ROUTE' => ['name' => 'Согласующие (ID через запятую)', 'type' => 'S'],
                    'CAL_EVENT_ID' => ['name' => 'Событие в календаре сотрудника (ID)', 'type' => 'N'],
                    'CAL_ERROR' => ['name' => 'Почему событие или запись в графике не созданы', 'type' => 'S'],
                    'ABSENCE_ID' => ['name' => 'Запись в графике отсутствий (ID)', 'type' => 'N'],
                    'CANCEL_STEP' => ['name' => 'Отмена: шаг (head_review, hr_review, done, refused)', 'type' => 'S'],
                    'CANCEL_REASON' => ['name' => 'Отмена: причина', 'type' => 'S'],
                    'ROUTE_ID' => ['name' => 'Маршрут (ID записи kbroute)', 'type' => 'N'],
                    'ROUTE_DATA' => ['name' => 'Маршрут на момент подачи (JSON)', 'type' => 'S'],
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
                    'STEP' => ['name' => 'Шаг маршрута (номер с 1, 0 — не по маршруту)', 'type' => 'N'],
                ],
            ],
            self::ROLES => [
                'name' => 'Отпуска: роли',
                'properties' => [
                    'ROLE' => ['name' => 'Роль', 'type' => 'S'],
                    'USER_ID' => ['name' => 'Сотрудник (ID)', 'type' => 'N'],
                ],
            ],
            self::LIMITS => [
                'name' => 'Отпуска: лимиты отделов',
                'properties' => [
                    'DEPT_ID' => ['name' => 'Отдел (ID)', 'type' => 'N'],
                    // Строкой: пусто значит «без ограничения», а 0 у пересечения значит «не пересекаться вовсе»
                    'MAX_CONCURRENT' => ['name' => 'Максимум отсутствующих в один день (пусто — без ограничения)', 'type' => 'S'],
                    'MAX_OVERLAP' => ['name' => 'Максимум дней пересечения двух отсутствий (пусто — без ограничения)', 'type' => 'S'],
                    'WITH_SUB' => ['name' => 'Учитывать подотделы (Y/N)', 'type' => 'S'],
                    'WITH_HEAD' => ['name' => 'Учитывать руководителя (Y/N)', 'type' => 'S'],
                ],
            ],
            self::BALANCES => [
                'name' => 'Отпуска: остатки и даты приёма',
                'properties' => [
                    'USER_ID' => ['name' => 'Сотрудник (ID)', 'type' => 'N'],
                    'HIRE_DATE' => ['name' => 'Дата приёма, Y-m-d', 'type' => 'S'],
                    // Строкой: пусто значит «остаток не внесён, ограничения нет», дробные дни без потери точности
                    'DAYS' => ['name' => 'Остаток ежегодного отпуска, дн. (пусто — не внесён)', 'type' => 'S'],
                    'AS_OF' => ['name' => 'Остаток на дату, Y-m-d', 'type' => 'S'],
                    'COMMENT' => ['name' => 'Комментарий', 'type' => 'S'],
                    'UPDATED_BY' => ['name' => 'Кто изменил (ID)', 'type' => 'N'],
                    'UPDATED_AT' => ['name' => 'Когда изменил', 'type' => 'S'],
                ],
            ],
            self::ROUTES => [
                'name' => 'Отпуска: маршруты согласования',
                'properties' => [
                    'ROUTE_NAME' => ['name' => 'Название', 'type' => 'S'],
                    'ACTIVE' => ['name' => 'Включён (Y/N)', 'type' => 'S'],
                    'EXCLUSIVE' => ['name' => 'Единственный вариант для своих видов (Y/N)', 'type' => 'S'],
                    'KINDS' => ['name' => 'Виды отсутствия (коды через запятую, пусто — все)', 'type' => 'S'],
                    'DEPTS' => ['name' => 'Отделы (ID через запятую, пусто — все)', 'type' => 'S'],
                    'SORT' => ['name' => 'Сортировка', 'type' => 'N'],
                    'STEPS' => ['name' => 'Шаги (JSON)', 'type' => 'S'],
                    'UPDATED_BY' => ['name' => 'Кто изменил (ID)', 'type' => 'N'],
                    'UPDATED_AT' => ['name' => 'Когда изменил', 'type' => 'S'],
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
