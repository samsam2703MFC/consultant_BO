/*
 * Zakupy tygodnia (makiety 1 à 5) :
 *  - Kreator w 3 krokach : 1. co kupić (stan → produkcja → receptury, zapas +0 / +5 / +10 %, ostatnia najtańsza cena i dostawa),
 *    2. komu i jak (1 do 3 dostawców na pozycję, warunki dla wszystkich : płatność, dostawa, termin, cena za podwójną ilość),
 *    3. sprawdź i wyślij (jeden e-mail na dostawcę, w jego języku, z linkiem do wpisania cen) ;
 *  - Oferty : najtańsza ważna ★ (minimum zamówienia spełnione), podpowiedź « kup więcej, żeby spełnić minimum », oferta wpisana
 *    ręcznie, zamówienie u wybranych (zamówienia do dostawców e-mailem), przypomnienie, zakończenie ;
 *  - Wyniki : moje i zespołu (dyrektor zakładu) — miesiąc / kwartał / rok : udane zakupy, oszczędność, marża, czas, zwrot ;
 *  - Dostawcy pozycji : 1 do 3 dostawców na pozycję, najpierw pozycje z mniej niż 3 dostawcami (zadanie « znajdź dostawców »).
 * Wszystko przez API /supply/buy/… ; ilości w jednostkach bazowych (g, ml, szt), wyświetlane w jednostce surowca (kg, l…) ;
 * etykiety buy.* w supply.json.
 */
