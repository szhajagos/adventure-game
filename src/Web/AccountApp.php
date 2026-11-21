<?php

declare(strict_types=1);

namespace AdventureGame\Web;

use AdventureGame\Auth\Mailer;
use AdventureGame\Auth\Users;
use AdventureGame\Config;
use PDO;

/**
 * A játékos fiókoldalai: /fiok/
 *   ?p=regisztracio, belepes, kilepes, megerosites, elfelejtett, uj_jelszo  – és alapból a fiók áttekintése
 */
final class AccountApp
{
    private Users $users;

    public function __construct(private readonly PDO $db, private readonly string $appUrl)
    {
        $this->users = new Users($db);
    }

    public function run(): void
    {
        Session::start();
        $page = (string) ($_GET['p'] ?? '');
        $post = $_SERVER['REQUEST_METHOD'] === 'POST';

        if ($post && !Session::checkCsrf()) {
            Session::flash('err', 'A munkamenet lejárt, próbáld újra.');
            $this->redirect('/fiok/'.($page !== '' ? '?p='.rawurlencode($page) : ''));
        }

        match ($page) {
            'regisztracio' => $this->register($post),
            'belepes'      => $this->login($post),
            'kilepes'      => $post ? $this->logout() : $this->redirect('/fiok/'),
            'megerosites'  => $this->verify($post),
            'elfelejtett'  => $this->forgot($post),
            'uj_jelszo'    => $this->reset($post),
            'alkalmazas'   => $post ? $this->revokeApp() : $this->redirect('/fiok/'),
            default        => $this->overview(),
        };
    }

    // =====================================================================

    private function overview(): void
    {
        $user = $this->requireUser();
        $mcpUrl = $this->appUrl.'/mcp';

        $verify = $user['email_verified_at']
            ? ''
            : '<div class="box"><b>Erősítsd meg az e-mail-címed.</b> Küldtünk egy levelet a <span class="code">'.Page::e($user['email']).'</span> címre. '
              .'Ha nem találod, nézd meg a levélszemét mappát, vagy '
              .'<form method="post" action="/fiok/?p=megerosites" class="inline">'.Page::csrf().'<button class="link">küldjük el újra</button></form>.</div>';

        $apps = $this->users->connectedApps((int) $user['id']);
        $appRows = '';
        foreach ($apps as $a) {
            $host = parse_url((string) $a['client_id'], PHP_URL_HOST) ?: ($a['client_name'] ?: 'alkalmazás');
            $label = $a['client_name'] ? $a['client_name'].' ('.$host.')' : $host;
            $appRows .= '<tr><td>'.Page::e($label).'</td><td class="muted">'.Page::e(substr((string) $a['last_seen'], 0, 16)).'</td><td>'
                .'<form method="post" action="/fiok/?p=alkalmazas" class="inline" onsubmit="return confirm(\'Lekapcsolod ezt az alkalmazást?\')">'.Page::csrf()
                .Page::hidden('client_id', (string) $a['client_id']).'<button class="link">lekapcsolás</button></form></td></tr>';
        }

        $body = $verify
            .'<p>Szia, <b>'.Page::e($user['display_name']).'</b>! A játékot a saját AI-asszisztensedben játszod. Így kapcsolod össze:</p>'
            .'<h2>Claude</h2><ol>'
            .'<li><b>Beállítások → Customize → Connectors → Add → Add custom connector</b></li>'
            .'<li>Név: <span class="code">MCP Adventure</span>, URL:<div class="box code">'.Page::e($mcpUrl).'</div></li>'
            .'<li>Authentication: <b>Sign in now</b> · OAuth client: <b>Use Claude\'s published identity</b></li>'
            .'<li><b>Add</b>, majd <b>Connect</b>: megnyílik ez az oldal, itt jóváhagyod a kapcsolatot.</li>'
            .'<li>Új beszélgetésben kapcsold be a connectort a <b>+</b> menüben, és kérdezd meg: <i>„Milyen játékok vannak?”</i></li></ol>'
            .'<p class="muted">Más AI-asszisztens (ChatGPT, Gemini, Le Chat) is használhatja ugyanezt a címet, ha támogatja a saját MCP-connectorokat.</p>'
            .'<h2>Csatlakoztatott alkalmazások</h2>'
            .($appRows ? '<table>'.$appRows.'</table>' : '<p class="muted">Még egy AI-asszisztens sincs összekapcsolva a fiókoddal.</p>');

        Page::render('Fiókom', $body, Session::takeFlash(), $user['display_name']);
    }

