/*
 * Zaopatrzenie / Approvisionnement : une fois la production générée — besoins en matières et emballages des
 * semaines (feuille de production × recettes par carton), stock (inventaire + réceptions − production), mode
 * « en stock » (min. / max.) ou « à la commande », commandes fournisseurs par e-mail (ou passées autrement),
 * réceptions avec quantités et prix réels. Tout passe par l'API /supply/… ; les quantités de l'API sont en unité
 * de base (g, ml, szt), affichées en unité lisible (kg, l) avec factor. Onglet Factures : contrôle des factures
 * d'achat (KSeF, XML, PDF, scan ou photo lus par OCR dans le navigateur) contre les commandes et réceptions, noms du
 * fournisseur reliés à nos matières — en boutons rapides : « Pobierz nowe z KSeF », « Przypisz wszystkie » (seulement les
 * lignes inconnues), « Zatwierdź fakturę » (prix + stock + acceptation), « Zatwierdź gotowe ». « Kreator » [217] : toutes les
 * factures ouvertes dans un tableau, lignes vérifiées l'une après l'autre (invoice-wizard.js). Onglet Ceny i dostawcy :
 * historique des prix au kg par matière et par fournisseur (price-history.js).
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
    const TABS = ['needs', 'orders', 'invoices', 'prices', 'stock'];
    const PMONTHS = [3, 6, 12, 24, 36];
    const STATUSES = ['open', 'draft', 'sent', 'partial', 'received', 'cancelled', 'all'];
    const iso = (d) => d.toISOString().slice(0, 10);
    const todayIso = iso(new Date());
    const addDays = (s, k) => { const d = new Date(s + 'T12:00:00'); d.setDate(d.getDate() + k); return iso(d); };
    const day = (s) => s ? `${s.slice(8, 10)}.${s.slice(5, 7)}` : '—';
    const errText = (err) => ((err.body && err.body.errors) || []).map(x => x.message).join(' ') || err.message;
    // [210] Podwyżka ceny bez decyzji : okno « nowa cena bazowa ? » (invoice-price-up.js) zamiast samego komunikatu.
    const priceUp = (invoiceId, o = {}) => window.TfbInvoicePriceUp.open({api, invoiceId, ...o});
    const isPriceUp = (err) => window.TfbInvoicePriceUp.isPending(err);
    // Ilość w jednostce wyświetlanej (kg dla g) : baza ÷ factor ; pole edycji tak samo.
    const q = (r, v, d) => v === null || v === undefined ? '—' : `${n(v / r.factor, d ?? (r.factor > 1 ? 2 : 0))} ${r.unit}`;
    const toInput = (r, v) => v === null || v === undefined ? '' : String(Math.round(v / r.factor * 1000) / 1000).replace('.', ',');
    const money = (v) => v === null || v === undefined ? '—' : `${n(v, 2)} zł`;
    const unitPrice = (r, p) => p === null || p === undefined ? '—' : `${n(p * r.factor, 2)} zł/${r.unit}`;
    const badge = (status) => `<span class="badge tfb-po-${status}">${esc(t('status.' + status))}</span>`;

    let state = readHash();
    const data = {needs: null, orders: null, materials: null, order: null, preview: null, invoices: null, invoice: null, mappings: null, rules: null, prices: null};
    let receiving = false;

    function readHash() {
        const p = new URLSearchParams(location.hash.replace(/^#/, ''));
        return {
            tab: TABS.includes(p.get('tab')) ? p.get('tab') : 'needs',
            date: /^\d{4}-\d{2}-\d{2}$/.test(p.get('date') || '') ? p.get('date') : addDays(todayIso, 7),
            weeks: Math.min(4, Math.max(1, Number(p.get('weeks')) || 1)),
            status: STATUSES.includes(p.get('status')) ? p.get('status') : 'open',
            order: Number(p.get('order')) || null,
            receive: p.get('receive') === '1',
            kind: ['all', 'raw', 'pack'].includes(p.get('kind')) ? p.get('kind') : 'all',
            only: p.get('only') !== '0',
            // inv : map (przypisania), rules (reguły), wizard (kreator [217] : wszystkie pozycje, invoice-wizard.js) albo id faktury.
            inv: ['map', 'rules', 'wizard'].includes(p.get('inv')) ? p.get('inv') : Number(p.get('inv')) || null,
            istatus: ['open', 'accepted', 'disputed', 'all'].includes(p.get('ist')) ? p.get('ist') : 'open',
            // Rodzaj faktur : surowce i opakowania (goods), usługi → Finanse (service), odrzucone (rejected).
            ikind: ['goods', 'service', 'rejected'].includes(p.get('ik')) ? p.get('ik') : 'goods',
            pmonths: PMONTHS.includes(Number(p.get('pm'))) ? Number(p.get('pm')) : 12,
            // #tab=stock&inventory=1 : arkusz inwentaryzacji od razu (kreator « Zamknięcie miesiąca », krok 5).
            search: '', stockMode: 'all', used: true, inventory: p.get('tab') === 'stock' && p.get('inventory') === '1',
            // #…&inv=ID&pu=1 : okno nowej ceny od razu (Do obsłużenia › « Zatwierdź ceny »).
            pu: p.get('pu') === '1',
        };
    }
    function writeHash() {
        const p = new URLSearchParams({tab: state.tab, date: state.date, weeks: state.weeks, status: state.status, kind: state.kind, only: state.only ? '1' : '0'});
        if (state.order) p.set('order', state.order);
        if (state.tab === 'invoices') { p.set('ist', state.istatus); if (state.ikind !== 'goods') p.set('ik', state.ikind); if (state.inv) p.set('inv', state.inv); }
        if (state.tab === 'prices') p.set('pm', state.pmonths);
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
            // Z « Dostawy surowców » (przycisk Przyjmij) : zamówienie otwiera się od razu w asystencie przyjęcia.
            if (state.receive && data.order) { receiving = !!data.order.can_receive; state.receive = false; }
            if (state.tab === 'stock') data.materials = await api.get('/supply/materials');
            if (state.tab === 'prices') data.prices = await api.get(`/supply/prices?months=${state.pmonths}`);
            if (state.tab === 'invoices') {
                if (state.inv === 'wizard') { /* kreator wczytuje się sam */ }
                else if (state.inv === 'map') data.mappings = await api.get('/supply/mappings');
                else if (state.inv === 'rules') data.rules = await api.get('/supply/supplier-rules');
                else if (state.inv) {
                    data.invoice = await api.get(`/supply/invoices/${state.inv}`);
                    if (data.invoice.editable && data.invoice.lines.some(l => !l.material)) await ensureMaterials();
                } else data.invoices = await api.get(`/supply/invoices?status=${state.ikind === 'goods' ? state.istatus : 'all'}&kind=${state.ikind}`);
            }
        } catch (err) {
            if (err.status === 404 && (state.order || state.inv)) { state.order = null; state.inv = null; return load(); }
            el.innerHTML = shell(`<div class="alert alert-danger">${esc(errText(err))}</div>`);
            bindShell();
            return;
        }
        draw();
        if (state.tab === 'invoices' && typeof state.inv === 'number') autoPriceUp(data.invoice);
    }

    function draw() {
        const y = window.scrollY;
        const invoices = () => state.inv === 'wizard' ? '<div data-inv-wizard></div>' : state.inv === 'map' ? drawMappings() : state.inv === 'rules' ? drawRules() : state.inv && data.invoice ? drawInvoice() : drawInvoices();
        const body = state.tab === 'needs' ? drawNeeds() : state.tab === 'stock' ? drawStock() : state.tab === 'prices' ? drawPrices() : state.tab === 'invoices' ? invoices()
            : (state.order && data.order ? drawOrder() : data.batch ? drawBatch() : drawOrders());
        el.innerHTML = shell(body);
        bindShell();
        if (state.tab === 'needs') bindNeeds();
        else if (state.tab === 'stock') bindStock();
        else if (state.tab === 'prices') bindPrices();
        else if (state.tab === 'invoices' && state.inv === 'wizard') {
            // Kreator [217] : własny stan i API ; « Lista faktur » wraca do listy (bez kroku kreatora w adresie).
            window.TfbInvoiceWizard.mount(el.querySelector('[data-inv-wizard]'), {api, back: () => { state.inv = null; history.replaceState(null, '', location.hash.replace(/&ws=\d/, '')); load(); }});
        }
        else if (state.tab === 'invoices') (state.inv === 'map' ? bindMappings : state.inv === 'rules' ? bindRules : state.inv && data.invoice ? bindInvoice : bindInvoices)();
        else if (state.order && data.order) bindOrder();
        else if (data.batch) bindBatch();
        else bindOrders();
        window.scrollTo(0, y);
    }

    function bindShell() {
        el.querySelectorAll('[data-tab]').forEach(a => a.addEventListener('click', (e) => {
            e.preventDefault();
            state.tab = a.dataset.tab;
            state.order = null;
            state.inv = null;
            data.batch = null;
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
            // Kilku dostawców : podsumowanie (e-mail, dzień dostawy, język) i « Wyślij wszystkie » ; jeden : od razu jego zamówienie.
            data.batch = res.orders.length > 1 ? res.orders : null;
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

    // Po « Utwórz wszystkie » : jedno podsumowanie nowych zamówień (adres e-mail, dzień dostawy wg zwykłych dni dostawcy,
    // język dostawcy), potem « Wyślij wszystkie » — każde zamówienie zapisane i wysłane jak pojedynczo ; błąd zostaje w wierszu.
    function drawBatch() {
        const rows = data.batch;
        const wd = (k) => new Date(2024, 0, k, 12).toLocaleDateString(api.lang === 'fr' ? 'fr-FR' : 'pl-PL', {weekday: 'short'});
        const sendable = rows.filter(o => o.status === 'draft');
        return `<div class="card tfb-supply-batch"><div class="card-header"><h5 class="mb-1">${esc(t('batch.title', {n: rows.length}))}</h5><p class="small text-muted mb-0">${esc(t('batch.help'))}</p></div>
            <div class="card-body pt-2"><div class="table-responsive"><table class="table table-sm align-middle mb-2">
                <thead><tr><th>${esc(t('col.supplier'))}</th><th class="text-end">${esc(t('col.lines'))}</th><th class="text-end">${esc(t('col.ordered'))}</th><th>${esc(t('batch.email'))}</th>
                    <th>${esc(t('col.delivery'))}</th><th>${esc(t('batch.language'))}</th><th>${esc(t('col.status'))}</th></tr></thead>
                <tbody>${rows.map(o => `<tr data-batch="${o.id}">
                    <td><a href="#tab=orders&order=${o.id}" data-batch-open="${o.id}"><strong>${esc(o.supplier.name)}</strong></a><div class="small text-muted">${esc(o.code)}</div></td>
                    <td class="text-end">${o.lines.length}</td><td class="text-end">${esc(money(o.value_ordered))}</td>
                    <td>${o.status === 'draft' ? `<input type="email" class="form-control form-control-sm" data-bemail value="${esc(o.email_to || '')}" placeholder="${esc(t('batch.no_email'))}" maxlength="255">` : esc(o.email_to || '—')}</td>
                    <td>${o.status === 'draft' ? `<input type="date" class="form-control form-control-sm" data-bdate value="${esc(o.delivery_date || '')}">` : esc(day(o.delivery_date))}
                        <div class="small text-muted">${o.usual_days && o.usual_days.length ? esc(t('batch.usual', {days: o.usual_days.map(wd).join(', ')})) : esc(t('batch.usual_unknown'))}</div></td>
                    <td>${o.status === 'draft' ? `<select class="form-select form-select-sm" data-blang>${['pl', 'fr'].map(l => `<option value="${l}" ${o.language === l ? 'selected' : ''}>${esc(t('lang.' + l))}</option>`).join('')}</select>` : esc(t('lang.' + o.language))}</td>
                    <td data-bstate>${badge(o.status)}${o.batch_error ? `<div class="small text-danger">${esc(o.batch_error)}</div>` : ''}</td></tr>`).join('')}</tbody></table></div>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    ${sendable.length ? `<button type="button" class="btn btn-primary" data-batch-send><i class="bi bi-send"></i> ${esc(t('batch.send_all', {n: sendable.length}))}</button>
                    <button type="button" class="btn btn-outline-secondary" data-batch-save>${esc(t('batch.save_only'))}</button>` : ''}
                    <button type="button" class="btn btn-link ms-auto" data-batch-close>${esc(t('batch.to_list'))}</button></div></div></div>`;
    }

    function bindBatch() {
        const values = (tr) => ({email_to: tr.querySelector('[data-bemail]').value.trim() || null, delivery_date: tr.querySelector('[data-bdate]').value || null, language: tr.querySelector('[data-blang]').value});
        const close = () => { data.batch = null; data.orders = null; load(); };
        el.querySelector('[data-batch-close]').addEventListener('click', close);
        el.querySelectorAll('[data-batch-open]').forEach(a => a.addEventListener('click', (e) => { e.preventDefault(); state.order = Number(a.dataset.batchOpen); data.batch = null; load(); }));
        // Zapis jednego zamówienia (e-mail zapamiętany u dostawcy, gdy go nie miał) ; send = potem wysyłka mailem.
        const one = async (o, tr, send) => {
            const v = values(tr);
            try {
                if (send && !v.email_to) throw new Error(t('batch.missing_email'));
                const body = {...v, save_supplier_email: !!v.email_to && !o.supplier_email};
                let res = await api.patch(`/supply/orders/${o.id}`, body);
                if (send) res = await api.post(`/supply/orders/${o.id}/send`, {});
                Object.assign(o, res, {batch_error: null});
                return true;
            } catch (err) {
                Object.assign(o, v, {batch_error: err.status ? errText(err) : err.message});
                return false;
            }
        };
        const run = async (btn, send) => {
            const drafts = data.batch.filter(o => o.status === 'draft');
            if (send) {
                const noMail = drafts.filter(o => !values(el.querySelector(`tr[data-batch="${o.id}"]`)).email_to);
                if (noMail.length) { ui.notify(false, t('batch.missing_emails', {list: noMail.map(o => o.supplier.name).join(', ')})); return; }
                if (!window.confirm(t('batch.confirm', {n: drafts.length}))) return;
            }
            el.querySelectorAll('[data-batch-send], [data-batch-save]').forEach(b => { b.disabled = true; });
            let ok = 0;
            for (const o of drafts) if (await one(o, el.querySelector(`tr[data-batch="${o.id}"]`), send)) ok++;
            ui.notify(ok === drafts.length, t(send ? 'batch.sent' : 'batch.saved', {ok, n: drafts.length}));
            data.orders = null;
            draw();
        };
        el.querySelector('[data-batch-send]')?.addEventListener('click', (e) => run(e.currentTarget, true));
        el.querySelector('[data-batch-save]')?.addEventListener('click', (e) => run(e.currentTarget, false));
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
        // Asystent « Przyjęcie dostawy » (audyt 2, P12) : 1 Dostawa → 2 Ilości (zamówione) → 3 Partie i daty ważności →
        // 4 Problem ? (niezgodność dostawcy wypełniona) → 5 Zatwierdź. Kroki przełączane w przeglądarce, zapis na końcu.
        const rsteps = ['rs.delivery', 'rs.qty', 'rs.lots', 'rs.problem', 'rs.confirm'];
        const receive = receiving && o.can_receive ? `<div class="card tfb-supply-receive" data-receive>
                <div class="card-header"><h5 class="mb-2">${esc(t('receive_title'))} · ${esc(o.code)}</h5>
                    <ol class="at-steps at-steps-compact mb-0">${rsteps.map((k, i) => `<li data-rtab="${i}" class="${i === 0 ? 'is-active' : ''}"><span>${esc(t(k))}</span></li>`).join('')}</ol></div>
                <div class="card-body pt-2">
                <section data-rstep="0"><p class="text-muted mb-2">${esc(t('rs.delivery_help', {supplier: o.supplier.name}))}</p>
                    <label class="form-label">${esc(t('rs.date'))} <input class="form-control form-control-sm d-inline-block ms-2" type="date" data-received-on value="${todayIso}" max="${todayIso}" style="width:auto"></label>
                    <ul class="small mb-0">${o.lines.filter(l => l.remaining > 0).map(l => `<li>${esc(l.name)} — ${esc(q(l, l.remaining))}</li>`).join('') || `<li>${esc(t('rs.nothing_left'))}</li>`}</ul></section>
                <section data-rstep="1" hidden><table class="table table-sm align-middle mb-2"><thead><tr><th>${esc(t('col.material'))}</th><th class="text-end">${esc(t('col.remaining'))}</th><th>${esc(t('col.received_now'))}</th><th>${esc(t('col.real_price'))}</th></tr></thead>
                <tbody>${o.lines.map(l => `<tr data-rline="${l.id}"><td>${esc(l.name)}</td><td class="text-end">${esc(q(l, l.remaining))}</td>
                    <td><div class="input-group input-group-sm tfb-supply-qty"><input class="form-control text-end" inputmode="decimal" data-rqty value="${esc(toInput(l, l.remaining))}"><span class="input-group-text">${esc(l.unit)}</span></div></td>
                    <td><div class="input-group input-group-sm tfb-supply-qty"><input class="form-control text-end" inputmode="decimal" data-rprice value="${l.unit_price === null ? '' : esc(String(Math.round(l.unit_price * l.factor * 10000) / 10000).replace('.', ','))}"><span class="input-group-text">zł/${esc(l.unit)}</span></div></td></tr>`).join('')}</tbody></table></section>
                <section data-rstep="2" hidden><p class="text-muted mb-2">${esc(t('rs.lots_help'))}</p><table class="table table-sm align-middle mb-2"><thead><tr><th>${esc(t('col.material'))}</th><th>${esc(t('rs.lot'))}</th><th>${esc(t('rs.best_before'))}</th></tr></thead>
                <tbody>${o.lines.map(l => `<tr data-lotline="${l.id}"><td>${esc(l.name)}</td><td><input class="form-control form-control-sm" data-rlot maxlength="64" autocapitalize="characters"></td>
                    <td><input class="form-control form-control-sm" type="date" data-rbb min="${todayIso}"></td></tr>`).join('')}</tbody></table></section>
                <section data-rstep="3" hidden><p class="text-muted mb-2">${esc(t('rs.problem_help'))}</p>
                    ${o.lines.map(l => `<div class="tfb-supply-problem" data-pline="${l.id}"><label class="form-check"><input class="form-check-input" type="checkbox" data-pon> <span class="form-check-label">${esc(l.name)}</span></label>
                        <div class="row g-2 mb-2" data-pfields hidden><div class="col-12 col-md-6"><input class="form-control form-control-sm" data-pdesc maxlength="5000" placeholder="${esc(t('rs.problem_desc'))}"></div>
                        <div class="col-6 col-md-3"><select class="form-select form-select-sm" data-psev><option value="minor">${esc(t('rs.sev.minor'))}</option><option value="major">${esc(t('rs.sev.major'))}</option><option value="critical">${esc(t('rs.sev.critical'))}</option></select></div>
                        <div class="col-6 col-md-3"><div class="input-group input-group-sm"><input class="form-control text-end" inputmode="decimal" data-pqty placeholder="${esc(t('rs.problem_qty'))}"><span class="input-group-text">${esc(l.unit)}</span></div></div></div></div>`).join('')}</section>
                <section data-rstep="4" hidden><div data-rsummary></div></section>
                <div class="d-flex flex-wrap gap-2 mt-2 align-items-center">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-rback hidden>‹ ${esc(t('rs.back'))}</button>
                    <button type="button" class="btn btn-sm btn-link" data-act="close-receipt">${esc(t('act.close'))}</button>
                    <button type="button" class="btn btn-sm btn-primary ms-auto" data-rnext>${esc(t('rs.next'))} ›</button>
                    <button type="button" class="btn btn-sm btn-success ms-auto" data-act="save-receipt" hidden>${esc(t('act.save_receipt'))}</button></div></div></div>` : '';
        const receipts = o.receipts.length ? `<div class="small text-muted mt-2">${esc(t('receipts'))} : ${o.receipts.map(r => {
            const l = o.lines.find(x => x.id === r.line_id);
            return `${esc(day(r.received_on))} ${esc(l ? l.name : '')} ${esc(l ? q(l, r.quantity) : '')}${r.unit_price !== null && l ? ' @ ' + esc(unitPrice(l, r.unit_price)) : ''}${r.lot_number ? ' · ' + esc(t('rs.lot')) + ' ' + esc(r.lot_number) + (r.best_before ? ' (' + esc(day(r.best_before)) + ')' : '') : ''}${r.nc_id ? ' · <a href="' + esc(api.root) + '/purchasing/nc">NC</a>' : ''}${r.employee ? ' · ' + esc(t('rs.by_tablet', {name: r.employee})) : ''}`;
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
        // Asystent przyjęcia : dane z kroków (ilości, ceny, partie, problemy) ; null = błąd pokazany.
        const receiptBody = () => {
            const box = el.querySelector('[data-receive]');
            if (!box) return null;
            const lines = [];
            for (const tr of box.querySelectorAll('tr[data-rline]')) {
                const l = o.lines.find(x => x.id === Number(tr.dataset.rline));
                const v = ui.num(tr.querySelector('[data-rqty]').value);
                const p = ui.num(tr.querySelector('[data-rprice]').value);
                if (v === null || v === 0) continue;
                if (typeof v !== 'number' || v < 0 || (p !== null && (typeof p !== 'number' || p <= 0))) { ui.notify(false, t('qty_invalid', {list: l.name})); return null; }
                const lt = box.querySelector(`tr[data-lotline="${l.id}"]`);
                lines.push({id: l.id, quantity: Math.round(v * l.factor * 1000) / 1000, unit_price: p === null ? null : p / l.factor,
                    lot_number: lt.querySelector('[data-rlot]').value.trim().replace(/\s+/g, ' ').toUpperCase() || null, best_before: lt.querySelector('[data-rbb]').value || null});
            }
            if (!lines.length) { ui.notify(false, t('nothing_received')); return null; }
            const problems = [];
            for (const pb of box.querySelectorAll('[data-pline]')) {
                if (!pb.querySelector('[data-pon]').checked) continue;
                const l = o.lines.find(x => x.id === Number(pb.dataset.pline));
                const desc = pb.querySelector('[data-pdesc]').value.trim();
                if (!desc) { ui.notify(false, t('rs.problem_missing', {name: l.name})); return null; }
                const pq = ui.num(pb.querySelector('[data-pqty]').value);
                problems.push({line_id: l.id, description: desc, severity: pb.querySelector('[data-psev]').value, quantity: typeof pq === 'number' && pq > 0 ? Math.round(pq * l.factor * 1000) / 1000 : null});
            }
            return {received_on: box.querySelector('[data-received-on]').value || todayIso, lines, problems};
        };
        const bindReceive = () => {
            const box = el.querySelector('[data-receive]');
            if (!box) return;
            let step = 0;
            const last = 4;
            const show = (k) => {
                step = Math.max(0, Math.min(last, k));
                box.querySelectorAll('[data-rstep]').forEach(sct => { sct.hidden = Number(sct.dataset.rstep) !== step; });
                box.querySelectorAll('[data-rtab]').forEach(tb => { const n = Number(tb.dataset.rtab); tb.classList.toggle('is-active', n === step); tb.classList.toggle('is-done', n < step); });
                box.querySelector('[data-rback]').hidden = step === 0;
                box.querySelector('[data-rnext]').hidden = step === last;
                box.querySelector('[data-act="save-receipt"]').hidden = step !== last;
                if (step === last) {
                    const body = receiptBody();
                    if (!body) { show(1); return; }
                    box.querySelector('[data-rsummary]').innerHTML = `<p class="mb-1">${esc(t('rs.summary', {date: day(body.received_on), n: body.lines.length}))}</p>
                        <ul class="small mb-1">${body.lines.map(x => { const l = o.lines.find(y => y.id === x.id); return `<li>${esc(l.name)} : ${esc(q(l, x.quantity))}${x.lot_number ? ' · ' + esc(t('rs.lot')) + ' ' + esc(x.lot_number) : ' · <span class="text-warning">' + esc(t('rs.no_lot')) + '</span>'}${x.best_before ? ' (' + esc(day(x.best_before)) + ')' : ''}</li>`; }).join('')}</ul>
                        ${body.problems.length ? `<p class="text-danger mb-0">${esc(t('rs.summary_nc', {n: body.problems.length}))}</p>` : ''}`;
                }
            };
            box.querySelector('[data-rnext]').addEventListener('click', () => show(step + 1));
            box.querySelector('[data-rback]').addEventListener('click', () => show(step - 1));
            box.querySelectorAll('[data-pon]').forEach(c => c.addEventListener('change', () => { c.closest('[data-pline]').querySelector('[data-pfields]').hidden = !c.checked; }));
        };
        bindReceive();
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
                const body = receiptBody();
                if (!body) return;
                // Asystent zamyka się dopiero po zapisie : przy błędzie wpisane ilości, partie i problemy zostają.
                return run(b, async () => { const res = await api.post(`/supply/orders/${o.id}/receive`, body); receiving = false; return res; }, body.problems.length ? t('rs.received_nc', {n: body.problems.length}) : t('received_ok'));
            }
        }));
    }

    // --- Surowce i stany ----------------------------------------------------------------------------------------------
    const stockRows = () => data.materials.rows.filter(r => kindOk(r) && searchOk(r) && (state.stockMode === 'all' || r.mode === state.stockMode)
        && (!state.used || r.weekly_use > 0 || r.stock !== null || r.on_order > 0 || r.mode === 'stock'));

    // Inwentaryzacja całego magazynu : arkusz do wydruku (kategoria, surowiec, stan w systemie, miejsce na wpis),
    // potem wszystkie policzone ilości wpisane naraz i jeden zapis (pozycje bez wpisu zostają bez zmian).
    function drawInventory() {
        const rows = stockRows();
        const groups = [];
        rows.forEach(r => { const k = r.category || t('inv_sheet.no_category'); let g = groups.find(x => x.k === k); if (!g) groups.push(g = {k, rows: []}); g.rows.push(r); });
        return `<div class="card tfb-supply-bar"><div class="card-body d-flex flex-wrap gap-2 align-items-center">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-inv-back>‹ ${esc(t('inv_sheet.back'))}</button>
                ${kindButtons()}
                <label class="small d-flex align-items-center gap-1 m-0">${esc(t('inv_sheet.date'))} <input type="date" class="form-control form-control-sm" data-inv-date value="${todayIso}" max="${todayIso}" style="width:auto"></label>
                <input class="form-control form-control-sm" style="max-width:220px" data-inv-note maxlength="255" placeholder="${esc(t('inv_sheet.note'))}">
                <button type="button" class="btn btn-sm btn-outline-primary ms-auto" data-inv-print><i class="bi bi-printer"></i> ${esc(t('inv_sheet.print'))}</button>
                <button type="button" class="btn btn-sm btn-primary" data-inv-save ${data.materials.can_write ? '' : 'disabled'}><i class="bi bi-check2-all"></i> ${esc(t('inv_sheet.save'))} <span data-inv-n>(0)</span></button></div></div>
            <p class="small text-muted mb-2">${esc(t('inv_sheet.help', {n: rows.length}))}</p>
            <div class="card"><div class="card-body pt-3"><div class="table-responsive"><table class="table table-sm align-middle mb-0 tfb-supply-table tfb-inv-sheet">
                <thead><tr><th>${esc(t('col.material'))}</th><th class="text-end">${esc(t('inv_sheet.system'))}</th><th>${esc(t('inv_sheet.counted'))}</th><th class="text-end">${esc(t('inv_sheet.diff'))}</th></tr></thead>
                <tbody>${groups.map(g => `<tr class="table-light"><th colspan="4">${esc(g.k)} <span class="text-muted small">(${g.rows.length})</span></th></tr>${g.rows.map(r => `<tr data-inv="${r.id}">
                    <td><strong>${esc(r.name)}</strong>${r.count_date ? `<div class="small text-muted">${esc(t('inv_sheet.last', {date: day(r.count_date)}))}</div>` : ''}</td>
                    <td class="text-end">${r.stock !== null ? esc(q(r, r.stock)) : '—'}</td>
                    <td><div class="input-group input-group-sm tfb-supply-qty"><input class="form-control text-end" inputmode="decimal" data-inv-qty placeholder="—" aria-label="${esc(t('inv_sheet.counted'))} ${esc(r.name)}"><span class="input-group-text">${esc(r.unit)}</span></div></td>
                    <td class="text-end" data-inv-diff>—</td></tr>`).join('')}`).join('')
                    || `<tr><td colspan="4" class="text-muted">${esc(t('empty_materials'))}</td></tr>`}</tbody></table></div></div></div>`;
    }

    function bindInventory() {
        el.querySelectorAll('[data-kind]').forEach(b => b.addEventListener('click', () => { state.kind = b.dataset.kind; writeHash(); draw(); }));
        el.querySelector('[data-inv-back]').addEventListener('click', () => { state.inventory = false; draw(); });
        const rowOf = (tr) => data.materials.rows.find(x => x.id === Number(tr.dataset.inv));
        const filled = () => [...el.querySelectorAll('tr[data-inv]')].filter(tr => tr.querySelector('[data-inv-qty]').value.trim() !== '');
        const refresh = () => {
            filled().forEach(tr => {
                const r = rowOf(tr);
                const v = ui.num(tr.querySelector('[data-inv-qty]').value);
                const cell = tr.querySelector('[data-inv-diff]');
                if (typeof v !== 'number' || v < 0) { cell.innerHTML = `<span class="text-danger">${esc(t('count_invalid'))}</span>`; return; }
                const diff = v * r.factor - (r.stock ?? 0);
                cell.innerHTML = r.stock === null ? '—' : `<span class="${Math.abs(diff) < 1e-6 ? 'text-muted' : (diff < 0 ? 'text-danger' : 'text-success')}">${diff > 0 ? '+' : ''}${esc(q(r, diff))}</span>`;
            });
            el.querySelectorAll('tr[data-inv]').forEach(tr => { if (tr.querySelector('[data-inv-qty]').value.trim() === '') tr.querySelector('[data-inv-diff]').textContent = '—'; });
            el.querySelector('[data-inv-n]').textContent = `(${filled().length})`;
        };
        el.querySelector('.tfb-inv-sheet')?.addEventListener('input', refresh);
        // Enter = następna pozycja (liczenie z arkuszem w ręku).
        el.querySelectorAll('[data-inv-qty]').forEach((input, i, all) => input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); all[i + 1]?.focus(); } }));
        el.querySelector('[data-inv-print]').addEventListener('click', () => {
            const rows = stockRows();
            const w = window.open('', '_blank');
            if (!w) { ui.notify(false, t('inv_sheet.popup')); return; }
            const groups = [];
            rows.forEach(r => { const k = r.category || t('inv_sheet.no_category'); let g = groups.find(x => x.k === k); if (!g) groups.push(g = {k, rows: []}); g.rows.push(r); });
            w.document.write(`<!doctype html><html lang="${api.lang}"><head><meta charset="utf-8"><title>${esc(t('inv_sheet.title'))}</title><style>
                body{font:12px/1.35 Arial,sans-serif;margin:16mm 12mm;color:#222} h1{font-size:17px;color:#8D1D2C;margin:0 0 4px} p{margin:0 0 10px;color:#555}
                table{width:100%;border-collapse:collapse} th,td{border:0.5px solid #bbb;padding:4px 6px;text-align:left} th{background:#8D1D2C;color:#fff}
                tr.g td{background:#F8ECEE;font-weight:bold;color:#8D1D2C} td.n{text-align:right;white-space:nowrap} td.box{width:28%} @page{size:A4;margin:10mm}</style></head><body>
                <h1>${esc(t('inv_sheet.title'))}</h1><p>${esc(t('inv_sheet.print_sub', {date: fullDay(el.querySelector('[data-inv-date]').value || todayIso), n: rows.length}))}</p>
                <table><thead><tr><th>${esc(t('col.material'))}</th><th>${esc(t('inv_sheet.unit'))}</th><th>${esc(t('inv_sheet.system'))}</th><th>${esc(t('inv_sheet.counted'))}</th></tr></thead><tbody>
                ${groups.map(g => `<tr class="g"><td colspan="4">${esc(g.k)}</td></tr>${g.rows.map(r => `<tr><td>${esc(r.name)}</td><td>${esc(r.unit)}</td><td class="n">${r.stock !== null ? esc(q(r, r.stock)) : '—'}</td><td class="box"></td></tr>`).join('')}`).join('')}
                </tbody></table></body></html>`);
            w.document.close();
            w.focus();
            w.print();
        });
        el.querySelector('[data-inv-save]').addEventListener('click', async (e) => {
            const counts = [];
            for (const tr of filled()) {
                const r = rowOf(tr);
                const v = ui.num(tr.querySelector('[data-inv-qty]').value);
                if (typeof v !== 'number' || v < 0) { ui.notify(false, t('qty_invalid', {list: r.name})); tr.querySelector('[data-inv-qty]').focus(); return; }
                counts.push({id: r.id, quantity: Math.round(v * r.factor * 1000) / 1000});
            }
            if (!counts.length) { ui.notify(false, t('inv_sheet.nothing')); return; }
            const date = el.querySelector('[data-inv-date]').value || todayIso;
            if (!window.confirm(t('inv_sheet.confirm', {n: counts.length, date: fullDay(date)}))) return;
            e.currentTarget.disabled = true;
            try {
                const res = await api.post('/supply/materials/counts', {count_date: date, note: el.querySelector('[data-inv-note]').value.trim() || null, counts});
                data.materials = res;
                state.inventory = false;
                ui.notify(true, t('inv_sheet.saved', {n: res.saved}));
                draw();
            } catch (err) { ui.notify(false, errText(err)); e.currentTarget.disabled = false; }
        });
    }

    function drawStock() {
        if (state.inventory && data.materials.can_write) return drawInventory();
        const d = data.materials;
        const editable = d.can_write;
        const rows = stockRows();
        const suggestable = rows.filter(r => r.weekly_use > 0);
        const minmax = (r, field) => `<div class="input-group input-group-sm tfb-supply-qty"><input class="form-control text-end" inputmode="decimal" data-limit="${field}" value="${esc(toInput(r, r[field]))}" placeholder="—" ${editable ? '' : 'disabled'}
            aria-label="${esc(t('col.' + field))}"><span class="input-group-text">${esc(r.unit)}</span></div>`;
        return `<div class="card tfb-supply-bar"><div class="card-body d-flex flex-wrap gap-2 align-items-center">
                ${kindButtons()}
                <div class="btn-group btn-group-sm" role="group">${['all', 'stock', 'order'].map(m => `<button type="button" class="btn ${state.stockMode === m ? 'btn-primary' : 'btn-outline-primary'}" data-smode="${m}">${esc(t('smode.' + m))}</button>`).join('')}</div>
                <label class="form-check form-switch m-0 small"><input class="form-check-input" type="checkbox" data-used ${state.used ? 'checked' : ''}> ${esc(t('only_used'))}</label>
                <input class="form-control form-control-sm ms-auto" style="max-width:220px" data-search value="${esc(state.search)}" placeholder="${esc(t('search'))}">
                ${editable ? `<button type="button" class="btn btn-sm btn-outline-primary" data-inventory><i class="bi bi-clipboard-check"></i> ${esc(t('inv_sheet.open'))}</button>
                <button type="button" class="btn btn-sm btn-outline-primary" data-suggest-all ${suggestable.length ? '' : 'disabled'} title="${esc(t('suggest_all_help'))}"><i class="bi bi-magic"></i> ${esc(t('suggest_all', {n: suggestable.length}))}</button>` : ''}</div></div>
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
        if (state.inventory && data.materials.can_write) return bindInventory();
        el.querySelector('[data-inventory]')?.addEventListener('click', () => { state.inventory = true; draw(); window.scrollTo(0, 0); });
        el.querySelector('[data-suggest-all]')?.addEventListener('click', async (e) => {
            const ids = stockRows().filter(r => r.weekly_use > 0).map(r => r.id);
            if (!ids.length || !window.confirm(t('suggest_all_confirm', {n: ids.length}))) return;
            e.currentTarget.disabled = true;
            try {
                const res = await api.post('/supply/materials/limits', {ids});
                data.materials = res;
                ui.notify(true, t('suggest_all_done', {n: res.updated, skipped: res.skipped}));
                draw();
            } catch (err) { ui.notify(false, errText(err)); e.currentTarget.disabled = false; }
        });
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
        qty_under_received: 'bg-warning text-dark', price_over: 'bg-danger', price_under: 'bg-success', price_jump: 'bg-warning text-dark',
        price_up: 'bg-danger', price_up_accept: 'bg-success', price_up_keep: 'bg-secondary'};
    const BAD_FLAGS = ['not_in_order', 'qty_over_received', 'qty_over_ordered', 'price_over'];
    const disp = (base) => base === 'g' ? {unit: 'kg', factor: 1000} : base === 'ml' ? {unit: 'l', factor: 1000} : {unit: base || 'szt', factor: 1};
    // Jednostki do wyboru w przeliczniku « 1 OP = … » : grupa (masa, objętość, sztuki) i skala względem jednostki bazowej.
    const UNIT_PICK = ['kg', 'g', 'l', 'ml', 'szt'];
    const unitGroup = (u) => ['g', 'kg'].includes(u) ? 'mass' : ['ml', 'l'].includes(u) ? 'volume' : 'count';
    const unitScale = (u, base) => (u === 'kg' || u === 'l' ? 1000 : 1) / (base === 'kg' || base === 'l' ? 1000 : 1);
    const fullDay = (s) => s ? `${s.slice(8, 10)}.${s.slice(5, 7)}.${s.slice(0, 4)}` : '—';
    const cur = (v, c) => v === null || v === undefined ? '—' : `${n(v, 2)} ${!c || c === 'PLN' ? 'zł' : c}`;
    const signed = (v, f) => `${v > 0 ? '+' : ''}${f(v)}`;
    const pct = (v) => signed(v, x => `${n(x, 1)} %`);
    const ctrlBadge = (c) => `<span class="badge ${STATE_CLS[c.state] || 'bg-secondary'}" title="${esc(t('inv.state_help.' + c.state))}">${esc(t('inv.state.' + c.state))}</span>`
        + (c.state === 'gaps' && Math.abs(c.value_gap) >= 0.01 ? ` <span class="small ${c.value_gap > 0 ? 'text-danger' : 'text-success'}">${esc(signed(c.value_gap, money))}</span>` : '');
    const invBadge = (s) => `<span class="badge tfb-inv-${s}">${esc(t('inv.status.' + s))}</span>`;
    const iv = {ksefOpen: false, ksef: null, from: null, to: null, editLines: false, editLine: null, file: null, showFile: false};
    // Historia cen surowca (okno) : wykres i dostawcy, ceny za kg.
    const openHistory = (id, name) => window.TfbPriceHistory && window.TfbPriceHistory.open({title: name, lang: api.lang, months: 24,
        load: (m) => api.get(`/supply/materials/${id}/prices?months=${m}`)});
    // Następny krok faktury (lista) : zatwierdź jednym kliknięciem, przypisz, wybierz dostawcę albo sprawdź.
    function nextStep(i, w) {
        if (i.status !== 'open' || !i.ready) return '';
        const r = i.ready;
        if (r.ok) return w ? `<button type="button" class="btn btn-sm btn-success text-nowrap" data-quick-process="${i.id}"><i class="bi bi-check2-all"></i> ${esc(t('inv.act.process_short'))}</button>` : '';
        if (r.blockers.includes('no_supplier')) return `<span class="badge bg-warning text-dark">${esc(t('inv.next.supplier'))}</span>`;
        if (r.blockers.includes('unmapped')) return `<span class="badge bg-warning text-dark">${esc(t('inv.next.map', {n: r.unmapped}))}</span>`;
        if (r.blockers.includes('price_up')) return w ? `<button type="button" class="btn btn-sm btn-danger text-nowrap" data-quick-pu="${i.id}"><i class="bi bi-graph-up-arrow"></i> ${esc(t('inv.next.price_up', {n: r.price_ups}))}</button>`
            : `<span class="badge bg-danger"><i class="bi bi-graph-up-arrow"></i> ${esc(t('inv.next.price_up', {n: r.price_ups}))}</span>`;
        if (r.blockers.length) return `<span class="badge bg-light text-dark">${esc(t('inv.next.lines'))}</span>`;
        return `<span class="badge bg-warning text-dark" title="${esc(r.warnings.map(x => t('inv.warn.' + x)).join(' · '))}">${esc(t('inv.next.check'))}</span>`;
    }

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
        await autoPriceUp(invoice);
    }
    // #…&pu=1 (Do obsłużenia › « Zatwierdź ceny ») : okno nowej ceny raz, po otwarciu faktury.
    async function autoPriceUp(invoice) {
        if (!state.pu || !invoice) return;
        state.pu = false;
        if (!invoice.editable || !invoice.ready || !invoice.ready.blockers.includes('price_up')) return;
        const res = await priceUp(invoice.id, {next: t('pu.next_after')});
        if (res) await showInvoice(res);
    }

    function drawInvoices() {
        const d = data.invoices;
        const w = d.can_write;
        const ready = d.invoices.filter(i => i.status === 'open' && i.ready && i.ready.ok).length;
        if (!iv.from) { iv.from = addDays(todayIso.slice(0, 8) + '01', -1).slice(0, 8) + '01'; iv.to = todayIso; }
        const ksef = iv.ksefOpen ? `<div class="card tfb-inv-ksef"><div class="card-body">
                <form class="d-flex flex-wrap gap-2 align-items-end" data-ksef-form>
                    <div><label class="form-label small mb-0">${esc(t('inv.from'))}</label><input class="form-control form-control-sm" type="date" name="from" value="${esc(iv.from)}" max="${todayIso}" required></div>
                    <div><label class="form-label small mb-0">${esc(t('inv.to'))}</label><input class="form-control form-control-sm" type="date" name="to" value="${esc(iv.to)}" max="${todayIso}" required></div>
                    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i> ${esc(t('inv.ksef_search'))}</button>
                    <span class="small text-muted">${esc(t('inv.ksef_help'))}</span></form>
                ${iv.ksef ? ksefTable() : ''}</div></div>` : '';
        const k = d.kpi;
        const cnt = d.counts;
        // Przełącznik (makieta 1) : do walidacji / zatwierdzone (reklamacje, gdy są) ; rodzaj : surowce i opakowania, usługi → Finanse, odrzucone.
        const segs = ['open', 'accepted', ...(cnt.goods.disputed || state.istatus === 'disputed' ? ['disputed'] : [])];
        const toggles = `<div class="tfb-inv-toggles">
                ${state.ikind === 'goods' ? `<div class="at-seg" role="group" aria-label="${esc(t('inv.toggle'))}">${segs.map(s => `<button type="button" class="${state.istatus === s ? 'is-on' : ''}" data-istatus="${s}" aria-pressed="${state.istatus === s}">
                    ${esc(t('inv.seg.' + s))} <span class="tfb-num">${cnt.goods[s]}</span></button>`).join('')}</div>` : ''}
                <div class="tfb-inv-kinds" role="group" aria-label="${esc(t('inv.kinds'))}">${['goods', 'service', 'rejected'].map(x => `<button type="button" class="tfb-inv-kind ${state.ikind === x ? 'is-on' : ''}" data-ikind="${x}" aria-pressed="${state.ikind === x}">
                    <i class="bi ${{goods: 'bi-basket', service: 'bi-arrow-right-square', rejected: 'bi-slash-circle'}[x]}"></i> ${esc(t('inv.kind.' + x))}${x === 'goods' ? '' : ` <span class="tfb-num">${x === 'service' ? cnt.service.open + cnt.service.accepted : cnt.rejected}</span>`}</button>`).join('')}</div>
                <a href="#tab=invoices&inv=rules" class="btn btn-sm btn-link ms-auto" data-rules><i class="bi bi-funnel"></i> ${esc(t('inv.rules', {n: cnt.rules}))}</a></div>`;
        const tiles = `<div class="at-tiles tfb-inv-tiles">
                <div class="at-tile ${k.open ? 'at-tile-warn' : 'at-tile-ok'}"><span class="at-tile-label">${esc(t('inv.kpi.open'))}</span><span class="at-tile-value tfb-num">${k.open}</span><span class="at-tile-meta">${esc(cur(k.open_net))}</span></div>
                <div class="at-tile ${k.ready ? 'at-tile-ok' : ''}"><span class="at-tile-label">${esc(t('inv.kpi.ready'))}</span><span class="at-tile-value tfb-num">${k.ready}</span><span class="at-tile-meta">${esc(t('inv.kpi.ready_help'))}</span></div>
                <div class="at-tile ${k.price_ups ? 'at-tile-danger' : ''}"><span class="at-tile-label">${esc(t('inv.kpi.price_ups'))}</span><span class="at-tile-value tfb-num">${k.price_ups}</span><span class="at-tile-meta">${esc(t('inv.kpi.price_ups_help'))}</span></div>
                <a class="at-tile ${k.services_suspected ? 'at-tile-warn' : ''}" href="${d.can_finance ? api.root + '/finances/costs' : '#tab=invoices&ik=service'}" ${d.can_finance ? '' : 'data-ikind-link="service"'}>
                    <span class="at-tile-label">${esc(t('inv.kpi.services'))}</span><span class="at-tile-value tfb-num">${k.services_month_count}</span>
                    <span class="at-tile-meta">${esc(k.services_suspected ? t('inv.kpi.suspected', {n: k.services_suspected}) : t('inv.kpi.services_help', {n: k.services_open}))}</span></a></div>`;
        if (state.ikind !== 'goods') return toggles + tiles + otherInvoices(d);
        return toggles + tiles + `<div class="card tfb-supply-bar"><div class="card-body d-flex flex-wrap gap-2 align-items-center">
                <a href="#tab=invoices&inv=wizard" class="btn btn-sm btn-primary" data-wizard title="${esc(t('kw.open_help'))}"><i class="bi bi-list-check"></i> ${esc(t('kw.open'))}${k.open ? ` <span class="badge bg-light text-dark">${k.open}</span>` : ''}</a>
                ${w ? `<button type="button" class="btn btn-sm btn-outline-primary" data-ksef-sync title="${esc(t('inv.ksef_sync_help'))}"><i class="bi bi-cloud-download"></i> ${esc(t('inv.ksef_sync'))}</button>
                <button type="button" class="btn btn-sm ${iv.ksefOpen ? 'btn-secondary' : 'btn-outline-primary'}" data-ksef-toggle><i class="bi bi-calendar-range"></i> ${esc(t('inv.from_ksef'))}</button>
                <label class="btn btn-sm btn-outline-primary mb-0"><i class="bi bi-upload"></i> ${esc(t('inv.upload'))}<input type="file" class="d-none" accept=".pdf,.xml,application/pdf,application/xml,text/xml,image/*" data-upload></label>
                <label class="btn btn-sm btn-outline-primary mb-0"><i class="bi bi-camera"></i> ${esc(t('inv.photo'))}<input type="file" class="d-none" accept="image/*" capture="environment" data-upload></label>
                <span class="small text-muted" data-ocr-status></span>
                ${ready ? `<button type="button" class="btn btn-sm btn-success" data-process-ready><i class="bi bi-check2-all"></i> ${esc(t('inv.act.process_ready', {n: ready}))}</button>` : ''}` : ''}
                <a class="btn btn-sm btn-outline-secondary ms-auto" href="${api.root}/settings/ksef/purchase-invoices"><i class="bi bi-eye"></i> ${esc(t('inv.ksef_viewer'))}</a>
                <a href="#tab=invoices&inv=map" class="btn btn-sm btn-link ms-auto" data-mappings><i class="bi bi-arrow-left-right"></i> ${esc(t('inv.mappings'))}</a></div></div>
            ${ksef}
            <p class="small text-muted mb-2">${esc(t('inv.help'))} ${esc(d.auto && d.auto.prices ? t(d.auto.full ? 'inv.auto_full' : 'inv.auto_prices', {pct: n(d.auto.price_alert_pct, 0)}) : t('inv.auto_off'))}
                <a href="${api.root}/other-settings#params">${esc(t('inv.auto_settings'))}</a></p>
            <div class="card"><div class="card-body pt-3"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
                <thead><tr><th>${esc(t('inv.col.number'))}</th><th>${esc(t('col.supplier'))}</th><th>${esc(t('inv.col.date'))}</th><th class="text-end">${esc(t('inv.col.net'))}</th>
                    <th>${esc(t('inv.col.order'))}</th><th>${esc(t('inv.col.control'))}</th><th>${esc(t('col.status'))}</th><th>${esc(t('inv.col.next'))}</th></tr></thead>
                <tbody>${d.invoices.map(i => `<tr data-open-inv="${i.id}" class="tfb-supply-click">
                    <td><a href="#tab=invoices&inv=${i.id}"><strong>${esc(i.number || t('inv.no_number'))}</strong></a><div class="small text-muted">${esc(t('inv.source.' + i.source))}</div></td>
                    <td>${i.supplier ? esc(i.supplier.name) : `${esc(i.seller_name || '—')}<div class="small text-warning">${esc(t('inv.no_supplier'))}</div>`}</td>
                    <td class="text-nowrap">${esc(fullDay(i.issue_date))}</td><td class="text-end text-nowrap tfb-num">${esc(cur(i.total_net, i.currency))}</td>
                    <td>${i.order ? esc(i.order.code) : '—'}</td>
                    <td>${ctrlBadge(i.control)}${i.control.lines ? `<div class="small text-muted">${esc(t('inv.mapped_n', {mapped: i.control.mapped, lines: i.control.lines}))}</div>` : ''}</td>
                    <td>${invBadge(i.status)}</td><td>${i.service_guess && w ? serviceHint(i) : nextStep(i, w)}</td></tr>`).join('')
                    || `<tr><td colspan="8" class="text-muted">${esc(t('inv.empty'))}</td></tr>`}</tbody></table></div></div></div>`;
    }

    // Faktura, która wygląda na usługę (Orlen → paliwo…) : podpowiedź i dwa przyciski — okno « co zrobić z tą fakturą ? ».
    function serviceHint(i) {
        return `<div class="tfb-inv-guess"><span class="small text-muted text-nowrap">${esc(t('inv.guess', {label: i.service_guess.label}))}</span>
            <span class="d-flex gap-1"><button type="button" class="btn btn-sm btn-outline-primary text-nowrap" data-classify="${i.id}" data-action="service"><i class="bi bi-arrow-right-square"></i> ${esc(t('inv.to_finance'))}</button>
            <button type="button" class="btn btn-sm btn-outline-secondary text-nowrap" data-classify="${i.id}" data-action="reject">${esc(t('inv.reject'))}</button></span></div>`;
    }

    // Usługi (w Finansach) i odrzucone : kategoria, miesiąc, kwota, powód ; z powrotem do zaopatrzenia.
    function otherInvoices(d) {
        const service = state.ikind === 'service';
        const w = d.can_write;
        const head = service ? `<th>${esc(t('inv.col.category'))}</th><th>${esc(t('inv.col.month'))}</th><th>${esc(t('col.status'))}</th>` : `<th>${esc(t('inv.col.reason'))}</th>`;
        return `<p class="small text-muted mb-2">${esc(t(service ? 'inv.service_help' : 'inv.rejected_help'))}
                ${service && d.can_finance ? `<a href="${api.root}/finances/costs">${esc(t('inv.open_finance'))}</a>` : ''}</p>
            <div class="card"><div class="card-body pt-3"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
                <thead><tr><th>${esc(t('inv.col.number'))}</th><th>${esc(t('inv.col.seller'))}</th><th>${esc(t('inv.col.date'))}</th><th class="text-end">${esc(t('inv.col.net'))}</th>${head}<th></th></tr></thead>
                <tbody>${d.invoices.map(i => `<tr data-open-inv="${i.id}" class="tfb-supply-click">
                    <td><a href="#tab=invoices&inv=${i.id}"><strong>${esc(i.number || t('inv.no_number'))}</strong></a><div class="small text-muted">${esc(t('inv.source.' + i.source))}</div></td>
                    <td>${esc(i.supplier ? i.supplier.name : (i.seller_name || '—'))}${i.seller_nip ? `<div class="small text-muted">NIP ${esc(i.seller_nip)}</div>` : ''}</td>
                    <td class="text-nowrap">${esc(fullDay(i.issue_date))}</td><td class="text-end tfb-num">${esc(cur(i.total_net, i.currency))}</td>
                    ${service ? `<td>${esc(i.category ? i.category.label : '—')}${i.by_rule ? ` <span class="badge tfb-inv-rule" title="${esc(t('inv.by_rule_help'))}">${esc(t('inv.by_rule'))}</span>` : ''}</td>
                        <td class="text-nowrap">${esc(monthLabel(i.service_month))}</td><td>${invBadge(i.status === 'open' ? 'service_open' : 'service_accepted')}</td>`
                        : `<td class="small">${esc(i.note || '—')}${i.by_rule ? ` <span class="badge tfb-inv-rule">${esc(t('inv.by_rule'))}</span>` : ''}</td>`}
                    <td class="text-end">${w ? `<button type="button" class="btn btn-sm btn-outline-secondary text-nowrap" data-to-goods="${i.id}"><i class="bi bi-arrow-counterclockwise"></i> ${esc(t('inv.to_goods'))}</button>` : ''}</td></tr>`).join('')
                    || `<tr><td colspan="7" class="text-muted">${esc(t(service ? 'inv.service_empty' : 'inv.rejected_empty'))}</td></tr>`}</tbody></table></div></div></div>`;
    }

    const MONTHS = {pl: ['styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'],
        fr: ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre']};
    const monthLabel = (ym) => ym ? `${(MONTHS[api.lang] || MONTHS.pl)[Number(ym.slice(5, 7)) - 1]} ${ym.slice(0, 4)}` : '—';

    // Reguły dostawców : NIP → zawsze usługa (kategoria) albo zawsze odrzucona.
    function drawRules() {
        const d = data.rules;
        return `<p><a href="#tab=invoices" data-back-inv>‹ ${esc(t('inv.back'))}</a></p>
            <p class="small text-muted mb-2">${esc(t('inv.rules_help'))}</p>
            <div class="card"><div class="card-body pt-3"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
                <thead><tr><th>${esc(t('inv.col.seller'))}</th><th>${esc(t('inv.col.rule'))}</th><th class="text-end">${esc(t('inv.col.invoices'))}</th><th>${esc(t('inv.col.last'))}</th><th>${esc(t('inv.col.by'))}</th><th></th></tr></thead>
                <tbody>${d.rules.map(r => `<tr><td><strong>${esc(r.seller_name || '—')}</strong><div class="small text-muted">NIP ${esc(r.seller_nip)}</div></td>
                    <td>${r.action === 'service' ? `<span class="badge tfb-inv-service_open"><i class="bi bi-arrow-right-square"></i> ${esc(t('inv.rule_service', {category: r.category || r.cost_category || '—'}))}</span>`
                        : `<span class="badge tfb-inv-rejected"><i class="bi bi-slash-circle"></i> ${esc(t('inv.rule_reject'))}</span>`}</td>
                    <td class="text-end tfb-num">${r.invoices}</td><td class="text-nowrap">${esc(fullDay(r.last_invoice))}</td>
                    <td class="small text-muted">${esc(r.created_by || '—')} · ${esc(fullDay((r.created_at || '').slice(0, 10)))}</td>
                    <td class="text-end">${d.can_write ? `<button type="button" class="btn btn-sm btn-outline-danger" data-del-rule="${r.id}" title="${esc(t('inv.rule_delete'))}" aria-label="${esc(t('inv.rule_delete'))}"><i class="bi bi-trash"></i></button>` : ''}</td></tr>`).join('')
                    || `<tr><td colspan="6" class="text-muted">${esc(t('inv.rules_empty'))}</td></tr>`}</tbody></table></div></div></div>`;
    }

    function bindRules() {
        el.querySelector('[data-back-inv]').addEventListener('click', (e) => { e.preventDefault(); state.inv = null; load(); });
        el.querySelectorAll('[data-del-rule]').forEach(b => b.addEventListener('click', async () => {
            const r = data.rules.rules.find(x => x.id === Number(b.dataset.delRule));
            if (!window.confirm(t('inv.confirm_rule_delete', {name: r.seller_name || r.seller_nip}))) return;
            b.disabled = true;
            try {
                await api.del(`/supply/supplier-rules/${r.id}`);
                ui.notify(true, t('deleted'));
                load();
            } catch (err) { ui.notify(false, errText(err)); b.disabled = false; }
        }));
    }

    const classify = (id, action) => window.TfbInvoiceClassify.open({api, invoiceId: id, action, onDone: () => (state.inv ? openInvoice(state.inv) : load())});

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

    // Wynik « Zatwierdź fakturę » : co zrobiono (ceny, stan, akceptacja).
    const processedText = (p) => [p.prices ? t('inv.done.prices', {n: p.prices}) : null, p.stock ? t('inv.done.stock') : null, p.accepted ? t('inv.done.accepted') : null]
        .filter(Boolean).join(' · ') + (p.prices_skipped && p.prices_skipped.length ? ' ' + t('inv.prices_skipped', {list: p.prices_skipped.join(', ')}) : '')
        + (p.prices_kept && p.prices_kept.length ? ' ' + t('inv.prices_kept', {list: p.prices_kept.join(', ')}) : '')
        + (p.prices_held && p.prices_held.length ? ' ' + t('inv.prices_held', {list: p.prices_held.join(', ')}) : '');

    function bindInvoices() {
        const d = data.invoices;
        el.querySelectorAll('[data-istatus]').forEach(b => b.addEventListener('click', () => { state.istatus = b.dataset.istatus; load(); }));
        el.querySelectorAll('[data-ikind]').forEach(b => b.addEventListener('click', () => { state.ikind = b.dataset.ikind; load(); }));
        el.querySelector('[data-ikind-link]')?.addEventListener('click', (e) => { e.preventDefault(); state.ikind = 'service'; load(); });
        el.querySelector('[data-rules]').addEventListener('click', (e) => { e.preventDefault(); state.inv = 'rules'; load(); });
        el.querySelector('[data-mappings]')?.addEventListener('click', (e) => { e.preventDefault(); openInvoice('map'); });
        el.querySelector('[data-wizard]')?.addEventListener('click', (e) => { e.preventDefault(); openInvoice('wizard'); });
        el.querySelectorAll('[data-classify]').forEach(b => b.addEventListener('click', (e) => { e.stopPropagation(); classify(Number(b.dataset.classify), b.dataset.action); }));
        el.querySelectorAll('[data-to-goods]').forEach(b => b.addEventListener('click', (e) => {
            e.stopPropagation();
            const inv = d.invoices.find(x => x.id === Number(b.dataset.toGoods));
            window.TfbInvoiceClassify.toGoods({api, invoice: inv, rule: !!inv.by_rule, onDone: () => load()});
        }));
        el.querySelectorAll('tr[data-open-inv]').forEach(tr => tr.addEventListener('click', (e) => {
            if (e.target.closest('[data-quick-process], [data-quick-pu], [data-classify], [data-to-goods]')) return;
            e.preventDefault();
            openInvoice(Number(tr.dataset.openInv));
        }));
        // Jedno kliknięcie : nowe faktury z KSeF (od ostatniej pobranej), gotowe faktury, jedna faktura z listy.
        el.querySelector('[data-ksef-sync]')?.addEventListener('click', async (e) => {
            const b = e.currentTarget;
            b.disabled = true;
            b.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span>${esc(t('inv.ksef_syncing'))}`;
            try {
                const res = await api.post('/supply/invoices/ksef/sync', {});
                ui.notify(!res.errors.length, t('inv.ksef_synced', {n: res.imported.length, found: res.found, from: fullDay(res.from), to: fullDay(res.to)})
                    + (res.auto.length ? ' ' + t('inv.auto_done_n', {n: res.auto.length}) : '') + (res.more ? ' ' + t('inv.ksef_more', {n: res.more}) : '')
                    + (res.errors.length ? ' ' + t('inv.ksef_errors', {list: res.errors.map(x => x.ksef_number).join(', ')}) : ''));
                if (res.imported.length === 1 && !res.auto.length) return openInvoice(res.imported[0]);
                load();
            } catch (err) { ui.notify(false, errText(err)); load(); }
        });
        el.querySelector('[data-process-ready]')?.addEventListener('click', async (e) => {
            const n = d.invoices.filter(i => i.status === 'open' && i.ready && i.ready.ok).length;
            if (!window.confirm(t('inv.confirm_ready', {n}))) return;
            e.currentTarget.disabled = true;
            try {
                const res = await api.post('/supply/invoices/process-ready', {});
                ui.notify(!res.errors.length, t('inv.ready_done', {n: res.processed.length, left: res.left}));
            } catch (err) { ui.notify(false, errText(err)); }
            load();
        });
        // Podwyżka ceny z listy : okno « nowa cena bazowa » bez otwierania faktury [210].
        el.querySelectorAll('[data-quick-pu]').forEach(b => b.addEventListener('click', async (e) => {
            e.stopPropagation();
            b.disabled = true;
            if (await priceUp(Number(b.dataset.quickPu), {next: t('pu.next_after')})) load();
            else b.disabled = false;
        }));
        el.querySelectorAll('[data-quick-process]').forEach(b => b.addEventListener('click', async (e) => {
            e.stopPropagation();
            const inv = d.invoices.find(x => x.id === Number(b.dataset.quickProcess));
            if (!window.confirm(t('inv.confirm_process', {number: inv.number || t('inv.no_number')}))) return;
            b.disabled = true;
            const process = () => api.post(`/supply/invoices/${inv.id}/process`, {});
            try {
                const res = await process();
                ui.notify(true, processedText(res.processed));
            } catch (err) {
                if (!isPriceUp(err)) ui.notify(false, errText(err));
                else if (await priceUp(inv.id, {next: t('pu.next_process')})) {
                    try { ui.notify(true, processedText((await process()).processed)); } catch (err2) { ui.notify(false, errText(err2)); }
                }
            }
            load();
        }));
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
        el.querySelectorAll('[data-upload]').forEach(i => i.addEventListener('change', (e) => upload(e.target)));
    }

    // Plik faktury : PDF / XML wysłane jak są ; zdjęcie (albo skan PDF bez tekstu : PDF_SCANNED) odczytane OCR w przeglądarce,
    // tekst wysłany z plikiem (ocr_text) — linie rozpoznane na serwerze jak w PDF, do sprawdzenia z oryginałem.
    async function upload(input) {
        const file = input.files && input.files[0];
        if (!file) return;
        const image = /^image\//.test(file.type) || /\.(jpe?g|png|webp|heic|heif)$/i.test(file.name);
        if (file.size > (image ? 30 : 5) * 1024 * 1024) { ui.notify(false, t('inv.too_big')); input.value = ''; return; }
        const inputs = [...el.querySelectorAll('[data-upload]')];
        inputs.forEach(x => { x.disabled = true; });
        try {
            let body;
            if (image) {
                ocrStatus(t('inv.ocr.prepare'));
                const img = await prepareImage(file);
                body = {filename: file.name.replace(/\.[^.]*$/, '') + '.jpg', content_base64: img.stored, ocr_text: await ocrRead([img.ocr])};
            } else {
                body = {filename: file.name, content_base64: await fileBase64(file)};
            }
            let res;
            try {
                res = await api.post('/supply/invoices/upload', body);
            } catch (err) {
                if (image || !err.body || !['PDF_SCANNED', 'PDF_TEXT_UNAVAILABLE'].includes(err.body.description)) throw err;
                ocrStatus(t('inv.ocr.scan'));
                body.ocr_text = await ocrRead(await pdfPages(file));
                res = await api.post('/supply/invoices/upload', body);
            }
            ui.notify(true, t(res.source === 'ocr' ? 'inv.uploaded_ocr' : 'inv.uploaded', {number: res.number || file.name, n: res.lines.length})
                + (res.auto_processed ? ' ' + t('inv.auto_done') + ' ' + processedText(res.auto_processed) : ''));
            openInvoice(res.id, res);
        } catch (err) {
            ui.notify(false, err.ocr ? t('inv.ocr.failed') : errText(err));
            inputs.forEach(x => { x.disabled = false; x.value = ''; });
        } finally {
            ocrStatus('');
        }
    }

    const fileBase64 = (file) => new Promise((resolve, reject) => {
        const r = new FileReader();
        r.onload = () => resolve(String(r.result).split(',')[1] || '');
        r.onerror = () => reject(r.error);
        r.readAsDataURL(file);
    });
    const ocrStatus = (text) => { const s = el.querySelector('[data-ocr-status]'); if (s) s.innerHTML = text ? `<span class="spinner-border spinner-border-sm me-1"></span>${esc(text)}` : ''; };
    const loadScript = (src, global) => window[global] ? Promise.resolve() : new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = src; s.onload = resolve; s.onerror = () => reject(new Error(src));
        document.head.appendChild(s);
    });
    const ocrError = (e) => Object.assign(new Error((e && e.message) || String(e)), {ocr: true});

    // Zdjęcie : orientacja z EXIF, do zapisu JPEG ≤ 2200 px ; do OCR szare z rozciągniętym kontrastem, długi bok 2000–3200 px.
    async function prepareImage(file) {
        let img;
        try {
            img = window.createImageBitmap ? await createImageBitmap(file, {imageOrientation: 'from-image'}).catch(() => createImageBitmap(file))
                : await new Promise((resolve, reject) => { const x = new Image(); x.onload = () => resolve(x); x.onerror = reject; x.src = URL.createObjectURL(file); });
        } catch (e) { throw ocrError(e); }
        const long = Math.max(img.width, img.height);
        const keep = scaled(img, Math.min(1, 2200 / long));
        const ocr = grayStretch(scaled(img, long < 2000 ? Math.min(2.5, 2000 / long) : Math.min(1, 3200 / long)));
        return {stored: keep.toDataURL('image/jpeg', 0.85).split(',')[1], ocr};
    }
    function scaled(src, k) {
        const c = document.createElement('canvas');
        c.width = Math.max(1, Math.round(src.width * k));
        c.height = Math.max(1, Math.round(src.height * k));
        const ctx = c.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, c.width, c.height);
        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(src, 0, 0, c.width, c.height);
        return c;
    }
    // Do OCR : skala szarości, tło wyrównane (każdy piksel ÷ jasność papieru wokół — cienie i szary papier → białe),
    // kontrast rozciągnięty, bez kresek tabeli.
    function grayStretch(c) {
        const ctx = c.getContext('2d');
        const w = c.width, h = c.height;
        const im = ctx.getImageData(0, 0, w, h);
        const d = im.data;
        const g = new Float32Array(w * h);
        for (let i = 0; i < w * h; i++) g[i] = (d[i * 4] * 299 + d[i * 4 + 1] * 587 + d[i * 4 + 2] * 114) / 1000;
        // Jasność tła : maksimum w blokach (większych niż litery), wygładzone, interpolowane.
        const B = Math.max(24, Math.round(Math.max(w, h) / 70));
        const gw = Math.ceil(w / B), gh = Math.ceil(h / B);
        const grid = new Float32Array(gw * gh);
        for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) { const k = ((y / B) | 0) * gw + ((x / B) | 0); if (g[y * w + x] > grid[k]) grid[k] = g[y * w + x]; }
        const bg = new Float32Array(gw * gh);
        for (let gy = 0; gy < gh; gy++) for (let gx = 0; gx < gw; gx++) {
            let m = 0;
            for (let dy = -1; dy <= 1; dy++) for (let dx = -1; dx <= 1; dx++) { const yy = gy + dy, xx = gx + dx; if (yy >= 0 && yy < gh && xx >= 0 && xx < gw) m = Math.max(m, grid[yy * gw + xx]); }
            bg[gy * gw + gx] = Math.max(m, 40);
        }
        const at = (gx, gy) => bg[Math.min(gh - 1, Math.max(0, gy)) * gw + Math.min(gw - 1, Math.max(0, gx))];
        for (let y = 0; y < h; y++) {
            const fy = y / B - 0.5, y0 = Math.floor(fy), ty = fy - y0;
            for (let x = 0; x < w; x++) {
                const fx = x / B - 0.5, x0 = Math.floor(fx), tx = fx - x0;
                const back = (at(x0, y0) * (1 - tx) + at(x0 + 1, y0) * tx) * (1 - ty) + (at(x0, y0 + 1) * (1 - tx) + at(x0 + 1, y0 + 1) * tx) * ty;
                const v = Math.max(0, Math.min(255, (g[y * w + x] / back * 255 - 60) * 255 / 165));
                const k = (y * w + x) * 4;
                d[k] = d[k + 1] = d[k + 2] = v;
            }
        }
        removeRules(d, w, h);
        ctx.putImageData(im, 0, 0);
        return c;
    }
    // Kreski tabeli (ciemne odcinki dłuższe niż ~1/60 dłuższego boku, poziome i pionowe) wybielone : OCR czyta wtedy wiersze tabeli jak tekst.
    function removeRules(d, w, h) {
        const dark = new Uint8Array(w * h);
        for (let i = 0; i < w * h; i++) dark[i] = d[i * 4] < 140 ? 1 : 0;
        const run = Math.max(40, Math.round(Math.max(w, h) / 60));
        const kill = new Uint8Array(w * h);
        const scan = (count, len, at) => {
            for (let a = 0; a < count; a++) {
                let start = -1;
                for (let b = 0; b <= len; b++) {
                    const on = b < len && dark[at(a, b)];
                    if (on && start < 0) start = b;
                    else if (!on && start >= 0) { if (b - start >= run) for (let k = start; k < b; k++) kill[at(a, k)] = 1; start = -1; }
                }
            }
        };
        scan(h, w, (y, x) => y * w + x);
        scan(w, h, (x, y) => y * w + x);
        for (let i = 0; i < w * h; i++) if (kill[i]) d[i * 4] = d[i * 4 + 1] = d[i * 4 + 2] = 255;
    }

    // Skan PDF : strony (maks. 4) narysowane przez pdf.js w ~2400 px szerokości.
    async function pdfPages(file) {
        try {
            await loadScript('https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js', 'pdfjsLib');
            window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
            const pdf = await window.pdfjsLib.getDocument({data: new Uint8Array(await file.arrayBuffer())}).promise;
            const out = [];
            for (let p = 1; p <= Math.min(pdf.numPages, 4); p++) {
                const page = await pdf.getPage(p);
                const viewport = page.getViewport({scale: Math.min(4, 2400 / page.getViewport({scale: 1}).width)});
                const c = document.createElement('canvas');
                c.width = Math.round(viewport.width);
                c.height = Math.round(viewport.height);
                const ctx = c.getContext('2d');
                ctx.fillStyle = '#fff';
                ctx.fillRect(0, 0, c.width, c.height);
                await page.render({canvasContext: ctx, viewport}).promise;
                out.push(grayStretch(c));
            }
            return out;
        } catch (e) { throw ocrError(e); }
    }

    // OCR (Tesseract.js, polski, blok tekstu z liniami tabeli) ; worker ładowany przy pierwszym odczycie i zachowany.
    let ocrWorker = null;
    let ocrLog = () => {}; // postęp bieżącego odczytu
    async function ocrRead(canvases) {
        try {
            await loadScript('https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/tesseract.min.js', 'Tesseract');
            let page = 1;
            ocrLog = (m) => {
                if (m.status === 'recognizing text') ocrStatus(t('inv.ocr.reading', {page, pages: canvases.length, pct: Math.round(m.progress * 100)}));
                else if (/loading|initializ/.test(m.status || '')) ocrStatus(t('inv.ocr.loading'));
            };
            if (!ocrWorker) {
                ocrWorker = window.Tesseract.createWorker('pol', 1, {logger: (m) => ocrLog(m)}).then(async (w) => {
                    await w.setParameters({tessedit_pageseg_mode: '6', preserve_interword_spaces: '1'});
                    return w;
                });
                ocrWorker.catch(() => { ocrWorker = null; });
            }
            const worker = await ocrWorker;
            const pages = [];
            for (const c of canvases) {
                const r = await worker.recognize(c);
                pages.push(layoutText(r.data));
                page++;
            }
            return pages.join('\n\n').slice(0, 200000);
        } catch (e) { throw ocrError(e); }
    }

    // Linie OCR → tekst kolumnowy jak pdftotext -layout : przerwa między słowami większa niż ~1,6 znaku = kolumna (3 spacje).
    function layoutText(d) {
        const lines = d.lines && d.lines.length ? d.lines : (d.blocks || []).flatMap(b => (b.paragraphs || []).flatMap(p => p.lines || []));
        if (!lines.length) return d.text || '';
        return lines.map(l => {
            const words = (l.words || []).filter(w => w.text && w.text.trim());
            if (!words.length) return (l.text || '').trim();
            const chars = words.reduce((sum, w) => sum + w.text.length, 0);
            const cw = words.reduce((sum, w) => sum + (w.bbox.x1 - w.bbox.x0), 0) / Math.max(1, chars);
            return words.map((w, k) => (k === 0 ? '' : (w.bbox.x0 - words[k - 1].bbox.x1 > cw * 1.6 ? '   ' : ' ')) + w.text.trim()).join('');
        }).join('\n');
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
        const idFields = edit && ['pdf', 'ocr', 'manual'].includes(i.source)
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
            <tbody>${i.lines.map(l => invoiceLine(l, edit)).join('') || `<tr><td colspan="8" class="text-muted">${esc(t(i.lines_editable ? (i.source === 'ocr' ? 'inv.no_lines_ocr' : 'inv.no_lines_pdf') : 'inv.no_lines'))}</td></tr>`}</tbody>
            <tfoot><tr><th colspan="3" class="text-end">${esc(t('total'))}</th><th class="text-end">${esc(cur(c.lines_net, i.currency))}</th><th colspan="4"></th></tr></tfoot></table></div>`;
        const actions = [];
        if (i.lines_editable && !iv.editLines) actions.push(`<button type="button" class="btn btn-sm btn-outline-secondary" data-iact="edit-lines"><i class="bi bi-pencil"></i> ${esc(t('inv.act.edit_lines'))}</button>`);
        if (edit) {
            actions.push(`<button type="button" class="btn btn-sm btn-success" data-iact="accept"><i class="bi bi-check2-circle"></i> ${esc(t('inv.act.accept'))}</button>`);
            actions.push(`<button type="button" class="btn btn-sm btn-outline-danger" data-iact="dispute"><i class="bi bi-exclamation-octagon"></i> ${esc(t('inv.act.dispute'))}</button>`);
            actions.push(`<button type="button" class="btn btn-sm btn-outline-danger ms-auto" data-iact="delete"><i class="bi bi-trash"></i> ${esc(t('inv.act.delete'))}</button>`);
        } else if (i.can_write && i.status !== 'open' && i.kind === 'goods' && i.status !== 'rejected') {
            actions.push(`<button type="button" class="btn btn-sm btn-outline-secondary" data-iact="reopen"><i class="bi bi-arrow-counterclockwise"></i> ${esc(t('inv.act.reopen'))}</button>`);
        }
        const file = i.file ? `<button type="button" class="btn btn-sm btn-outline-secondary" data-iact="file"><i class="bi bi-file-earmark-text"></i> ${esc(t(iv.showFile ? 'inv.act.hide_file' : 'inv.act.show_file'))}</button>` : '';
        const viewer = iv.showFile && iv.file ? `<div class="card tfb-inv-viewer"><div class="card-header d-flex align-items-center gap-2"><strong class="small">${esc(iv.file.name)}</strong>
                <a class="btn btn-sm btn-link ms-auto" href="${iv.file.url}" download="${esc(iv.file.name)}"><i class="bi bi-download"></i> ${esc(t('inv.download'))}</a></div>
                <iframe src="${iv.file.url}" title="${esc(iv.file.name)}"></iframe></div>` : '';
        return `<p><a href="#tab=invoices" data-back-inv>‹ ${esc(t('inv.back'))}</a></p>
            <div class="card"><div class="card-header d-flex flex-wrap align-items-center gap-2">
                <h5 class="mb-0">${esc(i.number || t('inv.no_number'))} · ${esc(i.supplier ? i.supplier.name : (i.seller_name || '—'))}</h5>${invBadge(i.status)} ${ctrlBadge(c)}${i.is_correction ? ` <span class="badge bg-dark">${esc(t('inv.correction'))}</span>` : ''}
                <span class="small text-muted ms-auto">${esc(t('inv.source.' + i.source))} · ${esc(fullDay(i.issue_date))}${i.seller_nip ? ' · NIP ' + esc(i.seller_nip) : ''}</span>${file}</div>
              <div class="card-body">
                <div class="row g-2 mb-2" data-inv-head>
                    <div class="col-md-3"><label class="form-label small mb-0">${esc(t('col.supplier'))}</label>${supplierField}
                        ${i.seller_name ? `<div class="small text-muted">${esc(t('inv.on_invoice', {name: i.seller_name}))}</div>` : ''}</div>
                    <div class="col-md-3"><label class="form-label small mb-0">${esc(t('inv.col.order'))}</label>${orderField}</div>
                    ${idFields}
                    <div class="col-md-2"><label class="form-label small mb-0">${esc(t('inv.totals'))}</label><div>${esc(cur(i.total_net, i.currency))}
                        <span class="small text-muted">· ${esc(t('inv.gross', {v: cur(i.total_gross, i.currency)}))}</span></div></div></div>
                ${kindBar(i)}
                ${i.source === 'ocr' && edit ? `<div class="alert alert-warning py-2 small mb-2"><i class="bi bi-eye"></i> ${esc(t('inv.ocr.check'))}</div>` : ''}
                ${i.is_correction ? `<div class="alert alert-secondary py-2 small mb-2"><i class="bi bi-arrow-counterclockwise"></i> ${esc(t('inv.correction_help'))}</div>` : ''}
                ${steps(i)}
                <div class="alert ${ALERT_CLS[c.state] || 'alert-secondary'} py-2 small mb-2">${esc(summary)}
                    ${c.price_jumps ? `<div>${esc(t('inv.sum.price_jumps', {n: c.price_jumps, pct: n(i.auto.price_alert_pct, 0)}))}</div>` : ''}
                    ${c.total_mismatch ? `<div>${esc(t('inv.sum.total_mismatch', {lines: cur(c.lines_net, i.currency), total: cur(i.total_net, i.currency)}))}</div>` : ''}</div>
                ${iv.editLines ? '' : priceUpBox(i)}
                ${iv.editLines ? '' : quickAction(i)}
                ${lines}
                ${iv.editLines ? '' : importBox(i)}
                ${i.note ? `<div class="small mb-2"><strong>${esc(t('f.note'))} :</strong> ${esc(i.note)}</div>` : ''}
                ${i.text ? `<details class="tfb-inv-text mb-2"><summary class="small">${esc(t(i.source === 'ocr' ? 'inv.ocr_text' : 'inv.pdf_text'))}</summary><pre>${esc(i.text)}</pre></details>` : ''}
                <div class="d-flex flex-wrap gap-2 mt-2">${actions.join('')}</div></div></div>
            ${viewer}`;
    }

    // Rodzaj faktury na karcie : usługa w Finansach / odrzucona (z powrotem do zaopatrzenia) albo « to nie surowiec ? ».
    function kindBar(i) {
        const c = i.classify || {};
        if (i.kind === 'service' || i.status === 'rejected') {
            const text = i.kind === 'service' ? t('inv.kind_service', {category: i.category ? i.category.label : '—', month: monthLabel(i.service_month)}) : t('inv.kind_rejected', {note: i.note || '—'});
            return `<div class="alert ${i.kind === 'service' ? 'alert-info' : 'alert-secondary'} py-2 small mb-2 d-flex flex-wrap align-items-center gap-2">
                <span><i class="bi ${i.kind === 'service' ? 'bi-arrow-right-square' : 'bi-slash-circle'}"></i> ${esc(text)}${i.by_rule ? ' · ' + esc(t('inv.by_rule')) : ''}</span>
                ${i.can_write && c.movable ? `<button type="button" class="btn btn-sm btn-outline-secondary ms-auto" data-iact="to-goods"><i class="bi bi-arrow-counterclockwise"></i> ${esc(t('inv.to_goods'))}</button>` : ''}</div>`;
        }
        if (!(i.can_write && c.movable && i.status === 'open')) return '';
        return `<div class="tfb-inv-kindbar small mb-2"><span class="text-muted">${esc(c.guess ? t('inv.guess', {label: c.guess.label}) : t('inv.not_goods'))}</span>
            <button type="button" class="btn btn-sm ${c.guess ? 'btn-outline-primary' : 'btn-link'}" data-iact="classify"><i class="bi bi-arrow-right-square"></i> ${esc(t('inv.to_finance'))}</button>
            <button type="button" class="btn btn-sm btn-link text-secondary" data-iact="classify-reject">${esc(t('inv.reject'))}</button></div>`;
    }

    // Kroki faktury (pas) : dostawca → przypisanie (tylko nowe nazwy) → kontrola → ceny + stan + akceptacja.
    function steps(i) {
        const c = i.control;
        const r = i.ready || {blockers: [], warnings: [], unmapped: 0};
        const closed = i.status !== 'open';
        const items = [
            ['supplier', closed || !!i.supplier, i.supplier ? i.supplier.name : t('inv.step.supplier_todo')],
            ['map', closed || (c.lines > 0 && c.mapped === c.lines), c.lines && c.mapped < c.lines ? t('inv.next.map', {n: c.lines - c.mapped}) : t('inv.step.map_done', {n: c.lines})],
            ['check', closed || (c.lines > 0 && c.mapped === c.lines && !r.warnings.length && !r.price_ups), c.mapped < c.lines ? t('inv.step.check_wait')
                : r.price_ups ? t('inv.step.price_up', {n: r.price_ups})
                : r.warnings.length ? r.warnings.map(x => t('inv.warn.' + x)).join(' · ') : (c.state === 'no_order' ? t('inv.state.no_order') : t('inv.state.clean'))],
            ['done', i.status === 'accepted', i.status === 'accepted' ? t('inv.status.accepted') : i.status === 'disputed' ? t('inv.status.disputed') : t('inv.step.done_todo')],
        ];
        const current = items.findIndex(x => !x[1]);
        return `<ol class="tfb-inv-steps" aria-label="${esc(t('inv.steps'))}">${items.map(([k, done, sub], idx) => {
            const warn = k === 'check' && (r.warnings.length || r.price_ups) && !closed;
            return `<li class="${done ? 'is-done' : ''} ${idx === current ? 'is-current' : ''} ${warn ? 'is-warn' : ''}"><span class="tfb-inv-step-n">${done ? '<i class="bi bi-check-lg"></i>' : idx + 1}</span>
                <span><strong>${esc(t('inv.step.' + k))}</strong><small>${esc(sub)}</small></span></li>`;
        }).join('')}</ol>`;
    }

    // Jeden przycisk na stan faktury : przypisz wszystkie (tylko nowe nazwy) albo zatwierdź (ceny + stan + akceptacja).
    function quickAction(i) {
        if (!i.editable || !i.ready) return '';
        const r = i.ready;
        if (r.blockers.includes('no_lines')) return '';
        if (r.blockers.includes('unmapped')) {
            return `<div class="tfb-inv-quick"><button type="button" class="btn btn-primary" data-map-all><i class="bi bi-link-45deg"></i> ${esc(t('inv.act.map_all', {n: r.unmapped}))}</button>
                <span class="small text-muted">${esc(t('inv.map_all_help'))}</span></div>`;
        }
        if (r.blockers.includes('no_supplier')) return `<div class="tfb-inv-quick"><span class="small text-warning"><i class="bi bi-arrow-up"></i> ${esc(t('inv.next.supplier_help'))}</span></div>`;
        if (r.blockers.includes('price_up')) return `<div class="tfb-inv-quick is-warn">${i.editable ? `<button type="button" class="btn btn-danger" data-pu-modal><i class="bi bi-graph-up-arrow"></i> ${esc(t('pu.open', {n: r.price_ups}))}</button>` : ''}
            <span class="small text-danger"><i class="bi bi-graph-up-arrow"></i> ${esc(t('inv.price_up.first', {n: r.price_ups}))}</span></div>`;
        const opt = (k, on, enabled, label) => `<label class="form-check form-check-inline small mb-0"><input class="form-check-input" type="checkbox" data-step="${k}" ${on ? 'checked' : ''} ${enabled ? '' : 'disabled'}> ${esc(label)}</label>`;
        const pricesDone = !!i.prices_imported_at;
        return `<div class="tfb-inv-quick ${r.warnings.length ? 'is-warn' : ''}">
            <button type="button" class="btn btn-success" data-process><i class="bi bi-check2-all"></i> ${esc(t('inv.act.process'))}</button>
            <span class="d-inline-flex flex-wrap gap-1 align-items-center">
                ${opt('prices', !pricesDone, !pricesDone, pricesDone ? t('inv.step_opt.prices_done') : t('inv.step_opt.prices'))}
                ${opt('stock', i.can_receive, i.can_receive, i.can_receive ? t(i.receive_mode === 'order' ? 'inv.step_opt.stock_order' : 'inv.step_opt.stock') : t('inv.step_opt.stock_none'))}
                ${opt('accept', true, true, t('inv.step_opt.accept'))}</span>
            ${r.warnings.length ? `<div class="small text-warning w-100"><i class="bi bi-exclamation-triangle"></i> ${esc(t('inv.warn_confirm', {list: r.warnings.map(x => t('inv.warn.' + x)).join(' · ')}))}</div>` : ''}</div>`;
    }

    // Ceny wyższe niż cena bazowa (kartoteka — kalkulacja receptur) : decyzja na surowiec — nowa cena bazowa albo bez zmian ;
    // bez decyzji ani automat, ani « Zatwierdź fakturę ». Kto i kiedy zdecydował — przy surowcu.
    function priceUpBox(i) {
        const ups = i.price_ups || [];
        if (!ups.length || i.prices_imported_at) return '';
        const can = i.editable;
        const pending = ups.filter(u => !u.decision).length;
        const rows = ups.map(u => {
            const r = {unit: u.unit, factor: u.factor};
            const state = u.decision ? `<span class="badge ${u.decision === 'accept' ? 'bg-success' : 'bg-secondary'}">${esc(t('inv.price_up.' + u.decision + '_done'))}</span>
                    <div class="small text-muted">${esc(t('inv.price_up.by', {who: u.decided_by || '—', at: u.decided_at ? fullDay(u.decided_at.slice(0, 10)) + ' ' + u.decided_at.slice(11, 16) : ''}))}</div>` : '';
            const buttons = can ? `<div class="d-flex flex-wrap gap-1 justify-content-end">
                    <button type="button" class="btn btn-sm ${u.decision === 'accept' ? 'btn-success' : 'btn-outline-success'}" data-pu="accept" data-pu-mat="${u.raw_material_id}" ${u.decision === 'accept' ? 'disabled' : ''}><i class="bi bi-check2"></i> ${esc(t('inv.price_up.accept'))}</button>
                    <button type="button" class="btn btn-sm ${u.decision === 'keep' ? 'btn-secondary' : 'btn-outline-secondary'}" data-pu="keep" data-pu-mat="${u.raw_material_id}" ${u.decision === 'keep' ? 'disabled' : ''}>${esc(t('inv.price_up.keep'))}</button></div>` : '';
            return `<tr data-pu-row="${u.raw_material_id}" class="${u.decision ? '' : 'table-warning'}"><td><strong>${esc(u.name)}</strong>
                    <div class="small text-muted">${esc(t('inv.price_up.recipes', {n: u.recipes}))}</div></td>
                <td class="text-end text-nowrap">${esc(unitPrice(r, u.base_price))}</td><td class="text-end text-nowrap"><strong>${esc(unitPrice(r, u.new_price))}</strong></td>
                <td class="text-end text-nowrap text-danger">${esc(pct(u.change_pct))}</td><td class="text-end text-nowrap">${esc(signed(u.extra, money))}
                    <div class="small text-muted">${esc(q(r, u.quantity))}</div></td>
                <td class="text-end">${state}${buttons}</td></tr>`;
        }).join('');
        return `<div class="card tfb-inv-priceup mb-2 ${pending ? 'border-danger' : 'border-success'}"><div class="card-body">
            <h6 class="tfb-team-title mb-1"><i class="bi bi-graph-up-arrow"></i> ${esc(t('inv.price_up.title'))}</h6>
            <p class="small text-muted mb-2">${esc(t('inv.price_up.help'))}</p>
            <div class="table-responsive"><table class="table table-sm align-middle mb-2"><thead><tr><th>${esc(t('inv.col.material'))}</th>
                <th class="text-end">${esc(t('inv.price_up.base'))}</th><th class="text-end">${esc(t('inv.price_up.new'))}</th><th class="text-end">${esc(t('inv.col.diff'))}</th>
                <th class="text-end">${esc(t('inv.price_up.extra'))}</th><th class="text-end">${esc(t('inv.price_up.decision'))}</th></tr></thead><tbody>${rows}</tbody></table></div>
            ${can && pending > 1 ? `<div class="d-flex flex-wrap gap-2"><button type="button" class="btn btn-sm btn-success" data-pu-all="accept"><i class="bi bi-check2-all"></i> ${esc(t('inv.price_up.accept_all', {n: pending}))}</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-pu-all="keep">${esc(t('inv.price_up.keep_all', {n: pending}))}</button></div>` : ''}
            </div></div>`;
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
            <h6 class="tfb-team-title mb-1">${esc(t('inv.import_title'))}</h6><p class="small text-muted mb-2">${esc(t('inv.import_help'))} ${esc(i.auto && i.auto.cost_max_months ? t('inv.import_max', {n: i.auto.cost_max_months}) : t('inv.import_max_never'))}</p>
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
                ${k.price_gap_pct ? `<div class="small ${k.flags.includes('price_over') ? 'text-danger' : k.flags.includes('price_under') ? 'text-success' : 'text-muted'}">${esc(pct(k.price_gap_pct))}${k.value_gap ? ' · ' + esc(signed(k.value_gap, money)) : ''}</div>` : ''}
                ${priceRefs(m, u, k.price_base)}</td>`;
        return `<tr data-iline="${l.id}" class="${k.flags.some(f => BAD_FLAGS.includes(f)) ? 'table-danger' : k.flags.includes('price_jump') || k.flags.includes('price_up') ? 'table-warning' : ''}">
            <td><strong>${esc(l.name)}</strong>${l.code ? `<div class="small text-muted">${esc(l.code)}</div>` : ''}</td>
            <td class="text-end text-nowrap">${esc(n(l.quantity, 3))} ${esc(l.unit || '')}</td>
            <td class="text-end text-nowrap">${l.unit_price !== null ? esc(n(l.unit_price, 4)) : '—'}</td>
            <td class="text-end text-nowrap">${esc(money(l.net_value))}${l.vat_rate ? `<div class="small text-muted">VAT ${esc(l.vat_rate)}${/^\d+$/.test(l.vat_rate) ? ' %' : ''}</div>` : ''}</td>
            <td class="tfb-inv-map">${mapping}</td>${ours}<td>${flags}</td></tr>`;
    }

    // Historia ceny w linii : poprzednia cena (spoza tej faktury) i najtańszy dostawca z 12 miesięcy, link do wykresu.
    function priceRefs(m, u, price) {
        const ref = data.invoice.price_refs && data.invoice.price_refs[m.id];
        const parts = [];
        if (ref && ref.previous) parts.push(t('inv.ref.previous', {price: unitPrice(u, ref.previous.price), supplier: ref.previous.supplier || '—', day: fullDay(ref.previous.day)}));
        if (ref && ref.best && ref.suppliers > 1 && price !== null && ref.best.price < price * 0.995) parts.push(t('inv.ref.best', {price: unitPrice(u, ref.best.price), supplier: ref.best.supplier || '—'}));
        return `<div class="small text-muted tfb-inv-ref">${parts.map(x => `<div>${esc(x)}</div>`).join('')}
            <button type="button" class="btn btn-link btn-sm p-0" data-ph-mat="${m.id}"><i class="bi bi-graph-up"></i> ${esc(t('prices.history'))}</button></div>`;
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
                <input class="form-control text-end" inputmode="decimal" data-map-factor placeholder="${esc(t('inv.factor_auto'))}" aria-label="${esc(t('inv.factor'))}">
                <select class="form-select tfb-unit-pick" data-map-unit aria-label="${esc(t('inv.unit_pick'))}" title="${esc(t('inv.unit_pick'))}">${UNIT_PICK.map(u => `<option value="${u}">${u}</option>`).join('')}</select></div>
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
            const unitSel = form.querySelector('[data-map-unit]');
            const baseOf = (id) => {
                const s = (l.suggestions || []).find(x => x.raw_material_id === id);
                const m = data.materials ? data.materials.rows.find(x => x.id === id) : null;
                return l.material && l.material.id === id ? l.material.base_unit : s ? s.base_unit : m ? m.base_unit : null;
            };
            const fill = () => {
                const id = Number(select.value) || null;
                const s = (l.suggestions || []).find(x => x.raw_material_id === id);
                const base = baseOf(id);
                const u = disp(base);
                const f = l.material && l.material.id === id ? l.factor : s ? s.factor : null;
                unitSel.value = u.unit;
                unitSel.disabled = !base;
                unitSel.dataset.prev = u.unit;
                factor.value = f !== null && f !== undefined ? String(Math.round(f / u.factor * 10000) / 10000).replace('.', ',') : '';
                factor.dataset.scale = u.factor;
            };
            fill();
            select.addEventListener('change', fill);
            // Jednostka klikalna (27/10/2026) : kg ↔ g, l ↔ ml = sama skala ; masa / objętość / sztuki = zmiana na karcie surowca, od razu.
            unitSel.addEventListener('change', async () => {
                const id = Number(select.value) || null;
                const base = baseOf(id);
                const to = unitSel.value;
                const prev = unitSel.dataset.prev;
                if (!id || !base) return;
                if (unitGroup(to) === unitGroup(base)) {
                    const v = ui.num(factor.value);
                    const scale = unitScale(to, base);
                    if (typeof v === 'number') factor.value = String(Math.round(v * Number(factor.dataset.scale || 1) / scale * 10000) / 10000).replace('.', ',');
                    factor.dataset.scale = scale;
                    unitSel.dataset.prev = to;
                    return;
                }
                const name = select.selectedOptions[0].textContent;
                if (!window.confirm(t('inv.unit_confirm', {name, from: prev, to}))) { unitSel.value = prev; return; }
                unitSel.disabled = true;
                try {
                    const r = await api.patch(`/supply/materials/${id}/unit`, {unit: to});
                    // Stan lokalny : nowa jednostka bazowa wszędzie, przeliczniki w nowej jednostce (serwer przeliczył zapisane).
                    const scaleF = (x) => { if (x && x.factor !== null && x.factor !== undefined && r.ratio) x.factor = Math.round(x.factor * r.ratio * 10000) / 10000; };
                    if (l.material && l.material.id === id) { l.material.base_unit = r.base_unit; scaleF(l); }
                    (l.suggestions || []).filter(x => x.raw_material_id === id).forEach(x => { x.base_unit = r.base_unit; scaleF(x); });
                    (data.materials ? data.materials.rows : []).filter(x => x.id === id).forEach(x => { x.base_unit = r.base_unit; });
                    fill();
                    unitSel.value = to;
                    unitSel.dataset.prev = to;
                    factor.dataset.scale = unitScale(to, r.base_unit);
                    ui.notify(true, t(r.converted ? 'inv.unit_changed' : 'inv.unit_changed_check', {name, unit: to, price: n(r.price_per_unit, 4)})
                        + (r.recipes ? ' ' + t('inv.unit_recipes', {n: r.recipes}) : ''));
                } catch (err) {
                    unitSel.value = prev;
                    ui.notify(false, err.message);
                } finally {
                    unitSel.disabled = false;
                }
            });
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
        el.querySelectorAll('[data-ph-mat]').forEach(b => b.addEventListener('click', () => {
            const l = i.lines.find(x => x.material && x.material.id === Number(b.dataset.phMat));
            openHistory(Number(b.dataset.phMat), l ? l.material.name : '');
        }));
        // « Przypisz wszystkie » : surowiec i przelicznik z formularzy linii (podpowiedź wybrana od razu), zapamiętane dla dostawcy.
        el.querySelector('[data-map-all]')?.addEventListener('click', (e) => {
            const lines = [];
            let missing = 0;
            let bad = false;
            el.querySelectorAll('[data-mapform]').forEach(form => {
                const l = i.lines.find(x => x.id === Number(form.dataset.mapform));
                if (!l || l.material) return;
                const id = Number(form.querySelector('[data-map-mat]').value) || null;
                if (!id) { missing++; return; }
                const factor = form.querySelector('[data-map-factor]');
                const f = ui.num(factor.value);
                if (f !== null && (typeof f !== 'number' || f <= 0)) { bad = true; return; }
                lines.push({line_id: l.id, raw_material_id: id, ...(f !== null ? {factor: Math.round(f * Number(factor.dataset.scale || 1) * 10000) / 10000} : {})});
            });
            if (bad) { ui.notify(false, t('inv.factor_invalid')); return; }
            if (!lines.length) { ui.notify(false, t('inv.map_all_none')); return; }
            run(e.currentTarget, () => api.put(`/supply/invoices/${i.id}/map`, {lines, remember: true}), t('inv.map_all_ok', {n: lines.length}) + (missing ? ' ' + t('inv.map_all_missing', {n: missing}) : ''));
        });
        // Podwyżki cen : decyzja dla jednego surowca albo wszystkich bez decyzji (nowa cena bazowa / bez zmian).
        // Nowa (wyższa) cena bazowa : zawsze przez okno potwierdzenia [210] ; « Bez zmian » od razu.
        const confirmUps = async (b, o = {}) => {
            b.disabled = true;
            const res = await priceUp(i.id, o);
            b.disabled = false;
            if (res) await showInvoice(res);
        };
        el.querySelector('[data-pu-modal]')?.addEventListener('click', (e) => confirmUps(e.currentTarget, {next: t('pu.next_after')}));
        el.querySelectorAll('[data-pu]').forEach(b => b.addEventListener('click', () => {
            const decision = b.dataset.pu;
            if (decision === 'accept') return confirmUps(b, {materialIds: [Number(b.dataset.puMat)]});
            run(b, () => api.post(`/supply/invoices/${i.id}/price-decisions`, {decision, raw_material_ids: [Number(b.dataset.puMat)]}), t('inv.price_up.' + decision + '_ok', {n: 1}));
        }));
        el.querySelectorAll('[data-pu-all]').forEach(b => b.addEventListener('click', () => {
            const decision = b.dataset.puAll;
            const n = (i.price_ups || []).filter(u => !u.decision).length;
            if (decision === 'accept') return confirmUps(b);
            run(b, () => api.post(`/supply/invoices/${i.id}/price-decisions`, {decision}), t('inv.price_up.' + decision + '_ok', {n}));
        }));
        // « Zatwierdź fakturę » : wybrane kroki ; ostrzeżenia (rozbieżności, skok ceny, suma) — potwierdzenie i force.
        el.querySelector('[data-process]')?.addEventListener('click', async (e) => {
            const b = e.currentTarget;
            const body = {};
            el.querySelectorAll('[data-step]').forEach(c => { body[c.dataset.step] = c.checked && !c.disabled; });
            if (!body.prices && !body.stock && !body.accept) { ui.notify(false, t('inv.step_none')); return; }
            if (i.ready.warnings.length) {
                if (!window.confirm(t('inv.confirm_process_warn', {list: i.ready.warnings.map(x => t('inv.warn.' + x)).join(', ')}))) return;
                body.force = true;
            }
            b.disabled = true;
            const process = async () => {
                const res = await api.post(`/supply/invoices/${i.id}/process`, body);
                ui.notify(true, processedText(res.processed) || t('saved'));
                await showInvoice(res);
            };
            try {
                await process();
            } catch (err) {
                if (!isPriceUp(err)) { ui.notify(false, errText(err)); b.disabled = false; return; }
                const res = await priceUp(i.id, {next: t('pu.next_process')});
                if (!res) { b.disabled = false; return; }
                try { await process(); } catch (err2) { ui.notify(false, errText(err2)); await showInvoice(res); }
            }
        });
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
                const importPrices = async () => {
                    const res = await api.post(`/supply/invoices/${i.id}/prices`, {});
                    ui.notify(true, t('inv.prices_ok', {n: res.imported_prices.length}) + (res.skipped.length ? ' ' + t('inv.prices_skipped', {list: res.skipped.join(', ')}) : ''));
                    await showInvoice(res);
                };
                try {
                    await importPrices();
                } catch (err) {
                    if (!isPriceUp(err)) { ui.notify(false, errText(err)); b.disabled = false; return; }
                    const res = await priceUp(i.id, {next: t('pu.next_prices')});
                    if (!res) { b.disabled = false; return; }
                    try { await importPrices(); } catch (err2) { ui.notify(false, errText(err2)); await showInvoice(res); }
                }
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
            if (act === 'classify' || act === 'classify-reject') return classify(i.id, act === 'classify' ? 'service' : 'reject');
            if (act === 'to-goods') return window.TfbInvoiceClassify.toGoods({api, invoice: i, rule: !!i.by_rule, onDone: () => openInvoice(i.id)});
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

    // Ceny i dostawcy : ostatnia cena za kg każdego surowca, zmiana, najtańsza i najdroższa w okresie — wykres po kliknięciu.
    function drawPrices() {
        const d = data.prices;
        const s = state.search.toLowerCase();
        const rows = d.rows.filter(r => (state.kind === 'all' || (state.kind === 'pack') === r.packaging)
            && (!s || r.name.toLowerCase().includes(s) || (r.last.supplier || '').toLowerCase().includes(s)));
        const per = (r, p) => unitPrice({unit: r.unit, factor: r.factor}, p);
        const ch = (v) => v === null ? 'text-muted' : v > 0.05 ? 'text-danger' : v < -0.05 ? 'text-success' : 'text-muted';
        return `<div class="card tfb-supply-bar"><div class="card-body d-flex flex-wrap gap-2 align-items-center">${kindButtons()}
                <div class="btn-group btn-group-sm" role="group">${PMONTHS.map(m => `<button type="button" class="btn ${state.pmonths === m ? 'btn-primary' : 'btn-outline-primary'}" data-pmonths="${m}">${esc(t('prices.months', {n: m}))}</button>`).join('')}</div>
                <input class="form-control form-control-sm ms-auto" style="max-width:240px" data-search value="${esc(state.search)}" placeholder="${esc(t('search'))}"></div></div>
            <p class="small text-muted mb-2">${esc(t('prices.help'))}</p>
            <div class="card"><div class="card-body pt-3"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
                <thead><tr><th>${esc(t('col.material'))}</th><th class="text-end" title="${esc(t('prices.col.base_help'))}">${esc(t('prices.col.base'))}</th><th class="text-end">${esc(t('prices.col.last'))}</th><th>${esc(t('col.supplier'))}</th><th>${esc(t('inv.col.date'))}</th>
                    <th class="text-end">${esc(t('prices.col.change'))}</th><th class="text-end">${esc(t('prices.col.min'))}</th><th class="text-end">${esc(t('prices.col.max'))}</th>
                    <th class="text-end">${esc(t('prices.col.suppliers'))}</th><th></th></tr></thead>
                <tbody>${rows.map(r => `<tr data-ph-row="${r.raw_material_id}" class="tfb-supply-click">
                    <td><strong>${esc(r.name)}</strong></td>
                    <td class="text-end text-nowrap"><strong>${r.catalog_price ? esc(per(r, r.catalog_price)) : '—'}</strong></td>
                    <td class="text-end text-nowrap">${esc(per(r, r.last.price))}</td>
                    <td>${esc(r.last.supplier || '—')}<div class="small text-muted">${esc(t('prices.kind.' + r.last.kind))}${r.last.ref && r.last.kind !== 'catalog' ? ' ' + esc(r.last.ref) : ''}</div></td>
                    <td class="text-nowrap">${esc(fullDay(r.last.day))}</td>
                    <td class="text-end text-nowrap ${ch(r.change_pct)}">${r.change_pct === null ? '—' : esc(pct(r.change_pct))}${r.previous ? `<div class="small text-muted">${esc(t('prices.was', {price: per(r, r.previous.price)}))}</div>` : ''}</td>
                    <td class="text-end text-nowrap">${esc(per(r, r.min.price))}<div class="small text-muted">${esc(r.min.supplier || '—')}</div></td>
                    <td class="text-end text-nowrap">${esc(per(r, r.max.price))}<div class="small text-muted">${esc(r.max.supplier || '—')}</div></td>
                    <td class="text-end">${r.suppliers}</td>
                    <td class="text-end"><button type="button" class="btn btn-sm btn-outline-primary text-nowrap" data-ph-row="${r.raw_material_id}"><i class="bi bi-graph-up"></i> ${esc(t('prices.history'))}</button></td></tr>`).join('')
                    || `<tr><td colspan="10" class="text-muted">${esc(t('prices.empty'))}</td></tr>`}</tbody></table></div></div></div>`;
    }

    function bindPrices() {
        bindSearch();
        el.querySelectorAll('[data-kind]').forEach(b => b.addEventListener('click', () => { state.kind = b.dataset.kind; writeHash(); draw(); }));
        el.querySelectorAll('[data-pmonths]').forEach(b => b.addEventListener('click', () => { state.pmonths = Number(b.dataset.pmonths); load(); }));
        el.querySelectorAll('tr[data-ph-row]').forEach(tr => tr.addEventListener('click', () => {
            const r = data.prices.rows.find(x => x.raw_material_id === Number(tr.dataset.phRow));
            if (r) openHistory(r.raw_material_id, r.name);
        }));
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
