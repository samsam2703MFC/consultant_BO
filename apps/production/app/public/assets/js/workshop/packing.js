/*
 * Tablet pakowania / Tablette d'emballage (PWA, 28/10/2026). Parowanie tym samym kodem co inne tablety (Tablety ›
 * Zarządzanie) ; każdy sparowany tablet, bez PIN. Dwa tryby (przełącznik w nagłówku, zapamiętany na tablecie) :
 *  - Pakowanie : produkty do spakowania (zamówione − na paletach − na stanie, w kolejności trasy), kartony, sztuki,
 *    czas wg normy « Pakowanie », osoby, czy zdążymy przed wyjazdem ; licznik − / + / « Gotowe » / « Cofnij »
 *    (informacyjny : nie tworzy kartonów w magazynie) ; odświeżanie co 30 s.
 *  - Paletyzacja : operator (osoby obecne), transport (pojazd albo wszyscy klienci dnia), klienci u góry, produkty
 *    zamówione przez klienta : zamówione / na palecie / zostało ; dotknięcie wiersza → szczegóły (skan + 1 + 2 + 32 = …),
 *    « + » → klawiatura (1, 5, 10, reszta) → kartony na paletę ; cofnięcie ostatniego wpisu ; odświeżanie co 15 s.
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
    let mode = ['pack', 'pallets'].includes(query.get('mode')) ? query.get('mode') : (store.get(MODE_KEY) === 'pallets' ? 'pallets' : 'pack');
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
    const current = () => (mode === 'pack' ? data : pal);

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
        const title = t(mode === 'pack' ? 'title' : 'title_pallets');
        if (!s) {
            return `<header class="kiosk-head"><div class="kiosk-brand"><div class="kiosk-logo">LA</div><div><h1>${esc(title)}</h1>
                <p class="kiosk-muted">${esc(t('subtitle'))}</p></div></div>
                <div class="kiosk-head-tools">${device ? `<button type="button" class="kiosk-chip" data-refresh aria-label="${esc(t('refresh'))}">↻</button>` : ''}
                <button type="button" class="kiosk-chip" data-lang>${esc(t('lang_switch'))}</button></div></header>`;
        }
        const op = mode === 'pallets' ? operatorNow() : null;
        const who = mode === 'pallets' ? `<button type="button" class="pk-avatar ${op ? '' : 'is-empty'}" data-drop="operator" aria-haspopup="listbox"
                title="${esc(op ? op.name : t('operator_none'))}" aria-label="${esc(t('operator'))} : ${esc(op ? op.name : t('operator_none'))}">${op ? esc(initials(op.name)) : '?'}</button><span class="pk-gap"></span>` : '';
        const sw = `<div class="pk-switch" role="radiogroup" aria-label="${esc(t('mode'))}">
                ${['pack', 'pallets'].map(m => `<button type="button" role="radio" aria-checked="${m === mode}" class="${m === mode ? 'is-on' : ''}" data-mode="${m}">${esc(t(m === 'pack' ? 'mode_pack' : 'mode_pallets'))}</button>`).join('')}</div>`;
        return `<header class="kiosk-head pk-head"><div class="kiosk-logo" title="${esc(title)}">LA</div><h1 class="pk-sr">${esc(title)}</h1>${daySeg(s)}
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
            if (mode === 'pack') {
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
        if (mode === 'pack') renderPack(); else renderPallets();
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
    const operatorNow = () => (pal ? pal.people.find(p => p.id === operator) : null);

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
        if (!c.load_now && !c.load_final) return '';
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
        const html = row(t('load_now'), c.load_now) + row(t('load_final'), c.load_final);
        return html || `<div class="pp-load"><span class="pp-load-l">${esc(t('load_none'))}</span></div>`;
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
            items = pal.people.length ? pal.people.map(p => `<button type="button" class="pp-opt ${p.id === operator ? 'is-on' : ''}" data-operator="${p.id}"><span class="pp-av">${esc(initials(p.name))}</span>
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
    }, 15000);

    loadLabels().then(load);
})();
