<?php
/**
 * Customizer Section: Typography Colors
 *
 * Color configuration for text elements within content boxes:
 * paragraphs, headings (H1-H6).
 *
 * @package     gTemplate
 * @subpackage  Customizer
 * @since       1.0.0
 */

if (!defined('ABSPATH')) exit;

/**
 * Register Typography Colors customizer section and controls.
 *
 * @param WP_Customize_Manager $wp_customize
 */
function gtemplate_customizer_typography_colors($wp_customize) {

    // INDEX OF SECTION: TYPOGRAPHY COLORS
    $wp_customize->add_section('typography_colors', array(
        'title' => __('Typography Colors', 'gtemplate'),
        'description' => __('Configure colors for text elements within content boxes.', 'gtemplate'),
        'priority' => 186,
    ));

    // Paragraph color
    $wp_customize->add_setting('typography_color_p', array(
        'default' => '#ffffff',
        'sanitize_callback' => 'sanitize_hex_color',
        'transport' => 'refresh',
    ));
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'typography_color_p', array(
        'label' => __('Paragraph Color', 'gtemplate'),
        'description' => __('Color for paragraph text (p tags)', 'gtemplate'),
        'section' => 'typography_colors',
    )));

    // H1 color
    $wp_customize->add_setting('typography_color_h1', array(
        'default' => '#00f3ff',
        'sanitize_callback' => 'sanitize_hex_color',
        'transport' => 'refresh',
    ));
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'typography_color_h1', array(
        'label' => __('Heading 1 (H1) Color', 'gtemplate'),
        'description' => __('Color for main headings', 'gtemplate'),
        'section' => 'typography_colors',
    )));

    // H2 color
    $wp_customize->add_setting('typography_color_h2', array(
        'default' => '#00f3ff',
        'sanitize_callback' => 'sanitize_hex_color',
        'transport' => 'refresh',
    ));
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'typography_color_h2', array(
        'label' => __('Heading 2 (H2) Color', 'gtemplate'),
        'description' => __('Color for secondary headings', 'gtemplate'),
        'section' => 'typography_colors',
    )));

    // H3 color
    $wp_customize->add_setting('typography_color_h3', array(
        'default' => '#00f3ff',
        'sanitize_callback' => 'sanitize_hex_color',
        'transport' => 'refresh',
    ));
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'typography_color_h3', array(
        'label' => __('Heading 3 (H3) Color', 'gtemplate'),
        'description' => __('Color for tertiary headings', 'gtemplate'),
        'section' => 'typography_colors',
    )));

    // H4+ color
    $wp_customize->add_setting('typography_color_h4', array(
        'default' => '#00f3ff',
        'sanitize_callback' => 'sanitize_hex_color',
        'transport' => 'refresh',
    ));
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'typography_color_h4', array(
        'label' => __('Heading 4-6 (H4/H5/H6) Color', 'gtemplate'),
        'description' => __('Color for smaller headings', 'gtemplate'),
        'section' => 'typography_colors',
    )));
}