(async function () {
    'use strict';
    const el = document.getElementById('tfbBuy');
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
    const money = (v, d) => v === null || v === undefined ? '—' : `${ui.fmt(v, d ?? 0, api.lang)} zł`;
    const errText = (err) => ((err.body && err.body.errors) || []).map(x => x.message).join(' ') || err.message;
    const DAYS = {pl: ['nd.', 'pon.', 'wt.', 'śr.', 'czw.', 'pt.', 'sob.'], fr: ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.']};
    const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    const addDays = (s, k) => { const d = new Date(s + 'T12:00:00'); d.setDate(d.getDate() + k); return iso(d); };
    const today = iso(new Date());
    const dm = (s) => s ? `${Number(s.slice(8, 10))}.${s.slice(5, 7)}` : '—';
    const dayLabel = (s) => !s ? '—' : s === today ? t('buy.today') : s === addDays(today, 1) ? t('buy.tomorrow')
        : `${(DAYS[api.lang] || DAYS.pl)[new Date(s + 'T12:00:00').getDay()]} ${dm(s)}`;
    const dateTime = (s) => s ? `${dayLabel(s.slice(0, 10))} ${s.slice(11, 16)}` : '—';
    // Ilość w jednostce surowca (kg, l, szt) ; cena za jednostkę surowca (zł/kg).
    const digits = (v) => Math.abs(v) >= 100 ? 0 : Math.abs(v) >= 1 ? 1 : 3;
    const qty = (base, m) => base === null || base === undefined ? '—' : `${n(base / (m.factor || 1), digits(base / (m.factor || 1)))} ${m.unit}`;
    // Oszczędność : grosze przy małych kwotach.
    const gain = (v) => money(v, v !== null && v !== undefined && Math.abs(v) < 100 ? 2 : 0);
    const price = (perBase, m) => perBase === null || perBase === undefined ? '—' : n(perBase * (m.factor || 1), perBase * (m.factor || 1) < 1 ? 3 : 2);
    const packs = (l, m) => l.packages && l.package_size && Math.abs(l.package_size - (m.factor || 1)) > 1e-9
        ? t('buy.packs', {n: n(l.packages, 0), size: n(l.package_size / (m.factor || 1), 2), unit: m.unit}) : '';

    const hash = new URLSearchParams(location.hash.slice(1));
    const TABS = ['wizard', 'offers', 'results', 'suppliers'];
    const state = {
        tab: TABS.includes(hash.get('tab')) ? hash.get('tab') : null, date: hash.get('date'), safety: null, step: null, data: null,
        unchecked: new Set(), pick: {}, cond: null, preview: null, previewSupplier: null, buy: {}, offerForm: null,
        period: 'month', results: null, sup: null, supAll: false, supAdd: null, supLimit: 50, allSuppliers: null, addFor: null,
    };
    const round = () => state.data && state.data.round;
    const sent = () => round() && ['sent', 'ordered'].includes(round().status);

    function setHash() {
        const p = new URLSearchParams();
        if (state.tab && state.tab !== 'wizard') p.set('tab', state.tab);
        if (state.date) p.set('date', state.date);
        history.replaceState(null, '', p.toString() ? '#' + p.toString() : location.pathname + location.search);
    }

    function head() {
        const d = state.data;
        const r = round();
        const w = d ? d.week : null;
        const sub = w ? t('buy.subtitle', {week: w.iso_week, from: dm(w.week_start), to: dm(w.week_end)}) + (r ? ` · ${r.code} · ${t('buy.status.' + r.status)}` : '') : '';
        const offers = r && r.status !== 'draft' ? r.totals.with_offers : 0;
        const tab = (k, badge) => `<button type="button" class="${state.tab === k ? 'is-on' : ''}" data-tab="${k}">${esc(t('buy.tab.' + k))}${badge ? ` <span class="tfb-num tfb-buy-tabn">${esc(String(badge))}</span>` : ''}</button>`;
        return `<div class="tfb-head"><div><h3>${esc(t('buy.title'))}</h3><p class="text-muted mb-0">${esc(sub)}</p></div>
            <div class="tfb-head-actions"><div class="at-seg tfb-buy-tabs" role="tablist">${tab('wizard')}${tab('offers', offers)}${tab('results')}${tab('suppliers', d ? d.missing_suppliers : 0)}</div>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-lang-switch>${esc(t('lang_switch'))}</button></div></div>`;
    }

    async function load() {
        el.innerHTML = head() + `<p class="text-muted">${esc(t('loading'))}</p>`;
        try {
            const q = new URLSearchParams();
            if (state.date) q.set('date', state.date);
            if (state.safety !== null) q.set('safety', String(state.safety));
            state.data = await api.get('/supply/buy/week' + (q.toString() ? '?' + q.toString() : ''));
        } catch (err) {
            el.innerHTML = head() + `<div class="alert alert-danger">${esc(errText(err))}</div>`;
            return;
        }
        const r = round();
        if (!state.tab) state.tab = sent() ? 'offers' : 'wizard';
        if (state.safety === null) state.safety = state.data.proposal ? state.data.proposal.safety_pct : (r ? r.safety_pct : 5);
        if (r && r.status === 'draft') {
            if (state.step === null) state.step = r.requests.length ? 3 : 2;
            // Szkic : pozycje odznaczone = propozycja bez pozycji szkicu.
            const inDraft = new Set(r.lines.map(l => l.material.id));
            state.unchecked = new Set((state.data.proposal ? state.data.proposal.lines : []).filter(l => l.selected && !inDraft.has(l.material.id)).map(l => l.material.id));
        } else if (state.step === null || (r && r.status !== 'draft')) {
            state.step = 1;
        }
        state.cond = null;
        state.buy = {};
        draw();
    }

    function draw() {
        const y = window.scrollY;
        setHash();
        let body = '';
        if (state.tab === 'wizard') body = drawWizard();
        else if (state.tab === 'offers') body = drawOffers();
        else if (state.tab === 'results') body = drawResults();
        else body = drawSuppliers();
        el.innerHTML = head() + body;
        window.scrollTo(0, y);
    }

    // --- Kreator ---------------------------------------------------------------------------------------------------

    function stepper(cur) {
        const s = (k) => `<span class="tfb-buy-step ${k < cur ? 'is-done' : k === cur ? 'is-on' : ''}"><span class="tfb-buy-step-n">${k < cur ? '<i class="bi bi-check-lg"></i>' : k}</span>${esc(t('buy.step' + k))}</span>`;
        return `<div class="tfb-buy-stepper">${s(1)}<span class="tfb-buy-step-sep">›</span>${s(2)}<span class="tfb-buy-step-sep">›</span>${s(3)}</div>`;
    }

    function weekChips() {
        const d = state.data;
        const isThis = d.week.this;
        return `<div class="at-seg tfb-buy-weeks">${[['this', today], ['next', addDays(today, 7)]].map(([k, date]) =>
            `<button type="button" class="${(k === 'this') === isThis ? 'is-on' : ''}" data-week="${date}">${esc(t('buy.week_' + k))}</button>`).join('')}</div>`;
    }

    function drawWizard() {
        const r = round();
        if (sent()) return drawSent();
        const closedNote = r && r.status === 'closed' ? `<div class="alert alert-light small">${esc(t('buy.closed_note', {code: r.code}))}</div>` : '';
        if (!state.data.can_write) return `<div class="alert alert-light">${esc(t('buy.read_only'))}</div>`;
        if (state.step === 2 && r && r.status === 'draft') return stepper(2) + drawStep2();
        if (state.step === 3 && r && r.status === 'draft') return stepper(3) + drawStep3();
        return closedNote + drawStep1();
    }

    function drawStep1() {
        const d = state.data;
        const p = d.proposal;
        const tiles = d.products.slice(0, 7).map(x => `<div class="tfb-buy-tile"><strong>${esc(x.name)}</strong>
                <div class="small text-muted">${x.stock !== null ? esc(t('buy.tile_stock', {stock: n(x.stock, 0), min: x.min !== null ? n(x.min, 0) : '—'})) : esc(t('buy.tile_orders', {n: n(x.orders, 0)}))}</div>
                <div class="tfb-buy-tile-row"><span class="small text-muted">${esc(t('buy.to_produce'))}</span><span class="tfb-num"><strong>${esc(n(x.to_produce, 0))}</strong> ${esc(t('buy.cartons'))}</span></div></div>`).join('');
        const rest = d.products.slice(7);
        const restTile = rest.length ? `<div class="tfb-buy-tile"><strong>${esc(t('buy.more_products', {n: rest.length}))}</strong><div class="small text-muted">${esc(t('buy.with_orders'))}</div>
                <div class="tfb-buy-tile-row"><span class="small text-muted">${esc(t('buy.to_produce'))}</span><span class="tfb-num"><strong>${esc(n(rest.reduce((a, x) => a + x.to_produce, 0), 0))}</strong> ${esc(t('buy.cartons'))}</span></div></div>` : '';
        const lines = p ? p.lines : [];
        const on = (l) => l.quantity > 0 && !state.unchecked.has(l.material.id);
        const chosen = lines.filter(on);
        const value = chosen.reduce((a, l) => a + (l.value || 0), 0);
        const row = (l) => {
            const m = l.material;
            const off = l.quantity <= 0;
            return `<tr class="${off ? 'is-off' : ''}">
                <td class="tfb-buy-check">${off ? '<span class="tfb-buy-box is-off"></span>' : `<button type="button" class="tfb-buy-box ${on(l) ? 'is-on' : ''}" data-toggle-line="${m.id}" aria-pressed="${on(l)}" aria-label="${esc(m.name)}">${on(l) ? '<i class="bi bi-check-lg"></i>' : ''}</button>`}</td>
                <td><strong>${esc(m.name)}</strong>${off ? ` <span class="badge tfb-buy-ok">${esc(t('buy.enough'))}</span>` : ''}${m.packaging ? ` <span class="small text-muted">${esc(t('buy.packaging'))}</span>` : ''}</td>
                <td class="tfb-num text-end">${esc(l.stock !== null ? qty(l.stock, m) : '—')}</td>
                <td class="tfb-num text-end">${esc(qty(l.need, m))}</td>
                <td class="tfb-num text-end">${off ? '—' : `<strong>${esc(qty(l.quantity, m))}</strong><div class="small text-muted">${esc(packs(l, m))}</div>`}</td>
                <td>${l.last ? `<strong>${esc(l.last.supplier || '—')}</strong> · <span class="tfb-num">${esc(price(l.last.price, m))} zł/${esc(m.unit)}</span><div class="small text-muted">${esc(t('buy.last_on', {d: dm(l.last.day)}))}</div>` : `<span class="small text-muted">${esc(t('buy.no_price'))}</span>`}</td>
                <td class="text-end">${off ? '' : esc(dayLabel(l.arrives))}</td></tr>`;
        };
        const safety = `<div class="tfb-buy-safety"><span class="small text-muted">${esc(t('buy.safety'))}</span><div class="at-seg">${[0, 5, 10].map(s =>
            `<button type="button" class="${state.safety === s ? 'is-on' : ''}" data-safety="${s}">+${s} %</button>`).join('')}</div></div>`;
        return `<div class="tfb-buy-bar">${stepper(1)}${weekChips()}${safety}</div>
            <section class="card tfb-buy-card"><div class="card-body">
                <div class="tfb-buy-card-head"><h5><span class="tfb-buy-circle">1</span> ${esc(t('buy.produce_title'))}</h5><span class="small text-muted">${esc(t('buy.produce_hint'))}</span></div>
                ${d.products.length ? `<div class="tfb-buy-tiles">${tiles}${restTile}</div>` : `<p class="text-muted small mb-0">${esc(t('buy.no_products'))}</p>`}
            </div></section>
            <section class="card tfb-buy-card"><div class="card-body">
                <div class="tfb-buy-card-head"><h5><span class="tfb-buy-circle">2</span> ${esc(t('buy.buy_title'))}</h5><span class="small text-muted">${esc(t('buy.buy_hint', {pct: state.safety}))}</span></div>
                ${lines.length ? `<div class="table-responsive"><table class="table tfb-buy-table align-middle mb-0"><thead><tr><th></th><th>${esc(t('buy.col.item'))}</th>
                    <th class="text-end">${esc(t('buy.col.stock'))}</th><th class="text-end">${esc(t('buy.col.need'))}</th><th class="text-end">${esc(t('buy.col.buy'))}</th>
                    <th>${esc(t('buy.col.last'))}</th><th class="text-end">${esc(t('buy.col.arrives'))}</th></tr></thead><tbody>${lines.map(row).join('')}</tbody></table></div>`
                    : `<p class="text-muted mb-0">${esc(t('buy.nothing'))}</p>`}
                <div class="tfb-buy-foot"><span><strong class="tfb-num">${esc(t('buy.n_items', {n: chosen.length}))}</strong> · ${esc(t('buy.estimate'))} <strong class="tfb-num">${esc(money(value))}</strong> ${esc(t('buy.net'))}</span>
                    <button type="button" class="btn btn-primary btn-lg" data-step1-next ${chosen.length ? '' : 'disabled'}>${esc(t('buy.next_who'))} ›</button></div>
            </div></section>`;
    }

    // Krok 2 : dostawcy pozycji (1–3) i warunki.
    function picks(l) {
        if (state.pick[l.id]) return state.pick[l.id];
        const usable = l.candidates.filter(c => c.active && c.email);
        const base = l.suppliers.length && round().requests.length ? l.suppliers.map(s => s.id) : usable.slice(0, 3).map(c => c.id);
        state.pick[l.id] = base;
        return base;
    }

    function conditions() {
        if (state.cond) return state.cond;
        const r = round();
        state.cond = {pay_terms: r.pay_terms, delivery: r.delivery, deliver_by: r.deliver_by, reply_by: r.reply_by ? r.reply_by.slice(0, 16) : null, ask_double: r.ask_double};
        return state.cond;
    }

    function drawStep2() {
        const r = round();
        const c = conditions();
        const supplierCount = new Set(r.lines.flatMap(l => picks(l))).size;
        const chip = (l, s, on) => {
            const tag = s.source === 'category' ? t('buy.new_supplier') : s.last_day ? dm(s.last_day) : '';
            const disabled = !s.email || !s.active;
            return `<button type="button" class="tfb-buy-chip ${on ? 'is-on' : ''} ${s.source === 'category' ? 'is-new' : ''}" data-pick="${l.id}:${s.id}" ${disabled && !on ? 'disabled' : ''}>
                <span class="tfb-buy-box ${on ? 'is-on' : ''}">${on ? '<i class="bi bi-check-lg"></i>' : ''}</span><strong>${esc(s.name)}</strong>
                ${s.last_price !== null && s.last_price !== undefined ? `<span class="tfb-num">${esc(price(s.last_price, l.material))}</span>` : ''}
                ${!s.email ? `<span class="small text-danger">${esc(t('buy.no_email'))}</span>` : tag ? `<span class="small text-muted">· ${esc(tag)}</span>` : ''}</button>`;
        };
        const lineRow = (l) => {
            const sel = picks(l);
            const cands = l.candidates.slice();
            for (const id of sel) if (!cands.some(x => x.id === id)) {
                const s = (state.allSuppliers || []).find(x => x.id === id) || {id, name: '#' + id};
                cands.push({id, name: s.name, email: s.email ?? 'x', active: true, last_price: null, last_day: null, source: 'manual'});
            }
            const k = sel.length;
            return `<div class="tfb-buy-sline" data-line="${l.id}">
                <div class="tfb-buy-sline-head"><span><strong>${esc(l.material.name)}</strong> <span class="text-muted tfb-num">· ${esc(qty(l.quantity, l.material))}</span></span>
                    <span class="badge tfb-num ${k >= 3 ? 'tfb-buy-ok' : k === 0 ? 'tfb-buy-bad' : 'tfb-buy-warn'}">${esc(t('buy.k_of_3', {k}))}</span></div>
                <div class="tfb-buy-chips">${cands.map(s => chip(l, s, sel.includes(s.id))).join('')}
                    ${k < 3 ? (state.addFor === l.id ? supplierSelect(l, sel) : `<button type="button" class="tfb-buy-chip is-add" data-add-supplier="${l.id}">+ ${esc(t('buy.add_supplier'))}</button>`) : ''}</div></div>`;
        };
        const seg = (field, values, label) => `<div class="at-seg tfb-buy-seg">${values.map(v => `<button type="button" class="${c[field] === v ? 'is-on' : ''}" data-cond="${field}" data-value="${esc(v)}">${esc(label(v))}</button>`).join('')}</div>`;
        const wkStart = r.week_start;
        const byOptions = [...new Set([addDays(wkStart, -1) > today ? addDays(wkStart, -1) : addDays(today, 1), wkStart])];
        const replyOptions = [`${addDays(today, 1)} 12:00`];
        if (new Date().getHours() < 15) replyOptions.push(`${today} 16:00`);
        const dateChips = (field, options, fmt, type) => `<div class="tfb-buy-chips">${options.map(v => `<button type="button" class="tfb-buy-chip is-plain ${c[field] === v ? 'is-on' : ''}" data-cond="${field}" data-value="${esc(v)}">${esc(fmt(v))}</button>`).join('')}
            <input type="${type}" class="form-control tfb-buy-date ${c[field] && !options.includes(c[field]) ? 'is-on' : ''}" data-cond-input="${field}" value="${esc(c[field] && !options.includes(c[field]) ? (type === 'date' ? c[field] : c[field].replace(' ', 'T')) : '')}" aria-label="${esc(t('buy.other_date'))}"></div>`;
        return `<div class="tfb-buy-two">
            <section class="card tfb-buy-card"><div class="card-body">
                <div class="tfb-buy-card-head"><h5>${esc(t('buy.s2.title'))}</h5><span class="small text-muted">${esc(t('buy.s2.hint'))}</span></div>
                <p class="small"><strong>${esc(t('buy.s2.rule'))}</strong> ${esc(t('buy.s2.rule_more'))}</p>
                ${r.lines.map(lineRow).join('')}
            </div></section>
            <section class="card tfb-buy-card"><div class="card-body">
                <div class="tfb-buy-card-head"><h5>${esc(t('buy.cond.title'))}</h5></div>
                <h6 class="tfb-buy-label">${esc(t('buy.cond.pay'))}</h6>${seg('pay_terms', ['7', '14', '30', 'proforma'], v => t('buy.pay.' + v))}
                <p class="small text-muted">${esc(t('buy.cond.proforma_hint'))}</p>
                <h6 class="tfb-buy-label">${esc(t('buy.cond.delivery'))}</h6>${seg('delivery', ['delivery', 'pickup'], v => t('buy.delivery.' + v))}
                <h6 class="tfb-buy-label">${esc(t('buy.cond.by'))}</h6>${dateChips('deliver_by', byOptions, dayLabel, 'date')}
                <h6 class="tfb-buy-label">${esc(t('buy.cond.reply'))}</h6>${dateChips('reply_by', replyOptions, dateTime, 'datetime-local')}
                <h6 class="tfb-buy-label">${esc(t('buy.cond.double_title'))}</h6>
                <label class="tfb-buy-checkline"><button type="button" class="tfb-buy-box ${c.ask_double ? 'is-on' : ''}" data-cond-double aria-pressed="${c.ask_double}">${c.ask_double ? '<i class="bi bi-check-lg"></i>' : ''}</button> ${esc(t('buy.cond.double'))}</label>
            </div></section></div>
            <div class="card tfb-buy-card"><div class="card-body tfb-buy-foot">
                <span><strong class="tfb-num">${esc(t('buy.s2.count', {n: supplierCount}))}</strong> ${esc(t('buy.s2.count_more'))}</span>
                <span class="tfb-buy-foot-actions"><button type="button" class="btn btn-outline-secondary btn-lg" data-step="1">‹ ${esc(t('buy.back'))}</button>
                <button type="button" class="btn btn-primary btn-lg" data-step2-next>${esc(t('buy.next_send'))} ›</button></span></div></div>`;
    }

    function supplierSelect(l, sel) {
        const all = state.allSuppliers;
        if (!all) return `<span class="small text-muted">${esc(t('loading'))}</span>`;
        const opts = all.filter(s => !sel.includes(s.id));
        return `<span class="tfb-buy-addbox"><select class="form-select form-select-sm" data-add-select="${l.id}"><option value="">${esc(t('buy.choose_supplier'))}</option>
            ${opts.map(s => `<option value="${s.id}" ${s.email ? '' : 'disabled'}>${esc(s.name)}${s.email ? '' : ' — ' + esc(t('buy.no_email'))}</option>`).join('')}</select>
            <a class="small" href="${api.root}/material-suppliers/new" target="_blank" rel="noopener">${esc(t('buy.new_supplier_card'))}</a>
            <button type="button" class="btn btn-sm btn-link" data-add-cancel>×</button></span>`;
    }

    // Krok 3 : podgląd e-maili i wysyłka.
    function drawStep3() {
        const r = round();
        const reqs = r.requests;
        if (!state.previewSupplier && reqs.length) state.previewSupplier = reqs[0].supplier.id;
        const pv = state.preview;
        const list = reqs.map(q => `<button type="button" class="tfb-buy-req ${state.previewSupplier === q.supplier.id ? 'is-on' : ''}" data-preview="${q.supplier.id}">
                <span><strong>${esc(q.supplier.name)}</strong> <span class="small text-muted">${esc(t('buy.n_lines', {n: q.lines}))}</span></span>
                <span class="badge tfb-buy-lang">${esc((q.language || 'pl').toUpperCase())}</span>${q.email ? '' : `<span class="small text-danger">${esc(t('buy.no_email'))}</span>`}</button>`).join('');
        const preview = !pv ? `<p class="text-muted">${esc(t('loading'))}</p>` : `<div class="tfb-buy-mailhead">
                <div><strong>${esc(t('buy.mail.to'))}</strong> ${esc(pv.supplier.name)} <span class="text-muted">&lt;${esc(pv.recipient || '—')}&gt;</span></div>
                <div><strong>${esc(t('buy.mail.subject'))}</strong> ${esc(pv.subject)}</div></div>
            <pre class="tfb-buy-mailbody">${esc(pv.body)}</pre>
            <p class="small text-muted mb-0">${esc(t('buy.mail.footer'))}</p>`;
        return `<div class="tfb-buy-mail">
            <section class="card tfb-buy-card"><div class="card-body"><h5>${esc(t('buy.s3.n', {n: reqs.length}))}</h5><p class="small text-muted">${esc(t('buy.s3.click'))}</p>${list}</div></section>
            <section class="card tfb-buy-card"><div class="card-body">${preview}</div></section></div>
            <div class="card tfb-buy-card"><div class="card-body tfb-buy-foot">
                <span><strong>${esc(t('buy.s3.reply', {d: dateTime(r.reply_by)}))}</strong> ${esc(t('buy.s3.reply_more'))}</span>
                <span class="tfb-buy-foot-actions"><button type="button" class="btn btn-outline-secondary btn-lg" data-step="2">‹ ${esc(t('buy.back'))}</button>
                <button type="button" class="btn btn-success btn-lg" data-send ${reqs.length ? '' : 'disabled'}>${esc(t('buy.s3.send', {n: reqs.length}))}</button></span></div></div>
            <p class="small"><button type="button" class="btn btn-link btn-sm text-danger p-0" data-delete-round>${esc(t('buy.delete_draft'))}</button></p>`;
    }

    // Zakupy wysłane : stan zapytań.
    function drawSent() {
        const r = round();
        const waiting = r.requests.filter(q => !q.responded_at);
        return `<section class="card tfb-buy-card"><div class="card-body">
            <div class="tfb-buy-card-head"><h5><span class="tfb-buy-box is-on"><i class="bi bi-check-lg"></i></span> ${esc(t('buy.sent_title', {code: r.code}))}</h5>
                <span class="badge tfb-buy-ok">${esc(t('buy.responded', {n: r.totals.responded, total: r.totals.requests}))}</span></div>
            <p class="small text-muted">${esc(t('buy.sent_info', {sent: dateTime(r.sent_at), reply: dateTime(r.reply_by)}))}</p>
            <div class="table-responsive"><table class="table align-middle mb-2"><thead><tr><th>${esc(t('buy.col.supplier'))}</th><th class="text-end">${esc(t('buy.col.lines'))}</th>
                <th>${esc(t('buy.col.state'))}</th><th>${esc(t('buy.col.link'))}</th></tr></thead><tbody>
            ${r.requests.map(q => `<tr><td><strong>${esc(q.supplier.name)}</strong> <span class="badge tfb-buy-lang">${esc((q.language || 'pl').toUpperCase())}</span></td>
                <td class="text-end tfb-num">${esc(String(q.lines))}</td>
                <td>${q.responded_at ? `<span class="badge tfb-buy-ok">${esc(t('buy.req.responded', {d: dateTime(q.responded_at)}))}</span>`
                    : q.opened_at ? `<span class="badge tfb-buy-warn">${esc(t('buy.req.opened'))}</span>` : `<span class="badge tfb-buy-muted">${esc(t('buy.req.waiting'))}</span>`}
                    ${q.reminded_at ? `<span class="small text-muted">${esc(t('buy.req.reminded', {d: dateTime(q.reminded_at)}))}</span>` : ''}</td>
                <td>${q.link ? `<button type="button" class="btn btn-sm btn-link p-0" data-copy="${esc(q.link)}"><i class="bi bi-link-45deg"></i> ${esc(t('buy.copy_link'))}</button>` : ''}</td></tr>`).join('')}
            </tbody></table></div>
            <div class="tfb-buy-foot"><span class="small text-muted">${esc(t('buy.sent_hint'))}</span><span class="tfb-buy-foot-actions">
                ${waiting.length && state.data.can_write ? `<button type="button" class="btn btn-outline-secondary btn-lg" data-remind>${esc(t('buy.remind', {n: waiting.length}))}</button>` : ''}
                <button type="button" class="btn btn-primary btn-lg" data-tab="offers">${esc(t('buy.to_offers'))} ›</button></span></div>
        </div></section>`;
    }

    // --- Oferty ----------------------------------------------------------------------------------------------------

    function choice(l) {
        if (state.buy[l.id]) return state.buy[l.id];
        const c = {on: l.best_offer_id !== null, offer_id: l.best_offer_id, quantity: l.quantity};
        state.buy[l.id] = c;
        return c;
    }

    function chosenPrice(l, c) {
        if (c.offer_id === null) return l.last ? l.last.price : null;
        const o = l.offers.find(x => x.id === c.offer_id);
        return o ? o.price : null;
    }

    function drawOffers() {
        const r = round();
        if (!r || !sent()) {
            return `<section class="card tfb-buy-card"><div class="card-body"><p class="mb-2">${esc(r && r.status === 'closed' ? t('buy.offers_closed', {code: r.code}) : t('buy.offers_none'))}</p>
                <button type="button" class="btn btn-primary" data-tab="wizard">${esc(t('buy.tab.wizard'))} ›</button></div></section>`;
        }
        const w = state.data.can_write;
        const waitingReq = r.requests.filter(q => !q.responded_at);
        let total = 0;
        let count = 0;
        const hints = [];
        const row = (l) => {
            const m = l.material;
            if (l.order) {
                const o = l.order;
                return `<tr class="is-ordered"><td><strong>${esc(m.name)}</strong><div class="small text-muted tfb-num">${esc(qty(o.quantity, m))}</div></td>
                    <td class="text-end tfb-num">${esc(l.last ? price(l.last.price, m) : '—')}</td>
                    <td><span class="badge tfb-buy-ok">${esc(t('buy.ordered'))}</span> <strong>${esc(o.supplier.name)}</strong> <span class="tfb-num">${esc(price(o.price, m))}</span>
                        ${o.po ? `<a class="small" href="${api.root}/workshop/supply#tab=orders&order=${o.po.id}">${esc(o.po.code)}</a>` : ''}</td>
                    <td class="text-end tfb-num tfb-buy-save">${esc(gain(o.saving))}</td><td></td></tr>`;
            }
            const c = choice(l);
            const p = chosenPrice(l, c);
            const saving = c.on && p !== null && l.last ? (l.last.price - p) * c.quantity : null;
            if (c.on) { count++; total += saving || 0; }
            if (l.hint && l.hint.quantity && !(c.offer_id === l.hint.offer_id && c.quantity >= l.hint.quantity)) hints.push(l);
            const offered = new Set(l.offers.map(o => o.supplier.id));
            const chip = (o) => {
                const minText = o.min_qty !== null ? `${n(o.min_qty, 0)} ${m.unit}` : o.min_value !== null ? money(o.min_value) : o.min_note;
                const sel = c.on && c.offer_id === o.id;
                return `<button type="button" class="tfb-buy-offer ${o.id === l.best_offer_id ? 'is-best' : ''} ${sel ? 'is-on' : ''} ${o.below_min ? 'is-below' : ''}" data-offer="${l.id}:${o.id}" ${w ? '' : 'disabled'}>
                    ${o.id === l.best_offer_id ? '★ ' : ''}<strong>${esc(o.supplier.name)}</strong> <span class="tfb-num">${esc(price(o.price, m))}</span>
                    ${o.price_double !== null ? `<span class="small text-muted tfb-num">×2 : ${esc(price(o.price_double, m))}</span>` : ''}
                    ${minText ? `<span class="tfb-buy-min ${o.below_min ? 'is-bad' : ''}">${esc(t(o.below_min ? 'buy.min_short' : 'buy.min', {v: minText}))}</span>` : ''}
                    ${o.delivery_date ? `<span class="small text-muted">${esc(dayLabel(o.delivery_date))}</span>` : ''}</button>`;
            };
            const lastChip = l.last && l.last.supplier ? `<button type="button" class="tfb-buy-offer is-last ${c.on && c.offer_id === null ? 'is-on' : ''}" data-offer="${l.id}:last" ${w ? '' : 'disabled'}>
                ${esc(t('buy.last_supplier'))} <strong>${esc(l.last.supplier.name)}</strong> <span class="tfb-num">${esc(price(l.last.price, m))}</span></button>` : '';
            const waiting = l.suppliers.filter(s => !offered.has(s.id)).map(s => `<span class="tfb-buy-offer is-waiting">${esc(s.name)} <span class="small">${esc(t('buy.waits'))}</span></span>`).join('');
            const form = state.offerForm === l.id ? offerForm(l) : '';
            return `<tr><td><strong>${esc(m.name)}</strong><div class="small text-muted tfb-num">${esc(qty(c.quantity, m))}${c.quantity !== l.quantity ? ` (${esc(t('buy.instead', {q: qty(l.quantity, m)}))})` : ''}</div></td>
                <td class="text-end tfb-num">${esc(l.last ? price(l.last.price, m) : '—')}</td>
                <td><div class="tfb-buy-offers">${l.offers.map(chip).join('')}${waiting}${!l.offers.length ? lastChip : ''}
                    ${w ? `<button type="button" class="tfb-buy-offer is-add" data-offer-form="${l.id}">+ ${esc(t('buy.enter_offer'))}</button>` : ''}</div>${form}</td>
                <td class="text-end tfb-num tfb-buy-save">${saving !== null ? esc(gain(saving)) : '—'}</td>
                <td class="text-center">${w ? `<button type="button" class="tfb-buy-box ${c.on ? 'is-on' : ''}" data-buy-toggle="${l.id}" aria-pressed="${c.on}" ${c.offer_id === null && !l.last ? 'disabled' : ''}>${c.on ? '<i class="bi bi-check-lg"></i>' : ''}</button>` : ''}</td></tr>`;
        };
        const rows = r.lines.map(row).join('');
        const hintRows = hints.map(l => {
            const o = l.offers.find(x => x.id === l.hint.offer_id);
            const m = l.material;
            return `<div class="tfb-buy-hint"><strong>${esc(m.name)}</strong>
                <span>${esc(t('buy.hint', {supplier: o.supplier.name, price: price(o.price, m), min: qty(l.hint.quantity, m), need: qty(l.quantity, m), extra: qty(l.hint.extra, m),
                    weeks: l.hint.weeks !== null ? n(l.hint.weeks, 0) : '—', saving: gain(l.hint.saving)}))}</span>
                ${w ? `<button type="button" class="btn btn-sm btn-light" data-hint="${l.id}">${esc(t('buy.hint_yes', {q: qty(l.hint.quantity, m)}))}</button>` : ''}</div>`;
        }).join('');
        return `<section class="card tfb-buy-card"><div class="card-body">
            <div class="tfb-buy-card-head"><h5>${esc(t('buy.offers_title'))}</h5><span class="small text-muted">${esc(t('buy.offers_info', {n: r.totals.responded, total: r.totals.requests, d: dateTime(r.reply_by)}))}</span></div>
            <div class="table-responsive"><table class="table tfb-buy-table tfb-buy-otable align-middle mb-2"><thead><tr><th>${esc(t('buy.col.item'))}</th><th class="text-end">${esc(t('buy.col.last_price'))}</th>
                <th>${esc(t('buy.col.offers'))}</th><th class="text-end">${esc(t('buy.col.saving'))}</th><th class="text-center">${esc(t('buy.col.take'))}</th></tr></thead><tbody>${rows}</tbody></table></div>
            ${hintRows}
            <div class="tfb-buy-foot"><span>${esc(t('buy.saving_total'))} <strong class="tfb-num tfb-buy-save tfb-buy-big">${esc(gain(total))}</strong> ${esc(t('buy.vs_last'))}</span>
                <span class="tfb-buy-foot-actions">${w && waitingReq.length ? `<button type="button" class="btn btn-outline-secondary" data-remind>${esc(t('buy.remind', {n: waitingReq.length}))}</button>` : ''}
                ${w ? `<button type="button" class="btn btn-outline-secondary" data-close-round>${esc(t('buy.close'))}</button>
                <button type="button" class="btn btn-primary btn-lg" data-order ${count ? '' : 'disabled'}>${esc(t('buy.order', {n: count}))}</button>` : ''}</span></div>
        </div></section>`;
    }

    function offerForm(l) {
        const m = l.material;
        const sups = l.suppliers.concat(l.candidates.filter(c => !l.suppliers.some(s => s.id === c.id)));
        return `<div class="tfb-buy-oform" data-oform="${l.id}">
            <select class="form-select form-select-sm" data-field="supplier_id">${sups.map(s => `<option value="${s.id}">${esc(s.name)}</option>`).join('')}</select>
            <input class="form-control form-control-sm tfb-num" data-field="price" inputmode="decimal" placeholder="${esc(t('buy.f.price', {unit: m.unit}))}">
            <input class="form-control form-control-sm tfb-num" data-field="min_qty" inputmode="decimal" placeholder="${esc(t('buy.f.min_qty', {unit: m.unit}))}">
            <input class="form-control form-control-sm tfb-num" data-field="min_value" inputmode="decimal" placeholder="${esc(t('buy.f.min_value'))}">
            <input class="form-control form-control-sm" data-field="min_note" maxlength="60" placeholder="${esc(t('buy.f.min_note'))}">
            <input class="form-control form-control-sm" type="date" data-field="delivery_date" aria-label="${esc(t('buy.f.delivery'))}">
            <button type="button" class="btn btn-sm btn-primary" data-oform-save="${l.id}">${esc(t('buy.save'))}</button>
            <button type="button" class="btn btn-sm btn-link" data-oform-cancel>×</button></div>`;
    }

    // --- Wyniki ----------------------------------------------------------------------------------------------------

    function drawResults() {
        const d = state.results;
        const seg = `<div class="at-seg">${['month', 'quarter', 'year'].map(p => `<button type="button" class="${state.period === p ? 'is-on' : ''}" data-period="${p}">${esc(t('buy.period.' + p))}</button>`).join('')}</div>`;
        if (!d || d.period !== state.period) {
            loadResults();
            return `<section class="card tfb-buy-card"><div class="card-body"><div class="tfb-buy-card-head"><h5>${esc(t('buy.res.mine'))}</h5>${seg}</div><p class="text-muted">${esc(t('loading'))}</p></div></section>`;
        }
        const me = d.me;
        const tile = (value, label, cls) => `<div class="tfb-buy-kpi ${cls || ''}"><div class="tfb-buy-kpi-v tfb-num">${esc(value)}</div><div class="small text-muted">${esc(label)}</div></div>`;
        const tiles = `<div class="tfb-buy-kpis">
            ${tile(t('buy.res.x_of_y', {x: me.successful, y: me.decisions}), t('buy.res.successful'))}
            ${tile(gain(me.savings), t('buy.res.savings'), 'is-ok')}
            ${tile(me.margin_pp !== null ? `${me.margin_pp >= 0 ? '+' : ''}${n(me.margin_pp, 2)} pp` : '—', t('buy.res.margin'))}
            ${tile(me.roi !== null ? `×${n(me.roi, 1)}` : '—', me.hourly_cost === null ? t('buy.res.roi_no_cost') : t('buy.res.roi'))}</div>
            <p class="small text-muted mb-0">${esc(t('buy.res.time', {h: ui.duration(me.hours * 3600, api), emails: me.emails, offers: me.offers, rounds: me.rounds, cost: money(me.cost)}))}</p>`;
        const months = d.months.length ? `<div class="tfb-buy-months">${d.months.map(x => {
            const max = Math.max(...d.months.map(y => Math.abs(y.savings)), 1);
            return `<div class="tfb-buy-month"><span class="small text-muted">${esc(x.month.slice(5))}.${esc(x.month.slice(2, 4))}</span><span class="tfb-buy-month-bar" style="width:${Math.round(Math.max(0, x.savings) / max * 100)}%"></span><span class="tfb-num small">${esc(gain(x.savings))}</span></div>`;
        }).join('')}</div>` : '';
        const team = d.team === null ? '' : `<section class="card tfb-buy-card"><div class="card-body">
            <div class="tfb-buy-card-head"><h5>${esc(t('buy.res.team'))}</h5><span class="small text-muted">${esc(t('buy.res.year', {v: gain(d.year_savings)}))}</span></div>
            ${d.team.length ? `<div class="table-responsive"><table class="table tfb-buy-table align-middle mb-0"><thead><tr><th>${esc(t('buy.res.person'))}</th>
                <th class="text-end">${esc(t('buy.res.rounds'))}</th><th class="text-end">${esc(t('buy.res.offers'))}</th><th class="text-end">${esc(t('buy.res.ok'))}</th>
                <th class="text-end">${esc(t('buy.res.savings_col'))}</th><th class="text-end">${esc(t('buy.res.margin_col'))}</th><th class="text-end">${esc(t('buy.res.time_col'))}</th><th class="text-end">${esc(t('buy.res.roi_col'))}</th></tr></thead>
            <tbody>${d.team.map(x => `<tr><td><strong>${esc(x.user.name)}</strong></td><td class="text-end tfb-num">${x.rounds}</td><td class="text-end tfb-num">${x.offers}</td>
                <td class="text-end tfb-num">${x.successful} / ${x.decisions}</td><td class="text-end tfb-num tfb-buy-save">${esc(gain(x.savings))}</td>
                <td class="text-end tfb-num">${x.margin_pp !== null ? esc(`${x.margin_pp >= 0 ? '+' : ''}${n(x.margin_pp, 2)} pp`) : '—'}</td>
                <td class="text-end tfb-num text-muted">${esc(ui.duration(x.hours * 3600, api))} · ${esc(t('buy.res.n_mails', {n: x.emails}))}</td>
                <td class="text-end tfb-num"><strong>${x.roi !== null ? esc('×' + n(x.roi, 1)) : '—'}</strong></td></tr>`).join('')}
            ${d.team_total ? `<tr class="tfb-buy-total"><td>${esc(t('buy.res.total'))}</td><td class="text-end tfb-num">${d.team_total.rounds}</td><td class="text-end tfb-num">${d.team_total.offers}</td>
                <td class="text-end tfb-num">${d.team_total.successful} / ${d.team_total.decisions}</td><td class="text-end tfb-num tfb-buy-save">${esc(gain(d.team_total.savings))}</td>
                <td class="text-end tfb-num">${d.team_total.margin_pp !== null ? esc(`${d.team_total.margin_pp >= 0 ? '+' : ''}${n(d.team_total.margin_pp, 2)} pp`) : '—'}</td>
                <td class="text-end tfb-num text-muted">${esc(ui.duration(d.team_total.hours * 3600, api))} · ${esc(t('buy.res.n_mails', {n: d.team_total.emails}))}</td>
                <td class="text-end tfb-num"><strong>${d.team_total.roi !== null ? esc('×' + n(d.team_total.roi, 1)) : '—'}</strong></td></tr>` : ''}</tbody></table></div>`
                : `<p class="text-muted mb-0">${esc(t('buy.res.team_empty'))}</p>`}
        </div></section>`;
        return `<div class="tfb-buy-res">
            <section class="card tfb-buy-card"><div class="card-body"><div class="tfb-buy-card-head"><h5>${esc(t('buy.res.mine'))}</h5>${seg}</div>${tiles}${months}</div></section></div>
            ${team}
            <p class="small text-muted">${esc(t('buy.res.explain'))}</p>`;
    }

    async function loadResults() {
        try {
            state.results = await api.get('/supply/buy/results?period=' + state.period);
        } catch (err) {
            state.results = null;
            ui.notify(false, errText(err));
            return;
        }
        if (state.tab === 'results') draw();
    }

    // --- Dostawcy pozycji (1–3) ------------------------------------------------------------------------------------

    function drawSuppliers() {
        const d = state.sup;
        if (!d) {
            loadSuppliers();
            return `<p class="text-muted">${esc(t('loading'))}</p>`;
        }
        const all = d.rows.filter(r => state.supAll || r.suppliers.length < d.max);
        const rows = all.slice(0, state.supLimit);
        const w = d.can_write;
        const row = (r) => {
            const k = r.suppliers.length;
            return `<tr><td><strong>${esc(r.name)}</strong><div class="small text-muted">${esc(r.category || '')}${r.packaging ? ' · ' + esc(t('buy.packaging')) : ''}</div></td>
                <td><span class="badge tfb-num ${k >= 3 ? 'tfb-buy-ok' : k <= 1 ? 'tfb-buy-bad' : 'tfb-buy-warn'}">${k} / 3</span></td>
                <td><div class="tfb-buy-chips">${r.suppliers.map(s => `<span class="tfb-buy-chip is-plain">${esc(s.name)}${s.email ? '' : ` <span class="small text-danger">${esc(t('buy.no_email'))}</span>`}
                    ${w && k > 1 ? `<button type="button" class="btn btn-link btn-sm p-0 ms-1" data-sup-remove="${r.id}:${s.id}" aria-label="${esc(t('buy.remove'))}">×</button>` : ''}</span>`).join('')}</div></td>
                <td class="text-end tfb-num text-nowrap">${esc(t('buy.per_week', {v: n(r.weekly_use / (r.factor || 1), digits(r.weekly_use / (r.factor || 1))), unit: r.unit}))}</td>
                <td class="text-end">${w && k < 3 ? (state.supAdd === r.id ? `<span class="tfb-buy-addbox"><select class="form-select form-select-sm" data-sup-select="${r.id}"><option value="">${esc(t('buy.choose_supplier'))}</option>
                    ${d.suppliers.filter(s => !r.suppliers.some(x => x.id === s.id)).map(s => `<option value="${s.id}">${esc(s.name)}${s.email ? '' : ' — ' + esc(t('buy.no_email'))}</option>`).join('')}</select>
                    <button type="button" class="btn btn-sm btn-link" data-sup-cancel>×</button></span>`
                    : `<button type="button" class="btn btn-outline-secondary text-nowrap" data-sup-add="${r.id}">+ ${esc(t('buy.add_supplier'))}</button>`) : ''}</td></tr>`;
        };
        return `<section class="card tfb-buy-card"><div class="card-body">
            <div class="tfb-buy-card-head"><h5>${esc(t('buy.sup.title', {n: d.missing}))}</h5>
                <div class="at-seg"><button type="button" class="${state.supAll ? '' : 'is-on'}" data-sup-all="0">${esc(t('buy.sup.missing'))}</button><button type="button" class="${state.supAll ? 'is-on' : ''}" data-sup-all="1">${esc(t('buy.sup.all'))}</button></div></div>
            <p class="small text-muted">${esc(t('buy.sup.hint', {weeks: d.weeks}))}</p>
            ${rows.length ? `<div class="table-responsive"><table class="table tfb-buy-table align-middle mb-2"><thead><tr><th>${esc(t('buy.col.item'))}</th><th>${esc(t('buy.sup.col_n'))}</th>
                <th>${esc(t('buy.sup.col_current'))}</th><th class="text-end">${esc(t('buy.sup.col_use'))}</th><th></th></tr></thead><tbody>${rows.map(row).join('')}</tbody></table></div>
                ${all.length > rows.length ? `<button type="button" class="btn btn-outline-secondary w-100 mb-2" data-sup-more>${esc(t('buy.sup.more', {n: all.length - rows.length}))}</button>` : ''}`
                : `<p class="text-muted mb-0">${esc(t('buy.sup.done'))}</p>`}
            <p class="small text-muted mb-0">${esc(t('buy.sup.new'))} <a href="${api.root}/material-suppliers/new">${esc(t('buy.new_supplier_card'))}</a></p>
        </div></section>`;
    }

    async function loadSuppliers() {
        try {
            state.sup = await api.get('/supply/buy/suppliers');
        } catch (err) {
            ui.notify(false, errText(err));
            return;
        }
        if (state.tab === 'suppliers') draw();
    }

    async function ensureAllSuppliers() {
        if (state.allSuppliers) return;
        if (!state.sup) state.sup = await api.get('/supply/buy/suppliers');
        state.allSuppliers = state.sup.suppliers;
    }

    async function loadPreview() {
        const r = round();
        if (!r || !state.previewSupplier) return;
        try {
            state.preview = await api.get(`/supply/buy/rounds/${r.id}/preview?supplier=${state.previewSupplier}`);
        } catch (err) {
            state.preview = null;
            ui.notify(false, errText(err));
            return;
        }
        draw();
    }

    // --- Akcje -----------------------------------------------------------------------------------------------------

    const busy = (b) => { if (!b) return; b.disabled = true; b.insertAdjacentHTML('afterbegin', '<span class="spinner-border spinner-border-sm me-1"></span>'); };
    const replaceRound = (r) => { state.data.round = r; };

    async function act(button, fn) {
        busy(button);
        try {
            await fn();
        } catch (err) {
            ui.notify(false, errText(err));
            draw();
        }
    }

    el.addEventListener('click', async (e) => {
        const b = e.target.closest('button, [data-tab]');
        if (!b || !el.contains(b)) return;
        const r = round();
        if (b.matches('[data-lang-switch]')) { await api.setLang(api.lang === 'pl' ? 'fr' : 'pl'); load(); return; }
        if (b.dataset.tab) { state.tab = b.dataset.tab; if (state.tab === 'suppliers') state.sup = null; draw(); return; }
        if (b.dataset.week) { state.date = b.dataset.week; state.step = null; state.safety = null; state.pick = {}; state.preview = null; state.previewSupplier = null; load(); return; }
        if (b.dataset.safety !== undefined) { state.safety = Number(b.dataset.safety); load(); return; }
        if (b.dataset.toggleLine) {
            const id = Number(b.dataset.toggleLine);
            state.unchecked.has(id) ? state.unchecked.delete(id) : state.unchecked.add(id);
            draw();
            return;
        }
        if (b.matches('[data-step1-next]')) {
            const lines = state.data.proposal.lines.filter(l => l.quantity > 0 && !state.unchecked.has(l.material.id)).map(l => ({material_id: l.material.id, quantity: l.quantity}));
            await act(b, async () => {
                const res = r && r.status === 'draft'
                    ? await api.patch(`/supply/buy/rounds/${r.id}`, {lines, safety_pct: state.safety})
                    : await api.post('/supply/buy/rounds', {week_start: state.data.week.week_start, safety_pct: state.safety, lines});
                replaceRound(res);
                state.step = 2;
                state.cond = null;
                window.scrollTo(0, 0);
                draw();
            });
            return;
        }
        if (b.dataset.step) { state.step = Number(b.dataset.step); if (state.step === 1) { load(); return; } draw(); window.scrollTo(0, 0); return; }
        if (b.dataset.pick) {
            const [lineId, sid] = b.dataset.pick.split(':').map(Number);
            const l = r.lines.find(x => x.id === lineId);
            const sel = picks(l);
            if (sel.includes(sid)) state.pick[lineId] = sel.filter(x => x !== sid);
            else if (sel.length >= 3) { ui.notify(false, t('buy.max3')); return; }
            else state.pick[lineId] = [...sel, sid];
            draw();
            return;
        }
        if (b.dataset.addSupplier) {
            state.addFor = Number(b.dataset.addSupplier);
            draw();
            try { await ensureAllSuppliers(); } catch (err) { ui.notify(false, errText(err)); }
            draw();
            return;
        }
        if (b.matches('[data-add-cancel]')) { state.addFor = null; draw(); return; }
        if (b.dataset.cond) { conditions()[b.dataset.cond] = b.dataset.value; draw(); return; }
        if (b.matches('[data-cond-double]')) { conditions().ask_double = !conditions().ask_double; draw(); return; }
        if (b.matches('[data-step2-next]')) {
            const missing = r.lines.filter(l => !picks(l).length);
            if (missing.length) { ui.notify(false, t('buy.s2.missing', {items: missing.slice(0, 3).map(l => l.material.name).join(', ')})); return; }
            const c = conditions();
            const suppliers = {};
            for (const l of r.lines) suppliers[l.id] = picks(l);
            await act(b, async () => {
                replaceRound(await api.patch(`/supply/buy/rounds/${r.id}`, {suppliers, pay_terms: c.pay_terms, delivery: c.delivery, deliver_by: c.deliver_by, reply_by: c.reply_by, ask_double: !!c.ask_double}));
                state.step = 3;
                state.preview = null;
                state.previewSupplier = null;
                window.scrollTo(0, 0);
                draw();
                loadPreview();
            });
            return;
        }
        if (b.dataset.preview) { state.previewSupplier = Number(b.dataset.preview); state.preview = null; draw(); loadPreview(); return; }
        if (b.matches('[data-send]')) {
            await act(b, async () => {
                replaceRound(await api.post(`/supply/buy/rounds/${r.id}/send`));
                ui.notify(true, t('buy.sent_ok', {n: round().requests.length}));
                state.tab = 'offers';
                window.scrollTo(0, 0);
                draw();
            });
            return;
        }
        if (b.matches('[data-delete-round]')) {
            if (!window.confirm(t('buy.confirm_delete'))) return;
            await act(b, async () => { await api.del(`/supply/buy/rounds/${r.id}`); state.step = null; state.pick = {}; load(); });
            return;
        }
        if (b.matches('[data-remind]')) {
            await act(b, async () => {
                const res = await api.post(`/supply/buy/rounds/${r.id}/remind`);
                ui.notify(true, t('buy.reminded', {n: res.reminded}));
                delete res.reminded;
                replaceRound(res);
                draw();
            });
            return;
        }
        if (b.dataset.copy) {
            try { await navigator.clipboard.writeText(b.dataset.copy); ui.notify(true, t('buy.copied')); } catch (err) { window.prompt(t('buy.copy_link'), b.dataset.copy); }
            return;
        }
        if (b.dataset.offer) {
            const [lineId, oid] = b.dataset.offer.split(':');
            const l = r.lines.find(x => x.id === Number(lineId));
            const c = choice(l);
            const o = oid === 'last' ? null : l.offers.find(x => x.id === Number(oid));
            if (o && o.below_min) {
                state.buy[l.id] = {on: true, offer_id: o.id, quantity: o.min_quantity ?? l.quantity};
            } else {
                state.buy[l.id] = {on: true, offer_id: o ? o.id : null, quantity: c.offer_id === (o ? o.id : null) ? c.quantity : l.quantity};
            }
            draw();
            return;
        }
        if (b.dataset.buyToggle) { const c = choice(r.lines.find(x => x.id === Number(b.dataset.buyToggle))); c.on = !c.on; draw(); return; }
        if (b.dataset.hint) {
            const l = r.lines.find(x => x.id === Number(b.dataset.hint));
            state.buy[l.id] = {on: true, offer_id: l.hint.offer_id, quantity: l.hint.quantity};
            draw();
            return;
        }
        if (b.dataset.offerForm) { state.offerForm = Number(b.dataset.offerForm); draw(); return; }
        if (b.matches('[data-oform-cancel]')) { state.offerForm = null; draw(); return; }
        if (b.dataset.oformSave) {
            const box = el.querySelector(`[data-oform="${b.dataset.oformSave}"]`);
            const v = (f) => box.querySelector(`[data-field="${f}"]`).value.trim();
            const body = {line_id: Number(b.dataset.oformSave), supplier_id: Number(v('supplier_id')), price: ui.num(v('price'))};
            for (const f of ['min_qty', 'min_value']) if (v(f) !== '') body[f] = ui.num(v(f));
            if (v('min_note') !== '') body.min_note = v('min_note');
            if (v('delivery_date') !== '') body.delivery_date = v('delivery_date');
            busy(b);
            try {
                replaceRound(await api.post(`/supply/buy/rounds/${r.id}/offers`, body));
                state.offerForm = null;
                delete state.buy[body.line_id];
                ui.notify(true, t('buy.offer_saved'));
                draw();
            } catch (err) {
                b.disabled = false;
                b.querySelector('.spinner-border')?.remove();
                const left = ui.fieldErrors(box, err);
                if (left.length) ui.notify(false, left.join(' '));
            }
            return;
        }
        if (b.matches('[data-order]')) {
            const lines = r.lines.filter(l => !l.order && choice(l).on).map(l => ({line_id: l.id, offer_id: choice(l).offer_id, quantity: choice(l).quantity}));
            if (!lines.length || !window.confirm(t('buy.confirm_order', {n: lines.length}))) return;
            await act(b, async () => {
                const res = await api.post(`/supply/buy/rounds/${r.id}/order`, {lines});
                const orders = res.orders || [];
                delete res.orders;
                replaceRound(res);
                state.buy = {};
                state.results = null;
                const drafts = orders.filter(o => !o.sent);
                ui.notify(true, t('buy.ordered_ok', {n: orders.length}) + (drafts.length ? ' ' + t('buy.ordered_drafts', {names: drafts.map(o => o.supplier).join(', ')}) : ''));
                draw();
            });
            return;
        }
        if (b.matches('[data-close-round]')) {
            if (!window.confirm(t('buy.confirm_close'))) return;
            await act(b, async () => { replaceRound(await api.post(`/supply/buy/rounds/${r.id}/close`)); state.step = null; load(); });
            return;
        }
        if (b.dataset.period) { state.period = b.dataset.period; draw(); return; }
        if (b.dataset.supAll !== undefined) { state.supAll = b.dataset.supAll === '1'; state.supLimit = 50; draw(); return; }
        if (b.matches('[data-sup-more]')) { state.supLimit += 50; draw(); return; }
        if (b.dataset.supAdd) { state.supAdd = Number(b.dataset.supAdd); draw(); return; }
        if (b.matches('[data-sup-cancel]')) { state.supAdd = null; draw(); return; }
        if (b.dataset.supRemove) {
            const [mid, sid] = b.dataset.supRemove.split(':').map(Number);
            const row = state.sup.rows.find(x => x.id === mid);
            await saveSuppliers(b, row, row.suppliers.map(s => s.id).filter(x => x !== sid));
        }
    });

    async function saveSuppliers(b, row, ids) {
        busy(b);
        try {
            const res = await api.put(`/supply/buy/materials/${row.id}/suppliers`, {supplier_ids: ids});
            const before = row.suppliers.length;
            row.suppliers = res.suppliers;
            if (before < 3 && row.suppliers.length >= 3) state.sup.missing = Math.max(0, state.sup.missing - 1);
            if (before >= 3 && row.suppliers.length < 3) state.sup.missing++;
            if (state.data) state.data.missing_suppliers = state.sup.missing;
            state.supAdd = null;
            state.pick = {};
            ui.notify(true, t('buy.sup.saved', {name: row.name}));
        } catch (err) {
            ui.notify(false, errText(err));
        }
        draw();
    }

    el.addEventListener('change', async (e) => {
        const s = e.target;
        if (s.dataset.addSelect && s.value) {
            const lineId = Number(s.dataset.addSelect);
            const l = round().lines.find(x => x.id === lineId);
            const sel = picks(l);
            if (sel.length >= 3) { ui.notify(false, t('buy.max3')); return; }
            state.pick[lineId] = [...sel, Number(s.value)];
            state.addFor = null;
            draw();
            return;
        }
        if (s.dataset.condInput) {
            const v = s.value ? (s.type === 'date' ? s.value : s.value.replace('T', ' ').slice(0, 16)) : null;
            if (v) { conditions()[s.dataset.condInput] = v; draw(); }
            return;
        }
        if (s.dataset.supSelect && s.value) {
            const row = state.sup.rows.find(x => x.id === Number(s.dataset.supSelect));
            await saveSuppliers(s, row, [...row.suppliers.map(x => x.id), Number(s.value)]);
        }
    });

    window.addEventListener('hashchange', () => {
        const h = new URLSearchParams(location.hash.slice(1));
        const tab = TABS.includes(h.get('tab')) ? h.get('tab') : 'wizard';
        if (tab !== state.tab) { state.tab = tab; draw(); }
    });

    await load();
    if (state.tab === 'wizard' && state.step === 3) loadPreview();
})();
