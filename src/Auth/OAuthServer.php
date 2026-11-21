<?php

declare(strict_types=1);

namespace AdventureGame\Auth;

use AdventureGame\Config;
use AdventureGame\Web\Page;
use AdventureGame\Web\Session;
use PDO;

/**
 * A játék saját OAuth 2.1 engedélyező szervere.
 * Ezen keresztül jelentkezik be a játékos a Claude-ból (vagy más AI-ból), és a Claude
 * ettől kap tokent, amivel a játékos nevében hívja az MCP-végpontot.
 *
 *  - Felfedezés: RFC 8414 (engedélyező szerver) és RFC 9728 (védett erőforrás) metaadatok
 *  - Kliensek: Dynamic Client Registration (RFC 7591) és Client ID Metadata Document (CIMD)
 *  - Kód + PKCE (S256), forgó frissítő tokenek, nyilvános (titok nélküli) kliensek
 */
final class OAuthServer
{
    public const SCOPE = 'jatek';
    private const ACCESS_TTL = 3600;              // 1 óra
    private const REFRESH_TTL_DAYS = 60;
    private const CODE_TTL = 300;                 // 5 perc
    private const CIMD_REFRESH_HOURS = 24;

    private string $issuer;
    private string $resource;

    public function __construct(private readonly PDO $db, string $appUrl)
    {
        $this->issuer = rtrim($appUrl, '/');
        $this->resource = $this->issuer.'/mcp';
    }

    public function resource(): string
    {
        return $this->resource;
    }

    public function resourceMetadataUrl(): string
    {
        return $this->issuer.'/.well-known/oauth-protected-resource/mcp';
    }

    // =====================================================================
    //  Felfedezés
    // =====================================================================

    public function authorizationServerMetadata(): array
    {
        return [
            'issuer'                                 => $this->issuer,
            'authorization_endpoint'                 => $this->issuer.'/oauth/authorize',
            'token_endpoint'                         => $this->issuer.'/oauth/token',
            'registration_endpoint'                  => $this->issuer.'/oauth/register',
            'scopes_supported'                       => [self::SCOPE],
            'response_types_supported'               => ['code'],
            'grant_types_supported'                  => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported'  => ['none'],
            'code_challenge_methods_supported'       => ['S256'],
            'client_id_metadata_document_supported'  => true,
            'authorization_response_iss_parameter_supported' => true,
            'service_documentation'                  => $this->issuer.'/fiok/',
        ];
    }

    public function protectedResourceMetadata(): array
    {
        return [
            'resource'                 => $this->resource,
            'authorization_servers'    => [$this->issuer],
            'scopes_supported'         => [self::SCOPE],
            'bearer_methods_supported' => ['header'],
            'resource_name'            => 'MCP Adventure',
            'resource_documentation'   => $this->issuer.'/fiok/',
        ];
    }

    public function wwwAuthenticate(string $error = '', string $description = ''): string
    {
        $parts = ['resource_metadata="'.$this->resourceMetadataUrl().'"', 'scope="'.self::SCOPE.'"'];
        if ($error !== '') {
            array_unshift($parts, 'error="'.$error.'"'.($description !== '' ? ', error_description="'.$description.'"' : ''));
        }

        return 'Bearer '.implode(', ', $parts);
    }

    // =====================================================================
    //  Dinamikus kliensregisztráció (RFC 7591)
    // =====================================================================

