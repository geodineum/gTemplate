<?php
/**
 * Rendering Helper Functions
 *
 * Core rendering utilities shared by all Geodineum child themes.
 * Uses gtemplate_ prefix with filter-based parameterization for
 * face count, face prefix, and REST namespace.
 *
 * @package    gTemplate
 * @subpackage Rendering
 * @since      1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get key-based gNode client from globals
 *
 * @return mixed|null gNode key-based client or null
 */
function gtemplate_gnode_keybased() {
    return $GLOBALS['gtemplate_gnode_keybased_client'] ?? null;
}

/**
 * Get stream-based gNode client from globals
 *
 * @return mixed|null gNode stream client or null
 */
function gtemplate_gnode() {
    return $GLOBALS['gtemplate_gnode_client'] ?? null;
}

/**
 * Check if running in free tier mode (no gNode/ValKey)
 *
 * @return bool True if free tier
 */
function gtemplate_is_free_tier(): bool {
    if (defined('GTEMPLATE_FREE_TIER') && GTEMPLATE_FREE_TIER) {
        return true;
    }
    if (!empty($GLOBALS['gtemplate_free_tier_mode'])) {
        return true;
    }
    return gtemplate_gnode() === null && gtemplate_gnode_keybased() === null;
}

/**
 * Get rendering mode based on tier
 *
 * @return string 'free_tier' or 'premium'
 */
function gtemplate_get_mode(): string {
    return gtemplate_is_free_tier() ? 'free_tier' : 'premium';
}

/**
 * Detect initial routing from WordPress query
 *
 * Determines which face/cell to show and whether a specific post is targeted.
 *
 * @return array ['cell' => int, 'post_id' => int|null, 'post_slug' => string|null]
 */
function gtemplate_detect_initial_routing(): array {
    $result = ['cell' => 0, 'post_id' => null, 'post_slug' => null];
    $face_count = gtemplate_get_face_count();
    $face_prefix = gtemplate_get_face_prefix();

    if (is_single()) {
        global $post;
        if ($post && $post->post_type === 'post') {
            $result['cell'] = gtemplate_find_posts_face();
            $result['post_id'] = $post->ID;
            $result['post_slug'] = $post->post_name;
        }
        return $result;
    }

    if (is_page()) {
        global $post;
        if ($post) {
            $cell = gtemplate_find_face_for_page($post->ID);
            if ($cell !== null) {
                $result['cell'] = $cell;
            }
        }
        return $result;
    }

    if (is_category() || is_archive() || is_tag()) {
        $result['cell'] = gtemplate_find_posts_face();
        return $result;
    }

    return $result;
}

/**
 * Find the face/cell configured for posts/blog content
 *
 * @return int Face index
 */
function gtemplate_find_posts_face(): int {
    $face_prefix = gtemplate_get_face_prefix();
    $face_count = gtemplate_get_face_count();

    for ($i = 0; $i < $face_count; $i++) {
        $source = get_theme_mod("{$face_prefix}_{$i}_source", '');
        if ($source === 'posts' || $source === 'blog') {
            return $i;
        }
    }

    return max(0, $face_count - 2); // sensible default
}

/**
 * Find the face/cell assigned to a specific page
 *
 * @param int $page_id WordPress page ID
 * @return int|null Face index or null if not found
 */
function gtemplate_find_face_for_page(int $page_id): ?int {
    $face_prefix = gtemplate_get_face_prefix();
    $face_count = gtemplate_get_face_count();

    for ($i = 0; $i < $face_count; $i++) {
        $source = get_theme_mod("{$face_prefix}_{$i}_source", '');
        $content_id = (int) get_theme_mod("{$face_prefix}_{$i}_content_id", 0);
        if ($source === 'page' && $content_id === $page_id) {
            return $i;
        }
    }

    return null;
}

/**
 * Get complete face mapping for navigation and JavaScript config
 *
 * @return array Indexed array of face configurations
 */
function gtemplate_get_face_mapping(): array {
    $mapping = [];
    $face_prefix = gtemplate_get_face_prefix();
    $face_count = gtemplate_get_face_count();
    $defaults = apply_filters('gtemplate_default_labels', ['Home', 'About', 'Services', 'Portfolio', 'Blog', 'Contact']);

    for ($i = 0; $i < $face_count; $i++) {
        $label = get_theme_mod("{$face_prefix}_{$i}_label", $defaults[$i] ?? 'Face ' . ($i + 1));
        $source = get_theme_mod("{$face_prefix}_{$i}_source", 'demo');
        $content_id = (int) get_theme_mod("{$face_prefix}_{$i}_content_id", 0);

        $mapping[$i] = [
            'label' => $label,
            'slug' => sanitize_title($label),
            'source' => $source,
            'contentId' => $content_id,
            'pageSlug' => null,
        ];

        if ($source === 'page' && $content_id > 0) {
            $page = get_post($content_id);
            if ($page) {
                $mapping[$i]['pageSlug'] = $page->post_name;
            }
        }
    }

    return $mapping;
}
