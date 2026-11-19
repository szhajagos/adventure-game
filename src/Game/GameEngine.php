<?php

declare(strict_types=1);

namespace AdventureGame\Game;

use PDO;
use RuntimeException;

/**
 * A játékmotor: itt dől el, mi történik ténylegesen.
 * Az AI csak meghívja ezeket a műveleteket, és elmeséli az eredményt –
 * a játék állapotát (pénz, helyszín, éhség…) kizárólag ez az osztály módosítja.
 */
final class GameEngine
{
    /** A kezdőállapot, amire az új játék visszaállít. */
    private const START = [
        'location_id' => 'park_pad',
        'money'       => 340,
        'hunger'      => 45,
        'fatigue'     => 35,
        'health'      => 90,
        'game_minute' => 390, // 1. nap, 6:30
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Körülnézés az aktuális helyszínen.
     */
    public function look(int $playerId): array
    {
        $player = $this->player($playerId);

        return [
            'siker'    => true,
            'helyszin' => $this->describeLocation($player['location_id']),
            'ido'      => self::formatTime((int) $player['game_minute']),
        ];
    }

    /**
     * Mozgás a megadott irányba.
     */
    public function move(int $playerId, string $direction): array
    {
        $player = $this->player($playerId);
        $dir = Direction::normalize($direction);

        $stmt = $this->db->prepare('SELECT * FROM exits WHERE from_location = ? AND direction = ?');
        $stmt->execute([$player['location_id'], $dir]);
        $exit = $stmt->fetch();

        if (!$exit) {
            return [
                'siker'          => false,
                'esemeny'        => sprintf('Arra (%s) nem lehet menni innen.', $direction),
                'lehetseges_iranyok' => $this->exitDirections($player['location_id']),
            ];
        }

        $minutes = (int) $exit['minutes'];
        $hunger  = self::clamp((int) $player['hunger'] + (int) ceil($minutes / 2));
        $fatigue = self::clamp((int) $player['fatigue'] + (int) ceil($minutes / 3));
        $time    = (int) $player['game_minute'] + $minutes;

        $this->db->prepare(
            'UPDATE players SET location_id = ?, hunger = ?, fatigue = ?, game_minute = ? WHERE id = ?'
        )->execute([$exit['to_location'], $hunger, $fatigue, $time, $playerId]);

        return [
            'siker'    => true,
            'esemeny'  => sprintf('A játékos %s irányba indult (%s), az út %d percig tartott.',
                Direction::label($dir), $exit['description'] ?? 'tovább', $minutes),
            'valtozasok' => [
                'ehseg'    => [(int) $player['hunger'], $hunger],
                'faradtsag' => [(int) $player['fatigue'], $fatigue],
            ],
            'helyszin' => $this->describeLocation($exit['to_location']),
            'ido'      => self::formatTime($time),
        ];
    }

    /**
     * A játékos állapota és leltára.
     */
    public function status(int $playerId): array
    {
        $p = $this->player($playerId);

        $stmt = $this->db->prepare(
            'SELECT i.name, pi.quantity FROM player_items pi JOIN items i ON i.id = pi.item_id
             WHERE pi.player_id = ? ORDER BY i.name'
        );
        $stmt->execute([$playerId]);
        $inventory = array_map(
            fn (array $r) => ['nev' => $r['name'], 'darab' => (int) $r['quantity']],
            $stmt->fetchAll()
        );

        return [
            'siker'     => true,
            'nev'       => $p['name'],
            'helyszin'  => $this->locationName($p['location_id']),
            'ido'       => self::formatTime((int) $p['game_minute']),
            'penz_ft'   => (int) $p['money'],
            'ehseg'     => ['ertek' => (int) $p['hunger'], 'szoveg' => self::hungerLabel((int) $p['hunger'])],
            'faradtsag' => ['ertek' => (int) $p['fatigue'], 'szoveg' => self::fatigueLabel((int) $p['fatigue'])],
            'egeszseg'  => (int) $p['health'],
            'leltar'    => $inventory,
        ];
    }

    /**
     * Új játék: a játékos visszakerül a kezdőállapotba.
     */
    public function newGame(int $playerId): array
    {
        $this->player($playerId); // létezik-e

        $this->db->prepare(
            'UPDATE players SET location_id = ?, money = ?, hunger = ?, fatigue = ?, health = ?, game_minute = ? WHERE id = ?'
        )->execute([...array_values(self::START), $playerId]);
        $this->db->prepare('DELETE FROM player_items WHERE player_id = ?')->execute([$playerId]);

        return [
            'siker'    => true,
            'esemeny'  => 'Új játék kezdődött. A játékos most ébred fel, nem emlékszik, hogyan került ide.',
            'helyszin' => $this->describeLocation(self::START['location_id']),
            'ido'      => self::formatTime(self::START['game_minute']),
        ];
    }

    // --- belső segédfüggvények -------------------------------------------

    private function player(int $playerId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM players WHERE id = ?');
        $stmt->execute([$playerId]);
        $player = $stmt->fetch();

        if (!$player) {
            throw new RuntimeException("Nincs ilyen játékos: {$playerId}");
        }

        return $player;
    }

    private function describeLocation(string $locationId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM locations WHERE id = ?');
        $stmt->execute([$locationId]);
        $loc = $stmt->fetch();

        $stmt = $this->db->prepare(
            'SELECT e.direction, e.description, l.name AS target
             FROM exits e JOIN locations l ON l.id = e.to_location
             WHERE e.from_location = ? ORDER BY e.direction'
        );
        $stmt->execute([$locationId]);
        $exits = array_map(fn (array $e) => [
            'irany'   => $e['direction'],
            'leiras'  => $e['description'],
            'celpont' => $e['target'],
        ], $stmt->fetchAll());

        $stmt = $this->db->prepare(
            'SELECT i.name, li.quantity FROM location_items li JOIN items i ON i.id = li.item_id
             WHERE li.location_id = ? AND li.quantity > 0 ORDER BY i.name'
        );
        $stmt->execute([$locationId]);
        $items = array_map(
            fn (array $r) => ['nev' => $r['name'], 'darab' => (int) $r['quantity']],
            $stmt->fetchAll()
        );

        return [
            'id'        => $loc['id'],
            'nev'       => $loc['name'],
            'leiras'    => $loc['description'],
            'kijaratok' => $exits,
            'targyak'   => $items,
            'kep'       => $loc['image'],
        ];
    }

    private function locationName(string $locationId): string
    {
        $stmt = $this->db->prepare('SELECT name FROM locations WHERE id = ?');
        $stmt->execute([$locationId]);

        return (string) $stmt->fetchColumn();
    }

    /** @return list<string> */
    private function exitDirections(string $locationId): array
    {
        $stmt = $this->db->prepare('SELECT direction FROM exits WHERE from_location = ? ORDER BY direction');
        $stmt->execute([$locationId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function formatTime(int $minute): string
    {
        $day = intdiv($minute, 1440) + 1;
        $inDay = $minute % 1440;

        return sprintf('%d. nap, %02d:%02d', $day, intdiv($inDay, 60), $inDay % 60);
    }

    private static function clamp(int $v): int
    {
        return max(0, min(100, $v));
    }

    private static function hungerLabel(int $v): string
    {
        return match (true) {
            $v <= 20 => 'jóllakott',
            $v <= 50 => 'kicsit éhes',
            $v <= 75 => 'éhes',
            default  => 'nagyon éhes',
        };
    }

    private static function fatigueLabel(int $v): string
    {
        return match (true) {
            $v <= 20 => 'kipihent',
            $v <= 50 => 'kicsit fáradt',
            $v <= 75 => 'fáradt',
            default  => 'kimerült',
        };
    }
}
