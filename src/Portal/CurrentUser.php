<?php
declare(strict_types=1);

namespace Kb24Rest\Portal;

/**
 * Сотрудник, открывший приложение. Только ID в портале, без ФИО.
 */
final class CurrentUser
{
    public function __construct(
        public readonly Portal $portal,
        public readonly int $userId,
        public readonly bool $isAdmin,
    ) {
    }
}
