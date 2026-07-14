<?php
declare(strict_types=1);
/**
 * Translation Integration for gTemplate
 *
 * Integrates the TranslateManager extension for multilingual support.
 * Features:
 * - Browser language detection
 * - Manual translations via WordPress admin
 * - Auto-translation via InferenceManager (when available)
 * - Pre-rendered multilingual bundles via gNode (when available)
 * - Language switcher widget/shortcode
 *
 * @package gTemplate
 * @version 1.0.0
 */

namespace gTemplate;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Initialize TranslateManager integration
 */
function gtemplate_init_translation(): void
{
    // Check if TranslateManager is available
    $translate_manager_path = \get_template_directory() . '/inc/translate-manager/src/TranslateManager.php';

    if (!\file_exists($translate_manager_path)) {
        if (defined('GTEMPLATE_DEBUG') && GTEMPLATE_DEBUG) {
            \gtemplate_track_error('[gTemplate Translation] TranslateManager not found at: ' . $translate_manager_path);
        }
        return;
    }

    // Check if ModuleInterface is available (gCore dependency)
    // If not, TranslateManager can't be loaded - this is expected in standalone mode
    if (!\interface_exists('\gCore\Modules\Core\Interfaces\Shared\ModuleInterface')) {
        if (defined('GTEMPLATE_DEBUG') && GTEMPLATE_DEBUG) {
            \gtemplate_track_error('[gTemplate Translation] ModuleInterface not available - TranslateManager requires gCore framework');
        }
        return;
    }

    try {
        // Load TranslateManager components
        require_once $translate_manager_path;
        require_once \get_template_directory() . '/inc/translate-manager/src/LanguageDetector.php';
        require_once \get_template_directory() . '/inc/translate-manager/src/TranslationStorage.php';
        require_once \get_template_directory() . '/inc/translate-manager/src/StringRegistry.php';
        require_once \get_template_directory() . '/inc/translate-manager/src/AutoTranslator.php';

        // Get configuration from registration config
        $config = \function_exists('gtemplate_get_registration_config')
            ? \gtemplate_get_registration_config()
            : [];
        $translation_config = $config['translation'] ?? [];

        // Get gCore instance
        global $gCore;
        if (!$gCore) {
            \gtemplate_track_error('[gTemplate Translation] gCore not available');
            return;
        }

        // Get TranslateManager via gCore resolver (returns stub or extension automatically)
        $translate = $gCore->getService('TranslateManager');

        // Configure from site settings
        $translate->initialize([
            'default_language' => $translation_config['default_language'] ?? get_locale_language(),
            'supported_languages' => $translation_config['languages'] ?? ['en', 'nl', 'de', 'es', 'fr'],
            'cookie_expiry' => $translation_config['cookie_expiry'] ?? 30 * DAY_IN_SECONDS,
            'auto_detect' => $translation_config['auto_detect'] ?? true,
        ]);

        if (defined('GTEMPLATE_DEBUG') && GTEMPLATE_DEBUG) {
            $mode = $gCore->isExtensionInstalled('TranslateManager') ? 'full' : 'stub';
            \gtemplate_track_error("[gTemplate] TranslateManager initialized ({$mode} mode)");
        }

    } catch (\Throwable $e) {
        \gtemplate_track_error('[gTemplate Translation] Failed to initialize: ' . $e->getMessage());
    }
}
\add_action('after_setup_theme', 'gTemplate\\gtemplate_init_translation', 15);

/**
 * Get the locale language code (2-letter)
 */
function get_locale_language(): string
{
    $locale = \get_locale();
    return \substr($locale, 0, 2);
}

/**
 * Get TranslateManager instance
 *
 * @return object|null
 */
function gtemplate_get_translate_manager(): ?object
{
    global $gCore;

    if (!$gCore) {
        return null;
    }

    try {
        return $gCore->getService('TranslateManager');
    } catch (\Throwable $e) {
        // Service-registry-not-ready (early init / late shutdown). Caller
        // checks for null and degrades gracefully; logging would be noise.
        return null;
    }
}

