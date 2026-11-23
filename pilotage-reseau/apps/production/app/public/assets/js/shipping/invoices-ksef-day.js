// [378] Faktury i KSeF dnia (Administracja i fakturowanie) : 1. wysyłki dnia, ich faktury WDT i statusy KSeF (z zaległymi),
// 2. un résultat par document signalé (poprawione / w toku / bez faktury), 3. validation → la tâche du poste se valide seule.
// Rien n'est envoyé à KSeF depuis cet écran : les liens ouvrent « Wysyłki i dokumenty » (bouton « Wyślij KSeF »).
(() => {
    const app = document.getElementById('ikApp');
    if (!app || !window.tw) return;
    const {esc, nf} = tw;
    let D = JSON.parse(document.getElementById('ikBoot').textContent);
    let step = 1, reached = 1, busy = false;
    // Brouillons de l'étape 2 (résultat choisi, note) par expédition, gardés entre deux rendus.
    const draft = {};
    const STEPS = ['Przegląd', 'Do wyjaśnienia', 'Zatwierdź'];
    const RES_CLS = {fixed: 'ok', in_progress: 'warn', justified: 'warn'};
    const SIG_CLS = {no_wdt: 'bad', not_invoiced: 'bad', ksef_not_sent: 'warn', ksef_rejected: 'bad', ksef_failed: 'bad', ksef_pending: 'warn'};
    const SHORT = {no_wdt: 'bez WDT', not_invoiced: 'bez daty faktury', ksef_not_sent: 'niewysłana', ksef_rejected: 'odrzucona', ksef_failed: 'błąd wysyłki', ksef_pending: 'bez odpowiedzi'};
    const KSEF_CLS = {accepted: 'ok', rejected: 'bad', failed: 'bad'};
    const all = () => D.docs.concat(D.carried);
    const review = () => all().filter(d => d.review);
    const canFinish = () => !!D.can_finish;
    // [387] Propositions de l'IA du matin (aiProp, ai-proposals.js) : rien si D.ai est vide.
    const ai = window.aiProp || null;
    const ksef = (d) => d.wdt ? `${tw.pill(d.ksef.label, d.ksef.out ? '' : (KSEF_CLS[d.ksef.status] || (d.ksef.status ? 'warn' : '')))}${d.ksef.env === 'test' ? ' <small style="display:inline">test</small>' : ''}${d.ksef.number ? `<small>${esc(d.ksef.number)}</small>` : ''}` : '<span style="color:#999">—</span>';
    const wdt = (d) => d.wdt ? `<b>${esc(d.wdt.number)}</b><small>${d.wdt.invoiced ? 'faktura ' + esc(tw.dm(d.wdt.invoiced)) : '<span class="tw-red">bez daty faktury</span>'}</small>` : '<span class="tw-red">brak WDT</span>';
    const sigs = (d) => d.signals.map(s => tw.pill(SHORT[s.code] || s.code, SIG_CLS[s.code] || '')).join(' ')
        || (d.wdt && d.ksef.status !== 'accepted' && !d.ksef.out ? `<span style="color:#7a6a55">w terminie (${D.pending_days} ${tw.plural(D.pending_days, 'dzień', 'dni', 'dni')})</span>` : '<span class="tw-green">OK</span>');
    const status = (d) => !d.review ? tw.pill('OK', 'ok') : (d.waiting ? tw.pill(d.check ? (d.check.result === 'fixed' ? 'znów problem' : 'do wyjaśnienia dziś') : 'do wyjaśnienia', 'bad') : tw.pill(d.check.label, RES_CLS[d.check.result] || ''));
    const day = (d) => `${esc(d.day_label)}${d.today ? '<small>dziś</small>' : ''}${d.carried ? '<small class="tw-red">zaległa</small>' : ''}`;
    const links = (d) => d.links.length ? `<div style="display:flex;gap:4px 14px;flex-wrap:wrap">${d.links.map(l => `<a class="tw-link" href="${at.root}${esc(l.url)}" target="_blank" rel="noopener" style="padding:0"><i class="bi ${esc(l.icon)}"></i> ${esc(l.label)}</a>`).join('')}</div>` : '';

    function step1() {
        const t = D.totals, bc = t.by_code;
        const row = (d) => ({cls: d.waiting ? 'hl' : '', cells: [day(d), `<b>${esc(d.customer.name)}</b>`, wdt(d), ksef(d), sigs(d), status(d)]});
        const head = [{label: 'Wysyłka'}, {label: 'Klient'}, {label: 'Faktura WDT'}, {label: 'KSeF'}, {label: 'Sygnały'}, {label: ''}];
        const carried = D.carried.length ? `<div class="tw-cap">Zaległe z wcześniejszych wysyłek (${D.carried.length}) — problem nadal otwarty</div>` + tw.table(head, D.carried.map(row)) : '';
        const out = t.out_of_ksef ? tw.alert(`${t.out_of_ksef} ${tw.plural(t.out_of_ksef, 'wysyłka', 'wysyłki', 'wysyłek')} sprzed pierwszej wysyłki do KSeF${D.ksef_since ? ' (' + esc(tw.dm(D.ksef_since)) + ')' : ''} — poza KSeF, bez sygnału.`, '', 'bi-info-circle') : '';
        return `<p class="tw-lead">Wysyłki <b>${esc(D.window.label)}</b> i ich faktury WDT w KSeF. Faktury wysyła się w « Wysyłki i dokumenty » (przycisk « Wyślij KSeF ») albo w « Wyślij dzień » ; statusy KSeF odświeżane co 5 minut. Dokumenty z sygnałem trafiają do kroku 2.</p>
            ${tw.tiles([{label: 'Wysyłki', value: String(t.shipments), sub: `${t.wdt} z fakturą WDT`},
                {label: 'Przyjęte w KSeF', value: `${t.accepted} z ${t.wdt}`, sub: t.wdt ? `${Math.round(100 * t.accepted / t.wdt)} % faktur` : 'brak faktur', cls: t.accepted === t.wdt ? 'ok' : ''},
                {label: 'Odrzucone / błąd', value: String(bc.ksef_rejected + bc.ksef_failed), sub: `${bc.ksef_rejected} odrzucone · ${bc.ksef_failed} błąd wysyłki`, cls: bc.ksef_rejected + bc.ksef_failed ? 'warn' : ''},
                {label: `Czekają ≥ ${D.pending_days} ${tw.plural(D.pending_days, 'dzień', 'dni', 'dni')}`, value: String(bc.ksef_not_sent + bc.ksef_pending), sub: `${bc.ksef_not_sent} niewysłane · ${bc.ksef_pending} bez odpowiedzi`, cls: bc.ksef_not_sent + bc.ksef_pending ? 'warn' : ''},
                {label: 'Bez faktury', value: String(bc.no_wdt + bc.not_invoiced), sub: `${bc.no_wdt} bez WDT · ${bc.not_invoiced} bez daty`, cls: bc.no_wdt + bc.not_invoiced ? 'warn' : ''},
                {label: 'Do wyjaśnienia', value: String(t.review), sub: t.review ? `${t.resolved} z ${t.review} z wynikiem` : 'brak problemów', cls: t.waiting ? 'warn' : 'ok'}])}
            ${out}
            ${tw.table(head, D.docs.map(row), 'Brak wysyłek w tym okresie.')}
            ${carried}
            <p class="tw-note">Sygnały : wysyłka bez faktury WDT ; WDT bez daty faktury ; faktura niewysłana do KSeF albo bez odpowiedzi KSeF od ${D.pending_days} ${tw.plural(D.pending_days, 'dnia', 'dni', 'dni')} (ksef_day.pending_days) ; odrzucona przez KSeF ; błąd wysyłki.
                Zakres : od poprzedniego dnia roboczego do dziś${D.window.kind === 'catchup' ? ` (nadrabianie, najwyżej ${D.catchup_days} dni — ksef_day.catchup_days)` : ''} ; zaległe : ${D.lookback_days} dni wstecz (ksef_day.lookback_days) — Ustawienia › Parametry.</p>`;
    }

    function resultCell(d) {
        const r = draft[d.key] ?? (draft[d.key] = {result: d.check && !d.waiting ? d.check.result : '', note: d.check ? (d.check.note || '') : ''});
        const ok = d.ksef.status === 'accepted' && !d.signals.length;
        const seg = Object.entries(D.results).map(([k, l]) => `<button type="button" class="${r.result === k ? 'on' : ''}" data-ik-res="${esc(d.key)}" data-v="${k}" ${k === 'fixed' && !ok ? 'title="Zapisze się, gdy KSeF przyjmie fakturę"' : ''}>${esc(l)}</button>`).join('');
        const need = r.result === 'in_progress' || r.result === 'justified';
        const ph = r.result === 'in_progress' ? 'Notatka (wymagana) : co jest robione, kto, do kiedy' : (r.result === 'justified' ? 'Notatka (wymagana) : dlaczego bez faktury' : 'Notatka (opcjonalnie)');
        const saved = d.check ? `<small>${tw.pill(d.check.label, RES_CLS[d.check.result] || '')} ${esc(d.check.on === D.today ? d.check.at : tw.dm(d.check.on))}${d.check.by ? ' · ' + esc(d.check.by) : ''}${d.waiting ? ` · <span class="tw-red">${d.check.result === 'fixed' ? 'problem wrócił' : 'nadal otwarte — wyjaśnij dziś'}</span>` : ''}</small>` : '';
        const dirty = !d.check || d.waiting || d.check.result !== r.result || (d.check.note || '') !== r.note;
        return `<div style="display:grid;gap:6px;min-width:440px">${ai && d.waiting ? ai.box(D.ai, d.key) : ''}<div class="tw-seg" style="justify-self:start">${seg}</div>
            <div style="display:flex;gap:6px"><input class="form-control form-control-sm" data-ik-note="${esc(d.key)}" maxlength="500" value="${esc(r.note)}" placeholder="${esc(ph)}" style="min-height:36px;border-radius:9px">
            <button type="button" class="tw-btn ghost sm" data-ik-save="${esc(d.key)}" ${!r.result || !dirty || busy || (need && r.note.trim().length < 3) ? 'disabled' : ''}>Zapisz</button></div>${links(d)}${saved}</div>`;
    }

    function step2() {
        const list = review(), t = D.totals;
        if (!list.length) return tw.alert('Nic do wyjaśnienia : każda wysyłka ma fakturę WDT przyjętą w KSeF (albo jeszcze w terminie).', 'ok', 'bi-check-circle');
        const rows = list.map(d => ({cls: d.waiting ? 'hl' : '', cells: [
            `<b>${esc(d.customer.name)}</b><small>${esc(d.day_label)}${d.carried ? ' · zaległa' : ''}</small>`,
            `${wdt(d)}<div style="margin-top:4px">${ksef(d)}</div>`,
            d.signals.length ? d.signals.map(s => `<div style="display:flex;gap:6px;align-items:baseline;margin:1px 0"><i class="bi bi-exclamation-circle ${SIG_CLS[s.code] === 'bad' ? 'tw-red' : ''}"></i><span><b>${esc(s.label)}</b> : ${esc(s.detail)}</span></div>`).join('') : '<span class="tw-green">Problem rozwiązany</span>',
            resultCell(d)]}));
        return `${ai ? ai.banner(D.ai) : ''}<p class="tw-lead">Dla każdego dokumentu zapisz wynik : <b>Wysłane ponownie / poprawione</b> (sprawdzane : faktura przyjęta w KSeF), <b>W toku</b> z notatką
                albo <b>Bez faktury — uzasadnione</b> z notatką. Krok 3 otworzy się, gdy wszystkie mają wynik.</p>
            ${tw.tiles([{label: 'Do wyjaśnienia', value: String(t.review), sub: `${t.signals} ${tw.plural(t.signals, 'sygnał', 'sygnały', 'sygnałów')}${t.carried ? ` · ${t.carried} zaległe` : ''}`},
                {label: 'Z wynikiem', value: `${t.resolved} z ${t.review}`, sub: t.waiting ? `zostało ${t.waiting}` : 'wszystkie wyjaśnione', cls: t.waiting ? 'warn' : 'ok'},
                {label: 'Poprawione', value: String(t.fixed), sub: 'sprawdzone : przyjęte w KSeF'}, {label: 'W toku / bez faktury', value: `${t.in_progress} / ${t.justified}`, sub: 'z notatką'}])}
            ${tw.table([{label: 'Klient'}, {label: 'Dokument'}, {label: 'Problem'}, {label: 'Wynik'}], rows)}
            <p class="tw-note">Asystent niczego nie wysyła do KSeF : wyślij ponownie w « Wysyłki i dokumenty » (filtr « KSeF : nieprzyjęte »), popraw ceny WDT, a gdy status zmieni się na « przyjęta », zapisz « Wysłane ponownie / poprawione ». « W toku » obowiązuje na dziś — jutro dokument wróci, jeśli problem trwa.</p>`;
    }

    function step3() {
        const t = D.totals;
        if (D.done) return tw.done('Faktury i KSeF dnia sprawdzone · ' + (D.done.at || ''), D.done.summary + ' · zadanie zaliczone');
        const decided = review().filter(d => d.check);
        return `<p class="tw-lead">Gotowe : <b>${esc(D.summary)}</b>.</p>
            ${tw.tiles([{label: 'Wysyłki', value: String(t.shipments), sub: `${t.clean} bez problemów`, cls: 'ok'}, {label: 'Poprawione', value: String(t.fixed), sub: 'przyjęte w KSeF'},
                {label: 'W toku', value: String(t.in_progress), sub: 'notatki poniżej', cls: t.in_progress ? 'warn' : ''}, {label: 'Bez faktury — uzasadnione', value: String(t.justified), sub: 'notatki poniżej'}])}
            ${decided.length ? `<div class="tw-cap">Wyniki</div>` + tw.table([{label: 'Wysyłka'}, {label: 'Klient'}, {label: 'Faktura WDT'}, {label: 'KSeF'}, {label: 'Wynik'}, {label: 'Notatka'}],
                decided.map(d => ({cells: [day(d), esc(d.customer.name), d.wdt ? esc(d.wdt.number) : '—', ksef(d), tw.pill(d.check.label, RES_CLS[d.check.result] || ''), esc(d.check.note || '—')]}))) : ''}
            <p class="tw-note">« Zakończ » zalicza zadanie « Faktury i KSeF dnia » na dziś. Nic nie jest wysyłane do KSeF ani zmieniane w dokumentach.</p>`;
    }

    function render() {
        const top = canFinish() ? reached : Math.min(reached, 2);
        if (step > top) step = top;
        const body = step === 1 ? step1() : (step === 2 ? step2() : step3());
        const next = step === 1 ? {label: 'Dalej : do wyjaśnienia', attr: 'data-ik-next'}
            : step === 2 ? {label: 'Dalej : zatwierdź', attr: 'data-ik-next', disabled: !canFinish()}
                : (D.done ? null : {label: 'Zakończ', attr: 'data-ik-finish', final: true, disabled: busy || !canFinish()});
        app.innerHTML = tw.frame({title: 'Faktury i KSeF dnia', sub: `${D.label} · krok ${step} z 3 · ok. 10 min`, steps: STEPS, step, reached: top, body,
            prev: step > 1 ? 'Wstecz' : null, next, later: !D.done && step < 3});
    }

    tw.bind(app, (n) => { step = n === -1 ? Math.max(1, step - 1) : n; render(); });
    // « Przyjmij » / « Popraw » : la proposition remplit le brouillon (résultat et note) ; l'enregistrement reste « Zapisz ».
    if (ai) ai.bind(app, {ai: () => D.ai, render, apply: (p, edit) => {
        draft[p.ref] = {result: p.decision, note: p.note || ''};
        render();
        const n = app.querySelector(`[data-ik-note="${CSS.escape(p.ref)}"]`);
        if (n && edit) { n.focus(); n.select(); }
    }});
    app.addEventListener('input', (e) => {
        const n = e.target.closest('[data-ik-note]');
        if (!n) return;
        const key = n.dataset.ikNote;
        (draft[key] ??= {result: '', note: ''}).note = n.value;
        const d = all().find(x => x.key === key), r = draft[key];
        const btn = app.querySelector(`[data-ik-save="${CSS.escape(key)}"]`);
        const need = r.result === 'in_progress' || r.result === 'justified';
        if (btn && d) btn.disabled = !r.result || busy || (need && r.note.trim().length < 3) || (d.check && !d.waiting && d.check.result === r.result && (d.check.note || '') === r.note);
    });
    app.addEventListener('click', async (e) => {
        if (e.target.closest('[data-ik-next]')) { step = Math.min(3, step + 1); reached = Math.max(reached, step); render(); return; }
        const r = e.target.closest('[data-ik-res]');
        if (r) { const key = r.dataset.ikRes; (draft[key] ??= {result: '', note: ''}).result = r.dataset.v; render(); app.querySelector(`[data-ik-note="${CSS.escape(key)}"]`)?.focus(); return; }
        const s = e.target.closest('[data-ik-save]');
        if (s) {
            const key = s.dataset.ikSave, d = draft[key], doc = all().find(x => x.key === key);
            if (!d || !d.result || !doc || busy) return;
            busy = true; s.disabled = true;
            const res = await tw.post('/ajax/shipments/ksef-day/result', {id_shipment: doc.id, result: d.result, note: d.note});
            busy = false;
            if (res.ok) { D = res.data; delete draft[key]; if (canFinish()) reached = Math.max(reached, 2); }
            render();
            return;
        }
        if (e.target.closest('[data-ik-finish]')) {
            if (busy) return;
            busy = true; render();
            const res = await tw.post('/ajax/shipments/ksef-day/finish', {});
            busy = false;
            if (res.ok) { at.notify(true, res.message); D = res.data; }
            render();
        }
    });
    render();
})();
