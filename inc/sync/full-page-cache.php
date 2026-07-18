<?php
declare(strict_types=1);
/**
 * gTemplate full-page-cache — anonymous-GET HTML cache via ValKey
 *
 * Extracted from inc/gnode-content-sync.php in Commit 1.10.c.
 * Owns the full-page anonymous cache that fronts WordPress for
 * cacheable GETs:
 *   - feature flag + cacheable-request gate (gtemplate_page_cache_enabled,
 *     request_is_cacheable)
 *   - per-URL cache key (gtemplate_get_page_cache_key)
 *   - serve-from-cache fast path (gtemplate_serve_cached_page,
 *     hooked at template_redirect priority 1)
 *   - capture-to-cache buffer (gtemplate_start_page_cache_buffer +
 *     page_cache_callback, hooked at template_redirect priority 2)
 *   - invalidate single URL / all URLs (gtemplate_invalidate_fullpage_cache,
 *     invalidate_all_fullpage_cache)
 *   - WordPress hook wiring: save_post → invalidate, customize_save_after
 *     → invalidate-all
 *
 * ⚠️  The page-cache replay surface lives here
 * — the cached payload includes a `headers` field that gets replayed via
 * header() at serve time. Commit 1.11 will add HMAC binding so a ValKey
 * write-primitive can no longer plant arbitrary HTML + Set-Cookie /
 * Location: forgery for every anonymous GET. The audit explicitly said
 * "split first, then HMAC" — this commit is the split prerequisite.
 *
 * @package gTemplate
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Commit 1.11.a: per-site HMAC secret for the page-cache
 * payload. WP options live in MySQL (different ACL surface than the
 * ValKey cache backend), so a ValKey-only write primitive cannot
 * forge a matching HMAC. The secret is auto-generated on first use
 * with 64 random chars from wp_generate_password(); operators can
 * rotate by `wp option delete gtemplate_page_cache_hmac_secret`.
 */
function gtemplate_page_cache_hmac_secret(): string
{
    $secret = get_option('gtemplate_page_cache_hmac_secret', '');
    if (!is_string($secret) || strlen($secret) < 32) {
        $secret = wp_generate_password(64, true, true);
        // Use update_option with autoload=no so the secret isn't
        // pulled into every page load's options cache.
        update_option('gtemplate_page_cache_hmac_secret', $secret, false);
    }
    return $secret;
}

/**
 * Commit 1.11.a: compute HMAC tag for cache payload.
 */
function gtemplate_page_cache_hmac(string $html, int $cached_at, string $url): string
{
    $msg = $cached_at . '|' . $url . '|' . $html;
    return hash_hmac('sha256', $msg, gtemplate_page_cache_hmac_secret());
}

// ============================================================================
// FULL-PAGE VALKEY CACHING (gNode-enhanced feature)
// ============================================================================

/**
 * Check if full-page caching is enabled
 *
 * Full-page caching stores the complete rendered HTML in ValKey,
 * eliminating PHP execution time for cached pages.
 *
 * Disable with: define('GTEMPLATE_DISABLE_PAGE_CACHE', true);
 *
 * @return bool
 */
function gtemplate_page_cache_enabled(): bool {
    // Disabled by constant
    if (defined('GTEMPLATE_DISABLE_PAGE_CACHE') && GTEMPLATE_DISABLE_PAGE_CACHE) {
        return false;
    }

    // Free tier mode doesn't have gNode
    if (defined('GTEMPLATE_FREE_TIER') && GTEMPLATE_FREE_TIER) {
        return false;
    }

    return true;
}

/**
 * Check if current request is cacheable
 *
 * @return bool
 */
