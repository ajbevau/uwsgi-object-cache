<?php
/**
 * Plugin Name: uWSGI Object Cache
 * Plugin URI: https://github.com/andrewbevitt/uwsgi-object-cache
 * Description: WordPress Object Cache built on top of uWSGI.
 * Version: 1.0.1
 * Author: Andrew Bevitt
 * Author URI: http://andrewbevitt.com/
 * License: GPL2
 */

/* WARNINGS:
 *  1. This plugin requires manual install!
 *  2. There is a bug in uWSGI so you need to include this:
 *     https://github.com/unbit/uwsgi/pull/660
 * 
 * This is similar to most of the other persistent object cache implementations.
 * It defines the WP_Object_Cache class (see below) and then calls object methods
 * for each of the cache operations. The uWSGI cache name should be set with
 *   define( 'UWSGI_CACHE', '...' );
 * in your wp-config.php file.
 *
 * To install copy this file to WP_CONTENT_DIR/object-cache.php
 * 
 * NOTE: This only provides object caching, for page caching use batcache:
 *  1. Install batcache plugin and advanced-cache.php
 *  2. Add the following to wp-config.php
 *      $batcache = array('group'=>UWSGI_CACHE, 'remote'=>1);
 *     You should also consider 'debug'=>false once you're happy.
 *
 * http://codex.wordpress.org/Class_Reference/WP_Object_Cache
 *   $key: the key to indicate the value.
 *   $data: the value you want to store.
 *   $group: (optional) this is a way of grouping data within the cache.
 *           Allows you to use the same key across different groups.
 *   $expire: (optional) this defines how many seconds to keep the cache for.
 *            Only applicable to some functions. Defaults to 0 (as long as possible). 
 *   $offset: amount by which to increment/decrement the value (default is 1).
 */


function wp_cache_add( $key, $data, $group = UWSGI_CACHE, $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->add( $key, $data, $group, (int) $expire );
}

// This always returns TRUE as the uWSGI cache doesn't need to be closed
function wp_cache_close() { return true; }

function wp_cache_decr( $key, $offset = 1, $group = UWSGI_CACHE ) {
	global $wp_object_cache;
	return $wp_object_cache->decr( $key, $offset, $group );
}

function wp_cache_delete( $key, $group = UWSGI_CACHE ) {
	global $wp_object_cache;
	return $wp_object_cache->delete($key, $group);
}

function wp_cache_flush() {
	global $wp_object_cache;
	return $wp_object_cache->flush();
}

function wp_cache_get( $key, $group = UWSGI_CACHE, $force = false, &$found = null ) {
	global $wp_object_cache;
	return $wp_object_cache->get( $key, $group, $force, $found );
}

function wp_cache_incr( $key, $offset = 1, $group = UWSGI_CACHE ) {
	global $wp_object_cache;
	return $wp_object_cache->incr( $key, $offset, $group );
}

function wp_cache_init() {
	$GLOBALS['wp_object_cache'] = new WP_Object_Cache();
}

function wp_cache_replace( $key, $data, $group = UWSGI_CACHE, $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->replace( $key, $data, $group, (int) $expire );
}

function wp_cache_set( $key, $data, $group = UWSGI_CACHE, $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->set( $key, $data, $group, (int) $expire );
}

function wp_cache_switch_to_blog( $blog_id ) {
	global $wp_object_cache;
	return $wp_object_cache->switch_to_blog( $blog_id );
}

function wp_cache_add_global_groups( $groups ) {
	global $wp_object_cache;
	return $wp_object_cache->add_global_groups( $groups );
}

// Non-persistent object should by cached internally by the WP_Object_Cache
// and not pushed to the uWSGI cache - this is done using an array.
function wp_cache_add_non_persistent_groups( $groups ) {
	global $wp_object_cache;
	return $wp_object_cache->add_non_persistent_groups( $groups );
}


