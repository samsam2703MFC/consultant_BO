/* ==========================================================================
   ERP — powiadomienia w dzwonku na górnym pasku.
   Licznik nieodczytanych + lista w menu dzwonka. Pod paskiem zostaje tylko
   wąska, dyskretna linia dla pilnych (severity = danger) [268] : kropka, liczba,
   pierwszy tytuł ; kilka pilnych rozwija się w zwartą listę. Odświeżanie co 20 s.
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

    var urgentOpen = false;
    try { urgentOpen = window.sessionStorage.getItem('atUrgentOpen') === '1'; } catch (e) { /* tryb prywatny */ }

    function urgentCount(n) {
        var few = n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 12 || n % 100 > 14);
        return n + ' ' + (n === 1 || few ? 'pilne' : 'pilnych');
    }

    function urgentActions(n) {
        var href = link(n.link_url);
        return (href ? '<a class="at-urgent-go" href="' + esc(href) + '" data-notification-open="' + esc(n.id) + '">Przejdź</a>' : '') +
            '<button class="at-urgent-read" type="button" data-notification-read="' + esc(n.id) + '" title="Oznacz jako odczytane" aria-label="Oznacz jako odczytane"><i class="bi bi-check2"></i></button>';
    }

    function urgentText(n) {
        return '<span class="at-urgent-text"><strong>' + esc(n.title) + '</strong>' + (n.body ? ' — ' + esc(n.body) : '') + '</span>';
    }

    function strip(urgent) {
        if (urgent.length === 1) {
            return '<div class="at-urgent-strip" role="status"><div class="at-urgent-row"><span class="at-urgent-dot" aria-hidden="true"></span>' +
                urgentText(urgent[0]) + urgentActions(urgent[0]) + '</div></div>';
        }
        return '<div class="at-urgent-strip' + (urgentOpen ? ' is-open' : '') + '" role="status">' +
            '<button type="button" class="at-urgent-toggle" data-urgent-toggle aria-expanded="' + (urgentOpen ? 'true' : 'false') + '">' +
                '<span class="at-urgent-dot" aria-hidden="true"></span><span class="at-urgent-count">' + urgentCount(urgent.length) + '</span>' +
                '<span class="at-urgent-first">' + esc(urgent[0].title) + '</span><span class="at-urgent-more">+' + (urgent.length - 1) + '</span>' +
                '<i class="bi bi-chevron-down" aria-hidden="true"></i></button>' +
            '<div class="at-urgent-list">' + urgent.map(function (n) { return '<div class="at-urgent-row">' + urgentText(n) + urgentActions(n) + '</div>'; }).join('') + '</div>' +
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

        // [268] Pod paskiem tylko pilne, dyskretnie : jedna wąska linia (kropka, liczba, pierwszy tytuł) ; kilka pilnych
        // rozwija się po dotknięciu w zwartą listę. Reszta czeka w dzwonku.
        if (banner) {
            var urgent = data.filter(function (n) { return n.severity === 'danger'; });
            banner.innerHTML = urgent.length ? strip(urgent) : '';
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
        var toggle = e.target.closest('[data-urgent-toggle]');
        if (toggle) {
            urgentOpen = !urgentOpen;
            try { window.sessionStorage.setItem('atUrgentOpen', urgentOpen ? '1' : '0'); } catch (x) { /* tryb prywatny */ }
            toggle.parentNode.classList.toggle('is-open', urgentOpen);
            toggle.setAttribute('aria-expanded', urgentOpen ? 'true' : 'false');
            return;
        }
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

    // Liczniki przy pozycjach menu i skrótach górnego paska: <span data-count="complaints">.
    // Skrót „Rentowność” w górnym pasku: koszt personelu / sprzedaż bieżącego miesiąca wobec celu.
    function counters() {
        if (!document.querySelector('[data-count], [data-profit]')) return;
        request(root + '/ajax/rail-counters').then(function (r) {
            if (!r.ok) return;
            document.querySelectorAll('[data-count]').forEach(function (el) {
                var n = parseInt(r[el.dataset.count], 10) || 0;
                el.textContent = n > 99 ? '99+' : String(n);
                el.hidden = n === 0;
            });
            var p = r.profit;
            document.querySelectorAll('[data-profit]').forEach(function (el) {
                var value = el.querySelector('[data-profit-value]');
                el.classList.remove('ok', 'bad');
                if (!p || !value) return;
                value.textContent = String(p.staff_pct).replace('.', ',') + ' % / cel ' + String(p.target).replace('.', ',') + ' %';
                el.classList.add(p.ok ? 'ok' : 'bad');
                el.title = p.ok ? 'Koszt personelu w celu' : 'Brakuje ' + Number(p.missing).toLocaleString('pl-PL') + ' € sprzedaży do celu';
            });
        }).catch(function () {});
    }

    refresh();
    counters();
    window.setInterval(refresh, 20000);
    window.setInterval(counters, 60000);
})();
