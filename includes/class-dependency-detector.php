<?php
/**
 * Dependency Detector for Samybaxy's Hyperdrive
 *
 * Intelligently detects plugin dependencies using a multi-layered approach:
 *
 * Layer 1: WordPress 6.5+ native WP_Plugin_Dependencies (most authoritative)
 * Layer 2: "Requires Plugins" header parsing (for WP < 6.5 or fallback)
 * Layer 3: Code analysis for common dependency patterns
 * Layer 4: Known plugin ecosystem relationships (hardcoded fallback)
 * Layer 5: Heuristic-based implicit dependency detection (naming patterns)
 *
 * Time Complexity:
 * - get_dependency_map(): O(1) amortized (cached in static + database)
 * - build_dependency_map(): O(n * m) where n = plugins, m = avg file size for analysis
 * - resolve_dependencies(): O(k + e) where k = plugins to load, e = total edges
 * - detect_circular_dependencies(): O(V + E) using DFS with coloring
 *
 * Space Complexity:
 * - Dependency map: O(n * d) where n = plugins, d = avg dependencies per plugin
 * - Circular detection: O(n) for visited/recursion stack sets
 *
 * @package SamybaxyHyperdrive
 * @since 6.0.0
 * @version 6.0.2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SHYPDR_Dependency_Detector {

    /**
     * Option name for storing dependency map
     */
    const DEPENDENCY_MAP_OPTION = 'shypdr_dependency_map';

    /**
     * Option name for storing circular dependencies
     */
    const CIRCULAR_DEPS_OPTION = 'shypdr_circular_dependencies';

    /**
     * WordPress.org slug validation regex (matches WP core)
     * Only lowercase alphanumeric and hyphens, no leading/trailing hyphens
     */
    const SLUG_REGEX = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    /**
     * Static cache for dependency map (request lifetime)
     *
     * @var array|null
     */
    private static $cached_map = null;

    /**
     * Static cache for circular dependencies
     *
     * @var array|null
     */
    private static $circular_deps_cache = null;

    /**
     * Track if WP_Plugin_Dependencies is available
     *
     * @var bool|null
     */
    private static $wp_deps_available = null;

    /**
     * Known ecosystem patterns for fallback/validation
     * These are hardcoded relationships that may not be declared in headers
     *
     * @var array
     */
    private static $known_ecosystems = [
        'elementor' => [
            'elementor-pro',
            'the-plus-addons-for-elementor-page-builder',
            'jetelements-for-elementor',
            'jetelementor',
        ],
        'woocommerce' => [
            'woocommerce-subscriptions',
            'woocommerce-memberships',
            'woocommerce-product-bundles',
            'woocommerce-smart-coupons',
            'jet-woo-builder',
            'jet-woo-product-gallery',
            // Payment gateways - CRITICAL for checkout
            'woocommerce-gateway-stripe',
            'woocommerce-stripe-gateway',
            'stripe',
            'stripe-for-woocommerce',
            'stripe-payments',
            'woocommerce-payments',
            'woocommerce-paypal-payments',
            'woo-paystack',
            'paystack',
        ],
        'jet-engine' => [
            'jet-menu',
            'jet-blocks',
            'jet-elements',
            'jet-tabs',
            'jet-popup',
            'jet-smart-filters',
            'jet-blog',
            'jet-search',
            'jet-reviews',
            'jet-compare-wishlist',
            'jet-tricks',
            'jet-theme-core',
            'jetformbuilder',
            'jet-woo-builder',
            'jet-woo-product-gallery',
            'jet-appointments-booking',
            'jet-booking',
            'jet-engine-trim-callback',
            'jet-engine-attachment-link-callback',
            'jet-engine-custom-visibility-conditions',
            'jet-engine-dynamic-charts-module',
            'jet-engine-dynamic-tables-module',
        ],
        'learnpress' => [
            'learnpress-prerequisites',
            'learnpress-course-review',
            'learnpress-assignments',
            'learnpress-gradebook',
            'learnpress-certificates',
        ],
        'restrict-content-pro' => [
            'rcp-content-filter-utility',
            'rcp-csv-user-import',
        ],
        'fluentform' => [
            'fluentformpro',
            'fluent-forms-pro',
        ],
        'fluent-crm' => [
            'fluentcrm-pro',
        ],
        'uncanny-automator' => [
            'uncanny-automator-pro',
        ],
        'affiliatewp' => [
            'affiliatewp-allowed-products',
            'affiliatewp-recurring-referrals',
        ],
    ];

    /**
     * Class name to plugin slug mapping for code analysis
     *
     * @var array
     */
    private static $class_to_slug = [
        'WooCommerce'                                    => 'woocommerce',
        'Elementor\\Plugin'                              => 'elementor',
        'Elementor\\Core\\Base\\Module'                  => 'elementor',
        'ElementorPro\\Plugin'                           => 'elementor-pro',
        'Jet_Engine'                                     => 'jet-engine',
        'Jet_Engine_Base_Module'                         => 'jet-engine',
        'LearnPress'                                     => 'learnpress',
        'LP_Course'                                      => 'learnpress',
        'RCP_Requirements_Check'                         => 'restrict-content-pro',
        'Restrict_Content_Pro'                           => 'restrict-content-pro',
        'FluentForm\\Framework\\Foundation\\Application' => 'fluentform',
        'FluentCrm\\App\\App'                            => 'fluent-crm',
        'Jetstylemanager'                                => 'jetstylemanager',
        'AffiliateWP'                                    => 'affiliatewp',
        'Affiliate_WP'                                   => 'affiliatewp',
        'bbPress'                                        => 'bbpress',
        'BuddyPress'                                     => 'buddypress',
        'GravityForms'                                   => 'gravityforms',
        'GFAPI'                                          => 'gravityforms',
        'Tribe__Events__Main'                            => 'the-events-calendar',
    ];

    /**
     * Constant to plugin slug mapping for code analysis
     *
     * @var array
     */
    private static $constant_to_slug = [
        'ELEMENTOR_VERSION'         => 'elementor',
        'ELEMENTOR_PRO_VERSION'     => 'elementor-pro',
        'WC_VERSION'                => 'woocommerce',
        'WOOCOMMERCE_VERSION'       => 'woocommerce',
        'JET_ENGINE_VERSION'        => 'jet-engine',
        'LEARNPRESS_VERSION'        => 'learnpress',
        'RCP_PLUGIN_VERSION'        => 'restrict-content-pro',
        'FLUENTFORM_VERSION'        => 'fluentform',
        'JETSTYLEMANAGER_VERSION'   => 'jetstylemanager',
        'JETSTYLEMANAGER_ACTIVE'    => 'jetstylemanager',
        'JETSTYLEMANAGER_PATH'      => 'jetstylemanager',
        'JETSTYLEMANAGER_SLUG'      => 'jetstylemanager',
        'JETSTYLEMANAGER_NAME'      => 'jetstylemanager',
        'JETSTYLEMANAGER_URL'       => 'jetstylemanager',
        'JETSTYLEMANAGER_FILE'      => 'jetstylemanager',
        'JETSTYLEMANAGER_PLUGIN_BASENAME' => 'jetstylemanager',
        'JETSTYLEMANAGER_PLUGIN_DIR'      => 'jetstylemanager',
        'JETSTYLEMANAGER_PLUGIN_URL'      => 'jetstylemanager',
        'JETSTYLEMANAGER_PLUGIN_FILE'     => 'jetstylemanager',
        'JETSTYLEMANAGER_PLUGIN_SLUG'     => 'jetstylemanager',
        'JETSTYLEMANAGER_PLUGIN_NAME'     => 'jetstylemanager',
        'JETSTYLEMANAGER_PLUGIN_VERSION'  => 'jetstylemanager',
        'JETSTYLEMANAGER_PLUGIN_PREFIX'   => 'jetstylemanager',
        'JETSTYLEMANAGER_PLUGIN_DIR_PATH' => 'jetstylemanager',
        'JETSTYLEMANAGER_PLUGIN_DIR_URL'  => 'jetstylemanager',
        'JETSTYLEMANAGER_PLUGIN_BASENAME_DIR' => 'jetstylemanager',
        'JETSTYLEMANAGER_PLUGIN_BASENAME_FILE' => 'jetstylemanager',
        'JET_SM_VERSION'            => 'jet-style-manager',
        'JETELEMENTS_VERSION'       => 'jet-elements',
        'JET_MENU_VERSION'          => 'jet-menu',
        'JET_BLOCKS_VERSION'        => 'jet-blocks',
        'JET_SMART_FILTERS_VERSION' => 'jet-smart-filters',
        'JET_POPUP_VERSION'         => 'jet-popup',
        'JETWOOBUILDER_VERSION'     => 'jet-woo-builder',
        'JETWOOGALLERY_VERSION'     => 'jet-woo-product-gallery',
        'JETFORMBUILDER_VERSION'    => 'jetformbuilder',
        'JETWOO_BUILDER_VERSION'    => 'jet-woo-builder',
        'JETWOO_PRODUCT_GALLERY_VERSION' => 'jet-woo-product-gallery',
        'JETWOO_PRODUCT_GALLERY'    => 'jet-woo-product-gallery',
        'JETWOO_BUILDER'            => 'jet-woo-builder',
        'JETWOO_BUILDER_URL'        => 'jet-woo-builder',
        'JETWOO_BUILDER_PATH'       => 'jet-woo-builder',
        'JETWOO_BUILDER_FILE'       => 'jet-woo-builder',
        'JETWOO_BUILDER_SLUG'       => 'jet-woo-builder',
        'JETWOO_BUILDER_NAME'       => 'jet-woo-builder',
        'JETWOO_BUILDER_PLUGIN_FILE' => 'jet-woo-builder',
        'JETWOO_BUILDER_PLUGIN_SLUG' => 'jet-woo-builder',
        'JETWOO_BUILDER_PLUGIN_NAME' => 'jet-woo-builder',
        'JETWOO_BUILDER_PLUGIN_VERSION' => 'jet-woo-builder',
        'JETWOO_BUILDER_PLUGIN_PREFIX' => 'jet-woo-builder',
        'JETWOO_BUILDER_PLUGIN_DIR_PATH' => 'jet-woo-builder',
        'JETWOO_BUILDER_PLUGIN_DIR_URL' => 'jet-woo-builder',
        'JETWOO_BUILDER_PLUGIN_BASENAME_DIR' => 'jet-woo-builder',
        'JETWOO_BUILDER_PLUGIN_BASENAME_FILE' => 'jet-woo-builder',
        'JETWOO_BUILDER_PLUGIN_BASENAME' => 'jet-woo-builder',
        'JETWOO_BUILDER_PLUGIN_DIR' => 'jet-woo-builder',
        'JETWOO_BUILDER_PLUGIN_URL' => 'jet-woo-builder',
        'JETWOO_PRODUCT_GALLERY_VERSION' => 'jet-woo-product-gallery',
        'JETWOO_PRODUCT_GALLERY_URL' => 'jet-woo-product-gallery',
        'JETWOO_PRODUCT_GALLERY_PATH' => 'jet-woo-product-gallery',
        'JETWOO_PRODUCT_GALLERY_FILE' => 'jet-woo-product-gallery',
        'JETWOO_PRODUCT_GALLERY_SLUG' => 'jet-woo-product-gallery',
        'JETWOO_PRODUCT_GALLERY_NAME' => 'jet-woo-product-gallery',
        'JETWOO_PRODUCT_GALLERY_PLUGIN_FILE' => 'jet-woo-product-gallery',
        'JETWOO_PRODUCT_GALLERY_PLUGIN_SLUG' => 'jet-woo-product-gallery',
        'JETWOO_PRODUCT_GALLERY_PLUGIN_NAME' => 'jet-woo-product-gallery',
        'AFFILIATEWP_VERSION'       => 'affiliatewp',
        'AFFILIATE_WP_VERSION'      => 'affiliatewp',
    ];

    /**
     * Hook pattern to plugin slug mapping for code analysis
     *
     * @var array
     */
    private static $hook_to_slug = [
        'elementor/'                     => 'elementor',
        'elementor_pro/'                 => 'elementor-pro',
        'woocommerce_'                   => 'woocommerce',
        'woocommerce/'                   => 'woocommerce',
        'jet-engine/'                    => 'jet-engine',
        'jet_engine/'                    => 'jet-engine',
        'jet_engine_'                    => 'jet-engine',
        'learnpress_'                    => 'learnpress',
        'learnpress/'                    => 'learnpress',
        'learn_press_'                   => 'learnpress',
        'learn-press/'                   => 'learnpress',
        'rcp_'                           => 'restrict-content-pro',
        'fluentform_'                    => 'fluentform',
        'fluentform/'                    => 'fluentform',
        'fluentcrm_'                     => 'fluent-crm',
        'fluentcrm/'                     => 'fluent-crm',
        'jetstylemanager_'               => 'jetstylemanager',
        'jetstylemanager/'               => 'jetstylemanager',
        'jet_style_manager_'             => 'jet-style-manager',
        'jet-style-manager/'             => 'jet-style-manager',
        'jet-menu/'                      => 'jet-menu',
        'jet_menu/'                      => 'jet-menu',
        'jet_menu_'                      => 'jet-menu',
        'jet-blocks/'                    => 'jet-blocks',
        'jet_blocks/'                    => 'jet-blocks',
        'jet_blocks_'                    => 'jet-blocks',
        'jet-elements/'                  => 'jet-elements',
        'jet_elements/'                  => 'jet-elements',
        'jet_elements_'                  => 'jet-elements',
        'jet-smart-filters/'             => 'jet-smart-filters',
        'jet_smart_filters/'             => 'jet-smart-filters',
        'jet_smart_filters_'             => 'jet-smart-filters',
        'jet-popup/'                     => 'jet-popup',
        'jet_popup/'                     => 'jet-popup',
        'jet_popup_'                     => 'jet-popup',
        'jet-woo-builder/'               => 'jet-woo-builder',
        'jet_woo_builder/'               => 'jet-woo-builder',
        'jet_woo_builder_'               => 'jet-woo-builder',
        'jet-woo-product-gallery/'       => 'jet-woo-product-gallery',
        'jet_woo_product_gallery/'       => 'jet-woo-product-gallery',
        'jet_woo_product_gallery_'       => 'jet-woo-product-gallery',
        'jetformbuilder/'                => 'jetformbuilder',
        'jet_form_builder/'              => 'jetformbuilder',
        'jet_form_builder_'              => 'jetformbuilder',
        'affiliatewp_'                   => 'affiliatewp',
        'affiliate_wp_'                  => 'affiliatewp',
        'bbp_'                           => 'bbpress',
        'bbpress/'                       => 'bbpress',
        'bp_'                            => 'buddypress',
        'buddypress/'                    => 'buddypress',
        'gform_'                         => 'gravityforms',
        'gravityforms/'                  => 'gravityforms',
        'tribe_events_'                  => 'the-events-calendar',
    ];

    /**
     * Check if WordPress 6.5+ WP_Plugin_Dependencies is available
     *
     * @return bool
     */
    public static function is_wp_plugin_dependencies_available() {
        if ( null !== self::$wp_deps_available ) {
            return self::$wp_deps_available;
        }

        self::$wp_deps_available = class_exists( 'WP_Plugin_Dependencies' );
        return self::$wp_deps_available;
    }

    /**
     * Get the complete dependency map (with caching)
     *
     * Time Complexity: O(1) amortized (static + database cache)
     * Space Complexity: O(n * d) where n = plugins, d = avg deps
     *
     * @return array Dependency map with structure:
     *               [
     *                   'plugin-slug' => [
     *                       'depends_on' => ['parent-1', 'parent-2'],
     *                       'plugins_depending' => ['child-1', 'child-2'],
     *                       'source' => 'wp_core|header|code|pattern|ecosystem'
     *                   ]
     *               ]
     */
    public static function get_dependency_map() {
        // Level 1: Static cache (fastest)
        if ( null !== self::$cached_map ) {
            return self::$cached_map;
        }

        // Level 2: Database cache
        $map = get_option( self::DEPENDENCY_MAP_OPTION, false );

        // If not found or empty, build it
        if ( false === $map || empty( $map ) || ! is_array( $map ) ) {
            $map = self::build_dependency_map();
            update_option( self::DEPENDENCY_MAP_OPTION, $map, false );
        }

        // Allow filtering for custom dependencies
        $map = apply_filters( 'shypdr_dependency_map', $map );

        self::$cached_map = $map;
        return $map;
    }

    /**
     * Build dependency map by scanning all active plugins
     *
     * Uses a 5-layer detection strategy:
     * 1. WP_Plugin_Dependencies (WP 6.5+) - most authoritative
     * 2. "Requires Plugins" header parsing
     * 3. Code analysis (class_exists, defined, hooks)
     * 4. Pattern matching (naming conventions)
     * 5. Known ecosystem relationships
     *
     * Time Complexity: O(n * m) where n = plugins, m = avg file size
     * Space Complexity: O(n * d) for the map
     *
     * @return array Dependency map
     */
    public static function build_dependency_map() {
        $active_plugins = get_option( 'active_plugins', [] );
        $dependency_map = [];

        // Layer 1: Try WordPress 6.5+ native dependency system first
        if ( self::is_wp_plugin_dependencies_available() ) {
            $dependency_map = self::get_dependencies_from_wp_core( $active_plugins );
        }

        // Layers 2-5: Scan each plugin for additional dependencies
        foreach ( $active_plugins as $plugin_path ) {
            $slug = self::get_plugin_slug( $plugin_path );
            $dependencies = self::detect_plugin_dependencies( $plugin_path, $dependency_map );

            if ( ! empty( $dependencies ) ) {
                if ( ! isset( $dependency_map[ $slug ] ) ) {
                    $dependency_map[ $slug ] = [
                        'depends_on'        => [],
                        'plugins_depending' => [],
                        'source'            => 'heuristic',
                    ];
                }

                // Merge dependencies, avoiding duplicates
                $dependency_map[ $slug ]['depends_on'] = array_unique(
                    array_merge( $dependency_map[ $slug ]['depends_on'], $dependencies )
                );
            }
        }

        // Build reverse dependencies (who depends on this plugin)
        // Time: O(n * d) where d = avg dependencies per plugin
        foreach ( $dependency_map as $plugin => $data ) {
            foreach ( $data['depends_on'] as $required_plugin ) {
                if ( ! isset( $dependency_map[ $required_plugin ] ) ) {
                    $dependency_map[ $required_plugin ] = [
                        'depends_on'        => [],
                        'plugins_depending' => [],
                        'source'            => 'inferred',
                    ];
                }
                if ( ! in_array( $plugin, $dependency_map[ $required_plugin ]['plugins_depending'], true ) ) {
                    $dependency_map[ $required_plugin ]['plugins_depending'][] = $plugin;
                }
            }
        }

        // Layer 5: Merge with known ecosystems for validation
        $dependency_map = self::merge_known_ecosystems( $dependency_map, $active_plugins );

        // Detect and flag circular dependencies
        $circular = self::detect_circular_dependencies( $dependency_map );
        if ( ! empty( $circular ) ) {
            update_option( self::CIRCULAR_DEPS_OPTION, $circular, false );
            // Mark circular dependencies in the map
            foreach ( $circular as $pair ) {
                if ( isset( $dependency_map[ $pair[0] ] ) ) {
                    $dependency_map[ $pair[0] ]['has_circular'] = true;
                    $dependency_map[ $pair[0] ]['circular_with'] = $pair[1];
                }
            }
        }

        return $dependency_map;
    }

    /**
     * Get dependencies from WordPress 6.5+ native WP_Plugin_Dependencies
     *
     * @param array $active_plugins List of active plugin paths
     * @return array Dependency map from WP core
     */
    private static function get_dependencies_from_wp_core( $active_plugins ) {
        $dependency_map = [];

        if ( ! class_exists( 'WP_Plugin_Dependencies' ) ) {
            return $dependency_map;
        }

        // Initialize WP_Plugin_Dependencies if not already done
        WP_Plugin_Dependencies::initialize();

        foreach ( $active_plugins as $plugin_path ) {
            $slug = self::get_plugin_slug( $plugin_path );

            // Check if this plugin has dependencies via WP core
            if ( WP_Plugin_Dependencies::has_dependencies( $plugin_path ) ) {
                $deps = WP_Plugin_Dependencies::get_dependencies( $plugin_path );

                if ( ! empty( $deps ) ) {
                    $dependency_map[ $slug ] = [
                        'depends_on'        => $deps,
                        'plugins_depending' => [],
                        'source'            => 'wp_core',
                    ];
                }
            }

            // Check if this plugin has dependents via WP core
            if ( WP_Plugin_Dependencies::has_dependents( $plugin_path ) ) {
                if ( ! isset( $dependency_map[ $slug ] ) ) {
                    $dependency_map[ $slug ] = [
                        'depends_on'        => [],
                        'plugins_depending' => [],
                        'source'            => 'wp_core',
                    ];
                }
                // Dependents will be populated in the reverse pass
            }
        }

        return $dependency_map;
    }

    /**
     * Detect dependencies for a single plugin using multiple methods
     *
     * @param string $plugin_path Plugin file path
     * @param array  $existing_map Existing dependency map to avoid duplicate detection
     * @return array Array of required plugin slugs
     */
    private static function detect_plugin_dependencies( $plugin_path, $existing_map = [] ) {
        $plugin_file = WP_PLUGIN_DIR . '/' . $plugin_path;
        $slug = self::get_plugin_slug( $plugin_path );
        $dependencies = [];

        if ( ! file_exists( $plugin_file ) ) {
            return $dependencies;
        }

        // Skip if already fully detected by WP core
        if ( isset( $existing_map[ $slug ] ) && 'wp_core' === $existing_map[ $slug ]['source'] ) {
            return []; // WP core already has authoritative data
        }

        // Method 1: Check "Requires Plugins" header (for WP < 6.5 or as fallback)
        $requires_plugins = self::get_requires_plugins_header( $plugin_file );
        if ( ! empty( $requires_plugins ) ) {
            $dependencies = array_merge( $dependencies, $requires_plugins );
        }

        // Method 2: Analyze plugin code for common dependency patterns
        $code_dependencies = self::analyze_code_dependencies( $plugin_file );
        if ( ! empty( $code_dependencies ) ) {
            $dependencies = array_merge( $dependencies, $code_dependencies );
        }

        // Method 3: Check plugin slug patterns (e.g., "jet-*" depends on "jet-engine")
        $pattern_dependencies = self::detect_pattern_dependencies( $plugin_path );
        if ( ! empty( $pattern_dependencies ) ) {
            $dependencies = array_merge( $dependencies, $pattern_dependencies );
        }

        // Remove duplicates and self-references
        $dependencies = array_unique( $dependencies );
        $dependencies = array_filter( $dependencies, function( $dep ) use ( $slug ) {
            return $dep !== $slug;
        });

        return array_values( $dependencies );
    }

    /**
     * Get "Requires Plugins" header from plugin file
     *
     * Supports the WordPress 6.5+ header format and applies the
     * wp_plugin_dependencies_slug filter for premium/free plugin swapping.
     *
     * @param string $plugin_file Full path to plugin file
     * @return array Array of validated and sanitized plugin slugs
     */
    private static function get_requires_plugins_header( $plugin_file ) {
        // Use WordPress's get_plugin_data() which handles the header
        if ( ! function_exists( 'get_plugin_data' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugin_data = get_plugin_data( $plugin_file, false, false );

        // Check for "Requires Plugins" header
        if ( empty( $plugin_data['RequiresPlugins'] ) ) {
            return [];
        }

        // Parse and sanitize slugs (matching WP core's sanitize_dependency_slugs)
        return self::sanitize_dependency_slugs( $plugin_data['RequiresPlugins'] );
    }

    /**
     * Sanitize dependency slugs (matching WordPress core's implementation)
     *
     * @param string $slugs Comma-separated string of plugin slugs
     * @return array Array of validated, sanitized slugs
     */
    public static function sanitize_dependency_slugs( $slugs ) {
        $sanitized_slugs = [];
        $slug_array = explode( ',', $slugs );

        foreach ( $slug_array as $slug ) {
            $slug = trim( $slug );

            /**
             * Filter a plugin dependency's slug before validation.
             *
             * Can be used to switch between free and premium plugin slugs.
             * This matches the WordPress core filter for compatibility.
             *
             * @since 6.0.2
             *
             * @param string $slug The plugin slug.
             */
            $slug = apply_filters( 'wp_plugin_dependencies_slug', $slug );

            // Validate against WordPress.org slug format
            if ( self::is_valid_slug( $slug ) ) {
                $sanitized_slugs[] = $slug;
            }
        }

        $sanitized_slugs = array_unique( $sanitized_slugs );
        sort( $sanitized_slugs );

        return $sanitized_slugs;
    }

    /**
     * Validate a plugin slug against WordPress.org format
     *
     * @param string $slug The slug to validate
     * @return bool True if valid
     */
    public static function is_valid_slug( $slug ) {
        if ( empty( $slug ) || ! is_string( $slug ) ) {
            return false;
        }

        // Match WordPress.org slug format: lowercase alphanumeric and hyphens
        // No leading/trailing hyphens, no consecutive hyphens
        return (bool) preg_match( self::SLUG_REGEX, $slug );
    }

    /**
     * Analyze plugin code for dependency patterns
     *
     * Scans for:
     * - class_exists() / function_exists() checks
     * - defined() constant checks
     * - do_action / apply_filters hook patterns
     *
     * Time Complexity: O(m) where m = file size (limited to 50KB)
     *
     * @param string $plugin_file Full path to plugin file
     * @return array Array of detected plugin slugs
     */
    private static function analyze_code_dependencies( $plugin_file ) {
        $dependencies = [];

        // Read first 50KB of plugin file for analysis (performance limit)
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $content = file_get_contents( $plugin_file, false, null, 0, 50000 );

        if ( false === $content ) {
            return $dependencies;
        }

        // Pattern 1: Check for class_exists() or function_exists() checks
        foreach ( self::$class_to_slug as $class_name => $plugin_slug ) {
            // Escape backslashes for class names with namespaces
            $escaped_class = str_replace( '\\', '\\\\', $class_name );
            if ( false !== strpos( $content, $class_name ) ||
                 preg_match( '/class_exists\s*\(\s*[\'"]' . preg_quote( $escaped_class, '/' ) . '[\'"]\s*\)/i', $content ) ) {
                $dependencies[] = $plugin_slug;
            }
        }

        // Pattern 2: Check for defined constants
        foreach ( self::$constant_to_slug as $constant => $plugin_slug ) {
            if ( false !== strpos( $content, $constant ) ) {
                $dependencies[] = $plugin_slug;
            }
        }

        // Pattern 3: Check for plugin-specific hooks
        foreach ( self::$hook_to_slug as $pattern => $plugin_slug ) {
            if ( false !== strpos( $content, $pattern ) ) {
                $dependencies[] = $plugin_slug;
            }
        }

        return array_unique( $dependencies );
    }

    /**
     * Detect dependencies based on plugin naming patterns
     *
     * @param string $plugin_path Plugin file path
     * @return array Array of detected plugin slugs
     */
    private static function detect_pattern_dependencies( $plugin_path ) {
        $slug = self::get_plugin_slug( $plugin_path );
        $dependencies = [];

        // Pattern rules: regex => parent plugin
        $patterns = [
            // Jet plugins ecosystem (except jet-engine itself)
            '/^jet-(?!engine$)/' => 'jet-engine',

            // Elementor ecosystem
            '/^elementor-pro$/' => 'elementor',
            '/-for-elementor/' => 'elementor',
            '/-elementor-/' => 'elementor',

            // WooCommerce ecosystem
            '/^woocommerce-(?!$)/' => 'woocommerce',
            '/^woo-/' => 'woocommerce',
            '/-for-woocommerce/' => 'woocommerce',
            '/-woocommerce$/' => 'woocommerce',

            // LearnPress ecosystem
            '/^learnpress-/' => 'learnpress',

            // Fluent ecosystem
            '/^fluentformpro$/' => 'fluentform',
            '/^fluent-forms-pro$/' => 'fluentform',
            '/^fluentcrm-pro$/' => 'fluent-crm',

            // Restrict Content Pro ecosystem
            '/^rcp-/' => 'restrict-content-pro',

            // AffiliateWP ecosystem
            '/^affiliatewp-/' => 'affiliatewp',

            // Uncanny Automator
            '/^uncanny-automator-pro$/' => 'uncanny-automator',

            // bbPress ecosystem
            '/^bbpress-/' => 'bbpress',
            '/-for-bbpress/' => 'bbpress',

            // BuddyPress ecosystem
            '/^buddypress-/' => 'buddypress',
            '/-for-buddypress/' => 'buddypress',

            // Gravity Forms ecosystem
            '/^gravityforms-/' => 'gravityforms',
            '/^gf-/' => 'gravityforms',
        ];

        foreach ( $patterns as $pattern => $parent_plugin ) {
            if ( preg_match( $pattern, $slug ) && $slug !== $parent_plugin ) {
                $dependencies[] = $parent_plugin;
            }
        }

        return array_unique( $dependencies );
    }

    /**
     * Merge detected dependencies with known ecosystem relationships
     *
     * This ensures we don't miss critical dependencies that may not be
     * declared in headers (especially for premium/non-WordPress.org plugins).
     *
     * @param array $detected_map Detected dependency map
     * @param array $active_plugins List of active plugin paths
     * @return array Merged dependency map
     */
    private static function merge_known_ecosystems( $detected_map, $active_plugins ) {
        // Build active slugs set for O(1) lookup
        $active_slugs = [];
        foreach ( $active_plugins as $plugin_path ) {
            $active_slugs[ self::get_plugin_slug( $plugin_path ) ] = true;
        }

        foreach ( self::$known_ecosystems as $parent => $children ) {
            foreach ( $children as $child ) {
                // Only process if child plugin is actually active
                if ( ! isset( $active_slugs[ $child ] ) ) {
                    continue;
                }

                // Ensure child has dependency on parent
                if ( ! isset( $detected_map[ $child ] ) ) {
                    $detected_map[ $child ] = [
                        'depends_on'        => [],
                        'plugins_depending' => [],
                        'source'            => 'ecosystem',
                    ];
                }

                if ( ! in_array( $parent, $detected_map[ $child ]['depends_on'], true ) ) {
                    $detected_map[ $child ]['depends_on'][] = $parent;
                }

                // Ensure parent has reverse dependency
                if ( ! isset( $detected_map[ $parent ] ) ) {
                    $detected_map[ $parent ] = [
                        'depends_on'        => [],
                        'plugins_depending' => [],
                        'source'            => 'ecosystem',
                    ];
                }

                if ( ! in_array( $child, $detected_map[ $parent ]['plugins_depending'], true ) ) {
                    $detected_map[ $parent ]['plugins_depending'][] = $child;
                }
            }
        }

        return $detected_map;
    }

    /**
     * Detect circular dependencies using DFS with three-color marking
     *
     * Uses the standard graph algorithm for cycle detection:
     * - WHITE (0): Not visited
     * - GRAY (1): Currently in recursion stack
     * - BLACK (2): Fully processed
     *
     * Time Complexity: O(V + E) where V = plugins, E = dependency edges
     * Space Complexity: O(V) for color array and recursion stack
     *
     * @param array $dependency_map The dependency map to check
     * @return array Array of circular dependency pairs [['a', 'b'], ['c', 'd']]
     */
    public static function detect_circular_dependencies( $dependency_map ) {
        $circular_pairs = [];
        $color = []; // 0 = white, 1 = gray, 2 = black
        $parent = []; // Track parent for path reconstruction

        // Initialize all nodes as white
        foreach ( $dependency_map as $plugin => $data ) {
            $color[ $plugin ] = 0;
            // Also initialize nodes that are dependencies but may not be in map as keys
            foreach ( $data['depends_on'] as $dep ) {
                if ( ! isset( $color[ $dep ] ) ) {
                    $color[ $dep ] = 0;
                }
            }
        }

        // DFS from each unvisited node
        foreach ( array_keys( $color ) as $plugin ) {
            if ( 0 === $color[ $plugin ] ) {
                self::dfs_detect_cycle( $plugin, $dependency_map, $color, $parent, $circular_pairs );
            }
        }

        // Remove duplicate pairs (normalize order)
        $unique_pairs = [];
        foreach ( $circular_pairs as $pair ) {
            sort( $pair );
            $key = implode( '|', $pair );
            $unique_pairs[ $key ] = $pair;
        }

        return array_values( $unique_pairs );
    }

    /**
     * DFS helper for cycle detection
     *
     * @param string $node Current node
     * @param array  $dependency_map Dependency map
     * @param array  &$color Color array (modified)
     * @param array  &$parent Parent tracking array
     * @param array  &$circular_pairs Found circular pairs (modified)
     */
    private static function dfs_detect_cycle( $node, $dependency_map, &$color, &$parent, &$circular_pairs ) {
        // Mark as gray (in progress)
        $color[ $node ] = 1;

        // Get dependencies for this node
        $deps = isset( $dependency_map[ $node ]['depends_on'] )
            ? $dependency_map[ $node ]['depends_on']
            : [];

        foreach ( $deps as $dep ) {
            if ( ! isset( $color[ $dep ] ) ) {
                $color[ $dep ] = 0;
            }

            if ( 0 === $color[ $dep ] ) {
                // White: not visited, recurse
                $parent[ $dep ] = $node;
                self::dfs_detect_cycle( $dep, $dependency_map, $color, $parent, $circular_pairs );
            } elseif ( 1 === $color[ $dep ] ) {
                // Gray: found a back edge = cycle
                $circular_pairs[] = [ $node, $dep ];
            }
            // Black: already fully processed, no action needed
        }

        // Mark as black (fully processed)
        $color[ $node ] = 2;
    }

    /**
     * Check if a plugin has circular dependencies
     *
     * @param string $plugin_slug Plugin slug to check
     * @return bool|array False if no circular deps, or array with the conflicting plugin
     */
    public static function has_circular_dependency( $plugin_slug ) {
        $map = self::get_dependency_map();

        if ( isset( $map[ $plugin_slug ]['has_circular'] ) && $map[ $plugin_slug ]['has_circular'] ) {
            return [
                'has_circular'  => true,
                'circular_with' => $map[ $plugin_slug ]['circular_with'] ?? 'unknown',
            ];
        }

        return false;
    }

    /**
     * Get all circular dependencies
     *
     * @return array Array of circular dependency pairs
     */
    public static function get_circular_dependencies() {
        if ( null !== self::$circular_deps_cache ) {
            return self::$circular_deps_cache;
        }

        self::$circular_deps_cache = get_option( self::CIRCULAR_DEPS_OPTION, [] );
        return self::$circular_deps_cache;
    }

    /**
     * Resolve dependencies for a set of plugins (BFS approach)
     *
     * Given a set of required plugin slugs, returns the full set including
     * all transitive dependencies, properly handling:
     * - Direct dependencies (what the plugin requires)
     * - Reverse dependencies (what requires the plugin, if active)
     * - Circular dependency protection
     *
     * Time Complexity: O(k + e) where k = plugins to process, e = edges traversed
     * Space Complexity: O(k) for the queue and result set
     *
     * @param array $required_slugs Initial set of required plugin slugs
     * @param array $active_plugins Full list of active plugins (for reverse dep check)
     * @param bool  $include_reverse Whether to include reverse dependencies
     * @return array Complete set of plugins to load
     */
    public static function resolve_dependencies( $required_slugs, $active_plugins = [], $include_reverse = true ) {
        $dependency_map = self::get_dependency_map();
        $circular_deps = self::get_circular_dependencies();

        // Build active slugs set for O(1) lookup
        $active_set = [];
        foreach ( $active_plugins as $plugin_path ) {
            $slug = self::get_plugin_slug( $plugin_path );
            $active_set[ $slug ] = true;
        }

        // Build circular deps set for O(1) lookup
        $circular_set = [];
        foreach ( $circular_deps as $pair ) {
            $circular_set[ $pair[0] . '|' . $pair[1] ] = true;
            $circular_set[ $pair[1] . '|' . $pair[0] ] = true;
        }

        $to_load = [];
        $queue = $required_slugs;
        $max_iterations = 1000; // Safety limit to prevent infinite loops
        $iterations = 0;

        while ( ! empty( $queue ) && $iterations < $max_iterations ) {
            $iterations++;
            $slug = array_shift( $queue );

            // Skip if already processed
            if ( isset( $to_load[ $slug ] ) ) {
                continue;
            }

            $to_load[ $slug ] = true;

            // Add direct dependencies
            if ( isset( $dependency_map[ $slug ]['depends_on'] ) ) {
                foreach ( $dependency_map[ $slug ]['depends_on'] as $dep ) {
                    // Check for circular dependency before adding
                    $pair_key = $slug . '|' . $dep;
                    if ( isset( $circular_set[ $pair_key ] ) ) {
                        // Skip circular dependency but log it
                        continue;
                    }

                    if ( ! isset( $to_load[ $dep ] ) ) {
                        $queue[] = $dep;
                    }
                }
            }

            // Add reverse dependencies (children that depend on this plugin)
            if ( $include_reverse && isset( $dependency_map[ $slug ]['plugins_depending'] ) ) {
                foreach ( $dependency_map[ $slug ]['plugins_depending'] as $rdep ) {
                    // Only add if the reverse dependency is active
                    if ( ! isset( $to_load[ $rdep ] ) && isset( $active_set[ $rdep ] ) ) {
                        // Check for circular dependency
                        $pair_key = $slug . '|' . $rdep;
                        if ( ! isset( $circular_set[ $pair_key ] ) ) {
                            $queue[] = $rdep;
                        }
                    }
                }
            }
        }

        return array_keys( $to_load );
    }

    /**
     * Extract plugin slug from path
     *
     * @param string $plugin_path e.g., "elementor/elementor.php" or "hello.php"
     * @return string e.g., "elementor" or "hello-dolly"
     */
    public static function get_plugin_slug( $plugin_path ) {
        // Special case for hello.php (WordPress core oddity)
        if ( 'hello.php' === $plugin_path ) {
            return 'hello-dolly';
        }

        // Standard case: slug is directory name
        if ( str_contains( $plugin_path, '/' ) ) {
            return dirname( $plugin_path );
        }

        // Single-file plugin: slug is filename without .php
        return str_replace( '.php', '', $plugin_path );
    }

    /**
     * Rebuild dependency map and clear all caches
     *
     * @return array Statistics about the rebuild
     */
    public static function rebuild_dependency_map() {
        // Clear all caches
        self::$cached_map = null;
        self::$circular_deps_cache = null;
        delete_option( self::DEPENDENCY_MAP_OPTION );
        delete_option( self::CIRCULAR_DEPS_OPTION );

        // Rebuild
        $map = self::build_dependency_map();
        update_option( self::DEPENDENCY_MAP_OPTION, $map, false );

        // Get circular deps that were detected during build
        $circular = get_option( self::CIRCULAR_DEPS_OPTION, [] );

        return [
            'total_plugins'                 => count( $map ),
            'plugins_with_dependencies'     => count( array_filter( $map, function( $data ) {
                return ! empty( $data['depends_on'] );
            } ) ),
            'total_dependency_relationships' => array_sum( array_map( function( $data ) {
                return count( $data['depends_on'] );
            }, $map ) ),
            'circular_dependencies'         => count( $circular ),
            'detection_sources'             => self::count_detection_sources( $map ),
        ];
    }

    /**
     * Count detection sources for statistics
     *
     * @param array $map Dependency map
     * @return array Source counts
     */
    private static function count_detection_sources( $map ) {
        $sources = [
            'wp_core'   => 0,
            'header'    => 0,
            'code'      => 0,
            'pattern'   => 0,
            'ecosystem' => 0,
            'heuristic' => 0,
            'inferred'  => 0,
        ];

        foreach ( $map as $data ) {
            $source = $data['source'] ?? 'unknown';
            if ( isset( $sources[ $source ] ) ) {
                $sources[ $source ]++;
            }
        }

        return $sources;
    }

    /**
     * Clear dependency cache
     */
    public static function clear_cache() {
        self::$cached_map = null;
        self::$circular_deps_cache = null;
        delete_option( self::DEPENDENCY_MAP_OPTION );
        delete_option( self::CIRCULAR_DEPS_OPTION );
    }

    /**
     * Get dependency statistics
     *
     * @return array Statistics about dependencies
     */
    public static function get_stats() {
        $map = self::get_dependency_map();
        $circular = self::get_circular_dependencies();

        $total_plugins = count( $map );
        $plugins_with_deps = 0;
        $total_dependencies = 0;
        $max_deps = 0;
        $plugin_with_most_deps = '';

        foreach ( $map as $plugin => $data ) {
            $dep_count = count( $data['depends_on'] ?? [] );
            if ( $dep_count > 0 ) {
                $plugins_with_deps++;
                $total_dependencies += $dep_count;
                if ( $dep_count > $max_deps ) {
                    $max_deps = $dep_count;
                    $plugin_with_most_deps = $plugin;
                }
            }
        }

        return [
            'total_plugins'                  => $total_plugins,
            'plugins_with_dependencies'      => $plugins_with_deps,
            'total_dependency_relationships' => $total_dependencies,
            'circular_dependencies'          => count( $circular ),
            'circular_pairs'                 => $circular,
            'max_dependencies'               => $max_deps,
            'plugin_with_most_dependencies'  => $plugin_with_most_deps,
            'wp_plugin_dependencies_available' => self::is_wp_plugin_dependencies_available(),
            'detection_sources'              => self::count_detection_sources( $map ),
        ];
    }

    /**
     * Add custom dependency relationship
     *
     * @param string $child_plugin Plugin that depends on another
     * @param string $parent_plugin Plugin that is required
     * @return bool Success
     */
    public static function add_custom_dependency( $child_plugin, $parent_plugin ) {
        // Validate slugs
        if ( ! self::is_valid_slug( $child_plugin ) || ! self::is_valid_slug( $parent_plugin ) ) {
            return false;
        }

        // Prevent self-dependency
        if ( $child_plugin === $parent_plugin ) {
            return false;
        }

        $map = get_option( self::DEPENDENCY_MAP_OPTION, [] );

        // Initialize child if needed
        if ( ! isset( $map[ $child_plugin ] ) ) {
            $map[ $child_plugin ] = [
                'depends_on'        => [],
                'plugins_depending' => [],
                'source'            => 'custom',
            ];
        }

        // Add dependency if not exists
        if ( ! in_array( $parent_plugin, $map[ $child_plugin ]['depends_on'], true ) ) {
            $map[ $child_plugin ]['depends_on'][] = $parent_plugin;
        }

        // Initialize parent if needed
        if ( ! isset( $map[ $parent_plugin ] ) ) {
            $map[ $parent_plugin ] = [
                'depends_on'        => [],
                'plugins_depending' => [],
                'source'            => 'custom',
            ];
        }

        // Add reverse dependency if not exists
        if ( ! in_array( $child_plugin, $map[ $parent_plugin ]['plugins_depending'], true ) ) {
            $map[ $parent_plugin ]['plugins_depending'][] = $child_plugin;
        }

        // Check for circular dependency before saving
        $test_circular = self::detect_circular_dependencies( $map );
        foreach ( $test_circular as $pair ) {
            if ( in_array( $child_plugin, $pair, true ) && in_array( $parent_plugin, $pair, true ) ) {
                // This would create a circular dependency
                return false;
            }
        }

        // Clear cache and save
        self::$cached_map = null;
        return update_option( self::DEPENDENCY_MAP_OPTION, $map, false );
    }

    /**
     * Remove custom dependency relationship
     *
     * @param string $child_plugin Plugin that depends on another
     * @param string $parent_plugin Plugin that is required
     * @return bool Success
     */
    public static function remove_custom_dependency( $child_plugin, $parent_plugin ) {
        $map = get_option( self::DEPENDENCY_MAP_OPTION, [] );

        $modified = false;

        // Remove from child's depends_on
        if ( isset( $map[ $child_plugin ]['depends_on'] ) ) {
            $key = array_search( $parent_plugin, $map[ $child_plugin ]['depends_on'], true );
            if ( false !== $key ) {
                unset( $map[ $child_plugin ]['depends_on'][ $key ] );
                $map[ $child_plugin ]['depends_on'] = array_values( $map[ $child_plugin ]['depends_on'] );
                $modified = true;
            }
        }

        // Remove from parent's plugins_depending
        if ( isset( $map[ $parent_plugin ]['plugins_depending'] ) ) {
            $key = array_search( $child_plugin, $map[ $parent_plugin ]['plugins_depending'], true );
            if ( false !== $key ) {
                unset( $map[ $parent_plugin ]['plugins_depending'][ $key ] );
                $map[ $parent_plugin ]['plugins_depending'] = array_values( $map[ $parent_plugin ]['plugins_depending'] );
                $modified = true;
            }
        }

        if ( $modified ) {
            self::$cached_map = null;
            return update_option( self::DEPENDENCY_MAP_OPTION, $map, false );
        }

        return false;
    }

    /**
     * Get dependencies for a specific plugin
     *
     * @param string $plugin_slug Plugin slug
     * @return array Array of dependency slugs
     */
    public static function get_plugin_dependencies( $plugin_slug ) {
        $map = self::get_dependency_map();

        if ( isset( $map[ $plugin_slug ]['depends_on'] ) ) {
            return $map[ $plugin_slug ]['depends_on'];
        }

        return [];
    }

    /**
     * Get plugins that depend on a specific plugin
     *
     * @param string $plugin_slug Plugin slug
     * @return array Array of dependent plugin slugs
     */
    public static function get_plugin_dependents( $plugin_slug ) {
        $map = self::get_dependency_map();

        if ( isset( $map[ $plugin_slug ]['plugins_depending'] ) ) {
            return $map[ $plugin_slug ]['plugins_depending'];
        }

        return [];
    }

    /**
     * Check if a plugin is a dependency of any other active plugin
     *
     * @param string $plugin_slug Plugin slug to check
     * @return bool True if other plugins depend on this one
     */
    public static function is_required_by_others( $plugin_slug ) {
        $dependents = self::get_plugin_dependents( $plugin_slug );
        return ! empty( $dependents );
    }

    /**
     * Get the detection source for a plugin's dependencies
     *
     * @param string $plugin_slug Plugin slug
     * @return string Source type (wp_core, header, code, pattern, ecosystem, custom, unknown)
     */
    public static function get_detection_source( $plugin_slug ) {
        $map = self::get_dependency_map();

        if ( isset( $map[ $plugin_slug ]['source'] ) ) {
            return $map[ $plugin_slug ]['source'];
        }

        return 'unknown';
    }
}
