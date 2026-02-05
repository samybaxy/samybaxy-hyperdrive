<?php
/**
 * Plugin Name: Samybaxy's Hyperdrive
 * Plugin URI: https://github.com/samybaxy/samybaxy-hyperdrive
 * Description: Revolutionary plugin filtering - Load only essential plugins per page. Requires MU-plugin loader for actual performance gains.
 * Version: 6.0.2
 * Author: samybaxy
 * Author URI: https://github.com/samybaxy
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: samybaxy-hyperdrive
 * Requires at least: 6.4
 * Requires PHP: 8.2
 *
 * @package SamybaxyHyperdrive
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Core initialization constants
define('SHYPDR_VERSION', '6.0.2');
define('SHYPDR_DIR', plugin_dir_path(__FILE__));
define('SHYPDR_URL', plugin_dir_url(__FILE__));
define('SHYPDR_BASENAME', plugin_basename(__FILE__));

// Load core components
require_once SHYPDR_DIR . 'includes/class-dependency-detector.php';
require_once SHYPDR_DIR . 'includes/class-plugin-scanner.php';
require_once SHYPDR_DIR . 'includes/class-detection-cache.php';
require_once SHYPDR_DIR . 'includes/class-content-analyzer.php';
require_once SHYPDR_DIR . 'includes/class-requirements-cache.php';
require_once SHYPDR_DIR . 'includes/class-main.php';

// Initialize plugin on WordPress hooks
if (class_exists('SHYPDR_Main')) {
    add_action('plugins_loaded', [SHYPDR_Main::class, 'init'], 5);
}

// Activation hook - Run intelligent plugin scan and install MU-loader
register_activation_hook(__FILE__, 'shypdr_activation_handler');

function shypdr_activation_handler() {
    // Set default options using add_option (won't overwrite existing)
    add_option('shypdr_enabled', false);
    add_option('shypdr_debug_enabled', false);

    // Flag that we need to run first-time setup (for scanning)
    update_option('shypdr_needs_setup', true);

    // CRITICAL: Install/update MU-loader during activation
    // This ensures the MU-loader is always the latest version
    // and prevents old MU-loaders from interfering with activation
    shypdr_install_mu_loader();

    // Rebuild dependency map on activation (includes WP 6.5+ header support)
    if (class_exists('SHYPDR_Dependency_Detector')) {
        SHYPDR_Dependency_Detector::rebuild_dependency_map();
    }

    // Store current version for upgrade detection
    update_option('shypdr_version', SHYPDR_VERSION);
}

/**
 * Check for plugin version upgrade and run migrations
 */
function shypdr_check_version_upgrade() {
    $stored_version = get_option('shypdr_version', '0');

    if (version_compare($stored_version, SHYPDR_VERSION, '<')) {
        // Version upgrade detected - rebuild dependency map
        // This ensures WP 6.5+ Requires Plugins header data is picked up
        if (class_exists('SHYPDR_Dependency_Detector')) {
            SHYPDR_Dependency_Detector::rebuild_dependency_map();
        }

        // Update MU-loader to latest version
        shypdr_install_mu_loader();

        // Store new version
        update_option('shypdr_version', SHYPDR_VERSION);
    }
}
add_action('admin_init', 'shypdr_check_version_upgrade');

/**
 * Install MU-plugin loader automatically
 *
 * @return bool|WP_Error Success or error
 */
function shypdr_install_mu_loader() {
    $mu_plugins_dir = WPMU_PLUGIN_DIR;
    $source_file = SHYPDR_DIR . 'mu-loader/shypdr-mu-loader.php';
    $dest_file = $mu_plugins_dir . '/shypdr-mu-loader.php';

    // Check if source file exists
    if ( ! file_exists( $source_file ) ) {
        return new WP_Error( 'source_missing', __( 'MU-loader source file not found', 'samybaxy-hyperdrive' ) );
    }

    // Create mu-plugins directory if it doesn't exist
    if ( ! file_exists( $mu_plugins_dir ) ) {
        if ( ! wp_mkdir_p( $mu_plugins_dir ) ) {
            return new WP_Error( 'mkdir_failed', __( 'Could not create mu-plugins directory', 'samybaxy-hyperdrive' ) );
        }
    }

    // Check if we can write to mu-plugins directory
    // Use WordPress filesystem check instead of is_writable()
    if ( ! wp_is_writable( $mu_plugins_dir ) ) {
        return new WP_Error( 'not_writable', __( 'mu-plugins directory is not writable', 'samybaxy-hyperdrive' ) );
    }

    // Copy MU-loader file
    if ( ! copy( $source_file, $dest_file ) ) {
        return new WP_Error( 'copy_failed', __( 'Could not copy MU-loader file', 'samybaxy-hyperdrive' ) );
    }

    return true;
}

