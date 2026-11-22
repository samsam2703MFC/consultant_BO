// [375] Terminowość tygodnia (OTIF) (Kierownik operacyjny, co tydzień) : 1. wynik tygodnia (na czas, w całości, OTIF,
// trend 4 tygodni, dni, klienci, przyczyny), 2. przyczyny (zamówienia nie-OTIF, decyzje innych asystentów, działanie dla
// każdej głównej przyczyny), 3. podsumowanie → zadanie tygodnia zalicza się samo. Bez kwot ; nic nie jest wysyłane.
(() => {
    const app = document.getElementById('otApp');
    if (!app || !window.tw) return;
    const {esc, nf} = tw;
    let D = JSON.parse(document.getElementById('otBoot').textContent);
    let step = 1, reached = 1, busy = false;
    // Brouillons d'action par cause, gardés entre deux rendus.
    const draft = {};
    const STEPS = ['Wynik tygodnia', 'Przyczyny', 'Podsumowanie'];
    const DEC_CLS = {accepted: 'ok', corrective: 'bad', watch: 'warn'};
    const needsNote = (d) => (D.note_required || []).includes(d);
    const pct = (v) => v === null || v === undefined ? '<span style="color:#8a8580">brak danych</span>' : tw.pct(v, v % 1 ? 1 : 0);
    const cls = (v) => v === null || v === undefined ? '' : (v >= D.settings.target_pct ? 'ok' : 'bad');
    const yes = (v) => v === null || v === undefined ? '<span style="color:#8a8580">brak danych</span>' : (v ? '<i class="bi bi-check-lg tw-green"></i>' : '<i class="bi bi-x-lg tw-red"></i>');
    const ref = (o) => `<b>#${o.id}</b>${o.reference ? ` <small>${esc(o.reference)}</small>` : ''}${o.supplement ? ' ' + tw.pill('domówienie') : ''}`;
    const ratio = (n, d) => d ? `<small>${n} z ${d}</small>` : '';

    function trendBars() {
        return `<div class="tw-hbars">${D.trend.map(w => `<div class="tw-hbar"><span>${w.current ? '<b>' : ''}${esc(w.label)}${w.current ? '</b>' : ''}</span>
            <span class="tr"><i style="width:${w.otif_pct ?? 0}%;${w.current ? '' : 'opacity:.45'}"></i><i style="left:${D.settings.target_pct}%;width:2px;background:#1f1b18;border-radius:0"></i></span>
            <span class="num">${pct(w.otif_pct)}</span></div>`).join('')}</div>
            <p class="tw-note" style="margin-top:6px">Pionowa kreska = cel ${nf(D.settings.target_pct)} %. Słupek = OTIF tygodnia (zamówienia z terminem w tygodniu).</p>`;
    }

    function trendTable() {
        return tw.table([{label: 'Tydzień'}, {label: 'Zamówienia', num: true}, {label: 'Na czas', num: true}, {label: 'W całości', num: true}, {label: 'OTIF', num: true}, {label: ''}],
            D.trend.map(w => ({cls: w.current ? 'hl' : '', cells: [`${w.current ? '<b>' : ''}${esc(w.label)}${w.current ? '</b> <small>oceniany</small>' : ''}`, String(w.orders),
                pct(w.on_time_pct) + ratio(w.on_time, w.measured_ot), pct(w.in_full_pct) + ratio(w.in_full, w.measured_full), `<b>${pct(w.otif_pct)}</b>` + ratio(w.otif, w.measured_otif),
                w.saved_pct !== null ? tw.pill('zatwierdzony ' + tw.pct(w.saved_pct, w.saved_pct % 1 ? 1 : 0)) : (w.otif_pct !== null ? tw.gbar(w.otif_pct, cls(w.otif_pct) === 'bad' ? 'bad' : '') : '')]})));
    }

    function alerts() {
        const t = D.totals, out = [];
        if (t.orders && t.no_data) out.push(tw.alert(`<b>${t.no_data}</b> ${tw.plural(t.no_data, 'zamówienie', 'zamówienia', 'zamówień')} bez pełnych danych (brak WDT z pozycjami albo daty wysyłki) — <b>brak danych</b>, poza wyliczeniem OTIF. Lista poniżej.`, 'info', 'bi-question-circle'));
        if (t.cancelled) out.push(tw.alert(`${t.cancelled} ${tw.plural(t.cancelled, 'zamówienie zarchiwizowane', 'zamówienia zarchiwizowane', 'zamówień zarchiwizowanych')} bez wysyłki (« Nie ») — poza pomiarem.`, '', 'bi-archive'));
        return out.join('');
    }

    function step1() {
        const t = D.totals;
        if (!t.orders) return `<p class="tw-lead">Tydzień <b>${esc(D.week_label)}</b> : brak zamówień klientów z terminem wysyłki w tym tygodniu.</p>${alerts()}<div class="tw-cap">Trend</div>${trendTable()}`;
        const delta = t.delta_pts === null ? 'brak wcześniejszych tygodni' : `wobec średniej 4 poprzednich tygodni (${pct(t.prev_avg_pct)})`;
        const days = D.days.map(d => ({cls: d.non_otif ? 'hl' : '', cells: [`<b>${esc(d.label)}</b>${d.workday ? '' : ' <small>dzień wolny</small>'}`, String(d.orders),
            d.orders ? pct(d.on_time_pct) : '—', d.orders ? pct(d.in_full_pct) : '—', d.orders ? `<b>${pct(d.otif_pct)}</b>` : '—', d.non_otif ? String(d.non_otif) : '—']}));
        const custs = D.customers.slice(0, 15).map(c => ({cls: c.non_otif ? 'hl' : '', cells: [`<b>${esc(c.name)}</b>`, String(c.orders), pct(c.on_time_pct), pct(c.in_full_pct),
            `<b>${pct(c.otif_pct)}</b>`, c.non_otif ? String(c.non_otif) : '—']}));
        const causes = D.causes.map(c => ({cells: [`<b>${esc(c.label)}</b>`, String(c.orders), String(c.primary), String(c.customers)]}));
        const noData = D.no_data.map(o => ({cells: [ref(o), esc(o.customer), esc(o.shipping_label), esc(o.no_data.join(' ; ') || 'brak danych'), o.ship_label ? `${esc(o.ship_label)}<small>${esc(o.ship_source_label)}</small>` : '—']}));
        return `<p class="tw-lead">Zamówienia klientów z terminem wysyłki w tygodniu <b>${esc(D.week_label)}</b> (bez gratisów) : ile wyjechało <b>na czas</b>, ile <b>w całości</b>, i oba naraz — <b>OTIF</b>.</p>
            ${t.below_target ? tw.alert(`OTIF <b>${pct(t.otif_pct)}</b> poniżej celu <b>${nf(D.settings.target_pct)} %</b> — w kroku 2 przyczyny i działania.`, '', 'bi-graph-down-arrow') : (t.otif_pct !== null ? tw.alert(`OTIF <b>${pct(t.otif_pct)}</b> — cel ${nf(D.settings.target_pct)} % osiągnięty.`, 'ok', 'bi-check-circle') : '')}
            ${alerts()}
            ${tw.tiles([{label: 'OTIF', html: pct(t.otif_pct), sub: `${t.otif} z ${t.measured_otif} · cel ${nf(D.settings.target_pct)} %`, cls: cls(t.otif_pct)},
                {label: 'Na czas', html: pct(t.on_time_pct), sub: `${t.on_time} z ${t.measured_ot} zamówień`, cls: cls(t.on_time_pct)},
                {label: 'W całości', html: pct(t.in_full_pct), sub: `${t.in_full} z ${t.measured_full} z danymi o ilościach`, cls: cls(t.in_full_pct)},
                {label: 'Trend', html: t.delta_pts === null ? '—' : tw.signed(t.delta_pts, t.delta_pts % 1 ? 1 : 0, ' pkt'), subHtml: delta, cls: t.delta_pts === null ? '' : (t.delta_pts < 0 ? 'warn' : 'ok')}])}
            <div class="tw-grid2"><div><div class="tw-cap">OTIF tydzień po tygodniu</div>${trendBars()}</div>
                <div><div class="tw-cap">Przyczyny (zamówienia nie-OTIF : ${t.non_otif})</div>${tw.table([{label: 'Przyczyna'}, {label: 'Zamówienia', num: true}, {label: 'Główna', num: true}, {label: 'Klienci', num: true}], causes, 'Brak przyczyn : wszystkie zmierzone zamówienia OTIF.')}</div></div>
            <div class="tw-cap">Trend : 4 poprzednie tygodnie</div>${trendTable()}
            <div class="tw-cap">Dni tygodnia (dni robocze : ${esc(D.settings.workdays_label)})</div>
            ${tw.table([{label: 'Dzień'}, {label: 'Zamówienia', num: true}, {label: 'Na czas', num: true}, {label: 'W całości', num: true}, {label: 'OTIF', num: true}, {label: 'Nie-OTIF', num: true}], days)}
            <div class="tw-cap">Klienci${D.customers.length > 15 ? ` (15 z ${D.customers.length}, najsłabsi najpierw)` : ' (najsłabsi najpierw)'}</div>
            ${tw.table([{label: 'Klient'}, {label: 'Zamówienia', num: true}, {label: 'Na czas', num: true}, {label: 'W całości', num: true}, {label: 'OTIF', num: true}, {label: 'Nie-OTIF', num: true}], custs)}
            ${noData.length ? `<div class="tw-cap">Brak danych (poza OTIF)</div>` + tw.table([{label: 'Zamówienie'}, {label: 'Klient'}, {label: 'Termin'}, {label: 'Czego brakuje'}, {label: 'Wysłane'}], noData) : ''}
            <p class="tw-note"><b>Na czas</b> : wysłane najpóźniej w dniu terminu wysyłki zamówienia — dzień wysyłki z zapisu dokumentów wysyłki (Wyślij dzień) albo ze zmiany statusu na « W transporcie », bez nich z daty WDT ;
                i niedostarczone z opóźnieniem (dostawa potwierdzona na tablecie kierowcy więcej niż ${D.settings.delivery_days} ${tw.plural(D.settings.delivery_days, 'dzień', 'dni', 'dni')} po terminie, spóźnienie, odmowa, klient zamknięty).
                Termin przesunięty poza tydzień = nie na czas w tygodniu pierwotnego terminu.
                <b>W całości</b> : każdy produkt zamówienia na WDT (kartony z palet klienta i dnia) w ilości co najmniej zamówionej przez wszystkie zamówienia tej WDT ; brak lub uszkodzenie zgłoszone przy dostawie = niekompletne ;
                bez WDT z pozycjami — brak danych. <b>OTIF</b> = na czas i w całości. Ustawienia › Parametry : otif.target_pct, otif.delivery_days.</p>`;
    }

    function priorCell(o) {
        const p = (o.prior || []).map(x => `<div><small>${esc(x.source_label)} ${esc(x.day_label)} :</small> ${tw.pill(x.label)}${x.new_label ? ` <small>→ ${esc(x.new_label)}</small>` : ''}${x.note ? `<small>${esc(x.note)}</small>` : ''}</div>`);
        const c = (o.complaints || []).map(x => `<div><small>Reklamacja${x.at ? ' ' + esc(x.at) : ''} :</small> ${esc(x.reason)}${x.product ? ` <small>${esc(x.product)}</small>` : ''}</div>`);
        return p.concat(c).join('') || '<small style="color:#8a8580">—</small>';
    }

    function actionCell(g) {
        const a = g.action;
        const d = draft[g.cause] ?? (draft[g.cause] = {decision: a ? a.decision : '', note: a ? (a.note || '') : '', id_responsible: a && a.id_responsible ? String(a.id_responsible) : '',
            id_position: a && a.id_position ? String(a.id_position) : ''});
        const seg = Object.entries(D.decisions).map(([k, l]) => `<button type="button" class="${d.decision === k ? 'on' : ''}" data-ot-dec="${esc(g.cause)}" data-v="${k}">${esc(l)}</button>`).join('');
        const dirty = !a || a.decision !== d.decision || (a.note || '') !== d.note || String(a.id_responsible || '') !== d.id_responsible || String(a.id_position || '') !== d.id_position;
        const ph = d.decision === 'corrective' ? 'Co zmieniamy (wymagane) : kto, co, do kiedy' : 'Notatka (opcjonalnie)';
        const who = d.decision === 'corrective' ? `<div style="display:flex;gap:6px">
                <select class="form-select form-select-sm" data-ot-sel="id_responsible" data-c="${esc(g.cause)}"><option value="">Osoba odpowiedzialna (opcjonalnie)</option>${D.users.map(u => `<option value="${u.id}" ${String(u.id) === d.id_responsible ? 'selected' : ''}>${esc(u.name)}</option>`).join('')}</select>
                <select class="form-select form-select-sm" data-ot-sel="id_position" data-c="${esc(g.cause)}"><option value="">Stanowisko (opcjonalnie)</option>${D.positions.map(p => `<option value="${p.id}" ${String(p.id) === d.id_position ? 'selected' : ''}>${esc(p.name)}</option>`).join('')}</select></div>` : '';
        const saved = a ? `<small>${tw.pill(a.label, DEC_CLS[a.decision] ?? '')} ${esc(a.at || '')}${a.by ? ' · ' + esc(a.by) : ''}${a.responsible ? ' · odp. ' + esc(a.responsible) : ''}${a.position ? ' · ' + esc(a.position) : ''}</small>` : '';
        return `<div style="display:grid;gap:6px;min-width:420px"><div class="tw-seg" style="justify-self:start">${seg}</div>${who}
            <div style="display:flex;gap:6px"><input class="form-control form-control-sm" data-ot-note="${esc(g.cause)}" maxlength="500" value="${esc(d.note)}" placeholder="${ph}" style="min-height:36px;border-radius:9px">
            <button type="button" class="tw-btn ghost sm" data-ot-save="${esc(g.cause)}" ${!d.decision || !dirty || busy ? 'disabled' : ''}>Zapisz</button></div>${saved}</div>`;
    }

    function step2() {
        const t = D.totals;
        if (!D.groups.length) return `<p class="tw-lead">Brak zamówień nie-OTIF w tygodniu <b>${esc(D.week_label)}</b>${t.no_data ? ` (${t.no_data} bez danych — krok 1)` : ''}. Nic do wyjaśnienia : przejdź do podsumowania.</p>`;
        const rows = D.late.map(o => ({cells: [ref(o), `<b>${esc(o.customer)}</b>`, `${esc(o.shipping_label)}${o.moved ? `<small>→ ${esc(o.moved.after_label)}</small>` : ''}`,
            o.causes.map((c, i) => `<div style="display:flex;gap:6px;align-items:baseline;margin:2px 0"><i class="bi ${i ? 'bi-dot' : 'bi-exclamation-circle tw-red'}"></i><span>${i ? '' : '<b>'}${esc(D.cause_labels[c.code])}${i ? '' : '</b>'} <small>${esc(c.reason)}</small></span></div>`).join('')
                + (o.date_changes > 1 ? `<small>data wysyłki zmieniana ${o.date_changes} razy</small>` : ''),
            priorCell(o)]}));
        const groups = D.groups.map(g => ({cls: g.action ? '' : 'hl', cells: [`<b>${esc(g.label)}</b><small>${g.orders} ${tw.plural(g.orders, 'zamówienie', 'zamówienia', 'zamówień')} · ${esc(g.customers.slice(0, 4).join(', '))}${g.customers.length > 4 ? '…' : ''}</small>`, actionCell(g)]}));
        return `<p class="tw-lead">Zamówienia, które nie były OTIF, z przyczyną odczytaną z ERP i decyzjami zapisanymi już w innych asystentach. Dla każdej <b>głównej przyczyny</b> : <b>Akceptowane (jednorazowe)</b>, <b>Działanie korygujące</b> (z notatką) albo <b>Do obserwacji</b>.</p>
            ${tw.tiles([{label: 'Nie-OTIF', value: String(t.non_otif), sub: `z ${t.measured_otif} zmierzonych`, cls: t.non_otif ? 'warn' : 'ok'},
                {label: 'Główne przyczyny', value: String(t.groups), sub: 'po jednym działaniu'}, {label: 'Z działaniem', value: `${t.decided} z ${t.groups}`, sub: t.waiting ? `zostało ${t.waiting}` : 'wszystkie', cls: t.waiting ? 'warn' : 'ok'},
                {label: 'OTIF', html: pct(t.otif_pct), sub: `cel ${nf(D.settings.target_pct)} %`, cls: cls(t.otif_pct)}])}
            <div class="tw-cap">Działanie dla głównej przyczyny</div>${tw.table([{label: 'Przyczyna'}, {label: 'Działanie'}], groups)}
            <div class="tw-cap">Zamówienia nie-OTIF (${D.late.length})</div>
            ${tw.table([{label: 'Zamówienie'}, {label: 'Klient'}, {label: 'Termin'}, {label: 'Przyczyna'}, {label: 'Wcześniejsze decyzje i reklamacje'}], rows)}
            <p class="tw-note">Decyzje z asystentów « Zamówienia dnia », « Domówienia dnia » i « Opóźnienia dostaw » są tylko pokazane. Działanie zapisuje ustalenie tygodnia : nic nie jest wysyłane, zamówienia się nie zmieniają.</p>`;
    }

    function step3() {
        const t = D.totals;
        if (D.done) return tw.done('Terminowość tygodnia sprawdzona · ' + (D.done.at || ''), D.done.summary + ' · zadanie zaliczone')
            + `<div class="tw-cap">Trend</div>${trendTable()}`;
        const list = D.groups.filter(g => g.action);
        return `<p class="tw-lead">Gotowe : <b>${esc(D.summary)}</b>.</p>
            ${tw.tiles([{label: 'OTIF', html: pct(t.otif_pct), sub: `cel ${nf(D.settings.target_pct)} %`, cls: cls(t.otif_pct)}, {label: 'Na czas', html: pct(t.on_time_pct), sub: `${t.on_time} z ${t.measured_ot}`},
                {label: 'W całości', html: pct(t.in_full_pct), sub: `${t.in_full} z ${t.measured_full}`},
                {label: 'Działania', value: String(t.decided), sub: `${t.corrective} korygujące · ${t.watch} do obserwacji · ${t.accepted} akceptowane`}])}
            <div class="tw-cap">Trend</div>${trendTable()}
            ${list.length ? `<div class="tw-cap">Działania</div>` + tw.table([{label: 'Przyczyna'}, {label: 'Zamówienia', num: true}, {label: 'Działanie'}, {label: 'Notatka'}, {label: 'Odpowiedzialny'}],
                list.map(g => ({cells: [`<b>${esc(g.label)}</b>`, String(g.orders), tw.pill(g.action.label, DEC_CLS[g.action.decision] ?? ''), esc(g.action.note || '—'),
                    esc([g.action.responsible, g.action.position].filter(Boolean).join(' · ') || '—')]}))) : ''}
            <p class="tw-note">« Zakończ » zapisuje wynik tygodnia ${esc(D.week_label)} i zalicza zadanie « Terminowość tygodnia (OTIF) » na ten tydzień. Nic nie jest wysyłane.</p>`;
    }

    function render() {
        const top = D.can_finish ? reached : Math.min(reached, 2);
        if (step > top) step = top;
        const body = step === 1 ? step1() : (step === 2 ? step2() : step3());
        const next = step === 1 ? {label: 'Dalej : przyczyny', attr: 'data-ot-next'}
            : step === 2 ? {label: 'Dalej : podsumowanie', attr: 'data-ot-next', disabled: !D.can_finish}
                : (D.done ? null : {label: 'Zakończ', attr: 'data-ot-finish', final: true, disabled: busy || !D.can_finish});
        app.innerHTML = tw.frame({title: 'Terminowość tygodnia (OTIF)', sub: `tydzień ${D.week_label} · krok ${step} z 3 · ok. 15 min`, steps: STEPS, step, reached: top, body,
            prev: step > 1 ? 'Wstecz' : null, next, later: !D.done && step < 3});
    }

    const saveBtn = (cause) => [...app.querySelectorAll('[data-ot-save]')].find(x => x.dataset.otSave === cause);
    const refresh = (cause) => {
        const g = D.groups.find(x => x.cause === cause), d = draft[cause], a = g && g.action, btn = saveBtn(cause);
        if (btn && g) btn.disabled = !d.decision || busy || (a && a.decision === d.decision && (a.note || '') === d.note && String(a.id_responsible || '') === d.id_responsible && String(a.id_position || '') === d.id_position);
    };
    tw.bind(app, (n) => { step = n === -1 ? Math.max(1, step - 1) : n; render(); });
    app.addEventListener('input', (e) => {
        const n = e.target.closest('[data-ot-note]');
        if (!n) return;
        draft[n.dataset.otNote].note = n.value;
        refresh(n.dataset.otNote);
    });
    app.addEventListener('change', (e) => {
        const s = e.target.closest('[data-ot-sel]');
        if (!s) return;
        draft[s.dataset.c][s.dataset.otSel] = s.value;
        refresh(s.dataset.c);
    });
    app.addEventListener('click', async (e) => {
        if (e.target.closest('[data-ot-next]')) { step = Math.min(3, step + 1); reached = Math.max(reached, step); render(); return; }
        const r = e.target.closest('[data-ot-dec]');
        if (r) { draft[r.dataset.otDec].decision = r.dataset.v; render(); return; }
        const s = e.target.closest('[data-ot-save]');
        if (s) {
            const cause = s.dataset.otSave, d = draft[cause];
            if (!d || !d.decision || busy) return;
            if (needsNote(d.decision) && d.note.trim().length < 3) { at.notify(false, 'Działanie korygujące : wpisz, co zostanie zmienione (kto, co, do kiedy).'); return; }
            busy = true; s.disabled = true;
            const body = {cause, decision: d.decision, note: d.note};
            if (d.decision === 'corrective') { body.id_responsible = d.id_responsible ? Number(d.id_responsible) : null; body.id_position = d.id_position ? Number(d.id_position) : null; }
            const res = await tw.post('/ajax/client-orders/otif/action', body);
            busy = false;
            if (res.ok) { D = res.data; delete draft[cause]; }
            render();
            return;
        }
        if (e.target.closest('[data-ot-finish]')) {
            if (busy) return;
            busy = true; render();
            const res = await tw.post('/ajax/client-orders/otif/finish', {});
            busy = false;
            if (res.ok) { at.notify(true, res.message); D = res.data; }
            render();
        }
    });
    render();
})();
