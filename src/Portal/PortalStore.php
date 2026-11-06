<?php
declare(strict_types=1);

namespace Kb24Rest\Portal;

interface PortalStore
{
    public function findByMemberId(string $memberId): ?Portal;

    /**
     * Вставляет или обновляет портал по member_id и возвращает его с id.
     */
    public function save(Portal $portal): Portal;

    /**
     * Версия схемы хранилищ entity.*, которую сервер уже создал в портале (хэш Schema::storages()).
     */
    public function storageVersion(string $memberId): ?string;

    public function setStorageVersion(string $memberId, string $version): void;

    /**
     * member_id всех порталов, где установлено приложение (для заданий по расписанию).
     *
     * @return list<string>
     */
    public function memberIds(): array;

    /**
     * application_token из событий портала (ONAPPUPDATE): по нему проверяем, что событие прислал Битрикс24.
     */
    public function applicationToken(string $memberId): ?string;

    public function setApplicationToken(string $memberId, string $token): void;

    /**
     * Удаляет портал, кэш сессий его сотрудников и журнал его запросов к REST (после удаления приложения с портала).
     */
    public function delete(string $memberId): void;
}
