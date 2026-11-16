// [303] Transport dla zamówień (makieta 56) : 1. dni bez transportu (dziś, jutro), 2. zaznacz klientów → wybierz pojazd
// z floty (zapełnienie przed / po), 3. kierowca i trasa pojazdu [306], 4. sprawdź — gdy nikt nie zostaje bez transportu,
// zadanie Logistyki jest zaliczone (ERP odblokowany). Brak pojazdu → mini-asystent « Dodaj pojazd » (włącz istniejący
// albo nowy) ; brak kierowcy → mini-asystent « Dodaj kierowcę » (pracownik, stanowisko Kierowca-dostawca, PIN tabletu).
(() => {
    const app = document.getElementById('otwApp');
    if (!app || !window.tw) return;
    const {esc, nf} = tw;
    let D = JSON.parse(document.getElementById('otwBoot').textContent);
    let step = 1, reached = 1, busy = false;
    let dayIdx = Math.max(0, D.days.findIndex(d => d.unassigned.length));
    let picked = new Set(), vehicle = null;
    // Mini-asystenci [306] : pojazd (formularz otwarty, tryb, wartości) i kierowca (pojazd, którego formularz jest otwarty).
    let vehForm = false, vf = {};
    let drvForm = null, df = {};
    const sel = {};   // krok 3 : pojazd → {driver_id, tour_id} wybrane, jeszcze nie zapisane
    const STEPS = ['Dni bez transportu', 'Przypisz do transportu', 'Kierowca i trasa', 'Sprawdź i zatwierdź'];
    const LAST = STEPS.length;
    const vInfo = (id) => (D.vehicles || []).find(v => v.id === id) || {};
    const personName = (id) => [...(D.drivers || []), ...(D.other_employees || [])].find(p => p.id === id)?.name || null;
    const pal = (n) => nf(n) + ' pal.';
    const fill = (p, places) => places ? Math.round(p / places * 100) : null;

    function step1() {
        if (!D.uncovered) return tw.done('Wszystkie wysyłki mają transport', 'wysyłka dziś i jutro · zadanie zaliczone — sprawdź jeszcze kierowców (krok 3)');
        const rows = D.days.map((d, i) => ({cls: d.unassigned.length ? 'click hl' : '', attr: d.unassigned.length ? `data-otw-day="${i}"` : '',
            cells: [`<b>${esc(d.label)}</b>`, String(d.customers), d.unassigned.length ? tw.pill(d.unassigned.length + ' bez transportu', 'bad') : tw.pill('OK', 'ok'),
                pal(d.unassigned.reduce((s, c) => s + c.pallets, 0)), d.unassigned.map(c => esc(c.name)).join(', ') || '—']}));
        return `<p class="tw-lead">Klienci z <b>wysyłką</b> dziś i jutro (data wysyłki zamówienia, nie data zamówienia), którzy nie mają pojazdu. Dopóki ktoś zostaje bez transportu, ERP jest zablokowany dla Logistyki.</p>
            ${tw.table([{label: 'Dzień wysyłki'}, {label: 'Klienci', num: true}, {label: 'Stan'}, {label: 'Palety bez transportu', num: true}, {label: 'Klienci bez transportu'}], rows)}`;
    }

    function step2() {
        const d = D.days[dayIdx];
        if (!d.unassigned.length) return tw.alert(`Wysyłka ${esc(d.label)} : każdy klient ma transport.`, 'ok', 'bi-check-circle');
        const sel = d.unassigned.filter(c => picked.has(c.id));
        const add = sel.reduce((s, c) => s + c.pallets, 0);
        const tabs = D.days.map((x, i) => `<button type="button" class="tw-btn ${i === dayIdx ? '' : 'ghost'} sm" data-otw-tab="${i}">Wysyłka ${esc(x.label)}${x.unassigned.length ? ' · ' + x.unassigned.length : ''}</button>`).join(' ');
        const clients = d.unassigned.map(c => `<label class="otw-cl ${picked.has(c.id) ? 'is-on' : ''}"><input type="checkbox" data-otw-pick="${c.id}" ${picked.has(c.id) ? 'checked' : ''}>
            <span class="otw-cl-t"><b>${esc(c.name)}</b>${c.town ? ' · ' + esc(c.town) : ''}<small>${c.orders} ${tw.plural(c.orders, 'zamówienie', 'zamówienia', 'zamówień')}</small></span>${tw.pill(pal(c.pallets), 'bad')}</label>`).join('');
        const fleet = d.fleet.length ? d.fleet.map(v => {
            const now = fill(v.pallets, v.places), after = fill(v.pallets + (vehicle === v.id ? add : 0), v.places);
            const over = v.places !== null && v.pallets + (vehicle === v.id ? add : 0) > v.places;
            return `<button type="button" class="otw-veh ${vehicle === v.id ? 'is-on' : ''}" data-otw-veh="${v.id}">
                <b><i class="bi bi-truck"></i> ${esc(v.label)}</b>${v.carrier && v.carrier !== v.label ? ' · ' + esc(v.carrier) : ''}${v.departure ? ` <span class="tw-note">· wyjazd ${esc(String(v.departure).slice(0, 5))}</span>` : ''}
                ${tw.gbar(after ?? 0, over ? 'bad' : ((after ?? 0) > 90 ? 'warn' : ''))}
                <span class="tw-note">${v.places ? `${nf(v.pallets)} / ${nf(v.places)} pal.` : `${nf(v.pallets)} pal. · brak pojemności`}${vehicle === v.id && add ? ` → po przypisaniu ${nf(v.pallets + add)}${v.places ? ' / ' + nf(v.places) : ''}` : ''}${v.customers ? ` · ${v.customers} ${tw.plural(v.customers, 'klient', 'klientów', 'klientów')}` : ''}</span></button>`;
        }).join('') + (vehForm ? vehicleForm(false) : `<button type="button" class="tw-btn ghost sm" data-otw-vopen><i class="bi bi-plus-lg"></i> Dodaj pojazd</button>`)
            : vehicleForm(true);
        return `<p>${tabs}</p>
            <p class="tw-lead"><b>Wysyłka ${esc(d.label)} · ${d.unassigned.length} ${tw.plural(d.unassigned.length, 'klient', 'klientów', 'klientów')} bez transportu.</b> Zaznacz klientów, potem wybierz pojazd po prawej.</p>
            <div class="otw-cols"><div>${clients}</div><div>${fleet}</div></div>
            <p class="otw-act"><button type="button" class="tw-btn" data-otw-assign ${!sel.length || !vehicle || busy ? 'disabled' : ''}>${sel.length && vehicle
                ? `Przypisz ${sel.length} ${tw.plural(sel.length, 'klienta', 'klientów', 'klientów')} (${pal(add)}) do ${esc(d.fleet.find(v => v.id === vehicle)?.label || '')}` : 'Zaznacz klientów i pojazd'}</button></p>`;
    }

    // [308] Pojemność i wyjazd : tylko do odczytu — zmienia się je w parametrach pojazdu (Pojazdy i kierowcy).
    function capacityNote() {
        const v = vf.mode === 'existing' ? vInfo(Number(vf.vehicle_id)) : {};
        const places = v.places || D.default_places || 33;
        const dep = v.departure || D.default_departure || '';
        return `<p class="otw-cap"><i class="bi bi-box-seam"></i> Pojemność <b>${nf(places)} pal.</b>${v.places ? '' : ' (domyślna)'}${dep ? ` · wyjazd <b>${esc(String(dep).slice(0, 5))}</b>` : ''}
            <span class="tw-note">— zmiana tylko w parametrach pojazdu : <a href="${at.root}/logistics/vehicles" target="_blank" rel="noopener">Pojazdy i kierowcy</a></span></p>`;
    }

    // [306] Mini-asystent « Dodaj pojazd » : włącz pojazd, który już jest w ERP, albo nowy (przewoźnik, numer).
    function vehicleForm(empty) {
        const off = (D.vehicles || []).filter(v => !v.active);
        if (!vf.mode) vf = {mode: off.length ? 'existing' : 'new', vehicle_id: off[0]?.id || '', carrier_id: '', regis_num: '',
};
        const opt = (list, cur, label) => list.map(x => `<option value="${x.id}" ${String(x.id) === String(cur) ? 'selected' : ''}>${esc(label(x))}</option>`).join('');
        const field = (lab, html) => `<div class="tw-field"><label>${lab}</label>${html}</div>`;
        const who = vf.mode === 'existing'
            ? field('Pojazd', `<select class="form-select" data-otw-vf="vehicle_id">${opt(off, vf.vehicle_id, v => v.label + (v.carrier && v.carrier !== v.label ? ' · ' + v.carrier : '') + (v.driver ? ' · ' + v.driver : ''))}</select>`)
            : `<div class="tw-grid2">${field('Przewoźnik', `<select class="form-select" data-otw-vf="carrier_id"><option value="">— wybierz —</option>${opt(D.carriers || [], vf.carrier_id, c => c.name)}</select>`)}
                ${field('Numer rejestracyjny', `<input class="form-control" data-otw-vf="regis_num" maxlength="60" value="${esc(vf.regis_num)}" placeholder="np. DW 12345">`)}</div>`;
        const ok = vf.mode === 'existing' ? !!vf.vehicle_id : (vf.carrier_id && vf.regis_num.trim());
        return `<div class="otw-mini">
            <div class="otw-mini-h"><b><i class="bi bi-truck"></i> Dodaj pojazd do floty</b>${off.length ? `<span class="tw-seg">
                <button type="button" class="${vf.mode === 'existing' ? 'on' : ''}" data-otw-vmode="existing">Włącz istniejący (${off.length})</button>
                <button type="button" class="${vf.mode === 'new' ? 'on' : ''}" data-otw-vmode="new">Nowy pojazd</button></span>` : ''}</div>
            ${empty ? `<p class="tw-note">Brak pojazdów we flocie. ${off.length ? 'Włącz pojazd, który już jest w ERP, albo dodaj nowy.' : 'Dodaj pierwszy pojazd.'}</p>` : ''}
            ${who}
            ${capacityNote()}
            <p class="otw-mini-a">${empty ? '' : '<button type="button" class="tw-btn ghost sm" data-otw-vclose>Anuluj</button>'}
                <button type="button" class="tw-btn sm" data-otw-vsave ${ok && !busy ? '' : 'disabled'}>${vf.mode === 'existing' ? 'Włącz pojazd' : 'Dodaj pojazd'}</button></p></div>`;
    }

    // [306] Krok 3 : kierowca i trasa każdego pojazdu, który wiezie zamówienia dziś / jutro.
    const usedVehicles = () => {
        const out = new Map();
        D.days.forEach(d => d.fleet.filter(v => v.customers).forEach(v => {
            const u = out.get(v.id) || {id: v.id, label: v.label, days: [], customers: 0};
            u.days.push(d.label); u.customers += v.customers; out.set(v.id, u);
        }));
        return [...out.values()];
    };

    function driverForm(vid) {
        const opt = (D.sections || []).map(x => `<option value="${x.id}" ${String(x.id) === String(df.section_id) ? 'selected' : ''}>${esc(x.name)}</option>`).join('');
        const f = (lab, html) => `<div class="tw-field"><label>${lab}</label>${html}</div>`;
        return `<div class="otw-mini">
            <div class="otw-mini-h"><b><i class="bi bi-person-plus"></i> Dodaj kierowcę</b><span class="tw-note">pracownik · stanowisko Kierowca-dostawca · od razu kierowca tego pojazdu</span></div>
            <div class="tw-grid2">${f('Imię', `<input class="form-control" data-otw-df="name" maxlength="60" value="${esc(df.name || '')}">`)}${f('Nazwisko', `<input class="form-control" data-otw-df="surname" maxlength="60" value="${esc(df.surname || '')}">`)}</div>
            <div class="tw-grid2">${f('Dział', `<select class="form-select" data-otw-df="section_id">${opt}</select>`)}${f('Telefon (na CMR, opcjonalnie)', `<input class="form-control" data-otw-df="driver_phone" maxlength="30" value="${esc(df.driver_phone || '')}">`)}</div>
            ${f('PIN tabletu kierowcy (4–6 cyfr, opcjonalnie)', `<input class="form-control" type="password" inputmode="numeric" autocomplete="new-password" maxlength="6" data-otw-df="pin" value="${esc(df.pin || '')}">`)}
            <p class="otw-mini-a"><button type="button" class="tw-btn ghost sm" data-otw-dclose>Anuluj</button>
                <button type="button" class="tw-btn sm" data-otw-dsave="${vid}" ${busy ? 'disabled' : ''}>Dodaj kierowcę i przypisz</button></p></div>`;
    }

    function step3() {
        const list = usedVehicles();
        if (!list.length) return tw.alert('Żaden pojazd nie wiezie jeszcze wysyłki dziś i jutro — wróć do kroku 2.', '', 'bi-info-circle');
        const noDrivers = !(D.drivers || []).length;
        if (noDrivers && drvForm === null) { const first = list.find(u => !vInfo(u.id).driver_id); if (first) openDriverForm(first.id); }
        const tours = D.tours || [];
        const rows = list.map(u => {
            const v = vInfo(u.id);
            const s = sel[u.id] || {};
            // Pojazd bez kierowcy : podpowiedź = kierowca jego trasy (do zapisania).
            const cur = s.driver_id ?? v.driver_id ?? v.tour_driver_id ?? '';
            const opts = (arr) => arr.map(p => `<option value="${p.id}" ${String(p.id) === String(cur) ? 'selected' : ''}>${esc(p.name)}</option>`).join('');
            const tourNames = (v.tour_ids || []).map(id => tours.find(t => t.id === id)?.name).filter(Boolean);
            const freeTours = tours.filter(t => t.active && !(v.tour_ids || []).includes(t.id));
            if (!v.driver_id && v.tour_driver_id && s.driver_id === undefined) (sel[u.id] ??= {}).driver_id = v.tour_driver_id;
            const dirty = ((sel[u.id] || {}).driver_id !== undefined && String(sel[u.id].driver_id) !== String(v.driver_id ?? '')) || !!(sel[u.id] || {}).tour_id;
            return `<div class="otw-drv">
                <div class="otw-drv-h"><b><i class="bi bi-truck"></i> ${esc(u.label)}</b><span class="tw-note">${esc(u.days.join(', '))} · ${u.customers} ${tw.plural(u.customers, 'klient', 'klientów', 'klientów')}</span>
                    ${v.driver_id ? tw.pill('Kierowca : ' + (personName(v.driver_id) || v.driver || '—'), 'ok') : tw.pill('Brak kierowcy', 'bad')}</div>
                <div class="otw-drv-f">
                    <div class="tw-field"><label>Kierowca</label><select class="form-select" data-otw-drv="${u.id}"><option value="">— wybierz kierowcę —</option>
                        ${(D.drivers || []).length ? `<optgroup label="Kierowcy">${opts(D.drivers)}</optgroup>` : ''}
                        ${(D.other_employees || []).length ? `<optgroup label="Inni pracownicy">${opts(D.other_employees)}</optgroup>` : ''}</select></div>
                    <div class="tw-field"><label>Trasa</label>${freeTours.length ? `<select class="form-select" data-otw-tour="${u.id}"><option value="">${tourNames.length ? esc(tourNames.join(', ')) : '— bez trasy —'}</option>
                        ${freeTours.map(t => `<option value="${t.id}" ${String(t.id) === String(s.tour_id || '') ? 'selected' : ''}>+ ${esc(t.name)}</option>`).join('')}</select>`
                        : `<span class="otw-ro">${tourNames.length ? esc(tourNames.join(', ')) : 'brak tras — Logistyka › Trasy'}</span>`}</div>
                    <div class="otw-drv-b"><button type="button" class="tw-btn sm" data-otw-dlink="${u.id}" ${dirty && !busy ? '' : 'disabled'}>Zapisz</button>
                        <button type="button" class="tw-btn ghost sm" data-otw-dopen="${u.id}"><i class="bi bi-person-plus"></i> Nowy kierowca</button></div></div>
                ${drvForm === u.id ? driverForm(u.id) : ''}</div>`;
        }).join('');
        return `<p class="tw-lead">Kto prowadzi pojazd i na jakiej trasie. Kierowca widzi swoje palety i klientów na tablecie kierowcy, a jego nazwisko trafia na CMR.</p>
            ${noDrivers ? tw.alert('Brak kierowców w ERP — dodaj kierowcę (pracownik ze stanowiskiem Kierowca-dostawca).', '', 'bi-person-exclamation') : ''}${rows}`;
    }

    function openDriverForm(vid) { drvForm = vid; df = {section_id: D.default_section || ''}; }

    function step4() {
        const rows = [];
        D.days.forEach(d => d.fleet.filter(v => v.customers).forEach(v => {
            const f = fill(v.pallets, v.places);
            const vi = vInfo(v.id);
            const drv = vi.driver_id ? esc(personName(vi.driver_id) || vi.driver || '') : tw.pill('brak', 'bad');
            rows.push({cells: [esc(d.label), `<b>${esc(v.label)}</b>`, drv, v.names.map(esc).join(', '), pal(v.pallets), f === null ? '—' : tw.pill(nf(f) + ' %', f > 100 ? 'bad' : (f > 90 ? 'warn' : 'ok'))]});
        }));
        const left = D.uncovered;
        return `${left ? tw.alert(`Zostaje ${left} ${tw.plural(left, 'klient', 'klientów', 'klientów')} bez transportu — wróć do kroku 2.`, '', 'bi-exclamation-triangle')
                : tw.done('Wszystkie wysyłki mają transport', 'wysyłka dziś i jutro · zadanie zaliczone — ERP odblokowany')}
            ${rows.length ? tw.table([{label: 'Dzień wysyłki'}, {label: 'Transport'}, {label: 'Kierowca'}, {label: 'Klienci'}, {label: 'Palety', num: true}, {label: 'Zapełnienie', num: true}], rows) : ''}`;
    }

    function render() {
        const body = [step1, step2, step3, step4][step - 1]();
        const labels = {1: 'Dalej : przypisz do transportu', 2: 'Dalej : kierowca i trasa', 3: 'Dalej : sprawdź'};
        const next = step < LAST ? {label: labels[step], attr: 'data-otw-next', disabled: step === 1 && !D.uncovered && !usedVehicles().length}
            : (D.uncovered ? {label: 'Wróć do przypisywania', attr: 'data-otw-back2'} : {label: 'Wróć do ERP', attr: 'data-otw-home', final: true});
        app.innerHTML = tw.frame({title: 'Transport dla zamówień', sub: `wysyłka dziś i jutro · krok ${step} z ${LAST} · ${D.uncovered ? D.uncovered + ' bez transportu' : 'wszystko przypisane'}`,
            steps: STEPS, step, reached, body, prev: step > 1 ? 'Wstecz' : null, next, later: false});
    }

    tw.bind(app, (n) => { step = n === -1 ? Math.max(1, step - 1) : n; render(); });
    app.addEventListener('change', (e) => {
        const p = e.target.closest('[data-otw-pick]');
        if (p) { const id = Number(p.dataset.otwPick); p.checked ? picked.add(id) : picked.delete(id); render(); return; }
        const f = e.target.closest('[data-otw-vf]');
        if (f && f.tagName === 'SELECT') {
            vf[f.dataset.otwVf] = f.value;
            render(); return;
        }
        const dr = e.target.closest('[data-otw-drv]');
        if (dr) { (sel[dr.dataset.otwDrv] ??= {}).driver_id = dr.value ? Number(dr.value) : ''; render(); return; }
        const tr = e.target.closest('[data-otw-tour]');
        if (tr) { (sel[tr.dataset.otwTour] ??= {}).tour_id = tr.value ? Number(tr.value) : null; render(); return; }
        const dsel = e.target.closest('[data-otw-df]');
        if (dsel) df[dsel.dataset.otwDf] = dsel.value;
    });
    // Pola tekstowe : bez przerysowania (kursor zostaje) ; przycisk « Dodaj pojazd » włącza się sam.
    app.addEventListener('input', (e) => {
        const f = e.target.closest('[data-otw-vf]');
        if (f) {
            vf[f.dataset.otwVf] = f.value;
            const b = app.querySelector('[data-otw-vsave]');
            if (b) b.disabled = busy || !(vf.mode === 'existing' ? vf.vehicle_id : (vf.carrier_id && String(vf.regis_num).trim()));
        }
        const d = e.target.closest('[data-otw-df]');
        if (d) df[d.dataset.otwDf] = d.value;
    });
    app.addEventListener('click', async (e) => {
        const day = e.target.closest('[data-otw-day]');
        if (day) { dayIdx = Number(day.dataset.otwDay); picked.clear(); vehicle = null; step = 2; reached = Math.max(reached, 2); render(); return; }
        const tab = e.target.closest('[data-otw-tab]');
        if (tab) { dayIdx = Number(tab.dataset.otwTab); picked.clear(); vehicle = null; render(); return; }
        const veh = e.target.closest('[data-otw-veh]');
        if (veh) { vehicle = Number(veh.dataset.otwVeh); render(); return; }
        if (e.target.closest('[data-otw-next]')) { step = Math.min(LAST, step + 1); reached = Math.max(reached, step); render(); return; }
        if (e.target.closest('[data-otw-vopen]')) { vehForm = true; vf = {}; render(); return; }
        if (e.target.closest('[data-otw-vclose]')) { vehForm = false; vf = {}; render(); return; }
        const vm = e.target.closest('[data-otw-vmode]');
        if (vm) { vf.mode = vm.dataset.otwVmode; render(); return; }
        if (e.target.closest('[data-otw-vsave]')) {
            if (busy) return;
            busy = true; render();
            const body = {};
            Object.assign(body, vf.mode === 'existing' ? {vehicle_id: Number(vf.vehicle_id)} : {carrier_id: Number(vf.carrier_id), regis_num: vf.regis_num});
            const res = await tw.post('/ajax/logistics/transport-wizard/vehicle', body);
            busy = false;
            if (res.ok) { at.notify(true, res.message); D = res.data; vehicle = res.vehicle_id; vehForm = false; vf = {}; }
            render(); return;
        }
        const dopen = e.target.closest('[data-otw-dopen]');
        if (dopen) { const id = Number(dopen.dataset.otwDopen); if (drvForm === id) drvForm = null; else openDriverForm(id); render(); return; }
        if (e.target.closest('[data-otw-dclose]')) { drvForm = -1; render(); return; }
        const dlink = e.target.closest('[data-otw-dlink]');
        if (dlink) {
            const id = Number(dlink.dataset.otwDlink), s = sel[id] || {};
            if (busy) return;
            busy = true; render();
            const res = await tw.post('/ajax/logistics/transport-wizard/driver', {vehicle_id: id, driver_id: s.driver_id || null, tour_id: s.tour_id || null});
            busy = false;
            if (res.ok) { at.notify(true, res.message); D = res.data; delete sel[id]; }
            render(); return;
        }
        const dsave = e.target.closest('[data-otw-dsave]');
        if (dsave) {
            if (busy) return;
            busy = true; render();
            const res = await tw.post('/ajax/logistics/transport-wizard/new-driver', {...df, vehicle_id: Number(dsave.dataset.otwDsave)});
            busy = false;
            if (res.ok) {
                at.notify(true, res.message + ((res.problems || []).length ? ' Do uzupełnienia : ' + res.problems.join(' ') : ''));
                D = res.data; drvForm = -1; df = {}; delete sel[Number(dsave.dataset.otwDsave)];
            }
            render(); return;
        }
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
                    if (nxt >= 0) dayIdx = nxt; else { step = 3; reached = Math.max(reached, 3); }
                }
            }
            render();
        }
    });
    render();
})();
