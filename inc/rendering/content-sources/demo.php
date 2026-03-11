<?php
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

    $default_demos = [
        0 => ['title' => 'Welcome', 'content' => '<p>Experience the next-generation interface powered by the Geodineum stack.</p><p style="font-size:1.6vmin;color:#999;text-align:center;"><em>Configure this ' . $face_label . ' in Customizer</em></p>'],
        1 => ['title' => ucfirst($face_label) . ' 2', 'content' => '<p>This is the default content for ' . $face_label . ' 2.</p><p style="font-size:1.6vmin;color:#999;text-align:center;"><em>Configure in Customizer</em></p>'],
        2 => ['title' => ucfirst($face_label) . ' 3', 'content' => '<p>Content sources: Pages, Posts, Custom HTML, Templates, and more.</p><p style="font-size:1.6vmin;color:#999;text-align:center;"><em>Configure in Customizer</em></p>'],
        3 => ['title' => ucfirst($face_label) . ' 4', 'content' => '<p>Powered by gCore framework with gNode integration.</p><p style="font-size:1.6vmin;color:#999;text-align:center;"><em>Configure in Customizer</em></p>'],
        4 => ['title' => ucfirst($face_label) . ' 5', 'content' => '<p>3-tier rendering: Bundle cache &rarr; gNode templates &rarr; PHP fallback.</p><p style="font-size:1.6vmin;color:#999;text-align:center;"><em>Configure in Customizer</em></p>'],
        5 => ['title' => ucfirst($face_label) . ' 6', 'content' => '<p>Set up your content in Appearance &rarr; Customize.</p><p style="font-size:1.6vmin;color:#999;text-align:center;"><em>Configure in Customizer</em></p>'],
    ];

    $demos = apply_filters('gtemplate_demo_content', $default_demos, $face_id);
    $face_data = $demos[$face_id] ?? $demos[0] ?? ['title' => 'Demo', 'content' => '<p>Configure this ' . $face_label . ' in the Customizer.</p>'];

    ob_start();
    ?>
    <div class="face-content face-content-demo" data-source="demo" data-face-id="<?php echo esc_attr($face_id); ?>">
        <h2 class="face-content-title" style="font-size: 3vmin; margin-bottom: 20px; color: #e51022;">
            <?php echo esc_html($face_data['title']); ?>
        </h2>
        <div class="face-content-body">
            <?php echo $face_data['content']; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
