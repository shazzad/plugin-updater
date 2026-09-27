<?php
/**
 * Insights — usage tracking with consent, for free plugins.
 *
 * @package Shazzad\PluginUpdater\V4
 * @version 4.0
 */

namespace Shazzad\PluginUpdater\V4;

use Shazzad\PluginUpdater\V4\Insights\Client;
use Shazzad\PluginUpdater\V4\Insights\Collector;
use Shazzad\PluginUpdater\V4\Insights\Consent;
use Shazzad\PluginUpdater\V4\Insights\Notice;
use Shazzad\PluginUpdater\V4\Insights\Scheduler;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\Insights' ) ) :

	/**
	 * Class Insights
	 *
	 * Free-plugin entry point. Wires the consent notice, consent state,
	 * payload collector, HTTP client and scheduler — and nothing else. It
	 * never loads update or license code, so a wordpress.org plugin can ship
	 * this file plus `Insights/` and strip the rest of the package.
	 *
	 * Nothing is sent until an admin clicks "Allow" (or the plugin calls
	 * opt_in() from its own UI).
	 *
	 * @since 4.0.0
	 */
	class Insights {

		/**
		 * Insights API base URL.
		 *
		 * @since 4.0.0
		 *
		 * @var string
		 */
		public $api_url;

		/**
		 * Plugin basename (`dir/file.php`).
		 *
		 * @since 4.0.0
		 *
		 * @var string
		 */
		public $file;

		/**
		 * Storage slug: the sanitized plugin directory name.
		 *
		 * @since 4.0.0
		 *
		 * @var string
		 */
		public $slug;

		/**
		 * Product uid (`prod_…`). Required; the API URLs are built from it.
		 *
		 * @since 4.0.0
		 *
		 * @var string
		 */
		public $product_uid = '';

		/**
		 * Numeric product id. Informational only: never sent.
		 *
		 * @since 4.0.0
		 *
		 * @var string
		 */
		public $product_id = '';

		/**
		 * Name shown in the notice; '' = plugin header Name.
		 *
		 * @since 4.0.0
		 *
		 * @var string
		 */
		public $name = '';

		/**
		 * Privacy/"Learn more" URL; '' = no link.
		 *
		 * @since 4.0.0
		 *
		 * @var string
		 */
		public $privacy_url = '';

		/**
		 * Consent state.
		 *
		 * @since 4.0.0
		 *
		 * @var Consent
		 */
		public $consent;

		/**
		 * Payload builder.
		 *
		 * @since 4.0.0
		 *
		 * @var Collector
		 */
		public $collector;

		/**
		 * HTTP client.
		 *
		 * @since 4.0.0
		 *
		 * @var Client
		 */
		public $client;

		/**
		 * Scheduler.
		 *
		 * @since 4.0.0
		 *
		 * @var Scheduler
		 */
		public $scheduler;

		/**
		 * Consent notice; null when `notice` is false or nothing could be sent
		 * (no valid `product_uid`, no or a `wp-repo/v3` `api_url`).
		 *
		 * @since 4.0.0
		 *
		 * @var Notice|null
		 */
		public $notice = null;

		/**
		 * Constructor.
		 *
		 * @since 4.0.0
		 *
		 * @param array $config {
		 *     Insights configuration.
		 *
		 *     @type string         $api_url       Repo API base, e.g.
		 *                                         `https://w4dev.com/wp-json/wp-repo/v4`. Required.
		 *     @type string         $file          Plugin main file: __FILE__ or its plugin_basename() form. Required.
		 *     @type string         $product_uid   `prod_…` uid on the repo server. Required: without it
		 *                                         nothing is sent and no consent notice is drawn.
		 *     @type string         $product_id    Numeric product id. Optional, never sent.
		 *     @type string         $name          Name shown in the notice. Default: plugin header Name.
		 *     @type string         $privacy_url   "Learn more" link in the notice. Omitted when empty.
		 *     @type array|false    $notice        `screens` (screen ids; default every admin screen),
		 *                                         `text` (override; `%s` = name), `show_callback` (extra gate),
		 *                                         `items` (extra "What we collect" lines, strings);
		 *                                         or false to draw no notice (the plugin calls opt_in() itself).
		 *     @type array          $meta          Static metadata; Closures resolve at send time.
		 *     @type callable       $meta_callback Returns a metadata array at send time.
		 * }
		 *
		 * Unknown or missing keys trigger `_doing_it_wrong()` in debug mode;
		 * construction always proceeds — a misconfigured Insights must never
		 * fatal the plugin embedding it.
		 */
		public function __construct( array $config ) {
			$this->validate_config( $config );

			$this->api_url     = isset( $config['api_url'] ) ? (string) $config['api_url'] : '';
			$this->product_uid = isset( $config['product_uid'] ) ? (string) $config['product_uid'] : '';
			$this->product_id  = isset( $config['product_id'] ) ? (string) $config['product_id'] : '';
			$this->name        = isset( $config['name'] ) && \is_string( $config['name'] ) ? $config['name'] : '';
			$this->privacy_url = isset( $config['privacy_url'] ) && \is_string( $config['privacy_url'] ) ? $config['privacy_url'] : '';

			$file = isset( $config['file'] ) ? (string) $config['file'] : '';

			if ( $file && \function_exists( 'plugin_basename' ) ) {
				$file = plugin_basename( $file );
			}

			$this->file = $file;
			$this->slug = sanitize_key( Collector::basename_to_slug( $file ) );

			$this->consent   = new Consent( $this->slug, Consent::MODE_CONSENT );
			$this->collector = new Collector(
				$this->file,
				$this->consent,
				[
					'meta'          => isset( $config['meta'] ) ? $config['meta'] : [],
					'meta_callback' => isset( $config['meta_callback'] ) ? $config['meta_callback'] : null,
				]
			);
			$this->client    = new Client( $this->api_url, $this->get_product_key(), $this->collector, $this->consent );
			$this->scheduler = new Scheduler( $this->file, $this->slug, $this->client, $this->collector, $this->consent );

			$notice = \array_key_exists( 'notice', $config ) ? $config['notice'] : [];

			// Nothing can be sent (no valid uid, no or a v3 api_url): asking
			// for consent would be a lie.
			if ( false !== $notice && null === $this->client->get_config_error() ) {
				$this->notice = new Notice( $this, \is_array( $notice ) ? $notice : [] );
			}
		}

		/**
		 * Flags config typos and missing keys in debug mode. Debug notices only.
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

			$known = [ 'api_url', 'file', 'product_uid', 'product_id', 'name', 'privacy_url', 'notice', 'meta', 'meta_callback' ];

			foreach ( \array_diff( \array_keys( $config ), $known ) as $unknown ) {
				_doing_it_wrong( __METHOD__, esc_html( \sprintf( 'Unrecognized config key "%s".', (string) $unknown ) ), '4.0.0' );
			}

			foreach ( [ 'api_url', 'file' ] as $required ) {
				if ( empty( $config[ $required ] ) ) {
					_doing_it_wrong( __METHOD__, esc_html( \sprintf( 'Missing required config key "%s".', $required ) ), '4.0.0' );
				}
			}

			if ( empty( $config['product_uid'] ) ) {
				_doing_it_wrong(
					__METHOD__,
					'Missing required config key "product_uid". The wp-repo/v4 API addresses a plugin by its'
					. ' "prod_…" uid only, so nothing is sent and no consent notice is shown without it.',
					'4.0.0'
				);
			} elseif ( ! Client::is_valid_uid( $config['product_uid'] ) ) {
				_doing_it_wrong(
					__METHOD__,
					'Config "product_uid" is not a valid uid (expected "prod_" followed by lowercase letters'
					. ' and digits, as shown on the product screen). Nothing is sent and no consent notice is'
					. ' shown with it.',
					'4.0.0'
				);
			}

			if ( isset( $config['meta_callback'] ) && ! \is_callable( $config['meta_callback'] ) ) {
				_doing_it_wrong( __METHOD__, 'Config key "meta_callback" is not callable and will be ignored.', '4.0.0' );
			}

			if ( isset( $config['meta'] ) && ! \is_array( $config['meta'] ) ) {
				_doing_it_wrong( __METHOD__, 'Config key "meta" is not an array and will be ignored.', '4.0.0' );
			}

			if ( \array_key_exists( 'notice', $config ) && false !== $config['notice'] && ! \is_array( $config['notice'] ) ) {
				_doing_it_wrong( __METHOD__, 'Config key "notice" must be an array or false; using the defaults.', '4.0.0' );
			}

			if ( isset( $config['api_url'] ) && \is_string( $config['api_url'] ) && Client::is_v3_api_url( \rtrim( $config['api_url'], '/' ) ) ) {
				_doing_it_wrong(
					__METHOD__,
					'Config "api_url" points at wp-repo/v3, which has no Insights routes; use wp-repo/v4.'
					. ' Nothing is sent and no consent notice is shown until then.',
					'4.0.0'
				);
			}

			if ( \is_array( $config['notice'] ?? null ) ) {
				foreach ( \array_diff( \array_keys( $config['notice'] ), [ 'screens', 'text', 'show_callback', 'items' ] ) as $unknown ) {
					_doing_it_wrong( __METHOD__, esc_html( \sprintf( 'Unrecognized notice key "%s".', (string) $unknown ) ), '4.0.0' );
				}

				if ( isset( $config['notice']['show_callback'] ) && ! \is_callable( $config['notice']['show_callback'] ) ) {
					_doing_it_wrong( __METHOD__, 'Notice key "show_callback" is not callable and will be ignored.', '4.0.0' );
				}

				if ( isset( $config['notice']['items'] ) ) {
					$items = $config['notice']['items'];

					if ( ! \is_array( $items ) ) {
						_doing_it_wrong( __METHOD__, 'Notice key "items" is not an array and will be ignored.', '4.0.0' );
					} elseif ( \count( \array_filter( $items, [ Notice::class, 'is_valid_item' ] ) ) !== \count( $items ) ) {
						_doing_it_wrong( __METHOD__, 'Notice key "items" must hold non-empty strings; other entries will be ignored.', '4.0.0' );
					}
				}
			}
		}

		/**
		 * Plugin key used in API URLs (`{api_url}/plugins/{uid}/…`): the uid
		 * only. `wp-repo/v4` answers a numeric id with `404 rest_no_route`,
		 * so there is no fallback to `product_id`.
		 *
		 * @since 4.0.0
		 *
		 * @return string The uid, or '' when none is configured.
		 */
		public function get_product_key() {
			return $this->product_uid;
		}

		/**
		 * Product name for the notice: configured name, else plugin header
		 * Name, else the slug.
		 *
		 * @since 4.0.0
		 *
		 * @return string
		 */
		public function get_name() {
			if ( '' !== $this->name ) {
				return $this->name;
			}

			$header = $this->collector->get_plugin_header();

			return '' !== $header['Name'] ? $header['Name'] : $this->slug;
		}

		/**
		 * Whether the admin allowed tracking.
		 *
		 * @since 4.0.0
		 *
		 * @return bool
		 */
		public function has_consent() {
			return $this->consent->is_granted();
		}

		/**
		 * Consent state.
		 *
		 * @since 4.0.0
		 *
		 * @return string `yes`, `no`, or '' while unanswered.
		 */
		public function get_consent() {
			return $this->consent->get();
		}

		/**
		 * Grants consent: stores `yes`, creates the token, schedules the
		 * daily track and sends an immediate `optin` track.
		 *
		 * When nothing could ever be sent (no valid `product_uid`, no or a
		 * `wp-repo/v3` `api_url`) it stores nothing and returns that error.
		 *
		 * @since 4.0.0
		 *
		 * @return array|\WP_Error Result of the `optin` track.
		 */
		public function opt_in() {
			// Nothing could ever be sent: store no consent, token or cron.
			$config_error = $this->client->get_config_error();

			if ( $config_error ) {
				return $config_error;
			}

			// A new opt-in supersedes an earlier deletion request that never
			// reached the server.
			$this->consent->clear_optout_pending();
			$this->consent->grant();
			$this->scheduler->schedule();

			return $this->scheduler->send( 'optin' );
		}

		/**
		 * Declines or withdraws consent: when consent was `yes`, asks the
		 * server to delete this install's data (token-authenticated); then
		 * stores `no` and clears the cron. From an unanswered state nothing
		 * is sent at all.
		 *
		 * When the deletion request fails (timeout, 5xx), consent is still
		 * `no` at once — nothing is tracked any more — but the request is
		 * remembered in `{slug}_insights_optout_pending` and retried on
		 * admin_init and by the daily cron (kept for that), until it goes
		 * through or Scheduler::OPTOUT_GIVE_UP has passed.
		 *
		 * @since 4.0.0
		 *
		 * @return array|\WP_Error|null Result of the optout call, or null when
		 *                              nothing was sent.
		 */
		public function opt_out() {
			$result = null;

			if ( $this->consent->is_granted() || $this->consent->has_optout_pending() ) {
				$result = $this->client->optout();
			}

			$this->consent->revoke();

			if ( Scheduler::optout_should_retry( $result ) ) {
				$this->consent->mark_optout_pending();
				$this->scheduler->schedule();
			} else {
				$this->consent->clear_optout_pending();
				$this->scheduler->unschedule();
			}

			return $result;
		}

		/**
		 * Removes everything Insights stored for a plugin: the consent, token,
		 * last-send and pending-opt-out options and the daily cron — on every
		 * site of a multisite network. Sends nothing.
		 *
		 * Call it from the plugin's uninstall routine: `uninstall.php` (pass
		 * `WP_UNINSTALL_PLUGIN`) or a `register_uninstall_hook()` callback in
		 * the main file (pass `__FILE__`).
		 *
		 * @since 4.0.0
		 *
		 * @param string $file Plugin main file: absolute path or plugin_basename() form.
		 * @return void
		 */
		public static function uninstall( $file ) {
			$file = (string) $file;

			if ( $file && \function_exists( 'plugin_basename' ) ) {
				$file = plugin_basename( $file );
			}

			$slug = sanitize_key( Collector::basename_to_slug( $file ) );

			if ( '' === $slug ) {
				return;
			}

			Scheduler::each_site(
				function () use ( $slug ) {
					foreach ( self::get_option_keys( $slug ) as $key ) {
						delete_option( $key );
					}

					wp_clear_scheduled_hook( "wprepo_insights_track_{$slug}" );
				}
			);
		}

		/**
		 * Every option key Insights stores for a slug.
		 *
		 * @since 4.0.0
		 *
		 * @param string $slug Storage slug.
		 * @return string[]
		 */
		public static function get_option_keys( $slug ) {
			$consent = new Consent( $slug );

			return [
				$consent->get_consent_key(),
				$consent->get_token_key(),
				"{$slug}_insights_last_send",
				$consent->get_optout_pending_key(),
				"{$slug}_insights_last_attempt",
				"{$slug}_insights_disabled_version",
			];
		}
	}

endif;
