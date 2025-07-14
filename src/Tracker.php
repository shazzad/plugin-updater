<?php
/**
 * WordPress Plugin Updater Tracker.
 *
 * @package Shazzad\PluginUpdater
 * @version 1.0
 */
namespace Shazzad\PluginUpdater;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Tracker' ) ) :

	/**
	 * Class Tracker
	 *
	 * Handles plugin update checks, license verification, and upgrade processes.
	 *
	 * @since 1.0
	 */
	class Tracker {

		public Integration $integration;

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
		 * @since 1.0
		 * @return void
		 */
		public function sync_license_data() {
			$license = $this->integration->get_license_code();
			if ( empty( $license ) ) {
				// do ping to notify the installation.
				$this->integration->api_request( 'ping' );

				return;
			}

			$response = $this->integration->api_request( 'check_license' );
			if ( is_wp_error( $response ) ) {
				return;
			}

			if ( ! empty( $response['license'] ) ) {
				update_option( $this->integration->license_name . '_data', $response['license'] );
			}
		}

		/**
		 * Fires once our plugin is activated; pings the API server and refreshes caches.
		 *
		 * @since 1.0
		 * @return void
		 */
		public function product_activated() {
			$this->integration->product_status = 'active';
			$this->integration->clear_updates_transient();
			$this->integration->api_request( 'ping' );
		}

		/**
		 * Fires once our plugin is deactivated; pings the API server and refreshes caches.
		 *
		 * @since 1.0
		 * @return void
		 */
		public function product_deactivated() {
			$this->integration->product_status = 'inactive';
			$this->integration->clear_updates_transient();
			$this->integration->api_request( 'ping' );
		}
	}

endif;
