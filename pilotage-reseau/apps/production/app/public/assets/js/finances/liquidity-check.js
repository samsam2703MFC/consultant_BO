// [373] Płynność i gotówka (Kierownik operacyjny, co tydzień) : 1. sytuacja 4 tygodni (wpływy, wydatki, zaległości),
// 2. plan (saldo dostępne, prognoza tydzień po tygodniu, decyzja dla każdego sygnału), 3. podsumowanie → zadanie
// tygodnia zalicza się samo. Kwoty tylko w profilu Finanse i CEO (serwer je usuwa). Nic nie jest wysyłane.
(() => {
    const app = document.getElementById('lqApp');
    if (!app || !window.tw) return;
    const {esc, nf} = tw;
    let D = JSON.parse(document.getElementById('lqBoot').textContent);
    let step = 1, reached = 1, busy = false;
    // Brouillons : saldo (étape 2) et décision par signal, gardés entre deux rendus.
    let bal = null;
    const draft = {};
    const STEPS = ['Sytuacja', 'Plan', 'Podsumowanie'];
    const DEC_CLS = {monitor: 'warn', action: 'bad', no_risk: 'ok'};
    const TYPE_ICON = {week: 'bi-calendar-week', client: 'bi-shop', supplier: 'bi-truck'};
    const money = (v) => v === null || v === undefined ? (D.values ? '—' : '<span style="color:#8a8580">ukryte</span>') : tw.zl(v);
    const signedZl = (v) => v === null || v === undefined ? money(v) : (v < 0 ? `<span class="tw-red">${tw.zl(v)}</span>` : tw.zl(v));
    const needsNote = (d) => (D.note_required || []).includes(d);
    const canFinish = () => !!D.can_finish;

    function balDraft() {
        if (bal) return bal;
        const b = D.balance || {};
        const v = (x) => (x === null || x === undefined || !b.saved || !D.values) ? '' : String(x).replace('.', ',');
        bal = {bank_pln: v(b.bank_pln), bank_eur: v(b.bank_eur), cash_pln: v(b.cash_pln), other: [0, 1, 2, 3].map(i => v((b.other || [])[i] || null)),
            date: b.saved ? b.date : D.today, note: b.note || ''};
        return bal;
    }

    function alerts() {
        const out = [];
        if (!D.values) out.push(tw.alert('Kwoty widzi tylko profil <b>Finanse</b> i <b>CEO</b> : tutaj są ukryte. Sygnały, terminy i decyzje działają bez kwot.', 'info', 'bi-eye-slash'));
        if (D.rate_missing) out.push(tw.alert('Brak kursu EUR w ustawieniach firmy : kwoty w € liczone 1 : 1 jak zł.', '', 'bi-currency-exchange'));
        if (D.payroll.missing.length) out.push(tw.alert(`Brak zatwierdzonej listy płac : wynagrodzenia (${esc(D.payroll.missing.join(', '))}) nie są w prognozie — dopisz je w « Inne wydatki ».`, '', 'bi-people'));
        return out.join('');
    }

    function weeksTable(withEnd) {
        const rows = D.weeks.map(w => ({cls: withEnd && w.risk ? 'hl' : '', cells: [
            `<b>Tydzień ${w.no}</b><small>${esc(w.label)}</small>`, money(w.in_receivables), money(w.in_orders), money(w.out_suppliers), money(w.out_payroll), money(w.out_other),
            signedZl(w.net), withEnd ? (w.end === null && D.values ? '<small>po wpisaniu salda</small>' : signedZl(w.end)) : null,
            withEnd ? (w.risk ? tw.pill('ryzyko', 'bad') : (D.balance.saved ? tw.pill('OK', 'ok') : '')) : (w.negative_flow ? tw.pill('wydatki > wpływy', 'warn') : '')].filter(c => c !== null)}));
        const head = [{label: 'Tydzień'}, {label: 'Wpływy : proformy', num: true}, {label: 'Wpływy : zamówienia', num: true}, {label: 'Dostawcy', num: true},
            {label: 'Wynagrodzenia, ZUS, PIT', num: true}, {label: 'Inne', num: true}, {label: 'Netto', num: true}];
        if (withEnd) head.push({label: 'Saldo na koniec', num: true});
        head.push({label: ''});
        return tw.table(head, rows);
    }

    function step1() {
        const t = D.totals;
        const clients = D.clients.slice(0, 12).map(c => ({cls: c.overdue_count ? 'hl' : '', cells: [
            `<b>${esc(c.name)}</b><small>termin ${c.terms ?? '—'} dni</small>`, String(c.proformas), money(c.due_4w),
            c.overdue_count ? `<span class="tw-red">${money(c.overdue)}</span><small>${c.overdue_count} ${tw.plural(c.overdue_count, 'proforma', 'proformy', 'proform')}</small>` : '—',
            c.max_days_over ? `${c.max_days_over} ${tw.plural(c.max_days_over, 'dzień', 'dni', 'dni')}` : '—',
            c.reminder ? `poziom ${c.reminder.level}<small>${esc(c.reminder.at)}</small>` : '—',
            c.open_orders ? `${c.open_orders}<small>${money(c.open_value)}</small>` : '—']}));
        const pay = D.outflows.slice(0, 15).map(o => ({cells: [`<b>${esc(tw.dm(o.date))}</b><small>tydzień ${o.week + 1}</small>`, esc(o.supplier), `${esc(o.label)}<small>${esc(o.type_label)}</small>`, money(o.amount)]}));
        return `<p class="tw-lead">Co wpłynie i co wyjdzie w najbliższych 4 tygodniach (<b>${esc(D.horizon)}</b>), z danych ERP. Zaległe należności są pokazane osobno i <b>nie</b> liczą się we wpływach.</p>
            ${alerts()}
            ${tw.tiles([{label: 'Wpływy 4 tyg.', html: money(t.in_4w), sub: `${t.proformas_4w} ${tw.plural(t.proformas_4w, 'proforma', 'proformy', 'proform')} · ${t.orders_4w} ${tw.plural(t.orders_4w, 'zamówienie', 'zamówienia', 'zamówień')} bez proformy`},
                {label: 'Wydatki 4 tyg.', html: money(t.out_4w), sub: `${t.payables_4w} ${tw.plural(t.payables_4w, 'faktura / zamówienie', 'faktury / zamówienia', 'faktur / zamówień')} · wynagrodzenia`},
                {label: 'Zaległe należności', html: money(t.overdue_total), sub: `${t.overdue_clients} ${tw.plural(t.overdue_clients, 'klient', 'klientów', 'klientów')} po terminie`, cls: t.overdue_clients ? 'warn' : 'ok'},
                {label: 'Tygodnie z ujemnym przepływem', value: String(t.negative_weeks), sub: 'przed wpisaniem salda', cls: t.negative_weeks ? 'warn' : 'ok'}])}
            <div class="tw-cap">Tydzień po tygodniu</div>${weeksTable(false)}
            <div class="tw-cap">Należności klientów (proformy niezapłacone)</div>
            ${tw.table([{label: 'Klient'}, {label: 'Proformy', num: true}, {label: 'Do zapłaty w 4 tyg.', num: true}, {label: 'Zaległe', num: true}, {label: 'Po terminie'}, {label: 'Przypomnienie'}, {label: 'Zamówienia otwarte'}],
                clients, 'Brak niezapłaconych proform.')}
            <div class="tw-cap">Do zapłaty w 4 tygodniach${D.outflows.length > 15 ? ` (15 pierwszych z ${D.outflows.length})` : ''}</div>
            ${tw.table([{label: 'Termin'}, {label: 'Komu'}, {label: 'Co'}, {label: 'Kwota', num: true}], pay, 'Brak płatności w 4 tygodniach.')}
            <p class="tw-note">Wpływy : proformy niezapłacone — termin = data proformy + « czas na płatność » klienta (bez niego ${D.settings.client_terms_days} dni) ; zamówienia otwarte bez proformy — wysyłka + termin klienta.
                Wydatki : faktury zakupowe (brutto) i zamówienia u dostawców bez faktury (netto) — termin = wystawienie / dostawa + ${D.settings.supplier_terms_days} dni (ERP nie zapisuje terminu ani zapłaty faktur zakupowych : faktura po tym terminie jest uznana za zapłaconą) ;
                wynagrodzenia netto (10. dzień miesiąca albo data przelewów), ZUS 15., PIT 20.${D.payroll.estimate.length ? ' — ' + esc(D.payroll.estimate.join(', ')) + ' : szacunek z ostatniej zatwierdzonej listy płac' : ''}.
                Kwoty w zł${D.rate ? ', € po kursie ' + nf(D.rate, 4) + ' z ustawień firmy' : ''}. Progi : Ustawienia › Parametry (liquidity.*).</p>`;
    }

    function balanceForm() {
        const b = balDraft(), s = D.balance;
        const inp = (name, val, ph, i = null) => `<input class="form-control" inputmode="decimal" data-lq-bal="${name}"${i !== null ? ` data-i="${i}"` : ''} value="${esc(val)}" placeholder="${esc(ph)}">`;
        const field = (label, html) => `<div class="tw-field" style="margin:0"><label>${esc(label)}</label>${html}</div>`;
        const saved = s.saved ? tw.pill('zapisane', 'ok') + ` <small>${esc(s.date_label)} · ${esc(s.at || '')}${s.by ? ' · ' + esc(s.by) : ''}${!D.values ? ' — kwoty ukryte, wpisz ponownie, aby zmienić' : ''}</small>` : tw.pill('do wpisania', 'bad');
        return `<div class="tw-cap">Saldo dostępne ${saved}</div>
            <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;max-width:900px">
                ${field('Bank (zł)', inp('bank_pln', b.bank_pln, 'np. 120 000'))}${field('Bank (€)', inp('bank_eur', b.bank_eur, '0'))}${field('Kasa (zł)', inp('cash_pln', b.cash_pln, '0'))}
                ${field('Saldo na dzień', `<input class="form-control" type="date" data-lq-bal="date" value="${esc(b.date)}" min="${esc(D.week_start)}" max="${esc(D.today)}">`)}
            </div>
            <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;max-width:900px;margin-top:10px">
                ${D.weeks.map((w, i) => field(`Inne wydatki · tydz. ${w.no} (${w.label})`, inp('other', b.other[i], '0', i))).join('')}
            </div>
            <div style="display:flex;gap:10px;align-items:center;margin-top:10px;max-width:900px">
                <input class="form-control" data-lq-bal="note" maxlength="500" value="${esc(b.note)}" placeholder="Notatka (opcjonalnie) : rata kredytu, podatek, czynsz…" style="min-height:40px;border-radius:10px">
                <button type="button" class="tw-btn ghost" data-lq-save-bal ${busy ? 'disabled' : ''}>Zapisz saldo</button>
            </div>`;
    }

    function decisionCell(s) {
        const d = draft[s.key] ?? (draft[s.key] = {decision: s.decision ? s.decision.decision : '', note: s.decision ? (s.decision.note || '') : ''});
        const seg = Object.entries(D.decisions).map(([k, l]) => `<button type="button" class="${d.decision === k ? 'on' : ''}" data-lq-dec="${esc(s.key)}" data-v="${k}">${esc(l)}</button>`).join('');
        const saved = s.decision ? `<small>${tw.pill(s.decision.label, DEC_CLS[s.decision.decision] ?? '')} ${esc(s.decision.at || '')}${s.decision.by ? ' · ' + esc(s.decision.by) : ''}</small>` : '';
        const dirty = !s.decision || s.decision.decision !== d.decision || (s.decision.note || '') !== d.note;
        const ph = d.decision === 'action' ? 'Notatka (wymagana) : przypomnienie, przesunięcie płatności, kredyt…' : 'Notatka (opcjonalnie)';
        return `<div style="display:grid;gap:6px;min-width:360px"><div class="tw-seg" style="justify-self:start">${seg}</div>
            <div style="display:flex;gap:6px"><input class="form-control form-control-sm" data-lq-note="${esc(s.key)}" maxlength="500" value="${esc(d.note)}" placeholder="${ph}" style="min-height:36px;border-radius:9px">
            <button type="button" class="tw-btn ghost sm" data-lq-save="${esc(s.key)}" ${!d.decision || !dirty || busy ? 'disabled' : ''}>Zapisz</button></div>${saved}</div>`;
    }

    function step2() {
        const t = D.totals;
        const rows = D.signals.map(s => ({cls: s.decision ? '' : 'hl', cells: [
            `<i class="bi ${TYPE_ICON[s.type] || 'bi-flag'}"></i> <b>${esc(s.title)}</b>${s.amount !== null ? `<small>${signedZl(s.amount)}</small>` : ''}`,
            s.reasons.map(r => `<div style="display:flex;gap:6px;align-items:baseline;margin:2px 0"><i class="bi bi-exclamation-circle tw-red"></i><span>${esc(r)}</span></div>`).join(''),
            decisionCell(s)]}));
        return `<p class="tw-lead">Wpisz saldo banku i kasy na dziś — prognoza przeliczy się tydzień po tygodniu. Potem dla każdego sygnału : <b>Monitorować</b>, <b>Działanie</b> (z notatką) albo <b>Bez ryzyka</b>.</p>
            ${alerts()}
            ${balanceForm()}
            ${tw.tiles([{label: 'Saldo na start', html: money(t.opening), sub: D.balance.saved ? 'bank + kasa' : 'niewpisane', cls: D.balance.saved ? '' : 'warn'},
                {label: 'Najniższe saldo', html: money(t.min_end), sub: `poduszka ${D.values ? tw.zl(D.settings.min_balance) : 'ukryta'}`, cls: t.risk_weeks ? 'warn' : (D.balance.saved ? 'ok' : '')},
                {label: 'Tygodnie z ryzykiem', value: String(t.risk_weeks), sub: D.balance.saved ? 'saldo poniżej poduszki' : 'po wpisaniu salda', cls: t.risk_weeks ? 'warn' : 'ok'},
                {label: 'Sygnały z decyzją', value: `${t.decided} z ${t.signals}`, sub: t.waiting ? `zostało ${t.waiting}` : 'wszystkie', cls: t.waiting ? 'warn' : 'ok'}])}
            <div class="tw-cap">Prognoza z saldem</div>${weeksTable(true)}
            <div class="tw-cap">Sygnały</div>
            ${rows.length ? tw.table([{label: 'Sygnał'}, {label: 'Dlaczego'}, {label: 'Decyzja'}], rows)
                : tw.alert('Brak sygnałów : żaden klient nie ma dużej zaległości, brak dużych płatności w ' + D.big_purchase_days + ' dni' + (D.balance.saved ? ', saldo powyżej poduszki we wszystkich tygodniach.' : ' (tygodnie sprawdzimy po wpisaniu salda).'), 'ok', 'bi-check-circle')}
            <p class="tw-note">Sygnały : tydzień z saldem na koniec poniżej poduszki (liquidity.min_balance) ; klient z zaległością od ${D.values ? tw.zl(D.settings.overdue_min) : 'progu'} (liquidity.overdue_min) albo z zaległością, który nadal zamawia ;
                dostawca, któremu trzeba zapłacić w ${D.big_purchase_days} dni od ${D.values ? tw.zl(D.settings.big_purchase_min) : 'progu'} (liquidity.big_purchase_min). Decyzja tylko zapisuje ustalenie : nic nie jest wysyłane, płatności i zamówienia zmieniasz w ich ekranach.</p>`;
    }

    function step3() {
        const t = D.totals;
        if (D.done) return tw.done('Płynność sprawdzona · ' + (D.done.at || ''), D.done.summary + ' · zadanie zaliczone');
        const list = D.signals.filter(s => s.decision);
        return `<p class="tw-lead">Gotowe : <b>${esc(D.summary)}</b>.</p>
            ${tw.tiles([{label: 'Saldo na start', html: money(t.opening), sub: D.balance.date_label || ''}, {label: 'Saldo po 4 tygodniach', html: money(t.end_last), cls: t.end_last !== null && t.end_last < 0 ? 'warn' : '', sub: `najniższe ${D.values ? (t.min_end !== null ? tw.zl(t.min_end) : '—') : 'ukryte'}`},
                {label: 'Tygodnie z ryzykiem', value: String(t.risk_weeks), sub: 'z 4', cls: t.risk_weeks ? 'warn' : 'ok'}, {label: 'Decyzje', value: String(t.decided), sub: `${t.action} ${tw.plural(t.action, 'działanie', 'działania', 'działań')} · ${t.monitor} monitorować · ${t.no_risk} bez ryzyka`}])}
            ${weeksTable(true)}
            ${list.length ? `<div class="tw-cap">Decyzje</div>` + tw.table([{label: 'Sygnał'}, {label: 'Decyzja'}, {label: 'Notatka'}],
                list.map(s => ({cells: [`<b>${esc(s.title)}</b>`, tw.pill(s.decision.label, DEC_CLS[s.decision.decision] ?? ''), esc(s.decision.note || '—')]}))) : ''}
            <p class="tw-note">« Zakończ » zalicza zadanie « Płynność i gotówka » na ten tydzień. Nic nie jest wysyłane ; zapisane są tylko saldo i decyzje.</p>`;
    }

    function render() {
        const top = canFinish() ? reached : Math.min(reached, 2);
        if (step > top) step = top;
        const body = step === 1 ? step1() : (step === 2 ? step2() : step3());
        const next = step === 1 ? {label: 'Dalej : plan', attr: 'data-lq-next'}
            : step === 2 ? {label: 'Dalej : podsumowanie', attr: 'data-lq-next', disabled: !canFinish()}
                : (D.done ? null : {label: 'Zakończ', attr: 'data-lq-finish', final: true, disabled: busy || !canFinish()});
        app.innerHTML = tw.frame({title: 'Płynność i gotówka', sub: `${D.label} · ${D.horizon} · krok ${step} z 3 · ok. 15 min`, steps: STEPS, step, reached: top, body,
            prev: step > 1 ? 'Wstecz' : null, next, later: !D.done && step < 3});
    }

    tw.bind(app, (n) => { step = n === -1 ? Math.max(1, step - 1) : n; render(); });
    app.addEventListener('input', (e) => {
        const b = e.target.closest('[data-lq-bal]');
        if (b) {
            const d = balDraft();
            if (b.dataset.lqBal === 'other') d.other[Number(b.dataset.i)] = b.value; else d[b.dataset.lqBal] = b.value;
            return;
        }
        const n = e.target.closest('[data-lq-note]');
        if (!n) return;
        const key = n.dataset.lqNote;
        (draft[key] ??= {decision: '', note: ''}).note = n.value;
        const s = D.signals.find(x => x.key === key), d = draft[key];
        const btn = [...app.querySelectorAll('[data-lq-save]')].find(x => x.dataset.lqSave === key);
        if (btn && s) btn.disabled = !d.decision || busy || (s.decision && s.decision.decision === d.decision && (s.decision.note || '') === d.note);
    });
    app.addEventListener('click', async (e) => {
        if (e.target.closest('[data-lq-next]')) { step = Math.min(3, step + 1); reached = Math.max(reached, step); render(); return; }
        const r = e.target.closest('[data-lq-dec]');
        if (r) { (draft[r.dataset.lqDec] ??= {decision: '', note: ''}).decision = r.dataset.v; render(); return; }
        if (e.target.closest('[data-lq-save-bal]')) {
            if (busy) return;
            const b = balDraft();
            busy = true; render();
            const res = await tw.post('/ajax/finances/liquidity/balance', {bank_pln: b.bank_pln, bank_eur: b.bank_eur, cash_pln: b.cash_pln, other: b.other, date: b.date, note: b.note});
            busy = false;
            if (res.ok) { at.notify(true, res.message); D = res.data; bal = null; }
            render();
            return;
        }
        const s = e.target.closest('[data-lq-save]');
        if (s) {
            const key = s.dataset.lqSave, d = draft[key];
            if (!d || !d.decision || busy) return;
            if (needsNote(d.decision) && d.note.trim().length < 3) { at.notify(false, 'Działanie : wpisz notatkę (przypomnienie, przesunięcie płatności, kredyt…).'); return; }
            busy = true; s.disabled = true;
            const res = await tw.post('/ajax/finances/liquidity/decision', {key, decision: d.decision, note: d.note});
            busy = false;
            if (res.ok) { D = res.data; delete draft[key]; }
            render();
            return;
        }
        if (e.target.closest('[data-lq-finish]')) {
            if (busy) return;
            busy = true; render();
            const res = await tw.post('/ajax/finances/liquidity/finish', {});
            busy = false;
            if (res.ok) { at.notify(true, res.message); D = res.data; }
            render();
        }
    });
    render();
})();
