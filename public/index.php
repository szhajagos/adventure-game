<?php
declare(strict_types=1);

use AdventureGame\Config;
use AdventureGame\Database;

$status = 'OK';
$games = [];

try {
    require dirname(__DIR__).'/vendor/autoload.php';
    $games = Database::connect()
        ->query('SELECT title, description FROM games WHERE active = 1 ORDER BY sort_order, title')
        ->fetchAll();
} catch (Throwable $e) {
    // Élesben ne áruljuk el a részleteket (szervernév, felhasználó…)
    $debug = class_exists(Config::class) && Config::get('APP_DEBUG', '0') === '1';
    $status = 'HIBA' . ($debug ? ': ' . $e->getMessage() : '');
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MCP Adventure</title>
<style>
  body { background:#3e31a2; color:#a5a5ff; font-family: "Courier New", monospace; padding:2rem; }
  .box { border:4px solid #a5a5ff; padding:1.5rem; max-width:760px; }
  h1 { font-size:1.3rem; text-transform:uppercase; }
  h2 { font-size:1.05rem; color:#fff; margin-bottom:.3rem; }
  .ok { color:#9fff9f; } .err { color:#ff9f9f; }
</style>
</head>
<body>
<div class="box">
  <h1>**** MCP ADVENTURE ****</h1>
  <p>Szöveges kalandjátékok a saját AI-asszisztensedhez.</p>
  <p>Rendszer: <span class="<?= $status === 'OK' ? 'ok' : 'err' ?>"><?= htmlspecialchars($status) ?></span></p>
  <?php foreach ($games as $g): ?>
    <h2><?= htmlspecialchars($g['title']) ?></h2>
    <p><?= htmlspecialchars($g['description']) ?></p>
  <?php endforeach; ?>
  <p>READY.</p>
</div>
</body>
</html>
