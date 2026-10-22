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
    // Wersja zasobów z adresu skryptu (?v=) : etykiety w tej samej wersji co skrypt.
    const VERSION = (document.currentScript && new URL(document.currentScript.src, location.href).searchParams.get('v')) || '';
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
    let week = null; // plan tygodnia zespołu : {date, display, day, timer, idle}

    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const t = (k, p) => { let s = labels[k] ?? k; for (const [a, b] of Object.entries(p || {})) s = s.split('{' + a + '}').join(String(b)); return s; };
    const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16); }));
    const hhmm = (s) => String(s || '').slice(11, 16);
    const fmt = (n) => Number(n).toLocaleString(lang === 'fr' ? 'fr-FR' : 'pl-PL', {maximumFractionDigits: 2});

    async function loadLabels() {
        const res = await fetch(`${root}/resources/lang/ui/${lang}/kiosk.json${VERSION ? '?v=' + encodeURIComponent(VERSION) : ''}`, {credentials: 'same-origin'});
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
        stopScan();
        session = null;
        longIdle = false;
        busy = false;
        window.clearTimeout(idleTimer);
        renderHome();
        if (message) toast(message);
    }

    // --- Parowanie --------------------------------------------------------------------------------
    function renderPairing(message) {
        stopWeek();
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
                location.hash === '#week' ? renderWeek(null, true) : renderHome();
            } catch (err) { el.querySelector('[data-error]').textContent = err.message; }
        });
        el.querySelector('[name=code]').focus();
    }

    // --- Accueil : badge ou nom -----------------------------------------------------------------------
    async function renderHome() {
        if (!device) return renderPairing();
        stopWeek();
        el.innerHTML = header() + `<button type="button" class="kiosk-btn kiosk-week-open" data-week-open><span aria-hidden="true">📅</span>
                <span><strong>${esc(t('week_open'))}</strong><small>${esc(t('week_open_help'))}</small></span></button>
            <section class="kiosk-card"><div class="kiosk-badge-hint"><span aria-hidden="true">🪪</span><strong>${esc(t('badge_hint'))}</strong>
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
        el.querySelector('[data-week-open]').addEventListener('click', () => renderWeek(null, false));
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

    // --- Plan tygodnia zespołu (bez identyfikacji : tablet stoi przy zespole) -----------------------------
    // Z ekranu głównego : powrót po 3 min bez dotyku (kod QR do pointowania). Z linku …/kiosk#week : plan zostaje
    // na ekranie (tryb wyświetlacza). Odświeżanie co 5 min.
    const WEEK_REFRESH_MS = 300000;
    const WEEK_IDLE_MS = 180000;
    const dm = (s) => s ? `${s.slice(8, 10)}.${s.slice(5, 7)}` : '—';
    const wq = (v, unit, factor) => `${fmt(v / (factor || 1))} ${unit}`;
    const isoDay = (s, k) => { const d = new Date(s + 'T12:00:00'); d.setDate(d.getDate() + k); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; };

    function stopWeek() {
        if (!week) return;
        window.clearInterval(week.timer);
        window.clearTimeout(week.idle);
        week = null;
    }
    function weekIdle() {
        if (!week || week.display) return;
        window.clearTimeout(week.idle);
        week.idle = window.setTimeout(() => renderHome(), WEEK_IDLE_MS);
    }

    async function renderWeek(date, display) {
        const keep = week && week.date === date ? week.day : null;
        stopWeek();
        week = {date, display, day: keep, timer: null, idle: null};
        window.clearInterval(qrTimer);
        qrTimer = null;
        let w;
        try {
            w = await api('GET', '/kiosk/week' + (date ? '?date=' + date : ''));
        } catch (err) {
            if (['DEVICE_UNAUTHENTICATED', 'DEVICE_DISABLED'].includes(err.code)) return;
            toast(err.message, true);
            if (!display) renderHome();
            return;
        }
        if (!week) return;
        const team = w.teams[0];
        if (!week.day || !team.days.some(d => d.date === week.day)) week.day = (team.days.find(d => d.today) || team.days.find(d => d.blocks.length) || team.days[0]).date;
        const d = team.days.find(x => x.date === week.day);
        const color = /^#[0-9a-f]{6}$/i.test(team.color || '') ? team.color : '#8D1D2C';
        const current = w.week_start <= w.today && w.today <= w.week_end;
        const block = (b) => `<div class="kiosk-plan-block ${b.late ? 'is-late' : ''}"><span class="kiosk-plan-time">${esc(b.time || '—')}</span>
            <div><h3>${esc(b.name)}</h3><p class="kiosk-muted">${esc(wq(b.quantity, b.unit, b.factor))}${b.cartons ? ' · ' + esc(fmt(b.cartons)) + ' ' + esc(t('week_cartons')) : ''}${b.batches ? ' · ' + esc(fmt(b.batches)) + ' ' + esc(t('week_batches')) : ''} · ${esc(fmt(b.hours))} h</p>
            <div class="kiosk-row">${b.direct_qty > 0 ? `<span class="kiosk-tag is-ship">${esc(t('week_ship', {qty: wq(b.direct_qty, b.unit, b.factor)}))}</span>` : ''}${b.stock_qty > 0 ? `<span class="kiosk-tag">${esc(t('week_stock', {qty: wq(b.stock_qty, b.unit, b.factor)}))}</span>` : ''}
                ${b.late ? `<span class="kiosk-tag is-late">${esc(t('week_late'))}</span>` : ''}</div></div></div>`;
        const deliveryState = (l) => l.received >= l.quantity - 0.0005 ? 'received' : (l.received > 0 ? 'partial' : 'expected');
        const delivery = (o) => `<div class="kiosk-plan-delivery"><span aria-hidden="true">🚚</span><div><h3>${esc(o.supplier)}${o.date ? '' : ' · ' + esc(t('week_undated'))}</h3>
            ${o.lines.map(l => `<p>${esc(l.name)} — ${esc(wq(l.quantity, l.unit, l.factor))} <span class="kiosk-muted">(${esc(t('week_delivery_' + deliveryState(l)))} · ${esc(t('week_need', {qty: wq(l.need, l.unit, l.factor)}))})</span></p>`).join('')}</div></div>`;
        const dayDeliveries = team.deliveries.filter(o => o.date === d.date);
        el.innerHTML = header(`<button type="button" class="kiosk-btn is-small" data-home>‹ ${esc(t('week_back'))}</button>`) + `
            <section class="kiosk-card kiosk-week" style="--team:${color}"><div class="kiosk-week-head"><div><h2>${esc(team.name || '—')}</h2>
                <p class="kiosk-muted">${esc(t('week_label', {week: w.iso_week, from: dm(w.week_start), to: dm(w.week_end)}))}</p></div>
                <div class="kiosk-row"><button type="button" class="kiosk-btn is-small" data-wk="-7" aria-label="${esc(t('week_prev'))}">‹</button>
                    ${current ? '' : `<button type="button" class="kiosk-btn is-small" data-wk="0">${esc(t('week_this'))}</button>`}
                    <button type="button" class="kiosk-btn is-small" data-wk="7" aria-label="${esc(t('week_next'))}">›</button></div></div>
                <nav class="kiosk-days" style="grid-template-columns:repeat(${team.days.length}, minmax(92px, 1fr))" aria-label="${esc(t('week_days'))}">${team.days.map(x => `<button type="button" class="kiosk-day ${x.date === d.date ? 'is-active' : ''} ${x.today ? 'is-today' : ''}" data-day="${x.date}">
                    <strong>${esc(t('weekday_' + x.weekday))} ${esc(dm(x.date))}</strong><small>${x.blocks.length ? esc(t('week_blocks', {n: x.blocks.length, hours: fmt(x.required_h)})) : '—'}${x.deliveries.length ? ' · 🚚' : ''}</small></button>`).join('')}</nav></section>
            <section class="kiosk-card"><div class="kiosk-week-head"><h2>${esc(t('weekday_long_' + d.weekday))} ${esc(dm(d.date))}</h2>
                <p class="kiosk-muted">${d.available_h > 0 || d.required_h > 0 ? esc(t('week_day_hours', {people: d.people, required: fmt(d.required_h), available: fmt(d.available_h)})) : esc(t('week_no_shift'))}</p></div>
                <div class="kiosk-plan">${d.blocks.map(block).join('') || `<p class="kiosk-muted">${esc(t('week_day_empty'))}</p>`}</div>
                ${dayDeliveries.length ? `<h3 class="kiosk-plan-sub">${esc(t('week_deliveries_day'))}</h3>${dayDeliveries.map(delivery).join('')}` : ''}</section>
            <section class="kiosk-card"><h2>${esc(t('week_all'))}</h2>${team.products.length ? `<div class="kiosk-table-wrap"><table class="kiosk-table"><thead><tr><th>${esc(t('week_product'))}</th><th class="is-num">${esc(t('week_total'))}</th>
                ${team.days.map(x => `<th class="is-num ${x.today ? 'is-today' : ''}">${esc(t('weekday_' + x.weekday))}<br>${esc(dm(x.date))}</th>`).join('')}</tr></thead>
                <tbody>${team.products.map(r => `<tr><td>${esc(r.name)}${r.unassigned_qty > 0 ? `<br><small class="kiosk-late">${esc(t('week_waiting', {qty: wq(r.unassigned_qty, r.unit, r.factor)}))}</small>` : ''}${Math.abs(r.need - r.quantity) > 0.5 ? `<br><small class="kiosk-muted">${esc(t('week_need_total', {qty: wq(r.need, r.unit, r.factor)}))}</small>` : ''}</td>
                    <td class="is-num">${esc(wq(r.quantity, r.unit, r.factor))}</td>
                    ${team.days.map(x => { const v = r.days[x.date]; return `<td class="is-num ${x.today ? 'is-today' : ''}">${v ? (v.time ? `<small>${esc(v.time)}</small><br>` : '') + esc(fmt(v.quantity / (r.factor || 1))) : ''}</td>`; }).join('')}</tr>`).join('')}</tbody></table></div>`
                : `<p class="kiosk-muted">${esc(t('week_team_empty'))}</p>`}</section>
            ${team.deliveries.length ? `<section class="kiosk-card"><h2>${esc(t('week_deliveries'))}</h2>${team.deliveries.map(o => `<p class="kiosk-plan-sub">${o.date ? esc(t('weekday_long_' + (new Date(o.date + 'T12:00:00').getDay() || 7))) + ' ' + esc(dm(o.date)) : esc(t('week_undated'))}</p>${delivery(o)}`).join('')}</section>` : ''}`;
        el.querySelector('[data-home]').addEventListener('click', () => renderHome());
        el.querySelectorAll('[data-wk]').forEach(b => b.addEventListener('click', () => { const k = Number(b.dataset.wk); renderWeek(k === 0 ? null : isoDay(w.week_start, k), display); }));
        el.querySelectorAll('[data-day]').forEach(b => b.addEventListener('click', () => { week.day = b.dataset.day; renderWeek(date, display); }));
        week.timer = window.setInterval(() => { if (!document.hidden) renderWeek(date, display); }, WEEK_REFRESH_MS);
        weekIdle();
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

    // --- Menu osoby : Czas pracy / Produkcja / Kontrola jakości / Receptury ---------------------------
    const TABS = [['clock', '⏱', 'tab_clock'], ['production', '📦', 'tab_production'], ['quality', '✅', 'tab_quality'], ['recipes', '📖', 'tab_recipes'], ['machines', '🤖', 'tab_machines'], ['dev', '🧪', 'tab_dev']];
    const personHeader = () => header(`<button type="button" class="kiosk-btn is-small" data-logout>${esc(t('logout'))}</button>`);
    function tabs(active) {
        if (session) session.tab = active;
        // Zakładki Maszyny (TRS) i Nowe produkty tylko, gdy grupa tabletu ma maszyny z TRS / produkty pokazane na tablecie.
        const shown = ([k]) => (k !== 'machines' || (session && session.machines > 0)) && (k !== 'dev' || (session && session.dev > 0));
        return `<nav class="kiosk-tabs" role="tablist" aria-label="${esc(t('tabs'))}">${TABS.filter(shown).map(([k, icon, label]) => `<button type="button" class="kiosk-tab ${k === active ? 'is-active' : ''}"
            role="tab" aria-selected="${k === active}" data-tab="${k}"><span aria-hidden="true">${icon}</span> ${esc(t(label))}</button>`).join('')}</nav>`;
    }
    function bindTabs() {
        el.querySelectorAll('[data-tab]').forEach(b => b.addEventListener('click', () => { if (!b.classList.contains('is-active')) openTab(b.dataset.tab); }));
        el.querySelector('[data-logout]')?.addEventListener('click', () => logout());
    }
    function openTab(k, message) {
        if (k === 'recipes') return renderRecipes(message);
        if (k === 'machines') return renderMachines(message);
        if (k === 'dev') return renderDev(message);
        return k === 'production' ? renderProduction(message) : (k === 'quality' ? renderQuality(message) : renderMe(message));
    }

    // --- Czas pracy (pointeuse) ----------------------------------------------------------------------
    async function renderMe(message) {
        longIdle = false;
        let me;
        try {
            let machines, dev;
            [me, machines, dev] = await Promise.all([api('GET', '/kiosk/me', undefined, true), api('GET', '/kiosk/machines', undefined, true).catch(() => null),
                api('GET', '/kiosk/development', undefined, true).catch(() => null)]);
            if (session) { session.machines = machines ? machines.machines.length : 0; session.dev = dev ? dev.products.length : 0; }
        } catch (err) { if (!err.code) toast(err.message, true); return; }
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

    // Dzień zespołu : plan → zrobione (jednym dotknięciem albo partiami), straty z powodem, zatwierdzenie dnia.
    async function renderProduction(message) {
        longIdle = true;
        resetIdle();
        let day, me;
        try {
            [day, me] = await Promise.all([api('GET', '/kiosk/production', undefined, true), api('GET', '/kiosk/me', undefined, true).catch(() => null)]);
        } catch (err) { if (!err.code || err.code === 'DEVICE_WITHOUT_GROUP') toast(err.message, true); return; }
        drawProduction(day, me, message);
    }

    function drawProduction(day, me, message) {
        const others = day.catalog.filter(c => !day.items.some(i => i.item_id === c.item_id));
        const tot = day.totals || {};
        const closed = day.closed;
        el.innerHTML = personHeader() + tabs('production') + `
            <section class="kiosk-card kiosk-dayhead ${closed && !closed.changed ? 'is-closed' : ''}"><div><h2>${esc(t('prod_title', {group: day.group.name}))}</h2>
                <p class="kiosk-muted">${esc(t('prod_help'))}</p>
                <p class="kiosk-status">${tot.planned ? esc(t('day_plan_done', {done: tot.planned_done, total: tot.planned})) + ' · ' : ''}${esc(t('day_waste', {q: fmt(tot.waste || 0), pct: tot.waste_pct === null || tot.waste_pct === undefined ? '0' : fmt(tot.waste_pct)}))}</p>
                ${closed ? `<p class="kiosk-dayhead-state">${closed.changed ? '⚠ ' + esc(t('day_changed')) : '✔ ' + esc(t('day_closed', {time: hhmm(closed.at), name: closed.employee || ''}))}${closed.note ? ' · ' + esc(closed.note) : ''}</p>` : ''}</div>
                ${day.editable && day.items.length ? `<button type="button" class="kiosk-btn ${closed && !closed.changed ? '' : 'is-primary'}" data-close-day>✔ ${esc(t(closed ? 'day_close_again' : 'day_close'))}</button>` : ''}</section>
            <div class="kiosk-prod">${day.items.length ? day.items.map(i => {
                const left = i.planned !== null ? Math.max(0, Math.round((i.planned - i.produced) * 1000) / 1000) : null;
                const pct = i.planned ? Math.min(100, Math.round(i.produced / i.planned * 100)) : null;
                const live = i.wastes.filter(w => !w.cancelled);
                return `<article class="kiosk-card kiosk-prod-item ${left === 0 ? 'is-done' : ''}" data-item="${i.item_id}">
                    <div class="kiosk-prod-head"><div><h3>${esc(i.name)}</h3>
                        <div class="kiosk-muted">${i.planned !== null ? esc(t('prod_planned', {q: qty(i.planned, i)})) + ' · ' : ''}${esc(t('prod_done', {q: qty(i.produced, i)}))}${i.waste ? ' · ' + esc(t('prod_waste', {q: qty(i.waste, i)})) + ' · <strong>' + esc(t('prod_good', {q: qty(i.good, i)})) + '</strong>' : ''}</div></div>
                        ${day.editable ? `<div class="kiosk-prod-actions">
                            ${left ? `<button type="button" class="kiosk-btn is-ok" data-as-planned="${i.item_id}">✓ ${esc(t('prod_as_planned', {q: qty(left, i)}))}</button>` : ''}
                            <button type="button" class="kiosk-btn ${left ? '' : 'is-ok'}" data-new="${i.item_id}">＋ ${esc(t('prod_batch'))}</button>
                            ${i.good > 0 ? `<button type="button" class="kiosk-btn is-danger" data-waste="${i.item_id}">− ${esc(t('waste_add'))}</button>` : ''}</div>` : ''}</div>
                    ${pct !== null ? `<div class="kiosk-bar"><span style="width:${pct}%"></span></div>` : ''}
                    ${batchTiles(i)}
                    ${i.batches.length ? `<ul class="kiosk-batches">${i.batches.map(b => `<li class="${b.cancelled ? 'is-cancelled' : ''}">
                        <button type="button" class="kiosk-batch" data-batch="${b.id}"><strong>${esc(b.lot_code)}</strong>
                        <span>${esc(qty(b.quantity, i))}</span><span class="kiosk-muted">${esc(hhmm(b.created_at))}${b.employee ? ' · ' + esc(b.employee) : ''}</span>
                        <span class="kiosk-lots ${b.cancelled ? '' : (b.lots_total && b.lots_done >= b.lots_total ? 'is-ok' : 'is-todo')}">${b.cancelled ? esc(t('prod_cancelled')) : esc(t('prod_lots', {done: b.lots_done ?? 0, total: b.lots_total ?? 0}))}</span></button></li>`).join('')}</ul>` : ''}
                    ${live.length ? `<ul class="kiosk-wastes">${live.map(w => `<li class="kiosk-waste"><span>🗑 <strong>${esc(qty(w.quantity, i))}</strong> · ${esc(t('waste_' + w.reason))}${w.note ? ' — ' + esc(w.note) : ''}</span>
                        <span class="kiosk-muted">${w.lot_code ? esc(w.lot_code) + ' · ' : ''}${esc(hhmm(w.created_at))}${w.employee ? ' · ' + esc(w.employee) : ''}</span>
                        ${w.editable ? `<button type="button" class="kiosk-btn is-small" data-waste-cancel="${w.id}" aria-label="${esc(t('waste_cancel'))}">✕</button>` : ''}</li>`).join('')}</ul>` : ''}
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
        el.querySelectorAll('[data-waste]').forEach(b => b.addEventListener('click', () => renderWaste(find(Number(b.dataset.waste)))));
        // Zrobione według planu : jedna partia z resztą planu (numery partii surowców potem, jak przy każdej partii).
        el.querySelectorAll('[data-as-planned]').forEach(b => b.addEventListener('click', async () => {
            const i = find(Number(b.dataset.asPlanned));
            const left = Math.max(0, Math.round((i.planned - i.produced) * 1000) / 1000);
            if (!left || !window.confirm(t('prod_as_planned_confirm', {name: i.name, q: qty(left, i)}))) return;
            b.disabled = true;
            try {
                const r = await api('POST', '/kiosk/production', {item_id: i.item_id, quantity: left, client_uuid: uuid()}, true);
                renderProduction(t('prod_saved_plan', {lot: r.lot_code, q: qty(left, i)}));
            } catch (err) { b.disabled = false; toast(err.offline ? t('offline_retry') : err.message, true); }
        }));
        el.querySelectorAll('[data-waste-cancel]').forEach(b => b.addEventListener('click', async () => {
            if (!window.confirm(t('waste_cancel_confirm'))) return;
            b.disabled = true;
            try { drawProduction(await api('PATCH', `/kiosk/waste/${b.dataset.wasteCancel}`, {cancelled: true}, true), me, t('waste_cancelled')); }
            catch (err) { b.disabled = false; toast(err.message, true); }
        }));
        el.querySelector('[data-close-day]')?.addEventListener('click', () => renderClose(day, me));
        const go = el.querySelector('[data-other-go]');
        if (go) go.addEventListener('click', () => { const v = Number(el.querySelector('[data-other]').value); if (v) renderNewBatch(find(v)); });
    }

    // Strata : ilość (kartony + sztuki), powód, opcjonalnie partia i notatka (« inne » : notatka wymagana).
    function renderWaste(item) {
        resetIdle();
        let reason = null;
        const icons = {quality: '🔍', burnt: '🔥', damaged: '💥', samples: '🧪', other: '✏️'};
        const batches = (item.batches || []).filter(b => !b.cancelled);
        el.innerHTML = header() + `<section class="kiosk-card"><h2>${esc(t('waste_title', {name: item.name}))}</h2>
            <p class="kiosk-muted">${esc(t('waste_help', {q: qty(item.good, item)}))}</p>
            <form class="kiosk-form" data-waste-form>
                ${item.ppc ? `<label>${esc(t('prod_cartons', {ppc: item.ppc}))}<input name="cartons" inputmode="numeric"></label>
                    <label>${esc(t('prod_loose', {unit: item.unit}))}<input name="loose" inputmode="decimal"></label>`
                    : `<label>${esc(t('prod_quantity', {unit: item.unit}))}<input name="loose" inputmode="decimal"></label>`}
                <p class="kiosk-status is-wide" data-total></p>
                <div class="is-wide"><span class="kiosk-muted">${esc(t('waste_reason'))}</span><div class="kiosk-reasons" data-reasons>${['quality', 'burnt', 'damaged', 'samples', 'other'].map(k =>
                    `<button type="button" class="kiosk-btn kiosk-reason" data-reason="${k}"><span aria-hidden="true">${icons[k]}</span> ${esc(t('waste_' + k))}</button>`).join('')}</div></div>
                ${batches.length ? `<label>${esc(t('waste_batch'))}<select class="kiosk-select" name="output_id"><option value="">${esc(t('waste_no_batch'))}</option>
                    ${batches.map(b => `<option value="${b.id}">${esc(b.lot_code)} · ${esc(qty(b.quantity, item))}</option>`).join('')}</select></label>` : ''}
                <label class="is-wide">${esc(t('waste_note'))}<input name="note" maxlength="255"></label>
            </form><p class="kiosk-error" data-error></p>
            <div class="kiosk-row is-end"><button type="button" class="kiosk-btn" data-cancel>${esc(t('cancel'))}</button>
                <button type="button" class="kiosk-btn is-danger" data-save>${esc(t('waste_save'))}</button></div></section>`;
        const form = el.querySelector('[data-waste-form]');
        const num = (v) => { const n = Number(String(v || '').replace(',', '.').replace(/\s/g, '')); return Number.isFinite(n) ? n : NaN; };
        const total = () => (item.ppc ? num(form.cartons.value) * item.ppc : 0) + num(form.loose.value);
        const show = () => { const q = total(); el.querySelector('[data-total]').textContent = Number.isFinite(q) && q > 0 ? t('prod_total', {q: qty(q, item)}) : ''; };
        form.addEventListener('input', () => { resetIdle(); show(); });
        el.querySelector('[data-reasons]').addEventListener('click', (e) => {
            const b = e.target.closest('[data-reason]');
            if (!b) return;
            reason = b.dataset.reason;
            el.querySelectorAll('[data-reason]').forEach(x => x.classList.toggle('is-primary', x === b));
            if (reason === 'other') form.note.focus();
        });
        const id = uuid();
        el.querySelector('[data-cancel]').addEventListener('click', () => renderProduction());
        el.querySelector('[data-save]').addEventListener('click', async (e) => {
            const q = total();
            const error = el.querySelector('[data-error]');
            if (!Number.isFinite(q) || q <= 0) { error.textContent = t('prod_qty_required'); return; }
            if (!reason) { error.textContent = t('waste_reason_required'); return; }
            if (reason === 'other' && !form.note.value.trim()) { error.textContent = t('waste_note_required'); form.note.focus(); return; }
            e.target.disabled = true;
            try {
                const body = {item_id: item.item_id, quantity: Math.round(q * 1000) / 1000, reason, note: form.note.value.trim() || null, client_uuid: id};
                if (form.output_id && form.output_id.value) body.output_id = Number(form.output_id.value);
                await api('POST', '/kiosk/waste', body, true);
                renderProduction(t('waste_saved', {q: qty(q, item)}));
            } catch (err) { e.target.disabled = false; error.textContent = err.offline ? t('offline_retry') : err.message; }
        });
    }

    // Zatwierdzenie dnia : podsumowanie na produkt (plan, zrobione, straty, dobre), braki wobec planu, notatka zespołu.
    function renderClose(day, me) {
        resetIdle();
        const short = day.items.filter(i => i.planned !== null && i.produced + 1e-6 < i.planned);
        el.innerHTML = header() + `<section class="kiosk-card"><h2>${esc(t('day_close_title', {group: day.group.name}))}</h2>
            <p class="kiosk-muted">${esc(t('day_close_help'))}</p>
            <table class="kiosk-table"><thead><tr><th>${esc(t('day_col_product'))}</th><th>${esc(t('day_col_planned'))}</th><th>${esc(t('day_col_done'))}</th><th>${esc(t('day_col_waste'))}</th><th>${esc(t('day_col_good'))}</th></tr></thead>
            <tbody>${day.items.map(i => `<tr class="${i.planned !== null && i.produced + 1e-6 < i.planned ? 'is-short' : ''}"><td>${esc(i.name)}</td><td>${i.planned !== null ? esc(qty(i.planned, i)) : '—'}</td>
                <td>${esc(qty(i.produced, i))}</td><td>${i.waste ? esc(qty(i.waste, i)) : '—'}</td><td><strong>${esc(qty(i.good, i))}</strong></td></tr>`).join('')}</tbody></table>
            ${short.length ? `<p class="kiosk-error">⚠ ${esc(t('day_short', {n: short.length}))}</p>` : ''}
            <form class="kiosk-form" data-close-form><label class="is-wide">${esc(t('day_note'))}<input name="note" maxlength="255" value="${esc(day.closed && day.closed.note || '')}"></label></form>
            <p class="kiosk-error" data-error></p>
            <div class="kiosk-row is-end"><button type="button" class="kiosk-btn" data-cancel>${esc(t('cancel'))}</button>
                <button type="button" class="kiosk-btn is-primary" data-save>✔ ${esc(t('day_confirm'))}</button></div></section>`;
        el.querySelector('[data-close-form]').addEventListener('input', resetIdle);
        el.querySelector('[data-cancel]').addEventListener('click', () => drawProduction(day, me));
        el.querySelector('[data-save]').addEventListener('click', async (e) => {
            e.target.disabled = true;
            try {
                const r = await api('POST', '/kiosk/production/close', {date: day.date, note: el.querySelector('[name=note]').value.trim() || null}, true);
                drawProduction(r, me, t('day_closed_done'));
            } catch (err) { e.target.disabled = false; el.querySelector('[data-error]').textContent = err.offline ? t('offline_retry') : err.message; }
        });
    }

    // --- Receptury : karty grupy, drzewo receptury przeliczone na wybraną ilość (karton, partia, plan dnia) --------
    async function renderRecipes(message) {
        longIdle = true;
        resetIdle();
        let r;
        try { r = await api('GET', '/kiosk/recipes', undefined, true); } catch (err) { if (!err.code || err.code === 'DEVICE_WITHOUT_GROUP') toast(err.message, true); return; }
        const today = r.items.filter(i => i.planned);
        const rest = r.items.filter(i => !i.planned);
        const tile = (i) => `<button type="button" class="kiosk-card kiosk-recipe-tile" data-recipe="${i.item_id}"><strong>${esc(i.name)}</strong>
            <span class="kiosk-muted">${i.planned ? esc(t('prod_planned', {q: qty(i.planned, i)})) : esc(t(i.type === 'semi' ? 'rec_semi' : 'rec_product'))}</span></button>`;
        el.innerHTML = personHeader() + tabs('recipes') + `
            <section class="kiosk-card"><h2>${esc(t('rec_title'))}</h2><p class="kiosk-muted">${esc(t('rec_help'))}</p>
                <input class="kiosk-search" type="search" data-rec-search placeholder="${esc(t('rec_search'))}" autocomplete="off"></section>
            ${today.length ? `<h2 class="kiosk-section">${esc(t('rec_today'))}</h2><div class="kiosk-recipe-grid">${today.map(tile).join('')}</div>` : ''}
            ${rest.length ? `<h2 class="kiosk-section">${esc(t('rec_all'))}</h2><div class="kiosk-recipe-grid">${rest.map(tile).join('')}</div>` : ''}
            ${r.items.length ? '' : `<section class="kiosk-card"><p class="kiosk-muted">${esc(t('rec_none'))}</p></section>`}`;
        if (message) toast(message);
        bindTabs();
        el.querySelectorAll('[data-recipe]').forEach(b => b.addEventListener('click', () => renderRecipe(Number(b.dataset.recipe))));
        el.querySelector('[data-rec-search]').addEventListener('input', (e) => {
            resetIdle();
            const q = e.target.value.trim().toLowerCase();
            el.querySelectorAll('[data-recipe]').forEach(b => { b.hidden = q !== '' && !b.textContent.toLowerCase().includes(q); });
        });
    }

    // Ilość : gramy / mililitry od 1000 jako kg / l.
    const measure = (q, unit) => {
        if ((unit === 'g' || unit === 'ml') && Math.abs(q) >= 1000) return `${amount(q / 1000)} ${unit === 'g' ? 'kg' : 'l'}`;
        return `${amount(q)} ${unit || ''}`.trim();
    };

    // Drzewo receptury (podreceptury, surowce, opakowania, etapy przygotowania) × mnożnik.
    function recNode(n, f, depth) {
        const items = n.lines.filter(l => l.kind === 'recipe' || !l.packaging);
        const pack = depth === 0 ? n.lines.filter(l => l.kind === 'material' && l.packaging) : [];
        return `<div class="kiosk-rec-node ${depth ? 'is-sub' : ''}">
            ${n.steps.length ? `<ol class="kiosk-rec-steps">${n.steps.map(st => `<li>${esc(st)}</li>`).join('')}</ol>` : ''}
            <ul class="kiosk-rec-lines">${items.map(l => l.kind === 'recipe'
                ? `<li class="kiosk-rec-sub"><details open><summary><strong>${esc(l.name)}</strong> <span class="kiosk-rec-q">${esc(measure(l.quantity * f, l.unit))}</span></summary>
                    ${l.node ? recNode(l.node, f, depth + 1) : ''}</details></li>`
                : `<li><span>${esc(l.name)}</span><span class="kiosk-rec-q">${esc(measure(l.quantity * f, l.unit))}</span></li>`).join('')}</ul>
            ${pack.length ? `<details class="kiosk-rec-pack"><summary>${esc(t('lot_packaging', {n: pack.length}))}</summary><ul class="kiosk-rec-lines">${pack.map(l =>
                `<li><span>${esc(l.name)}</span><span class="kiosk-rec-q">${esc(measure(l.quantity * f, l.unit))}</span></li>`).join('')}</ul></details>` : ''}</div>`;
    }

    async function renderRecipe(itemId) {
        longIdle = true;
        resetIdle();
        let r;
        try { r = await api('GET', `/kiosk/recipes/${itemId}`, undefined, true); } catch (err) { toast(err.message, true); return; }
        // Mnożnik drzewa : karton (produkt), kg (półprodukt) albo receptura.
        const presets = [];
        if (r.basis === 'carton') {
            presets.push({k: 'carton', label: t('rec_one_carton', {ppc: r.ppc}), f: 1});
            if (r.batch) presets.push({k: 'batch', label: t('rec_one_batch', {q: qty(r.batch, r)}), f: r.batch / r.ppc});
            if (r.planned) presets.push({k: 'plan', label: t('rec_plan', {q: qty(r.planned, r)}), f: r.planned / r.ppc});
        } else if (r.basis === 'kg') {
            if (r.recipe_yield) presets.push({k: 'recipe', label: t('rec_one_recipe_kg', {q: fmt(r.recipe_yield)}), f: r.recipe_yield});
            if (r.batch) presets.push({k: 'batch', label: t('rec_one_batch', {q: qty(r.batch, r)}), f: r.batch});
            if (r.planned) presets.push({k: 'plan', label: t('rec_plan', {q: qty(r.planned, r)}), f: r.planned});
        } else {
            presets.push({k: 'recipe', label: t('rec_one_recipe'), f: 1});
        }
        let factor = (presets.find(p => p.k === 'plan') || presets[0]).f;
        const customLabel = r.basis === 'carton' ? t('rec_custom_pieces', {unit: r.unit}) : (r.basis === 'kg' ? t('rec_custom_kg') : t('rec_custom_times'));
        const draw = () => {
            el.querySelector('[data-rec-tree]').innerHTML = recNode(r.tree, factor, 0);
            const pieces = r.basis === 'carton' ? factor * r.ppc : null;
            el.querySelector('[data-rec-for]').textContent = r.basis === 'carton' ? t('rec_for_pieces', {q: qty(pieces, r)})
                : (r.basis === 'kg' ? t('rec_for_kg', {q: fmt(factor)}) : t('rec_for_times', {q: fmt(factor)}));
        };
        el.innerHTML = header(`<button type="button" class="kiosk-btn is-small" data-back>${esc(t('back'))}</button>`) + `
            <section class="kiosk-card"><h2>${esc(r.name)}</h2>
                <div class="kiosk-row kiosk-rec-presets" data-presets>${presets.map(p => `<button type="button" class="kiosk-btn is-small" data-f="${p.f}">${esc(p.label)}</button>`).join('')}
                    <label class="kiosk-rec-custom">${esc(customLabel)}<input data-custom inputmode="decimal"></label></div>
                <p class="kiosk-status" data-rec-for></p></section>
            <section class="kiosk-card" data-rec-tree></section>
            ${r.routing.length ? `<section class="kiosk-card"><h2>${esc(t('rec_routing'))}</h2><ol class="kiosk-rec-steps">${r.routing.map(st =>
                `<li><strong>${esc(st.name)}</strong>${st.workstation ? ' · ' + esc(st.workstation) : ''}${st.temperature_c !== null ? ' · ' + esc(fmt(st.temperature_c)) + ' °C' : ''}</li>`).join('')}</ol></section>` : ''}`;
        const mark = () => el.querySelectorAll('[data-f]').forEach(b => b.classList.toggle('is-primary', Math.abs(Number(b.dataset.f) - factor) < 1e-9));
        el.querySelector('[data-back]').addEventListener('click', () => renderRecipes());
        el.querySelector('[data-presets]').addEventListener('click', (e) => {
            const b = e.target.closest('[data-f]');
            if (!b) return;
            factor = Number(b.dataset.f);
            el.querySelector('[data-custom]').value = '';
            mark();
            draw();
        });
        el.querySelector('[data-custom]').addEventListener('input', (e) => {
            resetIdle();
            const v = Number(String(e.target.value).replace(',', '.').replace(/\s/g, ''));
            if (!Number.isFinite(v) || v <= 0) return;
            factor = r.basis === 'carton' ? v / r.ppc : v;
            mark();
            draw();
        });
        mark();
        draw();
    }

    // --- Nowe produkty : produkty testowe pokazane grupie — etap, receptura, film, zdjęcia, notatki --------------------
    const dateFmt = (d) => d ? d.split('-').reverse().join('.') : '';

    async function renderDev(message) {
        longIdle = true;
        resetIdle();
        let d;
        try { d = await api('GET', '/kiosk/development', undefined, true); } catch (err) { if (!err.code || err.code === 'DEVICE_WITHOUT_GROUP') toast(err.message, true); return; }
        if (session) session.dev = d.products.length;
        el.innerHTML = personHeader() + tabs('dev') + `
            <section class="kiosk-card"><h2>${esc(t('dev_title'))}</h2><p class="kiosk-muted">${esc(t('dev_help'))}</p></section>
            ${d.products.length ? `<div class="kiosk-recipe-grid">${d.products.map(p => `<button type="button" class="kiosk-card kiosk-recipe-tile kiosk-dev-tile" data-dev="${p.id}">
                <strong>${esc(p.name)}</strong>
                <span class="kiosk-muted">${esc([p.status, p.stage && p.stage.name].filter(Boolean).join(' · '))}</span>
                <span class="kiosk-dev-badges">${p.recipe_id ? '📖 ' : ''}${p.has_video ? '🎬 ' : ''}${p.photo_count ? '📷 ' + p.photo_count + ' ' : ''}${p.has_notes ? '📝' : ''}</span>
                ${p.stage && p.stage.deadline ? `<span class="kiosk-muted">${esc(t('dev_deadline', {date: dateFmt(p.stage.deadline)}))}</span>` : ''}</button>`).join('')}</div>`
                : `<section class="kiosk-card"><p class="kiosk-muted">${esc(t('dev_none'))}</p></section>`}`;
        if (message) toast(message);
        bindTabs();
        el.querySelectorAll('[data-dev]').forEach(b => b.addEventListener('click', () => renderDevProduct(Number(b.dataset.dev))));
    }

    async function renderDevProduct(id) {
        longIdle = true;
        resetIdle();
        let p;
        try { p = await api('GET', `/kiosk/development/${id}`, undefined, true); } catch (err) { toast(err.message, true); return; }
        let factor = 1;
        const v = p.video;
        const video = !v ? '' : (v.embed
            ? `<div class="kiosk-dev-video"><iframe src="${esc(v.embed)}" title="${esc(p.name)}" allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen loading="lazy"></iframe></div>`
            : (v.kind === 'file' ? `<video class="kiosk-dev-file" src="${esc(v.url)}" controls playsinline preload="metadata"></video>` : ''))
            + `<p><a class="kiosk-btn is-small" href="${esc(v.url)}" target="_blank" rel="noopener">▶ ${esc(t('dev_open_video'))}</a></p>`;
        el.innerHTML = header(`<button type="button" class="kiosk-btn is-small" data-back>${esc(t('back'))}</button>`) + `
            <section class="kiosk-card"><h2>${esc(p.name)}</h2>
                <p class="kiosk-status">${esc([p.status, p.type, p.section].filter(Boolean).join(' · '))}</p>
                ${p.stages.length ? `<p><strong>${esc(t('dev_stage'))}</strong> ${esc(p.stages[0].name || '')}${p.stages[0].deadline ? ' · ' + esc(t('dev_deadline', {date: dateFmt(p.stages[0].deadline)})) : ''}</p>
                    ${p.stages[0].description ? `<p class="kiosk-dev-text">${esc(p.stages[0].description)}</p>` : ''}` : ''}</section>
            ${p.notes ? `<section class="kiosk-card"><h2>📝 ${esc(t('dev_notes'))}</h2><p class="kiosk-dev-text">${esc(p.notes)}</p></section>` : ''}
            ${v ? `<section class="kiosk-card"><h2>🎬 ${esc(t('dev_video'))}</h2>${video}</section>` : ''}
            ${p.photos.length ? `<section class="kiosk-card"><h2>📷 ${esc(t('dev_photos', {n: p.photos.length}))}</h2><div class="kiosk-dev-photos">${p.photos.map(ph =>
                `<button type="button" class="kiosk-dev-photo" data-photo-open="${ph.id}"><img src="${ph.src}" alt="${esc(ph.caption || p.name)}">${ph.caption ? `<span>${esc(ph.caption)}</span>` : ''}</button>`).join('')}</div></section>` : ''}
            ${p.recipe ? `<section class="kiosk-card"><h2>📖 ${esc(t('dev_recipe', {name: p.recipe.name}))}</h2>
                <div class="kiosk-row kiosk-rec-presets"><label class="kiosk-rec-custom">${esc(t('rec_custom_times'))}<input data-times inputmode="decimal" value="1"></label></div>
                <p class="kiosk-status" data-dev-for></p><div data-dev-tree></div></section>`
                : `<section class="kiosk-card"><p class="kiosk-muted">${esc(t('dev_no_recipe'))}</p></section>`}
            ${p.stages.length > 1 ? `<section class="kiosk-card"><details><summary>${esc(t('dev_history', {n: p.stages.length}))}</summary><ul class="kiosk-dev-stages">${p.stages.map(s =>
                `<li><strong>${esc(s.name || '')}</strong>${s.deadline ? ' · ' + esc(dateFmt(s.deadline)) : ''}${s.description ? `<div class="kiosk-muted">${esc(s.description)}</div>` : ''}</li>`).join('')}</ul></details></section>` : ''}`;
        el.querySelector('[data-back]').addEventListener('click', () => renderDev());
        const draw = () => {
            if (!p.recipe) return;
            el.querySelector('[data-dev-tree]').innerHTML = recNode(p.recipe, factor, 0);
            el.querySelector('[data-dev-for]').textContent = t('dev_for', {q: fmt(factor), yield: measure(p.recipe.yield * factor, p.recipe.unit)});
        };
        el.querySelector('[data-times]')?.addEventListener('input', (e) => {
            resetIdle();
            const n = Number(String(e.target.value).replace(',', '.').replace(/\s/g, ''));
            if (Number.isFinite(n) && n > 0) { factor = n; draw(); }
        });
        el.querySelectorAll('[data-photo-open]').forEach(b => b.addEventListener('click', () => {
            const ph = p.photos.find(x => x.id === Number(b.dataset.photoOpen));
            const box = document.createElement('div');
            box.className = 'kiosk-lightbox';
            box.innerHTML = `<img src="${ph.src}" alt=""><button type="button" class="kiosk-btn">✕ ${esc(t('dev_close'))}</button>`;
            box.addEventListener('click', () => box.remove());
            document.body.appendChild(box);
            resetIdle();
        }));
        draw();
    }

    // --- Maszyny (TRS) : stan maszyny jednym dotknięciem — praca, postój z powodem, koniec pracy ; TRS dnia ----------
    const REASON_ICON = {breakdown: '🔧', setup: '⚙️', waiting: '⏳', cleaning: '🧽', break: '☕', other: '✏️'};
    const pct = (v) => v === null || v === undefined ? '—' : `${fmt(v)} %`;
    const level = (v) => v === null || v === undefined ? '' : (v >= 85 ? 'is-ok' : (v >= 60 ? 'is-warn' : 'is-bad'));

    async function renderMachines(message) {
        longIdle = true;
        resetIdle();
        let d;
        try { d = await api('GET', '/kiosk/machines', undefined, true); } catch (err) { if (!err.code || err.code === 'DEVICE_WITHOUT_GROUP') toast(err.message, true); return; }
        drawMachines(d, message);
    }

    function timeline(m) {
        const segs = m.segments || [];
        if (!segs.length) return '';
        const t0 = new Date(segs[0].start.replace(' ', 'T')).getTime();
        const end = (sg) => sg.end ? new Date(sg.end.replace(' ', 'T')).getTime() : Date.now();
        const t1 = Math.max(...segs.map(end));
        const span = Math.max(60000, t1 - t0);
        return `<div class="kiosk-timeline" aria-hidden="true">${segs.map(sg => {
            const a = (new Date(sg.start.replace(' ', 'T')).getTime() - t0) / span * 100;
            const w = Math.max(0.5, (end(sg) - new Date(sg.start.replace(' ', 'T')).getTime()) / span * 100);
            const cls = sg.state === 'run' ? 'is-run' : (['cleaning', 'break'].includes(sg.reason) ? 'is-planned' : 'is-stop');
            return `<span class="${cls}" style="left:${a}%;width:${w}%" title="${esc(hhmm(sg.start))}–${esc(sg.end ? hhmm(sg.end) : '…')} ${esc(sg.state === 'run' ? t('mach_run_short') : t('reason.' + sg.reason))}"></span>`;
        }).join('')}</div><div class="kiosk-timeline-scale"><span>${esc(hhmm(segs[0].start))}</span><span>${esc(new Date(t1).toTimeString().slice(0, 5))}</span></div>`;
    }

    function machineCard(m, reasons) {
        const st = m.state;
        const cls = st ? (st.state === 'run' ? 'is-run' : 'is-stop') : 'is-off';
        const status = !st ? `⏹ ${t('mach_off_state')}` : (st.state === 'run' ? `▶ ${t('mach_running', {time: hhmm(st.since)})}`
            : `${REASON_ICON[st.reason] || '⏸'} ${t('mach_stopped', {reason: t('reason.' + st.reason), time: hhmm(st.since)})}${st.note ? ' — ' + st.note : ''}`);
        const tiles = [['availability', 'kpi_availability'], ['performance', 'kpi_performance'], ['quality', 'kpi_quality'], ['oee', 'kpi_oee']];
        return `<article class="kiosk-card kiosk-machine ${cls}" data-machine="${m.id}">
            <div class="kiosk-machine-head"><div><h3>${m.collaborative ? '🤖 ' : ''}${esc(m.name)}</h3><div class="kiosk-muted">${esc(m.code)}</div></div>
                <div class="kiosk-machine-state">${esc(status)}${st && st.stale ? `<div class="kiosk-error">⚠ ${esc(t('mach_stale'))}</div>` : ''}</div></div>
            <div class="kiosk-machine-actions">
                ${!st || st.state !== 'run' ? `<button type="button" class="kiosk-btn is-ok" data-run>▶ ${esc(t('mach_run'))}</button>` : ''}
                <button type="button" class="kiosk-btn is-danger" data-stop>⏸ ${esc(t(st && st.state === 'stop' ? 'mach_stop_change' : 'mach_stop'))}</button>
                ${st ? `<button type="button" class="kiosk-btn" data-off>⏹ ${esc(t('mach_off'))}</button>` : ''}</div>
            <div class="kiosk-reasons" data-reasons hidden>${reasons.map(r => `<button type="button" class="kiosk-btn kiosk-reason" data-reason="${r}"><span aria-hidden="true">${REASON_ICON[r]}</span> ${esc(t('reason.' + r))}</button>`).join('')}
                <label class="kiosk-reason-note">${esc(t('mach_note'))}<input data-note maxlength="255"></label></div>
            <div class="kiosk-oee">${tiles.map(([k, label]) => `<div class="kiosk-oee-tile ${k === 'oee' ? 'is-main ' : ''}${level(m[k])}"><span>${esc(t(label))}</span><strong>${pct(m[k])}</strong></div>`).join('')}</div>
            <p class="kiosk-muted">${esc(t('mach_details', {run: fmt(m.run_h), stop: fmt(m.stop_h), planned: fmt(m.planned_stop_h), pieces: fmt(m.pieces), ideal: fmt(m.ideal_h)}))}
                ${m.performance_raw !== null && m.performance_raw > 110 ? ' · ⚠ ' + esc(t('mach_perf_over', {pct: fmt(m.performance_raw)})) : ''}</p>
            ${m.has_routing ? '' : `<p class="kiosk-muted">${esc(t('mach_no_routing'))}</p>`}
            ${timeline(m)}
            ${m.stops.length ? `<ul class="kiosk-stops">${m.stops.map(x => `<li>${REASON_ICON[x.reason] || ''} ${esc(t('reason.' + x.reason))}${x.planned ? ' <span class="kiosk-muted">(' + esc(t('mach_planned')) + ')</span>' : ''} · <strong>${x.minutes < 1 ? '&lt; 1' : fmt(x.minutes)} min</strong></li>`).join('')}</ul>` : ''}
        </article>`;
    }

    function drawMachines(d, message) {
        el.innerHTML = personHeader() + tabs('machines') + `
            <section class="kiosk-card"><h2>${esc(t('mach_title'))}</h2><p class="kiosk-muted">${esc(t('mach_help'))}</p></section>
            ${d.machines.length ? d.machines.map(m => machineCard(m, d.stop_reasons)).join('') : `<section class="kiosk-card"><p class="kiosk-muted">${esc(t('mach_empty'))}</p></section>`}`;
        if (message) toast(message);
        bindTabs();
        const send = async (card, body, done) => {
            card.querySelectorAll('button').forEach(b => b.disabled = true);
            try {
                const r = await api('POST', `/kiosk/machines/${card.dataset.machine}/state`, {...body, client_uuid: uuid()}, true);
                drawMachines(r, done);
            } catch (err) {
                card.querySelectorAll('button').forEach(b => b.disabled = false);
                toast(err.offline ? t('offline_retry') : err.message, true);
            }
        };
        el.querySelectorAll('[data-machine]').forEach(card => {
            const m = d.machines.find(x => x.id === Number(card.dataset.machine));
            card.querySelector('[data-run]')?.addEventListener('click', () => send(card, {state: 'run'}, t('mach_saved_run', {name: m.name})));
            card.querySelector('[data-off]')?.addEventListener('click', () => { if (window.confirm(t('mach_off_confirm', {name: m.name}))) send(card, {state: 'off'}, t('mach_saved_off', {name: m.name})); });
            card.querySelector('[data-stop]').addEventListener('click', () => { const box = card.querySelector('[data-reasons]'); box.hidden = !box.hidden; resetIdle(); });
            card.querySelectorAll('[data-reason]').forEach(b => b.addEventListener('click', () => {
                const reason = b.dataset.reason;
                const note = card.querySelector('[data-note]').value.trim();
                if (reason === 'other' && !note) { toast(t('mach_note_required'), true); card.querySelector('[data-note]').focus(); return; }
                send(card, {state: 'stop', reason, note: note || null}, t('mach_saved_stop', {name: m.name, reason: t('reason.' + reason)}));
            }));
        });
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
        stopScan();
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
                ${canScan() ? `<button type="button" class="kiosk-btn is-small is-primary" data-scan>🎥 ${esc(t('lot_scan'))}</button>` : ''}
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
        el.querySelector('[data-back]').addEventListener('click', () => { stopScan(); renderProduction(); });
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
            card.querySelector('.kiosk-photo')?.addEventListener('click', () => { stopScan(); busy = true; resetIdle(); });
            card.querySelector('[data-scan]')?.addEventListener('click', () => scan(card, pending[m] = {}));
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

    // Skanowanie kamerą (wideo) : klatka co ~1 s do OCR, aż numer partii pojawi się w dwóch odczytach (albo dotknięcie
    // kandydata) ; klatka z odczytem zostaje jako zdjęcie etykiety. Kamera wymaga HTTPS — bez niej zostaje zdjęcie.
    const canScan = () => !!(window.isSecureContext && navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
    let scanning = null; // {stream, timer, card}

    function stopScan() {
        if (!scanning) return;
        const s = scanning;
        scanning = null;
        window.clearTimeout(s.timer);
        s.stream.getTracks().forEach(tr => tr.stop());
        const v = s.card.querySelector('[data-video]');
        if (v) v.srcObject = null;
        s.card.querySelector('[data-scan-view]')?.remove();
        busy = false;
        resetIdle();
    }

    async function scan(card, slot) {
        stopScan();
        const box = card.querySelector('[data-ocr]');
        box.hidden = false;
        box.innerHTML = `<div class="kiosk-scan" data-scan-view><video data-video autoplay playsinline muted></video><div class="kiosk-scan-frame" aria-hidden="true"></div></div>
            <p class="kiosk-muted" data-scan-msg>${esc(t('scan_starting'))}</p><div class="kiosk-row" data-scan-chips></div>
            <div class="kiosk-row"><button type="button" class="kiosk-btn is-small" data-scan-stop>⏹ ${esc(t('scan_stop'))}</button></div>`;
        const msg = box.querySelector('[data-scan-msg]');
        const chips = box.querySelector('[data-scan-chips]');
        box.querySelector('[data-scan-stop]').addEventListener('click', () => { stopScan(); msg.textContent = t('scan_stopped'); });
        busy = true;
        let stream;
        try {
            stream = await navigator.mediaDevices.getUserMedia({video: {facingMode: {ideal: 'environment'}, width: {ideal: 1280}, height: {ideal: 720}}, audio: false});
        } catch (e) {
            busy = false;
            box.querySelector('[data-scan-view]')?.remove();
            msg.textContent = t('scan_denied');
            return;
        }
        const video = box.querySelector('[data-video]');
        video.srcObject = stream;
        const state = scanning = {stream, timer: null, card};
        const seen = {}; // numer => liczba odczytów
        const dates = {};
        const started = Date.now();
        const lotInput = card.querySelector('[name=lot]');
        const bbInput = card.querySelector('[name=bb]');
        const showChips = () => {
            const lots = Object.keys(seen).sort((a, b) => seen[b] - seen[a]).slice(0, 4);
            const ds = Object.keys(dates).sort((a, b) => dates[b] - dates[a]).slice(0, 2);
            chips.innerHTML = lots.map(l => `<button type="button" class="kiosk-btn is-small" data-lot="${esc(l)}">${esc(t('ocr_lot'))} ${esc(l)}</button>`).join('')
                + ds.map(d => `<button type="button" class="kiosk-btn is-small" data-date="${esc(d)}">${esc(t('ocr_date'))} ${esc(d.split('-').reverse().join('.'))}</button>`).join('');
        };
        chips.addEventListener('click', (e) => {
            const l = e.target.closest('[data-lot]');
            const d = e.target.closest('[data-date]');
            if (l) { lotInput.value = l.dataset.lot; stopScan(); msg.textContent = t('scan_found', {lot: l.dataset.lot}); }
            if (d) bbInput.value = d.dataset.date;
        });
        const frame = () => {
            const w = video.videoWidth, h = video.videoHeight;
            if (!w || !h) return null;
            // Środek obrazu (ramka celownika) : mniej tła, szybszy OCR.
            const cw = Math.round(w * 0.8), ch = Math.round(h * 0.6);
            const canvas = document.createElement('canvas');
            const scale = Math.min(1, 1400 / cw);
            canvas.width = Math.round(cw * scale);
            canvas.height = Math.round(ch * scale);
            canvas.getContext('2d').drawImage(video, (w - cw) / 2, (h - ch) / 2, cw, ch, 0, 0, canvas.width, canvas.height);
            return canvas;
        };
        const tick = async () => {
            if (scanning !== state) return;
            resetIdle();
            if (Date.now() - started > 60000) { stopScan(); msg.textContent = t('scan_timeout'); return; }
            const canvas = frame();
            if (canvas) {
                msg.textContent = t('scan_looking');
                let text = '';
                try { text = await ocr(canvas, () => {}); } catch (e) { stopScan(); msg.textContent = t('ocr_unavailable'); return; }
                if (scanning !== state) return;
                const found = lotCandidates(text);
                found.dates.forEach(d => { dates[d] = (dates[d] || 0) + 1; });
                let best = null;
                found.lots.forEach(l => { seen[l] = (seen[l] || 0) + 1; if (seen[l] >= 2 && (!best || seen[l] > seen[best])) best = l; });
                if (found.lots.length || found.dates.length) {
                    slot.photo = canvas.toDataURL('image/jpeg', 0.75);
                    slot.ocr = text.trim() || null;
                    showChips();
                }
                if (best) {
                    lotInput.value = best;
                    const d = Object.keys(dates).sort((a, b) => dates[b] - dates[a])[0];
                    if (d && !bbInput.value) bbInput.value = d;
                    stopScan();
                    msg.textContent = t('scan_found', {lot: best});
                    return;
                }
            }
            state.timer = window.setTimeout(tick, 700);
        };
        video.addEventListener('loadeddata', () => { state.timer = window.setTimeout(tick, 400); }, {once: true});
    }

    function loadImage(file) {
        if (window.createImageBitmap) return createImageBitmap(file, {imageOrientation: 'from-image'}).catch(() => createImageBitmap(file));
        return new Promise((resolve, reject) => { const img = new Image(); img.onload = () => resolve(img); img.onerror = reject; img.src = URL.createObjectURL(file); });
    }

    let ocrWorker = null;
    let ocrProgress = () => {}; // postęp bieżącego odczytu (zdjęcie albo klatka kamery)
    async function ocr(canvas, progress) {
        ocrProgress = progress;
        if (!window.Tesseract) {
            await new Promise((resolve, reject) => {
                const s = document.createElement('script');
                s.src = 'https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/tesseract.min.js';
                s.onload = resolve; s.onerror = reject;
                document.head.appendChild(s);
            });
        }
        if (!ocrWorker) ocrWorker = await window.Tesseract.createWorker('eng', 1, {logger: (m) => { if (m.status === 'recognizing text') ocrProgress(Math.round(m.progress * 100)); }});
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
            session ? openTab(session.tab || 'clock') : (week ? renderWeek(week.date, week.display) : renderHome());
        }
    });
    ['pointerdown', 'keydown'].forEach(ev => document.addEventListener(ev, () => { if (session) resetIdle(); else weekIdle(); }, {passive: true}));
    window.setInterval(() => { const c = el.querySelector('[data-clock]'); if (c) c.textContent = new Date().toTimeString().slice(0, 5); }, 10000);
    window.addEventListener('hashchange', () => { if (location.hash === '#week' && device && !session) renderWeek(null, true); });
    loadLabels().then(() => (device ? (location.hash === '#week' ? renderWeek(null, true) : renderHome()) : renderPairing()));
})();
