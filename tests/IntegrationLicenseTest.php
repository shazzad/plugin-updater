<?php
namespace Shazzad\PluginUpdater\Tests;

use Brain\Monkey\Functions;

class IntegrationLicenseTest extends TestCase {

	/** @test */
	public function get_license_option_returns_expected_key() {
		$integration = $this->create_integration();

		// license_name is sanitize_key( "my-plugin42" ) = "my-plugin42"
		$this->assertSame( 'my-plugin42_code', $integration->get_license_option() );
	}

	/** @test */
	public function get_license_option_varies_by_product() {
		$integration = $this->create_integration( [
			'product_file' => 'other-plugin/other-plugin.php',
			'product_id'   => '99',
		] );

		$this->assertSame( 'other-plugin99_code', $integration->get_license_option() );
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
}
