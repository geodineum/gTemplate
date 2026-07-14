<?php
declare(strict_types=1);
/**
 * Custom HTML Content Source
 *
 * Renders user-provided HTML content for a theme face/cell.
 *
 * @package    gTemplate
 * @subpackage Rendering\ContentSources
 * @since      1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get custom HTML content for a face
 *
 * @param string $html Custom HTML content
 * @param string $title_override Optional title
 * @param bool $show_title Whether to show the title
 * @return string HTML content
 */
function gtemplate_get_custom_content($html, $title_override = '', $show_title = true) {
    if (empty($html)) {
        return gtemplate_get_empty_content_message('custom');
    }

    ob_start();
    ?>
    <div class="face-content face-content-custom" data-source="custom">
        <?php if ($show_title && !empty($title_override)): ?>
        <h2 class="face-content-title"><?php echo esc_html($title_override); ?></h2>
        <?php endif; ?>

        <div class="face-content-body">
            <?php echo wp_kses_post($html); ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
