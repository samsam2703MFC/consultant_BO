// [402] Dokumenty wysyłek miesiąca (Kierownik produkcji — co miesiąc) : 1. wysyłki du mois écoulé dont les documents de
// transport sont absents ou incomplets (WZ, CMR, WDT, quantités WZ / WDT), dont la różnica ou le problème de livraison n'est
// pas réglé, ou sans potwierdzenie odbioru de la tablette chauffeur, 2. une décision par wysyłka (uzupełnione — sprawdzone /
// dokument papierowy kompletny / nie dotyczy), 3. récapitulatif et validation → la tâche du mois se valide seule. Rien
// n'est changé dans les documents depuis cet écran (liens vers les écrans existants), rien n'est envoyé. Le dowód opakowań
// est contrôlé par l'assistant « PPWR i opakowania » (compté ici, lien seulement).
(() => {
    const app = document.getElementById('sdApp');
    if (!app || !window.tw) return;
    const {esc} = tw;
    let D = JSON.parse(document.getElementById('sdBoot').textContent);
    let step = 1, reached = 1, busy = false;
    // Brouillons de l'étape 2 (décision, note) par wysyłka, gardés entre deux rendus.
    const draft = {};
    const STEPS = ['Przegląd', 'Do decyzji', 'Zatwierdź'];
    const DEC_CLS = {checked: 'ok', paper_ok: 'ok', not_applicable: ''};
    const sel = (k) => CSS.escape(k);
    const link = (l) => `<a class="tw-link" href="${at.root}${esc(l.href)}" target="_blank" rel="noopener" style="padding:0"><i class="bi bi-box-arrow-up-right"></i> ${esc(l.label)}</a>`;
    const links = (i) => i.links.length ? `<div style="display:flex;gap:4px 14px;flex-wrap:wrap">${i.links.map(link).join('')}</div>` : '';
    const sigs = (i) => i.signals.map(s => tw.pill(s.label, 'bad')).join(' ');
    const what = (i) => `<b><i class="bi bi-truck"></i> ${esc(i.title)}</b><small>${esc(i.sub || '—')}</small>`;
    const eff = (i) => i.decision;
    const status = (i) => i.waiting ? tw.pill('do sprawdzenia', 'bad')
        : (eff(i) ? tw.pill(eff(i).label, DEC_CLS[eff(i).code] || '') : tw.pill('bez braków', 'ok'));

    function step1() {
        const t = D.totals, s = t.shipments, ok = D.source.available, c = D.checks;
        const v = (x) => ok ? x : 'brak danych';
        const tiles = [
            {label: 'Wysyłki z brakami', value: v(`${s.incomplete} / ${s.total}`), sub: ok ? D.month_label : D.source.note, cls: ok ? (s.incomplete ? 'warn' : 'ok') : ''},
            {label: 'Dokumenty transportowe', value: c.wz || c.cmr || c.wdt ? v(String(s.transport)) : 'brak danych', sub: 'WZ, CMR, WDT, ilości WZ / WDT', cls: ok ? (s.transport ? 'warn' : 'ok') : ''},
            {label: 'Rozliczenia', value: c.diff ? v(String(s.settlement)) : 'brak danych', sub: 'różnice i problemy dostaw nierozliczone', cls: ok && c.diff ? (s.settlement ? 'warn' : 'ok') : ''},
            {label: 'Potwierdzenia odbioru', value: c.receipt ? v(String(s.receipt)) : 'brak danych', sub: c.receipt ? `dni z tabletem kierowcy : ${s.receipt_days}` : 'brak tabel tabletu kierowcy', cls: ok && c.receipt ? (s.receipt ? 'warn' : 'ok') : ''},
            {label: 'Do decyzji', value: String(t.review), sub: t.review ? `${t.resolved} z ${t.review} z decyzją` : 'brak braków', cls: t.waiting ? 'warn' : 'ok'}];
        const head = [{label: 'Wysyłka'}, {label: 'Braki'}, {label: 'Gdzie sprawdzić'}, {label: ''}];
        const row = (i) => ({cls: i.waiting ? 'hl' : '', cells: [what(i), sigs(i) + i.signals.filter(x => x.detail).map(x => `<small>${esc(x.detail)}</small>`).join(''), links(i), status(i)]});
        const ppwr = c.packaging && ok ? tw.alert(`Dowody opakowań (BDO / PPWR) : ${s.no_packaging} ${tw.plural(s.no_packaging, 'wysyłka', 'wysyłki', 'wysyłek')} bez dowodu — sprawdza je asystent « PPWR i opakowania », nie ten.`
            + (D.ppwr_link ? ` <a class="tw-link" style="padding:0" href="${at.root}${esc(D.ppwr_link.href)}" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> ${esc(D.ppwr_link.label)}</a>` : ''), '', 'bi-info-circle') : '';
        return `<p class="tw-lead">Miesiąc <b>${esc(D.month_label)}</b> : dokumenty każdej wysyłki (WZ, CMR, WDT, dokument różnic) według tego, co ERP tworzy przy wysyłce, rozliczenie różnic i potwierdzenie odbioru. Każda wysyłka z brakiem trafia do kroku 2.</p>
            ${tw.tiles(tiles)}
            ${ok ? '' : tw.alert(esc(D.source.note), '', 'bi-info-circle')}
            ${ppwr}
            <div class="tw-cap">Do decyzji (${D.review.length})</div>
            ${tw.table(head, D.review.map(row), 'Brak braków — dokumenty wysyłek miesiąca kompletne.')}
            <p class="tw-note">Potwierdzenie odbioru sprawdzane tylko w dni, w które kierowca używał tabletu ; podpisany papier CMR / WZ wpisz w kroku 2 jako « Dokument papierowy kompletny ».
                KSeF faktur WDT sprawdza asystent « Faktury i KSeF dnia ».</p>`;
    }

    function decisionCell(i) {
        const a = i.decision;
        const r = draft[i.key] ?? (draft[i.key] = {decision: a ? a.code : '', note: a ? (a.note || '') : ''});
        const seg = Object.keys(D.decisions).map(k => `<button type="button" class="${r.decision === k ? 'on' : ''}" data-sd-dec="${esc(i.key)}" data-v="${k}">${esc(D.decisions[k])}</button>`).join('');
        const need = D.note_required.includes(r.decision);
        const ph = r.decision === 'paper_ok' ? 'Gdzie jest (wymagane) : np. segregator CMR, podpis odbiorcy' : (r.decision === 'not_applicable' ? 'Dlaczego nie dotyczy (wymagane) : np. odbiór własny' : 'Notatka (opcjonalnie) : co sprawdzono');
        const saved = a ? `<small>${tw.pill(a.label, DEC_CLS[a.code] || '')} ${esc(a.at)}${a.by ? ' · ' + esc(a.by) : ''}</small>` : '';
        const dirty = !a || a.code !== r.decision || (a.note || '') !== r.note;
        return `<div style="display:grid;gap:6px;min-width:420px"><div class="tw-seg" style="justify-self:start">${seg}</div>
            <div style="display:flex;gap:6px"><input class="form-control form-control-sm" data-sd-note="${esc(i.key)}" maxlength="500" value="${esc(r.note)}" placeholder="${esc(ph)}" style="min-height:36px;border-radius:9px">
            <button type="button" class="tw-btn ghost sm" data-sd-save="${esc(i.key)}" ${!r.decision || !dirty || busy || (need && r.note.trim().length < 3) ? 'disabled' : ''}>Zapisz</button></div>
            ${links(i)}${i.reason ? `<small class="tw-red">${esc(i.reason)}</small>` : ''}${saved}</div>`;
    }

    function step2() {
        const list = D.review, t = D.totals;
        if (!list.length) return tw.alert('Nic do decyzji : dokumenty wysyłek miesiąca kompletne.', 'ok', 'bi-check-circle');
        const rows = list.map(i => ({cls: i.waiting ? 'hl' : '', cells: [what(i),
            i.signals.map(s => `<div style="display:flex;gap:6px;align-items:baseline;margin:1px 0"><i class="bi bi-exclamation-circle tw-red"></i><span><b>${esc(s.label)}</b>${s.detail ? ' : ' + esc(s.detail) : ''}</span></div>`).join('') || '<small>braki uzupełnione</small>',
            decisionCell(i)]}));
        return `<p class="tw-lead">Dla każdej wysyłki zapisz decyzję : <b>Uzupełnione — sprawdzone</b> (przyjęte, gdy w ERP nie ma już braków), <b>Dokument papierowy kompletny</b>
                (gdzie jest — podpisany papier jest w archiwum) albo <b>Nie dotyczy</b> (z powodem, np. odbiór własny klienta). Krok 3 otworzy się, gdy wszystkie mają decyzję.</p>
            ${tw.tiles([{label: 'Do decyzji', value: String(t.review), sub: `${t.shipments.transport} dokumenty · ${t.shipments.settlement} rozliczenia · ${t.shipments.receipt} potwierdzenia`},
                {label: 'Z decyzją', value: `${t.resolved} z ${t.review}`, sub: t.waiting ? `zostało ${t.waiting}` : 'wszystkie z decyzją', cls: t.waiting ? 'warn' : 'ok'},
                {label: 'Sprawdzone / na papierze', value: `${t.checked} / ${t.paper_ok}`, sub: 'w tym przeglądzie'}, {label: 'Nie dotyczy', value: String(t.not_applicable), sub: 'z powodem'}])}
            ${tw.table([{label: 'Wysyłka'}, {label: 'Braki'}, {label: 'Decyzja'}], rows)}
            <p class="tw-note">Asystent niczego nie zmienia w dokumentach i niczego nie wysyła (ani e-maili, ani KSeF).</p>`;
    }

    function step3() {
        const t = D.totals, s = t.shipments;
        if (D.done) return tw.done('Dokumenty wysyłek sprawdzone · ' + (D.done.at || ''), D.done.summary + ' · zadanie zaliczone');
        const decided = D.review.filter(i => eff(i));
        return `<p class="tw-lead">Gotowe : <b>${esc(D.summary)}</b>.</p>
            ${tw.tiles([{label: 'Wysyłki', value: `${s.incomplete} z brakami`, sub: `z ${s.total} (${D.month_label})`},
                {label: 'Dokumenty', value: String(s.transport), sub: 'WZ, CMR, WDT'},
                {label: 'Rozliczenia / potwierdzenia', value: `${s.settlement} / ${s.receipt}`, sub: 'różnice, problemy, odbiór'},
                {label: 'Decyzje', value: `${t.resolved} z ${t.review}`, sub: `${t.checked} sprawdzone · ${t.paper_ok} na papierze · ${t.not_applicable} nie dotyczy`, cls: 'ok'}])}
            ${decided.length ? `<div class="tw-cap">Decyzje</div>` + tw.table([{label: 'Wysyłka'}, {label: 'Braki'}, {label: 'Decyzja'}, {label: 'Notatka'}],
                decided.map(i => ({cells: [what(i), sigs(i) || '<small>uzupełnione</small>', status(i), esc(eff(i).note || '—')]}))) : ''}
            <p class="tw-note">« Zakończ » zalicza zadanie « Dokumenty wysyłek miesiąca ». Dokumenty nie są zmieniane, nic nie jest wysyłane.</p>`;
    }

    function render() {
        const top = D.can_finish ? reached : Math.min(reached, 2);
        if (step > top) step = top;
        const body = step === 1 ? step1() : (step === 2 ? step2() : step3());
        const next = step === 1 ? {label: 'Dalej : do decyzji', attr: 'data-sd-next'}
            : step === 2 ? {label: 'Dalej : zatwierdź', attr: 'data-sd-next', disabled: !D.can_finish}
                : (D.done ? null : {label: 'Zakończ', attr: 'data-sd-finish', final: true, disabled: busy || !D.can_finish});
        app.innerHTML = tw.frame({title: 'Dokumenty wysyłek miesiąca', sub: `${D.month_label} · krok ${step} z 3 · ok. 30 min`, steps: STEPS, step, reached: top, body,
            prev: step > 1 ? 'Wstecz' : null, next, later: !D.done && step < 3});
    }

    const refresh = (key) => {
        const i = D.review.find(x => x.key === key), r = draft[key], a = i && i.decision, btn = app.querySelector(`[data-sd-save="${sel(key)}"]`);
        if (!btn || !i) return;
        btn.disabled = !r.decision || busy || (D.note_required.includes(r.decision) && r.note.trim().length < 3) || (a && a.code === r.decision && (a.note || '') === r.note);
    };
    tw.bind(app, (n) => { step = n === -1 ? Math.max(1, step - 1) : n; render(); });
    app.addEventListener('input', (e) => {
        const n = e.target.closest('[data-sd-note]');
        if (!n) return;
        (draft[n.dataset.sdNote] ??= {decision: '', note: ''}).note = n.value;
        refresh(n.dataset.sdNote);
    });
    app.addEventListener('click', async (e) => {
        if (e.target.closest('[data-sd-next]')) { step = Math.min(3, step + 1); reached = Math.max(reached, step); render(); return; }
        const r = e.target.closest('[data-sd-dec]');
        if (r) { const key = r.dataset.sdDec; (draft[key] ??= {decision: '', note: ''}).decision = r.dataset.v; render(); app.querySelector(`[data-sd-note="${sel(key)}"]`)?.focus(); return; }
        const s = e.target.closest('[data-sd-save]');
        if (s) {
            const key = s.dataset.sdSave, d = draft[key];
            if (!d || !d.decision || busy) return;
            busy = true; s.disabled = true;
            // seen : braki affichés (si complétés entre-temps dans l'ERP, « uzupełnione » s'enregistre avec eux).
            const it = D.review.find(x => x.key === key);
            const res = await tw.post('/ajax/shipments/documents-month/decision', {key, decision: d.decision, note: d.note, seen: it ? it.signals.map(x => x.code) : []});
            busy = false;
            if (res.ok) { D = res.data; delete draft[key]; }
            render();
            return;
        }
        if (e.target.closest('[data-sd-finish]')) {
            if (busy) return;
            busy = true; render();
            const res = await tw.post('/ajax/shipments/documents-month/finish', {});
            busy = false;
            if (res.ok) { at.notify(true, res.message); D = res.data; }
            render();
        }
    });
    render();
})();
