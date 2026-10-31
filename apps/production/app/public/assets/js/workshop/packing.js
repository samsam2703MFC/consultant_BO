/*
 * Tablet pakowania / Tablette d'emballage (PWA, 28/10/2026). Parowanie tym samym kodem co inne tablety (Tablety ›
 * Zarządzanie) ; każdy sparowany tablet, bez PIN. Dwa tryby (przełącznik w nagłówku, zapamiętany na tablecie) :
 *  - Pakowanie : produkty do spakowania (zamówione − na paletach − na stanie, w kolejności trasy), kartony, sztuki,
 *    czas wg normy « Pakowanie », osoby, czy zdążymy przed wyjazdem ; licznik − / + / « Gotowe » / « Cofnij »
 *    (informacyjny : nie tworzy kartonów w magazynie) ; odświeżanie co 30 s.
 *  - Paletyzacja : operator (osoby obecne), transport (pojazd albo wszyscy klienci dnia), klienci u góry, produkty
 *    zamówione przez klienta : zamówione / na palecie / zostało ; dotknięcie wiersza → szczegóły (skan + 1 + 2 + 32 = …),
 *    « + » → klawiatura (1, 5, 10, reszta) → kartony na paletę ; cofnięcie ostatniego wpisu ; odświeżanie co 15 s.
 *  - Przyjęcia [230] : kontrola przyjęć dnia — dostawy z zamówień dostawców (lista z lewej), pozycja po pozycji : ilość,
 *    partia, termin, temperatura (chłodnia), OK / Niezgodność (co jest nie tak, zdjęcie z aparatu tabletu, opis, decyzja) ;
 *    zapis = przyjęcie w zamówieniu + NC ; operator jak w paletyzacji. Zadanie « Kontrola przyjęć » zalicza się samo.
 */
