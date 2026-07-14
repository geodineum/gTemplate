/**
 * 3D Nav Cubes — Navigation cube controller
 *
 * Handles click events, active state management, and optional
 * rotation sync with the main theme navigation.
 *
 * Listens for the generic 'gtemplate:navigate' CustomEvent that
 * child themes bridge from their own navigation events.
 *
 * @package gTemplate
 * @since 2.1.0
 */

(function () {
    'use strict';

    const TRANSITION_MS = 400;

    /**
     * Initialize nav cube click handlers
     */
    function init() {
        const items = document.querySelectorAll('.nav-cube-item');
        if (!items.length) return;

        // Click handler — delegates to child theme's navigate function
        items.forEach(item => {
            item.addEventListener('click', (e) => {
                e.preventDefault();
                const faceIndex = parseInt(item.dataset.face, 10);
                const faceKey = item.dataset.key || '';

                // Fire generic navigate event — child theme JS listens and handles
                window.dispatchEvent(new CustomEvent('gtemplate:nav-cube-click', {
                    detail: { index: faceIndex, key: faceKey, element: item }
                }));

                // Direct fallback: try calling the child theme's navigate API
                // Works even if the child hasn't set up an event bridge yet
                if (window.tesseract && window.tesseract.navigate) {
                    window.tesseract.navigate(faceKey || 'cell' + faceIndex, null);
                } else if (window.cube && window.cube.rotateToCubeFace) {
                    window.cube.rotateToCubeFace(faceIndex);
                }
            });

            // Keyboard support
            item.setAttribute('tabindex', '0');
            item.setAttribute('role', 'button');
            item.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    item.click();
                }
            });
        });

        // Listen for navigation events (from child theme bridge)
        window.addEventListener('gtemplate:navigate', (e) => {
            const { index } = e.detail;
            setActive(index);
        });
    }

    /**
     * Set the active nav cube
     */
    function setActive(activeIndex) {
        document.querySelectorAll('.nav-cube-item').forEach(item => {
            const faceIndex = parseInt(item.dataset.face, 10);
            item.classList.toggle('active', faceIndex === activeIndex);
            item.setAttribute('aria-pressed', faceIndex === activeIndex ? 'true' : 'false');
        });
    }

    /**
     * Rotate a specific nav cube to show a given face
     * Can be called by child themes for contextual face reveals
     *
     * @param {number} cubeIndex - Which nav cube to rotate
     * @param {string} face - 'front'|'back'|'left'|'right'|'top'|'bottom'
     */
    function showFace(cubeIndex, face) {
        const item = document.querySelector(`.nav-cube-item[data-face="${cubeIndex}"]`);
        if (!item) return;

        const cube = item.querySelector('.nav-cube');
        if (!cube) return;

        const size = getComputedStyle(item.querySelector('.nav-cube-scene'))
            .getPropertyValue('width');
        const half = `calc(${size} / -2)`;

        const rotations = {
            'front':  `translateZ(${half})`,
            'back':   `translateZ(${half}) rotateY(180deg)`,
            'right':  `translateZ(${half}) rotateY(-90deg)`,
            'left':   `translateZ(${half}) rotateY(90deg)`,
            'top':    `translateZ(${half}) rotateX(-90deg)`,
            'bottom': `translateZ(${half}) rotateX(90deg)`,
        };

        cube.style.transform = rotations[face] || rotations['front'];
    }

    // Auto-init on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Expose API for child themes
    window.gTemplateNavCubes = { setActive, showFace };
})();
