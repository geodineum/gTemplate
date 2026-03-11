<?php
if (!defined('ABSPATH')) exit;

$gtemplate_customizer_dir = __DIR__ . '/sections';

// Shared sections (parent theme)
require_once $gtemplate_customizer_dir . '/logo.php';
require_once $gtemplate_customizer_dir . '/fonts.php';
require_once $gtemplate_customizer_dir . '/content-expansion.php';
require_once $gtemplate_customizer_dir . '/typography-colors.php';
require_once $gtemplate_customizer_dir . '/post-overlay.php';

function gtemplate_customize_register($wp_customize) {
    // Register shared/base sections
    gtemplate_customizer_logo($wp_customize);
    gtemplate_customizer_fonts($wp_customize);
    gtemplate_customizer_content_expansion($wp_customize);
    gtemplate_customizer_typography_colors($wp_customize);
    gtemplate_customizer_post_overlay($wp_customize);

    // Action: child themes register their geometry-specific sections here
    do_action('gtemplate_register_customizer_sections', $wp_customize);
}
add_action('customize_register', 'gtemplate_customize_register');
