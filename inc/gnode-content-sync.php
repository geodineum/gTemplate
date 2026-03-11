<?php
/**
 * WordPress → gNode Template Synchronization
 *
 * Automatically converts WordPress pages to Tera templates and registers
 * them with gNode daemon for ultra-fast rendering (<50ms).
 *
 * This is the core of gTemplate's dynamic content loading system, enabling:
 * - Blazing fast face rendering via gNode
 * - Smooth iOS scrolling (60fps, no lag)
 * - HTMX lazy loading (progressive enhancement)
 * - WordPress WYSIWYG content editing
 *
 * @package gTemplate
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Convert WordPress page to Tera template
 *
 * Generates a Tera template with WordPress content, featured images,
 * and metadata. Optimized for face rendering.
 *
 * @param int $post_id WordPress page ID
 * @return string|null Tera template content or null on failure
 */
function gtemplate_page_to_tera_template($post_id) {
    $post = get_post($post_id);

    if (!$post || $post->post_type !== 'page') {
        return null;
    }

    // Build Tera template with WordPress content
    // Uses Tera syntax for dynamic variables
    $template = <<<'TERA'
{# Auto-generated from WordPress Page #}
{# Page ID: {{ page_id }} - {{ title }} #}
<div class="cube-face-content wordpress-page" data-page-id="{{ page_id }}" data-slug="{{ slug }}">
    <header class="face-header">
        <h2 class="face-title">{{ title }}</h2>

        {% if featured_image %}
        <div class="face-featured-image">
            <img src="{{ featured_image }}"
                 alt="{{ title }}"
                 loading="lazy"
                 width="{{ featured_image_width }}"
                 height="{{ featured_image_height }}">
        </div>
        {% endif %}
    </header>

    <main class="face-body">
        {% if excerpt %}
        <div class="page-excerpt">
            <p>{{ excerpt }}</p>
        </div>
        {% endif %}

        <div class="page-content">
            {{ content | safe }}
        </div>

        {% if author or date %}
        <div class="page-meta">
            {% if author %}
            <span class="author">By {{ author }}</span>
            {% endif %}
            {% if date %}
            <time class="date" datetime="{{ date_iso }}">{{ date }}</time>
            {% endif %}
        </div>
        {% endif %}
    </main>

    <footer class="face-footer">
        {% if permalink %}
        <a href="{{ permalink }}" class="read-more">
            Read Full Page <span aria-hidden="true">&rarr;</span>
        </a>
        {% endif %}

        <small class="site-credit">
            <span>{{ blog_name }}</span>
            {% if updated %}
            <span class="updated">Updated: {{ updated }}</span>
            {% endif %}
        </small>
    </footer>
</div>
TERA;

    return $template;
}

/**
 * Register WordPress page template with gNode daemon
 *
 * Converts page to Tera template and registers it for server-side rendering.
 * Includes distributed locking to prevent race conditions.
 *
 * @param int $post_id WordPress page ID
 * @return bool Success status
 */
function gtemplate_register_page_template($post_id) {
    global $gCore;

    $post = get_post($post_id);

    if (!$post || $post->post_type !== 'page' || $post->post_status !== 'publish') {
        return false;
    }

    $gNode = gtemplate_gnode();
    if (!$gNode) {
        error_log("gTemplate: Cannot register page template, gNode unavailable (page: {$post->post_title})");
        return false;
    }

    // Acquire distributed lock for template registration
    $cache = null;
    $lock = null;

    try {
        $cache = $gCore->getService('Cache');

        if ($cache) {
            $lock_key = "gtemplate:page_template_reg:{$post_id}";
            $lock = $cache->acquireLock($lock_key, 15);  // 15 second timeout

            if (!$lock) {
                error_log("gTemplate: Template registration for page {$post_id} already in progress");
                return false;
            }
        }
    } catch (\Throwable $e) {
        error_log("gTemplate: Lock acquisition failed, proceeding anyway: " . $e->getMessage());
    }

    try {
        // Generate Tera template
        $template_content = gtemplate_page_to_tera_template($post_id);

        if (!$template_content) {
            return false;
        }

        // Template ID format: wp_page_{post_id}
        $template_id = "wp_page_{$post_id}";

        // Register with gNode
        $result = $gNode->registerTemplate($template_id, $template_content);

        if ($result) {
            error_log("gTemplate: Registered template '{$template_id}' for page: {$post->post_title}");

            // Cache template variables for fast rendering
            gtemplate_cache_page_variables($post_id);

            return true;
        } else {
            error_log("gTemplate: Failed to register template '{$template_id}'");
            return false;
        }

    } catch (\Throwable $e) {
        error_log("gTemplate: Error registering template for page {$post_id}: " . $e->getMessage());
        return false;

    } finally {
        // Always release lock
        if ($lock && $cache) {
            try {
                $cache->releaseLock($lock);
            } catch (\Throwable $e) {
                error_log("gTemplate: Failed to release lock: " . $e->getMessage());
            }
        }
    }
}

/**
 * Cache page variables for fast template rendering
 *
 * Pre-computes all template variables and stores them in ValKey
 * for <1ms retrieval during rendering.
 *
 * @param int $post_id WordPress page ID
 */
function gtemplate_cache_page_variables($post_id) {
    global $gCore;

    $post = get_post($post_id);

    if (!$post) {
        return;
    }

    // Get featured image dimensions
    $featured_image_id = get_post_thumbnail_id($post);
    $featured_image_meta = wp_get_attachment_metadata($featured_image_id);

    // Build template variables
    $variables = [
        'page_id' => $post_id,
        'slug' => $post->post_name,
        'title' => $post->post_title,
        'content' => apply_filters('the_content', $post->post_content),
        'excerpt' => $post->post_excerpt ?: wp_trim_words($post->post_content, 30),
        'author' => get_the_author_meta('display_name', $post->post_author),
        'date' => get_the_date('', $post),
        'date_iso' => get_the_date('c', $post),
        'updated' => get_the_modified_date('', $post),
        'permalink' => get_permalink($post),
        'featured_image' => get_the_post_thumbnail_url($post, 'large'),
        'featured_image_width' => $featured_image_meta['width'] ?? null,
        'featured_image_height' => $featured_image_meta['height'] ?? null,
        'blog_name' => get_bloginfo('name'),
        'timestamp' => time()
    ];

    // Cache via gCore CacheManager
    try {
        $cache = $gCore->getService('Cache');

        if ($cache) {
            $cache_key = "gtemplate:page_vars:{$post_id}";
            $cache->set($cache_key, $variables, 3600);  // 1 hour TTL
        }
    } catch (\Throwable $e) {
        error_log("gTemplate: Failed to cache page variables: " . $e->getMessage());
    }
}

/**
 * Get cached page variables with fallback
 *
 * @param int $post_id WordPress page ID
 * @return array Template variables
 */
function gtemplate_get_page_variables($post_id) {
    global $gCore;

    try {
        $cache = $gCore->getService('Cache');

        if ($cache) {
            $cache_key = "gtemplate:page_vars:{$post_id}";
            $cached = $cache->get($cache_key);

            if ($cached && is_array($cached)) {
                return $cached;
            }
        }
    } catch (\Throwable $e) {
        error_log("gTemplate: Cache retrieval failed: " . $e->getMessage());
    }

    // Cache miss - rebuild
    gtemplate_cache_page_variables($post_id);

    // Recursive call to get freshly cached data
    return gtemplate_get_page_variables_direct($post_id);
}

/**
 * Get page variables directly without caching (fallback)
 *
 * @param int $post_id WordPress page ID
 * @return array Template variables
 */
function gtemplate_get_page_variables_direct($post_id) {
    $post = get_post($post_id);

    if (!$post) {
        return [];
    }

    $featured_image_id = get_post_thumbnail_id($post);
    $featured_image_meta = wp_get_attachment_metadata($featured_image_id);

    return [
        'page_id' => $post_id,
        'slug' => $post->post_name,
        'title' => $post->post_title,
        'content' => apply_filters('the_content', $post->post_content),
        'excerpt' => $post->post_excerpt ?: wp_trim_words($post->post_content, 30),
        'author' => get_the_author_meta('display_name', $post->post_author),
        'date' => get_the_date('', $post),
        'date_iso' => get_the_date('c', $post),
        'updated' => get_the_modified_date('', $post),
        'permalink' => get_permalink($post),
        'featured_image' => get_the_post_thumbnail_url($post, 'large'),
        'featured_image_width' => $featured_image_meta['width'] ?? null,
        'featured_image_height' => $featured_image_meta['height'] ?? null,
        'blog_name' => get_bloginfo('name'),
        'timestamp' => time()
    ];
}

/**
 * Auto-register page template on save
 *
 * Hooks into WordPress save_post action to automatically
 * sync page content to gNode templates.
 */
function gtemplate_auto_register_on_save($post_id, $post, $update) {
    // Skip auto-saves and revisions
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (wp_is_post_revision($post_id)) {
        return;
    }

    // Only process published pages
    if ($post->post_type === 'page' && $post->post_status === 'publish') {
        // Defer to next request to avoid slowing down save
        wp_schedule_single_event(time() + 5, 'gtemplate_register_template_event', [$post_id]);
    }
}
add_action('save_post', 'gtemplate_auto_register_on_save', 20, 3);

/**
 * Deferred template registration event
 */
function gtemplate_deferred_template_registration($post_id) {
    gtemplate_register_page_template($post_id);
}
add_action('gtemplate_register_template_event', 'gtemplate_deferred_template_registration');

/**
 * Invalidate cache on page update
 */
function gtemplate_invalidate_page_cache($post_id) {
    global $gCore;

    try {
        $cache = $gCore->getService('Cache');

        if ($cache) {
            $cache_key = "gtemplate:page_vars:{$post_id}";
            $cache->delete($cache_key);
            error_log("gTemplate: Invalidated cache for page {$post_id}");
        }
    } catch (\Throwable $e) {
        error_log("gTemplate: Cache invalidation failed: " . $e->getMessage());
    }
}
add_action('save_post_page', 'gtemplate_invalidate_page_cache', 10);

/**
 * Delete template on page delete
 */
function gtemplate_delete_page_template($post_id) {
    $post = get_post($post_id);

    if ($post && $post->post_type === 'page') {
        $template_id = "wp_page_{$post_id}";
        error_log("gTemplate: Page {$post_id} deleted, template '{$template_id}' orphaned (no gNode deleteTemplate method)");

        // Invalidate cache
        gtemplate_invalidate_page_cache($post_id);
    }
}
add_action('delete_post', 'gtemplate_delete_page_template');

/**
 * Bulk register all existing pages
 *
 * Useful for initial setup or after gNode daemon restart.
 * Can be triggered via WP-CLI or admin action.
 *
 * @return int Number of pages registered
 */
function gtemplate_bulk_register_pages() {
    $pages = get_pages([
        'post_status' => 'publish',
        'number' => 0  // All pages
    ]);

    $registered = 0;
    $failed = 0;

    foreach ($pages as $page) {
        if (gtemplate_register_page_template($page->ID)) {
            $registered++;
        } else {
            $failed++;
        }

        // Prevent timeout on large sites
        if ($registered % 10 === 0) {
            usleep(100000);  // 100ms pause every 10 pages
        }
    }

    error_log("gTemplate: Bulk registration complete - {$registered} registered, {$failed} failed");

    return $registered;
}

/**
 * Get page ID for face cell (mapping system)
 *
 * Maps face cell to WordPress page ID.
 * Uses customizer settings with dynamic face prefix.
 *
 * @param int $cell_id Face cell ID
 * @return int|null WordPress page ID or null
 */
function gtemplate_get_cell_page_mapping($cell_id) {
    $face_count = gtemplate_get_face_count();
    $face_prefix = gtemplate_get_face_prefix();

    // Validate cell ID
    if ($cell_id < 0 || $cell_id >= $face_count) {
        return null;
    }

    // Get from customizer settings
    $source = get_theme_mod("{$face_prefix}_{$cell_id}_source", 'demo');

    if ($source === 'page' || $source === 'post') {
        $content_id = (int) get_theme_mod("{$face_prefix}_{$cell_id}_content_id", 0);
        if ($content_id > 0) {
            return $content_id;
        }
    }

    return null;
}

/**
 * Get all face cell configurations from customizer
 *
 * @return array Array of cell configurations
 */
if (!function_exists('gtemplate_get_all_cell_configs')) {
    function gtemplate_get_all_cell_configs() {
        $cells = [];
        $face_count = gtemplate_get_face_count();
        $face_prefix = gtemplate_get_face_prefix();
        $defaults = ['Home', 'About', 'Services', 'Projects', 'Portfolio', 'Team', 'Blog', 'Contact'];

        for ($i = 0; $i < $face_count; $i++) {
            $cells[$i] = [
                'label' => get_theme_mod("{$face_prefix}_{$i}_label", $defaults[$i] ?? "Face {$i}"),
                'source' => get_theme_mod("{$face_prefix}_{$i}_source", 'demo'),
                'content_id' => (int) get_theme_mod("{$face_prefix}_{$i}_content_id", 0),
                'custom_html' => get_theme_mod("{$face_prefix}_{$i}_custom_html", ''),
                'template_name' => get_theme_mod("{$face_prefix}_{$i}_template_name", ''),
                'category_filter' => get_theme_mod("{$face_prefix}_{$i}_category_filter", ''),
                'posts_per_page' => (int) get_theme_mod("{$face_prefix}_{$i}_posts_per_page", 10),
                'bundle' => get_theme_mod("{$face_prefix}_{$i}_bundle", ''),
            ];
        }

        return $cells;
    }
}

/**
 * Sync all face cells to gNode
 *
 * Registers all cell templates with gNode daemon based on customizer settings.
 * Creates bundles for pages/posts assigned to cells.
 *
 * @return array Results with registered count and errors
 */
function gtemplate_sync_cells_to_gnode() {
    $gNode = gtemplate_gnode();
    if (!$gNode) {
        return ['error' => 'gNode unavailable', 'registered' => 0];
    }

    $cells = gtemplate_get_all_cell_configs();
    $face_count = gtemplate_get_face_count();
    $registered = 0;
    $errors = [];

    foreach ($cells as $cell_id => $config) {
        $result = gtemplate_register_cell_template($cell_id, $config);
        if ($result) {
            $registered++;
        } else {
            $errors[] = "Cell {$cell_id} failed to register";
        }
    }

    error_log("gTemplate: Cell sync complete - {$registered}/{$face_count} registered");

    return [
        'registered' => $registered,
        'total' => $face_count,
        'errors' => $errors
    ];
}

/**
 * Register a single cell template with gNode
 *
 * @param int $cell_id Cell ID
 * @param array $config Cell configuration
 * @return bool Success status
 */
function gtemplate_register_cell_template($cell_id, $config) {
    $gNode = gtemplate_gnode();
    if (!$gNode) {
        return false;
    }

    $face_prefix = gtemplate_get_face_prefix();
    $template_id = "{$face_prefix}_{$cell_id}";

    switch ($config['source']) {
        case 'page':
        case 'post':
            if ($config['content_id'] > 0) {
                // Register WordPress content as template
                return gtemplate_register_page_template($config['content_id']);
            }
            break;

        case 'custom':
            // Register custom HTML as template
            if (!empty($config['custom_html'])) {
                try {
                    $template = gtemplate_wrap_custom_html($config['custom_html'], $config['label']);
                    return $gNode->registerTemplate($template_id, $template);
                } catch (\Throwable $e) {
                    error_log("gTemplate: Failed to register custom template for cell {$cell_id}: " . $e->getMessage());
                    return false;
                }
            }
            break;

        case 'template':
            // Register from Template Library
            $templateName = $config['template_name'] ?? '';
            if (!empty($templateName)) {
                try {
                    // Get template content from helper function
                    $content = gtemplate_get_template_content($templateName);
                    if (!$content) {
                        error_log("gTemplate: Template '{$templateName}' not found in library");
                        return false;
                    }

                    // Build variables for template
                    $variables = gtemplate_get_template_variables($templateName, $cell_id, $config['label']);

                    // Register with gNode
                    return $gNode->registerTemplate($template_id, $content, [
                        'variables' => $variables,
                        'dependencies' => ["library:{$templateName}"]
                    ]);
                } catch (\Throwable $e) {
                    error_log("gTemplate: Failed to register library template for cell {$cell_id}: " . $e->getMessage());
                    return false;
                }
            }
            break;

        case 'demo':
        default:
            // Demo content is rendered client-side, no gNode template needed
            return true;
    }

    return false;
}

/**
 * Wrap custom HTML in a Tera template
 *
 * @param string $html Custom HTML content
 * @param string $title Cell title
 * @return string Tera template
 */
function gtemplate_wrap_custom_html($html, $title) {
    return <<<TERA
{# Custom HTML Cell Template #}
<div class="face-cell-content custom-content">
    <header class="cell-header">
        <h2 class="cell-title">{$title}</h2>
    </header>
    <main class="cell-body">
        {$html}
    </main>
</div>
TERA;
}

/**
 * Hook: Sync cells on customizer save
 */
function gtemplate_on_customizer_save() {
    // Schedule sync to avoid blocking customizer save
    wp_schedule_single_event(time() + 2, 'gtemplate_sync_cells_event');
}
add_action('customize_save_after', 'gtemplate_on_customizer_save');

/**
 * Deferred cell sync event
 */
function gtemplate_deferred_cell_sync() {
    gtemplate_sync_cells_to_gnode();
}
add_action('gtemplate_sync_cells_event', 'gtemplate_deferred_cell_sync');

// Legacy aliases for backward compatibility
function gtemplate_get_face_page_mapping($face_id) {
    return gtemplate_get_cell_page_mapping($face_id);
}

function gtemplate_set_face_mapping($face_id, $page_id) {
    $face_count = gtemplate_get_face_count();
    $face_prefix = gtemplate_get_face_prefix();

    if ($face_id < 0 || $face_id >= $face_count) {
        return false;
    }
    // Update via customizer settings instead
    set_theme_mod("{$face_prefix}_{$face_id}_source", 'page');
    set_theme_mod("{$face_prefix}_{$face_id}_content_id", (int) $page_id);
    return true;
}

/**
 * Sync face mapping configuration to ValKey for daemon bundle generation
 *
 * Stores comprehensive face configuration including:
 * - Content source type (page, posts, template, custom, demo)
 * - Rendered content for each face
 * - Template variables and metadata
 *
 * The daemon reads this to generate pre-rendered bundles.
 *
 * @return bool Success status
 */
function gtemplate_sync_face_mapping_to_valkey(): bool {
    global $gCore;

    try {
        $gNodeClient = gtemplate_gnode();
        if (!$gNodeClient) {
            error_log("gTemplate: Cannot sync face mapping - gNode client not available");
            return false;
        }

        $site_id = \gTemplate\get_site_id_from_domain();
        $cells = gtemplate_get_all_cell_configs();
        $face_count = gtemplate_get_face_count();
        $faces = [];

        // Process each face
        for ($i = 0; $i < $face_count; $i++) {
            $config = $cells[$i];
            $face_data = gtemplate_build_face_data($i, $config);
            $faces[$i] = $face_data;
        }

        // Build complete mapping structure
        $mapping = [
            'site_id' => $site_id,
            'faces' => $faces,
            'metadata' => [
                'site_name' => get_bloginfo('name'),
                'site_url' => get_site_url(),
                'description' => get_bloginfo('description'),
                'theme_version' => wp_get_theme()->get('Version'),
                'synced_at' => time(),
            ],
            'navigation' => gtemplate_get_navigation_for_bundle(),
            'posts' => gtemplate_get_recent_posts_for_bundle(),
            'bundles' => gtemplate_get_bundles_for_mapping(),
        ];

        // Store in ValKey under gNode namespace for ACL compatibility
        // Use fcall with gNode_CACHE_SET for proper ACL compliance
        $key = "{$site_id}:gnode:face_mapping";  // Note: key without braces - fcall handles it
        $json = json_encode($mapping, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $ttl = 0;  // No TTL - persist until updated

        // Use fcall through gCore's gNode client (ACL-compliant)
        $result = $gNodeClient->fcall('GNODE_CACHE_SET', [], [$key, $json, $ttl, $site_id]);

        if ($result !== false && $result !== null) {
            error_log("gTemplate: Face mapping synced to ValKey ({$key}) - " . strlen($json) . " bytes");

            // Trigger bundle rebuild via invalidation event
            gtemplate_trigger_bundle_rebuild($site_id);

            // Sync to AssetManager manifest (new manifest-driven builder)
            if ($gCore) {
                $assetManager = $gCore->getService('AssetManager');
                if ($assetManager && $assetManager->isInitialized()) {
                    $assetManager->syncFaceMapping($mapping);
                }
            }

            return true;
        }

        error_log("gTemplate: Failed to store face mapping in ValKey (result: " . var_export($result, true) . ")");
        return false;

    } catch (\Throwable $e) {
        error_log("gTemplate: Face mapping sync error: " . $e->getMessage());
        return false;
    }
}

/**
 * Build face data for a single cell
 *
 * @param int $face_id Face ID
 * @param array $config Cell configuration from customizer
 * @return array Face data structure
 */
function gtemplate_build_face_data(int $face_id, array $config): array {
    $face_prefix = gtemplate_get_face_prefix();

    $face_data = [
        'id' => $face_id,
        'label' => $config['label'] ?? "Face {$face_id}",
        'source' => $config['source'] ?? 'demo',
        'enabled' => (bool) get_theme_mod("{$face_prefix}_{$face_id}_enabled", true),
    ];

    switch ($config['source']) {
        case 'page':
        case 'post':
            $content_id = (int) ($config['content_id'] ?? 0);
            if ($content_id > 0) {
                $face_data['content_id'] = $content_id;
                $face_data['template_id'] = "wp_page_{$content_id}";
                $face_data['variables'] = gtemplate_get_page_variables_direct($content_id);
                // Pre-render the content for the bundle
                $face_data['html'] = gtemplate_render_page_content($content_id);
            } else {
                $face_data['html'] = gtemplate_get_demo_content_for_bundle($face_id, $config['label']);
            }
            break;

        case 'posts':
            // Check for bundle first (overrides category filter)
            $bundle_slug = $config['bundle'] ?? '';
            if (!empty($bundle_slug) && function_exists('gtemplate_get_bundle_content')) {
                $face_data['bundle_slug'] = $bundle_slug;
                $face_data['html'] = gtemplate_get_bundle_content($bundle_slug, $face_id, $config['label'], true);
            } else {
                // Fall back to category filter
                $category = $config['category_filter'] ?? '';
                $posts_per_page = (int) ($config['posts_per_page'] ?? 10);
                $face_data['category_filter'] = $category;
                $face_data['posts_per_page'] = $posts_per_page;
                $face_data['html'] = gtemplate_render_posts_list($category, $posts_per_page, $config['label']);
            }
            break;

        case 'bundle':
            $bundle_slug = $config['bundle'] ?? '';
            $face_data['bundle_slug'] = $bundle_slug;
            if (!empty($bundle_slug) && function_exists('gtemplate_get_bundle_content')) {
                $face_data['html'] = gtemplate_get_bundle_content($bundle_slug, $face_id, $config['label'], true);
            } else {
                $face_data['html'] = gtemplate_get_demo_content_for_bundle($face_id, $config['label']);
            }
            break;

        case 'template':
            $template_name = $config['template_name'] ?? '';
            if (!empty($template_name)) {
                $face_data['template_name'] = $template_name;
                // Get template content and variables
                $template_content = gtemplate_get_template_content($template_name);
                if ($template_content) {
                    $face_data['template_content'] = $template_content;
                    $face_data['variables'] = gtemplate_get_template_variables($template_name, $face_id, $config['label']);
                    // Pre-render for bundle (daemon will use this directly)
                    $face_data['html'] = gtemplate_pre_render_template($template_name, $face_data['variables']);
                    // Include template-specific JS for gNode bundle
                    $template_js = gtemplate_get_template_js_content($template_name);
                    if ($template_js) {
                        $face_data['js'] = $template_js;
                    }
                }
            }
            break;

        case 'custom':
            $custom_html = $config['custom_html'] ?? '';
            if (!empty($custom_html)) {
                $face_data['html'] = gtemplate_wrap_custom_html($custom_html, $config['label']);
            } else {
                $face_data['html'] = gtemplate_get_demo_content_for_bundle($face_id, $config['label']);
            }
            break;

        case 'demo':
        default:
            $face_data['html'] = gtemplate_get_demo_content_for_bundle($face_id, $config['label']);
            break;
    }

    // Add CSS/JS if available
    $face_data['css'] = get_theme_mod("{$face_prefix}_{$face_id}_css", null);
    $face_data['js'] = get_theme_mod("{$face_prefix}_{$face_id}_js", null);

    return $face_data;
}

/**
 * Render page content for bundle
 *
 * @param int $post_id WordPress post/page ID
 * @return string Rendered HTML
 */
function gtemplate_render_page_content(int $post_id): string {
    $post = get_post($post_id);
    if (!$post) {
        return "<div class='face-error'>Page not found (ID: {$post_id})</div>";
    }

    $vars = gtemplate_get_page_variables_direct($post_id);

    // Build HTML structure matching the Tera template
    $html = '<div class="cube-face-content wordpress-page" data-page-id="' . esc_attr($vars['page_id']) . '" data-slug="' . esc_attr($vars['slug']) . '">';
    $html .= '<header class="face-header">';
    $html .= '<h2 class="face-title">' . esc_html($vars['title']) . '</h2>';

    if (!empty($vars['featured_image'])) {
        $html .= '<div class="face-featured-image">';
        $html .= '<img src="' . esc_url($vars['featured_image']) . '" alt="' . esc_attr($vars['title']) . '" loading="lazy"';
        if (!empty($vars['featured_image_width'])) {
            $html .= ' width="' . esc_attr($vars['featured_image_width']) . '"';
        }
        if (!empty($vars['featured_image_height'])) {
            $html .= ' height="' . esc_attr($vars['featured_image_height']) . '"';
        }
        $html .= '></div>';
    }
    $html .= '</header>';

    $html .= '<main class="face-body">';
    if (!empty($vars['excerpt'])) {
        $html .= '<div class="page-excerpt"><p>' . esc_html($vars['excerpt']) . '</p></div>';
    }
    $html .= '<div class="page-content">' . $vars['content'] . '</div>';
    $html .= '</main>';

    $html .= '<footer class="face-footer">';
    if (!empty($vars['permalink'])) {
        $html .= '<a href="' . esc_url($vars['permalink']) . '" class="read-more">Read Full Page <span aria-hidden="true">&rarr;</span></a>';
    }
    $html .= '<small class="site-credit"><span>' . esc_html($vars['blog_name']) . '</span></small>';
    $html .= '</footer>';
    $html .= '</div>';

    return $html;
}

/**
 * Render posts list for bundle
 *
 * @param string $category Category filter (slug or empty for all)
 * @param int $posts_per_page Number of posts
 * @param string $title Section title
 * @return string Rendered HTML
 */
function gtemplate_render_posts_list(string $category, int $posts_per_page, string $title): string {
    $args = [
        'post_type' => 'post',
        'post_status' => 'publish',
        'posts_per_page' => $posts_per_page,
        'orderby' => 'date',
        'order' => 'DESC',
    ];

    if (!empty($category)) {
        $args['category_name'] = $category;
    }

    $query = new \WP_Query($args);

    $html = '<div class="cube-face-content posts-list" data-category="' . esc_attr($category) . '">';
    $html .= '<header class="face-header"><h2 class="face-title">' . esc_html($title) . '</h2></header>';
    $html .= '<main class="face-body">';

    if ($query->have_posts()) {
        $html .= '<ul class="posts-grid">';
        while ($query->have_posts()) {
            $query->the_post();
            $html .= '<li class="post-item">';
            $html .= '<article class="post-card">';

            if (has_post_thumbnail()) {
                $html .= '<div class="post-thumbnail">';
                $html .= '<img src="' . esc_url(get_the_post_thumbnail_url(null, 'medium')) . '" alt="' . esc_attr(get_the_title()) . '" loading="lazy">';
                $html .= '</div>';
            }

            $html .= '<div class="post-content">';
            $html .= '<h3 class="post-title"><a href="' . esc_url(get_permalink()) . '">' . esc_html(get_the_title()) . '</a></h3>';
            $html .= '<time class="post-date" datetime="' . esc_attr(get_the_date('c')) . '">' . esc_html(get_the_date()) . '</time>';
            $html .= '<p class="post-excerpt">' . esc_html(get_the_excerpt()) . '</p>';
            $html .= '</div>';

            $html .= '</article>';
            $html .= '</li>';
        }
        $html .= '</ul>';
        wp_reset_postdata();
    } else {
        $html .= '<p class="no-posts">No posts found.</p>';
    }

    $html .= '</main>';
    $html .= '<footer class="face-footer"><small class="site-credit"><span>' . esc_html(get_bloginfo('name')) . '</span></small></footer>';
    $html .= '</div>';

    return $html;
}

/**
 * Pre-render a Tera template with variables (PHP-side fallback)
 *
 * @param string $template_name Template name
 * @param array $variables Template variables
 * @return string Rendered HTML
 */
function gtemplate_pre_render_template(string $template_name, array $variables): string {
    // Try to use gNode daemon for rendering
    $gNode = gtemplate_gnode();
    if ($gNode) {
        try {
            $result = $gNode->renderTemplate($template_name, $variables);
            if ($result && !empty($result)) {
                return $result;
            }
        } catch (\Throwable $e) {
            error_log("gTemplate: Daemon template render failed, using PHP fallback: " . $e->getMessage());
        }
    }

    // PHP fallback: Load and do basic variable substitution
    $template_content = gtemplate_get_template_content($template_name);
    if (!$template_content) {
        return "<div class='face-error'>Template not found: {$template_name}</div>";
    }

    // Basic Tera variable substitution ({{ variable }})
    $html = $template_content;
    foreach ($variables as $key => $value) {
        if (is_string($value) || is_numeric($value)) {
            $html = str_replace("{{ {$key} }}", esc_html((string) $value), $html);
            $html = str_replace("{{$key}}", esc_html((string) $value), $html);
        }
    }

    // Handle | safe filter (don't escape)
    foreach ($variables as $key => $value) {
        if (is_string($value)) {
            $html = str_replace("{{ {$key} | safe }}", $value, $html);
        }
    }

    return $html;
}

/**
 * Get demo content for a face (bundle version with label)
 *
 * @param int $face_id Face ID
 * @param string $label Face label
 * @return string Demo HTML content
 */
function gtemplate_get_demo_content_for_bundle(int $face_id, string $label): string {
    $face_prefix = gtemplate_get_face_prefix();
    $positions = ['top', 'front', 'right', 'back', 'left', 'bottom', 'inner-1', 'inner-2'];
    $position = $positions[$face_id] ?? 'unknown';

    return <<<HTML
<div class="cube-face-content demo-content" data-face-id="{$face_id}" data-position="{$position}">
    <header class="face-header">
        <h2 class="face-title">{$label}</h2>
    </header>
    <main class="face-body">
        <div class="demo-placeholder">
            <p>Configure this face in the Customizer to display your content.</p>
            <p class="demo-hint">Go to: Appearance &rarr; Customize &rarr; Face Cells &rarr; Cell {$face_id}</p>
        </div>
    </main>
    <footer class="face-footer">
        <small class="position-indicator">Position: {$position}</small>
    </footer>
</div>
HTML;
}

/**
 * Get navigation menu for bundle
 *
 * @return array Navigation structure
 */
function gtemplate_get_navigation_for_bundle(): array {
    $menu_items = [];
    $locations = get_nav_menu_locations();

    // Try primary menu first, then fallback to any registered menu
    $menu_id = $locations['primary'] ?? ($locations['main'] ?? 0);

    if ($menu_id) {
        $items = wp_get_nav_menu_items($menu_id);
        if ($items) {
            foreach ($items as $item) {
                if ($item->menu_item_parent == 0) {
                    $menu_items[] = [
                        'title' => $item->title,
                        'url' => $item->url,
                        'children' => gtemplate_get_menu_children($items, $item->ID),
                    ];
                }
            }
        }
    }

    return [
        'menu' => $menu_items,
        'breadcrumbs' => [],
    ];
}

/**
 * Get child menu items
 *
 * @param array $items All menu items
 * @param int $parent_id Parent item ID
 * @return array Child items
 */
function gtemplate_get_menu_children(array $items, int $parent_id): array {
    $children = [];
    foreach ($items as $item) {
        if ($item->menu_item_parent == $parent_id) {
            $children[] = [
                'title' => $item->title,
                'url' => $item->url,
                'children' => gtemplate_get_menu_children($items, $item->ID),
            ];
        }
    }
    return $children;
}

/**
 * Get recent posts for bundle
 *
 * @return array Posts data
 */
function gtemplate_get_recent_posts_for_bundle(): array {
    $posts = get_posts([
        'post_type' => 'post',
        'post_status' => 'publish',
        'posts_per_page' => 20,
        'orderby' => 'date',
        'order' => 'DESC',
    ]);

    $list = [];
    $by_id = [];

    foreach ($posts as $post) {
        $data = [
            'id' => (string) $post->ID,
            'title' => $post->post_title,
            'excerpt' => get_the_excerpt($post),
            'url' => get_permalink($post),
            'date' => get_the_date('Y-m-d', $post),
        ];
        $list[] = $data;
        $by_id[$post->ID] = $data;
    }

    return [
        'list' => $list,
        'by_id' => $by_id,
    ];
}

/**
 * Get all configured post bundles for ValKey mapping
 *
 * Returns bundle configurations with post data for gNode to use.
 *
 * @return array Bundles data indexed by slug
 */
function gtemplate_get_bundles_for_mapping(): array {
    $bundles = [];

    // Get bundles from customizer (uses function from register-options-wp.php)
    if (function_exists('gtemplate_get_all_post_bundles')) {
        $all_bundles = gtemplate_get_all_post_bundles();
    } else {
        // Fallback if function not available
        $all_bundles = [];
        for ($i = 1; $i <= 5; $i++) {
            $enabled = get_theme_mod("post_bundle_{$i}_enabled", false);
            if (!$enabled) {
                continue;
            }

            $slug = get_theme_mod("post_bundle_{$i}_slug", sprintf('bundle-%d', $i));
            $post_ids_str = get_theme_mod("post_bundle_{$i}_post_ids", '');
            $post_ids = array_filter(array_map('intval', array_map('trim', explode(',', $post_ids_str))));

            $all_bundles[$slug] = [
                'index' => $i,
                'name' => get_theme_mod("post_bundle_{$i}_name", sprintf('Bundle %d', $i)),
                'slug' => $slug,
                'post_ids' => $post_ids,
                'description' => get_theme_mod("post_bundle_{$i}_description", ''),
            ];
        }
    }

    // Enrich each bundle with post data
    foreach ($all_bundles as $slug => $bundle) {
        if (empty($bundle['post_ids'])) {
            continue;
        }

        // Fetch posts in order
        $posts = get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            'post__in' => $bundle['post_ids'],
            'orderby' => 'post__in',
            'posts_per_page' => count($bundle['post_ids']),
        ]);

        $posts_data = [];
        foreach ($posts as $post) {
            $posts_data[] = [
                'id' => (string) $post->ID,
                'title' => $post->post_title,
                'excerpt' => get_the_excerpt($post),
                'url' => get_permalink($post),
                'date' => get_the_date('Y-m-d', $post),
                'thumbnail' => get_the_post_thumbnail_url($post, 'medium') ?: null,
                'categories' => wp_list_pluck(get_the_category($post->ID), 'name'),
            ];
        }

        $bundles[$slug] = [
            'name' => $bundle['name'],
            'slug' => $slug,
            'description' => $bundle['description'],
            'post_count' => count($posts_data),
            'posts' => $posts_data,
        ];
    }

    return $bundles;
}

// ============================================================================
// POST BUNDLE VALKEY CACHING LAYER
// Provides O(1) retrieval for curated post bundles via ValKey
// ============================================================================

/**
 * Cache a post bundle's data in ValKey
 *
 * Stores bundle configuration and enriched post data for fast retrieval.
 * Key pattern: {site_id}:post_bundle:{slug}
 *
 * @param string $slug Bundle slug
 * @param array|null $bundle_config Optional bundle config (fetched if not provided)
 * @return bool Success status
 */
function gtemplate_cache_post_bundle(string $slug, ?array $bundle_config = null): bool {
    try {
        $gNodeClient = gtemplate_gnode();
        if (!$gNodeClient) {
            return false;  // gNode not available - silent fail
        }

        // Get bundle config if not provided
        if ($bundle_config === null) {
            if (function_exists('gtemplate_get_post_bundle')) {
                $bundle_config = gtemplate_get_post_bundle($slug);
            }
            if (!$bundle_config) {
                error_log("gTemplate: Bundle '{$slug}' not found");
                return false;
            }
        }

        if (empty($bundle_config['post_ids'])) {
            return false;
        }

        // Fetch posts with all needed data
        $posts = get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            'post__in' => $bundle_config['post_ids'],
            'orderby' => 'post__in',
            'posts_per_page' => count($bundle_config['post_ids']),
        ]);

        // Build enriched post data
        $posts_data = [];
        foreach ($posts as $post) {
            $categories = get_the_category($post->ID);
            $posts_data[] = [
                'id' => $post->ID,
                'title' => $post->post_title,
                'slug' => $post->post_name,
                'excerpt' => wp_trim_words($post->post_content, 25),
                'content' => apply_filters('the_content', $post->post_content),
                'date' => get_the_date('', $post),
                'date_iso' => get_the_date('c', $post),
                'author' => get_the_author_meta('display_name', $post->post_author),
                'thumbnail' => get_the_post_thumbnail_url($post, 'medium') ?: null,
                'thumbnail_large' => get_the_post_thumbnail_url($post, 'large') ?: null,
                'categories' => !empty($categories) ? wp_list_pluck($categories, 'name') : [],
                'category_primary' => !empty($categories) ? $categories[0]->name : null,
            ];
        }

        // Build cache payload
        $cache_data = [
            'slug' => $slug,
            'name' => $bundle_config['name'],
            'description' => $bundle_config['description'] ?? '',
            'post_ids' => $bundle_config['post_ids'],
            'post_count' => count($posts_data),
            'posts' => $posts_data,
            'cached_at' => time(),
            'expires_at' => time() + 3600,  // 1 hour TTL
        ];

        // Store in ValKey
        $site_id = \gTemplate\get_site_id_from_domain();
        $key = "{$site_id}:post_bundle:{$slug}";
        $json = json_encode($cache_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $ttl = 3600;  // 1 hour

        $result = $gNodeClient->fcall('GNODE_CACHE_SET', [], [$key, $json, $ttl, $site_id]);

        if ($result !== false && $result !== null) {
            error_log("gTemplate: Cached post bundle '{$slug}' ({$cache_data['post_count']} posts) - " . strlen($json) . " bytes");
            return true;
        }

        error_log("gTemplate: Failed to cache post bundle '{$slug}'");
        return false;

    } catch (\Throwable $e) {
        error_log("gTemplate: Post bundle cache error: " . $e->getMessage());
        return false;
    }
}

/**
 * Retrieve a post bundle from ValKey (with WordPress fallback)
 *
 * Checks ValKey first for O(1) retrieval, falls back to WordPress on miss.
 *
 * @param string $slug Bundle slug
 * @param bool $cache_on_miss Whether to cache on miss (default true)
 * @return array|null Bundle data with posts or null if not found
 */
function gtemplate_get_cached_post_bundle(string $slug, bool $cache_on_miss = true): ?array {
    // Try ValKey first
    $gNodeClient = gtemplate_gnode();
    if ($gNodeClient) {
        try {
            $site_id = \gTemplate\get_site_id_from_domain();
            $key = "{$site_id}:post_bundle:{$slug}";

            $result = $gNodeClient->fcall('GNODE_CACHE_GET', [], [$key, $site_id]);

            if ($result && is_string($result)) {
                $data = json_decode($result, true);
                if ($data && !empty($data['posts'])) {
                    // Check if cache is still valid
                    if (isset($data['expires_at']) && $data['expires_at'] > time()) {
                        error_log("gTemplate: Post bundle '{$slug}' retrieved from ValKey cache");
                        return $data;
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log("gTemplate: ValKey retrieval failed, falling back to WP: " . $e->getMessage());
        }
    }

    // Cache miss - fetch from WordPress
    error_log("gTemplate: Post bundle '{$slug}' cache miss, fetching from WordPress");

    $bundle_config = null;
    if (function_exists('gtemplate_get_post_bundle')) {
        $bundle_config = gtemplate_get_post_bundle($slug);
    }

    if (!$bundle_config || empty($bundle_config['post_ids'])) {
        return null;
    }

    // Fetch posts
    $posts = get_posts([
        'post_type' => 'post',
        'post_status' => 'publish',
        'post__in' => $bundle_config['post_ids'],
        'orderby' => 'post__in',
        'posts_per_page' => count($bundle_config['post_ids']),
    ]);

    if (empty($posts)) {
        return null;
    }

    // Build response data
    $posts_data = [];
    foreach ($posts as $post) {
        $categories = get_the_category($post->ID);
        $posts_data[] = [
            'id' => $post->ID,
            'title' => $post->post_title,
            'slug' => $post->post_name,
            'excerpt' => wp_trim_words($post->post_content, 25),
            'date' => get_the_date('', $post),
            'date_iso' => get_the_date('c', $post),
            'author' => get_the_author_meta('display_name', $post->post_author),
            'thumbnail' => get_the_post_thumbnail_url($post, 'medium') ?: null,
            'categories' => !empty($categories) ? wp_list_pluck($categories, 'name') : [],
            'category_primary' => !empty($categories) ? $categories[0]->name : null,
        ];
    }

    $data = [
        'slug' => $slug,
        'name' => $bundle_config['name'],
        'description' => $bundle_config['description'] ?? '',
        'post_ids' => $bundle_config['post_ids'],
        'post_count' => count($posts_data),
        'posts' => $posts_data,
        'cached_at' => time(),
    ];

    // Cache for next time
    if ($cache_on_miss) {
        gtemplate_cache_post_bundle($slug, $bundle_config);
    }

    return $data;
}

/**
 * Cache all configured post bundles to ValKey
 *
 * Batch operation to pre-warm the cache for all bundles.
 *
 * @return array Results with success/failure counts
 */
function gtemplate_cache_all_post_bundles(): array {
    $results = ['cached' => 0, 'failed' => 0, 'bundles' => []];

    $bundles = function_exists('gtemplate_get_all_post_bundles')
        ? gtemplate_get_all_post_bundles()
        : [];

    foreach ($bundles as $slug => $config) {
        if (gtemplate_cache_post_bundle($slug, $config)) {
            $results['cached']++;
            $results['bundles'][$slug] = 'cached';
        } else {
            $results['failed']++;
            $results['bundles'][$slug] = 'failed';
        }
    }

    error_log("gTemplate: Cached {$results['cached']} post bundles, {$results['failed']} failed");
    return $results;
}

/**
 * Invalidate post bundle cache for a specific post
 *
 * Called when a post is updated/deleted to ensure bundle cache consistency.
 *
 * @param int $post_id WordPress post ID
 */
function gtemplate_invalidate_post_bundle_cache(int $post_id): void {
    $gNodeClient = gtemplate_gnode();
    if (!$gNodeClient) {
        return;
    }

    // Get all bundles and check if this post is in any
    $bundles = function_exists('gtemplate_get_all_post_bundles')
        ? gtemplate_get_all_post_bundles()
        : [];

    $invalidated = [];

    foreach ($bundles as $slug => $config) {
        if (in_array($post_id, $config['post_ids'], true)) {
            // This post is in this bundle - invalidate and re-cache
            try {
                if ($gNodeClient) {
                    $site_id = \gTemplate\get_site_id_from_domain();
                    $key = "{$site_id}:post_bundle:{$slug}";

                    // Delete the cached bundle
                    $gNodeClient->fcall('GNODE_CACHE_DELETE', [], [$key, $site_id]);
                    $invalidated[] = $slug;

                    // Re-cache with updated data (deferred to avoid slowing save)
                    wp_schedule_single_event(time() + 5, 'gtemplate_recache_post_bundle', [$slug]);
                }
            } catch (\Throwable $e) {
                error_log("gTemplate: Failed to invalidate bundle '{$slug}': " . $e->getMessage());
            }
        }
    }

    if (!empty($invalidated)) {
        error_log("gTemplate: Invalidated post bundles for post {$post_id}: " . implode(', ', $invalidated));
    }
}

/**
 * Hook: Re-cache a post bundle (deferred event)
 */
function gtemplate_recache_post_bundle_handler(string $slug): void {
    gtemplate_cache_post_bundle($slug);
}
add_action('gtemplate_recache_post_bundle', 'gtemplate_recache_post_bundle_handler');

/**
 * Hook: Invalidate post bundle cache on post save
 */
function gtemplate_on_post_save_invalidate_bundles(int $post_id, \WP_Post $post, bool $update): void {
    // Only for published posts
    if ($post->post_type !== 'post' || $post->post_status !== 'publish') {
        return;
    }

    // Skip autosaves and revisions
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (wp_is_post_revision($post_id)) {
        return;
    }

    gtemplate_invalidate_post_bundle_cache($post_id);
}
add_action('save_post', 'gtemplate_on_post_save_invalidate_bundles', 20, 3);

/**
 * Hook: Invalidate post bundle cache on post delete
 */
function gtemplate_on_post_delete_invalidate_bundles(int $post_id): void {
    $post = get_post($post_id);
    if ($post && $post->post_type === 'post') {
        gtemplate_invalidate_post_bundle_cache($post_id);
    }
}
add_action('before_delete_post', 'gtemplate_on_post_delete_invalidate_bundles');

/**
 * Hook: Cache all bundles when customizer saves
 */
add_action('customize_save_after', function() {
    // Schedule bundle caching after customizer save
    wp_schedule_single_event(time() + 5, 'gtemplate_cache_all_bundles_event');
}, 25);

add_action('gtemplate_cache_all_bundles_event', 'gtemplate_cache_all_post_bundles');

// ============================================================================
// END POST BUNDLE VALKEY CACHING LAYER
// ============================================================================

/**
 * Trigger bundle rebuild via invalidation event
 *
 * @param string $site_id Site identifier
 */
function gtemplate_trigger_bundle_rebuild(string $site_id): void {
    try {
        $gNodeClient = gtemplate_gnode();
        if (!$gNodeClient) {
            return;
        }

        // Publish invalidation event to trigger daemon bundle rebuild
        $event = json_encode([
            'event' => 'bundle_rebuild_requested',
            'site_id' => $site_id,
            'timestamp' => time(),
        ]);

        $channel = "{$site_id}:events:invalidate";
        $gNodeClient->publish($channel, $event);

        error_log("gTemplate: Bundle rebuild triggered for {$site_id}");

    } catch (\Throwable $e) {
        error_log("gTemplate: Failed to trigger bundle rebuild: " . $e->getMessage());
    }
}

/**
 * Get JavaScript content for a template
 *
 * Maps template names to their JS files and returns the file content
 * for inclusion in gNode bundles.
 *
 * @param string $template_name Template name (e.g., 'contact-form')
 * @return string|null JS content or null if no JS for this template
 */
function gtemplate_get_template_js_content(string $template_name): ?string {
    // Map templates to their JS files
    $template_js_map = [
        'contact-form' => 'contact-form.js',
        'newsletter-signup' => 'contact-form.js',
        'booking-form' => 'contact-form.js',
    ];

    if (!isset($template_js_map[$template_name])) {
        return null;
    }

    $js_file = $template_js_map[$template_name];
    $js_path = get_template_directory() . '/assets/js/' . $js_file;

    if (!file_exists($js_path)) {
        error_log("gTemplate: Template JS file not found: {$js_path}");
        return null;
    }

    $content = file_get_contents($js_path);
    if ($content === false) {
        error_log("gTemplate: Failed to read template JS file: {$js_path}");
        return null;
    }

    return $content;
}

/**
 * Hook: Also sync face mapping when customizer saves
 */
add_action('customize_save_after', function() {
    // Sync face mapping after a short delay
    wp_schedule_single_event(time() + 3, 'gtemplate_sync_face_mapping_event');
}, 20);

add_action('gtemplate_sync_face_mapping_event', 'gtemplate_sync_face_mapping_to_valkey');

/**
 * WP-CLI command to manually sync face mapping
 */
if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('gtemplate sync-faces', function($args, $assoc_args) {
        $result = gtemplate_sync_face_mapping_to_valkey();
        if ($result) {
            \WP_CLI::success('Face mapping synced to ValKey');
        } else {
            \WP_CLI::error('Failed to sync face mapping');
        }
    });
}

// ============================================================================
// FULL-PAGE VALKEY CACHING (gNode Premium Feature)
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
                // Send cached response
                header('Content-Type: text/html; charset=utf-8');
                header('X-Cache: HIT');
                header('X-Cache-Age: ' . (time() - ($data['cached_at'] ?? 0)));

                // Send any cached headers
                if (!empty($data['headers'])) {
                    foreach ($data['headers'] as $header) {
                        header($header);
                    }
                }

                echo $data['html'];
                exit;
            }
        }
    } catch (\Throwable $e) {
        error_log('gTemplate: Page cache read error: ' . $e->getMessage());
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

        // Prepare cache data
        $cache_data = [
            'html' => $cached_html,
            'cached_at' => time(),
            'url' => $_SERVER['REQUEST_URI'] ?? '/',
            'headers' => [],  // Could capture custom headers if needed
        ];

        $json = json_encode($cache_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $ttl = 3600;  // 1 hour (matches CacheManager page group config)

        $result = $gNodeClient->fcall('GNODE_CACHE_SET', [], [$cache_key, $json, $ttl, $site_id]);

        if ($result !== false && $result !== null) {
            // Add MISS header for first request
            if (!headers_sent()) {
                header('X-Cache: MISS');
            }
            error_log("gTemplate: Cached page " . ($_SERVER['REQUEST_URI'] ?? '/') . " (" . strlen($json) . " bytes)");
        }

    } catch (\Throwable $e) {
        error_log('gTemplate: Page cache write error: ' . $e->getMessage());
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

        $gNodeClient->fcall('GNODE_CACHE_DELETE', [], [$cache_key, $site_id]);
        error_log("gTemplate: Invalidated page cache for {$url}");

    } catch (\Throwable $e) {
        error_log('gTemplate: Page cache invalidation error: ' . $e->getMessage());
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
 */
function gtemplate_invalidate_all_fullpage_cache(): void {
    try {
        $gNodeClient = gtemplate_gnode();
        if (!$gNodeClient) {
            return;
        }

        $site_id = function_exists('\gTemplate\get_site_id_from_domain')
            ? \gTemplate\get_site_id_from_domain()
            : 'default';

        // Delete all page cache keys for this site using SCAN + DELETE pattern
        // For now, just log - proper implementation would use Lua script
        error_log("gTemplate: Full page cache invalidation requested for {$site_id}");

        // Schedule a proper cache clear via gNode
        wp_schedule_single_event(time(), 'gtemplate_full_cache_clear_event', [$site_id]);

    } catch (\Throwable $e) {
        error_log('gTemplate: Full cache invalidation error: ' . $e->getMessage());
    }
}

// Invalidate full cache on customizer save (theme changes affect all pages)
add_action('customize_save_after', 'gtemplate_invalidate_all_fullpage_cache', 100);

// ============================================================================
// END FULL-PAGE VALKEY CACHING
// ============================================================================

// ============================================================================
// PER-POST BUNDLE GENERATION
// ============================================================================

/**
 * Generate a rendered HTML bundle for a single post/page and store in ValKey
 *
 * @param int $post_id WordPress post ID
 * @return array|null Array with 'key', 'size', 'timestamp' on success, null on failure
 */
function gtemplate_generate_post_bundle(int $post_id): ?array {
    $post = get_post($post_id);
    if (!$post) {
        error_log("[gTemplate Bundle] Post {$post_id} not found");
        return null;
    }

    $site_id = gtemplate_get_site_id();
    $valkey_key = "{{$site_id}}:bundle:post_{$post_id}";
    $template_id = "wp_{$post->post_type}_{$post_id}";

    // Build content variables
    $variables = gtemplate_get_page_variables_direct($post_id);
    $html = null;

    // TIER 1: Try gNode KeyBasedClient rendering
    $keybased = gtemplate_gnode_keybased();
    if ($keybased) {
        try {
            $rendered = $keybased->renderTemplate($template_id, $variables);
            if ($rendered && !empty($rendered)) {
                $html = is_array($rendered) ? ($rendered['result'] ?? null) : $rendered;
            }
        } catch (\Throwable $e) {
            error_log("[gTemplate Bundle] KeyBased render failed for {$post_id}: " . $e->getMessage());
        }
    }

    // TIER 2: PHP fallback rendering
    if (!$html) {
        $html = gtemplate_render_page_fallback($post);
    }

    if (!$html) {
        error_log("[gTemplate Bundle] No HTML generated for post {$post_id}");
        return null;
    }

    // Store in ValKey
    $stored = false;
    if ($keybased) {
        try {
            $storage = $keybased->getStorage();
            if ($storage) {
                $bundle_data = json_encode([
                    'post_id' => $post_id,
                    'post_type' => $post->post_type,
                    'title' => $post->post_title,
                    'slug' => $post->post_name,
                    'html' => $html,
                    'trigger' => get_post_meta($post_id, '_gtemplate_bundle_trigger', true) ?: 'on_entry',
                    'generated_at' => time(),
                    'site_id' => $site_id,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                $storage->set($valkey_key, $bundle_data);
                $stored = true;
            }
        } catch (\Throwable $e) {
            error_log("[gTemplate Bundle] ValKey store failed for {$post_id}: " . $e->getMessage());
        }
    }

    // If ValKey unavailable, store as transient fallback
    if (!$stored) {
        set_transient("gtemplate_bundle_{$post_id}", $html, DAY_IN_SECONDS);
        error_log("[gTemplate Bundle] Stored post {$post_id} as transient fallback");
    }

    $size = strlen($html);
    $timestamp = time();

    // Update post meta
    update_post_meta($post_id, '_gtemplate_bundle_key', $valkey_key);
    update_post_meta($post_id, '_gtemplate_bundle_generated_at', $timestamp);
    update_post_meta($post_id, '_gtemplate_bundle_size', $size);

    error_log("[gTemplate Bundle] Generated bundle for post {$post_id}: {$valkey_key} (" . size_format($size) . ")");

    return [
        'key' => $valkey_key,
        'size' => $size,
        'timestamp' => $timestamp,
    ];
}

/**
 * Get ValKey key for a bundled post
 *
 * @param int $post_id WordPress post ID
 * @return string|null ValKey key or null if not bundled
 */
function gtemplate_get_bundle_key(int $post_id): ?string {
    $bundled = get_post_meta($post_id, '_gtemplate_bundled', true);
    if (!$bundled) {
        return null;
    }
    return get_post_meta($post_id, '_gtemplate_bundle_key', true) ?: null;
}
