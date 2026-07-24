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

        initTilt();
    }

    /**
     * Parallax tilt — the cubes turn to face the cursor, so the whole nav ring
     * feels like it is watching the pointer. Desktop + fine-pointer only; skips
     * touch and reduced-motion. Inline transforms win over the CSS hover/active
     * angles, so this replaces the static peek with live tracking.
     */
    function initTilt() {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        const cubes = Array.prototype.slice.call(document.querySelectorAll('.nav-cube'));
        if (!cubes.length) return;

        const HALF = 'calc(var(--nav-cube-size) / -2)';
        const MAX = 24; // degrees of swing at the extremes

        let raf = null, mx = 0, my = 0, tracking = false;
        // Gyro drives one shared angle (the whole ring leans with the device);
        // the pointer aims each cube individually at the cursor.
        let gyro = null;

        // Smoothing lives HERE (lerp per frame, transition:none while the loop
        // runs), never in a CSS transition — easing a per-frame retargeted
        // transform leaves every cube perpetually dragging behind the cursor.
        // When the ring settles the inline transition is cleared so the
        // stylesheet's 0.4s governs face-switch rotations again.
        const state = cubes.map(() => ({ ry: 0, rx: 0 }));
        let engaged = false;

        function apply() {
            const halfW = window.innerWidth * 0.5, halfH = window.innerHeight * 0.5;
            let settled = true;
            cubes.forEach((cube, i) => {
                let ty, tx;
                if (gyro) {
                    ty = gyro.ry;
                    tx = gyro.rx;
                } else {
                    const r = cube.parentElement.getBoundingClientRect();
                    const dx = Math.max(-1, Math.min(1, (mx - (r.left + r.width / 2)) / halfW));
                    const dy = Math.max(-1, Math.min(1, (my - (r.top + r.height / 2)) / halfH));
                    ty = tracking ? dx * MAX : 0;
                    tx = tracking ? -dy * MAX : 0;
                }
                const s = state[i];
                s.ry += (ty - s.ry) * 0.18;
                s.rx += (tx - s.rx) * 0.18;
                if (Math.abs(ty - s.ry) > 0.05 || Math.abs(tx - s.rx) > 0.05) settled = false;
                if (!engaged) cube.style.transition = 'none';
                cube.style.transform = `translateZ(${HALF}) rotateY(${s.ry.toFixed(2)}deg) rotateX(${s.rx.toFixed(2)}deg)`;
            });
            engaged = true;
            // Exit purely on settled — mousemove/gyro events re-arm via
            // schedule(), so no permanent RAF loop idles on mobile.
            if (!settled) {
                raf = requestAnimationFrame(apply);
            } else {
                raf = null;
                engaged = false;
                cubes.forEach(c => { c.style.transition = ''; });
            }
        }
        function schedule() { if (!raf) raf = requestAnimationFrame(apply); }

        if (window.matchMedia('(hover: none)').matches) {
            // No pointer on touch devices — lean the ring with the gyroscope
            // instead, same smoothing/holding-angle model as the tesseract.
            let sg = 0, sb = 0;
            function onOrient(e) {
                if (e.gamma === null || e.beta === null) return;
                const g = Math.max(-45, Math.min(45, e.gamma)) / 45;
                const b = Math.max(-45, Math.min(45, e.beta - 45)) / 45;
                sg += (g - sg) * 0.12;
                sb += (b - sb) * 0.12;
                gyro = { ry: sg * MAX, rx: -sb * MAX };
                schedule();
            }
            const listen = () => window.addEventListener('deviceorientation', onOrient, { passive: true });
            if (typeof DeviceOrientationEvent !== 'undefined' &&
                typeof DeviceOrientationEvent.requestPermission === 'function') {
                // iOS requires a gesture before it will hand over orientation.
                document.addEventListener('touchend', function grant() {
                    DeviceOrientationEvent.requestPermission()
                        .then(s => { if (s === 'granted') listen(); })
                        .catch(() => {});
                }, { once: true });
            } else {
                listen();
            }
            return;
        }

        window.addEventListener('mousemove', e => {
            tracking = true; mx = e.clientX; my = e.clientY;
            schedule();
        }, { passive: true });
        document.addEventListener('mouseleave', () => {
            tracking = false;
            schedule();
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
