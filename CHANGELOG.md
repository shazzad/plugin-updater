# Changelog

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
