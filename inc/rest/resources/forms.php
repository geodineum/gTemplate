<?php
/**
 * REST API Resource: Forms
 *
 * Endpoints for contact form submission and CSRF token management.
 *
 * Routes:
 *   POST /contact/submit - Submit contact form with anti-spam validation
 *   GET  /csrf-token     - Get fresh CSRF token for cached pages
 *
 * @package gTemplate
 * @subpackage REST
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register form-related REST routes
 *
 * @param string $namespace REST API namespace
 */
function gtemplate_register_form_routes(string $namespace): void {
    // Contact form submission endpoint
    register_rest_route($namespace, '/contact/submit', [
        'methods' => 'POST',
        'callback' => 'gtemplate_rest_submit_contact_form',
        'permission_callback' => '__return_true',
        'args' => [
            'name' => [
                'required' => true,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field'
            ],
            'email' => [
                'required' => true,
                'type' => 'string',
                'format' => 'email',
                'sanitize_callback' => 'sanitize_email'
            ],
            'subject' => [
                'required' => false,
                'type' => 'string',
                'default' => '',
                'sanitize_callback' => 'sanitize_text_field'
            ],
            'message' => [
                'required' => true,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_textarea_field'
            ]
        ]
    ]);

    // CSRF token endpoint (for refreshing stale tokens on cached pages)
    register_rest_route($namespace, '/csrf-token', [
        'methods' => 'GET',
        'callback' => 'gtemplate_rest_get_csrf_token',
        'permission_callback' => '__return_true'
    ]);
}

