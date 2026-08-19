<?php
namespace Shazzad\PluginUpdater\Tests\V2;

use Brain\Monkey\Functions;
use Shazzad\PluginUpdater\V2\Admin\UpdateMessage;

/**
 * V2-only coverage: the plugins-list update-row explanation line.
 */
class UpdateMessageTest extends TestCase {

	/**
	 * @param array $options get_option() key => value map.
	 */
	private function stub_environment( array $options = [] ) {
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'menu_page_url' )->justReturn( 'http://example.test/wp-admin/plugins.php?page=my-plugin42' );

		Functions\when( 'get_option' )->alias( function ( $key ) use ( $options ) {
			return array_key_exists( $key, $options ) ? $options[ $key ] : false;
		} );
	}

	private function render_output( UpdateMessage $message ): string {
		ob_start();
		$message->render();
		return ob_get_clean();
	}

	/** @test */
	public function explains_the_dead_update_when_unlicensed() {
		$integration = $this->create_integration( [ 'license' => true, 'menu' => [] ] );
		$this->stub_environment();

		$output = $this->render_output( $integration->update_message );

		$this->assertStringContainsString( 'Enter your license key', $output );
		$this->assertStringContainsString( 'page=my-plugin42', $output );
	}

	/** @test */
	public function explains_the_dead_update_when_expired_with_renewal_link() {
		$integration = $this->create_integration( [ 'license' => true ] );
		$this->stub_environment( [
			'my-plugin42_code' => 'ABC-123',
			'my-plugin42_data' => [
				'status'      => 'expired',
				'renewal_url' => 'https://portal.example.test/renew',
			],
		] );

		$output = $this->render_output( $integration->update_message );

		$this->assertStringContainsString( 'license has expired', $output );
		$this->assertStringContainsString( 'https://portal.example.test/renew', $output );
	}

	/** @test */
	public function prints_nothing_when_the_license_is_active() {
		$integration = $this->create_integration( [ 'license' => true ] );
		$this->stub_environment( [
			'my-plugin42_code' => 'ABC-123',
			'my-plugin42_data' => [ 'status' => 'active' ],
		] );

		$this->assertSame( '', $this->render_output( $integration->update_message ) );
	}

	/** @test */
	public function free_mode_gets_no_instance_at_all() {
		$integration = $this->create_integration( [ 'license' => false ] );

		$this->assertNull( $integration->update_message );
		$this->assertNull( $integration->notices );
	}

	/** @test */
	public function falls_back_to_plain_text_without_a_license_page() {
		$integration = $this->create_integration( [ 'license' => true, 'menu' => false ] );
		$this->stub_environment();

		$output = $this->render_output( $integration->update_message );

		$this->assertStringContainsString( 'Enter your license key to enable this update.', $output );
		$this->assertStringNotContainsString( '<a href', $output );
	}
}
