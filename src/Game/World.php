<?php

declare(strict_types=1);

namespace AdventureGame\Game;

use PDO;

/**
 * Egy mentés tárgyvilága: a tárgypéldányok és a helyük.
 *
 *   location  – egy helyszínen (place_id = helyszín)
 *   slot      – a játékosnál: bal_kez, jobb_kez, zseb, hat
 *   container – egy másik példányban (place_id = a tároló példány azonosítója)
 */
final class World
{
    public const SLOTS = ['bal_kez' => 'bal kéz', 'jobb_kez' => 'jobb kéz', 'zseb' => 'zseb', 'hat' => 'hát'];

    /** @var array<string, array> tárgytípusok id szerint (props dekódolva) */
    private array $types;
    /** @var array<int, array> példányok id szerint */
    private array $inst = [];

    public function __construct(
        private readonly PDO $db,
        private readonly int $playerId,
        private readonly string $gameId,
        private readonly array $settings,
    ) {
        $stmt = $db->prepare('SELECT * FROM items WHERE game_id = ?');
        $stmt->execute([$gameId]);
        $this->types = [];
        foreach ($stmt->fetchAll() as $t) {
            $t['props'] = json_decode((string) ($t['props'] ?? '{}'), true) ?: [];
            $this->types[$t['id']] = $t;
        }
        $this->reload();
    }

    public function reload(): void
    {
        $stmt = $this->db->prepare('SELECT * FROM item_instances WHERE player_id = ? AND game_id = ? ORDER BY id');
        $stmt->execute([$this->playerId, $this->gameId]);
        $this->inst = [];
        foreach ($stmt->fetchAll() as $i) {
            $this->inst[(int) $i['id']] = $i;
        }
    }

    /** A mentés világának létrehozása a játék kezdeti tárgyaiból (új játéknál, vagy régi mentésnél először). */
    public function initFromTemplate(int $minute): void
    {
        $stmt = $this->db->prepare('SELECT location_id, item_id, quantity FROM location_items WHERE game_id = ?');
        $stmt->execute([$this->gameId]);
        foreach ($stmt->fetchAll() as $li) {
            $this->create($li['item_id'], (int) $li['quantity'], 'location', $li['location_id'], $minute);
        }
    }

    // =====================================================================
    //  Lekérdezések
    // =====================================================================

    public function type(string $itemId): ?array
    {
        return $this->types[$itemId] ?? null;
    }

    public function name(array $inst): string
    {
        return (string) ($this->types[$inst['item_id']]['name'] ?? $inst['item_id']);
    }

    public function size(array $inst): int
    {
        return max(0, (int) ($this->types[$inst['item_id']]['size'] ?? 1));
    }

    public function props(string $itemId): array
    {
        return $this->types[$itemId]['props'] ?? [];
    }

    public function tags(string $itemId): array
    {
        return (array) ($this->props($itemId)['cimkek'] ?? []);
    }

    /** @return list<array> a helyen közvetlenül lévő példányok */
    public function at(string $placeType, string $placeId): array
    {
        return array_values(array_filter($this->inst, fn ($i) => $i['place_type'] === $placeType && $i['place_id'] === $placeId));
    }

    /** @return list<array> a példány teljes tartalma (rekurzívan) */
    public function contentsDeep(int $containerId): array
    {
        $out = [];
        foreach ($this->at('container', (string) $containerId) as $c) {
            $out[] = $c;
            array_push($out, ...$this->contentsDeep((int) $c['id']));
        }

        return $out;
    }

    /** @return list<array> minden, ami a játékosnál van (zsebben, kézben, háton, és azok tárolóiban) */
    public function carried(): array
    {
        $out = [];
        foreach (array_keys(self::SLOTS) as $slot) {
            foreach ($this->at('slot', $slot) as $i) {
                $out[] = $i;
                array_push($out, ...$this->contentsDeep((int) $i['id']));
            }
        }

        return $out;
    }

    /** @return list<array> minden a helyszínen (a helyszínen hagyott tárolók tartalmával együtt) */
    public function atLocationDeep(string $locationId): array
    {
        $out = [];
        foreach ($this->at('location', $locationId) as $i) {
            $out[] = $i;
            array_push($out, ...$this->contentsDeep((int) $i['id']));
        }

        return $out;
    }

