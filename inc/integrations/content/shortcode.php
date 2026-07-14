<?php
declare(strict_types=1);
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
        gtemplate_track_error('gTemplate shortcode template error: ' . $e->getMessage());
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
        gtemplate_track_error('gTemplate shortcode bundle error: ' . $e->getMessage());
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
 * Whether a REAL inference backend is available (not the inert base-tier
 * stub). Ch.1 resolves InferenceManager to the stub, whose isAvailable()
 * returns false, so the AI shortcodes below render nothing; the Chapter-2
 * Geodine-backed gcore-inference extension makes isAvailable() true and
 * lights them up. Gate on isAvailable() — NOT method_exists(): the stub
 * implements every method (method_exists is always true) but no-ops.
 */
function gtemplate_ai_available(): bool {
    global $gCore;

    if (!$gCore) {
        return false;
    }

    try {
        $inference = $gCore->getService('InferenceManager');
    } catch (\Throwable $e) {
        return false;
    }

    return $inference !== null
        && method_exists($inference, 'isAvailable')
        && $inference->isAvailable();
}

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
    // Ch.1 ships the inert InferenceManager stub — render nothing rather than
    // a dead/misleading fallback comment. Lights up under the Chapter-2 extension.
    if (!gtemplate_ai_available()) {
        return '';
    }

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
        gtemplate_track_error('gTemplate AI shortcode error: ' . $e->getMessage());
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
    // Inference-backed: stays dark on the Ch.1 stub (see gtemplate_ai_available).
    if (!gtemplate_ai_available()) {
        return '';
    }

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
    // Ch.1 ships the inert InferenceManager stub — do not render a functional-
    // looking chat widget whose /ai/chat endpoint can only ever error. Lights
    // up under the Chapter-2 Geodine-backed gcore-inference extension.
    if (!gtemplate_ai_available()) {
        return '';
    }

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

    // AI chat endpoint for HTMX.
    //
    // Commit 1.11.b: anonymous access preserved (chat
    // widget is for site visitors), but the handler now applies
    // a per-IP rate-limit via SecurityManager BEFORE calling
    // InferenceManager. Pre-fix `permission_callback => function() {
    // return true; }` documented the gap as a TODO that never
    // landed. Mirrors the gCube CB-D2.02 fix from Commit 1.8.b.
    register_rest_route(gtemplate_get_rest_namespace(), '/ai/chat', [
        'methods' => 'POST',
        'callback' => 'gtemplate_shortcode_rest_ai_chat',
        'permission_callback' => '__return_true',
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

    // Commit 1.11.b: per-IP rate limit before any LLM
    // work. 20 req/60s; 429 + HTMX-error fragment on exceedance.
    // Identifier comes from gtemplate_get_client_identifier (XFF-
    // safe via GTEMPLATE_TRUST_PROXY in 1.11.d).
    try {
        $security = $gCore->getService('Security');
        if ($security && method_exists($security, 'validateAPIRequest')) {
            $identifier = function_exists('gtemplate_get_client_identifier')
                ? gtemplate_get_client_identifier()
                : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
            $validation = $security->validateAPIRequest($request, [
                'rate_limit' => [
                    'limit' => 20,
                    'window' => 60,
                    'identifier' => 'ai_chat:' . $identifier,
                ],
            ]);
            if (!$validation['valid']) {
                return new WP_REST_Response(
                    '<div class="gtemplate-chat-error">Too many requests. Please wait a moment before trying again.</div>',
                    429,
                    ['Content-Type' => 'text/html; charset=utf-8']
                );
            }
        }
    } catch (\Throwable $e) {
        gtemplate_track_error('[gTemplate] /ai/chat rate-limit check failed: ' . preg_replace('/[\r\n\t]+/', ' ', $e->getMessage()));
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
        gtemplate_track_error('gTemplate AI chat error: ' . $e->getMessage());
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

// Commit 1.10.a: the duplicate gtemplate_init_inference_manager
// previously defined here was load-order-shadowed by the canonical
// definition in inc/integrations/managers/inference.php (the latter wins
// because integrations/index.php loads managers/ before content/, and
// the function_exists wrapper makes the second definition a no-op). The
// dead branch contained a hardcoded `OLLAMA_BASE_URL` fallback +
// referenced a non-existent inference-integration.php file. Removed
// entirely; canonical lives in managers/inference.php.

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

// =============================================================================
// PHI BUTTON — golden-ratio CTA shortcode
// =============================================================================

/**
 * Print the Phi-button CSS + JS exactly once per request. Vars are prefixed
 * --gb-* and scoped to .gbtn so they never clobber the theme's :root tokens.
 */
function gtemplate_gbtn_assets(): string {
    static $printed = false;
    if ($printed) {
        return '';
    }
    $printed = true;
    return <<<'HTML'
<style id="gbtn-css">
.gbtn{
  --gb-gold:#c9a961;--gb-gold-bright:#e8c468;--gb-gold-soft:#8d7944;--gb-good:#6fd388;
  --gb-bg-1:#111118;--gb-line:#2a2a35;
  --gb-text:#d4d4dc;--gb-s3:13px;--gb-s4:21px;--gb-s5:34px;--gb-t3:.944rem;--gb-t4:1rem;
  position:relative;display:inline-flex;align-items:center;justify-content:center;gap:.382em;
  font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;font-size:var(--gb-t4);line-height:1;
  padding:var(--gb-s3) var(--gb-s4);border:0;background:transparent;color:var(--gb-text);cursor:pointer;isolation:isolate;text-decoration:none;
  -webkit-tap-highlight-color:transparent;-webkit-user-select:none;user-select:none;
  transition:transform .35s cubic-bezier(.2,.8,.2,1),box-shadow .35s ease,color .35s ease,border-color .35s ease;}
.gbtn .gb-label{position:relative;z-index:2;display:inline-block;white-space:nowrap;}
.gbtn:focus-visible{outline:2px solid var(--gb-gold-bright);outline-offset:3px;}
.gb-lift{border:1px solid var(--gb-gold-soft);color:var(--gb-gold-bright);letter-spacing:.18em;text-transform:uppercase;font-size:var(--gb-t3);background:linear-gradient(180deg,rgba(201,169,97,.06),transparent);}
.gb-lift:hover{transform:translateY(-7px);border-color:var(--gb-gold-bright);box-shadow:0 18px 40px rgba(0,0,0,.55),0 0 40px rgba(201,169,97,.22);}
.gb-lift:active{transform:translateY(-2px);transition-duration:.09s;box-shadow:0 4px 12px rgba(0,0,0,.5),0 0 20px rgba(201,169,97,.18);}
.gb-sweep{border:1px solid var(--gb-line);background:var(--gb-bg-1);color:var(--gb-gold-bright);letter-spacing:.18em;text-transform:uppercase;font-size:var(--gb-t3);overflow:hidden;}
.gb-sweep::before{content:"";position:absolute;top:0;bottom:0;left:-160%;width:55%;z-index:0;background:linear-gradient(100deg,transparent,rgba(232,196,104,.4),transparent);transform:skewX(-20deg);transition:left .65s ease;}
.gb-sweep:hover{border-color:var(--gb-gold);box-shadow:0 0 34px rgba(201,169,97,.2);}
.gb-sweep:hover::before{left:160%;}
.gb-sweep:active{transform:scale(.98);}
.gb-facet{color:var(--gb-gold-bright);letter-spacing:.18em;text-transform:uppercase;font-size:var(--gb-t3);}
.gb-facet::before,.gb-facet::after{content:"";position:absolute;width:21px;height:21px;z-index:0;transition:width .45s cubic-bezier(.2,.8,.2,1),height .45s cubic-bezier(.2,.8,.2,1);}
.gb-facet::before{top:0;right:0;border-top:1px solid var(--gb-gold);border-right:1px solid var(--gb-gold);}
.gb-facet::after{bottom:0;left:0;border-bottom:1px solid var(--gb-gold);border-left:1px solid var(--gb-gold);}
.gb-facet:hover{color:#fff;}
.gb-facet:hover::before,.gb-facet:hover::after{width:100%;height:100%;}
.gb-facet:active{transform:scale(.96);}
.gbtn.is-success{background:var(--gb-bg-1)!important;color:var(--gb-good)!important;border-color:var(--gb-good)!important;box-shadow:0 0 var(--gb-s5) rgba(111,211,136,.32)!important;transform:none!important;text-shadow:none!important;pointer-events:none;}
.gbtn.is-success::before,.gbtn.is-success::after{opacity:0!important;}
.gbtn.is-success .gb-label{color:var(--gb-good)!important;transform:none!important;}
.gb-panel{margin-top:var(--gb-s3);}
</style>
<script>
(function(){
  if(window.__gbtnInit)return; window.__gbtnInit=true;
  document.addEventListener('click',function(e){
    var btn=e.target.closest('.gbtn'); if(!btn)return;
    var reveal=btn.getAttribute('data-reveal');
    if(reveal){var p=document.getElementById(reveal); if(p){p.hidden=!p.hidden; btn.setAttribute('aria-expanded',String(!p.hidden));} return;}
    if(btn.tagName==='A')return;            /* links navigate natively */
    var label=btn.querySelector('.gb-label');
    if(!label||btn.classList.contains('is-success'))return;
    var original=label.textContent||'';
    label.textContent=btn.getAttribute('data-success')||'Requested ✓';
    btn.classList.add('is-success');
    setTimeout(function(){btn.classList.remove('is-success');label.textContent=original;},1800);
  });
})();
</script>
HTML;
}

/**
 * Golden-ratio "Phi Lift" CTA button. Drop into any post/page content.
 *
 * Usage:
 *   [gbtn label="Request access" href="https://example.com/apply"]
 *   [gbtn label="Read the brief" page="42"]              (WP page id or slug)
 *   [gbtn label="Details"]<p>Custom HTML revealed on click</p>[/gbtn]
 *   [gbtn label="Notify me" success="Requested ✓"]       (no target → success toast)
 *
 * Attrs: label, href, page, success, variant (default "lift"), target, class
 */
function gtemplate_shortcode_gbtn($atts, $content = null) {
    $atts = shortcode_atts([
        'label'   => 'Request access',
        'href'    => '',
        'page'    => '',
        'success' => 'Requested ✓',
        'variant' => 'lift',
        'target'  => '_self',
        'class'   => '',
    ], $atts, 'gbtn');

    // Resolve the target: a WP page (id or slug) → permalink, else a raw URL.
    $url = '';
    if ($atts['page'] !== '') {
        $p = is_numeric($atts['page'])
            ? get_post((int) $atts['page'])
            : get_page_by_path(sanitize_title($atts['page']));
        if ($p) {
            $url = (string) get_permalink($p);
        }
    }
    if ($url === '' && $atts['href'] !== '') {
        $url = $atts['href'];
    }

    $variant = preg_match('/^[a-z0-9_-]+$/', (string) $atts['variant']) ? $atts['variant'] : 'lift';
    $classes = trim('gbtn gb-' . $variant . ($atts['class'] ? ' ' . $atts['class'] : ''));
    $label   = esc_html($atts['label']);
    $success = esc_attr($atts['success'] !== '' ? $atts['success'] : 'Requested ✓');
    $target  = in_array($atts['target'], ['_self', '_blank', '_parent', '_top'], true) ? $atts['target'] : '_self';

    // Enclosed custom HTML → revealed in a panel on click.
    $reveal = ($content !== null && trim($content) !== '') ? do_shortcode($content) : '';

    $out = gtemplate_gbtn_assets();

    if ($url !== '') {
        $out .= sprintf(
            '<a class="%s" href="%s" target="%s"%s data-success="%s"><span class="gb-label">%s</span></a>',
            esc_attr($classes), esc_url($url), esc_attr($target),
            ($target === '_blank' ? ' rel="noopener noreferrer"' : ''),
            $success, $label
        );
    } elseif ($reveal !== '') {
        $pid = 'gbtn-panel-' . (function_exists('wp_unique_id') ? wp_unique_id() : substr(md5($reveal . microtime()), 0, 8));
        $out .= sprintf(
            '<button type="button" class="%s" data-success="%s" data-reveal="%s" aria-expanded="false" aria-controls="%s"><span class="gb-label">%s</span></button><div id="%s" class="gb-panel" hidden>%s</div>',
            esc_attr($classes), $success, esc_attr($pid), esc_attr($pid), $label, esc_attr($pid), $reveal
        );
    } else {
        $out .= sprintf(
            '<button type="button" class="%s" data-success="%s"><span class="gb-label">%s</span></button>',
            esc_attr($classes), $success, $label
        );
    }
    return $out;
}
add_shortcode('gbtn', 'gtemplate_shortcode_gbtn');
add_shortcode('geo_button', 'gtemplate_shortcode_gbtn');

// =============================================================================
// [gform] — generic data-capturing form. Every submission lands in the
// per-form stream {site}:forms:<id> (see gtemplate_rest_submit_form).
// =============================================================================

/**
 * Print the [gform] CSS + JS exactly once per request. Scoped under .gform.
 */
function gtemplate_gform_assets(): string {
    static $printed = false;
    if ($printed) {
        return '';
    }
    $printed = true;
    $rest = esc_url_raw(rest_url(gtemplate_get_rest_namespace() . '/form/submit'));
    return <<<HTML
<style id="gform-css">
.gform{--gf-gold:#e8c468;--gf-soft:#8d7944;--gf-good:#6fd388;--gf-bad:#e07a7a;--gf-text:#d4d4dc;
  display:grid;gap:13px;max-width:560px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:var(--gf-text);}
.gform label{display:grid;gap:5px;font-size:.86rem;letter-spacing:.02em;}
.gform input,.gform textarea,.gform select{background:rgba(17,17,24,.6);border:1px solid var(--gf-soft);border-radius:6px;color:var(--gf-text);padding:10px 12px;font:inherit;width:100%;box-sizing:border-box;}
.gform input:focus,.gform textarea:focus{outline:2px solid var(--gf-gold);outline-offset:1px;border-color:var(--gf-gold);}
.gform textarea{min-height:120px;resize:vertical;}
.gform .gf-hp{position:absolute!important;left:-9999px!important;width:1px;height:1px;overflow:hidden;}
.gform .gf-consent{display:flex;align-items:flex-start;gap:8px;font-size:.82rem;}
.gform .gf-consent input{width:auto;margin-top:3px;}
.gform button{justify-self:start;border:1px solid var(--gf-soft);background:linear-gradient(180deg,rgba(201,169,97,.08),transparent);color:var(--gf-gold);
  letter-spacing:.16em;text-transform:uppercase;font-size:.86rem;padding:12px 26px;border-radius:6px;cursor:pointer;transition:transform .3s,box-shadow .3s,border-color .3s;}
.gform button:hover{transform:translateY(-3px);border-color:var(--gf-gold);box-shadow:0 12px 28px rgba(0,0,0,.45);}
.gform button[disabled]{opacity:.5;pointer-events:none;}
.gform .gf-msg{font-size:.9rem;min-height:1.2em;}
.gform .gf-msg.ok{color:var(--gf-good);}
.gform .gf-msg.err{color:var(--gf-bad);}
</style>
<script>
(function(){
  if(window.__gformInit)return; window.__gformInit=true;
  var EP="$rest";
  document.addEventListener('submit',function(e){
    var f=e.target; if(!f.classList||!f.classList.contains('gform'))return;
    e.preventDefault();
    if(f.dataset.busy)return; f.dataset.busy="1";
    var btn=f.querySelector('button'), msg=f.querySelector('.gf-msg');
    var jc=f.querySelector('input[name=_js_challenge]'); if(jc)jc.value='gcore_'+Date.now().toString(36);
    var data={}; new FormData(f).forEach(function(v,k){data[k]=v;});
    var c=f.querySelector('input[name=consent]'); data.consent=(c&&c.checked)?'1':'';
    if(btn)btn.disabled=true; if(msg){msg.textContent='';msg.className='gf-msg';}
    fetch(EP,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)})
      .then(function(r){return r.json();})
      .then(function(j){
        if(msg){msg.textContent=j.success?(f.dataset.success||j.message||'Thank you.'):(j.error||j.message||'Error');msg.className='gf-msg '+(j.success?'ok':'err');}
        if(j.success){f.reset();}else if(btn){btn.disabled=false;}
      })
      .catch(function(){if(msg){msg.textContent='Network error. Please try again.';msg.className='gf-msg err';}if(btn)btn.disabled=false;})
      .finally(function(){f.dataset.busy="";});
  });
})();
</script>
HTML;
}

/**
 * Generic data-capturing form. Every submission lands in {site}:forms:<id>.
 *
 * Usage:
 *   [gform id="signup" fields="name:text:Your name*,email:email:Email*,msg:textarea:Message"
 *          submit="Join" consent="I agree to be contacted" success="You're on the list."]
 *
 * fields = comma-separated  name:type:Label   (trailing * on the label = required)
 * types: text, email, tel, number, textarea, url
 *
 * deliver="email" also dispatches the submission to gNode-COMMS for email
 * (needs name/email/message fields); omit it for capture-only forms.
 */
function gtemplate_shortcode_gform($atts, $content = null) {
    $atts = shortcode_atts([
        'id'      => '',
        'fields'  => 'name:text:Name*,email:email:Email*,message:textarea:Message',
        'submit'  => 'Send',
        'consent' => 'I consent to my submission being stored and used to contact me.',
        'success' => '',
        'deliver' => '',
        'class'   => '',
    ], $atts, 'gform');

    $form_id = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $atts['id']));
    if ($form_id === '') { $form_id = 'default'; }

    $allowed = ['text', 'email', 'tel', 'number', 'textarea', 'url'];
    $rows = '';
    foreach (explode(',', (string) $atts['fields']) as $spec) {
        $parts = array_map('trim', explode(':', $spec));
        if (count($parts) < 2 || $parts[0] === '') { continue; }
        $name = sanitize_key($parts[0]);
        $type = in_array($parts[1], $allowed, true) ? $parts[1] : 'text';
        $label = $parts[2] ?? ucfirst($name);
        $required = '';
        if (substr($label, -1) === '*') { $required = ' required'; $label = rtrim(substr($label, 0, -1)); }
        $lbl = esc_html($label);
        $nm  = esc_attr($name);
        $rows .= ($type === 'textarea')
            ? "<label>{$lbl}<textarea name=\"{$nm}\"{$required}></textarea></label>"
            : "<label>{$lbl}<input type=\"" . esc_attr($type) . "\" name=\"{$nm}\"{$required}></label>";
    }

    $cls = trim('gform ' . sanitize_html_class((string) $atts['class']));
    $src = esc_url(home_url($_SERVER['REQUEST_URI'] ?? '/'));

    $html  = gtemplate_gform_assets();
    $html .= '<form class="' . esc_attr($cls) . '" novalidate' .
             ($atts['success'] !== '' ? ' data-success="' . esc_attr($atts['success']) . '"' : '') . '>';
    $html .= '<input type="hidden" name="form_id" value="' . esc_attr($form_id) . '">';
    if (strtolower((string) $atts['deliver']) === 'email') {
        $html .= '<input type="hidden" name="deliver" value="email">';
    }
    $html .= '<input type="hidden" name="_form_load_time" value="' . time() . '">';
    $html .= '<input type="hidden" name="_js_challenge" value="">';
    $html .= '<input type="hidden" name="source_url" value="' . $src . '">';
    $html .= $rows;
    $html .= '<span class="gf-hp" aria-hidden="true"><label>Website<input type="text" name="website_url" tabindex="-1" autocomplete="off"></label></span>';
    $html .= '<label class="gf-consent"><input type="checkbox" name="consent" value="1" required><span>' . esc_html($atts['consent']) . '</span></label>';
    $html .= '<button type="submit">' . esc_html($atts['submit']) . '</button>';
    $html .= '<div class="gf-msg" role="status" aria-live="polite"></div>';
    $html .= '</form>';
    return $html;
}
add_shortcode('gform', 'gtemplate_shortcode_gform');

