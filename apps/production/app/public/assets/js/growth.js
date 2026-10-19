/*
 * Rentowność — rozwój sprzedaży grupy : okno z sytuacją miesięczną grupy, działaniami
 * (status, sprzedaż, marża, inwestycja, zwrot, ROI) i kreatorem nowego działania.
 * Wymaga page_components/ajax_helpers.twig (obiekt at).
 */
(function () {
    'use strict';
    const modalEl = document.getElementById('growthModal');
    if (!modalEl || !window.at) return;
    const body = modalEl.querySelector('[data-growth-body]');
    const titleEl = modalEl.querySelector('[data-growth-title]');
    const period = modalEl.dataset.period || 'month';
    const modal = window.bootstrap ? new window.bootstrap.Modal(modalEl) : null;
    const STATUS = {idea: 'pomysł', planned: 'zaplanowane', in_progress: 'w realizacji', developed: 'wdrożone', dropped: 'porzucone'};
    const esc = at.esc;
    const eur = (n, d = 0) => n === null || n === undefined ? '—' : Number(n).toLocaleString('pl-PL', {minimumFractionDigits: d, maximumFractionDigits: d}) + ' €';
    const pct = (n, d = 1) => n === null || n === undefined ? '—' : Number(n).toLocaleString('pl-PL', {minimumFractionDigits: d, maximumFractionDigits: d}) + ' %';
    const num = (v) => { const n = Number(String(v ?? '').replace(/\s/g, '').replace(',', '.')); return Number.isFinite(n) ? n : 0; };
    let data = null;
    let excluded = new Set();

    function metrics(a) {
        const pieces = num(a.pieces_per_shop_month) * num(a.shops);
        const revenue = pieces * num(a.selling_price_eur);
        const margin = revenue * num(a.target_margin_pct) / 100;
        const inv = num(a.investment_eur);
        const roi = (m) => inv > 0 ? (margin * m - inv) / inv * 100 : null;
        return {pieces, revenue, margin, payback: inv <= 0 ? 0 : (margin > 0 ? inv / margin : null), roi12: roi(12), roiH: roi(data.defaults.horizon_months)};
    }

    async function open(sectionId, name) {
        titleEl.textContent = 'Rozwój sprzedaży — ' + name;
        body.innerHTML = '<p class="text-muted">Wczytywanie…</p>';
        excluded = new Set();
        modal ? modal.show() : modalEl.classList.add('show');
        const res = await at.send(`/ajax/growth/sections/${sectionId}?period=${encodeURIComponent(period)}`, 'GET');
        if (!res.ok) { body.innerHTML = `<div class="alert alert-danger">${esc(res.message)}</div>`; return; }
        data = res.data;
        renderOverview();
    }

    function scenario() {
        const c = data.context;
        if (!c) return null;
        const active = data.actions.filter(a => a.status !== 'dropped' && !excluded.has(a.id));
        const added = active.reduce((s, a) => s + a.metrics.revenue_month, 0);
        const margin = active.reduce((s, a) => s + a.metrics.margin_month, 0);
        const inv = active.reduce((s, a) => s + a.investment_eur, 0);
        const revenueIf = c.revenue_month + added;
        return {added, margin, inv, revenueIf, staffIf: revenueIf > 0 ? c.staff_month / revenueIf * 100 : null,
            gapAfter: Math.max(0, c.needed_month - revenueIf), coverage: c.gap_month > 0 ? Math.min(100, added / c.gap_month * 100) : null,
            payback: inv <= 0 ? 0 : (margin > 0 ? inv / margin : null)};
    }

    function situationTable() {
        const c = data.context;
        if (!c) return '<p class="text-muted">Brak sprzedaży i kosztów personelu tej grupy w wybranym okresie.</p>';
        const s = scenario();
        return `<div class="table-responsive"><table class="table at-growth-situation mb-2">
            <thead><tr><th>Miesięcznie (${esc(c.period_label)})</th><th class="text-end">Teraz</th><th class="text-end">Jeśli działania wdrożone</th></tr></thead>
            <tbody>
                <tr><td>Sprzedaż</td><td class="text-end">${eur(c.revenue_month)}</td><td class="text-end"><strong>${eur(s.revenueIf)}</strong> <span class="at-sub">(+${eur(s.added)})</span></td></tr>
                <tr><td>Koszt personelu / sprzedaż <span class="at-sub">cel ≤ ${pct(c.staff_target, 0)}</span></td>
                    <td class="text-end ${c.staff_pct !== null && c.staff_pct > c.staff_target ? 'text-danger' : 'text-success'}">${pct(c.staff_pct)}</td>
                    <td class="text-end ${s.staffIf !== null && s.staffIf > c.staff_target ? 'text-danger' : 'text-success'}"><strong>${pct(s.staffIf)}</strong></td></tr>
                <tr><td>Potrzebna sprzedaż dla celu</td><td class="text-end">${eur(c.needed_month)}</td><td class="text-end">${eur(c.needed_month)}</td></tr>
                <tr><td>Brakuje</td><td class="text-end ${c.gap_month > 0 ? 'text-danger' : 'text-success'}">${c.gap_month > 0 ? eur(c.gap_month) : 'w normie'}</td>
                    <td class="text-end ${s.gapAfter > 0 ? 'text-danger' : 'text-success'}"><strong>${s.gapAfter > 0 ? eur(s.gapAfter) : 'cel osiągnięty'}</strong>${s.coverage !== null ? ` <span class="at-sub">działania pokrywają ${pct(s.coverage, 0)}</span>` : ''}</td></tr>
                <tr><td>Marża z działań / inwestycje</td><td></td><td class="text-end">${eur(s.margin)} / mies. · inwestycje ${eur(s.inv)}${s.payback ? ` · zwrot ${String(s.payback.toFixed(1)).replace('.', ',')} mies.` : ''}</td></tr>
            </tbody></table></div>
            <p class="at-sub">„Teraz”: sprzedaż okresu przeliczona na pełny miesiąc (w bieżącym miesiącu: obecne tempo). „Jeśli wdrożone”: wszystkie działania oprócz porzuconych i odznaczonych poniżej. Koszt personelu = pełny miesiąc z kalkulatora kosztów.</p>`;
    }

    function renderOverview() {
        body.innerHTML = `<div data-situation>${situationTable()}</div>
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3 mb-2">
                <h6 class="mb-0">Działania (${data.actions.length})</h6>
                <button type="button" class="btn btn-primary btn-sm" data-add><i class="bi bi-plus"></i> Dodaj działanie</button>
            </div>
            ${data.actions.length ? `<div class="table-responsive"><table class="table table-sm at-growth-actions">
                <thead><tr><th title="Uwzględnij w symulacji">✓</th><th>Działanie</th><th>Status</th><th class="text-end">Szt. / mies.</th><th class="text-end">Sprzedaż / mies.</th>
                    <th class="text-end">Marża / mies.</th><th class="text-end">Inwestycja</th><th class="text-end">Zwrot</th><th class="text-end">ROI 12 / ${data.defaults.horizon_months} mies.</th><th></th></tr></thead>
                <tbody>${data.actions.map(a => `<tr data-action="${a.id}" class="${a.status === 'dropped' ? 'at-inactive' : ''}">
                    <td><input type="checkbox" class="form-check-input" data-include ${a.status === 'dropped' ? 'disabled' : (excluded.has(a.id) ? '' : 'checked')} aria-label="Uwzględnij ${esc(a.title)}"></td>
                    <td><strong>${esc(a.title)}</strong><div class="at-sub">${esc(a.product || '')}${a.is_new_product ? ' · nowy produkt' : ''} · ${a.shops} skl. × ${a.pieces_per_shop_month.toLocaleString('pl-PL')} szt. · ${eur(a.selling_price_eur, 2)}/szt.${a.start_month ? ' · od ' + esc(a.start_month) : ''}${a.owner ? ' · ' + esc(a.owner) : ''}</div></td>
                    <td><select class="form-select form-select-sm" data-status aria-label="Status">${Object.entries(STATUS).map(([k, l]) => `<option value="${k}" ${a.status === k ? 'selected' : ''}>${l}</option>`).join('')}</select></td>
                    <td class="text-end">${a.metrics.pieces_month.toLocaleString('pl-PL', {maximumFractionDigits: 0})}</td>
                    <td class="text-end"><strong>${eur(a.metrics.revenue_month)}</strong></td>
                    <td class="text-end">${eur(a.metrics.margin_month)} <span class="at-sub">${pct(a.target_margin_pct, 0)}</span></td>
                    <td class="text-end">${eur(a.investment_eur)}</td>
                    <td class="text-end">${a.metrics.payback_months === null ? '<span class="text-danger">brak</span>' : (a.metrics.payback_months === 0 ? '—' : String(a.metrics.payback_months.toFixed(1)).replace('.', ',') + ' mies.')}</td>
                    <td class="text-end">${a.metrics.roi_12 === null ? '—' : `<span class="${a.metrics.roi_12 < 0 ? 'text-danger' : 'text-success'}">${pct(a.metrics.roi_12, 0)}</span> / <span class="${a.metrics.roi_horizon < 0 ? 'text-danger' : 'text-success'}">${pct(a.metrics.roi_horizon, 0)}</span>`}</td>
                    <td class="text-end"><button type="button" class="btn btn-sm btn-outline-primary" data-edit>Edytuj</button></td>
                </tr>`).join('')}</tbody></table></div>` : '<p class="at-empty">Brak działań dla tej grupy. Dodaj pierwsze: produkt, ilość na sklep, cena, marża, inwestycja.</p>'}`;
        body.querySelector('[data-add]').addEventListener('click', () => wizard(null));
        body.querySelectorAll('[data-action]').forEach(row => {
            const id = Number(row.dataset.action);
            row.querySelector('[data-edit]').addEventListener('click', () => wizard(data.actions.find(a => a.id === id)));
            row.querySelector('[data-include]').addEventListener('change', (e) => {
                e.target.checked ? excluded.delete(id) : excluded.add(id);
                body.querySelector('[data-situation]').innerHTML = situationTable();
            });
            row.querySelector('[data-status]').addEventListener('change', async (e) => {
                const res = await at.send(`/ajax/growth/actions/${id}`, 'PATCH', {status: e.target.value});
                at.notify(res.ok, res.message);
                if (res.ok) reload();
            });
        });
    }

    async function reload() {
        const res = await at.send(`/ajax/growth/sections/${data.section.id}?period=${encodeURIComponent(period)}`, 'GET');
        if (res.ok) { data = res.data; renderOverview(); }
    }

    // --- Kreator działania : produkt → sprzedaż → marża i inwestycja → podsumowanie ---------
    function wizard(existing) {
        const a = existing ? {...existing} : {
            title: '', final_product_id: null, product_name: '', pieces_per_shop_month: '', shops: data.defaults.shops,
            selling_price_eur: '', target_margin_pct: '', investment_eur: 0, start_month: data.defaults.start_month, status: 'idea', owner_user: null, notes: '',
        };
        let mode = a.final_product_id || !existing ? 'existing' : 'new';
        if (existing && existing.is_new_product) mode = 'new';
        let step = 1;
        const product = () => data.products.find(p => p.id === Number(a.final_product_id));

        const liveHtml = () => {
            const m = metrics(a);
            const c = data.context;
            return `<div><span class="at-sub">Sztuk / miesiąc</span><strong>${m.pieces.toLocaleString('pl-PL', {maximumFractionDigits: 0})}</strong></div>
                <div><span class="at-sub">Sprzedaż / miesiąc</span><strong>${eur(m.revenue)}</strong><span class="at-sub">${eur(m.revenue * 12)} / rok</span></div>
                <div><span class="at-sub">Marża / miesiąc</span><strong>${eur(m.margin)}</strong></div>
                <div><span class="at-sub">Zwrot inwestycji</span><strong>${m.payback === null ? 'brak (marża ≤ 0)' : (m.payback === 0 ? 'bez inwestycji' : String(m.payback.toFixed(1)).replace('.', ',') + ' mies.')}</strong>
                    ${m.roi12 !== null ? `<span class="at-sub">ROI 12 mies. ${pct(m.roi12, 0)} · ${data.defaults.horizon_months} mies. ${pct(m.roiH, 0)}</span>` : ''}</div>
                ${c ? `<div><span class="at-sub">Brakująca sprzedaż grupy</span><strong>${c.gap_month > 0 ? eur(c.gap_month) + ' / mies.' : 'w normie'}</strong>
                    ${c.gap_month > 0 ? `<span class="at-sub">to działanie pokrywa ${pct(Math.min(100, m.revenue / c.gap_month * 100), 0)}</span>` : ''}
                    <span class="at-sub">personel / sprzedaż po wdrożeniu: ${pct(c.staff_month / (c.revenue_month + c.actions_revenue_month - (existing && existing.status !== 'dropped' ? existing.metrics.revenue_month : 0) + m.revenue) * 100)}</span></div>` : ''}`;
        };

        const draw = () => {
            const p = product();
            const materialPct = p && p.material_piece_eur && num(a.selling_price_eur) > 0 ? p.material_piece_eur / num(a.selling_price_eur) * 100 : null;
            body.innerHTML = `<ol class="tfb-stepper">${['Produkt', 'Sprzedaż', 'Marża i inwestycja', 'Podsumowanie'].map((l, i) => `<li class="${i + 1 === step ? 'is-current' : ''} ${i + 1 < step ? 'is-done' : ''}"><a href="#" data-goto="${i + 1}"><span>${i + 1}</span>${l}</a></li>`).join('')}</ol>
            <div class="at-growth-wizard">
              <form class="at-purch-form" data-form>${stepFields(step, p, materialPct)}</form>
              <aside class="at-growth-live">${liveHtml()}</aside>
            </div>
            <div class="tfb-nav">
                <button type="button" class="btn btn-outline-secondary" data-back>${step === 1 ? 'Anuluj' : 'Wstecz'}</button>
                ${step < 4 ? '<button type="button" class="btn btn-primary" data-next>Dalej</button>' : '<button type="button" class="btn btn-primary" data-save>Zapisz działanie</button>'}
            </div>`;
            const form = body.querySelector('[data-form]');
            form.addEventListener('input', (e) => { collect(form); if (e.target.dataset.live !== undefined) drawLive(); });
            form.addEventListener('change', (e) => {
                collect(form);
                if (e.target.name === 'product_mode' || e.target.name === 'final_product_id') {
                    if (e.target.name === 'final_product_id' && product()) {
                        const pr = product();
                        if (!num(a.selling_price_eur) && pr.price_piece_eur) a.selling_price_eur = pr.price_piece_eur;
                        if (!String(a.target_margin_pct) && pr.target_margin_pct !== null) a.target_margin_pct = pr.target_margin_pct;
                        if (!a.title) a.title = pr.name;
                    }
                    draw();
                }
            });
            body.querySelectorAll('[data-goto]').forEach(l => l.addEventListener('click', (e) => { e.preventDefault(); collect(form); step = Number(l.dataset.goto); draw(); }));
            body.querySelector('[data-back]').addEventListener('click', () => { collect(form); if (step === 1) renderOverview(); else { step--; draw(); } });
            const next = body.querySelector('[data-next]');
            if (next) next.addEventListener('click', () => { collect(form); step++; draw(); });
            const save = body.querySelector('[data-save]');
            if (save) save.addEventListener('click', () => submit(form, save));
        };

        const drawLive = () => {
            const live = body.querySelector('.at-growth-live');
            if (live) live.innerHTML = liveHtml();
        };

        const stepFields = (n, p, materialPct) => {
            if (n === 1) return `
                <label class="at-purch-label at-purch-wide">Nazwa działania <span class="text-danger">*</span><input class="form-control" name="title" maxlength="150" value="${esc(a.title)}" placeholder="np. Mini croissant do sieci Max"></label>
                <fieldset class="at-purch-wide"><legend class="at-sub">Produkt</legend>
                    <label class="form-check form-check-inline"><input class="form-check-input" type="radio" name="product_mode" value="existing" ${mode === 'existing' ? 'checked' : ''}> istniejący produkt grupy</label>
                    <label class="form-check form-check-inline"><input class="form-check-input" type="radio" name="product_mode" value="new" ${mode === 'new' ? 'checked' : ''}> nowy produkt</label>
                </fieldset>
                ${mode === 'existing' ? `<label class="at-purch-label at-purch-wide">Produkt<select class="form-select" name="final_product_id"><option value="">— wybierz —</option>
                    ${data.products.map(pr => `<option value="${pr.id}" ${Number(a.final_product_id) === pr.id ? 'selected' : ''}>${esc(pr.name)}${pr.price_piece_eur ? ' · ' + eur(pr.price_piece_eur, 2) + '/szt.' : ''}</option>`).join('')}</select></label>`
                    : `<label class="at-purch-label at-purch-wide">Nazwa nowego produktu<input class="form-control" name="product_name" maxlength="200" value="${esc(a.product_name || '')}"></label>`}`;
            if (n === 2) return `
                <label class="at-purch-label">Sztuk / sklep / miesiąc <span class="text-danger">*</span><input class="form-control" name="pieces_per_shop_month" inputmode="decimal" data-live value="${esc(a.pieces_per_shop_month)}"></label>
                <label class="at-purch-label">Liczba sklepów <span class="text-danger">*</span><input class="form-control" name="shops" inputmode="numeric" data-live value="${esc(a.shops)}"><span class="at-sub">domyślnie: sklepy z dostawą w ostatnich 90 dniach (${data.defaults.shops})</span></label>
                <label class="at-purch-label">Cena sprzedaży / szt. (€) <span class="text-danger">*</span><input class="form-control" name="selling_price_eur" inputmode="decimal" data-live value="${esc(a.selling_price_eur)}">${p && p.price_piece_eur ? `<span class="at-sub">cena w cenniku: ${eur(p.price_piece_eur, 2)}</span>` : ''}</label>
                <label class="at-purch-label">Start<input class="form-control" type="month" name="start_month" value="${esc(a.start_month || '')}"></label>`;
            if (n === 3) return `
                <label class="at-purch-label">Marża docelowa (%) <span class="text-danger">*</span><input class="form-control" name="target_margin_pct" inputmode="decimal" data-live value="${esc(a.target_margin_pct)}">${materialPct !== null ? `<span class="at-sub">surowce to ok. ${pct(materialPct, 0)} ceny</span>` : ''}</label>
                <label class="at-purch-label">Inwestycja potrzebna (€)<input class="form-control" name="investment_eur" inputmode="decimal" data-live value="${esc(a.investment_eur)}"><span class="at-sub">maszyny, formy, opakowania, marketing…</span></label>
                <label class="at-purch-label">Status<select class="form-select" name="status">${Object.entries(STATUS).map(([k, l]) => `<option value="${k}" ${a.status === k ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
                <label class="at-purch-label">Odpowiedzialny<select class="form-select" name="owner_user"><option value="">—</option>${data.users.map(u => `<option value="${u.id}" ${Number(a.owner_user) === Number(u.id) ? 'selected' : ''}>${esc(u.displayed_name)}</option>`).join('')}</select></label>
                <label class="at-purch-label at-purch-wide">Uwagi<textarea class="form-control" name="notes" rows="2" maxlength="2000">${esc(a.notes || '')}</textarea></label>`;
            const m = metrics(a);
            return `<div class="at-purch-wide"><table class="table table-sm mb-0">
                <tr><td>Działanie</td><td><strong>${esc(a.title)}</strong> — ${esc(mode === 'existing' ? (p ? p.name : '—') : (a.product_name || '—') + ' (nowy)')}</td></tr>
                <tr><td>Wolumen</td><td>${num(a.shops)} sklepów × ${num(a.pieces_per_shop_month).toLocaleString('pl-PL')} szt. = ${m.pieces.toLocaleString('pl-PL', {maximumFractionDigits: 0})} szt. / mies.${a.start_month ? ', od ' + esc(a.start_month) : ''}</td></tr>
                <tr><td>Sprzedaż</td><td>${eur(m.revenue)} / mies. · ${eur(m.revenue * 12)} / rok (cena ${eur(num(a.selling_price_eur), 2)}/szt.)</td></tr>
                <tr><td>Marża</td><td>${eur(m.margin)} / mies. · ${eur(m.margin * 12)} / rok (${pct(num(a.target_margin_pct), 0)})</td></tr>
                <tr><td>Inwestycja</td><td>${eur(num(a.investment_eur))} · zwrot ${m.payback === null ? 'nigdy (marża ≤ 0)' : (m.payback === 0 ? '—' : String(m.payback.toFixed(1)).replace('.', ',') + ' mies.')}${m.roi12 !== null ? ` · ROI 12 mies. ${pct(m.roi12, 0)}, ${data.defaults.horizon_months} mies. ${pct(m.roiH, 0)}` : ''}</td></tr>
                <tr><td>Status</td><td>${STATUS[a.status]}</td></tr>
            </table></div>`;
        };

        const collect = (form) => {
            const fd = new FormData(form);
            if (fd.has('product_mode')) mode = fd.get('product_mode');
            for (const k of ['title', 'product_name', 'pieces_per_shop_month', 'shops', 'selling_price_eur', 'start_month', 'target_margin_pct', 'investment_eur', 'status', 'notes']) {
                if (fd.has(k)) a[k] = fd.get(k);
            }
            if (fd.has('final_product_id')) a.final_product_id = fd.get('final_product_id') ? Number(fd.get('final_product_id')) : null;
            if (fd.has('owner_user')) a.owner_user = fd.get('owner_user') ? Number(fd.get('owner_user')) : null;
        };

        const submit = async (form, button) => {
            const payload = {
                title: a.title, pieces_per_shop_month: num(a.pieces_per_shop_month), shops: Math.round(num(a.shops)),
                selling_price_eur: num(a.selling_price_eur), target_margin_pct: num(a.target_margin_pct), investment_eur: num(a.investment_eur),
                start_month: a.start_month || null, status: a.status, owner_user: a.owner_user || null, notes: a.notes || null,
            };
            if (mode === 'existing') payload.final_product_id = a.final_product_id || null; else payload.product_name = a.product_name || null;
            if (!existing) payload.section_id = data.section.id;
            button.disabled = true;
            const res = await at.send(existing ? `/ajax/growth/actions/${existing.id}` : '/ajax/growth/actions', existing ? 'PATCH' : 'POST', payload);
            button.disabled = false;
            if (!res.ok) {
                // Revenir à l'étape du premier champ en erreur.
                const fieldStep = {title: 1, final_product_id: 1, product_name: 1, pieces_per_shop_month: 2, shops: 2, selling_price_eur: 2, start_month: 2};
                const first = (res.errors || [])[0];
                step = first ? (fieldStep[String(first.field).replace(/^.*\./, '')] || 3) : step;
                draw();
                at.showErrors(body.querySelector('[data-form]'), res);
                return;
            }
            at.notify(true, res.message);
            await reload();
        };
        draw();
    }

    document.addEventListener('click', (e) => {
        const trigger = e.target.closest('[data-growth]');
        if (!trigger) return;
        e.preventDefault();
        open(Number(trigger.dataset.growth), trigger.dataset.growthName || '');
    });
})();
