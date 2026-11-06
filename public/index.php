<?php
declare(strict_types=1);

use Kb24Rest\Bitrix24\CurlTransport;
use Kb24Rest\Bitrix24\OAuthClient;
use Kb24Rest\Bitrix24\RestClient;
use Kb24Rest\Config;
use Kb24Rest\Db;
use Kb24Rest\Http\App;
use Kb24Rest\Log\PdoRestLog;
use Kb24Rest\Portal\PdoPortalStore;
use Kb24Rest\Portal\UserResolver;
use Kb24Rest\Storage\EntityStorage;
use Kb24Rest\Structure\StructureService;
use Kb24Rest\Vacation\AbsenceCalendar;
use Kb24Rest\Vacation\BalanceService;
use Kb24Rest\Vacation\CalendarService;
use Kb24Rest\Vacation\DashboardService;
use Kb24Rest\Vacation\LimitService;
use Kb24Rest\Vacation\Notifier;
use Kb24Rest\Vacation\RequestService;
use Kb24Rest\Vacation\RoleService;
use Kb24Rest\Vacation\RouteService;
use Kb24Rest\Vacation\Settings;
use Kb24Rest\Vacation\WatchService;
use Kb24Rest\Vacation\WorkCalendar;

require dirname(__DIR__) . '/vendor/autoload.php';

$config = Config::fromEnv();
$pdo = Db::connect($config);
Db::migrate($pdo, dirname(__DIR__) . '/migrations');

$http = new CurlTransport();
$portals = new PdoPortalStore($pdo);
// Журнал запросов к REST хранится 3 суток: устаревшие записи удаляются после ответа браузеру
$restLog = new PdoRestLog($pdo);
$oauth = new OAuthClient($http, $config->clientId, $config->clientSecret, $restLog);
$rest = new RestClient($http, $oauth, $portals, $restLog);

$structure = new StructureService($rest);
$storage = new EntityStorage($rest);
$settings = new Settings($rest);
$roles = new RoleService($storage, $structure, $settings);
// При проверке под одним аккаунтом уведомления приходят и тому, кто сделал действие
$notifier = new Notifier($rest, $config->appCode, $config->allowSelfApproval);
$absence = new AbsenceCalendar($rest, $storage);
$limits = new LimitService($storage, $structure);
$balances = new BalanceService($storage, $roles, $settings);
$routes = new RouteService($storage, $roles, $structure, $settings);
$watch = new WatchService($storage, $rest, $notifier);
$requests = new RequestService($storage, $structure, $roles, new WorkCalendar($rest), new DateTimeImmutable('today'), $config->allowSelfApproval, $notifier, $absence, $settings, $limits, $balances, $routes, $watch);
$calendar = new CalendarService($structure, $roles, $limits, $settings);

$dashboard = new DashboardService($storage, $structure, $roles, $balances);
$app = new App($portals, $oauth, $rest, new UserResolver($portals, $rest, $pdo), $structure, $storage, $roles, $requests, dirname(__DIR__) . '/templates', $config->appUrl, [$absence, $watch, $notifier, $restLog], $settings, $calendar, $limits, $balances, $routes, $dashboard, $absence);
$app->handle(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', $_POST, $_SERVER);
