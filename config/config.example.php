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

    // Az MCP-végpontot elérő host-nevek, vesszővel elválasztva – a domained
    'MCP_ALLOWED_HOSTS' => 'kalandjatek.hu,www.kalandjatek.hu',

    // Prototípus: melyik játékos nevében fut az MCP-szerver
    'PLAYER_ID' => '1',
];
