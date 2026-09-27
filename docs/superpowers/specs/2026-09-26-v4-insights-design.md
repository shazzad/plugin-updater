# V4 Insights — usage tracking with consent (design)

Date: 2026-09-26 · Status: agreed in session with Shazzad, built in the overnight run of the same date
Repos: `shazzad/plugin-updater` (client, this repo) and `shazzad/plugin-repo` (server)

> **Revised 2026-09-27 (Shazzad):** the Insights routes no longer live in a side namespace
> `wp-repo-insights/v1`, and `V4\Integration` no longer derives an Insights URL from `api_url`
> or accepts `insights_api_url`. The server's `wp-repo/v4` is now a full copy of v3 plus
> `track` / `optout`, and both V4 entry points use one `api_url` =
> `https://w4dev.com/wp-json/wp-repo/v4`. v3 is untouched. Sections below are updated to match;
> "Hard constraints" 1 still holds for `wp-repo/v3`.
>
> **Revised again 2026-09-27 (Shazzad):** the namespace was built as `V3` and renamed `V4`
> before release, so the updater's namespace number matches the API it calls (`V4` ↔
> `wp-repo/v4`; V1/V2 ↔ `wp-repo/v3` is the legacy exception); the library release is 4.0.0,
> not 3.0.0. The server's v4 then moved to plugin routes (`shazzad/plugin-repo` 2.9.0, contract
> in its `docs/v4-api.md`): every call is `{api_url}/plugins/{uid}/…`, only the `prod_…` uid is
> accepted (a numeric id is `404 rest_no_route`), there is no `ping` on v4, and the track
> payload says `plugin_version` / `plugin_status`. So `product_uid` is **required** by both
> entry points — without it `_doing_it_wrong()` fires and nothing is sent — and `product_id` is
> optional, never sent, kept only for V1 id-keyed license storage. Config keys and the
> `product_*` properties keep their names. Sections below are updated to match.

## Why

W4 Post List uses Appsero Insights for usage data. We want the same capability in-house, on the
repo server we already run, so that:

- **free wordpress.org plugins** (Adminkeep next, not W4 Post List — it stays on Appsero) can
  collect usage data after an explicit opt-in, as wp.org guideline 7 requires, and
