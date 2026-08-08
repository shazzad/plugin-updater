# Product UID Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let consumer plugins identify themselves by opaque product uid (`prod_…`) instead of numeric id, with a self-limiting clone of id-keyed license options to uid-keyed ones.

**Architecture:** All changes live in the `shazzad/plugin-updater` library (release 1.5.0). `$product_id` stays in constructor position 3; the uid arrives via a new fluent `setProductUid()`. Two resolution helpers (`get_api_product_key()`, `get_storage_name()`) pick uid over id everywhere URLs and option keys are built. The clone runs inside `setProductUid()` and short-circuits once the uid-keyed option exists; delete methods cascade to the legacy keys so a removed license cannot resurrect. The 1.6.0 prune and consumer rollouts are follow-ups, NOT in this plan.

**Tech Stack:** PHP 7.4+, WordPress coding standards, PHPUnit 9 + Brain Monkey (`composer test`), PHPCS (`composer lint`).

**Spec:** `docs/superpowers/specs/2026-08-08-product-uid-migration-design.md`

## Global Constraints

- Work in the nested git repo `/home/shazzad/personal-assistant/w4dev-project/shazzad-plugin-updater` (own upstream `shazzad/plugin-updater`). All paths below are relative to it. Create branch `feature/product-uid` from `main` before Task 1; commit there.
- **Strictly additive API** — no signature changes, no renamed properties, no behavior change unless the uid is set. The existing test suite must stay green untouched; it is the backward-compat guarantee in test form.
- The `license_name` property keeps its id-based value (BC); only the four key-helper methods switch to `get_storage_name()`.
- New docblocks use `@since 1.5` (codebase style is major.minor — see `@since 1.4` in Integration.php). No version file exists to bump; the library versions by git tag at release time.
- WPCS style: tabs, snake_case for methods (except the existing fluent `setX()` camelCase pattern, which `setProductUid` follows), short arrays `[]`, spaces inside parens, single quotes.
- TDD per task: write tests, see them fail, implement, see the whole suite pass (`composer test`), commit.
- `composer install` first if `vendor/` is missing. Run `composer lint` before the final commit of each task; fix violations in the code you added (do not reformat untouched code).

---

### Task 1: `setProductUid()` and uid-aware key resolution

**Files:**
- Modify: `src/Integration.php`
- Test: `tests/IntegrationProductUidTest.php` (new)

