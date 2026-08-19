<?php
/**
 * WordPress Plugin Updater License Notices.
 *
 * @package Shazzad\PluginUpdater\V2
 * @version 2.0
 */
namespace Shazzad\PluginUpdater\V2\Admin;

use Shazzad\PluginUpdater\V2\Integration;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\Notices' ) ) :

	/**
	 * Class Notices
	 *
	 * Renders sitewide admin notices when the site cannot receive updates:
	 * no license key saved, or the saved license has expired. Each notice is
	 * dismissible for one week, per product and per notice type, stored
	 * site-wide.
	 *
	 * @since 2.0.0
	 */
	class Notices {

		/**
		 * One week, in seconds. Inlined rather than WEEK_IN_SECONDS so the
		 * class cannot fatal in contexts where WP constants are absent.
		 *
		 * @since 2.0.0
		 *
		 * @var int
		 */
		const SNOOZE_SECONDS = 604800;

		/**
		 * Notice types this class knows how to render.
		 *
		 * @since 2.0.0
		 *
		 * @var string[]
		 */
		const TYPES = [ 'unlicensed', 'expired' ];

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

			add_action( 'admin_notices', [ $this, 'render' ] );
			add_action( 'admin_init', [ $this, 'handle_snooze' ] );
		}

		/**
		 * Resolves which notice, if any, applies right now.
		 *
		 * The two states are mutually exclusive: a site without a license key
		 * has no stored status worth reporting, so `unlicensed` wins.
		 *
		 * @since 2.0.0
		 *
		 * @return string 'unlicensed', 'expired', or '' when no notice applies.
		 */
		public function get_notice_type() {
			if ( ! $this->integration->license_enabled ) {
				return '';
			}

			if ( ! $this->integration->has_license_code() ) {
				return 'unlicensed';
			}

			if ( 'expired' === $this->integration->get_license_status() ) {
				return 'expired';
			}

			return '';
		}

		/**
		 * Option key holding the snooze-until timestamp for a notice type.
		 *
		 * @since 2.0.0
		 *
		 * @param string $type Notice type.
		 * @return string
		 */
		public function get_snooze_key( $type ) {
			return "{$this->integration->get_storage_name()}_notice_snooze_{$type}";
		}

		/**
		 * Whether a notice type is currently snoozed.
		 *
		 * @since 2.0.0
		 *
		 * @param string $type Notice type.
		 * @return bool
		 */
		public function is_snoozed( $type ) {
			$until = get_option( $this->get_snooze_key( $type ) );

			return $until && time() < (int) $until;
		}

		/**
		 * Renders the applicable notice on admin_notices.
		 *
		 * Skipped for users who cannot update plugins, on the product's own
		 * license page (the full status already renders there), and while
		 * the notice type is snoozed.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public function render() {
			if ( ! current_user_can( 'update_plugins' ) ) {
				return;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page check.
			$current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

			if ( $current_page === $this->integration->license_name ) {
				return;
			}

			$type = $this->get_notice_type();

			if ( ! $type || $this->is_snoozed( $type ) ) {
				return;
			}

			$name = $this->integration->product_name
				? $this->integration->product_name
				: $this->integration->product_slug;

			if ( 'unlicensed' === $type ) {
				$license_url = $this->get_license_page_url();

				if ( $license_url ) {
					$message = \sprintf(
						'<strong>%s</strong>: <a href="%s">enter your license key</a> to enable plugin updates.',
						esc_html( $name ),
						esc_url( $license_url )
					);
				} else {
					$message = \sprintf(
						'<strong>%s</strong>: enter your license key to enable plugin updates.',
						esc_html( $name )
					);
				}
			} else {
				$renewal_url = $this->integration->get_license_renewal_url();

				if ( $renewal_url ) {
					$message = \sprintf(
						'<strong>%s</strong>: your license has expired. <a href="%s">Renew your license</a> to keep receiving updates.',
						esc_html( $name ),
						esc_url( $renewal_url )
					);
				} else {
					$message = \sprintf(
						'<strong>%s</strong>: your license has expired. Renew your license to keep receiving updates.',
						esc_html( $name )
					);
				}
			}

			\printf(
				'<div class="notice notice-warning wprepo-license-notice"><p>%s</p><p><a href="%s">Dismiss for a week</a></p></div>',
				$message, // Built above from escaped parts.
				esc_url( $this->get_snooze_url( $type ) )
			);
		}

		/**
		 * Snoozes a notice for one week when its dismiss link is followed.
		 *
		 * Scoped to this product via the license_name in the query arg, so
		 * multiple products embedding the library never handle each other's
		 * dismissals. Invalid requests return silently — other admin_init
		 * callbacks must keep running.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public function handle_snooze() {
			if ( ! isset( $_GET['wprepo_snooze'], $_GET['wprepo_snooze_type'], $_GET['_wpnonce'] ) ) {
				return;
			}

			if ( sanitize_key( wp_unslash( $_GET['wprepo_snooze'] ) ) !== $this->integration->license_name ) {
				return;
			}

			$type = sanitize_key( wp_unslash( $_GET['wprepo_snooze_type'] ) );

			if ( ! \in_array( $type, self::TYPES, true ) ) {
				return;
			}

			if ( ! current_user_can( 'update_plugins' ) ) {
				return;
			}

			$nonce = sanitize_key( wp_unslash( $_GET['_wpnonce'] ) );

			if ( ! wp_verify_nonce( $nonce, $this->get_snooze_action( $type ) ) ) {
				return;
			}

			update_option( $this->get_snooze_key( $type ), time() + self::SNOOZE_SECONDS, false );

			$this->redirect( remove_query_arg( [ 'wprepo_snooze', 'wprepo_snooze_type', '_wpnonce' ] ) );
		}

		/**
		 * The nonce action for a snooze request.
		 *
		 * @since 2.0.0
		 *
		 * @param string $type Notice type.
		 * @return string
		 */
		public function get_snooze_action( $type ) {
			return "wprepo_snooze_{$this->integration->license_name}_{$type}";
		}

		/**
		 * Builds the dismiss link for the current request.
		 *
		 * @since 2.0.0
		 *
		 * @param string $type Notice type.
		 * @return string
		 */
		public function get_snooze_url( $type ) {
			return add_query_arg(
				[
					'wprepo_snooze'      => $this->integration->license_name,
					'wprepo_snooze_type' => $type,
					'_wpnonce'           => wp_create_nonce( $this->get_snooze_action( $type ) ),
				]
			);
		}

		/**
		 * URL of the product's license page, or empty string when the page
		 * is disabled or not registered.
		 *
		 * admin_notices fires after admin_menu, so menu_page_url() can
		 * resolve the registered submenu whatever parent it sits under.
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

		/**
		 * Redirects and terminates. Split out so tests can intercept the exit.
		 *
		 * @since 2.0.0
		 *
		 * @param string $url Destination.
		 * @return void
		 */
		protected function redirect( $url ) {
			wp_safe_redirect( $url );
			exit;
		}
	}

endif;
