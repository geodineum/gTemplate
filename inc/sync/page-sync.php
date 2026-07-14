<?php
declare(strict_types=1);
/**
 * gTemplate page-sync — convert WP pages to Tera templates + register
 *
 * Extracted from inc/gnode-content-sync.php in Commit 1.10.c
 * to break the 1,900 LOC god-file into single-concern slices. Owns the
 * "WP page → Tera template" lifecycle:
 *   - generate template body from post content (gtemplate_page_to_tera_template)
 *   - register / re-register template with gNode (gtemplate_register_page_template)
 *   - cache + read template variables (gtemplate_cache_page_variables, get_page_variables[_direct])
 *   - WordPress hook wiring: save_post → auto-register, delete_post → unregister,
 *     save_post_page → invalidate per-page cache
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
        gtemplate_track_error("gTemplate: Cannot register page template, gNode unavailable (page: {$post->post_title})");
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
                gtemplate_track_error("gTemplate: Template registration for page {$post_id} already in progress");
                return false;
            }
        }
    } catch (\Throwable $e) {
        gtemplate_track_error("gTemplate: Lock acquisition failed, proceeding anyway: " . $e->getMessage());
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
            gtemplate_track_error("gTemplate: Registered template '{$template_id}' for page: {$post->post_title}");

            // Cache template variables for fast rendering
            gtemplate_cache_page_variables($post_id);

            return true;
        } else {
            gtemplate_track_error("gTemplate: Failed to register template '{$template_id}'");
            return false;
        }

    } catch (\Throwable $e) {
        gtemplate_track_error("gTemplate: Error registering template for page {$post_id}: " . $e->getMessage());
        return false;

    } finally {
        // Always release lock
        if ($lock && $cache) {
            try {
                $cache->releaseLock($lock);
            } catch (\Throwable $e) {
                gtemplate_track_error("gTemplate: Failed to release lock: " . $e->getMessage());
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
        gtemplate_track_error("gTemplate: Failed to cache page variables: " . $e->getMessage());
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
        gtemplate_track_error("gTemplate: Cache retrieval failed: " . $e->getMessage());
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
            gtemplate_track_error("gTemplate: Invalidated cache for page {$post_id}");
        }
    } catch (\Throwable $e) {
        gtemplate_track_error("gTemplate: Cache invalidation failed: " . $e->getMessage());
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
        gtemplate_track_error("gTemplate: Page {$post_id} deleted, template '{$template_id}' orphaned (no gNode deleteTemplate method)");

        // Invalidate cache
        gtemplate_invalidate_page_cache($post_id);
    }
}
add_action('delete_post', 'gtemplate_delete_page_template');
