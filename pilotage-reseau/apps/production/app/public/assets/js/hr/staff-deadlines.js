// [404] Badania i szkolenia (Kierownik produkcji — co miesiąc) : 1. badania lekarskie (data z HR) et szkolenia (registre de
// l'assistant, formulaire d'ajout) des salariés actifs, 2. une décision par échéance signalée (umówione avec la date, aktualne —
// sprawdzone quand le signal a disparu, nie dotyczy avec note), 3. récapitulatif et validation → la tâche du mois se valide seule.
// Prendre rendez-vous reste un geste humain : rien n'est envoyé, la fiche HR n'est pas modifiée ici.
(() => {
    const app = document.getElementById('sdApp');
    if (!app || !window.tw) return;
    const {esc} = tw;
    let D = JSON.parse(document.getElementById('sdBoot').textContent);
    let step = 1, reached = 1, busy = false;
    // Brouillons : décision par élément (décision, date, note), szkolenie à ajouter par élément, formulaire du registre.
    const draft = {}, add = {};
    const reg = {id_employee: '', kind: 'bhp', title: '', done_on: '', valid_until: ''};
    const STEPS = ['Przegląd', 'Do decyzji', 'Zatwierdź'];
    const DEC_CLS = {booked: 'warn', current: 'ok', not_applicable: ''};
    const STATE_CLS = {expired: 'bad', soon: 'warn', ok: 'ok'};
    const STATE_LABEL = {expired: 'po terminie', soon: 'wkrótce', ok: 'aktualne'};
    const sel = (k) => CSS.escape(k);
    const dmy = (d) => d ? `${d.slice(8, 10)}.${d.slice(5, 7)}.${d.slice(0, 4)}` : '—';
    const link = (l) => `<a class="tw-link" href="${at.root}${esc(l.href)}" target="_blank" rel="noopener" style="padding:0"><i class="bi bi-box-arrow-up-right"></i> ${esc(l.label)}</a>`;
    const links = (ls) => ls && ls.length ? `<div style="display:flex;gap:4px 14px;flex-wrap:wrap">${ls.map(link).join('')}</div>` : '';
    const inp = (attr, key, val, ph, extra = '') => `<input class="form-control form-control-sm" ${attr}="${esc(key)}" value="${esc(val)}" placeholder="${esc(ph)}" style="min-height:36px;border-radius:9px;${extra}">`;
    const date = (attr, key, val, title) => `<input type="date" class="form-control form-control-sm" ${attr}="${esc(key)}" value="${esc(val)}" title="${esc(title)}" style="max-width:160px;min-height:36px;border-radius:9px">`;
    const eff = (i) => i.decision || i.earlier;
    const what = (i) => `<b><i class="bi ${i.kind === 'medical' ? 'bi-heart-pulse' : 'bi-mortarboard'}"></i> ${esc(i.title)}</b><small>${esc(i.subject)}${i.sub ? ' · ' + esc(i.sub) : ''}</small>`;

    function step1() {
        const t = D.totals, s = D.settings;
        const med = t.medical_expired + t.medical_missing + t.medical_soon, tr = t.training_expired + t.training_missing + t.training_soon;
        const tiles = [{label: 'Aktywni pracownicy', value: String(t.employees), sub: `${t.trainings} ${tw.plural(t.trainings, 'szkolenie', 'szkolenia', 'szkoleń')} w rejestrze`},
            {label: 'Badania lekarskie', value: String(med), sub: `${t.medical_expired} po terminie · ${t.medical_missing} bez daty · ${t.medical_soon} wkrótce`, cls: med ? 'warn' : 'ok'},
            {label: 'Szkolenia', value: String(tr), sub: `${t.training_expired} po terminie · ${t.training_missing} bez BHP · ${t.training_soon} wkrótce`, cls: tr ? 'warn' : 'ok'},
            {label: 'Do decyzji', value: String(t.review), sub: t.review ? `${t.resolved} z ${t.review} z decyzją` : 'brak', cls: t.waiting ? 'warn' : 'ok'}];
        const missing = Object.values(D.sources).filter(x => x.note);
        const info = `<div><b>Reguły</b> : « wkrótce » = w ciągu <b>${s.alert_days}</b> dni (do ${esc(dmy(D.soon_until))}) — ten sam termin co przypomnienie w HR (HR › Ustawienia).
            Brak szkolenia BHP w rejestrze ${s.bhp_required ? 'jest sygnałem dla każdego aktywnego pracownika' : 'nie jest sygnałem (ustawienie staff_deadlines.bhp_required = 0)'}.
            Data badań pochodzi z karty HR (pole « Badania ważne do ») ; szkolenia z rejestru poniżej — ERP nie zna innych terminów szkoleń.</div>`;
        return `<p class="tw-lead">Miesiąc <b>${esc(D.month_label)}</b> : terminy badań lekarskich i szkoleń aktywnych pracowników. Każdy termin po terminie, bez daty albo kończący się wkrótce trafia do kroku 2 :
                umów badanie lub szkolenie, potwierdź aktualność po uzupełnieniu albo zapisz « Nie dotyczy ». Asystent niczego nie wysyła.</p>
            ${tw.tiles(tiles)}
            ${tw.alert(info, '', 'bi-info-circle')}
            ${missing.length ? tw.alert(missing.map(x => `<div>${esc(x.note)}</div>`).join(''), '', 'bi-info-circle') : ''}
            <div class="tw-cap">Rejestr szkoleń</div>
            ${registerForm()}
            ${tw.table([{label: 'Pracownik'}, {label: 'Szkolenie'}, {label: 'Data szkolenia'}, {label: 'Ważne do'}, {label: 'Stan'}, {label: ''}],
                D.register.map(r => ({cells: [esc(r.employee), `<b>${esc(r.kind_label)}</b>${r.title ? `<small>${esc(r.title)}</small>` : ''}`, esc(dmy(r.done_on)), r.valid_until ? esc(dmy(r.valid_until)) : '<small>bezterminowe</small>',
                    tw.pill(STATE_LABEL[r.state], STATE_CLS[r.state]), `<button type="button" class="tw-link" style="padding:0" data-sd-remove="${r.id}" ${busy ? 'disabled' : ''} title="Błędny wpis — usuń z rejestru"><i class="bi bi-x-circle"></i></button>`]})),
                'Rejestr szkoleń jest pusty — dodaj szkolenia pracowników powyżej.')}`;
    }

    function registerForm() {
        const opts = ['<option value="">— pracownik —</option>'].concat(D.employees.map(e => `<option value="${e.id}" ${String(reg.id_employee) === String(e.id) ? 'selected' : ''}>${esc(e.name)}</option>`)).join('');
        const kinds = Object.entries(D.trainings).map(([k, l]) => `<option value="${k}" ${reg.kind === k ? 'selected' : ''}>${esc(l)}</option>`).join('');
        const ok = reg.id_employee && reg.kind && reg.done_on && !busy;
        return `<div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin:6px 0 10px">
            <select class="form-select form-select-sm" data-sd-reg="id_employee" style="max-width:220px;min-height:36px;border-radius:9px">${opts}</select>
            <select class="form-select form-select-sm" data-sd-reg="kind" style="max-width:230px;min-height:36px;border-radius:9px">${kinds}</select>
            ${inp('data-sd-reg', 'title', reg.title, 'nazwa (opcjonalnie)', 'max-width:200px')}
            <label class="tw-note" style="margin:0">szkolenie</label>${date('data-sd-reg', 'done_on', reg.done_on, 'Data szkolenia')}
            <label class="tw-note" style="margin:0">ważne do</label>${date('data-sd-reg', 'valid_until', reg.valid_until, 'Ważne do (puste = bezterminowe)')}
            <button type="button" class="tw-btn ghost sm" data-sd-reg-save ${ok ? '' : 'disabled'}><i class="bi bi-plus-lg"></i> Dodaj szkolenie</button></div>`;
    }

    function addForm(i) {
        if (i.kind !== 'training') return '';
        const a = add[i.key] ?? (add[i.key] = {done_on: '', valid_until: '', title: ''});
        return `<div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap"><small>Szkolenie odbyte :</small>${date('data-sd-add-done', i.key, a.done_on, 'Data szkolenia')}
            <small>ważne do</small>${date('data-sd-add-until', i.key, a.valid_until, 'Ważne do (puste = bezterminowe)')}
            <button type="button" class="tw-btn ghost sm" data-sd-add="${esc(i.key)}" ${busy || !a.done_on ? 'disabled' : ''}>Zapisz w rejestrze</button></div>`;
    }

    function decisionCell(i) {
        const a = i.decision;
        const r = draft[i.key] ?? (draft[i.key] = {decision: a ? a.code : '', booked_on: a && a.booked_on ? a.booked_on : '', note: a ? (a.note || '') : ''});
        const seg = Object.keys(D.decisions).map(k => `<button type="button" class="${r.decision === k ? 'on' : ''}" data-sd-dec="${esc(i.key)}" data-v="${k}">${esc(D.decisions[k])}</button>`).join('');
        const ph = r.decision === 'not_applicable' ? 'Dlaczego nie dotyczy (wymagane)' : (r.decision === 'booked' ? 'Gdzie / u kogo (opcjonalnie)' : 'Notatka (opcjonalnie)');
        const saved = a ? `<small>${tw.pill(a.label + (a.booked_on ? ' na ' + dmy(a.booked_on) : ''), DEC_CLS[a.code] || '')} ${esc(a.at)}${a.by ? ' · ' + esc(a.by) : ''}${a.note ? ' — ' + esc(a.note) : ''}</small>`
            : (i.earlier ? `<small>${tw.pill(i.earlier.label + (i.earlier.booked_on ? ' na ' + dmy(i.earlier.booked_on) : ''), DEC_CLS[i.earlier.code] || '')} w miesiącu « ${esc(i.earlier.month_label)} »${i.earlier.note ? ' — ' + esc(i.earlier.note) : ''}</small>` : '');
        const dirty = !a || a.code !== r.decision || (a.note || '') !== r.note || (a.booked_on || '') !== r.booked_on;
        const bad = !r.decision || (r.decision === 'not_applicable' && r.note.trim().length < 3) || (r.decision === 'booked' && !r.booked_on);
        return `<div style="display:grid;gap:6px;min-width:420px">${addForm(i)}<div class="tw-seg" style="justify-self:start">${seg}</div>
            <div style="display:flex;gap:6px">${r.decision === 'booked' ? date('data-sd-booked', i.key, r.booked_on, 'Data umówionych badań / szkolenia') : ''}
            ${inp('data-sd-note', i.key, r.note, ph)}
            <button type="button" class="tw-btn ghost sm" data-sd-save="${esc(i.key)}" ${bad || !dirty || busy ? 'disabled' : ''}>Zapisz</button></div>
            ${links(i.links)}${i.reason ? `<small class="tw-red">${esc(i.reason)}</small>` : ''}${saved}</div>`;
    }

    function step2() {
        const list = D.review, t = D.totals;
        if (!list.length) return tw.alert('Nic do decyzji : wszystkie badania i szkolenia aktywnych pracowników są aktualne.', 'ok', 'bi-check-circle');
        const rows = list.map(i => ({cls: i.waiting ? 'hl' : '', cells: [what(i),
            i.signals.map(s => `<div style="display:flex;gap:6px;align-items:baseline;margin:1px 0"><i class="bi bi-exclamation-circle ${/_soon$/.test(s.code) ? '' : 'tw-red'}"></i><span><b>${esc(s.label)}</b>${s.detail ? ' : ' + esc(s.detail) : ''}</span></div>`).join('')
                + (i.info ? `<small>${esc(i.info)}</small>` : '') || '<small>aktualne</small>',
            decisionCell(i)]}));
        return `<p class="tw-lead">Dla każdego terminu : <b>Umówione</b> (data badań lub szkolenia), <b>Aktualne — sprawdzone</b> (gdy nowa data jest już w HR albo szkolenie w rejestrze — zapis szkolenia tutaj zapisuje to samo),
                albo <b>Nie dotyczy</b> z powodem. Umówione obowiązuje do daty wizyty ; « Nie dotyczy » w kolejnych miesiącach, dopóki sygnał jest ten sam.</p>
            ${tw.tiles([{label: 'Do decyzji', value: String(t.review), sub: `badania ${t.by_kind.medical} · szkolenia ${t.by_kind.training}`},
                {label: 'Z decyzją', value: `${t.resolved} z ${t.review}`, sub: t.waiting ? `zostało ${t.waiting}` : 'wszystkie z decyzją', cls: t.waiting ? 'warn' : 'ok'},
                {label: 'Umówione', value: String(t.booked), sub: t.earlier ? `${t.earlier} z poprzednich miesięcy` : 'w tym przeglądzie'},
                {label: 'Aktualne / nie dotyczy', value: `${t.current} / ${t.not_applicable}`, sub: 'po sprawdzeniu'}])}
            ${tw.table([{label: 'Pracownik i termin'}, {label: 'Sygnał'}, {label: 'Decyzja'}], rows)}
            <p class="tw-note">Datę badań zmienia się w karcie pracownika w HR (link) ; asystent zapisuje tylko rejestr szkoleń i decyzje. Nic nie jest wysyłane do pracowników ani do lekarza.</p>`;
    }

    function step3() {
        const t = D.totals;
        if (D.done) return tw.done('Badania i szkolenia sprawdzone · ' + (D.done.at || ''), D.done.summary + ' · zadanie zaliczone');
        const booked = D.review.filter(i => eff(i) && eff(i).code === 'booked').map(i => ({cells: [what(i), esc(dmy(eff(i).booked_on)), eff(i).note ? `<small>${esc(eff(i).note)}</small>` : '']}));
        return `<p class="tw-lead">Gotowe : <b>${esc(D.summary)}</b>.</p>
            ${tw.tiles([{label: 'Umówione', value: String(t.booked), sub: 'badania i szkolenia'}, {label: 'Aktualne', value: String(t.current), sub: 'po uzupełnieniu'},
                {label: 'Nie dotyczy', value: String(t.not_applicable), sub: 'z powodem'}, {label: 'Decyzje', value: `${t.resolved} z ${t.review}`, sub: 'wszystkie terminy', cls: 'ok'}])}
            ${booked.length ? '<div class="tw-cap">Umówione terminy</div>' + tw.table([{label: 'Pracownik i termin'}, {label: 'Data'}, {label: 'Notatka'}], booked) : ''}
            <p class="tw-note">« Zakończ » zalicza zadanie « Badania i szkolenia » na ten miesiąc.</p>`;
    }

    function render() {
        if (!D) { app.innerHTML = tw.alert('Brak dostępu do HR : daty badań i szkoleń widzi tylko ten, kto ma prawo « HR ».', '', 'bi-lock'); return; }
        const top = D.can_finish ? reached : Math.min(reached, 2);
        if (step > top) step = top;
        const body = step === 1 ? step1() : (step === 2 ? step2() : step3());
        const next = step === 1 ? {label: 'Dalej : do decyzji', attr: 'data-sd-next'}
            : step === 2 ? {label: 'Dalej : zatwierdź', attr: 'data-sd-next', disabled: !D.can_finish}
                : (D.done ? null : {label: 'Zakończ', attr: 'data-sd-finish', final: true, disabled: busy || !D.can_finish});
        app.innerHTML = tw.frame({title: 'Badania i szkolenia', sub: `${D.month_label} · krok ${step} z 3 · ok. 20 min`, steps: STEPS, step, reached: top, body,
            prev: step > 1 ? 'Wstecz' : null, next, later: !D.done && step < 3});
    }

    const after = (res, key) => { busy = false; if (res.ok) { D = res.data; if (key) { delete draft[key]; delete add[key]; } } render(); };
    tw.bind(app, (n) => { step = n === -1 ? Math.max(1, step - 1) : n; render(); });
    const onField = (e) => {
        const t = e.target;
        if (t.dataset.sdReg) { reg[t.dataset.sdReg] = t.value; const b = app.querySelector('[data-sd-reg-save]'); if (b) b.disabled = !(reg.id_employee && reg.kind && reg.done_on) || busy; return; }
        if (t.dataset.sdNote !== undefined || t.dataset.sdBooked !== undefined) {
            const key = t.dataset.sdNote ?? t.dataset.sdBooked;
            draft[key][t.dataset.sdNote !== undefined ? 'note' : 'booked_on'] = t.value;
            const r = draft[key], i = D.review.find(x => x.key === key), a = i && i.decision, btn = app.querySelector(`[data-sd-save="${sel(key)}"]`);
            if (btn) btn.disabled = busy || !r.decision || (r.decision === 'not_applicable' && r.note.trim().length < 3) || (r.decision === 'booked' && !r.booked_on)
                || (a && a.code === r.decision && (a.note || '') === r.note && (a.booked_on || '') === r.booked_on);
            return;
        }
        const k = t.dataset.sdAddDone ?? t.dataset.sdAddUntil;
        if (k !== undefined) {
            add[k][t.dataset.sdAddDone !== undefined ? 'done_on' : 'valid_until'] = t.value;
            const b = app.querySelector(`[data-sd-add="${sel(k)}"]`);
            if (b) b.disabled = busy || !add[k].done_on;
        }
    };
    app.addEventListener('input', onField);
    app.addEventListener('change', onField);
    app.addEventListener('click', async (e) => {
        if (e.target.closest('[data-sd-next]')) { step = Math.min(3, step + 1); reached = Math.max(reached, step); render(); return; }
        const r = e.target.closest('[data-sd-dec]');
        if (r) { const key = r.dataset.sdDec; draft[key].decision = r.dataset.v; render(); app.querySelector(`[data-sd-note="${sel(key)}"]`)?.focus(); return; }
        const s = e.target.closest('[data-sd-save]');
        if (s) {
            const key = s.dataset.sdSave, d = draft[key], i = D.review.find(x => x.key === key);
            if (!d || !d.decision || busy) return;
            busy = true; s.disabled = true;
            // seen : signaux affichés (si la date a été corrigée dans HR entre-temps, « aktualne » s'enregistre avec eux).
            after(await tw.post('/ajax/hr/deadlines/decision', {key, decision: d.decision, booked_on: d.booked_on, note: d.note, seen: i ? i.codes : []}), key);
            return;
        }
        const ad = e.target.closest('[data-sd-add]');
        if (ad) {
            const key = ad.dataset.sdAdd, a = add[key], i = D.review.find(x => x.key === key);
            if (!a || !i || busy) return;
            busy = true; ad.disabled = true;
            after(await tw.post('/ajax/hr/deadlines/training', {id_employee: i.id_employee, kind: i.training_kind, done_on: a.done_on, valid_until: a.valid_until}), key);
            return;
        }
        if (e.target.closest('[data-sd-reg-save]')) {
            if (busy) return;
            busy = true; render();
            const res = await tw.post('/ajax/hr/deadlines/training', {...reg});
            if (res.ok) { reg.title = ''; reg.done_on = ''; reg.valid_until = ''; }
            after(res, null);
            return;
        }
        const rm = e.target.closest('[data-sd-remove]');
        if (rm) {
            if (busy || !window.confirm('Usunąć ten wpis z rejestru szkoleń (błędny wpis) ?')) return;
            busy = true;
            after(await tw.post('/ajax/hr/deadlines/training/remove', {id: Number(rm.dataset.sdRemove)}), null);
            return;
        }
        if (e.target.closest('[data-sd-finish]')) {
            if (busy) return;
            busy = true; render();
            const res = await tw.post('/ajax/hr/deadlines/finish', {});
            busy = false;
            if (res.ok) { at.notify(true, res.message); D = res.data; }
            render();
        }
    });
    render();
})();
