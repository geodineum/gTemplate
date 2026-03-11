<?php
/**
 * REST API Resource: Posts
 *
 * Endpoints for single post content and per-post bundles.
 *
 * Routes:
 *   GET /post/{post_id}        - Single post HTML for expanded view
 *   GET /bundle/post/{post_id} - Pre-rendered post bundle from ValKey
 *
 * @package gTemplate
 * @subpackage REST
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register post-related REST routes
 *
 * @param string $namespace REST API namespace
 */
function gtemplate_register_post_routes(string $namespace): void {
    // Single post endpoint - returns HTML fragment for expanded view
    register_rest_route($namespace, '/post/(?P<post_id>\d+)', [
        'methods' => 'GET',
        'callback' => 'gtemplate_rest_get_single_post',
        'permission_callback' => '__return_true',
        'args' => [
            'post_id' => [
                'required' => true,
                'type' => 'integer',
                'minimum' => 1
            ]
        ]
    ]);

    // Per-post bundle retrieval endpoint
    register_rest_route($namespace, '/bundle/post/(?P<post_id>\d+)', [
        'methods' => 'GET',
        'callback' => 'gtemplate_rest_get_post_bundle',
        'permission_callback' => '__return_true',
        'args' => [
            'post_id' => [
                'required' => true,
                'type' => 'integer',
                'minimum' => 1
            ]
        ]
    ]);
}

/**
 * REST endpoint: Get single post HTML for expanded view
 *
 * Returns an HTML fragment suitable for the expanded view.
 * Used when clicking "Read More" on blog post cards.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function gtemplate_rest_get_single_post($request) {
    $post_id = (int) $request['post_id'];

    // Check if post exists
    $post = get_post($post_id);

    if (!$post || $post->post_type !== 'post') {
        return new WP_Error(
            'post_not_found',
            'Post not found',
            ['status' => 404]
        );
    }

    if ($post->post_status !== 'publish') {
        if (!current_user_can('edit_post', $post_id)) {
            return new WP_Error(
                'post_not_published',
                'Post is not published',
                ['status' => 403]
            );
        }
    }

    // Build HTML for expanded post view
    $html = gtemplate_render_single_post_html($post);

    return new WP_REST_Response($html, 200, [
        'Content-Type' => 'text/html; charset=utf-8',
        'X-Post-ID' => $post_id,
        'X-Post-Slug' => $post->post_name,
        'Cache-Control' => 'public, max-age=300'
    ]);
}

/**
 * Render single post HTML for expanded view
 *
 * Respects WP Customizer settings from "Post Overlay Styling" section:
 * - post_overlay_show_hero: Show/hide featured image
 * - post_overlay_show_author: Show/hide author name
 * - post_overlay_show_date: Show/hide publication date
 * - post_overlay_show_categories: Show/hide categories
 *
 * @param WP_Post $post
 * @return string HTML content
 */
