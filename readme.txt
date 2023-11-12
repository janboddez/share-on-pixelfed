=== Share on Pixelfed ===
Contributors: janboddez
Tags: pixelfed, share, publicize, crosspost, fediverse
Tested up to: 6.4
Stable tag: 0.9.0
License: GNU General Public License v3.0
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Automatically share WordPress (image) posts on Pixelfed.

== Description ==
Automatically share WordPress posts on [Pixelfed](https://pixelfed.org/).

You choose which post types are shared, though sharing can still be disabled on a per-post basis. (Posts without either a featured image or image content will not be shared.)

Supports both WordPress' block editor and the classic editor, custom post types, and more.

More details can be found on [this plugin's web page](https://jan.boddez.net/wordpress/share-on-pixelfed).

== Installation ==
Within WP Admin, visit Plugins > Add New and search for "share on pixelfed" to locate the plugin. (Alternatively, upload this plugin’s ZIP file via the “Upload Plugin” button.)

After activation, head over to *Settings > Share on Pixelfed* to authorize WordPress to post to your Pixelfed account.

More detailed instructions can be found on [this plugin's web page](https://jan.boddez.net/wordpress/share-on-pixelfed).

== Changelog ==
= 0.9.0 =
Add status template, update custom statuses, and improve Gutenberg behavior. Deprecate `share_on_pixelfed_image_path` filter in favor of `share_on_pixelfed_media`.

= 0.8.0 =
Improved alt handling.

= 0.7.0 =
Add a whole bunch of options.

= 0.6.1 =
Add filter to make sharing opt-in.

= 0.6 =
Add "first image" (rather than Featured Image) option.

= 0.5 =
More robust instance URL handling. Reset client details after instance switch.

= 0.2 =
Allow `Post_Handler` hook callbacks to be removed.

= 0.1 =
Initial release.
