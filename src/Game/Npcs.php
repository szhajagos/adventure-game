<?php

declare(strict_types=1);

namespace AdventureGame\Game;

use PDO;

/**
 * Szereplők és kereskedés egy játékban: ki hol van most, milyen kedvében van,
 * mit árul vagy vásárol fel, és mennyiért.
 */
final class Npcs
{
    public const MOODS = [1 => 'nagyon rosszkedvű', 2 => 'morcos', 3 => 'átlagos kedvű', 4 => 'jókedvű', 5 => 'ragyogó kedvű'];

    /** @var array<string, array> */
    private array $npcs = [];
    /** @var list<array> */
    private array $trades;
    private PDO $db;

    public function __construct(PDO $db, string $gameId)
    {
        $this->db = $db;
        $stmt = $db->prepare('SELECT * FROM npcs WHERE game_id = ? ORDER BY sort_order, name');
        $stmt->execute([$gameId]);
        foreach ($stmt->fetchAll() as $n) {
            $n['schedule'] = json_decode((string) $n['schedule'], true) ?: [];
            $this->npcs[$n['id']] = $n;
        }
        $stmt = $db->prepare('SELECT * FROM trades WHERE game_id = ? ORDER BY sort_order, id');
        $stmt->execute([$gameId]);
        $this->trades = array_map(function (array $t) {
            $t['conditions'] = json_decode((string) $t['conditions'], true) ?: [];
            return $t;
        }, $stmt->fetchAll());
    }

    public function get(string $id): ?array
    {
        return $this->npcs[$id] ?? null;
    }

    /** Hol van most a szereplő (a beosztása szerint)? null: sehol (nincs szolgálatban). */
    public function whereIs(string $npcId, Context $ctx): ?string
    {
        foreach ($this->npcs[$npcId]['schedule'] ?? [] as $slot) {
            $from = $slot['tol'] ?? null;
            $to = $slot['ig'] ?? null;
            if ($from === null || $to === null || $ctx->isOpen($from.'-'.$to)) {
                return $slot['helyszin'] ?? null;
            }
        }

        return null;
    }

    /** @return list<array> a játékos helyszínén most jelen lévő szereplők */
    public function present(Context $ctx): array
    {
        return array_values(array_filter($this->npcs, fn ($n) => $this->whereIs($n['id'], $ctx) === $ctx->location));
    }

    /** @return array{0: ?array, 1: list<string>} */
    public function find(string $query, array $candidates): array
    {
        $q = World::simplify($query);
        $hits = array_values(array_filter($candidates, function ($n) use ($q) {
            $name = World::simplify($n['name']);
            return $q !== '' && ($name === $q || World::simplify($n['id']) === $q || str_contains($name, $q) || str_contains($q, World::simplify($n['id'])));
        }));
        if (count($hits) === 1) {
            return [$hits[0], []];
        }
        if (!$hits && count($candidates) === 1 && preg_match('/^(o|ot|vele|neki|elado|arus)$/', $q)) {
            return [$candidates[0], []];
        }

        return [null, array_map(fn ($n) => $n['name'], $hits ?: $candidates)];
    }

    /** A szereplő mai kedve (1–5): naponta egyszer sorsolódik, és a mentésben marad, így nem lehet újradobni. */
    public function mood(string $npcId, Context $ctx): int
    {
        $day = intdiv($ctx->minute, 1440);
        $stored = (string) ($ctx->state->flag('_hangulat:'.$npcId) ?? '');
        if (preg_match('/^(\d+):([1-5])$/', $stored, $m) && (int) $m[1] === $day) {
            return (int) $m[2];
        }
        // a szélsőségek ritkábbak: 1 és 5 egy-egy, a többi két-két esély a nyolcból
        $mood = [1, 2, 2, 3, 3, 4, 4, 5][random_int(0, 7)];
        $ctx->state->setFlag('_hangulat:'.$npcId, $day.':'.$mood);

        return $mood;
    }

    /** @return list<array> a beszélgetési témák, amelyeknek a feltétele most teljesül */
    public function topics(string $npcId, Context $ctx): array
    {
        $stmt = $this->db->prepare('SELECT * FROM npc_topics WHERE game_id = ? AND npc_id = ? ORDER BY sort_order, id');
        $stmt->execute([$ctx->gameId(), $npcId]);

        return array_values(array_filter($stmt->fetchAll(), fn ($t) =>
            Rules::check(json_decode((string) $t['conditions'], true) ?: [], $ctx, ['muvelet' => 'beszel', 'szereplo' => $npcId])));
    }