**Interfaces:**
- Consumes: existing `Integration` constructor (TestCase's `create_integration()` builds it with `product_file 'my-plugin/my-plugin.php'`, `product_id '42'` → `license_name 'my-plugin42'`).
- Produces: `setProductUid( string $product_uid ): $this` (no migration call yet — Task 3 adds it); `get_api_product_key(): string` (uid if set, else id — Task 2 uses it in Client URLs); `get_storage_name(): string` (uid-based `sanitize_key("{$product_slug}{$product_uid}")` if set, else `license_name`); public property `$product_uid = ''`; the four key helpers (`get_license_code_key`, `get_license_data_key`, `get_updates_cache_key`, `get_details_cache_key`) now build from `get_storage_name()`.

- [ ] **Step 1: Write the failing tests**

Create `tests/IntegrationProductUidTest.php`:

```php
<?php
namespace Shazzad\PluginUpdater\Tests;

class IntegrationProductUidTest extends TestCase {

	/** @test */
	public function set_product_uid_is_fluent() {
		$integration = $this->create_integration();

		$this->assertSame( $integration, $integration->setProductUid( 'prod_testuid' ) );
	}

	/** @test */
	public function api_product_key_is_id_without_uid() {
		$integration = $this->create_integration();

		$this->assertSame( '42', $integration->get_api_product_key() );
	}

	/** @test */
	public function api_product_key_is_uid_when_set() {
		$integration = $this->create_integration();
		$integration->setProductUid( 'prod_testuid' );

		$this->assertSame( 'prod_testuid', $integration->get_api_product_key() );
	}

	/** @test */
	public function storage_keys_stay_id_based_without_uid() {
		$integration = $this->create_integration();

		$this->assertSame( 'my-plugin42_code', $integration->get_license_code_key() );
		$this->assertSame( 'my-plugin42_data', $integration->get_license_data_key() );
		$this->assertSame( 'my-plugin42_updates_cache', $integration->get_updates_cache_key() );
		$this->assertSame( 'my-plugin42_details_cache', $integration->get_details_cache_key() );
	}

	/** @test */
	public function storage_keys_become_uid_based_when_uid_set() {
		$integration = $this->create_integration();
		$integration->setProductUid( 'prod_testuid' );

		$this->assertSame( 'my-pluginprod_testuid_code', $integration->get_license_code_key() );
		$this->assertSame( 'my-pluginprod_testuid_data', $integration->get_license_data_key() );
		$this->assertSame( 'my-pluginprod_testuid_updates_cache', $integration->get_updates_cache_key() );
		$this->assertSame( 'my-pluginprod_testuid_details_cache', $integration->get_details_cache_key() );
	}

	/** @test */
	public function license_name_property_stays_id_based_for_bc() {
		$integration = $this->create_integration();
		$integration->setProductUid( 'prod_testuid' );

		$this->assertSame( 'my-plugin42', $integration->license_name );
	}
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- --filter IntegrationProductUidTest`
Expected: FAIL — `Call to undefined method ...::setProductUid()` (and the two no-uid tests may pass; that's fine).

- [ ] **Step 3: Implement in `src/Integration.php`**

3a. Add the property directly after the `$product_id` property declaration (after line 38):

```php
		/**
		 * Opaque product uid (`prod_…`) on the remote server.
		 *
		 * When set, API URLs and license storage keys use the uid instead
		 * of the numeric product id.
		 *
		 * @since 1.5
		 *
		 * @var string
		 */
		public $product_uid = '';
```

3b. Add the setter directly after the `setMetaCallback()` method:

```php
		/**
		 * Sets the opaque product uid used for API URLs and storage keys.
		 *
		 * Fluent, like setMeta(). Consumers should guard the call with
		 * method_exists(): on a site running several plugins that bundle
		 * this library, the oldest loaded copy wins the class_exists race
		 * and may not have this method.
		 *
		 * @since 1.5
		 *
		 * @param string $product_uid The `prod_…` uid from the repo server.
		 * @return $this
		 */
		public function setProductUid( $product_uid ) {
			$this->product_uid = $product_uid;

			return $this;
		}
```

3c. Add the two resolution helpers directly before `get_license_code_key()`:

```php
		/**
		 * Resolves the product identifier used in API URLs.
		 *
		 * @since 1.5
		 *
		 * @return string The product uid when set, otherwise the numeric id.
		 */
		public function get_api_product_key() {
			return $this->product_uid ? $this->product_uid : $this->product_id;
		}

		/**
		 * Resolves the base name for license/cache storage keys.
		 *
		 * Uid-based when the uid is set; otherwise the id-based
		 * `license_name`, which keeps its value for backward compatibility.
		 *
		 * @since 1.5
		 *
		 * @return string
		 */
		public function get_storage_name() {
			if ( $this->product_uid ) {
				return sanitize_key( "{$this->product_slug}{$this->product_uid}" );
			}

			return $this->license_name;
		}
```

3d. Switch the four key helpers to `get_storage_name()` — change only the return lines:

- `get_license_code_key()`: `return "{$this->get_storage_name()}_code";`
- `get_license_data_key()`: `return "{$this->get_storage_name()}_data";`
- `get_updates_cache_key()`: `return "{$this->get_storage_name()}_updates_cache";`
- `get_details_cache_key()`: `return "{$this->get_storage_name()}_details_cache";`

- [ ] **Step 4: Run the full suite**

Run: `composer test`
Expected: ALL tests pass, including the pre-existing suite (no-uid behavior is unchanged).

- [ ] **Step 5: Lint and commit**

```bash
composer lint
git add src/Integration.php tests/IntegrationProductUidTest.php
git commit -m "feat: setProductUid with uid-aware API and storage key resolution

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: Client URLs use the uid

**Files:**
- Modify: `src/Client.php:61` and `src/Client.php:243`
- Test: `tests/ClientApiRequestTest.php` (append)

**Interfaces:**
- Consumes: `Integration::get_api_product_key()` and `setProductUid()` from Task 1.
- Produces: all Client HTTP calls (`ping()` at line 61; `check_license()`/`updates()`/`details()` via private `request()` at line 243) hit `products/{uid}/...` when the uid is set, `products/{id}/...` otherwise.

- [ ] **Step 1: Write the failing tests**

Append to `tests/ClientApiRequestTest.php` (before the closing `}` of the class):

```php
	/** @test */
	public function ping_url_uses_uid_when_set() {
		$integration = $this->create_integration();
		$integration->setProductUid( 'prod_testuid' );
		$this->stub_api_dependencies();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$fixture      = $this->load_fixture_raw( 'ping-success.json' );
		$captured_url = null;

		Functions\expect( 'wp_remote_post' )
			->once()
			->with( \Mockery::on( function ( $url ) use ( &$captured_url ) {
				$captured_url = $url;
				return true;
			} ), \Mockery::any() )
			->andReturn( [ 'body' => $fixture ] );

		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( $fixture );

		$integration->client->ping();

		$this->assertSame(
			'https://api.example.com/wp-json/wp-repo/v3/products/prod_testuid/ping',
			$captured_url
		);
	}

	/** @test */
	public function check_license_url_uses_uid_when_set() {
		$integration = $this->create_integration();
		$integration->setProductUid( 'prod_testuid' );
		$this->stub_http_dependencies();

		$body         = '{"status":"active"}';
		$captured_url = null;

		Functions\expect( 'wp_remote_request' )
			->once()
			->with( \Mockery::on( function ( $url ) use ( &$captured_url ) {
				$captured_url = $url;
				return true;
			} ), \Mockery::any() )
			->andReturn( [ 'body' => $body ] );

		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( $body );

		$integration->client->check_license( 'ABC-123' );

		$this->assertStringContainsString( '/products/prod_testuid/check_license', $captured_url );
	}
```

Note: `create_integration()` defaults to `license_enabled => false`, so `ping()` skips the stored-license lookup — no `get_option` stub needed, and (after Task 3 lands) `setProductUid()`'s migration guard short-circuits the same way.

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- --filter ClientApiRequestTest`
Expected: the two new tests FAIL — captured URL contains `/products/42/`, not the uid. Pre-existing tests pass.

- [ ] **Step 3: Implement in `src/Client.php`**

Line 61, in `ping()` — replace:

```php
			$request_url = "{$this->integration->api_url}/products/{$this->integration->product_id}/ping";
```

with:

```php
			$request_url = "{$this->integration->api_url}/products/{$this->integration->get_api_product_key()}/ping";
```

Line 243, in `request()` — replace:

```php
			$request_url = "{$this->integration->api_url}/products/{$this->integration->product_id}/$method";
```

with:

```php
			$request_url = "{$this->integration->api_url}/products/{$this->integration->get_api_product_key()}/$method";
```

- [ ] **Step 4: Run the full suite**

Run: `composer test`
Expected: ALL tests pass (existing Client tests still see `/products/42/` because no uid is set).

- [ ] **Step 5: Lint and commit**

```bash
composer lint
git add src/Client.php tests/ClientApiRequestTest.php
git commit -m "feat: API URLs use product uid when set

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: License storage clone and delete cascade

**Files:**
- Modify: `src/Integration.php` (`setProductUid()`, `delete_license_code()`, `delete_license_data()`; new methods after `get_details_cache_key()`)
- Test: `tests/IntegrationMigrationTest.php` (new)

**Interfaces:**
- Consumes: Task 1's `setProductUid()`, `get_storage_name()`, and uid-aware key helpers.
- Produces: `maybe_migrate_license_storage(): void` (called from `setProductUid()`); `get_legacy_license_code_key(): string` / `get_legacy_license_data_key(): string` (always id-based); `delete_license_code()` / `delete_license_data()` also delete the legacy copy when the uid is set. The 1.6.0 prune release will reuse the legacy key helpers.

- [ ] **Step 1: Write the failing tests**

Create `tests/IntegrationMigrationTest.php`:

```php
<?php
namespace Shazzad\PluginUpdater\Tests;

use Brain\Monkey\Functions;

class IntegrationMigrationTest extends TestCase {

	/** @test */
	public function clone_copies_code_and_data_to_uid_keys() {
		$integration = $this->create_integration( [ 'license_enabled' => true ] );

		Functions\when( 'get_option' )->alias( function ( $key ) {
			$options = [
				'my-plugin42_code' => 'ABC-123-DEF',
				'my-plugin42_data' => [ 'status' => 'active' ],
			];
			return array_key_exists( $key, $options ) ? $options[ $key ] : false;
		} );

		$updates = [];
		Functions\when( 'update_option' )->alias( function ( $key, $value ) use ( &$updates ) {
			$updates[ $key ] = $value;
			return true;
		} );

		$integration->setProductUid( 'prod_testuid' );

		$this->assertSame( 'ABC-123-DEF', $updates['my-pluginprod_testuid_code'] );
		$this->assertSame( [ 'status' => 'active' ], $updates['my-pluginprod_testuid_data'] );
	}

	/** @test */
	public function clone_skips_when_uid_key_already_exists() {
		$integration = $this->create_integration( [ 'license_enabled' => true ] );

		Functions\when( 'get_option' )->alias( function ( $key ) {
			if ( 'my-pluginprod_testuid_code' === $key ) {
				return 'ALREADY-THERE';
			}
			return 'my-plugin42_code' === $key ? 'ABC-123-DEF' : false;
		} );

		Functions\expect( 'update_option' )->never();

		$integration->setProductUid( 'prod_testuid' );
	}

	/** @test */
	public function clone_skips_when_license_disabled() {
		$integration = $this->create_integration( [ 'license_enabled' => false ] );

		Functions\expect( 'get_option' )->never();
		Functions\expect( 'update_option' )->never();

		$integration->setProductUid( 'prod_testuid' );
	}

	/** @test */
	public function clone_skips_when_no_legacy_license() {
		$integration = $this->create_integration( [ 'license_enabled' => true ] );

		Functions\when( 'get_option' )->justReturn( false );
		Functions\expect( 'update_option' )->never();

		$integration->setProductUid( 'prod_testuid' );
	}

	/** @test */
	public function clone_copies_code_only_when_no_legacy_data() {
		$integration = $this->create_integration( [ 'license_enabled' => true ] );

		Functions\when( 'get_option' )->alias( function ( $key ) {
			return 'my-plugin42_code' === $key ? 'ABC-123-DEF' : false;
		} );

		$updates = [];
		Functions\when( 'update_option' )->alias( function ( $key, $value ) use ( &$updates ) {
			$updates[ $key ] = $value;
			return true;
		} );

		$integration->setProductUid( 'prod_testuid' );

		$this->assertSame( [ 'my-pluginprod_testuid_code' => 'ABC-123-DEF' ], $updates );
	}

	/** @test */
	public function delete_license_code_removes_both_copies_when_uid_set() {
		$integration = $this->create_integration( [ 'license_enabled' => true ] );

		// Short-circuit the clone triggered by setProductUid().
		Functions\when( 'get_option' )->alias( function ( $key ) {
			return 'my-pluginprod_testuid_code' === $key ? 'ABC-123-DEF' : false;
		} );

		$integration->setProductUid( 'prod_testuid' );

		$deleted = [];
		Functions\when( 'delete_option' )->alias( function ( $key ) use ( &$deleted ) {
			$deleted[] = $key;
			return true;
		} );

		$this->assertTrue( $integration->delete_license_code() );
		$this->assertSame( [ 'my-plugin42_code', 'my-pluginprod_testuid_code' ], $deleted );
	}

	/** @test */
	public function delete_license_data_removes_both_copies_when_uid_set() {
		$integration = $this->create_integration( [ 'license_enabled' => true ] );

		Functions\when( 'get_option' )->alias( function ( $key ) {
			return 'my-pluginprod_testuid_code' === $key ? 'ABC-123-DEF' : false;
		} );

		$integration->setProductUid( 'prod_testuid' );

		$deleted = [];
		Functions\when( 'delete_option' )->alias( function ( $key ) use ( &$deleted ) {
			$deleted[] = $key;
			return true;
		} );

		$this->assertTrue( $integration->delete_license_data() );
		$this->assertSame( [ 'my-plugin42_data', 'my-pluginprod_testuid_data' ], $deleted );
	}
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- --filter IntegrationMigrationTest`
Expected: FAIL — clone tests find `$updates` empty (`maybe_migrate_license_storage` undefined / never called); cascade tests see only one `delete_option` call.

- [ ] **Step 3: Implement in `src/Integration.php`**

3a. In `setProductUid()` (added in Task 1), insert the migration call — replace:

```php
		public function setProductUid( $product_uid ) {
			$this->product_uid = $product_uid;

			return $this;
		}
```

body with:

```php
		public function setProductUid( $product_uid ) {
			$this->product_uid = $product_uid;

			$this->maybe_migrate_license_storage();

			return $this;
		}
```

3b. Add after `get_details_cache_key()`:

```php
		/**
		 * Retrieves the id-based option key the license code was stored
		 * under before the uid migration.
		 *
		 * @since 1.5
		 *
		 * @return string
		 */
		public function get_legacy_license_code_key() {
			return "{$this->license_name}_code";
		}

		/**
		 * Retrieves the id-based option key the license data was stored
		 * under before the uid migration.
		 *
		 * @since 1.5
		 *
		 * @return string
		 */
		public function get_legacy_license_data_key() {
			return "{$this->license_name}_data";
		}

		/**
		 * Clones id-based license options to their uid-based keys.
		 *
		 * One-way and self-limiting: once the uid-based code option exists
		 * the clone never runs again. Old copies stay in place until the
		 * prune release (1.6). A failed write simply re-runs next load.
		 *
		 * @since 1.5
		 *
		 * @return void
		 */
		public function maybe_migrate_license_storage() {
			if ( ! $this->license_enabled || ! $this->product_uid ) {
				return;
			}

			if ( false !== get_option( $this->get_license_code_key() ) ) {
				return;
			}

			$legacy_code = get_option( $this->get_legacy_license_code_key() );

			if ( false === $legacy_code ) {
				return;
			}

			update_option( $this->get_license_code_key(), $legacy_code );

			$legacy_data = get_option( $this->get_legacy_license_data_key() );

			if ( false !== $legacy_data ) {
				update_option( $this->get_license_data_key(), $legacy_data );
			}
		}
```

3c. Replace `delete_license_code()` and `delete_license_data()` with:

```php
		/**
		 * Deletes the license code from the database.
		 *
		 * @since 1.2
		 * @since 1.5 Also removes the id-based copy when a uid is set, so a
		 *            deleted license cannot be resurrected by the clone.
		 *
		 * @return bool True if the option was deleted, false otherwise.
		 */
		public function delete_license_code() {
			if ( $this->product_uid ) {
				delete_option( $this->get_legacy_license_code_key() );
			}

			return delete_option( $this->get_license_code_key() );
		}

		/**
		 * Deletes the license data from the database.
		 *
		 * @since 1.2
		 * @since 1.5 Also removes the id-based copy when a uid is set.
		 *
		 * @return bool True if the option was deleted, false otherwise.
		 */
		public function delete_license_data() {
			if ( $this->product_uid ) {
				delete_option( $this->get_legacy_license_data_key() );
			}

			return delete_option( $this->get_license_data_key() );
		}
```

- [ ] **Step 4: Run the full suite**

Run: `composer test`
Expected: ALL tests pass — including Task 1's uid tests (their `create_integration()` defaults to `license_enabled => false`, so the new migration call is a no-op there) and the pre-existing delete tests (no uid → single `delete_option`).

- [ ] **Step 5: Lint and commit**

```bash
composer lint
git add src/Integration.php tests/IntegrationMigrationTest.php
git commit -m "feat: clone id-based license storage to uid keys, cascade deletes

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 4: Real-WordPress smoke of the migration

**Files:**
- None modified — verification only, against the local docker WordPress (the library is path-symlinked into `shazzad-plugin-updater-test`'s vendor dir, so branch code is live in the container).

**Interfaces:**
- Consumes: everything from Tasks 1–3.
- Produces: evidence that the clone and cascade work against real `get_option`/`update_option` (not Brain Monkey stubs).

- [ ] **Step 1: Start the stack and run the smoke script**

```bash
cd /home/shazzad/personal-assistant/w4dev-project && docker compose up -d
docker compose exec -T wordpress wp eval '
$prefix = "uidsmoke/uidsmoke.php";
update_option( "uidsmoke7_code", "SMOKE-CODE" );
update_option( "uidsmoke7_data", [ "status" => "active" ] );
delete_option( "uidsmokeprod_smoketest_code" );
delete_option( "uidsmokeprod_smoketest_data" );

$i = new Shazzad\PluginUpdater\Integration( "https://example.com/wp-json/wp-repo/v3", $prefix, 7, true, false );
$i->setProductUid( "prod_smoketest" );

echo "cloned_code=" . var_export( get_option( "uidsmokeprod_smoketest_code" ), true ) . "\n";
echo "cloned_data_status=" . var_export( get_option( "uidsmokeprod_smoketest_data" )["status"] ?? null, true ) . "\n";
echo "legacy_kept=" . var_export( get_option( "uidsmoke7_code" ), true ) . "\n";
echo "url_key=" . $i->get_api_product_key() . "\n";

$i->delete_license_code();
$i->delete_license_data();
echo "after_delete_new=" . var_export( get_option( "uidsmokeprod_smoketest_code" ), true ) . "\n";
echo "after_delete_legacy=" . var_export( get_option( "uidsmoke7_code" ), true ) . "\n";

delete_option( "uidsmoke7_data" );
' --allow-root
```

Expected output lines:

```
cloned_code='SMOKE-CODE'
cloned_data_status='active'
legacy_kept='SMOKE-CODE'
url_key=prod_smoketest
after_delete_new=false
after_delete_legacy=false
```

Notes: the fifth constructor arg `false` disables the admin menu so no hooks matter in CLI. The script cleans up its own options (the two deletes at the end plus the cascade covering the rest). If the loaded library class lacks `setProductUid` (symlinked vendor not picking up the branch), run `composer install` inside `../shazzad-plugin-updater-test` and retry once; if it still fails, report BLOCKED with the output.

- [ ] **Step 2: Record the evidence**

Paste the smoke output into the task report. No commit — nothing changed.

---

## Not in this plan (documented follow-ups)

- **Library 1.6.0 prune release**: delete legacy `_code`/`_data` when the uid-based `_code` exists. Ships only after all consumers carry the uid for a while.
- **Consumer rollouts** (LoxoWP, Soccer Engine, WP Pro Admin, PimiPay, PimiDocs, updater-test): library bump + `method_exists`-guarded `setProductUid( '<uid>' )` per plugin, each its own release.
