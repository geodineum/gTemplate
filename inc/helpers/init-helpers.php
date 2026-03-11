<?php
/**
 * gTemplate-wp Initialization Helper Functions
 *
 * Core utility functions shared by all Geodineum child themes.
 * Functions use gtemplate_ prefix but behavior is theme-agnostic.
 *
 * @package gTemplate
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get the face count for the current theme
 *
 * @return int Number of faces/cells
 */
function gtemplate_get_face_count(): int {
    static $count = null;
    if ($count === null) {
        $count = (int) apply_filters('gtemplate_face_count', GTEMPLATE_FACE_COUNT);
    }
    return $count;
}

/**
 * Get the face label (e.g., 'face', 'cell')
 *
 * @return string Face label
 */
function gtemplate_get_face_label(): string {
    static $label = null;
    if ($label === null) {
        $label = apply_filters('gtemplate_face_label', 'face');
    }
    return $label;
}

/**
 * Get the customizer face prefix (e.g., 'cube_face', 'tesseract_cell')
 *
 * @return string Customizer face prefix
 */
function gtemplate_get_face_prefix(): string {
    static $prefix = null;
    if ($prefix === null) {
        $prefix = apply_filters('gtemplate_customizer_face_prefix', 'gtemplate_face');
    }
    return $prefix;
}

/**
 * Get REST API namespace (e.g., 'gcube/v1', 'gtesseract/v1')
 *
 * @return string REST namespace
 */
function gtemplate_get_rest_namespace(): string {
    static $ns = null;
    if ($ns === null) {
        $ns = apply_filters('gtemplate_rest_namespace', 'gtemplate/v1');
    }
    return $ns;
}

/**
 * Get the theme prefix (e.g., 'gcube', 'gtesseract')
 *
 * @return string Theme prefix
 */
function gtemplate_get_theme_prefix(): string {
    static $prefix = null;
    if ($prefix === null) {
        $prefix = apply_filters('gtemplate_theme_prefix', 'gtemplate');
    }
    return $prefix;
}

/**
 * Get site ID from config or auto-detect from domain
 *
 * @return string Site ID (e.g., "staging_nierto_com")
 */
function gtemplate_get_site_id(): string {
    static $site_id = null;

    if ($site_id === null) {
        $reg_config = gtemplate_get_registration_config();

        if (!empty($reg_config['site_id'])) {
            $site_id = $reg_config['site_id'];
        } else {
            $site_domain = parse_url(get_site_url(), PHP_URL_HOST);
            $site_id = str_replace(['.', '-'], '_', $site_domain);
        }
    }

    return $site_id;
}

/**
 * Log a message to the centralized geodineum logging system
 *
 * @param string $message Log message
 * @param string $level   Log level: 'DEBUG', 'INFO', 'WARNING', 'ERROR'
 * @param array  $context Optional context data
 */
function geodineum_log(string $message, string $level = 'INFO', array $context = []): void {
    $level = strtoupper($level);
    $validLevels = ['DEBUG', 'INFO', 'WARNING', 'ERROR'];
    if (!in_array($level, $validLevels)) {
        $level = 'INFO';
    }

    $timestamp = date('Y-m-d\TH:i:s.v') . date('P');
    $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
    $formatted = "[{$timestamp}] [{$level}] {$message}{$contextStr}\n";

    $theme_prefix = gtemplate_get_theme_prefix();

    if (defined('GEODINEUM_LOG_DIR')) {
        $siteId = gtemplate_get_site_id();
        $logDir = GEODINEUM_LOG_DIR . '/themes/' . $theme_prefix . '/sites/' . $siteId;

        if (!is_dir($logDir)) {
            @mkdir($logDir, 0750, true);
        }

        $logFile = $logDir . '/theme.log';

        if (@file_put_contents($logFile, $formatted, FILE_APPEND | LOCK_EX) !== false) {
            return;
        }
    }

    error_log("[{$theme_prefix}] [{$level}] {$message}" . $contextStr);
}

/**
 * Get ValKey credentials from config with fallbacks
 *
 * @deprecated gCore handles all ValKey credential discovery internally.
 * @return array
 */
