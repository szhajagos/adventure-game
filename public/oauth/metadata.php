<?php
// Felfedezési dokumentumok: /.well-known/oauth-authorization-server és /.well-known/oauth-protected-resource[/mcp]
declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

$server = new AdventureGame\Auth\OAuthServer(AdventureGame\Database::connect(), AdventureGame\Config::appUrl());
$doc = ($_GET['doc'] ?? '') === 'as' ? $server->authorizationServerMetadata() : $server->protectedResourceMetadata();

header('Content-Type: application/json');
header('Cache-Control: public, max-age=300');
header('Access-Control-Allow-Origin: *');
echo json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
