<?php
/**
 * gNodeConfigLoader - High-performance configuration loader with caching
 *
 * Eliminates per-request YAML parsing overhead by caching configuration
 * in APCu (shared across workers) with automatic refresh on file changes.
 *
 * Performance:
 * - First request: ~2-5ms (YAML parse + cache store)
 * - Subsequent requests: ~0.01ms (APCu fetch)
 * - File change detection: ~0.1ms (stat() call)
 *
 * Cache invalidation:
 * - Automatic: File mtime change detected
 * - Manual: gNodeConfigLoader::invalidate()
 * - TTL-based: Configurable max age (default 300s)
 *
 * @package gTemplate
 * @since 1.2.0
 */

namespace gTemplate;

class gNodeConfigLoader
{
    /** @var array|null In-memory config cache (per-request) */
    private static ?array $config = null;

    /** @var int|null Cached file modification time */
    private static ?int $configMtime = null;

    /** @var string|null Path to loaded config file */
    private static ?string $configPath = null;

    /** @var int Cache TTL in seconds */
    private const CACHE_TTL = 300;

    /** @var string APCu cache key prefix */
    private const CACHE_PREFIX = 'gtemplate_config_';

    /**
     * Get configuration with intelligent caching
     *
     * Cache hierarchy:
     * 1. Static (in-memory, same request) - instant
     * 2. APCu (shared memory, cross-request) - ~0.01ms
     * 3. File (YAML parse) - ~2-5ms
     *
     * @param bool $forceRefresh Force reload from file
     * @return array Configuration array
     */
    public static function get(bool $forceRefresh = false): array
    {
        // Level 1: Static cache (same request)
        if (self::$config !== null && !$forceRefresh) {
            return self::$config;
        }

        $cacheKey = self::getCacheKey();

        // Level 2: APCu cache (cross-request)
        if (!$forceRefresh && function_exists('apcu_fetch')) {
            $cached = apcu_fetch($cacheKey, $success);
            if ($success && is_array($cached)) {
                // Validate cache freshness via file mtime
                if (self::isCacheFresh($cached)) {
                    self::$config = $cached['config'];
                    self::$configMtime = $cached['mtime'];
                    self::$configPath = $cached['path'];
                    return self::$config;
                }
            }
        }

        // Level 3: Load from file
        return self::loadFromFile();
    }

    /**
     * Load configuration from YAML file
     *
     * @return array Configuration array
     */
    private static function loadFromFile(): array
    {
        $locations = self::getConfigLocations();

        foreach ($locations as $configFile) {
            if (file_exists($configFile)) {
                $config = yaml_parse_file($configFile);

                if ($config !== false && is_array($config)) {
                    // Auto-fill defaults
                    $config = self::applyDefaults($config);

                    // Get file mtime for cache validation
                    $mtime = filemtime($configFile);

                    // Store in static cache
                    self::$config = $config;
                    self::$configMtime = $mtime;
                    self::$configPath = $configFile;

                    // Store in APCu cache
                    self::storeInCache($config, $configFile, $mtime);

                    return $config;
                }
            }
        }

        // Fallback: auto-detected config
        $config = self::getAutoDetectedConfig();
        self::$config = $config;

        return $config;
    }

    /**
     * Get config file search locations
     *
     * @return array List of paths to check
     */
    private static function getConfigLocations(): array
    {
        $locations = [];

        // 1. WordPress root (highest priority)
        if (defined('ABSPATH')) {
            $locations[] = ABSPATH . 'wp-config-geodineum.yaml';
        }

        // 2. Theme local override (gitignored)
        if (function_exists('get_template_directory')) {
            $themeDir = get_template_directory();
            $locations[] = $themeDir . '/registration.local.yaml';
            $locations[] = $themeDir . '/registration.yaml';
        }

        return $locations;
    }

    /**
     * Apply default values to config
     *
     * @param array $config Raw config from YAML
     * @return array Config with defaults applied
     */
    private static function applyDefaults(array $config): array
    {
        // Auto-fill site_id from domain
        if (empty($config['site_id'])) {
            $domain = function_exists('get_site_url')
                ? parse_url(get_site_url(), PHP_URL_HOST)
                : ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $config['site_id'] = str_replace(['.', '-'], '_', $domain);
        }

        // Auto-fill ValKey user
        if (empty($config['valkey']['user'])) {
            $config['valkey']['user'] = 'gnode_client_' . $config['site_id'];
        }

        // Auto-fill ValKey password file
        if (empty($config['valkey']['password_file'])) {
            $config['valkey']['password_file'] = '/opt/gNode/.gnode/valkey_client_' . $config['site_id'] . '.password';
        }

        return $config;
    }

