// [379] Reklamacje klientów (Specjalista ds. jakości, co tydzień) : 1. reklamacje otwarte i źle prowadzone, tydzień według
// typu, przyczyny, klienta, produktu i grupy, 2. une décision par réclamation signalée (odpowiedź / NC / do obserwacji),
// 3. récapitulatif et validation → la tâche du poste se valide seule. Rien n'est changé dans les réclamations depuis cet
// écran : les liens ouvrent la liste « Reklamacje » et le registre NC. Montants seulement si D.values ([312]).
(() => {
    const app = document.getElementById('crApp');
    if (!app || !window.tw) return;
    const {esc, nf} = tw;
    let D = JSON.parse(document.getElementById('crBoot').textContent);
    let step = 1, reached = 1, busy = false;
    // Brouillons de l'étape 2 (décision choisie, note) par réclamation, gardés entre deux rendus.
    const draft = {};
    const STEPS = ['Przegląd', 'Do wyjaśnienia', 'Zatwierdź'];
    const DEC_CLS = {answered: 'ok', nc: 'bad', watch: 'warn'};
    const SIG_CLS = {open_late: 'bad', no_response: 'warn', not_seen: 'warn', no_group: 'warn'};
    const SHORT = {open_late: 'bez decyzji', no_response: 'bez odpowiedzi', not_seen: 'niewidziana', no_group: 'bez grupy'};
    const ST_CLS = {NEW: 'warn', IN_REVIEW: 'warn', ACCEPTED: 'ok', REJECTED: ''};
    const eur = (v) => nf(v, 2) + ' €';
    const canFinish = () => !!D.can_finish;
    const what = (c) => c.product ? `<b>${esc(c.product.name)}</b>` : `<b>${esc(c.type_label)}</b>`;
    const sigs = (c) => c.signals.map(s => tw.pill(SHORT[s.code] || s.code, SIG_CLS[s.code] || '')).join(' ') || '<span class="tw-green">OK</span>';
    const status = (c) => c.waiting ? tw.pill('do wyjaśnienia', 'bad') : (c.decision ? tw.pill(c.decision.label, DEC_CLS[c.decision.code] || '') : tw.pill('OK', 'ok'));
    const group = (c) => c.type !== 'PRODUCT' ? '<span style="color:#999">—</span>'
        : (c.group ? `${esc(c.group.name)}${c.status === 'ACCEPTED' ? `<small>${c.seen ? 'widziane ' + esc(c.seen.at) + (c.seen.by ? ' · ' + esc(c.seen.by) : '') : '<span class="tw-red">niewidziane</span>'}</small>` : ''}` : '<span class="tw-red">bez grupy</span>');
    const ncs = (c) => c.ncs.length ? c.ncs.map(n => `<small><i class="bi bi-clipboard-x"></i> ${esc(n.number)} · ${esc(n.on_label)} · ${esc(n.status_label)}</small>`).join('') : '';
    const listLink = `<a class="tw-link" href="${at.root}${esc(D.list_link)}" target="_blank" rel="noopener" style="padding:0"><i class="bi bi-list-ul"></i> Reklamacje (lista)</a>`;
    const ncLink = `<a class="tw-link" href="${at.root}${esc(D.nc_link)}" target="_blank" rel="noopener" style="padding:0"><i class="bi bi-clipboard-plus"></i> Rejestr NC — nowa</a>`;

    function breakdown(title, rows, empty) {
        const head = [{label: title}, {label: 'Razem', num: true}, {label: 'Przyjęte', num: true}, {label: 'Odrzucone', num: true}, {label: 'Otwarte', num: true}]
            .concat(D.values ? [{label: 'Wartość', num: true}] : []);
        const body = rows.slice(0, 8).map(r => ({cells: [esc(r.label), String(r.count), String(r.accepted), String(r.rejected), r.open ? `<span class="tw-red">${r.open}</span>` : '0']
            .concat(D.values ? [eur(r.value)] : [])}));
        const more = rows.length > 8 ? `<p class="tw-note">+ ${rows.length - 8} ${tw.plural(rows.length - 8, 'pozycja', 'pozycje', 'pozycji')}</p>` : '';
        return `<div><div class="tw-cap">${esc(title)}</div>${tw.table(head, body, empty || 'Brak.')}${more}</div>`;
    }

    function step1() {
        const t = D.totals, bc = t.by_code;
        const head = [{label: 'Zgłoszona'}, {label: 'Klient'}, {label: 'Produkt / typ'}, {label: 'Przyczyna'}, {label: 'Status'}, {label: 'Grupa'}, {label: 'Sygnały'}, {label: ''}];
        const row = (c) => ({cls: c.waiting ? 'hl' : '', cells: [`${esc(c.reported_label)}${c.age !== null ? `<small>${c.age} ${tw.plural(c.age, 'dzień', 'dni', 'dni')} temu</small>` : ''}`,
            `<b>${esc(c.customer.name)}</b>`, what(c), esc(c.reason), tw.pill(c.status_label, ST_CLS[c.status] || ''), group(c), sigs(c), status(c)]});
        const tiles = [{label: 'Otwarte (bez decyzji)', value: String(t.open), sub: `${t.open_new} nowe · ${t.open_review} w weryfikacji · ${bc.open_late} ponad ${D.open_days} ${tw.plural(D.open_days, 'dzień', 'dni', 'dni')}`, cls: bc.open_late ? 'warn' : ''},
            {label: 'Przyjęte bez odpowiedzi', value: String(bc.no_response), sub: `przyjęte w ${D.lookback_days} dni`, cls: bc.no_response ? 'warn' : 'ok'},
            {label: 'Grupa nie widziała', value: String(bc.not_seen + bc.no_group), sub: `${bc.not_seen} bez « Widziane » · ${bc.no_group} bez grupy`, cls: bc.not_seen + bc.no_group ? 'warn' : 'ok'},
            {label: `Tydzień ${D.week_label}`, value: String(t.week), sub: `${t.week_product} produktowe · ${t.week_other} inne · ${t.week_customers} ${tw.plural(t.week_customers, 'klient', 'klientów', 'klientów')}`}];
        if (D.values) tiles.push({label: 'Wartość tygodnia', value: eur(t.week_value), sub: `przyjęte ${eur(t.week_accepted_value)} · kartony rozpoczęte × cena kartonu`});
        tiles.push({label: 'Do wyjaśnienia', value: String(t.review), sub: t.review ? `${t.resolved} z ${t.review} z decyzją` : 'brak sygnałów', cls: t.waiting ? 'warn' : 'ok'});
        const b = D.breakdown;
        return `<p class="tw-lead">Reklamacje otwarte i źle prowadzone (stan na dziś) oraz reklamacje tygodnia <b>${esc(D.week_label)}</b>. Decyzje i odpowiedzi do magazynu zapisuje się w « Reklamacje » ; grupa produktu potwierdza « Widziane » na tablecie. Reklamacje z sygnałem trafiają do kroku 2.</p>
            ${tw.tiles(tiles)}
            <div class="tw-cap">Do wyjaśnienia (${D.review.length})</div>
            ${tw.table(head, D.review.map(row), 'Brak reklamacji otwartych ani źle prowadzonych.')}
            <div class="tw-cap">Tydzień ${esc(D.week_label)} : ${t.week} ${tw.plural(t.week, 'reklamacja', 'reklamacje', 'reklamacji')} (${t.week_accepted} przyjęte · ${t.week_rejected} odrzucone · ${t.week_open} otwarte)</div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(380px,1fr));gap:4px 22px">
                ${breakdown('Typ', b.types, 'Brak reklamacji w tygodniu.')}${breakdown('Przyczyna', b.reasons)}${breakdown('Klient', b.customers)}${breakdown('Produkt', b.products)}${breakdown('Grupa produkcji', b.groups)}
            </div>
            <p class="tw-note">Sygnały : nowa albo w weryfikacji od ponad ${D.open_days} ${tw.plural(D.open_days, 'dnia', 'dni', 'dni')} (complaints_review.open_days) ; przyjęta bez odpowiedzi do magazynu ;
                przyjęta, a grupa produktu nie potwierdziła « Widziane » po ${D.open_days} ${tw.plural(D.open_days, 'dniu', 'dniach', 'dniach')} ; produkt bez grupy produkcji. Reklamacje przyjęte : ${D.lookback_days} dni wstecz (complaints_review.lookback_days) — Ustawienia › Parametry.
                ${D.values ? '' : 'Kwoty widoczne tylko w profilu Finanse i CEO.'}</p>`;
    }

    function decisionCell(c) {
        const r = draft[c.key] ?? (draft[c.key] = {decision: c.decision ? c.decision.code : '', note: c.decision ? (c.decision.note || '') : ''});
        const answerable = c.status === 'REJECTED' || (c.status === 'ACCEPTED' && !!c.response);
        const seg = Object.entries(D.decisions).map(([k, l]) => `<button type="button" class="${r.decision === k ? 'on' : ''}" data-cr-dec="${esc(c.key)}" data-v="${k}" ${k === 'answered' && !answerable ? 'title="Zapisze się, gdy reklamacja ma decyzję i odpowiedź w « Reklamacje »"' : ''}>${esc(l)}</button>`).join('');
        const need = D.note_required.includes(r.decision);
        const ph = r.decision === 'watch' ? 'Notatka (wymagana) : co obserwujemy, do kiedy, kto' : (r.decision === 'nc' ? 'Notatka : numer NC, przyczyna, działanie' : 'Notatka (opcjonalnie)');
        const saved = c.decision ? `<small>${tw.pill(c.decision.label, DEC_CLS[c.decision.code] || '')} ${esc(c.decision.at)}${c.decision.by ? ' · ' + esc(c.decision.by) : ''}</small>` : '';
        const dirty = !c.decision || c.decision.code !== r.decision || (c.decision.note || '') !== r.note;
        return `<div style="display:grid;gap:6px;min-width:420px"><div class="tw-seg" style="justify-self:start">${seg}</div>
            <div style="display:flex;gap:6px"><input class="form-control form-control-sm" data-cr-note="${esc(c.key)}" maxlength="500" value="${esc(r.note)}" placeholder="${esc(ph)}" style="min-height:36px;border-radius:9px">
            <button type="button" class="tw-btn ghost sm" data-cr-save="${esc(c.key)}" ${!r.decision || !dirty || busy || (need && r.note.trim().length < 3) ? 'disabled' : ''}>Zapisz</button></div>
            <div style="display:flex;gap:4px 14px;flex-wrap:wrap">${listLink}${r.decision === 'nc' || (c.decision && c.decision.code === 'nc') ? ncLink : ''}</div>${saved}</div>`;
    }

    function step2() {
        const list = D.review, t = D.totals;
        if (!list.length) return tw.alert('Nic do wyjaśnienia : reklamacje mają decyzję w terminie, odpowiedź do magazynu i « Widziane » grupy.', 'ok', 'bi-check-circle');
        const rows = list.map(c => ({cls: c.waiting ? 'hl' : '', cells: [
            `<b>${esc(c.customer.name)}</b><small>zgłoszona ${esc(c.reported_label)}${c.accepted_label ? ' · przyjęta ' + esc(c.accepted_label) : ''}</small>`,
            `${what(c)}<small>${esc(c.reason)}${c.quantity !== null && c.type === 'PRODUCT' ? ' · ' + nf(c.quantity, c.quantity % 1 ? 2 : 0) + ' szt.' : ''}${D.values ? ' · ' + eur(c.value) : ''}</small>
                <div style="margin-top:4px">${tw.pill(c.status_label, ST_CLS[c.status] || '')}</div>
                ${c.comment ? `<small style="color:#7a6a55">« ${esc(c.comment)} »</small>` : ''}${c.response ? `<small class="tw-green">Odpowiedź : ${esc(c.response)}</small>` : ''}${ncs(c)}`,
            c.signals.length ? c.signals.map(s => `<div style="display:flex;gap:6px;align-items:baseline;margin:1px 0"><i class="bi bi-exclamation-circle ${SIG_CLS[s.code] === 'bad' ? 'tw-red' : ''}"></i><span><b>${esc(s.label)}</b> : ${esc(s.detail)}</span></div>`).join('') : '<span class="tw-green">Sygnał zniknął</span>',
            decisionCell(c)]}));
        return `<p class="tw-lead">Dla każdej reklamacji zapisz decyzję : <b>Odpowiedź / zamknięta</b> (sprawdzane : reklamacja odrzucona albo przyjęta z odpowiedzią do magazynu), <b>Niezgodność (NC)</b>
                — NC zakłada się w rejestrze NC — albo <b>Do obserwacji</b> z notatką. Krok 3 otworzy się, gdy wszystkie mają decyzję.</p>
            ${tw.tiles([{label: 'Do wyjaśnienia', value: String(t.review), sub: `${t.review} ${tw.plural(t.review, 'reklamacja', 'reklamacje', 'reklamacji')} z sygnałem`},
                {label: 'Z decyzją', value: `${t.resolved} z ${t.review}`, sub: t.waiting ? `zostało ${t.waiting}` : 'wszystkie wyjaśnione', cls: t.waiting ? 'warn' : 'ok'},
                {label: 'Odpowiedź / zamknięta', value: String(t.answered), sub: 'sprawdzone w reklamacji'}, {label: 'NC / do obserwacji', value: `${t.nc} / ${t.watch}`, sub: 'z notatką'}])}
            ${tw.table([{label: 'Klient'}, {label: 'Reklamacja'}, {label: 'Problem'}, {label: 'Decyzja'}], rows)}
            <p class="tw-note">Asystent nie zmienia statusu reklamacji i nie zakłada NC : decyzję i odpowiedź zapisz w « Reklamacje » (lista ; wykres i okno szczegółów reklamacji są tam), NC w rejestrze NC. Decyzje dotyczą ocenianego tygodnia — w przyszłym tygodniu reklamacja wróci, jeśli sygnał trwa.</p>`;
    }

    function step3() {
        const t = D.totals;
        if (D.done) return tw.done('Reklamacje klientów przejrzane · ' + (D.done.at || ''), D.done.summary + ' · zadanie zaliczone');
        const decided = D.review.filter(c => c.decision);
        const rec = D.recurring.length ? `<div class="tw-cap">Powtarzające się : ten sam klient i produkt w ${D.recurring_weeks} tygodniach (${D.recurring.length})</div>`
            + tw.table([{label: 'Klient'}, {label: 'Produkt'}, {label: 'Reklamacje', num: true}, {label: 'Dni'}, {label: 'Przyczyny'}],
                D.recurring.map(r => ({cls: r.in_week ? 'hl' : '', cells: [esc(r.customer.name), `<b>${esc(r.product.name)}</b>`, String(r.count), esc(r.dates.join(', ')), esc(r.reasons.join(' · '))]})))
            : tw.alert(`Brak powtarzających się reklamacji (ten sam klient i produkt) w ${D.recurring_weeks} tygodniach.`, 'ok', 'bi-check-circle');
        const types = D.breakdown.types.map(x => ({label: x.label, value: String(x.count), sub: `${x.accepted} przyjęte · ${x.rejected} odrzucone · ${x.open} otwarte`}));
        return `<p class="tw-lead">Gotowe : <b>${esc(D.summary)}</b>.</p>
            ${tw.tiles([{label: `Tydzień ${D.week_label}`, value: String(t.week), sub: `${t.week_customers} ${tw.plural(t.week_customers, 'klient', 'klientów', 'klientów')}`}].concat(types,
                [{label: 'Decyzje', value: `${t.resolved} z ${t.review}`, sub: `${t.answered} odpowiedź · ${t.nc} NC · ${t.watch} do obserwacji`, cls: 'ok'}]))}
            ${breakdown('Przyczyna (tydzień)', D.breakdown.reasons, 'Brak reklamacji w tygodniu.')}
            ${rec}
            ${decided.length ? `<div class="tw-cap">Decyzje</div>` + tw.table([{label: 'Zgłoszona'}, {label: 'Klient'}, {label: 'Produkt / typ'}, {label: 'Sygnały'}, {label: 'Decyzja'}, {label: 'Notatka'}],
                decided.map(c => ({cells: [esc(c.reported_label), esc(c.customer.name), what(c), sigs(c), tw.pill(c.decision.label, DEC_CLS[c.decision.code] || ''), esc(c.decision.note || '—')]}))) : ''}
            <p class="tw-note">« Zakończ » zalicza zadanie « Reklamacje klientów » na ten tydzień. Statusy reklamacji nie są zmieniane, NC nie są zakładane.</p>`;
    }

    function render() {
        const top = canFinish() ? reached : Math.min(reached, 2);
        if (step > top) step = top;
        const body = step === 1 ? step1() : (step === 2 ? step2() : step3());
        const next = step === 1 ? {label: 'Dalej : do wyjaśnienia', attr: 'data-cr-next'}
            : step === 2 ? {label: 'Dalej : zatwierdź', attr: 'data-cr-next', disabled: !canFinish()}
                : (D.done ? null : {label: 'Zakończ', attr: 'data-cr-finish', final: true, disabled: busy || !canFinish()});
        app.innerHTML = tw.frame({title: 'Reklamacje klientów', sub: `tydzień ${D.week_label} · krok ${step} z 3 · ok. 15 min`, steps: STEPS, step, reached: top, body,
            prev: step > 1 ? 'Wstecz' : null, next, later: !D.done && step < 3});
    }

    tw.bind(app, (n) => { step = n === -1 ? Math.max(1, step - 1) : n; render(); });
    app.addEventListener('input', (e) => {
        const n = e.target.closest('[data-cr-note]');
        if (!n) return;
        const key = n.dataset.crNote;
        (draft[key] ??= {decision: '', note: ''}).note = n.value;
        const c = D.review.find(x => x.key === key), r = draft[key];
        const btn = app.querySelector(`[data-cr-save="${CSS.escape(key)}"]`);
        const need = D.note_required.includes(r.decision);
        if (btn && c) btn.disabled = !r.decision || busy || (need && r.note.trim().length < 3) || (c.decision && c.decision.code === r.decision && (c.decision.note || '') === r.note);
    });
    app.addEventListener('click', async (e) => {
        if (e.target.closest('[data-cr-next]')) { step = Math.min(3, step + 1); reached = Math.max(reached, step); render(); return; }
        const r = e.target.closest('[data-cr-dec]');
        if (r) { const key = r.dataset.crDec; (draft[key] ??= {decision: '', note: ''}).decision = r.dataset.v; render(); app.querySelector(`[data-cr-note="${CSS.escape(key)}"]`)?.focus(); return; }
        const s = e.target.closest('[data-cr-save]');
        if (s) {
            const key = s.dataset.crSave, d = draft[key], c = D.review.find(x => x.key === key);
            if (!d || !d.decision || !c || busy) return;
            busy = true; s.disabled = true;
            const res = await tw.post('/ajax/franchise-complaints/review/decision', {id_complaint: c.id, decision: d.decision, note: d.note});
            busy = false;
            if (res.ok) { D = res.data; delete draft[key]; if (canFinish()) reached = Math.max(reached, 2); }
            render();
            return;
        }
        if (e.target.closest('[data-cr-finish]')) {
            if (busy) return;
            busy = true; render();
            const res = await tw.post('/ajax/franchise-complaints/review/finish', {});
            busy = false;
            if (res.ok) { at.notify(true, res.message); D = res.data; }
            render();
        }
    });
    render();
})();
