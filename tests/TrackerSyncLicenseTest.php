<?php
namespace Shazzad\PluginUpdater\Tests;

use Brain\Monkey\Functions;
use WP_Error;

class TrackerSyncLicenseTest extends TestCase {

	/**
	 * Swap in a client whose check_license() returns a fixed response.
	 *
	 * @param mixed $check_license_response Value check_license() should return.
	 * @return object The stand-in client.
	 */
	private function stub_client( $check_license_response ) {
		return new class( $check_license_response ) {
			private $response;

			public function __construct( $response ) {
				$this->response = $response;
			}

			public function ping() {
				return [ 'message' => 'Ping successful' ];
			}

			public function check_license( $license = '' ) {
				return $this->response;
			}
		};
	}

	/** @test */
	public function invalid_license_marks_data_invalid_and_keeps_the_code() {
		$integration = $this->create_integration( [ 'license_enabled' => true ] );

		Functions\when( 'get_option' )->alias( function ( $key ) {
			return 'my-plugin42_code' === $key ? 'MY-LICENSE-KEY' : false;
		} );

		$integration->client = $this->stub_client(
			new WP_Error( 'invalid_license', 'Invalid license code' )
		);

		// The stored code must survive: the server returns invalid_license for
		// any unmatched code/product pair, not only for a revoked license.
		Functions\expect( 'delete_option' )->never();

		$captured = null;

		Functions\expect( 'update_option' )
			->once()
			->with( 'my-plugin42_data', \Mockery::on( function ( $data ) use ( &$captured ) {
				$captured = $data;
				return true;
			} ) )
			->andReturn( true );

		$integration->tracker->sync_license_data();

		$this->assertSame( [ 'status' => 'invalid' ], $captured );
		$this->assertSame( 'MY-LICENSE-KEY', $integration->get_license_code() );
	}

	/** @test */
	public function transient_api_failure_leaves_stored_license_untouched() {
		$integration = $this->create_integration( [ 'license_enabled' => true ] );

		Functions\when( 'get_option' )->alias( function ( $key ) {
			return 'my-plugin42_code' === $key ? 'MY-LICENSE-KEY' : false;
		} );

		// What a 502 decodes to: no JSON body, so no license verdict at all.
		$integration->client = $this->stub_client(
			new WP_Error( 'wprepo_api_fail', 'No response from update server' )
		);

		Functions\expect( 'delete_option' )->never();
		Functions\expect( 'update_option' )->never();

		$integration->tracker->sync_license_data();

		$this->assertSame( 'MY-LICENSE-KEY', $integration->get_license_code() );
	}

	/** @test */
	public function valid_license_stores_returned_data() {
		$integration = $this->create_integration( [ 'license_enabled' => true ] );

		Functions\when( 'get_option' )->alias( function ( $key ) {
			return 'my-plugin42_code' === $key ? 'MY-LICENSE-KEY' : false;
		} );

		$integration->client = $this->stub_client(
			[ 'license' => [ 'status' => 'active', 'buyer_email' => 'user@example.com' ] ]
		);

		Functions\expect( 'delete_option' )->never();

		$captured = null;

		Functions\expect( 'update_option' )
			->once()
			->with( 'my-plugin42_data', \Mockery::on( function ( $data ) use ( &$captured ) {
				$captured = $data;
				return true;
			} ) )
			->andReturn( true );

		$integration->tracker->sync_license_data();

		$this->assertSame(
			[ 'status' => 'active', 'buyer_email' => 'user@example.com' ],
			$captured
		);
	}
}
