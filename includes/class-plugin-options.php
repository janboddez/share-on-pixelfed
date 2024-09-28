<?php
/**
 * @package Share_On_Pixelfed
 */

namespace Share_On_Pixelfed;

/**
 * Handles settings page.
 */
class Plugin_Options extends Options_Handler {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$options = get_option( 'share_on_pixelfed_settings' );

		$this->options = array_merge(
			static::get_default_options(),
			is_array( $options )
				? $options
				: array()
		);
	}

	/**
	 * Registers hook callbacks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'create_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		add_action( 'admin_post_share_on_pixelfed', array( $this, 'reset_settings' ) );

		add_action( 'share_on_pixelfed_refresh_token', array( $this, 'cron_verify_token' ) );
		add_action( 'share_on_pixelfed_refresh_token', array( $this, 'cron_refresh_token' ), 11 );
	}

	/**
	 * Registers the plugin settings page.
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
	 */
	public function add_settings() {
		add_option( 'share_on_pixelfed_settings', $this->options );

		// @todo: Move to `sanitize_settings()`?
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
	 * @param  array $settings Submitted settings.
	 * @return array           (Sanitized) options to be stored.
	 */
	public function sanitize_setup_settings( $settings ) {
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
					$this->options['pixelfed_token_expiry']  = 0;

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
	 * Handles submitted "post type" options.
	 *
	 * @param  array $settings Submitted settings.
	 * @return array           (Sanitized) options to be stored.
	 */
	public function sanitize_post_types_settings( $settings ) {
		$this->options['post_types'] = array();

		if ( isset( $settings['post_types'] ) && is_array( $settings['post_types'] ) ) {
			// Post types considered valid.
			$supported_post_types = (array) apply_filters( 'share_on_pixelfed_post_types', get_post_types( array( 'public' => true ) ) );
			$supported_post_types = array_diff( $supported_post_types, array( 'attachment' ) );

			foreach ( $settings['post_types'] as $post_type ) {
				if ( in_array( $post_type, $supported_post_types, true ) ) {
					// Valid post type. Add to array.
					$this->options['post_types'][] = $post_type;
				}
			}
		}

		// Updated settings.
		return $this->options;
	}

	/**
	 * Handles submitted "advanced" options.
	 *
	 * @param  array $settings Submitted settings.
	 * @return array           (Sanitized) options to be stored.
	 */
	public function sanitize_advanced_settings( $settings ) {
		$options = array(
			'use_first_image'     => isset( $settings['use_first_image'] ) && '1' === $settings['use_first_image'] ? true : false,
			'optin'               => isset( $settings['optin'] ) ? true : false,
			'share_always'        => isset( $settings['share_always'] ) ? true : false,
			'delay_sharing'       => isset( $settings['delay_sharing'] ) && ctype_digit( $settings['delay_sharing'] )
				? (int) $settings['delay_sharing']
				: 0,
			'micropub_compat'     => isset( $settings['micropub_compat'] ) ? true : false,
			'syn_links_compat'    => isset( $settings['syn_links_compat'] ) ? true : false,
			'custom_status_field' => isset( $settings['custom_status_field'] ) ? true : false,
			'status_template'     => isset( $settings['status_template'] ) && is_string( $settings['status_template'] )
				? preg_replace( '~\R~u', "\r\n", sanitize_textarea_field( $settings['status_template'] ) )
				: '',
			'meta_box'            => isset( $settings['meta_box'] ) ? true : false,
		);

		// Updated settings.
		return array_merge( $this->options, $options );
	}

	/**
	 * Handles submitted "debugging" options.
	 *
	 * @param  array $settings Submitted settings.
	 * @return array           (Sanitized) options to be stored.
	 */
	public function sanitize_debug_settings( $settings ) {
		$options = array(
			'debug_logging' => isset( $settings['debug_logging'] ) ? true : false,
		);

		// Updated settings.
		return array_merge( $this->options, $options );
	}

	/**
	 * Echoes the plugin options form. Handles the OAuth flow, too, for now.
	 */
	public function settings_page() {
		$active_tab = $this->get_active_tab();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Share on Pixelfed', 'share-on-pixelfed' ); ?></h1>

			<h2 class="nav-tab-wrapper">
				<a href="<?php echo esc_url( $this->get_options_url( 'setup' ) ); ?>" class="nav-tab <?php echo esc_attr( 'setup' === $active_tab ? 'nav-tab-active' : '' ); ?>"><?php esc_html_e( 'Setup', 'share-on-pixelfed' ); ?></a>
				<a href="<?php echo esc_url( $this->get_options_url( 'post_types' ) ); ?>" class="nav-tab <?php echo esc_attr( 'post_types' === $active_tab ? 'nav-tab-active' : '' ); ?>"><?php esc_html_e( 'Post Types', 'share-on-pixelfed' ); ?></a>				<a href="<?php echo esc_url( $this->get_options_url( 'advanced' ) ); ?>" class="nav-tab <?php echo esc_attr( 'advanced' === $active_tab ? 'nav-tab-active' : '' ); ?>"><?php esc_html_e( 'Advanced', 'share-on-pixelfed' ); ?></a>
				<a href="<?php echo esc_url( $this->get_options_url( 'debug' ) ); ?>" class="nav-tab <?php echo esc_attr( 'debug' === $active_tab ? 'nav-tab-active' : '' ); ?>"><?php esc_html_e( 'Debugging', 'share-on-pixelfed' ); ?></a>
			</h2>

			<?php if ( 'setup' === $active_tab ) : ?>
				<form method="post" action="options.php" novalidate="novalidate">
					<?php
					// Print nonces and such.
					settings_fields( 'share-on-pixelfed-settings-group' );
					?>
					<table class="form-table">
						<tr valign="top">
							<th scope="row"><label for="share_on_pixelfed_settings[pixelfed_host]"><?php esc_html_e( 'Instance', 'share-on-pixelfed' ); ?></label></th>
							<td><input type="url" id="share_on_pixelfed_settings[pixelfed_host]" name="share_on_pixelfed_settings[pixelfed_host]" style="min-width: 33%;" value="<?php echo esc_attr( $this->options['pixelfed_host'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Your Pixelfed instance&rsquo;s URL.', 'share-on-pixelfed' ); ?></p></td>
						</tr>
					</table>
					<p class="submit"><?php submit_button( __( 'Save Changes' ), 'primary', 'submit', false ); ?></p>
				</form>

				<h3><?php esc_html_e( 'Authorize Access', 'share-on-pixelfed' ); ?></h3>
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
							if ( $this->request_user_token( wp_unslash( $_GET['code'] ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
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
							$this->options['pixelfed_token_expiry']  = 0;

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

			if ( 'post_types' === $active_tab ) :
				?>
				<form method="post" action="options.php">
					<?php
					// Print nonces and such.
					settings_fields( 'share-on-pixelfed-settings-group' );
					// Post types considered valid.
					$supported_post_types = (array) apply_filters( 'share_on_pixelfed_post_types', get_post_types( array( 'public' => true ) ) );
					$supported_post_types = array_diff( $supported_post_types, array( 'attachment' ) );
					?>
					<table class="form-table">
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
				<?php
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
							<th scope="row"><label for="share_on_pixelfed_settings[delay_sharing]"><?php esc_html_e( 'Delayed Sharing', 'share-on-pixelfed' ); ?></label></th>
							<td><input type="number" style="width: 6em;" id="share_on_pixelfed_settings[delay_sharing]" name="share_on_pixelfed_settings[delay_sharing]" value="<?php echo esc_attr( isset( $this->options['delay_sharing'] ) ? $this->options['delay_sharing'] : 0 ); ?>" />
							<p class="description"><?php esc_html_e( 'The time, in seconds, WordPress should delay sharing after a post is first published. (Setting this to, e.g., &ldquo;300&rdquo;&mdash;that&rsquo;s 5 minutes&mdash;may resolve issues with image uploads.)', 'share-on-pixelfed' ); ?></p></td>
						</tr>
						<tr valign="top">
							<th scope="row"><?php esc_html_e( 'Image Choice', 'share-on-pixelfed' ); ?></th>
							<td><ul style="list-style: none; margin-top: 4px;">
								<li><label><input type="radio" name="share_on_pixelfed_settings[use_first_image]" value="0" <?php checked( empty( $this->options['use_first_image'] ) ); ?>><?php esc_html_e( 'Featured', 'share-on-pixelfed' ); ?></label></li>
								<li><label><input type="radio" name="share_on_pixelfed_settings[use_first_image]" value="1" <?php checked( ! empty( $this->options['use_first_image'] ) ); ?>><?php esc_html_e( 'First', 'share-on-pixelfed' ); ?></label></li>
							</ul>
							<p class="description"><?php esc_html_e( 'Share either the post&rsquo;s Featured Image or the first image inside the post content. (Posts for which the chosen image type does not exist, will not be shared.)', 'share-on-pixelfed' ); ?></p></td>
						</tr>
						<tr valign="top">
							<th scope="row"><?php esc_html_e( 'Opt-In', 'share-on-pixelfed' ); ?></th>
							<td><label><input type="checkbox" name="share_on_pixelfed_settings[optin]" value="1" <?php checked( ! empty( $this->options['optin'] ) ); ?> /> <?php esc_html_e( 'Make sharing opt-in rather than opt-out', 'share-on-pixelfed' ); ?></label></td>
						</tr>
						<tr valign="top">
							<th scope="row"><?php esc_html_e( 'Share Always', 'share-on-pixelfed' ); ?></th>
							<td><label><input type="checkbox" name="share_on_pixelfed_settings[share_always]" value="1" <?php checked( ! empty( $this->options['share_always'] ) ); ?> /> <?php esc_html_e( 'Always syndicate to Pixelfed', 'share-on-pixelfed' ); ?></label>
							<?php /* translators: %s: link to the `share_on_pixelfed_enabled` documentation */ ?>
							<p class="description"><?php printf( esc_html__( ' &ldquo;Force&rdquo; syndication, like when posting from a mobile app. For more fine-grained control, have a look at the %s filter hook.', 'share-on-pixelfed' ), '<a target="_blank" href="https://jan.boddez.net/wordpress/share-on-pixelfed#share_on_pixelfed_enabled"><code>share_on_pixelfed_enabled</code></a>' ); ?></p></td>
						</tr>
						<tr valign="top">
							<th scope="row"><label for="share_on_pixelfed_status_template"><?php esc_html_e( 'Status Template', 'share-on-pixelfed' ); ?></label></th>
							<td><textarea name="share_on_pixelfed_settings[status_template]" id="share_on_pixelfed_status_template" rows="5" style="min-width: 33%;"><?php echo ! empty( $this->options['status_template'] ) ? esc_html( $this->options['status_template'] ) : ''; ?></textarea>
							<?php /* translators: %s: supported template tags */ ?>
							<p class="description"><?php printf( esc_html__( 'Customize the default status template. Supported &ldquo;template tags&rdquo;: %s.', 'share-on-pixelfed' ), '<code>%title%</code>, <code>%excerpt%</code>, <code>%tags%</code>, <code>%permalink%</code>' ); ?></p></td>
						</tr>
						<tr valign="top">
							<th scope="row"><?php esc_html_e( 'Customize Status', 'share-on-pixelfed' ); ?></th>
							<td><label><input type="checkbox" name="share_on_pixelfed_settings[custom_status_field]" value="1" <?php checked( ! empty( $this->options['custom_status_field'] ) ); ?> /> <?php esc_html_e( 'Allow customizing Pixelfed statuses', 'share-on-pixelfed' ); ?></label>
								<?php /* translators: %s: link to the `share_on_pixelfed_status` documentation */ ?>
							<p class="description"><?php printf( esc_html__( 'Add a custom &ldquo;Message&rdquo; field to Share on Pixelfed&rsquo;s &ldquo;meta box.&rdquo; (For more fine-grained control, please have a look at the %s filter instead.)', 'share-on-pixelfed' ), '<a href="https://jan.boddez.net/wordpress/share-on-pixelfed#share_on_pixelfed_status" target="_blank" rel="noopener noreferrer"><code>share_on_pixelfed_status</code></a>' ); ?></p></td>
						</tr>

						<tr valign="top">
							<th scope="row"><?php esc_html_e( 'Meta Box', 'share-on-pixelfed' ); ?></th>
							<td><label><input type="checkbox" name="share_on_pixelfed_settings[meta_box]" value="1" <?php checked( ! empty( $this->options['meta_box'] ) ); ?> /> <?php esc_html_e( 'Use &ldquo;classic&rdquo; meta box', 'share-on-pixelfed' ); ?></label>
							<p class="description"><?php esc_html_e( 'Replace Share on Pixelfed&rsquo;s &ldquo;block editor sidebar panel&rdquo; with a &ldquo;classic&rdquo; meta box (even for post types that use the block editor).', 'share-on-pixelfed' ); ?></p></td>
						</tr>

						<?php if ( class_exists( 'Micropub_Endpoint' ) ) : ?>
							<tr valign="top">
								<th scope="row"><?php esc_html_e( 'Micropub', 'share-on-pixelfed' ); ?></th>
								<td><label><input type="checkbox" name="share_on_pixelfed_settings[micropub_compat]" value="1" <?php checked( ! empty( $this->options['micropub_compat'] ) ); ?> /> <?php esc_html_e( 'Add syndication target', 'share-on-pixelfed' ); ?></label>
								<p class="description"><?php esc_html_e( 'Add &ldquo;Pixelfed&rdquo; as a Micropub syndication target.', 'share-on-pixelfed' ); ?></p></td>
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
				<form method="post" action="options.php">
					<?php
					// Print nonces and such.
					settings_fields( 'share-on-pixelfed-settings-group' );
					?>
					<table class="form-table">
						<tr valign="top">
							<th scope="row"><label for="share_on_pixelfed_settings[debug_logging]"><?php esc_html_e( 'Logging', 'share-on-pixelfed' ); ?></label></th>
							<td><label><input type="checkbox" name="share_on_pixelfed_settings[debug_logging]" value="1" <?php checked( ! empty( $this->options['debug_logging'] ) ); ?> /> <?php esc_html_e( 'Enable debug logging', 'share-on-pixelfed' ); ?></label>
							<?php /* translators: %s: link to the official WordPress documentation */ ?>
							<p class="description"><?php printf( esc_html__( 'You&rsquo;ll also need to set WordPress&rsquo; %s.', 'share-on-pixelfed' ), sprintf( '<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>', 'https://wordpress.org/documentation/article/debugging-in-wordpress/#example-wp-config-php-for-debugging', esc_html__( 'debug logging constants', 'share-on-pixelfed' ) ) ); ?></p></td>
						</tr>
					</table>
					<p class="submit"><?php submit_button( __( 'Save Changes' ), 'primary', 'submit', false ); ?></p>
				</form>

				<p style="margin: 1em 0 0.5em;"><?php esc_html_e( 'Just in case, below button lets you delete all of Share on Pixelfed&rsquo;s settings. Note: This in itself will not invalidate previously issued tokens! (You can, however, still invalidate them on your instance&rsquo;s &ldquo;Profile > Settings > Applications&rdquo; page.)', 'share-on-pixelfed' ); ?></p>
				<p>
					<?php
					printf(
						'<a href="%1$s" class="button button-reset-settings" style="color: #a00; border-color: #a00;">%2$s</a>',
						esc_url(
							add_query_arg(
								array(
									'action'   => 'share_on_pixelfed',
									'reset'    => 'true',
									'_wpnonce' => wp_create_nonce( 'share-on-pixelfed:settings:reset' ),
								),
								admin_url( 'admin-post.php' )
							)
						),
						esc_html__( 'Reset Settings', 'share-on-pixelfed' )
					);
					?>
				</p>
				<?php
			endif;
			?>
		</div>
		<?php
	}

	/**
	 * Loads (admin) scripts.
	 *
	 * @since 0.7.0
	 *
	 * @param string $hook_suffix Current WP-Admin page.
	 */
	public function enqueue_scripts( $hook_suffix ) {
		if ( 'settings_page_share-on-pixelfed' !== $hook_suffix ) {
			return;
		}

		// Enqueue JS.
		wp_enqueue_script( 'share-on-pixelfed', plugins_url( '/assets/share-on-pixelfed.js', __DIR__ ), array(), Share_On_Pixelfed::PLUGIN_VERSION, true );
		wp_localize_script(
			'share-on-pixelfed',
			'share_on_pixelfed_obj',
			array( 'message' => esc_attr__( 'Are you sure you want to reset all settings?', 'share-on-pixelfed' ) ) // Confirmation message.
		);
	}

	/**
	 * Resets all plugin settings.
	 */
	public function reset_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You have insufficient permissions to access this page.', 'share-on-pixelfed' ) );
		}

		if ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'share-on-pixelfed:settings:reset' ) ) {
			// Reset all plugin settings.
			$this->options = static::get_default_options();
			$this->save();
		}

		wp_safe_redirect( esc_url_raw( add_query_arg( array( 'page' => 'share-on-pixelfed' ), admin_url( 'options-general.php' ) ) ) );
		exit;
	}

	/**
	 * Returns this plugin's options URL with a `tab` query parameter.
	 *
	 * @param  string $tab Target tab.
	 * @return string      Options page URL.
	 */
	protected function get_options_url( $tab = 'setup' ) {
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

			if ( isset( $query_vars['tab'] ) && in_array( $query_vars['tab'], array( 'post_types', 'advanced', 'debug' ), true ) ) {
				return $query_vars['tab'];
			}

			return 'setup';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['tab'] ) && in_array( $_GET['tab'], array( 'post_types', 'advanced', 'debug' ), true ) ) {
			return $_GET['tab']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		return 'setup';
	}

	/**
	 * Requests a new user token.
	 *
	 * @param string $code Authorization code.
	 */
	protected function request_user_token( $code ) {
		// Redirect here after authorization.
		$redirect_uri = add_query_arg( array( 'page' => 'share-on-pixelfed' ), admin_url( 'options-general.php' ) );

		// Request an access token.
		$response = wp_safe_remote_post(
			esc_url_raw( $this->options['pixelfed_host'] . '/oauth/token' ),
			array(
				'body'                => array(
					'client_id'     => $this->options['pixelfed_client_id'],
					'client_secret' => $this->options['pixelfed_client_secret'],
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'redirect_uri'  => esc_url_raw( $redirect_uri ),
				),
				'timeout'             => 15,
				'limit_response_size' => 1048576,
			)
		);

		if ( is_wp_error( $response ) ) {
			debug_log( $response );
			return false;
		}

		$token = json_decode( $response['body'] );

		if ( isset( $token->access_token ) ) {
			// Success. Store access token.
			$this->options['pixelfed_access_token'] = $token->access_token;

			// Update in database.
			$this->save();

			// @todo: This function **might** delete our token, we should take that into account somehow.
			$this->cron_verify_token(); // In order to get and store a username.

			return true;
		}

		debug_log( $response );

		return false;
	}

	/**
	 * Writes the current settings to the database.
	 *
	 * @param int $user_id (Optional) user ID.
	 */
	protected function save( $user_id = 0 ) {
		update_option( 'share_on_pixelfed_settings', $this->options );
	}
}
