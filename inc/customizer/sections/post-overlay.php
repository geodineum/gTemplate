<?php
/**
 * Customizer Section: Post Overlay Styling
 *
 * Layout, typography, colors, and meta visibility settings for the
 * expanded single post overlay (deep-linking view).
 *
 * @package     gTemplate
 * @subpackage  Customizer
 * @since       1.0.0
 */

if (!defined('ABSPATH')) exit;

/**
 * Register Post Overlay Styling customizer section and controls.
 *
 * @param WP_Customize_Manager $wp_customize
 */
function gtemplate_customizer_post_overlay($wp_customize) {

    // ===================================================================
    // INDEX OF SECTION: POST OVERLAY STYLING
    // Styles for the expanded single post overlay (deep-linking view)
    // ===================================================================
    $wp_customize->add_section('gtemplate_post_overlay', array(
        'title' => __('Post Overlay Styling', 'gtemplate'),
        'description' => __('Customize the appearance of expanded blog posts when opened via deep links or from the blog cell.', 'gtemplate'),
        'priority' => 187,
    ));

    // --- LAYOUT SETTINGS ---

    // Post overlay max width
    $wp_customize->add_setting('post_overlay_max_width', array(
        'default' => '900px',
        'sanitize_callback' => 'sanitize_text_field',
        'transport' => 'refresh',
    ));
    $wp_customize->add_control('post_overlay_max_width', array(
        'label' => __('Content Max Width', 'gtemplate'),
        'description' => __('Maximum width of the post content. Examples: 900px, 60rem, 70vw', 'gtemplate'),
        'section' => 'gtemplate_post_overlay',
        'type' => 'text',
    ));

    // Post overlay padding
    $wp_customize->add_setting('post_overlay_padding', array(
        'default' => '60px 20px 40px',
        'sanitize_callback' => 'sanitize_text_field',
        'transport' => 'refresh',
    ));
    $wp_customize->add_control('post_overlay_padding', array(
        'label' => __('Content Padding', 'gtemplate'),
        'description' => __('Padding around the post content. Examples: 60px 20px 40px, 2rem', 'gtemplate'),
        'section' => 'gtemplate_post_overlay',
        'type' => 'text',
    ));

    // Hero image max height
    $wp_customize->add_setting('post_overlay_hero_height', array(
        'default' => 50,
        'sanitize_callback' => 'absint',
        'transport' => 'refresh',
    ));
    $wp_customize->add_control('post_overlay_hero_height', array(
        'label' => __('Hero Image Max Height (vh)', 'gtemplate'),
        'description' => __('Maximum height of the featured image as percentage of viewport height', 'gtemplate'),
        'section' => 'gtemplate_post_overlay',
        'type' => 'range',
        'input_attrs' => array(
            'min' => 20,
            'max' => 80,
            'step' => 5,
        ),
    ));

    // --- TYPOGRAPHY SETTINGS ---

    // Post title size
    $wp_customize->add_setting('post_overlay_title_size_min', array(
        'default' => '1.8rem',
        'sanitize_callback' => 'sanitize_text_field',
        'transport' => 'refresh',
    ));
    $wp_customize->add_control('post_overlay_title_size_min', array(
        'label' => __('Title Size (Min)', 'gtemplate'),
        'description' => __('Minimum title size for fluid typography. Example: 1.8rem', 'gtemplate'),
        'section' => 'gtemplate_post_overlay',
        'type' => 'text',
    ));

    $wp_customize->add_setting('post_overlay_title_size_max', array(
        'default' => '3rem',
        'sanitize_callback' => 'sanitize_text_field',
        'transport' => 'refresh',
    ));
    $wp_customize->add_control('post_overlay_title_size_max', array(
        'label' => __('Title Size (Max)', 'gtemplate'),
        'description' => __('Maximum title size for fluid typography. Example: 3rem', 'gtemplate'),
        'section' => 'gtemplate_post_overlay',
        'type' => 'text',
    ));

    // Post body font size
    $wp_customize->add_setting('post_overlay_body_size', array(
        'default' => '1rem',
        'sanitize_callback' => 'sanitize_text_field',
        'transport' => 'refresh',
    ));
    $wp_customize->add_control('post_overlay_body_size', array(
        'label' => __('Body Font Size', 'gtemplate'),
        'description' => __('Font size for post body text. Example: 1rem, 16px, 1.1em', 'gtemplate'),
        'section' => 'gtemplate_post_overlay',
        'type' => 'text',
    ));

    // Post line height
    $wp_customize->add_setting('post_overlay_line_height', array(
        'default' => '1.8',
        'sanitize_callback' => 'sanitize_text_field',
        'transport' => 'refresh',
    ));
    $wp_customize->add_control('post_overlay_line_height', array(
        'label' => __('Line Height', 'gtemplate'),
        'description' => __('Line height for body text. Example: 1.8, 1.6, 2', 'gtemplate'),
        'section' => 'gtemplate_post_overlay',
        'type' => 'text',
    ));

    // --- COLOR SETTINGS ---

    // Post overlay background color
    $wp_customize->add_setting('post_overlay_bg_color', array(
        'default' => '#0a0a0f',
        'sanitize_callback' => 'sanitize_text_field',
        'transport' => 'refresh',
    ));
    $wp_customize->add_control('post_overlay_bg_color', array(
        'label' => __('Background Color', 'gtemplate'),
        'description' => __('Background color of the overlay. Supports hex or rgba.', 'gtemplate'),
        'section' => 'gtemplate_post_overlay',
        'type' => 'text',
    ));

    // Post title color
    $wp_customize->add_setting('post_overlay_title_color', array(
        'default' => '#00f3ff',
        'sanitize_callback' => 'sanitize_hex_color',
        'transport' => 'refresh',
    ));
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'post_overlay_title_color', array(
        'label' => __('Title Color', 'gtemplate'),
        'description' => __('Color for the post title', 'gtemplate'),
        'section' => 'gtemplate_post_overlay',
    )));

    // Post body text color
    $wp_customize->add_setting('post_overlay_body_color', array(
        'default' => 'rgba(255, 255, 255, 0.9)',
        'sanitize_callback' => 'sanitize_text_field',
        'transport' => 'refresh',
    ));
    $wp_customize->add_control('post_overlay_body_color', array(
        'label' => __('Body Text Color', 'gtemplate'),
        'description' => __('Color for post body text. Supports hex or rgba.', 'gtemplate'),
        'section' => 'gtemplate_post_overlay',
        'type' => 'text',
    ));

    // Post heading color (h2, h3, h4 in content)
    $wp_customize->add_setting('post_overlay_heading_color', array(
        'default' => '#00f3ff',
        'sanitize_callback' => 'sanitize_hex_color',
        'transport' => 'refresh',
    ));
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'post_overlay_heading_color', array(
        'label' => __('Content Headings Color', 'gtemplate'),
        'description' => __('Color for h2, h3, h4 within the post content', 'gtemplate'),
        'section' => 'gtemplate_post_overlay',
    )));

    // Post link color
    $wp_customize->add_setting('post_overlay_link_color', array(
        'default' => '#00f3ff',
        'sanitize_callback' => 'sanitize_hex_color',
        'transport' => 'refresh',
    ));
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'post_overlay_link_color', array(
        'label' => __('Link Color', 'gtemplate'),
        'description' => __('Color for links within post content', 'gtemplate'),
        'section' => 'gtemplate_post_overlay',
    )));

    // Meta text color
    $wp_customize->add_setting('post_overlay_meta_color', array(
        'default' => 'rgba(255, 255, 255, 0.6)',
        'sanitize_callback' => 'sanitize_text_field',
        'transport' => 'refresh',
    ));
    $wp_customize->add_control('post_overlay_meta_color', array(
        'label' => __('Meta Text Color', 'gtemplate'),
        'description' => __('Color for author, date, and category text', 'gtemplate'),
        'section' => 'gtemplate_post_overlay',
        'type' => 'text',
    ));

    // Border color for header/footer dividers
    $wp_customize->add_setting('post_overlay_border_color', array(
        'default' => 'rgba(0, 243, 255, 0.2)',
        'sanitize_callback' => 'sanitize_text_field',
        'transport' => 'refresh',
    ));
    $wp_customize->add_control('post_overlay_border_color', array(
        'label' => __('Border/Divider Color', 'gtemplate'),
        'description' => __('Color for header and footer divider lines', 'gtemplate'),
        'section' => 'gtemplate_post_overlay',
        'type' => 'text',
    ));

    // --- META VISIBILITY SETTINGS ---

    // Show author
    $wp_customize->add_setting('post_overlay_show_author', array(
        'default' => true,
        'sanitize_callback' => 'rest_sanitize_boolean',
        'transport' => 'refresh',
    ));
    $wp_customize->add_control('post_overlay_show_author', array(
        'label' => __('Show Author', 'gtemplate'),
        'description' => __('Display the post author in the meta section', 'gtemplate'),
        'section' => 'gtemplate_post_overlay',
        'type' => 'checkbox',
    ));

    // Show date
    $wp_customize->add_setting('post_overlay_show_date', array(
        'default' => true,
        'sanitize_callback' => 'rest_sanitize_boolean',
        'transport' => 'refresh',
    ));
    $wp_customize->add_control('post_overlay_show_date', array(
        'label' => __('Show Date', 'gtemplate'),
        'description' => __('Display the publication date in the meta section', 'gtemplate'),
        'section' => 'gtemplate_post_overlay',
        'type' => 'checkbox',
    ));

    // Show categories
    $wp_customize->add_setting('post_overlay_show_categories', array(
        'default' => true,
        'sanitize_callback' => 'rest_sanitize_boolean',
        'transport' => 'refresh',
    ));
    $wp_customize->add_control('post_overlay_show_categories', array(
        'label' => __('Show Categories', 'gtemplate'),
        'description' => __('Display categories in the meta section', 'gtemplate'),
        'section' => 'gtemplate_post_overlay',
        'type' => 'checkbox',
    ));

    // Show featured image
    $wp_customize->add_setting('post_overlay_show_hero', array(
        'default' => true,
        'sanitize_callback' => 'rest_sanitize_boolean',
        'transport' => 'refresh',
    ));
    $wp_customize->add_control('post_overlay_show_hero', array(
        'label' => __('Show Featured Image', 'gtemplate'),
        'description' => __('Display the featured image as a hero banner', 'gtemplate'),
        'section' => 'gtemplate_post_overlay',
        'type' => 'checkbox',
    ));
}
