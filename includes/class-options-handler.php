<?php
/**
 * Handles WP Admin settings pages and the like.
 *
 * @package Share_On_Pixelfed
 */

namespace Share_On_Pixelfed;

/**
 * Options handler class.
 */
class Options_Handler {
	/**
	 * Share on Pixelfed's default options.
	 *
	 * @since 0.7.0
	 *
	 * @var array Default plugin options.
	 */
	const DEFAULT_PLUGIN_OPTIONS = array(
		'pixelfed_host'          => '',
		'pixelfed_client_id'     => '',
		'pixelfed_client_secret' => '',
		'pixelfed_access_token'  => '',
		'pixelfed_refresh_token' => '',
		'pixelfed_token_expiry'  => '',
		'post_types'             => array(),
		'use_first_image'        => false,
		'pixelfed_username'      => '',
		'micropub_compat'        => false,
		'syn_links_compat'       => false,
	);

	/**
	 * WordPress' default post types.
	 *
	 * @since 0.1.0
	 *
	 * @var array WordPress' default post types, minus 'post' itself.
	 */
	const DEFAULT_POST_TYPES = array(
		'page',
		'attachment',
	);

	/**
	 * Plugin options.
	 *
	 * @since 0.1.0
	 * @var   array $options Plugin options.
	 */
	private $options = array();

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 */
	public function __construct() {
		$this->options = get_option( 'share_on_pixelfed_settings', self::DEFAULT_PLUGIN_OPTIONS );
	}

	/**
	 * Registers hook callbacks.
	 *
	 * @since 0.4.0
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'create_menu' ) );
		add_action( 'share_on_pixelfed_refresh_token', array( $this, 'cron_refresh_token' ), 11 );
		add_action( 'share_on_pixelfed_refresh_token', array( $this, 'cron_verify_token' ) );
		add_action( 'admin_post_share_on_pixelfed', array( $this, 'admin_post' ) );
	}

	/**
	 * Registers the plugin settings page.
	 *
	 * @since 0.1.0
	 */
	public function create_menu() {
		add_options_page(
			__( 'Share on Pixelfed', 'share-on-pixelfed' ),
			__( 'Share on Pixelfed', 'share-on-pixelfed' ),
			'manage_options',
			'share-on-pixelfed',
			array( $this, 'settings_page' )
		);
		add_action( 'admin_init', array( $this, 'add_settings' ) );
	}

	/**
	 * Registers the actual options.
	 *
	 * @since 0.1.0
	 */
	public function add_settings() {
		add_option( 'share_on_pixelfed_settings', self::DEFAULT_PLUGIN_OPTIONS );

		$active_tab = $this->get_active_tab();

		register_setting(
			'share-on-pixelfed-settings-group',
			'share_on_pixelfed_settings',
			array( 'sanitize_callback' => array( $this, "sanitize_{$active_tab}_settings" ) )
		);
	}

	/**
	 * Handles submitted "setup" options.
	 *
	 * @since 0.7.0
	 *
	 * @param  array $settings Settings as submitted through WP Admin.
	 * @return array           Options to be stored.
	 */
	public function sanitize_setup_settings( $settings ) {
		$this->options['post_types'] = array();

		if ( isset( $settings['post_types'] ) && is_array( $settings['post_types'] ) ) {
			// Post types considered valid.
			$supported_post_types = (array) apply_filters( 'share_on_pixelfed_post_types', get_post_types( array( 'public' => true ) ) );
			$supported_post_types = array_diff( $supported_post_types, self::DEFAULT_POST_TYPES );

			foreach ( $settings['post_types'] as $post_type ) {
				if ( in_array( $post_type, $supported_post_types, true ) ) {
					// Valid post type. Add to array.
					$this->options['post_types'][] = $post_type;
				}
			}
		}

		if ( isset( $settings['pixelfed_host'] ) ) {
			// Clean up and sanitize the user-submitted URL.
			$pixelfed_host = $this->clean_url( $settings['pixelfed_host'] );

			if ( '' === $pixelfed_host ) {
				// Removing the instance URL. Might be done to temporarily
				// disable crossposting. Let's not "revoke access" just yet.
				$this->options['pixelfed_host'] = '';
			} elseif ( wp_http_validate_url( $pixelfed_host ) ) {
				if ( $pixelfed_host !== $this->options['pixelfed_host'] ) {
					// Updated URL. Forget access token.
					$this->options['pixelfed_access_token']  = '';
					$this->options['pixelfed_refresh_token'] = '';
					$this->options['pixelfed_token_expiry']  = '';

					// Then, save the new URL.
					$this->options['pixelfed_host'] = esc_url_raw( $pixelfed_host );

					// Forget client ID and secret. A new client ID and
					// secret will be requested next time the page loads.
					$this->options['pixelfed_client_id']     = '';
					$this->options['pixelfed_client_secret'] = '';
				}
			} else {
				// Invalid URL. Display error message.
				add_settings_error(
					'share-on-pixelfed-pixelfed-host',
					'invalid-url',
					esc_html__( 'Please provide a valid URL.', 'share-on-pixelfed' )
				);
			}
		}

		// Updated settings.
		return $this->options;
	}


