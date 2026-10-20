/*
 * Arkusz tygodnia / Feuille de production de la semaine : toute la production de la semaine sur une feuille,
 * une ligne par produit — stock, disponible, min., max., commandes, réassort, à produire, pièces par lot, lots,
 * heures, jours ; par groupe : heures → ETP. Règle : réassort si disponible < min. (jusqu'au max.), à produire
 * = commandes + réassort (+ lots fixes 📌), arrondi aux lots entiers. Lecture : GET /planning/sheet ; dans la
 * ligne : mode, stocks min./max. et lot → PATCH /produits/items/{id}/settings, puis la feuille est recalculée.
 */
(async function () {
    'use strict';
    const el = document.getElementById('tfbSheet');
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
    const fmt = (n, d) => ui.fmt(n, d ?? 0, api.lang);
    let state = readHash();
    let sheet = null;
    let groups = [];

    function readHash() {
        const p = new URLSearchParams(location.hash.replace(/^#/, ''));
        const date = /^\d{4}-\d{2}-\d{2}$/.test(p.get('date') || '') ? p.get('date') : new Date().toISOString().slice(0, 10);
        return {date, group: Number(p.get('group')) || 0, all: p.get('all') === '1'};
    }
    function writeHash() {
        const p = new URLSearchParams({date: state.date});
        if (state.group) p.set('group', state.group);
        if (state.all) p.set('all', '1');
        history.replaceState(null, '', '#' + p.toString());
    }
    const shift = (iso, days) => { const d = new Date(iso + 'T12:00:00'); d.setDate(d.getDate() + days); return d.toISOString().slice(0, 10); };
    const short = (iso) => iso.slice(8, 10) + '.' + iso.slice(5, 7);
    const dayName = (iso) => new Date(iso + 'T12:00:00').toLocaleDateString(api.lang === 'fr' ? 'fr-FR' : 'pl-PL', {weekday: 'short'});

    // Ilość w jednostce, w której myśli piekarnia : kartony (produkt z kartonem), inaczej jednostka karty.
    const cartons = (r) => !!r.ppc;
    const q = (r, v) => v === null || v === undefined ? '—'
        : (cartons(r) ? `${fmt(v / r.ppc, Math.abs(v / r.ppc) >= 10 ? 0 : 1)} ${t('cart')}` : `${fmt(v, r.unit === 'kg' ? 1 : 0)} ${r.unit}`);
    const pieces = (r, v) => v === null || v === undefined ? '' : `${fmt(v, r.unit === 'kg' ? 1 : 0)} ${r.unit}`;
    const toInput = (r, v) => v === null || v === undefined ? '' : String(cartons(r) ? Math.round(v / r.ppc * 100) / 100 : v).replace('.', ',');

    async function load() {
        el.innerHTML = header() + `<p class="text-muted">${esc(t('loading'))}</p>`;
        try {
            [sheet, groups] = await Promise.all([api.get(`/planning/sheet?date=${state.date}`), groups.length ? groups : api.list('/referentiels/groups').then(r => r.data.filter(g => g.active))]);
        } catch (err) {
            el.innerHTML = header() + `<div class="alert alert-danger">${esc(err.message)}</div>`;
            return;
        }
        state.date = sheet.week_start;
        writeHash();
        draw();
    }

    function header() {
        return `<div class="tfb-head"><div><h3>${esc(t('title'))}</h3><p class="text-muted mb-0">${esc(t('subtitle'))}</p></div>
            <div class="tfb-head-actions"><button type="button" class="btn btn-outline-secondary btn-sm" data-lang-switch>${esc(t('lang_switch'))}</button></div></div>`;
    }

    // Obliczenie w jednej linii : zamówienia + uzupełnienie (+ stałe partie) = potrzeba → partie → do produkcji.
    function calc(r) {
        const parts = [];
        if (r.orders > 0) parts.push(`🚚 ${q(r, r.orders)}`);
        if (r.refill > 0) parts.push(`📦 ${q(r, r.refill)} (${t('calc_refill', {max: q(r, r.stock_max), available: q(r, r.available)})})`);
        if (r.fixed > 0) parts.push(`📌 ${q(r, r.fixed)}`);
        if (!parts.length) {
            if (r.to_produce > 0) return `= ${q(r, r.to_produce)}`;
            return r.mode === 'MTS' && r.available !== null
                ? t(r.stock_max === null ? 'calc_no_max' : 'calc_enough', {available: q(r, r.available), min: q(r, r.stock_min ?? r.stock_max)}) : '';
        }
        const need = r.orders + r.refill + r.fixed;
        let s = parts.join(' + ') + (parts.length > 1 ? ` = ${q(r, need)}` : '');
        if (r.batch && need > 0) {
            s += ` → ${pieces(r, need)} ÷ ${pieces(r, r.batch)} = ${fmt(need / r.batch, 2)} → ${t('calc_batches', {n: r.batches})} = ${pieces(r, r.to_produce)}`;
        } else if (Math.abs(r.to_produce - need) > 0.001) {
            s += ` → ${q(r, r.to_produce)}`;
        }
        return s;
    }

    function draw() {
        const s = sheet;
        const fteH = s.fte_hours || 40;
        const rows = s.rows.filter(r => (!state.group || r.group_id === state.group) && (state.all || r.to_produce > 0 || (r.mode === 'MTS' && r.stock_max === null)));
        const groupOf = (id) => s.groups.find(g => (g.id ?? null) === (id ?? null));
        const hoursOf = {};
        for (const r of rows) hoursOf[r.group_id ?? 0] = (hoursOf[r.group_id ?? 0] || 0) + r.hours;
        const need = (h) => t('group_need', {hours: fmt(h, 1), fte: fmt(h / fteH, 1), base: fmt(fteH, 0)});
        const groupHead = (id) => {
            const g = groupOf(id);
            const h = hoursOf[id ?? 0] || 0;
            const short = g && g.available_h > 0 && h > g.available_h + 0.05;
            return `<tr class="tfb-week-table-group"><th colspan="12">${esc(g?.name || t('no_group'))}
                <span class="tfb-week-fte ${short ? 'is-short' : ''}">${esc(need(h))}${g ? ' · ' + esc(t('group_has', {hours: fmt(g.available_h, 0), people: g.people, fte: fmt(g.available_h / fteH, 1)})) : ''}</span></th></tr>`;
        };
        const editable = s.can_edit;
        let last;
        const body = rows.map(r => {
            const sep = (r.group_id ?? 0) !== last ? groupHead(r.group_id) : '';
            last = r.group_id ?? 0;
            const mts = r.mode === 'MTS';
            const low = mts && r.available !== null && r.stock_min !== null && r.available < r.stock_min;
            const days = r.days.map(d => `<span title="${esc(d)}">${esc(dayName(d))}</span>`).join(', ');
            const placedBatches = r.batch ? r.placed / r.batch : null;
            return sep + `<tr data-item="${r.item_id}" class="${r.to_produce > 0 ? '' : 'is-idle'}">
                <td><a href="${esc(api.root)}/workshop/products#/item/${r.item_id}/4"><strong>${esc(r.name)}</strong></a>${r.item_type === 'semi' ? ` <span class="badge bg-light text-dark">${esc(t('semi'))}</span>` : ''}
                    <div class="tfb-sheet-calc">${esc(calc(r))}</div></td>
                <td class="text-center">${r.item_type === 'product' ? `<div class="form-check form-switch d-inline-block m-0" title="${esc(r.never_frozen ? t('never_frozen') : t('mode_help'))}">
                    <input class="form-check-input" type="checkbox" role="switch" data-mode ${mts ? 'checked' : ''} ${r.never_frozen || !editable ? 'disabled' : ''} aria-label="${esc(t('mode_mts'))}"></div>
                    <div class="small text-muted">${esc(t(mts ? 'mode_mts' : 'mode_mto'))}</div>` : '<span class="text-muted">—</span>'}</td>
                <td class="text-end" title="${esc(pieces(r, r.stock))}">${q(r, r.stock)}</td>
                <td class="text-end ${low ? 'text-danger fw-semibold' : ''}" title="${esc(r.available === null ? '' : t('available_help', {stock: pieces(r, r.stock), production: pieces(r, r.in_production), orders: pieces(r, r.orders_from_stock)}))}">${mts ? q(r, r.available) : '—'}</td>
                <td>${mts ? stockInput(r, 'stock_min', editable) : '<span class="text-muted">—</span>'}</td>
                <td>${mts ? stockInput(r, 'stock_max', editable) : '<span class="text-muted">—</span>'}</td>
                <td class="text-end">${r.orders > 0 ? q(r, r.orders) : '—'}</td>
                <td class="text-end">${r.refill > 0 ? q(r, r.refill) : '—'}${r.fixed > 0 ? `<div class="small text-muted">📌 ${q(r, r.fixed)}</div>` : ''}</td>
                <td class="text-end"><strong>${r.to_produce > 0 ? q(r, r.to_produce) : '—'}</strong>${r.to_produce > 0 && cartons(r) ? `<div class="small text-muted">${esc(pieces(r, r.to_produce))}</div>` : ''}</td>
                <td><div class="input-group input-group-sm tfb-sheet-batch"><input class="form-control" inputmode="decimal" data-batch value="${r.batch ?? ''}" placeholder="—" ${editable ? '' : 'disabled'}
                    aria-label="${esc(t('col_batch'))}"><span class="input-group-text">${esc(r.unit)}</span></div>${r.batch && cartons(r) ? `<div class="small text-muted">${esc(q(r, r.batch))}</div>` : ''}</td>
                <td class="text-end"><strong class="tfb-sheet-batches">${r.batches ?? '—'}</strong>${r.batches ? `<div class="small text-muted">${esc(t('placed', {n: fmt(Math.min(placedBatches, r.batches), placedBatches % 1 > 0.001 ? 1 : 0)}))}</div>` : ''}</td>
                <td class="text-end">${r.hours > 0 ? fmt(r.hours, 1) + ' h' : '—'}<div class="small text-muted">${days}</div></td>
            </tr>`;
        }).join('');
        const total = Object.values(hoursOf).reduce((a, b) => a + b, 0);
        const totalBatches = rows.reduce((a, r) => a + (r.batches || 0), 0);
        el.innerHTML = header() + `
            <div class="card tfb-sheet-bar"><div class="card-body d-flex flex-wrap gap-2 align-items-center">
                <div class="btn-group btn-group-sm"><button type="button" class="btn btn-outline-primary" data-week="-7" aria-label="${esc(t('previous'))}">‹</button>
                    <button type="button" class="btn btn-outline-primary" data-week="0">${esc(t('this_week'))}</button>
                    <button type="button" class="btn btn-outline-primary" data-week="7" aria-label="${esc(t('next'))}">›</button></div>
                <strong class="ms-1">${esc(t('week', {n: s.iso_week, from: short(s.week_start), to: short(s.week_end)}))}</strong>
                <select class="form-select form-select-sm" data-group style="width:auto"><option value="0">${esc(t('all_groups'))}</option>
                    ${groups.map(g => `<option value="${g.id}" ${state.group === g.id ? 'selected' : ''}>${esc(g.name)}</option>`).join('')}</select>
                <label class="form-check form-switch m-0 small"><input class="form-check-input" type="checkbox" data-all ${state.all ? 'checked' : ''}> ${esc(t('show_all'))}</label>
                <a class="btn btn-sm btn-outline-secondary ms-auto" href="${esc(api.root)}/workshop/gantt#from=${s.week_start}&view=week"><i class="bi bi-calendar-week"></i> ${esc(t('to_board'))}</a>
            </div></div>
            <div class="tfb-sheet-rule">${esc(t('rule'))}</div>
            ${s.source && s.source.type === 'plan' ? `<div class="alert alert-info py-2 small">${esc(t('source_plan'))}</div>` : ''}
            <div class="card"><div class="card-body pt-3"><div class="table-responsive"><table class="table table-sm align-middle mb-0 tfb-week-table tfb-sheet">
                <thead><tr><th>${esc(t('col_product'))}</th><th class="text-center">${esc(t('col_mode'))}</th><th class="text-end">${esc(t('col_stock'))}</th>
                    <th class="text-end" title="${esc(t('col_available_help'))}">${esc(t('col_available'))}</th><th>${esc(t('col_min'))}</th><th>${esc(t('col_max'))}</th>
                    <th class="text-end">🚚 ${esc(t('col_orders'))}</th><th class="text-end">📦 ${esc(t('col_refill'))}</th><th class="text-end">${esc(t('col_to_produce'))}</th>
                    <th>${esc(t('col_batch'))}</th><th class="text-end">${esc(t('col_batches'))}</th><th class="text-end">${esc(t('col_hours'))}</th></tr></thead>
                <tbody>${body || `<tr><td colspan="12" class="text-muted">${esc(t('empty'))}</td></tr>`}</tbody>
                <tfoot><tr class="tfb-week-table-total"><th colspan="12">${esc(t('total'))} <span class="tfb-week-fte">${esc(need(total))} · ${esc(t('total_batches', {n: totalBatches}))}</span></th></tr></tfoot>
            </table></div></div></div>`;
        bind();
    }

    function stockInput(r, field, editable) {
        return `<div class="input-group input-group-sm tfb-sheet-stock"><input class="form-control" inputmode="decimal" data-stock="${field}" value="${esc(toInput(r, r[field]))}" placeholder="—"
            ${editable ? '' : 'disabled'} aria-label="${esc(t('col_' + (field === 'stock_min' ? 'min' : 'max')))}" title="${esc(pieces(r, r[field]))}">
            <span class="input-group-text">${esc(cartons(r) ? t('cart') : r.unit)}</span></div>`;
    }

    // Zapis w wierszu : karta produktu (te same zasady co w karcie), potem arkusz liczy się od nowa.
    async function save(r, body, input) {
        if (input) input.disabled = true;
        try {
            await api.patch(`/produits/items/${r.item_id}/settings`, body);
            ui.notify(true, t('saved_recalc'));
            const y = window.scrollY;
            sheet = await api.get(`/planning/sheet?date=${state.date}`);
            draw();
            window.scrollTo(0, y);
        } catch (err) {
            ui.notify(false, ((err.body && err.body.errors) || []).map(x => x.message).join(' ') || err.message);
            if (input) input.disabled = false;
            draw();
        }
    }

    function bind() {
        el.querySelectorAll('[data-week]').forEach(b => b.addEventListener('click', () => {
            state.date = b.dataset.week === '0' ? new Date().toISOString().slice(0, 10) : shift(sheet.week_start, Number(b.dataset.week));
            load();
        }));
        el.querySelector('[data-group]').addEventListener('change', (e) => { state.group = Number(e.target.value) || 0; writeHash(); draw(); });
        el.querySelector('[data-all]').addEventListener('change', (e) => { state.all = e.target.checked; writeHash(); draw(); });
        el.querySelectorAll('tr[data-item]').forEach(tr => {
            const r = sheet.rows.find(x => x.item_id === Number(tr.dataset.item));
            tr.querySelector('[data-mode]')?.addEventListener('change', (e) => save(r, {mode: e.target.checked ? 'MTS' : 'MTO'}, e.target));
            tr.querySelectorAll('[data-stock]').forEach(input => input.addEventListener('change', () => {
                const v = ui.num(input.value);
                if (v !== null && typeof v !== 'number') { ui.notify(false, t('invalid_number')); return; }
                save(r, {[input.dataset.stock]: v === null ? null : (cartons(r) ? Math.round(v * r.ppc) : v)}, input);
            }));
            const batch = tr.querySelector('[data-batch]');
            batch?.addEventListener('change', () => {
                const v = ui.num(batch.value);
                if (v !== null && (typeof v !== 'number' || v <= 0)) { ui.notify(false, t('invalid_number')); return; }
                save(r, {batch_multiple: v}, batch);
            });
        });
    }

    el.addEventListener('click', async (e) => {
        if (!e.target.closest('[data-lang-switch]')) return;
        await api.setLang(api.lang === 'pl' ? 'fr' : 'pl');
        draw();
    });
    window.addEventListener('hashchange', () => { const s = readHash(); if (s.date !== state.date) { state = s; load(); } });
    load();
})();
