<?php
declare(strict_types=1);
/**
 * Keybased / Bundle-cache Integration for gTemplate
 *
 * Reads pre-rendered face bundles from gNode for fast template
 * delivery (avoids the ~114ms stream-based round-trip on warm-cache
 * hits, ~10ms typical). This file talks to the `gNodeClientInterface`
 * surface — gNodeClient (which implements gNodeClientInterface) is the
 * unified client for key-based reads and stream-based writes.
 *
 * Usage:
 * 1. gNode daemon running with bundle-cache producer (gnode-cms or
 *    equivalent) populating the {site}:gnode:bundle:full key.
 * 2. This file is loaded by gTemplate's bootstrap autoload at Phase 9
 *    (integrations) — no manual require needed.
 * 3. Callers use gtemplate_get_face_from_bundle($faceId),
 *    gtemplate_render_template_fast(), gtemplate_get_bundle(), etc.
 *
 * @package gTemplate
 * @version 2.1.0
 */

use gCore\gNode\gNodeClientInterface;
use gCore\gNode\Storage\ValKeyStorage;
use gCore\gNode\Exception\KeyBasedException;

/**
 * Get the gNode client instance from the keybased-tier global.
 *
 * The global `gtemplate_gnode_keybased_client` is populated
 * by gcore-init.php with a `\gCore\gNode\gNodeClient` instance; that
 * class implements gNodeClientInterface, so this getter returns it
 * via the interface's contract rather than the removed concrete
 * class. The defensive `instanceof KeyBasedClient` gate from the
 * A simple isset() is sufficient — the return type aligns with the
 * canonical client class.
 *
 * @return gNodeClientInterface|null
 */
function gtemplate_get_keybased_client(): ?gNodeClientInterface
{
    if (isset($GLOBALS['gtemplate_gnode_keybased_client'])
        && $GLOBALS['gtemplate_gnode_keybased_client'] instanceof gNodeClientInterface) {
        return $GLOBALS['gtemplate_gnode_keybased_client'];
    }

    // Admin / REST context: gCore is frontend-only-initialized, so the frontend
    // global is absent here and the Cache page reported "KeyBasedClient may not
    // be initialized". Lazily build the lightweight per-site admin client (the
    // same gNodeClient::forSite the gCore admin pages use) so cache stats +
    // invalidation work in wp-admin without a full frontend gCore init. Gated to
    // backend so frontend free-tier (global explicitly null) stays untouched.
    $is_backend = is_admin() || (defined('REST_REQUEST') && REST_REQUEST);
    if ($is_backend && function_exists('gcore_get_admin_gnode_client')) {
        $client = gcore_get_admin_gnode_client();
        if ($client instanceof gNodeClientInterface) {
            $GLOBALS['gtemplate_gnode_keybased_client'] = $client;
            return $client;
        }
    }

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

    gtemplate_track_error('[gTemplate gNode] Stream-based Client not initialized (missing global)');
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
        gtemplate_track_error('[gTemplate KeyBased] Failed to get face: ' . $e->getMessage());
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
        gtemplate_track_error('[gTemplate KeyBased] Failed to get bundle: ' . $e->getMessage());
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
        gtemplate_track_error('[gTemplate KeyBased] Template render failed: ' . $e->getMessage());
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
        gtemplate_track_error('[gTemplate KeyBased] Cache invalidation failed: ' . $e->getMessage());
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
        gtemplate_track_error('[gTemplate KeyBased] Bundle invalidation failed: ' . $e->getMessage());
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
        gtemplate_track_error('[gTemplate KeyBased] Failed to get cache stats: ' . $e->getMessage());
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
        gtemplate_track_error('[gTemplate KeyBased] Failed to get navigation: ' . $e->getMessage());
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
        gtemplate_track_error('[gTemplate KeyBased] Failed to get posts: ' . $e->getMessage());
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
        gtemplate_track_error('[gTemplate KeyBased] Failed to get metadata: ' . $e->getMessage());
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

    gtemplate_track_error('[gTemplate KeyBased] Invalidated cache after post save: ' . $post_id);
}, 10, 1);

/**
 * Hook: Invalidate cache when theme options are updated
 */
