<?php
declare(strict_types=1);
/**
 * Email-to-Post Feature for gTemplate
 *
 * Enables creating WordPress posts by sending emails to a designated address.
 * Incoming emails are received via webhook from the email gateway.
 *
 * Features:
 * - REST API endpoint for email webhook
 * - Sender verification (whitelist)
 * - Attachment handling (images become media library items)
 * - Draft posts for review before publishing
 * - Rate limiting to prevent spam
 *
 * @package gTemplate
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register REST API routes for email-to-post
 */
add_action('rest_api_init', function() {
    // Main webhook endpoint
    register_rest_route('gtemplate/v1', '/email-to-post', [
        'methods' => 'POST',
        'callback' => 'gtemplate_handle_email_to_post',
        'permission_callback' => 'gtemplate_verify_email_webhook',
    ]);

    // Get email-to-post status/config (admin only)
    register_rest_route('gtemplate/v1', '/email-to-post/status', [
        'methods' => 'GET',
        'callback' => 'gtemplate_email_to_post_status',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        },
    ]);
});

/**
 * Verify email webhook request authenticity
 *
 * Checks:
 * 1. Webhook secret matches
 * 2. Request comes from allowed IP (if configured)
 * 3. Rate limiting not exceeded
 *
 * @param WP_REST_Request $request
 * @return bool|WP_Error
 */
function gtemplate_verify_email_webhook(WP_REST_Request $request): bool
{
    // Commit 1.11.c: HMAC-SHA256 over `(timestamp + body)`
    // with replay-cache. Pre-fix was a static-secret X-Webhook-Secret
    // header check — a leak (log, proxy hop, gateway compromise)
    // turned the webhook into a forge-anything primitive. Post-fix:
    //
    //   - X-Webhook-Timestamp: <unix-seconds>          required
    //   - X-Webhook-Signature: sha256=<hex>            required
    //   - signature = hash_hmac('sha256', "<ts>.<body>", $secret)
    //   - reject if |now - ts| > 5 minutes
    //   - short replay-cache (5 min sliding) keyed on the signature
    //   - rate-limit retained (max 10 emails / minute, sliding window)
    //
    // Backward-compat shim: if X-Webhook-Timestamp + X-Webhook-Signature
    // are absent BUT the legacy X-Webhook-Secret matches, log a
    // deprecation warning and accept once. This lets gateway operators
    // upgrade their signing logic in their own time. Remove the shim
    // in a future Tier-2 commit once all operators have migrated.

    $webhook_secret = get_option('gtemplate_email_webhook_secret', '');
    if (empty($webhook_secret)) {
        gtemplate_track_error('[gTemplate Email-to-Post] Webhook secret not configured');
        return false;
    }

    $timestamp = (string) ($request->get_header('X-Webhook-Timestamp') ?? '');
    $signature = (string) ($request->get_header('X-Webhook-Signature') ?? '');

    if ($timestamp !== '' && $signature !== '') {
        // HMAC path (preferred).
        $ts_int = (int) $timestamp;
        if ($ts_int <= 0 || abs(time() - $ts_int) > 300) {
            gtemplate_track_error('[gTemplate Email-to-Post] Webhook timestamp out of range (|now - ts| > 5 min)');
            return false;
        }

        // strip optional `sha256=` prefix
        if (\strpos($signature, 'sha256=') === 0) {
            $signature = \substr($signature, 7);
        }

        // The raw body is what was signed; WP_REST_Request gives us
        // the JSON-decoded params, but get_body() returns the raw bytes.
        $body = (string) $request->get_body();
        $expected = \hash_hmac('sha256', $timestamp . '.' . $body, $webhook_secret);

        if (!\hash_equals($expected, $signature)) {
            gtemplate_track_error('[gTemplate Email-to-Post] Webhook HMAC mismatch');
            return false;
        }

        // Replay-cache. Use the signature itself as the cache key.
        $replay_key = 'gtemplate_email_webhook_replay_' . \substr($expected, 0, 32);
        if (get_transient($replay_key) !== false) {
            gtemplate_track_error('[gTemplate Email-to-Post] Webhook replay rejected');
            return false;
        }
        set_transient($replay_key, 1, 300); // 5 minute window
    } else {
        // Legacy static-secret path (deprecated, shim).
        $provided_secret = (string) ($request->get_header('X-Webhook-Secret') ?? '');
        if ($provided_secret === '' || !\hash_equals($webhook_secret, $provided_secret)) {
            gtemplate_track_error('[gTemplate Email-to-Post] Invalid webhook auth (no HMAC headers, fallback X-Webhook-Secret mismatch)');
            return false;
        }
        gtemplate_track_error('[gTemplate Email-to-Post] DEPRECATED: webhook used legacy X-Webhook-Secret instead of HMAC. Migrate gateway to send X-Webhook-Timestamp + X-Webhook-Signature.');
    }

    // Rate limiting: max 10 emails per minute (unchanged).
    $rate_key = 'gtemplate_email_webhook_rate_' . date('YmdHi');
    $rate_count = (int) get_transient($rate_key);

    if ($rate_count >= 10) {
        gtemplate_track_error('[gTemplate Email-to-Post] Rate limit exceeded');
        return false;
    }

    set_transient($rate_key, $rate_count + 1, 120);

    return true;
}

