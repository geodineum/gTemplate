<?php
declare(strict_types=1);
/**
 * SEOManager Integration for gTemplate
 *
 * Integrates gCore SEOManager for OpenGraph, Twitter Cards, and Schema.org structured data
 * for cube faces and WordPress content.
 *
 * @package gTemplate
 * @version 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Initialize SEOManager for gTemplate
 *
 * Called during theme initialization after gCore is available.
 *
 * @return \gCore\Modules\Managers\Base\SEOManager\SEOManager|null
 */
function gtemplate_init_seo_manager() {
    global $gCore;

    if (!$gCore) {
        return null;
    }

    try {
        $seo = $gCore->getService('SEOManager');
        if (!$seo) {
            gtemplate_track_error('gTemplate: SEOManager not available from gCore');
            return null;
        }

        // Check if already initialized
        if ($seo->isInitialized()) {
            return $seo;
        }

        // Get gNode client for broadcasting
        $gNode = gtemplate_gnode();

        $seo->initialize([
            'site_id' => gtemplate_get_site_id(),
            'node_id' => 'web-' . gethostname(),
            'gnode_client' => $gNode,
            'use_gnode' => ($gNode !== null),
            'enable_og' => true,
            'enable_twitter' => true,
            'enable_schema' => true,
            'sitemap_url' => home_url('/sitemap-gtemplate.xml'),
            'default_ttl' => 3600,
            'debug' => defined('WP_DEBUG') && WP_DEBUG
        ]);

        gtemplate_track_error('gTemplate: SEOManager initialized successfully');
        return $seo;

    } catch (\Throwable $e) {
        gtemplate_track_error('gTemplate: SEO init error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Get the SEOManager instance
 *
 * @return \gCore\Modules\Managers\Base\SEOManager\SEOManager|null
 */
function gtemplate_get_seo_manager() {
    global $gCore;

    if (!$gCore) {
        return null;
    }

    try {
        $seo = $gCore->getService('SEOManager');
        return ($seo && $seo->isInitialized()) ? $seo : null;
    } catch (\Throwable $e) {
        // Service-registry-not-ready (early init / late shutdown). Caller
        // checks for null and degrades gracefully; logging would be noise.
        return null;
    }
}

/**
 * Output SEO meta tags in wp_head
 *
 * Generates OpenGraph, Twitter Cards, and Schema.org for current page/face.
 * Named function so child themes can remove_action() to avoid duplicates.
 */
function gtemplate_output_seo_meta_tags() {
    // Child themes set this flag to suppress parent's duplicate SEO output
    if (apply_filters('gtemplate_child_seo_active', false)) {
        return;
    }

    $seo = gtemplate_get_seo_manager();
    if (!$seo) {
        return;
    }

    // Determine what content we're showing
    $face_id = isset($_GET['face']) ? absint($_GET['face']) : null;
    $page_id = get_queried_object_id();

    // Get face config if applicable
    $face_config = null;
    if ($face_id !== null && $face_id >= 0 && $face_id <= 5) {
        $all_configs = gtemplate_get_all_face_configs();
        $face_config = $all_configs[$face_id] ?? null;
    }

    // Build meta data
    $title = get_bloginfo('name');
    $description = get_bloginfo('description');
    $image = get_site_icon_url(512);
    $url = home_url($_SERVER['REQUEST_URI'] ?? '/');
    $type = 'website';

    if ($face_config) {
        $face_label = $face_config['nav_label'] ?? 'Face ' . $face_id;
        $title = $face_label . ' | ' . get_bloginfo('name');

        // Try to get description from face content
        if ($face_config['source'] === 'page' && $face_config['content_id'] > 0) {
            $page = get_post($face_config['content_id']);
            if ($page) {
                $description = $page->post_excerpt ?: wp_trim_words(strip_tags($page->post_content), 30);
                $thumb = get_the_post_thumbnail_url($face_config['content_id'], 'large');
                if ($thumb) {
                    $image = $thumb;
                }
            }
        } elseif ($face_config['source'] === 'post' && $face_config['content_id'] > 0) {
            $post = get_post($face_config['content_id']);
            if ($post) {
                $description = $post->post_excerpt ?: wp_trim_words(strip_tags($post->post_content), 30);
                $thumb = get_the_post_thumbnail_url($face_config['content_id'], 'large');
                if ($thumb) {
                    $image = $thumb;
                }
                $type = 'article';
            }
        } elseif (!empty($face_config['custom_html'])) {
            $description = wp_trim_words(strip_tags($face_config['custom_html']), 30);
        }
    } elseif ($page_id && is_singular()) {
        $post = get_post($page_id);
        if ($post) {
            $title = $post->post_title . ' | ' . get_bloginfo('name');
            $description = $post->post_excerpt ?: wp_trim_words(strip_tags($post->post_content), 30);
            $thumb = get_the_post_thumbnail_url($page_id, 'large');
            if ($thumb) {
                $image = $thumb;
            }
            $type = ($post->post_type === 'post') ? 'article' : 'website';
        }
    }

    // Set meta tags (this also generates OG and Twitter internally)
    $page_key = $face_config ? 'face-' . $face_id : 'page-' . ($page_id ?: 'home');

    try {
        $seo->setMetaTags($page_key, [
            'title' => $title,
            'description' => $description,
            'image' => $image,
            'url' => $url,
            'type' => $type
        ]);

        // Get the cached meta data with OG and Twitter
        $meta = $seo->getMetaTags($page_key);

        if (!$meta) {
            return;
        }

        // Output OpenGraph tags
        if (!empty($meta['og'])) {
            foreach ($meta['og'] as $property => $content) {
                if (!empty($content)) {
                    printf(
                        '<meta property="%s" content="%s">' . "\n",
                        esc_attr($property),
                        esc_attr($content)
                    );
                }
            }
        }

        // Output Twitter Card tags
        if (!empty($meta['twitter'])) {
            foreach ($meta['twitter'] as $name => $content) {
                if (!empty($content)) {
                    printf(
                        '<meta name="%s" content="%s">' . "\n",
                        esc_attr($name),
                        esc_attr($content)
                    );
                }
            }
        }

        // Generate and output Schema.org structured data
        $schema_data = [
            'name' => $title,
            'description' => $description,
            'url' => $url,
            'image' => $image,
            'isPartOf' => [
                '@type' => 'WebSite',
                'name' => get_bloginfo('name'),
                'url' => home_url()
            ]
        ];

        $schema = $seo->generateSchema('WebPage', $schema_data);
        echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";

    } catch (\Throwable $e) {
        gtemplate_track_error('gTemplate SEO: Meta tag generation error: ' . $e->getMessage());
    }

}
add_action('wp_head', 'gtemplate_output_seo_meta_tags', 5);

/**
 * Register rewrite rule for cube sitemap
 */
add_action('init', function() {
    add_rewrite_rule('^sitemap-gtemplate\.xml$', 'index.php?gtemplate_sitemap=1', 'top');
});

/**
 * Add query var for sitemap
 */
add_filter('query_vars', function($vars) {
    $vars[] = 'gtemplate_sitemap';
    return $vars;
});

/**
 * Handle sitemap request
 */
add_action('template_redirect', function() {
    if (!get_query_var('gtemplate_sitemap')) {
        return;
    }

    $seo = gtemplate_get_seo_manager();

    header('Content-Type: application/xml; charset=UTF-8');
    header('X-Robots-Tag: noindex');

    $pages = [];
    $configs = gtemplate_get_all_face_configs();

    // Add each face to sitemap
    foreach ($configs as $face_id => $config) {
        $priority = 0.8;
        $changefreq = 'weekly';

        // Front face (1) gets highest priority
        if ($face_id === 1) {
            $priority = 1.0;
            $changefreq = 'daily';
        }

        // Get last modified time from content if available
        $lastmod = time();
        if (($config['source'] === 'page' || $config['source'] === 'post') && $config['content_id'] > 0) {
            $post = get_post($config['content_id']);
            if ($post) {
                $lastmod = strtotime($post->post_modified);
            }
        }

        $pages[] = [
            'url' => home_url('/?face=' . $face_id),
            'lastmod' => $lastmod,
            'changefreq' => $changefreq,
            'priority' => $priority
        ];
    }

    // Add homepage
    $pages[] = [
        'url' => home_url('/'),
        'lastmod' => time(),
        'changefreq' => 'daily',
        'priority' => 1.0
    ];

    if ($seo) {
        echo $seo->generateSitemap($pages);
    } else {
        // Fallback sitemap generation
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($pages as $page) {
            echo '  <url>' . "\n";
            echo '    <loc>' . esc_url($page['url']) . '</loc>' . "\n";
            echo '    <lastmod>' . date('c', $page['lastmod']) . '</lastmod>' . "\n";
            echo '    <changefreq>' . esc_html($page['changefreq']) . '</changefreq>' . "\n";
            echo '    <priority>' . number_format($page['priority'], 1) . '</priority>' . "\n";
            echo '  </url>' . "\n";
        }
        echo '</urlset>';
    }

    exit;
});

/**
 * Add Organization schema to homepage
 */
add_action('wp_head', function() {
    if (apply_filters('gtemplate_child_seo_active', false)) {
        return;
    }

    // Only on homepage
    if (!is_front_page() && !is_home()) {
        return;
    }

    $seo = gtemplate_get_seo_manager();
    if (!$seo) {
        return;
    }

    try {
        $org_data = [
            'name' => get_bloginfo('name'),
            'description' => get_bloginfo('description'),
            'url' => home_url(),
            'logo' => get_site_icon_url(512)
        ];

        $schema = $seo->generateSchema('Organization', $org_data);
        echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";

    } catch (\Throwable $e) {
        gtemplate_track_error('gTemplate SEO: Organization schema error: ' . $e->getMessage());
    }
}, 6);  // Priority 6 = after main schema

/**
 * Flush rewrite rules on theme activation
 */
add_action('after_switch_theme', function() {
    // Re-add the rewrite rule
    add_rewrite_rule('^sitemap-gtemplate\.xml$', 'index.php?gtemplate_sitemap=1', 'top');
    flush_rewrite_rules();
});

/**
 * Add canonical URL for cube faces
 */
add_action('wp_head', function() {
    $face_id = isset($_GET['face']) ? absint($_GET['face']) : null;

    if ($face_id !== null && $face_id >= 0 && $face_id <= 5) {
        // Set canonical URL for face
        $canonical = home_url('/?face=' . $face_id);
        echo '<link rel="canonical" href="' . esc_url($canonical) . '">' . "\n";
    }
}, 1);  // Priority 1 = very early

/**
 * Invalidate SEO cache when content changes
 */
add_action('save_post', function($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (wp_is_post_revision($post_id)) {
        return;
    }

    $seo = gtemplate_get_seo_manager();
    if (!$seo) {
        return;
    }

    // Check if this post is used in any cube face
    $configs = gtemplate_get_all_face_configs();
    foreach ($configs as $face_id => $config) {
        if ($config['content_id'] == $post_id) {
            // Invalidate the face's SEO cache by setting new meta
            // This will update the cache with fresh data on next request
            gtemplate_track_error("gTemplate SEO: Post {$post_id} updated, face {$face_id} SEO will refresh on next request");
            break;
        }
    }
}, 20);
