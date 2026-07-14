<?php
declare(strict_types=1);
/**
 * gTemplate Initialization Helper Functions
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
 * Track an error/warning/info event through the gCore ErrorManager when
 * available, with a graceful fallback to error_log() for early-init paths
 * (and for any environment where gCore isn't loaded — free-tier themes,
 * activation hooks, fatal-during-bootstrap scenarios).
 *
 * Replaces the ~100 raw error_log() call sites that previously bypassed
 * the framework's structured error pipeline (ROADMAP §B.2). With
 * ErrorManager wired, gTemplate's PHP-side errors land in ValKey, get
 * categorized by severity, and become visible to gDash + admin
 * notifications.
 *
 * @param string $message  Human-readable error message (free-form;
 *                         existing "gTemplate: ..." / "[gTemplate] ..."
 *                         prefixes are preserved as-is for journal
 *                         continuity)
 * @param array  $context  Optional structured context (request_id,
 *                         site_id, hook_name, exception_class, …).
 *                         ErrorManager indexes by these keys.
 * @param string $level    Severity. 'INFO' / 'WARNING' (default) /
 *                         'ERROR' / 'CRITICAL'. Mirrors PSR-3 levels.
 */
function gtemplate_track_error(string $message, array $context = [], string $level = 'WARNING'): void {
    global $gCore;
    try {
        if ($gCore && method_exists($gCore, 'hasService') && $gCore->hasService('ErrorManager')) {
            $em = $gCore->getService('ErrorManager');
            if ($em && method_exists($em, 'trackError')) {
                $em->trackError($level, $message, $context);
                return;
            }
        }
    } catch (\Throwable $_) {
        // Never let the error tracker itself raise — fall through.
    }
    // Fallback: write the message verbatim (no double-prefix) so the
    // resulting log line matches what the call site would have emitted
    // before the helper existed.
    error_log($message);
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
 * Get REST API namespace (e.g., 'gcube/v1', 'childtheme/v1')
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
 * Get the theme prefix (e.g., 'gcube', 'childtheme')
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
 * @return string Site ID (e.g., "your_site")
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
if (!function_exists('geodineum_log')) {
function geodineum_log(string $message, string $level = 'INFO', array $context = []): void {
    $level = strtoupper($level);
    $validLevels = ['DEBUG', 'INFO', 'WARNING', 'ERROR'];
    if (!in_array($level, $validLevels)) {
        $level = 'INFO';
    }

    $timestamp = date('Y-m-d\TH:i:s.v') . date('P');
    $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
    $formatted = "[{$timestamp}] [{$level}] {$message}{$contextStr}\n";

    // Use theme-aware prefix if available (parent defines gtemplate_get_theme_prefix)
    $theme_prefix = function_exists('gtemplate_get_theme_prefix') ? gtemplate_get_theme_prefix() : 'geodineum';

    if (defined('GEODINEUM_LOG_DIR')) {
        $siteId = function_exists('gtemplate_get_site_id') ? gtemplate_get_site_id() : 'unknown';
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
} // end function_exists('geodineum_log')

/**
 * Detect DTAP environment from config or constants
 *
 * @return string Environment: 'testing', 'staging', 'acceptance', or 'production'
 */
function gtemplate_detect_environment(): string {
    static $environment = null;
    if ($environment !== null) {
        return $environment;
    }

    // Authoritative: active_environment in ValKey (written by `geodineum env
    // set`), read via gCore over the site's ACL-scoped connection. A switch
    // takes effect instantly — no config-file edit, no cache bust, no wp-config
    // vs .geodineum drift. Only an authoritative hit is memoised; a miss falls
    // through to the file and is NOT cached, so a later call (once gCore/ValKey
    // is up) can still pick up the live value.
    if (function_exists('gcore_site_active_environment')) {
        $vk = gcore_site_active_environment();
        if ($vk) {
            $environment = $vk;
            return $environment;
        }
    }

    // Bootstrap fallback: config file → WP_ENVIRONMENT_TYPE → production.
    $reg_config = gtemplate_get_registration_config();
    $env = $reg_config['metadata']['environment'] ?? null;
    if (!$env && defined('WP_ENVIRONMENT_TYPE')) {
        $env_map = [
            'development' => 'testing',
            'local' => 'testing',
            'staging' => 'staging',
            'acceptance' => 'acceptance',
            'production' => 'production',
        ];
        $env = $env_map[WP_ENVIRONMENT_TYPE] ?? 'production';
    }
    return $env ?: 'production';
}

/**
 * Get client identifier for rate limiting.
 *
 * Commit 1.11.d: XFF-spoof guard. Pre-fix unconditionally
 * trusted `X-Forwarded-For` and `X-Real-IP`, so any direct-connect
 * deployment let an attacker bypass the SecurityManager rate limit
 * on /pages, /forms, /ai/chat etc. via `curl -H "X-Forwarded-For: …"`.
 * Post-fix gates header trust on the operator-declared
 * `GTEMPLATE_TRUST_PROXY` constant or env var. Mirrors gCube's
 * `GCUBE_TRUST_PROXY` from Commit 1.8.b.
 *
 * @return string Client IP address or identifier
 */
function gtemplate_get_client_identifier(): string {
    $trust_proxy = (defined('GTEMPLATE_TRUST_PROXY') && GTEMPLATE_TRUST_PROXY)
        || getenv('GTEMPLATE_TRUST_PROXY') === '1';

    if ($trust_proxy) {
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
        gtemplate_track_error('gTemplate: Failed to inject gNode-Client into SecurityManager: ' . $e->getMessage(), [], 'ERROR');
        return false;
    }
}
