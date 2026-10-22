/*
 * Zaopatrzenie / Approvisionnement : une fois la production générée — besoins en matières et emballages des
 * semaines (feuille de production × recettes par carton), stock (inventaire + réceptions − production), mode
 * « en stock » (min. / max.) ou « à la commande », commandes fournisseurs par e-mail (ou passées autrement),
 * réceptions avec quantités et prix réels. Tout passe par l'API /supply/… ; les quantités de l'API sont en unité
 * de base (g, ml, szt), affichées en unité lisible (kg, l) avec factor. Onglet Factures : contrôle des factures
 * d'achat (KSeF, XML, PDF) contre les commandes et réceptions, noms du fournisseur reliés à nos matières.
 */
(async function () {
    'use strict';
    const el = document.getElementById('tfbSupply');
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
    const n = (v, d) => v === null || v === undefined ? '—' : ui.fmt(v, d ?? 1, api.lang);
    const TABS = ['needs', 'orders', 'invoices', 'stock'];
    const STATUSES = ['open', 'draft', 'sent', 'partial', 'received', 'cancelled', 'all'];
    const iso = (d) => d.toISOString().slice(0, 10);
    const todayIso = iso(new Date());
    const addDays = (s, k) => { const d = new Date(s + 'T12:00:00'); d.setDate(d.getDate() + k); return iso(d); };
    const day = (s) => s ? `${s.slice(8, 10)}.${s.slice(5, 7)}` : '—';
    const errText = (err) => ((err.body && err.body.errors) || []).map(x => x.message).join(' ') || err.message;
    // Ilość w jednostce wyświetlanej (kg dla g) : baza ÷ factor ; pole edycji tak samo.
    const q = (r, v, d) => v === null || v === undefined ? '—' : `${n(v / r.factor, d ?? (r.factor > 1 ? 2 : 0))} ${r.unit}`;
    const toInput = (r, v) => v === null || v === undefined ? '' : String(Math.round(v / r.factor * 1000) / 1000).replace('.', ',');
    const money = (v) => v === null || v === undefined ? '—' : `${n(v, 2)} zł`;
    const unitPrice = (r, p) => p === null || p === undefined ? '—' : `${n(p * r.factor, 2)} zł/${r.unit}`;
    const badge = (status) => `<span class="badge tfb-po-${status}">${esc(t('status.' + status))}</span>`;

    let state = readHash();
    const data = {needs: null, orders: null, materials: null, order: null, preview: null, invoices: null, invoice: null, mappings: null};
    let receiving = false;

    function readHash() {
        const p = new URLSearchParams(location.hash.replace(/^#/, ''));
        return {
            tab: TABS.includes(p.get('tab')) ? p.get('tab') : 'needs',
            date: /^\d{4}-\d{2}-\d{2}$/.test(p.get('date') || '') ? p.get('date') : addDays(todayIso, 7),
            weeks: Math.min(4, Math.max(1, Number(p.get('weeks')) || 1)),
            status: STATUSES.includes(p.get('status')) ? p.get('status') : 'open',
            order: Number(p.get('order')) || null,
            kind: ['all', 'raw', 'pack'].includes(p.get('kind')) ? p.get('kind') : 'all',
            only: p.get('only') !== '0',
            inv: p.get('inv') === 'map' ? 'map' : Number(p.get('inv')) || null,
            istatus: ['open', 'accepted', 'disputed', 'all'].includes(p.get('ist')) ? p.get('ist') : 'open',
            search: '', stockMode: 'all', used: true,
        };
    }
    function writeHash() {
        const p = new URLSearchParams({tab: state.tab, date: state.date, weeks: state.weeks, status: state.status, kind: state.kind, only: state.only ? '1' : '0'});
        if (state.order) p.set('order', state.order);
        if (state.tab === 'invoices') { p.set('ist', state.istatus); if (state.inv) p.set('inv', state.inv); }
        history.replaceState(null, '', '#' + p.toString());
    }

    function shell(body) {
        const open = data.orders ? data.orders.orders.filter(o => ['draft', 'sent', 'partial'].includes(o.status)).length : null;
        return `<div class="tfb-head"><div><h3>${esc(t('title'))}</h3><p class="text-muted mb-0">${esc(t('subtitle'))}</p></div>
                <div class="tfb-head-actions"><button type="button" class="btn btn-outline-secondary btn-sm" data-lang-switch>${esc(t('lang_switch'))}</button></div></div>
            <ul class="nav nav-tabs tfb-supply-tabs mb-3" role="tablist">${TABS.map(x => `<li class="nav-item"><a class="nav-link ${state.tab === x ? 'active' : ''}" href="#tab=${x}" data-tab="${x}" role="tab">
                ${esc(t('tab.' + x))}${x === 'orders' && open ? ` <span class="badge bg-primary">${open}</span>` : ''}</a></li>`).join('')}</ul>
            <div class="tfb-supply-body">${body}</div>`;
    }

    async function load() {
        writeHash();
        el.innerHTML = shell(`<p class="text-muted">${esc(t(state.tab === 'needs' ? 'loading_needs' : 'loading'))}</p>`);
        bindShell();
        try {
            if (state.tab === 'needs') data.needs = await api.get(`/supply/needs?date=${state.date}&weeks=${state.weeks}`);
            if (state.tab === 'orders' || !data.orders) data.orders = await api.get(`/supply/orders?status=${state.status}`);
            if (state.tab === 'orders' && state.order) data.order = await api.get(`/supply/orders/${state.order}`);
            if (state.tab === 'stock') data.materials = await api.get('/supply/materials');
            if (state.tab === 'invoices') {
                if (state.inv === 'map') data.mappings = await api.get('/supply/mappings');
                else if (state.inv) {
                    data.invoice = await api.get(`/supply/invoices/${state.inv}`);
                    if (data.invoice.editable && data.invoice.lines.some(l => !l.material)) await ensureMaterials();
                } else data.invoices = await api.get(`/supply/invoices?status=${state.istatus}`);
            }
        } catch (err) {
            if (err.status === 404 && (state.order || state.inv)) { state.order = null; state.inv = null; return load(); }
            el.innerHTML = shell(`<div class="alert alert-danger">${esc(errText(err))}</div>`);
            bindShell();
            return;
        }
        draw();
    }

    function draw() {
        const y = window.scrollY;
        const invoices = () => state.inv === 'map' ? drawMappings() : state.inv && data.invoice ? drawInvoice() : drawInvoices();
        const body = state.tab === 'needs' ? drawNeeds() : state.tab === 'stock' ? drawStock() : state.tab === 'invoices' ? invoices()
            : (state.order && data.order ? drawOrder() : drawOrders());
        el.innerHTML = shell(body);
        bindShell();
        if (state.tab === 'needs') bindNeeds();
        else if (state.tab === 'stock') bindStock();
        else if (state.tab === 'invoices') (state.inv === 'map' ? bindMappings : state.inv && data.invoice ? bindInvoice : bindInvoices)();
        else if (state.order && data.order) bindOrder();
        else bindOrders();
        window.scrollTo(0, y);
    }

    function bindShell() {
        el.querySelectorAll('[data-tab]').forEach(a => a.addEventListener('click', (e) => {
            e.preventDefault();
            state.tab = a.dataset.tab;
            state.order = null;
            state.inv = null;
            receiving = false;
            data.preview = null;
            resetInvoice();
            load();
        }));
    }

    const kindOk = (r) => state.kind === 'all' || (state.kind === 'pack') === r.packaging;
    const searchOk = (r) => !state.search || r.name.toLowerCase().includes(state.search.toLowerCase()) || (r.supplier?.name || '').toLowerCase().includes(state.search.toLowerCase());
    const kindButtons = () => `<div class="btn-group btn-group-sm" role="group">${['all', 'raw', 'pack'].map(k => `<button type="button" class="btn ${state.kind === k ? 'btn-primary' : 'btn-outline-primary'}" data-kind="${k}">${esc(t('kind.' + k))}</button>`).join('')}</div>`;
    const modeSwitch = (r, editable) => `<div class="form-check form-switch d-inline-block m-0" title="${esc(t('mode_help'))}">
            <input class="form-check-input" type="checkbox" role="switch" data-mode ${r.mode === 'stock' ? 'checked' : ''} ${editable ? '' : 'disabled'} aria-label="${esc(t('mode.stock'))}"></div>
        <div class="small ${r.mode === 'stock' ? 'text-success' : 'text-muted'}">${esc(t('mode.' + r.mode))}</div>`;
    const stockCell = (r) => `${r.stock === null ? `<span class="text-muted">—</span>` : `<span class="${r.stock < 0 ? 'text-danger' : ''}">${esc(q(r, r.stock))}</span>`}
        <div class="small text-muted">${esc(r.stock_basis === 'count' ? t('basis.count', {day: day(r.count_date)}) : r.stock_basis === 'receipts' ? t('basis.receipts') : t('basis.none'))}</div>`;

    // --- Potrzeby z produkcji -------------------------------------------------------------------------------------
    function drawNeeds() {
        const d = data.needs;
        const w = d.weeks;
        const rows = d.rows.filter(r => kindOk(r) && searchOk(r) && (!state.only || r.to_order > 0));
        const groups = new Map();
        for (const r of rows) {
            const key = r.supplier ? r.supplier.id : 0;
            if (!groups.has(key)) groups.set(key, {supplier: r.supplier, rows: []});
            groups.get(key).rows.push(r);
        }
        const editable = d.can_write;
        const weekLabel = t('week_label', {w: w[0].iso_week, from: day(w[0].week_start), to: day(w[w.length - 1].week_end)}) + (w.length > 1 ? ' ' + t('weeks_more', {n: w.length}) : '');
        const warn = d.warnings;
        const warnings = [
            warn.no_recipe.length ? t('warn.no_recipe', {n: warn.no_recipe.length, list: warn.no_recipe.slice(0, 6).join(', ') + (warn.no_recipe.length > 6 ? '…' : '')}) : null,
            warn.no_supplier.length ? t('warn.no_supplier', {list: warn.no_supplier.join(', ')}) : null,
            warn.no_price.length ? t('warn.no_price', {list: warn.no_price.join(', ')}) : null,
            warn.no_count ? t('warn.no_count', {n: warn.no_count}) : null,
        ].filter(Boolean);
        const row = (r) => {
            const late = r.available_after < 0;
            const packs = r.package_size && r.package_size !== r.factor ? t('packages', {n: n(r.to_order / r.package_size, 2), size: q(r, r.package_size)}) : '';
            return `<tr data-mat="${r.id}" class="${r.to_order > 0 ? '' : 'is-idle'}">
                <td class="text-center"><input class="form-check-input" type="checkbox" data-pick ${r.to_order > 0 && r.supplier ? 'checked' : ''} ${r.supplier && editable ? '' : 'disabled'} aria-label="${esc(t('pick'))}"></td>
                <td><strong>${esc(r.name)}</strong>${r.packaging ? ` <span class="badge bg-light text-dark">${esc(t('kind.pack'))}</span>` : ''}
                    <div class="small text-muted">${esc(r.category || '')}${r.products ? ' · ' + esc(t('products_n', {n: r.products})) : ''}</div></td>
                <td class="text-center">${modeSwitch(r, editable)}${r.mode === 'stock' ? `<div class="small text-muted">${r.stock_max === null ? esc(t('no_minmax')) : esc(t('minmax', {min: q(r, r.stock_min ?? 0), max: q(r, r.stock_max)}))}</div>` : ''}</td>
                <td class="text-end"><strong>${esc(q(r, r.need))}</strong>${w.length > 1 ? `<div class="small text-muted">${r.need_weeks.map((v, i) => `S${w[i].iso_week} ${esc(q(r, v))}`).join(' · ')}</div>` : ''}</td>
                <td class="text-end">${stockCell(r)}</td>
                <td class="text-end">${r.on_order > 0 ? esc(q(r, r.on_order)) : '—'}</td>
                <td class="text-end ${late ? 'text-danger fw-semibold' : ''}" title="${esc(t('after_help'))}">${esc(q(r, r.available_after))}</td>
                <td><div class="input-group input-group-sm tfb-supply-qty"><input class="form-control text-end" inputmode="decimal" data-qty value="${esc(toInput(r, r.to_order))}" ${editable && r.supplier ? '' : 'disabled'}
                    aria-label="${esc(t('col.to_order'))}"><span class="input-group-text">${esc(r.unit)}</span></div>${packs ? `<div class="small text-muted" data-packs>${esc(packs)}</div>` : ''}</td>
                <td class="text-end">${esc(money(r.value))}<div class="small text-muted">${esc(unitPrice(r, r.price))}</div></td></tr>`;
        };
        const head = `<thead><tr><th style="width:34px"></th><th>${esc(t('col.material'))}</th><th class="text-center">${esc(t('col.mode'))}</th>
            <th class="text-end">${esc(t('col.need'))}</th><th class="text-end">${esc(t('col.stock'))}</th><th class="text-end">${esc(t('col.on_order'))}</th>
            <th class="text-end" title="${esc(t('after_help'))}">${esc(t('col.after'))}</th><th>${esc(t('col.to_order'))}</th><th class="text-end">${esc(t('col.value'))}</th></tr></thead>`;
        const cards = [...groups.values()].map(g => {
            const s = g.supplier;
            const value = g.rows.filter(r => r.to_order > 0).reduce((a, r) => a + (r.value || 0), 0);
            return `<div class="card tfb-supply-supplier" data-supplier="${s ? s.id : 0}"><div class="card-header d-flex flex-wrap align-items-center gap-2">
                    <h5 class="mb-0">${esc(s ? s.name : t('no_supplier'))}</h5>
                    ${s ? (s.email ? `<span class="small text-muted"><i class="bi bi-envelope"></i> ${esc(s.email)}</span>` : `<span class="badge bg-warning text-dark">${esc(t('no_email'))}</span>`) : `<span class="small text-danger">${esc(t('no_supplier_help'))}</span>`}
                    <span class="ms-auto small text-muted">${esc(t('supplier_total', {n: g.rows.filter(r => r.to_order > 0).length, value: money(value)}))}</span>
                    ${s && editable ? `<button type="button" class="btn btn-sm btn-primary" data-create="${s.id}"><i class="bi bi-cart-plus"></i> ${esc(t('create_one'))}</button>` : ''}</div>
                <div class="card-body pt-0"><div class="table-responsive"><table class="table table-sm align-middle mb-0 tfb-supply-table">${head}<tbody>${g.rows.map(row).join('')}</tbody></table></div></div></div>`;
        }).join('');
        return `<div class="card tfb-supply-bar"><div class="card-body d-flex flex-wrap gap-2 align-items-center">
                <div class="btn-group btn-group-sm"><button type="button" class="btn btn-outline-primary" data-week="-7" aria-label="${esc(t('previous'))}">‹</button>
                    <button type="button" class="btn btn-outline-primary" data-week="0">${esc(t('next_week'))}</button>
                    <button type="button" class="btn btn-outline-primary" data-week="7" aria-label="${esc(t('next'))}">›</button></div>
                <strong>${esc(weekLabel)}</strong>
                <select class="form-select form-select-sm" data-weeks style="width:auto">${[1, 2, 3, 4].map(k => `<option value="${k}" ${state.weeks === k ? 'selected' : ''}>${esc(t('weeks_n', {n: k}))}</option>`).join('')}</select>
                ${kindButtons()}
                <label class="form-check form-switch m-0 small"><input class="form-check-input" type="checkbox" data-only ${state.only ? 'checked' : ''}> ${esc(t('only_to_order'))}</label>
                <input class="form-control form-control-sm ms-auto" style="max-width:220px" data-search value="${esc(state.search)}" placeholder="${esc(t('search'))}">
            </div></div>
            <div class="tfb-supply-rule small text-muted mb-2">${esc(t('rule'))}</div>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2"><strong>${esc(t('summary', {n: d.totals.to_order, suppliers: d.totals.suppliers, value: money(d.totals.value)}))}</strong>
                ${editable && d.totals.to_order ? `<button type="button" class="btn btn-sm btn-primary ms-auto" data-create="all"><i class="bi bi-cart-check"></i> ${esc(t('create_all'))}</button>` : ''}</div>
            ${warnings.length ? `<div class="alert alert-warning py-2 small">${warnings.map(x => `<div>${esc(x)}</div>`).join('')}</div>` : ''}
            ${cards || `<p class="text-muted">${esc(t(state.only ? 'empty_to_order' : 'empty_needs'))}</p>`}`;
    }

    function bindNeeds() {
        el.querySelectorAll('[data-week]').forEach(b => b.addEventListener('click', () => {
            state.date = b.dataset.week === '0' ? addDays(todayIso, 7) : addDays(data.needs.date, Number(b.dataset.week));
            load();
        }));
        el.querySelector('[data-weeks]').addEventListener('change', (e) => { state.weeks = Number(e.target.value); load(); });
        el.querySelectorAll('[data-kind]').forEach(b => b.addEventListener('click', () => { state.kind = b.dataset.kind; writeHash(); draw(); }));
        el.querySelector('[data-only]').addEventListener('change', (e) => { state.only = e.target.checked; writeHash(); draw(); });
        bindSearch();
        el.querySelectorAll('tr[data-mat]').forEach(tr => {
            const r = data.needs.rows.find(x => x.id === Number(tr.dataset.mat));
            tr.querySelector('[data-mode]')?.addEventListener('change', (e) => saveMode(r, e.target.checked ? 'stock' : 'order', e.target));
            const qty = tr.querySelector('[data-qty]');
            qty?.addEventListener('input', () => {
                const v = ui.num(qty.value);
                const packs = tr.querySelector('[data-packs]');
                if (packs && r.package_size && typeof v === 'number') packs.textContent = t('packages', {n: n(v * r.factor / r.package_size, 2), size: q(r, r.package_size)});
                const pick = tr.querySelector('[data-pick]');
                if (pick && !pick.disabled) pick.checked = typeof v === 'number' && v > 0;
            });
        });
        el.querySelectorAll('[data-create]').forEach(b => b.addEventListener('click', () => createOrders(b.dataset.create, b)));
    }

    function bindSearch() {
        const s = el.querySelector('[data-search]');
        if (!s) return;
        let typing = null;
        s.addEventListener('input', () => {
            clearTimeout(typing);
            typing = setTimeout(() => {
                state.search = s.value;
                const pos = s.selectionStart;
                draw();
                const s2 = el.querySelector('[data-search]');
                s2.focus();
                s2.setSelectionRange(pos, pos);
            }, 250);
        });
    }

    async function saveMode(r, mode, input) {
        input.disabled = true;
        try {
            await api.patch(`/supply/materials/${r.id}`, {supply_mode: mode});
            ui.notify(true, t(mode === 'stock' && r.stock_max === null ? 'mode_saved_set_minmax' : 'mode_saved', {name: r.name}));
            await load();
        } catch (err) {
            ui.notify(false, errText(err));
            input.checked = !input.checked;
            input.disabled = false;
        }
    }

    async function createOrders(scope, btn) {
        const lines = [];
        const bad = [];
        el.querySelectorAll('[data-supplier]').forEach(card => {
            if (scope !== 'all' && card.dataset.supplier !== scope) return;
            card.querySelectorAll('tr[data-mat]').forEach(tr => {
                if (!tr.querySelector('[data-pick]')?.checked) return;
                const r = data.needs.rows.find(x => x.id === Number(tr.dataset.mat));
                const v = ui.num(tr.querySelector('[data-qty]').value);
                if (typeof v !== 'number' || v <= 0) { bad.push(r.name); return; }
                lines.push({raw_material_id: r.id, quantity: Math.round(v * r.factor * 1000) / 1000});
            });
        });
        if (bad.length) { ui.notify(false, t('qty_invalid', {list: bad.join(', ')})); return; }
        if (!lines.length) { ui.notify(false, t('nothing_picked')); return; }
        btn.disabled = true;
        try {
            const res = await api.post('/supply/orders', {week_start: data.needs.date, lines});
            ui.notify(true, t('orders_created', {n: res.orders.length}));
            state.tab = 'orders';
            state.status = 'open';
            state.order = res.orders.length === 1 ? res.orders[0].id : null;
            data.orders = null;
            await load();
        } catch (err) {
            ui.notify(false, errText(err));
            btn.disabled = false;
        }
    }

    // --- Zamówienia ---------------------------------------------------------------------------------------------------
    function drawOrders() {
        const list = data.orders.orders;
        return `<div class="card tfb-supply-bar"><div class="card-body d-flex flex-wrap gap-2 align-items-center">
                <div class="btn-group btn-group-sm flex-wrap" role="group">${STATUSES.map(s => `<button type="button" class="btn ${state.status === s ? 'btn-primary' : 'btn-outline-primary'}" data-status="${s}">${esc(t('filter.' + s))}</button>`).join('')}</div>
                <span class="small text-muted ms-auto">${esc(t('orders_help'))}</span></div></div>
            <div class="card"><div class="card-body pt-3"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
                <thead><tr><th>${esc(t('col.code'))}</th><th>${esc(t('col.supplier'))}</th><th>${esc(t('col.status'))}</th><th>${esc(t('col.delivery'))}</th>
                    <th class="text-end">${esc(t('col.lines'))}</th><th class="text-end">${esc(t('col.ordered'))}</th><th class="text-end">${esc(t('col.received'))}</th>
                    <th class="text-end">${esc(t('col.price_gaps'))}</th><th>${esc(t('col.sent'))}</th></tr></thead>
                <tbody>${list.map(o => `<tr data-open-order="${o.id}" class="tfb-supply-click">
                    <td><a href="#tab=orders&order=${o.id}" data-open-order="${o.id}"><strong>${esc(o.code)}</strong></a></td><td>${esc(o.supplier.name)}</td><td>${badge(o.status)}</td>
                    <td>${o.delivery_date ? esc(day(o.delivery_date)) : '—'}${o.week_start ? `<div class="small text-muted">${esc(t('for_week', {day: day(o.week_start)}))}</div>` : ''}</td>
                    <td class="text-end">${o.lines_count}</td><td class="text-end">${esc(money(o.value_ordered))}</td>
                    <td class="text-end">${o.received_pct > 0 ? `${esc(n(o.received_pct, 0))} %<div class="small text-muted">${esc(money(o.value_received))}</div>` : '—'}</td>
                    <td class="text-end">${o.price_gaps ? `<span class="badge bg-warning text-dark">${o.price_gaps}</span>` : '—'}</td>
                    <td>${o.sent_at ? `${esc(day(o.sent_at.slice(0, 10)))} · ${esc(t('sent_by.' + o.sent_by))}` : '—'}</td></tr>`).join('')
                    || `<tr><td colspan="9" class="text-muted">${esc(t('empty_orders'))}</td></tr>`}</tbody></table></div></div></div>`;
    }

    function bindOrders() {
        el.querySelectorAll('[data-status]').forEach(b => b.addEventListener('click', () => { state.status = b.dataset.status; load(); }));
        el.querySelectorAll('tr[data-open-order]').forEach(tr => tr.addEventListener('click', (e) => {
            e.preventDefault();
            state.order = Number(tr.dataset.openOrder);
            receiving = false;
            data.preview = null;
            load();
        }));
    }

    function drawOrder() {
        const o = data.order;
        const edit = o.can_edit;
        const lineRow = (l) => edit ? `<tr data-line="${l.id}">
                <td><strong>${esc(l.name)}</strong></td>
                <td><div class="input-group input-group-sm tfb-supply-qty"><input class="form-control text-end" inputmode="decimal" data-lqty value="${esc(toInput(l, l.quantity))}"><span class="input-group-text">${esc(l.unit)}</span></div>
                    ${l.package_size && l.package_size !== l.factor ? `<div class="small text-muted">${esc(t('packages', {n: n(l.packages, 2), size: q(l, l.package_size)}))}</div>` : ''}</td>
                <td><div class="input-group input-group-sm tfb-supply-qty"><input class="form-control text-end" inputmode="decimal" data-lprice value="${l.unit_price === null ? '' : esc(String(Math.round(l.unit_price * l.factor * 10000) / 10000).replace('.', ','))}"><span class="input-group-text">zł/${esc(l.unit)}</span></div>
                    ${l.catalog_price !== null && l.unit_price !== null && Math.abs(l.catalog_price - l.unit_price) > 1e-9 ? `<div class="small text-muted">${esc(t('catalog', {price: unitPrice(l, l.catalog_price)}))}</div>` : ''}</td>
                <td class="text-end">${esc(money(l.value))}</td>
                <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" data-remove="${l.id}" title="${esc(t('remove_line'))}"><i class="bi bi-x-lg"></i></button></td></tr>`
            : `<tr><td><strong>${esc(l.name)}</strong></td>
                <td class="text-end">${esc(q(l, l.quantity))}${l.package_size && l.package_size !== l.factor ? `<div class="small text-muted">${esc(t('packages', {n: n(l.packages, 2), size: q(l, l.package_size)}))}</div>` : ''}</td>
                <td class="text-end">${esc(unitPrice(l, l.unit_price))}</td><td class="text-end">${esc(money(l.value))}</td>
                <td class="text-end">${l.received_qty > 0 ? `<strong>${esc(q(l, l.received_qty))}</strong>${l.qty_gap_pct ? `<div class="small ${l.qty_gap_pct < 0 ? 'text-danger' : 'text-muted'}">${l.qty_gap_pct > 0 ? '+' : ''}${esc(n(l.qty_gap_pct, 1))} %</div>` : ''}` : '—'}</td>
                <td class="text-end">${l.remaining > 0 ? esc(q(l, l.remaining)) : '✓'}</td>
                <td class="text-end">${l.last_price !== null ? `${esc(unitPrice(l, l.last_price))}${l.price_gap_pct ? `<div class="small ${l.price_gap_pct > 0 ? 'text-danger' : 'text-success'}">${l.price_gap_pct > 0 ? '+' : ''}${esc(n(l.price_gap_pct, 1))} %</div>` : ''}` : '—'}</td></tr>`;
        const head = edit
            ? `<tr><th>${esc(t('col.material'))}</th><th>${esc(t('col.quantity'))}</th><th>${esc(t('col.price'))}</th><th class="text-end">${esc(t('col.value'))}</th><th></th></tr>`
            : `<tr><th>${esc(t('col.material'))}</th><th class="text-end">${esc(t('col.quantity'))}</th><th class="text-end">${esc(t('col.price'))}</th><th class="text-end">${esc(t('col.value'))}</th>
                <th class="text-end">${esc(t('col.received'))}</th><th class="text-end">${esc(t('col.remaining'))}</th><th class="text-end" title="${esc(t('last_price_help'))}">${esc(t('col.last_price'))}</th></tr>`;
        const header = edit ? `<form class="row g-2 align-items-end" data-head>
                <div class="col-md-4"><label class="form-label small mb-0">${esc(t('f.email_to'))}</label><input class="form-control form-control-sm" name="email_to" type="email" value="${esc(o.email_to || '')}" data-field="email_to" placeholder="${esc(t('f.email_placeholder'))}">
                    <label class="form-check small mt-1"><input class="form-check-input" type="checkbox" name="save_supplier_email" ${o.supplier_email ? '' : 'checked'}> ${esc(t('f.save_supplier_email'))}</label></div>
                <div class="col-md-2"><label class="form-label small mb-0">${esc(t('f.delivery_date'))}</label><input class="form-control form-control-sm" name="delivery_date" type="date" value="${esc(o.delivery_date || '')}" data-field="delivery_date"></div>
                <div class="col-md-2"><label class="form-label small mb-0">${esc(t('f.language'))}</label><select class="form-select form-select-sm" name="language">${['pl', 'fr'].map(l => `<option value="${l}" ${o.language === l ? 'selected' : ''}>${esc(t('lang.' + l))}</option>`).join('')}</select></div>
                <div class="col-md-4"><label class="form-label small mb-0">${esc(t('f.note'))}</label><input class="form-control form-control-sm" name="note" value="${esc(o.note || '')}" data-field="note" maxlength="1000"></div>
                <div class="col-12"><button type="submit" class="btn btn-sm btn-outline-primary">${esc(t('save_header'))}</button></div></form>`
            : `<div class="small">${o.email_to ? `<i class="bi bi-envelope"></i> ${esc(o.email_to)} · ` : ''}${esc(t('lang.' + o.language))}${o.delivery_date ? ' · ' + esc(t('delivery_on', {day: day(o.delivery_date)})) : ''}${o.note ? ' · ' + esc(o.note) : ''}</div>`;
        const actions = [];
        if (edit) {
            actions.push(`<button type="button" class="btn btn-sm btn-outline-secondary" data-act="preview"><i class="bi bi-eye"></i> ${esc(t('act.preview'))}</button>`);
            actions.push(`<button type="button" class="btn btn-sm btn-primary" data-act="send" ${o.email_to ? '' : 'disabled'}><i class="bi bi-send"></i> ${esc(t('act.send'))}</button>`);
            actions.push(`<button type="button" class="btn btn-sm btn-outline-primary" data-act="manual" title="${esc(t('act.manual_help'))}"><i class="bi bi-telephone"></i> ${esc(t('act.manual'))}</button>`);
            actions.push(`<button type="button" class="btn btn-sm btn-outline-danger ms-auto" data-act="delete"><i class="bi bi-trash"></i> ${esc(t('act.delete'))}</button>`);
        }
        if (o.can_receive) {
            actions.push(`<button type="button" class="btn btn-sm btn-primary" data-act="receive"><i class="bi bi-box-arrow-in-down"></i> ${esc(t('act.receive'))}</button>`);
            actions.push(`<button type="button" class="btn btn-sm btn-outline-secondary" data-act="preview"><i class="bi bi-eye"></i> ${esc(t('act.preview'))}</button>`);
            actions.push(`<button type="button" class="btn btn-sm btn-outline-danger ms-auto" data-act="cancel"><i class="bi bi-x-circle"></i> ${esc(t('act.cancel'))}</button>`);
        }
        const preview = data.preview ? `<div class="card tfb-supply-mail"><div class="card-body">
                <div class="small text-muted">${esc(t('mail.to'))} : <strong>${esc(data.preview.recipient || t('no_email'))}</strong></div>
                <div class="small text-muted">${esc(t('mail.subject'))} : <strong>${esc(data.preview.subject)}</strong></div>
                <pre class="tfb-supply-mail-body mb-0">${esc(data.preview.body)}</pre><div class="small text-muted mt-1">${esc(t('mail.csv'))}</div></div></div>` : '';
        const receive = receiving && o.can_receive ? `<div class="card tfb-supply-receive"><div class="card-header d-flex flex-wrap gap-2 align-items-center"><h5 class="mb-0">${esc(t('receive_title'))}</h5>
                <input class="form-control form-control-sm" type="date" data-received-on value="${todayIso}" max="${todayIso}" style="width:auto"></div>
                <div class="card-body pt-0"><table class="table table-sm align-middle mb-2"><thead><tr><th>${esc(t('col.material'))}</th><th class="text-end">${esc(t('col.remaining'))}</th><th>${esc(t('col.received_now'))}</th><th>${esc(t('col.real_price'))}</th></tr></thead>
                <tbody>${o.lines.map(l => `<tr data-rline="${l.id}"><td>${esc(l.name)}</td><td class="text-end">${esc(q(l, l.remaining))}</td>
                    <td><div class="input-group input-group-sm tfb-supply-qty"><input class="form-control text-end" inputmode="decimal" data-rqty value="${esc(toInput(l, l.remaining))}"><span class="input-group-text">${esc(l.unit)}</span></div></td>
                    <td><div class="input-group input-group-sm tfb-supply-qty"><input class="form-control text-end" inputmode="decimal" data-rprice value="${l.unit_price === null ? '' : esc(String(Math.round(l.unit_price * l.factor * 10000) / 10000).replace('.', ','))}"><span class="input-group-text">zł/${esc(l.unit)}</span></div></td></tr>`).join('')}</tbody></table>
                <button type="button" class="btn btn-sm btn-primary" data-act="save-receipt">${esc(t('act.save_receipt'))}</button>
                <button type="button" class="btn btn-sm btn-link" data-act="close-receipt">${esc(t('act.close'))}</button></div></div>` : '';
        const receipts = o.receipts.length ? `<div class="small text-muted mt-2">${esc(t('receipts'))} : ${o.receipts.map(r => {
            const l = o.lines.find(x => x.id === r.line_id);
            return `${esc(day(r.received_on))} ${esc(l ? l.name : '')} ${esc(l ? q(l, r.quantity) : '')}${r.unit_price !== null && l ? ' @ ' + esc(unitPrice(l, r.unit_price)) : ''}`;
        }).join(' · ')}</div>` : '';
        return `<p><a href="#tab=orders" data-back>‹ ${esc(t('back_orders'))}</a></p>
            <div class="card"><div class="card-header d-flex flex-wrap align-items-center gap-2"><h5 class="mb-0">${esc(o.code)} · ${esc(o.supplier.name)}</h5>${badge(o.status)}
                <span class="small text-muted ms-auto">${o.week_start ? esc(t('for_week', {day: day(o.week_start)})) + ' · ' : ''}${o.sent_at ? esc(t('sent_on', {day: day(o.sent_at.slice(0, 10)), by: t('sent_by.' + o.sent_by)})) : esc(t('not_sent'))}</span></div>
                <div class="card-body">${header}
                    <div class="table-responsive mt-3"><table class="table table-sm align-middle mb-2 tfb-supply-table"><thead>${head}</thead><tbody>${o.lines.map(lineRow).join('') || `<tr><td colspan="7" class="text-muted">${esc(t('no_lines'))}</td></tr>`}</tbody>
                        <tfoot><tr><th colspan="3" class="text-end">${esc(t('total'))}</th><th class="text-end">${esc(money(o.value_ordered))}</th><th colspan="3" class="text-end">${o.value_received ? esc(t('received_value', {value: money(o.value_received), expected: money(o.value_received_expected)})) : ''}</th></tr></tfoot></table></div>
                    ${edit ? `<button type="button" class="btn btn-sm btn-outline-primary mb-2" data-act="save-lines">${esc(t('save_lines'))}</button>` : ''}
                    <div class="d-flex flex-wrap gap-2 mt-2">${actions.join('')}</div>${receipts}</div></div>
            ${preview}${receive}`;
    }

    function bindOrder() {
        const o = data.order;
        el.querySelector('[data-back]').addEventListener('click', (e) => { e.preventDefault(); state.order = null; receiving = false; data.preview = null; load(); });
        const run = async (btn, fn, ok) => {
            if (btn) btn.disabled = true;
            try {
                const res = await fn();
                if (res && res.id) data.order = res;
                if (ok) ui.notify(true, ok);
                data.orders = null;
                draw();
            } catch (err) {
                ui.notify(false, errText(err));
                if (btn) btn.disabled = false;
            }
        };
        const head = el.querySelector('[data-head]');
        head?.addEventListener('submit', (e) => {
            e.preventDefault();
            const f = new FormData(head);
            const body = {email_to: (f.get('email_to') || '').trim() || null, delivery_date: f.get('delivery_date') || null, language: f.get('language'), note: (f.get('note') || '').trim() || null,
                save_supplier_email: f.get('save_supplier_email') === 'on'};
            run(head.querySelector('button'), () => api.patch(`/supply/orders/${o.id}`, body), t('saved'));
        });
        el.querySelectorAll('[data-remove]').forEach(b => b.addEventListener('click', () => b.closest('tr').remove()));
        el.querySelectorAll('[data-act]').forEach(b => b.addEventListener('click', async () => {
            const act = b.dataset.act;
            if (act === 'preview') return run(b, async () => { data.preview = await api.get(`/supply/orders/${o.id}/preview`); return null; });
            if (act === 'save-lines') {
                const lines = [];
                for (const tr of el.querySelectorAll('tr[data-line]')) {
                    const l = o.lines.find(x => x.id === Number(tr.dataset.line));
                    const v = ui.num(tr.querySelector('[data-lqty]').value);
                    const p = ui.num(tr.querySelector('[data-lprice]').value);
                    if (typeof v !== 'number' || v <= 0 || (p !== null && (typeof p !== 'number' || p <= 0))) { ui.notify(false, t('qty_invalid', {list: l.name})); return; }
                    lines.push({raw_material_id: l.raw_material_id, quantity: Math.round(v * l.factor * 1000) / 1000, unit_price: p === null ? null : p / l.factor});
                }
                return run(b, () => api.put(`/supply/orders/${o.id}/lines`, {lines}), t('saved'));
            }
            if (act === 'send') {
                if (!window.confirm(t('confirm_send', {email: o.email_to}))) return;
                return run(b, () => api.post(`/supply/orders/${o.id}/send`, {}), t('sent_ok', {email: o.email_to}));
            }
            if (act === 'manual') {
                if (!window.confirm(t('confirm_manual'))) return;
                return run(b, () => api.post(`/supply/orders/${o.id}/send`, {manual: true}), t('manual_ok'));
            }
            if (act === 'delete') {
                if (!window.confirm(t('confirm_delete', {code: o.code}))) return;
                b.disabled = true;
                try { await api.del(`/supply/orders/${o.id}`); ui.notify(true, t('deleted')); state.order = null; data.orders = null; load(); } catch (err) { ui.notify(false, errText(err)); b.disabled = false; }
                return;
            }
            if (act === 'cancel') {
                if (!window.confirm(t('confirm_cancel', {code: o.code}))) return;
                return run(b, () => api.post(`/supply/orders/${o.id}/cancel`, {}), t('cancelled'));
            }
            if (act === 'receive') { receiving = true; draw(); el.querySelector('.tfb-supply-receive')?.scrollIntoView({block: 'center'}); return; }
            if (act === 'close-receipt') { receiving = false; draw(); return; }
            if (act === 'save-receipt') {
                const lines = [];
                for (const tr of el.querySelectorAll('tr[data-rline]')) {
                    const l = o.lines.find(x => x.id === Number(tr.dataset.rline));
                    const v = ui.num(tr.querySelector('[data-rqty]').value);
                    const p = ui.num(tr.querySelector('[data-rprice]').value);
                    if (v === null || v === 0) continue;
                    if (typeof v !== 'number' || v < 0 || (p !== null && (typeof p !== 'number' || p <= 0))) { ui.notify(false, t('qty_invalid', {list: l.name})); return; }
                    lines.push({id: l.id, quantity: Math.round(v * l.factor * 1000) / 1000, unit_price: p === null ? null : p / l.factor});
                }
                if (!lines.length) { ui.notify(false, t('nothing_received')); return; }
                receiving = false;
                return run(b, () => api.post(`/supply/orders/${o.id}/receive`, {received_on: el.querySelector('[data-received-on]').value || todayIso, lines}), t('received_ok'));
            }
        }));
    }

    // --- Surowce i stany ----------------------------------------------------------------------------------------------
    function drawStock() {
        const d = data.materials;
        const editable = d.can_write;
        const rows = d.rows.filter(r => kindOk(r) && searchOk(r) && (state.stockMode === 'all' || r.mode === state.stockMode)
            && (!state.used || r.weekly_use > 0 || r.stock !== null || r.on_order > 0 || r.mode === 'stock'));
        const minmax = (r, field) => `<div class="input-group input-group-sm tfb-supply-qty"><input class="form-control text-end" inputmode="decimal" data-limit="${field}" value="${esc(toInput(r, r[field]))}" placeholder="—" ${editable ? '' : 'disabled'}
            aria-label="${esc(t('col.' + field))}"><span class="input-group-text">${esc(r.unit)}</span></div>`;
        return `<div class="card tfb-supply-bar"><div class="card-body d-flex flex-wrap gap-2 align-items-center">
                ${kindButtons()}
                <div class="btn-group btn-group-sm" role="group">${['all', 'stock', 'order'].map(m => `<button type="button" class="btn ${state.stockMode === m ? 'btn-primary' : 'btn-outline-primary'}" data-smode="${m}">${esc(t('smode.' + m))}</button>`).join('')}</div>
                <label class="form-check form-switch m-0 small"><input class="form-check-input" type="checkbox" data-used ${state.used ? 'checked' : ''}> ${esc(t('only_used'))}</label>
                <input class="form-control form-control-sm ms-auto" style="max-width:220px" data-search value="${esc(state.search)}" placeholder="${esc(t('search'))}"></div></div>
            <p class="small text-muted mb-2">${esc(t('stock_help', {weeks: d.use_weeks}))}</p>
            <div class="card"><div class="card-body pt-3"><div class="table-responsive"><table class="table table-sm align-middle mb-0 tfb-supply-table">
                <thead><tr><th>${esc(t('col.material'))}</th><th class="text-center">${esc(t('col.mode'))}</th><th class="text-end">${esc(t('col.weekly_use'))}</th>
                    <th class="text-end">${esc(t('col.stock'))}</th><th class="text-end">${esc(t('col.on_order'))}</th><th>${esc(t('col.stock_min'))}</th><th>${esc(t('col.stock_max'))}</th>
                    <th>${esc(t('col.count'))}</th></tr></thead>
                <tbody>${rows.map(r => `<tr data-mat="${r.id}">
                    <td><strong>${esc(r.name)}</strong>${r.packaging ? ` <span class="badge bg-light text-dark">${esc(t('kind.pack'))}</span>` : ''}
                        <div class="small text-muted">${esc(r.category || '')}${r.supplier ? ' · ' + esc(r.supplier.name) : ' · ' + esc(t('no_supplier'))}${r.package_size ? ' · ' + esc(t('package', {size: q(r, r.package_size)})) : ''}</div></td>
                    <td class="text-center">${modeSwitch(r, editable)}</td>
                    <td class="text-end">${r.weekly_use > 0 ? esc(q(r, r.weekly_use)) : '—'}</td>
                    <td class="text-end">${stockCell(r)}</td>
                    <td class="text-end">${r.on_order > 0 ? esc(q(r, r.on_order)) : '—'}</td>
                    <td>${minmax(r, 'stock_min')}</td>
                    <td>${minmax(r, 'stock_max')}${editable && r.weekly_use > 0 ? `<button type="button" class="btn btn-link btn-sm p-0" data-suggest title="${esc(t('suggest_help'))}">${esc(t('suggest'))}</button>` : ''}</td>
                    <td><div class="d-flex gap-1"><div class="input-group input-group-sm tfb-supply-qty"><input class="form-control text-end" inputmode="decimal" data-count placeholder="—" ${editable ? '' : 'disabled'} aria-label="${esc(t('col.count'))}"><span class="input-group-text">${esc(r.unit)}</span></div>
                        <button type="button" class="btn btn-sm btn-outline-primary" data-save-count ${editable ? '' : 'disabled'} title="${esc(t('count_help'))}"><i class="bi bi-check2"></i></button></div></td></tr>`).join('')
                    || `<tr><td colspan="8" class="text-muted">${esc(t('empty_materials'))}</td></tr>`}</tbody></table></div></div></div>`;
    }

    function bindStock() {
        el.querySelectorAll('[data-kind]').forEach(b => b.addEventListener('click', () => { state.kind = b.dataset.kind; writeHash(); draw(); }));
        el.querySelectorAll('[data-smode]').forEach(b => b.addEventListener('click', () => { state.stockMode = b.dataset.smode; draw(); }));
        el.querySelector('[data-used]').addEventListener('change', (e) => { state.used = e.target.checked; draw(); });
        bindSearch();
        const replace = (m) => { const i = data.materials.rows.findIndex(x => x.id === m.id); if (i >= 0) data.materials.rows[i] = {...data.materials.rows[i], ...m}; };
        const save = async (r, body, input, ok) => {
            if (input) input.disabled = true;
            try {
                replace(await api.patch(`/supply/materials/${r.id}`, body));
                ui.notify(true, ok || t('saved'));
                draw();
            } catch (err) { ui.notify(false, errText(err)); if (input) input.disabled = false; }
        };
        el.querySelectorAll('tr[data-mat]').forEach(tr => {
            const r = data.materials.rows.find(x => x.id === Number(tr.dataset.mat));
            tr.querySelector('[data-mode]')?.addEventListener('change', (e) => save(r, {supply_mode: e.target.checked ? 'stock' : 'order'}, e.target,
                t(e.target.checked && r.stock_max === null ? 'mode_saved_set_minmax' : 'mode_saved', {name: r.name})));
            tr.querySelectorAll('[data-limit]').forEach(input => input.addEventListener('change', () => {
                const v = ui.num(input.value);
                if (v !== null && (typeof v !== 'number' || v < 0)) { ui.notify(false, t('qty_invalid', {list: r.name})); return; }
                save(r, {[input.dataset.limit]: v === null ? null : Math.round(v * r.factor * 1000) / 1000}, input);
            }));
            // Propozycja : min. = zużycie 1 tygodnia, maks. = 2 tygodni (w górę do pełnych opakowań).
            const suggest = tr.querySelector('[data-suggest]');
            suggest?.addEventListener('click', () => {
                const up = (v) => r.package_size ? Math.ceil(v / r.package_size - 1e-9) * r.package_size : Math.ceil(v);
                save(r, {supply_mode: 'stock', stock_min: up(r.weekly_use), stock_max: up(2 * r.weekly_use)}, suggest, t('suggest_saved', {name: r.name}));
            });
            const countBtn = tr.querySelector('[data-save-count]');
            countBtn?.addEventListener('click', async () => {
                const v = ui.num(tr.querySelector('[data-count]').value);
                if (typeof v !== 'number' || v < 0) { ui.notify(false, t('count_invalid')); return; }
                countBtn.disabled = true;
                try {
                    replace(await api.post(`/supply/materials/${r.id}/count`, {quantity: Math.round(v * r.factor * 1000) / 1000, count_date: todayIso}));
                    ui.notify(true, t('count_saved', {name: r.name}));
                    draw();
                } catch (err) { ui.notify(false, errText(err)); countBtn.disabled = false; }
            });
        });
    }

    // --- Kontrola faktur ----------------------------------------------------------------------------------------------
    // Faktura z KSeF albo z pliku (XML KSeF, PDF) ; nazwy dostawcy przypisane do naszych surowców (z przelicznikiem
    // jednostki faktury na jednostkę surowca), porównanie z zamówieniem i przyjęciami, decyzja. Przypisania są
    // zapamiętane na dostawcę : następne faktury przychodzą już przypisane.
    const ISTATUSES = ['open', 'accepted', 'disputed', 'all'];
    const STATE_CLS = {clean: 'bg-success', gaps: 'bg-danger', unmapped: 'bg-warning text-dark', no_order: 'bg-secondary', no_lines: 'bg-light text-dark'};
    const ALERT_CLS = {clean: 'alert-success', gaps: 'alert-danger', unmapped: 'alert-warning', no_order: 'alert-secondary', no_lines: 'alert-warning'};
    const FLAG_CLS = {unmapped: 'bg-warning text-dark', not_in_order: 'bg-danger', qty_over_received: 'bg-danger', qty_over_ordered: 'bg-danger',
        qty_under_received: 'bg-warning text-dark', price_over: 'bg-danger', price_under: 'bg-success'};
    const BAD_FLAGS = ['not_in_order', 'qty_over_received', 'qty_over_ordered', 'price_over'];
    const disp = (base) => base === 'g' ? {unit: 'kg', factor: 1000} : base === 'ml' ? {unit: 'l', factor: 1000} : {unit: base || 'szt', factor: 1};
    const fullDay = (s) => s ? `${s.slice(8, 10)}.${s.slice(5, 7)}.${s.slice(0, 4)}` : '—';
    const cur = (v, c) => v === null || v === undefined ? '—' : `${n(v, 2)} ${!c || c === 'PLN' ? 'zł' : c}`;
    const signed = (v, f) => `${v > 0 ? '+' : ''}${f(v)}`;
    const pct = (v) => signed(v, x => `${n(x, 1)} %`);
    const ctrlBadge = (c) => `<span class="badge ${STATE_CLS[c.state] || 'bg-secondary'}" title="${esc(t('inv.state_help.' + c.state))}">${esc(t('inv.state.' + c.state))}</span>`
        + (c.state === 'gaps' && Math.abs(c.value_gap) >= 0.01 ? ` <span class="small ${c.value_gap > 0 ? 'text-danger' : 'text-success'}">${esc(signed(c.value_gap, money))}</span>` : '');
    const invBadge = (s) => `<span class="badge tfb-inv-${s}">${esc(t('inv.status.' + s))}</span>`;
    const iv = {ksefOpen: false, ksef: null, from: null, to: null, editLines: false, editLine: null, file: null, showFile: false};

    function resetInvoice() {
        iv.editLines = false;
        iv.editLine = null;
        iv.showFile = false;
        if (iv.file) URL.revokeObjectURL(iv.file.url);
        iv.file = null;
    }
    function openInvoice(id, loaded) {
        state.inv = id;
        if (id === 'map') state.search = '';
        resetInvoice();
        if (!loaded) return load();
        writeHash();
        return showInvoice(loaded);
    }
    // Faktura do pokazania ; z liniami do przypisania — najpierw lista wszystkich surowców (wybór spoza podpowiedzi).
    async function showInvoice(invoice) {
        data.invoice = invoice;
        if (invoice.editable && invoice.lines.some(l => !l.material || l.id === iv.editLine)) await ensureMaterials();
        draw();
    }

    function drawInvoices() {
        const d = data.invoices;
        const w = d.can_write;
        if (!iv.from) { iv.from = addDays(todayIso.slice(0, 8) + '01', -1).slice(0, 8) + '01'; iv.to = todayIso; }
        const ksef = iv.ksefOpen ? `<div class="card tfb-inv-ksef"><div class="card-body">
                <form class="d-flex flex-wrap gap-2 align-items-end" data-ksef-form>
                    <div><label class="form-label small mb-0">${esc(t('inv.from'))}</label><input class="form-control form-control-sm" type="date" name="from" value="${esc(iv.from)}" max="${todayIso}" required></div>
                    <div><label class="form-label small mb-0">${esc(t('inv.to'))}</label><input class="form-control form-control-sm" type="date" name="to" value="${esc(iv.to)}" max="${todayIso}" required></div>
                    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i> ${esc(t('inv.ksef_search'))}</button>
                    <span class="small text-muted">${esc(t('inv.ksef_help'))}</span></form>
                ${iv.ksef ? ksefTable() : ''}</div></div>` : '';
        return `<div class="card tfb-supply-bar"><div class="card-body d-flex flex-wrap gap-2 align-items-center">
                <div class="btn-group btn-group-sm" role="group">${ISTATUSES.map(s => `<button type="button" class="btn ${state.istatus === s ? 'btn-primary' : 'btn-outline-primary'}" data-istatus="${s}">${esc(t('inv.filter.' + s))}</button>`).join('')}</div>
                ${w ? `<button type="button" class="btn btn-sm ${iv.ksefOpen ? 'btn-secondary' : 'btn-outline-primary'}" data-ksef-toggle><i class="bi bi-cloud-download"></i> ${esc(t('inv.from_ksef'))}</button>
                <label class="btn btn-sm btn-outline-primary mb-0"><i class="bi bi-upload"></i> ${esc(t('inv.upload'))}<input type="file" class="d-none" accept=".pdf,.xml,application/pdf,application/xml,text/xml" data-upload></label>` : ''}
                <a href="#tab=invoices&inv=map" class="btn btn-sm btn-link ms-auto" data-mappings><i class="bi bi-arrow-left-right"></i> ${esc(t('inv.mappings'))}</a></div></div>
            ${ksef}
            <p class="small text-muted mb-2">${esc(t('inv.help'))}</p>
            <div class="card"><div class="card-body pt-3"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
                <thead><tr><th>${esc(t('inv.col.number'))}</th><th>${esc(t('col.supplier'))}</th><th>${esc(t('inv.col.date'))}</th><th class="text-end">${esc(t('inv.col.net'))}</th>
                    <th>${esc(t('inv.col.order'))}</th><th>${esc(t('inv.col.control'))}</th><th>${esc(t('col.status'))}</th></tr></thead>
                <tbody>${d.invoices.map(i => `<tr data-open-inv="${i.id}" class="tfb-supply-click">
                    <td><a href="#tab=invoices&inv=${i.id}"><strong>${esc(i.number || t('inv.no_number'))}</strong></a><div class="small text-muted">${esc(t('inv.source.' + i.source))}</div></td>
                    <td>${i.supplier ? esc(i.supplier.name) : `${esc(i.seller_name || '—')}<div class="small text-warning">${esc(t('inv.no_supplier'))}</div>`}</td>
                    <td>${esc(fullDay(i.issue_date))}</td><td class="text-end">${esc(cur(i.total_net, i.currency))}</td>
                    <td>${i.order ? esc(i.order.code) : '—'}</td>
                    <td>${ctrlBadge(i.control)}${i.control.lines ? `<div class="small text-muted">${esc(t('inv.mapped_n', {mapped: i.control.mapped, lines: i.control.lines}))}</div>` : ''}</td>
                    <td>${invBadge(i.status)}</td></tr>`).join('')
                    || `<tr><td colspan="7" class="text-muted">${esc(t('inv.empty'))}</td></tr>`}</tbody></table></div></div></div>`;
    }

    function ksefTable() {
        const rows = iv.ksef.invoices;
        if (!rows.length) return `<p class="text-muted small mt-2 mb-0">${esc(t('inv.ksef_empty'))}</p>`;
        const fresh = rows.filter(r => !r.imported).length;
        return `<div class="table-responsive mt-2"><table class="table table-sm align-middle mb-2"><thead><tr>
                <th class="text-center"><input class="form-check-input" type="checkbox" data-ksef-all ${fresh ? 'checked' : 'disabled'} aria-label="${esc(t('inv.pick_all'))}"></th>
                <th>${esc(t('inv.col.number'))}</th><th>${esc(t('inv.col.seller'))}</th><th>${esc(t('inv.col.date'))}</th><th class="text-end">${esc(t('inv.col.net'))}</th><th class="text-end">${esc(t('inv.col.gross'))}</th><th></th></tr></thead>
            <tbody>${rows.map(r => `<tr><td class="text-center"><input class="form-check-input" type="checkbox" data-ksef-pick="${esc(r.ksef_number)}" ${r.imported ? 'disabled' : 'checked'} aria-label="${esc(t('pick'))}"></td>
                <td><strong>${esc(r.number || '—')}</strong><div class="small text-muted">${esc(r.ksef_number)}</div></td>
                <td>${esc(r.seller_name || '—')}<div class="small text-muted">NIP ${esc(r.seller_nip || '—')}</div></td>
                <td>${esc(fullDay(r.issue_date))}</td><td class="text-end">${esc(cur(r.net, r.currency))}</td><td class="text-end">${esc(cur(r.gross, r.currency))}</td>
                <td>${r.imported ? `<span class="badge bg-light text-dark">${esc(t('inv.imported'))}</span>` : ''}</td></tr>`).join('')}</tbody></table></div>
            <button type="button" class="btn btn-sm btn-primary" data-ksef-import ${fresh ? '' : 'disabled'}><i class="bi bi-cloud-download"></i> ${esc(t('inv.ksef_import'))}</button>`;
    }

    function bindInvoices() {
        el.querySelectorAll('[data-istatus]').forEach(b => b.addEventListener('click', () => { state.istatus = b.dataset.istatus; load(); }));
        el.querySelector('[data-mappings]').addEventListener('click', (e) => { e.preventDefault(); openInvoice('map'); });
        el.querySelectorAll('tr[data-open-inv]').forEach(tr => tr.addEventListener('click', (e) => { e.preventDefault(); openInvoice(Number(tr.dataset.openInv)); }));
        el.querySelector('[data-ksef-toggle]')?.addEventListener('click', () => { iv.ksefOpen = !iv.ksefOpen; draw(); });
        const form = el.querySelector('[data-ksef-form]');
        form?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const f = new FormData(form);
            iv.from = f.get('from');
            iv.to = f.get('to');
            const btn = form.querySelector('button');
            btn.disabled = true;
            try {
                iv.ksef = await api.post('/supply/invoices/ksef/search', {from: iv.from, to: iv.to});
                draw();
            } catch (err) { ui.notify(false, errText(err)); btn.disabled = false; }
        });
        el.querySelector('[data-ksef-all]')?.addEventListener('change', (e) => el.querySelectorAll('[data-ksef-pick]:not(:disabled)').forEach(c => { c.checked = e.target.checked; }));
        const importBtn = el.querySelector('[data-ksef-import]');
        importBtn?.addEventListener('click', async () => {
            const numbers = [...el.querySelectorAll('[data-ksef-pick]:checked')].map(c => c.dataset.ksefPick);
            if (!numbers.length) { ui.notify(false, t('inv.nothing_picked')); return; }
            importBtn.disabled = true;
            try {
                const res = await api.post('/supply/invoices/ksef/import', {ksef_numbers: numbers.slice(0, 50)});
                ui.notify(!res.errors.length, t('inv.ksef_done', {n: res.imported.length, skipped: res.skipped})
                    + (res.errors.length ? ' ' + t('inv.ksef_errors', {list: res.errors.map(x => x.ksef_number).join(', ')}) : ''));
                iv.ksef = null;
                iv.ksefOpen = false;
                if (res.imported.length === 1) return openInvoice(res.imported[0]);
                load();
            } catch (err) { ui.notify(false, errText(err)); importBtn.disabled = false; }
        });
        el.querySelector('[data-upload]')?.addEventListener('change', (e) => upload(e.target));
    }

    async function upload(input) {
        const file = input.files && input.files[0];
        if (!file) return;
        if (file.size > 5 * 1024 * 1024) { ui.notify(false, t('inv.too_big')); input.value = ''; return; }
        input.disabled = true;
        try {
            const content = await new Promise((resolve, reject) => {
                const r = new FileReader();
                r.onload = () => resolve(String(r.result).split(',')[1] || '');
                r.onerror = () => reject(r.error);
                r.readAsDataURL(file);
            });
            const res = await api.post('/supply/invoices/upload', {filename: file.name, content_base64: content});
            ui.notify(true, t('inv.uploaded', {number: res.number || file.name}));
            openInvoice(res.id, res);
        } catch (err) {
            ui.notify(false, errText(err));
            input.disabled = false;
            input.value = '';
        }
    }

    function drawInvoice() {
        const i = data.invoice;
        const c = i.control;
        const edit = i.editable;
        const isCurrent = (o) => i.order && i.order.id === o.id;
        const seen = new Set();
        const twins = new Set(i.suppliers.map(s => s.name.toLowerCase()).filter(x => seen.has(x) || !seen.add(x)));
        const supplierField = edit ? `<select class="form-select form-select-sm" data-inv-supplier data-field="supplier_id"><option value="">${esc(t('inv.pick_supplier'))}</option>
                ${i.suppliers.map(s => `<option value="${s.id}" ${i.supplier && i.supplier.id === s.id ? 'selected' : ''}>${esc(s.name)}${twins.has(s.name.toLowerCase()) ? ` (#${s.id})` : ''}</option>`).join('')}</select>`
            : `<div>${esc(i.supplier ? i.supplier.name : '—')}</div>`;
        const orderLink = i.order ? `<a href="#tab=orders&order=${i.order.id}" data-goto-order="${i.order.id}">${esc(i.order.code)}</a> ${badge(i.order.status)}` : '';
        const orderField = edit && i.supplier ? `<select class="form-select form-select-sm" data-inv-order data-field="po_id"><option value="">${esc(t('inv.without_order'))}</option>
                ${i.orders.map(o => `<option value="${o.id}" ${isCurrent(o) ? 'selected' : ''}>${esc(o.code)} · ${esc(t('status.' + o.status))}${o.delivery_date ? ' · ' + esc(day(o.delivery_date)) : ''}
                    · ${esc(t('inv.overlap', {n: o.overlap, lines: o.lines}))}${o.invoices && !isCurrent(o) ? ' · ' + esc(t('inv.already_invoiced')) : ''}</option>`).join('')}</select>
                ${orderLink ? `<div class="small mt-1">${orderLink}</div>` : ''}`
            : `<div>${orderLink || (i.supplier ? '—' : `<span class="small text-muted">${esc(t('inv.pick_supplier_first'))}</span>`)}</div>`;
        const idFields = edit && ['pdf', 'manual'].includes(i.source)
            ? `<div class="col-md-2"><label class="form-label small mb-0">${esc(t('inv.f.number'))}</label><input class="form-control form-control-sm" data-inv-field="number" data-field="number" value="${esc(i.number || '')}" maxlength="80"></div>
               <div class="col-md-2"><label class="form-label small mb-0">${esc(t('inv.f.issue_date'))}</label><input class="form-control form-control-sm" type="date" data-inv-field="issue_date" data-field="issue_date" value="${esc(i.issue_date || '')}"></div>` : '';
        const summary = {
            no_lines: t('inv.sum.no_lines'),
            unmapped: t('inv.sum.unmapped', {n: c.lines - c.mapped, lines: c.lines}),
            no_order: t('inv.sum.no_order'),
            gaps: t('inv.sum.gaps', {n: c.issues}) + (Math.abs(c.value_gap) >= 0.01 ? ' ' + t('inv.sum.value_gap', {gap: signed(c.value_gap, money)}) : ''),
            clean: t('inv.sum.clean'),
        }[c.state];
        const lines = iv.editLines ? editLinesTable(i) : `<div class="table-responsive"><table class="table table-sm align-middle mb-2 tfb-supply-table tfb-inv-lines"><thead><tr>
                <th>${esc(t('inv.col.line'))}</th><th class="text-end">${esc(t('col.quantity'))}</th><th class="text-end">${esc(t('inv.col.unit_price'))}</th><th class="text-end">${esc(t('inv.col.net'))}</th>
                <th>${esc(t('inv.col.material'))}</th><th class="text-end">${esc(t('inv.col.our_qty'))}</th><th class="text-end">${esc(t('inv.col.our_price'))}</th><th>${esc(t('inv.col.control'))}</th></tr></thead>
            <tbody>${i.lines.map(l => invoiceLine(l, edit)).join('') || `<tr><td colspan="8" class="text-muted">${esc(t(i.lines_editable ? 'inv.no_lines_pdf' : 'inv.no_lines'))}</td></tr>`}</tbody>
            <tfoot><tr><th colspan="3" class="text-end">${esc(t('total'))}</th><th class="text-end">${esc(cur(c.lines_net, i.currency))}</th><th colspan="4"></th></tr></tfoot></table></div>`;
        const actions = [];
        if (i.lines_editable && !iv.editLines) actions.push(`<button type="button" class="btn btn-sm btn-outline-secondary" data-iact="edit-lines"><i class="bi bi-pencil"></i> ${esc(t('inv.act.edit_lines'))}</button>`);
        if (edit) {
            actions.push(`<button type="button" class="btn btn-sm btn-success" data-iact="accept"><i class="bi bi-check2-circle"></i> ${esc(t('inv.act.accept'))}</button>`);
            actions.push(`<button type="button" class="btn btn-sm btn-outline-danger" data-iact="dispute"><i class="bi bi-exclamation-octagon"></i> ${esc(t('inv.act.dispute'))}</button>`);
            actions.push(`<button type="button" class="btn btn-sm btn-outline-danger ms-auto" data-iact="delete"><i class="bi bi-trash"></i> ${esc(t('inv.act.delete'))}</button>`);
        } else if (i.can_write && i.status !== 'open') {
            actions.push(`<button type="button" class="btn btn-sm btn-outline-secondary" data-iact="reopen"><i class="bi bi-arrow-counterclockwise"></i> ${esc(t('inv.act.reopen'))}</button>`);
        }
        const file = i.file ? `<button type="button" class="btn btn-sm btn-outline-secondary" data-iact="file"><i class="bi bi-file-earmark-text"></i> ${esc(t(iv.showFile ? 'inv.act.hide_file' : 'inv.act.show_file'))}</button>` : '';
        const viewer = iv.showFile && iv.file ? `<div class="card tfb-inv-viewer"><div class="card-header d-flex align-items-center gap-2"><strong class="small">${esc(iv.file.name)}</strong>
                <a class="btn btn-sm btn-link ms-auto" href="${iv.file.url}" download="${esc(iv.file.name)}"><i class="bi bi-download"></i> ${esc(t('inv.download'))}</a></div>
                <iframe src="${iv.file.url}" title="${esc(iv.file.name)}"></iframe></div>` : '';
        return `<p><a href="#tab=invoices" data-back-inv>‹ ${esc(t('inv.back'))}</a></p>
            <div class="card"><div class="card-header d-flex flex-wrap align-items-center gap-2">
                <h5 class="mb-0">${esc(i.number || t('inv.no_number'))} · ${esc(i.supplier ? i.supplier.name : (i.seller_name || '—'))}</h5>${invBadge(i.status)} ${ctrlBadge(c)}
                <span class="small text-muted ms-auto">${esc(t('inv.source.' + i.source))} · ${esc(fullDay(i.issue_date))}${i.seller_nip ? ' · NIP ' + esc(i.seller_nip) : ''}</span>${file}</div>
              <div class="card-body">
                <div class="row g-2 mb-2" data-inv-head>
                    <div class="col-md-3"><label class="form-label small mb-0">${esc(t('col.supplier'))}</label>${supplierField}
                        ${i.seller_name ? `<div class="small text-muted">${esc(t('inv.on_invoice', {name: i.seller_name}))}</div>` : ''}</div>
                    <div class="col-md-3"><label class="form-label small mb-0">${esc(t('inv.col.order'))}</label>${orderField}</div>
                    ${idFields}
                    <div class="col-md-2"><label class="form-label small mb-0">${esc(t('inv.totals'))}</label><div>${esc(cur(i.total_net, i.currency))}
                        <span class="small text-muted">· ${esc(t('inv.gross', {v: cur(i.total_gross, i.currency)}))}</span></div></div></div>
                <div class="alert ${ALERT_CLS[c.state] || 'alert-secondary'} py-2 small mb-2">${esc(summary)}
                    ${c.total_mismatch ? `<div>${esc(t('inv.sum.total_mismatch', {lines: cur(c.lines_net, i.currency), total: cur(i.total_net, i.currency)}))}</div>` : ''}</div>
                ${lines}
                ${iv.editLines ? '' : importBox(i)}
                ${i.note ? `<div class="small mb-2"><strong>${esc(t('f.note'))} :</strong> ${esc(i.note)}</div>` : ''}
                ${i.text ? `<details class="tfb-inv-text mb-2"><summary class="small">${esc(t('inv.pdf_text'))}</summary><pre>${esc(i.text)}</pre></details>` : ''}
                <div class="d-flex flex-wrap gap-2 mt-2">${actions.join('')}</div></div></div>
            ${viewer}`;
    }

    // Import do kartoteki : cena z faktury (netto na jednostkę surowca, ważona przy kilku liniach) wobec ceny w kartotece ;
    // ceny → kartoteka surowców, ilości → przyjęcie (do zamówienia albo bez zamówienia).
    function importBox(i) {
        if (!i.can_import_prices && !i.can_receive && !i.prices_imported_at) return '';
        const byMaterial = new Map();
        for (const l of i.lines) {
            if (!l.material || !l.control.quantity_base) continue;
            const net = l.net_value ?? (l.unit_price !== null ? l.unit_price * l.quantity : null);
            const m = byMaterial.get(l.material.id) || {material: l.material, qty: 0, value: 0, priced: 0, catalog: l.control.catalog_price};
            m.qty += l.control.quantity_base;
            if (net !== null) { m.value += net; m.priced += l.control.quantity_base; }
            byMaterial.set(l.material.id, m);
        }
        const rows = [...byMaterial.values()].map(m => {
            const u = {unit: m.material.unit, factor: m.material.factor_display};
            const price = m.priced > 0 ? m.value / m.priced : null;
            const gap = price !== null && m.catalog ? (price - m.catalog) / m.catalog * 100 : null;
            return `<tr><td><strong>${esc(m.material.name)}</strong></td><td class="text-end text-nowrap">${esc(q(u, m.qty))}</td>
                <td class="text-end text-nowrap">${esc(unitPrice(u, price))}</td><td class="text-end text-nowrap">${esc(unitPrice(u, m.catalog))}</td>
                <td class="text-end text-nowrap ${gap === null || Math.abs(gap) < 0.05 ? 'text-muted' : gap > 0 ? 'text-danger' : 'text-success'}">${gap === null ? '—' : esc(pct(gap))}</td></tr>`;
        });
        if (!rows.length) return '';
        const buttons = [];
        if (i.can_import_prices) buttons.push(`<button type="button" class="btn btn-sm btn-primary" data-iact="prices"><i class="bi bi-tags"></i> ${esc(t('inv.act.prices', {n: rows.length}))}</button>`);
        if (i.can_receive) buttons.push(`<button type="button" class="btn btn-sm btn-outline-primary" data-iact="receive" title="${esc(t('inv.act.receive_help'))}"><i class="bi bi-box-arrow-in-down"></i> ${esc(t(i.receive_mode === 'order' ? 'inv.act.receive' : 'inv.act.stock'))}</button>`);
        return `<div class="card tfb-inv-import mb-2"><div class="card-body">
            <h6 class="tfb-team-title mb-1">${esc(t('inv.import_title'))}</h6><p class="small text-muted mb-2">${esc(t('inv.import_help'))}</p>
            <div class="table-responsive"><table class="table table-sm align-middle mb-2"><thead><tr><th>${esc(t('inv.col.material'))}</th><th class="text-end">${esc(t('col.quantity'))}</th>
                <th class="text-end">${esc(t('inv.col.invoice_price'))}</th><th class="text-end">${esc(t('inv.col.catalog_price'))}</th><th class="text-end">${esc(t('inv.col.diff'))}</th></tr></thead>
                <tbody>${rows.join('')}</tbody></table></div>
            <div class="d-flex flex-wrap gap-2 align-items-center">${buttons.join('')}
                ${i.prices_imported_at ? `<span class="small text-success"><i class="bi bi-check2"></i> ${esc(t('inv.prices_done', {at: fullDay(i.prices_imported_at.slice(0, 10)) + ' ' + i.prices_imported_at.slice(11, 16)}))}</span>` : ''}</div></div></div>`;
    }

    function invoiceLine(l, edit) {
        const k = l.control;
        const m = l.material;
        const u = m ? {unit: m.unit, factor: m.factor_display} : null;
        const mapping = edit && (!m || iv.editLine === l.id) ? mapForm(l)
            : m ? `<strong>${esc(m.name)}</strong><div class="small text-muted">${esc(t('inv.factor_text', {unit: l.unit || 'szt', n: n(l.factor / u.factor, 4), our: u.unit}))}</div>
                ${edit ? `<button type="button" class="btn btn-link btn-sm p-0" data-edit-map="${l.id}">${esc(t('inv.change'))}</button>` : ''}`
            : `<span class="text-muted">—</span>`;
        const flags = k.flags.map(f => `<span class="badge ${FLAG_CLS[f] || 'bg-secondary'}">${esc(t('inv.flag.' + f))}</span>`).join(' ');
        const ours = !m ? '<td></td><td></td>' : `<td class="text-end text-nowrap">${esc(q(u, k.quantity_base))}
                ${k.ordered !== null ? `<div class="small text-muted">${esc(t('inv.ordered_received', {ordered: q(u, k.ordered), received: k.received > 0 ? q(u, k.received) : '—'}))}</div>` : ''}
                ${k.qty_gap_pct ? `<div class="small ${k.flags.some(f => f.startsWith('qty_')) ? 'text-danger' : 'text-muted'}">${esc(pct(k.qty_gap_pct))}</div>` : ''}</td>
            <td class="text-end text-nowrap">${esc(unitPrice(u, k.price_base))}
                ${k.order_price !== null ? `<div class="small text-muted">${esc(t('inv.order_price', {price: unitPrice(u, k.order_price)}))}</div>`
                    : k.catalog_price !== null ? `<div class="small text-muted">${esc(t('catalog', {price: unitPrice(u, k.catalog_price)}))}${k.catalog_gap_pct ? ' · ' + esc(pct(k.catalog_gap_pct)) : ''}</div>` : ''}
                ${k.price_gap_pct ? `<div class="small ${k.flags.includes('price_over') ? 'text-danger' : k.flags.includes('price_under') ? 'text-success' : 'text-muted'}">${esc(pct(k.price_gap_pct))}${k.value_gap ? ' · ' + esc(signed(k.value_gap, money)) : ''}</div>` : ''}</td>`;
        return `<tr data-iline="${l.id}" class="${k.flags.some(f => BAD_FLAGS.includes(f)) ? 'table-danger' : ''}">
            <td><strong>${esc(l.name)}</strong>${l.code ? `<div class="small text-muted">${esc(l.code)}</div>` : ''}</td>
            <td class="text-end text-nowrap">${esc(n(l.quantity, 3))} ${esc(l.unit || '')}</td>
            <td class="text-end text-nowrap">${l.unit_price !== null ? esc(n(l.unit_price, 4)) : '—'}</td>
            <td class="text-end text-nowrap">${esc(money(l.net_value))}${l.vat_rate ? `<div class="small text-muted">VAT ${esc(l.vat_rate)}${/^\d+$/.test(l.vat_rate) ? ' %' : ''}</div>` : ''}</td>
            <td class="tfb-inv-map">${mapping}</td>${ours}<td>${flags}</td></tr>`;
    }

    // Wybór surowca : podpowiedzi (nazwa, zamówienie, dostawca), potem wszystkie surowce ; przelicznik w jednostce wyświetlanej (kg, l).
    function mapForm(l) {
        const sug = l.suggestions || [];
        const chosen = l.material ? l.material.id : (sug[0] ? sug[0].raw_material_id : null);
        const sugIds = new Set(sug.map(s => s.raw_material_id));
        const all = (data.materials ? data.materials.rows : []).filter(m => !sugIds.has(m.id));
        const opts = `<option value="">${esc(t('inv.pick_material'))}</option>`
            + (sug.length ? `<optgroup label="${esc(t('inv.suggested'))}">${sug.map(s => `<option value="${s.raw_material_id}" ${s.raw_material_id === chosen ? 'selected' : ''}>${esc(s.name)}</option>`).join('')}</optgroup>` : '')
            + (all.length ? `<optgroup label="${esc(t('inv.all_materials'))}">${all.map(m => `<option value="${m.id}" ${m.id === chosen ? 'selected' : ''}>${esc(m.name)}</option>`).join('')}</optgroup>` : '');
        return `<div class="tfb-inv-mapform" data-mapform="${l.id}"><select class="form-select form-select-sm mb-1" data-map-mat aria-label="${esc(t('inv.col.material'))}">${opts}</select>
            <div class="input-group input-group-sm mb-1"><span class="input-group-text">1 ${esc(l.unit || 'szt')} =</span>
                <input class="form-control text-end" inputmode="decimal" data-map-factor placeholder="${esc(t('inv.factor_auto'))}" aria-label="${esc(t('inv.factor'))}"><span class="input-group-text" data-map-unit>—</span></div>
            <label class="form-check small mb-1"><input class="form-check-input" type="checkbox" data-map-remember checked> ${esc(t('inv.remember'))}</label>
            <div class="d-flex flex-wrap gap-1"><button type="button" class="btn btn-sm btn-primary" data-map-save>${esc(t('inv.map'))}</button>
                ${l.material ? `<button type="button" class="btn btn-sm btn-outline-danger" data-map-clear>${esc(t('inv.unmap'))}</button><button type="button" class="btn btn-sm btn-link" data-map-cancel>${esc(t('act.close'))}</button>` : ''}</div></div>`;
    }

    // Linie PDF : tekst odczytany automatycznie, do poprawienia ręcznie (nazwa, ilość, jednostka, cena, wartość, VAT).
    function editLinesTable(i) {
        const row = (l) => `<tr data-eline>
            <td><input class="form-control form-control-sm" data-e="name" value="${esc(l.name || '')}" maxlength="500" aria-label="${esc(t('inv.col.line'))}"></td>
            <td><input class="form-control form-control-sm text-end" data-e="quantity" inputmode="decimal" value="${l.quantity !== undefined && l.quantity !== null ? esc(String(l.quantity).replace('.', ',')) : ''}" aria-label="${esc(t('col.quantity'))}"></td>
            <td><input class="form-control form-control-sm" data-e="unit" value="${esc(l.unit || '')}" maxlength="20" aria-label="${esc(t('inv.col.unit'))}"></td>
            <td><input class="form-control form-control-sm text-end" data-e="unit_price" inputmode="decimal" value="${l.unit_price !== undefined && l.unit_price !== null ? esc(String(Math.round(l.unit_price * 10000) / 10000).replace('.', ',')) : ''}" aria-label="${esc(t('inv.col.unit_price'))}"></td>
            <td><input class="form-control form-control-sm text-end" data-e="net_value" inputmode="decimal" value="${l.net_value !== undefined && l.net_value !== null ? esc(String(l.net_value).replace('.', ',')) : ''}" aria-label="${esc(t('inv.col.net'))}"></td>
            <td><input class="form-control form-control-sm" data-e="vat_rate" value="${esc(l.vat_rate || '')}" maxlength="8" aria-label="VAT"></td>
            <td><button type="button" class="btn btn-sm btn-outline-danger" data-e-remove title="${esc(t('remove_line'))}"><i class="bi bi-x-lg"></i></button></td></tr>`;
        return `<p class="small text-muted mb-1">${esc(t('inv.edit_help'))}</p>
            <div class="table-responsive"><table class="table table-sm align-middle mb-2 tfb-inv-edit"><thead><tr><th>${esc(t('inv.col.line'))}</th><th>${esc(t('col.quantity'))}</th><th>${esc(t('inv.col.unit'))}</th>
                <th>${esc(t('inv.col.unit_price'))}</th><th>${esc(t('inv.col.net'))}</th><th>VAT</th><th></th></tr></thead>
            <tbody data-elines>${(i.lines.length ? i.lines : [{}]).map(row).join('')}</tbody></table></div>
            <template data-eline-template>${row({})}</template>
            <div class="d-flex flex-wrap gap-2 mb-2"><button type="button" class="btn btn-sm btn-outline-secondary" data-iact="add-line"><i class="bi bi-plus-lg"></i> ${esc(t('inv.act.add_line'))}</button>
                <button type="button" class="btn btn-sm btn-primary" data-iact="save-lines">${esc(t('save_lines'))}</button>
                <button type="button" class="btn btn-sm btn-link" data-iact="cancel-lines">${esc(t('act.close'))}</button></div>`;
    }

    function bindInvoice() {
        const i = data.invoice;
        el.querySelector('[data-back-inv]').addEventListener('click', (e) => { e.preventDefault(); state.inv = null; resetInvoice(); load(); });
        el.querySelector('[data-goto-order]')?.addEventListener('click', (e) => {
            e.preventDefault();
            state.tab = 'orders';
            state.order = Number(e.currentTarget.dataset.gotoOrder);
            state.inv = null;
            resetInvoice();
            load();
        });
        const run = async (ctrl, fn, ok) => {
            if (ctrl) ctrl.disabled = true;
            try {
                const res = await fn();
                if (ok) ui.notify(true, ok);
                await showInvoice(res && res.id ? res : i);
                return true;
            } catch (err) {
                const orphans = ui.fieldErrors(el.querySelector('[data-inv-head]') || el, err);
                ui.notify(false, orphans.join(' ') || errText(err));
                if (ctrl) ctrl.disabled = false;
                return false;
            }
        };
        const supplier = el.querySelector('[data-inv-supplier]');
        supplier?.addEventListener('change', () => run(supplier, () => api.patch(`/supply/invoices/${i.id}`, {supplier_id: supplier.value ? Number(supplier.value) : null}), t('saved')));
        const order = el.querySelector('[data-inv-order]');
        order?.addEventListener('change', () => run(order, () => api.patch(`/supply/invoices/${i.id}`, {po_id: order.value ? Number(order.value) : null}), t('saved')));
        el.querySelectorAll('[data-inv-field]').forEach(input => input.addEventListener('change', () => {
            const v = input.value.trim();
            run(input, () => api.patch(`/supply/invoices/${i.id}`, {[input.dataset.invField]: v || null}), t('saved'));
        }));
        // Formularze przypisania : jednostka wyświetlana i domyślny przelicznik wybranego surowca.
        el.querySelectorAll('[data-mapform]').forEach(form => {
            const l = i.lines.find(x => x.id === Number(form.dataset.mapform));
            const select = form.querySelector('[data-map-mat]');
            const factor = form.querySelector('[data-map-factor]');
            const fill = () => {
                const id = Number(select.value) || null;
                const s = (l.suggestions || []).find(x => x.raw_material_id === id);
                const m = data.materials ? data.materials.rows.find(x => x.id === id) : null;
                const base = l.material && l.material.id === id ? l.material.base_unit : s ? s.base_unit : m ? m.base_unit : null;
                const u = disp(base);
                const f = l.material && l.material.id === id ? l.factor : s ? s.factor : null;
                form.querySelector('[data-map-unit]').textContent = base ? u.unit : '—';
                factor.value = f !== null && f !== undefined ? String(Math.round(f / u.factor * 10000) / 10000).replace('.', ',') : '';
                factor.dataset.scale = u.factor;
            };
            fill();
            select.addEventListener('change', fill);
            const save = form.querySelector('[data-map-save]');
            save.addEventListener('click', () => {
                const id = Number(select.value) || null;
                if (!id) { ui.notify(false, t('inv.pick_material')); return; }
                const f = ui.num(factor.value);
                if (f !== null && (typeof f !== 'number' || f <= 0)) { ui.notify(false, t('inv.factor_invalid')); return; }
                const body = {raw_material_id: id, remember: form.querySelector('[data-map-remember]').checked};
                if (f !== null) body.factor = Math.round(f * Number(factor.dataset.scale || 1) * 10000) / 10000;
                iv.editLine = null;
                run(save, () => api.put(`/supply/invoices/${i.id}/lines/${l.id}/map`, body), t('inv.mapped_ok', {name: select.selectedOptions[0].textContent}));
            });
            form.querySelector('[data-map-clear]')?.addEventListener('click', (e) => {
                iv.editLine = null;
                run(e.currentTarget, () => api.put(`/supply/invoices/${i.id}/lines/${l.id}/map`, {raw_material_id: null, remember: false}), t('saved'));
            });
            form.querySelector('[data-map-cancel]')?.addEventListener('click', () => { iv.editLine = null; draw(); });
        });
        el.querySelectorAll('[data-edit-map]').forEach(b => b.addEventListener('click', () => { iv.editLine = Number(b.dataset.editMap); showInvoice(i); }));
        el.querySelectorAll('[data-e-remove]').forEach(b => b.addEventListener('click', () => b.closest('tr').remove()));
        el.querySelectorAll('[data-iact]').forEach(b => b.addEventListener('click', async () => {
            const act = b.dataset.iact;
            if (act === 'file') return toggleFile(b);
            if (act === 'edit-lines') { iv.editLines = true; draw(); return; }
            if (act === 'cancel-lines') { iv.editLines = false; draw(); return; }
            if (act === 'add-line') {
                const tpl = el.querySelector('[data-eline-template]');
                const tr = tpl.content.firstElementChild.cloneNode(true);
                tr.querySelector('[data-e-remove]').addEventListener('click', () => tr.remove());
                el.querySelector('[data-elines]').appendChild(tr);
                tr.querySelector('[data-e="name"]').focus();
                return;
            }
            if (act === 'save-lines') {
                const lines = [];
                for (const tr of el.querySelectorAll('[data-elines] tr[data-eline]')) {
                    const v = (k) => tr.querySelector(`[data-e="${k}"]`).value.trim();
                    if (!v('name') && !v('quantity') && !v('net_value')) continue;
                    const qty = ui.num(v('quantity'));
                    const price = ui.num(v('unit_price'));
                    const net = ui.num(v('net_value'));
                    if (!v('name') || typeof qty !== 'number' || qty <= 0 || (price !== null && typeof price !== 'number') || (net !== null && typeof net !== 'number')) {
                        ui.notify(false, t('inv.line_invalid', {name: v('name') || '?'}));
                        return;
                    }
                    lines.push({name: v('name'), quantity: qty, unit: v('unit') || null, unit_price: price, net_value: net, vat_rate: v('vat_rate') || null});
                }
                iv.editLines = false;
                if (!await run(b, () => api.put(`/supply/invoices/${i.id}/lines`, {lines}), t('saved'))) iv.editLines = true;
                return;
            }
            if (act === 'receive') {
                if (!window.confirm(i.order ? t('inv.confirm_receive', {code: i.order.code}) : t('inv.confirm_stock', {supplier: i.supplier ? i.supplier.name : ''}))) return;
                return run(b, () => api.post(`/supply/invoices/${i.id}/receive`, {}), t(i.order ? 'received_ok' : 'inv.stock_ok'));
            }
            if (act === 'prices') {
                const n = new Set(i.lines.filter(l => l.material).map(l => l.material.id)).size;
                if (!window.confirm(t('inv.confirm_prices', {n}))) return;
                b.disabled = true;
                try {
                    const res = await api.post(`/supply/invoices/${i.id}/prices`, {});
                    ui.notify(true, t('inv.prices_ok', {n: res.imported_prices.length}) + (res.skipped.length ? ' ' + t('inv.prices_skipped', {list: res.skipped.join(', ')}) : ''));
                    await showInvoice(res);
                } catch (err) { ui.notify(false, errText(err)); b.disabled = false; }
                return;
            }
            if (act === 'accept') {
                if (i.control.state !== 'clean' && !window.confirm(t('inv.confirm_accept_gaps'))) return;
                return run(b, () => api.post(`/supply/invoices/${i.id}/decision`, {status: 'accepted'}), t('inv.accepted_ok'));
            }
            if (act === 'dispute') {
                const note = window.prompt(t('inv.dispute_prompt'), i.note || disputeNote(i));
                if (note === null) return;
                return run(b, () => api.post(`/supply/invoices/${i.id}/decision`, {status: 'disputed', note: note.trim().slice(0, 1000) || null}), t('inv.disputed_ok'));
            }
            if (act === 'reopen') return run(b, () => api.post(`/supply/invoices/${i.id}/decision`, {status: 'open'}), t('inv.reopened_ok'));
            if (act === 'delete') {
                if (!window.confirm(t('inv.confirm_delete', {number: i.number || t('inv.no_number')}))) return;
                b.disabled = true;
                try { await api.del(`/supply/invoices/${i.id}`); ui.notify(true, t('deleted')); state.inv = null; resetInvoice(); load(); } catch (err) { ui.notify(false, errText(err)); b.disabled = false; }
            }
        }));
    }

    // Treść reklamacji : linie z rozbieżnościami (ilość, cena) — do poprawienia przed zapisem.
    function disputeNote(i) {
        return i.lines.filter(l => l.control.flags.some(f => BAD_FLAGS.includes(f))).map(l => {
            const k = l.control;
            const u = l.material ? {unit: l.material.unit, factor: l.material.factor_display} : null;
            const parts = [];
            if (k.flags.includes('not_in_order')) parts.push(t('inv.flag.not_in_order'));
            if (u && k.qty_gap_pct && k.flags.some(f => f.startsWith('qty_over'))) parts.push(t('inv.note_qty', {invoiced: q(u, k.quantity_base), ref: q(u, k.received > 0 ? k.received : k.ordered)}));
            if (u && k.flags.includes('price_over')) parts.push(t('inv.note_price', {price: unitPrice(u, k.price_base), order: unitPrice(u, k.order_price)}));
            return `${l.name} : ${parts.join(', ')}`;
        }).join(' ; ');
    }

    async function toggleFile(btn) {
        if (iv.file) { iv.showFile = !iv.showFile; draw(); return; }
        btn.disabled = true;
        try {
            const f = await api.get(`/supply/invoices/${data.invoice.id}/file`);
            const bin = atob(f.content_base64);
            const bytes = new Uint8Array(bin.length);
            for (let k = 0; k < bin.length; k++) bytes[k] = bin.charCodeAt(k);
            iv.file = {url: URL.createObjectURL(new Blob([bytes], {type: f.mime})), name: f.filename};
            iv.showFile = true;
            draw();
            el.querySelector('.tfb-inv-viewer')?.scrollIntoView({block: 'start', behavior: 'smooth'});
        } catch (err) { ui.notify(false, errText(err)); btn.disabled = false; }
    }

    // Lista surowców (do wyboru spoza podpowiedzi) — raz, przy pierwszym formularzu przypisania.
    async function ensureMaterials() {
        if (data.materials) return;
        try { data.materials = await api.get('/supply/materials'); } catch (err) { ui.notify(false, errText(err)); }
    }

    function drawMappings() {
        const d = data.mappings;
        const s = state.search.toLowerCase();
        const rows = d.mappings.filter(m => !s || [m.supplier, m.supplier_name, m.material].some(x => (x || '').toLowerCase().includes(s)));
        return `<p><a href="#tab=invoices" data-back-inv>‹ ${esc(t('inv.back'))}</a></p>
            <div class="card tfb-supply-bar"><div class="card-body d-flex flex-wrap gap-2 align-items-center">
                <span class="small text-muted">${esc(t('inv.mappings_help'))}</span>
                <input class="form-control form-control-sm ms-auto" style="max-width:220px" data-search value="${esc(state.search)}" placeholder="${esc(t('search'))}"></div></div>
            <div class="card"><div class="card-body pt-3"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
                <thead><tr><th>${esc(t('col.supplier'))}</th><th>${esc(t('inv.col.supplier_name'))}</th><th>${esc(t('inv.col.material'))}</th><th>${esc(t('inv.factor'))}</th><th>${esc(t('inv.col.updated'))}</th><th></th></tr></thead>
                <tbody>${rows.map(m => {
                    const u = disp(m.base_unit);
                    return `<tr data-mapping="${m.id}"><td>${esc(m.supplier || (m.supplier_key.startsWith('nip:') ? 'NIP ' + m.supplier_key.slice(4) : '—'))}</td>
                        <td>${esc(m.supplier_name)}${m.supplier_unit ? ` <span class="small text-muted">(${esc(m.supplier_unit)})</span>` : ''}</td><td><strong>${esc(m.material || '—')}</strong></td>
                        <td class="text-nowrap">${esc(t('inv.factor_text', {unit: m.supplier_unit || 'szt', n: n(m.factor / u.factor, 4), our: u.unit}))}</td>
                        <td class="small text-muted">${esc(fullDay((m.updated_at || '').slice(0, 10)))}</td>
                        <td class="text-end">${d.can_write ? `<button type="button" class="btn btn-sm btn-outline-danger" data-del-mapping="${m.id}" title="${esc(t('inv.unmap'))}"><i class="bi bi-trash"></i></button>` : ''}</td></tr>`;
                }).join('') || `<tr><td colspan="6" class="text-muted">${esc(t('inv.mappings_empty'))}</td></tr>`}</tbody></table></div></div></div>`;
    }

    function bindMappings() {
        el.querySelector('[data-back-inv]').addEventListener('click', (e) => { e.preventDefault(); state.inv = null; load(); });
        bindSearch();
        el.querySelectorAll('[data-del-mapping]').forEach(b => b.addEventListener('click', async () => {
            const m = data.mappings.mappings.find(x => x.id === Number(b.dataset.delMapping));
            if (!window.confirm(t('inv.confirm_unmap', {name: m.supplier_name, material: m.material || ''}))) return;
            b.disabled = true;
            try {
                await api.del(`/supply/mappings/${m.id}`);
                data.mappings.mappings = data.mappings.mappings.filter(x => x.id !== m.id);
                ui.notify(true, t('deleted'));
                draw();
            } catch (err) { ui.notify(false, errText(err)); b.disabled = false; }
        }));
    }

    el.addEventListener('click', async (e) => {
        if (!e.target.closest('[data-lang-switch]')) return;
        await api.setLang(api.lang === 'pl' ? 'fr' : 'pl');
        load();
    });
    load();
})();
