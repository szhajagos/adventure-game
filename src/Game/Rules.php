<?php

declare(strict_types=1);

namespace AdventureGame\Game;

use PDO;

/**
 * Szabálymotor: kiváltó művelet → feltételek → hatások → üzenet.
 *
 * Egy feltétel vagy hatás egy JSON-objektum, egy (vagy több) kulccsal, pl.
 *   {"van_nala": "csavarhuzo"}   {"tulajdonsag": ["ehseg", ">=", 80]}   {"jelzo": ["ajto_nyitva", true]}
 * Egy szabály akkor teljesül, ha MINDEN feltétele igaz.
 *
 * A feltételeket a művelet ELŐTT értékeljük ki. Ha egy teljesülő szabályban
 * „tiltas” hatás van, a művelet elmarad; különben a hatások a művelet UTÁN érvényesülnek.
 */
final class Rules
{
    public const TRIGGERS = [
        'felvesz'    => 'felvesz egy tárgyat',
        'letesz'     => 'letesz egy tárgyat',
        'belerak'    => 'belerak valamit egy tárolóba',
        'kivesz'     => 'kivesz valamit egy tárolóból',
        'megvizsgal' => 'megvizsgál egy tárgyat',
        'fogyaszt'   => 'megeszik vagy megiszik valamit',
        'menj'       => 'elindulna egy irányba (még az indulás előtt; a tiltás megállítja)',
        'megerkezik' => 'megérkezik egy helyszínre',
        'alszik'       => 'lefekszik aludni (a tiltás megakadályozza)',
        'pihen'        => 'megpihen egy kicsit',
        'tisztalkodik' => 'megmosakszik',
        'var'          => 'vár, hagyja telni az időt',
        'orankent'     => '– minden egész órában (az idő múlásával magától)',
        'ejfelkor'     => '– minden éjfélkor (a napi számlálók nullázása után)',
    ];

    /** feltétel kulcsa => [az érték alakja, leírás] (a szerkesztő súgójához is) */
    public const CONDITIONS = [
        'targy'         => ['"tárgy_id"', 'a művelet tárgya ez (felvesz, letesz, megvizsgál, fogyaszt, belerak, kivesz)'],
        'tarolo'        => ['"tárgy_id"', 'a belerak / kivesz művelet tárolója ez'],
        'cimke'         => ['"címke"', 'a művelet tárgyának van ilyen címkéje'],
        'irany'         => ['"irány"', 'a menj művelet iránya (eszak, del, kelet, nyugat, fel, le, be, ki)'],
        'helyszin'      => ['"helyszín_id" vagy ["id1", "id2"]', 'a játékos ezen a helyszínen van'],
        'van_nala'      => ['"tárgy_id" vagy ["tárgy_id", darab]', 'a játékosnál van (kézben, zsebben, táskában)'],
        'nincs_nala'    => ['"tárgy_id"', 'a játékosnál nincs ilyen'],
        'van_itt'       => ['"tárgy_id"', 'a helyszínen van ilyen (a földön vagy egy itt hagyott tárolóban)'],
        'jelzo'         => ['"kulcs" vagy ["kulcs", érték]', 'a jelző be van állítva (vagy ennyi az értéke)'],
        'nincs_jelzo'   => ['"kulcs"', 'a jelző nincs beállítva'],
        'tulajdonsag'   => ['["id", "<=", szám]', 'egy tulajdonság összevetése (<, <=, >, >=, =, !=)'],
        'szamlalo'      => ['["kulcs", ">=", szám]', 'egy számláló összevetése (a „napi_” kezdetűek éjfélkor nullázódnak)'],
        'felszereltseg' => ['"címke"', 'a helyszín felszereltsége tartalmazza (pl. mosdo, bolt)'],
        'napszak'       => ['"ejszaka" vagy ["este", "ejszaka"]', 'napszak: hajnal (4–7), nappal (7–18), este (18–22), ejszaka (22–4)'],
        'ido_kozott'    => ['["06:00", "09:00"]', 'a játékbeli idő e két időpont között van (átnyúlhat éjfélen)'],
        'latogatott'    => ['"helyszín_id"', 'a játékos járt már ezen a helyszínen'],
        'veletlen'      => ['szám (0–100)', 'ennyi százalék eséllyel teljesül'],
    ];

