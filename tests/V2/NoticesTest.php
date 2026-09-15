<?php
namespace Shazzad\PluginUpdater\Tests\V2;

use Brain\Monkey\Functions;
use Shazzad\PluginUpdater\V2\Admin\Notices;

/**
 * V2-only coverage: sitewide license notices with the one-week snooze.
 */
class NoticesTest extends TestCase {

	protected function tearDown(): void {
		$_GET = [];
		parent::tearDown();
	}

	/**
	 * Stubs every WP function the render path touches, with an option map.
	 *
	 * @param array $options get_option() key => value map.
	 */
	private function stub_render_environment( array $options = [] ) {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'menu_page_url' )->justReturn( 'http://example.test/wp-admin/plugins.php?page=my-plugin42' );
		Functions\when( 'add_query_arg' )->justReturn( 'http://example.test/wp-admin/plugins.php?wprepo_snooze=my-plugin42' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce123' );

		// Safe defaults for the plugins-screen check; individual tests
		// re-stub these. Must be stubbed here unconditionally — once any
		// test defines them, Brain Monkey keeps the functions defined
		// process-wide and un-mocked calls throw (see TestCase note).
		Functions\when( 'get_current_screen' )->justReturn( null );
		Functions\when( 'get_site_transient' )->justReturn( false );

		Functions\when( 'get_option' )->alias( function ( $key ) use ( $options ) {
			return array_key_exists( $key, $options ) ? $options[ $key ] : false;
		} );
	}

	private function render_output( Notices $notices ): string {
		ob_start();
		$notices->render();
		return ob_get_clean();
	}

	/** @test */
	public function renders_set_license_notice_when_unlicensed() {
		$integration = $this->create_integration( [ 'license' => true, 'menu' => [] ] );
		$this->stub_render_environment();

		$output = $this->render_output( $integration->notices );

		$this->assertStringContainsString( 'notice-warning', $output );
		$this->assertStringContainsString( 'My Plugin', $output );
		$this->assertStringContainsString( 'enter your license key', $output );
		$this->assertStringContainsString( 'page=my-plugin42', $output );
		$this->assertStringContainsString( 'Dismiss for a week', $output );
	}

	/** @test */
	public function renders_renew_notice_with_renewal_link_when_expired() {
		$integration = $this->create_integration( [ 'license' => true ] );
		$this->stub_render_environment( [
			'my-plugin42_code' => 'ABC-123',
			'my-plugin42_data' => [
				'status'      => 'expired',
				'renewal_url' => 'https://portal.example.test/renew',
			],
		] );

		$output = $this->render_output( $integration->notices );

		$this->assertStringContainsString( 'your license has expired', $output );
		$this->assertStringContainsString( 'https://portal.example.test/renew', $output );
		$this->assertStringContainsString( 'Renew your license', $output );
	}

	/** @test */
	public function renders_nothing_when_license_is_active() {
		$integration = $this->create_integration( [ 'license' => true ] );
		$this->stub_render_environment( [
			'my-plugin42_code' => 'ABC-123',
			'my-plugin42_data' => [ 'status' => 'active' ],
		] );

		$this->assertSame( '', $this->render_output( $integration->notices ) );
	}

	/** @test */
	public function renders_nothing_without_update_plugins_capability() {
		$integration = $this->create_integration( [ 'license' => true ] );
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->assertSame( '', $this->render_output( $integration->notices ) );
	}

	/** @test */
	public function renders_nothing_on_the_products_own_license_page() {
		$integration = $this->create_integration( [ 'license' => true ] );
		$this->stub_render_environment();

		$_GET['page'] = 'my-plugin42';

		$this->assertSame( '', $this->render_output( $integration->notices ) );
	}

	/** @test */
	public function plugins_screen_notice_defers_to_a_pending_update_row() {
		$integration = $this->create_integration( [ 'license' => true, 'menu' => [] ] );
		$this->stub_render_environment();
		Functions\when( 'get_current_screen' )->justReturn( (object) [ 'id' => 'plugins' ] );
		Functions\when( 'get_site_transient' )->justReturn( (object) [
			'response' => [ 'my-plugin/my-plugin.php' => (object) [ 'new_version' => '9.9.9' ] ],
		] );

		$this->assertSame( '', $this->render_output( $integration->notices ) );
	}

