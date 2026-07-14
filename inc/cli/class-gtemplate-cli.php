<?php
declare(strict_types=1);
/**
 * WP-CLI commands for gTemplate.
 *
 * Usage:
 *   wp <prefix> register                 # Smart registration (check first)
 *   wp <prefix> register --force         # Force re-registration
 *   wp <prefix> status                   # Show registration status
 *   wp <prefix> config                   # Show registration config
 *   wp <prefix> sync_config              # Re-publish config to ValKey
 *   wp <prefix> runtime_get|set|list     # Runtime config inspection
 *   wp <prefix> aio_*                    # AI optimization commands
 *   wp <prefix> viewkey|environment      # Environment-gate management
 *
 * The command name is derived dynamically from gtemplate_get_theme_prefix().
 *
 * Commit 1.10.d: the previous 1,078 LOC monolith of 14
 * command methods is now split across 4 trait files under
 * `inc/cli/commands/`, composed back into `GtemplateCLI` via `use`.
 * UX preserved verbatim — operator muscle memory (`wp gtemplate
 * register`, etc.) untouched.
 *
 * Audit's preferred shape was "per-command classes". Traits achieve
 * the maintainability goal (single-concern files <500 LOC each) while
 * keeping the existing single `WP_CLI::add_command()` registration
 * line working as-is. Splitting into separate classes would have
 * required either:
 *   - sub-namespaces (`wp gtemplate config get`, breaking existing
 *     `wp gtemplate runtime_get`), or
 *   - separate top-level commands (`wp gtemplate-config`, breaking
 *     the unified `gtemplate` namespace), or
 *   - a delegating facade with 14 thin methods that just call
 *     out to per-class services (more LOC, more indirection).
 * The trait approach is the simplest path to the same maintainability
 * outcome.
 *
 * @package gTemplate
 * @version 1.0.0
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

require_once __DIR__ . '/commands/registration.php';
require_once __DIR__ . '/commands/config.php';
require_once __DIR__ . '/commands/aio.php';
require_once __DIR__ . '/commands/environment.php';

/**
 * gTemplate site registration and management commands.
 *
 * Composes 4 trait files; each holds a logical command group. Add
 * a new trait + `use` line below to grow the surface — do NOT
 * inline new methods directly into this class body.
 */
class GtemplateCLI
{
    use GtemplateCLI_Registration;
    use GtemplateCLI_Config;
    use GtemplateCLI_AIO;
    use GtemplateCLI_Environment;
}

// Register CLI command using the theme prefix dynamically
$gtemplate_cli_command = function_exists('gtemplate_get_theme_prefix')
    ? gtemplate_get_theme_prefix()
    : 'gtemplate';
WP_CLI::add_command($gtemplate_cli_command, 'GtemplateCLI');
