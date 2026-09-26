<?php
/**
 * Insights scheduler.
 *
 * @package Shazzad\PluginUpdater\V3
 * @version 3.0
 */

namespace Shazzad\PluginUpdater\V3\Insights;

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
	 * @since 3.0.0
	 */
	class Scheduler {

		/**
		 * Minimum gap between two daily tracks, in seconds (20 hours). Keeps a
		 * cron that fires twice, or a re-scheduled event, from double-sending.
		 *
		 * @since 3.0.0
		 *
		 * @var int
		 */
		const MIN_INTERVAL = 72000;

		/**
		 * Plugin basename (`dir/file.php`).
		 *
		 * @since 3.0.0
		 *
		 * @var string
		 */
		public $file;

		/**
		 * Storage slug.
		 *
		 * @since 3.0.0
		 *
		 * @var string
		 */
		public $slug;

		/**
		 * HTTP client.
		 *
		 * @since 3.0.0
		 *
		 * @var Client
		 */
		public $client;

		/**
		 * Payload builder (its product_status is set here).
		 *
		 * @since 3.0.0
		 *
		 * @var Collector
		 */
		public $collector;

		/**
		 * Consent state.
		 *
		 * @since 3.0.0
		 *
		 * @var Consent
		 */
		public $consent;

		/**
		 * Constructor. Registers the cron, lifecycle and upgrade hooks.
		 *
		 * @since 3.0.0
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
		 * @since 3.0.0
		 *
		 * @return string
		 */
		public function get_hook_name() {
			return "wprepo_insights_track_{$this->slug}";
		}

		/**
		 * Option key holding the last successful send timestamp.
		 *
		 * @since 3.0.0
		 *
		 * @return string
		 */
		public function get_last_send_key() {
			return "{$this->slug}_insights_last_send";
		}

		/**
		 * Timestamp of the last successful send, 0 when none.
		 *
		 * @since 3.0.0
		 *
		 * @return int
		 */
		public function get_last_send() {
			return (int) get_option( $this->get_last_send_key(), 0 );
		}

		/**
		 * Schedules the daily event when it is not already scheduled.
		 *
		 * @since 3.0.0
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
		 * @since 3.0.0
		 *
		 * @return void
		 */
		public function unschedule() {
			wp_clear_scheduled_hook( $this->get_hook_name() );
		}

		/**
		 * Re-creates a missing daily event on admin requests while consent is
		 * granted. Covers installs that never saw an activation with this code
		 * (a commercial plugin updated in place to V3) and cron entries lost
		 * to a cron reset.
		 *
		 * @since 3.0.0
		 *
		 * @return void
		 */
		public function maybe_schedule() {
			if ( $this->consent->is_granted() ) {
				$this->schedule();
			}
		}

		/**
		 * Sends one event and records the send time on success.
		 *
		 * @since 3.0.0
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
		 * Daily cron callback. Sends at most once per MIN_INTERVAL.
		 *
		 * @since 3.0.0
		 *
		 * @return void
		 */
		public function run_daily() {
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
		 * @since 3.0.0
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
		 * @since 3.0.0
		 *
		 * @return void
		 */
		public function product_deactivated() {
			$this->collector->product_status = 'inactive';

			if ( $this->consent->is_granted() ) {
				$this->send( 'deactivate' );
			}

			$this->unschedule();
		}

		/**
		 * `upgrader_process_complete` callback: sends `upgrade` when this
		 * plugin was among the updated ones.
		 *
		 * @since 3.0.0
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
		 * @since 3.0.0
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
