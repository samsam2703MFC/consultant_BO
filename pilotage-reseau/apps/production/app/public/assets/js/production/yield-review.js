// Wydajność i straty kwartału [247] (makieta 23) : 1. straty (grupy, przyczyny, produkty), 2. wydajność (szt./h, plan,
// TRS), 3. najwyżej 3 działania (co, cel, kto, grupa, do kiedy) → zebranie produkcyjne + tablety ; zadanie zaliczone.
(() => {
    const app = document.getElementById('yrApp');
    if (!app || !window.tw) return;
    const {esc, nf} = tw;
    let D = JSON.parse(document.getElementById('yrBoot').textContent);
    let step = 1, reached = 1, busy = false;
    const STEPS = ['Straty', 'Wydajność', 'Działania'];
    const pct = (v) => v === null || v === undefined ? '—' : nf(v, 1) + ' %';
    const pp = (a, b) => a === null || b === null || a === undefined || b === undefined ? '' : tw.signed(Math.round((a - b) * 10) / 10, 1, ' pp');
    let acts = D.actions.length ? D.actions.map(a => ({title: a.title, goal: a.goal || '', user_id: '', group_id: '', due_date: a.due_date || ''})) : [];
    if (!acts.length) {
        const worst = [...D.groups].sort((a, b) => (b.waste_pct ?? 0) - (a.waste_pct ?? 0))[0];
        const reason = D.reasons[0];
        acts = [{title: worst && reason ? `${worst.name} : ${reason.label.toLowerCase()} — ` : '', goal: worst && worst.waste_pct ? `straty < ${nf(Math.max(1, Math.floor(worst.waste_pct - 0.5)), 0)} %` : '', user_id: '', group_id: worst ? String(worst.id) : '', due_date: ''}];
    }

    function step1() {
        const t = D.totals;
        const maxQ = Math.max(1, ...D.reasons.map(r => r.q));
        const rows = D.groups.map(g => ({cls: t.worst && g.name === t.worst.name ? 'hl' : '', cells: [`<b>${esc(g.name)}</b>`,
            (g.waste_pct ?? 0) > 2.5 ? `<span class="tw-red">${pct(g.waste_pct)}</span>` : pct(g.waste_pct), nf(g.waste), tw.zl(g.value), pct(g.waste_pct_prev), pp(g.waste_pct, g.waste_pct_prev)]}));
        const bars = D.reasons.map(r => `<div class="tw-hbar"><span>${esc(r.label)}</span><span class="tr"><i style="width:${r.q / maxQ * 100}%"></i></span><span class="num">${nf(r.q)} szt.</span></div>`).join('');
        const top = D.top.map(p => ({cells: [esc(p.name || '—'), nf(p.q), tw.zl(p.value)]}));
        return `${tw.tiles([{label: 'Straty · ' + D.quarter.label, value: pct(t.waste_pct), sub: `produkcji · ${tw.zl(t.value)} surowców`},
                {label: D.prev.label, value: pct(t.waste_pct_prev), sub: pp(t.waste_pct, t.waste_pct_prev) || 'brak danych'},
                {label: 'Najwięcej', value: t.worst ? t.worst.name : '—', sub: t.worst ? `${pct(t.worst.waste_pct)} · ${tw.zl(t.worst.value)}` : 'brak strat', cls: t.worst ? 'warn' : ''},
                {label: 'Zrobione', value: nf(t.produced), sub: 'sztuk z tabletów'}])}
            ${D.groups.length ? `<div class="tw-grid2"><div>${tw.table([{label: 'Grupa'}, {label: 'Straty', num: true}, {label: 'Szt.', num: true}, {label: 'Wartość', num: true}, {label: D.prev.key.slice(5), num: true}, {label: 'Zmiana', num: true}], rows)}</div>
                <div><div class="tw-cap" style="margin-top:4px">Przyczyny strat</div><div class="tw-hbars">${bars || '<p class="tw-empty">Brak.</p>'}</div>
                ${top.length ? `<div class="tw-cap">Produkty — największa strata</div>${tw.table([{label: 'Produkt'}, {label: 'Szt.', num: true}, {label: 'Wartość', num: true}], top)}` : ''}</div></div>`
                : tw.alert(`Brak danych z tabletów za ${esc(D.quarter.label)} — przegląd będzie pełny, gdy grupy zatwierdzają dni na tabletach.`, 'info', 'bi-info-circle')}
            <p class="tw-note">Wartość = sztuki ÷ sztuk w kartonie × koszt surowców kartonu. Straty z tabletów grup (anulowane pominięte).</p>`;
    }

    function step2() {
        const rows = D.groups.map(g => ({cells: [`<b>${esc(g.name)}</b>`, g.per_hour === null ? '—' : nf(g.per_hour, 0), g.per_hour_prev === null ? '—' : nf(g.per_hour_prev, 0),
            g.hours ? nf(g.hours) + ' h' : '—', pct(g.plan_pct), g.trs === null ? '—' : ((g.trs ?? 0) < 65 ? `<span class="tw-red">${pct(g.trs)}</span>` : pct(g.trs))]}));
        const m = D.machines.map(x => ({cells: [esc(x.name), esc(x.group || '—'), pct(x.oee), nf(x.stop_h, 1) + ' h']}));
        return `${tw.table([{label: 'Grupa'}, {label: 'Szt. / godz.', num: true}, {label: D.prev.key.slice(5), num: true}, {label: 'Godziny pracy', num: true}, {label: 'Plan zrobiony', num: true}, {label: 'TRS', num: true}], rows, 'Brak danych.')}
            ${m.length ? `<div class="tw-cap">TRS maszyn</div>${tw.table([{label: 'Maszyna'}, {label: 'Grupa'}, {label: 'TRS', num: true}, {label: 'Postoje', num: true}], m)}` : ''}
            <p class="tw-note">Szt. / godz. = zrobione sztuki ÷ godziny pracy grupy (odbicia wejście / wyjście). TRS = dostępność × wydajność × jakość ze stanów maszyn na tabletach.</p>`;
    }

    function step3() {
        if (D.done) return tw.done('Przegląd zatwierdzony', D.done.summary + ' · zadanie zaliczone')
            + (D.actions.length ? tw.table([{label: 'Działanie'}, {label: 'Cel'}, {label: 'Kto / grupa'}, {label: 'Do'}], D.actions.map(a => ({cells: [esc(a.title), esc(a.goal || '—'), esc(a.user || a.group || '—'), tw.dm(a.due_date)]}))) : '')
            + '<p class="tw-note">Działania wracają na zebraniu produkcyjnym (krok 2).</p>';
        const users = (sel) => `<option value="">— kto —</option>` + D.users.map(u => `<option value="${u.id}" ${String(sel) === String(u.id) ? 'selected' : ''}>${esc(u.name)}</option>`).join('');
        const groups = (sel) => `<option value="">— grupa —</option>` + D.all_groups.map(g => `<option value="${g.id}" ${String(sel) === String(g.id) ? 'selected' : ''}>${esc(g.name)}</option>`).join('');
        const rows = acts.map((a, i) => `<tr data-yr-a="${i}"><td><input class="form-control" data-f="title" maxlength="200" value="${esc(a.title)}" placeholder="co zmieniamy"></td>
            <td><input class="form-control" data-f="goal" maxlength="120" value="${esc(a.goal)}" placeholder="np. straty < 2 %"></td>
            <td><select class="form-select" data-f="user_id">${users(a.user_id)}</select></td><td><select class="form-select" data-f="group_id">${groups(a.group_id)}</select></td>
            <td><input type="date" class="form-control" data-f="due_date" value="${esc(a.due_date)}"></td></tr>`).join('');
        return `<p class="tw-lead">Najwyżej ${D.max_actions} działania — te, które naprawdę zmienią wynik kwartału.</p>
            <div class="table-responsive"><table class="tw-tb form"><thead><tr><th>Działanie</th><th>Cel</th><th>Kto</th><th>Grupa</th><th>Do kiedy</th></tr></thead><tbody>${rows}</tbody></table></div>
            ${acts.length < D.max_actions ? '<button type="button" class="tw-link" data-yr-add><i class="bi bi-plus-circle"></i> Dodaj działanie</button>' : ''}
            ${tw.check('tablets', true, 'Na tablety grup', 'baner « Od kierownika produkcji » dla grupy działania')}
            <div class="tw-ck" style="cursor:default"><i class="bi bi-arrow-repeat" style="color:#8b857e"></i><span>Działania wracają na zebraniu produkcyjnym<small>krok 2 zebrania : zrobione / niezrobione</small></span></div>`;
    }

    function render() {
        const body = step === 1 ? step1() : (step === 2 ? step2() : step3());
        const next = step < 3 ? {label: step === 1 ? 'Dalej : wydajność' : 'Dalej : działania', attr: 'data-yr-next'}
            : (D.done ? null : {label: 'Zatwierdź przegląd', attr: 'data-yr-finish', final: true, disabled: busy});
        app.innerHTML = tw.frame({title: 'Wydajność i straty', sub: `${D.quarter.label} · krok ${step} z 3 · ok. 45 min`, steps: STEPS, step, reached, body, prev: step > 1 && !D.done ? 'Wstecz' : null, next, later: !D.done});
    }

    tw.bind(app, (n) => { step = n === -1 ? Math.max(1, step - 1) : n; render(); });
    const upd = (e) => { const tr = e.target.closest('[data-yr-a]'); if (tr && e.target.dataset.f) acts[Number(tr.dataset.yrA)][e.target.dataset.f] = e.target.value; };
    app.addEventListener('input', upd);
    app.addEventListener('change', upd);
    app.addEventListener('click', async (e) => {
        if (e.target.closest('[data-yr-next]')) { step = Math.min(3, step + 1); reached = Math.max(reached, step); render(); return; }
        if (e.target.closest('[data-yr-add]')) { acts.push({title: '', goal: '', user_id: '', group_id: '', due_date: ''}); render(); return; }
        if (e.target.closest('[data-yr-finish]') && !busy) {
            busy = true;
            const tablets = !!app.querySelector('[data-tw-ck="tablets"]')?.checked;
            render();
            const res = await tw.post('/ajax/production/yield-review/finish', {q: D.quarter.key, actions: acts, tablets});
            busy = false;
            if (res.ok) { at.notify(true, res.message); D = res.data; }
            render();
        }
    });
    render();
})();
