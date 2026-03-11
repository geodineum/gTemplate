<?php
/**
 * Face Renderer
 *
 * 3-tier rendering pipeline: Bundle cache -> gNode templates -> PHP fallback.
 * Uses filter-based parameterization so child themes define their own
 * template mappings and face counts.
 *
 * @package    gTemplate
 * @subpackage Rendering
 * @since      1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Render a face/cell through the 3-tier pipeline
 *
 * TIER 1: Bundle cache (pre-rendered HTML from gNode)
 * TIER 2: gNode key-based rendering (live Tera template)
 * TIER 3: PHP fallback (WordPress content sources)
 *
 * @param int $face_id Face/cell index
 * @param array $data Additional render data (position, etc.)
 * @return string Rendered HTML
 */
function gtemplate_render_face($face_id, $data = []) {
    // TIER 1: Bundle
    if (function_exists('gtemplate_get_face_from_bundle')) {
        $bundle_html = gtemplate_get_face_from_bundle($face_id);
        if ($bundle_html) {
            if (GTEMPLATE_DEBUG) {
                error_log("gTemplate: Face {$face_id} rendered via BUNDLE");
            }
            return $bundle_html;
        }
    }

    // TIER 2: gNode key-based
    try {
        $gNode = gtemplate_gnode_keybased();
        if ($gNode) {
            $theme_prefix = gtemplate_get_theme_prefix();
            $default_templates = [];
            for ($i = 0; $i < gtemplate_get_face_count(); $i++) {
                $default_templates[$i] = $theme_prefix . '_face_' . $i;
            }
            $templates = apply_filters('gtemplate_face_templates', $default_templates, $face_id);
            $template_id = $templates[$face_id] ?? $theme_prefix . '_face_0';

            $template_data = array_merge([
                'site_id' => gtemplate_get_site_id(),
                'face_id' => $face_id,
                'position' => $data['position'] ?? 'unknown',
                'theme_url' => get_template_directory_uri(),
                'home_url' => home_url(),
                'blog_name' => get_bloginfo('name'),
                'blog_description' => get_bloginfo('description'),
            ], $data);

            $html = $gNode->renderTemplate($template_id, $template_data);
            if ($html && !empty($html)) {
                if (GTEMPLATE_DEBUG) {
                    error_log("gTemplate: Face {$face_id} rendered via KEY-BASED");
                }
                return $html;
            }
        }
    } catch (\Throwable $e) {
        error_log("gTemplate: gNode render failed for face {$face_id}: " . $e->getMessage());
    }

    // TIER 3: PHP fallback
    if (GTEMPLATE_DEBUG) {
        error_log("gTemplate: Face {$face_id} rendered via PHP FALLBACK");
    }
    return gtemplate_render_face_fallback($face_id, $data['position'] ?? 'unknown');
}

/**
 * PHP fallback renderer using WordPress content sources
 *
 * @param int $face_id Face/cell index
 * @param string $position CSS position class
 * @return string Rendered HTML
 */
function gtemplate_render_face_fallback($face_id, $position = 'unknown') {
    $content = gtemplate_get_face_content($face_id);

    ob_start();
    ?>
    <div class="face-container">
        <div class="face-content fallback" data-face-id="<?php echo esc_attr($face_id); ?>">
            <main class="face-body"><?php echo $content; ?></main>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
