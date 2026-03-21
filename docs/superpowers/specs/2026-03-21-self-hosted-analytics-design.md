# Self-Hosted Analytics for meditationsteps.lv

## Overview

Lightweight, cookie-free, self-hosted analytics system for the meditation retreat single-page site. Tracks pageviews, CTA clicks, scroll depth, referrers, and UTM campaigns. Data stored in SQLite, viewed via a password-protected HTML dashboard.

## Architecture

```
site/index.html                   site/api/track.php
  |                                  |
  |  POST /api/track.php             |
  |  {event, metadata}  ---------->  | --> /var/data/meditationsteps/analytics.db
  |                                  |
  |                          site/api/dashboard.php
  |                                  |
  |  GET /api/dashboard.php          |
  |  (password-protected)  <-------  | <-- /var/data/meditationsteps/analytics.db
```

No cookies. No fingerprinting. No sessions. Each event is independent.

## Components

### 1. Tracker Script (inline JS in index.html, ~40 lines)

Added before `</body>` in `site/index.html`.

**Events tracked:**

| Event | Trigger | Data Captured |
|-------|---------|---------------|
| `pageview` | Page load | referrer, UTM params (source/medium/campaign), language, screen width, is_return (boolean via localStorage) |
| `click` | CTA button click | button location: `hero`, `nav-desktop`, `nav-mobile`, `pricing`, `sticky-mobile` |
| `section` | Section enters viewport | section id: `programme`, `teachers`, `schedule`, `location`, `activities`, `tickets` (credibility stats are inside `#teachers` section, not tracked separately) |
| `accordion` | Schedule day tab or accordion opened | day number (1-4) |
| `conversion` | Page load with `?registered=true` | (set as Google Form redirect URL) |
| `duration` | Page unload | active seconds spent on page |
| `first_click` | First CTA click in session | seconds elapsed since page load |

**Implementation details:**
- Uses `navigator.sendBeacon()` for all event delivery (reliable on page close)
- Fallback to `fetch()` with `keepalive: true` if sendBeacon unavailable
- Section visibility tracking: use `IntersectionObserver` (threshold: 0.3) on each `<section>` with an `id` attribute plus the credibility/activities containers. Fire once per section per pageview. This replaces percentage-based scroll depth — knowing "60% saw the pricing section" is more actionable than "60% scrolled to 75%".
- Schedule accordion tracking: listen for clicks on `.day-tab` buttons, fire `accordion` event with the day number. Each day fires only once per pageview.
- CTA click tracking: attach event listeners to all `a.btn-primary` elements. Identify button location using this algorithm:
  1. If the link is inside `.sticky-cta` → `sticky-mobile`
  2. If inside `nav` or `header` and has class `mobile-only` or is inside the mobile overlay → `nav-mobile`
  3. If inside `nav` or `header` and has class `desktop-only` → `nav-desktop`
  4. If inside `#tickets` section → `pricing`
  5. If inside `.hero-section` → `hero`
  6. Fallback: closest `section[id]` attribute value, or `unknown`
- Language detection: read `document.documentElement.lang` (the `<html>` element has `:lang="lang"` binding which Alpine.js sets reactively)
- Return visitor detection: on first visit, set `localStorage.setItem('ms_visited', '1')`. On subsequent visits, check for this key and include `is_return: true` in the pageview event. No cookies needed.
- Conversion tracking: on page load, check for `?registered=true` in the URL. If present, fire a `conversion` event. The Google Form must be configured to redirect to `https://meditationsteps.lv?registered=true` on submission.
- Time-to-first-click: record `performance.now()` on page load. On the first CTA click, fire a `first_click` event with elapsed seconds. Only fires once per pageview.
- UTM params: parse from `window.location.search` on page load
- Duration: record timestamp on load, pause timer on `visibilitychange` (hidden), resume on visible, send accumulated active time on `visibilitychange` (hidden) or `beforeunload`
- All events POST to `/api/track.php` as JSON

