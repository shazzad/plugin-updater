<?php
namespace Shazzad\PluginUpdater\Tests;

class IntegrationProductUidTest extends TestCase {

	/** @test */
	public function set_product_uid_is_fluent() {
		$integration = $this->create_integration();

		$this->assertSame( $integration, $integration->setProductUid( 'prod_testuid' ) );
	}

	/** @test */
	public function api_product_key_is_id_without_uid() {
		$integration = $this->create_integration();

		$this->assertSame( '42', $integration->get_api_product_key() );
	}

	/** @test */
	public function api_product_key_is_uid_when_set() {
		$integration = $this->create_integration();
		$integration->setProductUid( 'prod_testuid' );

		$this->assertSame( 'prod_testuid', $integration->get_api_product_key() );
	}

	/** @test */
	public function storage_keys_stay_id_based_without_uid() {
		$integration = $this->create_integration();

		$this->assertSame( 'my-plugin42_code', $integration->get_license_code_key() );
		$this->assertSame( 'my-plugin42_data', $integration->get_license_data_key() );
		$this->assertSame( 'my-plugin42_updates_cache', $integration->get_updates_cache_key() );
		$this->assertSame( 'my-plugin42_details_cache', $integration->get_details_cache_key() );
	}

	/** @test */
	public function storage_keys_become_uid_based_when_uid_set() {
		$integration = $this->create_integration();
		$integration->setProductUid( 'prod_testuid' );

		$this->assertSame( 'my-pluginprod_testuid_code', $integration->get_license_code_key() );
		$this->assertSame( 'my-pluginprod_testuid_data', $integration->get_license_data_key() );
		$this->assertSame( 'my-pluginprod_testuid_updates_cache', $integration->get_updates_cache_key() );
		$this->assertSame( 'my-pluginprod_testuid_details_cache', $integration->get_details_cache_key() );
	}

	/** @test */
	public function license_name_property_stays_id_based_for_bc() {
		$integration = $this->create_integration();
		$integration->setProductUid( 'prod_testuid' );

		$this->assertSame( 'my-plugin42', $integration->license_name );
	}
}
