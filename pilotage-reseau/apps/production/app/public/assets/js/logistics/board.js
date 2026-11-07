/*
 * Logistyka i pakowanie (28/10/2026) : ekrany dnia wysyłki na tablet i biuro (pakowanie : osobny tablet /tablet/packing).
 *  - live    : paletyzacja na żywo (odświeżanie co 10 s) — na klienta : na paletach / gotowe (stan + spakowane) / brak ;
 *  - loading : załadunek — naczepa (miejsca palet), klienci w kolejności rozładunku, przeciągnij paletę na miejsce,
 *              klienta w kolejności / na inny pojazd / poza pojazd (mysz i palec, albo dotknij i dotknij), plan na tablet kierowcy.
 */
(function () {
    'use strict';
    const el = document.getElementById('lgBoard');
    if (!el || !window.at) return;
    const board = el.dataset.board;
    const date = el.dataset.date;
    let state = null;
    try { state = JSON.parse(document.getElementById('lgState').textContent || 'null'); } catch (e) { state = null; }
    const esc = at.esc;
    const fmt = (n, d) => Number(n || 0).toLocaleString('pl-PL', {maximumFractionDigits: d ?? 0});
    const COLORS = ['#2E7D4F', '#1f4f7a', '#8D1D2C', '#C17A2A', '#6b4c9a', '#00838f', '#9c4a22', '#5d6d1e'];
    const color = (i) => COLORS[((i || 1) - 1) % COLORS.length];
    const hm = (s) => { const m = Math.max(0, Math.round(s / 60)); return m >= 60 ? `${Math.floor(m / 60)} h ${String(m % 60).padStart(2, '0')}` : `${m} min`; };
    const time = (iso) => (iso || '').slice(11, 16);
    const plural = (n, one, few, many) => {
        const a = Math.abs(n) % 100, b = a % 10;
        return n === 1 ? one : (b >= 2 && b <= 4 && (a < 12 || a > 14) ? few : many);
    };
    // Waga i wysokość palety [213] : « 412 kg · 1,62 m », alarm gdy ponad limit (palety, kartonu albo ciężarówki).
    const meters = (mm) => mm ? (mm / 1000).toLocaleString('pl-PL', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' m' : '? m';
    const over = (l) => !!l && (l.over_kg || l.over_height || l.over_truck);
    const loadTxt = (l) => l ? `${fmt(l.kg)} kg · ${meters(l.height_mm)}${l.known_height ? '' : '+'}` : '';
    const overTxt = (l) => [l.over_kg ? `ponad ${fmt(l.max_kg)} kg` : '', l.over_height ? `ponad ${meters(l.max_height_mm)}` : '', l.over_truck ? 'wyższa niż ciężarówka' : ''].filter(Boolean).join(', ');
    const loadSum = (c) => {
        const f = c.load_final || {pallets: []};
        const heavy = f.pallets.reduce((m, p) => Math.max(m, p.kg), 0);
        return `<span class="lg-load ${f.over ? 'is-over' : ''}">Teraz ≈ ${fmt((c.load_now || {}).kg)} kg na paletach · po zamówieniu ${f.pallets.length} pal. : najcięższa ${fmt(heavy)} kg, najwyższa ${meters(f.max_height_mm)}${f.over ? ` · ⚠ ${f.over} ponad limit` : ''}</span>`;
    };
    // Czas serwera (strefa ERP) : różnica z zegarem tabletu.
    const skew = state && state.now ? Date.parse(state.now.replace(' ', 'T')) - Date.now() : 0;
    const serverNow = () => new Date(Date.now() + skew);
    const untilDeparture = (departure) => {
        const ms = Date.parse(`${date}T${departure}:00`) - serverNow().getTime();
        if (Number.isNaN(ms)) return '';
        if (ms <= 0) return `Wyjazd ${departure} · po czasie`;
        return ms > 86400000 ? `Wyjazd ${departure}` : `Wyjazd ${departure} · za ${hm(ms / 1000)}`;
    };

    // Pełny ekran : bez menu i nagłówka ERP (tablet na rampie).
    document.querySelectorAll('[data-fullscreen]').forEach(b => b.addEventListener('click', () => {
        const on = document.body.classList.toggle('lg-kiosk');
        try {
            if (on && document.documentElement.requestFullscreen) document.documentElement.requestFullscreen().catch(() => {});
            if (!on && document.fullscreenElement) document.exitFullscreen().catch(() => {});
        } catch (e) { /* pełny ekran niedostępny */ }
    }));

    async function reload(extra) {
        const res = await at.send(`/ajax/logistics/${board}?date=${encodeURIComponent(date)}${extra || ''}`, 'GET');
        if (!res.ok) { at.notify(false, res.message || 'Błąd'); return false; }
        state = res.data;
        render();
        return true;
    }
    function every(seconds, fn) {
        window.setInterval(() => { if (document.visibilityState === 'visible' && !busy) fn(); }, seconds * 1000);
    }
    let busy = false;

    // ============================================================== Ekran paletyzacji
    let detail = null;
    let detailFilter = 'missing';
    function renderLive() {
        const s = state;
        const T = s.totals;
        const pct = (v) => T.ordered ? Math.min(100, v / T.ordered * 100) : 0;
        if (detail !== null && !s.clients.some(c => c.id === detail)) detail = null;
        const head = `<div class="lg-strip"><span class="lg-live">Na żywo${s.last_activity ? ' · ostatni ruch ' + esc(time(s.last_activity)) : ''}</span>
            <span class="lg-pill is-warn">${esc(untilDeparture(s.departure))}</span></div>`;
        if (!s.clients.length) { el.innerHTML = head + '<p class="at-empty">Brak zamówień do wysyłki w tym dniu.</p>'; return; }
        if (detail !== null) return renderLiveDetail(head);
        const kpi = `<div class="lg-kpis">
            <div class="lg-kpi"><span>Na paletach</span><strong>${fmt(T.on_pallet)} <small>/ ${fmt(T.ordered)} kart.</small></strong>
                <div class="lg-bar"><i class="is-ok" style="width:${pct(T.on_pallet)}%"></i><i class="is-ready" style="width:${pct(T.ready)}%"></i></div>
                <small>${Math.round(pct(T.on_pallet))} % · ${T.pallets} ${plural(T.pallets, 'paleta', 'palety', 'palet')} (${T.pallets_open} w budowie) · plan ${T.planned_pallets}</small></div>
            <div class="lg-kpi"><span>Gotowe do położenia</span><strong class="is-ready">${fmt(T.ready)} <small>kart.</small></strong>
                <small>na stanie ${fmt(T.stock)}${T.packed ? ' · spakowane ' + fmt(T.packed) : ''} — weź i połóż na paletę</small></div>
            <div class="lg-kpi ${T.missing ? 'is-alert' : 'is-done'}"><span>Brak — nie ma na stanie</span><strong class="${T.missing ? 'is-missing' : 'is-ok'}">${fmt(T.missing)} <small>kart.</small></strong>
                <small>${T.missing ? 'do spakowania przed ' + esc(s.departure) + ' (tablet pakowania)' : 'wszystko jest na stanie albo na paletach'}</small></div></div>`;
        const alerts = s.alerts.length ? `<div class="lg-alerts">${s.alerts.map(a => a.type === 'no_pallet'
            ? `<span class="lg-pill is-bad">⚠ ${esc(a.customer)} : jeszcze żadnej palety</span>`
            : a.type === 'pallet_over'
            ? `<span class="lg-pill is-bad" title="${esc(a.pallets.map(id => 'P' + id).join(', '))}">⚠ ${esc(a.customer)} : ${a.pallets.length === 1 ? 'P' + a.pallets[0] : a.pallets.length + ' ' + plural(a.pallets.length, 'paleta', 'palety', 'palet')} ponad limit — do ${fmt(a.kg)} kg · ${meters(a.height_mm)} (limit ${fmt(a.max_kg)} kg, ${meters(a.max_height_mm)})</span>`
            : `<span class="lg-pill is-bad">⚠ ${esc(a.customer)} : ${fmt(a.cartons)} kart. nie ma na stanie (${a.products.map(esc).join(', ')}…)</span>`).join('')}</div>` : '';
        const cards = s.clients.map(c => {
            const p = (v) => c.ordered ? Math.min(100, v / c.ordered * 100) : 0;
            const planned = Math.max(0, c.planned_pallets - c.pallets.length);
            const lines = c.lines.filter(l => l.missing > 0).slice(0, 3).map(l => `<li><span>${esc(l.name)}</span><span class="lg-pill is-bad">brak ${fmt(l.missing)}</span></li>`)
                .concat(c.lines.filter(l => l.missing === 0 && l.ready > 0).slice(0, 2).map(l => `<li><span>${esc(l.name)}</span><span class="lg-pill is-warn">${l.packed ? 'gotowe' : 'magazyn'} ${fmt(l.ready)}</span></li>`)).join('');
            return `<button type="button" class="lg-client ${c.ordered && !c.on_pallet ? 'is-alert' : ''} ${c.left === 0 ? 'is-done' : ''}" style="--c:${color(c.n)}" data-client="${c.id}">
                <span class="lg-client-head"><span class="lg-no">${c.n}</span><span><b>${esc(c.name)}</b><small>${esc([c.town, c.vehicle ? c.vehicle.label : 'bez pojazdu', 'plan ' + c.planned_pallets + ' pal.'].filter(Boolean).join(' · '))}</small></span></span>
                <span class="lg-big"><strong>${fmt(c.on_pallet)}</strong> / ${fmt(c.ordered)} kart. na paletach</span>
                <span class="lg-bar"><i class="is-ok" style="width:${p(c.on_pallet)}%"></i><i class="is-ready" style="width:${p(c.ready)}%"></i></span>
                <span class="lg-pals">${c.pallets.map(x => `<span class="lg-pal ${x.open ? '' : 'is-closed'} ${over(x.load) ? 'is-over' : ''}" ${over(x.load) ? `title="${esc(overTxt(x.load))}"` : ''}><b>P${x.id}</b> ${x.cartons} kart.<small>${x.load ? esc(loadTxt(x.load)) + ' · ' : ''}${x.open ? 'w budowie' : 'zamknięta'}</small></span>`).join('')}
                    ${planned ? `<span class="lg-pal is-ghost">+${planned} ${plural(planned, 'planowana', 'planowane', 'planowanych')}</span>` : ''}</span>
                ${c.ordered ? loadSum(c) : ''}
                ${lines ? `<ul class="lg-miss">${lines}</ul>` : (c.left === 0 ? '<span class="lg-pill is-ok">✓ wszystko na paletach</span>' : '')}
                <span class="lg-foot"><span>Zostało ${fmt(c.left)} : <b class="is-ready">${fmt(c.ready)} gotowe</b> · <b class="is-missing">${fmt(c.missing)} nie ma</b></span><span class="lg-go">Szczegóły ›</span></span>
            </button>`;
        }).join('');
        el.innerHTML = head + kpi + alerts + `<div class="lg-clients">${cards}</div>
            <p class="lg-legend"><span><i class="is-ok"></i>na palecie</span><span><i class="is-ready"></i>gotowe — na stanie albo spakowane</span><span><i></i>brak — do spakowania</span><span>Odświeżanie co 10 s (skaner palet i tablet paletyzacji)</span></p>`;
        el.querySelectorAll('[data-client]').forEach(b => b.addEventListener('click', () => { detail = Number(b.dataset.client); renderLive(); window.scrollTo(0, 0); }));
    }
    function renderLiveDetail(head) {
        const c = state.clients.find(x => x.id === detail);
        const filters = {missing: ['Brakuje', l => l.on_pallet < l.ordered], pallet: ['Na paletach', l => l.on_pallet > 0], all: ['Wszystko', () => true]};
        const rows = c.lines.filter(filters[detailFilter][1]);
        const state1 = (l) => l.on_pallet >= l.ordered ? '<span class="lg-pill is-ok">✓ na palecie</span>'
            : (l.missing > 0 ? '<span class="lg-pill is-bad">brak — pakowanie</span>' : `<span class="lg-pill is-warn">${l.packed ? 'spakowane — połóż' : 'weź z magazynu'}</span>`);
        el.innerHTML = head + `<div class="lg-detail-head" style="--c:${color(c.n)}"><button type="button" class="btn btn-light" data-back>‹ Wszyscy klienci</button>
            <span class="lg-no">${c.n}</span><div><h3>${esc(c.name)}${c.town ? ' — ' + esc(c.town) : ''}</h3>
            <p class="at-sub mb-0">${c.lines.length} ${plural(c.lines.length, 'produkt', 'produkty', 'produktów')} · ${fmt(c.ordered)} kart. · ${c.vehicle ? esc(c.vehicle.label) : 'bez pojazdu'} · plan ${c.planned_pallets} pal.</p></div></div>
            <div class="lg-switch">${Object.entries(filters).map(([k, [label, f]]) => `<button type="button" class="${k === detailFilter ? 'is-on' : ''}" data-filter="${k}">${label} (${c.lines.filter(f).length})</button>`).join('')}
                <span class="at-sub">${c.pallets.length ? 'Palety : ' + c.pallets.map(p => `<b>P${p.id}</b> ${p.cartons} kart.${p.load ? ' · ' + esc(loadTxt(p.load)) : ''}`).join(' · ') : 'Jeszcze żadnej palety'}</span></div>
            <div class="lg-loads"><b>Palety po zamówieniu (waga i wysokość)</b>${(c.load_final || {pallets: []}).pallets.map((p, i) => `<span class="lg-pal ${over(p) ? 'is-over' : ''}" ${over(p) ? `title="${esc(overTxt(p))}"` : ''}><b>${i + 1}</b> ${fmt(p.cartons)} kart.<small>${esc(loadTxt(p))}${p.layers ? ' · ' + p.layers + ' ' + plural(p.layers, 'warstwa', 'warstwy', 'warstw') : ''}${over(p) ? ' · ⚠ ' + esc(overTxt(p)) : ''}</small></span>`).join('') || '<span class="at-sub">brak danych paletowych</span>'}</div>
            <div class="card"><div class="table-responsive"><table class="table lg-table mb-0"><thead><tr><th>Produkt</th><th class="text-end">Zamówione</th><th class="text-end">Na palecie</th>
                <th class="text-end">Na stanie</th><th class="text-end">Brak</th><th>Co zrobić</th></tr></thead><tbody>
                ${rows.map(l => `<tr class="${l.missing ? 'is-missing' : ''}"><td><b>${esc(l.name)}</b><div class="at-sub">${esc(l.category || '')}${l.entered ? ' · tablet ' + fmt(l.entered) : ''}</div></td>
                    <td class="text-end">${fmt(l.ordered)}</td><td class="text-end">${l.on_pallet ? fmt(l.on_pallet) : '—'}</td><td class="text-end">${l.ready ? fmt(l.ready) : '—'}</td>
                    <td class="text-end lg-num-bad">${l.missing ? fmt(l.missing) : '—'}</td><td>${state1(l)}</td></tr>`).join('') || '<tr><td colspan="6" class="at-sub">Nic do pokazania.</td></tr>'}
            </tbody></table></div></div>`;
        el.querySelector('[data-back]').addEventListener('click', () => { detail = null; renderLive(); });
        el.querySelectorAll('[data-filter]').forEach(b => b.addEventListener('click', () => { detailFilter = b.dataset.filter; renderLive(); }));
    }

    // ============================================================== Załadunek
    let picked = null; // dotknij i dotknij : wybrane miejsce
    function renderLoading() {
        const s = state;
        if (!s.vehicle) {
            el.innerHTML = `<p class="at-empty">Brak pojazdów w użyciu — <a href="${at.root}/logistics/vehicles">dodaj pojazd</a>.</p>`
                + (s.unassigned.length ? `<p class="at-sub">${s.unassigned.length} klient(ów) bez pojazdu.</p>` : '');
            return;
        }
        const v = s.vehicle;
        const stopOf = {};
        v.customers.forEach(c => { stopOf[c.id] = c; });
        const bySlot = {};
        v.layout.forEach(x => { bySlot[x.slot] = x; });
        const cells = [];
        for (let slot = 1; slot <= v.places; slot++) {
            const x = bySlot[slot];
            const c = x ? stopOf[x.customer_id] : null;
            cells.push(x && c ? `<div class="lg-slot is-full ${picked === slot ? 'is-picked' : ''}" style="--c:${color(c.stop)}" data-slot="${slot}" data-drag="slot" data-drop="slot">
                    <b>${x.pallet_id ? 'P' + x.pallet_id : 'plan.'}</b><small>${esc(c.name)}</small></div>`
                : `<div class="lg-slot ${picked !== null ? 'is-target' : ''}" data-slot="${slot}" data-drop="slot"><small>${slot}</small></div>`);
        }
        const overflow = Object.entries(v.overflow || {});
        const tabs = s.vehicles.map(t => `<button type="button" class="lg-vtab ${t.id === v.id ? 'is-on' : ''} ${t.over ? 'is-over' : ''}" data-vehicle="${t.id}" data-drop="vehicle">
            <b>${esc(t.label)}</b><small>${esc(t.carrier || '')} · ${t.customers} ${plural(t.customers, 'klient', 'klientów', 'klientów')} · ${t.pallets}/${t.places} pal.${t.sent ? ' · ✓ wysłany' : ''}</small></button>`).join('');
        const stops = v.customers.map(c => `<li class="lg-stop" style="--c:${color(c.stop)}" data-customer="${c.id}" data-drag="stop" data-drop="stop">
            <span class="lg-grip" aria-hidden="true">⋮⋮</span><span class="lg-no">${c.stop}</span><div><b>${esc(c.name)}</b>
            <small>${esc(c.town || '')} · ${c.pallets} ${plural(c.pallets, 'paleta', 'palety', 'palet')} · ${fmt(c.cartons)} kart.${c.real.length ? ' · zeskan. ' + c.real.length : ''}</small></div>
            <button type="button" class="lg-x" data-unassign="${c.id}" title="Zdejmij z pojazdu" aria-label="Zdejmij ${esc(c.name)} z pojazdu">✕</button></li>`).join('');
        const unassigned = s.unassigned.map(c => `<li class="lg-free" data-customer="${c.id}" data-drag="client"><div><b>${esc(c.name)}</b><small>${esc(c.town || '')} · ${c.pallets} pal. · ${fmt(c.cartons)} kart.</small></div>
            <button type="button" class="btn btn-sm btn-outline-primary" data-add="${c.id}">+ do ${esc(v.label)}</button></li>`).join('');
        const sent = v.sent;
        const weightPct = v.max_kg ? Math.min(100, v.weight_kg / v.max_kg * 100) : 0;
        el.innerHTML = `<div class="lg-vtabs">${tabs}</div>
            <div class="lg-load">
                <section class="card lg-side">
                    <h4>Bez pojazdu <span class="lg-pill ${s.unassigned.length ? 'is-bad' : 'is-ok'}">${s.unassigned.length}</span></h4>
                    <ul class="lg-frees" data-drop="unassigned">${unassigned || '<li class="at-sub">Wszyscy klienci mają pojazd. Przeciągnij tu klienta, aby zdjąć go z pojazdu.</li>'}</ul>
                    ${overflow.length ? `<h4 class="mt-3">Bez miejsca <span class="lg-pill is-bad">${overflow.reduce((a, [, n]) => a + n, 0)}</span></h4>
                        <ul class="lg-frees">${overflow.map(([cid, n]) => `<li><b>${esc((stopOf[cid] || {}).name || '')}</b><small>${n} ${plural(n, 'paleta', 'palety', 'palet')} — za mało miejsc, przenieś klienta</small></li>`).join('')}</ul>` : ''}
                    <p class="at-sub lg-tip">Paleta zeskanowana ma numer (P4294) ; planowana — liczona z zamówień, numer po skanie. Dotknij paletę, potem miejsce, albo przeciągnij.</p>
                </section>
                <section class="card lg-truck"><div class="lg-cab">KABINA</div>
                    <div class="lg-floor" style="grid-template-columns:repeat(${v.columns}, 1fr)">${cells.join('')}</div><div class="lg-door">DRZWI — rozładunek</div></section>
                <section class="lg-right">
                    <div class="card"><div class="card-body"><h4>Kolejność rozładunku</h4>
                        <ol class="lg-stops" data-drop="stops">${stops || '<li class="at-sub">Brak klientów — dodaj z listy « Bez pojazdu ».</li>'}</ol>
                        <p class="at-sub lg-tip">Przeciągnij klienta : kolejność (naczepa układa się od nowa : pierwszy klient przy drzwiach), na inny pojazd (zakładka u góry) albo do « Bez pojazdu ».</p></div></div>
                    <div class="card"><div class="card-body lg-fill">
                        <div><span>Miejsca</span><b>${v.used} / ${v.places}</b></div><div class="lg-bar"><i class="${v.used > v.places ? 'is-bad' : 'is-ok'}" style="width:${Math.min(100, v.used / v.places * 100)}%"></i></div>
                        <div><span>Waga</span><b>${fmt(v.weight_kg)}${v.max_kg ? ' / ' + fmt(v.max_kg) : ''} kg</b></div>${v.max_kg ? `<div class="lg-bar"><i class="is-info" style="width:${weightPct}%"></i></div>` : ''}
                        <div><span>Wyjazd</span><b>${esc(v.departure)} · załadunek od ${esc(v.loading_from)}</b></div></div></div>
                    <div class="lg-actions"><button type="button" class="btn btn-light" data-auto ${v.auto ? 'disabled' : ''}>↺ Ułóż automatycznie</button>
                        <button type="button" class="btn btn-primary lg-send" data-send ${v.customers.length ? '' : 'disabled'}>📲 Wyślij plan do kierowcy</button></div>
                    <p class="at-sub">${sent ? `Wysłano ${esc(time(sent.at))}${sent.by ? ' (' + esc(sent.by) + ')' : ''} · wersja ${sent.version} · załadowane ${sent.loaded}/${sent.total}${sent.changed ? ' · <b class="text-danger">zmiany niewysłane</b>' : ''}`
                        : 'Plan jeszcze nie wysłany — kierowca zobaczy go na tablecie po wysłaniu.'}</p>
                </section>
            </div>`;
        bindLoading();
    }
    function layoutOf() {
        return state.vehicle.layout.map(x => ({slot: x.slot, customer_id: x.customer_id, pallet_id: x.pallet_id}));
    }
    async function save(body, vehicleId) {
        busy = true;
        const res = await at.send('/ajax/logistics/loading', 'PUT', {date, vehicle_id: vehicleId ?? state.vehicle.id, ...body});
        busy = false;
        if (!res.ok) { at.notify(false, res.message || 'Błąd'); return; }
        if (vehicleId && vehicleId !== state.vehicle.id) return reload(`&vehicle=${state.vehicle.id}`);
        state = res.data;
        renderLoading();
    }
    function moveSlot(from, to) {
        if (from === to) return;
        const layout = layoutOf();
        const a = layout.find(x => x.slot === from);
        const b = layout.find(x => x.slot === to);
        if (!a) return;
        a.slot = to;
        if (b) b.slot = from;
        save({customers: state.vehicle.customers.map(c => c.id), layout});
    }
    function stopOrder(dragged, beforeId) {
        const ids = state.vehicle.customers.map(c => c.id).filter(id => id !== dragged);
        const at_ = beforeId === null ? ids.length : ids.indexOf(beforeId);
        ids.splice(at_ < 0 ? ids.length : at_, 0, dragged);
        return ids;
    }
    function bindLoading() {
        const v = state.vehicle;
        el.querySelectorAll('[data-vehicle]').forEach(b => b.addEventListener('click', () => { picked = null; reload(`&vehicle=${b.dataset.vehicle}`); }));
        el.querySelectorAll('[data-unassign]').forEach(b => b.addEventListener('click', (e) => {
            e.stopPropagation();
            const id = Number(b.dataset.unassign);
            save({customers: v.customers.map(c => c.id).filter(x => x !== id), unassign: [id]});
        }));
        el.querySelectorAll('[data-add]').forEach(b => b.addEventListener('click', () => save({append: [Number(b.dataset.add)]})));
        const auto = el.querySelector('[data-auto]');
        if (auto) auto.addEventListener('click', () => save({customers: v.customers.map(c => c.id), layout: null}));
        el.querySelector('[data-send]').addEventListener('click', async (e) => {
            const b = e.currentTarget;
            b.disabled = true;
            const res = await at.send('/ajax/logistics/loading/send', 'POST', {date, vehicle_id: v.id});
            at.notify(res.ok, res.message || (res.ok ? 'Wysłano' : 'Błąd'));
            if (res.ok) { state = res.data; renderLoading(); } else b.disabled = false;
        });
        // Dotknij paletę, potem miejsce (bez przeciągania).
        el.querySelectorAll('[data-slot]').forEach(c => c.addEventListener('click', () => {
            if (dragMoved) return;
            const slot = Number(c.dataset.slot);
            if (picked === null) { if (c.classList.contains('is-full')) { picked = slot; renderLoading(); } return; }
            const from = picked;
            picked = null;
            if (from === slot) { renderLoading(); return; }
            moveSlot(from, slot);
        }));
    }

    // Przeciąganie (mysz i palec) : pointer events, cel = element pod palcem.
    let drag = null;
    let dragMoved = false;
    el.addEventListener('pointerdown', (e) => {
        const src = e.target.closest('[data-drag]');
        if (!src || board !== 'loading' || e.target.closest('button')) return;
        drag = {src, kind: src.dataset.drag, x: e.clientX, y: e.clientY, ghost: null, over: null};
        dragMoved = false;
    });
    window.addEventListener('pointermove', (e) => {
        if (!drag) return;
        if (!drag.ghost) {
            if (Math.hypot(e.clientX - drag.x, e.clientY - drag.y) < 8) return;
            dragMoved = true;
            picked = null;
            drag.ghost = drag.src.cloneNode(true);
            drag.ghost.classList.add('lg-ghost');
            drag.ghost.style.width = drag.src.getBoundingClientRect().width + 'px';
            document.body.appendChild(drag.ghost);
            drag.src.classList.add('is-dragging');
            document.body.classList.add('lg-dragging');
        }
        e.preventDefault();
        drag.ghost.style.left = e.clientX + 'px';
        drag.ghost.style.top = e.clientY + 'px';
        const under = document.elementFromPoint(e.clientX, e.clientY);
        const target = under ? under.closest('[data-drop]') : null;
        const ok = target && accepts(drag.kind, target.dataset.drop);
        if (drag.over && drag.over !== target) drag.over.classList.remove('is-drop');
        drag.over = ok ? target : null;
        if (drag.over) drag.over.classList.add('is-drop');
    }, {passive: false});
    window.addEventListener('pointerup', () => {
        if (!drag) return;
        const d = drag;
        drag = null;
        if (d.ghost) d.ghost.remove();
        d.src.classList.remove('is-dragging');
        document.body.classList.remove('lg-dragging');
        if (d.over) d.over.classList.remove('is-drop');
        if (d.ghost && d.over) drop(d.kind, d.src, d.over);
        window.setTimeout(() => { dragMoved = false; }, 0);
    });
    window.addEventListener('pointercancel', () => {
        if (!drag) return;
        if (drag.ghost) drag.ghost.remove();
        drag.src.classList.remove('is-dragging');
        document.body.classList.remove('lg-dragging');
        drag = null;
    });
    function accepts(kind, target) {
        if (kind === 'slot') return target === 'slot';
        if (kind === 'stop') return ['stop', 'stops', 'unassigned', 'vehicle'].includes(target);
        if (kind === 'client') return ['stop', 'stops', 'vehicle'].includes(target);
        return false;
    }
    function drop(kind, src, target) {
        const v = state.vehicle;
        const type = target.dataset.drop;
        if (kind === 'slot') return moveSlot(Number(src.dataset.slot), Number(target.dataset.slot));
        const cid = Number(src.dataset.customer);
        if (type === 'vehicle') {
            const vid = Number(target.dataset.vehicle);
            if (vid === v.id) { if (kind === 'client') save({append: [cid]}); return; }
            return save({append: [cid]}, vid);
        }
        if (type === 'unassigned') return save({customers: v.customers.map(c => c.id).filter(x => x !== cid), unassign: [cid]});
        const before = type === 'stop' ? Number(target.dataset.customer) : null;
        // Nowa kolejność rozładunku : naczepa układa się od nowa (pierwszy klient przy drzwiach).
        save({customers: stopOrder(cid, before === cid ? null : before), layout: null});
    }

    function render() {
        if (!state) return;
        if (board === 'live') renderLive();
        if (board === 'loading') renderLoading();
    }
    render();
    if (board === 'live') every(10, () => reload());
    if (board === 'loading') every(30, () => { if (!drag && picked === null) reload(`&vehicle=${state.vehicle ? state.vehicle.id : ''}`); });
})();
