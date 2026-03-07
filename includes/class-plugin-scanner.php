<?php
/**
 * Intelligent Plugin Scanner for Samybaxy's Hyperdrive
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

    /**
     * Known heavy ecosystems — parent slug => URL keywords that trigger loading.
     * Children are resolved via the dependency map automatically.
     */
    private static $heavy_ecosystems = [
        'woocommerce' => [
            'keywords' => ['shop', 'product', 'products', 'cart', 'checkout', 'my-account', 'order-received', 'order-pay'],
            'post_types' => ['product', 'shop_order', 'shop_coupon', 'product_variation'],
            'logged_in_only' => false,
        ],
        'learnpress' => [
            'keywords' => ['courses', 'course', 'lessons', 'lesson', 'quiz', 'quizzes', 'instructor', 'become-instructor'],
            'post_types' => ['lp_course', 'lp_lesson', 'lp_quiz', 'lp_question', 'lp_order'],
            'logged_in_only' => false,
        ],
        'affiliatewp' => [
            'keywords' => ['affiliate', 'affiliates', 'referral', 'partner', 'partner-dashboard'],
            'post_types' => ['affiliate'],
            'logged_in_only' => false,
        ],
        'affiliate-wp' => [
            'keywords' => ['affiliate', 'affiliates', 'referral', 'partner', 'partner-dashboard'],
            'post_types' => ['affiliate'],
            'logged_in_only' => false,
        ],
        'bbpress' => [
            'keywords' => ['forums', 'forum', 'topics', 'topic', 'community', 'discussion'],
            'post_types' => ['forum', 'topic', 'reply'],
            'logged_in_only' => false,
        ],
        'the-events-calendar' => [
            'keywords' => ['events', 'event', 'calendar', 'tribe-events'],
            'post_types' => ['tribe_events', 'tribe_venue', 'tribe_organizer'],
            'logged_in_only' => false,
        ],
        'restrict-content-pro' => [
            'keywords' => ['members', 'member', 'register', 'login', 'subscription', 'account'],
            'post_types' => ['rcp_subscription', 'rcp_payment'],
            'logged_in_only' => false,
        ],
        'fluentform' => [
            'keywords' => ['contact', 'form', 'apply', 'submit', 'booking', 'appointment', 'schedule'],
            'post_types' => ['fluentform'],
            'logged_in_only' => false,
        ],
        'jetformbuilder' => [
            'keywords' => ['contact', 'form', 'apply', 'submit', 'booking', 'appointment', 'schedule'],
            'post_types' => ['jet-form-builder'],
            'logged_in_only' => false,
        ],
        'contact-form-7' => [
            'keywords' => ['contact', 'form', 'apply', 'submit'],
            'post_types' => [],
            'logged_in_only' => false,
        ],
    ];

    /**
     * Get or build the restrictable plugins set
     *
     * @param bool $force_rebuild Force a rebuild
     * @return array Restrictable plugin slugs
     */
    public static function get_restrictable_plugins($force_rebuild = false) {
        if (!$force_rebuild) {
            $cached = get_option('shypdr_restrictable_plugins', false);
            if ($cached !== false) {
                return $cached;
            }
        }

        $result = self::build_restrictable_set();
        update_option('shypdr_restrictable_plugins', $result, false);
        return $result;
    }

    /**
     * Get or build the restriction rules
     *
     * @param bool $force_rebuild Force a rebuild
     * @return array Ecosystem slug => rule definition
     */
    public static function get_restriction_rules($force_rebuild = false) {
        if (!$force_rebuild) {
            $cached = get_option('shypdr_restriction_rules', false);
            if ($cached !== false) {
                return $cached;
            }
        }

        $result = self::build_restriction_rules();
        update_option('shypdr_restriction_rules', $result, false);
        return $result;
    }

    /**
     * Build the set of plugins that CAN be conditionally restricted.
     *
     * Only heavy ecosystem plugins end up here. Lightweight utilities,
     * page builders, theme frameworks, and anything scored "critical"
     * are never restrictable.
     *
     * @return array Plugin slugs that can be restricted
     */
    public static function build_restrictable_set() {
        $active_plugins = get_option('active_plugins', []);
        $restrictable = [];

        // Get the dependency map so we can resolve ecosystem children
        $dep_map = [];
        if (class_exists('SHYPDR_Dependency_Detector')) {
            $dep_map = SHYPDR_Dependency_Detector::get_dependency_map();
        }

        // Build a set of all ecosystem parent slugs
        $ecosystem_parents = array_keys(self::$heavy_ecosystems);

        // Build a reverse lookup: child slug => parent slug(s)
        $child_to_parent = [];

        // From dependency map: if a plugin depends_on an ecosystem parent, it's a child
        foreach ($dep_map as $slug => $data) {
            if (!empty($data['depends_on'])) {
                foreach ($data['depends_on'] as $dep) {
                    if (in_array($dep, $ecosystem_parents, true)) {
                        $child_to_parent[$slug] = $dep;
                    }
                }
            }
        }

        // Also use known_ecosystems from dependency detector if available
        if (class_exists('SHYPDR_Dependency_Detector') && method_exists('SHYPDR_Dependency_Detector', 'get_known_ecosystems')) {
            $known = SHYPDR_Dependency_Detector::get_known_ecosystems();
            foreach ($known as $parent => $children) {
                if (in_array($parent, $ecosystem_parents, true)) {
                    foreach ($children as $child) {
                        $child_to_parent[$child] = $parent;
                    }
                }
            }
        }

        // Analyze each active plugin
        foreach ($active_plugins as $plugin_path) {
            $slug = self::get_plugin_slug($plugin_path);

            // Skip the hyperdrive plugin itself
            if ($slug === 'samybaxy-hyperdrive') {
                continue;
            }

            // Check if it's an ecosystem parent
            if (in_array($slug, $ecosystem_parents, true)) {
                $restrictable[] = $slug;
                continue;
            }

            // Check if it's a known ecosystem child
            if (isset($child_to_parent[$slug])) {
                $restrictable[] = $slug;
                continue;
            }

            // Heuristic: slug prefix matches an ecosystem parent
            // e.g., "woocommerce-subscriptions" starts with "woocommerce"
            foreach ($ecosystem_parents as $parent) {
                if (strpos($slug, $parent . '-') === 0 || strpos($slug, $parent . '_') === 0) {
                    $restrictable[] = $slug;
                    continue 2;
                }
            }

            // Heuristic for "woo-" prefixed plugins (common WooCommerce pattern)
            if (strpos($slug, 'woo-') === 0 || strpos($slug, 'wc-') === 0) {
                $restrictable[] = $slug;
                continue;
            }

            // Heuristic for jet-woo plugins
            if (strpos($slug, 'jet-woo') === 0) {
                $restrictable[] = $slug;
                continue;
            }
        }

        // Allow admin overrides — merge in any manually-added restrictable plugins
        $manual_additions = get_option('shypdr_manual_restrictable', []);
        if (is_array($manual_additions)) {
            $restrictable = array_merge($restrictable, $manual_additions);
        }

        // Allow admin overrides — remove any manually-excluded plugins
        $manual_exclusions = get_option('shypdr_manual_unrestricted', []);
        if (is_array($manual_exclusions)) {
            $restrictable = array_diff($restrictable, $manual_exclusions);
        }

        return array_unique(array_values($restrictable));
    }

    /**
     * Build restriction rules for each ecosystem.
     *
     * Rules define WHEN a restrictable ecosystem should be LOADED
     * (i.e., when not to restrict it). If any rule matches, the
     * ecosystem and all its children load.
     *
     * @return array Ecosystem slug => rule definition
     */
    public static function build_restriction_rules() {
        $rules = [];
        $active_plugins = get_option('active_plugins', []);
        $active_slugs = array_map([__CLASS__, 'get_plugin_slug'], $active_plugins);

        // Only build rules for ecosystems that have active plugins
        foreach (self::$heavy_ecosystems as $parent => $rule) {
            if (in_array($parent, $active_slugs, true)) {
                // Get shortcodes that map to this ecosystem
                $shortcodes = self::get_shortcodes_for_plugin($parent);

                $rules[$parent] = [
                    'keywords' => $rule['keywords'],
                    'post_types' => $rule['post_types'],
                    'shortcodes' => $shortcodes,
                    'logged_in_only' => $rule['logged_in_only'],
                ];
            }
        }

        // Allow custom rules via filter
        $rules = apply_filters('shypdr_restriction_rules', $rules);

        return $rules;
    }

    /**
     * Get shortcodes that map to a given plugin slug.
     * Uses the content analyzer's shortcode map.
     *
     * @param string $plugin_slug Plugin slug
     * @return array Shortcode names
     */
    private static function get_shortcodes_for_plugin($plugin_slug) {
        $shortcodes = [];

        // Access the content analyzer's shortcode map
        if (!class_exists('SHYPDR_Content_Analyzer')) {
            return $shortcodes;
        }

        // The shortcode map is private, so we use reflection or a known list
        // For now, use the known mapping from the content analyzer
        $known_shortcode_map = [
            'woocommerce' => ['woocommerce_cart', 'woocommerce_checkout', 'woocommerce_my_account', 'woocommerce_order_tracking', 'products', 'product', 'product_page', 'product_category', 'product_categories', 'add_to_cart', 'add_to_cart_url', 'shop_messages', 'recent_products', 'sale_products', 'best_selling_products', 'top_rated_products', 'featured_products', 'related_products'],
            'learnpress' => ['learn_press_profile', 'learn_press_become_teacher_form', 'learn_press_checkout', 'learn_press_courses', 'learn_press_popular_courses', 'learn_press_featured_courses', 'learn_press_recent_courses'],
            'affiliatewp' => ['affiliate_area', 'affiliate_login', 'affiliate_registration', 'affiliate_referral_url', 'affiliate_creatives'],
            'restrict-content-pro' => ['register_form', 'login_form', 'rcp_registration_form', 'rcp_login_form', 'restrict'],
            'fluentform' => ['fluentform', 'fluentform_modal', 'fluentform_info'],
            'jetformbuilder' => ['jet_fb_form'],
            'contact-form-7' => ['contact-form-7', 'contact-form'],
            'bbpress' => [],
            'the-events-calendar' => [],
        ];

        return $known_shortcode_map[$plugin_slug] ?? [];
    }

    /**
     * Rebuild all restrictable data (set + rules).
     * Called on plugin activation/deactivation and manual rescan.
     */
    public static function rebuild_restrictable_data() {
        self::get_restrictable_plugins(true);
        self::get_restriction_rules(true);
    }
}
