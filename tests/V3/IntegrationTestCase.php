<?php
namespace Shazzad\PluginUpdater\Tests\V3;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Shazzad\PluginUpdater\V3\Integration;

/**
 * Base for the commercial V3\Integration tests carried over from the V2
 * suite: minimal stubs, each test stubs what its path needs.
 *
 * The Insights-side commercial tests (IntegrationInsightsTest) extend the
 * Insights TestCase instead, which stubs the full collector environment.
 */
abstract class IntegrationTestCase extends PHPUnitTestCase {

	/** @var array hook name => list of callbacks registered with add_action() */
	protected $hooks = [];

	/** @var string[] messages passed to _doing_it_wrong() */
	protected $doing_it_wrong = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Stub is_wp_error — Brain Monkey cannot intercept instanceof checks,
		// so we define it as a real function via Brain Monkey.
		Functions\when( 'is_wp_error' )->alias( function ( $thing ) {
			return $thing instanceof \WP_Error;
		} );

		// Defined process-wide once the Insights suite has run; stub it here
		// so a config notice never hits an un-mocked function.
		$test = $this;
		Functions\when( '_doing_it_wrong' )->alias( function ( $method, $message ) use ( $test ) {
			$test->doing_it_wrong[] = $message;
		} );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Load a JSON fixture and return decoded array.
	 */
	protected function load_fixture( string $name ): array {
		$path = dirname( __DIR__ ) . '/fixtures/' . $name;
		return json_decode( file_get_contents( $path ), true );
	}

	/**
	 * Load a JSON fixture and return raw string.
	 */
	protected function load_fixture_raw( string $name ): string {
		return file_get_contents( dirname( __DIR__ ) . '/fixtures/' . $name );
	}

	/**
	 * Create a V3 Integration instance with all required WP function stubs.
	 *
	 * @param array $overrides Override default constructor config.
	 * @return Integration
	 */
	protected function create_integration( array $overrides = [] ): Integration {
		$defaults = [
			'api_url'    => 'https://api.example.com/wp-json/wp-repo/v4',
			'file'       => 'my-plugin/my-plugin.php',
			'product_id' => '42',
			'license'    => false,
			'menu'       => false,
		];
		$config = array_merge( $defaults, $overrides );

		// Stubs needed by the constructor.
		Functions\when( 'sanitize_key' )->alias( function ( $key ) {
			return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
		} );

		// WP-faithful enough for tests: strips the (fake) plugins dir from
		// absolute paths, passes relative input through. Must be stubbed
		// unconditionally: once any test defines it, Brain Monkey keeps the
		// function defined process-wide and un-mocked calls throw.
		Functions\when( 'plugin_basename' )->alias( function ( $file ) {
			return ltrim( str_replace( '/var/www/wp-content/plugins/', '', $file ), '/' );
		} );

		$test = $this;
		Functions\when( 'add_action' )->alias( function ( $hook, $callback ) use ( $test ) {
			$test->hooks[ $hook ][] = $callback;
			return true;
		} );
		Functions\when( 'add_filter' )->justReturn( true );

		$integration = new Integration( $config );

		// Stand in for what prepare_product_data() resolves on `init`, so tests
		// exercise the path they name rather than re-resolving it every time.
		$integration->product_version = '1.0.0';
		$integration->product_name    = 'My Plugin';

		return $integration;
	}
}
