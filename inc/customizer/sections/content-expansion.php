<?php
/**
 * Customizer Section: Content Expansion Options
 *
 * Face expansion mode, zoom controls, and long-press duration
 * for the content area zoom/expand functionality.
 *
 * @package     gTemplate
 * @subpackage  Customizer
 * @since       1.0.0
 */

if (!defined('ABSPATH')) exit;

/**
 * Register Content Expansion Options customizer section and controls.
 *
 * @param WP_Customize_Manager $wp_customize
 */
function gtemplate_customizer_content_expansion($wp_customize) {

    // ZOOM functionality of main content area (also called: Content Expansion Options) SECTION
    $wp_customize->add_section('gCore_content_options', array(
        'title' => __('Content Expansion Options', 'gtemplate'),
        'priority' => 300,
    ));

    // INDEX OF SECTION: ZOOM (Content Expansion Options)

    // Face expansion mode setting
    $wp_customize->add_setting('gtemplate_expand_mode', array(
        'default' => 'focus',
        'sanitize_callback' => 'sanitize_text_field',
        'transport' => 'refresh',
    ));
    $wp_customize->add_control('gtemplate_expand_mode', array(
        'label' => __('Face Expansion Mode', 'gtemplate'),
        'description' => __('How cube faces expand when double-clicked', 'gtemplate'),
        'section' => 'gCore_content_options',
        'type' => 'select',
        'choices' => array(
            'focus' => __('Focus Mode - 100% viewport fullscreen', 'gtemplate'),
            'classic' => __('Classic Mode - Scaled 3D cube face', 'gtemplate'),
            'disabled' => __('Disabled - No expansion', 'gtemplate'),
        ),
    ));

    // Enable/disable expansion feature
    $wp_customize->add_setting('gtemplate_expand_enabled', array(
        'default' => true,
        'sanitize_callback' => 'absint',
        'transport' => 'refresh',
    ));
    $wp_customize->add_control('gtemplate_expand_enabled', array(
        'label' => __('Enable Face Expansion', 'gtemplate'),
        'description' => __('Allow double-click/tap to expand faces', 'gtemplate'),
        'section' => 'gCore_content_options',
        'type' => 'checkbox',
    ));

    // Show close button
    $wp_customize->add_setting('gtemplate_expand_show_close', array(
        'default' => true,
        'sanitize_callback' => 'absint',
        'transport' => 'refresh',
    ));
    $wp_customize->add_control('gtemplate_expand_show_close', array(
        'label' => __('Show Close Button', 'gtemplate'),
        'description' => __('Display X button to close expanded face', 'gtemplate'),
        'section' => 'gCore_content_options',
        'type' => 'checkbox',
    ));

    // Show expansion hint
    $wp_customize->add_setting('gtemplate_expand_show_hint', array(
        'default' => true,
        'sanitize_callback' => 'absint',
        'transport' => 'refresh',
    ));
    $wp_customize->add_control('gtemplate_expand_show_hint', array(
        'label' => __('Show Expansion Hint', 'gtemplate'),
        'description' => __('Display "Double-click to expand" hint on hover', 'gtemplate'),
        'section' => 'gCore_content_options',
        'type' => 'checkbox',
    ));

    $wp_customize->add_setting('gCore_max_zoom', array(
        'default' => '90',
        'sanitize_callback' => 'absint',
    ));
    $wp_customize->add_control('gCore_max_zoom', array(
        'label' => __('Maximum Content Zoom (%)', 'gtemplate'),
        'section' => 'gCore_content_options',
        'type' => 'range',
        'input_attrs' => array(
            'min' => 80,
            'max' => 100,
            'step' => 1,
        ),
    ));
    $wp_customize->add_setting('gCore_long_press_duration', array(
        'default' => '1300',
        'sanitize_callback' => 'absint',
    ));
    $wp_customize->add_control('gCore_long_press_duration', array(
        'label' => __('Long Press Duration (ms)', 'gtemplate'),
        'section' => 'gCore_content_options',
        'type' => 'range',
        'input_attrs' => array(
            'min' => 500,
            'max' => 2000,
            'step' => 50,
        ),
    ));
}
