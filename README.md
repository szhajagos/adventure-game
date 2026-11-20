# Adventure Game (munkacím)

AI-vezérelt szöveges kalandjáték. A játékmotor PHP + MariaDB, a játékos a saját
Claude- vagy ChatGPT-fiókjából játszik, a játék MCP-szerverként csatlakozik.

## Fejlesztői környezet indítása

Előfeltétel: Docker Desktop.

```bash
docker compose up -d --build
```

| Szolgáltatás | Cím |
|---|---|
| Játék (tesztoldal) | http://localhost:8100 |
| Adminer (adatbázis-kezelő) | http://localhost:8101 – szerver: `db`, felhasználó/jelszó: `adventure` / `adventure` |

A portok és jelszavak a `.env.example` alapján egy `.env` fájlban módosíthatók.

Első indításkor (és ha a `composer.json` változik) a PHP-csomagokat telepíteni kell:

```bash
docker compose exec web composer install
```

Leállítás: `docker compose down`
Adatbázis nullázása (az init SQL újrafut): `docker compose down -v`

## Mappaszerkezet

- `public/` – a webszerver gyökere (Apache DocumentRoot)
- `src/` – a játékmotor és az MCP-szerver PHP-kódja
- `db/init/` – adatbázis-séma és kezdőadatok (első induláskor fut)
- `assets/images/` – előre generált helyszínképek
- `docker/` – a konténerek konfigurációja
- `config/` – webtárhelyes beállítások (`config.php`, mintája: `config.example.php`)

## MCP-szerver

Végpont: `http://localhost:8100/mcp` (ugyanaz: `http://localhost:8100/mcp.php`)

Eszközök (tools):

| Eszköz | Mit csinál |
|---|---|
| `jatekok` | az elérhető játékok listája, a saját állásokkal |
| `jatek_inditasa` | játék indítása vagy folytatása (`jatek`: azonosító vagy cím) |
| `korulnez` | leírja a helyszínt, a kijáratokat és a tárgyakat |
| `menj` | mozgás egy irányba (`irany`: eszak, del, kelet, nyugat, fel, le, be, ki…) |
| `allapot` | pénz, éhség, fáradtság, egészség, idő, leltár |
| `uj_jatek` | az aktuális játék újrakezdése (a többi játék állása megmarad) |

Tesztelés MCP Inspectorral (Node.js kell hozzá):

```bash
npx @modelcontextprotocol/inspector
```

A megnyíló felületen: Transport Type = **Streamable HTTP**, URL = `http://localhost:8100/mcp`.
Vagy közvetlenül: `npx @modelcontextprotocol/inspector --server-url http://localhost:8100/mcp`

## Játék a saját Claude-ból (Cloudflare-alagúttal)

```bash
docker compose --profile tunnel up -d
docker compose logs cloudflared
```

A naplóban megjelenik egy `https://valami-nev.trycloudflare.com` cím. A Claude-ban
(Beállítások → Connectors → Add custom connector) ezt add meg, a végére `/mcp`-vel:
`https://valami-nev.trycloudflare.com/mcp`

Az alagút címe minden indításkor új. Leállítás: `docker compose stop cloudflared`

Figyelem: amíg az alagút fut, a cím ismeretében bárki elérheti a tesztjátékost
(bejelentkezés még nincs). Ha nem játszol, állítsd le.

## Telepítés webtárhelyre (kötött public_html)

A projekt úgy készült, hogy akkor is biztonságosan fusson, ha a tárhelyen
a webgyökér nem állítható át, és minden a `public_html`-be kerül.

Követelmények: PHP 8.2+ (`pdo_mysql`), MariaDB/MySQL, Apache 2.4 + `mod_rewrite`, HTTPS.

1. Itthon, a Dockerben a csomagok:
   `docker compose exec web composer install --no-dev --optimize-autoloader`
2. Csomag készítése a projektmappában (Windows PowerShell):
   `tar -a -c -f deploy.zip --exclude config/config.php .htaccess public src vendor config assets`
3. cPanel → File Manager → az aldomain mappája: töltsd fel a `deploy.zip`-et, majd **Extract**, végül töröld a zipet.
4. A `config/config.example.php` alapján hozd létre a `config/config.php`-t a tárhely adataival
   és egy titkos `MCP_ACCESS_KEY` kulccsal.
5. Az adatbázist a tárhely phpMyAdminjában töltsd fel: `db/init/*.sql`, sorrendben (001, 002, 003…).
6. A Claude-ban a connector címe: `https://play.mcpadventure.com/mcp/<MCP_ACCESS_KEY>`, hitelesítés: **No sign-in**.

Titkos kulcs készítése (PowerShell):

```powershell
$b = New-Object byte[] 24; [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($b); -join ($b | % { $_.ToString('x2') })
```

A `.htaccess` védelme:

| Mi | Elérhető? |
|---|---|
| `/`, `/mcp`, a `public/` tartalma | igen |
| `assets/images/` képei (png, jpg, webp, gif) | igen |
| `src/`, `vendor/`, `config/`, `db/`, `docker/`, `.git/` | nem (403/404) |
| rejtett fájlok (`.env`, `.htaccess`…), `composer.json/lock`, `*.sql`, `*.md`, `*.yml`… | nem (403) |
| könyvtárlista | nem |

Az `Authorization` fejlécet (későbbi bejelentkezéshez) a `.htaccess` továbbadja a PHP-nak.
A Let's Encrypt tanúsítvány-ellenőrzés (`/.well-known/acme-challenge/`) érintetlen marad.

### Kipróbálás itthon: tárhely-szimuláció

```bash
docker compose --profile tarhely up -d --build
```

A http://localhost:8102 úgy viselkedik, mint a tárhely: a projektgyökér a webgyökér,
és a `.htaccess` fájlok érvényesek. Próbáld ki, hogy pl. a
http://localhost:8102/composer.json vagy a http://localhost:8102/src/Config.php tiltott,
a http://localhost:8102/mcp pedig működik.
