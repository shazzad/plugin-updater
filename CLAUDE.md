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

Tests use PHPUnit 9 with Brain Monkey for WordPress function mocking. Fixture-based JSON files in `tests/fixtures/` represent API response shapes and are shared by both suites: legacy tests in `tests/`, V2 tests in `tests/V2/`. CI (`.github/workflows/tests.yml`) runs `composer test` on PHP 7.4, so both suites must stay green.

## Architecture

Since 2.0.0 (2026-08-19) the library is **versioned by namespace**. Both trees ship in the same
package and coexist on one site without interacting, which is what ends the first-loader-wins
fatal when two plugins bundle different library versions:

- **`src/` — `Shazzad\PluginUpdater` (V1) — FROZEN.** Critical/security fixes only; every
  feature request is a V2 answer. Still what most consumer plugins load.
- **`src/V2/` — `Shazzad\PluginUpdater\V2` — active.** All new development. Within V2 changes
  are additive-only; any breaking change opens `src/V3/`.

Adoption is opt-in per plugin: bump the Composer constraint to `^2.0` and instantiate the `V2`
namespace. Storage compatibility is sacred — V2 uses the same option keys, transients, and cron
hook names as V1, so a plugin moving V1→V2 keeps every customer's saved license. The pending
legacy-key prune (issue #24) is a V2-era decision; never ship it in the frozen namespace.
Design: `docs/superpowers/specs/2026-08-19-versioned-namespace-v2-design.md`.

### V2 (`src/V2/`)

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

### Shared by both

- `Updater` hooks `pre_set_site_transient_update_plugins`, `plugins_api`,
  `upgrader_package_options`, `upgrader_process_complete`, and `load-update-core.php` (clears
  the API response cache).
- The license admin page requires `delete_users` (`Admin.php` and `V2/Admin/LicensePage.php`);
  it handles save/verify via POST with nonce verification.
- All classes receive the `Integration` instance and use its public properties directly (no
  getters/setters pattern). API calls go through `$integration->client->method()` — each
  returns an associative array on success or `WP_Error` on failure.

## Code Conventions

- Every file, V1 and V2, keeps the WordPress `ABSPATH` guard and a `class_exists()` guard
  against its own namespace
- Namespace `Shazzad\PluginUpdater` with PSR-4 autoloading from `src/`; the `V2` sub-namespace
  resolves through the same mapping — no autoload change needed for new versions