/**
 * Whether a REAL translation backend is available (not the inert base-tier
 * stub). Ch.1 ships the stub, so this is false and the translation UI stays
 * hidden; the Chapter-2 Geodine-backed gcore-translate extension makes it true
 * and lights up the switcher, options and hreflang.
 */
function gtemplate_translation_available(): bool
{
    $mgr = gtemplate_get_translate_manager();
    return $mgr !== null
        && \method_exists($mgr, 'isAvailable')
        && $mgr->isAvailable();
}

/**
 * Template function: Get translated string
 *
 * @param string $key String key from registry
 * @param string|null $lang Target language (null = current)
 * @return string
 */
function gtemplate_translate(string $key, ?string $lang = null): string
{
    $translate = gtemplate_get_translate_manager();
    if (!$translate || !\method_exists($translate, 'getString')) {
        return $key;
    }
    return $translate->getString($key, $lang) ?? $key;
}

/**
 * Template function: Get current language
 *
 * @return string 2-letter language code
 */
function gtemplate_current_language(): string
{
    $translate = gtemplate_get_translate_manager();
    if (!$translate || !\method_exists($translate, 'getCurrentLanguage')) {
        return get_locale_language();
    }
    return $translate->getCurrentLanguage();
}

/**
 * Shortcode: Language Switcher
 *
 * Usage: [gtemplate_language_switcher style="dropdown" show_flags="true"]
 */
function gtemplate_language_switcher_shortcode(array $atts = []): string
{
    $atts = \shortcode_atts([
        'style' => 'dropdown',      // dropdown, flags, inline, select
        'show_flags' => 'true',
        'show_native' => 'true',
        'show_current' => 'true',
    ], $atts, 'gtemplate_language_switcher');

    $translate = gtemplate_get_translate_manager();
    if (!gtemplate_translation_available() || !\method_exists($translate, 'renderLanguageSwitcher')) {
        return '';
    }

    return $translate->renderLanguageSwitcher([
        'style' => $atts['style'],
        'show_flags' => \filter_var($atts['show_flags'], FILTER_VALIDATE_BOOLEAN),
        'show_native' => \filter_var($atts['show_native'], FILTER_VALIDATE_BOOLEAN),
        'show_current' => \filter_var($atts['show_current'], FILTER_VALIDATE_BOOLEAN),
    ]);
}
\add_shortcode('gtemplate_language_switcher', 'gTemplate\\gtemplate_language_switcher_shortcode');

/**
 * Add language switcher to header (optional via filter)
 */
function gtemplate_maybe_add_header_language_switcher(): void
{
    // Check if enabled in customizer
    $show_in_header = \get_theme_mod('gtemplate_language_switcher_header', false);

    if (!$show_in_header) {
        return;
    }

    $translate = gtemplate_get_translate_manager();
    if (!gtemplate_translation_available() || !\method_exists($translate, 'renderLanguageSwitcher')) {
        return;
    }

    echo '<div class="gtemplate-header-language-switcher">';
    echo $translate->renderLanguageSwitcher([
        'style' => \get_theme_mod('gtemplate_language_switcher_style', 'dropdown'),
        'show_flags' => \get_theme_mod('gtemplate_language_switcher_flags', true),
        'show_native' => \get_theme_mod('gtemplate_language_switcher_native', true),
    ]);
    echo '</div>';
}
\add_action('gtemplate_header_extras', 'gTemplate\\gtemplate_maybe_add_header_language_switcher');

/**
 * Add hreflang tags for SEO
 */
