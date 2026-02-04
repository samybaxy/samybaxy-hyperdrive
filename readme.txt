=== Samybaxy's Hyperdrive ===
Contributors: samybaxy
Donate link: https://github.com/samybaxy/samybaxy-hyperdrive/blob/main/DONATE.md
Tags: performance, optimization, speed, caching, conditional-loading
Requires at least: 6.4
Tested up to: 6.9
Stable tag: 6.0.1
Requires PHP: 8.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Load only essential plugins per page for 65-75% faster WordPress sites through intelligent filtering.

== Description ==

**Status:** Production Ready
**Current Version:** 6.0.1

Samybaxy's Hyperdrive makes WordPress sites **65-75% faster** by intelligently loading only the plugins needed for each page.

Instead of loading 120 plugins for every page, we load only 12-45 plugins for the current page - automatically, without breaking anything.

= Key Features =

* **85-90% plugin reduction** on most pages
* **65-75% faster page loads** without caching
* **Zero configuration needed** - works automatically
* **Zero broken functionality** - intelligent dependency detection
* **Automatic dependency resolution** - understands plugin ecosystems
* **Multi-layer caching** - 60-75% faster on cached requests
* **Admin-only debug widget** - real-time performance monitoring

= How It Works =

Traditional WordPress loads ALL plugins on EVERY page:
* Shop page loads: WooCommerce, LearnPress, Forms, Analytics, SEO... (120 plugins)
* Blog page loads: WooCommerce, LearnPress, Forms, Analytics, SEO... (120 plugins)
* Result: Slow sites (3-8 seconds TTFB)

**Samybaxy's Hyperdrive intelligently filters plugins:**
* Shop page loads: WooCommerce + dependencies only (35 plugins)
* Blog page loads: Blog plugins + dependencies only (18 plugins)
* Result: **65-75% faster!**

= Intelligent Detection System =

The plugin automatically detects which plugins are needed via:

1. **URL-based detection** - Recognizes WooCommerce, courses, membership, blog pages
2. **Content analysis** - Scans post content for shortcodes and page builder widgets
3. **Dependency resolution** - Automatically loads all required plugin dependencies
4. **User role detection** - Loads extra plugins for logged-in users, affiliates, members
5. **Smart defaults** - Always loads essential plugins (page builders, theme cores)

= Supported Plugin Ecosystems =

* **JetEngine** - jet-engine, jet-menu, jet-blocks, jet-elements, jet-tabs, jet-popup, jet-woo-builder, and 10+ modules
* **WooCommerce** - woocommerce, memberships, subscriptions, product bundles, smart coupons
* **Elementor** - elementor, elementor-pro, the-plus-addons, thim-elementor-kit
* **Content Restriction** - restrict-content-pro, rcp-content-filter-utility
* **Automation** - uncanny-automator, fluent-crm
* **Forms** - fluentform, fluentformpro, jetformbuilder
* **Other** - LearnPress, Affiliate WP, EmbedPress, Presto Player, and many more

= Safety Features =

* Never filters WordPress admin area
* Never filters AJAX requests
* Never filters REST API requests
* Never filters WP-CRON requests
* Validates plugin existence before loading
* Maintains WordPress native plugin load order
* Falls back to loading all plugins if anything breaks
* **Security:** Debug widget only visible to administrators
* **Security:** Plugin info hidden from frontend users and visitors
* **Clean:** No error logging or debug output

= Performance Optimization =

* **Expected reduction:** 85-90% fewer plugins loading on most pages
* **Speed improvement:** 65-75% faster page loads
* **Memory savings:** 40-60% less memory usage
* **Filter overhead:** < 2.5ms per request
* **Server cost reduction:** 60-70% for same traffic

= What's New in v5.1 =

**Heuristic Dependency Detection System** - Zero Manual Maintenance!

The plugin now automatically detects plugin dependencies using 4 intelligent methods:

1. **WordPress 6.5+ Headers** - Reads official "Requires Plugins" header
2. **Code Analysis** - Scans for class_exists(), defined(), hook patterns
3. **Pattern Matching** - Recognizes naming conventions (jet-*, woocommerce-*, elementor-*)
4. **Known Ecosystems** - Validates with curated plugin relationships

**Benefits:**
* Zero manual maintenance - dependencies auto-detected
* Works with custom/proprietary plugins automatically
* Auto-rebuilds on plugin activation/deactivation
* Database storage for fast retrieval

= What's New in v5.0 =

**Intelligent Plugin Scanner** - Heuristic Analysis System

Automatically analyzes all plugins and scores them 0-100 based on:
* Known patterns (page builders, theme cores)
* Keywords in name/description
* Hook registrations (wp_head, wp_footer, etc.)
* Asset enqueuing (global CSS/JS)
* Custom post type registration

