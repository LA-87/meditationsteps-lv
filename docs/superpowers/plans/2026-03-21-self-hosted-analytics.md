# Self-Hosted Analytics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add cookie-free, self-hosted analytics to meditationsteps.lv — tracking pageviews, CTA clicks, section visibility, schedule interactions, conversions, and referrers, with a password-protected dashboard.

**Architecture:** A ~60-line JS tracker in `index.html` sends events via `sendBeacon` to `track.php`, which stores them in a SQLite database. A self-contained `dashboard.php` page queries the DB and renders charts with Chart.js. For local dev, a `test-analytics.html` harness validates the full pipeline.

**Tech Stack:** Vanilla JS (client tracker), PHP + SQLite3 (backend), Chart.js CDN (dashboard charts), Laravel Herd (local dev), Nginx + PHP-FPM (production)

**Spec:** `docs/superpowers/specs/2026-03-21-self-hosted-analytics-design.md`

---

## File Structure

| File | Responsibility |
|------|---------------|
| `site/api/track.php` | Receives event POSTs, validates, rate-limits, stores in SQLite |
| `site/api/dashboard.php` | Password-protected analytics dashboard with charts |
| `site/api/test-analytics.html` | Manual test harness for verifying the pipeline locally |
| `site/index.html` | Existing file — add tracking script + `id="activities"` on activities section |

Database: `/var/data/meditationsteps/analytics.db` (production) or `/tmp/ms-analytics.db` (local dev)

---

### Task 1: Create track.php — Event Receiver

**Files:**
- Create: `site/api/track.php`

- [ ] **Step 1: Create the api directory**

Run: `mkdir -p site/api`

- [ ] **Step 2: Write track.php**

Create `site/api/track.php` with the following content:

```php
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
```

- [ ] **Step 3: Test locally with curl**

Run:
```
curl -v -X POST http://meditationsteps.lv.test/api/track.php \
  -H "Content-Type: application/json" \
  -H "Origin: http://meditationsteps.lv.test" \
  -d '{"event":"pageview","lang":"en","screen_width":1440,"referrer":"https://google.com"}'
```

Expected: HTTP 204 response. If Herd doesn't process PHP in `api/`, check Herd's PHP configuration.

- [ ] **Step 4: Commit**

```
git add site/api/track.php
git commit -m "feat: add analytics event tracking endpoint (track.php)"
```

---

### Task 2: Create Test Harness

**Files:**
- Create: `site/api/test-analytics.html`

- [ ] **Step 1: Write test-analytics.html**

Create `site/api/test-analytics.html` with the following content:

