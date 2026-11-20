<?php

declare(strict_types=1);

namespace AdventureGame;

/**
 * Beállítások olvasása.
 *
 * Sorrend:
 *  1. környezeti változó (Docker: docker-compose.yml / .env)
 *  2. config/config.php (webtárhely: ott nincsenek környezeti változók)
 *  3. a megadott alapértelmezés
 */
final class Config
{
    /** @var array<string, mixed>|null */
    private static ?array $file = null;

    public static function get(string $key, ?string $default = null): ?string
    {
        $env = getenv($key);
        if ($env !== false && $env !== '') {
            return $env;
        }

        $file = self::file();
        if (array_key_exists($key, $file) && $file[$key] !== null && $file[$key] !== '') {
            return (string) $file[$key];
        }

        return $default;
    }

    /** @return array<string, mixed> */
    private static function file(): array
    {
        if (self::$file === null) {
            $path = dirname(__DIR__).'/config/config.php';
            $data = is_file($path) ? require $path : [];
            self::$file = is_array($data) ? $data : [];
        }

        return self::$file;
    }
}
