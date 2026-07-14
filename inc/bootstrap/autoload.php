<?php
declare(strict_types=1);
/**
 * gTemplate Autoload Orchestrator
 *
 * Loads all theme files in the correct dependency order.
 * 9-phase bootstrap pattern shared by all Geodineum themes.
 *
 * @package    gTemplate
 * @subpackage Bootstrap
 * @since      1.0.0
 *
 * LOAD ORDER (9 Phases):
 * ======================
 * Phase 1: Constants (GTEMPLATE_VERSION, GTEMPLATE_DIR, etc.)
 * Phase 2: Composer autoloader
 * Phase 3: Configuration (registration, config loader)
 * Phase 4: Environment (gate, performance)
 * Phase 5: Helpers (init-helpers, template-helpers)
 * Phase 6: Bootstrap (theme-setup, gcore-init)
 * Phase 7: Rendering (face-renderer, content-sources, helpers, config-export)
 * Phase 8: Assets (enqueue)
 * Phase 9: Integrations, REST, CLI, Customizer
 */

if (!defined('ABSPATH')) {
    exit;
}

$theme_dir = get_template_directory();
$inc_dir = $theme_dir . '/inc';

//-----------------------------------------------------------------------------
// Phase 1: Constants
//-----------------------------------------------------------------------------

require_once $inc_dir . '/bootstrap/constants.php';

//-----------------------------------------------------------------------------
// Phase 2: Composer Autoloader
//-----------------------------------------------------------------------------

$autoload = $theme_dir . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

//-----------------------------------------------------------------------------
// Phase 3: Configuration
//-----------------------------------------------------------------------------

require_once $inc_dir . '/registration.php';
require_once $inc_dir . '/gNodeConfigLoader.php';

//-----------------------------------------------------------------------------
// Phase 4: Environment
//-----------------------------------------------------------------------------

require_once $inc_dir . '/environment-gate.php';
require_once $inc_dir . '/performance.php';

//-----------------------------------------------------------------------------
// Phase 5: Helpers
//-----------------------------------------------------------------------------

require_once $inc_dir . '/helpers/init-helpers.php';
require_once $inc_dir . '/helpers/template-helpers.php';

//-----------------------------------------------------------------------------
// Phase 6: Bootstrap (theme setup, gCore initialization)
//-----------------------------------------------------------------------------

require_once $inc_dir . '/bootstrap/theme-setup.php';
require_once $inc_dir . '/bootstrap/gcore-init.php';

//-----------------------------------------------------------------------------
// Phase 7: Rendering (face renderer, content sources, helpers)
//-----------------------------------------------------------------------------

require_once $inc_dir . '/rendering/helpers.php';
require_once $inc_dir . '/rendering/content-sources/index.php';
require_once $inc_dir . '/rendering/face-renderer.php';
require_once $inc_dir . '/rendering/config-export.php';
require_once $inc_dir . '/rendering/nav-renderer.php';

//-----------------------------------------------------------------------------
// Phase 8: Assets
//-----------------------------------------------------------------------------

require_once $inc_dir . '/setup/enqueue.php';

//-----------------------------------------------------------------------------
// Phase 9: Integrations, REST, CLI, Customizer
//-----------------------------------------------------------------------------

// REST API (modular resources)
require_once $inc_dir . '/rest/index.php';

// Integrations (gNode, managers, features, content)
require_once $inc_dir . '/integrations/index.php';

// gNode content sync — split into 4 single-concern files in Commit 1.10.c
// so the security-sensitive full-page-cache surface is no
// longer mixed with caching housekeeping. Page-sync handles WP→Tera
// template conversion + per-page registration; cell-mapping handles
// face-cell ↔ WP page resolution + sync; bundle-cache handles ValKey
// pre-rendered bundles + post-bundle generation; full-page-cache holds
// the anonymous-GET HTML cache (hardened in Commit 1.11).
require_once $inc_dir . '/sync/page-sync.php';
require_once $inc_dir . '/sync/cell-mapping.php';
require_once $inc_dir . '/sync/bundle-cache.php';
require_once $inc_dir . '/sync/full-page-cache.php';

// Email-to-Post integration
require_once $inc_dir . '/email-to-post.php';

// WP-CLI commands
require_once $inc_dir . '/cli/class-gtemplate-cli.php';

// Admin-only files
if (is_admin()) {
    $bundle_metabox = $inc_dir . '/admin/bundle-metabox.php';
    if (file_exists($bundle_metabox)) {
        require_once $bundle_metabox;
    }
}

// Security
require_once $inc_dir . '/security-hardening.php';

// Customizer (modular sections)
require_once $inc_dir . '/sanitation-funcs.php';
require_once $inc_dir . '/google-funcs.php';
require_once $inc_dir . '/customizer/index.php';
require_once $inc_dir . '/customizer-css.php';
