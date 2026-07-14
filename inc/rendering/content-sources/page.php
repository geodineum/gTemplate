<?php
declare(strict_types=1);
/**
 * Page Content Source
 *
 * Renders WordPress page content for a theme face/cell.
 *
 * @package    gTemplate
 * @subpackage Rendering\ContentSources
 * @since      1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get WordPress page content for a face
 *
 * @param int $page_id WordPress page ID
 * @param string $title_override Optional title override
 * @param bool $show_title Whether to show the title
 * @return string HTML content
 */
function gtemplate_get_page_content($page_id, $title_override = '', $show_title = true) {
    if ($page_id <= 0) {
        return gtemplate_get_empty_content_message('page');
    }

    $page = get_post($page_id);
    if (!$page || $page->post_type !== 'page' || $page->post_status !== 'publish') {
        return gtemplate_get_empty_content_message('page', $page_id);
    }

    $title = !empty($title_override) ? $title_override : $page->post_title;
    $content = apply_filters('the_content', $page->post_content);
    $featured_image = get_the_post_thumbnail_url($page, 'large');

    ob_start();
    ?>
    <div class="face-content face-content-page" data-source="page" data-content-id="<?php echo esc_attr($page_id); ?>">
        <?php if ($show_title): ?>
        <h2 class="face-content-title"><?php echo esc_html($title); ?></h2>
        <?php endif; ?>

        <?php if ($featured_image): ?>
        <div class="face-featured-image">
            <img src="<?php echo esc_url($featured_image); ?>" alt="<?php echo esc_attr($title); ?>" loading="lazy">
        </div>
        <?php endif; ?>

        <div class="face-content-body">
            <?php echo $content; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
