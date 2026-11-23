// [382] Powiadomienia o dostawie (Logistyka — codziennie) : 1. klienci dnia wysyłki (i następnego dnia roboczego, gdy automat
// wysyła powiadomienie dzień wcześniej) z powiadomieniem, błędem e-mail / SMS albo bez kontaktu, 2. une décision par client
// signalé (wysłane ponownie — vérifié côté serveur, kontakt telefoniczny, bez powiadomienia — uzasadnione), 3. récapitulatif et
// validation → la tâche du poste se valide seule. Cet écran n'envoie rien (ni e-mail ni SMS) : le (re)nvoi se fait dans
// Dzień wysyłki › Powiadomienia klientów (liens par jour).
(() => {
    const app = document.getElementById('dnApp');
    if (!app || !window.tw) return;
    const {esc} = tw;
    let D = JSON.parse(document.getElementById('dnBoot').textContent);
    let step = 1, reached = 1, busy = false;
    // Brouillons de l'étape 2 (décision choisie, note) par client et jour, gardés entre deux rendus.
    const draft = {};
    const STEPS = ['Przegląd', 'Do wyjaśnienia', 'Zatwierdź'];
    const DEC_CLS = {resent: 'ok', phone: 'warn', justified: ''};
    const SIG_CLS = {mail_failed: 'bad', sms_failed: 'bad', no_contact: 'bad', not_sent: 'warn'};
    const SHORT = {mail_failed: 'błąd e-mail', sms_failed: 'błąd SMS', no_contact: 'bez kontaktu', not_sent: 'brak powiadomienia'};
    const kl = (n) => tw.plural(n, 'klient', 'klientów', 'klientów');
    const canFinish = () => !!D.can_finish;
    const link = (l, txt) => l ? `<a class="tw-link" href="${at.root}${esc(l.href)}" target="_blank" rel="noopener" style="padding:0"><i class="bi bi-chat-dots"></i> ${esc(txt || l.label)}</a>` : '';
    const sigs = (c) => c.signals.map(s => tw.pill(SHORT[s.code] || s.code, SIG_CLS[s.code] || '')).join(' ') || '<span class="tw-green">OK</span>';
    const status = (c) => c.waiting ? tw.pill(c.decision && c.decision.stale ? 'nowy błąd' : 'do wyjaśnienia', 'bad') : (c.decision ? tw.pill(c.decision.label, DEC_CLS[c.decision.code] || '') : '');
    const notices = (c) => c.notices.length ? c.notices.map(n => `<small class="${n.failed ? 'tw-red' : (n.ok ? '' : 'tw-red')}">${esc(n.channel_label)} ${esc(n.at)} : ${esc(n.status_label)}${n.error ? ' — ' + esc(n.error) : ''}${n.automatic ? ' · automat' : ''}</small>`).join('')
        : '<small style="color:#999">brak</small>';
    const transport = (c) => `<small>${c.tour ? esc(c.tour) + (c.stop ? ` · ${c.stop}/${c.stops}` : '') + ' · ' : ''}${esc(c.vehicle || '— brak pojazdu')}${c.departure ? ' · wyjazd ' + esc(c.departure) : ''}</small>`
        + (c.driver ? `<small>${esc(c.driver)}</small>` : '');
    const contact = (c) => (c.email ? `<small>${esc(c.email)}</small>` : '<small class="tw-red">brak e-maila</small>') + (c.phone ? `<small>${esc(c.phone)}</small>` : '<small class="tw-red">brak telefonu</small>');
    const nodata = () => tw.alert(`<b>${esc(D.sources.notices.note || 'brak danych')}</b> — nie można sprawdzić powiadomień (tabela n_delivery_notice).`, 'warn', 'bi-database-x');

    function step1() {
        const t = D.totals;
        const tiles = D.days.map(d => ({label: `${d.today ? 'Dziś' : 'Następny dzień'} · ${d.label}`, value: d.customers ? `${d.notified} / ${d.customers}` : '—',
            sub: d.customers ? 'powiadomionych (jak pasek Dzień wysyłki)' : 'brak dostaw', cls: d.customers && d.notified < d.customers ? 'warn' : 'ok'}));
        tiles.push({label: 'Błąd e-mail / SMS', value: `${t.by_code.mail_failed} / ${t.by_code.sms_failed}`, sub: 'próby dnia z historii powiadomień', cls: t.by_code.mail_failed + t.by_code.sms_failed ? 'bad' : 'ok'},
            {label: 'Bez kontaktu', value: String(t.by_code.no_contact), sub: 'brak e-maila i telefonu', cls: t.by_code.no_contact ? 'warn' : 'ok'},
            {label: 'Do wyjaśnienia', value: String(t.review), sub: t.review ? `${t.resolved} z ${t.review} z decyzją` : 'brak sygnałów', cls: t.waiting ? 'warn' : 'ok'});
        const head = [{label: 'Dzień'}, {label: 'Klient'}, {label: 'Trasa · pojazd · wyjazd'}, {label: 'Kontakt'}, {label: 'Powiadomienia dnia'}, {label: 'Sygnały'}, {label: ''}];
        const rows = D.customers.map(c => ({cls: c.waiting ? 'hl' : '', cells: [esc(c.day_label), `<b>${esc(c.name)}</b>${c.town ? `<small>${esc(c.town)}</small>` : ''}`, transport(c), contact(c),
            notices(c), sigs(c), status(c)]}));
        return `<p class="tw-lead">Klienci dnia wysyłki z Planu dnia (ta sama lista co pasek « Dzień wysyłki » i « Powiadomienia klientów ») i ich powiadomienia o dostawie (e-mail, SMS).
                Sprawdzane : <b>${D.days.map(d => esc(d.label)).join(' i ')}</b> — ${esc(D.window_reason)}.
                Klient z błędem e-mail / SMS, bez powiadomienia albo bez kontaktu trafia do kroku 2.</p>
            ${D.available ? '' : nodata()}
            ${tw.tiles(tiles)}
            <div class="tw-cap">Klienci (${D.customers.length}) ${D.days.map(d => link(d.link)).join(' ')}</div>
            ${tw.table(head, rows, 'Brak dostaw w sprawdzanych dniach.')}
            <p class="tw-note">Powiadomiony : jak pasek Dzień wysyłki — powiadomienie dnia bez błędu (e-mail w kolejce albo wysłany, SMS wysłany). Błąd : próba dnia ze statusem « błąd »
                albo e-mail odrzucony przez kolejkę — zostaje do decyzji, także po udanym ponownym wysłaniu. SMS « zapisany (bez wysyłki) » : klient nic nie dostał. Kontakt jak w « Powiadomienia klientów » : e-mail główny albo handlowy, telefon główny albo dodatkowy.
                Asystent niczego nie wysyła.</p>`;
    }

    function decisionCell(c) {
        const r = draft[c.key] ?? (draft[c.key] = {decision: c.decision && !c.decision.stale ? c.decision.code : '', note: c.decision ? (c.decision.note || '') : ''});
        const seg = Object.entries(D.decisions).map(([k, l]) => `<button type="button" class="${r.decision === k ? 'on' : ''}" data-dn-dec="${esc(c.key)}" data-v="${k}">${esc(l)}</button>`).join('');
        const need = D.note_required.includes(r.decision);
        const ph = r.decision === 'phone' ? 'Notatka (wymagana) : z kim, kiedy, co przekazano' : (r.decision === 'justified' ? 'Notatka (wymagana) : dlaczego bez powiadomienia' : 'Notatka (opcjonalnie)');
        const resend = r.decision === 'resent' ? (c.resend_notice ? `<small class="tw-green"><i class="bi bi-check-circle"></i> ${esc(c.resend_notice.channel_label)} ${esc(c.resend_notice.at)} do ${esc(c.resend_notice.recipient)} — bez błędu</small>`
            : `<small class="tw-red">Brak udanego powiadomienia ${c.notices.length ? 'po błędzie' : ''} — wyślij je w <a href="${at.root}${esc(c.link.href)}" target="_blank" rel="noopener">« Powiadomienia klientów »</a> i wróć tutaj (dane odświeżą się same).</small>`) : '';
        const saved = c.decision ? `<small>${tw.pill(c.decision.label, DEC_CLS[c.decision.code] || '')} ${esc(c.decision.at)}${c.decision.by ? ' · ' + esc(c.decision.by) : ''}${c.decision.stale ? ' · <b class="tw-red">nowy błąd po tej decyzji</b>' : ''}</small>` : '';
        const dirty = !c.decision || c.decision.stale || c.decision.code !== r.decision || (c.decision.note || '') !== r.note;
        return `<div style="display:grid;gap:6px;min-width:420px"><div class="tw-seg" style="justify-self:start">${seg}</div>
            <div style="display:flex;gap:6px"><input class="form-control form-control-sm" data-dn-note="${esc(c.key)}" maxlength="500" value="${esc(r.note)}" placeholder="${esc(ph)}" style="min-height:36px;border-radius:9px">
            <button type="button" class="tw-btn ghost sm" data-dn-save="${esc(c.key)}" ${!r.decision || !dirty || busy || (need && r.note.trim().length < 3) || (r.decision === 'resent' && !c.resend_notice) ? 'disabled' : ''}>Zapisz</button></div>${resend}${saved}</div>`;
    }

    function step2() {
        const list = D.review, t = D.totals;
        if (!list.length) return (D.available ? '' : nodata()) + tw.alert('Nic do wyjaśnienia : każdy klient sprawdzanych dni ma powiadomienie bez błędu.', 'ok', 'bi-check-circle');
        const rows = list.map(c => ({cls: c.waiting ? 'hl' : '', cells: [
            `<b>${esc(c.name)}</b><small>${esc(c.day_label)}</small>${contact(c)}`,
            notices(c) + `<small>${link(c.link, 'Wyślij / wyślij ponownie')}</small>`,
            c.signals.length ? c.signals.map(s => `<div style="display:flex;gap:6px;align-items:baseline;margin:1px 0"><i class="bi bi-exclamation-circle ${SIG_CLS[s.code] === 'bad' ? 'tw-red' : ''}"></i><span><b>${esc(s.label)}</b> : ${esc(s.detail)}</span></div>`).join('') : '<span class="tw-green">Sygnał zniknął</span>',
            decisionCell(c)]}));
        return `<p class="tw-lead">Dla każdego klienta zapisz decyzję : <b>Wysłane ponownie</b> (powiadomienie wysłane jeszcze raz w « Powiadomienia klientów » — asystent sprawdza, że doszło bez błędu),
                <b>Kontakt telefoniczny</b> (notatka) albo <b>Bez powiadomienia — uzasadnione</b> (notatka). Krok 3 otworzy się, gdy wszyscy mają decyzję. Asystent niczego nie wysyła.</p>
            ${tw.tiles([{label: 'Do wyjaśnienia', value: String(t.review), sub: `${t.by_code.mail_failed + t.by_code.sms_failed} ${tw.plural(t.by_code.mail_failed + t.by_code.sms_failed, 'błąd', 'błędy', 'błędów')} · ${t.by_code.not_sent} brak · ${t.by_code.no_contact} bez kontaktu`},
                {label: 'Z decyzją', value: `${t.resolved} z ${t.review}`, sub: t.waiting ? `zostało ${t.waiting}` : 'wszyscy wyjaśnieni', cls: t.waiting ? 'warn' : 'ok'},
                {label: 'Wysłane ponownie', value: String(t.resent), sub: 'sprawdzone w historii'},
                {label: 'Telefon / uzasadnione', value: `${t.phone} / ${t.justified}`, sub: 'z notatką'}])}
            ${tw.table([{label: 'Klient'}, {label: 'Powiadomienia dnia'}, {label: 'Problem'}, {label: 'Decyzja'}], rows)}
            <p class="tw-note">Klient bez powiadomienia, któremu wyślesz je teraz, sam zniknie z listy (jak w pasku Dzień wysyłki). Błąd zostaje do decyzji ; nowy błąd po « Wysłane ponownie » otwiera klienta jeszcze raz.</p>`;
    }

    function step3() {
        const t = D.totals;
        if (D.done) return tw.done('Powiadomienia o dostawie sprawdzone · ' + (D.done.at || ''), D.done.summary + ' · zadanie zaliczone');
        const decided = D.review.filter(c => c.decision && !c.decision.stale);
        return `<p class="tw-lead">Gotowe : <b>${esc(D.summary)}</b>.</p>
            ${D.available ? '' : nodata()}
            ${tw.tiles([...D.days.map(d => ({label: d.label, value: d.customers ? `${d.notified} / ${d.customers}` : '—', sub: 'powiadomionych', cls: d.customers && d.notified < d.customers ? 'warn' : 'ok'})),
                {label: 'Decyzje', value: `${t.resolved} z ${t.review}`, sub: `${t.resent} wysłane ponownie · ${t.phone} ${tw.plural(t.phone, 'telefon', 'telefony', 'telefonów')} · ${t.justified} uzasadnione`, cls: 'ok'}])}
            ${decided.length ? `<div class="tw-cap">Decyzje</div>` + tw.table([{label: 'Dzień'}, {label: 'Klient'}, {label: 'Sygnały'}, {label: 'Decyzja'}, {label: 'Notatka'}],
                decided.map(c => ({cells: [esc(c.day_label), esc(c.name), sigs(c), tw.pill(c.decision.label, DEC_CLS[c.decision.code] || ''), esc(c.decision.note || '—')]}))) : ''}
            <p class="tw-note">« Zakończ » zalicza zadanie « Powiadomienia o dostawie » na dziś (Logistyka). Nic nie jest wysyłane do klientów.</p>`;
    }

    function render() {
        const top = canFinish() ? reached : Math.min(reached, 2);
        if (step > top) step = top;
        const body = step === 1 ? step1() : (step === 2 ? step2() : step3());
        const next = step === 1 ? {label: 'Dalej : do wyjaśnienia', attr: 'data-dn-next'}
            : step === 2 ? {label: 'Dalej : zatwierdź', attr: 'data-dn-next', disabled: !canFinish()}
                : (D.done ? null : {label: 'Zakończ', attr: 'data-dn-finish', final: true, disabled: busy || !canFinish()});
        app.innerHTML = tw.frame({title: 'Powiadomienia o dostawie', sub: `${D.days.map(d => d.label).join(' + ')} · ${D.totals.customers} ${kl(D.totals.customers)} · krok ${step} z 3 · ok. 5 min`, steps: STEPS, step, reached: top, body,
            prev: step > 1 ? 'Wstecz' : null, next, later: !D.done && step < 3});
    }

    tw.bind(app, (n) => { step = n === -1 ? Math.max(1, step - 1) : n; render(); });
    app.addEventListener('input', (e) => {
        const n = e.target.closest('[data-dn-note]');
        if (!n) return;
        const key = n.dataset.dnNote;
        (draft[key] ??= {decision: '', note: ''}).note = n.value;
        const c = D.review.find(x => x.key === key), r = draft[key];
        const btn = app.querySelector(`[data-dn-save="${CSS.escape(key)}"]`);
        const need = D.note_required.includes(r.decision);
        if (btn && c) btn.disabled = !r.decision || busy || (need && r.note.trim().length < 3) || (r.decision === 'resent' && !c.resend_notice)
            || (c.decision && !c.decision.stale && c.decision.code === r.decision && (c.decision.note || '') === r.note);
    });
    app.addEventListener('click', async (e) => {
        if (e.target.closest('[data-dn-next]')) { step = Math.min(3, step + 1); reached = Math.max(reached, step); render(); return; }
        const r = e.target.closest('[data-dn-dec]');
        if (r) { const key = r.dataset.dnDec; (draft[key] ??= {decision: '', note: ''}).decision = r.dataset.v; render(); app.querySelector(`[data-dn-note="${CSS.escape(key)}"]`)?.focus(); return; }
        const s = e.target.closest('[data-dn-save]');
        if (s) {
            const key = s.dataset.dnSave, d = draft[key], c = D.review.find(x => x.key === key);
            if (!d || !d.decision || !c || busy) return;
            busy = true; s.disabled = true;
            const res = await tw.post('/ajax/logistics/notices-check/decision', {ship_date: c.day, id_customer: c.id, decision: d.decision, note: d.note});
            busy = false;
            if (res.ok) { D = res.data; delete draft[key]; if (canFinish()) reached = Math.max(reached, 2); }
            render();
            return;
        }
        if (e.target.closest('[data-dn-finish]')) {
            if (busy) return;
            busy = true; render();
            const res = await tw.post('/ajax/logistics/notices-check/finish', {});
            busy = false;
            if (res.ok) { at.notify(true, res.message); D = res.data; }
            render();
        }
    });
    // Retour depuis « Powiadomienia klientów » (autre onglet) : données relues, sans rien envoyer.
    document.addEventListener('visibilitychange', async () => {
        if (document.visibilityState !== 'visible' || busy || D.done) return;
        const res = await at.send('/ajax/logistics/notices-check', 'GET');
        if (res && res.ok && res.data) { D = res.data; render(); }
    });
    render();
})();
