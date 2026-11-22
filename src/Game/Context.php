<?php

declare(strict_types=1);

namespace AdventureGame\Game;

/**
 * Egy művelet munkaterülete: a mentés betöltött állapota, a tárgyvilág,
 * és ami a művelet közben történt (üzenetek a mesélőnek, változások, a játék vége).
 * A GameEngine a végén egyben menti el.
 */
final class Context
{
    /** @var list<string> a szabályok üzenetei a mesélőnek */
    public array $messages = [];
    /** @var array<string, array{0: float, 1: float}> tulajdonság => [előtte, utána] */
    public array $changes = [];
    /** @var list<string> a művelet után létrehozandó ellenőrző pontok */
    public array $checkpoints = [];
    public ?string $blocked = null;
    /** Közelkép a panelnek (megvizsgáláskor): ['kep' => url, 'cim' => név] */
    public ?array $closeup = null;
    /** Hány szabály teljesült (az óránkéntiek és az éjféliek nélkül) – a menj_ide ennél áll meg */
    public int $notable = 0;
    /** @var array<string, list<string>> helyszín => felszereltség */
    public array $featureMap = [];
    /** @var array<string, array<string, array>> helyszín => állapot => adatai (név, leírás, felszereltség) */
    public array $locationStates = [];
    /** A szabálymotor: az óránkénti és az éjféli szabályokhoz kell */
    public ?Rules $rules = null;
    /** A szereplők (a „jelen_van” feltételhez és a kereskedéshez) */
    public ?Npcs $npcs = null;
    private bool $ticking = false;

    public function __construct(
        public readonly int $playerId,
        public readonly array $game,
        public readonly array $settings,
        public readonly State $state,
        public readonly World $world,
        public string $location,
        public int $minute,
    ) {
    }

    public function gameId(): string
    {
        return $this->game['id'];
    }

    /**
     * Telik az idő: óráról órára haladva a tulajdonságok változnak, minden egész órakor
     * lefutnak az „orankent”, éjfélkor az „ejfelkor” szabályok, és nullázódnak a napi számlálók.
     */
    public function passTime(int $minutes): void
    {
        if ($minutes <= 0) {
            return;
        }
        if ($this->ticking || $this->rules === null) {
            $this->minute += $minutes;
            $this->record($this->state->passTime($minutes));
            return;
        }
        $this->ticking = true;
        try {
            $left = $minutes;
            while ($left > 0 && $this->state->end() === null) {
                $step = min($left, 60 - $this->minute % 60);
                $this->minute += $step;
                $left -= $step;
                $this->record($this->state->passTime($step));
                if ($this->minute % 60 === 0) {
                    $every = max(1, (int) ($this->settings['idojaras_valtozas_ora'] ?? 3));
                    if (intdiv($this->minute, 60) % $every === 0) {
                        $this->rollWeather();
                    }
                    if ($this->minute % 1440 === 0) {
                        $this->midnight();
                    }
                    $this->decay();
                    $this->weatherOnFires();
                    if ($this->minute % 1440 === 60 * (int) ($this->settings['keszletpotlas_ora'] ?? 6)) {
                        $this->state->resetPrefix('keszlet:'); // a kereskedők készlete reggel pótlódik
                    }
                    if ($this->minute % 1440 === 0) {
                        $this->state->resetDaily();
                        $this->rules->after($this, ['muvelet' => 'ejfelkor']);
                    }
                    $this->rules->after($this, ['muvelet' => 'orankent']);
                }
            }
        } finally {
            $this->ticking = false;
        }
    }

    public function addStat(string $id, float $delta): void
    {
        $before = $this->state->stat($id);
        $this->state->addStat($id, $delta);
        $this->record([$id => [$before, $this->state->stat($id)]]);
    }

    public function setStat(string $id, float $value): void
    {
        $before = $this->state->stat($id);
        $this->state->setStat($id, $value);
        $this->record([$id => [$before, $this->state->stat($id)]]);
    }

    /** @param array<string, array{0: float, 1: float}> $changes */
    private function record(array $changes): void
    {
        foreach ($changes as $id => [$from, $to]) {
            $this->changes[$id] = [$this->changes[$id][0] ?? $from, $to];
        }
    }

