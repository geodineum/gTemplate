<?php
declare(strict_types=1);
/**
 * gTemplate Smart Site Registration
 *
 * Handles idempotent service registration with gNode topology.
 * Uses direct ValKey writes to bypass stream protocol timeouts.
 *
 * @package gTemplate
 * @version 1.0.0
 */

namespace gTemplate;

/**
 * Load registration config with ValKey-first strategy
 *
 * Priority order:
 * 1. ValKey cache (fastest, ~1ms)
 * 2. YAML files (slower, ~10-50ms for file I/O + parsing)
 * 3. Auto-detection fallback
 *
 * When loading from YAML, config is automatically synced to ValKey.
 *
 * @param bool $force_yaml Force loading from YAML (bypass ValKey)
 * @return array|null Registration config or null on error
 */
function load_registration_config(bool $force_yaml = false): ?array
{
    // Determine site_id first (needed for ValKey key)
    $site_id = get_site_id_from_domain();

    // Try ValKey first (unless forced to use YAML)
    if (!$force_yaml) {
        $config = load_config_from_valkey($site_id);
        if ($config) {
            return $config;
        }
    }

    // Fall back to YAML files
    $config = load_config_from_yaml();

    if ($config) {
        // Auto-sync to ValKey for next request
        sync_config_to_valkey_async($config);
    }

    return $config;
}

/**
 * Get site_id from domain (fast, no I/O)
 */
function get_site_id_from_domain(): string
{
    $domain = parse_url(get_site_url(), PHP_URL_HOST);
    return str_replace(['.', '-'], '_', $domain);
}

/**
 * Try to load config from ValKey (fast path)
 *
 * Uses gCore's gNode-Client for all ValKey access.
 * Architecture: gTemplate → gCore → gNode-Client → ValKey
 *
 * @param string $site_id Site identifier
 * @return array|null Config or null if not in ValKey
 */
function load_config_from_valkey(string $site_id): ?array
{
    global $gCore;

    try {
        // Get gNode-Client from gCore (NEVER create directly!)
        if (!$gCore || !$gCore->hasService('gnode_client')) {
            return null;  // gCore not ready, fall back to YAML
        }

        $gNodeClient = $gCore->getService('gnode_client');
        if (!$gNodeClient) {
            return null;
        }

        $json = $gNodeClient->get("{{$site_id}}:config:registration");
        if (!$json) {
            return null;
        }

        $config = json_decode($json, true);
        if (!$config || !is_array($config)) {
            return null;
        }

        // Validate required fields
        if (empty($config['site_id']) || empty($config['valkey'])) {
            return null;
        }

        gtemplate_track_error("gTemplate Registration: Loaded config from ValKey via gCore (site_id: {$site_id})");
        return $config;

    } catch (\Throwable $e) {
        // gCore/gNode-Client not available, fall back to YAML
        return null;
    }
}

/**
 * Load config from YAML files (slow path)
 *
 * @return array|null Config or null on error
 */
