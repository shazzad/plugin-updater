<?php
namespace Shazzad\PluginUpdater\Tests\V4;

use Brain\Monkey\Functions;
use Shazzad\PluginUpdater\V4\Insights\Client as InsightsClient;
use Shazzad\PluginUpdater\V4\Insights\Collector;
use Shazzad\PluginUpdater\V4\Insights\Consent;
use Shazzad\PluginUpdater\V4\Insights\Notice;
use Shazzad\PluginUpdater\V4\Insights\Scheduler;
use Shazzad\PluginUpdater\V4\Integration;

/**
 * The commercial path: V4\Integration builds the Insights parts in
 * commercial mode, tracks without asking, carries the license key, and the
 * hourly license sync no longer pings.
 *
 * Extends the Insights TestCase for its full collector environment and its
 * recorded options / cron / HTTP / hooks.
 */
class IntegrationInsightsTest extends TestCase {

	/** @var array list of URLs passed to wp_remote_request() */
	protected $requests = [];

	/** @var string[] site transients deleted */
	protected $deleted_transients = [];

	protected function setUp(): void {
		parent::setUp();

		$test = $this;

		// Update-API side, which the Insights base does not stub.
		Functions\when( 'add_query_arg' )->alias( function ( $args, $url ) {
			return $url . '?' . http_build_query( $args );
		} );
		Functions\when( 'wp_remote_request' )->alias( function ( $url ) use ( $test ) {
			$test->requests[] = $url;
			return [
				'response' => [ 'code' => 200 ],
				'body'     => '{"license":{"status":"active"}}',
			];
		} );
		Functions\when( 'get_site_transient' )->justReturn( false );
		Functions\when( 'set_site_transient' )->justReturn( true );
		Functions\when( 'delete_site_transient' )->alias( function ( $key ) use ( $test ) {
			$test->deleted_transients[] = $key;
			return true;
		} );
	}

	/**
	 * Commercial config: the V2 array against the update API.
	 */
	private function commercial_config( array $overrides = [] ): array {
		return array_merge(
			[
				'api_url'     => 'https://repo.example.com/wp-json/wp-repo/v4',
				'file'        => WP_PLUGIN_DIR . '/my-plugin/my-plugin.php',
				'product_uid' => 'prod_abc',
				'product_id'  => '12',
				'license'     => true,
				'menu'        => [ 'parent' => 'options-general.php' ],
			],
			$overrides
		);
	}

	private function create_integration( array $overrides = [] ): Integration {
		return new Integration( $this->commercial_config( $overrides ) );
	}

	/**
	 * Runs every callback registered on a hook, like do_action().
	 */
	private function fire( string $hook, ...$args ): void {
		foreach ( $this->hooks[ $hook ] ?? [] as $callback ) {
			call_user_func_array( $callback, $args );
		}
	}

