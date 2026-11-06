// Zebranie produkcyjne [247] (makieta 20) : 1. tydzień w liczbach (śledzenie produkcji, reklamacje), 2. sprawy i decyzje
// (działania z poprzednich zebrań i przeglądów, nowe sprawy, obecni), 3. protokół → zadanie zrobione (+ tablety).
(() => {
    const app = document.getElementById('mwApp');
    if (!app || !window.tw) return;
    const {esc, nf} = tw;
    let D = JSON.parse(document.getElementById('mwBoot').textContent);
    let step = D.final ? 3 : 1, reached = D.final ? 3 : 1, busy = false;
    const qs = new URLSearchParams(location.search);
    const sent = qs.has('sent') ? Number(qs.get('sent')) : null;
    const STEPS = ['Tydzień w liczbach', 'Sprawy i decyzje', 'Protokół'];
    const people = new Set(D.participants);
    const close = new Set(), reopen = new Set(), del = new Set();
    let items = [{topic: '', decision: '', user_id: '', due_date: ''}];
    let notes = D.topics.other || '';
    const fu = D.follow_up || {groups: [], total: {}, machines: []};
    const pct = (v) => v === null || v === undefined ? '—' : nf(v, 1) + ' %';
    const dm = tw.dm;

    function flags(g) {
        const f = [];
        if ((g.waste_pct ?? 0) > 2) f.push(tw.pill('straty', 'bad'));
        if (g.plan_pct !== null && g.plan_pct < 90) f.push(tw.pill('plan', 'warn'));
        if (g.unclosed > 0) f.push(tw.pill(g.unclosed + ' dni niezatwierdzone', 'warn'));
        return f.join(' ');
    }

    function step1() {
        const t = fu.total || {};
        const oee = fu.machines.filter(m => m.oee !== null && m.oee !== undefined);
        const avgOee = oee.length ? oee.reduce((s, m) => s + Number(m.oee), 0) / oee.length : null;
        const c = D.complaints;
        const rows = fu.groups.map(g => ({cls: flags(g) ? 'hl' : '', cells: [`<b>${esc(g.name)}</b>`, pct(g.plan_pct), (g.waste_pct ?? 0) > 2 ? `<span class="tw-red">${pct(g.waste_pct)}</span>` : pct(g.waste_pct),
            nf(g.produced), nf(g.waste), `${g.closed} z ${g.days}`, flags(g)]}));
        return `<p class="tw-lead">Liczby z tabletów za ${dm(fu.from)} – ${dm(fu.to)} są już policzone — omów tylko to, co odstaje.
                <a href="${at.root}/production/follow-up?from=${esc(fu.from)}&to=${esc(fu.to)}">Szczegóły w śledzeniu produkcji</a>.</p>
            ${tw.tiles([{label: 'Plan zrobiony', value: pct(t.plan_pct), sub: `${nf(t.produced)} z ${nf(t.planned)} szt.`},
                {label: 'Straty', value: pct(t.waste_pct), sub: `${nf(t.waste)} szt.`, cls: (t.waste_pct ?? 0) > 2 ? 'warn' : ''},
                {label: 'Reklamacje', value: c ? String(c.count) : '—', sub: c ? `${c.open} otwarte` : 'brak danych'},
                {label: 'TRS maszyn', value: avgOee === null ? '—' : nf(avgOee * (avgOee <= 1 ? 100 : 1)) + ' %', sub: `${oee.length} ${tw.plural(oee.length, 'maszyna', 'maszyny', 'maszyn')} z tabletów`},
                {label: 'Niezgodności', value: String(t.nc ?? 0), sub: 'z tabletów i przyjęć'}], 5)}
            ${tw.table([{label: 'Grupa'}, {label: 'Plan zrobiony', num: true}, {label: 'Straty', num: true}, {label: 'Zrobione szt.', num: true}, {label: 'Straty szt.', num: true}, {label: 'Dni zatwierdzone', num: true}, {label: 'Do omówienia'}], rows,
                'Brak danych z tabletów w tym okresie.')}`;
    }

    function step2() {
        const carried = D.carried.map(a => {
            const done = (a.status === 'done' && !reopen.has(a.id)) || close.has(a.id);
            return `<label class="tw-ck"><input type="checkbox" data-mw-carried="${a.id}" ${done ? 'checked' : ''}><span>${esc(a.title)}<small>${esc(a.user_name || '—')}${a.due_date ? ' · do ' + dm(a.due_date) : ''} · zebranie ${dm(a.meeting_date)}</small></span></label>`;
        }).join('');
        const reviews = D.review_actions.map(a => `<label class="tw-ck"><input type="checkbox" data-mw-review="${a.id}" ${a.status === 'done' ? 'checked' : ''}><span>${esc(a.title)}${a.goal ? ' — cel : ' + esc(a.goal) : ''}<small>${esc(a.user_name || a.group_name || '—')}${a.due_date ? ' · do ' + dm(a.due_date) : ''} · przegląd ${esc(a.period)}</small></span></label>`).join('');
        const own = D.actions.filter(a => !del.has(a.id)).map(a => `<div class="tw-ck" style="cursor:default"><i class="bi bi-check2-square" style="color:#8b857e"></i><span>${esc(a.title)}<small>${esc(a.user_name || '—')}${a.due_date ? ' · do ' + dm(a.due_date) : ''}</small></span><span class="tw-sp"></span><button type="button" class="tw-link" data-mw-del="${a.id}">usuń</button></div>`).join('');
        const userOpts = (sel) => `<option value="">— kto —</option>` + D.users.map(u => `<option value="${u.id}" ${String(sel) === String(u.id) ? 'selected' : ''}>${esc(u.name)}${u.position_name ? ' · ' + esc(u.position_name) : ''}</option>`).join('');
        const rows = items.map((it, i) => `<tr data-mw-item="${i}"><td><input class="form-control" data-f="topic" maxlength="120" value="${esc(it.topic)}" placeholder="np. Rogal : 4 % strat"></td>
            <td><input class="form-control" data-f="decision" maxlength="170" value="${esc(it.decision)}" placeholder="np. karta temperatury pieca"></td>
            <td><select class="form-select" data-f="user_id">${userOpts(it.user_id)}</select></td><td><input type="date" class="form-control" data-f="due_date" value="${esc(it.due_date)}"></td></tr>`).join('');
        const chips = D.users.map(u => `<button type="button" class="tw-chip ${people.has(u.id) ? 'on' : ''}" data-mw-person="${u.id}">${esc(u.name)}</button>`).join('');
        return `${D.carried.length ? `<div class="tw-cap" style="margin-top:0">Działania z poprzednich zebrań</div>${carried}` : ''}
            ${D.review_actions.length ? `<div class="tw-cap">Działania z przeglądów kwartału</div>${reviews}` : ''}
            ${own ? `<div class="tw-cap">Zapisane w tym protokole</div>${own}` : ''}
            <div class="tw-cap">Nowe sprawy i decyzje</div>
            <div class="table-responsive"><table class="tw-tb form"><thead><tr><th>Sprawa</th><th>Decyzja</th><th>Kto</th><th>Do kiedy</th></tr></thead><tbody>${rows}</tbody></table></div>
            <button type="button" class="tw-link" data-mw-add><i class="bi bi-plus-circle"></i> Dodaj sprawę</button>
            <div class="tw-cap">Obecni (${people.size})</div><div class="tw-chips">${chips}</div>
            <div class="tw-field" style="margin-top:14px"><label for="mwNotes">Notatki (opcjonalnie)</label><textarea id="mwNotes" class="form-control" rows="2" maxlength="4000">${esc(notes)}</textarea></div>`;
    }

    function newActions() {
        return items.filter(it => it.topic.trim() || it.decision.trim()).map(it => ({title: [it.topic.trim(), it.decision.trim()].filter(Boolean).join(' — '), user_id: it.user_id || null, due_date: it.due_date || ''}));
    }

    function step3() {
        if (D.final) {
            return tw.done('Protokół zapisany', `${D.actions.length} ${tw.plural(D.actions.length, 'działanie', 'działania', 'działań')} · zadanie zrobione`)
                + (sent !== null ? `<p class="tw-note">${sent ? 'Decyzje wysłane na tablety grup (' + sent + ').' : 'Bez wiadomości na tablety.'}</p>` : '')
                + (D.form_id ? `<p class="tw-note"><a href="${at.root}/task-forms/${D.form_id}"><i class="bi bi-printer"></i> Podgląd i druk protokołu</a> · <a href="${at.root}/task-forms">Wszystkie protokoły</a></p>` : '');
        }
        const n = newActions().length + D.actions.filter(a => !del.has(a.id)).length;
        return `<p class="tw-lead">Protokół powstaje sam z kroków 1–2 : liczby tygodnia, ${n} ${tw.plural(n, 'decyzja', 'decyzje', 'decyzji')}, ${people.size} ${tw.plural(people.size, 'osoba obecna', 'osoby obecne', 'osób obecnych')}.</p>
            ${tw.check('tablets', n > 0, 'Decyzje na tablety grup', 'baner « Od kierownika produkcji » — Przeczytane (PIN)')}
            <div class="tw-ck" style="cursor:default"><i class="bi bi-arrow-repeat" style="color:#8b857e"></i><span>Działania wracają na następnym zebraniu<small>kto i do kiedy — w kroku 2 następnego protokołu</small></span></div>
            <p class="tw-note">Pełny formularz z 6 tematami (BHP, jakość, zespół…) : <a href="?classic=1">otwórz</a>.</p>`;
    }

    function payload(final) {
        const topics = {...D.topics, other: notes};
        return {meeting_date: D.meeting_date, participants: [...people], guests: D.guests, topics, new_actions: newActions(),
            close_actions: [...close], reopen_actions: [...reopen], delete_actions: [...del], period_from: fu.from, period_to: fu.to, final};
    }

    async function save(final) {
        if (busy) return;
        busy = true; render();
        const res = await tw.post(`/ajax/my-tasks/${D.occurrence_id}/form`, payload(final), 'PUT');
        busy = false;
        if (!res.ok) { render(); return; }
        if (final) {
            D.final = true; D.form_id = res.id;
            let n = 0;
            if (app.dataset.tablets === '1') {
                const t = await tw.post(`/ajax/my-tasks/${D.occurrence_id}/form/tablets`, {});
                n = t.ok ? t.sent : 0;
            }
            location.href = location.pathname + '?sent=' + n;
            return;
        }
        at.notify(true, res.message);
        location.reload();
    }

    function render() {
        const body = step === 1 ? step1() : (step === 2 ? step2() : step3());
        const next = step < 3 ? {label: step === 1 ? 'Dalej : sprawy i decyzje' : 'Dalej : protokół', attr: 'data-mw-next'}
            : (D.final ? null : {label: 'Zapisz protokół i zalicz', attr: 'data-mw-final', final: true, disabled: busy});
        const extra = !D.final && step > 1 ? `<button type="button" class="tw-link" data-mw-draft ${busy ? 'disabled' : ''}>Zapisz szkic</button>` : '';
        app.innerHTML = tw.frame({title: 'Zebranie produkcyjne', sub: `${D.period_key} · ${dm(D.meeting_date)} · krok ${step} z 3 · ok. 30 min`, steps: STEPS, step, reached,
            body, prev: step > 1 && !D.final ? 'Wstecz' : null, next, extra, later: false});
    }

    tw.bind(app, (n) => { step = n === -1 ? Math.max(1, step - 1) : n; render(); });
    app.addEventListener('input', (e) => {
        const tr = e.target.closest('[data-mw-item]');
        if (tr && e.target.dataset.f) items[Number(tr.dataset.mwItem)][e.target.dataset.f] = e.target.value;
        if (e.target.id === 'mwNotes') notes = e.target.value;
    });
    app.addEventListener('change', async (e) => {
        const tr = e.target.closest('[data-mw-item]');
        if (tr && e.target.dataset.f) items[Number(tr.dataset.mwItem)][e.target.dataset.f] = e.target.value;
        const c = e.target.closest('[data-mw-carried]');
        if (c) {
            const id = Number(c.dataset.mwCarried), a = D.carried.find(x => x.id === id);
            close.delete(id); reopen.delete(id);
            if (c.checked && a.status !== 'done') close.add(id);
            if (!c.checked && a.status === 'done') reopen.add(id);
        }
        const r = e.target.closest('[data-mw-review]');
        if (r) {
            const res = await tw.post(`/ajax/wizard-actions/${r.dataset.mwReview}`, {done: r.checked});
            if (res.ok) { const a = D.review_actions.find(x => x.id === Number(r.dataset.mwReview)); if (a) a.status = r.checked ? 'done' : 'open'; } else r.checked = !r.checked;
        }
        if (e.target.dataset.twCk === 'tablets') app.dataset.tablets = e.target.checked ? '1' : '0';
    });
    app.addEventListener('click', (e) => {
        if (e.target.closest('[data-mw-next]')) { step = Math.min(3, step + 1); reached = Math.max(reached, step); render(); if (step === 3) app.dataset.tablets = app.querySelector('[data-tw-ck="tablets"]')?.checked ? '1' : '0'; return; }
        if (e.target.closest('[data-mw-add]')) { items.push({topic: '', decision: '', user_id: '', due_date: ''}); render(); return; }
        const p = e.target.closest('[data-mw-person]');
        if (p) { const id = Number(p.dataset.mwPerson); people.has(id) ? people.delete(id) : people.add(id); render(); return; }
        const d = e.target.closest('[data-mw-del]');
        if (d) { del.add(Number(d.dataset.mwDel)); render(); return; }
        if (e.target.closest('[data-mw-draft]')) { save(false); return; }
        if (e.target.closest('[data-mw-final]')) save(true);
    });
    render();
})();
