<?php
/**
 * WordPress Plugin Updater.
 *
 * @package Shazzad\PluginUpdater
 * @version 1.0
 */
namespace Shazzad\PluginUpdater;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Updater' ) ) :

	/**
	 * Class Updater
	 *
	 * Handles plugin update checks, license verification, and upgrade processes.
	 *
	 * @since 1.0
	 */
	class Updater {

		public Integration $integration;

		public function __construct( Integration $integration ) {
			$this->integration = $integration;

			add_action( 'init', [ $this, 'init' ] );
			add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'pre_set_transient' ], 50 );
			add_filter( 'plugins_api', [ $this, 'plugins_api' ], 10, 3 );
			add_filter( 'upgrader_package_options', [ $this, 'upgrader_package_options' ], 10 );
			add_action( 'upgrader_process_complete', [ $this, 'upgrader_process_complete' ], 20, 2 );
		}

		/**
		 * Initialize plugin data.
		 *
		 * Fetches plugin data using WordPress core function `get_plugin_data()`.
		 *
		 * @since 1.0
		 * @return void
		 */
		public function init() {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
			$plugin = get_plugin_data( WP_PLUGIN_DIR . '/' . $this->integration->product_file );

			$this->integration->product_version = $plugin['Version'];
			$this->integration->product_name    = $plugin['Name'];

			// Schedule a cron job to update license hourly.
			$hook_name = "wprepo_sync_license_data_{$this->integration->license_name}";

			if ( ! wp_next_scheduled( $hook_name ) ) {
				wp_schedule_event( time(), 'hourly', $hook_name );
			}
		}

		/**
		 * Check if our plugin has a new release and store the data to update_plugins transient.
		 *
		 * @since 1.0
		 *
		 * @param object $transient The pre_set_site_transient_update_plugins value.
		 * @return object Filtered transient.
		 */
		public function pre_set_transient( $transient ) {
			if ( property_exists( $transient, 'checked' ) && ! empty( $transient->checked ) ) {
				$response = $this->integration->api_request( 'updates' );

				if ( ! is_wp_error( $response ) && ! empty( $response['updates'] ) ) {
					$updates = $response['updates'];

					if (
						isset( $updates['new_version'] ) &&
						version_compare( $updates['new_version'], $this->integration->product_version, '>' )
					) {
						if ( ! isset( $transient->response ) ) {
							$transient->response = [];
						}

						$updates['plugin'] = $this->integration->product_file;
						$updates['slug']   = $this->integration->product_slug;

						// WordPress expects an object for the update data.
						$transient->response[ $this->integration->product_file ] = (object) $updates;

					} else {
						if ( ! isset( $transient->no_update ) ) {
							$transient->no_update = [];
						}

						if ( isset( $transient->response[ $this->integration->product_file ] ) ) {
							$transient->no_update[ $this->integration->product_file ] = $transient->response[ $this->integration->product_file ];
							unset( $transient->response[ $this->integration->product_file ] );
						} else {
							// plugin is up to date
							$transient->no_update[ $this->integration->product_file ] = (object) array(
								'slug'        => $this->integration->product_slug,
								'plugin'      => $this->integration->product_file,
								'new_version' => $this->integration->product_version,
								'url'         => '',
								'package'     => '',
							);
						}
					}
				}
			}

			return $transient;
		}

		/**
		 * Filters WordPress plugin information API (plugins_api).
		 *
		 * @since 1.0
		 *
		 * @param mixed  $return The default response object or WP_Error.
		 * @param string $action The requested action.
		 * @param object $arg    Plugin API arguments.
		 * @return mixed Updated plugin information or original return.
		 */
		public function plugins_api( $return, $action, $arg ) {
			// Only proceed for plugin_information of our plugin slug.
			if ( 'plugin_information' !== $action ) {
				return $return;
			}

			if ( ! empty( $arg ) && isset( $arg->slug ) && $arg->slug === $this->integration->product_slug ) {
				$response = $this->integration->api_request( 'details' );

				if ( is_wp_error( $response ) ) {
					$return           = new \stdClass();
					$return->sections = array(
						'error' => sprintf(
							'Unable to retrieve information. <br /><b>Error</b>: %s',
							$response->get_error_message()
						),
					);
				} elseif ( empty( $response['details'] ) ) {
					$return           = new \stdClass();
					$return->sections = array(
						'api_error' => ! empty( $response['message'] )
							? $response['message']
							: 'Errors occured. Try back later',
					);
				} else {
					$details = $response['details'];

					// Convert section content into array, apply paragraphs.
					foreach ( $details['sections'] as $k => $s ) {
						$details['sections'][ $k ] = wpautop( $s );
					}

					$return = (object) $details;
				}
			}

			return $return;
		}

		/**
		 * Helper function to tweak WordPress upgrade package options.
		 *
		 * Allows forcing WordPress to extract on top of the existing folder
		 * and delete existing files, but stops if folder already exists.
		 *
		 * @since 1.0
		 *
		 * @param array $options Options used while upgrading.
		 * @return array Updated options.
		 */
		public function upgrader_package_options( $options ) {
			if (
				isset( $options['hook_extra'] ) &&
				isset( $options['hook_extra']['plugin'] )
			) {
				if (
					stripos( $options['hook_extra']['plugin'], $this->integration->product_file ) !== false
				) {
					$options['clear_destination']           = true;
					$options['abort_if_destination_exists'] = true;
				}
			}

			return $options;
		}

		/**
		 * Once plugin is updated, ping to API server and clear plugin cache to get new data.
		 *
		 * @since 1.0
		 *
		 * @param object $upgrader The upgrader object.
		 * @param array  $args     Additional arguments about the upgrade process.
		 * @return void
		 */
		public function upgrader_process_complete( $upgrader, $args ) {
			if (
				isset( $args ) &&
				isset( $args['type'] ) &&
				$args['type'] === 'plugin' &&
				$args['action'] === 'update' &&
				isset( $args['plugins'] ) &&
				in_array( $this->integration->product_file, $args['plugins'] )
			) {
				include_once ABSPATH . 'wp-admin/includes/plugin.php';
				$plugin = get_plugin_data( WP_PLUGIN_DIR . '/' . $this->integration->product_file );

				$this->integration->clear_updates_transient();

				$this->integration->product_version = $plugin['Version'];
				$this->integration->api_request( 'ping' );
			}
		}
	}

endif;
