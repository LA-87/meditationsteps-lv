# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Static single-page website for **Meditation Steps Latvia** — a yoga and meditation retreat event page. The site promotes an Ananda Marga retreat held in Lithuania (Dragonfly Land, near Vilnius), organized by Meditation Steps Latvia.

## Tech Stack

- **Single HTML file**: `site/index.html` (~890 lines) containing all markup, CSS (inline `<style>`), and JavaScript
- **Alpine.js** (CDN) — used for schedule accordions, mobile menu toggle, language switching, and interactive components
- **Google Fonts**: Cormorant Garamond (display/headings), DM Sans (body text)
- **No build system** — edit HTML directly, open in browser to preview
- **Served via Laravel Herd** at `meditationsteps.lv.test` locally

## Architecture

Everything lives in `site/index.html`:
- **Lines 1-297**: `<style>` block with CSS custom properties, component styles, animations, and responsive breakpoints
- **Lines 298+**: HTML body with Alpine.js-powered components
- CSS uses custom properties defined in `:root` (color palette: cream, forest, sage, terracotta, gold)
- Reveal animations use IntersectionObserver with `.reveal-up`, `.reveal-left`, `.reveal-right`, `.reveal-scale` classes
- Schedule section uses Alpine.js `x-data`/`x-show` for accordion behavior
- Language toggle (EN/RU) uses Alpine.js state management inline

## Key Design Tokens

| Token | Value | Usage |
|-------|-------|-------|
| `--c-cream` | #F5F0E8 | Page background |
| `--c-forest` | #1E3828 | Primary dark (nav, headings, featured cards) |
| `--c-terracotta` | #A8503A | CTA buttons, accents |
| `--c-sage` | #7A9E87 | Secondary accent |
| `--c-gold` | #C4960A | Best-value badge |

## Images

All images in `site/images/`. Key files:
- `dada-hero.jpeg`, `didi-hero.jpg` — teacher hero images
- `dada-portrait.png`, `didi-portrait.png`, `didi-photo.jpg` — teacher portraits
- `activity-1.jpg` through `activity-7.jpg` — activity cards
- `icon-lotus1.png` through `icon-lotus3.png` — decorative lotus icons
- `logo.png` — site logo

Note: Some filenames are misleading (`didi-portrait.png` is actually Dada Sadananda).

## Reference Documents

- `site/TERMINOLOGY.md` — glossary of Ananda Marga terms used on the site (sadhana, kiirtana, kaoshiki, etc.)
- `site/IMPROVEMENT-IDEAS.md` — prioritized improvement backlog compiled from spiritual, UX, and conversion perspectives
- Root-level `.jpeg`/`.png` files are design reference screenshots, not site assets

## Content Context

- The retreat has two parts: Part 1 (general, open to newcomers) and Part 2 / "Marg Retreat" (for experienced practitioners)
- Domain is `.lv` (Latvia) but the event venue is in Lithuania — this is intentional
- Audience is multilingual (English, Russian); site has EN/RU language toggle
- Registration links to an external Google Form

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
