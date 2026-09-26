# V3 Insights — usage tracking with consent (design)

Date: 2026-09-26 · Status: agreed in session with Shazzad, built in the overnight run of the same date
Repos: `shazzad/plugin-updater` (client, this repo) and `shazzad/plugin-repo` (server)

## Why

W4 Post List uses Appsero Insights for usage data. We want the same capability in-house, on the
repo server we already run, so that:

- **free wordpress.org plugins** (Adminkeep next, not W4 Post List — it stays on Appsero) can
  collect usage data after an explicit opt-in, as wp.org guideline 7 requires, and
- **commercial plugins** get the same richer data (plugin list, theme, users, server) through the
  pings they already send, **without** a consent step (Shazzad's call).

## Hard constraints

1. **Total isolation.** V1 (`src/*.php`) and V2 (`src/V2/`) stay byte-identical. Existing
   server routes (`wp-repo/v3/*`, `wp-repo/v4/products/{id}/ping`) and existing tables keep their
   behaviour. Only code that instantiates the new `V3` classes sees anything new.
2. **Free plugins carry no update code.** wp.org guideline 8 forbids self-updating from a
   non-wp.org source. `V3\Insights` must never load, instantiate or reference `Updater`,
   `License\*` or `Admin\LicensePage`, and free plugins strip every other file of this package
   from their zip at build time (Plugin Check skips `vendor/`, human review may not).
3. **No data before consent** in free plugins — not even a "skipped" ping. "No thanks" sends nothing.
4. No deactivation-feedback modal in this version.

## Client — `src/V3/` (namespace `Shazzad\PluginUpdater\V3`)

### Files

| File | Role | Loaded by |
|---|---|---|
| `Insights/Collector.php` | Builds the payload (site, admin, wp, server, users, plugins, meta) | both |
| `Insights/Client.php` | `track( $event )`, `optout()` HTTP calls to `wp-repo-insights/v1` | both |
| `Insights/Scheduler.php` | Daily cron hook, activation/deactivation/upgrade events, last-send bookkeeping | both |
| `Insights/Consent.php` | Consent state (`yes`/`no`/unset), token, `opt_in()` / `opt_out()` | both (commercial = always granted) |
| `Insights/Notice.php` | Appsero-style admin notice + nonce'd Allow / No thanks handler | free only |
| `Insights.php` | **Free entry point** — wires Collector, Client, Scheduler, Consent, Notice. Nothing else. | free |
| `Integration.php`, `Client.php`, `Updater.php`, `Tracker.php`, `License/Store.php`, `Admin/*` | **Commercial entry point** — copy of V2 with the ping replaced by the Insights parts (consent granted) | commercial |

Free plugins keep only `src/V3/Insights.php` + `src/V3/Insights/` in their zip.

### Free usage

```php
if ( class_exists( \Shazzad\PluginUpdater\V3\Insights::class ) ) {
    new \Shazzad\PluginUpdater\V3\Insights( [
        'api_url'       => 'https://w4dev.com/wp-json/wp-repo-insights/v1', // required
        'file'          => __FILE__,                                          // required
        'product_uid'   => 'prod_…',            // or product_id
        'product_id'    => '12',
        'name'          => 'Adminkeep',         // shown in the notice; default = plugin header Name
        'privacy_url'   => 'https://…',         // "Learn more" link in the notice; omitted when empty
        'notice'        => [
            'screens'       => [ 'settings_page_adminkeep' ], // screen ids; default: every admin screen
            'text'          => '…',                            // optional override, %s = name
            'show_callback' => fn() => true,                   // optional extra gate
        ],                                                     // or false = no notice (plugin draws its own UI and calls opt_in())
        'meta'          => [ … ],
        'meta_callback' => fn() => [ … ],
    ] );
}
```

Public API on the instance: `has_consent()`, `get_consent()` (`'yes'|'no'|''`), `opt_in()`,
`opt_out()`, `->collector->collect()` (for a "show me what you send" screen).

### Commercial usage

`V3\Integration` takes the V2 config array unchanged (`api_url` = the existing
`https://w4dev.com/wp-json/wp-repo/v3` update API) plus one optional key,
`insights_api_url` (default: derived by replacing `/wp-repo/v3` with `/wp-repo-insights/v1`).
Updates, license check, license page, notices and update message behave exactly as V2. Storage
keys, transients and the hourly `wprepo_sync_license_data_{license_name}` cron are the same as
V2, so a plugin moving V2 → V3 keeps every saved license. Difference: the hourly sync no longer
calls the old `/ping`; tracking goes through Insights (daily + activate/deactivate/upgrade), with
the license key in the payload so the server can bind the install.

### Consent notice (free)

Copy of Appsero's: an `notice notice-info` box for users with `manage_options`, on the configured
screens, while consent is unset:

> Want to help make **Adminkeep** even better? Allow Adminkeep to collect diagnostic data and
> usage information. (what we collect) [Allow] [No thanks]

"what we collect" is a `<details>` element (no JavaScript, no jQuery) listing: server environment
details (PHP, MySQL, server, WordPress versions); number of users on your site; site language;
number of active and inactive plugins; active plugins' names; site name and URL; **your name and
email address**; plus a "Learn more" link to `privacy_url` when set.

Allow / No thanks are nonce'd GET links handled on `admin_init` (skipped under `wp_doing_ajax()`),
capability-checked, then redirect back without the query args. Allow → consent `yes`, token
generated, daily cron scheduled, immediate `optin` track. No thanks → consent `no`, nothing sent.

### Options (per plugin, `{slug}` = plugin directory)

`{slug}_insights_consent` (`yes`/`no`), `{slug}_insights_token` (random 32 chars, sent with every
call, required by optout), `{slug}_insights_last_send` (timestamp). Cron hook
`wprepo_insights_track_{slug}`, **daily** — not weekly like Appsero, because the server marks an
install inactive after 7 days without a check-in (`wprepo_deactivate_unused_installs_days`).
Deactivation sends a `deactivate` track (when consented) and clears the cron; the options stay so
a re-activation doesn't re-ask. `opt_out()` sends `optout`, sets consent `no`, clears the cron.

### Payload (JSON body)

```json
{
  "event": "daily|activate|deactivate|optin|upgrade",
  "mode": "consent|commercial",
  "token": "…",
  "product_version": "2.0.0", "product_status": "active|inactive",
  "site":   { "url": "https://…", "name": "…", "locale": "en_US", "is_local": false, "multisite": false },
  "admin":  { "email": "…", "name": "…" },
  "wp":     { "version": "6.9", "memory_limit": "256M", "debug_mode": false,
              "theme": { "slug": "…", "name": "…", "version": "…", "parent": "" } },
  "server": { "php_version": "8.3", "db_version": "…", "server_software": "…",
              "php_memory_limit": "…", "max_execution_time": 30, "upload_max_filesize": "…" },
  "users":  { "total": 3, "by_role": { "administrator": 1 } },
  "plugins":{ "active_count": 12, "inactive_count": 3,
              "active": [ { "slug": "…", "name": "…", "version": "…" } ] },
  "license": "…",          // commercial only, when set
  "meta":    { … }         // plugin's own counters
}
```

Admin email = `admin_email` option, admin name = first administrator's display name (the V2
rule). `plugins.active` is capped at 200 entries. Timeouts: 5 s, errors swallowed (next daily
run retries).

