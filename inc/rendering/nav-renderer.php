<?php
declare(strict_types=1);
/**
 * Navigation Renderer — 3D Cube Nav Buttons
 *
 * Renders navigation as mini 3D cubes instead of flat buttons.
 * Activated by child themes via:
 *   add_filter('gtemplate_nav_renderer', fn() => 'cube-3d');
 *
 * Each cube face is filterable:
 *   add_filter('gtemplate_nav_cube_face', fn($faces, $index, $data) => $faces, 10, 3);
 *
 * @package gTemplate
 * @since 2.1.0
 */

if (!defined('ABSPATH')) exit;

/**
 * Render 3D cube navigation
 *
 * Hooks into gtemplate_render_navigation when renderer is 'cube-3d'.
 *
 * @param array $faces Array of face/cell data
 * @param int $first_enabled Index of first enabled face
 */
function gtemplate_render_nav_cubes($faces, $first_enabled) {
    $face_label = apply_filters('gtemplate_face_label', 'face');

    // Split faces into left/right groups (same as flat buttons)
    $total = count($faces);
    $half = (int) ceil($total / 2);

    $left_faces = array_slice($faces, 0, $half, true);
    $right_faces = array_slice($faces, $half, null, true);

    echo '<div class="nav-layer left">';
    gtemplate_render_nav_cube_group($left_faces, $first_enabled, $face_label);
    echo '</div>';

    echo '<div class="nav-layer right">';
    gtemplate_render_nav_cube_group($right_faces, $first_enabled, $face_label);
    echo '</div>';
}

/**
 * Render a group of nav cubes
 */
function gtemplate_render_nav_cube_group($faces, $first_enabled, $face_label) {
    foreach ($faces as $index => $face) {
        if (!$face['enabled']) continue;

        $is_active = ($index === $first_enabled);
        $key = $face['key'] ?? "{$face_label}{$index}";

        // Default face content — front gets the label, others empty
        $cube_faces = [
            'front'  => esc_html($face['label']),
            'back'   => '',
            'right'  => '',
            'left'   => '',
            'top'    => '',
            'bottom' => '',
        ];

        /**
         * Filter: gtemplate_nav_cube_face
         *
         * Customize the content of each nav cube's six faces.
         *
         * @param array  $cube_faces  Associative array: front|back|right|left|top|bottom => HTML
         * @param int    $index       Face/cell index
         * @param array  $face        Face data (label, title, key, enabled, show_title)
         */
        $cube_faces = apply_filters('gtemplate_nav_cube_face', $cube_faces, $index, $face);

        $active_class = $is_active ? ' active' : '';
        // Anchor when the child supplies a URL (JS preventDefaults; href serves crawlers + middle-click)
        $tag  = !empty($face['url']) ? 'a' : 'div';
        $href = !empty($face['url']) ? ' href="' . esc_url($face['url']) . '"' : '';
        ?>
        <<?php echo $tag; ?> class="nav-cube-item<?php echo $active_class; ?>"<?php echo $href; ?>
             data-face="<?php echo esc_attr($index); ?>"
             data-key="<?php echo esc_attr($key); ?>"
             aria-pressed="<?php echo $is_active ? 'true' : 'false'; ?>"
             aria-label="<?php echo esc_attr($face['label']); ?>">
            <div class="nav-cube-scene">
                <div class="nav-cube">
                    <?php foreach ($cube_faces as $side => $content): ?>
                    <div class="nav-cube-face <?php echo esc_attr($side); ?>"><?php echo $content; ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </<?php echo $tag; ?>>
        <?php
    }
}

/**
 * Hook nav cube renderer into the navigation action
 * Only fires when the child theme has opted in
 */
add_action('gtemplate_render_navigation', function ($faces, $first_enabled) {
    $renderer = apply_filters('gtemplate_nav_renderer', 'flat');
    if ($renderer === 'cube-3d') {
        gtemplate_render_nav_cubes($faces, $first_enabled);
    }
}, 10, 2);
