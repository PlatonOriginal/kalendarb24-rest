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
}
