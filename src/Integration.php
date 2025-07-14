<?php
/**
 * WordPress Plugin Updater Integration.
 *
 * @package Shazzad\PluginUpdater
 * @version 1.0
 */
namespace Shazzad\PluginUpdater;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Integration' ) ) :

	/**
	 * Class Integration
	 *
	 * Handles plugin update checks, license verification, and upgrade processes.
	 *
	 * @since 1.0
	 */
	class Integration {

		/**
		 * API endpoint URL.
		 *
		 * @var string
		 */
		public $api_url;

		/**
		 * Product ID for reference on remote server.
		 *
		 * @var string
		 */
		public $product_id;

		/**
		 * Main plugin file path (e.g., plugin-folder/plugin-file.php).
		 *
		 * @var string
		 */
		public $product_file;

		/**
		 * Directory name slug for the plugin.
		 *
		 * @var string
		 */
		public $product_slug;

		/**
		 * Current plugin status (active, inactive, etc.).
		 *
		 * @var string
		 */
		public $product_status;

		/**
		 * Currently installed version of the plugin.
		 *
		 * @var string
		 */
		public $product_version;

		/**
		 * Readable plugin name.
		 *
		 * @var string
		 */
		public $product_name;

		/**
		 * Sanitized name for the plugin license option.
		 *
		 * @var string
		 */
		public $license_name;

		/**
		 * Whether to display the license menu/page in the admin.
		 *
		 * @var bool
		 */
		public $display_menu;

		/**
		 * Label for the license submenu page.
		 *
		 * @var string
		 */
		public $menu_label;

		/**
		 * The parent slug under which the submenu will appear.
		 *
		 * @var string
		 */
		public $menu_parent;

		/**
		 * Menu priority.
		 * 
		 * @var string
		 */
		public $menu_priority;

		/**
		 * Whether the plugin license features are enabled.
		 *
		 * @var bool
		 */
		public $license_enabled;

		/**
		 * Constructor.
		 *
		 * @param string $api_url          URL of the API server.
		 * @param string $product_file     Plugin file path (e.g., "my-plugin/my-plugin.php").
		 * @param string $product_id       Unique product ID on the remote server.
		 * @param bool   $license_enabled  Whether license checks are enabled.
		 * @param bool   $display_menu     Whether to add license settings page to the menu.
		 * @param string $menu_label       Label for the settings submenu.
		 * @param string $menu_parent      Parent menu slug.
		 * @param string $menu_priority    Menu priority.
		 *
		 * @since 1.0
		 */
		public function __construct(
			$api_url,
			$product_file,
			$product_id,
			$license_enabled = false,
			$display_menu = true,
			$menu_label = '',
			$menu_parent = '',
			$menu_priority = 9999
		) {
			$this->api_url         = $api_url;
			$this->product_file    = $product_file;
			$this->product_id      = $product_id;
			$this->product_status  = 'active';
			$this->license_enabled = $license_enabled;
			$this->display_menu    = $this->license_enabled ? $display_menu : false;
			$this->menu_label      = $menu_label;
			$this->menu_parent     = $menu_parent;
			$this->menu_priority   = $menu_priority;
			$this->product_slug    = dirname( $product_file );

			if ( ! $this->product_file ) {
				$this->product_file = $this->product_slug;
			}

			$this->license_name = sanitize_html_class( "{$this->product_slug}{$this->product_id}" );

			new Updater( $this );
			new Tracker( $this );

			if ( $this->display_menu ) {
				new Admin( $this );
			}
		}

		/**
		 * Retrieves the option name for storing the license key.
		 *
		 * @since 1.0
		 *
		 * @return string
		 */
		public function get_license_option() {
			return "{$this->license_name}_code";
		}

		/**
		 * Gets the license code from the database.
		 *
		 * @since 1.0
		 *
		 * @return false|string License code or false if not found.
		 */
		public function get_license_code() {
			return get_option( $this->get_license_option() );
		}

		/**
		 * Checks if the license code exists in the database.
		 *
		 * @since 1.0
		 *
		 * @return bool True if a license code is set, false otherwise.
		 */
		public function has_license_code() {
			return (bool) $this->get_license_code();
		}

		/**
		 * Checks if the license is currently active.
		 *
		 * @since 1.0
		 *
		 * @return bool True if license is active, false otherwise.
		 */
		public function is_license_active() {
			$data = get_option( "{$this->license_name}_data" );
			if ( empty( $data ) ) {
				return false;
			}

			if ( isset( $data['status'] ) && 'active' === $data['status'] ) {
				return true;
			}

			return false;
		}

		/**
		 * Forces WordPress to refresh the update plugins transient.
		 *
		 * @since 1.0
		 * @return void
		 */
		public function refresh_updates_transient() {
			set_site_transient( 'update_plugins', get_site_transient( 'update_plugins' ) );
		}

		/**
		 * Reverts the update plugins transient, ensuring the plugin is listed in no_update.
		 *
		 * @since 1.0
		 * @return void
		 */
		public function clear_updates_transient() {
			$transient = get_site_transient( 'update_plugins' );

			// Initialize transient if it doesn't exist
			if ( false === $transient ) {
				$transient            = new \stdClass();
				$transient->response  = [];
				$transient->no_update = [];
				$transient->checked   = [];
			}

			if ( ! isset( $transient->no_update ) ) {
				$transient->no_update = [];
			}

			if ( isset( $transient->response[ $this->product_file ] ) ) {
				$transient->no_update[ $this->product_file ] = $transient->response[ $this->product_file ];
				unset( $transient->response[ $this->product_file ] );
			} else {
				// plugin is up to date
				$transient->no_update[ $this->product_file ] = (object) array(
					'slug'        => $this->product_slug,
					'plugin'      => $this->product_file,
					'new_version' => $this->product_version,
					'url'         => '',
					'package'     => '',
				);
			}

			set_site_transient( 'update_plugins', $transient );
		}

		/**
		 * Sends API request to the remote server.
		 *
		 * @since 1.0
		 *
		 * @param string $method  The API endpoint method (e.g., 'ping', 'updates').
		 * @param string $license (Optional) License key to check or validate.
		 * @return array|WP_Error Response data from the API or WP_Error on failure.
		 */
		public function api_request( $method, $license = '' ) {
			$request_url = "{$this->api_url}/products/{$this->product_id}/$method";

			$args = [ 
				'product_version' => $this->product_version,
				'product_status'  => $this->product_status,
				'wp_url'          => esc_url( site_url( '', 'https' ) ),
				'wp_locale'       => get_locale(),
				'wp_version'      => get_bloginfo( 'version', 'display' ),
			];

			if ( $this->license_enabled ) {
				if ( ! $license ) {
					$license = $this->get_license_code();
				}
				if ( $license ) {
					$args['license'] = $license;
				}
			}

			$request_url = add_query_arg( $args, $request_url );

			$timeout = 5;
			if ( 'ping' === $method ) {
				$timeout = 2;
			}

			$request = wp_remote_request(
				$request_url,
				[ 'timeout' => $timeout ]
			);

			if ( is_wp_error( $request ) ) {
				return $request;
			}

			$body = wp_remote_retrieve_body( $request );
			$body = json_decode( $body, true );

			if ( empty( $body ) ) {
				return new WP_Error(
					'wprepo_api_fail',
					'No response from update server'
				);
			}

			return $body;
		}

		/**
		 * Helper function to print array or object data in a <pre> tag for debugging.
		 *
		 * @since 1.0
		 *
		 * @param mixed $data The data to print.
		 * @param bool  $exit Whether to exit after printing.
		 * @return void
		 */
		public function p( $data, $exit = false ) {
			echo '<pre>';
			print_r( $data );
			echo '</pre>';

			if ( $exit ) {
				exit;
			}
		}
	}

endif;
