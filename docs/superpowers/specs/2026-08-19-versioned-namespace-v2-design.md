# Versioned namespaces: freeze legacy, open `src/V2/`

**Date:** 2026-08-19 · **Status:** approved (chat design, Shazzad) · **Target release:** 2.0.0

## Problem

Every class in the library is wrapped in `if ( ! class_exists( ... ) )` under the single
namespace `Shazzad\PluginUpdater`, and every consumer plugin bundles its own copy. On a site
running two of Shazzad's plugins, whichever plugin loads first supplies the updater code for
all of them. A plugin built against a newer minor calling a method the loaded older copy
lacks (e.g. `setProductUid()`, added in 1.5) fatals on the customer's site. Version skew
across the ten-product fleet makes this inevitable, not hypothetical.

## Decision

Each **major** version of the library lives in its own namespace and directory, so different
majors coexist on one site without interacting:

```
src/                      ← legacy namespace Shazzad\PluginUpdater — FROZEN (critical fixes only)
src/V2/                   ← namespace Shazzad\PluginUpdater\V2 — all new development
  Integration.php         ← entry point + config
  Client.php              ← HTTP client for the repo API
  Updater.php             ← update_plugins filter wiring
  Tracker.php             ← pings + hourly license sync
  License/
    Store.php             ← option storage, uid-keyed, legacy-key migration, license data/status
  Admin/
    LicensePage.php       ← the license settings page (port of Admin.php)
```

Version numbers are **library majors**, deliberately independent of the repo server API
versions (Shazzad's call, 2026-08-19). PSR-4 already resolves the `V2` subnamespace from the
existing `"Shazzad\\PluginUpdater\\": "src/"` mapping — no composer autoload change.

## Rules

1. **Legacy `src/` is frozen.** Security/critical fixes only. Every feature request from now
   on is a V2 (or later) answer.
2. **Within a versioned namespace, changes are additive-only.** Any breaking change — or any
   new method consumer plugins will call where mixed-version sites are a risk — opens the
   next `src/V<n>/`. This discipline is what actually kills first-loader-wins fatals.
3. **Storage compatibility is sacred.** V2 reads/writes the same wp_options keys V1 wrote
   (uid-keyed primary from 1.5, with legacy id-key migration retained), so a plugin upgrading
   V1→V2 keeps every customer's saved license with zero customer action. Same cron hook
   names, same transient keys.
4. Every V2 file keeps the per-file `class_exists` guard, against its own namespace.

## V2 entry API

Replace the 8 positional constructor args with a config array; product uid becomes the
primary identity (1.5 retrofitted it via a setter):

```php
new Shazzad\PluginUpdater\V2\Integration( [
    'api_url'     => 'https://w4dev.com/wp-json/wp-repo/v3/',
    'file'        => __FILE__,                    // plugin main file
    'product_uid' => 'prod_xxx',                  // preferred identity
    'product_id'  => 12,                          // optional legacy identity
    'license'     => true,                        // false = free mode
    'menu'        => [ 'parent' => 'plugins.php', 'label' => 'My Plugin License' ],
    'meta'        => [ 'memory_limit' => ini_get( 'memory_limit' ) ],   // optional
    'meta_callback' => 'my_plugin_updater_meta',                        // optional
] );
```

Fluent setters (`setMeta`, `setMetaCallback`) are retained for parity — `meta` and
`meta_callback` config keys apply the same values at construction (Shazzad's addition,
2026-08-19). `setProductUid` becomes unnecessary (config key) but stays as a thin alias so
ported plugin code diffs stay small.

**Config validation:** unrecognized config keys (typos) and missing required keys
(`api_url`, `file`) each trigger a `_doing_it_wrong()` notice — visible in debug mode, free
in production, and guarded by `function_exists` for non-WP contexts. Construction always
proceeds: a misconfigured updater must never fatal the plugin embedding it.

## Phase 1 scope: the port (done 2026-08-19)

Behavior-identical port of the five legacy classes into the layout above. `Integration`'s
storage/migration/license-data methods (~half its 698 lines) move to `License\Store`;
`Client`/`Updater`/`Tracker` follow their references. **No behavior changes** beyond the
constructor surface. All 8 existing test files are ported to `tests/V2/` (adapted to the
config-array constructor and moved classes); the legacy tests stay untouched and both suites
run in CI. PHP 7.4 floor unchanged (WPCS + PHPCompatibility lint must pass on `src/`).

## Phase 2 scope: license visibility (same branch, ships IN 2.0.0)

*Revised 2026-08-19 (Shazzad's call): these ship on `feature/v2-namespace` before the tag,
so 2.0.0 is the complete package — no interim 1.x or 2.1.0 for them.*

- **Admin notices** (`Admin/Notices.php`): unlicensed → "set license" notice; expired →
  renew notice (with `renewal_url`), shown with or without an update available; dismissible
  with a one-week snooze.
- **Update-row message** (`Admin/UpdateMessage.php`): non-dismissible
  `in_plugin_update_message` line explaining the empty package on expired/unlicensed sites.

## Release plan

Feature branch + PR → suite green (legacy + V2) → Shazzad tests → tag `2.0.0` (no `v`
prefix) + GitHub release. Packagist picks up the tag; plugins adopt deliberately by bumping
their constraint to `^2.0` and switching the instantiation namespace. First adopter: LoxoWP,
to prove the migration path (licenses must survive untouched). The pending 1.6.0 legacy-key
prune (issue #24, not before ~Sep 2026) becomes a V2-era decision — do not ship it in the
frozen legacy namespace.
