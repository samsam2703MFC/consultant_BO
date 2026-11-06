// Niezgodności miesiąca [247] (makieta 21) : 1. lista NC miesiąca (+ starsze otwarte), 2. każda otwarta NC : przyczyna,
// działanie, kto, do kiedy, 3. dostawcy (NC w 3 mies., ocena) → audyt kwartału, e-mail ; « Zatwierdź miesiąc ».
(() => {
    const app = document.getElementById('nrApp');
    if (!app || !window.tw) return;
    const {esc, nf} = tw;
    let D = JSON.parse(document.getElementById('nrBoot').textContent);
    let step = 1, reached = 1, idx = 0, busy = false;
    const STEPS = ['Lista', 'Przyczyna i działanie', 'Dostawcy'];
    const form = {};   // id NC => {cause_cat, corrective_action, owner_user, due_date, status}
    const who = (r) => [r.material || r.product, r.supplier || r.group].filter(Boolean).join(' · ') || '—';
    const SEV = {minor: '', major: 'bad', critical: 'bad'};
    const ST = {open: ['otwarta', 'warn'], in_progress: ['w toku', 'warn'], closed: ['zamknięta', 'ok']};

    function step1() {
        const t = D.totals, src = t.by_source;
        const parts = Object.entries(D.sources).filter(([k]) => src[k]).map(([k, l]) => `${src[k]} ${l.toLowerCase()}`).join(' · ') || 'brak';
        const rows = D.rows.map(r => ({cls: r.needs_action ? 'hl' : '', cells: [`<b>${esc(r.number)}</b>${r.in_month ? '' : '<small>starsza, otwarta</small>'}`, tw.dm(r.date), esc(D.sources[r.source] || r.source),
            esc(who(r)), esc(r.description), tw.pill(D.severities[r.severity] || r.severity, SEV[r.severity] || ''), r.cost === null ? '—' : tw.zl(r.cost), tw.pill(...(ST[r.status] || [r.status, '']))]}));
        return `${tw.tiles([{label: D.month.label, value: `${t.count} NC`, sub: parts},
                {label: 'Koszt NC', value: tw.zl(t.cost), sub: 'koszt wpisany przy NC'},
                {label: 'Bez działania', value: String(t.open), sub: t.older_open ? `w tym ${t.older_open} starsze otwarte` : 'otwarte bez przyczyny i działania', cls: t.open ? 'warn' : 'ok'},
                {label: t.top ? t.top.name : 'Dostawcy', value: t.top ? `${t.top.nc3} NC` : '0', sub: t.top ? 'w 3 miesiącach → krok 3' : 'bez NC dostaw w 3 mies.'}])}
            ${tw.table([{label: 'Nr'}, {label: 'Data'}, {label: 'Źródło'}, {label: 'Co · kto'}, {label: 'Opis'}, {label: 'Waga'}, {label: 'Koszt', num: true}, {label: 'Stan'}], rows,
                `W miesiącu ${D.month.label} nie ma niezgodności.`)}
            <p class="tw-note">Źródła : kontrola przyjęć dnia, tablety (produkcja), reklamacje klientów, wpisy ręczne. <a href="${at.root}/purchasing/nc">Wszystkie niezgodności</a>.</p>`;
    }

    function step2() {
        const ids = D.open;
        if (!ids.length) return tw.alert('Każda niezgodność ma przyczynę i działanie — przejdź do dostawców.', 'ok', 'bi-check-circle');
        idx = Math.min(idx, ids.length - 1);
        const r = D.rows.find(x => x.id === ids[idx]);
        const f = form[r.id] ??= {cause_cat: r.cause_cat || '', corrective_action: r.action || '', owner_user: r.owner || '', due_date: r.due_date || '', status: 'in_progress'};
        const chips = Object.entries(D.causes).map(([k, l]) => `<button type="button" class="tw-chip ${f.cause_cat === k ? 'on' : ''}" data-nr-cause="${k}">${esc(l)}</button>`).join('');
        const users = `<option value="">— kto —</option>` + D.users.map(u => `<option value="${u.id}" ${String(f.owner_user) === String(u.id) ? 'selected' : ''}>${esc(u.name)}</option>`).join('');
        const photo = r.photo_id ? `<a href="${at.root}/purchasing/nc/photo/${r.photo_id}" target="_blank" rel="noopener"><img class="tw-photo" src="${at.root}/purchasing/nc/photo/${r.photo_id}" alt="zdjęcie NC"></a>` : '<span class="tw-photo"><i class="bi bi-image"></i></span>';
        return `<div style="display:flex;gap:12px;align-items:center;margin-bottom:12px">${photo}<div><b>${esc(r.number)} · ${esc(who(r))}</b><br>
                <small class="tw-note">${tw.dm(r.date)} · ${esc(r.description)}${r.quantity !== null ? ' · ' + nf(r.quantity, 1) + ' ' + esc(r.unit || '') : ''}${r.photos > 1 ? ' · ' + r.photos + ' zdjęć' : ''}</small></div>
                <span class="tw-sp"></span>${tw.pill((idx + 1) + ' z ' + ids.length)}</div>
            <div class="tw-field"><label>Przyczyna</label><div class="tw-chips">${chips}</div></div>
            <div class="tw-field"><label for="nrAct">Działanie korygujące</label><textarea id="nrAct" class="form-control" rows="2" maxlength="5000" data-nr-f="corrective_action" placeholder="co zmieniamy, żeby się nie powtórzyło">${esc(f.corrective_action)}</textarea></div>
            <div class="tw-grid2"><div class="tw-field"><label for="nrWho">Kto</label><select id="nrWho" class="form-select" data-nr-f="owner_user">${users}</select></div>
                <div class="tw-field"><label for="nrDue">Do kiedy</label><input id="nrDue" type="date" class="form-control" data-nr-f="due_date" value="${esc(f.due_date)}"></div></div>
            <label class="tw-ck"><input type="checkbox" data-nr-close ${f.status === 'closed' ? 'checked' : ''}><span>Zamknij niezgodność<small>działanie już zrobione</small></span></label>`;
    }

    function step3() {
        const s = D.suppliers;
        const rows = s.map(x => {
            const a = x.audit;
            const audit = a ? (a.result ? tw.pill('audyt : ' + (D.results[a.result] || a.result), a.result === 'failed' ? 'bad' : (a.result === 'remarks' ? 'warn' : 'ok')) : tw.pill('audyt ' + tw.dm(a.planned_on), 'warn'))
                : (x.propose_audit ? `<button type="button" class="tw-btn ghost sm" data-nr-plan="${x.id}">Zaplanuj audyt</button>` : '<span class="tw-note">obserwuj</span>');
            const mail = x.month_nc && x.email ? `<button type="button" class="tw-btn ghost sm" data-nr-mail="${x.id}"><i class="bi bi-envelope"></i> E-mail z NC</button>` : '';
            return {cls: x.propose_audit ? 'hl' : '', cells: [`<b>${esc(x.name)}</b>`, String(x.nc3), x.cost3 ? tw.zl(x.cost3) : '—', x.grade ? tw.pill(x.grade, x.grade === 'C' ? 'bad' : (x.grade === 'B' ? 'warn' : 'ok')) : '—', audit, mail]};
        });
        const due = D.month.quarter_end ? D.suppliers.filter(x => x.audit && !x.audit.result) : [];
        const results = due.map(x => `<div class="tw-ck" style="cursor:default"><span><b>${esc(x.name)}</b><small>audyt ${tw.dm(x.audit.planned_on)}</small></span><span class="tw-sp"></span>
            <span class="tw-seg">${Object.entries(D.results).map(([k, l]) => `<button type="button" data-nr-result="${x.id}" data-v="${k}">${esc(l)}</button>`).join('')}</span></div>`).join('');
        return `${tw.table([{label: 'Dostawca'}, {label: 'NC · 3 mies.', num: true}, {label: 'Koszt', num: true}, {label: 'Ocena'}, {label: 'Audyt'}, {label: ''}], rows, 'Brak niezgodności dostaw w ostatnich 3 miesiącach.')}
            <p class="tw-note">Audyt proponowany : co najmniej 3 NC dostaw w 3 miesiącach albo ocena C (ocena dostawców co kwartał). E-mail idzie w języku dostawcy, ze zdjęciami NC.</p>
            ${D.month.quarter_end ? tw.alert(`<b>Koniec kwartału (${esc(D.month.quarter)}) :</b> wpisz wynik audytów dostawców — zastępuje osobne zadanie « Audyt dostawcy ».`, 'info', 'bi-info-circle') + results
                : tw.alert('Co kwartał (marzec, czerwiec, wrzesień, grudzień) ten krok zawiera wynik audytów dostawców.', 'info', 'bi-info-circle')}
            ${D.done ? tw.done('Miesiąc zatwierdzony', D.done.summary + ' · zadanie zaliczone') : ''}`;
    }

    function render() {
        const body = step === 1 ? step1() : (step === 2 ? step2() : step3());
        let next;
        if (step === 1) next = {label: D.open.length ? `Dalej : ${D.open.length} bez działania` : 'Dalej : dostawcy', attr: 'data-nr-next'};
        else if (step === 2) next = D.open.length ? {label: 'Zapisz i następna', attr: 'data-nr-save', disabled: busy} : {label: 'Dalej : dostawcy', attr: 'data-nr-next'};
        else next = D.done ? null : {label: 'Zatwierdź miesiąc', attr: 'data-nr-finish', final: true, disabled: busy || D.open.length > 0};
        const extra = step === 2 && D.open.length > 1 ? `<button type="button" class="tw-link" data-nr-skip>Pomiń</button>` : '';
        app.innerHTML = tw.frame({title: 'Niezgodności miesiąca', sub: `${D.month.label} · krok ${step} z 3 · ok. 30 min`, steps: STEPS, step, reached, body, prev: step > 1 ? 'Wstecz' : null, next, extra, later: !D.done});
    }

    tw.bind(app, (n) => { step = n === -1 ? Math.max(1, step - 1) : n; render(); });
    const cur = () => D.rows.find(x => x.id === D.open[idx]);
    app.addEventListener('input', (e) => { const k = e.target.dataset.nrF; if (k && cur()) form[cur().id][k] = e.target.value; });
    app.addEventListener('change', (e) => {
        const k = e.target.dataset.nrF; if (k && cur()) form[cur().id][k] = e.target.value;
        if (e.target.matches('[data-nr-close]') && cur()) form[cur().id].status = e.target.checked ? 'closed' : 'in_progress';
    });
    app.addEventListener('click', async (e) => {
        if (e.target.closest('[data-nr-next]')) { step = Math.min(3, step + 1); reached = Math.max(reached, step); render(); return; }
        const c = e.target.closest('[data-nr-cause]');
        if (c && cur()) { form[cur().id].cause_cat = c.dataset.nrCause; render(); return; }
        if (e.target.closest('[data-nr-skip]')) { idx = (idx + 1) % D.open.length; render(); return; }
        if (busy) return;
        const run = async (url, body, after) => { busy = true; render(); const res = await tw.post(url, {m: D.month.key, ...body}); busy = false; if (res.ok) { at.notify(true, res.message); if (res.data) D = res.data; after && after(); } render(); };
        if (e.target.closest('[data-nr-save]')) { const r = cur(); await run(`/ajax/purchasing/nc-review/${r.id}/action`, form[r.id], () => { delete form[r.id]; if (!D.open.length) { step = 3; reached = 3; } }); return; }
        const p = e.target.closest('[data-nr-plan]');
        if (p) { const x = D.suppliers.find(s => s.id === Number(p.dataset.nrPlan)); await run('/ajax/purchasing/nc-review/audit', {supplier_id: x.id, reason: `${x.nc3} NC w 3 mies.${x.grade ? ' · ocena ' + x.grade : ''}`}); return; }
        const rs = e.target.closest('[data-nr-result]');
        if (rs) { const x = D.suppliers.find(s => s.id === Number(rs.dataset.nrResult)); await run('/ajax/purchasing/nc-review/audit', {supplier_id: x.id, planned_on: x.audit.planned_on, done_on: new Date().toISOString().slice(0, 10), result: rs.dataset.v, reason: null}); return; }
        const ml = e.target.closest('[data-nr-mail]');
        if (ml) { const x = D.suppliers.find(s => s.id === Number(ml.dataset.nrMail)); if (!confirm(`Wysłać e-mail do « ${x.name} » z niezgodnościami miesiąca ${D.month.label} ?`)) return; await run('/ajax/purchasing/nc-review/mail', {supplier_id: x.id}); return; }
        if (e.target.closest('[data-nr-finish]')) await run('/ajax/purchasing/nc-review/finish', {});
    });
    render();
})();
