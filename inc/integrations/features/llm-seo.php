<?php
declare(strict_types=1);
/**
 * LLM/SEO Integration for gTemplate (AIO - AI Optimization)
 *
 * WordPress-specific wrapper for gCore's SEOManager AIO features.
 * Provides llms.txt endpoints, AI meta generation on save, and REST API.
 *
 * Features:
 * - /llms.txt and /llms-full.txt endpoints
 * - /wp-json/gtemplate/v1/llm-context REST endpoint
 * - Auto-generate AI meta on post save (async)
 * - AI-specific meta tags in wp_head
 * - FAQPage schema from AI-generated FAQ pairs
 *
 * @package gTemplate
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// =============================================================================
// CONFIGURATION
// =============================================================================

/**
 * Check if AIO (AI Optimization) features are enabled
 *
 * @return bool
 */
function gtemplate_aio_is_enabled(): bool {
    // Check if explicitly disabled
    if (defined('GTEMPLATE_DISABLE_AIO') && GTEMPLATE_DISABLE_AIO) {
        return false;
    }

    // Check if SEOManager has AIO enabled (uses isGeoEnabled internally)
    $seo = gtemplate_get_seo_manager();
    if ($seo && method_exists($seo, 'isGeoEnabled')) {
        return $seo->isGeoEnabled();
    }

    return false;
}

// Backwards compatibility alias
function gtemplate_geo_is_enabled(): bool {
    return gtemplate_aio_is_enabled();
}

/**
 * Get site configuration for llms.txt generation
 *
 * @return array
 */
function gtemplate_get_llms_site_config(): array {
    return [
        'name' => get_bloginfo('name'),
        'description' => get_bloginfo('description'),
        'url' => home_url(),
        'details' => apply_filters('gtemplate_llms_site_details', sprintf(
            "A WordPress site powered by gTemplate theme.\n\n" .
            "Technologies: WordPress, PHP, gCore Framework, gNode, HTMX\n" .
            "Contact: %s",
            get_option('admin_email')
        )),
        'optional_pages' => apply_filters('gtemplate_llms_optional_pages', [])
    ];
}

// =============================================================================
// LLMS.TXT ENDPOINTS
// =============================================================================

/**
 * Register rewrite rules for llms.txt files
 */
add_action('init', function() {
    add_rewrite_rule('^llms\.txt$', 'index.php?gtemplate_llms_txt=1', 'top');
    add_rewrite_rule('^llms-full\.txt$', 'index.php?gtemplate_llms_full_txt=1', 'top');
});

/**
 * Add query vars
 */
add_filter('query_vars', function($vars) {
    $vars[] = 'gtemplate_llms_txt';
    $vars[] = 'gtemplate_llms_full_txt';
    return $vars;
});

/**
 * Handle llms.txt request
 */
add_action('template_redirect', function() {
    $isLlmsTxt = get_query_var('gtemplate_llms_txt');
    $isLlmsFullTxt = get_query_var('gtemplate_llms_full_txt');

    if (!$isLlmsTxt && !$isLlmsFullTxt) {
        return;
    }

    $seo = gtemplate_get_seo_manager();
    $siteConfig = gtemplate_get_llms_site_config();

    // Gather pages for llms.txt
    $pages = gtemplate_get_llms_pages();

    header('Content-Type: text/markdown; charset=UTF-8');
    header('X-Robots-Tag: noindex');
    header('Cache-Control: public, max-age=3600');

    if ($seo && method_exists($seo, 'generateLLMsTxt')) {
        if ($isLlmsFullTxt) {
            // Full version with content
            $pagesWithContent = gtemplate_get_llms_pages_with_content();
            echo $seo->generateLLMsFullTxt($siteConfig, $pagesWithContent);
        } else {
            // Standard llms.txt
            echo $seo->generateLLMsTxt($siteConfig, $pages);
        }
    } else {
        // Fallback if SEOManager not available
        echo gtemplate_generate_llms_txt_fallback($siteConfig, $pages);
    }

    exit;
});

