<?php
/**
 * WordPress Plugin Updater Integration.
 *
 * @package Shazzad\PluginUpdater\V2
 * @version 2.0
 */
namespace Shazzad\PluginUpdater\V2;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\Integration' ) ) :

	/**
	 * Class Integration
	 *
	 * Main entry point for consumer plugins. Holds all shared state (API URL, product
	 * info, license config) and wires up the subsystem instances. License/option
	 * storage lives in License\Store; the thin accessors here delegate to it.
	 *
	 * @since 2.0.0
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
		 * Opaque product uid (`prod_…`) on the remote server.
		 *
		 * When set, API URLs and license storage keys use the uid instead
		 * of the numeric product id.
		 *
		 * @var string
		 */
		public $product_uid = '';

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
		 * Site admin email.
		 *
		 * @var string
		 */
		public $admin_email = '';

		/**
		 * First admin username.
		 *
		 * @var string
		 */
		public $admin_name = '';

		/**
		 * Custom metadata to send with pings.
		 * Values can be static or callable (resolved at ping time).
		 *
		 * @var array
		 */
		public $meta = [];

		/**
		 * Callback that returns metadata array at ping time.
		 *
		 * @var callable|null
		 */
		public $meta_callback = null;

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
		 * @var int
		 */
		public $menu_priority;

		/**
		 * Whether the plugin license features are enabled.
		 *
		 * @var bool
		 */
		public $license_enabled;

		/**
		 * License/option storage instance.
		 *
		 * Untyped on purpose, like the other subsystem properties: tests and
		 * consumer code swap stand-ins into these seams.
		 *
		 * @var License\Store
		 */
		public $store;

		/**
		 * API client instance.
		 *
		 * @var Client
		 */
		public $client;

		/**
		 * Updater instance.
		 *
		 * @var Updater
		 */
		public $updater;

		/**
		 * Tracker instance.
		 *
		 * @var Tracker
		 */
		public $tracker;

		/**
		 * License page instance.
		 *
		 * @var Admin\LicensePage|null
		 */
		public $admin;

		/**
		 * License admin notices instance. Set whenever licensing is enabled.
		 *
		 * @var Admin\Notices|null
		 */
		public $notices;

		/**
		 * Plugins-list update-row message instance. Set whenever licensing
		 * is enabled.
		 *
		 * @var Admin\UpdateMessage|null
		 */
		public $update_message;

		/**
		 * Constructor.
		 *
		 * @param array $config {
		 *     Integration configuration.
		 *
		 *     @type string      $api_url     URL of the API server. Required.
		 *     @type string      $file        Plugin file path (e.g., "my-plugin/my-plugin.php"). Required.
		 *     @type string      $product_uid Opaque `prod_…` uid on the remote server. Preferred identity.
		 *     @type string      $product_id  Numeric product id on the remote server. Optional legacy identity;
		 *                                    required to reach license options stored under id-based keys.
		 *     @type bool        $license     Whether license checks are enabled. Default false.
		 *     @type array|false $menu        License page settings (`parent`, `label`, `priority`), or
		 *                                    false to disable the page. Default empty array (page shown
		 *                                    with defaults when licensing is enabled).
		 *     @type array       $meta        Static metadata to send with pings. Same effect as setMeta().
		 *     @type callable    $meta_callback Callback returning metadata at ping time. Same effect as
		 *                                    setMetaCallback().
		 * }
		 *
		 * Unrecognized config keys and missing required keys (`api_url`, `file`) trigger a
		 * `_doing_it_wrong()` notice in debug mode; construction always proceeds — a
		 * misconfigured updater must never fatal the plugin embedding it.
		 *
		 * @since 2.0.0
		 */
		public function __construct( array $config ) {
			$this->validate_config( $config );

			$this->api_url         = isset( $config['api_url'] ) ? $config['api_url'] : '';
			$this->product_file    = isset( $config['file'] ) ? $config['file'] : '';
			$this->product_id      = isset( $config['product_id'] ) ? $config['product_id'] : '';
			$this->product_uid     = isset( $config['product_uid'] ) ? $config['product_uid'] : '';
			$this->product_status  = 'active';
			$this->license_enabled = ! empty( $config['license'] );

			$menu               = \array_key_exists( 'menu', $config ) ? $config['menu'] : [];
			$this->display_menu = $this->license_enabled ? ( false !== $menu ) : false;

			$menu = \is_array( $menu ) ? $menu : [];

			$this->menu_label    = isset( $menu['label'] ) ? $menu['label'] : '';
			$this->menu_parent   = isset( $menu['parent'] ) ? $menu['parent'] : '';
			$this->menu_priority = isset( $menu['priority'] ) ? $menu['priority'] : 9999;

			$this->product_slug = dirname( $this->product_file );

			if ( ! $this->product_file ) {
				$this->product_file = $this->product_slug;
			}

			$this->license_name = sanitize_key( "{$this->product_slug}{$this->product_id}" );

			if ( isset( $config['meta'] ) && \is_array( $config['meta'] ) ) {
				$this->meta = $config['meta'];
			}

			if ( isset( $config['meta_callback'] ) && \is_callable( $config['meta_callback'] ) ) {
				$this->meta_callback = $config['meta_callback'];
			}

			$this->store   = new License\Store( $this );
			$this->client  = new Client( $this );
			$this->updater = new Updater( $this );
			$this->tracker = new Tracker( $this );

			if ( $this->display_menu ) {
				$this->admin = new Admin\LicensePage( $this );
			}

			if ( $this->license_enabled ) {
				$this->notices        = new Admin\Notices( $this );
				$this->update_message = new Admin\UpdateMessage( $this );
			}

			if ( $this->product_uid ) {
				$this->store->maybe_migrate_license_storage();
			}
		}

		/**
		 * Flags config-array typos and missing required keys in debug mode.
		 *
		 * Notices only — construction proceeds regardless, because a broken
		 * updater must never take the embedding plugin down with it.
		 *
		 * @since 2.0.0
		 *
		 * @param array $config Constructor config.
		 * @return void
		 */
		private function validate_config( array $config ) {
			if ( ! \function_exists( '_doing_it_wrong' ) ) {
				return;
			}

			$known = [ 'api_url', 'file', 'product_uid', 'product_id', 'license', 'menu', 'meta', 'meta_callback' ];

			foreach ( \array_diff( \array_keys( $config ), $known ) as $unknown ) {
				_doing_it_wrong(
					__METHOD__,
					\sprintf( 'Unrecognized config key "%s".', (string) $unknown ),
					'2.0.0'
				);
			}

			foreach ( [ 'api_url', 'file' ] as $required ) {
				if ( empty( $config[ $required ] ) ) {
					_doing_it_wrong(
						__METHOD__,
						\sprintf( 'Missing required config key "%s".', $required ),
						'2.0.0'
					);
				}
			}
		}

		/**
		 * Set custom metadata to send with pings.
		 * Values can be static or callable (resolved at ping time).
		 *
		 * @since 2.0.0
		 *
		 * @param array $meta Key-value pairs of metadata.
		 * @return $this
		 */
		public function setMeta( array $meta ) {
			$this->meta = $meta;

			return $this;
		}

		/**
		 * Set a callback that returns metadata array at ping time.
		 *
		 * @since 2.0.0
		 *
		 * @param callable $callback Function that returns an array of metadata.
		 * @return $this
		 */
		public function setMetaCallback( callable $callback ) {
			$this->meta_callback = $callback;

			return $this;
		}

		/**
		 * Sets the opaque product uid used for API URLs and storage keys.
		 *
		 * Thin alias kept so plugin code ported from the legacy namespace diffs
		 * small; new integrations should pass `product_uid` in the constructor
		 * config instead.
		 *
		 * @since 2.0.0
		 *
		 * @param string $product_uid The `prod_…` uid from the repo server.
		 * @return $this
		 */
		public function setProductUid( $product_uid ) {
			$this->product_uid = $product_uid;

			$this->store->maybe_migrate_license_storage();

			return $this;
		}

		/**
		 * Resolves the plugin and site details reported to the API.
		 *
		 * Normally filled on `init`, but a ping can fire in a request where
		 * that hook has already passed: activating a plugin includes its file
		 * and fires `activate_{file}` after `init`, so the callback registered
		 * in this constructor never runs and the activation ping would report
		 * empty details. Calling this from ping() keeps the payload complete
		 * whatever the hook timing.
		 *
		 * @since 2.0.0
		 *
		 * @param bool $force Overwrite values that are already set. Default false.
		 * @return void
		 */
		public function prepare_product_data( $force = false ) {
			if ( $force || empty( $this->product_version ) || empty( $this->product_name ) ) {
				if ( ! function_exists( 'get_plugin_data' ) ) {
					include_once ABSPATH . 'wp-admin/includes/plugin.php';
				}

				$plugin = get_plugin_data( WP_PLUGIN_DIR . '/' . $this->product_file );

				if ( $force || empty( $this->product_version ) ) {
					$this->product_version = $plugin['Version'];
				}

				if ( $force || empty( $this->product_name ) ) {
					$this->product_name = $plugin['Name'];
				}
			}

			if ( $force || empty( $this->admin_email ) ) {
				$this->admin_email = get_option( 'admin_email' );
			}

			if ( $force || empty( $this->admin_name ) ) {
				$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC' ] );

				if ( ! empty( $admins ) ) {
					$this->admin_name = $admins[0]->display_name;
				}
			}
		}

		/**
		 * Resolves the product identifier used in API URLs.
		 *
		 * @since 2.0.0
		 *
		 * @return string The product uid when set, otherwise the numeric id.
		 */
		public function get_api_product_key() {
			return $this->product_uid ? $this->product_uid : $this->product_id;
		}

		/**
		 * Resolves the base name for license/cache storage keys.
		 *
		 * @since 2.0.0
		 *
		 * @return string
		 */
		public function get_storage_name() {
			return $this->store->get_storage_name();
		}

		/**
		 * Retrieves the option key for storing the license code.
		 *
		 * @since 2.0.0
		 *
		 * @return string
		 */
		public function get_license_code_key() {
			return $this->store->get_license_code_key();
		}

		/**
		 * Gets the license code from the database.
		 *
		 * @since 2.0.0
		 *
		 * @return false|string License code or false if not found.
		 */
		public function get_license_code() {
			return $this->store->get_license_code();
		}

		/**
		 * Checks if the license code exists in the database.
		 *
		 * @since 2.0.0
		 *
		 * @return bool True if a license code is set, false otherwise.
		 */
		public function has_license_code() {
			return $this->store->has_license_code();
		}

		/**
		 * Retrieves the option key for storing license data.
		 *
		 * @since 2.0.0
		 *
		 * @return string
		 */
		public function get_license_data_key() {
			return $this->store->get_license_data_key();
		}

		/**
		 * Retrieves the transient key for caching updates API responses.
		 *
		 * @since 2.0.0
		 *
		 * @return string
		 */
		public function get_updates_cache_key() {
			return $this->store->get_updates_cache_key();
		}

		/**
		 * Retrieves the transient key for caching details API responses.
		 *
		 * @since 2.0.0
		 *
		 * @return string
		 */
		public function get_details_cache_key() {
			return $this->store->get_details_cache_key();
		}

		/**
		 * Retrieves the id-based option key the license code was stored
		 * under before the uid migration.
		 *
		 * @since 2.0.0
		 *
		 * @return string
		 */
		public function get_legacy_license_code_key() {
			return $this->store->get_legacy_license_code_key();
		}

		/**
		 * Retrieves the id-based option key the license data was stored
		 * under before the uid migration.
		 *
		 * @since 2.0.0
		 *
		 * @return string
		 */
		public function get_legacy_license_data_key() {
			return $this->store->get_legacy_license_data_key();
		}

		/**
		 * Clones id-based license options to their uid-based keys.
		 *
		 * @since 2.0.0
		 *
		 * @return void
		 */
		public function maybe_migrate_license_storage() {
			$this->store->maybe_migrate_license_storage();
		}

		/**
		 * Get license status.
		 *
		 * @since 2.0.0
		 *
		 * @return string
		 */
		public function get_license_status() {
			return $this->store->get_license_status();
		}

		/**
		 * Get license renewal URL from stored license data.
		 *
		 * @since 2.0.0
		 *
		 * @return string Renewal URL or empty string if not available.
		 */
		public function get_license_renewal_url() {
			return $this->store->get_license_renewal_url();
		}

		/**
		 * Gets the license data from the database.
		 *
		 * @since 2.0.0
		 *
		 * @return false|array License data or false if not found.
		 */
		public function get_license_data() {
			return $this->store->get_license_data();
		}

		/**
		 * Updates the license data in the database.
		 *
		 * @since 2.0.0
		 *
		 * @param array $data License data to store.
		 * @return bool True if the value was updated, false otherwise.
		 */
		public function update_license_data( $data ) {
			return $this->store->update_license_data( $data );
		}

		/**
		 * Records that the server rejected the stored license.
		 *
		 * @since 2.0.0
		 *
		 * @return bool True if the value was updated, false otherwise.
		 */
		public function mark_license_invalid() {
			return $this->store->mark_license_invalid();
		}

		/**
		 * Deletes the license code from the database.
		 *
		 * @since 2.0.0
		 *
		 * @return bool True if the option was deleted, false otherwise.
		 */
		public function delete_license_code() {
			return $this->store->delete_license_code();
		}

		/**
		 * Deletes the license data from the database.
		 *
		 * @since 2.0.0
		 *
		 * @return bool True if the option was deleted, false otherwise.
		 */
		public function delete_license_data() {
			return $this->store->delete_license_data();
		}

		/**
		 * Checks if the license is currently active.
		 *
		 * @since 2.0.0
		 *
		 * @return bool True if license is active, false otherwise.
		 */
		public function is_license_active() {
			return $this->store->is_license_active();
		}

		/**
		 * Forces WordPress to refresh the update plugins transient.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public function refresh_updates_transient() {
			delete_site_transient( $this->get_updates_cache_key() );
			delete_site_transient( $this->get_details_cache_key() );

			$transient = get_site_transient( 'update_plugins' );

			// A cold transient reads back as false ('' once a false was stored);
			// re-setting a non-object would pass it through the
			// pre_set_site_transient_update_plugins filter chain, fataling
			// callbacks on PHP 8. Normalize as wp_update_plugins() does.
			if ( ! is_object( $transient ) ) {
				$transient = new \stdClass();
			}

			set_site_transient( 'update_plugins', $transient );
		}

		/**
		 * Reverts the update plugins transient, ensuring the plugin is listed in no_update.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public function clear_updates_transient() {
			delete_site_transient( $this->get_updates_cache_key() );
			delete_site_transient( $this->get_details_cache_key() );

			$transient = get_site_transient( 'update_plugins' );

			// Initialize when missing or non-object — a transient stored as
			// false reads back as '' on single site, which is not === false.
			if ( ! is_object( $transient ) ) {
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

	}

endif;