    /**
     * Get auto-detected config when no file exists
     *
     * @return array Minimal working config
     */
    private static function getAutoDetectedConfig(): array
    {
        $domain = function_exists('get_site_url')
            ? parse_url(get_site_url(), PHP_URL_HOST)
            : ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $siteId = str_replace(['.', '-'], '_', $domain);

        $themeName = function_exists('wp_get_theme')
            ? wp_get_theme()->get('Name')
            : 'gTemplate';

        return [
            'version' => '1.0.0',
            'site_id' => $siteId,
            'service' => [
                'type' => 'wordpress-site',
                'tier' => 'service',
                'update_mode' => 'upsert',
            ],
            'capabilities' => [
                'wordpress-site' => 1.0,
                'gnode-integrated' => 1.0,
            ],
            'metadata' => [
                'type' => 'wordpress-site',
                'theme' => $themeName,
                'environment' => defined('WP_ENVIRONMENT_TYPE') ? WP_ENVIRONMENT_TYPE : 'production',
                'domain' => function_exists('get_site_url') ? get_site_url() : '',
            ],
            'valkey' => [
                'user' => 'gnode_client_' . $siteId,
                'password_file' => '/opt/gNode/.gnode/valkey_client_' . $siteId . '.password',
            ],
            '_auto_detected' => true,
        ];
    }

    /**
     * Check if cached config is still fresh
     *
     * @param array $cached Cached data with mtime
     * @return bool True if cache is fresh
     */
    private static function isCacheFresh(array $cached): bool
    {
        // Check TTL
        if (isset($cached['cached_at'])) {
            $age = time() - $cached['cached_at'];
            if ($age > self::CACHE_TTL) {
                return false;
            }
        }

        // Check file mtime (fast stat() call)
        if (isset($cached['path']) && isset($cached['mtime'])) {
            $currentMtime = @filemtime($cached['path']);
            if ($currentMtime !== false && $currentMtime !== $cached['mtime']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Store config in APCu cache
     *
     * @param array $config Config to cache
     * @param string $path Config file path
     * @param int $mtime File modification time
     */
    private static function storeInCache(array $config, string $path, int $mtime): void
    {
        if (!function_exists('apcu_store')) {
            return;
        }

        $cacheData = [
            'config' => $config,
            'path' => $path,
            'mtime' => $mtime,
            'cached_at' => time(),
        ];

        apcu_store(self::getCacheKey(), $cacheData, self::CACHE_TTL);
    }

    /**
     * Get unique cache key for this WordPress installation
     *
     * @return string Cache key
     */
    private static function getCacheKey(): string
    {
        $identifier = defined('ABSPATH') ? ABSPATH : __DIR__;
        return self::CACHE_PREFIX . md5($identifier);
    }

    /**
     * Invalidate cached configuration
     *
     * Call this after config file changes (e.g., from admin UI)
     */
    public static function invalidate(): void
    {
        self::$config = null;
        self::$configMtime = null;
        self::$configPath = null;

        if (function_exists('apcu_delete')) {
            apcu_delete(self::getCacheKey());
        }
    }

    /**
     * Get specific config value by dot-notation path
     *
     * @param string $path Dot-notation path (e.g., 'valkey.user')
     * @param mixed $default Default value if not found
     * @return mixed Config value or default
     */
    public static function getValue(string $path, $default = null)
    {
        $config = self::get();
        $keys = explode('.', $path);

        foreach ($keys as $key) {
            if (!is_array($config) || !array_key_exists($key, $config)) {
                return $default;
            }
            $config = $config[$key];
        }

        return $config;
    }

    /**
     * Get site_id (common accessor)
     *
     * @return string Site identifier
     */
    public static function getSiteId(): string
    {
        return self::getValue('site_id', 'unknown');
    }

    /**
     * Get environment (common accessor)
     *
     * @return string Environment (testing|staging|acceptance|production)
     */
    public static function getEnvironment(): string
    {
        return self::getValue('metadata.environment', 'production');
    }

    /**
     * Get ValKey credentials
     *
     * @return array{user: string, password: string|null}
     */
    public static function getValKeyCredentials(): array
    {
        $config = self::get();
        $user = $config['valkey']['user'] ?? 'default';
        $passwordFile = $config['valkey']['password_file'] ?? '';

        $password = null;
        if ($passwordFile && file_exists($passwordFile)) {
            $password = trim(file_get_contents($passwordFile));
        }

        return [
            'user' => $user,
            'password' => $password,
        ];
    }

    /**
     * Get cache statistics for debugging
     *
     * @return array Cache stats
     */
    public static function getStats(): array
    {
        $stats = [
            'static_cached' => self::$config !== null,
            'config_path' => self::$configPath,
            'config_mtime' => self::$configMtime,
            'apcu_available' => function_exists('apcu_fetch'),
        ];

        if (function_exists('apcu_fetch')) {
            $cached = apcu_fetch(self::getCacheKey(), $success);
            $stats['apcu_hit'] = $success;
            if ($success && isset($cached['cached_at'])) {
                $stats['apcu_age_seconds'] = time() - $cached['cached_at'];
            }
        }

        return $stats;
    }
}