function gtemplate_add_hreflang_tags(): void
{
    $translate = gtemplate_get_translate_manager();
    if (!gtemplate_translation_available() || !\method_exists($translate, 'getSupportedLanguages')) {
        return;
    }

    $current_url = \home_url(\add_query_arg(null, null));
    $languages = $translate->getSupportedLanguages();
    $current_lang = gtemplate_current_language();

    foreach ($languages as $code => $info) {
        $lang_url = \add_query_arg('lang', $code, $current_url);
        $hreflang = ($code === $current_lang) ? 'x-default' : $code;

        \printf(
            '<link rel="alternate" hreflang="%s" href="%s" />' . "\n",
            \esc_attr($hreflang),
            \esc_url($lang_url)
        );
    }
}
\add_action('wp_head', 'gTemplate\\gtemplate_add_hreflang_tags', 5);

/**
 * Register customizer settings for translation
 */
function gtemplate_translation_customizer(object $wp_customize): void
{
    // Only expose translation options when a real backend is present. Ch.1
    // ships the inert stub; the Chapter-2 Geodine-backed gcore-translate
    // extension makes translation available and lights this section up.
    // Without the gate, users would see configurable options that do nothing.
    if (!gtemplate_translation_available()) {
        return;
    }

    // Translation Section
    $wp_customize->add_section('gtemplate_translation', [
        'title' => \__('Translation Settings', 'gtemplate'),
        'priority' => 85,
    ]);

    // Enable language switcher in header
    $wp_customize->add_setting('gtemplate_language_switcher_header', [
        'default' => false,
        'sanitize_callback' => 'wp_validate_boolean',
    ]);
    $wp_customize->add_control('gtemplate_language_switcher_header', [
        'type' => 'checkbox',
        'section' => 'gtemplate_translation',
        'label' => \__('Show language switcher in header', 'gtemplate'),
    ]);

    // Language switcher style
    $wp_customize->add_setting('gtemplate_language_switcher_style', [
        'default' => 'dropdown',
        'sanitize_callback' => 'sanitize_text_field',
    ]);
    $wp_customize->add_control('gtemplate_language_switcher_style', [
        'type' => 'select',
        'section' => 'gtemplate_translation',
        'label' => \__('Language switcher style', 'gtemplate'),
        'choices' => [
            'dropdown' => \__('Dropdown', 'gtemplate'),
            'flags' => \__('Flags only', 'gtemplate'),
            'inline' => \__('Inline list', 'gtemplate'),
            'select' => \__('Select box', 'gtemplate'),
        ],
    ]);

    // Show flags
    $wp_customize->add_setting('gtemplate_language_switcher_flags', [
        'default' => true,
        'sanitize_callback' => 'wp_validate_boolean',
    ]);
    $wp_customize->add_control('gtemplate_language_switcher_flags', [
        'type' => 'checkbox',
        'section' => 'gtemplate_translation',
        'label' => \__('Show country flags', 'gtemplate'),
    ]);

    // Show native names
    $wp_customize->add_setting('gtemplate_language_switcher_native', [
        'default' => true,
        'sanitize_callback' => 'wp_validate_boolean',
    ]);
    $wp_customize->add_control('gtemplate_language_switcher_native', [
        'type' => 'checkbox',
        'section' => 'gtemplate_translation',
        'label' => \__('Show native language names', 'gtemplate'),
    ]);
}
\add_action('customize_register', 'gTemplate\\gtemplate_translation_customizer');

/**
 * REST API endpoint for language switching
 */
