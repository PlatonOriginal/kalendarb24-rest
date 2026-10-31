<?php
declare(strict_types=1);

/**
 * Напоминания тем, кто не подтвердил ознакомление с результатом заявки. Запускать раз в день утром
 * (Планировщик Windows: scripts\remind.cmd, на сервере — cron). Повторный запуск в тот же день ничего не шлёт.
 * Нерабочие дни берутся из настроек рабочего времени портала: в выходные и праздники напоминаний нет.
 */

use Kb24Rest\Bitrix24\CurlTransport;
use Kb24Rest\Bitrix24\OAuthClient;
use Kb24Rest\Bitrix24\RestClient;
use Kb24Rest\Bitrix24\RestException;
use Kb24Rest\Config;
use Kb24Rest\Db;
use Kb24Rest\Portal\PdoPortalStore;
use Kb24Rest\Storage\EntityStorage;
use Kb24Rest\Vacation\Notifier;
use Kb24Rest\Vacation\WatchService;
use Kb24Rest\Vacation\WorkCalendar;

require dirname(__DIR__) . '/vendor/autoload.php';

$config = Config::fromEnv();
$pdo = Db::connect($config);
$http = new CurlTransport();
$portals = new PdoPortalStore($pdo);
$rest = new RestClient($http, new OAuthClient($http, $config->clientId, $config->clientSecret), $portals);
$notifier = new Notifier($rest, $config->appCode, $config->allowSelfApproval);
$watch = new WatchService(new EntityStorage($rest), $rest, $notifier);
$calendar = new WorkCalendar($rest);
$today = new DateTimeImmutable('today');

$exitCode = 0;
foreach ($portals->memberIds() as $memberId)
{
    $portal = $portals->findByMemberId($memberId);
    try
    {
        $count = $watch->remind($portal, $calendar->workingDays($portal)->isWorkingDay($today));
        $notifier->send();
        echo $portal->domain . ': напоминаний ' . $count . PHP_EOL;
    }
    catch (RestException $e)
    {
        // Портал мог удалить приложение или отозвать доступ: остальные порталы это не останавливает
        echo $portal->domain . ': ' . $e->errorCode . ' ' . $e->getMessage() . PHP_EOL;
        $exitCode = 1;
    }
}
exit($exitCode);
