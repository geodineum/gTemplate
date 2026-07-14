<?php
declare(strict_types=1);
/**
 * Demo Content Source
 *
 * Provides generic demo content with configurable hint text.
 * Uses filter-based parameterization so child themes get
 * appropriate labels (face/cell) and content automatically.
 *
 * @package    gTemplate
 * @subpackage Rendering\ContentSources
 * @since      1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get demo content for a face/cell
 *
 * Child themes can override demo content via 'gtemplate_demo_content' filter.
 *
 * @param int $face_id Face/cell index
 * @return string HTML content
 */
function gtemplate_get_demo_content($face_id) {
    $face_label = gtemplate_get_face_label();
    $theme_prefix = gtemplate_get_theme_prefix();

    // Neutral, on-brand fallback. Child themes supply real content via the
    // 'gtemplate_demo_content' filter; this is only what a fresh install shows
    // before any section is configured.
    $hint = sprintf(
        /* translators: %s: the theme's word for a section (e.g. "section", "face"). */
        '<p class="muted">Configure this %s in the Customizer.</p>',
        esc_html($face_label)
    );
    $default_demos = [];
    for ($i = 0; $i < 6; $i++) {
        $default_demos[$i] = ['title' => '', 'content' => $hint];
    }

    $demos = apply_filters('gtemplate_demo_content', $default_demos, $face_id);
    $face_data = $demos[$face_id] ?? $demos[0] ?? ['title' => '', 'content' => $hint];

    ob_start();
    ?>
    <div class="face-content face-content-demo" data-source="demo" data-face-id="<?php echo esc_attr($face_id); ?>">
        <?php if (!empty($face_data['title'])): ?>
        <h2 class="face-content-title"><?php echo esc_html($face_data['title']); ?></h2>
        <?php endif; ?>
        <div class="face-content-body">
            <?php echo $face_data['content']; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