function gtemplate_get_valkey_credentials(): array {
    $site_id = gtemplate_get_site_id();
    return [
        'user' => 'gnode_client_' . $site_id,
        'password' => null,
        'password_file' => ''
    ];
}

/**
 * Detect DTAP environment from config or constants
 *
 * @return string Environment: 'testing', 'staging', 'acceptance', or 'production'
 */
function gtemplate_detect_environment(): string {
    static $environment = null;

    if ($environment === null) {
        $reg_config = gtemplate_get_registration_config();
        $environment = $reg_config['metadata']['environment'] ?? null;

        if (!$environment && defined('WP_ENVIRONMENT_TYPE')) {
            $env_map = [
                'development' => 'testing',
                'local' => 'testing',
                'staging' => 'staging',
                'acceptance' => 'acceptance',
                'production' => 'production'
            ];
            $environment = $env_map[WP_ENVIRONMENT_TYPE] ?? 'production';
        }

        if (!$environment) {
            $environment = 'production';
        }
    }

    return $environment;
}

/**
 * Get gNode client from gCore
 *
 * @deprecated Use $gCore->getService('gnode_client') directly.
 */
function gtemplate_create_gnode_clients(string $environment): array {
    global $gCore;

    if (!$gCore) {
        throw new \RuntimeException('gCore not initialized - cannot get gNode client');
    }

    $gNodeClient = $gCore->getService('gnode_client');

    if (!$gNodeClient) {
        throw new \RuntimeException('gNode client not available from gCore');
    }

    return [
        'stream' => $gNodeClient,
        'keybased' => $gNodeClient,
        'storage' => $gNodeClient->getStorage()
    ];
}

/**
 * Get client identifier for rate limiting
 *
 * @return string Client IP address or identifier
 */
function gtemplate_get_client_identifier(): string {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($ips[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }

    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $ip = trim($_SERVER['HTTP_X_REAL_IP']);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }

    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Get gCore initialization configuration
 *
 * @return array gCore configuration array
 */
function gtemplate_get_gcore_config(): array {
    $site_id = gtemplate_get_site_id();
    $environment = gtemplate_detect_environment();

    $config = [
        'core' => [
            'environment' => 'wordpress',
            'debug' => defined('WP_DEBUG') && WP_DEBUG,
            'site_id' => $site_id,
            'node_id' => 'web-' . gethostname()
        ],
        'site_id' => $site_id,
        'gnode_environment' => $environment,
        'gnode_stream_prefix' => 'gnode',
        'gnode_batch_enabled' => (defined('DOING_CRON') && DOING_CRON) || (php_sapi_name() === 'cli'),
        'gnode_batch_queue_size' => 100,
        'gnode_batch_timeout_ms' => 10.0,
        'gnode_cache_enabled' => true,
        'gnode_cache_ttl' => 60,
        'gnode_max_retries' => 3,
        'gnode_circuit_breaker_enabled' => true,
        'gnode_circuit_breaker_threshold' => 5,
        'gnode_circuit_breaker_cooldown_secs' => 30,
        'modules' => [
            'TopologyManager' => [
                'enabled' => true,
                'use_gnode' => true,
                'auto_register_service' => false,
                'site_id' => $site_id,
                'node_id' => 'web-' . gethostname(),
                'cache_enabled' => true,
                'default_dimensions' => 19,
                'debug' => defined('WP_DEBUG') && WP_DEBUG
            ]
        ]
    ];

    return apply_filters('gtemplate_gcore_config', $config);
}

/**
 * Inject gNode-Client into SecurityManager for centralized metrics
 *
 * @param mixed $gNodeClient gNode key-based client
 * @return bool True if injection successful
 */
function gtemplate_inject_gnode_into_security_manager($gNodeClient): bool {
    if ($gNodeClient === null) {
        return false;
    }

    global $gCore;

    if (!$gCore) {
        return false;
    }

    try {
        $security = $gCore->getService('SecurityManager');
        if (!$security) {
            return false;
        }

        if (!method_exists($security, 'setgNodeClient')) {
            return false;
        }

        $security->setgNodeClient($gNodeClient);
        return true;

    } catch (\Throwable $e) {
        error_log('gTemplate: Failed to inject gNode-Client into SecurityManager: ' . $e->getMessage());
        return false;
    }
}
