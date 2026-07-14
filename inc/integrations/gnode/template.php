<?php
declare(strict_types=1);
/**
 * Template Integration for gTemplate Theme
 *
 * Provides graceful template management capabilities:
 * - Extension: Full Tera engine rendering via gNode
 * - Default: Basic PHP variable substitution
 *
 * @package     gTemplate
 * @subpackage  Inc
 * @version     1.0.0
 * @since       1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Initialize TemplateManager with graceful fallback
 *
 * @param \gCore\gNode\gNodeClientInterface|null $gNodeClient gNode client instance
 * @return void
 */
function gtemplate_init_template_manager($gNodeClient = null) {
    global $gCore;

    if (!$gCore) {
        if (defined('GTEMPLATE_DEBUG') && GTEMPLATE_DEBUG) {
            gtemplate_track_error('[gTemplate] TemplateManager: gCore not available');
        }
        return;
    }

    try {
        // Get TemplateManager via gCore resolver (returns stub or extension automatically)
        $manager = $gCore->getService('TemplateManager');
        $manager->initialize([
            'site_id' => gtemplate_get_site_id(),
            'node_id' => 'web-' . gethostname(),
            'use_gnode' => $gNodeClient !== null,
            'gnode_client' => $gNodeClient,
            'csrf_ttl' => 3600,
            'rate_limit' => [
                'submissions_per_hour' => 10,
                'submissions_per_day' => 50
            ],
        ]);
        $GLOBALS['gtemplate_template_manager'] = $manager;

        if (defined('GTEMPLATE_DEBUG') && GTEMPLATE_DEBUG) {
            $mode = $gCore->isExtensionInstalled('TemplateManager') ? 'full' : 'stub';
            gtemplate_track_error("[gTemplate] TemplateManager initialized ({$mode} mode)");
        }

    } catch (\Throwable $e) {
        gtemplate_track_error('[gTemplate] TemplateManager initialization failed: ' . $e->getMessage());
    }
}

/**
 * Get the TemplateManager instance
 *
 * @return \gCore\Modules\Core\Interfaces\Extensions\TemplateManagerInterface|null
 */
function gtemplate_get_template_manager() {
    return $GLOBALS['gtemplate_template_manager'] ?? null;
}

/**
 * Render a template string with variable substitution
 *
 * Extension: Full Tera engine with loops, conditionals, filters
 * Default: Basic {{ variable }} substitution
 *
 * @param string $template Template content
 * @param array $variables Variables for substitution
 * @return string Rendered output
 */
function gtemplate_render_template_string(string $template, array $variables = []): string {
    $manager = gtemplate_get_template_manager();

    if (!$manager) {
        // Ultra-basic fallback
        $output = $template;
        foreach ($variables as $key => $value) {
            if (is_scalar($value)) {
                $output = str_replace('{{ ' . $key . ' }}', htmlspecialchars((string)$value), $output);
                $output = str_replace('{{' . $key . '}}', htmlspecialchars((string)$value), $output);
            }
        }
        return $output;
    }

    return $manager->render($template, $variables);
}

/**
 * Register a template with the system
 *
 * @param string $id Template identifier
 * @param string $content Template content
 * @param array $config Optional configuration
 * @return array Result with 'success' and 'mode' keys
 */
function gtemplate_register_template(string $id, string $content, array $config = []): array {
    $manager = gtemplate_get_template_manager();

    if (!$manager) {
        return [
            'success' => false,
            'error' => 'TemplateManager not available',
            'stub_mode' => true
        ];
    }

    return $manager->registerTemplate($id, $content, $config);
}

/**
 * Get available templates
 *
 * @param string|null $category Optional category filter
 * @return array Available templates
 */
function gtemplate_get_templates(?string $category = null): array {
    $manager = gtemplate_get_template_manager();

    if (!$manager) {
        return [];
    }

    return $manager->getAvailableTemplates($category);
}

/**
 * Generate CSRF token for form security
 *
 * @param string $formId Form identifier
 * @return string CSRF token
 */
function gtemplate_csrf_token(string $formId): string {
    $manager = gtemplate_get_template_manager();

    if (!$manager || !method_exists($manager, 'generateCsrfToken')) {
        // Fallback to WordPress nonce
        return wp_create_nonce('gtemplate_form_' . $formId);
    }

    return $manager->generateCsrfToken($formId);
}

/**
 * Validate CSRF token
 *
 * @param string $formId Form identifier
 * @param string $token Token to validate
 * @return bool Valid or not
 */
function gtemplate_validate_csrf(string $formId, string $token): bool {
    $manager = gtemplate_get_template_manager();

    if (!$manager || !method_exists($manager, 'validateCsrfToken')) {
        // Fallback to WordPress nonce verification
        return wp_verify_nonce($token, 'gtemplate_form_' . $formId) !== false;
    }

    return $manager->validateCsrfToken($formId, $token);
}

/**
 * Escape HTML for safe output
 *
 * @param string $string String to escape
 * @return string Escaped string
 */
function gtemplate_escape_html(string $string): string {
    $manager = gtemplate_get_template_manager();

    if (!$manager) {
        return htmlspecialchars($string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    return $manager->escapeHtml($string);
}

/**
 * Check if extension template features are available
 *
 * @return bool
 */
function gtemplate_template_extension_mode(): bool {
    $manager = gtemplate_get_template_manager();

    if (!$manager) {
        return false;
    }

    $status = $manager->getStatus();
    return ($status['mode'] ?? 'stub') === 'full';
}

/**
 * Get template service status
 *
 * @return array Status information
 */
function gtemplate_template_status(): array {
    $manager = gtemplate_get_template_manager();

    if (!$manager) {
        return [
            'available' => false,
            'stub_mode' => true,
            'message' => 'TemplateManager not initialized'
        ];
    }

    return $manager->getStatus();
}