function load_config_from_yaml(): ?array
{
    $locations = [
        ABSPATH . 'wp-config-geodineum.yaml',                    // 1. Site-specific (WordPress root)
        get_template_directory() . '/registration.local.yaml',   // 2. Theme override (gitignored)
        get_template_directory() . '/registration.yaml',         // 3. Theme default (template)
    ];

    foreach ($locations as $config_file) {
        if (file_exists($config_file)) {
            $config = yaml_parse_file($config_file);

            if ($config !== false && is_array($config)) {
                // Auto-fill site_id from domain if missing
                if (empty($config['site_id'])) {
                    $config['site_id'] = get_site_id_from_domain();
                    gtemplate_track_error("gTemplate Registration: Auto-derived site_id from domain: {$config['site_id']}");
                }

                // Auto-fill ValKey user if missing
                if (empty($config['valkey']['user'])) {
                    $config['valkey']['user'] = 'gnode_client_' . $config['site_id'];
                }

                // Auto-fill ValKey password file if missing
                if (empty($config['valkey']['password_file'])) {
                    $config['valkey']['password_file'] = '/opt/gNode/.gnode/valkey_client_' . $config['site_id'] . '.password';
                }

                gtemplate_track_error("gTemplate Registration: Loaded config from {$config_file} (site_id: {$config['site_id']})");
                return $config;
            }
        }
    }

    // Fallback: Return minimal auto-detected config declared by name on the one topology
    $auto_site_id = get_site_id_from_domain();
    gtemplate_track_error("gTemplate Registration: No config file found, using auto-detected config (site_id: {$auto_site_id})");

    return [
        'version' => '1.0.0',
        'site_id' => $auto_site_id,
        'service' => [
            'type' => 'wordpress-site',
            'shape' => 'site',
            'update_mode' => 'upsert',
        ],
        // The one topology's service body, declared by name (SPEC-ONE-TOPOLOGY §3);
        // the daemon codes the words, this file never carries a number.
        'capabilities' => [
            'protocol' => ['http_rest'],
            'auth_method' => 'session_cookie',
            'service_scope' => 'client_facing',
            'domain' => ['content', 'presentation'],
            'contract_stability' => 'stable',
            'contract_version' => 'v1',
            'throughput_tier' => 'professional',
            'latency_class' => 'responsive',
            'reliability_tier' => 'high',
            'execution_priority' => 'normal',
            'implementation_language' => 'php',
            'native_format' => 'json',
        ],
        'metadata' => [
            'type' => 'wordpress-site',
            'theme' => wp_get_theme()->get('Name'),
            'environment' => defined('WP_ENVIRONMENT_TYPE') ? WP_ENVIRONMENT_TYPE : 'production',
            'domain' => get_site_url(),
        ],
        'valkey' => [
            'user' => 'gnode_client_' . $auto_site_id,
            'password_file' => '/opt/gNode/.gnode/valkey_client_' . $auto_site_id . '.password',
        ],
    ];
}

/**
 * Async sync config to ValKey (non-blocking)
 * Uses register_shutdown_function to sync after response is sent
 *
 * @param array $config Config to sync
 */
function sync_config_to_valkey_async(array $config): void
{
    static $scheduled = false;

    if ($scheduled) {
        return; // Already scheduled for this request
    }

    $scheduled = true;

    // Schedule sync after response is sent
    register_shutdown_function(function() use ($config) {
        sync_config_to_valkey($config);
    });
}

/**
 * Calculate canonical hash of registration config
 *
 * Only includes static fields (excludes runtime data and registration state).
 * This ensures hash only changes when actual config changes.
 *
 * @param array $config Registration config
 * @return string SHA-256 hash
 */
function calculate_registration_hash(array $config): string
{
    // Extract only static fields for hash
    $canonical = [
        'version' => $config['version'] ?? '1.0.0',
        'site_id' => $config['site_id'] ?? '',
        'service' => $config['service'] ?? [],
        'capabilities' => $config['capabilities'] ?? [],
        'metadata' => $config['metadata'] ?? [],
    ];

    // Use JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE for deterministic encoding
    $json = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return hash('sha256', $json);
}

/**
 * Register site via canonical TopologyManager flow
 *
 * @deprecated 2.0.1 Use TopologyManager::forceRegister() directly
 *
 * ARCHITECTURE CHANGE (2026-01-07): Now delegates to gCore TopologyManager.
 * The manager declares the site by name on the one topology (shape site, the
 * capabilities below as words) and discovers API endpoints.
 *
 * Falls back to stream-based registration ONLY if TopologyManager is unavailable
 * (e.g., during early bootstrap before gCore is initialized).
 *
 * @param array $config Registration config (ignored when using TopologyManager)
 * @return bool Success
 */
