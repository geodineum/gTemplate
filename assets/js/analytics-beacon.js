/**
 * Cookieless visitor analytics beacon.
 *
 * Fires one POST to the gTemplate analytics/hit endpoint per page view. No
 * cookies, no device storage — the server derives a daily-rotating hash. An
 * explicit analytics opt-out (cookie-consent banner) suppresses it.
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

    if (document.readyState === 'complete') {
        send();
    } else {
        window.addEventListener('load', function once() {
            window.removeEventListener('load', once);
            send();
        });
    }
})();
