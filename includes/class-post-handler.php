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
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );

		add_action( 'transition_post_status', array( $this, 'update_meta' ), 11, 3 );
		add_action( 'transition_post_status', array( $this, 'toot' ), 999, 3 );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_share_on_pixelfed_unlink_url', array( $this, 'unlink_url' ) );
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
		?>
		<label>
			<input type="checkbox" name="share_on_pixelfed" value="1" <?php checked( in_array( get_post_meta( $post->ID, '_share_on_pixelfed', true ), array( '', '1' ), true ) ); ?>>
			<?php esc_html_e( 'Share on Pixelfed', 'share-on-pixelfed' ); ?>
		</label>
		<?php
		$url = get_post_meta( $post->ID, '_share_on_pixelfed_url', true );

		if ( '' !== $url && false !== wp_http_validate_url( $url ) ) :
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

		if ( isset( $_POST['share_on_pixelfed'] ) && ! post_password_required( $post ) ) {
			// If sharing enabled and post not password-protected.
			update_post_meta( $post->ID, '_share_on_pixelfed', '1' );
		} else {
			update_post_meta( $post->ID, '_share_on_pixelfed', '0' );
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

		// Upload image.
		$media_id = $this->upload_thumbnail( $post->ID );

		if ( empty( $media_id ) ) {
			// Something went wrong uploading the image.
			return;
		}

		$status = wp_strip_all_tags( get_the_title( $post->ID ) ) . ' ' . esc_url_raw( get_permalink( $post->ID ) );
		$status = apply_filters( 'share_on_pixelfed_status', $status, $post );

		// Encode, build query string.
		$query_string = http_build_query(
			array(
				'status'     => $status,
				'visibility' => 'public', // Required (?) by Pixelfed.
			)
		);

		// Handle after `http_build_query()`, as apparently the API
		// doesn't like numbers for query string array keys.
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
		$status = @json_decode( $response['body'] );

		if ( ! empty( $status->url ) && post_type_supports( $post->post_type, 'custom-fields' ) ) {
			update_post_meta( $post->ID, '_share_on_pixelfed_url', $status->url );
		} else {
			// Provided debugging's enabled, let's store the (somehow faulty)
			// response.
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}

	/**
	 * Uploads a post thumbnail and returns a (single) media ID.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return string|null Unique media ID, or nothing on failure.
	 */
	private function upload_thumbnail( $post_id ) {
		$file_path = '';

		if ( isset( $this->options['use_first_image'] ) && $this->options['use_first_image'] ) {
			// Using first image rather than post thumbnail.
			$file_path = $this->find_first_image( $post_id );
		} elseif ( has_post_thumbnail( $post_id ) ) {
			// Get post thumbnail (i.e., Featured Image).
			$thumb_id = get_post_thumbnail_id( $post_id );

			// Then, grab the "large" image.
			$image = wp_get_attachment_image_src( $thumb_id, apply_filters( 'share_on_pixelfed_image_size', 'large', $thumb_id ) );

			if ( ! empty( $image[0] ) ) {
				$url = $image[0];
			} else {
				// Get the original image instead.
				$url = wp_get_attachment_url( $thumb_id ); // Original image URL.
			}

			$uploads   = wp_upload_dir();
			$file_path = str_replace( $uploads['baseurl'], $uploads['basedir'], $url );
		}

		$file_path = apply_filters( 'share_on_pixelfed_image_path', $file_path, $post_id );

		if ( ! is_file( $file_path ) ) {
			// File doesn't seem to exist.
			return;
		}

		$boundary = md5( time() );
		$eol      = "\r\n";

		$body  = '--' . $boundary . $eol;
		$body .= 'Content-Disposition: form-data; name="file"; filename="' . basename( $file_path ) . '"' . $eol;
		$body .= 'Content-Type: ' . mime_content_type( $file_path ) . $eol . $eol;
		$body .= file_get_contents( $file_path ) . $eol; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$body .= '--' . $boundary . '--';

		$response = wp_remote_post(
			esc_url_raw( $this->options['pixelfed_host'] . '/api/v1/media' ),
			array(
				'headers'     => array(
					'Authorization' => 'Bearer ' . $this->options['pixelfed_access_token'],
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'data_format' => 'body',
				'body'        => $body,
				'timeout'     => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			// An error occurred.
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return;
		}

		// Decode JSON, suppressing possible formatting errors.
		$media = @json_decode( $response['body'] );

		if ( ! empty( $media->id ) ) {
			return $media->id;
		}

		// Provided debugging's enabled, let's store the (somehow faulty)
		// response.
		error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
	}

	/**
	 * Returns the file path of the first image inside a post's content.
	 *
	 * @since 0.6.0
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return string|null File path, or nothing on failure.
	 */
	public function find_first_image( $post_id ) {
		$post = get_post( $post_id );

		// Assumes `src` value is wrapped in quotes. This will almost always be
		// the case.
		preg_match_all( '~<img(?:.+?)src=[\'"]([^\'"]+)[\'"](?:.*?)>~i', $post->post_content, $matches );

		if ( empty( $matches[1] ) ) {
			return;
		}

		foreach ( $matches[1] as $match ) {
			// Convert URL back to attachment ID.
			$image_id = attachment_url_to_postid( $match );

			if ( 0 === $image_id ) {
				// Unknown to WordPress.
				continue;
			}

			// Then, grab the "large" image.
			$image = wp_get_attachment_image_src( $image_id, apply_filters( 'share_on_pixelfed_image_size', 'large', $image_id ) );

			if ( ! empty( $image[0] ) ) {
				$url = $image[0];
			} else {
				// Get the original image instead.
				$url = wp_get_attachment_url( $image_id ); // Original image URL.
			}

			// Convert URL to file path.
			$uploads   = wp_upload_dir();
			$file_path = str_replace( $uploads['baseurl'], $uploads['basedir'], $url );

			return $file_path;
		}
	}

	/**
	 * Checks whether a post's content contains images.
	 *
	 * External images are ignored.
	 *
	 * @since 0.6.0
	 *
	 * @param WP_Post $post Post object.
	 *
	 * @return bool If the post content contains images.
	 */
	private function has_images( $post ) {
		preg_match_all( '~<img(?:.+?)src=[\'"]([^\'"]+)[\'"](?:.*?)>~i', $post->post_content, $matches );

		if ( empty( $matches[1] ) ) {
			// No images here.
			return false;
		}

		foreach ( $matches[1] as $match ) {
			// Convert URL back to attachment ID.
			$image_id = attachment_url_to_postid( $match );

			if ( 0 !== $image_id ) {
				// Image exists in WordPress media library.
				return true;
			}
		}

		return false;
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
		if ( 'post-new.php' !== $hook_suffix && 'post.php' !== $hook_suffix ) {
			// Not an "Edit Post" screen.
			return;
		}

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
		wp_enqueue_style( 'share-on-pixelfed', plugins_url( '/assets/share-on-pixelfed.css', dirname( __FILE__ ) ), array(), '0.5.1' );
		wp_enqueue_script( 'share-on-pixelfed', plugins_url( '/assets/share-on-pixelfed.js', dirname( __FILE__ ) ), array( 'jquery' ), '0.5.1', false );
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
