<?php
/**
 * Template Library Helper Functions
 *
 * Provides helper functions for integrating gCore's TemplateLibrary
 * with gTemplate's WordPress customizer.
 *
 * @package     gTemplate
 * @subpackage  Helpers
 * @version     1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get available template choices for customizer select control
 *
 * @return array Associative array of template_id => template_name
 */
function gtemplate_get_template_choices(): array {
    $choices = ['' => __('-- Select Template --', 'gtemplate')];

    try {
        // Try to get templates from gCore TemplateLibrary
        global $gCore;
        if ($gCore && method_exists($gCore, 'hasService') && $gCore->hasService('TemplateLibrary')) {
            $library = $gCore->getService('TemplateLibrary');
            if ($library && method_exists($library, 'listTemplates')) {
                $templates = $library->listTemplates();
                foreach ($templates as $template) {
                    $id = $template['id'] ?? '';
                    $name = $template['name'] ?? $id;
                    if ($id) {
                        $choices[$id] = $name;
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        // Log error but don't break customizer
        error_log('[gTemplate] Failed to load templates from TemplateLibrary: ' . $e->getMessage());
    }

    // Always include built-in templates as fallback
    $builtInTemplates = [
        'contact-form' => __('Contact Form', 'gtemplate'),
        'newsletter-signup' => __('Newsletter Signup', 'gtemplate'),
    ];

    foreach ($builtInTemplates as $id => $name) {
        if (!isset($choices[$id])) {
            $choices[$id] = $name;
        }
    }

    return $choices;
}

/**
 * Get template content by name
 *
 * Retrieves template content from gCore TemplateLibrary or falls back
 * to local template files.
 *
 * @param string $templateName Template identifier
 * @return string|null Template content (Tera syntax) or null if not found
 */
function gtemplate_get_template_content(string $templateName): ?string {
    try {
        // Try gCore TemplateLibrary first
        global $gCore;
        if ($gCore && method_exists($gCore, 'hasService') && $gCore->hasService('TemplateLibrary')) {
            $library = $gCore->getService('TemplateLibrary');
            if ($library && method_exists($library, 'getTemplate')) {
                $content = $library->getTemplate($templateName);
                if ($content) {
                    return $content;
                }
            }
        }
    } catch (\Throwable $e) {
        error_log('[gTemplate] Failed to get template from TemplateLibrary: ' . $e->getMessage());
    }

    // Fallback to local template files
    $templatePath = get_template_directory() . '/templates/faces/' . $templateName . '.tera';
    if (file_exists($templatePath)) {
        return file_get_contents($templatePath);
    }

    return null;
}

/**
 * Check if a template is a form template
 *
 * Form templates require CSRF token and security handling.
 *
 * @param string $templateName Template identifier
 * @return bool
 */
function gtemplate_is_form_template(string $templateName): bool {
    // Known form templates
    $formTemplates = ['contact-form', 'newsletter-signup', 'booking-form', 'comment-form', 'ticket-form'];

    if (in_array($templateName, $formTemplates)) {
        return true;
    }

    try {
        // Check via TemplateLibrary
        global $gCore;
        if ($gCore && method_exists($gCore, 'hasService') && $gCore->hasService('TemplateLibrary')) {
            $library = $gCore->getService('TemplateLibrary');
            if ($library && method_exists($library, 'isFormTemplate')) {
                return $library->isFormTemplate($templateName);
            }
        }
    } catch (\Throwable $e) {
        // Ignore errors, assume not a form
    }

    return false;
}

/**
 * Generate CSRF token for form templates
 *
 * @return string CSRF token
 */
function gtemplate_generate_csrf_token(): string {
    try {
        global $gCore;
        if ($gCore && method_exists($gCore, 'hasService') && $gCore->hasService('TemplateLibrary')) {
            $library = $gCore->getService('TemplateLibrary');
            if ($library && method_exists($library, 'generateCSRFToken')) {
                return $library->generateCSRFToken();
            }
        }
    } catch (\Throwable $e) {
        error_log('[gTemplate] Failed to generate CSRF token: ' . $e->getMessage());
    }

    // Fallback to WordPress nonce
    return wp_create_nonce('gcore_form_submit');
}

/**
 * Get template variables for rendering
 *
 * @param string $templateName Template identifier
 * @param int $faceId Face/cell ID
 * @param string $cellLabel Cell label
 * @return array Variables for template rendering
 */
function gtemplate_get_template_variables(string $templateName, int $faceId = 0, string $cellLabel = ''): array {
    $variables = [
        'blog_name' => get_bloginfo('name'),
        'site_url' => get_site_url(),
        'face_id' => $faceId,
        'cell_label' => $cellLabel,
        'api_base' => rest_url(gtemplate_get_rest_namespace()),
        'timestamp' => time(),
        'current_url' => home_url($_SERVER['REQUEST_URI'] ?? '/'),
    ];

    // Add CSRF token for form templates
    if (gtemplate_is_form_template($templateName)) {
        $variables['csrf_token'] = gtemplate_generate_csrf_token();
    }

    // Add contact info for contact form
    if ($templateName === 'contact-form') {
        // Get contact email - use customizer setting if set, otherwise admin email
        $theme_prefix = gtemplate_get_theme_prefix();
        $contactEmail = get_theme_mod($theme_prefix . '_contact_email', '');
        if (empty($contactEmail)) {
            $contactEmail = get_option('admin_email');
        }

        $phone = get_theme_mod($theme_prefix . '_contact_phone', '');
        $phoneProtect = get_theme_mod($theme_prefix . '_contact_phone_protect', true);

        // Build contact info array
        $contactInfo = [
            'email' => $contactEmail,
            'address' => get_theme_mod($theme_prefix . '_contact_address', ''),
            'hours' => get_theme_mod($theme_prefix . '_contact_hours', ''),
        ];

        // Handle phone number - pre-render HTML to avoid Tera array syntax issues
        if (!empty($phone)) {
            $phoneIcon = '<svg class="info-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>';

            if ($phoneProtect) {
                // Chunk phone for bot protection - split into 3-4 parts
                $cleanPhone = preg_replace('/[^0-9+]/', '', $phone);
                $displayPhone = esc_attr($phone);
                $len = strlen($cleanPhone);

                // Split into chunks (roughly equal parts, 3-4 chunks)
                $chunkSize = max(3, ceil($len / 4));
                $chunks = str_split($cleanPhone, $chunkSize);

                // Pre-render protected phone HTML
                $contactInfo['phone_html'] = sprintf(
                    '<p class="info-item">%s<strong>Phone:</strong><a href="#" class="protected-phone" data-p1="%s" data-p2="%s" data-p3="%s" data-p4="%s" data-display="%s"></a></p>',
                    $phoneIcon,
                    esc_attr($chunks[0] ?? ''),
                    esc_attr($chunks[1] ?? ''),
                    esc_attr($chunks[2] ?? ''),
                    esc_attr($chunks[3] ?? ''),
                    $displayPhone
                );
            } else {
                // Pre-render plain phone HTML
                $contactInfo['phone_html'] = sprintf(
                    '<p class="info-item">%s<strong>Phone:</strong><a href="tel:%s">%s</a></p>',
                    $phoneIcon,
                    esc_attr($phone),
                    esc_html($phone)
                );
            }
        }

        $variables['contact_info'] = $contactInfo;
    }

    // Allow filtering of template variables
    return apply_filters('gtemplate_template_variables', $variables, $templateName, $faceId);
}

/**
 * Get security fields HTML for form templates
 *
 * @return string HTML for hidden security fields
 */
function gtemplate_get_security_fields_html(): string {
    try {
        global $gCore;
        if ($gCore && method_exists($gCore, 'hasService') && $gCore->hasService('TemplateLibrary')) {
            $library = $gCore->getService('TemplateLibrary');
            if ($library && method_exists($library, 'generateSecurityFieldsHtml')) {
                $csrfToken = gtemplate_generate_csrf_token();
                return $library->generateSecurityFieldsHtml($csrfToken);
            }
        }
    } catch (\Throwable $e) {
        error_log('[gTemplate] Failed to generate security fields: ' . $e->getMessage());
    }

    // Fallback
    $csrfToken = gtemplate_generate_csrf_token();
    $timestamp = time();

    return <<<HTML
<input type="hidden" name="_csrf_token" value="{$csrfToken}">
<input type="hidden" name="_form_load_time" value="{$timestamp}">
<input type="hidden" name="_js_challenge" value="">
<input type="hidden" name="_behavior_data" value="">
HTML;
}

/**
 * Get honeypot fields HTML for form templates
 *
 * @return string HTML for honeypot fields (hidden spam protection)
 */
function gtemplate_get_honeypot_fields_html(): string {
    try {
        global $gCore;
        if ($gCore && method_exists($gCore, 'hasService') && $gCore->hasService('TemplateLibrary')) {
            $library = $gCore->getService('TemplateLibrary');
            if ($library && method_exists($library, 'generateHoneypotFieldsHtml')) {
                return $library->generateHoneypotFieldsHtml();
            }
        }
    } catch (\Throwable $e) {
        error_log('[gTemplate] Failed to generate honeypot fields: ' . $e->getMessage());
    }

    // Fallback
    return <<<HTML
<div class="hp-container" style="display:none !important; visibility:hidden; position:absolute; left:-9999px;" aria-hidden="true">
    <input type="url" name="website_url" autocomplete="url" tabindex="-1">
    <input type="tel" name="phone_number_2" autocomplete="tel" tabindex="-1">
    <input type="text" name="company_fax" autocomplete="fax" tabindex="-1">
</div>
HTML;
}

/**
 * Get security JavaScript for form templates
 *
 * @param string $formId Form element ID
 * @return string JavaScript code
 */
function gtemplate_get_security_javascript(string $formId): string {
    try {
        global $gCore;
        if ($gCore && method_exists($gCore, 'hasService') && $gCore->hasService('TemplateLibrary')) {
            $library = $gCore->getService('TemplateLibrary');
            if ($library && method_exists($library, 'generateSecurityJavaScript')) {
                return $library->generateSecurityJavaScript($formId);
            }
        }
    } catch (\Throwable $e) {
        error_log('[gTemplate] Failed to generate security JavaScript: ' . $e->getMessage());
    }

    // Fallback minimal JS
    return <<<JS
<script>
(function() {
    var form = document.getElementById('{$formId}');
    if (!form) return;

    var challengeField = form.querySelector('input[name="_js_challenge"]');
    if (challengeField) {
        var timestamp = Math.floor(Date.now() / 1000);
        var random = Math.random().toString(36).substring(2, 10);
        challengeField.value = 'gcore_' + timestamp + '_' + random;
    }

    var loadTimeField = form.querySelector('input[name="_form_load_time"]');
    if (loadTimeField && !loadTimeField.value) {
        loadTimeField.value = Math.floor(Date.now() / 1000);
    }
})();
</script>
JS;
}
