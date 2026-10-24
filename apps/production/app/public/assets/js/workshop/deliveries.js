/*
 * Dostawy surowców / Livraisons de matières (Produkcja) : kto dostarcza co i kiedy. Tydzień produkcji dzień po dniu
 * (zamówienia u dostawców : oczekiwane, częściowo przyjęte, po terminie, przyjęte), zaległe i bez terminu, a pod spodem
 * dostawcy : co dostarczają, w jakie dni zwykle, ostatnia i najbliższa dostawa. Zamawia się w Zakupach.
 */
(async function () {
    'use strict';
    const el = document.getElementById('tfbDeliveries');
    if (!el) return;
    const {WorkshopApi, ui} = window.TfbWorkshop;
    const api = new WorkshopApi(el);
    const esc = ui.esc;
    try {
        await api.init();
    } catch (e) {
        el.innerHTML = `<div class="alert alert-danger">${esc(e.message)}</div>`;
        return;
    }
    const t = (k, p) => api.t(k, p);
    const n = (v, d) => ui.fmt(v, d ?? 2, api.lang);
    const locale = () => api.lang === 'fr' ? 'fr-FR' : 'pl-PL';
    const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    const shift = (s, k) => { const d = new Date(s + 'T12:00:00'); d.setDate(d.getDate() + k); return iso(d); };
    const dm = (s) => s ? `${s.slice(8, 10)}.${s.slice(5, 7)}` : '—';
    const dayName = (s, style) => new Date(s + 'T12:00:00').toLocaleDateString(locale(), {weekday: style || 'short'});
    // 1 = poniedziałek … 7 = niedziela (2024-01-01 był poniedziałkiem).
    const weekdayName = (k, style) => new Date(2024, 0, k, 12).toLocaleDateString(locale(), {weekday: style || 'short'});
    const qty = (v, l) => `${n(v / (l.factor || 1))} ${l.unit}`;
    const MAX_LINES = 4;
    const MAX_CHIPS = 6;

    const readHash = () => {
        const p = new URLSearchParams(location.hash.replace(/^#/, ''));
        return {date: /^\d{4}-\d{2}-\d{2}$/.test(p.get('date') || '') ? p.get('date') : null, all: p.get('all') === '1', q: (p.get('q') || '').slice(0, 60)};
    };
    let state = readHash();
    let data = null;

    const head = () => `<div class="tfb-head"><div><h3>${esc(t('title'))}</h3><p class="text-muted mb-0">${esc(t('subtitle'))}</p></div>
        <div class="tfb-head-actions">${data && data.can_order ? `<a class="btn btn-outline-primary btn-sm" href="${esc(api.root)}/workshop/supply#tab=orders"><i class="bi bi-cart3"></i> ${esc(t('to_orders'))}</a>` : ''}
            <button type="button" class="btn btn-outline-secondary btn-sm" data-lang-switch>${esc(t('lang_switch'))}</button></div></div>`;

    async function load() {
        el.innerHTML = head() + `<p class="text-muted">${esc(t('loading'))}</p>`;
        try {
            data = await api.get('/supply/deliveries' + (state.date ? '?date=' + state.date : ''));
        } catch (err) {
            el.innerHTML = head() + `<div class="alert alert-danger">${esc(err.message)}</div>`;
            bindHead();
            return;
        }
        draw();
    }

    function writeHash() {
        const p = new URLSearchParams();
        if (state.date) p.set('date', state.date);
        if (state.all) p.set('all', '1');
        if (state.q.trim()) p.set('q', state.q.trim());
        history.replaceState(null, '', p.toString() ? '#' + p.toString() : location.pathname + location.search);
    }

    const STATE_CLASS = {expected: 'tfb-po-sent', partial: 'tfb-po-partial', late: 'tfb-po-cancelled', received: 'tfb-po-received'};
    function card(o, withDate) {
        const shown = o.lines.slice(0, MAX_LINES);
        const more = o.lines.length - shown.length;
        const line = (l) => `<li${l.packaging ? ' class="is-pack"' : ''}><span>${esc(l.name)}</span><span class="tfb-dlv-qty">${esc(qty(l.quantity, l))}
            ${o.state !== 'received' && l.received > 0 ? `<small>${esc(t('received_part', {qty: qty(l.received, l)}))}</small>` : ''}</span></li>`;
        const code = data.can_order ? `<a href="${esc(api.root)}/workshop/supply#tab=orders&order=${o.order_id}">${esc(o.code)}</a>` : esc(o.code);
        return `<article class="tfb-dlv-card is-${o.state}">
            <header><strong>${esc(o.supplier.name)}</strong><span class="badge ${STATE_CLASS[o.state]}">${esc(t('state.' + o.state))}</span></header>
            <div class="tfb-dlv-meta">${code}${withDate && o.planned_date ? ` · ${esc(t('planned', {date: dm(o.planned_date)}))}` : ''}${o.state === 'received' && o.received_on ? ` · ${esc(t('received_on', {date: dm(o.received_on)}))}` : ''}
                ${o.supplier.phone ? ` · <a href="tel:${esc(o.supplier.phone.replace(/\s+/g, ''))}">${esc(o.supplier.phone)}</a>` : ''}</div>
            <ul class="tfb-dlv-lines">${shown.map(line).join('')}</ul>
            ${more > 0 ? `<details class="tfb-dlv-more"><summary>${esc(t('more_lines', {n: more}))}</summary><ul class="tfb-dlv-lines">${o.lines.slice(MAX_LINES).map(line).join('')}</ul></details>` : ''}
        </article>`;
    }

    function calendar() {
        // Jours avec livraisons plus larges (3 parts), jours vides étroits (1 part).
        const cols = data.days.map(d => `minmax(0, ${d.deliveries.length ? 3 : 1}fr)`).join(' ');
        return `<div class="tfb-dlv-week" style="--tfb-dlv-cols: ${cols}">${data.days.map(d => `<section class="tfb-dlv-day ${d.deliveries.length ? '' : 'is-empty'} ${d.today ? 'is-today' : ''} ${d.weekday >= 6 ? 'is-weekend' : ''}">
            <h4><span>${esc(dayName(d.date))}</span> ${esc(dm(d.date))}${d.today ? ` <span class="badge bg-primary">${esc(t('today'))}</span>` : ''}</h4>
            ${d.deliveries.length ? d.deliveries.map(o => card(o, false)).join('') : `<p class="tfb-dlv-none">${esc(t('no_delivery'))}</p>`}</section>`).join('')}</div>`;
    }

    function pending() {
        if (!data.late.length && !data.undated.length) return '';
        return `<div class="card"><div class="card-body">
            ${data.late.length ? `<h5 class="mb-2"><i class="bi bi-exclamation-triangle text-danger"></i> ${esc(t('late_title'))}</h5>
                <p class="small text-muted">${esc(t('late_help'))}</p><div class="tfb-dlv-row">${data.late.map(o => card(o, true)).join('')}</div>` : ''}
            ${data.undated.length ? `<h5 class="mb-2 ${data.late.length ? 'mt-3' : ''}"><i class="bi bi-question-circle"></i> ${esc(t('undated_title'))}</h5>
                <p class="small text-muted">${esc(t('undated_help'))}</p><div class="tfb-dlv-row">${data.undated.map(o => card(o, true)).join('')}</div>` : ''}
        </div></div>`;
    }

    function suppliersTable() {
        const q = state.q.trim().toLowerCase();
        const rows = data.suppliers.filter(s => (state.all || s.materials.length || s.deliveries || s.open_orders)
            && (!q || s.name.toLowerCase().includes(q) || s.materials.some(m => m.name.toLowerCase().includes(q))));
        const days = [1, 2, 3, 4, 5, 6, 7];
        const chips = (s) => {
            const list = q ? [...s.materials].sort((a, b) => b.name.toLowerCase().includes(q) - a.name.toLowerCase().includes(q)) : s.materials;
            const shown = list.slice(0, MAX_CHIPS);
            return shown.map(m => `<span class="tfb-dlv-chip ${m.packaging ? 'is-pack' : ''} ${q && m.name.toLowerCase().includes(q) ? 'is-hit' : ''}" title="${esc(m.current ? t('current_supplier') : t('from_history'))}">${esc(m.name)}</span>`).join('')
                + (list.length > shown.length ? `<span class="tfb-dlv-chip is-more" title="${esc(list.slice(MAX_CHIPS).map(m => m.name).join(', '))}">${esc(t('more_materials', {n: list.length - shown.length}))}</span>` : '')
                || `<span class="text-muted">—</span>`;
        };
        const pills = (s) => `<div class="tfb-dlv-days" aria-label="${esc(s.usual_days.length ? t('usual_days_aria', {days: s.usual_days.map(k => weekdayName(k, 'long')).join(', ')}) : t('no_pattern'))}">
            ${days.map(k => { const w = s.weekdays.find(x => x.day === k) || {deliveries: 0};
                return `<span class="${s.usual_days.includes(k) ? 'is-usual' : (w.deliveries ? 'is-some' : '')}" title="${esc(t('weekday_count', {day: weekdayName(k, 'long'), n: w.deliveries}))}">${esc(weekdayName(k).slice(0, 2))}</span>`; }).join('')}</div>
            <small class="text-muted">${esc(s.deliveries ? t('deliveries_n', {n: s.deliveries}) : t('no_history'))}</small>`;
        return `<div class="card"><div class="card-body">
            <div class="d-flex flex-wrap align-items-end gap-2 mb-2"><div class="me-auto"><h5 class="mb-1">${esc(t('suppliers_title'))}</h5>
                <p class="small text-muted mb-0">${esc(t('suppliers_help', {months: Math.round(data.history_days / 30.4)}))}</p></div>
                <input type="search" class="form-control form-control-sm tfb-dlv-search" data-search placeholder="${esc(t('search'))}" value="${esc(state.q)}" aria-label="${esc(t('search'))}">
                <label class="form-check form-switch small mb-0"><input class="form-check-input" type="checkbox" data-all ${state.all ? 'checked' : ''}> ${esc(t('show_all'))}</label></div>
            ${rows.length ? `<div class="table-responsive"><table class="table table-sm align-middle mb-0 tfb-dlv-table">
                <thead><tr><th>${esc(t('col_supplier'))}</th><th>${esc(t('col_what'))}</th><th>${esc(t('col_when'))}</th><th>${esc(t('col_last'))}</th><th>${esc(t('col_next'))}</th><th class="text-end">${esc(t('col_open'))}</th></tr></thead>
                <tbody>${rows.map(s => `<tr class="${s.in_week ? 'is-week' : ''} ${s.active ? '' : 'text-muted'}">
                    <td><strong>${esc(s.name)}</strong>${s.in_week ? ` <span class="badge tfb-po-sent">${esc(t('this_week', {date: dm(s.in_week)}))}</span>` : ''}
                        <div class="small text-muted">${[s.phone ? `<a href="tel:${esc(s.phone.replace(/\s+/g, ''))}">${esc(s.phone)}</a>` : '', s.email ? `<a href="mailto:${esc(s.email)}">${esc(s.email)}</a>` : ''].filter(Boolean).join(' · ')}</div></td>
                    <td class="tfb-dlv-what">${chips(s)}</td>
                    <td>${pills(s)}</td>
                    <td class="text-nowrap">${s.last_delivery ? `${esc(dayName(s.last_delivery))} ${esc(dm(s.last_delivery))}` : '<span class="text-muted">—</span>'}</td>
                    <td class="text-nowrap">${s.next_delivery ? `<strong>${esc(dayName(s.next_delivery))} ${esc(dm(s.next_delivery))}</strong>` : '<span class="text-muted">—</span>'}</td>
                    <td class="text-end">${s.open_orders || '<span class="text-muted">0</span>'}</td></tr>`).join('')}</tbody></table></div>`
                : `<p class="text-muted mb-0">${esc(t('no_suppliers'))}</p>`}
        </div></div>`;
    }

    function draw() {
        const tot = data.totals;
        const stat = (v, label, cls) => `<div class="tfb-dlv-stat ${cls || ''}"><strong>${esc(v)}</strong><span>${esc(label)}</span></div>`;
        el.innerHTML = head() + `
            <div class="tfb-dlv-nav">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-week="-7" aria-label="${esc(t('prev_week'))}"><i class="bi bi-chevron-left"></i></button>
                <strong>${esc(t('week_label', {week: data.iso_week, from: dm(data.week_start), to: dm(data.week_end)}))}</strong>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-week="7" aria-label="${esc(t('next_week'))}"><i class="bi bi-chevron-right"></i></button>
                ${data.today < data.week_start || data.today > data.week_end ? `<button type="button" class="btn btn-link btn-sm" data-this-week>${esc(t('this_week_btn'))}</button>` : ''}
                <div class="tfb-dlv-stats">${stat(tot.deliveries, t('stat_deliveries'))}${stat(tot.suppliers, t('stat_suppliers'))}${stat(tot.received, t('stat_received'), tot.received ? 'is-ok' : '')}
                    ${stat(tot.late, t('stat_late'), tot.late ? 'is-bad' : '')}${tot.undated ? stat(tot.undated, t('stat_undated')) : ''}</div>
            </div>
            ${calendar()}
            ${pending()}
            ${tot.deliveries === 0 && !data.late.length && !data.undated.length ? `<p class="text-muted small">${esc(t('week_empty'))}${data.can_order ? ' ' + esc(t('week_empty_order')) : ''}</p>` : ''}
            <div data-suppliers>${suppliersTable()}</div>`;
        bindHead();
        el.querySelectorAll('[data-week]').forEach(b => b.addEventListener('click', () => { state.date = shift(data.week_start, Number(b.dataset.week)); writeHash(); load(); }));
        const thisWeek = el.querySelector('[data-this-week]');
        if (thisWeek) thisWeek.addEventListener('click', () => { state.date = null; writeHash(); load(); });
        bindSuppliers();
    }

    function bindSuppliers() {
        const box = el.querySelector('[data-suppliers]');
        const search = box.querySelector('[data-search]');
        search.addEventListener('input', () => {
            state.q = search.value;
            writeHash();
            const pos = search.selectionStart;
            box.innerHTML = suppliersTable();
            bindSuppliers();
            const again = box.querySelector('[data-search]');
            again.focus();
            again.setSelectionRange(pos, pos);
        });
        box.querySelector('[data-all]').addEventListener('change', (e) => { state.all = e.target.checked; writeHash(); box.innerHTML = suppliersTable(); bindSuppliers(); });
    }

    function bindHead() {
        const sw = el.querySelector('[data-lang-switch]');
        if (sw) sw.addEventListener('click', async () => { await api.setLang(api.lang === 'pl' ? 'fr' : 'pl'); load(); });
    }

    window.addEventListener('hashchange', () => { const next = readHash(); if (next.date !== state.date || next.all !== state.all) { state = next; load(); } else if (next.q !== state.q.trim()) { state.q = next.q; draw(); } });
    load();
})();
