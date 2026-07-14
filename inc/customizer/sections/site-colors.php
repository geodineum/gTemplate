<?php
declare(strict_types=1);
/**
 * Customizer: Colors Section (site palette)
 *
 * Owns the single 'colors' section (core section id) and registers the
 * shared site palette: background, text, header, gradient cycle,
 * scrollbar. Typography colors (typography-colors.php) and child-theme
 * colors register into the same section — one Colors section for colors.
 *
 * @package     gTemplate
 * @subpackage  Customizer
 */

if (!defined('ABSPATH')) exit;

/**
 * Register the Colors section and site palette controls.
 *
 * @param WP_Customize_Manager $wp_customize
 */
function gtemplate_customizer_site_colors($wp_customize) {

    $wp_customize->add_section('colors', array(
        'title' => __('Colors', 'gtemplate'),
        'description' => __('Site palette, gradient animation, scrollbar, and typography colors.', 'gtemplate'),
        'priority' => 23,
    ));

    // ── Background & text ──
    $wp_customize->add_setting('color_background', [
        'default' => '#1b1b2f',
        'sanitize_callback' => 'sanitize_hex_color',
        'transport' => 'refresh',
    ]);
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'color_background', [
        'label' => __('Background Color', 'gtemplate'),
        'description' => __('Site background fallback color', 'gtemplate'),
        'section' => 'colors',
        'priority' => 10,
    ]));

    $wp_customize->add_setting('color_text', [
        'default' => '#ffffff',
        'sanitize_callback' => 'sanitize_hex_color',
        'transport' => 'refresh',
    ]);
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'color_text', [
        'label' => __('Text Color', 'gtemplate'),
        'description' => __('Default body text color', 'gtemplate'),
        'section' => 'colors',
        'priority' => 11,
    ]));

    $wp_customize->add_setting('color_header', [
        'default' => '#00f3ff',
        'sanitize_callback' => 'sanitize_hex_color',
        'transport' => 'refresh',
    ]);
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'color_header', [
        'label' => __('Header Color', 'gtemplate'),
        'description' => __('Site header accent color', 'gtemplate'),
        'section' => 'colors',
        'priority' => 12,
    ]));

    // ── Gradient animation colors (used by gCube and legacy themes) ──
    $grad_colors = [
        'grad_color1' => ['default' => '#ee7752', 'label' => 'Gradient Color 1', 'priority' => 20],
        'grad_color2' => ['default' => '#e73c7e', 'label' => 'Gradient Color 2', 'priority' => 21],
        'grad_color3' => ['default' => '#23a6d5', 'label' => 'Gradient Color 3', 'priority' => 22],
        'grad_color4' => ['default' => '#23d5ab', 'label' => 'Gradient Color 4', 'priority' => 23],
    ];
    foreach ($grad_colors as $id => $vals) {
        $wp_customize->add_setting($id, [
            'default' => $vals['default'],
            'sanitize_callback' => 'sanitize_hex_color',
            'transport' => 'refresh',
        ]);
        $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, $id, [
            'label' => __($vals['label'], 'gtemplate'),
            'description' => __('Animated gradient cycle color', 'gtemplate'),
            'section' => 'colors',
            'priority' => $vals['priority'],
        ]));
    }

    // ── Scrollbar ──
    $wp_customize->add_setting('scrollbar_color1', [
        'default' => '#00f3ff',
        'sanitize_callback' => 'sanitize_hex_color',
        'transport' => 'refresh',
    ]);
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'scrollbar_color1', [
        'label' => __('Scrollbar Thumb Color', 'gtemplate'),
        'section' => 'colors',
        'priority' => 30,
    ]));

    $wp_customize->add_setting('scrollbar_color2', [
        'default' => '#1b1b2f',
        'sanitize_callback' => 'sanitize_hex_color',
        'transport' => 'refresh',
    ]);
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'scrollbar_color2', [
        'label' => __('Scrollbar Track Color', 'gtemplate'),
        'section' => 'colors',
        'priority' => 31,
    ]));

    // ── Geodineum content system (.geo) — accent + background ──
    $geo_palette = [
        'geo_gold'        => ['default' => '#c9a961', 'label' => 'Content Accent (gold)',    'desc' => 'Borders, rules, muted accents in .geo page content'],
        'geo_gold_bright' => ['default' => '#e8c468', 'label' => 'Content Accent (highlight)', 'desc' => 'Headings, labels, emphasis in .geo page content'],
        'geo_bg'          => ['default' => '#0a0a0d', 'label' => 'Content Background',        'desc' => 'Base background for .geo page content'],
    ];
    $geo_priority = 40;
    foreach ($geo_palette as $id => $vals) {
        $wp_customize->add_setting($id, [
            'default' => $vals['default'],
            'sanitize_callback' => 'sanitize_hex_color',
            'transport' => 'refresh',
        ]);
        $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, $id, [
            'label' => __($vals['label'], 'gtemplate'),
            'description' => __($vals['desc'], 'gtemplate'),
            'section' => 'colors',
            'priority' => $geo_priority++,
        ]));
    }

    // ── Cascading tooltips (window.GeoTip) — shared across all child themes ──
    // Color controls default empty so the tooltip inherits the theme tokens
    // (and flips with light/dark) until an operator picks an override.
    $tip_colors = [
        'tooltip_bg'     => 'Tooltip Background (blank = theme surface)',
        'tooltip_accent' => 'Tooltip Accent (blank = theme gold)',
        'tooltip_border' => 'Tooltip Border (blank = theme line)',
    ];
    foreach ($tip_colors as $id => $label) {
        $wp_customize->add_setting($id, [
            'default' => '',
            'sanitize_callback' => 'sanitize_hex_color',
            'transport' => 'refresh',
        ]);
        $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, $id, [
            'label' => __($label, 'gtemplate'),
            'section' => 'colors',
            'priority' => $geo_priority++,
        ]));
    }
    $tip_nums = [
        'tooltip_max_width'  => ['default' => 300, 'label' => 'Tooltip Max Width (px)',       'min' => 160, 'max' => 520],
        'tooltip_open_delay' => ['default' => 350, 'label' => 'Tooltip Open Delay (ms)',      'min' => 0,   'max' => 1200],
        'tooltip_hide_delay' => ['default' => 220, 'label' => 'Tooltip Close Grace (ms)',     'min' => 0,   'max' => 1200],
        'tooltip_max_depth'  => ['default' => 3,   'label' => 'Tooltip Nesting Depth',        'min' => 1,   'max' => 5],
    ];
    foreach ($tip_nums as $id => $vals) {
        $wp_customize->add_setting($id, [
            'default' => $vals['default'],
            'sanitize_callback' => 'absint',
            'transport' => 'refresh',
        ]);
        $wp_customize->add_control($id, [
            'label' => __($vals['label'], 'gtemplate'),
            'section' => 'colors',
            'type' => 'number',
            'input_attrs' => ['min' => $vals['min'], 'max' => $vals['max']],
            'priority' => $geo_priority++,
        ]);
    }
}
