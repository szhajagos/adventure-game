<?php

declare(strict_types=1);

namespace AdventureGame\Admin;

use AdventureGame\Config;
use AdventureGame\Game\Direction;
use AdventureGame\Game\Rules;
use PDO;
use Throwable;

/**
 * A szerkesztőfelület: játékok, helyszínek, kijáratok, tárgyak és képek kezelése.
 * Egyetlen belépési pont (public/admin/index.php), a lapot a ?p= paraméter választja ki,
 * a módosító műveleteket POST-ban az a= paraméter.
 */
final class AdminApp
{
    private const ID_PATTERN = '/^[a-z0-9_]{1,50}$/';
    private const IMAGE_EXT = ['webp', 'jpg', 'jpeg', 'png'];
    private const FEATURES = ['fedett', 'szabadter', 'mosdo', 'furdo', 'agy', 'bolt', 'konyha', 'ules', 'aram', 'viz'];

    private const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
    private const LOCATION_GOAL = 50;

    /** Az irányok ellentéte a visszairányú kijárathoz */
    private const OPPOSITE = [
        'eszak' => 'del', 'del' => 'eszak', 'kelet' => 'nyugat', 'nyugat' => 'kelet',
        'fel' => 'le', 'le' => 'fel', 'be' => 'ki', 'ki' => 'be',
    ];

    private Auth $auth;
    private ?array $user = null;

    public function __construct(
        private readonly PDO $db,
        private readonly string $imageDir,
        private readonly string $imageUrl,
    ) {
        $this->auth = new Auth($db);
    }

