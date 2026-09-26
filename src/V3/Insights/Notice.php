<?php
/**
 * Insights consent notice.
 *
 * @package Shazzad\PluginUpdater\V3
 * @version 3.0
 */

namespace Shazzad\PluginUpdater\V3\Insights;

use Shazzad\PluginUpdater\V3\Insights;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\Notice' ) ) :

	/**
	 * Class Notice
	 *
	 * The opt-in admin notice for free plugins: "Allow" / "No thanks" as
	 * nonce'd GET links, with a no-JavaScript `<details>` listing what is
	 * collected. Shown to users who can `manage_options`, on the configured
	 * screens, while consent is unset.
	 *
	 * @since 3.0.0
	 */
	class Notice {

		/**
		 * Query arg carrying the storage slug (scopes the request to one plugin).
		 *
		 * @since 3.0.0
		 *
		 * @var string
		 */
		const QUERY_SLUG = 'wprepo_insights';

		/**
		 * Query arg carrying the choice.
		 *
		 * @since 3.0.0
		 *
		 * @var string
		 */
		const QUERY_ACTION = 'wprepo_insights_action';

		/**
		 * Allowed choices.
		 *
		 * @since 3.0.0
		 *
		 * @var string[]
		 */
		const ACTIONS = [ 'allow', 'decline' ];

		/**
		 * Default notice text; `%1$s` is the product name.
		 *
		 * @since 3.0.0
		 *
		 * @var string
		 */
		const DEFAULT_TEXT = 'Want to help make <strong>%1$s</strong> even better? Allow %1$s to collect diagnostic data and usage information.';

		/**
		 * Owning Insights instance (consent, opt-in/opt-out).
		 *
		 * @since 3.0.0
		 *
		 * @var Insights
		 */
		public $insights;

		/**
		 * Screen ids the notice may render on; empty = every admin screen.
		 *
		 * @since 3.0.0
		 *
		 * @var string[]
		 */
		public $screens = [];

		/**
		 * Text override (`%s` / `%1$s` = product name); '' = default.
		 *
		 * @since 3.0.0
		 *
		 * @var string
		 */
		public $text = '';

		/**
		 * Extra gate; the notice renders only when it returns truthy.
		 *
		 * @since 3.0.0
		 *
		 * @var callable|null
		 */
		public $show_callback = null;

		/**
		 * Extra "What we collect" lines appended to the built-in list.
		 *
		 * @since 3.0.0
		 *
		 * @var string[]
		 */
		public $items = [];

		/**
		 * Constructor.
		 *
		 * @since 3.0.0
		 *
		 * @param Insights $insights Owning Insights instance.
		 * @param array    $args {
		 *     Optional.
		 *
		 *     @type string[] $screens       Screen ids.
		 *     @type string   $text          Text override.
		 *     @type callable $show_callback Extra gate.
		 *     @type string[] $items         Extra "What we collect" lines.
		 * }
		 */
		public function __construct( Insights $insights, array $args = [] ) {
			$this->insights = $insights;

			if ( ! empty( $args['screens'] ) ) {
				$this->screens = \array_values( \array_map( 'strval', (array) $args['screens'] ) );
			}

			if ( isset( $args['text'] ) && \is_string( $args['text'] ) ) {
				$this->text = $args['text'];
			}

			if ( isset( $args['show_callback'] ) && \is_callable( $args['show_callback'] ) ) {
				$this->show_callback = $args['show_callback'];
			}

			if ( isset( $args['items'] ) && \is_array( $args['items'] ) ) {
				$this->items = \array_values( \array_filter( $args['items'], [ self::class, 'is_valid_item' ] ) );
			}

			add_action( 'admin_notices', [ $this, 'render' ] );
			add_action( 'admin_init', [ $this, 'handle_action' ] );
		}

		/**
		 * Whether the notice should render in the current request.
		 *
		 * @since 3.0.0
		 *
		 * @return bool
		 */
		public function should_show() {
			if ( $this->insights->consent->is_set() ) {
				return false;
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				return false;
			}

			if ( ! empty( $this->screens ) ) {
				$screen = \function_exists( 'get_current_screen' ) ? get_current_screen() : null;

				if ( ! $screen || ! \in_array( $screen->id, $this->screens, true ) ) {
					return false;
				}
			}

			if ( null !== $this->show_callback && ! \call_user_func( $this->show_callback ) ) {
				return false;
			}

			return true;
		}

		/**
		 * `admin_notices` callback.
		 *
		 * @since 3.0.0
		 *
		 * @return void
		 */
		public function render() {
			if ( ! $this->should_show() ) {
				return;
			}

			$name = esc_html( $this->insights->get_name() );
			$text = '' !== $this->text ? $this->text : self::DEFAULT_TEXT;

			echo '<div class="notice notice-info wprepo-insights-notice"><p>';
			// str_replace, not sprintf: a custom text with a stray "%" must
			// not throw ArgumentCountError on PHP 8.
			echo wp_kses_post( \str_replace( [ '%1$s', '%s' ], $name, $text ) );
			echo '</p><details><summary>What we collect</summary><ul style="list-style:disc;padding-left:1.5em;">';

			foreach ( $this->get_collected_items() as $item ) {
				echo '<li>' . esc_html( $item ) . '</li>';
			}

			echo '</ul>';

			if ( $this->insights->privacy_url ) {
				echo '<p><a href="' . esc_url( $this->insights->privacy_url ) . '" target="_blank" rel="noopener noreferrer">Learn more</a> about how this data is collected and handled.</p>';
			}

			echo '</details><p>';
			echo '<a href="' . esc_url( $this->get_action_url( 'allow' ) ) . '" class="button button-primary">Allow</a> ';
			echo '<a href="' . esc_url( $this->get_action_url( 'decline' ) ) . '" class="button button-secondary">No thanks</a>';
			echo '</p></div>';
		}

		/**
		 * What the notice says is collected: one line per group of the
		 * Collector payload, a line for the plugin's own statistics when it
		 * sends `meta`, then the configured extra `items`. Plain text; the
		 * renderer escapes it.
		 *
		 * Keep this in step with Collector::collect() — the notice is the
		 * consent, so it must never promise less than is sent.
		 *
		 * @since 3.0.0
		 *
		 * @return string[]
		 */
		public function get_collected_items() {
			$items = [
				'Site name, URL and language, whether it is a multisite, and whether it looks like a local development site',
				"Your site's admin email address and administrator name",
				'WordPress version, memory limit and debug mode',
				'Active theme (name, version and parent theme)',
				'Server environment details (PHP and MySQL versions, server software, PHP memory, execution time and upload limits)',
				'Number of users on your site, by role',
				'Number of active and inactive plugins, and the names and versions of active plugins',
			];

			$collector = $this->insights->collector;

			if ( ! empty( $collector->meta ) || null !== $collector->meta_callback ) {
				$items[] = \sprintf( 'Usage statistics specific to %s', $this->insights->get_name() );
			}

			return \array_merge( $items, $this->items );
		}

		/**
		 * Whether a configured extra item is usable: a non-empty string.
		 *
		 * @since 3.0.0
		 *
		 * @param mixed $item Candidate item.
		 * @return bool
		 */
		public static function is_valid_item( $item ) {
			return \is_string( $item ) && '' !== \trim( $item );
		}

		/**
		 * Nonce action for a choice.
		 *
		 * @since 3.0.0
		 *
		 * @param string $action `allow` or `decline`.
		 * @return string
		 */
		public function get_nonce_action( $action ) {
			return "wprepo_insights_{$this->insights->slug}_{$action}";
		}

		/**
		 * Nonce'd link for a choice, on the current admin URL.
		 *
		 * @since 3.0.0
		 *
		 * @param string $action `allow` or `decline`.
		 * @return string
		 */
		public function get_action_url( $action ) {
			return wp_nonce_url(
				add_query_arg(
					[
						self::QUERY_SLUG   => $this->insights->slug,
						self::QUERY_ACTION => $action,
					]
				),
				$this->get_nonce_action( $action )
			);
		}

		/**
		 * `admin_init` callback handling Allow / No thanks.
		 *
		 * Scoped to this plugin by slug, so several plugins embedding Insights
		 * never handle each other's clicks. Invalid requests return silently.
		 *
		 * @since 3.0.0
		 *
		 * @return void
		 */
		public function handle_action() {
			if ( wp_doing_ajax() ) {
				return;
			}

			if ( ! isset( $_GET[ self::QUERY_SLUG ], $_GET[ self::QUERY_ACTION ], $_GET['_wpnonce'] ) ) {
				return;
			}

			if ( sanitize_key( wp_unslash( $_GET[ self::QUERY_SLUG ] ) ) !== $this->insights->slug ) {
				return;
			}

			$action = sanitize_key( wp_unslash( $_GET[ self::QUERY_ACTION ] ) );

			if ( ! \in_array( $action, self::ACTIONS, true ) ) {
				return;
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$nonce = sanitize_key( wp_unslash( $_GET['_wpnonce'] ) );

			if ( ! wp_verify_nonce( $nonce, $this->get_nonce_action( $action ) ) ) {
				return;
			}

			if ( 'allow' === $action ) {
				$this->insights->opt_in();
			} else {
				$this->insights->opt_out();
			}

			$this->redirect( remove_query_arg( [ self::QUERY_SLUG, self::QUERY_ACTION, '_wpnonce' ] ) );
		}

		/**
		 * Redirects and terminates. Split out so tests can intercept the exit.
		 *
		 * @since 3.0.0
		 *
		 * @param string $url Destination.
		 * @return void
		 */
		protected function redirect( $url ) {
			wp_safe_redirect( $url );
			exit;
		}
	}

endif;
