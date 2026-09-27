<?php
/**
 * WordPress Plugin Updater API Client.
 *
 * @package Shazzad\PluginUpdater\V4
 * @version 4.0
 */
namespace Shazzad\PluginUpdater\V4;

use WP_Error;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\Client' ) ) :

	/**
	 * Class Client
	 *
	 * Handles the update-API HTTP calls: license check, updates and details.
	 * Install tracking is not here — it goes through Insights\Client (the
	 * V2 `ping()` is gone).
	 *
	 * @since 4.0.0
	 */
	class Client {

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
		 * Check a license against the remote API.
		 *
		 * @since 4.0.0
		 *
		 * @param string $license License key. Uses stored license if empty.
		 * @return array|WP_Error Response data or WP_Error on failure.
		 */
		public function check_license( $license = '' ) {
			if ( empty( $license ) ) {
				$license = $this->integration->get_license_code();
			}

			$args = [];
			if ( $license ) {
				$args['license'] = $license;
			}

			return $this->request( 'check_license', $args );
		}

		/**
		 * Fetch available updates from the remote API.
		 *
		 * Uses a short-lived site transient cache to avoid redundant HTTP calls
		 * when WordPress sets the update_plugins transient multiple times.
		 *
		 * @since 4.0.0
		 *
		 * @param int $cache_period Cache duration in seconds. Pass 0 to skip caching.
		 * @return array|WP_Error Response data or WP_Error on failure.
		 */
		public function updates( $cache_period = 600 ) {
			if ( $cache_period > 0 ) {
				$cache_key = $this->integration->get_updates_cache_key();
				$cached    = get_site_transient( $cache_key );

				if ( false !== $cached ) {
					return $cached;
				}
			}

			$args = [];
			if ( $this->integration->license_enabled ) {
				$license = $this->integration->get_license_code();
				if ( $license ) {
					$args['license'] = $license;
				}
			}

			$response = $this->request( 'updates', $args );

			if ( $cache_period > 0 && ! is_wp_error( $response ) ) {
				set_site_transient( $cache_key, $response, $cache_period );
			}

			return $response;
		}

		/**
		 * Fetch plugin details from the remote API.
		 *
		 * Uses a short-lived site transient cache to avoid redundant HTTP calls
		 * from plugins_api and the license admin page.
		 *
		 * @since 4.0.0
		 *
		 * @param int $cache_period Cache duration in seconds. Pass 0 to skip caching.
		 * @return array|WP_Error Response data or WP_Error on failure.
		 */
		public function details( $cache_period = 600 ) {
			if ( $cache_period > 0 ) {
				$cache_key = $this->integration->get_details_cache_key();
				$cached    = get_site_transient( $cache_key );

				if ( false !== $cached ) {
					return $cached;
				}
			}

			$args = [];
			if ( $this->integration->license_enabled ) {
				$license = $this->integration->get_license_code();
				if ( $license ) {
					$args['license'] = $license;
				}
			}

			$response = $this->request( 'details', $args );

			if ( $cache_period > 0 && ! is_wp_error( $response ) ) {
				set_site_transient( $cache_key, $response, $cache_period );
			}

			return $response;
		}

		/**
		 * Sends an API request to the remote server.
		 *
		 * @since 4.0.0
		 *
		 * @param string $method  The API endpoint method.
		 * @param array  $args    Additional query arguments.
		 * @param int    $timeout Request timeout in seconds.
		 * @return array|WP_Error Response data or WP_Error on failure.
		 */
		private function request( $method, $args = [], $timeout = 5 ) {
			// wp-repo/v4 addresses a plugin by its `prod_…` uid only, and
			// wp-repo/v3 has no plugins/{uid} routes; any such URL is a 404,
			// so don't send one. Never an `invalid_license` verdict.
			$config_error = $this->integration->get_api_error();

			if ( $config_error ) {
				return $config_error;
			}

			$uid         = $this->integration->get_api_product_key();
			$request_url = "{$this->integration->api_url}/plugins/{$uid}/$method";

			if ( ! empty( $args ) ) {
				// add_query_arg() does not encode values; a key holding `+`,
				// `&` or `#` would otherwise reach the server altered.
				$request_url = add_query_arg( \array_map( 'rawurlencode', \array_map( 'strval', $args ) ), $request_url );
			}

			$request = wp_remote_request(
				$request_url,
				[ 'timeout' => $timeout ]
			);

			if ( is_wp_error( $request ) ) {
				return $request;
			}

			$status_code = wp_remote_retrieve_response_code( $request );
			$body        = wp_remote_retrieve_body( $request );
			$body        = json_decode( $body, true );

			if ( empty( $body ) ) {
				return new WP_Error(
					'wprepo_api_fail',
					'No response from update server'
				);
			}

			if ( $status_code >= 400 ) {
				return new WP_Error(
					! empty( $body['code'] ) ? $body['code'] : 'wprepo_api_error',
					! empty( $body['message'] ) ? $body['message'] : 'API request failed'
				);
			}

			return $body;
		}
	}

endif;
