<?php
/**
 * Content Analyzer for Samybaxy Hyperdrive
 *
 * Intelligently detects which plugins are required for each page by analyzing:
 * - Post content (shortcodes, blocks)
 * - Elementor widget data
 * - Page builder elements
 * - Post type and taxonomy context
 *
 * @package SamybaxyHyperdrive
 */

if (!defined('ABSPATH')) {
    exit;
}

class SHYPDR_Content_Analyzer {

    /**
     * Shortcode to plugin mapping
     */
    private static $shortcode_map = [
        // WooCommerce
        'woocommerce_cart' => 'woocommerce',
        'woocommerce_checkout' => 'woocommerce',
        'woocommerce_my_account' => 'woocommerce',
        'woocommerce_order_tracking' => 'woocommerce',
        'products' => 'woocommerce',
        'product' => 'woocommerce',
        'product_page' => 'woocommerce',
        'product_category' => 'woocommerce',
        'product_categories' => 'woocommerce',
        'add_to_cart' => 'woocommerce',
        'add_to_cart_url' => 'woocommerce',
        'shop_messages' => 'woocommerce',
        'recent_products' => 'woocommerce',
        'sale_products' => 'woocommerce',
        'best_selling_products' => 'woocommerce',
        'top_rated_products' => 'woocommerce',
        'featured_products' => 'woocommerce',
        'related_products' => 'woocommerce',

        // LearnPress
        'learn_press_profile' => 'learnpress',
        'learn_press_become_teacher_form' => 'learnpress',
        'learn_press_checkout' => 'learnpress',
        'learn_press_courses' => 'learnpress',
        'learn_press_popular_courses' => 'learnpress',
        'learn_press_featured_courses' => 'learnpress',
        'learn_press_recent_courses' => 'learnpress',

        // Fluent Forms
        'fluentform' => 'fluentform',
        'fluentform_modal' => 'fluentform',
        'fluentform_info' => 'fluentform',

        // JetFormBuilder
        'jet_fb_form' => 'jetformbuilder',

        // Contact Form 7
        'contact-form-7' => 'contact-form-7',
        'contact-form' => 'contact-form-7',

        // Restrict Content Pro
        'register_form' => 'restrict-content-pro',
        'login_form' => 'restrict-content-pro',
        'rcp_registration_form' => 'restrict-content-pro',
        'rcp_login_form' => 'restrict-content-pro',
        'restrict' => 'restrict-content-pro',

        // AffiliateWP
        'affiliate_area' => 'affiliatewp',
        'affiliate_login' => 'affiliatewp',
        'affiliate_registration' => 'affiliatewp',
        'affiliate_referral_url' => 'affiliatewp',
        'affiliate_creatives' => 'affiliatewp',

        // FluentCRM
        'fluentcrm_forms' => 'fluent-crm',
        'fluentcrm_subscriber_info' => 'fluent-crm',

        // JetEngine
        'jet_engine' => 'jet-engine',
        'jet_listing_grid' => 'jet-engine',
        'jet_listing' => 'jet-engine',

        // JetSmartFilters
        'jet_smart_filters' => 'jet-smart-filters',

        // JetSearch
        'jet_ajax_search' => 'jet-search',

        // JetTabs
        'jet_tabs' => 'jet-tabs',

        // JetPopup
        'jet_popup' => 'jet-popup',

        // JetCompareWishlist
        'jet_compare' => 'jet-compare-wishlist',
        'jet_wishlist' => 'jet-compare-wishlist',

        // JetReviews
        'jet_reviews' => 'jet-reviews',

        // JetBlog
        'jet_blog_posts' => 'jet-blog',
        'jet_blog_smart_posts' => 'jet-blog',

        // Gallery plugins
        'gallery' => null, // Standard WP gallery
        'envira-gallery' => 'envira-gallery-lite',
        'foo-gallery' => 'foogallery',

        // Presto Player
        'presto_player' => 'presto-player',
        'presto_video' => 'presto-player',
        'presto-player' => 'presto-player',

        // EmbedPress
        'embedpress' => 'embedpress',
        'embed_press' => 'embedpress',
    ];

