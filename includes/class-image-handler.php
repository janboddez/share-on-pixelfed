<?php
/**
 * All things images.
 *
 * @package Share_On_Pixelfed
 */

namespace Share_On_Pixelfed;

/**
 * Image handler class.
 */
class Image_Handler {
	/**
	 * Uploads a post thumbnail and returns a (single) media ID.
	 *
	 * @since 0.7.0
	 *
	 * @param  int $post_id Post ID.
	 * @return string|null  Unique media ID, or nothing on failure.
	 */
	public static function upload_thumbnail( $post_id ) {
		$options = \Share_On_Pixelfed\Share_On_Pixelfed::get_instance()
			->get_options_handler()
			->get_options();

		$file_path = '';

		if ( ! empty( $options['use_first_image'] ) ) {
			// Using first image rather than post thumbnail.
			$thumb_id = static::find_first_image( $post_id );
		} elseif ( has_post_thumbnail( $post_id ) ) {
			// Get post thumbnail (i.e., Featured Image).
			$thumb_id = get_post_thumbnail_id( $post_id );
		}

		// Then, grab the "large" image.
		$image   = wp_get_attachment_image_src( $thumb_id, apply_filters( 'share_on_pixelfed_image_size', 'large', $thumb_id ) );
		$uploads = wp_upload_dir();

		if ( ! empty( $image[0] ) && 0 === strpos( $image[0], $uploads['baseurl'] ) ) {
			// Found a "large" thumbnail that lives on our own site (and not,
			// e.g., a CDN).
			$url = $image[0];
		} else {
			// Get the original image instead.
			$url = wp_get_attachment_url( $thumb_id ); // Original image URL.
		}

		$file_path = str_replace( $uploads['baseurl'], $uploads['basedir'], $url );
		$file_path = apply_filters( 'share_on_pixelfed_image_path', $file_path, $post_id );

		if ( ! is_file( $file_path ) ) {
			// File doesn't seem to exist.
			return;
		}

		// Fetch alt text.
		$alt = get_post_meta( $thumb_id, '_wp_attachment_image_alt', true );

		if ( '' === $alt ) {
			$alt = wp_get_attachment_caption( $thumb_id ); // Fallback to caption.
		}

		$boundary = md5( time() );
		$eol      = "\r\n";

		$body = '--' . $boundary . $eol;

		if ( false !== $alt && '' !== $alt ) {
			error_log( "[Share on Pixelfed] Found the following alt text for the attachment with ID $thumb_id: $alt" ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			// Send along an image description, because accessibility.
			$body .= 'Content-Disposition: form-data; name="description";' . $eol . $eol;
			$body .= $alt . $eol;
			$body .= '--' . $boundary . $eol;

			error_log( "[Share on Pixelfed] Here's the `alt` bit of what we're about to send the Pixelfed API: `$body`" ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		} else {
			error_log( "[Share on Pixelfed] Did not find alt text for the attachment with ID $thumb_id" ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		// The actual (binary) image data.
		$body .= 'Content-Disposition: form-data; name="file"; filename="' . basename( $file_path ) . '"' . $eol;
		$body .= 'Content-Type: ' . mime_content_type( $file_path ) . $eol . $eol;
		$body .= file_get_contents( $file_path ) . $eol; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$body .= '--' . $boundary . '--';

		$response = wp_remote_post(
			esc_url_raw( $options['pixelfed_host'] . '/api/v1/media' ),
			array(
				'headers'     => array(
					'Authorization' => 'Bearer ' . $options['pixelfed_access_token'],
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

		$media = json_decode( $response['body'] );

		if ( ! empty( $media->id ) ) {
			return $media->id;
		} elseif ( ! empty( $media->error ) ) {
			update_post_meta( $post_id, '_share_on_pixelfed_error', sanitize_text_field( $media->error ) );
		}

		// Provided debugging's enabled, let's store the (somehow faulty)
		// response.
		error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
	}

	/**
	 * Returns the file path of the first image inside a post's content.
	 *
	 * @since 0.7.0
	 *
	 * @param  int $post_id Post ID.
	 * @return int|null     Image ID, or nothing on failure.
	 */
	public static function find_first_image( $post_id ) {
		$post = get_post( $post_id );

		// Assumes `src` value is wrapped in quotes. This will almost always be
		// the case.
		preg_match_all( '~<img(?:.+?)src=[\'"]([^\'"]+)[\'"](?:.*?)>~i', $post->post_content, $matches );

		if ( empty( $matches[1] ) ) {
			return;
		}

		foreach ( $matches[1] as $match ) {
			$filename = pathinfo( $match, PATHINFO_FILENAME );
			$original = preg_replace( '~-(?:\d+x\d+|scaled|rotated)$~', '', $filename ); // Strip dimensions, etc., off resized images.

			$url = str_replace( $filename, $original, $match );

			// Convert URL back to attachment ID.
			$thumb_id = (int) attachment_url_to_postid( $url );

			if ( 0 === $thumb_id ) {
				// Unknown to WordPress.
				continue;
			}

			return $thumb_id;
		}
	}
}