/**
 * Get pages for llms.txt
 *
 * @return array
 */
function gtemplate_get_llms_pages(): array {
    $pages = [];

    // Add cube faces
    $faceConfigs = gtemplate_get_all_face_configs();
    foreach ($faceConfigs as $faceId => $config) {
        $label = $config['nav_label'] ?? 'Face ' . $faceId;
        $pages[] = [
            'title' => $label,
            'url' => home_url('/?face=' . $faceId),
            'type' => 'Cube Faces',
            'description' => ''
        ];
    }

    // Add published pages
    $wpPages = get_posts([
        'post_type' => 'page',
        'post_status' => 'publish',
        'numberposts' => 50,
        'orderby' => 'menu_order',
        'order' => 'ASC'
    ]);

    foreach ($wpPages as $page) {
        $pages[] = [
            'title' => $page->post_title,
            'url' => get_permalink($page),
            'type' => 'Pages',
            'description' => $page->post_excerpt ?: ''
        ];
    }

    // Add recent posts
    $posts = get_posts([
        'post_type' => 'post',
        'post_status' => 'publish',
        'numberposts' => 20,
        'orderby' => 'date',
        'order' => 'DESC'
    ]);

    foreach ($posts as $post) {
        $pages[] = [
            'title' => $post->post_title,
            'url' => get_permalink($post),
            'type' => 'Posts',
            'description' => $post->post_excerpt ?: ''
        ];
    }

    return apply_filters('gtemplate_llms_pages', $pages);
}

/**
 * Get pages with full content for llms-full.txt
 *
 * @return array
 */
function gtemplate_get_llms_pages_with_content(): array {
    $pages = gtemplate_get_llms_pages();

    foreach ($pages as &$page) {
        // Try to get AI meta if available
        $postId = url_to_postid($page['url']);
        if ($postId) {
            $aiMeta = get_post_meta($postId, 'gtemplate_ai_meta', true);
            if ($aiMeta && is_array($aiMeta)) {
                $page['tldr'] = $aiMeta['tldr'] ?? '';
                $page['spr'] = $aiMeta['spr'] ?? '';
            }

            // Get content
            $post = get_post($postId);
            if ($post) {
                $page['content'] = wp_strip_all_tags($post->post_content);
            }
        }
    }

    return $pages;
}

/**
 * Fallback llms.txt generation if SEOManager not available
 *
 * @param array $siteConfig
 * @param array $pages
 * @return string
 */
function gtemplate_generate_llms_txt_fallback(array $siteConfig, array $pages): string {
    $content = "# " . ($siteConfig['name'] ?? 'Website') . "\n\n";

    if (!empty($siteConfig['description'])) {
        $content .= "> " . $siteConfig['description'] . "\n\n";
    }

    if (!empty($siteConfig['details'])) {
        $content .= $siteConfig['details'] . "\n\n";
    }

    // Group pages by type
    $grouped = [];
    foreach ($pages as $page) {
        $type = $page['type'] ?? 'Pages';
        $grouped[$type][] = $page;
    }

    foreach ($grouped as $type => $typePages) {
        $content .= "## {$type}\n\n";
        foreach ($typePages as $page) {
            $content .= "- [" . ($page['title'] ?? 'Untitled') . "](" . ($page['url'] ?? '#') . ")";
            if (!empty($page['description'])) {
                $content .= ": " . $page['description'];
            }
            $content .= "\n";
        }
        $content .= "\n";
    }

    return $content;
}

// =============================================================================
// REST API ENDPOINTS
// =============================================================================