- **commercial plugins** get the same richer data (plugin list, theme, users, server) through the
  pings they already send, **without** a consent step (Shazzad's call).

## Hard constraints

1. **Total isolation.** V1 (`src/*.php`) and V2 (`src/V2/`) stay byte-identical. Existing
   server routes (`wp-repo/v3/*`) and existing tables keep their behaviour. Only code that instantiates the new `V4` classes sees anything new.
2. **Free plugins carry no update code.** wp.org guideline 8 forbids self-updating from a
   non-wp.org source. `V4\Insights` must never load, instantiate or reference `Updater`,
   `License\*` or `Admin\LicensePage`, and free plugins strip every other file of this package
   from their zip at build time (Plugin Check skips `vendor/`, human review may not).
3. **No data before consent** in free plugins — not even a "skipped" ping. "No thanks" sends nothing.
4. No deactivation-feedback modal in this version.

## Client — `src/V4/` (namespace `Shazzad\PluginUpdater\V4`)

### Files

| File | Role | Loaded by |
|---|---|---|
| `Insights/Collector.php` | Builds the payload (site, admin, wp, server, users, plugins, meta) | both |
| `Insights/Client.php` | `track( $event )`, `optout()` HTTP calls to `wp-repo/v4` | both |
| `Insights/Scheduler.php` | Daily cron hook, activation/deactivation/upgrade events, last-send bookkeeping | both |
| `Insights/Consent.php` | Consent state (`yes`/`no`/unset), token, `opt_in()` / `opt_out()` | both (commercial = always granted) |
| `Insights/Notice.php` | Appsero-style admin notice + nonce'd Allow / No thanks handler | free only |
| `Insights.php` | **Free entry point** — wires Collector, Client, Scheduler, Consent, Notice. Nothing else. | free |
| `Integration.php`, `Client.php`, `Updater.php`, `Tracker.php`, `License/Store.php`, `Admin/*` | **Commercial entry point** — copy of V2 with the ping replaced by the Insights parts (consent granted) | commercial |

Free plugins keep only `src/V4/Insights.php` + `src/V4/Insights/` in their zip.

### Free usage

```php
if ( class_exists( \Shazzad\PluginUpdater\V4\Insights::class ) ) {
    new \Shazzad\PluginUpdater\V4\Insights( [
        'api_url'       => 'https://w4dev.com/wp-json/wp-repo/v4', // required
        'file'          => __FILE__,                                          // required
        'product_uid'   => 'prod_…',            // required
        'product_id'    => '12',                // optional, never sent
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

`V4\Integration` takes the V2 config array unchanged, with `api_url` =
`https://w4dev.com/wp-json/wp-repo/v4`, which serves updates, licensing and Insights at
`{api_url}/plugins/{uid}/…`. `product_uid` is required (without it: `_doing_it_wrong()`, no
update, license or Insights call); `product_id` only reaches V1 id-keyed license storage.
Updates, license check, license page, notices and update message behave exactly as V2. Storage
keys, transients and the hourly `wprepo_sync_license_data_{license_name}` cron are the same as
V2, so a plugin moving V2 → V4 keeps every saved license. Difference: the hourly sync no longer
calls the old `/ping`; tracking goes through Insights (daily + activate/deactivate/upgrade), with
the license key in the payload so the server can bind the install.

### Consent notice (free)

Copy of Appsero's: an `notice notice-info` box for users with `manage_options`, on the configured
screens, while consent is unset:

> Want to help make **Adminkeep** even better? Allow Adminkeep to collect diagnostic data and
> usage information. (what we collect) [Allow] [No thanks]

"what we collect" is a `<details>` element (no JavaScript, no jQuery). The notice is the consent,
so it lists every group of the payload (revised after review, 2026-09-26 — the first list
under-disclosed):

- Site name, URL and language, whether it is a multisite, and whether it looks like a local
  development site
- **Your site's admin email address and administrator name** (the `admin_email` option and the
  first administrator's display name — not the user who clicks Allow)
- WordPress version, memory limit and debug mode
- Active theme (name, version and parent theme)
- Server environment details (PHP and MySQL versions, server software, PHP memory, execution
  time and upload limits)
- Number of users on your site, by role
- Number of active and inactive plugins, and the names and versions of active plugins
- "Usage statistics specific to {name}" — added automatically when `meta` or `meta_callback`
  is configured
- any extra lines from the `notice.items` config key (strings; non-strings and empty strings
  are dropped with a `_doing_it_wrong()` notice) — for plugins to spell out what their `meta`
  holds

plus a "Learn more" link to `privacy_url` when set. `Notice::get_collected_items()` must stay in
step with `Collector::collect()`; `NoticeTest::discloses_every_payload_group` guards it.

Allow / No thanks are nonce'd GET links handled on `admin_init` (skipped under `wp_doing_ajax()`),
capability-checked, then redirect back without the query args. Allow → consent `yes`, token
generated, daily cron scheduled, immediate `optin` track. No thanks → consent `no`, nothing sent.

### Options (per plugin, `{slug}` = plugin directory)

`{slug}_insights_consent` (`yes`/`no`), `{slug}_insights_token` (random 32 chars, sent with every
call, required by optout), `{slug}_insights_last_send` (timestamp), `{slug}_insights_optout_pending`
(`since` / `last_try` of an opt-out that failed to reach the server). Cron hook
`wprepo_insights_track_{slug}`, **daily** — not weekly like Appsero, because the server marks an
install inactive after 7 days without a check-in (`wprepo_deactivate_unused_installs_days`).
Deactivation sends a `deactivate` track (when consented) and clears the cron; the options stay so
a re-activation doesn't re-ask; a **network** deactivation clears the cron on every site (only
the current site sends `deactivate`). `opt_out()` sends `optout`, sets consent `no`, clears the
cron. If the `optout` call fails (timeout, 5xx), consent is still `no` at once but the request is
kept in `_optout_pending` and retried — on `admin_init` (at most hourly) and by the daily cron,
which is kept for that — until it succeeds, a new opt-in supersedes it, or 7 days pass since the
first failure. While pending, the client may send `optout` (only `{site_url, token}`) despite
consent `no`; tracks stay refused. `Insights::uninstall( $file )` deletes all four options and
the cron, on every site of a network; plugins call it from `uninstall.php` /
`register_uninstall_hook()`.

