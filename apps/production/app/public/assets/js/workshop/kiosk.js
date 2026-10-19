/*
 * Tablet produkcji / Tablette de l'atelier (P7).
 * Parowanie kodem od kierownika (token tabletu w pamięci przeglądarki), identyfikacja osoby :
 * identyfikator NFC / QR (czytnik działający jak klawiatura albo Web NFC) lub dotknięcie nazwiska,
 * potem PIN ; wejście / wyjście jednym dotknięciem, operacje (start, pauza, koniec z ilościami),
 * podpis odręczny (wyjście, karta miesiąca). Wylogowanie po bezczynności. Na ekranie głównym kod QR
 * (zmieniany co chwilę) do wejścia / wyjścia własnym telefonem. Wszystko przez API.
 */
(function () {
    'use strict';
    const el = document.getElementById('tfbKiosk');
    if (!el) return;
    const base = (el.dataset.apiBase || '').replace(/\/$/, '');
    const root = (el.dataset.root || '').replace(/\/$/, '');
    const DEVICE_KEY = 'tfb.kiosk.device';
    const LANG_KEY = 'tfb.lang';
    const store = {
        get(k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
        set(k, v) { try { v === null ? window.localStorage.removeItem(k) : window.localStorage.setItem(k, v); } catch (e) { /* stockage indisponible */ } },
    };
    let lang = store.get(LANG_KEY) === 'fr' ? 'fr' : 'pl';
    let labels = {};
    let device = store.get(DEVICE_KEY);
    let session = null; // {token, name, id}
    let idleTimer = null;
    let idleSeconds = 45;
    let pending = null; // {uuid, direction} : dernier pointage à rejouer après une coupure réseau
    let qrTimer = null;

    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const t = (k, p) => { let s = labels[k] ?? k; for (const [a, b] of Object.entries(p || {})) s = s.split('{' + a + '}').join(String(b)); return s; };
    const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16); }));
    const hhmm = (s) => String(s || '').slice(11, 16);
    const fmt = (n) => Number(n).toLocaleString(lang === 'fr' ? 'fr-FR' : 'pl-PL', {maximumFractionDigits: 2});

    async function loadLabels() {
        const res = await fetch(`${root}/resources/lang/ui/${lang}/kiosk.json`, {credentials: 'same-origin'});
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

    async function api(method, path, body, withEmployee) {
        const headers = {'Accept': 'application/json', 'Accept-Language': lang, 'X-Device-Token': device || ''};
        if (body !== undefined) headers['Content-Type'] = 'application/json';
        if (withEmployee && session) headers['X-Employee-Token'] = session.token;
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
        if (['DEVICE_UNAUTHENTICATED', 'DEVICE_DISABLED'].includes(err.code)) {
            if (err.code === 'DEVICE_UNAUTHENTICATED') { device = null; store.set(DEVICE_KEY, null); }
            session = null;
            renderPairing(err.message);
        } else if (['EMPLOYEE_SESSION_EXPIRED', 'EMPLOYEE_UNAUTHENTICATED'].includes(err.code) && withEmployee) {
            logout(err.message);
        }
        throw err;
    }

    function header(right) {
        return `<header class="kiosk-head"><div><h1>${esc(t('title'))}</h1><p class="kiosk-muted">${esc(new Date().toLocaleDateString(lang === 'fr' ? 'fr-FR' : 'pl-PL', {weekday: 'long', day: 'numeric', month: 'long'}))}</p></div>
            <div class="kiosk-row"><span class="kiosk-clock" data-clock>${new Date().toTimeString().slice(0, 5)}</span>${right || ''}
            <button type="button" class="kiosk-btn is-small" data-lang>${esc(t('lang_switch'))}</button></div></header>`;
    }

    function resetIdle() {
        window.clearTimeout(idleTimer);
        if (session) idleTimer = window.setTimeout(() => logout(t('idle_logout')), idleSeconds * 1000);
    }

    function logout(message) {
        session = null;
        window.clearTimeout(idleTimer);
        renderHome();
        if (message) toast(message);
    }

    // --- Parowanie --------------------------------------------------------------------------------
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
                renderHome();
            } catch (err) { el.querySelector('[data-error]').textContent = err.message; }
        });
        el.querySelector('[name=code]').focus();
    }

    // --- Accueil : badge ou nom -----------------------------------------------------------------------
    async function renderHome() {
        if (!device) return renderPairing();
        el.innerHTML = header() + `<section class="kiosk-card"><div class="kiosk-badge-hint"><span aria-hidden="true">🪪</span><strong>${esc(t('badge_hint'))}</strong>
                ${'NDEFReader' in window ? `<button type="button" class="kiosk-btn is-small" data-nfc>${esc(t('nfc'))}</button>` : ''}</div>
                <input class="kiosk-badge-input" data-badge autocomplete="off" aria-label="${esc(t('badge'))}"></section>
            <section class="kiosk-card kiosk-qr" data-qr-card hidden><canvas data-qr width="220" height="220" role="img" aria-label="${esc(t('qr_title'))}"></canvas>
                <div class="kiosk-qr-text"><h2>${esc(t('qr_title'))}</h2><p class="kiosk-muted">${esc(t('qr_help'))}</p></div></section>
            <section class="kiosk-card"><h2>${esc(t('or_name'))}</h2><div class="kiosk-people" data-people><p class="kiosk-muted">${esc(t('loading'))}</p></div></section>`;
        const badge = el.querySelector('[data-badge]');
        const focusBadge = () => { if (document.activeElement !== badge && !el.querySelector('input:not([data-badge])')) badge.focus({preventScroll: true}); };
        focusBadge();
        badge.addEventListener('keydown', (e) => { if (e.key === 'Enter' && badge.value.trim()) { e.preventDefault(); const v = badge.value.trim(); badge.value = ''; renderPin({badge: v}); } });
        el.addEventListener('click', focusBadge, {once: true});
        const nfc = el.querySelector('[data-nfc]');
        if (nfc) nfc.addEventListener('click', async () => {
            try {
                const reader = new window.NDEFReader();
                await reader.scan();
                toast(t('nfc_ready'));
                reader.addEventListener('reading', (ev) => { if (!session) renderPin({badge: ev.serialNumber}); }, {once: true});
            } catch (e) { toast(t('nfc_error'), true); }
        });
        drawQr();
        try {
            const people = await api('GET', '/kiosk/people');
            const box = el.querySelector('[data-people]');
            if (!box) return;
            box.innerHTML = people.length ? people.map(p => `<button type="button" class="kiosk-btn kiosk-person ${p.clocked_in ? 'is-in' : ''}" data-person="${p.id}" data-name="${esc(p.name)}">
                ${esc(p.name)}<small>${esc(p.clocked_in ? t('present') : (p.has_pin ? t('absent') : t('no_pin')))}</small></button>`).join('') : `<p class="kiosk-muted">${esc(t('nobody'))}</p>`;
            box.addEventListener('click', (e) => { const b = e.target.closest('[data-person]'); if (b) renderPin({employee_id: Number(b.dataset.person), name: b.dataset.name}); });
        } catch (err) { if (!err.code) toast(err.message, true); }
    }

    // Kod QR wejścia / wyjścia telefonem : nowy co refresh_s sekund, tylko na ekranie głównym.
    async function drawQr() {
        const canvas = el.querySelector('[data-qr]');
        if (!canvas) { window.clearInterval(qrTimer); qrTimer = null; return; }
        try {
            // root : adresse absolue ou chemin de l'application → adresse absolue de la page du téléphone.
            const q = await api('GET', '/kiosk/qr?base=' + encodeURIComponent(new URL(root + '/', window.location.href).href.replace(/\/$/, '')));
            const quiet = 4;
            const total = q.size + 2 * quiet;
            const scale = Math.max(2, Math.floor(440 / total));
            canvas.width = canvas.height = total * scale;
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.fillStyle = '#000';
            q.rows.forEach((row, y) => { for (let x = 0; x < row.length; x++) if (row[x] === '1') ctx.fillRect((x + quiet) * scale, (y + quiet) * scale, scale, scale); });
            canvas.closest('[data-qr-card]').hidden = false;
            if (!qrTimer) qrTimer = window.setInterval(drawQr, Math.max(5, q.refresh_s) * 1000);
        } catch (err) {
            const card = el.querySelector('[data-qr-card]');
            if (card) card.hidden = true;
        }
    }

    // --- PIN -------------------------------------------------------------------------------------------
    function renderPin(who) {
        let pin = '';
        el.innerHTML = header() + `<section class="kiosk-card kiosk-pin"><h2>${esc(who.name || t('badge_read'))}</h2><p class="kiosk-muted">${esc(t('pin_help'))}</p>
            <div class="kiosk-dots" data-dots aria-hidden="true"></div><p class="kiosk-error" data-error></p>
            <div class="kiosk-pad">${[1, 2, 3, 4, 5, 6, 7, 8, 9].map(d => `<button type="button" class="kiosk-btn" data-digit="${d}">${d}</button>`).join('')}
                <button type="button" class="kiosk-btn" data-back aria-label="${esc(t('erase'))}">⌫</button><button type="button" class="kiosk-btn" data-digit="0">0</button>
                <button type="button" class="kiosk-btn is-primary" data-ok>OK</button></div>
            <button type="button" class="kiosk-btn is-small" data-cancel>${esc(t('cancel'))}</button></section>`;
        const dots = () => { el.querySelector('[data-dots]').innerHTML = Array.from({length: Math.max(4, pin.length)}, (_, i) => `<span class="${i < pin.length ? 'is-on' : ''}"></span>`).join(''); };
        dots();
        const submit = async () => {
            if (pin.length < 4) return;
            const ok = el.querySelector('[data-ok]');
            ok.disabled = true;
            try {
                const r = await api('POST', '/kiosk/identify', {...(who.badge ? {badge: who.badge} : {employee_id: who.employee_id}), pin});
                session = {token: r.employee_token, id: r.employee.id, name: r.employee.name};
                renderMe();
            } catch (err) {
                pin = ''; dots(); ok.disabled = false;
                el.querySelector('[data-error]').textContent = err.message;
            }
        };
        el.querySelector('.kiosk-pad').addEventListener('click', (e) => {
            const b = e.target.closest('button');
            if (!b) return;
            if (b.dataset.digit !== undefined && pin.length < 6) pin += b.dataset.digit;
            if (b.hasAttribute('data-back')) pin = pin.slice(0, -1);
            dots();
            if (b.hasAttribute('data-ok')) submit();
        });
        document.addEventListener('keydown', function keys(e) {
            if (!el.querySelector('.kiosk-pad')) { document.removeEventListener('keydown', keys); return; }
            if (/^\d$/.test(e.key) && pin.length < 6) { pin += e.key; dots(); }
            if (e.key === 'Backspace') { pin = pin.slice(0, -1); dots(); }
            if (e.key === 'Enter') submit();
        });
        el.querySelector('[data-cancel]').addEventListener('click', () => renderHome());
        window.clearTimeout(idleTimer);
        idleTimer = window.setTimeout(() => renderHome(), 30000);
    }

    // --- Ma journée ----------------------------------------------------------------------------------
    async function renderMe(message) {
        let me;
        try { me = await api('GET', '/kiosk/me', undefined, true); } catch (err) { if (!err.code) toast(err.message, true); return; }
        idleSeconds = me.idle_logout_s || 45;
        resetIdle();
        const working = new Set(me.working_on);
        el.innerHTML = header(`<button type="button" class="kiosk-btn is-small" data-logout>${esc(t('logout'))}</button>`) + `
            <section class="kiosk-card"><h2>${esc(t('hello', {name: me.employee.name}))}</h2>
                <button type="button" class="kiosk-btn kiosk-big ${me.clocked_in ? 'is-danger' : 'is-ok'}" data-clock-tap>
                    ${esc(me.clocked_in ? t('clock_out') : t('clock_in'))}</button>
                <p class="kiosk-status">${me.clocked_in ? esc(t('since', {time: hhmm(me.clock.clock_in), hours: fmt(me.clock.hours)})) : esc(t('not_in'))}</p>
                <div class="kiosk-row"><button type="button" class="kiosk-btn is-small" data-sign-month>${esc(t('sign_month'))}</button></div></section>
            <section class="kiosk-card"><h2>${esc(t('operations'))}</h2><div class="kiosk-ops">
                ${me.operations.length ? me.operations.map(o => `<article class="kiosk-card kiosk-op is-${esc(o.status)} ${o.late ? 'is-late' : ''}" data-op="${o.id}">
                    <div><h3>${esc(o.order_code)} · ${esc(o.item)}</h3>
                        <div class="kiosk-muted">${o.step_no ? esc(o.step_no) + '. ' : ''}${esc(o.step || t('kind.' + o.kind))} · ${esc(o.workstation)} · ${fmt(o.quantity)} ${esc(o.unit)}</div>
                        <div class="kiosk-op-time">${esc(hhmm(o.start))}–${esc(hhmm(o.end))} · ${esc(t('status.' + o.status))}${working.has(o.id) ? ' · ' + esc(t('you_work')) : ''}</div></div>
                    <div class="kiosk-op-actions">${actions(o, working.has(o.id), me.clocked_in)}</div></article>`).join('') : `<p class="kiosk-muted">${esc(t('no_operations'))}</p>`}
            </div></section>`;
        if (message) toast(message);
        el.querySelector('[data-logout]').addEventListener('click', () => logout());
        el.querySelector('[data-clock-tap]').addEventListener('click', (e) => clockTap(e.currentTarget, me.clocked_in ? 'out' : 'in'));
        el.querySelector('[data-sign-month]').addEventListener('click', () => renderSignature('timesheet_month', new Date().toISOString().slice(0, 7), t('sign_month_help')));
        el.querySelectorAll('[data-op]').forEach(card => card.addEventListener('click', (e) => {
            const b = e.target.closest('[data-action]');
            if (!b) return;
            const op = me.operations.find(o => o.id === Number(card.dataset.op));
            if (b.dataset.action === 'finish') return renderFinish(op);
            opAction(b, op.id, b.dataset.action);
        }));
    }

    function actions(o, mine, clockedIn) {
        if (o.status === 'done') return `<span class="kiosk-muted">✓ ${esc(fmt(o.qty_good ?? 0))}</span>`;
        const out = [];
        if (!mine) out.push(`<button type="button" class="kiosk-btn is-ok is-small" data-action="start" ${clockedIn ? '' : 'disabled'}>${esc(o.status === 'planned' ? t('start') : t('resume'))}</button>`);
        if (mine) out.push(`<button type="button" class="kiosk-btn is-small" data-action="pause">${esc(t('pause'))}</button>`);
        if (o.status !== 'planned') out.push(`<button type="button" class="kiosk-btn is-primary is-small" data-action="finish">${esc(t('finish'))}</button>`);
        return out.join('');
    }

    async function clockTap(button, direction) {
        button.disabled = true;
        if (!pending || pending.direction !== direction) pending = {uuid: uuid(), direction};
        try {
            const r = await api('POST', '/kiosk/clock', {direction, client_uuid: pending.uuid}, true);
            pending = null;
            if (direction === 'out' && r.signature_required) return renderSignature('clock_out', String(r.clock.id), t('sign_out_help', {hours: fmt(r.clock.hours)}), true);
            renderMe(direction === 'in' ? t('welcome') : t('bye', {hours: fmt(r.clock.hours)}));
        } catch (err) {
            button.disabled = false;
            toast(err.offline ? t('offline_retry') : err.message, true);
        }
    }

    async function opAction(button, id, action) {
        button.disabled = true;
        try {
            await api('POST', `/kiosk/operations/${id}/${action}`, action === 'start' ? {client_uuid: uuid()} : undefined, true);
            renderMe(t('saved'));
        } catch (err) { button.disabled = false; toast(err.message, true); }
    }

    function renderFinish(op) {
        resetIdle();
        el.innerHTML = header() + `<section class="kiosk-card"><h2>${esc(t('finish_title', {code: op.order_code, item: op.item}))}</h2>
            <form class="kiosk-form" data-finish>
                <label>${esc(t('qty_good'))} (${esc(op.unit)})<input name="qty_good" inputmode="decimal" value="${esc(op.quantity)}" required></label>
                <label>${esc(t('qty_scrap'))}<input name="qty_scrap" inputmode="decimal" value="0"></label>
                <label>${esc(t('scrap_reason'))}<input name="scrap_reason" maxlength="255"></label>
            </form><p class="kiosk-error" data-error></p>
            <div class="kiosk-row is-end"><button type="button" class="kiosk-btn" data-back>${esc(t('cancel'))}</button><button type="button" class="kiosk-btn is-primary" data-save>${esc(t('finish'))}</button></div></section>`;
        const form = el.querySelector('[data-finish]');
        form.addEventListener('input', resetIdle);
        el.querySelector('[data-back]').addEventListener('click', () => renderMe());
        el.querySelector('[data-save]').addEventListener('click', async (e) => {
            e.target.disabled = true;
            const num = (v) => Number(String(v).replace(',', '.'));
            try {
                await api('POST', `/kiosk/operations/${op.id}/finish`, {qty_good: num(form.qty_good.value), qty_scrap: num(form.qty_scrap.value || 0), scrap_reason: form.scrap_reason.value.trim() || null}, true);
                renderMe(t('saved'));
            } catch (err) { e.target.disabled = false; el.querySelector('[data-error]').textContent = err.message; }
        });
    }

    // --- Podpis ----------------------------------------------------------------------------------------
    function renderSignature(purpose, ref, help, logoutAfter) {
        resetIdle();
        el.innerHTML = header() + `<section class="kiosk-card"><h2>${esc(t('signature'))}</h2><p class="kiosk-status">${esc(help)}</p>
            <canvas class="kiosk-sign" data-pad width="900" height="260" aria-label="${esc(t('signature'))}"></canvas><p class="kiosk-error" data-error></p>
            <div class="kiosk-row is-end"><button type="button" class="kiosk-btn" data-clear>${esc(t('clear'))}</button>
                ${logoutAfter ? `<button type="button" class="kiosk-btn" data-skip>${esc(t('later'))}</button>` : `<button type="button" class="kiosk-btn" data-skip>${esc(t('cancel'))}</button>`}
                <button type="button" class="kiosk-btn is-primary" data-save>${esc(t('sign'))}</button></div></section>`;
        const canvas = el.querySelector('[data-pad]');
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.lineWidth = 3; ctx.lineCap = 'round'; ctx.strokeStyle = '#111';
        let drawing = false;
        let strokes = 0;
        const point = (e) => { const r = canvas.getBoundingClientRect(); return [(e.clientX - r.left) * canvas.width / r.width, (e.clientY - r.top) * canvas.height / r.height]; };
        canvas.addEventListener('pointerdown', (e) => { drawing = true; strokes++; canvas.setPointerCapture(e.pointerId); const [x, y] = point(e); ctx.beginPath(); ctx.moveTo(x, y); resetIdle(); });
        canvas.addEventListener('pointermove', (e) => { if (!drawing) return; const [x, y] = point(e); ctx.lineTo(x, y); ctx.stroke(); });
        canvas.addEventListener('pointerup', () => { drawing = false; });
        el.querySelector('[data-clear]').addEventListener('click', () => { ctx.fillRect(0, 0, canvas.width, canvas.height); strokes = 0; });
        el.querySelector('[data-skip]').addEventListener('click', () => logoutAfter ? logout(t('bye_short')) : renderMe());
        el.querySelector('[data-save]').addEventListener('click', async (e) => {
            if (!strokes) { el.querySelector('[data-error]').textContent = t('sign_first'); return; }
            e.target.disabled = true;
            try {
                await api('POST', '/kiosk/signatures', {purpose, ref, image: canvas.toDataURL('image/png')}, true);
                if (logoutAfter) logout(t('signed_bye')); else renderMe(t('signed'));
            } catch (err) { e.target.disabled = false; el.querySelector('[data-error]').textContent = err.message; }
        });
    }

    // --- Commun ----------------------------------------------------------------------------------------
    el.addEventListener('click', async (e) => {
        if (e.target.closest('[data-lang]')) {
            lang = lang === 'pl' ? 'fr' : 'pl';
            store.set(LANG_KEY, lang);
            await loadLabels();
            session ? renderMe() : renderHome();
        }
    });
    ['pointerdown', 'keydown'].forEach(ev => document.addEventListener(ev, () => { if (session) resetIdle(); }, {passive: true}));
    window.setInterval(() => { const c = el.querySelector('[data-clock]'); if (c) c.textContent = new Date().toTimeString().slice(0, 5); }, 10000);
    loadLabels().then(() => (device ? renderHome() : renderPairing()));
})();
