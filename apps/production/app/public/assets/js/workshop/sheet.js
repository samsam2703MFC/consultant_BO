/*
 * Arkusz tygodnia / Feuille de production de la semaine : toute la production de la semaine sur une feuille,
 * une ligne par produit — stock, disponible, min., max., commandes, réassort, à produire, pièces par lot, lots,
 * temps du lot (personnes × minutes = heures par lot), heures, jours ; par groupe : heures → ETP. Règle : réassort si disponible < min. (jusqu'au max.), à produire
 * = commandes + réassort, arrondi aux lots entiers ; les lots vont d'abord sur les jours 📌, le reste attend dans
 * « À produire ». Lecture : GET /planning/sheet ; dans la
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
        return {date, group: Number(p.get('group')) || 0, all: p.get('all') === '1', params: p.get('params') === '1'};
    }
    function writeHash() {
        const p = new URLSearchParams({date: state.date});
        if (state.group) p.set('group', state.group);
        if (state.all) p.set('all', '1');
        if (state.params) p.set('params', '1');
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

    // Obliczenie w jednej linii : zamówienia + uzupełnienie = potrzeba → partie → do produkcji.
    function calc(r) {
        const parts = [];
        if (r.orders > 0) parts.push(`🚚 ${q(r, r.orders)}`);
        if (r.refill > 0) parts.push(`📦 ${q(r, r.refill)} (${t('calc_refill', {max: q(r, r.stock_max), available: q(r, r.available)})})`);
        if (!parts.length) {
            if (r.to_produce > 0) return `= ${q(r, r.to_produce)}`;
            return r.mode === 'MTS' && r.available !== null
                ? t(r.stock_max === null ? 'calc_no_max' : 'calc_enough', {available: q(r, r.available), min: q(r, r.stock_min ?? r.stock_max)}) : '';
        }
        const need = r.orders + r.refill;
        let s = parts.join(' + ') + (parts.length > 1 ? ` = ${q(r, need)}` : '');
        if (r.batch && need > 0) {
            s += ` → ${pieces(r, need)} ÷ ${pieces(r, r.batch)} = ${fmt(need / r.batch, 2)} → ${t('calc_batches', {n: r.batches})} = ${pieces(r, r.to_produce)}`;
        } else if (Math.abs(r.to_produce - need) > 0.001) {
            s += ` → ${q(r, r.to_produce)}`;
        }
        return s;
    }

    function draw() {
        if (state.params) return drawParams();
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
            return sep + `<tr data-item="${r.item_id}" class="${r.to_produce > 0 ? '' : 'is-idle'}">
                <td><a href="${esc(api.root)}/workshop/products#/item/${r.item_id}/4"><strong>${esc(r.name)}</strong></a>${r.item_type === 'semi' ? ` <span class="badge bg-light text-dark">${esc(t('semi'))}</span>` : ''}
                    <div class="tfb-sheet-calc">${esc(calc(r))}</div></td>
                <td class="text-center">${r.item_type === 'product' ? `<div class="form-check form-switch d-inline-block m-0" title="${esc(r.never_frozen ? t('never_frozen') : t('mode_help'))}">
                    <input class="form-check-input" type="checkbox" role="switch" data-mode ${mts ? 'checked' : ''} ${r.never_frozen || !editable ? 'disabled' : ''} aria-label="${esc(t('mode_mts'))}"></div>
                    <div class="small text-muted">${esc(t(mts ? 'mode_mts' : 'mode_mto'))}</div>` : '<span class="text-muted">—</span>'}</td>
                <td class="text-end" title="${esc(pieces(r, r.stock))}">${q(r, r.stock)}</td>
                <td class="text-end ${low ? 'text-danger fw-semibold' : ''}" title="${esc(r.available === null ? '' : t('available_help', {stock: pieces(r, r.stock), production: pieces(r, r.in_production), orders: pieces(r, r.orders_from_stock), made: pieces(r, r.made)}))}">${mts ? q(r, r.available) : '—'}${mts && r.made > 0 ? `<div class="small text-muted">${esc(t('made_week', {qty: q(r, r.made)}))}</div>` : ''}</td>
                <td>${mts ? stockInput(r, 'stock_min', editable) : '<span class="text-muted">—</span>'}</td>
                <td>${mts ? stockInput(r, 'stock_max', editable) : '<span class="text-muted">—</span>'}</td>
                <td class="text-end">${r.orders > 0 ? q(r, r.orders) : '—'}</td>
                <td class="text-end">${r.refill > 0 ? q(r, r.refill) : '—'}</td>
                <td class="text-end"><strong>${r.to_produce > 0 ? q(r, r.to_produce) : '—'}</strong>${r.to_produce > 0 && cartons(r) ? `<div class="small text-muted">${esc(pieces(r, r.to_produce))}</div>` : ''}</td>
                <td><div class="input-group input-group-sm tfb-sheet-batch"><input class="form-control" inputmode="decimal" data-batch value="${r.batch ?? ''}" placeholder="—" ${editable ? '' : 'disabled'}
                    aria-label="${esc(t('col_batch'))}"><span class="input-group-text">${esc(r.unit)}</span></div>${r.batch && cartons(r) ? `<div class="small text-muted">${esc(q(r, r.batch))}</div>` : ''}${labour(r, editable)}</td>
                <td class="text-end"><strong class="tfb-sheet-batches">${r.batches ?? (r.to_produce > 0 ? q(r, r.to_produce) : '—')}</strong>${split(r)}</td>
                <td class="text-end">${r.hours > 0 ? fmt(r.hours, 1) + ' h' : '—'}<div class="small text-muted" title="${esc(t('hours_' + r.hours_source + '_help'))}">${esc(t('hours_' + r.hours_source))}${days ? ' · ' + days : ''}</div></td>
            </tr>`;
        }).join('');
        const total = Object.values(hoursOf).reduce((a, b) => a + b, 0);
        const totalBatches = rows.reduce((a, r) => a + (r.batches || 0), 0);
        el.innerHTML = header() + `
            ${toolbar()}
            <div class="tfb-sheet-rule">${esc(t('rule'))}</div>
            ${s.source && s.source.type === 'plan' ? `<div class="alert alert-info py-2 small">${esc(t('source_plan'))}</div>` : ''}
            <div class="card"><div class="card-body pt-3"><div class="table-responsive"><table class="table table-sm align-middle mb-0 tfb-week-table tfb-sheet">
                <thead><tr><th>${esc(t('col_product'))}</th><th class="text-center">${esc(t('col_mode'))}</th><th class="text-end">${esc(t('col_stock'))}</th>
                    <th class="text-end" title="${esc(t('col_available_help'))}">${esc(t('col_available'))}</th><th>${esc(t('col_min'))}</th><th>${esc(t('col_max'))}</th>
                    <th class="text-end">🚚 ${esc(t('col_orders'))}</th><th class="text-end">📦 ${esc(t('col_refill'))}</th><th class="text-end">${esc(t('col_to_produce'))}</th>
                    <th title="${esc(t('col_labour_help'))}">${esc(t('col_batch'))}<div class="tfb-sheet-subhead">${esc(t('col_labour'))}</div></th><th class="text-end">${esc(t('col_batches'))}</th>
                    <th class="text-end">${esc(t('col_hours'))}</th></tr></thead>
                <tbody>${body || `<tr><td colspan="12" class="text-muted">${esc(t('empty'))}</td></tr>`}</tbody>
                <tfoot><tr class="tfb-week-table-total"><th colspan="12">${esc(t('total'))} <span class="tfb-week-fte">${esc(need(total))} · ${esc(t('total_batches', {n: totalBatches}))}</span></th></tr></tfoot>
            </table></div></div></div>`;
        bind();
    }

    // Czas partii : osoby × minuty = roboczogodziny na partię (godziny tygodnia = partie × to) ; bez partii — nieaktywne.
    function labour(r, editable) {
        const dis = editable && r.batch ? '' : 'disabled';
        const result = r.batch_hours ? t('labour_value', {h: fmt(r.batch_hours, r.batch_hours % 1 ? 2 : 0)}) : (r.batch ? t('labour_empty') : t('labour_no_batch'));
        return `<div class="tfb-sheet-labour"><div class="input-group input-group-sm"><input class="form-control" inputmode="decimal" data-people value="${r.batch_people ?? ''}" placeholder="—" ${dis}
                aria-label="${esc(t('labour_people'))}" title="${esc(t('labour_people'))}"><span class="input-group-text">${esc(t('labour_people_unit'))}</span></div>
            <span class="tfb-sheet-x">×</span><div class="input-group input-group-sm"><input class="form-control" inputmode="decimal" data-minutes value="${r.batch_minutes ?? ''}" placeholder="—" ${dis}
                aria-label="${esc(t('labour_minutes'))}" title="${esc(t('labour_minutes'))}"><span class="input-group-text">min</span></div></div>
            <div class="small ${r.batch_hours ? 'text-muted' : 'text-warning-emphasis'}">${esc(result)}</div>`;
    }

    // Partie tygodnia : 📌 na przypiętych dniach (z miejsca), ułożone ręcznie, ⏳ czekają w « Do zrobienia ».
    function split(r) {
        const n = (v) => r.batch ? fmt(v / r.batch, Math.abs(v / r.batch % 1) > 0.001 ? 1 : 0) : q(r, v);
        const room = (r.pins || []).filter(x => !x.past && !x.released).reduce((a, x) => a + x.room, 0);
        const lines = [];
        if (room > 0 || r.pinned > 0) lines.push(['pinned', {n: n(r.pinned), room: n(room)}]);
        if (r.placed - r.pinned > 0.001) lines.push(['placed', {n: n(r.placed - r.pinned)}]);
        if (r.waiting > 0.001) lines.push(['waiting', {n: n(r.waiting)}]);
        return lines.map(([k, p]) => `<div class="small ${k === 'waiting' ? 'text-warning-emphasis' : 'text-muted'}" title="${esc(t('split_' + k + '_title', p))}">${esc(t('split_' + k, p))}</div>`).join('');
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

    // Pasek : tydzień, grupa, « pokaż wszystkie », parametry produktów, tablica tygodnia.
    function toolbar() {
        const s = sheet;
        return `<div class="card tfb-sheet-bar"><div class="card-body d-flex flex-wrap gap-2 align-items-center">
                <div class="btn-group btn-group-sm"><button type="button" class="btn btn-outline-primary" data-week="-7" aria-label="${esc(t('previous'))}">‹</button>
                    <button type="button" class="btn btn-outline-primary" data-week="0">${esc(t('this_week'))}</button>
                    <button type="button" class="btn btn-outline-primary" data-week="7" aria-label="${esc(t('next'))}">›</button></div>
                <strong class="ms-1">${esc(t('week', {n: s.iso_week, from: short(s.week_start), to: short(s.week_end)}))}</strong>
                <select class="form-select form-select-sm" data-group style="width:auto"><option value="0">${esc(t('all_groups'))}</option>
                    ${groups.map(g => `<option value="${g.id}" ${state.group === g.id ? 'selected' : ''}>${esc(g.name)}</option>`).join('')}</select>
                <label class="form-check form-switch m-0 small"><input class="form-check-input" type="checkbox" data-all ${state.all ? 'checked' : ''}> ${esc(t('show_all'))}</label>
                <button type="button" class="btn btn-sm ${state.params ? 'btn-primary' : 'btn-outline-primary'} ms-auto" data-params><i class="bi bi-sliders"></i> ${esc(t('params_button'))}</button>
                <a class="btn btn-sm btn-outline-secondary" href="${esc(api.root)}/workshop/gantt#from=${s.week_start}&view=week"><i class="bi bi-calendar-week"></i> ${esc(t('to_board'))}</a>
            </div></div>`;
    }

    // --- Parametry produktów : wszystkie karty — tryb MTO / MTS, sztuk w partii, osoby × minuty na partię -------------
    let items = null;
    let search = '';
    async function drawParams() {
        if (!items) {
            el.innerHTML = header() + `<p class="text-muted">${esc(t('loading'))}</p>`;
            try { items = (await api.list('/produits/items')).data; } catch (err) { el.innerHTML = header() + `<div class="alert alert-danger">${esc(err.message)}</div>`; return; }
        }
        const groupName = (id) => groups.find(g => g.id === id)?.name || t('no_group');
        const editable = sheet ? sheet.can_edit : false;
        const q = search.trim().toLowerCase();
        const shown = items.filter(i => (!state.group || i.settings.primary_group_id === state.group) && (!q || i.name.toLowerCase().includes(q)) && i.erp.active !== false)
            .sort((a, b) => groupName(a.settings.primary_group_id).localeCompare(groupName(b.settings.primary_group_id)) || a.name.localeCompare(b.name));
        let last = null;
        const rows = shown.map(i => {
            const st = i.settings;
            const g = st.primary_group_id ?? 0;
            const sep = g !== last ? `<tr class="tfb-week-table-group"><th colspan="5">${esc(groupName(st.primary_group_id))}</th></tr>` : '';
            last = g;
            const product = i.item_type === 'product';
            const hours = st.batch_multiple && st.batch_people && st.batch_minutes ? st.batch_people * st.batch_minutes / 60 : null;
            const dis = editable ? '' : 'disabled';
            return sep + `<tr data-param-item="${i.id}">
                <td><a href="${esc(api.root)}/workshop/products#/item/${i.id}/4"><strong>${esc(i.name)}</strong></a>${product ? '' : ` <span class="badge bg-light text-dark">${esc(t('semi'))}</span>`}</td>
                <td class="text-center">${product ? `<div class="form-check form-switch d-inline-block m-0" title="${esc(st.never_frozen ? t('never_frozen') : t('mode_help'))}">
                    <input class="form-check-input" type="checkbox" role="switch" data-pmode ${st.mode === 'MTS' ? 'checked' : ''} ${st.never_frozen || !editable ? 'disabled' : ''} aria-label="${esc(t('mode_mts'))}"></div>
                    <div class="small text-muted">${esc(t(st.mode === 'MTS' ? 'mode_mts' : 'mode_mto'))}</div>` : '<span class="text-muted">—</span>'}</td>
                <td><div class="input-group input-group-sm tfb-sheet-batch"><input class="form-control" inputmode="decimal" data-pfield="batch_multiple" value="${st.batch_multiple ?? ''}" placeholder="—" ${dis}
                    aria-label="${esc(t('col_batch'))}"><span class="input-group-text">${esc(i.base_unit)}</span></div>${st.batch_multiple && i.erp.ppc ? `<div class="small text-muted">${esc(fmt(st.batch_multiple / i.erp.ppc, 1))} ${esc(t('cart'))}</div>` : ''}</td>
                <td><div class="tfb-sheet-labour m-0"><div class="input-group input-group-sm"><input class="form-control" inputmode="decimal" data-pfield="batch_people" value="${st.batch_people ?? ''}" placeholder="—" ${dis}
                        aria-label="${esc(t('labour_people'))}"><span class="input-group-text">${esc(t('labour_people_unit'))}</span></div><span class="tfb-sheet-x">×</span>
                    <div class="input-group input-group-sm"><input class="form-control" inputmode="decimal" data-pfield="batch_minutes" value="${st.batch_minutes ?? ''}" placeholder="—" ${dis}
                        aria-label="${esc(t('labour_minutes'))}"><span class="input-group-text">min</span></div></div></td>
                <td class="text-end">${hours !== null ? `<strong>${esc(fmt(hours, hours % 1 ? 2 : 0))} h</strong>` : `<span class="small text-muted">${esc(st.batch_multiple ? t('labour_empty') : t('labour_no_batch'))}</span>`}</td></tr>`;
        }).join('');
        el.innerHTML = header() + toolbar() + `
            <div class="card"><div class="card-body pt-3">
                <div class="d-flex flex-wrap gap-2 align-items-center mb-2"><h5 class="mb-0">${esc(t('params_title'))}</h5>
                    <input class="form-control form-control-sm" style="max-width:260px" data-search value="${esc(search)}" placeholder="${esc(t('search_placeholder'))}">
                    <span class="small text-muted">${esc(t('params_count', {n: shown.length}))}</span></div>
                <p class="small text-muted mb-2">${esc(t('params_help'))}</p>
                <div class="table-responsive"><table class="table table-sm align-middle mb-0 tfb-week-table tfb-sheet">
                    <thead><tr><th>${esc(t('col_product_only'))}</th><th class="text-center">${esc(t('col_mode'))}</th><th>${esc(t('col_batch'))}</th>
                        <th title="${esc(t('col_labour_help'))}">${esc(t('col_labour'))}</th><th class="text-end">${esc(t('col_batch_hours'))}</th></tr></thead>
                    <tbody>${rows || `<tr><td colspan="5" class="text-muted">${esc(t('empty'))}</td></tr>`}</tbody></table></div>
            </div></div>`;
        bindToolbar();
        const searchInput = el.querySelector('[data-search]');
        searchInput.addEventListener('input', () => { search = searchInput.value; const pos = searchInput.selectionStart; drawParams().then(() => { const i2 = el.querySelector('[data-search]'); i2.focus(); i2.setSelectionRange(pos, pos); }); });
        el.querySelectorAll('tr[data-param-item]').forEach(tr => {
            const item = items.find(x => x.id === Number(tr.dataset.paramItem));
            tr.querySelector('[data-pmode]')?.addEventListener('change', (e) => saveParam(item, {mode: e.target.checked ? 'MTS' : 'MTO'}, e.target));
            tr.querySelectorAll('[data-pfield]').forEach(input => input.addEventListener('change', () => {
                const v = ui.num(input.value);
                const max = input.dataset.pfield === 'batch_people' ? 100 : (input.dataset.pfield === 'batch_minutes' ? 10000 : Infinity);
                if (v !== null && (typeof v !== 'number' || v <= 0 || v > max)) { ui.notify(false, t('invalid_number')); return; }
                saveParam(item, {[input.dataset.pfield]: v}, input);
            }));
        });
    }

    async function saveParam(item, body, input) {
        if (input) input.disabled = true;
        try {
            const res = await api.patch(`/produits/items/${item.id}/settings`, body);
            if (res && res.settings) item.settings = {...item.settings, ...res.settings};
            ui.notify(true, t('saved'));
        } catch (err) {
            ui.notify(false, ((err.body && err.body.errors) || []).map(x => x.message).join(' ') || err.message);
        }
        const y = window.scrollY;
        await drawParams();
        window.scrollTo(0, y);
    }

    function bindToolbar() {
        el.querySelectorAll('[data-week]').forEach(b => b.addEventListener('click', () => {
            state.date = b.dataset.week === '0' ? new Date().toISOString().slice(0, 10) : shift(sheet.week_start, Number(b.dataset.week));
            load();
        }));
        el.querySelector('[data-group]').addEventListener('change', (e) => { state.group = Number(e.target.value) || 0; writeHash(); draw(); });
        el.querySelector('[data-all]').addEventListener('change', (e) => { state.all = e.target.checked; writeHash(); draw(); });
        // Parametry : po wyjściu arkusz liczy się od nowa (zmienione partie, tryby, czasy).
        el.querySelector('[data-params]').addEventListener('click', () => {
            state.params = !state.params;
            writeHash();
            if (state.params) draw(); else { items = null; load(); }
        });
    }

    function bind() {
        bindToolbar();
        el.querySelectorAll('tr[data-item]').forEach(tr => {
            const r = sheet.rows.find(x => x.item_id === Number(tr.dataset.item));
            tr.querySelector('[data-mode]')?.addEventListener('change', (e) => save(r, {mode: e.target.checked ? 'MTS' : 'MTO'}, e.target));
            tr.querySelectorAll('[data-stock]').forEach(input => input.addEventListener('change', () => {
                const v = ui.num(input.value);
                if (v !== null && typeof v !== 'number') { ui.notify(false, t('invalid_number')); return; }
                save(r, {[input.dataset.stock]: v === null ? null : (cartons(r) ? Math.round(v * r.ppc) : v)}, input);
            }));
            for (const [attr, field, max] of [['data-people', 'batch_people', 100], ['data-minutes', 'batch_minutes', 10000]]) {
                const input = tr.querySelector(`[${attr}]`);
                input?.addEventListener('change', () => {
                    const v = ui.num(input.value);
                    if (v !== null && (typeof v !== 'number' || v <= 0 || v > max)) { ui.notify(false, t('invalid_number')); return; }
                    save(r, {[field]: v}, input);
                });
            }
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
