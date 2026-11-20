<?php

/*
 * Az MCP-végpont: ide csatlakozik a játékos Claude-ja vagy ChatGPT-je.
 * Cím: /mcp  (vagy közvetlenül /mcp.php)
 */

declare(strict_types=1);

use AdventureGame\Config;
use AdventureGame\Database;
use AdventureGame\Game\GameEngine;
use AdventureGame\Mcp\ServerFactory;
use Http\Discovery\Psr17Factory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;

require dirname(__DIR__).'/vendor/autoload.php';

// Webtárhelyen az Authorization fejlécet a .htaccess adja át; egy belső
// átirányítás után REDIRECT_ előtaggal érkezik. (A későbbi bejelentkezéshez kell.)
if (empty($_SERVER['HTTP_AUTHORIZATION']) && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
}

// Prototípus: egyetlen játékos. Később a bejelentkezésből (OAuth) jön.
$playerId = (int) Config::get('PLAYER_ID', '1');

// Mely host-nevekről fogadunk kéréseket (élesben: a domained). '*' = bármelyik.
$allowedHosts = array_values(array_filter(array_map(
    'trim',
    explode(',', Config::get('MCP_ALLOWED_HOSTS', 'localhost,127.0.0.1'))
)));

$server = ServerFactory::create(new GameEngine(Database::connect()), $playerId);

$transport = new StreamableHttpTransport(
    (new Psr17Factory())->createServerRequestFromGlobals(),
    middleware: [
        new CorsMiddleware(),
        new DnsRebindingProtectionMiddleware(allowedHosts: $allowedHosts),
    ],
);

(new SapiEmitter())->emit($server->run($transport));
