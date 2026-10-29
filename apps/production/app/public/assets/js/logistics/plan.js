/*
 * Plan logistyki na 12 tygodni [200] (Logistyka i pakowanie › Ciężarówki : plan i tydzień › Plan 12 tygodni).
 * Tablica : ciężarówki × tygodnie, klienci (palety) przeciągani palcem albo myszą na ciężarówkę tego samego tygodnia
 *   (albo : dotknij klienta, potem ciężarówkę) ; ⚙ = kierowca na zastępstwo / ciężarówka niedostępna ; tydzień : kopiuj
 *   układ na następne, zatwierdź (pojazdy klientów na dzień wyjazdu).
 * Prognoza : klienci × tygodnie (N-1 × współczynnik, bez N-1 średnia 3 mies., zamówione, wpisane), współczynnik klienta
 *   albo dla wszystkich, dodatkowe palety tygodnia (% , co roku).
 * Święta i kierowcy : dzień wyjazdu tygodnia, dni wolne PL / BE (pomiń, dodaj), urlopy kierowców.
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
    let tab = ({ '#prognoza': 'forecast', '#swieta': 'calendar' })[location.hash] || 'board';
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
            + tile('Dni świąt', num(k.holiday_days), `pn–pt, ${P.params.departure_country} i ${P.params.delivery_country}`, k.holiday_days ? 'is-bad' : '')
            + tile('Do rozplanowania', num(k.unassigned) + ' pal.', k.absent_drivers ? `${k.absent_drivers} × kierowca na urlopie` : `${k.approved} z ${P.weeks.length} tyg. zatwierdzonych`, k.unassigned ? 'is-bad' : 'is-ok');
    }

    root.querySelectorAll('[data-tab]').forEach(b => b.addEventListener('click', () => {
        tab = b.dataset.tab;
        history.replaceState(null, '', location.pathname + location.search + ({ forecast: '#prognoza', calendar: '#swieta' }[tab] || ''));
        render();
    }));

    function render() {
        root.querySelectorAll('[data-tab]').forEach(b => { b.classList.toggle('on', b.dataset.tab === tab); b.setAttribute('aria-selected', b.dataset.tab === tab); });
        renderNav();
        renderKpis();
        if (tab === 'forecast') renderForecast();
        else if (tab === 'calendar') renderCalendar();
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
            <div class="lp-actions"><button type="button" class="btn btn-outline-primary lp-big" data-auto><i class="bi bi-magic"></i> Rozdziel automatycznie</button>
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
                <span>${esc(v.carrier || '')} · ${v.places}${v.places_known ? '' : ' ?'} m.${v.active ? '' : ' · nieaktywna'}</span><span>${esc(v.driver.name || 'kierowca przewoźnika')}</span></div></div>`;
            for (const w of P.weeks) {
                const cell = v.cells[w.week_start];
                const gear = `<button type="button" class="lp-gear" data-vw="${v.id}|${w.week_start}" title="Kierowca / dostępność w tym tygodniu" aria-label="Kierowca i dostępność ${esc(v.label)} tydzień ${w.iso_week}"><i class="bi bi-gear"></i></button>`;
                const chips = cell.customers.map(id => chip(cmap[id], w.week_start)).join('');
                if (!cell.usable && !chips) {
                    const why = cell.absent ? `urlop<br>${esc(cell.driver.name)}` : (cell.unavailable ? 'niedostępna' : 'nieaktywna');
                    html += `<div class="lp-cell lp-off" data-drop="vehicle" data-vehicle="${v.id}" data-week="${w.week_start}"><span>${why}</span>${gear}</div>`;
                    continue;
                }
                const note = cell.absent ? `<span class="lp-warn">urlop : ${esc(cell.driver.name)}</span>` : (cell.unavailable ? '<span class="lp-warn">niedostępna</span>'
                    : (cell.driver_override ? `<span class="lp-sub">${esc(cell.driver.name)}</span>` : ''));
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
            <label class="lp-check"><input type="checkbox" name="unavailable" ${cell.unavailable ? 'checked' : ''}> Ciężarówka niedostępna w tym tygodniu</label>`;
        modal(`${v.label} · tydzień ${w.iso_week}`, body, async (form) => {
            const res = await api(`/ajax/logistics/plan/weeks/${ws}/vehicles/${vid}`, 'PUT', { driver_id: form.driver_id.value || null, unavailable: form.unavailable.checked });
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
                <tr><th>Święta<small>${P.params.departure_country} wyjazd · ${P.params.delivery_country} dostawa</small></th><td></td><td></td>${hol}</tr>
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
                    : `<button type="button" class="btn btn-outline-secondary btn-sm" data-hol="${h.date}|${h.country}|skip">Pracujemy</button>`);
            return `<tr class="${h.skipped ? 'lp-dim' : ''}"><td>${wd(h.date)} ${dm(h.date)}</td><td><span class="lp-hol">${h.country}</span></td>
                <td>${esc(h.name)}${h.skipped ? ' <small>(pominięte)</small>' : (h.source === 'manual' ? ' <small>(dodane)</small>' : '')}</td><td class="n">${act}</td></tr>`;
        }).join('');
        const abs = P.absences.map(a => `<tr><td><b>${esc(a.employee)}</b></td><td>${dm(a.date_from)} – ${dm(a.date_to)}.${a.date_to.slice(0, 4)}</td><td>${esc(a.reason || '')}</td>
            <td class="n"><button type="button" class="btn btn-outline-danger btn-sm" data-abs="${a.id}" aria-label="Usuń urlop ${esc(a.employee)}"><i class="bi bi-trash"></i></button></td></tr>`).join('');
        view.innerHTML = `<div class="lp-cols">
            <div class="lp-card"><h4>Wyjazd w tygodniu</h4><p class="lp-hint">Zwykle ${DAYS[P.params.ship_weekday]} ; dostawa = wyjazd + ${P.params.delivery_days} dz. Gdy w dniu wyjazdu święto ${P.params.departure_country}
                albo w dniu dostawy święto ${P.params.delivery_country} (lub niedziela) — wyjazd przesunięty sam (najpierw wcześniej w tygodniu).</p>
                <div class="lp-scroll"><table class="lp-tbl"><thead><tr><th>Tydz.</th><th>Wyjazd</th><th>Dostawa</th><th>Uwagi</th></tr></thead><tbody>${weeks}</tbody></table></div></div>
            <div><div class="lp-card"><h4>Święta i dni wolne</h4><p class="lp-hint">Święta ustawowe ${P.params.departure_country} i ${P.params.delivery_country} liczone same. « Pracujemy » : święto pominięte w planie.</p>
                <table class="lp-tbl"><tbody>${hol || '<tr><td class="at-empty">Brak świąt w tych tygodniach.</td></tr>'}</tbody></table>
                <form class="lp-form" data-hol-add><input class="form-control" type="date" name="day" required aria-label="Dzień"><select class="form-select" name="country" aria-label="Kraj">
                    <option value="${P.params.departure_country}">${P.params.departure_country} (wyjazd)</option><option value="${P.params.delivery_country}">${P.params.delivery_country} (dostawa)</option></select>
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

    render();
})();
