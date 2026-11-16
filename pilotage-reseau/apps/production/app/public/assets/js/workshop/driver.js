/*
 * Tablet kierowcy / Tablette chauffeur (PWA). Parowanie tym samym kodem co tablet pracowni (tablet w trybie
 * « driver » w zarządzaniu tabletami). Trasa dnia pojazdu : klienci w kolejności rozładunku, adres, telefon,
 * palety i ich numery, dokumenty, instrukcje dostawy klienta (procedura rozładunku, alarm, klucz, zamrażarka) ;
 * start i koniec trasy ; przyjazd (zdjęcia przed rozładunkiem z datą i godziną na zdjęciu) ; « Dostarczono »
 * (zdjęcia po rozładunku, odbiorca, podpis, palety oddane i zwrócone) albo « Problem » (przyczyna, notatka, zdjęcie) ;
 * raport PDF trasy i klienta. Bez sieci zapisy i zdjęcia czekają w kolejce i wysyłają się same po powrocie sieci.
 * Kierowca wybiera się z listy i wpisuje swój PIN (jak na tablecie pracowni) ; sesja trwa trasę (driver.session_hours).
 * Kod QR trasy z ERP (Logistyka › Dostawy) otwiera pojazd i dzień : skan w aplikacji albo link …/driver?date=…&vehicle=….
 */
