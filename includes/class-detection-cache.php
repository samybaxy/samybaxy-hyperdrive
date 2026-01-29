<?php
/**
 * Detection Result Caching for Samybaxy Hyperdrive
 *
 * Caches plugin detection results to avoid redundant analysis
 * on repeated URL patterns and post content.
 *
 * @package SamybaxyHyperdrive
 */

if (!defined('ABSPATH')) {
    exit;
}

class SHYPDR_Detection_Cache {

    /**
     * Cache group for URL pattern caching
     */
    const URL_CACHE_GROUP = 'shypdr_url_detection';

    /**
     * Cache group for content scanning
     */
    const CONTENT_CACHE_GROUP = 'shypdr_content_detection';

    /**
     * Cache expiration time (1 hour)
     */
    const CACHE_EXPIRATION = 3600;

    /**
     * Get cached detection result for URL pattern
     *
     * @param string $url Request URI
     * @return array|false Cached detection result or false
     */
    public static function get_url_detection($url) {
        $cache_key = self::generate_url_cache_key($url);

        // Try object cache first (Redis, Memcached)
        if (wp_using_ext_object_cache()) {
            $cached = wp_cache_get($cache_key, self::URL_CACHE_GROUP);
            if ($cached !== false) {
                return $cached;
            }
        }

        // Fall back to transient
        return get_transient($cache_key);
    }

    /**
     * Set cached detection result for URL pattern
     *
     * @param string $url Request URI
     * @param array $detected Array of detected plugins
     * @return bool Success
     */
    public static function set_url_detection($url, $detected) {
        $cache_key = self::generate_url_cache_key($url);

        // Save to object cache if available
        if (wp_using_ext_object_cache()) {
            wp_cache_set($cache_key, $detected, self::URL_CACHE_GROUP, self::CACHE_EXPIRATION);
        }

        // Also save to transient for persistence
        return set_transient($cache_key, $detected, self::CACHE_EXPIRATION);
    }

    /**
     * Get cached content scan result for post
     *
     * @param int $post_id Post ID
     * @return array|false Cached scan result or false
     */
    public static function get_content_scan($post_id) {
        // Check post meta first (persistent)
        $cached = get_post_meta($post_id, '_shypdr_required_plugins', true);

        if (!empty($cached) && is_array($cached)) {
            // Check if cache is still valid (not older than 1 week)
            $cached_time = get_post_meta($post_id, '_shypdr_cache_time', true);

            if ($cached_time && (current_time('timestamp') - $cached_time) < WEEK_IN_SECONDS) {
                return $cached;
            }
        }

        return false;
    }

    /**
     * Set cached content scan result for post
     *
     * @param int $post_id Post ID
     * @param array $detected Array of detected plugins
     * @return bool Success
     */
    public static function set_content_scan($post_id, $detected) {
        update_post_meta($post_id, '_shypdr_required_plugins', $detected);
        update_post_meta($post_id, '_shypdr_cache_time', current_time('timestamp'));
        return true;
    }

    /**
     * Generate cache key for URL pattern
     *
     * @param string $url Request URI
     * @return string Cache key
     */
    private static function generate_url_cache_key($url) {
        // Normalize URL (remove query params and hash)
        $url = strtok($url, '?');
        $url = strtok($url, '#');

        // Generate short hash
        return 'shypdr_url_' . md5($url);
    }

    /**
     * Clear all URL detection caches
     */
    public static function clear_url_cache() {
        global $wpdb;

        // Clear transients
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk cache deletion, more efficient than looping delete_transient()
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_shypdr_url_%'");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk cache deletion, more efficient than looping delete_transient()
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_shypdr_url_%'");

        // Clear object cache if available
        if (wp_using_ext_object_cache()) {
            wp_cache_flush_group(self::URL_CACHE_GROUP);
        }
    }

    /**
     * Clear content scan cache for specific post
     *
     * @param int $post_id Post ID
     */
    public static function clear_post_cache($post_id) {
        delete_post_meta($post_id, '_shypdr_required_plugins');
        delete_post_meta($post_id, '_shypdr_cache_time');
    }

    /**
     * Clear all content scan caches
     */
    public static function clear_all_content_cache() {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk cache deletion, more efficient than looping delete_post_meta()
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_shypdr_required_plugins'");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk cache deletion, more efficient than looping delete_post_meta()
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_shypdr_cache_time'");
    }

    /**
     * Clear all caches (URL + Content)
     */
    public static function clear_all_caches() {
        self::clear_url_cache();
        self::clear_all_content_cache();
    }

    /**
     * Get cache statistics
     *
     * @return array Cache stats
     */
    public static function get_cache_stats() {
        global $wpdb;

        // Count URL cache entries
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Statistics query, no WP function available for counting transients
        $url_cache_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_shypdr_url_%'"
        );

        // Count content cache entries
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Statistics query, no WP function available for counting postmeta
        $content_cache_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_shypdr_required_plugins'"
        );

        // Calculate approximate cache size
        $cache_size = ($url_cache_count * 500) + ($content_cache_count * 300); // Bytes estimate

        return [
            'url_cache_entries' => (int) $url_cache_count,
            'content_cache_entries' => (int) $content_cache_count,
            'total_entries' => (int) ($url_cache_count + $content_cache_count),
            'estimated_size_kb' => round($cache_size / 1024, 2),
            'using_object_cache' => wp_using_ext_object_cache()
        ];
    }
}
