<?php
namespace Shazzad\PluginUpdater\Tests\V3;

use Shazzad\PluginUpdater\V3\Insights\Collector;
use Shazzad\PluginUpdater\V3\Insights\Consent;

/**
 * Payload shape.
 */
class CollectorTest extends TestCase {

	private function collector( array $args = [], $mode = 'consent' ): Collector {
		return new Collector( 'my-plugin/my-plugin.php', new Consent( 'my-plugin', $mode ), $args );
	}

	/** @test */
	public function payload_has_the_spec_shape() {
		$this->options['my-plugin_insights_consent'] = 'yes';
		$this->options['my-plugin_insights_token']   = 'tok123';
		$this->options['admin_email']                = 'owner@example.org';

		$data = $this->collector()->collect( 'daily' );

		$this->assertSame(
			[ 'event', 'mode', 'token', 'product_version', 'product_status', 'site', 'admin', 'wp', 'server', 'users', 'plugins' ],
			array_keys( $data )
		);
		$this->assertSame( 'daily', $data['event'] );
		$this->assertSame( 'consent', $data['mode'] );
		$this->assertSame( 'tok123', $data['token'] );
		$this->assertSame( '2.0.0', $data['product_version'] );
		$this->assertSame( 'active', $data['product_status'] );

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
		$this->assertSame( [ 'email' => 'owner@example.org', 'name' => 'Site Admin' ], $data['admin'] );

		$this->assertSame( '6.9', $data['wp']['version'] );
		$this->assertArrayHasKey( 'memory_limit', $data['wp'] );
		$this->assertIsBool( $data['wp']['debug_mode'] );
		$this->assertSame(
			[ 'slug' => 'child-theme', 'name' => 'Child Theme', 'version' => '1.2.3', 'parent' => 'parent-theme' ],
			$data['wp']['theme']
		);

		$this->assertSame(
			[ 'php_version', 'db_version', 'server_software', 'php_memory_limit', 'max_execution_time', 'upload_max_filesize' ],
			array_keys( $data['server'] )
		);
		$this->assertSame( phpversion(), $data['server']['php_version'] );
		$this->assertSame( '8.0.33', $data['server']['db_version'] );
		$this->assertIsInt( $data['server']['max_execution_time'] );

		$this->assertSame( [ 'total' => 3, 'by_role' => [ 'administrator' => 1, 'subscriber' => 2 ] ], $data['users'] );

		$this->assertSame( 2, $data['plugins']['active_count'] );
		$this->assertSame( 1, $data['plugins']['inactive_count'] );
		$this->assertSame(
			[
				[ 'slug' => 'my-plugin', 'name' => 'My Plugin', 'version' => '2.0.0' ],
				[ 'slug' => 'hello', 'name' => 'Hello Dolly', 'version' => '1.7.2' ],
			],
			$data['plugins']['active']
		);

		$this->assertArrayNotHasKey( 'license', $data );
		$this->assertArrayNotHasKey( 'meta', $data );
	}

	/** @test */
	public function payload_is_json_encodable() {
		$this->assertNotFalse( json_encode( $this->collector()->collect() ) );
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

		$plugins = $this->collector()->get_plugins_data();

		$this->assertCount( 200, $plugins['active'] );
		$this->assertSame( 250, $plugins['active_count'] );
		$this->assertSame( 1, $plugins['inactive_count'] );
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
	public function license_is_sent_only_when_callback_returns_a_key() {
		$empty = $this->collector( [ 'license_callback' => function () {
			return '';
		} ], 'commercial' )->collect();
		$false = $this->collector( [ 'license_callback' => function () {
			return false;
		} ], 'commercial' )->collect();
		$set   = $this->collector( [ 'license_callback' => function () {
			return 'KEY-123';
		} ], 'commercial' )->collect();

		$this->assertArrayNotHasKey( 'license', $empty );
		$this->assertArrayNotHasKey( 'license', $false );
		$this->assertSame( 'KEY-123', $set['license'] );
		$this->assertSame( 'commercial', $set['mode'] );
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

		$this->assertSame( 'inactive', $collector->collect()['product_status'] );
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
