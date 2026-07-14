<?php
declare(strict_types=1);
/**
 * FormatManager Integration for gTemplate
 *
 * Integrates gCore FormatManager with gNode's format system for:
 * - Face configuration schema validation (JSONSchema Draft-07)
 * - REST API request validation
 * - Format detection and conversion
 *
 * gNode Format Commands Used:
 * - register_format: Register custom schemas at theme init
 * - detect_format: Auto-detect incoming message format
 * - convert_format: Transform between formats (future: mobile optimization)
 *
 * @package gTemplate
 * @version 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Initialize FormatManager and register gTemplate schemas
 *
 * Called during theme initialization after gCore and gNode are available.
 *
 * @return \gCore\Modules\Managers\Base\FormatManager\FormatManager|null
 */
function gtemplate_init_format_manager() {
    global $gCore;

    if (!$gCore) {
        return null;
    }

    try {
        $format = $gCore->getService('FormatManager');
        if (!$format) {
            gtemplate_track_error('gTemplate: FormatManager not available from gCore');
            return null;
        }

        // Check if already initialized
        if ($format->isInitialized()) {
            return $format;
        }

        // Get gNode client for format operations
        $gNode = gtemplate_gnode();

        $format->initialize([
            'use_gnode' => ($gNode !== null),
            'auto_detect' => true,
            'validation_mode' => 'strict',
            'cache_formats' => true
        ], [
            'site_id' => gtemplate_get_site_id(),
            'node_id' => 'web-' . gethostname()
        ], $gNode);

        // Register gTemplate-specific formats
        if ($gNode) {
            gtemplate_register_formats($format);
        }

        gtemplate_track_error('gTemplate: FormatManager initialized successfully');
        return $format;

    } catch (\Throwable $e) {
        gtemplate_track_error('gTemplate: FormatManager init error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Register gTemplate-specific format schemas with gNode
 *
 * @param \gCore\Modules\Managers\Base\FormatManager\FormatManager $format
 * @return void
 */
function gtemplate_register_formats($format): void {
    try {
        // Register face configuration format
        $format->registerFormat(
            'gtemplate_face_config',
            gtemplate_get_face_config_schema(),
            gtemplate_get_face_config_patterns(),
            [
                'version' => '1.0.0',
                'description' => 'gTemplate cube face configuration format',
                'content_type' => 'application/json'
            ]
        );
        gtemplate_track_error('gTemplate: Registered gtemplate_face_config format');

        // Register REST API request format
        $format->registerFormat(
            'gtemplate_rest_request',
            gtemplate_get_rest_request_schema(),
            gtemplate_get_rest_request_patterns(),
            [
                'version' => '1.0.0',
                'description' => 'gTemplate REST API request format',
                'content_type' => 'application/json'
            ]
        );
        gtemplate_track_error('gTemplate: Registered gtemplate_rest_request format');

        // Register render request format
        $format->registerFormat(
            'gtemplate_render_request',
            gtemplate_get_render_request_schema(),
            [],
            [
                'version' => '1.0.0',
                'description' => 'gTemplate template render request format',
                'content_type' => 'application/json'
            ]
        );
        gtemplate_track_error('gTemplate: Registered gtemplate_render_request format');

    } catch (\Throwable $e) {
        gtemplate_track_error('gTemplate: Format registration error: ' . $e->getMessage());
    }
}

/**
 * Get JSONSchema for face configuration
 *
 * Validates face configs from Customizer before processing.
 * Schema follows JSONSchema Draft-07 specification.
 *
 * @return array JSONSchema definition
 */
function gtemplate_get_face_config_schema(): array {
    return [
        '$schema' => 'http://json-schema.org/draft-07/schema#',
        'title' => 'gTemplate Face Configuration',
        'description' => 'Configuration for a single cube face',
        'type' => 'object',
        'required' => ['face_id', 'source'],
        'properties' => [
            'face_id' => [
                'type' => 'integer',
                'minimum' => 0,
                'maximum' => 5,
                'description' => 'Face index (0=top, 1=front, 2=right, 3=back, 4=left, 5=bottom)'
            ],
            'customizer_num' => [
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => 6,
                'description' => 'WordPress Customizer face number (1-6)'
            ],
            'source' => [
                'type' => 'string',
                'enum' => ['demo', 'page', 'post', 'custom'],
                'description' => 'Content source type'
            ],
            'content_id' => [
                'type' => 'integer',
                'minimum' => 0,
                'description' => 'WordPress post/page ID (0 = none selected)'
            ],
            'custom_html' => [
                'type' => 'string',
                'maxLength' => 65535,
                'description' => 'Custom HTML content (when source=custom)'
            ],
            'nav_label' => [
                'type' => 'string',
                'minLength' => 1,
                'maxLength' => 50,
                'description' => 'Navigation button label'
            ],
            'title_override' => [
                'type' => 'string',
                'maxLength' => 200,
                'description' => 'Optional title override'
            ],
            'show_title' => [
                'type' => 'boolean',
                'default' => true,
                'description' => 'Whether to display the title'
            ],
            'position' => [
                'type' => 'string',
                'enum' => ['top', 'front', 'right', 'back', 'left', 'bottom'],
                'description' => 'Face position name'
            ],
            'css_class' => [
                'type' => 'string',
                'enum' => ['one', 'two', 'three', 'four', 'five', 'six'],
                'description' => 'CSS class for face element'
            ],
            'content_html' => [
                'type' => 'string',
                'description' => 'Pre-rendered HTML content'
            ],
            'content_data' => [
                'type' => ['object', 'null'],
                'description' => 'WordPress post/page data object'
            ]
        ],
        'additionalProperties' => false
    ];
}

/**
 * Get detection patterns for face config format
 *
 * @return array Pattern definitions
 */
function gtemplate_get_face_config_patterns(): array {
    return [
        [
            'pattern_type' => 'regex',
            'pattern' => '^\s*\{\s*"face_id"\s*:',
            'confidence' => 0.9
        ],
        [
            'pattern_type' => 'regex',
            'pattern' => '"source"\s*:\s*"(demo|page|post|custom)"',
            'confidence' => 0.85
        ]
    ];
}

/**
 * Get JSONSchema for REST API requests
 *
 * @return array JSONSchema definition
 */
function gtemplate_get_rest_request_schema(): array {
    return [
        '$schema' => 'http://json-schema.org/draft-07/schema#',
        'title' => 'gTemplate REST API Request',
        'description' => 'Generic REST API request validation',
        'type' => 'object',
        'properties' => [
            'page_id' => [
                'type' => 'integer',
                'minimum' => 1,
                'description' => 'WordPress page ID'
            ],
            'face_id' => [
                'type' => 'integer',
                'minimum' => 0,
                'maximum' => 5,
                'description' => 'Cube face ID'
            ],
            'template' => [
                'type' => 'string',
                'minLength' => 1,
                'maxLength' => 100,
                'pattern' => '^[a-zA-Z0-9_-]+$',
                'description' => 'Template identifier'
            ],
            'data' => [
                'type' => 'object',
                'description' => 'Additional request data'
            ]
        ],
        'additionalProperties' => true
    ];
}

/**
 * Get detection patterns for REST request format
 *
 * @return array Pattern definitions
 */
function gtemplate_get_rest_request_patterns(): array {
    return [
        [
            'pattern_type' => 'regex',
            'pattern' => '"(page_id|face_id|template)"\s*:',
            'confidence' => 0.8
        ]
    ];
}

/**
 * Get JSONSchema for render requests
 *
 * @return array JSONSchema definition
 */
function gtemplate_get_render_request_schema(): array {
    return [
        '$schema' => 'http://json-schema.org/draft-07/schema#',
        'title' => 'gTemplate Render Request',
        'description' => 'Template render request validation',
        'type' => 'object',
        'required' => ['template', 'face_id'],
        'properties' => [
            'template' => [
                'type' => 'string',
                'minLength' => 1,
                'maxLength' => 100,
                'pattern' => '^[a-zA-Z0-9_-]+$',
                'description' => 'Template ID to render'
            ],
            'face_id' => [
                'type' => 'integer',
                'minimum' => 0,
                'maximum' => 5,
                'description' => 'Target cube face ID'
            ],
            'data' => [
                'type' => 'object',
                'properties' => [
                    'site_id' => ['type' => 'string'],
                    'theme_url' => ['type' => 'string', 'format' => 'uri'],
                    'home_url' => ['type' => 'string', 'format' => 'uri'],
                    'blog_name' => ['type' => 'string'],
                    'blog_description' => ['type' => 'string'],
                    'content' => ['type' => 'string']
                ],
                'additionalProperties' => true,
                'description' => 'Template variables'
            ]
        ],
        'additionalProperties' => false
    ];
}

/**
 * Get the FormatManager instance
 *
 * @return \gCore\Modules\Managers\Base\FormatManager\FormatManager|null
 */
function gtemplate_get_format_manager() {
    global $gCore;

    if (!$gCore) {
        return null;
    }

    try {
        $format = $gCore->getService('FormatManager');
        return ($format && $format->isInitialized()) ? $format : null;
    } catch (\Throwable $e) {
        // Service-registry-not-ready (early init / late shutdown). Caller
        // checks for null and degrades gracefully; logging would be noise.
        return null;
    }
}

/**
 * Validate face configuration against schema
 *
 * Uses gNode format system for JSONSchema validation.
 * Falls back to local validation if gNode unavailable.
 *
 * @param array $config Face configuration to validate
 * @return array Validation result ['valid' => bool, 'errors' => array]
 */
function gtemplate_validate_face_config(array $config): array {
    $format = gtemplate_get_format_manager();

    if ($format) {
        try {
            // Use gNode-based validation
            $result = $format->validateMessage(
                json_encode($config),
                'gtemplate_face_config'
            );
            return $result;
        } catch (\Throwable $e) {
            gtemplate_track_error('gTemplate: gNode validation failed, using local: ' . $e->getMessage());
        }
    }

    // Local fallback validation
    return gtemplate_validate_face_config_local($config);
}

/**
 * Local fallback validation for face configuration
 *
 * Used when gNode is unavailable. Performs basic validation.
 *
 * @param array $config Face configuration
 * @return array Validation result
 */
function gtemplate_validate_face_config_local(array $config): array {
    $errors = [];

    // Required: face_id
    if (!isset($config['face_id'])) {
        $errors[] = 'face_id is required';
    } elseif (!is_int($config['face_id']) || $config['face_id'] < 0 || $config['face_id'] > 5) {
        $errors[] = 'face_id must be an integer between 0 and 5';
    }

    // Required: source
    if (!isset($config['source'])) {
        $errors[] = 'source is required';
    } elseif (!in_array($config['source'], ['demo', 'page', 'post', 'custom'], true)) {
        $errors[] = 'source must be one of: demo, page, post, custom';
    }

    // Conditional: content_id required for page/post sources
    if (isset($config['source']) && in_array($config['source'], ['page', 'post'], true)) {
        if (!isset($config['content_id']) || $config['content_id'] < 1) {
            $errors[] = "content_id is required and must be > 0 when source is '{$config['source']}'";
        }
    }

    // Optional: nav_label length
    if (isset($config['nav_label']) && strlen($config['nav_label']) > 50) {
        $errors[] = 'nav_label must be 50 characters or less';
    }

    // Optional: custom_html length
    if (isset($config['custom_html']) && strlen($config['custom_html']) > 65535) {
        $errors[] = 'custom_html must be 65535 characters or less';
    }

    return [
        'valid' => empty($errors),
        'errors' => $errors
    ];
}

/**
 * Validate all face configurations
 *
 * @param array $configs Array of face configurations
 * @return array Validation results per face
 */
function gtemplate_validate_all_face_configs(array $configs): array {
    $results = [];

    foreach ($configs as $face_id => $config) {
        $results[$face_id] = gtemplate_validate_face_config($config);
    }

    return $results;
}

/**
 * Validate render request
 *
 * @param array $request Render request data
 * @return array Validation result
 */
function gtemplate_validate_render_request(array $request): array {
    $format = gtemplate_get_format_manager();

    if ($format) {
        try {
            return $format->validateMessage(
                json_encode($request),
                'gtemplate_render_request'
            );
        } catch (\Throwable $e) {
            gtemplate_track_error('gTemplate: Render request validation error: ' . $e->getMessage());
        }
    }

    // Local fallback
    $errors = [];

    if (empty($request['template'])) {
        $errors[] = 'template is required';
    } elseif (!preg_match('/^[a-zA-Z0-9_-]+$/', $request['template'])) {
        $errors[] = 'template contains invalid characters';
    }

    if (!isset($request['face_id'])) {
        $errors[] = 'face_id is required';
    } elseif (!is_int($request['face_id']) || $request['face_id'] < 0 || $request['face_id'] > 5) {
        $errors[] = 'face_id must be an integer between 0 and 5';
    }

    return [
        'valid' => empty($errors),
        'errors' => $errors
    ];
}

/**
 * Detect format of incoming message
 *
 * Uses gNode format detection with confidence scoring.
 *
 * @param string $message Message content
 * @return array Detection result ['format' => string, 'confidence' => float]
 */
function gtemplate_detect_message_format(string $message): array {
    $format = gtemplate_get_format_manager();

    if ($format) {
        try {
            return $format->detectFormat($message);
        } catch (\Throwable $e) {
            gtemplate_track_error('gTemplate: Format detection error: ' . $e->getMessage());
        }
    }

    // Local fallback: basic JSON detection
    $decoded = json_decode($message, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        // Check for gTemplate-specific patterns
        if (isset($decoded['face_id']) && isset($decoded['source'])) {
            return ['format' => 'gtemplate_face_config', 'confidence' => 0.9];
        }
        if (isset($decoded['template']) && isset($decoded['face_id'])) {
            return ['format' => 'gtemplate_render_request', 'confidence' => 0.9];
        }
        return ['format' => 'standard_json', 'confidence' => 0.7];
    }

    return ['format' => 'unknown', 'confidence' => 0.0];
}

/**
 * Get format manager metrics
 *
 * @return array Metrics data
 */
function gtemplate_get_format_metrics(): array {
    $format = gtemplate_get_format_manager();

    if ($format) {
        return $format->getMetrics();
    }

    return [
        'mode' => 'unavailable',
        'registrations' => 0,
        'detections' => 0,
        'conversions' => 0
    ];
}

/**
 * Hook: Validate face configs on Customizer save
 *
 * Validates all face configurations before allowing save.
 */
add_filter('customize_validate_gtemplate_face_config', function($validity, $value, $setting) {
    // Parse the face number from setting ID
    if (preg_match('/cube_face_(\d+)_/', $setting->id, $matches)) {
        $face_num = (int) $matches[1];
        $face_id = $face_num - 1; // Convert to 0-based

        // Build config from current settings
        $config = [
            'face_id' => $face_id,
            'source' => $value,
            'content_id' => (int) get_theme_mod(gtemplate_get_face_prefix() . "_{$face_num}_content_id", 0),
            'custom_html' => get_theme_mod(gtemplate_get_face_prefix() . "_{$face_num}_custom_html", ''),
            'nav_label' => get_theme_mod(gtemplate_get_face_prefix() . "_{$face_num}_nav_label", '')
        ];

        $validation = gtemplate_validate_face_config($config);

        if (!$validation['valid']) {
            $validity->add('gtemplate_invalid_config', implode('; ', $validation['errors']));
        }
    }

    return $validity;
}, 10, 3);
