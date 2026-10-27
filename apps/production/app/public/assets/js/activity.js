/* ==========================================================================
   ERP — Aktywność (Ustawienia administratora « Aktywność w ERP »).
   Na każdej stronie : wyświetlenie strony, czas aktywny (karta widoczna i ruch
   myszy / klawiatury / przewijania w ostatniej minucie) i kliknięcia według
   elementu : etykieta przycisku albo trasa linku. Nigdy treść pól formularzy.
   Puls co 30 s i przy zamknięciu strony (sendBeacon).
   ========================================================================== */
(function () {
    'use strict';
    var script = document.currentScript;
    var root = (script && script.dataset.root) || '';
    var IDLE_MS = 60000;
    var id = null, active = 0, clicks = 0, targets = {}, sentActive = -1, sentClicks = -1;
    var lastInput = Date.now();

    var route = function (path) {
        return path.replace(/\/+$/, '').split('/').map(function (s) { return /\d/.test(s) ? '{id}' : s; }).join('/') || '/';
    };
    var relative = function (pathname) {
        var base = new URL(root || '/', location.origin).pathname.replace(/\/+$/, '');
        return base && pathname.indexOf(base) === 0 ? pathname.slice(base.length) || '/' : pathname;
    };
    var label = function (el) {
        if (el.matches('a[href]')) {
            var href = el.getAttribute('href');
            if (href && href !== '#' && href.indexOf('javascript:') !== 0) {
                var u = new URL(href, location.href);
                if (u.origin === location.origin) return '→ ' + route(relative(u.pathname));
            }
        }
        var text = el.getAttribute('aria-label') || el.getAttribute('title') || (el.matches('input') ? el.value : el.textContent) || '';
        text = text.replace(/\s+/g, ' ').trim();
        return text ? text.slice(0, 60) : null;
    };

    ['mousemove', 'keydown', 'scroll', 'wheel', 'touchstart', 'click'].forEach(function (ev) {
        window.addEventListener(ev, function () { lastInput = Date.now(); }, {passive: true, capture: true});
    });
    document.addEventListener('click', function (e) {
        var el = e.target.closest && e.target.closest('a[href], button, [role=button], [role=tab], input[type=submit], input[type=button], summary, .btn');
        if (!el || el.closest('[data-no-track]')) return;
        clicks++;
        var l = label(el);
        if (l) targets[l] = (targets[l] || 0) + 1;
    }, true);

    setInterval(function () {
        if (document.visibilityState === 'visible' && Date.now() - lastInput < IDLE_MS) active++;
    }, 1000);

    var payload = function () {
        var body = {id: id, active_s: active, clicks: clicks, targets: targets};
        targets = {};
        sentActive = active;
        sentClicks = clicks;
        return JSON.stringify(body);
    };
    var beat = function (leaving) {
        if (!id || (active === sentActive && clicks === sentClicks && !Object.keys(targets).length)) return;
        var url = root + '/ajax/activity/beat';
        var body = payload();
        if (leaving && navigator.sendBeacon) {
            navigator.sendBeacon(url, new Blob([body], {type: 'text/plain'}));
            return;
        }
        fetch(url, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'Accept': 'application/json'}, body: body, keepalive: true})
            .catch(function () { /* sieć : następny puls */ });
    };

    fetch(root + '/ajax/activity/view', {
        method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
        body: JSON.stringify({path: location.pathname, title: document.title.slice(0, 160)}),
    }).then(function (r) { return r.ok ? r.json() : null; }).then(function (r) {
        if (!r || !r.data) return;
        id = r.data.id;
        setInterval(function () { beat(false); }, 30000);
    }).catch(function () { /* bez zapisu */ });

    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') beat(true); });
    window.addEventListener('pagehide', function () { beat(true); });
})();
