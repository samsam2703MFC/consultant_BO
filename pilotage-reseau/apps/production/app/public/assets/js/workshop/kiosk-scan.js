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

    function message(text, error, withLeave) {
        el.innerHTML = header() + `<section class="kiosk-card kiosk-phone-msg"><p class="${error ? 'kiosk-error' : 'kiosk-status'}">${esc(text)}</p>
            ${error ? `<p class="kiosk-muted">${esc(t('scan_help'))}</p>` : ''}${store.get(PHONE_KEY) ? `<button type="button" class="kiosk-btn is-small" data-forget>${esc(t('not_me'))}</button>` : ''}</section>
            ${withLeave ? '<section class="kiosk-card kiosk-leave" data-leave-box></section>' : ''}`;
        if (withLeave) leaveBox();
    }

    // =====================================================================================================
    // [288] Urlop z telefonu (makieta 49) : saldo, wniosek od–do (kalendarz), podsumowanie, « Moje urlopy ».
    // Wniosek zatwierdza kierownik produkcji w ERP (Urlopy i dni wolne) ; status widać przy następnym skanie.
    // =====================================================================================================
    let leave = null;
    const ld = (d) => d ? `${d.slice(8, 10)}.${d.slice(5, 7)}` : '—';
    const ldy = (d) => d ? `${ld(d)}.${d.slice(0, 4)}` : '—';
    const addD = (d, n) => { const x = new Date(d + 'T12:00:00'); x.setDate(x.getDate() + n); return x.toISOString().slice(0, 10); };
    const isWork = (d) => { const w = new Date(d + 'T12:00:00').getDay(); return w !== 0 && w !== 6 && !(leave.holidays || []).includes(d); };
    const countWork = (from, to) => { let n = 0; for (let d = from; d <= to; d = addD(d, 1)) if (isWork(d)) n++; return n; };
    const balFor = (d) => leave.balances[String(Number(String(d || leave.today).slice(0, 4)))] || leave.balances[String(leave.year)];

    async function leaveBox() {
        const box = el.querySelector('[data-leave-box]');
        if (!box) return;
        try { leave = await api('GET', '/kiosk/phone/leave'); } catch (err) { box.remove(); return; }
        const b = leave.balances[String(leave.year)];
        const pend = leave.requests.filter(r => r.status === 'pending').length;
        box.innerHTML = `<div class="kiosk-leave-tiles"><div><small>${esc(t('leave_left', {year: leave.year}))}</small><b>${b.remaining} <span>${esc(t('leave_days'))}</span></b></div>
                <div><small>${esc(t('leave_carry', {year: leave.year - 1}))}</small><b>${b.carry_left}</b>${b.carry_left ? `<span class="kiosk-muted">${esc(t('leave_until', {date: ldy(b.carry_deadline)}))}</span>` : ''}</div></div>
            ${b.pending ? `<p class="kiosk-muted">${esc(t('leave_pending_days', {days: b.pending}))}</p>` : ''}
            <button type="button" class="kiosk-btn kiosk-big is-primary" data-leave-new><i aria-hidden="true">🏖</i> ${esc(t('leave_request'))}</button>
            <button type="button" class="kiosk-btn" data-leave-list>${esc(t('leave_mine', {n: leave.requests.length + leave.absences.length}))}${pend ? ` · ${esc(t('leave_status_pending'))} ${pend}` : ''}</button>`;
        box.querySelector('[data-leave-new]').addEventListener('click', () => leaveForm({type: 'urlop', from: null, to: null, month: leave.today.slice(0, 7)}));
        box.querySelector('[data-leave-list]').addEventListener('click', () => leaveList());
    }

    function leaveBack() {
        return `<button type="button" class="kiosk-btn is-small" data-leave-home>‹ ${esc(t('back'))}</button>`;
    }

    function bindBack() {
        const b = el.querySelector('[data-leave-home]');
        if (b) b.addEventListener('click', () => renderClock());
    }

    function leaveForm(st, error) {
        const b = balFor(st.from);
        const odLeft = b.on_demand_left;
        const [y, m] = st.month.split('-').map(Number);
        const first = `${st.month}-01`;
        const startPad = (new Date(first + 'T12:00:00').getDay() + 6) % 7;
        const last = new Date(y, m, 0).getDate();
        const months = lang === 'fr' ? ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre']
            : ['styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];
        const dows = lang === 'fr' ? ['lu', 'ma', 'me', 'je', 've', 'sa', 'di'] : ['pn', 'wt', 'śr', 'cz', 'pt', 'sb', 'nd'];
        const cells = [];
        for (let i = 0; i < startPad; i++) cells.push('<span></span>');
        for (let n = 1; n <= last; n++) {
            const d = `${st.month}-${String(n).padStart(2, '0')}`;
            const off = d < leave.today || d > leave.max_date;
            const cls = [isWork(d) ? '' : 'is-off', st.from && st.to && d > st.from && d < st.to ? 'is-in' : '', d === st.from || d === st.to ? 'is-end' : '', d === leave.today ? 'is-today' : ''].join(' ');
            cells.push(`<button type="button" class="kiosk-cal-d ${cls}" data-day="${d}" ${off ? 'disabled' : ''}>${n}</button>`);
        }
        const prevM = m === 1 ? `${y - 1}-12` : `${y}-${String(m - 1).padStart(2, '0')}`;
        const nextM = m === 12 ? `${y + 1}-01` : `${y}-${String(m + 1).padStart(2, '0')}`;
        el.innerHTML = header() + `<section class="kiosk-card kiosk-leave-form">${leaveBack()}
                <h2>${esc(t('leave_request_title'))}</h2><p class="kiosk-muted">${esc(t('leave_pick'))}</p>
                <div class="kiosk-leave-types"><button type="button" class="kiosk-chip ${st.type === 'urlop' ? 'is-on' : ''}" data-type="urlop">${esc(t('leave_type_urlop'))}</button>
                    <button type="button" class="kiosk-chip ${st.type === 'na_zadanie' ? 'is-on' : ''}" data-type="na_zadanie" ${odLeft ? '' : 'disabled'}>${esc(t('leave_type_od', {n: odLeft}))}</button></div>
                <div class="kiosk-cal-head"><button type="button" class="kiosk-btn is-small" data-month="${prevM}" ${prevM < leave.today.slice(0, 7) ? 'disabled' : ''} aria-label="‹">‹</button>
                    <b>${months[m - 1]} ${y}</b><button type="button" class="kiosk-btn is-small" data-month="${nextM}" ${nextM > leave.max_date.slice(0, 7) ? 'disabled' : ''} aria-label="›">›</button></div>
                <div class="kiosk-cal">${dows.map(x => `<small>${x}</small>`).join('')}${cells.join('')}</div>
                <div class="kiosk-leave-range"><div><small>${esc(t('leave_from'))}</small><b>${ldy(st.from)}</b></div><div><small>${esc(t('leave_to'))}</small><b>${ldy(st.to)}</b></div></div>
                <p class="kiosk-error" data-error>${esc(error || '')}</p>
                <button type="button" class="kiosk-btn kiosk-big is-primary" data-next ${st.from && st.to ? '' : 'disabled'}>${esc(t('next'))}</button></section>`;
        bindBack();
        el.querySelectorAll('[data-type]').forEach(x => x.addEventListener('click', () => leaveForm({...st, type: x.dataset.type})));
        el.querySelectorAll('[data-month]').forEach(x => x.addEventListener('click', () => leaveForm({...st, month: x.dataset.month})));
        el.querySelectorAll('[data-day]').forEach(x => x.addEventListener('click', () => {
            const d = x.dataset.day;
            // Pierwszy dotyk = od ; drugi = do (wcześniejszy dzień zaczyna od nowa).
            if (!st.from || st.to || d < st.from) leaveForm({...st, from: d, to: null});
            else leaveForm({...st, to: d});
        }));
        el.querySelector('[data-next]').addEventListener('click', () => {
            if (st.from.slice(0, 4) !== st.to.slice(0, 4)) return leaveForm(st, t('leave_one_year'));
            if (!countWork(st.from, st.to)) return leaveForm(st, t('leave_no_workdays'));
            leaveSummary(st);
        });
    }

    function leaveSummary(st, error) {
        const b = balFor(st.from);
        const n = countWork(st.from, st.to);
        let cal = 0; for (let d = st.from; d <= st.to; d = addD(d, 1)) cal++;
        const hol = (leave.holidays || []).filter(d => d >= st.from && d <= st.to && ![0, 6].includes(new Date(d + 'T12:00:00').getDay()));
        const after = b.available - n;
        const entitled = b.right + (b.adjust || 0) + b.carry - b.carry_lapsed;
        const carryNote = b.carry_left ? t('leave_carry_first', {days: b.carry_left, date: ldy(b.carry_deadline)})
            : (after > 0 ? t('leave_carry_next', {days: after, year: Number(st.from.slice(0, 4)) + 1, date: ldy(`${Number(st.from.slice(0, 4)) + 1}${b.carry_deadline.slice(4)}`)}) : '');
        el.innerHTML = header() + `<section class="kiosk-card kiosk-leave-form">${leaveBack()}
                <h2>${ldy(st.from)}${st.to !== st.from ? ' – ' + ldy(st.to) : ''}</h2>
                <p class="kiosk-muted">${esc(t('leave_span', {cal, work: n}))}${hol.length ? ' (' + esc(hol.map(ld).join(', ')) + ' ' + esc(t('leave_holiday')) + ')' : ''} · ${esc(st.type === 'urlop' ? t('leave_type_urlop') : t('leave_type_od', {n: b.on_demand_left}))}</p>
                <div class="kiosk-leave-tiles is-4"><div><small>${esc(t('leave_entitled'))}</small><b>${entitled}</b></div><div><small>${esc(t('leave_taken'))}</small><b>${b.used + b.planned + b.pending}</b></div>
                    <div><small>${esc(t('leave_this'))}</small><b>${n}</b></div><div class="${after < 0 ? 'is-bad' : ''}"><small>${esc(t('leave_after'))}</small><b>${after}</b></div></div>
                ${carryNote ? `<p class="kiosk-leave-note">${esc(carryNote)}</p>` : ''}
                <label class="kiosk-field"><span>${esc(t('leave_note'))}</span><input type="text" maxlength="255" data-note value="${esc(st.note || '')}"></label>
                <p class="kiosk-error" data-error>${esc(error || '')}</p>
                <button type="button" class="kiosk-btn kiosk-big is-primary" data-send ${after < 0 ? 'disabled' : ''}>${esc(t('leave_send'))}</button>
                <p class="kiosk-muted kiosk-center">${esc(t('leave_send_help'))}</p></section>`;
        bindBack();
        const send = el.querySelector('[data-send]');
        send.addEventListener('click', async () => {
            send.disabled = true;
            const note = el.querySelector('[data-note]').value;
            try {
                const r = await api('POST', '/kiosk/phone/leave', {type: st.type, from: st.from, to: st.to, note});
                leave = await api('GET', '/kiosk/phone/leave');
                leaveList(r.message || t('leave_sent'));
            } catch (err) {
                send.disabled = false;
                el.querySelector('[data-error]').textContent = err.offline ? t('offline_retry') : err.message;
            }
        });
    }

    function leaveList(info) {
        const b = leave.balances[String(leave.year)];
        const st = {pending: ['is-warn', 'leave_status_pending'], approved: ['is-ok', 'leave_status_approved'], rejected: ['is-bad', 'leave_status_rejected'], cancelled: ['', 'leave_status_cancelled']};
        // Zatwierdzone wnioski są już urlopem w kalendarzu : pokazujemy wpis z kalendarza (bez podwójnej linii).
        const rows = leave.requests.filter(r => r.status !== 'approved').map(r => ({from: r.from, to: r.to, days: r.days, type: r.type, status: r.status, id: r.id, note: r.decision_note}))
            .concat(leave.absences.map(a => ({from: a.from, to: a.to, days: a.days, type: a.type, status: 'approved'})))
            .sort((a, c) => c.from.localeCompare(a.from));
        el.innerHTML = header() + `<section class="kiosk-card kiosk-leave-form">${leaveBack()}
                ${info ? `<p class="kiosk-status">${esc(info)}</p>` : ''}
                <h2>${esc(t('leave_mine_title', {year: leave.year}))}</h2><p class="kiosk-muted">${esc(t('leave_balance_line', {left: b.remaining, total: b.right + (b.adjust || 0) + b.carry - b.carry_lapsed}))}</p>
                <ul class="kiosk-leave-list">${rows.map(r => `<li><div><b>${ld(r.from)}${r.to !== r.from ? ' – ' + ld(r.to) : ''}</b> · ${r.days} ${esc(t('leave_days'))}${r.type === 'na_zadanie' ? ' (' + esc(t('leave_od_short')) + ')' : ''}
                        ${r.status === 'rejected' && r.note ? `<span class="kiosk-muted">${esc(r.note)}</span>` : ''}</div>
                    <span class="kiosk-tag ${st[r.status][0]}">${esc(t(st[r.status][1]))}</span>
                    ${r.status === 'pending' ? `<button type="button" class="kiosk-btn is-small" data-cancel="${r.id}">${esc(t('leave_cancel'))}</button>` : ''}</li>`).join('') || `<li class="kiosk-muted">${esc(t('leave_none'))}</li>`}</ul>
                <button type="button" class="kiosk-btn kiosk-big is-primary" data-leave-new>${esc(t('leave_request'))}</button></section>`;
        bindBack();
        el.querySelector('[data-leave-new]').addEventListener('click', () => leaveForm({type: 'urlop', from: null, to: null, month: leave.today.slice(0, 7)}));
        el.querySelectorAll('[data-cancel]').forEach(x => x.addEventListener('click', async () => {
            x.disabled = true;
            try { await api('DELETE', '/kiosk/phone/leave/' + x.dataset.cancel); leave = await api('GET', '/kiosk/phone/leave'); leaveList(t('leave_cancelled')); }
            catch (err) { x.disabled = false; alert(err.message); }
        }));
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
            <section class="kiosk-card kiosk-leave" data-leave-box></section>
            <button type="button" class="kiosk-btn is-small kiosk-phone-forget" data-forget>${esc(t('not_me'))}</button>`;
        leaveBox();
        el.querySelector('[data-clock-tap]').addEventListener('click', (e) => {
            const direction = e.currentTarget.dataset.clockTap;
            const questions = me.questions?.[direction] || [];
            const sign = !!me.signature?.[direction];
            if (questions.length || sign) renderQuestions(questions, direction, null, sign, me.questions?.catalog);
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
    function renderQuestions(questions, direction, error, sign, catalog) {
        // [263] Punkty do potwierdzenia i pytania Tak / Nie (np. towar przy wyjściu, (i) z zasadą firmy).
        let refresh = () => {};
        const cq = window.TfbClockQuestions(questions, {t, esc, catalog: catalog || [], onChange: () => refresh()});
        el.innerHTML = header() + `<section class="kiosk-card kiosk-confirm">
                <h2>${esc(t(direction === 'in' ? 'confirm_in' : 'confirm_out'))}</h2>
                ${cq.html}
                ${sign ? `<div class="kiosk-confirm-sign"><p class="kiosk-status">${esc(t(direction === 'in' ? 'sign_in_here' : 'sign_out_here'))}</p>
                    <canvas class="kiosk-sign" data-pad width="700" height="320" aria-label="${esc(t('signature'))}"></canvas>
                    <div class="kiosk-row is-end"><button type="button" class="kiosk-btn is-small" data-clear>${esc(t('clear'))}</button></div></div>` : ''}
                <p class="kiosk-error" data-error>${esc(error || '')}</p>
                <button type="button" class="kiosk-btn kiosk-big ${direction === 'in' ? 'is-ok' : 'is-danger'}" data-confirm>${esc(t('confirm_ok'))}</button>
                <button type="button" class="kiosk-btn is-small" data-back>${esc(t('back'))}</button>
            </section>`;
        const ok = el.querySelector('[data-confirm]');
        const pad = sign ? signaturePad(el.querySelector('[data-pad]'), () => refresh()) : null;
        refresh = () => { ok.disabled = cq.blocked() || (pad !== null && pad.empty()); };
        refresh();
        cq.bind(el);
        el.querySelector('[data-clear]')?.addEventListener('click', () => { pad.clear(); refresh(); });
        ok.addEventListener('click', () => clockTap(ok, direction, cq.answers(), pad ? pad.image() : null));
        el.querySelector('[data-back]').addEventListener('click', () => renderClock());
    }

    // [262] Dzień wolny za nadgodziny (Czas pracy › Nadgodziny) : podpis palcem na telefonie ; « Nie teraz » — przy następnym odbiciu.
    function renderComp(comps, done, error) {
        const c = comps[0];
        const day = new Date(c.day + 'T12:00:00').toLocaleDateString(lang === 'fr' ? 'fr-FR' : 'pl-PL', {weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'});
        el.innerHTML = header() + `<section class="kiosk-card kiosk-confirm">
                <p class="kiosk-status">${esc(done)}</p>
                <h2>${esc(t('comp_title'))}${comps.length > 1 ? ` (${comps.length})` : ''}</h2>
                <p class="kiosk-status"><b>${esc(t('comp_text', {hours: c.hours, day}))}</b></p>
                <p class="kiosk-muted">${esc(t('comp_help'))}</p>
                <div class="kiosk-confirm-sign"><canvas class="kiosk-sign" data-pad width="700" height="320" aria-label="${esc(t('signature'))}"></canvas>
                    <div class="kiosk-row is-end"><button type="button" class="kiosk-btn is-small" data-clear>${esc(t('clear'))}</button></div></div>
                <p class="kiosk-error" data-error>${esc(error || '')}</p>
                <button type="button" class="kiosk-btn kiosk-big is-ok" data-comp-sign disabled>${esc(t('comp_sign'))}</button>
                <button type="button" class="kiosk-btn is-small" data-comp-later>${esc(t('comp_later'))}</button>
            </section>`;
        const ok = el.querySelector('[data-comp-sign]');
        const pad = signaturePad(el.querySelector('[data-pad]'), () => { ok.disabled = pad.empty(); });
        el.querySelector('[data-clear]').addEventListener('click', () => { pad.clear(); ok.disabled = true; });
        el.querySelector('[data-comp-later]').addEventListener('click', () => message(done, false, true));
        ok.addEventListener('click', async () => {
            ok.disabled = true;
            try {
                await api('POST', `/kiosk/phone/comps/${c.id}/sign`, {image: pad.image()});
                comps.shift();
                if (comps.length) return renderComp(comps, done);
                message(done + ' ' + t('comp_done'), false, true);
            } catch (err) {
                ok.disabled = false;
                el.querySelector('[data-error]').textContent = err.offline ? t('offline_retry') : err.message;
            }
        });
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
            const done = direction === 'in' ? t('welcome') : t('bye', {hours: fmt(r.clock.hours)});
            // [262] Dzień wolny za nadgodziny zaproponowany przez kierownika : podpis zaraz po odbiciu.
            const me = await api('GET', '/kiosk/phone/me').catch(() => null);
            if (me && me.comp_days && me.comp_days.length) return renderComp(me.comp_days.slice(), done);
            message(done, false, true);
        } catch (err) {
            button.disabled = false;
            if (['PHONE_UNAUTHENTICATED', 'PHONE_REVOKED'].includes(err.code)) { forget(); return renderPeople(); }
            // Hors ligne : on garde l'écran (même client_uuid, pas de doublon) ; sinon message (QR expiré…).
            if (err.offline) { el.querySelector('[data-error]').textContent = t('offline_retry'); return; }
            // Pytania albo podpis zmienione w międzyczasie : aktualny ekran z komunikatem.
            if (err.code === 'CLOCK_QUESTIONS_REQUIRED' || err.fields?.includes('signature')) {
                const me = await api('GET', '/kiosk/phone/me').catch(() => null);
                if (me) return renderQuestions(me.questions?.[direction] || [], direction, err.message, !!me.signature?.[direction], me.questions?.catalog);
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
