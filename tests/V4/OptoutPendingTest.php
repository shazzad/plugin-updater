<?php
namespace Shazzad\PluginUpdater\Tests\V4;

use Shazzad\PluginUpdater\V4\Insights;
use Shazzad\PluginUpdater\V4\Insights\Scheduler;
use WP_Error;

/**
 * An opt-out that fails to reach the server (timeout, 5xx) is not
 * forgotten: consent turns `no` at once, and the deletion request is
 * retried on admin_init and by the daily cron until it goes through or
 * retries give up.
 */
class OptoutPendingTest extends TestCase {

	const PENDING = 'my-plugin_insights_optout_pending';
	const HOOK    = 'wprepo_insights_track_my-plugin';

	/**
	 * An opted-in install whose next optout call fails.
	 */
	private function opted_in_then_failed_optout( $failure = null ): Insights {
		$insights = $this->create_insights( [ 'notice' => false ] );
		$insights->opt_in();
		$this->http = [];

		$this->http_response = $failure ? $failure : new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );

		$insights->opt_out();

		return $insights;
	}

	private function server_back_up() {
		$this->http_response = [
			'response' => [ 'code' => 202 ],
			'body'     => '{"status":"accepted"}',
		];
	}

	/** @test */
	public function failed_optout_revokes_consent_but_remembers_the_deletion_request() {
		$insights = $this->opted_in_then_failed_optout();

		$this->assertSame( 'no', $insights->get_consent() );
		$this->assertCount( 1, $this->http );
		$this->assertEqualsWithDelta( time(), $this->options[ self::PENDING ]['since'], 5 );
		$this->assertTrue( $insights->consent->has_optout_pending() );
		$this->assertSame( 'daily', $this->cron[ self::HOOK ], 'The daily event stays to carry the retry.' );
	}

	/** @test */
	public function http_5xx_counts_as_a_failure_too() {
		$insights = $this->opted_in_then_failed_optout(
			[
				'response' => [ 'code' => 503 ],
				'body'     => '',
			]
		);

		$this->assertTrue( $insights->consent->has_optout_pending() );
	}

	/** @test */
	public function successful_optout_leaves_nothing_pending() {
		$insights = $this->create_insights( [ 'notice' => false ] );
		$insights->opt_in();

		$insights->opt_out();

		$this->assertArrayNotHasKey( self::PENDING, $this->options );
		$this->assertArrayNotHasKey( self::HOOK, $this->cron );
	}

	/** @test */
	public function missing_token_is_not_retried() {
		$this->options['my-plugin_insights_consent'] = 'yes'; // granted, but no token was ever stored.
		$insights                                    = $this->create_insights( [ 'notice' => false ] );

		$result = $insights->opt_out();

		$this->assertSame( 'wprepo_insights_no_token', $result->get_error_code() );
		$this->assertSame( [], $this->http );
		$this->assertArrayNotHasKey( self::PENDING, $this->options );
		$this->assertArrayNotHasKey( self::HOOK, $this->cron );
	}

	/** @test */
	public function admin_init_retries_with_only_site_url_and_token_then_clears() {
		$insights = $this->opted_in_then_failed_optout();
		$this->server_back_up();
		$this->http = [];
		$this->options[ self::PENDING ]['last_try'] = time() - Scheduler::OPTOUT_RETRY_INTERVAL;

		$insights->scheduler->maybe_schedule(); // the admin_init callback.

		$this->assertCount( 1, $this->http );
		$this->assertStringEndsWith( '/products/prod_abc/optout', $this->http[0][0] );
		$this->assertSame(
			[ 'site_url' => 'https://example.org', 'token' => 'abcdefghijklmnopqrstuvwxyz012345' ],
			$this->http_body()
		);
		$this->assertArrayNotHasKey( self::PENDING, $this->options );
		$this->assertArrayNotHasKey( self::HOOK, $this->cron );
		$this->assertSame( 'no', $insights->get_consent() );
	}

	/** @test */
	public function daily_cron_retries_instead_of_tracking() {
		$insights = $this->opted_in_then_failed_optout();
		$this->server_back_up();
		$this->http = [];
		$this->options[ self::PENDING ]['last_try'] = time() - 86400;

		$insights->scheduler->run_daily();

		$this->assertCount( 1, $this->http );
		$this->assertStringEndsWith( '/optout', $this->http[0][0] );
		$this->assertArrayNotHasKey( self::PENDING, $this->options );
	}

	/** @test */
	public function retries_are_throttled_to_one_per_interval() {
		$insights = $this->opted_in_then_failed_optout();
		$this->http = [];

		$insights->scheduler->maybe_schedule();
		$insights->scheduler->run_daily();

		$this->assertSame( [], $this->http, 'A retry right after the failure must wait OPTOUT_RETRY_INTERVAL.' );
	}

	/** @test */
	public function a_failed_retry_keeps_the_first_failure_time() {
		$insights = $this->opted_in_then_failed_optout();
		$since    = time() - 2 * 86400;

		$this->options[ self::PENDING ] = [ 'since' => $since, 'last_try' => time() - 7200 ];
		$this->http                     = [];

		$result = $insights->scheduler->retry_optout();

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertCount( 1, $this->http );
		$this->assertSame( $since, $this->options[ self::PENDING ]['since'] );
		$this->assertEqualsWithDelta( time(), $this->options[ self::PENDING ]['last_try'], 5 );
		$this->assertSame( 'daily', $this->cron[ self::HOOK ] );
	}

	/** @test */
	public function retries_give_up_after_the_give_up_period() {
		$insights = $this->opted_in_then_failed_optout();
		$this->http = [];

		$this->options[ self::PENDING ] = [
			'since'    => time() - Scheduler::OPTOUT_GIVE_UP,
			'last_try' => time() - 86400,
		];

		$insights->scheduler->run_daily();

		$this->assertSame( [], $this->http );
		$this->assertArrayNotHasKey( self::PENDING, $this->options );
		$this->assertArrayNotHasKey( self::HOOK, $this->cron );
	}

	/** @test */
	public function nothing_but_the_optout_is_sent_while_pending() {
		$insights = $this->opted_in_then_failed_optout();
		$this->http = [];

		$this->assertTrue( is_wp_error( $insights->client->track( 'daily' ) ) );
		$this->assertTrue( is_wp_error( $insights->scheduler->send( 'daily' ) ) );

		$insights->scheduler->product_activated();
		$insights->scheduler->product_upgraded( null, [ 'action' => 'update', 'type' => 'plugin', 'plugins' => [ 'my-plugin/my-plugin.php' ] ] );

		$this->assertSame( [], $this->http );
	}

	/** @test */
	public function opting_in_again_supersedes_the_pending_deletion() {
		$insights = $this->opted_in_then_failed_optout();
		$this->server_back_up();

		$insights->opt_in();

		$this->assertArrayNotHasKey( self::PENDING, $this->options );
		$this->assertSame( 'yes', $insights->get_consent() );
	}

	/** @test */
	public function opting_out_again_while_pending_retries_at_once() {
		$insights = $this->opted_in_then_failed_optout();
		$this->server_back_up();
		$this->http = [];

		$insights->opt_out();

		$this->assertCount( 1, $this->http );
		$this->assertStringEndsWith( '/optout', $this->http[0][0] );
		$this->assertArrayNotHasKey( self::PENDING, $this->options );
		$this->assertArrayNotHasKey( self::HOOK, $this->cron );
	}

	/** @test */
	public function malformed_pending_option_is_ignored() {
		$this->options['my-plugin_insights_consent'] = 'no';
		$this->options[ self::PENDING ]              = 'garbage';
		$insights                                    = $this->create_insights( [ 'notice' => false ] );

		$this->assertFalse( $insights->consent->has_optout_pending() );
		$this->assertTrue( is_wp_error( $insights->client->optout() ) );
		$this->assertSame( [], $this->http );
	}
}