add_action('rest_api_init', function() {
    // Site-wide LLM context
    register_rest_route(gtemplate_get_rest_namespace(), '/llm-context', [
        'methods' => 'GET',
        'callback' => 'gtemplate_rest_llm_context_site',
        'permission_callback' => '__return_true',
    ]);

    // Per-post LLM context
    register_rest_route(gtemplate_get_rest_namespace(), '/llm-context/(?P<id>\d+)', [
        'methods' => 'GET',
        'callback' => 'gtemplate_rest_llm_context_post',
        'permission_callback' => '__return_true',
        'args' => [
            'id' => [
                'validate_callback' => function($param) {
                    return is_numeric($param);
                }
            ]
        ]
    ]);

    // Regenerate AI meta for a post (requires auth)
    register_rest_route(gtemplate_get_rest_namespace(), '/llm-context/(?P<id>\d+)/regenerate', [
        'methods' => 'POST',
        'callback' => 'gtemplate_rest_regenerate_ai_meta',
        'permission_callback' => function() {
            return current_user_can('edit_posts');
        },
        'args' => [
            'id' => [
                'validate_callback' => function($param) {
                    return is_numeric($param);
                }
            ]
        ]
    ]);
});

/**
 * REST callback: Get site-wide LLM context
 */
function gtemplate_rest_llm_context_site(WP_REST_Request $request): WP_REST_Response {
    $siteConfig = gtemplate_get_llms_site_config();
    $pages = gtemplate_get_llms_pages();

    // Aggregate FAQ pairs from all posts with AI meta
    $aggregatedFaq = [];
    $aggregatedEntities = [];

    $postsWithMeta = get_posts([
        'post_type' => ['post', 'page'],
        'post_status' => 'publish',
        'numberposts' => 50,
        'meta_key' => 'gtemplate_ai_meta',
    ]);

    foreach ($postsWithMeta as $post) {
        $aiMeta = get_post_meta($post->ID, 'gtemplate_ai_meta', true);
        if ($aiMeta && is_array($aiMeta)) {
            if (!empty($aiMeta['faq'])) {
                $aggregatedFaq = array_merge($aggregatedFaq, array_slice($aiMeta['faq'], 0, 2));
            }
            if (!empty($aiMeta['entities'])) {
                foreach ($aiMeta['entities'] as $entity) {
                    $key = $entity['name'] ?? '';
                    if ($key && !isset($aggregatedEntities[$key])) {
                        $aggregatedEntities[$key] = $entity;
                    }
                }
            }
        }
    }

    return new WP_REST_Response([
        'success' => true,
        'version' => '1.0.0',
        'site' => [
            'name' => $siteConfig['name'],
            'description' => $siteConfig['description'],
            'url' => $siteConfig['url'],
        ],
        'pages' => array_map(function($page) {
            return [
                'title' => $page['title'],
                'url' => $page['url'],
                'type' => $page['type'],
            ];
        }, $pages),
        'faq' => array_slice($aggregatedFaq, 0, 20),
        'entities' => array_values(array_slice($aggregatedEntities, 0, 30)),
        'generated_at' => time(),
    ], 200);
}

/**
 * REST callback: Get LLM context for specific post
 */
function gtemplate_rest_llm_context_post(WP_REST_Request $request): WP_REST_Response {
    $postId = (int) $request->get_param('id');
    $post = get_post($postId);

    if (!$post || $post->post_status !== 'publish') {
        return new WP_REST_Response([
            'success' => false,
            'error' => 'Post not found or not published'
        ], 404);
    }

    // Get cached AI meta
    $aiMeta = get_post_meta($postId, 'gtemplate_ai_meta', true);

    if (!$aiMeta || !is_array($aiMeta)) {
        // Generate on-the-fly if not cached
        $aiMeta = gtemplate_generate_ai_meta_for_post($postId);
    }

    if (!$aiMeta || !($aiMeta['success'] ?? false)) {
        return new WP_REST_Response([
            'success' => false,
            'error' => 'AI metadata not available',
            'content_id' => 'post-' . $postId
        ], 200);
    }

    // Build response
    $author = get_the_author_meta('display_name', $post->post_author);

    return new WP_REST_Response([
        'success' => true,
        'version' => '1.0.0',
        'content_id' => 'post-' . $postId,
        'generated_at' => $aiMeta['generated_at'] ?? time(),

        'summary' => [
            'tldr' => $aiMeta['tldr'] ?? '',
            'description' => $aiMeta['description'] ?? '',
        ],

        'structured' => [
            'faq' => $aiMeta['faq'] ?? [],
            'entities' => $aiMeta['entities'] ?? [],
        ],

        'spr' => $aiMeta['spr'] ?? '',

        'meta' => [
            'title' => $post->post_title,
            'url' => get_permalink($post),
            'author' => $author,
            'date_published' => get_the_date('c', $post),
            'date_modified' => get_the_modified_date('c', $post),
        ],

        'cached' => $aiMeta['cached'] ?? true,
    ], 200);
}