/**
 * Uninstall MU-plugin loader
 *
 * @return bool Success
 */
function shypdr_uninstall_mu_loader() {
    $dest_file = WPMU_PLUGIN_DIR . '/shypdr-mu-loader.php';

    if (file_exists($dest_file)) {
        return wp_delete_file($dest_file);
    }

    return true;
}

/**
 * Check if MU-loader is installed and active
 *
 * @return bool
 */
function shypdr_is_mu_loader_active() {
    // Check if constant is defined (MU-loader is running)
    if (defined('SHYPDR_MU_LOADER_ACTIVE') && SHYPDR_MU_LOADER_ACTIVE === true) {
        return true;
    }

    // Also check if file exists (for immediate feedback after installation)
    $mu_loader_file = WPMU_PLUGIN_DIR . '/shypdr-mu-loader.php';
    return file_exists($mu_loader_file);
}

/**
 * Get MU-loader filter data
 *
 * @return array|null Filter data or null if not available
 */
function shypdr_get_mu_filter_data() {
    if (!shypdr_is_mu_loader_active()) {
        return null;
    }

    return $GLOBALS['shypdr_mu_filter_data'] ?? null;
}

// Deactivation hook - Cleanup
register_deactivation_hook(__FILE__, 'shypdr_deactivation_handler');

function shypdr_deactivation_handler() {
    // Clear all caches on deactivation
    if (class_exists('SHYPDR_Detection_Cache')) {
        SHYPDR_Detection_Cache::clear_all_caches();
    }

    // Clear transients
    delete_transient('shypdr_logs');
    delete_transient('shypdr_activation_notice');

    // Note: We don't remove MU-loader on deactivation, only on uninstall
}

// Uninstall hook
register_uninstall_hook(__FILE__, 'shypdr_uninstall_handler');

function shypdr_uninstall_handler() {
    // Remove MU-loader
    shypdr_uninstall_mu_loader();

    // Clean up all options
    delete_option('shypdr_enabled');
    delete_option('shypdr_debug_enabled');
    delete_option('shypdr_essential_plugins');
    delete_option('shypdr_plugin_analysis');
    delete_option('shypdr_scan_completed');
    delete_option('shypdr_needs_setup');

    // Clean up transients
    delete_transient('shypdr_logs');

    // Clean up caches (check if class exists during uninstall)
    if (class_exists('SHYPDR_Detection_Cache')) {
        SHYPDR_Detection_Cache::clear_all_caches();
    }
}

// First-time setup on admin load (runs once after activation)
add_action('admin_init', 'shypdr_first_time_setup');

function shypdr_first_time_setup() {
    // Only run if setup is needed
    if (!get_option('shypdr_needs_setup')) {
        return;
    }

    // Only run for users who can manage options
    if (!current_user_can('manage_options')) {
        return;
    }

    // Clear the setup flag first to prevent re-runs
    delete_option('shypdr_needs_setup');

    // Now run the setup operations (these are safe to fail)
    try {
        // Clear any old caches
        if (class_exists('SHYPDR_Detection_Cache')) {
            SHYPDR_Detection_Cache::clear_all_caches();
        }

        // Run intelligent plugin scanner on first activation
        if (class_exists('SHYPDR_Plugin_Scanner')) {
            if (!SHYPDR_Plugin_Scanner::is_scan_completed()) {
                SHYPDR_Plugin_Scanner::get_essential_plugins(true);
                set_transient('shypdr_activation_notice', true, 60);
            }
        }

        // Attempt to install MU-loader automatically
        shypdr_install_mu_loader();
    } catch (Exception $e) {
        // Silently handle installation errors (user can manually install)
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
    } catch (Error $e) {
        // Silently handle installation errors (user can manually install)
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
    }
}

// Admin notice for MU-loader status
add_action('admin_notices', 'shypdr_admin_notices');

