<?php
/**
 * KeyBasedClient Integration for gTemplate
 *
 * Demonstrates how to use the new KeyBasedClient for 11× faster gNode operations.
 *
 * Performance comparison:
 * - Stream-based (old): 114ms average per request
 * - Key-based (new):     10ms average per request
 *
 * Usage:
 * 1. Ensure gNode daemon is running with key-based response handler
 * 2. Include this file: require_once get_template_directory() . '/inc/keybased-integration.php';
 * 3. Call functions: gtemplate_get_face_from_bundle(), gtemplate_render_template_fast(), etc.
 *
 * @package gTemplate
 * @version 2.0.0
 */

use gCore\gNode\KeyBasedClient;
use gCore\gNode\Storage\ValKeyStorage;
use gCore\gNode\Exception\KeyBasedException;

/**
 * Get KeyBasedClient instance from global variable
 *
 * @return KeyBasedClient|null
 */
function gtemplate_get_keybased_client(): ?KeyBasedClient
{
    // Get from global variable (initialized in functions.php)
    if (isset($GLOBALS['gtemplate_gnode_keybased_client'])) {
        return $GLOBALS['gtemplate_gnode_keybased_client'];
    }

    error_log('[gTemplate KeyBased] KeyBasedClient not initialized (missing global)');
    return null;
}

/**
 * Get stream-based gNode Client instance from global variable
 *
 * @return \gCore\gNode\Client|null
 */
function gtemplate_get_gnode_client(): ?\gCore\gNode\Client
{
    // Get from global variable (initialized in functions.php)
    if (isset($GLOBALS['gtemplate_gnode_client'])) {
        return $GLOBALS['gtemplate_gnode_client'];
    }

    error_log('[gTemplate gNode] Stream-based Client not initialized (missing global)');
    return null;
}

/**
 * Get face HTML from bundle (fastest method)
 *
 * @param int $faceId Face ID (0-5)
 * @return string|null Face HTML or null if not available
 */
function gtemplate_get_face_from_bundle(int $faceId): ?string
{
    $client = gtemplate_get_keybased_client();
    if (!$client) {
        return null;
    }

    try {
        return $client->getFaceHtml($faceId);
    } catch (KeyBasedException $e) {
        error_log('[gTemplate KeyBased] Failed to get face: ' . $e->getMessage());
        return null;
    }
}

/**
 * Get entire bundle (all faces, posts, navigation, metadata)
 *
 * @return array|null Bundle data or null if not available
 */
function gtemplate_get_bundle(): ?array
{
    $client = gtemplate_get_keybased_client();
    if (!$client) {
        return null;
    }

    try {
        return $client->getBundle();
    } catch (KeyBasedException $e) {
        error_log('[gTemplate KeyBased] Failed to get bundle: ' . $e->getMessage());
        return null;
    }
}

/**
 * Render template with automatic caching (key-based)
 *
 * @param string $templateId Template identifier
 * @param array $context Template context variables
 * @return string|null Rendered HTML or null on error
 */