/**
 * REST callback: Regenerate AI meta for post
 */
function gtemplate_rest_regenerate_ai_meta(WP_REST_Request $request): WP_REST_Response {
    $postId = (int) $request->get_param('id');
    $post = get_post($postId);

    if (!$post) {
        return new WP_REST_Response([
            'success' => false,
            'error' => 'Post not found'
        ], 404);
    }

    // Force regeneration
    $aiMeta = gtemplate_generate_ai_meta_for_post($postId, ['force_regenerate' => true]);

    if ($aiMeta && ($aiMeta['success'] ?? false)) {
        return new WP_REST_Response([
            'success' => true,
            'message' => 'AI metadata regenerated',
            'content_id' => 'post-' . $postId,
            'faq_count' => count($aiMeta['faq'] ?? []),
            'entity_count' => count($aiMeta['entities'] ?? []),
        ], 200);
    }

    return new WP_REST_Response([
        'success' => false,
        'error' => $aiMeta['error'] ?? 'Generation failed'
    ], 500);
}

// =============================================================================
// AI META GENERATION ON SAVE
// =============================================================================

/**
 * Generate AI metadata for a post
 *
 * @param int $postId
 * @param array $options
 * @return array|null
 */
function gtemplate_generate_ai_meta_for_post(int $postId, array $options = []): ?array {
    $post = get_post($postId);
    if (!$post) {
        return null;
    }

    $seo = gtemplate_get_seo_manager();
    if (!$seo || !method_exists($seo, 'generateAIMeta')) {
        return null;
    }

    // Strip HTML and prepare content
    $content = wp_strip_all_tags($post->post_content);

    // Don't process empty or very short content
    if (strlen($content) < 100) {
        return [
            'success' => false,
            'error' => 'Content too short for AI analysis',
            'content_id' => 'post-' . $postId
        ];
    }

    $author = get_the_author_meta('display_name', $post->post_author);

    $result = $seo->generateAIMeta('post-' . $postId, $content, array_merge([
        'title' => $post->post_title,
        'url' => get_permalink($post),
        'author' => $author,
        'date_published' => get_the_date('c', $post),
        'date_modified' => get_the_modified_date('c', $post),
    ], $options));

    if ($result && ($result['success'] ?? false)) {
        // Store in post meta
        update_post_meta($postId, 'gtemplate_ai_meta', $result);
        gtemplate_track_error("[gTemplate] AI meta generated for post {$postId}");
    }

    return $result;
}

/**
 * Hook into save_post to generate AI meta
 *
 * Note: Generation is expensive, so we only trigger for published content
 * and allow disabling via filter.
 */
