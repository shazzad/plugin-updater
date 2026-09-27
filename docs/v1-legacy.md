# V1 guide (legacy) — `Shazzad\PluginUpdater`

Frozen: critical fixes only. Existing plugins keep working unchanged and opt in to a newer
major deliberately by bumping the constraint and switching the namespace — to [V4](v4.md) for
new work. V1 calls the frozen **`wp-repo/v3`** API, the same routes as [V2](v2.md#api-endpoints).

Back to the [README](../README.md) for the version overview and the parts every version
shares.

## Contents

- [Quick start](#quick-start)
- [Constructor parameters](#constructor-parameters)
- [Example configurations](#example-configurations)
- [Product uid](#product-uid)
- [API endpoints](#api-endpoints)
- [Custom metadata](#custom-metadata)
- [Hooks and scheduled tasks](#hooks-and-scheduled-tasks)
- [Admin interface](#admin-interface)
- [File structure](#file-structure)

## Quick start

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

## Constructor parameters

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

## Example configurations

### Basic update checking (no licensing)

```php
new \Shazzad\PluginUpdater\Integration(
    'https://api.example.com',
    plugin_basename( __FILE__ ),
    'my-plugin-id'
);
```

### Full featured with licensing and metadata

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

## Product uid

In V1, multiple plugins may bundle this library as a dependency, and the oldest loaded copy wins the `class_exists()` race — the `setProductUid()` method may not exist in the loaded class. Use a guard to detect it, then call it to set the opaque product uid (format: `prod_…`). When set, API requests address the product by uid instead of the enumerable numeric id, and licenses are stored under uid-based option keys; when unset, numeric `product_id` behavior is unchanged. On first call (or on V2 construction with a `product_uid`), existing id-based licenses are automatically cloned to uid-based keys; old copies are retained for backward compatibility until a future prune release (tracked as [issue #24](https://github.com/shazzad/plugin-updater/issues/24); it will ship in V2, never in the frozen V1 namespace).

```php
$integration = new \Shazzad\PluginUpdater\Integration( $api_url, $basename, 6, true );

if ( method_exists( $integration, 'setProductUid' ) ) {
	$integration->setProductUid( 'prod_xxxxxxxxxxxxxxxxxxxx' );
}
```

## API endpoints

V1 calls the same `wp-repo/v3` routes as V2 — `updates`, `details`, `check_license` and
`ping` under `/products/{product_id}/` — with the same ping body; see
[V2 API endpoints](v2.md#api-endpoints).

## Custom metadata

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

In V1 only `Closure` instances are resolved (for `setMetaCallback()` too). The merge rules
shared by every version are in [Custom metadata](../README.md#custom-metadata); the ping
specifics (sync on every ping, automatic admin and server fields) are in the
[V2 guide](v2.md#custom-metadata) and apply to V1 unchanged.

## Hooks and scheduled tasks

- The [shared update hooks](../README.md#wordpress-update-hooks)
- Plugin activation/deactivation hooks for tracking
- **License sync**: hourly cron `wprepo_sync_license_data_{license_name}` verifies the license
  status and pings

V1 has no license notices and no update-row message.

## Admin interface

The license page ([shared by every version](../README.md#license-admin-page)) is added only when
`$license_enabled` and `$display_menu` are both true. To choose its parent menu, pass the parent
slug as the 7th constructor argument (`$menu_parent`).

## File structure

```
/src/                       # V1 — namespace Shazzad\PluginUpdater — frozen
├── Integration.php         # Core state, license helpers, and subsystem wiring
├── Client.php              # API client with typed methods (ping, check_license, updates, details)
├── Updater.php             # Update checks and WordPress integration
├── Admin.php               # License admin page
└── Tracker.php             # Plugin tracking and license sync
```

`src/` also holds the [V2](v2.md#file-structure) (`src/V2/`) and [V4](v4.md#file-structure)
(`src/V4/`) trees.
