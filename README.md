# WordPress Plugin Updater Package

A comprehensive WordPress plugin updater library that enables automatic updates, license verification, and remote plugin management for custom WordPress plugins.

## Features

- **Automatic Plugin Updates**: Seamlessly check for and install plugin updates from your remote server
- **License Management**: Built-in license key verification and validation system
- **Admin Interface**: Clean WordPress admin interface for license management
- **Plugin Tracking**: Track plugin activation, deactivation, and usage statistics
- **Insights (V3)**: Appsero-style usage data — opt-in with a consent notice for free wordpress.org plugins, implied for commercial plugins
- **WordPress Integration**: Hooks into WordPress core update system
- **Flexible Configuration**: Customizable API endpoints, menu placement, and licensing options

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- Valid API server endpoint for plugin updates and license verification

## Installation

```bash
composer require shazzad/plugin-updater
```

## Which namespace to use

The library ships one namespace per major version, side by side:

- **`Shazzad\PluginUpdater\V3`** (`src/V3/`) — active since 3.0.0. **New consumers should use
  this.** Two entry points:
  - **`V3\Insights`** — for **free wordpress.org plugins**: usage tracking only, after an
    explicit opt-in through an Appsero-style consent notice. No update or license code at all;
    ship only the Insights files (see [Shipping a free wp.org plugin](#shipping-a-free-wporg-plugin)).
  - **`V3\Integration`** — for **commercial plugins**: everything V2 does (updates, license page,
    notices, update-row message), with install tracking moved from the old `/ping` to Insights
    (consent implied, license key in the payload). Same option keys, transients and cron hook
    as V2/V1, so a plugin moving to V3 keeps every saved license.

  Both talk to the repo server's **`wp-repo/v4`** API — one `api_url`
  (`https://w4dev.com/wp-json/wp-repo/v4`) serves updates, licensing and Insights. `wp-repo/v3`
  has no Insights routes and stays for V1/V2 clients. Requires
  `composer require shazzad/plugin-updater:^3.0` and `shazzad/plugin-repo` 2.8.0+ on the server.
- **`Shazzad\PluginUpdater\V2`** (`src/V2/`) — stable: additive fixes only. Config-array
  constructor, license admin notices, and an explanation line in the plugins-list update row.
- **`Shazzad\PluginUpdater`** (`src/`) — V1, frozen: critical fixes only. Existing plugins keep
  working unchanged and opt in to a newer major deliberately by bumping the constraint and
  switching the namespace.

The majors never share classes, so plugins on different library versions coexist on one site
without the first-loader-wins fatal V1 was exposed to.

## Quick Start (V3, free plugin — Insights)

```php
<?php
// Nothing is sent until an admin clicks "Allow" in the notice.
if ( class_exists( \Shazzad\PluginUpdater\V3\Insights::class ) ) {
    $insights = new \Shazzad\PluginUpdater\V3\Insights( [
        'api_url'       => 'https://w4dev.com/wp-json/wp-repo/v4', // required
        'file'          => __FILE__,                               // required
        'product_uid'   => 'prod_xxxxxxxxxxxxxxxxxxxx',            // or product_id
        'name'          => 'My Plugin',          // shown in the notice; default = plugin header Name
        'privacy_url'   => 'https://example.com/privacy', // "Learn more" link; omitted when empty
        'notice'        => [
            'screens' => [ 'settings_page_my-plugin' ], // screen ids; default: every admin screen
        ],                                              // or false = draw your own UI, call opt_in()
        'meta_callback' => function () {
            return [ 'lists' => (int) wp_count_posts( 'my_list' )->publish ];
        },
    ] );
}
```

The notice ("Want to help make **My Plugin** even better?… Allow / No thanks") shows to users
with `manage_options` while consent is unanswered. **Allow** stores consent, schedules the daily
`wprepo_insights_track_{slug}` cron and sends an `optin` track; **No thanks** stores the refusal
and sends nothing. On the instance: `has_consent()`, `get_consent()` (`'yes'|'no'|''`),
`opt_in()`, `opt_out()` (asks the server to delete this site's data; a failed request is retried
on `admin_init` and daily for up to 7 days), and `->collector->collect()` to show exactly what
would be sent.

What is sent (JSON, `POST {api_url}/products/{uid-or-id}/track`): event, plugin version and
status, site URL/name/locale plus multisite and local-site flags, the site's `admin_email` and
the first administrator's display name, WordPress version/memory limit/debug mode, the active
theme (name, version, parent), server versions and PHP limits, user counts by role,
active/inactive plugin counts and the active plugin list with versions (max 200), and your
`meta`. Daily, plus `activate` / `deactivate` / `upgrade` / `optin` events. The notice's
"What we collect" list discloses all of it, adds "Usage statistics specific to {name}" when
`meta` or `meta_callback` is set, and appends any `notice.items` you pass — describe what your
`meta` holds there.

## Quick Start (V3, commercial plugin)

```php
<?php
if ( class_exists( \Shazzad\PluginUpdater\V3\Integration::class ) ) {
    new \Shazzad\PluginUpdater\V3\Integration( [
        'api_url'     => 'https://w4dev.com/wp-json/wp-repo/v4',
        'file'        => __FILE__,
        'product_uid' => 'prod_xxxxxxxxxxxxxxxxxxxx',
        'product_id'  => '12',
        'license'     => true,
        'menu'        => [ 'parent' => 'options-general.php' ],
        'meta'        => [ 'channel' => 'direct' ], // optional, sent with every track
    ] );
}
```

The config is the V2 array, with `api_url` on `wp-repo/v4`: the same base serves updates,
licensing and Insights. An `api_url` still on `wp-repo/v3` (copied from a V2 config) fires
`_doing_it_wrong()` and leaves tracking off — v3 has no Insights routes (updates and licensing
still work). Tracking needs no consent here and
never shows a notice; the stored license key is included so the server can bind the install.
The Insights parts are public properties: `$insights_consent`, `$insights_collector`,
`$insights_client`, `$insights_scheduler` (all `null` when tracking is off).

Moving a plugin from V2: bump to `^3.0`, change `V2` to `V3` in the namespace and `api_url`
from `…/wp-repo/v3` to `…/wp-repo/v4`. Saved
licenses, the license page and the hourly `wprepo_sync_license_data_{name}` cron carry over.
Check your plugin for these V2 surfaces, which changed:

- `Client::ping()` is gone, and so is the old `/ping` call. The hourly sync checks the license
  and backs up the daily Insights track (self-healing its cron), so a plugin updated in place
  on a site nobody opens wp-admin on still checks in.
- `Integration` no longer has the public `$admin_email` / `$admin_name` properties; the
  collector reads both at send time.
- Metadata: assigning `$integration->meta` or `$integration->meta_callback` directly after
  construction does **not** reach the payload (the collector took its copy in the
  constructor). Pass `meta` / `meta_callback` in the config, or call `setMeta()` /
  `setMetaCallback()`.

## Shipping a free wp.org plugin

wordpress.org guideline 8 forbids a plugin from updating itself from anywhere but wordpress.org,
and reviewers read `vendor/`. `V3\Insights` never loads update or license code (a test guards
this), but the package also contains V1, V2 and the commercial V3 files — **strip them from the
release zip**. Keep only:

- `vendor/shazzad/plugin-updater/src/V3/Insights.php`
- `vendor/shazzad/plugin-updater/src/V3/Insights/`
- Composer's own autoload files (`vendor/autoload.php`, `vendor/composer/`)

Then regenerate the autoloader so no classmap entry points at a deleted file (with a stale
optimized classmap, a `class_exists()` on a stripped class would `include` a missing file):

```bash
# Run inside the build copy of the plugin, after `composer install --no-dev`.
PKG=vendor/shazzad/plugin-updater

# Everything in the package dir except src/ (composer.json, README, CHANGELOG, …)
find "$PKG" -mindepth 1 -maxdepth 1 ! -name src -exec rm -rf {} +
# Everything in src/ except V3/ (V1 files and V2/)
find "$PKG/src" -mindepth 1 -maxdepth 1 ! -name V3 -exec rm -rf {} +
# Everything in V3/ except the Insights entry point and its folder
find "$PKG/src/V3" -mindepth 1 -maxdepth 1 ! -name Insights.php ! -name Insights -exec rm -rf {} +

composer dump-autoload --no-dev --optimize

# Sanity check: must print nothing (whole words, so "PluginUpdater" does not match).
grep -rlwE 'Updater|LicensePage|Integration|Store' "$PKG" || true
```

## Quick Start (V2)

```php
<?php
// Guarded with class_exists() so a build that's missing the library degrades
// to "no license/update UI" instead of a fatal error on every request.
if ( class_exists( \Shazzad\PluginUpdater\V2\Integration::class ) ) {
    new \Shazzad\PluginUpdater\V2\Integration( [
        'api_url'     => 'https://your-api-server.com/api',
        'file'        => __FILE__,              // or plugin_basename( __FILE__ ) — both accepted
        'product_uid' => 'prod_xxxxxxxxxxxxxxxxxxxx', // preferred identity
        'product_id'  => '12',                  // legacy identity; needed to reach id-keyed licenses
        'license'     => true,                  // false = update checks only
        'menu'        => [                      // omit for defaults; false hides the page
            'label'    => 'My Plugin License',
            'parent'   => 'plugins.php',
            'priority' => 10,
        ],
        'meta'        => [ 'memory_limit' => ini_get( 'memory_limit' ) ], // optional
    ] );
}
```

Unknown config keys, a missing `api_url`/`file`, a non-callable `meta_callback`, or
`product_uid` without `product_id` trigger `_doing_it_wrong()` in debug mode — construction
always proceeds. `setMeta()`, `setMetaCallback()`, and `setProductUid()` are still available
as chainable setters. The `shazzad-plugin-updater-test` plugin is a working V2 example
(`product_id` 99, license on, custom menu label, `setMetaCallback()` + `setMeta()` chained).

Moving a V2 plugin to V3 is a namespace change — see the V3 commercial quick start above.

## Quick Start (V1, legacy)

```php
<?php
// Initialize the updater (autoloaded via Composer). Guarded with class_exists()
// so a build that's missing the library degrades to "no license/update UI"
// instead of a fatal error on every request.
if ( class_exists( \Shazzad\PluginUpdater\Integration::class ) ) {
    new \Shazzad\PluginUpdater\Integration(
        'https://your-api-server.com/api',  // API URL
        plugin_basename( __FILE__ ),        // Plugin file path
        'your-product-id',                  // Product ID
        true,                              // Enable licensing
        true,                              // Display admin menu
        'My Plugin License',               // Menu label
        'plugins.php',                     // Parent menu
        10                                 // Menu priority
    );
}
```

## File Structure

```
/src/                       # V1 — namespace Shazzad\PluginUpdater — frozen
├── Integration.php         # Core state, license helpers, and subsystem wiring
├── Client.php              # API client with typed methods (ping, check_license, updates, details)
├── Updater.php             # Update checks and WordPress integration
├── Admin.php               # License admin page
├── Tracker.php             # Plugin tracking and license sync
└── V2/                     # V2 — namespace Shazzad\PluginUpdater\V2 — active
    ├── Integration.php     # Config-array entry point and subsystem wiring
    ├── Client.php          # API client (same methods as V1)
    ├── Updater.php         # Update checks and WordPress integration
    ├── Tracker.php         # Plugin tracking and license sync
    ├── License/Store.php   # Option/transient keys, uid-keyed storage, legacy-key migration
    └── Admin/
        ├── LicensePage.php # License admin page
        ├── Notices.php     # Dismissible "enter license" / "license expired" notices
        └── UpdateMessage.php # Explanation line in the plugins-list update row
/src/V3/                    # V3 — namespace Shazzad\PluginUpdater\V3 — active
├── Insights.php            # FREE entry point: consent notice + tracking, nothing else
├── Insights/               # Shared by both entry points (free plugins ship only these + Insights.php)
│   ├── Consent.php         # Consent state (yes/no/unset; always yes in commercial mode) and install token
│   ├── Collector.php       # Builds the payload (site, admin, wp, server, users, plugins, meta, license)
│   ├── Client.php          # track() / optout() against wp-repo/v4 — refuses without consent
│   ├── Scheduler.php       # Daily cron + activate/deactivate/upgrade events
│   └── Notice.php          # Consent notice + Allow / No thanks handler (free only)
├── Integration.php         # COMMERCIAL entry point: V2 config + Insights parts in commercial mode
├── Client.php              # check_license, updates, details (no ping)
├── Updater.php             # Update checks and WordPress integration
├── Tracker.php             # Activation cache refresh and hourly license sync
├── License/Store.php       # Same storage as V2
└── Admin/                  # LicensePage, Notices, UpdateMessage — same as V2
```

## Configuration Options

### V2 Config Keys

| Key             | Type          | Default | Description                                                                                   |
| --------------- | ------------- | ------- | --------------------------------------------------------------------------------------------- |
| `api_url`       | string        | -       | **Required.** Your API server URL                                                             |
| `file`          | string        | -       | **Required.** Plugin main file — `__FILE__` or `plugin_basename( __FILE__ )`                  |
| `product_uid`   | string        | `''`    | Opaque `prod_…` uid on the server. Preferred identity                                         |
| `product_id`    | string        | `''`    | Numeric product id. Legacy identity; required to reach licenses stored under id-based keys    |
| `license`       | bool          | `false` | Enable license verification features                                                          |
| `menu`          | array\|false | `[]`    | License page settings: `parent` (defaults to `plugins.php`), `label`, `priority` (`9999`). `false` hides the page |
| `meta`          | array         | `[]`    | Static ping metadata — same as `setMeta()`                                                    |
| `meta_callback` | callable      | `null`  | Builds ping metadata at ping time — same as `setMetaCallback()`                               |

### V3 Config Keys

`V3\Integration` (commercial) takes exactly the V2 keys above. `api_url` is the `wp-repo/v4`
base (e.g. `https://w4dev.com/wp-json/wp-repo/v4`), used for updates, licensing and Insights;
`meta` / `meta_callback` are now sent with Insights tracks.

`V3\Insights` (free):

| Key             | Type          | Default            | Description                                                                 |
| --------------- | ------------- | ------------------ | --------------------------------------------------------------------------- |
| `api_url`       | string        | -                  | **Required.** Repo API base, e.g. `https://w4dev.com/wp-json/wp-repo/v4` |
| `file`          | string        | -                  | **Required.** `__FILE__` or its `plugin_basename()` form                   |
| `product_uid`   | string        | `''`               | `prod_…` uid. One of `product_uid` / `product_id` is required               |
| `product_id`    | string        | `''`               | Numeric product id                                                          |
| `name`          | string        | plugin header Name | Name shown in the notice                                                    |
| `privacy_url`   | string        | `''`               | "Learn more" link in the notice; omitted when empty                         |
| `notice`        | array\|false | `[]`               | `screens` (screen ids; default every admin screen), `text` (override, `%s` = name), `show_callback` (extra gate), `items` (extra "What we collect" lines, strings); `false` = no notice |
| `meta`          | array         | `[]`               | Static metadata; Closures resolve at send time                              |
| `meta_callback` | callable      | `null`             | Returns a metadata array at send time                                       |

Options per plugin (`{slug}` = plugin directory): `{slug}_insights_consent`,
`{slug}_insights_token`, `{slug}_insights_last_send`, `{slug}_insights_optout_pending` (a failed
opt-out awaiting retry); cron hook `wprepo_insights_track_{slug}`. Deactivation clears the cron
(on every site of the network when network-deactivated) and keeps the options, so a
re-activation does not ask again.

Remove them on uninstall with `\Shazzad\PluginUpdater\V3\Insights::uninstall( $file )` — it
deletes the four options and the cron on every site of a multisite network and sends nothing.
It works for commercial `V3\Integration` plugins too (same keys; the consent option is simply
absent there).

```php
// uninstall.php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
require __DIR__ . '/vendor/autoload.php';
\Shazzad\PluginUpdater\V3\Insights::uninstall( WP_UNINSTALL_PLUGIN );

// …or in the main plugin file (the callback must be a static method or function):
register_uninstall_hook( __FILE__, 'my_plugin_uninstall' );
function my_plugin_uninstall() {
    \Shazzad\PluginUpdater\V3\Insights::uninstall( __FILE__ );
}
```

### V1 Constructor Parameters (legacy)

| Parameter          | Type   | Default | Description                                                      |
| ------------------ | ------ | ------- | ---------------------------------------------------------------- |
| `$api_url`         | string | -       | **Required.** Your API server URL                                |
| `$product_file`    | string | -       | **Required.** Plugin file path (e.g., "my-plugin/my-plugin.php") |
| `$product_id`      | string | -       | **Required.** Unique product identifier                          |
| `$license_enabled` | bool   | `false` | Enable license verification features                             |
| `$display_menu`    | bool   | `true`  | Show license settings in WordPress admin                         |
| `$menu_label`      | string | `''`    | Custom label for admin menu item                                 |
| `$menu_parent`     | string | `''`    | Parent menu slug (defaults to 'plugins.php')                     |
| `$menu_priority`   | int    | `9999`  | Menu display priority                                            |

### Example Configurations

#### Basic Update Checking (No Licensing)

```php
new \Shazzad\PluginUpdater\Integration(
    'https://api.example.com',
    plugin_basename( __FILE__ ),
    'my-plugin-id'
);
```

#### Full Featured with Licensing and Metadata

```php
( new \Shazzad\PluginUpdater\Integration(
    'https://api.example.com',
    plugin_basename( __FILE__ ),
    'my-plugin-id',
    true,                           // Enable licensing
    true,                           // Show admin menu
    'My Plugin Updates',            // Menu label
    'tools.php',                    // Under Tools menu
    20                              // Menu priority
) )->setMeta( [
    'theme' => function () { return get_stylesheet(); },
] );
```

## API Server Requirements

Your API server should provide the following endpoints:

### Update Check Endpoint

```
GET /products/{product_id}/updates
```

**Response:**

```json
{
  "updates": {
    "new_version": "2.1.0",
    "package": "https://download-url.com/plugin.zip",
    "url": "https://plugin-info-url.com",
    "tested": "6.4",
    "requires": "5.0",
    "changelog": "Bug fixes and improvements"
  }
}
```

### Plugin Details Endpoint

```
GET /products/{product_id}/details
```

**Response:**

```json
{
  "details": {
    "name": "My Plugin",
    "version": "2.1.0",
    "author": "Developer Name",
    "homepage": "https://plugin-website.com",
    "sections": {
      "description": "Plugin description",
      "changelog": "Version history",
      "installation": "Installation instructions"
    },
    "download_link": "https://download-url.com/plugin.zip"
  }
}
```

### License Verification Endpoint

```
GET /products/{product_id}/check_license?license=LICENSE_KEY
```

**Response:**

```json
{
  "license": {
    "status": "active",
    "expires": "2024-12-31",
    "customer_name": "John Doe",
    "customer_email": "john@example.com",
    "renewal_url": "https://example.com/renew?license={license_code}&email={email}"
  }
}
```

The `renewal_url` field is optional in the license verification response. When present and the license status is `expired`, a renewal link is displayed on the admin license page. The URL supports two placeholders that are replaced automatically:

- `{license_code}` — replaced with the stored license key
- `{email}` — replaced with the `buyer_email` from the license data

A static URL without placeholders (e.g., `https://example.com/renew`) is also supported.

### Insights Endpoints (V3)

```
POST /wp-json/wp-repo/v4/products/{product_uid_or_id}/track
POST /wp-json/wp-repo/v4/products/{product_uid_or_id}/optout
```

`track` takes the JSON payload described in the V3 quick start (`event`, `mode`, `token`,
`product_version`, `product_status`, `site`, `admin`, `wp`, `server`, `users`, `plugins`,
`license` for commercial installs, `meta`) and answers 202. `optout` takes
`{ site_url, token }` and deletes the install's data when the token matches. Both are served by
`shazzad/plugin-repo` 2.8.0+ (not available on `wp-repo/v3`).

### Ping Endpoint (V1/V2)

```
POST /products/{product_id}/ping
```

Used by V1 and V2 for tracking plugin installations and status (V3 uses the Insights endpoints above). Sends site environment data and optional custom metadata.

**Request body:**

- `product_version`: Current plugin version
- `product_status`: Plugin status (active/inactive)
- `wp_url`: WordPress site URL
- `wp_locale`: WordPress locale
- `wp_version`: WordPress version
- `admin_email`: Site admin email
- `admin_name`: First admin user's display name
- `php_version`: PHP version of the server
- `db_version`: Database server version (e.g. `8.0.36` or `10.11.6-MariaDB`)
- `server_software`: Web server software (e.g. `nginx/1.24.0`, `Apache/2.4.58 (Ubuntu)`)
- `license`: The stored license key, when licensing is enabled and a key is saved (lets the server bind the install to its license)
- `meta`: Optional key-value pairs of custom metadata

## Custom Metadata

You can attach custom metadata to pings using `setMeta()`. Values can be static or closures — closures are resolved at ping time so data is always fresh.

```php
( new \Shazzad\PluginUpdater\Integration(
    'https://api.example.com',
    plugin_basename( __FILE__ ),
    'my-plugin-id'
) )->setMeta( [
    'theme'                => function () { return get_stylesheet(); },
    'memory_limit'         => ini_get( 'memory_limit' ),
    'active_plugins_count' => function () {
        return count( get_option( 'active_plugins' ) );
    },
] );
```

Alternatively, `setMetaCallback()` accepts a single closure that builds the whole metadata array at once — it runs fresh at every ping. Both methods are chainable and can be combined:

```php
( new \Shazzad\PluginUpdater\Integration(
    'https://api.example.com',
    plugin_basename( __FILE__ ),
    'my-plugin-id'
) )->setMeta( [
    'environment' => 'production',
    'channel'     => 'direct',
] )->setMetaCallback( function () {
    return [
        'memory_limit' => ini_get( 'memory_limit' ),
        'theme'        => get_stylesheet(),
        'plugin_count' => count( get_option( 'active_plugins', [] ) ),
    ];
} );
```

- **Static values** (strings, numbers) are sent as-is
- **Closures** are called at each ping and the return value is sent. In V1 only `Closure` instances are resolved (for `setMetaCallback()` too). In V2 and V3 the callback may be any callable, and `meta` values that are Closures or array-callables are resolved — plain strings always stay data even when they happen to name a function
- When both are used, the `setMetaCallback()` array is built first and `setMeta()` entries are merged over it — on a key conflict, `setMeta()` wins
- Metadata is synced on every ping — keys removed from `setMeta()` are deleted from the server
- The site admin name and email are always sent automatically as top-level ping fields (`admin_name`, `admin_email`) — no metadata entries needed for those (in V3 they are in the Insights payload's `admin` block)
- In V3 metadata goes out with each Insights track (daily and on activate/deactivate/upgrade) instead of the hourly ping
- The server environment is also reported automatically as top-level ping fields (`php_version`, `db_version`, `server_software`) — do not duplicate these in metadata

## Product uid

In V2 the uid is simply the `product_uid` config key (see above). In V1, multiple plugins may bundle this library as a dependency, and the oldest loaded copy wins the `class_exists()` race — the `setProductUid()` method may not exist in the loaded class. Use a guard to detect it, then call it to set the opaque product uid (format: `prod_…`). When set, API requests address the product by uid instead of the enumerable numeric id, and licenses are stored under uid-based option keys; when unset, numeric `product_id` behavior is unchanged. On first call (or on V2 construction with a `product_uid`), existing id-based licenses are automatically cloned to uid-based keys; old copies are retained for backward compatibility until a future prune release (tracked as [issue #24](https://github.com/shazzad/plugin-updater/issues/24); it will ship in V2, never in the frozen V1 namespace).

```php
$integration = new \Shazzad\PluginUpdater\Integration( $api_url, $basename, 6, true );

if ( method_exists( $integration, 'setProductUid' ) ) {
	$integration->setProductUid( 'prod_xxxxxxxxxxxxxxxxxxxx' );
}
```

## Request Parameters

API requests to `updates`, `details`, and `check_license` include these query parameters:

- `license`: License key (if licensing enabled)

## WordPress Integration

### Hooks and Filters

The updater integrates with WordPress using these hooks:

- `pre_set_site_transient_update_plugins`: Inject update information
- `plugins_api`: Provide plugin details for update screen
- `upgrader_package_options`: Configure upgrade process
- `upgrader_process_complete`: Handle post-update cleanup
- `load-update-core.php`: Clear the cached API responses so "Check again" fetches fresh data
- Plugin activation/deactivation hooks for tracking
- V2 only: `admin_notices` / `admin_init` (license notices and their snooze) and `in_plugin_update_message-{file}` (update-row explanation)

### Scheduled Tasks

- **License Sync**: Hourly cron job to verify license status (V1/V2 also ping here; V3 does not)
- **Insights (V3)**: Daily `wprepo_insights_track_{slug}` cron, re-created on `admin_init` if lost (commercial: also by the hourly license sync, which sends the daily track when it is due)
- **Update Checks**: Integrated with WordPress core update system

## Admin Interface

When licensing is enabled, the updater adds an admin page with:

- License key input field
- License status display
- Update availability notifications
- Direct upgrade buttons
- Changelog and upgrade notices

V2 additionally shows, to users with the `update_plugins` capability, a dismissible admin notice when no license key is saved or the license has expired (linking `renewal_url`), snoozable for one week per product and notice type, plus an explanation line inside the plugin's update row on the Plugins screen when the update package is withheld.

### Menu Placement

By default, the license page appears under the **Plugins** menu; an empty parent falls back
to `plugins.php`. The page is always a submenu (`add_submenu_page()`) — there is no top-level
option. To customise the parent:

```php
// V2
'menu' => [ 'parent' => 'tools.php' ]            // Under Tools
'menu' => [ 'parent' => 'options-general.php' ]  // Under Settings
'menu' => false                                  // No license page at all

// V1: pass the parent slug as the 7th constructor argument ($menu_parent)
```

## Security Features

- **Input Sanitization**: All user inputs are properly sanitized
- **Nonce Verification**: WordPress nonces protect admin forms
- **Capability Checks**: Requires `delete_users` capability for license management
- **XSS Protection**: Output is escaped using WordPress functions

## Error Handling

The updater includes comprehensive error handling:

- API connection failures
- Invalid license keys
- Update server timeouts
- Malformed responses

Errors are returned as `WP_Error` from the `Client` methods and surfaced on the license admin page; the library does not write to the PHP error log.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for the full release history.

## Support

For support and bug reports, please contact your plugin developer or visit the plugin's official support channels.

## License

This updater package is typically licensed under the same terms as your main plugin. Check your plugin's license file for specific terms.
