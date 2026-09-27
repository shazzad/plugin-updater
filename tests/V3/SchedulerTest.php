<?php
namespace Shazzad\PluginUpdater\Tests\V3;

use Shazzad\PluginUpdater\V3\Insights\Client;
use Shazzad\PluginUpdater\V3\Insights\Collector;
use Shazzad\PluginUpdater\V3\Insights\Consent;
use Shazzad\PluginUpdater\V3\Insights\Scheduler;

/**
 * Daily cron, lifecycle events and the no-consent-no-HTTP rule.
 */
class SchedulerTest extends TestCase {

	private function consented() {
		$this->options['my-plugin_insights_consent'] = 'yes';
		$this->options['my-plugin_insights_token']   = 'tok123';
	}

	private function upgrade_extra( array $plugins ): array {
		return [ 'action' => 'update', 'type' => 'plugin', 'plugins' => $plugins ];
	}

	/** @test */
	public function nothing_is_sent_without_consent_from_any_path() {
		foreach ( [ '', 'no' ] as $state ) {
			$this->options = $state ? [ 'my-plugin_insights_consent' => $state ] : [];
			$insights      = $this->create_insights();
			$scheduler     = $insights->scheduler;

			$scheduler->run_daily();
			$scheduler->product_activated();
			$scheduler->product_deactivated();
			$scheduler->product_upgraded( null, $this->upgrade_extra( [ 'my-plugin/my-plugin.php' ] ) );
			$scheduler->maybe_schedule();

			$this->assertTrue( is_wp_error( $scheduler->send( 'daily' ) ) );
			$this->assertTrue( is_wp_error( $insights->client->track( 'daily' ) ) );
			$this->assertTrue( is_wp_error( $insights->client->optout() ) );

			$this->assertSame( [], $this->http, "state '{$state}'" );
			$this->assertSame( [], $this->cron, "state '{$state}'" );
		}
	}

	/** @test */
	public function daily_sends_when_consented_and_records_last_send() {
		$this->consented();
		$insights = $this->create_insights();

		$insights->scheduler->run_daily();

		$this->assertCount( 1, $this->http );
		$this->assertSame( 'daily', $this->http_body()['event'] );
		$this->assertEqualsWithDelta( time(), $this->options['my-plugin_insights_last_send'], 5 );
	}

	/** @test */
	public function daily_is_throttled_to_once_per_twenty_hours() {
		$this->consented();
		$this->options['my-plugin_insights_last_send'] = time() - 3600;
		$insights                                     = $this->create_insights();

		$insights->scheduler->run_daily();
		$this->assertSame( [], $this->http );

		$this->options['my-plugin_insights_last_send'] = time() - 72001;
		$insights->scheduler->run_daily();
		$this->assertCount( 1, $this->http );
	}

	/** @test */
	public function failed_send_does_not_record_last_send() {
		$this->consented();
		$this->http_response = new \WP_Error( 'http_request_failed', 'timeout' );
		$insights            = $this->create_insights();

		$insights->scheduler->run_daily();

		$this->assertCount( 1, $this->http );
		$this->assertArrayNotHasKey( 'my-plugin_insights_last_send', $this->options );
	}

	/** @test */
	public function activation_with_consent_schedules_and_tracks() {
		$this->consented();
		$insights = $this->create_insights();

		$insights->scheduler->product_activated();

		$this->assertSame( 'daily', $this->cron['wprepo_insights_track_my-plugin'] );
		$this->assertCount( 1, $this->http );
		$this->assertSame( 'activate', $this->http_body()['event'] );
		$this->assertSame( 'active', $this->http_body()['product_status'] );
	}

	/** @test */
	public function deactivation_with_consent_tracks_inactive_and_clears_cron() {
		$this->consented();
		$this->cron['wprepo_insights_track_my-plugin'] = 'daily';
		$insights                                      = $this->create_insights();

		$insights->scheduler->product_deactivated();

		$this->assertSame( [], $this->cron );
		$this->assertCount( 1, $this->http );
		$this->assertSame( 'deactivate', $this->http_body()['event'] );
		$this->assertSame( 'inactive', $this->http_body()['product_status'] );
		$this->assertSame( 'yes', $this->options['my-plugin_insights_consent'] );
	}

	/** @test */
	public function upgrade_of_this_plugin_tracks() {
		$this->consented();
		$insights = $this->create_insights();

		$insights->scheduler->product_upgraded( null, $this->upgrade_extra( [ 'akismet/akismet.php', 'my-plugin/my-plugin.php' ] ) );
		$insights->scheduler->product_upgraded( null, [ 'action' => 'update', 'type' => 'plugin', 'plugin' => 'my-plugin/my-plugin.php' ] );

		$this->assertCount( 2, $this->http );
		$this->assertSame( 'upgrade', $this->http_body( 0 )['event'] );
		$this->assertSame( 'upgrade', $this->http_body( 1 )['event'] );
	}

