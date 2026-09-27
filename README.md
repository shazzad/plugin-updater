# WordPress Plugin Updater Package

A comprehensive WordPress plugin updater library that enables automatic updates, license verification, and remote plugin management for custom WordPress plugins.

## Features

- **Automatic Plugin Updates**: Seamlessly check for and install plugin updates from your remote server
- **License Management**: Built-in license key verification and validation system
- **Admin Interface**: Clean WordPress admin interface for license management
- **Plugin Tracking**: Track plugin activation, deactivation, and usage statistics
- **Insights (V4)**: Appsero-style usage data — opt-in with a consent notice for free wordpress.org plugins, implied for commercial plugins
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

- **`Shazzad\PluginUpdater\V4`** (`src/V4/`) — active since 4.0.0. **New consumers should use
  this.** Two entry points:
  - **`V4\Insights`** — for **free wordpress.org plugins**: usage tracking only, after an
    explicit opt-in through an Appsero-style consent notice. No update or license code at all;
    ship only the Insights files (see [Shipping a free wp.org plugin](docs/v4.md#shipping-a-free-wporg-plugin)).
  - **`V4\Integration`** — for **commercial plugins**: everything V2 does (updates, license page,
    notices, update-row message), with install tracking moved from the old `/ping` to Insights
    (consent implied, license key in the payload). Same option keys, transients and cron hook
    as V2/V1, so a plugin moving to V4 keeps every saved license.

  Both talk to the repo server's **`wp-repo/v4`** API — one `api_url`
  (`https://w4dev.com/wp-json/wp-repo/v4`) serves updates, licensing and Insights, each plugin
  addressed as `{api_url}/plugins/{uid}/…`. Both require **`product_uid`** (the `prod_…` uid):
  v4 accepts no numeric id. `wp-repo/v3` has no `plugins/{uid}` routes, so V4 does nothing
  against it; it stays, frozen, for V1/V2 clients. Requires `composer require shazzad/plugin-updater:^4.0` and `shazzad/plugin-repo`
  2.9.0+ on the server (the `plugins/{uid}` routes).
- **`Shazzad\PluginUpdater\V2`** (`src/V2/`) — stable: additive fixes only. Config-array
  constructor, license admin notices, and an explanation line in the plugins-list update row.
- **`Shazzad\PluginUpdater`** (`src/`) — V1, frozen: critical fixes only. Existing plugins keep
  working unchanged and opt in to a newer major deliberately by bumping the constraint and
  switching the namespace.

The majors never share classes, so plugins on different library versions coexist on one site
without the first-loader-wins fatal V1 was exposed to.

**The namespace number matches the server API it calls:** `V4` talks to `wp-repo/v4`, and a
future `V5` would talk to `wp-repo/v5`. The one exception is legacy: V1 and `V2` both call
`wp-repo/v3` (V3 was never released under that name — it became V4 before shipping).

| Namespace | Status | Server API | Guide |
| --------- | ------ | ---------- | ----- |
| `Shazzad\PluginUpdater\V4` | Active | `wp-repo/v4` (`plugins/{uid}`) | [docs/v4.md](docs/v4.md) |
| `Shazzad\PluginUpdater\V2` | Stable, additive fixes only | `wp-repo/v3` (`products/{id}`, legacy exception) | [docs/v2.md](docs/v2.md) |
| `Shazzad\PluginUpdater` (V1) | Frozen, critical fixes only | `wp-repo/v3` (`products/{id}`) | [docs/v1-legacy.md](docs/v1-legacy.md) |

## Minimal example (V4, commercial plugin)

```php
<?php
if ( class_exists( \Shazzad\PluginUpdater\V4\Integration::class ) ) {
    new \Shazzad\PluginUpdater\V4\Integration( [
        'api_url'     => 'https://w4dev.com/wp-json/wp-repo/v4',
        'file'        => __FILE__,
        'product_uid' => 'prod_xxxxxxxxxxxxxxxxxxxx',
        'license'     => true,
    ] );
}
```

The free-plugin (`V4\Insights`) quick start, every config key, and what happens without a
`product_uid` or with a `wp-repo/v3` `api_url` are in the [V4 guide](docs/v4.md).

## Documentation

- **[V4 guide](docs/v4.md)** — free (`V4\Insights`) and commercial (`V4\Integration`) quick
  starts, moving from V2, config keys, Insights storage/backoff/uninstall, the `wp-repo/v4`
  `plugins/{uid}` and Insights endpoints, hooks, and the free wp.org build strip recipe.
- **[V2 guide](docs/v2.md)** — quick start, config keys, product uid, the `wp-repo/v3`
  endpoints including ping, hooks, license notices and update-row message.
- **[V1 guide (legacy)](docs/v1-legacy.md)** — positional constructor, example
  configurations, `setProductUid()` guard and license-key migration, `setMeta()` /
  `setMetaCallback()` examples.
- [CHANGELOG.md](CHANGELOG.md) — release history.

The rest of this page applies to every version.

## Server responses

Your API server should provide `updates`, `details` and `check_license` endpoints (plus
`ping` for V1/V2 or the Insights `track` / `optout` routes for V4). They return the same bodies on
`wp-repo/v3` (V1, V2: `/products/{product_id}/…`) and `wp-repo/v4` (V4: `/plugins/{uid}/…`).
Each version's paths are in its guide; the full v4 contract is `docs/v4-api.md` in
`shazzad/plugin-repo`.

### Update check

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

### Plugin details

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

### License verification

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

## Request parameters

API requests to `updates`, `details`, and `check_license` include these query parameters:

- `license`: License key (if licensing enabled)

## Custom metadata

Every version accepts plugin metadata through `setMeta()` (static values or closures) and
`setMetaCallback()` (one callback that builds the whole array); V2 and V4 also take them as
the `meta` / `meta_callback` config keys. V1 and V2 send it with each ping, V4 with each
Insights track.

- **Static values** (strings, numbers) are sent as-is
- **Closures** are called at each send and the return value is sent, so data is always fresh.
  Which callables resolve differs per version — see the [V1](docs/v1-legacy.md#custom-metadata),
  [V2](docs/v2.md#custom-metadata) and [V4](docs/v4.md#custom-metadata) guides
- When both are used, the `setMetaCallback()` array is built first and `setMeta()` entries are merged over it — on a key conflict, `setMeta()` wins

## WordPress update hooks

The `Updater` of V1, V2 and commercial V4 integrates with the WordPress core update system
using these hooks:

- `pre_set_site_transient_update_plugins`: Inject update information
- `plugins_api`: Provide plugin details for update screen
- `upgrader_package_options`: Configure upgrade process
- `upgrader_process_complete`: Handle post-update cleanup
- `load-update-core.php`: Clear the cached API responses so "Check again" fetches fresh data

Activation/deactivation hooks, admin notices and scheduled tasks differ per version; each
guide lists its own.

## License admin page

When licensing is enabled, the updater adds an admin page with:

- License key input field
- License status display
- Update availability notifications
- Direct upgrade buttons
- Changelog and upgrade notices

By default, the license page appears under the **Plugins** menu; an empty parent falls back
to `plugins.php`. The page is always a submenu (`add_submenu_page()`) — there is no top-level
option. To customise the parent, use the `menu` key in [V2](docs/v2.md#admin-interface) and
[V4](docs/v4.md#admin-interface), or the `$menu_parent` constructor argument in
[V1](docs/v1-legacy.md#admin-interface).

## Security Features

- **Input Sanitization**: All user inputs are properly sanitized
- **Nonce Verification**: WordPress nonces protect admin forms
- **Capability Checks**: Requires `delete_users` capability for license management (the V4
  Insights consent notice requires `manage_options`)
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
