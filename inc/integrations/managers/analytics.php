<?php
declare(strict_types=1);
/**
 * Analytics Integration for gTemplate Theme
 *
 * Provides graceful privacy-first analytics:
 * - Extension: Full gNode-based analytics with visitor tracking
 * - Default: Stub implementation with no tracking
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
 * Initialize AnalyticsManager with graceful fallback
 *
 * @param \gCore\gNode\gNodeClientInterface|null $gNodeClient gNode client instance
 * @return void
 */
function gtemplate_init_analytics_manager($gNodeClient = null) {
    global $gCore;

    if (!$gCore) {
        if (defined('GTEMPLATE_DEBUG') && GTEMPLATE_DEBUG) {
            gtemplate_track_error('[gTemplate] AnalyticsManager: gCore not available');
        }
        return;
    }

    try {
        // Get AnalyticsManager via gCore resolver (returns stub or extension automatically)
        $manager = $gCore->getService('AnalyticsManager');
        $manager->initialize([
            'site_id' => gtemplate_get_site_id(),
            'node_id' => 'web-' . gethostname(),
            'use_gnode' => $gNodeClient !== null,
            'gnode_client' => $gNodeClient,
        ]);
        $GLOBALS['gtemplate_analytics_manager'] = $manager;

        if (defined('GTEMPLATE_DEBUG') && GTEMPLATE_DEBUG) {
            $mode = $gCore->isExtensionInstalled('AnalyticsManager') ? 'full' : 'stub';
            gtemplate_track_error("[gTemplate] AnalyticsManager initialized ({$mode} mode)");
        }

    } catch (\Throwable $e) {
        gtemplate_track_error('[gTemplate] AnalyticsManager initialization failed: ' . $e->getMessage());
    }
}

/**
 * Get the AnalyticsManager instance
 *
 * @return \gCore\Modules\Core\Interfaces\Extensions\AnalyticsManagerInterface|null
 */
function gtemplate_get_analytics_manager() {
    return $GLOBALS['gtemplate_analytics_manager'] ?? null;
}

/**
 * Track a page visit
 *
 * @param array $data Visit data
 * @return bool Success
 */
function gtemplate_track_visit(array $data = []): bool {
    $manager = gtemplate_get_analytics_manager();
    if (!$manager) {
        return false;
    }
    return $manager->trackVisit($data);
}

/**
 * Get analytics summary
 *
 * @param int $days Number of days to summarize
 * @return array Summary data
 */
function gtemplate_get_analytics_summary(int $days = 7): array {
    $manager = gtemplate_get_analytics_manager();
    if (!$manager) {
        return [
            'unique_visitors' => 0,
            'total_pageviews' => 0,
            'stub_mode' => true
        ];
    }
    return $manager->getSummary($days);
}

/**
 * Get today's analytics
 *
 * @return array Today's summary
 */
function gtemplate_get_analytics_today(): array {
    $manager = gtemplate_get_analytics_manager();
    if (!$manager) {
        return [
            'unique_visitors' => 0,
            'total_pageviews' => 0,
            'stub_mode' => true
        ];
    }
    return $manager->getTodaySummary();
}

/**
 * Get unique visitors count
 *
 * @param string $startDate Start date (Y-m-d)
 * @param string $endDate End date (Y-m-d)
 * @return int Visitor count
 */
function gtemplate_get_unique_visitors(string $startDate, string $endDate): int {
    $manager = gtemplate_get_analytics_manager();
    if (!$manager) {
        return 0;
    }
    return $manager->getUniqueVisitors($startDate, $endDate);
}

/**
 * Get top pages
 *
 * @param string $startDate Start date
 * @param string $endDate End date
 * @param int $limit Number of pages
 * @return array Top pages
 */
function gtemplate_get_top_pages(string $startDate, string $endDate, int $limit = 10): array {
    $manager = gtemplate_get_analytics_manager();
    if (!$manager) {
        return [];
    }
    return $manager->getTopPages($startDate, $endDate, $limit);
}

/**
 * Check if analytics is available (requires extension)
 *
 * @return bool
 */
function gtemplate_has_analytics(): bool {
    $manager = gtemplate_get_analytics_manager();
    if (!$manager) {
        return false;
    }
    $status = $manager->getStatus();
    return !($status['stub_mode'] ?? true);
}
