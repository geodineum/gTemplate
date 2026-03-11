<?php
/**
 * Shortcode Integration for gTemplate
 *
 * Provides WordPress shortcodes for:
 * - Tera templates via gNode
 * - Pre-rendered bundles
 * - HTMX lazy loading
 * - AI inference via InferenceManager
 *
 * @package gTemplate
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// =============================================================================
// TEMPLATE SHORTCODES
// =============================================================================

/**
 * Render a Tera template via gNode
 *
 * Usage:
 *   [gtemplate_template name="hero"]
 *   [gtemplate_template name="card" data='{"title":"Hello","image":"url"}']
 *   [gtemplate_template name="list" cache="3600"]
 *
 * @param array $atts Shortcode attributes
 * @return string Rendered HTML
 */
function gtemplate_shortcode_template($atts) {
    $atts = shortcode_atts([
        'name' => '',
        'data' => '{}',
        'cache' => 3600,
        'fallback' => '',
    ], $atts, 'gtemplate_template');

    if (empty($atts['name'])) {
        return '<!-- gtemplate_template: name required -->';
    }

    // Parse JSON data
    $data = json_decode($atts['data'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $data = [];
    }

    // Add standard context
    $data = array_merge([
        'site_id' => gtemplate_get_site_id(),
        'theme_url' => get_template_directory_uri(),
        'home_url' => home_url(),
        'blog_name' => get_bloginfo('name'),
    ], $data);

    try {
        $gNode = gtemplate_gnode_keybased();

        if ($gNode) {
            $html = $gNode->renderTemplate($atts['name'], $data);
            if ($html && !empty($html)) {
                return $html;
            }
        }
    } catch (\Throwable $e) {
        error_log('gTemplate shortcode template error: ' . $e->getMessage());
    }

    // Fallback
    return $atts['fallback'] ?: '<!-- gtemplate_template: render failed -->';
}
add_shortcode('gtemplate_template', 'gtemplate_shortcode_template');

/**
 * Render a cube face
 *
 * Usage:
 *   [gtemplate_face id="1"]
 *   [gtemplate_face id="front"]
 *   [gtemplate_face id="2" class="custom-class"]
 *
 * @param array $atts Shortcode attributes
 * @return string Rendered face HTML
 */
function gtemplate_shortcode_face($atts) {
    $atts = shortcode_atts([
        'id' => '0',
        'class' => '',
        'wrapper' => true,
    ], $atts, 'gtemplate_face');

    // Map name to ID if needed
    $face_map = [
        'top' => 0, 'front' => 1, 'right' => 2,
        'back' => 3, 'left' => 4, 'bottom' => 5
    ];

    $face_id = is_numeric($atts['id'])
        ? (int) $atts['id']
        : ($face_map[strtolower($atts['id'])] ?? 0);

    $position = array_search($face_id, $face_map) ?: 'unknown';

    $html = gtemplate_render_face($face_id, ['position' => $position]);

    if ($atts['wrapper']) {
        $class = 'gtemplate-face-shortcode' . ($atts['class'] ? ' ' . esc_attr($atts['class']) : '');
        $html = sprintf('<div class="%s" data-face-id="%d">%s</div>', $class, $face_id, $html);
    }

    return $html;
}
add_shortcode('gtemplate_face', 'gtemplate_shortcode_face');

// =============================================================================
// BUNDLE SHORTCODES
// =============================================================================

/**
 * Render pre-rendered bundle content
 *
 * Usage:
 *   [gtemplate_bundle]                    -- Full 6-face bundle
 *   [gtemplate_bundle type="navigation"]  -- Navigation bundle
 *   [gtemplate_bundle face="1"]           -- Single face from bundle
 *
 * @param array $atts Shortcode attributes
 * @return string Bundle HTML
 */
function gtemplate_shortcode_bundle($atts) {
    $atts = shortcode_atts([
        'type' => 'full',
        'face' => null,
        'fallback' => 'php',
    ], $atts, 'gtemplate_bundle');

    // Single face from bundle
    if ($atts['face'] !== null) {
        $face_id = (int) $atts['face'];
        $html = gtemplate_get_face_from_bundle($face_id);

        if ($html) {
            return $html;
        }

        // Fallback to render
        if ($atts['fallback'] === 'php') {
            return gtemplate_render_face($face_id, []);
        }

        return '<!-- gtemplate_bundle: face not found -->';
    }

    // Full bundle
    try {
        $gNode = gtemplate_gnode_keybased();
        $site_id = gtemplate_get_site_id();

        if ($gNode) {
            $bundle_key = "{$site_id}:gnode:bundle:{$atts['type']}";
            $bundle = $gNode->get($bundle_key);

            if ($bundle) {
                $data = is_string($bundle) ? json_decode($bundle, true) : $bundle;
                return $data['html'] ?? $data['content'] ?? '';
            }
        }
    } catch (\Throwable $e) {
        error_log('gTemplate shortcode bundle error: ' . $e->getMessage());
    }

    return '<!-- gtemplate_bundle: not available -->';
}
add_shortcode('gtemplate_bundle', 'gtemplate_shortcode_bundle');

// =============================================================================
// HTMX SHORTCODES
// =============================================================================

/**
 * Create HTMX lazy-loading element
 *
 * Usage:
 *   [gtemplate_htmx endpoint="/wp-json/gtemplate/v1/face/1"]
 *   [gtemplate_htmx endpoint="/wp-json/gtemplate/v1/template/hero" trigger="revealed"]
 *   [gtemplate_htmx endpoint="/api/data" trigger="click" swap="innerHTML"]
 *
 * @param array $atts Shortcode attributes
 * @param string $content Inner content (loading placeholder)
 * @return string HTMX element
 */
function gtemplate_shortcode_htmx($atts, $content = null) {
    $atts = shortcode_atts([
        'endpoint' => '',
        'trigger' => 'revealed',
        'swap' => 'innerHTML',
        'target' => 'this',
        'indicator' => '',
        'class' => '',
        'tag' => 'div',
        'method' => 'get',
    ], $atts, 'gtemplate_htmx');

    if (empty($atts['endpoint'])) {
        return '<!-- gtemplate_htmx: endpoint required -->';
    }

    // Build HTMX attributes
    $hx_attrs = [
        sprintf('hx-%s="%s"', strtolower($atts['method']), esc_url($atts['endpoint'])),
        sprintf('hx-trigger="%s"', esc_attr($atts['trigger'])),
        sprintf('hx-swap="%s"', esc_attr($atts['swap'])),
    ];

    if ($atts['target'] !== 'this') {
        $hx_attrs[] = sprintf('hx-target="%s"', esc_attr($atts['target']));
    }

    if ($atts['indicator']) {
        $hx_attrs[] = sprintf('hx-indicator="%s"', esc_attr($atts['indicator']));
    }

    $class = 'gtemplate-htmx' . ($atts['class'] ? ' ' . esc_attr($atts['class']) : '');
    $tag = in_array($atts['tag'], ['div', 'span', 'section', 'article']) ? $atts['tag'] : 'div';

    // Default loading content
    $placeholder = $content ?: '<div class="gtemplate-htmx-loading">Loading...</div>';

    return sprintf(
        '<%s class="%s" %s>%s</%s>',
        $tag,
        $class,
        implode(' ', $hx_attrs),
        $placeholder,
        $tag
    );
}
add_shortcode('gtemplate_htmx', 'gtemplate_shortcode_htmx');

/**
 * Lazy-load a cube face via HTMX
 *
 * Usage:
 *   [gtemplate_lazy_face id="1"]
 *   [gtemplate_lazy_face id="back" placeholder="Loading back face..."]
 *
 * @param array $atts Shortcode attributes
 * @return string HTMX lazy-loading element
 */
function gtemplate_shortcode_lazy_face($atts) {
    $atts = shortcode_atts([
        'id' => '0',
        'placeholder' => '',
        'trigger' => 'revealed',
    ], $atts, 'gtemplate_lazy_face');

    // Map name to ID
    $face_map = ['top' => 0, 'front' => 1, 'right' => 2, 'back' => 3, 'left' => 4, 'bottom' => 5];
    $face_id = is_numeric($atts['id']) ? (int) $atts['id'] : ($face_map[strtolower($atts['id'])] ?? 0);

    $endpoint = rest_url(gtemplate_get_rest_namespace() . "/face/{$face_id}");
    $placeholder = $atts['placeholder'] ?: sprintf(
        '<div class="gtemplate-face-placeholder" data-face="%d">Loading face %d...</div>',
        $face_id, $face_id
    );

    return gtemplate_shortcode_htmx([
        'endpoint' => $endpoint,
        'trigger' => $atts['trigger'],
        'class' => 'gtemplate-lazy-face',
    ], $placeholder);
}
add_shortcode('gtemplate_lazy_face', 'gtemplate_shortcode_lazy_face');

// =============================================================================
// AI/INFERENCE SHORTCODES
// =============================================================================

/**
 * Generate AI text via InferenceManager
 *
 * Usage:
 *   [gtemplate_ai prompt="Write a tagline for a tech company"]
 *   [gtemplate_ai prompt="Explain quantum computing" model="llama3" max_tokens="500"]
 *   [gtemplate_ai prompt="..." cache="86400"]
 *
 * @param array $atts Shortcode attributes
 * @return string Generated text
 */
function gtemplate_shortcode_ai($atts) {
    $atts = shortcode_atts([
        'prompt' => '',
        'model' => 'llama3',
        'max_tokens' => 1024,
        'temperature' => 0.7,
        'cache' => 3600,
        'fallback' => '',
        'wrapper' => true,
        'class' => '',
    ], $atts, 'gtemplate_ai');

    if (empty($atts['prompt'])) {
        return '<!-- gtemplate_ai: prompt required -->';
    }

    try {
        global $gCore;

        if (!$gCore) {
            return $atts['fallback'] ?: '<!-- gtemplate_ai: gCore not available -->';
        }

        $inference = $gCore->getService('InferenceManager');

        if (!$inference || !$inference->isInitialized()) {
            return $atts['fallback'] ?: '<!-- gtemplate_ai: InferenceManager not available -->';
        }

        $result = $inference->generateText($atts['prompt'], $atts['model'], [
            'max_tokens' => (int) $atts['max_tokens'],
            'temperature' => (float) $atts['temperature'],
        ]);

        if ($result['success']) {
            $content = esc_html($result['result']);

            if ($atts['wrapper']) {
                $class = 'gtemplate-ai-content' . ($atts['class'] ? ' ' . esc_attr($atts['class']) : '');
                $cached = $result['cached'] ? ' data-cached="true"' : '';
                return sprintf('<div class="%s"%s>%s</div>', $class, $cached, nl2br($content));
            }

            return $content;
        }

        return $atts['fallback'] ?: '<!-- gtemplate_ai: generation failed -->';

    } catch (\Throwable $e) {
        error_log('gTemplate AI shortcode error: ' . $e->getMessage());
        return $atts['fallback'] ?: '<!-- gtemplate_ai: error -->';
    }
}
add_shortcode('gtemplate_ai', 'gtemplate_shortcode_ai');

/**
 * AI-powered content summary
 *
 * Usage:
 *   [gtemplate_ai_summary]Long content to summarize...[/gtemplate_ai_summary]
 *   [gtemplate_ai_summary length="short" model="llama3"]...[/gtemplate_ai_summary]
 *
 * @param array $atts Shortcode attributes
 * @param string $content Content to summarize
 * @return string Summary
 */
function gtemplate_shortcode_ai_summary($atts, $content = null) {
    if (empty($content)) {
        return '';
    }

    $atts = shortcode_atts([
        'length' => 'medium',  // short, medium, long
        'model' => 'llama3',
        'cache' => 86400,
        'fallback' => '',
    ], $atts, 'gtemplate_ai_summary');

    $length_prompts = [
        'short' => 'Summarize in 1-2 sentences',
        'medium' => 'Summarize in a short paragraph',
        'long' => 'Provide a detailed summary',
    ];

    $instruction = $length_prompts[$atts['length']] ?? $length_prompts['medium'];
    $prompt = "{$instruction}:\n\n" . strip_tags($content);

    return gtemplate_shortcode_ai([
        'prompt' => $prompt,
        'model' => $atts['model'],
        'cache' => $atts['cache'],
        'fallback' => $atts['fallback'],
        'class' => 'gtemplate-ai-summary',
    ]);
}
add_shortcode('gtemplate_ai_summary', 'gtemplate_shortcode_ai_summary');

/**
 * AI chat interface (requires JavaScript)
 *
 * Usage:
 *   [gtemplate_ai_chat]
 *   [gtemplate_ai_chat model="llama3" placeholder="Ask me anything..."]
 *
 * @param array $atts Shortcode attributes
 * @return string Chat interface HTML
 */
function gtemplate_shortcode_ai_chat($atts) {
    $atts = shortcode_atts([
        'model' => 'llama3',
        'placeholder' => 'Type your message...',
        'system_prompt' => 'You are a helpful assistant.',
        'class' => '',
    ], $atts, 'gtemplate_ai_chat');

    $chat_id = 'gtemplate-chat-' . wp_generate_uuid4();
    $class = 'gtemplate-ai-chat' . ($atts['class'] ? ' ' . esc_attr($atts['class']) : '');

    ob_start();
    ?>
    <div id="<?php echo esc_attr($chat_id); ?>" class="<?php echo $class; ?>"
         data-model="<?php echo esc_attr($atts['model']); ?>"
         data-system-prompt="<?php echo esc_attr($atts['system_prompt']); ?>">
        <div class="gtemplate-chat-messages"></div>
        <form class="gtemplate-chat-form" hx-post="<?php echo esc_url(rest_url(gtemplate_get_rest_namespace() . '/ai/chat')); ?>"
              hx-target="#<?php echo esc_attr($chat_id); ?> .gtemplate-chat-messages"
              hx-swap="beforeend"
              hx-indicator="#<?php echo esc_attr($chat_id); ?> .gtemplate-chat-indicator">
            <input type="hidden" name="model" value="<?php echo esc_attr($atts['model']); ?>">
            <input type="hidden" name="conversation_id" value="">
            <input type="text" name="message" class="gtemplate-chat-input"
                   placeholder="<?php echo esc_attr($atts['placeholder']); ?>" required>
            <button type="submit" class="gtemplate-chat-submit">Send</button>
            <span class="gtemplate-chat-indicator htmx-indicator">...</span>
        </form>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('gtemplate_ai_chat', 'gtemplate_shortcode_ai_chat');

// =============================================================================
// REST API ENDPOINTS FOR SHORTCODES
// =============================================================================

add_action('rest_api_init', function() {
    // Template endpoint for HTMX (face endpoint already exists in rest-endpoints.php)
    register_rest_route(gtemplate_get_rest_namespace(), '/template/(?P<name>[a-z0-9_-]+)', [
        'methods' => 'GET',
        'callback' => 'gtemplate_shortcode_rest_template',
        'permission_callback' => '__return_true',
        'args' => [
            'name' => [
                'type' => 'string',
                'required' => true,
            ],
            'data' => [
                'type' => 'string',
                'default' => '{}',
            ]
        ]
    ]);

    // AI chat endpoint for HTMX
    register_rest_route(gtemplate_get_rest_namespace(), '/ai/chat', [
        'methods' => 'POST',
        'callback' => 'gtemplate_shortcode_rest_ai_chat',
        'permission_callback' => function() {
            // Allow authenticated users or apply rate limiting for anonymous
            return true;
        },
        'args' => [
            'message' => [
                'type' => 'string',
                'required' => true,
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'model' => [
                'type' => 'string',
                'default' => 'llama3',
            ],
            'conversation_id' => [
                'type' => 'string',
                'default' => '',
            ]
        ]
    ]);

    // AI generate endpoint
    register_rest_route(gtemplate_get_rest_namespace(), '/ai/generate', [
        'methods' => 'POST',
        'callback' => 'gtemplate_shortcode_rest_ai_generate',
        'permission_callback' => function() {
            return current_user_can('edit_posts');
        },
        'args' => [
            'prompt' => [
                'type' => 'string',
                'required' => true,
            ],
            'model' => [
                'type' => 'string',
                'default' => 'llama3',
            ]
        ]
    ]);
});

/**
 * REST callback: Get rendered template (for HTMX shortcodes)
 */
function gtemplate_shortcode_rest_template($request) {
    $name = $request->get_param('name');
    $data_json = $request->get_param('data');

    $data = json_decode($data_json, true) ?: [];

    $html = gtemplate_shortcode_template([
        'name' => $name,
        'data' => json_encode($data),
    ]);

    return new WP_REST_Response($html, 200, [
        'Content-Type' => 'text/html; charset=utf-8'
    ]);
}

/**
 * REST callback: AI chat message
 */
function gtemplate_shortcode_rest_ai_chat($request) {
    global $gCore;

    $message = $request->get_param('message');
    $model = $request->get_param('model');
    $conversation_id = $request->get_param('conversation_id');

    if (!$gCore) {
        return new WP_REST_Response(
            '<div class="gtemplate-chat-error">AI service unavailable</div>',
            200,
            ['Content-Type' => 'text/html; charset=utf-8']
        );
    }

    try {
        $inference = $gCore->getService('InferenceManager');

        if (!$inference || !$inference->isInitialized()) {
            return new WP_REST_Response(
                '<div class="gtemplate-chat-error">AI service not initialized</div>',
                200,
                ['Content-Type' => 'text/html; charset=utf-8']
            );
        }

        $messages = [
            ['role' => 'user', 'content' => $message]
        ];

        $options = [];
        if ($conversation_id) {
            $options['conversation_id'] = $conversation_id;
        }

        $result = $inference->chat($messages, $model, $options);

        if ($result['success']) {
            $response_html = sprintf(
                '<div class="gtemplate-chat-message gtemplate-chat-user">%s</div>' .
                '<div class="gtemplate-chat-message gtemplate-chat-assistant">%s</div>',
                esc_html($message),
                nl2br(esc_html($result['result']))
            );

            return new WP_REST_Response($response_html, 200, [
                'Content-Type' => 'text/html; charset=utf-8',
                'X-Conversation-Id' => $result['conversation_id'] ?? '',
            ]);
        }

        return new WP_REST_Response(
            '<div class="gtemplate-chat-error">Failed to get response</div>',
            200,
            ['Content-Type' => 'text/html; charset=utf-8']
        );

    } catch (\Throwable $e) {
        error_log('gTemplate AI chat error: ' . $e->getMessage());
        return new WP_REST_Response(
            '<div class="gtemplate-chat-error">Error processing request</div>',
            200,
            ['Content-Type' => 'text/html; charset=utf-8']
        );
    }
}

/**
 * REST callback: AI generate (for editor use)
 */
function gtemplate_shortcode_rest_ai_generate($request) {
    global $gCore;

    $prompt = $request->get_param('prompt');
    $model = $request->get_param('model');

    if (!$gCore) {
        return new WP_REST_Response(['error' => 'gCore not available'], 500);
    }

    try {
        $inference = $gCore->getService('InferenceManager');

        if (!$inference || !$inference->isInitialized()) {
            return new WP_REST_Response(['error' => 'InferenceManager not available'], 500);
        }

        $result = $inference->generateText($prompt, $model);

        return new WP_REST_Response([
            'success' => $result['success'],
            'text' => $result['result'] ?? null,
            'cached' => $result['cached'] ?? false,
            'metrics' => $result['metrics'] ?? [],
            'error' => $result['error'] ?? null,
        ], $result['success'] ? 200 : 500);

    } catch (\Throwable $e) {
        return new WP_REST_Response(['error' => $e->getMessage()], 500);
    }
}

// =============================================================================
// INITIALIZATION
// =============================================================================

/**
 * Initialize InferenceManager with gNode client
 * NOTE: This function is now defined in inference-integration.php
 * Keeping this as a fallback for backward compatibility only
 */
if (!function_exists('gtemplate_init_inference_manager')) {
    function gtemplate_init_inference_manager() {
        global $gCore;

        if (!$gCore) {
            return;
        }

        try {
            // Get InferenceManager via gCore resolver (returns stub or premium automatically)
            $inference = $gCore->getService('InferenceManager');

            if (!$inference->isInitialized()) {
                $gnode_client = $GLOBALS['gtemplate_gnode_client'] ?? null;

                $inference->initialize([
                    'site_id' => gtemplate_get_site_id(),
                    'node_id' => 'web-' . gethostname(),
                    'use_gnode' => $gnode_client !== null,
                    'gnode_client' => $gnode_client,
                    'ollama_base_url' => defined('OLLAMA_BASE_URL')
                        ? OLLAMA_BASE_URL
                        : 'http://localhost:11434/api',
                    'cache_enabled' => true,
                    'debug' => defined('WP_DEBUG') && WP_DEBUG,
                ]);

                error_log('[gTemplate] InferenceManager initialized for shortcodes');
            }

        } catch (\Throwable $e) {
            error_log('[gTemplate] InferenceManager init failed: ' . $e->getMessage());
        }
    }
}

// Initialize on wp_loaded (after gCore is ready)
// Note: InferenceManager requires Ollama running. Disabled by default.
// Uncomment below line to enable: add_action('wp_loaded', 'gtemplate_init_inference_manager', 20);

// =============================================================================
// CSS FOR SHORTCODE ELEMENTS
// =============================================================================

/**
 * Enqueue minimal CSS for shortcode elements
 */
function gtemplate_shortcode_styles() {
    $css = '
    .gtemplate-htmx-loading { opacity: 0.6; }
    .gtemplate-ai-content { padding: 1em; background: #f9f9f9; border-radius: 4px; }
    .gtemplate-ai-summary { font-style: italic; }
    .gtemplate-ai-chat { border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
    .gtemplate-chat-messages { max-height: 400px; overflow-y: auto; padding: 1em; }
    .gtemplate-chat-message { padding: 0.5em 1em; margin: 0.5em 0; border-radius: 8px; }
    .gtemplate-chat-user { background: #007bff; color: white; margin-left: 20%; }
    .gtemplate-chat-assistant { background: #f1f1f1; margin-right: 20%; }
    .gtemplate-chat-form { display: flex; padding: 0.5em; border-top: 1px solid #ddd; }
    .gtemplate-chat-input { flex: 1; padding: 0.5em; border: 1px solid #ddd; border-radius: 4px; }
    .gtemplate-chat-submit { padding: 0.5em 1em; margin-left: 0.5em; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; }
    .gtemplate-chat-indicator { display: none; }
    .htmx-request .gtemplate-chat-indicator { display: inline; }
    .gtemplate-chat-error { color: #dc3545; padding: 0.5em; }
    ';

    wp_add_inline_style('gtemplate-cube-css', $css);
}
add_action('wp_enqueue_scripts', 'gtemplate_shortcode_styles', 20);

error_log('[gTemplate shortcode-integration.php] Loaded');
