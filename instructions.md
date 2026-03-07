# Hyperdrive MU-Loader Overhaul: Whitelist to Blacklist Architecture

## Problem Statement

The current MU-loader uses a **whitelist approach** — it maintains hardcoded lists of plugins to load for each page type (WooCommerce keywords, LearnPress keywords, affiliate keywords, etc.). Any plugin not explicitly listed gets filtered out on the frontend.

This causes recurring issues:
- **Plugins silently break** — e.g., `user-switching`, AffiliateWP Portal, and any newly installed utility plugin gets blocked because nobody remembered to add it to the MU-loader.
- **Every new plugin requires a code change** — adding a plugin to the site means editing `shypdr-mu-loader.php`, committing, deploying to SVN, and updating the MU-loader on every site.
- **Hardcoded keyword-to-plugin mappings** in `detect_from_keywords()` are brittle and site-specific.
- **Hardcoded dependency maps** (`$fallback_dependencies`, `$fallback_reverse_deps`) duplicate logic already handled by `class-dependency-detector.php`.

## Solution: Invert to Blacklist + Heavyweight Restriction

Flip the logic: **load everything by default, only restrict known-heavy plugins when their page conditions aren't met.**

### Core Concept

Instead of:
```
allowed = essential + detected_for_this_page
filter out everything NOT in allowed
```

Do:
```
restrictable = heavy plugins that are conditionally needed (WooCommerce, LearnPress, etc.)
needed_here = restrictable plugins detected for this page
filter out = restrictable - needed_here
load = all_active - filter_out
```

This means:
- Lightweight plugins (`user-switching`, `code-snippets`, `header-footer-code-manager`, analytics, SEO, small utilities) **always load** — they're never in the restrictable set.
- Only heavy plugin ecosystems (WooCommerce + extensions, LearnPress + extensions, bbPress, The Events Calendar, etc.) get conditionally restricted.
- New plugins auto-load unless the scanner explicitly flags them as heavy/conditional.

## Architecture

### Data Flow

```
[Admin: Plugin activated/deactivated or manual rescan]
    |
    v
[SHYPDR_Plugin_Scanner::scan_active_plugins()]
    - Scores each plugin (existing logic: critical=80+, conditional=40-79, optional=0-39)
    - NEW: Builds "restrictable set" from conditional plugins that belong to heavy ecosystems
    |
    v
[DB: shypdr_restrictable_plugins] — stored as option
    - Array of plugin slugs that CAN be restricted
    - e.g., ['woocommerce', 'jet-woo-builder', 'learnpress', 'bbpress', 'the-events-calendar', ...]
    - Includes ecosystem children (WooCommerce extensions, LearnPress addons, etc.)
    |
    v
[DB: shypdr_restriction_rules] — stored as option
    - Maps each restrictable plugin to its loading conditions
    - e.g., 'woocommerce' => ['keywords' => ['shop','product','cart','checkout','my-account'], 'post_types' => ['product']]
    - e.g., 'learnpress' => ['keywords' => ['courses','course','lesson','quiz'], 'post_types' => ['lp_course','lp_lesson']]
    |
    v
[MU-Loader: shypdr-mu-loader.php]
    - Reads restrictable set + restriction rules (2 DB queries, cached in static)
    - Reads URL requirements lookup table (1 DB query, existing)
    - For each restrictable plugin: check if current page needs it
    - Filter out restrictable plugins whose conditions are NOT met
    - Everything else loads
```

### What Goes in the Restrictable Set

The scanner should flag plugins as restrictable based on:

1. **Known heavy ecosystems** — WooCommerce + all extensions, LearnPress + addons, bbPress, The Events Calendar, AffiliateWP + addons, etc.
2. **Heuristic detection** — plugins that register many hooks, enqueue heavy assets, or have large file counts, BUT only if they're conditional (score 40-79).
3. **Explicit admin override** — the settings UI should let the user mark/unmark plugins as restrictable.

Plugins that should **NEVER** be restrictable (always load):
- Page builders and their addons (Elementor, Elementor Pro, Bricks, etc.)
- Theme framework plugins (JetEngine core, JetThemeCore, jet-menu, jet-blocks, etc.)
- The Hyperdrive plugin itself
- Plugins scored as "critical" (80+) by the scanner
- Small utility plugins (user-switching, code-snippets, etc.)