function gtemplate_render_template_fast(string $templateId, array $context = []): ?string
{
    $client = gtemplate_get_keybased_client();
    if (!$client) {
        return null;
    }

    try {
        $response = $client->renderTemplate($templateId, $context);
        return $response['result'] ?? null;
    } catch (KeyBasedException $e) {
        error_log('[gTemplate KeyBased] Template render failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Invalidate cache for specific pattern
 *
 * @param string|null $pattern Key pattern (null = invalidate all site cache)
 * @return int Number of keys invalidated
 */
function gtemplate_invalidate_cache(?string $pattern = null): int
{
    $client = gtemplate_get_keybased_client();
    if (!$client) {
        return 0;
    }

    try {
        return $client->invalidateCache($pattern);
    } catch (KeyBasedException $e) {
        error_log('[gTemplate KeyBased] Cache invalidation failed: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Invalidate bundle (forces rebuild by gNode daemon)
 *
 * @return bool True if bundle was invalidated
 */
function gtemplate_invalidate_bundle(): bool
{
    $client = gtemplate_get_keybased_client();
    if (!$client) {
        return false;
    }

    try {
        return $client->invalidateBundle();
    } catch (KeyBasedException $e) {
        error_log('[gTemplate KeyBased] Bundle invalidation failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get cache statistics
 *
 * @return array Cache stats (key_count, total_size_mb, etc.)
 */
function gtemplate_get_cache_stats(): array
{
    $client = gtemplate_get_keybased_client();
    if (!$client) {
        return [];
    }

    try {
        return $client->getCacheStats();
    } catch (KeyBasedException $e) {
        error_log('[gTemplate KeyBased] Failed to get cache stats: ' . $e->getMessage());
        return [];
    }
}

/**
 * Get navigation menu from bundle
 *
 * @return array|null Navigation menu or null
 */
function gtemplate_get_navigation_from_bundle(): ?array
{
    $client = gtemplate_get_keybased_client();
    if (!$client) {
        return null;
    }

    try {
        return $client->getNavigationMenu();
    } catch (KeyBasedException $e) {
        error_log('[gTemplate KeyBased] Failed to get navigation: ' . $e->getMessage());
        return null;
    }
}

/**
 * Get posts list from bundle
 *
 * @return array|null Posts list or null
 */
function gtemplate_get_posts_from_bundle(): ?array
{
    $client = gtemplate_get_keybased_client();
    if (!$client) {
        return null;
    }

    try {
        return $client->getPostsList();
    } catch (KeyBasedException $e) {
        error_log('[gTemplate KeyBased] Failed to get posts: ' . $e->getMessage());
        return null;
    }
}

/**
 * Get site metadata from bundle
 *
 * @return array|null Site metadata or null
 */
function gtemplate_get_metadata_from_bundle(): ?array
{
    $client = gtemplate_get_keybased_client();
    if (!$client) {
        return null;
    }

    try {
        return $client->getSiteMetadata();
    } catch (KeyBasedException $e) {
        error_log('[gTemplate KeyBased] Failed to get metadata: ' . $e->getMessage());
        return null;
    }
}

/**
 * Hook: Invalidate cache when post is saved
 */
add_action('save_post', function($post_id) {
    // Skip autosaves and revisions
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (wp_is_post_revision($post_id)) {
        return;
    }

    // Invalidate bundle and cache
    gtemplate_invalidate_bundle();
    gtemplate_invalidate_cache('cache:*');

    error_log('[gTemplate KeyBased] Invalidated cache after post save: ' . $post_id);
}, 10, 1);

/**
 * Hook: Invalidate cache when theme options are updated
 */
add_action('update_option', function($option_name, $old_value, $value) {
    // Only invalidate for theme-related options
    if (strpos($option_name, 'gtemplate_') === 0 || strpos($option_name, 'theme_') === 0) {
        gtemplate_invalidate_bundle();
        gtemplate_invalidate_cache();
        error_log('[gTemplate KeyBased] Invalidated cache after option update: ' . $option_name);
    }
}, 10, 3);

/**
 * REST API endpoint: Get cache statistics
 */
add_action('rest_api_init', function() {
    register_rest_route(gtemplate_get_rest_namespace(), '/cache/stats', [
        'methods' => 'GET',
        'callback' => function() {
            $stats = gtemplate_get_cache_stats();
            return new \WP_REST_Response($stats, 200);
        },
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ]);

    register_rest_route(gtemplate_get_rest_namespace(), '/cache/invalidate', [
        'methods' => 'POST',
        'callback' => function(\WP_REST_Request $request) {
            $pattern = $request->get_param('pattern');
            $count = gtemplate_invalidate_cache($pattern);
            return new \WP_REST_Response([
                'invalidated' => $count,
                'pattern' => $pattern ?? 'all'
            ], 200);
        },
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ]);

    register_rest_route(gtemplate_get_rest_namespace(), '/bundle/invalidate', [
        'methods' => 'POST',
        'callback' => function() {
            $success = gtemplate_invalidate_bundle();
            return new \WP_REST_Response([
                'success' => $success,
                'message' => $success ? 'Bundle invalidated' : 'Bundle invalidation failed'
            ], $success ? 200 : 500);
        },
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ]);

    // Bundle version endpoint - lightweight (~200 bytes) for cache validation
    // Public access, no auth needed - returns only version hash, not content
    register_rest_route(gtemplate_get_rest_namespace(), '/bundle/version', [
        'methods' => 'GET',
        'callback' => function() {
            global $gCore;

            try {
                // Get gNode client from gCore
                $client = $gCore ? $gCore->getService('gnode_client') : null;
                if (!$client) {
                    return new \WP_REST_Response(['error' => 'gNode client not available'], 503);
                }

                // gNodeClient::getBundle() fetches and decompresses from ValKey
                $bundle = $client->getBundle(true);
                if (!$bundle) {
                    return new \WP_REST_Response(['error' => 'Bundle not found'], 404);
                }

                // Return minimal version info for cache validation (~100 bytes)
                $version = [
                    'v' => $bundle['version'] ?? '2.0.0',
                    't' => (int)($bundle['built_at'] ?? 0),
                    'h' => substr(md5(json_encode($bundle)), 0, 8), // 8-char content hash
                    'f' => count($bundle['faces'] ?? []),
                ];

                return new \WP_REST_Response($version, 200, [
                    'Cache-Control' => 'no-cache, must-revalidate',
                    'ETag' => '"' . $version['h'] . '"',
                ]);
            } catch (\Exception $e) {
                return new \WP_REST_Response(['error' => $e->getMessage()], 500);
            }
        },
        'permission_callback' => '__return_true' // Public endpoint
    ]);
});

/**
 * Admin menu: Cache management
 */
add_action('admin_menu', function() {
    add_management_page(
        'gTemplate Cache',
        'gTemplate Cache',
        'manage_options',
        'gtemplate-cache',
        function() {
            $stats = gtemplate_get_cache_stats();
            ?>
            <div class="wrap">
                <h1>gTemplate Cache Management</h1>

                <div class="card">
                    <h2>Cache Statistics</h2>
                    <?php if (!empty($stats)): ?>
                        <table class="widefat">
                            <tr><th>Site ID</th><td><?php echo esc_html($stats['site_id']); ?></td></tr>
                            <tr><th>Total Keys</th><td><?php echo number_format($stats['key_count']); ?></td></tr>
                            <tr><th>Total Size</th><td><?php echo esc_html($stats['total_size_mb']); ?> MB</td></tr>
                        </table>
                    <?php else: ?>
                        <p>Cache statistics not available. KeyBasedClient may not be initialized.</p>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <h2>Actions</h2>
                    <form method="post" action="">
                        <?php wp_nonce_field('gtemplate_cache_actions'); ?>
                        <p>
                            <button type="submit" name="action" value="invalidate_cache" class="button">
                                Invalidate All Cache
                            </button>
                            <button type="submit" name="action" value="invalidate_bundle" class="button">
                                Invalidate Bundle
                            </button>
                        </p>
                    </form>

                    <?php
                    if (isset($_POST['action']) && check_admin_referer('gtemplate_cache_actions')) {
                        if ($_POST['action'] === 'invalidate_cache') {
                            $count = gtemplate_invalidate_cache();
                            echo '<div class="notice notice-success"><p>Invalidated ' . $count . ' cache keys.</p></div>';
                        } elseif ($_POST['action'] === 'invalidate_bundle') {
                            $success = gtemplate_invalidate_bundle();
                            if ($success) {
                                echo '<div class="notice notice-success"><p>Bundle invalidated successfully.</p></div>';
                            } else {
                                echo '<div class="notice notice-error"><p>Bundle invalidation failed.</p></div>';
                            }
                        }
                    }
                    ?>
                </div>

                <div class="card">
                    <h2>REST API Endpoints</h2>
                    <ul>
                        <li><code>GET /wp-json/gtemplate/v1/cache/stats</code> - Get cache statistics</li>
                        <li><code>POST /wp-json/gtemplate/v1/cache/invalidate</code> - Invalidate cache (optional param: pattern)</li>
                        <li><code>POST /wp-json/gtemplate/v1/bundle/invalidate</code> - Invalidate bundle</li>
                    </ul>
                </div>
            </div>
            <?php
        }
    );
});
