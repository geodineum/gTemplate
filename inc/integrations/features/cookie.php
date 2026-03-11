<?php
/**
 * CookieManager Integration for gTemplate GDPR Compliance
 *
 * Provides GDPR-compliant cookie consent management with:
 * - Granular cookie categories (Essential, Functional, Analytics, Marketing)
 * - Cookie encryption and security
 * - WordPress privacy tools integration (data export/erasure)
 * - Multi-tenant isolation (site_id/node_id)
 *
 * @package gTemplate
 * @since 1.0.0
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Initialize CookieManager for gTemplate GDPR compliance
 *
 * @param \gCore\Modules\Core\gCore $gCore
 * @return \gCore\Modules\Managers\Base\CookieManager\CookieManager|null
 */
function gtemplate_init_cookie_manager($gCore) {
    try {
        $cookie = $gCore->getService('CookieManager');
        if (!$cookie) {
            error_log('gTemplate: CookieManager not available from gCore');
            return null;
        }

        $cookie->initialize([
            'enabled' => gtemplate_cookies_enabled(),
            'display_banner' => false,  // gTemplate provides its own styled banner
            'site_id' => gtemplate_get_site_id(),
            'node_id' => 'web-' . gethostname(),
            'consent_duration' => YEAR_IN_SECONDS,
            'require_explicit_consent' => true,
            'minimum_age' => 16, // GDPR default
            'encryption_key' => gtemplate_get_cookie_encryption_key(),
            'debug' => defined('WP_DEBUG') && WP_DEBUG
        ]);

        error_log('gTemplate: CookieManager initialized for GDPR compliance');
        return $cookie;

    } catch (\Throwable $e) {
        error_log('gTemplate: CookieManager init error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Check if cookie consent is enabled (customizer setting)
 *
 * @return bool
 */
function gtemplate_cookies_enabled(): bool {
    return (bool) get_theme_mod('gtemplate_cookies_enabled', true);
}

/**
 * Get cookie encryption key
 *
 * Uses WordPress AUTH_KEY for encryption. Falls back to site-specific
 * key if AUTH_KEY is default/empty.
 *
 * @return string|null Encryption key or null to disable encryption
 */
function gtemplate_get_cookie_encryption_key(): ?string {
    // Use WordPress AUTH_KEY if available and not default
    if (defined('AUTH_KEY') && AUTH_KEY !== 'put your unique phrase here' && strlen(AUTH_KEY) >= 32) {
        return AUTH_KEY;
    }

    // Fall back to site-specific key
    $site_key = get_option('gtemplate_cookie_key', '');
    if (empty($site_key)) {
        // Generate and store a key
        $site_key = wp_generate_password(64, true, true);
        update_option('gtemplate_cookie_key', $site_key, false);
    }

    return $site_key;
}

/**
 * Check if user has consent for a specific cookie category
 *
 * @param string $category Category name (essential, functional, analytics, marketing)
 * @return bool
 */
function gtemplate_has_cookie_consent(string $category): bool {
    global $gCore;

    if (!$gCore) {
        return $category === 'essential'; // Essential always allowed
    }

    try {
        $cookie = $gCore->getService('CookieManager');
        if ($cookie && $cookie->isInitialized()) {
            return $cookie->hasConsent($category);
        }
    } catch (\Throwable $e) {
        error_log('gTemplate: Cookie consent check error: ' . $e->getMessage());
    }

    return $category === 'essential';
}

/**
 * Update cookie consent preferences
 *
 * @param array $preferences Array of category => bool
 * @return bool Success
 */
function gtemplate_update_cookie_consent(array $preferences): bool {
    global $gCore;

    if (!$gCore) {
        return false;
    }

    try {
        $cookie = $gCore->getService('CookieManager');
        if ($cookie && $cookie->isInitialized()) {
            return $cookie->updateConsent($preferences);
        }
    } catch (\Throwable $e) {
        error_log('gTemplate: Cookie consent update error: ' . $e->getMessage());
    }

    return false;
}

/**
 * Get cookie consent status for all categories
 *
 * @return array Category => consent status
 */
function gtemplate_get_cookie_consent_status(): array {
    $categories = ['essential', 'functional', 'analytics', 'marketing'];
    $status = [];

    foreach ($categories as $category) {
        $status[$category] = gtemplate_has_cookie_consent($category);
    }

    return $status;
}

/**
 * Add cookie consent banner to footer
 *
 * Outputs a customizable consent banner with accept/reject options.
 * Styling respects the cube theme's design language.
 */
add_action('wp_footer', function() {
    // Skip if disabled via customizer
    if (!gtemplate_cookies_enabled()) {
        return;
    }

    // NOTE: Do NOT check server-side consent here. CookieManager stores consent
    // per-site (not per-user), so one user's accept hides the banner for everyone.
    // Banner visibility is handled client-side via localStorage (per-browser).

    // Get customizer settings
    $banner_text = get_theme_mod(
        'gtemplate_cookie_banner_text',
        __('We use cookies to enhance your experience. By continuing to visit this site you agree to our use of cookies.', 'gtemplate')
    );
    $privacy_url = get_theme_mod('gtemplate_cookie_privacy_url', get_privacy_policy_url());
    $theme_color = get_theme_mod('gtemplate_pwa_theme_color', '#e51022');

    ?>
    <div id="gtemplate-cookie-consent" class="gtemplate-cookie-banner" style="
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        background: rgba(0, 0, 0, 0.95);
        color: #fff;
        padding: 20px;
        z-index: 99999;
        box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.3);
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen-Sans, Ubuntu, Cantarell, sans-serif;
        display: none;
    ">
        <div style="max-width: 1200px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px;">
            <div style="flex: 1; min-width: 300px;">
                <p style="margin: 0 0 10px 0; font-size: 14px; line-height: 1.5;">
                    <?php echo esc_html($banner_text); ?>
                </p>
                <?php if ($privacy_url): ?>
                <a href="<?php echo esc_url($privacy_url); ?>" style="color: <?php echo esc_attr($theme_color); ?>; font-size: 12px;">
                    <?php esc_html_e('Learn more about our privacy policy', 'gtemplate'); ?>
                </a>
                <?php endif; ?>
            </div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <button type="button" onclick="gtemplateCookieConsent.acceptAll()" style="
                    background: <?php echo esc_attr($theme_color); ?>;
                    color: #fff;
                    border: none;
                    padding: 12px 24px;
                    cursor: pointer;
                    font-size: 14px;
                    font-weight: 600;
                    border-radius: 4px;
                    transition: opacity 0.2s;
                " onmouseover="this.style.opacity='0.9'" onmouseout="this.style.opacity='1'">
                    <?php esc_html_e('Accept All', 'gtemplate'); ?>
                </button>
                <button type="button" onclick="gtemplateCookieConsent.rejectNonEssential()" style="
                    background: transparent;
                    color: #fff;
                    border: 1px solid rgba(255,255,255,0.3);
                    padding: 12px 24px;
                    cursor: pointer;
                    font-size: 14px;
                    font-weight: 600;
                    border-radius: 4px;
                    transition: border-color 0.2s;
                " onmouseover="this.style.borderColor='rgba(255,255,255,0.6)'" onmouseout="this.style.borderColor='rgba(255,255,255,0.3)'">
                    <?php esc_html_e('Essential Only', 'gtemplate'); ?>
                </button>
                <button type="button" onclick="gtemplateCookieConsent.showSettings()" style="
                    background: transparent;
                    color: rgba(255,255,255,0.7);
                    border: none;
                    padding: 12px 16px;
                    cursor: pointer;
                    font-size: 12px;
                    text-decoration: underline;
                ">
                    <?php esc_html_e('Customize', 'gtemplate'); ?>
                </button>
            </div>
        </div>
    </div>

    <script>
    (function() {
        var banner = document.getElementById('gtemplate-cookie-consent');
        var consentKey = 'gtemplate_cookie_consent_<?php echo esc_js(gtemplate_get_site_id()); ?>';

        // Check if consent already given
        var stored = localStorage.getItem(consentKey);
        if (!stored) {
            banner.style.display = 'block';
        }

        window.gtemplateCookieConsent = {
            acceptAll: function() {
                banner.style.display = 'none';
                this.saveConsent({
                    essential: true,
                    functional: true,
                    analytics: true,
                    marketing: true
                });
            },

            rejectNonEssential: function() {
                banner.style.display = 'none';
                this.saveConsent({
                    essential: true,
                    functional: false,
                    analytics: false,
                    marketing: false
                });
            },

            showSettings: function() {
                var privacyUrl = '<?php echo esc_js($privacy_url); ?>';
                if (privacyUrl) {
                    window.location.href = privacyUrl + '#cookies';
                }
            },

            saveConsent: function(preferences) {
                // Save to localStorage first (client-side, always works)
                try {
                    localStorage.setItem(consentKey, JSON.stringify({
                        preferences: preferences,
                        timestamp: Date.now()
                    }));
                } catch(e) {
                    // localStorage unavailable (private browsing, quota) - banner still hides
                }

                // Send to server - fetch fresh nonce first to handle stale cached pages
                fetch('<?php echo esc_url(rest_url(gtemplate_get_rest_namespace() . '/cookie-consent')); ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>'
                    },
                    body: JSON.stringify(preferences)
                }).catch(function() {});

                // Dispatch event for other scripts
                try {
                    window.dispatchEvent(new CustomEvent('gtemplate-cookie-consent', {
                        detail: preferences
                    }));
                } catch(e) {}
            },

            hasConsent: function(category) {
                try {
                    var data = JSON.parse(localStorage.getItem(consentKey));
                    return data && data.preferences && data.preferences[category] === true;
                } catch(e) {
                    return category === 'essential';
                }
            }
        };
    })();
    </script>
    <?php
}, 100);

