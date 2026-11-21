<?php

/*
 * Webtárhelyes beállítások.
 *
 * Másold le config.php néven ebbe a mappába, és írd be a tárhely adatait.
 * A config.php NEM kerül fel a GitHubra (.gitignore), és a .htaccess
 * megtiltja, hogy böngészőből letölthető legyen.
 *
 * Dockerben nincs rá szükség: ott a docker-compose.yml környezeti változói
 * az irányadók, és azok felülírják az itteni értékeket.
 */

return [
    // Adatbázis (a tárhely vezérlőpultjában találod)
    'DB_HOST' => 'localhost',
    'DB_NAME' => 'adatbazis_neve',
    'DB_USER' => 'felhasznalo',
    'DB_PASS' => 'jelszo',

    // A játék nyilvános címe (a képek URL-jéhez). Üresen hagyva a kérés címéből számolja.
    'APP_URL' => 'https://play.mcpadventure.com',

    // Az MCP-végpontot elérő host-nevek, vesszővel elválasztva – a domained
    'MCP_ALLOWED_HOSTS' => 'play.mcpadventure.com',

    // Titkos tesztkulcs: https://play.mcpadventure.com/mcp/<kulcs> bejelentkezés nélkül a PLAYER_ID
    // tesztjátékosként játszik (fejlesztéshez). Ez a kulcs kell az első admin létrehozásához is.
    // Csak betűk, számok, kötőjel és aláhúzás.
    'MCP_ACCESS_KEY' => '',

    // Prototípus: melyik játékos nevében fut az MCP-szerver
    'PLAYER_ID' => '1',

    // Levelek feladója (regisztráció megerősítése, elfelejtett jelszó)
    'MAIL_FROM'      => 'noreply@mcpadventure.com',
    'MAIL_FROM_NAME' => 'MCP Adventure',

    // Csak megerősített e-mail-címmel lehet az AI-t a fiókhoz kapcsolni ('1' = igen)
    'REQUIRE_EMAIL_VERIFICATION' => '1',

    // Hibák részletes kiírása a tesztoldalon (élesben maradjon '0')
    'APP_DEBUG' => '0',
];
