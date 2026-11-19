<?php
declare(strict_types=1);

$status = 'OK';
$locations = [];

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST'), getenv('DB_NAME')),
        getenv('DB_USER'),
        getenv('DB_PASS'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $locations = $pdo->query('SELECT id, name, description FROM locations')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $status = 'HIBA: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
<meta charset="utf-8">
<title>Adventure Game – fejlesztői környezet</title>
<style>
  body { background:#3e31a2; color:#a5a5ff; font-family: "Courier New", monospace; padding:2rem; }
  .box { border:4px solid #a5a5ff; padding:1.5rem; max-width:760px; }
  h1 { font-size:1.3rem; text-transform:uppercase; }
  .ok { color:#9fff9f; } .err { color:#ff9f9f; }
</style>
</head>
<body>
<div class="box">
  <h1>**** ADVENTURE GAME ****</h1>
  <p>PHP <?= htmlspecialchars(PHP_VERSION) ?> &middot; Adatbázis:
     <span class="<?= $status === 'OK' ? 'ok' : 'err' ?>"><?= htmlspecialchars($status) ?></span></p>
  <?php foreach ($locations as $loc): ?>
    <h2><?= htmlspecialchars($loc['name']) ?></h2>
    <p><?= htmlspecialchars($loc['description']) ?></p>
  <?php endforeach; ?>
  <p>READY.</p>
</div>
</body>
</html>
