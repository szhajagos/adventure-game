<?php

/*
 * A szerkesztőfelület belépési pontja: https://play.mcpadventure.com/admin/
 */

declare(strict_types=1);

use AdventureGame\Admin\AdminApp;
use AdventureGame\Database;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$root = dirname(__DIR__, 2);

(new AdminApp(
    Database::connect(),
    imageDir: $root.'/assets/images',
    imageUrl: '/assets/images',
))->run();
