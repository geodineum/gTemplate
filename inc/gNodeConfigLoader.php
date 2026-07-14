<?php
declare(strict_types=1);
/**
 * gNodeConfigLoader - Constellation-aware configuration loader
 *
 * Four-tier caching with distributed ValKey support:
 *   Tier 1: Static (per-request in-memory) — instant
 *   Tier 2: APCu (cross-request shared memory) + constellation generation check — ~0.01ms
 *   Tier 3: ValKey (distributed, cross-server) — ~0.1ms
 *   Tier 4: YAML file parsing — ~2-5ms
 *
 * The "constellation generation" counter in ValKey enables instant cache
 * invalidation without TTL expiry when config changes on any node.
 *
 * Lower tiers backfill upper tiers on success (YAML → ValKey + APCu).
 * ValKey failure is always silent — falls through to YAML.
 *
 * This class lives in gTemplate (parent theme). Child themes (gCube,
 * custom) use it via namespace alias — no duplication.
 *
 * @package gTemplate
 * @since 2.0.0
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

    /** @var \Redis|null|false Bootstrap ValKey connection (null=untried, false=failed) */
    private static $bootstrapValKey = null;

    /** @var int|null Cached constellation generation (per-request) */
    private static ?int $cachedGeneration = null;

    /** @var int Cache TTL in seconds */
    private const CACHE_TTL = 300;

    /** @var string APCu cache key prefix */
    private const CACHE_PREFIX = 'gtemplate_config_';

    /** ValKey connection timeout (fail fast) */
    private const VALKEY_TIMEOUT = 0.5;

    /** ValKey read timeout */
    private const VALKEY_READ_TIMEOUT = 0.5;

    /** Persistent connection ID for FPM worker reuse */
    private const VALKEY_PERSISTENT_ID = 'gtemplate_config';

    /**
     * Get configuration with four-tier caching
     *
     * Cache hierarchy:
     * 1. Static (in-memory, same request) — instant
     * 2. APCu (shared memory, cross-request) + constellation generation check — ~0.01ms
     * 3. ValKey (distributed, cross-server) — ~0.1ms
     * 4. YAML file parsing — ~2-5ms
     *
     * @param bool $forceRefresh Force reload from all caches
     * @return array Configuration array
     */
    public static function get(bool $forceRefresh = false): array
    {
        // Tier 1: Static cache (same request)
        if (self::$config !== null && !$forceRefresh) {
            return self::$config;
        }

        $cacheKey = self::getCacheKey();

        // Tier 2: APCu cache + constellation generation freshness
        if (!$forceRefresh && function_exists('apcu_fetch')) {
            $cached = apcu_fetch($cacheKey, $success);
            if ($success && is_array($cached) && self::isGenerationFresh($cached) && self::isCacheFresh($cached)) {
                self::$config = $cached['config'];
                self::$configMtime = $cached['mtime'] ?? null;
                self::$configPath = $cached['path'] ?? null;
                return self::$config;
            }
        }

        // Tier 3: ValKey (distributed cache — enables constellation-wide config)
        $siteId = self::deriveSiteId();
        $config = self::loadFromValKey($siteId);
        if ($config !== null) {
            self::$config = $config;
            self::storeInCache($config, self::$configPath ?? '', self::$configMtime ?? 0);
            return $config;
        }

        // Tier 4: Load from YAML file
        $config = self::loadFromFile();

        // Backfill ValKey for other constellation nodes
        self::storeToValKey($config, $siteId);

        return $config;
    }

    /**
     * Load configuration from YAML file.
     *
     * YAML parse + env-var resolution is delegated to
     * gCore\Modules\Core\Utils\ConfigLoader (its `load()` with
     * `useCache=false` runs Tier-4-only — parse + resolveEnvVars —
     * skipping the compiled-section cache tiers that don't apply to
     * arbitrary files like wp-config-geodineum.yaml). When gCore is
     * unreachable (free-tier installs, bootstrap-fatal scenarios) the
     * helper falls back to a direct yaml_parse_file call so the loader
     * keeps working without the framework. ROADMAP §B.1 partial
     * closure — the constellation-aware APCu+ValKey caching machinery
     * below remains parallel to gCore ConfigLoader's compiled-section
     * cache; full unification (extracting a shared ConfigCache
     * primitive that both loaders share) is the §B.1.b post-launch
     * follow-up.
     *
     * @return array Configuration array
     */
    private static function loadFromFile(): array
    {
        static $coreLoader = null;
        if ($coreLoader === null) {
            $coreLoaderClass = '\\gCore\\Modules\\Core\\Utils\\ConfigLoader';
            if (class_exists($coreLoaderClass)) {
                try {
                    $coreLoader = new $coreLoaderClass();
                } catch (\Throwable $_) {
                    $coreLoader = false;
                }
            } else {
                $coreLoader = false;
            }
        }

        foreach (self::getConfigLocations() as $configFile) {
            if (!file_exists($configFile)) {
                continue;
            }

            $config = null;
            if ($coreLoader && is_object($coreLoader) && method_exists($coreLoader, 'load')) {
                try {
                    // useCache=false → Tier-4-only path: yaml_parse_file +
                    // resolveEnvVars. We run our own caching above this
                    // call (constellation-aware APCu+ValKey).
                    $config = $coreLoader->load($configFile, null, false);
                } catch (\Throwable $e) {
                    gtemplate_track_error(
                        '[gTemplate gNodeConfigLoader] gCore ConfigLoader load failed for ' . $configFile . ': ' . $e->getMessage(),
                        ['file' => $configFile],
                        'WARNING'
                    );
                }
            }
            if (!is_array($config) && function_exists('yaml_parse_file')) {
                $parsed = @yaml_parse_file($configFile);
                if (is_array($parsed)) {
                    $config = $parsed;
                }
            }

            if (is_array($config)) {
                $config = self::applyDefaults($config);
                $mtime = (int) (@filemtime($configFile) ?: 0);

                self::$config = $config;
                self::$configMtime = $mtime;
                self::$configPath = $configFile;

                self::storeInCache($config, $configFile, $mtime);
                return $config;
            }
        }

        // Fallback: auto-detected config (no file found at any search location).
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

        // Auto-fill ValKey password file (centralized FHS path)
        if (empty($config['valkey']['password_file'])) {
            $config['valkey']['password_file'] = '/etc/geodineum/credentials/valkey_client_' . $config['site_id'] . '.password';
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
                'environment' => defined('WP_ENVIRONMENT_TYPE') ? WP_ENVIRONMENT_TYPE : 'testing',
                'domain' => function_exists('get_site_url') ? get_site_url() : '',
            ],
            'valkey' => [
                'user' => 'gnode_client_' . $siteId,
                'password_file' => '/etc/geodineum/credentials/valkey_client_' . $siteId . '.password',
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
     * Store config in APCu cache with constellation generation tag
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
            '_constellation_gen' => self::getConstellationGeneration() ?? 0,
        ];

        apcu_store(self::getCacheKey(), $cacheData, self::CACHE_TTL);
    }

    /**
     * Check if APCu entry's constellation generation matches current
     *
     * @param array $cached Cached entry
     * @return bool True if fresh (generation matches or ValKey unavailable)
     */
    private static function isGenerationFresh(array $cached): bool
    {
        if (!isset($cached['_constellation_gen'])) {
            return false; // Old format — re-store with generation
        }

        $currentGen = self::getConstellationGeneration();
        if ($currentGen === null) {
            return true; // ValKey unavailable — trust APCu TTL
        }

        return $currentGen === $cached['_constellation_gen'];
    }

    // =========================================================================
    // Tier 3: ValKey distributed cache (Geodineum Constellation)
    // =========================================================================

    /**
     * Derive site ID from domain or env var (no config dependency)
     *
     * @return string Site identifier
     */
    private static function deriveSiteId(): string
    {
        $envSiteId = getenv('GCORE_SITE_ID');
        if ($envSiteId !== false && $envSiteId !== '') {
            return $envSiteId;
        }

        $domain = function_exists('get_site_url')
            ? parse_url(get_site_url(), PHP_URL_HOST)
            : ($_SERVER['HTTP_HOST'] ?? 'localhost');

        return str_replace(['.', '-'], '_', $domain);
    }

    /**
     * Get a bootstrap ValKey connection
     *
     * Uses ONLY env vars + credential files — NOT the config being loaded.
     *
     * @return \Redis|null Connection or null on failure
     */
    private static function getBootstrapValKey(): ?\Redis
    {
        if (self::$bootstrapValKey === false) {
            return null;
        }
        if (self::$bootstrapValKey instanceof \Redis) {
            return self::$bootstrapValKey;
        }
        if (!extension_loaded('redis')) {
            self::$bootstrapValKey = false;
            return null;
        }

        try {
            $host = getenv('VALKEY_HOST') ?: '127.0.0.1';
            $port = (int)(getenv('VALKEY_PORT') ?: 47445);
            $siteId = self::deriveSiteId();

            // Credential resolution: centralized → standard → legacy
            $credDirs = [
                '/etc/geodineum/credentials',
                '/opt/geodineum/gNode/.gnode',
                '/opt/gNode/.gnode',
            ];
            $password = null;
            foreach ($credDirs as $dir) {
                $file = $dir . '/valkey_client_' . $siteId . '.password';
                if (file_exists($file) && is_readable($file)) {
                    $password = trim(file_get_contents($file));
                    break;
                }
            }
            if ($password === null || $password === '') {
                self::$bootstrapValKey = false;
                return null;
            }

            $redis = new \Redis();
            if (!$redis->pconnect($host, $port, self::VALKEY_TIMEOUT, self::VALKEY_PERSISTENT_ID)) {
                self::$bootstrapValKey = false;
                return null;
            }

            $redis->setOption(\Redis::OPT_READ_TIMEOUT, self::VALKEY_READ_TIMEOUT);

            if (!$redis->auth(['gnode_client_' . $siteId, $password])) {
                self::$bootstrapValKey = false;
                return null;
            }

            self::$bootstrapValKey = $redis;
            return $redis;
        } catch (\Throwable $e) {
            gtemplate_track_error('[gTemplate gNodeConfigLoader] bootstrap ValKey connection failed: ' . $e->getMessage());
            self::$bootstrapValKey = false;
            return null;
        }
    }

    /**
     * Load site config from ValKey
     *
     * Key: {site_id}:constellation:site_config
     *
     * @param string $siteId Site identifier
     * @return array|null Config array or null
     */
    private static function loadFromValKey(string $siteId): ?array
    {
        $redis = self::getBootstrapValKey();
        if ($redis === null) {
            return null;
        }

        try {
            // Hash-tagged for cluster co-location with sibling per-site
            // keys (Ch.1.1 §C.6 follow-up: matches `{site_id}:constellation:generation`
            // at line 475 + ecosystem convention).
            $data = $redis->get('{' . $siteId . '}:constellation:site_config');
            if ($data === false || $data === null) {
                return null;
            }
            $config = json_decode($data, true);
            return is_array($config) ? $config : null;
        } catch (\Throwable $e) {
            gtemplate_track_error('[gTemplate gNodeConfigLoader] loadFromValKey failed for site=' . $siteId . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Store site config to ValKey (backfill from YAML)
     *
     * Seeds ValKey for other constellation nodes.
     *
     * @param array $config Config data
     * @param string $siteId Site identifier
     */
    private static function storeToValKey(array $config, string $siteId): void
    {
        $redis = self::getBootstrapValKey();
        if ($redis === null) {
            return;
        }

        try {
            $json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json !== false) {
                // Hash-tagged for cluster co-location (matches read at L420
                // + sibling `{site_id}:constellation:generation`).
                $redis->set('{' . $siteId . '}:constellation:site_config', $json);
            }
        } catch (\Throwable $e) {
            // Silent — ValKey is optional
        }
    }

    /**
     * Get constellation generation from ValKey (cached per-request)
     *
     * @return int|null Current generation or null if ValKey unavailable
     */
    private static function getConstellationGeneration(): ?int
    {
        if (self::$cachedGeneration !== null) {
            return self::$cachedGeneration;
        }

        $redis = self::getBootstrapValKey();
        if ($redis === null) {
            return null;
        }

        try {
            $siteId = self::deriveSiteId();
            $gen = $redis->get('{' . $siteId . '}:constellation:generation');
            self::$cachedGeneration = ($gen !== false && $gen !== null) ? (int)$gen : 0;
            return self::$cachedGeneration;
        } catch (\Throwable $e) {
            gtemplate_track_error('[gTemplate gNodeConfigLoader] getConstellationGeneration failed for site=' . $siteId . ': ' . $e->getMessage());
            return null;
        }
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
        self::$cachedGeneration = null;
        self::$bootstrapValKey = null;

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
            'valkey_available' => self::getBootstrapValKey() !== null,
            'constellation_generation' => self::getConstellationGeneration(),
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