/**
 * Handle incoming email-to-post webhook
 *
 * Expected payload:
 * {
 *   "from": "sender@example.com",
 *   "to": "posts@site-hash.geodineum.email",
 *   "subject": "Post Title",
 *   "body": "Post content in HTML or plain text",
 *   "body_plain": "Plain text version",
 *   "attachments": [
 *     {"filename": "image.jpg", "content_type": "image/jpeg", "url": "https://..."}
 *   ],
 *   "timestamp": "2025-12-11T10:00:00Z"
 * }
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function gtemplate_handle_email_to_post(WP_REST_Request $request)
{
    $email_data = $request->get_json_params();

    // Validate required fields
    if (empty($email_data['from']) || empty($email_data['subject'])) {
        return new WP_Error(
            'invalid_payload',
            'Missing required fields: from, subject',
            ['status' => 400]
        );
    }

    // Verify sender is authorized
    $allowed_senders = get_option('gtemplate_email_to_post_senders', []);
    $sender_email = sanitize_email($email_data['from']);

    if (!gtemplate_is_sender_authorized($sender_email, $allowed_senders)) {
        gtemplate_track_error("[gTemplate Email-to-Post] Unauthorized sender: {$sender_email}");
        return new WP_Error(
            'unauthorized_sender',
            'Sender email is not authorized to create posts',
            ['status' => 403]
        );
    }

    // Sanitize email content
    $post_title = sanitize_text_field($email_data['subject']);
    $post_content = !empty($email_data['body'])
        ? wp_kses_post($email_data['body'])
        : wp_kses_post(nl2br($email_data['body_plain'] ?? ''));

    // Determine post author
    $author_id = gtemplate_get_email_author($sender_email);

    // Create draft post
    $post_data = [
        'post_title' => $post_title,
        'post_content' => $post_content,
        'post_status' => 'draft',
        'post_author' => $author_id,
        'post_type' => 'post',
        'meta_input' => [
            '_gtemplate_email_source' => $sender_email,
            '_gtemplate_email_received' => current_time('mysql'),
        ],
    ];

    // Apply default category if configured
    $default_category = get_option('gtemplate_email_to_post_category', 0);
    if ($default_category > 0) {
        $post_data['post_category'] = [$default_category];
    }

    $post_id = wp_insert_post($post_data, true);

    if (is_wp_error($post_id)) {
        gtemplate_track_error('[gTemplate Email-to-Post] Failed to create post: ' . $post_id->get_error_message());
        return new WP_Error(
            'post_creation_failed',
            'Failed to create post: ' . $post_id->get_error_message(),
            ['status' => 500]
        );
    }

    // Handle attachments
    $attachment_ids = [];
    if (!empty($email_data['attachments']) && is_array($email_data['attachments'])) {
        foreach ($email_data['attachments'] as $attachment) {
            $attachment_id = gtemplate_sideload_email_attachment($post_id, $attachment);
            if ($attachment_id) {
                $attachment_ids[] = $attachment_id;
            }
        }

        // Set first image as featured image
        if (!empty($attachment_ids)) {
            set_post_thumbnail($post_id, $attachment_ids[0]);
        }
    }

    // Notify admin
    gtemplate_notify_email_to_post($post_id, $sender_email, $post_title);

    gtemplate_track_error("[gTemplate Email-to-Post] Created draft post {$post_id} from {$sender_email}");

    return new WP_REST_Response([
        'success' => true,
        'post_id' => $post_id,
        'attachments' => count($attachment_ids),
        'edit_url' => admin_url("post.php?post={$post_id}&action=edit"),
    ], 201);
}

/**
 * Check if sender email is authorized
 *
 * @param string $sender_email
 * @param array $allowed_senders
 * @return bool
 */
