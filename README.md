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

Leállítás: `docker compose down`
Adatbázis nullázása (az init SQL újrafut): `docker compose down -v`

## Mappaszerkezet

- `public/` – a webszerver gyökere (Apache DocumentRoot)
- `src/` – a játékmotor és az MCP-szerver PHP-kódja
- `db/init/` – adatbázis-séma és kezdőadatok (első induláskor fut)
- `assets/images/` – előre generált helyszínképek
- `docker/` – a konténerek konfigurációja
