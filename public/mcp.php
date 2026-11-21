<?php

/*
 * Az MCP-végpont: ide csatlakozik a játékos Claude-ja vagy ChatGPT-je.
 * Cím: /mcp
 *
 * Azonosítás:
 *  1. OAuth: a kérés egy „Authorization: Bearer …” tokent hoz → a token gazdájának játékosa játszik.
 *  2. Átmeneti tesztkulcs: /mcp/<MCP_ACCESS_KEY> → a PLAYER_ID tesztjátékos (csak fejlesztéshez).
 *  3. Bejelentkezés nélkül a kapcsolódás és az eszközlista elérhető, de egy játékművelet (tools/call)
 *     401-et ad, amire a Claude elindítja a bejelentkezést.
 */

declare(strict_types=1);

use AdventureGame\Auth\OAuthServer;
use AdventureGame\Auth\Users;
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
// átirányítás után REDIRECT_ előtaggal érkezik.
if (empty($_SERVER['HTTP_AUTHORIZATION']) && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
}

$appUrl = Config::appUrl();
$db = Database::connect();
$oauth = new OAuthServer($db, $appUrl);

$deny = function (int $status, string $error = '', string $description = '') use ($oauth): never {
    http_response_code($status);
    header('WWW-Authenticate: '.$oauth->wwwAuthenticate($error, $description));
    header('Content-Type: application/json');
    echo json_encode(array_filter(['error' => $error ?: 'unauthorized', 'error_description' => $description ?: 'Bejelentkezés szükséges.']), JSON_UNESCAPED_UNICODE);
    exit;
};

// --- Ki játszik? ---------------------------------------------------------------
$playerId = null;
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$givenKey = (string) ($_GET['mcp_key'] ?? trim((string) ($_SERVER['PATH_INFO'] ?? ''), '/'));
$accessKey = (string) Config::get('MCP_ACCESS_KEY', '');

if (preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) {
    $userId = $oauth->userForAccessToken($m[1]);
    if ($userId === null) {
        $deny(401, 'invalid_token', 'A token érvénytelen vagy lejárt.');
    }
    $playerId = (new Users($db))->playerFor($userId);
} elseif ($givenKey !== '') {
    // Tesztkulcs az URL-ben: rossz kulcsra a végpont „nem létezik”
    if ($accessKey === '' || !hash_equals($accessKey, $givenKey)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Not Found\n";
        exit;
    }
    $playerId = (int) Config::get('PLAYER_ID', '1');
}

// Bejelentkezés nélkül játékművelet nem futhat: 401 → a Claude elindítja a bejelentkezést
if ($playerId === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode((string) file_get_contents('php://input'), true);
    $messages = is_array($body) && array_is_list($body) ? $body : [$body];
    foreach ($messages as $msg) {
        if (is_array($msg) && ($msg['method'] ?? null) === 'tools/call') {
            $deny(401);
        }
    }
}

// --- Az MCP-szerver ---------------------------------------------------------------
$allowedHosts = array_values(array_filter(array_map(
    'trim',
    explode(',', Config::get('MCP_ALLOWED_HOSTS', 'localhost,127.0.0.1'))
)));

$engine = new GameEngine(
    $db,
    imageDir: dirname(__DIR__).'/assets/images',
    imageUrl: $appUrl !== '' ? $appUrl.'/assets/images' : null,
);
$server = ServerFactory::create($engine, $playerId ?? 0, $appUrl !== '' ? $appUrl : null);

$transport = new StreamableHttpTransport(
    (new Psr17Factory())->createServerRequestFromGlobals(),
    middleware: [
        new CorsMiddleware(),
        new DnsRebindingProtectionMiddleware(allowedHosts: $allowedHosts),
    ],
);

(new SapiEmitter())->emit($server->run($transport));
