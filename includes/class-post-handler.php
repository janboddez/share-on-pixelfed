<?php
/**
 * Handles posting to Pixelfed and the like.
 *
 * @package Share_On_Pixelfed
 */

namespace Share_On_Pixelfed;

/**
 * Post handler class.
 */
class Post_Handler {
	/**
	 * Array that holds this plugin's settings.
	 *
	 * @since 0.1.0
	 *
	 * @var array $options Plugin options.
	 */
	private $options = array();

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param Options_Handler $options_handler `Options_Handler` instance.
	 */
	public function __construct( Options_Handler $options_handler = null ) {
		if ( null !== $options_handler ) {
			$this->options = $options_handler->get_options();
		}
	}

	/**
	 * Registers hook callbacks.
	 *
	 * @since 0.4.0
	 */
	public function register() {
		add_action( 'transition_post_status', array( $this, 'update_meta' ), 11, 3 );
		add_action( 'transition_post_status', array( $this, 'toot' ), 999, 3 );
		add_action( 'share_on_pixelfed_post', array( $this, 'post_to_pixelfed' ) );

		add_action( 'rest_api_init', array( $this, 'register_meta' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_share_on_pixelfed_unlink_url', array( $this, 'unlink_url' ) );
	}

	/**
	 * Register `_share_on_pixelfed_url` meta for use with the REST API.
	 *
	 * @since 0.7.0
	 */
	public function register_meta() {
		$post_types = (array) $this->options['post_types'];

		foreach ( $post_types as $post_type ) {
			register_post_meta(
				$post_type,
				'_share_on_pixelfed_url',
				array(
					'single'        => true,
					'show_in_rest'  => true,
					'type'          => 'string',
					'auth_callback' => '__return_true',
				)
			);
		}
	}

	/**
	 * Registers a new meta box.
	 *
	 * @since 0.1.0
	 */
	public function add_meta_box() {
		if ( empty( $this->options['post_types'] ) ) {
			// Sharing disabled for all post types.
			return;
		}

		add_meta_box(
			'share-on-pixelfed',
			__( 'Share on Pixelfed', 'share-on-pixelfed' ),
			array( $this, 'render_meta_box' ),
			(array) $this->options['post_types'],
			'side',
			'default'
		);
	}

	/**
	 * Renders custom fields meta boxes on the custom post type edit page.
	 *
	 * @since 0.1.0
	 * @param WP_Post $post Post being edited.
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( basename( __FILE__ ), 'share_on_pixelfed_nonce' );

		$enabled = ! empty( $this->options['optin'] );
		$check   = array( '', '1' );

		if ( apply_filters( 'share_on_pixelfed_optin', $enabled ) ) {
			$check = array( '1' ); // Make sharing opt-in.
		}
		?>
		<label>
			<input type="checkbox" name="share_on_pixelfed" value="1" <?php checked( in_array( get_post_meta( $post->ID, '_share_on_pixelfed', true ), $check, true ) ); ?>>
			<?php esc_html_e( 'Share on Pixelfed', 'share-on-pixelfed' ); ?>
		</label>
		<?php
		$url = get_post_meta( $post->ID, '_share_on_pixelfed_url', true );

		if ( '' !== $url && wp_http_validate_url( $url ) ) :
			$url_parts = wp_parse_url( $url );

			$display_url  = '<span class="screen-reader-text">' . $url_parts['scheme'] . '://';
			$display_url .= ( ! empty( $url_parts['user'] ) ? $url_parts['user'] . ( ! empty( $url_parts['pass'] ) ? ':' . $url_parts['pass'] : '' ) . '@' : '' ) . '</span>';
			$display_url .= '<span class="ellipsis">' . substr( $url_parts['host'] . $url_parts['path'], 0, 20 ) . '</span><span class="screen-reader-text">' . substr( $url_parts['host'] . $url_parts['path'], 20 ) . '</span>';
			?>
			<p class="description">
				<?php /* translators: toot URL */ ?>
				<?php printf( esc_html__( 'Shared at %s', 'share-on-pixelfed' ), '<a class="url" href="' . esc_url( $url ) . '">' . $display_url . '</a>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php /* translators: "unlink" link text */ ?>
				<a href="#" class="unlink"><?php esc_html_e( 'Unlink', 'share-on-pixelfed' ); ?></a>
			</p>
			<?php
		else :
			$error_message = get_post_meta( $post->ID, '_share_on_pixelfed_error', true );

			if ( '' !== $error_message ) :
				?>
				<p class="description"><i><?php echo esc_html( $error_message ); ?></i></p>
				<?php
			endif;
		endif;

		if ( apply_filters( 'share_on_pixelfed_custom_status_field', false ) ) :
			?>
			<div style="margin-top: 0.75em;"><details>
				<summary><label for="share_on_pixelfed_status"><?php esc_html_e( '(Optional) Message', 'share-on-pixelfed' ); ?></label></summary>
				<textarea id="share_on_pixelfed_status" name="share_on_pixelfed_status" rows="3" style="width: 100%; box-sizing: border-box; margin-top: 0.5em;"><?php echo esc_html( get_post_meta( $post->ID, '_share_on_pixelfed_status', true ) ); ?></textarea>
				<p class="description" style="margin-top: 0.25em;"><?php esc_html_e( 'Customize this post&rsquo;s Pixelfed status.', 'share-on-pixelfed' ); ?></p>
			</details></div>
			<?php
		endif;
	}

	/**
	 * Handles metadata.
	 *
	 * @since 0.1.0
	 *
	 * @param string  $new_status Old post status.
	 * @param string  $old_status New post status.
	 * @param WP_Post $post       Post object.
	 */
	public function update_meta( $new_status, $old_status, $post ) {
		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			// Prevent double posting.
			return;
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		if ( ! isset( $_POST['share_on_pixelfed_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['share_on_pixelfed_nonce'] ), basename( __FILE__ ) ) ) {
			// Nonce missing or invalid.
			return;
		}

		if ( ! in_array( $post->post_type, (array) $this->options['post_types'], true ) ) {
			// Unsupported post type.
			return;
		}

		if ( post_password_required( $post ) ) {
			return;
		}

		if ( isset( $_POST['share_on_pixelfed'] ) ) {
			// If sharing enabled.
			update_post_meta( $post->ID, '_share_on_pixelfed', '1' );
		} else {
			update_post_meta( $post->ID, '_share_on_pixelfed', '0' );
		}

		if ( apply_filters( 'share_on_pixelfed_custom_status_field', false ) && isset( $_POST['share_on_pixelfed_status'] ) ) {
			$status = sanitize_textarea_field( wp_unslash( $_POST['share_on_pixelfed_status'] ) );
		}

		if ( ! empty( $status ) ) {
			update_post_meta( $post->ID, '_share_on_pixelfed_status', $status );
		} else {
			delete_post_meta( $post->ID, '_share_on_pixelfed_status' );
		}
	}

	/**
	 * Shares a post on Pixelfed.
	 *
	 * @since 0.1.0
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post object.
	 */
	public function toot( $new_status, $old_status, $post ) {
		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			// Prevent accidental double posting.
			return;
		}

		$is_enabled = ( '1' === get_post_meta( $post->ID, '_share_on_pixelfed', true ) ? true : false );

		if ( ! empty( $this->options['share_always'] ) ) {
			$is_enabled = true;
		}

		if ( ! apply_filters( 'share_on_pixelfed_enabled', $is_enabled, $post ) ) {
			// Disabled for this post.
			return;
		}

		if ( '' !== get_post_meta( $post->ID, '_share_on_pixelfed_url', true ) ) {
			// Prevent duplicate statuses.
			return;
		}

		if ( 'publish' !== $new_status ) {
			// Status is something other than `publish`.
			return;
		}

		if ( post_password_required( $post ) ) {
			// Post is password-protected.
			return;
		}

		if ( ! in_array( $post->post_type, (array) $this->options['post_types'], true ) ) {
			// Unsupported post type.
			return;
		}

		if ( empty( $this->options['pixelfed_host'] ) ) {
			return;
		}

		if ( ! wp_http_validate_url( $this->options['pixelfed_host'] ) ) {
			return;
		}

		if ( empty( $this->options['pixelfed_access_token'] ) ) {
			return;
		}

		if ( ! empty( $this->options['delay_sharing'] ) ) {
			// Since version 0.7.0, there's an option to "schedule" sharing
			// rather than do everything inline.
			wp_schedule_single_event(
				time() + $this->options['delay_sharing'],
				'share_on_pixelfed_post',
				array( $post->ID )
			);
		} else {
			// Share immediately.
			$this->post_to_pixelfed( $post->ID );
		}
	}

	/**
	 * Shares a post on Pixelfed.
	 *
	 * @since 0.7.0
	 *
	 * @param int $post_id Post ID.
	 */
	public function post_to_pixelfed( $post_id ) {
		$post = get_post( $post_id );

		// Let's rerun all of these checks, as something may have changed.
		$is_enabled = ( '1' === get_post_meta( $post->ID, '_share_on_pixelfed', true ) ? true : false );

		if ( ! apply_filters( 'share_on_pixelfed_enabled', $is_enabled, $post->ID ) ) {
			// Disabled for this post.
			return;
		}

		if ( '' !== get_post_meta( $post->ID, '_share_on_pixelfed_url', true ) ) {
			// Prevent duplicate toots.
			return;
		}

		if ( 'publish' !== $post->post_status ) {
			// Status is something other than `publish`.
			return;
		}

		if ( post_password_required( $post ) ) {
			// Post is password-protected.
			return;
		}

		if ( ! in_array( $post->post_type, (array) $this->options['post_types'], true ) ) {
			// Unsupported post type.
			return;
		}

		if ( empty( $this->options['pixelfed_host'] ) ) {
			return;
		}

		if ( ! wp_http_validate_url( $this->options['pixelfed_host'] ) ) {
			return;
		}

		if ( empty( $this->options['pixelfed_access_token'] ) ) {
			return;
		}

		// Upload image.
		$media_id = Image_Handler::upload_thumbnail( $post->ID );

		if ( empty( $media_id ) ) {
			// Something went wrong uploading the image.
			return;
		}

		$status = get_post_meta( $post->ID, '_share_on_pixelfed_status', true );

		if ( empty( $status ) ) {
			$status = get_the_title( $post->ID );
		}

		$status  = wp_strip_all_tags( html_entity_decode( $status, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ) ); // Avoid double-encoded HTML entities.
		$status .= ' ' . esc_url_raw( get_permalink( $post->ID ) );
		$status  = apply_filters( 'share_on_pixelfed_status', $status, $post );

		// Encode, build query string.
		$query_string = http_build_query(
			array(
				'status'     => $status,
				'visibility' => 'public', // Required (?) by Pixelfed.
			)
		);

		// Handle after `http_build_query()`, as apparently the API doesn't like
		// numbers for query string array keys.
		$query_string .= '&media_ids[]=' . rawurlencode( $media_id );

		$response = wp_remote_post(
			esc_url_raw( $this->options['pixelfed_host'] . '/api/v1/statuses' ),
			array(
				'headers'     => array(
					'Authorization' => 'Bearer ' . $this->options['pixelfed_access_token'],
				),
				// Prevent WordPress from applying `http_build_query()`, for the
				// same reason.
				'data_format' => 'body',
				'body'        => $query_string,
				'timeout'     => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			// An error occurred.
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return;
		}

		// Decode JSON, suppressing possible formatting errors.
		$status = json_decode( $response['body'] );

		if ( ! empty( $status->url ) ) {
			delete_post_meta( $post->ID, '_share_on_pixelfed_status' );
			delete_post_meta( $post->ID, '_share_on_pixelfed_error' );
			update_post_meta( $post->ID, '_share_on_pixelfed_url', $status->url );
		} elseif ( ! empty( $status->error ) ) {
			update_post_meta( $post->ID, '_share_on_pixelfed_error', sanitize_text_field( $status->error ) );

			// Provided debugging's enabled, let's store the (somehow faulty)
			// response.
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}

	/**
	 * Deletes a post's Pixelfed URL.
	 *
	 * Should only ever be called through AJAX.
	 *
	 * @since 0.5.1
	 */
	public function unlink_url() {
		if ( ! isset( $_POST['share_on_pixelfed_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['share_on_pixelfed_nonce'] ), basename( __FILE__ ) ) ) {
			status_header( 400 );
			esc_html_e( 'Missing or invalid nonce.', 'share-on-pixelfed' );
			wp_die();
		}

		if ( ! isset( $_POST['post_id'] ) || ! ctype_digit( $_POST['post_id'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			status_header( 400 );
			esc_html_e( 'Missing or incorrect post ID.', 'share-on-pixelfed' );
			wp_die();
		}

		if ( ! current_user_can( 'edit_post', intval( $_POST['post_id'] ) ) ) {
			status_header( 400 );
			esc_html_e( 'Insufficient rights.', 'share-on-pixelfed' );
			wp_die();
		}

		// Have WordPress forget the Pixelfed URL.
		if ( '' !== get_post_meta( intval( $_POST['post_id'] ), '_share_on_pixelfed_url', true ) ) {
			delete_post_meta( intval( $_POST['post_id'] ), '_share_on_pixelfed_url' );
		}

		wp_die();
	}

	/**
	 * Adds admin scripts and styles.
	 *
	 * @since 0.5.1
	 *
	 * @param string $hook_suffix Current WP Admin page.
	 */
	public function enqueue_scripts( $hook_suffix ) {
		if ( in_array( $hook_suffix, array( 'post-new.php', 'post.php' ), true ) ) {
			global $post;

			if ( empty( $post ) ) {
				// Can't do much without a `$post` object.
				return;
			}

			if ( ! in_array( $post->post_type, (array) $this->options['post_types'], true ) ) {
				// Unsupported post type.
				return;
			}

			// Enqueue CSS and JS.
			wp_enqueue_style( 'share-on-pixelfed', plugins_url( '/assets/share-on-pixelfed.css', dirname( __FILE__ ) ), array(), \Share_On_Pixelfed\Share_On_Pixelfed::PLUGIN_VERSION );
			wp_enqueue_script( 'share-on-pixelfed', plugins_url( '/assets/share-on-pixelfed.js', dirname( __FILE__ ) ), array( 'jquery' ), \Share_On_Pixelfed\Share_On_Pixelfed::PLUGIN_VERSION, false );
			wp_localize_script(
				'share-on-pixelfed',
				'share_on_pixelfed_obj',
				array(
					'message' => esc_attr__( 'Forget this URL?', 'share-on-pixelfed' ), // Confirmation message.
					'post_id' => $post->ID, // Pass current post ID to JS.
				)
			);
		}
	}
}
