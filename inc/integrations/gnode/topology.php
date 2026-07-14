<?php
declare(strict_types=1);
/**
 * Topology Integration for gTemplate Theme
 *
 * Provides graceful topology/service mesh capabilities:
 * - Extension: Full gNode-based service discovery and registration
 * - Default: Stub implementation with no-op operations
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
 * Initialize TopologyManager with graceful fallback
 *
 * Note: gTemplate already initializes TopologyManager in functions.php
 * This file provides additional wrapper functions for theme use.
 *
 * @param \gCore\gNode\gNodeClientInterface|null $gNodeClient gNode client instance
 * @return void
 */
function gtemplate_init_topology_integration($gNodeClient = null) {
    // TopologyManager is initialized in gtemplate_initialize_topology_manager()
    // This function provides additional setup if needed

    if (defined('GTEMPLATE_DEBUG') && GTEMPLATE_DEBUG) {
        gtemplate_track_error('[gTemplate] Topology integration loaded');
    }
}

/**
 * Get the TopologyManager instance
 *
 * @return \gCore\Modules\Core\Interfaces\Extensions\TopologyManagerInterface|null
 */
function gtemplate_get_topology_manager() {
    global $gCore;

    if (!$gCore) {
        return null;
    }

    try {
        return $gCore->getService('TopologyManager');
    } catch (\Throwable $e) {
        gtemplate_track_error('[gTemplate] TopologyManager access failed: ' . $e->getMessage());
    }

    return null;
}

/**
 * Discover services matching capability requirements
 *
 * @param array $requirements Capability requirements (dimension => min_value)
 * @param int $limit Maximum results
 * @return array Matching services
 */
function gtemplate_discover_services(array $requirements, int $limit = 10): array {
    $manager = gtemplate_get_topology_manager();

    if (!$manager) {
        return [];
    }

    return $manager->discoverServices($requirements, $limit);
}

/**
 * Find services by capability requirements
 *
 * @param array $requirements Capability requirements (dimension => min_value)
 * @return array Matching services
 */
function gtemplate_find_services(array $requirements): array {
    $manager = gtemplate_get_topology_manager();

    if (!$manager) {
        return [];
    }

    return $manager->findServices($requirements);
}

/**
 * Get topology visualization data
 *
 * @param array $dimensions Dimensions for visualization axes
 * @return array 3D mesh visualization data
 */
function gtemplate_get_topology_visualization(array $dimensions = []): array {
    $manager = gtemplate_get_topology_manager();

    if (!$manager) {
        return [
            'nodes' => [],
            'edges' => [],
            'stub_mode' => true
        ];
    }

    return $manager->getTopologyVisualization($dimensions);
}

/**
 * Register current site in topology (smart registration)
 *
 * @param array $capabilities Optional capability overrides
 * @param array $metadata Optional metadata
 * @return bool Success
 */
function gtemplate_register_in_topology(array $capabilities = [], array $metadata = []): bool {
    $manager = gtemplate_get_topology_manager();

    if (!$manager) {
        return false;
    }

    // Use smart registration if available
    if (method_exists($manager, 'smartRegister')) {
        return $manager->smartRegister($capabilities, $metadata);
    }

    return false;
}

/**
 * Check if current site is registered in topology
 *
 * @return bool
 */
function gtemplate_is_registered_in_topology(): bool {
    $manager = gtemplate_get_topology_manager();

    if (!$manager) {
        return false;
    }

    return $manager->isRegisteredInTopology();
}

/**
 * Deregister current site from topology
 *
 * @return bool Success
 */
function gtemplate_deregister_from_topology(): bool {
    $manager = gtemplate_get_topology_manager();

    if (!$manager) {
        return false;
    }

    return $manager->deregister();
}

/**
 * Get registration status
 *
 * @return array Status information
 */
function gtemplate_get_topology_status(): array {
    $manager = gtemplate_get_topology_manager();

    if (!$manager) {
        return [
            'registered' => false,
            'stub_mode' => true,
            'message' => 'TopologyManager not available'
        ];
    }

    return $manager->getRegistrationStatus();
}

/**
 * Check if topology features are available (requires extension)
 *
 * @return bool
 */
function gtemplate_has_topology(): bool {
    $manager = gtemplate_get_topology_manager();

    if (!$manager) {
        return false;
    }

    $status = $manager->getStatus();
    return !($status['stub_mode'] ?? true);
}

/**
 * Deregister from topology when theme is switched
 */
function gtemplate_deregister_on_theme_switch() {
    gtemplate_deregister_from_topology();
}
add_action('switch_theme', 'gtemplate_deregister_on_theme_switch');
