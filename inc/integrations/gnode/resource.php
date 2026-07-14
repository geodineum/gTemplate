<?php
declare(strict_types=1);
/**
 * ResourceManager Integration for gTemplate Asset Management
 *
 * Provides optimized asset management with gNode integration:
 * - Asset bundling (CSS/JS) with minification
 * - Template fragment management
 * - Resource preloading and lazy loading
 * - Cache-busted URLs with versioning
 * - Multi-tenant isolation (site_id/node_id)
 *
 * Performance benefits:
 * - Native gNode asset bundling (batched, single round-trip)
 * - Server-side template rendering via Tera
 * - <1ms cache retrieval from ValKey
 *
 * @package gTemplate
 * @since 1.0.0
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Initialize ResourceManager for gTemplate asset optimization
 *
 * @param \gCore\Modules\Core\gCore $gCore
 * @param \gCore\gNode\Client|null $gNodeClient Optional gNode client for asset bundling
 * @return \gCore\Modules\Managers\Base\ResourceManager\ResourceManager|null
 */
function gtemplate_init_resource_manager($gCore, $gNodeClient = null) {
    try {
        $resource = $gCore->getService('ResourceManager');
        if (!$resource) {
            gtemplate_track_error('gTemplate: ResourceManager not available from gCore');
            return null;
        }

        $resource->initialize([
            'site_id' => gtemplate_get_site_id(),
            'node_id' => 'web-' . gethostname(),
            'use_gnode' => $gNodeClient !== null,
            'gnode_client' => $gNodeClient,
            'cache_enabled' => true,
            'optimization_enabled' => true,
            'default_bundle_type' => 'mixed',
            'default_minify' => !defined('SCRIPT_DEBUG') || !SCRIPT_DEBUG,
            'default_ttl' => 3600, // 1 hour
            'max_bundle_size' => 1048576, // 1MB
            'debug' => defined('WP_DEBUG') && WP_DEBUG
        ]);

        gtemplate_track_error('gTemplate: ResourceManager initialized' . ($gNodeClient ? ' with gNode' : ' (legacy mode)'));
        return $resource;

    } catch (\Throwable $e) {
        gtemplate_track_error('gTemplate: ResourceManager init error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Get versioned asset URL with cache busting
 *
 * Uses VersionManager integration for coordinated cache invalidation
 * across all cube faces and assets.
 *
 * @param string $asset_path Relative path to asset (from theme directory)
 * @param string $group Version group (core, face, api, manifest)
 * @return string Versioned URL
 */
function gtemplate_asset_url(string $asset_path, string $group = 'core'): string {
    $theme_uri = get_template_directory_uri();
    $theme_dir = get_template_directory();

    // Get file modification time for local cache busting
    $file_path = $theme_dir . '/' . ltrim($asset_path, '/');
    $mtime = file_exists($file_path) ? filemtime($file_path) : 0;

    // Get version from VersionManager if available
    $version = gtemplate_get_asset_version($group);

    // Combine version and mtime for reliable cache busting
    $cache_buster = $version . '.' . $mtime;

    return $theme_uri . '/' . ltrim($asset_path, '/') . '?v=' . $cache_buster;
}

/**
 * Get asset version from VersionManager
 *
 * @param string $group Version group
 * @return int Version number
 */
function gtemplate_get_asset_version(string $group = 'core'): int {
    global $gCore;

    if (!$gCore) {
        return 1;
    }

    try {
        $version = $gCore->getService('VersionManager');
        if ($version && $version->isInitialized()) {
            return $version->getVersion($group);
        }
    } catch (\Throwable $e) {
        // Silent fallback
    }

    return 1;
}

/**
 * Create an optimized asset bundle using gNode
 *
 * Combines multiple CSS or JS files into a single optimized bundle
 * with optional minification. Requires gNode integration.
 *
 * @param string $bundle_id Unique bundle identifier
 * @param array $assets Array of asset paths or content
 * @param string $type Bundle type (css, js, mixed)
 * @param bool $minify Enable minification
 * @return array|null Bundle result or null on failure
 */
function gtemplate_create_bundle(string $bundle_id, array $assets, string $type = 'css', bool $minify = true): ?array {
    global $gCore;

    if (!$gCore) {
        return null;
    }

    try {
        $resource = $gCore->getService('ResourceManager');
        if ($resource && $resource->isInitialized()) {
            return $resource->createAssetBundle($bundle_id, $assets, $type, $minify);
        }
    } catch (\Throwable $e) {
        gtemplate_track_error('gTemplate: Bundle creation failed: ' . $e->getMessage());
    }

    return null;
}

/**
 * Get a cached bundle's manifest via ResourceManager
 *
 * @param string $bundle_id Bundle identifier
 * @return array|null Bundle manifest or null
 */
function gtemplate_resource_get_bundle_manifest(string $bundle_id): ?array {
    global $gCore;

    if (!$gCore) {
        return null;
    }

    try {
        $resource = $gCore->getService('ResourceManager');
        if ($resource && $resource->isInitialized()) {
            return $resource->getBundleManifest($bundle_id);
        }
    } catch (\Throwable $e) {
        gtemplate_track_error('gTemplate: Bundle retrieval failed: ' . $e->getMessage());
    }

    return null;
}

/**
 * Invalidate an asset or bundle cache
 *
 * @param string $asset_id Asset or bundle identifier
 * @param string $type Type (asset, bundle, template)
 * @return bool Success
 */
function gtemplate_invalidate_asset(string $asset_id, string $type = 'asset'): bool {
    global $gCore;

    if (!$gCore) {
        return false;
    }

    try {
        $resource = $gCore->getService('ResourceManager');
        if ($resource && $resource->isInitialized()) {
            switch ($type) {
                case 'bundle':
                    // Bundles are invalidated via VersionManager
                    $version = $gCore->getService('VersionManager');
                    if ($version) {
                        $version->incrementVersion('core');
                    }
                    return true;

                case 'template':
                    return $resource->invalidateTemplate($asset_id);

                default:
                    return $resource->invalidateAsset($asset_id);
            }
        }
    } catch (\Throwable $e) {
        gtemplate_track_error('gTemplate: Asset invalidation failed: ' . $e->getMessage());
    }

    return false;
}

/**
 * Register cube face templates with ResourceManager
 *
 * Pre-registers all 6 cube face templates for gNode rendering.
 *
 * @return bool Success
 */
function gtemplate_register_face_templates(): bool {
    global $gCore;

    if (!$gCore) {
        return false;
    }

    try {
        $resource = $gCore->getService('ResourceManager');
        if (!$resource || !$resource->isInitialized()) {
            return false;
        }

        $faces = [
            'gtemplate_face_top' => ['position' => 'top', 'face_id' => 0],
            'gtemplate_face_front' => ['position' => 'front', 'face_id' => 1],
            'gtemplate_face_right' => ['position' => 'right', 'face_id' => 2],
            'gtemplate_face_back' => ['position' => 'back', 'face_id' => 3],
            'gtemplate_face_left' => ['position' => 'left', 'face_id' => 4],
            'gtemplate_face_bottom' => ['position' => 'bottom', 'face_id' => 5],
        ];

        foreach ($faces as $template_id => $variables) {
            $template_path = get_template_directory() . '/templates/' . $template_id . '.html';

            if (file_exists($template_path)) {
                $content = file_get_contents($template_path);
                $resource->storeTemplateFragment(
                    $template_id,
                    $content,
                    [], // dependencies
                    $variables,
                    86400 // 24 hour TTL
                );
            }
        }

        gtemplate_track_error('gTemplate: Registered ' . count($faces) . ' face templates with ResourceManager');
        return true;

    } catch (\Throwable $e) {
        gtemplate_track_error('gTemplate: Face template registration failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Generate preload hints for critical assets
 *
 * Adds <link rel="preload"> tags for critical cube CSS and JS.
 *
 * @return array Preload hints
 */
function gtemplate_get_preload_hints(): array {
    $hints = [];

    // Critical CSS (only if file exists — child themes may not use parent assets)
    $css_path = get_parent_theme_file_path('assets/css/theme.css');
    if (file_exists($css_path)) {
        $hints[] = [
            'href' => gtemplate_asset_url('assets/css/theme.css'),
            'as' => 'style',
            'type' => 'text/css'
        ];
    }

    // Critical JS (only if file exists)
    $js_path = get_parent_theme_file_path('assets/js/theme.js');
    if (file_exists($js_path)) {
        $hints[] = [
            'href' => gtemplate_asset_url('assets/js/theme.js'),
            'as' => 'script',
            'type' => 'text/javascript'
        ];
    }

    return apply_filters('gtemplate_preload_hints', $hints);
}

/**
 * Add preload hints to document head
 */
add_action('wp_head', function() {
    $hints = gtemplate_get_preload_hints();

    foreach ($hints as $hint) {
        printf(
            '<link rel="preload" href="%s" as="%s"%s>%s',
            esc_url($hint['href']),
            esc_attr($hint['as']),
            isset($hint['type']) ? ' type="' . esc_attr($hint['type']) . '"' : '',
            "\n"
        );
    }
}, 1);

/**
 * Add resource hints for external domains
 */
add_action('wp_head', function() {
    // DNS prefetch for common external resources
    $prefetch_domains = apply_filters('gtemplate_dns_prefetch_domains', []);

    foreach ($prefetch_domains as $domain) {
        echo '<link rel="dns-prefetch" href="' . esc_url($domain) . '">' . "\n";
    }
}, 2);

/**
 * REST API endpoint for resource statistics
 */
add_action('rest_api_init', function() {
    register_rest_route(gtemplate_get_rest_namespace(), '/resources/stats', [
        'methods' => 'GET',
        'callback' => 'gtemplate_rest_get_resource_stats',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        },
    ]);

    register_rest_route(gtemplate_get_rest_namespace(), '/resources/invalidate', [
        'methods' => 'POST',
        'callback' => 'gtemplate_rest_invalidate_resource',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        },
        'args' => [
            'type' => [
                'type' => 'string',
                'enum' => ['asset', 'bundle', 'template', 'all'],
                'default' => 'all'
            ],
            'id' => [
                'type' => 'string',
                'default' => ''
            ]
        ]
    ]);
});

/**
 * REST endpoint callback for resource statistics
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function gtemplate_rest_get_resource_stats($request) {
    global $gCore;

    if (!$gCore) {
        return new WP_REST_Response([
            'error' => 'gCore not initialized'
        ], 500);
    }

    try {
        $resource = $gCore->getService('ResourceManager');
        if ($resource && $resource->isInitialized()) {
            return new WP_REST_Response([
                'status' => $resource->getStatus(),
                'statistics' => $resource->getStatistics(),
                'capabilities' => $resource->getCapabilityVector()
            ], 200);
        }

        return new WP_REST_Response([
            'error' => 'ResourceManager not available'
        ], 503);

    } catch (\Throwable $e) {
        return new WP_REST_Response([
            'error' => $e->getMessage()
        ], 500);
    }
}

/**
 * REST endpoint callback for cache invalidation
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function gtemplate_rest_invalidate_resource($request) {
    $type = $request->get_param('type');
    $id = $request->get_param('id');

    if ($type === 'all') {
        // Increment all versions to invalidate everything
        global $gCore;
        if ($gCore) {
            try {
                $version = $gCore->getService('VersionManager');
                if ($version) {
                    $version->incrementAllVersions();
                    return new WP_REST_Response([
                        'success' => true,
                        'message' => 'All resource caches invalidated'
                    ], 200);
                }
            } catch (\Throwable $e) {
                return new WP_REST_Response([
                    'error' => $e->getMessage()
                ], 500);
            }
        }
    }

    if (empty($id)) {
        return new WP_REST_Response([
            'error' => 'Resource ID required for specific invalidation'
        ], 400);
    }

    $success = gtemplate_invalidate_asset($id, $type);

    return new WP_REST_Response([
        'success' => $success,
        'type' => $type,
        'id' => $id
    ], $success ? 200 : 500);
}

/**
 * Invalidate all resources when theme is updated
 */
add_action('upgrader_process_complete', function($upgrader, $options) {
    if (is_array($options) &&
        isset($options['action']) && $options['action'] === 'update' &&
        isset($options['type']) && $options['type'] === 'theme') {

        global $gCore;
        if ($gCore) {
            try {
                $version = $gCore->getService('VersionManager');
                if ($version) {
                    $version->incrementAllVersions();
                    gtemplate_track_error('gTemplate: Resource caches invalidated after theme update');
                }
            } catch (\Throwable $e) {
                gtemplate_track_error('gTemplate: Failed to invalidate caches: ' . $e->getMessage());
            }
        }
    }
}, 10, 2);

/**
 * Filter: Add cache-busted version to WordPress script/style URLs
 */
add_filter('style_loader_src', 'gtemplate_add_cache_buster', 10, 2);
add_filter('script_loader_src', 'gtemplate_add_cache_buster', 10, 2);

/**
 * Add cache buster to theme assets
 *
 * @param string $src Source URL
 * @param string $handle Asset handle
 * @return string Modified URL
 */
function gtemplate_add_cache_buster($src, $handle) {
    // WordPress passes false/empty for styles + scripts registered without a
    // src (inline, or src deregistered). strpos() on a non-string is a fatal
    // TypeError on PHP 8+, so bail before touching it.
    if (!is_string($src) || $src === '') {
        return $src;
    }

    // Only modify our theme's assets
    if (strpos($src, get_template_directory_uri()) === false) {
        return $src;
    }

    // Skip if already has version parameter
    if (strpos($src, 'ver=') !== false) {
        return $src;
    }

    $version = gtemplate_get_asset_version('core');

    return add_query_arg('v', $version, $src);
}
