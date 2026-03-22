<?php
// Configuration
$DB_PATH = getenv('ANALYTICS_DB_PATH') ?: '/var/data/meditationsteps/analytics.db';
$SECRET = getenv('ANALYTICS_SECRET') ?: 'dev-secret-key';
$ALLOWED_ORIGIN = getenv('ANALYTICS_ORIGIN') ?: 'http://meditationsteps.lv.test';
$DEBUG = getenv('ANALYTICS_DEBUG') === '1';

// CORS headers
header('Access-Control-Allow-Origin: ' . $ALLOWED_ORIGIN);
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// Bot filtering
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$bots = ['Googlebot', 'bingbot', 'Baiduspider', 'YandexBot', 'DuckDuckBot',
         'Slurp', 'facebookexternalhit', 'Twitterbot', 'LinkedInBot',
         'Applebot', 'SemrushBot', 'AhrefsBot', 'MJ12bot', 'DotBot', 'PetalBot'];
foreach ($bots as $bot) {
    if (stripos($ua, $bot) !== false) {
        http_response_code(204);
        exit;
    }
}

// Parse input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['event'])) {
    http_response_code(400);
    exit;
}

// Validate event type
$allowed_events = ['pageview', 'click', 'section', 'accordion',
                   'conversion', 'duration', 'first_click'];
$event = $input['event'];
if (!in_array($event, $allowed_events, true)) {
    http_response_code(400);
    exit;
}

// Sanitize inputs
$event_data = isset($input['data']) ? json_encode($input['data']) : null;
if ($event_data !== null && strlen($event_data) > 1024) {
    http_response_code(400);
    exit;
}
$referrer = isset($input['referrer']) ? substr((string)$input['referrer'], 0, 2048) : null;
$utm_source = isset($input['utm_source']) ? substr((string)$input['utm_source'], 0, 255) : null;
$utm_medium = isset($input['utm_medium']) ? substr((string)$input['utm_medium'], 0, 255) : null;
$utm_campaign = isset($input['utm_campaign']) ? substr((string)$input['utm_campaign'], 0, 255) : null;
$lang = isset($input['lang']) ? substr((string)$input['lang'], 0, 5) : null;
$screen_width = isset($input['screen_width']) ? (int)$input['screen_width'] : null;

// Hash IP with daily-rotating salt
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$ip_hash = hash('sha256', $ip . date('Y-m-d') . $SECRET);

// Open database (auto-create tables)
$db = new SQLite3($DB_PATH);
$db->busyTimeout(5000);
$db->exec('PRAGMA journal_mode=WAL');

$db->exec("CREATE TABLE IF NOT EXISTS events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type TEXT NOT NULL,
    event_data TEXT,
    ip_hash TEXT NOT NULL,
    user_agent TEXT,
    referrer TEXT,
    utm_source TEXT,
    utm_medium TEXT,
    utm_campaign TEXT,
    language TEXT,
    screen_width INTEGER,
    created_at DATETIME DEFAULT (datetime('now'))
)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_events_type_date ON events(event_type, created_at)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_events_created ON events(created_at)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_events_ip_created ON events(ip_hash, created_at)");

// Rate limit: 50 events per IP per minute
$stmt = $db->prepare("SELECT COUNT(*) as cnt FROM events WHERE ip_hash = :ip AND created_at > datetime('now', '-1 minute')");
$stmt->bindValue(':ip', $ip_hash, SQLITE3_TEXT);
$result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
if ($result['cnt'] >= 50) {
    http_response_code(429);
    $db->close();
    exit;
}

// Insert event
$stmt = $db->prepare("INSERT INTO events (event_type, event_data, ip_hash, user_agent, referrer, utm_source, utm_medium, utm_campaign, language, screen_width) VALUES (:event_type, :event_data, :ip_hash, :user_agent, :referrer, :utm_source, :utm_medium, :utm_campaign, :language, :screen_width)");
$stmt->bindValue(':event_type', $event, SQLITE3_TEXT);
$stmt->bindValue(':event_data', $event_data, SQLITE3_TEXT);
$stmt->bindValue(':ip_hash', $ip_hash, SQLITE3_TEXT);
$stmt->bindValue(':user_agent', $ua, SQLITE3_TEXT);
$stmt->bindValue(':referrer', $referrer, SQLITE3_TEXT);
$stmt->bindValue(':utm_source', $utm_source, SQLITE3_TEXT);
$stmt->bindValue(':utm_medium', $utm_medium, SQLITE3_TEXT);
$stmt->bindValue(':utm_campaign', $utm_campaign, SQLITE3_TEXT);
$stmt->bindValue(':language', $lang, SQLITE3_TEXT);
$stmt->bindValue(':screen_width', $screen_width, SQLITE3_INTEGER);
$stmt->execute();

$last_id = $db->lastInsertRowID();
$db->close();

// Debug mode: return inserted data
if ($DEBUG && isset($_GET['debug'])) {
    header('Content-Type: application/json');
    echo json_encode(['id' => $last_id, 'event' => $event, 'data' => $input['data'] ?? null]);
} else {
    http_response_code(204);
}
