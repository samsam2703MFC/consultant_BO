// [303] Transport dla zamówień (makieta 56) : 1. dni bez transportu (dziś, jutro), 2. zaznacz klientów → wybierz pojazd
// z floty (zapełnienie przed / po), 3. sprawdź — gdy nikt nie zostaje bez transportu, zadanie Logistyki jest zaliczone (ERP odblokowany).
(() => {
    const app = document.getElementById('otwApp');
    if (!app || !window.tw) return;
    const {esc, nf} = tw;
    let D = JSON.parse(document.getElementById('otwBoot').textContent);
    let step = 1, reached = 1, busy = false;
    let dayIdx = Math.max(0, D.days.findIndex(d => d.unassigned.length));
    let picked = new Set(), vehicle = null;
    const STEPS = ['Dni bez transportu', 'Przypisz do transportu', 'Sprawdź i zatwierdź'];
    const pal = (n) => nf(n) + ' pal.';
    const fill = (p, places) => places ? Math.round(p / places * 100) : null;

    function step1() {
        if (!D.uncovered) return tw.done('Wszystkie zamówienia mają transport', 'dziś i jutro · zadanie zaliczone');
        const rows = D.days.map((d, i) => ({cls: d.unassigned.length ? 'click hl' : '', attr: d.unassigned.length ? `data-otw-day="${i}"` : '',
            cells: [`<b>${esc(d.label)}</b>`, String(d.customers), d.unassigned.length ? tw.pill(d.unassigned.length + ' bez transportu', 'bad') : tw.pill('OK', 'ok'),
                pal(d.unassigned.reduce((s, c) => s + c.pallets, 0)), d.unassigned.map(c => esc(c.name)).join(', ') || '—']}));
        return `<p class="tw-lead">Klienci z zamówieniem na dziś i jutro, którzy nie mają pojazdu. Dopóki ktoś zostaje bez transportu, ERP jest zablokowany dla Logistyki.</p>
            ${tw.table([{label: 'Dzień'}, {label: 'Klienci', num: true}, {label: 'Stan'}, {label: 'Palety bez transportu', num: true}, {label: 'Klienci bez transportu'}], rows)}`;
    }

    function step2() {
        const d = D.days[dayIdx];
        if (!d.unassigned.length) return tw.alert(`Na ${esc(d.label)} każdy klient ma transport.`, 'ok', 'bi-check-circle');
        const sel = d.unassigned.filter(c => picked.has(c.id));
        const add = sel.reduce((s, c) => s + c.pallets, 0);
        const tabs = D.days.map((x, i) => `<button type="button" class="tw-btn ${i === dayIdx ? '' : 'ghost'} sm" data-otw-tab="${i}">${esc(x.label)}${x.unassigned.length ? ' · ' + x.unassigned.length : ''}</button>`).join(' ');
        const clients = d.unassigned.map(c => `<label class="otw-cl ${picked.has(c.id) ? 'is-on' : ''}"><input type="checkbox" data-otw-pick="${c.id}" ${picked.has(c.id) ? 'checked' : ''}>
            <span class="otw-cl-t"><b>${esc(c.name)}</b>${c.town ? ' · ' + esc(c.town) : ''}<small>${c.orders} ${tw.plural(c.orders, 'zamówienie', 'zamówienia', 'zamówień')}</small></span>${tw.pill(pal(c.pallets), 'bad')}</label>`).join('');
        const fleet = d.fleet.length ? d.fleet.map(v => {
            const now = fill(v.pallets, v.places), after = fill(v.pallets + (vehicle === v.id ? add : 0), v.places);
            const over = v.places !== null && v.pallets + (vehicle === v.id ? add : 0) > v.places;
            return `<button type="button" class="otw-veh ${vehicle === v.id ? 'is-on' : ''}" data-otw-veh="${v.id}">
                <b><i class="bi bi-truck"></i> ${esc(v.label)}</b>${v.carrier && v.carrier !== v.label ? ' · ' + esc(v.carrier) : ''}${v.departure ? ` <span class="tw-note">· wyjazd ${esc(String(v.departure).slice(0, 5))}</span>` : ''}
                ${tw.gbar(after ?? 0, over ? 'bad' : ((after ?? 0) > 90 ? 'warn' : ''))}
                <span class="tw-note">${v.places ? `${nf(v.pallets)} / ${nf(v.places)} pal.` : `${nf(v.pallets)} pal. · brak pojemności`}${vehicle === v.id && add ? ` → po przypisaniu ${nf(v.pallets + add)}${v.places ? ' / ' + nf(v.places) : ''}` : ''}${v.customers ? ` · ${v.customers} ${tw.plural(v.customers, 'klient', 'klientów', 'klientów')}` : ''}</span></button>`;
        }).join('') : tw.alert('Brak pojazdów we flocie — dodaj pojazd w Logistyka › Pojazdy.', '', 'bi-exclamation-triangle');
        return `<p>${tabs}</p>
            <p class="tw-lead"><b>${esc(d.label)} · ${d.unassigned.length} ${tw.plural(d.unassigned.length, 'klient', 'klientów', 'klientów')} bez transportu.</b> Zaznacz klientów, potem wybierz pojazd po prawej.</p>
            <div class="otw-cols"><div>${clients}</div><div>${fleet}</div></div>
            <p class="otw-act"><button type="button" class="tw-btn" data-otw-assign ${!sel.length || !vehicle || busy ? 'disabled' : ''}>${sel.length && vehicle
                ? `Przypisz ${sel.length} ${tw.plural(sel.length, 'klienta', 'klientów', 'klientów')} (${pal(add)}) do ${esc(d.fleet.find(v => v.id === vehicle)?.label || '')}` : 'Zaznacz klientów i pojazd'}</button></p>`;
    }

    function step3() {
        const rows = [];
        D.days.forEach(d => d.fleet.filter(v => v.customers).forEach(v => {
            const f = fill(v.pallets, v.places);
            rows.push({cells: [esc(d.label), `<b>${esc(v.label)}</b>`, v.names.map(esc).join(', '), pal(v.pallets), f === null ? '—' : tw.pill(nf(f) + ' %', f > 100 ? 'bad' : (f > 90 ? 'warn' : 'ok'))]});
        }));
        const left = D.uncovered;
        return `${left ? tw.alert(`Zostaje ${left} ${tw.plural(left, 'klient', 'klientów', 'klientów')} bez transportu — wróć do kroku 2.`, '', 'bi-exclamation-triangle')
                : tw.done('Wszystkie zamówienia mają transport', 'dziś i jutro · zadanie zaliczone — ERP odblokowany')}
            ${rows.length ? tw.table([{label: 'Dzień'}, {label: 'Transport'}, {label: 'Klienci'}, {label: 'Palety', num: true}, {label: 'Zapełnienie', num: true}], rows) : ''}`;
    }

    function render() {
        const body = step === 1 ? step1() : (step === 2 ? step2() : step3());
        const next = step < 3 ? {label: step === 1 ? 'Dalej : przypisz do transportu' : 'Dalej : sprawdź', attr: 'data-otw-next', disabled: step === 1 && !D.uncovered}
            : (D.uncovered ? {label: 'Wróć do przypisywania', attr: 'data-otw-back2'} : {label: 'Wróć do ERP', attr: 'data-otw-home', final: true});
        app.innerHTML = tw.frame({title: 'Transport dla zamówień', sub: `dziś i jutro · krok ${step} z 3 · ${D.uncovered ? D.uncovered + ' bez transportu' : 'wszystko przypisane'}`,
            steps: STEPS, step, reached, body, prev: step > 1 ? 'Wstecz' : null, next, later: false});
    }

    tw.bind(app, (n) => { step = n === -1 ? Math.max(1, step - 1) : n; render(); });
    app.addEventListener('change', (e) => {
        const p = e.target.closest('[data-otw-pick]');
        if (p) { const id = Number(p.dataset.otwPick); p.checked ? picked.add(id) : picked.delete(id); render(); }
    });
    app.addEventListener('click', async (e) => {
        const day = e.target.closest('[data-otw-day]');
        if (day) { dayIdx = Number(day.dataset.otwDay); picked.clear(); vehicle = null; step = 2; reached = Math.max(reached, 2); render(); return; }
        const tab = e.target.closest('[data-otw-tab]');
        if (tab) { dayIdx = Number(tab.dataset.otwTab); picked.clear(); vehicle = null; render(); return; }
        const veh = e.target.closest('[data-otw-veh]');
        if (veh) { vehicle = Number(veh.dataset.otwVeh); render(); return; }
        if (e.target.closest('[data-otw-next]')) { step = Math.min(3, step + 1); reached = Math.max(reached, step); render(); return; }
        if (e.target.closest('[data-otw-back2]')) { step = 2; dayIdx = Math.max(0, D.days.findIndex(d => d.unassigned.length)); render(); return; }
        if (e.target.closest('[data-otw-home]')) { window.location.href = at.root + '/home'; return; }
        if (e.target.closest('[data-otw-assign]')) {
            if (busy || !vehicle || !picked.size) return;
            busy = true; render();
            const res = await tw.post('/ajax/logistics/transport-wizard/assign', {date: D.days[dayIdx].date, vehicle_id: vehicle, customers: [...picked]});
            busy = false;
            if (res.ok) {
                at.notify(true, res.message); D = res.data; picked.clear(); vehicle = null;
                if (!D.days[dayIdx].unassigned.length) {
                    const nxt = D.days.findIndex(d => d.unassigned.length);
                    if (nxt >= 0) dayIdx = nxt; else { step = 3; reached = 3; }
                }
            }
            render();
        }
    });
    render();
})();