function register_site_direct(array $config): bool
{
    global $gCore;

    // TRY: Use canonical TopologyManager flow via gCore resolver
    try {
        if ($gCore) {
            $topology = $gCore->getService('TopologyManager');
            if ($topology && $topology->isInitialized()) {
                gtemplate_track_error("gTemplate: register_site_direct() delegating to TopologyManager (canonical flow)");
                return $topology->forceRegister();
            }
        }
    } catch (\Throwable $e) {
        gtemplate_track_error("gTemplate: TopologyManager unavailable, using fallback: " . $e->getMessage());
    }

    // Service registration is now handled by the gNode daemon's periodic discovery
    // (reads geometric_topology.yaml). No PHP-side registration needed.
    gtemplate_track_error("gTemplate: register_site_direct() skipped — daemon handles registration via discovery");
    return false;
}

/**
 * Sync registration config to ValKey
 *
 * Stores config in {site_id}:config:* keys for other services to access.
 *
 * @param array $config Registration config
 * @return bool Success
 */
function sync_config_to_valkey(array $config): bool
{
    global $gCore;

    try {
        $site_id = $config['site_id'];

        // Get gNode-Client from gCore (NEVER create directly!)
        if (!$gCore || !$gCore->hasService('gnode_client')) {
            gtemplate_track_error("gTemplate Registration: gCore not available for config sync");
            return false;
        }

        $gNodeClient = $gCore->getService('gnode_client');
        if (!$gNodeClient) {
            gtemplate_track_error("gTemplate Registration: gNode client not available from gCore");
            return false;
        }

        $ttl = $config['registration']['valkey_config_ttl'] ?? 86400;

        // Store registration config
        $gNodeClient->setex(
            "{{$site_id}}:config:registration",
            $ttl,
            json_encode($config)
        );

        // Store version hash
        $hash = calculate_registration_hash($config);
        $gNodeClient->set(
            "{{$site_id}}:config:version:registration",
            $hash
        );

        // Store manager configs
        $config_dir = get_template_directory();
        foreach ($config['managers'] ?? [] as $manager => $yaml_file) {
            $manager_config_file = $config_dir . '/' . $yaml_file;
            if (file_exists($manager_config_file)) {
                $manager_config = yaml_parse_file($manager_config_file);
                if ($manager_config) {
                    $gNodeClient->setex(
                        "{{$site_id}}:config:managers:{$manager}",
                        $ttl,
                        json_encode($manager_config)
                    );
                }
            }
        }

        gtemplate_track_error("gTemplate Registration: Config synced to ValKey via gCore ({{$site_id}}:config:*)");
        return true;

    } catch (\Throwable $e) {
        gtemplate_track_error("gTemplate Registration: Config sync failed - " . $e->getMessage());
        return false;
    }
}

// ============================================================================
// DEPRECATED: Registration functions moved to gCore TopologyManager
// ============================================================================
// The following functions have been REMOVED with the move to the TopologyManager flow:
//
// - smart_register_site()    → Use TopologyManager::smartRegister()
// - is_site_registered()     → Use TopologyManager::getRegistrationStatus()['registered']
// - get_registration_status() → Use TopologyManager::getRegistrationStatus()
// - force_reregister()       → Use TopologyManager::forceRegister()
//
// gTemplate now delegates ALL registration to gCore's TopologyManager,
// ensuring a single canonical registration flow across all themes.
//
// @see the registration architecture documentation
// @see GNODE_SERVICE_REGISTRATION_PROTOCOL.md
// ============================================================================

// Registration is not the theme's: `geodineum register` registers the site, and
// `geodineum deregister` removes it. Activating or switching themes changes neither.

/**
 * Theme Deactivation Hook: logs only. The site stays registered.
 */
add_action('switch_theme', function($new_name, $new_theme) {
    // Only run when switching AWAY from our theme
    // Check if this file is part of the old (still active at this point) theme
    $this_file = __FILE__;
    $theme_dir = get_template_directory();
    if (strpos($this_file, $theme_dir) === false) {
        return;
    }

    $theme_name = wp_get_theme()->get('Name');
    gtemplate_track_error("gTemplate: Theme deactivated ({$theme_name}) - site remains registered (it leaves with: sudo geodineum deregister <site_id>)");
}, 10, 2);
