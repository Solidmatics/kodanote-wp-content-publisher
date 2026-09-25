/**
 * Modified for Kodanote on 2026-09-25. Licensed under GPL-2.0-or-later; see LICENSE and NOTICE.md.
 * Kodanote Conversion Tracker
 *
 * Intercepts Google Ads (gtag / dataLayer) and Facebook Pixel (fbq)
 * conversion events and beacons them to the local WP REST proxy,
 * which forwards to the Kodanote API server-side.
 *
 * SAFETY INVARIANT: The original gtag/fbq/dataLayer.push calls are
 * NEVER wrapped in try/catch -- they execute exactly as they would
 * without this script. Only our own tracking logic is guarded.
 */
(function () {
    'use strict';

    var currentScript = document.currentScript || {};
    var config = window.kodanoteConversionTracker || {};
    if (!config.endpoint && currentScript.getAttribute) {
        config.endpoint = currentScript.getAttribute('data-endpoint') || '';
        config.token = currentScript.getAttribute('data-token') || '';
        config.pageviewEndpoint = currentScript.getAttribute('data-pageview-endpoint') || '';
        config.articleId = currentScript.getAttribute('data-article-id') || '';
    }
    if (!config.endpoint) return;

    var CONVERSION_EVENTS_GTAG = ['conversion', 'purchase'];
    var CONVERSION_EVENTS_FBQ = ['Purchase', 'Subscribe', 'CompleteRegistration', 'Lead', 'StartTrial'];
    var DATALAYER_EVENTS = ['purchase', 'conversion'];
    var ATTRIBUTION_WINDOW_MS = 30 * 24 * 60 * 60 * 1000;
    var VISITOR_STORAGE_KEY = 'kodanote_visitor_id';
    var ATTRIBUTION_STORAGE_KEY = 'kodanote_last_article_attribution';

    var queue = [];
    var flushTimer = null;
    var seen = {};
    var visitorId = getVisitorId();
    var currentArticleView = null;

    captureInboundAttribution();
    recordCurrentArticleView();

    function dedupeKey(source, type, value) {
        return source + '|' + type + '|' + (value || '') + '|' + Math.floor(Date.now() / 2000);
    }

    function enqueue(source, type, params) {
        var key = dedupeKey(source, type, params && params.value);
        if (seen[key]) return;
        seen[key] = true;

        var val = params && params.value ? parseFloat(params.value) : null;
        if (val !== null && (isNaN(val) || !isFinite(val))) val = null;
        var attribution = getStoredAttribution();

        var event = {
            event_source: source,
            event_type: String(type).substring(0, 100),
            event_value: val,
            event_currency: params && (params.currency || params.Currency) || null,
            page_url: (location.pathname + location.search).substring(0, 500),
            referrer_url: document.referrer ? document.referrer.substring(0, 500) : null,
            visitor_id: visitorId,
            timestamp: new Date().toISOString()
        };

        if (attribution) {
            event.attributed_article_id = attribution.article_id;
            event.article_viewed_at = attribution.viewed_at;
            event.article_attribution_source = attribution.source || null;
        }

        queue.push(event);

        if (!flushTimer) {
            flushTimer = setTimeout(flush, 1500);
        }
    }

    function storageGet(key) {
        try {
            return localStorage.getItem(key);
        } catch (e) {
            return null;
        }
    }

    function storageSet(key, value) {
        try {
            localStorage.setItem(key, value);
        } catch (e) {}
    }

    function getVisitorId() {
        var inbound = getQueryParam('kodanote_vid');
        if (isValidVisitorId(inbound)) {
            storageSet(VISITOR_STORAGE_KEY, inbound);
            return inbound;
        }

        var existing = storageGet(VISITOR_STORAGE_KEY);
        if (isValidVisitorId(existing)) return existing;

        var generated = 'kn_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 12);
        storageSet(VISITOR_STORAGE_KEY, generated);
        return generated;
    }

    function isValidVisitorId(value) {
        return typeof value === 'string' && value.length > 0 && value.length <= 128 && /^[A-Za-z0-9._:-]+$/.test(value);
    }

    function getQueryParam(name) {
        try {
            return new URLSearchParams(window.location.search).get(name);
        } catch (e) {
            return null;
        }
    }

    function captureInboundAttribution() {
        var articleId = parseInt(getQueryParam('kodanote_aid'), 10);
        if (!articleId || articleId < 1) return;

        var viewedAt = getQueryParam('kodanote_avt') || new Date().toISOString();
        var viewedTime = Date.parse(viewedAt);
        if (!viewedTime || Date.now() - viewedTime > ATTRIBUTION_WINDOW_MS) return;

        storeAttribution({
            article_id: articleId,
            viewed_at: new Date(viewedTime).toISOString(),
            source: (getQueryParam('kodanote_src') || 'hosted_blog_link').substring(0, 50)
        });
    }

    function recordCurrentArticleView() {
        var articleId = parseInt(config.articleId, 10);
        if (!articleId || articleId < 1) return;

        var viewedAt = new Date().toISOString();
        currentArticleView = {
            articleId: articleId,
            viewedAt: viewedAt,
            startedAt: Date.now(),
            pageviewSent: false,
            timeOnPageSent: false
        };
        storeAttribution({
            article_id: articleId,
            viewed_at: viewedAt,
            source: 'wordpress'
        });
        sendArticlePageview(articleId, viewedAt);
    }

    function storeAttribution(attribution) {
        storageSet(ATTRIBUTION_STORAGE_KEY, JSON.stringify(attribution));
    }

    function getStoredAttribution() {
        try {
            var raw = storageGet(ATTRIBUTION_STORAGE_KEY);
            if (!raw) return null;
            var attribution = JSON.parse(raw);
            var articleId = parseInt(attribution.article_id, 10);
            var viewedTime = Date.parse(attribution.viewed_at);
            if (!articleId || !viewedTime || Date.now() - viewedTime > ATTRIBUTION_WINDOW_MS) return null;
            return {
                article_id: articleId,
                viewed_at: new Date(viewedTime).toISOString(),
                source: attribution.source ? String(attribution.source).substring(0, 50) : null
            };
        } catch (e) {
            return null;
        }
    }

    function sendArticlePageview(articleId, viewedAt) {
        if (!config.pageviewEndpoint) return;

        var sessionKey = 'kodanote_pv_sent_' + articleId + '_' + location.pathname;
        try {
            if (sessionStorage.getItem(sessionKey)) return;
            sessionStorage.setItem(sessionKey, '1');
        } catch (e) {}

        var payload = JSON.stringify({
            article_id: articleId,
            visitor_id: visitorId,
            event_type: 'pageview',
            page_url: (location.pathname + location.search).substring(0, 500),
            referrer_url: document.referrer ? document.referrer.substring(0, 500) : null,
            timestamp: viewedAt
        });

        try {
            if (navigator.sendBeacon) {
                var blob = new Blob([payload], { type: 'application/json' });
                if (navigator.sendBeacon(config.pageviewEndpoint, blob)) {
                    markArticlePageviewSent(articleId);
                    return;
                }
            }
            if (window.fetch) {
                fetch(config.pageviewEndpoint, {
                    method: 'POST',
                    body: payload,
                    headers: { 'Content-Type': 'application/json' },
                    keepalive: true,
                    credentials: 'same-origin'
                }).then(function (response) {
                    if (response.ok) markArticlePageviewSent(articleId);
                }).catch(function () {});
            }
        } catch (e) {}
    }

    function markArticlePageviewSent(articleId) {
        if (!currentArticleView || currentArticleView.articleId !== articleId) return;
        currentArticleView.pageviewSent = true;
    }

    function sendArticleTimeOnPage() {
        if (!currentArticleView || !currentArticleView.pageviewSent || currentArticleView.timeOnPageSent) return;
        if (!config.pageviewEndpoint) return;

        var durationSeconds = Math.round((Date.now() - currentArticleView.startedAt) / 1000);
        if (durationSeconds < 3) return;
        if (durationSeconds > 3600) durationSeconds = 3600;

        currentArticleView.timeOnPageSent = true;
        var payload = JSON.stringify({
            article_id: currentArticleView.articleId,
            visitor_id: visitorId,
            event_type: 'time_on_page',
            duration_seconds: durationSeconds,
            page_url: (location.pathname + location.search).substring(0, 500),
            referrer_url: document.referrer ? document.referrer.substring(0, 500) : null,
            timestamp: currentArticleView.viewedAt
        });

        try {
            if (navigator.sendBeacon) {
                var blob = new Blob([payload], { type: 'application/json' });
                if (navigator.sendBeacon(config.pageviewEndpoint, blob)) return;
            }
            if (window.fetch) {
                fetch(config.pageviewEndpoint, {
                    method: 'POST',
                    body: payload,
                    headers: { 'Content-Type': 'application/json' },
                    keepalive: true,
                    credentials: 'same-origin'
                }).catch(function () {});
            }
        } catch (e) {}
    }

    function flush() {
        flushTimer = null;
        if (!queue.length) return;

        var events = queue.splice(0, 20);
        var payload = JSON.stringify({
            token: config.token || '',
            events: events
        });

        try {
            if (navigator.sendBeacon) {
                var blob = new Blob([payload], { type: 'application/json' });
                if (navigator.sendBeacon(config.endpoint, blob)) {
                    return;
                }
            }
            if (window.fetch) {
                fetch(config.endpoint, {
                    method: 'POST',
                    body: payload,
                    headers: { 'Content-Type': 'application/json' },
                    keepalive: true,
                    credentials: 'same-origin'
                }).catch(function () {});
                return;
            }
            var xhr = new XMLHttpRequest();
            xhr.open('POST', config.endpoint, true);
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.send(payload);
        } catch (e) {}
    }

    // --- gtag() interception ---
    function wrapGtag() {
        if (!window.gtag || window.__kodanote_gtag_wrapped) return;
        if (typeof window.gtag !== 'function') return;
        var original = window.gtag;
        window.__kodanote_gtag_wrapped = true;
        window.gtag = function () {
            // Original runs UNGUARDED -- behaves exactly as if we weren't here
            var result = original.apply(this, arguments);
            // Our tracking is guarded -- failures are silent and harmless
            try {
                if (arguments[0] === 'event' && CONVERSION_EVENTS_GTAG.indexOf(arguments[1]) !== -1) {
                    enqueue('google_ads', arguments[1], arguments[2] || {});
                }
            } catch (e) {}
            return result;
        };
    }

    // --- dataLayer.push() interception ---
    var wrappedDlInstance = null;
    function wrapDataLayer() {
        var dl = window.dataLayer;
        if (!dl || !Array.isArray(dl)) return;
        if (dl === wrappedDlInstance) return;
        wrappedDlInstance = dl;
        var originalPush = dl.push;
        dl.push = function () {
            // Original runs UNGUARDED
            var result = originalPush.apply(dl, arguments);
            // Our tracking is guarded
            try {
                for (var i = 0; i < arguments.length; i++) {
                    var obj = arguments[i];
                    if (obj && typeof obj === 'object') {
                        var evt = obj.event || (obj[0] === 'event' ? obj[1] : null);
                        if (evt && DATALAYER_EVENTS.indexOf(evt.toLowerCase ? evt.toLowerCase() : evt) !== -1) {
                            var ecom = obj.ecommerce || obj;
                            enqueue('dataLayer', evt, {
                                value: ecom.value || null,
                                currency: ecom.currency || null
                            });
                        }
                    }
                }
            } catch (e) {}
            return result;
        };
    }

    // --- fbq() interception ---
    // Keep window.fbq itself intact. The standard Meta bootstrap stub closes over
    // its original function object; replacing window.fbq before fbevents.js adds
    // callMethod can leave real pixel calls stuck in the old queue.
    function wrapFbq() {
        if (!window.fbq || window.__kodanote_fbq_wrapped) return;
        if (typeof window.fbq !== 'function') return;
        if (typeof window.fbq.callMethod !== 'function') return;

        var originalCallMethod = window.fbq.callMethod;
        window.__kodanote_fbq_wrapped = true;
        window.fbq.callMethod = function () {
            // Original runs UNGUARDED
            var result = originalCallMethod.apply(this, arguments);
            // Our tracking is guarded
            try {
                if (arguments[0] === 'track' && CONVERSION_EVENTS_FBQ.indexOf(arguments[1]) !== -1) {
                    enqueue('facebook', arguments[1], arguments[2] || {});
                }
            } catch (e) {}
            return result;
        };
    }

    // --- Safe wrapper: if wrapping itself fails, the original global is untouched ---
    function safeWrap(fn) {
        try { fn(); } catch (e) {}
    }

    function tryWrapAll() {
        safeWrap(wrapGtag);
        safeWrap(wrapDataLayer);
        safeWrap(wrapFbq);
    }

    // Wrap immediately if already loaded
    tryWrapAll();

    // Use defineProperty to catch late-loading scripts that create gtag/fbq/dataLayer
    function watchProperty(name, wrapFn) {
        if (window[name]) return;
        var value;
        var insideSetter = false;
        try {
            Object.defineProperty(window, name, {
                configurable: true,
                get: function () { return value; },
                set: function (v) {
                    if (insideSetter) { value = v; return; }
                    insideSetter = true;
                    value = v;
                    // Restore normal property FIRST, before calling wrapFn.
                    // Even if wrapFn throws, the property is restored and usable.
                    try {
                        Object.defineProperty(window, name, {
                            configurable: true,
                            writable: true,
                            value: v
                        });
                    } catch (e) {
                        // defineProperty restore failed; value is still readable
                        // via the getter. Don't try window[name]=v (re-enters setter).
                    }
                    insideSetter = false;
                    safeWrap(wrapFn);
                }
            });
        } catch (e) {
            // defineProperty on window not supported -- poll fallback handles it
        }
    }

    watchProperty('gtag', wrapGtag);
    watchProperty('fbq', wrapFbq);
    watchProperty('dataLayer', wrapDataLayer);

    // Fallback poll for environments where defineProperty on window fails
    var pollCount = 0;
    var pollInterval = setInterval(function () {
        tryWrapAll();
        pollCount++;
        if (pollCount > 20) clearInterval(pollInterval);
    }, 500);

    // Flush on page unload
    try {
        window.addEventListener('pagehide', sendArticleTimeOnPage);
        window.addEventListener('pagehide', flush);
        window.addEventListener('beforeunload', flush);
    } catch (e) {}
})();
