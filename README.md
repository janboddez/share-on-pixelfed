# Share on Pixelfed
Automatically share image posts on [Pixelfed](https://pixelfed.org/). You choose which Post Types are shared. (Sharing can still be disabled on a per-post basis.)

By default, shared statuses look something like:
```
My Awesome Post Title https://url.to/original-post/
```

## Custom Formatting
If you'd rather format statuses differently, there's a `share_on_pixelfed_status` filter.

**Example:** if the image posts you share are all very short, mostly plain-text messages and you want them to appear exactly as written and without a backlink, the following couple lines of PHP would handle that.
```
add_filter( 'share_on_pixelfed_status', function( $status, $post ) {
	$status = wp_strip_all_tags( $post->post_content );
	return $status;
}, 10, 2 );
```

## Documentation
More complete documentation, and code examples, can be found at https://jan.boddez.net/wordpress/share-on-pixelfed.
