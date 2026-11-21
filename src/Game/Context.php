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
    /** @var array<string, list<string>> helyszín => felszereltség */
    public array $featureMap = [];
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
        return $this->featureMap[$this->location] ?? [];
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
