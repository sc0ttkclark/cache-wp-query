<?php
/**
 * Plugin Name:       Cache WP_Query
 * Plugin URI:        https://github.com/sc0ttkclark/cache-wp-query
 * Description:       Cache posts from WP_Query in an automatic wrapper for WP_Query, avoid extra DB requests.
 * Version:           0.2
 * Author:            Scott Kingsley Clark
 * Author URI:        http://scottkclark.com/
 */
class Cache_WP_Query {

	/**
	 * @var array Allowed post types to cache
	 */
	private $post_types = array();

	/**
	 * @var array Current query
	 */
	private $query_vars = array();

	/**
	 * @var null|string Cache key
	 */
	private $cache_key = null;

	/**
	 * @var array|null Cached posts
	 */
	private $cached_posts = null;

	/**
	 * @var int|null Found posts
	 */
	private $found_posts = null;

	/**
	 * @var int|null Max num pages
	 */
	private $max_num_pages = null;

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

		// Get supported post types
		$this->get_supported_post_types();

		// Skip EP integration if we have cache
		add_filter( 'ep_skip_query_integration', array( $this, 'filter_ep_skip_query_integration' ), 10, 2 );

		// Reset data
		add_action( 'pre_get_posts', array( $this, 'action_pre_get_posts' ), 4 );

		// Make sure we return nothing for MySQL posts query
		add_filter( 'posts_request', array( $this, 'filter_posts_request' ), 9, 2 );

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

		// Handle publish transitions
		add_action( 'transition_post_status', array( $this, 'transition_post_status' ), 10, 3 );

