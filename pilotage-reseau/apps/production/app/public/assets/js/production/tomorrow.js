// Plan na jutro [247] (makieta 19) : 1. obciążenie grup (+ propozycja przesunięcia), 2. ludzie i surowce,
// 3. potwierdzenie (wiadomość na tablety grup, lista dnia PDF) → zadanie zaliczone.
(() => {
    const app = document.getElementById('tpApp');
    if (!app || !window.tw) return;
    const {esc, nf} = tw;
    let D = JSON.parse(document.getElementById('tpBoot').textContent);
    let step = 1, reached = 1, open = new Set(), busy = false;
    const STEPS = ['Co jutro', 'Ludzie i surowce', 'Potwierdź'];
    const h = (v) => nf(v, 1) + ' h';
    const state = (g) => g.free_h < -0.05 ? tw.pill('+' + nf(-g.free_h, 1) + ' h ponad obsadę', 'bad') : ((g.load_pct ?? 0) > 90 ? tw.pill('pełno', 'warn') : tw.pill('OK', 'ok'));

    function step1() {
        const t = D.totals;
        if (!D.groups.length) return `<p class="tw-lead">Na ${esc(D.label)} nie ma planu w tablicy tygodnia.</p>${tw.alert(`Zaplanuj dzień na <a href="${at.root}/workshop/gantt">tablicy tygodnia</a> — potem wróć tutaj.`, 'info', 'bi-info-circle')}`;
        const rows = [];
        D.groups.forEach(g => {
            const pct = g.load_pct ?? 0, over = g.free_h < -0.05;
            rows.push({cls: 'click' + (over ? ' hl' : ''), attr: `data-tp-open="${g.id}"`, cells: [`<b>${esc(g.name)}</b> <i class="bi bi-chevron-${open.has(g.id) ? 'up' : 'down'} tw-note"></i>`, g.products, nf(g.cartons), h(g.hours), h(g.available_h),
                `${tw.gbar(pct, over ? 'bad' : (pct > 90 ? 'warn' : ''))} <span style="display:inline-block;width:52px;text-align:right">${nf(pct)} %</span>`, state(g)]});
            if (open.has(g.id)) g.blocks.forEach(b => rows.push({cls: 'sub', cells: [esc(b.name), '', nf(b.cartons, 1), h(b.hours), '', `${nf(b.quantity)} ${esc(b.unit)}${b.batches ? ' · ' + esc(b.batches) + ' ' + tw.plural(Number(b.batches), 'partia', 'partie', 'partii') : ''}`, '']}));
        });
        const props = D.groups.filter(g => g.proposal).map(g => {
            const p = g.proposal;
            if (!p.item_id) return tw.alert(`<b>${esc(g.name)} : ${nf(p.excess_h, 1)} h ponad obsadę.</b> W tym tygodniu nie ma dnia z wolnymi godzinami — zmniejsz plan na <a href="${at.root}/workshop/gantt">tablicy tygodnia</a> albo dodaj osobę.`);
            return tw.alert(`<b>${esc(g.name)} : ${nf(p.excess_h, 1)} h ponad obsadę.</b> Przesuń « ${esc(p.name)} » (${nf(p.hours, 1)} h) na ${esc(p.to_label)} — wolne ${nf(p.to_free_h, 1)} h.`,
                '', 'bi-exclamation-triangle', `<button type="button" class="tw-btn ghost" data-tp-move="${g.id}">Przesuń na ${esc(p.to_label)}</button>`);
        }).join('');
        return `<p class="tw-lead">Jutro to, co w planie tygodnia na ${esc(D.label)}. Sprawdź obciążenie grup ; kliknij grupę, aby zobaczyć produkty.</p>
            ${tw.tiles([{label: 'Jutro · ' + D.label, value: nf(t.cartons) + ' kart.', sub: `${t.products} ${tw.plural(t.products, 'produkt', 'produkty', 'produktów')} · ${t.groups} ${tw.plural(t.groups, 'grupa', 'grupy', 'grup')}`},
                {label: 'Godziny planu', value: h(t.hours), sub: `z ${h(t.available_h)} obecnych ludzi`},
                {label: 'Ponad obsadę', value: String(t.over) + ' ' + tw.plural(t.over, 'grupa', 'grupy', 'grup'), sub: t.over ? D.groups.filter(g => g.free_h < -0.05).map(g => g.name).join(', ') : 'wszystkie grupy się mieszczą', cls: t.over ? 'warn' : 'ok'},
                {label: 'Ludzie', value: String(t.people), sub: 'obsada grup w planie'}])}
            ${tw.table([{label: 'Grupa'}, {label: 'Produkty', num: true}, {label: 'Kartony', num: true}, {label: 'Godziny planu', num: true}, {label: 'Godziny ludzi', num: true}, {label: 'Obciążenie'}, {label: 'Stan'}], rows)}
            ${props}`;
    }

    function step2() {
        const ppl = D.groups.map(g => ({cells: [`<b>${esc(g.name)}</b>`, String(g.people), h(g.available_h), h(g.hours), g.free_h < -0.05 ? `<span class="tw-red">${tw.signed(g.free_h, 1, ' h')}</span>` : tw.signed(g.free_h, 1, ' h')]}));
        const m = D.materials;
        const mat = m.rows.map(r => ({cls: r.state === 'short' ? 'hl' : '', cells: [`<b>${esc(r.name)}</b>`, `${nf(r.need, 1)} ${esc(r.unit)}`, r.stock === null ? '—' : `${nf(r.stock, 1)} ${esc(r.unit)}`,
            r.delivery > 0 ? tw.pill(`dostawa ${nf(r.delivery, 1)} ${r.unit}`, 'ok') : '—',
            r.state === 'short' ? `${tw.pill('brakuje ' + nf(r.missing, 1) + ' ' + r.unit, 'bad')} <a class="tw-btn ghost sm" href="${at.root}/workshop/buy">Zamów</a>` : tw.pill(r.state === 'delivery' ? 'z dostawą OK' : 'sprawdź', r.state === 'delivery' ? 'ok' : 'warn')]}));
        return `${tw.table([{label: 'Grupa'}, {label: 'Ludzie', num: true}, {label: 'Godziny ludzi', num: true}, {label: 'Godziny planu', num: true}, {label: 'Zapas', num: true}], ppl)}
            <div class="tw-cap">Surowce na ${esc(D.label)}</div>
            ${m.rows.length ? tw.table([{label: 'Surowiec'}, {label: 'Potrzeba (waga kupiona)', num: true}, {label: 'Stan', num: true}, {label: 'Dostawa w tym dniu'}, {label: 'Uwagi'}], mat)
                : tw.alert(`Wszystkie surowce na ${esc(D.label)} są na stanie (${m.ok}).`, 'ok', 'bi-check-circle')}
            <p class="tw-note">Tylko surowce z brakiem albo z dostawą tego dnia${m.ok && m.rows.length ? ` — ${m.ok} innych jest na stanie` : ''}. Obsada : plan zespołów (bez nieobecności).${D.no_recipe.length ? ` Bez receptury : ${D.no_recipe.map(esc).join(', ')}.` : ''}</p>`;
    }

    function step3() {
        const t = D.totals;
        if (D.done) return tw.done('Plan zatwierdzony · ' + (D.done.at || ''), D.done.summary + ' · zadanie zaliczone') + `<p class="tw-note"><a href="${at.root}/production/tomorrow/pdf?date=${esc(D.day)}" target="_blank" rel="noopener"><i class="bi bi-filetype-pdf"></i> Lista dnia (PDF)</a></p>`;
        return `<p class="tw-lead">Gotowe : <b>${t.groups} ${tw.plural(t.groups, 'grupa', 'grupy', 'grup')} · ${t.products} ${tw.plural(t.products, 'produkt', 'produkty', 'produktów')} · ${nf(t.cartons)} kart. · ${h(t.hours)}</b>. Tablety grup pokazują plan z tablicy tygodnia na bieżąco.</p>
            ${tw.check('tablets', true, 'Wiadomość na tablety grup (' + t.groups + ')', '« Od kierownika produkcji » : plan na ' + D.label + ' — znika po zatwierdzeniu dnia przez grupę')}
            <div class="tw-field" style="margin-top:10px"><label for="tpNote">Dopisek do wiadomości (opcjonalnie)</label><input id="tpNote" class="form-control" maxlength="300" placeholder="np. start Piekarni o 5:30"></div>
            <p class="tw-note"><a href="${at.root}/production/tomorrow/pdf?date=${esc(D.day)}" target="_blank" rel="noopener"><i class="bi bi-filetype-pdf"></i> Lista dnia PDF — 1 strona na grupę, do druku</a></p>`;
    }

    function render() {
        const body = step === 1 ? step1() : (step === 2 ? step2() : step3());
        const next = step < 3 ? {label: step === 1 ? 'Dalej : ludzie i surowce' : 'Dalej : potwierdź', attr: 'data-tp-next', disabled: !D.groups.length}
            : (D.done ? null : {label: 'Zatwierdź plan i zalicz', attr: 'data-tp-confirm', final: true, disabled: busy});
        app.innerHTML = tw.frame({title: 'Plan na jutro', sub: `${D.label} · krok ${step} z 3 · ok. 15 min`, steps: STEPS, step, reached, body, prev: step > 1 ? 'Wstecz' : null, next, later: !D.done && step < 3});
    }

    tw.bind(app, (n) => { step = n === -1 ? Math.max(1, step - 1) : n; render(); });
    app.addEventListener('click', async (e) => {
        const row = e.target.closest('[data-tp-open]');
        if (row) { const id = Number(row.dataset.tpOpen); open.has(id) ? open.delete(id) : open.add(id); render(); return; }
        if (e.target.closest('[data-tp-next]')) { step = Math.min(3, step + 1); reached = Math.max(reached, step); render(); return; }
        const mv = e.target.closest('[data-tp-move]');
        if (mv) {
            const g = D.groups.find(x => x.id === Number(mv.dataset.tpMove));
            if (!g || !g.proposal || busy) return;
            busy = true; mv.disabled = true;
            const res = await tw.post('/ajax/production/tomorrow/move', {date: D.day, item_id: g.proposal.item_id, to: g.proposal.to});
            busy = false;
            if (res.ok) { at.notify(true, res.message); D = res.data; render(); } else mv.disabled = false;
            return;
        }
        if (e.target.closest('[data-tp-confirm]')) {
            if (busy) return;
            busy = true; render();
            const res = await tw.post('/ajax/production/tomorrow/confirm', {date: D.day, tablets: !!app.querySelector('[data-tw-ck="tablets"]')?.checked, note: app.querySelector('#tpNote')?.value || ''});
            busy = false;
            if (res.ok) { at.notify(true, res.message); D = res.data; }
            render();
        }
    });
    render();
})();