    public function register(): void
    {
        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body)) {
            $this->json(['error' => 'invalid_client_metadata', 'error_description' => 'JSON body expected'], 400);
        }
        $uris = $body['redirect_uris'] ?? null;
        if (!is_array($uris) || $uris === [] || count($uris) > 10) {
            $this->json(['error' => 'invalid_redirect_uri', 'error_description' => 'redirect_uris required'], 400);
        }
        foreach ($uris as $u) {
            if (!is_string($u) || !$this->acceptableRedirect($u)) {
                $this->json(['error' => 'invalid_redirect_uri', 'error_description' => 'redirect_uri must be https or a loopback http address'], 400);
            }
        }
        $method = $body['token_endpoint_auth_method'] ?? 'none';
        if ($method !== 'none') {
            $this->json(['error' => 'invalid_client_metadata', 'error_description' => 'only public clients (token_endpoint_auth_method=none) are supported'], 400);
        }
        $name = isset($body['client_name']) && is_string($body['client_name']) ? mb_substr($body['client_name'], 0, 255) : null;

        $clientId = 'dcr_'.bin2hex(random_bytes(16));
        $this->db->prepare("INSERT INTO oauth_clients (client_id, kind, client_name, redirect_uris) VALUES (?, 'dcr', ?, ?)")
            ->execute([$clientId, $name, json_encode(array_values($uris), JSON_UNESCAPED_SLASHES)]);

        $this->json([
            'client_id'                  => $clientId,
            'client_id_issued_at'        => time(),
            'client_name'                => $name,
            'redirect_uris'              => array_values($uris),
            'token_endpoint_auth_method' => 'none',
            'grant_types'                => ['authorization_code', 'refresh_token'],
            'response_types'             => ['code'],
            'scope'                      => self::SCOPE,
        ], 201);
    }

    // =====================================================================
    //  Engedélyezés: bejelentkezés + jóváhagyás
    // =====================================================================

    public function authorize(): void
    {
        Session::start();
        $q = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
        $clientId = (string) ($q['client_id'] ?? '');
        $redirect = (string) ($q['redirect_uri'] ?? '');

        // 1. A kliens és a visszairányítás ellenőrzése – ha ez hibás, NEM irányítunk vissza
        [$client, $err] = $this->client($clientId);
        if (!$client) {
            $this->errorPage('Ismeretlen alkalmazás', $err ?? 'Az alkalmazás azonosítója érvénytelen.');
        }
        if (!$this->redirectAllowed($redirect, $client['redirect_uris'])) {
            $this->errorPage('Érvénytelen visszairányítás', 'Az alkalmazás olyan címre kérte a visszairányítást, amelyet nem regisztrált.');
        }

        // 2. A kérés többi része – hiba esetén szabványos hibaüzenettel vissza az alkalmazáshoz
        $state = (string) ($q['state'] ?? '');
        if (($q['response_type'] ?? '') !== 'code') {
            $this->redirectError($redirect, $state, 'unsupported_response_type', 'response_type must be code');
        }
        $challenge = (string) ($q['code_challenge'] ?? '');
        if (($q['code_challenge_method'] ?? '') !== 'S256' || !preg_match('/^[A-Za-z0-9_-]{43,128}$/', $challenge)) {
            $this->redirectError($redirect, $state, 'invalid_request', 'PKCE with S256 is required');
        }
        $resource = (string) ($q['resource'] ?? '');
        if ($resource !== '' && !$this->resourceMatches($resource)) {
            $this->redirectError($redirect, $state, 'invalid_target', 'unknown resource');
        }

        // 3. Bejelentkezés
        $userId = Session::userId();
        $users = new Users($this->db);
        $user = $userId ? $users->find($userId) : null;
        if (!$user) {
            $back = '/oauth/authorize?'.http_build_query($this->flowParams($q));
            header('Location: /fiok/?p=belepes&vissza='.rawurlencode($back), true, 303);
            exit;
        }
        if (Config::get('REQUIRE_EMAIL_VERIFICATION', '1') === '1' && !$user['email_verified_at']) {
            $this->errorPage('Erősítsd meg az e-mail-címed',
                'A játék összekapcsolása előtt meg kell erősítened az e-mail-címed. A megerősítő levelet a regisztrációkor küldtük el; '
                .'a <a href="/fiok/">fiókoldaladon</a> újra is kérheted. Utána próbáld újra a csatlakozást.', true, $user['display_name']);
        }

        $appHost = $client['kind'] === 'cimd' ? (string) parse_url($clientId, PHP_URL_HOST) : (string) parse_url($redirect, PHP_URL_HOST);
        $redirectHost = (string) parse_url($redirect, PHP_URL_HOST);

        // 4. Döntés (POST) vagy a jóváhagyó képernyő (GET)
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Session::checkCsrf()) {
                $this->errorPage('Lejárt munkamenet', 'Próbáld újra a csatlakozást az AI-asszisztensedből.');
            }
            if (($_POST['decision'] ?? '') !== 'allow') {
                $this->redirectError($redirect, $state, 'access_denied', 'the user denied the request');
            }
            $code = bin2hex(random_bytes(32));
            $this->db->prepare(
                'INSERT INTO oauth_codes (code_hash, client_id, user_id, redirect_uri, code_challenge, scope, resource, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW() + INTERVAL '.self::CODE_TTL.' SECOND)'
            )->execute([hash('sha256', $code), $clientId, $user['id'], $redirect, $challenge, self::SCOPE, $resource ?: $this->resource]);

            $this->redirectTo($redirect, ['code' => $code, 'state' => $state, 'iss' => $this->issuer]);
        }

        $loopback = $this->isLoopback($redirect);
        $body = '<p><b>'.Page::e($appHost ?: 'Egy alkalmazás').'</b> hozzáférést kér a játékodhoz.</p>'
            .'<div class="box">Ha engedélyezed, ez az alkalmazás a nevedben játszhat az MCP Adventure játékaival: lépni, nézelődni, '
            .'és látja a játékállásodat. A fiókod adataihoz (e-mail-cím, jelszó) nem fér hozzá.</div>'
            .'<p class="muted">Bejelentkezve: '.Page::e($user['display_name']).' ('.Page::e($user['email']).') · '
            .'Visszairányítás ide: <span class="code">'.Page::e($redirectHost).'</span></p>'
            .($loopback ? '<p class="muted">Figyelem: a visszairányítás a saját gépedre mutat. Csak akkor engedélyezd, ha te indítottad a kapcsolódást (pl. a Claude Code-ból).</p>' : '')
            .'<form method="post" action="/oauth/authorize" class="row">'.Page::csrf();
        foreach ($this->flowParams($q) as $k => $v) {
            $body .= Page::hidden($k, $v);
        }
        $body .= '<button class="btn" name="decision" value="allow">Engedélyezem</button>'
            .'<button class="btn ghost" name="decision" value="deny">Mégsem</button></form>';

        Page::render('Kapcsolódás jóváhagyása', $body, null, $user['display_name']);
    }

    // =====================================================================
    //  Token végpont
    // =====================================================================

    public function token(): void
    {
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['error' => 'invalid_request', 'error_description' => 'POST required'], 405);
        }
        $p = $_POST;
        $clientId = (string) ($p['client_id'] ?? '');
        if ($clientId === '' && isset($_SERVER['PHP_AUTH_USER'])) {
            $clientId = (string) $_SERVER['PHP_AUTH_USER'];
        }
        [$client] = $this->client($clientId, allowFetch: false);
        if (!$client) {
            $this->json(['error' => 'invalid_client', 'error_description' => 'unknown client'], 401);
        }

        match ((string) ($p['grant_type'] ?? '')) {
            'authorization_code' => $this->exchangeCode($clientId, $p),
            'refresh_token'      => $this->refresh($clientId, $p),
            default              => $this->json(['error' => 'unsupported_grant_type'], 400),
        };
    }

    private function exchangeCode(string $clientId, array $p): never
    {
        $code = (string) ($p['code'] ?? '');
        $this->db->beginTransaction();
        $stmt = $this->db->prepare('SELECT * FROM oauth_codes WHERE code_hash = ? FOR UPDATE');
        $stmt->execute([hash('sha256', $code)]);
        $row = $stmt->fetch();

        $fail = function (string $why) {
            $this->db->rollBack();
            $this->json(['error' => 'invalid_grant', 'error_description' => $why], 400);
        };
        if (!$row || $row['used_at'] !== null || strtotime($row['expires_at']) < $this->dbNow()) {
            if ($row && $row['used_at'] !== null) {
                // Egy kódot kétszer próbáltak beváltani: az ebből kiadott tokeneket is visszavonjuk
                $this->db->prepare('UPDATE oauth_tokens SET revoked_at = NOW() WHERE user_id = ? AND client_id = ? AND created_at >= ?')
                    ->execute([$row['user_id'], $row['client_id'], $row['used_at']]);
                $this->db->commit();
                $this->json(['error' => 'invalid_grant', 'error_description' => 'code already used'], 400);
            }
            $fail('invalid or expired code');
        }
        if (!hash_equals($row['client_id'], $clientId)) {
            $fail('code was issued to another client');
        }
        if (!hash_equals($row['redirect_uri'], (string) ($p['redirect_uri'] ?? ''))) {
            $fail('redirect_uri mismatch');
        }
        $verifier = (string) ($p['code_verifier'] ?? '');
        $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier) || !hash_equals($row['code_challenge'], $expected)) {
            $fail('PKCE verification failed');
        }
        $resource = (string) ($p['resource'] ?? '');
        if ($resource !== '' && !$this->resourceMatches($resource)) {
            $fail('unknown resource');
        }

        $this->db->prepare('UPDATE oauth_codes SET used_at = NOW() WHERE code_hash = ?')->execute([$row['code_hash']]);
        $tokens = $this->issueTokens($clientId, (int) $row['user_id'], $row['scope'], $row['resource']);
        $this->db->commit();
        $this->json($tokens);
    }

    private function refresh(string $clientId, array $p): never
    {
        $refresh = (string) ($p['refresh_token'] ?? '');
        $this->db->beginTransaction();
        $stmt = $this->db->prepare('SELECT * FROM oauth_tokens WHERE refresh_hash = ? FOR UPDATE');
        $stmt->execute([hash('sha256', $refresh)]);
        $row = $stmt->fetch();

        if (!$row || !hash_equals($row['client_id'], $clientId)) {
            $this->db->rollBack();
            $this->json(['error' => 'invalid_grant', 'error_description' => 'invalid refresh token'], 400);
        }
        if ($row['revoked_at'] !== null) {
            // Egy már lecserélt frissítő token újra felbukkant: lopás gyanúja → a kapcsolat minden tokenje érvénytelen
            $this->db->prepare('UPDATE oauth_tokens SET revoked_at = NOW() WHERE user_id = ? AND client_id = ? AND revoked_at IS NULL')
                ->execute([$row['user_id'], $clientId]);
            $this->db->commit();
            $this->json(['error' => 'invalid_grant', 'error_description' => 'refresh token reuse detected'], 400);
        }
        if ($row['refresh_expires_at'] === null || strtotime($row['refresh_expires_at']) < $this->dbNow()) {
            $this->db->rollBack();
            $this->json(['error' => 'invalid_grant', 'error_description' => 'refresh token expired'], 400);
        }

        $this->db->prepare('UPDATE oauth_tokens SET revoked_at = NOW() WHERE id = ?')->execute([$row['id']]);
        $tokens = $this->issueTokens($clientId, (int) $row['user_id'], $row['scope'], $row['resource']);
        $this->db->commit();
        $this->json($tokens);
    }

    private function issueTokens(string $clientId, int $userId, string $scope, ?string $resource): array
    {
        $access = 'mcpa_'.bin2hex(random_bytes(32));
        $refresh = 'mcpr_'.bin2hex(random_bytes(32));
        $this->db->prepare(
            'INSERT INTO oauth_tokens (access_hash, refresh_hash, client_id, user_id, scope, resource, access_expires_at, refresh_expires_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW() + INTERVAL '.self::ACCESS_TTL.' SECOND, NOW() + INTERVAL '.self::REFRESH_TTL_DAYS.' DAY)'
        )->execute([hash('sha256', $access), hash('sha256', $refresh), $clientId, $userId, $scope, $resource]);

        return [
            'access_token'  => $access,
            'token_type'    => 'Bearer',
            'expires_in'    => self::ACCESS_TTL,
            'refresh_token' => $refresh,
            'scope'         => $scope,
        ];
    }

    // =====================================================================
    //  Token ellenőrzése az MCP-végponton
    // =====================================================================

    /** @return int|null a tokenhez tartozó felhasználó, vagy null, ha érvénytelen */
    public function userForAccessToken(string $token): ?int
    {
        if (!preg_match('/^mcpa_[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT user_id, resource FROM oauth_tokens WHERE access_hash = ? AND revoked_at IS NULL AND access_expires_at > NOW()'
        );
        $stmt->execute([hash('sha256', $token)]);
        $row = $stmt->fetch();
        if (!$row || ($row['resource'] !== null && !$this->resourceMatches($row['resource']))) {
            return null;
        }

        return (int) $row['user_id'];
    }

    // =====================================================================
    //  Kliensek: DCR az adatbázisból, CIMD a kliens saját dokumentumából
    // =====================================================================

    /** @return array{0: ?array, 1: ?string} [kliens (redirect_uris tömbbel), hibaüzenet] */
    private function client(string $clientId, bool $allowFetch = true): array
    {
        if ($clientId === '' || strlen($clientId) > 255) {
            return [null, null];
        }
        $stmt = $this->db->prepare('SELECT * FROM oauth_clients WHERE client_id = ?');
        $stmt->execute([$clientId]);
        $row = $stmt->fetch() ?: null;

        if (str_starts_with($clientId, 'https://') || ($this->insecureCimdAllowed() && str_starts_with($clientId, 'http://'))) {
            $stale = !$row || $row['fetched_at'] === null || strtotime($row['fetched_at']) < $this->dbNow() - self::CIMD_REFRESH_HOURS * 3600;
            if ($stale && $allowFetch) {
                [$doc, $err] = $this->fetchClientMetadata($clientId);
                if (!$doc) {
                    return [null, $err];
                }
                $this->db->prepare(
                    "INSERT INTO oauth_clients (client_id, kind, client_name, redirect_uris, fetched_at) VALUES (?, 'cimd', ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE client_name = VALUES(client_name), redirect_uris = VALUES(redirect_uris), fetched_at = NOW()"
                )->execute([$clientId, $doc['client_name'], json_encode($doc['redirect_uris'], JSON_UNESCAPED_SLASHES)]);
                $stmt->execute([$clientId]);
                $row = $stmt->fetch() ?: null;
            }
        } elseif ($row && $row['kind'] !== 'dcr') {
            $row = null;
        }

        if (!$row) {
            return [null, null];
        }
        $row['redirect_uris'] = json_decode((string) $row['redirect_uris'], true) ?: [];

        return [$row, null];
    }

    /** @return array{0: ?array, 1: ?string} */
    private function fetchClientMetadata(string $url): array
    {
        $parts = parse_url($url);
        $host = (string) ($parts['host'] ?? '');
        if ($host === '' || !empty($parts['user']) || !empty($parts['fragment'])) {
            return [null, 'Az alkalmazás azonosítója nem érvényes cím.'];
        }
        if (!$this->insecureCimdAllowed()) {
            $ips = gethostbynamel($host) ?: [];
            if ($ips === []) {
                return [null, 'Az alkalmazás címe nem érhető el.'];
            }
            foreach ($ips as $ip) {
                if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return [null, 'Az alkalmazás címe belső hálózatra mutat.'];
                }
            }
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_PROTOCOLS      => $this->insecureCimdAllowed() ? (CURLPROTO_HTTPS | CURLPROTO_HTTP) : CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_USERAGENT      => 'MCP-Adventure-OAuth/1.0',
            CURLOPT_NOPROGRESS     => false,
            CURLOPT_PROGRESSFUNCTION => fn ($c, $dlTotal, $dl) => $dl > 65536 ? 1 : 0, // legfeljebb 64 KB
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if (!is_string($raw) || $status !== 200) {
            return [null, 'Az alkalmazás adatai nem tölthetők le ('.$status.').'];
        }
        $doc = json_decode($raw, true);
        if (!is_array($doc) || ($doc['client_id'] ?? null) !== $url) {
            return [null, 'Az alkalmazás adatai hibásak.'];
        }
        $uris = array_values(array_filter((array) ($doc['redirect_uris'] ?? []), fn ($u) => is_string($u) && $this->acceptableRedirect($u)));
        if ($uris === []) {
            return [null, 'Az alkalmazás nem adott meg érvényes visszairányítási címet.'];
        }

        return [['client_name' => is_string($doc['client_name'] ?? null) ? mb_substr($doc['client_name'], 0, 255) : null, 'redirect_uris' => $uris], null];
    }

    private function insecureCimdAllowed(): bool
    {
        return Config::get('OAUTH_CIMD_ALLOW_INSECURE', '0') === '1'; // csak helyi teszteléshez!
    }

    // =====================================================================
    //  Visszairányítási címek
    // =====================================================================

    private function acceptableRedirect(string $uri): bool
    {
        $p = parse_url($uri);
        if (!$p || empty($p['scheme']) || empty($p['host']) || !empty($p['fragment'])) {
            return false;
        }

        return $p['scheme'] === 'https' || ($p['scheme'] === 'http' && $this->isLoopback($uri));
    }

    private function isLoopback(string $uri): bool
    {
        $p = parse_url($uri);

        return ($p['scheme'] ?? '') === 'http' && in_array(strtolower(trim((string) ($p['host'] ?? ''), '[]')), ['127.0.0.1', 'localhost', '::1'], true);
    }

    /** Pontos egyezés; a helyi (loopback) címeknél a port nem számít (RFC 8252). */
    private function redirectAllowed(string $requested, array $registered): bool
    {
        if ($requested === '' || !$this->acceptableRedirect($requested)) {
            return false;
        }
        foreach ($registered as $r) {
            if (hash_equals((string) $r, $requested)) {
                return true;
            }
            if ($this->isLoopback($requested) && $this->isLoopback((string) $r)) {
                $a = parse_url($requested);
                $b = parse_url((string) $r);
                if (strtolower($a['host']) === strtolower($b['host'])
                    && ($a['path'] ?? '/') === ($b['path'] ?? '/')
                    && ($a['query'] ?? '') === ($b['query'] ?? '')) {
                    return true;
                }
            }
        }

        return false;
    }

    private function resourceMatches(string $resource): bool
    {
        return rtrim($resource, '/') === $this->resource;
    }

    // =====================================================================
    //  Segédek
    // =====================================================================

    /** Az engedélyezési kérés paraméterei, amiket a bejelentkezésen és a jóváhagyáson át megőrzünk */
    private function flowParams(array $q): array
    {
        $out = [];
        foreach (['response_type', 'client_id', 'redirect_uri', 'state', 'scope', 'code_challenge', 'code_challenge_method', 'resource'] as $k) {
            if (isset($q[$k]) && is_string($q[$k]) && $q[$k] !== '') {
                $out[$k] = $q[$k];
            }
        }

        return $out;
    }

    private function redirectTo(string $uri, array $params): never
    {
        $params = array_filter($params, fn ($v) => $v !== '' && $v !== null);
        header('Location: '.$uri.(str_contains($uri, '?') ? '&' : '?').http_build_query($params), true, 302);
        exit;
    }

    private function redirectError(string $uri, string $state, string $error, string $description): never
    {
        $this->redirectTo($uri, ['error' => $error, 'error_description' => $description, 'state' => $state, 'iss' => $this->issuer]);
    }

    private function errorPage(string $title, string $html, bool $trustedHtml = false, ?string $userName = null): never
    {
        http_response_code(400);
        Page::render($title, '<p>'.($trustedHtml ? $html : Page::e($html)).'</p>', null, $userName);
        exit;
    }

    private function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function dbNow(): int
    {
        return (int) strtotime((string) $this->db->query('SELECT NOW()')->fetchColumn());
    }
}
