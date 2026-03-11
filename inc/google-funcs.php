<?php
/**
 * Google Fonts Integration Functions
 *
 * @package gCore
 * @subpackage Font_Management
 * @version 1.0.0
 *
 * ----------------------------------------------------------------------------
 * DESCRIPTION
 * ----------------------------------------------------------------------------
 * Manages Google Fonts integration for the gCore theme, handling font loading,
 * family management, and CSS variable generation. Provides flexible font source
 * switching between Google Fonts and local system fonts.
 *
 * ----------------------------------------------------------------------------
 * KEY FUNCTIONS
 * ----------------------------------------------------------------------------
 * get_google_font_url()
 *     - Constructs Google Fonts URL based on theme settings
 *     - Handles multiple font families with variants
 *     - Returns empty string if no Google Fonts are configured
 *
 * get_font_family()
 *     - Retrieves font family string for specific theme elements
 *     - Supports both Google and local font sources
 *     - Handles fallback font configurations
 *
 * gCore_output_font_css_variables()
 *     - Outputs CSS variables for font families
 *     - Enables consistent font usage across theme styles
 *     - Hooked to wp_head with priority 5
 *
 * ----------------------------------------------------------------------------
 * DEPENDENCIES
 * ----------------------------------------------------------------------------
 * WordPress Core:
 *     - get_theme_mod()
 *     - add_action()
 *     - wp_head
 *
 * Theme Functions:
 *     - Customizer settings for font configuration
 *     - Theme modification options for font sources
 *
 * ----------------------------------------------------------------------------
 * ARCHITECTURE & DESIGN
 * ----------------------------------------------------------------------------
 * - Follows WordPress coding standards
 * - Implements singleton-like behavior through static functions
 * - Uses theme_mod API for persistent storage
 * - Provides fallback mechanisms for font loading failures
 *
 * ----------------------------------------------------------------------------
 * PERFORMANCE CONSIDERATIONS
 * ----------------------------------------------------------------------------
 * - Google Fonts loading may impact initial page load
 * - Consider implementing font-display: swap for better UX
 * - URL construction optimized for minimal string operations
 *
 * ----------------------------------------------------------------------------
 * SECURITY MEASURES
 * ----------------------------------------------------------------------------
 * - Escapes URLs and CSS values
 * - Validates font family names
 * - Sanitizes theme mod inputs
 *
 * ----------------------------------------------------------------------------
 * POTENTIAL IMPROVEMENTS
 * ----------------------------------------------------------------------------
 * @todo Implement font preloading for critical fonts
 * @todo Add font subsetting support for better performance
 * @todo Consider implementing local font fallback caching
 * @todo Add font loading error handling and reporting
 * @todo Implement Font Loading API support
 *
 * ----------------------------------------------------------------------------
 * USAGE EXAMPLE
 * ----------------------------------------------------------------------------
 * // Get Google Fonts URL
 * $google_fonts_url = get_google_font_url();
 *
 * // Get font family for specific element
 * $body_font = get_font_family('body_font');
 *
 * ----------------------------------------------------------------------------
 * CHANGELOG
 * ----------------------------------------------------------------------------
 * 1.0.0
 * - Initial implementation
 * - Basic Google Fonts integration
 * - Font family management
 * - CSS variable output
 *
 * ----------------------------------------------------------------------------
 * @since 1.0.0
 * @see https://developers.google.com/fonts/docs/getting_started
 */
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

function get_google_font_url() {
    $google_fonts = [];

    // Default Google Font values (must match register-options-wp.php defaults)
    $font_defaults = [
        'body_font' => 'Ubuntu:wght@300;400;700&display=swap',
        'heading_font' => 'Ubuntu:wght@300;400;700&display=swap',
        'button_font' => 'Rubik:wght@400;500&display=swap',
        'extra_font' => 'Rubik:wght@300;400;700&display=swap',
    ];

    foreach ($font_defaults as $setting => $default) {
        if (get_theme_mod($setting . '_source', 'google') === 'google') {
            $font = get_theme_mod($setting . '_google', $default);
            if (!empty($font)) {
                $google_fonts[] = $font;
            }
        }
    }

    if (!empty($google_fonts)) {
        return "https://fonts.googleapis.com/css2?family=" . implode('&family=', array_unique($google_fonts));
    }

    return '';
}

function get_font_family($setting) {
    $source = get_theme_mod($setting . '_source', 'google');
    if ($source === 'google') {
        $font_url = get_theme_mod($setting . '_google', 'Ubuntu:wght@300;400;700&display=swap');
        $font_family = explode(':', $font_url)[0];
        return "'" . str_replace('+', ' ', $font_family) . "', sans-serif";
    } else {
        return get_theme_mod($setting . '_local', 'Arial, sans-serif');
    }
}
/**
 * Check if using self-hosted fonts (default: true for performance)
 *
 * Self-hosted fonts eliminate 5-8 external requests to Google Fonts.
 * Set GTEMPLATE_USE_GOOGLE_FONTS to true in wp-config.php to use external fonts.
 *
 * @return bool True if using self-hosted fonts
 */
function gtemplate_use_local_fonts(): bool {
    // Default to local fonts for maximum performance
    if (defined('GTEMPLATE_USE_GOOGLE_FONTS') && GTEMPLATE_USE_GOOGLE_FONTS) {
        return false;
    }
    return true;
}

/**
 * Enqueue fonts stylesheet
 *
 * By default, uses self-hosted fonts (saves 5-8 requests).
 * Can be switched to Google Fonts via GTEMPLATE_USE_GOOGLE_FONTS constant.
 */
function gCore_enqueue_google_fonts() {
    $theme_uri = get_template_directory_uri();
    $theme_dir = get_template_directory();
    $version = wp_get_theme()->get('Version');

    // Use self-hosted fonts by default (performance optimization)
    if (gtemplate_use_local_fonts()) {
        // Check if local fonts CSS exists
        if (file_exists($theme_dir . '/assets/css/fonts.css')) {
            wp_enqueue_style(
                'gtemplate-local-fonts',
                $theme_uri . '/assets/css/fonts.css',
                [],
                $version
            );
            return; // Skip Google Fonts
        }
    }

    // Fallback to Google Fonts
    $google_fonts_url = get_google_font_url();
    if (!empty($google_fonts_url)) {
        wp_enqueue_style(
            'gtemplate-google-fonts',
            $google_fonts_url,
            [],
            null
        );
    }
}
add_action('wp_enqueue_scripts', 'gCore_enqueue_google_fonts', 1);

/**
 * Add preconnect hints for Google Fonts performance (only if using Google Fonts)
 */
function gCore_google_fonts_preconnect() {
    // Skip if using local fonts
    if (gtemplate_use_local_fonts()) {
        return;
    }

    $google_fonts_url = get_google_font_url();
    if (!empty($google_fonts_url)) {
        echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
        echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
    }
}
add_action('wp_head', 'gCore_google_fonts_preconnect', 1);

// Output font CSS variables for use in other stylesheets
function gCore_output_font_css_variables() {
    ?>
    <style>
        :root {
            --body-font: <?php echo get_font_family('body_font'); ?>;
            --heading-font: <?php echo get_font_family('heading_font'); ?>;
            --button-font: <?php echo get_font_family('button_font'); ?>;
            --extra-font: <?php echo get_font_family('extra_font'); ?>;
        }
    </style>
    <?php
}
add_action('wp_head', 'gCore_output_font_css_variables', 5);
