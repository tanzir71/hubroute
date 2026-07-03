<?php
declare(strict_types=1);

function safeIniSet(string $option, string $value): void
{
    if (function_exists('ini_set')) {
        @ini_set($option, $value);
    }
}

function safeGetEnv(string $key)
{
    if (function_exists('getenv')) {
        $value = @getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }
    }
    return $_ENV[$key] ?? false;
}

function loadEnvFile(string $path): void
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
        if (safeGetEnv($key) === false || safeGetEnv($key) === '') {
            if (function_exists('putenv')) {
                @putenv($key . '=' . $value);
            }
            $_ENV[$key] = $value;
        }
    }
}

function envString(string $key, string $default): string
{
    $value = safeGetEnv($key);
    return $value === false || $value === '' ? $default : (string)$value;
}

function envInt(string $key, int $default): int
{
    $value = safeGetEnv($key);
    return $value === false || !preg_match('/^\d+$/', (string)$value) ? $default : (int)$value;
}

function envBool(string $key, bool $default): bool
{
    $value = safeGetEnv($key);
    if ($value === false || $value === '') {
        return $default;
    }
    return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true);
}

function appPath(string $path): string
{
    if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/])/', $path)) {
        return $path;
    }
    return __DIR__ . DIRECTORY_SEPARATOR . $path;
}

loadEnvFile(__DIR__ . DIRECTORY_SEPARATOR . '.env');

define('APP_NAME', envString('APP_NAME', 'HubRoute'));
define('DATA_DIR', appPath(envString('DATA_DIR', 'data')));
define('DB_PATH', appPath(envString('DB_PATH', DATA_DIR . DIRECTORY_SEPARATOR . 'hubroute.sqlite')));
define('APP_TIMEZONE', envString('APP_TIMEZONE', 'Asia/Dhaka'));
define('SESSION_IDLE_TIMEOUT_SECONDS', envInt('SESSION_IDLE_TIMEOUT_SECONDS', 1800));
define('SESSION_ABSOLUTE_TIMEOUT_SECONDS', envInt('SESSION_ABSOLUTE_TIMEOUT_SECONDS', 28800));
define('RATE_LIMIT_LOGIN_ATTEMPTS', envInt('RATE_LIMIT_LOGIN_ATTEMPTS', 5));
define('RATE_LIMIT_LOGIN_WINDOW_SECONDS', envInt('RATE_LIMIT_LOGIN_WINDOW_SECONDS', 900));
define('RATE_LIMIT_ACTION_ATTEMPTS', envInt('RATE_LIMIT_ACTION_ATTEMPTS', 60));
define('RATE_LIMIT_ACTION_WINDOW_SECONDS', envInt('RATE_LIMIT_ACTION_WINDOW_SECONDS', 300));
define('ENABLE_EXTRA_LOGGING', envBool('ENABLE_EXTRA_LOGGING', false));

if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0775, true);
}
$dbDir = dirname(DB_PATH);
if (!is_dir($dbDir)) {
    @mkdir($dbDir, 0775, true);
}

safeIniSet('display_errors', '0');
safeIniSet('log_errors', '1');
safeIniSet('error_log', DATA_DIR . DIRECTORY_SEPARATOR . 'php-error.log');
error_reporting(E_ALL);

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

date_default_timezone_set(APP_TIMEZONE);

class UserSafeException extends RuntimeException
{
}

function sendSecurityHeaders(): void
{
    if (headers_sent()) {
        return;
    }

    // Customize CSP here if you add external assets, scripts, or API endpoints.
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

function destroySessionOnly(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function enforceSessionTimeout(): void
{
    $now = time();
    $_SESSION['created_at'] = (int)($_SESSION['created_at'] ?? $now);
    $_SESSION['last_activity'] = (int)($_SESSION['last_activity'] ?? $now);

    if (!empty($_SESSION['uid'])) {
        $idleExpired = ($now - (int)$_SESSION['last_activity']) > SESSION_IDLE_TIMEOUT_SECONDS;
        $absoluteExpired = ($now - (int)$_SESSION['created_at']) > SESSION_ABSOLUTE_TIMEOUT_SECONDS;
        if ($idleExpired || $absoluteExpired) {
            destroySessionOnly();
            header('Location: ?r=login&timeout=1');
            exit;
        }
    }

    $_SESSION['last_activity'] = $now;
}

sendSecurityHeaders();
enforceSessionTimeout();

function htmlEscape(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function e(string $s): string
{
    return htmlEscape($s);
}

function logAppError(Throwable $e, array $context = []): void
{
    error_log((string)$e);
    if (ENABLE_EXTRA_LOGGING && $context) {
        error_log('context=' . json_encode($context, JSON_UNESCAPED_SLASHES));
    }
}

function hrLower(string $s): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

function hrPos(string $haystack, string $needle)
{
    return function_exists('mb_strpos') ? mb_strpos($haystack, $needle, 0, 'UTF-8') : strpos($haystack, $needle);
}

function hrLen(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

function hrSub(string $s, int $start, ?int $length = null): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($s, $start, $length, 'UTF-8');
    }
    return $length === null ? substr($s, $start) : substr($s, $start, $length);
}

function boundedText(string $value, int $maxLength): string
{
    $value = trim(preg_replace('/[[:cntrl:]]+/u', ' ', $value) ?? $value);
    if (hrLen($value) > $maxLength) {
        $value = hrSub($value, 0, $maxLength);
    }
    return $value;
}

function postText(string $key, int $maxLength = 255): string
{
    return boundedText((string)($_POST[$key] ?? ''), $maxLength);
}

function queryText(string $key, int $maxLength = 255): string
{
    return boundedText((string)($_GET[$key] ?? ''), $maxLength);
}

function nullablePositiveInt(string $value): ?int
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if (!ctype_digit($value) || (int)$value <= 0) {
        throw new UserSafeException('Invalid numeric identifier');
    }
    return (int)$value;
}

function postPositiveInt(string $key): int
{
    $value = nullablePositiveInt((string)($_POST[$key] ?? ''));
    if ($value === null) {
        throw new UserSafeException('Missing numeric identifier');
    }
    return $value;
}

function parseOptionalFloat(string $value, float $min, float $max): ?float
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        throw new UserSafeException('Invalid numeric value');
    }
    $float = (float)$value;
    if ($float < $min || $float > $max) {
        throw new UserSafeException('Numeric value is outside the allowed range');
    }
    return $float;
}

function parseMoneyCents(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $value)) {
        throw new UserSafeException('Amount must be a positive currency value');
    }
    return (int)round(((float)$value) * 100);
}

function validEmail(string $email): string
{
    $email = boundedText($email, 254);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new UserSafeException('Invalid email address');
    }
    return $email;
}

function parseCsvKeywords(string $raw, int $maxItems = 30, int $maxItemLength = 80): array
{
    $keywords = [];
    foreach (explode(',', $raw) as $item) {
        $item = boundedText($item, $maxItemLength);
        if ($item !== '') {
            $keywords[] = $item;
        }
        if (count($keywords) >= $maxItems) {
            break;
        }
    }
    return array_values(array_unique($keywords));
}

function allowedStatuses(): array
{
    return ['requested','assigned','en_route','picked_up','in_warehouse','out_for_delivery','delivered','failed','returned'];
}

function cleanStatusFilter(string $status): string
{
    return in_array($status, allowedStatuses(), true) ? $status : '';
}

function allowedEventTypes(): array
{
    return ['hub_arrived','hub_departed','in_warehouse','out_for_delivery','returned','failed','note_only','payment_collected','delivered','picked_up','en_route'];
}

function idempotentOperations(): array
{
    return ['customer_create','customer_confirm','hub_assign','hub_create_route','record_event','agent_step','settle','admin_create_hub','admin_create_agent','admin_create_customer','admin_create_user','admin_update_user'];
}

function nowIso(): string
{
    return gmdate('c');
}

function formatMoney(int $cents): string
{
    $sign = $cents < 0 ? '-' : '';
    $abs = abs($cents);
    $dollars = intdiv($abs, 100);
    $rem = $abs % 100;
    return $sign . '$' . number_format((float)($dollars . '.' . str_pad((string)$rem, 2, '0', STR_PAD_LEFT)), 2, '.', ',');
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION['csrf'];
}

function csrfCheck(): void
{
    $t = (string)($_POST['csrf'] ?? '');
    if ($t === '' || !hash_equals((string)($_SESSION['csrf'] ?? ''), $t)) {
        http_response_code(400);
        echo 'Bad CSRF token';
        exit;
    }
}

function newIdempotencyKey(): string
{
    return bin2hex(random_bytes(16));
}

function idempotencyInput(): string
{
    return '<input type="hidden" name="idempotency_key" value="' . e(newIdempotencyKey()) . '">';
}

function postIdempotencyKey(): string
{
    $key = boundedText((string)($_POST['idempotency_key'] ?? ''), 128);
    if (!preg_match('/^[A-Za-z0-9._:-]{12,128}$/', $key)) {
        throw new UserSafeException('Invalid idempotency key');
    }
    return $key;
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function takeFlash(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($f) ? $f : [];
}

function redirect(string $r, array $params = []): void
{
    $q = http_build_query(array_merge(['r' => $r], $params));
    header('Location: ?' . $q);
    exit;
}

function installDataDirectoryDenyFiles(): void
{
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0775, true);
    }

    $deny = DATA_DIR . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($deny)) {
        @file_put_contents($deny, "Require all denied\nDeny from all\n");
    }

    $index = DATA_DIR . DIRECTORY_SEPARATOR . 'index.html';
    if (!is_file($index)) {
        @file_put_contents($index, '');
    }
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0775, true);
    }
    $dbDir = dirname(DB_PATH);
    if (!is_dir($dbDir)) {
        @mkdir($dbDir, 0775, true);
    }
    installDataDirectoryDenyFiles();

    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('SQLite driver not available. Enable PDO SQLite (pdo_sqlite) and SQLite3 extensions.');
    }

    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("PRAGMA journal_mode=WAL");
    $pdo->exec("PRAGMA synchronous=NORMAL");
    $pdo->exec("PRAGMA foreign_keys=ON");
    $pdo->exec("PRAGMA busy_timeout=3000");
    migrateAndSeed($pdo);
    return $pdo;
}

function renderFatal(string $title, string $message): void
{
    http_response_code(500);
    $safeTitle = e($title);
    $safeMsg = nl2br(e($message));
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . $safeTitle . '</title>'
        . '<style>body{font-family:Inter,ui-sans-serif,system-ui,Segoe UI,Arial,sans-serif;background:#fff;color:#080808;margin:0;padding:24px}.card{max-width:820px;margin:0 auto;background:#fff;border:1px solid #d8d8d8;border-radius:8px;padding:18px}.h1{font-size:20px;font-weight:700;margin:0 0 10px}.muted{color:#666}</style>'
        . '</head><body><div class="card"><div class="h1">' . $safeTitle . '</div><div>' . $safeMsg . '</div>'
        . '<div class="muted" style="margin-top:12px">Check server error log: <code>' . e(DATA_DIR . DIRECTORY_SEPARATOR . 'php-error.log') . '</code></div>'
        . '</div></body></html>';
}

