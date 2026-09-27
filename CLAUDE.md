# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

WordPress plugin updater library (`shazzad/plugin-updater`) that enables automatic updates, license verification, and remote plugin management for custom WordPress plugins. It hooks into WordPress core's update system to check a remote API for updates.

## Commands

```bash
# Run tests
composer test

# Lint (WordPress coding standards + PHP compatibility)
composer lint

# Lint with WordPress coding standards only
composer phpcs

# Auto-fix coding standard violations
composer fix
```

Tests use PHPUnit 9 with Brain Monkey for WordPress function mocking. Fixture-based JSON files in `tests/fixtures/` represent API response shapes and are shared by the suites: legacy tests in `tests/`, V2 tests in `tests/V2/`, V4 tests in `tests/V4/` (`TestCase.php` = Insights base with the full collector environment stubbed; `IntegrationTestCase.php` = base for the tests carried over from V2). CI (`.github/workflows/tests.yml`) runs `composer test` on PHP 7.4, so every suite must stay green. Locally: `docker run --rm -v "$PWD":/app -w /app php:7.4-cli vendor/bin/phpunit`.

## Architecture

Since 2.0.0 (2026-08-19) the library is **versioned by namespace**. All trees ship in the same
package and coexist on one site without interacting, which is what ends the first-loader-wins
fatal when two plugins bundle different library versions:

- **`src/` — `Shazzad\PluginUpdater` (V1) — FROZEN.** Critical/security fixes only. Still what
  most consumer plugins load.
- **`src/V2/` — `Shazzad\PluginUpdater\V2` — stable, additive fixes only.** No new features.
- **`src/V4/` — `Shazzad\PluginUpdater\V4` — active (4.0.0).** All new development. Within V4
  changes are additive-only; any breaking change opens `src/V5/`.

**Never edit V1 or V2 while working on V4** — `git diff main -- src/*.php src/V2` must stay
empty on a V4 branch.

**The namespace number matches the server API version it calls:** `V4` ↔ `wp-repo/v4` (and a
future `V5` ↔ `wp-repo/v5`). The legacy exception: V1 and `V2` both call `wp-repo/v3`, which
is frozen on the server. There is no `V3` namespace and no 3.0.0 — it was renamed to `V4` before
its first release.

