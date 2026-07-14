<?php
declare(strict_types=1);
/**
 * Per-Post Bundle Meta Box for gTemplate
 *
 * Adds a meta box to posts, pages, and products allowing per-content
 * bundle toggling, ValKey key display, and on-demand generation.
 *
 * @package gTemplate
 * @since 1.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register the bundle meta box on supported post types
 */
add_action('add_meta_boxes', function () {
    $post_types = ['post', 'page'];
    if (post_type_exists('product')) {
        $post_types[] = 'product';
    }

    foreach ($post_types as $type) {
        add_meta_box(
            'gtemplate_bundle',
            __('gTemplate Bundle', 'gtemplate'),
            'gtemplate_bundle_metabox_render',
            $type,
            'side',
            'default'
        );
    }
});

/**
 * Render the bundle meta box
 */
function gtemplate_bundle_metabox_render($post) {
    wp_nonce_field('gtemplate_bundle_save', 'gtemplate_bundle_nonce');

    $bundled = get_post_meta($post->ID, '_gtemplate_bundled', true);
    $trigger = get_post_meta($post->ID, '_gtemplate_bundle_trigger', true) ?: 'on_entry';
    $last_generated = get_post_meta($post->ID, '_gtemplate_bundle_generated_at', true);
    $bundle_size = get_post_meta($post->ID, '_gtemplate_bundle_size', true);

    $site_id = function_exists('gtemplate_get_site_id') ? gtemplate_get_site_id() : 'unknown';
    $valkey_key = "{{$site_id}}:bundle:post_{$post->ID}";
    ?>
    <div class="gtemplate-bundle-metabox">
        <p>
            <label>
                <input type="checkbox" name="gtemplate_bundled" value="1" <?php checked($bundled, '1'); ?>>
                <?php esc_html_e('Bundle this content', 'gtemplate'); ?>
            </label>
        </p>

        <p>
            <label for="gtemplate_bundle_trigger"><strong><?php esc_html_e('Delivery Trigger:', 'gtemplate'); ?></strong></label><br>
            <select name="gtemplate_bundle_trigger" id="gtemplate_bundle_trigger" style="width:100%">
                <option value="on_entry" <?php selected($trigger, 'on_entry'); ?>><?php esc_html_e('On Entry (preload)', 'gtemplate'); ?></option>
                <option value="on_click" <?php selected($trigger, 'on_click'); ?>><?php esc_html_e('On Click (lazy)', 'gtemplate'); ?></option>
                <option value="manual" <?php selected($trigger, 'manual'); ?>><?php esc_html_e('Manual (API only)', 'gtemplate'); ?></option>
            </select>
        </p>

        <p>
            <label><strong><?php esc_html_e('ValKey Key:', 'gtemplate'); ?></strong></label><br>
            <input type="text" value="<?php echo esc_attr($valkey_key); ?>" readonly style="width:100%;font-family:monospace;font-size:11px;" onclick="this.select()">
        </p>

        <?php if ($last_generated) : ?>
        <p class="description">
            <?php printf(
                esc_html__('Last generated: %s', 'gtemplate'),
                esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $last_generated))
            ); ?>
            <?php if ($bundle_size) : ?>
                <br><?php printf(esc_html__('Size: %s', 'gtemplate'), esc_html(size_format($bundle_size))); ?>
            <?php endif; ?>
        </p>
        <?php endif; ?>

        <p>
            <button type="button" class="button" id="gtemplate-generate-bundle" data-post-id="<?php echo esc_attr($post->ID); ?>">
                <?php esc_html_e('Generate Bundle Now', 'gtemplate'); ?>
            </button>
            <span id="gtemplate-bundle-status" style="display:none;margin-left:5px;"></span>
        </p>
    </div>

    <script>
    (function() {
        var btn = document.getElementById('gtemplate-generate-bundle');
        var status = document.getElementById('gtemplate-bundle-status');
        if (!btn) return;

        btn.addEventListener('click', function() {
            btn.disabled = true;
            btn.textContent = '<?php echo esc_js(__('Generating...', 'gtemplate')); ?>';
            status.style.display = 'inline';
            status.textContent = '';

            var formData = new FormData();
            formData.append('action', 'gtemplate_generate_bundle');
            formData.append('post_id', btn.dataset.postId);
            formData.append('nonce', '<?php echo wp_create_nonce('gtemplate_generate_bundle'); ?>');

            fetch(ajaxurl, { method: 'POST', body: formData })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    btn.disabled = false;
                    btn.textContent = '<?php echo esc_js(__('Generate Bundle Now', 'gtemplate')); ?>';
                    if (data.success) {
                        status.textContent = data.data.message || 'Done!';
                        status.style.color = '#00a32a';
                    } else {
                        status.textContent = data.data || 'Error';
                        status.style.color = '#d63638';
                    }
                })
                .catch(function() {
                    btn.disabled = false;
                    btn.textContent = '<?php echo esc_js(__('Generate Bundle Now', 'gtemplate')); ?>';
                    status.textContent = 'Request failed';
                    status.style.color = '#d63638';
                });
        });
    })();
    </script>
    <?php
}

/**
 * Save bundle meta box data
 */
add_action('save_post', function ($post_id) {
    if (!isset($_POST['gtemplate_bundle_nonce'])) {
        return;
    }
    if (!wp_verify_nonce($_POST['gtemplate_bundle_nonce'], 'gtemplate_bundle_save')) {
        return;
    }
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    $bundled = !empty($_POST['gtemplate_bundled']) ? '1' : '';
    update_post_meta($post_id, '_gtemplate_bundled', $bundled);

    $trigger = sanitize_text_field($_POST['gtemplate_bundle_trigger'] ?? 'on_entry');
    if (!in_array($trigger, ['on_entry', 'on_click', 'manual'], true)) {
        $trigger = 'on_entry';
    }
    update_post_meta($post_id, '_gtemplate_bundle_trigger', $trigger);

    // Auto-generate bundle on save if toggle is on and post is published
    if ($bundled && get_post_status($post_id) === 'publish') {
        gtemplate_generate_post_bundle($post_id);
    }
}, 20);

/**
 * AJAX handler for on-demand bundle generation
 */
add_action('wp_ajax_gtemplate_generate_bundle', function () {
    check_ajax_referer('gtemplate_generate_bundle', 'nonce');

    $post_id = intval($_POST['post_id'] ?? 0);
    if (!$post_id || !current_user_can('edit_post', $post_id)) {
        wp_send_json_error(__('Invalid post or insufficient permissions.', 'gtemplate'));
    }

    $result = gtemplate_generate_post_bundle($post_id);
    if ($result) {
        wp_send_json_success([
            'message' => sprintf(__('Bundle generated (%s)', 'gtemplate'), size_format($result['size'])),
            'key' => $result['key'],
            'size' => $result['size'],
            'timestamp' => $result['timestamp'],
        ]);
    } else {
        wp_send_json_error(__('Bundle generation failed. Check error log.', 'gtemplate'));
    }
});
