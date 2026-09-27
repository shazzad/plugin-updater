<?php
namespace Shazzad\PluginUpdater\Tests\V4;

use Shazzad\PluginUpdater\V4\Insights\Collector;
use Shazzad\PluginUpdater\V4\Insights\Consent;

/**
 * Payload shape.
 */
class CollectorTest extends TestCase {

	private function collector( array $args = [], $mode = 'consent' ): Collector {
		return new Collector( 'my-plugin/my-plugin.php', new Consent( 'my-plugin', $mode ), $args );
	}

	/**
	 * Free plugins (consent mode) send only what the consent notice lists.
	 *
	 * @test
	 */
	public function consent_payload_has_only_the_allowed_keys() {
		$this->options['my-plugin_insights_consent'] = 'yes';
		$this->options['my-plugin_insights_token']   = 'tok123';
		$this->options['admin_email']                = 'owner@example.org';

		$data = $this->collector()->collect( 'daily' );

		$this->assertSame(
			[ 'event', 'mode', 'token', 'plugin_version', 'plugin_status', 'site', 'wp', 'server', 'plugins' ],
			array_keys( $data )
		);
		$this->assertSame( 'daily', $data['event'] );
		$this->assertSame( 'consent', $data['mode'] );
		$this->assertSame( 'tok123', $data['token'] );
		$this->assertSame( '2.0.0', $data['plugin_version'] );
		$this->assertSame( 'active', $data['plugin_status'] );

		$this->assertSame(
			[
				'url'       => 'https://example.org',
				'name'      => 'Example & Co',
				'locale'    => 'en_US',
				'is_local'  => false,
				'multisite' => false,
			],
			$data['site']
		);

		$this->assertSame( [ 'version', 'memory_limit', 'theme' ], array_keys( $data['wp'] ) );
		$this->assertSame( '6.9', $data['wp']['version'] );
		$this->assertSame(
			[ 'slug' => 'child-theme', 'name' => 'Child Theme', 'version' => '1.2.3', 'parent' => 'parent-theme' ],
			$data['wp']['theme']
		);

		$this->assertSame( [ 'php_version', 'db_version', 'server_software', 'php_memory_limit' ], array_keys( $data['server'] ) );
		$this->assertSame( phpversion(), $data['server']['php_version'] );
		$this->assertSame( '8.0.33', $data['server']['db_version'] );

		$this->assertSame(
			[
				'active' => [
					[ 'slug' => 'my-plugin', 'name' => 'My Plugin', 'version' => '2.0.0', 'url' => 'https://example.com/my-plugin/' ],
					[ 'slug' => 'hello', 'name' => 'Hello Dolly', 'version' => '1.7.2', 'url' => '' ],
				],
			],
			$data['plugins']
		);

		// Dropped for free plugins; asserted by name so a regression is obvious.
		$this->assertArrayNotHasKey( 'users', $data );
		$this->assertArrayNotHasKey( 'admin', $data );
		$this->assertArrayNotHasKey( 'debug_mode', $data['wp'] );
		$this->assertArrayNotHasKey( 'max_execution_time', $data['server'] );
		$this->assertArrayNotHasKey( 'upload_max_filesize', $data['server'] );
		$this->assertArrayNotHasKey( 'active_count', $data['plugins'] );
		$this->assertArrayNotHasKey( 'inactive_count', $data['plugins'] );
		$this->assertStringNotContainsString( 'owner@example.org', json_encode( $data ) );
		$this->assertStringNotContainsString( 'Akismet', json_encode( $data ), 'Inactive plugins are never listed.' );

		$this->assertArrayNotHasKey( 'license', $data );
		$this->assertArrayNotHasKey( 'meta', $data );
	}

