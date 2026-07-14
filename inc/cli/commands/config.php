<?php
declare(strict_types=1);
/**
 * gTemplate WP-CLI config commands (config, sync_config, runtime_*).
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

trait GtemplateCLI_Config
{
    /**
     * Show registration configuration
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format (table, json, yaml)
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - yaml
     * ---
     *
     * ## EXAMPLES
     *
     *     wp <prefix> config
     *     wp <prefix> config --format=json
     *
     * @when after_wp_load
     */
    public function config($args, $assoc_args)
    {
        $format = $assoc_args['format'] ?? 'table';

        $config = \gTemplate\load_registration_config();
        if (!$config) {
            WP_CLI::error('Failed to load registration.yaml');
            return;
        }

        if ($format === 'json') {
            WP_CLI::line(json_encode($config, JSON_PRETTY_PRINT));
            return;
        }

        if ($format === 'yaml') {
            WP_CLI::line(yaml_emit($config));
            return;
        }

        // Table format
        WP_CLI::line('');
        WP_CLI::line('=== gTemplate Registration Config ===');
        WP_CLI::line('');

        WP_CLI::line('Basic Information:');
        WP_CLI::line('  Version: ' . ($config['version'] ?? 'unknown'));
        WP_CLI::line('  Site ID: ' . ($config['site_id'] ?? 'unknown'));
        WP_CLI::line('  Type: ' . ($config['service']['type'] ?? 'unknown'));
        WP_CLI::line('  Environment: ' . ($config['metadata']['environment'] ?? 'unknown'));
        WP_CLI::line('  Domain: ' . ($config['metadata']['domain'] ?? 'unknown'));
        WP_CLI::line('');

        WP_CLI::line('Capabilities:');
        foreach ($config['capabilities'] ?? [] as $cap => $value) {
            WP_CLI::line(sprintf('  %-25s %.2f', $cap, $value));
        }
        WP_CLI::line('');

        WP_CLI::line('ValKey:');
        WP_CLI::line('  User: ' . ($config['valkey']['user'] ?? 'unknown'));
        WP_CLI::line('  Password file: ' . ($config['valkey']['password_file'] ?? 'unknown'));
        WP_CLI::line('');

        WP_CLI::line('Managers:');
        foreach ($config['managers'] ?? [] as $manager => $file) {
            WP_CLI::line(sprintf('  %-15s %s', $manager, $file));
        }
        WP_CLI::line('');
    }

    /**
     * Sync configuration to ValKey
     *
     * ## EXAMPLES
     *
     *     wp <prefix> sync-config
     *
     * @when after_wp_load
     */
    public function sync_config($args, $assoc_args)
    {
        WP_CLI::line('');
        WP_CLI::line('=== Syncing Config to ValKey ===');
        WP_CLI::line('');

        $config = \gTemplate\load_registration_config();
        if (!$config) {
            WP_CLI::error('Failed to load registration.yaml');
            return;
        }

        WP_CLI::line('Syncing configuration...');

        $success = \gTemplate\sync_config_to_valkey($config);

        if ($success) {
            WP_CLI::success('Config synced successfully!');
            WP_CLI::line('  Keys: {' . $config['site_id'] . '}:config:*');
        } else {
            WP_CLI::error('Config sync failed! Check error log.');
        }

        WP_CLI::line('');
    }

    /**
     * Get a runtime config value from ValKey
     *
     * ## OPTIONS
     *
     * <category>
     * : Config category (ratelimit, cache, security, features)
     *
     * <key>
     * : Config key
     *
     * [--default=<value>]
     * : Default value if not found
     *
     * ## EXAMPLES
     *
     *     wp <prefix> runtime-get ratelimit api_limit
     *
     * @when after_wp_load
     * @subcommand runtime-get
     */
    public function runtime_get($args, $assoc_args) {
        $prefix = function_exists('gtemplate_get_theme_prefix') ? gtemplate_get_theme_prefix() : 'gtemplate';

        if (count($args) < 2) {
            WP_CLI::error('Usage: wp ' . $prefix . ' runtime-get <category> <key>');
            return;
        }
        if (!function_exists('gtemplate_config_get')) {
            WP_CLI::error('Config integration not loaded');
            return;
        }
        $value = gtemplate_config_get($args[0], $args[1], $assoc_args['default'] ?? null);
        WP_CLI::line($value ?? '(null)');
    }

    /**
     * Set a runtime config value in ValKey
     *
     * ## OPTIONS
     *
     * <category>
     * : Config category
     *
     * <key>
     * : Config key
     *
     * <value>
     * : Value to set
     *
     * ## EXAMPLES
     *
     *     wp <prefix> runtime-set ratelimit api_limit 200
     *
     * @when after_wp_load
     * @subcommand runtime-set
     */
    public function runtime_set($args, $assoc_args) {
        $prefix = function_exists('gtemplate_get_theme_prefix') ? gtemplate_get_theme_prefix() : 'gtemplate';

        if (count($args) < 3) {
            WP_CLI::error('Usage: wp ' . $prefix . ' runtime-set <category> <key> <value>');
            return;
        }
        if (!function_exists('gtemplate_config_set')) {
            WP_CLI::error('Config integration not loaded');
            return;
        }
        $success = gtemplate_config_set($args[0], $args[1], $args[2]);
        if ($success) {
            WP_CLI::success("Set {$args[0]}.{$args[1]} = {$args[2]}");
        } else {
            WP_CLI::error("Failed to set {$args[0]}.{$args[1]}");
        }
    }

    /**
     * List runtime config values
     *
     * ## OPTIONS
     *
     * [<category>]
     * : Optional category to list
     *
     * [--format=<format>]
     * : Output format (table, json)
     *
     * ## EXAMPLES
     *
     *     wp <prefix> runtime-list
     *     wp <prefix> runtime-list ratelimit
     *
     * @when after_wp_load
     * @subcommand runtime-list
     */
    public function runtime_list($args, $assoc_args) {
        if (!function_exists('gtemplate_config_get_all')) {
            WP_CLI::error('Config integration not loaded');
            return;
        }
        $format = $assoc_args['format'] ?? 'table';
        $category = $args[0] ?? null;
        $categories = $category ? [$category] : ['ratelimit', 'cache', 'security', 'features'];
        $all = [];
        foreach ($categories as $cat) {
            $all[$cat] = gtemplate_config_get_all($cat);
        }
        if ($format === 'json') {
            WP_CLI::line(json_encode($all, JSON_PRETTY_PRINT));
            return;
        }
        WP_CLI::line('');
        foreach ($all as $cat => $values) {
            WP_CLI::line("[{$cat}]");
            foreach ($values as $key => $value) {
                WP_CLI::line(sprintf('  %-25s %s', $key, $value));
            }
            WP_CLI::line('');
        }
    }
}
