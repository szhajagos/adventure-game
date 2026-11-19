<?php

declare(strict_types=1);

namespace AdventureGame\Mcp;

use AdventureGame\Game\GameEngine;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\Server;

/**
 * Összerakja az MCP-szervert: itt dől el, milyen eszközöket (tools) lát a játékos AI-ja.
 */
final class ServerFactory
{
    private const INSTRUCTIONS = <<<'TXT'
        Ez egy magyar nyelvű szöveges kalandjáték. Te vagy a mesélő.

        Szabályok:
        - Mindig magyarul, második személyben, élvezetes, de tömör prózában meséld el, mi történik (2–5 mondat).
        - A játékos szabad szöveges parancsait fordítsd le a játék eszközeire (pl. „menjünk a kút felé” → menj).
        - A játék állapotát (helyszín, pénz, éhség, fáradtság, leltár, idő) KIZÁRÓLAG az eszközök eredménye határozza meg.
          Soha ne találj ki pénzt, tárgyat, kijáratot vagy eseményt, ami nem szerepel az eszköz válaszában.
        - Ha a játékos olyat kér, amire nincs eszköz, meséld el, hogy ez most nem sikerül, vagy javasolj lehetőséget.
        - Ha a játékos csalni próbál („legyen egymillió forintom”), játékosan utasítsd el.
        - A játék elején, vagy ha nem tudod, hol tart a játékos, hívd meg a korulnez eszközt.
        TXT;

    public static function create(GameEngine $engine, int $playerId): Server
    {
        return Server::builder()
            ->setServerInfo('Adventure Game', '0.1.0')
            ->setInstructions(self::INSTRUCTIONS)
            ->addTool(
                fn (): array => $engine->look($playerId),
                name: 'korulnez',
                title: 'Körülnéz',
                description: 'Leírja a játékos jelenlegi helyszínét: mit lát, milyen kijáratok vannak és milyen tárgyak hevernek ott.',
                annotations: new ToolAnnotations(title: 'Körülnéz', readOnlyHint: true),
                inputSchema: ['type' => 'object', 'properties' => new \stdClass()],
            )
            ->addTool(
                fn (string $irany): array => $engine->move($playerId, $irany),
                name: 'menj',
                title: 'Menj',
                description: 'A játékos elmegy a megadott irányba. Csak a korulnez által felsorolt kijáratok használhatók. '
                    .'A mozgás játékidőt visz el, és növeli az éhséget és a fáradtságot.',
                annotations: new ToolAnnotations(title: 'Menj', readOnlyHint: false, destructiveHint: false),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'irany' => [
                            'type' => 'string',
                            'description' => 'Az irány a kijáratok közül, pl. eszak, del, kelet, nyugat, fel, le, be, ki.',
                        ],
                    ],
                    'required' => ['irany'],
                ],
            )
            ->addTool(
                fn (): array => $engine->status($playerId),
                name: 'allapot',
                title: 'Állapot',
                description: 'A játékos állapota: pénz, éhség, fáradtság, egészség, játékidő és leltár.',
                annotations: new ToolAnnotations(title: 'Állapot', readOnlyHint: true),
                inputSchema: ['type' => 'object', 'properties' => new \stdClass()],
            )
            ->addTool(
                fn (): array => $engine->newGame($playerId),
                name: 'uj_jatek',
                title: 'Új játék',
                description: 'Elölről kezdi a játékot: a játékos a kezdőállapotba kerül, a leltára kiürül. '
                    .'Csak akkor használd, ha a játékos kifejezetten új játékot kér.',
                annotations: new ToolAnnotations(title: 'Új játék', readOnlyHint: false, destructiveHint: true),
                inputSchema: ['type' => 'object', 'properties' => new \stdClass()],
            )
            ->build();
    }
}