Commercial installs have a second trigger for the daily track: the hourly
`wprepo_sync_license_data_{name}` event (scheduled by `Updater` on every `init`) self-heals the
daily cron and calls `run_daily()` (its 20 h `MIN_INTERVAL` prevents doubles). Without it, a
plugin updated in place V2 → V4 on a site where nobody opens wp-admin would never schedule the
daily cron, never track, and be marked inactive by the server after 7 days.

### Payload (JSON body)

```json
{
  "event": "daily|activate|deactivate|optin|upgrade",
  "mode": "consent|commercial",
  "token": "…",
  "plugin_version": "2.0.0", "plugin_status": "active|inactive",
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

### Routes (on `wp-repo/v4`, public, `permission_callback` `__return_true`)

- `POST /plugins/{uid}/track` — `{uid}` = the `prod_` uid only (a numeric id is
  `404 rest_no_route`). 404 `wprepo_insights_unknown_plugin` for an unknown uid, 403 when the
  plugin has install tracking off, 400 on missing `site.url` / `plugin_version` / `token`. Writes the **same `installs` row** the old ping writes (matched by product +
  `wp_url_key` via `Install\Data::create_install()`), including `admin_email`/`admin_name`,
  env columns and — only when `license` is present — the license binding. `meta` goes to
  `installmeta` (as today). Everything else goes to the new `install_insights` row. Returns 202.
- `POST /plugins/{uid}/optout` — `{ site_url, token }`. Only when the stored token matches and
  the row's mode is `consent`: if an Insights track created the install row (`owns_install`),
  deletes the install row, its meta, its insights row and its install events; otherwise (the row
  came from the old v3 ping) removes only the insights row. Commercial rows are never deleted
  this way. 202 either way (no oracle for which sites exist); 413 over 64 KB.
- Both routes reject site URLs containing a query-builder reserved value (`__empty__`,
  `__not_empty__`, …) and treat such license codes, or an empty one, as "no license".
  Bodies over 64 KB get 413. (Review fixes 2026-09-26; see plugin-repo#77.)

### Table `{prefix}wprepo_install_insights`

`install_id` (PK), `product_id`, `mode` (`consent`/`commercial`), `token_hash`, `site_name`,
`is_local`, `multisite`, `theme`, `users_total`, `active_plugins`, `inactive_plugins`, `data`
(longtext JSON: wp, server, users, plugins), `last_event`, `created`, `updated`; KEY `product_id`.
Created by `Installer::install_tables()`; DB version bumped so `maybe_upgrade_db()` adds it on
deploy (`Installer::DB_VERSION`, stamped only once the table exists). Plus `owns_install`
(1 when an Insights track created the install row). The token is **first writer wins**: stored
when the insights row is created, never replaced by a track with a different token (such a track
still updates the data, as the open ping does); only the stored token can change `mode`.
Optout requires it.

### Admin

The Installs detail view gains an "Insights" block (mode, consent date, site name, theme, users,
plugin counts, active plugin list) when a row exists. Nothing else in the admin changes.

### Free products

A wp.org plugin gets a normal product row with install tracking on and no versions uploaded, so
`details`/`updates`/`download` never offer it anything.

## Testing

- Client: PHPUnit + Brain Monkey in `tests/V4/`; a guard test asserting `src/V4/Insights*`
  never references `Updater`, `License` or `LicensePage`; `git diff main -- src/*.php src/V2`
  empty.
- Server: `wp eval-file` tests in `tests/insights-*-test.php` run in the w4dev stack.
- End to end: a free test plugin and a commercial V4 test plugin in `shazzad-plugin-updater-test`
  pointed at the local w4dev stack's own repo server.
- Manual QA sheet for Shazzad: `docs/testing/v4-insights-manual-qa.md` (this repo).

## Out of scope

Deactivation modal · W4 Post List migration · Adminkeep adoption · releases/deploys ·
dashboards/reports over the collected data.
