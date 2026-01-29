<?php
/**
 * Intelligent Plugin Scanner for Samybaxy Hyperdrive
 *
 * Analyzes installed plugins using heuristics to determine:
 * - Critical plugins (page builders, theme cores)
 * - Conditional plugins (WooCommerce, forms, courses)
 * - Optional plugins (analytics, SEO, utilities)
 *
 * @package SamybaxyHyperdrive
 */

if (!defined('ABSPATH')) {
    exit;
}

class SHYPDR_Plugin_Scanner {

    /**
     * Known patterns for critical plugin categories
     */
    private static $critical_patterns = [
        'page_builders' => [
            'elementor', 'elementor-pro', 'beaver-builder', 'divi-builder', 'wpbakery',
            'oxygen', 'bricks', 'breakdance'
        ],
        'theme_cores' => [
            'jet-engine', 'jet-theme-core', 'jetthemecore', 'astra-addon', 'kadence-blocks',
            'generatepress-premium', 'thim-elementor-kit'
        ],
        'framework_cores' => [
            'redux-framework', 'cmb2', 'acf-pro', 'advanced-custom-fields',
            'code-snippets'
        ],
        'essential_utilities' => [
            'header-footer-code-manager', 'nitropack'
        ],
        'media_players' => [
            'presto-player', 'presto-player-pro'
        ]
    ];

    /**
     * Keywords indicating critical/essential plugins
     */
    private static $critical_keywords = [
        'page builder', 'theme framework', 'core', 'essential',
        'header', 'footer', 'layout', 'template', 'design system'
    ];

    /**
     * Keywords indicating conditional plugins
     */
    private static $conditional_keywords = [
        'ecommerce', 'shop', 'cart', 'checkout', 'woocommerce',
        'form', 'contact', 'learning', 'course', 'membership',
        'forum', 'community', 'social', 'booking', 'calendar'
    ];

    /**
     * Hooks that indicate a plugin modifies global appearance
     */
    private static $critical_hooks = [
        'wp_enqueue_scripts', 'wp_head', 'wp_footer',
        'body_class', 'wp_body_open', 'template_include',
        'template_redirect', 'init'
    ];

    /**
     * Run intelligent scan on all active plugins
     *
     * @return array Analysis results with categorized plugins
     */
    public static function scan_active_plugins() {
        $active_plugins = get_option('active_plugins', []);
        $analysis = [
            'critical' => [],      // Must load on every page
            'conditional' => [],   // Load based on page type
            'optional' => [],      // Can be filtered aggressively
            'analyzed_at' => current_time('mysql'),
            'total_plugins' => count($active_plugins)
        ];

        foreach ($active_plugins as $plugin_path) {
            $plugin_data = self::analyze_plugin($plugin_path);

            if ($plugin_data['score'] >= 80) {
                $analysis['critical'][] = $plugin_data;
            } elseif ($plugin_data['score'] >= 40) {
                $analysis['conditional'][] = $plugin_data;
            } else {
                $analysis['optional'][] = $plugin_data;
            }
        }

        // Sort by score (highest first)
        usort($analysis['critical'], function($a, $b) {
            return $b['score'] - $a['score'];
        });

        return $analysis;
    }