/**
 * uWSGI Object Cache for WordPress.
 *
 * https://uwsgi-docs.readthedocs.org/en/latest/PHP.html#uwsgi-api-support
 *
 * The idea here is to provide an object cache within the application server
 * which is running PHP. This will save on trips to the database and for the
 * themes/plugins which support the Transients API, better fragment caching.
 */
class WP_Object_Cache {

	var $cache = array ();
	var $cache_hits = 0;
	var $cache_misses = 0;
	var $groups = array();
	var $blog_prefix;

	/**
	 * Wrapper around the uwsgi_cache_X functions to serialize and unserialize
	 * values before and after caching. The uWSGI cache always returns as a
	 * string otherwise.
	 * TODO
	 */
	private function _su( $which, $key, $dataRaw, $group, $expire ) {
		if ( ! array_key_exists( $group, $this->groups ) )
			return FALSE;
		if ( is_object( $dataRaw ) )
			$dataRaw = clone $dataRaw;
		if ( ! $this->groups[ $group ] ) {
			$this->cache[ $key ] = $dataRaw;
			return TRUE;
		} else {
			$data = serialize( $dataRaw );
			if ( $which == 'set' )
				return ( uwsgi_cache_set( $key, $data, $expire, $group ) ) ? TRUE : FALSE;
			elseif ( $which == 'update' )
				return ( uwsgi_cache_update( $key, $data, $expire, $group ) ) ? TRUE : FALSE;
		}
	}
	private function _set( $key, $dataRaw, $group, $expire ) {
		return $this->_su( 'set', $key, $dataRaw, $group, $expire );
	}
	private function _update( $key, $dataRaw, $group, $expire ) {
		return $this->_su( 'update', $key, $dataRaw, $group, $expire );
	}
	private function _get( $key, $group, &$found=FALSE ) {
		if ( ! array_key_exists( $group, $this->groups ) )
			return NULL;
		if ( ! $this->groups[ $group ] ) {
			if ( array_key_exists( $key, $this->cache ) ) {
				$found = TRUE;
				$output = $this->cache[ $key ];
				if ( is_object( $output ) )
					$output = clone $output;
				return $output;
			}
		} else {
			if ( ! is_null( $data = uwsgi_cache_get( $key, $group ) ) ) {
				$found = TRUE;
				$output = unserialize( $data );
				if ( is_object( $output ) )
					$output = clone $output;
				return $output;
			}
		}
		$found = FALSE;
		return NULL;
	}

	/**
	 * Utility function to determine whether a key exists in the cache.
	 *
	 * TODO
	 * @access private
	 */
	private function _exists( $key, $group ) {
		if ( ! array_key_exists( $group, $this->groups ) )
			return false;
		if ( ! $this->groups[ $group ] )
			return ( array_key_exists( $key, $this->cache ) ) ? TRUE : FALSE;
		return ( uwsgi_cache_exists( $key, $group ) ) ? TRUE : FALSE;
	}

	/**
	 * Modifies the key to ensure no collisions where using multi-site,
	 * sharing wp-config.php files or table prefixes.
	 *
	 * @param string $key The original object key
	 * @param string $group The uWSGI cache name to store in
	 * @return string The derived key for this object
	 * @access private
	 */
	private function _key( $key, $group = UWSGI_CACHE ) {
		$id = $key;
		if ( $this->multisite && ( ! ( isset( $this->groups[ $group ] ) && $this->groups[ $group ] ) ) )
			$id = $this->blog_prefix . $key;
		return $id;
	}

	/**
	 * TODO
	 */
	private function _sanitize_group( $group ) {
		if ( empty( $group ) )
			$group = UWSGI_CACHE;
		if ( ! array_key_exists( $group, $this->groups ) )
			$this->groups[ $group ] = false;
		return $group;
	}

