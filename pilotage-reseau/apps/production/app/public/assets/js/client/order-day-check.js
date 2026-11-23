// [369] Zamówienia dnia (Kierownik operacyjny) : 1. nouvelles et modifiées depuis la validation précédente,
// 2. à vérifier (signaux automatiques, un résultat par commande), 3. validation → la tâche du poste se valide seule.
(() => {
    const app = document.getElementById('odApp');
    if (!app || !window.tw) return;
    const {esc, nf} = tw;
    let D = JSON.parse(document.getElementById('odBoot').textContent);
    let step = 1, reached = 1, busy = false;
    // Brouillons de l'étape 2 (résultat choisi, note) par commande, gardés entre deux rendus.
    const draft = {};
    const STEPS = ['Nowe i zmienione', 'Do wyjaśnienia', 'Zatwierdź'];
    const RES_CLS = {ok: 'ok', contact: 'warn', changed: ''};
    const link = (o) => `<a href="${at.root}/client-orders/${o.id}/edit" target="_blank" rel="noopener">#${o.id}</a>`;
    const ref = (o) => `${link(o)}${o.reference ? ' · ' + esc(o.reference) : ''}${o.description ? `<small>${esc(o.description)}</small>` : ''}`;
    // [372] SMS au client (confirmation ou modification), un clic, texte relu dans la fenêtre.
    const sms = (o) => window.orderSms ? `<span style="display:inline-flex;gap:6px;align-items:center;margin-left:6px">${orderSms.badge(o.sms)}${orderSms.button(`data-od-sms="${o.id}"`)}</span>` : '';
    async function reload() { const r = await at.send('/ajax/client-orders/day-check', 'GET'); if (r.ok) { D = r.data; render(); } }
    const ship = (o) => o.shipping_date ? `${esc(dayLabel(o.shipping_date))}` : '<span class="tw-red">brak</span>';
    const type = (o) => (o.type === 'SUPPLEMENT' ? tw.pill('domówienie', 'warn') : 'zwykłe') + (o.freebie ? ' ' + tw.pill('gratis') : '');
    const flagged = () => D.orders.filter(o => o.flags.length);
    const canFinish = () => !!D.can_finish;

    function dayLabel(d) {
        const days = ['niedz.', 'pon.', 'wt.', 'śr.', 'czw.', 'pt.', 'sob.'];
        const t = new Date(d + 'T12:00:00');
        return isNaN(t) ? d : `${days[t.getDay()]} ${t.getDate()}.${String(t.getMonth() + 1).padStart(2, '0')}`;
    }

    function what(o) {
        if (o.kind === 'new') return tw.pill('nowe', 'ok') + (o.changes.length ? `<small>potem : ${o.changes.map(esc).join(' · ')}</small>` : '');
        const at_ = o.changed_at ? o.changed_at.slice(11, 16).replace(/^0/, '') : '';
        return `${o.changes.map(esc).join(' · ')}<small>zmiana ${esc(at_)}${o.changed_by ? ' · ' + esc(o.changed_by) : ''}</small>`;
    }

    function step1() {
        const t = D.totals;
        const rows = D.orders.map(o => ({cls: o.flags.length && !o.check ? 'hl' : '', cells: [
            `<b>${esc(o.customer)}</b>`, ref(o), ship(o), nf(o.lines), nf(o.cartons, o.cartons % 1 ? 1 : 0), type(o), what(o),
            `<span style="display:inline-flex;align-items:center;white-space:nowrap">${o.flags.length ? (o.check ? tw.pill('wyjaśnione', 'ok') : tw.pill('do wyjaśnienia' + (o.flags.length > 1 ? ' (' + o.flags.length + ')' : ''), 'bad')) : (o.open ? '' : tw.pill(o.status_label))}${sms(o)}</span>`]}));
        return `<p class="tw-lead">Zamówienia złożone i zmienione <b>${esc(D.since.label)}</b>. Sprawdź listę ; zamówienia z sygnałem trafiają do kroku 2.</p>
            ${tw.tiles([{label: 'Nowe', value: String(t.new), sub: t.supplements ? `w tym ${t.supplements} ${tw.plural(t.supplements, 'domówienie', 'domówienia', 'domówień')}` : 'zamówienia złożone'},
                {label: 'Zmienione', value: String(t.changed), sub: 'data, pozycje albo opis'},
                {label: 'Do wyjaśnienia', value: String(t.flagged), sub: t.flagged ? `${t.resolved} z ${t.flagged} z wynikiem` : 'brak sygnałów', cls: t.waiting ? 'warn' : 'ok'},
                {label: 'Kartony', value: nf(t.cartons) + ' kart.', sub: `w ${t.orders} ${tw.plural(t.orders, 'zamówieniu', 'zamówieniach', 'zamówieniach')}`}])}
            ${tw.table([{label: 'Klient'}, {label: 'Nr / referencja'}, {label: 'Wysyłka'}, {label: 'Pozycje', num: true}, {label: 'Kartony', num: true}, {label: 'Typ'}, {label: 'Co się zmieniło'}, {label: ''}],
                rows, 'Brak nowych i zmienionych zamówień od ostatniego sprawdzenia.')}
            <p class="tw-note">Najkrótszy termin do wysyłki : ${D.lead_days} ${tw.plural(D.lead_days, 'dzień', 'dni', 'dni')} (Ustawienia › Parametry, orders.min_lead_days). Zmiany techniczne (proforma, status, wysyłka domówienia) nie liczą się jako zmiana.</p>`;
    }

    function resultCell(o) {
        const d = draft[o.id] ?? (draft[o.id] = {result: o.check ? o.check.result : '', note: o.check ? (o.check.note || '') : ''});
        const seg = Object.entries(D.results).map(([k, l]) => `<button type="button" class="${d.result === k ? 'on' : ''}" data-od-res="${o.id}" data-v="${k}">${esc(l)}</button>`).join('');
        const need = d.result === 'contact';
        const saved = o.check ? `<small>${tw.pill(o.check.label, RES_CLS[o.check.result] ?? '')} ${esc(o.check.at || '')}${o.check.by ? ' · ' + esc(o.check.by) : ''}</small>` : '';
        const dirty = !o.check || o.check.result !== d.result || (o.check.note || '') !== d.note;
        return `<div style="display:grid;gap:6px;min-width:330px"><div class="tw-seg" style="justify-self:start">${seg}</div>
            <div style="display:flex;gap:6px"><input class="form-control form-control-sm" data-od-note="${o.id}" maxlength="500" value="${esc(d.note)}"
                placeholder="${need ? 'Notatka (wymagana) : z kim, co ustalono' : 'Notatka (opcjonalnie)'}" style="min-height:36px;border-radius:9px">
            <button type="button" class="tw-btn ghost sm" data-od-save="${o.id}" ${!d.result || !dirty || busy ? 'disabled' : ''}>Zapisz</button></div>${saved}</div>`;
    }

    function step2() {
        const list = flagged(), t = D.totals;
        if (!list.length) return tw.alert('Nic do wyjaśnienia : żadne nowe ani zmienione zamówienie nie ma sygnału.', 'ok', 'bi-check-circle');
        const rows = list.map(o => ({cls: o.check ? '' : 'hl', cells: [
            `<b>${esc(o.customer)}</b><small>${link(o)}${o.reference ? ' · ' + esc(o.reference) : ''} · ${o.kind === 'new' ? 'nowe' : 'zmienione'}</small><div style="margin-top:4px;margin-left:-6px">${sms(o)}</div>`,
            ship(o), nf(o.cartons, o.cartons % 1 ? 1 : 0),
            o.flags.map(f => `<div style="display:flex;gap:6px;align-items:baseline;margin:2px 0"><i class="bi bi-exclamation-circle tw-red"></i><span>${esc(f.reason)}</span></div>`).join(''),
            resultCell(o)]}));
        return `<p class="tw-lead">Sygnały automatyczne. Dla każdego zamówienia zapisz wynik : <b>OK — sprawdzone</b>, <b>Kontakt z klientem</b> (z notatką) albo <b>Zmienione</b>.
                Krok 3 otworzy się, gdy wszystkie mają wynik.</p>
            ${tw.tiles([{label: 'Do wyjaśnienia', value: String(t.flagged), sub: 'zamówienia z sygnałem'}, {label: 'Z wynikiem', value: `${t.resolved} z ${t.flagged}`, sub: t.waiting ? `zostało ${t.waiting}` : 'wszystkie wyjaśnione', cls: t.waiting ? 'warn' : 'ok'},
                {label: 'Kontakt z klientem', value: String(t.contact), sub: 'z notatką'}, {label: 'Zmienione', value: String(t.changed_result), sub: 'zamówienie poprawione'}])}
            ${tw.table([{label: 'Zamówienie'}, {label: 'Wysyłka'}, {label: 'Kartony', num: true}, {label: 'Dlaczego'}, {label: 'Wynik'}], rows)}
            <p class="tw-note">Sygnały : data wysyłki w przeszłości, brak daty albo termin krótszy niż ${D.lead_days} ${tw.plural(D.lead_days, 'dzień', 'dni', 'dni')} ; kartony ponad 2× albo poniżej 0,3× średniej zamówienia klienta z 8 tygodni (gdy klient ma co najmniej 3 zamówienia ; bez domówień i gratisów) ; produkt nieaktywny w zamówieniu. Tylko zamówienia otwarte (bez WDT).</p>`;
    }

    function step3() {
        const t = D.totals;
        if (D.done) return tw.done('Zamówienia dnia sprawdzone · ' + (D.done.at || ''), D.done.summary + ' · zadanie zaliczone');
        const contacts = flagged().filter(o => o.check && o.check.result !== 'ok');
        return `<p class="tw-lead">Gotowe : <b>${esc(D.summary)}</b>.</p>
            ${tw.tiles([{label: 'Zamówienia', value: String(t.orders), sub: `${t.new} ${tw.plural(t.new, 'nowe', 'nowe', 'nowych')} · ${t.changed} ${tw.plural(t.changed, 'zmienione', 'zmienione', 'zmienionych')}`}, {label: 'Wyjaśnione', value: String(t.resolved), sub: `${t.ok} OK bez zmian`, cls: 'ok'},
                {label: 'Kontakt z klientem', value: String(t.contact), sub: 'notatki poniżej'}, {label: 'Kartony', value: nf(t.cartons) + ' kart.', sub: D.label}])}
            ${contacts.length ? `<div class="tw-cap">Kontakty i zmiany</div>` + tw.table([{label: 'Klient'}, {label: 'Zamówienie'}, {label: 'Wynik'}, {label: 'Notatka'}],
                contacts.map(o => ({cells: [`<b>${esc(o.customer)}</b>`, link(o), tw.pill(o.check.label, RES_CLS[o.check.result] ?? ''), esc(o.check.note || '—')]}))) : ''}
            <p class="tw-note">« Zakończ » zalicza zadanie « Zamówienia dnia » na dziś. Nic nie jest wysyłane do klientów.</p>`;
    }

    function render() {
        const top = canFinish() ? reached : Math.min(reached, 2);
        if (step > top) step = top;
        const body = step === 1 ? step1() : (step === 2 ? step2() : step3());
        const next = step === 1 ? {label: 'Dalej : do wyjaśnienia', attr: 'data-od-next'}
            : step === 2 ? {label: 'Dalej : zatwierdź', attr: 'data-od-next', disabled: !canFinish()}
                : (D.done ? null : {label: 'Zakończ', attr: 'data-od-finish', final: true, disabled: busy || !canFinish()});
        app.innerHTML = tw.frame({title: 'Zamówienia dnia', sub: `${D.label} · krok ${step} z 3 · ok. 10 min`, steps: STEPS, step, reached: top, body,
            prev: step > 1 ? 'Wstecz' : null, next, later: !D.done && step < 3});
    }

    tw.bind(app, (n) => { step = n === -1 ? Math.max(1, step - 1) : n; render(); });
    app.addEventListener('input', (e) => {
        const n = e.target.closest('[data-od-note]');
        if (!n) return;
        const id = Number(n.dataset.odNote);
        (draft[id] ??= {result: '', note: ''}).note = n.value;
        const o = D.orders.find(x => x.id === id), d = draft[id];
        const btn = app.querySelector(`[data-od-save="${id}"]`);
        if (btn && o) btn.disabled = !d.result || busy || (o.check && o.check.result === d.result && (o.check.note || '') === d.note);
    });
    app.addEventListener('click', async (e) => {
        if (e.target.closest('[data-od-next]')) { step = Math.min(3, step + 1); reached = Math.max(reached, step); render(); return; }
        const m = e.target.closest('[data-od-sms]');
        if (m && window.orderSms) {
            const o = D.orders.find(x => x.id === Number(m.dataset.odSms));
            if (o) orderSms.open({kinds: o.kind === 'new' ? ['order_confirm', 'order_changed'] : ['order_changed', 'order_confirm'], id: o.id,
                hints: {detail: o.check && o.check.result === 'changed' ? o.check.note : ''}, onSent: reload});
            return;
        }
        const r = e.target.closest('[data-od-res]');
        if (r) { const id = Number(r.dataset.odRes); (draft[id] ??= {result: '', note: ''}).result = r.dataset.v; render(); app.querySelector(`[data-od-note="${id}"]`)?.focus(); return; }
        const s = e.target.closest('[data-od-save]');
        if (s) {
            const id = Number(s.dataset.odSave), d = draft[id];
            if (!d || !d.result || busy) return;
            if (d.result === 'contact' && d.note.trim().length < 3) { at.notify(false, 'Kontakt z klientem : wpisz notatkę (z kim, co ustalono).'); return; }
            busy = true; s.disabled = true;
            const res = await tw.post('/ajax/client-orders/day-check/result', {id_order: id, result: d.result, note: d.note});
            busy = false;
            if (res.ok) { D = res.data; delete draft[id]; if (canFinish()) reached = Math.max(reached, 2); }
            render();
            return;
        }
        if (e.target.closest('[data-od-finish]')) {
            if (busy) return;
            busy = true; render();
            const res = await tw.post('/ajax/client-orders/day-check/finish', {});
            busy = false;
            if (res.ok) { at.notify(true, res.message); D = res.data; }
            render();
        }
    });
    render();
})();