/**
 * REST API endpoint for saving cookie consent
 */
add_action('rest_api_init', function() {
    register_rest_route(gtemplate_get_rest_namespace(), '/cookie-consent', [
        'methods' => 'POST',
        'callback' => 'gtemplate_rest_save_cookie_consent',
        'permission_callback' => '__return_true',
        'args' => [
            'essential' => ['type' => 'boolean', 'default' => true],
            'functional' => ['type' => 'boolean', 'default' => false],
            'analytics' => ['type' => 'boolean', 'default' => false],
            'marketing' => ['type' => 'boolean', 'default' => false],
        ]
    ]);

    register_rest_route(gtemplate_get_rest_namespace(), '/cookie-consent', [
        'methods' => 'GET',
        'callback' => 'gtemplate_rest_get_cookie_consent',
        'permission_callback' => '__return_true',
    ]);
});

/**
 * REST endpoint callback for saving cookie consent
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function gtemplate_rest_save_cookie_consent($request) {
    $preferences = [
        'essential' => true, // Always true
        'functional' => (bool) $request->get_param('functional'),
        'analytics' => (bool) $request->get_param('analytics'),
        'marketing' => (bool) $request->get_param('marketing'),
    ];

    // Try to save to CookieManager (optional - client localStorage is the primary store)
    $saved = gtemplate_update_cookie_consent($preferences);

    // Always return 200 - consent is stored client-side regardless
    // Server-side storage is a bonus for cross-device consistency
    return new WP_REST_Response([
        'success' => true,
        'server_saved' => $saved,
        'preferences' => $preferences
    ], 200);
}

/**
 * REST endpoint callback for getting cookie consent status
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function gtemplate_rest_get_cookie_consent($request) {
    return new WP_REST_Response([
        'preferences' => gtemplate_get_cookie_consent_status(),
        'categories' => [
            'essential' => [
                'name' => __('Essential', 'gtemplate'),
                'description' => __('Required for basic website functionality.', 'gtemplate'),
                'required' => true
            ],
            'functional' => [
                'name' => __('Functional', 'gtemplate'),
                'description' => __('Enable enhanced functionality and preferences.', 'gtemplate'),
                'required' => false
            ],
            'analytics' => [
                'name' => __('Analytics', 'gtemplate'),
                'description' => __('Help us understand how visitors interact with the site.', 'gtemplate'),
                'required' => false
            ],
            'marketing' => [
                'name' => __('Marketing', 'gtemplate'),
                'description' => __('Used for targeted advertising.', 'gtemplate'),
                'required' => false
            ]
        ]
    ], 200);
}

/**
 * Add Cookie settings to WordPress Customizer
 */
