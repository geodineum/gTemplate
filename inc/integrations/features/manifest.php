<?php
/**
 * ManifestManager Integration for gTemplate PWA
 *
 * Enables gTemplate to be installed as a Progressive Web App (PWA) on
 * mobile and desktop devices. Provides full-screen immersive 3D cube
 * experience with home screen icon and offline potential.
 *
 * @package gTemplate
 * @since 1.0.0
 * @version 1.0.0
 *
 * Features:
 * - Installable as native-like app
 * - Full-screen mode (no browser chrome)
 * - Custom cube-themed icons
 * - Theme color integration (#e51022)
 * - Face-specific deep linking
 * - Service worker ready (future offline support)
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Initialize ManifestManager for gTemplate PWA support
 *
 * @param \gCore\Modules\Core\gCore $gCore
 * @return \gCore\Modules\Managers\Base\ManifestManager\ManifestManager|null
 */
function gtemplate_init_manifest_manager($gCore) {
    try {
        $manifest = $gCore->getService('ManifestManager');
        if (!$manifest) {
            error_log('gTemplate: ManifestManager not available from gCore');
            return null;
        }

        // Get cube theme configuration
        $theme_config = gtemplate_get_pwa_config();

        $manifest->initialize([
            'enabled' => true,
            'cache_enabled' => true,
            'ttl' => 86400, // 24 hours

            // App Identity
            'name' => $theme_config['name'],
            'short_name' => $theme_config['short_name'],
            'description' => $theme_config['description'],

            // Display Settings - Optimized for 3D Cube
            'display' => 'standalone',           // Full-screen, no browser chrome
            'orientation' => 'any',              // Support all orientations for cube rotation
            'start_url' => $theme_config['start_url'],
            'scope' => home_url('/'),

            // Theme Colors - Cube's signature red highlight
            'theme_color' => $theme_config['theme_color'],
            'background_color' => $theme_config['background_color'],

            // Icons
            'icon_192x192' => $theme_config['icon_192'],
            'icon_512x512' => $theme_config['icon_512'],

            // Multi-tenant
            'site_id' => gtemplate_get_site_id(),
            'node_id' => 'web-' . gethostname(),

            // Framework
            'framework' => 'wordpress',
            'rest_namespace' => gtemplate_get_rest_namespace()
        ]);

        error_log('gTemplate: ManifestManager initialized for PWA support');
        return $manifest;

    } catch (\Throwable $e) {
        error_log('gTemplate: ManifestManager init error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Get PWA configuration with defaults and customizer overrides
 *
 * @return array PWA configuration
 */
function gtemplate_get_pwa_config(): array {
    $site_name = get_bloginfo('name');
    $site_description = get_bloginfo('description');

    // Check for customizer overrides
    $config = [
        'name' => get_theme_mod('gtemplate_pwa_name', $site_name ?: 'gTemplate'),
        'short_name' => get_theme_mod('gtemplate_pwa_short_name', gtemplate_truncate_name($site_name, 12)),
        'description' => get_theme_mod('gtemplate_pwa_description', $site_description ?: 'Geodineum Theme Experience'),

        // Start on front face by default, or customizer choice
        'start_url' => get_theme_mod('gtemplate_pwa_start_url', home_url('/?face=1')),

        // Theme colors - use cube's signature colors
        'theme_color' => get_theme_mod('gtemplate_pwa_theme_color', '#e51022'),
        'background_color' => get_theme_mod('gtemplate_pwa_background_color', '#1a1a1a'),

        // Icons - check for custom or use defaults
        'icon_192' => gtemplate_get_pwa_icon(192),
        'icon_512' => gtemplate_get_pwa_icon(512),
    ];

    return $config;
}

/**
 * Get PWA icon URL for specified size
 *
 * Priority:
 * 1. Customizer setting
 * 2. Theme assets/icons/ directory
 * 3. Site icon (WordPress)
 * 4. Empty (ManifestManager handles gracefully)
 *
 * @param int $size Icon size (192 or 512)
 * @return string Icon URL
 */
function gtemplate_get_pwa_icon(int $size): string {
    $size_key = "gtemplate_pwa_icon_{$size}";

    // 1. Check customizer
    $customizer_icon = get_theme_mod($size_key, '');
    if (!empty($customizer_icon)) {
        return $customizer_icon;
    }

    // 2. Check theme assets
    $theme_icon_path = "/assets/icons/cube-{$size}.png";
    $theme_icon_file = get_template_directory() . $theme_icon_path;
    if (file_exists($theme_icon_file)) {
        return get_template_directory_uri() . $theme_icon_path;
    }

    // 3. Fallback to WordPress site icon
    $site_icon = get_site_icon_url($size);
    if (!empty($site_icon)) {
        return $site_icon;
    }

    // 4. No icon available
    return '';
}

/**
 * Truncate name for short_name field (max 12 chars for PWA)
 *
 * @param string|null $name Full name
 * @param int $max_length Maximum length
 * @return string Truncated name
 */
function gtemplate_truncate_name(?string $name, int $max_length = 12): string {
    if ($name === null || $name === '') {
        return 'gTemplate';
    }
    if (strlen($name) <= $max_length) {
        return $name;
    }

    // Try to break at word boundary
    $truncated = substr($name, 0, $max_length);
    $last_space = strrpos($truncated, ' ');

    if ($last_space !== false && $last_space > $max_length - 4) {
        return substr($truncated, 0, $last_space);
    }

    return $truncated;
}

/**
 * Add additional PWA meta tags to wp_head
 *
 * Supplements ManifestManager's addManifestLink() with:
 * - Apple-specific meta tags for iOS
 * - Microsoft tile configuration
 * - Mobile web app capable flags
 */
add_action('wp_head', function() {
    // Only output if PWA is enabled
    if (!apply_filters('gtemplate_pwa_enabled', true)) {
        return;
    }

    $config = gtemplate_get_pwa_config();
    $icon_192 = $config['icon_192'];
    $icon_512 = $config['icon_512'];
    $theme_color = $config['theme_color'];
    $app_name = $config['name'];

    // iOS Safari specific
    echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
    echo '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">' . "\n";
    echo '<meta name="apple-mobile-web-app-title" content="' . esc_attr($app_name) . '">' . "\n";

    // Apple touch icons
    if (!empty($icon_192)) {
        echo '<link rel="apple-touch-icon" sizes="192x192" href="' . esc_url($icon_192) . '">' . "\n";
    }
    if (!empty($icon_512)) {
        echo '<link rel="apple-touch-icon" sizes="512x512" href="' . esc_url($icon_512) . '">' . "\n";
    }

    // Microsoft Tile
    echo '<meta name="msapplication-TileColor" content="' . esc_attr($theme_color) . '">' . "\n";
    if (!empty($icon_192)) {
        echo '<meta name="msapplication-TileImage" content="' . esc_url($icon_192) . '">' . "\n";
    }

    // Mobile web app
    echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";

}, 1); // Priority 1 = very early in wp_head

/**
 * Register service worker for offline cube support (future)
 *
 * Currently registers a placeholder that can be expanded for:
 * - Offline cube face caching
 * - Background sync
 * - Push notifications
 */
add_action('wp_footer', function() {
    // Only if PWA enabled and service worker file exists
    if (!apply_filters('gtemplate_pwa_enabled', true)) {
        return;
    }

    $sw_path = get_template_directory() . '/assets/js/service-worker.js';
    if (!file_exists($sw_path)) {
        return; // No service worker yet - skip registration
    }

    $sw_url = get_template_directory_uri() . '/assets/js/service-worker.js';
    ?>
    <script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function() {
            navigator.serviceWorker.register('<?php echo esc_url($sw_url); ?>', {
                scope: '/'
            }).then(function(registration) {
                console.log('gTemplate SW registered:', registration.scope);
            }).catch(function(error) {
                console.log('gTemplate SW registration failed:', error);
            });
        });
    }
    </script>
    <?php
}, 99);

/**
 * Add PWA settings to WordPress Customizer
 */
add_action('customize_register', function($wp_customize) {
    // Add PWA Section
    $wp_customize->add_section('gtemplate_pwa_section', [
        'title' => __('PWA Settings', 'gtemplate'),
        'description' => __('Configure Progressive Web App settings for installable cube experience.', 'gtemplate'),
        'priority' => 120,
    ]);

    // App Name
    $wp_customize->add_setting('gtemplate_pwa_name', [
        'default' => get_bloginfo('name'),
        'sanitize_callback' => 'sanitize_text_field',
    ]);
    $wp_customize->add_control('gtemplate_pwa_name', [
        'label' => __('App Name', 'gtemplate'),
        'description' => __('Full name shown when installing the app.', 'gtemplate'),
        'section' => 'gtemplate_pwa_section',
        'type' => 'text',
    ]);

    // Short Name
    $wp_customize->add_setting('gtemplate_pwa_short_name', [
        'default' => '',
        'sanitize_callback' => 'sanitize_text_field',
    ]);
    $wp_customize->add_control('gtemplate_pwa_short_name', [
        'label' => __('Short Name', 'gtemplate'),
        'description' => __('Name shown on home screen (max 12 characters).', 'gtemplate'),
        'section' => 'gtemplate_pwa_section',
        'type' => 'text',
        'input_attrs' => ['maxlength' => 12],
    ]);

    // Theme Color
    $wp_customize->add_setting('gtemplate_pwa_theme_color', [
        'default' => '#e51022',
        'sanitize_callback' => 'sanitize_hex_color',
    ]);
    $wp_customize->add_control(new \WP_Customize_Color_Control($wp_customize, 'gtemplate_pwa_theme_color', [
        'label' => __('Theme Color', 'gtemplate'),
        'description' => __('Browser toolbar and splash screen color.', 'gtemplate'),
        'section' => 'gtemplate_pwa_section',
    ]));

    // Background Color
    $wp_customize->add_setting('gtemplate_pwa_background_color', [
        'default' => '#1a1a1a',
        'sanitize_callback' => 'sanitize_hex_color',
    ]);
    $wp_customize->add_control(new \WP_Customize_Color_Control($wp_customize, 'gtemplate_pwa_background_color', [
        'label' => __('Background Color', 'gtemplate'),
        'description' => __('Splash screen background color.', 'gtemplate'),
        'section' => 'gtemplate_pwa_section',
    ]));

    // Start Face
    $wp_customize->add_setting('gtemplate_pwa_start_face', [
        'default' => '1',
        'sanitize_callback' => 'absint',
    ]);
    $wp_customize->add_control('gtemplate_pwa_start_face', [
        'label' => __('Start Face', 'gtemplate'),
        'description' => __('Which cube face to show when app launches (0-5).', 'gtemplate'),
        'section' => 'gtemplate_pwa_section',
        'type' => 'select',
        'choices' => [
            '0' => __('Top (0)', 'gtemplate'),
            '1' => __('Front (1) - Default', 'gtemplate'),
            '2' => __('Right (2)', 'gtemplate'),
            '3' => __('Back (3)', 'gtemplate'),
            '4' => __('Left (4)', 'gtemplate'),
            '5' => __('Bottom (5)', 'gtemplate'),
        ],
    ]);
});

/**
 * Filter to build start_url from start_face setting
 */
add_filter('theme_mod_gtemplate_pwa_start_url', function($value) {
    if (empty($value)) {
        $face = get_theme_mod('gtemplate_pwa_start_face', 1);
        return home_url('/?face=' . absint($face));
    }
    return $value;
});

/**
 * Invalidate manifest cache when theme mods change
 */
add_action('customize_save_after', function() {
    global $gCore;

    if (!$gCore) {
        return;
    }

    try {
        $manifest = $gCore->getService('ManifestManager');
        if ($manifest && method_exists($manifest, 'invalidateCache')) {
            $manifest->invalidateCache();
            error_log('gTemplate: ManifestManager cache invalidated after customizer save');
        }
    } catch (\Throwable $e) {
        error_log('gTemplate: Failed to invalidate manifest cache: ' . $e->getMessage());
    }
});

/**
 * Add manifest endpoint to gTemplate REST API namespace
 *
 * This provides the manifest at /wp-json/gtemplate/v1/manifest
 * in addition to the gCore endpoint.
 */
add_action('rest_api_init', function() {
    register_rest_route(gtemplate_get_rest_namespace(), '/manifest', [
        'methods' => 'GET',
        'callback' => 'gtemplate_rest_get_manifest',
        'permission_callback' => '__return_true',
    ]);
});

/**
 * REST endpoint callback for manifest
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function gtemplate_rest_get_manifest($request) {
    global $gCore;

    try {
        // Debug: Check if we can use ManifestManager
        if ($gCore) {
            try {
                $manifest = $gCore->getService('ManifestManager');
                if ($manifest && $manifest->isInitialized()) {
                    return $manifest->getManifestJson();
                }
            } catch (\Throwable $e) {
                error_log('gTemplate: ManifestManager service error: ' . $e->getMessage());
                // Fall through to manual generation
            }
        }

        // Fallback: Generate manifest directly
        $config = gtemplate_get_pwa_config();

        $manifest_data = [
            'name' => $config['name'],
            'short_name' => $config['short_name'],
            'description' => $config['description'],
            'start_url' => $config['start_url'],
            'display' => 'standalone',
            'orientation' => 'any',
            'background_color' => $config['background_color'],
            'theme_color' => $config['theme_color'],
            'icons' => [],
            'scope' => home_url('/'),
            'lang' => get_locale(),
            'dir' => (function_exists('is_rtl') && is_rtl()) ? 'rtl' : 'ltr',
        ];

        // Add icons
        if (!empty($config['icon_192'])) {
            $manifest_data['icons'][] = [
                'src' => $config['icon_192'],
                'sizes' => '192x192',
                'type' => 'image/png',
                'purpose' => 'any maskable'
            ];
        }
        if (!empty($config['icon_512'])) {
            $manifest_data['icons'][] = [
                'src' => $config['icon_512'],
                'sizes' => '512x512',
                'type' => 'image/png',
                'purpose' => 'any maskable'
            ];
        }

        return new WP_REST_Response($manifest_data, 200, [
            'Cache-Control' => 'public, max-age=3600'
        ]);

    } catch (\Throwable $e) {
        error_log('gTemplate: Manifest REST error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        return new WP_REST_Response([
            'error' => 'Failed to generate manifest',
            'message' => WP_DEBUG ? $e->getMessage() : 'Internal error'
        ], 500);
    }
}
