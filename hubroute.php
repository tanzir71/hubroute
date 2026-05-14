<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'php-error.log');
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

const APP_NAME = 'HubRoute';
const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';
const DB_PATH = DATA_DIR . DIRECTORY_SEPARATOR . 'hubroute.sqlite';
const APP_TIMEZONE = 'UTC';

date_default_timezone_set(APP_TIMEZONE);

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0775, true);
    }

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
        . '<style>body{font-family:system-ui,Segoe UI,Arial,sans-serif;background:#f7f8fa;color:#111827;margin:0;padding:24px} .card{max-width:820px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px} .h1{font-size:20px;font-weight:700;margin:0 0 10px} .muted{color:#6b7280}</style>'
        . '</head><body><div class="card"><div class="h1">' . $safeTitle . '</div><div>' . $safeMsg . '</div>'
        . '<div class="muted" style="margin-top:12px">Check server error log: <code>data/php-error.log</code></div>'
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

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_parcels_status ON parcels(status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_parcels_assigned_agent ON parcels(assigned_agent_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_parcels_hub_pickup ON parcels(hub_pickup_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_parcels_tracking ON parcels(tracking_code)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_events_parcel_ts ON events(parcel_id, ts)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_routes_hub_active ON routes(hub_id, active)");

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
        $links[] = ['Logout', 'logout'];

        $navLinks = '';
        foreach ($links as $l) {
            $navLinks .= '<a class="navlink" href="?r=' . e($l[1]) . '">' . e($l[0]) . '</a>';
        }
        $nav = '<div class="topbar"><div class="brand">' . e(APP_NAME) . '</div><div class="nav">' . $navLinks . '</div><div class="who">' . e((string)$user['email']) . ' · ' . e(roleLabel((string)$user['role'])) . '</div></div>';
    } else {
        $nav = '<div class="topbar"><div class="brand">' . e(APP_NAME) . '</div><div class="nav"><a class="navlink" href="?r=track">Public Tracking</a><a class="navlink" href="?r=login">Login</a></div></div>';
    }

    $flashHtml = '';
    foreach ($flash as $f) {
        $t = $f['type'] ?? 'info';
        $msg = $f['msg'] ?? '';
        $flashHtml .= '<div class="flash ' . e((string)$t) . '">' . e((string)$msg) . '</div>';
    }

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' . $refreshTag . '<title>' . e($title) . ' · ' . e(APP_NAME) . '</title>';
    echo '<style>
    :root{--bg:#f7f8fa;--card:#fff;--text:#111827;--muted:#6b7280;--border:#e5e7eb;--pri:#2563eb;--danger:#dc2626;}
    *{box-sizing:border-box}body{margin:0;font:14px/1.45 system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:var(--text);background:var(--bg)}
    a{color:var(--pri);text-decoration:none}a:hover{text-decoration:underline}
    .topbar{display:flex;gap:12px;align-items:center;justify-content:space-between;padding:12px 16px;background:var(--card);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:20}
    .brand{font-weight:700}
    .nav{display:flex;gap:10px;flex-wrap:wrap}
    .navlink{padding:6px 8px;border-radius:8px;color:var(--text)}
    .navlink:hover{background:#f1f5f9;text-decoration:none}
    .who{color:var(--muted);font-size:12px}
    .wrap{max-width:1200px;margin:0 auto;padding:16px}
    .grid{display:grid;grid-template-columns:1fr;gap:12px}
    @media(min-width:980px){.grid.cols2{grid-template-columns:2fr 1fr}}
    .card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:12px}
    .h1{font-size:18px;font-weight:700;margin:0 0 8px}
    .muted{color:var(--muted)}
    .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
    .btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:9px 12px;border-radius:10px;border:1px solid var(--border);background:#fff;color:var(--text);cursor:pointer}
    .btn.primary{background:var(--pri);border-color:var(--pri);color:#fff}
    .btn:disabled{opacity:.45;cursor:not-allowed}
    input,select,textarea{width:100%;padding:9px 10px;border-radius:10px;border:1px solid var(--border);background:#fff}
    textarea{min-height:90px;resize:vertical}
    label{display:block;font-size:12px;color:var(--muted);margin:6px 0}
    .form{display:grid;gap:10px}
    .table{width:100%;border-collapse:collapse}
    .table th{position:sticky;top:52px;background:var(--card);text-align:left;font-size:12px;color:var(--muted);border-bottom:1px solid var(--border);padding:10px}
    .table td{border-bottom:1px solid var(--border);padding:10px;vertical-align:top}
    .pill{display:inline-block;padding:3px 8px;border-radius:999px;font-size:12px;border:1px solid var(--border);background:#f8fafc}
    .pill.bg-blue{background:#dbeafe;border-color:#bfdbfe}
    .pill.bg-indigo{background:#e0e7ff;border-color:#c7d2fe}
    .pill.bg-amber{background:#fef3c7;border-color:#fde68a}
    .pill.bg-purple{background:#ede9fe;border-color:#ddd6fe}
    .pill.bg-teal{background:#ccfbf1;border-color:#99f6e4}
    .pill.bg-green{background:#dcfce7;border-color:#bbf7d0}
    .pill.bg-red{background:#fee2e2;border-color:#fecaca}
    .pill.bg-orange{background:#ffedd5;border-color:#fed7aa}
    .flash{padding:10px 12px;border-radius:10px;margin:0 0 10px;border:1px solid var(--border);background:#fff}
    .flash.ok{border-color:#bbf7d0;background:#f0fdf4}
    .flash.err{border-color:#fecaca;background:#fef2f2}
    .kpis{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}
    @media(min-width:640px){.kpis{grid-template-columns:repeat(4,1fr)}}
    .kpi{padding:10px 12px;border:1px solid var(--border);border-radius:12px;background:#fff}
    .kpi .n{font-size:18px;font-weight:800}
    .kpi .l{font-size:12px;color:var(--muted)}
    </style>';
    echo '</head><body>' . $nav . '<div class="wrap">' . $flashHtml . $content . '</div></body></html>';
}

function pageLogin(PDO $pdo): void
{
    $content = '<div class="card" style="max-width:520px;margin:40px auto"><div class="h1">Sign in</div>';
    $content .= '<form method="post" class="form"><input type="hidden" name="csrf" value="' . e(csrfToken()) . '"><input type="hidden" name="action" value="login">';
    $content .= '<div><label>Email</label><input name="email" type="email" required></div>';
    $content .= '<div><label>Password</label><input name="password" type="password" required></div>';
    $content .= '<button class="btn primary" type="submit">Sign in</button>';
    $content .= '</form><div class="muted" style="margin-top:10px">Public tracking: <a href="?r=track">Track a parcel</a></div></div>';
    renderLayout('Login', null, $content);
}

function actionLogin(PDO $pdo): void
{
    csrfCheck();
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email=? AND active=1");
    $stmt->execute([$email]);
    $u = $stmt->fetch();
    if (!$u || !password_verify($password, (string)$u['password_hash'])) {
        flash('err', 'Invalid credentials');
        redirect('login');
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    flash('ok', 'Welcome back');
    redirect('dashboard');
}

function actionLogout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    redirect('login');
}

function pagePublicTrack(PDO $pdo): void
{
    $code = trim((string)($_GET['code'] ?? ''));
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
    $status = trim((string)($_GET['status'] ?? ''));
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
    $content .= '<form method="post" class="form"><input type="hidden" name="csrf" value="' . e(csrfToken()) . '"><input type="hidden" name="action" value="customer_create">';
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
    $pickup = trim((string)($_POST['pickup_address'] ?? ''));
    $dropoff = trim((string)($_POST['dropoff_address'] ?? ''));
    $amount = trim((string)($_POST['amount'] ?? '0'));
    $pickupWindow = trim((string)($_POST['pickup_window'] ?? ''));
    $note = trim((string)($_POST['note'] ?? ''));

    if ($pickup === '' || $dropoff === '') {
        flash('err', 'Pickup and dropoff are required');
        redirect('customer_parcels');
    }

    $amountCents = 0;
    if ($amount !== '') {
        $amountCents = (int)round(((float)$amount) * 100);
        if ($amountCents < 0) {
            $amountCents = 0;
        }
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
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    flash('ok', 'Request created. Tracking: ' . $tracking);
    redirect('customer_parcels');
}

function pageHubParcels(PDO $pdo, array $u): void
{
    requireRole($u, ['hub']);
    $hubId = (int)$u['hub_id'];
    $status = trim((string)($_GET['status'] ?? ''));
    $q = trim((string)($_GET['q'] ?? ''));

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
        $content .= '<form method="post" class="row" style="gap:6px"><input type="hidden" name="csrf" value="' . e(csrfToken()) . '"><input type="hidden" name="action" value="hub_assign"><input type="hidden" name="parcel_id" value="' . (int)$p['id'] . '">';
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
    $parcelId = (int)($_POST['parcel_id'] ?? 0);

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM parcels WHERE id=? AND customer_id=?");
        $st->execute([$parcelId, (int)$u['customer_id']]);
        $p = $st->fetch();
        if (!$p) {
            throw new RuntimeException('Parcel not found');
        }
        if ((string)$p['status'] !== 'delivered') {
            throw new RuntimeException('Parcel is not delivered');
        }

        $meta = json_decode((string)$p['metadata'], true);
        $meta = is_array($meta) ? $meta : [];
        if (!empty($meta['customer_confirmed_at'])) {
            throw new RuntimeException('Already confirmed');
        }
        $meta['customer_confirmed_at'] = nowIso();

        $upd = $pdo->prepare("UPDATE parcels SET metadata=?, updated_at=? WHERE id=? AND customer_id=?");
        $upd->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), nowIso(), $parcelId, (int)$u['customer_id']]);
        addEvent($pdo, $parcelId, 'customer', (int)$u['id'], null, $p['current_hub_id'] !== null ? (int)$p['current_hub_id'] : null, $p['route_id'] !== null ? (int)$p['route_id'] : null, 'customer_confirmed', 'Customer confirmed receipt', null, null);
        $pdo->commit();
        flash('ok', 'Receipt confirmed');
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash('err', $e->getMessage());
    }
    redirect('parcel', ['id' => (string)$parcelId]);
}

function actionHubAssign(PDO $pdo, array $u): void
{
    requireRole($u, ['hub']);
    csrfCheck();
    $hubId = (int)$u['hub_id'];
    $parcelId = (int)($_POST['parcel_id'] ?? 0);
    $agentIdRaw = trim((string)($_POST['agent_id'] ?? ''));
    $routeIdRaw = trim((string)($_POST['route_id'] ?? ''));
    $agentId = $agentIdRaw !== '' ? (int)$agentIdRaw : null;
    $routeId = $routeIdRaw !== '' ? (int)$routeIdRaw : null;

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM parcels WHERE id=?");
        $st->execute([$parcelId]);
        $p = $st->fetch();
        if (!$p) {
            throw new RuntimeException('Parcel not found');
        }
        $touchesHub = ((int)$p['hub_pickup_id'] === $hubId) || ((int)$p['hub_warehouse_id'] === $hubId) || ((int)$p['hub_delivery_id'] === $hubId) || ((int)$p['current_hub_id'] === $hubId);
        if (!$touchesHub) {
            throw new RuntimeException('Parcel is not visible to this hub');
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
                throw new RuntimeException('Parcel was updated by another user; retry');
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

        $pdo->commit();
        flash('ok', 'Assignment updated');
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash('err', $e->getMessage());
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
    $content .= '<form method="post" class="form"><input type="hidden" name="csrf" value="' . e(csrfToken()) . '"><input type="hidden" name="action" value="hub_create_route">';
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
    $name = trim((string)($_POST['name'] ?? ''));
    $keywordsRaw = trim((string)($_POST['keywords'] ?? ''));
    $assignedAgentRaw = trim((string)($_POST['assigned_agent_id'] ?? ''));
    $centerLatRaw = trim((string)($_POST['center_lat'] ?? ''));
    $centerLngRaw = trim((string)($_POST['center_lng'] ?? ''));
    $radiusRaw = trim((string)($_POST['radius_km'] ?? ''));

    if ($name === '' || $keywordsRaw === '') {
        flash('err', 'Name and keywords required');
        redirect('hub_routes');
    }

    $keywords = [];
    foreach (explode(',', $keywordsRaw) as $k) {
        $k = trim($k);
        if ($k !== '') {
            $keywords[] = $k;
        }
    }

    $assignedAgent = $assignedAgentRaw !== '' ? (int)$assignedAgentRaw : null;
    $centerLat = $centerLatRaw !== '' ? (float)$centerLatRaw : null;
    $centerLng = $centerLngRaw !== '' ? (float)$centerLngRaw : null;
    $radiusKm = $radiusRaw !== '' ? (float)$radiusRaw : null;
    $pdo->prepare("INSERT INTO routes (hub_id,name,match_keywords,assigned_agent_id,active,center_lat,center_lng,radius_km,created_at) VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([$hubId, $name, json_encode($keywords, JSON_UNESCAPED_UNICODE), $assignedAgent, 1, $centerLat, $centerLng, $radiusKm, nowIso()]);
    flash('ok', 'Route created');
    redirect('hub_routes');
}

function pageScan(PDO $pdo, array $u): void
{
    requireRole($u, ['hub', 'agent', 'admin']);
    $tracking = trim((string)($_GET['code'] ?? ''));
    $parcel = null;
    if ($tracking !== '') {
        $st = $pdo->prepare("SELECT p.*, a.name AS agent_name, h.name AS hub_name FROM parcels p LEFT JOIN agents a ON a.id=p.assigned_agent_id LEFT JOIN hubs h ON h.id=p.current_hub_id WHERE p.tracking_code=?");
        $st->execute([$tracking]);
        $parcel = $st->fetch();
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

    $content .= '<form method="post" class="form" style="margin-top:10px"><input type="hidden" name="csrf" value="' . e(csrfToken()) . '"><input type="hidden" name="action" value="record_event"><input type="hidden" name="parcel_id" value="' . (int)$parcel['id'] . '">';
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
    $parcelId = (int)($_POST['parcel_id'] ?? 0);
    $eventType = trim((string)($_POST['event_type'] ?? ''));
    $note = trim((string)($_POST['note'] ?? ''));
    $latRaw = trim((string)($_POST['lat'] ?? ''));
    $lngRaw = trim((string)($_POST['lng'] ?? ''));
    $lat = $latRaw !== '' ? (float)$latRaw : null;
    $lng = $lngRaw !== '' ? (float)$lngRaw : null;

    $st = $pdo->prepare("SELECT p.* FROM parcels p WHERE p.id=?");
    $st->execute([$parcelId]);
    $p = $st->fetch();
    if (!$p) {
        flash('err', 'Parcel not found');
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
        $pdo->commit();
        flash('ok', 'Event recorded');
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash('err', $e->getMessage());
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
    if ($role === 'customer' && (int)$u['customer_id'] !== (int)$p['customer_id']) {
        http_response_code(403);
        echo 'Forbidden';
        return;
    }
    if ($role === 'hub') {
        $hid = (int)$u['hub_id'];
        $touchesHub = ((int)$p['hub_pickup_id'] === $hid) || ((int)$p['hub_warehouse_id'] === $hid) || ((int)$p['hub_delivery_id'] === $hid) || ((int)$p['current_hub_id'] === $hid);
        if (!$touchesHub) {
            http_response_code(403);
            echo 'Forbidden';
            return;
        }
    }
    if ($role === 'agent' && (int)$u['agent_id'] !== (int)$p['assigned_agent_id']) {
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
            $content .= '<form method="post" class="row"><input type="hidden" name="csrf" value="' . e(csrfToken()) . '"><input type="hidden" name="action" value="customer_confirm"><input type="hidden" name="parcel_id" value="' . (int)$p['id'] . '"><button class="btn primary" type="submit">Confirm delivered</button></form>';
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
            $right .= '<form method="post" class="form"><input type="hidden" name="csrf" value="' . e(csrfToken()) . '"><input type="hidden" name="action" value="agent_step"><input type="hidden" name="parcel_id" value="' . (int)$p['id'] . '">';
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
    $parcelId = (int)($_POST['parcel_id'] ?? 0);
    $toStatus = trim((string)($_POST['to_status'] ?? ''));
    $note = trim((string)($_POST['note'] ?? ''));
    $alsoPayment = (string)($_POST['also_payment'] ?? '') === '1';
    $latRaw = trim((string)($_POST['lat'] ?? ''));
    $lngRaw = trim((string)($_POST['lng'] ?? ''));
    $lat = $latRaw !== '' ? (float)$latRaw : null;
    $lng = $lngRaw !== '' ? (float)$lngRaw : null;

    $agentId = (int)$u['agent_id'];

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM parcels WHERE id=?");
        $st->execute([$parcelId]);
        $p = $st->fetch();
        if (!$p) {
            throw new RuntimeException('Parcel not found');
        }
        if ((int)$p['assigned_agent_id'] !== $agentId) {
            throw new RuntimeException('Not assigned to you');
        }
        $current = (string)$p['status'];
        $allowed = allowedNextStatuses($current);
        if (!in_array($toStatus, $allowed, true)) {
            throw new RuntimeException('Invalid transition');
        }

        $now = nowIso();
        $upd = $pdo->prepare("UPDATE parcels SET status=?, updated_at=? WHERE id=? AND status=? AND assigned_agent_id=?");
        $upd->execute([$toStatus, $now, $parcelId, $current, $agentId]);
        if ($upd->rowCount() !== 1) {
            throw new RuntimeException('Parcel changed; retry');
        }

        $routeId = $p['route_id'] !== null ? (int)$p['route_id'] : null;
        $hubId = $p['current_hub_id'] !== null ? (int)$p['current_hub_id'] : null;

        addEvent($pdo, $parcelId, 'agent', (int)$u['id'], $agentId, $hubId, $routeId, $toStatus, $note, $lat, $lng);
        if ($alsoPayment && (int)$p['amount_cents'] > 0) {
            addEvent($pdo, $parcelId, 'agent', (int)$u['id'], $agentId, $hubId, $routeId, 'payment_collected', $note !== '' ? $note : 'Payment collected', $lat, $lng);
        }

        $pdo->commit();
        flash('ok', 'Updated');
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash('err', $e->getMessage());
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
            $content .= '<form method="post"><input type="hidden" name="csrf" value="' . e(csrfToken()) . '"><input type="hidden" name="action" value="settle"><input type="hidden" name="parcel_id" value="' . (int)$p['id'] . '"><button class="btn" type="submit">Mark settled</button></form>';
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
    $pid = (int)($_POST['parcel_id'] ?? 0);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM parcels WHERE id=?");
        $st->execute([$pid]);
        $p = $st->fetch();
        if (!$p) {
            throw new RuntimeException('Parcel not found');
        }
        $touchesHub = ((int)$p['hub_pickup_id'] === $hubId) || ((int)$p['hub_warehouse_id'] === $hubId) || ((int)$p['hub_delivery_id'] === $hubId) || ((int)$p['current_hub_id'] === $hubId);
        if (!$touchesHub) {
            throw new RuntimeException('Not visible to this hub');
        }
        $upd = $pdo->prepare("UPDATE parcels SET cod_settled=1, updated_at=? WHERE id=? AND cod_settled=0");
        $upd->execute([nowIso(), $pid]);
        if ($upd->rowCount() !== 1) {
            throw new RuntimeException('Already settled');
        }
        addEvent($pdo, $pid, 'hub', (int)$u['id'], null, $hubId, $p['route_id'] !== null ? (int)$p['route_id'] : null, 'settlement_marked', 'COD marked settled', null, null);
        $pdo->commit();
        flash('ok', 'Marked settled');
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash('err', $e->getMessage());
    }
    redirect('settlements');
}

function pageAdmin(PDO $pdo, array $u): void
{
    requireRole($u, ['admin']);
    $tab = (string)($_GET['tab'] ?? 'overview');

    $content = '<div class="card"><div class="h1">Admin</div><div class="row" style="margin-top:8px">'
        . '<a class="btn" href="?r=admin&tab=overview">Overview</a>'
        . '<a class="btn" href="?r=admin&tab=hubs">Hubs</a>'
        . '<a class="btn" href="?r=admin&tab=agents">Agents</a>'
        . '<a class="btn" href="?r=admin&tab=customers">Customers</a>'
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

    if ($tab === 'hubs') {
        $hubs = $pdo->query("SELECT * FROM hubs ORDER BY id")->fetchAll();
        $content .= '<div class="grid cols2">';
        $content .= '<div class="card"><div class="h1">Hubs</div><table class="table"><thead><tr><th>Name</th><th>Type</th><th>City</th><th>Auto</th></tr></thead><tbody>';
        foreach ($hubs as $h) {
            $content .= '<tr><td>' . e((string)$h['name']) . '</td><td>' . e((string)$h['type']) . '</td><td class="muted">' . e((string)($h['city'] ?? '')) . '</td><td>' . ((int)$h['auto_assign'] === 1 ? 'yes' : 'no') . '</td></tr>';
        }
        $content .= '</tbody></table></div>';
        $content .= '<div class="card"><div class="h1">Create hub</div><form method="post" class="form"><input type="hidden" name="csrf" value="' . e(csrfToken()) . '"><input type="hidden" name="action" value="admin_create_hub">'
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
        $content .= '<div class="card"><div class="h1">Create agent + login</div><form method="post" class="form"><input type="hidden" name="csrf" value="' . e(csrfToken()) . '"><input type="hidden" name="action" value="admin_create_agent">'
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
        $content .= '<div class="card"><div class="h1">Create customer + login</div><form method="post" class="form"><input type="hidden" name="csrf" value="' . e(csrfToken()) . '"><input type="hidden" name="action" value="admin_create_customer">'
            . '<div><label>Name</label><input name="name" required></div>'
            . '<div><label>Email</label><input name="email" type="email" required></div>'
            . '<div><label>Temporary password</label><input name="password" required></div>'
            . '<button class="btn primary" type="submit">Create</button>'
            . '</form></div>';
        $content .= '</div>';
    }

    renderLayout('Admin', $u, $content);
}

function actionAdminCreateHub(PDO $pdo, array $u): void
{
    requireRole($u, ['admin']);
    csrfCheck();
    $name = trim((string)($_POST['name'] ?? ''));
    $type = trim((string)($_POST['type'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));
    $city = trim((string)($_POST['city'] ?? ''));
    $coverageRaw = trim((string)($_POST['coverage'] ?? ''));
    $autoAssign = (int)($_POST['auto_assign'] ?? 1);

    $coverage = [];
    foreach (explode(',', $coverageRaw) as $k) {
        $k = trim($k);
        if ($k !== '') {
            $coverage[] = $k;
        }
    }

    $pdo->prepare("INSERT INTO hubs (name,type,address,city,coverage_keywords,auto_assign,created_at) VALUES (?,?,?,?,?,?,?)")
        ->execute([$name, $type, $address !== '' ? $address : null, $city !== '' ? $city : null, json_encode($coverage, JSON_UNESCAPED_UNICODE), $autoAssign ? 1 : 0, nowIso()]);
    flash('ok', 'Hub created');
    redirect('admin', ['tab' => 'hubs']);
}

function actionAdminCreateAgent(PDO $pdo, array $u): void
{
    requireRole($u, ['admin']);
    csrfCheck();
    $name = trim((string)($_POST['name'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $role = trim((string)($_POST['role'] ?? 'both'));
    $hubId = (int)($_POST['hub_id'] ?? 0);
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($name === '' || $hubId === 0 || $email === '' || $password === '') {
        flash('err', 'Missing fields');
        redirect('admin', ['tab' => 'agents']);
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO agents (hub_id,name,phone,role,active,current_status,last_seen_ts,created_at) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$hubId, $name, $phone !== '' ? $phone : null, $role, 1, 'idle', null, nowIso()]);
        $agentId = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO users (email,password_hash,role,hub_id,agent_id,customer_id,active,created_at) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$email, password_hash($password, PASSWORD_DEFAULT), 'agent', $hubId, $agentId, null, 1, nowIso()]);
        $pdo->commit();
        flash('ok', 'Agent created');
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash('err', $e->getMessage());
    }
    redirect('admin', ['tab' => 'agents']);
}

function actionAdminCreateCustomer(PDO $pdo, array $u): void
{
    requireRole($u, ['admin']);
    csrfCheck();
    $name = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($name === '' || $email === '' || $password === '') {
        flash('err', 'Missing fields');
        redirect('admin', ['tab' => 'customers']);
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO customers (name,email,api_key,created_at) VALUES (?,?,?,?)")
            ->execute([$name, $email, null, nowIso()]);
        $cid = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO users (email,password_hash,role,hub_id,agent_id,customer_id,active,created_at) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$email, password_hash($password, PASSWORD_DEFAULT), 'customer', null, null, $cid, 1, nowIso()]);
        $pdo->commit();
        flash('ok', 'Customer created');
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash('err', $e->getMessage());
    }
    redirect('admin', ['tab' => 'customers']);
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
            (string)$route['name'],
            (string)$route['hub_name'],
            (string)$p['tracking_code'],
            (string)$p['pickup_address'],
            (string)$p['dropoff_address'],
            (int)$p['amount_cents'],
            (string)$p['status'],
            (string)($p['agent_name'] ?? ''),
            (string)$p['customer_name'],
        ]);
    }
    fclose($out);
    exit;
}

$pdo = null;
try {
    $pdo = db();
} catch (Throwable $e) {
    error_log((string)$e);
    renderFatal('HubRoute startup error', $e->getMessage() . "\n\nThis environment does not have SQLite enabled. On shared hosting, enable extensions: pdo_sqlite and sqlite3.");
    exit;
}

$pathInfo = (string)($_SERVER['PATH_INFO'] ?? '');
if ($pathInfo !== '' && preg_match('#^/track/([A-Za-z0-9_-]+)$#', $pathInfo, $m)) {
    $_GET['r'] = 'track';
    $_GET['code'] = $m[1];
}

$action = (string)($_POST['action'] ?? '');
if ($action !== '') {
    $u = currentUser($pdo);
    if ($action === 'login') {
        actionLogin($pdo);
    }
    if ($action === 'customer_create') {
        if (!$u) {
            redirect('login');
        }
        actionCustomerCreate($pdo, $u);
    }
    if ($action === 'customer_confirm') {
        if (!$u) {
            redirect('login');
        }
        actionCustomerConfirm($pdo, $u);
    }
    if ($action === 'hub_assign') {
        if (!$u) {
            redirect('login');
        }
        actionHubAssign($pdo, $u);
    }
    if ($action === 'hub_create_route') {
        if (!$u) {
            redirect('login');
        }
        actionHubCreateRoute($pdo, $u);
    }
    if ($action === 'record_event') {
        if (!$u) {
            redirect('login');
        }
        actionRecordEvent($pdo, $u);
    }
    if ($action === 'agent_step') {
        if (!$u) {
            redirect('login');
        }
        actionAgentStep($pdo, $u);
    }
    if ($action === 'settle') {
        if (!$u) {
            redirect('login');
        }
        actionSettle($pdo, $u);
    }
    if ($action === 'admin_create_hub') {
        if (!$u) {
            redirect('login');
        }
        actionAdminCreateHub($pdo, $u);
    }
    if ($action === 'admin_create_agent') {
        if (!$u) {
            redirect('login');
        }
        actionAdminCreateAgent($pdo, $u);
    }
    if ($action === 'admin_create_customer') {
        if (!$u) {
            redirect('login');
        }
        actionAdminCreateCustomer($pdo, $u);
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
    pageLogin($pdo);
    exit;
}
if ($route === 'logout') {
    actionLogout();
}
if ($route === 'track') {
    pagePublicTrack($pdo);
    exit;
}

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

