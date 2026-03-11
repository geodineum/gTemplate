<?php
/**
 * Posts Content Source
 *
 * Renders multiple WordPress posts (category-filtered, date-ordered)
 * as a scrollable list within a theme face/cell.
 *
 * @package    gTemplate
 * @subpackage Rendering\ContentSources
 * @since      1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get multiple posts content for a face (scrollable list)
 *
 * @param string $category_filter Comma-separated category IDs or slugs (empty = ALL posts)
 * @param int $posts_per_page Number of posts to display
 * @param int $cell_id Cell identifier for unique container
 * @param string $title_override Optional title override
 * @param bool $show_title Whether to show the title
 * @return string HTML content with scrollable posts
 */
function gtemplate_get_posts_content(string $category_filter = '', int $posts_per_page = 10, int $cell_id = 0, string $title_override = '', bool $show_title = true): string {
    $args = [
        'post_type' => 'post',
        'post_status' => 'publish',
        'posts_per_page' => $posts_per_page,
        'orderby' => 'date',
        'order' => 'DESC',
    ];

    if (!empty($category_filter)) {
        $filters = array_map('trim', explode(',', $category_filter));
        $category_ids = [];

        foreach ($filters as $filter) {
            if (is_numeric($filter)) {
                $category_ids[] = (int) $filter;
            } else {
                $cat = get_category_by_slug($filter);
                if ($cat) {
                    $category_ids[] = $cat->term_id;
                }
            }
        }

        if (!empty($category_ids)) {
            $args['category__in'] = $category_ids;
        }
    }

    $posts = get_posts($args);

    if (empty($posts)) {
        return '<div class="face-content face-posts-empty" data-source="posts"><div class="face-posts-message">' . esc_html__('No posts found.', 'gtemplate') . '</div></div>';
    }

    ob_start();
    ?>
    <div class="face-content face-content-posts" data-source="posts" data-face-id="<?php echo esc_attr($cell_id); ?>">
        <?php if ($show_title && !empty($title_override)): ?>
        <h2 class="face-content-title"><?php echo esc_html($title_override); ?></h2>
        <?php endif; ?>

        <div class="face-posts-container" data-face-id="<?php echo esc_attr($cell_id); ?>">
            <?php foreach ($posts as $post): ?>
            <article class="face-post-item" data-post-id="<?php echo esc_attr($post->ID); ?>">
                <?php if (has_post_thumbnail($post)): ?>
                <div class="face-post-thumbnail">
                    <?php echo get_the_post_thumbnail($post, 'medium'); ?>
                </div>
                <?php endif; ?>
                <div class="face-post-content">
                    <h3 class="face-post-title">
                        <a href="#" data-action="load-post" data-post-id="<?php echo esc_attr($post->ID); ?>">
                            <?php echo esc_html($post->post_title); ?>
                        </a>
                    </h3>
                    <div class="face-post-excerpt">
                        <?php echo wp_trim_words($post->post_content, 25); ?>
                    </div>
                    <div class="face-post-meta">
                        <span class="face-post-date"><?php echo get_the_date('', $post); ?></span>
                        <?php
                        $categories = get_the_category($post->ID);
                        if (!empty($categories)):
                        ?>
                        <span class="face-post-categories">
                            <?php echo esc_html($categories[0]->name); ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <div class="face-post-actions">
                        <button class="face-post-read-more" data-action="load-post" data-post-id="<?php echo esc_attr($post->ID); ?>">
                            <?php esc_html_e('Read More', 'gtemplate'); ?>
                        </button>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
