<?php
declare(strict_types=1);
/**
 * Configuration Export
 *
 * Exports all face/cell configurations for JavaScript, REST API,
 * and gNode template registration. Uses filter-based parameterization
 * for face count and prefix.
 *
 * @package    gTemplate
 * @subpackage Rendering
 * @since      1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get all face/cell configurations from the customizer
 *
 * @return array Indexed array of face configuration arrays
 */
function gtemplate_get_all_face_configs() {
    $configs = [];
    $face_prefix = gtemplate_get_face_prefix();
    $face_count = gtemplate_get_face_count();
    $defaults = apply_filters('gtemplate_default_labels', ['Home', 'About', 'Services', 'Portfolio', 'Blog', 'Contact']);

    for ($i = 0; $i < $face_count; $i++) {
        $configs[$i] = [
            'face_id' => $i,
            'label' => get_theme_mod("{$face_prefix}_{$i}_label", $defaults[$i] ?? ''),
            'source' => get_theme_mod("{$face_prefix}_{$i}_source", 'demo'),
            'content_id' => (int) get_theme_mod("{$face_prefix}_{$i}_content_id", 0),
            'custom_html' => get_theme_mod("{$face_prefix}_{$i}_custom_html", ''),
            'template_name' => get_theme_mod("{$face_prefix}_{$i}_template_name", ''),
            'category_filter' => get_theme_mod("{$face_prefix}_{$i}_category_filter", ''),
            'posts_per_page' => (int) get_theme_mod("{$face_prefix}_{$i}_posts_per_page", 10),
        ];
    }

    return $configs;
}
