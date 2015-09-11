<?php
/**
 * Plugin Name:       Cache WP_Query
 * Plugin URI:        https://github.com/sc0ttkclark/cache-wp-query
 * Description:       Cache posts from WP_Query in an automatic wrapper for WP_Query, avoid extra DB requests
 * Version:           0.1
 * Author:            Scott Kingsley Clark
 * Author URI:        http://scottkclark.com/
 */
class Cache_WP_Query {

	/**
	 * @var array Current query
	 */
	public $query_vars = array();

	/**
	 * @var null|string Cache key
	 */
	public $cache_key = null;

	/**
	 * @var array|null Cached posts
	 */
	public $cached_posts = null;

	/**
	 * @var int|null Found posts
	 */
	public $found_posts = null;

	/**
	 * @var int|null Max num pages
	 */
	public $max_num_pages = null;

	/**
	 * Placeholder method
	 */
	private function __construct() {

		// Nope

	}

	/**
	 * Hook into filters
	 */
	public function setup() {

		// Skip EP integration if we have cache
		add_filter( 'ep_skip_query_integration', array( $this, 'filter_ep_skip_query_integration' ), 10, 2 );

		// Reset data
		add_action( 'pre_get_posts', array( $this, 'action_pre_get_posts' ), 9 );

		// Make sure we return nothing for MySQL posts query
		add_filter( 'posts_request', array( $this, 'filter_posts_request' ), 11, 2 );

		// Nukes the FOUND_ROWS() database query
		add_filter( 'found_posts_query', array( $this, 'filter_found_posts_query' ), 6, 2 );

		// Search and filter in EP_Posts to WP_Query
		add_filter( 'the_posts', array( $this, 'filter_the_posts' ), 11, 2 );

		// Properly restore blog if necessary
		add_action( 'loop_end', array( $this, 'action_loop_end' ), 11, 1 );

		// Properly switch to blog if necessary
		add_action( 'the_post', array( $this, 'action_the_post' ), 11, 1 );

		// Hook into ElasticPress search integration
		add_action( 'ep_wp_query_search', array( $this, 'ep_wp_query_search' ), 10, 3 );

	}

	/**
	 * @param boolean   $skip
	 * @param \WP_Query $query
	 */
	public function filter_ep_skip_query_integration( $skip, $query ) {

		if ( ! $skip && $query ) {
			$cached_posts = $this->get_posts();

			if ( ! empty( $cached_posts ) ) {
				$skip = true;
			}
		}

		return $skip;

	}

	/**
	 * Disables cache_results, adds header.
	 *
	 * @param \WP_Query $query
	 */
	public function action_pre_get_posts( $query ) {

		$this->reset();

		if ( ( ! empty( $query->query_vars['cache_results'] ) || ! empty( $query->query_vars['s'] ) ) && ! empty( $query->query_vars['post_type'] ) ) {
			$post_types = (array) $query->query_vars['post_type'];

			if ( in_array( 'post', $post_types ) || in_array( 'cmm_article', $post_types ) ) {
				$this->query_vars = $query->query_vars;

				$cache_key = $this->get_cache_key();
			}
		}

	}

	/**
	 * Switch to the correct site if the post site id is different than the actual one
	 *
	 * @param array $post
	 *
	 * @since 0.9
	 */
	public function action_the_post( $post ) {

		$cache_key = $this->get_cache_key();

		if ( ! empty( $cache_key ) ) {
			if ( is_multisite() && ! empty( $post->site_id ) && get_current_blog_id() != $post->site_id ) {
				restore_current_blog();

				switch_to_blog( $post->site_id );

				remove_action( 'the_post', array( $this, 'action_the_post' ), 11 );
				setup_postdata( $post );
				add_action( 'the_post', array( $this, 'action_the_post' ), 11, 1 );
			}
		}

	}

	/**
	 * Make sure the correct blog is restored
	 */
	public function action_loop_end( $query ) {

		$cache_key = $this->get_cache_key();

		if ( ! empty( $cache_key ) ) {
			$this->reset();

			if ( is_multisite() && ! empty( $GLOBALS['switched'] ) ) {
				restore_current_blog();
			}
		}

	}

	/**
	 * Filter the posts array to contain cached search results.
	 *
	 * @param array     $posts
	 * @param \WP_Query &$query
	 *
	 * @return array
	 */
	public function filter_the_posts( $posts, $query ) {

		// Can be cached
		$cache_key = $this->get_cache_key();

		if ( ! empty( $cache_key ) ) {
			if ( ! empty( $posts ) ) {
				$this->set_posts( $posts, $query );
			} else {
				$cached_posts = $this->get_posts();

				if ( ! empty( $cached_posts ) ) {
					$posts = $cached_posts;
				}
			}
		}

		return $posts;

	}

	/**
	 * Remove the found_rows from the SQL Query
	 *
	 * @param string $sql
	 * @param object $query
	 *
	 * @since 0.9
	 * @return string
	 */
	public function filter_found_posts_query( $sql, $query ) {

		// Can be cached
		$cache_key = $this->get_cache_key();

		if ( ! empty( $cache_key ) ) {
			$cached_posts = $this->get_posts();

			if ( ! empty( $cached_posts ) ) {
				$sql = '';
			}
		}

		return $sql;

	}