    public function countCarried(string $itemId): int
    {
        return array_sum(array_map(fn ($i) => (int) $i['quantity'], array_filter($this->carried(), fn ($i) => $i['item_id'] === $itemId)));
    }

    public function countAt(string $locationId, string $itemId): int
    {
        return array_sum(array_map(fn ($i) => (int) $i['quantity'], array_filter($this->atLocationDeep($locationId), fn ($i) => $i['item_id'] === $itemId)));
    }

    public function get(int $id): ?array
    {
        return $this->inst[$id] ?? null;
    }

    /** A példány helyének leírása a játékosnak („a bal kezedben”, „a nejlonszatyorban”) */
    public function whereLabel(array $inst): string
    {
        return match ($inst['place_type']) {
            'slot'      => self::SLOTS[$inst['place_id']] ?? $inst['place_id'],
            'container' => ($p = $this->get((int) $inst['place_id'])) ? $this->name($p) : 'tároló',
            default     => 'a földön',
        };
    }

    /** A leltár fastruktúrában (a panelnek és a mesélőnek) */
    public function inventoryTree(): array
    {
        $out = [];
        foreach (array_keys(self::SLOTS) as $slot) {
            foreach ($this->at('slot', $slot) as $i) {
                $out[] = $this->node($i, self::SLOTS[$slot]);
            }
        }

        return $out;
    }

    /** A helyszín tárgyai fastruktúrában */
    public function locationTree(string $locationId): array
    {
        return array_map(fn ($i) => $this->node($i, null), $this->at('location', $locationId));
    }

    private function node(array $i, ?string $where): array
    {
        $n = ['nev' => $this->name($i), 'darab' => (int) $i['quantity']];
        if ($where !== null) {
            $n['hol'] = $where;
        }
        $inside = $this->at('container', (string) $i['id']);
        if ($inside || isset($this->props($i['item_id'])['tarolo'])) {
            $n['tartalom'] = array_map(fn ($c) => $this->node($c, null), $inside);
        }

        return $n;
    }

    // =====================================================================
    //  Keresés név szerint (a játékos szavaiból)
    // =====================================================================

    /**
     * @param list<array> $candidates
     * @return array{0: ?array, 1: list<string>} [a talált példány, egyébként a szóba jöhető nevek]
     */
    public function findByName(string $query, array $candidates): array
    {
        $q = self::simplify($query);
        if ($q === '') {
            return [null, []];
        }
        $exact = [];
        $partial = [];
        foreach ($candidates as $c) {
            $name = self::simplify($this->name($c));
            $id = self::simplify($c['item_id']);
            if ($name === $q || $id === $q) {
                $exact[$c['item_id']][] = $c;
            } elseif (str_contains($name, $q) || str_contains($q, $name) || str_contains($id, $q)) {
                $partial[$c['item_id']][] = $c;
            }
        }
        $pool = $exact ?: $partial;
        if (count($pool) === 1) {
            return [array_values($pool)[0][0], []];
        }

        return [null, array_map(fn ($list) => $this->name($list[0]), array_values($pool))];
    }

    // =====================================================================
    //  Elhelyezés és kapacitás
    // =====================================================================