## Server — `plugin-repo`

### Routes (new namespace `wp-repo-insights/v1`, public, `permission_callback` `__return_true`)

- `POST /products/{key}/track` — `{key}` = numeric id or `prod_` uid. 404 unknown product, 403
  when the product has install tracking off, 400 on missing `site.url` / `product_version` /
  `token`. Writes the **same `installs` row** the old ping writes (matched by product +
  `wp_url_key` via `Install\Data::create_install()`), including `admin_email`/`admin_name`,
  env columns and — only when `license` is present — the license binding. `meta` goes to
  `installmeta` (as today). Everything else goes to the new `install_insights` row. Returns 202.
- `POST /products/{key}/optout` — `{ site_url, token }`. Only when the stored token matches:
  deletes the install row, its meta, its insights row and its install events. 202 either way
  (no oracle for which sites exist).

### Table `{prefix}wprepo_install_insights`

`install_id` (PK), `product_id`, `mode` (`consent`/`commercial`), `token_hash`, `site_name`,
`is_local`, `multisite`, `theme`, `users_total`, `active_plugins`, `inactive_plugins`, `data`
(longtext JSON: wp, server, users, plugins), `last_event`, `created`, `updated`; KEY `product_id`.
Created by `Installer::install_tables()`; DB version bumped so `maybe_upgrade_db()` adds it on
deploy. Track replaces the token hash (last writer wins — spoofing risk equals the existing
open ping); optout requires it.

### Admin

The Installs detail view gains an "Insights" block (mode, consent date, site name, theme, users,
plugin counts, active plugin list) when a row exists. Nothing else in the admin changes.

### Free products

A wp.org plugin gets a normal product row with install tracking on and no versions uploaded, so
`details`/`updates`/`download` never offer it anything.

## Testing

- Client: PHPUnit + Brain Monkey in `tests/V3/`; a guard test asserting `src/V3/Insights*`
  never references `Updater`, `License` or `LicensePage`; `git diff main -- src/*.php src/V2`
  empty.
- Server: `wp eval-file` tests in `tests/insights-*-test.php` run in the w4dev stack.
- End to end: a free test plugin and a commercial V3 test plugin in `shazzad-plugin-updater-test`
  pointed at the local w4dev stack's own repo server.
- Manual QA sheet for Shazzad: `docs/testing/v3-insights-manual-qa.md` (this repo).

## Out of scope

Deactivation modal · W4 Post List migration · Adminkeep adoption · releases/deploys ·
dashboards/reports over the collected data.