**Payload format:**
```json
{
  "event": "pageview|click|section|accordion|conversion|duration|first_click",
  "data": {
    "button": "hero",
    "section": "teachers",
    "day": 2,
    "seconds": 142,
    "is_return": true
  },
  "referrer": "https://facebook.com/...",
  "utm_source": "facebook",
  "utm_medium": "cpc",
  "utm_campaign": "spring2026",
  "lang": "en",
  "screen_width": 1440
}
```
Note: only relevant `data` fields are sent per event type. E.g., `click` sends `button`, `section` sends `section`, `duration` sends `seconds`.

### 2. track.php (~60 lines)

Location: `site/api/track.php`

**Responsibilities:**
- Accept POST requests with JSON body
- Validate `event` field against whitelist: `pageview`, `click`, `section`, `accordion`, `conversion`, `duration`, `first_click`
- Validate and sanitize all input (max string lengths, numeric ranges)
- Hash the client IP with SHA256 using a daily-rotating salt: `SHA256(ip + date('Y-m-d') + SECRET_KEY)`. This prevents rainbow table reversal and also avoids long-term visitor tracking across days. The `SECRET_KEY` is read from an environment variable `ANALYTICS_SECRET`.
- Insert into SQLite `events` table
- Rate limit: max 50 events per IP hash per minute (use a simple COUNT query with timestamp filter). Return 429 if exceeded.
- Auto-create database and tables on first request if they don't exist
- Return 200 with empty body on success, appropriate error codes on failure
- Read User-Agent server-side from `$_SERVER['HTTP_USER_AGENT']` (not sent by client — avoids payload bloat)
- Basic bot filtering: skip recording events if User-Agent matches common bots (Googlebot, bingbot, Baiduspider, YandexBot, etc.)
- Set CORS headers: `Access-Control-Allow-Origin` should be set to the exact site origin (e.g., `https://meditationsteps.lv`). Define as a constant or env var so it can be adjusted for www/non-www

**Database path:** `/var/data/meditationsteps/analytics.db`
- This path is outside the web root for security
- The directory must be writable by the web server user (www-data)
- PHP's SQLite3 extension is required (standard in most PHP installs)

**Table schema:**
```sql
CREATE TABLE IF NOT EXISTS events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type TEXT NOT NULL,
    event_data TEXT,          -- JSON string for event-specific data
    ip_hash TEXT NOT NULL,
    user_agent TEXT,
    referrer TEXT,
    utm_source TEXT,
    utm_medium TEXT,
    utm_campaign TEXT,
    language TEXT,             -- 'en' or 'ru'
    screen_width INTEGER,
    created_at DATETIME DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_events_type_date ON events(event_type, created_at);
CREATE INDEX IF NOT EXISTS idx_events_created ON events(created_at);
CREATE INDEX IF NOT EXISTS idx_events_ip_created ON events(ip_hash, created_at);
```

### 3. dashboard.php (~200 lines, self-contained)

Location: `site/api/dashboard.php`

**Authentication:** Simple password check. Password read from environment variable `ANALYTICS_PASSWORD` (via `getenv()`). If the variable is not set, the dashboard refuses to serve and shows an error. Uses a form POST to submit password, verified against the env var, then stored in a PHP session for the browser tab. Requires `session_start()`. Not high-security — just prevents casual access. Note: the PHP session cookie is the only cookie in the system and only applies to the dashboard page, not to visitors.

**Time range selector:** Today, Last 7 days, Last 30 days, All time. Passed as query parameter. Valid values: `?range=today`, `?range=7d`, `?range=30d`, `?range=all` (default: `7d`).

**Dashboard sections:**

