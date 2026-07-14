<?php
declare(strict_types=1);
/**
 * Runtime Configuration Integration for gTemplate
 *
 * Provides runtime configuration management via ValKey Lua functions.
 * Enables configuration changes without PHP restart.
 *
 * Key Features:
 * - Read/write config via gNode_CONFIG_* Lua functions
 * - Automatic fallback chain (site -> global -> defaults)
 * - Config seeding from YAML files
 * - WP-CLI integration for config management
 *
 * ValKey Key Schema:
 *   {site_id}:config:{category} -> Hash with key-value pairs
 *   {default}:config:{category} -> Global defaults
 *
 * Categories:
 *   - ratelimit: API rate limiting settings
 *   - cache: TTL and caching settings
 *   - security: Security flags and rules
 *   - features: Feature toggles
 *
 * @package gTemplate
 * @since 1.0.0
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get a runtime config value
 *
 * Uses GNODE_CONFIG_GET Lua function with automatic fallback:
 * 1. Site-specific config in ValKey
 * 2. Global defaults in ValKey
 * 3. Baked-in Lua defaults
 * 4. Provided PHP default
 *
 * @param string $category Config category (ratelimit, cache, security, features)
 * @param string $key Config key
 * @param mixed $default Default value if not found
 * @return string|null Config value
 */
function gtemplate_config_get(string $category, string $key, $default = null): ?string {
    $storage = $GLOBALS['gtemplate_gnode_storage'] ?? null;

    if (!$storage) {
        return $default;
    }

    try {
        $site_id = gtemplate_get_site_id();

        // Call Lua function: GNODE_CONFIG_GET site_id category key [default]
        $args = [$site_id, $category, $key];
        if ($default !== null) {
            $args[] = (string) $default;
        }

        $result = $storage->fcall('GNODE_CONFIG_GET', [], $args);

        return $result !== null ? (string) $result : $default;

    } catch (\Throwable $e) {
        gtemplate_track_error('gTemplate: Config get error: ' . $e->getMessage());
        return $default;
    }
}

/**
 * Get a runtime config value as integer
 *
 * @param string $category Config category
 * @param string $key Config key
 * @param int $default Default value
 * @return int Config value
 */
function gtemplate_config_get_int(string $category, string $key, int $default = 0): int {
    $storage = $GLOBALS['gtemplate_gnode_storage'] ?? null;

    if (!$storage) {
        return $default;
    }

    try {
        $site_id = gtemplate_get_site_id();

        $result = $storage->fcall('GNODE_CONFIG_GET_INT', [], [
            $site_id, $category, $key, (string) $default
        ]);

        return (int) $result;

    } catch (\Throwable $e) {
        gtemplate_track_error('gTemplate: Config get_int error: ' . $e->getMessage());
        return $default;
    }
}

/**
 * Get a runtime config value as boolean
 *
 * @param string $category Config category
 * @param string $key Config key
 * @param bool $default Default value
 * @return bool Config value
 */
function gtemplate_config_get_bool(string $category, string $key, bool $default = false): bool {
    $value = gtemplate_config_get($category, $key, $default ? '1' : '0');
    return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
}

/**
 * Set a runtime config value
 *
 * @param string $category Config category
 * @param string $key Config key
 * @param mixed $value Value to set
 * @return bool Success
 */
