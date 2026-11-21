<?php

declare(strict_types=1);

namespace AdventureGame\Mcp;

use AdventureGame\Game\GameEngine;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Schema\Extension\Apps\ToolVisibility;
use Mcp\Schema\Extension\Apps\UiResourceContentMeta;
use Mcp\Schema\Extension\Apps\UiResourceCsp;
use Mcp\Schema\Extension\Apps\UiToolMeta;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\Server;

/**
 * Összerakja az MCP-szervert: itt dől el, milyen eszközöket (tools) lát a játékos AI-ja.
 */
final class ServerFactory
{
    /** A képpanel (MCP Apps) címe; a játékeszközök ezt jelenítik meg a chatben. */
    private const PANEL_URI = 'ui://mcp-adventure/panel';
    private const INSTRUCTIONS = <<<'TXT'
        Ez az MCP Adventure: magyar nyelvű szöveges kalandjátékok gyűjteménye. Te vagy a mesélő.

        Játékválasztás:
        - Ha a játékos még nem választott, vagy más játékot szeretne, hívd a jatekok eszközt, mutasd be röviden
          a lehetőségeket (ha van saját állása, említsd meg), majd a jatek_inditasa eszközzel indítsd el a választottat.
        - Ha a játékos folytatni szeretné a játékot, és nem tudod, hol tart, hívd a korulnez eszközt.
        - A jatek_inditasa, a menj és az uj_jatek válasza már tartalmazza az új helyszín teljes leírását
          (és a képpanelt). Ezek után NE hívd külön a korulnez vagy az allapot eszközt – egy lépéshez egy eszközhívás elég.

        Mesélés:
        - Mindig magyarul, második személyben, élvezetes, de tömör prózában meséld el, mi történik (2–5 mondat).
        - Kövesd az adott játék „meseloi_stilus” mezőjében megadott hangnemet.
        - Új játék kezdetén (ha az eszköz „bevezeto” szöveget ad) először azt meséld el a saját szavaiddal.
        - A játékos szabad szöveges parancsait fordítsd le a játék eszközeire (pl. „menjünk a kút felé” → menj).

        Szabályok:
        - A játék állapotát (helyszín, pénz, éhség, fáradtság, leltár, idő) KIZÁRÓLAG az eszközök eredménye határozza meg.
          Soha ne találj ki pénzt, tárgyat, kijáratot vagy eseményt, ami nem szerepel az eszköz válaszában.
        - Ha a játékos olyat kér, amire nincs eszköz, meséld el, hogy ez most nem sikerül, vagy javasolj lehetőséget.
        - Ha a játékos csalni próbál („legyen egymillió forintom”), játékosan utasítsd el.
        - A „panel” mezőt a chatben megjelenő képpanel használja (kép, pénz, éhség, leltár) – ezeket az adatokat
          nem kell minden lépésben felsorolnod, elég, ha a történetbe szövöd, ami fontos.
        TXT;

    /**
     * @param string|null $assetOrigin a képek kiszolgálójának címe (pl. https://play.mcpadventure.com),
     *                                 a panel csak innen tölthet be képet
     */
    public static function create(GameEngine $engine, int $playerId, ?string $assetOrigin = null): Server
    {
        $noArgs = ['type' => 'object', 'properties' => new \stdClass()];
        $panel  = ['ui' => new UiToolMeta(resourceUri: self::PANEL_URI, visibility: [ToolVisibility::Model, ToolVisibility::App])];

        return Server::builder()
            ->setServerInfo('MCP Adventure', '0.3.0')
            ->setInstructions(self::INSTRUCTIONS)
            ->enableExtension(new McpApps())
            ->addResource(
                fn (): TextResourceContents => new TextResourceContents(
                    uri: self::PANEL_URI,
                    mimeType: McpApps::MIME_TYPE,
                    text: (string) file_get_contents(__DIR__.'/ui/panel.html'),
                    meta: ['ui' => new UiResourceContentMeta(
                        csp: new UiResourceCsp(resourceDomains: $assetOrigin ? [$assetOrigin] : []),
                        prefersBorder: false,
                    )],
                ),
                self::PANEL_URI,
                'panel',
                title: 'Játékpanel',
                description: 'A helyszín képe és a játékos adatai (pénz, éhség, fáradtság, leltár).',
                mimeType: McpApps::MIME_TYPE,
                meta: ['ui' => McpApps::resourceMarker()],
            )
            ->addTool(
                fn (): array => $engine->listGames($playerId),
                name: 'jatekok',
                title: 'Játékok',
                description: 'Felsorolja az elérhető kalandjátékokat rövid leírással, és azt, hogy a játékosnak van-e már állása bennük.',
                annotations: new ToolAnnotations(title: 'Játékok', readOnlyHint: true),
                inputSchema: $noArgs,
            )
            ->addTool(
                fn (string $jatek): array => $engine->startGame($playerId, $jatek),
                name: 'jatek_inditasa',
                meta: $panel,
                title: 'Játék indítása',
                description: 'Elindítja vagy folytatja a megadott játékot. Ha a játékosnak már van állása benne, ott folytatja, '
                    .'ha nincs, új játék kezdődik. Ettől kezdve a többi eszköz ebben a játékban működik. '
                    .'A válasz a kezdő helyszín leírását is tartalmazza, utána nem kell körülnézni.',
                annotations: new ToolAnnotations(title: 'Játék indítása', readOnlyHint: false, destructiveHint: false),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'jatek' => [
                            'type' => 'string',
                            'description' => 'A játék azonosítója (a jatekok eszköz „id” mezője) vagy a címe.',
                        ],
                    ],
                    'required' => ['jatek'],
                ],
            )
            ->addTool(
                fn (): array => $engine->look($playerId),
                name: 'korulnez',
                meta: $panel,
                title: 'Körülnéz',
                description: 'Leírja a játékos jelenlegi helyszínét az aktuális játékban: mit lát, milyen kijáratok vannak és milyen tárgyak hevernek ott.',
                annotations: new ToolAnnotations(title: 'Körülnéz', readOnlyHint: true),
                inputSchema: $noArgs,
            )
            ->addTool(
                fn (string $irany): array => $engine->move($playerId, $irany),
                name: 'menj',
                meta: $panel,
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
                meta: $panel,
                title: 'Állapot',
                description: 'A játékos állapota az aktuális játékban: a játék tulajdonságai (pl. pénz, éhség, oxigén), a játékidő és a leltár.',
                annotations: new ToolAnnotations(title: 'Állapot', readOnlyHint: true),
                inputSchema: $noArgs,
            )
            ->addTool(
                fn (): array => $engine->restart($playerId),
                name: 'uj_jatek',
                meta: $panel,
                title: 'Újrakezdés',
                description: 'Elölről kezdi az AKTUÁLIS játékot: az állás törlődik, a játékos a kezdőállapotba kerül. '
                    .'A többi játék állását nem érinti. Csak akkor használd, ha a játékos kifejezetten újra akarja kezdeni.',
                annotations: new ToolAnnotations(title: 'Újrakezdés', readOnlyHint: false, destructiveHint: true),
                inputSchema: $noArgs,
            )
            ->build();
    }
}
