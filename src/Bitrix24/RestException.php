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
        // REST 3.0 отдаёт ошибку объектом, классический REST строкой
        $error = $body['error'] ?? 'unknown_error';
        if (is_array($error))
        {
            return new self((string)($error['code'] ?? 'unknown_error'), (string)($error['message'] ?? ''));
        }

        return new self((string)$error, (string)($body['error_description'] ?? ''));
    }
}
