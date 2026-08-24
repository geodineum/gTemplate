/**
 * Cookieless visitor analytics beacon.
 *
 * Fires one POST to the gTemplate analytics/hit endpoint per page view, and one
 * to analytics/click whenever a [data-track] element is activated. No cookies,
 * no device storage: the server derives a daily-rotating hash. An explicit
 * analytics opt-out (cookie-consent banner) suppresses both.
 */
(function () {
    'use strict';

    var cfg = window.gtemplateAnalytics;
    if (!cfg || !cfg.url) {
        return;
    }

    // Honor an explicit analytics opt-out; cookieless, so no decision = send.
    try {
        var raw = cfg.consentKey && window.localStorage.getItem(cfg.consentKey);
        if (raw) {
            var consent = JSON.parse(raw);
            if (consent && consent.analytics === false) {
                return;
            }
        }
    } catch (e) { /* localStorage blocked — proceed */ }

    var payload = JSON.stringify({
        path: location.pathname + location.search,
        ref: document.referrer || ''
    });

    function send() {
        try {
            var blob = new Blob([payload], { type: 'application/json' });
            if (navigator.sendBeacon && navigator.sendBeacon(cfg.url, blob)) {
                return;
            }
        } catch (e) { /* fall through */ }
        try {
            fetch(cfg.url, {
                method: 'POST',
                keepalive: true,
                headers: { 'Content-Type': 'application/json' },
                body: payload
            });
        } catch (e) { /* give up silently */ }
    }

    // Delegated so it covers markup added after load, and capture-phase so a
    // click still records when the handler navigates away immediately.
    if (cfg.clickUrl) {
        document.addEventListener('click', function (ev) {
            var el = ev.target && ev.target.closest && ev.target.closest('[data-track]');
            if (!el) { return; }
            var label = (el.getAttribute('data-track') || '').slice(0, 64);
            if (!label) { return; }
            var body = JSON.stringify({
                label: label,
                path: location.pathname + location.search
            });
            try {
                var b = new Blob([body], { type: 'application/json' });
                if (navigator.sendBeacon && navigator.sendBeacon(cfg.clickUrl, b)) {
                    return;
                }
            } catch (e) { /* fall through */ }
            try {
                fetch(cfg.clickUrl, {
                    method: 'POST',
                    keepalive: true,
                    headers: { 'Content-Type': 'application/json' },
                    body: body
                });
            } catch (e) { /* give up silently */ }
        }, true);
    }

    if (document.readyState === 'complete') {
        send();
    } else {
        window.addEventListener('load', function once() {
            window.removeEventListener('load', once);
            send();
        });
    }
})();