### Restriction Rules Structure

```php
$restriction_rules = [
    'woocommerce' => [
        'keywords' => ['shop', 'product', 'products', 'cart', 'checkout', 'my-account', 'order-received', 'order-pay'],
        'post_types' => ['product', 'shop_order', 'shop_coupon'],
        'shortcodes' => ['woocommerce_cart', 'woocommerce_checkout', 'products', 'add_to_cart'],
        'logged_in_only' => false,
    ],
    'learnpress' => [
        'keywords' => ['courses', 'course', 'lessons', 'lesson', 'quiz', 'quizzes', 'instructor'],
        'post_types' => ['lp_course', 'lp_lesson', 'lp_quiz'],
        'shortcodes' => ['learn_press_profile', 'learn_press_courses'],
        'logged_in_only' => false,
    ],
    'affiliatewp' => [
        'keywords' => ['affiliate', 'affiliates', 'referral', 'partner', 'partner-dashboard'],
        'post_types' => [],
        'shortcodes' => ['affiliate_area', 'affiliate_login', 'affiliate_registration'],
        'logged_in_only' => false,
    ],
    'bbpress' => [
        'keywords' => ['forums', 'forum', 'topics', 'topic', 'community', 'discussion'],
        'post_types' => ['forum', 'topic', 'reply'],
        'shortcodes' => [],
        'logged_in_only' => false,
    ],
    'the-events-calendar' => [
        'keywords' => ['events', 'event', 'calendar', 'tribe-events'],
        'post_types' => ['tribe_events', 'tribe_venue', 'tribe_organizer'],
        'shortcodes' => [],
        'logged_in_only' => false,
    ],
    // ...built dynamically by scanner, not hardcoded
];
```

### Ecosystem Grouping

When a parent plugin is restrictable, its children should follow the same rules. The dependency detector already knows these relationships. For example, if `woocommerce` is restricted on a page, all of these should also be restricted:
- `woocommerce-subscriptions`
- `woocommerce-memberships`
- `woocommerce-gateway-stripe`
- `jet-woo-builder`
- `jet-woo-product-gallery`
- etc.

The MU-loader should use the existing `shypdr_dependency_map` to resolve this — no need for separate hardcoded lists.

## Files to Modify

### 1. `includes/class-plugin-scanner.php`

**Add:**
- `build_restrictable_set()` — analyzes active plugins and returns the set of plugins that should be conditionally restricted.
- `build_restriction_rules()` — generates the keyword/post_type/shortcode rules for each restrictable plugin ecosystem.
- `get_restrictable_plugins($force_rebuild = false)` — returns cached restrictable set from DB, rebuilds if needed.

**Logic for building the restrictable set:**
```php
// Pseudocode
foreach active_plugins as plugin:
    slug = get_slug(plugin)
    analysis = analyze_plugin(plugin)

    // Never restrict critical plugins
    if analysis.score >= 80:
        continue

    // Check if plugin belongs to a known heavy ecosystem
    if belongs_to_ecosystem(slug, ['woocommerce', 'learnpress', 'bbpress', ...]):
        restrictable[] = slug
        continue

    // Heuristic: large conditional plugins with many hooks
    if analysis.score >= 40 and analysis.hook_count > 20:
        restrictable[] = slug
```

**Logic for building restriction rules:**
- For each ecosystem parent in the restrictable set, generate rules from the existing `$shortcode_map`, `$post_type_map`, and keyword lists in `class-content-analyzer.php`.
- Store as `shypdr_restriction_rules` option.

### 2. `includes/class-requirements-cache.php`

**Add:**
- Store restrictable set alongside lookup table.
- Provide `get_restrictable_plugins()` and `get_restriction_rules()` methods for MU-loader consumption.

### 3. `mu-loader/shypdr-mu-loader.php` — Major Rewrite

**Replace** the current whitelist logic with:

