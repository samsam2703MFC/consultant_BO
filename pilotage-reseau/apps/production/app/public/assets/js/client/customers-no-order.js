// [370] Klienci bez zamówienia (Kierownik operacyjny) : 1. przegląd stałych klientów (oczekiwani dziś, zamówili,
// bez zamówienia), 2. do kontaktu (wynik dla każdego klienta), 3. zatwierdzenie → zadanie stanowiska zalicza się samo.
// [372] Bouton SMS : relance du client (texte relu avant l'envoi) ; un SMS envoyé note « Kontakt z klientem ».
(() => {
    const app = document.getElementById('cnApp');
    if (!app || !window.tw) return;
    const {esc} = tw;
    let D = JSON.parse(document.getElementById('cnBoot').textContent);
    let step = 1, reached = 1, busy = false;
    // Brouillons de l'étape 2 (résultat, note, fin de pause) par client, gardés entre deux rendus.
    const draft = {};
    const STEPS = ['Przegląd', 'Do kontaktu', 'Zatwierdź'];
    const RES_CLS = {contacted: 'warn', arrived: 'ok', paused: ''};
    const late = () => D.clients.filter(c => c.status === 'late');
    const canFinish = () => !!D.can_finish;
    const days = (n) => `${n} ${tw.plural(n, 'dzień roboczy', 'dni robocze', 'dni roboczych')}`;
    const order = (c) => c.last_order
        ? `<a href="${at.root}/client-orders/${c.last_order.id}/edit" target="_blank" rel="noopener">#${c.last_order.id}</a> · ${esc(c.last_order.label)}${c.last_order.shipping_date ? `<small>wysyłka ${esc(tw.dm(c.last_order.shipping_date))}</small>` : ''}`
        : '—';
    const rhythm = (c) => `${esc(c.rhythm.label)}<small>${c.order_days} ${tw.plural(c.order_days, 'dzień', 'dni', 'dni')} z zamówieniem w ${D.settings.weeks} tyg.</small>`;
    const who = (c) => `<b>${esc(c.customer)}</b>${c.contact || c.phone || c.email ? `<small>${[c.contact ? esc(c.contact) : '', c.phone ? `<a href="tel:${esc(c.phone.replace(/[^\d+]/g, ''))}">${esc(c.phone)}</a>` : '', c.email ? esc(c.email) : ''].filter(Boolean).join(' · ')}</small>` : ''}`;

    const sms = (c) => window.orderSms && c.status !== 'ordered' ? `<span style="display:inline-flex;gap:6px;align-items:center;margin-left:6px">${orderSms.badge(c.sms)}${orderSms.button(`data-cn-sms="${c.id}"`, !c.phone, c.phone ? 'Wyślij SMS do klienta' : 'Brak numeru telefonu')}</span>` : '';
    async function reload() { const r = await at.send('/ajax/client-orders/missing', 'GET'); if (r.ok) { D = r.data; render(); } }

    function status(c) {
        if (c.status === 'ordered') return tw.pill('zamówił', 'ok');
        if (c.status === 'waiting') return tw.pill('czekamy — w terminie', 'warn');
        if (c.check) return tw.pill(c.check.label, RES_CLS[c.check.result] ?? '');
        return tw.pill('bez zamówienia · ' + days(c.days_late), 'bad');
    }

    function step1() {
        const t = D.totals, s = D.settings;
        const rows = D.clients.map(c => ({cls: c.status === 'late' && !c.check ? 'hl' : '', cells: [
            who(c), rhythm(c), c.expected_label ? esc(c.expected_label) + (c.expected_on === D.today ? '<small>dziś</small>' : '') : '—', order(c), `<span style="display:inline-flex;align-items:center;white-space:nowrap">${status(c)}${sms(c)}</span>`]}));
        return `<p class="tw-lead">Stali klienci i ich zwykły rytm zamówień z ostatnich ${s.weeks} tygodni. Klienci, których oczekiwane zamówienie nie przyszło, trafiają do kroku 2.</p>
            ${tw.tiles([{label: 'Stali klienci', value: String(t.regular), sub: `co najmniej ${s.min_orders} ${tw.plural(s.min_orders, 'dzień', 'dni', 'dni')} z zamówieniem w ${s.weeks} tyg.`},
                {label: 'Oczekiwani dziś', value: String(t.expected_today), sub: t.expected_today ? `${t.ordered_today} z ${t.expected_today} już zamówiło` : 'nikt nie zamawia zwykle dziś'},
                {label: 'Jeszcze w terminie', value: String(t.waiting), sub: 'oczekiwane, bez zamówienia'},
                {label: 'Bez zamówienia', value: String(t.late), sub: t.late ? `${t.resolved} z ${t.late} z wynikiem` : 'wszyscy zamówili', cls: t.open ? 'warn' : 'ok'}])}
            ${tw.table([{label: 'Klient'}, {label: 'Rytm'}, {label: 'Oczekiwane'}, {label: 'Ostatnie zamówienie'}, {label: ''}],
                rows, 'Dziś żaden stały klient nie ma oczekiwanego zamówienia.')}
            <p class="tw-note">Rytm : dni tygodnia, w które klient zamawiał w co najmniej ${s.share} % tygodni ; bez stałych dni — średni odstęp między zamówieniami.
                Bez zamówienia : ${s.grace} ${tw.plural(s.grace, 'dzień roboczy', 'dni robocze', 'dni roboczych')} (${esc(s.workdays_label)}) po oczekiwanym dniu.
                Liczą się zamówienia zwykłe (bez domówień, gratisów i zarchiwizowanych), tylko klienci aktywni. Ustawienia › Parametry : orders.regular_min_orders, orders.weekday_share_pct, orders.missing_grace_days.</p>`;
    }

    function resultCell(c) {
        const ck = c.check && !c.check.inherited ? c.check : null;
        const d = draft[c.id] ?? (draft[c.id] = {result: ck ? ck.result : '', note: ck ? (ck.note || '') : '', until: ck ? (ck.paused_until || '') : ''});
        const seg = Object.entries(D.results).map(([k, l]) => `<button type="button" class="${d.result === k ? 'on' : ''}" data-cn-res="${c.id}" data-v="${k}">${esc(l)}</button>`).join('');
        const need = d.result === 'contacted';
        const saved = c.check ? `<small>${tw.pill(c.check.label, RES_CLS[c.check.result] ?? '')}${c.check.paused_label ? ' do ' + esc(c.check.paused_label) : ''}
            ${c.check.inherited ? ' · zapisane ' + esc(c.check.saved_on) : ' ' + esc(c.check.at || '')}${c.check.by ? ' · ' + esc(c.check.by) : ''}${c.check.inherited && c.check.note ? ' · ' + esc(c.check.note) : ''}</small>` : '';
        const dirty = !ck || ck.result !== d.result || (ck.note || '') !== d.note || (ck.paused_until || '') !== d.until;
        const until = d.result === 'paused' ? `<label style="display:flex;gap:6px;align-items:center;white-space:nowrap"><small>do</small><input type="date" class="form-control form-control-sm" data-cn-until="${c.id}"
                value="${esc(d.until)}" min="${esc(D.today)}" max="${esc(D.pause_max)}" style="min-height:36px;border-radius:9px;width:150px"></label>` : '';
        return `<div style="display:grid;gap:6px;min-width:360px"><div class="tw-seg" style="justify-self:start">${seg}</div>
            <div style="display:flex;gap:6px"><input class="form-control form-control-sm" data-cn-note="${c.id}" maxlength="500" value="${esc(d.note)}"
                placeholder="${need ? 'Notatka (wymagana) : z kim, co ustalono' : (d.result === 'paused' ? 'Powód (urlop, remont…)' : 'Notatka (opcjonalnie)')}" style="min-height:36px;border-radius:9px">${until}
            <button type="button" class="tw-btn ghost sm" data-cn-save="${c.id}" ${!d.result || !dirty || busy ? 'disabled' : ''}>Zapisz</button></div>${saved}</div>`;
    }

    function step2() {
        const list = late(), t = D.totals;
        if (!list.length) return tw.alert('Nikogo do kontaktu : wszyscy stali klienci zamówili w swoim rytmie.', 'ok', 'bi-check-circle');
        const rows = list.map(c => ({cls: c.check ? '' : 'hl', cells: [
            who(c) + `<div style="margin-top:4px;margin-left:-6px">${sms(c)}</div>`, `${esc(c.expected_label)}<small>${esc(c.rhythm.label)} · ${days(c.days_late)} temu</small>`, order(c), resultCell(c)]}));
        return `<p class="tw-lead">Ci stali klienci nie złożyli oczekiwanego zamówienia. Skontaktuj się i zapisz wynik : <b>Kontakt z klientem</b> (z notatką),
                <b>Zamówienie przyszło</b> albo <b>Brak zamówienia w planie</b> (przerwa, urlop — z datą końca, jeśli znana). Krok 3 otworzy się, gdy wszyscy mają wynik.</p>
            ${tw.tiles([{label: 'Do kontaktu', value: String(t.late), sub: 'bez oczekiwanego zamówienia'}, {label: 'Z wynikiem', value: `${t.resolved} z ${t.late}`, sub: t.open ? `zostało ${t.open}` : 'wszyscy wyjaśnieni', cls: t.open ? 'warn' : 'ok'},
                {label: 'Kontakt z klientem', value: String(t.contacted), sub: 'z notatką'}, {label: 'Przerwa / przyszło', value: `${t.paused} / ${t.arrived}`, sub: 'brak w planie / zamówienie przyszło'}])}
            ${tw.table([{label: 'Klient'}, {label: 'Oczekiwane'}, {label: 'Ostatnie zamówienie'}, {label: 'Wynik'}], rows)}
            <p class="tw-note">Wynik dotyczy oczekiwanego dnia : jutro klient nie wróci na listę, dopóki nie minie jego kolejny dzień zamówienia. Przerwa z datą obejmuje też kolejne dni do tej daty.
                Przycisk SMS : przypomnienie do klienta w jego języku (tekst do poprawienia przed wysłaniem) ; wysłany SMS zapisuje wynik « Kontakt z klientem ». Nic nie wychodzi bez kliknięcia.</p>`;
    }

    function step3() {
        const t = D.totals;
        if (D.done) return tw.done('Klienci bez zamówienia sprawdzeni · ' + (D.done.at || ''), D.done.summary + ' · zadanie zaliczone');
        const list = late();
        return `<p class="tw-lead">Gotowe : <b>${esc(D.summary)}</b>.</p>
            ${tw.tiles([{label: 'Stali klienci', value: String(t.regular), sub: `${t.ordered_today} z ${t.expected_today} oczekiwanych dziś zamówiło`}, {label: 'Bez zamówienia', value: String(t.late), sub: 'wszyscy z wynikiem', cls: 'ok'},
                {label: 'Kontakt z klientem', value: String(t.contacted), sub: 'notatki poniżej'}, {label: 'Przerwa / przyszło', value: `${t.paused} / ${t.arrived}`, sub: D.label}])}
            ${list.length ? `<div class="tw-cap">Wyniki</div>` + tw.table([{label: 'Klient'}, {label: 'Oczekiwane'}, {label: 'Wynik'}, {label: 'Notatka'}],
                list.map(c => ({cells: [`<b>${esc(c.customer)}</b>`, esc(c.expected_label), tw.pill(c.check.label, RES_CLS[c.check.result] ?? '') + (c.check.paused_label ? ' <small>do ' + esc(c.check.paused_label) + '</small>' : ''), esc(c.check.note || '—')]}))) : ''}
            <p class="tw-note">« Zakończ » zalicza zadanie « Klienci bez zamówienia » na dziś. Nic nie jest wysyłane do klientów.</p>`;
    }

    function render() {
        const top = canFinish() ? reached : Math.min(reached, 2);
        if (step > top) step = top;
        const body = step === 1 ? step1() : (step === 2 ? step2() : step3());
        const next = step === 1 ? {label: 'Dalej : do kontaktu', attr: 'data-cn-next'}
            : step === 2 ? {label: 'Dalej : zatwierdź', attr: 'data-cn-next', disabled: !canFinish()}
                : (D.done ? null : {label: 'Zakończ', attr: 'data-cn-finish', final: true, disabled: busy || !canFinish()});
        app.innerHTML = tw.frame({title: 'Klienci bez zamówienia', sub: `${D.label} · krok ${step} z 3 · ok. 10 min`, steps: STEPS, step, reached: top, body,
            prev: step > 1 ? 'Wstecz' : null, next, later: !D.done && step < 3});
    }

    function refreshSave(id) {
        const c = D.clients.find(x => x.id === id), d = draft[id];
        const btn = app.querySelector(`[data-cn-save="${id}"]`);
        if (!btn || !c || !d) return;
        const ck = c.check && !c.check.inherited ? c.check : null;
        btn.disabled = !d.result || busy || (ck && ck.result === d.result && (ck.note || '') === d.note && (ck.paused_until || '') === d.until);
    }

    tw.bind(app, (n) => { step = n === -1 ? Math.max(1, step - 1) : n; render(); });
    app.addEventListener('input', (e) => {
        const n = e.target.closest('[data-cn-note]'), u = e.target.closest('[data-cn-until]');
        if (!n && !u) return;
        const id = Number((n || u).dataset[n ? 'cnNote' : 'cnUntil']);
        const d = draft[id] ??= {result: '', note: '', until: ''};
        if (n) d.note = n.value; else d.until = u.value;
        refreshSave(id);
    });
    app.addEventListener('click', async (e) => {
        if (e.target.closest('[data-cn-next]')) { step = Math.min(3, step + 1); reached = Math.max(reached, step); render(); return; }
        const m = e.target.closest('[data-cn-sms]');
        if (m && window.orderSms) {
            const c = D.clients.find(x => x.id === Number(m.dataset.cnSms));
            if (c) orderSms.open({kinds: ['order_missing'], id: c.id, hints: {expected: c.expected_on}, onSent: async () => { delete draft[c.id]; await reload(); }});
            return;
        }
        const r = e.target.closest('[data-cn-res]');
        if (r) {
            const id = Number(r.dataset.cnRes), d = draft[id] ??= {result: '', note: '', until: ''};
            d.result = r.dataset.v;
            if (d.result !== 'paused') d.until = '';
            render(); app.querySelector(`[data-cn-note="${id}"]`)?.focus(); return;
        }
        const s = e.target.closest('[data-cn-save]');
        if (s) {
            const id = Number(s.dataset.cnSave), d = draft[id];
            if (!d || !d.result || busy) return;
            if (d.result === 'contacted' && d.note.trim().length < 3) { at.notify(false, 'Kontakt z klientem : wpisz notatkę (z kim, co ustalono).'); return; }
            busy = true; s.disabled = true;
            const res = await tw.post('/ajax/client-orders/missing/result', {id_customer: id, result: d.result, note: d.note, paused_until: d.result === 'paused' ? d.until : ''});
            busy = false;
            if (res.ok) { D = res.data; delete draft[id]; if (canFinish()) reached = Math.max(reached, 2); }
            render();
            return;
        }
        if (e.target.closest('[data-cn-finish]')) {
            if (busy) return;
            busy = true; render();
            const res = await tw.post('/ajax/client-orders/missing/finish', {});
            busy = false;
            if (res.ok) { at.notify(true, res.message); D = res.data; }
            render();
        }
    });
    render();
})();