    private function register(bool $post): void
    {
        if (Session::userId()) {
            $this->redirect('/fiok/');
        }
        $error = null;
        if ($post) {
            [$id, $error] = $this->users->register(
                (string) ($_POST['email'] ?? ''), (string) ($_POST['name'] ?? ''),
                (string) ($_POST['password'] ?? ''), (string) ($_POST['password2'] ?? ''),
            );
            if ($id !== null) {
                $this->sendVerification($id);
                Session::login($id);
                Session::flash('ok', 'Üdv a játékban! Küldtünk egy megerősítő levelet az e-mail-címedre.');
                $this->redirect($this->back('/fiok/'));
            }
        }
        $body = '<form method="post">'.Page::csrf().Page::hidden('vissza', $this->back(''))
            .Page::field('Játékosnév', 'name', (string) ($_POST['name'] ?? ''), 'text', ['required' => true, 'maxlength' => 60, 'autocomplete' => 'nickname'])
            .Page::field('E-mail-cím', 'email', (string) ($_POST['email'] ?? ''), 'email', ['required' => true, 'autocomplete' => 'email'])
            .Page::field('Jelszó (legalább '.Users::MIN_PASSWORD.' karakter)', 'password', '', 'password', ['required' => true, 'minlength' => Users::MIN_PASSWORD, 'autocomplete' => 'new-password'])
            .Page::field('Jelszó még egyszer', 'password2', '', 'password', ['required' => true, 'minlength' => Users::MIN_PASSWORD, 'autocomplete' => 'new-password'])
            .'<div class="row"><button class="btn">Regisztráció</button><a href="/fiok/?p=belepes'.$this->backQuery().'">Már van fiókom</a></div></form>';
        Page::render('Regisztráció', $body, $error ? ['type' => 'err', 'text' => $error] : Session::takeFlash());
    }

