<?php
declare(strict_types=1);
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

    // Generic form submission — captures any [gform] into a per-form stream.
    register_rest_route($namespace, '/form/submit', [
        'methods' => 'POST',
        'callback' => 'gtemplate_rest_submit_form',
        'permission_callback' => '__return_true',
    ]);
}

/**
 * Deliver a message to gNode-COMMS for email dispatch — the single canonical
 * path shared by the contact form and any email-delivering [gform]. Tries the
 * gNode-Client (which defaults the environment to production), then a direct
 * XADD fallback in the same flat COMMS field shape. The environment is resolved
 * via gtemplate_detect_environment() (defaults to production); a non-production
 * value makes COMMS dry-run and send no email, so it is never defaulted to
 * staging here.
 *
 * @return bool true if the message reached the comms stream.
 */
function gtemplate_queue_comms(string $type, array $channels, array $sender, string $subject, string $body, array $meta = [], int $priority = 3): bool {
    $source_url = $meta['source_url'] ?? home_url();
    $face_id    = (int) ($meta['face_id'] ?? 0);
    $form_id    = (string) ($meta['form_id'] ?? $type);

    // Primary: gNode-Client general producer (stamps top-level environment for
    // the COMMS non-prod gate; builds the canonical message shape).
    global $gCore;
    if ($gCore) {
        try {
            $gNodeClient = $gCore->getService('gnode_client');
            if ($gNodeClient && method_exists($gNodeClient, 'queueCommsMessage')) {
                $id = $gNodeClient->queueCommsMessage(
                    $type,
                    $sender,
                    ['subject' => $subject, 'body' => $body],
                    ['form_type' => $form_id, 'source_url' => $source_url, 'face_id' => $face_id],
                    $priority,
                    $channels
                );
                if ($id) {
                    gtemplate_track_error("[gTemplate comms] queued {$type} via client: {$id}");
                    return true;
                }
            }
        } catch (\Throwable $e) {
            gtemplate_track_error('[gTemplate comms] gNode-Client error: ' . $e->getMessage());
        }
    }

    // Fallback: direct XADD, same flat shape the daemon parses. type, channels
    // and priority all pass through — never hardcode email here or an anonymous
    // poll routed via the fallback would email the operator.
    try {
        $keybased = gtemplate_gnode_keybased();
        $storage  = $keybased ? $keybased->getStorage() : null;
        if ($storage) {
            $site_id     = gtemplate_get_site_id();
            $environment = gtemplate_detect_environment();
            $comms_key   = "{{$site_id}}:gnode:comms:{$environment}";
            $msgId = $storage->xadd($comms_key, '*', [
                'id'          => wp_generate_uuid4(),
                'type'        => $type,
                'timestamp'   => current_time('c'),
                'site_id'     => $site_id,
                'environment' => $environment,
                'priority'    => (string) $priority,
                'sender'      => json_encode([
                    'name'       => $sender['name'] ?? '',
                    'email'      => $sender['email'] ?? '',
                    'ip'         => $sender['ip'] ?? '',
                    'user_agent' => $sender['user_agent'] ?? '',
                ]),
                'content'     => json_encode([
                    'subject'     => $subject,
                    'body'        => $body,
                    'attachments' => [],
                ]),
                'metadata'    => json_encode([
                    'form_type'   => $form_id,
                    'source_url'  => $source_url,
                    'face_id'     => $face_id,
                    'environment' => $environment,
                ]),
                'dispatch'    => json_encode([
                    'channels'     => $channels,
                    'status'       => 'pending',
                    'attempts'     => 0,
                    'last_attempt' => null,
                    'next_retry'   => null,
                ]),
            ]);
            if ($msgId) {
                gtemplate_track_error("[gTemplate comms] direct XADD {$type}: {$msgId}");
                return true;
            }
        }
    } catch (\Throwable $e) {
        gtemplate_track_error('[gTemplate comms] direct XADD error: ' . $e->getMessage());
    }

    return false;
}