    public const EFFECTS = [
        'tulajdonsag'         => ['["id", változás]', 'a tulajdonság változik (pl. ["ehseg", -20])'],
        'tulajdonsag_beallit' => ['["id", érték]', 'a tulajdonság új értéket kap'],
        'jelzo'               => ['"kulcs" vagy ["kulcs", érték]', 'jelző beállítása (false: törlés). A "kep:helyszín_id" jelző a helyszín képváltozatát választja: ["kep:park_pad", "ejjel"] → park_pad~ejjel.webp'],
        'szamlalo'            => ['"kulcs" vagy ["kulcs", változás]', 'a számláló nő (alapból 1-gyel)'],
        'kapcsolat'           => ['["szereplő", változás]', 'a kapcsolat egy szereplővel javul vagy romlik'],
        'targy_letrehoz'      => ['"tárgy_id" vagy {"targy": "id", "darab": 1, "hova": "nala"}', 'új tárgy keletkezik (hova: nala vagy itt)'],
        'targy_elvesz'        => ['"tárgy_id" vagy ["tárgy_id", darab]', 'a tárgy eltűnik (előbb a játékostól, aztán a helyszínről)'],
        'targy_atalakit'      => ['["miből", "mivé"]', 'a tárgy más tárggyá válik (pl. kifli → száraz kifli)'],
        'tiltas'              => ['"üzenet"', 'a művelet nem történik meg; az üzenet a magyarázat'],
        'uzenet'              => ['"szöveg"', 'üzenet a mesélőnek (a szabály saját üzenetén felül)'],
        'athelyez'            => ['"helyszín_id"', 'a játékos átkerül egy másik helyszínre'],
        'ido'                 => ['percek', 'ennyi idő telik el'],
        'ellenorzo_pont'      => ['"név"', 'ellenőrző pont: vereség után innen folytatható'],
        'jatek_vege'          => ['{"tipus": "gyozelem" vagy "vereseg", "szoveg": "…"}', 'a játék véget ér'],
    ];

    /** @var list<array> */
    private array $rules;

    public function __construct(PDO $db, string $gameId)
    {
        $stmt = $db->prepare('SELECT * FROM rules WHERE game_id = ? AND active = 1 ORDER BY priority, id');
        $stmt->execute([$gameId]);
        $this->rules = array_map(function (array $r) {
            $r['conditions'] = json_decode((string) $r['conditions'], true) ?: [];
            $r['effects'] = json_decode((string) $r['effects'], true) ?: [];
            return $r;
        }, $stmt->fetchAll());
    }

    /**
     * Egy esemény szabályainak lefuttatása a művelet köré.
     *
     * @param array{muvelet: string, targy?: ?string, tarolo?: ?string, irany?: ?string} $event
     * @param callable(): array $action a tényleges művelet; ['siker' => bool, ...] tömböt ad vissza
     */
    public function around(Context $ctx, array $event, callable $action): array
    {
        $matched = $this->matching($ctx, $event);

        foreach ($matched as $rule) {
            if ($this->hasEffect($rule, 'tiltas')) {
                $this->fire($ctx, $rule, $event);
                return ['siker' => false, 'esemeny' => $ctx->blocked ?? 'Ez most nem sikerül.'];
            }
        }

        $result = $action();
        if (!($result['siker'] ?? false)) {
            return $result;
        }
        foreach ($matched as $rule) {
            $this->fire($ctx, $rule, $event);
        }

        return $result;
    }

    /** Csak a hatások (pl. megérkezéskor) – a feltételeket most értékeli ki. */
    public function after(Context $ctx, array $event): void
    {
        foreach ($this->matching($ctx, $event) as $rule) {
            if (!$this->hasEffect($rule, 'tiltas')) {
                $this->fire($ctx, $rule, $event);
            }
        }
    }

    /** @return list<array> */
    private function matching(Context $ctx, array $event): array
    {
        return array_values(array_filter($this->rules, fn (array $r) =>
            $r['trigger_action'] === $event['muvelet']
            && !($r['once'] && $ctx->state->flag('_szabaly_'.$r['id']))
            && self::check($r['conditions'], $ctx, $event)));
    }

    private function hasEffect(array $rule, string $key): bool
    {
        foreach ($rule['effects'] as $e) {
            if (is_array($e) && array_key_exists($key, $e)) {
                return true;
            }
        }

        return false;
    }