/**
 * REST endpoint: Submit contact form
 *
 * Processes contact form submissions with:
 * - Anti-spam validation (honeypot, timing, behavior)
 * - Rate limiting via SecurityManager
 * - Email notification to site admin
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function gtemplate_rest_submit_contact_form($request) {
    // Get form data
    $name = $request->get_param('name');
    $email = $request->get_param('email');
    $subject = $request->get_param('subject') ?: 'Contact Form Submission';
    $message = $request->get_param('message');

    // Anti-spam: Check honeypot fields (bots fill these)
    $honeypot_fields = ['website_url', 'phone_number_2', 'company_fax'];
    foreach ($honeypot_fields as $field) {
        if (!empty($request->get_param($field))) {
            // Bot detected - silently reject
            error_log("[gTemplate Contact] Honeypot triggered by: " . gtemplate_get_client_identifier());
            return new WP_REST_Response([
                'success' => true,  // Lie to bots
                'message' => 'Thank you for your message.'
            ], 200);
        }
    }

    // Anti-spam: Check form timing (humans take > 3 seconds)
    $form_load_time = (int) $request->get_param('_form_load_time');
    $now = time();
    if ($form_load_time > 0 && ($now - $form_load_time) < 3) {
        error_log("[gTemplate Contact] Timing check failed (too fast): " . gtemplate_get_client_identifier());
        return new WP_REST_Response([
            'success' => false,
            'error' => 'Please take your time filling out the form.'
        ], 400);
    }

    // Anti-spam: Check JS challenge (proves JavaScript execution)
    $js_challenge = $request->get_param('_js_challenge');
    if (empty($js_challenge) || strpos($js_challenge, 'gcore_') !== 0) {
        error_log("[gTemplate Contact] JS challenge failed: " . gtemplate_get_client_identifier());
        return new WP_REST_Response([
            'success' => false,
            'error' => 'JavaScript verification failed. Please enable JavaScript.'
        ], 400);
    }

    // Validate email format
    if (!is_email($email)) {
        return new WP_REST_Response([
            'success' => false,
            'error' => 'Please provide a valid email address.'
        ], 400);
    }

    // Validate message length
    if (strlen($message) < 10) {
        return new WP_REST_Response([
            'success' => false,
            'error' => 'Please provide a longer message (at least 10 characters).'
        ], 400);
    }

    // Get source URL and face ID for metadata
    $source_url = $request->get_param('source_url') ?: home_url();
    $face_id = $request->get_param('face_id') ?: 0;
    $client_ip = gtemplate_get_client_identifier();

    // Queue message to gNode-COMMS via gCore
    $gnode_sent = false;
    global $gCore;

    if ($gCore) {
        try {
            $gNodeClient = $gCore->getService('gnode_client');

            if ($gNodeClient && method_exists($gNodeClient, 'queueContactForm')) {
                $messageId = $gNodeClient->queueContactForm(
                    $name,
                    $email,
                    $subject,
                    $message,
                    [
                        'source_url' => $source_url,
                        'face_id' => (int) $face_id,
                        'ip' => $client_ip,
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                    ]
                );

                if ($messageId) {
                    error_log("[gTemplate Contact] Message queued to comms stream: {$messageId}");
                    $gnode_sent = true;
                } else {
                    error_log("[gTemplate Contact] Failed to queue message to comms stream");
                }
            } else {
                error_log("[gTemplate Contact] gNode-Client not available or missing queueContactForm method");
            }
        } catch (\Throwable $e) {
            error_log("[gTemplate Contact] gNode-Client error: " . $e->getMessage());
        }
    } else {
        error_log("[gTemplate Contact] gCore not initialized in REST context");
    }

    // If gNode-Client unavailable, try direct KeyBasedClient initialization
    if (!$gnode_sent) {
        try {
            $keybased = gtemplate_gnode_keybased();
            if ($keybased) {
                $storage = $keybased->getStorage();
                if ($storage) {
                    $site_id = gtemplate_get_site_id();
                    $config = gtemplate_get_registration_config();
                    $environment = $config['metadata']['environment'] ?? 'staging';
                    $comms_key = "{{$site_id}}:gnode:comms:{$environment}";

                    $payload = json_encode([
                        'id' => wp_generate_uuid4(),
                        'type' => 'contact',
                        'timestamp' => current_time('c'),
                        'site_id' => $site_id,
                        'priority' => 3,
                        'sender' => [
                            'name' => $name,
                            'email' => $email,
                            'ip' => $client_ip,
                            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                        ],
                        'content' => [
                            'subject' => $subject,
                            'body' => $message,
                        ],
                        'metadata' => [
                            'form_type' => 'contact',
                            'source_url' => $source_url,
                            'face_id' => (int) $face_id,
                        ],
                    ]);

                    $msgId = $storage->xadd($comms_key, '*', ['payload' => $payload]);
                    if ($msgId) {
                        error_log("[gTemplate Contact] Direct XADD to comms stream: {$msgId}");
                        $gnode_sent = true;
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log("[gTemplate Contact] Direct ValKey fallback error: " . $e->getMessage());
        }
    }

    if ($gnode_sent) {
        $success_html = '<div class="form-success">' .
            '<h3>Thank You!</h3>' .
            '<p>Your message has been sent successfully. We\'ll get back to you soon.</p>' .
            '</div>';

        header('Content-Type: text/html; charset=UTF-8');
        echo $success_html;
        exit;
    }

    // Fallback: Try wp_mail if gNode paths unavailable
    error_log("[gTemplate Contact] Falling back to wp_mail");

    $to = get_option('admin_email');
    $site_name = get_bloginfo('name');
    $email_subject = "[{$site_name}] {$subject}";

    $email_body = sprintf(
        "New contact form submission from %s\n\n" .
        "Name: %s\n" .
        "Email: %s\n" .
        "Subject: %s\n\n" .
        "Message:\n%s\n\n" .
        "---\n" .
        "Sent from: %s\n" .
        "Face ID: %s\n" .
        "IP Address: %s\n" .
        "Submitted: %s",
        $site_name,
        $name,
        $email,
        $subject,
        $message,
        $source_url,
        $face_id,
        $client_ip,
        current_time('mysql')
    );

    $headers = [
        'From: ' . $site_name . ' <' . $to . '>',
        'Reply-To: ' . $name . ' <' . $email . '>',
        'Content-Type: text/plain; charset=UTF-8'
    ];

    $sent = wp_mail($to, $email_subject, $email_body, $headers);

    if ($sent) {
        error_log("[gTemplate Contact] Message sent via wp_mail to {$to}");

        $success_html = '<div class="form-success">' .
            '<h3>Thank You!</h3>' .
            '<p>Your message has been sent successfully. We\'ll get back to you soon.</p>' .
            '</div>';

        header('Content-Type: text/html; charset=UTF-8');
        echo $success_html;
        exit;
    }

    // Both gNode and wp_mail failed
    error_log("[gTemplate Contact] All delivery methods failed (gNode + wp_mail) for submission from {$email}");

    $error_html = '<div class="form-error">' .
        '<h3>Message Not Sent</h3>' .
        '<p>We were unable to send your message at this time. ' .
        'Please try again later or contact us directly at ' .
        '<a href="mailto:' . esc_attr($to) . '">' . esc_html($to) . '</a>.</p>' .
        '</div>';

    status_header(500);
    header('Content-Type: text/html; charset=UTF-8');
    echo $error_html;
    exit;
}

/**
 * REST endpoint: Get fresh CSRF token
 *
 * Required for cached pages where the embedded token may be stale.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function gtemplate_rest_get_csrf_token($request) {
    $token = wp_create_nonce('gcore_form_submit');

    return new WP_REST_Response([
        'success' => true,
        'token' => $token,
        'expires_in' => DAY_IN_SECONDS  // WordPress nonces are valid for ~24 hours
    ], 200);
}
