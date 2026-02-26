<?php
namespace Shazzad\PluginUpdater\Tests;

use Brain\Monkey\Functions;
use Shazzad\PluginUpdater\Updater;
use WP_Error;

class UpdaterPreSetTransientTest extends TestCase {

	/**
	 * Helper: stub the WP functions used by Client::request() inside pre_set_transient().
	 */
	private function stub_api_dependencies(): void {
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'site_url' )->justReturn( 'https://example.com' );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'get_bloginfo' )->justReturn( '6.4' );
		Functions\when( 'add_query_arg' )->alias( function ( $args, $url ) {
			return $url . '?' . http_build_query( $args );
		} );
	}

	/**
	 * Helper: create an Updater with its Integration and stub an API response.
	 *
	 * Stubs cache as a miss (get_site_transient returns false) so the API call
	 * proceeds, and accepts the set_site_transient call to store the cache.
	 *
	 * @param string|null $fixture_name Fixture file name or null for WP_Error.
	 * @param int         $status_code  HTTP status code.
	 * @return Updater
	 */
	private function create_updater_with_api_response( ?string $fixture_name, int $status_code = 200 ): Updater {
		$integration = $this->create_integration();
		$this->stub_api_dependencies();

		Functions\when( 'get_site_transient' )->justReturn( false );
		Functions\when( 'set_site_transient' )->justReturn( true );

		if ( null === $fixture_name ) {
			Functions\expect( 'wp_remote_request' )
				->once()
				->andReturn( new WP_Error( 'http_error', 'Timeout' ) );
		} else {
			$fixture = $this->load_fixture_raw( $fixture_name );

			Functions\expect( 'wp_remote_request' )->once()->andReturn( [] );
			Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( $status_code );
			Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( $fixture );
		}

		return $integration->updater;
	}

	/**
	 * Helper: build a transient object.
	 */
	private function make_transient( array $checked = [ 'my-plugin/my-plugin.php' => '1.0.0' ] ): \stdClass {
		$transient           = new \stdClass();
		$transient->checked  = $checked;
		$transient->response = [];
		$transient->no_update = [];

		return $transient;
	}

	/** @test */
	public function returns_unmodified_when_checked_is_empty() {
		$integration = $this->create_integration();

		$transient          = new \stdClass();
		$transient->checked = [];

		$result = $integration->updater->pre_set_transient( $transient );

		$this->assertSame( $transient, $result );
		$this->assertEmpty( $transient->checked );
	}

	/** @test */
	public function returns_unmodified_when_checked_is_missing() {
		$integration = $this->create_integration();

		$transient = new \stdClass();

		$result = $integration->updater->pre_set_transient( $transient );

		$this->assertSame( $transient, $result );
	}

	/** @test */
	public function adds_to_response_when_new_version_available() {
		$updater   = $this->create_updater_with_api_response( 'updates-available.json' );
		$transient = $this->make_transient();

		$result = $updater->pre_set_transient( $transient );

		$this->assertArrayHasKey( 'my-plugin/my-plugin.php', $result->response );
		$entry = $result->response['my-plugin/my-plugin.php'];
		$this->assertSame( '1.3.0', $entry->new_version );
		$this->assertSame( 'my-plugin/my-plugin.php', $entry->plugin );
		$this->assertSame( 'my-plugin', $entry->slug );
	}

	/** @test */
	public function adds_to_no_update_when_version_is_same() {
		$integration = $this->create_integration();
		$integration->product_version = '1.3.0'; // Same as fixture.
		$this->stub_api_dependencies();

		Functions\when( 'get_site_transient' )->justReturn( false );
		Functions\when( 'set_site_transient' )->justReturn( true );

		$fixture = $this->load_fixture_raw( 'updates-available.json' );

		Functions\expect( 'wp_remote_request' )->once()->andReturn( [] );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( $fixture );

		$transient = $this->make_transient();

		$result = $integration->updater->pre_set_transient( $transient );

		$this->assertArrayNotHasKey( 'my-plugin/my-plugin.php', $result->response );
		$this->assertArrayHasKey( 'my-plugin/my-plugin.php', $result->no_update );
	}

	/** @test */
	public function adds_to_no_update_when_version_is_lower() {
		$integration = $this->create_integration();
		$integration->product_version = '2.0.0'; // Higher than fixture's 1.3.0.
		$this->stub_api_dependencies();

		Functions\when( 'get_site_transient' )->justReturn( false );
		Functions\when( 'set_site_transient' )->justReturn( true );

		$fixture = $this->load_fixture_raw( 'updates-available.json' );

		Functions\expect( 'wp_remote_request' )->once()->andReturn( [] );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( $fixture );

		$transient = $this->make_transient();

		$result = $integration->updater->pre_set_transient( $transient );

		$this->assertArrayNotHasKey( 'my-plugin/my-plugin.php', $result->response );
		$this->assertArrayHasKey( 'my-plugin/my-plugin.php', $result->no_update );
	}

	/** @test */
	public function moves_existing_response_to_no_update_when_not_newer() {
		$integration = $this->create_integration();
		$integration->product_version = '1.3.0'; // Same as fixture.
		$this->stub_api_dependencies();

		Functions\when( 'get_site_transient' )->justReturn( false );
		Functions\when( 'set_site_transient' )->justReturn( true );

		$fixture = $this->load_fixture_raw( 'updates-available.json' );

		Functions\expect( 'wp_remote_request' )->once()->andReturn( [] );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( $fixture );

		$old_entry = (object) [ 'new_version' => '1.2.0', 'slug' => 'my-plugin' ];

		$transient           = $this->make_transient();
		$transient->response = [ 'my-plugin/my-plugin.php' => $old_entry ];

		$result = $integration->updater->pre_set_transient( $transient );

		$this->assertArrayNotHasKey( 'my-plugin/my-plugin.php', $result->response );
		$this->assertArrayHasKey( 'my-plugin/my-plugin.php', $result->no_update );
		$this->assertSame( $old_entry, $result->no_update['my-plugin/my-plugin.php'] );
	}

	/** @test */
	public function does_nothing_on_api_error() {
		$updater   = $this->create_updater_with_api_response( null );
		$transient = $this->make_transient();

		$result = $updater->pre_set_transient( $transient );

		$this->assertEmpty( $result->response );
		$this->assertEmpty( $result->no_update );
	}

	/** @test */
	public function does_nothing_when_updates_key_missing() {
		$updater   = $this->create_updater_with_api_response( 'ping-success.json' );
		$transient = $this->make_transient();

		$result = $updater->pre_set_transient( $transient );

		$this->assertEmpty( $result->response );
		$this->assertEmpty( $result->no_update );
	}

}