	/**
	 * Commercial plugins keep the full payload, plus the plugin URL.
	 *
	 * @test
	 */
	public function commercial_payload_keeps_every_group() {
		$this->options['my-plugin_insights_token'] = 'tok123';
		$this->options['admin_email']              = 'owner@example.org';

		$data = $this->collector( [], 'commercial' )->collect( 'daily' );

		$this->assertSame(
			[ 'event', 'mode', 'token', 'plugin_version', 'plugin_status', 'site', 'admin', 'wp', 'server', 'users', 'plugins' ],
			array_keys( $data )
		);
		$this->assertSame( 'commercial', $data['mode'] );
		$this->assertSame( 'tok123', $data['token'] );

		$this->assertSame( [ 'url', 'name', 'locale', 'is_local', 'multisite' ], array_keys( $data['site'] ) );
		$this->assertSame( [ 'email' => 'owner@example.org', 'name' => 'Site Admin' ], $data['admin'] );

		$this->assertSame( [ 'version', 'memory_limit', 'debug_mode', 'theme' ], array_keys( $data['wp'] ) );
		$this->assertIsBool( $data['wp']['debug_mode'] );

		$this->assertSame(
			[ 'php_version', 'db_version', 'server_software', 'php_memory_limit', 'max_execution_time', 'upload_max_filesize' ],
			array_keys( $data['server'] )
		);
		$this->assertIsInt( $data['server']['max_execution_time'] );

		$this->assertSame( [ 'total' => 3, 'by_role' => [ 'administrator' => 1, 'subscriber' => 2 ] ], $data['users'] );

		$this->assertSame( [ 'active_count', 'inactive_count', 'active' ], array_keys( $data['plugins'] ) );
		$this->assertSame( 2, $data['plugins']['active_count'] );
		$this->assertSame( 1, $data['plugins']['inactive_count'] );
		$this->assertSame(
			[
				[ 'slug' => 'my-plugin', 'name' => 'My Plugin', 'version' => '2.0.0', 'url' => 'https://example.com/my-plugin/' ],
				[ 'slug' => 'hello', 'name' => 'Hello Dolly', 'version' => '1.7.2', 'url' => '' ],
			],
			$data['plugins']['active']
		);
	}

	/** @test */
	public function plugin_url_is_the_plugin_uri_header_passed_through_esc_url_raw() {
		\Brain\Monkey\Functions\when( 'esc_url_raw' )->alias( function ( $url ) {
			return 'javascript:alert(1)' === $url ? '' : $url;
		} );
		$this->installed_plugins['hello.php']['PluginURI'] = 'javascript:alert(1)';

		foreach ( [ 'consent', 'commercial' ] as $mode ) {
			$active = $this->collector( [], $mode )->get_plugins_data()['active'];

			$this->assertSame( 'https://example.com/my-plugin/', $active[0]['url'], $mode );
			$this->assertSame( '', $active[1]['url'], $mode );
		}
	}

	/** @test */
	public function payload_is_json_encodable() {
		$this->assertNotFalse( json_encode( $this->collector()->collect() ) );
		$this->assertNotFalse( json_encode( $this->collector( [], 'commercial' )->collect() ) );
	}

	/** @test */
	public function active_plugins_list_is_capped_at_200_but_counts_are_not() {
		$this->installed_plugins = [];
		$this->active_plugins    = [];

		for ( $i = 1; $i <= 250; $i++ ) {
			$this->installed_plugins[ "p{$i}/p{$i}.php" ] = [ 'Name' => "P{$i}", 'Version' => '1.0' ];
			$this->active_plugins[]                       = "p{$i}/p{$i}.php";
		}
		$this->installed_plugins['off/off.php'] = [ 'Name' => 'Off', 'Version' => '1.0' ];

		$plugins = $this->collector( [], 'commercial' )->get_plugins_data();

		$this->assertCount( 200, $plugins['active'] );
		$this->assertSame( 250, $plugins['active_count'] );
		$this->assertSame( 1, $plugins['inactive_count'] );

		$free = $this->collector()->get_plugins_data();

		$this->assertSame( [ 'active' ], array_keys( $free ) );
		$this->assertCount( 200, $free['active'] );
	}

