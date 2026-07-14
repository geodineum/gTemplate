<?php
declare(strict_types=1);
/**
 * gTemplate WP-CLI environment commands (viewkey, environment).
 *
 * Extracted from inc/cli/class-gtemplate-cli.php in Commit 1.10.d
 *. Composed via PHP trait — UX preserved verbatim.
 *
 * @package gTemplate
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

trait GtemplateCLI_Environment
{
    /**
     * Show or generate viewkey for environment gate
     *
     * ## OPTIONS
     *
     * [--regenerate]
     * : Generate a new viewkey (overwrites existing)
     *
     * [--copy]
     * : Output just the viewkey (for scripting/copying)
     *
     * ## EXAMPLES
     *
     *     wp <prefix> viewkey
     *     wp <prefix> viewkey --copy
     *     wp <prefix> viewkey --regenerate
     *
     * @when after_wp_load
     */
    public function viewkey($args, $assoc_args)
    {
        $config = \gTemplate\load_registration_config();
        if (!$config) {
            WP_CLI::error('Failed to load site configuration');
            return;
        }

        $environment = $config['metadata']['environment'] ?? 'production';
        $viewkey = $config['security']['viewkey'] ?? '';
        $site_id = $config['site_id'] ?? 'unknown';
        $copyOnly = isset($assoc_args['copy']);

        // Regenerate if requested
        if (isset($assoc_args['regenerate'])) {
            $viewkey = bin2hex(random_bytes(16)); // 32 hex chars

            // Find config file location
            $config_file = ABSPATH . 'wp-config-geodineum.yaml';
            if (!file_exists($config_file)) {
                $config_file = get_template_directory() . '/registration.local.yaml';
            }

            $file_updated = false;
            if (file_exists($config_file) && is_writable($config_file)) {
                $content = file_get_contents($config_file);

                // Update or add viewkey
                if (preg_match('/^(\s*viewkey:\s*)["\']?[^"\'\n]*["\']?\s*$/m', $content)) {
                    $content = preg_replace(
                        '/^(\s*viewkey:\s*)["\']?[^"\'\n]*["\']?\s*$/m',
                        '$1"' . $viewkey . '"',
                        $content
                    );
                } else {
                    // Add to security section
                    if (preg_match('/^security:\s*$/m', $content)) {
                        $content = preg_replace(
                            '/^(security:\s*)$/m',
                            "$1\n  viewkey: \"$viewkey\"",
                            $content
                        );
                    }
                }

                file_put_contents($config_file, $content);
                $file_updated = true;
                WP_CLI::success('Viewkey regenerated in config file!');
            } else {
                WP_CLI::warning("Cannot write to config file. Add this manually:");
                WP_CLI::line("  viewkey: \"$viewkey\"");
            }

            // Always sync to ValKey (updates the cached config)
            $config['security'] = $config['security'] ?? [];
            $config['security']['viewkey'] = $viewkey;
            if (\gTemplate\sync_config_to_valkey($config)) {
                WP_CLI::success('Viewkey synced to ValKey cache!');
            } else {
                WP_CLI::warning('Failed to sync viewkey to ValKey');
            }
        }

        // Output
        if ($copyOnly) {
            if (empty($viewkey)) {
                WP_CLI::error('No viewkey configured');
            } else {
                WP_CLI::line($viewkey);
            }
            return;
        }

        WP_CLI::line('');
        WP_CLI::line('=== gTemplate Environment Gate ===');
        WP_CLI::line('');
        WP_CLI::line('Site ID:     ' . $site_id);
        WP_CLI::line('Environment: ' . $environment);
        WP_CLI::line('');

        if ($environment === 'production') {
            WP_CLI::line('Status: Environment gate is INACTIVE (production)');
            WP_CLI::line('');
            WP_CLI::line('The environment gate only activates for non-production environments.');
            WP_CLI::line('Change metadata.environment in your config to enable it.');
        } else {
            $prefix = function_exists('gtemplate_get_theme_prefix') ? gtemplate_get_theme_prefix() : 'gtemplate';

            WP_CLI::line('Status: Environment gate is ACTIVE');
            WP_CLI::line('');

            if (empty($viewkey)) {
                WP_CLI::warning('No viewkey configured!');
                WP_CLI::line('');
                WP_CLI::line('Anonymous visitors will see the gate screen but cannot enter a viewkey.');
                WP_CLI::line('Generate one with: wp ' . $prefix . ' viewkey --regenerate');
            } else {
                WP_CLI::line('Viewkey: ' . $viewkey);
                WP_CLI::line('');
                WP_CLI::line('Share this viewkey with clients to preview the site without WordPress login.');
                WP_CLI::line('');
                WP_CLI::line('Preview URL: ' . home_url('/?viewkey=' . $viewkey));
            }
        }

        WP_CLI::line('');
    }

    /**
     * Show current DTAP environment and detection logic
     *
     * ## EXAMPLES
     *
     *     wp <prefix> environment
     *
     * @when after_wp_load
     */
    public function environment($args, $assoc_args)
    {
        WP_CLI::line('');
        WP_CLI::line('=== gTemplate DTAP Environment ===');
        WP_CLI::line('');

        // Load config
        $config = \gTemplate\load_registration_config();
        $config_env = $config['metadata']['environment'] ?? null;

        // WordPress environment
        $wp_env = defined('WP_ENVIRONMENT_TYPE') ? WP_ENVIRONMENT_TYPE : 'not defined';

        // Auto-detect from domain
        $domain = parse_url(home_url(), PHP_URL_HOST);
        $detected_env = 'production'; // default

        if (strpos($domain, 'test') !== false || strpos($domain, 'dev') !== false || strpos($domain, 'local') !== false) {
            $detected_env = 'testing';
        } elseif (strpos($domain, 'staging') !== false) {
            $detected_env = 'staging';
        } elseif (strpos($domain, 'accept') !== false || strpos($domain, 'uat') !== false) {
            $detected_env = 'acceptance';
        }

        // Determine effective environment
        $effective_env = $config_env ?? ($wp_env !== 'not defined' ? $wp_env : $detected_env);

        // Map WP environment types to DTAP
        if ($effective_env === 'development' || $effective_env === 'local') {
            $effective_env = 'testing';
        }

        WP_CLI::line('Domain:              ' . $domain);
        WP_CLI::line('');
        WP_CLI::line('Detection Sources:');
        WP_CLI::line('  Config file:       ' . ($config_env ?? '(not set)'));
        WP_CLI::line('  WP_ENVIRONMENT:    ' . $wp_env);
        WP_CLI::line('  Domain detection:  ' . $detected_env);
        WP_CLI::line('');
        WP_CLI::line('Effective Environment: ' . strtoupper($effective_env));
        WP_CLI::line('');

        // Show DTAP info
        $dtap_info = [
            'testing' => 'Development/feature testing - Environment gate ACTIVE',
            'staging' => 'Pre-release validation - Environment gate ACTIVE',
            'acceptance' => 'UAT/client approval - Environment gate ACTIVE',
            'production' => 'Live production site - Environment gate INACTIVE',
        ];

        WP_CLI::line('DTAP Environments:');
        foreach ($dtap_info as $env => $desc) {
            $marker = ($env === $effective_env) ? ' <- CURRENT' : '';
            WP_CLI::line(sprintf('  %-12s %s%s', strtoupper($env), $desc, $marker));
        }

        WP_CLI::line('');

        // gNode stream info
        WP_CLI::line('gNode Stream Namespace: {' . $effective_env . '}:gnode:unified:default');
        WP_CLI::line('');
    }
}
