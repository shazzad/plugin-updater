<?php
namespace Shazzad\PluginUpdater\Tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Shazzad\PluginUpdater\Integration;

abstract class TestCase extends PHPUnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Stub is_wp_error — Brain Monkey cannot intercept instanceof checks,
		// so we define it as a real function via Brain Monkey.
		Functions\when( 'is_wp_error' )->alias( function ( $thing ) {
			return $thing instanceof \WP_Error;
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
		$path = __DIR__ . '/fixtures/' . $name;
		return json_decode( file_get_contents( $path ), true );
	}

	/**
	 * Load a JSON fixture and return raw string.
	 */
	protected function load_fixture_raw( string $name ): string {
		return file_get_contents( __DIR__ . '/fixtures/' . $name );
	}

	/**
	 * Create an Integration instance with all required WP function stubs.
	 *
	 * @param array $overrides Override default constructor args.
	 * @return Integration
	 */
	protected function create_integration( array $overrides = [] ): Integration {
		$defaults = [
			'api_url'         => 'https://api.example.com/wp-json/wp-repo/v3',
			'product_file'    => 'my-plugin/my-plugin.php',
			'product_id'      => '42',
			'license_enabled' => false,
			'display_menu'    => false,
		];
		$args = array_merge( $defaults, $overrides );

		// Stubs needed by the constructor.
		Functions\when( 'sanitize_key' )->alias( function ( $key ) {
			return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
		} );

		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );

		$integration = new Integration(
			$args['api_url'],
			$args['product_file'],
			$args['product_id'],
			$args['license_enabled'],
			$args['display_menu']
		);

		// Set a default version for tests.
		$integration->product_version = '1.0.0';

		return $integration;
	}
}
