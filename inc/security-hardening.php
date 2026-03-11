<?php
/**
 * gTemplate Security Hardening
 *
 * Implements security fixes identified in SECURITY_REPORT.md:
 * - REST API user enumeration protection (CRITICAL)
 * - Author archive enumeration protection (HIGH)
 * - Security headers (MEDIUM)
 *
 * @package gTemplate
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * =============================================================================
 * CRITICAL: Disable REST API User Enumeration
 * =============================================================================
 *
 * Prevents /wp-json/wp/v2/users from exposing usernames for brute-force attacks.
 * Only administrators can access user endpoints.
 */
add_filter('rest_endpoints', function($endpoints) {
    // Remove user listing endpoint entirely for non-admins
    if (!current_user_can('list_users')) {
        if (isset($endpoints['/wp/v2/users'])) {
            unset($endpoints['/wp/v2/users']);
        }
        if (isset($endpoints['/wp/v2/users/(?P<id>[\d]+)'])) {
            unset($endpoints['/wp/v2/users/(?P<id>[\d]+)']);
        }
        if (isset($endpoints['/wp/v2/users/me'])) {
            unset($endpoints['/wp/v2/users/me']);
        }
    }

    return $endpoints;
});

/**
 * Restrict user data in REST API responses
 * Even if endpoints exist, limit what data is exposed
 */
add_filter('rest_prepare_user', function($response, $user, $request) {
    // Only allow admins to see full user data
    if (!current_user_can('list_users')) {
        return new WP_Error(
            'rest_user_cannot_view',
            __('Sorry, you are not allowed to access user data.', 'gtemplate'),
            ['status' => 403]
        );
    }
    return $response;
}, 10, 3);

/**
 * =============================================================================
 * HIGH: Block Author Archive Enumeration
 * =============================================================================
 *
 * Prevents /?author=N from revealing usernames via redirect to /author/username/
 */

// Block ?author=N query parameter
add_action('init', function() {
    if (isset($_GET['author']) && is_numeric($_GET['author'])) {
        wp_safe_redirect(home_url('/'), 301);
        exit;
    }
}, 1);

// Redirect author archives to homepage
add_action('template_redirect', function() {
    if (is_author()) {
        wp_safe_redirect(home_url('/'), 301);
        exit;
    }
});

/**
 * Remove author name from various places
 */
add_filter('the_author', function($author) {
    // Only hide on frontend, allow in admin
    if (is_admin()) {
        return $author;
    }
    // Return site name instead of author for public display
    return get_bloginfo('name');
});

/**
 * Remove author from REST API post responses for non-admins
 */
add_filter('rest_prepare_post', function($response, $post, $request) {
    if (!current_user_can('edit_others_posts')) {
        $data = $response->get_data();
        // Remove author ID to prevent enumeration
        if (isset($data['author'])) {
            unset($data['author']);
        }
        $response->set_data($data);
    }
    return $response;
}, 10, 3);

/**
 * =============================================================================
 * MEDIUM: Security Headers
 * =============================================================================
 *
 * Adds security headers to protect against XSS, clickjacking, MIME sniffing
 */
