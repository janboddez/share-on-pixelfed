<?php
/**
 * Plugin Name:       Share on Pixelfed
 * Description:       Easily share WordPress (image) posts on Pixelfed.
 * Author:            Jan Boddez
 * Author URI:        https://janboddez.tech/
 * GitHub Plugin URI: https://github.com/janboddez/share-on-pixelfed
 * License:           GNU General Public License v3
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       share-on-pixelfed
 * Version:           0.1
 *
 * @author  Jan Boddez <jan@janboddez.be>
 * @license http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 * @package Share_On_Pixelfed
 */

namespace Share_On_Pixelfed;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require dirname( __FILE__ ) . '/includes/class-options-handler.php';
require dirname( __FILE__ ) . '/includes/class-post-handler.php';
require dirname( __FILE__ ) . '/includes/class-share-on-pixelfed.php';

new Share_On_Pixelfed();