	/**
	 * Handles submitted "advanced" options.
	 *
	 * @since 0.7.0
	 *
	 * @param  array $settings Settings as submitted through WP Admin.
	 * @return array           Options to be stored.
	 */
	public function sanitize_advanced_settings( $settings ) {
		$this->options['use_first_image'] = false;

		if ( isset( $settings['use_first_image'] ) && '1' === $settings['use_first_image'] ) {
			$this->options['use_first_image'] = true;
		}

		$this->options['micropub_compat']  = isset( $settings['micropub_compat'] ) ? true : false;
		$this->options['syn_links_compat'] = isset( $settings['syn_links_compat'] ) ? true : false;

		// Updated settings.
		return $this->options;
	}

	/**
	 * Echoes the plugin options form. Handles the OAuth flow, too, for now.
	 *
	 * @since 0.1.0
	 */
	public function settings_page() {
		$active_tab = $this->get_active_tab();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Share on Pixelfed', 'share-on-pixelfed' ); ?></h1>

			<h2 class="nav-tab-wrapper">
				<a href="<?php echo esc_url( $this->get_options_url( 'setup' ) ); ?>" class="nav-tab <?php echo esc_attr( 'setup' === $active_tab ? 'nav-tab-active' : '' ); ?>"><?php esc_html_e( 'Setup', 'share-on-pixelfed' ); ?></a>
				<a href="<?php echo esc_url( $this->get_options_url( 'advanced' ) ); ?>" class="nav-tab <?php echo esc_attr( 'advanced' === $active_tab ? 'nav-tab-active' : '' ); ?>"><?php esc_html_e( 'Advanced', 'share-on-pixelfed' ); ?></a>
				<a href="<?php echo esc_url( $this->get_options_url( 'debug' ) ); ?>" class="nav-tab <?php echo esc_attr( 'debug' === $active_tab ? 'nav-tab-active' : '' ); ?>"><?php esc_html_e( 'Debugging', 'share-on-pixelfed' ); ?></a>
			</h2>

			<?php if ( 'setup' === $active_tab ) : ?>
				<form method="post" action="options.php" novalidate="novalidate">
					<?php
					// Print nonces and such.
					settings_fields( 'share-on-pixelfed-settings-group' );

					// Post types considered valid.
					$supported_post_types = (array) apply_filters( 'share_on_pixelfed_post_types', get_post_types( array( 'public' => true ) ) );
					$supported_post_types = array_diff( $supported_post_types, self::DEFAULT_POST_TYPES );
					?>
					<table class="form-table">
						<tr valign="top">
							<th scope="row"><label for="share_on_pixelfed_settings[pixelfed_host]"><?php esc_html_e( 'Instance', 'share-on-pixelfed' ); ?></label></th>
							<td><input type="url" id="share_on_pixelfed_settings[pixelfed_host]" name="share_on_pixelfed_settings[pixelfed_host]" style="min-width: 33%;" value="<?php echo esc_attr( $this->options['pixelfed_host'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Your Pixelfed instance&rsquo;s URL.', 'share-on-pixelfed' ); ?></p></td>
						</tr>
						<tr valign="top">
							<th scope="row"><?php esc_html_e( 'Supported Post Types', 'share-on-pixelfed' ); ?></th>
							<td><ul style="list-style: none; margin-top: 4px;">
								<?php
								foreach ( $supported_post_types as $post_type ) :
									$post_type = get_post_type_object( $post_type );
									?>
									<li><label><input type="checkbox" name="share_on_pixelfed_settings[post_types][]" value="<?php echo esc_attr( $post_type->name ); ?>" <?php checked( in_array( $post_type->name, $this->options['post_types'], true ) ); ?>><?php echo esc_html( $post_type->labels->singular_name ); ?></label></li>
									<?php
								endforeach;
								?>
							</ul>
							<p class="description"><?php esc_html_e( 'Post types for which sharing to Pixelfed is possible. (Sharing can still be disabled on a per-post basis.)', 'share-on-pixelfed' ); ?></p></td>
						</tr>
					</table>
					<p class="submit"><?php submit_button( __( 'Save Changes' ), 'primary', 'submit', false ); ?></p>
				</form>

				<h2><?php esc_html_e( 'Authorize Access', 'share-on-pixelfed' ); ?></h2>
				<?php
				if ( ! empty( $this->options['pixelfed_host'] ) ) {
					// A valid instance URL was set.
					if ( empty( $this->options['pixelfed_client_id'] ) || empty( $this->options['pixelfed_client_secret'] ) ) {
						// No app is currently registered. Let's try to fix that!
						$this->register_app();
					}

					if ( ! empty( $this->options['pixelfed_client_id'] ) && ! empty( $this->options['pixelfed_client_secret'] ) ) {
						// An app was successfully registered.
						if ( ! empty( $_GET['code'] ) && empty( $this->options['pixelfed_access_token'] ) ) {
							// Access token request. Skip if we've already got one.
							if ( $this->request_access_token( sanitize_text_field( wp_unslash( $_GET['code'] ) ) ) ) {
								?>
								<div class="notice notice-success is-dismissible">
									<p><?php esc_html_e( 'Access granted!', 'share-on-pixelfed' ); ?></p>
								</div>
								<?php
							}
						} elseif ( isset( $_GET['action'] ) && 'revoke' === $_GET['action'] && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'share-on-pixelfed-revoke' ) ) {
							// Forget token(s).
							$this->options['pixelfed_access_token']  = '';
							$this->options['pixelfed_refresh_token'] = '';
							$this->options['pixelfed_token_expiry']  = '';

							update_option( 'share_on_pixelfed_settings', $this->options );
						}

						if ( empty( $this->options['pixelfed_access_token'] ) ) {
							// No access token exists. Echo authorization link.
							$url = $this->options['pixelfed_host'] . '/oauth/authorize?' . http_build_query(
								array(
									'response_type' => 'code',
									'client_id'     => $this->options['pixelfed_client_id'],
									'client_secret' => $this->options['pixelfed_client_secret'],
									'redirect_uri'  => add_query_arg(
										array(
											'page' => 'share-on-pixelfed',
										),
										admin_url( 'options-general.php' )
									), // Redirect here after authorization.
									'scope'         => 'read write',
								)
							);
							?>
							<p><?php esc_html_e( 'Authorize WordPress to read and write to your Pixelfed timeline in order to enable crossposting.', 'share-on-pixelfed' ); ?></p>
							<p><?php printf( '<a href="%1$s" class="button">%2$s</a>', esc_url( $url ), esc_html__( 'Authorize Access', 'share-on-pixelfed' ) ); ?>
							<?php
						} else {
							// An access token exists.
							?>
							<p>
								<?php esc_html_e( 'You&rsquo;ve authorized WordPress to read and write to your Pixelfed timeline.', 'share-on-pixelfed' ); ?>
								<?php esc_html_e( 'Access tokens are refreshed automatically, but a manual refresh is possible, too.', 'share-on-pixelfed' ); ?>
							</p>
							<p>
								<?php
								printf(
									'<a href="%1$s" class="button" style="border-color: #a00; color: #a00;">%2$s</a>',
									esc_url(
										add_query_arg(
											array(
												'page'     => 'share-on-pixelfed',
												'action'   => 'revoke',
												'_wpnonce' => wp_create_nonce( 'share-on-pixelfed-revoke' ),
											),
											admin_url( 'options-general.php' )
										)
									),
									esc_html__( 'Forget access token', 'share-on-pixelfed' )
								);
								?>
								<?php
								// Using `admin-post.php` rather than check for a
								// `$_GET` param on the settings page, which may
								// trigger more than one refresh token request.
								printf(
									'<a href="%1$s" class="button" style="margin-left: 0.25em;">%2$s</a>',
									esc_url(
										add_query_arg(
											array(
												'action'   => 'share_on_pixelfed',
												'refresh-token' => 'true',
												'_wpnonce' => wp_create_nonce( 'share-on-pixelfed-refresh-token' ),
											),
											admin_url( 'admin-post.php' )
										)
									),
									esc_html__( 'Refresh Token', 'share-on-pixelfed' )
								);
								?>
							</p>
							<?php
						}
					} else {
						// Still couldn't register our app.
						?>
						<p><?php esc_html_e( 'Something went wrong contacting your Pixelfed instance. Please reload this page to try again.', 'share-on-pixelfed' ); ?></p>
						<?php
					}
				} else {
					// We can't do much without an instance URL.
					?>
					<p><?php esc_html_e( 'Please fill out and save your Pixelfed instance&rsquo;s URL first.', 'share-on-pixelfed' ); ?></p>
					<?php
				}
			endif;

