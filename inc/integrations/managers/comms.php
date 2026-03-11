<?php
/**
 * Comms Integration for gTemplate Theme
 *
 * Provides graceful notification/communications capabilities:
 * - Premium: Full gNode-COMMS daemon with multi-channel dispatch
 * - Free-tier: Stub implementation with in-memory storage
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
 * Initialize CommsManager with graceful fallback
 *
 * @param \gCore\gNode\gNodeClientInterface|null $gNodeClient gNode client instance
 * @return void
 */
function gtemplate_init_comms_manager($gNodeClient = null) {
    global $gCore;

    if (!$gCore) {
        if (defined('GTEMPLATE_DEBUG') && GTEMPLATE_DEBUG) {
            error_log('[gTemplate] CommsManager: gCore not available');
        }
        return;
    }

    try {
        // Get CommsManager via gCore resolver (returns stub or premium automatically)
        $manager = $gCore->getService('CommsManager');
        $manager->initialize([
            'site_id' => gtemplate_get_site_id(),
            'node_id' => 'web-' . gethostname(),
            'use_gnode' => $gNodeClient !== null,
            'gnode_client' => $gNodeClient,
        ]);
        $GLOBALS['gtemplate_comms_manager'] = $manager;

        if (defined('GTEMPLATE_DEBUG') && GTEMPLATE_DEBUG) {
            $mode = $gCore->isPremiumInstalled('CommsManager') ? 'premium' : 'stub';
            error_log("[gTemplate] CommsManager initialized ({$mode} mode)");
        }

    } catch (\Throwable $e) {
        error_log('[gTemplate] CommsManager initialization failed: ' . $e->getMessage());
    }
}

/**
 * Get the CommsManager instance
 *
 * @return \gCore\Modules\Core\Interfaces\Premium\CommsManagerInterface|null
 */
function gtemplate_get_comms_manager() {
    return $GLOBALS['gtemplate_comms_manager'] ?? null;
}

/**
 * Get recent messages from the comms stream
 *
 * @param string|null $siteId Site identifier (null = current site)
 * @param int $count Number of messages to retrieve
 * @return array Messages
 */
function gtemplate_get_recent_messages(?string $siteId = null, int $count = 50): array {
    $manager = gtemplate_get_comms_manager();

    if (!$manager) {
        return [];
    }

    $siteId = $siteId ?? gtemplate_get_site_id();
    return $manager->getRecentMessages($siteId, 'production', $count);
}

/**
 * Get comms statistics for current site
 *
 * @param string|null $siteId Site identifier (null = current site)
 * @return array Statistics
 */
function gtemplate_get_comms_stats(?string $siteId = null): array {
    $manager = gtemplate_get_comms_manager();

    if (!$manager) {
        return [
            'total_messages' => 0,
            'pending_dispatch' => 0,
            'stub_mode' => true
        ];
    }

    $siteId = $siteId ?? gtemplate_get_site_id();
    return $manager->getStats($siteId);
}

/**
 * Get site notification settings
 *
 * @param string|null $siteId Site identifier (null = current site)
 * @return array|null Settings or null if not configured
 */
function gtemplate_get_comms_settings(?string $siteId = null): ?array {
    $manager = gtemplate_get_comms_manager();

    if (!$manager) {
        return null;
    }

    $siteId = $siteId ?? gtemplate_get_site_id();
    return $manager->getSiteSettings($siteId);
}

/**
 * Save site notification settings
 *
 * @param array $settings Settings to save
 * @param string|null $siteId Site identifier (null = current site)
 * @return bool Success
 */
function gtemplate_save_comms_settings(array $settings, ?string $siteId = null): bool {
    $manager = gtemplate_get_comms_manager();

    if (!$manager) {
        return false;
    }

    $siteId = $siteId ?? gtemplate_get_site_id();
    return $manager->saveSiteSettings($siteId, $settings);
}

/**
 * Test a notification channel
 *
 * @param string $channel Channel name (email, telegram, sms)
 * @param string|null $siteId Site identifier (null = current site)
 * @return array Result with 'success' and 'message' keys
 */
function gtemplate_test_comms_channel(string $channel, ?string $siteId = null): array {
    $manager = gtemplate_get_comms_manager();

    if (!$manager) {
        return [
            'success' => false,
            'message' => 'CommsManager not available',
            'stub_mode' => true
        ];
    }

    $siteId = $siteId ?? gtemplate_get_site_id();
    return $manager->testChannel($siteId, $channel);
}

/**
 * Get gNode-COMMS daemon status
 *
 * @param string|null $siteId Site identifier (null = current site)
 * @return array Daemon status
 */
function gtemplate_get_comms_daemon_status(?string $siteId = null): array {
    $manager = gtemplate_get_comms_manager();

    if (!$manager) {
        return [
            'status' => 'not_available',
            'message' => 'CommsManager not initialized',
            'stub_mode' => true
        ];
    }

    $siteId = $siteId ?? gtemplate_get_site_id();
    return $manager->getDaemonStatus($siteId);
}

/**
 * Check if premium comms features are available
 *
 * @return bool
 */
function gtemplate_comms_premium(): bool {
    $manager = gtemplate_get_comms_manager();

    if (!$manager) {
        return false;
    }

    $status = $manager->getStatus();
    return !($status['stub_mode'] ?? true);
}

/**
 * Get comms service status
 *
 * @return array Status information
 */
function gtemplate_comms_status(): array {
    $manager = gtemplate_get_comms_manager();

    if (!$manager) {
        return [
            'available' => false,
            'stub_mode' => true,
            'message' => 'CommsManager not initialized'
        ];
    }

    return $manager->getStatus();
}

/**
 * Create default comms settings for current site
 *
 * @param string|null $siteId Site identifier (null = current site)
 * @return array Created settings
 */
function gtemplate_create_default_comms_settings(?string $siteId = null): array {
    $manager = gtemplate_get_comms_manager();

    if (!$manager) {
        return [];
    }

    $siteId = $siteId ?? gtemplate_get_site_id();
    return $manager->createDefaultSettings($siteId);
}