| Section | Query | Visualization |
|---------|-------|---------------|
| **Visitors & Pageviews** | COUNT(DISTINCT ip_hash) and COUNT(*) WHERE event_type='pageview' grouped by date | Line/bar chart (daily) |
| **Conversions** | COUNT WHERE event_type='conversion', plus conversion rate (conversions / unique pageview visitors) | Big number + daily trend |
| **Return Visitors** | COUNT(DISTINCT ip_hash) WHERE event_type='pageview' AND event_data contains is_return=true, as % of total unique | Percentage + bar |
| **Section Engagement Funnel** | WHERE event_type='section', for each section: count unique ip_hash as % of total pageview visitors | Funnel chart (programme → teachers → schedule → ... → tickets) |
| **CTA Clicks** | WHERE event_type='click', GROUP BY event_data button value | Horizontal bar chart |
| **Time to First Click** | WHERE event_type='first_click', AVG/median of seconds | Single number + distribution |
| **Schedule Interest** | WHERE event_type='accordion', GROUP BY day number | Bar chart (Day 1-4) |
| **Top Referrers** | GROUP BY referrer, COUNT, ordered desc | Table with counts |
| **UTM Campaigns** | GROUP BY utm_source, utm_medium, utm_campaign, with CTA click count per campaign | Table with counts + click-through |
| **Devices** | Derive from screen_width: <768 = mobile, 768-1024 = tablet, >1024 = desktop | Pie/donut or simple bars |
| **Language Split** | GROUP BY language WHERE event_type='pageview' | Two bars (EN vs RU) |
| **Avg Active Duration** | WHERE event_type='duration', AVG of seconds value | Single number |

**Rendering:** Self-contained HTML page. Charts via Chart.js loaded from CDN (~60KB). All styles inline. CDN is acceptable here since only the admin sees the dashboard page, not site visitors.

### 4. SQLite Database

**Location:** `/var/data/meditationsteps/analytics.db`

**Retention:** All data kept forever. For a single-page site with moderate traffic, the DB will stay small (estimate: ~10MB per 100K events).

**Backup:** Standard file copy. Can be backed up with server backup scripts.

## Google Form Configuration

The Google Form used for registration must be configured to redirect on submission:
- In Google Forms: Settings → Presentation → Confirmation message → set to redirect
- Alternatively, use a custom "thank you" response that includes a link back to `https://meditationsteps.lv?registered=true`
- This enables closed-loop conversion tracking (CTA click → form submission → conversion event)

## File Changes Summary

| File | Action | Description |
|------|--------|-------------|
| `site/index.html` | Edit | Add ~60 lines tracking JS before `</body>` |
| `site/api/track.php` | Create | Event receiver + SQLite writer (~80 lines) |
| `site/api/dashboard.php` | Create | Password-protected analytics dashboard (~250 lines) |
| `site/api/test-analytics.html` | Create | Test harness page for verifying analytics locally (~80 lines) |

## Local Testing

### Test Harness (`site/api/test-analytics.html`)

A standalone HTML page that allows manual testing of the entire analytics pipeline without visiting the real site. Accessible at `meditationsteps.lv.test/api/test-analytics.html` locally.

**Features:**
- Buttons to fire each event type manually (pageview, click variants, section visibility, accordion, conversion, duration, first_click)
- Shows the JSON payload being sent for each event
- Shows the HTTP response from track.php
- A "View Dashboard" link that opens dashboard.php
- A "Reset DB" button that deletes the test SQLite database (dev only)

**Debug mode on track.php:**
- When called with query param `?debug=1`, track.php returns the inserted row as JSON instead of an empty 200
- Debug mode only works when the `ANALYTICS_DEBUG` environment variable is set to `1` (disabled in production)

### Testing Flow
1. Open `meditationsteps.lv.test/api/test-analytics.html`
2. Click each event button — verify payloads and 200 responses
3. Open `meditationsteps.lv.test/api/dashboard.php` — verify data appears in charts
4. Open `meditationsteps.lv.test/index.html` — browse the real page, verify events fire automatically in browser DevTools Network tab
5. Refresh dashboard — verify real browsing data appears

## Security Considerations

- **No raw IPs stored** — SHA256 hash with daily-rotating salt (non-reversible)
- **SQLite DB outside web root** — not accessible via HTTP
- **Dashboard password-protected** — simple but effective for single-user access
- **Rate limiting** — prevents abuse of the tracking endpoint
- **Input validation** — whitelist event types, sanitize all strings, enforce max lengths
- **CORS** — track.php only accepts requests from the site's own domain
- **Nginx rule** — deny access to any `.db` files as a safety net:
  ```nginx
  location ~* \.db$ { deny all; }
  ```

## What This System Does NOT Do

