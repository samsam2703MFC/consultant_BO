/*
 * Karty produkcyjne / Fiches de production (P2) : liste, nouvelle fiche depuis l'ERP, assistant
 * unique du produit en 8 étapes, dans l'ordre de « Nowy produkt z recepturą » : produit (données
 * ERP), logistique, recette et pertes, mode et stocks, groupe et cuisson, étapes et temps (gamme),
 * contrôle des temps (résumé de production, chronométrages), validation. L'édition d'un produit ERP
 * (/products/{id}/edit) ouvre cet assistant (#/product/{id}, fiche créée si besoin). Tout passe par l'API.
 */
(async function () {
    'use strict';
    const el = document.getElementById('tfbProducts');
    if (!el) return;
    const {WorkshopApi, ui} = window.TfbWorkshop;
    const api = new WorkshopApi(el);
    const esc = ui.esc;
    const state = {groups: [], workstations: [], erp: {categories: [], sections: [], label_types: [0, 1]}, item: null, studies: {}};
    const STEPS = 8;

    try {
        await api.init();
    } catch (e) {
        el.innerHTML = `<div class="alert alert-danger">${esc(e.message)}</div>`;
        return;
    }

    const t = (k, p) => api.t(k, p);
    // Postes où l'étape a une température : four, pousse, froid, surgélation (comme l'API).
    const TEMPERATURE_TYPES = ['four', 'fermentation', 'froid', 'surgelation'];
    const wsType = (id) => (state.workstations.find(w => w.id === id) || {}).type;
    const groupName = (id) => (state.groups.find(g => g.id === id) || {}).name || '—';
    const wsOptions = (selected, filter) => state.workstations.filter(w => !filter || filter(w))
        .map(w => `<option value="${w.id}" ${w.id === selected ? 'selected' : ''}>${esc(w.code)} — ${esc(w.name)}</option>`).join('');
    const fail = (container, err) => {
        if (err.status === 401 || err.status === 403) { ui.notify(false, err.message); return; }
        const orphans = container ? ui.fieldErrors(container, err) : [];
        ui.notify(false, orphans.length ? orphans.join(' ') : err.message);
    };

    async function loadReferences() {
        const [groups, workstations, erp] = await Promise.all([api.list('/referentiels/groups?active=true'), api.list('/referentiels/workstations?active=true'), api.get('/produits/erp-options')]);
        state.groups = groups.data;
        state.workstations = workstations.data;
        state.erp = erp;
    }

    function header(extra) {
        return `<div class="tfb-head">
            <div><h3>${esc(t('title'))}</h3><p class="text-muted mb-0">${esc(t('subtitle'))}</p></div>
            <div class="tfb-head-actions">${extra || ''}<button type="button" class="btn btn-outline-secondary btn-sm" data-lang-switch>${esc(t('lang_switch'))}</button></div>
        </div>`;
    }

    // --- Liste ------------------------------------------------------------------
    async function renderList() {
        const params = new URLSearchParams(location.hash.split('?')[1] || '');
        const query = new URLSearchParams();
        for (const k of ['search', 'status', 'item_type', 'group_id']) if (params.get(k)) query.set(k, params.get(k));
        const res = await api.list('/produits/items' + (query.toString() ? '?' + query : ''));
        el.innerHTML = header(`<a class="btn btn-primary btn-sm" href="#/new"><i class="bi bi-plus"></i> ${esc(t('new_sheet'))}</a>`) + viewTabs('list') + `
            <form class="tfb-filters" data-filters>
                <input class="form-control form-control-sm" name="search" placeholder="${esc(t('search'))}" value="${esc(params.get('search') || '')}">
                <select class="form-select form-select-sm" name="status"><option value="">${esc(t('col.status'))}: ${esc(t('all'))}</option>
                    ${['draft', 'validated'].map(s => `<option value="${s}" ${params.get('status') === s ? 'selected' : ''}>${esc(t('status.' + s))}</option>`).join('')}</select>
                <select class="form-select form-select-sm" name="item_type"><option value="">${esc(t('col.type'))}: ${esc(t('all'))}</option>
                    ${['product', 'semi'].map(s => `<option value="${s}" ${params.get('item_type') === s ? 'selected' : ''}>${esc(t('type.' + s))}</option>`).join('')}</select>
                <select class="form-select form-select-sm" name="group_id"><option value="">${esc(t('col.group'))}: ${esc(t('all'))}</option>
                    ${state.groups.map(g => `<option value="${g.id}" ${params.get('group_id') === String(g.id) ? 'selected' : ''}>${esc(g.name)}</option>`).join('')}</select>
            </form>
            <div class="card"><div class="card-body pt-3"><div class="table-responsive"><table class="table table-hover mb-0">
                <thead><tr><th>${esc(t('col.name'))}</th><th>${esc(t('col.type'))}</th><th>${esc(t('col.group'))}</th><th>${esc(t('col.mode'))}</th><th class="text-end">${esc(t('col.steps'))}</th><th>${esc(t('col.status'))}</th></tr></thead>
                <tbody>${res.data.map(i => `<tr>
                    <td><a href="#/item/${i.id}/${i.settings.status === 'validated' ? 7 : Math.max(1, i.settings.wizard_step)}"><strong>${esc(i.name)}</strong></a>${i.erp.active ? '' : ` <span class="badge bg-secondary">${esc(t('erp.inactive'))}</span>`}</td>
                    <td>${esc(t('type.' + i.item_type))}</td>
                    <td>${esc(groupName(i.settings.primary_group_id))}</td>
                    <td>${esc(t('mode.' + i.settings.mode))}${i.settings.never_frozen ? ' <i class="bi bi-snow" title="' + esc(t('f.never_frozen')) + '"></i>' : ''}</td>
                    <td class="text-end">${i.step_count}</td>
                    <td><span class="badge ${i.settings.status === 'validated' ? 'bg-success' : 'bg-warning'}">${esc(t('status.' + i.settings.status))}</span></td>
                </tr>`).join('') || `<tr><td colspan="6" class="text-muted">${esc(t('empty_list'))}</td></tr>`}</tbody>
            </table></div></div></div>`;
        const form = el.querySelector('[data-filters]');
        const apply = () => {
            const q = new URLSearchParams();
            new FormData(form).forEach((v, k) => { if (v) q.set(k, v); });
            location.hash = '#/' + (q.toString() ? '?' + q : '');
        };
        form.addEventListener('change', apply);
        form.addEventListener('submit', (e) => { e.preventDefault(); apply(); });
    }


    // Widok listy : karty albo zapasy wg kategorii.
    function viewTabs(current) {
        return `<ul class="nav nav-pills tfb-view-tabs mb-3">
            <li class="nav-item"><a class="nav-link ${current === 'list' ? 'active' : ''}" href="#/"><i class="bi bi-list-ul"></i> ${esc(t('view.list'))}</a></li>
            <li class="nav-item"><a class="nav-link ${current === 'stock' ? 'active' : ''}" href="#/stock"><i class="bi bi-boxes"></i> ${esc(t('view.stock'))}</a></li>
            <li class="nav-item"><a class="nav-link ${current === 'levels' ? 'active' : ''}" href="#/levels"><i class="bi bi-bar-chart-line"></i> ${esc(t('view.levels'))}</a></li></ul>`;
    }

    // --- Zapasy wg kategorii : szybki MTS / MTO, stany min. / maks. w kartonach, sprzedaż, zapas optymalny ---
    async function renderStock(message) {
        const params = new URLSearchParams(location.hash.split('?')[1] || '');
        const group = params.get('group_id') || '';
        const data = await api.get('/produits/stock-overview' + (group ? '?group_id=' + group : ''));
        const p = data.params;
        const n = (v, d) => v === null || v === undefined ? '—' : ui.fmt(v, d ?? 1, api.lang);
        const eur = (v) => v === null || v === undefined ? '—' : ui.fmt(v, 0, api.lang) + ' €';
        const lastCalc = (v) => v ? new Date(String(v).replace(' ', 'T')).toLocaleDateString(api.lang === 'fr' ? 'fr-FR' : 'pl-PL') : '—';
        const row = (i) => {
            const noPpc = !i.ppc;
            return `<tr data-fp="${i.final_product_id}" class="${i.mode === 'MTS' ? 'tfb-stock-mts' : ''}">
                <td><a href="${i.id ? `#/item/${i.id}/4` : `#/product/${i.final_product_id}`}"><strong>${esc(i.name)}</strong></a>${i.erp_active ? '' : ` <span class="badge bg-secondary">${esc(t('erp.inactive'))}</span>`}${i.has_card ? '' : ` <span class="badge bg-light text-muted" title="${esc(t('st.no_card_help'))}">${esc(t('st.no_card'))}</span>`}
                    <div class="small text-muted">${esc(groupName(i.group_id))}${i.ppc ? ' · ' + esc(t('st.ppc', {ppc: i.ppc})) : ''}</div></td>
                <td class="text-center"><div class="form-check form-switch d-inline-block m-0" title="${esc(i.never_frozen ? t('f.never_frozen') : t('st.toggle_help'))}">
                    <input class="form-check-input" type="checkbox" role="switch" data-mts ${i.mode === 'MTS' ? 'checked' : ''} ${i.never_frozen ? 'disabled' : ''} aria-label="${esc(t('mode.MTS'))}"></div>
                    <div class="small ${i.mode === 'MTS' ? 'text-success' : 'text-muted'}">${esc(i.mode === 'MTS' ? t('st.mts') : t('st.mto'))}</div></td>
                <td class="text-end">${n(i.weekly_cartons)}<div class="small text-muted">${esc(t('st.shipped', {c: n(i.shipped_cartons, 0)}))}</div></td>
                <td class="text-end">${i.per_shop_weekly_cartons !== null ? `${n(i.per_shop_weekly_cartons, 2)} ${esc(t('st.cart'))}<div class="small text-muted">${i.per_shop_weekly_quantity !== null ? n(i.per_shop_weekly_quantity, 0) + ' ' + esc(t('st.pcs')) + ' · ' : ''}${esc(t('st.shops', {n: i.shops}))}</div>` : '—'}</td>
                <td><input class="form-control form-control-sm text-end" data-stock="stock_min" inputmode="decimal" value="${i.stock_min_cartons ?? ''}" ${noPpc ? 'disabled' : ''} aria-label="${esc(t('f.stock_min'))}">
                    <div class="small text-muted text-end">${i.stock_min !== null ? n(i.stock_min, 0) + ' ' + esc(t('st.pcs')) : ''}</div></td>
                <td><input class="form-control form-control-sm text-end" data-stock="stock_max" inputmode="decimal" value="${i.stock_max_cartons ?? ''}" ${noPpc ? 'disabled' : ''} aria-label="${esc(t('f.stock_max'))}">
                    <div class="small text-muted text-end">${i.stock_max !== null ? n(i.stock_max, 0) + ' ' + esc(t('st.pcs')) : ''}</div></td>
                <td class="text-end">${i.mode === 'MTS'
                    ? `<strong>${n(i.target_cartons, 0)} ${esc(t('st.cart'))}</strong>
                       <div class="small">${i.target_pallets !== null ? n(i.target_pallets, 2) + ' ' + esc(t('st.pal')) : esc(t('st.no_cpp'))}${i.target_value_eur !== null ? ' · ' + eur(i.target_value_eur) : ''}</div>`
                    : `<span class="text-muted">—</span>`}
                    <div class="small text-muted">${esc(t('st.suggest', {c: n(i.optimal_cartons, 0)}))}</div></td>
                <td class="text-center text-nowrap"><button type="button" class="btn btn-sm btn-outline-primary" data-recalc ${noPpc ? 'disabled' : ''} title="${esc(t('st.recalc_help'))}"><i class="bi bi-arrow-repeat"></i></button>
                    <div class="small text-muted">${esc(lastCalc(i.stock_calc_at))}</div></td></tr>`;
        };
        const all = data.categories.flatMap(c => c.items);
        const tot = data.totals;
        // Zapas MTS wg grupy / kategorii : kartony, palety, wartość (cena sprzedaży), surowce, stan dziś.
        const sumTable = (label, rows) => `<div class="table-responsive"><table class="table table-sm mb-0 tfb-stock-sumtable">
            <thead><tr><th>${esc(label)}</th><th class="text-end">MTS</th><th class="text-end">${esc(t('st.cart'))}</th><th class="text-end">${esc(t('st.pal'))}</th>
                <th class="text-end">${esc(t('st.value'))}</th><th class="text-end">${esc(t('st.cost'))}</th><th class="text-end">${esc(t('st.now'))}</th></tr></thead>
            <tbody>${rows.filter(r => r.totals.mts > 0).map(r => `<tr><td>${esc(r.name)}</td><td class="text-end">${r.totals.mts}/${r.totals.products}</td>
                <td class="text-end">${n(r.totals.target_cartons, 0)}</td><td class="text-end">${n(r.totals.target_pallets, 1)}</td>
                <td class="text-end"><strong>${eur(r.totals.target_value_eur)}</strong>${r.totals.no_price ? `<div class="small text-warning">${esc(t('st.no_price_n', {n: r.totals.no_price}))}</div>` : ''}</td><td class="text-end">${eur(r.totals.target_cost_eur)}</td>
                <td class="text-end">${n(r.totals.current_cartons, 0)} · ${eur(r.totals.current_value_eur)}</td></tr>`).join('') || `<tr><td colspan="7" class="text-muted">${esc(t('st.sum_empty'))}</td></tr>`}</tbody>
            <tfoot><tr><th>${esc(t('total'))}</th><th class="text-end">${tot.mts}/${tot.products}</th><th class="text-end">${n(tot.target_cartons, 0)}</th><th class="text-end">${n(tot.target_pallets, 1)}</th>
                <th class="text-end">${eur(tot.target_value_eur)}</th><th class="text-end">${eur(tot.target_cost_eur)}</th><th class="text-end">${n(tot.current_cartons, 0)} · ${eur(tot.current_value_eur)}</th></tr></tfoot></table></div>`;
        el.innerHTML = header() + viewTabs('stock') + `
            <div class="tfb-stock-bar card"><div class="card-body d-flex flex-wrap gap-3 align-items-center justify-content-between">
                <div>${esc(t('st.params', {min: n(p.min_weeks), max: n(p.max_weeks), weeks: p.weeks}))}
                    <a href="${esc(api.root || '')}/other-settings#stock-params" class="ms-1">${esc(t('st.params_edit'))}</a></div>
                <div class="d-flex gap-2 align-items-center flex-wrap">
                    <select class="form-select form-select-sm" data-group style="width:auto"><option value="">${esc(t('col.group'))}: ${esc(t('all'))}</option>
                        ${state.groups.map(g => `<option value="${g.id}" ${group === String(g.id) ? 'selected' : ''}>${esc(g.name)}</option>`).join('')}</select>
                    <button type="button" class="btn btn-sm btn-primary" data-recalc-all><i class="bi bi-arrow-repeat"></i> ${esc(t('st.recalc_all'))}</button></div>
                <div class="w-100 small text-muted">${esc(t('st.summary', {products: all.length, mts: tot.mts, cartons: n(tot.target_cartons, 0), pallets: n(tot.target_pallets, 1), value: eur(tot.target_value_eur)}))}
                    ${esc(t('st.stock_all', {cartons: n(tot.stock_all_cartons, 0), value: eur(tot.stock_all_value_eur)}))}</div>
            </div></div>
            <div class="card tfb-stock-sum"><div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <h5 class="mb-0">${esc(t('st.sum_title'))}</h5>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-csv><i class="bi bi-filetype-csv"></i> ${esc(t('st.csv'))}</button></div>
                <div class="card-body pt-0"><div class="row g-3">
                    <div class="col-12">${sumTable(t('col.group'), data.groups.map(g => ({name: groupName(g.id), totals: g.totals})).sort((a, b) => b.totals.target_value_eur - a.totals.target_value_eur))}</div>
                    <div class="col-12">${sumTable(t('col.category'), data.categories.map(c => ({name: c.name || t('st.no_category'), totals: c.totals})).sort((a, b) => b.totals.target_value_eur - a.totals.target_value_eur))}</div>
                </div><p class="small text-muted mb-0 mt-2">${esc(t('st.sum_note', {weeks: p.weeks}))}${tot.no_price ? ' ' + esc(t('st.sum_no_price', {n: tot.no_price})) : ''}</p></div></div>
            ${data.categories.map(c => `<div class="card tfb-stock-cat"><div class="card-header d-flex flex-wrap justify-content-between gap-2">
                    <h5 class="mb-0">${esc(c.name || t('st.no_category'))}</h5>
                    <span class="d-flex flex-wrap align-items-center gap-2">
                        <span class="text-muted small">${esc(t('st.cat_totals', {products: c.totals.products, mts: c.totals.mts, weekly: n(c.totals.weekly_cartons), cartons: n(c.totals.target_cartons, 0), pallets: n(c.totals.target_pallets, 1), value: eur(c.totals.target_value_eur)}))}</span>
                        <span class="btn-group btn-group-sm" role="group" aria-label="${esc(t('st.bulk'))}">
                            <button type="button" class="btn btn-outline-success" data-bulk="MTS" data-cat="${c.id ?? ''}">${esc(t('st.bulk_mts'))}</button>
                            <button type="button" class="btn btn-outline-secondary" data-bulk="MTO" data-cat="${c.id ?? ''}">${esc(t('st.bulk_mto'))}</button></span></span></div>
                <div class="card-body pt-0"><div class="table-responsive"><table class="table table-sm align-middle mb-0 tfb-stock-table">
                    <thead><tr><th>${esc(t('col.name'))}</th><th class="text-center">${esc(t('st.col_mode'))}</th>
                        <th class="text-end" title="${esc(t('st.col_weekly_help', {weeks: p.weeks}))}">${esc(t('st.col_weekly'))}</th>
                        <th class="text-end" title="${esc(t('st.col_shop_help'))}">${esc(t('st.col_shop'))}</th>
                        <th class="text-end" style="width:110px">${esc(t('st.col_min'))}</th><th class="text-end" style="width:110px">${esc(t('st.col_max'))}</th>
                        <th class="text-end" title="${esc(t('st.col_target_help', {max: n(p.max_weeks)}))}">${esc(t('st.col_target'))}</th><th class="text-center">${esc(t('st.col_calc'))}</th></tr></thead>
                    <tbody>${c.items.map(row).join('')}</tbody></table></div></div></div>`).join('') || `<p class="text-muted">${esc(t('empty_list'))}</p>`}`;
        if (message) ui.notify(true, message);
        const find = (fp) => all.find(i => i.final_product_id === fp);
        const save = async (item, body, ok) => {
            try {
                await api.patch(`/produits/items/${await ensureCard(item)}/settings`, body);
                const y = window.scrollY;
                await renderStock(ok);
                window.scrollTo(0, y);
                return true;
            } catch (err) { fail(null, err); return false; }
        };
        el.querySelector('[data-group]').addEventListener('change', (e) => { location.hash = '#/stock' + (e.target.value ? '?group_id=' + e.target.value : ''); });
        el.querySelectorAll('tr[data-fp]').forEach(tr => {
            const i = find(Number(tr.dataset.fp));
            tr.querySelector('[data-mts]').addEventListener('change', (e) => modeSwitch(e.target, i, save));
            tr.querySelectorAll('[data-stock]').forEach(input => input.addEventListener('change', () => {
                const c = ui.num(input.value);
                if (c !== null && typeof c !== 'number') { ui.notify(false, t('st.number')); return; }
                save(i, {[input.dataset.stock]: c === null ? null : Math.round(c * i.ppc)}, t('saved'));
            }));
            tr.querySelector('[data-recalc]').addEventListener('click', () => {
                if (i.suggested_max_cartons <= 0) { ui.notify(false, t('st.no_sales')); return; }
                save(i, suggested(i), t('st.recalculated', {name: i.name}));
            });
        });
        // Cała kategoria na magazyn / na zamówienie : MTS bez stanu maks. dostaje stany ze sprzedaży, bez sprzedaży pomijany.
        el.querySelectorAll('[data-bulk]').forEach(btn => btn.addEventListener('click', async () => {
            const mode = btn.dataset.bulk;
            const cat = data.categories.find(c => String(c.id ?? '') === btn.dataset.cat);
            const todo = cat.items.filter(i => i.mode !== mode && !(mode === 'MTS' && i.never_frozen));
            const skipped = mode === 'MTS' ? todo.filter(i => i.stock_max === null && (!i.ppc || i.suggested_max_cartons <= 0)) : [];
            const go = todo.filter(i => !skipped.includes(i));
            if (!go.length) { ui.notify(false, t('st.bulk_none')); return; }
            if (!window.confirm(t('st.bulk_confirm', {n: go.length, mode: t(mode === 'MTS' ? 'st.mts' : 'st.mto'), cat: cat.name || t('st.no_category')}))) return;
            btn.disabled = true;
            let done = 0;
            for (const i of go) {
                const body = mode === 'MTS' && i.stock_max === null ? {mode, ...suggested(i)} : {mode};
                try { await api.patch(`/produits/items/${await ensureCard(i)}/settings`, body); done++; } catch (err) { /* produit suivant */ }
                btn.textContent = `${done}/${go.length}`;
            }
            const y = window.scrollY;
            await renderStock(t('st.bulk_done', {n: done}) + (skipped.length ? ' ' + t('st.bulk_skipped', {n: skipped.length}) : ''));
            window.scrollTo(0, y);
        }));
        // Eksport CSV (średnik, przecinek dziesiętny) : produkty z zapasem docelowym i wartością.
        el.querySelector('[data-csv]').addEventListener('click', () => {
            const num = (v, d) => v === null || v === undefined ? '' : Number(v).toFixed(d).replace('.', ',');
            const cell = (v) => '"' + String(v ?? '').replace(/"/g, '""') + '"';
            const head = ['Grupa', 'Kategoria', 'Produkt', 'Tryb', 'Szt./karton', 'Kart./paleta', 'Sprzedaż kart./tydz.', 'Stan min. (kart.)', 'Stan maks. (kart.)',
                'Zapas docelowy (kart.)', 'Palety', 'Cena €/karton', 'Wartość zapasu €', 'Surowce €/karton', 'Surowce zapasu €', 'Stan dziś (kart.)', 'Wartość dziś €'];
            const lines = data.categories.flatMap(c => c.items.map(i => [groupName(i.group_id), c.name || t('st.no_category'), i.name, i.mode, i.ppc ?? '', i.cpp ?? '',
                num(i.weekly_cartons, 2), num(i.stock_min_cartons, 1), num(i.stock_max_cartons, 1), i.target_cartons, num(i.target_pallets, 2),
                num(i.price_eur, 2), num(i.target_value_eur, 2), num(i.cost_eur, 2), num(i.target_cost_eur, 2), num(i.free_cartons, 0), num(i.current_value_eur, 2)]));
            const csv = '\ufeff' + [head, ...lines].map(r => r.map(cell).join(';')).join('\r\n');
            const a = document.createElement('a');
            a.href = URL.createObjectURL(new Blob([csv], {type: 'text/csv;charset=utf-8'}));
            a.download = 'zapas-mts-' + new Date().toISOString().slice(0, 10) + '.csv';
            a.click();
            setTimeout(() => URL.revokeObjectURL(a.href), 1000);
        });
        el.querySelector('[data-recalc-all]').addEventListener('click', async (e) => {
            const todo = all.filter(i => i.mode === 'MTS' && i.ppc && i.suggested_max_cartons > 0);
            if (!todo.length) { ui.notify(false, t('st.recalc_none')); return; }
            if (!window.confirm(t('st.recalc_confirm', {n: todo.length}))) return;
            e.target.disabled = true;
            let done = 0;
            for (const i of todo) {
                try { await api.patch(`/produits/items/${i.id}/settings`, suggested(i)); done++; } catch (err) { /* produit suivant */ }
                e.target.textContent = `${done}/${todo.length}`;
            }
            await renderStock(t('st.recalc_done', {n: done}));
        });
    }

    // Produkt ERP bez karty : karta tworzona przy pierwszej zmianie (grupa domyślna z działu ERP).
    async function ensureCard(i) {
        if (i.id) return i.id;
        const item = await api.post('/produits/items', {item_type: 'product', final_product_id: i.final_product_id});
        i.id = item.id;
        i.has_card = true;
        return i.id;
    }

    // Stany ze sprzedaży (min = tygodnie min., maks. = tygodnie maks.), w sztukach.
    const suggested = (i) => ({stock_min: i.suggested_min_cartons * i.ppc, stock_max: i.suggested_max_cartons * i.ppc, stock_calculated: true});

    // Przełącznik na magazyn (MTS) / na zamówienie (MTO) : włączenie bez stanu maks. bierze stany ze sprzedaży.
    async function modeSwitch(input, i, save) {
        const on = input.checked;
        let body = {mode: on ? 'MTS' : 'MTO'};
        if (on && i.stock_max === null) {
            if (!i.ppc || i.suggested_max_cartons <= 0) { input.checked = false; ui.notify(false, t('st.need_max')); return; }
            body = {...body, ...suggested(i)};
        }
        if (!(await save(i, body, t(on ? 'st.now_mts' : 'st.now_mto', {name: i.name})))) input.checked = !on;
    }

    // Partie produkcyjne do stanu min. (w górę) i pełne partie mieszczące się do stanu maks. — razem
    // z zamówieniami ponad stan (jak krok « Tryb i zapasy » karty).
    function batchesCell(i) {
        const orders = i.orders_over_stock_cartons || 0;
        if (!i.batch || !i.ppc || (!i.to_min_cartons && !i.to_max_cartons && !orders)) return '';
        const bc = i.batch / i.ppc;
        return `<div class="small tfb-plan-batch">${esc(t('lv.batches', {min: Math.ceil(((i.to_min_cartons || 0) + orders) / bc - 1e-6), max: Math.floor(((i.to_max_cartons || 0) + orders) / bc + 1e-6)}))}</div>`;
    }

    // --- Stan magazynu : stan wolny, zamówienia, min. / maks., do produkcji poza zamówieniami --------
    async function renderLevels(message) {
        const params = new URLSearchParams(location.hash.split('?')[1] || '');
        const group = params.get('group_id') || '';
        const mode = params.get('mode') || 'MTS';
        const data = await api.get('/produits/stock-overview' + (group ? '?group_id=' + group : ''));
        const n = (v, d) => v === null || v === undefined ? '—' : ui.fmt(v, d ?? 0, api.lang);
        const all = data.categories.flatMap(c => c.items.map(i => ({...i, category: c.name || t('st.no_category')})));
        const rows = all.filter(i => mode === 'all' || i.mode === mode)
            .sort((a, b) => (b.to_min_cartons || 0) - (a.to_min_cartons || 0) || (b.orders_over_stock_cartons || 0) - (a.orders_over_stock_cartons || 0) || a.name.localeCompare(b.name));
        const mts = rows.filter(i => i.mode === 'MTS');
        const below = mts.filter(i => i.below_min);
        const sum = (list, f) => list.reduce((a, i) => a + (i[f] || 0), 0);
        const link = (m) => '#/levels?' + new URLSearchParams({...(group ? {group_id: group} : {}), mode: m});
        el.innerHTML = header() + viewTabs('levels') + `
            <div class="tfb-stock-bar card"><div class="card-body d-flex flex-wrap gap-3 align-items-center justify-content-between">
                <div class="btn-group btn-group-sm" role="group" aria-label="${esc(t('col.mode'))}">
                    ${['MTS', 'MTO', 'all'].map(m => `<a class="btn ${mode === m ? 'btn-primary' : 'btn-outline-primary'}" href="${link(m)}">${esc(t('lv.mode_' + m))}</a>`).join('')}</div>
                <select class="form-select form-select-sm" data-group style="width:auto"><option value="">${esc(t('col.group'))}: ${esc(t('all'))}</option>
                    ${state.groups.map(g => `<option value="${g.id}" ${group === String(g.id) ? 'selected' : ''}>${esc(g.name)}</option>`).join('')}</select>
                <div class="small">${mode !== 'MTO' ? esc(t('lv.summary', {below: below.length, products: mts.length, to_min: n(sum(mts, 'to_min_cartons')), to_max: n(sum(mts, 'to_max_cartons'))}))
                    : esc(t('lv.summary_mto', {products: rows.length, over: n(sum(rows, 'orders_over_stock_cartons'))}))}</div>
            </div></div>
            <div class="card"><div class="card-body pt-3"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0 tfb-stock-table">
                <thead><tr><th>${esc(t('col.name'))}</th><th class="text-center">${esc(t('st.col_mode'))}</th>
                    <th class="text-end" title="${esc(t('lv.free_help'))}">${esc(t('lv.free'))}</th><th class="text-end" title="${esc(t('lv.ordered_help'))}">${esc(t('lv.ordered'))}</th>
                    <th class="text-end" title="${esc(t('lv.in_production_help'))}">${esc(t('lv.in_production'))}</th><th class="text-end" title="${esc(t('lv.available_help'))}">${esc(t('lv.available'))}</th>
                    <th class="text-end">${esc(t('lv.min'))}</th><th class="text-end">${esc(t('lv.max'))}</th>
                    <th class="text-end" title="${esc(t('lv.to_produce_help'))}">${esc(t('lv.to_produce'))}</th></tr></thead>
                <tbody>${rows.map(i => `<tr data-fp="${i.final_product_id}" class="${i.below_min ? 'tfb-level-low' : ''}">
                    <td><a href="${i.id ? `#/item/${i.id}/4` : `#/product/${i.final_product_id}`}"><strong>${esc(i.name)}</strong></a><div class="small text-muted">${esc(i.category)} · ${esc(groupName(i.group_id))}${i.ppc ? ' · ' + esc(t('st.ppc', {ppc: i.ppc})) : ''}</div></td>
                    <td class="text-center"><div class="form-check form-switch d-inline-block m-0" title="${esc(i.never_frozen ? t('f.never_frozen') : t('st.toggle_help'))}">
                        <input class="form-check-input" type="checkbox" role="switch" data-mts ${i.mode === 'MTS' ? 'checked' : ''} ${i.never_frozen ? 'disabled' : ''} aria-label="${esc(t('mode.MTS'))}"></div></td>
                    <td class="text-end">${n(i.free_cartons)}</td>
                    <td class="text-end">${i.ordered_cartons ? n(i.ordered_cartons) : '—'}</td>
                    <td class="text-end">${i.in_production_cartons ? n(i.in_production_cartons, 1) : '—'}</td>
                    <td class="text-end"><strong class="${i.available_cartons < 0 ? 'text-danger' : ''}">${n(i.available_cartons, 1)}</strong></td>
                    <td class="text-end">${n(i.stock_min_cartons, 1)}</td><td class="text-end">${n(i.stock_max_cartons, 1)}</td>
                    <td class="text-end">${i.mode === 'MTS'
                        ? (i.to_min_cartons === null ? `<span class="text-muted small">${esc(t('lv.no_min'))}</span>`
                            : `<strong class="${i.to_min_cartons > 0 ? 'text-danger' : 'text-success'}">${i.to_min_cartons > 0 ? n(i.to_min_cartons) + ' ' + esc(t('st.cart')) : '✓ 0'}</strong>
                               ${i.to_max_cartons ? `<div class="small text-muted">${esc(t('lv.to_max', {c: n(i.to_max_cartons)}))}</div>` : ''}`)
                          + (i.orders_over_stock_cartons > 0 ? `<div class="small text-danger">${esc(t('lv.orders_short', {c: n(i.orders_over_stock_cartons)}))}</div>` : '')
                          + batchesCell(i)
                        : (i.orders_over_stock_cartons > 0 ? `<strong>${n(i.orders_over_stock_cartons)} ${esc(t('st.cart'))}</strong><div class="small text-muted">${esc(t('lv.for_orders'))}</div>` : '<span class="text-muted">—</span>')}</td>
                </tr>`).join('') || `<tr><td colspan="9" class="text-muted">${esc(t('empty_list'))}</td></tr>`}</tbody></table></div>
                <p class="text-muted small mb-0 mt-2">${esc(t('lv.note'))}</p></div></div>`;
        if (message) ui.notify(true, message);
        const save = async (item, body, ok) => {
            try {
                await api.patch(`/produits/items/${await ensureCard(item)}/settings`, body);
                const y = window.scrollY;
                await renderLevels(ok);
                window.scrollTo(0, y);
                return true;
            } catch (err) { fail(null, err); return false; }
        };
        el.querySelector('[data-group]').addEventListener('change', (e) => { location.hash = '#/levels?' + new URLSearchParams({...(e.target.value ? {group_id: e.target.value} : {}), mode}); });
        el.querySelectorAll('tr[data-fp]').forEach(tr => {
            const i = all.find(x => x.final_product_id === Number(tr.dataset.fp));
            tr.querySelector('[data-mts]').addEventListener('change', (e) => modeSwitch(e.target, i, save));
        });
    }

    // --- Nouvelle fiche (étape 1) ---------------------------------------------------
    async function renderNew() {
        el.innerHTML = header(`<a class="btn btn-outline-primary btn-sm" href="#/">${esc(t('back_to_list'))}</a>`) + `
            <div class="card"><div class="card-body">
                <h4>${esc(t('new.title'))}</h4><p class="text-muted">${esc(t('new.help'))}</p>
                <p><a class="btn btn-sm btn-outline-primary" href="${esc(api.root)}/products/new-with-recipe"><i class="bi bi-plus-lg"></i> ${esc(t('new.with_recipe'))}</a></p>
                <div class="tfb-filters">
                    <select class="form-select form-select-sm" data-type>${['product', 'semi'].map(s => `<option value="${s}">${esc(t('type.' + s))}</option>`).join('')}</select>
                    <input class="form-control form-control-sm" data-search placeholder="${esc(t('search'))}">
                </div>
                <div data-candidates class="tfb-candidates"></div>
            </div></div>`;
        const type = el.querySelector('[data-type]');
        const search = el.querySelector('[data-search]');
        const box = el.querySelector('[data-candidates]');
        let timer = null;
        const load = async () => {
            const res = await api.list(`/produits/candidates?item_type=${type.value}&search=${encodeURIComponent(search.value.trim())}`);
            box.innerHTML = res.data.map(c => `<button type="button" class="tfb-candidate" data-id="${c.id}">
                <strong>${esc(c.name)}</strong><span class="text-muted">${c.ppc ? esc(t('erp.ppc')) + ': ' + c.ppc : ''}</span></button>`).join('')
                || `<p class="text-muted">${esc(t('new.none'))}</p>`;
        };
        type.addEventListener('change', load);
        search.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(load, 250); });
        box.addEventListener('click', async (e) => {
            const b = e.target.closest('[data-id]');
            if (!b) return;
            b.disabled = true;
            try {
                const body = type.value === 'product' ? {item_type: 'product', final_product_id: Number(b.dataset.id)} : {item_type: 'semi', recipe_id: Number(b.dataset.id)};
                const item = await api.post('/produits/items', body);
                location.hash = `#/item/${item.id}/1`;
            } catch (err) { b.disabled = false; fail(null, err); }
        });
        await load();
    }

    // --- Produit ERP → sa fiche (créée si besoin) : point d'entrée de l'édition d'un produit ---
    async function openProduct(productId, step) {
        const found = await api.list(`/produits/items?final_product_id=${productId}`);
        let item = found.data[0];
        if (!item) {
            try {
                item = await api.post('/produits/items', {item_type: 'product', final_product_id: productId});
                // Après « Nowy produkt z recepturą » : produit déjà saisi, l'assistant reprend à la logistique.
                if (step > 1) await api.patch(`/produits/items/${item.id}/settings`, {wizard_step: Math.min(STEPS, step)});
            } catch (err) {
                el.innerHTML = header() + `<div class="alert alert-warning">${esc(err.message)}</div>
                    <a class="btn btn-outline-secondary" href="${esc(api.root)}/products/${productId}/edit?classic=1">${esc(t('p.classic_form'))}</a>`;
                return;
            }
        }
        history.replaceState(null, '', `#/item/${item.id}/${Math.min(STEPS, Math.max(1, step))}`);
        await renderItem(item.id, Math.min(STEPS, Math.max(1, step)));
    }

    // --- Fiche : assistant -------------------------------------------------------------
    async function renderItem(id, step) {
        state.item = await api.get(`/produits/items/${id}`);
        const item = state.item;
        const ro = !item.can_edit;
        el.innerHTML = header(`<a class="btn btn-outline-primary btn-sm" href="#/">${esc(t('back_to_list'))}</a>`) + `
            <div class="tfb-item-title"><h4>${esc(item.name)}</h4>
                <span class="badge ${item.settings.status === 'validated' ? 'bg-success' : 'bg-warning'}">${esc(t('status.' + item.settings.status))}</span>
                <span class="text-muted">${esc(t('type.' + item.item_type))} · ${esc(groupName(item.settings.primary_group_id))}</span></div>
            ${ro ? `<div class="alert alert-info">${esc(t('read_only'))}</div>` : ''}
            <ol class="at-steps at-steps-links at-steps-compact" aria-label="${esc(t('steps'))}">${Array.from({length: STEPS}, (_, k) => k + 1).map(n => `<li class="${n === step ? 'is-active' : ''} ${n !== step && (n < item.settings.wizard_step || item.settings.status === 'validated') ? 'is-done' : ''}">
                <a href="#/item/${id}/${n}" ${n === step ? 'aria-current="step"' : ''}><span>${esc(t('step.' + n))}</span></a></li>`).join('')}</ol>
            <div class="card"><div class="card-body" data-step-body></div></div>`;
        const body = el.querySelector('[data-step-body]');
        const renderers = {1: stepProduct, 2: stepArticle, 3: stepBom, 4: stepMode, 5: stepGroup, 6: stepRouting, 7: stepTimes, 8: stepValidate};
        await renderers[step](body, item, ro);
        if (ro) body.querySelectorAll('input, select, textarea, button[data-write]').forEach(x => x.disabled = true);
    }

    function nav(step, saveLabel) {
        return `<div class="tfb-nav">
            ${step > 1 ? `<a class="btn btn-outline-secondary" href="#/item/${state.item.id}/${step - 1}">${esc(t('back'))}</a>` : '<span></span>'}
            ${saveLabel === false ? (step < STEPS ? `<a class="btn btn-primary" href="#/item/${state.item.id}/${step + 1}">${esc(t('next'))}</a>` : '')
                : `<button type="submit" class="btn btn-primary" data-write>${esc(saveLabel || t('save_next'))}</button>`}
        </div>`;
    }

    async function saveSettings(form, data, nextStep) {
        ui.clearErrors(form);
        try {
            await api.patch(`/produits/items/${state.item.id}/settings`, {...data, wizard_step: Math.max(state.item.settings.wizard_step, nextStep)});
            ui.notify(true, t('saved'));
            location.hash = `#/item/${state.item.id}/${nextStep}`;
        } catch (err) { fail(form, err); }
    }

    // --- 1. Produit : données de l'ERP (mêmes champs que l'ancien formulaire d'édition) ----------
    function stepProduct(body, item) {
        const e = item.erp;
        if (item.item_type !== 'product') {
            body.innerHTML = `<dl class="tfb-dl"><dt>${esc(t('col.name'))}</dt><dd>${esc(item.name)}</dd><dt>${esc(t('col.type'))}</dt><dd>${esc(t('type.' + item.item_type))}</dd>
                <dt>${esc(t('erp.unit'))}</dt><dd>${esc(item.base_unit)}</dd></dl><p class="text-muted">${esc(t('p.semi_help'))}</p>${nav(1, false)}`;
            return;
        }
        const options = (list, current) => `<option value="">—</option>` + list.map(o => `<option value="${o.id}" ${o.id === current ? 'selected' : ''}>${esc(o.name)}</option>`).join('');
        body.innerHTML = `<form class="tfb-form" data-form>
            <label class="tfb-full">${esc(t('p.name'))}<input class="form-control" name="name" data-field="name" maxlength="255" required value="${esc(e.name || item.name)}"></label>
            <label>${esc(t('p.category'))}<select class="form-select" name="category_id" data-field="category_id" required>${options(state.erp.categories, e.category_id)}</select></label>
            <label>${esc(t('p.section'))}<select class="form-select" name="section_id" data-field="section_id" required>${options(state.erp.sections, e.section_id)}</select>
                <span class="form-text">${esc(t('p.section_help'))}</span></label>
            <label>${esc(t('p.label_type'))}<select class="form-select" name="label_type" data-field="label_type">
                ${state.erp.label_types.map(v => `<option value="${v}" ${v === e.label_type ? 'selected' : ''}>${esc(t('p.label_type.' + v))}</option>`).join('')}</select></label>
            <label>${esc(t('p.shelf_life'))}<input class="form-control" name="shelf_life" data-field="shelf_life" inputmode="numeric" value="${e.shelf_life ?? ''}"></label>
            <p class="form-text tfb-full">${esc(t('p.help'))}</p>
            ${nav(1)}</form>`;
        const form = body.querySelector('[data-form]');
        form.addEventListener('submit', async (ev) => {
            ev.preventDefault();
            ui.clearErrors(form);
            const data = {name: form.name.value.trim(), label_type: Number(form.label_type.value)};
            for (const f of ['category_id', 'section_id', 'shelf_life']) { const v = ui.num(form[f].value); if (v !== null) data[f] = v; }
            try {
                await api.put(`/produits/items/${item.id}/product`, data);
                await saveSettings(form, {}, 2);
            } catch (err) { fail(form, err); }
        });
    }

    // Recette : liens vers la kalkulacja (composition, préparation, coût) ou vers la création.
    function recipeLinks(item) {
        return item.recipe_id
            ? `<p class="tfb-recipe-links"><a class="btn btn-sm btn-outline-primary" href="${esc(api.root)}/recipes/${item.recipe_id}/calculation"><i class="bi bi-calculator"></i> ${esc(t('bom.calculation'))}</a>
                <span class="form-text">${esc(t('bom.calculation_help'))}</span></p>`
            : `<p class="alert alert-warning">${esc(t('bom.no_recipe'))}</p>`;
    }

    // Résumé de production (pièces/heure, étapes et délais D+1, plan de la semaine) : /production-summary.
    async function productionSummary(box, item) {
        if (!box) return;
        let p;
        try { p = await api.get(`/produits/items/${item.id}/production-summary`); } catch (err) { box.innerHTML = ''; return; }
        const n = (v, d = 0) => v === null || v === undefined ? '—' : ui.fmt(v, d, api.lang);
        const dur = (s) => ui.duration(Math.round(s), api);
        const offset = (s) => { const d = Math.floor(s / 86400), r = s % 86400; return (d ? t('s.day') + '+' + d + ' ' : '') + '+' + Math.floor(r / 3600) + ':' + String(Math.floor(r % 3600 / 60)).padStart(2, '0'); };
        const day = (d) => new Date(d + 'T00:00:00').toLocaleDateString(api.lang === 'fr' ? 'fr-FR' : 'pl-PL', {weekday: 'short', day: '2-digit', month: '2-digit'});
        const gap = (g, last) => g.kind === 'next_day' ? `<span class="at-pill at-pill-warn">${esc(t('s.day'))}+1</span> ${esc(t('s.after', {time: dur(g.seconds)}))}`
            : g.kind === 'minutes' ? `<span class="at-pill">+${esc(dur(g.seconds))}</span>` : `<span class="text-muted">${esc(t(last ? 's.end' : 's.at_once'))}</span>`;
        const w = p.week;
        box.innerHTML = `<div class="at-prodsum"><h5>${esc(t('s.title'))}</h5>
            <div class="at-prodsum-kpis">
                <div><span>${esc(t('s.per_hour'))}</span><strong>${n(p.per_hour)} ${esc(t('s.pcs_h'))}</strong><small>${esc(t('s.per_person'))}${p.per_hour_group !== null ? ' · ' + esc(t('s.group', {people: p.group_people, value: n(p.per_hour_group)})) : ''}</small></div>
                <div><span>${esc(t('s.labour'))}</span><strong>${p.minutes_per_carton !== null ? n(p.minutes_per_carton, 1) + ' ' + esc(t('s.min_carton')) : dur(p.labour_s)}</strong><small>${n(p.hours_per_1000, 1)} ${esc(t('s.h_1000'))}</small></div>
                <div><span>${esc(t('s.lead'))}</span><strong>${esc(dur(p.lead_time_s))}</strong><small>${esc(t('s.reference.' + p.reference.kind))}: ${n(p.reference.quantity)} ${esc(item.base_unit)}${p.next_day ? ' · ' + esc(t('s.next_day')) : ''}</small></div>
                <div><span>${esc(t('s.week', {week: w.iso_week}))}</span><strong>${w.quantity > 0 ? n(w.quantity) + ' ' + esc(item.base_unit) : '—'}</strong>
                    <small>${w.slots.length ? w.slots.map(x => esc(day(x.day)) + ': ' + n(x.quantity)).join(' · ') : esc(t('s.no_days'))}${w.operations ? ' · ' + esc(t('s.operations', {count: w.operations, done: w.done})) : ''}</small></div>
            </div>
            ${p.steps.length ? `<div class="table-responsive"><table class="table table-sm at-prodsum-table"><thead><tr><th>${esc(t('s.step'))}</th><th class="text-end">${esc(t('s.passes'))}</th>
                <th class="text-end">${esc(t('s.pcs_h'))}</th><th>${esc(t('s.start'))}</th><th>${esc(t('s.next'))}</th></tr></thead>
                <tbody>${p.steps.map((x, i) => `<tr><td><strong>${x.step_no}. ${esc(x.name)}</strong><div class="text-muted small">${esc(x.workstation)}</div></td>
                    <td class="text-end">${x.passes} · ${esc(dur(x.work_s))}</td><td class="text-end">${n(x.per_hour)}</td><td>${esc(offset(x.start_s))}</td>
                    <td>${gap(x.gap, i === p.steps.length - 1)}${x.delay_place ? `<div class="text-muted small">${esc(x.delay_place)}</div>` : ''}</td></tr>`).join('')}</tbody></table></div>` : ''}
            <p class="form-text">${esc(t('s.help'))}</p></div>`;
    }

    function stepArticle(body, item) {
        const info = `<dl class="tfb-dl">
            <dt>${esc(t('col.name'))}</dt><dd>${esc(item.name)}</dd>
            <dt>${esc(t('col.type'))}</dt><dd>${esc(t('type.' + item.item_type))}</dd>
            <dt>${esc(t('erp.unit'))}</dt><dd>${esc(item.base_unit)}</dd>
        </dl>`;
        if (item.item_type !== 'product') {
            body.innerHTML = `${info}${item.erp.weight ? `<p>${esc(t('erp.weight'))}: ${ui.fmt(item.erp.weight, 1, api.lang)}</p>` : ''}<p class="text-muted">${esc(t('new.help'))}</p>${nav(2, false)}`;
            return;
        }
        const e = item.erp;
        const lg = item.logistics || {computed: {}};
        const c = lg.computed || {};
        const field = (name, value) => `<label>${esc(t('l.' + name))}<input class="form-control" name="${name}" data-field="${name}" inputmode="numeric" value="${value ? value : ''}"></label>`;
        const choice = (name, values, current) => `<label>${esc(t('l.' + name))}<select class="form-select" name="${name}" data-field="${name}"><option value="">—</option>
            ${values.map(v => `<option value="${v}" ${current === v ? 'selected' : ''}>${esc(t('l.' + name + '.' + v))}</option>`).join('')}</select></label>`;
        const out = (label, value) => `<div class="tfb-calc"><span>${esc(label)}</span><strong>${value}</strong></div>`;
        const kg = (v) => v === null || v === undefined ? '—' : ui.fmt(v, v < 10 ? 2 : 1, api.lang) + ' kg';
        body.innerHTML = `${info}<form class="tfb-form" data-form>
            <h5 class="tfb-full">${esc(t('l.title_units'))}</h5>
            ${field('weight', e.weight)}${field('ppb', e.ppb)}${field('bpc', e.bpc)}${field('ppc', e.ppc)}
            <h5 class="tfb-full">${esc(t('l.title_pallet'))}</h5>
            ${field('cpl', e.cpl)}${field('lpp', e.lpp)}${field('cpp', e.cpp)}
            ${choice('pallet_type', ['eur', 'industrial', 'other'], lg.pallet_type)}${choice('storage', ['frozen', 'chilled', 'ambient'], lg.storage)}
            <div class="tfb-full tfb-calcs">
                ${out(t('l.carton_net'), kg(c.carton_net_kg))}
                ${out(t('l.pallet_units'), c.pallet_units ? ui.fmt(c.pallet_units, 0, api.lang) : '—')}
                ${out(t('l.pallet_gross'), kg(c.pallet_gross_kg ?? c.pallet_net_kg))}
                ${out(t('l.labour_carton'), c.labour_s_per_carton ? ui.duration(c.labour_s_per_carton, api) : '—')}
            </div>
            <p class="form-text tfb-full">${esc(t('help.logistics'))}</p>
            ${nav(2)}</form>`;
        const form = body.querySelector('[data-form]');
        // Cartons par palette proposés = couches × cartons par couche, tant que l'utilisateur ne l'a pas saisi.
        const syncPallet = () => {
            const cpl = ui.num(form.cpl.value);
            const lpp = ui.num(form.lpp.value);
            if (cpl && lpp && !form.cpp.dataset.touched) form.cpp.value = cpl * lpp;
        };
        form.cpp.addEventListener('input', () => { form.cpp.dataset.touched = '1'; });
        form.cpl.addEventListener('input', syncPallet);
        form.lpp.addEventListener('input', syncPallet);
        form.addEventListener('submit', async (ev) => {
            ev.preventDefault();
            ui.clearErrors(form);
            const data = {};
            // Champs de l'ERP : un champ vide garde la valeur de l'ERP ; champs de la fiche : vide = effacé.
            for (const f of ['weight', 'ppb', 'bpc', 'ppc', 'cpl', 'lpp', 'cpp']) { const v = ui.num(form[f].value); if (v !== null) data[f] = v; }
            for (const f of ['pallet_type', 'storage']) data[f] = form[f].value || null;
            try {
                await api.put(`/produits/items/${item.id}/logistics`, data);
                await saveSettings(form, {}, 3);
            } catch (err) { fail(form, err); }
        });
    }

    function stepMode(body, item) {
        const s = item.settings;
        // Produkt z kartonem : stany min. / maks. w kartonach lub sztukach (zapis w sztukach) ; pokrycia nie ma
        // w kreatorze, partia produkcyjna jest w kroku « Etapy i czasy ».
        const ppc = item.item_type === 'product' && item.erp && item.erp.ppc > 0 ? item.erp.ppc : 0;
        const PAIRED = ['stock_min', 'stock_max'];
        const cartons = (v) => v === null || v === undefined || v === '' || !Number.isFinite(Number(v)) ? '' : String(Math.round(Number(v) / ppc * 100) / 100).replace('.', ',');
        const qtyField = (f) => ppc && PAIRED.includes(f)
            ? `<label>${esc(t('f.' + f))}<div class="input-group tfb-qty-pair">
                <input class="form-control" name="${f}_cartons" inputmode="decimal" value="${cartons(s[f])}" aria-label="${esc(t('f.' + f))} — ${esc(t('m.cartons'))}">
                <span class="input-group-text">${esc(t('m.cartons'))}</span>
                <input class="form-control" name="${f}" data-field="${f}" inputmode="numeric" value="${s[f] ?? ''}" aria-label="${esc(t('f.' + f))} — ${esc(t('m.pieces'))}">
                <span class="input-group-text">${esc(t('m.pieces'))}</span></div></label>`
            : `<label>${esc(t('f.' + f))}<input class="form-control" name="${f}" data-field="${f}" inputmode="decimal" value="${s[f] ?? ''}"></label>`;
        body.innerHTML = `<form class="tfb-form" data-form>
            <div class="tfb-full tfb-mode" data-field="mode">
                <span class="tfb-mode-label" data-mode-label="MTO">${esc(t('mode.MTO'))}</span>
                <label class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" role="switch" name="mts" aria-label="${esc(t('f.mode'))}: ${esc(t('mode.MTS'))}" ${s.mode === 'MTS' ? 'checked' : ''}></label>
                <span class="tfb-mode-label" data-mode-label="MTS">${esc(t('mode.MTS'))}</span>
                <span class="form-text" data-mode-help></span>
            </div>
            <label class="form-check tfb-full"><input class="form-check-input" type="checkbox" name="never_frozen" data-field="never_frozen" ${s.never_frozen ? 'checked' : ''}> ${esc(t('f.never_frozen'))}
                <span class="form-text d-block">${esc(t('help.never_frozen'))}</span></label>
            ${PAIRED.map(qtyField).join('')}
            ${ppc ? `<p class="form-text tfb-full mt-0">${esc(t('m.per_carton', {ppc}))}</p>` : ''}
            ${ppc ? '<div class="tfb-full tfb-batch-calc" data-batch-calc></div>' : ''}
            ${item.item_type === 'product' ? `<div class="tfb-full tfb-suggest" data-suggest-box>
                <button type="button" class="btn btn-sm btn-outline-primary" data-suggest data-write><i class="bi bi-calculator"></i> ${esc(t('m.suggest'))}</button>
                <span class="form-text" data-suggest-info>${esc(t('m.suggest_help'))}</span>
                <span class="form-text tfb-suggest-last" data-suggest-last>${esc(s.stock_calc_at ? t('m.suggest_last', {date: new Date(String(s.stock_calc_at).replace(' ', 'T')).toLocaleString(api.lang === 'fr' ? 'fr-FR' : 'pl-PL', {day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit'})}) : t('m.suggest_never'))}</span></div>` : ''}
            <p class="form-text tfb-full">${esc(t('help.mts'))}</p>
            ${nav(4)}</form>`;
        const form = body.querySelector('[data-form]');
        // Kartony ↔ sztuki : zmiana jednego pola przelicza drugie.
        const fillCartons = (f) => { if (ppc && form[f + '_cartons']) form[f + '_cartons'].value = cartons(ui.num(form[f].value)); };
        if (ppc) PAIRED.forEach(f => {
            form[f + '_cartons'].addEventListener('input', () => { const c = ui.num(form[f + '_cartons'].value); if (c === null || typeof c === 'number') form[f].value = c === null ? '' : Math.round(c * ppc); });
            form[f].addEventListener('input', () => fillCartons(f));
        });
        // Partie produkcyjne (krok « Etapy i czasy ») : ile partii brakuje do stanu min. (w górę) i ile pełnych
        // partii mieści się do stanu maks. — od stanu dostępnego (stan − zamówienia + w produkcji), jak « Stan magazynu ».
        const drawBatches = () => {
            const box = form.querySelector('[data-batch-calc]');
            if (!box) return;
            const b = s.batch_multiple;
            const f = (v, d) => ui.fmt(v, d ?? 0, api.lang);
            const title = `<div class="tfb-batch-calc-title"><i class="bi bi-boxes"></i> ${esc(t('m.batch_title'))}</div>`;
            if (!b) {
                box.innerHTML = title + `<div>${esc(t('m.batch_none'))} <a href="#/item/${item.id}/6">${esc(t('m.batch_set'))}</a></div>`;
                return;
            }
            const bc = b / ppc;
            const st = item.stock;
            const lines = [`<strong>${esc(t('m.batch_size', {qty: f(b), unit: item.base_unit, cartons: f(bc, 1)}))}</strong> · <a href="#/item/${item.id}/6">${esc(t('m.batch_change'))}</a>`];
            const min = form.mts.checked ? ui.num(form.stock_min.value) : null;
            const max = form.mts.checked ? ui.num(form.stock_max.value) : null;
            const stocks = typeof min === 'number' || typeof max === 'number';
            if (st) {
                lines.push(esc(t('m.batch_available', {available: f(st.available_cartons, 1), free: f(st.free_cartons), ordered: f(st.ordered_cartons), production: f(st.in_production_cartons, 1)})));
                // Zamówienia ponad stan osobno tylko bez stanów MTS (inaczej są w brakach do stanów poniżej).
                if (st.orders_over_stock_cartons > 0 && !stocks) lines.push(esc(t('m.batch_orders', {cartons: f(st.orders_over_stock_cartons), batches: f(Math.ceil(st.orders_over_stock_cartons / bc - 1e-6))})));
            }
            // Od dostępnego (stan − zamówienia + w produkcji, może być ujemne) : brak do stanu obejmuje zamówienia ponad stan.
            const avail = st ? st.available_cartons : 0;
            const withOrders = avail < 0 ? ' ' + t('m.batch_with_orders') : '';
            if (typeof min === 'number') {
                const miss = Math.max(0, min / ppc - avail);
                lines.push(esc((miss > 0.05 ? t('m.batch_to_min', {min: f(min / ppc, 1), missing: f(miss, 1), batches: f(Math.ceil(miss / bc - 1e-6))}) : t('m.batch_min_ok', {min: f(min / ppc, 1)})) + (miss > 0.05 ? withOrders : '')));
            }
            if (typeof max === 'number') {
                const miss = Math.max(0, max / ppc - avail);
                lines.push(esc(t('m.batch_to_max', {max: f(max / ppc, 1), missing: f(miss, 1), exact: f(miss / bc, 1), whole: f(Math.floor(miss / bc + 1e-6))}) + (miss > 0.05 ? withOrders : '')));
                if (max < b) lines.push('⚠ ' + esc(t('r.batch_over_max', {max: f(max), unit: item.base_unit})));
            }
            box.innerHTML = title + lines.map(l => `<div>${l}</div>`).join('');
        };
        const sync = () => {
            const never = form.never_frozen.checked;
            form.mts.disabled = never;
            if (never) form.mts.checked = false;
            const mts = form.mts.checked;
            form.querySelectorAll('[data-mode-label]').forEach(x => x.classList.toggle('is-on', (x.dataset.modeLabel === 'MTS') === mts));
            form.querySelector('[data-mode-help]').textContent = t(mts ? 'help.mode_mts' : 'help.mode_mto');
            PAIRED.forEach(f => { form[f].closest('label').hidden = !mts; });
            drawBatches();
        };
        // Stany ze sprzedaży ostatnich tygodni : min = 1 tydzień, maks = 3 tygodnie (parametry), w pełnych kartonach.
        // Obliczenie przełącza na « Na magazyn » ; data obliczenia zapisuje się razem ze stanami.
        let calculated = false;
        form.querySelector('[data-suggest]')?.addEventListener('click', async () => {
            const info = form.querySelector('[data-suggest-info]');
            try {
                const r = await api.get(`/produits/items/${item.id}/stock-suggestion`);
                const q = (v) => ui.fmt(v, 0, api.lang);
                if (r.shipped_cartons <= 0) { info.textContent = t('m.suggest_none', {weeks: r.weeks}); return; }
                form.stock_min.value = r.stock_min;
                form.stock_max.value = r.stock_max;
                fillCartons('stock_min');
                fillCartons('stock_max');
                calculated = true;
                drawBatches();
                if (!form.mts.checked && !form.never_frozen.checked) { form.mts.checked = true; sync(); }
                form.querySelector('[data-suggest-last]').textContent = t('m.suggest_pending');
                info.textContent = t('m.suggest_done', {weeks: r.weeks, cartons: q(r.shipped_cartons), weekly: ui.fmt(r.weekly_cartons, 1, api.lang),
                    min: q(r.stock_min), min_c: q(r.stock_min_cartons), min_w: ui.fmt(r.min_weeks, 0, api.lang),
                    max: q(r.stock_max), max_c: q(r.stock_max_cartons), max_w: ui.fmt(r.max_weeks, 0, api.lang), unit: r.unit});
            } catch (err) { fail(form, err); }
        });
        form.addEventListener('change', sync);
        form.addEventListener('input', drawBatches);
        sync();
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            const data = {mode: form.mts.checked ? 'MTS' : 'MTO', never_frozen: form.never_frozen.checked};
            for (const f of PAIRED) data[f] = ui.num(form[f].value);
            if (calculated) data.stock_calculated = true;
            saveSettings(form, data, 5);
        });
    }

    function stepGroup(body, item) {
        const s = item.settings;
        const product = item.item_type === 'product';
        body.innerHTML = `<form class="tfb-form" data-form>
            <label>${esc(t('f.primary_group_id'))}<select class="form-select" name="primary_group_id" data-field="primary_group_id">
                <option value="">—</option>${state.groups.map(g => `<option value="${g.id}" ${s.primary_group_id === g.id ? 'selected' : ''}>${esc(g.name)}</option>`).join('')}</select></label>
            ${product ? `<label>${esc(t('f.bake_state'))}<select class="form-select" name="bake_state" data-field="bake_state"><option value="">—</option>
                ${['none', 'par_baked', 'baked'].map(b => `<option value="${b}" ${s.bake_state === b ? 'selected' : ''}>${esc(t('bake.' + b))}</option>`).join('')}</select></label>` : ''}
            ${nav(5)}</form>`;
        const form = body.querySelector('[data-form]');
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            const data = {
                primary_group_id: form.primary_group_id.value ? Number(form.primary_group_id.value) : null,
            };
            if (product) data.bake_state = form.bake_state.value || null;
            saveSettings(form, data, 6);
        });
    }

    function stepBom(body, item) {
        if (!item.bom.length) {
            body.innerHTML = `${recipeLinks(item)}<p class="text-muted">${esc(t('bom.empty'))}</p>${nav(3, false)}`;
            return;
        }
        body.innerHTML = `${recipeLinks(item)}<form data-form><div class="table-responsive"><table class="table">
            <thead><tr><th>${esc(t('bom.component'))}</th><th class="text-end">${esc(t('bom.quantity'))}</th><th style="width:140px">${esc(t('bom.loss'))}</th></tr></thead>
            <tbody>${item.bom.map((b, i) => `<tr data-row="${i}">
                <td>${esc(b.name)} <span class="text-muted">(${esc(t('bom.kind.' + b.child_kind))})</span></td>
                <td class="text-end">${ui.fmt(b.quantity, 3, api.lang)} ${esc(b.unit || '')}</td>
                <td><input class="form-control form-control-sm" inputmode="decimal" data-field="losses.${i}.loss_pct" value="${b.loss_pct}"></td></tr>`).join('')}</tbody>
            </table></div>${nav(3)}</form>`;
        const form = body.querySelector('[data-form]');
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            ui.clearErrors(form);
            const losses = item.bom.map((b, i) => ({child_kind: b.child_kind, child_id: b.child_id, loss_pct: ui.num(form.querySelector(`[data-field="losses.${i}.loss_pct"]`).value) ?? 0}));
            try {
                await api.put(`/produits/items/${item.id}/bom-losses`, {losses});
                await saveSettings(form, {}, 4);
            } catch (err) { fail(form, err); }
        });
    }

    // --- Gamme -------------------------------------------------------------------------
    function stepRouting(body, item) {
        let steps = item.routing.map(s => ({...s, alternatives: s.alternatives.map(a => ({...a}))}));
        const delayFilter = (w) => ['fermentation', 'froid', 'surgelation'].includes(w.type);
        // Partia produkcyjna (np. pistolet : 1300 szt. naraz) : plan proponuje pełne partie, tydzień rozkłada je w całości.
        let batch = item.settings.batch_multiple ?? null;
        const ppc = item.item_type === 'product' && item.erp && item.erp.ppc > 0 ? item.erp.ppc : 0;
        const batchInfo = () => {
            if (typeof batch !== 'number' || batch <= 0) return '';
            const out = [];
            if (ppc) out.push(t('r.batch_cartons', {cartons: ui.fmt(batch / ppc, batch % ppc ? 1 : 0, api.lang)}));
            const max = item.settings.mode === 'MTS' ? item.settings.stock_max : null;
            if (max !== null && max !== undefined && Number(max) < batch) out.push('⚠ ' + t('r.batch_over_max', {max: ui.fmt(max, 0, api.lang), unit: item.base_unit}));
            return out.join(' · ');
        };
        const draw = () => {
            body.innerHTML = `<p class="form-text">${esc(t('r.help'))}</p><form data-form>
                <div class="tfb-batch">
                    <label>${esc(t('r.batch'))}<div class="input-group input-group-sm">
                        ${ppc ? `<input class="form-control" name="batch_cartons" inputmode="decimal" value="${typeof batch === 'number' ? String(Math.round(batch / ppc * 100) / 100).replace('.', ',') : ''}" placeholder="—" aria-label="${esc(t('r.batch'))} — ${esc(t('m.cartons'))}">
                        <span class="input-group-text">${esc(t('m.cartons'))}</span>` : ''}
                        <input class="form-control" name="batch" data-field="batch_multiple" inputmode="decimal" value="${batch ?? ''}" placeholder="—" aria-label="${esc(t('r.batch'))} — ${esc(item.base_unit)}">
                        <span class="input-group-text">${esc(item.base_unit)}</span></div></label>
                    <div><strong class="form-text d-block" data-batch-info>${esc(batchInfo())}</strong><span class="form-text d-block">${esc(t('r.batch_help'))}</span></div>
                </div>
                ${steps.length ? '' : `<p class="text-muted">${esc(t('r.empty'))}</p>`}
                ${steps.map((s, i) => `<div class="tfb-step" data-index="${i}">
                    <div class="tfb-step-head"><strong>${i + 1}.</strong>
                        <input class="form-control form-control-sm" data-k="name_pl" data-field="steps.${i}.name_pl" value="${esc(s.name_pl || '')}" placeholder="${esc(t('r.name_pl'))}" maxlength="100">
                        <input class="form-control form-control-sm" data-k="name_fr" data-field="steps.${i}.name_fr" value="${esc(s.name_fr || '')}" placeholder="${esc(t('r.name_fr'))}" maxlength="100">
                        <span class="tfb-step-tools">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-move="-1" data-write title="${esc(t('r.up'))}" ${i === 0 ? 'disabled' : ''}>↑</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-move="1" data-write title="${esc(t('r.down'))}" ${i === steps.length - 1 ? 'disabled' : ''}>↓</button>
                            <button type="button" class="btn btn-sm btn-outline-danger" data-remove data-write>${esc(t('r.remove'))}</button></span>
                    </div>
                    <div class="tfb-step-grid">
                        <label>${esc(t('r.workstation'))}${s.workstation_id ? ` <span class="badge bg-light text-dark">${esc(t('ws.' + wsType(s.workstation_id)))}</span>` : ''}<select class="form-select form-select-sm" data-k="workstation_id" data-field="steps.${i}.workstation_id"><option value="">—</option>${wsOptions(s.workstation_id)}</select></label>
                        ${TEMPERATURE_TYPES.includes(wsType(s.workstation_id)) ? `<label>${esc(t('r.temperature'))}<input class="form-control form-control-sm" inputmode="decimal" data-k="temperature_c" data-field="steps.${i}.temperature_c" value="${s.temperature_c ?? ''}"></label>` : ''}
                        <label>${esc(t('r.fixed'))}<input class="form-control form-control-sm" inputmode="numeric" data-k="fixed_time_s" data-field="steps.${i}.fixed_time_s" value="${s.fixed_time_s ?? 0}"></label>
                        <label>${esc(t('r.variable'))}<input class="form-control form-control-sm" inputmode="decimal" data-k="variable_time_s" data-field="steps.${i}.variable_time_s" value="${s.variable_time_s ?? 0}"></label>
                        <label>${esc(t('r.capacity'))}<input class="form-control form-control-sm" inputmode="decimal" data-k="capacity_per_pass" data-field="steps.${i}.capacity_per_pass" value="${s.capacity_per_pass ?? ''}"></label>
                        <label>${esc(t('r.operators'))}<input class="form-control form-control-sm" inputmode="decimal" data-k="operators_required" data-field="steps.${i}.operators_required" value="${s.operators_required ?? 1}"></label>
                        <label>${esc(t('r.level'))}<input class="form-control form-control-sm" inputmode="numeric" data-k="min_level_order" data-field="steps.${i}.min_level_order" value="${s.min_level_order ?? ''}"></label>
                        <label>${esc(t('r.delay'))}<input class="form-control form-control-sm" inputmode="decimal" data-k="delay_min" data-field="steps.${i}.delay_after_s" value="${s.delay_after_s ? s.delay_after_s / 60 : ''}"></label>
                        <label>${esc(t('r.delay_ws'))}<select class="form-select form-select-sm" data-k="delay_workstation_id" data-field="steps.${i}.delay_workstation_id"><option value="">${esc(t('r.none_ws'))}</option>${wsOptions(s.delay_workstation_id, delayFilter)}</select></label>
                        <label>${esc(t('r.cleaning'))}<input class="form-control form-control-sm" inputmode="decimal" data-k="cleaning_min" data-field="steps.${i}.cleaning_time_s" value="${s.cleaning_time_s ? s.cleaning_time_s / 60 : ''}"></label>
                        <label class="tfb-wide">${esc(t('r.alternatives'))}<select class="form-select form-select-sm" multiple size="3" data-k="alternatives" data-field="steps.${i}.alternatives">${wsOptions(null, w => w.id !== s.workstation_id).replace(/<option value="(\d+)"/g, (m, id) => s.alternatives.some(a => a.workstation_id === Number(id)) ? `<option value="${id}" selected` : m)}</select></label>
                    </div></div>`).join('')}
                <button type="button" class="btn btn-outline-primary btn-sm" data-add data-write><i class="bi bi-plus"></i> ${esc(t('r.add_step'))}</button>
                ${nav(6)}</form>`;
            const form = body.querySelector('[data-form]');
            // Partia : kartony ↔ sztuki (zapis w sztukach).
            if (ppc) {
                form.batch_cartons.addEventListener('input', () => { const c = ui.num(form.batch_cartons.value); if (c === null || typeof c === 'number') form.batch.value = c === null ? '' : Math.round(c * ppc); });
                form.batch.addEventListener('input', () => { const q = ui.num(form.batch.value); form.batch_cartons.value = typeof q === 'number' ? String(Math.round(q / ppc * 100) / 100).replace('.', ',') : ''; });
            }
            form.addEventListener('input', collect);
            form.addEventListener('change', (e) => { collect(); if (e.target.dataset.k === 'workstation_id') draw(); });
            form.querySelector('[data-add]').addEventListener('click', () => { collect(); steps.push({name_pl: '', name_fr: '', fixed_time_s: 0, variable_time_s: 0, operators_required: 1, alternatives: []}); draw(); });
            form.querySelectorAll('[data-remove]').forEach(b => b.addEventListener('click', () => { collect(); steps.splice(Number(b.closest('[data-index]').dataset.index), 1); draw(); }));
            form.querySelectorAll('[data-move]').forEach(b => b.addEventListener('click', () => {
                collect();
                const i = Number(b.closest('[data-index]').dataset.index);
                const j = i + Number(b.dataset.move);
                [steps[i], steps[j]] = [steps[j], steps[i]];
                draw();
            }));
            form.addEventListener('submit', save);
            if (!item.can_edit) form.querySelectorAll('input, select, button[data-write]').forEach(x => x.disabled = true);
        };
        const collect = () => {
            const batchInput = body.querySelector('input[name=batch]');
            if (batchInput) {
                batch = batchInput.value.trim() === '' ? null : ui.num(batchInput.value);
                body.querySelector('[data-batch-info]').textContent = batchInfo();
            }
            body.querySelectorAll('[data-index]').forEach(card => {
                const s = steps[Number(card.dataset.index)];
                card.querySelectorAll('[data-k]').forEach(input => {
                    const k = input.dataset.k;
                    if (k === 'alternatives') {
                        const chosen = [...input.selectedOptions].map(o => Number(o.value));
                        s.alternatives = chosen.map(id => s.alternatives.find(a => a.workstation_id === id) || {workstation_id: id});
                    } else if (k === 'workstation_id' || k === 'delay_workstation_id') {
                        s[k] = input.value ? Number(input.value) : null;
                    } else if (k === 'delay_min') {
                        s.delay_after_s = input.value === '' ? 0 : Math.round(ui.num(input.value) * 60);
                    } else if (k === 'cleaning_min') {
                        s.cleaning_time_s = input.value === '' ? 0 : Math.round(ui.num(input.value) * 60);
                    } else if (k === 'name_pl' || k === 'name_fr') {
                        s[k] = input.value;
                    } else {
                        s[k] = input.value === '' ? null : ui.num(input.value);
                    }
                });
            });
        };
        const save = async (e) => {
            e.preventDefault();
            collect();
            const form = body.querySelector('[data-form]');
            ui.clearErrors(form);
            const payload = steps.map(s => {
                const out = {
                    name_pl: s.name_pl, workstation_id: s.workstation_id,
                    fixed_time_s: s.fixed_time_s ?? 0, variable_time_s: s.variable_time_s ?? 0,
                    operators_required: s.operators_required ?? 1, delay_after_s: s.delay_after_s || 0,
                    delay_workstation_id: s.delay_workstation_id ?? null,
                    cleaning_required: (s.cleaning_time_s || 0) > 0, cleaning_time_s: s.cleaning_time_s || 0,
                    min_level_order: s.min_level_order ?? null,
                    temperature_c: TEMPERATURE_TYPES.includes(wsType(s.workstation_id)) ? (s.temperature_c ?? null) : null,
                    alternatives: s.alternatives.map(a => {
                        const alt = {workstation_id: a.workstation_id};
                        for (const k of ['fixed_time_s', 'variable_time_s', 'capacity_per_pass', 'priority']) if (a[k] !== undefined) alt[k] = a[k];
                        return alt;
                    }),
                };
                if (s.id) out.id = s.id;
                if (s.name_fr) out.name_fr = s.name_fr;
                if (s.capacity_per_pass !== null && s.capacity_per_pass !== undefined && s.capacity_per_pass !== '') out.capacity_per_pass = s.capacity_per_pass;
                return out;
            });
            try {
                await api.put(`/produits/items/${item.id}/routing`, {steps: payload});
                await saveSettings(form, {batch_multiple: batch}, 7);
            } catch (err) { fail(form, err); }
        };
        draw();
    }

    // --- Contrôle des temps et chronométrages --------------------------------------------
    function durationTable(d) {
        const steps = state.item.routing;
        return `<div class="table-responsive"><table class="table table-sm tfb-durations"><thead><tr><th>#</th><th class="text-end">${esc(t('t.passes'))}</th><th class="text-end">${esc(t('t.work'))}</th>
            <th class="text-end">${esc(t('t.cleaning'))}</th><th class="text-end">${esc(t('t.delay'))}</th><th class="text-end">${esc(t('t.labour'))}</th></tr></thead>
            <tbody>${d.steps.map((s, i) => `<tr><td>${s.step_no}. ${esc((steps[i] || {}).name || '')}</td><td class="text-end">${s.passes}</td>
                <td class="text-end">${ui.duration(s.work_s, api)}</td><td class="text-end">${s.cleaning_s ? ui.duration(s.cleaning_s, api) : '—'}</td>
                <td class="text-end">${s.delay_s ? ui.duration(s.delay_s, api) : '—'}</td><td class="text-end">${ui.duration(s.labour_s, api)}</td></tr>`).join('')}</tbody>
            <tfoot><tr><th>${esc(t('t.total'))}</th><th></th><th class="text-end">${ui.duration(d.work_s, api)}</th><th></th><th></th><th class="text-end">${ui.duration(d.labour_s, api)}</th></tr></tfoot></table></div>
            <p class="text-muted small">${esc(t('t.elapsed'))}: <strong>${ui.duration(d.elapsed_s, api)}</strong> · ${esc(t('t.per_unit'))}: <strong>${ui.fmt(d.labour_s_per_unit, 1, api.lang)} ${esc(t('unit.s'))}</strong></p>`;
    }

    async function stepTimes(body, item) {
        if (!item.routing.length) {
            body.innerHTML = `<p class="text-muted">${esc(t('r.empty'))}</p>${nav(7, false)}`;
            return;
        }
        body.innerHTML = `<div data-summary></div><div class="tfb-samples">${item.durations.map(d => `<div class="tfb-sample"><h6>${esc(t('t.quantity'))}: ${ui.fmt(d.quantity, 0, api.lang)} ${esc(item.base_unit)}</h6>${durationTable(d)}</div>`).join('')}</div>
            <form class="tfb-filters" data-qty><input class="form-control form-control-sm" inputmode="decimal" name="q" placeholder="${esc(t('t.quantity'))}">
                <button class="btn btn-sm btn-outline-primary">${esc(t('t.compute'))}</button></form><div data-custom></div>
            <h5 class="mt-4">${esc(t('t.studies'))}</h5>
            ${item.routing.map(s => `<details class="tfb-study" data-step="${s.id}"><summary><strong>${s.step_no}. ${esc(s.name)}</strong>
                <span class="text-muted">${esc(s.workstation.name)} · ${s.fixed_time_s} ${esc(t('unit.s'))} ${esc(t('t.component.fixed'))} · ${ui.fmt(s.variable_time_s, 3, api.lang)} ${esc(t('unit.s'))} ${esc(t('t.component.variable'))}</span></summary>
                <div data-study-body>${esc(t('loading'))}</div></details>`).join('')}
            ${nav(7, false)}`;
        productionSummary(body.querySelector('[data-summary]'), item);
        body.querySelector('[data-qty]').addEventListener('submit', async (e) => {
            e.preventDefault();
            try {
                const d = await api.get(`/produits/items/${item.id}/duration?quantity=${encodeURIComponent(ui.num(e.target.q.value))}`);
                body.querySelector('[data-custom]').innerHTML = `<div class="tfb-sample"><h6>${esc(t('t.quantity'))}: ${ui.fmt(d.quantity, 0, api.lang)}</h6>${durationTable(d)}</div>`;
            } catch (err) { fail(null, err); }
        });
        body.querySelectorAll('details[data-step]').forEach(d => d.addEventListener('toggle', () => { if (d.open) loadStudies(d); }));
    }

    async function loadStudies(details) {
        const stepId = Number(details.dataset.step);
        const box = details.querySelector('[data-study-body]');
        let data;
        try { data = await api.get(`/produits/routing-steps/${stepId}/time-studies`); } catch (err) { box.textContent = err.message; return; }
        const canEdit = state.item.can_edit;
        const now = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16);
        box.innerHTML = `<div class="tfb-proposals">${data.proposals.map(p => `<div class="tfb-proposal ${p.alert ? 'is-alert' : ''}">
                <strong>${esc(t('t.proposal'))} (${esc(t('t.component.' + p.component))})</strong>
                <span>${esc(t('t.current'))}: ${ui.fmt(p.current_s, 3, api.lang)} ${esc(t('unit.s'))}</span>
                <span>${p.proposed_s === null ? '—' : ui.fmt(p.proposed_s, 3, api.lang) + ' ' + esc(t('unit.s'))}${p.deviation_pct !== null ? ' · ' + esc(t('t.deviation', {pct: ui.fmt(p.deviation_pct, 1, api.lang)})) : ''}</span>
                ${p.ready ? (canEdit ? `<button type="button" class="btn btn-sm btn-primary" data-apply="${p.component}">${esc(t('t.apply'))}</button>` : '')
                    : `<span class="text-muted">${esc(t('t.not_ready', {count: p.count, min: p.min_measurements}))}</span>`}
            </div>`).join('')}</div>
            <form class="tfb-measure" data-measure>
                <label>${esc(t('t.quantity'))}<input class="form-control form-control-sm" inputmode="decimal" name="quantity" data-field="quantity" required></label>
                <label>${esc(t('t.duration_min'))}<input class="form-control form-control-sm" inputmode="decimal" name="minutes" data-field="duration_s" required></label>
                <label>${esc(t('t.measured_at'))}<input class="form-control form-control-sm" type="datetime-local" name="measured_at" data-field="measured_at" value="${now}"></label>
                <label>${esc(t('t.note'))}<input class="form-control form-control-sm" name="note" data-field="note" maxlength="500"></label>
                <button class="btn btn-sm btn-outline-primary">${esc(t('t.add_measure'))}</button>
            </form>
            ${data.measurements.length ? `<table class="table table-sm"><thead><tr><th>${esc(t('t.measured_at'))}</th><th class="text-end">${esc(t('t.quantity'))}</th><th class="text-end">${esc(t('t.passes'))}</th>
                <th class="text-end">${esc(t('t.work'))}</th><th class="text-end">${esc(t('t.unit_time'))}</th><th></th></tr></thead><tbody>
                ${data.measurements.map(m => `<tr class="${m.excluded ? 'text-muted text-decoration-line-through' : ''}"><td>${esc(m.measured_at.slice(0, 16))}${m.employee ? '<br><small>' + esc(m.employee) + '</small>' : ''}</td>
                    <td class="text-end">${ui.fmt(m.quantity, 1, api.lang)}</td><td class="text-end">${m.passes}</td><td class="text-end">${ui.duration(m.duration_s, api)}</td>
                    <td class="text-end">${ui.fmt(m.unit_time_s, 3, api.lang)} <small>(${esc(t('t.component.' + m.component))})</small></td>
                    <td class="text-end"><button type="button" class="btn btn-sm btn-link" data-exclude="${m.id}" data-value="${m.excluded ? 0 : 1}">${esc(t(m.excluded ? 't.include' : 't.exclude'))}</button></td></tr>`).join('')}
                </tbody></table>` : `<p class="text-muted">${esc(t('t.no_studies'))}</p>`}`;
        const form = box.querySelector('[data-measure]');
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            ui.clearErrors(form);
            const payload = {quantity: ui.num(form.quantity.value), duration_s: Math.round(ui.num(form.minutes.value) * 60)};
            if (form.measured_at.value) payload.measured_at = form.measured_at.value.replace('T', ' ');
            if (form.note.value.trim()) payload.note = form.note.value.trim();
            try { await api.post(`/produits/routing-steps/${stepId}/time-studies`, payload); ui.notify(true, t('saved')); loadStudies(details); }
            catch (err) { fail(form, err); }
        });
        box.querySelectorAll('[data-exclude]').forEach(b => b.addEventListener('click', async () => {
            try { await api.patch(`/produits/time-studies/${b.dataset.exclude}`, {excluded: b.dataset.value === '1'}); loadStudies(details); } catch (err) { fail(null, err); }
        }));
        box.querySelectorAll('[data-apply]').forEach(b => b.addEventListener('click', async () => {
            try {
                await api.post(`/produits/routing-steps/${stepId}/apply-standard`, {component: b.dataset.apply});
                ui.notify(true, t('saved'));
                location.hash = `#/item/${state.item.id}/7?r=${Date.now()}`;
            } catch (err) { fail(null, err); }
        }));
    }

    function stepValidate(body, item) {
        const label = (c) => c.field.startsWith('routing.') ? t('check.step_time', {no: c.field.split('.')[1]})
            : c.code === 'BAKE_WITHOUT_OVEN' ? t('check.bake_oven') : t('check.' + c.field);
        const ready = item.checklist.every(c => c.ok);
        body.innerHTML = `<ul class="tfb-checklist">${item.checklist.map(c => `<li class="${c.ok ? 'ok' : 'ko'}"><i class="bi ${c.ok ? 'bi-check-circle' : 'bi-x-circle'}"></i> ${esc(label(c))}</li>`).join('')}</ul>
            ${item.settings.status === 'validated' ? `<p class="text-success">${esc(t('v.validated', {date: (item.settings.validated_at || '').slice(0, 16)}))}</p>` : (ready ? `<p>${esc(t('v.ready'))}</p>` : '')}
            <form data-form>${nav(8, t('v.validate'))}</form>`;
        const form = body.querySelector('[data-form]');
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            try { await api.post(`/produits/items/${item.id}/validate`); ui.notify(true, t('saved')); location.hash = `#/item/${item.id}/8?r=${Date.now()}`; }
            catch (err) { fail(form, err); }
        });
    }

    // --- Routage par ancre -----------------------------------------------------------
    async function route() {
        const hash = location.hash.replace(/^#/, '') || '/';
        const path = hash.split('?')[0];
        el.setAttribute('aria-busy', 'true');
        try {
            let m;
            if (path === '/new') await renderNew();
            else if (path === '/stock') await renderStock();
            else if (path === '/levels') await renderLevels();
            else if ((m = path.match(/^\/product\/(\d+)$/))) await openProduct(Number(m[1]), Number(new URLSearchParams(hash.split('?')[1] || '').get('step')) || 1);
            else if ((m = path.match(/^\/item\/(\d+)\/([1-8])$/))) await renderItem(Number(m[1]), Number(m[2]));
            else await renderList();
        } catch (err) {
            el.innerHTML = header() + `<div class="alert alert-danger">${esc(err.message)}</div>`;
        } finally {
            el.removeAttribute('aria-busy');
        }
    }

    el.addEventListener('click', async (e) => {
        if (!e.target.closest('[data-lang-switch]')) return;
        await api.setLang(api.lang === 'pl' ? 'fr' : 'pl');
        await loadReferences();
        route();
    });
    window.addEventListener('hashchange', route);
    try {
        await loadReferences();
    } catch (err) {
        el.innerHTML = header() + `<div class="alert alert-danger">${esc(err.message)}</div>`;
        return;
    }
    route();
})();
