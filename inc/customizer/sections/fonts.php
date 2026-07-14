<?php
declare(strict_types=1);
/**
 * Customizer Section: Font Settings
 *
 * Typography font family configuration for body, headings, buttons,
 * and extra fonts. Supports Google Fonts and local font stacks.
 *
 * @package     gTemplate
 * @subpackage  Customizer
 * @since       1.0.0
 */

if (!defined('ABSPATH')) exit;

/**
 * Register Font Settings customizer section and controls.
 *
 * @param WP_Customize_Manager $wp_customize
 */
function gtemplate_customizer_fonts($wp_customize) {

    // FONT SECTION
    $wp_customize->add_section('font', array(
        'title' => __('Font Settings', 'gtemplate'),
        'priority' => 183,
    ));

    // INDEX OF SECTION: FONT
    $font_settings = [
        'body_font' => [
            'label' => 'Body Font',
            'default_google' => 'Ubuntu:wght@300;400;700&display=swap',
            'default_local' => 'Ubuntu, sans-serif',
            'description' => 'The default font-family for the body text.'
        ],
        'heading_font' => [
            'label' => 'Heading Font',
            'default_google' => 'Ubuntu:wght@300;400;700&display=swap',
            'default_local' => 'Ubuntu, sans-serif',
            'description' => 'The default font family for headings.'
        ],
        'button_font' => [
            'label' => 'Button Font',
            'default_google' => 'Rubik:wght@400;500&display=swap',
            'default_local' => 'Rubik, sans-serif',
            'description' => 'The default font family for buttons, including navigation buttons.'
        ],
        'extra_font' => [
            'label' => 'Extra Font',
            'default_google' => 'Rubik:wght@300;400;700&display=swap',
            'default_local' => 'Rubik, sans-serif',
            'description' => 'An additional font for use with custom classes.'
        ]
    ];

    foreach ($font_settings as $setting_id => $values) {
        $wp_customize->add_setting($setting_id . '_source', [
            'default' => 'google',
            'sanitize_callback' => 'sanitize_text_field'
        ]);
        $wp_customize->add_control($setting_id . '_source', [
            'label' => __($values['label'] . ' Source', 'gtemplate'),
            'section' => 'font',
            'type' => 'radio',
            'choices' => [
                'google' => 'Google Font',
                'local' => 'Local Font'
            ]
        ]);
        $wp_customize->add_setting($setting_id . '_google', [
            'default' => $values['default_google'],
            'sanitize_callback' => 'sanitize_text_field'
        ]);
        $wp_customize->add_control($setting_id . '_google', [
            'label' => __($values['label'] . ' (Google)', 'gtemplate'),
            'description' => __($values['description'] . ' Enter the part of the Google Font URL after "https://fonts.googleapis.com/css2?family=". For example: Ubuntu:wght@300;400;700&display=swap', 'gtemplate'),
            'section' => 'font',
            'type' => 'text'
        ]);
        $wp_customize->add_setting($setting_id . '_local', [
            'default' => $values['default_local'],
            'sanitize_callback' => 'sanitize_text_field'
        ]);
        $wp_customize->add_control($setting_id . '_local', [
            'label' => __($values['label'] . ' (Local)', 'gtemplate'),
            'description' => __($values['description'] . ' Enter the font-family value for a locally available font. For example: Ubuntu, sans-serif', 'gtemplate'),
            'section' => 'font',
            'type' => 'text'
        ]);
    }
}