	/**
	 * Every object registered as a hook callback.
	 *
	 * @return object[]
	 */
	private function hooked_objects(): array {
		$objects = [];

		foreach ( $this->hooks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback ) && is_object( $callback[0] ) ) {
					$objects[] = $callback[0];
				}
			}
		}

		return $objects;
	}

	/** @test */
	public function insights_use_the_same_api_url_as_updates() {
		$integration = $this->create_integration();

		$this->assertInstanceOf( Consent::class, $integration->insights_consent );
		$this->assertInstanceOf( Collector::class, $integration->insights_collector );
		$this->assertInstanceOf( InsightsClient::class, $integration->insights_client );
		$this->assertInstanceOf( Scheduler::class, $integration->insights_scheduler );
		$this->assertSame(
			'https://repo.example.com/wp-json/wp-repo/v4/plugins/prod_abc/track',
			$integration->insights_client->get_url( 'track' )
		);
		$this->assertSame( [], $this->doing_it_wrong );
	}

	/** @test */
	public function insights_url_tolerates_a_trailing_slash_and_uses_the_uid() {
		$integration = $this->create_integration(
			[
				'api_url'     => 'https://repo.example.com/wp-json/wp-repo/v4/',
				'product_uid' => 'prod_abc',
			]
		);

		$this->assertSame(
			'https://repo.example.com/wp-json/wp-repo/v4/plugins/prod_abc/track',
			$integration->insights_client->get_url( 'track' )
		);
	}

	/** @test */
	public function insights_api_url_is_no_longer_a_config_key() {
		$integration = $this->create_integration( [ 'insights_api_url' => 'https://insights.example.net/v1' ] );

		$this->assertCount( 1, $this->doing_it_wrong );
		$this->assertStringContainsString( 'Unrecognized config key "insights_api_url"', $this->doing_it_wrong[0] );
		$this->assertSame(
			'https://repo.example.com/wp-json/wp-repo/v4/plugins/prod_abc/track',
			$integration->insights_client->get_url( 'track' )
		);
	}

	/** @test */
	public function a_v3_api_url_draws_a_notice_and_leaves_insights_off() {
		$integration = $this->create_integration( [ 'api_url' => 'https://repo.example.com/wp-json/wp-repo/v3' ] );

		$this->assertCount( 1, $this->doing_it_wrong );
		$this->assertStringContainsString( 'wp-repo/v4', $this->doing_it_wrong[0] );
		$this->assertNull( $integration->insights_client, 'No Insights against v3: its track route does not exist.' );
		$this->assertNull( $integration->insights_scheduler );
		$this->assertArrayNotHasKey( 'wprepo_insights_track_my-plugin', $this->hooks );
		$this->assertNotNull( $integration->updater );
	}

	/** @test */
	public function an_empty_api_url_leaves_insights_off_without_breaking_the_rest() {
		$integration = $this->create_integration( [ 'api_url' => '' ] );

		$this->assertCount( 1, $this->doing_it_wrong );
		$this->assertStringContainsString( 'Missing required config key "api_url"', $this->doing_it_wrong[0] );

		$this->assertNull( $integration->insights_consent );
		$this->assertNull( $integration->insights_collector );
		$this->assertNull( $integration->insights_client );
		$this->assertNull( $integration->insights_scheduler );
		$this->assertArrayNotHasKey( 'wprepo_insights_track_my-plugin', $this->hooks );

		// The update side is still fully wired.
		$this->assertNotNull( $integration->updater );
		$this->assertNotNull( $integration->tracker );
		$this->assertNotNull( $integration->admin );

		// Setters must not trip over the missing collector.
		$integration->setMeta( [ 'a' => 1 ] )->setMetaCallback( function () {
			return [];
		} );

		// Activation still refreshes caches but sends nothing.
		$this->fire( 'activate_my-plugin/my-plugin.php' );
		$this->assertSame( [], $this->http );
		$this->assertContains( 'prod_abc_updates_cache', $this->deleted_transients );
	}

	/** @test */
	public function a_missing_uid_draws_a_notice_and_sends_nothing_at_all() {
		$integration = $this->create_integration( [ 'product_uid' => '' ] );

		$this->assertCount( 1, $this->doing_it_wrong );
		$this->assertStringContainsString( 'Missing required config key "product_uid"', $this->doing_it_wrong[0] );

		// A numeric id alone would only reach 404 rest_no_route on v4.
		$this->assertNull( $integration->insights_consent );
		$this->assertNull( $integration->insights_collector );
		$this->assertNull( $integration->insights_client );
		$this->assertNull( $integration->insights_scheduler );
		$this->assertArrayNotHasKey( 'wprepo_insights_track_my-plugin', $this->hooks );
		$this->assertNotNull( $integration->updater );

		$this->options['my-plugin12_code'] = 'LIC-12';

		$this->assertArrayHasKey( 'wprepo_sync_license_data_my-plugin12', $this->hooks );
		$this->fire( 'activate_my-plugin/my-plugin.php' );
		$this->fire( 'wprepo_sync_license_data_my-plugin12' );
		$this->fire( 'deactivate_my-plugin/my-plugin.php' );

		$this->assertSame( [], $this->http, 'No track without a uid.' );
		$this->assertSame( [], $this->requests, 'No update/license call without a uid.' );
		// The refused call is not a verdict on the license.
		$this->assertArrayNotHasKey( 'my-plugin12_data', $this->options );
	}

	/** @test */
	public function set_product_uid_after_construction_turns_insights_on() {
		$integration = $this->create_integration( [ 'product_uid' => '' ] );
		$this->assertNull( $integration->insights_client );

		$integration->setProductUid( 'prod_abc' );

		$this->assertInstanceOf( InsightsClient::class, $integration->insights_client );
		$this->assertInstanceOf( Scheduler::class, $integration->insights_scheduler );
		$this->assertSame(
			'https://repo.example.com/wp-json/wp-repo/v4/plugins/prod_abc/track',
			$integration->insights_client->get_url( 'track' )
		);

		// A later uid change follows through to the Insights URL.
		$integration->setProductUid( 'prod_xyz' );
		$this->assertSame(
			'https://repo.example.com/wp-json/wp-repo/v4/plugins/prod_xyz/track',
			$integration->insights_client->get_url( 'track' )
		);
	}

	/** @test */
	public function set_product_uid_keeps_insights_off_on_a_v3_api_url() {
		$integration = $this->create_integration(
			[
				'api_url'     => 'https://repo.example.com/wp-json/wp-repo/v3',
				'product_uid' => '',
			]
		);

		$integration->setProductUid( 'prod_abc' );

		$this->assertNull( $integration->insights_client );
		$this->assertNull( $integration->insights_scheduler );
	}

	/** @test */
	public function commercial_mode_is_granted_and_never_asks() {
		$integration = $this->create_integration();

		$this->assertSame( Consent::MODE_COMMERCIAL, $integration->insights_consent->mode );
		$this->assertTrue( $integration->insights_consent->is_granted() );
		$this->assertArrayNotHasKey( 'my-plugin_insights_consent', $this->options );

		foreach ( $this->hooked_objects() as $object ) {
			$this->assertNotInstanceOf( Notice::class, $object );
		}

		foreach ( get_object_vars( $integration ) as $property => $value ) {
			$this->assertNotInstanceOf( Notice::class, $value, "Integration::\${$property}" );
		}
	}

	/**
	 * Runtime proof that the commercial path never even loads the consent
	 * notice class: in a fresh process, building a fully licensed
	 * Integration and sending a track leaves Notice unloaded.
	 *
	 * @test
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function commercial_path_never_loads_the_notice_class() {
		$this->options['prod_abc_code'] = 'LIC-12';

		$integration = $this->create_integration();
		$integration->insights_scheduler->run_daily();

		$this->assertCount( 1, $this->http );
		$this->assertFalse( class_exists( Notice::class, false ), 'Insights\\Notice was loaded on the commercial path.' );
		$this->assertFalse( class_exists( \Shazzad\PluginUpdater\V4\Insights::class, false ), 'The free entry point was loaded on the commercial path.' );
	}

	/** @test */
	public function collect_includes_the_stored_license_key() {
		$this->options['prod_abc_code'] = 'LIC-12';

		$data = $this->create_integration()->insights_collector->collect();

		$this->assertSame( 'LIC-12', $data['license'] );
		$this->assertSame( 'commercial', $data['mode'] );
		$this->assertSame( [ 'email' => '', 'name' => 'Site Admin' ], $data['admin'] );
	}

	/** @test */
	public function collect_sends_an_empty_license_when_licensed_but_none_is_stored() {
		// Licensing on, no code: '' tells the server to unbind the install and
		// free its seat (v4 track license rule).
		$data = $this->create_integration()->insights_collector->collect();

		$this->assertArrayHasKey( 'license', $data );
		$this->assertSame( '', $data['license'] );
	}

	/** @test */
	public function a_deleted_license_goes_out_as_an_empty_license_on_the_next_track() {
		$this->options['prod_abc_code'] = 'LIC-12';
		$integration                    = $this->create_integration();

		$integration->insights_scheduler->send( 'daily' );
		$this->assertSame( 'LIC-12', $this->http_body( 0 )['license'] );

		unset( $this->options['prod_abc_code'] );
		$integration->insights_scheduler->send( 'daily' );
		$this->assertSame( '', $this->http_body( 1 )['license'] );
	}

	/** @test */
	public function collect_omits_the_license_when_licensing_is_off() {
		$this->options['prod_abc_code'] = 'LIC-12';

		$data = $this->create_integration( [ 'license' => false ] )->insights_collector->collect();

		$this->assertArrayNotHasKey( 'license', $data );
	}

	/** @test */
	public function daily_track_sends_with_license_and_without_any_consent_option() {
		$this->options['prod_abc_code'] = 'LIC-12';

		$integration = $this->create_integration();

		$this->fire( 'wprepo_insights_track_my-plugin' );

		$this->assertCount( 1, $this->http );
		$this->assertSame( 'https://repo.example.com/wp-json/wp-repo/v4/plugins/prod_abc/track', $this->http[0][0] );
		$this->assertSame( 'daily', $this->http_body()['event'] );
		$this->assertSame( 'commercial', $this->http_body()['mode'] );
		$this->assertSame( 'LIC-12', $this->http_body()['license'] );
		$this->assertNotSame( '', $this->http_body()['token'] );
		$this->assertArrayNotHasKey( 'my-plugin_insights_consent', $this->options );
		$this->assertTrue( $integration->insights_consent->is_granted() );
	}

	/** @test */
	public function admin_init_self_heals_the_daily_cron_for_in_place_updates() {
		$this->create_integration();

		$this->fire( 'admin_init' );

		$this->assertSame( 'daily', $this->cron['wprepo_insights_track_my-plugin'] );
	}

	/** @test */
	public function hourly_license_sync_checks_the_license_and_never_pings() {
		$this->options['prod_abc_code']             = 'LIC-12';
		$this->options['my-plugin_insights_last_send'] = time() - 3600; // daily track not due.

		$integration = $this->create_integration();

		$this->fire( 'wprepo_sync_license_data_my-plugin12' );

		$this->assertCount( 1, $this->requests );
		$this->assertStringContainsString( '/wp-repo/v4/plugins/prod_abc/check_license', $this->requests[0] );
		$this->assertSame( [ 'status' => 'active' ], $this->options['prod_abc_data'] );
		$this->assertSame( [], $this->http, 'The hourly sync must not POST while the daily track is not due.' );

		foreach ( array_merge( $this->requests, array_column( $this->http, 0 ) ) as $url ) {
			$this->assertStringNotContainsString( '/ping', $url );
		}

		$this->assertFalse( method_exists( $integration->client, 'ping' ) );
	}

	/** @test */
	public function hourly_license_sync_without_a_license_makes_no_license_request() {
		$this->options['my-plugin_insights_last_send'] = time() - 3600;

		$this->create_integration();

		$this->fire( 'wprepo_sync_license_data_my-plugin12' );

		$this->assertSame( [], $this->requests );
		$this->assertSame( [], $this->http );
	}

	/**
	 * A plugin updated in place from V2 on a site nobody opens wp-admin on:
	 * no activation, no admin_init — only the hourly event Updater schedules
	 * on `init`. It must still check in daily, or the server marks the
	 * install inactive after 7 days.
	 *
	 * @test
	 */
	public function hourly_sync_sends_the_overdue_daily_track_and_self_heals_the_cron() {
		$this->create_integration( [ 'license' => false ] );

		$this->assertArrayNotHasKey( 'wprepo_insights_track_my-plugin', $this->cron );

		$this->fire( 'wprepo_sync_license_data_my-plugin12' );

		$this->assertCount( 1, $this->http );
		$this->assertSame( 'https://repo.example.com/wp-json/wp-repo/v4/plugins/prod_abc/track', $this->http[0][0] );
		$this->assertSame( 'daily', $this->http_body()['event'] );
		$this->assertSame( 'daily', $this->cron['wprepo_insights_track_my-plugin'], 'The daily event was not re-created.' );
		$this->assertEqualsWithDelta( time(), $this->options['my-plugin_insights_last_send'], 5 );
	}

	/** @test */
	public function hourly_sync_and_daily_cron_never_double_send() {
		$this->options['prod_abc_code'] = 'LIC-12';

		$this->create_integration();

		$this->fire( 'wprepo_sync_license_data_my-plugin12' );
		$this->fire( 'wprepo_sync_license_data_my-plugin12' );
		$this->fire( 'wprepo_insights_track_my-plugin' );

		$this->assertCount( 1, $this->http, 'MIN_INTERVAL must hold across the hourly and daily paths.' );
		$this->assertSame( 'LIC-12', $this->http_body()['license'] );
		$this->assertCount( 2, $this->requests, 'Each hourly run still checks the license.' );
	}

	/** @test */
	public function hourly_sync_without_an_api_url_sends_nothing() {
		$this->options['prod_abc_code'] = 'LIC-12';

		$integration = $this->create_integration( [ 'api_url' => '' ] );

		$this->assertNull( $integration->insights_scheduler );

		$this->fire( 'wprepo_sync_license_data_my-plugin12' );

		$this->assertSame( [], $this->requests );
		$this->assertSame( [], $this->http );
		$this->assertSame( [], $this->cron );
		$this->assertArrayNotHasKey( 'prod_abc_data', $this->options, 'Not an invalid_license verdict.' );
	}

	/** @test */
	public function activation_sends_exactly_one_activate_track_and_refreshes_caches() {
		$this->options['prod_abc_code'] = 'LIC-12';

		$integration = $this->create_integration();

		$this->fire( 'activate_my-plugin/my-plugin.php' );

		$this->assertCount( 1, $this->http );
		$this->assertSame( 'activate', $this->http_body()['event'] );
		$this->assertSame( 'active', $this->http_body()['plugin_status'] );
		$this->assertSame( 'LIC-12', $this->http_body()['license'] );
		$this->assertSame( [], $this->requests );
		$this->assertSame( 'daily', $this->cron['wprepo_insights_track_my-plugin'] );
		$this->assertSame( 'active', $integration->product_status );
		$this->assertContains( 'prod_abc_updates_cache', $this->deleted_transients );
	}

	/** @test */
	public function deactivation_sends_exactly_one_deactivate_track_and_clears_the_cron() {
		$integration = $this->create_integration();
		$this->cron['wprepo_insights_track_my-plugin'] = 'daily';

		$this->fire( 'deactivate_my-plugin/my-plugin.php' );

		$this->assertCount( 1, $this->http );
		$this->assertSame( 'deactivate', $this->http_body()['event'] );
		$this->assertSame( 'inactive', $this->http_body()['plugin_status'] );
		$this->assertArrayNotHasKey( 'wprepo_insights_track_my-plugin', $this->cron );
		$this->assertSame( 'inactive', $integration->product_status );
	}

	/** @test */
	public function meta_from_config_and_setters_reaches_the_payload() {
		$integration = $this->create_integration(
			[
				'meta'          => [ 'from_config' => 'yes' ],
				'meta_callback' => function () {
					return [ 'from_callback' => 1 ];
				},
			]
		);

		$this->assertSame(
			[ 'from_callback' => 1, 'from_config' => 'yes' ],
			$integration->insights_collector->collect()['meta']
		);

		$integration
			->setMeta(
				[
					'closure' => function () {
						return 'resolved';
					},
					'plain'   => 'time',
				]
			)
			->setMetaCallback(
				function () {
					return [ 'later' => true ];
				}
			);

		$this->assertSame(
			[ 'later' => true, 'closure' => 'resolved', 'plain' => 'time' ],
			$integration->insights_collector->collect()['meta']
		);
		$this->assertSame( 'time', $integration->meta['plain'] );
	}
}
