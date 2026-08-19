# Changelog

## 2.0.0 - 2026-08-19

The library is now versioned by namespace: the legacy `Shazzad\PluginUpdater` classes in `src/`
are **frozen** (critical fixes only) and all new development lives in `Shazzad\PluginUpdater\V2`
under `src/V2/`. Different majors coexist on one site without interacting, which ends the
first-loader-wins fatal when two plugins bundle different library versions. Existing plugins are
untouched until they opt in by instantiating the V2 namespace.

- New: `V2\Integration` takes a single config array (`api_url`, `file`, `product_uid`,
  `product_id`, `license`, `menu`, `meta`, `meta_callback`) instead of positional arguments.
  `file` accepts `__FILE__` or basename form. Unknown keys, missing `api_url`/`file`, a
  non-callable `meta_callback`, and `product_uid` without `product_id` each fire
  `_doing_it_wrong()` — visible in debug, never fatal
- New: `V2\Admin\Notices` — dismissible admin notices when no license key is set ("enter your
  license key") or the license has expired ("renew", linking `renewal_url`), shown to users who
  can update plugins, with a one-week nonce-protected snooze per product per notice type
- New: `V2\Admin\UpdateMessage` — a line inside the plugins-list update row explaining why the
  update is unavailable on unlicensed/expired sites
- Changed (V2): storage/migration/license-data logic moved to `V2\License\Store`; the license
  admin page is `V2\Admin\LicensePage`. Option keys, transients, and cron hook names are
  identical to V1, so switching a plugin to V2 keeps every customer's saved license
- Changed (V2): `Client::ping()` resolves any callable `meta_callback` (V1 only invoked
  Closures); string values inside `meta` remain data
- Packaging: `docs/` and `.gitattributes` are export-ignored, so Composer dist installs ship
  code, README, and changelog only

## 1.5.0 - 2026-08-09

- New: `Integration::setProductUid( $product_uid )` fluent setter — store an opaque product uid (prefix `prod_…`) used for API URLs and license storage keys when set; numeric `product_id` behavior is unchanged when the uid is not set
- New: on first `setProductUid()` call, existing id-based license options (`_code`, `_data`) are automatically cloned to uid-based keys; old copies are retained until a future 1.6 prune release for backward compatibility
- Changed: `delete_license_code()` and `delete_license_data()` also remove the corresponding uid-based option copy when `setProductUid()` has been called

## 1.4.0 - 2026-08-05

- Stop deleting the stored license code when the server answers `invalid_license`. That code is returned for any unmatched code/product pair — a site pointed at the wrong product, a license row removed by mistake — not only for a revoked license, and enforcement is server-side either way, so discarding the customer's only copy of the key achieved nothing. The stored data is now marked `status: invalid` instead, preserving `renewal_url` and the rest
- Fix: a rejected key submitted on the license page no longer wipes a previously working license. The submitted key is only stored once it verifies, so the failure path was deleting the old key and storing nothing
- Add `Integration::mark_license_invalid()`
- The admin license panel now explains an unrecognised key instead of reporting a missing upgrade package
- Ping now resolves the plugin and site details it reports instead of relying on the `init` hook having fired. Activating a plugin includes its file and fires `activate_{file}` after `init` has already passed, so the callback registered in the constructor never ran and the activation ping reported `admin_email` and `admin_name` empty — which the server then wrote over the stored values. `product_version` escaped only because `clear_updates_transient()` happens to backfill it first
- Add `Integration::prepare_product_data()`; `Updater::init()` now delegates to it, and values already set are left alone so the normal ping path does no extra work

## 1.3.0 - 2026-08-05

- Send the stored license code with each ping, so the server can bind the install to its license. Previously ping carried the site environment but no license, while `updates`/`check_license` carried the license but no site identity — the two halves never met, and install rows were recorded unbound. Requires `shazzad-plugin-repo` 1.7.1 or later, which stops treating a license-less ping as an instruction to clear the binding

## 1.2.1 - 2026-07-27

- README: wrap the Quick Start example in a `class_exists()` guard so a build missing the library degrades gracefully instead of fataling
- README: remove `php_version` from the `setMeta()` example — it's already sent automatically as a top-level ping field since 1.2.0

## 1.2.0 - 2026-07-25

- Report server environment automatically with every ping: `php_version`, `db_version`, and `server_software` are now sent as top-level fields — no `setMeta()`/`setMetaCallback()` entries needed for those. Values degrade to empty strings when unavailable (e.g. WP-CLI), and the server ignores empty values, so no configuration is required

## 1.1.4 - 2026-07-21

- Clear cached API responses when the Dashboard → Updates screen loads, so a manual "Check again" fetches fresh update data instead of waiting out the 10-minute response cache
- Document `setMetaCallback()` in the README with combined static + dynamic metadata examples

## 1.1.3 - 2026-07-17

- Guard `update_plugins` transient handling against non-object values (prevents a PHP 8 fatal on cold or corrupted transients)
- Remove redundant inline styles from the sync button

## 1.1.2 - 2026-03-27

- Only resolve `Closure` instances in metadata values, not arbitrary callables

## 1.1.1 - 2026-03-27

- Add `setMeta()` and `setMetaCallback()` for custom install metadata

## 1.1.0 - 2026-03-27

- Send ping as POST request with `admin_email` and `admin_name`
- Skip update transient injection when plugin is inactive
- Send ping on hourly cron for licensed plugins

## 1.0.x

- Refactored into modular structure
- Improved error handling
- Enhanced security measures
- Better WordPress integration
- Comprehensive documentation
