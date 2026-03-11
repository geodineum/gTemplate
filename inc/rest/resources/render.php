<?php
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
        error_log('[gTemplate REST] Template render error: ' . $e->getMessage());

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
