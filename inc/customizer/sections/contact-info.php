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
        'description' => __('Shown as "When I answer" on the contact face.', 'gtemplate'),
        'section' => 'gtemplate_contact_info',
        'type' => 'text',
    ]);

    // GitHub
    $wp_customize->add_setting($prefix . '_contact_github', [
        'default' => '',
        'sanitize_callback' => 'esc_url_raw',
        'transport' => 'refresh',
    ]);
    $wp_customize->add_control($prefix . '_contact_github', [
        'label' => __('GitHub URL', 'gtemplate'),
        'description' => __('Full URL. Leave empty to hide the row.', 'gtemplate'),
        'section' => 'gtemplate_contact_info',
        'type' => 'url',
    ]);

    // LinkedIn
    $wp_customize->add_setting($prefix . '_contact_linkedin', [
        'default' => '',
        'sanitize_callback' => 'esc_url_raw',
        'transport' => 'refresh',
    ]);
    $wp_customize->add_control($prefix . '_contact_linkedin', [
        'label' => __('LinkedIn URL', 'gtemplate'),
        'description' => __('Full URL. Leave empty to hide the row.', 'gtemplate'),
        'section' => 'gtemplate_contact_info',
        'type' => 'url',
    ]);

    // Intro heading
    $wp_customize->add_setting($prefix . '_contact_heading', [
        'default' => '',
        'sanitize_callback' => 'sanitize_text_field',
        'transport' => 'refresh',
    ]);
    $wp_customize->add_control($prefix . '_contact_heading', [
        'label' => __('Intro Heading', 'gtemplate'),
        'description' => __('Headline above the form. Empty hides the whole intro block.', 'gtemplate'),
        'section' => 'gtemplate_contact_info',
        'type' => 'text',
    ]);

    // Intro promise
    $wp_customize->add_setting($prefix . '_contact_promise', [
        'default' => '',
        'sanitize_callback' => 'sanitize_textarea_field',
        'transport' => 'refresh',
    ]);
    $wp_customize->add_control($prefix . '_contact_promise', [
        'label' => __('Intro Text', 'gtemplate'),
        'description' => __('What happens after someone presses Send - reply time, who reads it.', 'gtemplate'),
        'section' => 'gtemplate_contact_info',
        'type' => 'textarea',
    ]);

    // Privacy note
    $wp_customize->add_setting($prefix . '_contact_privacy', [
        'default' => '',
        'sanitize_callback' => 'sanitize_text_field',
        'transport' => 'refresh',
    ]);
    $wp_customize->add_control($prefix . '_contact_privacy', [
        'label' => __('Privacy Note', 'gtemplate'),
        'description' => __('Small print under the form. Empty uses the built-in default.', 'gtemplate'),
        'section' => 'gtemplate_contact_info',
        'type' => 'text',
    ]);
}
