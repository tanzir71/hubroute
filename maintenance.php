<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo "Not found\n";
    exit;
}

function maintSafeGetEnv(string $key)
{
    if (function_exists('getenv')) {
        $value = @getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }
    }
    return $_ENV[$key] ?? false;
}

function maintLoadEnvFile(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key === '') {
            continue;
        }
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }
        if (maintSafeGetEnv($key) === false || maintSafeGetEnv($key) === '') {
            if (function_exists('putenv')) {
                @putenv($key . '=' . $value);
            }
            $_ENV[$key] = $value;
        }
    }
}

function maintEnvString(string $key, string $default): string
{
    $value = maintSafeGetEnv($key);
    return $value === false || $value === '' ? $default : (string)$value;
}

function maintEnvInt(string $key, int $default): int
{
    $value = maintSafeGetEnv($key);
    return $value === false || !preg_match('/^\d+$/', (string)$value) ? $default : max(1, (int)$value);
}

function maintEnvBool(string $key, bool $default): bool
{
    $value = maintSafeGetEnv($key);
    if ($value === false || $value === '') {
        return $default;
    }
    return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true);
}

function maintAppPath(string $path): string
{
    if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/])/', $path)) {
        return $path;
    }
    return __DIR__ . DIRECTORY_SEPARATOR . $path;
}

function maintWriteDenyFiles(string $dir): void
{
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $deny = $dir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($deny)) {
        @file_put_contents($deny, "Require all denied\nDeny from all\n");
    }

    $index = $dir . DIRECTORY_SEPARATOR . 'index.html';
    if (!is_file($index)) {
        @file_put_contents($index, '');
    }
}

function maintOption(array $argv, string $name): ?string
{
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (str_starts_with($arg, $prefix)) {
            return substr($arg, strlen($prefix));
        }
    }
    return null;
}

function maintDays(array $argv, string $optionName, int $default): int
{
    $value = maintOption($argv, $optionName);
    if ($value === null || !preg_match('/^\d+$/', $value)) {
        return $default;
    }
    return max(1, (int)$value);
}

function maintPdo(string $dbPath): PDO
{
    if (!is_file($dbPath)) {
        throw new RuntimeException('SQLite database does not exist yet: ' . $dbPath);
    }
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('PDO SQLite is not available.');
    }

    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('PRAGMA foreign_keys=ON');
    $pdo->exec('PRAGMA busy_timeout=30000');
    return $pdo;
}