function gtemplate_is_sender_authorized(string $sender_email, array $allowed_senders): bool
{
    if (empty($allowed_senders)) {
        return false;
    }

    $sender_email = strtolower(trim($sender_email));

    foreach ($allowed_senders as $allowed) {
        $allowed = strtolower(trim($allowed));

        // Exact match
        if ($allowed === $sender_email) {
            return true;
        }

        // Domain wildcard (e.g., *@company.com)
        if (strpos($allowed, '*@') === 0) {
            $domain = substr($allowed, 2);
            if (str_ends_with($sender_email, '@' . $domain)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Get WordPress user ID for email author
 *
 * @param string $sender_email
 * @return int User ID (defaults to admin if not found)
 */
function gtemplate_get_email_author(string $sender_email): int
{
    // Try to find user by email
    $user = get_user_by('email', $sender_email);
    if ($user) {
        return $user->ID;
    }

    // Fall back to configured default author
    $default_author = get_option('gtemplate_email_to_post_author', 0);
    if ($default_author > 0) {
        return $default_author;
    }

    // Fall back to first admin
    $admins = get_users(['role' => 'administrator', 'number' => 1]);
    return !empty($admins) ? $admins[0]->ID : 1;
}

/**
 * Sideload email attachment to media library
 *
 * @param int $post_id Post to attach media to
 * @param array $attachment Attachment data (filename, url, content_type)
 * @return int|null Attachment ID or null on failure
 */
/**
 * Commit 1.11.c: SSRF guard for the email-to-post
 * attachment fetcher. Returns false if the URL is not safe for
 * download_url():
 *   - scheme must be http or https (rejects file://, phar://, ftp://,
 *     gopher://, data://)
 *   - host must resolve to public IPs only (rejects RFC1918,
 *     link-local, loopback, IPv6 ULA via FILTER_FLAG_NO_PRIV_RANGE |
 *     FILTER_FLAG_NO_RES_RANGE)
 *
 * Mirrors gCube's gcube_email_attachment_url_is_safe (Commit 1.8.c)
 * and gNode-Client's OpenAPIImporter SSRF guard (Commit 1.12.b).
 * Kept as a per-package helper rather than a shared composer dep
 * because the three projects don't share a Composer namespace.
 */
function gtemplate_email_attachment_url_is_safe(string $url): bool
{
    $parts = parse_url($url);
    if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
        return false;
    }
    $scheme = strtolower($parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        return false;
    }

    $host = $parts['host'];
    $resolved = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $resolved = [$host];
    } else {
        $a = @dns_get_record($host, DNS_A);
        $aaaa = @dns_get_record($host, DNS_AAAA);
        foreach ((array) $a as $rec) {
            if (!empty($rec['ip'])) {
                $resolved[] = $rec['ip'];
            }
        }
        foreach ((array) $aaaa as $rec) {
            if (!empty($rec['ipv6'])) {
                $resolved[] = $rec['ipv6'];
            }
        }
        if (empty($resolved)) {
            return false;
        }
    }

    foreach ($resolved as $ip) {
        $ok = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6
                | FILTER_FLAG_NO_PRIV_RANGE
                | FILTER_FLAG_NO_RES_RANGE
        );
        if ($ok === false) {
            return false;
        }
    }

    return true;
}

function gtemplate_sideload_email_attachment(int $post_id, array $attachment): ?int
{
    if (empty($attachment['url']) || empty($attachment['filename'])) {
        return null;
    }

    // Only allow images
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!empty($attachment['content_type']) && !in_array($attachment['content_type'], $allowed_types)) {
        gtemplate_track_error("[gTemplate Email-to-Post] Skipping non-image attachment: " . preg_replace('/[\r\n\t]+/', ' ', (string) $attachment['filename']));
        return null;
    }

    // Commit 1.11.c: SSRF defence on attachment URL.
    // Pre-fix accepted any URL scheme + any private-IP destination;
    // 30 was a timeout, not a size cap. Mirrors the gCube CB-D2.07
    // pattern from Commit 1.8.c.
    $url = (string) $attachment['url'];
    if (!gtemplate_email_attachment_url_is_safe($url)) {
        gtemplate_track_error("[gTemplate Email-to-Post] Rejected attachment URL (SSRF guard): " . preg_replace('/[\r\n\t]+/', ' ', $url));
        return null;
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    // Download file to temp with explicit 20 MiB size cap (5th arg
    // is the size cap in bytes per WP 5.2+).
    $tmp_file = download_url($url, 30, false, false, 20 * 1024 * 1024);
    if (is_wp_error($tmp_file)) {
        gtemplate_track_error("[gTemplate Email-to-Post] Failed to download attachment: " . preg_replace('/[\r\n\t]+/', ' ', $tmp_file->get_error_message()));
        return null;
    }

    // Prepare file array for sideloading
    $file_array = [
        'name' => sanitize_file_name($attachment['filename']),
        'tmp_name' => $tmp_file,
    ];

    // Sideload into media library
    $attachment_id = media_handle_sideload($file_array, $post_id);

    // Clean up temp file on failure
    if (is_wp_error($attachment_id)) {
        @unlink($tmp_file);
        gtemplate_track_error("[gTemplate Email-to-Post] Failed to sideload attachment: " . $attachment_id->get_error_message());
        return null;
    }

    return $attachment_id;
}

/**
 * Notify admin of new email-to-post draft
 *
 * @param int $post_id
 * @param string $sender_email
 * @param string $post_title
 */
function gtemplate_notify_email_to_post(int $post_id, string $sender_email, string $post_title): void
{
    $notify = get_option('gtemplate_email_to_post_notify', true);
    if (!$notify) {
        return;
    }

    $admin_email = get_option('admin_email');
    $site_name = get_bloginfo('name');
    $edit_url = admin_url("post.php?post={$post_id}&action=edit");

    $subject = sprintf('[%s] New draft post from email', $site_name);

    $message = sprintf(
        "A new draft post was created from an email.\n\n" .
        "Title: %s\n" .
        "From: %s\n" .
        "Post ID: %d\n\n" .
        "Review and publish: %s\n",
        $post_title,
        $sender_email,
        $post_id,
        $edit_url
    );

    wp_mail($admin_email, $subject, $message);
}

/**
 * Get email-to-post status
 *
 * @return WP_REST_Response
 */
function gtemplate_email_to_post_status(): WP_REST_Response
{
    $webhook_secret = get_option('gtemplate_email_webhook_secret', '');
    $allowed_senders = get_option('gtemplate_email_to_post_senders', []);
    $default_author = get_option('gtemplate_email_to_post_author', 0);
    $default_category = get_option('gtemplate_email_to_post_category', 0);

    return new WP_REST_Response([
        'enabled' => !empty($webhook_secret),
        'webhook_configured' => !empty($webhook_secret),
        'allowed_senders_count' => count($allowed_senders),
        'default_author' => $default_author,
        'default_category' => $default_category,
        'endpoint' => rest_url('gtemplate/v1/email-to-post'),
    ]);
}

/**
 * Admin settings page for email-to-post.
 *
 * Hidden by default: the WordPress receiving half is complete and hardened, but
 * the inbound mail -> webhook gateway that would feed it does not exist yet, so
 * the feature cannot ingest email end-to-end. Deferred to Chapter 2. Flip the
 * 'gtemplate_email_to_post_enabled' filter to true once an inbound gateway ships.
 */
add_action('admin_menu', function() {
    if (!apply_filters('gtemplate_email_to_post_enabled', false)) {
        return;
    }
    add_submenu_page(
        'gcore-dashboard',
        'Email to Post',
        'Email to Post',
        'manage_options',
        'gtemplate-email-to-post',
        'gtemplate_email_to_post_settings_page'
    );
});

/**
 * Render email-to-post settings page
 */
function gtemplate_email_to_post_settings_page(): void
{
    // Handle form submission
    if (isset($_POST['gtemplate_email_settings_nonce']) && wp_verify_nonce($_POST['gtemplate_email_settings_nonce'], 'gtemplate_email_settings')) {
        // Generate new webhook secret if requested
        if (isset($_POST['generate_secret'])) {
            $new_secret = wp_generate_password(32, false);
            update_option('gtemplate_email_webhook_secret', $new_secret);
            echo '<div class="notice notice-success"><p>New webhook secret generated.</p></div>';
        }

        // Save allowed senders
        if (isset($_POST['allowed_senders'])) {
            $senders = array_filter(array_map('trim', explode("\n", sanitize_textarea_field($_POST['allowed_senders']))));
            update_option('gtemplate_email_to_post_senders', $senders);
        }

        // Save default author
        if (isset($_POST['default_author'])) {
            update_option('gtemplate_email_to_post_author', absint($_POST['default_author']));
        }

        // Save default category
        if (isset($_POST['default_category'])) {
            update_option('gtemplate_email_to_post_category', absint($_POST['default_category']));
        }

        // Save notification preference
        update_option('gtemplate_email_to_post_notify', isset($_POST['notify_admin']));

        echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
    }

    // Get current settings
    $webhook_secret = get_option('gtemplate_email_webhook_secret', '');
    $allowed_senders = get_option('gtemplate_email_to_post_senders', []);
    $default_author = get_option('gtemplate_email_to_post_author', 0);
    $default_category = get_option('gtemplate_email_to_post_category', 0);
    $notify_admin = get_option('gtemplate_email_to_post_notify', true);
    ?>
    <div class="wrap">
        <h1>Email to Post Settings</h1>
        <p>Configure email-to-post functionality. Authorized senders can email content that becomes draft posts.</p>

        <form method="post" action="">
            <?php wp_nonce_field('gtemplate_email_settings', 'gtemplate_email_settings_nonce'); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">Webhook Endpoint</th>
                    <td>
                        <code><?php echo esc_html(rest_url('gtemplate/v1/email-to-post')); ?></code>
                        <p class="description">Configure your email gateway to POST to this endpoint.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Webhook Secret</th>
                    <td>
                        <?php if ($webhook_secret): ?>
                            <code><?php echo esc_html(substr($webhook_secret, 0, 8) . '...' . substr($webhook_secret, -4)); ?></code>
                            <p class="description">Secret is configured. Send as <code>X-Webhook-Secret</code> header.</p>
                        <?php else: ?>
                            <span class="dashicons dashicons-warning" style="color: #d63638;"></span>
                            <em>No secret configured. Generate one below.</em>
                        <?php endif; ?>
                        <br><br>
                        <button type="submit" name="generate_secret" class="button">Generate New Secret</button>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="allowed_senders">Authorized Senders</label>
                    </th>
                    <td>
                        <textarea name="allowed_senders" id="allowed_senders" rows="5" cols="50" class="large-text"><?php
                            echo esc_textarea(implode("\n", $allowed_senders));
                        ?></textarea>
                        <p class="description">
                            One email per line. Use <code>*@domain.com</code> for domain wildcards.<br>
                            Only these addresses can create posts via email.
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="default_author">Default Author</label>
                    </th>
                    <td>
                        <?php
                        wp_dropdown_users([
                            'name' => 'default_author',
                            'id' => 'default_author',
                            'selected' => $default_author,
                            'show_option_none' => '— Auto-detect from email —',
                            'option_none_value' => 0,
                        ]);
                        ?>
                        <p class="description">Author for posts when sender email doesn't match a user.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="default_category">Default Category</label>
                    </th>
                    <td>
                        <?php
                        wp_dropdown_categories([
                            'name' => 'default_category',
                            'id' => 'default_category',
                            'selected' => $default_category,
                            'show_option_none' => '— Uncategorized —',
                            'option_none_value' => 0,
                            'hide_empty' => false,
                        ]);
                        ?>
                        <p class="description">Category to assign to email-created posts.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Notifications</th>
                    <td>
                        <label>
                            <input type="checkbox" name="notify_admin" value="1" <?php checked($notify_admin); ?>>
                            Email admin when a new draft is created
                        </label>
                    </td>
                </tr>
            </table>

            <?php submit_button('Save Settings'); ?>
        </form>

        <hr>

        <h2>Recent Email Posts</h2>
        <?php
        $recent_posts = get_posts([
            'post_type' => 'post',
            'post_status' => ['draft', 'publish'],
            'meta_key' => '_gtemplate_email_source',
            'posts_per_page' => 10,
        ]);

        if ($recent_posts):
        ?>
        <table class="widefat">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>From</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_posts as $post): ?>
                <tr>
                    <td><?php echo esc_html($post->post_title); ?></td>
                    <td><?php echo esc_html(get_post_meta($post->ID, '_gtemplate_email_source', true)); ?></td>
                    <td><?php echo esc_html(ucfirst($post->post_status)); ?></td>
                    <td><?php echo esc_html(get_post_meta($post->ID, '_gtemplate_email_received', true)); ?></td>
                    <td>
                        <a href="<?php echo esc_url(get_edit_post_link($post->ID)); ?>">Edit</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p><em>No posts have been created from emails yet.</em></p>
        <?php endif; ?>
    </div>
    <?php
}
