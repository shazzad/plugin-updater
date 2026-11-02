<?php
/**
 * Insights HTTP client.
 *
 * @package Shazzad\PluginUpdater\V4
 * @version 4.0
 */

namespace Shazzad\PluginUpdater\V4\Insights;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\Client' ) ) :

	/**
	 * Class Client
	 *
	 * Talks to the repo server's Insights routes (`wp-repo/v4` track/optout). This is the
	 * one place data leaves the site, and it refuses to send anything unless
	 * Consent says yes — every caller (Scheduler, opt-in, opt-out) goes
	 * through here, so the rule cannot be bypassed by a new call path.
	 *
	 * Errors never throw and never block: they come back as WP_Error and the
	 * next daily run retries.
	 *
	 * @since 4.0.0
	 */
	class Client {

		/**
		 * HTTP timeout in seconds.
		 *
		 * @since 4.0.0
		 *
		 * @var int
		 */
		const TIMEOUT = 5;

		/**
		 * Shape of a plugin uid the `wp-repo/v4` routes accept: the server's
		 * route pattern `prod_[a-z0-9]+`. Anything else is `404 rest_no_route`
		 * (an upper-cased uid reaches the handler but never matches a plugin).
		 * The server generates `prod_` + 20 base36 characters; the length is
		 * not enforced here so a future uid length keeps working. `\z`, not
		 * `$`: a trailing newline must not pass.
		 *
		 * @since 4.0.0
		 *
		 * @var string
		 */
		const UID_PATTERN = '/^prod_[a-z0-9]+\z/';

		/**
		 * Error codes for a configuration no request can succeed with. No
		 * HTTP call is made for them and retrying cannot fix them.
		 *
		 * @since 4.0.0
		 *
		 * @var string[]
		 */
		const CONFIG_ERROR_CODES = [
			'wprepo_insights_no_api_url',
			'wprepo_insights_v3_api_url',
			'wprepo_insights_no_product_uid',
		];

		/**
		 * Repo API base, e.g. `https://w4dev.com/wp-json/wp-repo/v4`.
		 *
		 * @since 4.0.0
		 *
		 * @var string
		 */
		public $api_url;

		/**
		 * Plugin key in the URL: the `prod_…` uid (the only identifier
		 * `wp-repo/v4` accepts). Empty = nothing is ever sent.
		 *
		 * @since 4.0.0
		 *
		 * @var string
		 */
		public $product_key;

		/**
		 * Payload builder.
		 *
		 * @since 4.0.0
		 *
		 * @var Collector
		 */
		public $collector;

		/**
		 * Consent state; the send gate.
		 *
		 * @since 4.0.0
		 *
		 * @var Consent
		 */
		public $consent;

		/**
		 * Constructor.
		 *
		 * @since 4.0.0
		 *
		 * @param string    $api_url     Insights API base URL.
		 * @param string    $product_key Product uid (`prod_…`).
		 * @param Collector $collector   Payload builder.
		 * @param Consent   $consent     Consent state.
		 */
		public function __construct( $api_url, $product_key, Collector $collector, Consent $consent ) {
			$this->api_url     = \rtrim( (string) $api_url, '/' );
			$this->product_key = (string) $product_key;
			$this->collector   = $collector;
			$this->consent     = $consent;
		}

		/**
		 * Whether $uid has the shape `wp-repo/v4` routes accept.
		 *
		 * @since 4.0.0
		 *
		 * @param mixed $uid Candidate uid.
		 * @return bool
		 */
		public static function is_valid_uid( $uid ) {
			return \is_string( $uid ) && (bool) \preg_match( self::UID_PATTERN, $uid );
		}

		/**
		 * Whether $api_url is the `wp-repo/v3` base (as copied from a V2
		 * config). v3 has no `plugins/{uid}` routes.
		 *
		 * @since 4.0.0
		 *
		 * @param mixed $api_url API base URL.
		 * @return bool
		 */
		public static function is_v3_api_url( $api_url ) {
			return \is_string( $api_url ) && (bool) \preg_match( '#/wp-repo/v3/?$#', $api_url );
		}

		/**
		 * Why no request can be sent with this configuration, or null when
		 * one can: an empty `api_url`, a `wp-repo/v3` `api_url`, or a missing
		 * or malformed uid.
		 *
		 * @since 4.0.0
		 *
		 * @return WP_Error|null
		 */
		public function get_config_error() {
			if ( '' === $this->api_url ) {
				return new WP_Error( 'wprepo_insights_no_api_url', 'No api_url configured; nothing sent.' );
			}

			if ( self::is_v3_api_url( $this->api_url ) ) {
				return new WP_Error( 'wprepo_insights_v3_api_url', 'api_url points at wp-repo/v3, which has no Insights routes; nothing sent.' );
			}

			if ( ! self::is_valid_uid( $this->product_key ) ) {
				return new WP_Error( 'wprepo_insights_no_product_uid', 'No valid product_uid configured; nothing sent.' );
			}

			return null;
		}

		/**
		 * Sends one tracking event.
		 *
		 * @since 4.0.0
		 *
		 * @param string $event `daily`, `activate`, `deactivate`, `optin` or `upgrade`.
		 * @return array|WP_Error Decoded response body (possibly empty) or WP_Error.
		 */
		public function track( $event ) {
			if ( ! $this->consent->is_granted() ) {
				return new WP_Error( 'wprepo_insights_no_consent', 'Insights consent not granted; nothing sent.' );
			}

			$payload = $this->collector->collect( $event );
			$result  = $this->post( 'track', $payload );

			if ( ! is_wp_error( $result ) && \array_key_exists( 'license', $payload ) ) {
				$this->collector->license_sent( $payload['license'] );
			}

			return $result;
		}

		/**
		 * Asks the server to delete this install's data. Needs the token the
		 * install tracked with; sent while consent is still `yes` (callers
		 * revoke consent after this returns), or later while a failed
		 * opt-out is pending retry. It only ever sends the site URL and the
		 * token.
		 *
		 * @since 4.0.0
		 *
		 * @return array|WP_Error Decoded response body (possibly empty) or WP_Error.
		 */
		public function optout() {
			if ( ! $this->consent->is_granted() && ! $this->consent->has_optout_pending() ) {
				return new WP_Error( 'wprepo_insights_no_consent', 'Insights consent not granted; nothing sent.' );
			}

			$token = $this->consent->get_token();

			if ( '' === $token ) {
				return new WP_Error( 'wprepo_insights_no_token', 'No Insights token stored; nothing to opt out of.' );
			}

			return $this->post(
				'optout',
				[
					'site_url' => $this->collector->get_site_url(),
					'token'    => $token,
				]
			);
		}

		/**
		 * Full URL of a plugin route.
		 *
		 * @since 4.0.0
		 *
		 * @param string $route `track` or `optout`.
		 * @return string
		 */
		public function get_url( $route ) {
			return "{$this->api_url}/plugins/{$this->product_key}/{$route}";
		}

		/**
		 * POSTs a JSON body.
		 *
		 * @since 4.0.0
		 *
		 * @param string $route Route name.
		 * @param array  $body  Body data.
		 * @return array|WP_Error
		 */
		protected function post( $route, array $body ) {
			// Without a usable uid and v4 base the route would be a 404.
			$config_error = $this->get_config_error();

			if ( $config_error ) {
				return $config_error;
			}

			$response = wp_remote_post(
				$this->get_url( $route ),
				[
					'timeout' => self::TIMEOUT,
					'headers' => [
						'Content-Type' => 'application/json',
						'Accept'       => 'application/json',
					],
					'body'    => wp_json_encode( $body ),
				]
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$status_code = (int) wp_remote_retrieve_response_code( $response );
			$decoded     = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			$decoded     = \is_array( $decoded ) ? $decoded : [];

			if ( $status_code < 200 || $status_code >= 300 ) {
				return new WP_Error(
					! empty( $decoded['code'] ) ? (string) $decoded['code'] : 'wprepo_insights_http_error',
					! empty( $decoded['message'] ) ? (string) $decoded['message'] : 'Insights request failed.',
					[ 'status' => $status_code ]
				);
			}

			return $decoded;
		}
	}

endif;