			if ( 'advanced' === $active_tab ) :
				?>
				<form method="post" action="options.php">
					<?php
					// Print nonces and such.
					settings_fields( 'share-on-pixelfed-settings-group' );
					?>
					<table class="form-table">
						<tr valign="top">
							<th scope="row"><?php esc_html_e( 'Image Choice', 'share-on-pixelfed' ); ?></th>
							<td><ul style="list-style: none; margin-top: 4px;">
								<li><label><input type="radio" name="share_on_pixelfed_settings[use_first_image]" value="0" <?php checked( empty( $this->options['use_first_image'] ) ); ?>><?php esc_html_e( 'Featured', 'share-on-pixelfed' ); ?></label></li>
								<li><label><input type="radio" name="share_on_pixelfed_settings[use_first_image]" value="1" <?php checked( ! empty( $this->options['use_first_image'] ) ); ?>><?php esc_html_e( 'First', 'share-on-pixelfed' ); ?></label></li>
							</ul>
							<p class="description"><?php esc_html_e( 'Share either the post&rsquo;s Featured Image or the first image inside the post content. (Posts for which the chosen image type does not exist, will not be shared.)', 'share-on-pixelfed' ); ?></p></td>
						</tr>

						<?php if ( class_exists( 'Micropub_Endpoint' ) ) : ?>
							<tr valign="top">
								<th scope="row"><?php esc_html_e( 'Micropub', 'share-on-pixelfed' ); ?></th>
								<td><label><input type="checkbox" name="share_on_pixelfed_settings[micropub_compat]" value="1" <?php checked( ! empty( $this->options['micropub_compat'] ) ); ?> /> <?php esc_html_e( 'Add syndication target', 'share-on-pixelfed' ); ?></label>
								<p class="description"><?php esc_html_e( '(Experimental) Add &ldquo;Pixelfed&rdquo; as a Micropub syndication target.', 'share-on-pixelfed' ); ?></p></td>
							</tr>
						<?php endif; ?>