**Detection Result Caching** - 60-75% Faster Filtering

Dual-layer caching system:
* **Requirements Cache** - Pre-computed URL to plugins mapping
* **Detection Cache** - Runtime caching with object cache support
* **Performance:** 0.3-0.8ms cached (vs 1.2-2.1ms uncached)

= Admin Interface =

Settings page at **Settings > Samybaxy's Hyperdrive** with:
* Enable/disable plugin filtering checkbox
* Enable/disable debug widget checkbox
* Intelligent plugin scanner with visual cards
* Dependency map viewer with statistics
* Performance logs showing recent page loads
* Cache statistics and management
* Stats: plugins loaded, plugins filtered, reduction percentage

= Debug Widget =

Floating widget that appears on frontend when enabled:
* **Admin only** - Only visible to logged-in administrators
* Frontend users and incognito visitors cannot see it (security)
* Shows total plugins available
* Shows plugins loaded this page
* Shows plugins filtered out
* Shows reduction percentage
* Lists essential detected plugins
* Shows sample of filtered out plugins
* Fully interactive with expand/collapse
* Responsive design (works on mobile)

= Performance Statistics =

Typical performance improvements:

| Page Type | Before | After | Improvement |
|-----------|--------|-------|-------------|
| Homepage | 3.5s TTFB | 1.2s TTFB | 65% faster |
| Shop Page | 4.2s TTFB | 1.4s TTFB | 67% faster |
| Blog Page | 2.8s TTFB | 0.8s TTFB | 71% faster |
| Course Page | 5.1s TTFB | 1.9s TTFB | 63% faster |

== Installation ==

= Automatic Installation =

1. Upload the plugin files to `/wp-content/plugins/samybaxy-hyperdrive/` directory
2. Go to WordPress Admin > Plugins
3. Find "Samybaxy's Hyperdrive" and click "Activate"
4. Go to Settings > Samybaxy's Hyperdrive
5. Check "Enable Plugin Filtering"
6. Save changes

That's it! The plugin works automatically with zero configuration.

= MU-Loader Installation (Recommended) =

For best performance, install the MU-loader:

1. After activating the plugin, go to Settings > Samybaxy's Hyperdrive
2. Click "Install MU-Loader Now" button (if prompted)
3. The MU-loader will be automatically installed
4. This enables filtering BEFORE WordPress loads plugins

Alternatively, manually copy:
* From: `wp-content/plugins/samybaxy-hyperdrive/mu-loader/shypdr-mu-loader.php`
* To: `wp-content/mu-plugins/shypdr-mu-loader.php`

= Manual Installation =

1. Download the plugin files
2. Extract to `/wp-content/plugins/samybaxy-hyperdrive/`
3. Activate from WordPress Admin > Plugins
4. Enable filtering in Settings > Samybaxy's Hyperdrive

== Frequently Asked Questions ==

= Does it work with WooCommerce? =

Yes! WooCommerce + all 15+ extensions are fully supported with automatic dependency detection.

= Does it work with JetEngine? =

Yes! JetEngine + all 18+ add-ons are fully supported.

= Does it work with Elementor? =

Yes! Elementor + Pro + all add-ons are fully supported.

= What if plugins break? =

The system automatically detects issues and loads all plugins as a fallback. You can also disable filtering temporarily from Settings > Samybaxy's Hyperdrive.

= Does it require configuration? =

No! Works automatically with zero configuration. The intelligent scanner and dependency detector handle everything.

= What about WordPress admin? =

Admin always loads all plugins (safe by design). Filtering only happens on frontend pages.

= Can I disable it temporarily? =

Yes, go to Settings > Samybaxy's Hyperdrive and uncheck "Enable Plugin Filtering".

= Does it work with caching plugins? =

Yes! Works great together with WP Rocket, LiteSpeed Cache, and other caching plugins.

= Will it improve my Google ranking? =

Yes! Faster pages rank better in Google. Core Web Vitals improvements directly impact SEO.

= Is it safe? =

Yes. The plugin understands dependencies, won't break functionality, and has automatic fallbacks.

= How much memory does it use? =

~110KB additional overhead (includes caching system).

= How fast is the filter? =

0.3-0.8ms per request (cached) or 1.2-2.1ms (uncached). Target is < 2.5ms.

= Can I customize which plugins are essential? =

Yes! Go to Settings > Samybaxy's Hyperdrive > Essential Plugins tab to customize via the admin UI.

= How do I debug if something breaks? =

1. Go to Settings > Samybaxy's Hyperdrive
2. Check "Recent Performance Logs" to see which plugins were loaded
3. Enable "Debug Widget" to see real-time stats on frontend (admin only)
4. Temporarily disable filtering to verify it's the cause

= Does it work with multisite? =

Yes, the plugin supports WordPress multisite installations.

== Screenshots ==

