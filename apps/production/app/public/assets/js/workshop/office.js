/*
 * Tablet biurowy / Tablette de bureau (PWA). Tablet w trybie « Biuro » (zarządzanie tabletami), parowany tym samym
 * kodem co tablet pracowni. Osoba dotyka swojego działu, potem swojego nazwiska : tablet pokazuje jej osobisty kod QR
 * (osoba + dzień). Osoba skanuje go swoim telefonem i zatwierdza wejście albo wyjście na telefonie (pytania, podpis) ;
 * telefon innej osoby jest odrzucany. Tablet sprawdza co 2 s, czy pointowanie jest zapisane, i wraca do działów.
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

    async function api(method, path, body) {
        const headers = {'Accept': 'application/json', 'Accept-Language': lang};
        if (device) headers['X-Device-Token'] = device;
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
        if (err.code === 'DEVICE_UNAUTHENTICATED') { paired = false; clearTimers(); renderPairing(err.message); }
        // Tablet pracowni albo kierowcy : jego własna strona (/kiosk przekierowuje tablet kierowcy na /driver).
        if (err.code === 'DEVICE_NOT_OFFICE') location.replace(root + '/kiosk');
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
                if (r.device.mode !== 'office') { location.replace(root + (r.device.mode === 'driver' ? '/driver' : '/kiosk')); return; }
                toast(t('paired', {name: r.device.name}));
                renderHome();
            } catch (err) { el.querySelector('[data-error]').textContent = err.message; }
        });
        el.querySelector('[name=code]').focus();
    }

    // --- Działy ----------------------------------------------------------------------------------------
    const allPeople = () => sections.flatMap(s => s.people.map(p => ({...p, section: s.name, section_id: s.id})));

    async function renderHome() {
        if (!paired) return renderPairing();
        clearTimers();
        try {
            sections = await api('GET', '/kiosk/office/sections');
        } catch (err) {
            if (err.code === 'DEVICE_UNAUTHENTICATED' || err.code === 'DEVICE_NOT_OFFICE') return;
            el.innerHTML = header() + `<section class="kiosk-card"><p class="kiosk-error">${esc(err.message)}</p>
                <div class="kiosk-row"><button type="button" class="kiosk-btn is-primary" data-retry>${esc(t('retry'))}</button></div></section>`;
            el.querySelector('[data-retry]').addEventListener('click', renderHome);
            return;
        }
        const people = allPeople();
        const present = people.filter(p => p.clocked_in).length;
        const now = new Date();
        el.innerHTML = `<div class="kiosk-ipad office-home">
            <div class="kiosk-ipad-bar"><span class="kiosk-logo" aria-hidden="true">LA</span><span class="kiosk-ipad-name">${esc(t('title'))}</span>
                <button type="button" class="kiosk-chip is-glass" data-lang>${esc(t('lang_switch'))}</button></div>
            <section class="kiosk-ipad-hero"><div class="kiosk-ipad-time" data-clock>${now.toTimeString().slice(0, 5)}</div>
                <div class="kiosk-ipad-date">${esc(longDate(now))}</div>
                <p class="kiosk-ipad-greet">${esc(t('hero'))}</p>
                <p class="office-present">${esc(t('present_total', {n: present, total: people.length}))}</p></section>
            <input class="kiosk-search office-search" type="search" data-search placeholder="${esc(t('search'))}" aria-label="${esc(t('search'))}" autocomplete="off">
            <div data-home-body></div></div>`;
        const body = el.querySelector('[data-home-body]');
        const drawSections = () => {
            body.innerHTML = sections.length ? `<div class="office-sections">${sections.map(s => {
                const n = s.people.filter(p => p.clocked_in).length;
                return `<button type="button" class="office-section" data-section="${s.id}"><span class="office-section-name">${esc(s.name)}</span>
                    <span class="office-section-meta">${esc(t('section_people', {n: s.people.length, present: n}))}</span>
                    <span class="office-section-faces" aria-hidden="true">${s.people.slice(0, 5).map(p => avatar(p.name, p.clocked_in ? 'is-in' : '')).join('')}</span></button>`;
            }).join('')}</div>` : `<p class="kiosk-muted">${esc(t('no_people'))}</p>`;
        };
        drawSections();
        body.addEventListener('click', (e) => {
            const s = e.target.closest('[data-section]');
            if (s) return renderSection(Number(s.dataset.section));
            const p = e.target.closest('[data-person]');
            if (p) openPerson(Number(p.dataset.person));
        });
        // Wyszukiwanie : wszystkie działy naraz (nie trzeba pamiętać działu).
        el.querySelector('[data-search]').addEventListener('input', (e) => {
            const f = fold(e.target.value.trim());
            if (!f) return drawSections();
            const list = people.filter(p => fold(p.name).includes(f));
            body.innerHTML = list.length ? `<div class="kiosk-apps office-people">${list.map(personTile).join('')}</div>` : `<p class="kiosk-muted">${esc(t('no_match'))}</p>`;
        });
        // Stan obecności odświeżany co minutę, gdy nikt nie szuka.
        timers.push(window.setInterval(() => { const s = el.querySelector('[data-search]'); if (s && !s.value && document.activeElement !== s) renderHome(); }, 60000));
    }

    function personTile(p) {
        const state = p.clocked_in ? t('state_in', {time: p.since}) : p.left_at ? t('state_left', {time: p.left_at}) : (!p.has_pin && !p.phones ? t('state_no_pin') : t('state_out'));
        return `<button type="button" class="kiosk-app-person ${p.clocked_in ? 'is-in' : ''} ${!p.has_pin && !p.phones ? 'is-nopin' : ''}" data-person="${p.id}">
            ${avatar(p.name, 'is-app')}<span class="kiosk-app-name">${esc(p.name)}</span><small>${esc(state)}</small></button>`;
    }

    // --- Nazwiska działu -------------------------------------------------------------------------------
    function renderSection(sectionId) {
        clearTimers();
        const s = sections.find(x => x.id === sectionId);
        if (!s) return renderHome();
        const back = `<button type="button" class="kiosk-btn is-small" data-home>‹ ${esc(t('back'))}</button>`;
        el.innerHTML = header(back) + `<section class="kiosk-card office-section-card"><h2>${esc(s.name)}</h2><p class="kiosk-muted">${esc(t('who'))}</p>
            ${s.people.length ? `<div class="kiosk-apps office-people">${s.people.map(personTile).join('')}</div>` : `<p class="kiosk-muted">${esc(t('nobody'))}</p>`}</section>`;
        el.querySelector('.office-section-card').addEventListener('click', (e) => { const p = e.target.closest('[data-person]'); if (p) openPerson(Number(p.dataset.person)); });
        idle(IDLE_MS);
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
        const help = !p.has_pin && !p.phones ? t('qr_no_pin') : (!p.phones ? t('qr_first') : t('qr_help'));
        el.innerHTML = header(`<button type="button" class="kiosk-btn is-small" data-home>‹ ${esc(t('back'))}</button>`) + `<section class="kiosk-card office-qr">
                <div class="office-qr-who">${avatar(p.name, 'is-lg')}<div><h2>${esc(p.name)}</h2><p class="office-qr-day">${esc(longDate(day))}</p>
                    <span class="office-action is-${action}">${esc(t(action === 'in' ? 'action_in' : 'action_out'))}${p.clocked_in && p.since ? ` · ${esc(t('state_in', {time: p.since}))}` : ''}</span></div></div>
                <div class="office-qr-code"><canvas data-qr width="360" height="360" role="img" aria-label="QR ${esc(p.name)}"></canvas></div>
                <div class="office-qr-text"><p class="office-qr-help ${!p.has_pin && !p.phones ? 'kiosk-error' : ''}">${esc(help)}</p>
                    <p class="kiosk-muted">${esc(t('qr_only_you'))}</p>
                    <p class="office-waiting"><span class="office-dot" aria-hidden="true"></span>${esc(t('waiting'))}</p>
                    <p class="kiosk-muted office-closes" data-closes></p>
                    <div class="kiosk-row"><button type="button" class="kiosk-btn" data-home>${esc(t('not_me'))}</button></div></div>
            </section>`;
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
            if (paired && json && json.data && json.data.mode && json.data.mode !== 'office') { location.replace(root + (json.data.mode === 'driver' ? '/driver' : '/kiosk')); return; }
        } catch (e) { /* hors ligne : écran d'appairage */ }
    }
    Promise.all([loadLabels(), detectPairing()]).then(renderHome);
})();
