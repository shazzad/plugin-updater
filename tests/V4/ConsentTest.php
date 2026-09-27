<?php
namespace Shazzad\PluginUpdater\Tests\V4;

use Shazzad\PluginUpdater\V4\Insights\Consent;

/**
 * Consent state machine: unset -> yes/no, yes <-> no, commercial always yes.
 */
class ConsentTest extends TestCase {

	/** @test */
	public function starts_unset() {
		$insights = $this->create_insights();

		$this->assertSame( '', $insights->get_consent() );
		$this->assertFalse( $insights->has_consent() );
		$this->assertFalse( $insights->consent->is_set() );
	}

	/** @test */
	public function garbage_option_reads_as_unset() {
		$this->options['my-plugin_insights_consent'] = 'maybe';

		$this->assertSame( '', $this->create_insights()->get_consent() );
	}

	/** @test */
	public function opt_in_sets_yes_token_cron_and_sends_optin() {
		$insights = $this->create_insights();

		$insights->opt_in();

		$this->assertSame( 'yes', $insights->get_consent() );
		$this->assertTrue( $insights->has_consent() );
		$this->assertSame( 'abcdefghijklmnopqrstuvwxyz012345', $this->options['my-plugin_insights_token'] );
		$this->assertSame( 'daily', $this->cron['wprepo_insights_track_my-plugin'] );
		$this->assertCount( 1, $this->http );
		$this->assertSame( 'https://repo.example.com/wp-json/wp-repo/v4/plugins/prod_abc/track', $this->http[0][0] );
		$this->assertSame( 'optin', $this->http_body()['event'] );
		$this->assertSame( 'abcdefghijklmnopqrstuvwxyz012345', $this->http_body()['token'] );
		$this->assertIsInt( $this->options['my-plugin_insights_last_send'] );
	}

	/** @test */
	public function opt_out_from_unset_sets_no_and_sends_nothing() {
		$insights = $this->create_insights();

		$this->assertNull( $insights->opt_out() );

		$this->assertSame( 'no', $insights->get_consent() );
		$this->assertSame( [], $this->http );
	}

	/** @test */
	public function opt_out_after_opt_in_sends_optout_with_token_and_clears_cron() {
		$insights = $this->create_insights();
		$insights->opt_in();
		$this->http = [];

		$insights->opt_out();

		$this->assertSame( 'no', $insights->get_consent() );
		$this->assertArrayNotHasKey( 'wprepo_insights_track_my-plugin', $this->cron );
		$this->assertCount( 1, $this->http );
		$this->assertSame( 'https://repo.example.com/wp-json/wp-repo/v4/plugins/prod_abc/optout', $this->http[0][0] );
		$this->assertSame(
			[ 'site_url' => 'https://example.org', 'token' => 'abcdefghijklmnopqrstuvwxyz012345' ],
			$this->http_body()
		);
	}

	/** @test */
	public function opt_in_again_after_opt_out_keeps_the_token() {
		$insights = $this->create_insights();
		$insights->opt_in();
		$insights->opt_out();
		\Brain\Monkey\Functions\when( 'wp_generate_password' )->justReturn( 'DIFFERENT' );

		$insights->opt_in();

		$this->assertSame( 'yes', $insights->get_consent() );
		$this->assertSame( 'abcdefghijklmnopqrstuvwxyz012345', $this->options['my-plugin_insights_token'] );
	}

	/** @test */
	public function commercial_mode_is_always_granted_and_never_stored() {
		$consent = new Consent( 'my-plugin', 'commercial' );

		$this->assertTrue( $consent->is_commercial() );
		$this->assertSame( 'yes', $consent->get() );
		$this->assertTrue( $consent->is_granted() );

		$consent->revoke();
		$this->assertSame( 'yes', $consent->get() );
		$this->assertArrayNotHasKey( 'my-plugin_insights_consent', $this->options );
	}

	/** @test */
	public function unknown_mode_falls_back_to_consent() {
		$consent = new Consent( 'my-plugin', 'whatever' );

		$this->assertSame( 'consent', $consent->mode );
		$this->assertFalse( $consent->is_granted() );
	}

	/** @test */
	public function option_keys_follow_the_slug() {
		$consent = new Consent( 'my-plugin' );

		$this->assertSame( 'my-plugin_insights_consent', $consent->get_consent_key() );
		$this->assertSame( 'my-plugin_insights_token', $consent->get_token_key() );
	}
}
