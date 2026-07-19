<?php
declare(strict_types=1);
/**
 * Content Source Router
 *
 * Loads all built-in content sources and routes face content requests
 * to the appropriate handler. Child themes can register additional
 * content sources via the 'gtemplate_content_sources' filter.
 *
 * @package    gTemplate
 * @subpackage Rendering\ContentSources
 * @since      1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$content_sources_dir = __DIR__;
require_once $content_sources_dir . '/empty.php';
require_once $content_sources_dir . '/page.php';
require_once $content_sources_dir . '/post.php';
require_once $content_sources_dir . '/posts.php';
require_once $content_sources_dir . '/custom.php';
require_once $content_sources_dir . '/demo.php';

// Child theme content sources are loaded via filters, not includes here.
// e.g., gCube adds glass.php; other child themes add their own source files

/**
 * Get rendered content for a face/cell
 *
 * Routes to the appropriate content source based on customizer settings.
 * Child themes can register additional sources via 'gtemplate_content_sources' filter.
 *
 * @param int $face_id Face/cell index
 * @return string Rendered HTML content
 */
function gtemplate_get_face_content($face_id) {
    // Balanced tags are load-bearing: one unclosed div in any face's content
    // swallows every sibling face rendered after it (inactive faces are hidden).
    // Script bodies must be shielded first: markup inside a <script> is data,
    // not tags. The balancer counts opening tags in an embedded JSON payload
    // (whose closers are slash-escaped, so it never sees them) and appends a
    // pile of closers into the script, breaking JSON.parse and bare `<`
    // comparisons alike.
    $html = gtemplate_resolve_face_content($face_id);

    $scripts = [];
    $html = preg_replace_callback('#<script\b[^>]*>.*?</script>#is', function ($m) use (&$scripts) {
        $scripts[] = $m[0];
        return '<!--gtemplate-script-' . (count($scripts) - 1) . '-->';
    }, $html);

    $html = force_balance_tags($html);

    return preg_replace_callback('#<!--gtemplate-script-(\d+)-->#', function ($m) use ($scripts) {
        return $scripts[(int) $m[1]] ?? '';
    }, $html);
}

function gtemplate_resolve_face_content($face_id) {
    $face_count = gtemplate_get_face_count();
    $face_prefix = gtemplate_get_face_prefix();
    $face_id = max(0, min($face_count - 1, (int) $face_id));

    $source = get_theme_mod("{$face_prefix}_{$face_id}_source", 'demo');
    $content_id = (int) get_theme_mod("{$face_prefix}_{$face_id}_content_id", 0);
    $custom_html = get_theme_mod("{$face_prefix}_{$face_id}_custom_html", '');
    $label = get_theme_mod("{$face_prefix}_{$face_id}_label", '');
    $title_override = get_theme_mod("{$face_prefix}_{$face_id}_title", '');
    $show_title = (bool) get_theme_mod("{$face_prefix}_{$face_id}_show_title", true);
    $display_title = !empty($title_override) ? $title_override : $label;

    // Get registered content sources (child can add more via filter)
    $sources = apply_filters('gtemplate_content_sources', [
        'page'   => 'gtemplate_source_page',
        'post'   => 'gtemplate_source_post',
        'posts'  => 'gtemplate_source_posts',
        'custom' => 'gtemplate_source_custom',
        'demo'   => 'gtemplate_source_demo',
    ]);

    // Check if source has a registered handler
    if (isset($sources[$source]) && is_callable($sources[$source])) {
        return call_user_func($sources[$source], $face_id, $face_prefix, $display_title, $show_title);
    }

    // Default to demo
    return gtemplate_get_demo_content($face_id);
}

/**
 * Standard source handler: Page
 */
function gtemplate_source_page($face_id, $prefix, $title, $show_title) {
    $content_id = (int) get_theme_mod("{$prefix}_{$face_id}_content_id", 0);
    return gtemplate_get_page_content($content_id, $title, $show_title);
}

/**
 * Standard source handler: Single Post
 */
function gtemplate_source_post($face_id, $prefix, $title, $show_title) {
    $content_id = (int) get_theme_mod("{$prefix}_{$face_id}_content_id", 0);
    return gtemplate_get_post_content($content_id, $title, $show_title);
}

/**
 * Standard source handler: Posts List
 */
function gtemplate_source_posts($face_id, $prefix, $title, $show_title) {
    $category_filter = get_theme_mod("{$prefix}_{$face_id}_category_filter", '');
    $posts_per_page = (int) get_theme_mod("{$prefix}_{$face_id}_posts_per_page", 10);
    return gtemplate_get_posts_content($category_filter, $posts_per_page, $face_id, $title, $show_title);
}

/**
 * Standard source handler: Custom HTML
 */
function gtemplate_source_custom($face_id, $prefix, $title, $show_title) {
    $custom_html = get_theme_mod("{$prefix}_{$face_id}_custom_html", '');
    return gtemplate_get_custom_content($custom_html, $title, $show_title);
}

/**
 * Standard source handler: Demo Content
 */
function gtemplate_source_demo($face_id, $prefix, $title, $show_title) {
    return gtemplate_get_demo_content($face_id);
}
