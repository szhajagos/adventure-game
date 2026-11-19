<?php

/*
 * Az MCP-végpont: ide csatlakozik a játékos Claude-ja vagy ChatGPT-je.
 * Helyi cím: http://localhost:8100/mcp.php
 */

declare(strict_types=1);

use AdventureGame\Database;
use AdventureGame\Game\GameEngine;
use AdventureGame\Mcp\ServerFactory;
use Http\Discovery\Psr17Factory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;

require dirname(__DIR__).'/vendor/autoload.php';

// Prototípus: egyetlen játékos. Később a bejelentkezésből (OAuth) jön.
$playerId = (int) (getenv('PLAYER_ID') ?: 1);

// Mely host-nevekről fogadunk kéréseket (pl. a Cloudflare-alagút címe).
// Vesszővel elválasztva a .env MCP_ALLOWED_HOSTS változójában; '*' = bármelyik.
$allowedHosts = array_filter(array_map('trim', explode(',', getenv('MCP_ALLOWED_HOSTS') ?: 'localhost,127.0.0.1')));

$server = ServerFactory::create(new GameEngine(Database::connect()), $playerId);

$transport = new StreamableHttpTransport(
    (new Psr17Factory())->createServerRequestFromGlobals(),
    middleware: [
        new CorsMiddleware(),
        new DnsRebindingProtectionMiddleware(allowedHosts: $allowedHosts),
    ],
);

(new SapiEmitter())->emit($server->run($transport));