(function () {
    'use strict';
    const VERSION = (document.currentScript && new URL(document.currentScript.src, location.href).searchParams.get('v')) || '';
    const el = document.getElementById('tfbPacking');
    if (!el) return;
    const base = (el.dataset.apiBase || '').replace(/\/$/, '');
    const root = (el.dataset.root || '').replace(/\/$/, '');
    // Ten sam token co inne tablety : tablet sparowany raz działa też tutaj.
    const DEVICE_KEY = 'tfb.kiosk.device';
    const LANG_KEY = 'tfb.lang';
    const MODE_KEY = 'tfb.packing.mode';
    const VEHICLE_KEY = 'tfb.packing.vehicle';
    const OPERATOR_KEY = 'tfb.packing.operator';
    const COLORS = ['#2E7D4F', '#1f4f7a', '#8D1D2C', '#C17A2A', '#6b4c9a', '#00838f', '#9c4a22', '#5d6d1e'];
    const store = {
        get(k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
        set(k, v) { try { v === null ? window.localStorage.removeItem(k) : window.localStorage.setItem(k, v); } catch (e) { /* stockage indisponible */ } },
    };
    const query = new URLSearchParams(location.search);
    let lang = store.get(LANG_KEY) === 'fr' ? 'fr' : 'pl';
    let labels = {};
    let device = store.get(DEVICE_KEY);
    const MODES = ['pack', 'pallets', 'receiving'];
    let mode = MODES.includes(query.get('mode')) ? query.get('mode') : (MODES.includes(store.get(MODE_KEY)) ? store.get(MODE_KEY) : 'pack');
    let data = null;   // pakowanie
    let pal = null;    // paletyzacja
    let date = (query.get('date') || '').match(/^\d{4}-\d{2}-\d{2}$/) ? query.get('date') : null;
    let people = null;
    let busy = false;
    let skew = 0;
    // Paletyzacja : wybory na tablecie.
    let vehicle = store.get(VEHICLE_KEY) ? Number(store.get(VEHICLE_KEY)) || null : null;
    let operator = Number(store.get(OPERATOR_KEY)) || null;
    let customer = null;
    let product = null;     // wiersz otwarty z boku
    let panel = null;       // 'detail' | 'pad'
    let pad = '';
    let drop = null;        // 'vehicle' | 'operator'
    // Przyjęcia : dzień kontroli (dziś / wczoraj), dostawa otwarta, szkic pozycji (status, ilość, partia, NC…).
    let rec = null;
    let recDay = null;
    let recSel = null;
    let recOrder = null;
    let draft = {};
    let labelOcr = {};      // pozycja => {state: reading|found|nothing|unavailable|error, pct, lots, dates, img} (zdjęcie etykiety)

    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const t = (k, p) => { let s = labels[k] ?? k; for (const [a, b] of Object.entries(p || {})) s = s.split('{' + a + '}').join(String(b)); return s; };
    const fmt = (n) => Number(n || 0).toLocaleString(lang === 'fr' ? 'fr-FR' : 'pl-PL', {maximumFractionDigits: 0});
    const hm = (s) => { const m = Math.max(0, Math.round(s / 60)); return m >= 60 ? `${Math.floor(m / 60)} h ${String(m % 60).padStart(2, '0')}` : `${m} min`; };
    const hhmm = (d) => `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
    const time = (iso) => String(iso || '').slice(11, 16);
    const dayLabel = (iso) => new Date(iso + 'T12:00:00').toLocaleDateString(lang === 'fr' ? 'fr-FR' : 'pl-PL', {weekday: 'short', day: 'numeric', month: 'short'});
    const serverNow = () => new Date(Date.now() + skew);
    const color = (n) => COLORS[((n || 1) - 1) % COLORS.length];
    const initials = (name) => String(name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(x => x[0]).join('').toUpperCase();
    const current = () => (mode === 'pack' ? data : (mode === 'pallets' ? pal : rec));

    async function loadLabels() {
        const res = await fetch(`${root}/resources/lang/ui/${lang}/packing.json${VERSION ? '?v=' + encodeURIComponent(VERSION) : ''}`, {credentials: 'same-origin'});
        labels = res.ok ? await res.json() : {};
        document.documentElement.lang = lang;
    }

    function toast(message, error) {
        document.querySelectorAll('.kiosk-toast').forEach(n => n.remove());
        const n = document.createElement('div');
        n.className = 'kiosk-toast' + (error ? ' is-error' : '');
        n.setAttribute('role', error ? 'alert' : 'status');
        n.textContent = message;
        document.body.appendChild(n);
        window.setTimeout(() => n.remove(), error ? 5000 : 2500);
    }

    async function api(method, path, body) {
        const headers = {'Accept': 'application/json', 'Accept-Language': lang, 'X-Device-Token': device || ''};
        if (body !== undefined) headers['Content-Type'] = 'application/json';
        let res;
        try {
            res = await fetch(base + path, {method, headers, body: body === undefined ? undefined : JSON.stringify(body), credentials: 'same-origin'});
        } catch (e) { throw new Error(t('offline')); }
        const json = await res.json().catch(() => ({}));
        if (res.ok) return json.data;
        const err = new Error(((json.errors || []).map(x => x.message).join(' ')) || json.message || t('error'));
        err.code = json.description;
        if (err.code === 'DEVICE_UNAUTHENTICATED') { device = null; data = null; pal = null; store.set(DEVICE_KEY, null); renderPairing(); }
        throw err;
    }

    // Nagłówek w jednej linii (makieta 2) : logo | dni (segmenty) | operator (pastylka, paletyzacja) · tryb · ↻ · język.
    function header() {
        const s = current();
        const title = t(mode === 'pack' ? 'title' : (mode === 'pallets' ? 'title_pallets' : 'title_receiving'));
        if (!s) {
            return `<header class="kiosk-head"><div class="kiosk-brand"><div class="kiosk-logo">LA</div><div><h1>${esc(title)}</h1>
                <p class="kiosk-muted">${esc(t('subtitle'))}</p></div></div>
                <div class="kiosk-head-tools">${device ? `<button type="button" class="kiosk-chip" data-refresh aria-label="${esc(t('refresh'))}">↻</button>` : ''}
                <button type="button" class="kiosk-chip" data-lang>${esc(t('lang_switch'))}</button></div></header>`;
        }
        const op = mode !== 'pack' ? operatorNow() : null;
        const who = mode !== 'pack' ? `<button type="button" class="pk-avatar ${op ? '' : 'is-empty'}" data-drop="operator" aria-haspopup="listbox"
                title="${esc(op ? op.name : t('operator_none'))}" aria-label="${esc(t('operator'))} : ${esc(op ? op.name : t('operator_none'))}">${op ? esc(initials(op.name)) : '?'}</button><span class="pk-gap"></span>` : '';
        const sw = `<div class="pk-switch" role="radiogroup" aria-label="${esc(t('mode'))}">
                ${MODES.map(m => `<button type="button" role="radio" aria-checked="${m === mode}" class="${m === mode ? 'is-on' : ''}" data-mode="${m}">${esc(t('mode_' + m))}</button>`).join('')}</div>`;
        return `<header class="kiosk-head pk-head"><div class="kiosk-logo" title="${esc(title)}">LA</div><h1 class="pk-sr">${esc(title)}</h1>${mode === 'receiving' ? recDaySeg(s) : daySeg(s)}
            <div class="kiosk-head-tools">${who}${sw}<button type="button" class="kiosk-chip" data-refresh aria-label="${esc(t('refresh'))}">↻</button>
                <button type="button" class="kiosk-chip" data-lang>${esc(t('lang_switch'))}</button></div></header>`;
    }

    // Dni jako segmenty (jak przełącznik trybu) : wczoraj … kolejne dni z zamówieniami, najwyżej 4.
    function daySeg(s) {
        const today = s.now.slice(0, 10);
        const yesterday = new Date(new Date(today + 'T12:00:00').getTime() - 86400000).toISOString().slice(0, 10);
        const days = (s.dates || []).filter(d => d.ship_date >= yesterday).slice(0, 4);
        if (!days.some(d => d.ship_date === s.date)) days.unshift({ship_date: s.date});
        const label = (iso) => {
            const d = new Date(iso + 'T12:00:00');
            const wd = d.toLocaleDateString(lang === 'fr' ? 'fr-FR' : 'pl-PL', {weekday: 'short'});
            return `${wd} ${d.getDate()}${iso.slice(0, 7) === today.slice(0, 7) ? '' : '.' + String(d.getMonth() + 1).padStart(2, '0')}`;
        };
        return `<nav class="pk-dayseg" aria-label="${esc(t('day'))}">${days.map(d => `<button type="button" class="${d.ship_date === s.date ? 'is-on' : ''}" aria-pressed="${d.ship_date === s.date}"
            data-date="${d.ship_date}" title="${esc(dayLabel(d.ship_date))}">${esc(label(d.ship_date))}</button>`).join('')}</nav>`;
    }

    function renderPairing(message) {
        el.innerHTML = header() + `<section class="kiosk-card"><h2>${esc(t('pair_title'))}</h2><p class="kiosk-muted">${esc(t('pair_help'))}</p>
            <form class="kiosk-form" data-pair><label>${esc(t('pair_code'))}<input name="code" autocomplete="off" autocapitalize="characters" maxlength="12" required></label>
            <div class="kiosk-row"><button class="kiosk-btn is-primary">${esc(t('pair'))}</button></div></form>
            <p class="kiosk-error" data-error>${esc(message || '')}</p></section>`;
        el.querySelector('[data-pair]').addEventListener('submit', async (e) => {
            e.preventDefault();
            try {
                const r = await api('POST', '/kiosk/pair', {code: e.target.code.value});
                device = r.device_token;
                store.set(DEVICE_KEY, device);
                toast(t('paired', {name: r.device.name}));
                load();
            } catch (err) { el.querySelector('[data-error]').textContent = err.message; }
        });
        el.querySelector('[name=code]').focus();
    }

    // Bez tokenu w pamięci przeglądarki : serwer zna tablet z ciasteczka parowania (tablet zostaje sparowany).
    async function load() {
        try {
            if (mode === 'receiving') {
                rec = await api('GET', '/kiosk/receiving' + (recDay ? '?date=' + encodeURIComponent(recDay) : ''));
                recDay = rec.day;
                if (recSel && !rec.orders.some(o => o.id === recSel)) { recSel = null; recOrder = null; draft = {}; }
                if (!recSel) {
                    const next = rec.orders.find(o => o.due && !o.check) || null;
                    if (next) { recSel = next.id; draft = {}; }
                }
                if (recSel) recOrder = await api('GET', `/kiosk/receiving/orders/${recSel}?date=${encodeURIComponent(recDay)}`);
            } else if (mode === 'pack') {
                data = await api('GET', '/kiosk/packing' + (date ? '?date=' + encodeURIComponent(date) : ''));
                date = data.date;
                if (people === null) people = Math.max(1, data.people.present || 1);
            } else {
                const q = new URLSearchParams();
                if (date) q.set('date', date);
                if (vehicle) q.set('vehicle_id', String(vehicle));
                if (customer) q.set('customer_id', String(customer));
                setPal(await api('GET', '/kiosk/pallets' + (q.toString() ? '?' + q : '')));
            }
            skew = Date.parse(current().now.replace(' ', 'T')) - Date.now();
            render();
        } catch (err) {
            if (err.code === 'DEVICE_UNAUTHENTICATED') return;
            if (current()) toast(err.message, true); else el.innerHTML = header() + `<section class="kiosk-card"><p class="kiosk-error">${esc(err.message)}</p>
                <button type="button" class="kiosk-btn" data-refresh>${esc(t('retry'))}</button></section>`;
        }
    }

    function render() {
        if (mode === 'pack') renderPack(); else if (mode === 'pallets') renderPallets(); else renderReceiving();
    }

    // ============================================================== Pakowanie
    function renderPack() {
        const s = data;
        const T = s.totals;
        const finish = new Date(serverNow().getTime() + T.seconds / people * 1000);
        const deadline = Date.parse(`${s.date}T${s.departure}:00`);
        const margin = (deadline - finish.getTime()) / 1000;
        const gone = T.remaining > 0 && deadline < serverNow().getTime();
        const ok = T.remaining === 0 || margin >= 0;
        const kpi = `<section class="pk-kpis">
            <div class="kiosk-card pk-kpi"><span>${esc(t('to_pack'))}</span><strong>${fmt(T.remaining)}</strong><small>${esc(t('cartons'))} · ${esc(t('products_done', {n: T.products, done: T.done}))}</small></div>
            <div class="kiosk-card pk-kpi"><span>${esc(t('pieces'))}</span><strong>${fmt(T.pieces)}</strong><small>${esc(t('pieces_help'))}</small></div>
            <div class="kiosk-card pk-kpi"><span>${esc(t('time'))}</span><strong>${esc(hm(T.seconds / people))}</strong>
                <div class="pk-people"><button type="button" class="kiosk-btn" data-people="-1" aria-label="${esc(t('less_people'))}">−</button>
                <b>${esc(t('people_n', {n: people}))}</b><button type="button" class="kiosk-btn" data-people="1" aria-label="${esc(t('more_people'))}">+</button></div></div>
            <div class="kiosk-card pk-kpi ${gone ? '' : (ok ? 'is-ok' : 'is-late')}"><span>${esc(t('in_time', {time: s.departure}))}</span><strong>${esc(T.remaining === 0 ? t('all_done') : (gone ? '—' : (ok ? t('yes') : t('no'))))}</strong>
                <small>${esc(T.remaining === 0 ? t('nothing_left') : (gone ? t('departed') : t(ok ? 'finish_ok' : 'finish_late', {time: hhmm(finish), margin: hm(Math.abs(margin))})))}</small></div></section>`;
        let cat = null;
        const rows = s.items.map(i => {
            const head = i.category !== cat ? `<li class="pk-cat">${esc(cat = i.category || t('no_category'))}</li>` : '';
            const clients = i.clients.map(c => `${esc(c.name)} ${fmt(c.cartons)}`).join(' · ') || esc(t('stock_labels'));
            return head + `<li class="pk-row ${i.done ? 'is-done' : (i.packed ? 'is-go' : '')}" data-product="${i.product_id}">
                <div class="pk-name"><b>${esc(i.name)}</b><small>${clients}</small></div>
                <div class="pk-num"><strong>${fmt(i.remaining)}</strong><small>${esc(t('cartons_short'))}${i.need !== i.remaining ? ' / ' + fmt(i.need) : ''}</small></div>
                <div class="pk-num"><strong>${fmt(i.pieces)}</strong><small>${esc(t('pieces_short', {n: i.ppc}))}</small></div>
                <div class="pk-num"><strong>${i.done ? '—' : (i.norm ? '' : '≈ ') + esc(hm(i.seconds))}</strong><small>${esc(t(i.norm ? 'norm' : 'no_norm'))}</small></div>
                <div class="pk-ctr">${i.done
                    ? `<span class="pk-done">✓ ${esc(t('packed_n', {n: fmt(i.packed)}))}</span><button type="button" class="kiosk-btn" data-set="0">${esc(t('undo'))}</button>`
                    : `<button type="button" class="kiosk-btn pk-step" data-step="-1" ${i.packed ? '' : 'disabled'} aria-label="${esc(t('less'))}">−</button>
                       <span class="pk-cnt"><b>${fmt(i.packed)}</b> / ${fmt(i.need)}</span>
                       <button type="button" class="kiosk-btn pk-step" data-step="1" aria-label="${esc(t('more'))}">+</button>
                       <button type="button" class="kiosk-btn is-primary" data-set="${i.need}">${esc(t('done'))}</button>`}</div></li>`;
        }).join('');
        el.innerHTML = header() + kpi
            + (s.items.length ? `<section class="kiosk-card pk-list"><ul>${rows}</ul></section>` : `<section class="kiosk-card"><p class="pk-empty">${esc(t('empty'))}</p></section>`)
            + `<p class="kiosk-muted pk-note">${esc(t('note', {n: T.with_norm, fixed: fmt(s.average_norm.fixed), piece: String(s.average_norm.per_piece).replace('.', ',')}))}</p>`;
        el.querySelectorAll('[data-people]').forEach(b => b.addEventListener('click', () => { people = Math.max(1, Math.min(20, people + Number(b.dataset.people))); render(); }));
        el.querySelectorAll('[data-product]').forEach(row => {
            const item = s.items.find(i => i.product_id === Number(row.dataset.product));
            row.querySelectorAll('[data-step]').forEach(b => b.addEventListener('click', () => setPacked(item, Math.max(0, item.packed + Number(b.dataset.step)))));
            row.querySelectorAll('[data-set]').forEach(b => b.addEventListener('click', () => setPacked(item, Number(b.dataset.set))));
        });
    }

    async function setPacked(item, packed) {
        if (busy) return;
        busy = true;
        el.querySelectorAll(`[data-product="${item.product_id}"] button`).forEach(b => { b.disabled = true; });
        try {
            data = await api('PUT', `/kiosk/packing/${item.product_id}`, {date, packed});
            render();
        } catch (err) {
            if (err.code !== 'DEVICE_UNAUTHENTICATED') { toast(err.message, true); render(); }
        } finally { busy = false; }
    }

    // ============================================================== Paletyzacja
    function setPal(s) {
        pal = s;
        date = s.date;
        vehicle = s.vehicle_id;
        customer = s.customer_id;
        if (product && !clientNow()?.lines.some(l => l.product_id === product)) { product = null; panel = null; }
    }
    const clientNow = () => (pal ? pal.clients.find(c => c.id === customer) : null);
    const peopleNow = () => ((mode === 'receiving' ? rec : pal) || {}).people || [];
    const operatorNow = () => peopleNow().find(p => p.id === operator) || null;

    function renderPallets() {
        const s = pal;
        const c = clientNow();
        const truck = s.vehicles.find(v => v.id === s.vehicle_id);
        // Transport = pierwsza kafelka paska klientów (makieta 2).
        const tile = `<button type="button" class="pp-truck" data-drop="vehicle" aria-haspopup="listbox"><small>⛟ ${esc(t('transport'))} ▾</small>
                <b>${esc(truck ? truck.label : t('all_short'))}</b><small>${esc(t('departure_at', {time: s.departure}))}</small></button>`;
        const clients = `<div class="pp-top">${tile}<nav class="pp-clients" aria-label="${esc(t('clients'))}">${s.clients.map(x => `<button type="button" class="pp-client ${x.id === customer ? 'is-on' : ''}" style="--c:${color(x.n)}" data-customer="${x.id}">
                <span class="pp-cname"><span class="pp-no">${x.n}</span>${esc(x.name)}</span>
                <span class="pp-bar-p"><i style="width:${x.ordered ? Math.round(x.on_pallet / x.ordered * 100) : 0}%"></i></span>
                <small>${esc(t('client_progress', {on: fmt(x.on_pallet), ordered: fmt(x.ordered), left: fmt(x.left)}))}</small></button>`).join('')}</nav></div>`;
        let list = `<section class="kiosk-card"><p class="pk-empty">${esc(t('nothing_ordered'))}</p></section>`;
        if (c) {
            const open = c.lines.filter(l => l.left > 0);
            list = `<section class="kiosk-card pp-list"><div class="pp-head"><span>${esc(t('col_product'))}</span><span>${esc(t('col_ordered'))}</span><span>${esc(t('col_on'))}</span><span>${esc(t('col_left'))}</span><span></span></div>
                ${c.lines.map(l => `<div class="pp-row ${l.left === 0 ? 'is-done' : ''} ${l.product_id === product ? 'is-sel' : ''}" data-line="${l.product_id}">
                    <div class="pp-pn"><b>${esc(l.name)}</b><small>${esc(l.category || '')}${l.ppc ? ' · ' + esc(t('pcs_per', {n: l.ppc})) : ''}${l.carton_mm ? ' · ' + esc(t('carton_cm', {h: fmt(l.carton_mm / 10)})) : ''}</small></div>
                    <div class="pp-n">${fmt(l.ordered)}</div><div class="pp-n">${l.on_pallet ? fmt(l.on_pallet) : '—'}</div>
                    <div class="pp-n pp-rem ${l.left === 0 ? 'is-zero' : ''}">${l.left === 0 ? (l.over ? '+' + fmt(l.over) : '✓') : fmt(l.left)}</div>
                    <button type="button" class="pp-plus ${l.left === 0 ? 'is-ok' : ''}" data-plus="${l.product_id}" aria-label="${esc(t('add'))}">${l.left === 0 ? '✓' : '+'}</button></div>`).join('')}
                <div class="pp-sum">${open.length ? esc(t('left_sum', {n: fmt(c.left), p: open.length})) : esc(t('all_on'))}</div>${loads(c)}</section>`;
        }
        el.innerHTML = header() + clients + `<div class="pp-main ${panel ? 'has-side' : ''}">${list}${panel && c ? side(c) : ''}</div>` + dropdown();
        bindPallets();
    }

    // Waga i wysokość palet klienta [213] : już na paletach (skan + tablet) i po całym zamówieniu ; czerwone ponad limit.
    function loads(c) {
        if (!c.load_now && !c.load_final && !(c.mixed || []).length) return '';
        const m = (mm) => mm ? (mm / 1000).toLocaleString(lang === 'fr' ? 'fr-FR' : 'pl-PL', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' m' : '? m';
        const bad = (p) => p.over_kg || p.over_height || p.over_truck;
        // Najwyżej 6 palet (najpierw ponad limit), reszta w podsumowaniu : liczba, najcięższa, najwyższa, ponad limit.
        const row = (label, L) => {
            if (!L || !L.pallets.length) return '';
            const all = L.pallets.map((p, i) => ({...p, n: i + 1}));
            const shown = all.length <= 6 ? all : [...all.filter(bad), ...all.filter(p => !bad(p))].slice(0, 6).sort((a, b) => a.n - b.n);
            const over = all.filter(bad).length;
            const sum = all.length > 6 ? `<span class="pp-lp is-sum">${esc(t('load_sum', {n: all.length, kg: fmt(Math.max(...all.map(p => p.kg))), m: m(L.max_height_mm)}))}${over ? ' · ⚠ ' + over + ' ' + esc(t('load_over')) : ''}</span>` : '';
            return `<div class="pp-load"><span class="pp-load-l">${esc(label)}</span>${sum}${shown.map(p =>
                `<span class="pp-lp ${bad(p) ? 'is-over' : ''}" title="${esc(t('load_limits', {kg: fmt(p.max_kg), m: m(p.max_height_mm)}))}"><b>${esc(t('pallet_short', {n: p.n}))}</b> ${fmt(p.kg)} kg · ${m(p.height_mm)}${p.known_height ? '' : '+'}${bad(p) ? ' ⚠' : ''}</span>`).join('')}</div>`;
        };
        // Palety mieszane zatwierdzone [215] : kolejność układania warstw od dołu.
        const mixed = (c.mixed || []).map((s, i) => `<div class="pp-mixed"><b>${esc(t('mixed_title', {n: (c.mixed.length > 1 ? i + 1 : '')}).replace('  ', ' '))}</b>
            <ol>${s.items.map((it, k) => `<li>${esc(t('mixed_layer', {pos: k + 1, name: it.product, layers: it.layers, per: it.per_layer, n: fmt(it.cartons)}))}</li>`).join('')}</ol>
            ${s.height_mm ? `<small>${esc(t('mixed_total', {m: m(s.height_mm), kg: fmt(s.kg)}))}</small>` : ''}</div>`).join('');
        const html = row(t('load_now'), c.load_now) + row(t('load_final'), c.load_final);
        return (html || `<div class="pp-load"><span class="pp-load-l">${esc(t('load_none'))}</span></div>`) + mixed;
    }

    function side(c) {
        const l = c.lines.find(x => x.product_id === product);
        if (!l) return '';
        const head = `<div class="pp-side-head"><div><h3>${esc(l.name)}</h3><p class="kiosk-muted">${esc(c.name)} · ${esc(t('ordered_n', {n: fmt(l.ordered)}))}</p></div>
            <button type="button" class="pp-x" data-close aria-label="${esc(t('close'))}">✕</button></div>`;
        if (panel === 'pad') {
            const n = Number(pad || 0);
            const keys = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '⌫', '0', 'C'].map(k => `<button type="button" class="pp-k" data-key="${k}">${k}</button>`).join('');
            return `<aside class="kiosk-card pp-side" aria-label="${esc(t('putting'))}">${head}
                <div class="pp-big"><small>${esc(t('putting'))}</small><strong>${fmt(n)}</strong><small>${esc(t('cartons_short'))}</small></div>
                ${l.left > 0 && n > l.left ? `<p class="pp-warn">${esc(t('more_than_left', {n: fmt(l.left)}))}</p>` : ''}
                <div class="pp-quick">${[1, 5, 10].map(q => `<button type="button" class="kiosk-btn" data-quick="${q}">${q}</button>`).join('')}
                    ${l.left > 0 ? `<button type="button" class="kiosk-btn is-primary" data-quick="${l.left}">${esc(t('rest', {n: fmt(l.left)}))}</button>` : ''}</div>
                <div class="pp-pad">${keys}</div>
                <button type="button" class="kiosk-btn is-primary pp-add" data-add ${n > 0 ? '' : 'disabled'}>${esc(n > 0 ? t('add_n', {n: fmt(n)}) : t('type_number'))}</button></aside>`;
        }
        const entries = (pal.entries || {})[l.product_id] || [];
        const chips = [];
        if (l.scanned) chips.push(`<span class="pp-chip is-scan" title="${esc(t('scan_help'))}">${esc(t('scan'))} ${fmt(l.scanned)}</span>`);
        entries.forEach(e => chips.push(`<span class="pp-chip">${fmt(e.cartons)}</span>`));
        const eq = chips.length ? `<div class="pp-eq">${chips.join('<span>+</span>')}<span>=</span><span class="pp-tot">${fmt(l.on_pallet)}</span></div>` : `<p class="kiosk-muted">${esc(t('no_entries'))}</p>`;
        const last = [...entries].reverse().find(e => e.source === 'tablet');
        return `<aside class="kiosk-card pp-side">${head}${eq}
            <div class="pp-big pp-two"><div><small>${esc(t('col_on'))}</small><strong>${fmt(l.on_pallet)}</strong></div><div><small>${esc(t('col_left'))}</small><strong class="${l.left ? 'is-left' : 'is-ok'}">${l.left ? fmt(l.left) : '✓'}</strong></div></div>
            <ul class="pp-entries">${l.scanned ? `<li><span><b>${fmt(l.scanned)}</b> ${esc(t('cartons_short'))}</span><span class="kiosk-muted">${esc(t('scan_help'))}</span></li>` : ''}
                ${entries.map(e => `<li><span><b>+${fmt(e.cartons)}</b> ${esc(t('cartons_short'))}</span><span class="kiosk-muted">${esc(time(e.at))}${e.who ? ' · ' + esc(e.who) : ''}</span></li>`).join('')}</ul>
            <div class="pp-actions"><button type="button" class="kiosk-btn" data-undo="${last ? last.id : ''}" ${last ? '' : 'disabled'}>↶ ${esc(t('undo_last'))}</button>
                <button type="button" class="kiosk-btn is-primary" data-pad>+ ${esc(t('add'))}</button></div></aside>`;
    }

    function dropdown() {
        if (!drop) return '';
        let items;
        if (drop === 'vehicle') {
            items = pal.vehicles.map(v => `<button type="button" class="pp-opt ${v.id === pal.vehicle_id ? 'is-on' : ''}" data-vehicle="${v.id}"><span class="pp-ico">⛟</span>
                    <span><b>${esc(v.label)}</b><small>${esc(`${v.carrier || ''} · ${t('departure_at', {time: v.departure})} · ${t('n_clients', {n: v.customers})}`)}</small></span>${v.id === pal.vehicle_id ? '<span class="pp-check">✓</span>' : ''}</button>`).join('')
                + `${pal.vehicles.length ? '<div class="pp-sep"></div>' : ''}<button type="button" class="pp-opt ${pal.vehicle_id === null ? 'is-on' : ''}" data-vehicle=""><span class="pp-ico">☰</span>
                    <span><b>${esc(t('all_clients'))}</b><small>${esc(t('all_clients_help'))}</small></span>${pal.vehicle_id === null ? '<span class="pp-check">✓</span>' : ''}</button>`;
        } else {
            items = peopleNow().length ? peopleNow().map(p => `<button type="button" class="pp-opt ${p.id === operator ? 'is-on' : ''}" data-operator="${p.id}"><span class="pp-av">${esc(initials(p.name))}</span>
                    <span><b>${esc(p.name)}</b><small>${esc([p.group, t('since', {time: time(p.since)})].filter(Boolean).join(' · '))}</small></span>${p.id === operator ? '<span class="pp-check">✓</span>' : ''}</button>`).join('')
                : `<p class="kiosk-muted pp-none">${esc(t('no_people'))}</p>`;
        }
        return `<div class="pp-dim" data-dim></div><div class="kiosk-card pp-drop ${drop === 'vehicle' ? 'is-left' : ''}" role="listbox">${items}</div>`;
    }

    function bindPallets() {
        el.querySelectorAll('[data-drop]').forEach(b => b.addEventListener('click', () => { drop = drop === b.dataset.drop ? null : b.dataset.drop; renderPallets(); }));
        const dim = el.querySelector('[data-dim]');
        if (dim) dim.addEventListener('click', () => { drop = null; renderPallets(); });
        el.querySelectorAll('[data-vehicle]').forEach(b => b.addEventListener('click', () => {
            vehicle = b.dataset.vehicle ? Number(b.dataset.vehicle) : null;
            store.set(VEHICLE_KEY, vehicle ? String(vehicle) : null);
            drop = null; customer = null; product = null; panel = null;
            load();
        }));
        el.querySelectorAll('[data-operator]').forEach(b => b.addEventListener('click', () => {
            operator = Number(b.dataset.operator);
            store.set(OPERATOR_KEY, String(operator));
            drop = null;
            renderPallets();
        }));
        el.querySelectorAll('[data-customer]').forEach(b => b.addEventListener('click', () => {
            customer = Number(b.dataset.customer); product = null; panel = null;
            load();
        }));
        el.querySelectorAll('[data-line]').forEach(r => r.addEventListener('click', (e) => {
            if (e.target.closest('[data-plus]')) return;
            product = Number(r.dataset.line); panel = 'detail';
            renderPallets();
        }));
        el.querySelectorAll('[data-plus]').forEach(b => b.addEventListener('click', () => openPad(Number(b.dataset.plus))));
        const close = el.querySelector('[data-close]');
        if (close) close.addEventListener('click', () => { product = null; panel = null; renderPallets(); });
        const padBtn = el.querySelector('[data-pad]');
        if (padBtn) padBtn.addEventListener('click', () => openPad(product));
        el.querySelectorAll('[data-key]').forEach(b => b.addEventListener('click', () => {
            const k = b.dataset.key;
            if (k === 'C') pad = '';
            else if (k === '⌫') pad = pad.slice(0, -1);
            else if (pad.length < 3) pad = (pad + k).replace(/^0+/, '');
            renderPallets();
        }));
        el.querySelectorAll('[data-quick]').forEach(b => b.addEventListener('click', () => { pad = String(Math.min(999, Number(b.dataset.quick))); renderPallets(); }));
        const add = el.querySelector('[data-add]');
        if (add) add.addEventListener('click', addEntry);
        const undo = el.querySelector('[data-undo]');
        if (undo) undo.addEventListener('click', () => undoEntry(Number(undo.dataset.undo)));
    }

    function openPad(pid) {
        // Operator najpierw (gdy są osoby na zmianie) : kto położył kartony.
        if (pal.people.length && !operatorNow()) { toast(t('choose_operator_first'), true); drop = 'operator'; renderPallets(); return; }
        product = pid; panel = 'pad'; pad = '';
        renderPallets();
    }

    async function addEntry() {
        const n = Number(pad || 0);
        const l = clientNow()?.lines.find(x => x.product_id === product);
        if (busy || !n || !l) return;
        busy = true;
        try {
            setPal(await api('POST', '/kiosk/pallets/entries', {date, customer_id: customer, product_id: product, cartons: n,
                employee_id: operatorNow() ? operator : null, vehicle_id: vehicle}));
            toast(t('added', {n: fmt(n), product: l.name}));
            panel = 'detail'; pad = '';
            render();
        } catch (err) {
            if (err.code !== 'DEVICE_UNAUTHENTICATED') toast(err.message, true);
        } finally { busy = false; }
    }

    async function undoEntry(id) {
        const l = clientNow()?.lines.find(x => x.product_id === product);
        const e = ((pal.entries || {})[product] || []).find(x => x.id === id);
        if (busy || !id || !e || !window.confirm(t('confirm_undo', {n: fmt(e.cartons), product: l ? l.name : ''}))) return;
        busy = true;
        try {
            setPal(await api('DELETE', `/kiosk/pallets/entries/${id}${vehicle ? '?vehicle_id=' + vehicle : ''}`));
            toast(t('undone', {n: fmt(e.cartons)}));
            render();
        } catch (err) {
            if (err.code !== 'DEVICE_UNAUTHENTICATED') toast(err.message, true);
        } finally { busy = false; }
    }

    // ============================================================== Przyjęcia [230]
    const num = (v) => { const x = Number(String(v ?? '').replace(/\s/g, '').replace(',', '.')); return String(v ?? '').trim() === '' || !Number.isFinite(x) ? null : x; };
    const qtyFmt = (n) => Number(n || 0).toLocaleString(lang === 'fr' ? 'fr-FR' : 'pl-PL', {maximumFractionDigits: 3});
    const dm = (iso) => iso ? `${iso.slice(8, 10)}.${iso.slice(5, 7)}` : '';
    const kindLabel = (k) => labels['rc_kind_' + k] || (rec && rec.kinds[k]) || k;
    const decLabel = (k) => labels['rc_dec_' + k] || (rec && rec.decisions[k]) || k;
    // Pozycje do sprawdzenia : reszta do przyjęcia albo przyjęte dziś (np. wcześniej na tablecie grupy).
    const toCheck = (l) => l.remaining > 0 || !!l.today;
    const lineDraft = (l) => {
        if (!draft[l.id]) {
            draft[l.id] = {status: null, qty: recOrder.receivable && l.remaining > 0 ? String(l.remaining).replace('.', lang === 'fr' ? ',' : ',') : '',
                lot: l.today?.lot || '', bb: l.today?.best_before || '', temp: l.today?.temperature != null ? String(l.today.temperature).replace('.', ',') : '',
                kinds: [], desc: '', decision: null, photos: []};
        }
        return draft[l.id];
    };

    function recDaySeg(s) {
        const today = s.now.slice(0, 10);
        const days = [1, 0].map(n => new Date(new Date(today + 'T12:00:00').getTime() - n * 86400000).toISOString().slice(0, 10));
        return `<nav class="pk-dayseg" aria-label="${esc(t('day'))}">${days.map(d => `<button type="button" class="${d === s.day ? 'is-on' : ''}" aria-pressed="${d === s.day}"
            data-rday="${d}">${esc(d === today ? t('rc_today') : dayLabel(d))}</button>`).join('')}</nav>`;
    }

    function renderReceiving() {
        const s = rec;
        const st = s.stats;
        const orders = s.orders.length ? s.orders.map(o => {
            const state = o.check ? (o.check.arrived ? t('rc_checked', {ok: o.check.ok, nc: o.check.nc}) : t('rc_missing')) : (o.due ? t('rc_todo') : t('rc_overdue', {date: dm(o.delivery_date)}));
            const cls = o.check ? (o.check.arrived ? (o.check.nc ? 'is-nc' : 'is-ok') : 'is-miss') : (o.due ? '' : 'is-late');
            return `<button type="button" class="rc-order ${cls} ${o.id === recSel ? 'is-on' : ''}" data-rorder="${o.id}">
                <b>${esc(o.supplier)}</b><small>${esc(o.code)} · ${esc(t('rc_lines', {n: o.lines}))}</small><span class="rc-state">${esc(state)}</span></button>`;
        }).join('') : `<p class="kiosk-muted rc-none">${esc(t('rc_none'))}</p>`;
        let cold = 0, coldDone = 0;
        if (recOrder) recOrder.lines.filter(toCheck).filter(l => l.cold).forEach(l => { cold++; if (num(lineDraft(l).temp) !== null) coldDone++; });
        const stats = `<section class="kiosk-card rc-stats"><h3>${esc(t('rc_today'))}</h3>
            <div class="rc-kv"><span>${esc(t('rc_stats_due'))}</span><b>${st.checked} / ${st.due}</b></div>
            <div class="rc-kv"><span>${esc(t('rc_stats_ok'))}</span><b>${st.ok} / ${st.lines}</b></div>
            <div class="rc-kv"><span>${esc(t('rc_stats_nc'))}</span><b class="${st.nc ? 'is-bad' : ''}">${st.nc}</b></div>
            ${cold ? `<div class="rc-kv"><span>${esc(t('rc_stats_temp'))}</span><b>${esc(t('rc_stats_temp_v', {n: coldDone, m: cold}))}</b></div>` : ''}
            <p class="kiosk-muted">${esc(t('rc_note'))}</p></section>`;
        el.innerHTML = header() + `<div class="rc-main"><aside class="rc-left"><nav class="rc-orders" aria-label="${esc(t('rc_orders'))}">${orders}</nav>${stats}</aside>
            <section class="kiosk-card rc-card">${recOrder ? orderPanel() : `<p class="pk-empty">${esc(s.orders.length ? (st.due && st.checked >= st.due ? t('rc_done_all') : t('rc_choose')) : t('rc_none'))}</p>`}</section></div>`
            + dropdown() + `<input type="file" accept="image/*" capture="environment" class="pk-sr" data-photo-input tabindex="-1">`
            + `<input type="file" accept="image/*" capture="environment" class="pk-sr" data-label-input tabindex="-1">`;
        bindReceiving();
    }

    function orderPanel() {
        const o = recOrder;
        const lines = o.lines.filter(toCheck);
        const earlier = o.lines.filter(l => !toCheck(l));
        const head = `<div class="rc-head"><h2>${esc(o.supplier)} · ${esc(o.code)}</h2>
            ${o.delivery_date ? `<span class="rc-badge">${esc(t('rc_due', {date: dm(o.delivery_date)}))}</span>` : ''}
            ${o.check ? `<span class="rc-badge ${o.check.arrived ? 'is-ok' : 'is-bad'}">${esc(o.check.arrived ? t('rc_checked', {ok: o.check.ok, nc: o.check.nc}) : t('rc_missing'))} · ${esc(o.check.at)}</span>` : ''}</div>`;
        if (o.check && !recheck) {
            const rows = o.lines.filter(l => l.today).map(l => `<div class="rc-done-row"><b>${esc(l.name)}</b><span>${esc(t('rc_already', {qty: qtyFmt(l.today.qty) + ' ' + l.unit}))}${l.today.lot ? ' · ' + esc(l.today.lot) : ''}${l.today.best_before ? ' · ' + esc(dm(l.today.best_before)) : ''}${l.today.temperature != null ? ' · ' + esc(String(l.today.temperature).replace('.', ',')) + ' °C' : ''}${l.today.nc ? ' · ⚠ NC' : ''}</span></div>`).join('');
            const more = o.receivable && o.lines.some(l => l.remaining > 0);
            return head + (rows || `<p class="kiosk-muted">${esc(t('rc_nothing_today'))}</p>`)
                + `<div class="rc-foot"><span class="kiosk-muted">${esc(o.check.arrived ? '' : t('rc_missing_help'))}</span>${more ? `<button type="button" class="kiosk-btn" data-recheck>${esc(t('rc_recheck'))}</button>` : ''}
                    ${nextOrder() ? `<button type="button" class="kiosk-btn is-primary" data-rnext>${esc(t('rc_next'))} →</button>` : ''}</div>`;
        }
        const body = lines.map(l => lineRow(l, o)).join('');
        const done = lines.filter(l => lineDraft(l).status).length;
        const ok = lines.filter(l => lineDraft(l).status === 'ok').length;
        const nc = lines.filter(l => lineDraft(l).status === 'nc').length;
        const left = lines.length - done;
        const noneYet = o.receivable && !o.lines.some(l => l.today) && !o.check;
        return head + body + (earlier.length ? `<p class="kiosk-muted rc-earlier">${esc(t('rc_earlier', {n: earlier.length}))}</p>` : '')
            + `<div class="rc-foot">${noneYet ? `<button type="button" class="kiosk-btn" data-rmissing>${esc(t('rc_not_arrived'))}</button>` : ''}
                <span class="kiosk-muted">${esc(left ? t('rc_left', {n: left}) : t('rc_hint'))}</span>
                <button type="button" class="kiosk-btn is-primary rc-save" data-rsave ${left || !lines.length ? 'disabled' : ''}>${esc(t('rc_save', {ok, nc}))}</button></div>`;
    }

    function lineRow(l, o) {
        const d = lineDraft(l);
        const edit = o.receivable && l.remaining > 0;
        const sub = [t('rc_ordered', {qty: qtyFmt(l.ordered) + ' ' + l.unit})];
        if (l.received > 0) sub.push(t('rc_received_before', {qty: qtyFmt(l.received - (l.today ? l.today.qty : 0)) + ' ' + l.unit}));
        if (l.package) sub.push(t('rc_package', {qty: qtyFmt(l.package) + ' ' + l.unit}));
        if (l.cold) sub.push('❄ ' + t('rc_cold'));
        const inputs = edit
            ? `<label class="rc-in is-num"><input inputmode="decimal" data-f="qty" value="${esc(d.qty)}" aria-label="${esc(t('rc_qty'))}"><span>${esc(l.unit)}</span></label>
               <input class="rc-in" data-f="lot" value="${esc(d.lot)}" placeholder="${esc(t('rc_lot'))}" autocapitalize="characters" maxlength="64">
               <input class="rc-in" type="date" data-f="bb" value="${esc(d.bb)}" aria-label="${esc(t('rc_bb'))}" title="${esc(t('rc_bb'))}">`
            : `<span class="rc-ro">${esc(t('rc_already', {qty: qtyFmt(l.today ? l.today.qty : 0) + ' ' + l.unit}))}</span><span class="rc-ro">${esc(l.today?.lot || '—')}</span><span class="rc-ro">${esc(l.today?.best_before ? dm(l.today.best_before) : '—')}</span>`;
        const temp = l.cold && edit ? `<label class="rc-in is-num"><input inputmode="decimal" data-f="temp" value="${esc(d.temp)}" placeholder="${esc(t('rc_temp'))}"><span>°C</span></label>` : '<span></span>';
        const label = edit && window.tfbLabelOcr ? `<button type="button" class="rc-label" data-label title="${esc(t('rc_label_title'))}">📷 ${esc(t('rc_label'))}</button>` : '';
        const row = `<div class="rc-line ${d.status === 'ok' ? 'is-ok' : (d.status === 'nc' ? 'is-nc' : '')}" data-rline="${l.id}">
            <div class="rc-name"><b>${esc(l.name)}</b><small>${esc(sub.join(' · '))}</small>${label}</div>${inputs}${temp}
            <div class="rc-two"><button type="button" class="kiosk-btn ${d.status === 'ok' ? 'is-ok' : ''}" data-st="ok">✓ ${esc(t('rc_ok'))}</button>
                <button type="button" class="kiosk-btn ${d.status === 'nc' ? 'is-nc' : ''}" data-st="nc">⚠ ${esc(t('rc_nc'))}</button></div>${labelPanel(l)}</div>`;
        if (d.status !== 'nc') return row;
        const accepted = num(d.qty);
        const decisions = Object.keys(rec.decisions).map(k => `<button type="button" class="${d.decision === k ? 'is-on' : ''}" data-dec="${k}">${esc(decLabel(k))}${(k === 'accepted' || k === 'conditional') && edit && accepted !== null ? ' (' + esc(qtyFmt(accepted) + ' ' + l.unit) + ')' : ''}</button>`).join('');
        return row + `<div class="rc-nc" data-rline="${l.id}"><b class="rc-nc-t">${esc(t('rc_nc_title', {name: l.name}))}</b>
            <div><small>${esc(t('rc_what'))}</small><div class="rc-chips">${Object.keys(rec.kinds).map(k => `<button type="button" class="${d.kinds.includes(k) ? 'is-on' : ''}" data-kind="${k}">${esc(kindLabel(k))}</button>`).join('')}</div></div>
            <div class="rc-photos">${d.photos.map((p, i) => `<span class="rc-ph"><img src="${p}" alt=""><button type="button" data-unphoto="${i}" aria-label="${esc(t('close'))}">✕</button></span>`).join('')}
                ${d.photos.length < 4 ? `<button type="button" class="rc-cam" data-photo>📷<small>${esc(t('rc_photo'))}</small></button>` : ''}
                <label class="rc-desc"><small>${esc(t('rc_desc'))}</small><input data-f="desc" value="${esc(d.desc)}" maxlength="1000" placeholder="${esc(t('rc_desc_ph'))}"></label></div>
            <div><small>${esc(t('rc_decision'))}</small><div class="rc-chips is-dec">${decisions}</div></div></div>`;
    }

    // Panel odczytu etykiety pod pozycją : miniatura, postęp, kandydaci (LOT, data) do dotknięcia.
    function labelPanel(l) {
        const st = labelOcr[l.id];
        if (!st) return '';
        const d = lineDraft(l);
        const msg = st.state === 'reading' ? (st.pct ? t('rc_ocr_progress', {pct: st.pct}) : t('rc_ocr_reading'))
            : st.state === 'found' ? t('rc_ocr_found') : t(st.state === 'nothing' ? 'rc_ocr_nothing' : 'rc_ocr_unavailable');
        const chips = st.lots.map(v => `<button type="button" class="${d.lot === v ? 'is-on' : ''}" data-ocr-lot="${esc(v)}">${esc(t('rc_ocr_lot'))} ${esc(v)}</button>`).join('')
            + st.dates.map(v => `<button type="button" class="${d.bb === v ? 'is-on' : ''}" data-ocr-date="${esc(v)}">${esc(t('rc_ocr_date'))} ${esc(v.split('-').reverse().join('.'))}</button>`).join('');
        return `<div class="rc-ocr ${st.state === 'found' ? 'is-found' : ''}">${st.img ? `<img src="${st.img}" alt="">` : '<span class="rc-ocr-wait">📷</span>'}
            <div class="rc-ocr-body"><span data-ocr-msg>${esc(msg)}</span>${chips ? `<div class="rc-chips">${chips}</div>` : ''}</div>
            ${st.state !== 'reading' ? `<button type="button" class="rc-ocr-x" data-ocr-close aria-label="${esc(t('close'))}">✕</button>` : ''}</div>`;
    }

    async function readLabel(l, file) {
        const st = labelOcr[l.id] = {state: 'reading', pct: 0, lots: [], dates: [], img: null};
        renderReceiving();
        try {
            const {canvas, dataUrl} = await window.tfbLabelOcr.readPhoto(file);
            st.img = dataUrl;
            renderReceiving();
            let text = '';
            try {
                text = await window.tfbLabelOcr.ocr(canvas, (pct) => {
                    st.pct = pct;
                    const n = el.querySelector(`.rc-line[data-rline="${l.id}"] [data-ocr-msg]`);
                    if (n) n.textContent = t('rc_ocr_progress', {pct});
                });
            } catch (e) { st.state = 'unavailable'; renderReceiving(); return; }
            const found = window.tfbLabelOcr.lotCandidates(text);
            st.lots = found.lots; st.dates = found.dates;
            st.state = found.lots.length || found.dates.length ? 'found' : 'nothing';
            const d = lineDraft(l);
            if (found.lots.length && !String(d.lot || '').trim()) d.lot = found.lots[0];
            if (found.dates.length && !d.bb) d.bb = found.dates[0];
        } catch (e) { st.state = 'error'; }
        if (labelOcr[l.id] === st) renderReceiving();
    }

    let recheck = false;
    const nextOrder = () => (rec ? rec.orders.find(o => o.id !== recSel && o.due && !o.check) : null);

    async function openOrder(id) {
        recSel = id; draft = {}; recheck = false; labelOcr = {};
        try {
            recOrder = await api('GET', `/kiosk/receiving/orders/${id}?date=${encodeURIComponent(recDay)}`);
            renderReceiving();
        } catch (err) { if (err.code !== 'DEVICE_UNAUTHENTICATED') toast(err.message, true); }
    }

    function bindReceiving() {
        el.querySelectorAll('[data-drop]').forEach(b => b.addEventListener('click', () => { drop = drop === b.dataset.drop ? null : b.dataset.drop; renderReceiving(); }));
        const dim = el.querySelector('[data-dim]');
        if (dim) dim.addEventListener('click', () => { drop = null; renderReceiving(); });
        el.querySelectorAll('[data-operator]').forEach(b => b.addEventListener('click', () => {
            operator = Number(b.dataset.operator); store.set(OPERATOR_KEY, String(operator)); drop = null; renderReceiving();
        }));
        el.querySelectorAll('[data-rorder]').forEach(b => b.addEventListener('click', () => openOrder(Number(b.dataset.rorder))));
        el.querySelectorAll('[data-rday]').forEach(b => b.addEventListener('click', () => { recDay = b.dataset.rday; recSel = null; recOrder = null; draft = {}; load(); }));
        const next = el.querySelector('[data-rnext]');
        if (next) next.addEventListener('click', () => { const n = nextOrder(); if (n) openOrder(n.id); });
        const re = el.querySelector('[data-recheck]');
        if (re) re.addEventListener('click', () => { recheck = true; draft = {}; renderReceiving(); });
        const line = (node) => recOrder.lines.find(l => l.id === Number(node.closest('[data-rline]').dataset.rline));
        el.querySelectorAll('[data-f]').forEach(inp => inp.addEventListener('input', () => {
            const d = lineDraft(line(inp));
            d[inp.dataset.f] = inp.value;
            if (inp.dataset.f === 'temp') updateTempStat();
        }));
        el.querySelectorAll('[data-st]').forEach(b => b.addEventListener('click', () => {
            const d = lineDraft(line(b));
            d.status = d.status === b.dataset.st ? null : b.dataset.st;
            if (d.status === 'nc' && !d.decision) d.decision = 'accepted';
            renderReceiving();
        }));
        el.querySelectorAll('[data-kind]').forEach(b => b.addEventListener('click', () => {
            const d = lineDraft(line(b)); const k = b.dataset.kind;
            d.kinds = d.kinds.includes(k) ? d.kinds.filter(x => x !== k) : [...d.kinds, k];
            renderReceiving();
        }));
        el.querySelectorAll('[data-dec]').forEach(b => b.addEventListener('click', () => { lineDraft(line(b)).decision = b.dataset.dec; renderReceiving(); }));
        el.querySelectorAll('[data-unphoto]').forEach(b => b.addEventListener('click', () => { lineDraft(line(b)).photos.splice(Number(b.dataset.unphoto), 1); renderReceiving(); }));
        const file = el.querySelector('[data-photo-input]');
        el.querySelectorAll('[data-photo]').forEach(b => b.addEventListener('click', () => { file.dataset.line = b.closest('[data-rline]').dataset.rline; file.value = ''; file.click(); }));
        file.addEventListener('change', async () => {
            const f = file.files && file.files[0];
            const l = recOrder && recOrder.lines.find(x => x.id === Number(file.dataset.line));
            if (!f || !l) return;
            try { lineDraft(l).photos.push(await shrink(f)); renderReceiving(); } catch (e) { toast(t('rc_photo_error'), true); }
        });
        // Zdjęcie etykiety → OCR : partia i termin ważności wpisane (puste pola), kandydaci do dotknięcia.
        const labelInput = el.querySelector('[data-label-input]');
        el.querySelectorAll('[data-label]').forEach(b => b.addEventListener('click', () => { labelInput.dataset.line = b.closest('[data-rline]').dataset.rline; labelInput.value = ''; labelInput.click(); }));
        labelInput.addEventListener('change', () => {
            const f = labelInput.files && labelInput.files[0];
            const l = recOrder && recOrder.lines.find(x => x.id === Number(labelInput.dataset.line));
            if (f && l) readLabel(l, f);
        });
        el.querySelectorAll('[data-ocr-lot]').forEach(b => b.addEventListener('click', () => { lineDraft(line(b)).lot = b.dataset.ocrLot; renderReceiving(); }));
        el.querySelectorAll('[data-ocr-date]').forEach(b => b.addEventListener('click', () => { lineDraft(line(b)).bb = b.dataset.ocrDate; renderReceiving(); }));
        el.querySelectorAll('[data-ocr-close]').forEach(b => b.addEventListener('click', () => { delete labelOcr[line(b).id]; renderReceiving(); }));
        const missing = el.querySelector('[data-rmissing]');
        if (missing) missing.addEventListener('click', () => saveCheck(true));
        const save = el.querySelector('[data-rsave]');
        if (save) save.addEventListener('click', () => saveCheck(false));
    }

    function updateTempStat() {
        // Licznik « Chłodnia : temperatura » bez przerysowania (klawiatura zostaje otwarta).
        const kv = [...el.querySelectorAll('.rc-kv')].find(n => n.textContent.includes(t('rc_stats_temp')));
        if (!kv || !recOrder) return;
        const cold = recOrder.lines.filter(toCheck).filter(l => l.cold);
        kv.querySelector('b').textContent = t('rc_stats_temp_v', {n: cold.filter(l => num(lineDraft(l).temp) !== null).length, m: cold.length});
    }

    // Zdjęcie z aparatu → JPEG najwyżej 1280 px (≈ 150–300 kB).
    function shrink(file) {
        return new Promise((resolve, reject) => {
            const img = new Image();
            const url = URL.createObjectURL(file);
            img.onload = () => {
                const k = Math.min(1, 1280 / Math.max(img.width, img.height));
                const c = document.createElement('canvas');
                c.width = Math.round(img.width * k); c.height = Math.round(img.height * k);
                c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
                URL.revokeObjectURL(url);
                resolve(c.toDataURL('image/jpeg', 0.78));
            };
            img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('image')); };
            img.src = url;
        });
    }

    async function saveCheck(notArrived) {
        if (busy || !recOrder) return;
        if (peopleNow().length && !operatorNow()) { toast(t('choose_operator_first'), true); drop = 'operator'; renderReceiving(); return; }
        const o = recOrder;
        const body = {date: recDay, employee_id: operatorNow() ? operator : null};
        if (notArrived) {
            if (!window.confirm(t('rc_confirm_missing', {code: o.code}))) return;
            body.arrived = false;
        } else {
            const lines = o.lines.filter(toCheck);
            body.lines = []; body.problems = [];
            for (const l of lines) {
                const d = lineDraft(l);
                const edit = o.receivable && l.remaining > 0;
                const qty = edit ? num(d.qty) : null;
                if (edit && d.qty !== '' && qty === null) { toast(t('rc_number', {name: l.name}), true); return; }
                const temp = num(d.temp);
                body.lines.push({id: l.id, status: d.status, qty: edit ? (qty ?? 0) : null, lot: edit && d.lot.trim() ? d.lot.trim() : null,
                    best_before: edit && d.bb ? d.bb : null, temperature: edit && l.cold ? temp : null});
                if (d.status === 'nc') {
                    if (!d.kinds.length || !d.decision) { toast(t('rc_need_kind', {name: l.name}), true); return; }
                    body.problems.push({line_id: l.id, kinds: d.kinds, description: d.desc.trim(), decision: d.decision, photos: d.photos});
                }
            }
        }
        busy = true;
        el.querySelectorAll('[data-rsave],[data-rmissing]').forEach(b => { b.disabled = true; });
        try {
            const r = await api('POST', `/kiosk/receiving/orders/${o.id}`, body);
            const res = r.result || {ok: 0, nc: 0, nc_numbers: []};
            toast(notArrived ? t('rc_saved_missing', {code: o.code}) : t('rc_saved', {ok: res.ok, nc: res.nc}) + (res.nc_numbers.length ? ' · ' + res.nc_numbers.join(', ') : ''));
            draft = {}; recheck = false;
            rec = await api('GET', `/kiosk/receiving?date=${encodeURIComponent(recDay)}`);
            const n = nextOrder();
            if (n) { recSel = n.id; recOrder = await api('GET', `/kiosk/receiving/orders/${n.id}?date=${encodeURIComponent(recDay)}`); }
            else recOrder = r;
            renderReceiving();
        } catch (err) {
            if (err.code !== 'DEVICE_UNAUTHENTICATED') { toast(err.message, true); renderReceiving(); }
        } finally { busy = false; }
    }

    el.addEventListener('click', async (e) => {
        if (e.target.closest('[data-lang]')) {
            lang = lang === 'pl' ? 'fr' : 'pl';
            store.set(LANG_KEY, lang);
            await loadLabels();
            current() ? render() : load();
        }
        if (e.target.closest('[data-refresh]')) load();
        const m = e.target.closest('[data-mode]');
        if (m && m.dataset.mode !== mode) {
            mode = m.dataset.mode;
            store.set(MODE_KEY, mode);
            drop = null; panel = null; product = null;
            current() ? render() : null;
            load();
        }
        const d = e.target.closest('[data-date]');
        if (d) { date = d.dataset.date; people = null; customer = null; product = null; panel = null; load(); }
    });
    // Odświeżanie : pakowanie co 30 s, paletyzacja co 15 s (nie w trakcie wpisywania ani przy otwartej liście).
    let tick = 0;
    window.setInterval(() => {
        tick++;
        if (!current() || busy || document.visibilityState !== 'visible' || !navigator.onLine) return;
        if (mode === 'pack' && tick % 2 === 0) load();
        if (mode === 'pallets' && panel !== 'pad' && !drop) load();
        // Przyjęcia : odświeżenie listy co minutę, gdy nic nie jest wpisywane.
        if (mode === 'receiving' && tick % 4 === 0 && !drop && !Object.keys(draft).length) load();
    }, 15000);

    loadLabels().then(load);
})();
