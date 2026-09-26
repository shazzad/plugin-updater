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
		 * Hourly. The license check runs here; install tracking (the V2
		 * `ping()`) moved to the Insights scheduler's daily track, which this
		 * hourly event also backs up — see run_insights().
		 *
		 * @since 3.0.0
		 * @return void
		 */
		public function sync_license_data() {
			$this->run_insights();

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
		 * Hourly backup for the daily Insights track.
		 *
		 * The daily Insights cron is created on activation and re-created on
		 * admin_init. A plugin updated in place from V2 (WP-CLI, auto-update,
		 * a management dashboard) on a site where nobody opens wp-admin never
		 * gets either — but this hourly event is scheduled on every `init`.
		 * So: self-heal the daily event, and send the daily track when it is
		 * due. The scheduler's MIN_INTERVAL guard keeps this and the daily
		 * cron from double-sending.
		 *
		 * @since 3.0.0
		 * @return void
		 */
		private function run_insights() {
			$scheduler = $this->integration->insights_scheduler;

			if ( null === $scheduler ) {
				return;
			}

			$scheduler->maybe_schedule();
			$scheduler->run_daily();
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
