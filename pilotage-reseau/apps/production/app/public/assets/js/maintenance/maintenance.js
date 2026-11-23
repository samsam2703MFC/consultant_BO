// [405] Serwisy i przeglądy maszyn (Kierownik produkcji — co miesiąc, w każdej chwili) : 1. registres de l'assistant (urządzenia,
// przeglądy / serwisy / pomiary avec terme, zgłoszenia) et formulaires d'ajout, 2. une décision par élément signalé (przegląd
// échu ou proche, zgłoszenie en retard, awaria de la tablette sans zgłoszenie, machine hors registre) avec l'action qui le règle
// (wykonanie, stan zgłoszenia, nouvel zgłoszenie, rejestr), 3. récapitulatif et validation → la tâche du mois se valide seule.
// Rien n'est commandé ni envoyé : appeler le serwisant reste un geste humain.
(() => {
    const app = document.getElementById('mtApp');
    if (!app || !window.tw) return;
    const {esc} = tw;
    let D = JSON.parse(document.getElementById('mtBoot').textContent);
    let step = 1, reached = 1, busy = false;
    // Brouillons : décision par élément, action par élément, formulaires des registres (gardés entre deux rendus).
    const draft = {}, act = {};
    const form = {eq: {name: '', kind: 'machine', id_workstation: '', location: ''},
        ck: {id_equipment: '', kind: 'inspection', title: '', interval_months: '', last_done: '', next_due: '', provider: ''},
        tk: {kind: 'breakdown', target: '', title: '', provider: '', reported_on: '', due_date: '', status: 'open'}};
    const STEPS = ['Przegląd', 'Do decyzji', 'Zatwierdź'];
    const DEC_CLS = {booked: 'warn', done: 'ok', not_applicable: ''};
    const STATE = {overdue: ['po terminie', 'bad'], soon: ['wkrótce', 'warn'], none: ['bez terminu', 'warn'], ok: ['w terminie', 'ok']};
    const ICON = {check: 'bi-clipboard-check', ticket: 'bi-wrench-adjustable', stop: 'bi-exclamation-octagon', machine: 'bi-gear-wide-connected'};
    const sel = (k) => CSS.escape(k);
    const dmy = (d) => d ? `${d.slice(8, 10)}.${d.slice(5, 7)}.${d.slice(0, 4)}` : '—';
    const st = 'min-height:36px;border-radius:9px';
    const link = (l) => `<a class="tw-link" href="${at.root}${esc(l.href)}" target="_blank" rel="noopener" style="padding:0"><i class="bi bi-box-arrow-up-right"></i> ${esc(l.label)}</a>`;
    const links = (ls) => ls && ls.length ? `<div style="display:flex;gap:4px 14px;flex-wrap:wrap">${ls.map(link).join('')}</div>` : '';
    const text = (f, k, v, ph, w = 200) => `<input class="form-control form-control-sm" data-mt-f="${f}" data-k="${k}" value="${esc(v)}" placeholder="${esc(ph)}" style="max-width:${w}px;${st}">`;
    const date = (f, k, v, title) => `<input type="date" class="form-control form-control-sm" data-mt-f="${f}" data-k="${k}" value="${esc(v)}" title="${esc(title)}" style="max-width:160px;${st}">`;
    const select = (f, k, v, opts, w = 220) => `<select class="form-select form-select-sm" data-mt-f="${f}" data-k="${k}" style="max-width:${w}px;${st}">${opts.map(([val, l]) => `<option value="${esc(val)}" ${String(v) === String(val) ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select>`;
    const lab = (t) => `<small style="white-space:nowrap">${esc(t)}</small>`;
    const eff = (i) => i.decision || i.earlier;
    const what = (i) => `<b><i class="bi ${ICON[i.kind] || 'bi-dot'}"></i> ${esc(i.title)}</b><small>${esc(i.kind_label)}${i.sub ? ' · ' + esc(i.sub) : ''}</small>`;
    // Valeur d'un champ de brouillon : formulaires des registres (form.*) ou action d'un élément (act[clé]).
    const bucket = (f) => f.startsWith('act:') ? act[f.slice(4)] : (f.startsWith('dec:') ? draft[f.slice(4)] : form[f]);
    // Boutons actifs : mêmes règles au rendu et pendant la saisie (refresh, sans re-rendu : le focus reste).
    const ok = {
        eq: () => !!(form.eq.name.trim() || form.eq.id_workstation),
        ck: () => !!form.ck.id_equipment,
        tk: () => !!form.tk.target && form.tk.title.trim().length >= 3,
        act: (i) => { const a = act[i.key] || {}; return i.kind === 'check' ? !!a.done_on : (i.kind === 'stop' ? (a.title || '').trim().length >= 3 : true); },
        dec: (i) => {
            const r = draft[i.key], a = i.decision;
            if (!r || !r.decision || (r.decision === 'not_applicable' && r.note.trim().length < 3) || (r.decision === 'booked' && !r.booked_on)) return false;
            return !a || a.code !== r.decision || (a.note || '') !== r.note || (a.booked_on || '') !== r.booked_on;
        },
    };
    const item = (key) => D.review.find(x => x.key === key);
    function refresh() {
        app.querySelectorAll('[data-mt-save]').forEach(b => { b.disabled = busy || !ok[b.dataset.mtSave](); });
        app.querySelectorAll('[data-mt-act]').forEach(b => { const i = item(b.dataset.mtAct); b.disabled = busy || !i || !ok.act(i); });
        app.querySelectorAll('[data-mt-dsave]').forEach(b => { const i = item(b.dataset.mtDsave); b.disabled = busy || !i || !ok.dec(i); });
    }

    function step1() {
        const t = D.totals, s = D.settings, R = D.register;
        const tiles = [{label: 'Urządzenia', value: String(t.equipment), sub: `${t.checks} ${tw.plural(t.checks, 'przegląd', 'przeglądy', 'przeglądów')} · maszyny ERP ${t.machines - t.machine_unregistered} z ${t.machines} w rejestrze`},
            {label: 'Przeglądy i serwisy', value: String(t.check_overdue + t.check_soon + t.check_no_date), sub: `${t.check_overdue} po terminie · ${t.check_soon} wkrótce · ${t.check_no_date} bez terminu`, cls: t.check_overdue ? 'warn' : 'ok'},
            {label: 'Zgłoszenia otwarte', value: String(t.tickets_open), sub: `${t.ticket_overdue} po terminie · ${t.ticket_no_due} bez terminu`, cls: t.ticket_overdue ? 'warn' : 'ok'},
            {label: 'Awarie z tabletów', value: String(t.stops), sub: `${t.stop_no_ticket} bez zgłoszenia (od ${dmy(D.stops_from)})`, cls: t.stop_no_ticket ? 'warn' : 'ok'}];
        const notes = Object.values(D.sources).filter(x => x.note);
        const info = `<div><b>Reguły</b> : « wkrótce » = termin w ciągu <b>${s.alert_days}</b> dni (do ${esc(dmy(D.soon_until))}) ; awaria z tabletu krótsza niż <b>${s.breakdown_min_minutes}</b> min nie wymaga zgłoszenia
            (Ustawienia › Parametry, maintenance.*). Zgłoszenie pokrywa awarię, gdy jest z nią powiązane albo dotyczy tej samej maszyny (od dnia przed do 7 dni po awarii).</div>
            ${D.links.length ? `<div style="margin-top:4px">${links(D.links)}</div>` : ''}`;
        const eqOpts = [['', '— urządzenie —']].concat(R.equipment.map(e => [e.id, e.name]));
        const free = D.machines.filter(m => !m.registered);
        return `<p class="tw-lead">Stan na dziś, miesiąc <b>${esc(D.month_label)}</b> : przeglądy, serwisy i pomiary z terminem, zgłoszenia awarii i zleconych serwisów, awarie zapisane na tabletach grup.
                Rejestry prowadzi się tutaj (ERP nie miał rejestru utrzymania ruchu) ; każdy termin po czasie, wkrótce albo bez daty trafia do kroku 2.</p>
            ${tw.tiles(tiles)}
            ${tw.alert(info, '', 'bi-info-circle')}
            ${notes.length ? tw.alert(notes.map(x => `<div>${esc(x.note)}</div>`).join(''), '', 'bi-info-circle') : ''}
            <div class="tw-cap">Urządzenia</div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin:6px 0 10px">
                ${select('eq', 'id_workstation', form.eq.id_workstation, [['', '— maszyna ERP (opcjonalnie) —']].concat(free.map(m => [m.id, m.name])), 240)}
                ${text('eq', 'name', form.eq.name, 'nazwa (np. Chłodnia 2, Waga 15 kg)', 240)}
                ${select('eq', 'kind', form.eq.kind, Object.entries(D.equipment_kinds), 200)}
                ${text('eq', 'location', form.eq.location, 'miejsce (opcjonalnie)', 180)}
                <button type="button" class="tw-btn ghost sm" data-mt-save="eq" ${busy || !ok.eq() ? 'disabled' : ''}><i class="bi bi-plus-lg"></i> Dodaj urządzenie</button></div>
            ${tw.table([{label: 'Urządzenie'}, {label: 'Rodzaj'}, {label: 'Maszyna ERP'}, {label: 'Miejsce'}, {label: 'Przeglądy', num: true}],
                R.equipment.map(e => ({cells: [`<b>${esc(e.name)}</b>`, esc(e.kind_label), esc(e.machine || '—'), esc(e.location || '—'), String(e.checks)]})), 'Rejestr urządzeń jest pusty — dodaj maszyny i inne urządzenia (chłodnie, wagi, gaśnice…).')}
            <div class="tw-cap">Przeglądy, serwisy i pomiary</div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin:6px 0 10px">
                ${select('ck', 'id_equipment', form.ck.id_equipment, eqOpts, 220)} ${select('ck', 'kind', form.ck.kind, Object.entries(D.check_kinds), 200)}
                ${text('ck', 'title', form.ck.title, 'nazwa (np. pomiar izolacji)', 190)} ${text('ck', 'interval_months', form.ck.interval_months, 'co ile mies.', 110)}
                ${lab('ostatnio')}${date('ck', 'last_done', form.ck.last_done, 'Ostatnio wykonane')} ${lab('następny')}${date('ck', 'next_due', form.ck.next_due, 'Następny termin (puste = ostatnio + okres)')}
                ${text('ck', 'provider', form.ck.provider, 'firma (opcjonalnie)', 170)}
                <button type="button" class="tw-btn ghost sm" data-mt-save="ck" ${busy || !ok.ck() ? 'disabled' : ''}><i class="bi bi-plus-lg"></i> Dodaj przegląd</button></div>
            ${tw.table([{label: 'Urządzenie'}, {label: 'Przegląd / serwis'}, {label: 'Co ile'}, {label: 'Ostatnio'}, {label: 'Następny termin'}, {label: 'Firma'}, {label: 'Stan'}],
                R.checks.map(c => ({cells: [`<b>${esc(c.equipment)}</b>`, esc(c.kind_label) + (c.title ? `<small>${esc(c.title)}</small>` : ''), c.interval_months ? `${c.interval_months} mies.` : '—', esc(dmy(c.last_done)),
                    esc(dmy(c.next_due)), esc(c.provider || '—'), tw.pill(STATE[c.state][0], STATE[c.state][1])]})), 'Brak przeglądów w rejestrze.')}
            <div class="tw-cap">Zgłoszenia awarii i serwisów</div>
            ${ticketForm()}
            ${tw.table([{label: 'Zgłoszenie'}, {label: 'Urządzenie'}, {label: 'Zgłoszone'}, {label: 'Termin'}, {label: 'Firma'}, {label: 'Stan'}],
                R.tickets.map(k => ({cells: [`<b>${esc(k.title)}</b><small>${esc(k.kind_label)}</small>`, esc(k.what || '—'), esc(dmy(k.reported_on)), esc(dmy(k.due_date)), esc(k.provider || '—'),
                    tw.pill(k.status_label + (k.closed_on ? ' ' + dmy(k.closed_on) : ''), k.status === 'done' ? 'ok' : (k.status === 'cancelled' ? '' : 'warn'))]})), 'Brak otwartych zgłoszeń.')}`;
    }

    function ticketForm() {
        const targets = [['', '— urządzenie albo maszyna —']].concat(D.register.equipment.map(e => ['eq:' + e.id, e.name]), D.machines.filter(m => !m.registered).map(m => ['ws:' + m.id, m.name + ' (maszyna ERP)']));
        const f = form.tk;
        return `<div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin:6px 0 10px">
            ${select('tk', 'kind', f.kind, Object.entries(D.ticket_kinds), 160)} ${select('tk', 'target', f.target, targets, 240)} ${text('tk', 'title', f.title, 'opis (np. nie grzeje lewa komora)', 240)}
            ${text('tk', 'provider', f.provider, 'firma / serwisant', 170)} ${lab('termin')}${date('tk', 'due_date', f.due_date, 'Termin realizacji')}
            ${select('tk', 'status', f.status, [['open', 'otwarte'], ['ordered', 'zlecone']], 120)}
            <button type="button" class="tw-btn ghost sm" data-mt-save="tk" ${busy || !ok.tk() ? 'disabled' : ''}><i class="bi bi-plus-lg"></i> Dodaj zgłoszenie</button></div>`;
    }

    // Action qui règle le signal, selon le type d'élément.
    function action(i) {
        if (i.kind === 'check') {
            const a = act[i.key] ?? (act[i.key] = {done_on: '', next_due: ''});
            return `<div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">${lab('Wykonano')}${date('act:' + i.key, 'done_on', a.done_on, 'Data wykonania')}
                ${lab(i.interval_months ? `następny (puste = +${i.interval_months} mies.)` : 'następny')}${date('act:' + i.key, 'next_due', a.next_due, 'Następny termin')}
                <button type="button" class="tw-btn ghost sm" data-mt-act="${esc(i.key)}" ${busy || !ok.act(i) ? 'disabled' : ''}>Zapisz wykonanie</button></div>`;
        }
        if (i.kind === 'ticket') {
            const a = act[i.key] ?? (act[i.key] = {status: i.status, due_date: i.due_date || ''});
            return `<div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">${lab('Stan')}${select('act:' + i.key, 'status', a.status, Object.entries(D.statuses), 150)}
                ${lab('termin')}${date('act:' + i.key, 'due_date', a.due_date, 'Termin realizacji')}
                <button type="button" class="tw-btn ghost sm" data-mt-act="${esc(i.key)}" ${busy ? 'disabled' : ''}>Zapisz stan</button></div>`;
        }
        if (i.kind === 'stop') {
            if (!i.codes.length) return '';
            const a = act[i.key] ?? (act[i.key] = {title: 'Awaria — ' + i.title, provider: '', due_date: ''});
            return `<div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">${text('act:' + i.key, 'title', a.title, 'opis awarii', 220)}${text('act:' + i.key, 'provider', a.provider, 'serwisant', 150)}
                ${lab('termin')}${date('act:' + i.key, 'due_date', a.due_date, 'Termin realizacji')}
                <button type="button" class="tw-btn ghost sm" data-mt-act="${esc(i.key)}" ${busy || !ok.act(i) ? 'disabled' : ''}>Utwórz zgłoszenie</button></div>`;
        }
        if (i.kind === 'machine' && i.codes.length) return `<div><button type="button" class="tw-btn ghost sm" data-mt-act="${esc(i.key)}" ${busy ? 'disabled' : ''}><i class="bi bi-plus-lg"></i> Dodaj do rejestru urządzeń</button></div>`;
        return '';
    }

    function decisionCell(i) {
        const a = i.decision;
        const r = draft[i.key] ?? (draft[i.key] = {decision: a ? a.code : '', booked_on: a && a.booked_on ? a.booked_on : '', note: a ? (a.note || '') : ''});
        const seg = (D.kind_decisions[i.kind] || []).map(k => `<button type="button" class="${r.decision === k ? 'on' : ''}" data-mt-dec="${esc(i.key)}" data-v="${k}">${esc(D.decisions[k])}</button>`).join('');
        const ph = r.decision === 'not_applicable' ? 'Dlaczego nie dotyczy (wymagane)' : (r.decision === 'booked' ? 'Z kim umówione (opcjonalnie)' : 'Notatka (opcjonalnie)');
        const saved = a ? `<small>${tw.pill(a.label + (a.booked_on ? ' ' + dmy(a.booked_on) : ''), DEC_CLS[a.code] || '')} ${esc(a.at)}${a.by ? ' · ' + esc(a.by) : ''}${a.note ? ' — ' + esc(a.note) : ''}</small>`
            : (i.earlier ? `<small>${tw.pill(i.earlier.label + (i.earlier.booked_on ? ' ' + dmy(i.earlier.booked_on) : ''), DEC_CLS[i.earlier.code] || '')} w miesiącu « ${esc(i.earlier.month_label)} »${i.earlier.note ? ' — ' + esc(i.earlier.note) : ''}</small>` : '');
        return `<div style="display:grid;gap:6px;min-width:440px">${action(i)}<div class="tw-seg" style="justify-self:start">${seg}</div>
            <div style="display:flex;gap:6px">${r.decision === 'booked' ? date('dec:' + i.key, 'booked_on', r.booked_on, i.kind === 'ticket' ? 'Nowy termin realizacji' : 'Data umówionego przeglądu / serwisu') : ''}
            <input class="form-control form-control-sm" data-mt-f="dec:${esc(i.key)}" data-k="note" maxlength="500" value="${esc(r.note)}" placeholder="${esc(ph)}" style="${st}">
            <button type="button" class="tw-btn ghost sm" data-mt-dsave="${esc(i.key)}" ${busy || !ok.dec(i) ? 'disabled' : ''}>Zapisz</button></div>
            ${links(i.links)}${i.reason ? `<small class="tw-red">${esc(i.reason)}</small>` : ''}${saved}</div>`;
    }

    function step2() {
        const list = D.review, t = D.totals;
        if (!list.length) return tw.alert('Nic do decyzji : przeglądy w terminie, zgłoszenia w terminie, każda awaria z tabletu ma zgłoszenie.', 'ok', 'bi-check-circle');
        const rows = list.map(i => ({cls: i.waiting ? 'hl' : '', cells: [what(i),
            i.signals.map(s => `<div style="display:flex;gap:6px;align-items:baseline;margin:1px 0"><i class="bi bi-exclamation-circle ${['check_soon', 'machine_unregistered'].includes(s.code) ? '' : 'tw-red'}"></i><span><b>${esc(s.label)}</b>${s.detail ? ' : ' + esc(s.detail) : ''}</span></div>`).join('') || '<small>załatwione</small>',
            decisionCell(i)]}));
        return `<p class="tw-lead">Dla każdego elementu : zapisz to, co już zrobiono (wykonanie przeglądu, stan zgłoszenia, zgłoszenie awarii, wpis do rejestru) — « Załatwione » zapisuje się samo ;
                albo <b>Umówione — termin</b> (data serwisu ; dla zgłoszenia to nowy termin realizacji), albo <b>Nie dotyczy</b> z powodem.</p>
            ${tw.tiles([{label: 'Do decyzji', value: String(t.review), sub: `przeglądy ${t.by_kind.check} · zgłoszenia ${t.by_kind.ticket} · awarie ${t.by_kind.stop} · maszyny ${t.by_kind.machine}`},
                {label: 'Z decyzją', value: `${t.resolved} z ${t.review}`, sub: t.waiting ? `zostało ${t.waiting}` : 'wszystkie z decyzją', cls: t.waiting ? 'warn' : 'ok'},
                {label: 'Umówione', value: String(t.booked), sub: t.earlier ? `${t.earlier} z poprzednich miesięcy` : 'w tym przeglądzie'},
                {label: 'Załatwione / nie dotyczy', value: `${t.done} / ${t.not_applicable}`, sub: 'po sprawdzeniu'}])}
            ${tw.table([{label: 'Element'}, {label: 'Sygnał'}, {label: 'Działanie i decyzja'}], rows)}
            <p class="tw-note">Asystent zapisuje tylko swoje rejestry i decyzje ; maszyny i postoje w ERP się nie zmieniają, nic nie jest zamawiane ani wysyłane do serwisu.</p>`;
    }

    function step3() {
        const t = D.totals;
        if (D.done) return tw.done('Serwisy i przeglądy sprawdzone · ' + (D.done.at || ''), D.done.summary + ' · zadanie zaliczone');
        const booked = D.review.filter(i => eff(i) && eff(i).code === 'booked').map(i => ({cells: [what(i), esc(dmy(eff(i).booked_on)), eff(i).note ? `<small>${esc(eff(i).note)}</small>` : '']}));
        return `<p class="tw-lead">Gotowe : <b>${esc(D.summary)}</b>.</p>
            ${tw.tiles([{label: 'Umówione', value: String(t.booked), sub: 'serwisy i przeglądy'}, {label: 'Załatwione', value: String(t.done), sub: 'zapisane w rejestrach'},
                {label: 'Nie dotyczy', value: String(t.not_applicable), sub: 'z powodem'}, {label: 'Decyzje', value: `${t.resolved} z ${t.review}`, sub: 'wszystkie elementy', cls: 'ok'}])}
            ${booked.length ? '<div class="tw-cap">Umówione terminy</div>' + tw.table([{label: 'Element'}, {label: 'Data'}, {label: 'Notatka'}], booked) : ''}
            <p class="tw-note">« Zakończ » zalicza zadanie « Serwisy i przeglądy maszyn » na ten miesiąc ; asystent można otwierać w każdej chwili.</p>`;
    }

    function render() {
        const top = D.can_finish ? reached : Math.min(reached, 2);
        if (step > top) step = top;
        const body = step === 1 ? step1() : (step === 2 ? step2() : step3());
        const next = step === 1 ? {label: 'Dalej : do decyzji', attr: 'data-mt-next'}
            : step === 2 ? {label: 'Dalej : zatwierdź', attr: 'data-mt-next', disabled: !D.can_finish}
                : (D.done ? null : {label: 'Zakończ', attr: 'data-mt-finish', final: true, disabled: busy || !D.can_finish});
        app.innerHTML = tw.frame({title: 'Serwisy i przeglądy maszyn', sub: `${D.month_label} · krok ${step} z 3 · ok. 30 min`, steps: STEPS, step, reached: top, body,
            prev: step > 1 ? 'Wstecz' : null, next, later: !D.done && step < 3});
    }

    const post = async (url, body, key, reset) => {
        busy = true; render();
        const res = await tw.post(url, body);
        busy = false;
        if (res.ok) { D = res.data; if (key) { delete draft[key]; delete act[key]; } if (reset) reset(); }
        render();
    };
    tw.bind(app, (n) => { step = n === -1 ? Math.max(1, step - 1) : n; render(); });
    // Saisie : brouillon mis à jour sans re-rendu (le focus reste), boutons recalculés.
    const onField = (e) => { const el = e.target.closest('[data-mt-f]'); if (!el) return; const b = bucket(el.dataset.mtF); if (b) b[el.dataset.k] = el.value; refresh(); };
    app.addEventListener('input', onField);
    app.addEventListener('change', onField);
    app.addEventListener('click', async (e) => {
        if (busy) return;
        if (e.target.closest('[data-mt-next]')) { step = Math.min(3, step + 1); reached = Math.max(reached, step); render(); return; }
        const r = e.target.closest('[data-mt-dec]');
        if (r) { draft[r.dataset.mtDec].decision = r.dataset.v; render(); return; }
        const sv = e.target.closest('[data-mt-save]');
        if (sv) {
            const f = sv.dataset.mtSave;
            if (f === 'eq') return post('/ajax/maintenance/equipment', form.eq, null, () => { form.eq = {name: '', kind: 'machine', id_workstation: '', location: ''}; });
            if (f === 'ck') return post('/ajax/maintenance/check', form.ck, null, () => { form.ck = {...form.ck, title: '', interval_months: '', last_done: '', next_due: '', provider: ''}; });
            const t = form.tk, [kind, id] = t.target.split(':');
            return post('/ajax/maintenance/ticket', {kind: t.kind, title: t.title, provider: t.provider, due_date: t.due_date, status: t.status, [kind === 'eq' ? 'id_equipment' : 'id_workstation']: Number(id)},
                null, () => { form.tk = {kind: 'breakdown', target: '', title: '', provider: '', reported_on: '', due_date: '', status: 'open'}; });
        }
        const ac = e.target.closest('[data-mt-act]');
        if (ac) {
            const key = ac.dataset.mtAct, i = item(key), a = act[key] || {};
            if (!i) return;
            if (i.kind === 'check') return post('/ajax/maintenance/check/done', {id: i.source_id, done_on: a.done_on, next_due: a.next_due}, key);
            if (i.kind === 'ticket') return post('/ajax/maintenance/ticket/status', {id: i.source_id, status: a.status, due_date: a.due_date}, key);
            if (i.kind === 'stop') return post('/ajax/maintenance/ticket', {kind: 'breakdown', id_ws_state: i.source_id, title: a.title, provider: a.provider, due_date: a.due_date, reported_on: i.started_on}, key);
            if (i.kind === 'machine') return post('/ajax/maintenance/equipment', {kind: 'machine', id_workstation: i.source_id}, key);
        }
        const ds = e.target.closest('[data-mt-dsave]');
        if (ds) {
            const key = ds.dataset.mtDsave, d = draft[key], i = item(key);
            if (!d || !d.decision) return;
            // seen : signaux affichés (si réglés entre-temps, « załatwione » s'enregistre avec eux).
            return post('/ajax/maintenance/decision', {key, decision: d.decision, booked_on: d.booked_on, note: d.note, seen: i ? i.codes : []}, key);
        }
        if (e.target.closest('[data-mt-finish]')) {
            busy = true; render();
            const res = await tw.post('/ajax/maintenance/finish', {});
            busy = false;
            if (res.ok) { at.notify(true, res.message); D = res.data; }
            render();
        }
    });
    render();
})();