function gtemplate_request_is_cacheable(): bool {
    // Don't cache POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        return false;
    }

    // Don't cache admin pages
    if (is_admin()) {
        return false;
    }

    // Don't cache logged-in users (personalized content)
    if (is_user_logged_in()) {
        return false;
    }

    // Don't cache preview pages
    if (is_preview()) {
        return false;
    }

    // Don't cache search results (too dynamic)
    if (is_search()) {
        return false;
    }

    // Don't cache 404 pages
    if (is_404()) {
        return false;
    }

    // Don't cache pages with specific query parameters
    $nocache_params = ['preview', 'customize_changeset_uuid', 'wp-preview'];
    foreach ($nocache_params as $param) {
        if (isset($_GET[$param])) {
            return false;
        }
    }

    // Don't cache if cookies indicate no-cache
    foreach ($_COOKIE as $key => $value) {
        if (strpos($key, 'wordpress_logged_in') === 0) {
            return false;
        }
        if (strpos($key, 'comment_author') === 0) {
            return false;  // Comment authors see pending comments
        }
        // Viewkey holders bypass the environment gate and see live render;
        // caching their response would let anonymous visitors hit the cache
        // and silently bypass the gate. Treat them like logged-in users:
        // no read, no write. Anonymous requests then only cache the gate
        // page itself, which is the correct payload for them.
        if ($key === 'gcore_viewkey') {
            return false;
        }
        // Findings access-code holders get the full dashboard at the same
        // URL the anonymous teaser renders at — same trap as the viewkey:
        // no read, no write. Mirrored in gCore early-page-cache.php.
        if ($key === 'gan_access') {
            return false;
        }
    }

    return true;
}

/**
 * Generate cache key for current request
 *
 * @return string
 */
function gtemplate_get_page_cache_key(): string {
    $site_id = function_exists('\gTemplate\get_site_id_from_domain')
        ? \gTemplate\get_site_id_from_domain()
        : 'default';

    // Build URL hash (path + sorted query string)
    $path = $_SERVER['REQUEST_URI'] ?? '/';
    $parsed = parse_url($path);
    $clean_path = $parsed['path'] ?? '/';

    // Include query string in cache key (sorted for consistency)
    $query_parts = [];
    if (!empty($_GET)) {
        ksort($_GET);
        foreach ($_GET as $k => $v) {
            // Skip tracking/analytics params
            if (in_array($k, ['utm_source', 'utm_medium', 'utm_campaign', 'fbclid', 'gclid', 'ref'])) {
                continue;
            }
            $query_parts[] = urlencode($k) . '=' . urlencode($v);
        }
    }

    $full_path = $clean_path;
    if (!empty($query_parts)) {
        $full_path .= '?' . implode('&', $query_parts);
    }

    // Use MD5 hash for key (short but unique)
    $path_hash = md5($full_path);

    // Key format: {{site_id}}:cache:page:{hash}
    // Braces tell Lua build_key() to use key as-is (hash slot already specified)
    // Must match ACL pattern {{site_id}}:cache:* or site_id:cache:*
    return "{{$site_id}}:cache:page:{$path_hash}";
}

/**
 * Try to serve cached page from ValKey
 *
 * Called early in template_redirect to bypass WordPress rendering.
 */
function gtemplate_serve_cached_page(): void {
    // Early cache in mu-plugin handles most requests
    // This is fallback for cases where early cache didn't run
    if (defined('GCORE_EARLY_CACHE_INIT') && GCORE_EARLY_CACHE_INIT) {
        return; // Early cache already checked
    }

    if (!gtemplate_page_cache_enabled()) {
        return;
    }

    if (!gtemplate_request_is_cacheable()) {
        return;
    }

    try {
        $gNodeClient = gtemplate_gnode();
        if (!$gNodeClient) {
            return;
        }

        $cache_key = gtemplate_get_page_cache_key();
        $site_id = function_exists('\gTemplate\get_site_id_from_domain')
            ? \gTemplate\get_site_id_from_domain()
            : 'default';

        $cached = $gNodeClient->fcall('GNODE_CACHE_GET', [], [$cache_key, $site_id]);

        if ($cached && is_string($cached)) {
            $data = json_decode($cached, true);
            if ($data && !empty($data['html'])) {
                // Commit 1.11.a: verify HMAC before
                // serving. A ValKey write-primitive (cross-site weak
                // ACL, compromised admin, ACL misconfig) could
                // otherwise plant arbitrary HTML for every anonymous
                // GET on the cached URL. Mismatch → cache miss path.
                $expected = gtemplate_page_cache_hmac(
                    (string) $data['html'],
                    (int) ($data['cached_at'] ?? 0),
                    (string) ($data['url'] ?? '')
                );
                $actual = (string) ($data['hmac'] ?? '');
                if (!hash_equals($expected, $actual)) {
                    gtemplate_track_error('gTemplate: page-cache HMAC mismatch for ' . preg_replace('/[\r\n\t]+/', ' ', (string) ($_SERVER['REQUEST_URI'] ?? '')) . ' — falling through to live render (CB-D2.04)');
                    return;
                }

                // Send cached response.
                // Commit 1.11.a: the previous `headers`
                // replay loop is REMOVED. Any headers the writer
                // wanted carried (Cache-Control, etc.) need to be
                // produced by the live render path; we no longer
                // accept attacker-controlled `Set-Cookie` / `Location`
                // / `Cache-Control` from the cache payload.
                header('Content-Type: text/html; charset=utf-8');
                header('X-Cache: HIT');
                header('X-Cache-Age: ' . (time() - ($data['cached_at'] ?? 0)));

                echo $data['html'];
                exit;
            }
        }
    } catch (\Throwable $e) {
        gtemplate_track_error('gTemplate: Page cache read error: ' . $e->getMessage());
    }
}
add_action('template_redirect', 'gtemplate_serve_cached_page', 1);