    private function fire(Context $ctx, array $rule, array $event): void
    {
        if ($rule['once']) {
            $ctx->state->setFlag('_szabaly_'.$rule['id'], true);
        }
        self::apply($rule['effects'], $ctx, $event);
        if (!empty($rule['message'])) {
            $ctx->messages[] = (string) $rule['message'];
        }
    }

    // =====================================================================
    //  Feltételek
    // =====================================================================

    /** Minden feltétel teljesül-e. Ismeretlen feltétel: nem teljesül. */
    public static function check(array $conditions, Context $ctx, array $event = []): bool
    {
        foreach ($conditions as $cond) {
            if (!is_array($cond)) {
                return false;
            }
            foreach ($cond as $key => $val) {
                if (!self::one((string) $key, $val, $ctx, $event)) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function one(string $key, mixed $v, Context $ctx, array $event): bool
    {
        $w = $ctx->world;
        $st = $ctx->state;

        return match ($key) {
            'targy'         => ($event['targy'] ?? null) === $v,
            'tarolo'        => ($event['tarolo'] ?? null) === $v,
            'cimke'         => isset($event['targy']) && in_array($v, $w->tags($event['targy']), true),
            'irany'         => ($event['irany'] ?? null) === Direction::normalize((string) $v),
            'helyszin'      => in_array($ctx->location, (array) $v, true),
            'van_nala'      => $w->countCarried(is_array($v) ? (string) $v[0] : (string) $v) >= (is_array($v) ? (int) ($v[1] ?? 1) : 1),
            'nincs_nala'    => $w->countCarried((string) $v) === 0,
            'van_itt'       => $w->countAt($ctx->location, (string) $v) > 0,
            'jelzo'         => is_array($v) ? $st->flag((string) $v[0]) == ($v[1] ?? true) : (bool) $st->flag((string) $v),
            'nincs_jelzo'   => !$st->flag((string) $v),
            'tulajdonsag'   => is_array($v) && count($v) === 3 && self::compare($st->stat((string) $v[0]), (string) $v[1], (float) $v[2]),
            'szamlalo'      => is_array($v) && count($v) === 3 && self::compare($st->counter((string) $v[0]), (string) $v[1], (float) $v[2]),
            'felszereltseg' => in_array($v, $ctx->features(), true),
            'napszak'       => in_array($ctx->dayPart(), (array) $v, true),
            'ido_kozott'    => is_array($v) && count($v) === 2 && self::between($ctx->timeOfDay(), (string) $v[0], (string) $v[1]),
            'latogatott'    => $st->visited((string) $v),
            'veletlen'      => random_int(1, 100) <= (int) $v,
            default         => false,
        };
    }

    private static function compare(float $a, string $op, float $b): bool
    {
        return match ($op) {
            '<'       => $a < $b,
            '<='      => $a <= $b,
            '>'       => $a > $b,
            '>='      => $a >= $b,
            '=', '==' => abs($a - $b) < 0.0001,
            '!=', '<>' => abs($a - $b) >= 0.0001,
            default   => false,
        };
    }

    private static function between(int $minute, string $from, string $to): bool
    {
        $f = self::hm($from);
        $t = self::hm($to);

        return $f <= $t ? ($minute >= $f && $minute < $t) : ($minute >= $f || $minute < $t);
    }

    private static function hm(string $s): int
    {
        [$h, $m] = array_map('intval', explode(':', $s.':0'));

        return ($h * 60 + $m) % 1440;
    }

    // =====================================================================
    //  Hatások
    // =====================================================================

    public static function apply(array $effects, Context $ctx, array $event = []): void
    {
        foreach ($effects as $eff) {
            if (!is_array($eff)) {
                continue;
            }
            foreach ($eff as $key => $v) {
                self::effect((string) $key, $v, $ctx, $event);
            }
        }
    }

    private static function effect(string $key, mixed $v, Context $ctx, array $event): void
    {
        $w = $ctx->world;
        $st = $ctx->state;

        switch ($key) {
            case 'tulajdonsag':
                if (is_array($v) && $st->hasStat((string) $v[0])) {
                    $ctx->addStat((string) $v[0], (float) ($v[1] ?? 0));
                }
                break;
            case 'tulajdonsag_beallit':
                if (is_array($v) && $st->hasStat((string) $v[0])) {
                    $ctx->setStat((string) $v[0], (float) ($v[1] ?? 0));
                }
                break;
            case 'jelzo':
                is_array($v) ? $st->setFlag((string) $v[0], $v[1] ?? true) : $st->setFlag((string) $v, true);
                break;
            case 'szamlalo':
                is_array($v) ? $st->increment((string) $v[0], (int) ($v[1] ?? 1)) : $st->increment((string) $v);
                break;
            case 'kapcsolat':
                if (is_array($v)) {
                    $st->addRelation((string) $v[0], (int) ($v[1] ?? 1));
                }
                break;
            case 'targy_letrehoz':
                $id = is_array($v) ? (string) ($v['targy'] ?? '') : (string) $v;
                $qty = is_array($v) ? max(1, (int) ($v['darab'] ?? 1)) : 1;
                $where = is_array($v) ? ($v['hova'] ?? 'nala') : 'nala';
                if ($w->type($id) === null) {
                    break;
                }
                $new = $w->create($id, $qty, 'location', $ctx->location, $ctx->minute);
                if ($where === 'nala') {
                    $inst = $w->get($new);
                    [$type, $place] = $w->autoPlace($inst, $qty);
                    if ($type !== null) {
                        $w->move($inst, $qty, $type, $place);
                    } else {
                        $ctx->messages[] = sprintf('A(z) %s nem fért el a játékosnál, ezért a földre került.', $w->type($id)['name']);
                    }
                }
                break;
            case 'targy_elvesz':
                $id = is_array($v) ? (string) $v[0] : (string) $v;
                $left = is_array($v) ? max(1, (int) ($v[1] ?? 1)) : 1;
                foreach ([$w->carried(), $w->atLocationDeep($ctx->location)] as $pool) {
                    foreach ($pool as $inst) {
                        if ($left > 0 && $inst['item_id'] === $id) {
                            $cur = $w->get((int) $inst['id']);
                            if ($cur === null) {
                                continue;
                            }
                            $n = min($left, (int) $cur['quantity']);
                            $w->decrease($cur, $n);
                            $left -= $n;
                        }
                    }
                }
                break;
            case 'targy_atalakit':
                if (!is_array($v) || count($v) < 2 || $w->type((string) $v[1]) === null) {
                    break;
                }
                foreach (array_merge($w->carried(), $w->atLocationDeep($ctx->location)) as $inst) {
                    if ($inst['item_id'] === $v[0]) {
                        $w->transform($inst, (string) $v[1]);
                        break;
                    }
                }
                break;
            case 'tiltas':
                $ctx->blocked = (string) $v;
                break;
            case 'uzenet':
                $ctx->messages[] = (string) $v;
                break;
            case 'athelyez':
                $ctx->location = (string) $v;
                $st->visit((string) $v);
                break;
            case 'ido':
                $ctx->passTime((int) $v);
                break;
            case 'ellenorzo_pont':
                $ctx->checkpoints[] = (string) $v;
                break;
            case 'jatek_vege':
                $type = is_array($v) ? ($v['tipus'] ?? 'gyozelem') : (string) $v;
                $text = is_array($v) ? (string) ($v['szoveg'] ?? '') : '';
                $st->setEnd(['tipus' => $type === 'vereseg' ? 'vereseg' : 'gyozelem', 'szoveg' => $text]);
                break;
        }
    }

    // =====================================================================
    //  Ellenőrzés a szerkesztőnek
    // =====================================================================

    /** @return list<string> a hibák listája (üres: rendben) */
    public static function validate(string $json, array $allowed, string $what): array
    {
        if (trim($json) === '') {
            return [];
        }
        $data = json_decode($json, true);
        if (!is_array($data) || !array_is_list($data)) {
            return [sprintf('A(z) %s nem érvényes JSON-lista (szögletes zárójelek között, pl. [{"van_nala": "kulcs"}]).', $what)];
        }
        $errors = [];
        foreach ($data as $i => $item) {
            if (!is_array($item) || array_is_list($item) || $item === []) {
                $errors[] = sprintf('%s %d. eleme nem objektum (kapcsos zárójel kell: {"kulcs": érték}).', ucfirst($what), $i + 1);
                continue;
            }
            foreach (array_keys($item) as $k) {
                if (!isset($allowed[$k])) {
                    $errors[] = sprintf('Ismeretlen kulcs: „%s” (%s %d. eleme).', $k, $what, $i + 1);
                }
            }
        }

        return $errors;
    }
}
