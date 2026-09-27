<?php
/**
 * Insights payload collector.
 *
 * @package Shazzad\PluginUpdater\V4
 * @version 4.0
 */

namespace Shazzad\PluginUpdater\V4\Insights;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\Collector' ) ) :

	/**
	 * Class Collector
	 *
	 * Builds the Insights payload: product, site, admin, WordPress, server,
	 * users, plugins, plus the plugin's own meta and — commercial only — the
	 * license key. Read-only: it never sends anything, so a plugin can call
	 * collect() to show the admin exactly what would be sent.
	 *
	 * @since 4.0.0
	 */
	class Collector {

		/**
		 * Maximum entries in `plugins.active`.
		 *
		 * @since 4.0.0
		 *
		 * @var int
		 */
		const MAX_PLUGINS = 200;

		/**
		 * Plugin basename (`dir/file.php`).
		 *
		 * @since 4.0.0
		 *
		 * @var string
		 */
		public $file;

		/**
		 * Consent state; supplies the mode and the token.
		 *
		 * @since 4.0.0
		 *
		 * @var Consent
		 */
		public $consent;

		/**
		 * Plugin status reported with the next payload as `plugin_status`
		 * (`active`/`inactive`).
		 * The Scheduler flips it to `inactive` on deactivation.
		 *
		 * @since 4.0.0
		 *
		 * @var string
		 */
		public $product_status = 'active';

		/**
		 * Static metadata. Closures and array-callables resolve at collect time.
		 *
		 * @since 4.0.0
		 *
		 * @var array
		 */
		public $meta = [];

		/**
		 * Callback returning a metadata array at collect time.
		 *
		 * @since 4.0.0
		 *
		 * @var callable|null
		 */
		public $meta_callback = null;

		/**
		 * Callback returning the license key. The key is sent only when this
		 * is set and returns a non-empty string (commercial plugins).
		 *
		 * @since 4.0.0
		 *
		 * @var callable|null
		 */
		public $license_callback = null;

		/**
		 * Constructor.
		 *
		 * @since 4.0.0
		 *
		 * @param string  $file    Plugin basename (`dir/file.php`).
		 * @param Consent $consent Consent state.
		 * @param array   $args {
		 *     Optional.
		 *
		 *     @type array    $meta             Static metadata.
		 *     @type callable $meta_callback    Returns a metadata array.
		 *     @type callable $license_callback Returns the license key.
		 * }
		 */
		public function __construct( $file, Consent $consent, array $args = [] ) {
			$this->file    = (string) $file;
			$this->consent = $consent;

			if ( isset( $args['meta'] ) && \is_array( $args['meta'] ) ) {
				$this->meta = $args['meta'];
			}

			if ( isset( $args['meta_callback'] ) && \is_callable( $args['meta_callback'] ) ) {
				$this->meta_callback = $args['meta_callback'];
			}

			if ( isset( $args['license_callback'] ) && \is_callable( $args['license_callback'] ) ) {
				$this->license_callback = $args['license_callback'];
			}
		}

		/**
		 * Builds the full payload.
		 *
		 * @since 4.0.0
		 *
		 * @param string $event `daily`, `activate`, `deactivate`, `optin` or `upgrade`.
		 * @return array
		 */
		public function collect( $event = 'daily' ) {
			$plugin = $this->get_plugin_header();

			// Only an install that may send gets a token written; a preview
			// before consent shows whatever exists (usually nothing).
			$token = $this->consent->is_granted() ? $this->consent->ensure_token() : $this->consent->get_token();

			$data = [
				'event'          => (string) $event,
				'mode'           => $this->consent->mode,
				'token'          => $token,
				'plugin_version' => $plugin['Version'],
				'plugin_status'  => $this->product_status,
				'site'           => $this->get_site_data(),
				'admin'          => $this->get_admin_data(),
				'wp'             => $this->get_wp_data(),
				'server'         => $this->get_server_data(),
				'users'          => $this->get_users_data(),
				'plugins'        => $this->get_plugins_data(),
			];

			$license = $this->get_license();

			if ( '' !== $license ) {
				$data['license'] = $license;
			}

			$meta = $this->get_meta();

			if ( ! empty( $meta ) ) {
				$data['meta'] = $meta;
			}

			return $data;
		}

		/**
		 * Reads the plugin header of this plugin fresh from disk, so an
		 * `upgrade` event reports the new version.
		 *
		 * @since 4.0.0
		 *
		 * @return array With at least `Name` and `Version`.
		 */
		public function get_plugin_header() {
			if ( ! \function_exists( 'get_plugin_data' ) ) {
				include_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $this->file, false, false );

			return [
				'Name'    => isset( $data['Name'] ) ? (string) $data['Name'] : '',
				'Version' => isset( $data['Version'] ) ? (string) $data['Version'] : '',
			];
		}

		/**
		 * The site URL as the repo server keys installs by.
		 *
		 * @since 4.0.0
		 *
		 * @return string
		 */
		public function get_site_url() {
			return esc_url_raw( site_url( '', 'https' ) );
		}

		/**
		 * Site block.
		 *
		 * @since 4.0.0
		 *
		 * @return array
		 */
		public function get_site_data() {
			return [
				'url'       => $this->get_site_url(),
				'name'      => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
				'locale'    => get_locale(),
				'is_local'  => $this->is_local_server(),
				'multisite' => (bool) is_multisite(),
			];
		}

		/**
		 * Admin block: `admin_email` option and the first administrator's
		 * display name (same rule as the existing ping).
		 *
		 * @since 4.0.0
		 *
		 * @return array
		 */
		public function get_admin_data() {
			$name   = '';
			$admins = get_users(
				[
					'role'    => 'administrator',
					'number'  => 1,
					'orderby' => 'ID',
					'order'   => 'ASC',
				]
			);

			if ( ! empty( $admins ) && isset( $admins[0]->display_name ) ) {
				$name = (string) $admins[0]->display_name;
			}

			return [
				'email' => (string) get_option( 'admin_email', '' ),
				'name'  => $name,
			];
		}

		/**
		 * WordPress block, including the active theme.
		 *
		 * @since 4.0.0
		 *
		 * @return array
		 */
		public function get_wp_data() {
			$stylesheet = (string) get_stylesheet();
			$template   = (string) get_template();
			$theme      = wp_get_theme();

			return [
				'version'      => (string) get_bloginfo( 'version' ),
				'memory_limit' => \defined( 'WP_MEMORY_LIMIT' ) ? (string) WP_MEMORY_LIMIT : '',
				'debug_mode'   => \defined( 'WP_DEBUG' ) && WP_DEBUG,
				'theme'        => [
					'slug'    => $stylesheet,
					'name'    => $theme ? (string) $theme->get( 'Name' ) : '',
					'version' => $theme ? (string) $theme->get( 'Version' ) : '',
					'parent'  => $template !== $stylesheet ? $template : '',
				],
			];
		}

		/**
		 * Server block.
		 *
		 * @since 4.0.0
		 *
		 * @return array
		 */
		public function get_server_data() {
			global $wpdb;

			return [
				'php_version'         => (string) phpversion(),
				'db_version'          => \is_object( $wpdb ) && \is_callable( [ $wpdb, 'db_server_info' ] ) ? (string) $wpdb->db_server_info() : '',
				'server_software'     => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
				'php_memory_limit'    => (string) ini_get( 'memory_limit' ),
				'max_execution_time'  => (int) ini_get( 'max_execution_time' ),
				'upload_max_filesize' => (string) ini_get( 'upload_max_filesize' ),
			];
		}

		/**
		 * Users block: total and per-role counts (roles with zero users left out).
		 *
		 * @since 4.0.0
		 *
		 * @return array
		 */
		public function get_users_data() {
			$counts  = count_users();
			$by_role = [];

			if ( ! empty( $counts['avail_roles'] ) && \is_array( $counts['avail_roles'] ) ) {
				foreach ( $counts['avail_roles'] as $role => $count ) {
					if ( $count ) {
						$by_role[ $role ] = (int) $count;
					}
				}
			}

			return [
				'total'   => isset( $counts['total_users'] ) ? (int) $counts['total_users'] : 0,
				'by_role' => $by_role,
			];
		}

		/**
		 * Plugins block: active/inactive counts and the active list, capped at
		 * MAX_PLUGINS entries. Network-activated plugins count as active.
		 *
		 * @since 4.0.0
		 *
		 * @return array
		 */
		public function get_plugins_data() {
			if ( ! \function_exists( 'get_plugins' ) ) {
				include_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$installed = get_plugins();
			$installed = \is_array( $installed ) ? $installed : [];

			$active_keys = (array) get_option( 'active_plugins', [] );

			if ( is_multisite() ) {
				$active_keys = \array_merge( $active_keys, \array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
			}

			$active_keys = \array_flip( $active_keys );
			$active      = [];
			$active_n    = 0;

			foreach ( $installed as $basename => $plugin ) {
				if ( ! isset( $active_keys[ $basename ] ) ) {
					continue;
				}

				++$active_n;

				if ( \count( $active ) >= self::MAX_PLUGINS ) {
					continue;
				}

				$active[] = [
					'slug'    => self::basename_to_slug( $basename ),
					'name'    => isset( $plugin['Name'] ) ? wp_strip_all_tags( $plugin['Name'] ) : '',
					'version' => isset( $plugin['Version'] ) ? wp_strip_all_tags( $plugin['Version'] ) : '',
				];
			}

			return [
				'active_count'   => $active_n,
				'inactive_count' => \count( $installed ) - $active_n,
				'active'         => $active,
			];
		}

		/**
		 * The plugin's own metadata: `meta_callback` first, then `meta` entries
		 * on top. Closures and array-callables in `meta` resolve now; plain
		 * strings stay data even when they name a function (same rule as the
		 * existing ping).
		 *
		 * @since 4.0.0
		 *
		 * @return array
		 */
		public function get_meta() {
			$meta = [];

			if ( \is_callable( $this->meta_callback ) ) {
				$callback_meta = \call_user_func( $this->meta_callback );

				if ( \is_array( $callback_meta ) ) {
					$meta = $callback_meta;
				}
			}

			foreach ( $this->meta as $key => $value ) {
				if ( $value instanceof \Closure || ( \is_array( $value ) && \is_callable( $value ) ) ) {
					$value = \call_user_func( $value );
				}

				$meta[ $key ] = $value;
			}

			return $meta;
		}

		/**
		 * The license key, or '' when there is none to send.
		 *
		 * @since 4.0.0
		 *
		 * @return string
		 */
		public function get_license() {
			if ( ! \is_callable( $this->license_callback ) ) {
				return '';
			}

			$license = \call_user_func( $this->license_callback );

			return \is_string( $license ) ? \trim( $license ) : '';
		}

		/**
		 * Whether this looks like a local/development install.
		 *
		 * Same heuristic as Appsero's, but the host comes from the site URL
		 * rather than HTTP_HOST: the daily track runs in cron, where there is
		 * no request host and Appsero's fallback would call every site local.
		 * Filterable through `wprepo_insights_is_local`.
		 *
		 * @since 4.0.0
		 *
		 * @return bool
		 */
		public function is_local_server() {
			$host = (string) wp_parse_url( site_url(), PHP_URL_HOST );
			$ip   = isset( $_SERVER['SERVER_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) ) : '';

			$is_local = \in_array( $ip, [ '127.0.0.1', '::1' ], true )
				|| '' === $host
				|| false === \strpos( $host, '.' )
				|| \in_array( \strrchr( $host, '.' ), [ '.test', '.testing', '.local', '.localhost', '.localdomain', '.example', '.invalid' ], true );

			return (bool) apply_filters( 'wprepo_insights_is_local', $is_local, $this->file );
		}

		/**
		 * Plugin slug from a basename: the directory, or the file name without
		 * `.php` for a single-file plugin.
		 *
		 * @since 4.0.0
		 *
		 * @param string $basename Plugin basename.
		 * @return string
		 */
		public static function basename_to_slug( $basename ) {
			$dir = \dirname( (string) $basename );

			if ( '.' === $dir || '' === $dir ) {
				return \basename( (string) $basename, '.php' );
			}

			return $dir;
		}
	}

endif;
