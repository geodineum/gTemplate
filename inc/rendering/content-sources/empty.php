<?php
/**
 * Empty Content Message
 *
 * Provides user-friendly messages when a face/cell has no content
 * configured, guiding them to the Customizer.
 *
 * @package    gTemplate
 * @subpackage Rendering\ContentSources
 * @since      1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get empty content message for unconfigured faces
 *
 * @param string $type Content source type ('page', 'post', 'custom', 'template')
 * @param int $content_id Optional content ID for specific error messages
 * @return string HTML message
 */
function gtemplate_get_empty_content_message($type, $content_id = 0) {
    $face_label = gtemplate_get_face_label();

    $messages = [
        'page' => $content_id > 0
            ? sprintf(__('Page ID %d not found or not published.', 'gtemplate'), $content_id)
            : __('No page selected. Go to Customizer to choose a page.', 'gtemplate'),
        'post' => $content_id > 0
            ? sprintf(__('Post ID %d not found or not published.', 'gtemplate'), $content_id)
            : __('No post selected. Go to Customizer to choose a post.', 'gtemplate'),
        'custom' => __('No custom HTML entered. Go to Customizer to add content.', 'gtemplate'),
        'template' => __('No template selected. Go to Customizer to choose a template.', 'gtemplate'),
    ];

    $message = $messages[$type] ?? __('No content configured.', 'gtemplate');
    $hint = sprintf('Appearance &rarr; Customize &rarr; %s Settings', ucfirst($face_label));

    return sprintf(
        '<div class="face-content face-content-empty" data-source="%s">
            <div style="padding: 40px; text-align: center;">
                <p style="font-size: 2vmin; color: #666; margin-bottom: 20px;">%s</p>
                <p style="font-size: 1.6vmin; color: #999;"><em>%s</em></p>
            </div>
        </div>',
        esc_attr($type),
        esc_html($message),
        esc_html($hint)
    );
}
