<?php

declare(strict_types=1);

namespace AdventureGame\Admin;

use AdventureGame\Config;
use AdventureGame\Game\Direction;
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
            $irows .= '<tr><td><a href="'.View::e(View::url(['p' => 'item', 'g' => $gid, 'i' => $i['id']])).'"><b>'.View::e($i['name']).'</b></a>'
                .'<div class="mono muted">'.View::e($i['id']).'</div></td><td>'.View::e($i['price']).' '.View::e($this->moneyUnit($gid)).'</td>'
                .'<td>'.($i['nutrition'] ? '+'.View::e($i['nutrition']).' jóllakottság' : '–').'</td></tr>';
        }

        $count = count($locs);
        $pct = min(100, (int) round($count / self::LOCATION_GOAL * 100));

        $body = '<div class="card"><b>'.$count.' / '.self::LOCATION_GOAL.'</b> helyszín<div class="progress"><i style="width:'.$pct.'%"></i></div>'
            .'<div class="actions"><a class="btn light" href="'.View::e(View::url(['p' => 'export', 'g' => $gid])).'">SQL-mentés letöltése</a></div></div>'
            .'<h2>Ellenőrzés</h2>'.$this->checks($game, $locs)
            .'<h2>Helyszínek</h2><div class="actions"><a class="btn" href="'.View::e(View::url(['p' => 'loc_new', 'g' => $gid])).'">+ Új helyszín</a></div><br>'
            .($lrows ? '<table><tr><th>Kép</th><th>Helyszín</th><th>Kijáratok</th><th>Tárgyak</th></tr>'.$lrows.'</table>' : '<p class="muted">Még nincs helyszín.</p>')
            .'<h2>Tárgyak</h2><div class="actions"><a class="btn" href="'.View::e(View::url(['p' => 'item', 'g' => $gid])).'">+ Új tárgy</a></div><br>'
            .($irows ? '<table><tr><th>Tárgy</th><th>Ár</th><th>Étel</th></tr>'.$irows.'</table>' : '<p class="muted">Még nincs tárgy.</p>')
            .'<h2>Tulajdonságok</h2><p class="muted">A játékos jellemzői ebben a játékban (pénz, éhség, oxigén, hírnév…). '
            .'Új tulajdonság a meglévő mentésekben a kezdőértékével jelenik meg.</p>'
            .'<div class="actions"><a class="btn" href="'.View::e(View::url(['p' => 'stat', 'g' => $gid])).'">+ Új tulajdonság</a></div><br>'
            .$this->statsTable($gid)
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
            .($img ? View::postButton('Kép törlése', ['a' => 'delete_img', 'g' => $gid, 'l' => $lid], 'Biztosan törlöd a képet?', 'btn danger small') : '');

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
                .'<td>'.View::e($e['description']).'</td><td>'.View::e($e['minutes']).' perc</td>'
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
            .'<button class="btn">Mentés</button></form>'
            .'</div><div class="card"><b>Kép</b><br><br>'.$imgBlock.'</div></div>'
            .'<h2>Kijáratok innen</h2>'.($erows ? '<table><tr><th>Irány</th><th>Hová</th><th>Leírás</th><th>Idő</th><th></th></tr>'.$erows.'</table><br>' : '<p class="muted">Még nincs kijárat.</p>')
            .$exitForm
            .'<h2>Tárgyak a helyszínen (a játék elején)</h2>'.($trows ? '<table><tr><th>Tárgy</th><th>Darab</th><th></th></tr>'.$trows.'</table>' : '<p class="muted">Nincs itt tárgy.</p>')
            .$itemForm
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
        $item ??= ['id' => '', 'name' => '', 'description' => '', 'price' => 0, 'nutrition' => 0];

        $body = '<form method="post" class="card">'.View::csrf().'<input type="hidden" name="a" value="save_item"><input type="hidden" name="g" value="'.View::e($gid).'">'
            .($isNew ? '<input type="hidden" name="new" value="1">'.View::field('Azonosító (kisbetű, szám, aláhúzás)', 'i', '', 'text', ['required' => true, 'pattern' => '[a-z0-9_]{1,50}'])
                     : '<input type="hidden" name="i" value="'.View::e($item['id']).'"><p class="mono muted">Azonosító: '.View::e($item['id']).'</p>')
            .View::field('Név (ahogy a játékban megjelenik)', 'name', $item['name'], 'text', ['required' => true, 'maxlength' => 100])
            .View::field('Leírás', 'description', $item['description'], 'textarea')
            .'<div class="row">'.View::field('Ár ('.($this->moneyUnit($gid) ?: 'pénz').')', 'price', $item['price'], 'number', ['min' => 0])
            .View::field('Tápérték (ennyivel csökkenti az éhséget, 0 = nem étel)', 'nutrition', $item['nutrition'], 'number', ['min' => 0, 'max' => 100]).'</div>'
            .'<button class="btn">'.($isNew ? 'Tárgy létrehozása' : 'Mentés').'</button></form>'
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
                $this->db->prepare('UPDATE locations SET name = ?, description = ? WHERE game_id = ? AND id = ?')->execute([$name, $desc, $gid, $lid]);
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
                $this->db->prepare('REPLACE INTO exits (game_id, from_location, direction, to_location, description, minutes) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$gid, $lid, $dir, $to, $desc, $minutes]);
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
                $vals = [$name, trim((string) ($_POST['description'] ?? '')), max(0, (int) ($_POST['price'] ?? 0)), max(0, min(100, (int) ($_POST['nutrition'] ?? 0)))];
                if (!empty($_POST['new'])) {
                    $this->validId($iid, 'tárgy');
                    $stmt = $this->db->prepare('SELECT 1 FROM items WHERE game_id = ? AND id = ?');
                    $stmt->execute([$gid, $iid]);
                    if ($stmt->fetchColumn()) {
                        $this->fail('Ilyen azonosítójú tárgy már van.');
                    }
                    $this->db->prepare('INSERT INTO items (name, description, price, nutrition, game_id, id) VALUES (?, ?, ?, ?, ?, ?)')->execute([...$vals, $gid, $iid]);
                    $this->ok('A tárgy elkészült.', ['p' => 'game', 'g' => $gid]);
                }
                $this->db->prepare('UPDATE items SET name = ?, description = ?, price = ?, nutrition = ? WHERE game_id = ? AND id = ?')->execute([...$vals, $gid, $iid]);
                $this->ok('Mentve.', ['p' => 'item', 'g' => $gid, 'i' => $iid]);

            case 'delete_item':
                $iid = (string) ($_POST['i'] ?? '');
                $stmt = $this->db->prepare('SELECT COUNT(*) FROM save_items WHERE game_id = ? AND item_id = ?');
                $stmt->execute([$gid, $iid]);
                if ((int) $stmt->fetchColumn() > 0) {
                    $this->fail('Ez a tárgy jelenleg játékosoknál van, ezért most nem törölhető.');
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
            $ins->execute([$id, 'egeszseg', 'Egészség', 'bar_high_good', 0, 100, 100, 0, null, 20]);
            $this->ok('A játék elkészült (egyelőre rejtett). Most hozd létre az első helyszínt – ez lesz a kezdő helyszín.', ['p' => 'loc_new', 'g' => $id]);
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
            ."-- DELETE FROM game_stats WHERE game_id = ".$q($gid)."; DELETE FROM items WHERE game_id = ".$q($gid)."; DELETE FROM locations WHERE game_id = ".$q($gid)."; DELETE FROM games WHERE id = ".$q($gid).";\n\n";

        $tables = [
            'games'          => ['SELECT * FROM games WHERE id = ?', []],
            'locations'      => ['SELECT * FROM locations WHERE game_id = ? ORDER BY id', []],
            'exits'          => ['SELECT * FROM exits WHERE game_id = ? ORDER BY from_location, direction', []],
            'game_stats'     => ['SELECT * FROM game_stats WHERE game_id = ? ORDER BY sort_order, id', []],
            'items'          => ['SELECT * FROM items WHERE game_id = ? ORDER BY id', []],
            'location_items' => ['SELECT * FROM location_items WHERE game_id = ? ORDER BY location_id, item_id', []],
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
