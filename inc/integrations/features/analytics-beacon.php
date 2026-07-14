<?php
declare(strict_types=1);
/**
 * Cookieless Visitor Analytics Beacon
 *
 * A front-end beacon (assets/js/analytics-beacon.js, enqueued in setup/enqueue.php)
 * POSTs the current page + referrer here. This endpoint resolves the site, a
 * daily-rotating visitor hash, and a bot flag server-side, then records the hit
 * through the GNODE_ANALYTICS_HIT Lua function — one atomic write at the data.
 *
 * No cookies are set and no persistent identifier is stored: the visitor hash
 * is sha256(ip|ua|Ymd|salt) truncated, so it cannot be correlated across days.
 *
 * @package    gTemplate
 * @subpackage Integrations
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', function () {
    // Public: a beacon fires via navigator.sendBeacon, which cannot set an
    // X-WP-Nonce header, so this endpoint is unauthenticated by necessity. It
    // only increments per-site aggregate counters (no reads, no PII stored),
    // and validates/bounds every field server-side.
    register_rest_route(gtemplate_get_rest_namespace(), '/analytics/hit', [
        'methods' => 'POST',
        'callback' => 'gtemplate_rest_analytics_hit',
        'permission_callback' => '__return_true',
        'args' => [
            'path' => ['type' => 'string', 'default' => '/'],
            'ref' => ['type' => 'string', 'default' => ''],
        ],
    ]);
});

/**
 * Record a single visitor hit.
 *
 * @param \WP_REST_Request $request
 * @return \WP_REST_Response
 */
function gtemplate_rest_analytics_hit($request)
{
    $params = $request->get_json_params();
    if (!is_array($params)) {
        $params = $request->get_params();
    }

    // Normalize + bound the page path (drop fragment/query noise, cap length).
    $path = isset($params['path']) ? (string) $params['path'] : '/';
    $path = strtok($path, '#');
    $path = preg_replace('/[\x00-\x1f]/', '', (string) $path);
    $path = '/' . ltrim($path, '/');
    if (strlen($path) > 300) {
        $path = substr($path, 0, 300);
    }

    $storage = $GLOBALS['gtemplate_gnode_storage'] ?? null;
    if (!$storage) {
        // Nothing to write to; accept quietly so the beacon never retries.
        return new WP_REST_Response(['ok' => false], 202);
    }

    $site_id = gtemplate_get_site_id();

    // Referrer host — external referrers only.
    $ref = isset($params['ref']) ? (string) $params['ref'] : '';
    $ref_host = '';
    if ($ref !== '') {
        $rh = parse_url($ref, PHP_URL_HOST);
        $self = parse_url(home_url(), PHP_URL_HOST);
        if ($rh && strcasecmp((string) $rh, (string) $self) !== 0) {
            $ref_host = strtolower((string) preg_replace('/[^a-zA-Z0-9._-]/', '', (string) $rh));
            if (strlen($ref_host) > 120) {
                $ref_host = substr($ref_host, 0, 120);
            }
        }
    }

    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
    $is_bot = gtemplate_analytics_is_bot($ua) ? '1' : '0';

    // Cookieless, daily-rotating visitor hash — no cross-day correlation.
    $ymd = gmdate('Ymd');
    $vhash = substr(
        hash('sha256', gtemplate_analytics_client_ip() . '|' . $ua . '|' . $ymd . '|' . wp_salt('nonce')),
        0,
        24
    );

    try {
        $storage->fcall('GNODE_ANALYTICS_HIT', [], [
            $site_id,
            $vhash,
            $path,
            $ref_host,
            $is_bot,
            (string) time(),
            $ymd,
        ]);
    } catch (\Throwable $e) {
        if (function_exists('gtemplate_track_error')) {
            gtemplate_track_error('gTemplate: analytics hit failed: ' . $e->getMessage());
        }
    }

    return new WP_REST_Response(['ok' => true], 202);
}

/**
 * UA-based bot heuristic. A blank UA on a JS beacon is itself suspicious.
 *
 * @param string $ua
 * @return bool
 */
function gtemplate_analytics_is_bot(string $ua): bool
{
    if ($ua === '') {
        return true;
    }
    return (bool) preg_match(
        '/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|embedly|quora|pinterest|'
        . 'vkshare|W3C_Validator|headless|phantom|puppeteer|playwright|lighthouse|curl|wget|'
        . 'python-requests|go-http|okhttp|httpclient|scrapy|ahrefs|semrush|mj12|dotbot|petalbot|'
        . 'gptbot|ccbot|claudebot|bytespider|amazonbot|dataforseo/i',
        $ua
    );
}

/**
 * Best-effort client IP. Behind a local reverse proxy REMOTE_ADDR is loopback,
 * so fall back to the first X-Forwarded-For hop; the value is only hashed.
 *
 * @return string
 */
function gtemplate_analytics_client_ip(): string
{
    $remote = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    $is_local = in_array($remote, ['127.0.0.1', '::1'], true)
        || (bool) preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $remote);
    if ($is_local && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $first = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            return $first;
        }
    }
    return $remote;
}
