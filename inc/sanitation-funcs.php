<?php
declare(strict_types=1);
/**
 * gCore Sanitization Functions
 *
 * Provides core sanitization functionality for the gCore theme, ensuring
 * data security and validation across theme customizer options and user inputs.
 *
 * @package gTemplate
 * @subpackage  Security
 * @since       1.0.0
 *
 * =========================
 * FUNCTIONALITY OVERVIEW
 * =========================
 * Core sanitization functions for theme data validation, particularly focused on:
 * - Hex color validation for theme customizer
 * - CSS value sanitization for size and unit validation
 * - General option sanitization for customizer choices
 *
 * =========================
 * KEY FUNCTIONS
 * =========================
 * gtemplate_sanitize_hex_color()
 * - Validates and sanitizes hexadecimal color values
 * - Input: String (potential hex color)
 * - Output: Valid hex color or null
 *
 * gtemplate_sanitize_css_value()
 * - Validates CSS measurements including units
 * - Input: String (CSS value with unit)
 * - Output: Valid CSS value or null
 * - Supported units: px, em, rem, %, vw, vh, vmin, vmax
 *
 * gtemplate_sanitize_option()
 * - Validates customizer option against allowed choices
 * - Input: Mixed value, WP_Customize_Setting object
 * - Output: Sanitized value or default setting
 *
 * =========================
 * DEPENDENCIES
 * =========================
 * WordPress Core:
 * - None (standalone functionality)
 *
 * Theme Files:
 * - Used by register-options-wp.php for customizer validation
 * - Utilized in customizer-css.php for style processing
 *
 * =========================
 * ARCHITECTURAL NOTES
 * =========================
 * - Implements WordPress security best practices for input sanitization
 * - Follows functional programming paradigm for pure validation functions
 * - Uses strict type checking and regex patterns for reliable validation
 * - Provides graceful fallbacks for invalid inputs
 *
 * =========================
 * PERFORMANCE NOTES
 * =========================
 * - Lightweight validation using native PHP functions
 * - Minimal memory footprint with no state maintenance
 * - Efficient regex patterns for validation
 *
 * =========================
 * POTENTIAL IMPROVEMENTS
 * =========================
 * 1. Consider adding support for additional CSS units (e.g., ch, ex)
 * 2. Implement caching for frequently validated values
 * 3. Add support for color validation beyond hex (e.g., RGB, HSL)
 * 4. Consider adding type hints for PHP 7.4+ compatibility
 *
 * =========================
 * CODING STANDARDS
 * =========================
 * - Follows WordPress PHP Coding Standards
 * - Uses defensive programming practices
 * - Implements proper error handling with null returns
 * - Maintains consistent function naming convention
 *
 * =========================
 * SECURITY MEASURES
 * =========================
 * - Strict input validation using regex patterns
 * - No direct database interaction
 * - Proper escaping of output values
 * - Safe handling of null/invalid inputs
 *
 * @author     Niels Erik Toren
 * @copyright  2024 Nierto
 * @license    gCore License
 * @version    1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Guards: child theme may define these first (WordPress loads child before parent)
if (!function_exists('gtemplate_sanitize_hex_color')) {
function gtemplate_sanitize_hex_color($color) {
    if ('' === $color) {
        return '';
    }
    if (preg_match('|^#([A-Fa-f0-9]{3}){1,2}$|', $color)) {
        return $color;
    }
    return null;
}
}

if (!function_exists('gtemplate_sanitize_css_value')) {
function gtemplate_sanitize_css_value($input) {
    // 1-4 space-separated values (padding/margin/position shorthand) or 'auto'
    $valid_units = ['px', 'em', 'rem', '%', 'vw', 'vh', 'vmin', 'vmax', 'ms', 's'];
    $units_pattern = implode('|', $valid_units);
    $single_value = "(?:\\d*\\.?\\d+(?:$units_pattern)?|auto)";
    $pattern = "/^{$single_value}(?:\\s+{$single_value}){0,3}$/";
    if (preg_match($pattern, trim((string) $input))) {
        return $input;
    }
    return null;
}
}

if (!function_exists('gtemplate_sanitize_css_color')) {
function gtemplate_sanitize_css_color($input) {
    // hex, rgb(a), hsl(a), transparent, or var(--name)
    $input = trim((string) $input);
    if ('' === $input || 'transparent' === $input) {
        return $input;
    }
    if (preg_match('|^#([A-Fa-f0-9]{3,4}|[A-Fa-f0-9]{6}|[A-Fa-f0-9]{8})$|', $input)) {
        return $input;
    }
    if (preg_match('/^(rgb|rgba|hsl|hsla)\(\s*[\d.,%\s\/]+\)$/', $input)) {
        return $input;
    }
    if (preg_match('/^var\(--[A-Za-z0-9_-]+\)$/', $input)) {
        return $input;
    }
    return null;
}
}

if (!function_exists('gtemplate_sanitize_option')) {
function gtemplate_sanitize_option($input, $setting) {
    $choices = $setting->manager->get_control($setting->id)->choices;
    return (array_key_exists($input, $choices) ? $input : $setting->default);
}
}

/**
 * Sanitize integer values (allows negative numbers)
 *
 * @param mixed $input The value to sanitize
 * @return int Sanitized integer value
 */
function gtemplate_sanitize_integer($input) {
    return intval($input);
}
