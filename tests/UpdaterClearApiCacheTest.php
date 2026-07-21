<?php
namespace Shazzad\PluginUpdater\Tests;

use Brain\Monkey\Functions;
use Mockery;

class UpdaterClearApiCacheTest extends TestCase {

	/** @test */
	public function clear_api_cache_deletes_both_response_caches() {
		$integration = $this->create_integration();

		$deleted_keys = [];

		Functions\expect( 'delete_site_transient' )
			->twice()
			->with( Mockery::on( function ( $key ) use ( &$deleted_keys ) {
				$deleted_keys[] = $key;
				return true;
			} ) );

		$integration->updater->clear_api_cache();

		$this->assertContains( $integration->get_updates_cache_key(), $deleted_keys );
		$this->assertContains( $integration->get_details_cache_key(), $deleted_keys );
	}
}
