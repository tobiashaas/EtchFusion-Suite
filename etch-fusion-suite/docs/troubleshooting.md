# Troubleshooting — EtchFusion Suite Dev Environment

Quick reference for the most common issues when working with the dual-instance
`@wordpress/env` setup (Bricks on port 8888, Etch on port 8889).

Always run `npm run health` first — it covers WordPress core, permalink
structure, memory limits, plugin/theme activation, Composer dependencies, and
REST API reachability in one go.

---

## Table of Contents

1. [REST API returns 404 (Apache "Not Found")](#1-rest-api-returns-404)
2. [CORS error on cross-site requests](#2-cors-error)
3. [Plugins inactive after Docker restart](#3-plugins-inactive-after-restart)
4. [PHP memory exhausted](#4-php-memory-exhausted)
5. [Migration stuck at 0 % / background request failed](#5-migration-stuck-at-0)
6. [Connection URL rejected / pairing code invalid](#6-pairing-code-invalid)
7. [Health check false-positive on permalink](#7-health-check-permalink-artefact)
8. [General diagnostic commands](#8-general-diagnostic-commands)

---

## 1. REST API returns 404

**Symptom:** Browser or `curl` request to `http://localhost:8889/wp-json/...`
returns Apache's own "Not Found" page (not a JSON error from WordPress).

**Root cause:** WordPress is not using pretty permalinks, so Apache does not
route `/wp-json/` to `index.php`.  This happens on fresh containers or after
Docker auto-restarts that wipe the WP-Cron / rewrite cache.

**Diagnosis:**

```bash
npm run health
# Look for: FAIL Etch permalink structure
```

Or directly:

```bash
npm run wp:tests -- eval "echo get_option('permalink_structure');"
# Expected output: /%postname%/
# Bad output:      (empty)  or  C:/Program Files/Git/%postname%/
```

**Fix:**

```bash
npm run activate
# ensurePermalinks() runs automatically and uses PHP eval to set
# /%postname%/ safely, then flushes .htaccess rewrite rules.
```

Or manually:

```bash
# DO NOT use: npm run wp:tests -- option update permalink_structure /%postname%/
# Git bash on Windows expands / to C:/Program Files/Git/ — use eval instead:
npm run wp:tests -- eval "update_option('permalink_structure', '/%postname%/'); echo get_option('permalink_structure');"
npm run wp:tests -- rewrite flush --hard
```

**Verify:**

```bash
curl -s -o /dev/null -w "%{http_code} %{content_type}" http://localhost:8889/wp-json/efs/v1/status
# Expected: 200 application/json; charset=UTF-8
```

---

## 2. CORS error

**Symptom:** Browser console shows:

```
Access to fetch at 'http://localhost:8889/wp-json/efs/v1/...' from origin
'http://localhost:8888' has been blocked by CORS policy:
No 'Access-Control-Allow-Origin' header is present on the requested resource.
```

**Root causes (in order of likelihood):**

| # | Cause | Indicator |
|---|-------|-----------|
| 1 | REST API returns 404 (permalink issue) — Apache's 404 has no CORS headers | `net::ERR_FAILED 404` in the same console line |
| 2 | CORS manager not initialised — `$cors_manager` null, headers skipped | 4xx/5xx with JSON body but no CORS headers |
| 3 | Origin not in CORS whitelist | CORS error with a 403 JSON body |

**Fix for cause 1:** See [section 1](#1-rest-api-returns-404).

**Fix for cause 2 / 3:** Both are handled by `EFS_API_Endpoints::add_cors_headers_to_request`
(hooked to `rest_pre_serve_request`).  If headers are still missing after
the permalink fix, check that the plugin is active on the Etch site:

```bash
npm run wp:tests -- plugin is-active etch-fusion-suite
```

Default allowed origins (no config needed for local dev):

- `http://localhost:8888`
- `http://localhost:8889`
- `http://127.0.0.1:8888`
- `http://127.0.0.1:8889`

To add a custom origin, update the `cors_allowed_origins` option:

```bash
npm run wp:tests -- eval "update_option('cors_allowed_origins', ['https://mysite.com']);"
```

---

## 3. Plugins inactive after Docker restart

**Symptom:** `npm run dev` completes but Bricks / Etch / migration plugin show
as inactive; dashboard is broken.

**Root cause:** Docker can auto-restart containers while `wp-env start` is still
running, causing the port-in-use check in `dev.js` to skip the start step — but
`activate-plugins.js` may also be skipped or race with the restart.

**Fix:**

```bash
npm run activate         # re-run activation without restarting Docker
# or for a full reset:
npm run dev -- --restart  # forced stop + start + activate
```

**Verify:**

```bash
npm run health
# All plugin checks should show OK
```

---

## 4. PHP memory exhausted

**Symptom:** WP-CLI commands fail with "Allowed memory size exhausted" or
migrations abort mid-way with an out-of-memory PHP fatal.

**Diagnosis:**

```bash
npm run health
# Look for: WARN/FAIL memory limit
```

**Fix:** `activate-plugins.js` automatically raises memory limits when a memory
error is detected during activation.  To raise them manually:

```bash
npm run wp:tests -- config set WP_MEMORY_LIMIT 512M --type=constant
npm run wp:tests -- config set WP_MAX_MEMORY_LIMIT 512M --type=constant
```

For persistent settings, add to `.wp-env.json`:

```json
{
  "env": {
    "tests": {
      "phpConfig": {
        "memory_limit": "512M"
      }
    }
  }
}
```

See `docs/wp-env-etch-memory.md` for the full memory configuration reference.

---

## 5. Migration stuck at 0 %

**Symptom:** Migration starts but progress never moves beyond 0 %; eventually
shows "background request could not reach the server" or the run goes stale.

**Root cause:** The async loopback POST from the source WordPress to
`wp-admin/admin-ajax.php?action=efs_run_migration_background` failed.
Common reasons:

- Plugin not active on the Etch (target) site → REST API validation fails early
- Permalink issue on Bricks site → loopback POST can't reach admin-ajax.php
- Docker networking: container cannot reach `http://localhost:...` from within
  itself; `EFS_Background_Spawn_Handler` uses `docker-url-helper.php` to
  translate localhost URLs to container-internal addresses

**Diagnosis:**

```bash
npm run health                     # check both sites
npm run wp -- eval "echo admin_url();"        # Bricks admin URL
npm run wp:tests -- eval "echo home_url();"   # Etch home URL
```

**Fix:**

```bash
npm run activate                   # ensure both sites are fully set up
npm run dev -- --restart           # if containers need a hard reset
```

---

## 6. Pairing code invalid

**Symptom:** Wizard "Connect" step shows "Target site rejected the connection"
or "pairing code invalid / expired".

**Causes:**

- Pairing code is single-use and has a short TTL (set via `EFS_Migration_Token_Manager`)
- REST endpoint `efs/v1/generate-key` returned 404 (permalink issue on Etch)
- The source URL in the request did not match the expected origin

**Fix:**

1. On the Etch admin dashboard, generate a fresh connection URL.
2. Paste it immediately into the Bricks wizard (codes expire quickly in dev).
3. If the endpoint itself returns 404, fix the permalink issue first (section 1).

---

## 7. Health check permalink artefact

**Symptom:** `npm run health` shows:

```
FAIL Etch permalink structure: Permalink structure contains a Windows path
artefact: "C:/Program Files/Git/%postname%/".
```

**Cause:** At some point `wp option update permalink_structure /%postname%/`
was run in Git bash on Windows.  Git bash interprets the leading `/` as a
filesystem root and expands it to `C:/Program Files/Git/%postname%/`.

**Fix:**

```bash
npm run activate
# ensurePermalinks() detects the artefact and overwrites it via PHP eval.
```

This is also why `activate-plugins.js` / `ensurePermalinks()` always uses
`wp eval` + `update_option()` rather than `wp option update` for this value.

---

## 8. General diagnostic commands

```bash
# Full health check (both environments)
npm run health

# Health check for one environment only
npm run health -- --environment tests

# Check REST API reachability directly
curl -s http://localhost:8889/wp-json/ | head -c 200
curl -s http://localhost:8889/wp-json/efs/v1/status

# List active plugins
npm run wp -- plugin list --status=active
npm run wp:tests -- plugin list --status=active

# Read any WordPress option safely (avoids Windows path expansion)
npm run wp:tests -- eval "echo get_option('permalink_structure');"
npm run wp:tests -- eval "echo get_option('siteurl');"

# Flush rewrite rules
npm run wp:tests -- rewrite flush --hard

# Check PHP error log inside container
docker exec etch cat //var/log/apache2/error.log | tail -50

# Force full environment restart
npm run dev -- --restart
```