    /**
     * Analyze a single plugin using heuristics
     *
     * @param string $plugin_path Plugin file path (e.g., "elementor/elementor.php")
     * @return array Plugin analysis data
     */
    private static function analyze_plugin($plugin_path) {
        $plugin_file = WP_PLUGIN_DIR . '/' . $plugin_path;
        $slug = self::get_plugin_slug($plugin_path);

        // Check if plugin file exists before analyzing
        if (!file_exists($plugin_file)) {
            return [
                'slug' => $slug,
                'path' => $plugin_path,
                'name' => $slug,
                'description' => '',
                'version' => '',
                'author' => '',
                'score' => 0,
                'category' => 'optional',
                'reasons' => ['Plugin file not found'],
                'hook_count' => 0
            ];
        }

        // Get plugin headers
        $plugin_data = get_plugin_data($plugin_file, false, false);

        $score = 0;
        $reasons = [];
        $is_known_critical = false;

        // Score 1: Check against known critical patterns (100 points - automatically critical)
        foreach (self::$critical_patterns as $category => $patterns) {
            if (in_array($slug, $patterns)) {
                $score = 100; // Known critical plugins get max score
                $reasons[] = "Known {$category} plugin (auto-critical)";
                $is_known_critical = true;
                break;
            }
        }

        // Only run heuristic analysis if not already known as critical
        if (!$is_known_critical) {
            // Score 2: Analyze plugin name and description (30 points max)
            $name = $plugin_data['Name'] ?? '';
            $description = $plugin_data['Description'] ?? '';
            $text = strtolower($name . ' ' . $description);

            $critical_matches = 0;
            foreach (self::$critical_keywords as $keyword) {
                if (strpos($text, $keyword) !== false) {
                    $critical_matches++;
                }
            }
            if ($critical_matches > 0) {
                $keyword_score = min(30, $critical_matches * 10);
                $score += $keyword_score;
                $reasons[] = "Contains {$critical_matches} critical keywords";
            }

            // Check for conditional keywords (reduces score if present)
            $conditional_matches = 0;
            foreach (self::$conditional_keywords as $keyword) {
                if (strpos($text, $keyword) !== false) {
                    $conditional_matches++;
                }
            }
            if ($conditional_matches > 0) {
                $score -= min(20, $conditional_matches * 5);
                $reasons[] = "Contains {$conditional_matches} conditional keywords";
            }

            // Score 3: Check for hooks registration (20 points max)
            $hook_analysis = self::analyze_plugin_hooks($plugin_file);
            if ($hook_analysis['critical_hooks'] > 0) {
                $hook_score = min(20, $hook_analysis['critical_hooks'] * 5);
                $score += $hook_score;
                $reasons[] = "Registers {$hook_analysis['critical_hooks']} critical hooks";
            }

            // Score 4: Check if it enqueues global assets (15 points)
            if ($hook_analysis['enqueues_assets']) {
                $score += 15;
                $reasons[] = "Enqueues global CSS/JS";
            }

            // Score 5: Check plugin size (larger = likely more critical) (10 points max)
            $size_score = self::estimate_plugin_importance_by_size($plugin_file);
            $score += $size_score;
            if ($size_score > 0) {
                $reasons[] = "Size indicates importance (+{$size_score} points)";
            }

            // Score 6: Check for custom post types/taxonomies (10 points)
            if ($hook_analysis['registers_cpt']) {
                $score += 10;
                $reasons[] = "Registers custom post types";
            }
        } else {
            // For known critical plugins, still analyze hooks for display purposes
            $hook_analysis = self::analyze_plugin_hooks($plugin_file);
        }

        // Ensure score is within 0-100
        $score = max(0, min(100, $score));

        return [
            'slug' => $slug,
            'path' => $plugin_path,
            'name' => $plugin_data['Name'] ?? $slug,
            'description' => substr($plugin_data['Description'] ?? '', 0, 150),
            'version' => $plugin_data['Version'] ?? '',
            'author' => $plugin_data['Author'] ?? '',
            'score' => $score,
            'category' => self::categorize_by_score($score),
            'reasons' => $reasons,
            'hook_count' => $hook_analysis['total_hooks']
        ];
    }

