<?php

declare(strict_types=1);

namespace AdventureGame\Game;

use PDO;
use RuntimeException;

/**
 * A játékmotor: itt dől el, mi történik ténylegesen.
 * Az AI csak meghívja ezeket a műveleteket, és elmeséli az eredményt –
 * a játék állapotát (pénz, helyszín, éhség…) kizárólag ez az osztály módosítja.
 *
 * Egy szerveren több játék (világ) futhat. Minden játékosnak játékonként
 * külön mentése van, és mindig az „aktuális” játékában lép.
 */
final class GameEngine
{
    private const IMAGE_EXTENSIONS = ['webp', 'jpg', 'jpeg', 'png'];

    /** @var array<string, list<array>> */
    private array $statDefCache = [];

    /**
     * @param string|null $imageDir  a helyszínképek mappája a szerveren (assets/images)
     * @param string|null $imageUrl  ugyanennek a mappának a nyilvános címe (https://…/assets/images)
     */
    public function __construct(
        private readonly PDO $db,
        private readonly ?string $imageDir = null,
        private readonly ?string $imageUrl = null,
    ) {
    }

    // =====================================================================
    //  Játékválasztás
    // =====================================================================

    /**
     * Az elérhető játékok listája, a játékos saját állásával.
     */
    public function listGames(int $playerId): array
    {
        $player = $this->player($playerId);

        $stmt = $this->db->prepare(
            'SELECT g.id, g.title, g.description, s.location_id, s.game_minute, s.updated_at, l.name AS location_name
             FROM games g
             LEFT JOIN saves s ON s.game_id = g.id AND s.player_id = ?
             LEFT JOIN locations l ON l.game_id = s.game_id AND l.id = s.location_id
             WHERE g.active = 1
             ORDER BY g.sort_order, g.title'
        );
        $stmt->execute([$playerId]);

        $games = array_map(fn (array $g) => [
            'id'         => $g['id'],
            'cim'        => $g['title'],
            'leiras'     => $g['description'],
            'aktualis'   => $g['id'] === $player['current_game_id'],
            'sajat_allas' => $g['location_id'] === null ? null : [
                'helyszin'  => $g['location_name'],
                'ido'       => self::formatTime((int) $g['game_minute']),
                'utoljara'  => $g['updated_at'],
            ],
        ], $stmt->fetchAll());

        return [
            'siker'   => true,
            'jatekok' => $games,
            'tipp'    => 'A játékos a jatek_inditasa eszközzel választhat. Ha van saját állása, ott folytatja.',
        ];
    }

    /**
     * Játék kiválasztása: ha már van állása, ott folytatja, ha nincs, új mentés indul.
     */
    public function startGame(int $playerId, string $query): array
    {
        $this->player($playerId);
        $game = $this->findGame($query);

        if ($game === null) {
            return [
                'siker'   => false,
                'esemeny' => sprintf('Nincs ilyen játék: „%s”.', $query),
                'jatekok' => array_map(
                    fn (array $g) => ['id' => $g['id'], 'cim' => $g['cim']],
                    $this->listGames($playerId)['jatekok']
                ),
            ];
        }

        $isNew = $this->save($playerId, $game['id']) === null;
        if ($isNew) {
            $this->createSave($playerId, $game);
        }

        $this->db->prepare('UPDATE players SET current_game_id = ? WHERE id = ?')->execute([$game['id'], $playerId]);
        $save = $this->save($playerId, $game['id']);

        return [
            'siker'          => true,
            'esemeny'        => $isNew
                ? 'Új játék kezdődött. Meséld el a bevezetőt, majd a helyszínt.'
                : 'A játékos folytatja a korábbi állását ebben a játékban.',
            'jatek'          => $this->gameInfo($game),
            'bevezeto'       => $isNew ? $game['intro'] : null,
            'helyszin'       => $this->describeLocation($game['id'], $save['location_id']),
            'ido'            => self::formatTime((int) $save['game_minute']),
            'panel'          => $this->panel($playerId, $game),
        ];
    }

    // =====================================================================
    //  Játékműveletek (mindig az aktuális játékban)
    // =====================================================================