add_action('save_post', function($postId, $post, $update) {
    // Skip autosaves and revisions
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (wp_is_post_revision($postId)) {
        return;
    }

    // Only process published posts/pages
    if ($post->post_status !== 'publish') {
        return;
    }

    // Only process post and page types by default
    $allowedTypes = apply_filters('gtemplate_aio_post_types', ['post', 'page']);
    if (!in_array($post->post_type, $allowedTypes)) {
        return;
    }

    // Allow disabling auto-generation
    if (!apply_filters('gtemplate_aio_auto_generate', true, $postId, $post)) {
        return;
    }

    // Check if AIO is enabled
    if (!gtemplate_aio_is_enabled()) {
        return;
    }

    // Check if content has changed (compare hash)
    $contentHash = md5($post->post_content);
    $previousHash = get_post_meta($postId, 'gtemplate_content_hash', true);

    if ($previousHash === $contentHash) {
        // Content unchanged, skip regeneration
        return;
    }

    // Update hash
    update_post_meta($postId, 'gtemplate_content_hash', $contentHash);

    // Schedule async generation (don't block save)
    if (function_exists('wp_schedule_single_event')) {
        wp_schedule_single_event(time() + 5, 'gtemplate_generate_ai_meta_async', [$postId]);
        gtemplate_track_error("[gTemplate] Scheduled AI meta generation for post {$postId}");
    } else {
        // Fallback: generate synchronously
        gtemplate_generate_ai_meta_for_post($postId);
    }

}, 20, 3);

/**
 * Async AI meta generation handler
 */
add_action('gtemplate_generate_ai_meta_async', function($postId) {
    gtemplate_generate_ai_meta_for_post($postId);
});

// =============================================================================
// AI META TAGS IN WP_HEAD
// =============================================================================

/**
 * Output AI-specific meta tags
 */
add_action('wp_head', function() {
    if (!is_singular()) {
        return;
    }

    $postId = get_queried_object_id();
    if (!$postId) {
        return;
    }

    $aiMeta = get_post_meta($postId, 'gtemplate_ai_meta', true);
    if (!$aiMeta || !is_array($aiMeta) || !($aiMeta['success'] ?? false)) {
        return;
    }

    // Output AI-specific meta tags
    echo "\n<!-- gTemplate AIO Meta -->\n";

    // AI summary (for crawlers that may look for this)
    if (!empty($aiMeta['tldr'])) {
        printf(
            '<meta name="ai-summary" content="%s">' . "\n",
            esc_attr($aiMeta['tldr'])
        );
    }

    // AI description (conversational)
    if (!empty($aiMeta['description'])) {
        printf(
            '<meta name="ai-description" content="%s">' . "\n",
            esc_attr($aiMeta['description'])
        );
    }

    // Entity keywords
    if (!empty($aiMeta['entities'])) {
        $entityNames = array_map(function($e) {
            return $e['name'] ?? '';
        }, $aiMeta['entities']);
        $entityNames = array_filter($entityNames);

        if (!empty($entityNames)) {
            printf(
                '<meta name="ai-entities" content="%s">' . "\n",
                esc_attr(implode(', ', $entityNames))
            );
        }
    }

    // FAQ count indicator
    if (!empty($aiMeta['faq'])) {
        printf(
            '<meta name="ai-faq-count" content="%d">' . "\n",
            count($aiMeta['faq'])
        );
    }

    // Link to LLM context API
    printf(
        '<link rel="ai-context" href="%s">' . "\n",
        esc_url(rest_url(gtemplate_get_rest_namespace() . '/llm-context/' . $postId))
    );

    echo "<!-- /gTemplate AIO Meta -->\n\n";

}, 4); // Priority 4 = before main SEO tags

// =============================================================================
// FAQPAGE SCHEMA OUTPUT
// =============================================================================

/**
 * Output FAQPage schema from AI-generated FAQ pairs
 */
add_action('wp_head', function() {
    if (!is_singular()) {
        return;
    }

    $postId = get_queried_object_id();
    if (!$postId) {
        return;
    }

    $aiMeta = get_post_meta($postId, 'gtemplate_ai_meta', true);
    if (!$aiMeta || empty($aiMeta['faq'])) {
        return;
    }

    $seo = gtemplate_get_seo_manager();
    if ($seo && method_exists($seo, 'generateFAQPageSchema')) {
        $schema = $seo->generateFAQPageSchema($aiMeta['faq']);
        if (!empty($schema)) {
            echo '<script type="application/ld+json">';
            echo wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            echo '</script>' . "\n";
        }
    }

}, 7); // Priority 7 = after main schema

