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
use Kb24Rest\Structure\StructureService;

require dirname(__DIR__) . '/vendor/autoload.php';

$config = Config::fromEnv();
$pdo = Db::connect($config);
Db::migrate($pdo, dirname(__DIR__) . '/migrations');

$http = new CurlTransport();
$portals = new PdoPortalStore($pdo);
$oauth = new OAuthClient($http, $config->clientId, $config->clientSecret);
$rest = new RestClient($http, $oauth, $portals);

$app = new App($portals, $oauth, $rest, new UserResolver($portals, $rest, $pdo), new StructureService($rest), dirname(__DIR__) . '/templates');
$app->handle(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', $_POST, $_SERVER);
