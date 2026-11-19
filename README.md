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

## MCP-szerver

Végpont: `http://localhost:8100/mcp` (ugyanaz: `http://localhost:8100/mcp.php`)

Eszközök (tools):

| Eszköz | Mit csinál |
|---|---|
| `korulnez` | leírja a helyszínt, a kijáratokat és a tárgyakat |
| `menj` | mozgás egy irányba (`irany`: eszak, del, kelet, nyugat, fel, le, be, ki…) |
| `allapot` | pénz, éhség, fáradtság, egészség, idő, leltár |
| `uj_jatek` | a játékos visszaáll a kezdőállapotba |

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
