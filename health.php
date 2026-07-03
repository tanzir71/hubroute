<?php
declare(strict_types=1);

$root = __DIR__;
$dataDir = $root . DIRECTORY_SEPARATOR . 'data';
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0775, true);
}

$checks = [];
$checks[] = ['PHP version', PHP_VERSION, version_compare(PHP_VERSION, '8.1.0', '>=')];
$checks[] = ['PDO extension', class_exists('PDO') ? 'available' : 'missing', class_exists('PDO')];

$drivers = [];
if (class_exists('PDO')) {
    try {
        $drivers = PDO::getAvailableDrivers();
    } catch (Throwable $e) {
        $drivers = [];
    }
}
$checks[] = ['PDO SQLite driver', in_array('sqlite', $drivers, true) ? 'available' : 'missing', in_array('sqlite', $drivers, true)];
$checks[] = ['sqlite3 extension', extension_loaded('sqlite3') ? 'available' : 'missing', extension_loaded('sqlite3')];
$checks[] = ['data directory exists', is_dir($dataDir) ? 'yes' : 'no', is_dir($dataDir)];
$checks[] = ['data directory writable', is_writable($dataDir) ? 'yes' : 'no', is_writable($dataDir)];

$writePath = $dataDir . DIRECTORY_SEPARATOR . 'health-write-test.txt';
$writeOk = @file_put_contents($writePath, 'ok') !== false;
if ($writeOk) {
    @unlink($writePath);
}
$checks[] = ['write test', $writeOk ? 'ok' : 'failed', $writeOk];

$dbOk = false;
$dbMessage = 'not tested';
if (class_exists('PDO') && in_array('sqlite', $drivers, true) && $writeOk) {
    try {
        $pdo = new PDO('sqlite:' . $dataDir . DIRECTORY_SEPARATOR . 'health.sqlite');
        $pdo->exec('CREATE TABLE IF NOT EXISTS health (id INTEGER PRIMARY KEY, value TEXT)');
        $pdo->exec("INSERT INTO health (value) VALUES ('ok')");
        $dbOk = true;
        $dbMessage = 'ok';
    } catch (Throwable $e) {
        $dbMessage = $e->getMessage();
    }
}
$checks[] = ['SQLite open/write', $dbMessage, $dbOk];

http_response_code(200);
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>HubRoute Health</title>
  <style>
    body{font:14px/1.5 system-ui,-apple-system,Segoe UI,Arial,sans-serif;margin:24px;color:#111;background:#fff}
    main{max-width:760px;margin:0 auto}
    table{width:100%;border-collapse:collapse;margin-top:16px}
    th,td{border-bottom:1px solid #ddd;padding:10px;text-align:left;vertical-align:top}
    .ok{color:#116b39;font-weight:700}.bad{color:#9b1c1c;font-weight:700}
    a{color:#111}
  </style>
</head>
<body>
<main>
  <h1>HubRoute Health</h1>
  <p>Use this page only to verify the shared-hosting demo runtime.</p>
  <table>
    <thead><tr><th>Check</th><th>Result</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($checks as $check): ?>
      <tr>
        <td><?= htmlspecialchars((string)$check[0], ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars((string)$check[1], ENT_QUOTES, 'UTF-8') ?></td>
        <td class="<?= $check[2] ? 'ok' : 'bad' ?>"><?= $check[2] ? 'OK' : 'Fix' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p><a href="demo.php?r=login&amp;demo=hub">Open demo login</a></p>
</main>
</body>
</html>
