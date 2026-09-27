<?php
namespace Shazzad\PluginUpdater\Tests\V4;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Shazzad\PluginUpdater\V4\Insights;

/**
 * Base for V4 Insights tests.
 *
 * Stubs every WordPress function the Insights classes touch, backed by
 * in-memory stores the tests inspect: options, cron events, HTTP calls,
 * registered hooks and _doing_it_wrong() notices. Stubbing everything up
 * front matters with Brain Monkey: once any test defines a function it
 * stays defined process-wide, and an un-stubbed call would throw.
 */
abstract class TestCase extends PHPUnitTestCase {

	/** @var array option name => value */
	protected $options = [];

	/** @var array hook name => true for scheduled cron events */
	protected $cron = [];

	/** @var array list of [ url, args ] passed to wp_remote_post() */
	protected $http = [];

	/** @var array|\WP_Error what wp_remote_post() returns */
	protected $http_response;

	/** @var array hook name => list of callbacks */
	protected $hooks = [];

	/** @var string[] messages passed to _doing_it_wrong() */
	protected $doing_it_wrong = [];

	/** @var bool */
	protected $can_manage = true;

	/** @var object|null */
	protected $screen = null;

	/** @var bool */
	protected $doing_ajax = false;

	/** @var bool */
	protected $nonce_valid = true;

	/** @var string[] URLs passed to wp_safe_redirect() */
	protected $redirects = [];

	/** @var array get_plugins() return */
	protected $installed_plugins = [];

	/** @var string[] active_plugins option */
	protected $active_plugins = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->http_response = [
			'response' => [ 'code' => 202 ],
			'body'     => '{"status":"accepted"}',
		];

		$this->installed_plugins = [
			'my-plugin/my-plugin.php' => [ 'Name' => 'My Plugin', 'Version' => '2.0.0' ],
			'akismet/akismet.php'     => [ 'Name' => 'Akismet', 'Version' => '5.3' ],
			'hello.php'               => [ 'Name' => 'Hello Dolly', 'Version' => '1.7.2' ],
		];
		$this->active_plugins    = [ 'my-plugin/my-plugin.php', 'hello.php' ];

		$GLOBALS['wpdb'] = new class() {
			public function db_server_info() {
				return '8.0.33';
			}
		};

