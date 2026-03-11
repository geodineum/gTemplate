<?php
/**
 * Customizer Section: Logo Settings
 *
 * Logo dimensions, source image, alt text, and positioning.
 *
 * @package     gTemplate
 * @subpackage  Customizer
 * @since       1.0.0
 */

if (!defined('ABSPATH')) exit;

/**
 * Register Logo Settings customizer section and controls.
 *
 * @param WP_Customize_Manager $wp_customize
 */
function gtemplate_customizer_logo($wp_customize) {

    // LOGO SECTION
    $wp_customize->add_section('logo', array(
        'title' => __('Logo Settings', 'gtemplate'),
        'priority' => 173,
    ));

    //INDEX OF SECTION: LOGO
    // Logo settings
    for ($i = 1; $i <= 2; $i++) {
        if ($i == 1){
            $name = "width";
        } else {
            $name = "height";
        }
        $wp_customize->add_setting("logo_{$name}", array(
            'default' => "124px",
            'sanitize_callback' => 'sanitize_text_field',
            'transport' => 'refresh',
        ));
        $wp_customize->add_control("logo_{$name}", array(
            'label' => __("Logo Setting {$name}", 'gtemplate'),
            'section' => 'logo',
            'type' => 'text',
            'settings' => "logo_{$name}",
        ));
    }
    $wp_customize->add_setting('logo_source', array(
        'default' => '',
        'transport' => 'refresh',
        'sanitize_callback' => 'esc_url_raw'
    ));
    $wp_customize->add_control(new WP_Customize_Image_Control($wp_customize, 'source_logo', [
        'label' => __('Your logo', 'gtemplate'),
        'section' => 'logo',
        'settings' => 'logo_source',
    ]));
    $wp_customize->add_setting('logo_alt_text', array(
        'default' => get_bloginfo('name') . ' logo',
        'sanitize_callback' => 'sanitize_text_field',
    ));
    $wp_customize->add_control('logo_alt_text', array(
        'label' => __('Logo Alt Text', 'gtemplate'),
        'section' => 'logo',
        'type' => 'text',
    ));
    // Logo position (left, center, right)
    $wp_customize->add_setting('logo_position', array(
        'default' => 'center',
        'sanitize_callback' => 'sanitize_text_field',
        'transport' => 'refresh',
    ));
    $wp_customize->add_control('logo_position', array(
        'label' => __('Logo Position', 'gtemplate'),
        'description' => __('Position the logo horizontally on the page', 'gtemplate'),
        'section' => 'logo',
        'type' => 'select',
        'choices' => array(
            'left' => __('Left', 'gtemplate'),
            'center' => __('Center (default)', 'gtemplate'),
            'right' => __('Right', 'gtemplate'),
        ),
    ));
}