	/** @test */
	public function meta_merges_callback_then_static_and_resolves_closures() {
		$collector = $this->collector(
			[
				'meta'          => [
					'posts'  => function () {
						return 7;
					},
					'plan'   => 'time', // A plain string naming a function stays data.
					'shared' => 'static wins',
				],
				'meta_callback' => function () {
					return [ 'shared' => 'callback', 'lists' => 3 ];
				},
			]
		);

		$data = $collector->collect();

		$this->assertSame( [ 'shared' => 'static wins', 'lists' => 3, 'posts' => 7, 'plan' => 'time' ], $data['meta'] );
	}

	/** @test */
	public function license_is_sent_whenever_the_callback_returns_a_string() {
		$empty = $this->collector( [ 'license_callback' => function () {
			return '';
		} ], 'commercial' )->collect();
		$null  = $this->collector( [ 'license_callback' => function () {
			return null;
		} ], 'commercial' )->collect();
		$false = $this->collector( [ 'license_callback' => function () {
			return false;
		} ], 'commercial' )->collect();
		$set   = $this->collector( [ 'license_callback' => function () {
			return ' KEY-123 ';
		} ], 'commercial' )->collect();

		// '' is sent: it tells the server to unbind the install (seat release).
		$this->assertArrayHasKey( 'license', $empty );
		$this->assertSame( '', $empty['license'] );
		$this->assertArrayNotHasKey( 'license', $null );
		$this->assertArrayNotHasKey( 'license', $false );
		$this->assertSame( 'KEY-123', $set['license'] );
		$this->assertSame( 'commercial', $set['mode'] );
	}

	/** @test */
	public function free_collector_never_sends_a_license_key() {
		$this->options['my-plugin_insights_consent'] = 'yes';

		$data = $this->collector()->collect();

		$this->assertArrayNotHasKey( 'license', $data );
		$this->assertNull( $this->collector()->get_license() );
	}

	/** @test */
	public function preview_before_consent_writes_no_token() {
		$data = $this->collector()->collect();

		$this->assertSame( '', $data['token'] );
		$this->assertArrayNotHasKey( 'my-plugin_insights_token', $this->options );
	}

	/** @test */
	public function commercial_mode_generates_a_token() {
		$data = $this->collector( [], 'commercial' )->collect();

		$this->assertSame( 'abcdefghijklmnopqrstuvwxyz012345', $data['token'] );
		$this->assertSame( 'abcdefghijklmnopqrstuvwxyz012345', $this->options['my-plugin_insights_token'] );
	}

	/** @test */
	public function status_follows_the_property() {
		$collector                 = $this->collector();
		$collector->product_status = 'inactive';

		$this->assertSame( 'inactive', $collector->collect()['plugin_status'] );
	}

	/**
	 * @test
	 * @dataProvider local_hosts
	 */
	public function detects_local_hosts( $url, $expected ) {
		\Brain\Monkey\Functions\when( 'site_url' )->justReturn( $url );

		$this->assertSame( $expected, $this->collector()->is_local_server() );
	}

	public function local_hosts(): array {
		return [
			'public'    => [ 'https://example.org', false ],
			'localhost' => [ 'http://localhost:8080', true ],
			'.test'     => [ 'https://site.test', true ],
			'.local'    => [ 'https://site.local', true ],
			'no dot'    => [ 'http://devbox', true ],
		];
	}

	/** @test */
	public function loopback_server_address_is_local() {
		$_SERVER['SERVER_ADDR'] = '127.0.0.1';

		try {
			$this->assertTrue( $this->collector()->is_local_server() );
		} finally {
			unset( $_SERVER['SERVER_ADDR'] );
		}
	}

	/** @test */
	public function basename_to_slug_handles_single_file_plugins() {
		$this->assertSame( 'akismet', Collector::basename_to_slug( 'akismet/akismet.php' ) );
		$this->assertSame( 'hello', Collector::basename_to_slug( 'hello.php' ) );
	}
}
