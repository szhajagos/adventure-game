<?php

declare(strict_types=1);

namespace AdventureGame\Game;

/**
 * Egy mentés rugalmas állapota (a saves.state JSON-mező).
 *
 *   stats     – a játék tulajdonságai (pénz, éhség, oxigén…), a game_stats tábla szerint
 *   flags     – igen/nem tények (beszélt-e valakivel, nyitva van-e egy ajtó…)
 *   relations – kapcsolat a szereplőkkel (pl. a pék: 3)
 *   counters  – számlálók (pl. hányszor vásárolt a péknél)
 *   visited   – a már bejárt helyszínek azonosítói
 *
 * Új kulcs bármikor bekerülhet; ha egy tulajdonság hiányzik (mert a játékhoz
 * később adták hozzá), a kezdőértékét használjuk.
 */
final class State
{
    /** @var array<string, array> a játék tulajdonság-definíciói id szerint */
    private array $defs;
    private array $data;

    /** @param list<array> $statDefs a game_stats sorai */
    public function __construct(string $json, array $statDefs)
    {
        $this->defs = array_column($statDefs, null, 'id');
        $data = json_decode($json ?: '{}', true);
        $this->data = is_array($data) ? $data : [];
        foreach (['stats', 'flags', 'relations', 'counters', 'quests', 'loans'] as $k) {
            if (!isset($this->data[$k]) || !is_array($this->data[$k])) {
                $this->data[$k] = [];
            }
        }
        if (!isset($this->data['visited']) || !is_array($this->data['visited'])) {
            $this->data['visited'] = [];
        }
    }

    /** Új mentés kezdőállapota a definíciók alapján */
    public static function initial(array $statDefs, string $startLocation): self
    {
        $s = new self('{}', $statDefs);
        foreach ($statDefs as $d) {
            $s->data['stats'][$d['id']] = (float) $d['start_value'];
        }
        $s->visit($startLocation);

        return $s;
    }

