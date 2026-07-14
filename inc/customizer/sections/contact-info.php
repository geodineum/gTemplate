<?php
declare(strict_types=1);
/**
 * Customizer Section: Contact Information
 *
 * Contact details used by the contact-form template and
 * child theme contact pages. Setting IDs use the theme prefix
 * so child themes inherit a properly-namespaced set.
 *
 * @package     gTemplate
 * @subpackage  Customizer
 * @since       2.2.0
 */

if (!defined('ABSPATH')) exit;

/**
 * Register Contact Information customizer section and controls.
 *
 * @param WP_Customize_Manager $wp_customize
 */
function gtemplate_customizer_contact_info($wp_customize) {
    $prefix = gtemplate_get_theme_prefix();

    $wp_customize->add_section('gtemplate_contact_info', array(
        'title' => __('Contact Information', 'gtemplate'),
        'description' => __('Contact details used by the contact form template.', 'gtemplate'),
        'priority' => 190,
    ));

    // Email
    $wp_customize->add_setting($prefix . '_contact_email', [
        'default' => '',
        'sanitize_callback' => 'sanitize_email',
        'transport' => 'refresh',
    ]);
    $wp_customize->add_control($prefix . '_contact_email', [
        'label' => __('Contact Email', 'gtemplate'),
        'description' => __('Displayed on the contact page. Falls back to admin email if empty.', 'gtemplate'),
        'section' => 'gtemplate_contact_info',
        'type' => 'email',
    ]);

    // Phone
    $wp_customize->add_setting($prefix . '_contact_phone', [
        'default' => '',
        'sanitize_callback' => 'sanitize_text_field',
        'transport' => 'refresh',
    ]);
    $wp_customize->add_control($prefix . '_contact_phone', [
        'label' => __('Phone Number', 'gtemplate'),
        'section' => 'gtemplate_contact_info',
        'type' => 'tel',
    ]);

    // Phone protection
    $wp_customize->add_setting($prefix . '_contact_phone_protect', [
        'default' => true,
        'sanitize_callback' => 'absint',
        'transport' => 'refresh',
    ]);
    $wp_customize->add_control($prefix . '_contact_phone_protect', [
        'label' => __('Protect Phone from Bots', 'gtemplate'),
        'description' => __('Splits phone number into chunks to reduce scraping.', 'gtemplate'),
        'section' => 'gtemplate_contact_info',
        'type' => 'checkbox',
    ]);

    // Address
    $wp_customize->add_setting($prefix . '_contact_address', [
        'default' => '',
        'sanitize_callback' => 'sanitize_text_field',
        'transport' => 'refresh',
    ]);
    $wp_customize->add_control($prefix . '_contact_address', [
        'label' => __('Address', 'gtemplate'),
        'section' => 'gtemplate_contact_info',
        'type' => 'textarea',
    ]);

    // Hours
    $wp_customize->add_setting($prefix . '_contact_hours', [
        'default' => '',
        'sanitize_callback' => 'sanitize_text_field',
        'transport' => 'refresh',
    ]);
    $wp_customize->add_control($prefix . '_contact_hours', [
        'label' => __('Business Hours', 'gtemplate'),
        'section' => 'gtemplate_contact_info',
        'type' => 'text',
    ]);
}