    /** Elfér-e $qty darab a megadott helyen. @return string|null a hiba oka */
    public function fits(array $inst, int $qty, string $placeType, string $placeId): ?string
    {
        $size = $this->size($inst) * $qty;
        $handMax = (int) ($this->settings['kez_meret'] ?? 4);
        $pocket = (int) ($this->settings['zseb_kapacitas'] ?? 2);
        $props = $this->props($inst['item_id']);

        if ($placeType === 'slot') {
            $here = array_filter($this->at('slot', $placeId), fn ($i) => (int) $i['id'] !== (int) $inst['id']);
            return match ($placeId) {
                'bal_kez', 'jobb_kez' => $here && reset($here)['item_id'] !== $inst['item_id']
                    ? 'A kezedben már van valami.'
                    : ($this->used($here) + $size > $handMax ? 'Ez túl nagy vagy túl sok ahhoz, hogy egy kézben vidd.' : null),
                'zseb' => $this->used($here) + $size > $pocket ? 'Nem fér el a zsebedben.' : null,
                'hat'  => ($props['hely'] ?? null) !== 'hat' ? 'A hátadon csak hátizsákot vihetsz.' : ($here ? 'Már van valami a hátadon.' : null),
                default => 'Ismeretlen hely.',
            };
        }

        if ($placeType === 'container') {
            $box = $this->get((int) $placeId);
            $boxProps = $box ? $this->props($box['item_id']) : [];
            if (!$box || !isset($boxProps['tarolo'])) {
                return 'Abba nem lehet semmit tenni.';
            }
            if ((int) $box['id'] === (int) $inst['id'] || in_array((int) $box['id'], array_map(fn ($c) => (int) $c['id'], $this->contentsDeep((int) $inst['id'])), true)) {
                return 'Önmagába nem teheted.';
            }
            $accepts = (array) ($boxProps['tarolo']['befogad'] ?? []);
            $needs = $props['tarolot_igenyel'] ?? null;
            if ($needs !== null && !in_array($needs, $accepts, true)) {
                return 'Ebben nem tárolható.';
            }
            if ($accepts !== [] && $needs === null && !array_intersect($accepts, $this->tags($inst['item_id']))) {
                return 'Ebben nem tárolható.';
            }
            $here = array_filter($this->at('container', (string) $box['id']), fn ($i) => (int) $i['id'] !== (int) $inst['id']);

            return $this->used($here) + $size > (int) ($boxProps['tarolo']['kapacitas'] ?? 0) ? 'Nem fér el benne.' : null;
        }

        return null; // a helyszínen mindig elfér
    }

    /** Hova kerüljön egy felvett tárgy, ha a játékos nem mondta meg. @return array{0:?string,1:?string,2:?string} [típus, hely, hiba] */
    public function autoPlace(array $inst, int $qty): array
    {
        $props = $this->props($inst['item_id']);
        $tags = $this->tags($inst['item_id']);

        if (($props['hely'] ?? null) === 'hat' && $this->fits($inst, $qty, 'slot', 'hat') === null) {
            return ['slot', 'hat', null];
        }
        $tries = [];
        if (in_array('taska', $tags, true)) {          // táskát kézben viszünk
            $tries[] = ['slot', 'jobb_kez'];
            $tries[] = ['slot', 'bal_kez'];
        }
        foreach (['hat', 'jobb_kez', 'bal_kez'] as $slot) {  // a nálunk lévő tárolók
            foreach ($this->at('slot', $slot) as $box) {
                if (isset($this->props($box['item_id'])['tarolo'])) {
                    $tries[] = ['container', (string) $box['id']];
                }
            }
        }
        $tries[] = ['slot', 'zseb'];
        $tries[] = ['slot', 'jobb_kez'];
        $tries[] = ['slot', 'bal_kez'];

        foreach ($tries as [$type, $place]) {
            if ($this->fits($inst, $qty, $type, $place) === null) {
                return [$type, $place, null];
            }
        }

        return [null, null, 'Nincs hová tenned: tele a kezed, a zsebed és a tárolóid.'];
    }

    private function used(array $instances): int
    {
        return array_sum(array_map(fn ($i) => $this->size($i) * (int) $i['quantity'], $instances));
    }

    // =====================================================================
    //  Módosítások
    // =====================================================================

    /** $qty darabot áthelyez; ha a célhelyen ugyanilyen tárgy van, összevonja. @return int az új helyen lévő példány */
    public function move(array $inst, int $qty, string $placeType, string $placeId): int
    {
        $qty = min($qty, (int) $inst['quantity']);
        $hasContents = (bool) $this->at('container', (string) $inst['id']);
        $merge = null;
        if (!$hasContents) {
            foreach ($this->at($placeType, $placeId) as $other) {
                if ($other['item_id'] === $inst['item_id'] && (int) $other['id'] !== (int) $inst['id'] && !$this->at('container', (string) $other['id'])) {
                    $merge = $other;
                    break;
                }
            }
        }

        if ($merge) {
            $this->db->prepare('UPDATE item_instances SET quantity = quantity + ? WHERE id = ?')->execute([$qty, $merge['id']]);
            $this->decrease($inst, $qty);
            $target = (int) $merge['id'];
        } elseif ($qty < (int) $inst['quantity']) {
            $this->decrease($inst, $qty);
            $target = $this->create($inst['item_id'], $qty, $placeType, $placeId, (int) $inst['created_minute']);
        } else {
            $this->db->prepare('UPDATE item_instances SET place_type = ?, place_id = ? WHERE id = ?')->execute([$placeType, $placeId, $inst['id']]);
            $target = (int) $inst['id'];
        }
        $this->reload();

        return $target;
    }