function shypdr_admin_notices() {
    // Only show on our settings page or plugins page
    $screen = get_current_screen();
    if (!$screen || !in_array($screen->id, ['settings_page_shypdr-settings', 'plugins'])) {
        return;
    }

    // Check if filtering is enabled but MU-loader is not active
    $enabled = get_option('shypdr_enabled', false);

    if ( $enabled && ! shypdr_is_mu_loader_active() ) {
        ?>
        <div class="notice notice-error">
            <p><strong><?php esc_html_e( 'Samybaxy\'s Hyperdrive: MU-Loader Required!', 'samybaxy-hyperdrive' ); ?></strong></p>
            <p><?php esc_html_e( 'Plugin filtering is enabled but the MU-loader is not installed.', 'samybaxy-hyperdrive' ); ?> <strong><?php esc_html_e( 'Without the MU-loader, filtering will NOT work.', 'samybaxy-hyperdrive' ); ?></strong></p>
            <p>
                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'options-general.php?page=shypdr-settings&shypdr_install_mu=1' ), 'shypdr_install_mu' ) ); ?>"
                   class="button button-primary">
                    <?php esc_html_e( 'Install MU-Loader Now', 'samybaxy-hyperdrive' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    // Success notice after MU-loader installation
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice, no action taken
    if ( isset( $_GET['shypdr_mu_installed'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['shypdr_mu_installed'] ) ) ) {
        ?>
        <div class="notice notice-success is-dismissible">
            <p><strong><?php esc_html_e( 'MU-Loader installed successfully!', 'samybaxy-hyperdrive' ); ?></strong> <?php esc_html_e( 'Plugin filtering is now active and will work on the next page load.', 'samybaxy-hyperdrive' ); ?></p>
        </div>
        <?php
    }

    // Error notice if MU-loader installation failed
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice, no action taken
    if ( isset( $_GET['shypdr_mu_error'] ) ) {
        ?>
        <div class="notice notice-error is-dismissible">
            <p><strong><?php esc_html_e( 'MU-Loader installation failed:', 'samybaxy-hyperdrive' ); ?></strong> <?php
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only error message, no action taken
            echo esc_html( urldecode( sanitize_text_field( wp_unslash( $_GET['shypdr_mu_error'] ) ) ) ); ?></p>
            <p><?php
            printf(
                /* translators: 1: source file path, 2: destination file path */
                esc_html__( 'Please manually copy %1$s to %2$s', 'samybaxy-hyperdrive' ),
                '<code>wp-content/plugins/samybaxy-hyperdrive/mu-loader/shypdr-mu-loader.php</code>',
                '<code>wp-content/mu-plugins/shypdr-mu-loader.php</code>'
            );
            ?></p>
        </div>
        <?php
    }
}

// Add plugin meta links (shown in plugins list)
add_filter( 'plugin_row_meta', 'shypdr_plugin_row_meta', 10, 2 );

/**
 * Add custom links to plugin row meta
 *
 * @param array  $links Existing meta links.
 * @param string $file  Plugin file path.
 * @return array Modified meta links.
 */
function shypdr_plugin_row_meta( $links, $file ) {
    if ( SHYPDR_BASENAME === $file ) {
        $links[] = sprintf(
            '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
            esc_url( 'https://github.com/samybaxy/samybaxy-hyperdrive/issues' ),
            esc_html__( 'Report Issues', 'samybaxy-hyperdrive' )
        );
    }
    return $links;
}

// Handle MU-loader installation request
add_action('admin_init', 'shypdr_handle_mu_install');

function shypdr_handle_mu_install() {
    if ( ! isset( $_GET['shypdr_install_mu'] ) || ! isset( $_GET['_wpnonce'] ) ) {
        return;
    }

    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'shypdr_install_mu' ) ) {
        wp_die( esc_html__( 'Security check failed', 'samybaxy-hyperdrive' ) );
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Access denied', 'samybaxy-hyperdrive' ) );
    }

    $result = shypdr_install_mu_loader();

    if (is_wp_error($result)) {
        wp_safe_redirect(add_query_arg([
            'page' => 'shypdr-settings',
            'shypdr_mu_error' => urlencode($result->get_error_message())
        ], admin_url('options-general.php')));
    } else {
        wp_safe_redirect(add_query_arg([
            'page' => 'shypdr-settings',
            'shypdr_mu_installed' => '1'
        ], admin_url('options-general.php')));
    }
    exit;
}
