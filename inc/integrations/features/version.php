<?php
/**
 * VersionManager Integration for gTemplate Cache Management
 *
 * Provides coordinated cache versioning and invalidation:
 * - Group-based version tracking (core, face, api, manifest)
 * - Automatic cache busting on theme/plugin updates
 * - Versioned cache key generation
 * - Multi-tenant isolation (site_id/node_id)
 *
 * Cache groups:
 * - core: Base theme assets, global CSS/JS
 * - face: Cube face content and templates
 * - api: REST API responses
 * - manifest: PWA manifest and configuration
 *
 * @package gTemplate
 * @since 1.0.0
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Initialize VersionManager for gTemplate cache coordination
 *
 * @param \gCore\Modules\Core\gCore $gCore
 * @return \gCore\Modules\Managers\Base\VersionManager\VersionManager|null
 */
function gtemplate_init_version_manager($gCore) {
    try {
        $version = $gCore->getService('VersionManager');
        if (!$version) {
            error_log('gTemplate: VersionManager not available from gCore');
            return null;
        }

        $version->initialize([
            'site_id' => gtemplate_get_site_id(),
            'node_id' => 'web-' . gethostname(),
            'version_prefix' => 'gtemplate_',
            'auto_increment' => true,
            'store_history' => false,
            'debug' => defined('WP_DEBUG') && WP_DEBUG,
            'ttl' => DAY_IN_SECONDS
        ]);

        // Register cube-specific cache groups
        gtemplate_register_version_groups($version);

        error_log('gTemplate: VersionManager initialized with cache groups');
        return $version;

    } catch (\Throwable $e) {
        error_log('gTemplate: VersionManager init error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Register cube-specific version groups
 *
 * @param object $version VersionManager instance
 */
function gtemplate_register_version_groups($version) {
    $groups = [
        'core' => 1,      // Base theme assets
        'face' => 1,      // Cube face content
        'api' => 1,       // REST API responses
        'manifest' => 1,  // PWA manifest
        'bundle' => 1,    // Pre-rendered bundles
        'seo' => 1,       // SEO metadata
    ];

    foreach ($groups as $group => $initial) {
        $version->registerGroup($group, $initial);
    }
}

/**
 * Get version for a specific cache group
 *
 * @param string $group Cache group name
 * @return int Version number
 */
function gtemplate_get_version(string $group = 'core'): int {
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
 * Increment version for a specific cache group
 *
 * Use this when content in a specific group changes
 * (e.g., face content updated, API schema changed).
 *
 * @param string $group Cache group name
 * @return int New version number
 */
function gtemplate_increment_version(string $group = 'core'): int {
    global $gCore;

    if (!$gCore) {
        return 1;
    }

    try {
        $version = $gCore->getService('VersionManager');
        if ($version && $version->isInitialized()) {
            return $version->incrementVersion($group);
        }
    } catch (\Throwable $e) {
        error_log('gTemplate: Version increment failed: ' . $e->getMessage());
    }

    return 1;
}

/**
 * Increment all cache versions
 *
 * Use this for major updates that affect all cached content
 * (e.g., theme update, major configuration change).
 */
function gtemplate_increment_all_versions(): void {
    global $gCore;

    if (!$gCore) {
        return;
    }

    try {
        $version = $gCore->getService('VersionManager');
        if ($version && $version->isInitialized()) {
            $version->incrementAllVersions();
            error_log('gTemplate: All cache versions incremented');
        }
    } catch (\Throwable $e) {
        error_log('gTemplate: All versions increment failed: ' . $e->getMessage());
    }
}

/**
 * Get versioned cache key
 *
 * Generates a cache key with version prefix for multi-tenant
 * and version-aware caching.
 *
 * @param string $key Base cache key
 * @param string $group Cache group
 * @return string Versioned cache key
 */
function gtemplate_cache_key(string $key, string $group = 'core'): string {
    global $gCore;

    if (!$gCore) {
        return $key;
    }

    try {
        $version = $gCore->getService('VersionManager');
        if ($version && $version->isInitialized()) {
            return $version->generateKey($key, $group);
        }
    } catch (\Throwable $e) {
        // Silent fallback
    }

    return $key;
}

/**
 * Get cache prefix for a group
 *
 * Returns the full prefix including version, group, and tenant info.
 * Useful for bulk cache operations.
 *
 * @param string $group Cache group
 * @return string Cache prefix
 */
function gtemplate_cache_prefix(string $group = 'core'): string {
    global $gCore;

    if (!$gCore) {
        return 'gtemplate_';
    }

    try {
        $version = $gCore->getService('VersionManager');
        if ($version && $version->isInitialized()) {
            return $version->getPrefix($group);
        }
    } catch (\Throwable $e) {
        // Silent fallback
    }

    return 'gtemplate_';
}

/**
 * Get all version information
 *
 * @return array Version info for all groups
 */
function gtemplate_get_all_versions(): array {
    global $gCore;

    if (!$gCore) {
        return [];
    }

    try {
        $version = $gCore->getService('VersionManager');
        if ($version && $version->isInitialized()) {
            $status = $version->getStatus();
            return $status['versions'] ?? [];
        }
    } catch (\Throwable $e) {
        // Silent fallback
    }

    return [];
}

/**
 * Invalidate face cache when customizer saves
 *
 * Automatically increments face version when face content changes.
 */
add_action('customize_save_after', function() {
    gtemplate_increment_version('face');
    gtemplate_increment_version('manifest');
    error_log('gTemplate: Face and manifest versions incremented (customizer save)');
});

/**
 * Invalidate API cache when REST endpoints change
 *
 * Hook into specific actions that should invalidate API responses.
 */
add_action('save_post', function($post_id, $post, $update) {
    // Only increment if this is a published page/post that could be on a theme face
    if ($post->post_status === 'publish' &&
        in_array($post->post_type, ['page', 'post'])) {

        // Check if this post is assigned to any theme face
        for ($i = 0; $i < gtemplate_get_face_count(); $i++) {
            $source = get_theme_mod(gtemplate_get_face_prefix() . "_{$i}_source", 'demo');
            $content_id = (int) get_theme_mod(gtemplate_get_face_prefix() . "_{$i}_content_id", 0);

            if (($source === 'page' || $source === 'post') && $content_id === $post_id) {
                gtemplate_increment_version('cell');
                gtemplate_increment_version('bundle');
                error_log("gTemplate: Cell version incremented (post {$post_id} updated on cell {$i})");
                break;
            }
        }
    }
}, 10, 3);

/**
 * Invalidate bundle cache when bundle is regenerated
 */
add_action('gtemplate_bundle_generated', function() {
    gtemplate_increment_version('bundle');
});

/**
 * REST API endpoint for version information
 */
add_action('rest_api_init', function() {
    register_rest_route(gtemplate_get_rest_namespace(), '/versions', [
        'methods' => 'GET',
        'callback' => 'gtemplate_rest_get_versions',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route(gtemplate_get_rest_namespace(), '/versions/(?P<group>[a-z]+)', [
        'methods' => 'POST',
        'callback' => 'gtemplate_rest_increment_version',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        },
        'args' => [
            'group' => [
                'type' => 'string',
                'required' => true,
                'validate_callback' => function($value) {
                    return in_array($value, ['core', 'face', 'api', 'manifest', 'bundle', 'seo', 'all']);
                }
            ]
        ]
    ]);
});

/**
 * REST endpoint callback for getting versions
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function gtemplate_rest_get_versions($request) {
    $versions = gtemplate_get_all_versions();
    $prefixes = [];

    foreach (array_keys($versions) as $group) {
        $prefixes[$group] = gtemplate_cache_prefix($group);
    }

    return new WP_REST_Response([
        'versions' => $versions,
        'prefixes' => $prefixes,
        'site_id' => gtemplate_get_site_id()
    ], 200);
}

/**
 * REST endpoint callback for incrementing version
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function gtemplate_rest_increment_version($request) {
    $group = $request->get_param('group');

    if ($group === 'all') {
        gtemplate_increment_all_versions();
        return new WP_REST_Response([
            'success' => true,
            'message' => 'All versions incremented',
            'versions' => gtemplate_get_all_versions()
        ], 200);
    }

    $new_version = gtemplate_increment_version($group);

    return new WP_REST_Response([
        'success' => true,
        'group' => $group,
        'version' => $new_version,
        'prefix' => gtemplate_cache_prefix($group)
    ], 200);
}

/**
 * Add version info to REST API response headers
 *
 * Useful for debugging and cache validation.
 */
add_filter('rest_post_dispatch', function($response, $server, $request) {
    // Only add to gtemplate namespace
    if (strpos($request->get_route(), '/gtemplate/') === 0) {
        $response->header('X-GTemplate-Version', gtemplate_get_version('api'));
        $response->header('X-GTemplate-Cache-Prefix', gtemplate_cache_prefix('api'));
    }

    return $response;
}, 10, 3);

/**
 * WP-CLI: Add cache version commands
 *
 * Usage:
 *   wp gtemplate version list          - Show all versions
 *   wp gtemplate version get <group>   - Get version for group
 *   wp gtemplate version bump <group>  - Increment version for group
 *   wp gtemplate version bump-all      - Increment all versions
 */
if (!class_exists('GTemplate_Version_CLI')) {
    class GTemplate_Version_CLI {
        /**
         * List all cache versions
         *
         * ## EXAMPLES
         *
         *     wp gtemplate version list
         */
        public function list($args, $assoc_args) {
            $versions = gtemplate_get_all_versions();

            if (empty($versions)) {
                WP_CLI::warning('VersionManager not initialized');
                return;
            }

            $table = [];
            foreach ($versions as $group => $version) {
                $table[] = [
                    'group' => $group,
                    'version' => $version,
                    'prefix' => gtemplate_cache_prefix($group)
                ];
            }

            WP_CLI\Utils\format_items('table', $table, ['group', 'version', 'prefix']);
        }

        /**
         * Get version for a specific group
         *
         * ## OPTIONS
         *
         * <group>
         * : Cache group name (core, face, api, manifest, bundle, seo)
         *
         * ## EXAMPLES
         *
         *     wp gtemplate version get face
         */
        public function get($args, $assoc_args) {
            $group = $args[0];
            $version = gtemplate_get_version($group);
            WP_CLI::success("Version for '{$group}': {$version}");
        }

        /**
         * Increment version for a specific group
         *
         * ## OPTIONS
         *
         * <group>
         * : Cache group name (core, face, api, manifest, bundle, seo)
         *
         * ## EXAMPLES
         *
         *     wp gtemplate version bump face
         */
        public function bump($args, $assoc_args) {
            $group = $args[0];
            $new_version = gtemplate_increment_version($group);
            WP_CLI::success("Version for '{$group}' incremented to: {$new_version}");
        }

        /**
         * Increment all cache versions
         *
         * ## EXAMPLES
         *
         *     wp gtemplate version bump-all
         */
        public function bump_all($args, $assoc_args) {
            gtemplate_increment_all_versions();
            WP_CLI::success('All cache versions incremented');

            // Show new versions
            $this->list($args, $assoc_args);
        }
    }
}

// Register CLI command after class is defined
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('gtemplate version', 'GTemplate_Version_CLI');
}
