<?php
namespace Shazzad\PluginUpdater\Tests;

use Brain\Monkey\Functions;
use WP_Error;

class ClientApiRequestTest extends TestCase {

	/**
	 * Stub the common WP functions used by Client::request() and updates() cache.
	 */
	private function stub_api_dependencies(): void {
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'site_url' )->justReturn( 'https://example.com' );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'get_bloginfo' )->justReturn( '6.4' );
		Functions\when( 'add_query_arg' )->alias( function ( $args, $url ) {
			return $url . '?' . http_build_query( $args );
		} );
		Functions\when( 'get_site_transient' )->justReturn( false );
		Functions\when( 'set_site_transient' )->justReturn( true );
	}

	/**
	 * Stub only the HTTP-layer WP functions (no cache stubs).
	 */
	private function stub_http_dependencies(): void {
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'site_url' )->justReturn( 'https://example.com' );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'get_bloginfo' )->justReturn( '6.4' );
		Functions\when( 'add_query_arg' )->alias( function ( $args, $url ) {
			return $url . '?' . http_build_query( $args );
		} );
	}

	/** @test */
	public function ping_returns_parsed_body() {
		$integration = $this->create_integration();
		$this->stub_api_dependencies();

		$fixture = $this->load_fixture_raw( 'ping-success.json' );

		Functions\expect( 'wp_remote_request' )
			->once()
			->andReturn( [ 'body' => $fixture ] );

		Functions\expect( 'wp_remote_retrieve_response_code' )
			->once()
			->andReturn( 200 );

		Functions\expect( 'wp_remote_retrieve_body' )
			->once()
			->andReturn( $fixture );

		$result = $integration->client->ping();

		$this->assertIsArray( $result );
		$this->assertSame( 'Ping successful', $result['message'] );
	}

	/** @test */
	public function wp_error_from_wp_remote_request_is_returned() {
		$integration = $this->create_integration();
		$this->stub_api_dependencies();

		$error = new WP_Error( 'http_error', 'Connection timed out' );

		Functions\expect( 'wp_remote_request' )
			->once()
			->andReturn( $error );

		$result = $integration->client->ping();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'http_error', $result->get_error_code() );
	}

	/** @test */
	public function empty_body_returns_wp_error() {
		$integration = $this->create_integration();
		$this->stub_api_dependencies();

		Functions\expect( 'wp_remote_request' )->once()->andReturn( [] );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( '' );

		$result = $integration->client->updates();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wprepo_api_fail', $result->get_error_code() );
	}

	/** @test */
	public function error_404_with_error_body_returns_wp_error_with_api_code() {
		$integration = $this->create_integration();
		$this->stub_api_dependencies();

		$fixture = $this->load_fixture_raw( 'error-product-not-found.json' );

		Functions\expect( 'wp_remote_request' )->once()->andReturn( [] );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 404 );
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( $fixture );

		$result = $integration->client->updates();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'plugin_not_exists', $result->get_error_code() );
		$this->assertSame( 'Requested plugin does not exits on this provider', $result->get_error_message() );
	}

	/** @test */
	public function error_500_without_code_uses_fallback() {
		$integration = $this->create_integration();
		$this->stub_api_dependencies();

		$body = json_encode( [ 'message' => 'Internal error' ] );

		Functions\expect( 'wp_remote_request' )->once()->andReturn( [] );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 500 );
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( $body );

		$result = $integration->client->updates();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wprepo_api_error', $result->get_error_code() );
		$this->assertSame( 'Internal error', $result->get_error_message() );
	}

	/** @test */
	public function error_400_with_no_code_or_message_uses_both_fallbacks() {
		$integration = $this->create_integration();
		$this->stub_api_dependencies();

		$body = json_encode( [ 'data' => 'something' ] );

		Functions\expect( 'wp_remote_request' )->once()->andReturn( [] );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 400 );
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( $body );

		$result = $integration->client->updates();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wprepo_api_error', $result->get_error_code() );
		$this->assertSame( 'API request failed', $result->get_error_message() );
	}

	/** @test */
	public function license_included_in_updates_when_enabled() {
		$integration = $this->create_integration( [ 'license_enabled' => true ] );
		$this->stub_api_dependencies();

		Functions\expect( 'get_option' )
			->once()
			->with( 'my-plugin42_code' )
			->andReturn( 'MY-LICENSE-KEY' );

		$captured_url = null;
		$fixture      = $this->load_fixture_raw( 'ping-success.json' );

		Functions\expect( 'wp_remote_request' )
			->once()
			->with( \Mockery::on( function ( $url ) use ( &$captured_url ) {
				$captured_url = $url;
				return true;
			} ), \Mockery::any() )
			->andReturn( [] );

		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( $fixture );

		$integration->client->updates();

		$this->assertStringContainsString( 'license=MY-LICENSE-KEY', $captured_url );
	}

	/** @test */
	public function explicit_license_overrides_stored_value() {
		$integration = $this->create_integration( [ 'license_enabled' => true ] );
		$this->stub_api_dependencies();

		$captured_url = null;
		$fixture      = $this->load_fixture_raw( 'ping-success.json' );

		Functions\expect( 'wp_remote_request' )
			->once()
			->with( \Mockery::on( function ( $url ) use ( &$captured_url ) {
				$captured_url = $url;
				return true;
			} ), \Mockery::any() )
			->andReturn( [] );

		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( $fixture );

		$integration->client->check_license( 'EXPLICIT-KEY' );

		$this->assertStringContainsString( 'license=EXPLICIT-KEY', $captured_url );
		$this->assertStringNotContainsString( 'stored-key', $captured_url );
	}

	/** @test */
	public function check_license_returns_license_data() {
		$integration = $this->create_integration();
		$this->stub_api_dependencies();

		Functions\expect( 'get_option' )
			->once()
			->with( 'my-plugin42_code' )
			->andReturn( false );

		$fixture = $this->load_fixture_raw( 'check-license-success.json' );

		Functions\expect( 'wp_remote_request' )->once()->andReturn( [] );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( $fixture );

		$result = $integration->client->check_license();

		$this->assertIsArray( $result );
		$this->assertSame( 'active', $result['license']['status'] );
	}

	/** @test */
	public function updates_returns_update_data() {
		$integration = $this->create_integration();
		$this->stub_api_dependencies();

		$fixture = $this->load_fixture_raw( 'updates-available.json' );

		Functions\expect( 'wp_remote_request' )->once()->andReturn( [] );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( $fixture );

		$result = $integration->client->updates();

		$this->assertIsArray( $result );
		$this->assertSame( '1.3.0', $result['updates']['new_version'] );
	}

	/** @test */
	public function details_returns_plugin_details() {
		$integration = $this->create_integration();
		$this->stub_api_dependencies();

		$fixture = $this->load_fixture_raw( 'details-success.json' );

		Functions\expect( 'wp_remote_request' )->once()->andReturn( [] );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( $fixture );

		$result = $integration->client->details();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'details', $result );
	}

	/** @test */
	public function updates_returns_cached_response_without_api_call() {
		$integration = $this->create_integration();

		$cached_response = $this->load_fixture( 'updates-available.json' );

		Functions\expect( 'get_site_transient' )
			->once()
			->with( $integration->get_updates_cache_key() )
			->andReturn( $cached_response );

		Functions\expect( 'wp_remote_request' )->never();

		$result = $integration->client->updates();

		$this->assertIsArray( $result );
		$this->assertSame( '1.3.0', $result['updates']['new_version'] );
	}

	/** @test */
	public function updates_caches_successful_api_response() {
		$integration = $this->create_integration();
		$this->stub_http_dependencies();

		$fixture   = $this->load_fixture_raw( 'updates-available.json' );
		$cache_key = $integration->get_updates_cache_key();

		$stored = null;

		Functions\expect( 'get_site_transient' )
			->once()
			->with( $cache_key )
			->andReturn( false );

		Functions\expect( 'wp_remote_request' )->once()->andReturn( [] );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( $fixture );

		Functions\expect( 'set_site_transient' )
			->once()
			->with(
				$cache_key,
				\Mockery::on( function ( $value ) use ( &$stored ) {
					$stored = $value;
					return is_array( $value ) && ! empty( $value['updates'] );
				} ),
				600
			)
			->andReturn( true );

		$result = $integration->client->updates();

		$this->assertIsArray( $result );
		$this->assertNotNull( $stored );
		$this->assertArrayHasKey( 'updates', $stored );
	}

	/** @test */
	public function updates_does_not_cache_wp_error_response() {
		$integration = $this->create_integration();
		$this->stub_http_dependencies();

		$cache_key = $integration->get_updates_cache_key();

		Functions\expect( 'get_site_transient' )
			->once()
			->with( $cache_key )
			->andReturn( false );

		Functions\expect( 'wp_remote_request' )
			->once()
			->andReturn( new WP_Error( 'http_error', 'Timeout' ) );

		Functions\expect( 'set_site_transient' )->never();

		$result = $integration->client->updates();

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/** @test */
	public function updates_skips_cache_when_period_is_zero() {
		$integration = $this->create_integration();
		$this->stub_http_dependencies();

		$fixture = $this->load_fixture_raw( 'updates-available.json' );

		Functions\expect( 'get_site_transient' )->never();
		Functions\expect( 'set_site_transient' )->never();

		Functions\expect( 'wp_remote_request' )->once()->andReturn( [] );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( $fixture );

		$result = $integration->client->updates( 0 );

		$this->assertIsArray( $result );
		$this->assertSame( '1.3.0', $result['updates']['new_version'] );
	}

	/** @test */
	public function updates_uses_custom_cache_period() {
		$integration = $this->create_integration();
		$this->stub_http_dependencies();

		$fixture   = $this->load_fixture_raw( 'updates-available.json' );
		$cache_key = $integration->get_updates_cache_key();

		Functions\expect( 'get_site_transient' )
			->once()
			->with( $cache_key )
			->andReturn( false );

		Functions\expect( 'wp_remote_request' )->once()->andReturn( [] );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( $fixture );

		Functions\expect( 'set_site_transient' )
			->once()
			->with( $cache_key, \Mockery::type( 'array' ), 300 )
			->andReturn( true );

		$result = $integration->client->updates( 300 );

		$this->assertIsArray( $result );
		$this->assertSame( '1.3.0', $result['updates']['new_version'] );
	}

	/** @test */
	public function details_returns_cached_response_without_api_call() {
		$integration = $this->create_integration();

		$cached_response = $this->load_fixture( 'details-success.json' );

		Functions\expect( 'get_site_transient' )
			->once()
			->with( $integration->get_details_cache_key() )
			->andReturn( $cached_response );

		Functions\expect( 'wp_remote_request' )->never();

		$result = $integration->client->details();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'details', $result );
	}

	/** @test */
	public function details_caches_successful_api_response() {
		$integration = $this->create_integration();
		$this->stub_http_dependencies();

		$fixture   = $this->load_fixture_raw( 'details-success.json' );
		$cache_key = $integration->get_details_cache_key();

		$stored = null;

		Functions\expect( 'get_site_transient' )
			->once()
			->with( $cache_key )
			->andReturn( false );

		Functions\expect( 'wp_remote_request' )->once()->andReturn( [] );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( $fixture );

		Functions\expect( 'set_site_transient' )
			->once()
			->with(
				$cache_key,
				\Mockery::on( function ( $value ) use ( &$stored ) {
					$stored = $value;
					return is_array( $value ) && ! empty( $value['details'] );
				} ),
				600
			)
			->andReturn( true );

		$result = $integration->client->details();

		$this->assertIsArray( $result );
		$this->assertNotNull( $stored );
		$this->assertArrayHasKey( 'details', $stored );
	}

	/** @test */
	public function details_does_not_cache_wp_error_response() {
		$integration = $this->create_integration();
		$this->stub_http_dependencies();

		$cache_key = $integration->get_details_cache_key();

		Functions\expect( 'get_site_transient' )
			->once()
			->with( $cache_key )
			->andReturn( false );

		Functions\expect( 'wp_remote_request' )
			->once()
			->andReturn( new WP_Error( 'http_error', 'Timeout' ) );

		Functions\expect( 'set_site_transient' )->never();

		$result = $integration->client->details();

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/** @test */
	public function details_skips_cache_when_period_is_zero() {
		$integration = $this->create_integration();
		$this->stub_http_dependencies();

		$fixture = $this->load_fixture_raw( 'details-success.json' );

		Functions\expect( 'get_site_transient' )->never();
		Functions\expect( 'set_site_transient' )->never();

		Functions\expect( 'wp_remote_request' )->once()->andReturn( [] );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( $fixture );

		$result = $integration->client->details( 0 );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'details', $result );
	}
}