    public function look(int $playerId): array
    {
        [$game, $save] = $this->current($playerId);
        if ($game === null) {
            return $this->noGameSelected($playerId);
        }

        return [
            'siker'    => true,
            'jatek'    => $this->gameInfo($game),
            'helyszin' => $this->describeLocation($game['id'], $save['location_id']),
            'ido'      => self::formatTime((int) $save['game_minute']),
            'panel'    => $this->panel($playerId, $game),
        ];
    }

    public function move(int $playerId, string $direction): array
    {
        [$game, $save] = $this->current($playerId);
        if ($game === null) {
            return $this->noGameSelected($playerId);
        }

        $dir = Direction::normalize($direction);
        $stmt = $this->db->prepare('SELECT * FROM exits WHERE game_id = ? AND from_location = ? AND direction = ?');
        $stmt->execute([$game['id'], $save['location_id'], $dir]);
        $exit = $stmt->fetch();

        if (!$exit) {
            return [
                'siker'              => false,
                'esemeny'            => sprintf('Arra (%s) nem lehet menni innen.', $direction),
                'lehetseges_iranyok' => $this->exitDirections($game['id'], $save['location_id']),
            ];
        }

        $minutes = (int) $exit['minutes'];
        $time    = (int) $save['game_minute'] + $minutes;
        $state   = $this->state($save, $game['id']);
        $firstVisit = !$state->visited($exit['to_location']);
        $changes = $state->passTime($minutes);
        $state->visit($exit['to_location']);

        $this->db->prepare(
            'UPDATE saves SET location_id = ?, game_minute = ?, state = ? WHERE player_id = ? AND game_id = ?'
        )->execute([$exit['to_location'], $time, $state->toJson(), $playerId, $game['id']]);

        return [
            'siker'      => true,
            'esemeny'    => sprintf('A játékos %s irányba indult (%s), az út %d percig tartott.',
                Direction::label($dir), $exit['description'] ?? 'tovább', $minutes),
            'elso_latogatas' => $firstVisit,
            'valtozasok' => $this->describeChanges($game['id'], $changes),
            'helyszin'   => $this->describeLocation($game['id'], $exit['to_location']),
            'ido'        => self::formatTime($time),
            'panel'      => $this->panel($playerId, $game),
        ];
    }

    public function status(int $playerId): array
    {
        [$game, $save] = $this->current($playerId);
        if ($game === null) {
            return $this->noGameSelected($playerId);
        }

        $stmt = $this->db->prepare(
            'SELECT i.name, si.quantity FROM save_items si
             JOIN items i ON i.game_id = si.game_id AND i.id = si.item_id
             WHERE si.player_id = ? AND si.game_id = ? ORDER BY i.name'
        );
        $stmt->execute([$playerId, $game['id']]);
        $inventory = array_map(
            fn (array $r) => ['nev' => $r['name'], 'darab' => (int) $r['quantity']],
            $stmt->fetchAll()
        );

        return [
            'siker'     => true,
            'jatek'     => $game['title'],
            'helyszin'  => $this->locationName($game['id'], $save['location_id']),
            'ido'       => self::formatTime((int) $save['game_minute']),
            'tulajdonsagok' => $this->state($save, $game['id'])->statsView(),
            'leltar'    => $inventory,
            'panel'     => $this->panel($playerId, $game),
        ];
    }

    /**
     * Az aktuális játék újrakezdése: a mentés visszaáll a játék kezdőállapotára.
     */
    public function restart(int $playerId): array
    {
        [$game] = $this->current($playerId);
        if ($game === null) {
            return $this->noGameSelected($playerId);
        }

        $this->db->prepare('DELETE FROM saves WHERE player_id = ? AND game_id = ?')->execute([$playerId, $game['id']]);
        $this->createSave($playerId, $game);

        return [
            'siker'    => true,
            'esemeny'  => 'A játék elölről kezdődött. Meséld el a bevezetőt, majd a helyszínt.',
            'jatek'    => $this->gameInfo($game),
            'bevezeto' => $game['intro'],
            'helyszin' => $this->describeLocation($game['id'], $game['start_location']),
            'ido'      => self::formatTime((int) $game['start_minute']),
            'panel'    => $this->panel($playerId, $game),
        ];
    }

