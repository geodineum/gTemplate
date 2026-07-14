<?php
declare(strict_types=1);
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
 * Detect initial routing from WordPress query
 *
 * Determines which face/cell to show and whether a specific post is targeted.
 * Resolution order:
 *   1. Single post → posts cell
 *   2. WP page with explicit cell mapping → mapped cell
 *   3. URL slug matches a cell label slug → that cell (works for /contact, /news,
 *      etc. even when no WP page exists or no explicit mapping is configured)
 *   4. Category/archive/tag → posts cell
 *
 * @return array ['cell' => int, 'post_id' => int|null, 'post_slug' => string|null]
 */
function gtemplate_detect_initial_routing(): array {
    $result = ['cell' => 0, 'post_id' => null, 'post_slug' => null];

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
                return $result;
            }
            // Page exists but isn't mapped — try slug match against cell labels
            $cell = gtemplate_find_face_by_slug($post->post_name);
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

    // Fallback: match URL path slug against cell label slugs.
    // Handles /contact, /about etc. even when no WP page exists for that path.
    $request_slug = gtemplate_get_request_slug();
    if ($request_slug !== '') {
        $cell = gtemplate_find_face_by_slug($request_slug);
        if ($cell !== null) {
            $result['cell'] = $cell;
        }
    }

    return $result;
}

/**
 * Get the first path segment of the current request, sanitized as a slug.
 *
 * @return string Slug or empty string for the home page
 */
function gtemplate_get_request_slug(): string {
    $path = $_SERVER['REQUEST_URI'] ?? '';
    $path = parse_url($path, PHP_URL_PATH) ?: '';
    $path = trim($path, '/');
    if ($path === '') return '';
    $first = explode('/', $path)[0];
    return sanitize_title($first);
}

/**
 * Find a cell whose label slug matches the given slug.
 *
 * @param string $slug URL-style slug (e.g. 'contact')
 * @return int|null Cell index or null if no match
 */
function gtemplate_find_face_by_slug(string $slug): ?int {
    if ($slug === '') return null;
    $face_prefix = gtemplate_get_face_prefix();
    $face_count = gtemplate_get_face_count();

    for ($i = 0; $i < $face_count; $i++) {
        $label = (string) get_theme_mod("{$face_prefix}_{$i}_label", '');
        if ($label === '') continue;
        if (sanitize_title($label) === $slug) {
            return $i;
        }
    }
    return null;
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