Adoption is opt-in per plugin: bump the Composer constraint and instantiate the newer
namespace. Storage compatibility is sacred — V2 and V4 `Integration` use the same option keys,
transients, and cron hook names as V1, so a plugin moving V1→V2→V4 keeps every customer's saved
license. The pending legacy-key prune (issue #24) is a post-V1 decision; never ship it in the
frozen namespace. Designs: `docs/superpowers/specs/2026-08-19-versioned-namespace-v2-design.md`,
`docs/superpowers/specs/2026-09-26-v4-insights-design.md`.

### V4 (`src/V4/`)

Two entry points over one set of Insights parts:

- **`V4\Insights` — free wordpress.org plugins.** Wires `Insights/{Consent,Collector,Client,
  Scheduler,Notice}` and nothing else. Nothing is sent before an admin clicks Allow (or the
  plugin calls `opt_in()`). It must **never** reference `Updater`, `License*`, `Admin\*`,
  `Integration`, `Tracker` or V1/V2 — `tests/V4/InsightsIsolationTest.php` enforces it, because
  free plugins ship only `src/V4/Insights.php` + `src/V4/Insights/` (strip recipe in `docs/v4.md`).
- **`V4\Integration` — commercial plugins.** A copy of the V2 entry point (same config, same
  `$store`/`$client`/`$updater`/`$tracker`/`$admin`/`$notices`/`$update_message`) plus the
  Insights parts built in **commercial mode** (consent implied, never asked, `Notice` never
  loaded) as `$insights_consent`, `$insights_collector`, `$insights_client`,
  `$insights_scheduler`. No extra config keys: `api_url` is the plugin-repo `wp-repo/v4` base
  and serves updates, licensing and Insights alike, every call at `{api_url}/plugins/{uid}/…`.
  **`product_uid` is required** in both entry points (v4 answers a numeric id with
  `404 rest_no_route`): without it `_doing_it_wrong()` fires, `get_api_product_key()` /
  `Insights::get_product_key()` return `''` (never the id), `Client` / `Insights\Client` refuse
  to send (`wprepo_no_product_uid` / `wprepo_insights_no_product_uid`), `Integration` builds no
  Insights parts (a later `setProductUid()` does) and `Insights` draws no notice. `product_id`
  is optional, never sent, and only reaches V1 id-keyed license storage. Config keys and the
  `product_*` properties keep their names; only the wire changed (`plugins/` path, track keys
  `plugin_version` / `plugin_status`). A malformed uid (not `^prod_[a-z0-9]+\z`, the server's
  route pattern) draws its own notice and counts as missing. An `api_url` on `wp-repo/v3` (no
  `plugins/{uid}` routes) → `_doing_it_wrong()` and **nothing works**: the update `Client`
  refuses every call (`wprepo_v3_api_url`, never an `invalid_license` verdict), the four
  Insights properties stay `null`, and the free `Insights` draws no notice and sends nothing
  (`wprepo_insights_v3_api_url`). The single gates are `Integration::get_api_error()` and
  `Insights\Client::get_config_error()`; `Insights::opt_in()` returns the latter's error without
  storing consent, token or cron. `api_url` is right-trimmed of `/` once in the constructor.
  `Client::ping()` is gone: the hourly
  `wprepo_sync_license_data_{license_name}` sync checks the license and backs up the daily track, `Tracker`
  activate/deactivate and `Updater` post-upgrade only refresh caches, and the Insights
  `Scheduler` sends the `activate`/`deactivate`/`upgrade`/daily tracks (license key included
  via `get_insights_license()` when licensing is on; `""` only after an explicit removal —
  `Store::delete_license_code()` of a stored code sets `{slug}_insights_license_removed`,
  `update_license_code()` clears it, and so does the first 2xx track that carried `""` (via
  the collector's `license_sent_callback`). The server then unbinds the install and frees the
  seat on its next recount. No code and no flag → no `license` key, never `""`: an unreadable
  key (missed legacy migration) must not unbind a paying install).
  The update `Client` `rawurlencode()`s its query args (`add_query_arg()` does not). `meta`/`meta_callback`/`setMeta()`/
  `setMetaCallback()` feed the collector. `tests/V4/CommercialIsolationTest.php` keeps the
  commercial files off `Insights\Notice`, the free entry point, `ping` and V1/V2.

Insights storage per plugin (`{slug}` = plugin directory): options `{slug}_insights_consent`,
`{slug}_insights_token`, `{slug}_insights_last_send`, `{slug}_insights_optout_pending` (failed
opt-out, retried up to 7 days), `{slug}_insights_last_attempt` (last 4xx-refused track: the next
daily track waits `MIN_INTERVAL`, so the hourly sync never re-POSTs a refused body) and
`{slug}_insights_disabled_version` (`[version, since]` after `403
wprepo_insights_tracking_disabled`: no track at all, `deactivate` included — the server's 403
check runs first — until the installed version differs or `Scheduler::PAUSE_MAX` (7 days)
passes; opt-out is not a track and still goes out; 5xx and network errors keep retrying); daily cron `wprepo_insights_track_{slug}`, re-created on
`admin_init` when consent is granted and, for commercial plugins, by the hourly license sync
(which also sends the daily track when due). `Insights::uninstall( $file )` removes all of it,
network-wide. The notice's "What we collect" list (`Notice::get_collected_items()`) must stay in
step with `Collector::collect()` in consent mode, which since 4.1.0 is a trimmed payload (no
`admin`/`users` blocks, debug mode, PHP execution/upload limits or plugin counts); commercial
mode sends everything. Plugin entries carry `url` (the `Plugin URI` header) in both modes. Server side: `wp-repo/v4`
`track` + `optout` in `shazzad/plugin-repo` 2.9.0+ (contract: that repo's `docs/v4-api.md`).

### V2 (`src/V2/`, stable)

**Entry point:** `V2\Integration` takes a single config array — `api_url` (required), `file`
(required; `__FILE__` or its `plugin_basename()` form), `product_uid` (preferred identity),
`product_id` (legacy identity; needed to reach id-keyed license options), `license` (bool,
default false), `menu` (array of `parent`/`label`/`priority`, or `false` to hide the page),
`meta`, `meta_callback`. Unknown keys, missing `api_url`/`file`, a non-callable
`meta_callback`, or `product_uid` without `product_id` fire `_doing_it_wrong()` — visible in
debug, never fatal. Fluent `setMeta()`, `setMetaCallback()`, `setProductUid()` remain.
Subsystems are public properties:

- **`$store`** (`License/Store.php`) — Option/transient keys, uid-keyed storage with legacy
  id-key migration, license data/status/renewal URL. V1 keeps these methods on `Integration`;
  V2 `Integration` keeps same-named thin wrappers that delegate to the store.
- **`$client`** (`Client.php`) — `ping()`, `check_license($license)`, `updates()`, `details()`;
  private `request()` holds shared HTTP logic. Unlike V1, `ping()` resolves any callable
  `meta_callback` (V1 only invoked Closures); plain strings in `meta` stay data.
- **`$updater`** (`Updater.php`) — Same WordPress update hooks as V1 (below).
- **`$tracker`** (`Tracker.php`) — Activation/deactivation hooks and hourly cron
  `sync_license_data()`.
- **`$admin`** (`Admin/LicensePage.php`) — License page; only when `license` is on and `menu`
  is not `false`.
- **`$notices`** (`Admin/Notices.php`) — Whenever `license` is on: dismissible "enter your
  license key" / "license expired — renew" admin notices for users with `update_plugins`,
  one-week nonce-protected snooze per product per notice type. Steps aside on the plugins
  list while an update row for the product already carries the message.
- **`$update_message`** (`Admin/UpdateMessage.php`) — Whenever `license` is on: an
  `in_plugin_update_message-{file}` line explaining why the update package is missing on
  unlicensed/expired sites.

### V1 (`src/`, frozen)

**Entry point:** `Integration` positional constructor
`( $api_url, $product_file, $product_id, $license_enabled = false, $display_menu = true, $menu_label = '', $menu_parent = '', $menu_priority = 9999 )`.
Holds all shared state plus the license/transient helpers itself; subsystem properties
`$client`, `$updater`, `$tracker`, and `$admin` (`Admin.php`, only when `license_enabled` and
`display_menu` are both true). No notices or update-row message.

### Shared by V1, V2 and commercial V4

- `Updater` hooks `pre_set_site_transient_update_plugins`, `plugins_api`,
  `upgrader_package_options`, `upgrader_process_complete`, and `load-update-core.php` (clears
  the API response cache).
- The license admin page requires `delete_users` (`Admin.php` and `V2/Admin/LicensePage.php`);
  it handles save/verify via POST with nonce verification.
- All classes receive the `Integration` instance and use its public properties directly (no
  getters/setters pattern). API calls go through `$integration->client->method()` — each
  returns an associative array on success or `WP_Error` on failure.

## Code Conventions

- Every file, in every version, keeps the WordPress `ABSPATH` guard and a `class_exists()` guard
  against its own namespace
- Namespace `Shazzad\PluginUpdater` with PSR-4 autoloading from `src/`; the `V2` sub-namespace
  (and `V4`) resolves through the same mapping — no autoload change needed for new versions
