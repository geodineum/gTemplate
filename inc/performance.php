<?php
declare(strict_types=1);
/**
 * gTemplate Performance Optimizations
 *
 * Production-ready optimizations for PageSpeed performance:
 * - Disable WordPress emoji scripts (~3KB savings, removes render-blocking)
 * - Remove/conditionally load block library CSS (~116KB savings)
 * - Self-host HTMX instead of CDN (eliminates DNS lookup)
 * - Add resource hints (preconnect, dns-prefetch)
 * - Optimize script loading (defer, async)
 *
 * @package gTemplate
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Disable WordPress emoji scripts and styles
 *
 * WordPress loads emoji detection scripts on every page (~3KB).
 * Modern browsers have native emoji support, making this unnecessary.
 *
 * Savings: ~3KB JavaScript + ~1KB inline CSS + removes render-blocking
 */
function gtemplate_disable_emojis() {
    // Remove emoji script from head
    remove_action('wp_head', 'print_emoji_detection_script', 7);

    // Remove emoji styles
    remove_action('wp_print_styles', 'print_emoji_styles');

    // Remove emoji from admin (optional, for consistency)
    remove_action('admin_print_scripts', 'print_emoji_detection_script');
    remove_action('admin_print_styles', 'print_emoji_styles');

    // Remove emoji from RSS feeds
    remove_filter('the_content_feed', 'wp_staticize_emoji');
    remove_filter('comment_text_rss', 'wp_staticize_emoji');

    // Remove emoji from emails
    remove_filter('wp_mail', 'wp_staticize_emoji_for_email');

    // Remove emoji DNS prefetch
    add_filter('emoji_svg_url', '__return_false');

    // Remove TinyMCE emoji plugin (admin editor)
    add_filter('tiny_mce_plugins', 'gtemplate_disable_emojis_tinymce');
}
add_action('init', 'gtemplate_disable_emojis');

/**
 * Remove emoji plugin from TinyMCE editor
 *
 * @param array $plugins TinyMCE plugins
 * @return array Filtered plugins
 */
function gtemplate_disable_emojis_tinymce($plugins) {
    if (is_array($plugins)) {
        return array_diff($plugins, ['wpemoji']);
    }
    return $plugins;
}

/**
 * Remove WordPress block library CSS if not using Gutenberg blocks
 *
 * The block library CSS is ~116KB and loads on every page.
 * If you're not using Gutenberg blocks in your content, this is wasted bandwidth.
 *
 * Set GTEMPLATE_USE_GUTENBERG_BLOCKS to true in wp-config.php to keep block styles.
 *
 * Savings: ~116KB CSS (render-blocking)
 */
function gtemplate_optimize_block_styles() {
    // Allow override via constant
    if (defined('GTEMPLATE_USE_GUTENBERG_BLOCKS') && GTEMPLATE_USE_GUTENBERG_BLOCKS) {
        return;
    }

    // Check if any displayed content uses blocks
    if (gtemplate_page_uses_blocks()) {
        return;
    }

    // Remove block library CSS (external and inline)
    wp_dequeue_style('wp-block-library');
    wp_deregister_style('wp-block-library');
    wp_dequeue_style('wp-block-library-theme');
    wp_deregister_style('wp-block-library-theme');

    // Remove WooCommerce block styles if present
    wp_dequeue_style('wc-blocks-style');
    wp_dequeue_style('wc-block-style');

    // Remove global styles (inline CSS from theme.json / Gutenberg)
    wp_dequeue_style('global-styles');
    wp_deregister_style('global-styles');

    // Remove classic theme styles (button reset styles, etc.)
    wp_dequeue_style('classic-theme-styles');
    wp_deregister_style('classic-theme-styles');
}
add_action('wp_enqueue_scripts', 'gtemplate_optimize_block_styles', 100);

/**
 * Belt-and-suspenders: remove WP bloat styles that survive dequeue
 *
 * WordPress 6.x can re-add global-styles and classic-theme-styles as inline
 * output after wp_enqueue_scripts runs. Hooking wp_print_styles at a late
 * priority catches anything re-registered between enqueue and print.
 */
