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
        - Minden játékeszköz válasza tartalmazza a helyszín aktuális leírását (és a képpanelt).
          Utána NE hívd külön a korulnez vagy az allapot eszközt – egy lépéshez egy eszközhívás elég.

        Tárgyak:
        - A játékosnak két keze, zsebe és (hátizsák esetén) háta van; a táskák, szatyrok tárolók.
          A felvesz magától a legjobb helyre teszi a tárgyat; a „hova” mezőt csak akkor add meg, ha a játékos kéri.
        - Ha valami nem fér el, mondd el a játékosnak, és javasold, hogy tegyen le vagy rakjon el valamit.
        - A tárgyakat a nevükkel add meg (ahogy a válaszban szerepelnek). Ha a válasz „lehetosegek” listát ad,
          kérdezd meg a játékost, melyikre gondolt.
        - A „reszletek” mezőben olyan apróságok lehetnek, amelyeket csak alapos vizsgálat derít ki – ezeket meséld el.

        Test és idő:
        - Az idő csak a játékos lépéseivel telik. A „napszak” mező (hajnal, nappal, este, éjszaka) adja a hangulatot:
          éjjel sötét van, csend, hideg; ezt sződ bele a leírásba.
        - Ha a játékos fáradt, aludhat (alszik), napközben egyszer rövidet pihenhet (pihen); mosdónál vagy fürdőben
          tisztálkodhat (tisztalkodik); ha valamire várni akar (pl. a bolt nyitására), a var eszköz telteti az időt.
        - Ha egy hely zárva van, a válasz megmondja a nyitvatartást; javasolhatod, hogy a játékos várjon.

        Szereplők és kereskedés:
        - A helyszín „szereplok” listája mutatja, ki van jelen. Ha a játékos megszólít valakit, hívd a beszel eszközt;
          a válasz adatlapja (személyiség, mai kedv, kapcsolat, témák) alapján beszélj a szereplő nevében, élőbeszédben.
        - A szereplő csak a témáiban szereplő tényeket tudja. Ha a játékos valamelyikre rákérdez, hívd a beszel eszközt
          a „tema” mezővel, és a „valasz” tartalmát mondd el a szereplő hangján. Csevegni szabadon lehet, de új tényt,
          ígéretet, ajándékot vagy árat ne találj ki.
        - Vásárlás: vesz, eladás: elad, ajándék vagy kért tárgy átadása: odaad. Az árakat és a készletet a „kereskedes”
          vagy a „kinalat” lista adja; alkudozni nem lehet, a szereplő kedve és a kapcsolat már benne van az árban.

        Megbízások, munka, kölcsön:
        - Ha a beszel válaszában „ajanlatok” van, a szereplő a saját szavaival ajánlja fel. Ha a játékos elvállalja,
          hívd az elfogad eszközt, ha nem, az elutasit eszközt. A cél teljesülését és a határidőt a motor figyeli:
          ha az „esemenyek” között megjelenik a teljesítés vagy a kudarc, meséld el.
        - A helyszín „munkak” listája mutatja, hol lehet dolgozni; a munkát a dolgozik eszköz végzi el (órákig tart).
        - Kölcsönt a kolcsont_ker, visszafizetést a visszafizet eszköz intéz. A késést a motor bünteti.
        - Az „idojaras” mező az aktuális idő: sződ bele a leírásba (eső, köd, napsütés).

        Események és a játék vége:
        - Az „esemenyek” lista a játék szabályaiból jön: ezek megtörtént dolgok, mindig sződ bele őket a mesébe.
        - A „valtozasok” lista a tulajdonságok változása; ha jelentős, említsd meg.
        - Az „akadaly” jelű kijárat látszik, de most nem járható; ha a játékos arra indul, a válasz megmondja, miért.
        - Ha a válaszban „vege” mező van, a játék véget ért: kövesd a „teendo” mezőt. Győzelem után nincs több lépés;
          vereség után felajánlhatod a vissza_ellenorzo_ponthoz eszközt.

        Nyelv:
        - A játék adatai magyarul vannak, de mindig a játékos nyelvén mesélj: ha a játékos angolul (vagy más nyelven) ír,
          azon a nyelven meséld el a történetet, a helyszíneket, a tárgyakat és a szereplők szavait.
        - Az eszközök paramétereiben viszont mindig a válaszokban szereplő magyar nevet és irányt add meg
          (pl. „bread” → „fél kenyér”, „north” → „eszak”).

        Mesélés:
        - Második személyben, élvezetes, de tömör prózában meséld el, mi történik (2–5 mondat).
        - Kövesd az adott játék „meseloi_stilus” mezőjében megadott hangnemet.
        - Új játék kezdetén (ha az eszköz „bevezeto” szöveget ad) először azt meséld el a saját szavaiddal.
        - A játékos szabad szöveges parancsait fordítsd le a játék eszközeire (pl. „menjünk a kút felé” → menj).
        - Ha a játékos egy már bejárt, nem szomszédos helyre akar menni („vissza a padhoz”), egyetlen menj_ide hívást használj,
          ne több menj-et egymás után.

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
        // A panel címében a tartalom ujjlenyomata: ha a panel.html változik, új címet kap,
        // így a Claude (és más kliensek) nem a gyorsítótárazott régi változatot használják.
        $panelHtml = (string) file_get_contents(__DIR__.'/ui/panel.html');
        $panelUri = self::PANEL_URI.'-'.substr(sha1($panelHtml), 0, 10);
        $panel  = ['ui' => new UiToolMeta(resourceUri: $panelUri, visibility: [ToolVisibility::Model, ToolVisibility::App])];

        return Server::builder()
            ->setServerInfo('MCP Adventure', '0.8.0')
            ->setInstructions(self::INSTRUCTIONS)
            ->enableExtension(new McpApps())
            ->addResource(
                fn (): TextResourceContents => new TextResourceContents(
                    uri: $panelUri,
                    mimeType: McpApps::MIME_TYPE,
                    text: $panelHtml,
                    meta: ['ui' => new UiResourceContentMeta(
                        csp: new UiResourceCsp(resourceDomains: $assetOrigin ? [$assetOrigin] : []),
                        prefersBorder: false,
                    )],
                ),
                $panelUri,
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
                description: 'A játékos elmegy a megadott irányba. Csak a helyszín leírásában felsorolt kijáratok használhatók. '
                    .'A mozgás játékidőt visz el, ami a játékos tulajdonságait (pl. éhség, fáradtság) is változtatja.',
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
                fn (string $targy, ?int $darab = null, ?string $hova = null): array => $engine->take($playerId, $targy, $darab, $hova),
                name: 'felvesz',
                meta: $panel,
                title: 'Felvesz',
                description: 'A játékos felvesz egy tárgyat a helyszínről (a földről vagy egy itt lévő tárolóból). '
                    .'A tárgy magától a legjobb helyre kerül (hátizsák, táska, zseb, szabad kéz), ha van hely.',
                annotations: new ToolAnnotations(title: 'Felvesz', readOnlyHint: false, destructiveHint: false),
                inputSchema: self::schema([
                    'targy' => ['string', 'A tárgy neve, ahogy a helyszín leírásában szerepel.'],
                    'darab' => ['integer', 'Hány darabot (alapból mindet).'],
                    'hova'  => ['string', 'Csak ha a játékos kéri: bal kéz, jobb kéz, zseb, hát, vagy egy nála lévő tároló neve.'],
                ], ['targy']),
            )
            ->addTool(
                fn (string $targy, ?int $darab = null): array => $engine->drop($playerId, $targy, $darab),
                name: 'letesz',
                meta: $panel,
                title: 'Letesz',
                description: 'A játékos letesz egy nála lévő tárgyat a helyszínen. Egy tároló a tartalmával együtt kerül le.',
                annotations: new ToolAnnotations(title: 'Letesz', readOnlyHint: false, destructiveHint: false),
                inputSchema: self::schema([
                    'targy' => ['string', 'A nála lévő tárgy neve.'],
                    'darab' => ['integer', 'Hány darabot (alapból mindet).'],
                ], ['targy']),
            )
            ->addTool(
                fn (string $targy, string $tarolo, ?int $darab = null): array => $engine->putIn($playerId, $targy, $tarolo, $darab),
                name: 'belerak',
                meta: $panel,
                title: 'Belerak',
                description: 'A játékos belerak egy tárgyat egy tárolóba (táska, szatyor, doboz…), ami nála vagy a helyszínen van.',
                annotations: new ToolAnnotations(title: 'Belerak', readOnlyHint: false, destructiveHint: false),
                inputSchema: self::schema([
                    'targy'  => ['string', 'A berakandó tárgy neve.'],
                    'tarolo' => ['string', 'A tároló neve.'],
                    'darab'  => ['integer', 'Hány darabot (alapból mindet).'],
                ], ['targy', 'tarolo']),
            )
            ->addTool(
                fn (string $targy, string $tarolo, ?int $darab = null): array => $engine->takeOut($playerId, $targy, $tarolo, $darab),
                name: 'kivesz',
                meta: $panel,
                title: 'Kivesz',
                description: 'A játékos kivesz egy tárgyat egy tárolóból; a tárgy a kezébe vagy a zsebébe kerül.',
                annotations: new ToolAnnotations(title: 'Kivesz', readOnlyHint: false, destructiveHint: false),
                inputSchema: self::schema([
                    'targy'  => ['string', 'A kiveendő tárgy neve.'],
                    'tarolo' => ['string', 'A tároló neve.'],
                    'darab'  => ['integer', 'Hány darabot (alapból mindet).'],
                ], ['targy', 'tarolo']),
            )
            ->addTool(
                fn (string $targy): array => $engine->examine($playerId, $targy),
                name: 'megvizsgal',
                meta: $panel,
                title: 'Megvizsgál',
                description: 'A játékos alaposan megnéz egy tárgyat (nála vagy a helyszínen). Néha rejtett részletekre derül fény, '
                    .'például mi áll egy újságban, vagy mi van egy táskában.',
                annotations: new ToolAnnotations(title: 'Megvizsgál', readOnlyHint: false, destructiveHint: false),
                inputSchema: self::schema(['targy' => ['string', 'A tárgy neve.']], ['targy']),
            )
            ->addTool(
                fn (string $targy): array => $engine->consume($playerId, $targy),
                name: 'fogyaszt',
                meta: $panel,
                title: 'Elfogyaszt',
                description: 'A játékos megeszik vagy megiszik valamit, ami nála vagy a helyszínen van.',
                annotations: new ToolAnnotations(title: 'Elfogyaszt', readOnlyHint: false, destructiveHint: false),
                inputSchema: self::schema(['targy' => ['string', 'Az étel vagy ital neve.']], ['targy']),
            )
            ->addTool(
                fn (string $helyszin): array => $engine->goTo($playerId, $helyszin),
                name: 'menj_ide',
                meta: $panel,
                title: 'Menj ide',
                description: 'A játékos elmegy egy már bejárt helyszínre, akár több helyszínen át (pl. „menjünk vissza a padhoz”). '
                    .'A szerver keresi meg az utat; ha útközben valami történik vagy egy hely zárva van, ott megáll. '
                    .'Szomszédos helyszínre és ismeretlen irányba a menj eszközt használd.',
                annotations: new ToolAnnotations(title: 'Menj ide', readOnlyHint: false, destructiveHint: false),
                inputSchema: self::schema(['helyszin' => ['string', 'A bejárt helyszín neve, ahogy a játékban szerepelt.']], ['helyszin']),
            )
            ->addTool(
                fn (string $szereplo, ?string $tema = null): array => $engine->talk($playerId, $szereplo, $tema),
                name: 'beszel',
                meta: $panel,
                title: 'Beszél',
                description: 'A játékos megszólít egy jelen lévő szereplőt. Téma nélkül a szereplő adatlapját és a témáit adja vissza; '
                    .'témával azt, amit a szereplő arról elmond.',
                annotations: new ToolAnnotations(title: 'Beszél', readOnlyHint: false, destructiveHint: false),
                inputSchema: self::schema([
                    'szereplo' => ['string', 'A szereplő neve, ahogy a helyszín leírásában szerepel.'],
                    'tema'     => ['string', 'A téma címe a szereplő témalistájából, vagy a játékos kérdése néhány szóban.'],
                ], ['szereplo']),
            )
            ->addTool(
                fn (string $targy, string $szereplo): array => $engine->give($playerId, $targy, $szereplo),
                name: 'odaad',
                meta: $panel,
                title: 'Odaad',
                description: 'A játékos odaad egy nála lévő tárgyat egy jelen lévő szereplőnek (pl. kérésre, ajándékba).',
                annotations: new ToolAnnotations(title: 'Odaad', readOnlyHint: false, destructiveHint: false),
                inputSchema: self::schema([
                    'targy'    => ['string', 'A nála lévő tárgy neve.'],
                    'szereplo' => ['string', 'A szereplő neve.'],
                ], ['targy', 'szereplo']),
            )
            ->addTool(
                fn (string $targy, ?int $darab = null, ?string $kitol = null): array => $engine->buy($playerId, $targy, $darab, $kitol),
                name: 'vesz',
                meta: $panel,
                title: 'Vesz',
                description: 'A játékos megvesz valamit egy itteni árustól vagy automatából (pénzért, vagy ha a kínálat úgy szól, cserébe).',
                annotations: new ToolAnnotations(title: 'Vesz', readOnlyHint: false, destructiveHint: false),
                inputSchema: self::schema([
                    'targy' => ['string', 'A tárgy neve a kínálatból.'],
                    'darab' => ['integer', 'Hány darabot (alapból 1).'],
                    'kitol' => ['string', 'Csak ha több árus is van itt: kitől.'],
                ], ['targy']),
            )
            ->addTool(
                fn (string $targy, ?int $darab = null, ?string $kinek = null): array => $engine->sell($playerId, $targy, $darab, $kinek),
                name: 'elad',
                meta: $panel,
                title: 'Elad',
                description: 'A játékos elad egy nála lévő tárgyat egy itteni felvásárlónak (szereplőnek vagy automatának).',
                annotations: new ToolAnnotations(title: 'Elad', readOnlyHint: false, destructiveHint: false),
                inputSchema: self::schema([
                    'targy' => ['string', 'A nála lévő tárgy neve.'],
                    'darab' => ['integer', 'Hány darabot (alapból mindet, amennyit ma átvesznek).'],
                    'kinek' => ['string', 'Csak ha több felvásárló is van itt: kinek.'],
                ], ['targy']),
            )
            ->addTool(
                fn (): array => $engine->questLog($playerId),
                name: 'megbizasok',
                meta: $panel,
                title: 'Megbízások',
                description: 'A játékos futó és lezárt megbízásai, határidőkkel, és a kölcsöntartozásai.',
                annotations: new ToolAnnotations(title: 'Megbízások', readOnlyHint: true),
                inputSchema: $noArgs,
            )
            ->addTool(
                fn (string $megbizas): array => $engine->acceptQuest($playerId, $megbizas, true),
                name: 'elfogad',
                meta: $panel,
                title: 'Megbízás elfogadása',
                description: 'A játékos elvállal egy megbízást, amelyet egy jelen lévő szereplő felajánlott (a beszel válaszának „ajanlatok” listájából).',
                annotations: new ToolAnnotations(title: 'Megbízás elfogadása', readOnlyHint: false, destructiveHint: false),
                inputSchema: self::schema(['megbizas' => ['string', 'A megbízás címe vagy azonosítója.']], ['megbizas']),
            )
            ->addTool(
                fn (string $megbizas): array => $engine->acceptQuest($playerId, $megbizas, false),
                name: 'elutasit',
                meta: $panel,
                title: 'Megbízás elutasítása',
                description: 'A játékos visszautasít egy felajánlott megbízást. Van, amelyik később újra felajánlható, van, amelyik elvész.',
                annotations: new ToolAnnotations(title: 'Megbízás elutasítása', readOnlyHint: false, destructiveHint: false),
                inputSchema: self::schema(['megbizas' => ['string', 'A megbízás címe vagy azonosítója.']], ['megbizas']),
            )
            ->addTool(
                fn (?string $munka = null): array => $engine->work($playerId, $munka),
                name: 'dolgozik',
                meta: $panel,
                title: 'Dolgozik',
                description: 'A játékos elvégez egy munkát ezen a helyszínen (a helyszín „munkak” listájából): órákig tart, pénzt hoz, fáraszt.',
                annotations: new ToolAnnotations(title: 'Dolgozik', readOnlyHint: false, destructiveHint: false),
                inputSchema: self::schema(['munka' => ['string', 'A munka neve; ha itt csak egy van, elhagyható.']], []),
            )
            ->addTool(
                fn (string $szereplo): array => $engine->borrow($playerId, $szereplo),
                name: 'kolcsont_ker',
                meta: $panel,
                title: 'Kölcsönt kér',
                description: 'A játékos kölcsönt kér egy jelen lévő szereplőtől (ha az ad kölcsönt). Határidőre kamattal kell visszafizetni.',
                annotations: new ToolAnnotations(title: 'Kölcsönt kér', readOnlyHint: false, destructiveHint: false),
                inputSchema: self::schema(['szereplo' => ['string', 'A szereplő neve.']], ['szereplo']),
            )
            ->addTool(
                fn (string $szereplo, ?int $osszeg = null): array => $engine->repay($playerId, $szereplo, $osszeg),
                name: 'visszafizet',
                meta: $panel,
                title: 'Visszafizet',
                description: 'A játékos törleszti a tartozását egy jelen lévő szereplőnek (alapból a teljes összeget, amennyire a pénze futja).',
                annotations: new ToolAnnotations(title: 'Visszafizet', readOnlyHint: false, destructiveHint: false),
                inputSchema: self::schema([
                    'szereplo' => ['string', 'A szereplő neve.'],
                    'osszeg'   => ['integer', 'Csak ha a játékos részletet akar fizetni.'],
                ], ['szereplo']),
            )
            ->addTool(
                fn (?int $ora = null): array => $engine->sleep($playerId, $ora),
                name: 'alszik',
                meta: $panel,
                title: 'Alszik',
                description: 'A játékos lefekszik aludni. Óránként nő az energiája (ágyban jobban, máshol kevésbé), '
                    .'és felébred, ha kipihente magát. Közben telik az idő.',
                annotations: new ToolAnnotations(title: 'Alszik', readOnlyHint: false, destructiveHint: false),
                inputSchema: self::schema(['ora' => ['integer', 'Legfeljebb hány órát aludjon (1–12, alapból 8).']], []),
            )
            ->addTool(
                fn (): array => $engine->rest($playerId),
                name: 'pihen',
                meta: $panel,
                title: 'Pihen',
                description: 'A játékos fél órát pihen: kicsit nő az energiája. Naponta korlátozott alkalommal.',
                annotations: new ToolAnnotations(title: 'Pihen', readOnlyHint: false, destructiveHint: false),
                inputSchema: $noArgs,
            )
            ->addTool(
                fn (): array => $engine->wash($playerId),
                name: 'tisztalkodik',
                meta: $panel,
                title: 'Tisztálkodik',
                description: 'A játékos megmosakszik vagy megfürdik (csak mosdónál vagy fürdőben). A higiéniája a maximumra nő.',
                annotations: new ToolAnnotations(title: 'Tisztálkodik', readOnlyHint: false, destructiveHint: false),
                inputSchema: $noArgs,
            )
            ->addTool(
                fn (int $perc): array => $engine->wait($playerId, $perc),
                name: 'var',
                meta: $panel,
                title: 'Vár',
                description: 'A játékos vár: telik az idő (5 perctől 12 óráig). Pl. amíg kinyit egy bolt.',
                annotations: new ToolAnnotations(title: 'Vár', readOnlyHint: false, destructiveHint: false),
                inputSchema: self::schema(['perc' => ['integer', 'Hány percet várjon (5–720).']], ['perc']),
            )
            ->addTool(
                fn (): array => $engine->restoreCheckpoint($playerId),
                name: 'vissza_ellenorzo_ponthoz',
                meta: $panel,
                title: 'Vissza az ellenőrző ponthoz',
                description: 'Vereség után (vagy ha a játékos teljesen elakadt, és kéri) visszaviszi a játékost a legutóbbi ellenőrző ponthoz. '
                    .'Az azóta történtek elvesznek. Csak a játékos kérésére használd.',
                annotations: new ToolAnnotations(title: 'Vissza az ellenőrző ponthoz', readOnlyHint: false, destructiveHint: true),
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

    /** @param array<string, array{0: string, 1: string}> $props név => [típus, leírás] */
    private static function schema(array $props, array $required): array
    {
        $out = [];
        foreach ($props as $name => [$type, $desc]) {
            $out[$name] = ['type' => $type, 'description' => $desc];
        }

        return ['type' => 'object', 'properties' => $out, 'required' => $required];
    }
}
