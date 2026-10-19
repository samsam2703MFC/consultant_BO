/* ==========================================================================
   ERP — powiadomienia w dzwonku na górnym pasku.
   Licznik nieodczytanych + lista w menu dzwonka. Pod paskiem zostaje tylko
   krótki baner dla pilnych (severity = danger). Odświeżanie co 20 s.
   ========================================================================== */
(function () {
    'use strict';

    var bell = document.getElementById('atBell');
    if (!bell) return;

    var root = (bell.dataset.root || '').replace(/\/$/, '');
    var count = bell.querySelector('.at-bell-count');
    var list = bell.querySelector('.at-bell-list');
    var readAll = bell.querySelector('[data-notifications-read-all]');
    var banner = document.getElementById('appNotificationBanner');
    var known = null;   // identyfikatory z poprzedniego odświeżenia

    var ICONS = { danger: 'bi-exclamation-octagon-fill', warning: 'bi-exclamation-triangle-fill', info: 'bi-info-circle-fill' };

    function esc(value) {
        var el = document.createElement('div');
        el.textContent = value == null ? '' : String(value);
        return el.innerHTML;
    }

    function link(url) {
        return url && url.charAt(0) === '/' ? root + url : url;
    }

    function when(value) {
        var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(value || '');
        if (!m) return '';
        var d = new Date(+m[1], m[2] - 1, +m[3]);
        var today = new Date(); today.setHours(0, 0, 0, 0);
        var days = Math.round((today - d) / 86400000);
        var time = m[4] + ':' + m[5];
        if (days === 0) return 'dziś, ' + time;
        if (days === 1) return 'wczoraj, ' + time;
        return m[3] + '.' + m[2] + '.' + m[1] + ', ' + time;
    }

    function request(url, options) {
        options = options || {};
        var headers = { 'Accept': 'application/json' };
        if (options.body) headers['Content-Type'] = 'application/json';
        return fetch(url, { credentials: 'same-origin', method: options.method || 'GET', headers: headers, body: options.body })
            .then(function (response) {
                return response.json().catch(function () { return {}; }).then(function (body) {
                    body.ok = response.ok;
                    return body;
                });
            });
    }

    function acknowledge(id, via) {
        return request(root + '/ajax/app-notifications/' + encodeURIComponent(id) + '/acknowledge', {
            method: 'POST', body: JSON.stringify({ via: via })
        });
    }

    function item(n) {
        var sev = ICONS[n.severity] ? n.severity : 'info';
        var href = link(n.link_url);
        return '<div class="at-bell-item at-bell-' + sev + '">' +
            '<i class="bi ' + ICONS[sev] + '"></i>' +
            '<div class="at-bell-text">' +
                (href ? '<a href="' + esc(href) + '" data-notification-open="' + esc(n.id) + '">' + esc(n.title) + '</a>' : '<strong>' + esc(n.title) + '</strong>') +
                (n.body ? '<p>' + esc(n.body) + '</p>' : '') +
                '<small>' + esc(when(n.created_at)) + '</small>' +
            '</div>' +
            '<button type="button" class="at-bell-read" data-notification-read="' + esc(n.id) + '" title="Oznacz jako odczytane" aria-label="Oznacz jako odczytane"><i class="bi bi-check2"></i></button>' +
        '</div>';
    }

    function render(data, total) {
        total = typeof total === 'number' ? total : data.length;
        count.hidden = total === 0;
        count.textContent = total > 99 ? '99+' : String(total);
        bell.querySelector('.at-bell-toggle').setAttribute('aria-label', total ? 'Powiadomienia: ' + total + ' nowych' : 'Powiadomienia');
        readAll.hidden = total === 0;
        list.innerHTML = data.length
            ? data.map(item).join('') + (total > data.length ? '<p class="at-bell-more">i ' + (total - data.length) + ' starszych</p>' : '')
            : '<p class="at-bell-empty">Brak nowych powiadomień</p>';

        // Pod paskiem tylko pilne — reszta czeka w dzwonku.
        if (banner) {
            banner.innerHTML = data.filter(function (n) { return n.severity === 'danger'; }).map(function (n) {
                var href = link(n.link_url);
                return '<div class="alert alert-danger at-urgent d-flex align-items-center gap-2" role="alert">' +
                    '<i class="bi ' + ICONS.danger + '"></i>' +
                    '<span class="me-auto"><strong>' + esc(n.title) + '</strong>' + (n.body ? ' — ' + esc(n.body) : '') + '</span>' +
                    (href ? '<a class="btn btn-sm btn-outline-dark" href="' + esc(href) + '" data-notification-open="' + esc(n.id) + '">Przejdź</a>' : '') +
                    '<button class="btn btn-sm btn-outline-dark" type="button" data-notification-read="' + esc(n.id) + '">Odczytane</button>' +
                '</div>';
            }).join('');
        }

        var ids = data.map(function (n) { return String(n.id); });
        if (known && ids.some(function (id) { return known.indexOf(id) === -1; })) {
            bell.classList.remove('at-bell-ring');
            void bell.offsetWidth;
            bell.classList.add('at-bell-ring');
        }
        known = ids;
    }

    function refresh() {
        return request(root + '/ajax/app-notifications?limit=20')
            .then(function (r) { if (r.ok) render(r.data || [], r.total); })
            .catch(function () { /* chwilowy błąd odświeżania nie może psuć strony */ });
    }

    function onClick(e) {
        var read = e.target.closest('[data-notification-read]');
        var open = e.target.closest('[data-notification-open]');
        if (read) {
            e.preventDefault();
            acknowledge(read.dataset.notificationRead, 'read').then(refresh);
        } else if (open) {
            e.preventDefault();
            var go = function () { window.location.href = open.getAttribute('href'); };
            acknowledge(open.dataset.notificationOpen, 'read').then(go, go);
        }
    }

    bell.addEventListener('click', onClick);
    if (banner) banner.addEventListener('click', onClick);
    readAll.addEventListener('click', function () {
        request(root + '/ajax/app-notifications/acknowledge-all', { method: 'POST' }).then(refresh);
    });

    refresh();
    window.setInterval(refresh, 20000);
})();
