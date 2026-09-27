<?php
namespace Shazzad\PluginUpdater\Tests\V4;

use Shazzad\PluginUpdater\V4\Insights;
use Shazzad\PluginUpdater\V4\Insights\Notice;

/**
 * Notice that records the redirect instead of exiting.
 */
class TestableNotice extends Notice {

	/** @var string[] */
	public $redirected = [];

	protected function redirect( $url ) {
		wp_safe_redirect( $url );
		$this->redirected[] = $url;
	}
}

/**
 * Consent notice rendering and the Allow / No thanks handler.
 */
class NoticeTest extends TestCase {

	private function insights_with_notice( array $notice = [], array $config = [] ): Insights {
		$insights         = $this->create_insights( array_merge( [ 'notice' => false ], $config ) );
		$insights->notice = new TestableNotice( $insights, $notice );

		return $insights;
	}

	private function render( Insights $insights ): string {
		ob_start();
		$insights->notice->render();
		return ob_get_clean();
	}

	private function request( $action, $slug = 'my-plugin' ) {
		$_GET = [
			'wprepo_insights'        => $slug,
			'wprepo_insights_action' => $action,
			'_wpnonce'               => 'abc',
		];
	}

	/** @test */
	public function renders_while_consent_is_unset() {
		$insights = $this->insights_with_notice( [], [ 'name' => 'Adminkeep', 'privacy_url' => 'https://example.com/privacy' ] );

		$output = $this->render( $insights );

		$this->assertStringContainsString( 'notice notice-info', $output );
		$this->assertStringContainsString( 'Want to help make <strong>Adminkeep</strong> even better? Allow Adminkeep to collect', $output );
		$this->assertStringContainsString( '<details><summary>What we collect</summary>', $output );
		$this->assertStringContainsString( "Your site's admin email address and administrator name", $output );
		$this->assertStringContainsString( 'names and versions of active plugins', $output );
		$this->assertStringContainsString( 'href="https://example.com/privacy"', $output );
		$this->assertStringContainsString( 'wprepo_insights=my-plugin&wprepo_insights_action=allow&_wpnonce=nonce-wprepo_insights_my-plugin_allow', $output );
		$this->assertStringContainsString( 'wprepo_insights_action=decline&_wpnonce=nonce-wprepo_insights_my-plugin_decline', $output );
		$this->assertStringContainsString( '>Allow</a>', $output );
		$this->assertStringContainsString( '>No thanks</a>', $output );
		$this->assertStringNotContainsString( '<script', $output );
	}

	/**
	 * The notice is the consent: it must disclose every group of data
	 * Collector::collect() sends.
	 *
	 * @test
	 */
	public function discloses_every_payload_group() {
		$items = implode( "\n", $this->insights_with_notice()->notice->get_collected_items() );

		$expected = [
			'Site name, URL and language',                 // site.name / url / locale.
			'multisite',                                   // site.multisite.
			'local development site',                      // site.is_local.
			"Your site's admin email address and administrator name", // admin.
			'WordPress version, memory limit and debug mode', // wp.version / memory_limit / debug_mode.
			'Active theme (name, version and parent theme)',  // wp.theme.
			'PHP and MySQL versions, server software',     // server.*.
			'PHP memory, execution time and upload limits', // server limits.
			'Number of users on your site, by role',       // users.total / by_role.
			'Number of active and inactive plugins',       // plugins.*_count.
			'names and versions of active plugins',        // plugins.active.
		];

		foreach ( $expected as $needle ) {
			$this->assertStringContainsString( $needle, $items );
		}

		$this->assertStringNotContainsString( 'Your name', $items, 'The payload carries the site admin, not the clicking user.' );
		$this->assertStringNotContainsString( 'Usage statistics', $items, 'No meta configured, so no meta line.' );
	}

	/** @test */
	public function meta_or_meta_callback_adds_a_usage_statistics_line() {
		$with_meta = $this->insights_with_notice( [], [ 'name' => 'Adminkeep', 'meta' => [ 'lists' => 3 ] ] );
		$this->assertContains( 'Usage statistics specific to Adminkeep', $with_meta->notice->get_collected_items() );

		$with_callback = $this->insights_with_notice(
			[],
			[
				'meta_callback' => function () {
					return [ 'lists' => 3 ];
				},
			]
		);
		$this->assertContains( 'Usage statistics specific to My Plugin', $with_callback->notice->get_collected_items() );
		$this->assertStringContainsString( '<li>Usage statistics specific to My Plugin</li>', $this->render( $with_callback ) );
	}

	/** @test */
	public function extra_items_are_appended_and_invalid_entries_dropped() {
		$insights = $this->insights_with_notice(
			[ 'items' => [ 'Number of job listings', 42, '', '  ', [ 'nested' ], 'Which ATS you connect' ] ],
			[ 'meta' => [ 'jobs' => 1 ] ]
		);

		$items = $insights->notice->get_collected_items();

		$this->assertSame( [ 'Number of job listings', 'Which ATS you connect' ], $insights->notice->items );
		$this->assertSame( [ 'Usage statistics specific to My Plugin', 'Number of job listings', 'Which ATS you connect' ], array_slice( $items, -3 ) );
		$this->assertStringContainsString( '<li>Which ATS you connect</li>', $this->render( $insights ) );
	}

