<?php
namespace Shazzad\PluginUpdater\Tests\V4;

use Brain\Monkey\Functions;
use Shazzad\PluginUpdater\V4\Insights\Scheduler;
use Shazzad\PluginUpdater\V4\Integration;

/**
 * What the scheduler does after the server refuses a track: a 4xx waits
 * MIN_INTERVAL like a success (the hourly license sync must not re-POST the
 * same body every hour); `403 wprepo_insights_tracking_disabled` pauses
 * every track until the plugin version changes or 7 days pass (opt-out is
 * not a track and still goes out); network errors and 5xx keep retrying.
 */
class SchedulerBackoffTest extends TestCase {

	private function consented() {
		$this->options['my-plugin_insights_consent'] = 'yes';
		$this->options['my-plugin_insights_token']   = 'tok123';
	}

	private function respond( int $status, string $code = '' ): void {
		$this->http_response = [
			'response' => [ 'code' => $status ],
			'body'     => $code ? json_encode( [ 'code' => $code, 'message' => 'x', 'data' => [ 'status' => $status ] ] ) : '',
		];
	}

	private function installed_version( string $version ): void {
		Functions\when( 'get_plugin_data' )->justReturn( [ 'Name' => 'My Plugin', 'Version' => $version ] );
	}

	/**
	 * @test
	 * @dataProvider provide_4xx
	 */
	public function a_4xx_daily_track_waits_min_interval_before_the_next_try( int $status, string $code ) {
		$this->consented();
		$scheduler = $this->create_insights()->scheduler;

		$this->respond( $status, $code );
		$scheduler->run_daily();

		$this->assertCount( 1, $this->http );
		$this->assertArrayNotHasKey( 'my-plugin_insights_last_send', $this->options );
		$this->assertEqualsWithDelta( time(), $this->options['my-plugin_insights_last_attempt'], 5 );

		// Hourly backups and cron re-runs inside the window send nothing.
		$scheduler->run_daily();
		$scheduler->run_daily();
		$this->assertCount( 1, $this->http );

		// Once the window has passed, it tries again — and a success clears
		// the refusal state.
		$this->options['my-plugin_insights_last_attempt'] = time() - Scheduler::MIN_INTERVAL - 1;
		$this->respond( 202 );
		$scheduler->run_daily();

		$this->assertCount( 2, $this->http );
		$this->assertArrayNotHasKey( 'my-plugin_insights_last_attempt', $this->options );
		$this->assertArrayHasKey( 'my-plugin_insights_last_send', $this->options );
	}

	public function provide_4xx(): array {
		return [
			'400 missing fields' => [ 400, 'wprepo_insights_missing_fields' ],
			'404 unknown plugin' => [ 404, 'wprepo_insights_unknown_plugin' ],
			'404 no route'       => [ 404, 'rest_no_route' ],
			'413 too large'      => [ 413, 'wprepo_insights_body_too_large' ],
			'403 from a proxy'   => [ 403, '' ],
		];
	}

	/**
	 * @test
	 * @dataProvider provide_retryable
	 */
	public function network_errors_and_5xx_keep_retrying( $response ) {
		$this->consented();
		$scheduler = $this->create_insights()->scheduler;

		$this->http_response = $response;
		$scheduler->run_daily();
		$scheduler->run_daily();

		$this->assertCount( 2, $this->http );
		$this->assertArrayNotHasKey( 'my-plugin_insights_last_attempt', $this->options );
		$this->assertArrayNotHasKey( 'my-plugin_insights_disabled_version', $this->options );
	}

	public function provide_retryable(): array {
		return [
			'500'     => [ [ 'response' => [ 'code' => 500 ], 'body' => '{"code":"wprepo_insights_save_failed","message":"x"}' ] ],
			'502'     => [ [ 'response' => [ 'code' => 502 ], 'body' => '<html>' ] ],
			'network' => [ new \WP_Error( 'http_request_failed', 'timeout' ) ],
		];
	}

	/** @test */
	public function tracking_disabled_pauses_every_track_but_not_the_optout() {
		$this->consented();
		$insights  = $this->create_insights();
		$scheduler = $insights->scheduler;

		$this->respond( 403, 'wprepo_insights_tracking_disabled' );
		$scheduler->run_daily();

		$this->assertCount( 1, $this->http );
		$pause = $this->options['my-plugin_insights_disabled_version'];
		$this->assertSame( '2.0.0', $pause['version'] );
		$this->assertEqualsWithDelta( time(), $pause['since'], 5 );
		$this->assertTrue( $scheduler->is_tracking_disabled() );

		// Well past MIN_INTERVAL, still inside the pause: no track of any
		// kind — the server's 403 check runs first, so even `deactivate`
		// could not be recorded.
		$this->options['my-plugin_insights_last_attempt'] = time() - 2 * Scheduler::MIN_INTERVAL;
		$options_before                                   = $this->options;
		$this->respond( 202 );

		$scheduler->run_daily();
		$scheduler->product_activated();
		$scheduler->product_deactivated();
		$this->assertSame( 'wprepo_insights_tracking_paused', $scheduler->send( 'optin' )->get_error_code() );

		$this->assertCount( 1, $this->http );
		$this->assertSame( $options_before, $this->options, 'Paused calls write nothing.' );

		// An opt-out is not a track: it still goes out.
		$insights->opt_out();
		$this->assertCount( 2, $this->http );
		$this->assertStringEndsWith( '/optout', $this->http[1][0] );
	}