function gtemplate_remove_residual_block_styles() {
    if (defined('GTEMPLATE_USE_GUTENBERG_BLOCKS') && GTEMPLATE_USE_GUTENBERG_BLOCKS) {
        return;
    }
    if (gtemplate_page_uses_blocks()) {
        return;
    }

    wp_dequeue_style('global-styles');
    wp_deregister_style('global-styles');
    wp_dequeue_style('classic-theme-styles');
    wp_deregister_style('classic-theme-styles');
    wp_dequeue_style('wp-block-library');
    wp_deregister_style('wp-block-library');
}
add_action('wp_print_styles', 'gtemplate_remove_residual_block_styles', 100);

/**
 * Check if current page content uses Gutenberg blocks
 *
 * @return bool True if blocks are detected
 */
function gtemplate_page_uses_blocks() {
    // On singular pages, check the content
    if (is_singular()) {
        global $post;
        if ($post && has_blocks($post->post_content)) {
            return true;
        }
    }

    // Check face content sources for blocks (dynamic face count)
    $face_count = function_exists('gtemplate_get_face_count') ? gtemplate_get_face_count() : 6;
    $face_prefix = function_exists('gtemplate_get_face_prefix') ? gtemplate_get_face_prefix() : 'face';

    for ($i = 0; $i < $face_count; $i++) {
        $source = get_theme_mod("{$face_prefix}_{$i}_source", 'demo');
        if ($source === 'page' || $source === 'post') {
            $content_id = (int) get_theme_mod("{$face_prefix}_{$i}_content_id", 0);
            if ($content_id > 0) {
                $content_post = get_post($content_id);
                if ($content_post && has_blocks($content_post->post_content)) {
                    return true;
                }
            }
        }
    }

    return false;
}

/**
 * Enqueue self-hosted HTMX instead of CDN
 *
 * Benefits:
 * - Eliminates DNS lookup to unpkg.com (~50-100ms)
 * - Full cache control (1 year with immutable)
 * - No third-party dependency
 * - Works offline (with service worker)
 *
 * Uses parent theme URI (get_template_directory_uri()) since HTMX lives in parent.
 *
 * @param string $version HTMX version
 */
function gtemplate_enqueue_htmx() {
    $theme_uri = get_template_directory_uri();
    $theme_dir = get_template_directory();

    // Check if local HTMX exists
    $htmx_path = $theme_dir . '/assets/js/htmx.min.js';
    if (!file_exists($htmx_path)) {
        // Fallback to CDN if local file missing (should not happen in production)
        wp_enqueue_script(
            'htmx',
            'https://unpkg.com/htmx.org@1.9.10/dist/htmx.min.js',
            [],
            '1.9.10',
            false // Load in head for HTMX
        );
        return;
    }

    // Use local self-hosted version with defer strategy
    // HTMX scans the DOM after load, so defer is safe
    wp_enqueue_script(
        'htmx',
        $theme_uri . '/assets/js/htmx.min.js',
        [],
        '1.9.10',
        ['in_footer' => false, 'strategy' => 'defer']
    );
}
add_action('wp_enqueue_scripts', 'gtemplate_enqueue_htmx', 5);

// Note: HTMX defer is handled via WordPress 6.3+ script strategy in gtemplate_enqueue_htmx()

/**
 * Add resource hints for performance
 *
 * - preconnect: Establish early connection to critical origins
 * - dns-prefetch: Resolve DNS for secondary origins
 *
 * These hints allow the browser to start connections before resources are requested.
 */
function gtemplate_add_resource_hints() {
    // Preconnect to WordPress.org for updates/API (if used)
    // Only add if we actually make requests to these
    $hints = [];

    // Check if Gravatar is used (comments enabled)
    if (get_option('show_avatars')) {
        $hints[] = [
            'rel' => 'dns-prefetch',
            'href' => '//secure.gravatar.com'
        ];
    }

    // s.w.org is used by WordPress for emoji/smilies (but we disabled it)
    // Don't add hints for disabled features

    return $hints;
}

/**
 * Output resource hints in head
 */