	/** @test */
	public function omits_learn_more_without_privacy_url() {
		$output = $this->render( $this->insights_with_notice() );

		$this->assertStringNotContainsString( 'Learn more', $output );
	}

	/** @test */
	public function custom_text_replaces_name_placeholder() {
		$output = $this->render( $this->insights_with_notice( [ 'text' => 'Help %s, 100% optional.' ] ) );

		$this->assertStringContainsString( 'Help My Plugin, 100% optional.', $output );
	}

	/** @test */
	public function hidden_once_consent_is_set() {
		foreach ( [ 'yes', 'no' ] as $state ) {
			$this->options['my-plugin_insights_consent'] = $state;

			$this->assertSame( '', $this->render( $this->insights_with_notice() ), $state );
		}
	}

	/** @test */
	public function hidden_without_manage_options() {
		$this->can_manage = false;

		$this->assertSame( '', $this->render( $this->insights_with_notice() ) );
	}

	/** @test */
	public function screens_limit_where_it_renders() {
		$insights = $this->insights_with_notice( [ 'screens' => [ 'settings_page_my-plugin' ] ] );

		$this->screen = null;
		$this->assertSame( '', $this->render( $insights ) );

		$this->screen = (object) [ 'id' => 'dashboard' ];
		$this->assertSame( '', $this->render( $insights ) );

		$this->screen = (object) [ 'id' => 'settings_page_my-plugin' ];
		$this->assertStringContainsString( 'notice-info', $this->render( $insights ) );
	}

	/** @test */
	public function show_callback_gates_rendering() {
		$show     = false;
		$insights = $this->insights_with_notice(
			[
				'show_callback' => function () use ( &$show ) {
					return $show;
				},
			]
		);

		$this->assertSame( '', $this->render( $insights ) );

		$show = true;
		$this->assertStringContainsString( 'notice-info', $this->render( $insights ) );
	}

	/** @test */
	public function notice_false_registers_no_render_or_handler() {
		$insights = $this->create_insights( [ 'notice' => false ] );

		$this->assertNull( $insights->notice );
		$this->assertArrayNotHasKey( 'admin_notices', $this->hooks );
	}

	/** @test */
	public function allow_sets_yes_token_schedules_sends_once_and_redirects() {
		$insights = $this->insights_with_notice();
		$this->request( 'allow' );

		$insights->notice->handle_action();

		$this->assertSame( 'yes', $insights->get_consent() );
		$this->assertSame( 'abcdefghijklmnopqrstuvwxyz012345', $this->options['my-plugin_insights_token'] );
		$this->assertSame( 'daily', $this->cron['wprepo_insights_track_my-plugin'] );
		$this->assertCount( 1, $this->http );
		$this->assertSame( 'optin', $this->http_body()['event'] );
		$this->assertSame( [ 'http://example.org/wp-admin/index.php' ], $insights->notice->redirected );
	}

	/** @test */
	public function decline_sets_no_with_zero_http_calls() {
		$insights = $this->insights_with_notice();
		$this->request( 'decline' );

		$insights->notice->handle_action();

		$this->assertSame( 'no', $insights->get_consent() );
		$this->assertSame( [], $this->http );
		$this->assertSame( [], $this->cron );
		$this->assertCount( 1, $insights->notice->redirected );
	}

	/** @test */
	public function bad_nonce_does_nothing() {
		$this->nonce_valid = false;
		$insights          = $this->insights_with_notice();
		$this->request( 'allow' );

		$insights->notice->handle_action();

		$this->assertSame( '', $insights->get_consent() );
		$this->assertSame( [], $this->http );
		$this->assertSame( [], $insights->notice->redirected );
	}

	/** @test */
	public function missing_capability_does_nothing() {
		$this->can_manage = false;
		$insights         = $this->insights_with_notice();
		$this->request( 'allow' );

		$insights->notice->handle_action();

		$this->assertSame( '', $insights->get_consent() );
		$this->assertSame( [], $this->http );
		$this->assertSame( [], $insights->notice->redirected );
	}

	/** @test */
	public function ajax_requests_are_skipped() {
		$this->doing_ajax = true;
		$insights         = $this->insights_with_notice();
		$this->request( 'allow' );

		$insights->notice->handle_action();

		$this->assertSame( '', $insights->get_consent() );
		$this->assertSame( [], $this->http );
		$this->assertSame( [], $insights->notice->redirected );
	}

	/** @test */
	public function another_plugins_request_is_ignored() {
		$insights = $this->insights_with_notice();
		$this->request( 'allow', 'other-plugin' );

		$insights->notice->handle_action();

		$this->assertSame( '', $insights->get_consent() );
		$this->assertSame( [], $insights->notice->redirected );
	}

	/** @test */
	public function unknown_action_is_ignored() {
		$insights = $this->insights_with_notice();
		$this->request( 'hack' );

		$insights->notice->handle_action();

		$this->assertSame( '', $insights->get_consent() );
		$this->assertSame( [], $insights->notice->redirected );
	}

	/** @test */
	public function requests_without_args_are_ignored() {
		$insights = $this->insights_with_notice();

		$insights->notice->handle_action();

		$this->assertSame( [], $this->options );
		$this->assertSame( [], $insights->notice->redirected );
	}
}
