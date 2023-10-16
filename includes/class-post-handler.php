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
	 * @param array $options Plugin options.
	 */
	public function __construct( $options = array() ) {
		$this->options = $options;
	}

	/**
	 * Registers hook callbacks.
	 *
	 * @since 0.4.0
	 */
	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_share_on_pixelfed_unlink_url', array( $this, 'unlink_url' ) );

		if ( ! empty( $this->options['post_types'] ) ) {
			foreach ( (array) $this->options['post_types'] as $post_type ) {
				add_action( "save_post_{$post_type}", array( $this, 'update_meta' ), 11 );
				add_action( "save_post_{$post_type}", array( $this, 'toot' ), 20 );
			}
		}

		// "Delayed" sharing.
		add_action( 'share_on_pixelfed_post', array( $this, 'post_to_pixelfed' ) );
	}

	/**
	 * Register `_share_on_pixelfed_url` meta for use with the REST API.
	 */
	public function register_meta() {
		if ( empty( $this->options['post_types'] ) ) {
			return;
		}

		$post_types = (array) $this->options['post_types'];

		foreach ( $post_types as $post_type ) {
			// Expose Share on Mastodon's custom fields to the REST API.
			register_rest_field(
				$post_type,
				'share_on_pixelfed',
				array(
					'get_callback'    => array( $this, 'get_meta' ),
					'update_callback' => null,
				)
			);
		}
	}

	/**
	 * Exposes Share on Pixelfed's metadata to the REST API.
	 *
	 * @param  \WP_REST_Request|array $request API request (parameters).
	 * @return array|\WP_Error                 Response (or error).
	 */
	public function get_meta( $request ) {
		if ( is_array( $request ) ) {
			$post_id = $request['id'];
		} else {
			$post_id = $request->get_param( 'post_id' );
		}

		if ( empty( $post_id ) || ! ctype_digit( $post_id ) ) {
			return new \WP_Error( 'invalid_id', 'Invalid post ID.', array( 'status' => 400 ) );
		}

		$post_id = (int) $post_id;

		$url = get_post_meta( $post_id, '_share_on_pixelfed_url', true );

		return array(
			'url'   => get_post_meta( $post_id, '_share_on_pixelfed_url', true ),
			'error' => empty( $url ) // Don't bother if we've got a URL.
				? get_post_meta( $post_id, '_share_on_pixelfed_error', true )
				: '',
		);
	}

	/**
	 * Handles metadata.
	 *
	 * @since 0.1.0
	 *
	 * @param int|\WP_Post $post Post ID or object.
	 */
	public function update_meta( $post ) {
		$post = get_post( $post );

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

		if ( isset( $_POST['share_on_pixelfed_status'] ) ) {
			$status = sanitize_textarea_field( wp_unslash( $_POST['share_on_pixelfed_status'] ) );
			$status = preg_replace( '~\R~u', "\r\n", $status );
		}

		if (
			! empty( $status ) && '' !== preg_replace( '~\s~', '', $status ) &&
			( empty( $this->options['status_template'] ) || $status !== $this->options['status_template'] )
		) {
			// Save only if `$status` is non-empty and, if a template exists, different from said template.
			update_post_meta( $post->ID, '_share_on_pixelfed_status', $status );
		} else {
			// Ignore, or delete a previously stored value.
			delete_post_meta( $post->ID, '_share_on_pixelfed_status' );
		}

		if ( isset( $_POST['share_on_pixelfed'] ) && ! post_password_required( $post ) ) {
			// If sharing enabled.
			update_post_meta( $post->ID, '_share_on_pixelfed', '1' );
		} else {
			delete_post_meta( $post->ID, '_share_on_pixelfed_error' ); // Clear previous errors, if any.
			update_post_meta( $post->ID, '_share_on_pixelfed', '0' );
		}
	}

	/**
	 * Schedules sharing to Pixelfed.
	 *
	 * @since 0.1.0
	 *
	 * @param int|\WP_Post $post Post ID or object.
	 */
	public function toot( $post ) {
		$post = get_post( $post );

		if ( 0 === strpos( current_action(), 'save_' ) && defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			// For REST requests, we use a *later* hook, which runs *after*
			// metadata, if any, has been saved.
			add_action( "rest_after_insert_{$post->post_type}", array( $this, 'toot' ), 20 );

			// Don't do anything just yet.
			return;
		}

		if ( $this->is_gutenberg() && empty( $_REQUEST['meta-box-loader'] ) && ! empty( $this->options['meta_box'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			// This is the first of *two* "Gutenberg requests," and we should
			// ignore it. Now, it could be that `$this->is_gutenberg()` always
			// returns `false` whenever `$_REQUEST['meta-box-loader']` is
			// present. Still, doesn't hurt to check.
			return;
		}

		// In all other cases (non-REST request, non-Gutenberg REST request, or
		// *second* Gutenberg request), we move on.
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}

		if ( ! $this->setup_completed() ) {
			return;
		}

		if ( ! $this->is_valid( $post ) ) {
			return;
		}

		if ( ! empty( $this->options['delay_sharing'] ) ) {
			// Since version 0.7.0, there's an option to "schedule" sharing
			// rather than do everything inline.
			wp_schedule_single_event(
				time() + min( $this->options['delay_sharing'], 3600 ), // Limit to one hour.
				'share_on_pixelfed_post',
				array( $post->ID )
			);
		} else {
			// Share immediately.
			$this->post_to_pixelfed( $post->ID );
		}
	}

	/**
	 * Actually shares a post on Pixelfed.
	 *
	 * Can be called directly or as a (scheduled) `share_on_pixelfed_post`
	 * callback.
	 *
	 * @param int|\WP_Post $post Post ID or object.
	 */
	public function post_to_pixelfed( $post ) {
		if ( ! $this->setup_completed() ) {
			return;
		}

		$post = get_post( $post );

		if ( ! $this->is_valid( $post ) ) {
			return;
		}

		// Upload image.
		list( $image_id, $alt ) = Image_Handler::get_image( $post );
		if ( empty( $image_id ) ) {
			// Nothing to do.
			return;
		}

		$media_id = Image_Handler::upload_thumbnail( $image_id, $alt, $post->ID );
		if ( empty( $media_id ) ) {
			// Something went wrong uploading the image.
			return;
		}

		// Fetch custom status message, if any.
		$status = get_post_meta( $post->ID, '_share_on_pixelfed_status', true );
		// Parse template tags, and sanitize.
		$status = $this->parse_status( $status, $post->ID );

		if ( ( empty( $status ) || '' === preg_replace( '~\s~', '', $status ) ) && ! empty( $this->options['status_template'] ) ) {
			// Use template stored in settings.
			$status = $this->parse_status( $this->options['status_template'], $post->ID );
		}

		if ( empty( $status ) || '' === preg_replace( '~\s~', '', $status ) ) {
			// Fall back to post title.
			$status = get_the_title( $post->ID );
		}

		$status = wp_strip_all_tags(
			html_entity_decode( $status, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ) // Avoid double-encoded HTML entities.
		);

		// Append permalink, but only if it's not already there.
		$permalink = esc_url_raw( get_permalink( $post->ID ) );

		if ( false === strpos( $status, $permalink ) ) {
			// Post doesn't mention permalink, yet. Append it.
			if ( false === strpos( $status, "\n" ) ) {
				$status .= ' ' . $permalink; // Keep it single-line.
			} else {
				$status .= "\r\n\r\n" . $permalink;
			}
		}

		// Allow developers to (completely) override `$status`.
		$status = apply_filters( 'share_on_pixelfed_status', $status, $post );
		$args   = apply_filters( 'share_on_pixelfed_toot_args', array( 'status' => $status ), $post );

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
				// Prevent WordPress from applying `http_build_query()`.
				'data_format' => 'body',
				'body'        => $query_string,
				'timeout'     => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			// An error occurred.
			debug_log( '[Share on Pixelfed] ' . print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
			return;
		}

		$status = json_decode( $response['body'] );

		if ( ! empty( $status->url ) ) {
			delete_post_meta( $post->ID, '_share_on_pixelfed_error' );
			update_post_meta( $post->ID, '_share_on_pixelfed_url', esc_url_raw( $status->url ) );
			delete_transient( "share_on_pixelfed:{$post->ID}:url" );

			if ( 'share_on_pixelfed_post' !== current_filter() ) {
				// Show a notice only when this function was called directly.
				add_filter( 'redirect_post_location', array( Notices::class, 'add_success_query_var' ) );
			}
		} elseif ( ! empty( $status->error ) ) {
			update_post_meta( $post->ID, '_share_on_pixelfed_error', sanitize_text_field( $status->error ) );

			if ( 'share_on_pixelfed_post' !== current_filter() ) {
				// Show a notice only when this function was called directly.
				add_filter( 'redirect_post_location', array( Notices::class, 'add_error_query_var' ) );
			}

			// Provided debugging's enabled, let's store the (somehow faulty)
			// response.
			debug_log( '[Share on Pixelfed] ' . print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
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

		// This'll hide the meta box for Gutenberg users, who by default get the
		// new sidebar panel.
		$args = array(
			'__back_compat_meta_box' => true,
		);
		if ( ! empty( $this->options['meta_box'] ) ) {
			// And this will bring it back.
			$args = null;
		}

		add_meta_box(
			'share-on-pixelfed',
			__( 'Share on Pixelfed', 'share-on-pixelfed' ),
			array( $this, 'render_meta_box' ),
			(array) $this->options['post_types'],
			'side',
			'default',
			$args
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
		$checked = get_post_meta( $post->ID, '_share_on_pixelfed', true );

		if ( '' === $checked ) {
			// If sharing is "opt-in" or the post in question is older than 15
			// minutes, do _not_ check the checkbox by default.
			$checked = apply_filters( 'share_on_pixelfed_optin', ! empty( $this->options['optin'] ) ) || $this->is_older_than( 900, $post ) ? '0' : '1';
		}
		?>
		<label>
			<input type="checkbox" name="share_on_pixelfed" value="1" <?php checked( '1' === $checked ); ?>>
			<?php esc_html_e( 'Share on Pixelfed', 'share-on-pixelfed' ); ?>
		</label>
		<?php
		if ( ! empty( $this->options['custom_status_field'] ) ) :
			// Custom message saved earlier, if any.
			$custom_status = get_post_meta( $post->ID, '_share_on_pixelfed_status', true );

			if ( '' === $custom_status && ! empty( $this->options['status_template'] ) ) {
				// Default to the template as set on the options page.
				$custom_status = $this->options['status_template'];
			}
			?>
			<div style="margin-top: 1em;">
				<label for="share_on_pixelfed_status"><?php esc_html_e( '(Optional) Message', 'share-on-pixelfed' ); ?></label>
				<textarea id="share_on_pixelfed_status" name="share_on_pixelfed_status" rows="3" style="width: 100%; box-sizing: border-box; margin-top: 0.5em;"><?php echo esc_html( trim( $custom_status ) ); ?></textarea>
				<p class="description" style="margin-top: 0.25em;"><?php esc_html_e( 'Customize this post&rsquo;s Pixelfed status.', 'share-on-pixelfed' ); ?></p>
			</div>
			<?php
		endif;

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
		delete_post_meta( $post_id, '_share_on_pixelfed_url' );

		$options = get_options();

		if ( ! empty( $options['meta_box'] ) && use_block_editor_for_post( $post_id ) ) {
			// Delete the checkbox value, too, to prevent Gutenberg's' odd meta
			// box behavior from triggering an immediate re-share.
			delete_post_meta( $post_id, '_share_on_pixelfed' );
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
			wp_enqueue_style( 'share-on-pixelfed', plugins_url( '/assets/share-on-pixelfed.css', __DIR__ ), array(), \Share_On_Pixelfed\Share_On_Pixelfed::PLUGIN_VERSION );
			wp_enqueue_script( 'share-on-pixelfed', plugins_url( '/assets/share-on-pixelfed.js', __DIR__ ), array( 'jquery' ), \Share_On_Pixelfed\Share_On_Pixelfed::PLUGIN_VERSION, false );
			wp_localize_script(
				'share-on-pixelfed',
				'share_on_pixelfed_obj',
				array(
					'message'             => esc_attr__( 'Forget this URL?', 'share-on-pixelfed' ), // Confirmation message.
					'post_id'             => ! empty( $post->ID ) ? $post->ID : 0, // Pass current post ID to JS.
					'nonce'               => wp_create_nonce( basename( __FILE__ ) ),
					'ajaxurl'             => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
					'custom_status_field' => ! empty( $this->options['custom_status_field'] ) ? '1' : '0',
				)
			);
		}
	}

	/**
	 * Determines if a post should, in fact, be shared.
	 *
	 * @param  WP_Post $post Post object.
	 * @return bool          If the post should be shared.
	 */
	protected function is_valid( $post ) {
		if ( empty( $post->post_status ) || 'publish' !== $post->post_status ) {
			// Status is something other than `publish`.
			return false;
		}

		if ( post_password_required( $post ) ) {
			// Post is password-protected.
			return false;
		}

		if ( ! in_array( $post->post_type, (array) $this->options['post_types'], true ) ) {
			// Unsupported post type.
			return false;
		}

		if ( '' !== get_post_meta( $post->ID, '_share_on_pixelfed_url', true ) ) {
			// Was shared before (and not "unlinked").
			return false;
		}

		// A post should only be shared when either the "Share on Pixelfed"
		// checkbox was checked (and its value saved), or when "Share Always" is
		// active (and the post isn't "too old," to avoid mishaps).
		$share_always = false;
		$is_enabled   = false;

		if ( '1' === get_post_meta( $post->ID, '_share_on_pixelfed', true ) ) {
			// Sharing was "explicitly" enabled for this post.
			$is_enabled = true;
		}

		if ( ! empty( $this->options['share_always'] ) ) {
			$share_always = true;
		}

		// We have let developers override `$is_enabled` through a callback
		// function. In practice, this is almost always used to force sharing.
		if ( apply_filters( 'share_on_pixelfed_enabled', $is_enabled, $post->ID ) ) {
			$share_always = true;
		}

		if ( $this->is_older_than( DAY_IN_SECONDS / 2, $post ) ) {
			// Since v0.13.0, we disallow automatic sharing of "older" posts.
			// This sort of changes the behavior of the hook above, which would
			// always come last.
			$share_always = false;
		}

		if ( $is_enabled || $share_always ) {
			return true;
		}

		return false;
	}

	/**
	 * Determines whether a post is older than a certain number of seconds.
	 *
	 * @param  int     $seconds Minimum "age," in secondss.
	 * @param  WP_Post $post    Post object.
	 * @return bool             True if the post exists and is older than `$seconds`, false otherwise.
	 */
	protected function is_older_than( $seconds, $post ) {
		$post_time = get_post_time( 'U', true, $post );

		if ( false === $post_time ) {
			return false;
		}

		if ( $post_time >= time() - $seconds ) {
			return false;
		}

		return true;
	}

	/**
	 * Parses `%title%`, etc. template tags.
	 *
	 * @param  string $status  Pixelfed status, or template.
	 * @param  int    $post_id Post ID.
	 * @return string          Parsed status.
	 */
	protected function parse_status( $status, $post_id ) {
		$status = str_replace( '%title%', get_the_title( $post_id ), $status );
		$status = str_replace( '%excerpt%', $this->get_excerpt( $post_id ), $status );
		$status = str_replace( '%tags%', $this->get_tags( $post_id ), $status );
		$status = str_replace( '%permalink%', esc_url_raw( get_permalink( $post_id ) ), $status );
		$status = preg_replace( '~(\r\n){2,}~', "\r\n\r\n", $status ); // We should have normalized line endings by now.

		return sanitize_textarea_field( $status ); // Strips HTML and whatnot.
	}

	/**
	 * Returns a post's excerpt, but limited to approx. 125 characters.
	 *
	 * @param  int $post_id Post ID.
	 * @return string       (Possibly shortened) excerpt.
	 */
	protected function get_excerpt( $post_id ) {
		$orig    = apply_filters( 'the_excerpt', get_the_excerpt( $post_id ) );
		$orig    = wp_strip_all_tags( $orig ); // Just in case a site owner's allowing HTML in their excerpts or something.
		$orig    = html_entity_decode( $orig, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ); // Prevent special characters from messing things up.
		$excerpt = mb_substr( $orig, 0, apply_filters( 'share_on_pixelfed_excerpt_length', 125 ) );

		if ( $excerpt !== $orig && ! ctype_punct( mb_substr( $excerpt, -1 ) ) ) {
			$excerpt .= '…';
		}

		return trim( $excerpt );
	}

	/**
	 * Returns a post's tags as a string of space-separated hashtags.
	 *
	 * @param  int $post_id Post ID.
	 * @return string       Hashtag string.
	 */
	protected function get_tags( $post_id ) {
		$hashtags = '';
		$tags     = get_the_tags( $post_id );

		if ( $tags && ! is_wp_error( $tags ) ) {
			foreach ( $tags as $tag ) {
				$tag_name = $tag->name;

				if ( preg_match( '/\s+/', $tag_name ) ) {
					// Try to "CamelCase" multi-word tags.
					$tag_name = preg_replace( '/\s+/', ' ', $tag_name );
					$tag_name = explode( ' ', $tag_name );
					$tag_name = implode( '', array_map( 'ucfirst', $tag_name ) );
				}

				$hashtags .= '#' . $tag_name . ' ';
			}
		}

		return trim( $hashtags );
	}


	/**
	 * Checks for a Mastodon instance and auth token.
	 *
	 * @since 0.17.1
	 *
	 * @return bool Whether auth access was set up okay.
	 */
	protected function setup_completed() {
		if ( empty( $this->options['pixelfed_host'] ) ) {
			return false;
		}

		if ( ! wp_http_validate_url( $this->options['pixelfed_host'] ) ) {
			return false;
		}

		if ( empty( $this->options['pixelfed_access_token'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Checks whether the current request was initiated by the block editor.
	 *
	 * @return bool Whether the current request was initiated by the block editor.
	 */
	protected function is_gutenberg() {
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			// Not a REST request.
			return false;
		}

		$nonce = null;

		if ( isset( $_REQUEST['_wpnonce'] ) ) {
			$nonce = $_REQUEST['_wpnonce']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		} elseif ( isset( $_SERVER['HTTP_X_WP_NONCE'] ) ) {
			$nonce = $_SERVER['HTTP_X_WP_NONCE']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		if ( null === $nonce ) {
			return false;
		}

		// Check the nonce.
		return wp_verify_nonce( $nonce, 'wp_rest' );
	}
}
