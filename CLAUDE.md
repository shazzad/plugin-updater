# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

WordPress plugin updater library (`shazzad/plugin-updater`) that enables automatic updates, license verification, and remote plugin management for custom WordPress plugins. It hooks into WordPress core's update system to check a remote API for updates.

## Commands

```bash
# Run tests
composer test

# Lint (WordPress coding standards + PHP compatibility)
composer lint

# Lint with WordPress coding standards only
composer phpcs

# Auto-fix coding standard violations
composer fix
```

Tests use PHPUnit 9 with Brain Monkey for WordPress function mocking. Fixture-based JSON files in `tests/fixtures/` represent API response shapes.

## Architecture

**Entry point:** `Integration` is the main class — consumer plugins instantiate it with API URL, product file, product ID, and optional license/menu config. The constructor creates and stores all subsystem instances as public properties (`$client`, `$updater`, `$tracker`, `$admin`):

- **`Integration`** (`src/Integration.php`) — Holds all shared state (API URL, product info, license config) and license/transient management helpers. Stores subsystem instances so classes can reach each other.
- **`Client`** (`src/Client.php`) — Handles all HTTP communication with the remote API. Public methods: `ping()`, `check_license($license)`, `updates()`, `details()`. Private `request()` method contains shared HTTP/response logic.
- **`Updater`** (`src/Updater.php`) — Hooks into WordPress update system (`pre_set_site_transient_update_plugins`, `plugins_api`, `upgrader_package_options`, `upgrader_process_complete`) to inject update data from the remote API.
- **`Admin`** (`src/Admin.php`) — Renders the license management admin page. Only instantiated when `license_enabled` and `display_menu` are both true. Handles license save/verify via POST with nonce verification.
- **`Tracker`** (`src/Tracker.php`) — Handles plugin activation/deactivation hooks and hourly cron license sync via `sync_license_data()`.

All classes receive the `Integration` instance and use its public properties directly (no getters/setters pattern). API calls go through `$integration->client->method()`.

## Code Conventions

- **WordPress coding standards** enforced via PHPCS (`WordPress` standard)
- PHP 7.4+ with WordPress `ABSPATH` guard and `class_exists()` guard wrapping each class
- Namespace: `Shazzad\PluginUpdater` with PSR-4 autoloading from `src/`
- Uses tabs for indentation (WordPress standard)
- All API calls go through `Client` methods (`ping()`, `check_license()`, `updates()`, `details()`) — each returns associative array on success or `WP_Error` on failure
- WordPress capability required for admin: `delete_users`
