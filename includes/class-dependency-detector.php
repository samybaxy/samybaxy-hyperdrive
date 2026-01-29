<?php
/**
 * Dependency Detector for Samybaxy Hyperdrive
 *
 * Intelligently detects plugin dependencies using:
 * - WordPress 6.5+ "Requires Plugins" header
 * - Code analysis for common dependency patterns
 * - Known plugin ecosystem relationships
 * - Heuristic-based implicit dependency detection
 *
 * @package SamybaxyHyperdrive
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
     * Known ecosystem patterns for fallback/validation
     */
    private static $known_ecosystems = [
        'elementor' => ['elementor-pro', 'the-plus-addons-for-elementor-page-builder'],
        'woocommerce' => ['woocommerce-subscriptions', 'woocommerce-memberships', 'jet-woo-builder'],
        'jet-engine' => ['jet-menu', 'jet-blocks', 'jet-elements', 'jet-tabs', 'jet-popup', 'jet-smart-filters'],
        'learnpress' => ['learnpress-prerequisites', 'learnpress-course-review'],
        'restrict-content-pro' => ['rcp-content-filter-utility'],
    ];

    /**
     * Get the complete dependency map (with caching)
     *
     * @return array Dependency map
     */
    public static function get_dependency_map() {
        static $cached_map = null;

        if ( null !== $cached_map ) {
            return $cached_map;
        }

        // Get from database
        $map = get_option( self::DEPENDENCY_MAP_OPTION, false );

        // If not found or empty, build it
        if ( false === $map || empty( $map ) ) {
            $map = self::build_dependency_map();
            update_option( self::DEPENDENCY_MAP_OPTION, $map, false );
        }

        // Allow filtering for custom dependencies
        $map = apply_filters( 'shypdr_dependency_map', $map );

        $cached_map = $map;
        return $map;
    }

    /**
     * Build dependency map by scanning all active plugins
     *
     * @return array Dependency map
     */
    public static function build_dependency_map() {
        $active_plugins = get_option( 'active_plugins', [] );
        $dependency_map = [];

        foreach ( $active_plugins as $plugin_path ) {
            $slug = self::get_plugin_slug( $plugin_path );
            $dependencies = self::detect_plugin_dependencies( $plugin_path );

            if ( ! empty( $dependencies ) ) {
                $dependency_map[ $slug ] = [
                    'depends_on' => $dependencies,
                    'plugins_depending' => [],
                ];
            }
        }

        // Build reverse dependencies (who depends on this plugin)
        foreach ( $dependency_map as $plugin => $data ) {
            foreach ( $data['depends_on'] as $required_plugin ) {
                if ( ! isset( $dependency_map[ $required_plugin ] ) ) {
                    $dependency_map[ $required_plugin ] = [
                        'depends_on' => [],
                        'plugins_depending' => [],
                    ];
                }
                if ( ! in_array( $plugin, $dependency_map[ $required_plugin ]['plugins_depending'], true ) ) {
                    $dependency_map[ $required_plugin ]['plugins_depending'][] = $plugin;
                }
            }
        }

        // Merge with known ecosystems for validation
        $dependency_map = self::merge_known_ecosystems( $dependency_map );

        return $dependency_map;
    }

    /**
     * Detect dependencies for a single plugin
     *
     * @param string $plugin_path Plugin file path
     * @return array Array of required plugin slugs
     */
    private static function detect_plugin_dependencies( $plugin_path ) {
        $plugin_file = WP_PLUGIN_DIR . '/' . $plugin_path;
        $dependencies = [];

        if ( ! file_exists( $plugin_file ) ) {
            return $dependencies;
        }

        // Method 1: Check WordPress 6.5+ "Requires Plugins" header
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

        // Remove duplicates
        $dependencies = array_unique( $dependencies );

        return $dependencies;
    }

    /**
     * Get "Requires Plugins" header from plugin file (WordPress 6.5+)
     *
     * @param string $plugin_file Full path to plugin file
     * @return array Array of required plugin slugs
     */
    private static function get_requires_plugins_header( $plugin_file ) {
        $plugin_data = get_plugin_data( $plugin_file, false, false );

        // Check for "Requires Plugins" header
        if ( ! empty( $plugin_data['RequiresPlugins'] ) ) {
            // Parse comma-separated list of plugin slugs
            $plugins = array_map( 'trim', explode( ',', $plugin_data['RequiresPlugins'] ) );
            return array_filter( $plugins );
        }

        return [];
    }

    /**
     * Analyze plugin code for dependency patterns
     *
     * @param string $plugin_file Full path to plugin file
     * @return array Array of detected plugin slugs
     */
    private static function analyze_code_dependencies( $plugin_file ) {
        $dependencies = [];

        // Read first 50KB of plugin file for analysis
        $content = file_get_contents( $plugin_file, false, null, 0, 50000 );

        if ( false === $content ) {
            return $dependencies;
        }

        // Pattern 1: Check for class_exists() or function_exists() checks
        // Example: if ( class_exists( 'WooCommerce' ) )
        $class_checks = [
            'WooCommerce' => 'woocommerce',
            'Elementor\\Plugin' => 'elementor',
            'Jet_Engine' => 'jet-engine',
            'LearnPress' => 'learnpress',
            'RCP_Requirements_Check' => 'restrict-content-pro',
            'FluentForm\\Framework\\Foundation\\Application' => 'fluentform',
        ];

        foreach ( $class_checks as $class_name => $plugin_slug ) {
            if ( false !== strpos( $content, $class_name ) ) {
                $dependencies[] = $plugin_slug;
            }
        }

        // Pattern 2: Check for defined constants
        // Example: if ( defined( 'ELEMENTOR_VERSION' ) )
        $constant_checks = [
            'ELEMENTOR_VERSION' => 'elementor',
            'WC_VERSION' => 'woocommerce',
            'JET_ENGINE_VERSION' => 'jet-engine',
            'LEARNPRESS_VERSION' => 'learnpress',
        ];

        foreach ( $constant_checks as $constant => $plugin_slug ) {
            if ( false !== strpos( $content, $constant ) ) {
                $dependencies[] = $plugin_slug;
            }
        }

        // Pattern 3: Check for do_action/apply_filters with plugin-specific hooks
        // Example: do_action( 'elementor/widgets/widgets_registered' )
        $hook_patterns = [
            'elementor/' => 'elementor',
            'woocommerce_' => 'woocommerce',
            'jet-engine/' => 'jet-engine',
            'learnpress_' => 'learnpress',
        ];

        foreach ( $hook_patterns as $pattern => $plugin_slug ) {
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

        // Pattern: "parent-child" or "parent-addon"
        $patterns = [
            // Jet plugins ecosystem
            '/^jet-(?!engine)/' => 'jet-engine', // jet-* (except jet-engine itself) depends on jet-engine

            // Elementor ecosystem
            '/^elementor-pro$/' => 'elementor',
            '/-for-elementor/' => 'elementor', // Addons for Elementor

            // WooCommerce ecosystem
            '/^woocommerce-(?!$)/' => 'woocommerce', // woocommerce-* depends on woocommerce

            // LearnPress ecosystem
            '/^learnpress-(?!$)/' => 'learnpress',

            // Fluent ecosystem
            '/^fluentformpro$/' => 'fluentform',
            '/^fluentcrm-pro$/' => 'fluent-crm',
        ];

        foreach ( $patterns as $pattern => $parent_plugin ) {
            if ( preg_match( $pattern, $slug ) ) {
                $dependencies[] = $parent_plugin;
            }
        }

        return $dependencies;
    }

    /**
     * Merge detected dependencies with known ecosystem relationships
     *
     * @param array $detected_map Detected dependency map
     * @return array Merged dependency map
     */
    private static function merge_known_ecosystems( $detected_map ) {
        foreach ( self::$known_ecosystems as $parent => $children ) {
            foreach ( $children as $child ) {
                // If child plugin is active, ensure dependency on parent is recorded
                if ( isset( $detected_map[ $child ] ) ) {
                    if ( ! in_array( $parent, $detected_map[ $child ]['depends_on'], true ) ) {
                        $detected_map[ $child ]['depends_on'][] = $parent;
                    }
                }

                // Ensure parent has reverse dependency
                if ( ! isset( $detected_map[ $parent ] ) ) {
                    $detected_map[ $parent ] = [
                        'depends_on' => [],
                        'plugins_depending' => [],
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
     * Extract plugin slug from path
     *
     * @param string $plugin_path e.g., "elementor/elementor.php"
     * @return string e.g., "elementor"
     */
    private static function get_plugin_slug( $plugin_path ) {
        $parts = explode( '/', $plugin_path );
        return $parts[0] ?? '';
    }

    /**
     * Rebuild dependency map and clear cache
     *
     * @return int Number of dependencies detected
     */
    public static function rebuild_dependency_map() {
        delete_option( self::DEPENDENCY_MAP_OPTION );
        $map = self::build_dependency_map();
        update_option( self::DEPENDENCY_MAP_OPTION, $map, false );

        return count( $map );
    }

    /**
     * Clear dependency cache
     */
    public static function clear_cache() {
        delete_option( self::DEPENDENCY_MAP_OPTION );
    }

    /**
     * Get dependency statistics
     *
     * @return array Statistics about dependencies
     */
    public static function get_stats() {
        $map = self::get_dependency_map();

        $total_plugins = count( $map );
        $plugins_with_deps = 0;
        $total_dependencies = 0;

        foreach ( $map as $plugin => $data ) {
            if ( ! empty( $data['depends_on'] ) ) {
                $plugins_with_deps++;
                $total_dependencies += count( $data['depends_on'] );
            }
        }

        return [
            'total_plugins' => $total_plugins,
            'plugins_with_dependencies' => $plugins_with_deps,
            'total_dependency_relationships' => $total_dependencies,
            'detection_method' => 'heuristic_scan',
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
        $map = get_option( self::DEPENDENCY_MAP_OPTION, [] );

        if ( ! isset( $map[ $child_plugin ] ) ) {
            $map[ $child_plugin ] = [
                'depends_on' => [],
                'plugins_depending' => [],
            ];
        }

        if ( ! in_array( $parent_plugin, $map[ $child_plugin ]['depends_on'], true ) ) {
            $map[ $child_plugin ]['depends_on'][] = $parent_plugin;
        }

        // Update reverse dependency
        if ( ! isset( $map[ $parent_plugin ] ) ) {
            $map[ $parent_plugin ] = [
                'depends_on' => [],
                'plugins_depending' => [],
            ];
        }

        if ( ! in_array( $child_plugin, $map[ $parent_plugin ]['plugins_depending'], true ) ) {
            $map[ $parent_plugin ]['plugins_depending'][] = $child_plugin;
        }

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

        if ( isset( $map[ $child_plugin ]['depends_on'] ) ) {
            $key = array_search( $parent_plugin, $map[ $child_plugin ]['depends_on'], true );
            if ( false !== $key ) {
                unset( $map[ $child_plugin ]['depends_on'][ $key ] );
                $map[ $child_plugin ]['depends_on'] = array_values( $map[ $child_plugin ]['depends_on'] );
            }
        }

        if ( isset( $map[ $parent_plugin ]['plugins_depending'] ) ) {
            $key = array_search( $child_plugin, $map[ $parent_plugin ]['plugins_depending'], true );
            if ( false !== $key ) {
                unset( $map[ $parent_plugin ]['plugins_depending'][ $key ] );
                $map[ $parent_plugin ]['plugins_depending'] = array_values( $map[ $parent_plugin ]['plugins_depending'] );
            }
        }

        return update_option( self::DEPENDENCY_MAP_OPTION, $map, false );
    }
}
