<?php
/**
 * Main plugin class.
 *
 * @package Share_On_Pixelfed
 */

namespace Share_On_Pixelfed;

/**
 * Main plugin class.
 */
class Share_On_Pixelfed {
	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 */
	public function __construct() {
		register_activation_hook( dirname( dirname( __FILE__ ) ) . '/share-on-pixelfed.php', array( $this, 'activate' ) );
		register_deactivation_hook( dirname( dirname( __FILE__ ) ) . '/share-on-pixelfed.php', array( $this, 'deactivate' ) );

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'share_on_pixelfed_refresh_token', array( Options_Handler::get_instance(), 'cron_refresh_token' ) );

		$post_handler = Post_Handler::get_instance();
	}

	/**
	 * Enables localization.
	 *
	 * @since 0.1.0
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'share-on-pixelfed', false, basename( dirname( dirname( __FILE__ ) ) ) . '/languages' );
	}

	/**
	 * Runs on activation.
	 *
	 * @since 0.3.0
	 */
	public function activate() {
		// Schedule a daily cron job, starting 15 minutes after this plugin's
		// first activated.
		if ( false === wp_next_scheduled( 'share_on_pixelfed_refresh_token' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'share_on_pixelfed_refresh_token' );
		}
	}

	/**
	 * Runs on deactivation.
	 *
	 * @since 0.3.0
	 */
	public function deactivate() {
		wp_clear_scheduled_hook( 'share_on_pixelfed_refresh_token' );
	}
}
