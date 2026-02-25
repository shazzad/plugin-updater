<?php
/**
 * WordPress Plugin Updater Admin.
 *
 * @package Shazzad\PluginUpdater
 * @version 1.0
 */
namespace Shazzad\PluginUpdater;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\Admin' ) ) :

	/**
	 * Class Admin
	 *
	 * Renders the license management admin page and handles license save/verify via POST.
	 *
	 * @since 1.0
	 */
	class Admin {

		/**
		 * Integration instance holding shared state and API helpers.
		 *
		 * @since 1.0
		 *
		 * @var Integration
		 */
		public Integration $integration;

		/**
		 * Constructor.
		 *
		 * @since 1.0
		 *
		 * @param Integration $integration Integration instance.
		 */
		public function __construct( Integration $integration ) {
			$this->integration = $integration;

			add_action( 'admin_menu', [ $this, 'admin_menu' ], $this->integration->menu_priority );
		}

		/**
		 * Add the license settings submenu page.
		 *
		 * @since 1.0
		 * @return void
		 */
		public function admin_menu() {
			if ( empty( $this->integration->menu_label ) ) {
				$this->integration->menu_label = \sprintf( '%s Updates', $this->integration->product_name );
			}

			if ( empty( $this->integration->menu_parent ) ) {
				$this->integration->menu_parent = 'plugins.php';
			}

			$page = add_submenu_page(
				$this->integration->menu_parent,
				$this->integration->menu_label,
				$this->integration->menu_label,
				'delete_users',
				$this->integration->license_name,
				[ $this, 'admin_page' ]
			);

			add_action( "load-{$page}", [ $this, 'load_page' ] );
		}

		/**
		 * Handle saving license and verifying it upon page load.
		 *
		 * @since 1.0
		 * @return void
		 */
		public function load_page() {
			if ( isset( $_POST['wprepo_sync'] ) ) {
				$this->handle_sync();
				return;
			}

			if ( ! isset( $_POST['wprepo_update'] ) ) {
				return;
			}

			$this->handle_save();
		}

		/**
		 * Handle syncing/refreshing the existing license data.
		 *
		 * @since 1.1
		 * @return void
		 */
		private function handle_sync() {
			check_admin_referer( 'wprepo_license_update' );

			$base_url = remove_query_arg( [ 'm' ] );
			$key      = $this->integration->get_license_code();

			if ( empty( $key ) ) {
				wp_redirect(
					add_query_arg(
						'error',
						urlencode( 'No license key to sync' ),
						$base_url
					)
				);
				exit;
			}

			$response = $this->integration->client->check_license( $key );

			if ( is_wp_error( $response ) ) {
				wp_redirect(
					add_query_arg(
						'error',
						urlencode( $response->get_error_message() ),
						$base_url
					)
				);
				exit;
			}

			if ( ! empty( $response['license'] ) ) {
				$this->integration->update_license_data( $response['license'] );
				$this->integration->refresh_updates_transient();
				wp_redirect(
					add_query_arg(
						'message',
						urlencode( 'License data synced' ),
						$base_url
					)
				);
				exit;
			}

			$message = ! empty( $response['message'] )
				? $response['message']
				: 'Unable to sync license data';
			wp_redirect(
				add_query_arg(
					'error',
					urlencode( $message ),
					$base_url
				)
			);
			exit;
		}

		/**
		 * Handle saving/updating the license key.
		 *
		 * @since 1.1
		 * @return void
		 */
		private function handle_save() {
			check_admin_referer( 'wprepo_license_update' );

			$base_url = remove_query_arg( [ 'm' ] );

			if ( empty( $_POST['wprepo_license'] ) ) {
				delete_option( $this->integration->get_license_code_key() );
				$this->integration->clear_updates_transient();
				wp_redirect(
					add_query_arg(
						'message',
						urlencode( 'License deactivated' ),
						$base_url
					)
				);
				exit;
			}

			$key      = sanitize_text_field( $_POST['wprepo_license'] );
			$response = $this->integration->client->check_license( $key );

			if ( is_wp_error( $response ) ) {
				wp_redirect(
					add_query_arg(
						'error',
						urlencode( $response->get_error_message() ),
						$base_url
					)
				);
				exit;
			}

			if ( ! empty( $response['license'] ) ) {
				update_option( $this->integration->get_license_code_key(), $key );
				$this->integration->update_license_data( $response['license'] );

				$this->integration->refresh_updates_transient();
				wp_redirect(
					add_query_arg(
						'message',
						urlencode( 'License activated' ),
						$base_url
					)
				);
				exit;
			}

			$message = ! empty( $response['message'] )
				? $response['message']
				: 'Invalid License Key';
			wp_redirect(
				add_query_arg(
					'error',
					urlencode( $message ),
					$base_url
				)
			);
			exit;
		}

		/**
		 * Renders the license settings page in the admin area.
		 *
		 * @since 1.0
		 * @return void
		 */
		public function admin_page() {
			?>
			<div class="wrap">
				<h2>
					<?php
					\printf(
						__( '%s - Version: %s' ),
						$this->integration->product_name,
						$this->integration->product_version
					);
					?>
				</h2>

				<?php
				$this->render_notices();
				?>

				<form method="post">
					<?php wp_nonce_field( 'wprepo_license_update' ); ?>
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row">
									<label for="wprepo_license">
										<?php _e( 'License Key' ); ?>
									</label>
								</th>
								<td>
									<input name="wprepo_license" type="text" id="wprepo_license"
										aria-describedby="wprepo_license-description"
										value="<?php echo esc_attr( $this->integration->get_license_code() ); ?>"
										class="regular-text" />
									<?php if ( $this->integration->has_license_code() ) : ?>
										<button type="submit" name="wprepo_sync" value="1"
											class="button button-secondary" title="<?php esc_attr_e( 'Sync license data' ); ?>"
											style="vertical-align: baseline;">
											<span class="dashicons dashicons-update" style="vertical-align: text-bottom;"></span>
											<?php _e( 'Sync' ); ?>
										</button>
									<?php endif; ?>
									<p class="description" id="wprepo_license-description">
										Enter your License Key to receive automatic Updates
									</p>
								</td>
							</tr>
						</tbody>
					</table>

					<p class="submit">
						<input type="submit" class="button button-primary button-large" name="wprepo_update" value="Save License" />
					</p>
				</form>

				<?php
				$response = $this->integration->client->details();

				if ( is_wp_error( $response ) ) {
					\printf(
						'<div class="error" style="padding:10px 20px;">%s</div>',
						$response->get_error_message()
					);
				} elseif ( $this->integration->has_license_code() && ! empty( $response['details'] ) ) {
					$this->render_details_with_license( $response['details'] );
				} elseif ( ! empty( $response['details'] ) ) {
					$this->render_details_without_license( $response['details'] );
				}
				?>
			</div>
			<?php
		}

		/**
		 * Render flash message or error notice from query parameters.
		 *
		 * @since 1.0
		 *
		 * @return void
		 */
		private function render_notices() {
			if ( ! empty( $_GET['message'] ) ) {
				\printf(
					'<div class="updated fade"><p>%s</p></div>',
					esc_html( urldecode( $_GET['message'] ) )
				);
			} elseif ( ! empty( $_GET['error'] ) ) {
				\printf(
					'<div class="error fade"><p>%s</p></div>',
					esc_html( urldecode( $_GET['error'] ) )
				);
			}
		}

		/**
		 * Render upgrade version heading, changelog, and upgrade notice.
		 *
		 * @since 1.0
		 *
		 * @param array $details Plugin details from API response.
		 * @return string HTML output.
		 */
		private function render_upgrade_info( $details ) {
			$output = \sprintf(
				'<h3>Upgrade available. New version %s</h3>',
				$details['version']
			);
			if ( ! empty( $details['changelog_new'] ) ) {
				$output .= \sprintf(
					'<div><h4>Changelog:</h4>%s</div>',
					wpautop( $details['changelog_new'] )
				);
			}
			if ( ! empty( $details['upgrade_notice_new'] ) ) {
				$output .= \sprintf(
					'<div><h4>Upgrade notice:</h4>%s</div>',
					wpautop( $details['upgrade_notice_new'] )
				);
			}

			return $output;
		}

		/**
		 * Render plugin details panel when a license code is present.
		 *
		 * @since 1.0
		 *
		 * @param array $details Plugin details from API response.
		 * @return void
		 */
		private function render_details_with_license( $details ) {
			$output = '';

			if (
				isset( $details['version'] ) &&
				version_compare( $details['version'], $this->integration->product_version, '>' )
			) {
				$output .= $this->render_upgrade_info( $details );

				if ( ! empty( $details['download_link'] ) ) {
					$update_url = wp_nonce_url(
						admin_url(
							'update.php?action=upgrade-plugin&plugin='
							. urlencode( $this->integration->product_file )
						),
						'upgrade-plugin_' . $this->integration->product_file
					);

					$output .= \sprintf(
						'<div>
							<h4>Upgrade Now:</h4>
							<a class="button button-primary" href="%s">Upgrade Now</a>
						</div>',
						$update_url
					);
				} else {
					if ( 'expired' === $this->integration->get_license_status() ) {
						$renewal_url = $this->integration->get_license_renewal_url();
						if ( $renewal_url ) {
							$output .= \sprintf(
								'<strong style="color:red;">Your license has expired. <a href="%s">Renew your license</a> to get updates.</strong>',
								esc_url( $renewal_url )
							);
						} else {
							$output .= '<strong style="color:red;">Your license has expired. Please renew your license to get updates.</strong>';
						}
					} elseif ( 'suspended' === $this->integration->get_license_status() ) {
						if ( ! empty( $details['homepage'] ) ) {
							$output .= \sprintf(
								'<strong>Your license has been suspended. Please <a href="%s">contact support</a>.</strong>',
								esc_url( $details['homepage'] )
							);
						} else {
							$output .= '<strong>Your license has been suspended. Please contact support.</strong>';
						}
					} else {
						if ( ! empty( $details['homepage'] ) ) {
							$output .= \sprintf(
								'<strong>Upgrade package file missing, unable to upgrade. Please <a href="%s">contact support</a>.</strong>',
								esc_url( $details['homepage'] )
							);
						} else {
							$output .= '<strong>Upgrade package file missing, unable to upgrade. Please contact support.</strong>';
						}
					}
				}
			} else {
				$output .= '<div>You are using the latest version of our plugin.</div>';

				if ( 'expired' === $this->integration->get_license_status() ) {
					$renewal_url = $this->integration->get_license_renewal_url();
					if ( $renewal_url ) {
						$output .= \sprintf(
							'<p style="color:red;">Your license has expired. <a href="%s">Renew your license</a> to get new updates.</p>',
							esc_url( $renewal_url )
						);
					} else {
						$output .= '<p style="color:red;">Your license has expired. Please renew your license to get new updates.</p>';
					}
				}
			}

			\printf(
				'<div class="updated" style="padding:10px 20px;">%s</div>',
				$output
			);
		}

		/**
		 * Render plugin details panel when no license code is set.
		 *
		 * @since 1.0
		 *
		 * @param array $details Plugin details from API response.
		 * @return void
		 */
		private function render_details_without_license( $details ) {
			$output = '';

			if (
				isset( $details['version'] ) &&
				version_compare( $details['version'], $this->integration->product_version, '>' )
			) {
				$output .= $this->render_upgrade_info( $details );
				$output .= '<strong>Please save your license to receive updates.</strong>';
			} else {
				$output .= '<div>You are using the latest version of our plugin.</div>';
			}

			\printf(
				'<div class="updated" style="padding:10px 20px;">%s</div>',
				$output
			);
		}
	}

endif;
