<?php

declare(strict_types=1);

namespace AdventureGame\Game;

/**
 * Irányok egységesítése: „Északra”, „é”, „north” → „eszak”.
 */
final class Direction
{
    /** Kanonikus irány => megjelenített név */
    public const LABELS = [
        'eszak'  => 'észak',
        'del'    => 'dél',
        'kelet'  => 'kelet',
        'nyugat' => 'nyugat',
        'fel'    => 'fel',
        'le'     => 'le',
        'be'     => 'be',
        'ki'     => 'ki',
        'park'   => 'a park felé',
    ];

    private const ALIASES = [
        'e' => 'eszak', 'é' => 'eszak', 'n' => 'eszak', 'north' => 'eszak', 'eszakra' => 'eszak',
        'd' => 'del', 's' => 'del', 'south' => 'del', 'delre' => 'del',
        'k' => 'kelet', 'east' => 'kelet', 'keletre' => 'kelet',
        'ny' => 'nyugat', 'w' => 'nyugat', 'west' => 'nyugat', 'nyugatra' => 'nyugat',
        'felfele' => 'fel', 'up' => 'fel',
        'lefele' => 'le', 'down' => 'le',
        'befele' => 'be', 'in' => 'be', 'bemegy' => 'be',
        'kifele' => 'ki', 'out' => 'ki', 'kimegy' => 'ki',
    ];

    public static function normalize(string $input): string
    {
        $s = mb_strtolower(trim($input));
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ö' => 'o', 'ő' => 'o', 'ú' => 'u', 'ü' => 'u', 'ű' => 'u']);

        if (isset(self::LABELS[$s])) {
            return $s;
        }

        // Az ékezetmentesítés előtti alakot is megnézzük (pl. „é”)
        $raw = mb_strtolower(trim($input));

        return self::ALIASES[$s] ?? self::ALIASES[$raw] ?? $s;
    }

    public static function label(string $direction): string
    {
        return self::LABELS[$direction] ?? $direction;
    }
}
