/**
 * gCube PWA Content Loader
 *
 * Implements client-side template streaming for Progressive Web App functionality.
 * Loads gNode-rendered templates via REST API and injects into cube faces.
 *
 * @package gCube
 * @version 1.0.0
 */

(function(window) {
    'use strict';

    // Ensure gCube namespace exists
    window.gCube = window.gCube || {};

    /**
     * Load face content from gNode template
     *
     * @param {number} faceId - Face ID (0-5)
     * @param {string} templateId - Template identifier (e.g., "home", "blog-list")
     * @param {object} data - Additional template data
     * @returns {Promise<string>} Rendered HTML
     */
    window.gCube.loadFaceContent = async function(faceId, templateId, data = {}) {
        const face = document.querySelector(`[data-face-id="${faceId}"]`) ||
                     document.querySelector(`.face-${faceId}`);

        if (!face) {
            console.error(`[PWA] Face ${faceId} not found`);
            throw new Error(`Face ${faceId} not found`);
        }

        // Show loading state
        face.classList.add('loading');

        // Trigger loading event
        const loadingEvent = new CustomEvent('cube-face-loading', {
            detail: { faceId, templateId, data }
        });
        face.dispatchEvent(loadingEvent);

        try {
            // gNode template render request
            const response = await fetch('/wp-json/gcube/v1/render', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'text/html'
                },
                body: JSON.stringify({
                    template: templateId,
                    face_id: faceId,
                    data: data
                })
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const html = await response.text();

            // Inject rendered content (NO full page reload)
            face.innerHTML = html;

            // Mark as loaded
            face.classList.remove('loading');
            face.dataset.loaded = 'true';
            face.dataset.template = templateId;

            // Execute inline scripts (if any)
            executeScripts(face);

            // Trigger loaded event
            const loadedEvent = new CustomEvent('cube-face-loaded', {
                detail: { faceId, templateId, data, html }
            });
            face.dispatchEvent(loadedEvent);

            console.log(`[PWA] Loaded ${templateId} into face ${faceId}`);

            return html;

        } catch (error) {
            console.error(`[PWA] Failed to load ${templateId}:`, error);

            // Trigger error event
            const errorEvent = new CustomEvent('cube-face-error', {
                detail: { faceId, templateId, data, error }
            });
            face.dispatchEvent(errorEvent);

            // Offline fallback: show cached content or error state
            if (!navigator.onLine) {
                face.innerHTML = getOfflineFallback(faceId, templateId);
            } else {
                face.innerHTML = getErrorFallback(faceId, templateId, error);
            }

            face.classList.remove('loading');

            throw error;
        }
    };

    /**
     * Execute scripts in injected HTML
     *
     * @param {HTMLElement} container - Container element
     */
    function executeScripts(container) {
        const scripts = container.querySelectorAll('script');

        scripts.forEach(script => {
            const newScript = document.createElement('script');

            // Copy attributes
            Array.from(script.attributes).forEach(attr => {
                newScript.setAttribute(attr.name, attr.value);
            });

            // Copy content
            newScript.textContent = script.textContent;

            // Replace old script with new (executable) one
            script.parentNode.replaceChild(newScript, script);
        });
    }

    /**
     * Get offline fallback HTML
     *
     * @param {number} faceId - Face ID
     * @param {string} templateId - Template ID
     * @returns {string} Fallback HTML
     */
    function getOfflineFallback(faceId, templateId) {
        return `
            <div class="cube-face-content offline-fallback" data-face-id="${faceId}">
                <header class="face-header">
                    <h2>Offline</h2>
                </header>
                <main class="face-body">
                    <div class="offline-message">
                        <p>You are currently offline.</p>
                        <p>This content is not available in offline mode.</p>
                        <button onclick="window.gCube.reloadFace(${faceId}, '${templateId}')">
                            Try Again
                        </button>
                    </div>
                </main>
            </div>
        `;
    }

    /**
     * Get error fallback HTML
     *
     * @param {number} faceId - Face ID
     * @param {string} templateId - Template ID
     * @param {Error} error - Error object
     * @returns {string} Fallback HTML
     */
    function getErrorFallback(faceId, templateId, error) {
        return `
            <div class="cube-face-content error-fallback" data-face-id="${faceId}">
                <header class="face-header">
                    <h2>Content Unavailable</h2>
                </header>
                <main class="face-body">
                    <div class="error-message">
                        <p>Failed to load content.</p>
                        <details>
                            <summary>Error Details</summary>
                            <pre>${error.message}</pre>
                        </details>
                        <button onclick="window.gCube.reloadFace(${faceId}, '${templateId}')">
                            Try Again
                        </button>
                    </div>
                </main>
            </div>
        `;
    }

    /**
     * Reload face content (retry)
     *
     * @param {number} faceId - Face ID
     * @param {string} templateId - Template ID
     * @param {object} data - Template data
     */
    window.gCube.reloadFace = function(faceId, templateId, data = {}) {
        console.log(`[PWA] Reloading face ${faceId} with template ${templateId}`);
        return window.gCube.loadFaceContent(faceId, templateId, data);
    };

    /**
     * Lazy loading strategy: intersection observer
     *
     * Loads face content when it becomes visible
     */
    window.gCube.setupLazyLoading = function() {
        if (!('IntersectionObserver' in window)) {
            console.warn('[PWA] IntersectionObserver not supported, skipping lazy loading');
            return;
        }

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting && !entry.target.dataset.loaded) {
                    const faceId = parseInt(entry.target.dataset.faceId);
                    const templateId = entry.target.dataset.template || `face-${faceId}`;

                    console.log(`[PWA] Face ${faceId} visible, lazy loading content`);

                    // Trigger custom event for application-specific logic
                    const visibleEvent = new CustomEvent('cube-face-visible', {
                        detail: { faceId, templateId }
                    });
                    entry.target.dispatchEvent(visibleEvent);

                    // Load content only if face-visible listener didn't handle it
                    if (!entry.target.dataset.loaded) {
                        window.gCube.loadFaceContent(faceId, templateId);
                    }

                    // Stop observing after load
                    observer.unobserve(entry.target);
                }
            });
        }, {
            root: null,
            rootMargin: '50px',  // Preload 50px before visible
            threshold: 0.1       // Trigger at 10% visibility
        });

        // Observe all cube faces
        document.querySelectorAll('[data-face-id]').forEach(face => {
            observer.observe(face);
        });

        console.log('[PWA] Lazy loading initialized');
    };

    /**
     * Prefetch next likely face
     *
     * Predictively loads content for better perceived performance
     *
     * @param {number} currentFaceId - Current face ID
     */
    window.gCube.prefetchNextFace = function(currentFaceId) {
        // Simple prediction: next face in sequence
        const nextFaceId = (currentFaceId + 1) % 6;
        const nextFace = document.querySelector(`[data-face-id="${nextFaceId}"]`);

        if (nextFace && !nextFace.dataset.loaded) {
            const templateId = nextFace.dataset.template || `face-${nextFaceId}`;

            console.log(`[PWA] Prefetching face ${nextFaceId}`);

            // Load in background (requestIdleCallback for low priority)
            if ('requestIdleCallback' in window) {
                requestIdleCallback(() => {
                    window.gCube.loadFaceContent(nextFaceId, templateId);
                });
            } else {
                setTimeout(() => {
                    window.gCube.loadFaceContent(nextFaceId, templateId);
                }, 1000);
            }
        }
    };

    /**
     * Show update notification (when new Service Worker available)
     */
    window.gCube.showUpdateNotification = function() {
        const notification = document.createElement('div');
        notification.className = 'pwa-update-notification';
        notification.innerHTML = `
            <div class="notification-content">
                <p>New version available!</p>
                <button onclick="window.location.reload()">Update Now</button>
                <button onclick="this.parentElement.parentElement.remove()">Later</button>
            </div>
        `;
        document.body.appendChild(notification);

        console.log('[PWA] Update notification shown');
    };

    /**
     * Initialize PWA loader on DOM ready
     */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            console.log('[PWA] Content loader initialized');
        });
    } else {
        console.log('[PWA] Content loader initialized');
    }

})(window);
