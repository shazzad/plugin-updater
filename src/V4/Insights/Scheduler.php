<?php
/**
 * Insights scheduler.
 *
 * @package Shazzad\PluginUpdater\V4
 * @version 4.0
 */

namespace Shazzad\PluginUpdater\V4\Insights;

use WP_Error;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\Scheduler' ) ) :

	/**
	 * Class Scheduler
	 *
	 * Decides when to track: a daily cron event plus the plugin's own
	 * activate / deactivate / upgrade moments. Every send goes through
	 * Client::track(), which refuses without consent; the checks here only
	 * avoid pointless work.
	 *
	 * Daily rather than weekly: the repo server marks an install inactive
	 * after seven days without a check-in.
	 *
	 * @since 4.0.0
	 */
	class Scheduler {

		/**
		 * Minimum gap between two daily tracks, in seconds (20 hours). Keeps a
		 * cron that fires twice, or a re-scheduled event, from double-sending.
		 *
		 * @since 4.0.0
		 *
		 * @var int
		 */
		const MIN_INTERVAL = 72000;

		/**
		 * Minimum gap between two retries of a failed opt-out, in seconds
		 * (1 hour). The admin_init retry would otherwise cost every admin page
		 * load a timeout while the server is down.
		 *
		 * @since 4.0.0
		 *
		 * @var int
		 */
		const OPTOUT_RETRY_INTERVAL = 3600;

		/**
		 * How long a failed opt-out keeps being retried, counted from the
		 * first failure, in seconds (7 days — the server's own inactivity
		 * window).
		 *
		 * @since 4.0.0
		 *
		 * @var int
		 */
		const OPTOUT_GIVE_UP = 604800;

		/**
		 * Plugin basename (`dir/file.php`).
		 *
		 * @since 4.0.0
		 *
		 * @var string
		 */
		public $file;

		/**
		 * Storage slug.
		 *
		 * @since 4.0.0
		 *
		 * @var string
		 */
		public $slug;

		/**
		 * HTTP client.
		 *
		 * @since 4.0.0
		 *
		 * @var Client
		 */
		public $client;

		/**
		 * Payload builder (its product_status is set here).
		 *
		 * @since 4.0.0
		 *
		 * @var Collector
		 */
		public $collector;

		/**
		 * Consent state.
		 *
		 * @since 4.0.0
		 *
		 * @var Consent
		 */
		public $consent;

		/**
		 * Constructor. Registers the cron, lifecycle and upgrade hooks.
		 *
		 * @since 4.0.0
		 *
		 * @param string    $file      Plugin basename.
		 * @param string    $slug      Storage slug.
		 * @param Client    $client    HTTP client.
		 * @param Collector $collector Payload builder.
		 * @param Consent   $consent   Consent state.
		 */
		public function __construct( $file, $slug, Client $client, Collector $collector, Consent $consent ) {
			$this->file      = (string) $file;
			$this->slug      = (string) $slug;
			$this->client    = $client;
			$this->collector = $collector;
			$this->consent   = $consent;

			add_action( $this->get_hook_name(), [ $this, 'run_daily' ] );
			add_action( "activate_{$this->file}", [ $this, 'product_activated' ] );
			add_action( "deactivate_{$this->file}", [ $this, 'product_deactivated' ] );
			add_action( 'upgrader_process_complete', [ $this, 'product_upgraded' ], 10, 2 );
			add_action( 'admin_init', [ $this, 'maybe_schedule' ] );
		}

		/**
		 * Cron hook name.
		 *
		 * @since 4.0.0
		 *
		 * @return string
		 */
		public function get_hook_name() {
			return "wprepo_insights_track_{$this->slug}";
		}

		/**
		 * Option key holding the last successful send timestamp.
		 *
		 * @since 4.0.0
		 *
		 * @return string
		 */
		public function get_last_send_key() {
			return "{$this->slug}_insights_last_send";
		}

		/**
		 * Timestamp of the last successful send, 0 when none.
		 *
		 * @since 4.0.0
		 *
		 * @return int
		 */
		public function get_last_send() {
			return (int) get_option( $this->get_last_send_key(), 0 );
		}

		/**
		 * Schedules the daily event when it is not already scheduled.
		 *
		 * @since 4.0.0
		 *
		 * @return void
		 */
		public function schedule() {
			if ( ! wp_next_scheduled( $this->get_hook_name() ) ) {
				wp_schedule_event( time(), 'daily', $this->get_hook_name() );
			}
		}

		/**
		 * Removes the daily event.
		 *
		 * @since 4.0.0
		 *
		 * @return void
		 */
		public function unschedule() {
			wp_clear_scheduled_hook( $this->get_hook_name() );
		}

		/**
		 * Re-creates a missing daily event on admin requests while consent is
		 * granted. Covers installs that never saw an activation with this code
		 * (a commercial plugin updated in place to V4) and cron entries lost
		 * to a cron reset. While a failed opt-out is pending, retries it
		 * instead (throttled to once per OPTOUT_RETRY_INTERVAL).
		 *
		 * @since 4.0.0
		 *
		 * @return void
		 */
		public function maybe_schedule() {
			if ( $this->consent->has_optout_pending() ) {
				$this->retry_optout();
				return;
			}

			if ( $this->consent->is_granted() ) {
				$this->schedule();
			}
		}

		/**
		 * Retries an opt-out that failed to reach the server (timeout, 5xx).
		 *
		 * Clears the pending flag and the cron once the call goes through, when
		 * there turns out to be no token to opt out with, when consent was
		 * granted again (the new opt-in supersedes the deletion), or after
		 * OPTOUT_GIVE_UP since the first failure.
		 *
		 * @since 4.0.0
		 *
		 * @return array|WP_Error|null Result of the optout call, or null when
		 *                             nothing was sent.
		 */
		public function retry_optout() {
			$pending = $this->consent->get_optout_pending();

			if ( empty( $pending ) ) {
				return null;
			}

			if ( $this->consent->is_granted() ) {
				$this->consent->clear_optout_pending();
				return null;
			}

			$now = time();

			if ( ( $now - $pending['since'] ) >= self::OPTOUT_GIVE_UP ) {
				$this->consent->clear_optout_pending();
				$this->unschedule();
				return null;
			}

			if ( ( $now - $pending['last_try'] ) < self::OPTOUT_RETRY_INTERVAL ) {
				return null;
			}

			// Record the attempt first, so a request that hangs past a fatal
			// timeout is not retried on the very next page load.
			$this->consent->mark_optout_pending();

			$result = $this->client->optout();

			if ( self::optout_should_retry( $result ) ) {
				return $result;
			}

			$this->consent->clear_optout_pending();
			$this->unschedule();

			return $result;
		}

		/**
		 * Whether an optout result is a failure worth retrying: any WP_Error
		 * except a missing token or a missing product uid, which no retry can
		 * fix.
		 *
		 * @since 4.0.0
		 *
		 * @param mixed $result Client::optout() result.
		 * @return bool
		 */
		public static function optout_should_retry( $result ) {
			return is_wp_error( $result )
				&& ! \in_array( $result->get_error_code(), [ 'wprepo_insights_no_token', 'wprepo_insights_no_product_uid' ], true );
		}

		/**
		 * Sends one event and records the send time on success.
		 *
		 * @since 4.0.0
		 *
		 * @param string $event Event name.
		 * @return array|WP_Error
		 */
		public function send( $event ) {
			if ( ! $this->consent->is_granted() ) {
				return new WP_Error( 'wprepo_insights_no_consent', 'Insights consent not granted; nothing sent.' );
			}

			$result = $this->client->track( $event );

			if ( ! is_wp_error( $result ) ) {
				update_option( $this->get_last_send_key(), time(), false );
			}

			return $result;
		}

		/**
		 * Daily cron callback. Sends at most once per MIN_INTERVAL. While a
		 * failed opt-out is pending, retries that instead of tracking.
		 *
		 * @since 4.0.0
		 *
		 * @return void
		 */
		public function run_daily() {
			if ( $this->consent->has_optout_pending() ) {
				$this->retry_optout();
				return;
			}

			if ( ! $this->consent->is_granted() ) {
				return;
			}

			$last_send = $this->get_last_send();

			if ( $last_send && ( time() - $last_send ) < self::MIN_INTERVAL ) {
				return;
			}

			$this->send( 'daily' );
		}

		/**
		 * Activation: schedule and send `activate` — only with consent.
		 *
		 * @since 4.0.0
		 *
		 * @return void
		 */
		public function product_activated() {
			$this->collector->product_status = 'active';

			if ( ! $this->consent->is_granted() ) {
				return;
			}

			$this->schedule();
			$this->send( 'activate' );
		}

		/**
		 * Deactivation: send `deactivate` with consent, and always clear the
		 * cron. Options stay so a re-activation does not ask again.
		 *
		 * On a network deactivation the cron is cleared on every site of the
		 * network too (each site scheduled its own). Only the current site
		 * sends a `deactivate` track: one request per site could stall the
		 * deactivation on a large network.
		 *
		 * @since 4.0.0
		 *
		 * @param bool $network_wide Whether the plugin is being network-deactivated.
		 * @return void
		 */
		public function product_deactivated( $network_wide = false ) {
			$this->collector->product_status = 'inactive';

			if ( $this->consent->is_granted() ) {
				$this->send( 'deactivate' );
			}

			$this->unschedule();

			if ( $network_wide && is_multisite() ) {
				$hook = $this->get_hook_name();

				self::each_site(
					function () use ( $hook ) {
						wp_clear_scheduled_hook( $hook );
					}
				);
			}
		}

		/**
		 * Runs a callback once per site: on each site of a multisite network
		 * (switched to it), or once on a single site.
		 *
		 * @since 4.0.0
		 *
		 * @param callable $callback Called with no arguments.
		 * @return void
		 */
		public static function each_site( callable $callback ) {
			if ( ! is_multisite() || ! \function_exists( 'get_sites' ) || ! \function_exists( 'switch_to_blog' ) ) {
				\call_user_func( $callback );
				return;
			}

			$site_ids = get_sites(
				[
					'fields' => 'ids',
					'number' => 0,
				]
			);

			foreach ( (array) $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );

				try {
					\call_user_func( $callback );
				} finally {
					restore_current_blog();
				}
			}
		}

		/**
		 * `upgrader_process_complete` callback: sends `upgrade` when this
		 * plugin was among the updated ones.
		 *
		 * @since 4.0.0
		 *
		 * @param mixed $upgrader   Upgrader instance (unused).
		 * @param array $hook_extra Upgrade details.
		 * @return void
		 */
		public function product_upgraded( $upgrader, $hook_extra = [] ) {
			unset( $upgrader );

			if ( ! $this->is_own_upgrade( $hook_extra ) || ! $this->consent->is_granted() ) {
				return;
			}

			$this->send( 'upgrade' );
		}

		/**
		 * Whether an upgrader run updated this plugin.
		 *
		 * @since 4.0.0
		 *
		 * @param mixed $hook_extra Upgrade details.
		 * @return bool
		 */
		public function is_own_upgrade( $hook_extra ) {
			if ( ! \is_array( $hook_extra ) ) {
				return false;
			}

			if ( ! isset( $hook_extra['action'], $hook_extra['type'] ) || 'update' !== $hook_extra['action'] || 'plugin' !== $hook_extra['type'] ) {
				return false;
			}

			$plugins = [];

			if ( ! empty( $hook_extra['plugins'] ) && \is_array( $hook_extra['plugins'] ) ) {
				$plugins = $hook_extra['plugins'];
			} elseif ( ! empty( $hook_extra['plugin'] ) ) {
				$plugins = [ $hook_extra['plugin'] ];
			}

			return \in_array( $this->file, $plugins, true );
		}
	}

endif;
