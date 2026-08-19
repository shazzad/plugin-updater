<?php
namespace Shazzad\PluginUpdater\Tests\V2;

use Brain\Monkey\Functions;

/**
 * V2-only coverage: the config-array constructor surface.
 */
class IntegrationConfigTest extends TestCase {

	/** @test */
	public function config_populates_core_properties() {
		$integration = $this->create_integration();

		$this->assertSame( 'https://api.example.com/wp-json/wp-repo/v3', $integration->api_url );
		$this->assertSame( 'my-plugin/my-plugin.php', $integration->product_file );
		$this->assertSame( 'my-plugin', $integration->product_slug );
		$this->assertSame( '42', $integration->product_id );
		$this->assertSame( 'my-plugin42', $integration->license_name );
		$this->assertSame( 'active', $integration->product_status );
		$this->assertFalse( $integration->license_enabled );
	}

	/** @test */
	public function uid_via_config_drives_api_and_storage_keys() {
		$integration = $this->create_integration( [ 'product_uid' => 'prod_testuid' ] );

		$this->assertSame( 'prod_testuid', $integration->get_api_product_key() );
		$this->assertSame( 'prod_testuid_code', $integration->get_license_code_key() );
		$this->assertSame( 'prod_testuid_data', $integration->get_license_data_key() );
	}

	/** @test */
	public function uid_via_config_runs_legacy_migration_at_construction() {
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

		$this->create_integration( [
			'license'     => true,
			'product_uid' => 'prod_testuid',
		] );

		$this->assertSame( 'ABC-123-DEF', $updates['prod_testuid_code'] );
		$this->assertSame( [ 'status' => 'active' ], $updates['prod_testuid_data'] );
	}

	/** @test */
	public function uid_via_config_skips_migration_when_license_disabled() {
		Functions\expect( 'get_option' )->never();
		Functions\expect( 'update_option' )->never();

		$this->create_integration( [
			'license'     => false,
			'product_uid' => 'prod_testuid',
		] );

		// Silence beStrictAboutTestsThatDoNotTestAnything; the expectations
		// above are the assertions.
		$this->assertTrue( true );
	}

	/** @test */
	public function menu_false_disables_the_license_page() {
		$integration = $this->create_integration( [
			'license' => true,
			'menu'    => false,
		] );

		$this->assertFalse( $integration->display_menu );
		$this->assertNull( $integration->admin );
	}

	/** @test */
	public function menu_defaults_on_when_license_enabled_and_menu_omitted() {
		$config = [
			'api_url'    => 'https://api.example.com/wp-json/wp-repo/v3',
			'file'       => 'my-plugin/my-plugin.php',
			'product_id' => '42',
			'license'    => true,
		];

		Functions\when( 'sanitize_key' )->alias( function ( $key ) {
			return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
		} );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );

		$integration = new \Shazzad\PluginUpdater\V2\Integration( $config );

		$this->assertTrue( $integration->display_menu );
		$this->assertInstanceOf( \Shazzad\PluginUpdater\V2\Admin\LicensePage::class, $integration->admin );
	}

	/** @test */
	public function menu_settings_populate_label_parent_priority() {
		$integration = $this->create_integration( [
			'license' => true,
			'menu'    => [
				'parent'   => 'options-general.php',
				'label'    => 'My Plugin License',
				'priority' => 20,
			],
		] );

		$this->assertSame( 'options-general.php', $integration->menu_parent );
		$this->assertSame( 'My Plugin License', $integration->menu_label );
		$this->assertSame( 20, $integration->menu_priority );
	}

	/** @test */
	public function menu_is_never_shown_when_license_disabled() {
		$integration = $this->create_integration( [
			'license' => false,
			'menu'    => [ 'label' => 'My Plugin License' ],
		] );

		$this->assertFalse( $integration->display_menu );
		$this->assertNull( $integration->admin );
	}

	/** @test */
	public function store_is_exposed_as_public_property() {
		$integration = $this->create_integration();

		$this->assertInstanceOf( \Shazzad\PluginUpdater\V2\License\Store::class, $integration->store );
		$this->assertSame( $integration->get_license_code_key(), $integration->store->get_license_code_key() );
	}

	/** @test */
	public function meta_and_meta_callback_via_config_populate_properties() {
		$callback = function () {
			return [ 'php_version' => '7.4' ];
		};

		$integration = $this->create_integration( [
			'meta'          => [ 'memory_limit' => '256M' ],
			'meta_callback' => $callback,
		] );

		$this->assertSame( [ 'memory_limit' => '256M' ], $integration->meta );
		$this->assertSame( $callback, $integration->meta_callback );
	}

	/** @test */
	public function fluent_setters_override_config_meta() {
		$integration = $this->create_integration( [
			'meta' => [ 'memory_limit' => '256M' ],
		] );

		$integration->setMeta( [ 'theme' => 'twentytwentyfive' ] );

		$this->assertSame( [ 'theme' => 'twentytwentyfive' ], $integration->meta );
	}

	/** @test */
	public function unknown_config_key_triggers_doing_it_wrong_naming_the_key() {
		$notices = [];
		Functions\when( '_doing_it_wrong' )->alias( function ( $method, $message, $version ) use ( &$notices ) {
			$notices[] = $message;
		} );

		$this->create_integration( [ 'product-uid' => 'prod_typo' ] );

		$this->assertCount( 1, $notices );
		$this->assertStringContainsString( 'product-uid', $notices[0] );
	}

	/** @test */
	public function missing_required_keys_trigger_doing_it_wrong_per_key() {
		$notices = [];
		Functions\when( '_doing_it_wrong' )->alias( function ( $method, $message, $version ) use ( &$notices ) {
			$notices[] = $message;
		} );
		Functions\when( 'sanitize_key' )->alias( function ( $key ) {
			return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
		} );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );

		$integration = new \Shazzad\PluginUpdater\V2\Integration( [] );

		$this->assertCount( 2, $notices );
		$this->assertStringContainsString( 'api_url', $notices[0] );
		$this->assertStringContainsString( 'file', $notices[1] );
		// Construction proceeded despite the notices.
		$this->assertInstanceOf( \Shazzad\PluginUpdater\V2\Integration::class, $integration );
	}

	/** @test */
	public function valid_config_triggers_no_doing_it_wrong() {
		Functions\expect( '_doing_it_wrong' )->never();

		$this->create_integration( [
			'product_uid'   => 'prod_testuid',
			'meta'          => [ 'a' => 'b' ],
			'meta_callback' => function () {
				return [];
			},
		] );

		// The expectation above is the assertion.
		$this->assertTrue( true );
	}
}
