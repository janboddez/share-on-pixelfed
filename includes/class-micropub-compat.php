<?php
/**
 * Some Micropub-related enhancements.
 *
 * @package Share_On_Pixelfed
 */

namespace Share_On_Pixelfed;

/**
 * Micropub goodies.
 */
class Micropub_Compat {
	/**
	 * Enables Micropub syndication.
	 *
	 * @since 0.8.0
	 */
	public static function register() {
		// Micropub syndication.
		add_filter( 'micropub_syndicate-to', array( __CLASS__, 'syndicate_to' ) );
		add_action( 'micropub_syndication', array( __CLASS__, 'syndication' ), 10, 2 );
	}

	/**
	 * Registers a Micropub syndication target.
	 *
	 * @param  array $syndicate_to Syndication targets.
	 * @return array               Modified syndication targets.
	 *
	 * @since 0.8.0
	 */
	public static function syndicate_to( $syndicate_to ) {
		$plugin  = Share_On_Pixelfed::get_instance();
		$options = $plugin->get_options_handler()->get_options();

		if ( empty( $options['pixelfed_host'] ) ) {
			return $syndicate_to;
		}

		if ( empty( $options['pixelfed_username'] ) ) {
			return $syndicate_to;
		}

		$syndicate_to[] = array(
			'uid'  => "{$options['pixelfed_host']}/{$options['pixelfed_username']}",
			'name' => "Pixelfed ({$options['pixelfed_username']})",
		);

		return $syndicate_to;
	}

	/**
	 * Triggers syndication to Pixelfed.
	 *
	 * @param int   $post_id        Post ID.
	 * @param array $synd_requested Selected syndication targets.
	 *
	 * @since 0.8.0
	 */
	public static function syndication( $post_id, $synd_requested ) {
		$plugin  = Share_On_Pixelfed::get_instance();
		$options = $plugin->get_options_handler()->get_options();

		if ( empty( $options['pixelfed_host'] ) ) {
			return;
		}

		if ( empty( $options['pixelfed_username'] ) ) {
			return;
		}

		if ( in_array( "{$options['pixelfed_host']}/{$options['pixelfed_username']}", $synd_requested, true ) ) {
			update_post_meta( $post_id, '_share_on_pixelfed', '1' );
			delete_post_meta( $post_id, '_share_on_pixelfed_error' ); // Clear previous errors, if any.

			$post = get_post( $post_id );

			if ( 'publish' === $post->post_status ) {
				// Trigger syndication.
				$post_handler = $plugin->get_post_handler();
				$post_handler->toot( 'publish', 'publish', $post );
			}
		}
	}
}
