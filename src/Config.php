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

    /** A játék nyilvános címe (pl. https://play.mcpadventure.com): APP_URL, vagy a kérés saját címe */
    public static function appUrl(): string
    {
        $url = rtrim((string) self::get('APP_URL', ''), '/');
        if ($url === '' && !empty($_SERVER['HTTP_HOST'])) {
            $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
            $url = ($https ? 'https' : 'http').'://'.$_SERVER['HTTP_HOST'];
        }

        return $url;
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
