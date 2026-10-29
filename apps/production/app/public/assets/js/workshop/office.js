/*
 * Tablet biurowy / Tablette de bureau (PWA). Tablet rodzaju « Tablet biurowy » (zarządzanie tabletami), parowany tym samym
 * kodem co tablet pracowni ; tablet pracowni otwarty na /office też pokazuje ten ekran i zostaje na nim. Wszystkie grupy
 * i wszystkie osoby od razu na ekranie (pasek grup, wyszukiwanie) ; osoba dotyka swojego nazwiska : tablet pokazuje jej osobisty kod QR
 * (osoba + dzień). Osoba skanuje go swoim telefonem i zatwierdza wejście albo wyjście na telefonie (pytania, podpis) ;
 * telefon innej osoby jest odrzucany. Tablet sprawdza co 2 s, czy pointowanie jest zapisane, i wraca do działów.
 * Bez telefonu : PIN, potem pytania i podpis palcem na tablecie (pin_allowed ; kiosk.office_pin_all — wszyscy).
 */
(function () {
    'use strict';
    const VERSION = (document.currentScript && new URL(document.currentScript.src, location.href).searchParams.get('v')) || '';
    const el = document.getElementById('tfbOffice');
    if (!el) return;
    const base = (el.dataset.apiBase || '').replace(/\/$/, '');
    const root = (el.dataset.root || '').replace(/\/$/, '');
    // Ten sam token co tablet pracowni i kierowcy : tryb tabletu decyduje o stronie.
    const DEVICE_KEY = 'tfb.kiosk.device';
    // Tablet pracowni otwarty na /office : ekran biurowy zostaje jego stroną startową (/kiosk przekierowuje tutaj).
    const HOME_KEY = 'tfb.kiosk.home';
    const LANG_KEY = 'tfb.lang';
    const POLL_MS = 2000;
    const QR_SCREEN_MS = 180000; // ekran kodu QR : powrót do działów po 3 min
    const IDLE_MS = 60000;       // lista nazwisk : powrót po 1 min bez dotyku
    const DONE_MS = 6000;
    const store = {
        get(k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
        set(k, v) { try { v === null ? window.localStorage.removeItem(k) : window.localStorage.setItem(k, v); } catch (e) { /* stockage indisponible */ } },
    };
    let lang = store.get(LANG_KEY) === 'fr' ? 'fr' : 'pl';
    let labels = {};
    let device = store.get(DEVICE_KEY);
    let paired = !!device;
    let sections = [];
    let timers = [];
    let deviceInfo = null;
    let filter = null; // dział wybrany w pasku (null = wszyscy)

    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const t = (k, p) => { let s = labels[k] ?? k; for (const [a, b] of Object.entries(p || {})) s = s.split('{' + a + '}').join(String(b)); return s; };
    const fold = (s) => String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/ł/g, 'l');
    const initials = (name) => String(name || '?').trim().split(/\s+/).slice(0, 2).map(w => w.charAt(0)).join('').toUpperCase() || '?';
    const hue = (name) => [...String(name || '')].reduce((h, c) => (h * 31 + c.charCodeAt(0)) % 360, 7);
    const avatar = (name, cls) => `<span class="kiosk-avatar is-tone-${hue(name) % 5} ${cls || ''}" aria-hidden="true">${esc(initials(name))}</span>`;
    const longDate = (d) => d.toLocaleDateString(lang === 'fr' ? 'fr-FR' : 'pl-PL', {weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'});
    const mmss = (ms) => { const s = Math.max(0, Math.ceil(ms / 1000)); return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`; };

    let idleMs = 0;
    let idleTimer = null;

    function clearTimers() {
        timers.forEach(id => { window.clearTimeout(id); window.clearInterval(id); });
        timers = [];
        idleMs = 0;
        window.clearTimeout(idleTimer);
    }

    // Powrót do działów po chwili bez dotyku (każde dotknięcie liczy od nowa).
    function idle(ms) {
        idleMs = ms;
        window.clearTimeout(idleTimer);
        idleTimer = window.setTimeout(renderHome, ms);
    }

    async function loadLabels() {
        const res = await fetch(`${root}/resources/lang/ui/${lang}/office.json${VERSION ? '?v=' + encodeURIComponent(VERSION) : ''}`, {credentials: 'same-origin'});
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

    async function api(method, path, body, employeeToken) {
        const headers = {'Accept': 'application/json', 'Accept-Language': lang};
        if (device) headers['X-Device-Token'] = device;
        if (employeeToken) headers['X-Employee-Token'] = employeeToken;
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
        err.fields = (json.errors || []).map(x => x.field);
        if (err.code === 'DEVICE_UNAUTHENTICATED') { paired = false; clearTimers(); renderPairing(err.message); }
        // Tablet pracowni albo kierowcy : jego własna strona (/kiosk przekierowuje tablet kierowcy na /driver).
        // Tablet kierowcy : jego trasa (każdy inny tablet otwiera ekran biurowy).
        if (err.code === 'DEVICE_NOT_OFFICE') { store.set(HOME_KEY, null); location.replace(root + '/tablet/driver'); }
        throw err;
    }

    function header(lead) {
        const now = new Date();
        return `<header class="kiosk-head">${lead || ''}<div class="kiosk-brand"><span class="kiosk-logo" aria-hidden="true">LA</span><div><h1>${esc(t('title'))}</h1>
                <p class="kiosk-muted">${esc(longDate(now))}</p></div></div>
            <div class="kiosk-head-tools"><span class="kiosk-clock" data-clock>${now.toTimeString().slice(0, 5)}</span>
                <button type="button" class="kiosk-chip" data-lang>${esc(t('lang_switch'))}</button></div></header>`;
    }

    // --- Parowanie -------------------------------------------------------------------------------------
    function renderPairing(message) {
        clearTimers();
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
                try { if (navigator.storage && navigator.storage.persist) navigator.storage.persist().catch(() => {}); } catch (err) { /* non pris en charge */ }
                if (r.device.mode === 'driver') { location.replace(root + '/tablet/driver'); return; }
                deviceInfo = null;
                toast(t('paired', {name: r.device.name}));
                renderHome();
            } catch (err) { el.querySelector('[data-error]').textContent = err.message; }
        });
        el.querySelector('[name=code]').focus();
    }

    // --- Wszystkie grupy i wszystkie osoby na jednym ekranie -----------------------------------------
    const allPeople = () => sections.flatMap(s => s.people.map(p => ({...p, section: s.name, section_id: s.id})));

    async function renderHome() {
        if (!paired) return renderPairing();
        clearTimers();
        try {
            if (deviceInfo === null) deviceInfo = await api('GET', '/kiosk/device');
            // Tout le personnel : les personnes sans dział forment la section 0, nommée ici (« Bez działu » / « Sans service »).
            sections = (await api('GET', '/kiosk/office/sections')).map(x => x.name ? x : {...x, name: t('no_section')});
        } catch (err) {
            if (err.code === 'DEVICE_UNAUTHENTICATED' || err.code === 'DEVICE_NOT_OFFICE') return;
            el.innerHTML = header() + `<section class="kiosk-card"><p class="kiosk-error">${esc(err.message)}</p>
                <div class="kiosk-row"><button type="button" class="kiosk-btn is-primary" data-retry>${esc(t('retry'))}</button></div></section>`;
            el.querySelector('[data-retry]').addEventListener('click', renderHome);
            return;
        }
        if (filter !== null && !sections.some(x => x.id === filter)) filter = null;
        const people = allPeople();
        const present = people.filter(p => p.clocked_in).length;
        const now = new Date();
        const toProduction = deviceInfo && deviceInfo.mode === 'atelier'
            ? `<button type="button" class="kiosk-chip is-glass" data-to-production>${esc(t('to_production'))}</button>` : '';
        el.innerHTML = `<div class="kiosk-ipad office-home">
            <div class="kiosk-ipad-bar"><span class="kiosk-logo" aria-hidden="true">LA</span><span class="kiosk-ipad-name">${esc(t('title'))}</span>
                ${toProduction}<button type="button" class="kiosk-chip is-glass" data-lang>${esc(t('lang_switch'))}</button></div>
            <section class="kiosk-ipad-hero office-hero"><div class="kiosk-ipad-time" data-clock>${now.toTimeString().slice(0, 5)}</div>
                <div class="kiosk-ipad-date">${esc(longDate(now))}</div>
                <p class="kiosk-ipad-greet">${esc(t('hero_all'))}</p>
                <p class="office-present">${esc(t('present_total', {n: present, total: people.length}))}</p></section>
            <input class="kiosk-search office-search" type="search" data-search placeholder="${esc(t('search'))}" aria-label="${esc(t('search'))}" autocomplete="off">
            <nav class="office-groupbar" aria-label="${esc(t('groups'))}">
                <button type="button" class="office-group-chip ${filter === null ? 'is-on' : ''}" data-filter="">${esc(t('all_groups'))}<small>${present}/${people.length}</small></button>
                ${sections.map(x => `<button type="button" class="office-group-chip ${filter === x.id ? 'is-on' : ''}" data-filter="${x.id}">${esc(x.name)}<small>${x.people.filter(p => p.clocked_in).length}/${x.people.length}</small></button>`).join('')}</nav>
            <div data-home-body></div></div>`;
        const body = el.querySelector('[data-home-body]');
        const search = el.querySelector('[data-search]');
        // Grupy jedna pod drugą, każda ze wszystkimi osobami ; wyszukiwanie po nazwisku we wszystkich grupach.
        const draw = () => {
            const f = fold(search.value.trim());
            const groups = sections.filter(x => filter === null || x.id === filter)
                .map(x => ({...x, list: f ? x.people.filter(p => fold(p.name).includes(f)) : x.people}))
                .filter(x => x.list.length || (!f && filter !== null));
            body.innerHTML = groups.length ? groups.map(x => `<section class="office-group">
                    <h2 class="office-group-title">${esc(x.name)} <small>${esc(t('section_people', {n: x.people.length, present: x.people.filter(p => p.clocked_in).length}))}</small></h2>
                    ${x.list.length ? `<div class="kiosk-apps office-people">${x.list.map(personTile).join('')}</div>` : `<p class="kiosk-muted">${esc(t('nobody'))}</p>`}</section>`).join('')
                : `<p class="kiosk-muted">${esc(f ? t('no_match') : t('no_people'))}</p>`;
        };
        draw();
        body.addEventListener('click', (e) => {
            const p = e.target.closest('[data-person]');
            if (p) openPerson(Number(p.dataset.person));
        });
        el.querySelector('.office-groupbar').addEventListener('click', (e) => {
            const c = e.target.closest('[data-filter]');
            if (!c) return;
            filter = c.dataset.filter === '' ? null : Number(c.dataset.filter);
            el.querySelectorAll('[data-filter]').forEach(b => b.classList.toggle('is-on', b === c));
            draw();
            el.querySelector('.office-groupbar').scrollIntoView({block: 'start', behavior: 'smooth'});
        });
        search.addEventListener('input', draw);
        el.querySelector('[data-to-production]')?.addEventListener('click', () => { store.set(HOME_KEY, null); location.replace(root + '/tablet/production'); });
        // Stan obecności odświeżany co minutę, gdy nikt nie szuka.
        timers.push(window.setInterval(() => { if (!search.value && document.activeElement !== search) renderHome(); }, 60000));
    }

    function personTile(p) {
        const state = p.clocked_in ? t('state_in', {time: p.since}) : p.left_at ? t('state_left', {time: p.left_at}) : (!p.has_pin && !p.phones ? t('state_no_pin') : t('state_out'));
        return `<button type="button" class="kiosk-app-person ${p.clocked_in ? 'is-in' : ''} ${!p.has_pin && !p.phones ? 'is-nopin' : ''}" data-person="${p.id}">
            ${avatar(p.name, 'is-app')}<span class="kiosk-app-name">${esc(p.name)}</span><small>${esc(state)}</small></button>`;
    }

    // --- Osobisty kod QR (osoba + dzień) ---------------------------------------------------------------
    async function openPerson(personId) {
        clearTimers();
        const qrBase = new URL(root + '/', window.location.href).href.replace(/\/$/, '');
        let q;
        try {
            q = await api('POST', '/kiosk/office/qr', {person_id: personId, base: qrBase});
        } catch (err) {
            if (err.code === 'DEVICE_UNAUTHENTICATED' || err.code === 'DEVICE_NOT_OFFICE') return;
            toast(err.message, true);
            return;
        }
        const p = q.person;
        const since = q.since;
        const action = p.clocked_in ? 'out' : 'in';
        const day = new Date(q.day + 'T12:00:00');
        const help = !p.has_pin && !p.phones ? t('qr_no_pin') : (!p.phones ? t('qr_or_pin') : t('qr_help'));
        // Bez telefonu : PIN i podpis na tablecie (osoba z telefonem — tylko gdy kiosk.office_pin_all).
        const pinButton = p.pin_allowed ? `<button type="button" class="kiosk-btn ${p.phones ? '' : 'is-primary'} office-pin-go" data-pin-go>${esc(t(p.phones ? 'pin_instead' : 'pin_no_phone'))}</button>` : '';
        el.innerHTML = header(`<button type="button" class="kiosk-btn is-small" data-home>‹ ${esc(t('back'))}</button>`) + `<section class="kiosk-card office-qr">
                <div class="office-qr-who">${avatar(p.name, 'is-lg')}<div><h2>${esc(p.name)}</h2><p class="office-qr-day">${esc(longDate(day))}</p>
                    <span class="office-action is-${action}">${esc(t(action === 'in' ? 'action_in' : 'action_out'))}${p.clocked_in && p.since ? ` · ${esc(t('state_in', {time: p.since}))}` : ''}</span></div></div>
                <div class="office-qr-code"><canvas data-qr width="360" height="360" role="img" aria-label="QR ${esc(p.name)}"></canvas></div>
                <div class="office-qr-text"><p class="office-qr-help ${!p.has_pin && !p.phones ? 'kiosk-error' : ''}">${esc(help)}</p>
                    <p class="kiosk-muted">${esc(t('qr_only_you'))}</p>
                    <p class="office-waiting"><span class="office-dot" aria-hidden="true"></span>${esc(t('waiting'))}</p>
                    <p class="kiosk-muted office-closes" data-closes></p>
                    <div class="kiosk-row">${pinButton}<button type="button" class="kiosk-btn" data-home>${esc(t('not_me'))}</button></div></div>
            </section>`;
        el.querySelector('[data-pin-go]')?.addEventListener('click', () => renderPin(p));
        drawQr(q);
        const until = Date.now() + QR_SCREEN_MS;
        const closes = el.querySelector('[data-closes]');
        const tick = () => { if (closes) closes.textContent = t('closes_in', {time: mmss(until - Date.now())}); if (Date.now() >= until) renderHome(); };
        tick();
        timers.push(window.setInterval(tick, 1000));
        // Nowy kod przed wygaśnięciem (ten sam « since » : pointowanie od pierwszego wyświetlenia).
        timers.push(window.setInterval(async () => {
            try { drawQr(await api('POST', '/kiosk/office/qr', {person_id: personId, base: qrBase})); } catch (err) { /* następna próba */ }
        }, Math.max(10, q.refresh_s) * 1000));
        let polling = false;
        timers.push(window.setInterval(async () => {
            if (polling || !p.employee_id) return;
            polling = true;
            try {
                const s = await api('GET', `/kiosk/office/status?employee_id=${p.employee_id}&since=${encodeURIComponent(since)}`);
                if (s.clocked) renderDone(p, s);
            } catch (err) { /* sieć : następna próba */ } finally { polling = false; }
        }, POLL_MS));
    }

    // --- Bez telefonu : PIN → pytania + podpis palcem → wejście / wyjście ---------------------------------
    function renderPin(p) {
        clearTimers();
        let pin = '';
        el.innerHTML = header(`<button type="button" class="kiosk-btn is-small" data-home>‹ ${esc(t('back'))}</button>`) + `<section class="kiosk-card kiosk-pin">
                ${avatar(p.name, 'is-lg')}<h2>${esc(p.name)}</h2><p class="kiosk-muted">${esc(t('pin_help'))}</p>
                <div class="kiosk-dots" data-dots aria-hidden="true"></div><p class="kiosk-error" data-error></p>
                <div class="kiosk-pad">${[1, 2, 3, 4, 5, 6, 7, 8, 9].map(d => `<button type="button" class="kiosk-btn" data-digit="${d}">${d}</button>`).join('')}
                    <button type="button" class="kiosk-btn" data-erase aria-label="${esc(t('erase'))}">⌫</button><button type="button" class="kiosk-btn" data-digit="0">0</button>
                    <button type="button" class="kiosk-btn is-primary" data-ok>OK</button></div>
                <button type="button" class="kiosk-btn is-small" data-home>${esc(t('cancel'))}</button></section>`;
        const dots = () => { el.querySelector('[data-dots]').innerHTML = Array.from({length: Math.max(4, pin.length)}, (_, i) => `<span class="${i < pin.length ? 'is-on' : ''}"></span>`).join(''); };
        dots();
        const submit = async () => {
            if (pin.length < 4) return;
            const ok = el.querySelector('[data-ok]');
            ok.disabled = true;
            try {
                const r = await api('POST', '/kiosk/identify', {employee_id: p.employee_id, pin});
                const me = await api('GET', '/kiosk/me', undefined, r.employee_token);
                renderConfirm(p, r.employee_token, me, me.clocked_in ? 'out' : 'in');
            } catch (err) {
                if (err.code === 'DEVICE_UNAUTHENTICATED' || err.code === 'DEVICE_NOT_OFFICE') return;
                pin = ''; dots(); ok.disabled = false;
                el.querySelector('[data-error]').textContent = err.message;
            }
        };
        el.querySelector('.kiosk-pad').addEventListener('click', (e) => {
            const b = e.target.closest('button');
            if (!b) return;
            if (b.dataset.digit !== undefined && pin.length < 6) pin += b.dataset.digit;
            if (b.hasAttribute('data-erase')) pin = pin.slice(0, -1);
            dots();
            if (b.hasAttribute('data-ok')) submit();
        });
        idle(IDLE_MS);
    }

    // Pytania (ustawiane w zarządzaniu tabletami) i podpis palcem — obowiązkowy przy pointowaniu PIN-em na tablecie biurowym.
    function renderConfirm(p, token, me, direction, error) {
        clearTimers();
        const questions = (me.questions && me.questions[direction]) || [];
        const yes = new Set();
        el.innerHTML = header(`<button type="button" class="kiosk-btn is-small" data-home>‹ ${esc(t('back'))}</button>`) + `<section class="kiosk-card kiosk-confirm office-confirm">
                <div class="office-qr-who">${avatar(p.name, 'is-lg')}<div><h2>${esc(p.name)}</h2>
                    <span class="office-action is-${direction}">${esc(t(direction === 'in' ? 'confirm_in' : 'confirm_out'))}</span></div></div>
                ${questions.length ? `<p class="kiosk-muted">${esc(t('confirm_help'))}</p>
                <div class="kiosk-checks">${questions.map(q => `<button type="button" class="kiosk-check" data-q="${q.id}" aria-pressed="false">
                    <span class="kiosk-check-box" aria-hidden="true"></span><span>${esc(q.text)}${q.required ? '' : `<small>${esc(t('optional'))}</small>`}</span></button>`).join('')}</div>` : ''}
                <div class="kiosk-confirm-sign"><p class="kiosk-status">${esc(t(direction === 'in' ? 'sign_in_here' : 'sign_out_here'))}</p>
                    <canvas class="kiosk-sign" data-pad width="900" height="260" aria-label="${esc(t('signature'))}"></canvas>
                    <div class="kiosk-row is-end"><button type="button" class="kiosk-btn is-small" data-clear>${esc(t('clear'))}</button></div></div>
                <p class="kiosk-error" data-error>${esc(error || '')}</p>
                <button type="button" class="kiosk-btn kiosk-big ${direction === 'in' ? 'is-ok' : 'is-danger'}" data-confirm>${esc(t('confirm_ok'))}</button>
            </section>`;
        const ok = el.querySelector('[data-confirm]');
        const pad = signaturePad(el.querySelector('[data-pad]'), () => refresh());
        const refresh = () => { ok.disabled = questions.some(q => q.required && !yes.has(q.id)) || pad.empty(); };
        refresh();
        el.querySelector('.kiosk-checks')?.addEventListener('click', (e) => {
            const b = e.target.closest('[data-q]');
            if (!b) return;
            const id = Number(b.dataset.q);
            if (yes.has(id)) yes.delete(id); else yes.add(id);
            b.classList.toggle('is-on', yes.has(id));
            b.setAttribute('aria-pressed', String(yes.has(id)));
            refresh();
        });
        el.querySelector('[data-clear]').addEventListener('click', () => { pad.clear(); refresh(); });
        const uuid = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-8xxx-xxxxxxxxxxxx'.replace(/x/g, () => Math.floor(Math.random() * 16).toString(16));
        ok.addEventListener('click', async () => {
            ok.disabled = true;
            try {
                const r = await api('POST', '/kiosk/clock', {direction, client_uuid: uuid, answers: questions.map(q => ({question_id: q.id, yes: yes.has(q.id)})), signature: pad.image()}, token);
                const at = String((direction === 'out' ? r.clock.clock_out : r.clock.clock_in) || '').slice(11, 16);
                renderDone(p, {direction, at});
            } catch (err) {
                // Sesja PIN wygasła (kiosk.employee_token_ttl_s) : PIN jeszcze raz ; pytania zmienione : aktualny ekran.
                if (err.code === 'EMPLOYEE_SESSION_EXPIRED' || err.code === 'EMPLOYEE_UNAUTHENTICATED') return renderPin(p);
                if (err.code === 'CLOCK_QUESTIONS_REQUIRED') {
                    const fresh = await api('GET', '/kiosk/me', undefined, token).catch(() => null);
                    if (fresh) return renderConfirm(p, token, fresh, direction, err.message);
                }
                ok.disabled = false;
                el.querySelector('[data-error]').textContent = err.offline ? t('offline') : err.message;
            }
        });
        idle(IDLE_MS * 2);
    }

    // Ramka podpisu palcem (biały PNG) : empty() — nic nie narysowano, image() — data URL.
    function signaturePad(canvas, onDraw) {
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.lineWidth = 3; ctx.lineCap = 'round'; ctx.strokeStyle = '#111';
        let drawing = false;
        let strokes = 0;
        const point = (e) => { const r = canvas.getBoundingClientRect(); return [(e.clientX - r.left) * canvas.width / r.width, (e.clientY - r.top) * canvas.height / r.height]; };
        canvas.addEventListener('pointerdown', (e) => { drawing = true; strokes++; canvas.setPointerCapture(e.pointerId); const [x, y] = point(e); ctx.beginPath(); ctx.moveTo(x, y); if (idleMs) idle(idleMs); });
        canvas.addEventListener('pointermove', (e) => { if (!drawing) return; const [x, y] = point(e); ctx.lineTo(x, y); ctx.stroke(); });
        canvas.addEventListener('pointerup', () => { drawing = false; if (onDraw) onDraw(); });
        return {
            empty: () => strokes === 0,
            clear: () => { ctx.fillRect(0, 0, canvas.width, canvas.height); strokes = 0; },
            image: () => canvas.toDataURL('image/png'),
        };
    }

    function drawQr(q) {
        const canvas = el.querySelector('[data-qr]');
        if (!canvas) return;
        const quiet = 4;
        const total = q.size + 2 * quiet;
        const scale = Math.max(2, Math.floor(720 / total));
        canvas.width = canvas.height = total * scale;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = '#000';
        q.rows.forEach((row, y) => { for (let x = 0; x < row.length; x++) if (row[x] === '1') ctx.fillRect((x + quiet) * scale, (y + quiet) * scale, scale, scale); });
    }

    function renderDone(p, s) {
        clearTimers();
        const out = s.direction === 'out';
        el.innerHTML = header() + `<section class="kiosk-card office-done is-${out ? 'out' : 'in'}">
                <div class="office-done-mark" aria-hidden="true">✓</div>
                <h2>${esc(t(out ? 'bye' : 'hello', {name: p.name}))}</h2>
                <p class="office-done-text">${esc(t(out ? 'done_out' : 'done_in', {time: s.at}))}</p>
                <p class="kiosk-muted">${esc(t('done_back'))}</p>
                <div class="kiosk-row"><button type="button" class="kiosk-btn is-primary" data-home>${esc(t('ok'))}</button></div></section>`;
        if (navigator.vibrate) navigator.vibrate(80);
        timers.push(window.setTimeout(renderHome, DONE_MS));
    }

    el.addEventListener('click', async (e) => {
        if (e.target.closest('[data-lang]')) {
            lang = lang === 'pl' ? 'fr' : 'pl';
            store.set(LANG_KEY, lang);
            await loadLabels();
            renderHome();
            return;
        }
        if (e.target.closest('[data-home]')) renderHome();
    });
    el.addEventListener('pointerdown', () => { if (idleMs) idle(idleMs); });
    window.setInterval(() => { const c = el.querySelector('[data-clock]'); if (c) c.textContent = new Date().toTimeString().slice(0, 5); }, 10000);

    // Démarrage : sans jeton local, le cookie de l'appairage dit si la tablette est déjà appairée.
    async function detectPairing() {
        if (paired) return;
        try {
            const res = await fetch(base + '/kiosk/device', {headers: {'Accept': 'application/json', 'Accept-Language': lang}, credentials: 'same-origin'});
            const json = await res.json().catch(() => null);
            paired = res.ok;
            // Seule la tablette chauffeur part ailleurs ; une tablette de production reste sur l'écran bureau (27/10/2026).
            if (paired && json && json.data && json.data.mode === 'driver') { location.replace(root + '/tablet/driver'); return; }
        } catch (e) { /* hors ligne : écran d'appairage */ }
    }
    Promise.all([loadLabels(), detectPairing()]).then(renderHome);
})();
