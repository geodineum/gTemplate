<?php
/**
 * OptimizationManager Integration for gTemplate
 *
 * Integrates gCore OptimizationManager for centralized performance management.
 * Complements (not duplicates) existing optimizations in performance.php.
 *
 * OptimizationManager unique features used:
 * - Security headers (X-Content-Type-Options, X-Frame-Options, X-XSS-Protection)
 * - Query string removal from static resources (cache-friendly URLs)
 * - Centralized metrics recording
 * - Multi-tenant isolation (site_id/node_id)
 * - Configurable preload resources
 *
 * @package gTemplate
 * @version 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Initialize OptimizationManager for gTemplate
 *
 * Called during theme initialization after gCore is available.
 *
 * @return \gCore\Modules\Managers\Base\OptimizationManager\OptimizationManager|null
 */
function gtemplate_init_optimization_manager() {
    global $gCore;

    if (!$gCore) {
        return null;
    }

    try {
        $opt = $gCore->getService('OptimizationManager');
        if (!$opt) {
            error_log('gTemplate: OptimizationManager not available from gCore');
            return null;
        }

        // Check if already initialized
        if ($opt->isInitialized()) {
            return $opt;
        }

        // Get theme assets for preloading
        $theme_uri = get_template_directory_uri();
        $version = wp_get_theme()->get('Version');

        $opt->initialize([
            'enabled' => true,
            'debug' => defined('WP_DEBUG') && WP_DEBUG,
            'site_id' => gtemplate_get_site_id(),
            'node_id' => 'web-' . gethostname(),

            // Static file extensions for cache headers
            'static_extensions' => ['css', 'js', 'jpg', 'jpeg', 'png', 'gif', 'ico', 'svg', 'woff', 'woff2', 'webp', 'avif'],
            'cache_ttl' => 31536000, // 1 year for static assets

            // Resource version for cache busting
            'resource_version' => $version,

            // Script optimization (performance.php handles defer, so disable here to avoid conflict)
            'defer_scripts' => false, // Handled by performance.php gtemplate_optimize_script_loading()

            // Style optimization (performance.php handles this)
            'optimize_styles' => false, // Handled by performance.php

            // Query string removal (enables cleaner URLs for better caching)
            'remove_query_strings' => true,

            // Database optimization (adds cube_face post type to main query)
            'optimize_database' => true,

            // Preload critical resources for gTemplate 3D interface
            'preload_resources' => [
                // Critical CSS for above-the-fold cube rendering
                'assets/css/theme.css' => 'style',
                // Cube controls need to be ready quickly
                'assets/js/theme.js' => 'script',
            ]
        ]);

        error_log('gTemplate: OptimizationManager initialized successfully');
        return $opt;

    } catch (\Throwable $e) {
        error_log('gTemplate: OptimizationManager init error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Get the OptimizationManager instance
 *
 * @return \gCore\Modules\Managers\Base\OptimizationManager\OptimizationManager|null
 */
function gtemplate_get_optimization_manager() {
    global $gCore;

    if (!$gCore) {
        return null;
    }

    try {
        $opt = $gCore->getService('OptimizationManager');
        return ($opt && $opt->isInitialized()) ? $opt : null;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Get optimization status and metrics
 *
 * @return array Status information
 */
function gtemplate_get_optimization_status(): array {
    $opt = gtemplate_get_optimization_manager();

    if ($opt) {
        return $opt->getStatus();
    }

    return [
        'initialized' => false,
        'enabled' => false,
        'mode' => 'unavailable'
    ];
}

/**
 * Update optimization configuration at runtime
 *
 * @param array $config New configuration values
 * @return bool Success
 */
function gtemplate_update_optimization_config(array $config): bool {
    $opt = gtemplate_get_optimization_manager();

    if ($opt) {
        try {
            $opt->updateConfig($config);
            return true;
        } catch (\Throwable $e) {
            error_log('gTemplate: Optimization config update failed: ' . $e->getMessage());
        }
    }

    return false;
}

/**
 * Add gTemplate-specific security headers
 *
 * Supplements OptimizationManager's security headers with gTemplate-specific policies.
 */
add_action('send_headers', function() {
    // Only on frontend, not admin
    if (is_admin()) {
        return;
    }

    // Content Security Policy for 3D cube (allows inline styles for transforms)
    // Note: This is a baseline CSP - adjust based on your specific needs
    $csp_directives = [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline'", // HTMX requires inline handlers
        "style-src 'self' 'unsafe-inline'", // CSS transforms use inline styles
        "img-src 'self' data: https:", // Allow data URIs and HTTPS images
        "font-src 'self' https://fonts.gstatic.com",
        "connect-src 'self'", // HTMX AJAX calls
        "frame-ancestors 'self'", // Prevent clickjacking
    ];

    // Only set CSP in production (can interfere with dev tools)
    if (!defined('WP_DEBUG') || !WP_DEBUG) {
        header('Content-Security-Policy: ' . implode('; ', $csp_directives));
    }

    // Referrer policy for privacy
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // Permissions policy (restrict dangerous features)
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');
}, 1);

/**
 * Add performance timing headers for debugging
 *
 * Server-Timing header allows viewing backend timing in browser DevTools.
 */
add_action('shutdown', function() {
    // Only if headers not sent and debug enabled
    if (headers_sent() || !(defined('WP_DEBUG') && WP_DEBUG)) {
        return;
    }

    $opt = gtemplate_get_optimization_manager();
    if (!$opt) {
        return;
    }

    // Calculate total request time
    $total_time = microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'];
    $total_ms = round($total_time * 1000, 2);

    // Add Server-Timing header
    header("Server-Timing: total;dur={$total_ms};desc=\"Total Request\"");
}, 9999);

/**
 * Register cube_face as optimized post type
 *
 * If cube_face custom post type is registered, ensure it's included
 * in optimized database queries.
 */
add_filter('gtemplate_optimized_post_types', function($types) {
    $types[] = 'cube_face';
    return array_unique($types);
});

/**
 * Provide cache control hints for gNode-rendered content
 *
 * When content is served from gNode bundle, we can cache aggressively.
 */
add_filter('wp_headers', function($headers) {
    // Check if this request was served from gNode bundle
    if (!empty($GLOBALS['gtemplate_served_from_bundle'])) {
        // gNode bundle content is pre-rendered and can be cached
        $headers['Cache-Control'] = 'public, max-age=300, stale-while-revalidate=60';
        $headers['X-GTemplate-Cache'] = 'bundle-hit';
    }

    return $headers;
}, 20);

/**
 * Mark request as served from bundle (called by bundle rendering functions)
 *
 * @param bool $from_bundle Whether content came from gNode bundle
 */
function gtemplate_mark_served_from_bundle(bool $from_bundle = true): void {
    $GLOBALS['gtemplate_served_from_bundle'] = $from_bundle;
}

/**
 * Add Link headers for HTTP/2 server push hints
 *
 * Allows server to push critical resources before browser requests them.
 * Note: Requires HTTP/2 and server configuration to act on hints.
 */
add_action('send_headers', function() {
    if (is_admin()) {
        return;
    }

    $theme_uri = get_template_directory_uri();
    $version = wp_get_theme()->get('Version');

    // Critical resources for 3D cube
    $push_resources = [
        $theme_uri . '/assets/css/theme.css?ver=' . $version => 'style',
        $theme_uri . '/assets/js/theme.js?ver=' . $version => 'script',
    ];

    foreach ($push_resources as $url => $type) {
        // Link header format for HTTP/2 push hints
        header("Link: <{$url}>; rel=preload; as={$type}", false);
    }
}, 2);
