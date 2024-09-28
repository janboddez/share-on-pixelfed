<?php
/**
 * Plugin Name: Share on Pixelfed
 * Description: Easily share WordPress (image) posts on Pixelfed.
 * Author:      Jan Boddez
 * Author URI:  https://janboddez.tech/
 * License:     GNU General Public License v3
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: share-on-pixelfed
 * Version:     0.9.0
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

require __DIR__ . '/includes/class-block-editor.php';
require __DIR__ . '/includes/class-image-handler.php';
require __DIR__ . '/includes/class-micropub-compat.php';
require __DIR__ . '/includes/class-options-handler.php';
require __DIR__ . '/includes/class-pixelfed-client.php';
require __DIR__ . '/includes/class-plugin-options.php';
require __DIR__ . '/includes/class-post-handler.php';
require __DIR__ . '/includes/class-share-on-pixelfed.php';
require __DIR__ . '/includes/class-syn-links-compat.php';
require __DIR__ . '/includes/functions.php';

Share_On_Pixelfed::get_instance()
	->register();