    private function login(bool $post): void
    {
        if (Session::userId()) {
            $this->redirect($this->back('/fiok/'));
        }
        $error = null;
        if ($post) {
            [$id, $error] = $this->users->login((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
            if ($id !== null) {
                Session::login($id);
                $this->redirect($this->back('/fiok/'));
            }
        }
        $body = '<form method="post">'.Page::csrf().Page::hidden('vissza', $this->back(''))
            .Page::field('E-mail-cím', 'email', (string) ($_POST['email'] ?? ''), 'email', ['required' => true, 'autocomplete' => 'username'])
            .Page::field('Jelszó', 'password', '', 'password', ['required' => true, 'autocomplete' => 'current-password'])
            .'<div class="row"><button class="btn">Belépés</button><a href="/fiok/?p=regisztracio'.$this->backQuery().'">Regisztráció</a>'
            .'<a href="/fiok/?p=elfelejtett">Elfelejtettem a jelszavam</a></div></form>';
        Page::render('Belépés', $body, $error ? ['type' => 'err', 'text' => $error] : Session::takeFlash());
    }

    private function logout(): never
    {
        Session::logout();
        Session::start();
        Session::flash('ok', 'Kiléptél.');
        $this->redirect('/fiok/?p=belepes');
    }

    private function verify(bool $post): void
    {
        if ($post) { // megerősítő levél újraküldése
            $user = $this->requireUser();
            $this->sendVerification((int) $user['id']);
            Session::flash('ok', 'Újra elküldtük a megerősítő levelet.');
            $this->redirect('/fiok/');
        }
        $userId = $this->users->useToken((string) ($_GET['t'] ?? ''), 'verify');
        if ($userId === null) {
            Session::flash('err', 'A megerősítő link érvénytelen vagy lejárt. Belépés után kérhetsz újat.');
            $this->redirect('/fiok/');
        }
        $this->users->markVerified($userId);
        Session::flash('ok', 'Az e-mail-címed megerősítve. Mehet a játék!');
        $this->redirect('/fiok/');
    }

    private function forgot(bool $post): void
    {
        if ($post) {
            $user = $this->users->findByEmail((string) ($_POST['email'] ?? ''));
            if ($user) {
                $token = $this->users->createToken((int) $user['id'], 'reset', 60);
                Mailer::send($user['email'], 'Új jelszó – MCP Adventure',
                    "Szia {$user['display_name']}!\n\nÚj jelszót ezen a linken állíthatsz be (1 óráig érvényes):\n"
                    .$this->appUrl.'/fiok/?p=uj_jelszo&t='.$token."\n\nHa nem te kérted, hagyd figyelmen kívül ezt a levelet.\n\nMCP Adventure");
            }
            usleep(300_000);
            Session::flash('ok', 'Ha ehhez a címhez tartozik fiók, elküldtük a jelszó-visszaállító linket.');
            $this->redirect('/fiok/?p=belepes');
        }
        $body = '<p class="muted">Add meg a fiókod e-mail-címét, és küldünk egy linket az új jelszóhoz.</p><form method="post">'.Page::csrf()
            .Page::field('E-mail-cím', 'email', '', 'email', ['required' => true, 'autocomplete' => 'email'])
            .'<button class="btn">Link küldése</button></form>';
        Page::render('Elfelejtett jelszó', $body, Session::takeFlash());
    }

    private function reset(bool $post): void
    {
        $token = (string) ($_POST['t'] ?? $_GET['t'] ?? '');
        if ($this->users->useToken($token, 'reset', consume: false) === null) {
            Session::flash('err', 'A link érvénytelen vagy lejárt. Kérj újat.');
            $this->redirect('/fiok/?p=elfelejtett');
        }
        $error = null;
        if ($post) {
            $p1 = (string) ($_POST['password'] ?? '');
            if ($p1 !== (string) ($_POST['password2'] ?? '')) {
                $error = 'A két jelszó nem egyezik.';
            } elseif (mb_strlen($p1) < Users::MIN_PASSWORD) {
                $error = 'A jelszó legalább '.Users::MIN_PASSWORD.' karakter legyen.';
            } else {
                $userId = $this->users->useToken($token, 'reset');
                $this->users->setPassword((int) $userId, $p1);
                $this->users->markVerified((int) $userId); // a levél megérkezett, tehát a cím valódi
                Session::flash('ok', 'Az új jelszó beállítva, most már beléphetsz vele.');
                $this->redirect('/fiok/?p=belepes');
            }
        }
        $body = '<form method="post">'.Page::csrf().Page::hidden('t', $token)
            .Page::field('Új jelszó (legalább '.Users::MIN_PASSWORD.' karakter)', 'password', '', 'password', ['required' => true, 'minlength' => Users::MIN_PASSWORD, 'autocomplete' => 'new-password'])
            .Page::field('Új jelszó még egyszer', 'password2', '', 'password', ['required' => true, 'minlength' => Users::MIN_PASSWORD, 'autocomplete' => 'new-password'])
            .'<button class="btn">Jelszó beállítása</button></form>';
        Page::render('Új jelszó', $body, $error ? ['type' => 'err', 'text' => $error] : null);
    }

    private function revokeApp(): never
    {
        $user = $this->requireUser();
        $this->users->revokeApp((int) $user['id'], (string) ($_POST['client_id'] ?? ''));
        Session::flash('ok', 'Az alkalmazás lekapcsolva. Újra összekapcsolhatod, ha szeretnéd.');
        $this->redirect('/fiok/');
    }

    // =====================================================================

    private function sendVerification(int $userId): void
    {
        $user = $this->users->find($userId);
        $token = $this->users->createToken($userId, 'verify', 60 * 48);
        Mailer::send($user['email'], 'Erősítsd meg az e-mail-címed – MCP Adventure',
            "Szia {$user['display_name']}!\n\nKöszönjük, hogy regisztráltál. Az e-mail-címed ezen a linken erősítheted meg (48 óráig érvényes):\n"
            .$this->appUrl.'/fiok/?p=megerosites&t='.$token."\n\nHa nem te regisztráltál, hagyd figyelmen kívül ezt a levelet.\n\nMCP Adventure");
    }

    private function requireUser(): array
    {
        $id = Session::userId();
        $user = $id ? $this->users->find($id) : null;
        if (!$user) {
            $this->redirect('/fiok/?p=belepes');
        }

        return $user;
    }

    /** A belépés utáni visszatérési cím – csak a saját oldalaink közül */
    private function back(string $default): string
    {
        $v = (string) ($_POST['vissza'] ?? $_GET['vissza'] ?? '');
        if (preg_match('#^/(oauth/authorize\?|fiok/)#', $v) && !str_contains($v, '//') && !preg_match('/[\r\n]/', $v)) {
            return $v;
        }

        return $default;
    }

    private function backQuery(): string
    {
        $b = $this->back('');

        return $b !== '' ? '&vissza='.rawurlencode($b) : '';
    }

    private function redirect(string $to): never
    {
        header('Location: '.$to, true, 303);
        exit;
    }
}
