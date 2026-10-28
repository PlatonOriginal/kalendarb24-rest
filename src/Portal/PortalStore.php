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
}