function gtemplate_config_set(string $category, string $key, $value): bool {
    $storage = $GLOBALS['gtemplate_gnode_storage'] ?? null;

    if (!$storage) {
        return false;
    }

    try {
        $site_id = gtemplate_get_site_id();

        $result = $storage->fcall('GNODE_CONFIG_SET', [], [
            $site_id, $category, $key, (string) $value
        ]);

        // Invalidate any cached config
        \gTemplate\gNodeConfigLoader::invalidate();

        return $result === 1;

    } catch (\Throwable $e) {
        gtemplate_track_error('gTemplate: Config set error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Set multiple config values at once
 *
 * @param string $category Config category
 * @param array $values Associative array of key => value
 * @return int Number of values set
 */
function gtemplate_config_mset(string $category, array $values): int {
    $storage = $GLOBALS['gtemplate_gnode_storage'] ?? null;

    if (!$storage || empty($values)) {
        return 0;
    }

    try {
        $site_id = gtemplate_get_site_id();

        // Build args: site_id, category, key1, val1, key2, val2, ...
        $args = [$site_id, $category];
        foreach ($values as $key => $value) {
            $args[] = (string) $key;
            $args[] = (string) $value;
        }

        $result = $storage->fcall('GNODE_CONFIG_MSET', [], $args);

        \gTemplate\gNodeConfigLoader::invalidate();

        return (int) $result;

    } catch (\Throwable $e) {
        gtemplate_track_error('gTemplate: Config mset error: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Get all config values for a category (merged with defaults)
 *
 * @param string $category Config category
 * @return array Associative array of key => value
 */
function gtemplate_config_get_all(string $category): array {
    $storage = $GLOBALS['gtemplate_gnode_storage'] ?? null;

    if (!$storage) {
        return [];
    }

    try {
        $site_id = gtemplate_get_site_id();

        $result = $storage->fcall('GNODE_CONFIG_HGETALL', [], [$site_id, $category]);

        // Convert flat array [key, value, key, value, ...] to associative
        $config = [];
        if (is_array($result)) {
            for ($i = 0; $i < count($result); $i += 2) {
                if (isset($result[$i]) && isset($result[$i + 1])) {
                    $config[$result[$i]] = $result[$i + 1];
                }
            }
        }

        return $config;

    } catch (\Throwable $e) {
        gtemplate_track_error('gTemplate: Config get_all error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Delete a config key (reverts to default)
 *
 * @param string $category Config category
 * @param string $key Config key
 * @return bool Success
 */
function gtemplate_config_delete(string $category, string $key): bool {
    $storage = $GLOBALS['gtemplate_gnode_storage'] ?? null;

    if (!$storage) {
        return false;
    }

    try {
        $site_id = gtemplate_get_site_id();

        $result = $storage->fcall('GNODE_CONFIG_DELETE', [], [$site_id, $category, $key]);

        \gTemplate\gNodeConfigLoader::invalidate();

        return $result >= 0;

    } catch (\Throwable $e) {
        gtemplate_track_error('gTemplate: Config delete error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Reset a category to defaults (delete all site-specific overrides)
 *
 * @param string $category Config category
 * @return bool Success
 */
function gtemplate_config_reset(string $category): bool {
    $storage = $GLOBALS['gtemplate_gnode_storage'] ?? null;

    if (!$storage) {
        return false;
    }

    try {
        $site_id = gtemplate_get_site_id();

        $result = $storage->fcall('GNODE_CONFIG_RESET', [], [$site_id, $category]);

        \gTemplate\gNodeConfigLoader::invalidate();

        return $result === 1;

    } catch (\Throwable $e) {
        gtemplate_track_error('gTemplate: Config reset error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Export all config as JSON
 *
 * @return string|null JSON string or null on error
 */
function gtemplate_config_export(): ?string {
    $storage = $GLOBALS['gtemplate_gnode_storage'] ?? null;

    if (!$storage) {
        return null;
    }

    try {
        $site_id = gtemplate_get_site_id();
        return $storage->fcall('GNODE_CONFIG_EXPORT', [], [$site_id]);
    } catch (\Throwable $e) {
        gtemplate_track_error('gTemplate: Config export error: ' . $e->getMessage());
        return null;
    }
}

/**
 * List available config categories
 *
 * @return array Array of category names
 */
function gtemplate_config_list_categories(): array {
    $storage = $GLOBALS['gtemplate_gnode_storage'] ?? null;

    if (!$storage) {
        return ['ratelimit', 'cache', 'security', 'features']; // Hardcoded fallback
    }

    try {
        $result = $storage->fcall('GNODE_CONFIG_LIST_CATEGORIES', [], []);
        return is_array($result) ? $result : [];
    } catch (\Throwable $e) {
        gtemplate_track_error('[gTemplate config] GNODE_CONFIG_LIST_CATEGORIES failed (using hardcoded fallback): ' . $e->getMessage());
        return ['ratelimit', 'cache', 'security', 'features'];
    }
}

/**
 * Get default values for a category
 *
 * @param string $category Config category
 * @return array Associative array of default key => value
 */
function gtemplate_config_get_defaults(string $category): array {
    $storage = $GLOBALS['gtemplate_gnode_storage'] ?? null;

    if (!$storage) {
        return [];
    }

    try {
        $result = $storage->fcall('GNODE_CONFIG_GET_DEFAULTS', [], [$category]);

        // Convert flat array to associative
        $defaults = [];
        if (is_array($result)) {
            for ($i = 0; $i < count($result); $i += 2) {
                if (isset($result[$i]) && isset($result[$i + 1])) {
                    $defaults[$result[$i]] = $result[$i + 1];
                }
            }
        }

        return $defaults;

    } catch (\Throwable $e) {
        gtemplate_track_error('gTemplate: Config get_defaults error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Seed config from YAML files
 *
 * Reads manager YAML configs and seeds them into ValKey.
 * Called during registration or via WP-CLI.
 *
 * @param bool $force Force overwrite existing values
 * @return array Results per category
 */
function gtemplate_config_seed(bool $force = false): array {
    $storage = $GLOBALS['gtemplate_gnode_storage'] ?? null;
    $results = [];

    if (!$storage) {
        return ['error' => 'ValKey storage not available'];
    }

    $site_id = gtemplate_get_site_id();
    $config_dir = get_template_directory() . '/config/managers';

    // Map YAML files to config categories
    $mappings = [
        'SecurityManager.yaml' => [
            'category' => 'security',
            'extract' => function($yaml) {
                $config = $yaml['config'] ?? [];
                return [
                    'debug' => ($config['debug'] ?? false) ? '1' : '0',
                    'rate_limiting_enabled' => ($config['firewall']['rate_limiting'] ?? true) ? '1' : '0',
                    'firewall_enabled' => ($config['firewall']['enabled'] ?? true) ? '1' : '0',
                    'audit_enabled' => ($config['audit']['enabled'] ?? true) ? '1' : '0',
                    'audit_level' => $config['audit']['log_level'] ?? 'detailed',
                ];
            }
        ],
        'CacheManager.yaml' => [
            'category' => 'cache',
            'extract' => function($yaml) {
                $config = $yaml['config'] ?? [];
                $groups = $config['cache_groups'] ?? [];
                return [
                    'default_ttl' => (string) ($config['default_ttl'] ?? 3600),
                    'page_ttl' => (string) ($groups['page'] ?? 3600),
                    'fragment_ttl' => (string) ($groups['fragment'] ?? 1800),
                    'template_ttl' => (string) ($groups['template'] ?? 7200),
                    'api_ttl' => (string) ($groups['api'] ?? 300),
                    'gnode_ttl' => (string) ($groups['gnode'] ?? 600),
                    'cube_face_ttl' => (string) ($groups['cube_face'] ?? 3600),
                ];
            }
        ],
    ];

    try {
        foreach ($mappings as $yaml_file => $mapping) {
            $file_path = $config_dir . '/' . $yaml_file;
            $category = $mapping['category'];

            if (!file_exists($file_path)) {
                $results[$category] = ['status' => 'skipped', 'reason' => 'file not found'];
                continue;
            }

            // Check if already seeded (unless forced)
            if (!$force) {
                $existing = gtemplate_config_get($category, '_seeded_at');
                if ($existing) {
                    $results[$category] = ['status' => 'skipped', 'reason' => 'already seeded'];
                    continue;
                }
            }

            $yaml = yaml_parse_file($file_path);
            if (!$yaml) {
                $results[$category] = ['status' => 'error', 'reason' => 'YAML parse failed'];
                continue;
            }

            // Extract config values
            $values = $mapping['extract']($yaml);

            // Seed to ValKey
            $count = gtemplate_config_mset($category, $values);

            $results[$category] = [
                'status' => 'seeded',
                'count' => $count,
                'values' => $values
            ];
        }

        // Also seed feature flags from registration.yaml
        $reg_config = \gTemplate\gNodeConfigLoader::get();
        if (!empty($reg_config['capabilities'])) {
            $features = [
                'pwa_enabled' => isset($reg_config['capabilities']['pwa-enabled']) ? '1' : '0',
                'tera_templates' => isset($reg_config['capabilities']['tera-templates']) ? '1' : '0',
                'htmx_progressive' => isset($reg_config['capabilities']['htmx-progressive']) ? '1' : '0',
                'gpu_accelerated' => isset($reg_config['capabilities']['gpu-accelerated']) ? '1' : '0',
            ];

            $count = gtemplate_config_mset('features', $features);
            $results['features'] = [
                'status' => 'seeded',
                'count' => $count,
                'source' => 'registration.yaml capabilities'
            ];
        }

        \gTemplate\gNodeConfigLoader::invalidate();

        return $results;

    } catch (\Throwable $e) {
        gtemplate_track_error('gTemplate: Config seed error: ' . $e->getMessage());
        return ['error' => $e->getMessage()];
    }
}

/**
 * REST API endpoint for config management (admin only)
 */
add_action('rest_api_init', function() {
    // GET /wp-json/gtemplate/v1/config/{category}
    register_rest_route(gtemplate_get_rest_namespace(), '/config/(?P<category>[a-z]+)', [
        'methods' => 'GET',
        'callback' => 'gtemplate_rest_get_config',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        },
        'args' => [
            'category' => [
                'type' => 'string',
                'required' => true,
                'validate_callback' => function($value) {
                    return in_array($value, gtemplate_config_list_categories());
                }
            ]
        ]
    ]);

    // POST /wp-json/gtemplate/v1/config/{category}
    register_rest_route(gtemplate_get_rest_namespace(), '/config/(?P<category>[a-z]+)', [
        'methods' => 'POST',
        'callback' => 'gtemplate_rest_set_config',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        },
        'args' => [
            'category' => [
                'type' => 'string',
                'required' => true
            ]
        ]
    ]);

    // GET /wp-json/gtemplate/v1/config
    register_rest_route(gtemplate_get_rest_namespace(), '/config', [
        'methods' => 'GET',
        'callback' => 'gtemplate_rest_export_config',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ]);

    // POST /wp-json/gtemplate/v1/config/seed
    register_rest_route(gtemplate_get_rest_namespace(), '/config/seed', [
        'methods' => 'POST',
        'callback' => 'gtemplate_rest_seed_config',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        },
        'args' => [
            'force' => [
                'type' => 'boolean',
                'default' => false
            ]
        ]
    ]);
});

/**
 * REST callback: Get config for category
 */
function gtemplate_rest_get_config($request) {
    $category = $request->get_param('category');
    $config = gtemplate_config_get_all($category);
    $defaults = gtemplate_config_get_defaults($category);

    return new WP_REST_Response([
        'category' => $category,
        'config' => $config,
        'defaults' => $defaults,
        'site_id' => gtemplate_get_site_id()
    ], 200);
}

/**
 * REST callback: Set config values
 */
function gtemplate_rest_set_config($request) {
    $category = $request->get_param('category');
    $body = $request->get_json_params();

    if (empty($body) || !is_array($body)) {
        return new WP_REST_Response([
            'error' => 'Request body must be a JSON object with key-value pairs'
        ], 400);
    }

    // Filter out category from body if present
    unset($body['category']);

    $count = gtemplate_config_mset($category, $body);

    return new WP_REST_Response([
        'success' => $count > 0,
        'category' => $category,
        'updated' => $count,
        'config' => gtemplate_config_get_all($category)
    ], 200);
}

/**
 * REST callback: Export all config
 */
function gtemplate_rest_export_config($request) {
    $json = gtemplate_config_export();

    if ($json === null) {
        return new WP_REST_Response([
            'error' => 'Failed to export config'
        ], 500);
    }

    $config = json_decode($json, true);

    return new WP_REST_Response([
        'site_id' => gtemplate_get_site_id(),
        'config' => $config,
        'categories' => gtemplate_config_list_categories()
    ], 200);
}

/**
 * REST callback: Seed config from YAML
 */
function gtemplate_rest_seed_config($request) {
    $force = (bool) $request->get_param('force');
    $results = gtemplate_config_seed($force);

    return new WP_REST_Response([
        'success' => !isset($results['error']),
        'results' => $results
    ], isset($results['error']) ? 500 : 200);
}

/**
 * Convenience functions for common config access patterns
 */

/**
 * Get rate limit config
 */
function gtemplate_get_rate_limit_config(): array {
    return gtemplate_config_get_all('ratelimit');
}

/**
 * Check if rate limiting is enabled
 */
function gtemplate_is_rate_limiting_enabled(): bool {
    return gtemplate_config_get_bool('ratelimit', 'enabled', true);
}

/**
 * Get API rate limit value
 */
function gtemplate_get_api_rate_limit(): int {
    return gtemplate_config_get_int('ratelimit', 'api_limit', 100);
}

/**
 * Get cache TTL for a group
 */
function gtemplate_get_cache_ttl(string $group = 'default'): int {
    $key = $group === 'default' ? 'default_ttl' : $group . '_ttl';
    return gtemplate_config_get_int('cache', $key, 3600);
}

/**
 * Check if a feature is enabled
 */
function gtemplate_is_feature_enabled(string $feature): bool {
    return gtemplate_config_get_bool('features', $feature . '_enabled', true);
}

/**
 * Check if debug mode is enabled
 */
function gtemplate_is_debug_enabled(): bool {
    return gtemplate_config_get_bool('security', 'debug', false);
}
