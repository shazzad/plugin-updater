# Product UID Migration — Design

**Date:** 2026-08-08
**Status:** Approved
**Scope:** `shazzad/plugin-updater` library only (release 1.5.0 now; 1.6.0 prune documented
as follow-up). Consumer plugin rollouts are separate, mechanical releases.

## Problem

Consumer plugins identify themselves to the repo server by numeric `product_id`, which is
guessable/enumerable in public URLs (`products/6/download`). The server has supported the
opaque product uid (`prod_` + 20 base36 chars) on every public endpoint since the uid
dual-key work — id and uid are interchangeable in the URL. The updater library should move
to uid, but license data is stored in options keyed by the numeric id, and the library is
**bundled per plugin with `class_exists` guards, so the oldest loaded copy wins on any
site running several consumer plugins**. Nothing may break when old and new copies mix.

## Decisions

- **Option C — keep `$product_id` in place, add the uid as a new value.** The numeric id
  stays in constructor position 3 and doubles as the migration source; the uid arrives via
  a new fluent setter. No signature or behavior change unless the uid is set.
- **Two-phase storage migration.** 1.5.0 clones id-based options to uid-based keys and
  keeps both copies. 1.6.0 (a later release) prunes the id-based copies. 1.6.0 ships only
  after every consumer has had a uid-carrying release out for a while.
- **Consumers guard the new call** with `method_exists()` so a plugin built against 1.5.0
  survives an older bundled copy winning the `class_exists` race.

## Library changes (1.5.0)

### New API

```php
/**
 * Sets the opaque product uid used for API URLs and storage keys.
 *
 * @since 1.5.0
 *
 * @param string $product_uid The `prod_…` uid from the repo server.
 * @return $this
 */
public function setProductUid( $product_uid );
```

Fluent, matching `setMeta()` / `setMetaCallback()`. Stores `$this->product_uid` (public
property, default `''`). A 9th constructor arg was rejected: consumers would have to pad
the four menu args.

### Identifier resolution

- New helper `get_api_product_key()`: returns `product_uid` when non-empty, else
  `product_id`. `Client` uses it everywhere it currently interpolates
  `$integration->product_id` into URLs (`ping`, `check_license`, `updates`, `details`,
  and the download/package URL paths built from them).
- New helper `get_storage_name()`: returns `sanitize_key( "{product_slug}{product_uid}" )`
  when uid set, else the existing `sanitize_key( "{product_slug}{product_id}" )`. The
  `license_name` property keeps its current id-based value for backward compatibility;
  all key helpers (`get_license_code_key()`, `get_license_data_key()`,
  `get_updates_cache_key()`, `get_details_cache_key()`) switch to building from
  `get_storage_name()`.

### Migration clone (runs on load, self-limiting)

When ALL of: uid set · `license_enabled` · uid-based `_code` option `=== false` ·
id-based `_code` option `!== false`:

1. Copy id-based `_code` value to the uid-based `_code` key.
2. If the id-based `_data` option exists, copy it to the uid-based `_data` key.
3. Old copies are left in place (pruned by 1.6.0).

No marker option: after the clone the uid-based key exists, so later loads short-circuit
on one (autoloaded) lookup. Sites with no stored license do two cheap lookups per load.

### Delete cascade

`delete_license_code()` and `delete_license_data()` also delete the id-based copies when
a uid is set. Without this, deactivating a license deletes the uid-based copy and the
next page load resurrects it from the id-based one.

### Not migrated

`_updates_cache` / `_details_cache` site transients — they expire and regenerate under
the uid-based keys. Stale id-based transients age out on their own.

### Compatibility guarantees

- With no uid set, 1.5.0 behaves byte-for-byte like 1.4.x: id-based URLs, id-based keys,
  no clone, no cascade.
- A 1.5.0 copy loaded by one plugin serves 1.4.x-era consumer code identically (additive
  API only).
- A consumer that calls `setProductUid()` on a site where an older copy won the race
  skips the call via its `method_exists()` guard and keeps running id-based — licenses
  intact, updates working — until the older plugin updates its bundled copy.

## Consumer rollout (per plugin, separate releases)

```php
$integration = new Integration( $api_url, $basename, 6, true );

if ( method_exists( $integration, 'setProductUid' ) ) {
	$integration->setProductUid( 'prod_xxxxxxxxxxxxxxxxxxxx' );
}
```

One library bump + these lines per plugin (LoxoWP, Soccer Engine, WP Pro Admin, PimiPay,
PimiDocs, updater-test). The numeric id stays in place — it powers the clone now and the
prune later.

## Prune release (1.6.0 — follow-up, not in this spec's implementation)

When uid set and uid-based `_code` exists: delete id-based `_code` and `_data`.
Deterministic (the id is still in the constructor). Ship only after all consumers have
carried uid for a while; the last-updated plugin on any site is the one that migrates
last. Consumers may drop `setProductUid` guards/ids whenever convenient afterward —
leaving them is harmless.

## Error handling

No new error paths: the setter validates nothing (an invalid uid simply 404s at the API
exactly as a wrong id does today, surfacing through existing `WP_Error` returns in
`Client`). The clone uses plain `get_option`/`update_option`; a failed write means the
clone re-runs next load.

## Testing

PHPUnit + Brain Monkey (`composer test`), following the existing fixture-based test
style:

1. `setProductUid()` returns `$this` and switches `get_api_product_key()` /
   `get_storage_name()`; without uid both return id-based values.
2. Client URLs use the uid when set (extend `ClientApiRequestTest`).
3. Clone: runs when uid set + new key missing + old present; skips when new key exists;
   skips when `license_enabled` is false; copies `_data` only when it exists.
4. Delete cascade removes both copies when uid set, only the single copy when not.
5. Existing suite stays green untouched — the backward-compat guarantee in test form.

Manual smoke: `shazzad-plugin-updater-test` on the local w4dev docker site — set a
license under id keys, add the uid, reload, verify both option copies and working
update check via uid URLs.
