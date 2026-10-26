<?php
declare(strict_types=1);

namespace Kb24Rest\Tests;

use Kb24Rest\Portal\Portal;
use Kb24Rest\Portal\PortalStore;

final class MemoryPortalStore implements PortalStore
{
    /** @var array<string, Portal> */
    public array $portals = [];

    public function findByMemberId(string $memberId): ?Portal
    {
        return $this->portals[$memberId] ?? null;
    }

    public function save(Portal $portal): Portal
    {
        $portal = $portal->withId($portal->id ?? count($this->portals) + 1);

        return $this->portals[$portal->memberId] = $portal;
    }
}
