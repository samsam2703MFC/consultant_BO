/*
 * Tablet biurowy / Tablette de bureau (PWA). Tablet rodzaju « Tablet biurowy » (zarządzanie tabletami), parowany tym samym
 * kodem co tablet pracowni ; tablet pracowni otwarty na /office też pokazuje ten ekran i zostaje na nim. Wszystkie grupy
 * i wszystkie osoby od razu na ekranie (pasek grup, wyszukiwanie) ; osoba dotyka swojego nazwiska : tablet pokazuje jej osobisty kod QR
 * (osoba + dzień). Osoba skanuje go swoim telefonem i zatwierdza wejście albo wyjście na telefonie (pytania, podpis) ;
 * telefon innej osoby jest odrzucany. Tablet sprawdza co 2 s, czy pointowanie jest zapisane, i wraca do działów.
 * Bez telefonu : PIN, potem pytania i podpis palcem na tablecie (pin_allowed ; kiosk.office_pin_all — wszyscy).
 * [356] Osoba z PIN-em (pin_allowed) : dotknięcie nazwiska → od razu PIN (4 cyfry), duży przycisk WEJŚCIE / WYJŚCIE,
 *       pytania (Tak / Nie, punkty) i podpis ; kod QR telefonem — przycisk na ekranie PIN-u.
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
    const IDLE_MS = 60000;       // wyszukiwanie, PIN osoby : powrót do PIN-u po 1 min bez dotyku
    const DONE_MS = 6000;
    const store = {
        get(k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
        set(k, v) { try { v === null ? window.localStorage.removeItem(k) : window.localStorage.setItem(k, v); } catch (e) { /* stockage indisponible */ } },
    };
    let lang = store.get(LANG_KEY) === 'fr' ? 'fr' : 'pl';
    let labels = {};
    // [299] Otwarty z sesji ERP : serwer sparował go od razu (nowy token tylko, gdy przeglądarka nie miała ważnego).
    const erpDevice = el.dataset.erpDevice || '';
    if (erpDevice) store.set(DEVICE_KEY, erpDevice);
    let device = store.get(DEVICE_KEY);
    let paired = !!device;
    let timers = [];
    let deviceInfo = null;

    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const t = (k, p) => { let s = labels[k] ?? k; for (const [a, b] of Object.entries(p || {})) s = s.split('{' + a + '}').join(String(b)); return s; };
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
        if (err.code === 'DEVICE_UNAUTHENTICATED') {
            // [299] Sesja ERP : strona sama paruje się na nowo (raz na minutę najwyżej), bez ekranu z kodem.
            let last = 0;
            try { last = Number(window.sessionStorage.getItem('tfb.office.repair') || 0); } catch (e) { /* brak */ }
            if (el.dataset.erpSession && Date.now() - last > 60000) {
                try { window.sessionStorage.setItem('tfb.office.repair', String(Date.now())); } catch (e) { /* brak */ }
                store.set(DEVICE_KEY, null);
                location.reload();
            } else { paired = false; clearTimers(); renderPairing(err.message); }
        }
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

    // [361][364] Accueil : le clavier PIN — le PIN seul ouvre le pointage de la personne (tous les PIN sont différents).
    // RODO : plus de liste de tout le personnel ni de présences à l'écran ; sans PIN, « Nie mam PIN-u » → la personne tape
    // son nom, se choisit et crée son PIN.
    async function renderHome() {
        if (!paired) return renderPairing();
        clearTimers();
        try {
            if (deviceInfo === null) deviceInfo = await api('GET', '/kiosk/device');
        } catch (err) {
            if (err.code === 'DEVICE_UNAUTHENTICATED' || err.code === 'DEVICE_NOT_OFFICE') return;
        }
        pinPad(null, {
            home: true,
            help: t('pin_home_help'),
            extra: `<button type="button" class="kiosk-btn is-small" data-first-go>${esc(t('no_pin_btn'))}</button>`,
            onPin: async (pin) => {
                let r;
                try {
                    r = await api('POST', '/kiosk/office/login', {pin});
                } catch (err) {
                    if (err.code === 'PIN_CHOOSE_NAME') return renderSearch('choose', err.message);
                    throw err;
                }
                const person = r.person || {id: null, employee_id: r.employee.id, name: r.employee.name, has_pin: true, phones: 0};
                const me = await api('GET', '/kiosk/me', undefined, r.employee_token);
                if (r.pin_change_required) return renderChangePin(person, r.employee_token, me, pin, true);
                clockNow(person, r.employee_token, me, pin);
            },
        });
        el.querySelector('[data-first-go]').addEventListener('click', () => renderSearch('first'));
        idleMs = 0;
        window.clearTimeout(idleTimer);
    }

    /**
     * [364] Recherche de son nom (au moins 3 lettres) : le serveur ne renvoie que les noms qui correspondent (8 au plus),
     * sans service ni présence. mode first : créer son PIN (déjà un PIN → le responsable le remet) ; choose : deux
     * personnes avaient le même PIN — on se choisit puis on tape son PIN (un nouveau sera demandé).
     */
    function renderSearch(mode, message) {
        clearTimers();
        el.innerHTML = header(`<button type="button" class="kiosk-btn is-small" data-home>‹ ${esc(t('back'))}</button>`) + `<section class="kiosk-card office-search">
                <h2>${esc(t(mode === 'choose' ? 'search_title_choose' : 'search_title_first'))}</h2>
                ${message ? `<p class="kiosk-error">${esc(message)}</p>` : ''}
                <p class="kiosk-muted">${esc(t('search_help'))}</p>
                <input class="kiosk-search office-search-input" type="search" data-search placeholder="${esc(t('search_ph'))}" aria-label="${esc(t('search_ph'))}" autocomplete="off" autocapitalize="words" spellcheck="false">
                <div class="office-search-results" data-results aria-live="polite"></div>
                <div class="kiosk-row"><button type="button" class="kiosk-btn is-small" data-home>${esc(t('cancel'))}</button></div></section>`;
        const input = el.querySelector('[data-search]');
        const box = el.querySelector('[data-results]');
        let timer = null, seq = 0, found = [];
        const draw = (list, q) => {
            found = list;
            if (q.length < 3) { box.innerHTML = ''; return; }
            box.innerHTML = list.length ? list.map((p, i) => `<button type="button" class="office-search-row" data-pick="${i}">${avatar(p.name)}<span>${esc(p.name)}</span><i class="office-search-go">›</i></button>`).join('')
                : `<p class="kiosk-muted">${esc(t('search_none'))}</p>`;
        };
        input.addEventListener('input', () => {
            const q = input.value.trim();
            window.clearTimeout(timer);
            idle(IDLE_MS);
            if (q.length < 3) return draw([], q);
            timer = window.setTimeout(async () => {
                const mine = ++seq;
                try {
                    const list = await api('GET', '/kiosk/office/search?q=' + encodeURIComponent(q));
                    if (mine === seq) draw(list, q);
                } catch (err) { if (mine === seq) box.innerHTML = `<p class="kiosk-error">${esc(err.offline ? t('offline') : err.message)}</p>`; }
            }, 250);
        });
        box.addEventListener('click', (e) => {
            const b = e.target.closest('[data-pick]');
            const p = b && found[Number(b.dataset.pick)];
            if (!p) return;
            if (!p.has_pin) return renderFirstPin(p);
            if (mode === 'choose' && p.employee_id) return renderPin(p);
            box.innerHTML = `<p class="office-search-note">${esc(t('pin_forgot', {name: p.name}))}</p>`;
        });
        input.focus();
        idle(IDLE_MS);
    }

    // --- Osobisty kod QR (osoba + dzień) ---------------------------------------------------------------
    async function openPerson(personId) {
        clearTimers();
        // [355] Hôte de cette tablette (joignable sur le Wi-Fi de l'atelier), pas celui d'APP_URL.
        const qrBase = (window.location.origin + new URL(root + '/', window.location.href).pathname).replace(/\/$/, '');
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
                <div class="office-qr-code"><canvas data-qr width="360" height="360" role="img" aria-label="QR ${esc(p.name)}"></canvas><p class="office-qr-url" data-qr-url></p></div>
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
    // Klawiatura PIN-u (logowanie, pierwszy PIN, zmiana PIN-u) : onPin(pin) — błąd = komunikat i klawiatura od nowa.
    function pinPad(p, opts) {
        clearTimers();
        let pin = '';
        const now = new Date();
        // Accueil : heure, date et langue en haut, le clavier au centre ; sinon l'en-tête avec retour et le nom de la personne.
        const top = opts.home ? `<div class="kiosk-ipad-bar office-pinhome-bar"><span class="kiosk-logo" aria-hidden="true">LA</span><span class="kiosk-ipad-name">${esc(t('title'))}</span>
                <button type="button" class="kiosk-chip is-glass" data-lang>${esc(t('lang_switch'))}</button></div>
            <section class="kiosk-ipad-hero office-hero"><div class="kiosk-ipad-time" data-clock>${now.toTimeString().slice(0, 5)}</div>
                <div class="kiosk-ipad-date">${esc(longDate(now))}</div></section>`
            : header(`<button type="button" class="kiosk-btn is-small" data-home>‹ ${esc(t('back'))}</button>`);
        el.innerHTML = top + `<section class="kiosk-card kiosk-pin ${opts.home ? 'office-pinhome' : ''}">
                ${opts.home ? `<h2>${esc(t('pin_home_title'))}</h2>` : `${avatar(p.name, 'is-lg')}<h2>${esc(p.name)}</h2>`}${opts.title ? `<p class="office-pin-title">${esc(opts.title)}</p>` : ''}<p class="kiosk-muted">${esc(opts.help)}</p>
                <div class="kiosk-dots" data-dots aria-hidden="true"></div><p class="kiosk-error" data-error>${esc(opts.error || '')}</p>
                <div class="kiosk-pad">${[1, 2, 3, 4, 5, 6, 7, 8, 9].map(d => `<button type="button" class="kiosk-btn" data-digit="${d}">${d}</button>`).join('')}
                    <button type="button" class="kiosk-btn" data-erase aria-label="${esc(t('erase'))}">⌫</button><button type="button" class="kiosk-btn" data-digit="0">0</button>
                    <button type="button" class="kiosk-btn is-primary" data-ok>OK</button></div>
                <div class="kiosk-row">${opts.extra || ''}${opts.home ? '' : `<button type="button" class="kiosk-btn is-small" ${opts.onCancel ? 'data-pin-cancel' : 'data-home'}>${esc(t('cancel'))}</button>`}</div></section>`;
        if (opts.onCancel) el.querySelector('[data-pin-cancel]').addEventListener('click', opts.onCancel);
        const dots = () => { el.querySelector('[data-dots]').innerHTML = Array.from({length: Math.max(4, pin.length)}, (_, i) => `<span class="${i < pin.length ? 'is-on' : ''}"></span>`).join(''); };
        dots();
        const submit = async () => {
            if (pin.length < 4) return;
            const ok = el.querySelector('[data-ok]');
            ok.disabled = true;
            try {
                await opts.onPin(pin);
            } catch (err) {
                if (err.code === 'DEVICE_UNAUTHENTICATED' || err.code === 'DEVICE_NOT_OFFICE') return;
                pin = ''; dots(); ok.disabled = false;
                const box = el.querySelector('[data-error]');
                if (box) box.textContent = err.offline ? t('offline') : err.message;
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
        if (!opts.home) idle(IDLE_MS);
    }

    function renderPin(p) {
        pinPad(p, {
            help: t('pin_help'),
            extra: p.phones ? `<button type="button" class="kiosk-btn is-small" data-qr-go><i class="bi bi-qr-code"></i> ${esc(t('qr_instead'))}</button>` : '',
            onPin: async (pin) => {
                const r = await api('POST', '/kiosk/identify', {employee_id: p.employee_id, pin});
                const me = await api('GET', '/kiosk/me', undefined, r.employee_token);
                if (r.pin_change_required) return renderChangePin(p, r.employee_token, me, pin, true);
                clockNow(p, r.employee_token, me, pin);
            },
        });
        el.querySelector('[data-qr-go]')?.addEventListener('click', () => openPerson(p.id));
    }

    // [360] Nowy PIN dwa razy (pierwsze logowanie albo zmiana) ; save(pin) zapisuje, błąd serwera → od początku z komunikatem.
    function newPin(p, title, save, onCancel, error) {
        pinPad(p, {title, help: t('new_pin_help'), error, onCancel, onPin: async (first) => {
            pinPad(p, {title, help: t('new_pin_repeat'), onCancel, onPin: async (second) => {
                if (second !== first) return newPin(p, title, save, onCancel, t('pin_mismatch'));
                try { await save(first); } catch (err) {
                    if (err.code === 'DEVICE_UNAUTHENTICATED' || err.code === 'DEVICE_NOT_OFFICE') return;
                    // PIN zbyt prosty, taki sam jak obecny… : od początku, z komunikatem ; zły obecny PIN (zmiana) : błąd na ekranie.
                    if (err.code === 'VALIDATION_FAILED' || (err.fields || []).includes('pin')) return newPin(p, title, save, onCancel, err.message);
                    throw err;
                }
            }});
        }});
    }

    // [360] Pierwsze logowanie na tablecie biurowym : osoba bez PIN-u tworzy swój (4 cyfry, dwa razy), potem wejście / wyjście.
    function renderFirstPin(p) {
        newPin(p, t('new_pin_title'), async (pin) => {
            const r = await api('POST', '/kiosk/office/pin', {person_id: p.id, pin});
            const person = {...p, employee_id: r.employee ? r.employee.id : p.employee_id, has_pin: true};
            const me = await api('GET', '/kiosk/me', undefined, r.employee_token);
            clockNow(person, r.employee_token, me, pin, t('pin_created'));
        });
    }

    // [360] Zmiana PIN-u po zalogowaniu : nowy PIN dwa razy ; obecny PIN to ten, który osoba właśnie wpisała.
    // [361] forced : ce PIN est aussi celui d'une autre personne (posé avant l'unicité) — nouveau PIN avant de continuer.
    function renderChangePin(p, token, me, current, forced) {
        newPin(p, t('change_pin'), async (pin) => {
            await api('PUT', '/kiosk/me/pin', {current_pin: current, pin}, token);
            clockNow(p, token, me, pin, t('pin_changed'));
        }, forced ? () => renderHome() : () => clockNow(p, token, me, current), forced ? t('pin_change_required') : undefined);
    }

    // [364] Après le PIN : le pointage tout de suite (entrée si absent, sortie si présent) — questions puis signature.
    function clockNow(p, token, me, pin, info) {
        renderConfirm(p, token, me, me.clocked_in ? 'out' : 'in', undefined, {pin, info});
    }

    // Pytania (ustawiane w zarządzaniu tabletami) i podpis palcem — obowiązkowy przy pointowaniu PIN-em na tablecie biurowym.
    function renderConfirm(p, token, me, direction, error, extra) {
        clearTimers();
        extra = extra || {};
        const since = me.clocked_in && me.clock && me.clock.clock_in ? String(me.clock.clock_in).slice(11, 16) : null;
        const questions = (me.questions && me.questions[direction]) || [];
        // [263] Punkty do potwierdzenia i pytania Tak / Nie (np. towar przy wyjściu, (i) z zasadą firmy).
        let refresh = () => {};
        const cq = window.TfbClockQuestions(questions, {t, esc, catalog: (me.questions && me.questions.catalog) || [], onChange: () => refresh()});
        el.innerHTML = header(`<button type="button" class="kiosk-btn is-small" data-home>‹ ${esc(t('back'))}</button>`) + `<section class="kiosk-card kiosk-confirm office-confirm">
                <div class="office-confirm-main"><div class="office-qr-who">${avatar(p.name, 'is-lg')}<div><h2>${esc(t('hello', {name: p.name}))}</h2>
                    <span class="office-action is-${direction}">${esc(t(direction === 'in' ? 'confirm_in' : 'confirm_out'))}${since ? ` · ${esc(t('state_in', {time: since}))}` : ''}</span></div></div>
                ${extra.info ? `<p class="office-clock-info">${esc(extra.info)}</p>` : ''}
                ${cq.html}</div>
                <div class="office-confirm-side"><div class="kiosk-confirm-sign"><p class="kiosk-status">${esc(t(direction === 'in' ? 'sign_in_here' : 'sign_out_here'))}</p>
                    <canvas class="kiosk-sign" data-pad width="900" height="260" aria-label="${esc(t('signature'))}"></canvas>
                    <div class="kiosk-row is-end"><button type="button" class="kiosk-btn is-small" data-clear>${esc(t('clear'))}</button></div></div>
                ${direction === 'out' ? `<label class="office-daynote"><span>${esc(t('day_note'))}</span>
                    <textarea class="kiosk-input" data-day-note rows="2" maxlength="500" placeholder="${esc(t('day_note_ph'))}"></textarea></label>` : ''}
                <p class="kiosk-error" data-error>${esc(error || '')}</p>
                <button type="button" class="kiosk-btn kiosk-big ${direction === 'in' ? 'is-ok' : 'is-danger'}" data-confirm>${esc(t('confirm_ok'))}</button>
                <div class="kiosk-row office-clock-more">${extra.pin ? `<button type="button" class="kiosk-btn is-small" data-change-pin>${esc(t('change_pin'))}</button>` : ''}
                    <button type="button" class="kiosk-btn is-small" data-home>${esc(t('not_me'))}</button></div></div>
            </section>`;
        el.querySelector('[data-change-pin]')?.addEventListener('click', () => renderChangePin(p, token, me, extra.pin));
        const ok = el.querySelector('[data-confirm]');
        const pad = signaturePad(el.querySelector('[data-pad]'), () => refresh());
        refresh = () => { ok.disabled = cq.blocked() || pad.empty(); };
        refresh();
        cq.bind(el);
        el.querySelector('[data-clear]').addEventListener('click', () => { pad.clear(); refresh(); });
        const uuid = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-8xxx-xxxxxxxxxxxx'.replace(/x/g, () => Math.floor(Math.random() * 16).toString(16));
        ok.addEventListener('click', async () => {
            ok.disabled = true;
            try {
                const note = (el.querySelector('[data-day-note]')?.value || '').trim();
                const r = await api('POST', '/kiosk/clock', {direction, client_uuid: uuid, answers: cq.answers(), signature: pad.image(), ...(note ? {note} : {})}, token);
                const at = String((direction === 'out' ? r.clock.clock_out : r.clock.clock_in) || '').slice(11, 16);
                // [262] Dzień wolny za nadgodziny zaproponowany przez kierownika : podpis zaraz po odbiciu.
                if (me.comp_days && me.comp_days.length) return renderComp(p, token, me.comp_days.slice(), {direction, at});
                renderDone(p, {direction, at});
            } catch (err) {
                // Sesja PIN wygasła (kiosk.employee_token_ttl_s) : PIN jeszcze raz ; pytania zmienione : aktualny ekran.
                if (err.code === 'EMPLOYEE_SESSION_EXPIRED' || err.code === 'EMPLOYEE_UNAUTHENTICATED') return renderPin(p);
                if (err.code === 'CLOCK_QUESTIONS_REQUIRED') {
                    const fresh = await api('GET', '/kiosk/me', undefined, token).catch(() => null);
                    if (fresh) return renderConfirm(p, token, fresh, direction, err.message, extra);
                }
                ok.disabled = false;
                el.querySelector('[data-error]').textContent = err.offline ? t('offline') : err.message;
            }
        });
        idle(IDLE_MS * 2);
    }

    // [262] Dzień wolny za nadgodziny : osoba podpisuje palcem dzień zaproponowany przez kierownika produkcji (Czas pracy ›
    // Nadgodziny) ; podpis z datą i SHA-256 jak przy odbiciu, dzień trafia do kalendarza, bank nadgodzin maleje. « Nie teraz » :
    // propozycja czeka do następnego odbicia (albo podpis w ERP przy kierowniku).
    function renderComp(p, token, comps, done, error) {
        clearTimers();
        const c = comps[0];
        const day = longDate(new Date(c.day + 'T12:00:00'));
        el.innerHTML = header() + `<section class="kiosk-card kiosk-confirm office-confirm office-comp">
                <div class="office-qr-who">${avatar(p.name, 'is-lg')}<div><h2>${esc(p.name)}</h2>
                    <span class="office-action is-comp">${esc(t('comp_title'))}${comps.length > 1 ? ` (${comps.length})` : ''}</span></div></div>
                <p class="office-comp-text">${esc(t('comp_text', {hours: c.hours, day}))}</p>
                <p class="kiosk-muted">${esc(t('comp_help'))}</p>
                <div class="kiosk-confirm-sign"><canvas class="kiosk-sign" data-pad width="900" height="260" aria-label="${esc(t('signature'))}"></canvas>
                    <div class="kiosk-row is-end"><button type="button" class="kiosk-btn is-small" data-clear>${esc(t('clear'))}</button></div></div>
                <p class="kiosk-error" data-error>${esc(error || '')}</p>
                <button type="button" class="kiosk-btn kiosk-big is-ok" data-comp-sign>${esc(t('comp_sign'))}</button>
                <button type="button" class="kiosk-btn is-small" data-comp-later>${esc(t('comp_later'))}</button>
            </section>`;
        const ok = el.querySelector('[data-comp-sign]');
        const pad = signaturePad(el.querySelector('[data-pad]'), () => { ok.disabled = pad.empty(); });
        ok.disabled = true;
        el.querySelector('[data-clear]').addEventListener('click', () => { pad.clear(); ok.disabled = true; });
        el.querySelector('[data-comp-later]').addEventListener('click', () => renderDone(p, done));
        ok.addEventListener('click', async () => {
            ok.disabled = true;
            try {
                await api('POST', `/kiosk/comps/${c.id}/sign`, {image: pad.image()}, token);
                comps.shift();
                if (comps.length) return renderComp(p, token, comps, done);
                renderDone(p, Object.assign({}, done, {comp: true}));
            } catch (err) {
                if (err.code === 'EMPLOYEE_SESSION_EXPIRED' || err.code === 'EMPLOYEE_UNAUTHENTICATED') return renderDone(p, done);
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
        // [355] Adresse ouverte par le QR (sans le code) : à comparer avec celle qui s'ouvre sur le téléphone.
        const url = el.querySelector('[data-qr-url]');
        if (url) url.textContent = String(q.url || '').split('#')[0].replace(/^https?:\/\//, '');
    }

    function renderDone(p, s) {
        clearTimers();
        const out = s.direction === 'out';
        el.innerHTML = header() + `<section class="kiosk-card office-done is-${out ? 'out' : 'in'}">
                <div class="office-done-mark" aria-hidden="true">✓</div>
                <h2>${esc(t(out ? 'bye' : 'hello', {name: p.name}))}</h2>
                <p class="office-done-text">${esc(t(out ? 'done_out' : 'done_in', {time: s.at}))}</p>
                ${s.comp ? `<p class="office-done-text">${esc(t('comp_done'))}</p>` : ''}
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
