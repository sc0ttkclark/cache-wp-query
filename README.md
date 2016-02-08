# cache-wp-query
Cache posts from WP_Query in an automatic wrapper for WP_Query, avoid extra DB requests. Integrates with ElasticPress to cache those requests too!

## Usage

In your `WP_Query` arguments, set `'cache_wp_query' => true`.

```php
$args = array(
	'post_type'      => 'posts',
	/* complicated arguments with meta lookups, date queries, etc */
	'cache_wp_query' => true, // Cache this query
);

$my_query = new WP_Query( $args );
```

In your plugin or theme, add the post types you want to support for caching (this is primarily for cache clearing purposes when a new post is published). By default, no post types are cached until you add support for one.

```php
/**
 * Add support to my post types for Cache WP Query
 */
function my_cache_wp_query_post_types() {

	add_post_type_support( 'post', 'cache_wp_query' );
	add_post_type_support( 'my_cpt', 'cache_wp_query' );

}
add_filter( 'init', 'cache_wp_query_post_types' );
```