add_action('update_option', function($option_name, $old_value, $value) {
    // Only invalidate for theme-related options
    if (strpos($option_name, 'gtemplate_') === 0 || strpos($option_name, 'theme_') === 0) {
        gtemplate_invalidate_bundle();
        gtemplate_invalidate_cache();
        gtemplate_track_error('[gTemplate KeyBased] Invalidated cache after option update: ' . $option_name);
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
    add_submenu_page(
        'gcore-dashboard',
        'Cache Stats',
        'Cache Stats',
        'manage_options',
        'gtemplate-cache',
        function() {
            $stats = gtemplate_get_cache_stats();
            ?>
            <div class="wrap gdash">
                <h1><?php echo esc_html__('gTemplate Cache Management', 'gcore'); ?></h1>

                <div class="gdash-card">
                    <div class="gdash-card-title"><?php echo esc_html__('Cache Statistics', 'gcore'); ?></div>
                    <?php if (!empty($stats)):
                        // GNODE_CACHE_STATS returns hits/misses/writes/deletes/
                        // items/total_size/hit_ratio/... (not site_id/key_count/
                        // total_size_mb). Render what it actually returns.
                        $hits    = (int) ($stats['hits'] ?? 0);
                        $misses  = (int) ($stats['misses'] ?? 0);
                        $reqs    = $hits + $misses;
                        $ratio   = isset($stats['hit_ratio']) ? (float) $stats['hit_ratio'] : ($reqs > 0 ? $hits / $reqs : 0.0);
                        $items   = (int) ($stats['items'] ?? 0);
                        $writes  = (int) ($stats['writes'] ?? 0);
                        $deletes = (int) ($stats['deletes'] ?? 0);
                        $bytes   = (int) ($stats['total_size'] ?? 0);
                        $human   = $bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB'
                                 : ($bytes >= 1024 ? number_format($bytes / 1024, 1) . ' KB' : $bytes . ' B');
                    ?>
                        <div class="gdash-stat-grid">
                            <div class="gdash-stat">
                                <div class="gdash-stat-label"><?php echo esc_html__('Hit rate', 'gcore'); ?></div>
                                <div class="gdash-stat-value"><?php echo esc_html(number_format($ratio * 100, 1)); ?>%</div>
                                <div class="gdash-stat-sub"><?php echo esc_html(sprintf(__('%1$s hits / %2$s misses', 'gcore'), number_format($hits), number_format($misses))); ?></div>
                            </div>
                            <div class="gdash-stat">
                                <div class="gdash-stat-label"><?php echo esc_html__('Cached items', 'gcore'); ?></div>
                                <div class="gdash-stat-value"><?php echo esc_html(number_format($items)); ?></div>
                                <div class="gdash-stat-sub"><?php echo esc_html($human); ?></div>
                            </div>
                            <div class="gdash-stat">
                                <div class="gdash-stat-label"><?php echo esc_html__('Writes / Deletes', 'gcore'); ?></div>
                                <div class="gdash-stat-value"><?php echo esc_html(number_format($writes)); ?> / <?php echo esc_html(number_format($deletes)); ?></div>
                                <div class="gdash-stat-sub"><?php echo esc_html(sprintf(__('%s requests total', 'gcore'), number_format($reqs))); ?></div>
                            </div>
                        </div>
                    <?php else: ?>
                        <p><?php echo esc_html__('Cache statistics not available yet. If this persists after a page refresh, the cache metrics hash may be empty (no traffic) or the GNODE_CACHE_STATS function needs reloading on the daemon.', 'gcore'); ?></p>
                    <?php endif; ?>
                </div>

                <div class="gdash-card">
                    <div class="gdash-card-title"><?php echo esc_html__('Actions', 'gcore'); ?></div>
                    <form method="post" action="">
                        <?php wp_nonce_field('gtemplate_cache_actions'); ?>
                        <p>
                            <button type="submit" name="action" value="invalidate_cache" class="button">
                                <?php echo esc_html__('Invalidate All Cache', 'gcore'); ?>
                            </button>
                            <button type="submit" name="action" value="invalidate_bundle" class="button">
                                <?php echo esc_html__('Invalidate Bundle', 'gcore'); ?>
                            </button>
                        </p>
                    </form>

                    <?php
                    if (isset($_POST['action']) && check_admin_referer('gtemplate_cache_actions')) {
                        if ($_POST['action'] === 'invalidate_cache') {
                            $count = gtemplate_invalidate_cache();
                            echo '<div class="notice notice-success"><p>' . esc_html(sprintf(__('Invalidated %d cache keys.', 'gcore'), $count)) . '</p></div>';
                        } elseif ($_POST['action'] === 'invalidate_bundle') {
                            $success = gtemplate_invalidate_bundle();
                            if ($success) {
                                echo '<div class="notice notice-success"><p>' . esc_html__('Bundle invalidated successfully.', 'gcore') . '</p></div>';
                            } else {
                                echo '<div class="notice notice-error"><p>' . esc_html__('Bundle invalidation failed.', 'gcore') . '</p></div>';
                            }
                        }
                    }
                    ?>
                </div>

                <div class="gdash-card">
                    <div class="gdash-card-title"><?php echo esc_html__('REST API Endpoints', 'gcore'); ?></div>
                    <ul>
                        <li><code>GET /wp-json/gtemplate/v1/cache/stats</code> - <?php echo esc_html__('Get cache statistics', 'gcore'); ?></li>
                        <li><code>POST /wp-json/gtemplate/v1/cache/invalidate</code> - <?php echo esc_html__('Invalidate cache (optional param: pattern)', 'gcore'); ?></li>
                        <li><code>POST /wp-json/gtemplate/v1/bundle/invalidate</code> - <?php echo esc_html__('Invalidate bundle', 'gcore'); ?></li>
                    </ul>
                </div>
            </div>
            <?php
        }
    );
});