    // =====================================================================
    //  Belső segédfüggvények
    // =====================================================================

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

    /** @return array{0: ?array, 1: ?array} az aktuális játék és a hozzá tartozó mentés */
    private function current(int $playerId): array
    {
        $player = $this->player($playerId);
        if ($player['current_game_id'] === null) {
            return [null, null];
        }

        $game = $this->game($player['current_game_id']);
        $save = $game ? $this->save($playerId, $game['id']) : null;

        return ($game && $save) ? [$game, $save] : [null, null];
    }

    private function noGameSelected(int $playerId): array
    {
        return [
            'siker'   => false,
            'esemeny' => 'Még nincs kiválasztott játék. Mutasd be a játékosnak a lehetőségeket, és a jatek_inditasa eszközzel indítsd el, amelyiket választja.',
            'jatekok' => $this->listGames($playerId)['jatekok'],
        ];
    }

    private function game(string $gameId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM games WHERE id = ? AND active = 1');
        $stmt->execute([$gameId]);

        return $stmt->fetch() ?: null;
    }

    /** Játék keresése azonosító vagy cím (részlet) alapján, ékezetektől függetlenül. */
    private function findGame(string $query): ?array
    {
        $q = self::simplify($query);
        if ($q === '') {
            return null;
        }

        $games = $this->db->query('SELECT * FROM games WHERE active = 1 ORDER BY sort_order')->fetchAll();

        foreach ($games as $g) {
            if (self::simplify($g['id']) === $q) {
                return $g;
            }
        }

        $matches = array_values(array_filter($games, fn (array $g) =>
            str_contains(self::simplify($g['title']), $q) || str_contains($q, self::simplify($g['id']))));

        return count($matches) === 1 ? $matches[0] : null;
    }

    private function save(int $playerId, string $gameId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM saves WHERE player_id = ? AND game_id = ?');
        $stmt->execute([$playerId, $gameId]);

        return $stmt->fetch() ?: null;
    }

    private function createSave(int $playerId, array $game): void
    {
        $state = State::initial($this->statDefs($game['id']), $game['start_location']);
        $this->db->prepare(
            'INSERT INTO saves (player_id, game_id, location_id, game_minute, state) VALUES (?, ?, ?, ?, ?)'
        )->execute([$playerId, $game['id'], $game['start_location'], $game['start_minute'], $state->toJson()]);
    }

    /** A játék tulajdonság-definíciói (gyorsítótárazva egy kérésen belül) */
    private function statDefs(string $gameId): array
    {
        if (!isset($this->statDefCache[$gameId])) {
            $stmt = $this->db->prepare('SELECT * FROM game_stats WHERE game_id = ? ORDER BY sort_order, id');
            $stmt->execute([$gameId]);
            $this->statDefCache[$gameId] = $stmt->fetchAll();
        }

        return $this->statDefCache[$gameId];
    }

    private function state(array $save, string $gameId): State
    {
        return new State((string) $save['state'], $this->statDefs($gameId));
    }

    /** @param array<string, array{0:int,1:int}> $changes */
    private function describeChanges(string $gameId, array $changes): array
    {
        $labels = array_column($this->statDefs($gameId), 'label', 'id');
        $out = [];
        foreach ($changes as $id => [$from, $to]) {
            $out[] = ['tulajdonsag' => $labels[$id] ?? $id, 'elotte' => $from, 'utana' => $to];
        }

        return $out;
    }

    private function gameInfo(array $game): array
    {
        return [
            'id'             => $game['id'],
            'cim'            => $game['title'],
            'meseloi_stilus' => $game['narrator_style'],
        ];
    }