    public function toJson(): string
    {
        // Kerekítés a tároláshoz (a törtrészek az időalapú változásból jönnek)
        $out = $this->data;
        foreach ($out['stats'] as $k => $v) {
            $out['stats'][$k] = round((float) $v, 2);
        }
        if ($out['flags'] === []) { $out['flags'] = new \stdClass(); }
        if ($out['relations'] === []) { $out['relations'] = new \stdClass(); }
        if ($out['counters'] === []) { $out['counters'] = new \stdClass(); }
        if ($out['stats'] === []) { $out['stats'] = new \stdClass(); }
        if ($out['quests'] === []) { $out['quests'] = new \stdClass(); }
        if ($out['loans'] === []) { $out['loans'] = new \stdClass(); }

        return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    // --- tulajdonságok ------------------------------------------------------

    public function stat(string $id): float
    {
        if (array_key_exists($id, $this->data['stats'])) {
            return (float) $this->data['stats'][$id];
        }

        return (float) ($this->defs[$id]['start_value'] ?? 0);
    }

    public function setStat(string $id, float $value): void
    {
        $this->data['stats'][$id] = $this->clamp($id, $value);
    }

    public function addStat(string $id, float $delta): void
    {
        $this->setStat($id, $this->stat($id) + $delta);
    }

    /** A játékidő múlásának hatása (pl. éhség +30/óra, oxigén −12/óra). @return array<string, array{0: int, 1: int}> a változások */
    public function passTime(int $minutes): array
    {
        $changes = [];
        foreach ($this->defs as $id => $d) {
            $rate = (float) $d['per_hour'];
            if ($rate == 0.0 || $minutes <= 0) {
                continue;
            }
            $before = $this->stat($id);
            $this->setStat($id, $before + $rate * $minutes / 60);
            $after = $this->stat($id);
            if ((int) round($before) !== (int) round($after)) {
                $changes[$id] = [(int) round($before), (int) round($after)];
            }
        }

        return $changes;
    }

    /** A pénz-típusú tulajdonság azonosítója (ha van) */
    public function moneyStat(): ?string
    {
        foreach ($this->defs as $id => $d) {
            if ($d['kind'] === 'money') {
                return $id;
            }
        }

        return null;
    }

    /**
     * A tulajdonságok a játékosnak és a panelnek: definíció + aktuális érték.
     * @return list<array>
     */
    public function statsView(bool $includeHidden = false): array
    {
        $defs = array_values($this->defs);
        usort($defs, fn ($a, $b) => [$a['sort_order'], $a['id']] <=> [$b['sort_order'], $b['id']]);
        $out = [];
        foreach ($defs as $d) {
            if ($d['kind'] === 'hidden' && !$includeHidden) {
                continue;
            }
            $out[] = [
                'id'     => $d['id'],
                'nev'    => $d['label'],
                'ertek'  => (int) round($this->stat($d['id'])),
                'min'    => $d['min_value'] === null ? null : (int) $d['min_value'],
                'max'    => $d['max_value'] === null ? null : (int) $d['max_value'],
                'tipus'  => $d['kind'],
                'egyseg' => $d['unit'],
            ];
        }

        return $out;
    }

    // --- jelzők, kapcsolatok, számlálók, bejárt helyek --------------------------

    public function flag(string $key): mixed
    {
        return $this->data['flags'][$key] ?? null;
    }

    public function setFlag(string $key, mixed $value = true): void
    {
        $this->data['flags'][$key] = $value;
    }

    public function relation(string $npc): int
    {
        return (int) ($this->data['relations'][$npc] ?? 0);
    }

    public function addRelation(string $npc, int $delta): void
    {
        $this->data['relations'][$npc] = $this->relation($npc) + $delta;
    }

    public function counter(string $key): int
    {
        return (int) ($this->data['counters'][$key] ?? 0);
    }

    public function increment(string $key, int $by = 1): void
    {
        $this->data['counters'][$key] = $this->counter($key) + $by;
    }

    public function visited(string $locationId): bool
    {
        return in_array($locationId, $this->data['visited'], true);
    }

    public function visit(string $locationId): void
    {
        if (!$this->visited($locationId)) {
            $this->data['visited'][] = $locationId;
        }
    }

    // --- a játék vége ----------------------------------------------------------

    /** @return array{tipus: string, szoveg: string}|null */
    public function end(): ?array
    {
        return isset($this->data['vege']) && is_array($this->data['vege']) ? $this->data['vege'] : null;
    }

    public function setEnd(?array $end): void
    {
        if ($end === null) {
            unset($this->data['vege']);
        } else {
            $this->data['vege'] = $end;
        }
    }

    public function hasStat(string $id): bool
    {
        return isset($this->defs[$id]);
    }

    /** A tulajdonság óránkénti változása (a game_stats per_hour mezője) */
    public function perHour(string $id): float
    {
        return (float) ($this->defs[$id]['per_hour'] ?? 0);
    }

    public function maxOf(string $id): ?float
    {
        $v = $this->defs[$id]['max_value'] ?? null;

        return $v === null ? null : (float) $v;
    }

    public function minOf(string $id): ?float
    {
        $v = $this->defs[$id]['min_value'] ?? null;

        return $v === null ? null : (float) $v;
    }

    // --- megbízások, kölcsönök, időjárás ----------------------------------------

    /** @return array{allapot: string, hatarido?: ?int}|null */
    public function quest(string $id): ?array
    {
        return $this->data['quests'][$id] ?? null;
    }

    public function setQuest(string $id, ?array $q): void
    {
        if ($q === null) {
            unset($this->data['quests'][$id]);
        } else {
            $this->data['quests'][$id] = $q;
        }
    }

    /** @return array<string, array> */
    public function quests(): array
    {
        return $this->data['quests'];
    }

    /** @return array{tartozas: int, hatarido: int, kesett_napok?: int}|null */
    public function loan(string $npc): ?array
    {
        return $this->data['loans'][$npc] ?? null;
    }

    public function setLoan(string $npc, ?array $loan): void
    {
        if ($loan === null) {
            unset($this->data['loans'][$npc]);
        } else {
            $this->data['loans'][$npc] = $loan;
        }
    }

    /** @return array<string, array> */
    public function loans(): array
    {
        return $this->data['loans'];
    }

    public function weather(): ?string
    {
        return isset($this->data['idojaras']) ? (string) $this->data['idojaras'] : null;
    }

    public function setWeather(?string $w): void
    {
        $this->data['idojaras'] = $w;
    }

    /**
     * Mentéshez kötött véletlen szám: a mag és a sorszám a mentésben van, így egy dobást
     * nem lehet „újrakérni”, és egy hiba utólag visszakövethető.
     */
    public function random(int $min, int $max): int
    {
        if (!isset($this->data['rng']['mag'])) {
            $this->data['rng'] = ['mag' => bin2hex(random_bytes(8)), 'n' => 0];
        }
        $n = ++$this->data['rng']['n'];
        $x = hexdec(substr(hash('sha256', $this->data['rng']['mag'].':'.$n), 0, 12));

        return $min + (int) ($x % ($max - $min + 1));
    }

    /** Egy előtagú számlálók nullázása (pl. „keszlet:” a reggeli készletpótláskor) */
    public function resetPrefix(string $prefix): void
    {
        foreach (array_keys($this->data['counters']) as $k) {
            if (str_starts_with((string) $k, $prefix)) {
                unset($this->data['counters'][$k]);
            }
        }
    }

    /** Éjfélkor: a „napi_” kezdetű számlálók nullázódnak (pl. napi_pihenes) */
    public function resetDaily(): void
    {
        foreach (array_keys($this->data['counters']) as $k) {
            if (str_starts_with((string) $k, 'napi_')) {
                unset($this->data['counters'][$k]);
            }
        }
    }

    private function clamp(string $id, float $v): float
    {
        $d = $this->defs[$id] ?? null;
        if ($d) {
            if ($d['min_value'] !== null) {
                $v = max((float) $d['min_value'], $v);
            }
            if ($d['max_value'] !== null) {
                $v = min((float) $d['max_value'], $v);
            }
        }

        return $v;
    }
}
