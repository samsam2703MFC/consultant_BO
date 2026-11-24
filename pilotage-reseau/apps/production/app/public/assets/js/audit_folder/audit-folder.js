// [406] Teczka do audytu i kontroli (Kierownik produkcji — co kwartał) : 1. état de l'ERP (assistants de contrôle en usage,
// points ouverts : HACCP, NC, réclamations, PPWR, SOP) et liste des documents à tenir prêts (papier et électronique, modifiable
// ici), 2. une décision par élément signalé (gotowe — w teczce, do uzupełnienia avec terme, nie dotyczy), 3. récapitulatif,
// validation et « teczka » PDF à imprimer → la tâche du trimestre se valide seule. Rien n'est changé dans l'ERP ni envoyé.
(() => {
    const app = document.getElementById('afApp');
    if (!app || !window.tw) return;
    const {esc} = tw;
    let D = JSON.parse(document.getElementById('afBoot').textContent);
    let step = 1, reached = 1, busy = false;
    const draft = {}, fix = {};
    const empty = () => ({id: 0, title: '', area: 'sanepid', format: 'both', owner: '', location: '', check_months: '3', note: ''});
    let form = empty();
    const STEPS = ['Przegląd', 'Do decyzji', 'Zatwierdź'];
    const DEC_CLS = {ready: 'ok', action: 'warn', not_applicable: ''};
    const ICON = {wizard: 'bi-ui-checks', erp: 'bi-database-exclamation', doc: 'bi-folder2-open'};
    const st = 'min-height:36px;border-radius:9px';
    const dmy = (d) => d ? `${d.slice(8, 10)}.${d.slice(5, 7)}.${d.slice(0, 4)}` : '—';
    const link = (l) => `<a class="tw-link" href="${at.root}${esc(l.href)}" target="_blank" rel="noopener" style="padding:0"><i class="bi bi-box-arrow-up-right"></i> ${esc(l.label)}</a>`;
    const links = (ls) => ls && ls.length ? `<div style="display:flex;gap:4px 14px;flex-wrap:wrap">${ls.map(link).join('')}</div>` : '';
    const pdf = `<a class="tw-btn ghost sm" href="${at.root}/ajax/audit-folder/pdf" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf"></i> Teczka PDF</a>`;
    const text = (f, k, v, ph, w = 220) => `<input class="form-control form-control-sm" data-af-f="${f}" data-k="${k}" value="${esc(v ?? '')}" placeholder="${esc(ph)}" style="max-width:${w}px;${st}">`;
    const select = (f, k, v, opts, w = 180) => `<select class="form-select form-select-sm" data-af-f="${f}" data-k="${k}" style="max-width:${w}px;${st}">${Object.entries(opts).map(([val, l]) => `<option value="${esc(val)}" ${String(v) === val ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select>`;
    const what = (i) => `<b><i class="bi ${ICON[i.kind] || 'bi-dot'}"></i> ${esc(i.title)}</b><small>${esc(i.kind_label)}${i.sub ? ' · ' + esc(i.sub) : ''}</small>`;
    const item = (key) => D.review.find(x => x.key === key);
    const bucket = (f) => f === 'form' ? form : (f.startsWith('fix:') ? fix[f.slice(4)] : draft[f.slice(4)]);
    const ok = {
        form: () => form.title.trim().length >= 3,
        fix: (key) => { const x = fix[key]; return !!x && (x.owner.trim() !== '' || x.location.trim() !== ''); },
        dec: (i) => {
            const r = draft[i.key], a = i.decision;
            if (!r || !r.decision) return false;
            if (r.decision === 'action' && (!r.due_on || r.note.trim().length < 3)) return false;
            if (r.decision === 'not_applicable' && r.note.trim().length < 3) return false;
            if (r.decision === 'ready' && i.kind !== 'doc' && i.codes.length && r.note.trim().length < 3) return false;
            return !a || a.code !== r.decision || (a.note || '') !== r.note || (a.due_on || '') !== r.due_on;
        },
    };
    function refresh() {
        app.querySelectorAll('[data-af-save-item]').forEach(b => { b.disabled = busy || !ok.form(); });
        app.querySelectorAll('[data-af-fix]').forEach(b => { b.disabled = busy || !ok.fix(b.dataset.afFix); });
        app.querySelectorAll('[data-af-dsave]').forEach(b => { const i = item(b.dataset.afDsave); b.disabled = busy || !i || !ok.dec(i); });
    }
    const pill = (i) => i.codes.length ? tw.pill(i.decision ? i.decision.label : 'do decyzji', i.decision ? DEC_CLS[i.decision.code] : 'bad') : tw.pill(i.decision ? i.decision.label : 'w porządku', 'ok');

    function step1() {
        const t = D.totals;
        const tiles = [{label: 'Asystenci kontroli', value: `${t.wizards_ok} z ${t.wizards}`, sub: `w swoim rytmie${t.wizards_unused ? ` · ${t.wizards_unused} bez zadania` : ''}`, cls: t.wizards_ok < t.wizards ? 'warn' : 'ok'},
            {label: 'Otwarte sprawy w ERP', value: String(t.erp_open), sub: 'HACCP, NC, reklamacje, PPWR, SOP', cls: t.erp_open ? 'warn' : 'ok'},
            {label: 'Dokumenty gotowe', value: `${t.docs_ok} z ${t.docs}`, sub: 'sprawdzone, z osobą i miejscem', cls: t.docs_ok < t.docs ? 'warn' : 'ok'},
            {label: 'Do decyzji', value: String(t.review), sub: t.review ? `${t.resolved} z ${t.review} z decyzją` : 'brak', cls: t.waiting ? 'warn' : 'ok'}];
        const checks = D.checks.map(i => ({cells: [what(i), esc(i.state), pill(i), links(i.links)]}));
        const docs = D.documents.map(i => ({cls: form.id === i.doc.id ? 'hl' : '', cells: [`<b>${esc(i.title)}</b><small>${esc(D.areas[i.doc.area])} · co ${i.doc.check_months} mies.</small>`, esc(D.formats[i.doc.format]),
            i.doc.owner ? esc(i.doc.owner) : '<span class="tw-red">—</span>', i.doc.location ? esc(i.doc.location) : '<span class="tw-red">—</span>',
            i.doc.last_checked ? `${esc(dmy(i.doc.last_checked))}<small>następny ${esc(dmy(i.doc.next_check))}</small>` : '<small>nie sprawdzony</small>', pill(i),
            `<button type="button" class="tw-link" style="padding:0" data-af-edit="${i.doc.id}" title="Zmień"><i class="bi bi-pencil"></i></button> <button type="button" class="tw-link" style="padding:0" data-af-remove="${i.doc.id}" title="Usuń z listy"><i class="bi bi-x-circle"></i></button>`]}));
        return `<p class="tw-lead"><b>${esc(D.quarter_label)}</b> : czy dokumentacja jest gotowa na audyt, kontrolę Sanepidu albo urzędu. Stan z ERP (asystenci kontroli i otwarte sprawy) i lista dokumentów do teczki
                — papier i elektronicznie, kto odpowiada i gdzie leżą. Każdy sygnał trafia do kroku 2 ; na końcu teczka PDF do wydruku.</p>
            ${tw.tiles(tiles)}
            ${tw.alert(`<div><b>Reguły</b> : asystent kontroli bez « Zakończ » dłużej niż jego rytm + <b>${D.settings.grace_days}</b> dni ; dokument do sprawdzenia w ciągu <b>${D.settings.alert_days}</b> dni (do ${esc(dmy(D.soon_until))}) — Ustawienia › Parametry, audit_folder.*.</div>
                ${D.unused.length ? `<div>Bez zadania stanowiska (nie oceniane) : ${esc(D.unused.join(', '))}.</div>` : ''}`, '', 'bi-info-circle', pdf)}
            <div class="tw-cap">Stan w ERP</div>
            ${tw.table([{label: 'Kontrola'}, {label: 'Stan'}, {label: ''}, {label: ''}], checks)}
            <div class="tw-cap">Dokumenty do teczki</div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin:6px 0 10px">
                ${text('form', 'title', form.title, 'dokument (np. Wyniki badań wody)', 260)} ${select('form', 'area', form.area, D.areas, 170)} ${select('form', 'format', form.format, D.formats, 190)}
                ${text('form', 'owner', form.owner, 'kto odpowiada', 160)} ${text('form', 'location', form.location, 'gdzie (segregator, folder, plik)', 220)}
                ${text('form', 'check_months', form.check_months, 'co ile mies.', 100)}
                <button type="button" class="tw-btn ghost sm" data-af-save-item ${busy || !ok.form() ? 'disabled' : ''}><i class="bi ${form.id ? 'bi-check2' : 'bi-plus-lg'}"></i> ${form.id ? 'Zapisz zmiany' : 'Dodaj dokument'}</button>
                ${form.id ? '<button type="button" class="tw-link" data-af-cancel>Anuluj</button>' : ''}</div>
            ${tw.table([{label: 'Dokument'}, {label: 'Forma'}, {label: 'Odpowiedzialny'}, {label: 'Gdzie'}, {label: 'Sprawdzony'}, {label: 'Stan'}, {label: ''}], docs, 'Lista dokumentów jest pusta — dodaj dokumenty do teczki.')}`;
    }

    function fixCell(i) {
        if (i.kind !== 'doc' || !i.codes.some(c => c === 'doc_no_owner' || c === 'doc_no_location')) return '';
        const x = fix[i.key] ?? (fix[i.key] = {owner: i.doc.owner || '', location: i.doc.location || ''});
        return `<div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">${text('fix:' + i.key, 'owner', x.owner, 'kto odpowiada', 160)}${text('fix:' + i.key, 'location', x.location, 'gdzie leży', 200)}
            <button type="button" class="tw-btn ghost sm" data-af-fix="${esc(i.key)}" ${busy || !ok.fix(i.key) ? 'disabled' : ''}>Zapisz dane</button></div>`;
    }

    function decisionCell(i) {
        const a = i.decision;
        const r = draft[i.key] ?? (draft[i.key] = {decision: a ? a.code : '', due_on: a && a.due_on ? a.due_on : '', note: a ? (a.note || '') : ''});
        const seg = Object.keys(D.decisions).map(k => `<button type="button" class="${r.decision === k ? 'on' : ''}" data-af-dec="${esc(i.key)}" data-v="${k}">${esc(D.decisions[k])}</button>`).join('');
        const ph = r.decision === 'action' ? 'Czego brakuje i kto uzupełnia (wymagane)' : (r.decision === 'not_applicable' ? 'Dlaczego nie dotyczy (wymagane)'
            : (r.decision === 'ready' && i.kind !== 'doc' && i.codes.length ? 'Dlaczego teczka jest kompletna mimo sygnału (wymagane)' : 'Notatka (opcjonalnie)'));
        const saved = a ? `<small>${tw.pill(a.label + (a.due_on ? ' do ' + dmy(a.due_on) : ''), DEC_CLS[a.code] || '')} ${esc(a.at)}${a.by ? ' · ' + esc(a.by) : ''}${a.note ? ' — ' + esc(a.note) : ''}</small>` : '';
        return `<div style="display:grid;gap:6px;min-width:430px">${fixCell(i)}<div class="tw-seg" style="justify-self:start">${seg}</div>
            <div style="display:flex;gap:6px">${r.decision === 'action' ? `<input type="date" class="form-control form-control-sm" data-af-f="dec:${esc(i.key)}" data-k="due_on" value="${esc(r.due_on)}" title="Termin uzupełnienia" style="max-width:160px;${st}">` : ''}
            <input class="form-control form-control-sm" data-af-f="dec:${esc(i.key)}" data-k="note" maxlength="500" value="${esc(r.note)}" placeholder="${esc(ph)}" style="${st}">
            <button type="button" class="tw-btn ghost sm" data-af-dsave="${esc(i.key)}" ${busy || !ok.dec(i) ? 'disabled' : ''}>Zapisz</button></div>
            ${links(i.links)}${i.reason ? `<small class="tw-red">${esc(i.reason)}</small>` : ''}${saved}</div>`;
    }

    function step2() {
        const list = D.review, t = D.totals;
        if (!list.length) return tw.alert('Nic do decyzji : asystenci w rytmie, brak otwartych spraw, dokumenty sprawdzone.', 'ok', 'bi-check-circle');
        const rows = list.map(i => ({cls: i.waiting ? 'hl' : '', cells: [what(i),
            i.signals.map(s => `<div style="display:flex;gap:6px;align-items:baseline;margin:1px 0"><i class="bi bi-exclamation-circle ${s.code === 'doc_due' ? '' : 'tw-red'}"></i><span><b>${esc(s.label)}</b>${s.detail ? ' : ' + esc(s.detail) : ''}</span></div>`).join('')
                + (i.signals.some(s => s.detail === i.state) ? '' : `<small>${esc(i.state)}</small>`), decisionCell(i)]}));
        return `<p class="tw-lead">Dla każdego elementu : <b>Gotowe — w teczce</b> (dokument : sprawdzony dziś — potrzebne osoba i miejsce ; sprawa w ERP : z powodem, jeśli sygnał zostaje),
                <b>Do uzupełnienia</b> (termin i co trzeba zrobić) albo <b>Nie dotyczy</b> z powodem.</p>
            ${tw.tiles([{label: 'Do decyzji', value: String(t.review), sub: `asystenci ${t.by_kind.wizard} · ERP ${t.by_kind.erp} · dokumenty ${t.by_kind.doc}`},
                {label: 'Z decyzją', value: `${t.resolved} z ${t.review}`, sub: t.waiting ? `zostało ${t.waiting}` : 'wszystkie z decyzją', cls: t.waiting ? 'warn' : 'ok'},
                {label: 'Gotowe', value: String(t.ready), sub: 'w teczce'}, {label: 'Do uzupełnienia / nie dotyczy', value: `${t.action} / ${t.not_applicable}`, sub: 'z terminem / z powodem'}])}
            ${tw.table([{label: 'Element'}, {label: 'Sygnał i stan'}, {label: 'Decyzja'}], rows)}
            <p class="tw-note">Asystent nie zmienia danych ERP : otwarte sprawy zamyka się w ich ekranach (linki). Zapisują się tylko lista dokumentów i decyzje.</p>`;
    }

    function step3() {
        const t = D.totals;
        if (D.done) return tw.done('Teczka gotowa · ' + (D.done.at || ''), D.done.summary + ' · zadanie zaliczone') + `<div style="margin-top:10px">${pdf}</div>`;
        const todo = D.review.filter(i => i.decision && i.decision.code === 'action').map(i => ({cells: [what(i), esc(dmy(i.decision.due_on)), `<small>${esc(i.decision.note || '')}</small>`]}));
        return `<p class="tw-lead">Gotowe : <b>${esc(D.summary)}</b>.</p>
            ${tw.tiles([{label: 'Gotowe', value: String(t.ready), sub: 'w teczce'}, {label: 'Do uzupełnienia', value: String(t.action), sub: 'z terminem'},
                {label: 'Nie dotyczy', value: String(t.not_applicable), sub: 'z powodem'}, {label: 'Decyzje', value: `${t.resolved} z ${t.review}`, sub: 'wszystkie elementy', cls: 'ok'}])}
            ${todo.length ? '<div class="tw-cap">Do uzupełnienia</div>' + tw.table([{label: 'Element'}, {label: 'Termin'}, {label: 'Co i kto'}], todo) : ''}
            <div style="margin:10px 0">${pdf}</div>
            <p class="tw-note">« Zakończ » zalicza zadanie « Teczka do audytu i kontroli » na ten kwartał. Teczkę PDF można wydrukować w każdej chwili.</p>`;
    }

    function render() {
        const top = D.can_finish ? reached : Math.min(reached, 2);
        if (step > top) step = top;
        const body = step === 1 ? step1() : (step === 2 ? step2() : step3());
        const next = step === 1 ? {label: 'Dalej : do decyzji', attr: 'data-af-next'}
            : step === 2 ? {label: 'Dalej : zatwierdź', attr: 'data-af-next', disabled: !D.can_finish}
                : (D.done ? null : {label: 'Zakończ', attr: 'data-af-finish', final: true, disabled: busy || !D.can_finish});
        app.innerHTML = tw.frame({title: 'Teczka do audytu i kontroli', sub: `${D.quarter_label} · krok ${step} z 3 · ok. 60 min`, steps: STEPS, step, reached: top, body,
            prev: step > 1 ? 'Wstecz' : null, next, later: !D.done && step < 3});
    }

    const post = async (url, body, after) => {
        busy = true; render();
        const res = await tw.post(url, body);
        busy = false;
        if (res.ok) { D = res.data; if (after) after(); }
        render();
    };
    tw.bind(app, (n) => { step = n === -1 ? Math.max(1, step - 1) : n; render(); });
    const onField = (e) => { const el = e.target.closest('[data-af-f]'); if (!el) return; const b = bucket(el.dataset.afF); if (b) b[el.dataset.k] = el.value; refresh(); };
    app.addEventListener('input', onField);
    app.addEventListener('change', onField);
    app.addEventListener('click', async (e) => {
        if (busy) return;
        if (e.target.closest('[data-af-next]')) { step = Math.min(3, step + 1); reached = Math.max(reached, step); render(); return; }
        const r = e.target.closest('[data-af-dec]');
        if (r) { draft[r.dataset.afDec].decision = r.dataset.v; render(); return; }
        const ed = e.target.closest('[data-af-edit]');
        if (ed) {
            const i = D.documents.find(x => x.doc.id === Number(ed.dataset.afEdit));
            if (i) form = {id: i.doc.id, title: i.doc.title, area: i.doc.area, format: i.doc.format, owner: i.doc.owner || '', location: i.doc.location || '', check_months: String(i.doc.check_months), note: i.doc.note || ''};
            render(); return;
        }
        if (e.target.closest('[data-af-cancel]')) { form = empty(); render(); return; }
        if (e.target.closest('[data-af-save-item]')) return post('/ajax/audit-folder/item', form, () => { form = empty(); });
        const rm = e.target.closest('[data-af-remove]');
        if (rm) { if (window.confirm('Usunąć ten dokument z listy teczki ?')) post('/ajax/audit-folder/item/remove', {id: Number(rm.dataset.afRemove)}); return; }
        const fx = e.target.closest('[data-af-fix]');
        if (fx) {
            const key = fx.dataset.afFix, i = item(key), x = fix[key];
            if (!i || !x) return;
            return post('/ajax/audit-folder/item', {...i.doc, check_months: String(i.doc.check_months), owner: x.owner, location: x.location}, () => { delete fix[key]; });
        }
        const ds = e.target.closest('[data-af-dsave]');
        if (ds) {
            const key = ds.dataset.afDsave, d = draft[key];
            if (!d || !d.decision) return;
            return post('/ajax/audit-folder/decision', {key, decision: d.decision, due_on: d.due_on, note: d.note}, () => { delete draft[key]; });
        }
        if (e.target.closest('[data-af-finish]')) {
            busy = true; render();
            const res = await tw.post('/ajax/audit-folder/finish', {});
            busy = false;
            if (res.ok) { at.notify(true, res.message); D = res.data; }
            render();
        }
    });
    render();
})();