    /**
     * Elementor widget to plugin mapping
     */
    private static $elementor_widget_map = [
        // WooCommerce widgets
        'woocommerce-products' => 'woocommerce',
        'woocommerce-product-add-to-cart' => 'woocommerce',
        'wc-add-to-cart' => 'woocommerce',
        'wc-categories' => 'woocommerce',
        'wc-products' => 'woocommerce',
        'woocommerce-cart' => 'woocommerce',
        'woocommerce-checkout' => 'woocommerce',
        'woocommerce-my-account' => 'woocommerce',
        'woocommerce-purchase-summary' => 'woocommerce',

        // JetWooBuilder widgets
        'jet-woo' => 'jet-woo-builder',
        'jet-single-' => 'jet-woo-builder', // Prefix match
        'jet-cart-' => 'jet-woo-builder',
        'jet-checkout-' => 'jet-woo-builder',

        // JetEngine widgets
        'jet-listing-grid' => 'jet-engine',
        'jet-listing-dynamic-field' => 'jet-engine',
        'jet-listing-dynamic-image' => 'jet-engine',
        'jet-listing-dynamic-link' => 'jet-engine',
        'jet-listing-dynamic-meta' => 'jet-engine',
        'jet-listing-dynamic-terms' => 'jet-engine',
        'jet-listing-dynamic-repeater' => 'jet-engine',
        'jet-engine-maps-listing' => 'jet-engine',
        'jet-engine-booking-form' => 'jet-engine',

        // JetElements widgets
        'jet-elements' => 'jet-elements',
        'jet-animated-' => 'jet-elements',
        'jet-carousel' => 'jet-elements',
        'jet-slider' => 'jet-elements',
        'jet-team-member' => 'jet-elements',
        'jet-testimonials' => 'jet-elements',
        'jet-pricing-table' => 'jet-elements',
        'jet-progress-bar' => 'jet-elements',
        'jet-circle-progress' => 'jet-elements',

        // JetBlocks widgets
        'jet-blocks' => 'jet-blocks',
        'jet-auth-' => 'jet-blocks',
        'jet-nav-menu' => 'jet-blocks',
        'jet-breadcrumbs' => 'jet-blocks',
        'jet-hamburger-panel' => 'jet-blocks',
        'jet-search' => 'jet-blocks',
        'jet-login' => 'jet-blocks',
        'jet-register' => 'jet-blocks',

        // JetMenu widgets
        'jet-menu' => 'jet-menu',
        'jet-mega-menu' => 'jet-menu',
        'jet-custom-menu' => 'jet-menu',
        'jet-mobile-menu' => 'jet-menu',

        // JetSmartFilters widgets
        'jet-smart-filters' => 'jet-smart-filters',
        'jet-smart-filters-' => 'jet-smart-filters',

        // JetSearch widgets
        'jet-ajax-search' => 'jet-search',

        // JetTabs widgets
        'jet-tabs' => 'jet-tabs',
        'jet-accordion' => 'jet-tabs',
        'jet-switcher' => 'jet-tabs',

        // JetPopup
        'jet-popup' => 'jet-popup',
        'jet-popup-' => 'jet-popup',

        // JetTricks widgets
        'jet-tricks' => 'jet-tricks',
        'jet-unfold' => 'jet-tricks',
        'jet-view-more' => 'jet-tricks',
        'jet-hotspots' => 'jet-tricks',

        // JetBlog widgets
        'jet-blog-posts' => 'jet-blog',
        'jet-smart-tiles' => 'jet-blog',
        'jet-text-ticker' => 'jet-blog',
        'jet-video-playlist' => 'jet-blog',

        // JetReviews
        'jet-reviews' => 'jet-reviews',
        'jet-reviews-' => 'jet-reviews',

        // JetCompareWishlist
        'jet-compare-' => 'jet-compare-wishlist',
        'jet-wishlist-' => 'jet-compare-wishlist',

        // Fluent Forms
        'fluentform' => 'fluentform',
        'fluent-form-widget' => 'fluentform',

        // FluentCRM
        'fluentcrm-' => 'fluent-crm',

        // Presto Player (Video Player)
        'presto_video' => 'presto-player',
        'presto-player' => 'presto-player',
        'presto_player' => 'presto-player',

        // EmbedPress
        'embedpress' => 'embedpress',
        'embed-press' => 'embedpress',
        'embedpress_document' => 'embedpress',
        'embedpress_pdf' => 'embedpress',

        // Video Players
        'video' => null, // Standard WP video
        'video-player' => null,
    ];