function gtemplate_render_single_post_html($post) {
    $featured_image = get_the_post_thumbnail_url($post, 'large');
    $author_name = get_the_author_meta('display_name', $post->post_author);
    $date = get_the_date('F j, Y', $post);
    $categories = get_the_category($post->ID);
    $category_names = array_map(function($cat) { return $cat->name; }, $categories);

    // Get visibility settings from WP Customizer
    $show_hero = get_theme_mod('post_overlay_show_hero', true);
    $show_author = get_theme_mod('post_overlay_show_author', true);
    $show_date = get_theme_mod('post_overlay_show_date', true);
    $show_categories = get_theme_mod('post_overlay_show_categories', true);

    // Apply content filters (shortcodes, embeds, etc.)
    $content = apply_filters('the_content', $post->post_content);

    $html = '<article class="single-post-expanded" data-post-id="' . esc_attr($post->ID) . '" data-post-slug="' . esc_attr($post->post_name) . '">';

    // Header with featured image (conditional on customizer setting)
    if ($featured_image) {
        $hero_hidden = $show_hero ? 'false' : 'true';
        $html .= '<div class="post-hero-image" data-hidden="' . $hero_hidden . '">';
        $html .= '<img src="' . esc_url($featured_image) . '" alt="' . esc_attr($post->post_title) . '" loading="lazy">';
        $html .= '</div>';
    }

    // Post header
    $html .= '<header class="post-header">';
    $html .= '<h1 class="post-title">' . esc_html($post->post_title) . '</h1>';
    $html .= '<div class="post-meta">';

    // Author (conditional)
    $author_hidden = $show_author ? 'false' : 'true';
    $html .= '<span class="post-author" data-hidden="' . $author_hidden . '">By ' . esc_html($author_name) . '</span>';

    // Date (conditional)
    $date_hidden = $show_date ? 'false' : 'true';
    $html .= '<span class="post-date" data-hidden="' . $date_hidden . '">' . esc_html($date) . '</span>';

    // Categories (conditional)
    if (!empty($category_names)) {
        $categories_hidden = $show_categories ? 'false' : 'true';
        $html .= '<span class="post-categories" data-hidden="' . $categories_hidden . '">' . esc_html(implode(', ', $category_names)) . '</span>';
    }

    $html .= '</div>';
    $html .= '</header>';

    // Post content
    $html .= '<div class="post-content">';
    $html .= $content;
    $html .= '</div>';

    // Post footer with navigation
    $html .= '<footer class="post-footer">';
    $html .= '<button class="post-back-btn" data-action="close-post">&larr; Back to News</button>';
    $html .= '</footer>';

    $html .= '</article>';

    return $html;
}

/**
 * REST endpoint: Get per-post bundle HTML
 *
 * Returns pre-rendered HTML for a bundled post from ValKey.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function gtemplate_rest_get_post_bundle($request) {
    $post_id = (int) $request['post_id'];
    $face_prefix = gtemplate_get_face_prefix();

    // Check post exists and is published (or user can edit)
    $post = get_post($post_id);
    if (!$post) {
        return new WP_Error('not_found', 'Post not found', ['status' => 404]);
    }
    if ($post->post_status !== 'publish' && !current_user_can('edit_post', $post_id)) {
        return new WP_Error('forbidden', 'Post not accessible', ['status' => 403]);
    }

    // Check if post has bundling enabled
    $bundled = get_post_meta($post_id, '_gtemplate_bundled', true);
    if (!$bundled) {
        return new WP_Error('not_bundled', 'This post is not bundled', ['status' => 404]);
    }

    // Try ValKey first
    $keybased = gtemplate_gnode_keybased();
    if ($keybased) {
        try {
            $storage = $keybased->getStorage();
            if ($storage) {
                $site_id = gtemplate_get_site_id();
                $key = "{{$site_id}}:bundle:post_{$post_id}";
                $data = $storage->get($key);
                if ($data) {
                    $bundle = json_decode($data, true);
                    $html = $bundle['html'] ?? $data;
                    return new WP_REST_Response($html, 200, [
                        'Content-Type' => 'text/html; charset=utf-8',
                        'X-Bundle-Key' => $key,
                        'X-Bundle-Generated' => $bundle['generated_at'] ?? 'unknown',
                        'Cache-Control' => 'public, max-age=300',
                    ]);
                }
            }
        } catch (\Throwable $e) {
            error_log("[gTemplate Bundle] ValKey retrieval failed for post {$post_id}: " . $e->getMessage());
        }
    }

    // Try transient fallback
    $theme_prefix = gtemplate_get_theme_prefix();
    $html = get_transient("{$theme_prefix}_bundle_{$post_id}");
    if ($html) {
        return new WP_REST_Response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Bundle-Source' => 'transient',
            'Cache-Control' => 'public, max-age=60',
        ]);
    }

    return new WP_Error('bundle_not_found', 'Bundle not generated yet. Use the meta box to generate.', ['status' => 404]);
}