	/** @test */
	public function other_upgrades_are_ignored() {
		$this->consented();
		$insights = $this->create_insights();

		$insights->scheduler->product_upgraded( null, $this->upgrade_extra( [ 'akismet/akismet.php' ] ) );
		$insights->scheduler->product_upgraded( null, [ 'action' => 'install', 'type' => 'plugin', 'plugins' => [ 'my-plugin/my-plugin.php' ] ] );
		$insights->scheduler->product_upgraded( null, [ 'action' => 'update', 'type' => 'theme', 'themes' => [ 'my-plugin' ] ] );
		$insights->scheduler->product_upgraded( null, null );

		$this->assertSame( [], $this->http );
	}

	/** @test */
	public function maybe_schedule_restores_a_missing_event_only_with_consent() {
		$insights = $this->create_insights();
		$insights->scheduler->maybe_schedule();
		$this->assertSame( [], $this->cron );

		$this->consented();
		$insights->scheduler->maybe_schedule();
		$this->assertSame( 'daily', $this->cron['wprepo_insights_track_my-plugin'] );
	}

	/** @test */
	public function track_posts_json_with_timeout_five() {
		$this->consented();
		$insights = $this->create_insights();

		$insights->client->track( 'daily' );

		list( $url, $args ) = $this->http[0];
		$this->assertSame( 'https://repo.example.com/wp-json/wp-repo/v4/products/prod_abc/track', $url );
		$this->assertSame( 5, $args['timeout'] );
		$this->assertSame( 'application/json', $args['headers']['Content-Type'] );
		$this->assertIsString( $args['body'] );
		$this->assertSame( 'tok123', $this->http_body()['token'] );
	}

	/** @test */
	public function track_uses_product_id_when_no_uid() {
		$this->consented();
		$insights = $this->create_insights( [ 'product_uid' => '' ] );

		$insights->client->track( 'daily' );

		$this->assertSame( 'https://repo.example.com/wp-json/wp-repo/v4/products/12/track', $this->http[0][0] );
	}

	/** @test */
	public function http_errors_come_back_as_wp_error() {
		$this->consented();
		$insights = $this->create_insights();

		$this->http_response = [ 'response' => [ 'code' => 403 ], 'body' => '{"code":"tracking_disabled","message":"Off"}' ];
		$result              = $insights->client->track( 'daily' );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'tracking_disabled', $result->get_error_code() );

		$this->http_response = new \WP_Error( 'http_request_failed', 'timeout' );
		$this->assertSame( 'http_request_failed', $insights->client->track( 'daily' )->get_error_code() );

		$this->http_response = [ 'response' => [ 'code' => 202 ], 'body' => '' ];
		$this->assertSame( [], $insights->client->track( 'daily' ) );
	}

	/** @test */
	public function optout_without_token_sends_nothing() {
		$this->options['my-plugin_insights_consent'] = 'yes';
		$insights                                    = $this->create_insights();

		$result = $insights->client->optout();

		$this->assertSame( 'wprepo_insights_no_token', $result->get_error_code() );
		$this->assertSame( [], $this->http );
	}

	/** @test */
	public function parts_can_be_built_without_the_entry_point_in_commercial_mode() {
		$consent   = new Consent( 'my-plugin', Consent::MODE_COMMERCIAL );
		$collector = new Collector(
			'my-plugin/my-plugin.php',
			$consent,
			[
				'license_callback' => function () {
					return 'LIC-1';
				},
			]
		);
		$client    = new Client( 'https://repo.example.com/wp-json/wp-repo/v4/', 'prod_abc', $collector, $consent );
		$scheduler = new Scheduler( 'my-plugin/my-plugin.php', 'my-plugin', $client, $collector, $consent );

		$scheduler->product_activated();

		$this->assertSame( 'daily', $this->cron['wprepo_insights_track_my-plugin'] );
		$this->assertSame( 'https://repo.example.com/wp-json/wp-repo/v4/products/prod_abc/track', $this->http[0][0] );
		$this->assertSame( 'commercial', $this->http_body()['mode'] );
		$this->assertSame( 'LIC-1', $this->http_body()['license'] );
		$this->assertSame( 'activate', $this->http_body()['event'] );
		$this->assertArrayNotHasKey( 'my-plugin_insights_consent', $this->options );
	}
}