						<?php if ( function_exists( 'get_syndication_links' ) ) : ?>
							<tr valign="top">
								<th scope="row"><?php esc_html_e( 'Syndication Links', 'share-on-pixelfed' ); ?></th>
								<td><label><input type="checkbox" name="share_on_pixelfed_settings[syn_links_compat]" value="1" <?php checked( ! empty( $this->options['syn_links_compat'] ) ); ?> /> <?php esc_html_e( 'Add Pixelfed URLs to syndication links', 'share-on-pixelfed' ); ?></label>
								<p class="description"><?php esc_html_e( '(Experimental) Add Pixelfed URLs to Syndication Links&rsquo; list of syndication links.', 'share-on-pixelfed' ); ?></p></td>
							</tr>
						<?php endif; ?>

					</table>
					<p class="submit"><?php submit_button( __( 'Save Changes' ), 'primary', 'submit', false ); ?></p>
				</form>
				<?php
			endif;

			if ( 'debug' === $active_tab ) :
				?>
				<p style="margin: 1em 0 0.5em;"><?php esc_html_e( 'Just in case, below button lets you delete all of Share on Pixelfed&rsquo;s settings. Note: This in itself will not invalidate previously issued tokens!', 'share-on-pixelfed' ); ?></p>
				<p>
					<?php
					printf(
						'<a href="%1$s" class="button button-reset-settings" style="color: #a00; border-color: #a00;">%2$s</a>',
						esc_url(
							add_query_arg(
								array(
									'action'   => 'share_on_pixelfed',
									'reset'    => 'true',
									'_wpnonce' => wp_create_nonce( 'share-on-pixelfed-reset' ),
								),
								admin_url( 'admin-post.php' )
							)
						),
						esc_html__( 'Reset Settings', 'share-on-pixelfed' )
					);
					?>
				</p>
				<?php
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG && current_user_can( 'manage_options' ) ) {
					?>
					<p style="margin-top: 2em;"><?php esc_html_e( 'Below information is not meant to be shared with anyone but may help when troubleshooting issues.', 'share-on-pixelfed' ); ?></p>
					<p><textarea class="widefat" rows="5"><?php var_export( $this->options ); ?></textarea></p><?php // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export ?>
					<?php
				}
			endif;
			?>
		</div>
		<?php
	}

	/**
	 * Registers a new Pixelfed client.
	 *
	 * @since 0.1.0
	 */
	private function register_app() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Register a new app. Should only run once (per host)!
		$response = wp_remote_post(
			esc_url_raw( $this->options['pixelfed_host'] ) . '/api/v1/apps',
			array(
				'body' => array(
					'client_name'   => __( 'Share on Pixelfed' ),
					'redirect_uris' => add_query_arg(
						array(
							'page' => 'share-on-pixelfed',
						),
						admin_url(
							'options-general.php'
						)
					), // Allowed redirect URLs.
					'scopes'        => 'write:media write:statuses read:accounts read:statuses',
					'website'       => home_url(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( '[Share on Pixelfed] ' . print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r
			return;
		}

		$app = json_decode( $response['body'] );

		if ( isset( $app->client_id ) && isset( $app->client_secret ) ) {
			// After successfully registering the App, store its keys.
			$this->options['pixelfed_client_id']     = $app->client_id;
			$this->options['pixelfed_client_secret'] = $app->client_secret;

			update_option( 'share_on_pixelfed_settings', $this->options );
		} else {
			error_log( '[Share on Pixelfed] Could not register new client. ' . print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r
		}
	}

	/**
	 * Requests a new access token.
	 *
	 * @since 0.1.0
	 *
	 * @param string $code Authorization code.
	 */
	private function request_access_token( $code ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		// Request an access token.
		$response = wp_remote_post(
			esc_url_raw( $this->options['pixelfed_host'] ) . '/oauth/token',
			array(
				'body' => array(
					'client_id'     => $this->options['pixelfed_client_id'],
					'client_secret' => $this->options['pixelfed_client_secret'],
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'redirect_uri'  => add_query_arg(
						array(
							'page' => 'share-on-pixelfed',
						),
						admin_url( 'options-general.php' )
					), // Redirect here after authorization.
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( '[Share on Pixelfed] Authorization failed. ' . print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r
			return false;
		}

		$token = json_decode( $response['body'] );

		if ( isset( $token->access_token ) ) {
			// Success. Store access token.
			$this->options['pixelfed_access_token'] = $token->access_token;

			if ( isset( $token->refresh_token ) ) {
				$this->options['pixelfed_refresh_token'] = $token->refresh_token;
			}

			if ( isset( $token->expires_in ) ) {
				$this->options['pixelfed_token_expiry'] = time() + (int) $token->expires_in;
			}

			error_log( '[Share on Pixelfed] Authorization successful.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			update_option( 'share_on_pixelfed_settings', $this->options );

			$this->cron_verify_token(); // In order to get and store a username.
										// @todo: This function **might** delete
										// our token, we should take that into
										// account somehow.

			return true;
		} else {
			error_log( '[Share on Pixelfed] Authorization failed.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r
		}

		return false;
	}

	/**
	 * Requests a token refresh.
	 *
	 * @since 0.1.0
	 */
	private function refresh_access_token() {
		if ( ! current_user_can( 'manage_options' ) && ! wp_doing_cron() ) {
			return false;
		}

		// Request an access token.
		$response = wp_remote_post(
			esc_url_raw( $this->options['pixelfed_host'] ) . '/oauth/token',
			array(
				'body' => array(
					'client_id'     => $this->options['pixelfed_client_id'],
					'client_secret' => $this->options['pixelfed_client_secret'],
					'grant_type'    => 'refresh_token',
					'refresh_token' => $this->options['pixelfed_refresh_token'],
					'scope'         => 'read write',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( '[Share on Pixelfed] Token refresh failed. ' . print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r
			return false;
		}

		$token = json_decode( $response['body'] );

		if ( isset( $token->access_token ) ) {
			// Success. Store access token.
			$this->options['pixelfed_access_token'] = $token->access_token;

			if ( isset( $token->refresh_token ) ) {
				$this->options['pixelfed_refresh_token'] = $token->refresh_token;
			}

			if ( isset( $token->expires_in ) ) {
				$this->options['pixelfed_token_expiry'] = time() + (int) $token->expires_in;
			}

			error_log( '[Share on Pixelfed] Token refresh successful, or token not up for renewal, yet.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			update_option( 'share_on_pixelfed_settings', $this->options );

			return true;
		} else {
			error_log( '[Share on Pixelfed] ' . print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r
		}

		return false;
	}

	/**
	 * Requests an access token refresh before the current token expires.
	 *
	 * Normally runs once a day.
	 *
	 * @since 0.3.0
	 */
	public function cron_refresh_token() {
		if ( empty( $this->options['pixelfed_token_expiry'] ) ) {
			// No expiry date set.
			return;
		}

		if ( $this->options['pixelfed_token_expiry'] > time() + 2 * DAY_IN_SECONDS ) {
			// Token doesn't expire till two days from now.
			return;
		}

		$this->refresh_access_token();
	}

	/**
	 * Verifies Share on Pixelfed's token status.
	 *
	 * Normally runs once a day.
	 *
	 * @since 0.7.0
	 */
	public function cron_verify_token() {
		if ( empty( $this->options['pixelfed_host'] ) ) {
			return;
		}

		if ( empty( $this->options['pixelfed_access_token'] ) ) {
			return;
		}

		// Verify the current access token.
		$response = wp_remote_get(
			esc_url_raw( $this->options['pixelfed_host'] ) . '/api/v1/accounts/verify_credentials',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->options['pixelfed_access_token'],
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return;
		}

		if ( in_array( wp_remote_retrieve_response_code( $response ), array( 401, 403 ), true ) ) {
			// The current access token has somehow become invalid. Forget it.
			$this->options['pixelfed_access_token']  = '';
			$this->options['pixelfed_refresh_token'] = '';
			$this->options['pixelfed_token_expiry']  = '';
			update_option( 'share_on_pixelfed_settings', $this->options );
			return;
		}

		// Store username. Isn't actually used, yet, but may very well be in the
		// near future.
		$account = json_decode( $response['body'] );

		if ( isset( $account->username ) ) {
			if ( empty( $this->options['pixelfed_username'] ) || $account->username !== $this->options['pixelfed_username'] ) {
				$this->options['pixelfed_username'] = $account->username;
				update_option( 'share_on_pixelfed_settings', $this->options );
			}
		} else {
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}

	/**
	 * Resets all plugin options.
	 *
	 * @since 0.5.1
	 */
	private function reset_options() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		$this->options = self::DEFAULT_PLUGIN_OPTIONS;

		update_option( 'share_on_pixelfed_settings', $this->options );
	}

	/**
	 * `admin-post.php` callback.
	 *
	 * @since 0.5.1
	 */
	public function admin_post() {
		if ( isset( $_GET['refresh-token'] ) && 'true' === $_GET['refresh-token'] && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'share-on-pixelfed-refresh-token' ) ) {
			// Token refresh request.
			error_log( '[Share on Pixelfed] ' . __( 'Attempting to refresh access token.', 'share-on-pixelfed' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			$this->refresh_access_token();
		} elseif ( isset( $_GET['reset'] ) && 'true' === $_GET['reset'] && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'share-on-pixelfed-reset' ) ) {
			// Reset all of this plugin's settings.
			error_log( '[Share on Pixelfed] ' . __( 'Clearing all plugin settings.', 'share-on-pixelfed' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			$this->reset_options();
		}

		wp_redirect( // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			esc_url_raw(
				add_query_arg(
					array(
						'page' => 'share-on-pixelfed',
					),
					remove_query_arg( array( 'refresh', 'reset', '_wpnonce' ), admin_url( 'options-general.php' ) )
				)
			)
		);
		exit;
	}

	/**
	 * Returns the plugin options.
	 *
	 * @since 0.2.0
	 *
	 * @return array Plugin options.
	 */
	public function get_options() {
		return $this->options;
	}

	/**
	 * Preps user-submitted instance URLs for validation.
	 *
	 * @since 0.7.0
	 *
	 * @param  string $url Input URL.
	 * @return string      Sanitized URL, or an empty string on failure.
	 */
	protected function clean_url( $url ) {
		$url = untrailingslashit( trim( $url ) );

		// So, it looks like `wp_parse_url()` always expects a protocol.
		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'https:' . $url;
		}

		if ( 0 !== strpos( $url, 'https://' ) && 0 !== strpos( $url, 'http://' ) ) {
			$url = 'https://' . $url;
		}

		// Take apart, then reassemble the URL, and drop anything (a path, query
		// string, etc.) beyond the host.
		$parsed_url = wp_parse_url( $url );

		if ( empty( $parsed_url['host'] ) ) {
			// Invalid URL.
			return '';
		}

		if ( ! empty( $parsed_url['scheme'] ) ) {
			$url = $parsed_url['scheme'] . ':';
		} else {
			$url = 'https:';
		}

		$url .= '//' . $parsed_url['host'];

		if ( ! empty( $parsed_url['port'] ) ) {
			$url .= ':' . $parsed_url['port'];
		}

		return sanitize_url( $url );
	}

	/**
	 * Returns this plugin's options URL with a `tab` query parameter.
	 *
	 * @since 0.7.0
	 *
	 * @param  string $tab Target tab.
	 * @return string      Options page URL.
	 */
	public function get_options_url( $tab = 'setup' ) {
		return add_query_arg(
			array(
				'page' => 'share-on-pixelfed',
				'tab'  => $tab,
			),
			admin_url( 'options-general.php' )
		);
	}

	/**
	 * Returns the active tab.
	 *
	 * @since 0.7.0
	 *
	 * @return string Active tab.
	 */
	private function get_active_tab() {
		if ( ! empty( $_POST['submit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$query_string = wp_parse_url( wp_get_referer(), PHP_URL_QUERY );

			if ( empty( $query_string ) ) {
				return 'setup';
			}

			parse_str( $query_string, $query_vars );

			if ( isset( $query_vars['tab'] ) && in_array( $query_vars['tab'], array( 'advanced', 'debug' ), true ) ) {
				return $query_vars['tab'];
			}

			return 'setup';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['tab'] ) && in_array( $_GET['tab'], array( 'advanced', 'debug' ), true ) ) {
			return $_GET['tab']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		return 'setup';
	}
}
