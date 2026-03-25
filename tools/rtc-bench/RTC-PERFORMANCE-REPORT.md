# RTC Sync Endpoint Performance Report

**Date:** March 2026
**Endpoint:** `POST /wp-sync/v1/updates`
**Environments tested:** Local (wp-env), WordPress.com Atomic staging site, Self-hosted VPS (Plesk)

---

## Executive Summary

The RTC sync endpoint response time varies dramatically across environments: **~30ms local**, **~510ms on a self-hosted VPS**, and **~420ms on WordPress.com**. Server-side profiling reveals the actual sync logic takes **< 1ms** in all cases. The overhead comes from the WordPress request lifecycle — primarily REST API route registration from plugins like Jetpack, WooCommerce, and Yoast. On resource-constrained servers (1 vCPU, 1GB RAM), the PHP bootstrap itself is also a significant factor.

---

## Architecture Overview

```
Client (Yjs doc) ──POST /wp-sync/v1/updates──▶ PHP Server ──▶ Post Meta Storage
     ◀──── updates from other clients ────────┘
```

- **Storage:** Updates stored as individual `wp_postmeta` rows on a `wp_sync_storage` custom post type
- **Sync protocol:** Yjs CRDT updates, base64-encoded over JSON
- **Polling intervals:** 4s solo, 1s with collaborators, 25s background tab
- **Compaction:** Triggered at 50+ stored updates, performed by lowest client_id

---

## Benchmark Results

### Local (wp-env, Gutenberg only)

| Scenario | Mean | Median | P95 | StdDev |
|---|---|---|---|---|
| empty-poll | 32.3ms | 31.4ms | 37.0ms | 3.7ms |
| with-update (64B) | 32.0ms | 31.5ms | 36.9ms | 1.9ms |
| large-update (10KB) | 33.8ms | 33.4ms | 38.0ms | 2.1ms |
| multi-room (3 rooms) | 37.7ms | 37.1ms | 41.9ms | 3.0ms |
| accumulated (40 updates) | 36.4ms | 36.1ms | 38.9ms | 1.5ms |
| compaction | 33.7ms | 32.4ms | 38.6ms | 5.9ms |

### Local with Jetpack active

| Scenario | Without fast-sync | With fast-sync | Improvement |
|---|---|---|---|
| empty-poll | 37.1ms | 32.5ms | -12.4% |
| multi-room | 36.3ms | 32.2ms | -11.3% |

### Self-hosted VPS (Plesk, 6 popular plugins)

**Server specs:**

| Spec | Value |
|---|---|
| CPU | AMD EPYC-Milan, 1 vCPU @ 2.0 GHz |
| RAM | 945 MB (+ 1.9 GB swap) |
| Disk | 23 GB SSD |
| OS | Ubuntu 20.04.6 LTS |
| Web server | Nginx 1.28.2 → Apache 2.4.41 (reverse proxy) |
| PHP | 8.3.30 (PHP-FPM) |
| Database | MariaDB 10.3.39 (localhost) |
| OPcache | Enabled, `validate_timestamps=On`, 128MB, 10K files |
| Plesk | Obsidian 18.0.76.3 |

**Active plugins:** Gutenberg 22.7.1, Jetpack 15.6, WooCommerce 10.6.1, Contact Form 7 6.1.5, Yoast SEO 27.2, Elementor 3.35.7

**WordPress environment:**

| Component | Value |
|---|---|
| `rest_api_init` callbacks | 116 |
| REST routes registered | 974 |
| Hook entries | 2,540 |
| Autoloaded options | 283 |

**Benchmark results** (100 iterations, network latency ~15ms from client):

| Scenario | Mean | Median | P95 | StdDev |
|---|---|---|---|---|
| empty-poll | 554ms | 522ms | 1040ms | 174ms |
| with-update (64B) | 513ms | 511ms | 641ms | 83ms |
| large-update (10KB) | 533ms | 523ms | 644ms | 80ms |
| multi-room (3 rooms) | 518ms | 511ms | 603ms | 74ms |
| accumulated (40 updates) | 777ms | 523ms | 2106ms | 637ms |
| compaction | 530ms | 520ms | 629ms | 36ms |

