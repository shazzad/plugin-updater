<?php
namespace Shazzad\PluginUpdater\Tests\V3;

use Shazzad\PluginUpdater\V3\Insights;
use Shazzad\PluginUpdater\V3\Insights\Notice;

/**
 * The free entry point's config surface.
 */
class InsightsConfigTest extends TestCase {

	/** @test */
	public function config_populates_properties_and_wires_subsystems() {
		$insights = $this->create_insights( [ 'name' => 'Adminkeep', 'privacy_url' => 'https://example.com/privacy' ] );

		$this->assertSame( 'https://repo.example.com/wp-json/wp-repo-insights/v1', $insights->api_url );
		$this->assertSame( 'my-plugin/my-plugin.php', $insights->file );
		$this->assertSame( 'my-plugin', $insights->slug );
		$this->assertSame( 'prod_abc', $insights->get_product_key() );
		$this->assertSame( 'Adminkeep', $insights->get_name() );
		$this->assertSame( 'https://example.com/privacy', $insights->privacy_url );
		$this->assertSame( 'consent', $insights->consent->mode );
		$this->assertInstanceOf( Notice::class, $insights->notice );
		$this->assertNotNull( $insights->collector );
		$this->assertNotNull( $insights->client );
		$this->assertNotNull( $insights->scheduler );
		$this->assertSame( [], $this->doing_it_wrong );
	}

	/** @test */
	public function accepts_basename_form_of_file() {
		$insights = $this->create_insights( [ 'file' => 'my-plugin/my-plugin.php' ] );

		$this->assertSame( 'my-plugin/my-plugin.php', $insights->file );
		$this->assertSame( 'my-plugin', $insights->slug );
	}

	/** @test */
	public function single_file_plugin_slug_is_file_name() {
		$insights = $this->create_insights( [ 'file' => 'hello.php' ] );

		$this->assertSame( 'hello', $insights->slug );
	}

	/** @test */
	public function product_id_is_used_when_no_uid() {
		$insights = $this->create_insights( [ 'product_uid' => '' ] );

		$this->assertSame( '12', $insights->get_product_key() );
		$this->assertSame( [], $this->doing_it_wrong );
	}

	/** @test */
	public function name_defaults_to_plugin_header() {
		$insights = $this->create_insights();

		$this->assertSame( 'My Plugin', $insights->get_name() );
	}

	/** @test */
	public function notice_false_builds_no_notice() {
		$insights = $this->create_insights( [ 'notice' => false ] );

		$this->assertNull( $insights->notice );
		$this->assertArrayNotHasKey( 'admin_notices', $this->hooks );
	}

	/** @test */
	public function unknown_keys_only_notify() {
		$insights = $this->create_insights( [ 'licence' => true, 'notice' => [ 'screen' => 'x' ] ] );

		$this->assertInstanceOf( Insights::class, $insights );
		$this->assertContains( 'Unrecognized config key "licence".', $this->doing_it_wrong );
		$this->assertContains( 'Unrecognized notice key "screen".', $this->doing_it_wrong );
	}

	/** @test */
	public function missing_required_keys_only_notify() {
		$insights = new Insights( [] );

		$this->assertInstanceOf( Insights::class, $insights );
		$this->assertContains( 'Missing required config key "api_url".', $this->doing_it_wrong );
		$this->assertContains( 'Missing required config key "file".', $this->doing_it_wrong );
		$this->assertContains( 'Config needs "product_uid" or "product_id".', $this->doing_it_wrong );
	}

	/** @test */
	public function bad_callables_and_types_only_notify() {
		$insights = $this->create_insights(
			[
				'meta'          => 'nope',
				'meta_callback' => 'no_such_function_xyz',
				'notice'        => [ 'show_callback' => 'no_such_function_xyz' ],
			]
		);

		$this->assertInstanceOf( Insights::class, $insights );
		$this->assertContains( 'Config key "meta_callback" is not callable and will be ignored.', $this->doing_it_wrong );
		$this->assertContains( 'Config key "meta" is not an array and will be ignored.', $this->doing_it_wrong );
		$this->assertContains( 'Notice key "show_callback" is not callable and will be ignored.', $this->doing_it_wrong );
		$this->assertNull( $insights->collector->meta_callback );
		$this->assertNull( $insights->notice->show_callback );
	}

	/** @test */
	public function notice_items_are_a_known_key_and_validated() {
		$insights = $this->create_insights( [ 'notice' => [ 'items' => [ 'Number of forms' ] ] ] );

		$this->assertSame( [], $this->doing_it_wrong );
		$this->assertSame( [ 'Number of forms' ], $insights->notice->items );

		$this->create_insights( [ 'notice' => [ 'items' => 'Number of forms' ] ] );
		$this->assertContains( 'Notice key "items" is not an array and will be ignored.', $this->doing_it_wrong );

		$this->doing_it_wrong = [];
		$insights             = $this->create_insights( [ 'notice' => [ 'items' => [ 'Number of forms', 7, '' ] ] ] );
		$this->assertSame( [ 'Notice key "items" must hold non-empty strings; other entries will be ignored.' ], $this->doing_it_wrong );
		$this->assertSame( [ 'Number of forms' ], $insights->notice->items );
	}

	/** @test */
	public function non_array_notice_notifies_and_uses_defaults() {
		$insights = $this->create_insights( [ 'notice' => 'yes' ] );

		$this->assertInstanceOf( Notice::class, $insights->notice );
		$this->assertContains( 'Config key "notice" must be an array or false; using the defaults.', $this->doing_it_wrong );
	}

	/** @test */
	public function registers_cron_lifecycle_and_notice_hooks() {
		$this->create_insights();

		$this->assertArrayHasKey( 'wprepo_insights_track_my-plugin', $this->hooks );
		$this->assertArrayHasKey( 'activate_my-plugin/my-plugin.php', $this->hooks );
		$this->assertArrayHasKey( 'deactivate_my-plugin/my-plugin.php', $this->hooks );
		$this->assertArrayHasKey( 'upgrader_process_complete', $this->hooks );
		$this->assertArrayHasKey( 'admin_notices', $this->hooks );
		$this->assertArrayHasKey( 'admin_init', $this->hooks );
	}

	/** @test */
	public function construction_sends_nothing_and_writes_nothing() {
		$this->create_insights();

		$this->assertSame( [], $this->http );
		$this->assertSame( [], $this->options );
		$this->assertSame( [], $this->cron );
	}
}