    public function findTopic(string $query, array $topics): ?array
    {
        $q = World::simplify($query);
        if ($q === '') {
            return null;
        }
        foreach ($topics as $t) {
            if (World::simplify($t['title']) === $q) {
                return $t;
            }
        }
        foreach ($topics as $t) {
            $words = array_filter(array_map([World::class, 'simplify'], explode(',', $t['title'].','.(string) $t['keywords'])));
            foreach ($words as $w) {
                if (str_contains($q, $w) || str_contains($w, $q)) {
                    return $t;
                }
            }
        }

        return null;
    }

    // =====================================================================
    //  Kereskedés
    // =====================================================================

    /** @return list<array> az itt elérhető kereskedési sorok (jelen lévő szereplők és a helyszín automatái) */
    public function tradesHere(Context $ctx, ?string $mode = null): array
    {
        $present = array_column($this->present($ctx), 'id');

        return array_values(array_filter($this->trades, fn ($t) =>
            ($mode === null || $t['mode'] === $mode)
            && (($t['trader_type'] === 'location' && $t['trader_id'] === $ctx->location)
                || ($t['trader_type'] === 'npc' && in_array($t['trader_id'], $present, true)))));
    }

    public function traderName(array $trade, array $locationNames): string
    {
        return $trade['trader_type'] === 'npc'
            ? (string) ($this->npcs[$trade['trader_id']]['name'] ?? $trade['trader_id'])
            : (string) ($locationNames[$trade['trader_id']] ?? $trade['trader_id']);
    }

    /** Hány darab fér még bele a mai keretbe (null: korlátlan) */
    public function remaining(array $trade, Context $ctx): ?int
    {
        if ($trade['daily_limit'] === null) {
            return null;
        }

        return max(0, (int) $trade['daily_limit'] - $ctx->state->counter('keszlet:'.$trade['id']));
    }

    /**
     * A darabár a mai kedv, a kapcsolat és a hírnév szerint (csak pénzes kereskedésnél).
     * A szereplők árai mozognak; az automaták mindig a megadott áron dolgoznak.
     */
    public function unitPrice(array $trade, World $world, Context $ctx): ?int
    {
        if ($trade['price_item_id'] !== null) {
            return null;
        }
        $base = $trade['price'] !== null ? (int) $trade['price'] : (int) ($world->type($trade['item_id'])['price'] ?? 0);
        if ($trade['trader_type'] !== 'npc') {
            return $base;
        }

        $adj = [1 => 0.2, 2 => 0.1, 3 => 0.0, 4 => 0.0, 5 => -0.1][$this->mood($trade['trader_id'], $ctx)];
        $rel = $ctx->state->relation($trade['trader_id']);
        $adj += $rel >= 5 ? -0.1 : ($rel < 0 ? 0.2 : 0.0);
        $repStat = (string) ($ctx->settings['reputacio'] ?? '');
        if ($repStat !== '' && $ctx->state->hasStat($repStat)) {
            $rep = $ctx->state->stat($repStat);
            $adj += $rep < 0 ? 0.1 : ($rep >= 10 ? -0.05 : 0.0);
        }
        // eladáskor (a szereplő árul) drágább, felvásárláskor kevesebbet ad
        $mult = $trade['mode'] === 'arul' ? 1 + $adj : 1 - $adj;

        return max($base > 0 ? 1 : 0, (int) round($base * $mult));
    }

    /** A kereskedési sor a mesélőnek és a panelnek */
    public function tradeView(array $trade, World $world, Context $ctx, array $locationNames): array
    {
        $price = $this->unitPrice($trade, $world, $ctx);
        $money = $ctx->state->moneyStat();
        $unit = '';
        foreach ($ctx->state->statsView() as $s) {
            if ($s['id'] === $money) {
                $unit = (string) ($s['egyseg'] ?? '');
            }
        }

        return array_filter([
            'ki'        => $this->traderName($trade, $locationNames),
            'mod'       => $trade['mode'] === 'arul' ? 'árul' : 'felvásárol',
            'targy'     => $world->type($trade['item_id'])['name'] ?? $trade['item_id'],
            'ar'        => $price !== null ? trim($price.' '.$unit) : null,
            'csere'     => $trade['price_item_id'] !== null ? ($world->type($trade['price_item_id'])['name'] ?? $trade['price_item_id']) : null,
            'ma_meg'    => $this->remaining($trade, $ctx),
        ], fn ($v) => $v !== null);
    }
}
