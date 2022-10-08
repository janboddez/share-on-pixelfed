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
	 * This plugin's single instance.
	 *
	 * @since 0.4.0
	 *
	 * @var Share_On_Pixelfed $instance Plugin instance.
	 */
	private static $instance;

	/**
	 * `Options_Handler` instance.
	 *
	 * @since 0.4.0
	 *
	 * @var Options_Handler $instance `Options_Handler` instance.
	 */
	private $options_handler;

	/**
	 * `Post_Handler` instance.
	 *
	 * @since 0.4.0
	 *
	 * @var Post_Handler $instance `Post_Handler` instance.
	 */
	private $post_handler;

	/**
	 * Returns the single instance of this class.
	 *
	 * @since 0.4.0
	 *
	 * @return Share_On_Pixelfed Single class instance.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {
		$this->options_handler = new Options_Handler();
		$this->options_handler->register();

		$this->post_handler = new Post_Handler( $this->options_handler );
		$this->post_handler->register();
	}

	/**
	 * Registers hook callbacks.
	 *
	 * @since 0.4.0
	 */
	public function register() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'plugins_loaded', array( $this, 'activate' ) );

		register_deactivation_hook( dirname( dirname( __FILE__ ) ) . '/share-on-pixelfed.php', array( $this, 'deactivate' ) );

		$options = $this->options_handler->get_options();

		if ( ! empty( $options['micropub_compat'] ) ) {
			Micropub_Compat::register();
		}
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
	 * Registers WP-Cron hook.
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

	/**
	 * Returns `Options_Handler` instance.
	 *
	 * @since 0.5.0
	 *
	 * @return Options_Handler This plugin's `Options_Handler` instance.
	 */
	public function get_options_handler() {
		return $this->options_handler;
	}

	/**
	 * Returns `Post_Handler` instance.
	 *
	 * @since 0.5.0
	 *
	 * @return Post_Handler This plugin's `Post_Handler` instance.
	 */
	public function get_post_handler() {
		return $this->post_handler;
	}
}
