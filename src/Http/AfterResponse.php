<?php
declare(strict_types=1);

namespace Kb24Rest\Http;

/**
 * Работа, которую можно сделать после ответа браузеру: уведомления, событие в календаре.
 */
interface AfterResponse
{
    public function hasWork(): bool;

    public function run(): void;
}