1. The main settings page with options to Manage Essential Plugins, Plugin Dependencies, and rebuild cache.
2. The settings subpage where you can manage your essential plugin list or scan all plugins for heuristics.
3. The settings subpage where you can manage and map plugin dependencies.
4. The frontPage with Debug bar displaying plugin filteration data.
5. GTMetrix score for Dev environment without optimizations for over 124 plugins.
6. GTMetrix score for Dev environment running Optimization with NitroPack only on WPEngine Host.
7. GTMetrix score for Dev environment running Optimization with NitroPack and HyperDrive on WPEngine Host.

== Changelog ==

= 6.0.1 - February 1, 2026 =
🛒 Checkout & Payment Gateway Fixes
* 🐛 Fixed: Payment gateways (Stripe, PayPal, etc.) not loading on checkout pages
* ✨ New: Dynamic payment gateway detection for checkout/cart pages
* 🔧 Improved: Streamlined checkout plugin loading for better performance
* 🔧 Improved: Membership plugins now only load on checkout for logged-in users

= 6.0.0 - January 29, 2026 =
🚀 Official Rebrand & WordPress.org Submission
* ⚠️ Breaking: Complete plugin rename from "Turbo Charge" to "Samybaxy's Hyperdrive"
* ⚠️ Breaking: Slug changed from "turbo-charge" to "samybaxy-hyperdrive"
* ⚠️ Breaking: All prefixes changed from TC_/tc_ to SHYPDR_/shypdr_ (6-char distinctive prefix)
* ⚠️ Breaking: MU-loader renamed from tc-mu-loader.php to shypdr-mu-loader.php
* ✨ New: Extracted inline CSS to separate admin-styles.css file
* 🔧 Improved: WordPress.org plugin review compliance
* 🔧 Improved: All database options, transients, and post meta use new prefix
* 🔧 Improved: All CSS classes use new shypdr- prefix
* 📝 Note: Fresh installation required - settings from previous versions will not migrate

= 5.1.0 - December 14, 2025 =
🧠 Zero-Maintenance Dependency Detection
* ✨ New: Heuristic Dependency Detection System with 4 intelligent methods
* ✨ New: WordPress 6.5+ "Requires Plugins" header support
* ✨ New: Code analysis for class_exists(), defined(), and hook patterns
* ✨ New: Pattern matching for naming conventions (jet-*, woocommerce-*, elementor-*)
* ✨ New: Database storage with automatic rebuild on plugin changes
* 🔧 Improved: Dependencies admin page with visual statistics dashboard
* 🔧 Improved: Auto-rebuild triggers on plugin activation/deactivation
* 🔧 Improved: Debug widget now shows scrollable full plugin lists
* 🐛 Fixed: Membership plugins now load on shop page for logged-in users
* 🐛 Fixed: Numeric output escaping in printf() calls
* 🗑️ Removed: Hardcoded dependency map (replaced with heuristic detection)
* ✅ Compliance: Complete internationalization (i18n) for WordPress.org
* ✅ Compliance: WordPress Coding Standards and Plugin Check compatibility

= 5.0.0 - December 5, 2025 =
⚡ Intelligent Scanner & Multi-Layer Caching
* ✨ New: Intelligent Plugin Scanner with heuristic analysis (scores plugins 0-100)
* ✨ New: Dual-layer caching system (Requirements Cache + Detection Cache)
* ✨ New: Admin UI for managing essential plugins with visual cards
* ✨ New: Dynamic essential plugins (replaces static hardcoded whitelist)
* ✨ New: Requirements cache for O(1) hash lookups
* ✨ New: Content analyzer with intelligent shortcode/widget detection
* ✨ New: Filter hooks for developer extensibility (shypdr_essential_plugins, etc.)
* ✨ New: Automatic cache invalidation on content changes
* 🚀 Performance: 40-50% faster average filter time
* 🚀 Performance: 60-75% faster for cached requests (0.3-0.8ms vs 1.2-2.1ms)
* 🔧 Improved: More accurate essential plugin detection via heuristics
* 🔧 Improved: Better customization options through admin interface
* 🐛 Fixed: MU-loader cache early return bug
* 🐛 Fixed: Plugin scanner robustness with defensive checks

= 4.0.5 - August 2025 =
🏭 Production-Ready Stability Release
* 🐛 Fixed: Removed all error_log statements for production performance
* 🔧 Improved: Implemented recursion guard pattern for safe filtering
* 🔧 Improved: Cleaned up temporary debug files and documentation
* ✅ Stability: Production-ready implementation with comprehensive error handling

= 4.0.4 - August 2025 =
🛡️ Hook Filtering Stability
* ✨ New: Recursion guard mechanism to prevent infinite loops
* 🔧 Improved: Hook filtering reliability with dual protection
* 🔧 Improved: Enhanced type validation throughout codebase