		// Handle post saves
		add_action( 'save_post', array( $this, 'reset_cache_key_salt' ), 10, 2 );

	}

	/**
	 * Get post types that support Cache WP_Query
	 */
	public function get_supported_post_types() {

		$post_types = get_post_types( array(), 'names' );

		foreach ( $post_types as $post_type ) {
			if ( post_type_supports( $post_type, 'cache_wp_query' ) ) {
				$this->post_types[] = $post_type;
			}
		}

	}

	/**
	 * @param boolean   $skip
	 * @param \WP_Query $query
	 */
	public function filter_ep_skip_query_integration( $skip, $query ) {

		if ( ! $skip && false !== apply_filters( 'cache_wp_query_elasticpress', true ) && $query ) {
			$cache_key = $this->get_cache_key();

			if ( ! empty( $cache_key ) ) {
				$cached_posts = $this->get_posts();

				if ( ! empty( $cached_posts ) ) {
					$skip = true;
				}
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

		$this->setup_query( $query );

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

					$query->found_posts   = $this->found_posts;
					$query->max_num_pages = $this->max_num_pages;
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
	 * Clear caches on post publish
	 *
	 * @param string  $old_status
	 * @param string  $new_status
	 * @param WP_Post $post
	 */
	public function transition_post_status( $old_status, $new_status, $post ) {

		static $flushed;

		if ( ! empty( $flushed ) ) {
			return;
		}

		if ( $post && in_array( $post->post_type, $this->post_types ) && 'publish' === $new_status && $old_status !== $new_status ) {
			$this->reset_cache_key_salt( $post->ID, $post );

			// Flush Pods Caches
			if ( function_exists( 'pods_api' ) ) {
				pods_api()->cache_flush_pods();
			}

			$flushed = true;
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

		if ( ! empty( $cache_key ) && empty( $_REQUEST['bypass_cache_wp_query'] ) ) {
			$posts = $this->cached_posts;

			if ( null === $posts ) {
				$posts = $this->cache_get( $cache_key, 'cache-wp-query' );

				if ( is_array( $posts ) && ! empty( $posts ) ) {
					$posts = array_map( 'get_post', $posts );
				}

				if ( ! is_array( $posts ) || empty( $posts ) ) {
					$posts = array();

					$this->cached_posts = null;
				} else {
					$this->cached_posts = $posts;
				}

				if ( ! empty( $posts ) ) {
					$this->get_meta();
				}
			}
		}

		return $posts;

	}

	/**
	 * Set posts to cache
	 *
	 * @param array     $posts
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

				if ( ! empty( $posts_to_cache ) ) {
					$this->cache_set( $cache_key, $posts_to_cache, 'cache-wp-query', 30 * MINUTE_IN_SECONDS );

					$this->cached_posts = $posts;
				} else {
					$posts = array();
				}
			}

			if ( empty( $posts ) ) {
				$this->cache_clear( $cache_key, 'cache-wp-query' );

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

		if ( empty( $this->found_posts ) && empty( $this->max_num_pages ) && ! empty( $cache_key ) && empty( $_REQUEST['bypass_cache_wp_query'] ) ) {
			$posts = $this->cached_posts;

			if ( ! empty( $posts ) ) {
				$meta = $this->cache_get( $cache_key . '_meta', 'cache-wp-query' );

				if ( is_array( $meta ) && ! empty( $meta ) ) {
					$this->found_posts   = $meta['found_posts'];
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
				$this->found_posts   = $query->found_posts;
				$this->max_num_pages = $query->max_num_pages;

				$meta = array(
					'found_posts'   => $this->found_posts,
					'max_num_pages' => $this->max_num_pages,
				);

				$this->cache_set( $cache_key . '_meta', $meta, 'cache-wp-query', 30 * MINUTE_IN_SECONDS );
			} else {
				$this->cache_clear( $cache_key . '_meta', 'cache-wp-query' );

				$this->found_posts   = null;
				$this->max_num_pages = null;
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

			if ( ! empty( $this->query_vars ) && empty( $this->query_vars['cache_wp_query_skip'] ) ) {
				$cache_key = md5( serialize( $this->query_vars ) );

				$cache_key_salt = get_option( 'cache_wp_query_salt' );

				if ( $cache_key_salt ) {
					$cache_key .= '_' . $cache_key_salt;
				}
			}

			$this->cache_key = $cache_key;
		}

		return $cache_key;

	}

	/**
	 * Reset cache key salt on saving of a post
	 *
	 * @param int     $post_id
	 * @param WP_Post $post
	 */
	public function reset_cache_key_salt( $post_id, $post ) {

		if ( ! in_array( $post->post_type, $this->post_types ) ) {
			return;
		}

		delete_option( 'cache_wp_query_salt' );
		add_option( 'cache_wp_query_salt', time(), '', 'yes' );

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
	 * Setup query for caching
	 *
	 * @param \WP_Query $query
	 */
	public function setup_query( $query ) {

		$this->reset();

		$cache = false;

		if ( is_search() && ! empty( $query->query_vars['s'] ) && false !== apply_filters( 'cache_wp_query_search', true ) ) {
			$cache = true;
		} elseif ( ! empty( $query->query_vars['post_type'] ) ) {
			$cache = false;

			if ( ! empty( $query->query_vars['cache_wp_query'] ) ) {
				// Manual integration
				$cache = true;
			} elseif ( ! empty( $query->query_vars['ep_integrate'] ) && false !== apply_filters( 'cache_wp_query_elasticpress', true ) ) {
				// ElasticPress integration
				$cache = true;
			}

			// Check that all post types in query are supported for caching
			if ( $cache ) {
				$post_types = (array) $query->query_vars['post_type'];

				$cache = true;

				foreach ( $post_types as $post_type ) {
					if ( ! in_array( $post_type, $this->post_types ) ) {
						$cache = false;

						break;
					}
				}
			}
		}

		if ( $cache ) {
			$this->query_vars = $query->query_vars;

			$this->get_posts();
		}

	}

	/**
	 * Get value from cache
	 *
	 * @param string $key   Cache key
	 * @param string $group Cache group
	 *
	 * @return mixed
	 */
	public function cache_get( $key, $group ) {

		$value = null;

		if ( function_exists( 'pods_cache_get' ) ) {
			$value = pods_cache_get( $key, $group );
		} else {
			$value = wp_cache_get( $key, $group );
		}

		return $value;

	}

	/**
	 * Set cache value
	 *
	 * @param string   $key     Cache key
	 * @param mixed    $value   Cache value
	 * @param string   $group   Cache group
	 * @param int|null $expires Cache expiration
	 */
	public function cache_set( $key, $value, $group, $expires ) {

		if ( function_exists( 'pods_cache_set' ) ) {
			pods_cache_set( $key, $value, $group, $expires );
		} else {
			wp_cache_set( $key, $value, $group, $expires );
		}

	}

	/**
	 * Clear cache
	 *
	 * @param string $key   Cache key
	 * @param string $group Cache group
	 */
	public function cache_clear( $key, $group ) {

		if ( function_exists( 'pods_cache_clear' ) ) {
			pods_cache_clear( $key, $group );
		} else {
			wp_cache_delete( $key, $group );
		}

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

			add_action( 'init', array( $instance, 'setup' ), 99 );
		}

		return $instance;

	}
}

Cache_WP_Query::factory();