    /**
     * Analyze plugin file for hook registrations
     *
     * @param string $plugin_file Path to main plugin file
     * @return array Hook analysis data
     */
    private static function analyze_plugin_hooks($plugin_file) {
        if (!file_exists($plugin_file)) {
            return [
                'critical_hooks' => 0,
                'total_hooks' => 0,
                'enqueues_assets' => false,
                'registers_cpt' => false
            ];
        }

        $content = file_get_contents($plugin_file);
        if (strlen($content) > 500000) {
            // File too large, do basic check only
            $content = substr($content, 0, 100000);
        }

        $critical_hooks = 0;
        $enqueues_assets = false;
        $registers_cpt = false;

        // Check for critical hooks
        foreach (self::$critical_hooks as $hook) {
            if (preg_match('/add_(action|filter)\s*\(\s*[\'"]' . preg_quote($hook, '/') . '[\'"]/', $content)) {
                $critical_hooks++;
            }
        }

        // Check for asset enqueuing
        if (strpos($content, 'wp_enqueue_style') !== false ||
            strpos($content, 'wp_enqueue_script') !== false) {
            $enqueues_assets = true;
        }

        // Check for custom post type registration
        if (strpos($content, 'register_post_type') !== false ||
            strpos($content, 'register_taxonomy') !== false) {
            $registers_cpt = true;
        }

        // Count total add_action/add_filter calls
        preg_match_all('/add_(action|filter)\s*\(/', $content, $matches);
        $total_hooks = count($matches[0]);

        return [
            'critical_hooks' => $critical_hooks,
            'total_hooks' => $total_hooks,
            'enqueues_assets' => $enqueues_assets,
            'registers_cpt' => $registers_cpt
        ];
    }

    /**
     * Estimate plugin importance based on directory size
     * Larger plugins are often more critical (frameworks, page builders)
     *
     * @param string $plugin_file Plugin main file path
     * @return int Score (0-10)
     */
    private static function estimate_plugin_importance_by_size($plugin_file) {
        $plugin_dir = dirname($plugin_file);

        // Quick check: count files in directory
        $files = glob($plugin_dir . '/*.php');
        // glob() can return false on error
        $file_count = is_array($files) ? count($files) : 0;

        if ($file_count > 50) {
            return 10; // Very large plugin, likely critical
        } elseif ($file_count > 20) {
            return 7;
        } elseif ($file_count > 10) {
            return 4;
        } elseif ($file_count > 5) {
            return 2;
        }

        return 0;
    }

    /**
     * Categorize plugin by score
     *
     * @param int $score Plugin score (0-100)
     * @return string Category name
     */
    private static function categorize_by_score($score) {
        if ($score >= 80) {
            return 'critical';
        } elseif ($score >= 40) {
            return 'conditional';
        } else {
            return 'optional';
        }
    }

    /**
     * Extract plugin slug from path
     *
     * @param string $plugin_path e.g., "elementor/elementor.php"
     * @return string e.g., "elementor"
     */
    private static function get_plugin_slug($plugin_path) {
        $parts = explode('/', $plugin_path);
        return $parts[0] ?? '';
    }

    /**
     * Get or generate smart essential plugins list
     *
     * @param bool $force_rescan Force a new scan
     * @return array Array of essential plugin slugs
     */
    public static function get_essential_plugins($force_rescan = false) {
        // Check if user has customized the list
        $custom_essential = get_option('shypdr_essential_plugins', false);

        // If custom list exists and no force rescan, use it
        if ($custom_essential !== false && !$force_rescan) {
            return $custom_essential;
        }

        // Check if we have cached analysis
        $cached_analysis = get_option('shypdr_plugin_analysis', false);

        if ($cached_analysis === false || $force_rescan) {
            // Run new scan
            $analysis = self::scan_active_plugins();

            // Cache the analysis for 1 week
            update_option('shypdr_plugin_analysis', $analysis);

            // Mark scan as completed
            update_option('shypdr_scan_completed', true);
        } else {
            $analysis = $cached_analysis;
        }

        // Extract slugs from critical plugins
        $essential_slugs = array_map(function($plugin) {
            return $plugin['slug'];
        }, $analysis['critical']);

        // Auto-save critical plugins as essential after scan
        // This ensures critical plugins are always checked by default
        if ($force_rescan || $custom_essential === false) {
            update_option('shypdr_essential_plugins', $essential_slugs);
        }

        return $essential_slugs;
    }

    /**
     * Check if initial scan has been completed
     *
     * @return bool
     */
    public static function is_scan_completed() {
        return get_option('shypdr_scan_completed', false);
    }

    /**
     * Clear cached analysis and force rescan
     */
    public static function clear_cache() {
        delete_option('shypdr_plugin_analysis');
        delete_option('shypdr_scan_completed');
    }
}