function gtemplate_output_resource_hints() {
    $hints = gtemplate_add_resource_hints();

    foreach ($hints as $hint) {
        printf(
            '<link rel="%s" href="%s">' . "\n",
            esc_attr($hint['rel']),
            esc_url($hint['href'])
        );
    }
}
add_action('wp_head', 'gtemplate_output_resource_hints', 1);

/**
 * Remove unnecessary WordPress head elements
 *
 * Cleans up wp_head() output for leaner HTML.
 */
function gtemplate_cleanup_head() {
    // Remove RSD link (Really Simple Discovery - for XML-RPC clients)
    remove_action('wp_head', 'rsd_link');

    // Remove wlwmanifest link (Windows Live Writer - discontinued)
    remove_action('wp_head', 'wlwmanifest_link');

    // Remove WordPress generator tag (security through obscurity + clutter)
    remove_action('wp_head', 'wp_generator');

    // Remove shortlink
    remove_action('wp_head', 'wp_shortlink_wp_head', 10);

    // Remove REST API link from head (available via header anyway)
    remove_action('wp_head', 'rest_output_link_wp_head', 10);

    // Remove oEmbed discovery links
    remove_action('wp_head', 'wp_oembed_add_discovery_links', 10);

    // Remove adjacent post links (prev/next)
    remove_action('wp_head', 'adjacent_posts_rel_link_wp_head', 10);

    // Remove feed links (if not using RSS)
    // Uncomment if you don't need RSS feeds
    // remove_action('wp_head', 'feed_links', 2);
    // remove_action('wp_head', 'feed_links_extra', 3);
}
add_action('init', 'gtemplate_cleanup_head');

/**
 * Disable XML-RPC if not needed
 *
 * XML-RPC is a potential security vector and rarely needed.
 * Disable unless you use mobile apps or remote publishing.
 *
 * Set GTEMPLATE_ENABLE_XMLRPC to true in wp-config.php to keep it enabled.
 */
function gtemplate_disable_xmlrpc() {
    if (defined('GTEMPLATE_ENABLE_XMLRPC') && GTEMPLATE_ENABLE_XMLRPC) {
        return;
    }

    // Disable XML-RPC methods
    add_filter('xmlrpc_enabled', '__return_false');

    // Remove X-Pingback header
    add_filter('wp_headers', function($headers) {
        unset($headers['X-Pingback']);
        return $headers;
    });
}
add_action('init', 'gtemplate_disable_xmlrpc');

/**
 * Optimize jQuery loading (if WordPress loads it)
 *
 * Move jQuery to footer if no plugins require it in head.
 * Note: Many plugins expect jQuery in head, so this is disabled by default.
 *
 * Set GTEMPLATE_JQUERY_IN_FOOTER to true to enable.
 */
function gtemplate_optimize_jquery() {
    if (!defined('GTEMPLATE_JQUERY_IN_FOOTER') || !GTEMPLATE_JQUERY_IN_FOOTER) {
        return;
    }

    if (!is_admin()) {
        wp_scripts()->add_data('jquery', 'group', 1);
        wp_scripts()->add_data('jquery-core', 'group', 1);
        wp_scripts()->add_data('jquery-migrate', 'group', 1);
    }
}
add_action('wp_enqueue_scripts', 'gtemplate_optimize_jquery');

/**
 * Add async/defer to non-critical scripts
 *
 * @param string $tag Script tag
 * @param string $handle Script handle
 * @param string $src Script source
 * @return string Modified script tag
 */
function gtemplate_optimize_script_loading($tag, $handle, $src) {
    // Scripts that should be deferred (non-critical, can wait)
    $defer_scripts = [
        'gtemplate-cube-controls',  // Cube controls can wait for DOM
        'gtemplate-pwa-loader',
        'comment-reply',
    ];

    // Scripts that should be async (independent, order doesn't matter)
    $async_scripts = [];

    if (in_array($handle, $defer_scripts, true)) {
        return str_replace(' src=', ' defer src=', $tag);
    }

    if (in_array($handle, $async_scripts, true)) {
        return str_replace(' src=', ' async src=', $tag);
    }

    return $tag;
}
add_filter('script_loader_tag', 'gtemplate_optimize_script_loading', 10, 3);