    /** @return list<string> az aktuális helyszín felszereltsége */
    public function features(): array
    {
        $f = $this->featuresOf($this->location);
        if ($this->fireAt($this->location)) {
            $f[] = 'tuz'; // ég itt egy tűz
        }

        return $f;
    }

    /** A helyszín aktuális állapota (pl. leegett), vagy null, ha az eredeti */
    public function locationState(string $location): ?string
    {
        $s = ($this->state->extra('allapot') ?? [])[$location] ?? null;

        return $s !== null && $s !== '' ? (string) $s : null;
    }

    public function setLocationState(string $location, ?string $state): void
    {
        $all = $this->state->extra('allapot') ?? [];
        if ($state === null || $state === '') {
            unset($all[$location]);
        } else {
            $all[$location] = $state;
        }
        $this->state->setExtra('allapot', $all ?: null);
    }

    /** A helyszín felszereltsége, az állapotát is figyelembe véve */
    public function featuresOf(string $location): array
    {
        $st = $this->locationState($location);
        $over = $st !== null ? ($this->locationStates[$location][$st]['features'] ?? null) : null;

        return is_array($over) ? $over : ($this->featureMap[$location] ?? []);
    }

    /** Ég-e tűz a helyszínen (meddig), a mentés szerint */
    public function fireAt(string $location): ?int
    {
        $until = (int) (($this->state->extra('tuz') ?? [])[$location] ?? 0);

        return $until > $this->minute ? $until : null;
    }

    // =====================================================================
    //  Időjárás, éjféli elszámolás (kölcsönök, rendszeres költségek)
    // =====================================================================

    public const WEATHER = ['derult' => 'derült', 'felhos' => 'felhős', 'esos' => 'esős', 'havas' => 'havas', 'kodos' => 'ködös', 'viharos' => 'viharos'];

    /** Új időjárás sorsolása a játék súlyai szerint (pl. derült 55, felhős 30, esős 15) */
    public function rollWeather(): void
    {
        $weights = array_filter((array) ($this->settings['idojaras'] ?? []), fn ($w) => (int) $w > 0);
        if (!$weights) {
            return;
        }
        $before = $this->state->weather();
        $roll = $this->state->random(1, (int) array_sum($weights));
        foreach ($weights as $w => $p) {
            $roll -= (int) $p;
            if ($roll <= 0) {
                $this->state->setWeather((string) $w);
                if ($before !== null && $before !== $w) {
                    $this->messages[] = sprintf('Megváltozott az időjárás: %s lett.', self::WEATHER[$w] ?? $w);
                }
                return;
            }
        }
    }

    /**
     * Romlás: a „romlik” jellemzőjű tárgyak a koruk alapján átalakulnak (kifli → száraz kifli),
     * vagy ha nincs mivé, megsemmisülnek. Csak a játékosnál vagy a helyszínen történtekről szól.
     */
    private function decay(): void
    {
        foreach ($this->world->all() as $inst) {
            $rot = $this->world->props($inst['item_id'])['romlik'] ?? null;
            if (!is_array($rot) || empty($rot['ora']) || $this->minute - (int) $inst['created_minute'] < (int) round((float) $rot['ora'] * 60)) {
                continue;
            }
            $cur = $this->world->get((int) $inst['id']);
            if ($cur === null) {
                continue;
            }
            [$pt, $pid] = $this->world->rootPlace($cur);
            $seen = $pt === 'slot' || ($pt === 'location' && $pid === $this->location);
            $name = $this->world->name($cur);
            $to = (string) ($rot['mive'] ?? '');
            if ($to !== '' && $this->world->type($to) !== null) {
                $this->world->transform($cur, $to, $this->minute);
                if ($seen) {
                    $this->messages[] = sprintf('Az idő múlásával a(z) %s %s lett.', $name, $this->world->type($to)['name']);
                }
            } else {
                $this->world->decrease($cur, (int) $cur['quantity']);
                if ($seen) {
                    $this->messages[] = sprintf('A(z) %s teljesen tönkrement, ki kellett dobni.', $name);
                }
            }
        }
    }

