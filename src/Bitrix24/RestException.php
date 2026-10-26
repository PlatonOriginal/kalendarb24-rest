<?php
declare(strict_types=1);

namespace Kb24Rest\Bitrix24;

final class RestException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $description = '')
    {
        parent::__construct($description !== '' ? "$errorCode: $description" : $errorCode);
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromBody(array $body): self
    {
        return new self((string)($body['error'] ?? 'unknown_error'), (string)($body['error_description'] ?? ''));
    }
}
