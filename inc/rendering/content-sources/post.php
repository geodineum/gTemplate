<?php
/**
 * Post Content Source
 *
 * Renders WordPress single post content for a theme face/cell.
 *
 * @package    gTemplate
 * @subpackage Rendering\ContentSources
 * @since      1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get WordPress post content for a face
 *
 * @param int $post_id WordPress post ID
 * @param string $title_override Optional title override
 * @param bool $show_title Whether to show the title
 * @return string HTML content
 */
function gtemplate_get_post_content($post_id, $title_override = '', $show_title = true) {
    if ($post_id <= 0) {
        return gtemplate_get_empty_content_message('post');
    }

    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'post' || $post->post_status !== 'publish') {
        return gtemplate_get_empty_content_message('post', $post_id);
    }

    $title = !empty($title_override) ? $title_override : $post->post_title;
    $content = apply_filters('the_content', $post->post_content);
    $featured_image = get_the_post_thumbnail_url($post, 'large');
    $author = get_the_author_meta('display_name', $post->post_author);
    $date = get_the_date('', $post);

    ob_start();
    ?>
    <div class="face-content face-content-post" data-source="post" data-content-id="<?php echo esc_attr($post_id); ?>">
        <?php if ($show_title): ?>
        <h2 class="face-content-title"><?php echo esc_html($title); ?></h2>
        <?php endif; ?>

        <?php if ($featured_image): ?>
        <div class="face-featured-image">
            <img src="<?php echo esc_url($featured_image); ?>" alt="<?php echo esc_attr($title); ?>" loading="lazy">
        </div>
        <?php endif; ?>

        <div class="face-content-meta">
            <span class="face-content-author"><?php echo esc_html($author); ?></span>
            <span class="face-content-date"><?php echo esc_html($date); ?></span>
        </div>

        <div class="face-content-body">
            <?php echo $content; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
