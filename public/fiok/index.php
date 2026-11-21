<?php
// A játékos fiókoldalai: regisztráció, belépés, e-mail-megerősítés, új jelszó, fiók
declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

(new AdventureGame\Web\AccountApp(AdventureGame\Database::connect(), AdventureGame\Config::appUrl()))->run();
