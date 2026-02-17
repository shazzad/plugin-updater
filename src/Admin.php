<?php
/**
 * WordPress Plugin Updater Admin.
 *
 * @package Shazzad\PluginUpdater
 * @version 1.0
 */
namespace Shazzad\PluginUpdater;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\Admin' ) ) :

	/**
	 * Class Admin
	 *
	 * Handles plugin update checks, license verification, and upgrade processes.
	 *
	 * @since 1.0
	 */
	class Admin {

		public Integration $integration;

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
				$this->integration->menu_label = sprintf( '%s Updates', $this->integration->product_name );
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
			$base_url = remove_query_arg( [ 'm' ] );

			if ( isset( $_POST['wprepo_update'] ) ) {
				check_admin_referer( 'wprepo_license_update' );
				if ( empty( $_POST['wprepo_license'] ) ) {
					delete_option( $this->integration->get_license_option() );
					$this->integration->clear_updates_transient();
					wp_redirect(
						add_query_arg(
							'message',
							urlencode( 'License deactivated' ),
							$base_url
						)
					);
				} else {
					$key      = sanitize_text_field( $_POST['wprepo_license'] );
					$response = $this->integration->api_request( 'check_license', $key );

					if ( is_wp_error( $response ) ) {
						$message = $response->get_error_message();
						wp_redirect(
							add_query_arg(
								'error',
								urlencode( $message ),
								$base_url
							)
						);
					} elseif ( ! empty( $response['license'] ) ) {
						update_option( $this->integration->get_license_option(), $key );
						update_option(
							$this->integration->license_name . '_data',
							$response['license']
						);

						$this->integration->refresh_updates_transient();
						wp_redirect(
							add_query_arg(
								'message',
								urlencode( 'License activated' ),
								$base_url
							)
						);
					} else {
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
					}
				}
				exit;
			}
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
					printf(
						__( '%s - Version: %s' ),
						$this->integration->product_name,
						$this->integration->product_version
					);
					?>
				</h2>

				<?php
				if ( ! empty( $_GET['message'] ) ) {
					printf(
						'<div class="updated fade"><p>%s</p></div>',
						esc_html( urldecode( $_GET['message'] ) )
					);
				} elseif ( ! empty( $_GET['error'] ) ) {
					printf(
						'<div class="error fade"><p>%s</p></div>',
						esc_html( urldecode( $_GET['error'] ) )
					);
				}
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
				$response = $this->integration->api_request( 'details' );

				if ( $this->integration->has_license_code() ) {
					if ( is_wp_error( $response ) ) {
						printf(
							'<div class="error" style="padding:10px 20px;">%s</div>',
							$response->get_error_message()
						);
					} else {
						$details = $response['details'];
						$output  = '';

						if (
							isset( $details['version'] ) &&
							version_compare( $details['version'], $this->integration->product_version, '>' )
						) {
							$output .= sprintf(
								'<h3>Upgrade available. New version %s</h3>',
								$details['version']
							);
							if ( ! empty( $details['changelog_new'] ) ) {
								$output .= sprintf(
									'<div><h4>Changelog:</h4>%s</div>',
									wpautop( $details['changelog_new'] )
								);
							}
							if ( ! empty( $details['upgrade_notice_new'] ) ) {
								$output .= sprintf(
									'<div><h4>Upgrade notice:</h4>%s</div>',
									wpautop( $details['upgrade_notice_new'] )
								);
							}
							if ( ! empty( $details['download_link'] ) ) {
								$update_url = wp_nonce_url(
									admin_url(
										'update.php?action=upgrade-plugin&plugin='
										. urlencode( $this->integration->product_file )
									),
									'upgrade-plugin_' . $this->integration->product_file
								);

								$output .= sprintf(
									'<div>
										<h4>Upgrade Now:</h4>
										<a class="button button-primary" href="%s">Upgrade Now</a>
									</div>',
									$update_url
								);
							} else {
								$license_data = get_option( $this->integration->license_name . '_data' );
								if (
									! empty( $license_data['status'] )
									&& 'expired' === $license_data['status']
								) {
									$output .= '<strong style="color:red;">Your license has expired. Please renew your license to get updates.</strong>';
								} elseif (
									! empty( $license_data['status'] )
									&& 'suspended' === $license_data['status']
								) {
									$output .= '<strong>Your license has been suspended. Please contact support.</strong>';
								} else {
									$output .= '<strong>Unable to upgrade. Please contact support.</strong>';
								}
							}
						} else {
							$license_data = get_option( $this->integration->license_name . '_data' );
							$output .= '<div>You are using the latest version of our plugin.</div>';

							if (
								! empty( $license_data['status'] )
								&& 'expired' === $license_data['status']
							) {
								$output .= '<p style="color:red;">Your license has expired. Please renew your license to get new updates.</p>';
							}
						}

						printf(
							'<div class="updated" style="padding:10px 20px;">%s</div>',
							$output
						);
					}
				} elseif ( is_wp_error( $response ) ) {
					printf(
						'<div class="error" style="padding:10px 20px;">%s</div>',
						$response->get_error_message()
					);
				} elseif ( ! empty( $response['details'] ) ) {
					// No license code set yet.
					$details = $response['details'];
					$output  = '';

					if (
						isset( $details['version'] ) &&
						version_compare( $details['version'], $this->integration->product_version, '>' )
					) {
						$output .= sprintf(
							'<h3>Upgrade available. New version %s</h3>',
							$details['version']
						);
						if ( ! empty( $details['changelog_new'] ) ) {
							$output .= sprintf(
								'<div><h4>Changelog:</h4>%s</div>',
								wpautop( $details['changelog_new'] )
							);
						}
						if ( ! empty( $details['upgrade_notice_new'] ) ) {
							$output .= sprintf(
								'<div><h4>Upgrade notice:</h4>%s</div>',
								wpautop( $details['upgrade_notice_new'] )
							);
						}
						$output .= '<strong>Please save your license to receive updates.</strong>';
					} else {
						$output .= '<div>You are using the latest version of our plugin.</div>';
					}

					printf(
						'<div class="updated" style="padding:10px 20px;">%s</div>',
						$output
					);
				}
				?>
			</div>
			<?php
		}
	}

endif;