    private function describeLocation(string $gameId, string $locationId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM locations WHERE game_id = ? AND id = ?');
        $stmt->execute([$gameId, $locationId]);
        $loc = $stmt->fetch();

        $stmt = $this->db->prepare(
            'SELECT e.direction, e.description, l.name AS target
             FROM exits e JOIN locations l ON l.game_id = e.game_id AND l.id = e.to_location
             WHERE e.game_id = ? AND e.from_location = ? ORDER BY e.direction'
        );
        $stmt->execute([$gameId, $locationId]);
        $exits = array_map(fn (array $e) => [
            'irany'   => $e['direction'],
            'leiras'  => $e['description'],
            'celpont' => $e['target'],
        ], $stmt->fetchAll());

        $stmt = $this->db->prepare(
            'SELECT i.name, li.quantity FROM location_items li
             JOIN items i ON i.game_id = li.game_id AND i.id = li.item_id
             WHERE li.game_id = ? AND li.location_id = ? AND li.quantity > 0 ORDER BY i.name'
        );
        $stmt->execute([$gameId, $locationId]);
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
            'kep'       => $this->imageFor($gameId, $loc),
        ];
    }

    private function locationName(string $gameId, string $locationId): string
    {
        $stmt = $this->db->prepare('SELECT name FROM locations WHERE game_id = ? AND id = ?');
        $stmt->execute([$gameId, $locationId]);

        return (string) $stmt->fetchColumn();
    }

    /** @return list<string> */
    private function exitDirections(string $gameId, string $locationId): array
    {
        $stmt = $this->db->prepare('SELECT direction FROM exits WHERE game_id = ? AND from_location = ? ORDER BY direction');
        $stmt->execute([$gameId, $locationId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * A képpanel adatai (MCP Apps): a játékos aktuális állapota egy helyen.
     */
    private function panel(int $playerId, array $game): array
    {
        $save = $this->save($playerId, $game['id']);
        $loc  = $this->describeLocation($game['id'], $save['location_id']);

        $stmt = $this->db->prepare(
            'SELECT i.name, si.quantity FROM save_items si
             JOIN items i ON i.game_id = si.game_id AND i.id = si.item_id
             WHERE si.player_id = ? AND si.game_id = ? ORDER BY i.name'
        );
        $stmt->execute([$playerId, $game['id']]);

        return [
            'jatek'     => $game['title'],
            'helyszin'  => $loc['nev'],
            'kep'       => $loc['kep'],
            'ido'       => self::formatTime((int) $save['game_minute']),
            'tulajdonsagok' => $this->state($save, $game['id'])->statsView(),
            'leltar'    => array_map(
                fn (array $r) => ['nev' => $r['name'], 'darab' => (int) $r['quantity']],
                $stmt->fetchAll()
            ),
            'kijaratok' => array_map(
                fn (array $e) => ['irany' => Direction::label($e['irany']), 'celpont' => $e['celpont']],
                $loc['kijaratok']
            ),
            'zene'      => null, // később: a játék hangulatzene-oldala
        ];
    }

    /**
     * A helyszín képének nyilvános címe.
     * 1. ha az adatbázisban meg van adva (locations.image): azt használja
     *    (teljes URL, vagy az assets/images mappához képesti útvonal);
     * 2. különben megkeresi az assets/images/<játék>/<helyszín>.webp|jpg|jpeg|png fájlt.
     */
    private function imageFor(string $gameId, array $loc): ?string
    {
        if (!empty($loc['image'])) {
            return preg_match('#^https?://#', $loc['image'])
                ? $loc['image']
                : ($this->imageUrl !== null ? $this->imageUrl.'/'.ltrim($loc['image'], '/') : null);
        }

        if ($this->imageDir === null || $this->imageUrl === null) {
            return null;
        }

        foreach (self::IMAGE_EXTENSIONS as $ext) {
            $relative = $gameId.'/'.$loc['id'].'.'.$ext;
            $file = $this->imageDir.'/'.$relative;
            if (is_file($file)) {
                return $this->imageUrl.'/'.$relative.'?v='.filemtime($file); // a ?v= frissítéskor kikerüli a gyorsítótárat
            }
        }

        return null;
    }

    public static function formatTime(int $minute): string
    {
        $day = intdiv($minute, 1440) + 1;
        $inDay = $minute % 1440;

        return sprintf('%d. nap, %02d:%02d', $day, intdiv($inDay, 60), $inDay % 60);
    }

    /** Kisbetűs, ékezet- és írásjelmentes alak az összehasonlításhoz. */
    private static function simplify(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ö' => 'o', 'ő' => 'o', 'ú' => 'u', 'ü' => 'u', 'ű' => 'u']);

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $s));
    }
}
