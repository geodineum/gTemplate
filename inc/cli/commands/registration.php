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
        WP_CLI::warning('Registering is not the theme\'s job. Run on the host:  sudo geodineum register ' . ABSPATH);
        WP_CLI::line('This site\'s registration as the constellation sees it:  sudo geodineum status <site_id>');
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
