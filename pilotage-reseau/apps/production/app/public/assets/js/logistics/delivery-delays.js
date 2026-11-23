// [374] Opóźnienia dostaw (Kierownik operacyjny) : 1. przegląd dostaw (dziś, do następnego dnia roboczego, po terminie,
// w transporcie bez potwierdzenia), pojazdy dnia, 2. do wyjaśnienia (decyzja dla każdej dostawy z sygnałem, SMS do
// klienta jednym kliknięciem), 3. zatwierdzenie → zadanie stanowiska zalicza się samo. « Nowy termin » jest tylko
// zapisany : zamówienie zachowuje swoją datę. Aucun SMS ne part sans clic (fenêtre commune order-sms.js).
(() => {
    const app = document.getElementById('ddApp');
    if (!app || !window.tw) return;
    const {esc, nf} = tw;
    let D = JSON.parse(document.getElementById('ddBoot').textContent);
    let step = 1, reached = 1, busy = false;
    // Brouillons de l'étape 2 (décision, date, note) par commande, gardés entre deux rendus.
    const draft = {};
    const STEPS = ['Przegląd', 'Do wyjaśnienia', 'Zatwierdź'];
    const DEC_CLS = {ok: 'ok', new_date: 'warn', contact: ''};
    const SECTION_CLS = {overdue: 'bad', transit: 'warn', today: '', next: ''};
    const flagged = () => D.orders.filter(o => o.flags.length);
    const canFinish = () => !!D.can_finish;
    const needsNote = (r) => (D.note_required || []).includes(r);
    // « 27.11 » : jour et mois sur deux chiffres (texte du SMS).
    const ddmm = (d) => d ? `${d.slice(8, 10)}.${d.slice(5, 7)}` : '';
    const link = (id) => `<a href="${at.root}/client-orders/${id}/edit" target="_blank" rel="noopener">#${id}</a>`;
    const ref = (o) => `${link(o.id)}${o.reference ? ' · ' + esc(o.reference) : ''}${o.supplement ? ' ' + tw.pill('domówienie') : ''}<small>${nf(o.cartons, o.cartons % 1 ? 1 : 0)} kart.</small>`;
    const ship = (o) => esc(o.shipping_label) + (o.section === 'today' ? '<small>dziś</small>' : (o.late_days ? `<small class="tw-red">${o.late_days} ${tw.plural(o.late_days, 'dzień', 'dni', 'dni')} temu</small>` : (o.shipping_date === D.next_workday ? '<small>następny dzień roboczy</small>' : '')));
    const smsBtn = (o) => D.can_sms && window.orderSms ? `<span style="display:inline-flex;gap:6px;align-items:center">${orderSms.badge(o.sms)}${orderSms.button(`data-dd-sms="${o.id}"`, false, 'SMS do klienta : zmiana terminu wysyłki')}</span>` : '';

    function prep(o) {
        if (!o.open) return o.wdt ? `WDT ${esc(o.wdt.num || '#' + o.wdt.id)}${o.shipped_label ? `<small>wysłane ${esc(o.shipped_label)}</small>` : ''}` : '—';
        const p = o.prep, parts = [];
        if (p.ready) parts.push(tw.pill(`${p.ready} ${tw.plural(p.ready, 'paleta gotowa', 'palety gotowe', 'palet gotowych')}`, 'ok'));
        if (p.building) parts.push(tw.pill(`${p.building} w budowie`));
        if (!parts.length) parts.push(o.section === 'next' ? '<span>—</span>' : tw.pill('brak palet', o.section === 'today' || o.section === 'overdue' ? 'warn' : ''));
        return parts.join(' ') + `<small>${p.picking_label ? 'lista kompletacyjna ' + esc(p.picking_label) : 'bez listy kompletacyjnej'}</small>`;
    }

    function transport(o) {
        if (o.section === 'transit' || (!o.open && o.section !== 'today' && o.section !== 'next')) {
            return o.in_transit ? `W transporcie od ${esc(o.transit_label || '—')}<small>${o.stop ? esc(o.stop.status === 'problem' ? 'problem ' + o.stop.at : 'kierowca : ' + o.stop.at) : 'bez potwierdzenia kierowcy'}</small>` : '—';
        }
        const t = o.transport;
        if (!o.open) return o.delivered ? tw.pill('dostarczone', 'ok') : (o.in_transit ? `w drodze<small>od ${esc(o.transit_label || '—')}</small>` : '—');
        if (!t) return o.section === 'overdue' ? '<small>—</small>' : '<small>poza planem dnia</small>';
        if (!t.vehicle) return `<span class="tw-red">brak pojazdu</span><small>${t.tour ? 'trasa ' + esc(t.tour) + ' · ' : ''}wyjazd ${esc(t.departure)}</small>`;
        const v = t.vehicle;
        return `<b>${esc(v.label)}</b>${v.over ? ' ' + tw.pill('przeładowany', 'bad') : ''}<small>${v.driver ? esc(v.driver) : '<span class="tw-red">bez kierowcy</span>'} · wyjazd ${esc(t.departure)}${t.tour ? ' · ' + esc(t.tour) : ''}${v.run && v.run.started ? ' · wyjechał ' + esc(v.run.started) : ''}</small>`;
    }

    function mark(o) {
        if (o.flags.length) return o.check ? tw.pill('wyjaśnione', 'ok') : tw.pill('do wyjaśnienia' + (o.flags.length > 1 ? ' (' + o.flags.length + ')' : ''), 'bad');
        return tw.pill(o.status_label, o.delivered ? 'ok' : '');
    }

    function fleet() {
        if (!D.fleet.length) return '';
        const rows = D.fleet.map(v => ({cls: v.over || !v.driver ? 'hl' : '', cells: [
            `<b>${esc(v.label)}</b><small>${esc(v.date_label)}${v.carrier ? ' · ' + esc(v.carrier) : ''}</small>`,
            v.driver ? esc(v.driver) : '<span class="tw-red">brak kierowcy</span>',
            `${esc(v.departure)}<small>załadunek od ${esc(v.loading_from)}</small>`,
            `${v.pallets}${v.places ? ' / ' + v.places : ''} ${tw.gbar(v.fill_pct, v.over ? 'bad' : '')}${v.over ? '<small class="tw-red">ponad pojemność</small>' : ''}`,
            `${v.customers.length}<small>${esc(v.customers.slice(0, 4).join(', '))}${v.customers.length > 4 ? '…' : ''}</small>`,
            v.run ? (v.run.finished ? tw.pill('wrócił ' + v.run.finished, 'ok') : (v.run.started ? tw.pill('w trasie od ' + v.run.started) : '—')) : '—']}));
        return `<div class="tw-cap">Pojazdy · ${esc(D.label)} i ${esc(D.next_label)}</div>` + tw.table([{label: 'Pojazd'}, {label: 'Kierowca'}, {label: 'Wyjazd'}, {label: 'Palety / miejsca'}, {label: 'Klienci'}, {label: 'Tablet kierowcy'}], rows);
    }

    function step1() {
        const t = D.totals;
        const order = Object.keys(D.sections);
        const blocks = order.map(sec => {
            const list = D.orders.filter(o => o.section === sec);
            if (!list.length) return '';
            const rows = list.map(o => ({cls: o.flags.length && !o.check ? 'hl' : '', cells: [
                `<b>${esc(o.customer)}</b><small>${esc(o.town || '')}</small>`, ref(o), ship(o), prep(o), transport(o), mark(o)]}));
            const title = sec === 'today' ? `${D.sections[sec]} · ${D.label}` : (sec === 'next' ? `Wysyłka do ${D.next_label}` : D.sections[sec]);
            return `<div class="tw-cap">${esc(title)} ${tw.pill(String(list.length), SECTION_CLS[sec] || '')}</div>`
                + tw.table([{label: 'Klient'}, {label: 'Zamówienie'}, {label: 'Wysyłka'}, {label: 'Przygotowanie / dokumenty'}, {label: 'Transport'}, {label: ''}], rows);
        }).join('');
        return `<p class="tw-lead">Wysyłki <b>dziś (${esc(D.label)})</b> i do <b>${esc(D.next_label)}</b> (najbliższy dzień roboczy), zamówienia, które powinny już wyjechać, i te w transporcie bez potwierdzenia dostawy (od ${esc(D.from_label)}).
                Dostawy z sygnałem trafiają do kroku 2.</p>
            ${tw.tiles([{label: 'Wysyłka dziś', value: String(t.today), sub: `${t.today_customers} ${tw.plural(t.today_customers, 'klient', 'klientów', 'klientów')} · ${t.vehicles} ${tw.plural(t.vehicles, 'pojazd', 'pojazdy', 'pojazdów')} · ${t.today_shipped} z WDT`},
                {label: `Do ${D.next_label}`, value: String(t.next), sub: `${t.vehicles_next} ${tw.plural(t.vehicles_next, 'pojazd', 'pojazdy', 'pojazdów')} w planie`},
                {label: 'Po terminie', value: String(t.overdue), sub: 'nie wyjechały (bez WDT)', cls: t.overdue ? 'warn' : 'ok'},
                {label: 'W transporcie', value: String(t.transit), sub: 'bez potwierdzenia dostawy', cls: t.transit ? 'warn' : ''},
                {label: 'Do wyjaśnienia', value: String(t.flagged), sub: t.flagged ? `${t.resolved} z ${t.flagged} z decyzją` : 'brak sygnałów', cls: t.waiting ? 'warn' : 'ok'}], 5)}
            ${blocks || '<p class="tw-empty">Brak wysyłek na dziś i najbliższy dzień roboczy, brak opóźnionych dostaw.</p>'}
            ${fleet()}
            <p class="tw-note">Przygotowanie : palety zeskanowane dla klienta na dzień wysyłki (gotowe = bez dokumentów albo wysłane) i lista kompletacyjna zamówienia. Transport : przydział dnia, trasa albo ostatni CMR — ta sama reguła co Dzień wysyłki ;
                kierowca z pojazdu, z karty pojazdu albo z trasy. Dostarczone = potwierdzenie na tablecie kierowcy albo status « Zrealizowane ». Dni robocze : ${esc(D.settings.workdays_label.replace(/\.$/, ''))}. Gratisy jadą z dostawą i nie są tu pokazane.</p>`;
    }

    function decisionCell(o) {
        const d = draft[o.id] ?? (draft[o.id] = {decision: o.check ? o.check.decision : '', new_date: o.check && o.check.new_date ? o.check.new_date : '', note: o.check ? (o.check.note || '') : ''});
        const seg = Object.entries(D.decisions).map(([k, l]) => `<button type="button" class="${d.decision === k ? 'on' : ''}" data-dd-dec="${o.id}" data-v="${k}">${esc(l)}</button>`).join('');
        const saved = o.check ? `<small>${tw.pill(o.check.label + (o.check.new_label ? ' : ' + o.check.new_label : ''), DEC_CLS[o.check.decision] ?? '')} ${esc(o.check.at || '')}${o.check.by ? ' · ' + esc(o.check.by) : ''}</small>` : '';
        const dirty = !o.check || o.check.decision !== d.decision || (o.check.note || '') !== d.note || (o.check.new_date || '') !== (d.decision === 'new_date' ? d.new_date : '');
        const ph = d.decision === 'contact' ? 'Notatka (wymagana) : z kim, co ustalono' : (d.decision === 'new_date' ? 'Notatka (wymagana) : dlaczego, kto ustalił' : 'Notatka (opcjonalnie) : co sprawdzono');
        const date = d.decision === 'new_date' ? `<input type="date" class="form-control form-control-sm" data-dd-date="${o.id}" value="${esc(d.new_date)}" min="${esc(D.today)}" max="${esc(D.new_date_max)}"
            style="min-height:36px;border-radius:9px;max-width:150px" title="Nowy termin wysyłki (tylko zapisany — zamówienie bez zmian)">` : '';
        const prev = o.previous ? `<small>Poprzednio (${esc(o.previous.day_label)}) : ${esc(o.previous.label)}${o.previous.new_label ? ' ' + esc(o.previous.new_label) : ''}${o.previous.note ? ' — ' + esc(o.previous.note) : ''}</small>` : '';
        return `<div style="display:grid;gap:6px;min-width:470px"><div class="tw-seg" style="justify-self:start">${seg}</div>
            <div style="display:flex;gap:6px">${date}<input class="form-control form-control-sm" data-dd-note="${o.id}" maxlength="500" value="${esc(d.note)}"
                placeholder="${ph}" style="min-height:36px;border-radius:9px">
            <button type="button" class="tw-btn ghost sm" data-dd-save="${o.id}" ${!d.decision || !dirty || busy ? 'disabled' : ''}>Zapisz</button></div>${saved}${prev}</div>`;
    }

    function step2() {
        const list = flagged(), t = D.totals;
        if (!list.length) return tw.alert('Nic do wyjaśnienia : wysyłki dnia są przygotowane i mają transport, nic nie jest po terminie ani za długo w drodze.', 'ok', 'bi-check-circle');
        const rows = list.map(o => ({cls: o.check ? '' : 'hl', cells: [
            `<b>${esc(o.customer)}</b><small>${link(o.id)}${o.reference ? ' · ' + esc(o.reference) : ''} · ${esc(D.sections[o.section])}</small><div style="margin-top:4px">${smsBtn(o)}</div>`,
            ship(o),
            o.flags.map(f => `<div style="display:flex;gap:6px;align-items:baseline;margin:2px 0"><i class="bi bi-exclamation-circle tw-red"></i><span>${esc(f.reason)}</span></div>`).join(''),
            decisionCell(o)]}));
        return `<p class="tw-lead">Sygnały automatyczne. Dla każdej dostawy zapisz decyzję : <b>Wyjaśnione — w drodze / OK</b>, <b>Nowy termin</b> (data i notatka) albo <b>Kontakt z klientem</b> (notatka).
                Klienta możesz powiadomić SMS-em (tekst do przejrzenia, nic nie wychodzi samo). Krok 3 otworzy się, gdy wszystkie mają decyzję.</p>
            ${tw.tiles([{label: 'Do wyjaśnienia', value: String(t.flagged), sub: 'dostawy z sygnałem'}, {label: 'Z decyzją', value: `${t.resolved} z ${t.flagged}`, sub: t.waiting ? `zostało ${t.waiting}` : 'wszystkie wyjaśnione', cls: t.waiting ? 'warn' : 'ok'},
                {label: 'W drodze / OK', value: String(t.ok), sub: 'wyjaśnione'}, {label: 'Nowy termin / kontakt', value: `${t.new_date} / ${t.contact}`, sub: 'z notatką'}])}
            ${tw.table([{label: 'Dostawa'}, {label: 'Wysyłka'}, {label: 'Dlaczego'}, {label: 'Decyzja'}], rows)}
            <p class="tw-note">Sygnały : data wysyłki minęła bez WDT ; dziś po wyjeździe (+${esc(D.settings.departure_grace_min)} min) bez WDT ; dziś nic gotowego (bez listy kompletacyjnej albo od początku załadunku) ; bez pojazdu, pojazd przeładowany albo bez kierowcy ;
                « W transporcie » od ${esc(D.settings.transit_max_days)} dni bez potwierdzenia ; problem zgłoszony na tablecie kierowcy ; data wysyłki zmieniana co najmniej ${esc(D.settings.date_changes_max)} razy (Ustawienia › Parametry, deliveries.*).
                « Nowy termin » jest tylko zapisany : datę zamówienia zmieniasz w zamówieniu.${D.can_sms && !D.sms_delivers ? ' Bramka SMS nie wysyła (Ustawienia › SMS) : SMS zostanie zapisany w historii klienta.' : ''}</p>`;
    }

    function step3() {
        const t = D.totals;
        if (D.done) return tw.done('Opóźnienia dostaw sprawdzone · ' + (D.done.at || ''), D.done.summary + ' · zadanie zaliczone');
        const list = flagged().filter(o => o.check);
        return `<p class="tw-lead">Gotowe : <b>${esc(D.summary)}</b>.</p>
            ${tw.tiles([{label: 'Dostawy', value: String(t.orders), sub: `${t.today} dziś · ${t.overdue} po terminie · ${t.transit} w transporcie`}, {label: 'Wyjaśnione', value: String(t.resolved), sub: 'wszystkie z decyzją', cls: 'ok'},
                {label: 'Nowy termin', value: String(t.new_date), sub: 'zapisane, zamówienia bez zmian'}, {label: 'Kontakt z klientem', value: String(t.contact), sub: D.label}])}
            ${list.length ? `<div class="tw-cap">Decyzje</div>` + tw.table([{label: 'Klient'}, {label: 'Zamówienie'}, {label: 'Wysyłka'}, {label: 'Decyzja'}, {label: 'Notatka'}, {label: 'SMS'}],
                list.map(o => ({cells: [`<b>${esc(o.customer)}</b>`, link(o.id), ship(o), tw.pill(o.check.label + (o.check.new_label ? ' : ' + o.check.new_label : ''), DEC_CLS[o.check.decision] ?? ''),
                    esc(o.check.note || '—'), window.orderSms && o.sms ? orderSms.badge(o.sms) : '—']}))) : ''}
            <p class="tw-note">« Zakończ » zalicza zadanie « Opóźnienia dostaw » na dziś. Zamówienia, trasy i pojazdy pozostają bez zmian ; SMS wychodzi tylko po kliknięciu « Wyślij SMS ».</p>`;
    }

    function render() {
        const top = canFinish() ? reached : Math.min(reached, 2);
        if (step > top) step = top;
        const body = step === 1 ? step1() : (step === 2 ? step2() : step3());
        const next = step === 1 ? {label: 'Dalej : do wyjaśnienia', attr: 'data-dd-next'}
            : step === 2 ? {label: 'Dalej : zatwierdź', attr: 'data-dd-next', disabled: !canFinish()}
                : (D.done ? null : {label: 'Zakończ', attr: 'data-dd-finish', final: true, disabled: busy || !canFinish()});
        app.innerHTML = tw.frame({title: 'Opóźnienia dostaw', sub: `${D.label} · ${D.now} · krok ${step} z 3 · ok. 10 min`, steps: STEPS, step, reached: top, body,
            prev: step > 1 ? 'Wstecz' : null, next, later: !D.done && step < 3});
    }

    async function reload() {
        const r = await at.send('/ajax/logistics/delays', 'GET');
        if (r.ok && r.data) { D = r.data; render(); }
    }

    const refreshSave = (id) => {
        const o = D.orders.find(x => x.id === id), d = draft[id], btn = app.querySelector(`[data-dd-save="${id}"]`);
        if (!btn || !o || !d) return;
        btn.disabled = !d.decision || busy || (o.check && o.check.decision === d.decision && (o.check.note || '') === d.note && (o.check.new_date || '') === (d.decision === 'new_date' ? d.new_date : ''));
    };
    tw.bind(app, (n) => { step = n === -1 ? Math.max(1, step - 1) : n; render(); });
    app.addEventListener('input', (e) => {
        const n = e.target.closest('[data-dd-note]'), dt = e.target.closest('[data-dd-date]');
        if (!n && !dt) return;
        const id = Number((n || dt).dataset[n ? 'ddNote' : 'ddDate']);
        const d = (draft[id] ??= {decision: '', new_date: '', note: ''});
        if (n) d.note = n.value; else d.new_date = dt.value;
        refreshSave(id);
    });
    app.addEventListener('click', async (e) => {
        if (e.target.closest('[data-dd-next]')) { step = Math.min(3, step + 1); reached = Math.max(reached, step); render(); return; }
        const m = e.target.closest('[data-dd-sms]');
        if (m && window.orderSms) {
            const id = Number(m.dataset.ddSms), o = D.orders.find(x => x.id === id);
            const d = draft[id], when = (d && d.decision === 'new_date' && d.new_date) ? d.new_date : (o && o.check && o.check.new_date ? o.check.new_date : '');
            if (o) orderSms.open({kinds: ['order_changed'], id: o.id, hints: {date: when || ''}, onSent: reload});
            return;
        }
        const r = e.target.closest('[data-dd-dec]');
        if (r) {
            const id = Number(r.dataset.ddDec);
            (draft[id] ??= {decision: '', new_date: '', note: ''}).decision = r.dataset.v;
            render();
            app.querySelector(r.dataset.v === 'new_date' && !draft[id].new_date ? `[data-dd-date="${id}"]` : `[data-dd-note="${id}"]`)?.focus();
            return;
        }
        const s = e.target.closest('[data-dd-save]');
        if (s) {
            const id = Number(s.dataset.ddSave), d = draft[id];
            if (!d || !d.decision || busy) return;
            if (d.decision === 'new_date' && !d.new_date) { at.notify(false, 'Nowy termin : wybierz datę.'); return; }
            if (needsNote(d.decision) && d.note.trim().length < 3) {
                at.notify(false, d.decision === 'contact' ? 'Kontakt z klientem : wpisz notatkę (z kim, co ustalono).' : 'Nowy termin : wpisz notatkę (dlaczego, kto ustalił).');
                return;
            }
            busy = true; s.disabled = true;
            const res = await tw.post('/ajax/logistics/delays/decision', {id_order: id, decision: d.decision, new_date: d.decision === 'new_date' ? d.new_date : null, note: d.note});
            busy = false;
            if (res.ok) { D = res.data; delete draft[id]; if (canFinish()) reached = Math.max(reached, 2); }
            render();
            return;
        }
        if (e.target.closest('[data-dd-finish]')) {
            if (busy) return;
            busy = true; render();
            const res = await tw.post('/ajax/logistics/delays/finish', {});
            busy = false;
            if (res.ok) { at.notify(true, res.message); D = res.data; }
            render();
        }
    });
    render();
})();
