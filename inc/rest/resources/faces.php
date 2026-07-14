<?php
declare(strict_types=1);
/**
 * REST API Resource: Faces
 *
 * Endpoints for face content and configuration.
 *
 * Routes:
 *   GET /face-configs          - All face configurations (for gNode bundle builder)
 *   GET /face-config/{face_id} - Single face configuration
 *   GET /face/{face_id}        - Face content for HTMX lazy loading
 *
 * Also provides:
 *   gtemplate_sync_face_configs_to_valkey() - Sync configs on customizer save
 *
 * @package gTemplate
 * @subpackage REST
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register face-related REST routes
 *
 * @param string $namespace REST API namespace
 */
function gtemplate_register_face_routes(string $namespace): void {
    $max_face = gtemplate_get_face_count() - 1;

    // Face configurations endpoint - for gNode bundle builder.
    //
    // Commit 1.11.b: pre-fix `permission_callback => '__return_true'`
    // exposed the site-wide content plan (which page is on which face,
    // template metadata, etc.) to anonymous internet GETs. The comment
    // claimed "Public for gNode daemon" but nothing proved the caller
    // WAS the daemon. Post-fix: shared-secret header gate via
    // `gtemplate_face_configs_authorize` — daemon presents
    // `X-Gnode-Shared-Secret: <secret>` matching the WP option
    // `gtemplate_face_configs_secret`; admins (logged-in
    // manage_options) also pass for debugging. Anonymous internet =
    // 403.
    register_rest_route($namespace, '/face-configs', [
        'methods' => 'GET',
        'callback' => 'gtemplate_rest_get_face_configs',
        'permission_callback' => 'gtemplate_face_configs_authorize',
    ]);

    register_rest_route($namespace, '/face-config/(?P<face_id>\d+)', [
        'methods' => 'GET',
        'callback' => 'gtemplate_rest_get_single_face_config',
        'permission_callback' => 'gtemplate_face_configs_authorize',
        'args' => [
            'face_id' => [
                'required' => true,
                'type' => 'integer',
                'minimum' => 0,
                'maximum' => $max_face
            ]
        ]
    ]);

    // Face endpoint for HTMX lazy loading
    register_rest_route($namespace, '/face/(?P<face_id>\d+)', [
        'methods' => 'GET',
        'callback' => 'gtemplate_rest_get_face',
        'permission_callback' => '__return_true', // Public endpoint
        'args' => [
            'face_id' => [
                'required' => true,
                'type' => 'integer',
                'minimum' => 0,
                'maximum' => $max_face
            ]
        ]
    ]);
}

/**
 * Commit 1.11.b: authorize face-configs reads.
 *
 * Pass-criteria (any one):
 *   1. Logged-in user with manage_options (operator debugging).
 *   2. `X-Gnode-Shared-Secret` header matches the WP option
 *      `gtemplate_face_configs_secret`. The secret is auto-generated
 *      on first use (mirrors the page-cache HMAC pattern from 1.11.a).
 *      Operators provision the daemon's copy via
 *      `wp option get gtemplate_face_configs_secret`.
 *
 * Else 403.
 */
function gtemplate_face_configs_authorize(\WP_REST_Request $request)
{
    if (\function_exists('current_user_can') && \current_user_can('manage_options')) {
        return true;
    }

    $secret = (string) get_option('gtemplate_face_configs_secret', '');
    if ($secret === '' || \strlen($secret) < 32) {
        $secret = wp_generate_password(64, true, true);
        update_option('gtemplate_face_configs_secret', $secret, false);
    }

    $provided = (string) $request->get_header('x_gnode_shared_secret');
    if ($provided !== '' && \hash_equals($secret, $provided)) {
        return true;
    }

    return new \WP_Error(
        'gtemplate_face_configs_forbidden',
        'face-configs requires either an authenticated admin session or the X-Gnode-Shared-Secret header.',
        ['status' => 403]
    );
}

