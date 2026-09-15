# WordPress Plugin Updater Package

A comprehensive WordPress plugin updater library that enables automatic updates, license verification, and remote plugin management for custom WordPress plugins.

## Features

- **Automatic Plugin Updates**: Seamlessly check for and install plugin updates from your remote server
- **License Management**: Built-in license key verification and validation system
- **Admin Interface**: Clean WordPress admin interface for license management
- **Plugin Tracking**: Track plugin activation, deactivation, and usage statistics
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

Since 2.0.0 (2026-08-19) the library ships two namespaces side by side:

- **`Shazzad\PluginUpdater\V2`** (`src/V2/`) — active. **New consumers should use this.**
  Config-array constructor, license admin notices, and an explanation line in the plugins-list
  update row. Requires `composer require shazzad/plugin-updater:^2.0`.
- **`Shazzad\PluginUpdater`** (`src/`) — V1, frozen: critical fixes only. Existing plugins keep
  working unchanged and opt in to V2 deliberately by bumping to `^2.0` and switching the
  namespace. V2 uses the same option keys, transients, and cron hooks as V1, so a plugin
  moving V1→V2 keeps every saved license.

The two majors never share classes, so plugins on different library versions coexist on one
site without the first-loader-wins fatal V1 was exposed to.

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

### Ping Endpoint

```
POST /products/{product_id}/ping
```

Used for tracking plugin installations and status. Sends site environment data and optional custom metadata.

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
- **Closures** are called at each ping and the return value is sent. In V1 only `Closure` instances are resolved (for `setMetaCallback()` too). In V2 the callback may be any callable, and `meta` values that are Closures or array-callables are resolved — plain strings always stay data even when they happen to name a function
- When both are used, the `setMetaCallback()` array is built first and `setMeta()` entries are merged over it — on a key conflict, `setMeta()` wins
- Metadata is synced on every ping — keys removed from `setMeta()` are deleted from the server
- The site admin name and email are always sent automatically as top-level ping fields (`admin_name`, `admin_email`) — no metadata entries needed for those
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

- **License Sync**: Hourly cron job to verify license status
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
