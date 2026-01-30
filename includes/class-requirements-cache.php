<?php
/**
 * High-Performance Requirements Cache for Samybaxy's Hyperdrive
 *
 * This class maintains a pre-computed lookup table for plugin requirements.
 * All analysis happens on post save, so runtime lookups are O(1).
 *
 * The lookup table is stored as a single serialized option for fast retrieval.
 * The MU-loader does ONE database query and ONE hash lookup per request.
 *
 * @package SamybaxyHyperdrive
 */

if (!defined('ABSPATH')) {
    exit;
}

class SHYPDR_Requirements_Cache {

    /**
     * Option name for the lookup table
     */
    const LOOKUP_TABLE_OPTION = 'shypdr_url_requirements';

    /**
     * Option name for post type requirements
     */
    const POST_TYPE_OPTION = 'shypdr_post_type_requirements';

    /**
     * Get the full lookup table (for MU-loader to fetch once per request)
     *
     * @return array URL slug => plugins mapping
     */
    public static function get_lookup_table() {
        return get_option(self::LOOKUP_TABLE_OPTION, []);
    }

    /**
     * Get post type requirements
     *
     * @return array Post type => plugins mapping
     */
    public static function get_post_type_requirements() {
        return get_option(self::POST_TYPE_OPTION, self::get_default_post_type_requirements());
    }

    /**
     * Update requirements for a single page/post
     *
     * @param int $post_id Post ID
     * @return bool Success
     */
    public static function update_post_requirements($post_id) {
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish' || empty($post->post_name)) {
            return false;
        }

        // Skip revisions and autosaves
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return false;
        }

        // Analyze the post
        if (!class_exists('SHYPDR_Content_Analyzer')) {
            require_once dirname(__FILE__) . '/class-content-analyzer.php';
        }

        $required = SHYPDR_Content_Analyzer::analyze_post($post_id);

        // Update the lookup table atomically
        $table = get_option(self::LOOKUP_TABLE_OPTION, []);
        $table[$post->post_name] = $required;

        // Also store by ID for numeric lookups
        $table['id:' . $post_id] = $required;

        // If it's a page, also store by full path
        if ($post->post_type === 'page') {
            $path = get_page_uri($post);
            if ($path && $path !== $post->post_name) {
                $table['path:' . $path] = $required;
            }
        }

        return update_option(self::LOOKUP_TABLE_OPTION, $table, false);
    }

    /**
     * Remove requirements for a deleted post
     *
     * @param int $post_id Post ID
     * @return bool Success
     */
    public static function remove_post_requirements($post_id) {
        $post = get_post($post_id);

        $table = get_option(self::LOOKUP_TABLE_OPTION, []);

        // Remove by slug
        if ($post && !empty($post->post_name)) {
            unset($table[$post->post_name]);
        }

        // Remove by ID
        unset($table['id:' . $post_id]);

        // Remove path entries
        foreach ($table as $key => $value) {
            if (strpos($key, 'path:') === 0) {
                // Check if this path entry belongs to this post
                $path = substr($key, 5);
                $page_id = url_to_postid('/' . $path);
                if ($page_id === $post_id) {
                    unset($table[$key]);
                }
            }
        }

        return update_option(self::LOOKUP_TABLE_OPTION, $table, false);
    }

    /**
     * Rebuild the entire lookup table
     * Call this during plugin activation or after bulk changes
     *
     * @return int Number of pages processed
     */
    public static function rebuild_lookup_table() {
        global $wpdb;

        if (!class_exists('SHYPDR_Content_Analyzer')) {
            require_once dirname(__FILE__) . '/class-content-analyzer.php';
        }

        $table = [];
        $count = 0;

        // Get all published posts and pages
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only bulk rebuild operation, cache is being built
        $posts = $wpdb->get_results(
            "SELECT ID, post_name, post_type
             FROM {$wpdb->posts}
             WHERE post_status = 'publish'
             AND post_name != ''
             AND post_type IN ('post', 'page', 'product', 'lp_course')
             ORDER BY ID ASC
             LIMIT 500",
            ARRAY_A
        );

        foreach ($posts as $post_data) {
            $required = SHYPDR_Content_Analyzer::analyze_post($post_data['ID']);

            if (!empty($required)) {
                $table[$post_data['post_name']] = $required;
                $table['id:' . $post_data['ID']] = $required;
                $count++;
            }
        }

        // Add WooCommerce pages explicitly
        $woo_pages = ['shop', 'cart', 'checkout', 'my-account'];
        $woo_plugins = ['woocommerce', 'woocommerce-stripe-gateway'];
        foreach ($woo_pages as $page) {
            if (!isset($table[$page])) {
                $table[$page] = $woo_plugins;
            }
        }

        update_option(self::LOOKUP_TABLE_OPTION, $table, false);

        return $count;
    }

    /**
     * Get default post type to plugin requirements
     *
     * @return array Post type => plugins mapping
     */
    public static function get_default_post_type_requirements() {
        return [
            'product' => ['woocommerce', 'jet-woo-builder', 'jet-woo-product-gallery'],
            'product_cat' => ['woocommerce'],
            'product_tag' => ['woocommerce'],
            'lp_course' => ['learnpress'],
            'lp_lesson' => ['learnpress'],
            'lp_quiz' => ['learnpress'],
            'jet-popup' => ['jet-popup', 'jet-engine'],
            'jet-menu' => ['jet-menu', 'jet-engine'],
            'jet-smart-filters' => ['jet-smart-filters', 'jet-engine'],
            'tribe_events' => ['the-events-calendar'],
            'forum' => ['bbpress'],
            'topic' => ['bbpress'],
        ];
    }

    /**
     * Get requirements for a specific post type
     *
     * @param string $post_type Post type slug
     * @return array Required plugins
     */
    public static function get_for_post_type($post_type) {
        $requirements = self::get_post_type_requirements();
        return $requirements[$post_type] ?? [];
    }

    /**
     * Get requirements for a URL (fast O(1) lookup)
     *
     * @param string $url_slug URL slug to lookup
     * @return array Required plugins
     */
    public static function get_for_slug($url_slug) {
        $table = self::get_lookup_table();
        return $table[$url_slug] ?? [];
    }

    /**
     * Get cache statistics
     *
     * @return array Statistics
     */
    public static function get_stats() {
        $table = self::get_lookup_table();
        $size = strlen(serialize($table));

        return [
            'total_entries' => count($table),
            'size_bytes' => $size,
            'size_kb' => round($size / 1024, 2),
        ];
    }

    /**
     * Clear the entire cache
     */
    public static function clear() {
        delete_option(self::LOOKUP_TABLE_OPTION);
    }
}
