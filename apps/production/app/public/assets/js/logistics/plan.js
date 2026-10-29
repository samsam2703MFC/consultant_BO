/*
 * Plan logistyki na 12 tygodni [200] (Logistyka i pakowanie › Ciężarówki : plan i tydzień › Plan 12 tygodni).
 * Tablica : ciężarówki × tygodnie, klienci (palety) przeciągani palcem albo myszą na ciężarówkę tego samego tygodnia
 *   (albo : dotknij klienta, potem ciężarówkę) ; ⚙ = kierowca na zastępstwo / ciężarówka niedostępna ; tydzień : kopiuj
 *   układ na następne, zatwierdź (pojazdy klientów na dzień wyjazdu).
 * Prognoza : klienci × tygodnie (N-1 × współczynnik, bez N-1 średnia 3 mies., zamówione, wpisane), współczynnik klienta
 *   albo dla wszystkich, dodatkowe palety tygodnia (% , co roku).
 * Kalendarz tras [207] : ciężarówki × oś czasu, fazy trasy (załadunek, jazda, przerwy, odpoczynki, rozładunki, powrót)
 *   do przesuwania i zmiany, kontrola czasu pracy kierowcy (UE 561/2006), szablon trasy.
 * Święta i kierowcy : dzień wyjazdu tygodnia, dni wolne PL / BE / DE — przejazd, zakaz ruchu ciężarówek [202] (pomiń, dodaj), urlopy kierowców.
 */
