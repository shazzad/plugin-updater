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
	public function initializes_no_update_array_when_missing() {
		$integration = $this->create_integration();

		$transient           = new \stdClass();
		$transient->response = [];
		$transient->checked  = [];
		// no_update property not set

		$saved = null;

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
}
