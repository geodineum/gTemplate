<?php
declare(strict_types=1);
/**
 * State Integration for gTemplate Theme
 *
 * Provides graceful distributed state management:
 * - Extension: Full gNode-based state with persistence and pub/sub
 * - Default: In-memory only state (no persistence between requests)
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
 * Initialize StateManager with graceful fallback
 *
 * @param \gCore\gNode\gNodeClientInterface|null $gNodeClient gNode client instance
 * @return void
 */
function gtemplate_init_state_manager($gNodeClient = null) {
    global $gCore;

    if (!$gCore) {
        if (defined('GTEMPLATE_DEBUG') && GTEMPLATE_DEBUG) {
            gtemplate_track_error('[gTemplate] StateManager: gCore not available');
        }
        return;
    }

    try {
        // Get StateManager via gCore resolver (returns stub or extension automatically)
        $manager = $gCore->getService('StateManager');
        $manager->initialize([
            'site_id' => gtemplate_get_site_id(),
            'node_id' => 'web-' . gethostname(),
            'use_gnode' => $gNodeClient !== null,
            'gnode_client' => $gNodeClient,
        ]);
        $GLOBALS['gtemplate_state_manager'] = $manager;

        if (defined('GTEMPLATE_DEBUG') && GTEMPLATE_DEBUG) {
            $mode = $gCore->isExtensionInstalled('StateManager') ? 'full' : 'stub';
            gtemplate_track_error("[gTemplate] StateManager initialized ({$mode} mode)");
        }

    } catch (\Throwable $e) {
        gtemplate_track_error('[gTemplate] StateManager initialization failed: ' . $e->getMessage());
    }
}

/**
 * Get the StateManager instance
 *
 * @return \gCore\Modules\Core\Interfaces\Extensions\StateManagerInterface|null
 */
function gtemplate_get_state_manager() {
    return $GLOBALS['gtemplate_state_manager'] ?? null;
}

/**
 * Set state value
 *
 * @param string $key State key
 * @param mixed $value State value
 * @param array $options Optional settings
 * @return bool Success
 */
function gtemplate_set_state(string $key, $value, array $options = []): bool {
    $manager = gtemplate_get_state_manager();
    if (!$manager) {
        return false;
    }
    return $manager->setState($key, $value, $options);
}

/**
 * Get state value
 *
 * @param string $key State key
 * @param mixed $default Default value
 * @return mixed State value or default
 */
function gtemplate_get_state(string $key, $default = null) {
    $manager = gtemplate_get_state_manager();
    if (!$manager) {
        return $default;
    }
    return $manager->getState($key, $default);
}

/**
 * Remove state value
 *
 * @param string $key State key
 * @return bool Success
 */
function gtemplate_remove_state(string $key): bool {
    $manager = gtemplate_get_state_manager();
    if (!$manager) {
        return false;
    }
    return $manager->removeState($key);
}

/**
 * Begin a state transaction
 *
 * @return string|null Transaction ID or null on failure
 */
function gtemplate_begin_transaction(): ?string {
    $manager = gtemplate_get_state_manager();
    if (!$manager) {
        return null;
    }
    return $manager->beginTransaction();
}

/**
 * Commit a state transaction
 *
 * @param string|null $transactionId Transaction ID (optional)
 * @return bool Success
 */
function gtemplate_commit_transaction(?string $transactionId = null): bool {
    $manager = gtemplate_get_state_manager();
    if (!$manager) {
        return false;
    }
    return $manager->commitTransaction($transactionId);
}

/**
 * Rollback a state transaction
 *
 * @param string|null $transactionId Transaction ID (optional)
 * @return bool Success
 */
function gtemplate_rollback_transaction(?string $transactionId = null): bool {
    $manager = gtemplate_get_state_manager();
    if (!$manager) {
        return false;
    }
    return $manager->rollbackTransaction($transactionId);
}

/**
 * Check if state management is available (requires extension)
 *
 * @return bool
 */
function gtemplate_has_state(): bool {
    $manager = gtemplate_get_state_manager();
    if (!$manager) {
        return false;
    }
    $status = $manager->getStatus();
    return !($status['stub_mode'] ?? true);
}
