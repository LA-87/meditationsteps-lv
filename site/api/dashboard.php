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