    /**
     * Gutenberg block to plugin mapping
     */
    private static $block_map = [
        // WooCommerce blocks
        'woocommerce/' => 'woocommerce',

        // LearnPress blocks
        'learnpress/' => 'learnpress',
        'developer/' => 'learnpress', // LP developer blocks

        // JetEngine blocks
        'jet-engine/' => 'jet-engine',

        // JetFormBuilder blocks
        'jet-forms/' => 'jetformbuilder',

        // FluentForms blocks
        'fluentform/' => 'fluentform',

        // Contact Form 7
        'contact-form-7/' => 'contact-form-7',

        // Presto Player blocks
        'presto-player/' => 'presto-player',

        // EmbedPress blocks
        'embedpress/' => 'embedpress',
    ];

    /**
     * Post type to plugin mapping
     */
    private static $post_type_map = [
        // WooCommerce
        'product' => 'woocommerce',
        'shop_order' => 'woocommerce',
        'shop_coupon' => 'woocommerce',
        'product_variation' => 'woocommerce',

        // LearnPress
        'lp_course' => 'learnpress',
        'lp_lesson' => 'learnpress',
        'lp_quiz' => 'learnpress',
        'lp_question' => 'learnpress',
        'lp_order' => 'learnpress',

        // JetEngine
        'jet-engine' => 'jet-engine',
        'jet-menu' => 'jet-menu',
        'jet-popup' => 'jet-popup',

        // JetFormBuilder
        'jet-form-builder' => 'jetformbuilder',

        // JetSmartFilters
        'jet-smart-filters' => 'jet-smart-filters',

        // JetReviews
        'jet-reviews' => 'jet-reviews',

        // FluentForms
        'fluentform' => 'fluentform',

        // Fluent CRM
        'fc_campaign' => 'fluent-crm',
        'fc_list' => 'fluent-crm',

        // Restrict Content Pro
        'rcp_subscription' => 'restrict-content-pro',
        'rcp_payment' => 'restrict-content-pro',

        // AffiliateWP
        'affiliate' => 'affiliatewp',

        // Forum
        'forum' => 'bbpress',
        'topic' => 'bbpress',
        'reply' => 'bbpress',

        // Events
        'tribe_events' => 'the-events-calendar',
        'tribe_venue' => 'the-events-calendar',
        'tribe_organizer' => 'the-events-calendar',

        // Presto Player
        'pp_video_block' => 'presto-player',
        'presto_player' => 'presto-player',

        // EmbedPress
        'embedpress' => 'embedpress',
    ];

    /**
     * Analyze a post and return required plugins
     *
     * @param int $post_id Post ID
     * @return array Required plugin slugs
     */
    public static function analyze_post($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return [];
        }

        $required = [];

        // Layer 1: Post type detection
        $post_type_plugins = self::detect_from_post_type($post->post_type);
        $required = array_merge($required, $post_type_plugins);

        // Layer 2: Shortcode detection in content
        $shortcode_plugins = self::detect_shortcodes($post->post_content);
        $required = array_merge($required, $shortcode_plugins);

        // Layer 3: Elementor widget detection
        $elementor_plugins = self::detect_elementor_widgets($post_id);
        $required = array_merge($required, $elementor_plugins);

        // Layer 4: Gutenberg block detection
        $block_plugins = self::detect_gutenberg_blocks($post->post_content);
        $required = array_merge($required, $block_plugins);

        // Layer 5: Page template detection
        $template_plugins = self::detect_from_template($post_id);
        $required = array_merge($required, $template_plugins);

        // Layer 6: Theme builder template detection (JetThemeCore, Elementor Pro)
        $theme_builder_plugins = self::detect_from_theme_builder_templates($post_id, $post->post_type);
        $required = array_merge($required, $theme_builder_plugins);

