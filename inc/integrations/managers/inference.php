<?php
declare(strict_types=1);
/**
 * Inference Integration for gTemplate Theme
 *
 * Provides graceful AI inference capabilities:
 * - Extension: Full LLM inference via gNode-INFERENCE daemon
 * - Default: Stub implementation with upgrade notices
 *
 * @package     gTemplate
 * @subpackage  Inc
 * @version     1.0.0
 * @since       1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Initialize InferenceManager with graceful fallback
 *
 * @param \gCore\gNode\gNodeClientInterface|null $gNodeClient gNode client instance
 * @return void
 */
function gtemplate_init_inference_manager($gNodeClient = null) {
    global $gCore;

    if (!$gCore) {
        if (defined('GTEMPLATE_DEBUG') && GTEMPLATE_DEBUG) {
            gtemplate_track_error('[gTemplate] InferenceManager: gCore not available');
        }
        return;
    }

    try {
        // Get InferenceManager via gCore resolver (returns stub or extension automatically)
        $manager = $gCore->getService('InferenceManager');
        $manager->initialize([
            'site_id' => gtemplate_get_site_id(),
            'node_id' => 'web-' . gethostname(),
            'use_gnode' => $gNodeClient !== null,
            'gnode_client' => $gNodeClient,
        ]);
        $GLOBALS['gtemplate_inference_manager'] = $manager;

        if (defined('GTEMPLATE_DEBUG') && GTEMPLATE_DEBUG) {
            $mode = $gCore->isExtensionInstalled('InferenceManager') ? 'full' : 'stub';
            gtemplate_track_error("[gTemplate] InferenceManager initialized ({$mode} mode)");
        }

    } catch (\Throwable $e) {
        gtemplate_track_error('[gTemplate] InferenceManager initialization failed: ' . $e->getMessage());
    }
}

/**
 * Get the InferenceManager instance
 *
 * @return \gCore\Modules\Core\Interfaces\Extensions\InferenceManagerInterface|null
 */
function gtemplate_get_inference_manager() {
    return $GLOBALS['gtemplate_inference_manager'] ?? null;
}

/**
 * Run AI inference on content
 *
 * @param string $prompt The inference prompt
 * @param array $context Optional context data
 * @param array $options Optional inference options
 * @return array Result with 'success', 'response', and 'model' keys
 */
function gtemplate_infer(string $prompt, array $context = [], array $options = []): array {
    $manager = gtemplate_get_inference_manager();

    if (!$manager) {
        return [
            'success' => false,
            'error' => 'InferenceManager not available',
            'stub_mode' => true
        ];
    }

    return $manager->infer($prompt, $context, $options);
}

/**
 * Generate content summary using AI
 *
 * @param string $content Content to summarize
 * @param int $maxLength Maximum summary length
 * @return string Summary or original content on failure
 */
function gtemplate_summarize(string $content, int $maxLength = 200): string {
    $manager = gtemplate_get_inference_manager();

    if (!$manager) {
        // Free-tier fallback: simple truncation
        if (strlen($content) <= $maxLength) {
            return $content;
        }
        return substr($content, 0, $maxLength - 3) . '...';
    }

    return $manager->summarize($content, $maxLength);
}

/**
 * Extract key entities from content
 *
 * @param string $content Content to analyze
 * @return array Extracted entities
 */
function gtemplate_extract_entities(string $content): array {
    $manager = gtemplate_get_inference_manager();

    if (!$manager) {
        return [];
    }

    return $manager->extractEntities($content);
}

/**
 * Generate FAQ pairs from content
 *
 * @param string $content Content to analyze
 * @param int $count Number of FAQ pairs
 * @return array FAQ pairs
 */
function gtemplate_generate_faq(string $content, int $count = 5): array {
    $manager = gtemplate_get_inference_manager();

    if (!$manager) {
        return [];
    }

    return $manager->generateFAQPairs($content, $count);
}

/**
 * Check if inference is available (requires extension)
 *
 * @return bool
 */
function gtemplate_inference_available(): bool {
    $manager = gtemplate_get_inference_manager();

    if (!$manager) {
        return false;
    }

    $status = $manager->getStatus();
    return !($status['stub_mode'] ?? true);
}

/**
 * Get inference service status
 *
 * @return array Status information
 */
function gtemplate_inference_status(): array {
    $manager = gtemplate_get_inference_manager();

    if (!$manager) {
        return [
            'available' => false,
            'stub_mode' => true,
            'message' => 'InferenceManager not initialized'
        ];
    }

    return $manager->getStatus();
}
