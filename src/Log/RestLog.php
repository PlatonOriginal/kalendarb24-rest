<?php
declare(strict_types=1);

namespace Kb24Rest\Log;

/**
 * Журнал запросов сервера к REST API Битрикс24: что вызвали, в каком портале, с каким результатом.
 * Параметры, ответы и токены не пишутся. Записи старше RETENTION удаляются.
 */
interface RestLog
{
    /** Срок хранения записей: 3 суток, как в политике конфиденциальности */
    public const RETENTION = 3 * 86400;

    /** Вызов с токеном приложения (администратора, установившего приложение) */
    public const AUTH_APP = 'app';

    /** Вызов с токеном сотрудника, открывшего приложение */
    public const AUTH_USER = 'user';

    /**
     * @param string $memberId портал; пустая строка, если портал неизвестен (ошибка OAuth до ответа)
     * @param int $httpStatus 0 — ответа не было (ошибка сети)
     * @param string $errorCode код ошибки из ответа, пустая строка при успехе
     */
    public function record(string $memberId, string $method, string $authType, int $httpStatus, string $errorCode, int $durationMs): void;

    /**
     * Удаляет записи старше срока хранения.
     *
     * @return int сколько записей удалено
     */
    public function purge(?int $now = null): int;

    /**
     * Удаляет все записи портала (после удаления приложения с портала).
     */
    public function deletePortal(string $memberId): void;
}
