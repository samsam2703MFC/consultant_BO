/* ==========================================================================
   ERP — longues listes : recherche, pages, menu « ⋯ » des actions de ligne
   et confirmation avant une action risquée. Chargé sur toutes les pages ;
   ne fait rien tant qu'aucun élément ne le demande.

   <table data-at-table>                    recherche « Szukaj… » + pages
       data-at-table-size="50"              taille de page par défaut (25)
       data-at-table-search="false"         sans champ de recherche
   <tr data-at-group>                       en-tête de groupe (aussi : ligne
                                            d'une seule cellule colspan) —
                                            visible si une de ses lignes l'est
   <div class="at-more">                    menu « ⋯ » (bouton .at-more-toggle
                                            + .at-more-menu), placé en fixe :
                                            jamais rogné par .table-responsive
   [data-at-confirm="Usunąć…?"]             confirmation avant le clic

   Les lignes masquées par un autre script (style.display = 'none', hidden,
   d-none) restent masquées et ne comptent pas dans les pages. Aucune donnée
   envoyée au serveur ne change : les lignes hors page sont seulement
   cachées, leurs champs restent dans le formulaire.
   ========================================================================== */
(function () {
    'use strict';

    var SIZES = [25, 50, 100, 0];   // 0 = wszystkie

    function norm(s) {
        return String(s || '').toLowerCase().replace(/ł/g, 'l')
            .normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/\s+/g, ' ');
    }

    function store(key, value) {
        try {
            if (value === undefined) return window.localStorage.getItem(key);
            window.localStorage.setItem(key, value);
        } catch (e) { /* stockage indisponible : réglage non retenu */ }
        return null;
    }

    function plural(n, one, few, many) {
        var n10 = n % 10, n100 = n % 100;
        if (n === 1) return one;
        if (n10 >= 2 && n10 <= 4 && (n100 < 12 || n100 > 14)) return few;
        return many;
    }

    function el(tag, cls, html) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (html != null) e.innerHTML = html;
        return e;
    }

    /* --- Tableau paginé ----------------------------------------------------- */
    function AtTable(table, index) {
        this.table = table;
        this.page = 1;
        this.query = '';
        this.key = 'at-table-size:' + location.pathname + ':' + index;
        var def = parseInt(table.getAttribute('data-at-table-size'), 10);
        var saved = parseInt(store(this.key), 10);
        this.size = SIZES.indexOf(saved) >= 0 ? saved : (SIZES.indexOf(def) >= 0 ? def : 25);
        this.build();
        this.render();
        this.watch();
    }

    AtTable.prototype.build = function () {
        var self = this, table = this.table;
        var anchor = table.closest('.table-responsive') || table;
        var withSearch = table.getAttribute('data-at-table-search') !== 'false';

        var bar = el('div', 'at-table-bar');
        if (withSearch) {
            var label = el('label', 'at-table-search');
            label.innerHTML = '<i class="bi bi-search" aria-hidden="true"></i>';
            var input = el('input', 'form-control');
            input.type = 'search';
            input.placeholder = 'Szukaj…';
            input.setAttribute('aria-label', 'Szukaj w tabeli');
            label.appendChild(input);
            bar.appendChild(label);
            var timer = null;
            input.addEventListener('input', function () {
                clearTimeout(timer);
                timer = setTimeout(function () { self.query = norm(input.value).trim(); self.page = 1; self.render(); }, 120);
            });
            this.input = input;
        }
        this.info = el('span', 'at-table-info');
        bar.appendChild(this.info);

        var sizeLabel = el('label', 'at-table-size', 'Pokaż ');
        var select = el('select', 'form-select form-select-sm');
        select.setAttribute('aria-label', 'Liczba wierszy na stronie');
        SIZES.forEach(function (n) {
            var o = el('option', null, n ? String(n) : 'wszystkie');
            o.value = String(n);
            if (n === self.size) o.selected = true;
            select.appendChild(o);
        });
        select.addEventListener('change', function () {
            self.size = parseInt(select.value, 10) || 0;
            store(self.key, String(self.size));
            self.page = 1;
            self.render();
        });
        sizeLabel.appendChild(select);
        bar.appendChild(sizeLabel);

        this.pager = el('nav', 'at-table-pager');
        this.pager.setAttribute('aria-label', 'Strony tabeli');
        this.pager.addEventListener('click', function (e) {
            var b = e.target.closest('button[data-page]');
            if (!b || b.disabled) return;
            self.page = parseInt(b.getAttribute('data-page'), 10);
            self.render();
            var top = bar.getBoundingClientRect().top;
            if (top < 0) bar.scrollIntoView({ block: 'start' });
        });

        anchor.parentNode.insertBefore(bar, anchor);
        anchor.parentNode.insertBefore(this.pager, anchor.nextSibling);
        this.bar = bar;
    };

    AtTable.prototype.rows = function () {
        var out = [];
        Array.prototype.forEach.call(this.table.tBodies, function (tb) {
            Array.prototype.forEach.call(tb.rows, function (tr) {
                if (!tr.hasAttribute('data-at-none')) out.push(tr);
            });
        });
        return out;
    };

    function isGroup(tr) {
        if (tr.hasAttribute('data-at-group')) return true;
        return tr.cells.length === 1 && tr.cells[0].colSpan > 1;
    }

    function hiddenElsewhere(tr) {
        return tr.hidden || tr.style.display === 'none' || tr.classList.contains('d-none');
    }

    AtTable.prototype.render = function () {
        var rows = this.rows(), data = [], groupOf = new Map(), group = null, q = this.query;
        rows.forEach(function (tr) {
            if (isGroup(tr)) { group = tr; return; }
            data.push(tr);
            if (group) groupOf.set(tr, group);
        });

        // Tableau sans vraies lignes (message « Brak … ») : rien à paginer.
        if (!data.length) {
            this.bar.hidden = true;
            this.pager.hidden = true;
            rows.forEach(function (tr) { tr.classList.remove('at-row-off'); });
            this.none(false);
            return;
        }
        this.bar.hidden = false;

        var pool = data.filter(function (tr) { return !hiddenElsewhere(tr); });
        var matched = q ? pool.filter(function (tr) {
            var extra = tr.getAttribute('data-search') || '';
            return norm(tr.textContent + ' ' + extra).indexOf(q) >= 0;
        }) : pool;

        var size = this.size, pages = size ? Math.max(1, Math.ceil(matched.length / size)) : 1;
        if (this.page > pages) this.page = pages;
        if (this.page < 1) this.page = 1;
        var from = size ? (this.page - 1) * size : 0;
        var shown = new Set(size ? matched.slice(from, from + size) : matched);
        var shownGroups = new Set();

        data.forEach(function (tr) {
            var on = shown.has(tr);
            tr.classList.toggle('at-row-off', !on);
            if (on && groupOf.has(tr)) shownGroups.add(groupOf.get(tr));
        });
        rows.forEach(function (tr) {
            if (isGroup(tr)) tr.classList.toggle('at-row-off', !shownGroups.has(tr));
        });

        this.none(!matched.length);

        var total = pool.length, n = matched.length;
        var info;
        if (q) info = 'Znaleziono ' + n + ' z ' + total;
        else if (size && total > size) info = (from + 1) + '–' + Math.min(from + size, n) + ' z ' + total;
        else info = total + ' ' + plural(total, 'pozycja', 'pozycje', 'pozycji');
        this.info.textContent = info;

        this.renderPager(pages);
    };

    AtTable.prototype.none = function (show) {
        var tb = this.table.tBodies[0];
        if (!tb) return;
        var row = tb.querySelector('tr[data-at-none]');
        if (!show) { if (row) row.remove(); return; }
        if (!row) {
            var cols = 0;
            var head = this.table.tHead && this.table.tHead.rows[0];
            if (head) Array.prototype.forEach.call(head.cells, function (c) { cols += c.colSpan || 1; });
            row = el('tr');
            row.setAttribute('data-at-none', '');
            var td = el('td', 'at-table-none');
            td.colSpan = Math.max(cols, 1);
            row.appendChild(td);
            tb.appendChild(row);
        }
        row.cells[0].textContent = 'Brak wyników dla „' + (this.input ? this.input.value.trim() : '') + '”.';
    };

    AtTable.prototype.renderPager = function (pages) {
        var p = this.page, pager = this.pager;
        pager.hidden = pages <= 1;
        if (pages <= 1) { pager.innerHTML = ''; return; }
        var list = [1];
        for (var i = Math.max(2, p - 2); i <= Math.min(pages - 1, p + 2); i++) list.push(i);
        list.push(pages);
        var html = '<button type="button" data-page="' + (p - 1) + '"' + (p === 1 ? ' disabled' : '') + ' aria-label="Poprzednia strona"><i class="bi bi-chevron-left"></i></button>';
        var last = 0;
        list.forEach(function (n) {
            if (n === last) return;
            if (n - last > 1) html += '<span class="at-table-gap">…</span>';
            html += '<button type="button" data-page="' + n + '"' + (n === p ? ' class="active" aria-current="page"' : '') + '>' + n + '</button>';
            last = n;
        });
        html += '<button type="button" data-page="' + (p + 1) + '"' + (p === pages ? ' disabled' : '') + ' aria-label="Następna strona"><i class="bi bi-chevron-right"></i></button>';
        html += '<span class="at-table-pages">Strona ' + p + ' z ' + pages + '</span>';
        pager.innerHTML = html;
    };

    // Lignes ajoutées, retirées ou masquées par un autre script : on recompte.
    AtTable.prototype.watch = function () {
        var self = this, pending = false;
        new MutationObserver(function (mutations) {
            var relevant = mutations.some(function (m) {
                if (m.type === 'childList') {
                    return m.target.tagName === 'TBODY' || m.target === self.table;
                }
                return m.target.tagName === 'TR';
            });
            if (!relevant || pending) return;
            pending = true;
            requestAnimationFrame(function () { pending = false; self.render(); });
        }).observe(this.table, { childList: true, subtree: true, attributes: true, attributeFilter: ['style', 'hidden'] });
    };

    function initTables(root) {
        (root || document).querySelectorAll('table[data-at-table]').forEach(function (t, i) {
            if (t.atTable) return;
            t.atTable = new AtTable(t, i);
        });
    }
    window.atTable = { init: initTables };

    /* --- Menu « ⋯ » ------------------------------------------------------------ */
    var open = null;

    function items(menu) {
        return Array.prototype.filter.call(menu.querySelectorAll('a[href], button'), function (b) { return !b.disabled; });
    }

    function place(toggle, menu) {
        var r = toggle.getBoundingClientRect();
        menu.style.top = '0px';
        menu.style.left = '0px';
        var w = menu.offsetWidth, h = menu.offsetHeight;
        var left = Math.max(8, Math.min(r.right - w, window.innerWidth - w - 8));
        var top = r.bottom + 4;
        if (top + h > window.innerHeight - 8 && r.top - h - 4 > 8) top = r.top - h - 4;
        menu.style.left = left + 'px';
        menu.style.top = top + 'px';
    }

    function closeMenu(focusToggle) {
        if (!open) return;
        var o = open;
        open = null;
        o.menu.hidden = true;
        o.toggle.setAttribute('aria-expanded', 'false');
        o.wrap.classList.remove('is-open');
        if (focusToggle) o.toggle.focus();
    }

    function openMenu(wrap) {
        var toggle = wrap.querySelector('.at-more-toggle');
        var menu = wrap.querySelector('.at-more-menu');
        if (!toggle || !menu) return;
        closeMenu(false);
        menu.hidden = false;
        place(toggle, menu);
        toggle.setAttribute('aria-expanded', 'true');
        wrap.classList.add('is-open');
        open = { wrap: wrap, toggle: toggle, menu: menu };
    }

    document.addEventListener('click', function (e) {
        var toggle = e.target.closest('.at-more-toggle');
        if (toggle) {
            var wrap = toggle.closest('.at-more');
            if (open && open.wrap === wrap) closeMenu(false);
            else openMenu(wrap);
            return;
        }
        if (!open) return;
        if (open.menu.contains(e.target)) {
            // L'action du menu a déjà reçu le clic (phase de bouillonnement) : on referme.
            if (e.target.closest('a[href], button')) setTimeout(function () { closeMenu(false); }, 0);
            return;
        }
        closeMenu(false);
    });

    document.addEventListener('keydown', function (e) {
        if (!open) {
            var t = e.target.closest && e.target.closest('.at-more-toggle');
            if (t && (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ')) {
                e.preventDefault();
                openMenu(t.closest('.at-more'));
                var first = items(open.menu)[0];
                if (first) first.focus();
            }
            return;
        }
        var list = items(open.menu), i = list.indexOf(document.activeElement);
        if (e.key === 'Escape') { e.preventDefault(); closeMenu(true); }
        else if (e.key === 'ArrowDown') { e.preventDefault(); (list[i + 1] || list[0]).focus(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); (list[i - 1] || list[list.length - 1]).focus(); }
        else if (e.key === 'Home') { e.preventDefault(); list[0].focus(); }
        else if (e.key === 'End') { e.preventDefault(); list[list.length - 1].focus(); }
        else if (e.key === 'Tab') closeMenu(false);
    });

    window.addEventListener('resize', function () { closeMenu(false); });
    document.addEventListener('scroll', function (e) {
        if (open && !open.menu.contains(e.target)) closeMenu(false);
    }, true);

    /* --- Confirmation avant une action risquée ------------------------------ */
    function ask(el) {
        var text = el.getAttribute('data-at-confirm');
        var ok = el.getAttribute('data-at-confirm-ok') || 'Tak';
        if (typeof Swal !== 'undefined') {
            return Swal.fire({
                title: text, icon: 'warning', showCancelButton: true,
                confirmButtonText: ok, cancelButtonText: 'Anuluj', reverseButtons: true, focusCancel: true
            }).then(function (r) { return !!r.isConfirmed; });
        }
        return Promise.resolve(window.confirm(text));
    }

    document.addEventListener('click', function (e) {
        var target = e.target.closest('[data-at-confirm]');
        if (!target) return;
        if (target.getAttribute('data-at-confirmed') === '1') { target.removeAttribute('data-at-confirmed'); return; }
        e.preventDefault();
        e.stopImmediatePropagation();
        if (open) closeMenu(false);
        ask(target).then(function (ok) {
            if (!ok) return;
            target.setAttribute('data-at-confirmed', '1');
            target.click();
        });
    }, true);

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { initTables(); });
    else initTables();
})();
