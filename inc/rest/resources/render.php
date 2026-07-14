<?php
declare(strict_types=1);
/**
 * REST API Resource: Render
 *
 * Endpoint for dynamic template rendering via gNode.
 *
 * Routes:
 *   POST /render - Render template via gNode for PWA dynamic loading
 *
 * @package gTemplate
 * @subpackage REST
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register render-related REST routes
 *
 * @param string $namespace REST API namespace
 */
function gtemplate_register_render_routes(string $namespace): void {
    $max_face = gtemplate_get_face_count() - 1;

    // Batch render ALL faces in one request (used by preload-all strategy)
    register_rest_route($namespace, '/render-all', [
        'methods' => 'GET',
        'callback' => 'gtemplate_rest_render_all_faces',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route($namespace, '/render', [
        'methods' => 'POST',
        'callback' => 'gtemplate_rest_render_template',
        'permission_callback' => '__return_true', // Public endpoint
        'args' => [
            'template' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Template ID to render'
            ],
            'face_id' => [
                'required' => true,
                'type' => 'integer',
                'minimum' => 0,
                'maximum' => $max_face,
                'description' => 'Face ID'
            ],
            'data' => [
                'required' => false,
                'type' => 'object',
                'default' => [],
                'description' => 'Additional template data'
            ]
        ]
    ]);
}

/**
 * REST endpoint: Render template via gNode (for PWA dynamic loading)
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function gtemplate_rest_render_template($request) {
    $template_id = $request->get_param('template');
    $face_id = $request->get_param('face_id');
    $data = $request->get_param('data') ?? [];
    $positions = apply_filters('gtemplate_face_positions', []);

    try {
        // Get key-based gNode client (optimized for template rendering)
        $gNode = gtemplate_gnode_keybased();

        if (!$gNode) {
            // Fallback to face rendering if gNode unavailable
            $html = gtemplate_render_face($face_id, array_merge([
                'position' => $positions[$face_id] ?? 'unknown'
            ], $data));

            // Inject template-specific JS for fallback path too
            $html = gtemplate_inject_template_js($html, $template_id);

            return new WP_REST_Response($html, 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'X-Rendered-By' => 'PHP-Fallback',
                'Cache-Control' => 'public, max-age=300'
            ]);
        }

        // Prepare template data
        $template_data = array_merge([
            'site_id' => gtemplate_get_site_id(),
            'face_id' => $face_id,
            'theme_url' => get_template_directory_uri(),
            'home_url' => home_url(),
            'blog_name' => get_bloginfo('name'),
            'blog_description' => get_bloginfo('description'),
            'content' => gtemplate_get_face_content($face_id)
        ], $data);

        // Render via gNode
        $html = $gNode->renderTemplate($template_id, $template_data);

        if ($html && !empty($html)) {
            // Inject template-specific JS if available
            $html = gtemplate_inject_template_js($html, $template_id);

            return new WP_REST_Response($html, 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'X-Rendered-By' => 'gNode-Tera',
                'X-Template-ID' => $template_id,
                'Cache-Control' => 'public, max-age=300'
            ]);
        }

        // gNode returned empty - fallback
        $html = gtemplate_render_face($face_id, array_merge([
            'position' => $positions[$face_id] ?? 'unknown'
        ], $data));

        // Inject template-specific JS for fallback path too
        $html = gtemplate_inject_template_js($html, $template_id);

        return new WP_REST_Response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Rendered-By' => 'PHP-Fallback',
            'X-Reason' => 'gNode-Empty-Result',
            'Cache-Control' => 'public, max-age=300'
        ]);

    } catch (\Throwable $e) {
        gtemplate_track_error('[gTemplate REST] Template render error: ' . $e->getMessage());

        // Return error as HTML for PWA error handling
        $error_html = sprintf(
            '<div class="face-error" data-face-id="%d"><p><strong>Render Error</strong></p><small>%s</small></div>',
            $face_id,
            esc_html($e->getMessage())
        );

        return new WP_REST_Response($error_html, 500, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Error' => 'render-failed'
        ]);
    }
}

/**
 * REST endpoint: Batch-render ALL faces in a single request.
 *
 * Returns JSON with all face HTML pre-rendered. The client calls this
 * once on initial page load via requestIdleCallback — by the time the
 * user clicks any face button, all content is already in the DOM.
 *
 * ValKey optimization: if all faces have cached bundles, this is a
 * single GNODE_BATCH_MGET round-trip (~0.5ms) instead of 6 separate
 * GNODE_CACHE_GET calls.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function gtemplate_rest_render_all_faces($request) {
    $face_count = gtemplate_get_face_count();
    $positions = apply_filters('gtemplate_face_positions', []);
    $gNode = gtemplate_gnode_keybased();

    $faces = [];
    $rendered_by = 'mixed';

    // Try batch fetch from ValKey cache first (single round-trip)
    $site_id = gtemplate_get_site_id();
    $cache_keys = [];
    for ($i = 0; $i < $face_count; $i++) {
        $cache_keys[] = "{$site_id}:face:bundle:{$i}";
    }

    $cached_faces = [];
    if ($gNode && method_exists($gNode, 'batchGet')) {
        try {
            $cached_faces = $gNode->batchGet($cache_keys);
            $rendered_by = 'valkey-batch';
        } catch (\Throwable $e) {
            // Fall through to per-face rendering
        }
    }

    for ($i = 0; $i < $face_count; $i++) {
        // Use cached bundle if available
        if (!empty($cached_faces[$i])) {
            $faces[$i] = [
                'face_id' => $i,
                'html' => $cached_faces[$i],
                'cached' => true,
            ];
            continue;
        }

        // Render individually (fallback)
        try {
            $template_id = "face_{$i}";
            $template_data = [
                'site_id' => $site_id,
                'face_id' => $i,
                'theme_url' => get_template_directory_uri(),
                'home_url' => home_url(),
                'blog_name' => get_bloginfo('name'),
                'blog_description' => get_bloginfo('description'),
                'content' => gtemplate_get_face_content($i),
            ];

            $html = null;
            if ($gNode) {
                $html = $gNode->renderTemplate($template_id, $template_data);
            }

            if (empty($html)) {
                $html = gtemplate_render_face($i, array_merge([
                    'position' => $positions[$i] ?? 'unknown'
                ], $template_data));
                $rendered_by = 'php-fallback';
            }

            $html = gtemplate_inject_template_js($html, $template_id);

            $faces[$i] = [
                'face_id' => $i,
                'html' => $html,
                'cached' => false,
            ];
        } catch (\Throwable $e) {
            $faces[$i] = [
                'face_id' => $i,
                'html' => '<div class="face-error"><p>Content unavailable</p></div>',
                'error' => $e->getMessage(),
                'cached' => false,
            ];
        }
    }

    return new WP_REST_Response([
        'faces' => $faces,
        'count' => count($faces),
        'rendered_by' => $rendered_by,
    ], 200, [
        'Content-Type' => 'application/json',
        'X-Rendered-By' => $rendered_by,
        'Cache-Control' => 'public, max-age=300',
    ]);
}

/**
 * Inject template-specific JavaScript into rendered HTML
 *
 * Appends inline <script> tag with template JS for client-side execution.
 * Used when serving templates via REST API where wp_enqueue_script doesn't run.
 *
 * @param string $html Rendered HTML content
 * @param string $template_id Template identifier
 * @return string HTML with injected JS script tag
 */
function gtemplate_inject_template_js(string $html, string $template_id): string {
    // Allow child themes to define template-to-JS mappings
    $template_js_map = apply_filters('gtemplate_template_js_map', [
        'contact-form' => 'contact-form.js',
        'newsletter-signup' => 'contact-form.js',
        'booking-form' => 'contact-form.js',
    ]);

    if (!isset($template_js_map[$template_id])) {
        return $html;
    }

    $js_file = $template_js_map[$template_id];

    // Check child theme first, then parent theme
    $js_path = get_stylesheet_directory() . '/assets/js/' . $js_file;
    if (!file_exists($js_path)) {
        $js_path = get_template_directory() . '/assets/js/' . $js_file;
    }

    if (!file_exists($js_path)) {
        return $html;
    }

    $js_content = file_get_contents($js_path);
    if ($js_content === false) {
        return $html;
    }

    // Wrap in IIFE to avoid global namespace pollution on repeated loads
    // Mark with data attribute to prevent double-execution
    $script_tag = sprintf(
        '<script data-template-js="%s">(function(){if(document.querySelector(\'script[data-template-js="%s"]\').dataset.executed)return;document.querySelector(\'script[data-template-js="%s"]\').dataset.executed="1";%s})();</script>',
        esc_attr($template_id),
        esc_attr($template_id),
        esc_attr($template_id),
        $js_content
    );

    return $html . $script_tag;
}