/**
 * Backward-compatible email wrapper — the contact form and any email [gform].
 */
function gtemplate_queue_comms_email(string $name, string $email, string $subject, string $message, array $meta = []): bool {
    return gtemplate_queue_comms('contact', ['email'], [
        'name'       => $name,
        'email'      => $email,
        'ip'         => $meta['ip'] ?? gtemplate_get_client_identifier(),
        'user_agent' => $meta['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''),
    ], $subject, $message, $meta, 3);
}

/**
 * form_id + delivery intent → [comms type, channels, priority]. Email-intent
 * forms keep type 'contact' so recipient type-filters still match (the specific
 * form is in metadata.form_type). Anonymous polls/feedback are record-only on
 * the 'record' sentinel channel: seen in the COMMS console, never emailed.
 */
function gtemplate_comms_route_for(string $form_id, string $deliver): array {
    if ($deliver === 'email') {
        return ['contact', ['email'], 3];
    }
    switch ($form_id) {
        case 'poll_launchpad':
        case 'poll_discovery':
            $type = 'poll';
            break;
        case 'feedback':
            $type = 'feedback';
            break;
        default:
            $type = 'signal';
    }
    return [$type, ['record'], 5];
}

/**
 * REST endpoint: generic [gform] submission.
 *
 * Captures any form rendered by [gform] into a per-form stream
 * {site}:forms:<form_id>, tagged with a HASHED visitor fingerprint, the source
 * URI and a timestamp — the audience-data surface the dashboard mines. Same
 * anti-abuse posture as the contact form (honeypot + timing + JS challenge),
 * requires explicit consent, rate-limits per fingerprint, never stores the raw
 * IP, and caps stream length for retention.
 */
function gtemplate_rest_submit_form($request) {
    $params  = $request->get_params();
    $form_id = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($params['form_id'] ?? $params['_gform_id'] ?? '')));
    if ($form_id === '') {
        return new WP_REST_Response(['success' => false, 'error' => 'Missing form id.'], 400);
    }

    // Honeypot (bots fill these) — lie to them.
    foreach (['website_url', 'phone_number_2', 'company_fax'] as $hp) {
        if (!empty($params[$hp])) {
            return new WP_REST_Response(['success' => true, 'message' => 'Thank you.'], 200);
        }
    }
    // Timing (humans take > 3s).
    $loaded = (int) ($params['_form_load_time'] ?? 0);
    if ($loaded > 0 && (time() - $loaded) < 3) {
        return new WP_REST_Response(['success' => false, 'error' => 'Please take your time filling out the form.'], 400);
    }
    // JS challenge (proves JS executed).
    $js = (string) ($params['_js_challenge'] ?? '');
    if ($js === '' || strpos($js, 'gcore_') !== 0) {
        return new WP_REST_Response(['success' => false, 'error' => 'JavaScript verification failed. Please enable JavaScript.'], 400);
    }
    // Consent is mandatory.
    $consent = $params['consent'] ?? $params['_consent'] ?? '';
    if (empty($consent) || in_array(strtolower((string) $consent), ['0', 'false', 'no', 'off'], true)) {
        return new WP_REST_Response(['success' => false, 'error' => 'Please consent before submitting.'], 400);
    }

    $site_id    = gtemplate_get_site_id();
    $ip         = gtemplate_get_client_identifier();
    $ua         = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $fp         = substr(hash('sha256', $ip . '|' . $ua . '|' . $site_id), 0, 24); // hashed, never raw IP
    $source_url = esc_url_raw((string) ($params['source_url'] ?? ($_SERVER['HTTP_REFERER'] ?? home_url())));

    // Visitor-supplied fields only (strip control fields).
    $control = ['form_id', '_gform_id', '_form_load_time', '_js_challenge', '_gf_nonce', 'consent', '_consent', 'source_url', 'deliver', '_deliver', 'website_url', 'phone_number_2', 'company_fax'];
    $fields  = [];
    foreach ($params as $k => $v) {
        if (in_array($k, $control, true)) { continue; }
        if (is_array($v)) { $v = implode(', ', array_map('strval', $v)); }
        $fields[sanitize_key($k)] = sanitize_textarea_field((string) $v);
    }
    if (empty($fields)) {
        return new WP_REST_Response(['success' => false, 'error' => 'Nothing to submit.'], 400);
    }

    // Opt-in email delivery: a contact-style [gform] sets deliver="email". The
    // submission is still captured to the forms stream below; delivery is an
    // additional step routed through the one canonical comms path.
    $deliver    = strtolower((string) ($params['deliver'] ?? $params['_deliver'] ?? ''));
    $email_name = $email_addr = $email_msg = '';
    if ($deliver === 'email') {
        $email_name = (string) ($fields['name'] ?? $fields['your_name'] ?? '');
        $email_addr = (string) ($fields['email'] ?? $fields['your_email'] ?? '');
        $email_msg  = (string) ($fields['message'] ?? $fields['msg'] ?? $fields['your_message'] ?? '');
        if (!is_email($email_addr)) {
            return new WP_REST_Response(['success' => false, 'error' => 'Please provide a valid email address.'], 400);
        }
        if (strlen(trim($email_msg)) < 10) {
            return new WP_REST_Response(['success' => false, 'error' => 'Please provide a longer message (at least 10 characters).'], 400);
        }
    }

    $stored = false;
    try {
        $keybased = gtemplate_gnode_keybased();
        $storage  = $keybased ? $keybased->getStorage() : null;
        if ($storage) {
            // Rate-limit: 20 submissions / hour / fingerprint.
            try {
                $rl = '{' . $site_id . '}:forms:rl:' . $fp;
                $count = (int) $storage->incr($rl);
                if ($count === 1 && method_exists($storage, 'expire')) { $storage->expire($rl, 3600); }
                if ($count > 20) {
                    return new WP_REST_Response(['success' => false, 'error' => 'Too many submissions. Please try again later.'], 429);
                }
            } catch (\Throwable $e) { /* best-effort */ }

            $stream = '{' . $site_id . '}:forms:' . $form_id;
            $id = $storage->xadd($stream, '*', [
                'form_id' => $form_id,
                'ts'      => (string) time(),
                'iso'     => current_time('c'),
                'fp'      => $fp,
                'uri'     => $source_url,
                'ua'      => substr($ua, 0, 200),
                'consent' => '1',
                'fields'  => (string) wp_json_encode($fields),
            ]);
            $stored = (bool) $id;
            try { if (method_exists($storage, 'xtrim')) { $storage->xtrim($stream, 5000, true); } } catch (\Throwable $e) {}
        }
    } catch (\Throwable $e) {
        if (function_exists('gtemplate_track_error')) {
            gtemplate_track_error('[gTemplate gform] capture failed: ' . $e->getMessage());
        }
    }

    if (!$stored) {
        return new WP_REST_Response(['success' => false, 'error' => 'Could not record your submission. Please try again.'], 500);
    }

    // Newsletter opt-in: any form may offer a "newsletter" checkbox. When ticked
    // with a valid email, mirror the address into a per-environment subscriber
    // stream so the list is one queryable place, independent of the capturing
    // form. The flag is already inside the forms-stream fields JSON regardless.
    $newsletter = strtolower(trim((string) ($params['newsletter'] ?? '')));
    if (in_array($newsletter, ['1', 'true', 'yes', 'on'], true) && isset($storage) && $storage) {
        $sub_email = (string) ($fields['email'] ?? $fields['your_email'] ?? '');
        if (is_email($sub_email)) {
            try {
                $env = function_exists('gtemplate_detect_environment') ? gtemplate_detect_environment() : 'production';
                $storage->xadd('{' . $site_id . '}:newsletter:' . $env, '*', [
                    'email'   => $sub_email,
                    'name'    => (string) ($fields['name'] ?? ''),
                    'ts'      => (string) time(),
                    'iso'     => current_time('c'),
                    'form_id' => $form_id,
                    'fp'      => $fp,
                ]);
            } catch (\Throwable $e) { /* best-effort; flag is already in the forms stream */ }
        }
    }

    // Every submission routes through COMMS, the single hub for all channels.
    // Email-intent forms dispatch outbound (delivery must succeed); anonymous
    // polls/feedback are record-only (best-effort — the durable record is the
    // forms stream above, so a COMMS hiccup never fails the request).
    list($comms_type, $comms_channels, $comms_priority) = gtemplate_comms_route_for($form_id, $deliver);
    if ($deliver === 'email') {
        $subject = (string) ($fields['subject'] ?? sprintf('[%s] %s form', get_bloginfo('name'), $form_id));
        $delivered = gtemplate_queue_comms($comms_type, $comms_channels, [
            'name'       => $email_name,
            'email'      => $email_addr,
            'ip'         => $ip,
            'user_agent' => $ua,
        ], $subject, $email_msg, [
            'source_url' => $source_url,
            'face_id'    => 0,
            'form_id'    => $form_id,
        ], $comms_priority);
        if (!$delivered) {
            return new WP_REST_Response(['success' => false, 'error' => 'We could not send your message right now. Please try again later.'], 502);
        }
    } else {
        $subject = sprintf('[%s] %s', $comms_type, $form_id);
        $recorded = gtemplate_queue_comms($comms_type, $comms_channels, [
            'name'       => '',
            'email'      => '',
            'ip'         => '',
            'user_agent' => substr($ua, 0, 200),
        ], $subject, (string) wp_json_encode($fields), [
            'source_url' => $source_url,
            'form_id'    => $form_id,
        ], $comms_priority);
        if (!$recorded && function_exists('gtemplate_track_error')) {
            gtemplate_track_error("[gTemplate comms] record-only {$form_id} did not reach COMMS (captured to forms stream)");
        }
    }

    return new WP_REST_Response(['success' => true, 'message' => 'Thank you. Your submission has been received.'], 200);
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
            gtemplate_track_error("[gTemplate Contact] Honeypot triggered by: " . gtemplate_get_client_identifier());
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
        gtemplate_track_error("[gTemplate Contact] Timing check failed (too fast): " . gtemplate_get_client_identifier());
        return new WP_REST_Response([
            'success' => false,
            'error' => 'Please take your time filling out the form.'
        ], 400);
    }

    // Anti-spam: Check JS challenge (proves JavaScript execution)
    $js_challenge = $request->get_param('_js_challenge');
    if (empty($js_challenge) || strpos($js_challenge, 'gcore_') !== 0) {
        gtemplate_track_error("[gTemplate Contact] JS challenge failed: " . gtemplate_get_client_identifier());
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

    // Queue to gNode-COMMS via the one canonical delivery helper (gNode-Client,
    // then direct-XADD fallback in the same COMMS field shape). wp_mail below
    // stays the last-resort path when the comms stream is unreachable.
    $gnode_sent = gtemplate_queue_comms_email($name, $email, $subject, $message, [
        'source_url' => $source_url,
        'face_id'    => (int) $face_id,
        'ip'         => $client_ip,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    ]);

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
    gtemplate_track_error("[gTemplate Contact] Falling back to wp_mail");

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
        gtemplate_track_error("[gTemplate Contact] Message sent via wp_mail to {$to}");

        $success_html = '<div class="form-success">' .
            '<h3>Thank You!</h3>' .
            '<p>Your message has been sent successfully. We\'ll get back to you soon.</p>' .
            '</div>';

        header('Content-Type: text/html; charset=UTF-8');
        echo $success_html;
        exit;
    }

    // Both gNode and wp_mail failed
    gtemplate_track_error("[gTemplate Contact] All delivery methods failed (gNode + wp_mail) for submission from {$email}");

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
