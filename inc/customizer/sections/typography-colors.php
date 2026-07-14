<?php
declare(strict_types=1);
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

    // Controls register into the shared Colors section
    // ('colors', owned by site-colors.php) — one section for colors.

    // Paragraph color
    $wp_customize->add_setting('typography_color_p', array(
        'default' => '#ffffff',
        'sanitize_callback' => 'sanitize_hex_color',
        'transport' => 'refresh',
    ));
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'typography_color_p', array(
        'label' => __('Paragraph Color', 'gtemplate'),
        'description' => __('Color for paragraph text (p tags)', 'gtemplate'),
        'section' => 'colors',
        'priority' => 40,
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
        'section' => 'colors',
        'priority' => 41,
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
        'section' => 'colors',
        'priority' => 42,
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
        'section' => 'colors',
        'priority' => 43,
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
        'section' => 'colors',
        'priority' => 44,
    )));

    // ── Heading blur (text-shadow) radius controls ──
    $blur_settings = [
        'typography_blur_h1' => ['label' => 'H1 Blur Radius', 'default' => 20],
        'typography_blur_h2' => ['label' => 'H2 Blur Radius', 'default' => 15],
        'typography_blur_h3' => ['label' => 'H3 Blur Radius', 'default' => 10],
        'typography_blur_h4' => ['label' => 'H4-H6 Blur Radius', 'default' => 0],
    ];
    foreach ($blur_settings as $id => $vals) {
        $wp_customize->add_setting($id, [
            'default' => $vals['default'],
            'transport' => 'refresh',
            'sanitize_callback' => 'absint',
        ]);
        $wp_customize->add_control($id, [
            'label' => __($vals['label'], 'gtemplate'),
            'description' => __('Text-shadow blur in pixels (0 = off)', 'gtemplate'),
            'section' => 'colors',
            'priority' => 45,
            'type' => 'range',
            'input_attrs' => ['min' => 0, 'max' => 60, 'step' => 1],
        ]);
    }
}