```php
// Pseudocode for new filter_plugins()

// 1. Read restrictable set from DB (1 query, cached)
$restrictable = get_option('shypdr_restrictable_plugins'); // ['woocommerce', 'learnpress', ...]

// 2. If no restrictable set configured, load everything (safe default)
if empty($restrictable):
    return $plugins

// 3. Read restriction rules from DB (1 query, cached)
$rules = get_option('shypdr_restriction_rules');

// 4. Read URL lookup table (1 query, existing)
$lookup = get_lookup_table();

// 5. Determine which restrictable plugins ARE needed on this page
$needed = [];

// 5a. Check URL lookup table (content-analyzed per-page requirements)
if slug in lookup:
    needed += lookup[slug]

// 5b. Check restriction rules (keyword/URL matching)
foreach rules as ecosystem_slug => rule:
    if uri_matches_keywords(rule.keywords):
        needed[] = ecosystem_slug
    if rule.logged_in_only and not logged_in:
        continue

// 5c. Resolve dependencies — if woocommerce is needed, all its children are needed too
$needed = resolve_needed_with_deps($needed, $dependency_map)

// 6. Build the restriction set: restrictable plugins NOT needed here
$restrict_set = array_diff($restrictable, $needed)

// 7. Filter: remove restricted plugins, keep everything else
$filtered = [];
foreach $plugins as $plugin_path:
    $slug = get_slug($plugin_path)
    if slug NOT in $restrict_set:
        $filtered[] = $plugin_path

return $filtered
```

**Key changes:**
- Remove `get_essential_plugins()` — no longer needed (everything is essential by default).
- Remove `detect_from_keywords()` — replaced by DB-stored restriction rules.
- Remove `$fallback_dependencies` and `$fallback_reverse_deps` — use DB-stored dependency map only.
- Remove `get_payment_gateway_plugins()` and `get_media_plugins()` — these are no longer special cases; they're just "not restrictable" so they always load.
- Keep `resolve_dependencies()` but simplify — only used to expand "needed" set, not to build a full load list.
- Keep all the early bail-out checks (admin, AJAX, REST, CRON, CLI).
- Keep the safety guard (never filter below 3 plugins).

### 4. `includes/class-main.php`

**Add:**
- Hook into `activated_plugin` / `deactivated_plugin` to rebuild restrictable set.
- Add admin UI section showing which plugins are restrictable and their rules.
- Add manual override: admin can mark/unmark plugins as restrictable.

### 5. `samybaxy-hyperdrive.php`

**Add:**
- On activation: build restrictable set and restriction rules.
- On version upgrade: rebuild restrictable set.
- Bump version to `6.1.0` (this is a significant architectural change).

## Migration Path

1. **Backward compatible** — if `shypdr_restrictable_plugins` option doesn't exist, the MU-loader should fall back to loading everything (no filtering). This is safe.
2. **First run after upgrade** — the main plugin detects version change, triggers a rescan, builds the restrictable set and rules, stores in DB.
3. **MU-loader auto-updates** — the main plugin copies the new MU-loader to `mu-plugins/` on upgrade (existing behavior).

## Safety Guarantees

1. **No restrictable set = no filtering** — if the DB option is missing or empty, all plugins load.
2. **Safety minimum** — never return fewer than 3 plugins (existing guard).
3. **Admin/AJAX/REST/CRON/CLI always bypass** — existing behavior, unchanged.
4. **Content-analyzed pages override rules** — if the lookup table says a page needs WooCommerce (because it has a `[products]` shortcode), WooCommerce loads even if the URL doesn't match any keyword.
5. **Logged-in user detection** — some ecosystems (membership plugins) should only be restricted for logged-out users.

## Testing Checklist

- [ ] Fresh install: no restrictable set exists, all plugins load normally.
- [ ] After rescan: restrictable set is built, lightweight plugins confirmed NOT in it.
- [ ] Homepage: heavy plugins restricted, `user-switching` loads, page builders load.
- [ ] `/shop/` page: WooCommerce + extensions load, LearnPress restricted.
- [ ] `/courses/` page: LearnPress loads, WooCommerce restricted.
- [ ] `/partner-dashboard/`: AffiliateWP + all addons load.
- [ ] Admin pages: all plugins load (bypass).
- [ ] REST/AJAX requests: all plugins load (bypass).
- [ ] New plugin activated: auto-loads on frontend without code changes.
- [ ] Plugin with content-analyzed shortcode on a page: loads on that page regardless of URL keywords.
- [ ] Debug widget shows correct restriction data.

## Version

Bump to `6.1.0` — this is a breaking change in MU-loader architecture.

## SVN Release Process

1. Update all files in `trunk/`.
2. Create `tags/6.1.0/` from trunk.
3. Update `readme.txt` with changelog entry.
4. Commit trunk and tag together.