// =============================================================================
// ARTICLE SCHEMA WITH ENTITIES
// =============================================================================

/**
 * Output enhanced Article schema with entity mentions
 */
add_action('wp_head', function() {
    if (!is_singular(['post'])) {
        return;
    }

    $postId = get_queried_object_id();
    $post = get_post($postId);
    if (!$post) {
        return;
    }

    $aiMeta = get_post_meta($postId, 'gtemplate_ai_meta', true);
    $entities = ($aiMeta && !empty($aiMeta['entities'])) ? $aiMeta['entities'] : [];

    $seo = gtemplate_get_seo_manager();
    if (!$seo || !method_exists($seo, 'generateArticleSchemaWithEntities')) {
        return;
    }

    $author = get_the_author_meta('display_name', $post->post_author);
    $image = get_the_post_thumbnail_url($postId, 'large');

    $articleData = [
        'title' => $post->post_title,
        'description' => $aiMeta['description'] ?? $post->post_excerpt,
        'date_published' => get_the_date('c', $post),
        'date_modified' => get_the_modified_date('c', $post),
        'author' => $author,
        'url' => get_permalink($post),
        'image' => $image ?: null,
    ];

    $schema = $seo->generateArticleSchemaWithEntities($articleData, $entities);

    if (!empty($schema)) {
        echo '<script type="application/ld+json">';
        echo wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        echo '</script>' . "\n";
    }

}, 8); // Priority 8 = after FAQ schema

// =============================================================================
// FLUSH REWRITE RULES ON ACTIVATION
// =============================================================================

add_action('after_switch_theme', function() {
    // Re-add rewrite rules
    add_rewrite_rule('^llms\.txt$', 'index.php?gtemplate_llms_txt=1', 'top');
    add_rewrite_rule('^llms-full\.txt$', 'index.php?gtemplate_llms_full_txt=1', 'top');
    flush_rewrite_rules();
});

// =============================================================================
// ADMIN COLUMN FOR AI META STATUS
// =============================================================================

/**
 * Add column to show AI meta status in post list
 *
 * Only shown when AIO is active. Under the base-tier stub AI meta is never
 * generated, so the column would be permanently empty ("—") and advertise a
 * feature that isn't present.
 */
add_filter('manage_posts_columns', function($columns) {
    if (!gtemplate_aio_is_enabled()) {
        return $columns;
    }
    $columns['gtemplate_ai_meta'] = 'AI Meta';
    return $columns;
});

add_filter('manage_pages_columns', function($columns) {
    if (!gtemplate_aio_is_enabled()) {
        return $columns;
    }
    $columns['gtemplate_ai_meta'] = 'AI Meta';
    return $columns;
});

add_action('manage_posts_custom_column', 'gtemplate_render_ai_meta_column', 10, 2);
add_action('manage_pages_custom_column', 'gtemplate_render_ai_meta_column', 10, 2);

function gtemplate_render_ai_meta_column($column, $postId) {
    if ($column !== 'gtemplate_ai_meta') {
        return;
    }

    $aiMeta = get_post_meta($postId, 'gtemplate_ai_meta', true);

    if ($aiMeta && is_array($aiMeta) && ($aiMeta['success'] ?? false)) {
        $faqCount = count($aiMeta['faq'] ?? []);
        $entityCount = count($aiMeta['entities'] ?? []);
        $date = isset($aiMeta['generated_at']) ? date('M j', $aiMeta['generated_at']) : '?';

        echo '<span style="color: green;">&#10003;</span> ';
        echo "<small>{$faqCount} FAQ, {$entityCount} entities ({$date})</small>";
    } else {
        echo '<span style="color: #999;">&#8212;</span>';
    }
}

// =============================================================================
// INITIALIZATION
// =============================================================================

