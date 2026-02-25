<?php
namespace Shazzad\PluginUpdater\Tests;

use Brain\Monkey\Functions;

class IntegrationLicenseTest extends TestCase {

	/** @test */
	public function get_license_code_key_returns_expected_key() {
		$integration = $this->create_integration();

		// license_name is sanitize_key( "my-plugin42" ) = "my-plugin42"
		$this->assertSame( 'my-plugin42_code', $integration->get_license_code_key() );
	}

	/** @test */
	public function get_license_code_key_varies_by_product() {
		$integration = $this->create_integration( [
			'product_file' => 'other-plugin/other-plugin.php',
			'product_id'   => '99',
		] );

		$this->assertSame( 'other-plugin99_code', $integration->get_license_code_key() );
	}

	/** @test */
	public function get_license_code_returns_stored_value() {
		$integration = $this->create_integration();

		Functions\expect( 'get_option' )
			->once()
			->with( 'my-plugin42_code' )
			->andReturn( 'ABC-123-DEF' );

		$this->assertSame( 'ABC-123-DEF', $integration->get_license_code() );
	}

	/** @test */
	public function get_license_code_returns_false_when_missing() {
		$integration = $this->create_integration();

		Functions\expect( 'get_option' )
			->once()
			->with( 'my-plugin42_code' )
			->andReturn( false );

		$this->assertFalse( $integration->get_license_code() );
	}

	/** @test */
	public function has_license_code_returns_true_when_set() {
		$integration = $this->create_integration();

		Functions\expect( 'get_option' )
			->once()
			->with( 'my-plugin42_code' )
			->andReturn( 'ABC-123-DEF' );

		$this->assertTrue( $integration->has_license_code() );
	}

	/** @test */
	public function has_license_code_returns_false_when_empty_string() {
		$integration = $this->create_integration();

		Functions\expect( 'get_option' )
			->once()
			->with( 'my-plugin42_code' )
			->andReturn( '' );

		$this->assertFalse( $integration->has_license_code() );
	}

	/** @test */
	public function has_license_code_returns_false_when_option_missing() {
		$integration = $this->create_integration();

		Functions\expect( 'get_option' )
			->once()
			->with( 'my-plugin42_code' )
			->andReturn( false );

		$this->assertFalse( $integration->has_license_code() );
	}

	/** @test */
	public function is_license_active_returns_true_when_status_active() {
		$integration = $this->create_integration();

		Functions\expect( 'get_option' )
			->once()
			->with( 'my-plugin42_data' )
			->andReturn( [ 'status' => 'active' ] );

		$this->assertTrue( $integration->is_license_active() );
	}

	/** @test */
	public function is_license_active_returns_false_when_expired() {
		$integration = $this->create_integration();

		Functions\expect( 'get_option' )
			->once()
			->with( 'my-plugin42_data' )
			->andReturn( [ 'status' => 'expired' ] );

		$this->assertFalse( $integration->is_license_active() );
	}

	/** @test */
	public function is_license_active_returns_false_when_empty() {
		$integration = $this->create_integration();

		Functions\expect( 'get_option' )
			->once()
			->with( 'my-plugin42_data' )
			->andReturn( '' );

		$this->assertFalse( $integration->is_license_active() );
	}

	/** @test */
	public function is_license_active_returns_false_when_status_missing() {
		$integration = $this->create_integration();

		Functions\expect( 'get_option' )
			->once()
			->with( 'my-plugin42_data' )
			->andReturn( [ 'some_other_key' => 'value' ] );

		$this->assertFalse( $integration->is_license_active() );
	}

	/** @test */
	public function get_license_renewal_url_replaces_placeholders() {
		$integration = $this->create_integration();

		Functions\when( 'get_option' )->alias( function ( $key ) {
			if ( 'my-plugin42_data' === $key ) {
				return [
					'renewal_url' => 'https://example.com/renew?license={license_code}&email={email}',
					'buyer_email' => 'user@example.com',
				];
			}
			if ( 'my-plugin42_code' === $key ) {
				return 'ABC-123-DEF';
			}
			return false;
		} );

		$this->assertSame(
			'https://example.com/renew?license=ABC-123-DEF&email=user@example.com',
			$integration->get_license_renewal_url()
		);
	}

	/** @test */
	public function get_license_renewal_url_returns_empty_when_no_url() {
		$integration = $this->create_integration();

		Functions\expect( 'get_option' )
			->with( 'my-plugin42_data' )
			->andReturn( [ 'status' => 'expired' ] );

		$this->assertSame( '', $integration->get_license_renewal_url() );
	}

	/** @test */
	public function get_license_renewal_url_handles_missing_email() {
		$integration = $this->create_integration();

		Functions\when( 'get_option' )->alias( function ( $key ) {
			if ( 'my-plugin42_data' === $key ) {
				return [
					'renewal_url' => 'https://example.com/renew?license={license_code}&email={email}',
				];
			}
			if ( 'my-plugin42_code' === $key ) {
				return 'ABC-123-DEF';
			}
			return false;
		} );

		$this->assertSame(
			'https://example.com/renew?license=ABC-123-DEF&email=',
			$integration->get_license_renewal_url()
		);
	}

	/** @test */
	public function get_license_renewal_url_handles_missing_license_code() {
		$integration = $this->create_integration();

		Functions\when( 'get_option' )->alias( function ( $key ) {
			if ( 'my-plugin42_data' === $key ) {
				return [
					'renewal_url' => 'https://example.com/renew?license={license_code}&email={email}',
					'buyer_email' => 'user@example.com',
				];
			}
			if ( 'my-plugin42_code' === $key ) {
				return false;
			}
			return false;
		} );

		$this->assertSame(
			'https://example.com/renew?license=&email=user@example.com',
			$integration->get_license_renewal_url()
		);
	}

	/** @test */
	public function delete_license_code_calls_delete_option() {
		$integration = $this->create_integration();

		Functions\expect( 'delete_option' )
			->once()
			->with( 'my-plugin42_code' )
			->andReturn( true );

		$this->assertTrue( $integration->delete_license_code() );
	}

	/** @test */
	public function delete_license_data_calls_delete_option() {
		$integration = $this->create_integration();

		Functions\expect( 'delete_option' )
			->once()
			->with( 'my-plugin42_data' )
			->andReturn( true );

		$this->assertTrue( $integration->delete_license_data() );
	}

	/** @test */
	public function get_license_renewal_url_returns_url_without_placeholders() {
		$integration = $this->create_integration();

		Functions\when( 'get_option' )->alias( function ( $key ) {
			if ( 'my-plugin42_data' === $key ) {
				return [
					'renewal_url' => 'https://example.com/renew',
				];
			}
			if ( 'my-plugin42_code' === $key ) {
				return 'ABC-123-DEF';
			}
			return false;
		} );

		$this->assertSame(
			'https://example.com/renew',
			$integration->get_license_renewal_url()
		);
	}

}