function gtemplate_register_translation_rest_routes(): void
{
    // Commit 1.11.b: POST mutates session-language state.
    // Pre-fix: `__return_true` + sole validator `is_string && strlen === 2`
    // — any 2-char attacker-controlled string passed. Post-fix:
    // (a) CSRF nonce via `gtemplate_rest_verify_nonce`,
    // (b) whitelist against TranslateManager::getSupportedLanguages()
    //     keys at validate_callback time.
    \register_rest_route(gtemplate_get_rest_namespace(), '/language', [
        'methods' => 'POST',
        'callback' => 'gTemplate\\gtemplate_set_language_endpoint',
        'permission_callback' => 'gtemplate_rest_verify_nonce',
        'args' => [
            'lang' => [
                'required' => true,
                'validate_callback' => function($param) {
                    if (!\is_string($param) || \strlen($param) !== 2) {
                        return false;
                    }
                    $translate = \gTemplate\gtemplate_get_translate_manager();
                    if (!$translate || !\method_exists($translate, 'getSupportedLanguages')) {
                        // Fail-closed: if TranslateManager isn't available
                        // we can't validate, so reject. Better than silently
                        // accepting any 2-char string.
                        return false;
                    }
                    $supported = $translate->getSupportedLanguages();
                    if (!\is_array($supported)) {
                        return false;
                    }
                    return \array_key_exists($param, $supported)
                        || \in_array($param, $supported, true);
                },
            ],
        ],
    ]);

    // GET stays public — read-only.
    \register_rest_route(gtemplate_get_rest_namespace(), '/language', [
        'methods' => 'GET',
        'callback' => 'gTemplate\\gtemplate_get_language_endpoint',
        'permission_callback' => '__return_true',
    ]);
}
\add_action('rest_api_init', 'gTemplate\\gtemplate_register_translation_rest_routes');

/**
 * Set language endpoint handler
 */
function gtemplate_set_language_endpoint(\WP_REST_Request $request): \WP_REST_Response
{
    $lang = \sanitize_text_field($request->get_param('lang'));

    $translate = gtemplate_get_translate_manager();
    if (!$translate || !\method_exists($translate, 'setCurrentLanguage')) {
        return new \WP_REST_Response([
            'success' => false,
            'message' => 'TranslateManager not available',
        ], 503);
    }

    $translate->setCurrentLanguage($lang);

    return new \WP_REST_Response([
        'success' => true,
        'language' => $lang,
    ]);
}

/**
 * Get language endpoint handler
 */
function gtemplate_get_language_endpoint(): \WP_REST_Response
{
    $translate = gtemplate_get_translate_manager();

    $current = gtemplate_current_language();
    $supported = [];

    if ($translate && \method_exists($translate, 'getSupportedLanguages')) {
        $supported = $translate->getSupportedLanguages();
    }

    return new \WP_REST_Response([
        'current' => $current,
        'supported' => $supported,
    ]);
}

/**
 * Add translation CSS
 */
function gtemplate_translation_styles(): void
{
    $css = '
    .gtemplate-header-language-switcher {
        position: absolute;
        top: 10px;
        right: 10px;
        z-index: 1000;
    }
    .gtemplate-language-switcher {
        font-size: 14px;
    }
    .gtemplate-language-switcher.style-flags .lang-flag {
        font-size: 20px;
        cursor: pointer;
        margin: 0 2px;
        opacity: 0.7;
        transition: opacity 0.2s;
    }
    .gtemplate-language-switcher.style-flags .lang-flag:hover,
    .gtemplate-language-switcher.style-flags .lang-flag.active {
        opacity: 1;
    }
    .gtemplate-language-switcher.style-dropdown {
        position: relative;
    }
    .gtemplate-language-switcher.style-dropdown .dropdown-toggle {
        background: rgba(255,255,255,0.1);
        border: 1px solid rgba(255,255,255,0.2);
        border-radius: 4px;
        padding: 5px 10px;
        cursor: pointer;
        color: inherit;
    }
    .gtemplate-language-switcher.style-dropdown .dropdown-menu {
        position: absolute;
        top: 100%;
        right: 0;
        background: #fff;
        border-radius: 4px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.15);
        min-width: 150px;
        display: none;
        z-index: 1001;
    }
    .gtemplate-language-switcher.style-dropdown.open .dropdown-menu {
        display: block;
    }
    .gtemplate-language-switcher.style-dropdown .dropdown-item {
        display: block;
        padding: 8px 12px;
        color: #333;
        text-decoration: none;
        transition: background 0.2s;
    }
    .gtemplate-language-switcher.style-dropdown .dropdown-item:hover {
        background: #f5f5f5;
    }
    ';

    \wp_add_inline_style('gtemplate-style', $css);
}
\add_action('wp_enqueue_scripts', 'gTemplate\\gtemplate_translation_styles', 20);