	/**
	 * Filter query string used for get_posts(). Search for posts and save for later.
	 * Return a query that will return nothing.
	 *
	 * @param string $request
	 * @param object $query
	 *
	 * @return string
	 */
	public function filter_posts_request( $request, $query ) {

		// Can be cached
		$cache_key = $this->get_cache_key();

		if ( ! empty( $cache_key ) ) {
			$cached_posts = $this->get_posts();

			if ( ! empty( $cached_posts ) ) {
				/**
				 * @var \wpdb $wpdb
				 */
				global $wpdb;

				$request = "SELECT * FROM $wpdb->posts WHERE 1=0";
			}
		}

		return $request;

	}

	/**
	 * Handle caching ElasticPress posts
	 *
	 * @param array     $new_posts
	 * @param array     $search
	 * @param \WP_Query $query
	 */
	public function ep_wp_query_search( $new_posts, $search, $query ) {

		// Can be cached
		$cache_key = $this->get_cache_key();

		// Cache ElasticPress posts
		if ( ! empty( $cache_key ) ) {
			$this->set_posts( $new_posts, $query );
		}

	}

	/**
	 * Get posts from cache
	 *
	 * @return bool|null|array
	 */
	public function get_posts() {

		$posts = null;

		// Can be cached
		$cache_key = $this->get_cache_key();

		if ( ! empty( $cache_key ) ) {
			$posts = $this->cached_posts;

			if ( null === $posts ) {
				$posts = get_transient( $cache_key );

				if ( is_array( $posts ) && ! empty( $posts ) ) {
					$posts = array_map( 'get_post', $posts );
				}

				if ( ! is_array( $posts ) || empty( $posts ) ) {
					$posts = array();
				}

				$this->cached_posts = $posts;

				$this->get_meta();
			}
		}

		return $posts;

	}

	/**
	 * Set posts to cache
	 *
	 * @param array $posts
	 * @param \WP_Query $query
	 */
	public function set_posts( $posts, $query ) {

		// Can be cached
		$cache_key = $this->get_cache_key();

		if ( ! empty( $cache_key ) ) {
			if ( ! empty( $posts ) ) {
				if ( ! is_array( $posts ) ) {
					$posts = array( $posts );
				}

				$posts_to_cache = wp_list_pluck( $posts, 'ID' );

				set_transient( $cache_key, $posts_to_cache, 3 * DAY_IN_SECONDS );

				$this->cached_posts = $posts;
			} else {
				delete_transient( $cache_key );

				$this->cached_posts = array();
			}

			$this->set_meta( $query );
		}

	}

	/**
	 * Get meta from cache
	 *
	 * @return bool|null|array
	 */
	public function get_meta() {

		$meta = null;

		// Can be cached
		$cache_key = $this->get_cache_key();

		if ( ! empty( $cache_key ) ) {
			$posts = $this->cached_posts;

			if ( ! empty( $posts ) ) {
				$meta = get_transient( $cache_key . '_meta' );

				if ( is_array( $meta ) && ! empty( $meta ) ) {
					$this->found_posts = $meta['found_posts'];
					$this->max_num_pages = $meta['max_num_pages'];
				}
			}
		}

		return $meta;

	}

	/**
	 * Set meta properties
	 *
	 * @param \WP_Query $query
	 */
	public function set_meta( $query ) {

		// Can be cached
		$cache_key = $this->get_cache_key();
		$posts     = $this->cached_posts;

		if ( ! empty( $cache_key ) ) {
			if ( ! empty( $posts ) ) {
				$meta = array(
					'found_posts'   => $query->found_posts,
					'max_num_pages' => $query->max_num_pages,
				);

				set_transient( $cache_key . '_meta', $meta, 3 * DAY_IN_SECONDS );

				$this->cached_posts = $posts;
			} else {
				delete_transient( $cache_key . '_meta' );

				$this->cached_posts = array();
			}
		}

	}

	/**
	 * Get cache key
	 *
	 * @return string
	 */
	public function get_cache_key() {

		$cache_key = $this->cache_key;

		if ( empty( $cache_key ) ) {
			$cache_key = null;

			if ( ! empty( $this->query_vars ) ) {
				$cache_key = md5( serialize( $this->query_vars ) );
			}

			$this->cache_key = $cache_key;
		}

		return $cache_key;

	}

	/**
	 * Reset query info
	 */
	public function reset() {

		// Unset our stuff
		$this->query_vars    = array();
		$this->cache_key     = null;
		$this->cached_posts  = null;
		$this->found_posts   = null;
		$this->max_num_pages = null;

	}

	/**
	 * Return a singleton instance of the current class
	 *
	 * @return Cache_WP_Query
	 */
	public static function factory() {

		static $instance = false;

		if ( ! $instance ) {
			$instance = new self();

			add_action( 'init', array( $instance, 'setup' ) );
		}

		return $instance;

	}
}

Cache_WP_Query::factory();
