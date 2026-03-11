<?php
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

    // Face configurations endpoint - for gNode bundle builder
    register_rest_route($namespace, '/face-configs', [
        'methods' => 'GET',
        'callback' => 'gtemplate_rest_get_face_configs',
        'permission_callback' => '__return_true', // Public for gNode daemon
    ]);

    // Single face configuration endpoint
    register_rest_route($namespace, '/face-config/(?P<face_id>\d+)', [
        'methods' => 'GET',
        'callback' => 'gtemplate_rest_get_single_face_config',
        'permission_callback' => '__return_true',
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
        error_log("gTemplate: Face {$face_id} render failed: " . $e->getMessage());

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
 * Called on customizer save to update face configs in ValKey.
 * gNode daemon reads these to build bundles without HTTP requests.
 *
 * @return bool Success
 */
function gtemplate_sync_face_configs_to_valkey(): bool {
    try {
        $site_id = gtemplate_get_site_id();
        $configs = gtemplate_get_all_face_configs();
        $positions = apply_filters('gtemplate_face_positions', []);
        $css_classes = apply_filters('gtemplate_face_css_classes', []);

        // Get ValKey connection
        $gNode = gtemplate_gnode_keybased();
        if (!$gNode) {
            error_log('[gTemplate] Cannot sync face configs: gNode client not available');
            return false;
        }

        // Enhance configs with rendered content
        $enhanced_configs = [];
        foreach ($configs as $face_id => $config) {
            $enhanced_configs[$face_id] = array_merge($config, [
                'content_html' => gtemplate_get_face_content($face_id),
                'position' => $positions[$face_id] ?? 'unknown',
                'css_class' => $css_classes[$face_id] ?? '',
            ]);
        }

        // Store in ValKey via gNode client's storage
        $storage = $gNode->getStorage();
        if ($storage) {
            $key = "{{$site_id}}:config:face_configs";
            $data = json_encode([
                'site_id' => $site_id,
                'site_url' => home_url(),
                'site_name' => get_bloginfo('name'),
                'generated_at' => current_time('c'),
                'faces' => $enhanced_configs,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $storage->set($key, $data);
            error_log("[gTemplate] Synced face configs to ValKey: {$key}");

            // Invalidate bundle to trigger rebuild with new configs
            gtemplate_invalidate_bundle();

            return true;
        }

        return false;

    } catch (\Throwable $e) {
        error_log('[gTemplate] Failed to sync face configs: ' . $e->getMessage());
        return false;
    }
}

// Hook: Sync face configs when customizer is saved
add_action('customize_save_after', 'gtemplate_sync_face_configs_to_valkey');

// Hook: Sync face configs when theme mods are updated
add_action('update_option_theme_mods_' . get_option('stylesheet'), function() {
    // Debounce multiple saves
    static $synced = false;
    if (!$synced) {
        $synced = true;
        gtemplate_sync_face_configs_to_valkey();
    }
});
