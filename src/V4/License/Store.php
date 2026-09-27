<?php
/**
 * WordPress Plugin Updater License Store.
 *
 * @package Shazzad\PluginUpdater\V4
 * @version 4.0
 */
namespace Shazzad\PluginUpdater\V4\License;

use Shazzad\PluginUpdater\V4\Insights\Collector as InsightsCollector;
use Shazzad\PluginUpdater\V4\Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\Store' ) ) :

	/**
	 * Class Store
	 *
	 * Owns license/option storage: key resolution (uid-based with id-based legacy
	 * fallback), the one-way legacy-key migration, and license code/data/status
	 * reads and writes. Keys are identical to the legacy namespace so a plugin
	 * upgrading V1→V2→V4 keeps every customer's saved license.
	 *
	 * @since 4.0.0
	 */
	class Store {

		/**
		 * Integration instance holding shared state.
		 *
		 * @since 4.0.0
		 *
		 * @var Integration
		 */
		public Integration $integration;

		/**
		 * Constructor.
		 *
		 * @since 4.0.0
		 *
		 * @param Integration $integration Integration instance.
		 */
		public function __construct( Integration $integration ) {
			$this->integration = $integration;
		}

		/**
		 * Resolves the base name for license/cache storage keys.
		 *
		 * When a uid is set, returns the uid alone (globally unique on its own).
		 * Otherwise returns the id-based `license_name` for backward compatibility.
		 *
		 * @since 4.0.0
		 *
		 * @return string
		 */
		public function get_storage_name() {
			if ( $this->integration->product_uid ) {
				return sanitize_key( $this->integration->product_uid );
			}

			return $this->integration->license_name;
		}

		/**
		 * Retrieves the option key for storing the license code.
		 *
		 * @since 4.0.0
		 *
		 * @return string
		 */
		public function get_license_code_key() {
			return "{$this->get_storage_name()}_code";
		}

		/**
		 * Gets the license code from the database.
		 *
		 * @since 4.0.0
		 *
		 * @return false|string License code or false if not found.
		 */
		public function get_license_code() {
			return get_option( $this->get_license_code_key() );
		}

		/**
		 * Checks if the license code exists in the database.
		 *
		 * @since 4.0.0
		 *
		 * @return bool True if a license code is set, false otherwise.
		 */
		public function has_license_code() {
			return (bool) $this->get_license_code();
		}

		/**
		 * Stores a license code the user entered, and clears the "license
		 * removed" flag (see get_license_removed_key()).
		 *
		 * @since 4.0.0
		 *
		 * @param string $code License code.
		 * @return bool True if the value was updated, false otherwise.
		 */
		public function update_license_code( $code ) {
			$this->clear_license_removed();

			return update_option( $this->get_license_code_key(), $code );
		}

		/**
		 * Option key of the "license removed" flag: set when a stored license
		 * code is deleted, cleared when a code is saved or once a track has
		 * delivered `license: ""` (which makes the server unbind the install).
		 *
		 * Keyed like the Insights options (`{slug}_insights_…`, `{slug}` =
		 * plugin directory), so Insights::uninstall() removes it with them.
		 *
		 * @since 4.0.0
		 *
		 * @return string
		 */
		public function get_license_removed_key() {
			return sanitize_key( InsightsCollector::basename_to_slug( $this->integration->product_file ) ) . '_insights_license_removed';
		}

		/**
		 * Whether a stored license code was explicitly removed and the server
		 * has not been told yet.
		 *
		 * @since 4.0.0
		 *
		 * @return bool
		 */
		public function is_license_removed() {
			return (bool) get_option( $this->get_license_removed_key(), false );
		}

		/**
		 * Clears the "license removed" flag.
		 *
		 * @since 4.0.0
		 *
		 * @return void
		 */
		public function clear_license_removed() {
			delete_option( $this->get_license_removed_key() );
		}

		/**
		 * Retrieves the option key for storing license data.
		 *
		 * @since 4.0.0
		 *
		 * @return string
		 */
		public function get_license_data_key() {
			return "{$this->get_storage_name()}_data";
		}

		/**
		 * Retrieves the transient key for caching updates API responses.
		 *
		 * @since 4.0.0
		 *
		 * @return string
		 */
		public function get_updates_cache_key() {
			return "{$this->get_storage_name()}_updates_cache";
		}

		/**
		 * Retrieves the transient key for caching details API responses.
		 *
		 * @since 4.0.0
		 *
		 * @return string
		 */
		public function get_details_cache_key() {
			return "{$this->get_storage_name()}_details_cache";
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
			return "{$this->integration->license_name}_code";
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
			return "{$this->integration->license_name}_data";
		}

		/**
		 * Clones id-based license options to their uid-based keys.
		 *
		 * One-way and self-limiting: once the uid-based code option exists
		 * the clone never runs again. Old copies stay in place until the
		 * prune release. A failed write simply re-runs next load.
		 *
		 * @since 4.0.0
		 *
		 * @return void
		 */
		public function maybe_migrate_license_storage() {
			if ( ! $this->integration->license_enabled || ! $this->integration->product_uid ) {
				return;
			}

			if ( false !== get_option( $this->get_license_code_key() ) ) {
				return;
			}

			$legacy_code = get_option( $this->get_legacy_license_code_key() );

			if ( false === $legacy_code ) {
				return;
			}

			update_option( $this->get_license_code_key(), $legacy_code );

			$legacy_data = get_option( $this->get_legacy_license_data_key() );

			if ( false !== $legacy_data ) {
				update_option( $this->get_license_data_key(), $legacy_data );
			}
		}

		/**
		 * Get license status.
		 *
		 * @since 4.0.0
		 *
		 * @return string
		 */
		public function get_license_status() {
			$data = $this->get_license_data();

			if ( ! empty( $data['status'] ) ) {
				return $data['status'];
			}

			return 'unknown';
		}

		/**
		 * Get license renewal URL from stored license data.
		 *
		 * @since 4.0.0
		 *
		 * @return string Renewal URL or empty string if not available.
		 */
		public function get_license_renewal_url() {
			$data = $this->get_license_data();

			if ( empty( $data['renewal_url'] ) ) {
				return '';
			}

			$url = str_replace(
				[ '{license_code}', '{email}' ],
				[
					$this->get_license_code() ? $this->get_license_code() : '',
					! empty( $data['buyer_email'] ) ? $data['buyer_email'] : '',
				],
				$data['renewal_url']
			);

			return $url;
		}

		/**
		 * Gets the license data from the database.
		 *
		 * @since 4.0.0
		 *
		 * @return false|array License data or false if not found.
		 */
		public function get_license_data() {
			return get_option( $this->get_license_data_key() );
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
			return update_option( $this->get_license_data_key(), $data );
		}

		/**
		 * Records that the server rejected the stored license.
		 *
		 * Marks the stored data invalid rather than deleting the license code:
		 * the server returns `invalid_license` for any unmatched code/product
		 * pair, which also covers a site pointed at the wrong product or a
		 * license row removed by mistake. Enforcement is server-side either
		 * way, so discarding the customer's only copy of the code buys nothing.
		 * Existing data (notably `renewal_url`) is preserved.
		 *
		 * @since 4.0.0
		 *
		 * @return bool True if the value was updated, false otherwise.
		 */
		public function mark_license_invalid() {
			$data = $this->get_license_data();

			if ( ! is_array( $data ) ) {
				$data = [];
			}

			$data['status'] = 'invalid';

			return $this->update_license_data( $data );
		}

		/**
		 * Deletes the license code from the database.
		 *
		 * Also removes the id-based copy when a uid is set, so a deleted
		 * license cannot be resurrected by the clone.
		 *
		 * @since 4.0.0
		 *
		 * @return bool True if the option was deleted, false otherwise.
		 */
		public function delete_license_code() {
			// Only an explicit removal of a code that was there tells the
			// server to unbind the install (the next track sends
			// `license: ""`). A key that is merely unreadable — a missed
			// legacy migration, a failed option read — never does.
			if ( $this->integration->license_enabled && $this->has_license_code() ) {
				update_option( $this->get_license_removed_key(), time(), false );
			}

			if ( $this->integration->product_uid ) {
				delete_option( $this->get_legacy_license_code_key() );
			}

			return delete_option( $this->get_license_code_key() );
		}

		/**
		 * Deletes the license data from the database.
		 *
		 * Also removes the id-based copy when a uid is set.
		 *
		 * @since 4.0.0
		 *
		 * @return bool True if the option was deleted, false otherwise.
		 */
		public function delete_license_data() {
			if ( $this->integration->product_uid ) {
				delete_option( $this->get_legacy_license_data_key() );
			}

			return delete_option( $this->get_license_data_key() );
		}

		/**
		 * Checks if the license is currently active.
		 *
		 * @since 4.0.0
		 *
		 * @return bool True if license is active, false otherwise.
		 */
		public function is_license_active() {
			if ( 'active' === $this->get_license_status() ) {
				return true;
			}

			return false;
		}
	}

endif;