	/** @test */
	public function plugins_screen_notice_renders_when_no_update_is_pending() {
		$integration = $this->create_integration( [ 'license' => true, 'menu' => [] ] );
		$this->stub_render_environment();
		Functions\when( 'get_current_screen' )->justReturn( (object) [ 'id' => 'plugins' ] );
		Functions\when( 'get_site_transient' )->justReturn( (object) [ 'response' => [] ] );

		$output = $this->render_output( $integration->notices );

		$this->assertStringContainsString( 'enter your license key', $output );
	}

	/** @test */
	public function other_screens_render_the_notice_even_with_a_pending_update() {
		$integration = $this->create_integration( [ 'license' => true, 'menu' => [] ] );
		$this->stub_render_environment();
		Functions\when( 'get_current_screen' )->justReturn( (object) [ 'id' => 'dashboard' ] );
		Functions\when( 'get_site_transient' )->justReturn( (object) [
			'response' => [ 'my-plugin/my-plugin.php' => (object) [ 'new_version' => '9.9.9' ] ],
		] );

		$output = $this->render_output( $integration->notices );

		$this->assertStringContainsString( 'enter your license key', $output );
	}

	/** @test */
	public function active_snooze_suppresses_the_notice() {
		$integration = $this->create_integration( [ 'license' => true ] );
		$this->stub_render_environment( [
			'my-plugin42_notice_snooze_unlicensed' => time() + 3600,
		] );

		$this->assertSame( '', $this->render_output( $integration->notices ) );
	}

	/** @test */
	public function lapsed_snooze_no_longer_suppresses_the_notice() {
		$integration = $this->create_integration( [ 'license' => true ] );
		$this->stub_render_environment( [
			'my-plugin42_notice_snooze_unlicensed' => time() - 10,
		] );

		$output = $this->render_output( $integration->notices );

		$this->assertStringContainsString( 'enter your license key', $output );
	}

	/**
	 * Notices subclass whose redirect records instead of exiting.
	 */
	private function create_testable_notices( $integration ) {
		return new class( $integration ) extends Notices {
			public $redirected = null;

			protected function redirect( $url ) {
				$this->redirected = $url;
			}
		};
	}

	private function stub_snooze_request( string $product, string $type ) {
		$_GET = [
			'wprepo_snooze'      => $product,
			'wprepo_snooze_type' => $type,
			'_wpnonce'           => 'nonce123',
		];

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'remove_query_arg' )->justReturn( 'http://example.test/wp-admin/plugins.php' );
	}

	/** @test */
	public function snooze_handler_stores_a_week_long_snooze_and_redirects() {
		$integration = $this->create_integration( [ 'license' => true ] );
		$notices     = $this->create_testable_notices( $integration );

		$this->stub_snooze_request( 'my-plugin42', 'unlicensed' );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		$stored = [];
		Functions\when( 'update_option' )->alias( function ( $key, $value, $autoload = null ) use ( &$stored ) {
			$stored[ $key ] = $value;
			return true;
		} );

		$before = time();
		$notices->handle_snooze();

		$this->assertArrayHasKey( 'my-plugin42_notice_snooze_unlicensed', $stored );
		$this->assertGreaterThanOrEqual( $before + Notices::SNOOZE_SECONDS, $stored['my-plugin42_notice_snooze_unlicensed'] );
		$this->assertSame( 'http://example.test/wp-admin/plugins.php', $notices->redirected );
	}

	/** @test */
	public function snooze_handler_rejects_an_invalid_nonce() {
		$integration = $this->create_integration( [ 'license' => true ] );
		$notices     = $this->create_testable_notices( $integration );

		$this->stub_snooze_request( 'my-plugin42', 'unlicensed' );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		Functions\expect( 'update_option' )->never();

		$notices->handle_snooze();

		$this->assertNull( $notices->redirected );
	}

	/** @test */
	public function snooze_handler_ignores_other_products_dismissals() {
		$integration = $this->create_integration( [ 'license' => true ] );
		$notices     = $this->create_testable_notices( $integration );

		$this->stub_snooze_request( 'someone-else7', 'unlicensed' );
		Functions\expect( 'wp_verify_nonce' )->never();
		Functions\expect( 'update_option' )->never();

		$notices->handle_snooze();

		$this->assertNull( $notices->redirected );
	}

	/** @test */
	public function snooze_handler_ignores_unknown_notice_types() {
		$integration = $this->create_integration( [ 'license' => true ] );
		$notices     = $this->create_testable_notices( $integration );

		$this->stub_snooze_request( 'my-plugin42', 'bogus' );
		Functions\expect( 'update_option' )->never();

		$notices->handle_snooze();

		$this->assertNull( $notices->redirected );
	}
}
