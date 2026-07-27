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

## Quick Start

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
/src/
├── Integration.php    # Core state, license helpers, and subsystem wiring
├── Client.php        # API client with typed methods (ping, check_license, updates, details)
├── Updater.php       # Update checks and WordPress integration
├── Admin.php         # WordPress admin interface
└── Tracker.php       # Plugin tracking and license sync
```

## Configuration Options

### Constructor Parameters

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
- **Closures** are called at each ping and the return value is sent (only `Closure` instances, not arbitrary callable strings — this applies to `setMetaCallback()` too)
- When both are used, the `setMetaCallback()` array is built first and `setMeta()` entries are merged over it — on a key conflict, `setMeta()` wins
- Metadata is synced on every ping — keys removed from `setMeta()` are deleted from the server
- The site admin name and email are always sent automatically as top-level ping fields (`admin_name`, `admin_email`) — no metadata entries needed for those
- The server environment is also reported automatically as top-level ping fields (`php_version`, `db_version`, `server_software`) — do not duplicate these in metadata

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
- Plugin activation/deactivation hooks for tracking

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

### Menu Placement

By default, the license page appears under **Plugins** menu. You can customize this:

```php
// Under Tools menu
'menu_parent' => 'tools.php'

// Under Settings menu
'menu_parent' => 'options-general.php'

// Top-level menu
'menu_parent' => null
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

Errors are logged and displayed appropriately in the WordPress admin.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for the full release history.

## Support

For support and bug reports, please contact your plugin developer or visit the plugin's official support channels.

## License

This updater package is typically licensed under the same terms as your main plugin. Check your plugin's license file for specific terms.
