<?php
/**
 * Main plugin class for Samybaxy's Hyperdrive
 *
 * @package SamybaxyHyperdrive
 */

if (!defined('ABSPATH')) {
    exit;
}

class SHYPDR_Main {
    private static $instance = null;
    private static $enabled = false;
    private static $dependency_map = [];
    private static $log_messages = [];
    private static $essential_plugins_cache = null;

    /**
     * Get essential plugins list (dynamic, from database or scanner)
     *
     * @return array Essential plugin slugs
     */
    private static function get_essential_plugins() {
        if (self::$essential_plugins_cache !== null) {
            return self::$essential_plugins_cache;
        }

        $essential = apply_filters('shypdr_essential_plugins', null);

        if ($essential === null) {
            $essential = SHYPDR_Plugin_Scanner::get_essential_plugins();
        }

        if (empty($essential)) {
            $essential = ['elementor', 'jet-engine', 'jet-theme-core'];
        }

        self::$essential_plugins_cache = $essential;
        return $essential;
    }

    /**
     * Initialize the plugin
     */
    public static function init() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
            self::$instance->setup();
        }
    }

    /**
     * Setup plugin hooks and components
     */
    private function setup() {
        self::$enabled = get_option('shypdr_enabled', false);

        // Load dependency map (dynamically detected or from database)
        self::$dependency_map = SHYPDR_Dependency_Detector::get_dependency_map();

        // Setup admin hooks
        if (is_admin()) {
            add_action('admin_menu', [$this, 'register_admin_menu']);
            add_action('admin_init', [$this, 'register_settings']);
            add_action('admin_init', [$this, 'handle_clear_logs_request']);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        }

        // NOTE: Plugin filtering is now handled by MU-loader (shypdr-mu-loader.php)
        // The MU-loader runs BEFORE plugins load, which is required for actual filtering
        // This main plugin now only handles:
        // - Admin settings UI
        // - Scanner functionality
        // - Debug widget display
        // - Logging and statistics

        // Load debug widget on frontend if enabled (admin only for security)
        if (!is_admin() && get_option('shypdr_debug_enabled', false)) {
            add_action('wp_footer', [$this, 'render_debug_widget']);
            add_action('wp_enqueue_scripts', [$this, 'enqueue_debug_assets']);
        }

        // Cache invalidation hooks
        add_action('save_post', [$this, 'clear_post_cache'], 10, 1);
        add_action('activated_plugin', [$this, 'handle_plugin_activation']);
        add_action('deactivated_plugin', [$this, 'handle_plugin_deactivation']);

        // Content analysis on post save (for smart plugin detection)
        add_action('save_post', [$this, 'analyze_post_requirements'], 20, 2);
        add_action('delete_post', [$this, 'remove_post_requirements'], 10, 1);

        // Log MU-loader results for display
        if (!is_admin() && shypdr_is_mu_loader_active()) {
            add_action('wp_loaded', [$this, 'log_mu_filter_results']);
        }
    }

    /**
     * Log MU-loader filter results
     */
    public function log_mu_filter_results() {
        $data = shypdr_get_mu_filter_data();
        if (!$data || !$data['filtered']) {
            return;
        }

        // Sample only 10% of requests
        if (wp_rand(1, 10) !== 1) {
            return;
        }

        $log = [
            'timestamp' => current_time('mysql'),
            'url' => isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : 'unknown',
            'essential_detected' => array_slice($data['essential_plugins'], 0, 10),
            'plugins_loaded' => count($data['loaded_plugins']),
            'plugins_filtered' => $data['filtered_count'],
            'total_plugins' => $data['original_count'],
            'reduction_percent' => $data['reduction_percent'] . '%',
            'loaded_list' => array_slice($data['loaded_plugins'], 0, 20),
            'filtered_out_list' => [],
            'mu_loader' => true
        ];

        $logs = get_transient('shypdr_logs') ?: [];
        $logs[] = $log;
        set_transient('shypdr_logs', array_slice($logs, -50), HOUR_IN_SECONDS);
    }

    /**
     * Clear post-specific cache when post is saved
     */
    public function clear_post_cache($post_id) {
        SHYPDR_Detection_Cache::clear_post_cache($post_id);
    }

    /**
     * Handle plugin activation - rebuild dependencies and clear caches
     */
    public function handle_plugin_activation() {
        // Rebuild dependency map to include newly activated plugin
        SHYPDR_Dependency_Detector::rebuild_dependency_map();

        // Clear caches
        SHYPDR_Detection_Cache::clear_all_caches();
        SHYPDR_Requirements_Cache::clear();
        self::$essential_plugins_cache = null;
        self::$dependency_map = [];
    }

    /**
     * Handle plugin deactivation - rebuild dependencies and clear caches
     */
    public function handle_plugin_deactivation() {
        // Rebuild dependency map to remove deactivated plugin
        SHYPDR_Dependency_Detector::rebuild_dependency_map();

        // Clear caches
        SHYPDR_Detection_Cache::clear_all_caches();
        SHYPDR_Requirements_Cache::clear();
        self::$essential_plugins_cache = null;
        self::$dependency_map = [];
    }

    /**
     * Analyze post content and cache plugin requirements
     *
     * @param int $post_id Post ID
     * @param WP_Post $post Post object
     */
    public function analyze_post_requirements($post_id, $post) {
        // Skip revisions and autosaves
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        // Skip non-public post types
        if ($post->post_status !== 'publish') {
            return;
        }

        // Update requirements cache
        SHYPDR_Requirements_Cache::update_post_requirements($post_id);
    }

    /**
     * Remove post requirements from cache when post is deleted
     *
     * @param int $post_id Post ID
     */
    public function remove_post_requirements($post_id) {
        SHYPDR_Requirements_Cache::remove_post_requirements($post_id);
    }

    /**
     * NOTE: Dependency map is now auto-detected by SHYPDR_Dependency_Detector
     *
     * The dependency map is no longer hardcoded. Instead, it is:
     * 1. Automatically detected by scanning plugin headers and code
     * 2. Stored in database option 'shypdr_dependency_map'
     * 3. Rebuilt on plugin activation/deactivation
     * 4. Can be customized via filter: apply_filters('shypdr_dependency_map', $map)
     *
     * To add custom dependencies programmatically:
     *
     * add_filter('shypdr_dependency_map', function($map) {
     *     $map['my-plugin'] = [
     *         'depends_on' => ['parent-plugin'],
     *         'plugins_depending' => []
     *     ];
     *     return $map;
     * });
     *
     * Or use the admin UI: Settings → Samybaxy's Hyperdrive → Dependencies
     */

    /**
     * Handle clear logs request
     */
    public function handle_clear_logs_request() {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification handled in called methods based on action type
        if (!isset($_POST['shypdr_action'])) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification handled in called methods based on action type
        $action = sanitize_text_field( wp_unslash( $_POST['shypdr_action'] ) );

        if ( 'clear_logs' === $action ) {
            $this->clear_performance_logs();
        }

        if ( 'rebuild_cache' === $action ) {
            $this->rebuild_requirements_cache();
        }
    }

    /**
     * Rebuild the requirements lookup cache
     */
    public function rebuild_requirements_cache() {
        if ( ! isset( $_POST['shypdr_rebuild_cache_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['shypdr_rebuild_cache_nonce'] ) ), 'shypdr_rebuild_cache_action' ) ) {
            wp_die( esc_html__( 'Security check failed', 'samybaxy-hyperdrive' ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Access denied', 'samybaxy-hyperdrive' ) );
        }

        $count = SHYPDR_Requirements_Cache::rebuild_lookup_table();

        wp_safe_redirect(add_query_arg('shypdr_cache_rebuilt', $count, admin_url('options-general.php?page=shypdr-settings')));
        exit;
    }

    /**
     * Clear performance logs
     */
    public function clear_performance_logs() {
        if ( ! isset( $_POST['shypdr_clear_logs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['shypdr_clear_logs_nonce'] ) ), 'shypdr_clear_logs_action' ) ) {
            wp_die( esc_html__( 'Security check failed', 'samybaxy-hyperdrive' ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Access denied', 'samybaxy-hyperdrive' ) );
        }

        delete_transient('shypdr_logs');
        self::$log_messages = [];

        wp_safe_redirect(add_query_arg('shypdr_logs_cleared', '1', admin_url('options-general.php?page=shypdr-settings')));
        exit;
    }

    /**
     * Register admin menu
     */
    public function register_admin_menu() {
        add_options_page(
            'Samybaxy\'s Hyperdrive',
            'Samybaxy\'s Hyperdrive',
            'manage_options',
            'shypdr-settings',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('shypdr_settings', 'shypdr_enabled', [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false,
        ]);
        register_setting('shypdr_settings', 'shypdr_debug_enabled', [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false,
        ]);
    }

    /**
     * Enqueue admin styles
     */
    public function enqueue_admin_assets($hook) {
        if ($hook !== 'settings_page_shypdr-settings') {
            return;
        }
        wp_enqueue_style('shypdr-admin', SHYPDR_URL . 'assets/css/admin-styles.css', [], SHYPDR_VERSION);
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Access denied', 'samybaxy-hyperdrive' ) );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab parameter for display only, no action taken
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';

        if ( 'scanner' === $active_tab ) {
            $this->render_essential_plugins_page();
            return;
        }

        if ( 'dependencies' === $active_tab ) {
            $this->render_dependencies_page();
            return;
        }

        $enabled = get_option('shypdr_enabled', false);
        $debug_enabled = get_option('shypdr_debug_enabled', false);
        $logs = get_transient('shypdr_logs') ?: [];
        $mu_loader_active = shypdr_is_mu_loader_active();

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Samybaxy\'s Hyperdrive Settings', 'samybaxy-hyperdrive' ); ?></h1>

            <?php
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice, no action taken
            if ( isset( $_GET['shypdr_logs_cleared'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['shypdr_logs_cleared'] ) ) ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong><?php esc_html_e( 'Success!', 'samybaxy-hyperdrive' ); ?></strong> <?php esc_html_e( 'Performance logs have been cleared.', 'samybaxy-hyperdrive' ); ?></p>
                </div>
            <?php endif; ?>

            <?php
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice, no action taken
            if ( isset( $_GET['shypdr_cache_rebuilt'] ) ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong><?php esc_html_e( 'Success!', 'samybaxy-hyperdrive' ); ?></strong>
                    <?php
                    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice, no action taken
                    $pages_count = intval( sanitize_text_field( wp_unslash( $_GET['shypdr_cache_rebuilt'] ) ) );
                    printf(
                        /* translators: %d: number of pages analyzed */
                        esc_html__( 'Requirements cache rebuilt. Analyzed %d pages.', 'samybaxy-hyperdrive' ),
                        absint( $pages_count )
                    );
                    ?></p>
                </div>
            <?php endif; ?>

            <!-- MU-Loader Status Banner -->
            <div style="background: <?php echo esc_attr( $mu_loader_active ? '#d4edda' : '#f8d7da' ); ?>; padding: 20px; margin: 20px 0; border-left: 4px solid <?php echo esc_attr( $mu_loader_active ? '#28a745' : '#dc3545' ); ?>; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h2 style="margin-top: 0;">
                    <?php if ( $mu_loader_active ) : ?>
                        <?php esc_html_e( 'MU-Loader Active - Real Filtering Enabled', 'samybaxy-hyperdrive' ); ?>
                    <?php else : ?>
                        <?php esc_html_e( 'MU-Loader Not Installed - Filtering Won\'t Work', 'samybaxy-hyperdrive' ); ?>
                    <?php endif; ?>
                </h2>
                <?php if ( $mu_loader_active ) : ?>
                    <p style="color: #155724; margin-bottom: 0;">
                        <?php esc_html_e( 'The MU-loader is installed and filtering plugins before they load. This is the correct setup for actual performance gains.', 'samybaxy-hyperdrive' ); ?>
                    </p>
                <?php else : ?>
                    <p style="color: #721c24;">
                        <strong><?php esc_html_e( 'Without the MU-loader, plugin filtering cannot work.', 'samybaxy-hyperdrive' ); ?></strong>
                        <?php esc_html_e( 'Regular plugins load too late to filter out other plugins.', 'samybaxy-hyperdrive' ); ?>
                    </p>
                    <p>
                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'options-general.php?page=shypdr-settings&shypdr_install_mu=1' ), 'shypdr_install_mu' ) ); ?>"
                           class="button button-primary">
                            <?php esc_html_e( 'Install MU-Loader Now', 'samybaxy-hyperdrive' ); ?>
                        </a>
                    </p>
                <?php endif; ?>
            </div>

            <!-- Scanner & Dependencies Section -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin: 20px 0;">
                <div style="background: white; padding: 20px; border-left: 4px solid #667eea; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                    <h2 style="margin-top: 0;"><?php esc_html_e( 'Intelligent Plugin Scanner', 'samybaxy-hyperdrive' ); ?></h2>
                    <p><?php esc_html_e( 'Use AI-powered heuristics to automatically detect which plugins are essential for your site. The scanner analyzes all active plugins and categorizes them as critical, conditional, or optional.', 'samybaxy-hyperdrive' ); ?></p>
                    <a href="<?php echo esc_url( admin_url( 'options-general.php?page=shypdr-settings&tab=scanner' ) ); ?>" class="button button-primary button-large">
                        <?php esc_html_e( 'Manage Essential Plugins', 'samybaxy-hyperdrive' ); ?>
                    </a>
                </div>

                <div style="background: white; padding: 20px; border-left: 4px solid #28a745; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                    <h2 style="margin-top: 0;"><?php esc_html_e( 'Plugin Dependencies', 'samybaxy-hyperdrive' ); ?></h2>
                    <p><?php esc_html_e( 'View automatically detected plugin dependencies. Dependencies are discovered by analyzing plugin headers, code patterns, and ecosystem relationships.', 'samybaxy-hyperdrive' ); ?></p>
                    <a href="<?php echo esc_url( admin_url( 'options-general.php?page=shypdr-settings&tab=dependencies' ) ); ?>" class="button button-secondary button-large">
                        <?php esc_html_e( 'View Dependency Map', 'samybaxy-hyperdrive' ); ?>
                    </a>
                </div>
            </div>

            <!-- Smart Content Detection -->
            <?php
            $cache_stats = SHYPDR_Requirements_Cache::get_stats();
            ?>
            <div style="background: white; padding: 20px; margin: 20px 0; border-left: 4px solid #17a2b8; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h2 style="margin-top: 0;"><?php esc_html_e( 'Smart Content Detection', 'samybaxy-hyperdrive' ); ?></h2>
                <p><?php esc_html_e( 'Analyzes page content (shortcodes, Elementor widgets, Gutenberg blocks) to detect which plugins each page needs. This enables O(1) lookup for maximum performance.', 'samybaxy-hyperdrive' ); ?></p>
                <div style="display: flex; gap: 15px; align-items: center; margin: 15px 0;">
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="shypdr_action" value="rebuild_cache" />
                        <?php wp_nonce_field( 'shypdr_rebuild_cache_action', 'shypdr_rebuild_cache_nonce' ); ?>
                        <button type="submit" class="button button-secondary" onclick="return confirm('<?php echo esc_js( __( 'This will analyze all published pages. Continue?', 'samybaxy-hyperdrive' ) ); ?>');">
                            <?php esc_html_e( 'Rebuild Requirements Cache', 'samybaxy-hyperdrive' ); ?>
                        </button>
                    </form>
                    <span style="color: #666; font-size: 13px;">
                        <?php
                        printf(
                            /* translators: 1: number of pages cached, 2: cache size in KB */
                            esc_html__( '%1$s pages cached (%2$s KB)', 'samybaxy-hyperdrive' ),
                            '<strong>' . esc_html( $cache_stats['total_entries'] ) . '</strong>',
                            esc_html( $cache_stats['size_kb'] )
                        );
                        ?>
                    </span>
                </div>
                <p class="description"><?php esc_html_e( 'Run this after bulk content changes or when conditional loading isn\'t working correctly.', 'samybaxy-hyperdrive' ); ?></p>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields( 'shypdr_settings' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="shypdr_enabled"><?php esc_html_e( 'Enable Plugin Filtering', 'samybaxy-hyperdrive' ); ?></label>
                        </th>
                        <td>
                            <input type="checkbox" id="shypdr_enabled" name="shypdr_enabled" value="1"
                                <?php checked( $enabled ); ?>
                                <?php echo ! $mu_loader_active ? 'style="opacity: 0.5;"' : ''; ?> />
                            <?php if ( ! $mu_loader_active ) : ?>
                                <span style="color: #dc3545; font-weight: bold;"><?php esc_html_e( 'Install MU-Loader first!', 'samybaxy-hyperdrive' ); ?></span>
                            <?php endif; ?>
                            <p class="description">
                                <?php esc_html_e( 'When enabled, loads only essential plugins per page for better performance.', 'samybaxy-hyperdrive' ); ?>
                                <?php if ( ! $mu_loader_active ) : ?>
                                    <br><strong style="color: #dc3545;"><?php esc_html_e( 'Requires MU-Loader to actually work.', 'samybaxy-hyperdrive' ); ?></strong>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="shypdr_debug_enabled"><?php esc_html_e( 'Enable Debug Widget', 'samybaxy-hyperdrive' ); ?></label>
                        </th>
                        <td>
                            <input type="checkbox" id="shypdr_debug_enabled" name="shypdr_debug_enabled" value="1"
                                <?php checked( $debug_enabled ); ?> />
                            <p class="description"><?php esc_html_e( 'Show floating debug widget on frontend with performance stats (admins only).', 'samybaxy-hyperdrive' ); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <?php if ( ! empty( $logs ) ) : ?>
                <hr>
                <h2><?php esc_html_e( 'Recent Performance Logs', 'samybaxy-hyperdrive' ); ?></h2>
                <p class="description">
                    <?php esc_html_e( 'These logs show which plugins were loaded on each page request.', 'samybaxy-hyperdrive' ); ?>
                    <?php if ( $mu_loader_active ) : ?>
                        <span style="color: #28a745;"><?php esc_html_e( 'Using MU-loader for real filtering', 'samybaxy-hyperdrive' ); ?></span>
                    <?php else : ?>
                        <span style="color: #dc3545;"><?php esc_html_e( 'Logs show intended filtering, not actual (MU-loader not installed)', 'samybaxy-hyperdrive' ); ?></span>
                    <?php endif; ?>
                </p>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 15%;">Time</th>
                            <th style="width: 25%;">URL</th>
                            <th style="width: 8%;">Loaded</th>
                            <th style="width: 8%;">Filtered</th>
                            <th style="width: 10%;">Reduction</th>
                            <th style="width: 34%;">Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_reverse(array_slice($logs, -20)) as $log): ?>
                            <tr>
                                <td><?php echo esc_html($log['timestamp']); ?></td>
                                <td>
                                    <code style="font-size: 11px;">
                                        <?php echo esc_html(substr($log['url'], 0, 60)); ?>
                                    </code>
                                </td>
                                <td><strong><?php echo esc_html($log['plugins_loaded']); ?></strong></td>
                                <td><?php echo esc_html($log['plugins_filtered']); ?></td>
                                <td>
                                    <span style="background-color: <?php echo isset($log['mu_loader']) ? '#d4edda' : '#fff3cd'; ?>; padding: 2px 6px; border-radius: 3px;">
                                        <?php echo esc_html($log['reduction_percent']); ?>
                                    </span>
                                </td>
                                <td>
                                    <details style="font-size: 12px; cursor: pointer;">
                                        <summary style="cursor: pointer;">
                                            <?php
                                            $sample = array_slice($log['loaded_list'] ?? [], 0, 3);
                                            echo esc_html(implode(', ', array_map(function($p) {
                                                return explode('/', $p)[0];
                                            }, $sample)));
                                            ?>...
                                        </summary>
                                        <div style="margin-top: 10px; padding: 10px; background: #f5f5f5; border-radius: 3px;">
                                            <strong>Loaded Plugins:</strong>
                                            <ul style="margin: 5px 0; padding-left: 20px;">
                                                <?php foreach ($log['loaded_list'] ?? [] as $plugin): ?>
                                                    <li><?php echo esc_html($plugin); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    </details>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div style="margin-top: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="shypdr_action" value="clear_logs" />
                        <?php wp_nonce_field('shypdr_clear_logs_action', 'shypdr_clear_logs_nonce'); ?>
                        <button type="submit" class="button button-secondary" onclick="return confirm('Are you sure you want to clear all performance logs?');">
                            Clear Performance Logs
                        </button>
                    </form>
                </div>
            <?php else: ?>
                <div style="padding: 20px; background: #f0f0f0; border: 1px solid #ddd; border-radius: 4px; margin-top: 20px;">
                    <p><em>No performance logs yet. Enable filtering and visit some pages to see stats.</em></p>
                </div>
            <?php endif; ?>

            <!-- Technical Info -->
            <div style="margin-top: 30px; padding: 20px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                <h3>Technical Information</h3>
                <ul>
                    <li><strong>Plugin Version:</strong> <?php echo esc_html(SHYPDR_VERSION); ?></li>
                    <li><strong>MU-Loader:</strong> <?php echo $mu_loader_active ? '✅ Active (v' . esc_html(SHYPDR_MU_LOADER_VERSION) . ')' : '❌ Not Installed'; ?></li>
                    <li><strong>Total Active Plugins:</strong> <?php echo count(get_option('active_plugins', [])); ?></li>
                    <li><strong>Essential Plugins Configured:</strong> <?php echo count(get_option('shypdr_essential_plugins', [])); ?></li>
                    <li><strong>Object Cache:</strong> <?php echo wp_using_ext_object_cache() ? '✅ Active (Redis/Memcached)' : '❌ Not Available'; ?></li>
                </ul>
            </div>
        </div>
        <?php
    }

    /**
     * Enqueue debug widget assets
     */
    public function enqueue_debug_assets() {
        if (!current_user_can('manage_options')) {
            return;
        }

        wp_enqueue_style('shypdr-debug', SHYPDR_URL . 'assets/css/debug-widget.css', [], SHYPDR_VERSION);
        wp_enqueue_script('shypdr-debug', SHYPDR_URL . 'assets/js/debug-widget.js', [], SHYPDR_VERSION, true);
    }

    /**
     * Render Essential Plugins management page
     */
    public function render_essential_plugins_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Access denied', 'samybaxy-hyperdrive' ) );
        }

        // Handle form submission
        if (isset($_POST['shypdr_save_essential']) && check_admin_referer('shypdr_essential_plugins', 'shypdr_essential_nonce')) {
            $essential_plugins = isset($_POST['shypdr_essential']) ? array_map('sanitize_text_field', wp_unslash($_POST['shypdr_essential'])) : [];
            update_option('shypdr_essential_plugins', $essential_plugins);

            self::$essential_plugins_cache = null;
            SHYPDR_Detection_Cache::clear_all_caches();

            echo '<div class="notice notice-success is-dismissible"><p><strong>Essential plugins updated successfully!</strong></p></div>';
        }

        // Handle rescan
        if (isset($_POST['shypdr_rescan']) && check_admin_referer('shypdr_rescan_plugins', 'shypdr_rescan_nonce')) {
            SHYPDR_Plugin_Scanner::clear_cache();
            $analysis = SHYPDR_Plugin_Scanner::scan_active_plugins();
            update_option('shypdr_plugin_analysis', $analysis);

            SHYPDR_Plugin_Scanner::get_essential_plugins(true);

            self::$essential_plugins_cache = null;
            SHYPDR_Detection_Cache::clear_all_caches();

            echo '<div class="notice notice-success is-dismissible"><p><strong>Plugin scan completed!</strong> Found ' . count($analysis['critical']) . ' critical plugins and automatically marked them as essential.</p></div>';
        }

        $analysis = get_option('shypdr_plugin_analysis', false);
        if ($analysis === false) {
            $analysis = SHYPDR_Plugin_Scanner::scan_active_plugins();
        }

        $current_essential = get_option('shypdr_essential_plugins', []);
        $cache_stats = SHYPDR_Detection_Cache::get_cache_stats();

        ?>
        <div class="wrap">
            <h1>Samybaxy's Hyperdrive - Essential Plugins</h1>

            <a href="<?php echo esc_url(admin_url('options-general.php?page=shypdr-settings')); ?>" class="button button-secondary" style="margin-bottom: 15px;">
                ← Back to Settings
            </a>

            <div class="notice notice-info">
                <p><strong>What are Essential Plugins?</strong></p>
                <p>Essential plugins are loaded on <strong>every page</strong> (header, footer, global elements). Plugins like page builders, theme cores, and global functionality should be marked as essential. Other plugins will be loaded conditionally based on page context.</p>
            </div>

            <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h2>Plugin Load Strategy</h2>
                <p>Based on your selections and scanner analysis:</p>

                <?php
                // Calculate dynamic counts based on user selections
                $all_plugins = array_merge($analysis['critical'], $analysis['conditional'], $analysis['optional']);
                $essential_count = count($current_essential);
                $conditional_count = 0;
                $filtered_count = 0;

                foreach ($all_plugins as $plugin) {
                    $is_essential = in_array($plugin['slug'], $current_essential);
                    if (!$is_essential) {
                        // Not marked as essential by user
                        if ($plugin['score'] >= 40) {
                            $conditional_count++; // Will load based on page
                        } else {
                            $filtered_count++; // Will be filtered unless detected
                        }
                    }
                }
                ?>

                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin: 20px 0;">
                    <div style="padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px;">
                        <h3 style="margin: 0 0 5px 0; color: #155724;">Essential</h3>
                        <div style="font-size: 24px; font-weight: bold; color: #155724;"><?php echo esc_html($essential_count); ?></div>
                        <small>Always load on every page</small>
                    </div>
                    <div style="padding: 15px; background: #fff3cd; border: 1px solid #ffeeba; border-radius: 4px;">
                        <h3 style="margin: 0 0 5px 0; color: #856404;">Conditional</h3>
                        <div style="font-size: 24px; font-weight: bold; color: #856404;" id="shypdr-conditional-count"><?php echo esc_html($conditional_count); ?></div>
                        <small>Load based on page detection</small>
                    </div>
                    <div style="padding: 15px; background: #d1ecf1; border: 1px solid #bee5eb; border-radius: 4px;">
                        <h3 style="margin: 0 0 5px 0; color: #0c5460;">Filtered</h3>
                        <div style="font-size: 24px; font-weight: bold; color: #0c5460;" id="shypdr-filtered-count"><?php echo esc_html($filtered_count); ?></div>
                        <small>Filtered unless detected</small>
                    </div>
                </div>

                <details style="margin: 15px 0;">
                    <summary style="cursor: pointer; color: #666; font-size: 13px;">Scanner categorization (for reference)</summary>
                    <div style="margin-top: 10px; padding: 10px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 3px;">
                        <p style="margin: 5px 0; font-size: 13px;"><strong>Critical (score ≥ 80):</strong> <?php echo count($analysis['critical']); ?> plugins</p>
                        <p style="margin: 5px 0; font-size: 13px;"><strong>Conditional (score 40-79):</strong> <?php echo count($analysis['conditional']); ?> plugins</p>
                        <p style="margin: 5px 0; font-size: 13px;"><strong>Optional (score < 40):</strong> <?php echo count($analysis['optional']); ?> plugins</p>
                    </div>
                </details>

                <form method="post" style="display: inline;">
                    <?php wp_nonce_field('shypdr_rescan_plugins', 'shypdr_rescan_nonce'); ?>
                    <button type="submit" name="shypdr_rescan" class="button button-secondary">
                        🔍 Rescan All Plugins
                    </button>
                </form>
                <small style="margin-left: 10px; color: #666;">Run this after installing/updating plugins</small>
            </div>

            <form method="post">
                <?php wp_nonce_field('shypdr_essential_plugins', 'shypdr_essential_nonce'); ?>

                <h2>Select Essential Plugins</h2>
                <p>Check the plugins that should <strong>always load</strong> on every page:</p>

                <?php
                foreach (['critical' => 'Critical Plugins', 'conditional' => 'Conditional Plugins', 'optional' => 'Optional Plugins'] as $category_key => $category_label):
                    $plugins_in_category = $analysis[$category_key];
                    if (empty($plugins_in_category)) continue;
                ?>
                    <h3><?php echo esc_html($category_label); ?> (<?php echo count($plugins_in_category); ?>)</h3>
                    <div class="shypdr-plugin-list">
                        <?php foreach ($plugins_in_category as $plugin): ?>
                            <div class="shypdr-plugin-card <?php echo esc_attr($plugin['category']); ?>">
                                <label style="display: flex; align-items: start; cursor: pointer;">
                                    <input type="checkbox"
                                           name="shypdr_essential[]"
                                           value="<?php echo esc_attr($plugin['slug']); ?>"
                                           <?php checked(in_array($plugin['slug'], $current_essential)); ?>
                                           style="margin: 4px 10px 0 0;">
                                    <div style="flex: 1;">
                                        <div class="shypdr-plugin-name">
                                            <?php echo esc_html($plugin['name']); ?>
                                            <span class="shypdr-plugin-score <?php echo esc_attr($plugin['category']); ?>">
                                                Score: <?php echo esc_html($plugin['score']); ?>
                                            </span>
                                        </div>
                                        <div class="shypdr-plugin-desc"><?php echo esc_html($plugin['description']); ?></div>
                                        <?php if (!empty($plugin['reasons'])): ?>
                                            <div class="shypdr-plugin-reasons">
                                                📊 <?php echo esc_html(implode(' • ', array_slice($plugin['reasons'], 0, 2))); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>

                <p class="submit">
                    <button type="submit" name="shypdr_save_essential" class="button button-primary button-hero">
                        💾 Save Essential Plugins
                    </button>
                    <a href="<?php echo esc_url(admin_url('options-general.php?page=shypdr-settings')); ?>" class="button button-secondary button-hero" style="margin-left: 10px;">
                        ← Back to Settings
                    </a>
                </p>
            </form>

            <div style="background: #f9f9f9; padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
                <h3>Cache Statistics</h3>
                <ul>
                    <li><strong>URL Detection Cache:</strong> <?php echo esc_html($cache_stats['url_cache_entries']); ?> entries</li>
                    <li><strong>Content Scan Cache:</strong> <?php echo esc_html($cache_stats['content_cache_entries']); ?> entries</li>
                    <li><strong>Estimated Cache Size:</strong> <?php echo esc_html($cache_stats['estimated_size_kb']); ?> KB</li>
                    <li><strong>Object Cache:</strong> <?php echo $cache_stats['using_object_cache'] ? '✓ Enabled (Redis/Memcached)' : '✗ Using transients'; ?></li>
                </ul>
            </div>
        </div>
        <?php
    }

    /**
     * Render floating debug widget
     */
    public function render_debug_widget() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $mu_data = shypdr_get_mu_filter_data();
        $mu_loader_active = shypdr_is_mu_loader_active();

        ?>
        <div id="shypdr-debug-widget" class="shypdr-debug-widget">
            <div class="shypdr-debug-toggle">
                <span class="shypdr-debug-title">⚡ Hyperdrive</span>
            </div>
            <div class="shypdr-debug-content">
                <?php if ($mu_loader_active && $mu_data): ?>
                    <div class="shypdr-debug-stat" style="background: #d4edda; padding: 5px; border-radius: 3px; margin-bottom: 10px;">
                        <strong>✅ MU-Loader Active</strong>
                    </div>
                    <div class="shypdr-debug-stat">
                        <strong>Total Plugins:</strong> <?php echo esc_html($mu_data['original_count']); ?>
                    </div>
                    <div class="shypdr-debug-stat">
                        <strong>Loaded:</strong> <?php echo esc_html(count($mu_data['loaded_plugins'])); ?>
                    </div>
                    <div class="shypdr-debug-stat">
                        <strong>Filtered:</strong> <?php echo esc_html($mu_data['filtered_count']); ?>
                    </div>
                    <div class="shypdr-debug-stat highlight">
                        <strong>Reduction:</strong> <?php echo esc_html($mu_data['reduction_percent']); ?>%
                    </div>
                    <hr>
                    <div class="shypdr-debug-section">
                        <strong class="shypdr-section-title">
                            ✓ Loaded Plugins (<?php echo esc_html(count($mu_data['loaded_plugins'])); ?>)
                        </strong>
                        <div class="shypdr-plugin-list-scrollable">
                            <ul>
                                <?php foreach ($mu_data['loaded_plugins'] as $plugin): ?>
                                    <li><span class="shypdr-plugin-bullet">•</span> <?php echo esc_html($plugin); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                    <hr>
                    <div class="shypdr-debug-section">
                        <strong class="shypdr-section-title shypdr-collapsible" onclick="this.parentElement.classList.toggle('expanded')">
                            ⊖ Filtered Out (<?php echo esc_html($mu_data['filtered_count']); ?>)
                        </strong>
                        <div class="shypdr-plugin-list-scrollable shypdr-collapsible-content">
                            <ul>
                                <?php
                                $all_plugins = !empty($mu_data['original_plugins']) ? $mu_data['original_plugins'] : [];
                                $loaded_plugins = $mu_data['loaded_plugins'];

                                foreach ($all_plugins as $plugin_path):
                                    if (!in_array($plugin_path, $loaded_plugins, true)):
                                ?>
                                    <li><span class="shypdr-plugin-bullet">•</span> <?php echo esc_html($plugin_path); ?></li>
                                <?php
                                    endif;
                                endforeach;
                                ?>
                            </ul>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="shypdr-debug-stat" style="background: #f8d7da; padding: 5px; border-radius: 3px; margin-bottom: 10px;">
                        <strong>⚠️ MU-Loader Not Active</strong>
                    </div>
                    <p style="color: #721c24; font-size: 12px;">
                        Plugin filtering is not working. Install the MU-Loader from Settings → Samybaxy's Hyperdrive.
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render dependencies management page
     */
    public function render_dependencies_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Access denied', 'samybaxy-hyperdrive' ) );
        }

        // Handle rebuild request
        if ( isset( $_POST['shypdr_rebuild_dependencies'] ) && check_admin_referer( 'shypdr_rebuild_dependencies', 'shypdr_rebuild_deps_nonce' ) ) {
            $count = SHYPDR_Dependency_Detector::rebuild_dependency_map();
            echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html__( 'Success!', 'samybaxy-hyperdrive' ) . '</strong> ';
            printf(
                /* translators: %d: number of plugins analyzed */
                esc_html__( 'Dependency map rebuilt. Analyzed %d plugins.', 'samybaxy-hyperdrive' ),
                absint( $count )
            );
            echo '</p></div>';
        }

        $dependency_map = SHYPDR_Dependency_Detector::get_dependency_map();
        $stats = SHYPDR_Dependency_Detector::get_stats();

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Samybaxy\'s Hyperdrive - Plugin Dependencies', 'samybaxy-hyperdrive' ); ?></h1>

            <a href="<?php echo esc_url( admin_url( 'options-general.php?page=shypdr-settings' ) ); ?>" class="button button-secondary" style="margin-bottom: 15px;">
                <?php esc_html_e( '← Back to Settings', 'samybaxy-hyperdrive' ); ?>
            </a>

            <div class="notice notice-info">
                <p><strong><?php esc_html_e( 'About Plugin Dependencies', 'samybaxy-hyperdrive' ); ?></strong></p>
                <p><?php esc_html_e( 'Dependencies are automatically detected by analyzing plugin headers, code patterns, and ecosystem relationships. When a plugin is loaded, all its dependencies are automatically loaded too.', 'samybaxy-hyperdrive' ); ?></p>
            </div>

            <!-- Statistics -->
            <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h2><?php esc_html_e( 'Dependency Statistics', 'samybaxy-hyperdrive' ); ?></h2>
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin: 20px 0;">
                    <div style="padding: 15px; background: #e7f3ff; border: 1px solid #b3d9ff; border-radius: 4px;">
                        <h3 style="margin: 0 0 5px 0; color: #004085;"><?php esc_html_e( 'Total Plugins', 'samybaxy-hyperdrive' ); ?></h3>
                        <div style="font-size: 24px; font-weight: bold; color: #004085;"><?php echo esc_html( $stats['total_plugins'] ); ?></div>
                        <small><?php esc_html_e( 'In dependency map', 'samybaxy-hyperdrive' ); ?></small>
                    </div>
                    <div style="padding: 15px; background: #fff3cd; border: 1px solid #ffeeba; border-radius: 4px;">
                        <h3 style="margin: 0 0 5px 0; color: #856404;"><?php esc_html_e( 'With Dependencies', 'samybaxy-hyperdrive' ); ?></h3>
                        <div style="font-size: 24px; font-weight: bold; color: #856404;"><?php echo esc_html( $stats['plugins_with_dependencies'] ); ?></div>
                        <small><?php esc_html_e( 'Plugins requiring others', 'samybaxy-hyperdrive' ); ?></small>
                    </div>
                    <div style="padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px;">
                        <h3 style="margin: 0 0 5px 0; color: #155724;"><?php esc_html_e( 'Relationships', 'samybaxy-hyperdrive' ); ?></h3>
                        <div style="font-size: 24px; font-weight: bold; color: #155724;"><?php echo esc_html( $stats['total_dependency_relationships'] ); ?></div>
                        <small><?php esc_html_e( 'Total dependencies', 'samybaxy-hyperdrive' ); ?></small>
                    </div>
                </div>

                <form method="post" style="margin-top: 20px;">
                    <?php wp_nonce_field( 'shypdr_rebuild_dependencies', 'shypdr_rebuild_deps_nonce' ); ?>
                    <button type="submit" name="shypdr_rebuild_dependencies" class="button button-primary" onclick="return confirm('<?php echo esc_js( __( 'Rebuild dependency map? This will scan all active plugins.', 'samybaxy-hyperdrive' ) ); ?>');">
                        <?php esc_html_e( '🔄 Rebuild Dependency Map', 'samybaxy-hyperdrive' ); ?>
                    </button>
                    <small style="margin-left: 10px; color: #666;"><?php esc_html_e( 'Detection method: Heuristic scanning (plugin headers, code analysis, patterns)', 'samybaxy-hyperdrive' ); ?></small>
                </form>
            </div>

            <!-- Dependency List -->
            <h2><?php esc_html_e( 'Plugin Dependency Map', 'samybaxy-hyperdrive' ); ?></h2>

            <table class="shypdr-dep-table">
                <thead>
                    <tr>
                        <th style="width: 25%;"><?php esc_html_e( 'Plugin', 'samybaxy-hyperdrive' ); ?></th>
                        <th style="width: 35%;"><?php esc_html_e( 'Depends On', 'samybaxy-hyperdrive' ); ?></th>
                        <th style="width: 35%;"><?php esc_html_e( 'Required By', 'samybaxy-hyperdrive' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    ksort( $dependency_map );
                    foreach ( $dependency_map as $plugin_slug => $data ) :
                        $depends_on = ! empty( $data['depends_on'] ) ? $data['depends_on'] : [];
                        $required_by = ! empty( $data['plugins_depending'] ) ? $data['plugins_depending'] : [];
                        ?>
                        <tr>
                            <td>
                                <span class="shypdr-plugin-name"><?php echo esc_html( $plugin_slug ); ?></span>
                            </td>
                            <td>
                                <?php if ( ! empty( $depends_on ) ) : ?>
                                    <?php foreach ( $depends_on as $dep ) : ?>
                                        <span class="shypdr-dep-badge depends"><?php echo esc_html( $dep ); ?></span>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <span class="shypdr-dep-badge none"><?php esc_html_e( 'None', 'samybaxy-hyperdrive' ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ( ! empty( $required_by ) ) : ?>
                                    <?php foreach ( $required_by as $req ) : ?>
                                        <span class="shypdr-dep-badge required"><?php echo esc_html( $req ); ?></span>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <span class="shypdr-dep-badge none"><?php esc_html_e( 'None', 'samybaxy-hyperdrive' ); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div style="margin-top: 30px; padding: 20px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                <h3><?php esc_html_e( 'How Dependencies Are Detected', 'samybaxy-hyperdrive' ); ?></h3>
                <ul>
                    <li><strong><?php esc_html_e( 'WordPress 6.5+ Headers:', 'samybaxy-hyperdrive' ); ?></strong> <?php esc_html_e( 'Reads "Requires Plugins" header from plugin files', 'samybaxy-hyperdrive' ); ?></li>
                    <li><strong><?php esc_html_e( 'Code Analysis:', 'samybaxy-hyperdrive' ); ?></strong> <?php esc_html_e( 'Detects class_exists(), defined() checks for parent plugins', 'samybaxy-hyperdrive' ); ?></li>
                    <li><strong><?php esc_html_e( 'Naming Patterns:', 'samybaxy-hyperdrive' ); ?></strong> <?php esc_html_e( '"jet-*" depends on "jet-engine", "woocommerce-*" depends on "woocommerce"', 'samybaxy-hyperdrive' ); ?></li>
                    <li><strong><?php esc_html_e( 'Known Ecosystems:', 'samybaxy-hyperdrive' ); ?></strong> <?php esc_html_e( 'Built-in knowledge of major plugin families (Elementor, WooCommerce, LearnPress, etc.)', 'samybaxy-hyperdrive' ); ?></li>
                </ul>
                <p><strong><?php esc_html_e( 'Filter Hook:', 'samybaxy-hyperdrive' ); ?></strong> <?php esc_html_e( 'Developers can customize dependencies using the', 'samybaxy-hyperdrive' ); ?> <code>shypdr_dependency_map</code> <?php esc_html_e( 'filter.', 'samybaxy-hyperdrive' ); ?></p>
            </div>
        </div>
        <?php
    }
}
