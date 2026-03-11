<?php
if (!defined('ABSPATH')) exit;

function gtemplate_customizer_css() {
    $css = '';

    // Typography colors (shared)
    $heading_color = get_theme_mod('gtemplate_heading_color', '');
    $body_color = get_theme_mod('gtemplate_body_color', '');
    $link_color = get_theme_mod('gtemplate_link_color', '');

    if ($heading_color) $css .= "h1,h2,h3,h4,h5,h6{color:{$heading_color};}";
    if ($body_color) $css .= "body,.site-main{color:{$body_color};}";
    if ($link_color) $css .= "a{color:{$link_color};}";

    // Allow child themes to add their CSS
    $css = apply_filters('gtemplate_dynamic_css', $css);

    if (!empty($css)) {
        echo '<style id="gtemplate-customizer-css">' . $css . '</style>';
    }
}
add_action('wp_head', 'gtemplate_customizer_css', 100);

function gtemplate_js_config() {
    $config = apply_filters('gtemplate_js_config', []);
    if (!empty($config)) {
        echo '<script id="gtemplate-config">window.gTemplateConfig=' . json_encode($config) . ';</script>';
    }
}
add_action('wp_head', 'gtemplate_js_config', 101);
