// [381] Palety zwrotne (Logistyka, Kierowca-dostawca — co tydzień) : 1. saldo palet EUR klientów (dostarczone − zwrócone
// z tabletu kierowcy), brakujące palety bez uzasadnienia i uszkodzone palety tygodnia, 2. une décision par client signalé
// (przypomnienie / uzgodnione / spisane), 3. récapitulatif et validation → la tâche du poste se valide seule. Rien n'est
// changé dans les livraisons depuis cet écran (liens vers Dostawy › potwierdzenia) et rien n'est envoyé (ni SMS ni e-mail).
(() => {
    const app = document.getElementById('prApp');
    if (!app || !window.tw) return;
    const {esc} = tw;
    let D = JSON.parse(document.getElementById('prBoot').textContent);
    let step = 1, reached = 1, busy = false;
    // Brouillons de l'étape 2 (décision choisie, note) par client, gardés entre deux rendus.
    const draft = {};
    const STEPS = ['Przegląd', 'Do wyjaśnienia', 'Zatwierdź'];
    const DEC_CLS = {reminder: 'warn', agreed: 'ok', written_off: 'bad'};
    const SIG_CLS = {missing: 'bad', damaged: 'bad', balance_high: 'warn'};
    const SHORT = {missing: 'brak palet', damaged: 'uszkodzona', balance_high: 'saldo > próg'};
    const pal = (n) => tw.plural(Math.abs(n), 'paleta', 'palety', 'palet');
    const canFinish = () => !!D.can_finish;
    const delta = (v) => v > 0 ? `<span class="tw-red">${tw.signed(v)}</span>` : (v < 0 ? `<span class="tw-green">${tw.signed(v)}</span>` : '<span style="color:#999">0</span>');
    const bal = (c) => c.over ? `<b class="tw-red">${c.balance}</b>` : (c.balance > 0 ? `<b>${c.balance}</b>` : `<span class="tw-green">${c.balance}</span>`);
    const sigs = (c) => c.signals.map(s => tw.pill(SHORT[s.code] || s.code, SIG_CLS[s.code] || '')).join(' ') || '<span class="tw-green">OK</span>';
    const status = (c) => c.waiting ? tw.pill('do wyjaśnienia', 'bad') : (c.decision ? tw.pill(c.decision.label, DEC_CLS[c.decision.code] || '') : '');
    const link = (l) => l ? `<a class="tw-link" href="${at.root}${esc(l.href)}" target="_blank" rel="noopener" style="padding:0"><i class="bi bi-truck"></i> ${esc(l.label)}</a>` : '';
    const nodata = () => tw.alert(`<b>${esc(D.sources.pallets.note || 'brak danych')}</b> — w ${D.balance_weeks + 1} ${tw.plural(D.balance_weeks + 1, 'tygodniu', 'tygodniach', 'tygodniach')} żaden potwierdzony postój nie ma wpisanych palet (« Palety dostarczone », « Palety zwrócone (EUR) » na tablecie kierowcy). Saldo nie może być policzone.`, 'warn', 'bi-database-x');

    function step1() {
        const t = D.totals;
        const tiles = [{label: 'Saldo palet EUR', value: D.available ? String(t.balance) : '—', sub: D.available ? `${tw.signed(t.delta)} vs poprzedni tydzień · ${t.customers_positive} ${tw.plural(t.customers_positive, 'klient', 'klientów', 'klientów')} z saldem` : 'brak danych', cls: t.delta > 0 ? 'warn' : ''},
            {label: `Powyżej progu (${D.threshold})`, value: String(t.customers_over), sub: tw.plural(t.customers_over, 'klient', 'klientów', 'klientów'), cls: t.customers_over ? 'warn' : 'ok'},
            {label: `Tydzień ${D.week_label}`, value: `${t.week_delivered} / ${t.week_returned}`, sub: `dostarczone / zwrócone EUR · ${t.week_stops_pallets} z ${t.week_stops} postojów z paletami`},
            {label: 'Brakujące bez uzasadnienia', value: String(t.missing_pallets), sub: `${t.missing_stops} ${tw.plural(t.missing_stops, 'postój', 'postoje', 'postojów')}`, cls: t.missing_stops ? 'warn' : 'ok'},
            {label: 'Uszkodzone palety', value: String(t.damaged), sub: D.sources.damaged.available ? 'zgłoszenia z tabletu' : D.sources.damaged.note, cls: t.damaged ? 'warn' : 'ok'},
            {label: 'Do wyjaśnienia', value: String(t.review), sub: t.review ? `${t.resolved} z ${t.review} z decyzją` : 'brak sygnałów', cls: t.waiting ? 'warn' : 'ok'}];
        const head = [{label: 'Klient'}, {label: `Dostarczone (${D.balance_weeks} tyg.)`, num: true}, {label: 'Zwrócone EUR', num: true}, {label: 'Saldo', num: true},
            {label: 'vs poprz. tydz.', num: true}, {label: 'Tydzień dost. / zwr.', num: true}, {label: 'Ostatnia dostawa'}, {label: 'Sygnały'}, {label: ''}];
        const rows = D.balances.map(c => ({cls: c.waiting ? 'hl' : '', cells: [`<b>${esc(c.name)}</b>`, String(c.delivered), String(c.returned), bal(c), delta(c.delta),
            `${c.week_delivered} / ${c.week_returned}`, esc(c.last_ship_label || '—'), sigs(c), status(c)]}));
        const ev = [];
        D.review.forEach(c => {
            c.missing.forEach(s => ev.push({day: s.day, cells: [esc(s.day_label), `<b>${esc(c.name)}</b>`, tw.pill('brak palet', 'bad'), `${s.delivered} / ${s.returned ?? 0}`,
                `<b class="tw-red">${s.short}</b>`, '<span class="tw-red">bez uzasadnienia</span>' + (s.note ? `<small>${esc(s.note)}</small>` : ''), esc(s.driver || '—'), link(s.link)]}));
            c.damaged.forEach(d => ev.push({day: d.day, cells: [esc(d.day_label), `<b>${esc(c.name)}</b>`, tw.pill(d.label, 'bad'), '—', '—', esc(d.note || 'bez opisu'), esc(d.driver || '—'), link(d.link)]}));
        });
        ev.sort((a, b) => a.day < b.day ? -1 : (a.day > b.day ? 1 : 0));
        return `<p class="tw-lead">Saldo palet EUR klientów : <b>palety dostarczone − palety zwrócone</b> wpisane przez kierowcę na tablecie przy potwierdzeniu dostawy (jak w « Dostawy : potwierdzenia »),
                z ${D.balance_weeks} ${tw.plural(D.balance_weeks, 'tygodnia', 'tygodni', 'tygodni')} do końca tygodnia <b>${esc(D.week_label)}</b>. Klienci z saldem powyżej ${D.threshold} ${pal(D.threshold)}, z brakującą paletą bez uzasadnienia albo z uszkodzoną paletą w tygodniu trafiają do kroku 2.</p>
            ${D.available ? '' : nodata()}
            ${tw.tiles(tiles)}
            <div class="tw-cap">Saldo klientów (${D.balances.length})</div>
            ${tw.table(head, rows, D.available ? 'Brak palet w okresie.' : 'Brak danych — tablet kierowcy nie zapisuje palet.')}
            <div class="tw-cap">Tydzień ${esc(D.week_label)} : brakujące i uszkodzone palety (${ev.length})</div>
            ${tw.table([{label: 'Dzień'}, {label: 'Klient'}, {label: 'Rodzaj'}, {label: 'Dost. / zwr.', num: true}, {label: 'Brakuje', num: true}, {label: 'Uzasadnienie / opis'}, {label: 'Kierowca'}, {label: ''}],
                ev, 'Brak brakujących i uszkodzonych palet w tygodniu.')}
            <p class="tw-note">Brakująca paleta : postój tygodnia, na którym dostarczono więcej palet, niż zwrócono, a kierowca nie wpisał uzasadnienia (« Dlaczego brakuje palet ? »). Uszkodzona paleta : zgłoszenie « Coś nie tak › Uszkodzona paleta » z tabletu.
                Saldo : postoje potwierdzone (dostarczono albo problem), puste pole = 0. Okres ${D.balance_weeks} tyg. (pallets_returns.balance_weeks), próg ${D.threshold} (pallets_returns.threshold) — Ustawienia › Parametry.</p>`;
    }

    function decisionCell(c) {
        const r = draft[c.key] ?? (draft[c.key] = {decision: c.decision ? c.decision.code : '', note: c.decision ? (c.decision.note || '') : ''});
        const seg = Object.entries(D.decisions).map(([k, l]) => `<button type="button" class="${r.decision === k ? 'on' : ''}" data-pr-dec="${esc(c.key)}" data-v="${k}">${esc(l)}</button>`).join('');
        const need = D.note_required.includes(r.decision);
        const ph = r.decision === 'reminder' ? 'Notatka (wymagana) : kto, kiedy odbieramy, ile palet' : (r.decision === 'written_off' ? 'Notatka (wymagana) : ile palet, dlaczego, kto fakturuje' : 'Notatka (opcjonalnie) : z kim uzgodnione');
        const saved = c.decision ? `<small>${tw.pill(c.decision.label, DEC_CLS[c.decision.code] || '')} ${esc(c.decision.at)}${c.decision.by ? ' · ' + esc(c.decision.by) : ''}</small>` : '';
        const prev = c.previous ? `<small style="color:#7a6a55">Poprzedni tydzień : ${esc(c.previous.label)}${c.previous.balance !== null ? ' (saldo ' + c.previous.balance + ')' : ''}${c.previous.note ? ' — ' + esc(c.previous.note) : ''}</small>` : '';
        const dirty = !c.decision || c.decision.code !== r.decision || (c.decision.note || '') !== r.note;
        return `<div style="display:grid;gap:6px;min-width:440px"><div class="tw-seg" style="justify-self:start">${seg}</div>
            <div style="display:flex;gap:6px"><input class="form-control form-control-sm" data-pr-note="${esc(c.key)}" maxlength="500" value="${esc(r.note)}" placeholder="${esc(ph)}" style="min-height:36px;border-radius:9px">
            <button type="button" class="tw-btn ghost sm" data-pr-save="${esc(c.key)}" ${!r.decision || !dirty || busy || (need && r.note.trim().length < 3) ? 'disabled' : ''}>Zapisz</button></div>${saved}${prev}</div>`;
    }

    function step2() {
        const list = D.review, t = D.totals;
        if (!list.length) return (D.available ? '' : nodata()) + tw.alert('Nic do wyjaśnienia : saldo klientów w progu, brak brakujących i uszkodzonych palet w tygodniu.', 'ok', 'bi-check-circle');
        const rows = list.map(c => ({cls: c.waiting ? 'hl' : '', cells: [
            `<b>${esc(c.name)}</b><small>saldo ${c.balance} ${pal(c.balance)} · ${tw.signed(c.delta)} vs poprz. tydz.</small><small>${c.delivered} dostarczone − ${c.returned} zwrócone (${D.balance_weeks} tyg.)</small>`,
            c.week_stops.length ? c.week_stops.map(s => `<small>${esc(s.day_label)} : ${s.delivered ?? '—'} / ${s.returned ?? '—'}${s.pallets_note ? ' · « ' + esc(s.pallets_note) + ' »' : ''}${s.status === 'problem' ? ' · ' + esc(s.status_label) : ''} ${link(s.link)}</small>`).join('') : '<small style="color:#999">brak dostaw w tygodniu</small>',
            c.signals.length ? c.signals.map(s => `<div style="display:flex;gap:6px;align-items:baseline;margin:1px 0"><i class="bi bi-exclamation-circle ${SIG_CLS[s.code] === 'bad' ? 'tw-red' : ''}"></i><span><b>${esc(s.label)}</b> : ${esc(s.detail)}</span></div>`).join('') : '<span class="tw-green">Sygnał zniknął</span>',
            decisionCell(c)]}));
        return `<p class="tw-lead">Dla każdego klienta zapisz decyzję : <b>Przypomnienie / odbiór zaplanowany</b> (notatka : kto, kiedy), <b>OK — saldo uzgodnione</b> z klientem
                albo <b>Spisane / do faktury</b> (notatka). Krok 3 otworzy się, gdy wszyscy mają decyzję. Asystent niczego nie wysyła do klienta.</p>
            ${tw.tiles([{label: 'Do wyjaśnienia', value: String(t.review), sub: `${t.by_code.balance_high} saldo · ${t.by_code.missing} brak palet · ${t.by_code.damaged} uszkodzone`},
                {label: 'Z decyzją', value: `${t.resolved} z ${t.review}`, sub: t.waiting ? `zostało ${t.waiting}` : 'wszyscy wyjaśnieni', cls: t.waiting ? 'warn' : 'ok'},
                {label: 'Przypomnienie / odbiór', value: String(t.reminder), sub: t.reminder ? `${t.reminder_balance} ${pal(t.reminder_balance)} do odebrania` : 'z notatką'},
                {label: 'Uzgodnione / spisane', value: `${t.agreed} / ${t.written_off}`, sub: 'saldo OK · do faktury'}])}
            ${tw.table([{label: 'Klient'}, {label: 'Dostawy tygodnia (dost. / zwr.)'}, {label: 'Problem'}, {label: 'Decyzja'}], rows)}
            <p class="tw-note">Decyzje dotyczą ocenianego tygodnia — w przyszłym tygodniu klient wróci, jeśli saldo dalej jest powyżej progu. Postoje i zgłoszenia z tabletu nie są zmieniane ; brakujące uzasadnienie można dopisać tylko na tablecie kierowcy.</p>`;
    }

    function step3() {
        const t = D.totals;
        if (D.done) return tw.done('Palety zwrotne przejrzane · ' + (D.done.at || ''), D.done.summary + ' · zadanie zaliczone');
        const decided = D.review.filter(c => c.decision);
        const remind = decided.filter(c => c.decision.code === 'reminder');
        const series = tw.table([{label: 'Tydzień'}, {label: 'Postoje', num: true}, {label: 'Dostarczone', num: true}, {label: 'Zwrócone EUR', num: true}, {label: 'Różnica', num: true}],
            D.series.map(w => ({cls: w.reviewed ? 'hl' : '', cells: [esc(w.label) + (w.reviewed ? ' <small>oceniany</small>' : ''), String(w.stops), String(w.delivered), String(w.returned), delta(w.delivered - w.returned)]})),
            'Brak danych.');
        return `<p class="tw-lead">Gotowe : <b>${esc(D.summary)}</b>.</p>
            ${D.available ? '' : nodata()}
            ${tw.tiles([{label: 'Saldo palet EUR', value: D.available ? String(t.balance) : '—', sub: `${t.customers_positive} ${tw.plural(t.customers_positive, 'klient', 'klientów', 'klientów')} z saldem · ${t.customers_over} powyżej progu`},
                {label: 'vs poprzedni tydzień', value: D.available ? tw.signed(t.delta) : '—', sub: `${t.prev_balance} → ${t.balance}`, cls: t.delta > 0 ? 'warn' : 'ok'},
                {label: 'Do przypomnienia / odbioru', value: String(remind.length), sub: remind.length ? `${t.reminder_balance} ${pal(t.reminder_balance)}` : 'nikt', cls: remind.length ? 'warn' : 'ok'},
                {label: 'Decyzje', value: `${t.resolved} z ${t.review}`, sub: `${t.reminder} przypomnienie · ${t.agreed} uzgodnione · ${t.written_off} spisane`, cls: 'ok'}])}
            ${remind.length ? `<div class="tw-cap">Klienci do przypomnienia / odbioru (${remind.length})</div>` + tw.table([{label: 'Klient'}, {label: 'Saldo', num: true}, {label: 'Notatka'}],
                remind.map(c => ({cells: [`<b>${esc(c.name)}</b>`, bal(c), esc(c.decision.note || '—')]}))) : ''}
            <div class="tw-cap">Palety tydzień po tygodniu (${D.balance_weeks} tyg.)</div>${series}
            ${decided.length ? `<div class="tw-cap">Decyzje</div>` + tw.table([{label: 'Klient'}, {label: 'Saldo', num: true}, {label: 'Sygnały'}, {label: 'Decyzja'}, {label: 'Notatka'}],
                decided.map(c => ({cells: [esc(c.name), bal(c), sigs(c), tw.pill(c.decision.label, DEC_CLS[c.decision.code] || ''), esc(c.decision.note || '—')]}))) : ''}
            <p class="tw-note">« Zakończ » zalicza zadanie « Palety zwrotne » na ten tydzień (Logistyka, Kierowca-dostawca). Nic nie jest wysyłane do klientów, faktury nie są wystawiane.</p>`;
    }

    function render() {
        const top = canFinish() ? reached : Math.min(reached, 2);
        if (step > top) step = top;
        const body = step === 1 ? step1() : (step === 2 ? step2() : step3());
        const next = step === 1 ? {label: 'Dalej : do wyjaśnienia', attr: 'data-pr-next'}
            : step === 2 ? {label: 'Dalej : zatwierdź', attr: 'data-pr-next', disabled: !canFinish()}
                : (D.done ? null : {label: 'Zakończ', attr: 'data-pr-finish', final: true, disabled: busy || !canFinish()});
        app.innerHTML = tw.frame({title: 'Palety zwrotne', sub: `tydzień ${D.week_label} · krok ${step} z 3 · ok. 10 min`, steps: STEPS, step, reached: top, body,
            prev: step > 1 ? 'Wstecz' : null, next, later: !D.done && step < 3});
    }

    tw.bind(app, (n) => { step = n === -1 ? Math.max(1, step - 1) : n; render(); });
    app.addEventListener('input', (e) => {
        const n = e.target.closest('[data-pr-note]');
        if (!n) return;
        const key = n.dataset.prNote;
        (draft[key] ??= {decision: '', note: ''}).note = n.value;
        const c = D.review.find(x => x.key === key), r = draft[key];
        const btn = app.querySelector(`[data-pr-save="${CSS.escape(key)}"]`);
        const need = D.note_required.includes(r.decision);
        if (btn && c) btn.disabled = !r.decision || busy || (need && r.note.trim().length < 3) || (c.decision && c.decision.code === r.decision && (c.decision.note || '') === r.note);
    });
    app.addEventListener('click', async (e) => {
        if (e.target.closest('[data-pr-next]')) { step = Math.min(3, step + 1); reached = Math.max(reached, step); render(); return; }
        const r = e.target.closest('[data-pr-dec]');
        if (r) { const key = r.dataset.prDec; (draft[key] ??= {decision: '', note: ''}).decision = r.dataset.v; render(); app.querySelector(`[data-pr-note="${CSS.escape(key)}"]`)?.focus(); return; }
        const s = e.target.closest('[data-pr-save]');
        if (s) {
            const key = s.dataset.prSave, d = draft[key], c = D.review.find(x => x.key === key);
            if (!d || !d.decision || !c || busy) return;
            busy = true; s.disabled = true;
            const res = await tw.post('/ajax/logistics/pallets-returns/decision', {id_customer: c.id, decision: d.decision, note: d.note});
            busy = false;
            if (res.ok) { D = res.data; delete draft[key]; if (canFinish()) reached = Math.max(reached, 2); }
            render();
            return;
        }
        if (e.target.closest('[data-pr-finish]')) {
            if (busy) return;
            busy = true; render();
            const res = await tw.post('/ajax/logistics/pallets-returns/finish', {});
            busy = false;
            if (res.ok) { at.notify(true, res.message); D = res.data; }
            render();
        }
    });
    render();
})();