	/**
	 * Adds data to the cache if it doesn't already exist.
	 *
	 * @uses WP_Object_Cache::_exists Checks to see if the cache already has data.
	 * @uses WP_Object_Cache::set Sets the data after the checking the cache
	 *		contents existence.
	 *
	 * @param int|string $key What to call the contents in the cache
	 * @param mixed $data The contents to store in the cache
	 * @param string $group The uWSGI cache name to store in
	 * @param int $expire When to expire the cache contents
	 * @return bool False if cache key and group already exist, true on success
	 */
	function add( $key, $data, $group = UWSGI_CACHE, $expire = 0 ) {
		if ( function_exists( 'wp_suspend_cache_addition' ) && wp_suspend_cache_addition() )
			return false;

		$group = $this->_sanitize_group( $group );
		$id = $this->_key( $key, $group );
		if ( $this->_exists( $id, $group ) )
				return false;
		return $this->_set( $key, $data, $group, (int) $expire );
	}

	/**
	 * Sets the list of global groups.
	 *
	 * @param array $groups List of groups that are global.
	 */
	function add_global_groups( $groups ) {
		$groups = (array) $groups;
		$groups = array_fill_keys( $groups, true );
		$this->groups = array_merge( $this->groups, $groups );
	}

	/**
	 * Sets which groups should not go to uWSGI but should stay in memory.
	 * TODO
	 */
	function add_non_persistent_groups( $groups ) {
		$groups = (array) $groups;
		$groups = array_fill_keys( $groups, false );
		$this->groups = array_merge( $this->groups, $groups );
	}

	/**
	 * Decrement numeric cache item's value
	 *
	 * @param int|string $key The cache key to increment
	 * @param int $offset The amount by which to decrement the item's value. Default is 1.
	 * @param string $group The group the key is in.
	 * @return false|int False on failure, the item's new value on success.
	 */
	function decr( $key, $offset = 1, $group = UWSGI_CACHE ) {
		$group = $this->_sanitize_group( $group );
		$key = $this->_key( $key, $group );
		if ( ! $this->_exists( $key, $group ) )
			return false;

		if ( ! is_numeric( $oldValue = $this->_get( $key, $group ) ) )
			$oldValue = 0;

		$offset = (int) $offset;
		$newValue = ( $oldValue > $offset ) ? $oldValue - $offset : 0;
		if ( ! $this->_update( $key, $newValue, $group, 0 ) )
			return FALSE;
		return $newValue;
	}

	/**
	 * Remove the contents of the cache key from the uWSGI cache.
	 *
	 * If the cache key does not exist in the group, then nothing will happen.
	 *
	 * @param int|string $key What the contents in the cache are called
	 * @param string $group Where the cache contents are grouped
	 * @param bool $deprecated Deprecated.
	 * @return bool False if the contents weren't deleted and true on success
	 */
	function delete( $key, $group = UWSGI_CACHE, $deprecated = false ) {
		$group = $this->_sanitize_group( $group );
		$key = $this->_key( $key, $group );
        if ( ! $this->groups[ $group ] ) {
            if ( array_key_exists( $key, $this->cache ) ) {
                unset( $this->cache[ $key ] );
                return TRUE;
            }
        } else {
            if ( uwsgi_cache_del( $key, $group ) ) {
                return TRUE;
            }
        }
        return FALSE;
	}

	/**
	 * Clears the object cache of all data
	 *
	 * @since 2.0.0
	 *
	 * @return bool Always returns true
	 */
	function flush() {
		$this->cache = array ();
		// TODO: uwsgi_cache_clear( $group ??? )
		return true;
	}

	/**
	 * Retrieves the cache contents, if it exists
	 *
	 * The contents will be first attempted to be retrieved by searching by the
	 * key in the cache group. If the cache is hit (success) then the contents
	 * are returned.
	 *
	 * On failure, the number of cache misses will be incremented.
	 *
	 * @since 2.0.0
	 *
	 * @param int|string $key What the contents in the cache are called
	 * @param string $group Where the cache contents are grouped
	 * @param string $force Whether to force a refetch rather than relying on the local cache (default is false)
	 * @return bool|mixed False on failure to retrieve contents or the cache
	 *		contents on success
	 */
	function get( $key, $group = UWSGI_CACHE, $force = false, &$found = null ) {
		$group = $this->_sanitize_group( $group );
		$key = $this->_key( $key, $group );

		if ( $this->_exists( $key, $group ) ) {
			$found = true;
			$this->cache_hits += 1;
			$value = $this->_get( $key, $group );
			if ( is_object( $value ) )
				return clone $value;
			else
				return $value;
		}

		$found = false;
		$this->cache_misses += 1;
		return false;
	}

