<?php
/**
 * WordPress Plugin Updater Update-Row Message.
 *
 * @package Shazzad\PluginUpdater\V2
 * @version 2.0
 */
namespace Shazzad\PluginUpdater\V2\Admin;

use Shazzad\PluginUpdater\V2\Integration;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\UpdateMessage' ) ) :

	/**
	 * Class UpdateMessage
	 *
	 * Appends an explanation inside the plugins-list update row when the
	 * update cannot install: the server serves the new version but no
	 * package to an unlicensed or expired site, and core's bare "Automatic
	 * update is unavailable" tells the customer nothing. Deliberately not
	 * dismissible — it explains a dead button right where the button is.
	 *
	 * @since 2.0.0
	 */
	class UpdateMessage {

		/**
		 * Integration instance holding shared state.
		 *
		 * @since 2.0.0
		 *
		 * @var Integration
		 */
		public Integration $integration;

		/**
		 * Constructor.
		 *
		 * @since 2.0.0
		 *
		 * @param Integration $integration Integration instance.
		 */
		public function __construct( Integration $integration ) {
			$this->integration = $integration;

			add_action( "in_plugin_update_message-{$this->integration->product_file}", [ $this, 'render' ], 10, 2 );
		}

		/**
		 * Echoes the explanation line inside the update row.
		 *
		 * Nothing is printed when the license is fine — core's normal update
		 * row needs no help.
		 *
		 * @since 2.0.0
		 *
		 * @param array       $plugin_data Plugin headers, unused.
		 * @param object|null $response    Update API response for the row, unused.
		 * @return void
		 */
		public function render( $plugin_data = [], $response = null ) {
			if ( ! $this->integration->license_enabled ) {
				return;
			}

			if ( ! $this->integration->has_license_code() ) {
				$license_url = $this->get_license_page_url();

				if ( $license_url ) {
					\printf(
						'<br /><a href="%s">Enter your license key</a> to enable this update.',
						esc_url( $license_url )
					);
				} else {
					echo '<br />Enter your license key to enable this update.';
				}

				return;
			}

			if ( 'expired' !== $this->integration->get_license_status() ) {
				return;
			}

			$renewal_url = $this->integration->get_license_renewal_url();

			if ( $renewal_url ) {
				\printf(
					'<br />Your license has expired — <a href="%s">renew your license</a> to enable this update.',
					esc_url( $renewal_url )
				);
			} else {
				echo '<br />Your license has expired — renew your license to enable this update.';
			}
		}

		/**
		 * URL of the product's license page, or empty string when the page
		 * is disabled or not registered.
		 *
		 * @since 2.0.0
		 *
		 * @return string
		 */
		public function get_license_page_url() {
			if ( ! $this->integration->display_menu || ! \function_exists( 'menu_page_url' ) ) {
				return '';
			}

			return (string) menu_page_url( $this->integration->license_name, false );
		}
	}

endif;
