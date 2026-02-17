<?php
namespace Shazzad\PluginUpdater\Tests;

use Brain\Monkey\Functions;
use WP_Error;

class IntegrationApiRequestTest extends TestCase {

	/**
	 * Stub the common WP functions used by api_request().
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

	/** @test */
	public function success_returns_parsed_body() {
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

		$result = $integration->api_request( 'ping' );

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

		$result = $integration->api_request( 'ping' );

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

		$result = $integration->api_request( 'updates' );

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

		$result = $integration->api_request( 'updates' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'product_not_exists', $result->get_error_code() );
		$this->assertSame( 'Requested product does not exists', $result->get_error_message() );
	}

	/** @test */
	public function error_500_without_code_uses_fallback() {
		$integration = $this->create_integration();
		$this->stub_api_dependencies();

		$body = json_encode( [ 'message' => 'Internal error' ] );

		Functions\expect( 'wp_remote_request' )->once()->andReturn( [] );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 500 );
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( $body );

		$result = $integration->api_request( 'updates' );

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

		$result = $integration->api_request( 'updates' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wprepo_api_error', $result->get_error_code() );
		$this->assertSame( 'API request failed', $result->get_error_message() );
	}

	/** @test */
	public function license_included_when_enabled() {
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

		$integration->api_request( 'ping' );

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

		$integration->api_request( 'ping', 'EXPLICIT-KEY' );

		$this->assertStringContainsString( 'license=EXPLICIT-KEY', $captured_url );
		$this->assertStringNotContainsString( 'stored-key', $captured_url );
	}

	/** @test */
	public function check_license_returns_license_data() {
		$integration = $this->create_integration();
		$this->stub_api_dependencies();

		$fixture = $this->load_fixture_raw( 'check-license-success.json' );

		Functions\expect( 'wp_remote_request' )->once()->andReturn( [] );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( $fixture );

		$result = $integration->api_request( 'check_license' );

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

		$result = $integration->api_request( 'updates' );

		$this->assertIsArray( $result );
		$this->assertSame( '1.3.0', $result['updates']['new_version'] );
	}
}
