// [398] Intrastat miesiąca (Kierownik produkcji — co miesiąc) : 1. wywóz (WDT) et przywóz (factures des fournisseurs UE) du mois
// écoulé, par société et par sens, regroupés par code CN × pays × VAT partenaire, CSV à recopier dans PUESC, 2. une décision par
// signal (code CN et masse par unité, kurs saisis ici ; VAT odbiorcy et factures corrigés dans l'ERP ; déclaration zgłoszona
// w PUESC avec numéro ou poniżej progu), 3. récapitulatif et validation → la tâche du mois se valide seule. Aucun XML officiel,
// aucun appel extérieur, aucun envoi : l'ERP prépare les chiffres, l'humain déclare.
(() => {
    const app = document.getElementById('imApp');
    if (!app || !window.tw) return;
    const {esc} = tw;
    let D = JSON.parse(document.getElementById('imBoot').textContent);
    let step = 1, reached = 1, busy = false;
    // Brouillons de l'étape 2 (décision, note, code CN, masse, kurs) par élément, gardés entre deux rendus.
    const draft = {};
    const STEPS = ['Przegląd', 'Do decyzji', 'Zatwierdź'];
    const DEC_CLS = {completed: 'ok', not_applicable: '', declared: 'ok', below_threshold: 'warn'};
    const SIG_CLS = {threshold_exceeded: 'bad', threshold_below: 'ok', threshold_not_set: 'warn'};
    const KIND_ICON = {declaration: 'bi-file-earmark-spreadsheet', rate: 'bi-currency-exchange', product: 'bi-box-arrow-up-right', material: 'bi-box-arrow-in-down', partner: 'bi-person-badge', invoice: 'bi-receipt'};
    const sel = (k) => CSS.escape(k);
    const canFinish = () => !!D.can_finish;
    const nf = (v, d) => { const n = Number(v || 0), [i, f] = Math.abs(n).toFixed(d).split('.'), g = d === 2 ? f : (f || '').replace(/0+$/, ''); return (n < 0 ? '−' : '') + i.replace(/\B(?=(\d{3})+(?!\d))/g, '\u00a0') + (g ? ',' + g : ''); };
    const zl = (v) => nf(v, 2) + ' zł';
    const kg = (v) => nf(v, 3) + ' kg';
    const link = (l) => `<a class="tw-link" href="${at.root}${esc(l.href)}" target="_blank" rel="noopener" style="padding:0"><i class="bi bi-box-arrow-up-right"></i> ${esc(l.label)}</a>`;
    const links = (i) => i.links && i.links.length ? `<div style="display:flex;gap:4px 14px;flex-wrap:wrap">${i.links.map(link).join('')}</div>` : '';
    const csv = (dc) => `<a class="tw-link" style="padding:0" href="${at.root}/ajax/intrastat/month/csv?decl=${encodeURIComponent(dc.key)}"><i class="bi bi-filetype-csv"></i> CSV do PUESC</a>`;
    const eff = (i) => i.decision || i.earlier;
    const what = (i) => `<b><i class="bi ${KIND_ICON[i.kind] || 'bi-dot'}"></i> ${esc(i.title)}</b><small>${esc(i.kind_label)}${i.sub ? ' · ' + esc(i.sub) : ''}</small>`;
    const status = (i) => i.waiting ? tw.pill(i.kind === 'declaration' ? 'do zgłoszenia / decyzji' : 'do uzupełnienia', 'bad')
        : (eff(i) ? tw.pill(eff(i).label + (i.decision ? '' : ' (' + eff(i).month_label + ')'), DEC_CLS[eff(i).code] || '') : tw.pill('uzupełnione', 'ok'));
    const setting = (v) => v === null || v === undefined ? '<span class="tw-red">nie ustawiono</span>' : esc(v);

    function declTable(dc) {
        const head = [{label: 'Kod CN'}, {label: 'Kraj'}].concat(dc.dir === 'dispatch' ? [{label: 'VAT partnera'}] : [], [{label: 'Masa netto', num: true}, {label: 'Wartość PLN', num: true}, {label: 'Dok.', num: true}, {label: 'Towary'}, {label: 'Uwagi'}]);
        const red = (v) => v ? esc(v) : '<span class="tw-red">—</span>';
        const rows = dc.rows.map(r => {
            const cells = [r.cn ? `<b>${esc(r.cn)}</b>` : red(null), red(r.country)];
            if (dc.dir === 'dispatch') cells.push(red(r.partner_vat));
            cells.push(kg(r.mass_kg) + (r.mass_complete ? '' : ' <small class="tw-red">+ brak</small>'), (!r.value_complete && !r.value_pln ? '<span class="tw-red">—</span>' : zl(r.value_pln) + (r.value_complete ? '' : ' <small class="tw-red">+ bez kursu</small>')), String(r.docs),
                `<small>${esc(r.items.join(', '))}${r.items_count > r.items.length ? ' (+' + (r.items_count - r.items.length) + ')' : ''}</small>`,
                r.remarks.length ? `<small class="tw-red">${esc(r.remarks.join(', '))}</small>` : '');
            return {cls: r.remarks.length ? 'hl' : '', cells};
        });
        const thr = dc.threshold === null ? 'próg nie ustawiony' : `próg ${zl(dc.threshold)}`;
        return `<div class="tw-cap" style="display:flex;gap:12px;align-items:baseline;flex-wrap:wrap"><span>${esc(dc.dir_label)} — ${esc(dc.company_name)}${dc.company !== 'erp' ? ' · NIP ' + esc(dc.company) : ''}</span>
                <small>${zl(dc.value_pln)}${dc.value_complete ? '' : ' (bez pozycji bez kursu)'} · ${kg(dc.mass_kg)} · ${dc.docs} dok. · od początku roku ${zl(dc.year_pln)} · ${thr}</small>${csv(dc)}</div>
            ${tw.table(head, rows)}`;
    }

    function step1() {
        const t = D.totals, s = D.settings;
        const dirTile = (dir, label) => ({label, value: t[dir].declarations ? zl(t[dir].value_pln) : '—', sub: t[dir].declarations ? `${t[dir].docs} dok. · ${t[dir].declarations} ${tw.plural(t[dir].declarations, 'deklaracja', 'deklaracje', 'deklaracji')}${t[dir].value_complete ? '' : ' · część bez kursu'}` : 'brak w ERP w tym miesiącu'});
        const tiles = [dirTile('dispatch', 'Wywóz (WDT)'), dirTile('arrival', 'Przywóz (faktury UE)'),
            {label: 'Sygnały', value: String(t.signals), sub: `produkty ${t.by_kind.product} · surowce ${t.by_kind.material} · kursy ${t.by_kind.rate} · odbiorcy ${t.by_kind.partner} · faktury ${t.by_kind.invoice}`, cls: t.signals ? 'warn' : 'ok'},
            {label: 'Do decyzji', value: String(t.review), sub: t.review ? `${t.resolved} z ${t.review} z decyzją` : 'brak', cls: t.waiting ? 'warn' : 'ok'}];
        const missing = Object.values(D.sources).filter(x => !x.available);
        const settings = `<div><b>Ustawienia</b> (Ustawienia › Parametry, intrastat.*) : próg wywozu ${s.threshold_dispatch === null ? '<span class="tw-red">nie ustawiono</span>' : zl(s.threshold_dispatch)},
            próg przywozu ${s.threshold_arrival === null ? '<span class="tw-red">nie ustawiono</span>' : zl(s.threshold_arrival)}, rodzaj transakcji ${setting(s.transaction_nature)}, warunki dostawy ${setting(s.delivery_terms)},
            rodzaj transportu ${setting(s.transport_mode)}. Wartości z przepisów danego roku wpisuje użytkownik — ERP ich nie zakłada ; bez progu nie ma sygnału progu.
            ${D.settings_link ? `<a class="tw-link" style="padding:0" href="${at.root}${esc(D.settings_link)}" target="_blank" rel="noopener">Otwórz parametry</a>` : ''}</div>`;
        const company = `<div><b>Firmy</b> : ERP zna jedną firmę${D.company ? ` (NIP ${esc(D.company.nip)}${D.company.name ? ', ' + esc(D.company.name) : ''})` : ''} — wywóz według sprzedawcy na WDT, przywóz przypisany tej firmie (faktura nie ma NIP nabywcy).
            Pozostałe firmy z obowiązku (Samloc, Atelier) nie mają dokumentów w ERP : ich Intrastat poza asystentem.</div>`;
        return `<p class="tw-lead">Miesiąc <b>${esc(D.month_label)}</b> : wywóz z dokumentów WDT (data wysyłki) i przywóz z faktur zakupowych od sprzedawców z VAT UE, według firmy i kierunku,
                zgrupowane po kodzie CN, kraju i numerze VAT partnera. Wartość w PLN po kursie wpisanym na miesiąc. Każdy sygnał i każda deklaracja trafia do kroku 2.</p>
            ${tw.tiles(tiles)}
            ${tw.alert(settings + company, '', 'bi-info-circle')}
            ${missing.length ? tw.alert(missing.map(x => `<div><b>${esc(x.from)}</b> : ${esc(x.note)}</div>`).join(''), '', 'bi-info-circle') : ''}
            ${D.declarations.length ? D.declarations.map(declTable).join('') : '<p class="tw-empty">Brak wywozu WDT i przywozu od sprzedawców z UE w tym miesiącu.</p>'}
            <p class="tw-note">Plik CSV zawiera to, co wie ERP (kod CN, kraj, VAT partnera, masa netto, wartość PLN, ustawienia domyślne) — do przepisania w PUESC ; pozostałe pola zgłoszenia uzupełnia się w PUESC.
                ERP nie tworzy XML i niczego nie wysyła.</p>`;
    }

    function inputs(i, r) {
        if (i.kind === 'rate') return `<div style="display:flex;gap:6px;align-items:center"><span>1 ${esc(i.currency)} =</span><input class="form-control form-control-sm" inputmode="decimal" data-im-rate="${esc(i.key)}" value="${esc(r.rate)}" placeholder="kurs PLN" style="max-width:140px;min-height:36px;border-radius:9px"><span>PLN</span>
            <button type="button" class="tw-btn ghost sm" data-im-save-rate="${esc(i.key)}" ${busy || !String(r.rate).trim() ? 'disabled' : ''}>Zapisz kurs</button></div>`;
        if (i.kind !== 'product' && i.kind !== 'material') return '';
        if (!(i.item_id > 0)) return '<small class="tw-red">Pozycje bez produktu w kartotece : kodu CN nie można przypisać.</small>';
        const mass = i.needs_unit_mass ? `<input class="form-control form-control-sm" inputmode="decimal" data-im-mass="${esc(i.key)}" value="${esc(r.mass)}" placeholder="kg / 1 ${esc(i.base_unit || 'jedn.')}" title="Masa netto (kg) jednej jednostki bazowej surowca" style="max-width:150px;min-height:36px;border-radius:9px">` : '';
        return `<div style="display:flex;gap:6px;align-items:center"><input class="form-control form-control-sm" inputmode="numeric" maxlength="10" data-im-cn="${esc(i.key)}" value="${esc(r.cn)}" placeholder="kod CN (8 cyfr)" style="max-width:150px;min-height:36px;border-radius:9px">${mass}
            <button type="button" class="tw-btn ghost sm" data-im-save-code="${esc(i.key)}" ${busy ? 'disabled' : ''}>Zapisz kod</button></div>`;
    }

    function decisionCell(i) {
        const a = i.decision;
        const r = draft[i.key] ?? (draft[i.key] = {decision: a ? a.code : '', note: a ? (a.note || '') : '', cn: i.cn_code || '', mass: i.unit_mass_kg === null || i.unit_mass_kg === undefined ? '' : String(i.unit_mass_kg).replace('.', ','), rate: ''});
        const allowed = D.kind_decisions[i.kind] || [];
        const seg = allowed.map(k => `<button type="button" class="${r.decision === k ? 'on' : ''}" data-im-dec="${esc(i.key)}" data-v="${k}">${esc(D.decisions[k])}</button>`).join('');
        const need = D.note_required.includes(r.decision);
        const ph = r.decision === 'declared' ? 'Numer referencyjny zgłoszenia w PUESC (wymagany)' : (r.decision === 'below_threshold' ? 'Uzasadnienie (wymagane) : suma roku, próg z przepisów' : (r.decision === 'not_applicable' ? 'Dlaczego nie dotyczy (wymagane)' : 'Notatka (opcjonalnie)'));
        const saved = a ? `<small>${tw.pill(a.label, DEC_CLS[a.code] || '')} ${esc(a.at)}${a.by ? ' · ' + esc(a.by) : ''}${a.note ? ' — ' + esc(a.note) : ''}</small>`
            : (i.earlier ? `<small>${tw.pill(i.earlier.label, DEC_CLS[i.earlier.code] || '')} w miesiącu « ${esc(i.earlier.month_label)} »${i.earlier.note ? ' — ' + esc(i.earlier.note) : ''}</small>` : '');
        const dirty = !a || a.code !== r.decision || (a.note || '') !== r.note;
        const block = i.kind === 'declaration' && i.blocked ? `<small class="tw-red">« Zgłoszone » po uzupełnieniu ${i.blocked} ${tw.plural(i.blocked, 'elementu', 'elementów', 'elementów')} tej deklaracji.</small>` : '';
        const decl = i.kind === 'declaration' ? D.declarations.find(x => x.key === i.key) : null;
        return `<div style="display:grid;gap:6px;min-width:440px">${inputs(i, r)}<div class="tw-seg" style="justify-self:start">${seg}</div>
            <div style="display:flex;gap:6px"><input class="form-control form-control-sm" data-im-note="${esc(i.key)}" maxlength="500" value="${esc(r.note)}" placeholder="${esc(ph)}" style="min-height:36px;border-radius:9px">
            <button type="button" class="tw-btn ghost sm" data-im-save="${esc(i.key)}" ${!r.decision || !dirty || busy || (need && r.note.trim().length < 3) ? 'disabled' : ''}>Zapisz</button></div>
            ${block}${decl ? `<div>${csv(decl)}</div>` : ''}${links(i)}${i.reason ? `<small class="tw-red">${esc(i.reason)}</small>` : ''}${saved}</div>`;
    }

    function step2() {
        const list = D.review, t = D.totals;
        if (!list.length) return tw.alert('Nic do decyzji : brak wywozu i przywozu UE w tym miesiącu.', 'ok', 'bi-check-circle');
        const rows = list.map(i => ({cls: i.waiting ? 'hl' : '', cells: [what(i),
            i.signals.map(s => `<div style="display:flex;gap:6px;align-items:baseline;margin:1px 0"><i class="bi ${SIG_CLS[s.code] === 'ok' ? 'bi-check-circle tw-green' : 'bi-exclamation-circle ' + (SIG_CLS[s.code] === 'warn' ? '' : 'tw-red')}"></i><span><b>${esc(s.label)}</b>${s.detail ? ' : ' + esc(s.detail) : ''}</span></div>`).join('') || '<small>uzupełnione</small>',
            decisionCell(i)]}));
        return `<p class="tw-lead">Uzupełnij <b>kody CN</b> (8 cyfr) i masę, wpisz <b>kurs</b> waluty na miesiąc — zapis kodu albo kursu zapisuje « Uzupełnione », gdy sygnał znika.
                Odbiorcę bez VAT UE i fakturę bez surowca popraw w ERP (link) i zapisz « Uzupełnione », albo « Nie dotyczy » z powodem. Na końcu dla każdej deklaracji :
                <b>Zgłoszone w PUESC</b> (numer referencyjny) albo <b>Poniżej progu — bez zgłoszenia</b> (uzasadnienie).</p>
            ${tw.tiles([{label: 'Do decyzji', value: String(t.review), sub: `${t.by_kind.declaration} ${tw.plural(t.by_kind.declaration, 'deklaracja', 'deklaracje', 'deklaracji')} · ${t.signals} sygnałów`},
                {label: 'Z decyzją', value: `${t.resolved} z ${t.review}`, sub: t.waiting ? `zostało ${t.waiting}` : 'wszystkie z decyzją', cls: t.waiting ? 'warn' : 'ok'},
                {label: 'Uzupełnione / nie dotyczy', value: `${t.completed} / ${t.not_applicable}`, sub: t.earlier ? `${t.earlier} z poprzednich miesięcy` : 'w tym przeglądzie'},
                {label: 'Zgłoszone / poniżej progu', value: `${t.declared} / ${t.below_threshold}`, sub: 'deklaracje'}])}
            ${tw.table([{label: 'Element'}, {label: 'Sygnał'}, {label: 'Decyzja'}], rows)}
            <p class="tw-note">Kody CN, masa jednostki i kursy zapisują się tylko w asystencie (dla kolejnych miesięcy) ; w ERP nic się nie zmienia. « Nie dotyczy » obowiązuje w kolejnych miesiącach, dopóki sygnał jest ten sam.</p>`;
    }

    function step3() {
        const t = D.totals;
        if (D.done) return tw.done('Intrastat miesiąca gotowy · ' + (D.done.at || ''), D.done.summary + ' · zadanie zaliczone');
        const decls = D.declarations.map(dc => ({cells: [`<b>${esc(dc.dir_label)}</b><small>${esc(dc.company_name)}</small>`, zl(dc.value_pln), kg(dc.mass_kg), String(dc.docs),
            dc.decision ? tw.pill(dc.decision.label, DEC_CLS[dc.decision.code] || '') + (dc.decision.note ? `<small>${esc(dc.decision.note)}</small>` : '') : tw.pill('bez decyzji', 'bad'), csv(dc)]}));
        return `<p class="tw-lead">Gotowe : <b>${esc(D.summary)}</b>.</p>
            ${tw.tiles([{label: 'Wywóz', value: zl(t.dispatch.value_pln), sub: `${t.dispatch.docs} dok.`}, {label: 'Przywóz', value: zl(t.arrival.value_pln), sub: `${t.arrival.docs} dok.`},
                {label: 'Decyzje', value: `${t.resolved} z ${t.review}`, sub: `${t.completed} uzupełnione · ${t.not_applicable} nie dotyczy · ${t.declared} zgłoszone · ${t.below_threshold} poniżej progu`, cls: 'ok'}])}
            ${decls.length ? '<div class="tw-cap">Deklaracje</div>' + tw.table([{label: 'Deklaracja'}, {label: 'Wartość PLN', num: true}, {label: 'Masa netto', num: true}, {label: 'Dok.', num: true}, {label: 'Decyzja'}, {label: ''}], decls) : ''}
            <p class="tw-note">« Zakończ » zalicza zadanie « Intrastat miesiąca » na ten miesiąc. ERP niczego nie wysyła — zgłoszenie w PUESC robi użytkownik.</p>`;
    }

    function render() {
        const top = canFinish() ? reached : Math.min(reached, 2);
        if (step > top) step = top;
        const body = step === 1 ? step1() : (step === 2 ? step2() : step3());
        const next = step === 1 ? {label: 'Dalej : do decyzji', attr: 'data-im-next'}
            : step === 2 ? {label: 'Dalej : zatwierdź', attr: 'data-im-next', disabled: !canFinish()}
                : (D.done ? null : {label: 'Zakończ', attr: 'data-im-finish', final: true, disabled: busy || !canFinish()});
        app.innerHTML = tw.frame({title: 'Intrastat miesiąca', sub: `${D.month_label} · krok ${step} z 3 · ok. 45 min`, steps: STEPS, step, reached: top, body,
            prev: step > 1 ? 'Wstecz' : null, next, later: !D.done && step < 3});
    }

    const refresh = (key) => {
        const i = D.review.find(x => x.key === key), r = draft[key], a = i && i.decision, btn = app.querySelector(`[data-im-save="${sel(key)}"]`);
        if (!btn || !i) return;
        btn.disabled = !r.decision || busy || (D.note_required.includes(r.decision) && r.note.trim().length < 3) || (a && a.code === r.decision && (a.note || '') === r.note);
        const rb = app.querySelector(`[data-im-save-rate="${sel(key)}"]`);
        if (rb) rb.disabled = busy || !String(r.rate).trim();
    };
    const afterSave = (res, key) => { busy = false; if (res.ok) { D = res.data; delete draft[key]; } render(); };
    tw.bind(app, (n) => { step = n === -1 ? Math.max(1, step - 1) : n; render(); });
    app.addEventListener('input', (e) => {
        for (const [attr, field] of [['imNote', 'note'], ['imCn', 'cn'], ['imMass', 'mass'], ['imRate', 'rate']]) {
            const el = e.target.closest(`[data-${attr.replace(/[A-Z]/g, c => '-' + c.toLowerCase())}]`);
            if (!el) continue;
            const key = el.dataset[attr];
            (draft[key] ??= {decision: '', note: '', cn: '', mass: '', rate: ''})[field] = el.value;
            refresh(key);
            return;
        }
    });
    app.addEventListener('click', async (e) => {
        if (e.target.closest('[data-im-next]')) { step = Math.min(3, step + 1); reached = Math.max(reached, step); render(); return; }
        const r = e.target.closest('[data-im-dec]');
        if (r) { const key = r.dataset.imDec; draft[key].decision = r.dataset.v; render(); app.querySelector(`[data-im-note="${sel(key)}"]`)?.focus(); return; }
        const c = e.target.closest('[data-im-save-code]');
        if (c) {
            const key = c.dataset.imSaveCode, d = draft[key], i = D.review.find(x => x.key === key);
            if (!d || busy) return;
            busy = true; c.disabled = true;
            const body = {key, cn_code: d.cn};
            if (i && i.needs_unit_mass) body.unit_mass_kg = d.mass;
            afterSave(await tw.post('/ajax/intrastat/month/code', body), key);
            return;
        }
        const rt = e.target.closest('[data-im-save-rate]');
        if (rt) {
            const key = rt.dataset.imSaveRate, d = draft[key], i = D.review.find(x => x.key === key);
            if (!d || !i || busy) return;
            busy = true; rt.disabled = true;
            afterSave(await tw.post('/ajax/intrastat/month/rate', {currency: i.currency, rate: d.rate}), key);
            return;
        }
        const s = e.target.closest('[data-im-save]');
        if (s) {
            const key = s.dataset.imSave, d = draft[key], i = D.review.find(x => x.key === key);
            if (!d || !d.decision || busy) return;
            busy = true; s.disabled = true;
            // seen : signaux affichés (si corrigés entre-temps dans l'ERP, « Uzupełnione » s'enregistre avec eux).
            afterSave(await tw.post('/ajax/intrastat/month/decision', {key, decision: d.decision, note: d.note, seen: i ? i.signals.map(x => x.code) : []}), key);
            return;
        }
        if (e.target.closest('[data-im-finish]')) {
            if (busy) return;
            busy = true; render();
            const res = await tw.post('/ajax/intrastat/month/finish', {});
            busy = false;
            if (res.ok) { at.notify(true, res.message); D = res.data; }
            render();
        }
    });
    render();
})();