    /** Az eső, a hó és a vihar eloltja a szabadban égő tüzet */
    private function weatherOnFires(): void
    {
        $fires = $this->state->extra('tuz') ?? [];
        if (!$fires || !in_array($this->state->weather(), ['esos', 'havas', 'viharos'], true)) {
            return;
        }
        foreach ($fires as $loc => $until) {
            if ($until > $this->minute && in_array('szabadter', $this->featuresOf((string) $loc), true)) {
                unset($fires[$loc]);
                if ($loc === $this->location) {
                    $this->messages[] = 'Az időjárás eloltotta a tüzet.';
                }
            }
        }
        $this->state->setExtra('tuz', $fires ?: null);
    }

    /** Éjfélkor: a lejárt kölcsönök késedelmi kamata, és a rendszeres költségek */
    private function midnight(): void
    {
        $money = $this->state->moneyStat();
        foreach ($this->state->loans() as $npc => $loan) {
            if ($this->minute > (int) $loan['hatarido']) {
                $loan['tartozas'] = (int) ceil($loan['tartozas'] * (1 + (int) ($loan['kesedelmi_kamat'] ?? 10) / 100));
                $loan['kesett_napok'] = (int) ($loan['kesett_napok'] ?? 0) + 1;
                $this->state->setLoan((string) $npc, $loan);
                $this->state->addRelation((string) $npc, -1);
                $rep = (string) ($this->settings['reputacio'] ?? '');
                if ($rep !== '' && $this->state->hasStat($rep)) {
                    $this->addStat($rep, -1);
                }
                $this->messages[] = sprintf('Lejárt egy kölcsön (%s): a tartozás késedelmi kamattal %d lett, a hitelező egyre türelmetlenebb.',
                    $loan['nev'] ?? $npc, $loan['tartozas']);
            }
        }

        $day = intdiv($this->minute, 1440);
        foreach ((array) ($this->settings['koltsegek'] ?? []) as $c) {
            $every = max(1, (int) ($c['gyakorisag_nap'] ?? 1));
            if (!is_array($c) || $day % $every !== 0 || !Rules::check((array) ($c['feltetelek'] ?? []), $this)) {
                continue;
            }
            $sum = (int) ($c['osszeg'] ?? 0);
            if ($money !== null && $this->state->stat($money) >= $sum) {
                $this->addStat($money, -$sum);
                $this->messages[] = sprintf('Levonták a rendszeres költséget: %s (%d).', $c['nev'] ?? 'költség', $sum);
            } else {
                Rules::apply((array) ($c['ha_nem_fizet'] ?? []), $this);
                $this->messages[] = (string) ($c['uzenet_nem_fizet'] ?? sprintf('Nem volt pénz erre: %s.', $c['nev'] ?? 'költség'));
            }
        }
    }

    /** Napszak: hajnal (4–7), nappal (7–18), este (18–22), ejszaka (22–4) */
    public function dayPart(): string
    {
        return self::dayPartOf($this->minute);
    }

    public static function dayPartOf(int $minute): string
    {
        $h = intdiv($minute % 1440, 60);

        return match (true) {
            $h >= 4 && $h < 7   => 'hajnal',
            $h >= 7 && $h < 18  => 'nappal',
            $h >= 18 && $h < 22 => 'este',
            default             => 'ejszaka',
        };
    }

    /** Nyitva van-e egy „06:00-19:00” (vesszővel több sáv is) alakú nyitvatartás szerint */
    public function isOpen(?string $hours): bool
    {
        if ($hours === null || trim($hours) === '') {
            return true;
        }
        $now = $this->timeOfDay();
        foreach (explode(',', $hours) as $band) {
            if (!preg_match('/^\s*(\d{1,2}):(\d{2})\s*[-–]\s*(\d{1,2}):(\d{2})\s*$/u', $band, $m)) {
                continue;
            }
            $from = (int) $m[1] * 60 + (int) $m[2];
            $to = (int) $m[3] * 60 + (int) $m[4];
            if ($from <= $to ? ($now >= $from && $now < $to) : ($now >= $from || $now < $to)) {
                return true;
            }
        }

        return false;
    }

    /** A nap perce (0–1439) */
    public function timeOfDay(): int
    {
        return $this->minute % 1440;
    }
}