add_action('send_headers', function() {
    // Skip in admin area - some plugins need iframes
    if (is_admin()) {
        return;
    }

    // Prevent clickjacking - only allow same origin framing
    header('X-Frame-Options: SAMEORIGIN');

    // Prevent MIME type sniffing
    header('X-Content-Type-Options: nosniff');

    // XSS protection (legacy, but still useful for older browsers)
    header('X-XSS-Protection: 1; mode=block');

    // HSTS - enforce HTTPS (only if already on HTTPS)
    if (is_ssl()) {
        // 1 year max-age, include subdomains
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    // Referrer policy - send referrer only for same-origin or HTTPS
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // Permissions policy - disable unused browser features
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=()');

    // Content Security Policy
    // Start permissive, tighten based on site needs
    $csp_directives = [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline' 'unsafe-eval'",  // Required for WordPress/customizer
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",  // Required for inline styles + Google Fonts
        "style-src-elem 'self' 'unsafe-inline' https://fonts.googleapis.com",  // Explicit style element policy
        "img-src 'self' data: https:",                       // Allow images from HTTPS sources
        "font-src 'self' data: https://fonts.gstatic.com",   // Allow fonts including Google Fonts
        "connect-src 'self' wss: https:",                    // Allow WebSocket and HTTPS connections
        "frame-ancestors 'self'",                            // Prevent framing (CSP version of X-Frame-Options)
        "base-uri 'self'",                                   // Restrict base tag
        "form-action 'self'",                                // Restrict form submissions
    ];

    header('Content-Security-Policy: ' . implode('; ', $csp_directives));
});

/**
 * =============================================================================
 * Additional Security Measures
 * =============================================================================
 */

/**
 * Remove WordPress version from various outputs
 */
remove_action('wp_head', 'wp_generator');
add_filter('the_generator', '__return_empty_string');

/**
 * Remove version from RSS feeds
 */
add_filter('the_generator', function($generator_type) {
    return '';
});

/**
 * Disable XML-RPC (already blocked at server level, but double-check)
 */
add_filter('xmlrpc_enabled', '__return_false');

/**
 * Remove XML-RPC discovery links
 */
remove_action('wp_head', 'rsd_link');
remove_action('wp_head', 'wlwmanifest_link');

/**
 * Disable file editing in admin (defense in depth)
 */
if (!defined('DISALLOW_FILE_EDIT')) {
    define('DISALLOW_FILE_EDIT', true);
}

/**
 * Remove shortlink header (information disclosure)
 */
remove_action('wp_head', 'wp_shortlink_wp_head');
remove_action('template_redirect', 'wp_shortlink_header', 11);

/**
 * Remove REST API discovery links from head (reduce surface area)
 */
remove_action('wp_head', 'rest_output_link_wp_head');
remove_action('template_redirect', 'rest_output_link_header', 11);

/**
 * Remove oEmbed discovery links
 */
remove_action('wp_head', 'wp_oembed_add_discovery_links');
remove_action('wp_head', 'wp_oembed_add_host_js');

/**
 * Disable user registration by default (can be enabled in settings)
 */
add_filter('option_users_can_register', function($value) {
    // Allow override via constant
    if (defined('GTEMPLATE_ALLOW_REGISTRATION') && GTEMPLATE_ALLOW_REGISTRATION) {
        return $value;
    }
    return false;
});

/**
 * Log security-relevant events
 */
add_action('wp_login_failed', function($username) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    error_log(sprintf(
        '[gTemplate Security] Failed login attempt for user "%s" from IP %s',
        sanitize_user($username),
        $ip
    ));
});

/**
 * Rate limit login attempts (basic protection)
 * For production, consider a dedicated plugin like Limit Login Attempts
 */
add_filter('authenticate', function($user, $username, $password) {
    if (empty($username)) {
        return $user;
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $transient_key = 'gtemplate_login_attempts_' . md5($ip);
    $attempts = get_transient($transient_key) ?: 0;

    // Block after 5 failed attempts for 15 minutes
    if ($attempts >= 5) {
        return new WP_Error(
            'too_many_attempts',
            __('Too many failed login attempts. Please try again in 15 minutes.', 'gtemplate')
        );
    }

    return $user;
}, 30, 3);

add_action('wp_login_failed', function($username) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $transient_key = 'gtemplate_login_attempts_' . md5($ip);
    $attempts = get_transient($transient_key) ?: 0;
    set_transient($transient_key, $attempts + 1, 15 * MINUTE_IN_SECONDS);
});

// Clear login attempts on successful login
add_action('wp_login', function($username, $user) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $transient_key = 'gtemplate_login_attempts_' . md5($ip);
    delete_transient($transient_key);
}, 10, 2);
