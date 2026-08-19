<?php
namespace Shazzad\PluginUpdater\Tests\V2;

use Brain\Monkey\Functions;

class IntegrationMigrationTest extends TestCase {

	/** @test */
	public function clone_copies_code_and_data_to_uid_keys() {
		$integration = $this->create_integration( [ 'license' => true ] );

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

		$this->assertSame( 'ABC-123-DEF', $updates['prod_testuid_code'] );
		$this->assertSame( [ 'status' => 'active' ], $updates['prod_testuid_data'] );
	}

	/** @test */
	public function clone_skips_when_uid_key_already_exists() {
		$integration = $this->create_integration( [ 'license' => true ] );

		Functions\when( 'get_option' )->alias( function ( $key ) {
			if ( 'prod_testuid_code' === $key ) {
				return 'ALREADY-THERE';
			}
			return 'my-plugin42_code' === $key ? 'ABC-123-DEF' : false;
		} );

		Functions\expect( 'update_option' )->never();

		$integration->setProductUid( 'prod_testuid' );
	}

	/** @test */
	public function clone_skips_when_license_disabled() {
		$integration = $this->create_integration( [ 'license' => false ] );

		Functions\expect( 'get_option' )->never();
		Functions\expect( 'update_option' )->never();

		$integration->setProductUid( 'prod_testuid' );
	}

	/** @test */
	public function clone_skips_when_no_legacy_license() {
		$integration = $this->create_integration( [ 'license' => true ] );

		Functions\when( 'get_option' )->justReturn( false );
		Functions\expect( 'update_option' )->never();

		$integration->setProductUid( 'prod_testuid' );
	}

	/** @test */
	public function clone_copies_code_only_when_no_legacy_data() {
		$integration = $this->create_integration( [ 'license' => true ] );

		Functions\when( 'get_option' )->alias( function ( $key ) {
			return 'my-plugin42_code' === $key ? 'ABC-123-DEF' : false;
		} );

		$updates = [];
		Functions\when( 'update_option' )->alias( function ( $key, $value ) use ( &$updates ) {
			$updates[ $key ] = $value;
			return true;
		} );

		$integration->setProductUid( 'prod_testuid' );

		$this->assertSame( [ 'prod_testuid_code' => 'ABC-123-DEF' ], $updates );
	}

	/** @test */
	public function delete_license_code_removes_both_copies_when_uid_set() {
		$integration = $this->create_integration( [ 'license' => true ] );

		// Short-circuit the clone triggered by setProductUid().
		Functions\when( 'get_option' )->alias( function ( $key ) {
			return 'prod_testuid_code' === $key ? 'ABC-123-DEF' : false;
		} );

		$integration->setProductUid( 'prod_testuid' );

		$deleted = [];
		Functions\when( 'delete_option' )->alias( function ( $key ) use ( &$deleted ) {
			$deleted[] = $key;
			return true;
		} );

		$this->assertTrue( $integration->delete_license_code() );
		$this->assertSame( [ 'my-plugin42_code', 'prod_testuid_code' ], $deleted );
	}

	/** @test */
	public function delete_license_data_removes_both_copies_when_uid_set() {
		$integration = $this->create_integration( [ 'license' => true ] );

		Functions\when( 'get_option' )->alias( function ( $key ) {
			return 'prod_testuid_code' === $key ? 'ABC-123-DEF' : false;
		} );

		$integration->setProductUid( 'prod_testuid' );

		$deleted = [];
		Functions\when( 'delete_option' )->alias( function ( $key ) use ( &$deleted ) {
			$deleted[] = $key;
			return true;
		} );

		$this->assertTrue( $integration->delete_license_data() );
		$this->assertSame( [ 'my-plugin42_data', 'prod_testuid_data' ], $deleted );
	}
}
