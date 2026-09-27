<?php
namespace Shazzad\PluginUpdater\Tests\V4;

use Brain\Monkey\Functions;
use Shazzad\PluginUpdater\V4\Insights;

/**
 * Insights::uninstall() and network deactivation, on single sites and on a
 * simulated multisite network (per-blog options and cron swapped in and
 * out by switch_to_blog() / restore_current_blog()).
 */
class UninstallTest extends TestCase {

	/** @var array blog id => [ 'options' => [], 'cron' => [] ] for the blogs not switched in */
	private $blogs = [];

	/** @var int[] blog ids to restore */
	private $blog_stack = [];

	/** @var int */
	private $blog_id = 1;

	/** @var bool whether the live options/cron belong to a blog yet */
	private $network_loaded = false;

	/** @var int[] blogs switched to, in order */
	private $switched = [];

	private function stored_state( string $slug = 'my-plugin' ): array {
		return [
			"{$slug}_insights_consent"        => 'yes',
			"{$slug}_insights_token"          => 'tok',
			"{$slug}_insights_last_send"      => 123,
			"{$slug}_insights_optout_pending" => [ 'since' => 1, 'last_try' => 1 ],
			"{$slug}_insights_last_attempt"   => 456,
			"{$slug}_insights_disabled_version" => '2.0.0',
		];
	}

	/**
	 * Turns the test environment into a network of three blogs, each with
	 * its own Insights options and cron event, plus an unrelated option.
	 */
	private function network() {
		$test = $this;

		foreach ( [ 1, 2, 3 ] as $id ) {
			$this->blogs[ $id ] = [
				'options' => $this->stored_state() + [ 'blogname' => "Blog {$id}" ],
				'cron'    => [ 'wprepo_insights_track_my-plugin' => 'daily', 'other_hook' => 'daily' ],
			];
		}

		$this->load_blog( 1 );

		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_sites' )->alias( function ( $args ) use ( $test ) {
			$test->assertSame( 'ids', $args['fields'] );
			$test->assertSame( 0, $args['number'] );
			return [ '1', '2', '3' ];
		} );
		Functions\when( 'switch_to_blog' )->alias( function ( $id ) use ( $test ) {
			$test->switched[]   = $id;
			$test->blog_stack[] = $test->blog_id;
			$test->load_blog( $id );
			return true;
		} );
		Functions\when( 'restore_current_blog' )->alias( function () use ( $test ) {
			$test->load_blog( array_pop( $test->blog_stack ) );
			return true;
		} );
	}

	/**
	 * Saves the live options/cron to the current blog and loads another's.
	 */
	public function load_blog( int $id ) {
		if ( $this->network_loaded ) {
			$this->blogs[ $this->blog_id ] = [ 'options' => $this->options, 'cron' => $this->cron ];
		}

		$this->network_loaded = true;

		$this->blog_id = $id;
		$this->options = $this->blogs[ $id ]['options'];
		$this->cron    = $this->blogs[ $id ]['cron'];
	}

	private function all_blogs(): array {
		$this->blogs[ $this->blog_id ] = [ 'options' => $this->options, 'cron' => $this->cron ];
		return $this->blogs;
	}

	/** @test */
	public function uninstall_removes_every_option_and_the_cron_on_a_single_site() {
		$this->options = $this->stored_state() + $this->stored_state( 'other-plugin' );
		$this->cron    = [
			'wprepo_insights_track_my-plugin'    => 'daily',
			'wprepo_insights_track_other-plugin' => 'daily',
		];

		Insights::uninstall( WP_PLUGIN_DIR . '/my-plugin/my-plugin.php' );

		$this->assertSame( $this->stored_state( 'other-plugin' ), $this->options );
		$this->assertSame( [ 'wprepo_insights_track_other-plugin' => 'daily' ], $this->cron );
		$this->assertSame( [], $this->http );
	}

	/** @test */
	public function uninstall_accepts_the_basename_uninstall_php_receives() {
		$this->options = $this->stored_state();

		Insights::uninstall( 'my-plugin/my-plugin.php' ); // WP_UNINSTALL_PLUGIN.

		$this->assertSame( [], $this->options );
	}

	/** @test */
	public function uninstall_of_a_single_file_plugin_uses_the_file_name_slug() {
		$this->options = $this->stored_state( 'hello' );

		Insights::uninstall( 'hello.php' );

		$this->assertSame( [], $this->options );
	}

	/** @test */
	public function uninstall_with_an_empty_file_touches_nothing() {
		$this->options = $this->stored_state();

		Insights::uninstall( '' );

		$this->assertSame( $this->stored_state(), $this->options );
	}

	/** @test */
	public function uninstall_cleans_every_site_of_a_network() {
		$this->network();

		Insights::uninstall( 'my-plugin/my-plugin.php' );

		$this->assertSame( [ 1, 2, 3 ], $this->switched );
		$this->assertSame( [], $this->blog_stack, 'Every switch_to_blog() was restored.' );
		$this->assertSame( 1, $this->blog_id );

		foreach ( $this->all_blogs() as $id => $blog ) {
			$this->assertSame( [ 'blogname' => "Blog {$id}" ], $blog['options'], "blog {$id}" );
			$this->assertSame( [ 'other_hook' => 'daily' ], $blog['cron'], "blog {$id}" );
		}

		$this->assertSame( [], $this->http );
	}

	/** @test */
	public function network_deactivation_clears_the_cron_on_every_site() {
		$this->network();
		$insights = $this->create_insights( [ 'notice' => false ] );

		foreach ( $this->hooks['deactivate_my-plugin/my-plugin.php'] as $callback ) {
			call_user_func( $callback, true ); // WordPress passes $network_deactivating.
		}

		$this->assertCount( 1, $this->http, 'Only the current site sends a deactivate track.' );
		$this->assertSame( 'deactivate', $this->http_body()['event'] );

		foreach ( $this->all_blogs() as $id => $blog ) {
			$this->assertArrayNotHasKey( 'wprepo_insights_track_my-plugin', $blog['cron'], "blog {$id}" );
			$this->assertArrayHasKey( 'other_hook', $blog['cron'], "blog {$id}" );
			$this->assertSame( 'yes', $blog['options']['my-plugin_insights_consent'], "Options stay on blog {$id}." );
		}

		$this->assertSame( 'inactive', $insights->collector->product_status );
	}

	/** @test */
	public function single_site_deactivation_on_a_network_leaves_other_sites_alone() {
		$this->network();
		$insights = $this->create_insights( [ 'notice' => false ] );

		$insights->scheduler->product_deactivated( false );

		$this->assertSame( [], $this->switched );

		$blogs = $this->all_blogs();
		$this->assertArrayNotHasKey( 'wprepo_insights_track_my-plugin', $blogs[1]['cron'] );
		$this->assertArrayHasKey( 'wprepo_insights_track_my-plugin', $blogs[2]['cron'] );
		$this->assertArrayHasKey( 'wprepo_insights_track_my-plugin', $blogs[3]['cron'] );
	}

	/** @test */
	public function option_keys_cover_everything_insights_stores() {
		$this->assertSame(
			array_keys( $this->stored_state() ),
			Insights::get_option_keys( 'my-plugin' )
		);
	}
}
