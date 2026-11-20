<?php

declare(strict_types=1);

namespace AdventureGame;

use PDO;

/**
 * Adatbázis-kapcsolat. A beállítások a Config osztályból jönnek
 * (környezeti változó vagy config/config.php).
 */
final class Database
{
    public static function connect(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            Config::get('DB_HOST', 'localhost'),
            Config::get('DB_NAME', 'adventure'),
        );

        return new PDO($dsn, Config::get('DB_USER', 'adventure'), Config::get('DB_PASS', 'adventure'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
}