        // Dedupe and return
        return array_unique(array_filter($required));
    }

    /**
     * Detect plugins from theme builder templates
     * Handles JetThemeCore, Elementor Pro Theme Builder, etc.
     *
     * @param int $post_id Post ID
     * @param string $post_type Post type
     * @return array Required plugins
     */
    public static function detect_from_theme_builder_templates($post_id, $post_type) {
        $plugins = [];

        // Get all theme builder templates that might apply
        $template_types = [
            'elementor_library',  // Elementor Pro templates
            'jet-theme-core',     // JetThemeCore templates
            'jet-engine',         // JetEngine listing templates
        ];

        foreach ($template_types as $template_type) {
            $templates = self::get_applicable_templates($template_type, $post_type);
            foreach ($templates as $template_id) {
                // Analyze each template's Elementor data
                $template_plugins = self::detect_elementor_widgets($template_id);
                $plugins = array_merge($plugins, $template_plugins);

                // Also check template content for shortcodes/blocks
                $template = get_post($template_id);
                if ($template) {
                    $plugins = array_merge($plugins, self::detect_shortcodes($template->post_content));
                    $plugins = array_merge($plugins, self::detect_gutenberg_blocks($template->post_content));
                }
            }
        }

        return $plugins;
    }

    /**
     * Get applicable theme builder templates for a post type
     *
     * @param string $template_type Template post type
     * @param string $target_post_type Target post type
     * @return array Template IDs
     */
    private static function get_applicable_templates($template_type, $target_post_type) {
        global $wpdb;

        $templates = [];

        // Get all published templates of this type
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Theme builder template detection, custom post types not available via WP_Query in this context
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = %s
             AND post_status = 'publish'",
            $template_type
        ), ARRAY_A);

        foreach ($results as $row) {
            $template_id = $row['ID'];

            // Check template conditions (varies by plugin)
            // JetThemeCore stores conditions in postmeta
            $conditions = get_post_meta($template_id, '_conditions', true);
            if (!$conditions) {
                $conditions = get_post_meta($template_id, '_elementor_conditions', true);
            }

            // If no conditions, or conditions include this post type, include the template
            if (empty($conditions) || self::template_applies_to_post_type($conditions, $target_post_type)) {
                $templates[] = $template_id;
            }
        }

        return $templates;
    }

    /**
     * Check if template conditions apply to a post type
     *
     * @param mixed $conditions Template conditions
     * @param string $post_type Post type
     * @return bool
     */
    private static function template_applies_to_post_type($conditions, $post_type) {
        // Simple check - if conditions contain the post type or 'all', return true
        if (is_array($conditions)) {
            $conditions = json_encode($conditions);
        }

        if (is_string($conditions)) {
            // Check for common patterns
            if (strpos($conditions, 'all') !== false) {
                return true;
            }
            if (strpos($conditions, $post_type) !== false) {
                return true;
            }
            // Shop page conditions
            if ($post_type === 'page' && strpos($conditions, 'shop') !== false) {
                return true;
            }
            if ($post_type === 'product' && strpos($conditions, 'product') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect plugins from post type
     *
     * @param string $post_type Post type slug
     * @return array Required plugins
     */
    public static function detect_from_post_type($post_type) {
        $plugins = [];

        if (isset(self::$post_type_map[$post_type])) {
            $plugins[] = self::$post_type_map[$post_type];
        }

        return $plugins;
    }

    /**
     * Detect shortcodes in content
     *
     * @param string $content Post content
     * @return array Required plugins
     */
    public static function detect_shortcodes($content) {
        $plugins = [];

        if (empty($content)) {
            return $plugins;
        }

        // Find all shortcodes
        preg_match_all('/\[([a-zA-Z0-9_-]+)/', $content, $matches);

        if (!empty($matches[1])) {
            foreach ($matches[1] as $shortcode) {
                $shortcode_lower = strtolower($shortcode);

                // Strategy 1: Check exact match in known mapping
                if (isset(self::$shortcode_map[$shortcode_lower]) && self::$shortcode_map[$shortcode_lower] !== null) {
                    $plugins[] = self::$shortcode_map[$shortcode_lower];
                    continue;
                }

                // Strategy 2: Intelligent prefix matching against active plugins
                $discovered = self::discover_plugin_from_widget($shortcode);
                if ($discovered) {
                    $plugins[] = $discovered;
                }
            }
        }

        return $plugins;
    }

    /**
     * Detect Elementor widgets used in a post
     *
     * @param int $post_id Post ID
     * @return array Required plugins
     */
    public static function detect_elementor_widgets($post_id) {
        $plugins = [];

        // Check if Elementor data exists
        $elementor_data = get_post_meta($post_id, '_elementor_data', true);
        if (empty($elementor_data)) {
            return $plugins;
        }

        // Decode if JSON string
        if (is_string($elementor_data)) {
            $elementor_data = json_decode($elementor_data, true);
        }

        if (!is_array($elementor_data)) {
            return $plugins;
        }

        // Extract widget types recursively
        $widget_types = self::extract_elementor_widgets($elementor_data);

        // Map widgets to plugins using multi-strategy approach
        foreach ($widget_types as $widget_type) {
            $widget_type_lower = strtolower($widget_type);

            // Strategy 1: Check exact match in known mapping
            if (isset(self::$elementor_widget_map[$widget_type_lower])) {
                $plugins[] = self::$elementor_widget_map[$widget_type_lower];
                continue;
            }

            // Strategy 2: Check prefix matches in known mapping
            $found = false;
            foreach (self::$elementor_widget_map as $prefix => $plugin) {
                if (substr($prefix, -1) === '-' && strpos($widget_type_lower, rtrim($prefix, '-')) === 0) {
                    $plugins[] = $plugin;
                    $found = true;
                    break;
                }
            }

            if ($found) {
                continue;
            }

            // Strategy 3: Intelligent prefix matching against active plugins
            // Extract potential plugin prefix from widget name (e.g., "presto_video" → "presto")
            $discovered = self::discover_plugin_from_widget($widget_type);
            if ($discovered) {
                $plugins[] = $discovered;
            }
        }

        return $plugins;
    }

    /**
     * Intelligently discover which plugin provides a widget
     * Uses prefix matching against active plugins
     *
     * @param string $widget_type Widget type name
     * @return string|null Plugin slug or null if not found
     */
    private static function discover_plugin_from_widget($widget_type) {
        static $active_plugins_cache = null;

        // Get active plugins list (cached)
        if ($active_plugins_cache === null) {
            $active_plugins_cache = [];
            $active = get_option('active_plugins', []);
            foreach ($active as $plugin_path) {
                $slug = explode('/', $plugin_path)[0];
                $active_plugins_cache[$slug] = $slug;
                // Also store variations
                $active_plugins_cache[str_replace('-', '_', $slug)] = $slug;
                $active_plugins_cache[str_replace('_', '-', $slug)] = $slug;
            }
        }

        // Normalize widget type
        $widget_lower = strtolower($widget_type);

        // Extract prefix parts from widget name
        // "presto_video" → ["presto", "presto_video"]
        // "jet-woo-builder" → ["jet", "jet-woo", "jet-woo-builder"]
        $prefixes = [];

        // Handle underscore separators
        $parts = explode('_', $widget_lower);
        $prefix = '';
        foreach ($parts as $part) {
            $prefix = $prefix ? $prefix . '_' . $part : $part;
            $prefixes[] = $prefix;
            $prefixes[] = str_replace('_', '-', $prefix);
        }

        // Handle dash separators
        $parts = explode('-', $widget_lower);
        $prefix = '';
        foreach ($parts as $part) {
            $prefix = $prefix ? $prefix . '-' . $part : $part;
            $prefixes[] = $prefix;
            $prefixes[] = str_replace('-', '_', $prefix);
        }

        // Check if any prefix matches an active plugin
        foreach ($prefixes as $prefix) {
            if (isset($active_plugins_cache[$prefix])) {
                return $active_plugins_cache[$prefix];
            }

            // Also check with common suffixes
            foreach (['-pro', '-premium', '-addon', '-extension'] as $suffix) {
                $with_suffix = $prefix . $suffix;
                if (isset($active_plugins_cache[$with_suffix])) {
                    return $active_plugins_cache[$with_suffix];
                }
            }
        }

        return null;
    }

    /**
     * Recursively extract widget types from Elementor data
     *
     * @param array $elements Elementor elements array
     * @return array Widget types found
     */
    private static function extract_elementor_widgets($elements) {
        $widgets = [];

        if (!is_array($elements)) {
            return $widgets;
        }

        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }

            // Check if this is a widget
            if (isset($element['widgetType'])) {
                $widgets[] = $element['widgetType'];
            } elseif (isset($element['elType']) && $element['elType'] === 'widget' && isset($element['widgetType'])) {
                $widgets[] = $element['widgetType'];
            }

            // Recurse into elements
            if (isset($element['elements']) && is_array($element['elements'])) {
                $widgets = array_merge($widgets, self::extract_elementor_widgets($element['elements']));
            }
        }

        return $widgets;
    }

    /**
     * Detect Gutenberg blocks in content
     *
     * @param string $content Post content
     * @return array Required plugins
     */
    public static function detect_gutenberg_blocks($content) {
        $plugins = [];

        if (empty($content)) {
            return $plugins;
        }

        // Find all block comments (format: <!-- wp:namespace/block-name -->)
        preg_match_all('/<!-- wp:([a-zA-Z0-9_-]+)(?:\/[a-zA-Z0-9_-]+)?/', $content, $matches);

        if (!empty($matches[1])) {
            foreach ($matches[1] as $block_namespace) {
                $block_namespace = strtolower($block_namespace);

                // Skip core WordPress blocks
                if ($block_namespace === 'core' || $block_namespace === 'wp') {
                    continue;
                }

                // Strategy 1: Check known block mapping
                $found = false;
                foreach (self::$block_map as $prefix => $plugin) {
                    if (strpos($block_namespace . '/', $prefix) === 0 || $block_namespace === rtrim($prefix, '/')) {
                        $plugins[] = $plugin;
                        $found = true;
                        break;
                    }
                }

                if ($found) {
                    continue;
                }

                // Strategy 2: Intelligent prefix matching against active plugins
                // Block namespace often matches plugin slug (e.g., "woocommerce/..." → woocommerce)
                $discovered = self::discover_plugin_from_widget($block_namespace);
                if ($discovered) {
                    $plugins[] = $discovered;
                }
            }
        }

        return $plugins;
    }

    /**
     * Detect plugins from page template
     *
     * @param int $post_id Post ID
     * @return array Required plugins
     */
    public static function detect_from_template($post_id) {
        $plugins = [];

        $template = get_page_template_slug($post_id);
        if (empty($template)) {
            return $plugins;
        }

        // Template-specific plugin requirements
        $template_map = [
            'woocommerce' => 'woocommerce',
            'learnpress' => 'learnpress',
            'jet-theme' => 'jet-theme-core',
            'elementor' => 'elementor',
        ];

        foreach ($template_map as $keyword => $plugin) {
            if (stripos($template, $keyword) !== false) {
                $plugins[] = $plugin;
            }
        }

        return $plugins;
    }

    /**
     * Analyze URL and return required plugins (for MU-loader)
     * This method can be called before WordPress is fully loaded
     *
     * @param string $request_uri Request URI
     * @param object $wpdb WordPress database object
     * @return array Required plugins
     */
    public static function detect_from_url_early($request_uri, $wpdb) {
        $detected = [];

        // Normalize URI
        $uri = strtok($request_uri, '?');
        $uri = rtrim($uri, '/');

        // Extract slug from URI
        $parts = explode('/', trim($uri, '/'));
        $slug = end($parts);

        // Direct URL pattern matching (enhanced)
        $url_patterns = [
            // WooCommerce
            '#/(shop|products?|cart|checkout|my-account|order-received|order-pay|view-order)(/|$|\?)#i' => [
                'woocommerce', 'woocommerce-memberships', 'woocommerce-subscriptions',
                'woocommerce-stripe-gateway', 'woocommerce-gateway-stripe',
                'jet-woo-builder', 'jet-woo-product-gallery'
            ],

            // LearnPress
            '#/(courses?|lessons?|quiz|quizzes|lp-|learn-press|instructor|become-instructor)(/|$|\?)#i' => [
                'learnpress'
            ],

            // Membership / Account
            '#/(members?|account|subscription|register|login|profile|dashboard)(/|$|\?)#i' => [
                'restrict-content-pro', 'rcp-content-filter-utility'
            ],

            // Affiliates
            '#/(affiliate|referral|partner)(/|$|\?)#i' => [
                'affiliatewp', 'affiliate-wp'
            ],

            // Forms / Contact
            '#/(contact|form|apply|submit|booking|appointment|schedule)(/|$|\?)#i' => [
                'fluentform', 'fluentformpro', 'fluent-forms', 'fluent-forms-pro',
                'contact-form-7', 'jetformbuilder'
            ],

            // Blog / News
            '#/(blog|news|articles?|posts?|category/|tag/|author/)#i' => [
                'jet-blog', 'jet-smart-filters'
            ],

            // Events
            '#/(events?|calendar|tribe-events)(/|$|\?)#i' => [
                'the-events-calendar'
            ],

            // Forums
            '#/(forums?|topics?|community|discussion)(/|$|\?)#i' => [
                'bbpress'
            ],
        ];

        foreach ($url_patterns as $pattern => $plugins) {
            if (preg_match($pattern, $request_uri)) {
                $detected = array_merge($detected, $plugins);
            }
        }

        // Search pages
        if (strpos($request_uri, '?s=') !== false || strpos($request_uri, '/search/') !== false) {
            $detected[] = 'jet-search';
            $detected[] = 'jet-smart-filters';
        }

        // Query parameter based detection
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only detection, no actions performed
        if (isset($_GET['post_type'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only detection, no actions performed
            $post_type = sanitize_key($_GET['post_type']);
            if (isset(self::$post_type_map[$post_type])) {
                $detected[] = self::$post_type_map[$post_type];
            }
        }

        // Try to detect from cached page requirements
        if (!empty($slug) && $wpdb) {
            $cached = self::get_cached_page_requirements($slug, $wpdb);
            if (!empty($cached)) {
                $detected = array_merge($detected, $cached);
            }
        }

        return array_unique($detected);
    }

    /**
     * Get cached page requirements from database
     *
     * @param string $slug Page/post slug
     * @param object $wpdb Database object
     * @return array Required plugins
     */
    private static function get_cached_page_requirements($slug, $wpdb) {
        // Query for cached requirements by slug
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Early detection before WP fully loaded, called from MU-loader
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT pm.meta_value
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE p.post_name = %s
             AND pm.meta_key = '_shypdr_required_plugins'
             AND p.post_status = 'publish'
             LIMIT 1",
            $slug
        ));

        if ($result) {
            $required = maybe_unserialize($result);
            if (is_array($required)) {
                return $required;
            }
        }

        return [];
    }

    /**
     * Cache page requirements after analysis
     *
     * @param int $post_id Post ID
     * @return bool Success
     */
    public static function cache_page_requirements($post_id) {
        $required = self::analyze_post($post_id);

        if (!empty($required)) {
            update_post_meta($post_id, '_shypdr_required_plugins', $required);
            update_post_meta($post_id, '_shypdr_analyzed_at', current_time('timestamp'));
            return true;
        }

        return false;
    }

    /**
     * Build URL to plugins map for common pages
     *
     * @return array URL slug => plugins mapping
     */
    public static function build_url_requirements_map() {
        global $wpdb;

        $map = [];

        // Get all published posts/pages with cached requirements
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk operation building URL map, direct query more efficient than multiple WP_Query calls
        $results = $wpdb->get_results(
            "SELECT p.post_name, pm.meta_value
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = '_shypdr_required_plugins'
             AND p.post_status = 'publish'
             AND p.post_name != ''",
            ARRAY_A
        );

        foreach ($results as $row) {
            $plugins = maybe_unserialize($row['meta_value']);
            if (is_array($plugins) && !empty($plugins)) {
                $map[$row['post_name']] = $plugins;
            }
        }

        return $map;
    }

    /**
     * Get all detectable plugins from content (for debugging/display)
     *
     * @param int $post_id Post ID
     * @return array Detection results with sources
     */
    public static function get_detailed_analysis($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return [];
        }

        return [
            'post_type' => [
                'type' => $post->post_type,
                'plugins' => self::detect_from_post_type($post->post_type)
            ],
            'shortcodes' => [
                'found' => self::find_shortcodes_in_content($post->post_content),
                'plugins' => self::detect_shortcodes($post->post_content)
            ],
            'elementor_widgets' => [
                'found' => self::extract_elementor_widgets(
                    json_decode(get_post_meta($post_id, '_elementor_data', true), true) ?: []
                ),
                'plugins' => self::detect_elementor_widgets($post_id)
            ],
            'gutenberg_blocks' => [
                'found' => self::find_blocks_in_content($post->post_content),
                'plugins' => self::detect_gutenberg_blocks($post->post_content)
            ],
            'template' => [
                'name' => get_page_template_slug($post_id),
                'plugins' => self::detect_from_template($post_id)
            ],
            'combined' => self::analyze_post($post_id)
        ];
    }

    /**
     * Find all shortcodes in content
     *
     * @param string $content Post content
     * @return array Shortcode names found
     */
    private static function find_shortcodes_in_content($content) {
        preg_match_all('/\[([a-zA-Z0-9_-]+)/', $content, $matches);
        return array_unique($matches[1] ?? []);
    }

    /**
     * Find all blocks in content
     *
     * @param string $content Post content
     * @return array Block names found
     */
    private static function find_blocks_in_content($content) {
        preg_match_all('/<!-- wp:([a-zA-Z0-9_-]+\/[a-zA-Z0-9_-]+)/', $content, $matches);
        return array_unique($matches[1] ?? []);
    }
}
