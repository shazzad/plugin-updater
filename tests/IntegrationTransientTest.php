<?php
namespace Shazzad\PluginUpdater\Tests;

use Brain\Monkey\Functions;
use Mockery;

class IntegrationTransientTest extends TestCase {

	/** @test */
	public function moves_plugin_from_response_to_no_update() {
		$integration = $this->create_integration();

		$update_obj = (object) [
			'slug'        => 'my-plugin',
			'new_version' => '1.3.0',
			'package'     => 'https://example.com/download',
		];

		$transient           = new \stdClass();
		$transient->response = [ 'my-plugin/my-plugin.php' => $update_obj ];
		$transient->no_update = [];
		$transient->checked  = [];

		$saved = null;

		Functions\when( 'delete_site_transient' )->justReturn( true );

		Functions\expect( 'get_site_transient' )
			->once()
			->with( 'update_plugins' )
			->andReturn( $transient );

		Functions\expect( 'set_site_transient' )
			->once()
			->with( 'update_plugins', Mockery::on( function ( $t ) use ( &$saved ) {
				$saved = $t;
				return true;
			} ) );

		$integration->clear_updates_transient();

		$this->assertArrayNotHasKey( 'my-plugin/my-plugin.php', $saved->response );
		$this->assertArrayHasKey( 'my-plugin/my-plugin.php', $saved->no_update );
		$this->assertSame( $update_obj, $saved->no_update['my-plugin/my-plugin.php'] );
	}

	/** @test */
	public function creates_no_update_entry_when_plugin_not_in_response() {
		$integration = $this->create_integration();

		$transient            = new \stdClass();
		$transient->response  = [];
		$transient->no_update = [];
		$transient->checked   = [];

		$saved = null;

		Functions\when( 'delete_site_transient' )->justReturn( true );

		Functions\expect( 'get_site_transient' )
			->once()
			->with( 'update_plugins' )
			->andReturn( $transient );

		Functions\expect( 'set_site_transient' )
			->once()
			->with( 'update_plugins', Mockery::on( function ( $t ) use ( &$saved ) {
				$saved = $t;
				return true;
			} ) );

		$integration->clear_updates_transient();

		$this->assertArrayHasKey( 'my-plugin/my-plugin.php', $saved->no_update );
		$entry = $saved->no_update['my-plugin/my-plugin.php'];
		$this->assertSame( 'my-plugin', $entry->slug );
		$this->assertSame( 'my-plugin/my-plugin.php', $entry->plugin );
		$this->assertSame( '1.0.0', $entry->new_version );
	}

	/** @test */
	public function initializes_transient_when_false() {
		$integration = $this->create_integration();

		$saved = null;

		Functions\when( 'delete_site_transient' )->justReturn( true );

		Functions\expect( 'get_site_transient' )
			->once()
			->with( 'update_plugins' )
			->andReturn( false );

		Functions\expect( 'set_site_transient' )
			->once()
			->with( 'update_plugins', Mockery::on( function ( $t ) use ( &$saved ) {
				$saved = $t;
				return true;
			} ) );

		$integration->clear_updates_transient();

		$this->assertIsObject( $saved );
		$this->assertIsArray( $saved->response );
		$this->assertArrayHasKey( 'my-plugin/my-plugin.php', $saved->no_update );
	}

	/** @test */
	public function initializes_transient_when_empty_string() {
		$integration = $this->create_integration();

		$saved = null;

		Functions\when( 'delete_site_transient' )->justReturn( true );

		// A transient stored as boolean false reads back as '' on single site,
		// because the options table cannot round-trip false.
		Functions\expect( 'get_site_transient' )
			->once()
			->with( 'update_plugins' )
			->andReturn( '' );

		Functions\expect( 'set_site_transient' )
			->once()
			->with( 'update_plugins', Mockery::on( function ( $t ) use ( &$saved ) {
				$saved = $t;
				return true;
			} ) );

		$integration->clear_updates_transient();

		$this->assertIsObject( $saved );
		$this->assertIsArray( $saved->response );
		$this->assertArrayHasKey( 'my-plugin/my-plugin.php', $saved->no_update );
	}

	/** @test */
	public function initializes_no_update_array_when_missing() {
		$integration = $this->create_integration();

		$transient           = new \stdClass();
		$transient->response = [];
		$transient->checked  = [];
		// no_update property not set

		$saved = null;

		Functions\when( 'delete_site_transient' )->justReturn( true );

		Functions\expect( 'get_site_transient' )
			->once()
			->with( 'update_plugins' )
			->andReturn( $transient );

		Functions\expect( 'set_site_transient' )
			->once()
			->with( 'update_plugins', Mockery::on( function ( $t ) use ( &$saved ) {
				$saved = $t;
				return true;
			} ) );

		$integration->clear_updates_transient();

		$this->assertIsArray( $saved->no_update );
		$this->assertArrayHasKey( 'my-plugin/my-plugin.php', $saved->no_update );
	}

	/** @test */
	public function clear_updates_transient_deletes_caches() {
		$integration = $this->create_integration();

		$transient            = new \stdClass();
		$transient->response  = [];
		$transient->no_update = [];
		$transient->checked   = [];

		$deleted_keys = [];

		Functions\expect( 'delete_site_transient' )
			->twice()
			->with( Mockery::on( function ( $key ) use ( &$deleted_keys ) {
				$deleted_keys[] = $key;
				return true;
			} ) );

		Functions\expect( 'get_site_transient' )
			->once()
			->with( 'update_plugins' )
			->andReturn( $transient );

		Functions\expect( 'set_site_transient' )
			->once();

		$integration->clear_updates_transient();

		$this->assertContains( $integration->get_updates_cache_key(), $deleted_keys );
		$this->assertContains( $integration->get_details_cache_key(), $deleted_keys );
	}

	/** @test */
	public function refresh_normalizes_cold_transient_to_object() {
		$integration = $this->create_integration();

		$saved = null;

		Functions\when( 'delete_site_transient' )->justReturn( true );

		Functions\expect( 'get_site_transient' )
			->once()
			->with( 'update_plugins' )
			->andReturn( false );

		Functions\expect( 'set_site_transient' )
			->once()
			->with( 'update_plugins', Mockery::on( function ( $t ) use ( &$saved ) {
				$saved = $t;
				return true;
			} ) );

		$integration->refresh_updates_transient();

		// A cold transient must never be re-set as false: it would pass false
		// through the pre_set_site_transient_update_plugins filter chain and
		// store a value that reads back as '' on single site.
		$this->assertInstanceOf( \stdClass::class, $saved );

		// No last_checked, so core's wp_update_plugins() throttle never sees
		// a fresh timestamp and the next update check proceeds normally.
		$this->assertFalse( property_exists( $saved, 'last_checked' ) );
	}

	/** @test */
	public function refresh_updates_transient_deletes_caches() {
		$integration = $this->create_integration();

		$transient = new \stdClass();

		$deleted_keys = [];

		Functions\expect( 'delete_site_transient' )
			->twice()
			->with( Mockery::on( function ( $key ) use ( &$deleted_keys ) {
				$deleted_keys[] = $key;
				return true;
			} ) );

		Functions\expect( 'get_site_transient' )
			->once()
			->with( 'update_plugins' )
			->andReturn( $transient );

		Functions\expect( 'set_site_transient' )
			->once()
			->with( 'update_plugins', $transient );

		$integration->refresh_updates_transient();

		$this->assertContains( $integration->get_updates_cache_key(), $deleted_keys );
		$this->assertContains( $integration->get_details_cache_key(), $deleted_keys );
	}
}