function migrateAndSeed(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS hubs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        type TEXT NOT NULL CHECK (type IN ('pickup','warehouse','lastmile')),
        address TEXT,
        city TEXT,
        coverage_keywords TEXT NOT NULL DEFAULT '[]',
        auto_assign INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agents (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        hub_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        phone TEXT,
        role TEXT NOT NULL CHECK (role IN ('pickup','delivery','both')),
        active INTEGER NOT NULL DEFAULT 1,
        current_status TEXT NOT NULL DEFAULT 'idle' CHECK (current_status IN ('idle','assigned','enroute','busy')),
        last_seen_ts TEXT,
        created_at TEXT NOT NULL,
        FOREIGN KEY (hub_id) REFERENCES hubs(id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS customers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        api_key TEXT,
        created_at TEXT NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL CHECK (role IN ('admin','hub','agent','customer')),
        hub_id INTEGER,
        agent_id INTEGER,
        customer_id INTEGER,
        active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL,
        FOREIGN KEY (hub_id) REFERENCES hubs(id),
        FOREIGN KEY (agent_id) REFERENCES agents(id),
        FOREIGN KEY (customer_id) REFERENCES customers(id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS routes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        hub_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        match_keywords TEXT NOT NULL DEFAULT '[]',
        assigned_agent_id INTEGER,
        active INTEGER NOT NULL DEFAULT 1,
        center_lat REAL,
        center_lng REAL,
        radius_km REAL,
        created_at TEXT NOT NULL,
        FOREIGN KEY (hub_id) REFERENCES hubs(id),
        FOREIGN KEY (assigned_agent_id) REFERENCES agents(id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS parcels (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        customer_id INTEGER NOT NULL,
        hub_pickup_id INTEGER,
        hub_warehouse_id INTEGER,
        hub_delivery_id INTEGER,
        current_hub_id INTEGER,
        pickup_address TEXT NOT NULL,
        pickup_lat REAL,
        pickup_lng REAL,
        dropoff_address TEXT NOT NULL,
        dropoff_lat REAL,
        dropoff_lng REAL,
        amount_cents INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL CHECK (status IN ('requested','assigned','en_route','picked_up','in_warehouse','out_for_delivery','delivered','failed','returned')),
        assigned_agent_id INTEGER,
        route_id INTEGER,
        tracking_code TEXT NOT NULL UNIQUE,
        metadata TEXT NOT NULL DEFAULT '{}',
        cod_settled INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        FOREIGN KEY (customer_id) REFERENCES customers(id),
        FOREIGN KEY (hub_pickup_id) REFERENCES hubs(id),
        FOREIGN KEY (hub_warehouse_id) REFERENCES hubs(id),
        FOREIGN KEY (hub_delivery_id) REFERENCES hubs(id),
        FOREIGN KEY (current_hub_id) REFERENCES hubs(id),
        FOREIGN KEY (assigned_agent_id) REFERENCES agents(id),
        FOREIGN KEY (route_id) REFERENCES routes(id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        parcel_id INTEGER NOT NULL,
        user_type TEXT NOT NULL CHECK (user_type IN ('agent','hub','system','customer')),
        user_id INTEGER,
        agent_id INTEGER,
        hub_id INTEGER,
        route_id INTEGER,
        event_type TEXT NOT NULL,
        note TEXT,
        lat REAL,
        lng REAL,
        ts TEXT NOT NULL,
        FOREIGN KEY (parcel_id) REFERENCES parcels(id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS idempotency_keys (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        actor_user_id INTEGER NOT NULL,
        operation TEXT NOT NULL,
        idempotency_key TEXT NOT NULL,
        request_hash TEXT NOT NULL,
        status TEXT NOT NULL CHECK (status IN ('processing','completed')),
        result_route TEXT,
        result_params TEXT,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        UNIQUE(actor_user_id, operation, idempotency_key),
        FOREIGN KEY (actor_user_id) REFERENCES users(id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        actor_user_id INTEGER NOT NULL,
        actor_role TEXT NOT NULL,
        action TEXT NOT NULL,
        entity_type TEXT NOT NULL,
        entity_id INTEGER NOT NULL,
        before_json TEXT,
        after_json TEXT,
        reason TEXT,
        metadata_json TEXT NOT NULL DEFAULT '{}',
        created_at TEXT NOT NULL,
        FOREIGN KEY (actor_user_id) REFERENCES users(id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (
        key TEXT PRIMARY KEY,
        bucket TEXT NOT NULL,
        attempts INTEGER NOT NULL,
        window_start INTEGER NOT NULL,
        last_attempt INTEGER NOT NULL
    )");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_parcels_status ON parcels(status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_parcels_assigned_agent ON parcels(assigned_agent_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_parcels_hub_pickup ON parcels(hub_pickup_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_parcels_tracking ON parcels(tracking_code)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_events_parcel_ts ON events(parcel_id, ts)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_idempotency_actor_operation ON idempotency_keys(actor_user_id, operation, created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_audit_log_entity ON audit_log(entity_type, entity_id, created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_audit_log_actor ON audit_log(actor_user_id, created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_routes_hub_active ON routes(hub_id, active)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rate_limits_bucket ON rate_limits(bucket, window_start)");

    $idempotencyCutoff = gmdate('c', time() - (7 * 24 * 60 * 60));
    $cleanup = $pdo->prepare("DELETE FROM idempotency_keys WHERE created_at < ?");
    $cleanup->execute([$idempotencyCutoff]);

    $hubCount = (int)$pdo->query("SELECT COUNT(*) AS c FROM hubs")->fetch()['c'];
    if ($hubCount > 0) {
        return;
    }

    $now = nowIso();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO hubs (name,type,address,city,coverage_keywords,auto_assign,created_at) VALUES (?,?,?,?,?,?,?)")
            ->execute(['North Pickup Hub', 'pickup', '12 North Ave', 'Metro City', json_encode(['Northside', 'Uptown', '1000'], JSON_UNESCAPED_UNICODE), 1, $now]);
        $pickupHubId = (int)$pdo->lastInsertId();

        $pdo->prepare("INSERT INTO hubs (name,type,address,city,coverage_keywords,auto_assign,created_at) VALUES (?,?,?,?,?,?,?)")
            ->execute(['Central Warehouse Hub', 'warehouse', '1 Logistics Park', 'Metro City', json_encode(['Central', 'Warehouse', '2000'], JSON_UNESCAPED_UNICODE), 1, $now]);
        $warehouseHubId = (int)$pdo->lastInsertId();

        $pdo->prepare("INSERT INTO hubs (name,type,address,city,coverage_keywords,auto_assign,created_at) VALUES (?,?,?,?,?,?,?)")
            ->execute(['East Last-Mile Hub', 'lastmile', '77 East Depot', 'Metro City', json_encode(['Eastside', 'Riverside', '2000'], JSON_UNESCAPED_UNICODE), 1, $now]);
        $lastMileHubId = (int)$pdo->lastInsertId();

        $pdo->prepare("INSERT INTO agents (hub_id,name,phone,role,active,current_status,last_seen_ts,created_at) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$pickupHubId, 'Amina (Pickup)', '+1-555-1001', 'pickup', 1, 'idle', null, $now]);
        $pickupAgent1 = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO agents (hub_id,name,phone,role,active,current_status,last_seen_ts,created_at) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$pickupHubId, 'Noah (Both)', '+1-555-1002', 'both', 1, 'idle', null, $now]);
        $pickupAgent2 = (int)$pdo->lastInsertId();

        $pdo->prepare("INSERT INTO agents (hub_id,name,phone,role,active,current_status,last_seen_ts,created_at) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$warehouseHubId, 'Liam (Warehouse)', '+1-555-2001', 'both', 1, 'idle', null, $now]);
        $warehouseAgent1 = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO agents (hub_id,name,phone,role,active,current_status,last_seen_ts,created_at) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$warehouseHubId, 'Maya (Delivery)', '+1-555-2002', 'delivery', 1, 'idle', null, $now]);
        $warehouseAgent2 = (int)$pdo->lastInsertId();

        $pdo->prepare("INSERT INTO agents (hub_id,name,phone,role,active,current_status,last_seen_ts,created_at) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$lastMileHubId, 'Sofia (Delivery)', '+1-555-3001', 'delivery', 1, 'idle', null, $now]);
        $deliveryAgent1 = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO agents (hub_id,name,phone,role,active,current_status,last_seen_ts,created_at) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$lastMileHubId, 'Owen (Both)', '+1-555-3002', 'both', 1, 'idle', null, $now]);
        $deliveryAgent2 = (int)$pdo->lastInsertId();

        $pdo->prepare("INSERT INTO routes (hub_id,name,match_keywords,assigned_agent_id,active,created_at) VALUES (?,?,?,?,?,?)")
            ->execute([$pickupHubId, 'Northside Pickup Route', json_encode(['Northside', 'Uptown', '1000'], JSON_UNESCAPED_UNICODE), $pickupAgent1, 1, $now]);
        $pickupRouteId = (int)$pdo->lastInsertId();

        $pdo->prepare("INSERT INTO routes (hub_id,name,match_keywords,assigned_agent_id,active,created_at) VALUES (?,?,?,?,?,?)")
            ->execute([$lastMileHubId, 'Eastside Delivery Route', json_encode(['Eastside', 'Riverside', '2000'], JSON_UNESCAPED_UNICODE), $deliveryAgent1, 1, $now]);
        $deliveryRouteId = (int)$pdo->lastInsertId();

        $pdo->prepare("INSERT INTO customers (name,email,api_key,created_at) VALUES (?,?,?,?)")
            ->execute(['Alice Shop', 'alice@example.com', null, $now]);
        $cust1 = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO customers (name,email,api_key,created_at) VALUES (?,?,?,?)")
            ->execute(['Bob Sender', 'bob@example.com', null, $now]);
        $cust2 = (int)$pdo->lastInsertId();

        $pwdAdmin = password_hash('admin1234', PASSWORD_DEFAULT);
        $pwdHub = password_hash('hub1234', PASSWORD_DEFAULT);
        $pwdAgent = password_hash('agent1234', PASSWORD_DEFAULT);
        $pwdCustomer = password_hash('customer1234', PASSWORD_DEFAULT);

        $pdo->prepare("INSERT INTO users (email,password_hash,role,hub_id,agent_id,customer_id,active,created_at) VALUES (?,?,?,?,?,?,?,?)")
            ->execute(['admin@hubroute.local', $pwdAdmin, 'admin', null, null, null, 1, $now]);

        $pdo->prepare("INSERT INTO users (email,password_hash,role,hub_id,agent_id,customer_id,active,created_at) VALUES (?,?,?,?,?,?,?,?)")
            ->execute(['pickuphub@hubroute.local', $pwdHub, 'hub', $pickupHubId, null, null, 1, $now]);
        $pdo->prepare("INSERT INTO users (email,password_hash,role,hub_id,agent_id,customer_id,active,created_at) VALUES (?,?,?,?,?,?,?,?)")
            ->execute(['warehouse@hubroute.local', $pwdHub, 'hub', $warehouseHubId, null, null, 1, $now]);
        $pdo->prepare("INSERT INTO users (email,password_hash,role,hub_id,agent_id,customer_id,active,created_at) VALUES (?,?,?,?,?,?,?,?)")
            ->execute(['eastmile@hubroute.local', $pwdHub, 'hub', $lastMileHubId, null, null, 1, $now]);

        $pdo->prepare("INSERT INTO users (email,password_hash,role,hub_id,agent_id,customer_id,active,created_at) VALUES (?,?,?,?,?,?,?,?)")
            ->execute(['amina@hubroute.local', $pwdAgent, 'agent', $pickupHubId, $pickupAgent1, null, 1, $now]);
        $pdo->prepare("INSERT INTO users (email,password_hash,role,hub_id,agent_id,customer_id,active,created_at) VALUES (?,?,?,?,?,?,?,?)")
            ->execute(['noah@hubroute.local', $pwdAgent, 'agent', $pickupHubId, $pickupAgent2, null, 1, $now]);
        $pdo->prepare("INSERT INTO users (email,password_hash,role,hub_id,agent_id,customer_id,active,created_at) VALUES (?,?,?,?,?,?,?,?)")
            ->execute(['liam@hubroute.local', $pwdAgent, 'agent', $warehouseHubId, $warehouseAgent1, null, 1, $now]);
        $pdo->prepare("INSERT INTO users (email,password_hash,role,hub_id,agent_id,customer_id,active,created_at) VALUES (?,?,?,?,?,?,?,?)")
            ->execute(['maya@hubroute.local', $pwdAgent, 'agent', $warehouseHubId, $warehouseAgent2, null, 1, $now]);
        $pdo->prepare("INSERT INTO users (email,password_hash,role,hub_id,agent_id,customer_id,active,created_at) VALUES (?,?,?,?,?,?,?,?)")
            ->execute(['sofia@hubroute.local', $pwdAgent, 'agent', $lastMileHubId, $deliveryAgent1, null, 1, $now]);
        $pdo->prepare("INSERT INTO users (email,password_hash,role,hub_id,agent_id,customer_id,active,created_at) VALUES (?,?,?,?,?,?,?,?)")
            ->execute(['owen@hubroute.local', $pwdAgent, 'agent', $lastMileHubId, $deliveryAgent2, null, 1, $now]);

        $pdo->prepare("INSERT INTO users (email,password_hash,role,hub_id,agent_id,customer_id,active,created_at) VALUES (?,?,?,?,?,?,?,?)")
            ->execute(['alice@example.com', $pwdCustomer, 'customer', null, null, $cust1, 1, $now]);
        $pdo->prepare("INSERT INTO users (email,password_hash,role,hub_id,agent_id,customer_id,active,created_at) VALUES (?,?,?,?,?,?,?,?)")
            ->execute(['bob@example.com', $pwdCustomer, 'customer', null, null, $cust2, 1, $now]);

        $samples = [
            [$cust1, '19 Northside St, Metro City 1000', '55 Riverside Rd, Metro City 2000', 1299, 'Fragile glassware'],
            [$cust1, '88 Uptown Blvd, Metro City 1000', '9 Eastside Ave, Metro City 2000', 0, 'Documents'],
            [$cust1, '5 North Ave, Metro City 1000', '300 Central Sq, Metro City 2000', 499, 'COD on delivery'],
            [$cust2, '77 Unknown District, Metro City 9999', '12 Riverside Rd, Metro City 2000', 0, 'Manual assign demo'],
            [$cust2, '22 Northside Market, Metro City 1000', '14 Central Sq, Metro City 2000', 2500, 'High-value'],
            [$cust2, '91 Uptown Plaza, Metro City 1000', '77 East Depot, Metro City 2000', 0, 'Last-mile hub demo'],
        ];

        foreach ($samples as $s) {
            [$customerId, $pickupAddr, $dropoffAddr, $amount, $note] = $s;
            $deliveryHub = matchDeliveryHub($pdo, $dropoffAddr) ?? $lastMileHubId;
            $match = matchPickupRoute($pdo, $pickupAddr, null, null);
            $hubPickup = $match['hub_id'] ?? null;
            $routeId = $match['route_id'] ?? null;
            $agentId = $match['agent_id'] ?? null;
            $autoAssign = (int)($match['auto_assign'] ?? 0);

            $tracking = generateTrackingCode();
            $status = ($autoAssign === 1 && $agentId !== null) ? 'assigned' : 'requested';
            $meta = json_encode(['note' => $note, 'match_reasons' => $match['reasons'] ?? []], JSON_UNESCAPED_UNICODE);
            $pdo->prepare("INSERT INTO parcels (customer_id,hub_pickup_id,hub_warehouse_id,hub_delivery_id,current_hub_id,pickup_address,pickup_lat,pickup_lng,dropoff_address,dropoff_lat,dropoff_lng,amount_cents,status,assigned_agent_id,route_id,tracking_code,metadata,cod_settled,created_at,updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $customerId,
                    $hubPickup,
                    $warehouseHubId,
                    $deliveryHub,
                    $hubPickup,
                    $pickupAddr,
                    null,
                    null,
                    $dropoffAddr,
                    null,
                    null,
                    $amount,
                    $status,
                    $agentId,
                    $routeId,
                    $tracking,
                    $meta,
                    0,
                    $now,
                    $now,
                ]);
            $parcelId = (int)$pdo->lastInsertId();
            addEvent($pdo, $parcelId, 'system', null, null, $hubPickup, $routeId, 'requested', 'Seeded request', null, null);
            if ($status === 'assigned') {
                addEvent($pdo, $parcelId, 'system', null, $agentId, $hubPickup, $routeId, 'assigned', 'Auto-assigned on seed', null, null);
            }

            if ($hubPickup !== null) {
                addEvent($pdo, $parcelId, 'system', null, null, $hubPickup, $routeId, 'hub_arrived', 'Initial hub set (seed)', null, null);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function generateTrackingCode(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $rand = '';
    for ($i = 0; $i < 8; $i++) {
        $rand .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return 'HR' . gmdate('ymd') . $rand;
}

function jsonArray(string $json): array
{
    $v = json_decode($json, true);
    return is_array($v) ? $v : [];
}

function normalize(string $s): string
{
    $s = trim(hrLower($s));
    $s = preg_replace('/\s+/', ' ', $s);
    return $s ?? '';
}

function extractPostcodes(string $address): array
{
    preg_match_all('/\b\d{3,8}\b/', $address, $m);
    $out = [];
    foreach ($m[0] ?? [] as $p) {
        $out[] = $p;
    }
    return array_values(array_unique($out));
}

function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return 2 * $r * asin(min(1.0, sqrt($a)));
}

function scoreAddressAgainstKeywords(string $address, array $keywords): array
{
    $addr = normalize($address);
    $postcodes = extractPostcodes($address);
    $score = 0;
    $reasons = [];

    foreach ($keywords as $kwRaw) {
        $kw = trim((string)$kwRaw);
        if ($kw === '') {
            continue;
        }
        if (preg_match('/^\d{3,8}$/', $kw) === 1) {
            foreach ($postcodes as $pc) {
                if ($pc === $kw) {
                    $score = max($score, 300);
                    $reasons[] = 'postcode:' . $kw;
                }
            }
            continue;
        }
        $k = normalize($kw);
        if ($k !== '' && hrPos($addr, $k) !== false) {
            $score = max($score, 200);
            $reasons[] = 'keyword:' . $kw;
        }
    }

    return ['score' => $score, 'reasons' => array_values(array_unique($reasons))];
}

function matchPickupRoute(PDO $pdo, string $pickupAddress, ?float $lat, ?float $lng): array
{
    $best = ['score' => 0];
    $routes = $pdo->query("SELECT r.*, h.auto_assign, h.id AS hub_id
        FROM routes r
        JOIN hubs h ON h.id = r.hub_id
        WHERE r.active = 1 AND h.type = 'pickup'")->fetchAll();

    foreach ($routes as $r) {
        $kw = jsonArray((string)($r['match_keywords'] ?? '[]'));
        $s = scoreAddressAgainstKeywords($pickupAddress, $kw);
        $score = (int)$s['score'];

        $centerLat = $r['center_lat'];
        $centerLng = $r['center_lng'];
        $radiusKm = $r['radius_km'];
        if ($lat !== null && $lng !== null && $centerLat !== null && $centerLng !== null && $radiusKm !== null) {
            $d = haversineKm($lat, $lng, (float)$centerLat, (float)$centerLng);
            if ($d <= (float)$radiusKm) {
                $score = max($score, 100);
            }
        }

        if ($score > (int)$best['score']) {
            $best = [
                'score' => $score,
                'hub_id' => (int)$r['hub_id'],
                'route_id' => (int)$r['id'],
                'agent_id' => $r['assigned_agent_id'] !== null ? (int)$r['assigned_agent_id'] : null,
                'auto_assign' => (int)$r['auto_assign'],
                'reasons' => $s['reasons'],
            ];
        }
    }

    if ((int)$best['score'] > 0) {
        return $best;
    }

    $hubs = $pdo->query("SELECT * FROM hubs WHERE type='pickup'")->fetchAll();
    foreach ($hubs as $h) {
        $kw = jsonArray((string)($h['coverage_keywords'] ?? '[]'));
        $s = scoreAddressAgainstKeywords($pickupAddress, $kw);
        if ((int)$s['score'] > (int)$best['score']) {
            $best = [
                'score' => (int)$s['score'],
                'hub_id' => (int)$h['id'],
                'route_id' => null,
                'agent_id' => null,
                'auto_assign' => (int)$h['auto_assign'],
                'reasons' => $s['reasons'],
            ];
        }
    }
    return $best;
}

function matchDeliveryHub(PDO $pdo, string $dropoffAddress): ?int
{
    $bestHub = null;
    $bestScore = 0;
    $hubs = $pdo->query("SELECT * FROM hubs WHERE type='lastmile'")->fetchAll();
    foreach ($hubs as $h) {
        $kw = jsonArray((string)($h['coverage_keywords'] ?? '[]'));
        $s = scoreAddressAgainstKeywords($dropoffAddress, $kw);
        if ((int)$s['score'] > $bestScore) {
            $bestScore = (int)$s['score'];
            $bestHub = (int)$h['id'];
        }
    }
    return $bestHub;
}

function addEvent(PDO $pdo, int $parcelId, string $userType, ?int $userId, ?int $agentId, ?int $hubId, ?int $routeId, string $eventType, ?string $note, ?float $lat, ?float $lng): void
{
    $pdo->prepare("INSERT INTO events (parcel_id,user_type,user_id,agent_id,hub_id,route_id,event_type,note,lat,lng,ts)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$parcelId, $userType, $userId, $agentId, $hubId, $routeId, $eventType, $note, $lat, $lng, nowIso()]);
}

function stablePayloadHash(string $operation, array $payload): string
{
    return hash('sha256', json_encode([
        'operation' => $operation,
        'payload' => $payload,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function beginIdempotentAction(PDO $pdo, array $u, string $operation, array $payload): array
{
    if (!in_array($operation, idempotentOperations(), true)) {
        throw new UserSafeException('Unknown idempotent operation');
    }

    $key = postIdempotencyKey();
    $requestHash = stablePayloadHash($operation, $payload);
    $now = nowIso();

    $insert = $pdo->prepare("INSERT OR IGNORE INTO idempotency_keys (actor_user_id,operation,idempotency_key,request_hash,status,created_at,updated_at)
        VALUES (?,?,?,?,?,?,?)");
    $insert->execute([(int)$u['id'], $operation, $key, $requestHash, 'processing', $now, $now]);
    if ($insert->rowCount() === 1) {
        return [
            'duplicate' => false,
            'operation' => $operation,
            'key' => $key,
            'request_hash' => $requestHash,
        ];
    }

    $st = $pdo->prepare("SELECT * FROM idempotency_keys WHERE actor_user_id=? AND operation=? AND idempotency_key=?");
    $st->execute([(int)$u['id'], $operation, $key]);
    $existing = $st->fetch();
    if (!$existing) {
        throw new UserSafeException('Idempotency state could not be loaded');
    }
    if (!hash_equals((string)$existing['request_hash'], $requestHash)) {
        throw new UserSafeException('Idempotency key was reused for different input');
    }

    return [
        'duplicate' => true,
        'operation' => $operation,
        'key' => $key,
        'request_hash' => $requestHash,
        'result_route' => (string)($existing['result_route'] ?? ''),
        'result_params' => jsonArray((string)($existing['result_params'] ?? '{}')),
    ];
}

function completeIdempotentAction(PDO $pdo, array $u, array $idem, string $route, array $params = []): void
{
    if (!empty($idem['duplicate'])) {
        return;
    }

    $pdo->prepare("UPDATE idempotency_keys
        SET status='completed', result_route=?, result_params=?, updated_at=?
        WHERE actor_user_id=? AND operation=? AND idempotency_key=?")
        ->execute([
            $route,
            json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            nowIso(),
            (int)$u['id'],
            (string)$idem['operation'],
            (string)$idem['key'],
        ]);
}

function redirectDuplicateAction(PDO $pdo, array $idem, string $fallbackRoute, array $fallbackParams = []): void
{
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $route = (string)($idem['result_route'] ?? '');
    $params = $idem['result_params'] ?? [];
    if ($route === '') {
        $route = $fallbackRoute;
        $params = $fallbackParams;
    }
    if (!is_array($params)) {
        $params = $fallbackParams;
    }

    flash('ok', 'Duplicate request ignored; the original submission was already handled.');
    redirect($route, $params);
}

function jsonOrNull(?array $value): ?string
{
    return $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function addAuditLog(PDO $pdo, array $u, string $action, string $entityType, int $entityId, ?array $before, ?array $after, ?string $reason = null, array $metadata = []): void
{
    $pdo->prepare("INSERT INTO audit_log (actor_user_id,actor_role,action,entity_type,entity_id,before_json,after_json,reason,metadata_json,created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([
            (int)$u['id'],
            (string)$u['role'],
            $action,
            $entityType,
            $entityId,
            jsonOrNull($before),
            jsonOrNull($after),
            $reason,
            json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            nowIso(),
        ]);
}

function clientIp(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'cli');
    return preg_match('/^[A-Fa-f0-9:.]{2,45}$/', $ip) ? $ip : 'unknown';
}

function rateLimitKey(string $bucket, string $subject): string
{
    return hash('sha256', $bucket . '|' . clientIp() . '|' . $subject);
}

function checkRateLimit(PDO $pdo, string $bucket, string $subject, int $maxAttempts, int $windowSeconds): void
{
    $now = time();
    $key = rateLimitKey($bucket, $subject);
    $st = $pdo->prepare("SELECT attempts, window_start FROM rate_limits WHERE key=?");
    $st->execute([$key]);
    $row = $st->fetch();

    if (!$row || ($now - (int)$row['window_start']) >= $windowSeconds) {
        $pdo->prepare("INSERT INTO rate_limits (key,bucket,attempts,window_start,last_attempt)
            VALUES (?,?,?,?,?)
            ON CONFLICT(key) DO UPDATE SET attempts=excluded.attempts, window_start=excluded.window_start, last_attempt=excluded.last_attempt")
            ->execute([$key, $bucket, 1, $now, $now]);
        return;
    }

    if ((int)$row['attempts'] >= $maxAttempts) {
        http_response_code(429);
        echo 'Too many attempts. Try again later.';
        exit;
    }

    $pdo->prepare("UPDATE rate_limits SET attempts=attempts+1, last_attempt=? WHERE key=?")
        ->execute([$now, $key]);
}

function clearRateLimit(PDO $pdo, string $bucket, string $subject): void
{
    $pdo->prepare("DELETE FROM rate_limits WHERE key=?")
        ->execute([rateLimitKey($bucket, $subject)]);
}

function parcelTouchesHub(array $parcel, int $hubId): bool
{
    return ((int)$parcel['hub_pickup_id'] === $hubId)
        || ((int)$parcel['hub_warehouse_id'] === $hubId)
        || ((int)$parcel['hub_delivery_id'] === $hubId)
        || ((int)$parcel['current_hub_id'] === $hubId);
}

function canAccessParcel(array $u, array $parcel): bool
{
    $role = (string)$u['role'];
    if ($role === 'admin') {
        return true;
    }
    if ($role === 'customer') {
        return (int)$u['customer_id'] === (int)$parcel['customer_id'];
    }
    if ($role === 'hub') {
        return parcelTouchesHub($parcel, (int)$u['hub_id']);
    }
    if ($role === 'agent') {
        return (int)$u['agent_id'] === (int)$parcel['assigned_agent_id'];
    }
    return false;
}

function assertHubOwnsAgentAndRoute(PDO $pdo, int $hubId, ?int $agentId, ?int $routeId): void
{
    if ($agentId !== null) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM agents WHERE id=? AND hub_id=? AND active=1");
        $st->execute([$agentId, $hubId]);
        if ((int)$st->fetchColumn() !== 1) {
            throw new UserSafeException('Selected agent is not available to this hub');
        }
    }

    if ($routeId !== null) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM routes WHERE id=? AND hub_id=? AND active=1");
        $st->execute([$routeId, $hubId]);
        if ((int)$st->fetchColumn() !== 1) {
            throw new UserSafeException('Selected route is not available to this hub');
        }
    }
}

function currentUser(PDO $pdo): ?array
{
    $id = $_SESSION['uid'] ?? null;
    if (!is_int($id) && !ctype_digit((string)$id)) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id=? AND active=1");
    $stmt->execute([(int)$id]);
    $u = $stmt->fetch();
    return is_array($u) ? $u : null;
}

function requireLogin(PDO $pdo): array
{
    $u = currentUser($pdo);
    if (!$u) {
        redirect('login');
    }
    return $u;
}

function requireRole(array $u, array $roles): void
{
    if (!in_array((string)$u['role'], $roles, true)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

function roleLabel(string $role): string
{
    return match ($role) {
        'admin' => 'Admin',
        'hub' => 'Hub',
        'agent' => 'Agent',
        'customer' => 'Customer',
        default => $role,
    };
}

function seededAccountEmails(): array
{
    return [
        'admin@hubroute.local',
        'pickuphub@hubroute.local',
        'warehouse@hubroute.local',
        'eastmile@hubroute.local',
        'amina@hubroute.local',
        'noah@hubroute.local',
        'liam@hubroute.local',
        'maya@hubroute.local',
        'sofia@hubroute.local',
        'owen@hubroute.local',
        'alice@example.com',
        'bob@example.com',
    ];
}

function isSeededAccountEmail(string $email): bool
{
    return in_array(strtolower($email), seededAccountEmails(), true);
}

function statusPill(string $status): string
{
    $map = [
        'requested' => 'bg-slate',
        'assigned' => 'bg-blue',
        'en_route' => 'bg-indigo',
        'picked_up' => 'bg-amber',
        'in_warehouse' => 'bg-purple',
        'out_for_delivery' => 'bg-teal',
        'delivered' => 'bg-green',
        'failed' => 'bg-red',
        'returned' => 'bg-orange',
    ];
    $cls = $map[$status] ?? 'bg-slate';
    return '<span class="pill ' . e($cls) . '">' . e($status) . '</span>';
}

function redactAddress(string $addr): string
{
    $s = trim($addr);
    $s = preg_replace('/\d/', '•', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return (string)$s;
}

function mapsUrl(string $address): string
{
    return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($address);
}

function csvCell(string $value): string
{
    $value = str_replace(["\r", "\n"], ' ', $value);
    return preg_match('/^[=+\-@\t]/', $value) ? "'" . $value : $value;
}

function renderLayout(string $title, ?array $user, string $content, array $meta = []): void
{
    $refresh = $meta['refresh'] ?? null;
    $refreshTag = $refresh ? '<meta http-equiv="refresh" content="' . e((string)$refresh) . '">' : '';
    $flash = takeFlash();

    $nav = '';
    if ($user) {
        $links = [
            ['Dashboard', 'dashboard'],
        ];
        if ($user['role'] === 'customer') {
            $links[] = ['Create Request', 'customer_parcels'];
            $links[] = ['My Parcels', 'customer_parcels'];
        }
        if ($user['role'] === 'hub') {
            $links[] = ['Parcels', 'hub_parcels'];
            $links[] = ['Routes', 'hub_routes'];
            $links[] = ['Scan/Event', 'scan'];
            $links[] = ['Settlements', 'settlements'];
        }
        if ($user['role'] === 'agent') {
            $links[] = ['My Run', 'agent_run'];
        }
        if ($user['role'] === 'admin') {
            $links[] = ['Admin', 'admin'];
        }
        $links[] = ['Public Tracking', 'track'];

        $navLinks = '';
        foreach ($links as $l) {
            $navLinks .= '<a class="navlink" href="?r=' . e($l[1]) . '">' . e($l[0]) . '</a>';
        }
        $logout = '<form method="post" class="navform"><input type="hidden" name="csrf" value="' . e(csrfToken()) . '"><input type="hidden" name="action" value="logout"><button class="navlink navbutton" type="submit">Logout</button></form>';
        $nav = '<div class="topbar"><div class="brand">' . e(APP_NAME) . '</div><div class="nav">' . $navLinks . $logout . '</div><div class="who">' . e((string)$user['email']) . ' / ' . e(roleLabel((string)$user['role'])) . '</div></div>';
    } else {
        $nav = '<div class="topbar"><div class="brand">' . e(APP_NAME) . '</div><div class="nav"><a class="navlink" href="?r=track">Public Tracking</a><a class="navlink" href="?r=login">Login</a></div></div>';
    }

    $flashHtml = '';
    foreach ($flash as $f) {
        $t = $f['type'] ?? 'info';
        $msg = $f['msg'] ?? '';
        $flashHtml .= '<div class="flash ' . e((string)$t) . '">' . e((string)$msg) . '</div>';
    }

    $footer = '<footer class="footer"><span>' . e(APP_NAME) . '</span><a href="?r=track">Public Tracking</a></footer>';

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' . $refreshTag . '<title>' . e($title) . ' / ' . e(APP_NAME) . '</title>';
    echo '<style>
    :root{--bg:#fff;--card:#fff;--text:#080808;--muted:#666;--border:#d8d8d8;--soft:#f4f4f4;--pri:#080808;--danger:#8a1111;}
    *{box-sizing:border-box}body{margin:0;font:14px/1.45 Inter,ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:var(--text);background:var(--bg)}
    a{color:var(--text);text-decoration:underline;text-decoration-thickness:1px;text-underline-offset:3px}a:hover{color:#555}
    .topbar{display:flex;gap:12px;align-items:center;justify-content:space-between;padding:14px 18px;background:rgba(255,255,255,.94);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:20;backdrop-filter:blur(10px)}
    .brand{font-weight:750;letter-spacing:0}
    .nav{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
    .navform{margin:0}
    .navlink{display:inline-flex;align-items:center;padding:6px 8px;border-radius:6px;color:var(--text);text-decoration:none}
    .navlink:hover{background:var(--soft);text-decoration:none}
    .navbutton{border:0;background:transparent;font:inherit;cursor:pointer}
    .who{color:var(--muted);font-size:12px}
    .wrap{max-width:1200px;margin:0 auto;padding:18px}
    .grid{display:grid;grid-template-columns:1fr;gap:12px}
    @media(min-width:980px){.grid.cols2{grid-template-columns:minmax(0,2fr) minmax(280px,1fr)}}
    .card{background:var(--card);border:1px solid var(--border);border-radius:8px;padding:14px}
    .h1{font-size:18px;font-weight:750;margin:0 0 8px;letter-spacing:0}
    .muted{color:var(--muted)}
    .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
    .btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:9px 12px;border-radius:6px;border:1px solid var(--text);background:#fff;color:var(--text);cursor:pointer;text-decoration:none;font:inherit}
    .btn.primary{background:var(--pri);border-color:var(--pri);color:#fff}
    .btn:disabled{opacity:.45;cursor:not-allowed}
    input,select,textarea{width:100%;padding:9px 10px;border-radius:6px;border:1px solid var(--border);background:#fff;color:var(--text);font:inherit}
    input:focus,select:focus,textarea:focus,.btn:focus-visible,.navlink:focus-visible{outline:2px solid #111;outline-offset:2px}
    textarea{min-height:90px;resize:vertical}
    label{display:block;font-size:12px;color:var(--muted);margin:6px 0}
    .form{display:grid;gap:10px}
    .table{width:100%;border-collapse:collapse}
    .table th{position:sticky;top:52px;background:var(--card);text-align:left;font-size:12px;color:var(--muted);border-bottom:1px solid var(--border);padding:10px}
    .table td{border-bottom:1px solid var(--border);padding:10px;vertical-align:top}
    .pill{display:inline-block;padding:3px 8px;border-radius:999px;font-size:12px;border:1px solid var(--border);background:#fff}
    .pill.bg-blue,.pill.bg-indigo,.pill.bg-amber,.pill.bg-purple,.pill.bg-teal,.pill.bg-green,.pill.bg-red,.pill.bg-orange{background:var(--soft);border-color:var(--border)}
    .flash{padding:10px 12px;border-radius:8px;margin:0 0 10px;border:1px solid var(--border);background:#fff}
    .flash.ok{border-color:#111;background:#f8f8f8}
    .flash.err{border-color:var(--danger);background:#fff}
    .kpis{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}
    @media(min-width:640px){.kpis{grid-template-columns:repeat(4,1fr)}}
    .kpi{padding:10px 12px;border:1px solid var(--border);border-radius:8px;background:#fff}
    .kpi .n{font-size:18px;font-weight:800}
    .kpi .l{font-size:12px;color:var(--muted)}
    .footer{max-width:1200px;margin:28px auto 0;padding:18px;border-top:1px solid var(--border);display:flex;gap:14px;flex-wrap:wrap;color:var(--muted);font-size:12px}
    .footer a{color:var(--muted)}
    @media(max-width:720px){.topbar{align-items:flex-start;flex-direction:column}.who{order:2}.wrap{padding:12px}.card{padding:12px}.table th{position:static}}
    </style>';
    echo '</head><body>' . $nav . '<div class="wrap">' . $flashHtml . $content . '</div>' . $footer . '</body></html>';
}

function pageLogin(): void
{
    $demoEmail = queryText('demo', 20) === 'hub' ? 'pickuphub@hubroute.local' : '';
    $content = '<div class="card" style="max-width:520px;margin:40px auto"><div class="h1">Sign in</div>';
    $content .= '<form method="post" class="form"><input type="hidden" name="csrf" value="' . e(csrfToken()) . '"><input type="hidden" name="action" value="login">';
    $content .= '<div><label>Email</label><input name="email" type="email" value="' . e($demoEmail) . '" required></div>';
    $content .= '<div><label>Password</label><input name="password" type="password" required></div>';
    $content .= '<button class="btn primary" type="submit">Sign in</button>';
    $content .= '</form>';
    $content .= '<div class="card" style="padding:10px;margin-top:12px;background:#fafafa">'
        . '<div class="h1" style="font-size:15px;margin-bottom:6px">Demo hub account</div>'
        . '<div class="muted">Email: <strong>pickuphub@hubroute.local</strong></div>'
        . '<div class="muted">Password: <strong>hub1234</strong></div>'
        . '<div class="muted" style="margin-top:6px">Use for the seeded North Pickup Hub workflow. Rotate seeded credentials before production use.</div>'
        . '</div>';
    $content .= '<div class="muted" style="margin-top:10px">Public tracking: <a href="?r=track">Track a parcel</a></div></div>';
    renderLayout('Login', null, $content);
}

function actionLogin(PDO $pdo): void
{
    csrfCheck();
    $emailRaw = postText('email', 254);
    $password = (string)($_POST['password'] ?? '');
    $rateSubject = hash('sha256', hrLower($emailRaw));
    checkRateLimit($pdo, 'login', $rateSubject, RATE_LIMIT_LOGIN_ATTEMPTS, RATE_LIMIT_LOGIN_WINDOW_SECONDS);

    try {
        $email = validEmail($emailRaw);
    } catch (UserSafeException $e) {
        flash('err', 'Invalid credentials');
        redirect('login');
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email=? AND active=1");
    $stmt->execute([$email]);
    $u = $stmt->fetch();
    if (!$u || !password_verify($password, (string)$u['password_hash'])) {
        flash('err', 'Invalid credentials');
        redirect('login');
    }
    clearRateLimit($pdo, 'login', $rateSubject);
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    $_SESSION['created_at'] = time();
    $_SESSION['last_activity'] = time();
    flash('ok', 'Welcome back');
    redirect('dashboard');
}

function actionLogout(): void
{
    csrfCheck();
    destroySessionOnly();
    redirect('login');
}

function pagePublicTrack(PDO $pdo): void
{
    $code = preg_replace('/[^A-Za-z0-9_-]/', '', queryText('code', 64)) ?? '';
    $hasCode = $code !== '';
    $content = '<div class="grid cols2">';
    $content .= '<div class="card"><div class="h1">Track a Parcel</div><div class="muted">Enter a tracking code to see current status and hub-to-hub movement.</div>';
    $content .= '<form class="form" method="get" style="margin-top:10px"><input type="hidden" name="r" value="track">';
    $content .= '<div><label>Tracking code</label><input name="code" value="' . e($code) . '" placeholder="e.g., HR240101ABCDEFGH" required></div>';
    $content .= '<button class="btn primary" type="submit">Track</button>';
    $content .= '</form></div>';

    if ($hasCode) {
        $stmt = $pdo->prepare("SELECT p.* FROM parcels p WHERE p.tracking_code=?");
        $stmt->execute([$code]);
        $p = $stmt->fetch();
        if (!$p) {
            $content .= '<div class="card"><div class="h1">Not found</div><div class="muted">No parcel found for that tracking code.</div></div>';
        } else {
            $events = $pdo->prepare("SELECT e.*, h.name AS hub_name FROM events e LEFT JOIN hubs h ON h.id=e.hub_id WHERE e.parcel_id=? ORDER BY e.ts DESC LIMIT 30");
            $events->execute([(int)$p['id']]);
            $evs = $events->fetchAll();

            $hubPathStmt = $pdo->prepare("SELECT e.ts, e.hub_id, h.name AS hub_name
                FROM events e LEFT JOIN hubs h ON h.id=e.hub_id
                WHERE e.parcel_id=? AND e.hub_id IS NOT NULL
                ORDER BY e.ts ASC");
            $hubPathStmt->execute([(int)$p['id']]);
            $hpRows = $hubPathStmt->fetchAll();
            $hubPath = [];
            foreach ($hpRows as $r) {
                $hid = (string)($r['hub_id'] ?? '');
                if ($hid === '') {
                    continue;
                }
                if (!isset($hubPath[$hid])) {
                    $hubPath[$hid] = ['hub_name' => $r['hub_name'] ?: ('Hub #' . $hid), 'first_ts' => $r['ts']];
                }
                $hubPath[$hid]['last_ts'] = $r['ts'];
            }

            $currentHubName = null;
            if ($p['current_hub_id'] !== null) {
                $h = $pdo->prepare("SELECT name FROM hubs WHERE id=?");
                $h->execute([(int)$p['current_hub_id']]);
                $currentHubName = $h->fetchColumn() ?: null;
            }

            $content .= '<div class="card">'
                . '<div class="h1">' . e((string)$p['tracking_code']) . '</div>'
                . '<div class="row" style="margin-bottom:10px">'
                . statusPill((string)$p['status'])
                . '<span class="muted">Last updated: ' . e((string)$p['updated_at']) . '</span>'
                . ($currentHubName ? '<span class="pill">Current hub: ' . e((string)$currentHubName) . '</span>' : '')
                . '</div>';

            $content .= '<div class="card" style="padding:10px">'
                . '<div class="muted" style="font-size:12px;margin-bottom:6px">Hub path</div>';
            if (count($hubPath) === 0) {
                $content .= '<div class="muted">No hub updates yet.</div>';
            } else {
                foreach ($hubPath as $h) {
                    $content .= '<div style="padding:6px 0;border-bottom:1px solid var(--border)"><div><strong>' . e((string)$h['hub_name']) . '</strong></div><div class="muted" style="font-size:12px">' . e((string)$h['first_ts']) . (isset($h['last_ts']) ? ' → ' . e((string)$h['last_ts']) : '') . '</div></div>';
                }
            }
            $content .= '</div>';

            $content .= '<div class="card" style="padding:10px;margin-top:10px">'
                . '<div class="muted" style="font-size:12px;margin-bottom:6px">Addresses</div>'
                . '<div><span class="muted">Pickup:</span> ' . e(redactAddress((string)$p['pickup_address'])) . '</div>'
                . '<div><span class="muted">Dropoff:</span> ' . e(redactAddress((string)$p['dropoff_address'])) . '</div>'
                . '</div>';

            $content .= '<div class="card" style="padding:10px;margin-top:10px">'
                . '<div class="muted" style="font-size:12px;margin-bottom:6px">Recent events</div>';
            $content .= '<table class="table"><thead><tr><th>Time</th><th>Event</th><th>Hub</th><th>Note</th></tr></thead><tbody>';
            foreach ($evs as $ev) {
                $note = (string)($ev['note'] ?? '');
                $safeNote = hrLen($note) > 180 ? (hrSub($note, 0, 180) . '…') : $note;
                $content .= '<tr><td>' . e((string)$ev['ts']) . '</td><td>' . e((string)$ev['event_type']) . '</td><td>' . e((string)($ev['hub_name'] ?? '')) . '</td><td class="muted">' . e($safeNote) . '</td></tr>';
            }
            $content .= '</tbody></table>';
            $content .= '<div class="muted" style="margin-top:8px">Auto-refreshes every 15 seconds while open.</div>';
            $content .= '</div>';
            $content .= '</div>';
        }
    } else {
        $any = $pdo->query("SELECT tracking_code FROM parcels ORDER BY id DESC LIMIT 5")->fetchAll();
        $content .= '<div class="card"><div class="h1">Try a sample code</div>';
        if (count($any) === 0) {
            $content .= '<div class="muted">No parcels yet.</div>';
        } else {
            foreach ($any as $r) {
                $c = (string)$r['tracking_code'];
                $content .= '<div style="padding:6px 0"><a href="?r=track&code=' . e($c) . '">' . e($c) . '</a></div>';
            }
        }
        $content .= '</div>';
    }

    $content .= '</div>';
    renderLayout('Public Tracking', null, $content, $hasCode ? ['refresh' => 15] : []);
}

function pageDashboard(PDO $pdo, array $u): void
{
    $role = (string)$u['role'];
    if ($role === 'customer') {
        redirect('customer_parcels');
    }
    if ($role === 'hub') {
        redirect('hub_parcels');
    }
    if ($role === 'agent') {
        redirect('agent_run');
    }
    redirect('admin');
}

function pageCustomerParcels(PDO $pdo, array $u): void
{
    requireRole($u, ['customer']);
    $cid = (int)$u['customer_id'];
    $status = cleanStatusFilter(queryText('status', 40));
    $sql = "SELECT p.*, a.name AS agent_name FROM parcels p LEFT JOIN agents a ON a.id=p.assigned_agent_id WHERE p.customer_id=?";
    $params = [$cid];
    if ($status !== '') {
        $sql .= " AND p.status=?";
        $params[] = $status;
    }
    $sql .= " ORDER BY p.updated_at DESC LIMIT 200";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    $counts = $pdo->prepare("SELECT status, COUNT(*) AS c FROM parcels WHERE customer_id=? GROUP BY status");
    $counts->execute([$cid]);
    $by = [];
    foreach ($counts->fetchAll() as $r) {
        $by[$r['status']] = (int)$r['c'];
    }

    $content = '<div class="grid cols2">';
    $content .= '<div class="card"><div class="h1">My Parcels</div><div class="muted">Open a parcel to see its hub chain and timeline.</div>';
    $content .= '<div class="row" style="margin:10px 0">'
        . '<a class="btn" href="?r=customer_parcels">All</a>'
        . '<a class="btn" href="?r=customer_parcels&status=requested">Requested (' . (int)($by['requested'] ?? 0) . ')</a>'
        . '<a class="btn" href="?r=customer_parcels&status=assigned">Assigned (' . (int)($by['assigned'] ?? 0) . ')</a>'
        . '<a class="btn" href="?r=customer_parcels&status=out_for_delivery">Out (' . (int)($by['out_for_delivery'] ?? 0) . ')</a>'
        . '<a class="btn" href="?r=customer_parcels&status=delivered">Delivered (' . (int)($by['delivered'] ?? 0) . ')</a>'
        . '</div>';
    $content .= '<table class="table"><thead><tr><th>Tracking</th><th>Status</th><th>Pickup</th><th>Dropoff</th><th>COD</th><th>Agent</th><th>Updated</th></tr></thead><tbody>';
    foreach ($rows as $p) {
        $content .= '<tr>';
        $content .= '<td><a href="?r=parcel&id=' . (int)$p['id'] . '">' . e((string)$p['tracking_code']) . '</a></td>';
        $content .= '<td>' . statusPill((string)$p['status']) . '</td>';
        $content .= '<td class="muted">' . e((string)$p['pickup_address']) . '</td>';
        $content .= '<td class="muted">' . e((string)$p['dropoff_address']) . '</td>';
        $content .= '<td>' . e(formatMoney((int)$p['amount_cents'])) . '</td>';
        $content .= '<td>' . e((string)($p['agent_name'] ?? '')) . '</td>';
        $content .= '<td class="muted">' . e((string)$p['updated_at']) . '</td>';
        $content .= '</tr>';
    }
    $content .= '</tbody></table></div>';

    $content .= '<div class="card"><div class="h1">Create pickup request</div>';
    $content .= '<form method="post" class="form"><input type="hidden" name="csrf" value="' . e(csrfToken()) . '">' . idempotencyInput() . '<input type="hidden" name="action" value="customer_create">';
    $content .= '<div><label>Pickup address</label><textarea name="pickup_address" required></textarea></div>';
    $content .= '<div><label>Dropoff address</label><textarea name="dropoff_address" required></textarea></div>';
    $content .= '<div class="row"><div style="flex:1"><label>Amount to collect (USD)</label><input name="amount" type="number" min="0" step="0.01" placeholder="0.00"></div><div style="flex:1"><label>Preferred pickup window</label><input name="pickup_window" type="text" placeholder="e.g., 10:00–12:00"></div></div>';
    $content .= '<div><label>Notes</label><textarea name="note" placeholder="Optional"></textarea></div>';
    $content .= '<button class="btn primary" type="submit">Submit request</button>';
    $content .= '</form></div>';
    $content .= '</div>';
    renderLayout('Customer', $u, $content);
}

function actionCustomerCreate(PDO $pdo, array $u): void
{
    requireRole($u, ['customer']);
    csrfCheck();
    try {
        $pickup = postText('pickup_address', 500);
        $dropoff = postText('dropoff_address', 500);
        $amountCents = parseMoneyCents((string)($_POST['amount'] ?? '0'));
        $pickupWindow = postText('pickup_window', 80);
        $note = postText('note', 500);
    } catch (UserSafeException $e) {
        flash('err', $e->getMessage());
        redirect('customer_parcels');
    }

    if ($pickup === '' || $dropoff === '') {
        flash('err', 'Pickup and dropoff are required');
        redirect('customer_parcels');
    }

    $warehouseHubId = (int)($pdo->query("SELECT id FROM hubs WHERE type='warehouse' ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);
    $deliveryHubId = matchDeliveryHub($pdo, $dropoff);
    $match = matchPickupRoute($pdo, $pickup, null, null);
    $pickupHubId = $match['hub_id'] ?? null;
    $routeId = $match['route_id'] ?? null;
    $agentId = $match['agent_id'] ?? null;
    $autoAssign = (int)($match['auto_assign'] ?? 0);

    $status = ($autoAssign === 1 && $agentId !== null) ? 'assigned' : 'requested';
    $tracking = generateTrackingCode();
    $now = nowIso();
    $meta = json_encode([
        'preferred_pickup_window' => $pickupWindow,
        'note' => $note,
        'match_reasons' => $match['reasons'] ?? [],
    ], JSON_UNESCAPED_UNICODE);

    $pdo->beginTransaction();
    try {
        $idem = beginIdempotentAction($pdo, $u, 'customer_create', [
            'pickup' => $pickup,
            'dropoff' => $dropoff,
            'amount_cents' => $amountCents,
            'pickup_window' => $pickupWindow,
            'note' => $note,
        ]);
        if (!empty($idem['duplicate'])) {
            redirectDuplicateAction($pdo, $idem, 'customer_parcels');
        }

        $pdo->prepare("INSERT INTO parcels (customer_id,hub_pickup_id,hub_warehouse_id,hub_delivery_id,current_hub_id,pickup_address,pickup_lat,pickup_lng,dropoff_address,dropoff_lat,dropoff_lng,amount_cents,status,assigned_agent_id,route_id,tracking_code,metadata,cod_settled,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([
                (int)$u['customer_id'],
                $pickupHubId,
                $warehouseHubId ?: null,
                $deliveryHubId,
                $pickupHubId,
                $pickup,
                null,
                null,
                $dropoff,
                null,
                null,
                $amountCents,
                $status,
                $agentId,
                $routeId,
                $tracking,
                $meta,
                0,
                $now,
                $now,
            ]);
        $pid = (int)$pdo->lastInsertId();
        addEvent($pdo, $pid, 'customer', (int)$u['id'], null, $pickupHubId, $routeId, 'requested', $note !== '' ? $note : 'Customer request created', null, null);
        if ($pickupHubId !== null) {
            addEvent($pdo, $pid, 'system', null, null, $pickupHubId, $routeId, 'hub_arrived', 'Initial hub set', null, null);
        }
        if ($status === 'assigned') {
            addEvent($pdo, $pid, 'system', null, $agentId, $pickupHubId, $routeId, 'assigned', 'Auto-assigned by routing rules', null, null);
        }
        completeIdempotentAction($pdo, $u, $idem, 'customer_parcels');
        $pdo->commit();
    } catch (UserSafeException $e) {
        $pdo->rollBack();
        flash('err', $e->getMessage());
        redirect('customer_parcels');
    } catch (Throwable $e) {
        $pdo->rollBack();
        logAppError($e, ['action' => 'customer_create']);
        flash('err', 'Request could not be created.');
        redirect('customer_parcels');
    }

    flash('ok', 'Request created. Tracking: ' . $tracking);
    redirect('customer_parcels');
}

function pageHubParcels(PDO $pdo, array $u): void
{
    requireRole($u, ['hub']);
    $hubId = (int)$u['hub_id'];
    $status = cleanStatusFilter(queryText('status', 40));
    $q = queryText('q', 80);

    $sql = "SELECT p.*, c.name AS customer_name, a.name AS agent_name, r.name AS route_name
        FROM parcels p
        JOIN customers c ON c.id=p.customer_id
        LEFT JOIN agents a ON a.id=p.assigned_agent_id
        LEFT JOIN routes r ON r.id=p.route_id
        WHERE (p.hub_pickup_id=? OR p.hub_warehouse_id=? OR p.hub_delivery_id=? OR p.current_hub_id=?)";
    $params = [$hubId, $hubId, $hubId, $hubId];
    if ($status !== '') {
        $sql .= " AND p.status=?";
        $params[] = $status;
    }
    if ($q !== '') {
        $sql .= " AND (p.tracking_code LIKE ? OR p.pickup_address LIKE ? OR p.dropoff_address LIKE ?)";
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
    }
    $sql .= " ORDER BY p.updated_at DESC LIMIT 300";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    $hn = $pdo->prepare("SELECT name FROM hubs WHERE id=?");
    $hn->execute([$hubId]);
    $hubName = (string)($hn->fetchColumn() ?: ('Hub #' . $hubId));

    $agents = $pdo->prepare("SELECT * FROM agents WHERE hub_id=? AND active=1 ORDER BY name");
    $agents->execute([$hubId]);
    $agentRows = $agents->fetchAll();

    $routes = $pdo->prepare("SELECT * FROM routes WHERE hub_id=? AND active=1 ORDER BY name");
    $routes->execute([$hubId]);
    $routeRows = $routes->fetchAll();

    $content = '<div class="card"><div class="h1">Hub Operations · ' . e($hubName) . '</div>';
    $content .= '<form class="row" method="get" style="margin:10px 0"><input type="hidden" name="r" value="hub_parcels">'
        . '<div style="flex:1;min-width:220px"><input name="q" value="' . e($q) . '" placeholder="Search tracking/address"></div>'
        . '<div style="min-width:180px"><select name="status"><option value="">All statuses</option>';
    foreach (['requested','assigned','en_route','picked_up','in_warehouse','out_for_delivery','delivered','failed','returned'] as $s) {
        $content .= '<option value="' . e($s) . '"' . ($status === $s ? ' selected' : '') . '>' . e($s) . '</option>';
    }
    $content .= '</select></div>'
        . '<button class="btn" type="submit">Filter</button>'
        . '<a class="btn" href="?r=hub_parcels">Reset</a>'
        . '</form>';

    $content .= '<table class="table"><thead><tr><th>Tracking</th><th>Status</th><th>Pickup</th><th>Dropoff</th><th>COD</th><th>Route</th><th>Agent</th><th>Created</th><th>Assign</th></tr></thead><tbody>';
    foreach ($rows as $p) {
        $content .= '<tr>';
        $content .= '<td><a href="?r=parcel&id=' . (int)$p['id'] . '">' . e((string)$p['tracking_code']) . '</a><div class="muted" style="font-size:12px">' . e((string)$p['customer_name']) . '</div></td>';
        $content .= '<td>' . statusPill((string)$p['status']) . '<div class="muted" style="font-size:12px">Updated ' . e((string)$p['updated_at']) . '</div></td>';
        $pickupAddr = (string)$p['pickup_address'];
        $dropoffAddr = (string)$p['dropoff_address'];
        $content .= '<td class="muted">' . e($pickupAddr) . '<div style="margin-top:4px"><a href="' . e(mapsUrl($pickupAddr)) . '" target="_blank" rel="noopener">Map</a></div></td>';
        $content .= '<td class="muted">' . e($dropoffAddr) . '<div style="margin-top:4px"><a href="' . e(mapsUrl($dropoffAddr)) . '" target="_blank" rel="noopener">Map</a></div></td>';
        $content .= '<td>' . e(formatMoney((int)$p['amount_cents'])) . ($p['cod_settled'] ? '<div class="muted" style="font-size:12px">settled</div>' : '') . '</td>';
        $content .= '<td>' . e((string)($p['route_name'] ?? '')) . '</td>';
        $content .= '<td>' . e((string)($p['agent_name'] ?? '')) . '</td>';
        $content .= '<td class="muted">' . e((string)$p['created_at']) . '</td>';

        $content .= '<td>';
        $content .= '<form method="post" class="row" style="gap:6px"><input type="hidden" name="csrf" value="' . e(csrfToken()) . '">' . idempotencyInput() . '<input type="hidden" name="action" value="hub_assign"><input type="hidden" name="parcel_id" value="' . (int)$p['id'] . '">';
        $content .= '<select name="route_id" style="min-width:160px"><option value="">No route</option>';
        foreach ($routeRows as $r) {
            $sel = ((int)$p['route_id'] === (int)$r['id']) ? ' selected' : '';
            $content .= '<option value="' . (int)$r['id'] . '"' . $sel . '>' . e((string)$r['name']) . '</option>';
        }
        $content .= '</select>';
        $content .= '<select name="agent_id" style="min-width:160px"><option value="">Unassigned</option>';
        foreach ($agentRows as $a) {
            $sel = ((int)$p['assigned_agent_id'] === (int)$a['id']) ? ' selected' : '';
            $content .= '<option value="' . (int)$a['id'] . '"' . $sel . '>' . e((string)$a['name']) . '</option>';
        }
        $content .= '</select>';
        $content .= '<button class="btn" type="submit">Set</button>';
        $content .= '</form>';
        $content .= '</td>';
        $content .= '</tr>';
    }
    $content .= '</tbody></table>';
    $content .= '</div>';
    renderLayout('Hub Parcels', $u, $content);
}

function actionCustomerConfirm(PDO $pdo, array $u): void
{
    requireRole($u, ['customer']);
    csrfCheck();
    try {
        $parcelId = postPositiveInt('parcel_id');
    } catch (UserSafeException $e) {
        flash('err', $e->getMessage());
        redirect('customer_parcels');
    }

    $pdo->beginTransaction();
    try {
        $idem = beginIdempotentAction($pdo, $u, 'customer_confirm', [
            'parcel_id' => $parcelId,
        ]);
        if (!empty($idem['duplicate'])) {
            redirectDuplicateAction($pdo, $idem, 'parcel', ['id' => (string)$parcelId]);
        }

        $st = $pdo->prepare("SELECT * FROM parcels WHERE id=? AND customer_id=?");
        $st->execute([$parcelId, (int)$u['customer_id']]);
        $p = $st->fetch();
        if (!$p) {
            throw new UserSafeException('Parcel not found');
        }
        if ((string)$p['status'] !== 'delivered') {
            throw new UserSafeException('Parcel is not delivered');
        }

        $meta = json_decode((string)$p['metadata'], true);
        $meta = is_array($meta) ? $meta : [];
        if (!empty($meta['customer_confirmed_at'])) {
            throw new UserSafeException('Already confirmed');
        }
        $meta['customer_confirmed_at'] = nowIso();

        $upd = $pdo->prepare("UPDATE parcels SET metadata=?, updated_at=? WHERE id=? AND customer_id=?");
        $upd->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), nowIso(), $parcelId, (int)$u['customer_id']]);
        addEvent($pdo, $parcelId, 'customer', (int)$u['id'], null, $p['current_hub_id'] !== null ? (int)$p['current_hub_id'] : null, $p['route_id'] !== null ? (int)$p['route_id'] : null, 'customer_confirmed', 'Customer confirmed receipt', null, null);
        completeIdempotentAction($pdo, $u, $idem, 'parcel', ['id' => (string)$parcelId]);
        $pdo->commit();
        flash('ok', 'Receipt confirmed');
    } catch (UserSafeException $e) {
        $pdo->rollBack();
        flash('err', $e->getMessage());
    } catch (Throwable $e) {
        $pdo->rollBack();
        logAppError($e, ['action' => 'customer_confirm']);
        flash('err', 'Request could not be completed.');
    }
    redirect('parcel', ['id' => (string)$parcelId]);
}

function actionHubAssign(PDO $pdo, array $u): void
{
    requireRole($u, ['hub']);
    csrfCheck();
    $hubId = (int)$u['hub_id'];
    try {
        $parcelId = postPositiveInt('parcel_id');
        $agentId = nullablePositiveInt((string)($_POST['agent_id'] ?? ''));
        $routeId = nullablePositiveInt((string)($_POST['route_id'] ?? ''));
        assertHubOwnsAgentAndRoute($pdo, $hubId, $agentId, $routeId);
    } catch (UserSafeException $e) {
        flash('err', $e->getMessage());
        redirect('hub_parcels');
    }

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM parcels WHERE id=?");
        $st->execute([$parcelId]);
        $p = $st->fetch();
        if (!$p) {
            throw new UserSafeException('Parcel not found');
        }
        if (!parcelTouchesHub($p, $hubId)) {
            throw new UserSafeException('Parcel is not visible to this hub');
        }

        $idem = beginIdempotentAction($pdo, $u, 'hub_assign', [
            'parcel_id' => $parcelId,
            'agent_id' => $agentId,
            'route_id' => $routeId,
        ]);
        if (!empty($idem['duplicate'])) {
            redirectDuplicateAction($pdo, $idem, 'hub_parcels');
        }

        $now = nowIso();
        $status = (string)$p['status'];
        $newStatus = $status;
        if ($status === 'requested' && $agentId !== null) {
            $newStatus = 'assigned';
        }

        if ($status === 'requested') {
            $upd = $pdo->prepare("UPDATE parcels
                SET assigned_agent_id=?, route_id=?, status=?, updated_at=?
                WHERE id=? AND status='requested'");
            $upd->execute([$agentId, $routeId, $newStatus, $now, $parcelId]);
            if ($upd->rowCount() !== 1) {
                throw new UserSafeException('Parcel was updated by another user; retry');
            }
        } else {
            $upd = $pdo->prepare("UPDATE parcels
                SET assigned_agent_id=?, route_id=?, updated_at=?
                WHERE id=?");
            $upd->execute([$agentId, $routeId, $now, $parcelId]);
        }

        if ($agentId !== null) {
            addEvent($pdo, $parcelId, 'hub', (int)$u['id'], $agentId, $hubId, $routeId, 'assigned', 'Assigned by hub operator', null, null);
        } else {
            addEvent($pdo, $parcelId, 'hub', (int)$u['id'], null, $hubId, $routeId, 'unassigned', 'Unassigned by hub operator', null, null);
        }

        addAuditLog(
            $pdo,
            $u,
            'parcel_metadata_update',
            'parcel',
            $parcelId,
            [
                'assigned_agent_id' => $p['assigned_agent_id'] !== null ? (int)$p['assigned_agent_id'] : null,
                'route_id' => $p['route_id'] !== null ? (int)$p['route_id'] : null,
                'status' => (string)$p['status'],
            ],
            [
                'assigned_agent_id' => $agentId,
                'route_id' => $routeId,
                'status' => $newStatus,
            ],
            'Hub assignment updated'
        );
        completeIdempotentAction($pdo, $u, $idem, 'hub_parcels');
        $pdo->commit();
        flash('ok', 'Assignment updated');
    } catch (UserSafeException $e) {
        $pdo->rollBack();
        flash('err', $e->getMessage());
    } catch (Throwable $e) {
        $pdo->rollBack();
        logAppError($e, ['action' => 'hub_assign']);
        flash('err', 'Assignment could not be updated.');
    }

    redirect('hub_parcels');
}

function pageHubRoutes(PDO $pdo, array $u): void
{
    requireRole($u, ['hub']);
    $hubId = (int)$u['hub_id'];
    $hn = $pdo->prepare("SELECT name FROM hubs WHERE id=?");
    $hn->execute([$hubId]);
    $hubName = (string)($hn->fetchColumn() ?: ('Hub #' . $hubId));

    $routes = $pdo->prepare("SELECT r.*, a.name AS agent_name FROM routes r LEFT JOIN agents a ON a.id=r.assigned_agent_id WHERE r.hub_id=? ORDER BY r.active DESC, r.name");
    $routes->execute([$hubId]);
    $rows = $routes->fetchAll();

    $agents = $pdo->prepare("SELECT * FROM agents WHERE hub_id=? AND active=1 ORDER BY name");
    $agents->execute([$hubId]);
    $agentRows = $agents->fetchAll();

    $content = '<div class="grid cols2">';
    $content .= '<div class="card"><div class="h1">Routes · ' . e($hubName) . '</div>';
    $content .= '<table class="table"><thead><tr><th>Name</th><th>Match keywords</th><th>Agent</th><th>Active</th><th>Export</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $kw = implode(', ', jsonArray((string)$r['match_keywords']));
        $content .= '<tr>';
        $content .= '<td>' . e((string)$r['name']) . '</td>';
        $content .= '<td class="muted">' . e($kw) . '</td>';
        $content .= '<td>' . e((string)($r['agent_name'] ?? '')) . '</td>';
        $content .= '<td>' . ((int)$r['active'] === 1 ? '<span class="pill bg-green">active</span>' : '<span class="pill bg-red">inactive</span>') . '</td>';
        $content .= '<td><a class="btn" href="?r=route_export&id=' . (int)$r['id'] . '">CSV</a></td>';
        $content .= '</tr>';
    }
    $content .= '</tbody></table></div>';

    $content .= '<div class="card"><div class="h1">Create route</div>';
    $content .= '<form method="post" class="form"><input type="hidden" name="csrf" value="' . e(csrfToken()) . '">' . idempotencyInput() . '<input type="hidden" name="action" value="hub_create_route">';
    $content .= '<div><label>Route name</label><input name="name" required></div>';
    $content .= '<div><label>Match keywords/postcodes (comma-separated)</label><input name="keywords" placeholder="Northside, Uptown, 1000" required></div>';
    $content .= '<div><label>Assign agent (optional)</label><select name="assigned_agent_id"><option value="">Unassigned</option>';
    foreach ($agentRows as $a) {
        $content .= '<option value="' . (int)$a['id'] . '">' . e((string)$a['name']) . '</option>';
    }
    $content .= '</select></div>';
    $content .= '<div class="row"><div style="flex:1"><label>Center lat (optional)</label><input name="center_lat" type="number" step="0.000001"></div><div style="flex:1"><label>Center lng (optional)</label><input name="center_lng" type="number" step="0.000001"></div><div style="flex:1"><label>Radius km (optional)</label><input name="radius_km" type="number" step="0.1"></div></div>';
    $content .= '<button class="btn primary" type="submit">Create route</button>';
    $content .= '</form></div>';
    $content .= '</div>';
    renderLayout('Hub Routes', $u, $content);
}

function actionHubCreateRoute(PDO $pdo, array $u): void
{
    requireRole($u, ['hub']);
    csrfCheck();
    $hubId = (int)$u['hub_id'];
    try {
        $name = postText('name', 80);
        $keywordsRaw = postText('keywords', 500);
        $assignedAgent = nullablePositiveInt((string)($_POST['assigned_agent_id'] ?? ''));
        $centerLat = parseOptionalFloat((string)($_POST['center_lat'] ?? ''), -90, 90);
        $centerLng = parseOptionalFloat((string)($_POST['center_lng'] ?? ''), -180, 180);
        $radiusKm = parseOptionalFloat((string)($_POST['radius_km'] ?? ''), 0, 1000);
        assertHubOwnsAgentAndRoute($pdo, $hubId, $assignedAgent, null);
    } catch (UserSafeException $e) {
        flash('err', $e->getMessage());
        redirect('hub_routes');
    }

    if ($name === '' || $keywordsRaw === '') {
        flash('err', 'Name and keywords required');
        redirect('hub_routes');
    }

    $keywords = parseCsvKeywords($keywordsRaw);
    if (count($keywords) === 0) {
        flash('err', 'At least one keyword is required');
        redirect('hub_routes');
    }

    $pdo->beginTransaction();
    try {
        $idem = beginIdempotentAction($pdo, $u, 'hub_create_route', [
            'hub_id' => $hubId,
            'name' => $name,
            'keywords' => $keywords,
            'assigned_agent_id' => $assignedAgent,
            'center_lat' => $centerLat,
            'center_lng' => $centerLng,
            'radius_km' => $radiusKm,
        ]);
        if (!empty($idem['duplicate'])) {
            redirectDuplicateAction($pdo, $idem, 'hub_routes');
        }

        $createdAt = nowIso();
        $pdo->prepare("INSERT INTO routes (hub_id,name,match_keywords,assigned_agent_id,active,center_lat,center_lng,radius_km,created_at) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$hubId, $name, json_encode($keywords, JSON_UNESCAPED_UNICODE), $assignedAgent, 1, $centerLat, $centerLng, $radiusKm, $createdAt]);
        $routeId = (int)$pdo->lastInsertId();
        addAuditLog(
            $pdo,
            $u,
            'route_run_update',
            'run',
            $routeId,
            ['exists' => false],
            [
                'hub_id' => $hubId,
                'name' => $name,
                'match_keywords' => $keywords,
                'assigned_agent_id' => $assignedAgent,
                'active' => 1,
                'center_lat' => $centerLat,
                'center_lng' => $centerLng,
                'radius_km' => $radiusKm,
            ],
            'Route created'
        );
        completeIdempotentAction($pdo, $u, $idem, 'hub_routes');
        $pdo->commit();
        flash('ok', 'Route created');
    } catch (UserSafeException $e) {
        $pdo->rollBack();
        flash('err', $e->getMessage());
    } catch (Throwable $e) {
        $pdo->rollBack();
        logAppError($e, ['action' => 'hub_create_route']);
        flash('err', 'Route could not be created.');
    }
    redirect('hub_routes');
}

function pageScan(PDO $pdo, array $u): void
{
    requireRole($u, ['hub', 'agent', 'admin']);
    $tracking = preg_replace('/[^A-Za-z0-9_-]/', '', queryText('code', 64)) ?? '';
    $parcel = null;
    if ($tracking !== '') {
        $st = $pdo->prepare("SELECT p.*, a.name AS agent_name, h.name AS hub_name FROM parcels p LEFT JOIN agents a ON a.id=p.assigned_agent_id LEFT JOIN hubs h ON h.id=p.current_hub_id WHERE p.tracking_code=?");
        $st->execute([$tracking]);
        $parcel = $st->fetch();
        if ($parcel && !canAccessParcel($u, $parcel)) {
            $parcel = null;
            flash('err', 'Parcel is not visible to your account');
        }
    }

    $content = '<div class="grid cols2">';
    $content .= '<div class="card"><div class="h1">Scan / Add Event</div>';
    $content .= '<form method="get" class="row" style="margin:10px 0"><input type="hidden" name="r" value="scan"><input name="code" value="' . e($tracking) . '" placeholder="Tracking code" style="min-width:260px" required><button class="btn" type="submit">Find</button></form>';
    if (!$parcel) {
        $content .= '<div class="muted">Enter a tracking code to capture an event.</div>';
        $content .= '</div><div class="card"><div class="h1">Notes</div><div class="muted">Hub staff can record hub arrivals/departures and warehouse steps. Agents should use parcel detail for step-by-step status updates.</div></div></div>';
        renderLayout('Scan', $u, $content);
        return;
    }

    $content .= '<div class="card" style="padding:10px;margin-top:10px">'
        . '<div><strong>' . e((string)$parcel['tracking_code']) . '</strong> ' . statusPill((string)$parcel['status']) . '</div>'
        . '<div class="muted" style="font-size:12px">Current hub: ' . e((string)($parcel['hub_name'] ?? '')) . ' · Assigned agent: ' . e((string)($parcel['agent_name'] ?? '')) . '</div>'
        . '</div>';

    $content .= '<form method="post" class="form" style="margin-top:10px"><input type="hidden" name="csrf" value="' . e(csrfToken()) . '">' . idempotencyInput() . '<input type="hidden" name="action" value="record_event"><input type="hidden" name="parcel_id" value="' . (int)$parcel['id'] . '">';
    $content .= '<div><label>Event type</label><select name="event_type" required>';
    foreach (['hub_arrived','hub_departed','in_warehouse','out_for_delivery','returned','failed','note_only','payment_collected','delivered','picked_up','en_route'] as $c) {
        $content .= '<option value="' . e($c) . '">' . e($c) . '</option>';
    }
    $content .= '</select></div>';
    $content .= '<div class="row"><div style="flex:1"><label>Lat (optional)</label><input name="lat" type="number" step="0.000001"></div><div style="flex:1"><label>Lng (optional)</label><input name="lng" type="number" step="0.000001"></div></div>';
    $content .= '<div><label>Note</label><textarea name="note" placeholder="Optional"></textarea></div>';
    $content .= '<button class="btn primary" type="submit">Record event</button>';
    $content .= '</form>';
    $content .= '</div>';

    $content .= '<div class="card"><div class="h1">Recent events</div>';
    $ev = $pdo->prepare("SELECT e.*, h.name AS hub_name FROM events e LEFT JOIN hubs h ON h.id=e.hub_id WHERE e.parcel_id=? ORDER BY e.ts DESC LIMIT 20");
    $ev->execute([(int)$parcel['id']]);
    $content .= '<table class="table"><thead><tr><th>Time</th><th>Event</th><th>Hub</th><th>Note</th></tr></thead><tbody>';
    foreach ($ev->fetchAll() as $r) {
        $content .= '<tr><td>' . e((string)$r['ts']) . '</td><td>' . e((string)$r['event_type']) . '</td><td>' . e((string)($r['hub_name'] ?? '')) . '</td><td class="muted">' . e((string)($r['note'] ?? '')) . '</td></tr>';
    }
    $content .= '</tbody></table></div>';
    $content .= '</div>';
    renderLayout('Scan', $u, $content);
}

function actionRecordEvent(PDO $pdo, array $u): void
{
    requireRole($u, ['hub', 'agent', 'admin']);
    csrfCheck();
    try {
        $parcelId = postPositiveInt('parcel_id');
        $eventType = postText('event_type', 40);
        $note = postText('note', 500);
        $lat = parseOptionalFloat((string)($_POST['lat'] ?? ''), -90, 90);
        $lng = parseOptionalFloat((string)($_POST['lng'] ?? ''), -180, 180);
        if (!in_array($eventType, allowedEventTypes(), true)) {
            throw new UserSafeException('Invalid event type');
        }
    } catch (UserSafeException $e) {
        flash('err', $e->getMessage());
        redirect('scan');
    }

    $st = $pdo->prepare("SELECT p.* FROM parcels p WHERE p.id=?");
    $st->execute([$parcelId]);
    $p = $st->fetch();
    if (!$p) {
        flash('err', 'Parcel not found');
        redirect('scan');
    }
    if (!canAccessParcel($u, $p)) {
        flash('err', 'Parcel is not visible to your account');
        redirect('scan');
    }

    $hubId = null;
    $agentId = null;
    $routeId = $p['route_id'] !== null ? (int)$p['route_id'] : null;
    $userType = $u['role'] === 'admin' ? 'system' : (string)$u['role'];

    if ($u['role'] === 'hub') {
        $hubId = (int)$u['hub_id'];
    }
    if ($u['role'] === 'agent') {
        $agentId = (int)$u['agent_id'];
        $hubId = $p['current_hub_id'] !== null ? (int)$p['current_hub_id'] : null;
    }

    $now = nowIso();
    $pdo->beginTransaction();
    try {
        $idem = beginIdempotentAction($pdo, $u, 'record_event', [
            'parcel_id' => $parcelId,
            'event_type' => $eventType,
            'note' => $note,
            'lat' => $lat,
            'lng' => $lng,
        ]);
        if (!empty($idem['duplicate'])) {
            redirectDuplicateAction($pdo, $idem, 'scan', ['code' => (string)$p['tracking_code']]);
        }

        $statusUpdate = null;
        $setHub = null;

        if ($eventType === 'hub_arrived' && $u['role'] === 'hub') {
            $setHub = (int)$u['hub_id'];
        }
        if ($eventType === 'hub_departed' && $u['role'] === 'hub') {
            $setHub = (int)$u['hub_id'];
        }

        if (in_array($eventType, ['en_route','picked_up','in_warehouse','out_for_delivery','delivered','failed','returned'], true)) {
            $statusUpdate = $eventType;
        }
        if ($u['role'] === 'agent' && $statusUpdate !== null && !in_array($eventType, allowedNextStatuses((string)$p['status']), true)) {
            throw new UserSafeException('Invalid transition');
        }

        if ($eventType === 'payment_collected') {
            addEvent($pdo, $parcelId, $userType, (int)$u['id'], $agentId, $hubId, $routeId, 'payment_collected', $note !== '' ? $note : 'Payment collected', $lat, $lng);
        }

        if ($statusUpdate !== null) {
            $pdo->prepare("UPDATE parcels SET status=?, updated_at=? WHERE id=?")
                ->execute([$statusUpdate, $now, $parcelId]);
        } else {
            $pdo->prepare("UPDATE parcels SET updated_at=? WHERE id=?")
                ->execute([$now, $parcelId]);
        }

        if ($setHub !== null) {
            $pdo->prepare("UPDATE parcels SET current_hub_id=?, updated_at=? WHERE id=?")
                ->execute([$setHub, $now, $parcelId]);
            $hubId = $setHub;
        }

        if ($eventType !== 'payment_collected') {
            addEvent($pdo, $parcelId, $userType, (int)$u['id'], $agentId, $hubId, $routeId, $eventType, $note, $lat, $lng);
        }
        if ($statusUpdate !== null || $setHub !== null) {
            addAuditLog(
                $pdo,
                $u,
                'parcel_metadata_update',
                'parcel',
                $parcelId,
                [
                    'status' => (string)$p['status'],
                    'current_hub_id' => $p['current_hub_id'] !== null ? (int)$p['current_hub_id'] : null,
                ],
                [
                    'status' => $statusUpdate ?? (string)$p['status'],
                    'current_hub_id' => $setHub ?? ($p['current_hub_id'] !== null ? (int)$p['current_hub_id'] : null),
                ],
                'Scan event updated parcel custody'
            );
        }
        completeIdempotentAction($pdo, $u, $idem, 'scan', ['code' => (string)$p['tracking_code']]);
        $pdo->commit();
        flash('ok', 'Event recorded');
    } catch (UserSafeException $e) {
        $pdo->rollBack();
        flash('err', $e->getMessage());
    } catch (Throwable $e) {
        $pdo->rollBack();
        logAppError($e, ['action' => 'record_event']);
        flash('err', 'Event could not be recorded.');
    }

    redirect('scan', ['code' => (string)$p['tracking_code']]);
}

function allowedNextStatuses(string $current): array
{
    return match ($current) {
        'assigned' => ['en_route'],
        'en_route' => ['picked_up'],
        'picked_up' => ['in_warehouse'],
        'in_warehouse' => ['out_for_delivery'],
        'out_for_delivery' => ['delivered', 'failed', 'returned'],
        default => [],
    };
}

function pageAgentRun(PDO $pdo, array $u): void
{
    requireRole($u, ['agent']);
    $agentId = (int)$u['agent_id'];
    $st = $pdo->prepare("SELECT p.*, c.name AS customer_name FROM parcels p JOIN customers c ON c.id=p.customer_id WHERE p.assigned_agent_id=? AND p.status NOT IN ('delivered','failed','returned') ORDER BY p.updated_at ASC LIMIT 200");
    $st->execute([$agentId]);
    $rows = $st->fetchAll();

    $content = '<div class="grid cols2">';
    $content .= '<div class="card"><div class="h1">My Run</div><div class="muted">Open a parcel to update its next step.</div>';
    if (count($rows) === 0) {
        $content .= '<div class="muted" style="margin-top:10px">No assigned parcels right now.</div>';
    } else {
        $content .= '<table class="table"><thead><tr><th>Tracking</th><th>Status</th><th>Pickup</th><th>Dropoff</th><th>COD</th><th>Customer</th></tr></thead><tbody>';
        foreach ($rows as $p) {
            $content .= '<tr>';
            $content .= '<td><a href="?r=parcel&id=' . (int)$p['id'] . '">' . e((string)$p['tracking_code']) . '</a></td>';
            $content .= '<td>' . statusPill((string)$p['status']) . '</td>';
            $content .= '<td class="muted">' . e((string)$p['pickup_address']) . '</td>';
            $content .= '<td class="muted">' . e((string)$p['dropoff_address']) . '</td>';
            $content .= '<td>' . e(formatMoney((int)$p['amount_cents'])) . '</td>';
            $content .= '<td class="muted">' . e((string)$p['customer_name']) . '</td>';
            $content .= '</tr>';
        }
        $content .= '</tbody></table>';
    }
    $content .= '</div>';

    $content .= '<div class="card"><div class="h1">Quick links</div><div class="row">'
        . '<a class="btn" href="?r=scan">Scan / Add Event</a>'
        . '<a class="btn" href="?r=track">Public Tracking</a>'
        . '</div></div>';
    $content .= '</div>';
    renderLayout('Agent Run', $u, $content);
}

function pageParcelDetail(PDO $pdo, array $u): void
{
    $id = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare("SELECT p.*, c.name AS customer_name, a.name AS agent_name, r.name AS route_name,
        hp.name AS pickup_hub_name, hw.name AS warehouse_hub_name, hd.name AS delivery_hub_name, hc.name AS current_hub_name
        FROM parcels p
        JOIN customers c ON c.id=p.customer_id
        LEFT JOIN agents a ON a.id=p.assigned_agent_id
        LEFT JOIN routes r ON r.id=p.route_id
        LEFT JOIN hubs hp ON hp.id=p.hub_pickup_id
        LEFT JOIN hubs hw ON hw.id=p.hub_warehouse_id
        LEFT JOIN hubs hd ON hd.id=p.hub_delivery_id
        LEFT JOIN hubs hc ON hc.id=p.current_hub_id
        WHERE p.id=?");
    $st->execute([$id]);
    $p = $st->fetch();
    if (!$p) {
        http_response_code(404);
        echo 'Not found';
        return;
    }

    $role = (string)$u['role'];
    if (!canAccessParcel($u, $p)) {
        http_response_code(403);
        echo 'Forbidden';
        return;
    }

    $events = $pdo->prepare("SELECT e.*, h.name AS hub_name, ag.name AS agent_name
        FROM events e
        LEFT JOIN hubs h ON h.id=e.hub_id
        LEFT JOIN agents ag ON ag.id=e.agent_id
        WHERE e.parcel_id=? ORDER BY e.ts DESC LIMIT 120");
    $events->execute([(int)$p['id']]);
    $evs = $events->fetchAll();

    $hubPathStmt = $pdo->prepare("SELECT e.ts, e.hub_id, h.name AS hub_name
        FROM events e LEFT JOIN hubs h ON h.id=e.hub_id
        WHERE e.parcel_id=? AND e.hub_id IS NOT NULL
        ORDER BY e.ts ASC");
    $hubPathStmt->execute([(int)$p['id']]);
    $hpRows = $hubPathStmt->fetchAll();
    $hubPath = [];
    $order = [];
    foreach ($hpRows as $r) {
        $hid = (string)($r['hub_id'] ?? '');
        if ($hid === '') {
            continue;
        }
        if (!isset($hubPath[$hid])) {
            $hubPath[$hid] = ['hub_name' => $r['hub_name'] ?: ('Hub #' . $hid), 'first_ts' => $r['ts']];
            $order[] = $hid;
        }
        $hubPath[$hid]['last_ts'] = $r['ts'];
    }

    $content = '<div class="grid cols2">';
    $content .= '<div class="card">'
        . '<div class="row" style="justify-content:space-between;align-items:flex-start">'
        . '<div><div class="h1">' . e((string)$p['tracking_code']) . '</div>'
        . '<div class="muted">Customer: ' . e((string)$p['customer_name']) . '</div></div>'
        . '<div style="text-align:right">' . statusPill((string)$p['status']) . '<div class="muted" style="font-size:12px">Updated ' . e((string)$p['updated_at']) . '</div></div>'
        . '</div>';
    $content .= '<div class="row" style="margin-top:10px">'
        . ($p['current_hub_name'] ? '<span class="pill">Current hub: ' . e((string)$p['current_hub_name']) . '</span>' : '')
        . ($p['route_name'] ? '<span class="pill">Route: ' . e((string)$p['route_name']) . '</span>' : '')
        . ($p['agent_name'] ? '<span class="pill">Agent: ' . e((string)$p['agent_name']) . '</span>' : '')
        . '<span class="pill">COD: ' . e(formatMoney((int)$p['amount_cents'])) . '</span>'
        . '</div>';
    $pickupAddr = (string)$p['pickup_address'];
    $dropoffAddr = (string)$p['dropoff_address'];
    $content .= '<div style="margin-top:12px"><div class="muted" style="font-size:12px;margin-bottom:6px">Pickup address</div><div>' . e($pickupAddr) . '</div><div style="margin-top:4px"><a href="' . e(mapsUrl($pickupAddr)) . '" target="_blank" rel="noopener">Open in Maps</a></div></div>';
    $content .= '<div style="margin-top:12px"><div class="muted" style="font-size:12px;margin-bottom:6px">Dropoff address</div><div>' . e($dropoffAddr) . '</div><div style="margin-top:4px"><a href="' . e(mapsUrl($dropoffAddr)) . '" target="_blank" rel="noopener">Open in Maps</a></div></div>';

    $content .= '<div class="card" style="padding:10px;margin-top:12px"><div class="muted" style="font-size:12px;margin-bottom:6px">Hub chain</div>';
    if (count($order) === 0) {
        $content .= '<div class="muted">No hub updates yet.</div>';
    } else {
        foreach ($order as $hid) {
            $h = $hubPath[$hid];
            $content .= '<div style="padding:6px 0;border-bottom:1px solid var(--border)"><div><strong>' . e((string)$h['hub_name']) . '</strong></div><div class="muted" style="font-size:12px">' . e((string)$h['first_ts']) . (isset($h['last_ts']) ? ' → ' . e((string)$h['last_ts']) : '') . '</div></div>';
        }
    }
    $content .= '</div>';

    if ($role === 'hub') {
        $content .= '<div class="row" style="margin-top:12px"><a class="btn" href="?r=scan&code=' . e((string)$p['tracking_code']) . '">Scan / Add Event</a><a class="btn" href="?r=track&code=' . e((string)$p['tracking_code']) . '">Public Tracking</a></div>';
    }

    if ($role === 'customer') {
        $meta = json_decode((string)$p['metadata'], true);
        $meta = is_array($meta) ? $meta : [];
        if ((string)$p['status'] === 'delivered' && empty($meta['customer_confirmed_at'])) {
            $content .= '<div class="card" style="margin-top:12px"><div class="h1">Confirm receipt</div>';
            $content .= '<form method="post" class="row"><input type="hidden" name="csrf" value="' . e(csrfToken()) . '">' . idempotencyInput() . '<input type="hidden" name="action" value="customer_confirm"><input type="hidden" name="parcel_id" value="' . (int)$p['id'] . '"><button class="btn primary" type="submit">Confirm delivered</button></form>';
            $content .= '</div>';
        }
        if (!empty($meta['customer_confirmed_at'])) {
            $content .= '<div class="muted" style="margin-top:10px">Confirmed at: ' . e((string)$meta['customer_confirmed_at']) . '</div>';
        }
    }

    $content .= '</div>';

    $right = '<div class="card"><div class="h1">Timeline</div>';
    $right .= '<table class="table"><thead><tr><th>Time</th><th>Event</th><th>Hub</th><th>Actor</th><th>Note</th></tr></thead><tbody>';
    foreach ($evs as $ev) {
        $actor = (string)($ev['user_type'] ?? '');
        $actorName = '';
        if ($actor === 'agent') {
            $actorName = (string)($ev['agent_name'] ?? '');
        }
        $right .= '<tr><td>' . e((string)$ev['ts']) . '</td><td>' . e((string)$ev['event_type']) . '</td><td>' . e((string)($ev['hub_name'] ?? '')) . '</td><td class="muted">' . e($actorName !== '' ? ($actor . ':' . $actorName) : $actor) . '</td><td class="muted">' . e((string)($ev['note'] ?? '')) . '</td></tr>';
    }
    $right .= '</tbody></table>';

    if ($role === 'agent' && (int)$u['agent_id'] === (int)$p['assigned_agent_id']) {
        $next = allowedNextStatuses((string)$p['status']);
        $right .= '<div class="card" style="margin-top:12px"><div class="h1">Update status</div>';
        if (count($next) === 0) {
            $right .= '<div class="muted">No next step available for this status.</div>';
        } else {
            $right .= '<form method="post" class="form"><input type="hidden" name="csrf" value="' . e(csrfToken()) . '">' . idempotencyInput() . '<input type="hidden" name="action" value="agent_step"><input type="hidden" name="parcel_id" value="' . (int)$p['id'] . '">';
            $right .= '<div><label>Next status</label><select name="to_status">';
            foreach ($next as $n) {
                $right .= '<option value="' . e($n) . '">' . e($n) . '</option>';
            }
            $right .= '</select></div>';
            $right .= '<div class="row"><div style="flex:1"><label>Lat (optional)</label><input name="lat" type="number" step="0.000001"></div><div style="flex:1"><label>Lng (optional)</label><input name="lng" type="number" step="0.000001"></div></div>';
            $right .= '<div><label>Note</label><textarea name="note" placeholder="Optional"></textarea></div>';
            $right .= '<div class="row">'
                . '<button class="btn primary" type="submit">Submit</button>';
            if ((int)$p['amount_cents'] > 0) {
                $right .= '<button class="btn" type="submit" name="also_payment" value="1">Submit + payment collected</button>';
            }
            $right .= '</div></form>';
        }
        $right .= '</div>';
    }

    $right .= '<div class="muted" style="margin-top:10px">Auto-refreshes every 15 seconds while open.</div>';
    $right .= '</div>';
    $content .= $right;
    $content .= '</div>';
    renderLayout('Parcel', $u, $content, ['refresh' => 15]);
}

function actionAgentStep(PDO $pdo, array $u): void
{
    requireRole($u, ['agent']);
    csrfCheck();
    try {
        $parcelId = postPositiveInt('parcel_id');
        $toStatus = postText('to_status', 40);
        $note = postText('note', 500);
        $alsoPayment = (string)($_POST['also_payment'] ?? '') === '1';
        $lat = parseOptionalFloat((string)($_POST['lat'] ?? ''), -90, 90);
        $lng = parseOptionalFloat((string)($_POST['lng'] ?? ''), -180, 180);
    } catch (UserSafeException $e) {
        flash('err', $e->getMessage());
        redirect('agent_run');
    }

    $agentId = (int)$u['agent_id'];

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM parcels WHERE id=?");
        $st->execute([$parcelId]);
        $p = $st->fetch();
        if (!$p) {
            throw new UserSafeException('Parcel not found');
        }
        if ((int)$p['assigned_agent_id'] !== $agentId) {
            throw new UserSafeException('Not assigned to you');
        }
        $current = (string)$p['status'];
        $allowed = allowedNextStatuses($current);
        if (!in_array($toStatus, $allowed, true)) {
            throw new UserSafeException('Invalid transition');
        }

        $idem = beginIdempotentAction($pdo, $u, 'agent_step', [
            'parcel_id' => $parcelId,
            'to_status' => $toStatus,
            'also_payment' => $alsoPayment,
            'note' => $note,
            'lat' => $lat,
            'lng' => $lng,
        ]);
        if (!empty($idem['duplicate'])) {
            redirectDuplicateAction($pdo, $idem, 'parcel', ['id' => (string)$parcelId]);
        }

        $now = nowIso();
        $upd = $pdo->prepare("UPDATE parcels SET status=?, updated_at=? WHERE id=? AND status=? AND assigned_agent_id=?");
        $upd->execute([$toStatus, $now, $parcelId, $current, $agentId]);
        if ($upd->rowCount() !== 1) {
            throw new UserSafeException('Parcel changed; retry');
        }

        $routeId = $p['route_id'] !== null ? (int)$p['route_id'] : null;
        $hubId = $p['current_hub_id'] !== null ? (int)$p['current_hub_id'] : null;

        addEvent($pdo, $parcelId, 'agent', (int)$u['id'], $agentId, $hubId, $routeId, $toStatus, $note, $lat, $lng);
        if ($alsoPayment && (int)$p['amount_cents'] > 0) {
            addEvent($pdo, $parcelId, 'agent', (int)$u['id'], $agentId, $hubId, $routeId, 'payment_collected', $note !== '' ? $note : 'Payment collected', $lat, $lng);
        }

        addAuditLog(
            $pdo,
            $u,
            'parcel_metadata_update',
            'parcel',
            $parcelId,
            ['status' => $current],
            ['status' => $toStatus],
            'Agent status step'
        );
        completeIdempotentAction($pdo, $u, $idem, 'parcel', ['id' => (string)$parcelId]);
        $pdo->commit();
        flash('ok', 'Updated');
    } catch (UserSafeException $e) {
        $pdo->rollBack();
        flash('err', $e->getMessage());
    } catch (Throwable $e) {
        $pdo->rollBack();
        logAppError($e, ['action' => 'agent_step']);
        flash('err', 'Status could not be updated.');
    }
    redirect('parcel', ['id' => (string)$parcelId]);
}

function pageSettlements(PDO $pdo, array $u): void
{
    requireRole($u, ['hub']);
    $hubId = (int)$u['hub_id'];
    $rows = $pdo->prepare("SELECT p.*, c.name AS customer_name
        FROM parcels p
        JOIN customers c ON c.id=p.customer_id
        WHERE (p.hub_pickup_id=? OR p.hub_warehouse_id=? OR p.hub_delivery_id=? OR p.current_hub_id=?)
          AND p.amount_cents > 0
        ORDER BY p.updated_at DESC LIMIT 300");
    $rows->execute([$hubId, $hubId, $hubId, $hubId]);
    $ps = $rows->fetchAll();

    $content = '<div class="card"><div class="h1">Settlements</div><div class="muted">Mark parcels as settled after reconciliation.</div>';
    $content .= '<table class="table"><thead><tr><th>Tracking</th><th>Customer</th><th>Amount</th><th>Status</th><th>Settled</th><th>Action</th></tr></thead><tbody>';
    foreach ($ps as $p) {
        $content .= '<tr>';
        $content .= '<td><a href="?r=parcel&id=' . (int)$p['id'] . '">' . e((string)$p['tracking_code']) . '</a></td>';
        $content .= '<td class="muted">' . e((string)$p['customer_name']) . '</td>';
        $content .= '<td>' . e(formatMoney((int)$p['amount_cents'])) . '</td>';
        $content .= '<td>' . statusPill((string)$p['status']) . '</td>';
        $content .= '<td>' . ((int)$p['cod_settled'] === 1 ? '<span class="pill bg-green">yes</span>' : '<span class="pill bg-amber">no</span>') . '</td>';
        $content .= '<td>';
        if ((int)$p['cod_settled'] === 0) {
            $content .= '<form method="post"><input type="hidden" name="csrf" value="' . e(csrfToken()) . '">' . idempotencyInput() . '<input type="hidden" name="action" value="settle"><input type="hidden" name="parcel_id" value="' . (int)$p['id'] . '"><button class="btn" type="submit">Mark settled</button></form>';
        } else {
            $content .= '<span class="muted">—</span>';
        }
        $content .= '</td>';
        $content .= '</tr>';
    }
    $content .= '</tbody></table></div>';
    renderLayout('Settlements', $u, $content);
}

function actionSettle(PDO $pdo, array $u): void
{
    requireRole($u, ['hub']);
    csrfCheck();
    $hubId = (int)$u['hub_id'];
    try {
        $pid = postPositiveInt('parcel_id');
    } catch (UserSafeException $e) {
        flash('err', $e->getMessage());
        redirect('settlements');
    }
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM parcels WHERE id=?");
        $st->execute([$pid]);
        $p = $st->fetch();
        if (!$p) {
            throw new UserSafeException('Parcel not found');
        }
        if (!parcelTouchesHub($p, $hubId)) {
            throw new UserSafeException('Not visible to this hub');
        }
        $idem = beginIdempotentAction($pdo, $u, 'settle', [
            'parcel_id' => $pid,
        ]);
        if (!empty($idem['duplicate'])) {
            redirectDuplicateAction($pdo, $idem, 'settlements');
        }

        $upd = $pdo->prepare("UPDATE parcels SET cod_settled=1, updated_at=? WHERE id=? AND cod_settled=0");
        $upd->execute([nowIso(), $pid]);
        if ($upd->rowCount() !== 1) {
            throw new UserSafeException('Already settled');
        }
        addEvent($pdo, $pid, 'hub', (int)$u['id'], null, $hubId, $p['route_id'] !== null ? (int)$p['route_id'] : null, 'settlement_marked', 'COD marked settled', null, null);
        addAuditLog(
            $pdo,
            $u,
            'cod_ledger_adjustment',
            'cod_ledger',
            $pid,
            [
                'parcel_id' => $pid,
                'cod_settled' => (int)$p['cod_settled'],
            ],
            [
                'parcel_id' => $pid,
                'cod_settled' => 1,
            ],
            'COD marked settled'
        );
        completeIdempotentAction($pdo, $u, $idem, 'settlements');
        $pdo->commit();
        flash('ok', 'Marked settled');
    } catch (UserSafeException $e) {
        $pdo->rollBack();
        flash('err', $e->getMessage());
    } catch (Throwable $e) {
        $pdo->rollBack();
        logAppError($e, ['action' => 'settle']);
        flash('err', 'Settlement could not be updated.');
    }
    redirect('settlements');
}

function pageAdmin(PDO $pdo, array $u): void
{
    requireRole($u, ['admin']);
    $tab = queryText('tab', 20);
    if (!in_array($tab, ['overview','users','hubs','agents','customers','audit'], true)) {
        $tab = 'overview';
    }

    $content = '<div class="card"><div class="h1">Admin</div><div class="row" style="margin-top:8px">'
        . '<a class="btn" href="?r=admin&tab=overview">Overview</a>'
        . '<a class="btn" href="?r=admin&tab=users">Users</a>'
        . '<a class="btn" href="?r=admin&tab=hubs">Hubs</a>'
        . '<a class="btn" href="?r=admin&tab=agents">Agents</a>'
        . '<a class="btn" href="?r=admin&tab=customers">Customers</a>'
        . '<a class="btn" href="?r=admin&tab=audit">Audit</a>'
        . '</div></div>';

    if ($tab === 'overview') {
        $k1 = (int)$pdo->query("SELECT COUNT(*) FROM parcels")->fetchColumn();
        $k2 = (int)$pdo->query("SELECT COUNT(*) FROM hubs")->fetchColumn();
        $k3 = (int)$pdo->query("SELECT COUNT(*) FROM agents")->fetchColumn();
        $k4 = (int)$pdo->query("SELECT COUNT(*) FROM events")->fetchColumn();
        $content .= '<div class="card"><div class="h1">System overview</div><div class="kpis">'
            . '<div class="kpi"><div class="n">' . $k1 . '</div><div class="l">Parcels</div></div>'
            . '<div class="kpi"><div class="n">' . $k2 . '</div><div class="l">Hubs</div></div>'
            . '<div class="kpi"><div class="n">' . $k3 . '</div><div class="l">Agents</div></div>'
            . '<div class="kpi"><div class="n">' . $k4 . '</div><div class="l">Events</div></div>'
            . '</div></div>';
    }

    if ($tab === 'users') {
        $users = $pdo->query("SELECT u.*, h.name AS hub_name, a.name AS agent_name, c.name AS customer_name
            FROM users u
            LEFT JOIN hubs h ON h.id=u.hub_id
            LEFT JOIN agents a ON a.id=u.agent_id
            LEFT JOIN customers c ON c.id=u.customer_id
            ORDER BY u.active DESC, u.role, u.email")->fetchAll();
        $content .= '<div class="card"><div class="h1">User access control</div><div class="muted">Create a production admin first, then reset or disable every seeded login before real operations.</div>';
        $content .= '<table class="table"><thead><tr><th>User</th><th>Role</th><th>Scope</th><th>Status</th><th>Access action</th></tr></thead><tbody>';
        foreach ($users as $row) {
            $email = (string)$row['email'];
            $role = (string)$row['role'];
            $scopeParts = [];
            if (!empty($row['hub_name'])) {
                $scopeParts[] = 'Hub: ' . (string)$row['hub_name'];
            }
            if (!empty($row['agent_name'])) {
                $scopeParts[] = 'Agent: ' . (string)$row['agent_name'];
            }
            if (!empty($row['customer_name'])) {
                $scopeParts[] = 'Customer: ' . (string)$row['customer_name'];
            }
            $scope = $scopeParts ? implode(' / ', $scopeParts) : 'Global';
            $isActive = (int)$row['active'] === 1;
            $seededTag = isSeededAccountEmail($email) ? '<div><span class="pill bg-red">seeded</span></div>' : '';
            $content .= '<tr>'
                . '<td>' . e($email) . $seededTag . '<div class="muted" style="font-size:12px">User #' . (int)$row['id'] . '</div></td>'
                . '<td>' . e(roleLabel($role)) . '</td>'
                . '<td class="muted">' . e($scope) . '</td>'
                . '<td>' . ($isActive ? '<span class="pill bg-green">active</span>' : '<span class="pill bg-red">disabled</span>') . '</td>'
                . '<td><form method="post" class="form"><input type="hidden" name="csrf" value="' . e(csrfToken()) . '">' . idempotencyInput()
                . '<input type="hidden" name="action" value="admin_update_user">'
                . '<input type="hidden" name="user_id" value="' . (int)$row['id'] . '">'
                . '<div><label>New password</label><input name="password" type="password" placeholder="Leave blank to keep"></div>'
                . '<div><label>Status</label><select name="active"><option value="1"' . ($isActive ? ' selected' : '') . '>active</option><option value="0"' . (!$isActive ? ' selected' : '') . '>disabled</option></select></div>'
                . '<div><label>Reason</label><input name="reason" value="' . (isSeededAccountEmail($email) ? 'Post-deploy seeded credential rotation' : '') . '" required></div>'
                . '<button class="btn" type="submit">Update access</button>'
                . '</form></td>'
                . '</tr>';
        }
        $content .= '</tbody></table></div>';

        $content .= '<div class="card"><div class="h1">Create production admin</div><div class="muted">Use this before disabling <code>admin@hubroute.local</code>.</div>'
            . '<form method="post" class="form"><input type="hidden" name="csrf" value="' . e(csrfToken()) . '">' . idempotencyInput()
            . '<input type="hidden" name="action" value="admin_create_user">'
            . '<div><label>Admin email</label><input name="email" type="email" required></div>'
            . '<div><label>Temporary password</label><input name="password" type="password" required></div>'
            . '<button class="btn primary" type="submit">Create admin</button>'
            . '</form></div>';
    }

    if ($tab === 'hubs') {
        $hubs = $pdo->query("SELECT * FROM hubs ORDER BY id")->fetchAll();
        $content .= '<div class="grid cols2">';
        $content .= '<div class="card"><div class="h1">Hubs</div><table class="table"><thead><tr><th>Name</th><th>Type</th><th>City</th><th>Auto</th></tr></thead><tbody>';
        foreach ($hubs as $h) {
            $content .= '<tr><td>' . e((string)$h['name']) . '</td><td>' . e((string)$h['type']) . '</td><td class="muted">' . e((string)($h['city'] ?? '')) . '</td><td>' . ((int)$h['auto_assign'] === 1 ? 'yes' : 'no') . '</td></tr>';
        }
        $content .= '</tbody></table></div>';
        $content .= '<div class="card"><div class="h1">Create hub</div><form method="post" class="form"><input type="hidden" name="csrf" value="' . e(csrfToken()) . '">' . idempotencyInput() . '<input type="hidden" name="action" value="admin_create_hub">'
            . '<div><label>Name</label><input name="name" required></div>'
            . '<div><label>Type</label><select name="type" required><option value="pickup">pickup</option><option value="warehouse">warehouse</option><option value="lastmile">lastmile</option></select></div>'
            . '<div><label>Address</label><input name="address"></div>'
            . '<div><label>City</label><input name="city"></div>'
            . '<div><label>Coverage keywords/postcodes (comma-separated)</label><input name="coverage" placeholder="Northside, 1000"></div>'
            . '<div><label>Auto-assign</label><select name="auto_assign"><option value="1">true</option><option value="0">false</option></select></div>'
            . '<button class="btn primary" type="submit">Create</button>'
            . '</form></div>';
        $content .= '</div>';
    }

    if ($tab === 'agents') {
        $agents = $pdo->query("SELECT a.*, h.name AS hub_name FROM agents a JOIN hubs h ON h.id=a.hub_id ORDER BY a.id")->fetchAll();
        $hubs = $pdo->query("SELECT * FROM hubs ORDER BY id")->fetchAll();
        $content .= '<div class="grid cols2">';
        $content .= '<div class="card"><div class="h1">Agents</div><table class="table"><thead><tr><th>Name</th><th>Hub</th><th>Role</th><th>Active</th></tr></thead><tbody>';
        foreach ($agents as $a) {
            $content .= '<tr><td>' . e((string)$a['name']) . '</td><td class="muted">' . e((string)$a['hub_name']) . '</td><td>' . e((string)$a['role']) . '</td><td>' . ((int)$a['active'] === 1 ? 'yes' : 'no') . '</td></tr>';
        }
        $content .= '</tbody></table></div>';
        $content .= '<div class="card"><div class="h1">Create agent + login</div><form method="post" class="form"><input type="hidden" name="csrf" value="' . e(csrfToken()) . '">' . idempotencyInput() . '<input type="hidden" name="action" value="admin_create_agent">'
            . '<div><label>Name</label><input name="name" required></div>'
            . '<div><label>Phone</label><input name="phone"></div>'
            . '<div><label>Role</label><select name="role"><option value="pickup">pickup</option><option value="delivery">delivery</option><option value="both">both</option></select></div>'
            . '<div><label>Hub</label><select name="hub_id" required>';
        foreach ($hubs as $h) {
            $content .= '<option value="' . (int)$h['id'] . '">' . e((string)$h['name']) . '</option>';
        }
        $content .= '</select></div>'
            . '<div><label>Login email</label><input name="email" type="email" required></div>'
            . '<div><label>Temporary password</label><input name="password" required></div>'
            . '<button class="btn primary" type="submit">Create</button>'
            . '</form></div>';
        $content .= '</div>';
    }

    if ($tab === 'customers') {
        $customers = $pdo->query("SELECT * FROM customers ORDER BY id")->fetchAll();
        $content .= '<div class="grid cols2">';
        $content .= '<div class="card"><div class="h1">Customers</div><table class="table"><thead><tr><th>Name</th><th>Email</th><th>Created</th></tr></thead><tbody>';
        foreach ($customers as $c) {
            $content .= '<tr><td>' . e((string)$c['name']) . '</td><td class="muted">' . e((string)$c['email']) . '</td><td class="muted">' . e((string)$c['created_at']) . '</td></tr>';
        }
        $content .= '</tbody></table></div>';
        $content .= '<div class="card"><div class="h1">Create customer + login</div><form method="post" class="form"><input type="hidden" name="csrf" value="' . e(csrfToken()) . '">' . idempotencyInput() . '<input type="hidden" name="action" value="admin_create_customer">'
            . '<div><label>Name</label><input name="name" required></div>'
            . '<div><label>Email</label><input name="email" type="email" required></div>'
            . '<div><label>Temporary password</label><input name="password" required></div>'
            . '<button class="btn primary" type="submit">Create</button>'
            . '</form></div>';
        $content .= '</div>';
    }

    if ($tab === 'audit') {
        $rows = $pdo->query("SELECT a.*, u.email AS actor_email
            FROM audit_log a
            LEFT JOIN users u ON u.id=a.actor_user_id
            ORDER BY a.created_at DESC, a.id DESC
            LIMIT 200")->fetchAll();
        $content .= '<div class="card"><div class="h1">Audit log</div><div class="muted">Privileged changes and custody-affecting mutations, newest first.</div>';
        $content .= '<table class="table"><thead><tr><th>Time</th><th>Actor</th><th>Action</th><th>Entity</th><th>Reason</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $content .= '<tr>'
                . '<td class="muted">' . e((string)$row['created_at']) . '</td>'
                . '<td>' . e((string)($row['actor_email'] ?? ('User #' . $row['actor_user_id']))) . '<div class="muted" style="font-size:12px">' . e((string)$row['actor_role']) . '</div></td>'
                . '<td>' . e((string)$row['action']) . '</td>'
                . '<td>' . e((string)$row['entity_type']) . ' #' . (int)$row['entity_id'] . '</td>'
                . '<td class="muted">' . e((string)($row['reason'] ?? '')) . '</td>'
                . '</tr>';
        }
        $content .= '</tbody></table></div>';
    }

    renderLayout('Admin', $u, $content);
}

function actionAdminCreateHub(PDO $pdo, array $u): void
{
    requireRole($u, ['admin']);
    csrfCheck();
    try {
        $name = postText('name', 80);
        $type = postText('type', 20);
        $address = postText('address', 255);
        $city = postText('city', 80);
        $coverageRaw = postText('coverage', 500);
        $autoAssign = (string)($_POST['auto_assign'] ?? '1') === '1' ? 1 : 0;
        if ($name === '' || !in_array($type, ['pickup','warehouse','lastmile'], true)) {
            throw new UserSafeException('Invalid hub details');
        }
    } catch (UserSafeException $e) {
        flash('err', $e->getMessage());
        redirect('admin', ['tab' => 'hubs']);
    }

    $coverage = parseCsvKeywords($coverageRaw);

    $pdo->beginTransaction();
    try {
        $idem = beginIdempotentAction($pdo, $u, 'admin_create_hub', [
            'name' => $name,
            'type' => $type,
            'address' => $address,
            'city' => $city,
            'coverage' => $coverage,
            'auto_assign' => $autoAssign,
        ]);
        if (!empty($idem['duplicate'])) {
            redirectDuplicateAction($pdo, $idem, 'admin', ['tab' => 'hubs']);
        }

        $pdo->prepare("INSERT INTO hubs (name,type,address,city,coverage_keywords,auto_assign,created_at) VALUES (?,?,?,?,?,?,?)")
            ->execute([$name, $type, $address !== '' ? $address : null, $city !== '' ? $city : null, json_encode($coverage, JSON_UNESCAPED_UNICODE), $autoAssign, nowIso()]);
        $hubId = (int)$pdo->lastInsertId();
        addAuditLog(
            $pdo,
            $u,
            'network_config_changed',
            'hub',
            $hubId,
            ['exists' => false],
            [
                'name' => $name,
                'type' => $type,
                'address' => $address !== '' ? $address : null,
                'city' => $city !== '' ? $city : null,
                'coverage_keywords' => $coverage,
                'auto_assign' => $autoAssign,
            ],
            'Hub created'
        );
        completeIdempotentAction($pdo, $u, $idem, 'admin', ['tab' => 'hubs']);
        $pdo->commit();
        flash('ok', 'Hub created');
    } catch (UserSafeException $e) {
        $pdo->rollBack();
        flash('err', $e->getMessage());
    } catch (Throwable $e) {
        $pdo->rollBack();
        logAppError($e, ['action' => 'admin_create_hub']);
        flash('err', 'Hub could not be created.');
    }
    redirect('admin', ['tab' => 'hubs']);
}

function actionAdminCreateAgent(PDO $pdo, array $u): void
{
    requireRole($u, ['admin']);
    csrfCheck();
    try {
        $name = postText('name', 80);
        $phone = postText('phone', 40);
        $role = postText('role', 20);
        $hubId = postPositiveInt('hub_id');
        $email = validEmail((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        if ($name === '' || $password === '' || hrLen($password) < 8 || !in_array($role, ['pickup','delivery','both'], true)) {
            throw new UserSafeException('Invalid agent details');
        }
        $hubCheck = $pdo->prepare("SELECT COUNT(*) FROM hubs WHERE id=?");
        $hubCheck->execute([$hubId]);
        if ((int)$hubCheck->fetchColumn() !== 1) {
            throw new UserSafeException('Selected hub does not exist');
        }
    } catch (UserSafeException $e) {
        flash('err', $e->getMessage());
        redirect('admin', ['tab' => 'agents']);
    }
    $pdo->beginTransaction();
    try {
        $idem = beginIdempotentAction($pdo, $u, 'admin_create_agent', [
            'name' => $name,
            'phone' => $phone,
            'role' => $role,
            'hub_id' => $hubId,
            'email' => $email,
        ]);
        if (!empty($idem['duplicate'])) {
            redirectDuplicateAction($pdo, $idem, 'admin', ['tab' => 'agents']);
        }

        $pdo->prepare("INSERT INTO agents (hub_id,name,phone,role,active,current_status,last_seen_ts,created_at) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$hubId, $name, $phone !== '' ? $phone : null, $role, 1, 'idle', null, nowIso()]);
        $agentId = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO users (email,password_hash,role,hub_id,agent_id,customer_id,active,created_at) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$email, password_hash($password, PASSWORD_DEFAULT), 'agent', $hubId, $agentId, null, 1, nowIso()]);
        $userId = (int)$pdo->lastInsertId();
        addAuditLog(
            $pdo,
            $u,
            'user_role_changed',
            'user',
            $userId,
            ['exists' => false],
            [
                'email' => $email,
                'role' => 'agent',
                'hub_id' => $hubId,
                'agent_id' => $agentId,
                'active' => 1,
            ],
            'Agent user created'
        );
        completeIdempotentAction($pdo, $u, $idem, 'admin', ['tab' => 'agents']);
        $pdo->commit();
        flash('ok', 'Agent created');
    } catch (UserSafeException $e) {
        $pdo->rollBack();
        flash('err', $e->getMessage());
    } catch (Throwable $e) {
        $pdo->rollBack();
        logAppError($e, ['action' => 'admin_create_agent']);
        flash('err', 'Agent could not be created.');
    }
    redirect('admin', ['tab' => 'agents']);
}

function actionAdminCreateCustomer(PDO $pdo, array $u): void
{
    requireRole($u, ['admin']);
    csrfCheck();
    try {
        $name = postText('name', 80);
        $email = validEmail((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        if ($name === '' || $password === '' || hrLen($password) < 8) {
            throw new UserSafeException('Invalid customer details');
        }
    } catch (UserSafeException $e) {
        flash('err', $e->getMessage());
        redirect('admin', ['tab' => 'customers']);
    }
    $pdo->beginTransaction();
    try {
        $idem = beginIdempotentAction($pdo, $u, 'admin_create_customer', [
            'name' => $name,
            'email' => $email,
        ]);
        if (!empty($idem['duplicate'])) {
            redirectDuplicateAction($pdo, $idem, 'admin', ['tab' => 'customers']);
        }

        $pdo->prepare("INSERT INTO customers (name,email,api_key,created_at) VALUES (?,?,?,?)")
            ->execute([$name, $email, null, nowIso()]);
        $cid = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO users (email,password_hash,role,hub_id,agent_id,customer_id,active,created_at) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$email, password_hash($password, PASSWORD_DEFAULT), 'customer', null, null, $cid, 1, nowIso()]);
        $userId = (int)$pdo->lastInsertId();
        addAuditLog(
            $pdo,
            $u,
            'user_role_changed',
            'user',
            $userId,
            ['exists' => false],
            [
                'email' => $email,
                'role' => 'customer',
                'customer_id' => $cid,
                'active' => 1,
            ],
            'Customer user created'
        );
        completeIdempotentAction($pdo, $u, $idem, 'admin', ['tab' => 'customers']);
        $pdo->commit();
        flash('ok', 'Customer created');
    } catch (UserSafeException $e) {
        $pdo->rollBack();
        flash('err', $e->getMessage());
    } catch (Throwable $e) {
        $pdo->rollBack();
        logAppError($e, ['action' => 'admin_create_customer']);
        flash('err', 'Customer could not be created.');
    }
    redirect('admin', ['tab' => 'customers']);
}

function actionAdminCreateUser(PDO $pdo, array $u): void
{
    requireRole($u, ['admin']);
    csrfCheck();
    try {
        $email = validEmail((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        if ($password === '' || hrLen($password) < 12) {
            throw new UserSafeException('Admin temporary password must be at least 12 characters');
        }
        if (isSeededAccountEmail($email)) {
            throw new UserSafeException('Use a real production email, not a seeded account email');
        }
        $existing = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email=?");
        $existing->execute([$email]);
        if ((int)$existing->fetchColumn() > 0) {
            throw new UserSafeException('A user with that email already exists');
        }
    } catch (UserSafeException $e) {
        flash('err', $e->getMessage());
        redirect('admin', ['tab' => 'users']);
    }

    $pdo->beginTransaction();
    try {
        $idem = beginIdempotentAction($pdo, $u, 'admin_create_user', [
            'email' => $email,
            'role' => 'admin',
        ]);
        if (!empty($idem['duplicate'])) {
            redirectDuplicateAction($pdo, $idem, 'admin', ['tab' => 'users']);
        }

        $pdo->prepare("INSERT INTO users (email,password_hash,role,hub_id,agent_id,customer_id,active,created_at) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$email, password_hash($password, PASSWORD_DEFAULT), 'admin', null, null, null, 1, nowIso()]);
        $userId = (int)$pdo->lastInsertId();
        addAuditLog(
            $pdo,
            $u,
            'user_access_changed',
            'user',
            $userId,
            ['exists' => false],
            [
                'email' => $email,
                'role' => 'admin',
                'active' => 1,
                'password_reset' => true,
            ],
            'Production admin created'
        );
        completeIdempotentAction($pdo, $u, $idem, 'admin', ['tab' => 'users']);
        $pdo->commit();
        flash('ok', 'Production admin created. Log in as that user before disabling the seeded admin.');
    } catch (UserSafeException $e) {
        $pdo->rollBack();
        flash('err', $e->getMessage());
    } catch (Throwable $e) {
        $pdo->rollBack();
        logAppError($e, ['action' => 'admin_create_user']);
        flash('err', 'Admin user could not be created.');
    }
    redirect('admin', ['tab' => 'users']);
}

function actionAdminUpdateUser(PDO $pdo, array $u): void
{
    requireRole($u, ['admin']);
    csrfCheck();
    try {
        $userId = postPositiveInt('user_id');
        $newActive = (string)($_POST['active'] ?? '1') === '1' ? 1 : 0;
        $password = (string)($_POST['password'] ?? '');
        $reason = postText('reason', 255);
        if ($reason === '') {
            throw new UserSafeException('A reason is required for user access changes');
        }
        if ($password !== '' && hrLen($password) < 8) {
            throw new UserSafeException('New password must be at least 8 characters');
        }
    } catch (UserSafeException $e) {
        flash('err', $e->getMessage());
        redirect('admin', ['tab' => 'users']);
    }

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM users WHERE id=?");
        $st->execute([$userId]);
        $target = $st->fetch();
        if (!$target) {
            throw new UserSafeException('Selected user does not exist');
        }
        if ((int)$target['id'] === (int)$u['id'] && $newActive === 0) {
            throw new UserSafeException('You cannot disable your own active session');
        }
        if ((string)$target['role'] === 'admin' && (int)$target['active'] === 1 && $newActive === 0) {
            $admins = $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1")->fetchColumn();
            if ((int)$admins <= 1) {
                throw new UserSafeException('Create another active admin before disabling the last admin');
            }
        }

        $idem = beginIdempotentAction($pdo, $u, 'admin_update_user', [
            'user_id' => $userId,
            'active' => $newActive,
            'password_reset' => $password !== '',
            'reason' => $reason,
        ]);
        if (!empty($idem['duplicate'])) {
            redirectDuplicateAction($pdo, $idem, 'admin', ['tab' => 'users']);
        }

        $before = [
            'email' => (string)$target['email'],
            'role' => (string)$target['role'],
            'active' => (int)$target['active'],
        ];
        $after = $before;
        $after['active'] = $newActive;
        $after['password_reset'] = $password !== '';

        if ($password !== '') {
            $pdo->prepare("UPDATE users SET password_hash=?, active=? WHERE id=?")
                ->execute([password_hash($password, PASSWORD_DEFAULT), $newActive, $userId]);
        } else {
            $pdo->prepare("UPDATE users SET active=? WHERE id=?")
                ->execute([$newActive, $userId]);
        }

        if ((int)$target['agent_id'] > 0) {
            $pdo->prepare("UPDATE agents SET active=? WHERE id=?")
                ->execute([$newActive, (int)$target['agent_id']]);
        }

        addAuditLog(
            $pdo,
            $u,
            'user_access_changed',
            'user',
            $userId,
            $before,
            $after,
            $reason,
            ['seeded_account' => isSeededAccountEmail((string)$target['email'])]
        );
        completeIdempotentAction($pdo, $u, $idem, 'admin', ['tab' => 'users']);
        $pdo->commit();
        flash('ok', 'User access updated');
    } catch (UserSafeException $e) {
        $pdo->rollBack();
        flash('err', $e->getMessage());
    } catch (Throwable $e) {
        $pdo->rollBack();
        logAppError($e, ['action' => 'admin_update_user']);
        flash('err', 'User access could not be updated.');
    }
    redirect('admin', ['tab' => 'users']);
}

function routeExport(PDO $pdo, array $u): void
{
    requireRole($u, ['hub', 'admin']);
    $routeId = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare("SELECT r.*, h.name AS hub_name FROM routes r JOIN hubs h ON h.id=r.hub_id WHERE r.id=?");
    $st->execute([$routeId]);
    $route = $st->fetch();
    if (!$route) {
        http_response_code(404);
        echo 'Not found';
        return;
    }
    if ($u['role'] === 'hub' && (int)$u['hub_id'] !== (int)$route['hub_id']) {
        http_response_code(403);
        echo 'Forbidden';
        return;
    }

    $parcels = $pdo->prepare("SELECT p.*, c.name AS customer_name, a.name AS agent_name
        FROM parcels p
        JOIN customers c ON c.id=p.customer_id
        LEFT JOIN agents a ON a.id=p.assigned_agent_id
        WHERE p.route_id=?");
    $parcels->execute([$routeId]);
    $rows = $parcels->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="route_' . (int)$routeId . '_manifest.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['route_id', 'route_name', 'hub', 'tracking_code', 'pickup_address', 'dropoff_address', 'amount_cents', 'status', 'assigned_agent', 'customer']);
    foreach ($rows as $p) {
        fputcsv($out, [
            (int)$routeId,
            csvCell((string)$route['name']),
            csvCell((string)$route['hub_name']),
            csvCell((string)$p['tracking_code']),
            csvCell((string)$p['pickup_address']),
            csvCell((string)$p['dropoff_address']),
            (int)$p['amount_cents'],
            csvCell((string)$p['status']),
            csvCell((string)($p['agent_name'] ?? '')),
            csvCell((string)$p['customer_name']),
        ]);
    }
    fclose($out);
    exit;
}

function bootDbOrFail(): PDO
{
    try {
        return db();
    } catch (Throwable $e) {
        logAppError($e, ['phase' => 'startup']);
        renderFatal('HubRoute startup error', 'The application could not start. Check that PDO SQLite and SQLite3 are enabled, then review data/php-error.log on the server.');
        exit;
    }
}

$pathInfo = (string)($_SERVER['PATH_INFO'] ?? '');
if ($pathInfo !== '' && preg_match('#^/track/([A-Za-z0-9_-]+)$#', $pathInfo, $m)) {
    $_GET['r'] = 'track';
    $_GET['code'] = $m[1];
}

$action = (string)($_POST['action'] ?? '');
if ($action !== '') {
    $pdo = bootDbOrFail();
    $u = currentUser($pdo);
    $knownActions = ['login','logout','customer_create','customer_confirm','hub_assign','hub_create_route','record_event','agent_step','settle','admin_create_hub','admin_create_agent','admin_create_customer','admin_create_user','admin_update_user'];
    if (!in_array($action, $knownActions, true)) {
        csrfCheck();
        http_response_code(400);
        echo 'Unknown action';
        exit;
    }
    if ($action === 'login') {
        actionLogin($pdo);
    }
    if ($action === 'logout') {
        actionLogout();
    }
    if (!$u) {
        redirect('login');
    }
    checkRateLimit($pdo, 'action', (string)$u['id'] . ':' . $action, RATE_LIMIT_ACTION_ATTEMPTS, RATE_LIMIT_ACTION_WINDOW_SECONDS);
    if ($action === 'customer_create') {
        actionCustomerCreate($pdo, $u);
    }
    if ($action === 'customer_confirm') {
        actionCustomerConfirm($pdo, $u);
    }
    if ($action === 'hub_assign') {
        actionHubAssign($pdo, $u);
    }
    if ($action === 'hub_create_route') {
        actionHubCreateRoute($pdo, $u);
    }
    if ($action === 'record_event') {
        actionRecordEvent($pdo, $u);
    }
    if ($action === 'agent_step') {
        actionAgentStep($pdo, $u);
    }
    if ($action === 'settle') {
        actionSettle($pdo, $u);
    }
    if ($action === 'admin_create_hub') {
        actionAdminCreateHub($pdo, $u);
    }
    if ($action === 'admin_create_agent') {
        actionAdminCreateAgent($pdo, $u);
    }
    if ($action === 'admin_create_customer') {
        actionAdminCreateCustomer($pdo, $u);
    }
    if ($action === 'admin_create_user') {
        actionAdminCreateUser($pdo, $u);
    }
    if ($action === 'admin_update_user') {
        actionAdminUpdateUser($pdo, $u);
    }

    http_response_code(400);
    echo 'Unknown action';
    exit;
}

$route = (string)($_GET['r'] ?? '');
if ($route === '') {
    $route = 'track';
}

if ($route === 'login') {
    pageLogin();
    exit;
}
if ($route === 'logout') {
    http_response_code(405);
    echo 'Use the logout button to submit a CSRF-protected POST.';
    exit;
}
if ($route === 'track') {
    $pdo = bootDbOrFail();
    pagePublicTrack($pdo);
    exit;
}

$pdo = bootDbOrFail();
$u = requireLogin($pdo);

if ($route === 'dashboard') {
    pageDashboard($pdo, $u);
    exit;
}
if ($route === 'customer_parcels') {
    pageCustomerParcels($pdo, $u);
    exit;
}
if ($route === 'hub_parcels') {
    pageHubParcels($pdo, $u);
    exit;
}
if ($route === 'hub_routes') {
    pageHubRoutes($pdo, $u);
    exit;
}
if ($route === 'agent_run') {
    pageAgentRun($pdo, $u);
    exit;
}
if ($route === 'parcel') {
    pageParcelDetail($pdo, $u);
    exit;
}
if ($route === 'scan') {
    pageScan($pdo, $u);
    exit;
}
if ($route === 'settlements') {
    pageSettlements($pdo, $u);
    exit;
}
if ($route === 'admin') {
    pageAdmin($pdo, $u);
    exit;
}
if ($route === 'route_export') {
    routeExport($pdo, $u);
    exit;
}

http_response_code(404);
echo 'Not found';
