<?php
namespace Shazzad\PluginUpdater\Tests\V4;

use Brain\Monkey\Functions;
use Shazzad\PluginUpdater\V4\Insights;
use Shazzad\PluginUpdater\V4\Insights\Client as InsightsClient;
use Shazzad\PluginUpdater\V4\Integration;
use WP_Error;

/**
 * Configurations no request can succeed with: an `api_url` on wp-repo/v3
 * (no `plugins/{uid}` routes) and a missing or malformed uid (v4 answers
 * `404 rest_no_route`). Both entry points must notice, send nothing and never
 * read the refusal as a license verdict. Plus the `api_url` trailing-slash
 * trim.
 *
 * Extends the Insights TestCase for its recorded options / cron / HTTP /
 * hooks, and stubs the update-API side like IntegrationInsightsTest.
 */
class ApiConfigTest extends TestCase {

	/** @var array list of URLs passed to wp_remote_request() */
	protected $requests = [];

	protected function setUp(): void {
		parent::setUp();

		$test = $this;

		// WP's add_query_arg() does not encode values; neither does this.
		Functions\when( 'add_query_arg' )->alias( function ( $args, $url ) {
			$pairs = [];
			foreach ( $args as $key => $value ) {
				$pairs[] = $key . '=' . $value;
			}
			return $url . '?' . implode( '&', $pairs );
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
		Functions\when( 'delete_site_transient' )->justReturn( true );
	}

	private function create_integration( array $overrides = [] ): Integration {
		return new Integration(
			array_merge(
				[
					'api_url'     => 'https://repo.example.com/wp-json/wp-repo/v4',
					'file'        => WP_PLUGIN_DIR . '/my-plugin/my-plugin.php',
					'product_uid' => 'prod_abc',
					'product_id'  => '12',
					'license'     => true,
					'menu'        => false,
				],
				$overrides
			)
		);
	}

	private function fire( string $hook, ...$args ): void {
		foreach ( $this->hooks[ $hook ] ?? [] as $callback ) {
			call_user_func_array( $callback, $args );
		}
	}

	/**
	 * Every update-API call, each expected to come back as $code.
	 */
	private function assert_every_api_call_refused( Integration $integration, string $code ): void {
		foreach ( [
			'check_license' => $integration->client->check_license( 'LIC-12' ),
			'updates'       => $integration->client->updates(),
			'details'       => $integration->client->details(),
		] as $method => $result ) {
			$this->assertInstanceOf( WP_Error::class, $result, $method );
			$this->assertSame( $code, $result->get_error_code(), $method );
		}

		$this->assertSame( [], $this->requests );
	}

	/**
	 * @test
	 * @dataProvider provide_v3_api_urls
	 */
	public function a_v3_api_url_makes_the_update_client_refuse_every_call( string $api_url ) {
		$this->options['prod_abc_code'] = 'LIC-12';

		$integration = $this->create_integration( [ 'api_url' => $api_url ] );

		$this->assertCount( 1, $this->doing_it_wrong );
		$this->assertStringContainsString( 'wp-repo/v4', $this->doing_it_wrong[0] );
		$this->assertStringContainsString( 'nothing works', $this->doing_it_wrong[0] );

		$this->assert_every_api_call_refused( $integration, 'wprepo_v3_api_url' );

		// The hourly sync checks nothing, and the refusal is no license verdict.
		$this->fire( 'wprepo_sync_license_data_my-plugin12' );
		$this->assertSame( [], $this->requests );
		$this->assertSame( [], $this->http );
		$this->assertArrayNotHasKey( 'prod_abc_data', $this->options );
	}

	public function provide_v3_api_urls(): array {
		return [
			'plain'          => [ 'https://repo.example.com/wp-json/wp-repo/v3' ],
			'trailing slash' => [ 'https://repo.example.com/wp-json/wp-repo/v3/' ],
		];
	}

	/** @test */
	public function a_v3_api_url_keeps_the_updater_from_touching_the_update_transient() {
		$integration = $this->create_integration( [ 'api_url' => 'https://repo.example.com/wp-json/wp-repo/v3' ] );

		$transient           = new \stdClass();
		$transient->checked  = [ 'my-plugin/my-plugin.php' => '1.0.0' ];
		$transient->response = [];

		$integration->updater->pre_set_transient( $transient );

		$this->assertSame( [], $transient->response );
		$this->assertSame( [], $this->requests );
	}

	/** @test */
	public function the_free_entry_point_on_a_v3_api_url_draws_no_notice_and_sends_nothing() {
		$insights = $this->create_insights( [ 'api_url' => 'https://repo.example.com/wp-json/wp-repo/v3' ] );

		$this->assertCount( 1, $this->doing_it_wrong );
		$this->assertStringContainsString( 'wp-repo/v3', $this->doing_it_wrong[0] );
		$this->assertNull( $insights->notice, 'No consent can be used: v3 has no Insights routes.' );
		$this->assertArrayNotHasKey( 'admin_notices', $this->hooks );

		// Even with consent already stored (e.g. from an earlier config).
		$this->options['my-plugin_insights_consent'] = 'yes';
		$this->options['my-plugin_insights_token']   = 'tok123';

		$this->assertSame( 'wprepo_insights_v3_api_url', $insights->client->track( 'daily' )->get_error_code() );
		$this->assertSame( 'wprepo_insights_v3_api_url', $insights->client->optout()->get_error_code() );
		$insights->scheduler->run_daily();
		$this->assertSame( [], $this->http );
	}

	/** @test */
	public function the_free_entry_point_with_an_empty_api_url_draws_no_notice() {
		$insights = $this->create_insights( [ 'api_url' => '' ] );

		$this->assertNull( $insights->notice );
		$this->assertSame( 'wprepo_insights_no_api_url', $insights->client->get_config_error()->get_error_code() );
	}

	/** @test */
	public function a_trailing_slash_on_api_url_is_trimmed_once() {
		$integration = $this->create_integration( [ 'api_url' => 'https://repo.example.com/wp-json/wp-repo/v4/' ] );

		$this->assertSame( 'https://repo.example.com/wp-json/wp-repo/v4', $integration->api_url );
		$this->assertSame( [], $this->doing_it_wrong );

		$integration->client->check_license( 'LIC-12' );

		$this->assertSame(
			'https://repo.example.com/wp-json/wp-repo/v4/plugins/prod_abc/check_license?license=LIC-12',
			$this->requests[0]
		);
		$this->assertSame(
			'https://repo.example.com/wp-json/wp-repo/v4/plugins/prod_abc/track',
			$integration->insights_client->get_url( 'track' )
		);
	}

	/**
	 * @test
	 * @dataProvider provide_malformed_uids
	 */
	public function a_malformed_uid_is_treated_as_missing( string $uid ) {
		$integration = $this->create_integration( [ 'product_uid' => $uid ] );

		$this->assertCount( 1, $this->doing_it_wrong );
		$this->assertStringContainsString( 'not a valid uid', $this->doing_it_wrong[0] );
		$this->assertSame( '', $integration->get_api_product_key() );
		$this->assertNull( $integration->insights_client );
		$this->assert_every_api_call_refused( $integration, 'wprepo_no_product_uid' );

		$this->doing_it_wrong = [];
		$insights             = $this->create_insights( [ 'product_uid' => $uid ] );

		$this->assertCount( 1, $this->doing_it_wrong );
		$this->assertStringContainsString( 'not a valid uid', $this->doing_it_wrong[0] );
		$this->assertNull( $insights->notice );
		$this->assertSame( 'wprepo_insights_no_product_uid', $insights->client->get_config_error()->get_error_code() );
		$this->assertSame( [], $this->http );
	}

	public function provide_malformed_uids(): array {
		return [
			'upper case'   => [ 'PROD_ABC' ],
			'hyphen'       => [ 'prod-abc' ],
			'no suffix'    => [ 'prod_' ],
			'numeric id'   => [ '12' ],
			'inner space'  => [ 'prod_abc def' ],
			'path chars'   => [ 'prod_abc/../x' ],
			'trailing nl'  => [ "prod_abc\n" ],
		];
	}

	/** @test */
	public function valid_uids_pass_the_shape_check() {
		$this->assertTrue( InsightsClient::is_valid_uid( 'prod_9f3k2m9q4x8z7w1t2r5s' ) );
		$this->assertTrue( InsightsClient::is_valid_uid( 'prod_abc' ) );
		$this->assertFalse( InsightsClient::is_valid_uid( null ) );
		$this->assertFalse( InsightsClient::is_valid_uid( 12 ) );
	}

	/**
	 * @test
	 * @dataProvider provide_unusable_free_configs
	 */
	public function opt_in_stores_nothing_when_nothing_could_be_sent( array $overrides, string $code ) {
		$insights = $this->create_insights( $overrides );

		$result = $insights->opt_in();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( [], $this->options, 'No consent, no token.' );
		$this->assertSame( [], $this->cron );
		$this->assertSame( [], $this->http );
		$this->assertFalse( $insights->has_consent() );
	}

	public function provide_unusable_free_configs(): array {
		return [
			'no uid'     => [ [ 'product_uid' => '' ], 'wprepo_insights_no_product_uid' ],
			'bad uid'    => [ [ 'product_uid' => 'prod-abc' ], 'wprepo_insights_no_product_uid' ],
			'v3 api_url' => [ [ 'api_url' => 'https://repo.example.com/wp-json/wp-repo/v3' ], 'wprepo_insights_v3_api_url' ],
		];
	}

	/** @test */
	public function config_errors_are_not_retryable_optout_failures() {
		foreach ( InsightsClient::CONFIG_ERROR_CODES as $code ) {
			$this->assertFalse( Insights\Scheduler::optout_should_retry( new WP_Error( $code, 'x' ) ), $code );
		}

		$this->assertTrue( Insights\Scheduler::optout_should_retry( new WP_Error( 'http_request_failed', 'x' ) ) );
	}
}
