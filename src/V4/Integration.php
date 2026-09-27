<?php
/**
 * WordPress Plugin Updater Integration.
 *
 * @package Shazzad\PluginUpdater\V4
 * @version 4.0
 */
namespace Shazzad\PluginUpdater\V4;

use Shazzad\PluginUpdater\V4\Insights\Client as InsightsClient;
use Shazzad\PluginUpdater\V4\Insights\Collector as InsightsCollector;
use Shazzad\PluginUpdater\V4\Insights\Consent as InsightsConsent;
use Shazzad\PluginUpdater\V4\Insights\Scheduler as InsightsScheduler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\Integration' ) ) :

	/**
	 * Class Integration
	 *
	 * Main entry point for commercial consumer plugins. Holds all shared state
	 * (API URL, product info, license config) and wires up the subsystem
	 * instances. License/option storage lives in License\Store; the thin
	 * accessors here delegate to it.
	 *
	 * Same behaviour and storage as the V2 Integration, except that install
	 * tracking goes through the Insights parts (commercial mode: consent is
	 * implied, the license key rides along) instead of the old `/ping`.
	 *
	 * @since 4.0.0
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
		 * Opaque product uid (`prod_…`) on the remote server. Required: it is
		 * the only identifier `wp-repo/v4` accepts in API URLs. Without it no
		 * update, license or Insights call is made.
		 *
		 * License storage keys use it too (the numeric id only reaches the
		 * legacy id-based keys; see License\Store).
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
		 * Custom metadata sent with every Insights track.
		 * Values can be static or callable (resolved at send time).
		 *
		 * Mirrored into the Insights collector by the constructor and by
		 * setMeta(); assign through setMeta() after construction.
		 *
		 * @var array
		 */
		public $meta = [];

		/**
		 * Callback that returns a metadata array at send time.
		 *
		 * Mirrored into the Insights collector by the constructor and by
		 * setMetaCallback().
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
		 * Insights consent state, commercial mode (always granted, never
		 * asked). Null when Insights is off (no resolvable Insights URL).
		 *
		 * @since 4.0.0
		 *
		 * @var InsightsConsent|null
		 */
		public $insights_consent;

		/**
		 * Insights payload builder. Null when Insights is off.
		 *
		 * @since 4.0.0
		 *
		 * @var InsightsCollector|null
		 */
		public $insights_collector;

		/**
		 * Insights HTTP client. Null when Insights is off.
		 *
		 * @since 4.0.0
		 *
		 * @var InsightsClient|null
		 */
		public $insights_client;

		/**
		 * Insights scheduler: daily cron plus activate / deactivate / upgrade
		 * tracks. Null when Insights is off.
		 *
		 * @since 4.0.0
		 *
		 * @var InsightsScheduler|null
		 */
		public $insights_scheduler;

		/**
		 * Constructor.
		 *
		 * @param array $config {
		 *     Integration configuration.
		 *
		 *     @type string      $api_url     Repo API base, e.g. `https://w4dev.com/wp-json/wp-repo/v4`.
		 *                                    Serves updates, licensing and Insights. Required.
		 *     @type string      $file        Plugin main file: __FILE__ or its plugin_basename() form
		 *                                    ("my-plugin/my-plugin.php") — both accepted. Required.
		 *     @type string      $product_uid Opaque `prod_…` uid on the remote server. Required: the API
		 *                                    URLs (`{api_url}/plugins/{uid}/…`) are built from it alone.
		 *     @type string      $product_id  Numeric product id on the remote server. Optional, never sent;
		 *                                    required to reach license options stored under id-based keys
		 *                                    (plugins that shipped on the V1 library).
		 *     @type bool        $license     Whether license checks are enabled. Default false.
		 *     @type array|false $menu        License page settings (`parent`, `label`, `priority`), or
		 *                                    false to disable the page. Default empty array (page shown
		 *                                    with defaults when licensing is enabled).
		 *     @type array       $meta        Static metadata sent with Insights tracks. Same effect as setMeta().
		 *     @type callable    $meta_callback Callback returning metadata at send time. Same effect as
		 *                                    setMetaCallback().
		 * }
		 *
		 * Unrecognized config keys and missing required keys (`api_url`, `file`, `product_uid`)
		 * trigger a `_doing_it_wrong()` notice in debug mode; construction always proceeds — a
		 * misconfigured updater must never fatal the plugin embedding it. Without a valid
		 * `product_uid` (`prod_…`) no API call is made and Insights stays off. An `api_url` on
		 * `wp-repo/v3` draws a notice too, and nothing works against it (no update, license
		 * or Insights call): v3 has no `plugins/{uid}` routes. A trailing slash on `api_url`
		 * is trimmed.
		 *
		 * @since 4.0.0
		 */
		public function __construct( array $config ) {
			$this->validate_config( $config );

			$this->api_url         = isset( $config['api_url'] ) ? $config['api_url'] : '';

			// One trim here, so `…/v4/` never produces `v4//plugins/…`.
			if ( \is_string( $this->api_url ) ) {
				$this->api_url = \rtrim( $this->api_url, '/' );
			}

			$this->product_file    = isset( $config['file'] ) ? $config['file'] : '';
			$this->product_id      = isset( $config['product_id'] ) ? $config['product_id'] : '';
			$this->product_uid     = isset( $config['product_uid'] ) ? $config['product_uid'] : '';
			$this->product_status  = 'active';
			$this->license_enabled = ! empty( $config['license'] );

			// Accept either __FILE__ or an already-relative plugin_basename() form:
			// plugin_basename() is idempotent on relative "dir/file.php" input, and
			// a raw absolute path would poison storage keys and hook names.
			if ( $this->product_file && \function_exists( 'plugin_basename' ) ) {
				$this->product_file = plugin_basename( $this->product_file );
			}

			// A falsy non-array `menu` hides the license page (V1 truthiness
			// semantics); an array — even empty — or an absent key shows it.
			$menu               = \array_key_exists( 'menu', $config ) ? $config['menu'] : [];
			$this->display_menu = $this->license_enabled && ( \is_array( $menu ) || (bool) $menu );

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

			if ( $this->can_track() ) {
				$this->setup_insights();
			}

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
		 * @since 4.0.0
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
					'4.0.0'
				);
			}

			foreach ( [ 'api_url', 'file' ] as $required ) {
				if ( empty( $config[ $required ] ) ) {
					_doing_it_wrong(
						__METHOD__,
						\sprintf( 'Missing required config key "%s".', $required ),
						'4.0.0'
					);
				}
			}

			if ( empty( $config['product_uid'] ) ) {
				_doing_it_wrong(
					__METHOD__,
					'Missing required config key "product_uid". The wp-repo/v4 API addresses a plugin by its'
					. ' "prod_…" uid only, so no update, license or Insights call is made without it.'
					. ' "product_id" alone is not enough.',
					'4.0.0'
				);
			} elseif ( ! InsightsClient::is_valid_uid( $config['product_uid'] ) ) {
				_doing_it_wrong(
					__METHOD__,
					'Config "product_uid" is not a valid uid (expected "prod_" followed by lowercase letters'
					. ' and digits, as shown on the product screen). No update, license or Insights call is'
					. ' made with it.',
					'4.0.0'
				);
			}

			if ( ! empty( $config['license'] ) && ! empty( $config['product_uid'] ) && empty( $config['product_id'] ) ) {
				_doing_it_wrong(
					__METHOD__,
					'Config sets "product_uid" without "product_id". A plugin previously shipped on the V1'
					. ' (unversioned) library must pass "product_id" too, or its storage and cron identity'
					. ' changes and existing customer licenses are never migrated. Uid-only is fine for'
					. ' plugins that never shipped with V1.',
					'4.0.0'
				);
			}

			if ( isset( $config['meta_callback'] ) && ! \is_callable( $config['meta_callback'] ) ) {
				_doing_it_wrong(
					__METHOD__,
					'Config key "meta_callback" is not callable and will be ignored.',
					'4.0.0'
				);
			}

			if ( isset( $config['api_url'] ) && \is_string( $config['api_url'] ) && InsightsClient::is_v3_api_url( \rtrim( $config['api_url'], '/' ) ) ) {
				_doing_it_wrong(
					__METHOD__,
					'Config "api_url" points at wp-repo/v3. V4 of this library needs the wp-repo/v4 API'
					. ' (e.g. https://w4dev.com/wp-json/wp-repo/v4): v3 has no plugins/{uid} routes, so'
					. ' nothing works against it — no update check, no license check, no Insights.',
					'4.0.0'
				);
			}
		}

		/**
		 * Why no API call can be made with this configuration, or null when
		 * one can. Every update, license and Insights request needs a
		 * non-empty `wp-repo/v4` `api_url` and a valid `prod_…` uid; v3 has no
		 * `plugins/{uid}` routes and v4 has no numeric-id routes, so those
		 * calls could only 404.
		 *
		 * @since 4.0.0
		 *
		 * @return \WP_Error|null
		 */
		public function get_api_error() {
			if ( ! \is_string( $this->api_url ) || '' === $this->api_url ) {
				return new \WP_Error( 'wprepo_no_api_url', 'No api_url configured; nothing sent.' );
			}

			if ( InsightsClient::is_v3_api_url( $this->api_url ) ) {
				return new \WP_Error(
					'wprepo_v3_api_url',
					'api_url points at wp-repo/v3, which has no plugins/{uid} routes; nothing sent. Use wp-repo/v4.'
				);
			}

			if ( ! InsightsClient::is_valid_uid( $this->product_uid ) ) {
				return new \WP_Error( 'wprepo_no_product_uid', 'No valid product_uid configured; nothing sent.' );
			}

			return null;
		}

		/**
		 * Whether the Insights parts can be built: the same rule as any API
		 * call (see get_api_error()).
		 *
		 * @since 4.0.0
		 *
		 * @return bool
		 */
		private function can_track() {
			return null === $this->get_api_error();
		}

		/**
		 * Builds the Insights parts in commercial mode: consent is implied,
		 * nothing is asked (no notice is ever created), and the license key
		 * is sent when licensing is on.
		 *
		 * @since 4.0.0
		 *
		 * @return void
		 */
		private function setup_insights() {
			$slug = sanitize_key( InsightsCollector::basename_to_slug( $this->product_file ) );

			$this->insights_consent   = new InsightsConsent( $slug, InsightsConsent::MODE_COMMERCIAL );
			$this->insights_collector = new InsightsCollector(
				$this->product_file,
				$this->insights_consent,
				[
					'meta'             => $this->meta,
					'meta_callback'    => $this->meta_callback,
					'license_callback'      => [ $this, 'get_insights_license' ],
					'license_sent_callback' => [ $this, 'insights_license_sent' ],
				]
			);
			$this->insights_client    = new InsightsClient(
				$this->api_url,
				$this->get_api_product_key(),
				$this->insights_collector,
				$this->insights_consent
			);
			$this->insights_scheduler = new InsightsScheduler(
				$this->product_file,
				$slug,
				$this->insights_client,
				$this->insights_collector,
				$this->insights_consent
			);
		}

		/**
		 * License key for the Insights payload.
		 *
		 * - Licensing off: null — no `license` key in the payload, so the
		 *   server leaves the install's binding alone.
		 * - Licensing on, a code stored: the code (the server binds it).
		 * - Licensing on, the stored code was explicitly removed (license
		 *   page saved empty): '' — the server unbinds the install, and its
		 *   next install/activation recount frees the seat. Sent until one
		 *   such track succeeds (see insights_license_sent()).
		 * - Licensing on, no code and no removal: null, as when licensing is
		 *   off. A key the site cannot read (a missed legacy migration, a
		 *   failed option read) must never unbind a paying install.
		 *
		 * @since 4.0.0
		 *
		 * @return string|null
		 */
		public function get_insights_license() {
			if ( ! $this->license_enabled ) {
				return null;
			}

			$license = $this->get_license_code();

			if ( \is_string( $license ) && '' !== $license ) {
				return $license;
			}

			return $this->store->is_license_removed() ? '' : null;
		}

		/**
		 * Called after a track carrying `license` got a 2xx: once the server
		 * has received `license: ""`, the removal is delivered and the flag
		 * is cleared, so later tracks omit `license` again.
		 *
		 * @since 4.0.0
		 *
		 * @param string $license The license value that was delivered.
		 * @return void
		 */
		public function insights_license_sent( $license ) {
			if ( '' === $license ) {
				$this->store->clear_license_removed();
			}
		}

		/**
		 * Set custom metadata sent with Insights tracks.
		 * Values can be static or callable (resolved at send time).
		 *
		 * @since 4.0.0
		 *
		 * @param array $meta Key-value pairs of metadata.
		 * @return $this
		 */
		public function setMeta( array $meta ) {
			$this->meta = $meta;

			if ( $this->insights_collector ) {
				$this->insights_collector->meta = $meta;
			}

			return $this;
		}

		/**
		 * Set a callback that returns a metadata array at send time.
		 *
		 * @since 4.0.0
		 *
		 * @param callable $callback Function that returns an array of metadata.
		 * @return $this
		 */
		public function setMetaCallback( callable $callback ) {
			$this->meta_callback = $callback;

			if ( $this->insights_collector ) {
				$this->insights_collector->meta_callback = $callback;
			}

			return $this;
		}

		/**
		 * Sets the opaque product uid used for API URLs and storage keys.
		 *
		 * Thin alias kept so plugin code ported from the legacy namespace diffs
		 * small; new integrations should pass `product_uid` in the constructor
		 * config instead.
		 *
		 * @since 4.0.0
		 *
		 * @param string $product_uid The `prod_…` uid from the repo server.
		 * @return $this
		 */
		public function setProductUid( $product_uid ) {
			$this->product_uid = $product_uid;

			// Insights was skipped at construction when the uid was missing.
			if ( $this->insights_client ) {
				$this->insights_client->product_key = (string) $product_uid;
			} elseif ( $this->can_track() ) {
				$this->setup_insights();
			}

			$this->store->maybe_migrate_license_storage();

			return $this;
		}

		/**
		 * Resolves the installed plugin version and name, used by the updater,
		 * the license page and the notices. Filled on `init`.
		 *
		 * Site/admin details are no longer resolved here: the Insights
		 * collector gathers them at send time.
		 *
		 * @since 4.0.0
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
		}

		/**
		 * Resolves the plugin identifier used in API URLs
		 * (`{api_url}/plugins/{uid}/…`).
		 *
		 * The uid only: `wp-repo/v4` answers a numeric id with
		 * `404 rest_no_route`, so there is no fallback to `product_id`.
		 *
		 * @since 4.0.0
		 *
		 * @return string The product uid, or '' when none (or a malformed one) is configured.
		 */
		public function get_api_product_key() {
			return InsightsClient::is_valid_uid( $this->product_uid ) ? $this->product_uid : '';
		}

		/**
		 * Resolves the base name for license/cache storage keys.
		 *
		 * @since 4.0.0
		 *
		 * @return string
		 */
		public function get_storage_name() {
			return $this->store->get_storage_name();
		}

		/**
		 * Retrieves the option key for storing the license code.
		 *
		 * @since 4.0.0
		 *
		 * @return string
		 */
		public function get_license_code_key() {
			return $this->store->get_license_code_key();
		}

		/**
		 * Gets the license code from the database.
		 *
		 * @since 4.0.0
		 *
		 * @return false|string License code or false if not found.
		 */
		public function get_license_code() {
			return $this->store->get_license_code();
		}

		/**
		 * Checks if the license code exists in the database.
		 *
		 * @since 4.0.0
		 *
		 * @return bool True if a license code is set, false otherwise.
		 */
		public function has_license_code() {
			return $this->store->has_license_code();
		}

		/**
		 * Retrieves the option key for storing license data.
		 *
		 * @since 4.0.0
		 *
		 * @return string
		 */
		public function get_license_data_key() {
			return $this->store->get_license_data_key();
		}

		/**
		 * Retrieves the transient key for caching updates API responses.
		 *
		 * @since 4.0.0
		 *
		 * @return string
		 */
		public function get_updates_cache_key() {
			return $this->store->get_updates_cache_key();
		}

		/**
		 * Retrieves the transient key for caching details API responses.
		 *
		 * @since 4.0.0
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
		 * @since 4.0.0
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
		 * @since 4.0.0
		 *
		 * @return string
		 */
		public function get_legacy_license_data_key() {
			return $this->store->get_legacy_license_data_key();
		}

		/**
		 * Clones id-based license options to their uid-based keys.
		 *
		 * @since 4.0.0
		 *
		 * @return void
		 */
		public function maybe_migrate_license_storage() {
			$this->store->maybe_migrate_license_storage();
		}

		/**
		 * Get license status.
		 *
		 * @since 4.0.0
		 *
		 * @return string
		 */
		public function get_license_status() {
			return $this->store->get_license_status();
		}

		/**
		 * Get license renewal URL from stored license data.
		 *
		 * @since 4.0.0
		 *
		 * @return string Renewal URL or empty string if not available.
		 */
		public function get_license_renewal_url() {
			return $this->store->get_license_renewal_url();
		}

		/**
		 * Gets the license data from the database.
		 *
		 * @since 4.0.0
		 *
		 * @return false|array License data or false if not found.
		 */
		public function get_license_data() {
			return $this->store->get_license_data();
		}

		/**
		 * Updates the license data in the database.
		 *
		 * @since 4.0.0
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
		 * @since 4.0.0
		 *
		 * @return bool True if the value was updated, false otherwise.
		 */
		public function mark_license_invalid() {
			return $this->store->mark_license_invalid();
		}

		/**
		 * Stores a license code and clears the "license removed" flag.
		 *
		 * @since 4.0.0
		 *
		 * @param string $code License code.
		 * @return bool True if the value was updated, false otherwise.
		 */
		public function update_license_code( $code ) {
			return $this->store->update_license_code( $code );
		}

		/**
		 * Deletes the license code from the database.
		 *
		 * @since 4.0.0
		 *
		 * @return bool True if the option was deleted, false otherwise.
		 */
		public function delete_license_code() {
			return $this->store->delete_license_code();
		}

		/**
		 * Deletes the license data from the database.
		 *
		 * @since 4.0.0
		 *
		 * @return bool True if the option was deleted, false otherwise.
		 */
		public function delete_license_data() {
			return $this->store->delete_license_data();
		}

		/**
		 * Checks if the license is currently active.
		 *
		 * @since 4.0.0
		 *
		 * @return bool True if license is active, false otherwise.
		 */
		public function is_license_active() {
			return $this->store->is_license_active();
		}

		/**
		 * Forces WordPress to refresh the update plugins transient.
		 *
		 * @since 4.0.0
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
		 * @since 4.0.0
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
