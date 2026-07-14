<?php
declare(strict_types=1);
/**
 * Integration Loader
 *
 * Loads all integration modules in the correct order.
 * Integrations provide bridges between gTemplate and various services.
 *
 * @package    gTemplate
 * @subpackage Integrations
 * @since      2.0.0
 *
 * DIRECTORY STRUCTURE:
 * ====================
 * integrations/
 * ├── gnode/          - gNode daemon service proxies (ValKey/Lua)
 * │   ├── config.php      - Runtime configuration
 * │   ├── topology.php    - Service topology
 * │   ├── template.php    - Template rendering
 * │   ├── metrics.php     - Performance metrics
 * │   ├── resource.php    - Resource hints
 * │   └── keybased.php    - Key-based client integration
 * │
 * ├── managers/     - gCore extension service wrappers
 * │   ├── state.php       - StateManager
 * │   ├── analytics.php   - AnalyticsManager
 * │   ├── comms.php       - CommunicationsManager
 * │   └── inference.php   - InferenceManager
 * │
 * ├── features/     - Optional feature integrations
 * │   ├── cookie.php      - Cookie consent (GDPR)
 * │   ├── manifest.php    - PWA manifest
 * │   ├── optimization.php - Performance optimizations
 * │   ├── seo.php         - SEO features
 * │   ├── llm-seo.php     - AI-powered SEO
 * │   └── version.php     - Version management
 * │
 * └── content/      - Content transformation
 *     ├── format.php      - Content formatting
 *     ├── shortcode.php   - Shortcode handling
 *     └── translate.php   - Translation
 */

if (!defined('ABSPATH')) {
    exit;
}

$integrations_dir = __DIR__;

//-----------------------------------------------------------------------------
// gNode Service Integrations (ValKey/Lua function wrappers)
//-----------------------------------------------------------------------------

require_once $integrations_dir . '/gnode/config.php';
require_once $integrations_dir . '/gnode/topology.php';
require_once $integrations_dir . '/gnode/template.php';
require_once $integrations_dir . '/gnode/metrics.php';
require_once $integrations_dir . '/gnode/resource.php';
require_once $integrations_dir . '/gnode/keybased.php';

//-----------------------------------------------------------------------------
// gCore Manager Integrations (extensions with fallback stubs)
//-----------------------------------------------------------------------------

require_once $integrations_dir . '/managers/state.php';
require_once $integrations_dir . '/managers/analytics.php';
require_once $integrations_dir . '/managers/comms.php';
require_once $integrations_dir . '/managers/inference.php';

//-----------------------------------------------------------------------------
// Feature Integrations (toggleable features)
//-----------------------------------------------------------------------------

require_once $integrations_dir . '/features/optimization.php';
require_once $integrations_dir . '/features/version.php';
require_once $integrations_dir . '/features/seo.php';
require_once $integrations_dir . '/features/llm-seo.php';
require_once $integrations_dir . '/features/manifest.php';
require_once $integrations_dir . '/features/cookie.php';
require_once $integrations_dir . '/features/analytics-beacon.php';

//-----------------------------------------------------------------------------
// Content Integrations (content transformation)
//-----------------------------------------------------------------------------

require_once $integrations_dir . '/content/format.php';
require_once $integrations_dir . '/content/shortcode.php';
require_once $integrations_dir . '/content/translate.php';