/**
 * Remove type="text/javascript" and type="text/css" attributes
 *
 * These are unnecessary in HTML5 and add bytes to every page.
 */
function gtemplate_remove_type_attributes($tag, $handle) {
    return preg_replace("/type=['\"]text\/(javascript|css)['\"]/", '', $tag);
}
add_filter('style_loader_tag', 'gtemplate_remove_type_attributes', 10, 2);
add_filter('script_loader_tag', 'gtemplate_remove_type_attributes', 10, 2);

/**
 * Preload critical CSS
 *
 * Add preload hints for critical stylesheets.
 */
function gtemplate_preload_critical_assets() {
    $theme_uri = get_template_directory_uri();
    $version = wp_get_theme()->get('Version');

    // Preload main theme CSS (only if file exists — child themes provide their own)
    $css_path = get_template_directory() . '/assets/css/style.css';
    if (file_exists($css_path)) {
        echo '<link rel="preload" href="' . esc_url($theme_uri . '/assets/css/style.css?ver=' . $version) . '" as="style">' . "\n";
    }
}
add_action('wp_head', 'gtemplate_preload_critical_assets', 1);

/**
 * Disable WordPress auto-embeds (oEmbed)
 *
 * If you don't embed external content (YouTube, Twitter, etc.),
 * this saves ~5KB of JavaScript.
 *
 * Set GTEMPLATE_ENABLE_EMBEDS to true in wp-config.php to keep embeds.
 */
function gtemplate_disable_embeds() {
    if (defined('GTEMPLATE_ENABLE_EMBEDS') && GTEMPLATE_ENABLE_EMBEDS) {
        return;
    }

    // Remove oEmbed-related actions
    remove_action('wp_head', 'wp_oembed_add_discovery_links');
    remove_action('wp_head', 'wp_oembed_add_host_js');

    // Remove oEmbed-related filters
    remove_filter('oembed_dataparse', 'wp_filter_oembed_result', 10);

    // Disable oEmbed auto-discovery
    add_filter('embed_oembed_discover', '__return_false');

    // Remove oEmbed REST API endpoint
    remove_action('rest_api_init', 'wp_oembed_register_route');

    // Don't filter oEmbed results
    remove_filter('oembed_dataparse', 'wp_filter_oembed_result');

    // Remove oEmbed-specific JavaScript
    wp_deregister_script('wp-embed');
}
add_action('init', 'gtemplate_disable_embeds', 9999);

/**
 * Remove jQuery Migrate if not needed
 *
 * jQuery Migrate is for backwards compatibility with old jQuery code.
 * Modern themes/plugins don't need it.
 *
 * Set GTEMPLATE_KEEP_JQUERY_MIGRATE to true in wp-config.php to keep it.
 */
function gtemplate_remove_jquery_migrate($scripts) {
    if (defined('GTEMPLATE_KEEP_JQUERY_MIGRATE') && GTEMPLATE_KEEP_JQUERY_MIGRATE) {
        return;
    }

    // Remove on both frontend and customizer preview (but not full admin)
    // Customizer preview is not is_admin() but loads via admin
    $is_customizer_preview = isset($_GET['customize_changeset_uuid']);

    if ((!is_admin() || $is_customizer_preview) && isset($scripts->registered['jquery'])) {
        $script = $scripts->registered['jquery'];
        if ($script->deps) {
            $script->deps = array_diff($script->deps, ['jquery-migrate']);
        }
    }
}
add_action('wp_default_scripts', 'gtemplate_remove_jquery_migrate');

/**
 * Also dequeue jQuery Migrate script directly
 */
function gtemplate_dequeue_jquery_migrate() {
    if (defined('GTEMPLATE_KEEP_JQUERY_MIGRATE') && GTEMPLATE_KEEP_JQUERY_MIGRATE) {
        return;
    }

    // Don't remove in full admin (breaks things) but remove in customizer preview
    if (is_admin() && !isset($_GET['customize_changeset_uuid'])) {
        return;
    }

    wp_deregister_script('jquery-migrate');
}
add_action('wp_enqueue_scripts', 'gtemplate_dequeue_jquery_migrate', 1);
