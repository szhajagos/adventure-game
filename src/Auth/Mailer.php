<?php

declare(strict_types=1);

namespace AdventureGame\Auth;

use AdventureGame\Config;

/**
 * Levélküldés a PHP mail() függvényével (cPanel-tárhelyen beállítás nélkül működik).
 * Feladó: a config.php MAIL_FROM értéke, pl. noreply@mcpadventure.com.
 */
final class Mailer
{
    public static function send(string $to, string $subject, string $text): bool
    {
        $from = (string) Config::get('MAIL_FROM', 'noreply@mcpadventure.com');
        $name = (string) Config::get('MAIL_FROM_NAME', 'MCP Adventure');

        $headers = implode("\r\n", [
            'From: =?UTF-8?B?'.base64_encode($name).'?= <'.$from.'>',
            'Reply-To: '.$from,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ]);
        $encodedSubject = '=?UTF-8?B?'.base64_encode($subject).'?=';

        // Fejlesztés közben a levél tartalma a naplóba is bekerül (ha nincs levelezés)
        if (Config::get('APP_DEBUG', '0') === '1') {
            error_log("[MCP Adventure levél] Címzett: $to | $subject\n$text");
        }

        return @mail($to, $encodedSubject, $text, $headers, '-f'.$from);
    }
}
