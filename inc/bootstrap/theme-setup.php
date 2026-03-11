<?php
/**
 * gTemplate-wp Theme Setup
 *
 * Registers theme support features, navigation menus, and performs
 * initial WordPress configuration cleanup.
 *
 * @package    gTemplate
 * @subpackage Bootstrap
 * @since      1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Basic theme setup - register WordPress theme features
 */
function gtemplate_setup() {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');

    register_nav_menus([
        'primary' => __('Primary Menu', 'gtemplate'),
    ]);
}
add_action('after_setup_theme', 'gtemplate_setup', 10);

/**
 * Remove unnecessary HTTP headers for lean response
 */
function gtemplate_remove_unnecessary_headers() {
    remove_action('template_redirect', 'rest_output_link_header', 11);
    remove_action('template_redirect', 'wp_shortlink_header', 11);
    add_filter('pings_open', '__return_false', 9999);
}
add_action('init', 'gtemplate_remove_unnecessary_headers');