(function () {
    'use strict';
    const VERSION = (document.currentScript && new URL(document.currentScript.src, location.href).searchParams.get('v')) || '';
    const el = document.getElementById('tfbDriver');
    if (!el) return;
    const base = (el.dataset.apiBase || '').replace(/\/$/, '');
    const root = (el.dataset.root || '').replace(/\/$/, '');
    // Ten sam token co tablet pracowni : tablet sparowany raz działa na /kiosk i /driver (tryb decyduje o stronie).
    const DEVICE_KEY = 'tfb.kiosk.device';
    const LANG_KEY = 'tfb.lang';
    const QUEUE_KEY = 'tfb.driver.queue';
    const SESSION_KEY = 'tfb.driver.session';
    const VEHICLE_KEY = 'tfb.driver.vehicle';
    const store = {
        get(k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
        set(k, v) { try { v === null ? window.localStorage.removeItem(k) : window.localStorage.setItem(k, v); } catch (e) { /* stockage indisponible */ } },
    };
    let lang = store.get(LANG_KEY) === 'fr' ? 'fr' : 'pl';
    let labels = {};
    let device = store.get(DEVICE_KEY);
    let paired = !!device;
    let data = null;
    // Dzień w adresie (?date=RRRR-MM-DD) : np. logistyka sprawdza wczorajszą trasę.
    let date = (new URLSearchParams(location.search).get('date') || '').match(/^\d{4}-\d{2}-\d{2}$/) ? new URLSearchParams(location.search).get('date') : null;
    let vehicle = Number(new URLSearchParams(location.search).get('vehicle')) || Number(store.get(VEHICLE_KEY)) || null;
    // Kierowca zalogowany (lista + PIN) : {token, id, name, exp}.
    let session = null;
    try { session = JSON.parse(store.get(SESSION_KEY) || 'null'); } catch (e) { session = null; }
    if (session && (!session.token || !session.exp || session.exp < Date.now())) session = null;
    let queue = [];
    try { queue = JSON.parse(store.get(QUEUE_KEY) || '[]') || []; } catch (e) { queue = []; }
    let flushing = false;

    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const t = (k, p) => { let s = labels[k] ?? k; for (const [a, b] of Object.entries(p || {})) s = s.split('{' + a + '}').join(String(b)); return s; };
    const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16); }));
    const fmt = (n, d) => Number(n || 0).toLocaleString(lang === 'fr' ? 'fr-FR' : 'pl-PL', {maximumFractionDigits: d ?? 1});
    const hhmm = (s) => String(s || '').slice(11, 16);
    const pad = (n) => String(n).padStart(2, '0');
    const nowLocal = () => { const d = new Date(); return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`; };
    const dayLabel = (iso) => new Date(iso + 'T12:00:00').toLocaleDateString(lang === 'fr' ? 'fr-FR' : 'pl-PL', {weekday: 'short', day: 'numeric', month: 'short'});
    const stampText = (d) => `${pad(d.getDate())}.${pad(d.getMonth() + 1)}.${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
    const localIso = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
    /** Zapis kolejki ; false = brak miejsca na tablecie (zdjęcia są duże). */
    const saveQueue = () => {
        try {
            if (queue.length) window.localStorage.setItem(QUEUE_KEY, JSON.stringify(queue)); else window.localStorage.removeItem(QUEUE_KEY);
            return true;
        } catch (e) { return false; }
    };
    const STOP_PATH = '/driver/stops';
    const PHOTO_PATH = '/driver/photos';
    const PALLET_PATH = '/driver/pallets';
    const INCIDENT_PATH = '/driver/incidents';
    // [310] Punkt otwarty po prawej (lista punktów po lewej) ; miniatury zdjęć palet zrobionych w tej sesji.
    let selected = null;
    const thumbs = {};

    async function loadLabels() {
        const res = await fetch(`${root}/resources/lang/ui/${lang}/driver.json${VERSION ? '?v=' + encodeURIComponent(VERSION) : ''}`, {credentials: 'same-origin'});
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
        if (session) headers['X-Employee-Token'] = session.token;
        if (body !== undefined) headers['Content-Type'] = 'application/json';
        let res;
        try {
            res = await fetch(base + path, {method, headers, body: body === undefined ? undefined : JSON.stringify(body), credentials: 'same-origin'});
        } catch (e) {
            const err = new Error(t('offline'));
            err.offline = true;
            throw err;
        }
        const json = await res.json().catch(() => ({}));
        if (res.ok) return json.data;
        const err = new Error(((json.errors || []).map(x => x.message).join(' ')) || json.message || t('error'));
        err.code = json.description;
        err.status = res.status;
        if (err.code === 'DEVICE_UNAUTHENTICATED') { paired = false; renderPairing(err.message); }
        if (err.code === 'DEVICE_NOT_DRIVER') location.replace(root + '/tablet/production');
        if (err.code === 'EMPLOYEE_UNAUTHENTICATED' || err.code === 'EMPLOYEE_SESSION_EXPIRED') { setSession(null); renderLogin(err.message); }
        throw err;
    }

    function header() {
        const pending = queue.length;
        return `<header class="kiosk-head"><div class="kiosk-brand"><div class="kiosk-logo">LA</div><div><h1>${esc(t('title'))}</h1>
                <p class="kiosk-muted">${esc(data && data.vehicle ? data.vehicle.label + (data.vehicle.carrier && data.vehicle.carrier !== data.vehicle.label ? ' · ' + data.vehicle.carrier : '') : t('subtitle'))}</p></div></div>
            <div class="kiosk-head-tools">${pending ? `<span class="driver-queue" title="${esc(t('queue_help'))}">${esc(t('queue', {n: pending}))}</span>` : ''}
                ${paired && session ? `<button type="button" class="kiosk-chip" data-refresh aria-label="${esc(t('refresh'))}">↻</button>` : ''}
                <button type="button" class="kiosk-chip" data-lang>${esc(t('lang_switch'))}</button>
                ${session ? `<span class="driver-who">${esc(session.name)}</span><button type="button" class="kiosk-chip" data-logout>${esc(t('logout'))}</button>` : ''}</div></header>`;
    }

    function setSession(value) {
        session = value;
        store.set(SESSION_KEY, value ? JSON.stringify(value) : null);
    }

    // --- Tournée → kierowca (cały personel, kierowcy na górze) → PIN (27/10/2026) ---------------------------
    async function renderLogin(message) {
        if (!paired) return renderPairing();
        setSession(null);
        let people = [];
        let day = {tours: [], default_vehicle_id: null};
        try {
            [people, day] = await Promise.all([api('GET', '/kiosk/people'),
                api('GET', '/driver/tours' + (date ? '?date=' + encodeURIComponent(date) : '')).catch(() => ({tours: [], default_vehicle_id: null}))]);
        } catch (err) { if (err.code === 'DEVICE_UNAUTHENTICATED' || err.code === 'DEVICE_NOT_DRIVER') return; message = message || err.message; }
        const tours = day.tours || [];
        // Tournée proposée : celle de la tablette (dernier choix, QR), sinon celle du véhicule de la tablette, sinon la seule du jour.
        const preset = [vehicle, day.default_vehicle_id].find(id => id && tours.some(x => x.id === id)) || (tours.length === 1 ? tours[0].id : null);
        const option = (p) => `<option value="${p.id}" ${p.has_pin ? '' : 'disabled'}>${esc(p.name)}${p.has_pin ? '' : ' — ' + esc(t('no_pin'))}</option>`;
        const drivers = people.filter(p => p.driver);
        const others = people.filter(p => !p.driver);
        let pin = '';
        el.innerHTML = header() + `<section class="kiosk-card kiosk-pin driver-login"><h2>${esc(t('who_title'))}</h2><p class="kiosk-muted">${esc(t('who_help'))}</p>
            <button type="button" class="kiosk-btn is-primary driver-login-scan" data-login-scan>▣ ${esc(t('login_scan'))}</button>
            <label class="driver-field"><span>${esc(t('tour'))}</span><select class="driver-vehicle driver-person" data-tour aria-label="${esc(t('choose_tour'))}"><option value="">${esc(tours.length ? t('choose_tour') : t('no_tours'))}</option>
                ${tours.map(x => `<option value="${x.id}" ${x.id === preset ? 'selected' : ''}>${esc(x.label)}${x.carrier && x.carrier !== x.label ? ' · ' + esc(x.carrier) : ''}${x.departure ? ' · ' + esc(String(x.departure).slice(0, 5)) : ''} — ${esc(t('vehicle_stops', {n: x.customers, pallets: fmt(x.pallets, 0)}))}</option>`).join('')}</select></label>
            <label class="driver-field"><span>${esc(t('driver'))}</span><select class="driver-vehicle driver-person" data-person aria-label="${esc(t('choose_driver'))}"><option value="">${esc(t('choose_driver'))}</option>
                ${drivers.length && others.length ? `<optgroup label="${esc(t('drivers_group'))}">${drivers.map(option).join('')}</optgroup><optgroup label="${esc(t('others_group'))}">${others.map(option).join('')}</optgroup>`
                    : people.map(option).join('')}</select></label>
            <div class="kiosk-dots" data-dots aria-hidden="true"></div><p class="kiosk-error" data-error>${esc(message || (people.length ? '' : t('no_drivers')))}</p>
            <div class="kiosk-pad">${[1, 2, 3, 4, 5, 6, 7, 8, 9].map(d => `<button type="button" class="kiosk-btn" data-digit="${d}">${d}</button>`).join('')}
                <button type="button" class="kiosk-btn" data-back aria-label="${esc(t('erase'))}">⌫</button><button type="button" class="kiosk-btn" data-digit="0">0</button>
                <button type="button" class="kiosk-btn is-primary" data-ok>OK</button></div></section>`;
        const select = el.querySelector('[data-person]');
        const tourSelect = el.querySelector('[data-tour]');
        const error = el.querySelector('[data-error]');
        const dots = () => { el.querySelector('[data-dots]').innerHTML = Array.from({length: Math.max(4, pin.length)}, (_, i) => `<span class="${i < pin.length ? 'is-on' : ''}"></span>`).join(''); };
        dots();
        const submit = async (ok) => {
            if (tours.length && !tourSelect.value) { error.textContent = t('choose_tour'); return; }
            if (!select.value) { error.textContent = t('choose_driver'); return; }
            if (pin.length < 4) return;
            ok.disabled = true;
            try {
                const r = await api('POST', '/kiosk/identify', {employee_id: Number(select.value), pin});
                setSession({token: r.employee_token, id: r.employee.id, name: r.employee.name, exp: Date.now() + r.expires_in * 1000});
                // La tournée choisie devient le véhicule de la journée (comme le QR de la tournée).
                if (tourSelect.value) { vehicle = Number(tourSelect.value); store.set(VEHICLE_KEY, String(vehicle)); }
                toast(t('hello', {name: r.employee.name}));
                load().then(flush);
            } catch (err) {
                pin = ''; dots(); ok.disabled = false;
                error.textContent = err.message;
            }
        };
        el.querySelector('.kiosk-pad').addEventListener('click', (e) => {
            const b = e.target.closest('button');
            if (!b) return;
            if (b.dataset.digit !== undefined && pin.length < 6) pin += b.dataset.digit;
            if (b.hasAttribute('data-back')) pin = pin.slice(0, -1);
            dots();
            if (b.hasAttribute('data-ok')) submit(b);
        });
        select.addEventListener('change', () => { error.textContent = ''; });
        tourSelect.addEventListener('change', () => { error.textContent = ''; });
        // [310] 1. QR trasy → 2. nazwisko → 3. PIN → OK : trasa ustawiona, kierowca zaczyna.
        const shownDate = date;
        el.querySelector('[data-login-scan]').addEventListener('click', () => openTourScan(() => {
            if (date !== shownDate) { renderLogin(); return; }
            if (!tours.some(x => x.id === vehicle)) { error.textContent = t('tour_scanned_other'); return; }
            tourSelect.value = String(vehicle);
            error.textContent = '';
            select.focus();
        }));
    }

    // --- Parowanie -------------------------------------------------------------------------------------
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
                paired = true;
                store.set(DEVICE_KEY, device);
                if (r.device.mode !== 'driver') { location.replace(root + (r.device.mode === 'office' ? '/tablet/office' : '/tablet/production')); return; }
                toast(t('paired', {name: r.device.name}));
                load();
            } catch (err) { el.querySelector('[data-error]').textContent = err.message; }
        });
        el.querySelector('[name=code]').focus();
    }

    // --- Dzień ---------------------------------------------------------------------------------------------
    async function load() {
        if (!paired) return renderPairing();
        if (!session) return renderLogin();
        const q = new URLSearchParams();
        if (date) q.set('date', date);
        if (vehicle) q.set('vehicle_id', String(vehicle));
        try {
            data = await api('GET', '/driver/day' + (q.toString() ? '?' + q : ''));
            date = data.date;
            vehicle = data.vehicle ? data.vehicle.id : null;
            if (vehicle) store.set(VEHICLE_KEY, String(vehicle));
            render();
        } catch (err) {
            if (['DEVICE_UNAUTHENTICATED', 'DEVICE_NOT_DRIVER', 'EMPLOYEE_UNAUTHENTICATED', 'EMPLOYEE_SESSION_EXPIRED'].includes(err.code)) return;
            el.innerHTML = header() + `<section class="kiosk-card"><p class="kiosk-error">${esc(err.message)}</p>
                <div class="kiosk-row"><button type="button" class="kiosk-btn is-primary" data-retry>${esc(t('retry'))}</button></div></section>`;
            el.querySelector('[data-retry]').addEventListener('click', load);
        }
    }

    const queuedFor = (customerId) => queue.find(q => (q.path || STOP_PATH) === STOP_PATH && q.body.customer_id === customerId && q.body.date === date);
    /** Zdjęcia klienta : zapisane na serwerze + czekające w kolejce. */
    const photoCount = (s, kind) => (s.photos && s.photos[kind] ? s.photos[kind].length : 0)
        + queue.filter(q => q.path === PHOTO_PATH && q.body.customer_id === s.customer_id && q.body.date === date && q.body.kind === kind).length;

    function stopState(s) {
        const q = queuedFor(s.customer_id);
        if (q) return `<span class="driver-state is-queued">${esc(t('state_queued', {status: t('status_' + q.body.status)}))}</span>`;
        if (s.status === 'delivered') return `<span class="driver-state is-ok">${esc(t('state_delivered', {time: hhmm(s.recorded_at), who: s.receiver_name || ''}))}</span>`;
        if (s.status === 'arrived') return `<span class="driver-state is-arrived">${esc(t('state_arrived', {time: hhmm(s.arrived_at || s.recorded_at)}))}</span>`;
        if (s.status === 'problem') {
            const reason = (data.reasons.find(r => r.code === s.reason) || {}).label || s.reason;
            return `<span class="driver-state is-problem">${esc(t('state_problem', {time: hhmm(s.recorded_at), reason}))}</span>`;
        }
        return `<span class="driver-state">${esc(t('state_todo'))}</span>`;
    }

    /** Palety punktu zeskanowane : serwer + kolejka bez sieci. */
    const scannedOf = (s) => {
        const set = new Set((s.pallet_scans || []).map(x => Number(x.pallet_no)));
        queue.filter(q => q.path === PALLET_PATH && q.body.customer_id === s.customer_id && q.body.date === date).forEach(q => set.add(Number(q.body.pallet_no)));
        return set;
    };
    const isDone = (s) => s.status === 'delivered' || s.status === 'problem' || !!queuedFor(s.customer_id);
    const DOC_TYPES = {CMR: 'cmr', WZ: 'wz', WDT: 'wdt'};
    const docTag = (type) => type === 'WDT' ? 'FV' : type;
    const docName = (type) => type === 'WDT' ? t('invoice') : type;
    const incidentsOf = (s) => (s.incidents || []).concat(queue.filter(q => q.path === INCIDENT_PATH && q.body.customer_id === s.customer_id && q.body.date === date)
        .map(q => ({kind: q.body.kind, label: ((data.incident_kinds || []).find(k => k.code === q.body.kind) || {}).label || q.body.kind, note: q.body.note, queued: true})));

    function listItem(s) {
        const q = queuedFor(s.customer_id);
        const sub = q ? t('state_queued', {status: t('status_' + q.body.status)})
            : s.status === 'delivered' ? t('state_delivered', {time: hhmm(s.recorded_at), who: s.receiver_name || t('mode_absent')})
            : s.status === 'problem' ? t('status_problem') + ' ' + hhmm(s.recorded_at)
            : s.status === 'arrived' ? t('state_arrived', {time: hhmm(s.arrived_at || s.recorded_at)})
            : t('list_pallets', {n: fmt(s.pallets, 0)}) + (s.address ? ' · ' + s.address.split(',').slice(-2, -1).join('').trim() : '');
        const mark = q ? '…' : s.status === 'delivered' ? '✓' : s.status === 'problem' ? '!' : s.n;
        return `<button type="button" class="driver-li ${s.customer_id === selected ? 'is-sel' : ''} ${s.status ? 'is-' + s.status : ''}" data-select="${s.customer_id}">
            <span class="driver-n">${esc(mark)}</span><span class="driver-li-main"><b>${esc(s.name)}</b><small>${esc(sub)}</small></span></button>`;
    }

    function stopDetail(s) {
        const nums = s.pallet_numbers || [];
        const scanned = scannedOf(s);
        const missing = nums.filter(n => !scanned.has(Number(n)));
        const incidents = incidentsOf(s);
        const delivered = s.status === 'delivered';
        const tile = (label, value, cls) => `<div class="driver-tile ${cls || ''}"><span>${esc(label)}</span><strong>${value}</strong></div>`;
        const docs = (s.documents || []).filter(doc => DOC_TYPES[doc.type]).map(doc => `<button type="button" class="driver-docbtn" data-doc="${DOC_TYPES[doc.type]}">
                <span class="driver-doc-tag">${esc(docTag(doc.type))}</span><span><b>${esc(docName(doc.type))}</b><small>${esc(doc.num)}</small></span>
                ${doc.type === 'CMR' && s.has_signed_cmr ? `<em>✓ ${esc(t('signed'))}</em>` : '<i>›</i>'}</button>`).join('');
        const step = (attr, icon, title, sub, cls) => `<button type="button" class="driver-step ${cls || ''}" ${attr}><b>${icon} ${esc(title)}</b>${sub ? `<small>${esc(sub)}</small>` : ''}</button>`;
        return `<article class="kiosk-card driver-stop ${s.status ? 'is-' + s.status : ''}" data-stop="${s.customer_id}">
            <div class="driver-stop-head"><span class="driver-n">${s.n}</span><div class="driver-stop-title"><h3>${esc(s.name)}</h3>
                <p class="kiosk-muted">${esc(s.address || '—')}${s.phone ? ' · ☎ ' + esc(s.phone) : ''}</p></div>${stopState(s)}</div>
            <div class="driver-tiles">
                ${nums.length ? tile(t('tile_unloaded'), `${scanned.size} / ${nums.length}`, missing.length ? 'is-warn' : 'is-ok')
                    : tile(t('pallets'), (s.pallets_estimated ? '≈ ' : '') + fmt(s.pallets, 0))}
                ${tile(t('tile_returned'), s.pallets_returned ?? '—')}
                ${tile(t('tile_temperature'), photoCount(s, 'temperature') ? '📷 ' + photoCount(s, 'temperature') : '—')}
                ${tile(t('tile_status'), esc(s.status === 'arrived' ? t('tile_on_site', {time: hhmm(s.arrived_at || s.recorded_at)}) : s.status === 'delivered' ? t('status_delivered') + ' ' + hhmm(s.recorded_at) : s.status === 'problem' ? t('status_problem') : t('state_todo')))}
            </div>
            ${instructionsBlock(s)}
            <h4 class="driver-sub">${esc(t('stop_documents'))}</h4>
            ${docs ? `<div class="driver-docs">${docs}</div>` : `<p class="kiosk-muted">${esc(t('no_documents'))}</p>`}
            ${photoCount(s, 'before') || photoCount(s, 'after') ? `<p class="kiosk-muted driver-photo-summary">📷 ${esc(t('photos_before'))} : ${photoCount(s, 'before')} · ${esc(t('photos_after'))} : ${photoCount(s, 'after')}</p>` : ''}
            ${incidents.length ? `<ul class="driver-incidents">${incidents.map(x => `<li>⚠ <b>${esc(x.label)}</b>${x.note ? ' — ' + esc(x.note) : ''}${x.queued ? ' <span class="driver-state is-queued">' + esc(t('queued_short')) + '</span>' : ''}</li>`).join('')}</ul>` : ''}
            ${s.note && s.status === 'problem' ? `<p class="kiosk-muted">${esc(s.note)}</p>` : ''}
            <h4 class="driver-sub">${esc(t('delivery_steps'))}</h4>
            <div class="driver-steps">
                ${!delivered && !photoCount(s, 'temperature') ? step('data-photo="temperature"', '🌡', t('temperature_photo'), t('temperature_help'), 'is-light') : ''}
                ${!delivered ? step('data-photo="before"', '📷', t(photoCount(s, 'before') ? 'add_before' : 'arrive_photo'), '', 'is-light') : ''}
                ${nums.length ? step('data-pallets', '📦', t('unload_pallets'), missing.length ? t('unload_pallets_sub', {n: scanned.size, total: nums.length, missing: missing.join(', ')}) : t('unload_pallets_done', {n: nums.length}), missing.length ? 'is-blue' : 'is-done') : ''}
                ${step('data-photo="after"', '📷', t('after_photo'), photoCount(s, 'after') ? t('photos_n', {n: photoCount(s, 'after')}) : t('after_photo_help'), 'is-light')}
                ${step('data-incident', '⚠', t('incident'), t('incident_help'), 'is-warn')}
                ${step('data-deliver', '✍', delivered ? t('deliver_again') : t('receipt'), t('receipt_help'), 'is-red is-wide')}
            </div>
            <details class="driver-products"><summary>${esc(t('products', {n: s.products.length}))}</summary>
                <ul>${s.products.map(p => `<li>${esc(p.name)} — ${fmt(p.cartons, 1)} ${esc(t('cartons'))}</li>`).join('')}</ul></details>
            <div class="kiosk-row driver-actions">
                ${s.address ? `<a class="kiosk-btn is-small" href="https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(s.address)}" target="_blank" rel="noopener">${esc(t('navigate'))}</a>` : ''}
                ${s.phone ? `<a class="kiosk-btn is-small" href="tel:${esc(String(s.phone).replace(/[^0-9+]/g, ''))}">${esc(t('call'))}</a>` : ''}
                ${s.status ? `<button type="button" class="kiosk-btn is-small" data-stop-pdf>${esc(t('stop_pdf'))}</button>` : ''}
                <button type="button" class="kiosk-btn is-small is-danger-soft" data-problem>${esc(t('problem'))}</button></div>
        </article>`;
    }

    function render() {
        const d = data;
        const run = d.run || {};
        const v = d.vehicle;
        if (!d.stops.some(s => s.customer_id === selected)) selected = ((d.stops.find(s => !isDone(s)) || d.stops[0]) || {}).customer_id || null;
        const runCard = v ? `<section class="kiosk-card driver-run">
                <div class="driver-run-main"><div><h2>${esc(v.label)}</h2><p class="kiosk-muted">${esc(t('run_summary', {stops: d.counts.stops, pallets: fmt(v.pallets, 0), kg: fmt(v.weight_kg, 0)}))}
                    ${v.departure ? ' · ' + esc(t('departure', {time: v.departure})) : ''}</p></div>
                    <div class="driver-progress"><strong>${d.counts.delivered}/${d.counts.stops}</strong><span class="kiosk-muted">${esc(t('delivered_count'))}</span>
                    ${d.counts.problem ? `<span class="driver-state is-problem">${esc(t('problems', {n: d.counts.problem}))}</span>` : ''}</div></div>
                <div class="driver-run-actions"><span class="driver-name">${esc(t('driver_name'))}<strong>${esc(session ? session.name : (run.driver_name || ''))}</strong></span>
                    ${!run.started_at ? `<button type="button" class="kiosk-btn is-primary" data-run="start">${esc(t('start_run'))}</button>`
                        : !run.finished_at ? `<span class="kiosk-muted">${esc(t('started', {time: hhmm(run.started_at)}))}</span>`
                        : `<span class="driver-state is-ok">${esc(t('finished', {from: hhmm(run.started_at), to: hhmm(run.finished_at)}))}</span>`}
                    <button type="button" class="kiosk-btn ${d.loading && d.loading.loaded < d.loading.total ? 'is-primary' : ''}" data-loading ${d.loading ? '' : 'disabled'}>⛟ ${esc(d.loading ? t('loading_plan_n', {loaded: d.loading.loaded, total: d.loading.total}) : t('loading_plan_none'))}</button>
                    <button type="button" class="kiosk-btn" data-docs>${esc(t('documents'))}</button>
                    <button type="button" class="kiosk-btn ${d.stops.length && d.stops.every(isDone) && !run.finished_at ? 'is-ok' : ''}" data-summary>${esc(t('summary_button'))}</button></div></section>` : '';
        const choose = `<section class="driver-choose">
                <div class="driver-days">${d.dates.map(x => `<button type="button" class="kiosk-chip ${x === d.date ? 'is-active' : ''}" data-date="${x}">${esc(dayLabel(x))}</button>`).join('')}
                    <button type="button" class="kiosk-chip" data-scan-tour>▣ ${esc(t('scan_tour'))}</button></div>
                ${d.vehicles.length > 1 || !v ? `<select class="driver-vehicle" data-vehicle aria-label="${esc(t('vehicle'))}"><option value="">${esc(t('choose_vehicle'))}</option>
                    ${d.vehicles.map(x => `<option value="${x.id}" ${v && x.id === v.id ? 'selected' : ''}>${esc(x.label)} · ${esc(t('vehicle_stops', {n: x.customers, pallets: fmt(x.pallets, 0)}))}</option>`).join('')}</select>` : ''}
            </section>`;
        const current = d.stops.find(s => s.customer_id === selected);
        const split = d.stops.length ? `<div class="driver-split"><nav class="driver-list" aria-label="${esc(t('stops_list'))}">${d.stops.map(listItem).join('')}</nav>
                <div class="driver-detail">${current ? stopDetail(current) : ''}</div></div>` : `<section class="kiosk-card"><p class="kiosk-muted">${esc(t('no_stops'))}</p></section>`;
        el.innerHTML = header() + choose + runCard + (v ? split
            : `<section class="kiosk-card"><p class="kiosk-muted">${esc(d.vehicles.length ? t('choose_vehicle_help') : t('no_vehicles'))}</p></section>`);
        bind();
    }

    function bind() {
        el.querySelectorAll('[data-date]').forEach(b => b.addEventListener('click', () => { date = b.dataset.date; load(); }));
        const vs = el.querySelector('[data-vehicle]');
        if (vs) vs.addEventListener('change', () => { vehicle = Number(vs.value) || null; store.set(VEHICLE_KEY, vehicle ? String(vehicle) : null); load(); });
        const scanTour = el.querySelector('[data-scan-tour]');
        if (scanTour) scanTour.addEventListener('click', () => openTourScan());
        el.querySelectorAll('[data-run]').forEach(b => b.addEventListener('click', () => runAction(b, b.dataset.run)));
        const loadingBtn = el.querySelector('[data-loading]');
        if (loadingBtn) loadingBtn.addEventListener('click', openLoading);
        const docs = el.querySelector('[data-docs]');
        if (docs) docs.addEventListener('click', () => openFile('/driver/documents?date=' + encodeURIComponent(date), docs));
        const summary = el.querySelector('[data-summary]');
        if (summary) summary.addEventListener('click', openSummary);
        el.querySelectorAll('[data-select]').forEach(b => b.addEventListener('click', () => {
            selected = Number(b.dataset.select);
            render();
            const detail = el.querySelector('.driver-detail');
            if (detail && window.matchMedia('(max-width: 899px)').matches) detail.scrollIntoView({behavior: 'smooth', block: 'start'});
        }));
        el.querySelectorAll('[data-stop]').forEach(card => {
            const s = data.stops.find(x => x.customer_id === Number(card.dataset.stop));
            card.querySelector('[data-deliver]').addEventListener('click', () => openDeliver(s));
            card.querySelector('[data-problem]').addEventListener('click', () => openProblem(s));
            card.querySelector('[data-incident]').addEventListener('click', () => openIncident(s));
            const pallets = card.querySelector('[data-pallets]');
            if (pallets) pallets.addEventListener('click', () => openPallets(s));
            card.querySelectorAll('[data-doc]').forEach(b => b.addEventListener('click', () =>
                openFile(`/driver/stop-document?date=${encodeURIComponent(date)}&customer_id=${s.customer_id}&type=${b.dataset.doc}`, b)));
            const pdf = card.querySelector('[data-stop-pdf]');
            if (pdf) pdf.addEventListener('click', () => openFile(`/driver/report?date=${encodeURIComponent(date)}&vehicle_id=${vehicle}&customer_id=${s.customer_id}`, pdf));
            card.querySelectorAll('[data-photo]').forEach(b => b.addEventListener('click', () => takePhoto(s, b.dataset.photo).then(ok => { if (ok) render(); })));
            bindReveal(card);
        });
    }

    async function runAction(b, action) {
        if (action === 'finish' && !window.confirm(t('finish_confirm'))) return false;
        b.disabled = true;
        try {
            await api('POST', '/driver/run', {date, vehicle_id: vehicle, action});
            toast(t(action === 'start' ? 'run_started' : 'run_finished'));
            await load();
            return true;
        } catch (err) { b.disabled = false; toast(err.message, true); return false; }
    }

    // --- [310] Koniec trasy : podsumowanie punktów, raport PDF, « Zakończ trasę » ----------------------------------
    function openSummary() {
        const d = data;
        const run = d.run || {};
        const rows = d.stops.map(s => {
            const q = queuedFor(s.customer_id);
            const status = q ? q.body.status : s.status;
            const incidents = incidentsOf(s);
            const icon = status === 'delivered' ? (incidents.length ? '!' : '✓') : status === 'problem' ? '!' : '○';
            const parts = [];
            if (status === 'delivered') parts.push(t('mode_done_' + ((q ? q.body.receipt_mode : s.receipt_mode) || 'tablet')));
            if (status === 'problem') parts.push(t('status_problem'));
            if (!status) parts.push(t('state_todo'));
            if (incidents.length) parts.push(t('incident_n', {list: incidents.map(x => x.label).join(', ')}));
            const photos = photoCount(s, 'before') + photoCount(s, 'after') + photoCount(s, 'temperature');
            if (photos) parts.push(t('photos_n', {n: photos}));
            if ((s.pallet_numbers || []).length) parts.push(t('pallets_scanned_n', {n: scannedOf(s).size, total: s.pallet_numbers.length}));
            return `<li class="driver-sum ${status === 'delivered' && !incidents.length ? 'is-ok' : status ? 'is-warn' : ''}"><span class="driver-n">${icon}</span>
                <span class="driver-li-main"><b>${esc(s.name)}</b><small>${esc(parts.join(' · '))}</small></span><span class="kiosk-muted">${esc(hhmm(q ? q.body.recorded_at : s.recorded_at))}</span></li>`;
        }).join('');
        const returned = d.stops.reduce((n, s) => n + (Number(s.pallets_returned) || 0), 0);
        const box = sheet(t('summary_title', {day: dayLabel(date)}), `<p class="kiosk-muted">${esc(t('summary_sub', {done: d.stops.filter(isDone).length, n: d.stops.length, pallets: fmt(d.vehicle ? d.vehicle.pallets : 0, 0), returned}))}</p>
            <ul class="driver-sums">${rows}</ul>
            ${queue.length ? `<p class="driver-queue">${esc(t('queue', {n: queue.length}))} — ${esc(t('queue_help'))}</p>` : ''}
            <div class="kiosk-row is-end"><button type="button" class="kiosk-btn" data-close>${esc(t('cancel'))}</button>
                <button type="button" class="kiosk-btn" data-sum-report>📄 ${esc(t('report_pdf'))}</button>
                ${run.started_at && !run.finished_at ? `<button type="button" class="kiosk-btn is-ok" data-sum-finish>✓ ${esc(t('finish_run'))}</button>` : ''}</div>`);
        const rep = box.querySelector('[data-sum-report]');
        rep.addEventListener('click', () => openFile(`/driver/report?date=${encodeURIComponent(date)}&vehicle_id=${vehicle}`, rep));
        const fin = box.querySelector('[data-sum-finish]');
        if (fin) fin.addEventListener('click', async () => { if (await runAction(fin, 'finish')) box.remove(); });
    }

    // --- Plan załadunku (28/10/2026) : naczepa z biura, palety na miejscach, « załadowana » dotknięciem ---------------
    const STOP_COLORS = ['#2E7D4F', '#1f4f7a', '#8D1D2C', '#C17A2A', '#6b4c9a', '#00838f', '#9c4a22', '#5d6d1e'];
    const stopColor = (n) => STOP_COLORS[((n || 1) - 1) % STOP_COLORS.length];
    async function openLoading() {
        let res;
        try {
            res = await api('GET', `/driver/loading?date=${encodeURIComponent(date)}&vehicle_id=${vehicle}`);
        } catch (err) { toast(err.message, true); return; }
        renderLoading(res.plan);
    }
    function renderLoading(plan) {
        if (!plan) { toast(t('loading_plan_none'), true); render(); return; }
        const bySlot = {};
        plan.slots.forEach(x => { bySlot[x.slot] = x; });
        const cells = [];
        for (let slot = 1; slot <= plan.places; slot++) {
            const x = bySlot[slot];
            cells.push(x ? `<button type="button" class="driver-slot is-full ${x.loaded ? 'is-loaded' : ''}" style="--c:${stopColor(x.stop)}" data-slot="${slot}" data-loaded="${x.loaded ? 1 : 0}"
                aria-label="${esc(t('slot_label', {slot, stop: x.stop || '?'}))}"><b>${x.loaded ? '✓ ' : ''}${x.pallet_id ? 'P' + x.pallet_id : (x.stop || '•')}</b></button>` : '<span class="driver-slot"></span>');
        }
        const stops = plan.stops.map(st => `<li style="--c:${stopColor(st.stop)}"><span class="driver-n">${st.stop || '?'}</span><div class="driver-load-info"><b>${esc(st.name)}</b>
            <small class="kiosk-muted">${esc(st.town || '')} · ${esc(t('pallets_n', {n: st.slots.length}))} · ${esc(st.stop === 1 ? t('near_door') : (st.stop === plan.stops.length ? t('near_cab') : t('middle')))}</small>
            <div class="driver-load-pids">${st.slots.map(x => `<button type="button" class="driver-pid ${x.loaded ? 'is-loaded' : ''}" data-slot="${x.slot}" data-loaded="${x.loaded ? 1 : 0}">${x.loaded ? '✓ ' : ''}${x.pallet_id ? 'P' + x.pallet_id : esc(t('planned_pallet'))} · ${esc(t('slot_short', {slot: x.slot}))}</button>`).join('')}</div></div>
            <span class="driver-state ${st.slots.every(x => x.loaded) ? 'is-ok' : (st.slots.some(x => x.loaded) ? 'is-arrived' : '')}">${esc(t('loaded_n', {loaded: st.slots.filter(x => x.loaded).length, total: st.slots.length}))}</span></li>`).join('');
        el.innerHTML = header() + `<section class="driver-choose"><button type="button" class="kiosk-chip" data-back>‹ ${esc(t('back_route'))}</button>
                <span class="driver-state ${plan.loaded === plan.total ? 'is-ok' : 'is-arrived'}">${esc(t('loaded_n', {loaded: plan.loaded, total: plan.total}))}</span>
                <span class="kiosk-muted">${esc(t('plan_version', {version: plan.version, time: hhmm(plan.sent_at)}))}</span></section>
            <div class="driver-load"><section class="kiosk-card driver-truck"><div class="driver-cab">${esc(t('cab'))}</div>
                <div class="driver-floor" style="grid-template-columns:repeat(${plan.columns}, 1fr)">${cells.join('')}</div><div class="driver-cab">${esc(t('door'))}</div></section>
                <section class="kiosk-card"><h2>${esc(t('unload_order'))}</h2><ol class="driver-load-stops">${stops}</ol><p class="kiosk-muted">${esc(t('loading_help'))}</p></section></div>`;
        el.querySelector('[data-back]').addEventListener('click', () => render());
        el.querySelectorAll('[data-slot]').forEach(b => b.addEventListener('click', async () => {
            b.disabled = true;
            try {
                const r = await api('POST', '/driver/loading/checks', {date, vehicle_id: vehicle, slot: Number(b.dataset.slot), loaded: b.dataset.loaded !== '1'});
                if (data) data.loading = {version: r.plan.version, sent_at: r.plan.sent_at, loaded: r.plan.loaded, total: r.plan.total};
                renderLoading(r.plan);
            } catch (err) { b.disabled = false; toast(err.message, true); }
        }));
    }

    /** PDF z API (JSON base64) : otwarcie w nowej karcie. */
    async function openFile(path, button) {
        if (button) button.disabled = true;
        try {
            const f = await api('GET', path);
            const bytes = Uint8Array.from(atob(f.content_base64), c => c.charCodeAt(0));
            const url = URL.createObjectURL(new Blob([bytes], {type: 'application/pdf'}));
            const a = document.createElement('a');
            a.href = url; a.target = '_blank'; a.rel = 'noopener'; a.download = f.filename;
            document.body.appendChild(a); a.click(); a.remove();
            window.setTimeout(() => URL.revokeObjectURL(url), 60000);
        } catch (err) { toast(err.message, true); }
        if (button) button.disabled = false;
    }

    // --- Instrukcje dostawy klienta ------------------------------------------------------------------------
    /** Procedura rozładunku, zamrażarka, kontakt widoczne ; alarm i klucz dopiero po dotknięciu (60 s). */
    function instructionsBlock(s) {
        if (!s.instructions || !s.instructions.length) return '';
        return `<details class="driver-info" ${s.status === 'delivered' ? '' : 'open'}><summary>🔑 ${esc(t('instructions'))}</summary><dl>
            ${s.instructions.map(x => `<dt>${esc(x.label)}</dt><dd>${x.secret
                ? `<button type="button" class="kiosk-btn is-small" data-reveal="${esc(x.key)}">${esc(t('show_secret'))}</button><span class="driver-secret" data-secret="${esc(x.key)}" hidden>${esc(x.value).replace(/\n/g, '<br>')}</span>`
                : esc(x.value).replace(/\n/g, '<br>')}</dd>`).join('')}</dl></details>`;
    }

    function bindReveal(root) {
        root.querySelectorAll('[data-reveal]').forEach(b => b.addEventListener('click', () => {
            const secret = root.querySelector(`[data-secret="${b.dataset.reveal}"]`);
            if (!secret) return;
            secret.hidden = false; b.hidden = true;
            window.setTimeout(() => { secret.hidden = true; b.hidden = false; }, 60000);
        }));
    }

    // --- Zdjęcia przed / po rozładunku ----------------------------------------------------------------------
    /** Aparat tabletu ; null, gdy kierowca zrezygnował. Wywołanie w obsłudze kliknięcia (wymóg przeglądarki). */
    function pickPhoto() {
        return new Promise(resolve => {
            const input = document.createElement('input');
            input.type = 'file'; input.accept = 'image/*'; input.setAttribute('capture', 'environment'); input.hidden = true;
            document.body.appendChild(input);
            const done = (file) => { input.remove(); resolve(file); };
            input.addEventListener('change', () => done(input.files[0] || null), {once: true});
            input.addEventListener('cancel', () => done(null), {once: true});
            input.click();
        });
    }

    /** Zdjęcie zmniejszone do 1280 px (JPEG) z datą, godziną i klientem wpisanymi na dole zdjęcia. */
    async function stampPhoto(file, caption) {
        const taken = file.lastModified && Math.abs(Date.now() - file.lastModified) < 6 * 3600 * 1000 ? new Date(file.lastModified) : new Date();
        const bitmap = await createImageBitmap(file);
        return stampImage(bitmap, bitmap.width, bitmap.height, taken, caption);
    }

    /** [310] Klatka z aparatu skanera (zdjęcie palety bez otwierania aparatu drugi raz). */
    function stampVideo(video, caption) {
        return stampImage(video, video.videoWidth, video.videoHeight, new Date(), caption);
    }

    function stampImage(source, width, height, taken, caption) {
        const scale = Math.min(1, 1280 / Math.max(width, height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(width * scale);
        canvas.height = Math.round(height * scale);
        const ctx = canvas.getContext('2d');
        ctx.drawImage(source, 0, 0, canvas.width, canvas.height);
        const band = Math.max(26, Math.round(canvas.height * 0.06));
        ctx.fillStyle = 'rgba(0, 0, 0, 0.6)';
        ctx.fillRect(0, canvas.height - band, canvas.width, band);
        ctx.fillStyle = '#fff';
        ctx.font = `600 ${Math.round(band * 0.5)}px system-ui, sans-serif`;
        ctx.textBaseline = 'middle';
        ctx.fillText(`${stampText(taken)} · ${caption}`, Math.round(band * 0.4), canvas.height - band / 2, canvas.width - band);
        return {photo: canvas.toDataURL('image/jpeg', 0.72), taken_at: localIso(taken)};
    }

    /** Zdjęcie przed albo po rozładunku : od razu na serwer (przed = przyjazd), bez sieci do kolejki. */
    async function takePhoto(s, kind) {
        if (photoCount(s, kind) >= (data.max_photos || 6)) { toast(t('photo_limit', {max: data.max_photos || 6}), true); return false; }
        const file = await pickPhoto();
        if (!file) return false;
        let shot;
        try { shot = await stampPhoto(file, s.name + ' · ' + t('stamp_' + kind)); } catch (e) { toast(t('photo_error'), true); return false; }
        const pos = await position();
        const body = Object.assign({date, customer_id: s.customer_id, vehicle_id: vehicle, kind, client_uuid: uuid()}, shot, pos || {});
        try {
            const r = await api('POST', PHOTO_PATH, body);
            s.photos = s.photos || {};
            (s.photos[kind] = s.photos[kind] || []).push({id: r.id, taken_at: r.taken_at});
            if (kind !== 'after' && !s.status) { s.status = 'arrived'; s.arrived_at = r.taken_at; }
            toast(t('photo_saved', {time: hhmm(r.taken_at)}));
            return true;
        } catch (err) {
            if (err.offline) {
                queue.push({path: PHOTO_PATH, body});
                if (!saveQueue()) { queue.pop(); toast(t('storage_full'), true); return false; }
                toast(t('photo_queued'));
                return true;
            }
            toast(err.message, true);
            return false;
        }
    }

    // --- Dostawa / problem ------------------------------------------------------------------------------
    // --- Kod QR trasy (ERP › Logistyka › Dostawy) : …/driver?date=RRRR-MM-DD&vehicle=ID ------------------------
    function applyTourCode(text) {
        let u;
        try { u = new URL(text, location.href); } catch (e) { return false; }
        const v = Number(u.searchParams.get('vehicle'));
        const d = u.searchParams.get('date') || '';
        if (!/\/driver\/?$/.test(u.pathname) || !v) return false;
        vehicle = v;
        store.set(VEHICLE_KEY, String(v));
        if (/^\d{4}-\d{2}-\d{2}$/.test(d)) date = d;
        return true;
    }

    /** Odczyt QR z wideo : BarcodeDetector (Android, Chrome), inaczej jsQR (iPad, Safari). */
    async function qrDecoder() {
        if ('BarcodeDetector' in window) {
            try {
                if ((await window.BarcodeDetector.getSupportedFormats()).includes('qr_code')) {
                    const detector = new window.BarcodeDetector({formats: ['qr_code']});
                    return async (video) => { const r = await detector.detect(video); return r.length ? r[0].rawValue : null; };
                }
            } catch (e) { /* jsQR poniżej */ }
        }
        if (!window.jsQR) {
            await new Promise((resolve, reject) => {
                const sc = document.createElement('script');
                sc.src = 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js';
                sc.onload = resolve; sc.onerror = reject;
                document.head.appendChild(sc);
            });
        }
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d', {willReadFrequently: true});
        return async (video) => {
            if (!video.videoWidth) return null;
            const scale = Math.min(1, 800 / video.videoWidth);
            canvas.width = Math.round(video.videoWidth * scale);
            canvas.height = Math.round(video.videoHeight * scale);
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            const img = ctx.getImageData(0, 0, canvas.width, canvas.height);
            const r = window.jsQR(img.data, img.width, img.height, {inversionAttempts: 'dontInvert'});
            return r ? r.data : null;
        };
    }

    /** Skan QR trasy ; onDone (ekran logowania) zamiast wczytania dnia. */
    async function openTourScan(onDone) {
        const box = sheet(t('scan_tour'), `<div class="driver-scan"><video data-video autoplay playsinline muted></video></div>
            <p class="kiosk-muted" data-scan-msg>${esc(t('scan_tour_help'))}</p>
            <div class="kiosk-row"><button type="button" class="kiosk-btn" data-close>${esc(t('cancel'))}</button></div>`);
        const msg = box.querySelector('[data-scan-msg]');
        let stream = null;
        let timer = null;
        let done = false;
        const stop = () => { done = true; window.clearTimeout(timer); if (stream) stream.getTracks().forEach(tr => tr.stop()); };
        // Zamknięcie arkusza (Anuluj, tło) : kamera wyłączona.
        new MutationObserver((_, obs) => { if (!document.body.contains(box)) { stop(); obs.disconnect(); } }).observe(document.body, {childList: true});
        if (!(window.isSecureContext && navigator.mediaDevices && navigator.mediaDevices.getUserMedia)) { msg.textContent = t('scan_unavailable'); return; }
        let decode;
        try {
            stream = await navigator.mediaDevices.getUserMedia({video: {facingMode: {ideal: 'environment'}}, audio: false});
            decode = await qrDecoder();
        } catch (e) { stop(); msg.textContent = t('scan_unavailable'); return; }
        if (done) { stop(); return; }
        const video = box.querySelector('[data-video]');
        video.srcObject = stream;
        const tick = async () => {
            if (done) return;
            let text = null;
            try { text = await decode(video); } catch (e) { text = null; }
            if (text) {
                if (applyTourCode(text)) { stop(); box.remove(); toast(t('tour_opened')); if (onDone) onDone(); else load(); return; }
                msg.textContent = t('scan_tour_wrong');
            }
            timer = window.setTimeout(tick, 350);
        };
        tick();
    }

    function sheet(title, inner) {
        const box = document.createElement('div');
        box.className = 'driver-sheet';
        box.innerHTML = `<div class="driver-sheet-panel kiosk-card" role="dialog" aria-modal="true" aria-label="${esc(title)}"><h2>${esc(title)}</h2>${inner}</div>`;
        document.body.appendChild(box);
        box.addEventListener('click', (e) => { if (e.target === box || e.target.closest('[data-close]')) box.remove(); });
        return box;
    }

    function photoField() {
        return `<label class="driver-photo"><span>${esc(t('photo'))}</span><input type="file" accept="image/*" capture="environment" data-photo><img data-photo-preview alt="" hidden></label>`;
    }

    /** Zdjęcie zmniejszone do 1280 px (JPEG) : mały plik także przy słabym zasięgu. */
    async function readPhoto(box) {
        const input = box.querySelector('[data-photo]');
        const file = input && input.files[0];
        if (!file) return null;
        const bitmap = await createImageBitmap(file);
        const scale = Math.min(1, 1280 / Math.max(bitmap.width, bitmap.height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(bitmap.width * scale);
        canvas.height = Math.round(bitmap.height * scale);
        canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        return canvas.toDataURL('image/jpeg', 0.72);
    }

    function bindPhoto(box) {
        const input = box.querySelector('[data-photo]');
        input.addEventListener('change', () => {
            const img = box.querySelector('[data-photo-preview]');
            if (input.files[0]) { img.src = URL.createObjectURL(input.files[0]); img.hidden = false; } else img.hidden = true;
        });
    }

    function signaturePad(box) {
        const canvas = box.querySelector('[data-pad]');
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.lineWidth = 3; ctx.lineCap = 'round'; ctx.strokeStyle = '#111';
        let drawing = false;
        const pad = {strokes: 0, canvas};
        const point = (e) => { const r = canvas.getBoundingClientRect(); return [(e.clientX - r.left) * canvas.width / r.width, (e.clientY - r.top) * canvas.height / r.height]; };
        canvas.addEventListener('pointerdown', (e) => { drawing = true; pad.strokes++; canvas.setPointerCapture(e.pointerId); const [x, y] = point(e); ctx.beginPath(); ctx.moveTo(x, y); });
        canvas.addEventListener('pointermove', (e) => { if (!drawing) return; const [x, y] = point(e); ctx.lineTo(x, y); ctx.stroke(); });
        canvas.addEventListener('pointerup', () => { drawing = false; });
        box.querySelector('[data-clear]').addEventListener('click', () => { ctx.fillRect(0, 0, canvas.width, canvas.height); pad.strokes = 0; });
        return pad;
    }

    /**
     * [310] Odbiór : podpis na tablecie, podpis w polu 24 CMR z fakturowania (PDF), albo klient nieobecny (bez podpisu,
     * zdjęcie po dostawie obowiązkowe). Niezeskanowane palety : uzasadnienie obowiązkowe.
     */
    function openDeliver(s) {
        const cmr = (s.documents || []).find(d => d.type === 'CMR');
        let mode = s.receipt_mode && (s.receipt_mode !== 'cmr' || cmr) ? s.receipt_mode : 'tablet';
        const nums = s.pallet_numbers || [];
        const scanned = scannedOf(s);
        const missing = nums.filter(n => !scanned.has(Number(n)));
        const box = sheet(t('deliver_title', {name: s.name}), `<div class="driver-modes" role="tablist">
                ${['tablet', 'cmr', 'absent'].map(m => `<button type="button" role="tab" class="driver-mode" data-mode="${m}" ${m === 'cmr' && !cmr ? 'disabled title="' + esc(t('mode_cmr_none')) + '"' : ''}>${esc(t('mode_' + m))}</button>`).join('')}</div>
            <p class="driver-mode-help" data-mode-help></p>
            <form class="kiosk-form driver-form" data-form novalidate>
            <div class="driver-pallets"><label data-person>${esc(t('receiver'))}<input name="receiver_name" maxlength="100" autocomplete="off" value="${esc(s.receiver_name || '')}"></label>
                <label>${esc(t('pallets_delivered'))}<input name="pallets_delivered" type="number" min="0" max="99" inputmode="numeric" value="${s.pallets_delivered ?? (scanned.size || s.pallets || 0)}"></label>
                <label>${esc(t('pallets_returned'))}<input name="pallets_returned" type="number" min="0" max="99" inputmode="numeric" value="${s.pallets_returned ?? 0}"></label></div>
            ${missing.length ? `<div class="driver-missing"><p>⚠ ${esc(t('pallets_missing', {n: missing.length, list: missing.join(', ')}))}</p>
                <label>${esc(t('pallets_note'))}<input name="pallets_note" maxlength="255" autocomplete="off" value="${esc(s.pallets_note || '')}"></label>
                <button type="button" class="kiosk-btn is-small" data-open-pallets>📦 ${esc(t('unload_pallets'))}</button></div>` : ''}
            <div class="driver-checklist" data-checklist>${checklist(s)}</div>
            <div data-sign><span class="driver-label" data-sign-label>${esc(t('signature'))}</span><canvas class="kiosk-sign driver-sign" data-pad width="900" height="300" aria-label="${esc(t('signature'))}"></canvas>
                <button type="button" class="kiosk-btn is-small" data-clear>${esc(t('clear'))}</button></div>
            <label>${esc(t('note'))}<input name="note" maxlength="500" autocomplete="off"></label>
            <p class="kiosk-error" data-error></p>
            <div class="kiosk-row is-end"><button type="button" class="kiosk-btn" data-close>${esc(t('cancel'))}</button><button class="kiosk-btn is-ok" data-confirm>${esc(t('confirm_delivery'))}</button></div></form>`);
        const pad = signaturePad(box);
        const setMode = (m) => {
            mode = m;
            box.querySelectorAll('[data-mode]').forEach(b => { b.classList.toggle('is-active', b.dataset.mode === m); b.setAttribute('aria-selected', b.dataset.mode === m ? 'true' : 'false'); });
            box.querySelector('[data-mode-help]').textContent = m === 'cmr' ? t('mode_cmr_help', {num: cmr ? cmr.num : ''}) : t('mode_' + m + '_help');
            box.querySelector('[data-sign]').hidden = m === 'absent';
            box.querySelector('[data-person]').hidden = m === 'absent';
            box.querySelector('[data-sign-label]').textContent = t(m === 'cmr' ? 'signature_cmr' : 'signature');
            box.querySelector('[data-confirm]').textContent = t(m === 'cmr' ? 'confirm_cmr' : m === 'absent' ? 'confirm_absent' : 'confirm_delivery');
            box.querySelector('[data-error]').textContent = '';
        };
        box.querySelectorAll('[data-mode]').forEach(b => b.addEventListener('click', () => setMode(b.dataset.mode)));
        box.querySelector('[data-form]').addEventListener('input', () => { box.querySelector('[data-error]').textContent = ''; });
        setMode(mode);
        const reopen = box.querySelector('[data-open-pallets]');
        if (reopen) reopen.addEventListener('click', () => { box.remove(); openPallets(s); });
        const bindChecklist = () => box.querySelectorAll('[data-sheet-photo]').forEach(b => b.addEventListener('click', () => takePhoto(s, b.dataset.sheetPhoto).then(ok => {
            if (!ok) return;
            box.querySelector('[data-checklist]').innerHTML = checklist(s);
            bindChecklist();
        })));
        bindChecklist();
        box.querySelector('[data-form]').addEventListener('submit', async (e) => {
            e.preventDefault();
            const f = e.target;
            const err = box.querySelector('[data-error]');
            const note = f.pallets_note ? f.pallets_note.value.trim() : '';
            if (missing.length && !note) { err.textContent = t('pallets_note_required'); f.pallets_note.focus(); return; }
            if (mode === 'absent' && !photoCount(s, 'after')) { err.textContent = t('absent_photo_required'); return; }
            if (mode !== 'absent') {
                if (!f.receiver_name.value.trim()) { err.textContent = t('receiver_required'); f.receiver_name.focus(); return; }
                if (!pad.strokes) { err.textContent = t('sign_first'); return; }
                if ((!photoCount(s, 'before') || !photoCount(s, 'after')) && !window.confirm(t('no_photos_confirm'))) return;
            }
            f.querySelector('[data-confirm]').disabled = true;
            const body = {status: 'delivered', receipt_mode: mode, receiver_name: mode === 'absent' ? null : f.receiver_name.value.trim(),
                pallets_delivered: Number(f.pallets_delivered.value) || 0, pallets_returned: Number(f.pallets_returned.value) || 0,
                pallets_note: note || null, note: f.note.value.trim() || null, signature: mode === 'absent' ? null : pad.canvas.toDataURL('image/png')};
            await submit(s, body, box);
        });
    }

    /** Lista kontrolna dostawy : zdjęcia przed i po rozładunku (liczba, dodaj kolejne). */
    function checklist(s) {
        return ['before', 'after'].map(kind => {
            const n = photoCount(s, kind);
            return `<div class="driver-check ${n ? 'is-done' : ''}"><span>${n ? '✓' : '○'} ${esc(t(kind === 'before' ? 'photos_before' : 'photos_after'))}${n ? ' · ' + n : ''}</span>
                <button type="button" class="kiosk-btn is-small" data-sheet-photo="${kind}">📷 ${esc(t('add_photo'))}</button></div>`;
        }).join('');
    }

    function openProblem(s) {
        const box = sheet(t('problem_title', {name: s.name}), `<form class="kiosk-form driver-form" data-form>
            <div class="driver-reasons">${data.reasons.map(r => `<label class="driver-reason"><input type="radio" name="reason" value="${r.code}" required> <span>${esc(r.label)}</span></label>`).join('')}</div>
            <label>${esc(t('note'))}<input name="note" maxlength="500" autocomplete="off"></label>
            ${photoField()}
            <p class="kiosk-error" data-error></p>
            <div class="kiosk-row is-end"><button type="button" class="kiosk-btn" data-close>${esc(t('cancel'))}</button><button class="kiosk-btn is-danger">${esc(t('report_problem'))}</button></div></form>`);
        bindPhoto(box);
        box.querySelector('[data-form]').addEventListener('submit', async (e) => {
            e.preventDefault();
            const f = e.target;
            f.querySelector('.is-danger').disabled = true;
            await submit(s, {status: 'problem', reason: f.reason.value, note: f.note.value.trim() || null}, box);
        });
    }

    // --- [310] Rozładunek palet : skan QR etykiety (TFBPAL:numer) + zdjęcie każdej palety -------------------------------
    /** Numer palety z kodu QR etykiety albo z cyfr. */
    const palletFromCode = (text) => {
        const m = String(text || '').trim().match(/^TFBPAL:(\d{1,9})$/i) || String(text || '').trim().match(/^(\d{1,9})$/);
        return m ? Number(m[1]) : null;
    };

    async function openPallets(s) {
        const nums = (s.pallet_numbers || []).map(Number);
        const box = sheet(t('pallets_title', {name: s.name}), `<p class="kiosk-muted" data-count></p>
            <div class="driver-scan is-pallet"><video data-video autoplay playsinline muted></video><span class="driver-scan-frame" aria-hidden="true"></span></div>
            <p class="kiosk-muted" data-scan-msg>${esc(t('pallet_scan_help'))}</p>
            <div class="driver-pallet-alert" data-alert role="alert" hidden></div>
            <div class="driver-pallet-found" data-found hidden></div>
            <div class="driver-pallet-grid" data-grid></div>
            <div class="driver-manual"><input type="number" inputmode="numeric" min="1" data-manual placeholder="${esc(t('pallet_number'))}" aria-label="${esc(t('pallet_number'))}">
                <button type="button" class="kiosk-btn" data-manual-ok>⌨ ${esc(t('pallet_manual'))}</button></div>
            <div class="kiosk-row is-end"><button type="button" class="kiosk-btn is-warn-soft" data-missing>${esc(t('pallet_missing_btn'))}</button>
                <button type="button" class="kiosk-btn is-primary" data-close>${esc(t('done'))}</button></div>`);
        const video = box.querySelector('[data-video]');
        const msg = box.querySelector('[data-scan-msg]');
        const alertBox = box.querySelector('[data-alert]');
        const foundBox = box.querySelector('[data-found]');
        let stream = null;
        let timer = null;
        let closed = false;
        let pending = null;
        let last = {text: '', at: 0};
        const stopCam = () => { closed = true; window.clearTimeout(timer); if (stream) stream.getTracks().forEach(tr => tr.stop()); };
        new MutationObserver((_, obs) => { if (!document.body.contains(box)) { stopCam(); obs.disconnect(); render(); } }).observe(document.body, {childList: true});
        const scanTime = (no) => {
            const x = (s.pallet_scans || []).find(y => Number(y.pallet_no) === no);
            if (x) return hhmm(x.scanned_at);
            return queue.some(q => q.path === PALLET_PATH && q.body.customer_id === s.customer_id && q.body.date === date && Number(q.body.pallet_no) === no) ? t('queued_short') : null;
        };
        const draw = () => {
            const scanned = scannedOf(s);
            box.querySelector('[data-count]').textContent = t('pallets_count', {n: scanned.size, total: nums.length});
            box.querySelector('[data-grid]').innerHTML = nums.map(no => {
                const time = scanTime(no);
                const thumb = thumbs[`${date}:${s.customer_id}:${no}`];
                return `<div class="driver-ptile ${time ? 'is-done' : ''} ${pending && pending.no === no ? 'is-pending' : ''}"><b>${no}</b>
                    ${thumb ? `<img src="${thumb}" alt="">` : '<span class="driver-ptile-ph"></span>'}<small>${time ? '✓ ' + esc(time) : esc(t('to_scan'))}</small></div>`;
            }).join('');
        };
        const showAlert = (text) => { alertBox.textContent = '✕ ' + text; alertBox.hidden = !text; };
        const candidate = (no, method) => {
            showAlert('');
            if (!no) { showAlert(t('pallet_not_label')); return; }
            if (!nums.includes(no)) {
                const other = data.stops.find(x => (x.pallet_numbers || []).map(Number).includes(no));
                showAlert(other ? t('pallet_other', {pallet: no, name: other.name, n: other.n}) : t('pallet_unknown', {pallet: no}));
                return;
            }
            if (scannedOf(s).has(no)) { msg.textContent = t('pallet_already', {pallet: no}); return; }
            pending = {no, method};
            foundBox.hidden = false;
            foundBox.innerHTML = `<span>✓ ${esc(t('pallet_ok', {pallet: no}))}</span>
                <button type="button" class="kiosk-btn is-ok" data-shot>📷 ${esc(t('pallet_photo', {pallet: no}))}</button>
                <button type="button" class="kiosk-btn is-small" data-shot-cancel>${esc(t('cancel'))}</button>`;
            foundBox.querySelector('[data-shot]').addEventListener('click', (e) => shoot(e.currentTarget));
            foundBox.querySelector('[data-shot-cancel]').addEventListener('click', () => { pending = null; foundBox.hidden = true; draw(); });
            draw();
        };
        const shoot = async (btn) => {
            if (!pending) return;
            btn.disabled = true;
            const {no, method} = pending;
            const caption = `${s.name} · ${t('stamp_pallet', {pallet: no})}`;
            let shot = null;
            try {
                if (stream && video.videoWidth) shot = stampVideo(video, caption);
                else { const file = await pickPhoto(); if (file) shot = await stampPhoto(file, caption); }
            } catch (e) { shot = null; }
            if (!shot) { btn.disabled = false; toast(t('photo_error'), true); return; }
            const pos = await position();
            const body = Object.assign({date, customer_id: s.customer_id, vehicle_id: vehicle, pallet_no: no, method, client_uuid: uuid()}, shot, pos || {});
            try {
                const r = await api('POST', PALLET_PATH, body);
                s.pallet_scans = r.pallet_scans;
                if (!s.status) s.status = 'arrived';
                toast(t('pallet_saved', {pallet: no, n: r.scanned, total: r.total}));
            } catch (err) {
                if (!err.offline) { btn.disabled = false; showAlert(err.message); pending = null; foundBox.hidden = true; draw(); return; }
                queue.push({path: PALLET_PATH, body});
                if (!saveQueue()) { queue.pop(); btn.disabled = false; toast(t('storage_full'), true); return; }
                toast(t('photo_queued'));
            }
            thumbs[`${date}:${s.customer_id}:${no}`] = shot.photo;
            pending = null;
            foundBox.hidden = true;
            draw();
            if (scannedOf(s).size >= nums.length) msg.textContent = t('pallets_all');
        };
        box.querySelector('[data-manual-ok]').addEventListener('click', () => {
            const input = box.querySelector('[data-manual]');
            candidate(Number(input.value) || null, 'manual');
            input.value = '';
        });
        box.querySelector('[data-missing]').addEventListener('click', () => {
            const left = nums.filter(n => !scannedOf(s).has(n));
            box.remove();
            openIncident(s, 'missing_goods', left.length ? t('pallet_missing_note', {list: left.join(', ')}) : '');
        });
        draw();
        if (!(window.isSecureContext && navigator.mediaDevices && navigator.mediaDevices.getUserMedia)) { msg.textContent = t('scan_unavailable_manual'); video.parentElement.hidden = true; return; }
        let decode;
        try {
            stream = await navigator.mediaDevices.getUserMedia({video: {facingMode: {ideal: 'environment'}}, audio: false});
            decode = await qrDecoder();
        } catch (e) { if (stream) stream.getTracks().forEach(tr => tr.stop()); stream = null; msg.textContent = t('scan_unavailable_manual'); video.parentElement.hidden = true; return; }
        if (closed) { stopCam(); return; }
        video.srcObject = stream;
        const tick = async () => {
            if (closed) return;
            if (!pending) {
                let text = null;
                try { text = await decode(video); } catch (e) { text = null; }
                // Ten sam kod przed aparatem : jeden komunikat na 3 s.
                if (text && (text !== last.text || Date.now() - last.at > 3000)) { last = {text, at: Date.now()}; candidate(palletFromCode(text), 'qr'); }
            }
            timer = window.setTimeout(tick, 350);
        };
        tick();
    }

    // --- [310] « Coś nie tak » : zdjęcie (obowiązkowe) + rodzaj + opis ; nie blokuje dostawy ------------------------------
    function openIncident(s, kind, note) {
        const kinds = data.incident_kinds || [];
        const box = sheet(t('incident_title', {name: s.name}), `<form class="kiosk-form driver-form" data-form>
            <label class="driver-photo"><span>${esc(t('incident_photo'))}</span><input type="file" accept="image/*" capture="environment" data-photo><img data-photo-preview alt="" hidden></label>
            <span class="driver-label">${esc(t('incident_kind'))}</span>
            <div class="driver-kinds">${kinds.map((k, i) => `<label class="driver-kind"><input type="radio" name="kind" value="${esc(k.code)}" ${(kind ? k.code === kind : i === 0) ? 'checked' : ''}> <span>${esc(k.label)}</span></label>`).join('')}</div>
            <label>${esc(t('incident_note'))}<input name="note" maxlength="500" autocomplete="off" value="${esc(note || '')}"></label>
            <p class="kiosk-muted">${esc(t('incident_info'))}</p>
            <p class="kiosk-error" data-error></p>
            <div class="kiosk-row is-end"><button type="button" class="kiosk-btn" data-close>${esc(t('cancel'))}</button><button class="kiosk-btn is-warn" data-confirm>${esc(t('incident_save'))}</button></div></form>`);
        bindPhoto(box);
        box.querySelector('[data-form]').addEventListener('submit', async (e) => {
            e.preventDefault();
            const f = e.target;
            const err = box.querySelector('[data-error]');
            const file = f.querySelector('[data-photo]').files[0];
            if (!file) { err.textContent = t('incident_photo_required'); return; }
            const btn = f.querySelector('[data-confirm]');
            btn.disabled = true;
            const label = (kinds.find(k => k.code === f.kind.value) || {}).label || f.kind.value;
            let shot;
            try { shot = await stampPhoto(file, `${s.name} · ${label}`); } catch (x) { btn.disabled = false; err.textContent = t('photo_error'); return; }
            const pos = await position();
            const body = Object.assign({date, customer_id: s.customer_id, vehicle_id: vehicle, kind: f.kind.value, note: f.note.value.trim() || null, client_uuid: uuid()}, shot, pos || {});
            try {
                await api('POST', INCIDENT_PATH, body);
                (s.incidents = s.incidents || []).push({kind: body.kind, label, note: body.note});
                if (!s.status) s.status = 'arrived';
                toast(t('incident_saved'));
            } catch (x) {
                if (!x.offline) { btn.disabled = false; err.textContent = x.message; return; }
                queue.push({path: INCIDENT_PATH, body});
                if (!saveQueue()) { queue.pop(); btn.disabled = false; toast(t('storage_full'), true); return; }
                toast(t('photo_queued'));
            }
            box.remove();
            render();
        });
    }

    /** Położenie (opcjonalne) : najwyżej 4 s, bez blokowania zapisu. */
    function position() {
        return new Promise(resolve => {
            if (!navigator.geolocation) return resolve(null);
            const timer = window.setTimeout(() => resolve(null), 4000);
            navigator.geolocation.getCurrentPosition(p => { window.clearTimeout(timer); resolve({lat: Math.round(p.coords.latitude * 1e6) / 1e6, lng: Math.round(p.coords.longitude * 1e6) / 1e6}); },
                () => { window.clearTimeout(timer); resolve(null); }, {timeout: 4000, maximumAge: 120000});
        });
    }

    async function submit(s, fields, box) {
        let photo = null;
        try { photo = await readPhoto(box); } catch (e) { photo = null; }
        const pos = await position();
        const body = Object.assign({date, customer_id: s.customer_id, vehicle_id: vehicle, recorded_at: nowLocal(), client_uuid: uuid(), photo}, fields, pos || {});
        try {
            await api('POST', STOP_PATH, body);
            box.remove();
            toast(t(fields.status === 'delivered' ? 'saved_delivery' : 'saved_problem'));
            // [310] Po ostatnim punkcie : podsumowanie trasy (D) ; inaczej następny punkt do dostarczenia.
            selected = null;
            await load();
            if (data && data.stops.length && data.stops.every(isDone) && !(data.run || {}).finished_at) openSummary();
        } catch (err) {
            if (err.offline) {
                // Bez sieci : zapis w kolejce, wysyłka sama, gdy sieć wróci.
                queue = queue.filter(q => !((q.path || STOP_PATH) === STOP_PATH && q.body.customer_id === body.customer_id && q.body.date === body.date));
                queue.push({path: STOP_PATH, body});
                if (!saveQueue()) { queue.pop(); toast(t('storage_full'), true); return; }
                box.remove();
                toast(t('saved_offline'));
                selected = null;
                render();
                if (data.stops.every(isDone)) openSummary();
                return;
            }
            const btn = box.querySelector('[data-confirm], .is-ok, .is-danger');
            if (btn) btn.disabled = false;
            box.querySelector('[data-error]').textContent = err.message;
        }
    }

    async function flush() {
        if (flushing || !queue.length || !paired || !session) return;
        flushing = true;
        let sent = 0;
        try {
            while (queue.length) {
                try {
                    await api('POST', queue[0].path || STOP_PATH, queue[0].body);
                    sent++;
                } catch (err) {
                    // Brak sieci, błąd serwera albo tablet do ponownego sparowania : zapis zostaje w kolejce.
                    if (err.offline || err.status >= 500 || err.status === 401 || err.status === 403) break;
                    toast(t('queue_rejected', {message: err.message}), true);
                }
                queue.shift();
                saveQueue();
            }
        } finally {
            flushing = false;
        }
        if (sent) { toast(t('queue_sent', {n: sent})); load(); } else if (data) render();
    }

    el.addEventListener('click', async (e) => {
        if (e.target.closest('[data-lang]')) {
            lang = lang === 'pl' ? 'fr' : 'pl';
            store.set(LANG_KEY, lang);
            await loadLabels();
            paired ? load() : renderPairing();
        }
        if (e.target.closest('[data-refresh]')) { flush(); load(); }
        if (e.target.closest('[data-logout]')) {
            if (queue.length && !window.confirm(t('logout_queue', {n: queue.length}))) return;
            setSession(null);
            renderLogin();
        }
    });
    window.addEventListener('online', flush);
    window.setInterval(flush, 30000);
    window.setInterval(() => { if (!document.querySelector('.driver-sheet') && paired && session && navigator.onLine) load(); }, 300000);
    // Koniec sesji (trasa skończona, kolejny dzień) : znów lista + PIN.
    window.setInterval(() => { if (session && session.exp < Date.now()) { setSession(null); renderLogin(t('session_ended')); } }, 60000);

    // Komunikaty kierownika produkcji [232] (tablet kierowcy) : nad ekranem, co minutę ; « Przeczytane » PIN-em.
    function taskMessages() {
        if (!paired || !window.tfbTaskMessages) return;
        window.tfbTaskMessages.refresh({host: document.getElementById('tfbTaskMsgs'), api: (m, path, body) => api(m, path, body), audience: 'driver', lang,
            people: () => api('GET', '/kiosk/people'), toast});
    }
    window.setInterval(() => { if (!document.hidden) taskMessages(); }, 60000);

    loadLabels().then(() => { paired ? load().then(flush).then(taskMessages) : renderPairing(); });
})();
