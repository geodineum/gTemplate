/**
 * Contact Form JavaScript
 *
 * Handles:
 * - Character counting
 * - JS challenge for anti-spam
 * - Behavioral tracking for anti-bot
 * - HTMX response handling
 * - Double-submit prevention
 *
 * @package gTemplate
 */
(function() {
    'use strict';

    /**
     * Resolve the theme's REST API base for this form. Prefers the
     * server-rendered data-api-base attribute; falls back to stripping the
     * known submit path off hx-post. Both carry the active theme's REST
     * namespace, so no URL is hardcoded here.
     * @param {HTMLFormElement} form The form element
     * @returns {string} API base (e.g. /wp-json/gtemplate/v1) or ''
     */
    function getApiBase(form) {
        if (form.dataset.apiBase) {
            return form.dataset.apiBase.replace(/\/$/, '');
        }
        var hxPost = form.getAttribute('hx-post') || '';
        return hxPost.replace(/\/(contact|form)\/submit(\?.*)?$/, '');
    }

    /**
     * Refresh CSRF token from server
     * Required when page is served from cache (stale tokens)
     * @param {HTMLFormElement} form The form element
     * @returns {Promise<boolean>} Success status
     */
    function refreshCsrfToken(form) {
        var apiBase = getApiBase(form);
        if (!apiBase) {
            return Promise.resolve(false);
        }
        return fetch(apiBase + '/csrf-token', {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json'
            }
        })
        .then(function(response) {
            if (!response.ok) throw new Error('CSRF refresh failed');
            return response.json();
        })
        .then(function(data) {
            if (data.success && data.token) {
                // Update hidden field
                var tokenField = form.querySelector('input[name="_csrf_token"]');
                if (tokenField) {
                    tokenField.value = data.token;
                }
                // Update HTMX header
                var hxHeaders = form.getAttribute('hx-headers');
                if (hxHeaders) {
                    try {
                        var headers = JSON.parse(hxHeaders);
                        headers['X-CSRF-Token'] = data.token;
                        form.setAttribute('hx-headers', JSON.stringify(headers));
                    } catch (e) {
                        form.setAttribute('hx-headers', JSON.stringify({'X-CSRF-Token': data.token}));
                    }
                }
                return true;
            }
            return false;
        })
        .catch(function(err) {
            console.warn('[ContactForm] CSRF refresh error:', err.message);
            return false;
        });
    }

    /**
     * Initialize a contact form instance
     * @param {HTMLElement} container The .contact-form container element
     */
    function initContactForm(container) {
        if (!container) return;
        if (container.dataset.initialized === 'true') return; // Prevent double initialization

        var faceId = container.getAttribute('data-face-id');
        var form = container.querySelector('form');
        if (!form) return;


        // Mark as initialized
        container.dataset.initialized = 'true';

        // Refresh CSRF token (required for cached pages with stale tokens)
        refreshCsrfToken(form);

        // Assemble protected phone numbers (anti-bot protection)
        var protectedPhones = container.querySelectorAll('.protected-phone');
        protectedPhones.forEach(function(phoneEl) {
            // Assemble phone number from chunks
            var p1 = phoneEl.getAttribute('data-p1') || '';
            var p2 = phoneEl.getAttribute('data-p2') || '';
            var p3 = phoneEl.getAttribute('data-p3') || '';
            var p4 = phoneEl.getAttribute('data-p4') || '';
            var display = phoneEl.getAttribute('data-display') || '';

            // Build full phone number
            var fullPhone = p1 + p2 + p3 + p4;

            if (fullPhone) {
                // Set the tel: href for calling
                phoneEl.href = 'tel:' + fullPhone;
                // Use formatted display or the raw number
                phoneEl.textContent = display || fullPhone;
                // Clean up data attributes to make scraping harder
                phoneEl.removeAttribute('data-p1');
                phoneEl.removeAttribute('data-p2');
                phoneEl.removeAttribute('data-p3');
                phoneEl.removeAttribute('data-p4');
                phoneEl.removeAttribute('data-display');
            }
        });

        // Set JS challenge field (proves JavaScript execution)
        var challengeField = form.querySelector('input[name="_js_challenge"]');
        if (challengeField) {
            var timestamp = Math.floor(Date.now() / 1000);
            var random = Math.random().toString(36).substring(2, 10);
            challengeField.value = 'gcore_' + timestamp + '_' + random;
        }

        // Set form load time if not already set
        var loadTimeField = form.querySelector('input[name="_form_load_time"]');
        if (loadTimeField && (!loadTimeField.value || loadTimeField.value === '0')) {
            loadTimeField.value = Math.floor(Date.now() / 1000);
        }

        // Track behavioral signals (proves human interaction)
        var behavior = {
            mouse_movements: 0,
            key_events: 0,
            focus_events: 0,
            chars_typed: 0,
            scroll_events: 0,
            start_time: Date.now()
        };

        // Mouse movement tracking (bots rarely have mouse movement)
        form.addEventListener('mousemove', function() {
            behavior.mouse_movements++;
        }, { passive: true });

        // Keyboard tracking
        form.addEventListener('keydown', function() {
            behavior.key_events++;
            behavior.chars_typed++;
        }, { passive: true });

        // Focus tracking (humans focus on fields)
        form.addEventListener('focusin', function(e) {
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') {
                behavior.focus_events++;
            }
        }, { passive: true });

        // Scroll tracking within form
        form.addEventListener('scroll', function() {
            behavior.scroll_events++;
        }, { passive: true });

        // Character count for message field
        var messageField = form.querySelector('textarea[name="message"]');
        var charCountEl = container.querySelector('.char-count span');
        if (messageField && charCountEl) {
            // Initialize with current length
            charCountEl.textContent = messageField.value.length;

            messageField.addEventListener('input', function() {
                charCountEl.textContent = this.value.length;
            }, { passive: true });

        }

        // Before submit, calculate and set behavior data
        form.addEventListener('submit', function(e) {
            var elapsed = (Date.now() - behavior.start_time) / 1000;
            behavior.chars_per_second = behavior.chars_typed / Math.max(elapsed, 1);
            behavior.time_on_form = elapsed;

            var behaviorField = form.querySelector('input[name="_behavior_data"]');
            if (behaviorField) {
                behaviorField.value = JSON.stringify(behavior);
            }
        });

        // Double-submit prevention with 10-second cooldown
        var lastSubmit = 0;
        form.addEventListener('htmx:beforeRequest', function(e) {
            var now = Date.now();
            if (now - lastSubmit < 10000) {
                e.preventDefault();
                var status = container.querySelector('.form-status');
                if (status) {
                    status.innerHTML = '<div class="info-message">Please wait before submitting again.</div>';
                }
                return false;
            }
            lastSubmit = now;
        });

        // HTMX response handlers
        form.addEventListener('htmx:afterRequest', function(evt) {
            var status = container.querySelector('.form-status');

            if (evt.detail.successful) {
                form.classList.add('submitted-success');
            } else if (evt.detail.xhr && evt.detail.xhr.status >= 400) {
                form.classList.add('submitted-error');
                if (status) {
                    try {
                        var response = JSON.parse(evt.detail.xhr.responseText);
                        status.innerHTML = '<div class="error-message">' + (response.error || response.message || 'An error occurred. Please try again.') + '</div>';
                    } catch (e) {
                        status.innerHTML = '<div class="error-message">An error occurred. Please try again.</div>';
                    }
                }
            }
        });

    }

    /**
     * Initialize all contact forms on the page
     */
    function initAllContactForms() {
        var containers = document.querySelectorAll('.contact-form[data-face-id]');
        containers.forEach(function(container) {
            initContactForm(container);
        });
    }

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAllContactForms);
    } else {
        // DOM already loaded
        initAllContactForms();
    }

    // Re-initialize when HTMX loads new content
    document.body.addEventListener('htmx:load', function(evt) {
        // Check if the loaded content contains a contact form
        var newContent = evt.detail.elt;
        if (newContent) {
            // If the loaded element is a contact form
            if (newContent.classList && newContent.classList.contains('contact-form')) {
                initContactForm(newContent);
            }
            // If the loaded element contains contact forms
            var nestedForms = newContent.querySelectorAll ? newContent.querySelectorAll('.contact-form[data-face-id]') : [];
            nestedForms.forEach(function(container) {
                initContactForm(container);
            });
        }
    });

    // Expose for manual initialization if needed
    window.initContactForm = initContactForm;
    window.initAllContactForms = initAllContactForms;
})();
