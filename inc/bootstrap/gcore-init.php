<?php
/**
 * gCore Framework Initialization
 *
 * Initializes gCore, gNode-Client, and all manager integrations.
 * Handles free-tier mode fallback when gNode/ValKey is unavailable.
 *
 * @package    gTemplate
 * @subpackage Bootstrap
 * @since      1.0.0
 *
 * @dependencies constants.php, registration.php, helpers/init-helpers.php
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Helper function for config loading (uses gNodeConfigLoader)
 */
function gtemplate_get_registration_config(): array {
    return \gTemplate\gNodeConfigLoader::get();
}

/**
 * gCore initialization function
 *
 * Architecture: gTemplate → gCore → gNode-Client → ValKey
 */
function gtemplate_initialize_gcore() {
    // Skip entirely on admin - gCore initialization loads all managers which is too heavy
    if (is_admin() ||
        (defined('WP_ADMIN') && WP_ADMIN) ||
        (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/wp-admin') !== false) ||
        (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/wp-login.php') !== false)) {
        return;
    }

    global $gCore;

    // Suppress ALL output and errors during initialization
    $old_error_reporting = error_reporting(0);
    ob_start();

    try {
        $gCore = \gCore\Modules\Core\gCore::getInstance();

        // FREE-TIER MODE: Initialize without gNode/ValKey
        if (GTEMPLATE_FREE_TIER) {
            error_log('gTemplate: Initializing in FREE-TIER mode (no gNode/ValKey)');

            $gCore->initialize([
                'site_id' => gtemplate_get_site_id(),
                'node_id' => 'web-' . gethostname(),
                'use_gnode' => false,
                'gnode_client' => null,
                'storage' => [
                    'adapter' => 'transient',
                    'prefix' => 'gtemplate_'
                ]
            ]);

            $GLOBALS['gtemplate_gnode_client'] = null;
            $GLOBALS['gtemplate_gnode_keybased_client'] = null;
            $GLOBALS['gtemplate_gnode_storage'] = null;
            $GLOBALS['gtemplate_free_tier_mode'] = true;

            error_log('gTemplate: Free-tier initialization complete - using PHP fallback rendering');

            ob_get_clean();
            error_reporting($old_error_reporting);
            return;
        }

        // Initialize gCore - it handles ALL gNode-Client setup internally
        $gCore->initialize(gtemplate_get_gcore_config());

        // FRONTEND ONLY: Get gNode client and set up theme-specific services
        $site_id = gtemplate_get_site_id();
        $environment = gtemplate_detect_environment();

        try {
            $gNodeClient = $gCore->getService('gnode_client');

            if ($gNodeClient) {
                $GLOBALS['gtemplate_gnode_client'] = $gNodeClient;
                $GLOBALS['gtemplate_gnode_keybased_client'] = $gNodeClient;
                $GLOBALS['gtemplate_gnode_storage'] = $gNodeClient;

                if (GTEMPLATE_DEBUG) error_log("gTemplate: gNode-Client obtained from gCore (site: {$site_id}, env: {$environment})");
            } else {
                throw new \RuntimeException('gCore did not provide gnode_client service');
            }

        } catch (\Throwable $e) {
            error_log('gTemplate: gNode-Client not available from gCore: ' . $e->getMessage());
            $GLOBALS['gtemplate_free_tier_mode'] = true;
            $GLOBALS['gtemplate_gnode_client'] = null;
            $GLOBALS['gtemplate_gnode_keybased_client'] = null;
            $GLOBALS['gtemplate_gnode_storage'] = null;
        }

        // Initialize managers
        gtemplate_initialize_topology_manager($GLOBALS['gtemplate_gnode_client']);
        gtemplate_init_seo_manager();
        gtemplate_init_format_manager();
        gtemplate_init_optimization_manager();

        if ($GLOBALS['gtemplate_gnode_client']) {
            gtemplate_inject_gnode_into_security_manager($GLOBALS['gtemplate_gnode_client']);
        }

        gtemplate_init_manifest_manager($gCore);
        gtemplate_init_cookie_manager($gCore);

        // Premium add-on managers (graceful stub fallback)
        gtemplate_init_metrics_manager($GLOBALS['gtemplate_gnode_client']);
        gtemplate_init_state_manager($GLOBALS['gtemplate_gnode_client']);
        gtemplate_init_analytics_manager($GLOBALS['gtemplate_gnode_client']);
        gtemplate_init_inference_manager($GLOBALS['gtemplate_gnode_client']);
        gtemplate_init_template_manager($GLOBALS['gtemplate_gnode_client']);
        gtemplate_init_comms_manager($GLOBALS['gtemplate_gnode_client']);

    } catch (\Throwable $e) {
        error_log('gTemplate Core Initialization Error: ' . $e->getMessage());
    }

    $stray_output = ob_get_clean();
    error_reporting($old_error_reporting);

    if (!empty($stray_output)) {
        error_log('[gTemplate] Captured stray output during init: ' . substr($stray_output, 0, 500));
    }
}

/**
 * Initialize TopologyManager with gNode client
 */
function gtemplate_initialize_topology_manager($gNodeClient) {
    global $gCore;

    if (!$gCore) {
        error_log('[gTemplate] TopologyManager: gCore not available');
        return;
    }

    try {
        $topology = $gCore->getService('TopologyManager');
        $site_id = gtemplate_get_site_id();

        $topology->initialize([
            'site_id' => $site_id,
            'node_id' => 'web-' . gethostname(),
            'use_gnode' => true,
            'gnode_client' => $gNodeClient,
            'auto_register_service' => false,
            'cache_enabled' => true,
            'default_dimensions' => 9,
            'debug' => defined('WP_DEBUG') && WP_DEBUG
        ]);

        if (GTEMPLATE_DEBUG) error_log("[gTemplate] TopologyManager initialized (site_id: {$site_id})");

    } catch (\Throwable $e) {
        error_log('[gTemplate] TopologyManager initialization failed: ' . $e->getMessage());
    }
}

// Initialize gCore framework (priority 11 = after theme-setup at 10)
add_action('after_setup_theme', 'gtemplate_initialize_gcore', 11);
