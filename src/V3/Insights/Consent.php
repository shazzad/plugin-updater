<?php
/**
 * Insights consent state.
 *
 * @package Shazzad\PluginUpdater\V3
 * @version 3.0
 */

namespace Shazzad\PluginUpdater\V3\Insights;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\Consent' ) ) :

	/**
	 * Class Consent
	 *
	 * Holds the per-plugin consent state (`yes`, `no`, or unset) and the
	 * random install token sent with every Insights call.
	 *
	 * In `consent` mode (free plugins) the stored option decides. In
	 * `commercial` mode consent is always granted and the option is never
	 * read — commercial installs report through the pings they already
	 * send, so there is nothing to ask.
	 *
	 * State only: scheduling and HTTP live in Scheduler and Client, which
	 * both ask this class before doing anything.
	 *
	 * @since 3.0.0
	 */
	class Consent {

		/**
		 * Consent granted.
		 *
		 * @since 3.0.0
		 *
		 * @var string
		 */
		const YES = 'yes';

		/**
		 * Consent declined or withdrawn.
		 *
		 * @since 3.0.0
		 *
		 * @var string
		 */
		const NO = 'no';

		/**
		 * Free plugin mode: nothing is sent until the admin opts in.
		 *
		 * @since 3.0.0
		 *
		 * @var string
		 */
		const MODE_CONSENT = 'consent';

		/**
		 * Commercial plugin mode: consent is implied.
		 *
		 * @since 3.0.0
		 *
		 * @var string
		 */
		const MODE_COMMERCIAL = 'commercial';

		/**
		 * Length of the generated install token.
		 *
		 * @since 3.0.0
		 *
		 * @var int
		 */
		const TOKEN_LENGTH = 32;

		/**
		 * Storage slug (sanitized plugin directory name).
		 *
		 * @since 3.0.0
		 *
		 * @var string
		 */
		public $slug;

		/**
		 * `consent` or `commercial`.
		 *
		 * @since 3.0.0
		 *
		 * @var string
		 */
		public $mode;

		/**
		 * Constructor.
		 *
		 * @since 3.0.0
		 *
		 * @param string $slug Storage slug; option keys are `{slug}_insights_*`.
		 * @param string $mode `consent` (default) or `commercial`. Anything else
		 *                     falls back to `consent`, the safe side.
		 */
		public function __construct( $slug, $mode = self::MODE_CONSENT ) {
			$this->slug = (string) $slug;
			$this->mode = self::MODE_COMMERCIAL === $mode ? self::MODE_COMMERCIAL : self::MODE_CONSENT;
		}

		/**
		 * Whether consent is implied (commercial mode).
		 *
		 * @since 3.0.0
		 *
		 * @return bool
		 */
		public function is_commercial() {
			return self::MODE_COMMERCIAL === $this->mode;
		}

		/**
		 * Option key holding the consent state.
		 *
		 * @since 3.0.0
		 *
		 * @return string
		 */
		public function get_consent_key() {
			return "{$this->slug}_insights_consent";
		}

		/**
		 * Option key holding the install token.
		 *
		 * @since 3.0.0
		 *
		 * @return string
		 */
		public function get_token_key() {
			return "{$this->slug}_insights_token";
		}

		/**
		 * Option key holding a failed opt-out that still has to reach the
		 * server (`since` = first failure, `last_try` = latest attempt).
		 *
		 * @since 3.0.0
		 *
		 * @return string
		 */
		public function get_optout_pending_key() {
			return "{$this->slug}_insights_optout_pending";
		}

		/**
		 * The pending opt-out, if any.
		 *
		 * @since 3.0.0
		 *
		 * @return array Empty when none; otherwise `since` and `last_try` timestamps.
		 */
		public function get_optout_pending() {
			$pending = get_option( $this->get_optout_pending_key(), [] );

			if ( ! \is_array( $pending ) || empty( $pending['since'] ) ) {
				return [];
			}

			return [
				'since'    => (int) $pending['since'],
				'last_try' => isset( $pending['last_try'] ) ? (int) $pending['last_try'] : (int) $pending['since'],
			];
		}

		/**
		 * Whether a failed opt-out is waiting to be retried. While it is, the
		 * client may send the opt-out call (site URL + token only) even though
		 * consent is `no`.
		 *
		 * @since 3.0.0
		 *
		 * @return bool
		 */
		public function has_optout_pending() {
			return ! empty( $this->get_optout_pending() );
		}

		/**
		 * Records a failed opt-out attempt. The first failure time is kept, so
		 * retries give up a fixed period after the admin opted out.
		 *
		 * @since 3.0.0
		 *
		 * @return void
		 */
		public function mark_optout_pending() {
			$pending = $this->get_optout_pending();
			$now     = time();

			update_option(
				$this->get_optout_pending_key(),
				[
					'since'    => ! empty( $pending ) ? $pending['since'] : $now,
					'last_try' => $now,
				],
				false
			);
		}

		/**
		 * Forgets a pending opt-out (it went through, was superseded by a new
		 * opt-in, or retries gave up).
		 *
		 * @since 3.0.0
		 *
		 * @return void
		 */
		public function clear_optout_pending() {
			if ( $this->has_optout_pending() ) {
				delete_option( $this->get_optout_pending_key() );
			}
		}

		/**
		 * Current consent state.
		 *
		 * @since 3.0.0
		 *
		 * @return string `yes`, `no`, or '' while the admin has not answered.
		 *                Always `yes` in commercial mode.
		 */
		public function get() {
			if ( $this->is_commercial() ) {
				return self::YES;
			}

			$value = get_option( $this->get_consent_key(), '' );

			return \in_array( $value, [ self::YES, self::NO ], true ) ? $value : '';
		}

		/**
		 * Whether data may be sent.
		 *
		 * @since 3.0.0
		 *
		 * @return bool
		 */
		public function is_granted() {
			return self::YES === $this->get();
		}

		/**
		 * Whether the admin has answered (either way). Commercial mode
		 * counts as answered.
		 *
		 * @since 3.0.0
		 *
		 * @return bool
		 */
		public function is_set() {
			return '' !== $this->get();
		}

		/**
		 * Records consent and makes sure an install token exists.
		 *
		 * @since 3.0.0
		 *
		 * @return string The install token.
		 */
		public function grant() {
			if ( ! $this->is_commercial() ) {
				update_option( $this->get_consent_key(), self::YES, false );
			}

			return $this->ensure_token();
		}

		/**
		 * Records that consent was declined or withdrawn. The token stays so a
		 * later opt-in keeps the same install identity.
		 *
		 * No-op in commercial mode, where consent is not a stored choice.
		 *
		 * @since 3.0.0
		 *
		 * @return void
		 */
		public function revoke() {
			if ( $this->is_commercial() ) {
				return;
			}

			update_option( $this->get_consent_key(), self::NO, false );
		}

		/**
		 * The stored install token.
		 *
		 * @since 3.0.0
		 *
		 * @return string Empty string when none was generated yet.
		 */
		public function get_token() {
			$token = get_option( $this->get_token_key(), '' );

			return \is_string( $token ) ? $token : '';
		}

		/**
		 * Returns the install token, generating and storing one first when
		 * missing.
		 *
		 * @since 3.0.0
		 *
		 * @return string
		 */
		public function ensure_token() {
			$token = $this->get_token();

			if ( '' === $token ) {
				$token = wp_generate_password( self::TOKEN_LENGTH, false );
				update_option( $this->get_token_key(), $token, false );
			}

			return $token;
		}
	}

endif;
