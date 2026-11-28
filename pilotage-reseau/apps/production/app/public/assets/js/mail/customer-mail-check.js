// [383] Poczta od klientów (Administracja — codziennie) : 1. skrzynka z Poczty (ta sama, którą osoba czyta w Poczcie) i stan
// jej synchronizacji, e-maile od klientów bez odpowiedzi po terminie, wg klienta, 2. une décision par e-mail signalé
// (odpowiedziane — vérifié côté serveur, bez odpowiedzi — nie wymaga, przekazane), 3. récapitulatif et validation → la tâche
// du poste se valide seule. Cet écran n'envoie rien et ne touche pas aux messages : la réponse se fait dans Poczta (lien par
// message). Aucun contenu d'e-mail affiché au-delà de ce que montre la liste de Poczta (expéditeur, sujet, date, client).
(() => {
    const app = document.getElementById('cmApp');
    if (!app || !window.tw) return;
    const {esc} = tw;
    let D = JSON.parse(document.getElementById('cmBoot').textContent);
    if (!D) { app.innerHTML = tw.alert('Brak dostępu do poczty.', 'warn', 'bi-shield-lock'); return; }
    let step = 1, reached = 1, busy = false;
    // Brouillons de l'étape 2 (décision, note, personne, poste) par message, gardés entre deux rendus.
    const draft = {};
    const STEPS = ['Przegląd', 'Do wyjaśnienia', 'Zatwierdź'];
    const DEC_CLS = {answered: 'ok', not_needed: '', forwarded: 'warn'};
    const SIG_CLS = {unanswered: 'bad', late_reply: 'warn'};
    const SHORT = {unanswered: 'bez odpowiedzi', late_reply: 'odpowiedź po terminie'};
    const SYNC_CLS = {ok: 'ok', stale: 'warn', error: 'bad', never: 'bad', stopped: 'bad', none: 'bad'};
    const em = (n) => tw.plural(n, 'e-mail', 'e-maile', 'e-maili');
    const hrs = (h) => h === null || h === undefined ? '—' : tw.nf(h, Number.isInteger(h) ? 0 : 1) + ' h';
    const canFinish = () => !!D.can_finish;
    const sync = () => D.mailbox.sync;
    const link = (l, txt) => l ? `<a class="tw-link" href="${at.root}${esc(l.href)}" target="_blank" rel="noopener" style="padding:0"><i class="bi bi-envelope-open"></i> ${esc(txt || l.label)}</a>` : '';
    const sigs = (m) => m.signals.map(s => tw.pill(SHORT[s.code] || s.code, SIG_CLS[s.code] || '')).join(' ') || (m.replied ? '<span class="tw-green">odpowiedziany</span>' : '<small>w terminie</small>');
    const status = (m) => m.waiting ? tw.pill(m.decision && m.decision.stale ? 'brak odpowiedzi' : 'do wyjaśnienia', 'bad') : (m.decision ? tw.pill(m.decision.label, DEC_CLS[m.decision.code] || '') : '');
    const reply = (m) => m.reply ? `<small class="tw-green">${esc(m.reply.label)}${m.reply.at_label ? ' · ' + esc(m.reply.at_label) : ''}${m.reply.mode_label ? ' · ' + esc(m.reply.mode_label) : ''}</small>`
        : `<small class="tw-red">brak odpowiedzi · termin ${esc(m.deadline_label)}</small>`;
    const mail = (m) => `<b>${esc(m.subject)}</b><small>${esc(m.from)}${m.from !== m.from_email ? ' &lt;' + esc(m.from_email) + '&gt;' : ''}</small><small>${esc(m.received_label)}${m.seen ? '' : ' · <b>nieprzeczytany</b>'}</small>`;
    const syncAlert = () => sync().reliable ? '' : tw.alert(`<b>${esc(sync().label)}</b> — ${esc(sync().detail)} ${sync().readable ? 'Wynik może być niepełny : brak e-maili na liście nie znaczy, że klienci nie pisali.' : ''}
        <a href="${at.root}/mail/settings" target="_blank" rel="noopener">Ustawienia skrzynki</a>`, sync().readable ? 'warn' : 'bad', 'bi-cloud-slash');

    function step1() {
        const t = D.totals, s = sync();
        const tiles = [{label: 'Skrzynka', value: D.mailbox.email || '—', sub: s.label + (s.last_sync_at ? ' · ' + s.last_sync_at.slice(11, 16) : ''), cls: SYNC_CLS[s.code]},
            {label: `E-maile od klientów (${D.lookback_days} dni)`, value: s.readable && s.code !== 'never' ? String(t.mails) : '—', sub: s.readable ? `${t.customers} ${tw.plural(t.customers, 'klient', 'klientów', 'klientów')} · ${t.unread} nieprzeczytanych` : 'brak danych'},
            {label: `Bez odpowiedzi po ${D.hours} h`, value: s.readable ? String(t.overdue) : '—', sub: `${t.unanswered} bez odpowiedzi w ogóle`, cls: t.overdue ? 'bad' : 'ok'},
            {label: 'Śr. czas odpowiedzi dziś', value: hrs(t.avg_reply_hours), sub: t.replies_today ? `${t.replies_today} ${tw.plural(t.replies_today, 'odpowiedź', 'odpowiedzi', 'odpowiedzi')} z datą` : 'brak odpowiedzi z datą dziś'},
            {label: 'Do wyjaśnienia', value: String(t.review), sub: t.review ? `${t.resolved} z ${t.review} z decyzją` : 'brak sygnałów', cls: t.waiting ? 'warn' : 'ok'}];
        const cust = D.customers.map(c => ({cls: c.waiting ? 'hl' : '', cells: [`<b>${esc(c.name)}</b>`, tw.nf(c.mails), tw.nf(c.unread), tw.nf(c.replied), tw.nf(c.unanswered),
            c.overdue ? `<b class="tw-red">${tw.nf(c.overdue)}</b>` : '0', c.oldest_hours !== null ? hrs(c.oldest_hours) : '—']}));
        const rows = D.items.map(m => ({cls: m.waiting ? 'hl' : '', cells: [esc(m.customer), mail(m), reply(m), sigs(m), status(m), link(m.link)]}));
        return `<p class="tw-lead">E-maile od klientów w skrzynce <b>${esc(D.mailbox.email || '—')}</b> — tej samej, którą ${D.mailbox.own ? 'czytasz' : 'czyta osoba ze stanowiska'} w Poczcie — z ostatnich ${D.lookback_days} dni.
                Termin odpowiedzi : <b>${esc(D.hours_rule)}</b>. E-mail bez odpowiedzi po terminie (albo z odpowiedzią po terminie wysłaną dziś) trafia do kroku 2.</p>
            ${syncAlert()}
            ${tw.tiles(tiles)}
            ${s.readable ? `<div class="tw-cap">Wg klienta (${D.customers.length})</div>
            ${tw.table([{label: 'Klient'}, {label: 'E-maile', num: true}, {label: 'Nieprzeczytane', num: true}, {label: 'Z odpowiedzią', num: true}, {label: 'Bez odpowiedzi', num: true}, {label: 'Po terminie', num: true}, {label: 'Najstarszy bez odp.', num: true}], cust,
                s.code === 'never' ? 'Brak danych — skrzynka nie była jeszcze pobrana.' : 'Brak e-maili od klientów w sprawdzanych dniach.')}
            <div class="tw-cap">E-maile (${D.items.length})</div>
            ${tw.table([{label: 'Klient'}, {label: 'Wiadomość'}, {label: 'Odpowiedź'}, {label: 'Sygnał'}, {label: ''}, {label: ''}], rows, 'Brak e-maili od klientów.')}` : ''}
            <p class="tw-note">Odpowiedziany : oznaczony w skrzynce jako odpowiedziany albo z odpowiedzią w wątku (wysłaną z Poczty ERP albo w folderze Wysłane). Klient : nadawca rozpoznany po adresie z karty klienta.
                ${t.forwarded_open ? `<b>${t.forwarded_open}</b> ${em(t.forwarded_open)} przekazanych wcześniej nadal bez odpowiedzi — sprawdź u osoby, której je przekazano.` : ''}
                Gdy synchronizacja nie działa, lista jest niepełna. Asystent niczego nie wysyła i nie oznacza wiadomości jako przeczytanych.</p>`;
    }

    function decisionCell(m) {
        const r = draft[m.id] ?? (draft[m.id] = {decision: m.decision && !m.decision.stale ? m.decision.code : '', note: m.decision ? (m.decision.note || '') : '',
            id_responsible: m.decision && m.decision.id_responsible ? String(m.decision.id_responsible) : '', id_position: m.decision && m.decision.id_position ? String(m.decision.id_position) : ''});
        const seg = Object.entries(D.decisions).map(([k, l]) => `<button type="button" class="${r.decision === k ? 'on' : ''}" data-cm-dec="${m.id}" data-v="${k}">${esc(l)}</button>`).join('');
        const need = D.note_required.includes(r.decision);
        const ph = r.decision === 'forwarded' ? 'Notatka (wymagana) : komu, kiedy, co dalej' : (r.decision === 'not_needed' ? 'Notatka (wymagana) : dlaczego nie wymaga odpowiedzi' : 'Notatka (opcjonalnie)');
        const fwd = r.decision === 'forwarded' ? `<div style="display:flex;gap:6px">
            <select class="form-select form-select-sm" data-cm-sel="id_responsible" data-m="${m.id}"><option value="">Osoba (opcjonalnie)</option>${D.users.map(u => `<option value="${u.id}" ${String(u.id) === r.id_responsible ? 'selected' : ''}>${esc(u.name)}</option>`).join('')}</select>
            <select class="form-select form-select-sm" data-cm-sel="id_position" data-m="${m.id}"><option value="">Stanowisko (opcjonalnie)</option>${D.positions.map(p => `<option value="${p.id}" ${String(p.id) === r.id_position ? 'selected' : ''}>${esc(p.name)}</option>`).join('')}</select></div>` : '';
        const ans = r.decision === 'answered' ? (m.replied ? `<small class="tw-green"><i class="bi bi-check-circle"></i> ${esc(m.reply.label)}${m.reply.at_label ? ' · ' + esc(m.reply.at_label) : ''}</small>`
            : `<small class="tw-red">Wiadomość nie jest jeszcze odpowiedziana — odpowiedz w <a href="${at.root}${esc(m.link.href)}" target="_blank" rel="noopener">Poczcie</a> i wróć tutaj (dane odświeżą się same).</small>`) : '';
        const d = m.decision;
        const saved = d ? `<small>${tw.pill(d.label, DEC_CLS[d.code] || '')} ${esc(d.at)}${d.by ? ' · ' + esc(d.by) : ''}${d.responsible ? ' · ' + esc(d.responsible) : ''}${d.position ? ' · ' + esc(d.position) : ''}${d.stale ? ' · <b class="tw-red">brak już odpowiedzi</b>' : ''}</small>` : '';
        const dirty = !d || d.stale || d.code !== r.decision || (d.note || '') !== r.note || String(d.id_responsible || '') !== r.id_responsible || String(d.id_position || '') !== r.id_position;
        return `<div style="display:grid;gap:6px;min-width:440px"><div class="tw-seg" style="justify-self:start">${seg}</div>${fwd}
            <div style="display:flex;gap:6px"><input class="form-control form-control-sm" data-cm-note="${m.id}" maxlength="500" value="${esc(r.note)}" placeholder="${esc(ph)}" style="min-height:36px;border-radius:9px">
            <button type="button" class="tw-btn ghost sm" data-cm-save="${m.id}" ${!r.decision || !dirty || busy || (need && r.note.trim().length < 3) || (r.decision === 'answered' && !m.replied) ? 'disabled' : ''}>Zapisz</button></div>${ans}${saved}</div>`;
    }

    function step2() {
        const list = D.review, t = D.totals;
        if (!sync().readable) return syncAlert();
        if (!list.length) return syncAlert() + tw.alert(`Nic do wyjaśnienia : każdy e-mail od klienta ma odpowiedź w terminie (${esc(D.hours_rule)})${sync().reliable ? '' : ' — przy niepełnej synchronizacji'}.`, sync().reliable ? 'ok' : 'warn', 'bi-check-circle');
        const rows = list.map(m => ({cls: m.waiting ? 'hl' : '', cells: [
            `<small><b>${esc(m.customer)}</b></small>` + mail(m) + `<small>${link(m.link)}</small>`,
            m.signals.length ? m.signals.map(s => `<div style="display:flex;gap:6px;align-items:baseline;margin:1px 0"><i class="bi bi-exclamation-circle ${SIG_CLS[s.code] === 'bad' ? 'tw-red' : ''}"></i><span><b>${esc(s.label)}</b> : ${esc(s.detail)}</span></div>`).join('') + reply(m)
                : '<span class="tw-green">Sygnał zniknął</span>' + reply(m),
            decisionCell(m)]}));
        return `<p class="tw-lead">Dla każdego e-maila zapisz decyzję : <b>Odpowiedziane</b> (odpowiedź wysłana w Poczcie — asystent sprawdza, że wiadomość jest odpowiedziana),
                <b>Bez odpowiedzi — nie wymaga</b> (notatka) albo <b>Przekazane</b> (notatka, osoba i stanowisko opcjonalnie). Krok 3 otworzy się, gdy wszystkie mają decyzję. Asystent niczego nie wysyła.</p>
            ${syncAlert()}
            ${tw.tiles([{label: 'Do wyjaśnienia', value: String(t.review), sub: `${t.overdue} bez odpowiedzi · ${t.late_reply} po terminie`},
                {label: 'Z decyzją', value: `${t.resolved} z ${t.review}`, sub: t.waiting ? `zostało ${t.waiting}` : 'wszystkie wyjaśnione', cls: t.waiting ? 'warn' : 'ok'},
                {label: 'Odpowiedziane', value: String(t.answered), sub: 'sprawdzone w skrzynce'},
                {label: 'Nie wymaga / przekazane', value: `${t.not_needed} / ${t.forwarded}`, sub: 'z notatką'}])}
            ${tw.table([{label: 'Wiadomość'}, {label: 'Problem'}, {label: 'Decyzja'}], rows)}
            <p class="tw-note">E-mail, na który odpowiesz w Poczcie, zostaje na liście z odpowiedzią po terminie — zapisz « Odpowiedziane ». E-maile wyjaśnione w poprzednich dniach (${t.earlier}) nie wracają.</p>`;
    }

    function step3() {
        const t = D.totals;
        if (D.done) return tw.done('Poczta od klientów sprawdzona · ' + (D.done.at || ''), D.done.summary + ' · zadanie zaliczone');
        const decided = D.review.filter(m => m.decision && !m.decision.stale);
        return `<p class="tw-lead">Gotowe : <b>${esc(D.summary)}</b>.</p>
            ${syncAlert()}
            ${tw.tiles([{label: 'Skrzynka', value: D.mailbox.email || '—', sub: sync().label, cls: SYNC_CLS[sync().code]},
                {label: 'Bez odpowiedzi po terminie', value: String(t.overdue), sub: `${t.mails} ${em(t.mails)} od klientów`, cls: t.overdue ? 'warn' : 'ok'},
                {label: 'Śr. czas odpowiedzi dziś', value: hrs(t.avg_reply_hours), sub: t.replies_today ? `${t.replies_today} z datą` : 'nie do obliczenia'},
                {label: 'Decyzje', value: `${t.resolved} z ${t.review}`, sub: `${t.answered} odpowiedziane · ${t.not_needed} nie wymaga · ${t.forwarded} przekazane`, cls: 'ok'}])}
            ${decided.length ? `<div class="tw-cap">Decyzje</div>` + tw.table([{label: 'Klient'}, {label: 'Wiadomość'}, {label: 'Sygnał'}, {label: 'Decyzja'}, {label: 'Notatka'}],
                decided.map(m => ({cells: [esc(m.customer), esc(m.subject), sigs(m), tw.pill(m.decision.label, DEC_CLS[m.decision.code] || ''),
                    esc([m.decision.note, m.decision.responsible, m.decision.position].filter(Boolean).join(' · ') || '—')]}))) : ''}
            <p class="tw-note">« Zakończ » zalicza zadanie « Poczta od klientów » na dziś (Administracja). Nic nie jest wysyłane, żadna wiadomość nie jest zmieniana.</p>`;
    }

    function render() {
        const top = canFinish() ? reached : Math.min(reached, 2);
        if (step > top) step = top;
        const body = step === 1 ? step1() : (step === 2 ? step2() : step3());
        const next = step === 1 ? {label: 'Dalej : do wyjaśnienia', attr: 'data-cm-next'}
            : step === 2 ? {label: 'Dalej : zatwierdź', attr: 'data-cm-next', disabled: !canFinish()}
                : (D.done ? null : {label: 'Zakończ', attr: 'data-cm-finish', final: true, disabled: busy || !canFinish()});
        app.innerHTML = tw.frame({title: 'Poczta od klientów', sub: `${D.label} · ${D.mailbox.email || 'brak skrzynki'} · ${D.totals.mails} ${em(D.totals.mails)} · krok ${step} z 3 · ok. 5 min`, steps: STEPS, step, reached: top, body,
            prev: step > 1 ? 'Wstecz' : null, next, later: !D.done && step < 3});
    }

    const saveBtnState = (id) => {
        const m = D.review.find(x => x.id === id), r = draft[id];
        const btn = app.querySelector(`[data-cm-save="${id}"]`);
        if (!btn || !m || !r) return;
        const d = m.decision, need = D.note_required.includes(r.decision);
        btn.disabled = !r.decision || busy || (need && r.note.trim().length < 3) || (r.decision === 'answered' && !m.replied)
            || (d && !d.stale && d.code === r.decision && (d.note || '') === r.note && String(d.id_responsible || '') === r.id_responsible && String(d.id_position || '') === r.id_position);
    };
    tw.bind(app, (n) => { step = n === -1 ? Math.max(1, step - 1) : n; render(); });
    app.addEventListener('input', (e) => {
        const n = e.target.closest('[data-cm-note]');
        if (!n) return;
        const id = Number(n.dataset.cmNote);
        (draft[id] ??= {decision: '', note: '', id_responsible: '', id_position: ''}).note = n.value;
        saveBtnState(id);
    });
    app.addEventListener('change', (e) => {
        const s = e.target.closest('[data-cm-sel]');
        if (!s) return;
        const id = Number(s.dataset.m);
        (draft[id] ??= {decision: '', note: '', id_responsible: '', id_position: ''})[s.dataset.cmSel] = s.value;
        saveBtnState(id);
    });
    app.addEventListener('click', async (e) => {
        if (e.target.closest('[data-cm-next]')) { step = Math.min(3, step + 1); reached = Math.max(reached, step); render(); return; }
        const r = e.target.closest('[data-cm-dec]');
        if (r) { const id = Number(r.dataset.cmDec); (draft[id] ??= {decision: '', note: '', id_responsible: '', id_position: ''}).decision = r.dataset.v; render(); app.querySelector(`[data-cm-note="${id}"]`)?.focus(); return; }
        const s = e.target.closest('[data-cm-save]');
        if (s) {
            const id = Number(s.dataset.cmSave), d = draft[id], m = D.review.find(x => x.id === id);
            if (!d || !d.decision || !m || busy) return;
            busy = true; s.disabled = true;
            const body = {id_mail: id, decision: d.decision, note: d.note};
            if (d.decision === 'forwarded') { body.id_responsible = d.id_responsible ? Number(d.id_responsible) : null; body.id_position = d.id_position ? Number(d.id_position) : null; }
            const res = await tw.post('/ajax/mail/customer-check/decision', body);
            busy = false;
            if (res.ok) { D = res.data; delete draft[id]; if (canFinish()) reached = Math.max(reached, 2); }
            render();
            return;
        }
        if (e.target.closest('[data-cm-finish]')) {
            if (busy) return;
            busy = true; render();
            const res = await tw.post('/ajax/mail/customer-check/finish', {});
            busy = false;
            if (res.ok) { at.notify(true, res.message); D = res.data; }
            render();
        }
    });
    // Retour depuis Poczta (autre onglet, réponse envoyée) : données relues, sans rien envoyer ni marquer.
    document.addEventListener('visibilitychange', async () => {
        if (document.visibilityState !== 'visible' || busy || D.done) return;
        const res = await at.send('/ajax/mail/customer-check', 'GET');
        if (res && res.ok && res.data) { D = res.data; render(); }
    });
    render();
})();
