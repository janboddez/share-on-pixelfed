# Share on Pixelfed
Automatically share WordPress (image) posts on [Pixelfed](https://pixelfed.org/). You choose which Post Types are shared—though sharing can still be disabled on a per-post basis.

This plugin shares some 75%—not an exact number—of its code with [Share on Mastodon](https://github.com/janboddez/share-on-mastodon). Both plugins rely on the "Mastodon v1 API" (which Pixelfed supports, too).

By default, shared statuses look something like:
```
My Awesome Post Title https://url.to/original-post/
```

Pixelfed will not automatically turn that URL into a clickable link, but we can live with that. Posts without a Featured Image will not be shared. (The plugin currently doesn't look for other images inside the post, that is.)

## Custom Formatting
If you'd rather format statuses differently, there's a `share_on_pixelfed_status` filter.

**Example:** if the image posts you share are all very short, mostly plain-text messages and you want them to appear exactly as written and without a backlink, the following couple lines of PHP would handle that.
```
add_filter( 'share_on_pixelfed_status', function( $status, $post ) {
	$status = wp_strip_all_tags( $post->post_content );
	return $status;
}, 10, 2 );
```

## Post Privacy
Currently, all statuses sent via this plugin are **public**. Unlisted or followers-only statuses may become an option later on.

## Gutenberg
This plugin uses WordPress' Meta Box API—supported by Gutenberg—to store per-post sharing settings, which makes it 100% compatible with the new block editor.
