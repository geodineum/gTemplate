<?php
declare(strict_types=1);
/**
 * gTemplate WP-CLI registration commands (register, status).
 *
 * Extracted from inc/cli/class-gtemplate-cli.php in Commit 1.10.d
 * to break the 1,078 LOC monolith. Composed via PHP trait
 * into the existing GtemplateCLI class so the operator-facing
 * `wp <prefix> register` / `wp <prefix> status` muscle memory is
 * preserved verbatim.
 *
 * @package gTemplate
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

trait GtemplateCLI_Registration
{
    /**
     * Register site with gNode topology
     *
     * ## OPTIONS
     *
     * [--force]
     * : Force re-registration even if already registered
     *
     * ## EXAMPLES
     *
     *     # Smart registration (only if changed)
     *     wp <prefix> register
     *
     *     # Force re-registration
     *     wp <prefix> register --force
     *
     * @when after_wp_load
     */
    public function register($args, $assoc_args)
    {
        // Commit 1.10.b: the previous body called
        // \gTemplate\smart_register_site() and
        // \gTemplate\get_registration_status() — both DELETED in
        // registration.php's 2.0.1 cleanup. The CLI's first invocation
        // was guaranteed-fatal. Replaced with the canonical flow per
        // the breadcrumb at inc/registration.php:354-366: route through
        // gCore TopologyManager (smartRegister + getRegistrationStatus).
        // Fail-fast if gCore or TopologyManager is unavailable rather
        // than silently returning misleading status.
        $force = isset($assoc_args['force']);

        WP_CLI::line('');
        WP_CLI::line('=== gTemplate Site Registration ===');
        WP_CLI::line('');

        $config = \gTemplate\load_registration_config();
        if (!$config) {
            WP_CLI::error('Failed to load registration.yaml');
            return;
        }

        WP_CLI::line('Config loaded: registration.yaml');
        WP_CLI::line('Site ID: ' . ($config['site_id'] ?? 'unknown'));
        WP_CLI::line('');

        $topology = self::getTopologyManager();
        // self::getTopologyManager() WP_CLI::error()s on miss, so a
        // returned object means gCore + TopologyManager are live.

        $status = $topology->getRegistrationStatus();

        if (!empty($status['registered']) && !$force) {
            WP_CLI::line('Status: Already registered');
            WP_CLI::line('  Hash: ' . substr((string) ($status['hash'] ?? ''), 0, 16) . '...');
            WP_CLI::line('  Registered at: ' . ($status['registered_at'] ?? 'unknown'));
            WP_CLI::line('');
            WP_CLI::line('Checking for config changes...');
        }

        WP_CLI::line($force ? 'Force registering...' : 'Registering...');

        $success = method_exists($topology, 'smartRegister')
            ? $topology->smartRegister($force)
            : $topology->forceRegister();

        WP_CLI::line('');

        if ($success) {
            $new_status = $topology->getRegistrationStatus();
            WP_CLI::success('Registration completed successfully!');
            WP_CLI::line('  Hash: ' . substr((string) ($new_status['hash'] ?? ''), 0, 16) . '...');
            WP_CLI::line('  Method: ' . ($new_status['method'] ?? 'unknown'));
            WP_CLI::line('  Registered at: ' . ($new_status['registered_at'] ?? 'unknown'));
        } else {
            WP_CLI::error('Registration failed! Check error log for details.');
        }

        WP_CLI::line('');
    }

    /**
     * Resolve gCore TopologyManager or fail-fast.
     *
     * Commit 1.10.b helper. Centralizes the gCore + TopologyManager
     * lookup so register/status share one fail-fast site.
     *
     * @return object TopologyManager service (typed loosely because
     *                gCore's class-shape lives in another package)
     */
    private static function getTopologyManager()
    {
        global $gCore;

        if (!$gCore) {
            WP_CLI::error('gCore not initialized. Cannot reach TopologyManager.');
        }

        if (!method_exists($gCore, 'getService')) {
            WP_CLI::error('gCore present but does not expose getService(). Upgrade gCore.');
        }

        try {
            $topology = $gCore->getService('TopologyManager');
        } catch (\Throwable $e) {
            WP_CLI::error('gCore->getService(TopologyManager) threw: ' . $e->getMessage());
        }

        if (!$topology || !method_exists($topology, 'getRegistrationStatus')) {
            WP_CLI::error('TopologyManager service unavailable or missing getRegistrationStatus().');
        }

        return $topology;
    }

    /**
     * Show registration status
     *
     * ## EXAMPLES
     *
     *     wp <prefix> status
     *
     * @when after_wp_load
     */
    public function status($args, $assoc_args)
    {
        // Commit 1.10.b: same dead-fn replacement as
        // register() — route via TopologyManager, fail-fast on miss.
        $prefix = function_exists('gtemplate_get_theme_prefix') ? gtemplate_get_theme_prefix() : 'gtemplate';

        WP_CLI::line('');
        WP_CLI::line('=== gTemplate Registration Status ===');
        WP_CLI::line('');

        $topology = self::getTopologyManager();
        $status = $topology->getRegistrationStatus();

        if (!empty($status['registered'])) {
            WP_CLI::line('Status: Registered');
            WP_CLI::line('Hash: ' . ($status['hash'] ?? 'unknown'));
            WP_CLI::line('Registered at: ' . ($status['registered_at'] ?? 'unknown'));
            WP_CLI::line('Method: ' . ($status['method'] ?? 'unknown'));
        } else {
            WP_CLI::line('Status: Not registered');
            WP_CLI::line('');
            WP_CLI::line('Run: wp ' . $prefix . ' register');
        }

        WP_CLI::line('');
    }

}
