/*
 * Finanse (stanowisko Administracja i fakturowanie, makiety 3 i 4) :
 *  - « Faktury i koszty » (data-view = workspace) : 1. przypisz nowe pozycje (podpowiedź surowca jednym kliknięciem, faktury, które
 *    wyglądają na usługi → Finanse), 2. zatwierdź faktury (gotowe jednym kliknięciem, ostrzeżenia), 3. sprawdź wzrosty cen ponad
 *    cenę bazową (skutek na miesiąc, receptury ; akceptuj / zostaw starą / zapytanie ofertowe ; drobne jednym kliknięciem) ;
 *  - « Koszty usług miesiąca » (data-view = costs) : faktury usług po kategoriach kalkulatora kosztów, porównanie z poprzednim
 *    miesiącem (brakuje → dodaj koszt albo « nie w tym miesiącu »), zatwierdzenie, zamknięcie → kalkulator kosztów (Rentowność).
 * Wszystko przez API /finance/… i /supply/invoices/… ; etykiety fin.* w supply.json.
 */
(async function () {
    'use strict';
    const el = document.getElementById('tfbFinance');
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
    const VIEW = el.dataset.view === 'costs' ? 'costs' : 'workspace';
    const t = (k, p) => api.t(k, p);
    const n = (v, d) => v === null || v === undefined ? '—' : ui.fmt(v, d ?? 1, api.lang);
    const money = (v, c) => v === null || v === undefined ? '—' : `${n(v, 2)} ${!c || c === 'PLN' ? 'zł' : c}`;
    const fullDay = (s) => s ? `${s.slice(8, 10)}.${s.slice(5, 7)}.${s.slice(0, 4)}` : '—';
    const errText = (err) => ((err.body && err.body.errors) || []).map(x => x.message).join(' ') || err.message;
    const MONTHS = {pl: ['styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'],
        fr: ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre']};
    const monthLabel = (ym) => ym ? `${(MONTHS[api.lang] || MONTHS.pl)[Number(ym.slice(5, 7)) - 1]} ${ym.slice(0, 4)}` : '—';
    const supplyLink = (id) => `${api.root}/workshop/supply#tab=invoices&inv=${id}`;
    // Przelicznik podpowiedzi : jednostki bazowe surowca na jednostkę faktury, wyświetlany w kg / l od 1000 g / ml.
    const factorText = (unit, s) => {
        if (!s || !s.factor) return '';
        const big = (s.base_unit === 'g' || s.base_unit === 'ml') && s.factor >= 1000;
        return `1 ${unit || 'szt'} = ${n(big ? s.factor / 1000 : s.factor, 3)} ${big ? (s.base_unit === 'g' ? 'kg' : 'l') : s.base_unit}`;
    };
    const state = {month: (new URLSearchParams(location.hash.slice(1))).get('m') || null, data: null, adding: null, editing: null};

    function head() {
        const title = VIEW === 'costs' ? t('fin.costs.title') : t('fin.ws.title');
        const sub = VIEW === 'costs' ? t('fin.costs.subtitle') : t('fin.ws.subtitle');
        return `<div class="tfb-head"><div><h3>${esc(title)}</h3><p class="text-muted mb-0">${esc(sub)}</p></div>
            <div class="tfb-head-actions"><button type="button" class="btn btn-outline-secondary btn-sm" data-lang-switch>${esc(t('lang_switch'))}</button></div></div>`;
    }

    async function load() {
        el.innerHTML = head() + `<p class="text-muted">${esc(t('loading'))}</p>`;
        try {
            if (VIEW === 'costs') {
                state.data = await api.get('/finance/services' + (state.month ? `?month=${state.month}` : ''));
                state.month = state.data.month;
                history.replaceState(null, '', '#m=' + state.month);
            } else {
                state.data = await api.get('/finance/workspace');
            }
        } catch (err) {
            el.innerHTML = head() + `<div class="alert alert-danger">${esc(errText(err))}</div>`;
            return;
        }
        draw();
    }

    function draw() {
        const y = window.scrollY;
        el.innerHTML = head() + (VIEW === 'costs' ? drawCosts() : drawWorkspace());
        (VIEW === 'costs' ? bindCosts : bindWorkspace)();
        window.scrollTo(0, y);
    }

    // --- Faktury i koszty (makieta 4) ------------------------------------------------------------------------------

    function drawWorkspace() {
        const d = state.data;
        const w = d.can_write;
        const sm = d.service_month;
        const ups = d.price_ups;
        const big = ups.items.filter(u => !u.small);
        const small = ups.items.filter(u => u.small);
        const step = (k, title, count, body) => `<section class="card tfb-fin-step"><div class="card-body">
                <div class="tfb-fin-step-head"><span class="tfb-fin-step-n">${k}</span><h5 class="mb-0">${esc(title)}</h5>
                    <span class="badge ${count ? 'bg-warning text-dark' : 'tfb-inv-accepted'} ms-auto tfb-num">${count ? esc(String(count)) : '<i class="bi bi-check-lg"></i>'}</span></div>${body}</div></section>`;
        const map = d.map;
        const mapBody = `${map.suspects.length ? `<h6 class="tfb-fin-sub">${esc(t('fin.ws.suspects'))}</h6>
                <ul class="tfb-fin-list is-stacked">${map.suspects.map(s => `<li><div><strong>${esc(s.seller || '—')}</strong> <span class="small text-muted">${esc(s.number || '')} · ${esc(fullDay(s.issue_date))}</span>
                    <div class="small text-muted">${esc(t('inv.guess', {label: s.guess.label}))} · <span class="tfb-num">${esc(money(s.total_net, s.currency))}</span></div></div>
                    ${w ? `<div class="tfb-fin-actions"><button type="button" class="btn btn-sm btn-outline-primary" data-classify="${s.id}" data-action="service">${esc(t('inv.to_finance'))}</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-classify="${s.id}" data-action="reject">${esc(t('inv.reject'))}</button></div>` : ''}</li>`).join('')}</ul>` : ''}
            ${map.invoices.map(i => `<h6 class="tfb-fin-invhead"><a href="${supplyLink(i.id)}">${esc(i.seller || '—')}</a> <span class="small text-muted">${esc(i.number || '')}</span>
                    ${i.supplier_known ? '' : `<span class="badge bg-warning text-dark">${esc(t('inv.no_supplier'))}</span>`}</h6>
                <ul class="tfb-fin-list is-stacked">${i.lines.map(l => `<li><div><span>${esc(l.name)}</span> <span class="small text-muted tfb-num">${esc(n(l.quantity, 3))} ${esc(l.unit || '')}</span>
                    ${l.suggestion ? `<div class="small"><i class="bi bi-arrow-return-right"></i> <strong>${esc(l.suggestion.name)}</strong> <span class="text-muted">${esc(factorText(l.unit, l.suggestion))}</span></div>`
                        : `<div class="small text-muted">${esc(t('fin.ws.no_suggestion'))}</div>`}</div>
                    <div class="tfb-fin-actions">${w && l.suggestion && l.suggestion.factor ? `<button type="button" class="btn btn-sm btn-primary" data-map="${i.id}:${l.id}">${esc(t('fin.ws.map'))}</button>` : ''}
                        <a class="btn btn-sm btn-link" href="${supplyLink(i.id)}">${esc(t('fin.ws.open'))}</a></div></li>`).join('')}
                    ${i.more ? `<li class="small text-muted">${esc(t('fin.ws.more_lines', {n: i.more}))}</li>` : ''}</ul>`).join('')}
            ${map.invoices_total > map.invoices.length ? `<p class="small mb-0"><a href="${api.root}/workshop/supply#tab=invoices">${esc(t('fin.ws.more_invoices', {n: map.invoices_total - map.invoices.length}))}</a></p>` : ''}
            ${!map.suspects.length && !map.invoices.length ? `<p class="text-muted small mb-0">${esc(t('fin.ws.map_done'))}</p>` : ''}`;
        const v = d.validate;
        const validateBody = `<div class="tfb-fin-big"><span class="tfb-num">${v.ready.length}</span><small>${esc(t('fin.ws.ready', {net: money(v.ready_net)}))}</small></div>
            ${w && v.ready.length ? `<button type="button" class="btn btn-success w-100 mb-2" data-process-ready><i class="bi bi-check2-all"></i> ${esc(t('inv.act.process_ready', {n: v.ready.length}))}</button>` : ''}
            <ul class="tfb-fin-list">${v.ready.slice(0, 6).map(i => `<li><a href="${supplyLink(i.id)}">${esc(i.seller || '—')} · ${esc(i.number || '')}</a><span class="tfb-num">${esc(money(i.total_net, i.currency))}</span></li>`).join('')}</ul>
            ${v.warnings.length ? `<h6 class="tfb-fin-sub">${esc(t('fin.ws.warnings'))}</h6><ul class="tfb-fin-list">${v.warnings.map(i => `<li><div><a href="${supplyLink(i.id)}">${esc(i.seller || '—')} · ${esc(i.number || '')}</a>
                <div class="small text-muted">${esc(i.warnings.map(x => t('inv.warn.' + x)).join(' · '))}</div></div><span class="tfb-num">${esc(money(i.total_net, i.currency))}</span></li>`).join('')}</ul>` : ''}
            ${v.blocked ? `<p class="small text-muted mb-1">${esc(t('fin.ws.blocked', {n: v.blocked}))}</p>` : ''}
            <p class="small mb-0"><a href="${api.root}/finances/costs">${esc(t('fin.ws.services', {n: v.services_open}))}</a></p>`;
        const upRow = (u) => `<li class="tfb-fin-up"><div><strong>${esc(u.name)}</strong> <span class="badge ${u.small ? 'bg-light text-dark' : 'bg-danger'} tfb-num">+${esc(n(u.change_pct, 1))} %</span>
                <div class="small tfb-num">${esc(money(u.base_price * u.factor))} → <strong>${esc(money(u.new_price * u.factor))}</strong> / ${esc(u.unit)}</div>
                <div class="small text-muted">${esc(t('fin.ws.monthly', {v: money(u.monthly_extra)}))} · ${esc(t('fin.ws.recipes', {n: u.recipes}))} · <a href="${supplyLink(u.invoice_id)}">${esc(u.seller || '')} ${esc(u.number || '')}</a></div></div>
            ${w ? `<div class="tfb-fin-actions"><button type="button" class="btn btn-sm btn-outline-success" data-decide="${u.invoice_id}:${u.raw_material_id}" data-decision="accept">${esc(t('fin.ws.accept'))}</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-decide="${u.invoice_id}:${u.raw_material_id}" data-decision="keep">${esc(t('fin.ws.keep'))}</button>
                <a class="btn btn-sm btn-link" href="${api.root}/purchasing/tenders">${esc(t('fin.ws.negotiate'))}</a></div>` : ''}</li>`;
        const upsBody = `${ups.items.length ? `<div class="tfb-fin-big"><span class="tfb-num">${esc(money(ups.monthly_total))}</span><small>${esc(t('fin.ws.monthly_total', {days: ups.days}))}</small></div>` : ''}
            <ul class="tfb-fin-list is-stacked">${big.map(upRow).join('')}</ul>
            ${small.length ? `<details class="tfb-fin-small"><summary>${esc(t('fin.ws.small', {n: small.length, pct: n(ups.small_pct, 0)}))}</summary><ul class="tfb-fin-list is-stacked">${small.map(upRow).join('')}</ul>
                ${w ? `<button type="button" class="btn btn-sm btn-outline-success" data-accept-small>${esc(t('fin.ws.accept_small', {n: small.length}))}</button>` : ''}</details>` : ''}
            ${!ups.items.length ? `<p class="text-muted small mb-0">${esc(t('fin.ws.ups_done'))}</p>` : ''}`;
        return `<a class="tfb-fin-month ${sm.closed ? 'is-done' : ''}" href="${api.root}/finances/costs#m=${esc(sm.month)}">
                <i class="bi ${sm.closed ? 'bi-check-circle' : 'bi-calendar2-range'}"></i>
                <span>${esc(t(sm.closed ? 'fin.ws.month_closed' : sm.progress.total ? 'fin.ws.month_open' : 'fin.ws.month_empty', {month: sm.label, done: sm.progress.done, total: sm.progress.total}))}</span>
                ${!sm.closed && (sm.open || sm.missing) ? `<span class="small text-muted">${esc(t('fin.ws.month_todo', {open: sm.open, missing: sm.missing}))}</span>` : ''}<span class="ms-auto">›</span></a>
            <div class="tfb-fin-steps">
                ${step(1, t('fin.ws.step1'), map.lines_total + map.suspects.length, mapBody)}
                ${step(2, t('fin.ws.step2'), v.ready.length + v.warnings.length, validateBody)}
                ${step(3, t('fin.ws.step3'), ups.items.length, upsBody)}
            </div>`;
    }

    function bindWorkspace() {
        const d = state.data;
        const busy = (b) => { b.disabled = true; b.insertAdjacentHTML('afterbegin', '<span class="spinner-border spinner-border-sm me-1"></span>'); };
        el.querySelectorAll('[data-classify]').forEach(b => b.addEventListener('click', () => window.TfbInvoiceClassify.open({api, invoiceId: Number(b.dataset.classify), action: b.dataset.action, onDone: load})));
        el.querySelectorAll('[data-map]').forEach(b => b.addEventListener('click', async () => {
            const [inv, lineId] = b.dataset.map.split(':').map(Number);
            const line = d.map.invoices.find(i => i.id === inv).lines.find(l => l.id === lineId);
            busy(b);
            try {
                await api.put(`/supply/invoices/${inv}/lines/${lineId}/map`, {raw_material_id: line.suggestion.raw_material_id, factor: line.suggestion.factor, remember: true});
                ui.notify(true, t('inv.mapped_ok', {name: line.suggestion.name}));
            } catch (err) { ui.notify(false, errText(err)); }
            load();
        }));
        el.querySelector('[data-process-ready]')?.addEventListener('click', async (e) => {
            if (!window.confirm(t('inv.confirm_ready', {n: d.validate.ready.length}))) return;
            busy(e.currentTarget);
            try {
                const res = await api.post('/supply/invoices/process-ready', {});
                ui.notify(!res.errors.length, t('inv.ready_done', {n: res.processed.length, left: res.left}));
            } catch (err) { ui.notify(false, errText(err)); }
            load();
        });
        el.querySelectorAll('[data-decide]').forEach(b => b.addEventListener('click', async () => {
            const [inv, mid] = b.dataset.decide.split(':').map(Number);
            // Nowa (wyższa) cena bazowa : okno potwierdzenia [210] ; « Bez zmian » od razu.
            if (b.dataset.decision === 'accept') {
                b.disabled = true;
                if (await window.TfbInvoicePriceUp.open({api, invoiceId: inv, materialIds: [mid]})) load();
                else b.disabled = false;
                return;
            }
            busy(b);
            try {
                await api.post(`/supply/invoices/${inv}/price-decisions`, {decision: b.dataset.decision, raw_material_ids: [mid]});
                ui.notify(true, t('inv.price_up.' + b.dataset.decision + '_ok', {n: 1}));
            } catch (err) { ui.notify(false, errText(err)); }
            load();
        }));
        el.querySelector('[data-accept-small]')?.addEventListener('click', async (e) => {
            const byInvoice = {};
            d.price_ups.items.filter(u => u.small).forEach(u => { (byInvoice[u.invoice_id] = byInvoice[u.invoice_id] || []).push(u.raw_material_id); });
            if (!window.confirm(t('fin.ws.confirm_small', {n: d.price_ups.small_count}))) return;
            busy(e.currentTarget);
            let ok = 0;
            for (const [inv, ids] of Object.entries(byInvoice)) {
                try { await api.post(`/supply/invoices/${inv}/price-decisions`, {decision: 'accept', raw_material_ids: ids}); ok += ids.length; } catch (err) { ui.notify(false, errText(err)); }
            }
            if (ok) ui.notify(true, t('inv.price_up.accept_ok', {n: ok}));
            load();
        });
    }

    // --- Koszty usług miesiąca (makieta 3) -------------------------------------------------------------------------

    const STATE_BADGE = {done: ['tfb-inv-accepted', 'bi-check-lg'], none: ['bg-light text-dark', 'bi-dash'], open: ['tfb-inv-service_open', 'bi-hourglass-split'],
        missing: ['bg-danger', 'bi-exclamation-lg'], empty: ['bg-light text-dark', 'bi-dash']};

    function catSelect(list, value, attrs) {
        const groups = {};
        list.forEach(x => { (groups[x.group_label] = groups[x.group_label] || []).push(x); });
        return `<select class="form-select form-select-sm" ${attrs}><option value="">${esc(t('cls.pick_category'))}</option>${Object.entries(groups).map(([g, items]) => `<optgroup label="${esc(g)}">${items.map(x => `<option value="${esc(x.code)}" ${x.code === value ? 'selected' : ''}>${esc(x.label)}</option>`).join('')}</optgroup>`).join('')}</select>`;
    }

    function addForm(d) {
        const a = state.adding;
        if (!a) return '';
        return `<div class="card tfb-fin-add"><div class="card-body"><h6>${esc(t('fin.costs.add_title'))}</h6>
            <form class="row g-2 align-items-end" data-add-form>
                <div class="col-md-3"><label class="form-label small mb-0">${esc(t('fin.f.seller'))}</label><input class="form-control form-control-sm" name="seller_name" data-field="seller_name" value="${esc(a.seller_name || '')}" required maxlength="255"></div>
                <div class="col-md-2"><label class="form-label small mb-0">${esc(t('fin.f.nip'))}</label><input class="form-control form-control-sm" name="seller_nip" data-field="seller_nip" value="${esc(a.seller_nip || '')}" maxlength="20"></div>
                <div class="col-md-2"><label class="form-label small mb-0">${esc(t('fin.f.number'))}</label><input class="form-control form-control-sm" name="number" data-field="number" maxlength="80"></div>
                <div class="col-md-2"><label class="form-label small mb-0">${esc(t('fin.f.date'))}</label><input class="form-control form-control-sm" type="date" name="issue_date" data-field="issue_date" required value="${esc(d.month + '-01')}"></div>
                <div class="col-md-3"><label class="form-label small mb-0">${esc(t('cls.category'))}</label>${catSelect(d.category_list, a.cost_category || '', 'name="cost_category" data-field="cost_category" required')}</div>
                <div class="col-md-2"><label class="form-label small mb-0">${esc(t('cls.month'))}</label><input class="form-control form-control-sm" type="month" name="service_month" data-field="service_month" value="${esc(d.month)}"></div>
                <div class="col-md-2"><label class="form-label small mb-0">${esc(t('fin.f.net'))}</label><input class="form-control form-control-sm text-end" name="total_net" data-field="total_net" inputmode="decimal" required></div>
                <div class="col-md-2"><label class="form-label small mb-0">${esc(t('fin.f.gross'))}</label><input class="form-control form-control-sm text-end" name="total_gross" data-field="total_gross" inputmode="decimal"></div>
                <div class="col-md-3"><label class="form-label small mb-0">${esc(t('fin.f.file'))}</label><input class="form-control form-control-sm" type="file" name="file" data-field="content_base64" accept=".pdf,image/jpeg,image/png"></div>
                <div class="col-md-3 d-flex gap-2"><button type="submit" class="btn btn-sm btn-primary">${esc(t('fin.costs.add_save'))}</button><button type="button" class="btn btn-sm btn-outline-secondary" data-add-cancel>${esc(t('cls.cancel'))}</button></div>
            </form><p class="small text-muted mb-0 mt-2">${esc(t('fin.costs.add_help'))}</p></div></div>`;
    }

    function invoiceRow(d, c, i) {
        const w = d.can_write && !d.closed;
        if (state.editing === i.id) {
            return `<tr class="tfb-fin-inv is-editing" data-edit-row="${i.id}"><td colspan="6"><div class="row g-2 align-items-end">
                <div class="col-md-4"><label class="form-label small mb-0">${esc(t('cls.category'))}</label>${catSelect(d.category_list, i.cost_category, 'data-e="cost_category" data-field="cost_category"')}</div>
                <div class="col-md-2"><label class="form-label small mb-0">${esc(t('cls.month'))}</label><input class="form-control form-control-sm" type="month" data-e="service_month" data-field="service_month" value="${esc(i.service_month || '')}"></div>
                <div class="col-md-2"><label class="form-label small mb-0">${esc(t('fin.f.amount'))}</label><input class="form-control form-control-sm text-end" data-e="cost_amount" data-field="cost_amount" inputmode="decimal" value="${i.cost_amount_set ? esc(String(i.cost_amount).replace('.', ',')) : ''}" placeholder="${esc(i.currency === 'PLN' && i.total_net !== null ? String(i.total_net).replace('.', ',') : '')}"></div>
                <div class="col-md-4 d-flex gap-2"><button type="button" class="btn btn-sm btn-primary" data-save-edit="${i.id}">${esc(t('fin.costs.save'))}</button><button type="button" class="btn btn-sm btn-outline-secondary" data-cancel-edit>${esc(t('cls.cancel'))}</button>
                    <button type="button" class="btn btn-sm btn-link text-secondary ms-auto" data-to-goods="${i.id}">${esc(t('inv.to_goods'))}</button></div></div></td></tr>`;
        }
        return `<tr class="tfb-fin-inv"><td><span class="tfb-fin-seller">${esc(i.supplier || i.seller_name || '—')}</span>
                <div class="small text-muted">${esc(i.number || '—')} · ${esc(fullDay(i.issue_date))}${i.source === 'manual' ? ' · ' + esc(t('inv.source.manual')) : ''}${i.by_rule ? ` · <span class="badge tfb-inv-rule">${esc(t('inv.by_rule'))}</span>` : ''}</div></td>
            <td class="text-end tfb-num">${i.cost_amount === null ? `<span class="badge bg-warning text-dark">${esc(t('fin.costs.no_amount', {v: money(i.total_net, i.currency)}))}</span>` : esc(money(i.cost_amount))}
                ${i.cost_amount_set && i.total_net !== null && i.cost_amount !== i.total_net ? `<div class="small text-muted">${esc(t('fin.costs.of', {v: money(i.total_net, i.currency)}))}</div>` : ''}</td>
            <td>${i.file ? `<button type="button" class="btn btn-sm btn-link p-0" data-file="${i.id}" title="${esc(i.file.filename)}"><i class="bi bi-file-earmark-text"></i></button>` : ''}</td>
            <td>${i.status === 'accepted' ? `<span class="badge tfb-inv-accepted"><i class="bi bi-check-lg"></i> ${esc(t('inv.status.service_accepted'))}</span>` : `<span class="badge tfb-inv-service_open">${esc(t('inv.status.service_open'))}</span>`}</td>
            <td class="text-end text-nowrap" colspan="2">${w ? `${i.status === 'open' ? `<button type="button" class="btn btn-sm btn-success" data-accept="${i.id}">${esc(t('fin.costs.accept'))}</button>`
                : `<button type="button" class="btn btn-sm btn-link text-secondary" data-reopen-inv="${i.id}">${esc(t('fin.costs.to_check'))}</button>`}
                <button type="button" class="btn btn-sm btn-outline-secondary" data-edit="${i.id}" title="${esc(t('fin.costs.edit'))}" aria-label="${esc(t('fin.costs.edit'))}"><i class="bi bi-pencil"></i></button>
                ${i.source === 'manual' ? `<button type="button" class="btn btn-sm btn-outline-danger" data-delete="${i.id}" title="${esc(t('fin.costs.delete'))}" aria-label="${esc(t('fin.costs.delete'))}"><i class="bi bi-trash"></i></button>` : ''}` : ''}</td></tr>`;
    }

    function drawCosts() {
        const d = state.data;
        const w = d.can_write && !d.closed;
        const chips = `<div class="tfb-fin-chips" role="tablist">${d.months.map(m => {
            const st = m.closed ? `<i class="bi bi-check-circle"></i> ${esc(t('fin.costs.chip_closed'))}` : m.missing ? esc(t('fin.costs.chip_missing', {n: m.missing}))
                : m.open ? esc(t('fin.costs.chip_open', {n: m.open})) : !m.count ? esc(t('fin.costs.chip_none')) : m.over ? esc(t('fin.costs.chip_ready')) : esc(t('fin.costs.chip_running'));
            return `<button type="button" role="tab" class="tfb-fin-chip ${m.current ? 'is-on' : ''} ${m.closed ? 'is-closed' : m.missing ? 'is-missing' : ''}" data-month="${m.month}" aria-selected="${m.current}">
                <strong>${esc(m.label)}</strong><small>${st}</small><small class="tfb-num">${m.count ? esc(money(m.total)) : '&nbsp;'}</small></button>`;
        }).join('')}</div>`;
        const pctDone = d.progress.total ? Math.round(d.progress.done / d.progress.total * 100) : 100;
        const reasons = d.blockers.map(b => t('fin.costs.block.' + b, {open: d.totals.open, missing: d.totals.missing, n: d.totals.no_amount})).join(' · ');
        const top = d.closed ? `<div class="alert alert-success d-flex flex-wrap align-items-center gap-2"><i class="bi bi-lock"></i>
                <span>${esc(t('fin.costs.closed', {at: fullDay((d.closed.at || '').slice(0, 10)), by: d.closed.by || '—', total: money(d.closed.total)}))}</span>
                <a class="ms-auto" href="${api.root}/cost-calculator">${esc(t('fin.costs.calc'))}</a>
                ${d.can_write ? `<button type="button" class="btn btn-sm btn-outline-secondary" data-reopen>${esc(t('fin.costs.reopen'))}</button>` : ''}</div>`
            : `<div class="tfb-fin-progress"><div><strong class="tfb-num">${esc(t('fin.costs.progress', {done: d.progress.done, total: d.progress.total}))}</strong>
                    <div class="progress" role="progressbar" aria-valuenow="${pctDone}" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar ${pctDone === 100 ? 'bg-success' : ''}" style="width:${pctDone}%"></div></div></div>
                <div class="tfb-fin-total"><span class="tfb-num">${esc(money(d.totals.net))}</span><small>${esc(t('fin.costs.vs_prev', {month: d.prev_label, v: money(d.totals.prev)}))}</small></div>
                ${w ? `<div class="tfb-fin-bar-actions"><button type="button" class="btn btn-sm btn-outline-primary" data-add><i class="bi bi-plus-lg"></i> ${esc(t('fin.costs.add'))}</button>
                    ${d.totals.open ? `<button type="button" class="btn btn-sm btn-success" data-accept-all><i class="bi bi-check2-all"></i> ${esc(t('fin.costs.accept_all', {n: d.totals.open}))}</button>` : ''}
                    <button type="button" class="btn btn-sm btn-primary" data-close ${d.can_close ? '' : 'disabled'} title="${esc(reasons)}"><i class="bi bi-lock"></i> ${esc(t('fin.costs.close', {month: d.label}))}</button></div>` : ''}
                ${!d.can_close && reasons ? `<p class="small text-muted mb-0 tfb-fin-reasons">${esc(reasons)}</p>` : ''}</div>`;
        let lastGroup = null;
        const rows = d.categories.filter(c => c.state !== 'empty' || c.invoices.length).map(c => {
            const [cls, icon] = STATE_BADGE[c.state] || STATE_BADGE.empty;
            const group = c.group_label && c.group_label !== lastGroup ? `<tr class="tfb-fin-group"><th colspan="6">${esc(c.group_label)}</th></tr>` : '';
            lastGroup = c.group_label || lastGroup;
            const diff = c.diff_pct === null ? '' : ` <span class="small ${Math.abs(c.diff_pct) >= 15 ? 'text-danger' : 'text-muted'}">${c.diff_pct > 0 ? '+' : ''}${esc(n(c.diff_pct, 0))} %</span>`;
            const calc = !d.closed && c.calc && c.calc.manual > 0 && c.total > 0 ? `<div class="small text-muted">${esc(t('fin.costs.calc_replace', {v: money(c.calc.manual)}))}</div>`
                : d.closed && c.pushed ? `<div class="small text-success">${esc(t('fin.costs.pushed', {v: money(c.pushed)}))}</div>` : '';
            const missing = c.missing.map(m => `<tr class="tfb-fin-missing"><td><span class="tfb-fin-seller">${esc(m.seller_name || '—')}</span><div class="small text-danger">${esc(t('fin.costs.missing', {month: d.prev_label, v: money(m.prev_amount)}))}</div></td>
                <td></td><td></td><td><span class="badge bg-danger">${esc(t('fin.costs.missing_badge'))}</span></td>
                <td class="text-end text-nowrap" colspan="2">${w ? `<button type="button" class="btn btn-sm btn-outline-primary" data-add-missing="${esc(c.code)}|${esc(m.seller_key)}">${esc(t('fin.costs.add'))}</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-none="${esc(c.code)}|${esc(m.seller_key)}">${esc(t('fin.costs.none'))}</button>` : ''}</td></tr>`).join('');
            const marked = c.marked.map(m => `<tr class="tfb-fin-marked"><td><span class="tfb-fin-seller">${esc(m.seller_name || '—')}</span><div class="small text-muted">${esc(t('fin.costs.marked', {month: d.prev_label, v: money(m.prev_amount)}))}</div></td>
                <td></td><td></td><td><span class="badge bg-light text-dark">${esc(t('fin.costs.none_badge'))}</span></td>
                <td class="text-end" colspan="2">${w ? `<button type="button" class="btn btn-sm btn-link text-secondary" data-unmark="${esc(c.code)}|${esc(m.seller_key)}">${esc(t('fin.costs.unmark'))}</button>` : ''}</td></tr>`).join('');
            return group + `<tr class="tfb-fin-cat"><th><span class="badge ${cls}"><i class="bi ${icon}"></i></span> ${esc(c.label)} <span class="small text-muted fw-normal">${c.count ? esc(t('fin.costs.invoices', {n: c.count})) : ''}</span></th>
                    <th class="text-end tfb-num">${c.count ? esc(money(c.total)) : '—'}${diff}${calc}</th><th></th>
                    <th class="small text-muted fw-normal">${c.prev_total > 0 ? esc(t('fin.costs.prev_short', {v: money(c.prev_total)})) : ''}</th><th colspan="2"></th></tr>`
                + c.invoices.map(i => invoiceRow(d, c, i)).join('') + missing + marked;
        }).join('');
        return chips + top + addForm(d) + `<div class="card"><div class="card-body pt-2"><div class="table-responsive"><table class="table table-sm align-middle mb-0 tfb-fin-table">
                <thead><tr><th>${esc(t('fin.costs.col.cost'))}</th><th class="text-end">${esc(t('fin.costs.col.amount'))}</th><th></th><th>${esc(t('col.status'))}</th><th colspan="2"></th></tr></thead>
                <tbody>${rows || `<tr><td colspan="6" class="text-muted">${esc(t('fin.costs.empty', {month: d.label}))}</td></tr>`}</tbody></table></div></div></div>
            ${d.without_month ? `<p class="small text-warning mt-2">${esc(t('fin.costs.without_month', {n: d.without_month}))}</p>` : ''}
            <p class="small text-muted mt-2">${esc(t('fin.costs.help'))} <a href="${api.root}/workshop/supply#tab=invoices&ik=service">${esc(t('fin.costs.supply_link'))}</a></p>`;
    }

    function bindCosts() {
        const d = state.data;
        const act = async (b, fn, ok) => {
            if (b) b.disabled = true;
            try {
                const res = await fn();
                if (ok) ui.notify(true, typeof ok === 'function' ? ok(res) : ok);
                if (res && res.categories) { state.data = res; draw(); } else load();
                return true;
            } catch (err) {
                ui.notify(false, errText(err));
                if (b) b.disabled = false;
                return false;
            }
        };
        const find = (id) => d.categories.flatMap(c => c.invoices).find(i => i.id === id);
        el.querySelectorAll('[data-month]').forEach(b => b.addEventListener('click', () => { state.month = b.dataset.month; state.adding = null; state.editing = null; load(); }));
        el.querySelector('[data-add]')?.addEventListener('click', () => { state.adding = {}; draw(); el.querySelector('[data-add-form] [name=seller_name]').focus(); });
        el.querySelectorAll('[data-add-missing]').forEach(b => b.addEventListener('click', () => {
            const [code, key] = b.dataset.addMissing.split('|');
            const m = d.categories.find(c => c.code === code).missing.find(x => x.seller_key === key);
            state.adding = {seller_name: m.seller_name, seller_nip: m.seller_nip, cost_category: code};
            draw();
            el.querySelector('[data-add-form] [name=total_net]').focus();
        }));
        el.querySelector('[data-add-cancel]')?.addEventListener('click', () => { state.adding = null; draw(); });
        const form = el.querySelector('[data-add-form]');
        form?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const f = new FormData(form);
            const body = {seller_name: f.get('seller_name'), seller_nip: f.get('seller_nip') || null, number: f.get('number') || null, issue_date: f.get('issue_date'),
                cost_category: f.get('cost_category'), service_month: f.get('service_month') || null, total_net: ui.num(f.get('total_net')), total_gross: ui.num(f.get('total_gross'))};
            const file = form.querySelector('[name=file]').files[0];
            if (file) {
                if (file.size > 5 * 1024 * 1024) { ui.notify(false, t('inv.too_big')); return; }
                body.filename = file.name;
                body.content_base64 = await new Promise((res, rej) => { const r = new FileReader(); r.onload = () => res(String(r.result).split(',')[1]); r.onerror = rej; r.readAsDataURL(file); });
            }
            const btn = form.querySelector('[type=submit]');
            btn.disabled = true;
            try {
                await api.post('/finance/services', body);
                ui.notify(true, t('fin.costs.added'));
                state.adding = null;
                load();
            } catch (err) {
                const orphans = ui.fieldErrors(form, err);
                ui.notify(false, orphans.join(' ') || errText(err));
                btn.disabled = false;
            }
        });
        el.querySelectorAll('[data-accept]').forEach(b => b.addEventListener('click', () => act(b, () => api.post(`/finance/services/${b.dataset.accept}/decision`, {status: 'accepted'}), t('fin.costs.accepted'))));
        el.querySelectorAll('[data-reopen-inv]').forEach(b => b.addEventListener('click', () => act(b, () => api.post(`/finance/services/${b.dataset.reopenInv}/decision`, {status: 'open'}))));
        el.querySelector('[data-accept-all]')?.addEventListener('click', (e) => act(e.currentTarget, () => api.post(`/finance/months/${d.month}/accept-all`, {}),
            (res) => t('fin.costs.accepted_all', {n: res.accepted, left: res.left})));
        el.querySelectorAll('[data-edit]').forEach(b => b.addEventListener('click', () => { state.editing = Number(b.dataset.edit); draw(); }));
        el.querySelector('[data-cancel-edit]')?.addEventListener('click', () => { state.editing = null; draw(); });
        el.querySelector('[data-save-edit]')?.addEventListener('click', async (e) => {
            const id = Number(e.currentTarget.dataset.saveEdit);
            const row = el.querySelector(`[data-edit-row="${id}"]`);
            const i = find(id);
            const body = {};
            const cat = row.querySelector('[data-e=cost_category]').value;
            const month = row.querySelector('[data-e=service_month]').value;
            const amount = row.querySelector('[data-e=cost_amount]').value.trim();
            if (cat && cat !== i.cost_category) body.cost_category = cat;
            if (month && month !== i.service_month) body.service_month = month;
            const a = amount === '' ? null : ui.num(amount);
            if (a !== (i.cost_amount_set ? i.cost_amount : null)) body.cost_amount = a;
            if (!Object.keys(body).length) { state.editing = null; draw(); return; }
            const b = e.currentTarget;
            b.disabled = true;
            try {
                await api.patch(`/finance/services/${id}`, body);
                ui.notify(true, t('fin.costs.saved'));
                state.editing = null;
                load();
            } catch (err) {
                const orphans = ui.fieldErrors(row, err);
                ui.notify(false, orphans.join(' ') || errText(err));
                b.disabled = false;
            }
        });
        el.querySelectorAll('[data-to-goods]').forEach(b => b.addEventListener('click', () => {
            const i = find(Number(b.dataset.toGoods));
            window.TfbInvoiceClassify.toGoods({api, invoice: i, path: '/finance/services', rule: !!i.by_rule, onDone: () => { state.editing = null; load(); }});
        }));
        el.querySelectorAll('[data-delete]').forEach(b => b.addEventListener('click', () => {
            const i = find(Number(b.dataset.delete));
            if (!window.confirm(t('fin.costs.confirm_delete', {name: i.seller_name || '—', v: money(i.cost_amount)}))) return;
            act(b, () => api.del(`/finance/services/${i.id}`), t('deleted'));
        }));
        const mark = (b, value, none) => {
            const [code, key] = value.split('|');
            return act(b, () => api.put('/finance/services/marks', {month: d.month, cost_category: code, seller_key: key, none}));
        };
        el.querySelectorAll('[data-none]').forEach(b => b.addEventListener('click', () => mark(b, b.dataset.none, true)));
        el.querySelectorAll('[data-unmark]').forEach(b => b.addEventListener('click', () => mark(b, b.dataset.unmark, false)));
        el.querySelector('[data-close]')?.addEventListener('click', (e) => {
            const replaced = d.categories.filter(c => c.calc && c.calc.manual > 0 && c.total > 0).map(c => `${c.label} : ${money(c.calc.manual)} → ${money(c.total)}`);
            if (!window.confirm(t('fin.costs.confirm_close', {month: d.label, v: money(d.totals.net)}) + (replaced.length ? '\n\n' + t('fin.costs.confirm_replace') + '\n' + replaced.join('\n') : ''))) return;
            act(e.currentTarget, () => api.post(`/finance/months/${d.month}/close`, {}), (res) => t('fin.costs.closed_ok', {month: d.label, n: res.pushed.length}));
        });
        el.querySelector('[data-reopen]')?.addEventListener('click', (e) => {
            if (!window.confirm(t('fin.costs.confirm_reopen', {month: d.label}))) return;
            act(e.currentTarget, () => api.post(`/finance/months/${d.month}/reopen`, {}), t('fin.costs.reopened'));
        });
        el.querySelectorAll('[data-file]').forEach(b => b.addEventListener('click', async () => {
            try {
                const f = await api.get(`/finance/services/${b.dataset.file}/file`);
                const bytes = Uint8Array.from(atob(f.content_base64), c => c.charCodeAt(0));
                window.open(URL.createObjectURL(new Blob([bytes], {type: f.mime})), '_blank', 'noopener');
            } catch (err) { ui.notify(false, errText(err)); }
        }));
    }

    el.addEventListener('click', async (e) => {
        if (!e.target.closest('[data-lang-switch]')) return;
        await api.setLang(api.lang === 'pl' ? 'fr' : 'pl');
        load();
    });
    load();
})();