    public function run(): void
    {
        Auth::startSession();
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');

        $page = (string) ($_GET['p'] ?? 'home');

        try {
            // Belépés nélkül elérhető lapok
            if ($page === 'setup') {
                $this->setup();

                return;
            }
            if ($page === 'login') {
                $this->login();

                return;
            }

            $this->user = $this->auth->user();
            if (!$this->user) {
                $this->redirect(View::url(['p' => $this->auth->hasAdmin() ? 'login' : 'setup']));
            }

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                if (!Auth::checkCsrf()) {
                    $this->flash('err', 'A munkamenet lejárt, próbáld újra.');
                    $this->back();
                }
                $this->handlePost((string) ($_POST['a'] ?? ''));

                return;
            }

            match ($page) {
                'game'     => $this->pageGame(),
                'game_new' => $this->pageGameForm(null),
                'loc'      => $this->pageLocation(),
                'loc_new'  => $this->pageLocationNew(),
                'item'     => $this->pageItem(),
                'stat'     => $this->pageStat(),
                'rule'     => $this->pageRule(),
                'npc'      => $this->pageNpc(),
                'topic'    => $this->pageTopic(),
                'export'   => $this->export(),
                default    => $this->pageHome(),
            };
        } catch (Throwable $e) {
            http_response_code(500);
            $msg = Config::get('APP_DEBUG', '0') === '1' ? $e->getMessage() : 'Váratlan hiba történt.';
            echo View::layout('Hiba', '<div class="card">'.View::e($msg).'</div><p><a href="/admin/">Vissza</a></p>', $this->user);
        }
    }

    // =====================================================================
    //  Belépés, első beállítás
    // =====================================================================

    private function login(): void
    {
        if (!$this->auth->hasAdmin()) {
            $this->redirect(View::url(['p' => 'setup']));
        }
        $error = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Auth::checkCsrf()) {
                $error = 'A munkamenet lejárt, próbáld újra.';
            } else {
                $error = $this->auth->login((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
                if ($error === null) {
                    $this->redirect('/admin/');
                }
            }
        }
        $body = '<div class="card center"><form method="post">'.View::csrf()
            .View::field('E-mail-cím', 'email', $_POST['email'] ?? '', 'email', ['required' => true, 'autocomplete' => 'username'])
            .View::field('Jelszó', 'password', '', 'password', ['required' => true, 'autocomplete' => 'current-password'])
            .'<button class="btn">Belépés</button></form></div>';
        echo View::layout('Belépés', $body, null, $error ? ['type' => 'err', 'text' => $error] : null);
    }

    private function setup(): void
    {
        if ($this->auth->hasAdmin()) {
            $this->redirect(View::url(['p' => 'login']));
        }
        $key = (string) Config::get('MCP_ACCESS_KEY', '');
        $error = null;
        if ($key === '') {
            $error = 'Az első admin létrehozásához előbb állíts be egy MCP_ACCESS_KEY kulcsot a config.php-ban.';
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Auth::checkCsrf()) {
                $error = 'A munkamenet lejárt, próbáld újra.';
            } elseif (!hash_equals($key, (string) ($_POST['setup_key'] ?? ''))) {
                usleep(700_000);
                $error = 'Hibás titkos kulcs.';
            } elseif (($_POST['password'] ?? '') !== ($_POST['password2'] ?? '')) {
                $error = 'A két jelszó nem egyezik.';
            } else {
                $error = $this->auth->createFirstAdmin((string) $_POST['email'], (string) ($_POST['name'] ?? ''), (string) $_POST['password']);
                if ($error === null) {
                    $this->auth->login((string) $_POST['email'], (string) $_POST['password']);
                    $this->flash('ok', 'Az admin fiók elkészült. Üdv a szerkesztőben!');
                    $this->redirect('/admin/');
                }
            }
        }
        $body = '<div class="card center"><p class="muted">Ez az oldal csak egyszer, az első admin létrehozásakor érhető el. '
            .'A titkos kulcs a <span class="mono">config.php</span> <span class="mono">MCP_ACCESS_KEY</span> értéke.</p>'
            .'<form method="post">'.View::csrf()
            .View::field('Titkos kulcs', 'setup_key', '', 'password', ['required' => true, 'autocomplete' => 'off'])
            .View::field('Név', 'name', $_POST['name'] ?? '', 'text')
            .View::field('E-mail-cím', 'email', $_POST['email'] ?? '', 'email', ['required' => true, 'autocomplete' => 'username'])
            .View::field('Jelszó (legalább 12 karakter)', 'password', '', 'password', ['required' => true, 'minlength' => 12, 'autocomplete' => 'new-password'])
            .View::field('Jelszó még egyszer', 'password2', '', 'password', ['required' => true, 'minlength' => 12, 'autocomplete' => 'new-password'])
            .'<button class="btn">Admin létrehozása</button></form></div>';
        echo View::layout('Első admin létrehozása', $body, null, $error ? ['type' => 'err', 'text' => $error] : null);
    }

    // =====================================================================
    //  Lapok
    // =====================================================================

    private function pageHome(): void
    {
        $games = $this->db->query(
            'SELECT g.*, (SELECT COUNT(*) FROM locations l WHERE l.game_id = g.id) AS loc_count
             FROM games g ORDER BY g.sort_order, g.title'
        )->fetchAll();

        $rows = '';
        foreach ($games as $g) {
            $pct = min(100, (int) round($g['loc_count'] / self::LOCATION_GOAL * 100));
            $rows .= '<tr><td><a href="'.View::e(View::url(['p' => 'game', 'g' => $g['id']])).'"><b>'.View::e($g['title']).'</b></a>'
                .'<div class="mono muted">'.View::e($g['id']).'</div></td>'
                .'<td style="width:260px">'.$g['loc_count'].' / '.self::LOCATION_GOAL.' helyszín'
                .'<div class="progress"><i style="width:'.$pct.'%"></i></div></td>'
                .'<td>'.($g['active'] ? '<span class="badge ok">aktív</span>' : '<span class="badge">rejtett</span>').'</td></tr>';
        }

        $body = '<div class="actions"><a class="btn" href="'.View::e(View::url(['p' => 'game_new'])).'">+ Új játék</a></div><h2>Játékok</h2>'
            .($rows ? '<table><tr><th>Játék</th><th>Haladás</th><th>Állapot</th></tr>'.$rows.'</table>' : '<p class="muted">Még nincs játék.</p>');

        echo View::layout('Játékok', $body, $this->user, $this->takeFlash());
    }

    private function pageGame(): void
    {
        $game = $this->requireGame((string) ($_GET['g'] ?? ''));
        $gid = $game['id'];

        // Helyszínek
        $stmt = $this->db->prepare(
            'SELECT l.*, (SELECT COUNT(*) FROM exits e WHERE e.game_id = l.game_id AND e.from_location = l.id) AS exit_count,
                    (SELECT COUNT(*) FROM location_items li WHERE li.game_id = l.game_id AND li.location_id = l.id) AS item_count
             FROM locations l WHERE l.game_id = ? ORDER BY l.name'
        );
        $stmt->execute([$gid]);
        $locs = $stmt->fetchAll();

        $lrows = '';
        foreach ($locs as $l) {
            $img = $this->imageUrlFor($gid, $l['id']);
            $thumb = $img ? '<img class="thumb" src="'.View::e($img).'" alt="" loading="lazy">' : '<div class="thumb none">nincs kép</div>';
            $start = $l['id'] === $game['start_location'] ? ' <span class="badge ok">kezdő</span>' : '';
            $lrows .= '<tr><td>'.$thumb.'</td><td><a href="'.View::e(View::url(['p' => 'loc', 'g' => $gid, 'l' => $l['id']])).'"><b>'.View::e($l['name']).'</b></a>'.$start
                .'<div class="mono muted">'.View::e($l['id']).'</div></td><td>'.$l['exit_count'].' kijárat</td><td>'.$l['item_count'].' tárgy</td></tr>';
        }

        // Tárgyak
        $stmt = $this->db->prepare('SELECT * FROM items WHERE game_id = ? ORDER BY name');
        $stmt->execute([$gid]);
        $irows = '';
        foreach ($stmt->fetchAll() as $i) {
            $props = json_decode((string) $i['props'], true) ?: [];
            $badges = (!empty($props['cel']) ? ' <span class="badge ok">céltárgy</span>' : '')
                .(isset($props['tarolo']) ? ' <span class="badge">tároló: '.(int) ($props['tarolo']['kapacitas'] ?? 0).'</span>' : '')
                .(!empty($props['nem_felveheto']) ? ' <span class="badge">nem felvehető</span>' : '');
            $irows .= '<tr><td><a href="'.View::e(View::url(['p' => 'item', 'g' => $gid, 'i' => $i['id']])).'"><b>'.View::e($i['name']).'</b></a>'.$badges
                .'<div class="mono muted">'.View::e($i['id']).'</div></td><td>'.View::e($i['size']).'</td><td>'.View::e(implode(', ', (array) ($props['cimkek'] ?? []))).'</td>'
                .'<td>'.View::e($i['price']).' '.View::e($this->moneyUnit($gid)).'</td>'
                .'<td>'.($i['nutrition'] ? View::e($i['nutrition']) : '–').'</td></tr>';
        }

        $count = count($locs);
        $pct = min(100, (int) round($count / self::LOCATION_GOAL * 100));

        $body = '<div class="card"><b>'.$count.' / '.self::LOCATION_GOAL.'</b> helyszín<div class="progress"><i style="width:'.$pct.'%"></i></div>'
            .'<div class="actions"><a class="btn light" href="'.View::e(View::url(['p' => 'export', 'g' => $gid])).'">SQL-mentés letöltése</a></div></div>'
            .'<h2>Ellenőrzés</h2>'.$this->checks($game, $locs)
            .'<h2>Helyszínek</h2><div class="actions"><a class="btn" href="'.View::e(View::url(['p' => 'loc_new', 'g' => $gid])).'">+ Új helyszín</a></div><br>'
            .($lrows ? '<table><tr><th>Kép</th><th>Helyszín</th><th>Kijáratok</th><th>Tárgyak</th></tr>'.$lrows.'</table>' : '<p class="muted">Még nincs helyszín.</p>')
            .'<h2>Tárgyak</h2><div class="actions"><a class="btn" href="'.View::e(View::url(['p' => 'item', 'g' => $gid])).'">+ Új tárgy</a></div><br>'
            .($irows ? '<table><tr><th>Tárgy</th><th>Méret</th><th>Címkék</th><th>Ár</th><th>Tápérték</th></tr>'.$irows.'</table>' : '<p class="muted">Még nincs tárgy.</p>')
            .'<h2>Tulajdonságok</h2><p class="muted">A játékos jellemzői ebben a játékban (pénz, éhség, oxigén, hírnév…). '
            .'Új tulajdonság a meglévő mentésekben a kezdőértékével jelenik meg.</p>'
            .'<div class="actions"><a class="btn" href="'.View::e(View::url(['p' => 'stat', 'g' => $gid])).'">+ Új tulajdonság</a></div><br>'
            .$this->statsTable($gid)
            .'<h2>Szereplők</h2><p class="muted">Akikkel a játékos beszélhet, kereskedhet. A mesélő az adatlapjuk alapján beszél a nevükben.</p>'
            .'<div class="actions"><a class="btn" href="'.View::e(View::url(['p' => 'npc', 'g' => $gid])).'">+ Új szereplő</a></div><br>'
            .$this->npcsTable($gid)
            .'<h2>Szabályok</h2><p class="muted">Mi történjen, ha a játékos valamit csinál: <i>kiváltó művelet → feltételek → hatások → üzenet a mesélőnek</i>.</p>'
            .'<div class="actions"><a class="btn" href="'.View::e(View::url(['p' => 'rule', 'g' => $gid])).'">+ Új szabály</a></div><br>'
            .$this->rulesTable($gid)
            .'<h2>Játékszabályok és a játék vége</h2>'.$this->settingsForm($game)
            .'<h2>A játék adatai</h2>'.$this->gameForm($game, $locs);

        echo View::layout($game['title'], $body, $this->user, $this->takeFlash(), ['Játékok' => '/admin/', $game['title'] => null]);
    }

    private function pageGameForm(?array $game): void
    {
        echo View::layout('Új játék', $this->gameForm($game, []), $this->user, $this->takeFlash(), ['Játékok' => '/admin/', 'Új játék' => null]);
    }

    private function gameForm(?array $g, array $locs): string
    {
        $isNew = $g === null;
        $g ??= ['id' => '', 'title' => '', 'description' => '', 'intro' => '', 'narrator_style' => '', 'start_location' => '',
            'start_minute' => 480, 'sort_order' => 100, 'active' => 0];

        $locOptions = ['' => '– még nincs –'];
        foreach ($locs as $l) {
            $locOptions[$l['id']] = $l['name'].' ('.$l['id'].')';
        }
        $startTime = sprintf('%02d:%02d', intdiv((int) $g['start_minute'] % 1440, 60), (int) $g['start_minute'] % 60);

        return '<form method="post" class="card">'.View::csrf().'<input type="hidden" name="a" value="save_game">'
            .($isNew
                ? View::field('Azonosító (kisbetű, szám, aláhúzás – később nem módosítható)', 'id', '', 'text', ['required' => true, 'pattern' => '[a-z0-9_]{1,50}'])
                : '<input type="hidden" name="id" value="'.View::e($g['id']).'">')
            .View::field('Cím', 'title', $g['title'], 'text', ['required' => true, 'maxlength' => 150])
            .View::field('Rövid leírás (a játékválasztáshoz és a nyitóoldalhoz)', 'description', $g['description'], 'textarea')
            .View::field('Bevezető (amit a mesélő a játék elején elmond)', 'intro', $g['intro'], 'textarea')
            .View::field('Mesélői stílus (hangnem, hangulat – az AI ezt követi)', 'narrator_style', $g['narrator_style'], 'textarea')
            .'<div class="row">'
            .($isNew ? '' : View::select('Kezdő helyszín', 'start_location', $locOptions, $g['start_location']))
            .View::field('Kezdés időpontja (1. nap)', 'start_time', $startTime, 'text', ['pattern' => '[0-2]?[0-9]:[0-5][0-9]'])
            .View::field('Sorrend a listában', 'sort_order', $g['sort_order'], 'number')
            .'</div>'
            .'<label class="field"><span>Látható a játékosoknak</span><select name="active">'
            .'<option value="1"'.($g['active'] ? ' selected' : '').'>Igen, aktív</option>'
            .'<option value="0"'.(!$g['active'] ? ' selected' : '').'>Nem, rejtett (fejlesztés alatt)</option></select></label>'
            .'<button class="btn">'.($isNew ? 'Játék létrehozása' : 'Mentés').'</button></form>';
    }

    private function pageLocationNew(): void
    {
        $game = $this->requireGame((string) ($_GET['g'] ?? ''));
        $body = '<form method="post" class="card">'.View::csrf().'<input type="hidden" name="a" value="save_loc">'
            .'<input type="hidden" name="g" value="'.View::e($game['id']).'"><input type="hidden" name="new" value="1">'
            .View::field('Azonosító (kisbetű, szám, aláhúzás – ez lesz a kép fájlneve is)', 'l', '', 'text', ['required' => true, 'pattern' => '[a-z0-9_]{1,50}'])
            .View::field('Név', 'name', '', 'text', ['required' => true, 'maxlength' => 100])
            .View::field('Leírás (mit lát a játékos)', 'description', '', 'textarea', ['required' => true])
            .'<button class="btn">Helyszín létrehozása</button></form>';
        echo View::layout('Új helyszín', $body, $this->user, $this->takeFlash(),
            ['Játékok' => '/admin/', $game['title'] => View::url(['p' => 'game', 'g' => $game['id']]), 'Új helyszín' => null]);
    }

    private function pageLocation(): void
    {
        $game = $this->requireGame((string) ($_GET['g'] ?? ''));
        $gid = $game['id'];
        $loc = $this->requireLocation($gid, (string) ($_GET['l'] ?? ''));
        $lid = $loc['id'];

        // Kép
        $img = $this->imageUrlFor($gid, $lid);
        $imgBlock = ($img ? '<img class="big" src="'.View::e($img).'" alt="">' : '<p class="muted">Ehhez a helyszínhez még nincs kép.</p>')
            .'<form method="post" enctype="multipart/form-data" class="actions" style="margin-top:10px">'.View::csrf()
            .'<input type="hidden" name="a" value="upload_img"><input type="hidden" name="g" value="'.View::e($gid).'"><input type="hidden" name="l" value="'.View::e($lid).'">'
            .'<input type="file" name="image" accept=".webp,.jpg,.jpeg,.png,image/webp,image/jpeg,image/png" required>'
            .'<button class="btn">'.($img ? 'Kép cseréje' : 'Kép feltöltése').'</button></form>'
            .'<p class="muted" style="font-size:13px">WebP, JPG vagy PNG, legfeljebb 5 MB. Ajánlott: 16:9 arány, WebP. A fájl neve automatikusan <span class="mono">'.View::e($lid).'</span> lesz.</p>'
            .($img ? View::postButton('Kép törlése', ['a' => 'delete_img', 'g' => $gid, 'l' => $lid], 'Biztosan törlöd a képet?', 'btn danger small') : '')
            ;
        $layerBlock = $img ? $this->layersEditor($loc, $img) : '';

        // Kijáratok
        $locs = $this->locationsOf($gid);
        $locOptions = [];
        foreach ($locs as $l) {
            $locOptions[$l['id']] = $l['name'].' ('.$l['id'].')';
        }
        $stmt = $this->db->prepare('SELECT e.*, l.name AS target_name FROM exits e JOIN locations l ON l.game_id = e.game_id AND l.id = e.to_location WHERE e.game_id = ? AND e.from_location = ? ORDER BY e.direction');
        $stmt->execute([$gid, $lid]);
        $erows = '';
        foreach ($stmt->fetchAll() as $e) {
            $erows .= '<tr><td><b>'.View::e(Direction::label($e['direction'])).'</b> <span class="mono muted">'.View::e($e['direction']).'</span></td>'
                .'<td><a href="'.View::e(View::url(['p' => 'loc', 'g' => $gid, 'l' => $e['to_location']])).'">'.View::e($e['target_name']).'</a></td>'
                .'<td>'.View::e($e['description'])
                .($e['conditions'] ? '<div class="mono muted">feltétel: '.View::e($e['conditions']).'</div>' : '')
                .($e['blocked_message'] ? '<div class="muted">ha nem teljesül: „'.View::e($e['blocked_message']).'”</div>' : '')
                .'</td><td>'.View::e($e['minutes']).' perc</td>'
                .'<td>'.View::postButton('Törlés', ['a' => 'delete_exit', 'g' => $gid, 'l' => $lid, 'dir' => $e['direction']], 'Törlöd ezt a kijáratot?', 'btn danger small').'</td></tr>';
        }
        $dirOptions = '';
        foreach (Direction::LABELS as $k => $label) {
            $dirOptions .= '<option value="'.View::e($k).'">'.View::e($label).'</option>';
        }
        $exitForm = '<form method="post" class="card">'.View::csrf().'<input type="hidden" name="a" value="add_exit">'
            .'<input type="hidden" name="g" value="'.View::e($gid).'"><input type="hidden" name="l" value="'.View::e($lid).'">'
            .'<div class="row"><label class="field"><span>Irány</span><input type="text" name="dir" list="dirs" required placeholder="pl. eszak, be, le…"><datalist id="dirs">'.$dirOptions.'</datalist></label>'
            .View::select('Hová vezet', 'to', $locOptions)
            .View::field('Leírás (pl. „egy rozsdás kapu”)', 'edesc', '', 'text', ['maxlength' => 255])
            .View::field('Időtartam (perc)', 'minutes', 5, 'number', ['min' => 0, 'max' => 1440]).'</div>'
            .'<details><summary>Feltételes kijárat (pl. zárt ajtó)</summary><div class="row">'
            .View::field('Feltételek (JSON, ugyanúgy, mint a szabályoknál)', 'conditions', '', 'text', ['placeholder' => '[{"van_nala": "kulcs"}]', 'class' => 'mono'])
            .View::field('Üzenet, ha nem teljesül', 'blocked', '', 'text', ['maxlength' => 255, 'placeholder' => 'Az ajtó zárva van.'])
            .'</div><p class="muted" style="font-size:13px">Ugyanazzal az iránnyal újra felvéve a kijárat felülíródik – így módosítható a feltétel is.</p></details><br>'
            .'<label><input type="checkbox" name="reverse" value="1" checked> Visszafelé vezető kijárat is (az ellentétes iránnyal, ha van ilyen)</label><br><br>'
            .'<button class="btn">Kijárat hozzáadása</button></form>';

        // Tárgyak a helyszínen
        $stmt = $this->db->prepare('SELECT li.*, i.name FROM location_items li JOIN items i ON i.game_id = li.game_id AND i.id = li.item_id WHERE li.game_id = ? AND li.location_id = ? ORDER BY i.name');
        $stmt->execute([$gid, $lid]);
        $trows = '';
        foreach ($stmt->fetchAll() as $t) {
            $trows .= '<tr><td>'.View::e($t['name']).'</td><td>'.View::e($t['quantity']).' db</td>'
                .'<td>'.View::postButton('Eltávolítás', ['a' => 'delete_loc_item', 'g' => $gid, 'l' => $lid, 'i' => $t['item_id']], '', 'btn danger small').'</td></tr>';
        }
        $stmt = $this->db->prepare('SELECT id, name FROM items WHERE game_id = ? ORDER BY name');
        $stmt->execute([$gid]);
        $itemOptions = [];
        foreach ($stmt->fetchAll() as $i) {
            $itemOptions[$i['id']] = $i['name'];
        }
        $itemForm = $itemOptions
            ? '<form method="post" class="actions" style="margin-top:10px">'.View::csrf().'<input type="hidden" name="a" value="set_loc_item">'
              .'<input type="hidden" name="g" value="'.View::e($gid).'"><input type="hidden" name="l" value="'.View::e($lid).'">'
              .View::select('Tárgy', 'i', $itemOptions).View::field('Darab', 'qty', 1, 'number', ['min' => 1, 'max' => 999])
              .'<button class="btn">Hozzáadás / darabszám beállítása</button></form>'
            : '<p class="muted">Ebben a játékban még nincs tárgy. <a href="'.View::e(View::url(['p' => 'item', 'g' => $gid])).'">Új tárgy</a></p>';

        $isStart = $lid === $game['start_location'];

        $body = '<div class="two"><div>'
            .'<form method="post" class="card">'.View::csrf().'<input type="hidden" name="a" value="save_loc">'
            .'<input type="hidden" name="g" value="'.View::e($gid).'"><input type="hidden" name="l" value="'.View::e($lid).'">'
            .'<p class="mono muted">Azonosító: '.View::e($lid).($isStart ? ' · <span class="badge ok">kezdő helyszín</span>' : '').'</p>'
            .View::field('Név', 'name', $loc['name'], 'text', ['required' => true, 'maxlength' => 100])
            .View::field('Leírás (mit lát a játékos)', 'description', $loc['description'], 'textarea', ['required' => true, 'rows' => 6])
            .View::field('Felszereltség (vesszővel elválasztott címkék, pl. fedett, mosdo, bolt, agy)', 'features',
                implode(', ', json_decode((string) $loc['features'], true) ?: []), 'text', ['list' => 'featlist'])
            .View::field('Nyitvatartás (pl. 06:00-19:00; több sáv vesszővel; üres = mindig nyitva)', 'open_hours', (string) ($loc['open_hours'] ?? ''), 'text',
                ['placeholder' => '06:00-19:00'])
            .'<datalist id="featlist">'.implode('', array_map(fn ($f) => '<option value="'.$f.'">', self::FEATURES)).'</datalist>'
            .'<button class="btn">Mentés</button></form>'
            .'</div><div class="card"><b>Kép</b><br><br>'.$imgBlock.'</div></div>'
            .$layerBlock
            .'<h2>Kijáratok innen</h2>'.($erows ? '<table><tr><th>Irány</th><th>Hová</th><th>Leírás</th><th>Idő</th><th></th></tr>'.$erows.'</table><br>' : '<p class="muted">Még nincs kijárat.</p>')
            .$exitForm
            .'<h2>Tárgyak a helyszínen (a játék elején)</h2>'.($trows ? '<table><tr><th>Tárgy</th><th>Darab</th><th></th></tr>'.$trows.'</table>' : '<p class="muted">Nincs itt tárgy.</p>')
            .$itemForm
            .'<h2>Kereskedés a helyszínen (automata, bolt szereplő nélkül)</h2>'.$this->tradesBlock($gid, 'location', $lid)
            .'<h2>Veszélyzóna</h2>'
            .($isStart ? '<p class="muted">A kezdő helyszín nem törölhető.</p>'
                : View::postButton('Helyszín törlése', ['a' => 'delete_loc', 'g' => $gid, 'l' => $lid], 'Biztosan törlöd a helyszínt a kijárataival és tárgyaival együtt?', 'btn danger'));

        echo View::layout($loc['name'], $body, $this->user, $this->takeFlash(),
            ['Játékok' => '/admin/', $game['title'] => View::url(['p' => 'game', 'g' => $gid]), $loc['name'] => null]);
    }

    private function pageItem(): void
    {
        $game = $this->requireGame((string) ($_GET['g'] ?? ''));
        $gid = $game['id'];
        $iid = (string) ($_GET['i'] ?? '');
        $item = null;
        if ($iid !== '') {
            $stmt = $this->db->prepare('SELECT * FROM items WHERE game_id = ? AND id = ?');
            $stmt->execute([$gid, $iid]);
            $item = $stmt->fetch() ?: $this->notFound();
        }
        $isNew = $item === null;
        $item ??= ['id' => '', 'name' => '', 'description' => '', 'price' => 0, 'nutrition' => 0, 'size' => 1, 'props' => '{}'];
        $pr = json_decode((string) $item['props'], true) ?: [];
        $chk = fn (string $name, string $label, bool $on) => '<label style="display:block;margin:6px 0"><input type="checkbox" name="'.$name.'" value="1"'.($on ? ' checked' : '').'> '.$label.'</label>';

        $body = '<form method="post" class="card">'.View::csrf().'<input type="hidden" name="a" value="save_item"><input type="hidden" name="g" value="'.View::e($gid).'">'
            .($isNew ? '<input type="hidden" name="new" value="1">'.View::field('Azonosító (kisbetű, szám, aláhúzás)', 'i', '', 'text', ['required' => true, 'pattern' => '[a-z0-9_]{1,50}'])
                     : '<input type="hidden" name="i" value="'.View::e($item['id']).'"><p class="mono muted">Azonosító: '.View::e($item['id']).'</p>')
            .View::field('Név (ahogy a játékban megjelenik)', 'name', $item['name'], 'text', ['required' => true, 'maxlength' => 100])
            .View::field('Leírás', 'description', $item['description'], 'textarea')
            .'<div class="row">'.View::field('Ár ('.($this->moneyUnit($gid) ?: 'pénz').')', 'price', $item['price'], 'number', ['min' => 0])
            .View::field('Tápérték (0 = nem étel; a játékbeállításban megadott tulajdonságot változtatja)', 'nutrition', $item['nutrition'], 'number', ['min' => 0, 'max' => 100])
            .View::field('Méret (zseb: '.($this->gameSettings($game)['zseb_kapacitas'] ?? 2).', egy kéz: '.($this->gameSettings($game)['kez_meret'] ?? 4).')', 'size', $item['size'], 'number', ['min' => 0, 'max' => 100]).'</div>'
            .View::field('Címkék (vesszővel; pl. etel, ital, taska, papir, eszkoz, uveg)', 'tags', implode(', ', (array) ($pr['cimkek'] ?? [])), 'text')
            .View::field('Amit alapos vizsgálat derít ki (megvizsgál)', 'examine', (string) ($pr['vizsgalat'] ?? ''), 'textarea', ['rows' => 3])
            .'<h3>Tároló</h3><div class="row">'
            .View::field('Befogadóképesség (0 = nem tároló)', 'cap', (int) ($pr['tarolo']['kapacitas'] ?? 0), 'number', ['min' => 0, 'max' => 1000])
            .View::field('Csak ilyen címkéjű tárgyat fogad (vesszővel; üres = bármit)', 'accepts', implode(', ', (array) ($pr['tarolo']['befogad'] ?? [])), 'text')
            .View::field('Csak ilyen tárolóban vihető (pl. folyadek)', 'needs', (string) ($pr['tarolot_igenyel'] ?? ''), 'text').'</div>'
            .$chk('backpack', 'Hátizsák: a hátán viszi, nem foglal kezet', ($pr['hely'] ?? '') === 'hat')
            .$chk('fixed', 'Nem vehető fel (bútor, nagy tárgy – de megvizsgálható, tárolóként használható)', !empty($pr['nem_felveheto']))
            .'<h3>Céltárgy</h3>'
            .$chk('goal', 'Céltárgy: ha a játékoshoz kerül, a játék győzelemmel véget ér', !empty($pr['cel']))
            .View::field('Befejező szöveg (ezt meséli el az AI a győzelemkor)', 'ending', (string) ($pr['vege_szoveg'] ?? ''), 'textarea', ['rows' => 3])
            .'<button class="btn">'.($isNew ? 'Tárgy létrehozása' : 'Mentés').'</button></form>'
            .($isNew ? '' : '<h2>Kép a helyszínképekre</h2>'.$this->spriteBlock($gid, 'targyak', $item['id']))
            .($isNew ? '' : '<h2>Veszélyzóna</h2>'.View::postButton('Tárgy törlése', ['a' => 'delete_item', 'g' => $gid, 'i' => $item['id']], 'Biztosan törlöd? A helyszínekről is eltűnik.', 'btn danger'));

        echo View::layout($isNew ? 'Új tárgy' : $item['name'], $body, $this->user, $this->takeFlash(),
            ['Játékok' => '/admin/', $game['title'] => View::url(['p' => 'game', 'g' => $gid]), ($isNew ? 'Új tárgy' : $item['name']) => null]);
    }

    // =====================================================================
    //  Műveletek (POST)
    // =====================================================================

    private function handlePost(string $action): void
    {
        $gid = (string) ($_POST['g'] ?? '');
        $lid = (string) ($_POST['l'] ?? '');

        switch ($action) {
            case 'logout':
                $this->auth->logout();
                $this->redirect(View::url(['p' => 'login']));

            case 'save_game':
                $this->saveGame();

            case 'save_loc':
                $game = $this->requireGame($gid);
                $name = trim((string) ($_POST['name'] ?? ''));
                $desc = trim((string) ($_POST['description'] ?? ''));
                if ($name === '' || $desc === '') {
                    $this->fail('A név és a leírás kötelező.');
                }
                if (!empty($_POST['new'])) {
                    $this->validId($lid, 'helyszín');
                    if ($this->location($gid, $lid)) {
                        $this->fail('Ilyen azonosítójú helyszín már van ebben a játékban.');
                    }
                    $this->db->prepare('INSERT INTO locations (game_id, id, name, description) VALUES (?, ?, ?, ?)')->execute([$gid, $lid, $name, $desc]);
                    if ($game['start_location'] === '' || !$this->location($gid, $game['start_location'])) {
                        $this->db->prepare('UPDATE games SET start_location = ? WHERE id = ?')->execute([$lid, $gid]);
                    }
                    $this->ok('A helyszín elkészült. Adj hozzá kijáratokat és képet.', ['p' => 'loc', 'g' => $gid, 'l' => $lid]);
                }
                $this->requireLocation($gid, $lid);
                $features = json_encode(self::csv((string) ($_POST['features'] ?? '')), JSON_UNESCAPED_UNICODE);
                $open = trim((string) ($_POST['open_hours'] ?? ''));
                if ($open !== '' && !preg_match('/^\s*([0-2]?\d:[0-5]\d\s*[-–]\s*[0-2]?\d:[0-5]\d\s*,?\s*)+$/u', $open)) {
                    $this->fail('A nyitvatartás formája: 06:00-19:00 (több sáv vesszővel: 08:00-12:00, 14:00-18:00).');
                }
                $this->db->prepare('UPDATE locations SET name = ?, description = ?, features = ?, open_hours = ? WHERE game_id = ? AND id = ?')
                    ->execute([$name, $desc, $features, $open === '' ? null : $open, $gid, $lid]);
                $this->ok('Mentve.', ['p' => 'loc', 'g' => $gid, 'l' => $lid]);

            case 'delete_loc':
                $game = $this->requireGame($gid);
                $this->requireLocation($gid, $lid);
                if ($lid === $game['start_location']) {
                    $this->fail('A kezdő helyszín nem törölhető.');
                }
                $stmt = $this->db->prepare('SELECT COUNT(*) FROM saves WHERE game_id = ? AND location_id = ?');
                $stmt->execute([$gid, $lid]);
                if ((int) $stmt->fetchColumn() > 0) {
                    $this->fail('Jelenleg játékos tartózkodik ezen a helyszínen, ezért most nem törölhető.');
                }
                $this->db->beginTransaction();
                $this->db->prepare('DELETE FROM exits WHERE game_id = ? AND (from_location = ? OR to_location = ?)')->execute([$gid, $lid, $lid]);
                $this->db->prepare('DELETE FROM location_items WHERE game_id = ? AND location_id = ?')->execute([$gid, $lid]);
                $this->db->prepare('DELETE FROM trades WHERE game_id = ? AND trader_type = ? AND trader_id = ?')->execute([$gid, 'location', $lid]);
                $this->db->prepare('DELETE FROM locations WHERE game_id = ? AND id = ?')->execute([$gid, $lid]);
                $this->db->commit();
                $this->removeImages($gid, $lid);
                $this->ok('A helyszín törölve.', ['p' => 'game', 'g' => $gid]);

            case 'add_exit':
                $this->requireGame($gid);
                $this->requireLocation($gid, $lid);
                $dir = Direction::normalize((string) ($_POST['dir'] ?? ''));
                $to = (string) ($_POST['to'] ?? '');
                $this->requireLocation($gid, $to);
                if (!preg_match('/^[a-z0-9_]{1,20}$/', $dir)) {
                    $this->fail('Az irány csak kisbetűt, számot és aláhúzást tartalmazhat (pl. eszak, be, lepcso_fel).');
                }
                $minutes = max(0, min(1440, (int) ($_POST['minutes'] ?? 5)));
                $desc = trim((string) ($_POST['edesc'] ?? '')) ?: null;
                $conds = trim((string) ($_POST['conditions'] ?? ''));
                if ($errors = Rules::validate($conds, Rules::CONDITIONS, 'feltételek')) {
                    $this->fail(implode(' ', $errors));
                }
                $blocked = trim((string) ($_POST['blocked'] ?? '')) ?: null;
                $this->db->prepare('REPLACE INTO exits (game_id, from_location, direction, to_location, description, minutes, conditions, blocked_message) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$gid, $lid, $dir, $to, $desc, $minutes, $conds === '' || $conds === '[]' ? null : $conds, $blocked]);
                $msg = 'Kijárat hozzáadva.';
                if (!empty($_POST['reverse']) && isset(self::OPPOSITE[$dir])) {
                    $back = self::OPPOSITE[$dir];
                    $stmt = $this->db->prepare('SELECT 1 FROM exits WHERE game_id = ? AND from_location = ? AND direction = ?');
                    $stmt->execute([$gid, $to, $back]);
                    if ($stmt->fetchColumn()) {
                        $msg .= ' A visszairányú („'.Direction::label($back).'”) kijárat már létezett, azt nem írtam felül.';
                    } else {
                        $this->db->prepare('INSERT INTO exits (game_id, from_location, direction, to_location, description, minutes) VALUES (?, ?, ?, ?, ?, ?)')
                            ->execute([$gid, $to, $back, $lid, null, $minutes]);
                        $msg .= ' A visszairányú („'.Direction::label($back).'”) kijárat is elkészült.';
                    }
                }
                $this->ok($msg, ['p' => 'loc', 'g' => $gid, 'l' => $lid]);

            case 'delete_exit':
                $this->db->prepare('DELETE FROM exits WHERE game_id = ? AND from_location = ? AND direction = ?')
                    ->execute([$gid, $lid, (string) ($_POST['dir'] ?? '')]);
                $this->ok('Kijárat törölve.', ['p' => 'loc', 'g' => $gid, 'l' => $lid]);

            case 'set_loc_item':
                $this->requireLocation($gid, $lid);
                $iid = (string) ($_POST['i'] ?? '');
                $qty = max(1, min(999, (int) ($_POST['qty'] ?? 1)));
                $stmt = $this->db->prepare('SELECT 1 FROM items WHERE game_id = ? AND id = ?');
                $stmt->execute([$gid, $iid]);
                if (!$stmt->fetchColumn()) {
                    $this->fail('Nincs ilyen tárgy.');
                }
                $this->db->prepare('REPLACE INTO location_items (game_id, location_id, item_id, quantity) VALUES (?, ?, ?, ?)')->execute([$gid, $lid, $iid, $qty]);
                $this->ok('Tárgy beállítva.', ['p' => 'loc', 'g' => $gid, 'l' => $lid]);

            case 'delete_loc_item':
                $this->db->prepare('DELETE FROM location_items WHERE game_id = ? AND location_id = ? AND item_id = ?')
                    ->execute([$gid, $lid, (string) ($_POST['i'] ?? '')]);
                $this->ok('Tárgy eltávolítva a helyszínről.', ['p' => 'loc', 'g' => $gid, 'l' => $lid]);

            case 'save_item':
                $this->requireGame($gid);
                $iid = (string) ($_POST['i'] ?? '');
                $name = trim((string) ($_POST['name'] ?? ''));
                if ($name === '') {
                    $this->fail('A név kötelező.');
                }
                $stmt = $this->db->prepare('SELECT props FROM items WHERE game_id = ? AND id = ?');
                $stmt->execute([$gid, $iid]);
                $props = json_decode((string) ($stmt->fetchColumn() ?: '{}'), true) ?: [];
                $props = $this->itemPropsFromPost($props);
                $vals = [$name, trim((string) ($_POST['description'] ?? '')), max(0, (int) ($_POST['price'] ?? 0)), max(0, min(100, (int) ($_POST['nutrition'] ?? 0))),
                    max(0, min(100, (int) ($_POST['size'] ?? 1))), json_encode($props ?: new \stdClass(), JSON_UNESCAPED_UNICODE)];
                if (!empty($_POST['new'])) {
                    $this->validId($iid, 'tárgy');
                    $stmt = $this->db->prepare('SELECT 1 FROM items WHERE game_id = ? AND id = ?');
                    $stmt->execute([$gid, $iid]);
                    if ($stmt->fetchColumn()) {
                        $this->fail('Ilyen azonosítójú tárgy már van.');
                    }
                    $this->db->prepare('INSERT INTO items (name, description, price, nutrition, size, props, game_id, id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([...$vals, $gid, $iid]);
                    $this->ok('A tárgy elkészült.', ['p' => 'game', 'g' => $gid]);
                }
                $this->db->prepare('UPDATE items SET name = ?, description = ?, price = ?, nutrition = ?, size = ?, props = ? WHERE game_id = ? AND id = ?')->execute([...$vals, $gid, $iid]);
                $this->ok('Mentve.', ['p' => 'item', 'g' => $gid, 'i' => $iid]);

            case 'delete_item':
                $iid = (string) ($_POST['i'] ?? '');
                $stmt = $this->db->prepare('SELECT (SELECT COUNT(*) FROM save_items WHERE game_id = ? AND item_id = ?) + (SELECT COUNT(*) FROM item_instances WHERE game_id = ? AND item_id = ?)');
                $stmt->execute([$gid, $iid, $gid, $iid]);
                if ((int) $stmt->fetchColumn() > 0) {
                    $this->fail('Ez a tárgy szerepel játékosok mentésében, ezért most nem törölhető.');
                }
                $stmt = $this->db->prepare('SELECT COUNT(*) FROM trades WHERE game_id = ? AND (item_id = ? OR price_item_id = ?)');
                $stmt->execute([$gid, $iid, $iid]);
                if ((int) $stmt->fetchColumn() > 0) {
                    $this->fail('Ezt a tárgyat valaki árulja vagy felvásárolja; előbb töröld a kereskedési sorát.');
                }
                $this->db->prepare('DELETE FROM location_items WHERE game_id = ? AND item_id = ?')->execute([$gid, $iid]);
                $this->db->prepare('DELETE FROM items WHERE game_id = ? AND id = ?')->execute([$gid, $iid]);
                $this->ok('A tárgy törölve.', ['p' => 'game', 'g' => $gid]);

            case 'save_stat':
                $this->saveStat($gid);

            case 'delete_stat':
                $this->requireGame($gid);
                $this->db->prepare('DELETE FROM game_stats WHERE game_id = ? AND id = ?')->execute([$gid, (string) ($_POST['s'] ?? '')]);
                $this->ok('A tulajdonság törölve. (A mentésekben tárolt értéke megmarad, de a játék nem használja.)', ['p' => 'game', 'g' => $gid]);

            case 'save_npc':
                $this->saveNpc($gid);

            case 'delete_npc':
                $this->requireGame($gid);
                $nid = (string) ($_POST['n'] ?? '');
                $this->db->beginTransaction();
                $this->db->prepare('DELETE FROM trades WHERE game_id = ? AND trader_type = ? AND trader_id = ?')->execute([$gid, 'npc', $nid]);
                $this->db->prepare('DELETE FROM npc_topics WHERE game_id = ? AND npc_id = ?')->execute([$gid, $nid]);
                $this->db->prepare('DELETE FROM npcs WHERE game_id = ? AND id = ?')->execute([$gid, $nid]);
                $this->db->commit();
                $this->ok('A szereplő törölve.', ['p' => 'game', 'g' => $gid]);

            case 'save_topic':
                $this->saveTopic($gid);

            case 'delete_topic':
                $this->requireGame($gid);
                $nid = (string) ($_POST['n'] ?? '');
                $this->db->prepare('DELETE FROM npc_topics WHERE game_id = ? AND npc_id = ? AND id = ?')->execute([$gid, $nid, (int) ($_POST['t'] ?? 0)]);
                $this->ok('A téma törölve.', ['p' => 'npc', 'g' => $gid, 'n' => $nid]);

            case 'add_trade':
                $this->addTrade($gid);

            case 'delete_trade':
                $this->requireGame($gid);
                $this->db->prepare('DELETE FROM trades WHERE game_id = ? AND id = ?')->execute([$gid, (int) ($_POST['id'] ?? 0)]);
                [$bt, $bid] = explode(':', (string) ($_POST['back'] ?? 'location:'), 2) + ['', ''];
                $this->ok('Kereskedési sor törölve.', $bt === 'npc' ? ['p' => 'npc', 'g' => $gid, 'n' => $bid] : ['p' => 'loc', 'g' => $gid, 'l' => $bid]);

            case 'save_settings':
                $this->saveSettings($gid);

            case 'save_rule':
                $this->saveRule($gid);

            case 'delete_rule':
                $this->requireGame($gid);
                $this->db->prepare('DELETE FROM rules WHERE game_id = ? AND id = ?')->execute([$gid, (int) ($_POST['r'] ?? 0)]);
                $this->ok('A szabály törölve.', ['p' => 'game', 'g' => $gid]);

            case 'upload_sprite':
                $this->uploadSprite($gid);

            case 'delete_sprite':
                $sub = (string) ($_POST['sub'] ?? '');
                $sid = (string) ($_POST['id'] ?? '');
                if (isset(self::SPRITE_DIRS[$sub]) && preg_match(self::ID_PATTERN, $sid)) {
                    $this->requireGame($gid);
                    $this->removeSprite($gid, $sub, $sid);
                }
                $this->ok('A kép törölve.', $sub === 'targyak' ? ['p' => 'item', 'g' => $gid, 'i' => $sid] : ['p' => 'npc', 'g' => $gid, 'n' => $sid]);

            case 'save_layers':
                $this->saveLayers($gid, $lid);

            case 'upload_img':
                $this->requireLocation($gid, $lid);
                $this->uploadImage($gid, $lid);

            case 'delete_img':
                $this->requireLocation($gid, $lid);
                $this->removeImages($gid, $lid);
                $this->ok('A kép törölve.', ['p' => 'loc', 'g' => $gid, 'l' => $lid]);

            default:
                $this->fail('Ismeretlen művelet.');
        }
    }

    private function saveGame(): never
    {
        $id = (string) ($_POST['id'] ?? '');
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '') {
            $this->fail('A cím kötelező.');
        }
        [$h, $m] = array_map('intval', explode(':', (string) ($_POST['start_time'] ?? '08:00')) + [0, 0]);
        $fields = [
            'title'          => mb_substr($title, 0, 150),
            'description'    => trim((string) ($_POST['description'] ?? '')),
            'intro'          => trim((string) ($_POST['intro'] ?? '')),
            'narrator_style' => trim((string) ($_POST['narrator_style'] ?? '')),
            'start_minute'   => max(0, min(1439, $h * 60 + $m)),
            'sort_order'     => (int) ($_POST['sort_order'] ?? 100),
            'active'         => !empty($_POST['active']) ? 1 : 0,
        ];

        $stmt = $this->db->prepare('SELECT * FROM games WHERE id = ?');
        $stmt->execute([$id]);
        $existing = $stmt->fetch();

        if (!$existing) {
            $this->validId($id, 'játék');
            $fields['start_location'] = '';
            $fields['id'] = $id;
            $cols = implode(', ', array_keys($fields));
            $marks = implode(', ', array_fill(0, count($fields), '?'));
            $this->db->prepare("INSERT INTO games ($cols) VALUES ($marks)")->execute(array_values($fields));
            // Alap tulajdonságok – a játék oldalán bármikor módosíthatók, bővíthetők
            $ins = $this->db->prepare('INSERT INTO game_stats (game_id, id, label, kind, min_value, max_value, start_value, per_hour, unit, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $ins->execute([$id, 'penz', 'Pénz', 'money', 0, null, 0, 0, 'Ft', 10]);
            $ins->execute([$id, 'jokedv', 'Jókedv', 'bar_high_good', 0, 100, 60, 0, null, 20]);
            $ins->execute([$id, 'jollakottsag', 'Jóllakottság', 'bar_high_good', 0, 100, 70, -5, null, 30]);
            $ins->execute([$id, 'energia', 'Energia', 'bar_high_good', 0, 100, 80, -4, null, 40]);
            $ins->execute([$id, 'higienia', 'Higiénia', 'bar_high_good', 0, 100, 80, -2, null, 50]);
            $settings = [
                'kez_meret' => 4, 'zseb_kapacitas' => 2,
                'etkezes'   => ['tulajdonsag' => 'jollakottsag', 'irany' => 1],
                'test'      => ['energia' => 'energia', 'higienia' => 'higienia', 'alvas_orankent' => 12, 'alvas_agy_nelkul' => 0.5, 'pihenes' => 15, 'pihenes_naponta' => 1],
                'vereseg'   => [['feltetelek' => [['tulajdonsag' => ['jokedv', '<=', 0]], ['tulajdonsag' => ['jollakottsag', '<=', 0]], ['tulajdonsag' => ['energia', '<=', 0]]],
                                 'szoveg' => 'Elfogyott minden erőd és reményed. A kaland itt véget ér.']],
            ];
            $this->db->prepare('UPDATE games SET settings = ? WHERE id = ?')->execute([json_encode($settings, JSON_UNESCAPED_UNICODE), $id]);
            $rule = $this->db->prepare('INSERT INTO rules (game_id, name, trigger_action, conditions, effects, message, priority) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $rule->execute([$id, 'Az éhség rontja a kedvet', 'orankent', '[{"tulajdonsag":["jollakottsag","<=",0]}]', '[{"tulajdonsag":["jokedv",-5]}]', 'A játékos nagyon éhes, ez rontja a kedvét.', 10]);
            $rule->execute([$id, 'A kimerültség rontja a kedvet', 'orankent', '[{"tulajdonsag":["energia","<=",0]}]', '[{"tulajdonsag":["jokedv",-5]}]', 'A játékos teljesen kimerült.', 10]);
            $rule->execute([$id, 'A piszok rontja a kedvet', 'orankent', '[{"tulajdonsag":["higienia","<=",0]}]', '[{"tulajdonsag":["jokedv",-3]}]', 'A játékos koszosnak érzi magát.', 10]);
            $rule->execute([$id, 'Jóllakottan, kipihenten jobb a kedv', 'orankent',
                '[{"tulajdonsag":["jollakottsag",">=",50]},{"tulajdonsag":["energia",">=",40]},{"tulajdonsag":["higienia",">=",30]}]', '[{"tulajdonsag":["jokedv",2]}]', null, 20]);
            $this->ok('A játék elkészült (egyelőre rejtett), a szokásos testi tulajdonságokkal és szabályokkal. Most hozd létre az első helyszínt – ez lesz a kezdő helyszín.', ['p' => 'loc_new', 'g' => $id]);
        }

        $start = (string) ($_POST['start_location'] ?? $existing['start_location']);
        if ($start !== '' && !$this->location($id, $start)) {
            $this->fail('A kezdő helyszín nem létezik.');
        }
        if ($fields['active'] && $start === '') {
            $this->fail('Aktív játéknak kell kezdő helyszín.');
        }
        $fields['start_location'] = $start;
        $set = implode(', ', array_map(fn ($k) => "$k = ?", array_keys($fields)));
        $this->db->prepare("UPDATE games SET $set WHERE id = ?")->execute([...array_values($fields), $id]);
        $this->ok('A játék adatai mentve.', ['p' => 'game', 'g' => $id]);
    }

    private function uploadImage(string $gid, string $lid): never
    {
        $f = $_FILES['image'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->fail('A feltöltés nem sikerült (lehet, hogy túl nagy a fájl).');
        }
        if ($f['size'] > self::MAX_IMAGE_BYTES) {
            $this->fail('A kép legfeljebb 5 MB lehet.');
        }
        $info = @getimagesize($f['tmp_name']);
        $ext = match ($info[2] ?? null) {
            IMAGETYPE_WEBP => 'webp',
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG  => 'png',
            default        => null,
        };
        if ($ext === null) {
            $this->fail('Csak WebP, JPG vagy PNG kép tölthető fel.');
        }

        $dir = $this->imageDir.'/'.$gid;
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            $this->fail('A képmappa nem hozható létre (jogosultság?).');
        }
        $this->removeImages($gid, $lid);
        $target = $dir.'/'.$lid.'.'.$ext;
        if (!move_uploaded_file($f['tmp_name'], $target)) {
            $this->fail('A kép mentése nem sikerült (jogosultság?).');
        }
        @chmod($target, 0644);
        $this->ok('A kép feltöltve.', ['p' => 'loc', 'g' => $gid, 'l' => $lid]);
    }

    // =====================================================================
    //  Ellenőrzés és export
    // =====================================================================

    private function checks(array $game, array $locs): string
    {
        $gid = $game['id'];
        $items = [];

        if (!$locs) {
            return '<div class="card"><p class="muted">Hozd létre az első helyszínt.</p></div>';
        }
        if ($game['start_location'] === '' || !$this->location($gid, $game['start_location'])) {
            $items[] = ['bad', 'Nincs érvényes kezdő helyszín beállítva.'];
        }

        // Elérhetőség a kezdő helyszínről (szélességi bejárás)
        $stmt = $this->db->prepare('SELECT from_location, to_location FROM exits WHERE game_id = ?');
        $stmt->execute([$gid]);
        $edges = [];
        $back = [];
        foreach ($stmt->fetchAll() as $e) {
            $edges[$e['from_location']][] = $e['to_location'];
            $back[$e['from_location'].'>'.$e['to_location']] = true;
        }
        $seen = [$game['start_location'] => true];
        $queue = [$game['start_location']];
        while ($queue) {
            foreach ($edges[array_shift($queue)] ?? [] as $n) {
                if (!isset($seen[$n])) {
                    $seen[$n] = true;
                    $queue[] = $n;
                }
            }
        }

        $names = array_column($locs, 'name', 'id');
        $link = fn (string $id) => '<a href="'.View::e(View::url(['p' => 'loc', 'g' => $gid, 'l' => $id])).'">'.View::e($names[$id] ?? $id).'</a>';

        $unreachable = array_filter(array_keys($names), fn ($id) => !isset($seen[$id]));
        if ($unreachable) {
            $items[] = ['bad', 'A kezdő helyszínről nem érhető el: '.implode(', ', array_map($link, $unreachable))];
        }
        $deadEnds = array_filter(array_keys($names), fn ($id) => empty($edges[$id]));
        if ($deadEnds) {
            $items[] = ['warn', 'Nincs kijárata (zsákutca): '.implode(', ', array_map($link, $deadEnds))];
        }
        $oneWay = [];
        foreach ($edges as $from => $tos) {
            foreach ($tos as $to) {
                if (!isset($back[$to.'>'.$from])) {
                    $oneWay[] = $link($from).' → '.$link($to);
                }
            }
        }
        if ($oneWay) {
            $items[] = ['warn', 'Egyirányú átjárás (lehet szándékos): '.implode('; ', $oneWay)];
        }
        $noImage = array_filter(array_keys($names), fn ($id) => $this->imageUrlFor($gid, $id) === null);
        if ($noImage) {
            $items[] = ['warn', 'Nincs képe: '.implode(', ', array_map($link, $noImage))];
        }
        foreach (['description' => 'rövid leírása', 'intro' => 'bevezetője', 'narrator_style' => 'mesélői stílusa'] as $k => $label) {
            if (trim((string) $game[$k]) === '') {
                $items[] = ['warn', 'Hiányzik a játék '.$label.'.'];
            }
        }
        $stmt = $this->db->prepare('SELECT id, props FROM items WHERE game_id = ?');
        $stmt->execute([$gid]);
        $itemIds = [];
        $hasGoal = false;
        foreach ($stmt->fetchAll() as $it) {
            $itemIds[$it['id']] = true;
            $hasGoal = $hasGoal || !empty((json_decode((string) $it['props'], true) ?: [])['cel']);
        }
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM rules WHERE game_id = ? AND active = 1 AND effects LIKE ?');
        $stmt->execute([$gid, '%jatek_vege%']);
        if (!$hasGoal && !(int) $stmt->fetchColumn()) {
            $items[] = ['warn', 'Nincs céltárgy és győzelmet hozó szabály sem: a játéknak még nincs vége.'];
        }
        foreach ($this->brokenReferences($gid, array_keys($itemIds), array_keys($names)) as $msg) {
            $items[] = ['bad', $msg];
        }
        if (count($locs) < self::LOCATION_GOAL) {
            $items[] = ['info', 'Még '.(self::LOCATION_GOAL - count($locs)).' helyszín hiányzik az 50-es célhoz.'];
        }

        $ok = !array_filter($items, fn ($i) => in_array($i[0], ['bad', 'warn'], true));
        $html = '';
        foreach ($items as [$type, $text]) {
            $badge = ['bad' => '<span class="badge bad">hiba</span>', 'warn' => '<span class="badge warn">figyelem</span>', 'info' => '<span class="badge">info</span>'][$type];
            $html .= '<li>'.$badge.' '.$text.'</li>';
        }

        return '<div class="card">'.($ok ? '<p><span class="badge ok">rendben</span> Minden helyszín elérhető, mindegyiknek van képe.</p>' : '')
            .($html ? '<ul class="checks">'.$html.'</ul>' : '').'</div>';
    }

    /** A játék teljes tartalma SQL-fájlként (mentéshez, git-hez, másik szerverre költöztetéshez). */
    private function export(): void
    {
        $game = $this->requireGame((string) ($_GET['g'] ?? ''));
        $gid = $game['id'];
        $q = fn ($v) => $v === null ? 'NULL' : (is_int($v) ? (string) $v : $this->db->quote((string) $v));

        $out = "-- MCP Adventure – a(z) „{$game['title']}” játék exportja\n-- Készült: ".date('Y-m-d H:i:s')."\n"
            ."-- Betöltés: phpMyAdmin → Importálás. Ha a játék már létezik, előbb töröld (lásd lent).\nSET NAMES utf8mb4;\n\n"
            ."-- DELETE FROM location_items WHERE game_id = ".$q($gid)."; DELETE FROM exits WHERE game_id = ".$q($gid).";\n"
            ."-- DELETE FROM trades WHERE game_id = ".$q($gid)."; DELETE FROM npc_topics WHERE game_id = ".$q($gid)."; DELETE FROM npcs WHERE game_id = ".$q($gid).";\n"
            ."-- DELETE FROM rules WHERE game_id = ".$q($gid)."; DELETE FROM game_stats WHERE game_id = ".$q($gid)."; DELETE FROM items WHERE game_id = ".$q($gid)."; DELETE FROM locations WHERE game_id = ".$q($gid)."; DELETE FROM games WHERE id = ".$q($gid).";\n\n";

        $tables = [
            'games'          => ['SELECT * FROM games WHERE id = ?', []],
            'locations'      => ['SELECT * FROM locations WHERE game_id = ? ORDER BY id', []],
            'exits'          => ['SELECT * FROM exits WHERE game_id = ? ORDER BY from_location, direction', []],
            'game_stats'     => ['SELECT * FROM game_stats WHERE game_id = ? ORDER BY sort_order, id', []],
            'items'          => ['SELECT * FROM items WHERE game_id = ? ORDER BY id', []],
            'location_items' => ['SELECT * FROM location_items WHERE game_id = ? ORDER BY location_id, item_id', []],
            'npcs'           => ['SELECT * FROM npcs WHERE game_id = ? ORDER BY sort_order, id', []],
            'npc_topics'     => ['SELECT game_id, npc_id, title, keywords, content, conditions, effects, sort_order FROM npc_topics WHERE game_id = ? ORDER BY npc_id, sort_order, id', []],
            'trades'         => ['SELECT game_id, trader_type, trader_id, mode, item_id, price, price_item_id, daily_limit, conditions, sort_order FROM trades WHERE game_id = ? ORDER BY id', []],
            'rules'          => ['SELECT game_id, name, trigger_action, conditions, effects, message, once, priority, active FROM rules WHERE game_id = ? ORDER BY priority, id', []],
        ];
        foreach ($tables as $table => [$sql]) {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$gid]);
            $rows = $stmt->fetchAll();
            if (!$rows) {
                continue;
            }
            $cols = array_keys($rows[0]);
            $out .= "INSERT INTO $table (".implode(', ', $cols).") VALUES\n";
            $out .= implode(",\n", array_map(fn ($r) => '('.implode(', ', array_map($q, array_values($r))).')', $rows)).";\n\n";
        }

        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="jatek_'.$gid.'_'.date('Ymd_His').'.sql"');
        echo $out;
    }

    // =====================================================================
    //  Tulajdonságok
    // =====================================================================

    private const STAT_KINDS = [
        'money'         => 'Pénz (a tárgyak ára ebben értendő)',
        'bar_low_good'  => 'Sáv – a kevesebb a jobb (pl. éhség)',
        'bar_high_good' => 'Sáv – a több a jobb (pl. egészség, oxigén)',
        'number'        => 'Szám (pl. hírnév, pontszám)',
        'hidden'        => 'Rejtett érték (a panel nem mutatja)',
    ];

    private function statsTable(string $gid): string
    {
        $stmt = $this->db->prepare('SELECT * FROM game_stats WHERE game_id = ? ORDER BY sort_order, id');
        $stmt->execute([$gid]);
        $rows = '';
        foreach ($stmt->fetchAll() as $t) {
            $range = ($t['min_value'] ?? '–').' … '.($t['max_value'] ?? '–');
            $rate = (float) $t['per_hour'] == 0.0 ? '–' : (($t['per_hour'] > 0 ? '+' : '').rtrim(rtrim($t['per_hour'], '0'), '.').' / óra');
            $rows .= '<tr><td><a href="'.View::e(View::url(['p' => 'stat', 'g' => $gid, 's' => $t['id']])).'"><b>'.View::e($t['label']).'</b></a>'
                .'<div class="mono muted">'.View::e($t['id']).'</div></td><td>'.View::e(self::STAT_KINDS[$t['kind']] ?? $t['kind']).'</td>'
                .'<td>'.View::e($range).'</td><td>'.View::e($t['start_value']).($t['unit'] ? ' '.View::e($t['unit']) : '').'</td><td>'.View::e($rate).'</td></tr>';
        }

        return $rows ? '<table><tr><th>Tulajdonság</th><th>Típus</th><th>Tartomány</th><th>Kezdőérték</th><th>Időbeli változás</th></tr>'.$rows.'</table>'
                     : '<p class="muted">Még nincs tulajdonság.</p>';
    }

    private function pageStat(): void
    {
        $game = $this->requireGame((string) ($_GET['g'] ?? ''));
        $gid = $game['id'];
        $sid = (string) ($_GET['s'] ?? '');
        $stat = null;
        if ($sid !== '') {
            $stmt = $this->db->prepare('SELECT * FROM game_stats WHERE game_id = ? AND id = ?');
            $stmt->execute([$gid, $sid]);
            $stat = $stmt->fetch() ?: $this->notFound();
        }
        $isNew = $stat === null;
        $stat ??= ['id' => '', 'label' => '', 'kind' => 'bar_high_good', 'min_value' => 0, 'max_value' => 100,
            'start_value' => 100, 'per_hour' => 0, 'unit' => '', 'sort_order' => 50];

        $body = '<form method="post" class="card">'.View::csrf().'<input type="hidden" name="a" value="save_stat"><input type="hidden" name="g" value="'.View::e($gid).'">'
            .($isNew ? '<input type="hidden" name="new" value="1">'.View::field('Azonosító (kisbetű, szám, aláhúzás – pl. oxigen, hirnev)', 's', '', 'text', ['required' => true, 'pattern' => '[a-z0-9_]{1,50}'])
                     : '<input type="hidden" name="s" value="'.View::e($stat['id']).'"><p class="mono muted">Azonosító: '.View::e($stat['id']).'</p>')
            .'<div class="row">'.View::field('Megnevezés', 'label', $stat['label'], 'text', ['required' => true, 'maxlength' => 100])
            .View::select('Típus', 'kind', self::STAT_KINDS, $stat['kind']).'</div>'
            .'<div class="row">'
            .View::field('Legkisebb érték (üres = nincs)', 'min_value', $stat['min_value'], 'number')
            .View::field('Legnagyobb érték (üres = nincs)', 'max_value', $stat['max_value'], 'number')
            .View::field('Kezdőérték', 'start_value', $stat['start_value'], 'number', ['required' => true])
            .'</div><div class="row">'
            .View::field('Változás óránként (pl. 30 vagy -12; 0 = nem változik)', 'per_hour', rtrim(rtrim((string) $stat['per_hour'], '0'), '.') ?: '0', 'text', ['pattern' => '-?[0-9]+([.,][0-9]+)?'])
            .View::field('Mértékegység (pl. Ft, kredit – elhagyható)', 'unit', $stat['unit'], 'text', ['maxlength' => 20])
            .View::field('Sorrend a panelen', 'sort_order', $stat['sort_order'], 'number')
            .'</div><button class="btn">'.($isNew ? 'Tulajdonság létrehozása' : 'Mentés').'</button></form>'
            .($isNew ? '' : '<h2>Veszélyzóna</h2>'.View::postButton('Tulajdonság törlése', ['a' => 'delete_stat', 'g' => $gid, 's' => $stat['id']], 'Biztosan törlöd ezt a tulajdonságot?', 'btn danger'));

        echo View::layout($isNew ? 'Új tulajdonság' : $stat['label'], $body, $this->user, $this->takeFlash(),
            ['Játékok' => '/admin/', $game['title'] => View::url(['p' => 'game', 'g' => $gid]), ($isNew ? 'Új tulajdonság' : $stat['label']) => null]);
    }

    private function saveStat(string $gid): never
    {
        $this->requireGame($gid);
        $sid = (string) ($_POST['s'] ?? '');
        $label = trim((string) ($_POST['label'] ?? ''));
        $kind = (string) ($_POST['kind'] ?? 'number');
        if ($label === '' || !isset(self::STAT_KINDS[$kind])) {
            $this->fail('A megnevezés és a típus kötelező.');
        }
        $num = fn (string $k) => ($_POST[$k] ?? '') === '' ? null : (int) $_POST[$k];
        $min = $num('min_value');
        $max = $num('max_value');
        if ($min !== null && $max !== null && $min > $max) {
            $this->fail('A legkisebb érték nem lehet nagyobb a legnagyobbnál.');
        }
        $vals = [$label, $kind, $min, $max, (int) ($_POST['start_value'] ?? 0),
            (float) str_replace(',', '.', (string) ($_POST['per_hour'] ?? '0')),
            trim((string) ($_POST['unit'] ?? '')) ?: null, (int) ($_POST['sort_order'] ?? 50)];

        if (!empty($_POST['new'])) {
            $this->validId($sid, 'tulajdonság');
            $stmt = $this->db->prepare('SELECT 1 FROM game_stats WHERE game_id = ? AND id = ?');
            $stmt->execute([$gid, $sid]);
            if ($stmt->fetchColumn()) {
                $this->fail('Ilyen azonosítójú tulajdonság már van.');
            }
            $this->db->prepare('INSERT INTO game_stats (label, kind, min_value, max_value, start_value, per_hour, unit, sort_order, game_id, id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([...$vals, $gid, $sid]);
            $this->ok('A tulajdonság elkészült.', ['p' => 'game', 'g' => $gid]);
        }
        $this->db->prepare('UPDATE game_stats SET label = ?, kind = ?, min_value = ?, max_value = ?, start_value = ?, per_hour = ?, unit = ?, sort_order = ? WHERE game_id = ? AND id = ?')
            ->execute([...$vals, $gid, $sid]);
        $this->ok('Mentve.', ['p' => 'game', 'g' => $gid]);
    }

    private function moneyUnit(string $gid): string
    {
        $stmt = $this->db->prepare("SELECT unit FROM game_stats WHERE game_id = ? AND kind = 'money' ORDER BY sort_order LIMIT 1");
        $stmt->execute([$gid]);

        return (string) ($stmt->fetchColumn() ?: '');
    }

    // =====================================================================
    //  Segédek
    // =====================================================================

    private function requireGame(string $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM games WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: $this->notFound();
    }

    private function location(string $gid, string $lid): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM locations WHERE game_id = ? AND id = ?');
        $stmt->execute([$gid, $lid]);

        return $stmt->fetch() ?: null;
    }

    private function requireLocation(string $gid, string $lid): array
    {
        return $this->location($gid, $lid) ?? $this->notFound();
    }

    private function locationsOf(string $gid): array
    {
        $stmt = $this->db->prepare('SELECT id, name FROM locations WHERE game_id = ? ORDER BY name');
        $stmt->execute([$gid]);

        return $stmt->fetchAll();
    }

    // =====================================================================
    //  Képrétegek: tárgy- és szereplőképek, és a helyük a helyszínképen
    // =====================================================================

    private const SPRITE_DIRS = ['targyak' => 'tárgy', 'szereplok' => 'szereplő'];

    private function spriteUrl(string $gid, string $sub, string $id): ?string
    {
        foreach (['webp', 'png'] as $ext) {
            $file = $this->imageDir.'/'.$gid.'/'.$sub.'/'.$id.'.'.$ext;
            if (is_file($file)) {
                return $this->imageUrl.'/'.rawurlencode($gid).'/'.$sub.'/'.rawurlencode($id).'.'.$ext.'?v='.filemtime($file);
            }
        }

        return null;
    }

    /** Kép feltöltése egy tárgyhoz vagy szereplőhöz (átlátszó hátterű WebP vagy PNG) */
    private function spriteBlock(string $gid, string $sub, string $id): string
    {
        $url = $this->spriteUrl($gid, $sub, $id);

        return '<div class="card">'
            .($url ? '<div style="background:repeating-conic-gradient(#3a3550 0 25%,#2a2640 0 50%) 0 0/16px 16px;display:inline-block;padding:8px">'
                .'<img src="'.View::e($url).'" alt="" style="max-height:120px;image-rendering:pixelated"></div>'
                : '<p class="muted">Még nincs kép.</p>')
            .'<form method="post" enctype="multipart/form-data" class="actions" style="margin-top:10px">'.View::csrf()
            .'<input type="hidden" name="a" value="upload_sprite"><input type="hidden" name="g" value="'.View::e($gid).'">'
            .'<input type="hidden" name="sub" value="'.View::e($sub).'"><input type="hidden" name="id" value="'.View::e($id).'">'
            .'<input type="file" name="image" accept=".webp,.png,image/webp,image/png" required>'
            .'<button class="btn">'.($url ? 'Csere' : 'Feltöltés').'</button></form>'
            .'<p class="muted" style="font-size:13px">Átlátszó hátterű WebP vagy PNG, legfeljebb 5 MB. Hogy melyik helyszínképen hova kerüljön, a helyszín lapján állíthatod be. '
            .'Ha nincs képe vagy helye, a játék egyszerűen nélküle mutatja a helyszínt.</p>'
            .($url ? View::postButton('Kép törlése', ['a' => 'delete_sprite', 'g' => $gid, 'sub' => $sub, 'id' => $id], 'Törlöd a képet?', 'btn danger small') : '')
            .'</div>';
    }

    private function uploadSprite(string $gid): never
    {
        $this->requireGame($gid);
        $sub = (string) ($_POST['sub'] ?? '');
        $id = (string) ($_POST['id'] ?? '');
        if (!isset(self::SPRITE_DIRS[$sub]) || !preg_match(self::ID_PATTERN, $id)) {
            $this->fail('Hibás adatok.');
        }
        $back = $sub === 'targyak' ? ['p' => 'item', 'g' => $gid, 'i' => $id] : ['p' => 'npc', 'g' => $gid, 'n' => $id];
        $f = $_FILES['image'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->fail('A feltöltés nem sikerült (lehet, hogy túl nagy a fájl).');
        }
        if ($f['size'] > self::MAX_IMAGE_BYTES) {
            $this->fail('A kép legfeljebb 5 MB lehet.');
        }
        $ext = match (@getimagesize($f['tmp_name'])[2] ?? null) {
            IMAGETYPE_WEBP => 'webp',
            IMAGETYPE_PNG  => 'png',
            default        => null,
        };
        if ($ext === null) {
            $this->fail('Csak WebP vagy PNG tölthető fel (a JPG nem tud átlátszó hátteret).');
        }
        $dir = $this->imageDir.'/'.$gid.'/'.$sub;
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            $this->fail('A képmappa nem hozható létre (jogosultság?).');
        }
        $this->removeSprite($gid, $sub, $id);
        $target = $dir.'/'.$id.'.'.$ext;
        if (!move_uploaded_file($f['tmp_name'], $target)) {
            $this->fail('A kép mentése nem sikerült (jogosultság?).');
        }
        @chmod($target, 0644);
        $this->ok('A kép feltöltve.', $back);
    }

    private function removeSprite(string $gid, string $sub, string $id): void
    {
        foreach (['webp', 'png'] as $ext) {
            $file = $this->imageDir.'/'.$gid.'/'.$sub.'/'.$id.'.'.$ext;
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    /** A helyszín rétegszerkesztője: a képre kattintva kijelölhető, hova kerüljön egy tárgy vagy szereplő. */
    private function layersEditor(array $loc, string $bgUrl): string
    {
        $gid = $loc['game_id'];
        $pos = json_decode((string) ($loc['layers'] ?? '{}'), true) ?: [];
        $rows = [];
        $stmt = $this->db->prepare('SELECT id, name FROM items WHERE game_id = ? ORDER BY name');
        $stmt->execute([$gid]);
        foreach ($stmt->fetchAll() as $i) {
            if ($url = $this->spriteUrl($gid, 'targyak', $i['id'])) {
                $rows[] = [$i['id'], $i['name'], $url];
            }
        }
        $stmt = $this->db->prepare('SELECT id, name FROM npcs WHERE game_id = ? ORDER BY name');
        $stmt->execute([$gid]);
        foreach ($stmt->fetchAll() as $n) {
            if ($url = $this->spriteUrl($gid, 'szereplok', $n['id'])) {
                $rows[] = ['@'.$n['id'], '👤 '.$n['name'], $url];
            }
        }
        if (!$rows) {
            return '<p class="muted" style="font-size:13px">Képrétegek: ha egy tárgynak vagy szereplőnek feltöltesz egy átlátszó hátterű képet (a tárgy vagy a szereplő lapján), '
                .'itt beállíthatod, hova kerüljön ezen a képen. Akkor jelenik meg, amikor a tárgy itt hever, vagy a szereplő itt van.</p>';
        }

        $trs = '';
        foreach ($rows as $n => [$key, $name, $url]) {
            $p = $pos[$key] ?? [];
            $num = fn (string $f, string $k, $def) => '<input type="number" step="any" min="0" max="100" name="'.$f.'['.View::e($key).']" value="'.View::e((string) ($p[$k] ?? $def)).'" style="width:70px" data-k="'.$k.'">';
            $trs .= '<tr data-key="'.View::e($key).'" data-src="'.View::e($url).'"><td><input type="radio" name="sel" value="'.$n.'"'.($n === 0 ? ' checked' : '').'></td>'
                .'<td><img src="'.View::e($url).'" alt="" style="height:28px;image-rendering:pixelated;vertical-align:middle"> '.View::e($name).'</td>'
                .'<td>'.$num('x', 'x', '').'</td><td>'.$num('y', 'y', '').'</td><td>'.$num('w', 'w', '').'</td>'
                .'<td><button type="button" class="btn small" data-clear>Nem kerül rá</button></td></tr>';
        }

        return '<h2>Képrétegek</h2><p class="muted" style="font-size:13px">Válaszd ki a sort, majd kattints a képre oda, ahol a tárgy vagy a szereplő <b>talppontja</b> legyen. '
            .'A szélesség a kép szélességének százaléka. Üresen hagyva nem kerül a képre. A játékban csak akkor jelenik meg, ha a tárgy itt hever, vagy a szereplő itt van.</p>'
            .'<form method="post" class="card" id="layerform">'.View::csrf().'<input type="hidden" name="a" value="save_layers"><input type="hidden" name="g" value="'.View::e($gid).'"><input type="hidden" name="l" value="'.View::e($loc['id']).'">'
            .'<div id="lprev" style="position:relative;max-width:720px;cursor:crosshair;user-select:none"><img src="'.View::e($bgUrl).'" alt="" style="width:100%;display:block"></div><br>'
            .'<table><tr><th></th><th>Kép</th><th>x %</th><th>y %</th><th>szélesség %</th><th></th></tr>'.$trs.'</table><br>'
            .'<button class="btn">Rétegek mentése</button></form>'
            .<<<'JS'
<script>
(() => {
  const box = document.getElementById('lprev'), rows = [...document.querySelectorAll('#layerform tr[data-key]')];
  const val = (tr, k) => tr.querySelector('[data-k="' + k + '"]');
  function draw() {
    box.querySelectorAll('.lay').forEach(e => e.remove());
    for (const tr of rows) {
      const x = parseFloat(val(tr, 'x').value), y = parseFloat(val(tr, 'y').value), w = parseFloat(val(tr, 'w').value);
      if (isNaN(x) || isNaN(y) || isNaN(w)) continue;
      const im = document.createElement('img');
      im.className = 'lay'; im.src = tr.dataset.src;
      im.style.cssText = `position:absolute;left:${x}%;top:${y}%;width:${w}%;transform:translate(-50%,-100%);pointer-events:none;image-rendering:pixelated;z-index:${Math.round(y)}`;
      box.append(im);
    }
  }
  box.addEventListener('click', e => {
    const r = box.getBoundingClientRect(), sel = document.querySelector('#layerform input[name=sel]:checked');
    if (!sel) return;
    const tr = rows[+sel.value];
    val(tr, 'x').value = (100 * (e.clientX - r.left) / r.width).toFixed(1);
    val(tr, 'y').value = (100 * (e.clientY - r.top) / r.height).toFixed(1);
    if (!val(tr, 'w').value) val(tr, 'w').value = 8;
    draw();
  });
  rows.forEach(tr => {
    tr.querySelectorAll('input[type=number]').forEach(i => i.addEventListener('input', draw));
    tr.querySelector('[data-clear]').addEventListener('click', () => { ['x', 'y', 'w'].forEach(k => val(tr, k).value = ''); draw(); });
  });
  draw();
})();
</script>
JS;
    }

    private function saveLayers(string $gid, string $lid): never
    {
        $this->requireLocation($gid, $lid);
        $out = [];
        foreach ((array) ($_POST['x'] ?? []) as $key => $x) {
            $key = (string) $key;
            $y = $_POST['y'][$key] ?? '';
            $w = $_POST['w'][$key] ?? '';
            if (!preg_match('/^@?[a-z0-9_]{1,50}$/', $key) || $x === '' || $y === '' || $w === '') {
                continue;
            }
            $clamp = fn ($v, $min) => round(max($min, min(100, (float) $v)), 1);
            $out[$key] = ['x' => $clamp($x, 0), 'y' => $clamp($y, 0), 'w' => $clamp($w, 0.5)];
        }
        $this->db->prepare('UPDATE locations SET layers = ? WHERE game_id = ? AND id = ?')
            ->execute([json_encode($out ?: new \stdClass()), $gid, $lid]);
        $this->ok('A rétegek mentve.', ['p' => 'loc', 'g' => $gid, 'l' => $lid]);
    }

    // =====================================================================
    //  Szereplők, témák, kereskedés
    // =====================================================================

    private function npcsTable(string $gid): string
    {
        $stmt = $this->db->prepare('SELECT n.*, (SELECT COUNT(*) FROM npc_topics t WHERE t.game_id = n.game_id AND t.npc_id = n.id) AS topic_count,
            (SELECT COUNT(*) FROM trades r WHERE r.game_id = n.game_id AND r.trader_type = \'npc\' AND r.trader_id = n.id) AS trade_count
            FROM npcs n WHERE n.game_id = ? ORDER BY n.sort_order, n.name');
        $stmt->execute([$gid]);
        $rows = '';
        foreach ($stmt->fetchAll() as $n) {
            $rows .= '<tr><td><a href="'.View::e(View::url(['p' => 'npc', 'g' => $gid, 'n' => $n['id']])).'"><b>'.View::e($n['name']).'</b></a>'
                .'<div class="mono muted">'.View::e($n['id']).'</div></td><td>'.View::e(self::scheduleText($n['schedule'])).'</td>'
                .'<td>'.$n['topic_count'].' téma</td><td>'.$n['trade_count'].' kereskedési sor</td></tr>';
        }

        return $rows ? '<table><tr><th>Szereplő</th><th>Hol és mikor</th><th>Témák</th><th>Kereskedés</th></tr>'.$rows.'</table>'
                     : '<p class="muted">Még nincs szereplő.</p>';
    }

    /** A beosztás szövegként: soronként „helyszín 06:00-18:00” (idő nélkül: mindig ott van) */
    private static function scheduleText(string $json): string
    {
        $lines = [];
        foreach (json_decode($json, true) ?: [] as $s) {
            $lines[] = trim(($s['helyszin'] ?? '').' '.(isset($s['tol'], $s['ig']) ? $s['tol'].'-'.$s['ig'] : ''));
        }

        return implode("\n", $lines);
    }

    private function pageNpc(): void
    {
        $game = $this->requireGame((string) ($_GET['g'] ?? ''));
        $gid = $game['id'];
        $nid = (string) ($_GET['n'] ?? '');
        $npc = null;
        if ($nid !== '') {
            $stmt = $this->db->prepare('SELECT * FROM npcs WHERE game_id = ? AND id = ?');
            $stmt->execute([$gid, $nid]);
            $npc = $stmt->fetch() ?: $this->notFound();
        }
        $isNew = $npc === null;
        $npc ??= ['id' => '', 'name' => '', 'description' => '', 'personality' => '', 'schedule' => '[]', 'sort_order' => 100];
        $locIds = implode(', ', array_column($this->locationsOf($gid), 'id'));

        $body = '<form method="post" class="card">'.View::csrf().'<input type="hidden" name="a" value="save_npc"><input type="hidden" name="g" value="'.View::e($gid).'">'
            .($isNew ? '<input type="hidden" name="new" value="1">'.View::field('Azonosító (kisbetű, szám, aláhúzás)', 'n', '', 'text', ['required' => true, 'pattern' => '[a-z0-9_]{1,50}'])
                     : '<input type="hidden" name="n" value="'.View::e($npc['id']).'"><p class="mono muted">Azonosító: '.View::e($npc['id']).'</p>')
            .View::field('Név (ahogy a játékban megjelenik)', 'name', $npc['name'], 'text', ['required' => true, 'maxlength' => 100])
            .View::field('Külső (amit a játékos lát)', 'description', $npc['description'], 'textarea', ['rows' => 2])
            .View::field('Személyiség és beszédstílus (a mesélőnek)', 'personality', $npc['personality'], 'textarea', ['rows' => 3])
            .View::field('Hol és mikor van (soronként: helyszín_id 06:00-18:00; idő nélkül: mindig ott van)', 'schedule', self::scheduleText($npc['schedule']), 'textarea',
                ['rows' => 3, 'class' => 'mono', 'placeholder' => 'pekseg 06:00-19:00'])
            .'<p class="muted" style="font-size:13px">Helyszínek: <span class="mono">'.View::e($locIds).'</span></p>'
            .View::field('Sorrend', 'sort_order', $npc['sort_order'], 'number')
            .'<button class="btn">'.($isNew ? 'Szereplő létrehozása' : 'Mentés').'</button></form>';

        if (!$isNew) {
            $stmt = $this->db->prepare('SELECT * FROM npc_topics WHERE game_id = ? AND npc_id = ? ORDER BY sort_order, id');
            $stmt->execute([$gid, $nid]);
            $trows = '';
            foreach ($stmt->fetchAll() as $t) {
                $trows .= '<tr><td><a href="'.View::e(View::url(['p' => 'topic', 'g' => $gid, 'n' => $nid, 't' => $t['id']])).'"><b>'.View::e($t['title']).'</b></a>'
                    .'<div class="muted">'.View::e((string) $t['keywords']).'</div></td><td>'.View::e(mb_strimwidth($t['content'], 0, 140, '…')).'</td>'
                    .'<td class="mono">'.View::e($t['conditions'] === '[]' ? '' : $t['conditions']).'</td><td class="mono">'.View::e($t['effects'] === '[]' ? '' : $t['effects']).'</td></tr>';
            }
            $body .= '<h2>Beszélgetési témák</h2><p class="muted">Amit a szereplő tud és elmond. Az első elmondáskor a hatások érvényesülnek (pl. a játékos megtud valamit: jelző).</p>'
                .'<div class="actions"><a class="btn" href="'.View::e(View::url(['p' => 'topic', 'g' => $gid, 'n' => $nid])).'">+ Új téma</a></div><br>'
                .($trows ? '<table><tr><th>Téma</th><th>Amit elmond</th><th>Feltételek</th><th>Hatások</th></tr>'.$trows.'</table>' : '<p class="muted">Még nincs téma.</p>')
                .'<h2>Kereskedés</h2>'.$this->tradesBlock($gid, 'npc', $nid)
                .'<h2>Kép a helyszínképekre</h2>'.$this->spriteBlock($gid, 'szereplok', $nid)
                .'<h2>Veszélyzóna</h2>'.View::postButton('Szereplő törlése', ['a' => 'delete_npc', 'g' => $gid, 'n' => $nid], 'Biztosan törlöd a szereplőt a témáival és a kereskedésével együtt?', 'btn danger');
        }

        $title = $isNew ? 'Új szereplő' : $npc['name'];
        echo View::layout($title, $body, $this->user, $this->takeFlash(),
            ['Játékok' => '/admin/', $game['title'] => View::url(['p' => 'game', 'g' => $gid]), $title => null]);
    }

    private function saveNpc(string $gid): never
    {
        $this->requireGame($gid);
        $nid = (string) ($_POST['n'] ?? '');
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            $this->fail('A név kötelező.');
        }
        $locs = array_column($this->locationsOf($gid), 'id');
        $schedule = [];
        foreach (preg_split('/\R/', trim((string) ($_POST['schedule'] ?? ''))) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (!preg_match('/^([a-z0-9_]+)(?:\s+([0-2]?\d:[0-5]\d)\s*[-–]\s*([0-2]?\d:[0-5]\d))?$/u', $line, $m)) {
                $this->fail('A beosztás sorainak formája: helyszín_id 06:00-18:00 (hibás sor: „'.$line.'”).');
            }
            if (!in_array($m[1], $locs, true)) {
                $this->fail('Nincs ilyen helyszín: „'.$m[1].'”.');
            }
            $schedule[] = isset($m[2]) && $m[2] !== '' ? ['helyszin' => $m[1], 'tol' => $m[2], 'ig' => $m[3]] : ['helyszin' => $m[1]];
        }
        $vals = [$name, trim((string) ($_POST['description'] ?? '')), trim((string) ($_POST['personality'] ?? '')),
            json_encode($schedule, JSON_UNESCAPED_UNICODE), (int) ($_POST['sort_order'] ?? 100)];
        if (!empty($_POST['new'])) {
            $this->validId($nid, 'szereplő');
            $stmt = $this->db->prepare('SELECT 1 FROM npcs WHERE game_id = ? AND id = ?');
            $stmt->execute([$gid, $nid]);
            if ($stmt->fetchColumn()) {
                $this->fail('Ilyen azonosítójú szereplő már van.');
            }
            $this->db->prepare('INSERT INTO npcs (name, description, personality, schedule, sort_order, game_id, id) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([...$vals, $gid, $nid]);
            $this->ok('A szereplő elkészült. Adj hozzá témákat és kereskedést.', ['p' => 'npc', 'g' => $gid, 'n' => $nid]);
        }
        $this->db->prepare('UPDATE npcs SET name = ?, description = ?, personality = ?, schedule = ?, sort_order = ? WHERE game_id = ? AND id = ?')->execute([...$vals, $gid, $nid]);
        $this->ok('Mentve.', ['p' => 'npc', 'g' => $gid, 'n' => $nid]);
    }

    private function pageTopic(): void
    {
        $game = $this->requireGame((string) ($_GET['g'] ?? ''));
        $gid = $game['id'];
        $nid = (string) ($_GET['n'] ?? '');
        $stmt = $this->db->prepare('SELECT * FROM npcs WHERE game_id = ? AND id = ?');
        $stmt->execute([$gid, $nid]);
        $npc = $stmt->fetch() ?: $this->notFound();
        $tid = (int) ($_GET['t'] ?? 0);
        $topic = null;
        if ($tid > 0) {
            $stmt = $this->db->prepare('SELECT * FROM npc_topics WHERE game_id = ? AND npc_id = ? AND id = ?');
            $stmt->execute([$gid, $nid, $tid]);
            $topic = $stmt->fetch() ?: $this->notFound();
        }
        $isNew = $topic === null;
        $topic ??= ['id' => 0, 'title' => '', 'keywords' => '', 'content' => '', 'conditions' => '[]', 'effects' => '[]', 'sort_order' => 100];
        $pretty = fn (string $j) => ($d = json_decode($j, true)) ? json_encode($d, JSON_UNESCAPED_UNICODE) : '';

        $body = '<form method="post" class="card">'.View::csrf().'<input type="hidden" name="a" value="save_topic"><input type="hidden" name="g" value="'.View::e($gid).'">'
            .'<input type="hidden" name="n" value="'.View::e($nid).'"><input type="hidden" name="t" value="'.(int) $topic['id'].'">'
            .View::field('Téma címe', 'title', $topic['title'], 'text', ['required' => true, 'maxlength' => 150])
            .View::field('Kulcsszavak (vesszővel; a játékos szavai, amelyekre előjön)', 'keywords', (string) $topic['keywords'], 'text')
            .View::field('Amit a szereplő elmond (a mesélő a saját szavaival, a szereplő hangján adja elő)', 'content', $topic['content'], 'textarea', ['rows' => 4, 'required' => true])
            .View::field('Feltételek (JSON; mikor hajlandó beszélni róla – pl. [{"kapcsolat": ["pek", ">=", 3]}])', 'conditions', $pretty((string) $topic['conditions']), 'text', ['class' => 'mono'])
            .View::field('Hatások az első elmondáskor (JSON; pl. [{"jelzo": ["tudja_a_titkot", true]}])', 'effects', $pretty((string) $topic['effects']), 'text', ['class' => 'mono'])
            .View::field('Sorrend', 'sort_order', $topic['sort_order'], 'number')
            .'<button class="btn">'.($isNew ? 'Téma létrehozása' : 'Mentés').'</button></form>'
            .($isNew ? '' : '<h2>Veszélyzóna</h2>'.View::postButton('Téma törlése', ['a' => 'delete_topic', 'g' => $gid, 'n' => $nid, 't' => $topic['id']], 'Törlöd a témát?', 'btn danger'));

        $title = $isNew ? 'Új téma' : $topic['title'];
        echo View::layout($title, $body, $this->user, $this->takeFlash(),
            ['Játékok' => '/admin/', $game['title'] => View::url(['p' => 'game', 'g' => $gid]), $npc['name'] => View::url(['p' => 'npc', 'g' => $gid, 'n' => $nid]), $title => null]);
    }

    private function saveTopic(string $gid): never
    {
        $this->requireGame($gid);
        $nid = (string) ($_POST['n'] ?? '');
        $tid = (int) ($_POST['t'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $content = trim((string) ($_POST['content'] ?? ''));
        if ($title === '' || $content === '') {
            $this->fail('A cím és a tartalom kötelező.');
        }
        $conds = trim((string) ($_POST['conditions'] ?? '')) ?: '[]';
        $effects = trim((string) ($_POST['effects'] ?? '')) ?: '[]';
        if ($errors = array_merge(Rules::validate($conds, Rules::CONDITIONS, 'feltételek'), Rules::validate($effects, Rules::EFFECTS, 'hatások'))) {
            $this->fail(implode(' ', $errors));
        }
        $norm = fn (string $j) => json_encode(json_decode($j, true), JSON_UNESCAPED_UNICODE);
        $vals = [$title, trim((string) ($_POST['keywords'] ?? '')) ?: null, $content, $norm($conds), $norm($effects), (int) ($_POST['sort_order'] ?? 100)];
        if ($tid > 0) {
            $this->db->prepare('UPDATE npc_topics SET title = ?, keywords = ?, content = ?, conditions = ?, effects = ?, sort_order = ? WHERE game_id = ? AND npc_id = ? AND id = ?')
                ->execute([...$vals, $gid, $nid, $tid]);
        } else {
            $this->db->prepare('INSERT INTO npc_topics (title, keywords, content, conditions, effects, sort_order, game_id, npc_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([...$vals, $gid, $nid]);
        }
        $this->ok('A téma mentve.', ['p' => 'npc', 'g' => $gid, 'n' => $nid]);
    }

    /** Egy kereskedő (szereplő vagy helyszín) kereskedési sorai és az új sor űrlapja */
    private function tradesBlock(string $gid, string $type, string $traderId): string
    {
        $stmt = $this->db->prepare('SELECT t.*, i.name AS item_name, i.price AS base_price, p.name AS price_item_name FROM trades t
            JOIN items i ON i.game_id = t.game_id AND i.id = t.item_id LEFT JOIN items p ON p.game_id = t.game_id AND p.id = t.price_item_id
            WHERE t.game_id = ? AND t.trader_type = ? AND t.trader_id = ? ORDER BY t.mode, t.sort_order, t.id');
        $stmt->execute([$gid, $type, $traderId]);
        $unit = $this->moneyUnit($gid);
        $rows = '';
        foreach ($stmt->fetchAll() as $t) {
            $price = $t['price_item_id'] !== null ? 'csere: '.$t['price_item_name']
                : ($t['price'] !== null ? $t['price'].' '.$unit : $t['base_price'].' '.$unit.' (alapár)');
            $rows .= '<tr><td>'.($t['mode'] === 'arul' ? 'árul' : 'felvásárol').'</td><td><b>'.View::e($t['item_name']).'</b></td><td>'.View::e($price).'</td>'
                .'<td>'.($t['daily_limit'] === null ? 'korlátlan' : View::e($t['daily_limit']).' db / nap').'</td>'
                .'<td class="mono">'.View::e($t['conditions'] === '[]' ? '' : $t['conditions']).'</td>'
                .'<td>'.View::postButton('Törlés', ['a' => 'delete_trade', 'g' => $gid, 'id' => $t['id'], 'back' => $type.':'.$traderId], '', 'btn danger small').'</td></tr>';
        }
        $stmt = $this->db->prepare('SELECT id, name FROM items WHERE game_id = ? ORDER BY name');
        $stmt->execute([$gid]);
        $items = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        if (!$items) {
            return '<p class="muted">Ebben a játékban még nincs tárgy.</p>';
        }

        return ($rows ? '<table><tr><th>Mód</th><th>Tárgy</th><th>Ár</th><th>Keret</th><th>Feltétel</th><th></th></tr>'.$rows.'</table><br>' : '<p class="muted">Nincs kereskedés.</p>')
            .'<form method="post" class="card">'.View::csrf().'<input type="hidden" name="a" value="add_trade"><input type="hidden" name="g" value="'.View::e($gid).'">'
            .'<input type="hidden" name="type" value="'.View::e($type).'"><input type="hidden" name="trader" value="'.View::e($traderId).'">'
            .'<div class="row">'.View::select('Mód', 'mode', ['arul' => 'árul (a játékos megveheti)', 'felvasarol' => 'felvásárol (a játékos eladhatja)'])
            .View::select('Tárgy', 'item', $items)
            .View::field('Ár ('.($unit ?: 'pénz').'; üres = a tárgy alapára)', 'price', '', 'number', ['min' => 0])
            .View::field('Napi keret (db; üres = korlátlan)', 'limit', '', 'number', ['min' => 1]).'</div>'
            .'<div class="row">'.View::select('Csere: pénz helyett ezt kéri', 'price_item', ['' => '– nem csere, pénzért –'] + $items)
            .View::field('Feltétel (JSON; pl. [{"tulajdonsag": ["higienia", ">=", 10]}])', 'conds', '', 'text', ['class' => 'mono']).'</div>'
            .'<p class="muted" style="font-size:13px">A szereplők árai a napi kedvük és a kapcsolat szerint ±10–20%-kal mozognak; a helyszín automatái mindig a megadott áron dolgoznak. '
            .'A napi keret reggel (a játékszabályokban megadott órában) újraindul.</p>'
            .'<button class="btn">Hozzáadás</button></form>';
    }

    private function addTrade(string $gid): never
    {
        $this->requireGame($gid);
        $type = (string) ($_POST['type'] ?? '');
        $trader = (string) ($_POST['trader'] ?? '');
        $mode = (string) ($_POST['mode'] ?? '');
        $back = $type === 'npc' ? ['p' => 'npc', 'g' => $gid, 'n' => $trader] : ['p' => 'loc', 'g' => $gid, 'l' => $trader];
        if (!in_array($type, ['npc', 'location'], true) || !in_array($mode, ['arul', 'felvasarol'], true)) {
            $this->fail('Hibás adatok.');
        }
        $conds = trim((string) ($_POST['conds'] ?? '')) ?: '[]';
        if ($errors = Rules::validate($conds, Rules::CONDITIONS, 'feltételek')) {
            $this->fail(implode(' ', $errors));
        }
        $priceItem = (string) ($_POST['price_item'] ?? '');
        $price = trim((string) ($_POST['price'] ?? ''));
        $limit = trim((string) ($_POST['limit'] ?? ''));
        if ($priceItem !== '' && $mode !== 'arul') {
            $this->fail('Cserét csak árulásnál lehet megadni (a játékos ad egy tárgyat a másikért).');
        }
        $this->db->prepare('INSERT INTO trades (game_id, trader_type, trader_id, mode, item_id, price, price_item_id, daily_limit, conditions) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$gid, $type, $trader, $mode, (string) ($_POST['item'] ?? ''), $price === '' ? null : max(0, (int) $price),
                $priceItem === '' ? null : $priceItem, $limit === '' ? null : max(1, (int) $limit), json_encode(json_decode($conds, true), JSON_UNESCAPED_UNICODE)]);
        $this->ok('Kereskedési sor hozzáadva.', $back);
    }

    // =====================================================================
    //  Játékszabályok (beállítások) és szabályok
    // =====================================================================

    private function gameSettings(array $game): array
    {
        $s = json_decode((string) ($game['settings'] ?? '{}'), true);

        return is_array($s) ? $s : [];
    }

    private function settingsForm(array $game): string
    {
        $gid = $game['id'];
        $s = $this->gameSettings($game);
        $stmt = $this->db->prepare('SELECT id, label FROM game_stats WHERE game_id = ? ORDER BY sort_order, id');
        $stmt->execute([$gid]);
        $statOptions = ['' => '– nincs (az étel nem hat semmire) –'];
        foreach ($stmt->fetchAll() as $t) {
            $statOptions[$t['id']] = $t['label'].' ('.$t['id'].')';
        }
        $eat = $s['etkezes'] ?? ['tulajdonsag' => '', 'irany' => 1];
        $body = $s['test'] ?? ['energia' => '', 'higienia' => ''];
        $statOptionsBody = array_merge(['' => '– nincs –'], array_slice($statOptions, 1, null, true));
        $defeat = json_encode($s['vereseg'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return '<form method="post" class="card">'.View::csrf().'<input type="hidden" name="a" value="save_settings"><input type="hidden" name="g" value="'.View::e($gid).'">'
            .'<div class="row">'
            .View::field('Egy kézben vihető méret', 'hand', (int) ($s['kez_meret'] ?? 4), 'number', ['min' => 1, 'max' => 100])
            .View::field('A zseb befogadóképessége', 'pocket', (int) ($s['zseb_kapacitas'] ?? 2), 'number', ['min' => 0, 'max' => 100])
            .'</div><div class="row">'
            .View::select('Az étel ezt a tulajdonságot változtatja', 'eat_stat', $statOptions, $eat['tulajdonsag'] ?? '')
            .View::select('Irány', 'eat_dir', ['-1' => 'csökkenti (pl. éhség)', '1' => 'növeli (pl. jóllakottság)'], (string) ((int) ($eat['irany'] ?? -1) >= 0 ? 1 : -1))
            .'</div>'
            .'<h3>A test</h3><div class="row">'
            .View::select('Energia (az alvás és a pihenés ezt tölti)', 'body_energy', $statOptionsBody, $body['energia'] ?? '')
            .View::select('Higiénia (a tisztálkodás ezt állítja maximumra)', 'body_hygiene', $statOptionsBody, $body['higienia'] ?? '')
            .'</div><div class="row">'
            .View::field('Alvás: energia óránként, ágyban', 'sleep_rate', (float) ($body['alvas_orankent'] ?? 12), 'number', ['min' => 0, 'max' => 100, 'step' => '0.5'])
            .View::field('Ágy nélkül ennyiszerese (pl. 0.5)', 'sleep_nobed', (float) ($body['alvas_agy_nelkul'] ?? 0.5), 'number', ['min' => 0, 'max' => 1, 'step' => '0.05'])
            .View::field('Rövid pihenés: energia', 'rest_gain', (float) ($body['pihenes'] ?? 15), 'number', ['min' => 0, 'max' => 100])
            .View::field('Pihenés naponta (alkalom)', 'rest_daily', (int) ($body['pihenes_naponta'] ?? 1), 'number', ['min' => 0, 'max' => 10])
            .'</div><p class="muted" style="font-size:13px">Ágynak az „agy”, mosdónak a „mosdo”, fürdőnek a „furdo” felszereltségű helyszín számít. '
            .'Az óránkénti hatásokat (pl. éhesen romlik a jókedv) „minden egész órában” kiváltójú szabályokkal adhatod meg.</p>'
            .'<h3>Kereskedés</h3><div class="row">'
            .View::field('Készletpótlás órája (a napi keretek ekkor indulnak újra)', 'restock', (int) ($s['keszletpotlas_ora'] ?? 6), 'number', ['min' => 0, 'max' => 23])
            .View::select('Hírnév (globális reputáció) tulajdonság', 'reputation', $statOptionsBody, $s['reputacio'] ?? '')
            .'</div>'
            .'<h3>A játék vége</h3>'
            .View::field('Győzelmi szöveg (ha a céltárgynak nincs saját befejező szövege)', 'win_text', (string) ($s['gyozelem_szoveg'] ?? ''), 'textarea', ['rows' => 2])
            .View::field('Vereség feltételei (JSON-lista: mindegyik elem egy lehetséges vereség, saját szöveggel)', 'defeat', $defeat === '[]' ? '' : $defeat, 'textarea',
                ['rows' => 6, 'class' => 'mono', 'placeholder' => '[{"feltetelek": [{"tulajdonsag": ["egeszseg", "<=", 0]}], "szoveg": "Elfogytak az erőid."}]'])
            .'<button class="btn">Mentés</button></form>';
    }

    private function saveSettings(string $gid): never
    {
        $game = $this->requireGame($gid);
        $s = $this->gameSettings($game);
        $s['kez_meret'] = max(1, min(100, (int) ($_POST['hand'] ?? 4)));
        $s['zseb_kapacitas'] = max(0, min(100, (int) ($_POST['pocket'] ?? 2)));
        $stat = (string) ($_POST['eat_stat'] ?? '');
        $s['etkezes'] = ['tulajdonsag' => $stat, 'irany' => (int) ($_POST['eat_dir'] ?? -1) >= 0 ? 1 : -1];
        $s['test'] = [
            'energia'          => (string) ($_POST['body_energy'] ?? ''),
            'higienia'         => (string) ($_POST['body_hygiene'] ?? ''),
            'alvas_orankent'   => max(0, min(100, (float) ($_POST['sleep_rate'] ?? 12))),
            'alvas_agy_nelkul' => max(0, min(1, (float) ($_POST['sleep_nobed'] ?? 0.5))),
            'pihenes'          => max(0, min(100, (float) ($_POST['rest_gain'] ?? 15))),
            'pihenes_naponta'  => max(0, min(10, (int) ($_POST['rest_daily'] ?? 1))),
        ];
        $s['keszletpotlas_ora'] = max(0, min(23, (int) ($_POST['restock'] ?? 6)));
        $rep = (string) ($_POST['reputation'] ?? '');
        if ($rep === '') {
            unset($s['reputacio']);
        } else {
            $s['reputacio'] = $rep;
        }
        $win = trim((string) ($_POST['win_text'] ?? ''));
        if ($win === '') {
            unset($s['gyozelem_szoveg']);
        } else {
            $s['gyozelem_szoveg'] = $win;
        }

        $defeatJson = trim((string) ($_POST['defeat'] ?? ''));
        $defeat = $defeatJson === '' ? [] : json_decode($defeatJson, true);
        if (!is_array($defeat) || !array_is_list($defeat)) {
            $this->fail('A vereség feltételei nem érvényes JSON-lista.');
        }
        foreach ($defeat as $i => $d) {
            if (!is_array($d) || !isset($d['feltetelek']) || !is_array($d['feltetelek'])) {
                $this->fail(sprintf('A vereség %d. elemében hiányzik a „feltetelek” lista.', $i + 1));
            }
            if ($errors = Rules::validate(json_encode($d['feltetelek']), Rules::CONDITIONS, 'feltételek')) {
                $this->fail(implode(' ', $errors));
            }
        }
        $s['vereseg'] = $defeat;

        $this->db->prepare('UPDATE games SET settings = ? WHERE id = ?')->execute([json_encode($s, JSON_UNESCAPED_UNICODE), $gid]);
        $this->ok('A játékszabályok mentve.', ['p' => 'game', 'g' => $gid]);
    }

    private function rulesTable(string $gid): string
    {
        $stmt = $this->db->prepare('SELECT * FROM rules WHERE game_id = ? ORDER BY trigger_action, priority, id');
        $stmt->execute([$gid]);
        $rows = '';
        foreach ($stmt->fetchAll() as $r) {
            $rows .= '<tr><td><a href="'.View::e(View::url(['p' => 'rule', 'g' => $gid, 'r' => $r['id']])).'"><b>'.View::e($r['name']).'</b></a>'
                .($r['active'] ? '' : ' <span class="badge">kikapcsolva</span>').($r['once'] ? ' <span class="badge">egyszeri</span>' : '').'</td>'
                .'<td>'.View::e(Rules::TRIGGERS[$r['trigger_action']] ?? $r['trigger_action']).'</td>'
                .'<td class="mono">'.View::e($r['conditions']).'</td><td class="mono">'.View::e($r['effects']).'</td></tr>';
        }

        return $rows ? '<table><tr><th>Szabály</th><th>Amikor a játékos…</th><th>Feltételek</th><th>Hatások</th></tr>'.$rows.'</table>'
                     : '<p class="muted">Még nincs szabály.</p>';
    }

    private function pageRule(): void
    {
        $game = $this->requireGame((string) ($_GET['g'] ?? ''));
        $gid = $game['id'];
        $rid = (int) ($_GET['r'] ?? 0);
        $rule = null;
        if ($rid > 0) {
            $stmt = $this->db->prepare('SELECT * FROM rules WHERE game_id = ? AND id = ?');
            $stmt->execute([$gid, $rid]);
            $rule = $stmt->fetch() ?: $this->notFound();
        }
        $isNew = $rule === null;
        $rule ??= ['id' => 0, 'name' => '', 'trigger_action' => 'megvizsgal', 'conditions' => '[]', 'effects' => '[]', 'message' => '', 'once' => 0, 'priority' => 100, 'active' => 1];
        $pretty = fn (string $j) => ($d = json_decode($j, true)) ? json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : '';

        $help = function (array $list): string {
            $rows = '';
            foreach ($list as $k => [$shape, $desc]) {
                $rows .= '<tr><td class="mono">'.View::e($k).'</td><td class="mono">'.View::e($shape).'</td><td>'.View::e($desc).'</td></tr>';
            }
            return '<table><tr><th>Kulcs</th><th>Érték</th><th>Jelentés</th></tr>'.$rows.'</table>';
        };

        // Azonosítók a kéznél: tárgyak, helyszínek, tulajdonságok
        $ids = [];
        foreach (['items' => 'Tárgyak', 'locations' => 'Helyszínek', 'game_stats' => 'Tulajdonságok'] as $table => $label) {
            $stmt = $this->db->prepare("SELECT id FROM $table WHERE game_id = ? ORDER BY id");
            $stmt->execute([$gid]);
            $ids[] = '<b>'.$label.':</b> <span class="mono">'.View::e(implode(', ', $stmt->fetchAll(PDO::FETCH_COLUMN)) ?: '–').'</span>';
        }

        $body = '<form method="post" class="card">'.View::csrf().'<input type="hidden" name="a" value="save_rule"><input type="hidden" name="g" value="'.View::e($gid).'">'
            .'<input type="hidden" name="r" value="'.(int) $rule['id'].'">'
            .View::field('A szabály neve (csak neked)', 'name', $rule['name'], 'text', ['required' => true, 'maxlength' => 150])
            .'<div class="row">'.View::select('Amikor a játékos…', 'trigger', Rules::TRIGGERS, $rule['trigger_action'])
            .View::field('Sorrend (kisebb = előbb)', 'priority', $rule['priority'], 'number').'</div>'
            .View::field('Feltételek (JSON-lista; mind teljesüljön)', 'conditions', $pretty((string) $rule['conditions']), 'textarea',
                ['rows' => 5, 'class' => 'mono', 'placeholder' => '[{"targy": "ujsag"}, {"nincs_jelzo": "olvasta"}]'])
            .View::field('Hatások (JSON-lista)', 'effects', $pretty((string) $rule['effects']), 'textarea',
                ['rows' => 5, 'class' => 'mono', 'placeholder' => '[{"jelzo": "olvasta"}, {"tulajdonsag": ["ehseg", -10]}]'])
            .View::field('Üzenet a mesélőnek (mit meséljen el, ha a szabály teljesül)', 'message', (string) $rule['message'], 'textarea', ['rows' => 3])
            .'<label style="display:block;margin:6px 0"><input type="checkbox" name="once" value="1"'.($rule['once'] ? ' checked' : '').'> Egyszeri: egy játék alatt csak egyszer teljesülhet</label>'
            .'<label style="display:block;margin:6px 0 14px"><input type="checkbox" name="active" value="1"'.($rule['active'] ? ' checked' : '').'> Bekapcsolva</label>'
            .'<button class="btn">'.($isNew ? 'Szabály létrehozása' : 'Mentés').'</button></form>'
            .'<div class="card"><p>'.implode('<br>', $ids).'</p>'
            .'<p class="muted">A feltételek a művelet <b>előtt</b> értékelődnek ki. Ha teljesülnek és van <span class="mono">tiltas</span> hatás, a művelet elmarad; '
            .'különben a hatások a művelet <b>után</b> érvényesülnek. Példa – a kút vize csak üvegben vihető: '
            .'kiváltó: <i>felvesz</i>, feltételek: <span class="mono">[{"targy": "viz"}, {"nincs_nala": "ures_uveg"}]</span>, '
            .'hatás: <span class="mono">[{"tiltas": "Nincs mibe merítened a vizet."}]</span></p></div>'
            .'<h2>Feltételek</h2>'.$help(Rules::CONDITIONS)
            .'<h2>Hatások</h2>'.$help(Rules::EFFECTS)
            .($isNew ? '' : '<h2>Veszélyzóna</h2>'.View::postButton('Szabály törlése', ['a' => 'delete_rule', 'g' => $gid, 'r' => $rule['id']], 'Biztosan törlöd a szabályt?', 'btn danger'));

        $title = $isNew ? 'Új szabály' : $rule['name'];
        echo View::layout($title, $body, $this->user, $this->takeFlash(),
            ['Játékok' => '/admin/', $game['title'] => View::url(['p' => 'game', 'g' => $gid]), $title => null]);
    }

    private function saveRule(string $gid): never
    {
        $this->requireGame($gid);
        $rid = (int) ($_POST['r'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $trigger = (string) ($_POST['trigger'] ?? '');
        if ($name === '' || !isset(Rules::TRIGGERS[$trigger])) {
            $this->fail('A név és a kiváltó művelet kötelező.');
        }
        $conds = trim((string) ($_POST['conditions'] ?? '')) ?: '[]';
        $effects = trim((string) ($_POST['effects'] ?? '')) ?: '[]';
        $errors = array_merge(Rules::validate($conds, Rules::CONDITIONS, 'feltételek'), Rules::validate($effects, Rules::EFFECTS, 'hatások'));
        if ($errors) {
            $this->fail(implode(' ', $errors));
        }
        $norm = fn (string $j) => json_encode(json_decode($j, true), JSON_UNESCAPED_UNICODE);
        $vals = [$name, $trigger, $norm($conds), $norm($effects), trim((string) ($_POST['message'] ?? '')) ?: null,
            !empty($_POST['once']) ? 1 : 0, (int) ($_POST['priority'] ?? 100), !empty($_POST['active']) ? 1 : 0];

        if ($rid > 0) {
            $this->db->prepare('UPDATE rules SET name = ?, trigger_action = ?, conditions = ?, effects = ?, message = ?, once = ?, priority = ?, active = ? WHERE game_id = ? AND id = ?')
                ->execute([...$vals, $gid, $rid]);
        } else {
            $this->db->prepare('INSERT INTO rules (name, trigger_action, conditions, effects, message, once, priority, active, game_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([...$vals, $gid]);
            $rid = (int) $this->db->lastInsertId();
        }
        $this->ok('A szabály mentve.', ['p' => 'rule', 'g' => $gid, 'r' => $rid]);
    }

    /** A tárgyűrlap jellemzői; a nem ismert (kézzel beírt) kulcsok megmaradnak. */
    private function itemPropsFromPost(array $props): array
    {
        $set = function (string $key, mixed $value) use (&$props): void {
            if ($value === null || $value === '' || $value === [] || $value === false) {
                unset($props[$key]);
            } else {
                $props[$key] = $value;
            }
        };
        $set('cimkek', self::csv((string) ($_POST['tags'] ?? '')));
        $set('vizsgalat', trim((string) ($_POST['examine'] ?? '')));
        $cap = max(0, (int) ($_POST['cap'] ?? 0));
        $set('tarolo', $cap > 0 ? array_filter(['kapacitas' => $cap, 'befogad' => self::csv((string) ($_POST['accepts'] ?? ''))]) : null);
        $set('tarolot_igenyel', trim((string) ($_POST['needs'] ?? '')));
        $set('hely', !empty($_POST['backpack']) ? 'hat' : null);
        $set('nem_felveheto', !empty($_POST['fixed']));
        $set('cel', !empty($_POST['goal']));
        $set('vege_szoveg', trim((string) ($_POST['ending'] ?? '')));

        return $props;
    }

    /** Vesszővel elválasztott címkék → tisztított lista */
    private static function csv(string $s): array
    {
        $out = [];
        foreach (explode(',', $s) as $part) {
            $p = trim(mb_strtolower($part));
            $p = strtr($p, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ö' => 'o', 'ő' => 'o', 'ú' => 'u', 'ü' => 'u', 'ű' => 'u', ' ' => '_']);
            $p = (string) preg_replace('/[^a-z0-9_]/', '', $p);
            if ($p !== '' && !in_array($p, $out, true)) {
                $out[] = $p;
            }
        }

        return $out;
    }

    /** Szabályokban és kijáratokban hivatkozott, de nem létező tárgyak és helyszínek. @return list<string> */
    private function brokenReferences(string $gid, array $itemIds, array $locIds): array
    {
        $itemKeys = ['targy', 'tarolo', 'van_nala', 'nincs_nala', 'van_itt', 'targy_elvesz', 'targy_letrehoz', 'targy_atalakit'];
        $locKeys = ['helyszin', 'latogatott', 'athelyez'];
        $check = function (string $json, string $where) use ($itemIds, $locIds, $itemKeys, $locKeys): array {
            $bad = [];
            foreach (json_decode($json, true) ?: [] as $entry) {
                foreach (is_array($entry) ? $entry : [] as $k => $v) {
                    $vals = match (true) {
                        $k === 'targy_letrehoz' && is_array($v) => [$v['targy'] ?? ''],
                        $k === 'targy_atalakit' => (array) $v,
                        in_array($k, ['van_nala', 'targy_elvesz'], true) && is_array($v) => [$v[0] ?? ''],
                        default => (array) $v,
                    };
                    foreach ($vals as $id) {
                        if (in_array($k, $itemKeys, true) && is_string($id) && !in_array($id, $itemIds, true)) {
                            $bad[] = sprintf('%s: nincs ilyen tárgy: „%s”', $where, $id);
                        }
                        if (in_array($k, $locKeys, true) && is_string($id) && !in_array($id, $locIds, true)) {
                            $bad[] = sprintf('%s: nincs ilyen helyszín: „%s”', $where, $id);
                        }
                    }
                }
            }
            return $bad;
        };

        $out = [];
        $stmt = $this->db->prepare('SELECT name, conditions, effects FROM rules WHERE game_id = ?');
        $stmt->execute([$gid]);
        foreach ($stmt->fetchAll() as $r) {
            array_push($out, ...$check((string) $r['conditions'], 'Szabály („'.View::e($r['name']).'”)'), ...$check((string) $r['effects'], 'Szabály („'.View::e($r['name']).'”)'));
        }
        $stmt = $this->db->prepare('SELECT name, schedule FROM npcs WHERE game_id = ?');
        $stmt->execute([$gid]);
        foreach ($stmt->fetchAll() as $n) {
            foreach (json_decode((string) $n['schedule'], true) ?: [] as $slot) {
                if (!in_array($slot['helyszin'] ?? '', $locIds, true)) {
                    $out[] = sprintf('Szereplő („%s”): nincs ilyen helyszín a beosztásában: „%s”', View::e($n['name']), View::e($slot['helyszin'] ?? ''));
                }
            }
        }
        $stmt = $this->db->prepare('SELECT t.trader_id FROM trades t WHERE t.game_id = ? AND t.trader_type = \'location\'');
        $stmt->execute([$gid]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $tl) {
            if (!in_array($tl, $locIds, true)) {
                $out[] = sprintf('Kereskedés: nincs ilyen helyszín: „%s”', View::e($tl));
            }
        }
        $stmt = $this->db->prepare('SELECT from_location, direction, conditions FROM exits WHERE game_id = ? AND conditions IS NOT NULL');
        $stmt->execute([$gid]);
        foreach ($stmt->fetchAll() as $e) {
            array_push($out, ...$check((string) $e['conditions'], 'Kijárat ('.View::e($e['from_location'].' → '.$e['direction']).')'));
        }

        return array_values(array_unique($out));
    }

    private function validId(string $id, string $what): void
    {
        if (!preg_match(self::ID_PATTERN, $id)) {
            $this->fail("A(z) $what azonosítója csak kisbetűt, számot és aláhúzást tartalmazhat (legfeljebb 50 karakter).");
        }
    }

    private function imageUrlFor(string $gid, string $lid): ?string
    {
        foreach (self::IMAGE_EXT as $ext) {
            $file = $this->imageDir.'/'.$gid.'/'.$lid.'.'.$ext;
            if (is_file($file)) {
                return $this->imageUrl.'/'.rawurlencode($gid).'/'.rawurlencode($lid).'.'.$ext.'?v='.filemtime($file);
            }
        }

        return null;
    }

    private function removeImages(string $gid, string $lid): void
    {
        foreach (self::IMAGE_EXT as $ext) {
            $file = $this->imageDir.'/'.$gid.'/'.$lid.'.'.$ext;
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    private function flash(string $type, string $text): void
    {
        $_SESSION['flash'] = ['type' => $type, 'text' => $text];
    }

    private function takeFlash(): ?array
    {
        $f = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        return $f;
    }

    private function ok(string $text, array $to): never
    {
        $this->flash('ok', $text);
        $this->redirect(View::url($to));
    }

    private function fail(string $text): never
    {
        $this->flash('err', $text);
        $this->back();
    }

    private function back(): never
    {
        $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $path = parse_url($ref, PHP_URL_PATH);
        $query = parse_url($ref, PHP_URL_QUERY);
        $this->redirect($path === '/admin/' ? '/admin/'.($query ? '?'.$query : '') : '/admin/');
    }

    private function redirect(string $to): never
    {
        header('Location: '.$to, true, 303);
        exit;
    }

    private function notFound(): never
    {
        http_response_code(404);
        echo View::layout('Nem található', '<p>A keresett elem nem létezik. <a href="/admin/">Vissza a játékokhoz</a></p>', $this->user);
        exit;
    }
}