= 4.0.3 - August 2025 =
🚨 Critical Bug Fix Release
* 🐛 Fixed: Critical 502 errors caused by infinite recursion in plugin filtering
* 🐛 Fixed: Array type checking to prevent type errors
* 🔧 Improved: Error handling with try-catch-finally blocks

= 4.0.2 - August 2025 =
🔍 Debug & Monitoring Improvements
* ✨ New: Elementor diagnostics for widget detection
* ✨ New: Debug widget for real-time performance monitoring on frontend
* 🔧 Improved: Admin settings page layout and usability
* 🔧 Improved: Enhanced performance logging with detailed statistics

= 4.0.1 - July 2025 =
🔌 Essential Plugins & Compatibility
* ✨ New: Critical whitelist for essential plugins (Elementor, JetEngine, etc.)
* 🐛 Fixed: Jet Menu rendering issues on frontend
* 🔧 Improved: Enhanced dependency detection for plugin ecosystems

= 4.0.0 - July 2025 =
🎉 Initial Public Release
* ✨ New: Core plugin filtering system with intelligent detection
* ✨ New: 50+ plugin dependency map covering major ecosystems
* ✨ New: URL-based detection for WooCommerce, LearnPress, membership pages
* ✨ New: Content analysis for shortcodes and page builder widgets
* ✨ New: User role detection for logged-in users and affiliates
* ✨ New: Recursive dependency resolver algorithm
* ✨ New: Safety fallbacks to prevent site breakage
* ✨ New: Admin settings page for configuration

== Upgrade Notice ==

= 6.0.1 =
🛒 Fixes payment gateway loading on checkout pages. Recommended update for all WooCommerce users.

= 6.0.0 =
⚠️ BREAKING CHANGE: Complete plugin rename to "Samybaxy's Hyperdrive". Fresh installation required - settings from "Turbo Charge" will not migrate. Please reconfigure after upgrade.

= 5.1.0 =
🧠 Major update with automatic dependency detection! Zero manual maintenance required. Dependencies auto-detected via 4 intelligent methods.

= 5.0.0 =
⚡ Major architecture update with intelligent plugin scanner and dual-layer caching. Performance improvements of 40-75% on filtering operations.

= 4.0.5 =
🏭 Production-ready stability release. Recommended for all users on 4.x versions.

== Technical Details ==

= Performance =

* **Time Complexity:** O(1) detection with cached lookups, O(m) filtering where m = active plugins
* **Space Complexity:** ~110KB memory overhead (includes caching)
* **Filter Speed:** 0.3-0.8ms cached, 1.2-2.1ms uncached
* **Plugin Reduction:** 85-90% on most pages
* **Speed Improvement:** 65-75% faster page loads

= Architecture =

* **Heuristic Dependency Detector** - Auto-detects plugin dependencies
* **Intelligent Plugin Scanner** - Analyzes and scores all plugins
* **Dual-Layer Caching** - Requirements cache + detection cache
* **Content Analyzer** - Intelligent content scanning with caching
* **Detection System** - URL, content, user role, and default detection
* **Resolver Algorithm** - Queue-based recursive dependency resolution
* **Safety Layer** - Backend detection, validation, and fallbacks

= Database Options =

All options use `shypdr_` prefix:
* `shypdr_enabled` - Enable/disable plugin filtering
* `shypdr_debug_enabled` - Enable/disable debug widget
* `shypdr_essential_plugins` - User-customized essential plugins
* `shypdr_dependency_map` - Auto-detected plugin dependencies
* `shypdr_plugin_analysis` - Cached scanner results
* `shypdr_url_requirements` - Pre-computed URL lookups
* `shypdr_logs` (transient) - Performance logs

= WordPress Hooks =

* `plugins_loaded` - Initialize core components
* `admin_menu` - Register settings page
* `admin_init` - Register settings fields
* `option_active_plugins` - Filter plugin list before WordPress loads them
* `wp_enqueue_scripts` - Load debug widget CSS/JS
* `wp_footer` - Render debug widget HTML
* `save_post` - Update requirements cache
* `activated_plugin` - Rebuild dependency map
* `deactivated_plugin` - Rebuild dependency map

= Filter Hooks for Developers =

* `shypdr_essential_plugins` - Override essential plugins
* `shypdr_dependency_map` - Override dependency map
* `shypdr_url_detected_plugins` - Customize URL detection
* `shypdr_content_detected_plugins` - Customize content detection

= Support =

For support and documentation:
* GitHub: https://github.com/samybaxy/samybaxy-hyperdrive
* Settings > Samybaxy's Hyperdrive - View performance logs
* Enable debug widget for real-time monitoring

== Credits ==

Developed by samybaxy with a focus on performance, safety, and zero configuration.

Special thanks to the WordPress community for their feedback and testing.