/**
 * Start output buffering for cacheable pages
 */
function gtemplate_start_page_cache_buffer(): void {
    if (!gtemplate_page_cache_enabled()) {
        return;
    }

    if (!gtemplate_request_is_cacheable()) {
        return;
    }

    // Start buffering
    ob_start('gtemplate_page_cache_callback');
}
add_action('template_redirect', 'gtemplate_start_page_cache_buffer', 2);

/**
 * Output buffer callback - cache the rendered HTML
 *
 * @param string $buffer The output buffer
 * @return string The buffer (unchanged)
 */
function gtemplate_page_cache_callback(string $buffer): string {
    // Only cache complete HTML pages
    if (empty($buffer) || strpos($buffer, '</html>') === false) {
        return $buffer;
    }

    // Don't cache if page contains errors
    if (strpos($buffer, 'Fatal error') !== false || strpos($buffer, 'Warning:') !== false) {
        return $buffer;
    }

    try {
        $gNodeClient = gtemplate_gnode();
        if (!$gNodeClient) {
            return $buffer;
        }

        $cache_key = gtemplate_get_page_cache_key();
        $site_id = function_exists('\gTemplate\get_site_id_from_domain')
            ? \gTemplate\get_site_id_from_domain()
            : 'default';

        // Add cache marker to HTML
        $cache_marker = "\n<!-- Cached by gTemplate @ " . gmdate('Y-m-d H:i:s') . " UTC -->";
        $cached_html = str_replace('</html>', $cache_marker . "\n</html>", $buffer);

        // Commit 1.11.a: drop the `headers` field from the
        // cache payload entirely (was always `[]` by code path but the
        // serve-side replayed any headers it found, making future
        // expansion of the field a hidden footgun) + HMAC the payload
        // on write so the read side can verify it has not been
        // tampered with.
        $cached_at = time();
        $cache_url = $_SERVER['REQUEST_URI'] ?? '/';
        $cache_data = [
            'html' => $cached_html,
            'cached_at' => $cached_at,
            'url' => $cache_url,
            'hmac' => gtemplate_page_cache_hmac($cached_html, $cached_at, $cache_url),
        ];

        $json = json_encode($cache_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $ttl = 3600;  // 1 hour (matches CacheManager page group config)

        $result = $gNodeClient->fcall('GNODE_CACHE_SET', [], [$cache_key, $json, $ttl, $site_id]);

        if ($result !== false && $result !== null) {
            // Add MISS header for first request
            if (!headers_sent()) {
                header('X-Cache: MISS');
            }
            gtemplate_track_error("gTemplate: Cached page " . ($_SERVER['REQUEST_URI'] ?? '/') . " (" . strlen($json) . " bytes)");
        }

    } catch (\Throwable $e) {
        gtemplate_track_error('gTemplate: Page cache write error: ' . $e->getMessage());
    }

    return $buffer;
}

/**
 * Invalidate full-page cache for a URL
 *
 * @param string $url The URL to invalidate
 */
function gtemplate_invalidate_fullpage_cache(string $url): void {
    try {
        $gNodeClient = gtemplate_gnode();
        if (!$gNodeClient) {
            // A skipped purge keeps serving the stale page for the full TTL after
            // a content fix — never fail silently.
            error_log("gTemplate: PAGE CACHE PURGE SKIPPED for {$url} — no ValKey client (stale page serves until TTL expiry)");
            return;
        }

        $site_id = function_exists('\gTemplate\get_site_id_from_domain')
            ? \gTemplate\get_site_id_from_domain()
            : 'default';

        // Parse URL to get path
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? '/';
        $path_hash = md5($path);
        $cache_key = "{{$site_id}}:cache:page:{$path_hash}";

        $gNodeClient->fcall('GNODE_CACHE_DEL', [], [$cache_key, $site_id]);
        gtemplate_track_error("gTemplate: Invalidated page cache for {$url}");

    } catch (\Throwable $e) {
        gtemplate_track_error('gTemplate: Page cache invalidation error: ' . $e->getMessage());
    }
}

/**
 * Invalidate full-page cache when content changes
 *
 * @param int $post_id
 */
function gtemplate_invalidate_fullpage_cache_on_save(int $post_id): void {
    // Skip revisions
    if (wp_is_post_revision($post_id)) {
        return;
    }

    // Skip autosaves
    if (wp_is_post_autosave($post_id)) {
        return;
    }

    $post = get_post($post_id);
    if (!$post) {
        return;
    }

    // Invalidate the post's permalink
    $permalink = get_permalink($post_id);
    if ($permalink) {
        gtemplate_invalidate_fullpage_cache($permalink);
    }

    // Also invalidate home page (may show this post)
    gtemplate_invalidate_fullpage_cache(home_url('/'));

    // Invalidate archive pages
    if ($post->post_type === 'post') {
        $categories = get_the_category($post_id);
        foreach ($categories as $cat) {
            gtemplate_invalidate_fullpage_cache(get_category_link($cat->term_id));
        }
    }
}
add_action('save_post', 'gtemplate_invalidate_fullpage_cache_on_save', 100);

/**
 * Invalidate all full-page cache (nuclear option)
 *
 * The cache Lua surface has no pattern-delete, so enumerate every cacheable
 * URL and delete each key exactly the way GNODE_CACHE_SET wrote it.
 */
function gtemplate_invalidate_all_fullpage_cache(): void {
    try {
        $gNodeClient = gtemplate_gnode();
        if (!$gNodeClient) {
            error_log('gTemplate: FULL PAGE CACHE PURGE SKIPPED — no ValKey client (stale pages serve until TTL expiry)');
            return;
        }

        foreach (gtemplate_all_cacheable_urls() as $url) {
            gtemplate_invalidate_fullpage_cache($url);
        }

    } catch (\Throwable $e) {
        gtemplate_track_error('gTemplate: Full cache invalidation error: ' . $e->getMessage());
    }
}

/**
 * Every URL the page cache may hold: home, published pages and posts,
 * non-empty category archives.
 *
 * @return string[]
 */
function gtemplate_all_cacheable_urls(): array {
    $urls = [home_url('/')];
    foreach (get_pages(['post_status' => 'publish']) as $p) {
        $urls[] = get_permalink($p);
    }
    foreach (get_posts(['numberposts' => 500, 'post_status' => 'publish']) as $p) {
        $urls[] = get_permalink($p);
    }
    foreach (get_categories(['hide_empty' => true]) as $cat) {
        $link = get_category_link($cat->term_id);
        if (!is_wp_error($link)) {
            $urls[] = $link;
        }
    }
    return array_values(array_unique(array_filter($urls)));
}

// Events scheduled by older builds land here too; run the real purge.
add_action('gtemplate_full_cache_clear_event', 'gtemplate_invalidate_all_fullpage_cache');

// Invalidate full cache on customizer save (theme changes affect all pages)
add_action('customize_save_after', 'gtemplate_invalidate_all_fullpage_cache', 100);
