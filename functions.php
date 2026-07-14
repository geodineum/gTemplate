<?php
/**
 * gTemplate Parent Theme Functions
 *
 * Minimal entry point — loads the bootstrap autoloader which handles
 * all file loading in the correct dependency order.
 *
 * Child themes override behavior via filters defined in the
 * Filter Hook Registry.
 *
 * @package    gTemplate
 * @since      1.0.0
 *
 * Architecture: functions.php → autoload.php → 9-phase bootstrap
 * See inc/bootstrap/autoload.php for the full load order.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Load the bootstrap autoloader (handles all 9 phases)
require_once get_template_directory() . '/inc/bootstrap/autoload.php';