**HTTP timing breakdown** (curl, 5 samples):

| Component | Typical |
|---|---|
| TCP connect | ~15ms |
| TLS handshake | ~31ms |
| Server processing (TTFB - TLS) | ~470ms |
| **Total** | **~510ms** |

**Server-side sync logic** (measured via WP-CLI, 10 runs):

| Operation | Median |
|---|---|
| Storage init | 0.00ms |
| Permission check | 0.02ms |
| Awareness read | 0.00ms |
| Awareness write | 0.02ms |
| Updates read | 0.07ms |
| Cursor + count | 0.00ms |
| **Total sync logic** | **0.11ms** |

**Notable observations:**
- The `accumulated` scenario shows high P95/stddev (2106ms/637ms) — the 1-vCPU server struggles when the benchmark's setup phase (40 sequential write requests) competes with the measurement phase.
- Server processing time (~470ms) minus sync logic (~0.11ms) = **~470ms of WordPress overhead** to bootstrap PHP, load 6 plugins, and register 974 REST routes.
- Despite having localhost DB (no network latency), the VPS is **slower than WP.com** due to limited CPU (1 vCPU @ 2 GHz vs WP.com's optimized infrastructure).

### WordPress.com Atomic (staging)

| Scenario | Median | P95 | StdDev |
|---|---|---|---|
| empty-poll | 420ms | 794ms | 110ms |
| with-update | 422ms | 692ms | 104ms |
| large-update | 460ms | 652ms | 105ms |
| multi-room | 434ms | 545ms | 62ms |
| accumulated | 416ms | 515ms | 34ms |
| compaction | 453ms | 524ms | 48ms |

---

## Root Cause Analysis

### Server-side time breakdown (WP.com)

```
Server-timing header: cache;desc=BYPASS;dur=390ms
```

**Sync logic profiling** (measured via WP-CLI on production, 10 runs):

| Operation | Median |
|---|---|
| Storage init | 0.00ms |
| Permission check | 0.04ms |
| Awareness read | 0.01ms |
| Awareness write | 0.03ms |
| Updates read | 0.35ms |
| Cursor + count | 0.00ms |
| **Total sync logic** | **0.44ms** |

**Conclusion:** 99.9% of the 390ms server-side time is WordPress + platform overhead, not sync code.

### What consumes the overhead

| Component | Local (wp-env) | VPS (Plesk) | WP.com |
|---|---|---|---|
| Active plugins | 1 (Gutenberg) | 6 (popular stack) | 9 + wpcomsh |
| Jetpack modules | 0 | ~20 | 37 |
| `rest_api_init` callbacks | 20 | **116** | **140** |
| REST routes registered | 144 | **974** | **544** |
| Hook entries | 693 | **2,540** | **2,013** |
| Autoloaded options | 124 | 283 | 246 |
| Sync logic time | ~0.4ms | ~0.1ms | ~0.4ms |
| **Total response (median)** | **31ms** | **511ms** | **420ms** |

**`rest_get_server()` initialization** (fires all 140 `rest_api_init` callbacks, registers 544 routes):
- CLI: 68ms
- Web (estimated): **150-200ms**

**REST callback breakdown:**

| Source | Callbacks | Routes |
|---|---|---|
| Jetpack | 59 | 208 |
| WP.com platform | 50 | 87 |
| Core + Gutenberg + others | 31 | 249 |

### Estimated server-side breakdown (web request)

```
Total server-side:                    ~390ms
├── PHP bootstrap (wp-settings.php):   ~50-80ms
│   ├── DB connect + config              ~10ms
│   ├── MU-plugins (wpcomsh)             ~10ms
│   ├── Plugins (Jetpack 37 modules)     ~40-60ms
│   └── Theme + init hooks               ~10ms
├── REST API initialization:          ~150-200ms  ← BOTTLENECK
│   ├── 59 Jetpack REST callbacks          ~80ms
│   ├── 50 WP.com REST callbacks           ~60ms
│   └── Route matching (544 routes)        ~30ms
├── REST validation + auth:            ~50-80ms
│   ├── Cookie + nonce verification        ~20ms
│   └── Schema validation                 ~30ms
├── Sync handler (actual work):          ~0.5ms
└── Response serialization:              ~10ms
```

---

## Optimization Approaches Explored

### 1. Alternative storage backends

Three `WP_Sync_Storage` implementations were built and tested, switchable via the `wp_sync_storage_backend` filter:

| Backend | Description | Impact |
|---|---|---|
| `post-meta` (default) | Existing `wp_postmeta` storage | Baseline |
| `custom-table` | Dedicated `wp_sync_updates` + `wp_sync_awareness` tables with composite indexes | Negligible on small DB; beneficial on large `postmeta` tables |
| `object-cache` | Redis/Memcached via `wp_cache_*` | Requires persistent object cache; eliminates DB queries entirely |

**Result:** On WP.com with a small `postmeta` table, storage backend has no measurable impact (~0.35ms vs ~0.35ms). Storage optimization matters on self-hosted sites with millions of postmeta rows.

### 2. Lightweight endpoint (bypassing REST API)

A `WP_Sync_Lightweight_Endpoint` was built that hooks into `init` and processes sync requests before the REST API stack initializes. Switchable via the `wp_sync_endpoint_type` filter.

**Local results:**

| Scenario | REST API | Lightweight | Improvement |
|---|---|---|---|
| empty-poll | 30.0ms | 27.5ms | -8.3% |
| multi-room | 34.0ms | 27.2ms | -20% |

**WP.com result:** Not deployable. WordPress.com's edge infrastructure serves 404s for unknown paths before PHP runs, and `parse_request`/`init` hooks can't intercept REST-style URLs. OPcache with `validate_timestamps=0` also prevents deploying new PHP files without worker restarts.

### 3. Lazy REST route registration (fast-sync mu-plugin)

A mu-plugin that detects sync requests at `wp_loaded` and removes all non-essential `rest_api_init` callbacks before the REST server initializes. Reduces callbacks from ~116 → 4.

**Local results (with Jetpack):** 12% improvement (37ms → 32ms)

**VPS results (6 popular plugins, 100 iterations):**

| Scenario | Without fast-sync | With fast-sync | Improvement |
|---|---|---|---|
| empty-poll | 522ms | 406ms | **-116ms (-22%)** |
| with-update | 511ms | 401ms | **-110ms (-21%)** |
| large-update | 523ms | 428ms | **-95ms (-18%)** |
| multi-room | 511ms | 449ms | **-62ms (-12%)** |
| accumulated | 523ms | 451ms | **-72ms (-14%)** |
| compaction | 520ms | 415ms | **-105ms (-20%)** |

The fast-sync mu-plugin delivers a consistent **~100-115ms reduction** on baseline scenarios by skipping ~112 unnecessary `rest_api_init` callbacks that register ~970 routes not needed for sync. This validates the root cause analysis: REST route registration is the dominant overhead.

**WP.com result:** Could not be deployed due to OPcache `validate_timestamps=0`. New mu-plugin files are not picked up by PHP-FPM workers until they restart. PHP version toggling via the WP.com dashboard did not trigger a restart. The same ~20% improvement is expected if deployed.

---

## Key Findings

1. **The sync code itself is not the bottleneck.** At 0.1-0.4ms across all environments, it's negligible. All optimization effort should target the request lifecycle overhead.

2. **REST route registration is the single largest cost.** Every sync poll triggers 116-140 callbacks to register 544-974 routes. Only 1 route is needed. This is the equivalent of loading a phone book for every phone call.

3. **The overhead scales with installed plugins, not with sync complexity.** The VPS with 6 popular plugins (974 routes) is actually slower than WP.com (544 routes) despite having localhost DB — because WP.com has faster CPUs and more optimized infrastructure. More plugins = more REST routes = slower sync polling.

4. **A typical self-hosted VPS (1 vCPU, 1GB RAM) with popular plugins is the worst case.** At ~510ms median, it's slower than WP.com (~420ms). Resource-constrained servers amplify the WordPress bootstrap overhead that fast CPUs can partially mask.

5. **WordPress.com's OPcache configuration (`validate_timestamps=0`) makes deploying file-based optimizations extremely difficult.** Any new PHP file or modification requires a full worker restart, which is not user-accessible. Self-hosted servers with `validate_timestamps=On` don't have this issue.

6. **The awareness state is read twice per request** — once in `check_permissions()` and again in `handle_request()` → `process_awareness_update()`. This is a minor inefficiency (~0.04ms) but an easy fix.

---

## Recommendations

### Short-term (plugin-level)

- **Lazy REST route registration:** WordPress Core could support registering REST routes only for the requested namespace. This would benefit all REST endpoints, not just sync.
- **Fix double awareness read:** Cache the awareness state from `check_permissions` and reuse it in `handle_request`.
- **Reduce polling rooms:** The recent PR #76704 (polling primary room only at high frequency) is the right direction.

### Medium-term (platform-level, WP.com)

- **Namespace-scoped REST init:** Only fire `rest_api_init` callbacks that match the requested route namespace (`wp-sync/v1`). This could save ~150ms per sync request.
- **Dedicated sync worker pool:** PHP-FPM pool with a stripped WordPress bootstrap that only loads Gutenberg's sync code.
- **Server-Sent Events (SSE):** Replace polling with a persistent HTTP connection. Amortizes the bootstrap cost over the entire editing session.

### Long-term (architecture)

- **WebSocket relay:** Eliminates per-request PHP bootstrap entirely. The server maintains persistent connections and relays Yjs updates between clients.
- **Edge-side sync:** A lightweight sync relay at the CDN edge that batches updates and forwards to PHP only for persistence.

---

## Benchmark Tooling

All benchmarking tools are at `tools/rtc-bench/`:

```bash
# Basic benchmark
node tools/rtc-bench/bench.mjs --user "admin:password" --iterations 50

# Against a remote site with cookie auth
node tools/rtc-bench/bench.mjs \
  --url "https://example.com" \
  --cookie "wordpress_logged_in_xxx=..." \
  --nonce "abc123" \
  --post-id 3

# Specific scenarios
node tools/rtc-bench/bench.mjs --scenarios empty-poll,multi-room

# JSON output for scripting
node tools/rtc-bench/bench.mjs --json

# Switch backends locally (requires wp-env)
bash tools/rtc-bench/switch-backend.sh custom-table lightweight
```

### Available scenarios

| Scenario | Description |
|---|---|
| `empty-poll` | Poll with no updates (baseline) |
| `with-update` | Send a 64-byte update each request |
| `large-update` | Send a 10KB update each request |
| `multi-room` | 3 rooms in a single request |
| `accumulated` | Poll with 40 stored updates to retrieve |
| `compaction` | Trigger compaction (55+ stored updates) |

### Server-side profiling

Drop `tools/rtc-bench/rtc-profiler.php` into `wp-content/mu-plugins/` to inject timing data into sync responses. The profiler measures time at each WordPress lifecycle stage (`plugins_loaded`, `init`, `rest_api_init`, etc.) and adds a `_profiler` key to the JSON response.

### Fast-sync mu-plugin

Drop `tools/rtc-bench/rtc-fast-sync.php` into `wp-content/mu-plugins/` to skip unnecessary REST route registration for sync requests. Reduces `rest_api_init` callbacks from ~140 to 4 on sites with Jetpack.
