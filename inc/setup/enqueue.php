<?php
if (!defined('ABSPATH')) exit;

function gtemplate_enqueue_parent_assets() {
    $theme_uri = get_template_directory_uri();
    $version = GTEMPLATE_VERSION;

    // Golden Typography System (shared across all child themes)
    wp_enqueue_style('gtemplate-golden-typography', $theme_uri . '/assets/css/golden-typography.css', [], $version);

    // Self-hosted fonts (Ubuntu, Rubik)
    wp_enqueue_style('gtemplate-fonts', $theme_uri . '/assets/css/fonts.css', [], $version);

    // Allow child themes to enqueue their geometry CSS
    $child_styles = apply_filters('gtemplate_styles', []);
    foreach ($child_styles as $handle => $style) {
        wp_enqueue_style($handle, $style['src'], $style['deps'] ?? ['gtemplate-golden-typography', 'gtemplate-fonts'], $style['ver'] ?? $version);
    }

    // Allow child themes to enqueue their geometry JS
    $child_scripts = apply_filters('gtemplate_scripts', []);
    foreach ($child_scripts as $handle => $script) {
        wp_enqueue_script($handle, $script['src'], $script['deps'] ?? [], $script['ver'] ?? $version, $script['args'] ?? ['in_footer' => true, 'strategy' => 'defer']);
    }

    // Localize main child script (if registered) with shared settings
    $primary_script = apply_filters('gtemplate_primary_script_handle', '');
    if ($primary_script && wp_script_is($primary_script, 'enqueued')) {
        $routing_data = gtemplate_detect_initial_routing();
        $base_settings = [
            'expandMode' => get_theme_mod('gtemplate_expand_mode', 'focus'),
            'expandEnabled' => (bool) get_theme_mod('gtemplate_expand_enabled', true),
            'showCloseButton' => (bool) get_theme_mod('gtemplate_expand_show_close', true),
            'showHint' => (bool) get_theme_mod('gtemplate_expand_show_hint', true),
            'maxZoom' => (int) get_theme_mod('gCore_max_zoom', 90),
            'initialCell' => $routing_data['cell'],
            'initialPostId' => $routing_data['post_id'],
            'initialPostSlug' => $routing_data['post_slug'],
            'restUrl' => rest_url(gtemplate_get_rest_namespace() . '/'),
            'siteUrl' => home_url('/'),
            'cellMapping' => gtemplate_get_face_mapping(),
        ];
        $settings = apply_filters('gtemplate_js_settings', $base_settings);
        $localize_name = apply_filters('gtemplate_js_settings_name', gtemplate_get_theme_prefix() . 'Settings');
        wp_localize_script($primary_script, $localize_name, $settings);
    }

    // PWA loader (shared)
    wp_enqueue_script('gtemplate-pwa-loader', $theme_uri . '/assets/js/pwa-loader.js', [], $version, ['in_footer' => true, 'strategy' => 'defer']);
}
add_action('wp_enqueue_scripts', 'gtemplate_enqueue_parent_assets');
