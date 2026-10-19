/* ==========================================================================
   ERP — recherche globale de la barre du haut (GET /ajax/search?q=).
   « / » place le curseur dans le champ ; flèches haut/bas pour choisir,
   Entrée pour ouvrir, Échap pour fermer. Minimum 2 caractères.
   ========================================================================== */
(function () {
    'use strict';

    var box = document.getElementById('atSearch');
    if (!box) return;

    var root = (box.getAttribute('data-root') || '').replace(/\/$/, '');
    var input = box.querySelector('.at-search-input');
    var panel = box.querySelector('.at-search-panel');
    var MIN = 2;
    var timer = null, controller = null, seq = 0, active = -1, lastQuery = '';

    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // Surlignage sans casser l'échappement : on découpe le texte brut.
    function mark(text, q) {
        text = String(text == null ? '' : text);
        var needle = q.replace(/^#/, '').toLowerCase();
        if (!needle) return esc(text);
        var lower = text.toLowerCase(), out = '', from = 0, i;
        while ((i = lower.indexOf(needle, from)) !== -1) {
            out += esc(text.slice(from, i)) + '<mark>' + esc(text.slice(i, i + needle.length)) + '</mark>';
            from = i + needle.length;
        }
        return out + esc(text.slice(from));
    }

    function options() { return panel.querySelectorAll('[role="option"]'); }

    function open() {
        panel.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        box.classList.add('is-open');
    }

    function close() {
        panel.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        box.classList.remove('is-open');
        active = -1;
    }

    function setActive(i) {
        var list = options();
        if (!list.length) return;
        if (i < 0) i = list.length - 1;
        if (i >= list.length) i = 0;
        list.forEach(function (o, k) {
            o.classList.toggle('is-active', k === i);
            o.setAttribute('aria-selected', k === i ? 'true' : 'false');
        });
        active = i;
        input.setAttribute('aria-activedescendant', list[i].id);
        list[i].scrollIntoView({ block: 'nearest' });
    }

    function message(html) {
        panel.innerHTML = '<p class="at-search-msg">' + html + '</p>';
        active = -1;
        open();
    }

    function render(data, q) {
        if (!data.groups || !data.groups.length) {
            message('Brak wyników dla „' + esc(q) + '”.');
            return;
        }
        var n = 0, html = '';
        data.groups.forEach(function (g) {
            html += '<div class="at-search-group" role="group" aria-label="' + esc(g.label) + '">' +
                '<div class="at-search-head"><i class="bi ' + esc(g.icon) + '" aria-hidden="true"></i>' + esc(g.label) + '</div>';
            g.items.forEach(function (it) {
                html += '<a class="at-search-item" role="option" aria-selected="false" id="atSearchOpt' + (n++) + '" href="' + esc(root + it.url) + '">' +
                    '<span class="at-search-title">' + mark(it.title, q) + '</span>' +
                    (it.sub ? '<span class="at-search-sub">' + mark(it.sub, q) + '</span>' : '') + '</a>';
            });
            html += '</div>';
        });
        html += '<div class="at-search-foot"><span><kbd>↑</kbd><kbd>↓</kbd> wybór</span><span><kbd>Enter</kbd> otwórz</span><span><kbd>Esc</kbd> zamknij</span></div>';
        panel.innerHTML = html;
        active = -1;
        open();
        setActive(0);
    }

    function search(q) {
        if (controller) controller.abort();
        controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        var mine = ++seq;
        box.classList.add('is-loading');
        fetch(root + '/ajax/search?q=' + encodeURIComponent(q), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
            signal: controller ? controller.signal : undefined
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (body) { body.ok = r.ok; return body; });
        }).then(function (data) {
            if (mine !== seq) return;
            box.classList.remove('is-loading');
            if (!data.ok) { message(esc(data.message || 'Wyszukiwanie chwilowo nie działa.')); return; }
            if (document.activeElement === input) render(data, q);
        }).catch(function (err) {
            if (err && err.name === 'AbortError') return;
            box.classList.remove('is-loading');
            message('Wyszukiwanie chwilowo nie działa.');
        });
    }

    input.addEventListener('input', function () {
        clearTimeout(timer);
        var q = input.value.trim();
        lastQuery = q;
        if (!q) { close(); return; }
        if (q.replace(/^#/, '').length < MIN && !/^#?\d$/.test(q)) {
            message('Wpisz co najmniej ' + MIN + ' znaki.');
            return;
        }
        timer = setTimeout(function () { search(q); }, 180);
    });

    input.addEventListener('focus', function () {
        if (panel.innerHTML && lastQuery.length >= MIN) open();
    });

    input.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown') { e.preventDefault(); if (panel.hidden && lastQuery) open(); setActive(active + 1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(active - 1); }
        else if (e.key === 'Enter') {
            // Le gestionnaire global de base.twig bloque Entrée dans les champs texte : celui-ci est de type « search ».
            var list = options();
            if (!list.length) return;
            e.preventDefault();
            window.location.href = list[active >= 0 ? active : 0].href;
        }
        else if (e.key === 'Escape') {
            e.preventDefault();
            if (!panel.hidden) close();
            else { input.value = ''; lastQuery = ''; input.blur(); }
        }
        else if (e.key === 'Tab') close();
    });

    panel.addEventListener('mousemove', function (e) {
        var o = e.target.closest('[role="option"]');
        if (!o) return;
        var list = Array.prototype.indexOf.call(options(), o);
        if (list !== active) setActive(list);
    });
    // Le clic sur un résultat ne doit pas fermer le panneau avant la navigation.
    panel.addEventListener('mousedown', function (e) { e.preventDefault(); });

    document.addEventListener('click', function (e) {
        if (!box.contains(e.target)) close();
    });

    // « / » : aller à la recherche, sauf pendant une saisie.
    document.addEventListener('keydown', function (e) {
        if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) return;
        var t = e.target;
        if (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName))) return;
        e.preventDefault();
        input.focus();
        input.select();
    });
})();
