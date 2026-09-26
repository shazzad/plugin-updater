<?php
/**
 * WordPress Plugin Updater Tracker.
 *
 * @package Shazzad\PluginUpdater\V3
 * @version 3.0
 */
namespace Shazzad\PluginUpdater\V3;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\Tracker' ) ) :

	/**
	 * Class Tracker
	 *
	 * Handles plugin activation/deactivation hooks (cache refresh) and the
	 * hourly cron license sync. Install tracking lives in Insights\Scheduler.
	 *
	 * @since 3.0.0
	 */
	class Tracker {

		/**
		 * Integration instance holding shared state and API helpers.
		 *
		 * @since 3.0.0
		 *
		 * @var Integration
		 */
		public Integration $integration;

		/**
		 * Constructor.
		 *
		 * @since 3.0.0
		 *
		 * @param Integration $integration Integration instance.
		 */
		public function __construct( Integration $integration ) {
			$this->integration = $integration;

			$hook_name = "wprepo_sync_license_data_{$this->integration->license_name}";

			add_action( $hook_name, [ $this, 'sync_license_data' ] );

			add_action( "activate_{$integration->product_file}", [ $this, 'product_activated' ] );
			add_action( "deactivate_{$integration->product_file}", [ $this, 'product_deactivated' ] );
		}

		/**
		 * Synchronize license data with the remote server.
		 *
		 * Hourly. Only the license check runs here now; install tracking
		 * (the V2 `ping()`) moved to the Insights scheduler's daily track.
		 *
		 * @since 3.0.0
		 * @return void
		 */
		public function sync_license_data() {
			$license = $this->integration->get_license_code();
			if ( empty( $license ) ) {
				return;
			}

			$response = $this->integration->client->check_license();
			if ( is_wp_error( $response ) ) {
				if ( 'invalid_license' === $response->get_error_code() ) {
					$this->integration->mark_license_invalid();
				}

				return;
			}

			if ( ! empty( $response['license'] ) ) {
				$this->integration->update_license_data( $response['license'] );
			}
		}

		/**
		 * Fires once our plugin is activated; refreshes caches. The
		 * `activate` track is sent by the Insights scheduler.
		 *
		 * @since 3.0.0
		 * @return void
		 */
		public function product_activated() {
			$this->integration->product_status = 'active';
			$this->integration->clear_updates_transient();
		}

		/**
		 * Fires once our plugin is deactivated; refreshes caches. The
		 * `deactivate` track is sent by the Insights scheduler.
		 *
		 * @since 3.0.0
		 * @return void
		 */
		public function product_deactivated() {
			$this->integration->product_status = 'inactive';
			$this->integration->clear_updates_transient();
		}
	}

endif;