    public function create(string $itemId, int $qty, string $placeType, string $placeId, int $minute): int
    {
        $this->db->prepare(
            'INSERT INTO item_instances (player_id, game_id, item_id, quantity, place_type, place_id, created_minute) VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$this->playerId, $this->gameId, $itemId, $qty, $placeType, $placeId, $minute]);
        $id = (int) $this->db->lastInsertId();
        $this->reload();

        return $id;
    }

    /** $qty darab eltávolítása (elfogyott, odaadta…); ha elfogy, a tartalma a helyére kerül. */
    public function decrease(array $inst, int $qty): void
    {
        if ($qty >= (int) $inst['quantity']) {
            $this->db->prepare('UPDATE item_instances SET place_type = ?, place_id = ? WHERE place_type = ? AND place_id = ? AND player_id = ? AND game_id = ?')
                ->execute([$inst['place_type'], $inst['place_id'], 'container', (string) $inst['id'], $this->playerId, $this->gameId]);
            $this->db->prepare('DELETE FROM item_instances WHERE id = ?')->execute([$inst['id']]);
        } else {
            $this->db->prepare('UPDATE item_instances SET quantity = quantity - ? WHERE id = ?')->execute([$qty, $inst['id']]);
        }
        $this->reload();
    }

    /** Átalakítás: a példány más tárgytípussá válik (pl. száraz kifli, ázott könyv) */
    public function transform(array $inst, string $toItemId): void
    {
        $this->db->prepare('UPDATE item_instances SET item_id = ? WHERE id = ?')->execute([$toItemId, $inst['id']]);
        $this->reload();
    }

    // =====================================================================
    //  Pillanatkép (ellenőrző pontokhoz)
    // =====================================================================

    public function snapshot(): array
    {
        return array_values(array_map(fn ($i) => [
            'id' => (int) $i['id'], 'item_id' => $i['item_id'], 'quantity' => (int) $i['quantity'],
            'place_type' => $i['place_type'], 'place_id' => $i['place_id'],
            'created_minute' => (int) $i['created_minute'], 'wear' => (int) $i['wear'], 'props' => $i['props'],
        ], $this->inst));
    }

    public function restore(array $snapshot): void
    {
        $this->db->prepare('DELETE FROM item_instances WHERE player_id = ? AND game_id = ?')->execute([$this->playerId, $this->gameId]);
        $map = [];
        $pending = $snapshot;
        // A tárolókat előbb kell létrehozni, mint a tartalmukat (az új azonosítók miatt)
        for ($round = 0; $pending && $round < 20; $round++) {
            $next = [];
            foreach ($pending as $s) {
                if ($s['place_type'] === 'container' && !isset($map[(int) $s['place_id']])) {
                    $next[] = $s;
                    continue;
                }
                if (!isset($this->types[$s['item_id']])) {
                    continue; // azóta törölt tárgytípus
                }
                $place = $s['place_type'] === 'container' ? (string) $map[(int) $s['place_id']] : $s['place_id'];
                $this->db->prepare(
                    'INSERT INTO item_instances (player_id, game_id, item_id, quantity, place_type, place_id, created_minute, wear, props) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([$this->playerId, $this->gameId, $s['item_id'], $s['quantity'], $s['place_type'], $place, $s['created_minute'], $s['wear'], $s['props'] ?: '{}']);
                $map[(int) $s['id']] = (int) $this->db->lastInsertId();
            }
            $pending = $next;
        }
        $this->reload();
    }

    public static function simplify(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ö' => 'o', 'ő' => 'o', 'ú' => 'u', 'ü' => 'u', 'ű' => 'u']);
        $s = (string) preg_replace('/^(a|az|egy)\s+/', '', $s);

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $s));
    }
}
