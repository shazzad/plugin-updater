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

1. Copy the plugin updater files to your plugin directory
2. Include the Integration class in your main plugin file
3. Initialize the updater with your configuration

## Quick Start

```php
<?php
// Include the Integration class
require_once plugin_dir_path( __FILE__ ) . 'updater/Integration.php';

// Initialize the updater
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
```

## File Structure

```
/updater/
├── Integration.php    # Core functionality and API handling
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

#### Full Featured with Licensing

```php
new \Shazzad\PluginUpdater\Integration(
    'https://api.example.com',
    plugin_basename( __FILE__ ),
    'my-plugin-id',
    true,                           // Enable licensing
    true,                           // Show admin menu
    'My Plugin Updates',            // Menu label
    'tools.php',                    // Under Tools menu
    20                             // Menu priority
);
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
    "customer_email": "john@example.com"
  }
}
```

### Ping Endpoint

```
GET /products/{product_id}/ping
```

Used for tracking plugin installations and status.

## Request Parameters

All API requests include these parameters:

- `product_version`: Current plugin version
- `product_status`: Plugin status (active/inactive)
- `wp_url`: WordPress site URL
- `wp_locale`: WordPress locale
- `wp_version`: WordPress version
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

## Debugging

Enable debugging with the included helper method:

```php
$integration = new \Shazzad\PluginUpdater\Integration(/* ... */);
$integration->p($some_data); // Pretty print data
```

## Changelog

### Version 1.0

- Refactored into modular structure
- Improved error handling
- Enhanced security measures
- Better WordPress integration
- Comprehensive documentation

## Support

For support and bug reports, please contact your plugin developer or visit the plugin's official support channels.

## License

This updater package is typically licensed under the same terms as your main plugin. Check your plugin's license file for specific terms.