(function () {
    const root = document.getElementById('logisticsPlan');
    if (!root) return;
    let P = JSON.parse(document.getElementById('logisticsPlanBoot').textContent || '{}');
    const view = root.querySelector('[data-view]');
    const esc = at.esc;
    const DAYS = ['', 'pn', 'wt', 'śr', 'czw', 'pt', 'sob', 'nd'];
    const SRC = { n1: 'N-1 × współczynnik', avg: 'średnia 3 mies.', orders: 'zamówione', manual: 'wpisane ręcznie', none: 'brak' };
    const PRESETS = [['Boże Narodzenie', 40], ['Przed świętami', 30], ['Mikołaj / St-Nicolas', 15], ['Nowy Rok', 20], ['Wielkanoc', 20], ['Po świętach', -10]];
    let tab = ({ '#prognoza': 'forecast', '#trasy': 'trips', '#swieta': 'calendar' })[location.hash] || 'board';
    let picked = null;

    const dm = (d) => { const p = String(d).split('-'); return `${Number(p[2])}.${p[1]}`; };
    const wd = (d) => DAYS[new Date(d + 'T12:00:00').getDay() || 7];
    const num = (n, dec = 0) => Number(n || 0).toLocaleString('pl-PL', { minimumFractionDigits: dec, maximumFractionDigits: dec });
    const query = () => (P.range.from !== P.range.default_start ? `from=${P.range.from}` : '');
    const C = () => Object.fromEntries(P.customers.map(c => [c.id, c]));
    const V = () => Object.fromEntries(P.vehicles.map(v => [v.id, v]));
    const W = () => Object.fromEntries(P.weeks.map(w => [w.week_start, w]));
    const initials = (s) => String(s || '').split(/\s+/).filter(Boolean).slice(0, 2).map(x => x[0]).join('').toUpperCase() || '—';
    // Święta tygodnia : jeden znaczek na dzień (« 11.11 PL·BE »), nazwy w podpowiedzi.
    const holBadges = (w) => {
        const days = {};
        w.holidays.forEach(h => { (days[h.date] ??= []).push(h); });
        return Object.entries(days).map(([d, hs]) => `<span class="lp-hol" title="${esc(hs.map(h => h.country + ' : ' + h.name).join(' · '))}">${dm(d)} ${hs.map(h => h.country).join('·')}</span>`).join('');
    };
    // Kraje przejazdu (DE) : « DE » albo « DE, AT ».
    const transit = () => (P.params.transit_countries || []).join(', ');
    const countriesText = () => [P.params.departure_country, P.params.delivery_country, ...(P.params.transit_countries || [])].join(', ');
    const fieldText = (res) => { const f = Object.values(res.fields || {}); return f.length ? ' ' + f.join(' ') : ''; };

    async function api(url, method, data, quiet) {
        const qs = query();
        const res = await at.send(url + (qs ? (url.includes('?') ? '&' : '?') + qs : ''), method, data);
        if (!res.ok) { at.notify(false, (res.message || 'Błąd') + fieldText(res)); return null; }
        if (res.data) P = res.data;
        if (res.message && !quiet) at.notify(true, res.message);
        render();
        return res;
    }

    function ask(text, yes = 'Tak') {
        if (window.Swal) return Swal.fire({ text, icon: 'question', showCancelButton: true, confirmButtonText: yes, cancelButtonText: 'Anuluj' }).then(r => r.isConfirmed);
        return Promise.resolve(window.confirm(text));
    }

    // --- Nagłówek, wskaźniki, zakładki ---------------------------------------------------------------------

    function renderNav() {
        const nav = document.querySelector('[data-nav]');
        if (!nav) return;
        const last = P.weeks[P.weeks.length - 1];
        const href = (from) => `${at.root}/logistics/plan?from=${from}${location.hash}`;
        nav.innerHTML = `<a class="btn btn-outline-primary" href="${href(P.range.prev)}" aria-label="4 tygodnie wcześniej">‹</a>
            <span class="lp-nav-label">tydz. ${P.weeks[0].iso_week} (${dm(P.weeks[0].week_start)}) → tydz. ${last.iso_week} (${dm(P.range.to)}.${String(P.range.to).slice(0, 4)})</span>
            <a class="btn btn-outline-primary" href="${href(P.range.next)}" aria-label="4 tygodnie później">›</a>
            ${P.range.from !== P.range.default_start ? `<a class="btn btn-outline-secondary" href="${at.root}/logistics/plan${location.hash}">Od następnego tygodnia</a>` : ''}`;
    }

    function renderKpis() {
        const k = P.kpis;
        const tile = (label, value, meta, cls = '') => `<div class="lp-kpi ${cls}"><span>${label}</span><b>${value}</b><small>${meta}</small></div>`;
        root.querySelector('[data-kpis]').innerHTML =
            tile('Palet na tydzień', num(k.avg, 1), `średnio w ${P.weeks.length} tygodniach`)
            + tile('Szczyt', num(k.peak), k.peak_week ? `tydzień ${k.peak_week}` : '—', 'is-warn')
            + tile('Palet w planie', num(k.total), `${P.customers.length} klientów`)
            + tile('Dni świąt', num(k.holiday_days), `pn–pt, ${countriesText()}`, k.holiday_days ? 'is-bad' : '')
            + tile('Do rozplanowania', num(k.unassigned) + ' pal.', k.absent_drivers ? `${k.absent_drivers} × kierowca na urlopie` : `${k.approved} z ${P.weeks.length} tyg. zatwierdzonych`, k.unassigned ? 'is-bad' : 'is-ok');
    }

    root.querySelectorAll('[data-tab]').forEach(b => b.addEventListener('click', () => {
        tab = b.dataset.tab;
        history.replaceState(null, '', location.pathname + location.search + ({ forecast: '#prognoza', trips: '#trasy', calendar: '#swieta' }[tab] || ''));
        render();
    }));

    function render() {
        root.querySelectorAll('[data-tab]').forEach(b => { b.classList.toggle('on', b.dataset.tab === tab); b.setAttribute('aria-selected', b.dataset.tab === tab); });
        renderNav();
        renderKpis();
        if (tab === 'forecast') renderForecast();
        else if (tab === 'calendar') renderCalendar();
        else if (tab === 'trips') renderTrips();
        else renderBoard();
    }

    // --- Tablica --------------------------------------------------------------------------------------------

    function explain(c, cell, w) {
        const ev = w.event && Number(w.event.pct) ? ` × ${(1 + w.event.pct / 100).toLocaleString('pl-PL')} (${w.event.name})` : '';
        if (cell.source === 'orders') return `zamówione : ${num(cell.pallets)} pal. (${num(cell.cartons)} kart.)`;
        if (cell.source === 'manual') return `wpisane ręcznie : ${cell.pallets} pal.`;
        if (cell.source === 'n1') return `N-1 (tydz. ${new Date(w.n1_week + 'T12:00:00').toLocaleDateString('pl-PL')}) ${cell.n1} pal. × ${num(c.coef, 2)}${ev} = ${num(cell.value, 1)} → ${cell.pallets}`;
        if (cell.source === 'avg') return `brak N-1 : średnia 3 mies. ${num(c.avg, 1)}${ev} = ${num(cell.value, 1)} → ${cell.pallets}`;
        return 'brak palet';
    }

    function chip(c, ws) {
        const cell = c.cells[ws];
        const w = W()[ws];
        const sel = picked && picked.customer === c.id && picked.week === ws;
        return `<div class="lp-chip src-${cell.source} ${sel ? 'is-picked' : ''}" data-drag data-customer="${c.id}" data-week="${ws}" style="--c:${c.color}"
            title="${esc(c.name + ' — ' + explain(c, cell, w))}"><span>${esc(c.short)}</span><b>${cell.pallets}</b></div>`;
    }

    function fill(pallets, places) {
        const pct = places ? pallets / places * 100 : 0;
        const cls = pct > 100 ? 'is-over' : (pct >= 92 ? 'is-full' : '');
        return `<div class="lp-fill ${cls}"><div><span>${pallets}/${places}</span><span>${Math.round(pct)} %</span></div><i><b style="width:${Math.min(100, pct)}%"></b></i></div>`;
    }

    function renderBoard() {
        const cs = P.customers;
        const target = (ws) => picked && picked.week === ws ? ' is-target' : '';
        let html = `<div class="lp-toolbar"><div class="lp-legend">${cs.map(c => `<span><i style="background:${c.color}"></i>${esc(c.short)} <small>${num(c.avg, 1)}</small></span>`).join('')}</div>
            <div class="lp-actions"><button type="button" class="btn btn-outline-secondary lp-big" data-reset ${cs.some(c => Object.values(c.cells).some(x => x.vehicle_id !== null)) ? '' : 'disabled'}><i class="bi bi-arrow-counterclockwise"></i> Wszystko do rozplanowania</button>
            <button type="button" class="btn btn-outline-primary lp-big" data-auto><i class="bi bi-magic"></i> Rozdziel automatycznie</button>
            <button type="button" class="btn btn-primary lp-big" data-approve-all><i class="bi bi-check2-all"></i> Zatwierdź ${P.weeks.length} tygodni</button></div></div>`;
        if (!P.vehicles.length) {
            html += `<p class="lp-hint">Brak aktywnej ciężarówki : <a href="${at.root}/logistics/vehicles">Pojazdy i pojemność</a> (aktywny, miejsca paletowe, kierowca).</p>`;
        }
        html += `<div class="lp-scroll"><div class="lp-grid" style="--weeks:${P.weeks.length}"><div class="lp-rl"><b>Tydzień</b><span>wyjazd · palety / miejsca</span></div>`;
        for (const w of P.weeks) {
            const hol = holBadges(w);
            const ev = w.event && Number(w.event.pct) ? `<span class="lp-ev" title="${esc(w.event.name)}${w.event.inherited ? ' (co roku)' : ''}">${w.event.pct > 0 ? '+' : ''}${num(w.event.pct)} %</span>` : '';
            html += `<div class="lp-wh ${w.over ? 'is-over' : ''} ${w.approved_at ? 'is-ok' : ''}" data-week-head="${w.week_start}">
                <div class="lp-wh-top"><b>${w.iso_week}</b><span class="${w.over ? 'lp-red' : ''}">${w.pallets}/${w.places}</span></div>
                <div class="lp-wh-ship" title="${esc(w.shifted || (w.ship_manual ? 'Wyjazd wybrany ręcznie' : 'Wyjazd ' + wd(w.ship_date) + ' ' + dm(w.ship_date) + ', dostawa ' + wd(w.delivery_date) + ' ' + dm(w.delivery_date)))}">
                    ${wd(w.ship_date)} ${dm(w.ship_date)}${w.shifted || w.ship_manual ? ' <i class="bi bi-arrow-left-right"></i>' : ''}</div>
                <div class="lp-badges">${hol}${ev}</div>
                <div class="lp-wh-act"><button type="button" data-copy="${w.week_start}" title="Kopiuj układ na następne tygodnie" aria-label="Kopiuj układ tygodnia ${w.iso_week}"><i class="bi bi-copy"></i></button>
                    <button type="button" data-approve="${w.week_start}" class="${w.approved_at ? 'on' : ''}" title="${w.approved_at ? 'Zatwierdzony — zatwierdź ponownie' : 'Zatwierdź tydzień'}" aria-label="Zatwierdź tydzień ${w.iso_week}"><i class="bi bi-check2"></i></button></div></div>`;
        }
        const cmap = C();
        for (const v of P.vehicles) {
            html += `<div class="lp-rl lp-truck"><span class="lp-av">${esc(initials(v.driver.name || v.label))}</span><div><b>${esc(v.label)}</b>
                <span>${esc(v.carrier || '')} · ${v.places}${v.places_known ? '' : ' ?'} m.${v.trips_per_week > 1 ? ` × ${v.trips_per_week} trasy / tydz.` : ''}${v.active ? '' : ' · nieaktywna'}</span><span>${esc(v.driver.name || 'kierowca przewoźnika')}</span></div></div>`;
            for (const w of P.weeks) {
                const cell = v.cells[w.week_start];
                const gear = `<button type="button" class="lp-gear" data-vw="${v.id}|${w.week_start}" title="Kierowca, dostępność i trasy w tym tygodniu" aria-label="Kierowca, dostępność i trasy ${esc(v.label)} tydzień ${w.iso_week}"><i class="bi bi-gear"></i></button>`;
                const chips = cell.customers.map(id => chip(cmap[id], w.week_start)).join('');
                if (!cell.usable && !chips) {
                    const why = cell.absent ? `urlop<br>${esc(cell.driver.name)}` : (cell.unavailable ? 'niedostępna' : 'nieaktywna');
                    html += `<div class="lp-cell lp-off" data-drop="vehicle" data-vehicle="${v.id}" data-week="${w.week_start}"><span>${why}</span>${gear}</div>`;
                    continue;
                }
                const note = cell.absent ? `<span class="lp-warn">urlop : ${esc(cell.driver.name)}</span>` : (cell.unavailable ? '<span class="lp-warn">niedostępna</span>'
                    : (cell.driver_override ? `<span class="lp-sub">${esc(cell.driver.name)}</span>` : (cell.trips > 1 ? `<span class="lp-sub">${cell.trips} trasy</span>` : '')));
                html += `<div class="lp-cell${target(w.week_start)}" data-drop="vehicle" data-vehicle="${v.id}" data-week="${w.week_start}">${chips}
                    ${chips ? fill(cell.pallets, cell.places) : `<span class="lp-free">wolna · ${cell.places} m.</span>`}<div class="lp-cell-foot">${note}${gear}</div></div>`;
            }
        }
        html += `<div class="lp-rl lp-un"><b>Do rozplanowania</b><span>przeciągnij na ciężarówkę</span></div>`;
        for (const w of P.weeks) {
            const left = cs.filter(c => c.cells[w.week_start].pallets > 0 && c.cells[w.week_start].vehicle_id === null);
            html += `<div class="lp-cell lp-left${left.length ? ' has' : ''}${target(w.week_start)}" data-drop="none" data-week="${w.week_start}">
                ${left.length ? left.map(c => chip(c, w.week_start)).join('') : '<span class="lp-done"><i class="bi bi-check2"></i></span>'}</div>`;
        }
        html += '</div></div>';
        const warnings = P.weeks.flatMap(w => w.warnings.map(t => `<li><b>tydz. ${w.iso_week}</b> ${esc(t)}</li>`));
        html += `${warnings.length ? `<ul class="lp-warnings">${warnings.join('')}</ul>` : ''}
            <p class="lp-hint">Przeciągnij klienta na ciężarówkę w tym samym tygodniu (albo dotknij klienta, potem ciężarówkę). Palety : najedź na klienta — skąd liczba (N-1, średnia, zamówienia).
            Zatwierdzenie tygodnia zapisuje pojazd klienta na dzień wyjazdu (<a href="${at.root}/logistics">Dzień wysyłki</a>, <a href="${at.root}/logistics/loading">Załadunek</a>), gdy nie wybrano go tam ręcznie.
            Ciężarówki : <a href="${at.root}/logistics/vehicles">Pojazdy i pojemność</a>.</p>`;
        view.innerHTML = html;
    }

    async function move(customer, week, vehicle) {
        picked = null;
        const c = C()[customer];
        if (!c || c.cells[week].vehicle_id === vehicle) return render();
        await api('/ajax/logistics/plan/loads', 'PUT', { items: [{ week_start: week, customer_id: customer, vehicle_id: vehicle }] }, true);
    }

    // Przeciąganie (mysz i palec) : pointer events, cel = komórka pod palcem w tym samym tygodniu.
    let drag = null;
    let dragMoved = false;
    view.addEventListener('pointerdown', (e) => {
        const src = e.target.closest('[data-drag]');
        if (!src || tab !== 'board') return;
        drag = { src, x: e.clientX, y: e.clientY, ghost: null, over: null };
        dragMoved = false;
    });
    window.addEventListener('pointermove', (e) => {
        if (!drag) return;
        if (!drag.ghost) {
            if (Math.hypot(e.clientX - drag.x, e.clientY - drag.y) < 8) return;
            dragMoved = true;
            drag.ghost = drag.src.cloneNode(true);
            drag.ghost.classList.add('lp-ghost');
            drag.ghost.style.width = drag.src.getBoundingClientRect().width + 'px';
            document.body.appendChild(drag.ghost);
            drag.src.classList.add('is-dragging');
            document.body.classList.add('lp-dragging');
        }
        e.preventDefault();
        drag.ghost.style.left = e.clientX + 'px';
        drag.ghost.style.top = e.clientY + 'px';
        const under = document.elementFromPoint(e.clientX, e.clientY);
        const t = under ? under.closest('[data-drop]') : null;
        const ok = t && t.dataset.week === drag.src.dataset.week;
        if (drag.over && drag.over !== t) drag.over.classList.remove('is-drop');
        drag.over = ok ? t : null;
        if (drag.over) drag.over.classList.add('is-drop');
    }, { passive: false });
    window.addEventListener('pointerup', () => {
        if (!drag) return;
        const d = drag;
        drag = null;
        if (d.ghost) d.ghost.remove();
        d.src.classList.remove('is-dragging');
        document.body.classList.remove('lp-dragging');
        if (d.over) {
            d.over.classList.remove('is-drop');
            if (d.ghost) move(Number(d.src.dataset.customer), d.src.dataset.week, d.over.dataset.drop === 'vehicle' ? Number(d.over.dataset.vehicle) : null);
        }
        window.setTimeout(() => { dragMoved = false; }, 0);
    });
    window.addEventListener('pointercancel', () => {
        if (!drag) return;
        if (drag.ghost) drag.ghost.remove();
        drag.src.classList.remove('is-dragging');
        document.body.classList.remove('lp-dragging');
        drag = null;
    });

    view.addEventListener('click', async (e) => {
        if (tab !== 'board' || dragMoved) return;
        const b = e.target.closest('button');
        if (b) {
            if (b.matches('[data-auto]')) { b.disabled = true; await api('/ajax/logistics/plan/auto', 'POST', {}); return; }
            if (b.matches('[data-reset]')) {
                if (await ask('Zdjąć wszystkich klientów z ciężarówek (tygodnie od dziś) ? Wrócą do « Do rozplanowania ». Palety wpisane ręcznie zostają ; zatwierdzone tygodnie trzeba będzie zatwierdzić ponownie.', 'Wszystko do rozplanowania')) {
                    b.disabled = true;
                    if (!(await api('/ajax/logistics/plan/reset', 'POST', {}))) b.disabled = false;
                }
                return;
            }
            if (b.matches('[data-approve-all]')) {
                if (await ask(`Zatwierdzić ${P.weeks.length} tygodni ? Pojazdy klientów zostaną zapisane na dni wyjazdu (bez zmiany wyborów zrobionych ręcznie w Dniu wysyłki).`, 'Zatwierdź')) {
                    await api('/ajax/logistics/plan/approve', 'POST', { week_start: null });
                }
                return;
            }
            if (b.dataset.approve) { await api('/ajax/logistics/plan/approve', 'POST', { week_start: b.dataset.approve }); return; }
            if (b.dataset.copy) {
                const w = W()[b.dataset.copy];
                if (await ask(`Skopiować układ tygodnia ${w.iso_week} (klient → ciężarówka) na następne tygodnie ? Tylko klienci jeszcze bez ciężarówki.`, 'Kopiuj')) {
                    await api(`/ajax/logistics/plan/weeks/${b.dataset.copy}/copy`, 'POST', {});
                }
                return;
            }
            if (b.dataset.vw) { const [vid, ws] = b.dataset.vw.split('|'); openVehicleWeek(Number(vid), ws); return; }
            return;
        }
        const c = e.target.closest('[data-drag]');
        if (c) {
            const same = picked && picked.customer === Number(c.dataset.customer) && picked.week === c.dataset.week;
            picked = same ? null : { customer: Number(c.dataset.customer), week: c.dataset.week };
            return render();
        }
        const t = e.target.closest('[data-drop]');
        if (t && picked && t.dataset.week === picked.week) move(picked.customer, picked.week, t.dataset.drop === 'vehicle' ? Number(t.dataset.vehicle) : null);
    });

    // --- Okno ---------------------------------------------------------------------------------------------

    function modal(title, body, onSubmit, extra = '') {
        const box = document.createElement('div');
        box.className = 'lp-modal';
        box.innerHTML = `<form class="lp-modal-box" role="dialog" aria-modal="true" aria-label="${esc(title)}"><div class="lp-modal-head"><h4>${esc(title)}</h4>
            <button type="button" class="btn btn-outline-secondary" data-close>Zamknij</button></div>${body}
            <div class="lp-modal-foot">${extra}<button type="submit" class="btn btn-primary lp-big">Zapisz</button></div></form>`;
        document.body.appendChild(box);
        const close = () => { box.remove(); document.removeEventListener('keydown', onKey); };
        const onKey = (e) => { if (e.key === 'Escape') close(); };
        document.addEventListener('keydown', onKey);
        box.addEventListener('click', (e) => { if (e.target === box || e.target.closest('[data-close]')) close(); });
        const form = box.querySelector('form');
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = form.querySelector('[type=submit]');
            btn.disabled = true;
            if (await onSubmit(form, e.submitter)) close(); else btn.disabled = false;
        });
        const first = form.querySelector('input, select');
        if (first) first.focus();
        return { box, form, close };
    }

    function openVehicleWeek(vid, ws) {
        const v = V()[vid];
        const w = W()[ws];
        const cell = v.cells[ws];
        const body = `<p class="lp-hint">Wyjazd ${wd(w.ship_date)} ${dm(w.ship_date)}${cell.absent ? ` — <b class="lp-red">${esc(cell.driver.name)} na urlopie</b>` : ''}.</p>
            <label class="lp-field"><span>Kierowca w tym tygodniu</span><select class="form-select" name="driver_id">
                <option value="">Kierowca pojazdu (${esc(v.driver.name || 'przewoźnik')})</option>
                ${P.drivers.map(d => `<option value="${d.id}" ${cell.driver_override && cell.driver.id === d.id ? 'selected' : ''}>${esc(d.name)}</option>`).join('')}</select></label>
            <label class="lp-field"><span>Tras w tym tygodniu (zmiany kierowcy)</span><select class="form-select" name="trips">
                <option value="">Jak zwykle : ${v.trips_per_week} ${v.trips_per_week > 1 ? 'trasy' : 'trasa'} / tydz.</option>
                ${[1, 2, 3, 4].map(n => `<option value="${n}" ${cell.trips_override && cell.trips === n ? 'selected' : ''}>${n} ${n > 1 ? 'trasy' : 'trasa'} w tym tygodniu</option>`).join('')}</select>
                <small>Standard : 1 trasa na kierowcę na tydzień. Miejsca tygodnia = miejsca × trasy ; zmiana na stałe : Kalendarz tras albo Pojazdy.</small></label>
            <label class="lp-check"><input type="checkbox" name="unavailable" ${cell.unavailable ? 'checked' : ''}> Ciężarówka niedostępna w tym tygodniu</label>`;
        modal(`${v.label} · tydzień ${w.iso_week}`, body, async (form) => {
            const res = await api(`/ajax/logistics/plan/weeks/${ws}/vehicles/${vid}`, 'PUT', { driver_id: form.driver_id.value || null, unavailable: form.unavailable.checked, trips: form.trips.value || null });
            return !!res;
        });
    }

    function openEvent(ws) {
        const w = W()[ws];
        const ev = w.event;
        const own = ev && !ev.inherited ? ev : null;
        const body = `${ev && ev.inherited ? `<p class="lp-hint">Co roku z ${new Date(ev.week_start + 'T12:00:00').getFullYear()} : <b>${esc(ev.name)} ${ev.pct > 0 ? '+' : ''}${num(ev.pct)} %</b>. Zapis zmienia tylko ten tydzień (0 % = bez dodatkowych palet).</p>` : ''}
            <div class="lp-presets">${PRESETS.map(([n, p]) => `<button type="button" class="btn btn-outline-secondary" data-preset="${esc(n)}|${p}">${esc(n)} ${p > 0 ? '+' : ''}${p} %</button>`).join('')}</div>
            <label class="lp-field"><span>Nazwa</span><input class="form-control" name="name" maxlength="80" value="${esc(ev ? ev.name : '')}" required></label>
            <label class="lp-field"><span>Dodatkowe palety (% prognozy wszystkich klientów)</span><input class="form-control" name="pct" inputmode="decimal" value="${ev ? num(ev.pct) : ''}" placeholder="np. 40 albo -10" required></label>
            <label class="lp-check"><input type="checkbox" name="yearly" ${ev && ev.yearly && !ev.inherited ? 'checked' : ''}> Powtarzaj co roku (ten sam tydzień)</label>
            <p class="lp-hint">Tydzień ${w.iso_week} · ${dm(w.week_start)}–${dm(new Date(new Date(w.week_start + 'T12:00:00').getTime() + 6 * 864e5).toISOString().slice(0, 10))}. Prognoza N-1 zawiera już sezon z zeszłego roku — dodaj palety tylko, gdy w tym roku będzie więcej.</p>`;
        const m = modal(`Dodatkowe palety · tydzień ${w.iso_week}`, body, async (form) => {
            const res = await api('/ajax/logistics/plan/events', 'PUT', { week_start: ws, name: form.name.value, pct: form.pct.value, yearly: form.yearly.checked });
            return !!res;
        }, own ? '<button type="button" class="btn btn-outline-danger lp-big" data-del>Usuń</button>' : '');
        m.form.querySelectorAll('[data-preset]').forEach(b => b.addEventListener('click', () => {
            const [n, p] = b.dataset.preset.split('|');
            m.form.name.value = n;
            m.form.pct.value = p;
        }));
        const del = m.form.querySelector('[data-del]');
        if (del) del.addEventListener('click', async () => { del.disabled = true; if (await api(`/ajax/logistics/plan/events/${own.id}`, 'DELETE')) m.close(); else del.disabled = false; });
    }

    // --- Prognoza -----------------------------------------------------------------------------------------

    function renderForecast() {
        const weeks = P.weeks;
        const head = weeks.map(w => `<th class="n">${w.iso_week}<br><small>${wd(w.ship_date)} ${dm(w.ship_date)}</small></th>`).join('');
        const rows = P.customers.map(c => {
            const cells = weeks.map(w => {
                const cell = c.cells[w.week_start];
                const t = esc(explain(c, cell, w));
                return `<td class="n"><input class="lp-in src-${cell.source}" data-pal="${c.id}|${w.week_start}" inputmode="numeric" value="${cell.source === 'none' ? '' : cell.pallets}"
                    ${cell.source === 'orders' ? 'disabled' : ''} title="${t}" aria-label="${esc(c.short)} tydzień ${w.iso_week} : ${t}"></td>`;
            }).join('');
            const trend = c.trend !== null ? `trend × ${num(c.trend, 2)}` : `bez trendu × ${num(P.params.coef_default, 2)}`;
            return `<tr><td class="lp-name"><span class="lp-dot" style="background:${c.color}"></span><b>${esc(c.short)}</b><small>${esc(c.town || '')}${c.n1_any ? '' : ' · bez N-1'}</small></td>
                <td class="n">${num(c.avg, 1)}</td>
                <td class="n"><input class="lp-in lp-coef" data-coef="${c.id}" inputmode="decimal" value="${c.coef_manual !== null ? num(c.coef_manual, 2) : ''}" placeholder="${num(c.coef, 2)}" title="${trend}" aria-label="Współczynnik N-1 ${esc(c.short)} (${trend})"><small>${trend}</small></td>${cells}</tr>`;
        }).join('');
        const ev = weeks.map(w => {
            const e = w.event;
            const label = e && Number(e.pct) ? `<span class="lp-ev">${e.pct > 0 ? '+' : ''}${num(e.pct)} %</span><small>${esc(e.name)}${e.inherited || e.yearly ? ' ↻' : ''}</small>` : '<span class="lp-add">+ %</span>';
            return `<td class="n"><button type="button" class="lp-evbtn" data-event="${w.week_start}" aria-label="Dodatkowe palety tydzień ${w.iso_week}">${label}</button></td>`;
        }).join('');
        const hol = weeks.map(w => `<td class="n lp-hols">${holBadges(w)}</td>`).join('');
        const tot = weeks.map(w => `<td class="n"><b class="${w.over ? 'lp-red' : ''}">${w.pallets}</b><small>${w.places} m.</small></td>`).join('');
        view.innerHTML = `<div class="lp-toolbar"><div class="lp-legend lp-src">${Object.entries(SRC).filter(([k]) => k !== 'none').map(([k, t]) => `<span class="src-${k}">${t}</span>`).join('')}</div>
            <form class="lp-actions" data-coef-all><label class="lp-inline">Współczynnik N-1 dla wszystkich <input class="form-control lp-in" name="coef" inputmode="decimal" placeholder="np. 1,10"></label>
                <button type="submit" class="btn btn-outline-primary lp-big">Zastosuj</button><button type="button" class="btn btn-outline-secondary lp-big" data-trend>Trend 3 mies. dla wszystkich</button></form></div>
            <div class="lp-scroll"><table class="lp-fc"><thead><tr><th>Klient</th><th class="n">Śr. 3 mies.</th><th class="n">Wsp. N-1</th>${head}</tr></thead>
            <tbody>${rows || `<tr><td colspan="${weeks.length + 3}" class="at-empty">Brak klientów z wysyłkami w ostatnich 3 miesiącach.</td></tr>`}</tbody>
            <tfoot><tr><th>Dodatkowe palety<small>święta, akcje — % prognozy</small></th><td></td><td></td>${ev}</tr>
                <tr><th>Święta<small>${P.params.departure_country} wyjazd · ${P.params.delivery_country} dostawa${transit() ? ' · ' + transit() + ' przejazd' : ''}</small></th><td></td><td></td>${hol}</tr>
                <tr class="lp-total"><th>Razem palet<small>miejsca w dostępnych ciężarówkach</small></th><td></td><td></td>${tot}</tr></tfoot></table></div>
            <p class="lp-hint">Palety tygodnia = palety tego samego tygodnia rok temu (N-1) × współczynnik klienta (pusty = trend : średnio na tydzień w ostatnich 3 miesiącach ÷ te same tygodnie rok temu) ;
            bez N-1 w tym tygodniu : średnia z ostatnich ${P.params.avg_weeks} tygodni. Zamówienia złożone na tydzień zastępują prognozę. Palety liczone jak w Dniu wysyłki (karta produktu : kartony na paletę, palety mieszane).
            Wpisz liczbę, aby ustawić palety ręcznie ; pusta komórka = prognoza.</p>`;
        bindForecast();
    }

    function bindForecast() {
        view.querySelectorAll('[data-pal]').forEach(inp => inp.addEventListener('change', async () => {
            const [cid, ws] = inp.dataset.pal.split('|');
            const v = inp.value.trim();
            if (v !== '' && !/^\d{1,3}$/.test(v)) { at.notify(false, 'Palety : liczba od 0 do 999.'); return; }
            await api('/ajax/logistics/plan/loads', 'PUT', { items: [{ week_start: ws, customer_id: Number(cid), pallets: v === '' ? null : Number(v) }] }, true);
        }));
        view.querySelectorAll('[data-coef]').forEach(inp => inp.addEventListener('change', async () => {
            await api('/ajax/logistics/plan/coef', 'PUT', { customer_id: Number(inp.dataset.coef), coef: inp.value.trim() || null });
        }));
        view.querySelectorAll('[data-event]').forEach(b => b.addEventListener('click', () => openEvent(b.dataset.event)));
        const all = view.querySelector('[data-coef-all]');
        all.addEventListener('submit', async (e) => {
            e.preventDefault();
            if (!all.coef.value.trim()) return at.notify(false, 'Podaj współczynnik, np. 1,10.');
            await api('/ajax/logistics/plan/coef', 'PUT', { customer_id: null, coef: all.coef.value.trim() });
        });
        view.querySelector('[data-trend]').addEventListener('click', async () => {
            if (await ask('Usunąć współczynniki wpisane ręcznie ? Każdy klient dostanie swój trend z 3 miesięcy.', 'Usuń wpisane')) await api('/ajax/logistics/plan/coef', 'PUT', { customer_id: null, coef: null });
        });
    }

    // --- Święta i kierowcy --------------------------------------------------------------------------------

    function renderCalendar() {
        const weeks = P.weeks.map(w => {
            const opts = [0, 1, 2, 3, 4].map(i => {
                const d = new Date(new Date(w.week_start + 'T12:00:00').getTime() + i * 864e5).toISOString().slice(0, 10);
                return `<option value="${d}" ${w.ship_manual && w.ship_date === d ? 'selected' : ''}>${DAYS[i + 1]} ${dm(d)}</option>`;
            }).join('');
            const note = [w.shifted, ...w.warnings].filter(Boolean).map(t => `<div class="lp-warn">${esc(t)}</div>`).join('');
            return `<tr><td><b>${w.iso_week}</b> <small>${dm(w.week_start)}</small></td>
                <td><select class="form-select lp-sel" data-ship="${w.week_start}" aria-label="Wyjazd tydzień ${w.iso_week}">
                    <option value="" ${w.ship_manual ? '' : 'selected'}>auto : ${wd(w.ship_default)} ${dm(w.ship_default)}</option>${opts}</select></td>
                <td>${wd(w.delivery_date)} ${dm(w.delivery_date)}</td><td>${note || '<span class="lp-sub">—</span>'}</td></tr>`;
        }).join('');
        const hol = P.holidays.map(h => {
            const act = h.skipped ? `<button type="button" class="btn btn-outline-secondary btn-sm" data-hol="${h.date}|${h.country}|remove">Przywróć</button>`
                : (h.source === 'manual' ? `<button type="button" class="btn btn-outline-danger btn-sm" data-hol="${h.date}|${h.country}|remove">Usuń</button>`
                    : `<button type="button" class="btn btn-outline-secondary btn-sm" data-hol="${h.date}|${h.country}|skip">${(P.params.transit_countries || []).includes(h.country) ? 'Jedziemy' : 'Pracujemy'}</button>`);
            return `<tr class="${h.skipped ? 'lp-dim' : ''}"><td>${wd(h.date)} ${dm(h.date)}</td><td><span class="lp-hol">${h.country}</span></td>
                <td>${esc(h.name)}${h.skipped ? ' <small>(pominięte)</small>' : (h.source === 'manual' ? ' <small>(dodane)</small>' : '')}</td><td class="n">${act}</td></tr>`;
        }).join('');
        const abs = P.absences.map(a => `<tr><td><b>${esc(a.employee)}</b></td><td>${dm(a.date_from)} – ${dm(a.date_to)}.${a.date_to.slice(0, 4)}</td><td>${esc(a.reason || '')}</td>
            <td class="n"><button type="button" class="btn btn-outline-danger btn-sm" data-abs="${a.id}" aria-label="Usuń urlop ${esc(a.employee)}"><i class="bi bi-trash"></i></button></td></tr>`).join('');
        view.innerHTML = `<div class="lp-cols">
            <div class="lp-card"><h4>Wyjazd w tygodniu</h4><p class="lp-hint">Zwykle ${DAYS[P.params.ship_weekday]} ; dostawa = wyjazd + ${P.params.delivery_days} dz. Gdy w dniu wyjazdu święto ${P.params.departure_country}
                albo w dniu dostawy święto ${P.params.delivery_country} (lub niedziela)${transit() ? `, albo po drodze święto ${transit()} (zakaz ruchu ciężarówek od wyjazdu do dostawy)` : ''} — wyjazd przesunięty sam (najpierw wcześniej w tygodniu).</p>
                <div class="lp-scroll"><table class="lp-tbl"><thead><tr><th>Tydz.</th><th>Wyjazd</th><th>Dostawa</th><th>Uwagi</th></tr></thead><tbody>${weeks}</tbody></table></div></div>
            <div><div class="lp-card"><h4>Święta i dni wolne</h4><p class="lp-hint">Święta ustawowe ${countriesText()} liczone same${transit() ? ` (${transit()} : święta ogólnokrajowe — regionalne, np. Boże Ciało w NRW, dodaj niżej)` : ''}. « Pracujemy » / « Jedziemy » : święto pominięte w planie.</p>
                <table class="lp-tbl"><tbody>${hol || '<tr><td class="at-empty">Brak świąt w tych tygodniach.</td></tr>'}</tbody></table>
                <form class="lp-form" data-hol-add><input class="form-control" type="date" name="day" required aria-label="Dzień"><select class="form-select" name="country" aria-label="Kraj">
                    <option value="${P.params.departure_country}">${P.params.departure_country} (wyjazd)</option><option value="${P.params.delivery_country}">${P.params.delivery_country} (dostawa)</option>${(P.params.transit_countries || []).map(c => `<option value="${c}">${c} (przejazd)</option>`).join('')}</select>
                    <input class="form-control" name="name" maxlength="80" placeholder="np. zamknięcie zakładu" required aria-label="Nazwa"><button type="submit" class="btn btn-outline-primary lp-big">Dodaj dzień wolny</button></form></div>
            <div class="lp-card"><h4>Urlopy kierowców</h4><p class="lp-hint">Ciężarówka kierowcy na urlopie nie jedzie w dniu wyjazdu — na Tablicy ⚙ : kierowca na zastępstwo.</p>
                <table class="lp-tbl"><tbody>${abs || '<tr><td class="at-empty">Brak urlopów.</td></tr>'}</tbody></table>
                <form class="lp-form" data-abs-add><select class="form-select" name="employee_id" required aria-label="Kierowca"><option value="">Kierowca…</option>${P.drivers.map(d => `<option value="${d.id}">${esc(d.name)}</option>`).join('')}</select>
                    <input class="form-control" type="date" name="date_from" required aria-label="Od"><input class="form-control" type="date" name="date_to" aria-label="Do">
                    <input class="form-control" name="reason" maxlength="120" placeholder="powód (urlop, choroba…)" aria-label="Powód"><button type="submit" class="btn btn-outline-primary lp-big">Dodaj urlop</button></form></div></div></div>`;
        view.querySelectorAll('[data-ship]').forEach(s => s.addEventListener('change', () => api(`/ajax/logistics/plan/weeks/${s.dataset.ship}`, 'PUT', { ship_date: s.value || null })));
        view.querySelectorAll('[data-hol]').forEach(b => b.addEventListener('click', async () => {
            const [day, country, act] = b.dataset.hol.split('|');
            b.disabled = true;
            if (!(await api('/ajax/logistics/plan/holidays', 'PUT', act === 'remove' ? { day, country, remove: true } : { day, country, off: false }))) b.disabled = false;
        }));
        view.querySelectorAll('[data-abs]').forEach(b => b.addEventListener('click', async () => {
            if (await ask('Usunąć ten urlop ?', 'Usuń')) api(`/ajax/logistics/plan/absences/${b.dataset.abs}`, 'DELETE');
        }));
        const hf = view.querySelector('[data-hol-add]');
        hf.addEventListener('submit', async (e) => { e.preventDefault(); await api('/ajax/logistics/plan/holidays', 'PUT', { day: hf.day.value, country: hf.country.value, name: hf.name.value, off: true }); });
        const af = view.querySelector('[data-abs-add]');
        af.addEventListener('submit', async (e) => {
            e.preventDefault();
            await api('/ajax/logistics/plan/absences', 'POST', { employee_id: af.employee_id.value, date_from: af.date_from.value, date_to: af.date_to.value || af.date_from.value, reason: af.reason.value });
        });
    }

    // --- Kalendarz tras [207] (makieta 1) -------------------------------------------------------------------
    // Ciężarówki w wierszach, oś czasu (dzień, 4 dni, tydzień, miesiąc) ; fazy trasy : załadunek, jazda, przerwa,
    // odpoczynek, rozładunek, powrót, odpoczynek tygodniowy. Przeciągnij blok = przesuń (poprzednia faza dłuższa / krótsza,
    // pierwszy blok = godzina wyjazdu), przeciągnij prawy brzeg = czas fazy, dotknij = okienko (rodzaj, klient, czas,
    // kolejność, dodaj, usuń). Kontrola czasu pracy kierowcy (UE 561/2006) z serwera po zapisie.
    const KIND = {
        load: { label: 'Załadunek', short: 'Załad.', color: '#8D1D2C', text: '#fff' },
        drive: { label: 'Jazda', short: 'Jazda', color: '#3d3a37', text: '#fff' },
        brk: { label: 'Przerwa', short: 'P', color: '#E2A33B', text: '#2b1d05' },
        rest: { label: 'Odpoczynek dobowy', short: 'Odp.', color: '#B9B2A8', text: '#2b2b2b', hatch: true },
        unload: { label: 'Rozładunek', short: 'Rozł.', color: '#2E7D4F', text: '#fff' },
        back: { label: 'Powrót', short: 'Powr.', color: '#4A6FA5', text: '#fff' },
        weekly: { label: 'Odpoczynek tygodniowy', short: 'Odp. tyg.', color: '#D9D3CB', text: '#2b2b2b', hatch: true },
    };
    const ZOOM = { day: ['Dzień', 1, 5], '4d': ['4 dni', 4, 15], week: ['Tydzień', 7, 30], month: ['Miesiąc', 28, 60] };
    const tv = { zoom: '4d', week: null, day: null, sel: null, draft: null };
    const T = (s) => new Date(String(s).replace(' ', 'T')).getTime();
    const pad = (n) => String(n).padStart(2, '0');
    const iso = (t) => { const d = new Date(t); return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`; };
    const hm = (t) => { const d = new Date(t); return `${pad(d.getHours())}:${pad(d.getMinutes())}`; };
    const dlab = (t) => { const d = new Date(t); return `${DAYS[d.getDay() || 7]} ${d.getDate()}.${pad(d.getMonth() + 1)}`; };
    const dur = (m) => (m >= 60 ? `${Math.floor(m / 60)} h${m % 60 ? ' ' + pad(m % 60) : ''}` : `${m} min`);
    const parseDur = (v) => {
        const s = String(v).trim().toLowerCase().replace(',', '.');
        let m = s.match(/^(\d+)\s*(?:h|:)\s*(\d{1,2})?\s*(?:min)?$/);
        if (m) return Number(m[1]) * 60 + Number(m[2] || 0);
        m = s.match(/^(\d+(?:\.\d+)?)\s*h$/);
        if (m) return Math.round(Number(m[1]) * 60);
        m = s.match(/^(\d+)\s*(?:min|m)?$/);
        return m ? Number(m[1]) : null;
    };
    const tripKey = (t) => `${t.week_start}|${t.vehicle_id}|${t.trip_no || 1}`;
    const tripsOf = (ws) => (P.trips || []).filter(t => t.week_start === ws);
    const curWeek = () => {
        if (tv.week && W()[tv.week]) return tv.week;
        const withTrip = P.weeks.find(w => tripsOf(w.week_start).length);
        return (withTrip || P.weeks[0]).week_start;
    };
    // Fazy trasy (wersja robocza w trakcie edycji) z godzinami liczonymi w przeglądarce.
    const phasesOf = (t) => {
        const d = tv.draft && tv.draft.key === tripKey(t) ? tv.draft : null;
        let cur = T(d ? d.start_at : t.start_at);
        return (d ? d.phases : t.phases).map(p => { const s = cur; cur += p.m * 60000; return { ...p, s, e: cur }; });
    };
    const checkText = (c) => ({
        CONT_DRIVE: `jazda bez przerwy maks. ${dur(c.value)} (≤ 4 h 30)`,
        DAY_DRIVE: `jazda dzienna maks. ${dur(c.value)} (≤ 9 h, 2× w tyg. 10 h)`,
        DAILY_REST: `odpoczynek dobowy min. ${dur(c.value)} (≥ 11 h, skrócony 9 h)`,
        WEEK_DRIVE: `jazda w tygodniu ${dur(c.value)} (≤ 56 h)`,
    }[c.code] || c.code);
    const warnText = (w) => ({
        MISSING: `brak rozładunku : ${(w.names || []).join(', ')}`,
        EXTRA: `nie na tej ciężarówce : ${(w.names || []).join(', ')}`,
        SHIP_DATE: `wyjazd w planie : ${w.date ? wd(w.date) + ' ' + dm(w.date) : ''}`,
        OVERLAP: `trasa ${w.no} zaczyna się przed końcem poprzedniej (z odpoczynkiem 11 h)`,
    }[w.code] || w.code);

    function tripRange() {
        const ws = curWeek();
        const w = W()[ws];
        const z = ZOOM[tv.zoom];
        let from;
        if (tv.zoom === 'day') from = T((tv.day || w.ship_date) + ' 00:00');
        else if (tv.zoom === '4d') from = T(w.ship_date + ' 00:00');
        else from = T(ws + ' 00:00');
        return { ws, w, from, to: from + z[1] * 86400000 };
    }

    function renderTrips() {
        const { ws, w, from, to } = tripRange();
        const month = tv.zoom === 'month';
        const weeks = month ? P.weeks.filter(x => T(x.week_start + ' 00:00') >= from && T(x.week_start + ' 00:00') < to).map(x => x.week_start) : [ws];
        const trips = (P.trips || []).filter(t => weeks.includes(t.week_start));
        const rowsBy = {};
        trips.forEach(t => { (rowsBy[t.vehicle_id] ??= []).push(t); });
        const vids = Object.keys(rowsBy).map(Number).sort((a, b) => String(rowsBy[a][0].label).localeCompare(String(rowsBy[b][0].label)));
        const width = Math.max(320, view.clientWidth - 232);
        const px = (t) => (t - from) / (to - from) * width;
        // Oś : etykiety dni i godzin.
        const step = { day: 2, '4d': 6, week: 12, month: 24 }[tv.zoom] * 3600000;
        let axis = '', lines = '';
        for (let t = from; t <= to; t += step) {
            const d = new Date(t);
            const midnight = d.getHours() === 0;
            if (t < to && midnight && (!month || d.getDay() === 1 || t === from)) axis += `<span class="kc-d" style="left:${px(t)}px">${month ? dm(iso(t).slice(0, 10)) : dlab(t)}</span>`;
            if (!month && t > from && t < to) axis += `<span class="kc-t" style="left:${px(t)}px">${hm(t)}</span>`;
            lines += `<i class="kc-vl ${midnight ? '' : 'minor'}" style="left:${232 + px(t)}px"></i>`;
        }
        const now = Date.now();
        if (now > from && now < to) lines += `<i class="kc-now" style="left:${232 + px(now)}px" title="teraz"></i>`;
        const seg = (t, p, i, sel) => {
            const k = KIND[p.k] || KIND.drive;
            const l = Math.max(0, px(p.s)), r = Math.min(width, px(p.e));
            if (r <= 0 || l >= width) return '';
            const wpx = r - l;
            const lab = month ? '' : p.k === 'unload' ? `${esc(p.client || '')}<br>${p.pallets ?? ''}${p.pallets !== undefined ? ' pal.' : ''}`
                : wpx > 64 ? `${esc(k.label.replace('Odpoczynek ', 'Odp. '))}<br>${dur(p.m)}` : (wpx > 28 ? dur(p.m).replace(' h', 'h').replace(' min', '′') : '');
            return `<div class="kc-s ${k.hatch ? 'kc-hatch' : ''} ${sel ? 'sel' : ''}" data-seg="${tripKey(t)}|${i}" tabindex="0" role="button"
                aria-label="${esc(`${k.label}${p.client ? ' ' + p.client : ''} ${dlab(p.s)} ${hm(p.s)}–${hm(p.e)}`)}" title="${esc(`${k.label}${p.client ? ' · ' + p.client : ''} · ${dlab(p.s)} ${hm(p.s)}–${hm(p.e)} · ${dur(p.m)}`)}"
                style="left:${l}px;width:${Math.max(2, wpx - 1)}px;background:${k.color};color:${k.text}">${lab}${sel && !month ? '<i class="kc-h" data-resize></i>' : ''}</div>`;
        };
        let rows = '';
        vids.forEach(vid => {
            const list = rowsBy[vid];
            const t = list[0];
            const segs = list.map(x => phasesOf(x).map((p, i) => seg(x, p, i, tv.sel && tv.sel.key === tripKey(x) && tv.sel.i === i)).join('')).join('');
            const veh = V()[vid];
            const per = veh ? veh.trips_per_week : 1;
            let info = `<b>${esc(t.label)}</b><small>${esc(t.driver || '—')}</small>`;
            if (!month) {
                const multi = list.length > 1;
                info += list.map(x => {
                    const xp = phasesOf(x);
                    const arrive = xp.find(p => p.k === 'unload');
                    const home = xp.filter(p => !['weekly', 'rest', 'brk'].includes(p.k)).at(-1);
                    return `<small>${multi ? `<b>Trasa ${x.trip_no}</b> · ` : ''}${x.pallets} / ${x.places} pal. · ${x.clients} klientów</small>
                        <small>wyjazd ${dlab(xp[0].s)} ${hm(xp[0].s)}${arrive ? ` · u klienta ${dlab(arrive.s)} ${hm(arrive.s)}` : ''}${home ? ` · baza ${dlab(home.e)} ${hm(home.e)}` : ''}</small>
                        ${x.manual ? `<span class="kc-man">zmieniona ręcznie · <button type="button" class="kc-link" data-trip-reset="${tripKey(x)}">przywróć szablon</button></span>` : ''}`;
                }).join('');
                info += veh ? `<small class="kc-per-l">Tras w tygodniu (standard 1)</small><div class="kc-per" role="group" aria-label="Tras w tygodniu ${esc(t.label)}">${[1, 2, 3, 4].map(n => `<button type="button" data-per="${vid}|${n}" class="${per === n ? 'on' : ''}" aria-pressed="${per === n}">${n}</button>`).join('')}</div>
                    ${veh.cells[ws] && veh.cells[ws].trips_override ? `<small class="kc-man">w tym tygodniu : ${veh.cells[ws].trips} (⚙ Tablica)</small>` : ''}` : '';
            } else info += `<small>${list.length} × trasa${per > 1 ? ` · ${per} / tydz.` : ''}</small>`;
            const chips = month ? '' : `<div class="kc-chk">${list.map(x => x.checks.map(c => `<span class="kc-chip ${c.level === 'ok' ? '' : c.level}">${c.level === 'ok' ? '✓' : '⚠'} ${list.length > 1 && c.code !== 'WEEK_DRIVE' ? 'T' + x.trip_no + ' ' : ''}${esc(checkText(c))}</span>`).join('')
                    + x.warnings.map(w2 => `<span class="kc-chip bad">⚠ ${esc(warnText(w2))}</span>`).join('') + (tv.draft && tv.draft.key === tripKey(x) ? '<span class="kc-chip warn">niezapisane zmiany</span>' : '')).join('')}</div>`;
            rows += `<div class="kc-row ${month ? 'is-month' : ''} ${list.length > 1 && !month ? 'is-multi' : ''}" data-trip-row="${vid}"><div class="kc-who">${info}</div><div class="kc-track" data-track="${vid}">${segs}${chips}</div></div>`;
        });
        const label = tv.zoom === 'day' ? dlab(from) : `${month ? '' : 'tydz. ' + w.iso_week + ' · '}${dlab(from)} → ${dlab(to - 1)}`;
        view.innerHTML = `<div class="kc-bar">
                <div class="kc-seg" role="group" aria-label="Zakres">${Object.entries(ZOOM).map(([k, z]) => `<button type="button" data-zoom="${k}" class="${tv.zoom === k ? 'on' : ''}" aria-pressed="${tv.zoom === k}">${z[0]}</button>`).join('')}</div>
                <div class="kc-seg kc-nav"><button type="button" data-tnav="-1" aria-label="Wcześniej">‹</button><span>${esc(label)}</span><button type="button" data-tnav="1" aria-label="Później">›</button></div>
                <div class="kc-legend">${Object.values(KIND).map(k => `<span><i class="${k.hatch ? 'kc-hatch' : ''}" style="background:${k.color}"></i>${k.label}</span>`).join('')}</div>
                <div class="lp-actions"><button type="button" class="btn btn-outline-secondary lp-big" data-trip-tpl><i class="bi bi-sliders"></i> Szablon trasy</button>
                    <button type="button" class="btn btn-primary lp-big" data-trip-rest ${month ? 'disabled' : ''}><i class="bi bi-magic"></i> Wstaw przerwy i odpoczynki</button></div></div>
            <div class="kc-card kc-gantt">
                <div class="kc-axis"><div class="kc-axis-l">${month ? 'miesiąc' : 'tydz. ' + w.iso_week} · ciężarówka</div><div class="kc-ticks">${axis}</div></div>
                ${lines}${rows || `<p class="at-empty kc-empty">Brak tras : nikt nie jest na ciężarówce w tym tygodniu (Tablica → Rozdziel automatycznie albo przeciągnij klientów).</p>`}
                <div data-trip-pop></div>
            </div>
            <p class="lp-hint">Trasa = szablon (załadunek, jazda tam, rozładunek u klientów w kolejności trasy, powrót, odpoczynek tygodniowy ; przerwy co 4 h 30 i odpoczynek dobowy 11 h wstawiane same)
                od dnia wyjazdu tygodnia. Przeciągnij blok, aby go przesunąć (pierwszy blok = godzina wyjazdu), przeciągnij prawy brzeg, aby zmienić czas, dotknij bloku, aby zmienić rodzaj, klienta, kolejność, dodać albo usunąć fazę.
                Kontrola czasu pracy kierowcy (UE 561/2006) po każdym zapisie. Tras / tydz. : standard 1 trasa na kierowcę na tydzień ; przy 2–4 trasach klienci dzieleni według miejsc,
                następna trasa po odpoczynku dobowym 11 h, jazda w tygodniu ≤ 56 h.</p>`;
        view.dataset.from = from; view.dataset.to = to; view.dataset.width = width;
        if (tv.sel && !month) renderPopover();
    }

    function tripByKey(key) { return (P.trips || []).find(t => tripKey(t) === key); }
    function startDraft(t) {
        if (!tv.draft || tv.draft.key !== tripKey(t)) tv.draft = { key: tripKey(t), start_at: t.start_at, phases: t.phases.map(p => ({ k: p.k, m: p.m, ...(p.client_id ? { client_id: p.client_id, client: p.client, pallets: p.pallets } : {}) })) };
        return tv.draft;
    }

    function renderPopover() {
        const box = view.querySelector('[data-trip-pop]');
        const t = tripByKey(tv.sel.key);
        if (!box || !t) return;
        const ph = phasesOf(t);
        const i = tv.sel.i;
        const p = ph[i];
        if (!p) { tv.sel = null; return; }
        const k = KIND[p.k];
        const row = view.querySelector(`[data-trip-row="${t.vehicle_id}"]`);
        const width = Number(view.dataset.width);
        const left = Math.min(Math.max(232, 232 + (p.s - Number(view.dataset.from)) / (Number(view.dataset.to) - Number(view.dataset.from)) * width), 232 + width - 340);
        const clients = [...t.stops.map(s => [s.client_id, s.client]), ...(p.client_id && !t.stops.some(s => s.client_id === p.client_id) ? [[p.client_id, p.client || '#' + p.client_id]] : [])];
        box.innerHTML = `<div class="kc-pop" style="left:${left}px;top:${(row ? row.offsetTop + row.offsetHeight - 6 : 60)}px" role="dialog" aria-label="Faza trasy">
            <div class="kc-pop-h"><b>${esc(k.label)}${p.client ? ' · ' + esc(p.client) : ''}</b><span class="kc-chip">${esc(t.label)}${t.trips > 1 ? ' · trasa ' + t.trip_no : ''}</span></div>
            <div class="kc-pop-g">
                <label>Faza<select class="form-select" data-pop="k">${Object.entries(KIND).map(([key, x]) => `<option value="${key}" ${p.k === key ? 'selected' : ''}>${x.label}</option>`).join('')}</select></label>
                ${p.k === 'unload' ? `<label>Klient<select class="form-select" data-pop="client">${clients.map(([id, n]) => `<option value="${id}" ${p.client_id === id ? 'selected' : ''}>${esc(n)}</option>`).join('')}</select></label>` : '<span></span>'}
                <label>Początek${i === 0 ? `<input class="form-control" type="datetime-local" data-pop="start" value="${iso(p.s).replace(' ', 'T')}">` : `<input class="form-control" value="${dlab(p.s)} ${hm(p.s)}" disabled>`}</label>
                <label>Koniec<input class="form-control" value="${dlab(p.e)} ${hm(p.e)}" disabled></label>
            </div>
            <label class="kc-pop-dur">Czas trwania<div class="kc-step"><button type="button" data-pop-step="-15" aria-label="−15 min">−</button><input class="form-control" data-pop="m" value="${dur(p.m)}" aria-label="Czas trwania"><button type="button" data-pop-step="15" aria-label="+15 min">+</button></div></label>
            <div class="kc-pop-a"><button type="button" class="btn btn-outline-secondary btn-sm" data-pop-move="-1" ${i === 0 ? 'disabled' : ''}>↑ Wcześniej</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-pop-move="1" ${i === ph.length - 1 ? 'disabled' : ''}>↓ Później</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-pop-add>+ Faza po</button>
                <button type="button" class="btn btn-outline-danger btn-sm" data-pop-del ${ph.length < 2 ? 'disabled' : ''}>Usuń fazę</button></div>
            <div class="kc-pop-f"><button type="button" class="btn btn-outline-secondary" data-pop-cancel>Anuluj</button><button type="button" class="btn btn-primary" data-pop-save>Zapisz trasę</button></div>
        </div>`;
    }

    async function saveDraft() {
        const d = tv.draft;
        if (!d) return;
        const [ws, vid, no] = d.key.split('|');
        const res = await api(`/ajax/logistics/plan/trips/${ws}/${vid}/${no}`, 'PUT', { start_at: d.start_at, phases: d.phases.map(p => ({ k: p.k, m: p.m, ...(p.k === 'unload' ? { client_id: p.client_id } : {}) })) });
        if (res) tv.draft = null;
    }

    view.addEventListener('click', async (e) => {
        if (tab !== 'trips') return;
        const b = e.target.closest('button, [data-seg]');
        if (!b) { if (!e.target.closest('.kc-pop') && tv.sel) { tv.sel = null; if (!tv.draft) renderTrips(); else renderTrips(); } return; }
        if (b.dataset.zoom) { tv.zoom = b.dataset.zoom; tv.sel = null; if (b.dataset.zoom === 'day') tv.day = W()[curWeek()].ship_date; renderTrips(); return; }
        if (b.dataset.tnav) {
            const dir = Number(b.dataset.tnav);
            tv.sel = null;
            if (tv.zoom === 'day') { const d = new Date(T((tv.day || W()[curWeek()].ship_date) + ' 12:00') + dir * 86400000); tv.day = iso(d.getTime()).slice(0, 10); const mon = new Date(d); mon.setDate(d.getDate() - ((d.getDay() + 6) % 7)); const m = iso(mon.getTime()).slice(0, 10); if (W()[m]) tv.week = m; }
            else { const idx = P.weeks.findIndex(x => x.week_start === curWeek()); const n = P.weeks[Math.min(P.weeks.length - 1, Math.max(0, idx + dir * (tv.zoom === 'month' ? 4 : 1)))]; tv.week = n.week_start; }
            renderTrips();
            return;
        }
        if (b.matches('[data-trip-tpl]')) { openTripTemplate(); return; }
        if (b.matches('[data-trip-rest]')) { b.disabled = true; tv.draft = null; tv.sel = null; await api(`/ajax/logistics/plan/trips/${curWeek()}/rest`, 'POST', {}); return; }
        if (b.dataset.tripReset) {
            const [ws, vid, no] = b.dataset.tripReset.split('|');
            if (await ask('Przywrócić trasę z szablonu ? Zmiany ręczne tej trasy zostaną usunięte.', 'Przywróć')) { tv.draft = null; tv.sel = null; await api(`/ajax/logistics/plan/trips/${ws}/${vid}/${no}`, 'DELETE'); }
            return;
        }
        if (b.dataset.per) {
            const [vid, n] = b.dataset.per.split('|');
            await api(`/ajax/logistics/plan/vehicles/${vid}/trips`, 'PUT', { trips_per_week: Number(n) });
            return;
        }
        if (b.dataset.seg) {
            if (segDrag && segDrag.moved) return;
            const parts = b.dataset.seg.split('|');
            const key = parts.slice(0, 3).join('|');
            if (tv.zoom === 'month') { tv.zoom = '4d'; tv.week = parts[0]; }
            if (tv.draft && tv.draft.key !== key && !(await ask('Zapisać zmiany poprzedniej trasy ?', 'Zapisz'))) tv.draft = null;
            else if (tv.draft && tv.draft.key !== key) await saveDraft();
            tv.sel = { key, i: Number(parts[3]) };
            renderTrips();
            return;
        }
        const pop = e.target.closest('.kc-pop');
        if (!pop || !tv.sel) return;
        const t = tripByKey(tv.sel.key);
        const d = startDraft(t);
        const i = tv.sel.i;
        if (b.dataset.popStep) d.phases[i].m = Math.max(5, d.phases[i].m + Number(b.dataset.popStep));
        else if (b.dataset.popMove) {
            const j = i + Number(b.dataset.popMove);
            if (j < 0 || j >= d.phases.length) return;
            [d.phases[i], d.phases[j]] = [d.phases[j], d.phases[i]];
            tv.sel.i = j;
        } else if (b.matches('[data-pop-add]')) { d.phases.splice(i + 1, 0, { k: 'drive', m: 60 }); tv.sel.i = i + 1; }
        else if (b.matches('[data-pop-del]')) { d.phases.splice(i, 1); tv.sel.i = Math.max(0, i - 1); }
        else if (b.matches('[data-pop-cancel]')) { tv.draft = null; tv.sel = null; }
        else if (b.matches('[data-pop-save]')) { await saveDraft(); return; }
        renderTrips();
    });

    view.addEventListener('change', (e) => {
        if (tab !== 'trips' || !tv.sel) return;
        const el = e.target.closest('[data-pop]');
        if (!el) return;
        const t = tripByKey(tv.sel.key);
        const d = startDraft(t);
        const p = d.phases[tv.sel.i];
        if (el.dataset.pop === 'k') {
            p.k = el.value;
            if (p.k === 'unload' && !p.client_id) { const s = t.stops[0]; if (s) { p.client_id = s.client_id; p.client = s.client; p.pallets = s.pallets; } }
            if (p.k !== 'unload') { delete p.client_id; delete p.client; delete p.pallets; }
        } else if (el.dataset.pop === 'client') {
            const s = t.stops.find(x => x.client_id === Number(el.value));
            p.client_id = Number(el.value); p.client = s ? s.client : '#' + el.value; p.pallets = s ? s.pallets : undefined;
        } else if (el.dataset.pop === 'start') {
            if (el.value) d.start_at = el.value.replace('T', ' ').slice(0, 16);
        } else if (el.dataset.pop === 'm') {
            const m = parseDur(el.value);
            if (m === null || m < 1 || m > 6000) { at.notify(false, 'Czas : np. 45, 1:30 albo 4 h 30.'); el.value = dur(p.m); return; }
            p.m = m;
        }
        renderTrips();
    });

    view.addEventListener('keydown', (e) => {
        if (tab !== 'trips') return;
        const s = e.target.closest('[data-seg]');
        if (s && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); s.click(); }
        if (e.key === 'Escape' && tv.sel) { tv.sel = null; tv.draft = null; renderTrips(); }
    });

    // Przeciąganie bloku (przesuń) i prawego brzegu (czas fazy) ; zapis po puszczeniu.
    let segDrag = null;
    view.addEventListener('pointerdown', (e) => {
        if (tab !== 'trips' || tv.zoom === 'month' || e.button > 0) return;
        const s = e.target.closest('[data-seg]');
        if (!s) return;
        const parts = s.dataset.seg.split('|');
        const key = parts.slice(0, 3).join('|');
        if (tv.draft && tv.draft.key !== key) return;
        const t = tripByKey(key);
        const d = startDraft(t);
        segDrag = { key, i: Number(parts[3]), mode: e.target.closest('[data-resize]') ? 'resize' : 'move', x0: e.clientX, moved: false,
            start0: d.start_at, m0: d.phases.map(p => p.m), track: s.closest('[data-track]') };
        s.setPointerCapture(e.pointerId);
    });
    view.addEventListener('pointermove', (e) => {
        if (!segDrag) return;
        const span = Number(view.dataset.to) - Number(view.dataset.from);
        const snap = ZOOM[tv.zoom][2];
        const delta = Math.round((e.clientX - segDrag.x0) / Number(view.dataset.width) * span / 60000 / snap) * snap;
        if (!delta && !segDrag.moved) return;
        segDrag.moved = true;
        const d = tv.draft;
        const i = segDrag.i;
        if (segDrag.mode === 'resize') d.phases[i].m = Math.max(5, segDrag.m0[i] + delta);
        else if (i === 0) d.start_at = iso(T(segDrag.start0) + delta * 60000);
        else d.phases[i - 1].m = Math.max(5, segDrag.m0[i - 1] + delta);
        // Tylko pozycje bloków tej trasy (bez przebudowy — przeciąganie trwa).
        const t = tripByKey(segDrag.key);
        const from = Number(view.dataset.from), width = Number(view.dataset.width);
        const px = (x) => (x - from) / span * width;
        phasesOf(t).forEach((p, j) => {
            const el = segDrag.track.querySelector(`[data-seg="${segDrag.key}|${j}"]`);
            if (!el) return;
            const l = Math.max(0, px(p.s)), r = Math.min(width, px(p.e));
            el.style.left = l + 'px';
            el.style.width = Math.max(2, r - l - 1) + 'px';
        });
    });
    const endDrag = async () => {
        if (!segDrag) return;
        const moved = segDrag.moved;
        const key = segDrag.key, i = segDrag.i;
        setTimeout(() => { segDrag = null; }, 0);
        if (!moved) { if (!tv.sel || tv.sel.key !== key) tv.draft = tv.draft && tv.draft.key === key && JSON.stringify(tv.draft.phases.map(p => p.m)) === JSON.stringify(tripByKey(key).phases.map(p => p.m)) ? null : tv.draft; return; }
        tv.sel = { key, i };
        await saveDraft();
    };
    view.addEventListener('pointerup', endDrag);
    view.addEventListener('pointercancel', endDrag);
    window.addEventListener('resize', () => { if (tab === 'trips') renderTrips(); });

    function openTripTemplate() {
        const s = P.trip_template;
        const f = (name, label, value, hint = '') => `<label class="lp-field">${label}<input class="form-control" name="${name}" inputmode="numeric" value="${value}">${hint ? `<small>${hint}</small>` : ''}</label>`;
        modal('Szablon trasy', `<p class="lp-hint">Czasy bez przerw — przerwy 45 min (co 4 h 30 jazdy) i odpoczynek dobowy 11 h (po 9 h jazdy) wstawiane same. Trasy zmienione ręcznie zostają bez zmian.</p>
            <div class="lp-form-grid">
                <label class="lp-field">Godzina załadunku<input class="form-control" type="time" name="departure_time" value="${esc(s.departure_time)}"><small>gdy ciężarówka nie ma własnej</small></label>
                ${f('load_min', 'Załadunek (min)', s.load_min)}
                ${f('drive_out_min', 'Jazda do 1. klienta (min)', s.drive_out_min, dur(s.drive_out_min))}
                ${f('between_min', 'Jazda między klientami (min)', s.between_min)}
                ${f('unload_min', 'Rozładunek (min)', s.unload_min)}
                ${f('unload_pallet_min', '+ na paletę (min)', s.unload_pallet_min)}
                ${f('drive_back_min', 'Powrót do bazy (min)', s.drive_back_min, dur(s.drive_back_min))}
                ${f('weekly_rest_h', 'Odpoczynek tygodniowy (h)', Math.round(s.weekly_rest_min / 60), '45 h ; skrócony 24 h')}
            </div>`, async (form) => {
            const v = (n) => form[n].value.trim();
            const res = await api('/ajax/logistics/plan/trip-template', 'PUT', { departure_time: v('departure_time'), load_min: v('load_min'), drive_out_min: v('drive_out_min'), between_min: v('between_min'),
                unload_min: v('unload_min'), unload_pallet_min: v('unload_pallet_min'), drive_back_min: v('drive_back_min'), weekly_rest_min: String(Math.round(Number(v('weekly_rest_h').replace(',', '.')) * 60)) });
            return !!res;
        });
    }

    render();
})();
