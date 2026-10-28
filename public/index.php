<?php
declare(strict_types=1);

use Kb24Rest\Bitrix24\CurlTransport;
use Kb24Rest\Bitrix24\OAuthClient;
use Kb24Rest\Bitrix24\RestClient;
use Kb24Rest\Config;
use Kb24Rest\Db;
use Kb24Rest\Http\App;
use Kb24Rest\Portal\PdoPortalStore;
use Kb24Rest\Portal\UserResolver;
use Kb24Rest\Storage\EntityStorage;
use Kb24Rest\Structure\StructureService;
use Kb24Rest\Vacation\RequestService;
use Kb24Rest\Vacation\RoleService;
use Kb24Rest\Vacation\WorkCalendar;

require dirname(__DIR__) . '/vendor/autoload.php';

$config = Config::fromEnv();
$pdo = Db::connect($config);
Db::migrate($pdo, dirname(__DIR__) . '/migrations');

$http = new CurlTransport();
$portals = new PdoPortalStore($pdo);
$oauth = new OAuthClient($http, $config->clientId, $config->clientSecret);
$rest = new RestClient($http, $oauth, $portals);

$structure = new StructureService($rest);
$storage = new EntityStorage($rest);
$roles = new RoleService($storage);
$requests = new RequestService($storage, $structure, $roles, new WorkCalendar($rest), new DateTimeImmutable('today'), $config->allowSelfApproval);

$app = new App($portals, $oauth, $rest, new UserResolver($portals, $rest, $pdo), $structure, $storage, $roles, $requests, dirname(__DIR__) . '/templates');
$app->handle(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', $_POST, $_SERVER);
