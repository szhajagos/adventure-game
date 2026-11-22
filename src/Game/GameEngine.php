<?php

declare(strict_types=1);

namespace AdventureGame\Game;

use PDO;
use RuntimeException;

/**
 * A játékmotor: itt dől el, mi történik ténylegesen.
 * Az AI csak meghívja ezeket a műveleteket, és elmeséli az eredményt –
 * a játék állapotát (pénz, helyszín, éhség, tárgyak…) kizárólag ez az osztály módosítja.
 *
 * Egy szerveren több játék (világ) futhat. Minden játékosnak játékonként
 * külön mentése van (saját tárgyvilággal), és mindig az „aktuális” játékában lép.
 */
final class GameEngine
{
    private const IMAGE_EXTENSIONS = ['webp', 'jpg', 'jpeg', 'png'];

    /** A játék vége után is használható műveletek */
    private const AFTER_END = ['look', 'status', 'checkpoint'];

    public const DAY_PARTS = ['hajnal' => 'hajnal', 'nappal' => 'nappal', 'este' => 'este', 'ejszaka' => 'éjszaka'];

    /** Egyes műveletek időigénye percben */
    private const MINUTES = ['felvesz' => 1, 'letesz' => 1, 'belerak' => 1, 'kivesz' => 1, 'megvizsgal' => 2, 'fogyaszt' => 5, 'beszel' => 5, 'odaad' => 1, 'vesz' => 2];

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

    /** Az elérhető játékok listája, a játékos saját állásával. */
    public function listGames(int $playerId): array
    {
        $player = $this->player($playerId);

        $stmt = $this->db->prepare(
            'SELECT g.id, g.title, g.description, s.location_id, s.game_minute, s.updated_at, s.state, l.name AS location_name
             FROM games g
             LEFT JOIN saves s ON s.game_id = g.id AND s.player_id = ?
             LEFT JOIN locations l ON l.game_id = s.game_id AND l.id = s.location_id
             WHERE g.active = 1
             ORDER BY g.sort_order, g.title'
        );
        $stmt->execute([$playerId]);

        $games = array_map(function (array $g) use ($player) {
            $end = $g['state'] ? (json_decode((string) $g['state'], true)['vege'] ?? null) : null;
            return [
                'id'          => $g['id'],
                'cim'         => $g['title'],
                'leiras'      => $g['description'],
                'aktualis'    => $g['id'] === $player['current_game_id'],
                'sajat_allas' => $g['location_id'] === null ? null : [
                    'helyszin' => $g['location_name'],
                    'ido'      => self::formatTime((int) $g['game_minute']),
                    'utoljara' => $g['updated_at'],
                    'vege'     => $end['tipus'] ?? null,
                ],
            ];
        }, $stmt->fetchAll());

        return [
            'siker'   => true,
            'jatekok' => $games,
            'tipp'    => 'A játékos a jatek_inditasa eszközzel választhat. Ha van saját állása, ott folytatja.',
        ];
    }

    /** Játék kiválasztása: ha már van állása, ott folytatja, ha nincs, új mentés indul. */
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

        $ctx = $this->context($playerId, $game);
        $result = [
            'siker'    => true,
            'esemeny'  => $isNew
                ? 'Új játék kezdődött. Meséld el a bevezetőt, majd a helyszínt.'
                : 'A játékos folytatja a korábbi állását ebben a játékban.',
            'jatek'    => $this->gameInfo($game),
            'bevezeto' => $isNew ? $game['intro'] : null,
        ];