	/** @test */
	public function the_pause_expires_after_seven_days_without_a_version_change() {
		$this->consented();
		$scheduler = $this->create_insights()->scheduler;

		$this->options['my-plugin_insights_disabled_version'] = [
			'version' => '2.0.0',
			'since'   => time() - Scheduler::PAUSE_MAX + 60,
		];

		$scheduler->run_daily();
		$this->assertSame( [], $this->http, 'Still paused a minute before the cap.' );

		$this->options['my-plugin_insights_disabled_version']['since'] = time() - Scheduler::PAUSE_MAX;

		$scheduler->run_daily();

		$this->assertCount( 1, $this->http );
		$this->assertSame( 'daily', $this->http_body()['event'] );
		$this->assertArrayNotHasKey( 'my-plugin_insights_disabled_version', $this->options );
	}

	/** @test */
	public function a_repeat_403_after_expiry_starts_a_new_pause() {
		$this->consented();
		$scheduler = $this->create_insights()->scheduler;

		$this->options['my-plugin_insights_disabled_version'] = [ 'version' => '2.0.0', 'since' => 1 ];
		$this->respond( 403, 'wprepo_insights_tracking_disabled' );

		$scheduler->run_daily();

		$this->assertCount( 1, $this->http );
		$this->assertEqualsWithDelta( time(), $this->options['my-plugin_insights_disabled_version']['since'], 5 );
		$this->assertTrue( $scheduler->is_tracking_disabled() );
	}

	/** @test */
	public function tracking_resumes_once_the_plugin_version_changes() {
		$this->consented();
		$scheduler = $this->create_insights()->scheduler;

		$this->respond( 403, 'wprepo_insights_tracking_disabled' );
		$scheduler->run_daily();
		$this->assertCount( 1, $this->http );

		$this->installed_version( '2.0.1' );
		$this->respond( 202 );
		$scheduler->product_upgraded( null, [ 'action' => 'update', 'type' => 'plugin', 'plugins' => [ 'my-plugin/my-plugin.php' ] ] );

		$this->assertCount( 2, $this->http );
		$this->assertSame( 'upgrade', $this->http_body( 1 )['event'] );
		$this->assertSame( '2.0.1', $this->http_body( 1 )['plugin_version'] );
		$this->assertArrayNotHasKey( 'my-plugin_insights_disabled_version', $this->options );
		$this->assertArrayNotHasKey( 'my-plugin_insights_last_attempt', $this->options );
	}

	/** @test */
	public function a_version_change_without_an_upgrade_event_also_resumes_the_daily_track() {
		$this->consented();
		$this->options['my-plugin_insights_disabled_version'] = [ 'version' => '1.9.0', 'since' => time() ];

		$this->create_insights()->scheduler->run_daily();

		$this->assertCount( 1, $this->http );
		$this->assertArrayNotHasKey( 'my-plugin_insights_disabled_version', $this->options );
	}

	/** @test */
	public function the_commercial_hourly_sync_does_not_re_post_a_refused_track() {
		$test = $this;
		Functions\when( 'add_query_arg' )->alias( function ( $args, $url ) {
			return $url . '?' . http_build_query( $args );
		} );
		Functions\when( 'wp_remote_request' )->alias( function () {
			return [ 'response' => [ 'code' => 200 ], 'body' => '{"license":{"status":"active"}}' ];
		} );
		Functions\when( 'get_site_transient' )->justReturn( false );
		Functions\when( 'set_site_transient' )->justReturn( true );
		Functions\when( 'delete_site_transient' )->justReturn( true );

		$this->options['prod_abc_code'] = 'LIC-12';

		new Integration(
			[
				'api_url'     => 'https://repo.example.com/wp-json/wp-repo/v4',
				'file'        => WP_PLUGIN_DIR . '/my-plugin/my-plugin.php',
				'product_uid' => 'prod_abc',
				'product_id'  => '12',
				'license'     => true,
				'menu'        => false,
			]
		);

		$this->respond( 404, 'wprepo_insights_unknown_plugin' );
		$this->assertArrayHasKey( 'wprepo_sync_license_data_my-plugin12', $this->hooks );

		for ( $hour = 0; $hour < 5; $hour++ ) {
			foreach ( $test->hooks['wprepo_sync_license_data_my-plugin12'] ?? [] as $callback ) {
				call_user_func( $callback );
			}
		}

		$this->assertCount( 1, $this->http, 'One refused POST, not one per hour.' );
	}
}