	/**
	 * Increment numeric cache item's value
	 *
	 * @since 3.3.0
	 *
	 * @param int|string $key The cache key to increment
	 * @param int $offset The amount by which to increment the item's value. Default is 1.
	 * @param string $group The group the key is in.
	 * @return false|int False on failure, the item's new value on success.
	 */
	function incr( $key, $offset = 1, $group = UWSGI_CACHE ) {
        $group = $this->_sanitize_group( $group );
        $key = $this->_key( $key, $group );
        if ( ! $this->_exists( $key, $group ) )
            return false;

        if ( ! is_numeric( $oldValue = $this->_get( $key, $group ) ) )
            $oldValue = 0;

        $offset = (int) $offset;
        $newValue = ( $oldValue + $offset > 0 ) ? $oldValue + $offset : 0;
        if ( ! $this->_update( $key, $newValue, $group, 0 ) )
            return FALSE;
        return $newValue;
	}

	/**
	 * Replace the contents in the cache, if contents already exist
	 *
	 * @since 2.0.0
	 * @see WP_Object_Cache::set()
	 *
	 * @param int|string $key What to call the contents in the cache
	 * @param mixed $data The contents to store in the cache
	 * @param string $group Where to group the cache contents
	 * @param int $expire When to expire the cache contents
	 * @return bool False if not exists, true if contents were replaced
	 */
	function replace( $key, $data, $group = UWSGI_CACHE, $expire = 0 ) {
		$group = $this->_sanitize_group( $group );
		$id = $this->_key( $key, $group );
		if ( ! $this->_exists( $id, $group ) )
			return false;
		return $this->_update( $key, $data, $group, (int) $expire );
	}

	/**
	 * Sets the data contents into the cache
	 *
	 * @param int|string $key What to call the contents in the cache
	 * @param mixed $data The contents to store in the cache
	 * @param string $group Where to group the cache contents
	 * @param int $expire When to expire the cache contents
	 * @return bool Result of the cache update operation
	 */
	function set( $key, $data, $group = UWSGI_CACHE, $expire = 0 ) {
		$group = $this->_sanitize_group( $group );
		$key = $this->_key( $key, $group );
		return $this->_update( $key, $data, $group, (int) $expire );
	}

	/**
	 * Echoes the stats of the caching.
	 */
	function stats() {
		echo "<p>";
		echo "<strong>Cache Hits:</strong> {$this->cache_hits}<br />";
		echo "<strong>Cache Misses:</strong> {$this->cache_misses}<br />";
		echo "</p>";
	}

	/**
	 * Switch the interal blog id.
	 *
	 * This changes the blog id used to create keys in blog specific groups.
	 *
	 * @since 3.5.0
	 *
	 * @param int $blog_id Blog ID
	 */
	function switch_to_blog( $blog_id ) {
		$blog_id = (int) $blog_id;
		$this->blog_prefix = $this->multisite ? $blog_id . ':' : '';
	}

	/**
	 * Sets up object properties; PHP 5 style constructor
	 *
	 * @since 2.0.8
	 * @return null|WP_Object_Cache If cache is disabled, returns null.
	 */
	function __construct() {
		global $blog_id;

		$this->multisite = is_multisite();
		$this->blog_prefix =  $this->multisite ? $blog_id . ':' : '';
	}

	/**
	 * Will save the object cache before object is completely destroyed.
	 *
	 * Called upon object destruction, which should be when PHP ends.
	 *
	 * @since  2.0.8
	 *
	 * @return bool True value. Won't be used by PHP
	 */
	function __destruct() {
		return true;
	}
}