		$this->stub_environment();
	}

	protected function tearDown(): void {
		$_GET = [];
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function stub_environment() {
		$test = $this;

		Functions\when( 'is_wp_error' )->alias( function ( $thing ) {
			return $thing instanceof \WP_Error;
		} );
		Functions\when( 'sanitize_key' )->alias( function ( $key ) {
			return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
		} );
		Functions\when( 'plugin_basename' )->alias( function ( $file ) {
			return ltrim( str_replace( WP_PLUGIN_DIR . '/', '', $file ), '/' );
		} );
		Functions\when( 'add_action' )->alias( function ( $hook, $callback ) use ( $test ) {
			$test->hooks[ $hook ][] = $callback;
			return true;
		} );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( '_doing_it_wrong' )->alias( function ( $method, $message ) use ( $test ) {
			$test->doing_it_wrong[] = $message;
		} );

		// Options.
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) use ( $test ) {
			if ( 'active_plugins' === $key ) {
				return $test->active_plugins;
			}
			return array_key_exists( $key, $test->options ) ? $test->options[ $key ] : $default;
		} );
		Functions\when( 'update_option' )->alias( function ( $key, $value ) use ( $test ) {
			$test->options[ $key ] = $value;
			return true;
		} );
		Functions\when( 'delete_option' )->alias( function ( $key ) use ( $test ) {
			$existed = array_key_exists( $key, $test->options );
			unset( $test->options[ $key ] );
			return $existed;
		} );
		Functions\when( 'get_site_option' )->justReturn( [] );
		Functions\when( 'wp_generate_password' )->justReturn( 'abcdefghijklmnopqrstuvwxyz012345' );

		// Cron.
		Functions\when( 'wp_next_scheduled' )->alias( function ( $hook ) use ( $test ) {
			return isset( $test->cron[ $hook ] ) ? 1234567890 : false;
		} );
		Functions\when( 'wp_schedule_event' )->alias( function ( $time, $recurrence, $hook ) use ( $test ) {
			$test->cron[ $hook ] = $recurrence;
			return true;
		} );
		Functions\when( 'wp_clear_scheduled_hook' )->alias( function ( $hook ) use ( $test ) {
			unset( $test->cron[ $hook ] );
			return 1;
		} );

		// HTTP.
		Functions\when( 'wp_remote_post' )->alias( function ( $url, $args ) use ( $test ) {
			$test->http[] = [ $url, $args ];
			return $test->http_response;
		} );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( function ( $response ) {
			return isset( $response['response']['code'] ) ? $response['response']['code'] : '';
		} );
		Functions\when( 'wp_remote_retrieve_body' )->alias( function ( $response ) {
			return isset( $response['body'] ) ? $response['body'] : '';
		} );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		// Collector environment.
		Functions\when( 'get_plugin_data' )->justReturn( [ 'Name' => 'My Plugin', 'Version' => '2.0.0' ] );
		Functions\when( 'get_plugins' )->alias( function () use ( $test ) {
			return $test->installed_plugins;
		} );
		Functions\when( 'site_url' )->justReturn( 'https://example.org' );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'get_bloginfo' )->alias( function ( $show ) {
			return 'version' === $show ? '6.9' : 'Example &amp; Co';
		} );
		Functions\when( 'wp_specialchars_decode' )->alias( function ( $text ) {
			return html_entity_decode( $text, ENT_QUOTES );
		} );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_users' )->justReturn( [ (object) [ 'display_name' => 'Site Admin' ] ] );
		Functions\when( 'get_stylesheet' )->justReturn( 'child-theme' );
		Functions\when( 'get_template' )->justReturn( 'parent-theme' );
		Functions\when( 'wp_get_theme' )->justReturn(
			new class() {
				public function get( $key ) {
					return 'Name' === $key ? 'Child Theme' : '1.2.3';
				}
			}
		);
		Functions\when( 'count_users' )->justReturn(
			[
				'total_users' => 3,
				'avail_roles' => [ 'administrator' => 1, 'subscriber' => 2, 'editor' => 0 ],
			]
		);
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		// Notice environment.
		Functions\when( 'current_user_can' )->alias( function () use ( $test ) {
			return $test->can_manage;
		} );
		Functions\when( 'get_current_screen' )->alias( function () use ( $test ) {
			return $test->screen;
		} );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'add_query_arg' )->alias( function ( $args ) {
			return 'http://example.org/wp-admin/index.php?' . http_build_query( $args );
		} );
		Functions\when( 'wp_nonce_url' )->alias( function ( $url, $action ) {
			return $url . '&_wpnonce=nonce-' . $action;
		} );
		Functions\when( 'remove_query_arg' )->justReturn( 'http://example.org/wp-admin/index.php' );
		Functions\when( 'wp_doing_ajax' )->alias( function () use ( $test ) {
			return $test->doing_ajax;
		} );
		Functions\when( 'wp_verify_nonce' )->alias( function () use ( $test ) {
			return $test->nonce_valid ? 1 : false;
		} );
		Functions\when( 'wp_safe_redirect' )->alias( function ( $url ) use ( $test ) {
			$test->redirects[] = $url;
			return true;
		} );
	}

	/**
	 * Default free-plugin config.
	 *
	 * @param array $overrides Config overrides.
	 * @return array
	 */
	protected function config( array $overrides = [] ): array {
		return array_merge(
			[
				'api_url'     => 'https://repo.example.com/wp-json/wp-repo/v4',
				'file'        => WP_PLUGIN_DIR . '/my-plugin/my-plugin.php',
				'product_uid' => 'prod_abc',
				'product_id'  => '12',
			],
			$overrides
		);
	}

	/**
	 * Builds a free Insights instance.
	 *
	 * @param array $overrides Config overrides.
	 * @return Insights
	 */
	protected function create_insights( array $overrides = [] ): Insights {
		return new Insights( $this->config( $overrides ) );
	}

	/**
	 * Decoded JSON body of the Nth recorded HTTP call.
	 */
	protected function http_body( int $index = 0 ): array {
		return json_decode( $this->http[ $index ][1]['body'], true );
	}
}
