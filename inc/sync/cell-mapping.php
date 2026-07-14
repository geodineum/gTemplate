<?php
declare(strict_types=1);
/**
 * gTemplate cell-mapping — face-cell → WP page resolution + sync
 *
 * Extracted from inc/gnode-content-sync.php in Commit 1.10.c.
 * Owns the "which WordPress page is on which cube/face cell" mapping
 * subsystem:
 *   - resolve cell_id → page_id from customizer (gtemplate_get_cell_page_mapping)
 *   - sync the resolved cells to gNode (gtemplate_sync_cells_to_gnode)
 *   - register the per-cell template with gNode (gtemplate_register_cell_template)
 *   - wrap custom-HTML cells in the canonical face wrapper (gtemplate_wrap_custom_html)
 *   - WordPress hook wiring: customize_save_after → deferred re-sync of cells
 *
 * @package gTemplate
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
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

    gtemplate_track_error("gTemplate: Cell sync complete - {$registered}/{$face_count} registered");

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
                    gtemplate_track_error("gTemplate: Failed to register custom template for cell {$cell_id}: " . $e->getMessage());
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
                        gtemplate_track_error("gTemplate: Template '{$templateName}' not found in library");
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
                    gtemplate_track_error("gTemplate: Failed to register library template for cell {$cell_id}: " . $e->getMessage());
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
