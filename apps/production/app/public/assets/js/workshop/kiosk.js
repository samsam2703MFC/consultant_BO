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
    let session = null; // {token, name, id, exp}
    let longIdle = false; // ekrany produkcji / partii : zdjęcia i wpisywanie trwają dłużej
    let busy = false; // aparat lub OCR w toku : bez wylogowania
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
        if (withEmployee && path !== '/kiosk/refresh') await keepSession();
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
        if (session) idleTimer = window.setTimeout(() => (busy ? resetIdle() : logout(t('idle_logout'))), (longIdle ? Math.max(idleSeconds, 300) : idleSeconds) * 1000);
    }

    // Jeton osoby jest krótki (kiosk.employee_token_ttl_s) : przedłużany, póki osoba jest na tablecie.
    async function keepSession() {
        if (!session || !session.exp || Date.now() < session.exp - 70000) return;
        try {
            const r = await api('POST', '/kiosk/refresh', undefined, true);
            session.token = r.employee_token;
            session.exp = Date.now() + r.expires_in * 1000;
        } catch (e) { /* błąd sieci : spróbujemy przy następnym wywołaniu */ }
    }

    function logout(message) {
        session = null;
        longIdle = false;
        busy = false;
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
                session = {token: r.employee_token, id: r.employee.id, name: r.employee.name, exp: Date.now() + r.expires_in * 1000};
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

    // --- Menu osoby : Czas pracy / Produkcja / Kontrola jakości ---------------------------------------
    const TABS = [['clock', '⏱', 'tab_clock'], ['production', '📦', 'tab_production'], ['quality', '✅', 'tab_quality']];
    const personHeader = () => header(`<button type="button" class="kiosk-btn is-small" data-logout>${esc(t('logout'))}</button>`);
    function tabs(active) {
        if (session) session.tab = active;
        return `<nav class="kiosk-tabs" role="tablist" aria-label="${esc(t('tabs'))}">${TABS.map(([k, icon, label]) => `<button type="button" class="kiosk-tab ${k === active ? 'is-active' : ''}"
            role="tab" aria-selected="${k === active}" data-tab="${k}"><span aria-hidden="true">${icon}</span> ${esc(t(label))}</button>`).join('')}</nav>`;
    }
    function bindTabs() {
        el.querySelectorAll('[data-tab]').forEach(b => b.addEventListener('click', () => { if (!b.classList.contains('is-active')) openTab(b.dataset.tab); }));
        el.querySelector('[data-logout]')?.addEventListener('click', () => logout());
    }
    function openTab(k, message) {
        return k === 'production' ? renderProduction(message) : (k === 'quality' ? renderQuality(message) : renderMe(message));
    }

    // --- Czas pracy (pointeuse) ----------------------------------------------------------------------
    async function renderMe(message) {
        longIdle = false;
        let me;
        try { me = await api('GET', '/kiosk/me', undefined, true); } catch (err) { if (!err.code) toast(err.message, true); return; }
        idleSeconds = me.idle_logout_s || 45;
        resetIdle();
        el.innerHTML = personHeader() + tabs('clock') + `
            <section class="kiosk-card"><h2>${esc(t('hello', {name: me.employee.name}))}</h2>
                <button type="button" class="kiosk-btn kiosk-big ${me.clocked_in ? 'is-danger' : 'is-ok'}" data-clock-tap>
                    ${esc(me.clocked_in ? t('clock_out') : t('clock_in'))}</button>
                <p class="kiosk-status">${me.clocked_in ? esc(t('since', {time: hhmm(me.clock.clock_in), hours: fmt(me.clock.hours)})) : esc(t('not_in'))}</p>
                <div class="kiosk-row"><button type="button" class="kiosk-btn is-small" data-sign-month>${esc(t('sign_month'))}</button></div></section>`;
        if (message) toast(message);
        bindTabs();
        el.querySelector('[data-clock-tap]').addEventListener('click', (e) => clockTap(e.currentTarget, me.clocked_in ? 'out' : 'in'));
        el.querySelector('[data-sign-month]').addEventListener('click', () => renderSignature('timesheet_month', new Date().toISOString().slice(0, 7), t('sign_month_help')));
    }

    // Operacje zleceń osoby (start, pauza, koniec) : w zakładce « Produkcja », gdy są.
    function operationsCard(me) {
        if (!me || !me.operations.length) return '';
        const working = new Set(me.working_on);
        return `<section class="kiosk-card"><h2>${esc(t('operations'))}</h2><div class="kiosk-ops">
            ${me.operations.map(o => `<article class="kiosk-card kiosk-op is-${esc(o.status)} ${o.late ? 'is-late' : ''}" data-op="${o.id}">
                <div><h3>${esc(o.order_code)} · ${esc(o.item)}</h3>
                    <div class="kiosk-muted">${o.step_no ? esc(o.step_no) + '. ' : ''}${esc(o.step || t('kind.' + o.kind))} · ${esc(o.workstation)} · ${fmt(o.quantity)} ${esc(o.unit)}</div>
                    <div class="kiosk-op-time">${esc(hhmm(o.start))}–${esc(hhmm(o.end))} · ${esc(t('status.' + o.status))}${working.has(o.id) ? ' · ' + esc(t('you_work')) : ''}</div></div>
                <div class="kiosk-op-actions">${actions(o, working.has(o.id), me.clocked_in)}</div></article>`).join('')}
        </div></section>`;
    }
    function bindOperations(me) {
        if (!me) return;
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
            renderProduction(t('saved'));
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
        el.querySelector('[data-back]').addEventListener('click', () => renderProduction());
        el.querySelector('[data-save]').addEventListener('click', async (e) => {
            e.target.disabled = true;
            const num = (v) => Number(String(v).replace(',', '.'));
            try {
                await api('POST', `/kiosk/operations/${op.id}/finish`, {qty_good: num(form.qty_good.value), qty_scrap: num(form.qty_scrap.value || 0), scrap_reason: form.scrap_reason.value.trim() || null}, true);
                renderProduction(t('saved'));
            } catch (err) { e.target.disabled = false; el.querySelector('[data-error]').textContent = err.message; }
        });
    }


    // --- Produkcja dnia i partie (identyfikowalność) -------------------------------------------------
    const qty = (q, item) => {
        if (q === null || q === undefined) return '—';
        const base = `${fmt(q)} ${item.unit}`;
        return item.ppc ? `${base} (${fmt(Math.round(q / item.ppc * 10) / 10)} ${t('prod_cartons_short')})` : base;
    };

    // Ilość surowca : gramy bez miejsc po przecinku, małe ilości dokładniej.
    const amount = (v) => Number(v).toLocaleString(lang === 'fr' ? 'fr-FR' : 'pl-PL', {maximumFractionDigits: Math.abs(v) >= 100 ? 0 : (Math.abs(v) >= 10 ? 1 : 2)});

    async function renderProduction(message) {
        longIdle = true;
        resetIdle();
        let day, me;
        try {
            [day, me] = await Promise.all([api('GET', '/kiosk/production', undefined, true), api('GET', '/kiosk/me', undefined, true).catch(() => null)]);
        } catch (err) { if (!err.code || err.code === 'DEVICE_WITHOUT_GROUP') toast(err.message, true); return; }
        const others = day.catalog.filter(c => !day.items.some(i => i.item_id === c.item_id));
        el.innerHTML = personHeader() + tabs('production') + `
            <section class="kiosk-card"><h2>${esc(t('prod_title', {group: day.group.name}))}</h2>
                <p class="kiosk-muted">${esc(t('prod_help'))}</p></section>
            <div class="kiosk-prod">${day.items.length ? day.items.map(i => {
                const left = i.planned !== null ? Math.max(0, i.planned - i.produced) : null;
                const pct = i.planned ? Math.min(100, Math.round(i.produced / i.planned * 100)) : null;
                return `<article class="kiosk-card kiosk-prod-item ${left === 0 ? 'is-done' : ''}" data-item="${i.item_id}">
                    <div class="kiosk-prod-head"><div><h3>${esc(i.name)}</h3>
                        <div class="kiosk-muted">${i.planned !== null ? esc(t('prod_planned', {q: qty(i.planned, i)})) + ' · ' : ''}${esc(t('prod_done', {q: qty(i.produced, i)}))}</div></div>
                        ${day.editable ? `<button type="button" class="kiosk-btn is-ok" data-new="${i.item_id}">＋ ${esc(t('prod_batch'))}</button>` : ''}</div>
                    ${pct !== null ? `<div class="kiosk-bar"><span style="width:${pct}%"></span></div>` : ''}
                    ${batchTiles(i)}
                    ${i.batches.length ? `<ul class="kiosk-batches">${i.batches.map(b => `<li class="${b.cancelled ? 'is-cancelled' : ''}">
                        <button type="button" class="kiosk-batch" data-batch="${b.id}"><strong>${esc(b.lot_code)}</strong>
                        <span>${esc(qty(b.quantity, i))}</span><span class="kiosk-muted">${esc(hhmm(b.created_at))}${b.employee ? ' · ' + esc(b.employee) : ''}</span>
                        <span class="kiosk-lots ${b.cancelled ? '' : (b.lots_total && b.lots_done >= b.lots_total ? 'is-ok' : 'is-todo')}">${b.cancelled ? esc(t('prod_cancelled')) : esc(t('prod_lots', {done: b.lots_done ?? 0, total: b.lots_total ?? 0}))}</span></button></li>`).join('')}</ul>` : ''}
                </article>`;
            }).join('') : `<section class="kiosk-card"><p class="kiosk-muted">${esc(t('prod_nothing'))}</p></section>`}</div>
            ${day.editable && others.length ? `<section class="kiosk-card"><h2>${esc(t('prod_other'))}</h2><div class="kiosk-row">
                <select class="kiosk-select" data-other><option value="">—</option>${others.map(c => `<option value="${c.item_id}">${esc(c.name)}</option>`).join('')}</select>
                <button type="button" class="kiosk-btn is-ok" data-other-go>＋ ${esc(t('prod_batch'))}</button></div></section>` : ''}
            ${operationsCard(me)}`;
        if (message) toast(message);
        const find = (id) => day.items.find(i => i.item_id === id) || day.catalog.find(c => c.item_id === id);
        bindTabs();
        bindOperations(me);
        el.querySelectorAll('[data-new]').forEach(b => b.addEventListener('click', () => renderNewBatch(find(Number(b.dataset.new)))));
        el.querySelectorAll('[data-batch]').forEach(b => b.addEventListener('click', () => renderBatch(Number(b.dataset.batch))));
        const go = el.querySelector('[data-other-go]');
        if (go) go.addEventListener('click', () => { const v = Number(el.querySelector('[data-other]').value); if (v) renderNewBatch(find(v)); });
    }

    // Partia produkcyjna : kafelka na każdą zaplanowaną partię (zrobione ✓ według ilości zapisanej na tablecie).
    function batchTiles(i) {
        if (!i.batch || !i.planned) return '';
        const total = Math.ceil(i.planned / i.batch - 1e-6);
        const done = Math.min(total, Math.floor((i.produced || 0) / i.batch + 1e-6));
        if (total > 30) return `<p class="kiosk-muted">${esc(t('prod_batches', {done, total, size: qty(i.batch, i)}))}</p>`;
        return `<div class="kiosk-batch-tiles" aria-label="${esc(t('prod_batches', {done, total, size: qty(i.batch, i)}))}">${Array.from({length: total}, (_, k) =>
            `<span class="kiosk-batch-tile ${k < done ? 'is-done' : ''}">${k < done ? '✓ ' : ''}${k + 1}/${total}</span>`).join('')}
            <span class="kiosk-muted">${esc(t('prod_batch_size', {size: qty(i.batch, i)}))}</span></div>`;
    }

    // --- Kontrola jakości : każda partia dnia OK albo niezgodność (rejestr niezgodności ERP) -------------
    async function renderQuality(message) {
        longIdle = true;
        resetIdle();
        let q;
        try { q = await api('GET', '/kiosk/quality', undefined, true); } catch (err) { if (!err.code || err.code === 'DEVICE_WITHOUT_GROUP') toast(err.message, true); return; }
        const todo = q.batches.filter(b => !b.qc).length;
        el.innerHTML = personHeader() + tabs('quality') + `
            <section class="kiosk-card"><h2>${esc(t('qc_title', {group: q.group.name}))}</h2>
                <p class="kiosk-muted">${esc(t('qc_help'))}</p>
                <div class="kiosk-row"><button type="button" class="kiosk-btn is-danger" data-report>⚠ ${esc(t('qc_report'))}</button></div></section>
            <section class="kiosk-card"><h2>${esc(t('qc_batches', {todo, total: q.batches.length}))}</h2>
                ${q.batches.length ? `<ul class="kiosk-qc">${q.batches.map(b => `<li class="kiosk-qc-row ${b.qc ? 'is-' + esc(b.qc.status) : ''}">
                    <div><strong>${esc(b.lot_code)}</strong> · ${esc(b.name)}
                        <div class="kiosk-muted">${esc(qty(b.quantity, b))} · ${esc(hhmm(b.created_at))}${b.employee ? ' · ' + esc(b.employee) : ''}</div>
                        ${b.qc ? `<div class="kiosk-qc-state">${b.qc.status === 'ok' ? '✓ ' + esc(t('qc_done_ok')) : '✗ ' + esc(t('qc_done_nc', {number: b.qc.nc_number || ''}))} · ${esc(hhmm(b.qc.at))}${b.qc.employee ? ' · ' + esc(b.qc.employee) : ''}${b.qc.note ? ' · ' + esc(b.qc.note) : ''}</div>` : ''}</div>
                    <div class="kiosk-qc-actions">${b.qc && b.qc.status === 'nc' ? '' : `${b.qc ? '' : `<button type="button" class="kiosk-btn is-ok" data-qc-ok="${b.id}">✓ ${esc(t('qc_ok'))}</button>`}
                        <button type="button" class="kiosk-btn is-danger is-small" data-qc-nc="${b.id}">✗ ${esc(t('qc_nc'))}</button>`}</div></li>`).join('')}</ul>`
                    : `<p class="kiosk-muted">${esc(t('qc_no_batches'))}</p>`}</section>
            <section class="kiosk-card"><h2>${esc(t('qc_reports'))}</h2>
                ${q.reports.length ? `<ul class="kiosk-qc">${q.reports.map(r => `<li class="kiosk-qc-row is-report">
                    <div><strong>${esc(r.number)}</strong> · ${esc(r.reported_at)}${r.product ? ' · ' + esc(r.product) : ''}<div class="kiosk-muted">${esc(r.description)}</div></div>
                    <span class="kiosk-qc-badge is-${esc(r.severity)}">${esc(t('sev_' + r.severity))} · ${esc(t('nc_' + r.status))}</span></li>`).join('')}</ul>`
                    : `<p class="kiosk-muted">${esc(t('qc_no_reports'))}</p>`}</section>`;
        if (message) toast(message);
        bindTabs();
        el.querySelector('[data-report]').addEventListener('click', () => renderNcForm(q, null));
        el.querySelectorAll('[data-qc-ok]').forEach(b => b.addEventListener('click', async () => {
            b.disabled = true;
            try { await api('POST', `/kiosk/quality/batches/${b.dataset.qcOk}`, {result: 'ok'}, true); renderQuality(t('qc_saved_ok')); }
            catch (err) { b.disabled = false; toast(err.message, true); }
        }));
        el.querySelectorAll('[data-qc-nc]').forEach(b => b.addEventListener('click', () => renderNcForm(q, q.batches.find(x => x.id === Number(b.dataset.qcNc)))));
    }

    // Niezgodność partii (z kontroli) albo zgłoszona osobno : opis, waga, produkt i ilość (bez partii).
    function renderNcForm(q, batch) {
        resetIdle();
        let severity = 'minor';
        el.innerHTML = header() + `<section class="kiosk-card"><h2>${esc(batch ? t('qc_nc_batch', {lot: batch.lot_code, name: batch.name}) : t('qc_report'))}</h2>
            <form class="kiosk-form kiosk-nc-form" data-nc>
                ${batch ? '' : `<label>${esc(t('qc_product'))}<select class="kiosk-select" name="item_id"><option value="">${esc(t('qc_general'))}</option>
                    ${q.catalog.map(c => `<option value="${c.item_id}">${esc(c.name)}</option>`).join('')}</select></label>`}
                <label class="is-wide">${esc(t('qc_description'))}<textarea name="description" rows="3" maxlength="2000" required></textarea></label>
                <div class="is-wide"><span class="kiosk-muted">${esc(t('qc_severity'))}</span><div class="kiosk-row" data-sev>${['minor', 'major', 'critical'].map(k =>
                    `<button type="button" class="kiosk-btn is-small ${k === severity ? 'is-primary' : ''}" data-sev-k="${k}">${esc(t('sev_' + k))}</button>`).join('')}</div></div>
                ${batch ? `<label class="is-wide">${esc(t('qc_action'))}<input name="note" maxlength="255"></label>` : `<label>${esc(t('qc_quantity'))}<input name="quantity" inputmode="decimal"></label>`}
            </form><p class="kiosk-error" data-error></p>
            <div class="kiosk-row is-end"><button type="button" class="kiosk-btn" data-cancel>${esc(t('cancel'))}</button>
                <button type="button" class="kiosk-btn is-danger" data-save>${esc(t('qc_send'))}</button></div></section>`;
        const form = el.querySelector('[data-nc]');
        el.querySelector('[data-sev]').addEventListener('click', (e) => {
            const b = e.target.closest('[data-sev-k]');
            if (!b) return;
            severity = b.dataset.sevK;
            el.querySelectorAll('[data-sev-k]').forEach(x => x.classList.toggle('is-primary', x === b));
        });
        el.querySelector('[data-cancel]').addEventListener('click', () => renderQuality());
        el.querySelector('[data-save]').addEventListener('click', async (e) => {
            const description = form.description.value.trim();
            if (!description) { el.querySelector('[data-error]').textContent = t('qc_description_required'); form.description.focus(); return; }
            e.target.disabled = true;
            try {
                if (batch) {
                    await api('POST', `/kiosk/quality/batches/${batch.id}`, {result: 'nc', description, severity, note: form.note.value.trim() || null}, true);
                } else {
                    const quantity = form.quantity.value.trim() ? Number(form.quantity.value.replace(',', '.')) : null;
                    await api('POST', '/kiosk/quality/reports', {item_id: form.item_id.value ? Number(form.item_id.value) : null, description, severity, quantity}, true);
                }
                renderQuality(t('qc_saved_nc'));
            } catch (err) { e.target.disabled = false; el.querySelector('[data-error]').textContent = err.message; }
        });
        form.description.focus();
    }

    function renderNewBatch(item) {
        resetIdle();
        // Z partią produkcyjną : jedna partia naraz (albo reszta, gdy mniej niż partia).
        const rest = item.planned !== null && item.planned !== undefined ? Math.max(0, item.planned - (item.produced || 0)) : 0;
        const left = item.batch ? (rest > 0 ? Math.min(rest, item.batch) : item.batch) : rest;
        const cartons = item.ppc ? Math.floor(left / item.ppc) : 0;
        const loose = item.ppc ? Math.round(left - cartons * item.ppc) : left;
        el.innerHTML = header() + `<section class="kiosk-card"><h2>${esc(t('prod_new', {name: item.name}))}</h2>
            <form class="kiosk-form" data-new-batch>
                ${item.ppc ? `<label>${esc(t('prod_cartons', {ppc: item.ppc}))}<input name="cartons" inputmode="numeric" value="${cartons || ''}"></label>
                    <label>${esc(t('prod_loose', {unit: item.unit}))}<input name="loose" inputmode="decimal" value="${loose || ''}"></label>`
                    : `<label>${esc(t('prod_quantity', {unit: item.unit}))}<input name="loose" inputmode="decimal" value="${left ? fmt(left) : ''}"></label>`}
                <p class="kiosk-status" data-total></p>
            </form><p class="kiosk-error" data-error></p>
            <div class="kiosk-row is-end"><button type="button" class="kiosk-btn" data-cancel>${esc(t('cancel'))}</button>
                <button type="button" class="kiosk-btn is-primary" data-save>${esc(t('prod_save'))}</button></div></section>`;
        const form = el.querySelector('[data-new-batch]');
        const num = (v) => { const n = Number(String(v || '').replace(',', '.').replace(/\s/g, '')); return Number.isFinite(n) ? n : NaN; };
        const total = () => (item.ppc ? num(form.cartons.value) * item.ppc : 0) + num(form.loose.value);
        const show = () => { const q = total(); el.querySelector('[data-total]').textContent = Number.isFinite(q) && q > 0 ? t('prod_total', {q: qty(q, item)}) : ''; };
        form.addEventListener('input', () => { resetIdle(); show(); });
        show();
        const id = uuid();
        el.querySelector('[data-cancel]').addEventListener('click', () => renderProduction());
        el.querySelector('[data-save]').addEventListener('click', async (e) => {
            const q = total();
            if (!Number.isFinite(q) || q <= 0) { el.querySelector('[data-error]').textContent = t('prod_qty_required'); return; }
            e.target.disabled = true;
            try {
                const b = await api('POST', '/kiosk/production', {item_id: item.item_id, quantity: Math.round(q * 1000) / 1000, client_uuid: id}, true);
                renderBatch(b.id, t('prod_saved', {lot: b.lot_code}), b);
            } catch (err) { e.target.disabled = false; el.querySelector('[data-error]').textContent = err.offline ? t('offline_retry') : err.message; }
        });
    }

    // Partia : surowce i opakowania z receptury, numer partii (wpisany, « jak ostatnio » albo OCR ze zdjęcia etykiety).
    async function renderBatch(id, message, preloaded) {
        longIdle = true;
        resetIdle();
        let b = preloaded;
        if (!b) { try { b = await api('GET', `/kiosk/production/${id}`, undefined, true); } catch (err) { if (!err.code) toast(err.message, true); return; } }
        const pending = {}; // materiał => {photo, ocr}
        const item = b.item || {unit: '', ppc: null, name: ''};
        const row = (g) => `<article class="kiosk-card kiosk-lot ${g.lot_number ? 'is-ok' : ''}" data-material="${g.material_id}">
            <div class="kiosk-lot-head"><div><h3>${esc(g.name)}</h3><span class="kiosk-muted">${g.quantity !== null ? esc(amount(g.quantity) + ' ' + (g.unit || '')) : esc(amount(g.per_unit) + ' ' + (g.unit || '') + ' / ' + t('prod_per_recipe'))}</span></div>
                <span class="kiosk-lot-state">${g.lot_number ? '✓ ' + esc(g.lot_number) + (g.photo ? ' 📷' : '') : esc(t('lot_missing'))}</span></div>
            ${b.editable ? `<div class="kiosk-lot-fields">
                <label>${esc(t('lot_number'))}<input name="lot" autocomplete="off" autocapitalize="characters" maxlength="64" value="${esc(g.lot_number || '')}"></label>
                <label>${esc(t('lot_best_before'))}<input name="bb" type="date" value="${esc(g.best_before || '')}"></label>
                <label class="kiosk-btn is-small kiosk-photo">📷 ${esc(t('lot_photo'))}<input type="file" accept="image/*" capture="environment" data-photo hidden></label>
                ${g.last && g.last.lot_number !== g.lot_number ? `<button type="button" class="kiosk-btn is-small" data-same>${esc(t('lot_same', {lot: g.last.lot_number}))}</button>` : ''}
                <button type="button" class="kiosk-btn is-primary is-small" data-save-lot>${esc(t('lot_save'))}</button></div>
            <div class="kiosk-ocr" data-ocr hidden></div>` : ''}</article>`;
        const ingredients = b.ingredients.filter(g => !g.packaging);
        const packaging = b.ingredients.filter(g => g.packaging);
        el.innerHTML = header(`<button type="button" class="kiosk-btn is-small" data-back>${esc(t('back'))}</button>`) + `
            <section class="kiosk-card kiosk-batch-head ${b.cancelled ? 'is-cancelled' : ''}"><div><h2>${esc(item.name)}</h2>
                <p class="kiosk-status">${esc(t('prod_batch_line', {lot: b.lot_code, q: qty(b.quantity, item)}))}${b.cancelled ? ' · ' + esc(t('prod_cancelled')) : ''}</p>
                <p class="kiosk-muted">${esc(t('prod_lots', {done: b.lots_done, total: b.lots_total}))}${b.employee ? ' · ' + esc(b.employee) : ''} · ${esc(hhmm(b.created_at))}</p></div>
                ${b.editable ? `<button type="button" class="kiosk-btn is-small is-danger" data-cancel-batch>${esc(t('prod_cancel'))}</button>` : ''}</section>
            ${b.ingredients.length ? '' : `<section class="kiosk-card"><p class="kiosk-muted">${esc(t('prod_no_recipe'))}</p></section>`}
            ${ingredients.length ? `<h2 class="kiosk-section">${esc(t('lot_ingredients'))}</h2>${ingredients.map(row).join('')}` : ''}
            ${packaging.length ? `<details class="kiosk-section" ${packaging.some(g => g.lot_number) ? 'open' : ''}><summary>${esc(t('lot_packaging', {n: packaging.length}))}</summary>${packaging.map(row).join('')}</details>` : ''}`;
        if (message) toast(message);
        el.querySelector('[data-back]').addEventListener('click', () => renderProduction());
        el.querySelector('[data-cancel-batch]')?.addEventListener('click', async (e) => {
            if (!window.confirm(t('prod_cancel_confirm', {lot: b.lot_code}))) return;
            e.target.disabled = true;
            try { await api('PATCH', `/kiosk/production/${b.id}`, {cancelled: true}, true); renderProduction(t('prod_cancelled_done', {lot: b.lot_code})); }
            catch (err) { e.target.disabled = false; toast(err.message, true); }
        });
        el.querySelectorAll('[data-material]').forEach(card => {
            const m = Number(card.dataset.material);
            const g = b.ingredients.find(x => x.material_id === m);
            card.addEventListener('input', resetIdle);
            card.querySelector('[data-same]')?.addEventListener('click', () => {
                card.querySelector('[name=lot]').value = g.last.lot_number;
                if (g.last.best_before) card.querySelector('[name=bb]').value = g.last.best_before;
            });
            card.querySelector('[data-photo]')?.addEventListener('change', async (e) => {
                const file = e.target.files && e.target.files[0];
                e.target.value = '';
                busy = false;
                if (!file) return;
                await photo(card, file, pending[m] = {});
            });
            card.querySelector('.kiosk-photo')?.addEventListener('click', () => { busy = true; resetIdle(); });
            card.querySelector('[data-save-lot]')?.addEventListener('click', async (e) => {
                const lot = card.querySelector('[name=lot]').value.trim();
                const bb = card.querySelector('[name=bb]').value || null;
                const p = pending[m] || {};
                if (!lot && !p.photo) { toast(t('lot_required'), true); return; }
                e.target.disabled = true;
                try {
                    const scroll = window.scrollY;
                    const r = await api('PUT', `/kiosk/production/${b.id}/lots/${m}`, {lot_number: lot || null, best_before: bb, ...(p.photo ? {photo: p.photo} : {}), ...(p.ocr ? {ocr_text: p.ocr} : {})}, true);
                    await renderBatch(b.id, t('lot_saved', {name: g.name}), r);
                    window.scrollTo(0, scroll);
                } catch (err) { e.target.disabled = false; toast(err.message, true); }
            });
        });
    }

    // Zdjęcie etykiety : zmniejszone na tablecie (JPEG), tekst odczytany OCR (Tesseract.js ładowany przy pierwszym zdjęciu).
    async function photo(card, file, slot) {
        const box = card.querySelector('[data-ocr]');
        box.hidden = false;
        box.innerHTML = `<p class="kiosk-muted">${esc(t('ocr_reading'))}</p>`;
        busy = true;
        try {
            const bitmap = await loadImage(file);
            const scale = Math.min(1, 1600 / Math.max(bitmap.width, bitmap.height));
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(bitmap.width * scale);
            canvas.height = Math.round(bitmap.height * scale);
            canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
            slot.photo = canvas.toDataURL('image/jpeg', 0.75);
            box.innerHTML = `<img class="kiosk-ocr-img" src="${slot.photo}" alt=""><p class="kiosk-muted" data-ocr-msg>${esc(t('ocr_reading'))}</p>`;
            let text = '';
            try { text = await ocr(canvas, (pct) => { const n = box.querySelector('[data-ocr-msg]'); if (n) n.textContent = t('ocr_progress', {pct}); resetIdle(); }); }
            catch (e) { text = ''; }
            slot.ocr = text.trim() || null;
            const found = lotCandidates(text);
            const msg = box.querySelector('[data-ocr-msg]');
            if (!found.lots.length && !found.dates.length) { msg.textContent = t(text ? 'ocr_nothing' : 'ocr_unavailable'); return; }
            msg.textContent = t('ocr_pick');
            const chips = document.createElement('div');
            chips.className = 'kiosk-row';
            chips.innerHTML = found.lots.map(l => `<button type="button" class="kiosk-btn is-small" data-lot="${esc(l)}">${esc(t('ocr_lot'))} ${esc(l)}</button>`).join('')
                + found.dates.map(d => `<button type="button" class="kiosk-btn is-small" data-date="${esc(d)}">${esc(t('ocr_date'))} ${esc(d.split('-').reverse().join('.'))}</button>`).join('');
            box.appendChild(chips);
            chips.addEventListener('click', (e) => {
                const l = e.target.closest('[data-lot]');
                const d = e.target.closest('[data-date]');
                if (l) card.querySelector('[name=lot]').value = l.dataset.lot;
                if (d) card.querySelector('[name=bb]').value = d.dataset.date;
            });
            if (found.lots.length && !card.querySelector('[name=lot]').value) card.querySelector('[name=lot]').value = found.lots[0];
            if (found.dates.length && !card.querySelector('[name=bb]').value) card.querySelector('[name=bb]').value = found.dates[0];
        } catch (e) {
            box.innerHTML = `<p class="kiosk-error">${esc(t('photo_error'))}</p>`;
        } finally { busy = false; resetIdle(); }
    }

    function loadImage(file) {
        if (window.createImageBitmap) return createImageBitmap(file, {imageOrientation: 'from-image'}).catch(() => createImageBitmap(file));
        return new Promise((resolve, reject) => { const img = new Image(); img.onload = () => resolve(img); img.onerror = reject; img.src = URL.createObjectURL(file); });
    }

    let ocrWorker = null;
    async function ocr(canvas, progress) {
        if (!window.Tesseract) {
            await new Promise((resolve, reject) => {
                const s = document.createElement('script');
                s.src = 'https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/tesseract.min.js';
                s.onload = resolve; s.onerror = reject;
                document.head.appendChild(s);
            });
        }
        if (!ocrWorker) ocrWorker = await window.Tesseract.createWorker('eng', 1, {logger: (m) => { if (m.status === 'recognizing text') progress(Math.round(m.progress * 100)); }});
        const r = await ocrWorker.recognize(canvas);
        return (r && r.data && r.data.text) || '';
    }

    // Tekst etykiety → kandydaci na numer partii (LOT, L:, BATCH, PARTIA, CHARGE…) i datę przydatności.
    function lotCandidates(text) {
        const up = String(text || '').toUpperCase().replace(/[|]/g, 'I');
        const lots = [];
        const dates = [];
        const add = (list, v) => { if (v && !list.includes(v)) list.push(v); };
        const re = /(?:N[°ºO]?\s*(?:DE\s*)?LOT[EU]?|\bLOT[EU]?|\bL(?=\s*[:.]\s*\w)|BATCH(?:\s*(?:NO|NR|N°))?|CHARGE(?:N-?NR)?|CH\.?-?B\.?|PARTIA|NR\s*PARTII|NUMER\s*PARTII)\s*[:.#°º\-]*\s*([A-Z0-9][A-Z0-9\-\/.]{1,24})/g;
        let m;
        while ((m = re.exec(up))) add(lots, m[1].replace(/[.\-\/]+$/, ''));
        const pad = (n) => String(n).padStart(2, '0');
        const date = (d, mo, y) => {
            y = Number(y); if (y < 100) y += 2000;
            d = Number(d); mo = Number(mo);
            if (mo < 1 || mo > 12 || d < 1 || d > 31 || y < 2020 || y > 2045) return null;
            return `${y}-${pad(mo)}-${pad(d)}`;
        };
        const keyed = /(?:BBE|BBD|BEST\s*BEFORE|EXP|DDM|DLC|DLUO|MHD|USE\s*BY|NAJLEPIEJ\s*SPO[ZŻ]Y[CĆ]\s*PRZED(?:\s*KO[NŃ]CEM)?|NALE[ZŻ]Y\s*SPO[ZŻ]Y[CĆ]\s*DO|A\s*CONSOMMER)[^0-9]{0,25}(\d{1,2})[.\/\-](\d{1,2})[.\/\-](\d{2,4})/g;
        while ((m = keyed.exec(up))) add(dates, date(m[1], m[2], m[3]));
        const any = /\b(\d{1,2})[.\/\-](\d{1,2})[.\/\-](\d{4}|\d{2})\b/g;
        while ((m = any.exec(up))) add(dates, date(m[1], m[2], m[3]));
        const iso = /\b(20\d{2})[.\/\-](\d{2})[.\/\-](\d{2})\b/g;
        while ((m = iso.exec(up))) add(dates, date(m[3], m[2], m[1]));
        const monthYear = /(?:BBE|BEST\s*BEFORE|EXP|DDM|DLUO|MHD|KO[NŃ]CEM)[^0-9]{0,15}(\d{1,2})[.\/\-](20\d{2})\b/g;
        while ((m = monthYear.exec(up))) { const y = Number(m[2]); const mo = Number(m[1]); if (mo >= 1 && mo <= 12) add(dates, `${y}-${pad(mo)}-${pad(new Date(y, mo, 0).getDate())}`); }
        if (!lots.length) {
            // Bez słowa « LOT » : kody mieszające litery i cyfry (nie daty).
            (up.match(/\b(?=[A-Z0-9\-\/]*\d)(?=[A-Z0-9\-\/]*[A-Z])[A-Z0-9][A-Z0-9\-\/]{4,19}\b/g) || []).filter(v => !/^\d{1,2}[\-\/]\d{1,2}/.test(v)).slice(0, 3).forEach(v => add(lots, v));
        }
        return {lots: lots.filter(Boolean).slice(0, 4), dates: dates.filter(Boolean).slice(0, 3)};
    }
    window.tfbLotCandidates = lotCandidates;

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
            session ? openTab(session.tab || 'clock') : renderHome();
        }
    });
    ['pointerdown', 'keydown'].forEach(ev => document.addEventListener(ev, () => { if (session) resetIdle(); }, {passive: true}));
    window.setInterval(() => { const c = el.querySelector('[data-clock]'); if (c) c.textContent = new Date().toTimeString().slice(0, 5); }, 10000);
    loadLabels().then(() => (device ? renderHome() : renderPairing()));
})();
