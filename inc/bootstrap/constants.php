<?php
declare(strict_types=1);
/**
 * gTemplate Theme Constants
 *
 * Defines all theme constants used throughout the codebase.
 * This file MUST be loaded first, before any other theme files.
 *
 * Child themes can pre-define these constants before the parent loads
 * to override defaults (e.g., GTEMPLATE_FREE_TIER in wp-config.php).
 *
 * @package    gTemplate
 * @subpackage Bootstrap
 * @since      1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Theme version - used for cache busting and compatibility checks
 */
if (!defined('GTEMPLATE_VERSION')) {
    define('GTEMPLATE_VERSION', '1.0.3');
}

/**
 * Debug mode - enables verbose logging
 */
if (!defined('GTEMPLATE_DEBUG')) {
    define('GTEMPLATE_DEBUG', false);
}

/**
 * Free-tier mode - runs without gNode/ValKey infrastructure
 *
 * When enabled:
 * - Uses WordPress transients instead of ValKey
 * - Falls back to PHP rendering instead of gNode templates
 * - No bundle generation or caching
 *
 * Set to true in wp-config.php for free-tier deployments:
 *   define('GTEMPLATE_FREE_TIER', true);
 */
if (!defined('GTEMPLATE_FREE_TIER')) {
    define('GTEMPLATE_FREE_TIER', false);
}

/**
 * Parent theme directory path (always points to gTemplate)
 */
if (!defined('GTEMPLATE_DIR')) {
    define('GTEMPLATE_DIR', get_template_directory());
}

/**
 * Parent theme directory URI (always points to gTemplate)
 */
if (!defined('GTEMPLATE_URI')) {
    define('GTEMPLATE_URI', get_template_directory_uri());
}

/**
 * Parent inc directory path (most includes live here)
 */
if (!defined('GTEMPLATE_INC_DIR')) {
    define('GTEMPLATE_INC_DIR', GTEMPLATE_DIR . '/inc');
}

/**
 * Face count — child themes override via filter 'gtemplate_face_count'
 * This constant is set once at load time for performance.
 */
if (!defined('GTEMPLATE_FACE_COUNT')) {
    // Cannot use apply_filters at constants phase (too early).
    // Will be resolved later via gtemplate_get_face_count().
    define('GTEMPLATE_FACE_COUNT', 6);
}
