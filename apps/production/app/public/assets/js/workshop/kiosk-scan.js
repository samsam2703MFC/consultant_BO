/*
 * Wejście / wyjście telefonem / Entrée / sortie au téléphone (P7).
 * Strona otwierana kodem QR z tabletu (…/kiosk/scan#t=…) : kod jest ważny chwilę (obecność na miejscu).
 * Pierwszy raz : nazwisko + PIN, telefon dostaje własny token (pamięć przeglądarki). Potem WEJŚCIE / WYJŚCIE :
 * pytania ustawione przez kierownika (np. « Jestem w odzieży roboczej ») do potwierdzenia, podpis palcem (wejście :
 * lista obecności, wyjście : godziny ; bez podpisu nie ma zapisu) i « OK, zatwierdzam ». „To nie ja” zapomina telefon. Osobisty kod QR z tabletu biurowego (osoba + dzień) :
 * od razu PIN tej osoby przy pierwszym skanie ; telefon zarejestrowany na kogoś innego jest odrzucany.
 */
(function () {
    'use strict';
    // Wersja zasobów z adresu skryptu (?v=) : etykiety w tej samej wersji co skrypt.
    const VERSION = (document.currentScript && new URL(document.currentScript.src, location.href).searchParams.get('v')) || '';
    const el = document.getElementById('tfbScan');
    if (!el) return;
    const base = (el.dataset.apiBase || '').replace(/\/$/, '');
    const root = (el.dataset.root || '').replace(/\/$/, '');
    const PHONE_KEY = 'tfb.phone.token';
    const NAME_KEY = 'tfb.phone.name';
    const LANG_KEY = 'tfb.lang';
    const store = {
        get(k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
        set(k, v) { try { v === null ? window.localStorage.removeItem(k) : window.localStorage.setItem(k, v); } catch (e) { /* stockage indisponible */ } },
    };
    // Jeton du QR : dans le fragment (jamais envoyé au serveur), retiré de l'adresse aussitôt. Un nouveau
    // scan avec la page déjà ouverte ne change que le fragment (hashchange, sans rechargement).
    const readQr = () => {
        const token = (new URLSearchParams(window.location.hash.slice(1))).get('t') || '';
        if (window.location.hash) window.history.replaceState(null, '', window.location.pathname + window.location.search);
        return token;
    };
    let qr = readQr();
    let lang = store.get(LANG_KEY) === 'fr' ? 'fr' : 'pl';
    let labels = {};
    let pending = null; // {uuid, direction} : pointage rejoué sans doublon après une coupure

    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const t = (k, p) => { let s = labels[k] ?? k; for (const [a, b] of Object.entries(p || {})) s = s.split('{' + a + '}').join(String(b)); return s; };
    const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16); }));
    const fmt = (n) => Number(n).toLocaleString(lang === 'fr' ? 'fr-FR' : 'pl-PL', {maximumFractionDigits: 2});
    // Osobisty kod QR (tablet biurowy) : jeton z osobą (emp) — tylko do wyświetlenia, serwer sprawdza sam.
    const personalQr = () => { try { return !!JSON.parse(atob(qr.split('.')[1].replace(/-/g, '+').replace(/_/g, '/'))).emp; } catch (e) { return false; } };

    async function loadLabels() {
        const res = await fetch(`${root}/resources/lang/ui/${lang}/kiosk.json${VERSION ? '?v=' + encodeURIComponent(VERSION) : ''}`, {credentials: 'same-origin'});
        labels = res.ok ? await res.json() : {};
        document.documentElement.lang = lang;
    }

    async function api(method, path, body) {
        const headers = {'Accept': 'application/json', 'Accept-Language': lang, 'X-Qr-Token': qr};
        const phone = store.get(PHONE_KEY);
        if (phone) headers['X-Phone-Token'] = phone;
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
        throw err;
    }

    function header() {
        const name = store.get(NAME_KEY);
        return `<header class="kiosk-head"><div><h1>${esc(t('phone_title'))}</h1>${name && store.get(PHONE_KEY) ? `<p class="kiosk-muted">${esc(t('registered_as', {name}))}</p>` : ''}</div>
            <div class="kiosk-row"><span class="kiosk-clock" data-clock>${new Date().toTimeString().slice(0, 5)}</span>
            <button type="button" class="kiosk-btn is-small" data-lang>${esc(t('lang_switch'))}</button></div></header>`;
    }

    function message(text, error) {
        el.innerHTML = header() + `<section class="kiosk-card kiosk-phone-msg"><p class="${error ? 'kiosk-error' : 'kiosk-status'}">${esc(text)}</p>
            ${error ? `<p class="kiosk-muted">${esc(t('scan_help'))}</p>` : ''}${store.get(PHONE_KEY) ? `<button type="button" class="kiosk-btn is-small" data-forget>${esc(t('not_me'))}</button>` : ''}</section>`;
    }

    function forget() {
        store.set(PHONE_KEY, null);
        store.set(NAME_KEY, null);
    }

    async function start() {
        if (!qr) return message(t('scan_help'));
        if (!store.get(PHONE_KEY)) return renderPeople();
        renderClock();
    }

    // --- Premier scan : nom puis PIN ---------------------------------------------------------------------
    async function renderPeople() {
        el.innerHTML = header() + `<section class="kiosk-card"><p class="kiosk-status">${esc(t('who_are_you'))}</p>
            <input class="kiosk-search" type="search" data-search placeholder="${esc(t('search'))}" aria-label="${esc(t('search'))}" autocomplete="off">
            <div class="kiosk-people" data-people><p class="kiosk-muted">${esc(t('loading'))}</p></div></section>`;
        let people;
        try {
            people = await api('GET', '/kiosk/phone/people');
        } catch (err) { return message(err.message, true); }
        // Osobisty kod QR : jedna osoba — od razu jej PIN (bez PIN-u : prośba do kierownika).
        if (personalQr() && people.length === 1) {
            return people[0].has_pin ? renderPin(people[0].id, people[0].name, true) : message(t('no_pin_personal', {name: people[0].name}), true);
        }
        const box = el.querySelector('[data-people]');
        const draw = (filter) => {
            const f = filter.trim().toLowerCase();
            const list = people.filter(p => !f || p.name.toLowerCase().includes(f));
            box.innerHTML = list.map(p => `<button type="button" class="kiosk-btn kiosk-person" data-person="${p.id}" data-name="${esc(p.name)}" ${p.has_pin ? '' : 'disabled'}>
                ${esc(p.name)}${p.has_pin ? '' : `<small>${esc(t('no_pin'))}</small>`}</button>`).join('');
        };
        draw('');
        el.querySelector('[data-search]').addEventListener('input', (e) => draw(e.target.value));
        box.addEventListener('click', (e) => { const b = e.target.closest('[data-person]'); if (b) renderPin(Number(b.dataset.person), b.dataset.name); });
    }

    function renderPin(employeeId, name, personal) {
        let pin = '';
        el.innerHTML = header() + `<section class="kiosk-card kiosk-pin"><h2>${esc(name)}</h2><p class="kiosk-muted">${esc(t('pin_help'))}</p>
            <div class="kiosk-dots" data-dots aria-hidden="true"></div><p class="kiosk-error" data-error></p>
            <div class="kiosk-pad">${[1, 2, 3, 4, 5, 6, 7, 8, 9].map(d => `<button type="button" class="kiosk-btn" data-digit="${d}">${d}</button>`).join('')}
                <button type="button" class="kiosk-btn" data-back aria-label="${esc(t('erase'))}">⌫</button><button type="button" class="kiosk-btn" data-digit="0">0</button>
                <button type="button" class="kiosk-btn is-primary" data-ok>OK</button></div>
            <button type="button" class="kiosk-btn is-small" data-cancel>${esc(t('cancel'))}</button></section>`;
        const dots = () => { el.querySelector('[data-dots]').innerHTML = Array.from({length: Math.max(4, pin.length)}, (_, i) => `<span class="${i < pin.length ? 'is-on' : ''}"></span>`).join(''); };
        dots();
        el.querySelector('.kiosk-pad').addEventListener('click', async (e) => {
            const b = e.target.closest('button');
            if (!b) return;
            if (b.dataset.digit !== undefined && pin.length < 6) pin += b.dataset.digit;
            if (b.hasAttribute('data-back')) pin = pin.slice(0, -1);
            dots();
            if (!b.hasAttribute('data-ok') || pin.length < 4) return;
            b.disabled = true;
            try {
                const r = await api('POST', '/kiosk/phone/enroll', {employee_id: employeeId, pin});
                store.set(PHONE_KEY, r.phone_token);
                store.set(NAME_KEY, r.employee.name);
                renderClock(t('phone_saved', {name: r.employee.name}));
            } catch (err) {
                pin = ''; dots(); b.disabled = false;
                el.querySelector('[data-error]').textContent = err.message;
            }
        });
        el.querySelector('[data-cancel]').addEventListener('click', () => (personal ? message(t('scan_help')) : renderPeople()));
    }

    // --- Un geste : ENTRÉE / SORTIE ------------------------------------------------------------------------
    async function renderClock(info) {
        el.innerHTML = header() + `<section class="kiosk-card"><p class="kiosk-muted">${esc(t('loading'))}</p></section>`;
        let me;
        try {
            me = await api('GET', '/kiosk/phone/me');
        } catch (err) {
            if (['PHONE_UNAUTHENTICATED', 'PHONE_REVOKED'].includes(err.code)) { forget(); return renderPeople(); }
            if (err.code === 'QR_OTHER_PERSON') return renderOtherPerson(err.message);
            return message(err.message, true);
        }
        store.set(NAME_KEY, me.employee.name);
        const inside = me.clocked_in;
        el.innerHTML = header() + `<section class="kiosk-card kiosk-phone-main">
                <h2>${esc(t('hello', {name: me.employee.name}))}</h2>
                ${info ? `<p class="kiosk-status">${esc(info)}</p>` : ''}
                <p class="kiosk-status">${esc(inside ? t('since', {time: String(me.clock.clock_in).slice(11, 16), hours: fmt(me.clock.hours)}) : t('not_in'))}</p>
                <button type="button" class="kiosk-btn kiosk-big ${inside ? 'is-danger' : 'is-ok'}" data-clock-tap="${inside ? 'out' : 'in'}">${esc(t(inside ? 'clock_out' : 'clock_in'))}</button>
                <p class="kiosk-error" data-error></p>
            </section>
            <button type="button" class="kiosk-btn is-small kiosk-phone-forget" data-forget>${esc(t('not_me'))}</button>`;
        el.querySelector('[data-clock-tap]').addEventListener('click', (e) => {
            const direction = e.currentTarget.dataset.clockTap;
            const questions = me.questions?.[direction] || [];
            const sign = !!me.signature?.[direction];
            if (questions.length || sign) renderQuestions(questions, direction, null, sign);
            else clockTap(e.currentTarget, direction, [], null);
        });
    }

    // Osobisty kod QR innej osoby : ten telefon jest zarejestrowany na kogoś innego. Jeśli to jednak telefon osoby
    // z kodu (nowy właściciel), zapomina poprzednią rejestrację i prosi o PIN osoby z kodu.
    function renderOtherPerson(text) {
        el.innerHTML = header() + `<section class="kiosk-card kiosk-phone-msg"><p class="kiosk-error">${esc(text)}</p>
            <button type="button" class="kiosk-btn" data-forget>${esc(t('register_me'))}</button></section>`;
    }

    // Ekran zatwierdzenia : dotknięcie potwierdza punkt, podpis palcem w ramce ; « OK, zatwierdzam » gdy obowiązkowe
    // punkty są potwierdzone i podpis złożony.
    function renderQuestions(questions, direction, error, sign) {
        const yes = new Set();
        el.innerHTML = header() + `<section class="kiosk-card kiosk-confirm">
                <h2>${esc(t(direction === 'in' ? 'confirm_in' : 'confirm_out'))}</h2>
                ${questions.length ? `<p class="kiosk-muted">${esc(t('confirm_help'))}</p>
                <div class="kiosk-checks">${questions.map(q => `<button type="button" class="kiosk-check" data-q="${q.id}" aria-pressed="false">
                    <span class="kiosk-check-box" aria-hidden="true"></span><span>${esc(q.text)}${q.required ? '' : `<small>${esc(t('optional'))}</small>`}</span></button>`).join('')}</div>` : ''}
                ${sign ? `<div class="kiosk-confirm-sign"><p class="kiosk-status">${esc(t(direction === 'in' ? 'sign_in_here' : 'sign_out_here'))}</p>
                    <canvas class="kiosk-sign" data-pad width="700" height="320" aria-label="${esc(t('signature'))}"></canvas>
                    <div class="kiosk-row is-end"><button type="button" class="kiosk-btn is-small" data-clear>${esc(t('clear'))}</button></div></div>` : ''}
                <p class="kiosk-error" data-error>${esc(error || '')}</p>
                <button type="button" class="kiosk-btn kiosk-big ${direction === 'in' ? 'is-ok' : 'is-danger'}" data-confirm>${esc(t('confirm_ok'))}</button>
                <button type="button" class="kiosk-btn is-small" data-back>${esc(t('back'))}</button>
            </section>`;
        const ok = el.querySelector('[data-confirm]');
        const pad = sign ? signaturePad(el.querySelector('[data-pad]'), () => refresh()) : null;
        const refresh = () => { ok.disabled = questions.some(q => q.required && !yes.has(q.id)) || (pad !== null && pad.empty()); };
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
        el.querySelector('[data-clear]')?.addEventListener('click', () => { pad.clear(); refresh(); });
        ok.addEventListener('click', () => clockTap(ok, direction, questions.map(q => ({question_id: q.id, yes: yes.has(q.id)})), pad ? pad.image() : null));
        el.querySelector('[data-back]').addEventListener('click', () => renderClock());
    }

    // Ramka podpisu palcem (biały PNG) : empty() — nic nie narysowano, image() — data URL.
    function signaturePad(canvas, onDraw) {
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.lineWidth = 3; ctx.lineCap = 'round'; ctx.strokeStyle = '#111';
        let drawing = false;
        let strokes = 0;
        const point = (e) => { const r = canvas.getBoundingClientRect(); return [(e.clientX - r.left) * canvas.width / r.width, (e.clientY - r.top) * canvas.height / r.height]; };
        canvas.addEventListener('pointerdown', (e) => { drawing = true; strokes++; canvas.setPointerCapture(e.pointerId); const [x, y] = point(e); ctx.beginPath(); ctx.moveTo(x, y); });
        canvas.addEventListener('pointermove', (e) => { if (!drawing) return; const [x, y] = point(e); ctx.lineTo(x, y); ctx.stroke(); });
        canvas.addEventListener('pointerup', () => { drawing = false; if (onDraw) onDraw(); });
        return {
            empty: () => strokes === 0,
            clear: () => { ctx.fillRect(0, 0, canvas.width, canvas.height); strokes = 0; },
            image: () => canvas.toDataURL('image/png'),
        };
    }

    async function clockTap(button, direction, answers, signature) {
        button.disabled = true;
        if (!pending || pending.direction !== direction) pending = {uuid: uuid(), direction};
        try {
            const r = await api('POST', '/kiosk/phone/clock', {direction, client_uuid: pending.uuid, answers, ...(signature ? {signature} : {})});
            pending = null;
            if (navigator.vibrate) navigator.vibrate(80);
            message(direction === 'in' ? t('welcome') : t('bye', {hours: fmt(r.clock.hours)}));
        } catch (err) {
            button.disabled = false;
            if (['PHONE_UNAUTHENTICATED', 'PHONE_REVOKED'].includes(err.code)) { forget(); return renderPeople(); }
            // Hors ligne : on garde l'écran (même client_uuid, pas de doublon) ; sinon message (QR expiré…).
            if (err.offline) { el.querySelector('[data-error]').textContent = t('offline_retry'); return; }
            // Pytania albo podpis zmienione w międzyczasie : aktualny ekran z komunikatem.
            if (err.code === 'CLOCK_QUESTIONS_REQUIRED' || err.fields?.includes('signature')) {
                const me = await api('GET', '/kiosk/phone/me').catch(() => null);
                if (me) return renderQuestions(me.questions?.[direction] || [], direction, err.message, !!me.signature?.[direction]);
            }
            message(err.message, true);
        }
    }

    el.addEventListener('click', async (e) => {
        if (e.target.closest('[data-lang]')) {
            lang = lang === 'pl' ? 'fr' : 'pl';
            store.set(LANG_KEY, lang);
            await loadLabels();
            start();
        }
        if (e.target.closest('[data-forget]')) {
            forget();
            if (qr) renderPeople(); else message(t('forgotten'));
        }
    });
    window.addEventListener('hashchange', () => { const token = readQr(); if (token) { qr = token; pending = null; start(); } });
    window.setInterval(() => { const c = el.querySelector('[data-clock]'); if (c) c.textContent = new Date().toTimeString().slice(0, 5); }, 10000);
    loadLabels().then(start);
})();