function maintBackupSqlite(PDO $pdo, string $dbPath, string $backupDir): string
{
    if (!extension_loaded('sqlite3')) {
        throw new RuntimeException('SQLite3 extension is required for safe online backups.');
    }
    maintWriteDenyFiles($backupDir);
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');

    $backupPath = $backupDir . DIRECTORY_SEPARATOR . 'hubroute-' . gmdate('Ymd-His') . '.sqlite';
    $source = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
    $destination = new SQLite3($backupPath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    $ok = $source->backup($destination);
    $source->close();
    $destination->close();
    if (!$ok) {
        throw new RuntimeException('SQLite backup failed.');
    }
    @chmod($backupPath, 0600);
    return $backupPath;
}

function maintDeleteOldBackups(string $backupDir, int $keepDays): int
{
    if (!is_dir($backupDir)) {
        return 0;
    }
    $cutoff = time() - ($keepDays * 86400);
    $deleted = 0;
    foreach (glob($backupDir . DIRECTORY_SEPARATOR . 'hubroute-*.sqlite') ?: [] as $file) {
        if (is_file($file) && (int)filemtime($file) < $cutoff) {
            @unlink($file);
            $deleted++;
        }
    }
    return $deleted;
}

function maintScalar(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function maintDelete(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

function maintPrune(PDO $pdo, array $settings, bool $apply): array
{
    $terminalCutoff = gmdate('c', time() - ($settings['terminal_days'] * 86400));
    $auditCutoff = gmdate('c', time() - ($settings['audit_days'] * 86400));
    $idempotencyCutoff = gmdate('c', time() - ($settings['idempotency_days'] * 86400));
    $rateLimitCutoff = time() - ($settings['rate_limit_days'] * 86400);

    $terminalWhere = "status IN ('delivered','failed','returned') AND updated_at < ?";
    $counts = [
        'terminal_parcels' => maintScalar($pdo, "SELECT COUNT(*) FROM parcels WHERE $terminalWhere", [$terminalCutoff]),
        'terminal_events' => maintScalar($pdo, "SELECT COUNT(*) FROM events WHERE parcel_id IN (SELECT id FROM parcels WHERE $terminalWhere)", [$terminalCutoff]),
        'audit_log' => maintScalar($pdo, 'SELECT COUNT(*) FROM audit_log WHERE created_at < ?', [$auditCutoff]),
        'idempotency_keys' => maintScalar($pdo, 'SELECT COUNT(*) FROM idempotency_keys WHERE created_at < ?', [$idempotencyCutoff]),
        'rate_limits' => maintScalar($pdo, 'SELECT COUNT(*) FROM rate_limits WHERE last_attempt < ?', [$rateLimitCutoff]),
    ];

    if (!$apply) {
        return ['mode' => 'dry-run', 'deleted' => array_fill_keys(array_keys($counts), 0), 'matched' => $counts];
    }

    $deleted = [];
    $pdo->beginTransaction();
    try {
        $deleted['terminal_events'] = maintDelete($pdo, "DELETE FROM events WHERE parcel_id IN (SELECT id FROM parcels WHERE $terminalWhere)", [$terminalCutoff]);
        $deleted['terminal_parcels'] = maintDelete($pdo, "DELETE FROM parcels WHERE $terminalWhere", [$terminalCutoff]);
        $deleted['audit_log'] = maintDelete($pdo, 'DELETE FROM audit_log WHERE created_at < ?', [$auditCutoff]);
        $deleted['idempotency_keys'] = maintDelete($pdo, 'DELETE FROM idempotency_keys WHERE created_at < ?', [$idempotencyCutoff]);
        $deleted['rate_limits'] = maintDelete($pdo, 'DELETE FROM rate_limits WHERE last_attempt < ?', [$rateLimitCutoff]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return ['mode' => 'apply', 'deleted' => $deleted, 'matched' => $counts];
}

function maintUsage(): void
{
    echo "HubRoute maintenance\n";
    echo "\n";
    echo "Usage:\n";
    echo "  php maintenance.php backup\n";
    echo "  php maintenance.php prune [--apply]\n";
    echo "  php maintenance.php run --apply\n";
    echo "\n";
    echo "Options:\n";
    echo "  --terminal-days=N     Keep terminal parcels newer than N days\n";
    echo "  --audit-days=N        Keep audit rows newer than N days\n";
    echo "  --idempotency-days=N  Keep idempotency rows newer than N days\n";
    echo "  --rate-limit-days=N   Keep rate-limit rows newer than N days\n";
    echo "  --backup-days=N       Keep backup files newer than N days\n";
    echo "  --apply               Required to delete rows during prune/run\n";
}

maintLoadEnvFile(__DIR__ . DIRECTORY_SEPARATOR . '.env');

$dataDir = maintAppPath(maintEnvString('DATA_DIR', 'data'));
$dbPath = maintAppPath(maintEnvString('DB_PATH', $dataDir . DIRECTORY_SEPARATOR . 'hubroute.sqlite'));
$backupDir = maintAppPath(maintEnvString('BACKUP_DIR', $dataDir . DIRECTORY_SEPARATOR . 'backups'));
$settings = [
    'terminal_days' => maintDays($argv, 'terminal-days', maintEnvInt('MAINTENANCE_PRUNE_TERMINAL_DAYS', 180)),
    'audit_days' => maintDays($argv, 'audit-days', maintEnvInt('MAINTENANCE_PRUNE_AUDIT_DAYS', 365)),
    'idempotency_days' => maintDays($argv, 'idempotency-days', maintEnvInt('MAINTENANCE_PRUNE_IDEMPOTENCY_DAYS', 14)),
    'rate_limit_days' => maintDays($argv, 'rate-limit-days', maintEnvInt('MAINTENANCE_PRUNE_RATE_LIMIT_DAYS', 7)),
    'backup_days' => maintDays($argv, 'backup-days', maintEnvInt('MAINTENANCE_BACKUP_KEEP_DAYS', 30)),
];
$vacuumAfterPrune = maintEnvBool('MAINTENANCE_VACUUM_AFTER_PRUNE', true);
$command = $argv[1] ?? 'help';
$apply = in_array('--apply', $argv, true);

try {
    if ($command === 'help' || $command === '--help' || $command === '-h') {
        maintUsage();
        exit(0);
    }

    $pdo = maintPdo($dbPath);
    $didPrune = false;

    if ($command === 'backup' || $command === 'run' || ($command === 'prune' && $apply)) {
        $backupPath = maintBackupSqlite($pdo, $dbPath, $backupDir);
        echo 'backup=' . $backupPath . "\n";
        echo 'old_backups_deleted=' . maintDeleteOldBackups($backupDir, $settings['backup_days']) . "\n";
    }

    if ($command === 'prune' || $command === 'run') {
        $result = maintPrune($pdo, $settings, $apply);
        echo 'prune_mode=' . $result['mode'] . "\n";
        foreach ($settings as $key => $value) {
            echo 'setting_' . $key . '=' . $value . "\n";
        }
        foreach ($result['matched'] as $key => $value) {
            echo 'matched_' . $key . '=' . $value . "\n";
        }
        foreach ($result['deleted'] as $key => $value) {
            echo 'deleted_' . $key . '=' . $value . "\n";
            $didPrune = $didPrune || $value > 0;
        }
        if ($apply && $didPrune && $vacuumAfterPrune) {
            $pdo->exec('VACUUM');
            echo "vacuum=done\n";
        }
        exit(0);
    }

    if ($command === 'backup') {
        exit(0);
    }

    maintUsage();
    exit(2);
} catch (Throwable $e) {
    fwrite(STDERR, 'maintenance_error=' . $e->getMessage() . "\n");
    exit(1);
}