        return $this->finish($ctx, $result);
    }

    // =====================================================================
    //  Játékműveletek (mindig az aktuális játékban)
    // =====================================================================

    public function look(int $playerId): array
    {
        return $this->run($playerId, 'look', fn (Context $ctx) => [
            'siker' => true,
            'jatek' => $this->gameInfo($ctx->game),
        ]);
    }

    public function status(int $playerId): array
    {
        return $this->run($playerId, 'status', fn (Context $ctx) => [
            'siker'         => true,
            'jatek'         => $ctx->game['title'],
            'tulajdonsagok' => $ctx->state->statsView(),
            'leltar'        => $ctx->world->inventoryTree(),
            'kapacitas'     => $this->capacityView($ctx),
            'receptek'      => array_map(fn ($r) => $this->recipeView($ctx, $r), $this->knownRecipes($ctx)) ?: null,
        ], withLocation: false);
    }

    public function move(int $playerId, string $direction): array
    {
        return $this->run($playerId, 'menj', function (Context $ctx) use ($direction) {
            $dir = Direction::normalize($direction);
            $stmt = $this->db->prepare('SELECT * FROM exits WHERE game_id = ? AND from_location = ? AND direction = ?');
            $stmt->execute([$ctx->gameId(), $ctx->location, $dir]);
            $exit = $stmt->fetch();

            if (!$exit) {
                return [
                    'siker'              => false,
                    'esemeny'            => sprintf('Arra (%s) nem lehet menni innen.', $direction),
                    'lehetseges_iranyok' => $this->exitDirections($ctx->gameId(), $ctx->location),
                ];
            }

            return $this->step($ctx, $exit);
        });
    }

    /** Egy lépés egy kijáraton át: nyitvatartás, feltétel, szabályok, idő, megérkezés. */
    private function step(Context $ctx, array $exit): array
    {
        $dir = $exit['direction'];
        $event = ['muvelet' => 'menj', 'irany' => $dir];
        $target = $this->locationRow($ctx->gameId(), $exit['to_location']);
        if (!$ctx->isOpen($target['open_hours'] ?? null)) {
            return ['siker' => false, 'esemeny' => sprintf('Zárva: %s (nyitvatartás: %s).', $target['name'], $target['open_hours'])];
        }
        $conds = $exit['conditions'] ? (json_decode((string) $exit['conditions'], true) ?: []) : [];
        if ($conds && !Rules::check($conds, $ctx, $event)) {
            return ['siker' => false, 'esemeny' => $exit['blocked_message'] ?: 'Arra most nem tudsz továbbmenni.'];
        }

        $vehicle = $this->vehicle($ctx);
        if ($vehicle !== null) {
            $vp = $ctx->world->props($vehicle['item_id'])['jarmu'] ?? [];
            $roads = (array) ($vp['utak'] ?? ['szabadter']);
            if (!array_intersect($roads, $ctx->featureMap[$exit['to_location']] ?? [])) {
                return ['siker' => false, 'esemeny' => sprintf('A(z) %s nem mehet oda (%s). Előbb ki kell szállni.', $ctx->world->name($vehicle), $target['name'])];
            }
        }
        $rules = $this->rules($ctx);

        return $rules->around($ctx, $event, function () use ($ctx, $exit, $dir, $rules, $vehicle) {
            $minutes = (int) $exit['minutes'];
            if ($vehicle !== null) {
                $minutes = max(1, (int) ceil($minutes * (float) (($ctx->world->props($vehicle['item_id'])['jarmu'] ?? [])['sebesseg'] ?? 0.5)));
                $ctx->world->move($vehicle, (int) $vehicle['quantity'], 'location', $exit['to_location']);
            }
            $firstVisit = !$ctx->state->visited($exit['to_location']);
            $ctx->passTime($minutes);
            $ctx->location = $exit['to_location'];
            $ctx->state->visit($exit['to_location']);
            $rules->after($ctx, ['muvelet' => 'megerkezik']);

            return [
                'siker'          => true,
                'esemeny'        => sprintf('A játékos %s irányba indult (%s), az út %d percig tartott.',
                    Direction::label($dir), $exit['description'] ?? 'tovább', $minutes),
                'elso_latogatas' => $firstVisit,
                'perc'           => $minutes,
            ];
        });
    }

    /**
     * Elmegy egy már bejárt helyszínre: a motor a legrövidebb utat keresi a bejárt helyszíneken át,
     * és lépésenként végigmegy rajta. Megáll, ha útközben valami történik, egy hely zárva van,
     * vagy egy kijárat nem járható.
     */
    public function goTo(int $playerId, string $place): array
    {
        return $this->run($playerId, 'menj', function (Context $ctx) use ($place) {
            $stmt = $this->db->prepare('SELECT id, name FROM locations WHERE game_id = ?');
            $stmt->execute([$ctx->gameId()]);
            $known = array_filter($stmt->fetchAll(PDO::FETCH_KEY_PAIR), fn ($id) => $ctx->state->visited((string) $id), ARRAY_FILTER_USE_KEY);

            $q = World::simplify($place);
            $hits = [];
            foreach ($known as $id => $name) {
                $n = World::simplify($name);
                if ($q !== '' && ($n === $q || World::simplify((string) $id) === $q)) {
                    $hits = [$id => $name];
                    break;
                }
                if ($q !== '' && (str_contains($n, $q) || str_contains($q, $n))) {
                    $hits[$id] = $name;
                }
            }
            if (count($hits) !== 1) {
                return [
                    'siker'     => false,
                    'esemeny'   => $hits
                        ? sprintf('Több bejárt helyszínre is illik: „%s”. Melyikre gondol a játékos?', $place)
                        : sprintf('„%s”: ilyen helyen még nem járt a játékos (csak már bejárt helyre lehet így eljutni).', $place),
                    'bejart_helyszinek' => array_values($hits ?: $known),
                ];
            }
            $goal = (string) array_key_first($hits);
            if ($goal === $ctx->location) {
                return ['siker' => false, 'esemeny' => 'A játékos már itt van.'];
            }

            // Legrövidebb út (lépésszám szerint) a bejárt helyszíneken át
            $stmt = $this->db->prepare('SELECT * FROM exits WHERE game_id = ?');
            $stmt->execute([$ctx->gameId()]);
            $edges = [];
            foreach ($stmt->fetchAll() as $e) {
                if (isset($known[$e['from_location']], $known[$e['to_location']])) {
                    $edges[$e['from_location']][] = $e;
                }
            }
            $prev = [$ctx->location => null];
            $queue = [$ctx->location];
            while ($queue && !isset($prev[$goal])) {
                $cur = array_shift($queue);
                foreach ($edges[$cur] ?? [] as $e) {
                    if (!array_key_exists($e['to_location'], $prev)) {
                        $prev[$e['to_location']] = $e;
                        $queue[] = $e['to_location'];
                    }
                }
            }
            if (!isset($prev[$goal])) {
                return ['siker' => false, 'esemeny' => sprintf('A játékos nem ismer utat innen ide: %s.', $known[$goal])];
            }
            $path = [];
            for ($at = $goal; $prev[$at] !== null; $at = $prev[$at]['from_location']) {
                array_unshift($path, $prev[$at]);
            }

            // Végigmegy lépésenként; megáll, ha valami történik
            $route = [$known[$ctx->location]];
            $minutes = 0;
            foreach ($path as $i => $exit) {
                $before = $ctx->notable;
                $r = $this->step($ctx, $exit);
                if (!$r['siker']) {
                    return [
                        'siker'   => $i > 0,
                        'esemeny' => sprintf('A játékos elindult ide: %s (%s), de %s megállt. Ok: %s',
                            $known[$goal], implode(' → ', $route), $i > 0 ? 'útközben itt' : 'rögtön az elején', $r['esemeny']),
                        'megallt' => true,
                    ];
                }
                $route[] = $known[$exit['to_location']];
                $minutes += (int) $r['perc'];
                $last = $i === count($path) - 1;
                if (!$last && ($ctx->notable > $before || $ctx->checkpoints || $ctx->state->end() !== null)) {
                    return [
                        'siker'   => true,
                        'esemeny' => sprintf('A játékos elindult ide: %s, de útközben (%s, %d perc után) valami történt, ezért megállt: %s. Meséld el, mi történt.',
                            $known[$goal], implode(' → ', $route), $minutes, end($route)),
                        'megallt' => true,
                    ];
                }
            }

            return [
                'siker'   => true,
                'esemeny' => sprintf('A játékos %s ide: %s (%s), az út %d percig tartott. Az útvonal ismerős, elég röviden elmesélni.',
                    $ctx->state->extra('jarmu') !== null ? 'eljutott (járművel)' : 'elsétált', $known[$goal], implode(' → ', $route), $minutes),
            ];
        });
    }

    /** Tárgy felvétele a helyszínről (a földről vagy egy itt lévő tárolóból). */
    public function take(int $playerId, string $item, ?int $quantity = null, ?string $where = null): array
    {
        return $this->run($playerId, 'felvesz', function (Context $ctx) use ($item, $quantity, $where) {
            $w = $ctx->world;
            [$inst, $alts] = $w->findByName($item, $w->atLocationDeep($ctx->location));
            if ($inst === null) {
                return $this->notFound($item, $alts, 'itt');
            }
            $props = $w->props($inst['item_id']);
            if (!empty($props['nem_felveheto'])) {
                return ['siker' => false, 'esemeny' => sprintf('A(z) %s nem vehető fel.', $w->name($inst))];
            }
            $qty = max(1, min($quantity ?? (int) $inst['quantity'], (int) $inst['quantity']));

            return $this->rules($ctx)->around($ctx, $this->event('felvesz', $inst), function () use ($ctx, $inst, $qty, $where) {
                $w = $ctx->world;
                if ($where !== null && trim($where) !== '') {
                    [$type, $place, $err] = $this->resolvePlace($ctx, $where);
                    $err ??= $w->fits($inst, $qty, $type, $place);
                } else {
                    [$type, $place, $err] = $w->autoPlace($inst, $qty);
                }
                if ($err !== null) {
                    return ['siker' => false, 'esemeny' => $err];
                }
                $from = $inst['place_type'] === 'container' ? ' ('.$w->whereLabel($inst).')' : '';
                $id = $w->move($inst, $qty, $type, $place);
                $ctx->passTime(self::MINUTES['felvesz']);

                return [
                    'siker'   => true,
                    'esemeny' => sprintf('A játékos felvette: %s%s%s → %s.', $w->name($inst), $qty > 1 ? " ({$qty} db)" : '', $from, $w->whereLabel($w->get($id))),
                ];
            });
        });
    }

    /** Tárgy letétele a helyszínen. */
    public function drop(int $playerId, string $item, ?int $quantity = null): array
    {
        return $this->run($playerId, 'letesz', function (Context $ctx) use ($item, $quantity) {
            $w = $ctx->world;
            [$inst, $alts] = $w->findByName($item, $w->carried());
            if ($inst === null) {
                return $this->notFound($item, $alts, 'nala');
            }
            $qty = max(1, min($quantity ?? (int) $inst['quantity'], (int) $inst['quantity']));

            return $this->rules($ctx)->around($ctx, $this->event('letesz', $inst), function () use ($ctx, $inst, $qty) {
                $ctx->world->move($inst, $qty, 'location', $ctx->location);
                $ctx->passTime(self::MINUTES['letesz']);

                return ['siker' => true, 'esemeny' => sprintf('A játékos letette: %s%s.', $ctx->world->name($inst), $qty > 1 ? " ({$qty} db)" : '')];
            });
        });
    }

    /** Tárgy berakása egy tárolóba (ami a játékosnál vagy a helyszínen van). */
    public function putIn(int $playerId, string $item, string $container, ?int $quantity = null): array
    {
        return $this->run($playerId, 'belerak', function (Context $ctx) use ($item, $container, $quantity) {
            $w = $ctx->world;
            $reach = array_merge($w->carried(), $w->atLocationDeep($ctx->location));
            [$box, $alts] = $w->findByName($container, array_filter($reach, fn ($i) => isset($w->props($i['item_id'])['tarolo'])));
            if ($box === null) {
                return $this->notFound($container, $alts, 'tarolo');
            }
            [$inst, $alts] = $w->findByName($item, array_filter($reach, fn ($i) => (int) $i['id'] !== (int) $box['id']));
            if ($inst === null) {
                return $this->notFound($item, $alts, 'elerheto');
            }
            $qty = max(1, min($quantity ?? (int) $inst['quantity'], (int) $inst['quantity']));
            $event = $this->event('belerak', $inst) + ['tarolo' => $box['item_id']];

            return $this->rules($ctx)->around($ctx, $event, function () use ($ctx, $inst, $box, $qty) {
                $w = $ctx->world;
                if (($err = $w->fits($inst, $qty, 'container', (string) $box['id'])) !== null) {
                    return ['siker' => false, 'esemeny' => $err];
                }
                $w->move($inst, $qty, 'container', (string) $box['id']);
                $ctx->passTime(self::MINUTES['belerak']);

                return ['siker' => true, 'esemeny' => sprintf('A játékos belerakta: %s → %s.', $w->name($inst), $w->name($box))];
            });
        });
    }

    /** Tárgy kivétele egy tárolóból: a játékoshoz kerül. */
    public function takeOut(int $playerId, string $item, string $container, ?int $quantity = null): array
    {
        return $this->run($playerId, 'kivesz', function (Context $ctx) use ($item, $container, $quantity) {
            $w = $ctx->world;
            $reach = array_merge($w->carried(), $w->atLocationDeep($ctx->location));
            [$box, $alts] = $w->findByName($container, array_filter($reach, fn ($i) => isset($w->props($i['item_id'])['tarolo'])));
            if ($box === null) {
                return $this->notFound($container, $alts, 'tarolo');
            }
            [$inst, $alts] = $w->findByName($item, $w->at('container', (string) $box['id']));
            if ($inst === null) {
                return $this->notFound($item, $alts, 'tarolo_tartalma');
            }
            $qty = max(1, min($quantity ?? (int) $inst['quantity'], (int) $inst['quantity']));
            $event = $this->event('kivesz', $inst) + ['tarolo' => $box['item_id']];

            return $this->rules($ctx)->around($ctx, $event, function () use ($ctx, $inst, $box, $qty) {
                $w = $ctx->world;
                [$type, $place, $err] = $w->autoPlace($inst, $qty);
                // ha a kivett tárgy ugyanabba a tárolóba kerülne vissza, a kéz a jobb
                if ($err === null && $type === 'container' && $place === (string) $box['id']) {
                    $type = null;
                    foreach (['jobb_kez', 'bal_kez', 'zseb'] as $slot) {
                        if ($w->fits($inst, $qty, 'slot', $slot) === null) {
                            [$type, $place] = ['slot', $slot];
                            break;
                        }
                    }
                    $err = $type === null ? 'Nincs szabad kezed, és a zsebedben sincs hely.' : null;
                }
                if ($err !== null) {
                    return ['siker' => false, 'esemeny' => $err];
                }
                $id = $w->move($inst, $qty, $type, $place);
                $ctx->passTime(self::MINUTES['kivesz']);

                return ['siker' => true, 'esemeny' => sprintf('A játékos kivette: %s (%s) → %s.', $w->name($inst), $w->name($box), $w->whereLabel($w->get($id)))];
            });
        });
    }

    /** Tárgy alapos megvizsgálása (a játékosnál vagy a helyszínen). */
    public function examine(int $playerId, string $item): array
    {
        return $this->run($playerId, 'megvizsgal', function (Context $ctx) use ($item) {
            $w = $ctx->world;
            [$inst, $alts] = $w->findByName($item, array_merge($w->carried(), $w->atLocationDeep($ctx->location)));
            if ($inst === null) {
                // nem tárgy: talán a helyszín egy részlete (hirdetés, műszerfal, konyhapult)
                $detail = $this->findDetail($ctx, $item);
                if ($detail !== null) {
                    return $this->examineDetail($ctx, $detail);
                }
                $out = $this->notFound($item, $alts, 'elerheto');
                if (!$alts && ($seen = array_column($this->details($ctx, false), 'name'))) {
                    $out['latnivalok'] = $seen;
                }
                return $out;
            }

            return $this->rules($ctx)->around($ctx, $this->event('megvizsgal', $inst), function () use ($ctx, $inst) {
                $w = $ctx->world;
                $type = $w->type($inst['item_id']);
                $props = $w->props($inst['item_id']);
                $ctx->passTime(self::MINUTES['megvizsgal']);
                $out = [
                    'siker'   => true,
                    'esemeny' => sprintf('A játékos megvizsgálta: %s.', $w->name($inst)),
                    'targy'   => array_filter([
                        'nev'       => $type['name'],
                        'leiras'    => $type['description'],
                        'reszletek' => $props['vizsgalat'] ?? null,
                        'darab'     => (int) $inst['quantity'],
                        'hol'       => $w->whereLabel($inst),
                        'meret'     => (int) $type['size'],
                        'cimkek'    => $props['cimkek'] ?? null,
                    ], fn ($v) => $v !== null && $v !== []),
                ];
                if (isset($props['tarolo'])) {
                    $out['targy']['tartalom'] = array_map(fn ($c) => ['nev' => $w->name($c), 'darab' => (int) $c['quantity']], $w->at('container', (string) $inst['id']));
                    $out['targy']['kapacitas'] = (int) ($props['tarolo']['kapacitas'] ?? 0);
                }
                // közelkép: targyak/<tárgy>~nezet.webp – bárhol is van a tárgy
                if ($url = $this->imageFile($ctx->gameId(), 'targyak/'.$inst['item_id'].'~nezet')) {
                    $ctx->closeup = ['kep' => $url, 'cim' => $type['name']];
                }

                return $out;
            });
        });
    }

    /** Étel vagy ital elfogyasztása. */
    public function consume(int $playerId, string $item): array
    {
        return $this->run($playerId, 'fogyaszt', function (Context $ctx) use ($item) {
            $w = $ctx->world;
            [$inst, $alts] = $w->findByName($item, array_merge($w->carried(), $w->atLocationDeep($ctx->location)));
            if ($inst === null) {
                return $this->notFound($item, $alts, 'elerheto');
            }
            $type = $w->type($inst['item_id']);
            $tags = $w->tags($inst['item_id']);
            if ((int) $type['nutrition'] <= 0 && !array_intersect(['etel', 'ital'], $tags)) {
                return ['siker' => false, 'esemeny' => sprintf('A(z) %s nem ehető és nem iható.', $type['name'])];
            }

            $eat = $ctx->settings['etkezes'] ?? ['tulajdonsag' => 'jollakottsag', 'irany' => 1];
            $stat = (string) ($eat['tulajdonsag'] ?? '');
            if ((int) $type['nutrition'] > 0 && $stat !== '' && $ctx->state->hasStat($stat)) {
                $limit = (int) ($eat['irany'] ?? 1) >= 0 ? $ctx->state->maxOf($stat) : $ctx->state->minOf($stat);
                if ($limit !== null && abs($ctx->state->stat($stat) - $limit) < 1) {
                    return ['siker' => false, 'esemeny' => 'A játékos teljesen jóllakott, most egy falatot sem bír többet.'];
                }
            }

            return $this->rules($ctx)->around($ctx, $this->event('fogyaszt', $inst), function () use ($ctx, $inst, $type) {
                $eat = $ctx->settings['etkezes'] ?? ['tulajdonsag' => 'jollakottsag', 'irany' => 1];
                $stat = (string) ($eat['tulajdonsag'] ?? '');
                if ($stat !== '' && $ctx->state->hasStat($stat)) {
                    $ctx->addStat($stat, (int) $type['nutrition'] * ((int) ($eat['irany'] ?? -1) >= 0 ? 1 : -1));
                }
                $props = $ctx->world->props($inst['item_id']);
                $ctx->world->decrease($inst, 1);
                $ctx->passTime(self::MINUTES['fogyaszt']);
                if (!empty($props['marad']) && $ctx->world->type((string) $props['marad']) !== null) {
                    $this->giveToPlayer($ctx, (string) $props['marad'], 1); // pl. az üres üveg megmarad
                }
                Rules::apply((array) ($props['fogyasztas_hatas'] ?? []), $ctx, ['muvelet' => 'fogyaszt', 'targy' => $inst['item_id']]);

                return ['siker' => true, 'esemeny' => sprintf('A játékos elfogyasztotta: %s.', $type['name'])];
            });
        });
    }

    // =====================================================================
    //  Szereplők és kereskedés
    // =====================================================================

    /** Beszélgetés: téma nélkül a szereplő adatlapja és a témái; témával amit elmond róla. */
    public function talk(int $playerId, string $npc, ?string $topic = null): array
    {
        return $this->run($playerId, 'beszel', function (Context $ctx) use ($npc, $topic) {
            [$who, $alts] = $ctx->npcs->find($npc, $ctx->npcs->present($ctx));
            if ($who === null) {
                return $this->npcNotFound($npc, $alts);
            }
            $topics = $ctx->npcs->topics($who['id'], $ctx);
            $chosen = ($topic !== null && trim($topic) !== '') ? $ctx->npcs->findTopic($topic, $topics) : null;
            $event = ['muvelet' => 'beszel', 'szereplo' => $who['id']];

            return $this->rules($ctx)->around($ctx, $event, function () use ($ctx, $who, $topics, $topic, $chosen) {
                $ctx->passTime(self::MINUTES['beszel']);
                $out = [
                    'siker'     => true,
                    'esemeny'   => sprintf('A játékos beszél vele: %s.', $who['name']),
                    'szereplo'  => $this->npcView($ctx, $who, $topics),
                ];
                if ($topic !== null && trim($topic) !== '') {
                    if ($chosen === null) {
                        $out['tema'] = ['kerdes' => $topic, 'valasz' => null,
                            'megjegyzes' => 'Erről a szereplő nem tud, vagy nem hajlandó beszélni. Válaszolj kitérően a nevében, új tényt ne találj ki.'];
                    } else {
                        $first = !$ctx->state->flag('_tema:'.$chosen['id']);
                        if ($first) {
                            $ctx->state->setFlag('_tema:'.$chosen['id'], true);
                            Rules::apply(json_decode((string) $chosen['effects'], true) ?: [], $ctx, ['muvelet' => 'beszel', 'szereplo' => $who['id']]);
                        }
                        $out['tema'] = ['cim' => $chosen['title'], 'valasz' => $chosen['content'], 'mar_elmondta' => !$first];
                        $out['szereplo']['temak'] = $this->topicList($ctx, $topics);
                    }
                }

                return $out;
            });
        });
    }

    /** Egy nálunk lévő tárgy odaadása egy jelen lévő szereplőnek. */
    public function give(int $playerId, string $item, string $npc): array
    {
        return $this->run($playerId, 'odaad', function (Context $ctx) use ($item, $npc) {
            [$who, $alts] = $ctx->npcs->find($npc, $ctx->npcs->present($ctx));
            if ($who === null) {
                return $this->npcNotFound($npc, $alts);
            }
            $w = $ctx->world;
            [$inst, $alts] = $w->findByName($item, $w->carried());
            if ($inst === null) {
                return $this->notFound($item, $alts, 'nala');
            }
            $event = ['muvelet' => 'odaad', 'targy' => $inst['item_id'], 'szereplo' => $who['id']];

            return $this->rules($ctx)->around($ctx, $event, function () use ($ctx, $inst, $who) {
                $ctx->world->move($inst, 1, 'npc', $who['id']);
                $ctx->passTime(self::MINUTES['odaad']);

                return ['siker' => true, 'esemeny' => sprintf('A játékos odaadta: %s → %s.', $ctx->world->name($inst), $who['name'])];
            });
        });
    }

    /** Vásárlás egy jelen lévő szereplőtől vagy a helyszín automatájából (pénzért vagy cserébe). */
    public function buy(int $playerId, string $item, ?int $quantity = null, ?string $from = null): array
    {
        return $this->run($playerId, 'vesz', function (Context $ctx) use ($item, $quantity, $from) {
            [$trade, $error] = $this->pickTrade($ctx, 'arul', $item, $from);
            if ($trade === null) {
                return $error;
            }
            $w = $ctx->world;
            $qty = max(1, min(99, $quantity ?? 1));
            $left = $ctx->npcs->remaining($trade, $ctx);
            if ($left !== null && $left < $qty) {
                return ['siker' => false, 'esemeny' => $left === 0
                    ? 'Ebből mára elfogyott a készlet; reggel lesz újra.'
                    : sprintf('Ebből ma már csak %d darab van.', $left)];
            }
            if (!Rules::check($trade['conditions'], $ctx, ['muvelet' => 'vesz', 'targy' => $trade['item_id']])) {
                return ['siker' => false, 'esemeny' => 'Az eladó nem hajlandó kiszolgálni a játékost (lehet, hogy az ápolatlansága vagy a rossz híre miatt).'];
            }
            $name = $w->type($trade['item_id'])['name'];

            // fizetőeszköz: pénz vagy cseretárgy
            $price = $ctx->npcs->unitPrice($trade, $w, $ctx);
            $money = $ctx->state->moneyStat();
            if ($trade['price_item_id'] !== null) {
                if ($w->countCarried($trade['price_item_id']) < $qty) {
                    return ['siker' => false, 'esemeny' => sprintf('Ezért cserébe ez kellene: %s (%d db), de nincs nála ennyi.', $w->type($trade['price_item_id'])['name'], $qty)];
                }
            } elseif ($price > 0 && ($money === null || $ctx->state->stat($money) < $price * $qty)) {
                return ['siker' => false, 'esemeny' => sprintf('Nincs elég pénze: %d kellene, %d van nála.', $price * $qty, $money ? (int) $ctx->state->stat($money) : 0)];
            }
            // elfér-e?
            $probe = ['id' => 0, 'item_id' => $trade['item_id'], 'quantity' => $qty, 'place_type' => 'location', 'place_id' => $ctx->location];
            [$type, $place, $err] = $w->autoPlace($probe, $qty);
            if ($err !== null) {
                return ['siker' => false, 'esemeny' => $err.' Előbb tegyen le vagy rakjon el valamit.'];
            }
            $event = ['muvelet' => 'vesz', 'targy' => $trade['item_id'], 'szereplo' => $trade['trader_type'] === 'npc' ? $trade['trader_id'] : null];

            return $this->rules($ctx)->around($ctx, $event, function () use ($ctx, $trade, $qty, $price, $money, $type, $place, $name) {
                $w = $ctx->world;
                if ($trade['price_item_id'] !== null) {
                    Rules::apply([['targy_elvesz' => [$trade['price_item_id'], $qty]]], $ctx);
                    $paid = sprintf('cserébe: %s ×%d', $w->type($trade['price_item_id'])['name'], $qty);
                } else {
                    $ctx->addStat($money ?? '', -$price * $qty);
                    $paid = trim(($price * $qty).' '.$this->moneyUnit($ctx));
                }
                $id = $w->create($trade['item_id'], $qty, 'location', $ctx->location, $ctx->minute);
                $id = $w->move($w->get($id), $qty, $type, $place);
                $this->afterTrade($ctx, $trade, $qty);

                return ['siker' => true, 'esemeny' => sprintf('A játékos megvette: %s ×%d (%s) → %s.', $name, $qty, $paid, $w->whereLabel($w->get($id)))];
            });
        });
    }

    /** Eladás: a nálunk lévő tárgyat egy itteni felvásárló megveszi. */
    public function sell(int $playerId, string $item, ?int $quantity = null, ?string $to = null): array
    {
        return $this->run($playerId, 'elad', function (Context $ctx) use ($item, $quantity, $to) {
            $w = $ctx->world;
            [$inst, $alts] = $w->findByName($item, $w->carried());
            if ($inst === null) {
                return $this->notFound($item, $alts, 'nala');
            }
            [$trade, $error] = $this->pickTrade($ctx, 'felvasarol', $w->name($inst), $to, $inst['item_id']);
            if ($trade === null) {
                return $error;
            }
            if ($w->at('container', (string) $inst['id'])) {
                return ['siker' => false, 'esemeny' => 'Előbb ki kell üríteni.'];
            }
            $have = $w->countCarried($inst['item_id']);
            $qty = max(1, min($quantity ?? $have, $have));
            $left = $ctx->npcs->remaining($trade, $ctx);
            if ($left !== null) {
                if ($left === 0) {
                    return ['siker' => false, 'esemeny' => 'Ma már nem vesz át többet ebből; reggel újra lehet próbálni.'];
                }
                $qty = min($qty, $left);
            }
            if (!Rules::check($trade['conditions'], $ctx, ['muvelet' => 'elad', 'targy' => $trade['item_id']])) {
                return ['siker' => false, 'esemeny' => 'A felvásárló most nem hajlandó üzletelni a játékossal.'];
            }
            $price = (int) $ctx->npcs->unitPrice($trade, $w, $ctx);
            $event = ['muvelet' => 'elad', 'targy' => $trade['item_id'], 'szereplo' => $trade['trader_type'] === 'npc' ? $trade['trader_id'] : null];

            return $this->rules($ctx)->around($ctx, $event, function () use ($ctx, $trade, $qty, $price) {
                $name = $ctx->world->type($trade['item_id'])['name'];
                Rules::apply([['targy_elvesz' => [$trade['item_id'], $qty]]], $ctx);
                if (($money = $ctx->state->moneyStat()) !== null) {
                    $ctx->addStat($money, $price * $qty);
                }
                $this->afterTrade($ctx, $trade, $qty);

                return ['siker' => true, 'esemeny' => sprintf('A játékos eladta: %s ×%d, kapott érte: %s.', $name, $qty, trim(($price * $qty).' '.$this->moneyUnit($ctx)))];
            });
        });
    }

    /** @return array{0: ?array, 1: array} [a kereskedési sor, egyébként a hibaválasz] */
    private function pickTrade(Context $ctx, string $mode, string $item, ?string $trader, ?string $itemId = null): array
    {
        $w = $ctx->world;
        $lines = $ctx->npcs->tradesHere($ctx, $mode);
        if (!$lines) {
            return [null, ['siker' => false, 'esemeny' => $mode === 'arul' ? 'Itt most senki nem árul semmit.' : 'Itt most senki nem vásárol fel semmit.']];
        }
        $names = $this->locationNames($ctx->gameId());
        if ($trader !== null && trim($trader) !== '') {
            $t = World::simplify($trader);
            $lines = array_values(array_filter($lines, fn ($l) => str_contains(World::simplify($ctx->npcs->traderName($l, $names)), $t)
                || str_contains($t, World::simplify($l['trader_id'])))) ?: $lines;
        }
        $q = World::simplify($item);
        $match = array_values(array_filter($lines, function ($l) use ($w, $q, $itemId) {
            if ($itemId !== null) {
                return $l['item_id'] === $itemId;
            }
            $n = World::simplify($w->type($l['item_id'])['name'] ?? '');
            return $n === $q || World::simplify($l['item_id']) === $q || ($q !== '' && (str_contains($n, $q) || str_contains($q, $n)));
        }));
        if (!$match) {
            return [null, [
                'siker'   => false,
                'esemeny' => $mode === 'arul' ? sprintf('Itt nem árulnak ilyet: „%s”.', $item) : sprintf('Itt senki nem vesz meg ilyet: „%s”.', $item),
                'kinalat' => array_map(fn ($l) => $ctx->npcs->tradeView($l, $w, $ctx, $names), $lines),
            ]];
        }

        return [$match[0], []];
    }

    private function moneyUnit(Context $ctx): string
    {
        $money = $ctx->state->moneyStat();
        foreach ($ctx->state->statsView() as $st) {
            if ($st['id'] === $money) {
                return (string) ($st['egyseg'] ?? '');
            }
        }

        return '';
    }

    private function afterTrade(Context $ctx, array $trade, int $qty): void
    {
        $ctx->state->increment('keszlet:'.$trade['id'], $qty);
        if ($trade['trader_type'] === 'npc') {
            $ctx->state->increment('vasarlas:'.$trade['trader_id']);
            if ($ctx->state->relation($trade['trader_id']) < 10) {
                $ctx->state->addRelation($trade['trader_id'], 1);
            }
        }
        $ctx->passTime(self::MINUTES['vesz']);
    }

    private function npcView(Context $ctx, array $npc, array $topics): array
    {
        $names = $this->locationNames($ctx->gameId());

        return array_filter([
            'nev'          => $npc['name'],
            'leiras'       => $npc['description'],
            'szemelyiseg'  => $npc['personality'],
            'mai_kedve'    => Npcs::MOODS[$ctx->npcs->mood($npc['id'], $ctx)],
            'kapcsolat'    => $ctx->state->relation($npc['id']),
            'temak'        => $this->topicList($ctx, $topics),
            'kinalat'      => array_values(array_map(fn ($l) => $ctx->npcs->tradeView($l, $ctx->world, $ctx, $names),
                array_filter($ctx->npcs->tradesHere($ctx), fn ($l) => $l['trader_type'] === 'npc' && $l['trader_id'] === $npc['id']))) ?: null,
            'ajanlatok'    => array_map(fn ($q) => ['id' => $q['id'], 'cim' => $q['title'], 'amit_mond' => $q['offer_text'], 'cel' => $q['goal_text'],
                'hatarido' => $q['deadline_minutes'] !== null ? sprintf('%d óra', intdiv((int) $q['deadline_minutes'], 60)) : null], $this->offers($ctx, $npc['id'])) ?: null,
            'kolcson'      => ($l = json_decode((string) ($npc['loan'] ?? ''), true)) && !empty($l['osszeg'])
                ? sprintf('ad kölcsönt: %d, %d%% kamattal, %d napra (a feltételei: %s)', $l['osszeg'], $l['kamat'] ?? 0, $l['napok'] ?? 3,
                    Rules::check((array) ($l['feltetelek'] ?? []), $ctx) ? 'teljesülnek' : 'még nem teljesülnek')
                : null,
            'tartozas'     => ($t = $ctx->state->loan($npc['id'])) ? sprintf('%d, határidő: %s', $t['tartozas'], self::formatTime((int) $t['hatarido'])) : null,
            'utmutatas'    => 'Beszélj a szereplő nevében, a személyisége és a mai kedve szerint. Csak a témáiban szereplő tényeket mondhatja el; '
                .'ha a játékos egy témáról kérdez, hívd a beszel eszközt a téma nevével. Ha van „ajanlatok”, a szereplő a saját szavaival ajánlja fel; '
                .'a játékos döntése után hívd az elfogad vagy az elutasit eszközt.',
        ], fn ($v) => $v !== null && $v !== '');
    }

    private function topicList(Context $ctx, array $topics): array
    {
        return array_map(fn ($t) => ['cim' => $t['title'], 'elhangzott' => (bool) $ctx->state->flag('_tema:'.$t['id'])], $topics);
    }

    private function npcNotFound(string $query, array $alternatives): array
    {
        return $alternatives
            ? ['siker' => false, 'esemeny' => sprintf('Nincs itt ilyen szereplő: „%s”.', $query), 'itt_van' => $alternatives]
            : ['siker' => false, 'esemeny' => 'Itt most nincs senki, akivel beszélni lehetne.'];
    }

    /** @return array<string, string> */
    private function locationNames(string $gameId): array
    {
        $stmt = $this->db->prepare('SELECT id, name FROM locations WHERE game_id = ?');
        $stmt->execute([$gameId]);

        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    // =====================================================================
    //  A helyszín részletei (látnivalók) és a közelképek
    // =====================================================================

    /** @return list<array> a helyszín most látható részletei (a rejtettek csak $withHidden mellett) */
    private function details(Context $ctx, bool $withHidden): array
    {
        $stmt = $this->db->prepare('SELECT * FROM location_details WHERE game_id = ? AND location_id = ? ORDER BY sort_order, id');
        $stmt->execute([$ctx->gameId(), $ctx->location]);

        return array_values(array_filter($stmt->fetchAll(), fn ($d) => ($withHidden || !$d['hidden'])
            && Rules::check(json_decode((string) $d['conditions'], true) ?: [], $ctx, ['muvelet' => 'megvizsgal', 'reszlet' => $d['id']])));
    }

    private function findDetail(Context $ctx, string $query): ?array
    {
        $q = World::simplify($query);
        if ($q === '') {
            return null;
        }
        $all = $this->details($ctx, true);
        foreach ($all as $d) {
            if (World::simplify($d['name']) === $q || World::simplify($d['id']) === $q) {
                return $d;
            }
        }
        foreach ($all as $d) {
            $words = array_filter(array_map([World::class, 'simplify'], explode(',', $d['name'].','.(string) $d['keywords'])));
            foreach ($words as $word) {
                if (strlen($word) >= 3 && (str_contains($q, $word) || str_contains($word, $q))) {
                    return $d;
                }
            }
        }

        return null;
    }

    private function examineDetail(Context $ctx, array $d): array
    {
        $event = ['muvelet' => 'megvizsgal', 'reszlet' => $d['id']];

        return $this->rules($ctx)->around($ctx, $event, function () use ($ctx, $d) {
            $ctx->passTime(self::MINUTES['megvizsgal']);
            $first = !$ctx->state->flag('_reszlet:'.$d['id']);
            if ($first) {
                $ctx->state->setFlag('_reszlet:'.$d['id'], true);
                Rules::apply(json_decode((string) $d['effects'], true) ?: [], $ctx, ['muvelet' => 'megvizsgal', 'reszlet' => $d['id']]);
            }
            if ($url = $this->imageFile($ctx->gameId(), 'reszletek/'.$d['id'])) {
                $ctx->closeup = ['kep' => $url, 'cim' => $d['name']];
            }

            return [
                'siker'   => true,
                'esemeny' => sprintf('A játékos alaposan megnézte: %s.', $d['name']),
                'reszlet' => ['nev' => $d['name'], 'amit_lat' => $d['description'], 'mar_latta' => !$first],
            ];
        });
    }

    /** Egy kép az assets/images/<játék>/ alatt (webp, png, jpg) – ha nincs, null */
    private function imageFile(string $gameId, string $path): ?string
    {
        if ($this->imageDir === null || $this->imageUrl === null || !preg_match('#^[a-z0-9_]+/[a-z0-9_~-]+$#', $path)) {
            return null;
        }
        foreach (self::IMAGE_EXTENSIONS as $ext) {
            $file = $this->imageDir.'/'.$gameId.'/'.$path.'.'.$ext;
            if (is_file($file)) {
                return $this->imageUrl.'/'.$gameId.'/'.$path.'.'.$ext.'?v='.filemtime($file);
            }
        }

        return null;
    }

    // =====================================================================
    //  Haladó tárgyak: használat, receptek, jármű, tűz, kopás
    // =====================================================================

    /** Egy használat kopást okoz; ha a tartósság elfogy, a tárgy eltörik (vagy átalakul). */
    private function wearOut(Context $ctx, int $instId): void
    {
        $inst = $ctx->world->get($instId);
        $p = $inst ? $ctx->world->props($inst['item_id']) : [];
        if (!$inst || empty($p['tartossag'])) {
            return;
        }
        $wear = (int) $inst['wear'] + 1;
        if ($wear < (int) $p['tartossag']) {
            $ctx->world->setWear($inst, $wear);
            return;
        }
        $name = $ctx->world->name($inst);
        $to = (string) ($p['torik_mive'] ?? '');
        if ($to !== '' && $ctx->world->type($to) !== null) {
            if ((int) $inst['quantity'] > 1) {
                $ctx->world->decrease($inst, 1);
                $ctx->world->create($to, 1, $inst['place_type'], $inst['place_id'], $ctx->minute);
            } else {
                $ctx->world->transform($inst, $to, $ctx->minute);
            }
            $ctx->messages[] = sprintf('A(z) %s elhasználódott: %s lett belőle.', $name, $ctx->world->type($to)['name']);
        } else {
            $ctx->world->decrease($inst, 1);
            $ctx->messages[] = sprintf('A(z) %s elhasználódott, nincs belőle több.', $name);
        }
    }

    /** Új tárgy a játékosnak; ha nem fér el, a földre kerül. @return string hova került */
    private function giveToPlayer(Context $ctx, string $itemId, int $qty): string
    {
        $w = $ctx->world;
        $id = $w->create($itemId, $qty, 'location', $ctx->location, $ctx->minute);
        [$type, $place] = $w->autoPlace($w->get($id), $qty);
        if ($type === null) {
            return 'a földre (nem fért el a játékosnál)';
        }
        $id = $w->move($w->get($id), $qty, $type, $place);

        return $w->whereLabel($w->get($id));
    }

    /** Eszköz használata valamin (egy tárgyon vagy a helyszín egy részén); a hatást a játék szabályai adják. */
    public function useItem(int $playerId, string $item, ?string $target = null): array
    {
        return $this->run($playerId, 'hasznal', function (Context $ctx) use ($item, $target) {
            $w = $ctx->world;
            $reach = array_merge($w->carried(), $w->atLocationDeep($ctx->location));
            [$tool, $alts] = $w->findByName($item, $reach);
            if ($tool === null) {
                return $this->notFound($item, $alts, 'elerheto');
            }
            $celpont = null;
            $targetName = null;
            if ($target !== null && trim($target) !== '') {
                [$tInst] = $w->findByName($target, array_filter($reach, fn ($i) => (int) $i['id'] !== (int) $tool['id']));
                if ($tInst !== null) {
                    $celpont = $tInst['item_id'];
                    $targetName = $w->name($tInst);
                } else {
                    $q = World::simplify($target);
                    foreach ($ctx->features() as $f) {
                        $fs = World::simplify(str_replace('_', ' ', $f));
                        if ($q !== '' && ($fs === $q || str_contains($q, $fs) || str_contains($fs, $q))) {
                            $celpont = $f;
                            $targetName = $target;
                        }
                    }
                }
                if ($celpont === null) {
                    return ['siker' => false, 'esemeny' => sprintf('„%s”: ilyen nincs a közelben.', $target), 'felszereltseg' => $ctx->features()];
                }
            }
            $rules = $this->rules($ctx);
            $event = ['muvelet' => 'hasznal', 'targy' => $tool['item_id'], 'celpont' => $celpont];

            return $rules->around($ctx, $event, function () use ($ctx, $rules, $tool, $targetName) {
                $ctx->passTime(5);
                if ($rules->lastMatched === 0) {
                    return ['siker' => false, 'esemeny' => sprintf('A játékos kipróbálta: %s%s – de így nincs látható hatása.',
                        $ctx->world->name($tool), $targetName ? ' → '.$targetName : '')];
                }
                $this->wearOut($ctx, (int) $tool['id']);

                return ['siker' => true, 'esemeny' => sprintf('A játékos használta: %s%s.', $ctx->world->name($tool), $targetName ? ' → '.$targetName : '')];
            });
        });
    }

    /** @return list<array> a játék receptjei (JSON-mezők dekódolva) */
    private function recipes(string $gameId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM recipes WHERE game_id = ? ORDER BY sort_order, name');
        $stmt->execute([$gameId]);

        return array_map(function (array $r) {
            foreach (['ingredients', 'tools', 'features', 'conditions', 'effects'] as $k) {
                $r[$k] = json_decode((string) $r[$k], true) ?: [];
            }
            return $r;
        }, $stmt->fetchAll());
    }

    /** Mi hiányzik egy recepthez most? @return list<string> */
    private function recipeMissing(Context $ctx, array $r): array
    {
        $w = $ctx->world;
        $missing = [];
        foreach ($r['ingredients'] as $ing) {
            $have = $w->countCarried((string) $ing['targy']) + $w->countAt($ctx->location, (string) $ing['targy']);
            if ($have < (int) ($ing['darab'] ?? 1)) {
                $missing[] = sprintf('%s ×%d', $w->type((string) $ing['targy'])['name'] ?? $ing['targy'], (int) ($ing['darab'] ?? 1));
            }
        }
        foreach ($r['tools'] as $t) {
            if ($w->countCarried((string) $t) === 0) {
                $missing[] = sprintf('eszköz: %s', $w->type((string) $t)['name'] ?? $t);
            }
        }
        foreach ($r['features'] as $f) {
            if (!in_array($f, $ctx->features(), true)) {
                $missing[] = $f === 'tuz' ? 'egy égő tűz' : 'hely: '.$f;
            }
        }

        return $missing;
    }

    private function recipeView(Context $ctx, array $r): array
    {
        $w = $ctx->world;
        $missing = $this->recipeMissing($ctx, $r);

        return array_filter([
            'recept'   => $r['name'],
            'eredmeny' => sprintf('%s ×%d', $w->type($r['result_item'])['name'] ?? $r['result_item'], (int) $r['result_qty']),
            'kell'     => implode(', ', array_merge(
                array_map(fn ($i) => sprintf('%s ×%d', $w->type((string) $i['targy'])['name'] ?? $i['targy'], (int) ($i['darab'] ?? 1)), $r['ingredients']),
                array_map(fn ($t) => 'eszköz: '.($w->type((string) $t)['name'] ?? $t), $r['tools']),
                array_map(fn ($f) => $f === 'tuz' ? 'égő tűz' : 'hely: '.$f, $r['features']))),
            'hianyzik' => $missing ? implode(', ', $missing) : null,
        ], fn ($v) => $v !== null);
    }

    /** @return list<array> a játékos által ismert receptek (amelyeknek a feltétele teljesül) */
    private function knownRecipes(Context $ctx): array
    {
        return array_values(array_filter($this->recipes($ctx->gameId()), fn ($r) => Rules::check($r['conditions'], $ctx)));
    }

    /** Készítés recept szerint: az alapanyagok elfogynak, az eszközök kopnak, az eredmény a játékoshoz kerül. */
    public function craft(int $playerId, string $recipe): array
    {
        return $this->run($playerId, 'keszit', function (Context $ctx) use ($recipe) {
            $known = $this->knownRecipes($ctx);
            if (!$known) {
                return ['siker' => false, 'esemeny' => 'A játékos most nem tud semmit elkészíteni.'];
            }
            $q = World::simplify($recipe);
            $hit = null;
            foreach ($known as $r) {
                $n = World::simplify($r['name']);
                $res = World::simplify($ctx->world->type($r['result_item'])['name'] ?? '');
                if ($q !== '' && ($n === $q || $res === $q || str_contains($n, $q) || str_contains($q, $n) || str_contains($res, $q))) {
                    $hit = $r;
                    break;
                }
            }
            if ($hit === null) {
                return ['siker' => false, 'esemeny' => sprintf('Ilyet a játékos nem tud készíteni: „%s”.', $recipe),
                    'receptek' => array_map(fn ($r) => $this->recipeView($ctx, $r), $known)];
            }
            if ($missing = $this->recipeMissing($ctx, $hit)) {
                return ['siker' => false, 'esemeny' => sprintf('„%s”: még hiányzik: %s.', $hit['name'], implode(', ', $missing))];
            }
            $event = ['muvelet' => 'keszit', 'targy' => $hit['result_item']];

            return $this->rules($ctx)->around($ctx, $event, function () use ($ctx, $hit) {
                $w = $ctx->world;
                foreach ($hit['ingredients'] as $ing) {
                    Rules::apply([['targy_elvesz' => [(string) $ing['targy'], (int) ($ing['darab'] ?? 1)]]], $ctx);
                }
                foreach ($hit['tools'] as $t) {
                    foreach ($w->carried() as $inst) {
                        if ($inst['item_id'] === $t) {
                            $this->wearOut($ctx, (int) $inst['id']);
                            break;
                        }
                    }
                }
                $ctx->passTime((int) $hit['minutes']);
                $where = $this->giveToPlayer($ctx, $hit['result_item'], max(1, (int) $hit['result_qty']));
                Rules::apply($hit['effects'], $ctx, ['muvelet' => 'keszit', 'targy' => $hit['result_item']]);

                return ['siker' => true, 'esemeny' => sprintf('A játékos elkészítette: %s ×%d (%d perc) → %s.%s',
                    $w->type($hit['result_item'])['name'], (int) $hit['result_qty'], (int) $hit['minutes'], $where,
                    $hit['description'] ? ' '.$hit['description'] : '')];
            });
        });
    }

    /** Beszállás egy itt álló járműbe (vagy felülés rá). */
    public function board(int $playerId, string $vehicle): array
    {
        return $this->run($playerId, 'menj', function (Context $ctx) use ($vehicle) {
            $w = $ctx->world;
            if ($ctx->state->extra('jarmu') !== null) {
                return ['siker' => false, 'esemeny' => 'A játékos már egy járműben ül.'];
            }
            [$inst, $alts] = $w->findByName($vehicle, array_filter($w->at('location', $ctx->location), fn ($i) => isset($w->props($i['item_id'])['jarmu'])));
            if ($inst === null) {
                return $alts ? $this->notFound($vehicle, $alts, 'itt') : ['siker' => false, 'esemeny' => 'Itt nincs jármű, amibe be lehetne szállni.'];
            }
            $ctx->state->setExtra('jarmu', (int) $inst['id']);
            $ctx->passTime(1);

            return ['siker' => true, 'esemeny' => sprintf('A játékos beszállt / felült: %s. A szabad ég alatti utakon gyorsabban halad vele.', $w->name($inst))];
        });
    }

    public function alight(int $playerId): array
    {
        return $this->run($playerId, 'menj', function (Context $ctx) {
            $id = $ctx->state->extra('jarmu');
            if ($id === null) {
                return ['siker' => false, 'esemeny' => 'A játékos nem ül járműben.'];
            }
            $ctx->state->setExtra('jarmu', null);
            $ctx->passTime(1);
            $v = $ctx->world->get((int) $id);

            return ['siker' => true, 'esemeny' => sprintf('A játékos kiszállt / leszállt: %s. A jármű itt marad.', $v ? $ctx->world->name($v) : 'a járműről')];
        });
    }

    /** A jármű, amiben a játékos ül (ha még megvan és vele van) */
    private function vehicle(Context $ctx): ?array
    {
        $id = $ctx->state->extra('jarmu');
        $v = $id !== null ? $ctx->world->get((int) $id) : null;
        if ($id !== null && ($v === null || $v['place_type'] !== 'location' || $v['place_id'] !== $ctx->location)) {
            $ctx->state->setExtra('jarmu', null); // a jármű eltűnt vagy máshol van
            return null;
        }

        return $v;
    }

    /** Tűzrakás egy tűzrakóhelyen: az itt letett éghető dolgokból, egy gyújtóval. Ha már ég, táplálja. */
    public function makeFire(int $playerId, ?string $fuelName = null): array
    {
        return $this->run($playerId, 'hasznal', function (Context $ctx) use ($fuelName) {
            $w = $ctx->world;
            if (!in_array('tuzrakohely', $ctx->featureMap[$ctx->location] ?? [], true)) {
                return ['siker' => false, 'esemeny' => 'Itt nem lehet tüzet rakni (tűzrakóhely kellene).'];
            }
            $burning = $ctx->fireAt($ctx->location);
            $lighter = null;
            foreach ($w->carried() as $i) {
                if (!empty($w->props($i['item_id'])['gyujto'])) {
                    $lighter = $i;
                    break;
                }
            }
            if (!$burning && $lighter === null) {
                return ['siker' => false, 'esemeny' => 'Nincs mivel meggyújtani (gyufa, öngyújtó kellene).'];
            }
            if (!$burning && in_array($ctx->state->weather(), ['esos', 'havas', 'viharos'], true) && in_array('szabadter', $ctx->featureMap[$ctx->location] ?? [], true)) {
                return ['siker' => false, 'esemeny' => 'Ilyen időben a szabadban nem gyullad meg a tűz.'];
            }
            $burnable = fn ($i) => (float) ($w->props($i['item_id'])['eghet'] ?? 0) > 0;
            if ($fuelName !== null && trim($fuelName) !== '') {
                // a játékos megnevezte, mit tesz a tűzre (nála vagy itt)
                [$one, $alts] = $w->findByName($fuelName, array_filter(array_merge($w->carried(), $w->at('location', $ctx->location)), $burnable));
                if ($one === null) {
                    return $alts ? $this->notFound($fuelName, $alts, 'elerheto') : ['siker' => false, 'esemeny' => sprintf('„%s”: ez nem ég, vagy nincs itt.', $fuelName)];
                }
                $fuel = [$one];
            } else {
                // magától csak a tüzelőt (pl. ágakat) teszi rá, ami itt van a földön – a papírt, takarót nem
                $fuel = array_filter($w->at('location', $ctx->location), fn ($i) => $burnable($i) && in_array('tuzelo', $w->tags($i['item_id']), true));
            }
            if (!$fuel) {
                return ['siker' => false, 'esemeny' => 'Nincs itt tüzelő (pl. ág). Más éghető dolgot (papírt) csak akkor tesz a tűzre, ha a játékos megnevezi.'];
            }
            $hours = 0.0;
            $names = [];
            foreach ($fuel as $i) {
                $hours += (float) $w->props($i['item_id'])['eghet'] * (int) $i['quantity'];
                $names[] = $w->name($i).((int) $i['quantity'] > 1 ? ' ×'.$i['quantity'] : '');
                $w->decrease($i, (int) $i['quantity']);
            }
            if (!$burning) {
                $this->wearOut($ctx, (int) $lighter['id']);
            }
            $fires = $ctx->state->extra('tuz') ?? [];
            $fires[$ctx->location] = max($burning ?? $ctx->minute, $ctx->minute) + (int) round($hours * 60);
            $ctx->state->setExtra('tuz', $fires);
            $ctx->passTime(10);

            return ['siker' => true, 'esemeny' => sprintf('%s A tűzre került: %s. Kb. %s-ig ég.',
                $burning ? 'A játékos megrakta a tüzet.' : 'A játékos tüzet rakott.', implode(', ', $names), self::formatTime($fires[$ctx->location]))];
        });
    }

    // =====================================================================
    //  Megbízások, kölcsönök, munka
    // =====================================================================

    /** @return list<array> a játék megbízásai (JSON-mezők dekódolva) */
    private function questDefs(string $gameId): array
    {
        if (!isset($this->questCache[$gameId])) {
            $stmt = $this->db->prepare('SELECT * FROM quests WHERE game_id = ? ORDER BY sort_order, id');
            $stmt->execute([$gameId]);
            $this->questCache[$gameId] = array_map(function (array $q) {
                foreach (['offer_conditions', 'accept_effects', 'goal_conditions', 'reward_effects', 'penalty_effects'] as $k) {
                    $q[$k] = json_decode((string) $q[$k], true) ?: [];
                }
                return $q;
            }, $stmt->fetchAll());
        }

        return $this->questCache[$gameId];
    }

    /** @var array<string, list<array>> */
    private array $questCache = [];

    /** @return list<array> amit a szereplő most felajánl */
    private function offers(Context $ctx, string $npcId): array
    {
        return array_values(array_filter($this->questDefs($ctx->gameId()), function (array $q) use ($ctx, $npcId) {
            $st = $ctx->state->quest($q['id'])['allapot'] ?? null;
            $open = $st === null || ($q['repeatable'] && in_array($st, ['elutasitva', 'elbukott'], true));

            return $q['npc_id'] === $npcId && $open && Rules::check($q['offer_conditions'], $ctx, ['muvelet' => 'beszel', 'szereplo' => $npcId]);
        }));
    }

    private function questView(Context $ctx, array $q): array
    {
        $s = $ctx->state->quest($q['id']) ?? [];

        return array_filter([
            'id'       => $q['id'],
            'cim'      => $q['title'],
            'cel'      => $q['goal_text'],
            'allapot'  => $s['allapot'] ?? 'felajanlva',
            'hatarido' => isset($s['hatarido']) ? self::formatTime((int) $s['hatarido'])
                : ($q['deadline_minutes'] !== null && !$s ? sprintf('az elfogadástól %d óra', intdiv((int) $q['deadline_minutes'], 60)) : null),
        ], fn ($v) => $v !== null);
    }

    /** A futó megbízások ellenőrzése minden lépés után: teljesült-e a cél, vagy lejárt-e a határidő. */
    private function checkQuests(Context $ctx): void
    {
        foreach ($this->questDefs($ctx->gameId()) as $q) {
            $s = $ctx->state->quest($q['id']);
            if (($s['allapot'] ?? null) !== 'folyamatban') {
                continue;
            }
            if ($q['goal_conditions'] && Rules::check($q['goal_conditions'], $ctx)) {
                $ctx->state->setQuest($q['id'], ['allapot' => 'teljesitve']);
                Rules::apply($q['reward_effects'], $ctx);
                $ctx->messages[] = sprintf('Megbízás teljesítve: „%s”. %s', $q['title'], (string) $q['success_text']);
                $ctx->notable++;
            } elseif (isset($s['hatarido']) && $ctx->minute > (int) $s['hatarido']) {
                $ctx->state->setQuest($q['id'], ['allapot' => 'elbukott']);
                Rules::apply($q['penalty_effects'], $ctx);
                $ctx->messages[] = sprintf('Lejárt a határidő, a megbízás elbukott: „%s”. %s', $q['title'], (string) $q['fail_text']);
                $ctx->notable++;
            }
        }
    }

    /** A játékos megbízásai és tartozásai */
    public function questLog(int $playerId): array
    {
        return $this->run($playerId, 'status', function (Context $ctx) {
            $active = [];
            $done = [];
            foreach ($this->questDefs($ctx->gameId()) as $q) {
                $st = $ctx->state->quest($q['id'])['allapot'] ?? null;
                if ($st === 'folyamatban') {
                    $active[] = $this->questView($ctx, $q);
                } elseif (in_array($st, ['teljesitve', 'elbukott'], true)) {
                    $done[] = ['cim' => $q['title'], 'allapot' => $st];
                }
            }

            return [
                'siker'       => true,
                'folyamatban' => $active,
                'lezart'      => $done,
                'tartozasok'  => $this->loansView($ctx),
            ];
        }, withLocation: false);
    }

    /** Egy felajánlott megbízás elfogadása (a felajánló szereplőnek jelen kell lennie). */
    public function acceptQuest(int $playerId, string $quest, bool $accept = true): array
    {
        return $this->run($playerId, 'beszel', function (Context $ctx) use ($quest, $accept) {
            $offers = [];
            foreach ($ctx->npcs->present($ctx) as $npc) {
                array_push($offers, ...$this->offers($ctx, $npc['id']));
            }
            $q = World::simplify($quest);
            $hit = null;
            foreach ($offers as $o) {
                if (World::simplify($o['id']) === $q || World::simplify($o['title']) === $q || ($q !== '' && str_contains(World::simplify($o['title']), $q))) {
                    $hit = $o;
                    break;
                }
            }
            if ($hit === null && count($offers) === 1 && $q === '') {
                $hit = $offers[0];
            }
            if ($hit === null) {
                return ['siker' => false, 'esemeny' => $offers ? 'Nincs ilyen felajánlott megbízás.' : 'Itt most senki nem ajánl megbízást.',
                    'ajanlatok' => array_map(fn ($o) => $o['title'], $offers)];
            }
            if (!$accept) {
                $ctx->state->setQuest($hit['id'], ['allapot' => 'elutasitva']);
                $ctx->passTime(1);

                return ['siker' => true, 'esemeny' => sprintf('A játékos visszautasította: „%s”.%s', $hit['title'],
                    $hit['repeatable'] ? ' Később még elvállalhatja.' : ' Ez a lehetőség elveszett.')];
            }
            $ctx->state->setQuest($hit['id'], ['allapot' => 'folyamatban',
                'hatarido' => $hit['deadline_minutes'] !== null ? $ctx->minute + (int) $hit['deadline_minutes'] : null]);
            Rules::apply($hit['accept_effects'], $ctx);
            $ctx->passTime(2);

            return ['siker' => true, 'esemeny' => sprintf('A játékos elvállalta: „%s”.', $hit['title']), 'megbizas' => $this->questView($ctx, $hit)];
        });
    }

    private function loansView(Context $ctx): array
    {
        $out = [];
        foreach ($ctx->state->loans() as $npc => $l) {
            $out[] = array_filter([
                'kinek'        => $l['nev'] ?? $npc,
                'tartozas'     => trim($l['tartozas'].' '.$this->moneyUnit($ctx)),
                'hatarido'     => self::formatTime((int) $l['hatarido']),
                'lejart'       => $ctx->minute > (int) $l['hatarido'] ? true : null,
            ], fn ($v) => $v !== null);
        }

        return $out;
    }

    /** Kölcsönkérés egy jelen lévő szereplőtől (ha ad kölcsönt, és a feltételei teljesülnek). */
    public function borrow(int $playerId, string $npc): array
    {
        return $this->run($playerId, 'beszel', function (Context $ctx) use ($npc) {
            [$who, $alts] = $ctx->npcs->find($npc, $ctx->npcs->present($ctx));
            if ($who === null) {
                return $this->npcNotFound($npc, $alts);
            }
            $cfg = json_decode((string) ($who['loan'] ?? ''), true);
            $money = $ctx->state->moneyStat();
            if (!is_array($cfg) || empty($cfg['osszeg']) || $money === null) {
                return ['siker' => false, 'esemeny' => sprintf('%s nem ad kölcsönt.', $who['name'])];
            }
            if ($ctx->state->loan($who['id']) !== null) {
                return ['siker' => false, 'esemeny' => 'Előbb a régi tartozást kellene megadni.', 'tartozasok' => $this->loansView($ctx)];
            }
            if (!Rules::check((array) ($cfg['feltetelek'] ?? []), $ctx, ['muvelet' => 'beszel', 'szereplo' => $who['id']])) {
                return ['siker' => false, 'esemeny' => sprintf('%s még nem bízik annyira a játékosban, hogy kölcsönt adjon.', $who['name'])];
            }
            $sum = (int) $cfg['osszeg'];
            $due = (int) ceil($sum * (1 + (int) ($cfg['kamat'] ?? 0) / 100));
            $ctx->addStat($money, $sum);
            $ctx->state->setLoan($who['id'], ['nev' => $who['name'], 'tartozas' => $due,
                'hatarido' => $ctx->minute + max(1, (int) ($cfg['napok'] ?? 3)) * 1440, 'kesedelmi_kamat' => (int) ($cfg['kesedelmi_kamat'] ?? 10)]);
            $ctx->passTime(5);

            return ['siker' => true, 'esemeny' => sprintf('%s kölcsönadott %d-t. Vissza kell fizetni: %d, határidő: %s. Késés esetén naponta késedelmi kamat jár, és romlik a viszony.',
                $who['name'], $sum, $due, self::formatTime($ctx->minute - 5 + max(1, (int) ($cfg['napok'] ?? 3)) * 1440))];
        });
    }

    /** Tartozás visszafizetése (részben is lehet). */
    public function repay(int $playerId, string $npc, ?int $amount = null): array
    {
        return $this->run($playerId, 'beszel', function (Context $ctx) use ($npc, $amount) {
            [$who, $alts] = $ctx->npcs->find($npc, $ctx->npcs->present($ctx));
            if ($who === null) {
                return $this->npcNotFound($npc, $alts);
            }
            $loan = $ctx->state->loan($who['id']);
            if ($loan === null) {
                return ['siker' => false, 'esemeny' => sprintf('A játékosnak nincs tartozása %s felé.', $who['name'])];
            }
            $money = (string) $ctx->state->moneyStat();
            $have = (int) floor($ctx->state->stat($money));
            $pay = min($amount ?? (int) $loan['tartozas'], (int) $loan['tartozas'], $have);
            if ($pay <= 0) {
                return ['siker' => false, 'esemeny' => 'Nincs nála pénz, amiből fizethetne.'];
            }
            $ctx->addStat($money, -$pay);
            $loan['tartozas'] -= $pay;
            $ctx->passTime(2);
            if ($loan['tartozas'] <= 0) {
                $ctx->state->setLoan($who['id'], null);
                $late = ($loan['kesett_napok'] ?? 0) > 0;
                if (!$late) {
                    $ctx->state->addRelation($who['id'], 2);
                }

                return ['siker' => true, 'esemeny' => sprintf('A játékos kifizette a teljes tartozását %s felé.%s', $who['name'],
                    $late ? ' (Késve, ezt nem felejti el.)' : ' Időben fizetett, ez javította a viszonyukat.')];
            }
            $ctx->state->setLoan($who['id'], $loan);

            return ['siker' => true, 'esemeny' => sprintf('A játékos törlesztett %d-t; még %d a tartozás.', $pay, $loan['tartozas'])];
        });
    }

    /** @return list<array> az itteni munkák */
    private function jobsHere(Context $ctx): array
    {
        $stmt = $this->db->prepare('SELECT * FROM jobs WHERE game_id = ? AND location_id = ? ORDER BY sort_order, id');
        $stmt->execute([$ctx->gameId(), $ctx->location]);

        return $stmt->fetchAll();
    }

    private function jobView(Context $ctx, array $j): array
    {
        $why = $this->jobBlocker($ctx, $j);

        return array_filter([
            'id'        => $j['id'],
            'munka'     => $j['title'],
            'leiras'    => $j['description'],
            'ido'       => sprintf('%d óra', intdiv((int) $j['minutes'], 60)).((int) $j['minutes'] % 60 ? sprintf(' %d perc', (int) $j['minutes'] % 60) : ''),
            'fizetes'   => trim($j['pay'].' '.$this->moneyUnit($ctx)),
            'most'      => $why === null ? 'vállalható' : $why,
        ], fn ($v) => $v !== null && $v !== '');
    }

    private function jobBlocker(Context $ctx, array $j): ?string
    {
        if ($j['npc_id'] !== null && $ctx->npcs->whereIs($j['npc_id'], $ctx) !== $ctx->location) {
            return 'most nincs itt, aki munkát adna';
        }
        if ($ctx->state->counter('napi_munka:'.$j['id']) >= (int) $j['daily_limit']) {
            return 'mára már elvégezte';
        }
        if (!Rules::check(json_decode((string) $j['conditions'], true) ?: [], $ctx, ['muvelet' => 'dolgozik', 'munka' => $j['id']])) {
            return 'most nem vállalható (időpont, ápoltság vagy erőnlét miatt)';
        }

        return null;
    }

    /** Munka: időért pénz. Munka nélkül megadva az itteni lehetőségeket sorolja fel. */
    public function work(int $playerId, ?string $job = null): array
    {
        return $this->run($playerId, 'dolgozik', function (Context $ctx) use ($job) {
            $jobs = $this->jobsHere($ctx);
            if (!$jobs) {
                return ['siker' => false, 'esemeny' => 'Itt nincs munkalehetőség.'];
            }
            $q = World::simplify((string) $job);
            $hit = count($jobs) === 1 ? $jobs[0] : null;
            foreach ($jobs as $j) {
                if ($q !== '' && (World::simplify($j['id']) === $q || str_contains(World::simplify($j['title']), $q))) {
                    $hit = $j;
                }
            }
            if ($hit === null) {
                return ['siker' => false, 'esemeny' => 'Melyik munkát vállalja?', 'munkak' => array_map(fn ($j) => $this->jobView($ctx, $j), $jobs)];
            }
            if (($why = $this->jobBlocker($ctx, $hit)) !== null) {
                $cond = json_decode((string) $hit['conditions'], true) ?: [];
                return ['siker' => false, 'esemeny' => sprintf('„%s”: %s.', $hit['title'], $why), 'feltetelek' => $cond];
            }

            return $this->rules($ctx)->around($ctx, ['muvelet' => 'dolgozik', 'munka' => $hit['id']], function () use ($ctx, $hit) {
                $ctx->passTime((int) $hit['minutes']);
                if ($ctx->state->end() !== null) {
                    return ['siker' => true, 'esemeny' => 'A munka közben a játékos összeesett.'];
                }
                if (($money = $ctx->state->moneyStat()) !== null) {
                    $ctx->addStat($money, (int) $hit['pay']);
                }
                Rules::apply(json_decode((string) $hit['effects'], true) ?: [], $ctx, ['muvelet' => 'dolgozik', 'munka' => $hit['id']]);
                $ctx->state->increment('napi_munka:'.$hit['id']);

                return ['siker' => true, 'esemeny' => sprintf('A játékos végigdolgozta: %s (%s). Megkapta a fizetését: %s.',
                    $hit['title'], $this->jobView($ctx, $hit)['ido'], trim($hit['pay'].' '.$this->moneyUnit($ctx)))];
            });
        });
    }

    // =====================================================================
    //  A test: alvás, pihenés, tisztálkodás, várakozás
    // =====================================================================

    /** A test beállításai (melyik tulajdonság az energia, a higiénia; mennyit ad az alvás…) */
    private function body(Context $ctx): array
    {
        return ($ctx->settings['test'] ?? []) + [
            'energia'          => 'energia',
            'higienia'         => 'higienia',
            'alvas_orankent'   => 12,
            'alvas_agy_nelkul' => 0.5,
            'pihenes'          => 15,
            'pihenes_naponta'  => 1,
        ];
    }

    /** Alvás: óránként nő az energia (ágyban teljesen, máshol csak részben), amíg ki nem pihenti magát. */
    public function sleep(int $playerId, ?int $hours = null): array
    {
        return $this->run($playerId, 'alszik', function (Context $ctx) use ($hours) {
            $b = $this->body($ctx);
            $stat = (string) $b['energia'];
            $hasStat = $stat !== '' && $ctx->state->hasStat($stat);
            $max = $hasStat ? ($ctx->state->maxOf($stat) ?? 100) : null;
            if ($hasStat && $ctx->state->stat($stat) >= $max * 0.9) {
                return ['siker' => false, 'esemeny' => 'A játékos nem álmos, most nem tudna elaludni.'];
            }
            $hours = max(1, min(12, $hours ?? 8));
            $inBed = in_array('agy', $ctx->features(), true);
            $outside = (float) $b['alvas_agy_nelkul'];
            foreach ($ctx->world->carried() as $i) {  // pl. takaró: ágy nélkül is jobb az alvás
                $outside = max($outside, min(1.0, (float) ($ctx->world->props($i['item_id'])['alvas_szorzo'] ?? 0)));
            }
            $rate = (float) $b['alvas_orankent'] * ($inBed ? 1 : $outside);

            return $this->rules($ctx)->around($ctx, ['muvelet' => 'alszik'], function () use ($ctx, $hours, $hasStat, $stat, $max, $rate, $inBed) {
                $slept = 0;
                for ($i = 0; $i < $hours && $ctx->state->end() === null; $i++) {
                    $ctx->passTime(60);
                    if ($hasStat) {
                        $ctx->addStat($stat, $rate - $ctx->state->perHour($stat)); // az óránkénti fogyás alvás közben nincs
                    }
                    $slept++;
                    if ($hasStat && $ctx->state->stat($stat) >= $max) {
                        break;
                    }
                }

                return [
                    'siker'   => true,
                    'esemeny' => sprintf('A játékos %d órát aludt%s.%s', $slept,
                        $inBed ? ' ágyban' : ' (nem ágyban, ezért kevésbé pihentetően)',
                        $hasStat && $ctx->state->stat($stat) >= $max ? ' Teljesen kipihente magát.' : ''),
                ];
            });
        });
    }

    /** Rövid pihenés: egy kis energia, naponta korlátozott alkalommal. */
    public function rest(int $playerId): array
    {
        return $this->run($playerId, 'pihen', function (Context $ctx) {
            $b = $this->body($ctx);
            if ($ctx->state->counter('napi_pihenes') >= (int) $b['pihenes_naponta']) {
                return ['siker' => false, 'esemeny' => 'Ma már pihent a játékos; egy újabb rövid pihenő nem segítene, csak az alvás.'];
            }

            return $this->rules($ctx)->around($ctx, ['muvelet' => 'pihen'], function () use ($ctx, $b) {
                $ctx->passTime(30);
                $stat = (string) $b['energia'];
                if ($stat !== '' && $ctx->state->hasStat($stat)) {
                    $ctx->addStat($stat, (float) $b['pihenes']);
                }
                $ctx->state->increment('napi_pihenes');

                return ['siker' => true, 'esemeny' => 'A játékos fél órát pihent.'];
            });
        });
    }

    /** Tisztálkodás: mosdóban vagy fürdőben a higiénia a maximumra áll. */
    public function wash(int $playerId): array
    {
        return $this->run($playerId, 'tisztalkodik', function (Context $ctx) {
            $features = $ctx->features();
            $bath = in_array('furdo', $features, true);
            if (!$bath && !in_array('mosdo', $features, true)) {
                return ['siker' => false, 'esemeny' => 'Itt nincs hol megmosakodni (mosdó vagy fürdő kellene).'];
            }

            return $this->rules($ctx)->around($ctx, ['muvelet' => 'tisztalkodik'], function () use ($ctx, $bath) {
                $ctx->passTime($bath ? 30 : 15);
                $stat = (string) $this->body($ctx)['higienia'];
                if ($stat !== '' && $ctx->state->hasStat($stat)) {
                    $ctx->setStat($stat, $ctx->state->maxOf($stat) ?? 100);
                }

                return ['siker' => true, 'esemeny' => $bath ? 'A játékos megfürdött.' : 'A játékos megmosakodott.'];
            });
        });
    }

    /** Várakozás: telik az idő (legfeljebb 12 óra). */
    public function wait(int $playerId, int $minutes): array
    {
        return $this->run($playerId, 'var', function (Context $ctx) use ($minutes) {
            $minutes = max(5, min(720, $minutes));

            return $this->rules($ctx)->around($ctx, ['muvelet' => 'var'], function () use ($ctx, $minutes) {
                $ctx->passTime($minutes);

                return ['siker' => true, 'esemeny' => sprintf('A játékos várt %s.', $minutes >= 60
                    ? sprintf('%d órát%s', intdiv($minutes, 60), $minutes % 60 ? sprintf(' és %d percet', $minutes % 60) : '')
                    : $minutes.' percet')];
            });
        });
    }

    /** Visszatérés a legutóbbi ellenőrző ponthoz (vereség után). */
    public function restoreCheckpoint(int $playerId): array
    {
        [$game, $save] = $this->current($playerId);
        if ($game === null) {
            return $this->noGameSelected($playerId);
        }
        $state = $this->state($save, $game['id']);
        if (($state->end()['tipus'] ?? null) === 'gyozelem') {
            return ['siker' => false, 'esemeny' => 'Ezt a játékot már megnyerte a játékos. Új kalandot az uj_jatek eszközzel kezdhet.'];
        }
        $stmt = $this->db->prepare('SELECT * FROM save_checkpoints WHERE player_id = ? AND game_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$playerId, $game['id']]);
        $cp = $stmt->fetch();
        if (!$cp) {
            return ['siker' => false, 'esemeny' => 'Nincs ellenőrző pont. Az uj_jatek eszközzel elölről kezdhető a játék.'];
        }

        $this->db->beginTransaction();
        $this->db->prepare('UPDATE saves SET location_id = ?, game_minute = ?, state = ? WHERE player_id = ? AND game_id = ?')
            ->execute([$cp['location_id'], $cp['game_minute'], $cp['state'], $playerId, $game['id']]);
        $world = new World($this->db, $playerId, $game['id'], $this->settings($game));
        $world->restore(json_decode((string) $cp['instances'], true) ?: []);
        $this->db->commit();

        $ctx = $this->context($playerId, $game);

        return $this->finish($ctx, [
            'siker'   => true,
            'esemeny' => sprintf('A játékos visszatért a(z) „%s” ellenőrző ponthoz. Meséld el, hogy újra itt van, és folytathatja.', $cp['name']),
        ]);
    }

    /** Az aktuális játék újrakezdése: a mentés visszaáll a játék kezdőállapotára. */
    public function restart(int $playerId): array
    {
        [$game] = $this->current($playerId);
        if ($game === null) {
            return $this->noGameSelected($playerId);
        }

        $this->db->prepare('DELETE FROM saves WHERE player_id = ? AND game_id = ?')->execute([$playerId, $game['id']]);
        $this->createSave($playerId, $game);
        $ctx = $this->context($playerId, $game);

        return $this->finish($ctx, [
            'siker'    => true,
            'esemeny'  => 'A játék elölről kezdődött. Meséld el a bevezetőt, majd a helyszínt.',
            'jatek'    => $this->gameInfo($game),
            'bevezeto' => $game['intro'],
        ]);
    }

    // =====================================================================
    //  A műveletek közös kerete
    // =====================================================================

    /** @param callable(Context): array $body */
    private function run(int $playerId, string $action, callable $body, bool $withLocation = true): array
    {
        [$game, $save] = $this->current($playerId);
        if ($game === null) {
            return $this->noGameSelected($playerId);
        }
        $ctx = $this->context($playerId, $game);

        if ($ctx->state->end() !== null && !in_array($action, self::AFTER_END, true)) {
            return $this->finish($ctx, [
                'siker'   => false,
                'esemeny' => 'A játék véget ért, ez a művelet már nem lehetséges.',
            ], $withLocation);
        }

        $before = $ctx->location;
        $result = $body($ctx);
        $result['siker'] ??= false;
        if ($ctx->blocked !== null && !$result['siker']) {
            $result['esemeny'] = $ctx->blocked;
        }

        // Ugyanazon a helyszínen maradva a panel kicsi (kép nélküli); csak helyváltáskor és körülnézéskor teljes
        $compact = $ctx->location === $before && !in_array($action, ['look', 'menj'], true);

        return $this->finish($ctx, $result, $withLocation || $ctx->location !== $before, $compact);
    }

    /** Mentés, a játék végének ellenőrzése, és a válasz összeállítása. */
    private function finish(Context $ctx, array $result, bool $withLocation = true, bool $compact = false): array
    {
        $wasEnded = $ctx->state->end() !== null;
        if (!$wasEnded) {
            $this->checkQuests($ctx);
            $this->checkEnd($ctx);
        }
        $this->persist($ctx);

        // vereség pillanatában nem készül ellenőrző pont (különben onnan is azonnal vesztene)
        foreach (($ctx->state->end()['tipus'] ?? null) === 'vereseg' ? [] : $ctx->checkpoints as $name) {
            $this->createCheckpoint($ctx, $name);
            $ctx->messages[] = sprintf('Ellenőrző pont: „%s”. Ha a játékos később elbukik, innen folytathatja.', $name);
        }

        if ($ctx->messages) {
            $result['esemenyek'] = array_values(array_unique($ctx->messages));
        }
        if ($ctx->changes) {
            $result['valtozasok'] = $this->describeChanges($ctx->gameId(), $ctx->changes);
        }
        if ($withLocation) {
            $result['helyszin'] = $this->describeLocation($ctx, $ctx->location);
        }
        $result['ido'] = self::formatTime($ctx->minute);
        $result['napszak'] = self::DAY_PARTS[$ctx->dayPart()];
        if (($w = $ctx->state->weather()) !== null) {
            $result['idojaras'] = Context::WEATHER[$w] ?? $w;
        }

        if (($end = $ctx->state->end()) !== null) {
            $result['vege'] = $end + [
                'teendo' => $end['tipus'] === 'gyozelem'
                    ? ($wasEnded ? 'A játékos már megnyerte ezt a játékot.' : 'A játékos GYŐZÖTT! Meséld el ünnepélyesen a befejezést a szöveg alapján.')
                      .' Új kalandot az uj_jatek vagy a jatekok eszközzel kezdhet.'
                    : ($wasEnded ? 'A játék véget ért (vereség).' : 'A játékos ELBUKOTT. Meséld el a szöveg alapján, együttérzően.')
                      .' Felajánlhatod a visszatérést az utolsó ellenőrző ponthoz (vissza_ellenorzo_ponthoz), vagy az újrakezdést (uj_jatek).',
            ];
        }
        $result['panel'] = $this->panel($ctx) + ['kompakt' => $compact && $ctx->state->end() === null];

        return $result;
    }

    private function checkEnd(Context $ctx): void
    {
        // Győzelem: egy céltárgy a játékosnál van
        foreach ($ctx->world->carried() as $inst) {
            $props = $ctx->world->props($inst['item_id']);
            if (!empty($props['cel'])) {
                $ctx->state->setEnd([
                    'tipus'  => 'gyozelem',
                    'szoveg' => (string) ($props['vege_szoveg'] ?? $ctx->settings['gyozelem_szoveg'] ?? 'Elérted a célodat.'),
                    'cel'    => $ctx->world->name($inst),
                ]);
                return;
            }
        }
        // Vereség: a beállításokban megadott feltételek
        foreach ((array) ($ctx->settings['vereseg'] ?? []) as $def) {
            if (is_array($def) && !empty($def['feltetelek']) && Rules::check((array) $def['feltetelek'], $ctx)) {
                $ctx->state->setEnd(['tipus' => 'vereseg', 'szoveg' => (string) ($def['szoveg'] ?? 'Véget ért a kaland.')]);
                return;
            }
        }
    }

    private function persist(Context $ctx): void
    {
        $this->db->prepare('UPDATE saves SET location_id = ?, game_minute = ?, state = ? WHERE player_id = ? AND game_id = ?')
            ->execute([$ctx->location, $ctx->minute, $ctx->state->toJson(), $ctx->playerId, $ctx->gameId()]);
    }

    private function createCheckpoint(Context $ctx, string $name): void
    {
        $this->db->prepare(
            'INSERT INTO save_checkpoints (player_id, game_id, name, location_id, game_minute, state, instances) VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $ctx->playerId, $ctx->gameId(), $name, $ctx->location, $ctx->minute, $ctx->state->toJson(),
            json_encode($ctx->world->snapshot(), JSON_UNESCAPED_UNICODE),
        ]);
        // csak a legutóbbi néhányat tartjuk meg
        $this->db->prepare(
            'DELETE FROM save_checkpoints WHERE player_id = ? AND game_id = ? AND id NOT IN
             (SELECT id FROM (SELECT id FROM save_checkpoints WHERE player_id = ? AND game_id = ? ORDER BY id DESC LIMIT 5) t)'
        )->execute([$ctx->playerId, $ctx->gameId(), $ctx->playerId, $ctx->gameId()]);
    }

    private function context(int $playerId, array $game): Context
    {
        $save = $this->save($playerId, $game['id']);
        $settings = $this->settings($game);
        $state = $this->state($save, $game['id']);
        $world = new World($this->db, $playerId, $game['id'], $settings);

        // Régi mentés (az 1. réteg előttről): a tárgyvilág most jön létre
        if (!$state->flag('_vilag')) {
            $world->initFromTemplate((int) $save['game_minute']);
            $old = $this->db->prepare('SELECT item_id, quantity FROM save_items WHERE player_id = ? AND game_id = ?');
            $old->execute([$playerId, $game['id']]);
            foreach ($old->fetchAll() as $r) {
                $world->create($r['item_id'], (int) $r['quantity'], 'slot', 'zseb', (int) $save['game_minute']);
            }
            $this->db->prepare('DELETE FROM save_items WHERE player_id = ? AND game_id = ?')->execute([$playerId, $game['id']]);
            $state->setFlag('_vilag', true);
        }

        $ctx = new Context($playerId, $game, $settings, $state, $world, $save['location_id'], (int) $save['game_minute']);
        $ctx->rules = new Rules($this->db, $game['id']);
        $ctx->npcs = new Npcs($this->db, $game['id']);
        if ($state->weather() === null && !empty($settings['idojaras'])) {
            $ctx->rollWeather();
        }
        $stmt = $this->db->prepare('SELECT id, features FROM locations WHERE game_id = ?');
        $stmt->execute([$game['id']]);
        foreach ($stmt->fetchAll() as $l) {
            $ctx->featureMap[$l['id']] = json_decode((string) $l['features'], true) ?: [];
        }

        return $ctx;
    }

    private function rules(Context $ctx): Rules
    {
        return $ctx->rules ??= new Rules($this->db, $ctx->gameId());
    }

    private function event(string $action, array $inst): array
    {
        return ['muvelet' => $action, 'targy' => $inst['item_id']];
    }

    /** A játékos által megnevezett hely („bal kéz”, „zseb”, „szatyor”) */
    private function resolvePlace(Context $ctx, string $where): array
    {
        $q = World::simplify($where);
        foreach (['bal' => 'bal_kez', 'jobb' => 'jobb_kez', 'zseb' => 'zseb', 'hat' => 'hat'] as $word => $slot) {
            if (str_contains($q, $word)) {
                return ['slot', $slot, null];
            }
        }
        if (str_contains($q, 'kez')) {
            return $ctx->world->at('slot', 'jobb_kez') ? ['slot', 'bal_kez', null] : ['slot', 'jobb_kez', null];
        }
        $w = $ctx->world;
        [$box] = $w->findByName($where, array_filter($w->carried(), fn ($i) => isset($w->props($i['item_id'])['tarolo'])));
        if ($box !== null) {
            return ['container', (string) $box['id'], null];
        }

        return [null, null, sprintf('Nem tudod, hova tennéd: „%s”.', $where)];
    }

    private function notFound(string $query, array $alternatives, string $scope): array
    {
        if (count($alternatives) > 1) {
            return ['siker' => false, 'esemeny' => sprintf('Több dologra is illik: „%s”. Melyikre gondol a játékos?', $query), 'lehetosegek' => $alternatives];
        }
        $where = match ($scope) {
            'itt'             => 'itt nincs',
            'nala'            => 'nincs a játékosnál',
            'tarolo'          => 'nincs ilyen tároló a közelben',
            'tarolo_tartalma' => 'nincs a tárolóban',
            default           => 'nincs se a játékosnál, se a közelben',
        };

        return ['siker' => false, 'esemeny' => sprintf('„%s”: %s.', $query, $where)];
    }

    private function capacityView(Context $ctx): array
    {
        $w = $ctx->world;
        $used = fn (string $slot) => array_sum(array_map(fn ($i) => $w->size($i) * (int) $i['quantity'], $w->at('slot', $slot)));

        return [
            'zseb'     => sprintf('%d / %d', $used('zseb'), (int) ($ctx->settings['zseb_kapacitas'] ?? 2)),
            'bal_kez'  => $w->at('slot', 'bal_kez') ? 'foglalt' : 'szabad',
            'jobb_kez' => $w->at('slot', 'jobb_kez') ? 'foglalt' : 'szabad',
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

    private function settings(array $game): array
    {
        $s = json_decode((string) ($game['settings'] ?? '{}'), true);

        return is_array($s) ? $s : [];
    }

    /** Játék keresése azonosító vagy cím (részlet) alapján, ékezetektől függetlenül. */
    private function findGame(string $query): ?array
    {
        $q = World::simplify($query);
        if ($q === '') {
            return null;
        }

        $games = $this->db->query('SELECT * FROM games WHERE active = 1 ORDER BY sort_order')->fetchAll();

        foreach ($games as $g) {
            if (World::simplify($g['id']) === $q) {
                return $g;
            }
        }

        $matches = array_values(array_filter($games, fn (array $g) =>
            str_contains(World::simplify($g['title']), $q) || str_contains($q, World::simplify($g['id']))));

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
        $state->setFlag('_vilag', true);
        $this->db->prepare(
            'INSERT INTO saves (player_id, game_id, location_id, game_minute, state) VALUES (?, ?, ?, ?, ?)'
        )->execute([$playerId, $game['id'], $game['start_location'], $game['start_minute'], $state->toJson()]);

        $world = new World($this->db, $playerId, $game['id'], $this->settings($game));
        $world->initFromTemplate((int) $game['start_minute']);

        // Az első ellenőrző pont: a kezdet
        $this->db->prepare(
            'INSERT INTO save_checkpoints (player_id, game_id, name, location_id, game_minute, state, instances) VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$playerId, $game['id'], 'A kezdet', $game['start_location'], $game['start_minute'], $state->toJson(),
            json_encode($world->snapshot(), JSON_UNESCAPED_UNICODE)]);
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

    /** @param array<string, array{0:float,1:float}> $changes */
    private function describeChanges(string $gameId, array $changes): array
    {
        $labels = array_column($this->statDefs($gameId), 'label', 'id');
        $out = [];
        foreach ($changes as $id => [$from, $to]) {
            if (round($from) != round($to)) {
                $out[] = ['tulajdonsag' => $labels[$id] ?? $id, 'elotte' => round($from), 'utana' => round($to)];
            }
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

    private function describeLocation(Context $ctx, string $locationId): array
    {
        $gameId = $ctx->gameId();
        $loc = $this->locationRow($gameId, $locationId);

        $stmt = $this->db->prepare(
            'SELECT e.direction, e.description, e.conditions, l.name AS target, l.open_hours
             FROM exits e JOIN locations l ON l.game_id = e.game_id AND l.id = e.to_location
             WHERE e.game_id = ? AND e.from_location = ? ORDER BY e.direction'
        );
        $stmt->execute([$gameId, $locationId]);
        $exits = array_map(function (array $e) use ($ctx) {
            $out = ['irany' => $e['direction'], 'leiras' => $e['description'], 'celpont' => $e['target']];
            $conds = $e['conditions'] ? (json_decode((string) $e['conditions'], true) ?: []) : [];
            if (!$ctx->isOpen($e['open_hours'])) {
                $out['akadaly'] = true;
                $out['zarva'] = 'nyitva: '.$e['open_hours'];
            } elseif ($conds && !Rules::check($conds, $ctx, ['muvelet' => 'menj', 'irany' => $e['direction']])) {
                $out['akadaly'] = true; // látszik, de most nem járható
            }
            return $out;
        }, $stmt->fetchAll());

        return array_filter([
            'id'            => $loc['id'],
            'nev'           => $loc['name'],
            'leiras'        => $loc['description'],
            'felszereltseg' => json_decode((string) ($loc['features'] ?? '[]'), true) ?: null,
            'nyitvatartas'  => $loc['open_hours'] ?: null,
            'tuz'           => ($f = $ctx->fireAt($locationId)) ? 'ég egy tűz, kb. '.self::formatTime($f).'-ig' : null,
            'jarmuben'      => ($v = $this->vehicle($ctx)) ? $ctx->world->name($v) : null,
            'kijaratok'     => $exits,
            'targyak'       => $ctx->world->locationTree($locationId),
            'latnivalok'    => array_column($this->details($ctx, false), 'name') ?: null,
            'szereplok'     => array_map(fn ($n) => ['nev' => $n['name'], 'leiras' => $n['description']], $ctx->npcs->present($ctx)) ?: null,
            'munkak'        => array_map(fn ($j) => $this->jobView($ctx, $j), $this->jobsHere($ctx)) ?: null,
            'kereskedes'    => array_map(fn ($l) => $ctx->npcs->tradeView($l, $ctx->world, $ctx, $this->locationNames($gameId)), $ctx->npcs->tradesHere($ctx)) ?: null,
            'kep'           => $this->imageFor($gameId, $loc, $ctx->state, $ctx->minute),
        ], fn ($v) => $v !== null);
    }

    private function locationRow(string $gameId, string $locationId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM locations WHERE game_id = ? AND id = ?');
        $stmt->execute([$gameId, $locationId]);

        return $stmt->fetch() ?: null;
    }

    /** @return list<string> */
    private function exitDirections(string $gameId, string $locationId): array
    {
        $stmt = $this->db->prepare('SELECT direction FROM exits WHERE game_id = ? AND from_location = ? ORDER BY direction');
        $stmt->execute([$gameId, $locationId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** A képpanel adatai (MCP Apps): a játékos aktuális állapota egy helyen. */
    private function panel(Context $ctx): array
    {
        $loc = $this->describeLocation($ctx, $ctx->location);

        return [
            'jatek'         => $ctx->game['title'],
            'helyszin'      => $loc['nev'],
            'kep'           => $loc['kep'] ?? null,
            'ido'           => self::formatTime($ctx->minute),
            'napszak'       => $ctx->dayPart(),
            'idojaras'      => $ctx->state->weather(),
            'feladatok'     => array_values(array_merge(
                array_map(fn ($q) => ['cim' => $q['title'], 'hatarido' => isset(($ctx->state->quest($q['id']) ?? [])['hatarido']) ? self::formatTime((int) $ctx->state->quest($q['id'])['hatarido']) : null],
                    array_filter($this->questDefs($ctx->gameId()), fn ($q) => ($ctx->state->quest($q['id'])['allapot'] ?? null) === 'folyamatban')),
                array_map(fn ($l) => ['cim' => 'Tartozás: '.$l['kinek'].' ('.$l['tartozas'].')', 'hatarido' => $l['hatarido']], $this->loansView($ctx)))),
            'tulajdonsagok' => $ctx->state->statsView(),
            'leltar'        => $ctx->world->inventoryTree(),
            'szereplok'     => array_map(fn ($n) => $n['nev'], $loc['szereplok'] ?? []),
            'itt'           => array_map(fn ($n) => ['nev' => $n['nev'], 'darab' => $n['darab']], $ctx->world->locationTree($ctx->location)),
            'kijaratok'     => array_map(
                fn (array $e) => ['irany' => Direction::label($e['irany']), 'celpont' => $e['celpont'], 'akadaly' => !empty($e['akadaly'])],
                $loc['kijaratok']
            ),
            'retegek'       => $this->layersFor($ctx),
            'kozelkep'      => $ctx->closeup,
            'tuz'           => $ctx->fireAt($ctx->location) !== null,
            'jarmu'         => $loc['jarmuben'] ?? null,
            'vege'          => $ctx->state->end(),
            'zene'          => null, // később: a játék hangulatzene-oldala
        ];
    }

    /**
     * A helyszínképre rajzolandó rétegek: a földön heverő tárgyak és a jelen lévő szereplők képei.
     * Csak az kerül rá, aminek van képe ÉS a helyszínen megadott helye; a többi egyszerűen kimarad.
     *
     * @return list<array{kep: string, x: float, y: float, w: float}>
     */
    private function layersFor(Context $ctx): array
    {
        $loc = $this->locationRow($ctx->gameId(), $ctx->location);
        $pos = json_decode((string) ($loc['layers'] ?? '{}'), true);
        if (!is_array($pos) || $pos === []) {
            return [];
        }
        $out = [];
        $add = function (string $key, string $sub, string $id, int $copies) use ($ctx, $pos, &$out): void {
            $p = $pos[$key] ?? null;
            if (!is_array($p) || !isset($p['x'], $p['y'], $p['w'])) {
                return;
            }
            $url = $this->spriteUrl($ctx->gameId(), $sub, $id);
            if ($url === null) {
                return;
            }
            for ($i = 0; $i < min(3, max(1, $copies)); $i++) {  // több darabból legfeljebb hármat rajzol, kicsit eltolva
                $out[] = ['kep' => $url, 'x' => round((float) $p['x'] + $i * (float) $p['w'] * 0.55, 2), 'y' => (float) $p['y'], 'w' => (float) $p['w']];
            }
        };
        foreach ($ctx->world->at('location', $ctx->location) as $inst) {
            $add($inst['item_id'], 'targyak', $inst['item_id'], (int) $inst['quantity']);
        }
        foreach ($ctx->npcs?->present($ctx) ?? [] as $npc) {
            $add('@'.$npc['id'], 'szereplok', $npc['id'], 1);
        }
        usort($out, fn ($a, $b) => $a['y'] <=> $b['y']); // ami lejjebb van, az kerül előre

        return $out;
    }

    /** @var array<string, ?string> */
    private array $spriteCache = [];

    private function spriteUrl(string $gameId, string $sub, string $id): ?string
    {
        $key = $gameId.'/'.$sub.'/'.$id;
        if (!array_key_exists($key, $this->spriteCache)) {
            $this->spriteCache[$key] = null;
            if ($this->imageDir !== null && $this->imageUrl !== null && preg_match('/^[a-z0-9_]+$/', $id)) {
                foreach (['webp', 'png'] as $ext) {
                    $file = $this->imageDir.'/'.$key.'.'.$ext;
                    if (is_file($file)) {
                        $this->spriteCache[$key] = $this->imageUrl.'/'.$key.'.'.$ext.'?v='.filemtime($file);
                        break;
                    }
                }
            }
        }

        return $this->spriteCache[$key];
    }

    /**
     * A helyszín képének nyilvános címe.
     * 1. ha az adatbázisban meg van adva (locations.image): azt használja
     *    (teljes URL, vagy az assets/images mappához képesti útvonal);
     * 0. ha a mentésben a "kep:<helyszín>" jelző egy változatot ad meg, és van ilyen fájl: <helyszín>~<változat>.webp;
     *    utána a napszak szerinti változat: <helyszín>~ejszaka.webp (hajnal, nappal, este, ejszaka)
     * 2. különben megkeresi az assets/images/<játék>/<helyszín>.webp|jpg|jpeg|png fájlt.
     */
    private function imageFor(string $gameId, array $loc, ?State $state = null, ?int $minute = null): ?string
    {
        // Képváltozat: a "kep:<helyszín>" jelző értéke, pl. park_pad~ejjel.webp
        $variants = [];
        $flag = $state?->flag('kep:'.$loc['id']);
        if (is_string($flag)) {
            $variants[] = $flag;
        }
        if ($minute !== null && $state?->weather() !== null) {
            $variants[] = $state->weather().'-'.Context::dayPartOf($minute); // pl. park_pad~esos-ejszaka.webp
            $variants[] = (string) $state->weather();                         // pl. park_pad~esos.webp
        }
        if ($minute !== null) {
            $variants[] = Context::dayPartOf($minute); // pl. park_pad~ejszaka.webp
        }
        foreach ($variants as $variant) {
            if (!preg_match('/^[a-z0-9_-]+$/', $variant) || $this->imageDir === null || $this->imageUrl === null) {
                continue;
            }
            foreach (self::IMAGE_EXTENSIONS as $ext) {
                $relative = $gameId.'/'.$loc['id'].'~'.$variant.'.'.$ext;
                if (is_file($this->imageDir.'/'.$relative)) {
                    return $this->imageUrl.'/'.$relative.'?v='.filemtime($this->imageDir.'/'.$relative);
                }
            }
        }

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
}
