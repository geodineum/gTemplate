<?php
/**
 * Metrics Integration for gTemplate Theme
 *
 * Provides graceful metrics tracking capabilities:
 * - Premium: Full gNode-based metrics with persistence
 * - Free-tier: Stub implementation with no-op tracking
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
 * Initialize MetricsManager with graceful fallback
 *
 * @param \gCore\gNode\gNodeClientInterface|null $gNodeClient gNode client instance
 * @return void
 */
function gtemplate_init_metrics_manager($gNodeClient = null) {
    global $gCore;

    if (!$gCore) {
        if (defined('GTEMPLATE_DEBUG') && GTEMPLATE_DEBUG) {
            error_log('[gTemplate] MetricsManager: gCore not available');
        }
        return;
    }

    try {
        // Get MetricsManager via gCore resolver (returns stub or premium automatically)
        $manager = $gCore->getService('MetricsManager');
        $manager->initialize([
            'site_id' => gtemplate_get_site_id(),
            'node_id' => 'web-' . gethostname(),
            'use_gnode' => $gNodeClient !== null,
            'gnode_client' => $gNodeClient,
        ]);
        $GLOBALS['gtemplate_metrics_manager'] = $manager;

        if (defined('GTEMPLATE_DEBUG') && GTEMPLATE_DEBUG) {
            $mode = $gCore->isPremiumInstalled('MetricsManager') ? 'premium' : 'stub';
            error_log("[gTemplate] MetricsManager initialized ({$mode} mode)");
        }

    } catch (\Throwable $e) {
        error_log('[gTemplate] MetricsManager initialization failed: ' . $e->getMessage());
    }
}

/**
 * Get the MetricsManager instance
 *
 * @return \gCore\Modules\Core\Interfaces\Premium\MetricsManagerInterface|null
 */
function gtemplate_get_metrics_manager() {
    return $GLOBALS['gtemplate_metrics_manager'] ?? null;
}

/**
 * Record a metric (convenience wrapper)
 *
 * @param string $name Metric name
 * @param array $data Metric data
 * @return bool Success
 */
function gtemplate_record_metric(string $name, array $data = []): bool {
    $manager = gtemplate_get_metrics_manager();
    if (!$manager) {
        return false;
    }
    return $manager->recordMetric($name, $data);
}

/**
 * Track a metric (alias for recordMetric)
 *
 * @param string $name Metric name
 * @param mixed $value Metric value
 * @param array $tags Optional tags
 * @return bool Success
 */
function gtemplate_track_metric(string $name, $value, array $tags = []): bool {
    $manager = gtemplate_get_metrics_manager();
    if (!$manager) {
        return false;
    }
    return $manager->trackMetric($name, $value, $tags);
}

/**
 * Get metrics summary
 *
 * @param int $hours Hours to look back
 * @return array Summary data
 */
function gtemplate_get_metrics_summary(int $hours = 24): array {
    $manager = gtemplate_get_metrics_manager();
    if (!$manager) {
        return [];
    }
    return $manager->getSummary($hours);
}

/**
 * Check if metrics tracking is available (premium)
 *
 * @return bool
 */
function gtemplate_has_metrics(): bool {
    $manager = gtemplate_get_metrics_manager();
    if (!$manager) {
        return false;
    }
    $status = $manager->getStatus();
    return !($status['stub_mode'] ?? true);
}