/**
 * REST endpoint: Get all face configurations for gNode bundle builder
 *
 * Returns customizer settings for all faces, including:
 * - Content source (demo, page, post, custom)
 * - Content ID (page/post ID)
 * - Custom HTML
 * - Navigation labels
 * - Pre-rendered content HTML
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function gtemplate_rest_get_face_configs($request) {
    $configs = gtemplate_get_all_face_configs();
    $positions = apply_filters('gtemplate_face_positions', []);
    $css_classes = apply_filters('gtemplate_face_css_classes', []);

    // Enhance configs with actual content for bundle building
    $enhanced_configs = [];

    foreach ($configs as $face_id => $config) {
        // Get the rendered content for this face
        $content_html = gtemplate_get_face_content($face_id);

        // Get actual page/post data if applicable
        $content_data = null;
        if ($config['source'] === 'page' && $config['content_id'] > 0) {
            $page = get_post($config['content_id']);
            if ($page && $page->post_status === 'publish') {
                $content_data = [
                    'id' => $page->ID,
                    'title' => $page->post_title,
                    'slug' => $page->post_name,
                    'content' => apply_filters('the_content', $page->post_content),
                    'excerpt' => $page->post_excerpt,
                    'featured_image' => get_the_post_thumbnail_url($page, 'large'),
                    'modified' => $page->post_modified,
                ];
            }
        } elseif ($config['source'] === 'post' && $config['content_id'] > 0) {
            $post = get_post($config['content_id']);
            if ($post && $post->post_status === 'publish') {
                $content_data = [
                    'id' => $post->ID,
                    'title' => $post->post_title,
                    'slug' => $post->post_name,
                    'content' => apply_filters('the_content', $post->post_content),
                    'excerpt' => $post->post_excerpt,
                    'featured_image' => get_the_post_thumbnail_url($post, 'large'),
                    'author' => get_the_author_meta('display_name', $post->post_author),
                    'date' => get_the_date('c', $post),
                    'modified' => $post->post_modified,
                ];
            }
        }

        $enhanced_configs[$face_id] = array_merge($config, [
            'content_html' => $content_html,
            'content_data' => $content_data,
            'position' => $positions[$face_id] ?? 'unknown',
            'css_class' => $css_classes[$face_id] ?? '',
        ]);
    }

    return new WP_REST_Response([
        'site_id' => gtemplate_get_site_id(),
        'site_url' => home_url(),
        'site_name' => get_bloginfo('name'),
        'theme_url' => get_template_directory_uri(),
        'generated_at' => current_time('c'),
        'faces' => $enhanced_configs,
    ], 200, [
        'Cache-Control' => 'no-cache', // Always fresh for bundle building
    ]);
}

/**
 * REST endpoint: Get single face configuration
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function gtemplate_rest_get_single_face_config($request) {
    $face_id = (int) $request['face_id'];
    $max_face = gtemplate_get_face_count() - 1;

    if ($face_id < 0 || $face_id > $max_face) {
        return new WP_Error('invalid_face_id', sprintf('Face ID must be between 0 and %d', $max_face), ['status' => 400]);
    }

    $all_configs = gtemplate_get_all_face_configs();
    $config = $all_configs[$face_id] ?? null;

    if (!$config) {
        return new WP_Error('config_not_found', 'Face configuration not found', ['status' => 404]);
    }

    $positions = apply_filters('gtemplate_face_positions', []);
    $css_classes = apply_filters('gtemplate_face_css_classes', []);

    // Get rendered content
    $content_html = gtemplate_get_face_content($face_id);

    // Get content data if applicable
    $content_data = null;
    if ($config['source'] === 'page' && $config['content_id'] > 0) {
        $page = get_post($config['content_id']);
        if ($page && $page->post_status === 'publish') {
            $content_data = [
                'id' => $page->ID,
                'title' => $page->post_title,
                'slug' => $page->post_name,
                'content' => apply_filters('the_content', $page->post_content),
                'excerpt' => $page->post_excerpt,
                'featured_image' => get_the_post_thumbnail_url($page, 'large'),
                'modified' => $page->post_modified,
            ];
        }
    } elseif ($config['source'] === 'post' && $config['content_id'] > 0) {
        $post = get_post($config['content_id']);
        if ($post && $post->post_status === 'publish') {
            $content_data = [
                'id' => $post->ID,
                'title' => $post->post_title,
                'slug' => $post->post_name,
                'content' => apply_filters('the_content', $post->post_content),
                'excerpt' => $post->post_excerpt,
                'featured_image' => get_the_post_thumbnail_url($post, 'large'),
                'author' => get_the_author_meta('display_name', $post->post_author),
                'date' => get_the_date('c', $post),
                'modified' => $post->post_modified,
            ];
        }
    }

    return new WP_REST_Response(array_merge($config, [
        'face_id' => $face_id,
        'content_html' => $content_html,
        'content_data' => $content_data,
        'position' => $positions[$face_id] ?? 'unknown',
        'css_class' => $css_classes[$face_id] ?? '',
    ]), 200);
}

/**
 * REST endpoint: Load face content via HTMX
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function gtemplate_rest_get_face($request) {
    $face_id = (int) $request['face_id'];
    $max_face = gtemplate_get_face_count() - 1;
    $positions = apply_filters('gtemplate_face_positions', []);
    $namespace = gtemplate_get_rest_namespace();
    $theme_prefix = gtemplate_get_theme_prefix();

    // Validate face ID
    if ($face_id < 0 || $face_id > $max_face) {
        return new WP_Error(
            'invalid_face_id',
            sprintf('Face ID must be between 0 and %d', $max_face),
            ['status' => 400]
        );
    }

    try {
        // Render face via gtemplate_render_face() helper
        $html = gtemplate_render_face($face_id, [
            'position' => $positions[$face_id] ?? 'unknown'
        ]);

        if ($html && !empty($html)) {
            // Return raw HTML (bypass WordPress REST JSON encoding)
            add_filter('rest_pre_serve_request', function($served, $result, $request) use ($html, $face_id, $namespace, $theme_prefix) {
                if ($request->get_route() === '/' . $namespace . '/face/' . $face_id) {
                    header('Content-Type: text/html; charset=utf-8');
                    header('X-Face-ID: ' . $face_id);
                    header('X-Rendered-By: ' . $theme_prefix);
                    header('Cache-Control: public, max-age=300');
                    echo $html;
                    return true; // Prevent default REST response
                }
                return $served;
            }, 10, 3);

            // Return response object (but filter above will output raw HTML)
            return new WP_REST_Response($html, 200, [
                'Content-Type' => 'text/html; charset=utf-8',
                'X-Face-ID' => $face_id,
                'X-Rendered-By' => $theme_prefix,
                'Cache-Control' => 'public, max-age=300'
            ]);
        } else {
            throw new \RuntimeException('Face rendering returned empty result');
        }

    } catch (\Throwable $e) {
        gtemplate_track_error("gTemplate: Face {$face_id} render failed: " . $e->getMessage());

        // Return error message in HTML format for HTMX
        $error_html = sprintf(
            '<div class="face-error" data-face-id="%d"><p>Content unavailable</p><small>%s</small></div>',
            $face_id,
            esc_html($e->getMessage())
        );

        return new WP_REST_Response($error_html, 500, [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Error' => 'render-failed'
        ]);
    }
}

/**
 * Sync face configurations to ValKey for gNode daemon access
 *
 * Removed in Tier-2 commit 2.4 (CB-D1.13 mirror; gCube counterpart
 * landed in commit 2.0). The function previously wrote
 * `{site_id}:config:face_configs` — an orphan ValKey key with zero
 * readers across all 9 component repos. gNode daemon reads only
 * `{site_id}:gnode:face_mapping` (asset_builder.rs:526). The
 * keybased-client invalidation side effect now fires from the
 * surviving canonical `gtemplate_sync_face_mapping_to_valkey`
 * hook in inc/sync/bundle-cache.php.
 */
