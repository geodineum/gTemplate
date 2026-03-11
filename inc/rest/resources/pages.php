<?php
/**
 * REST API Resource: Pages
 *
 * Endpoints for loading WordPress page content into faces.
 *
 * Routes:
 *   GET /page/{page_id} - Load page content with gNode/fallback rendering
 *
 * @package gTemplate
 * @subpackage REST
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register page-related REST routes
 *
 * @param string $namespace REST API namespace
 */
function gtemplate_register_page_routes(string $namespace): void {
    register_rest_route($namespace, '/page/(?P<page_id>\d+)', [
        'methods' => 'GET',
        'callback' => 'gtemplate_rest_get_page',
        'permission_callback' => '__return_true',
        'args' => [
            'page_id' => [
                'required' => true,
                'type' => 'integer',
                'minimum' => 1
            ]
        ]
    ]);
}

/**
 * REST endpoint: Load WordPress page into face
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function gtemplate_rest_get_page($request) {
    global $gCore;

    $page_id = (int) $request['page_id'];

    // Security validation using gCore SecurityManager
    try {
        $security = $gCore->getService('Security');

        if ($security) {
            $validation = $security->validateAPIRequest($request, [
                'rate_limit' => [
                    'limit' => 100,        // 100 requests
                    'window' => 60,        // per 60 seconds
                    'identifier' => gtemplate_get_client_identifier()
                ],
                'params' => [
                    'page_id' => [
                        'type' => 'integer',
                        'min' => 1,
                        'required' => true
                    ]
                ]
            ]);

            if (!$validation['valid']) {
                return new WP_Error(
                    $validation['error_code'] ?? 'validation_failed',
                    $validation['error_message'] ?? 'Invalid page ID',
                    ['status' => $validation['status_code'] ?? 400]
                );
            }
        }
    } catch (\Throwable $e) {
        error_log('gTemplate: Page security validation failed: ' . $e->getMessage());
    }

    // Check if page exists
    $page = get_post($page_id);

    if (!$page || $page->post_type !== 'page') {
        return new WP_Error(
            'page_not_found',
            'Page not found',
            ['status' => 404]
        );
    }

    if ($page->post_status !== 'publish') {
        if (!current_user_can('edit_post', $page_id)) {
            return new WP_Error(
                'page_not_published',
                'Page is not published',
                ['status' => 403]
            );
        }
    }

    // TIER 1: Try key-based client first (non-blocking, fastest for cached content)
    $keybased = gtemplate_gnode_keybased();
    $template_id = "wp_page_{$page_id}";

    if ($keybased) {
        try {
            $variables = gtemplate_get_page_variables($page_id);
            $html = $keybased->renderTemplate($template_id, $variables);

            if ($html && !empty($html)) {
                return new WP_REST_Response($html, 200, [
                    'Content-Type' => 'text/html; charset=utf-8',
                    'X-Page-ID' => $page_id,
                    'X-Rendered-By' => 'gNode-KeyBased',
                    'X-Cache-Status' => 'hit',
                    'X-Render-Time' => '~10ms'
                ]);
            }
        } catch (\Throwable $e) {
            // Fall through to stream-based client
            error_log("gTemplate: Key-based render failed for page {$page_id}: " . $e->getMessage());
        }
    }

    // TIER 2: Try stream-based client (blocking, for cache misses that need generation)
    $gNode = gtemplate_gnode();

    if (!$gNode) {
        // TIER 3: Fallback rendering (PHP-based)
        return new WP_REST_Response(
            gtemplate_render_page_fallback($page),
            200,
            [
                'Content-Type' => 'text/html; charset=utf-8',
                'X-Fallback' => 'true'
            ]
        );
    }

    // Get cached variables
    $variables = gtemplate_get_page_variables($page_id);

    try {
        $html = $gNode->renderTemplate($template_id, $variables);

        if ($html && !empty($html)) {
            // Success - return gNode-rendered HTML
            return new WP_REST_Response($html, 200, [
                'Content-Type' => 'text/html; charset=utf-8',
                'X-Page-ID' => $page_id,
                'X-Rendered-By' => 'gNode-Stream',
                'X-Cache-Status' => 'miss',
                'X-Render-Time' => '~100ms'
            ]);
        } else {
            // Template not registered - register now and retry
            error_log("gTemplate: Template '{$template_id}' not found, registering now");
            gtemplate_register_page_template($page_id);

            // Retry render
            $html = $gNode->renderTemplate($template_id, $variables);

            if ($html && !empty($html)) {
                return new WP_REST_Response($html, 200, [
                    'Content-Type' => 'text/html; charset=utf-8',
                    'X-Page-ID' => $page_id,
                    'X-Rendered-By' => 'gNode-Stream',
                    'X-Cache-Status' => 'generated',
                    'X-Render-Time' => '~150ms'
                ]);
            } else {
                throw new \RuntimeException('gNode render returned empty result');
            }
        }

    } catch (\Throwable $e) {
        error_log("gTemplate: gNode render failed for page {$page_id}: " . $e->getMessage());

        // TIER 3: Fallback to PHP rendering
        return new WP_REST_Response(
            gtemplate_render_page_fallback($page),
            200,
            [
                'Content-Type' => 'text/html; charset=utf-8',
                'X-Fallback' => 'true',
                'X-Error' => 'gNode unavailable'
            ]
        );
    }
}

/**
 * Fallback page rendering (PHP-based, no gNode)
 *
 * @param WP_Post $page WordPress page object
 * @return string Rendered HTML
 */
function gtemplate_render_page_fallback($page) {
    $featured_image = get_the_post_thumbnail_url($page, 'large');
    $author = get_the_author_meta('display_name', $page->post_author);
    $date = get_the_date('', $page);
    $excerpt = $page->post_excerpt ?: wp_trim_words($page->post_content, 30);

    ob_start();
    ?>
    <div class="face-content wordpress-page fallback" data-page-id="<?php echo esc_attr($page->ID); ?>" data-slug="<?php echo esc_attr($page->post_name); ?>">
        <header class="face-header">
            <h2 class="face-title"><?php echo esc_html($page->post_title); ?></h2>

            <?php if ($featured_image): ?>
            <div class="face-featured-image">
                <img src="<?php echo esc_url($featured_image); ?>"
                     alt="<?php echo esc_attr($page->post_title); ?>"
                     loading="lazy">
            </div>
            <?php endif; ?>
        </header>

        <main class="face-body">
            <?php if ($excerpt): ?>
            <div class="page-excerpt">
                <p><?php echo esc_html($excerpt); ?></p>
            </div>
            <?php endif; ?>

            <div class="page-content">
                <?php echo apply_filters('the_content', $page->post_content); ?>
            </div>

            <?php if ($author || $date): ?>
            <div class="page-meta">
                <?php if ($author): ?>
                <span class="author">By <?php echo esc_html($author); ?></span>
                <?php endif; ?>

                <?php if ($date): ?>
                <time class="date" datetime="<?php echo esc_attr(get_the_date('c', $page)); ?>">
                    <?php echo esc_html($date); ?>
                </time>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </main>

        <footer class="face-footer">
            <a href="<?php echo esc_url(get_permalink($page)); ?>" class="read-more">
                Read Full Page <span aria-hidden="true">&rarr;</span>
            </a>

            <small class="site-credit">
                <span><?php bloginfo('name'); ?></span>
                <span class="updated">Updated: <?php echo esc_html(get_the_modified_date('', $page)); ?></span>
            </small>
        </footer>
    </div>
    <?php
    return ob_get_clean();
}