add_action('customize_register', function($wp_customize) {
    // Add Cookie Section
    $wp_customize->add_section('gtemplate_cookie_section', [
        'title' => __('Cookie Consent', 'gtemplate'),
        'description' => __('Configure GDPR-compliant cookie consent settings.', 'gtemplate'),
        'priority' => 125,
    ]);

    // Enable/Disable
    $wp_customize->add_setting('gtemplate_cookies_enabled', [
        'default' => true,
        'sanitize_callback' => function($input) {
            return (bool) $input;
        },
    ]);
    $wp_customize->add_control('gtemplate_cookies_enabled', [
        'label' => __('Enable Cookie Consent Banner', 'gtemplate'),
        'description' => __('Show the cookie consent banner to visitors.', 'gtemplate'),
        'section' => 'gtemplate_cookie_section',
        'type' => 'checkbox',
    ]);

    // Banner Text
    $wp_customize->add_setting('gtemplate_cookie_banner_text', [
        'default' => __('We use cookies to enhance your experience. By continuing to visit this site you agree to our use of cookies.', 'gtemplate'),
        'sanitize_callback' => 'sanitize_text_field',
    ]);
    $wp_customize->add_control('gtemplate_cookie_banner_text', [
        'label' => __('Banner Text', 'gtemplate'),
        'description' => __('Main message shown in the cookie consent banner.', 'gtemplate'),
        'section' => 'gtemplate_cookie_section',
        'type' => 'textarea',
    ]);

    // Privacy Policy URL
    $wp_customize->add_setting('gtemplate_cookie_privacy_url', [
        'default' => '',
        'sanitize_callback' => 'esc_url_raw',
    ]);
    $wp_customize->add_control('gtemplate_cookie_privacy_url', [
        'label' => __('Privacy Policy URL', 'gtemplate'),
        'description' => __('Link to your privacy policy. Leave empty to use WordPress privacy page.', 'gtemplate'),
        'section' => 'gtemplate_cookie_section',
        'type' => 'url',
    ]);
});

/**
 * Filter: Conditionally load analytics scripts based on consent
 *
 * Example usage:
 * if (gtemplate_has_cookie_consent('analytics')) {
 *     // Load Google Analytics
 * }
 */
add_action('wp_head', function() {
    // Hook for other plugins/themes to check consent
    do_action('gtemplate_cookie_consent_check', gtemplate_get_cookie_consent_status());
}, 1);
