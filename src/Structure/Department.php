<?php
declare(strict_types=1);

namespace Kb24Rest\Structure;

final class Department
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?int $parentId,
        public readonly ?int $headUserId,
    ) {
    }

    /**
     * @param array<string, mixed> $row строка из department.get
     */
    public static function fromRest(array $row): self
    {
        $parent = (int)($row['PARENT'] ?? 0);
        $head = (int)($row['UF_HEAD'] ?? 0);

        return new self((int)$row['ID'], (string)($row['NAME'] ?? ''), $parent > 0 ? $parent : null, $head > 0 ? $head : null);
    }
}