```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics Test Harness</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, sans-serif; max-width: 800px; margin: 2rem auto; padding: 0 1rem; background: #f5f5f5; }
        h1 { margin-bottom: 1rem; font-size: 1.5rem; }
        .test-group { background: white; border-radius: 8px; padding: 1.5rem; margin-bottom: 1rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .test-group h2 { font-size: 1rem; margin-bottom: 1rem; color: #333; }
        button { padding: 0.5rem 1rem; margin: 0.25rem; border: 1px solid #ddd; border-radius: 4px; background: white; cursor: pointer; font-size: 0.85rem; }
        button:hover { background: #f0f0f0; }
        button.success { border-color: #4caf50; background: #e8f5e9; }
        button.error { border-color: #f44336; background: #ffebee; }
        #log { background: #1e1e1e; color: #d4d4d4; padding: 1rem; border-radius: 8px; font-family: monospace; font-size: 0.8rem; max-height: 400px; overflow-y: auto; white-space: pre-wrap; margin-top: 1rem; }
        .log-entry { margin-bottom: 0.5rem; padding-bottom: 0.5rem; border-bottom: 1px solid #333; }
        .log-req { color: #569cd6; }
        .log-res-ok { color: #4ec9b0; }
        .log-res-err { color: #f44747; }
        .links { margin-top: 1rem; }
        .links a { color: #1976d2; margin-right: 1rem; }
    </style>
</head>
<body>
    <h1>Analytics Test Harness</h1>

    <div class="test-group">
        <h2>Pageview Events</h2>
        <button onclick="send('pageview', {is_return: false}, {referrer:'https://google.com', utm_source:'test', utm_medium:'manual', utm_campaign:'harness'})">Pageview (new visitor)</button>
        <button onclick="send('pageview', {is_return: true}, {referrer:'https://facebook.com'})">Pageview (return visitor)</button>
    </div>

    <div class="test-group">
        <h2>CTA Click Events</h2>
        <button onclick="send('click', {button:'hero'})">Click: Hero</button>
        <button onclick="send('click', {button:'nav-desktop'})">Click: Nav Desktop</button>
        <button onclick="send('click', {button:'nav-mobile'})">Click: Nav Mobile</button>
        <button onclick="send('click', {button:'pricing'})">Click: Pricing</button>
        <button onclick="send('click', {button:'sticky-mobile'})">Click: Sticky Mobile</button>
    </div>

    <div class="test-group">
        <h2>Section Visibility Events</h2>
        <button onclick="send('section', {section:'programme'})">Section: Programme</button>
        <button onclick="send('section', {section:'teachers'})">Section: Teachers</button>
        <button onclick="send('section', {section:'activities'})">Section: Activities</button>
        <button onclick="send('section', {section:'schedule'})">Section: Schedule</button>
        <button onclick="send('section', {section:'location'})">Section: Location</button>
        <button onclick="send('section', {section:'tickets'})">Section: Tickets</button>
    </div>

    <div class="test-group">
        <h2>Engagement Events</h2>
        <button onclick="send('accordion', {day:1})">Accordion: Day 1</button>
        <button onclick="send('accordion', {day:2})">Accordion: Day 2</button>
        <button onclick="send('accordion', {day:3})">Accordion: Day 3</button>
        <button onclick="send('accordion', {day:4})">Accordion: Day 4</button>
        <button onclick="send('duration', {seconds:95})">Duration: 95s</button>
        <button onclick="send('first_click', {seconds:12.5})">First Click: 12.5s</button>
        <button onclick="send('conversion', {})">Conversion</button>
    </div>

    <div class="links">
        <a href="dashboard.php" target="_blank">Open Dashboard</a>
    </div>

    <div id="log"></div>

    <script>
        var TRACK_URL = '/api/track.php?debug=1';
        var logEl = document.getElementById('log');

        async function send(event, data, extra) {
            extra = extra || {};
            var payload = Object.assign({
                event: event,
                data: data,
                lang: 'en',
                screen_width: window.innerWidth
            }, extra);

            var entry = document.createElement('div');
            entry.className = 'log-entry';
            entry.innerHTML = '<span class="log-req">-> POST ' + event + '</span>\n' + JSON.stringify(payload, null, 2);
            logEl.prepend(entry);

            try {
                var res = await fetch(TRACK_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                var text = await res.text();
                var cls = res.ok ? 'log-res-ok' : 'log-res-err';
                entry.innerHTML += '\n<span class="' + cls + '"><- ' + res.status + ' ' + text + '</span>';
            } catch (err) {
                entry.innerHTML += '\n<span class="log-res-err"><- ERROR: ' + err.message + '</span>';
            }
        }
    </script>
</body>
</html>
```

- [ ] **Step 2: Open in browser and test**

Open `http://meditationsteps.lv.test/api/test-analytics.html`. Click each button. Verify:
- Each button returns HTTP 204 (or 200 with JSON in debug mode)
- The log panel shows the payload and response
- No CORS errors in browser DevTools console

- [ ] **Step 3: Commit**

```
git add site/api/test-analytics.html
git commit -m "feat: add analytics test harness page"
```

---

### Task 3: Add Tracking Script to index.html

**Files:**
- Modify: `site/index.html:591` (add `id="activities"` to activities section)
- Modify: `site/index.html:726` (add tracking script before `</body>`)

- [ ] **Step 1: Add `id="activities"` to the activities section**

