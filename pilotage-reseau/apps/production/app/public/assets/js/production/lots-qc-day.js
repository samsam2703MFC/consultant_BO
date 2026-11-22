// [377] Partie i kontrola dnia (Specjalista ds. jakości) : 1. journées de groupe closes et leurs trous de traçabilité,
// 2. un résultat par journée à vérifier (uzupełnione / NC / zaakceptowane), 3. validation → la tâche du poste se valide seule.
(() => {
    const app = document.getElementById('lqApp');
    if (!app || !window.tw) return;
    const {esc, nf} = tw;
    let D = JSON.parse(document.getElementById('lqBoot').textContent);
    let step = 1, reached = 1, busy = false;
    // Brouillons de l'étape 2 (résultat choisi, note) par journée de groupe, gardés entre deux rendus.
    const draft = {};
    const STEPS = ['Przegląd', 'Do wyjaśnienia', 'Zatwierdź'];
    const RES_CLS = {completed: 'ok', nc: 'bad', accepted: 'warn'};
    const GAP_CLS = {lot_missing: 'bad', lot_reason: 'warn', qc_missing: 'bad', lot_expired: 'bad', lot_no_number: 'warn'};
    const SHORT = {lot_missing: 'bez partii', lot_reason: 'brak z powodem', qc_missing: 'bez kontroli', lot_expired: 'po DLC', lot_no_number: 'bez numeru'};
    const review = () => D.days.filter(d => d.review);
    const canFinish = () => !!D.can_finish;
    const group = (d) => `<span style="display:inline-flex;align-items:center;gap:6px"><i style="width:9px;height:9px;border-radius:50%;background:${esc(d.group.color || '#8D1D2C')};display:inline-block"></i><b>${esc(d.group.name)}</b></span>`;
    const counts = (d) => Object.entries(d.counts).filter(([, n]) => n > 0).map(([k, n]) => tw.pill(`${n} × ${SHORT[k] || k}`, GAP_CLS[k] || '')).join(' ') || '<span class="tw-green">kompletne</span>';
    const status = (d) => !d.review ? tw.pill('OK', 'ok') : (d.waiting ? tw.pill(d.check && d.check.result === 'completed' ? 'znów braki' : 'do wyjaśnienia', 'bad') : tw.pill(d.check.label, RES_CLS[d.check.result] || ''));

    function step1() {
        const t = D.totals, bc = t.by_code;
        const rows = D.days.map(d => ({cls: d.waiting ? 'hl' : '', cells: [
            `${esc(d.day_label)}${d.today ? '<small>dziś</small>' : ''}`, group(d),
            `${esc(d.closed_at)}${d.closed_by ? `<small>${esc(d.closed_by)}</small>` : ''}`,
            nf(d.batches), `${nf(d.qc_done)} z ${nf(d.batches)}${d.qc_nc ? `<small class="tw-red">${d.qc_nc} NC z tabletu</small>` : ''}`,
            counts(d), d.note ? `<span style="font-style:italic">« ${esc(d.note)} »</span>` : '<span style="color:#999">—</span>', status(d)]}));
        const unclosed = D.unclosed.length ? tw.alert(`<b>Niezatwierdzone na tablecie (${D.unclosed.length}) :</b> ${D.unclosed.map(u => `${esc(u.day_label)} ${esc(u.group)} (${u.batches} ${tw.plural(u.batches, 'partia', 'partie', 'partii')})`).join(' · ')}
            <small style="display:block">Sprawdzane po zatwierdzeniu dnia przez grupę (zadanie grupy « Dzień grupy »).</small>`, 'warn', 'bi-hourglass-split') : '';
        return `<p class="tw-lead">Dni grup zatwierdzone na tabletach <b>${esc(D.window.label)}</b>. Tablet pozwala zatwierdzić dzień z brakami i samą notatką — tu sprawdzasz identyfikowalność. Dni z brakiem albo z notatką trafiają do kroku 2.</p>
            ${tw.tiles([{label: 'Dni grup', value: String(t.days), sub: `${nf(t.batches)} ${tw.plural(t.batches, 'partia', 'partie', 'partii')} produkcji`},
                {label: 'Braki partii surowców', value: String(bc.lot_missing + bc.lot_reason), sub: `${bc.lot_missing} bez partii · ${bc.lot_reason} z powodem`, cls: bc.lot_missing ? 'warn' : ''},
                {label: 'Bez kontroli jakości', value: String(bc.qc_missing), sub: t.qc_nc ? `${t.qc_nc} NC z tabletu` : 'partie bez OK / NC', cls: bc.qc_missing ? 'warn' : ''},
                {label: 'Partie surowców', value: String(bc.lot_expired + bc.lot_no_number), sub: `${bc.lot_expired} po DLC · ${bc.lot_no_number} bez numeru`, cls: bc.lot_expired ? 'warn' : ''},
                {label: 'Do wyjaśnienia', value: String(t.review), sub: t.review ? `${t.resolved} z ${t.review} z wynikiem` : 'brak braków', cls: t.waiting ? 'warn' : 'ok'}])}
            ${unclosed}
            ${tw.table([{label: 'Dzień'}, {label: 'Grupa'}, {label: 'Zatwierdzony'}, {label: 'Partie', num: true}, {label: 'Kontrola jakości'}, {label: 'Braki'}, {label: 'Notatka z tabletu'}, {label: ''}],
                rows, 'Brak dni grup zatwierdzonych w tym okresie.')}
            <p class="tw-note">Braki : surowiec śledzony albo kompozycja innej grupy bez numeru partii (jak licznik « uzupełnione » na tablecie) ; « brak partii » z powodem ; partia bez kontroli jakości ;
                partia surowca użyta po dacie ważności (dzień produkcji) ; partia z rejestru bez numeru. Zakres : od poprzedniego dnia roboczego do dziś${D.window.kind === 'catchup' ? ` (nadrabianie, najwyżej ${D.catchup_days} dni — Ustawienia › Parametry, lots_qc.catchup_days)` : ''}.</p>`;
    }

    function gapList(d) {
        if (!d.gap_batches.length) return `<span class="tw-green">Braki uzupełnione</span><small>notatka z tabletu : « ${esc(d.note || '')} »</small>`;
        return d.gap_batches.map(b => `<div style="margin:3px 0 6px"><b>${esc(b.lot_code)}</b> · ${esc(b.product)} <small style="display:inline">${nf(b.quantity, b.quantity % 1 ? 1 : 0)} ${esc(b.unit || '')}</small>
            ${b.gaps.map(g => `<div style="display:flex;gap:6px;align-items:baseline;margin:1px 0 1px 8px"><i class="bi bi-exclamation-circle ${GAP_CLS[g.code] === 'bad' ? 'tw-red' : ''}"></i><span><b>${esc(g.label)}</b> : ${esc(g.detail)}</span></div>`).join('')}
            ${b.qc === 'nc' ? `<div style="margin-left:8px"><i class="bi bi-info-circle"></i> NC z tabletu${b.nc_id ? ` <a href="${at.root}/purchasing/nc" target="_blank" rel="noopener">#${b.nc_id}</a>` : ''}${b.qc_note ? ' : ' + esc(b.qc_note) : ''}</div>` : ''}</div>`).join('');
    }

    function resultCell(d) {
        const r = draft[d.key] ?? (draft[d.key] = {result: d.check ? d.check.result : '', note: d.check ? (d.check.note || '') : ''});
        const seg = Object.entries(D.results).map(([k, l]) => `<button type="button" class="${r.result === k ? 'on' : ''}" data-lq-res="${esc(d.key)}" data-v="${k}" ${k === 'completed' && d.gaps > 0 ? 'title="Najpierw uzupełnij braki na tablecie grupy"' : ''}>${esc(l)}</button>`).join('');
        const need = r.result === 'nc' || r.result === 'accepted';
        const ph = r.result === 'nc' ? 'Notatka (wymagana) : numer NC, co się stało' : (r.result === 'accepted' ? 'Notatka (wymagana) : dlaczego brak jest akceptowalny' : 'Notatka (opcjonalnie)');
        const saved = d.check ? `<small>${tw.pill(d.check.label, RES_CLS[d.check.result] || '')} ${esc(d.check.on === D.today ? d.check.at : tw.dm(d.check.on))}${d.check.by ? ' · ' + esc(d.check.by) : ''}${d.waiting ? ' · <span class="tw-red">braki wróciły — wybierz ponownie</span>' : ''}</small>` : '';
        const dirty = !d.check || d.waiting || d.check.result !== r.result || (d.check.note || '') !== r.note;
        const nc = r.result === 'nc' ? `<a class="tw-link" href="${at.root}${D.nc_link}" target="_blank" rel="noopener" style="justify-self:start"><i class="bi bi-plus-circle"></i> Zgłoś niezgodność w rejestrze NC (nowa karta)</a>` : '';
        const fix = d.gaps > 0 ? `<a class="tw-link" href="${at.root}${d.check_link}" target="_blank" rel="noopener" style="justify-self:start"><i class="bi bi-ui-checks"></i> Kontrola wejść ${esc(d.day_label)}</a>` : '';
        return `<div style="display:grid;gap:6px;min-width:340px"><div class="tw-seg" style="justify-self:start">${seg}</div>
            <div style="display:flex;gap:6px"><input class="form-control form-control-sm" data-lq-note="${esc(d.key)}" maxlength="500" value="${esc(r.note)}" placeholder="${esc(ph)}" style="min-height:36px;border-radius:9px">
            <button type="button" class="tw-btn ghost sm" data-lq-save="${esc(d.key)}" ${!r.result || !dirty || busy || (need && r.note.trim().length < 3) ? 'disabled' : ''}>Zapisz</button></div>${nc}${fix}${saved}</div>`;
    }

    function step2() {
        const list = review(), t = D.totals;
        if (!list.length) return tw.alert('Nic do wyjaśnienia : każdy zatwierdzony dzień grupy ma partie surowców i kontrolę jakości, bez notatki o brakach.', 'ok', 'bi-check-circle');
        const rows = list.map(d => ({cls: d.waiting ? 'hl' : '', cells: [
            `${group(d)}<small>${esc(d.day_label)} · zatwierdzony ${esc(d.closed_at)}${d.closed_by ? ' · ' + esc(d.closed_by) : ''}</small>${d.note ? `<div style="margin-top:4px;font-style:italic">« ${esc(d.note)} »</div>` : ''}`,
            `${d.gaps ? `<div style="margin-bottom:4px">${counts(d)}</div>` : ''}${gapList(d)}`, resultCell(d)]}));
        return `<p class="tw-lead">Dla każdego dnia grupy zapisz wynik : <b>Uzupełnione</b> (braki uzupełnione na tablecie — sprawdzane), <b>Niezgodność (NC)</b> z notatką
                albo <b>Zaakceptowane</b> z notatką. Krok 3 otworzy się, gdy wszystkie mają wynik.</p>
            ${tw.tiles([{label: 'Do wyjaśnienia', value: String(t.review), sub: `${t.gaps} ${tw.plural(t.gaps, 'brak', 'braki', 'braków')} · ${t.notes} z notatką`}, {label: 'Z wynikiem', value: `${t.resolved} z ${t.review}`, sub: t.waiting ? `zostało ${t.waiting}` : 'wszystkie wyjaśnione', cls: t.waiting ? 'warn' : 'ok'},
                {label: 'Uzupełnione', value: String(t.completed), sub: 'sprawdzone : brak braków'}, {label: 'NC / zaakceptowane', value: `${t.nc} / ${t.accepted}`, sub: 'z notatką'}])}
            ${tw.table([{label: 'Dzień grupy'}, {label: 'Braki w partiach'}, {label: 'Wynik'}], rows)}
            <p class="tw-note">« Uzupełnione » zapisuje się tylko wtedy, gdy braków już nie ma (numery partii i kontrola jakości są dalej możliwe na tablecie po zatwierdzeniu dnia). « Niezgodność (NC) » nie tworzy karty NC : link otwiera rejestr niezgodności.</p>`;
    }

    function step3() {
        const t = D.totals;
        if (D.done) return tw.done('Partie i kontrola dnia sprawdzone · ' + (D.done.at || ''), D.done.summary + ' · zadanie zaliczone');
        const decided = review().filter(d => d.check);
        return `<p class="tw-lead">Gotowe : <b>${esc(D.summary)}</b>.</p>
            ${tw.tiles([{label: 'Dni grup', value: String(t.days), sub: `${t.clean} bez braków`, cls: 'ok'}, {label: 'Uzupełnione', value: String(t.completed), sub: 'braki uzupełnione'},
                {label: 'Niezgodność (NC)', value: String(t.nc), sub: 'notatki poniżej', cls: t.nc ? 'warn' : ''}, {label: 'Zaakceptowane', value: String(t.accepted), sub: 'notatki poniżej'}])}
            ${decided.length ? `<div class="tw-cap">Wyniki</div>` + tw.table([{label: 'Dzień'}, {label: 'Grupa'}, {label: 'Braki'}, {label: 'Wynik'}, {label: 'Notatka'}],
                decided.map(d => ({cells: [esc(d.day_label), group(d), String(d.check.gaps), tw.pill(d.check.label, RES_CLS[d.check.result] || ''), esc(d.check.note || '—')]}))) : ''}
            <p class="tw-note">« Zakończ » zalicza zadanie « Partie i kontrola dnia » na dziś. Nic nie jest zmieniane w produkcji ani wysyłane.</p>`;
    }

    function render() {
        const top = canFinish() ? reached : Math.min(reached, 2);
        if (step > top) step = top;
        const body = step === 1 ? step1() : (step === 2 ? step2() : step3());
        const next = step === 1 ? {label: 'Dalej : do wyjaśnienia', attr: 'data-lq-next'}
            : step === 2 ? {label: 'Dalej : zatwierdź', attr: 'data-lq-next', disabled: !canFinish()}
                : (D.done ? null : {label: 'Zakończ', attr: 'data-lq-finish', final: true, disabled: busy || !canFinish()});
        app.innerHTML = tw.frame({title: 'Partie i kontrola dnia', sub: `${D.label} · krok ${step} z 3 · ok. 10 min`, steps: STEPS, step, reached: top, body,
            prev: step > 1 ? 'Wstecz' : null, next, later: !D.done && step < 3});
    }

    tw.bind(app, (n) => { step = n === -1 ? Math.max(1, step - 1) : n; render(); });
    app.addEventListener('input', (e) => {
        const n = e.target.closest('[data-lq-note]');
        if (!n) return;
        const key = n.dataset.lqNote;
        (draft[key] ??= {result: '', note: ''}).note = n.value;
        const d = D.days.find(x => x.key === key), r = draft[key];
        const btn = app.querySelector(`[data-lq-save="${CSS.escape(key)}"]`);
        const need = r.result === 'nc' || r.result === 'accepted';
        if (btn && d) btn.disabled = !r.result || busy || (need && r.note.trim().length < 3) || (d.check && !d.waiting && d.check.result === r.result && (d.check.note || '') === r.note);
    });
    app.addEventListener('click', async (e) => {
        if (e.target.closest('[data-lq-next]')) { step = Math.min(3, step + 1); reached = Math.max(reached, step); render(); return; }
        const r = e.target.closest('[data-lq-res]');
        if (r) { const key = r.dataset.lqRes; (draft[key] ??= {result: '', note: ''}).result = r.dataset.v; render(); app.querySelector(`[data-lq-note="${CSS.escape(key)}"]`)?.focus(); return; }
        const s = e.target.closest('[data-lq-save]');
        if (s) {
            const key = s.dataset.lqSave, d = draft[key], day = D.days.find(x => x.key === key);
            if (!d || !d.result || !day || busy) return;
            busy = true; s.disabled = true;
            const res = await tw.post('/ajax/workshop/traceability/day-check/result', {work_date: day.work_date, id_group: day.group.id, result: d.result, note: d.note});
            busy = false;
            if (res.ok) { D = res.data; delete draft[key]; if (canFinish()) reached = Math.max(reached, 2); }
            render();
            return;
        }
        if (e.target.closest('[data-lq-finish]')) {
            if (busy) return;
            busy = true; render();
            const res = await tw.post('/ajax/workshop/traceability/day-check/finish', {});
            busy = false;
            if (res.ok) { at.notify(true, res.message); D = res.data; }
            render();
        }
    });
    render();
})();