- No cookies, sessions, or user identification
- No real-time live updates (refresh the dashboard to see new data)
- No email/Telegram reports
- No JavaScript error tracking
- No heatmaps or session recordings
- No A/B testing

---

## Deployment Notes (for AI agent)

### Prerequisites
- Server: Ubuntu on Hetzner with Nginx and PHP-FPM installed
- PHP extensions needed: `sqlite3`, `json` (both typically installed by default)
- Web root for the site is the `site/` directory (or wherever Nginx points)

### Deployment Steps

1. **Create the data directory:**
   ```bash
   sudo mkdir -p /var/data/meditationsteps
   sudo chown www-data:www-data /var/data/meditationsteps
   sudo chmod 750 /var/data/meditationsteps
   ```

2. **Upload files:**
   - `site/api/track.php` and `site/api/dashboard.php` go into the `site/api/` directory on the server
   - Updated `site/index.html` replaces the existing file

3. **Set environment variables:**
   Add to the PHP-FPM pool config (e.g., `/etc/php/8.x/fpm/pool.d/www.conf`) or to the Nginx `fastcgi_param` directives:
   ```
   env[ANALYTICS_PASSWORD]=your-strong-password-here
   env[ANALYTICS_SECRET]=random-secret-key-for-ip-hashing
   ```
   Alternatively, set in Nginx:
   ```nginx
   fastcgi_param ANALYTICS_PASSWORD "your-strong-password-here";
   fastcgi_param ANALYTICS_SECRET "random-secret-key-for-ip-hashing";
   ```
   Generate the secret with: `openssl rand -hex 32`

   For local development (Laravel Herd), set env vars in the PHP-FPM config or use a `.env` file approach.
   Set `ANALYTICS_DEBUG=1` locally for debug mode on track.php. Do NOT set this in production.

   These should NOT be committed to git.

4. **Nginx configuration:**
   Add to the site's server block:
   ```nginx
   # Ensure PHP files in /api/ are processed by PHP-FPM
   location ~ ^/api/.*\.php$ {
       fastcgi_pass unix:/var/run/php/php-fpm.sock;  # adjust socket path if needed
       fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
       include fastcgi_params;
   }

   # Block direct access to any database files (safety net)
   location ~* \.db$ {
       deny all;
   }
   ```
   Then reload Nginx: `sudo nginx -t && sudo systemctl reload nginx`

5. **Verify PHP SQLite3 extension:**
   ```bash
   php -m | grep sqlite3
   ```
   If not present: `sudo apt install php-sqlite3 && sudo systemctl restart php8.*-fpm`
   Note: On Ubuntu the service is typically named `php8.x-fpm` (e.g., `php8.3-fpm`). Check with `systemctl list-units | grep php`.

6. **Test the tracking endpoint:**
   ```bash
   curl -X POST https://meditationsteps.lv/api/track.php \
     -H "Content-Type: application/json" \
     -d '{"event":"pageview","referrer":"test","lang":"en","screen_width":1440}'
   ```
   Should return 200. Check that `/var/data/meditationsteps/analytics.db` was created.

7. **Test the dashboard:**
   Visit `https://meditationsteps.lv/api/dashboard.php` in browser. Should show password prompt. After entering the password, the dashboard should load (empty at first).

8. **File permissions check:**
   ```bash
   ls -la /var/data/meditationsteps/
   # analytics.db should be owned by www-data
   ```

9. **Remove test harness in production:**
   Delete `site/api/test-analytics.html` from the production server — it should only exist locally for development testing. Alternatively, block access via Nginx:
   ```nginx
   location = /api/test-analytics.html { deny all; }
   ```

### Troubleshooting
- **500 error on track.php:** Check PHP error log (`/var/log/php-fpm/error.log` or `/var/log/nginx/error.log`). Most likely cause: directory permissions on `/var/data/meditationsteps/` or missing sqlite3 extension.
- **CORS errors in browser console:** Verify the domain in track.php's CORS header matches the actual site domain (including www vs non-www, http vs https).
- **Dashboard shows no data:** Check that the DB path in dashboard.php matches track.php. Verify the DB file exists and has data: `sqlite3 /var/data/meditationsteps/analytics.db "SELECT COUNT(*) FROM events;"`
