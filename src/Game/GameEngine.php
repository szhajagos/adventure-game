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

        $rules = $this->rules($ctx);

        return $rules->around($ctx, $event, function () use ($ctx, $exit, $dir, $rules) {
            $minutes = (int) $exit['minutes'];
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
                'esemeny' => sprintf('A játékos elsétált ide: %s (%s), az út %d percig tartott. Az útvonal ismerős, elég röviden elmesélni.',
                    $known[$goal], implode(' → ', $route), $minutes),
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
                return $this->notFound($item, $alts, 'elerheto');
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
                $ctx->world->decrease($inst, 1);
                $ctx->passTime(self::MINUTES['fogyaszt']);

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
            'utmutatas'    => 'Beszélj a szereplő nevében, a személyisége és a mai kedve szerint. Csak a témáiban szereplő tényeket mondhatja el; '
                .'ha a játékos egy témáról kérdez, hívd a beszel eszközt a téma nevével.',
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
            $rate = (float) $b['alvas_orankent'] * ($inBed ? 1 : (float) $b['alvas_agy_nelkul']);

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
            'kijaratok'     => $exits,
            'targyak'       => $ctx->world->locationTree($locationId),
            'szereplok'     => array_map(fn ($n) => ['nev' => $n['name'], 'leiras' => $n['description']], $ctx->npcs->present($ctx)) ?: null,
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
            'tulajdonsagok' => $ctx->state->statsView(),
            'leltar'        => $ctx->world->inventoryTree(),
            'szereplok'     => array_map(fn ($n) => $n['nev'], $loc['szereplok'] ?? []),
            'itt'           => array_map(fn ($n) => ['nev' => $n['nev'], 'darab' => $n['darab']], $ctx->world->locationTree($ctx->location)),
            'kijaratok'     => array_map(
                fn (array $e) => ['irany' => Direction::label($e['irany']), 'celpont' => $e['celpont'], 'akadaly' => !empty($e['akadaly'])],
                $loc['kijaratok']
            ),
            'retegek'       => $this->layersFor($ctx),
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
