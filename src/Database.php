<?php

declare(strict_types=1);

namespace AdventureGame;

use PDO;

/**
 * Adatbázis-kapcsolat a környezeti változókból (DB_HOST, DB_NAME, DB_USER, DB_PASS).
 */
final class Database
{
    public static function connect(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            getenv('DB_HOST') ?: 'localhost',
            getenv('DB_NAME') ?: 'adventure',
        );

        return new PDO($dsn, getenv('DB_USER') ?: 'adventure', getenv('DB_PASS') ?: 'adventure', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
}
