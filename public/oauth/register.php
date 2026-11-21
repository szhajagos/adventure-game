<?php
// OAuth: register
declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

(new AdventureGame\Auth\OAuthServer(AdventureGame\Database::connect(), AdventureGame\Config::appUrl()))->register();