On line 591, change:
```html
<section class="section-pad" style="background:var(--c-forest);color:var(--c-cream);">
```
to:
```html
<section id="activities" class="section-pad" style="background:var(--c-forest);color:var(--c-cream);">
```

- [ ] **Step 2: Add the analytics tracking script before `</body>`**

Insert the following script block after the existing Alpine.js script block (after line 726's `</script>`, before `</body>` on line 727):

```html
<!-- Analytics Tracker -->
<script>
(function() {
    var TRACK = '/api/track.php';
    var params = new URLSearchParams(location.search);
    var utm = {
        utm_source: params.get('utm_source') || undefined,
        utm_medium: params.get('utm_medium') || undefined,
        utm_campaign: params.get('utm_campaign') || undefined
    };
    var loadTime = performance.now();
    var activeStart = Date.now();
    var activeMs = 0;
    var firstClickSent = false;
    var sectionsTracked = {};
    var accordionsTracked = {};

    function track(event, data, extra) {
        var payload = Object.assign({
            event: event,
            data: data || {},
            lang: document.documentElement.lang || 'en',
            screen_width: window.innerWidth
        }, utm, extra || {});
        Object.keys(payload).forEach(function(k) {
            if (payload[k] === undefined) delete payload[k];
        });
        var body = JSON.stringify(payload);
        if (navigator.sendBeacon) {
            navigator.sendBeacon(TRACK, new Blob([body], {type: 'application/json'}));
        } else {
            fetch(TRACK, {method:'POST', body:body, headers:{'Content-Type':'application/json'}, keepalive:true});
        }
    }

    // Pageview
    var isReturn = !!localStorage.getItem('ms_visited');
    localStorage.setItem('ms_visited', '1');
    track('pageview', {is_return: isReturn}, {referrer: document.referrer || undefined});

    // Conversion check
    if (params.get('registered') === 'true') {
        track('conversion', {});
    }

    // Section visibility tracking
    var sectionObserver = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            if (entry.isIntersecting) {
                var id = entry.target.id;
                if (id && !sectionsTracked[id]) {
                    sectionsTracked[id] = true;
                    track('section', {section: id});
                }
            }
        });
    }, {threshold: 0.3});

    document.querySelectorAll('section[id]').forEach(function(el) {
        sectionObserver.observe(el);
    });

    // CTA click tracking
    function getButtonLocation(el) {
        if (el.closest('.sticky-cta')) return 'sticky-mobile';
        if (el.closest('.mobile-menu')) return 'nav-mobile';
        var navOrHeader = el.closest('nav') || el.closest('header');
        if (navOrHeader) {
            if (el.classList.contains('desktop-only')) return 'nav-desktop';
            return 'nav';
        }
        if (el.closest('#tickets')) return 'pricing';
        if (el.closest('.hero-section')) return 'hero';
        var sec = el.closest('section[id]');
        return sec ? sec.id : 'unknown';
    }

    document.querySelectorAll('a.btn-primary').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var loc = getButtonLocation(this);
            track('click', {button: loc});
            if (!firstClickSent) {
                firstClickSent = true;
                var elapsed = Math.round((performance.now() - loadTime) / 100) / 10;
                track('first_click', {seconds: elapsed});
            }
        });
    });

    // Schedule accordion tracking
    document.querySelectorAll('.day-tab').forEach(function(tab, i) {
        tab.addEventListener('click', function() {
            var day = i + 1;
            if (!accordionsTracked[day]) {
                accordionsTracked[day] = true;
                track('accordion', {day: day});
            }
        });
    });

    // Active duration tracking — only send once on final page close
    var durationSent = false;
    function sendDuration() {
        if (durationSent) return;
        durationSent = true;
        activeMs += Date.now() - activeStart;
        track('duration', {seconds: Math.round(activeMs / 1000)});
    }
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            activeMs += Date.now() - activeStart;
        } else {
            activeStart = Date.now();
            durationSent = false; // allow re-send if user returns then leaves again
        }
    });
    window.addEventListener('beforeunload', sendDuration);
    window.addEventListener('pagehide', sendDuration);
})();
</script>
```

- [ ] **Step 3: Test in browser**

Open `http://meditationsteps.lv.test/` and check browser DevTools Network tab:
- On load: a `pageview` POST should fire to `/api/track.php`
- Scroll down: `section` events should fire as each section becomes visible
- Click a "Register" button: `click` and `first_click` events should fire
- Click a schedule day tab: `accordion` event should fire
- Switch to another tab: `duration` event should fire

- [ ] **Step 4: Verify events are stored in the database**

Use curl or the test harness to confirm data is being recorded. Check browser DevTools Network tab to verify all event types return 204.

- [ ] **Step 5: Commit**

```
git add site/index.html
git commit -m "feat: add analytics tracking script and section IDs to index.html"
```

---

### Task 4: Create Dashboard

**Files:**
- Create: `site/api/dashboard.php`

- [ ] **Step 1: Write dashboard.php**

This is the largest file. It is self-contained: HTML, CSS, PHP queries, and Chart.js rendering all in one file. Create `site/api/dashboard.php` with the following content:

```php
<?php
session_start();

// Configuration
$DB_PATH = getenv('ANALYTICS_DB_PATH') ?: '/var/data/meditationsteps/analytics.db';
$PASSWORD = getenv('ANALYTICS_PASSWORD') ?: false;

// Password gate
if (!$PASSWORD) {
    die('ANALYTICS_PASSWORD environment variable not set.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (hash_equals($PASSWORD, $_POST['password'])) {
        $_SESSION['analytics_auth'] = true;
    }
}

if (empty($_SESSION['analytics_auth'])) {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Analytics Login</title>
    <style>body{font-family:-apple-system,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f5f0e8;margin:0;}
    form{background:white;padding:2rem;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,0.1);text-align:center;}
    input{padding:0.75rem 1rem;border:1px solid #ddd;border-radius:6px;font-size:1rem;margin:1rem 0;width:250px;display:block;}
    button{padding:0.75rem 2rem;background:#1e3828;color:white;border:none;border-radius:6px;font-size:1rem;cursor:pointer;}
    button:hover{background:#2a4d35;}</style></head><body>
    <form method="POST"><h2>Analytics</h2><input type="password" name="password" placeholder="Password" autofocus>
    <button type="submit">Login</button></form></body></html>';
    exit;
}

// Open database
if (!file_exists($DB_PATH)) {
    die('No analytics data yet. Database not found at: ' . htmlspecialchars($DB_PATH));
}
$db = new SQLite3($DB_PATH, SQLITE3_OPEN_READONLY);

// Time range
$range = $_GET['range'] ?? '7d';
$range_map = [
    'today' => "datetime('now', 'start of day')",
    '7d' => "datetime('now', '-7 days')",
    '30d' => "datetime('now', '-30 days')",
    'all' => "'2000-01-01'"
];
$since = $range_map[$range] ?? $range_map['7d'];

// Helper: query
function q($db, $sql, $params = []) {
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $result = $stmt->execute();
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;
    return $rows;
}

// Queries
$visitors_daily = q($db, "SELECT date(created_at) as day, COUNT(*) as views, COUNT(DISTINCT ip_hash) as visitors FROM events WHERE event_type='pageview' AND created_at >= $since GROUP BY day ORDER BY day");
$total_visitors = q($db, "SELECT COUNT(DISTINCT ip_hash) as cnt FROM events WHERE event_type='pageview' AND created_at >= $since")[0]['cnt'] ?? 0;
$total_pageviews = q($db, "SELECT COUNT(*) as cnt FROM events WHERE event_type='pageview' AND created_at >= $since")[0]['cnt'] ?? 0;
$total_conversions = q($db, "SELECT COUNT(*) as cnt FROM events WHERE event_type='conversion' AND created_at >= $since")[0]['cnt'] ?? 0;
$conversion_rate = $total_visitors > 0 ? round($total_conversions / $total_visitors * 100, 1) : 0;

$return_visitors = q($db, "SELECT COUNT(DISTINCT ip_hash) as cnt FROM events WHERE event_type='pageview' AND event_data LIKE '%\"is_return\":true%' AND created_at >= $since")[0]['cnt'] ?? 0;
$return_rate = $total_visitors > 0 ? round($return_visitors / $total_visitors * 100, 1) : 0;

$sections = q($db, "SELECT json_extract(event_data, '\$.section') as section, COUNT(DISTINCT ip_hash) as visitors FROM events WHERE event_type='section' AND created_at >= $since GROUP BY section ORDER BY visitors DESC");
$section_order = ['programme','teachers','activities','schedule','location','tickets'];

$clicks = q($db, "SELECT json_extract(event_data, '\$.button') as button, COUNT(*) as cnt FROM events WHERE event_type='click' AND created_at >= $since GROUP BY button ORDER BY cnt DESC");

$first_click_avg = q($db, "SELECT ROUND(AVG(json_extract(event_data, '\$.seconds')), 1) as avg_sec FROM events WHERE event_type='first_click' AND created_at >= $since")[0]['avg_sec'] ?? 0;

$accordion = q($db, "SELECT json_extract(event_data, '\$.day') as day, COUNT(*) as cnt FROM events WHERE event_type='accordion' AND created_at >= $since GROUP BY day ORDER BY day");

$referrers = q($db, "SELECT referrer, COUNT(*) as cnt FROM events WHERE event_type='pageview' AND referrer IS NOT NULL AND referrer != '' AND created_at >= $since GROUP BY referrer ORDER BY cnt DESC LIMIT 15");

$utm = q($db, "SELECT utm_source, utm_medium, utm_campaign, COUNT(*) as cnt FROM events WHERE event_type='pageview' AND utm_source IS NOT NULL AND created_at >= $since GROUP BY utm_source, utm_medium, utm_campaign ORDER BY cnt DESC LIMIT 20");

$devices = q($db, "SELECT CASE WHEN screen_width < 768 THEN 'Mobile' WHEN screen_width < 1024 THEN 'Tablet' ELSE 'Desktop' END as device, COUNT(DISTINCT ip_hash) as cnt FROM events WHERE event_type='pageview' AND screen_width IS NOT NULL AND created_at >= $since GROUP BY device ORDER BY cnt DESC");

$languages = q($db, "SELECT language as lang, COUNT(DISTINCT ip_hash) as cnt FROM events WHERE event_type='pageview' AND language IS NOT NULL AND created_at >= $since GROUP BY language ORDER BY cnt DESC");

$avg_duration = q($db, "SELECT ROUND(AVG(json_extract(event_data, '\$.seconds'))) as avg_sec FROM events WHERE event_type='duration' AND json_extract(event_data, '\$.seconds') > 5 AND created_at >= $since")[0]['avg_sec'] ?? 0;

$db->close();

// Build section funnel data in order
$section_map = [];
foreach ($sections as $s) $section_map[$s['section']] = $s['visitors'];
$funnel_labels = [];
$funnel_values = [];
foreach ($section_order as $sid) {
    $funnel_labels[] = ucfirst($sid);
    $funnel_values[] = $section_map[$sid] ?? 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics - meditationsteps.lv</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f0e8; color: #1e3828; }
        .header { background: #1e3828; color: #f5f0e8; padding: 1.5rem 2rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; }
        .header h1 { font-size: 1.2rem; font-weight: 500; }
        .range-btns { display: flex; gap: 0.5rem; }
        .range-btns a { color: #f5f0e8; text-decoration: none; padding: 0.4rem 1rem; border-radius: 20px; font-size: 0.8rem; opacity: 0.6; transition: opacity 0.2s; }
        .range-btns a:hover, .range-btns a.active { opacity: 1; background: rgba(245,240,232,0.15); }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.5rem; padding: 2rem; max-width: 1400px; margin: 0 auto; }
        .card { background: white; border-radius: 12px; padding: 1.5rem; box-shadow: 0 1px 4px rgba(0,0,0,0.06); }
        .card h2 { font-size: 0.85rem; color: #7a9e87; text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 1rem; font-weight: 600; }
        .big-number { font-size: 2.5rem; font-weight: 700; color: #1e3828; }
        .big-label { font-size: 0.85rem; color: #999; margin-top: 0.25rem; }
        .metric-row { display: flex; gap: 2rem; flex-wrap: wrap; }
        .metric { text-align: center; }
        table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        th { text-align: left; padding: 0.5rem 0; border-bottom: 2px solid #eee; color: #7a9e87; font-weight: 600; }
        td { padding: 0.5rem 0; border-bottom: 1px solid #f0f0f0; }
        td:last-child { text-align: right; font-weight: 600; }
        .chart-wrap { position: relative; height: 250px; }
        .wide { grid-column: 1 / -1; }
        @media (max-width: 600px) { .grid { padding: 1rem; gap: 1rem; } .header { padding: 1rem; } }
    </style>
</head>
<body>
    <div class="header">
        <h1>meditationsteps.lv Analytics</h1>
        <div class="range-btns">
            <a href="?range=today" class="<?= $range === 'today' ? 'active' : '' ?>">Today</a>
            <a href="?range=7d" class="<?= $range === '7d' ? 'active' : '' ?>">7 Days</a>
            <a href="?range=30d" class="<?= $range === '30d' ? 'active' : '' ?>">30 Days</a>
            <a href="?range=all" class="<?= $range === 'all' ? 'active' : '' ?>">All Time</a>
        </div>
    </div>

    <div class="grid">
        <!-- Key Metrics -->
        <div class="card">
            <h2>Overview</h2>
            <div class="metric-row">
                <div class="metric"><div class="big-number"><?= $total_visitors ?></div><div class="big-label">Unique Visitors</div></div>
                <div class="metric"><div class="big-number"><?= $total_pageviews ?></div><div class="big-label">Pageviews</div></div>
                <div class="metric"><div class="big-number"><?= $total_conversions ?></div><div class="big-label">Conversions</div></div>
                <div class="metric"><div class="big-number"><?= $conversion_rate ?>%</div><div class="big-label">Conv. Rate</div></div>
            </div>
        </div>

        <div class="card">
            <h2>Engagement</h2>
            <div class="metric-row">
                <div class="metric"><div class="big-number"><?= $return_rate ?>%</div><div class="big-label">Return Visitors</div></div>
                <div class="metric"><div class="big-number"><?= $first_click_avg ?>s</div><div class="big-label">Avg Time to First Click</div></div>
                <div class="metric"><div class="big-number"><?= $avg_duration ?>s</div><div class="big-label">Avg Active Duration</div></div>
            </div>
        </div>

        <!-- Visitors Chart -->
        <div class="card wide">
            <h2>Visitors &amp; Pageviews</h2>
            <div class="chart-wrap"><canvas id="visitorsChart"></canvas></div>
        </div>

        <!-- Section Funnel -->
        <div class="card">
            <h2>Section Engagement Funnel</h2>
            <div class="chart-wrap"><canvas id="funnelChart"></canvas></div>
        </div>

        <!-- CTA Clicks -->
        <div class="card">
            <h2>CTA Clicks by Location</h2>
            <div class="chart-wrap"><canvas id="clicksChart"></canvas></div>
        </div>

        <!-- Schedule Interest -->
        <div class="card">
            <h2>Schedule Interest (Accordion Opens)</h2>
            <div class="chart-wrap"><canvas id="accordionChart"></canvas></div>
        </div>

        <!-- Devices -->
        <div class="card">
            <h2>Devices</h2>
            <div class="chart-wrap"><canvas id="devicesChart"></canvas></div>
        </div>

        <!-- Language -->
        <div class="card">
            <h2>Language Split</h2>
            <div class="chart-wrap"><canvas id="langChart"></canvas></div>
        </div>

        <!-- Referrers -->
        <div class="card">
            <h2>Top Referrers</h2>
            <?php if (empty($referrers)): ?>
                <p style="color:#999;font-size:0.9rem;">No referrer data yet</p>
            <?php else: ?>
            <table>
                <tr><th>Referrer</th><th>Visits</th></tr>
                <?php foreach ($referrers as $r): ?>
                <tr><td><?= htmlspecialchars(parse_url($r['referrer'], PHP_URL_HOST) ?: $r['referrer']) ?></td><td><?= $r['cnt'] ?></td></tr>
                <?php endforeach; ?>
            </table>
            <?php endif; ?>
        </div>

        <!-- UTM Campaigns -->
        <div class="card">
            <h2>UTM Campaigns</h2>
            <?php if (empty($utm)): ?>
                <p style="color:#999;font-size:0.9rem;">No UTM data yet</p>
            <?php else: ?>
            <table>
                <tr><th>Source</th><th>Medium</th><th>Campaign</th><th>Visits</th></tr>
                <?php foreach ($utm as $u): ?>
                <tr><td><?= htmlspecialchars($u['utm_source'] ?? '-') ?></td><td><?= htmlspecialchars($u['utm_medium'] ?? '-') ?></td><td><?= htmlspecialchars($u['utm_campaign'] ?? '-') ?></td><td><?= $u['cnt'] ?></td></tr>
                <?php endforeach; ?>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <script>
        var C = {
            forest: '#1e3828', sage: '#7a9e87', terracotta: '#a8503a',
            gold: '#c4960a', cream: '#f5f0e8', blue: '#4a7c8f'
        };

        // Visitors chart
        new Chart(document.getElementById('visitorsChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_column($visitors_daily, 'day')) ?>,
                datasets: [
                    { label: 'Visitors', data: <?= json_encode(array_map('intval', array_column($visitors_daily, 'visitors'))) ?>, backgroundColor: C.forest, borderRadius: 4, order: 2 },
                    { label: 'Pageviews', data: <?= json_encode(array_map('intval', array_column($visitors_daily, 'views'))) ?>, type: 'line', borderColor: C.sage, pointBackgroundColor: C.sage, tension: 0.3, order: 1 }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true } } }
        });

        // Section funnel
        new Chart(document.getElementById('funnelChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($funnel_labels) ?>,
                datasets: [{ data: <?= json_encode($funnel_values) ?>, backgroundColor: [C.forest, C.sage, C.terracotta, C.gold, C.blue, C.forest], borderRadius: 4 }]
            },
            options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true } } }
        });

        // CTA clicks
        var clickData = <?= json_encode($clicks) ?>;
        new Chart(document.getElementById('clicksChart'), {
            type: 'bar',
            data: {
                labels: clickData.map(function(c) { return c.button; }),
                datasets: [{ data: clickData.map(function(c) { return parseInt(c.cnt); }), backgroundColor: C.terracotta, borderRadius: 4 }]
            },
            options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true } } }
        });

        // Accordion
        var accData = <?= json_encode($accordion) ?>;
        new Chart(document.getElementById('accordionChart'), {
            type: 'bar',
            data: {
                labels: accData.map(function(a) { return 'Day ' + a.day; }),
                datasets: [{ data: accData.map(function(a) { return parseInt(a.cnt); }), backgroundColor: C.sage, borderRadius: 4 }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
        });

        // Devices
        var devData = <?= json_encode($devices) ?>;
        new Chart(document.getElementById('devicesChart'), {
            type: 'doughnut',
            data: {
                labels: devData.map(function(d) { return d.device; }),
                datasets: [{ data: devData.map(function(d) { return parseInt(d.cnt); }), backgroundColor: [C.forest, C.sage, C.terracotta] }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
        });

        // Language
        var langData = <?= json_encode($languages) ?>;
        new Chart(document.getElementById('langChart'), {
            type: 'doughnut',
            data: {
                labels: langData.map(function(l) { return (l.lang || 'unknown').toUpperCase(); }),
                datasets: [{ data: langData.map(function(l) { return parseInt(l.cnt); }), backgroundColor: [C.forest, C.terracotta, C.sage] }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
        });
    </script>
</body>
</html>
```

- [ ] **Step 2: Test the dashboard locally**

1. Generate some test data: open `http://meditationsteps.lv.test/api/test-analytics.html` and click several buttons.
2. Open `http://meditationsteps.lv.test/api/dashboard.php`.
3. Verify: login form appears, enter password, dashboard loads with charts showing the test data.
4. Test each time range button (Today, 7d, 30d, All Time).
5. Check that all chart sections render without JavaScript errors (DevTools console).

- [ ] **Step 3: Test with real site browsing**

1. Open `http://meditationsteps.lv.test/` in a fresh tab.
2. Scroll through the entire page, click a CTA button, switch language, click schedule day tabs.
3. Open the dashboard and verify the real browsing events appear in all sections.

- [ ] **Step 4: Commit**

```
git add site/api/dashboard.php
git commit -m "feat: add analytics dashboard with charts and auth"
```

---

### Task 5: Add .gitignore Entries and Update CLAUDE.md

**Files:**
- Modify: `.gitignore`
- Modify: `CLAUDE.md`

- [ ] **Step 1: Add gitignore entries**

Add to `.gitignore`:
```
# Analytics
*.db
site/api/.env
```

- [ ] **Step 2: Update CLAUDE.md with analytics section**

Add the following section to the end of `CLAUDE.md`:

```markdown
## Analytics System

Self-hosted, cookie-free analytics. See `docs/superpowers/specs/2026-03-21-self-hosted-analytics-design.md` for full spec.

**Files:**
- `site/api/track.php` — event receiver (POST endpoint), stores to SQLite
- `site/api/dashboard.php` — password-protected dashboard with Chart.js
- `site/api/test-analytics.html` — local testing harness (not for production)
- Tracking script is inline at the bottom of `site/index.html`

**Environment variables (set on server, not in git):**
- `ANALYTICS_PASSWORD` — dashboard login password
- `ANALYTICS_SECRET` — salt for IP hashing
- `ANALYTICS_ORIGIN` — allowed CORS origin (default: `http://meditationsteps.lv.test`)
- `ANALYTICS_DB_PATH` — SQLite database path (default: `/var/data/meditationsteps/analytics.db`)
- `ANALYTICS_DEBUG` — set to `1` for debug mode (local dev only)

**Events tracked:** `pageview`, `click`, `section`, `accordion`, `conversion`, `duration`, `first_click`
```

- [ ] **Step 3: Commit**

```
git add .gitignore CLAUDE.md
git commit -m "docs: add analytics system documentation and gitignore entries"
```

---

### Task 6: Final Integration Test

- [ ] **Step 1: Full pipeline test**

1. Clear any existing test database (delete the SQLite file at the configured local path)
2. Open `http://meditationsteps.lv.test/` in browser
3. Perform these actions on the site:
   - Page loads: verify `pageview` fires in Network tab
   - Scroll to each section: verify `section` events fire
   - Click a "Register Now" CTA: verify `click` + `first_click` fire
   - Click schedule Day 2 tab: verify `accordion` fires
   - Switch to another tab: verify `duration` fires
4. Open `http://meditationsteps.lv.test/api/dashboard.php`
5. Verify all dashboard sections show data from the browsing session
6. Test with `?range=today`: data should appear
7. Open test harness, fire a `conversion` event, refresh dashboard: verify conversion count increases

- [ ] **Step 2: Mobile responsiveness check**

Open dashboard in browser DevTools responsive mode (375px width). Verify:
- Cards stack in single column
- Charts are readable
- Tables don't overflow
- Login form works

- [ ] **Step 3: Verify no console errors**

Check browser DevTools console on both the main site and dashboard. There should be zero JavaScript errors related to analytics.
