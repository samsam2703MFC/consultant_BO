/*
 * [415] Tablet jakości Kierownika produkcji / Tablette qualité du responsable de production (makieta hali I, J, K).
 * Parowanie tym samym kodem co inne tablety ; potem osoba ze stanowiska Kierownik produkcji albo Jakość + PIN
 * (POST /kiosk/identify). Ekrany :
 *  I. Obchód jakości — partie dnia wszystkich grup : Pilne (sygnał z tabletu grupy), Teraz, Potem, sprawdzone ;
 *  J. Kontrola partii — waga N sztuk (klawiatura), wygląd (+ zdjęcie), worki partii (Zeskanuj sam / Wyślij grupie),
 *     próbka (etykieta QR), « Zapisz kontrolę » ; poza normą : propozycja niezgodności (zgłoś albo zapisz bez NC) ;
 *  K. Śledzenie partii — skan etykiety kartonu albo partii : surowce (skąd), kartony (dokąd), PDF partii, wstrzymanie
 *     partii z potwierdzeniem (i zwolnienie) ;
 *  [432] Obchód chłodni przed zakupami — A. miejsca (chłodnie z rejestru urządzeń + dodane w ERP : zrobione / teraz /
 *     potem), B. jedno miejsce : surowiec po surowcu « W ERP » (stan Zaopatrzenia), « Jest naprawdę » (− / + / klawiatura,
 *     jednostka kg / l → jednostka bazowa), data OK / krótka — najpierw, do wyrzucenia / uszkodzone (ilość + powód),
 *     porządek tak / nie (+ zdjęcie), « Dodaj surowiec, który tu leży », « Zapisz chłodnię » (inwentaryzacja w ERP).
 * Sesja osoby (krótki token) przedłużana co minutę, póki ktoś dotyka ekranu ; po 15 min bez dotyku — wylogowanie.
 * Skaner QR : BarcodeDetector przeglądarki (bez bibliotek z zewnątrz) ; bez niego — wpisanie kodu.
 */
(function () {
    'use strict';
    const VERSION = (document.currentScript && new URL(document.currentScript.src, location.href).searchParams.get('v')) || '';
    const el = document.getElementById('tfbQuality');
    if (!el) return;
    const base = (el.dataset.apiBase || '').replace(/\/$/, '');
    const root = (el.dataset.root || '').replace(/\/$/, '');
    const DEVICE_KEY = 'tfb.kiosk.device';
    const LANG_KEY = 'tfb.lang';
    const IDLE_MS = 15 * 60 * 1000;
    const store = {
        get(k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
        set(k, v) { try { v === null ? window.localStorage.removeItem(k) : window.localStorage.setItem(k, v); } catch (e) { /* stockage indisponible */ } },
    };
    let lang = store.get(LANG_KEY) === 'fr' ? 'fr' : 'pl';
    let labels = {};
    let device = store.get(DEVICE_KEY);
    let session = null;      // {token, exp, id, name}
    let view = 'who';        // who | round | check | trace | cold | coldPlace
    let round = null;        // GET /kiosk/quality-round
    let batch = null;        // GET /kiosk/quality-round/batches/{id}
    let draft = null;        // kontrola w toku : {weights[], appearance, photoId, photoUrl, sampleLater}
    let trace = null;        // {scan, lot}
    let cold = null;         // [432] GET /kiosk/cold-visit
    let place = null;        // [432] GET /kiosk/cold-visit/places/{id}
    let cdraft = null;       // [432] miejsce w toku : {id, rows: {material_id → {qty, date, loss}}, clean, photoId, photoUrl, note, dirty}
    let busy = false;
    let lastTouch = Date.now();
    let camStop = null;

    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const t = (k, p) => { let s = labels[k] ?? k; for (const [a, b] of Object.entries(p || {})) s = s.split('{' + a + '}').join(String(b)); return s; };
    const icon = (id) => `<svg class="i" aria-hidden="true"><use href="#i-${id}"/></svg>`;
    // Grupowanie także dla 4 cyfr (1 044 szt.) : pl-PL grupuje dopiero od 5 cyfr.
    const num = (n, d = 1) => Number(n).toLocaleString(lang === 'fr' ? 'fr-FR' : 'pl-PL', {maximumFractionDigits: d, useGrouping: 'always'});
    const dm = (iso) => iso ? `${iso.slice(8, 10)}.${iso.slice(5, 7)}` : '';
    const dmy = (iso) => iso ? `${iso.slice(8, 10)}.${iso.slice(5, 7)}.${iso.slice(0, 4)}` : '';
    const dayLabel = (iso) => {
        const d = new Date(iso + 'T12:00:00');
        return d.toLocaleDateString(lang === 'fr' ? 'fr-FR' : 'pl-PL', {weekday: 'short'}).replace(/\.$/, '') + '. ' + dm(iso);
    };
    const clock = () => { const d = new Date(); return `${d.getHours()}:${String(d.getMinutes()).padStart(2, '0')}`; };
    const initials = (n) => String(n || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(x => x[0]).join('').toUpperCase();
    const plural = (n, one, few, many) => n === 1 ? one : ([2, 3, 4].includes(n % 10) && ![12, 13, 14].includes(n % 100) ? few : many);
    const position = () => t('pos_' + (round?.employee?.position || batch?.employee?.position || 'KIEROWNIK_PRODUKCJI'));

    async function loadLabels() {
        const res = await fetch(`${root}/resources/lang/ui/${lang}/quality.json${VERSION ? '?v=' + encodeURIComponent(VERSION) : ''}`, {credentials: 'same-origin'});
        labels = res.ok ? await res.json() : {};
        document.documentElement.lang = lang;
    }

    function toast(message, error) {
        document.querySelectorAll('.toast').forEach(n => n.remove());
        const n = document.createElement('div');
        n.className = 'toast' + (error ? ' is-error' : '');
        n.setAttribute('role', error ? 'alert' : 'status');
        n.textContent = message;
        document.body.appendChild(n);
        window.setTimeout(() => n.remove(), error ? 5000 : 2600);
    }

    async function api(method, path, body, withEmployee = true) {
        if (withEmployee && path !== '/kiosk/refresh') await keepSession();
        const headers = {'Accept': 'application/json', 'Accept-Language': lang, 'X-Device-Token': device || ''};
        if (body !== undefined) headers['Content-Type'] = 'application/json';
        if (withEmployee && session) headers['X-Employee-Token'] = session.token;
        let res;
        try {
            res = await fetch(base + path, {method, headers, body: body === undefined ? undefined : JSON.stringify(body), credentials: 'same-origin'});
        } catch (e) { throw new Error(t('offline')); }
        const json = await res.json().catch(() => ({}));
        if (res.ok) return json.data;
        const err = new Error(((json.errors || []).map(x => x.message).join(' ')) || json.message || t('error'));
        err.code = json.description;
        if (['DEVICE_UNAUTHENTICATED', 'DEVICE_DISABLED'].includes(err.code)) {
            if (err.code === 'DEVICE_UNAUTHENTICATED') { device = null; store.set(DEVICE_KEY, null); }
            session = null;
            renderPairing(err.message);
        } else if (withEmployee && ['EMPLOYEE_SESSION_EXPIRED', 'EMPLOYEE_UNAUTHENTICATED', 'QC_ROUND_POSITION'].includes(err.code)) {
            logout(err.message);
        }
        throw err;
    }

    // Jeton osoby krótki (kiosk.employee_token_ttl_s) : przedłużany, póki osoba dotyka tabletu.
    async function keepSession() {
        if (!session || Date.now() < session.exp - 70000) return;
        try {
            const r = await api('POST', '/kiosk/refresh', undefined, true);
            session.token = r.employee_token;
            session.exp = Date.now() + r.expires_in * 1000;
        } catch (e) { /* sieć : przy następnym wywołaniu */ }
    }

    window.setInterval(() => {
        const c = el.querySelector('[data-clock]');
        if (c) c.textContent = clock();
        if (!session) return;
        if (Date.now() - lastTouch > IDLE_MS && !busy) { logout(t('idle_logout')); return; }
        keepSession();
    }, 30000);
    document.addEventListener('pointerdown', () => { lastTouch = Date.now(); }, {passive: true});

    function logout(message) {
        stopCam();
        session = null; round = null; batch = null; draft = null; trace = null; cold = null; place = null; cdraft = null;
        view = 'who';
        closeSheet();
        renderWho();
        if (message) toast(message);
    }

    // ---------------------------------------------------------------------------------------------- Nagłówek
    function top(title, small, right, back) {
        const av = back ? `<button type="button" class="av" data-back aria-label="${esc(t('back'))}">${icon('back')}</button>`
            : (session ? `<button type="button" class="av" data-logout aria-label="${esc(t('logout', {name: session.name}))}" title="${esc(session.name)}">${icon('user')}</button>`
                : `<div class="av">${icon('user')}</div>`);
        return `<header class="top">${av}<div class="who"><b>${esc(title)}</b>${small ? `<small>${esc(small)}</small>` : ''}</div><span class="sp"></span>
            <span class="clock" data-clock>${clock()}</span>${right || ''}</header>`;
    }

    function bindTop() {
        const b = el.querySelector('[data-back]');
        if (b) b.addEventListener('click', () => {
            stopCam();
            // [432] Z jednego miejsca wraca się do listy miejsc obchodu (z pytaniem, gdy liczenie nie jest zapisane).
            if (view === 'coldPlace') { if (cdraft && cdraft.dirty && !window.confirm(t('cold_leave'))) return; openCold(); return; }
            openRound();
        });
        const l = el.querySelector('[data-logout]');
        if (l) l.addEventListener('click', () => { if (window.confirm(t('logout_confirm', {name: session.name}))) logout(); });
    }

    // ---------------------------------------------------------------------------------------------- Parowanie, kto, PIN
    function renderPairing(message) {
        view = 'pair';
        el.innerHTML = top(t('title'), t('pair_title'), `<button type="button" class="lang" data-lang>${esc(t('lang_switch'))}</button>`) + `<div class="body">
            <form class="card panel" data-pair style="max-width:620px"><p class="h2">${esc(t('pair_title'))}</p><p class="hint">${esc(t('pair_help'))}</p>
            <label class="field">${esc(t('pair_code'))}<input name="code" autocomplete="off" autocapitalize="characters" maxlength="12" required></label>
            <p class="err" data-error>${esc(message || '')}</p><button class="btn btn--primary">${esc(t('pair'))}</button></form></div>`;
        bindLang();
        el.querySelector('[data-pair]').addEventListener('submit', async (e) => {
            e.preventDefault();
            try {
                const r = await api('POST', '/kiosk/pair', {code: e.target.code.value}, false);
                device = r.device_token;
                store.set(DEVICE_KEY, device);
                toast(t('paired', {name: r.device.name}));
                renderWho();
            } catch (err) { const n = el.querySelector('[data-error]'); if (n) n.textContent = err.message; }
        });
    }

    function bindLang() {
        const b = el.querySelector('[data-lang]');
        if (b) b.addEventListener('click', async () => { lang = lang === 'fr' ? 'pl' : 'fr'; store.set(LANG_KEY, lang); await loadLabels(); view === 'pair' ? renderPairing() : renderWho(); });
    }

    async function renderWho() {
        view = 'who';
        let people = [];
        try {
            people = await api('GET', '/kiosk/quality-round/people', undefined, false);
        } catch (err) {
            if (err.code === 'DEVICE_UNAUTHENTICATED' || err.code === 'DEVICE_DISABLED') return;
            el.innerHTML = top(t('title'), '') + `<div class="body"><p class="err">${esc(err.message)}</p><button type="button" class="btn md" data-retry>${esc(t('retry'))}</button></div>`;
            el.querySelector('[data-retry]').addEventListener('click', renderWho);
            return;
        }
        el.innerHTML = top(t('title'), '', `<button type="button" class="lang" data-lang>${esc(t('lang_switch'))}</button>`) + `<div class="body">
            <p class="ask">${esc(t('who_title'))}</p><p class="sub">${esc(t('who_sub'))}</p>
            ${people.length ? `<div class="people">${people.map(p => `<button type="button" class="person" data-person="${p.id}" ${p.has_pin ? '' : 'disabled'}>
                <span class="ini">${esc(initials(p.name))}</span><span><b>${esc(p.name)}</b><small>${esc(t('pos_' + p.position))}${p.has_pin ? (p.clocked_in ? ' · ' + esc(t('present')) : '') : ' · ' + esc(t('no_pin'))}</small></span></button>`).join('')}</div>`
                : `<div class="card empty">${esc(t('who_none'))}</div>`}</div>`;
        bindLang();
        el.querySelectorAll('[data-person]').forEach(b => b.addEventListener('click', () => {
            const p = people.find(x => x.id === Number(b.dataset.person));
            if (p) pinSheet(p);
        }));
    }

    function pinSheet(p) {
        let pin = '';
        const box = sheet(`<h3>${esc(t('pin_title', {name: p.name}))}</h3><div class="dots" data-dots></div><p class="err" data-error></p>
            <div class="keypad">${[1, 2, 3, 4, 5, 6, 7, 8, 9].map(n => `<button type="button" class="btn" data-k="${n}">${n}</button>`).join('')}
            <button type="button" class="btn" data-k="del" aria-label="${esc(t('pin_erase'))}">${icon('back')}</button><button type="button" class="btn" data-k="0">0</button>
            <button type="button" class="btn btn--primary" data-k="ok">${esc(t('pad_ok'))}</button></div>
            <button type="button" class="btn md" data-close>${esc(t('cancel'))}</button>`);
        const dots = () => { box.querySelector('[data-dots]').innerHTML = Array.from({length: Math.max(4, pin.length)}, (_, i) => `<i class="${i < pin.length ? 'on' : ''}"></i>`).join(''); };
        dots();
        box.querySelectorAll('[data-k]').forEach(b => b.addEventListener('click', async () => {
            const k = b.dataset.k;
            if (k === 'del') pin = pin.slice(0, -1);
            else if (k === 'ok') {
                if (pin.length < 4 || busy) return;
                busy = true;
                try {
                    const r = await api('POST', '/kiosk/identify', {employee_id: p.id, pin}, false);
                    session = {token: r.employee_token, exp: Date.now() + r.expires_in * 1000, id: r.employee.id, name: r.employee.name};
                    lastTouch = Date.now();
                    closeSheet();
                    busy = false;
                    openRound();
                    return;
                } catch (err) { pin = ''; box.querySelector('[data-error]').textContent = err.message; }
                busy = false;
            } else if (pin.length < 6) pin += k;
            dots();
        }));
    }

    // ---------------------------------------------------------------------------------------------- Okna
    function sheet(inner, wide) {
        closeSheet();
        const v = document.createElement('div');
        v.className = 'veil';
        v.innerHTML = `<div class="sheet ${wide ? 'wide' : ''}" role="dialog" aria-modal="true">${inner}</div>`;
        document.body.appendChild(v);
        v.addEventListener('click', (e) => { if (e.target === v) closeSheet(); });
        v.querySelectorAll('[data-close]').forEach(b => b.addEventListener('click', closeSheet));
        return v.querySelector('.sheet');
    }

    function closeSheet() {
        stopCam();
        document.querySelectorAll('.veil').forEach(n => n.remove());
    }

    // ---------------------------------------------------------------------------------------------- I. Obchód
    async function openRound(data) {
        view = 'round';
        batch = null; draft = null; trace = null;
        try {
            round = data || await api('GET', '/kiosk/quality-round');
        } catch (err) { if (session) toast(err.message, true); return; }
        renderRound();
    }

    function rowSub(r) {
        if (r.status === 'urgent' && r.signal) {
            const s = r.signal.kind === 'group_nc' ? t('signal_group_nc', {at: r.signal.at.slice(11, 16).replace(/^0/, '')}) + (r.signal.text ? ' : ' + r.signal.text : '')
                : (r.signal.text || t('signal_group_report', {number: r.signal.number || ''}));
            return s + t('signal_suffix');
        }
        if (r.checked) {
            const items = (r.checked.items || []).map(i => t('item_' + i)).join(', ');
            return t('checked', {at: r.checked.at}) + (items ? ' — ' + items : '');
        }
        const parts = [t('todo_weight')];
        const n = r.todo.missing_lots;
        if (n) parts.push(t(plural(n, 'todo_lots', 'todo_lots_few', 'todo_lots_many'), {n}) + (r.todo.requested ? ' (' + t('todo_requested') + ')' : ''));
        if (r.todo.lot_reasons) parts.push(t('todo_reason', {n: r.todo.lot_reasons}));
        if (r.todo.sample) parts.push(t('todo_sample'));
        return parts.join(' · ');
    }

    const BADGE = {urgent: 'stop', now: 'warn', later: 'neu', ok: 'ok', warn: 'warn', nc: 'stop'};
    const ROW_ICON = {urgent: 'alert', now: 'scale', later: 'scale', ok: 'done', warn: 'done', nc: 'stop'};

    function renderRound() {
        const r = round;
        const next = r.rows.find(x => x.id === r.next_id) || null;
        const sub = t('round_sub', {position: position(), day: dayLabel(r.date)}) + (r.round ? t('round_no', {no: r.round.no, of: r.round.of}) : '');
        const right = r.to_check ? `<span class="badge warn big">${icon('alert')}${esc(t('to_check', {n: r.to_check}))}</span>` : `<span class="badge ok big">${icon('done')}${esc(t('all_checked'))}</span>`;
        el.innerHTML = top(t('title'), sub, right) + `<div class="body round" style="padding-top:20px"><p class="ask">${esc(t('ask_round'))}</p><p class="sub">${esc(t('sub_round'))}</p>
            <div class="list round-list">${r.rows.length ? r.rows.map(x => `<button type="button" class="li ${next && x.id === next.id ? 'is-next' : ''}" data-row="${x.id}">
                ${icon(ROW_ICON[x.status])}<div class="t"><div>${esc(t('row_title', {group: x.group, product: x.product, lot: x.lot_code}))}</div><div class="s">${esc(rowSub(x))}</div></div>
                <span class="r">${x.hold ? `<span class="badge stop" style="margin-right:8px">${esc(t('st_held'))}</span>` : ''}<span class="badge ${BADGE[x.status]}">${esc(t('st_' + x.status))}</span></span></button>`).join('')
                : `<p class="empty">${esc(t('no_batches'))}</p>`}</div>
            <div class="actions"><button type="button" class="btn" style="flex:1" data-trace>${icon('qr')}${esc(t('trace_btn'))}</button>
                <button type="button" class="btn" style="flex:1" data-cards>${icon('list')}${esc(t('cards_btn'))}</button>
                <button type="button" class="btn" style="flex:1" data-cold>${icon('temp')}${esc(t('cold_btn'))}</button>
                <button type="button" class="btn btn--primary" style="flex:1.6" data-check ${next ? '' : 'disabled'}>${esc(next ? t('check_btn', {product: next.product}) : t('all_checked'))} ${icon('next')}</button></div></div>`;
        bindTop();
        el.querySelectorAll('[data-row]').forEach(b => b.addEventListener('click', () => openCheck(Number(b.dataset.row))));
        el.querySelector('[data-trace]').addEventListener('click', () => openTrace());
        // [432] Obchód chłodni przed zakupami tygodnia.
        el.querySelector('[data-cold]').addEventListener('click', () => openCold());
        // [431] Karty produktów : l'assistant de l'ERP en mode tablette (connexion ERP par QR si le navigateur n'est pas connecté).
        el.querySelector('[data-cards]').addEventListener('click', () => { stopCam(); location.href = `${root}/workshop/products?tablet=1#/`; });
        const c = el.querySelector('[data-check]');
        if (next) c.addEventListener('click', () => openCheck(next.id));
    }

    // ---------------------------------------------------------------------------------------------- J. Kontrola partii
    async function openCheck(id, keepDraft) {
        try {
            batch = await api('GET', `/kiosk/quality-round/batches/${id}`);
        } catch (err) { if (session) toast(err.message, true); return; }
        view = 'check';
        if (!keepDraft || !draft || draft.id !== id) draft = {id, weights: Array(batch.weight.pieces).fill(null), appearance: null, photoId: null, photoUrl: null, sampleLater: false};
        renderCheck();
    }

    function weigh() {
        const w = batch.weight;
        const vals = draft.weights.filter(x => x !== null);
        const avg = vals.length ? Math.round(vals.reduce((a, b) => a + b, 0) / vals.length * 10) / 10 : null;
        const one = (x) => x === null || w.target_g === null ? null : (x < w.min_g ? 'light' : (x > w.max_g ? 'heavy' : 'ok'));
        const complete = vals.length === draft.weights.length;
        return {avg, pieces: draft.weights.map(one), result: !complete ? null : (w.target_g === null ? 'none' : one(avg)), complete};
    }

    function steps() {
        const w = weigh();
        const s = batch.sample.state;
        return {
            weight: w.complete, appearance: draft.appearance !== null,
            lots: batch.missing_lots === 0 || batch.requested !== null,
            sample: s === 'stored' || s === 'not_required' || draft.sampleLater,
        };
    }

    function renderCheck() {
        const b = batch;
        const w = weigh();
        const st = steps();
        const order = ['weight', 'appearance', 'lots', 'sample'];
        const current = order.find(k => !st[k]) || 'save';
        const all = order.every(k => st[k]);
        const prog = [...order, 'save'].map((k, i) => {
            const done = k === 'save' ? false : st[k];
            const cls = done ? 'd' : (k === current ? 'c' : '');
            return `${i ? '<span class="ln"></span>' : ''}<span class="st ${cls}"><i>${done ? icon('check') : i + 1}</i>${esc(t('step_' + k))}</span>`;
        }).join('');
        const n = b.weight.pieces;
        const wTitle = n === 1 ? t('weight_title_one') : t(plural(n, 'weight_title_one', 'weight_title', 'weight_title_many'), {n});
        const wBadge = w.result ? `<span class="badge ${w.result === 'ok' ? 'ok' : (w.result === 'none' ? 'neu' : 'warn')}">${esc(t('w_' + w.result))}</span>` : '';
        const pieces = draft.weights.map((x, i) => {
            const p = w.pieces[i];
            const badge = x === null ? '' : (p === 'ok' ? `<span class="badge ok">${icon('check')}</span>` : (p === 'light' ? `<span class="badge warn">${esc(t('piece_light'))}</span>`
                : (p === 'heavy' ? `<span class="badge warn">${esc(t('piece_heavy'))}</span>` : '')));
            return `<button type="button" class="card piece" data-piece="${i}"><span class="pl">${esc(t('piece', {n: i + 1}))}</span>
                <span class="pv ${x === null ? 'empty' : ''}">${x === null ? '— g' : esc(num(x)) + ' g'}</span>${badge}</button>`;
        }).join('');
        const wLine = b.weight.target_g !== null ? t('weight_line', {target: num(b.weight.target_g), min: num(b.weight.min_g), max: num(b.weight.max_g)}) + (w.avg !== null ? t('weight_avg', {avg: num(w.avg)}) : '')
            : t('weight_no_target') + (w.avg !== null ? t('weight_avg', {avg: num(w.avg)}) : '');
        const aBadge = draft.appearance ? `<span class="badge ${draft.appearance === 'good' ? 'ok' : 'stop'}">${esc(t(draft.appearance === 'good' ? 'w_ok' : 'app_bad'))}</span>` : '';
        // Worki do skanu : surowce śledzone (nieśledzone, np. woda, tylko w śledzeniu partii).
        const lots = [...b.lots.filter(l => l.state !== 'untracked').map(l => lotRow(l)), ...b.components.map(c => `<div class="li">${icon('bag')}<div class="t">${esc(c.name)}<div class="s15">${esc(c.state === 'ok' ? c.lot_code + ' · ' + t('lot_from_group', {producer: c.producer}) : t('lot_from_group', {producer: c.producer}))}</div></div>
            <span class="r badge ${c.state === 'ok' ? 'ok' : 'stop'}">${c.state === 'ok' ? icon('check') : esc(t('b_missing'))}</span></div>`)].join('');
        const missing = b.missing_lots > 0;
        const sample = sampleRow();
        const cardBtn = `<button type="button" class="btn" style="height:60px;font-size:19px;margin-left:12px" data-card>${icon('list')}${esc(t('card_btn'))}</button>`;
        el.innerHTML = top(b.product + (b.piece_weight ? ' ' + num(b.piece_weight) + ' g' : ''), t('check_sub', {lot: b.lot_code, group: b.group.name, at: b.started_at}), (b.hold ? `<span class="badge stop big">${icon('stop')}${esc(t('st_held'))}</span>` : '') + cardBtn, true)
            + `<div class="prog">${prog}</div><div class="body"><div class="two">
            <div class="card panel"><p class="h2">${esc(wTitle)} ${wBadge}</p>
                <div class="pieces" style="grid-template-columns:repeat(${Math.min(n, 3)},1fr)">${pieces}</div>
                <div class="hint">${esc(wLine)}</div>
                <p class="h2" style="margin-top:6px">${esc(t('appearance_title'))} ${aBadge}</p>
                <div class="row3"><button type="button" class="btn md ${draft.appearance === 'good' ? 'is-good' : ''}" data-app="good" aria-pressed="${draft.appearance === 'good'}">${icon('up')}${esc(t('app_good'))}</button>
                    <button type="button" class="btn md ${draft.appearance === 'bad' ? 'is-bad' : ''}" data-app="bad" aria-pressed="${draft.appearance === 'bad'}">${icon('down')}${esc(t('app_bad'))}</button>
                    <label class="btn md" style="position:relative">${draft.photoUrl ? `<img class="thumb" src="${draft.photoUrl}" alt="">` : icon('img')}${esc(t('photo'))}
                        <input type="file" accept="image/*" capture="environment" data-photo style="position:absolute;inset:0;opacity:0;width:100%"></label></div></div>
            <div class="card panel"><p class="h2">${esc(t('lots_title'))}</p>
                <div class="list lots-list">${lots}</div>
                <div class="row3"><button type="button" class="btn sm" data-scan-lot ${missing ? '' : 'disabled'}>${icon('qr')}${esc(t('scan_self'))}</button>
                    <button type="button" class="btn sm" data-send ${missing && !b.requested ? '' : 'disabled'}>${icon('alert')}${esc(b.requested ? t('sent_group', {at: b.requested.at}) : t('send_group'))}</button></div>
                <p class="h2" style="margin-top:6px">${esc(t('sample_title'))}</p>${sample}
                <button type="button" class="btn btn--primary grow ${all ? '' : 'off'}" data-save ${all ? '' : 'aria-disabled="true"'}>${esc(t('save'))} ${icon('next')}</button></div>
            </div></div>`;
        bindTop();
        // [431] Karta produktu du lot : fiche de l'article (étape 1 « Produkt »), sinon création à partir du produit.
        el.querySelector('[data-card]')?.addEventListener('click', () => { stopCam(); location.href = `${root}/workshop/products?tablet=1#/` + (b.item_id ? `item/${b.item_id}/1` : `product/${b.product_id}`); });
        el.querySelectorAll('[data-piece]').forEach(x => x.addEventListener('click', () => weightPad(Number(x.dataset.piece))));
        el.querySelectorAll('[data-app]').forEach(x => x.addEventListener('click', () => { draft.appearance = x.dataset.app; renderCheck(); }));
        el.querySelector('[data-photo]').addEventListener('change', (e) => takePhoto(e.target.files && e.target.files[0]));
        el.querySelector('[data-scan-lot]').addEventListener('click', () => lotSheet());
        el.querySelector('[data-send]').addEventListener('click', sendGroup);
        const sb = el.querySelector('[data-sample]');
        if (sb) sb.addEventListener('click', sampleSheet);
        el.querySelector('[data-save]').addEventListener('click', () => { if (all) save(); });
    }

    function lotRow(l) {
        let sub = '';
        let badge = '';
        if (l.state === 'ok') {
            sub = [l.lot_number, l.supplier, l.best_before ? t('lot_until', {date: dmy(l.best_before)}) : ''].filter(Boolean).join(' · ');
            badge = `<span class="r badge ok">${icon('check')}</span>`;
        } else if (l.state === 'reason') {
            sub = t('lot_reason', {reason: l.missing_reason});
            badge = `<span class="r badge warn">${esc(t('b_reason'))}</span>`;
        } else if (l.state === 'untracked') {
            sub = t('lot_untracked');
            badge = `<span class="r badge neu">${esc(t('b_untracked'))}</span>`;
        } else {
            sub = t('lot_missing');
            badge = `<span class="r badge stop">${esc(t('b_missing'))}</span>`;
        }
        return `<div class="li">${icon('bag')}<div class="t">${esc(l.name)}<div class="s15">${esc(sub)}</div></div>${badge}</div>`;
    }

    function sampleRow() {
        const s = batch.sample.state;
        let text, badge, tap = false;
        if (s === 'stored') { text = t('sample_stored'); badge = `<span class="r badge ok">${esc(t('b_stored'))}</span>`; }
        else if (s === 'not_required') { text = t('sample_none'); badge = `<span class="r badge neu">${esc(t('b_na'))}</span>`; }
        else if (draft.sampleLater) { text = t('sample_skipped'); badge = `<span class="r badge neu">${esc(t('b_later'))}</span>`; tap = true; }
        else { text = t(s === 'planned' ? 'sample_planned' : 'sample_suggested'); badge = `<span class="r badge neu">${esc(t('b_todo'))}</span>`; tap = true; }
        return tap ? `<button type="button" class="li" style="border:1px solid var(--line);border-radius:12px" data-sample>${icon('qr')}<div class="t">${esc(text)}</div>${badge}</button>`
            : `<div class="li" style="border:1px solid var(--line);border-radius:12px">${icon('qr')}<div class="t">${esc(text)}</div>${badge}</div>`;
    }

    function weightPad(i) {
        let v = draft.weights[i] !== null ? String(draft.weights[i]).replace('.', ',') : '';
        const box = sheet(`<h3>${esc(t('pad_title', {n: i + 1}))}</h3><div class="display" data-v></div>
            <div class="keypad">${[1, 2, 3, 4, 5, 6, 7, 8, 9].map(n => `<button type="button" class="btn" data-k="${n}">${n}</button>`).join('')}
            <button type="button" class="btn" data-k=",">,</button><button type="button" class="btn" data-k="0">0</button>
            <button type="button" class="btn" data-k="del" aria-label="${esc(t('pin_erase'))}">${icon('back')}</button></div>
            <div class="row3"><button type="button" class="btn md" data-close>${esc(t('cancel'))}</button><button type="button" class="btn btn--primary md" data-ok>${esc(t('pad_ok'))}</button></div>`);
        const draw = () => { box.querySelector('[data-v]').innerHTML = `${esc(v || '0')} <small>g</small>`; };
        draw();
        box.querySelectorAll('[data-k]').forEach(b => b.addEventListener('click', () => {
            const k = b.dataset.k;
            if (k === 'del') v = v.slice(0, -1);
            else if (k === ',') { if (!v.includes(',')) v = (v || '0') + ','; }
            else if (v.replace(',', '').length < 6 && !(v.includes(',') && v.split(',')[1].length >= 1)) v += k;
            draw();
        }));
        box.querySelector('[data-ok]').addEventListener('click', () => {
            const x = Number(v.replace(',', '.'));
            draft.weights[i] = v && x > 0 ? x : null;
            closeSheet();
            // Następna pusta sztuka od razu (ważenie po kolei).
            const next = draft.weights.findIndex(y => y === null);
            renderCheck();
            if (draft.weights[i] !== null && next >= 0) weightPad(next);
        });
    }

    async function takePhoto(file) {
        if (!file || busy) return;
        busy = true;
        try {
            const read = window.tfbLabelOcr ? await window.tfbLabelOcr.readPhoto(file, 1280) : {dataUrl: await new Promise((res, rej) => { const r = new FileReader(); r.onload = () => res(r.result); r.onerror = rej; r.readAsDataURL(file); })};
            const r = await api('POST', '/kiosk/quality-round/photos', {photo: read.dataUrl});
            draft.photoId = r.id;
            draft.photoUrl = read.dataUrl;
            toast(t('photo_ok'));
            renderCheck();
        } catch (err) { toast(err.message, true); } finally { busy = false; }
    }

    async function sendGroup() {
        if (busy) return;
        busy = true;
        try {
            batch = await api('POST', `/kiosk/quality-round/batches/${batch.id}/lot-requests`);
            toast(t('sent_ok', {group: batch.group.name}));
            renderCheck();
        } catch (err) { toast(err.message, true); } finally { busy = false; }
    }

    // « Zeskanuj sam » : worek bez skanu → partia z rejestru (FEFO) albo numer z etykiety.
    function lotSheet(materialId) {
        const missing = batch.lots.filter(l => l.state === 'missing');
        if (!missing.length) return;
        const l = missing.find(x => x.material_id === materialId) || (missing.length === 1 ? missing[0] : null);
        if (!l) {
            const box = sheet(`<h3>${esc(t('lot_choose_material'))}</h3><div class="list">${missing.map(x => `<button type="button" class="li" data-m="${x.material_id}">${icon('bag')}<div class="t">${esc(x.name)}</div>
                <span class="r badge stop">${esc(t('b_missing'))}</span></button>`).join('')}</div><button type="button" class="btn md" data-close>${esc(t('cancel'))}</button>`);
            box.querySelectorAll('[data-m]').forEach(b => b.addEventListener('click', () => lotSheet(Number(b.dataset.m))));
            return;
        }
        const box = sheet(`<h3>${esc(t('lot_sheet', {name: l.name}))}</h3><p>${esc(t('lot_sheet_help'))}</p>
            ${l.choices.length ? `<div class="list">${l.choices.map(c => `<button type="button" class="li" data-lot="${c.id}">${icon('bag')}<div class="t">${esc(c.lot_number)}
                <div class="s15">${esc([c.supplier, c.received_on ? t('received', {date: dm(c.received_on)}) : '', c.best_before ? t('lot_until', {date: dmy(c.best_before)}) : ''].filter(Boolean).join(' · '))}</div></div>${icon('next')}</button>`).join('')}</div>`
                : `<p>${esc(t('no_choices'))}</p>`}
            <form data-manual class="row3" style="align-items:flex-end"><label class="field" style="flex:2">${esc(t('lot_number'))}<input name="lot" autocomplete="off" autocapitalize="characters" maxlength="64"></label>
                <label class="field" style="flex:1">${esc(t('lot_bb'))}<input name="bb" type="date"></label></form>
            <p class="err" data-error></p>
            <div class="row3"><button type="button" class="btn md" data-close>${esc(t('cancel'))}</button><button type="button" class="btn btn--primary md" data-save-lot>${esc(t('lot_save'))}</button></div>`);
        const send = async (body) => {
            if (busy) return;
            busy = true;
            try {
                batch = await api('PUT', `/kiosk/quality-round/batches/${batch.id}/lots/${l.material_id}`, body);
                closeSheet();
                toast(t('lot_saved'));
                renderCheck();
            } catch (err) { box.querySelector('[data-error]').textContent = err.message; } finally { busy = false; }
        };
        box.querySelectorAll('[data-lot]').forEach(b => b.addEventListener('click', () => send({material_lot_id: Number(b.dataset.lot)})));
        box.querySelector('[data-save-lot]').addEventListener('click', () => {
            const f = box.querySelector('[data-manual]');
            const lot = f.lot.value.trim();
            if (!lot) { f.lot.focus(); return; }
            send({lot_number: lot, ...(f.bb.value ? {best_before: f.bb.value} : {})});
        });
    }

    // Próbka : etykieta QR (asystent « Próbki produkcji ») — skan, kod wpisany, utworzenie, « Później ».
    function sampleSheet() {
        const s = batch.sample;
        const create = s.state === 'suggested';
        const box = sheet(`<h3>${esc(t('sample_sheet'))}</h3><p>${esc(create ? t('sample_suggested') : t('sample_sheet_help'))}</p>
            ${create ? '' : `<div class="cam" style="min-height:300px"><video data-video autoplay playsinline muted></video><span class="fr" aria-hidden="true"></span><span class="h" data-cam-msg></span></div>
            <label class="field">${esc(t('sample_code'))}<input name="code" data-code autocomplete="off" autocapitalize="characters" maxlength="255"></label>`}
            <p class="err" data-error></p>
            <div class="row3"><button type="button" class="btn md" data-later>${esc(t('sample_skip'))}</button>
                <button type="button" class="btn btn--primary md" data-go>${esc(create ? t('sample_create') : t('sample_store'))}</button></div>`, true);
        const send = async (body) => {
            if (busy) return;
            busy = true;
            try {
                batch = await api('POST', `/kiosk/quality-round/batches/${batch.id}/sample`, body);
                draft.sampleLater = false;
                closeSheet();
                toast(body.action === 'create' ? t('sample_created') : t('sample_saved'));
                renderCheck();
                if (body.action === 'create' && batch.sample.state === 'planned') sampleSheet();
            } catch (err) { const n = box.querySelector('[data-error]'); if (n) n.textContent = err.message; } finally { busy = false; }
        };
        box.querySelector('[data-later]').addEventListener('click', () => { draft.sampleLater = true; closeSheet(); renderCheck(); });
        box.querySelector('[data-go]').addEventListener('click', () => {
            if (create) return send({action: 'create'});
            const code = box.querySelector('[data-code]').value.trim();
            if (!code) { box.querySelector('[data-code]').focus(); return; }
            send({action: 'store', code});
        });
        if (!create) startCam(box, (text) => { box.querySelector('[data-code]').value = text; send({action: 'store', code: text}); });
    }

    // Kamera : BarcodeDetector (bez bibliotek z zewnątrz) ; brak — tylko wpisanie kodu.
    async function startCam(box, onCode) {
        stopCam();
        const video = box.querySelector('[data-video]');
        const msg = box.querySelector('[data-cam-msg]');
        if (!video) return;
        const off = () => { if (msg) msg.textContent = t('scan_cam_off'); };
        if (!('BarcodeDetector' in window) || !(window.isSecureContext && navigator.mediaDevices && navigator.mediaDevices.getUserMedia)) { off(); return; }
        let stream;
        let detector;
        let done = false;
        let timer = null;
        try {
            detector = new window.BarcodeDetector({formats: ['qr_code', 'code_128', 'ean_13', 'code_39', 'data_matrix']});
            stream = await navigator.mediaDevices.getUserMedia({video: {facingMode: {ideal: 'environment'}}, audio: false});
        } catch (e) { off(); return; }
        camStop = () => { done = true; window.clearTimeout(timer); stream.getTracks().forEach(tr => tr.stop()); camStop = null; };
        if (!document.body.contains(video)) { camStop(); return; }
        video.srcObject = stream;
        const tick = async () => {
            if (done) return;
            let text = null;
            try { const r = await detector.detect(video); text = r.length ? r[0].rawValue : null; } catch (e) { text = null; }
            if (text) { if (camStop) camStop(); onCode(text); return; }
            timer = window.setTimeout(tick, 350);
        };
        tick();
    }

    function stopCam() { if (camStop) camStop(); }

    // « Zapisz kontrolę » : poza normą — propozycja niezgodności (zgłoś albo zapisz bez NC).
    function save() {
        const w = weigh();
        const reasons = [];
        if (w.result === 'light') reasons.push(t('nc_reason_weight_light', {avg: num(w.avg), min: num(batch.weight.min_g)}));
        if (w.result === 'heavy') reasons.push(t('nc_reason_weight_heavy', {avg: num(w.avg), max: num(batch.weight.max_g)}));
        if (draft.appearance === 'bad') reasons.push(t('nc_reason_appearance_bad'));
        const body = {weights: draft.weights, appearance: draft.appearance, photo_id: draft.photoId};
        if (!reasons.length) return post(body);
        let severity = 'minor';
        const text = reasons.join(' ; ');
        const box = sheet(`<h3>${esc(t('nc_title'))}</h3><p>${esc(t('nc_help', {group: batch.group.name}))}</p>
            <label class="field">${esc(t('nc_description'))}<textarea data-desc maxlength="2000">${esc(batch.product + ' : ' + text)}</textarea></label>
            <div class="field">${esc(t('nc_severity'))}<div class="seg" role="radiogroup">${batch.severities.map(s => `<button type="button" class="btn ${s === severity ? 'is-on' : ''}" role="radio" aria-checked="${s === severity}" data-sev="${s}">${esc(t('sev_' + s))}</button>`).join('')}</div></div>
            <label class="field">${esc(t('nc_note'))}<input data-note maxlength="255" autocomplete="off"></label>
            <p class="err" data-error></p>
            <div class="row3"><button type="button" class="btn md" data-skip>${esc(t('nc_skip'))}</button><button type="button" class="btn btn--primary md" data-create>${esc(t('nc_create'))}</button></div>`, true);
        box.querySelectorAll('[data-sev]').forEach(b => b.addEventListener('click', () => {
            severity = b.dataset.sev;
            box.querySelectorAll('[data-sev]').forEach(x => { const on = x.dataset.sev === severity; x.classList.toggle('is-on', on); x.setAttribute('aria-checked', String(on)); });
        }));
        box.querySelector('[data-create]').addEventListener('click', () => post({...body, nc: {create: true, description: box.querySelector('[data-desc]').value.trim(), severity}}, box));
        box.querySelector('[data-skip]').addEventListener('click', () => {
            const note = box.querySelector('[data-note]').value.trim();
            post({...body, nc: {create: false}, ...(note ? {note} : {})}, box);
        });
    }

    async function post(body, box) {
        if (busy) return;
        busy = true;
        try {
            const r = await api('POST', `/kiosk/quality-round/batches/${batch.id}/controls`, body);
            closeSheet();
            toast(t('saved'));
            busy = false;
            openRound(r.round);
        } catch (err) {
            busy = false;
            if (box) { const n = box.querySelector('[data-error]'); if (n) n.textContent = err.message; } else toast(err.message, true);
        }
    }

    // ---------------------------------------------------------------------------------------------- [432] Obchód chłodni
    // Ilość : API w jednostce bazowej (g, ml, szt), ekran w jednostce surowca (kg, l…) — factor z API.
    const shown = (base, r) => base === null || base === undefined ? null : Math.round(base / (r.factor || 1) * 1000) / 1000;
    const qtyLabel = (base, r) => base === null || base === undefined ? '—' : `${num(shown(base, r), 3)} ${r.unit}`;
    const toBase = (v, r) => Math.round(v * (r.factor || 1) * 1000) / 1000;
    const matCount = (n) => t(plural(n, 'cold_mat_one', 'cold_mat_few', 'cold_mat_many'), {n});
    const PLACE_BADGE = {done: 'ok', now: 'warn', later: 'neu'};
    // Data partii : w tym roku dzień i miesiąc (12.12), dalej z rokiem (31.03.2027).
    const bbLabel = (iso) => iso.slice(0, 4) === String(new Date().getFullYear()) ? dm(iso) : dmy(iso);

    async function openCold(data) {
        view = 'cold';
        place = null; cdraft = null; batch = null; draft = null; trace = null;
        try {
            cold = data || await api('GET', '/kiosk/cold-visit');
        } catch (err) { if (session) toast(err.message, true); return; }
        renderCold();
    }

    function placeSub(p) {
        if (p.saved) {
            const d = p.saved.differences;
            return [matCount(p.saved.materials), d ? t(plural(d, 'cold_diff_one', 'cold_diff_few', 'cold_diff_many'), {n: d}) : t('cold_diff_none'),
                t(p.saved.clean === 'yes' ? 'cold_clean_ok' : 'cold_clean_bad'), p.saved.at].join(' · ');
        }
        return p.materials ? matCount(p.materials) : t('cold_no_mats');
    }

    function renderCold() {
        const c = cold;
        const next = c.places.find(p => p.id === c.next_id) || null;
        const today = (c.now || '').slice(0, 10);
        const complete = c.visit && c.visit.state === 'complete';
        const right = c.places.length && c.to_do ? `<span class="badge warn big">${esc(t('cold_to_do', {n: c.to_do}))}</span>`
            : (c.places.length ? `<span class="badge ok big">${icon('done')}${esc(t('cold_done_badge'))}</span>` : '');
        const sub = complete && c.visit.valid_until ? t('cold_sub_ask_done', {at: dayLabel(c.visit.valid_until.slice(0, 10)) + ' ' + c.visit.valid_until.slice(11, 16).replace(/^0/, '')}) : t('cold_sub_ask');
        el.innerHTML = top(t('cold_title'), t('cold_sub', {position: t('pos_' + (c.employee?.position || 'KIEROWNIK_PRODUKCJI')), day: dayLabel(today)}), right)
            + `<div class="body cold" style="padding-top:20px"><p class="ask">${esc(next ? t('cold_ask') : (c.places.length ? t('cold_ask_done') : t('cold_title')))}</p><p class="sub">${esc(sub)}</p>
            ${c.places.length ? `<div class="places">${c.places.map(p => `<button type="button" class="card pl is-${p.status}" data-place="${p.id}">
                ${icon(p.status === 'done' ? 'done' : (p.kind === 'cold' ? 'temp' : 'box'))}<span class="t"><b>${esc(p.name)}</b><small>${esc(placeSub(p))}</small></span>
                <span class="badge ${PLACE_BADGE[p.status]}">${esc(t('cold_st_' + p.status))}</span></button>`).join('')}</div>`
                : `<div class="card empty">${esc(t('cold_no_places'))}</div>`}
            <div class="actions"><button type="button" class="btn" style="flex:1" data-round>${icon('back')}${esc(t('cold_back_round'))}</button>
                <button type="button" class="btn btn--primary" style="flex:2" data-go ${next ? '' : 'disabled'}>${esc(next ? next.name : t('cold_all_done'))} ${icon('next')}</button></div></div>`;
        bindTop();
        el.querySelectorAll('[data-place]').forEach(b => b.addEventListener('click', () => openColdPlace(Number(b.dataset.place))));
        el.querySelector('[data-round]').addEventListener('click', () => openRound());
        if (next) el.querySelector('[data-go]').addEventListener('click', () => openColdPlace(next.id));
    }

    async function openColdPlace(id, keep) {
        try {
            place = await api('GET', `/kiosk/cold-visit/places/${id}`);
        } catch (err) { if (session) toast(err.message, true); return; }
        view = 'coldPlace';
        const old = keep && cdraft && cdraft.id === id ? cdraft : null;
        cdraft = {id, rows: {}, clean: old ? old.clean : (place.saved ? place.saved.clean : null), photoId: old ? old.photoId : (place.saved ? place.saved.photo_id : null),
            photoUrl: old ? old.photoUrl : null, note: old ? old.note : (place.saved ? place.saved.note : null), dirty: old ? old.dirty : false};
        for (const r of place.rows) {
            const o = old && old.rows[r.material_id];
            cdraft.rows[r.material_id] = o || (r.saved ? {qty: r.saved.quantity, date: r.saved.date_state, loss: r.saved.loss} : {qty: null, date: null, loss: null});
        }
        renderColdPlace();
    }

    function coldLeft() { return place.rows.filter(r => cdraft.rows[r.material_id].qty === null).length; }

    function renderColdPlace() {
        const p = place;
        const counted = p.rows.length - coldLeft();
        const rows = p.rows.map(r => {
            const d = cdraft.rows[r.material_id];
            const lot = r.lot ? (r.lot.best_before ? t('cold_lot', {lot: r.lot.lot_number || '—', date: bbLabel(r.lot.best_before)}) : t('cold_lot_nobb', {lot: r.lot.lot_number || '—'})) : t('cold_no_lot');
            const sub = [lot, r.also_in.length ? t('cold_also_in', {places: r.also_in.join(', ')}) : ''].filter(Boolean).join(' · ');
            const val = d.qty === null ? `<span class="v empty">— <small>${esc(r.unit)}</small></span>`
                : `<span class="v">${esc(num(shown(d.qty, r), 3))} <small>${esc(r.unit)}</small></span>`;
            let flags;
            if (d.qty === null) {
                flags = r.erp !== null ? `<button type="button" class="btn" data-same="${r.material_id}">${esc(t('cold_same', {qty: qtyLabel(r.erp, r)}))}</button>`
                    : `<span class="hint">${esc(t('cold_count_hint'))}</span>`;
            } else {
                const lossTxt = d.loss ? t('cold_loss_on', {kind: t(d.loss.kind === 'damaged' ? 'cold_damaged' : 'cold_discard'), qty: qtyLabel(d.loss.quantity, r)}) : t('cold_discard');
                flags = `<button type="button" class="btn ${d.date === 'ok' ? 'on-ok' : ''}" data-date="${r.material_id}:ok" aria-pressed="${d.date === 'ok'}">${esc(t('cold_date_ok'))}</button>
                    <button type="button" class="btn ${d.date === 'short' ? 'on-w' : ''}" data-date="${r.material_id}:short" aria-pressed="${d.date === 'short'}">${esc(t(d.date === 'short' ? 'cold_short_on' : 'cold_short'))}</button>
                    <button type="button" class="btn ${d.loss ? 'on-s' : ''}" data-loss="${r.material_id}">${esc(lossTxt)}</button>`;
            }
            return `<div class="fr-row"><button type="button" class="nm" data-item="${r.material_id}">${esc(r.name)}<small>${esc(sub)}</small></button>
                <span class="erp">${esc(qtyLabel(r.erp, r))}</span>
                <span class="qty"><button type="button" class="btn" data-minus="${r.material_id}" aria-label="−">${icon('minus')}</button>
                    <button type="button" class="vbtn" data-pad="${r.material_id}">${val}</button>
                    <button type="button" class="btn" data-plus="${r.material_id}" aria-label="+">${icon('plus')}</button></span>
                <span class="flags">${flags}</span></div>`;
        }).join('');
        const left = coldLeft();
        const ready = left === 0 && cdraft.clean !== null && (cdraft.clean === 'yes' || cdraft.photoId !== null);
        const saveText = left ? t('cold_save_left', {left: matCount(left)}) : (cdraft.clean === null ? t('cold_save_clean') : (cdraft.clean === 'no' && cdraft.photoId === null ? t('cold_save_photo') : t('cold_save')));
        el.innerHTML = top(p.name, t('cold_place_sub', {materials: matCount(p.rows.length), counted}), '', true)
            + `<div class="body cold-place" style="padding-top:14px"><div class="card fr-card">
                <div class="fr-row fr-head"><span>${esc(t('cold_col_item'))}</span><span class="erp">${esc(t('cold_col_erp'))}</span><span>${esc(t('cold_col_real'))}</span><span>${esc(t('cold_col_state'))}</span></div>
                ${rows || `<p class="empty">${esc(t('cold_no_mats'))}</p>`}</div>
            <div class="fr-bottom"><div class="card fr-clean">${icon('clean')}<span class="q">${esc(t('cold_clean_q'))}</span>
                <button type="button" class="btn ${cdraft.clean === 'yes' ? 'is-good' : ''}" data-clean="yes" aria-pressed="${cdraft.clean === 'yes'}">${icon('up')}${esc(t('cold_yes'))}</button>
                <label class="btn ${cdraft.clean === 'no' ? 'is-bad' : ''}" style="position:relative">${cdraft.photoUrl ? `<img class="thumb" src="${cdraft.photoUrl}" alt="">` : icon('down')}${esc(cdraft.clean === 'no' && cdraft.photoId ? t('cold_no') : t('cold_no_photo'))}
                    <input type="file" accept="image/*" capture="environment" data-dirty-photo style="position:absolute;inset:0;opacity:0;width:100%"></label></div>
                <button type="button" class="card fr-add" data-add>${icon('plus')}<span>${esc(t('cold_add'))}</span></button></div>
            <button type="button" class="btn btn--primary ${ready ? '' : 'off'}" style="margin-top:14px;flex:none" data-save ${ready ? '' : 'aria-disabled="true"'}>${esc(saveText)} ${icon('next')}</button></div>`;
        bindTop();
        const row = (id) => p.rows.find(r => r.material_id === Number(id));
        const set = (id, patch) => { Object.assign(cdraft.rows[Number(id)], patch); cdraft.dirty = true; renderColdPlace(); };
        el.querySelectorAll('[data-same]').forEach(b => b.addEventListener('click', () => set(b.dataset.same, {qty: row(b.dataset.same).erp})));
        el.querySelectorAll('[data-minus], [data-plus]').forEach(b => b.addEventListener('click', () => {
            const id = b.dataset.minus || b.dataset.plus;
            const r = row(id);
            const d = cdraft.rows[r.material_id];
            const cur = shown(d.qty !== null ? d.qty : (r.erp !== null ? r.erp : 0), r);
            const step = b.dataset.minus ? -1 : 1;
            // Z ERP : pierwszy dotyk startuje od stanu ERP (zaokrąglonego do pełnej jednostki).
            const v = d.qty === null ? Math.max(0, Math.round(cur) + (r.erp !== null ? step : Math.max(0, step))) : Math.max(0, Math.round((cur + step) * 1000) / 1000);
            const base = toBase(v, r);
            set(id, {qty: base, loss: d.loss && d.loss.quantity > base ? null : d.loss});
        }));
        el.querySelectorAll('[data-pad]').forEach(b => b.addEventListener('click', () => qtyPad(row(b.dataset.pad))));
        el.querySelectorAll('[data-date]').forEach(b => b.addEventListener('click', () => {
            const [id, st] = b.dataset.date.split(':');
            set(id, {date: cdraft.rows[Number(id)].date === st ? null : st});
        }));
        el.querySelectorAll('[data-loss]').forEach(b => b.addEventListener('click', () => lossSheet(row(b.dataset.loss))));
        el.querySelectorAll('[data-item]').forEach(b => b.addEventListener('click', () => itemSheet(row(b.dataset.item))));
        el.querySelector('[data-clean="yes"]').addEventListener('click', () => { cdraft.clean = 'yes'; cdraft.dirty = true; renderColdPlace(); });
        el.querySelector('[data-dirty-photo]').addEventListener('change', (e) => dirtyPhoto(e.target.files && e.target.files[0]));
        el.querySelector('[data-add]').addEventListener('click', addSheet);
        el.querySelector('[data-save]').addEventListener('click', () => { if (ready) saveColdPlace(); });
    }

    // Klawiatura ilości w jednostce surowca (kg, l, szt).
    function qtyPad(r) {
        const d = cdraft.rows[r.material_id];
        let v = d.qty !== null ? String(shown(d.qty, r)).replace('.', ',') : '';
        const box = sheet(`<h3>${esc(t('cold_pad_title', {name: r.name}))}</h3><div class="display" data-v></div>
            <p class="hint" style="text-align:center">${esc(t('cold_col_erp') + ' : ' + qtyLabel(r.erp, r))}</p>
            <div class="keypad">${[1, 2, 3, 4, 5, 6, 7, 8, 9].map(n => `<button type="button" class="btn" data-k="${n}">${n}</button>`).join('')}
            <button type="button" class="btn" data-k=",">,</button><button type="button" class="btn" data-k="0">0</button>
            <button type="button" class="btn" data-k="del" aria-label="${esc(t('pin_erase'))}">${icon('back')}</button></div>
            <div class="row3"><button type="button" class="btn md" data-close>${esc(t('cancel'))}</button><button type="button" class="btn btn--primary md" data-ok>${esc(t('pad_ok'))}</button></div>`);
        const draw = () => { box.querySelector('[data-v]').innerHTML = `${esc(v || '0')} <small>${esc(r.unit)}</small>`; };
        draw();
        box.querySelectorAll('[data-k]').forEach(b => b.addEventListener('click', () => {
            const k = b.dataset.k;
            if (k === 'del') v = v.slice(0, -1);
            else if (k === ',') { if (!v.includes(',') && (r.factor || 1) > 1) v = (v || '0') + ','; }
            else if (v.replace(',', '').length < 7 && !(v.includes(',') && v.split(',')[1].length >= 3)) v += k;
            draw();
        }));
        box.querySelector('[data-ok]').addEventListener('click', () => {
            const x = Number((v || '0').replace(',', '.'));
            const base = toBase(Math.max(0, x), r);
            Object.assign(d, {qty: base, loss: d.loss && d.loss.quantity > base ? null : d.loss});
            cdraft.dirty = true;
            closeSheet();
            renderColdPlace();
        });
    }

    // « Do wyrzucenia » / « Uszkodzone » : ilość (≤ jest naprawdę) i powód — zostaje w stanie reszta.
    function lossSheet(r) {
        const d = cdraft.rows[r.material_id];
        let kind = d.loss ? d.loss.kind : 'discard';
        let v = d.loss ? String(shown(d.loss.quantity, r)).replace('.', ',') : '';
        let reason = d.loss ? d.loss.reason : '';
        const reasons = ['cold_r_expired', 'cold_r_spoiled', 'cold_r_pack', 'cold_r_thawed'].map(k => t(k));
        const box = sheet(`<h3>${esc(t('cold_loss_title', {name: r.name}))}</h3>
            <div class="seg" role="radiogroup">${['discard', 'damaged'].map(k => `<button type="button" class="btn" role="radio" data-kind="${k}">${esc(t(k === 'damaged' ? 'cold_damaged' : 'cold_discard'))}</button>`).join('')}</div>
            <div class="loss-grid"><div><div class="display" data-v></div><p class="hint" style="text-align:center" data-rest></p>
                <div class="keypad">${[1, 2, 3, 4, 5, 6, 7, 8, 9].map(n => `<button type="button" class="btn" data-k="${n}">${n}</button>`).join('')}
                <button type="button" class="btn" data-k=",">,</button><button type="button" class="btn" data-k="0">0</button>
                <button type="button" class="btn" data-k="del" aria-label="${esc(t('pin_erase'))}">${icon('back')}</button></div></div>
            <div class="field">${esc(t('cold_loss_reason'))}<div class="reasons">${reasons.map(x => `<button type="button" class="btn" data-reason="${esc(x)}">${esc(x)}</button>`).join('')}</div>
                <input data-reason-text maxlength="120" autocomplete="off" value="${esc(reason)}"></div></div>
            <p class="err" data-error></p>
            <div class="row3">${d.loss ? `<button type="button" class="btn md" data-none>${esc(t('cold_loss_remove'))}</button>` : `<button type="button" class="btn md" data-close>${esc(t('cancel'))}</button>`}
                <button type="button" class="btn btn--primary md" data-ok>${esc(t('pad_ok'))}</button></div>`, true);
        const draw = () => {
            box.querySelector('[data-v]').innerHTML = `${esc(v || '0')} <small>${esc(r.unit)}</small>`;
            const x = toBase(Number((v || '0').replace(',', '.')), r);
            box.querySelector('[data-rest]').textContent = t('cold_loss_help', {qty: qtyLabel(Math.max(0, (d.qty || 0) - x), r)});
            box.querySelectorAll('[data-kind]').forEach(b => { const on = b.dataset.kind === kind; b.classList.toggle('is-on', on); b.setAttribute('aria-checked', String(on)); });
            box.querySelectorAll('[data-reason]').forEach(b => b.classList.toggle('is-on', b.dataset.reason === reason));
        };
        draw();
        box.querySelectorAll('[data-kind]').forEach(b => b.addEventListener('click', () => { kind = b.dataset.kind; draw(); }));
        box.querySelectorAll('[data-reason]').forEach(b => b.addEventListener('click', () => { reason = b.dataset.reason; box.querySelector('[data-reason-text]').value = reason; draw(); }));
        box.querySelector('[data-reason-text]').addEventListener('input', (e) => { reason = e.target.value; draw(); });
        box.querySelectorAll('[data-k]').forEach(b => b.addEventListener('click', () => {
            const k = b.dataset.k;
            if (k === 'del') v = v.slice(0, -1);
            else if (k === ',') { if (!v.includes(',') && (r.factor || 1) > 1) v = (v || '0') + ','; }
            else if (v.replace(',', '').length < 7 && !(v.includes(',') && v.split(',')[1].length >= 3)) v += k;
            draw();
        }));
        const none = box.querySelector('[data-none]');
        if (none) none.addEventListener('click', () => { d.loss = null; cdraft.dirty = true; closeSheet(); renderColdPlace(); });
        box.querySelector('[data-ok]').addEventListener('click', () => {
            const q = toBase(Number((v || '0').replace(',', '.')), r);
            const why = reason.trim();
            const e = box.querySelector('[data-error]');
            if (!(q > 0) || q > (d.qty || 0) + 0.0005) { e.textContent = t('cold_loss_qty_err', {qty: qtyLabel(d.qty || 0, r)}); return; }
            if (why.length < 3) { e.textContent = t('cold_loss_reason_err'); box.querySelector('[data-reason-text]').focus(); return; }
            d.loss = {kind, quantity: q, reason: why};
            cdraft.dirty = true;
            closeSheet();
            renderColdPlace();
        });
    }

    // Surowiec miejsca : partia, gdzie jeszcze leży, « Nie leży tu — usuń ».
    function itemSheet(r) {
        const lot = r.lot ? (r.lot.best_before ? t('cold_lot', {lot: r.lot.lot_number || '—', date: bbLabel(r.lot.best_before)}) : t('cold_lot_nobb', {lot: r.lot.lot_number || '—'})) : t('cold_no_lot');
        const box = sheet(`<h3>${esc(r.name)}</h3><p>${esc([lot, t('cold_col_erp') + ' : ' + qtyLabel(r.erp, r), r.also_in.length ? t('cold_also_in', {places: r.also_in.join(', ')}) : ''].filter(Boolean).join(' · '))}</p>
            <p class="err" data-error></p>
            <div class="row3"><button type="button" class="btn md" data-close>${esc(t('cancel'))}</button><button type="button" class="btn md is-bad" data-remove>${icon('x')}${esc(t('cold_remove'))}</button></div>`);
        box.querySelector('[data-remove]').addEventListener('click', async () => {
            if (busy) return;
            busy = true;
            try {
                await api('DELETE', `/kiosk/cold-visit/places/${place.id}/materials/${r.material_id}`);
                delete cdraft.rows[r.material_id];
                closeSheet();
                busy = false;
                toast(t('cold_removed', {name: r.name}));
                openColdPlace(place.id, true);
            } catch (err) { busy = false; box.querySelector('[data-error]').textContent = err.message; }
        });
    }

    // « Dodaj surowiec, który tu leży » : wyszukiwanie po nazwie, dotyk = surowiec przypisany do miejsca.
    function addSheet() {
        const box = sheet(`<h3>${esc(t('cold_add'))}</h3>
            <label class="field">${esc(t('cold_search'))}<input data-q autocomplete="off" maxlength="60"></label>
            <div class="list" data-results style="max-height:330px"><p class="empty">${esc(t('cold_search_hint'))}</p></div>
            <p class="err" data-error></p><button type="button" class="btn md" data-close>${esc(t('cancel'))}</button>`, true);
        const input = box.querySelector('[data-q]');
        const out = box.querySelector('[data-results]');
        let timer = null;
        let seq = 0;
        const have = new Set(place.rows.map(r => r.material_id));
        const search = async () => {
            const q = input.value.trim();
            if (q.length < 2) { out.innerHTML = `<p class="empty">${esc(t('cold_search_hint'))}</p>`; return; }
            const my = ++seq;
            try {
                const list = await api('GET', '/kiosk/cold-visit/materials?q=' + encodeURIComponent(q));
                if (my !== seq) return;
                out.innerHTML = list.length ? list.map(m => `<button type="button" class="li" data-m="${m.id}" ${have.has(m.id) ? 'disabled' : ''}>${icon('bag')}<div class="t">${esc(m.name)}
                    <div class="s15">${esc(m.unit)}${have.has(m.id) ? ' · ' + esc(t('cold_already')) : ''}</div></div>${have.has(m.id) ? '' : icon('plus')}</button>`).join('')
                    : `<p class="empty">${esc(t('cold_search_none'))}</p>`;
                out.querySelectorAll('[data-m]').forEach(b => b.addEventListener('click', () => add(list.find(m => m.id === Number(b.dataset.m)))));
            } catch (err) { box.querySelector('[data-error]').textContent = err.message; }
        };
        const add = async (m) => {
            if (busy || !m) return;
            busy = true;
            try {
                await api('PUT', `/kiosk/cold-visit/places/${place.id}/materials/${m.id}`);
                closeSheet();
                busy = false;
                toast(t('cold_added', {name: m.name}));
                openColdPlace(place.id, true);
            } catch (err) { busy = false; box.querySelector('[data-error]').textContent = err.message; }
        };
        input.addEventListener('input', () => { window.clearTimeout(timer); timer = window.setTimeout(search, 250); });
        input.focus();
    }

    // Porządek « Nie » : zdjęcie obowiązkowe (ten sam zapis zdjęć co kontrola partii).
    async function dirtyPhoto(file) {
        if (!file || busy) return;
        busy = true;
        try {
            const read = window.tfbLabelOcr ? await window.tfbLabelOcr.readPhoto(file, 1280) : {dataUrl: await new Promise((res, rej) => { const r = new FileReader(); r.onload = () => res(r.result); r.onerror = rej; r.readAsDataURL(file); })};
            const r = await api('POST', '/kiosk/quality-round/photos', {photo: read.dataUrl});
            Object.assign(cdraft, {clean: 'no', photoId: r.id, photoUrl: read.dataUrl, dirty: true});
            toast(t('photo_ok'));
            renderColdPlace();
        } catch (err) { toast(err.message, true); } finally { busy = false; }
    }

    async function saveColdPlace() {
        if (busy) return;
        busy = true;
        const body = {lines: place.rows.map(r => {
            const d = cdraft.rows[r.material_id];
            return {material_id: r.material_id, quantity: d.qty, date_state: d.date, loss: d.loss};
        }), clean: cdraft.clean, photo_id: cdraft.clean === 'no' ? cdraft.photoId : null};
        try {
            const r = await api('POST', `/kiosk/cold-visit/places/${place.id}`, body);
            busy = false;
            toast(r.visit && r.visit.state === 'complete' ? t('cold_complete') : t('cold_saved'));
            openCold(r);
        } catch (err) { busy = false; toast(err.message, true); }
    }

    // ---------------------------------------------------------------------------------------------- K. Śledzenie partii
    function openTrace() {
        view = 'trace';
        trace = null;
        renderScan();
    }

    function renderScan() {
        const today = round ? round.rows : [];
        el.innerHTML = top(t('trace_title'), t('trace_sub_scan', {position: position()}), '', true) + `<div class="body"><div class="scan">
            <div class="cam"><video data-video autoplay playsinline muted></video><span class="fr" aria-hidden="true"></span><span class="h" data-cam-msg>${esc(t('scan_help'))}</span></div>
            <div class="card panel"><form data-find class="field">${esc(t('scan_code'))}<input name="code" autocomplete="off" autocapitalize="characters" maxlength="64">
                <button class="btn md">${icon('qr')}${esc(t('scan_go'))}</button></form>
                ${today.length ? `<p class="h2 small">${esc(t('today_batches'))}</p><div class="list">${today.map(r => `<button type="button" class="li" data-id="${r.id}">${icon('box')}
                    <div class="t">${esc(r.product)}<div class="s15">${esc(r.group + ' · ' + r.lot_code + ' · ' + r.started_at)}</div></div>${icon('next')}</button>`).join('')}</div>` : ''}</div>
            </div></div>`;
        bindTop();
        el.querySelector('[data-find]').addEventListener('submit', (e) => { e.preventDefault(); const c = e.target.code.value.trim(); if (c) find(c); });
        el.querySelectorAll('[data-id]').forEach(b => b.addEventListener('click', () => showLot(Number(b.dataset.id))));
        startCam(el, (text) => find(text));
    }

    async function find(code) {
        if (busy) return;
        busy = true;
        try {
            const r = await api('GET', '/kiosk/quality-round/trace?code=' + encodeURIComponent(code));
            stopCam();
            trace = {scan: r, lot: r.lot};
            busy = false;
            if (r.lot) renderLot(); else renderChoices();
        } catch (err) { busy = false; toast(err.message, true); }
    }

    async function showLot(id) {
        try {
            const lot = await api('GET', `/kiosk/quality-round/trace/${id}`);
            stopCam();
            trace = {scan: trace?.scan || {kind: 'lot'}, lot};
            renderLot();
        } catch (err) { toast(err.message, true); }
    }

    function renderChoices() {
        const s = trace.scan;
        el.innerHTML = top(t('trace_title'), t('trace_sub_carton', {position: position()}), `<button type="button" class="btn top-btn" data-again>${icon('qr')}${esc(t('scan_other'))}</button>`, true)
            + `<div class="body"><p class="ask" style="font-size:28px">${esc(t('choose_lot', {carton: s.carton.id, product: s.carton.product || '', day: dm(s.carton.label_day)}))}</p>
            <div class="list">${s.choices.map(c => `<button type="button" class="li" data-id="${c.id}">${icon('box')}<div class="t">${esc(c.product + ' · ' + c.lot_code)}
                <div class="s15">${esc(c.group + ' · ' + dm(c.work_date) + ', ' + c.started_at + ' · ' + num(c.quantity) + ' ' + (c.unit || ''))}</div></div>${icon('next')}</button>`).join('')}</div></div>`;
        bindTop();
        el.querySelector('[data-again]').addEventListener('click', openTrace);
        el.querySelectorAll('[data-id]').forEach(b => b.addEventListener('click', () => showLot(Number(b.dataset.id))));
    }

    function unitLabel(u) { return u === 'szt' ? t('unit_szt') : (u === 'kart' ? t('unit_kart') : (u || '')); }

    function renderLot() {
        const d = trace.lot;
        const kind = trace.scan && trace.scan.kind === 'carton' ? 'trace_sub_carton' : 'trace_sub_lot';
        const line = t('trace_line', {group: d.group, day: dayLabel(d.work_date), at: d.started_at, qty: num(d.quantity), unit: unitLabel(d.unit)})
            + (d.cartons.total ? t('trace_cartons', {n: num(d.cartons.total, 0)}) : '')
            + (d.control ? t('trace_control', {at: d.control.at, result: t('res_' + d.control.result)}) : '');
        const mats = d.materials.map(m => {
            let right;
            if (m.state === 'ok') right = `<span class="rt">${esc([m.lot_number, m.supplier, m.received_on ? t('received', {date: dm(m.received_on)}) : ''].filter(Boolean).join(' · '))}</span>`;
            else if (m.state === 'untracked') right = `<span class="rt">${esc(t('lot_untracked'))}</span>`;
            else if (m.state === 'reason') right = `<span class="r badge warn" style="font-size:14px">${esc(t('lot_reason', {reason: m.missing_reason}))}</span>`;
            else right = `<span class="r badge stop" style="font-size:14px">${esc(t('no_scan'))}</span>`;
            return `<div class="li">${esc(m.name)}${right}</div>`;
        }).join('');
        const dests = d.destinations.length ? d.destinations.map(x => {
            const name = x.kind === 'stock' ? t('dest_stock') : (x.kind === 'pallet' ? t('dest_pallet', {customer: x.customer || '—'}) : (x.customer || '—'));
            const right = t('dest_cartons', {n: num(x.cartons, 0)}) + (x.kind === 'stock' ? t('dest_in_stock')
                : (x.wdt_date ? t('dest_wdt', {date: dm(x.wdt_date)}) : (x.delivery_date ? t('dest_delivery', {date: dm(x.delivery_date)}) : '')));
            return `<div class="li">${esc(name)}<span class="rt">${esc(right)}</span></div>`;
        }).join('') : `<p class="empty">${esc(t('no_cartons', {days: d.label_from ? (dm(d.label_from) + (d.label_to !== d.label_from ? '–' + dm(d.label_to) : '')) : '—'}))}</p>`;
        const h = d.hold;
        el.innerHTML = top(t('trace_title'), t(kind, {position: position()}), `<button type="button" class="btn top-btn" data-again>${icon('qr')}${esc(t('scan_other'))}</button>`, true)
            + `<div class="body" style="padding-top:20px"><p class="ask">${esc(d.product)} · ${esc(lang === 'fr' ? 'lot' : 'partia')} ${esc(d.lot_code)}</p><p class="sub">${esc(line)}</p>
            ${h ? `<div class="held" style="margin-bottom:14px">${icon('stop')}${esc(t('held_banner', {at: h.held_at.slice(11, 16), by: h.held_by || '—', reason: h.reason}))}</div>` : ''}
            <div class="trace"><div class="card panel"><p class="h2 small">${icon('bag')}${esc(t('materials'))}</p><div class="list">${mats}</div></div>
                <div class="arrow">${icon('next')}</div>
                <div class="card panel"><p class="h2 small">${icon('box')}${esc(t('destinations'))}</p><div class="list">${dests}</div>
                    <div class="row3 grow"><button type="button" class="btn sm" data-pdf>${icon('list')}${esc(t('pdf'))}</button>
                    ${h ? `<button type="button" class="btn sm" data-release>${icon('done')}${esc(t('release'))}</button>`
                        : `<button type="button" class="btn sm is-stop" data-hold ${d.product_id ? '' : 'disabled'}>${icon('stop')}${esc(t('hold'))}</button>`}</div></div>
            </div></div>`;
        bindTop();
        el.querySelector('[data-again]').addEventListener('click', openTrace);
        el.querySelector('[data-pdf]').addEventListener('click', (e) => openPdf(e.currentTarget));
        const hb = el.querySelector('[data-hold]');
        if (hb) hb.addEventListener('click', holdSheet);
        const rb = el.querySelector('[data-release]');
        if (rb) rb.addEventListener('click', releaseSheet);
    }

    async function openPdf(button) {
        button.disabled = true;
        try {
            const f = await api('GET', `/kiosk/quality-round/trace/${trace.lot.id}/pdf`);
            const bytes = Uint8Array.from(atob(f.content_base64), c => c.charCodeAt(0));
            const url = URL.createObjectURL(new Blob([bytes], {type: 'application/pdf'}));
            const a = document.createElement('a');
            a.href = url; a.target = '_blank'; a.rel = 'noopener'; a.download = f.filename;
            document.body.appendChild(a); a.click(); a.remove();
            window.setTimeout(() => URL.revokeObjectURL(url), 60000);
        } catch (err) { toast(err.message, true); } finally { button.disabled = false; }
    }

    // « Wstrzymaj partię » : potwierdzenie z powodem (kartony zostają, paletyzacja i załadunek je zatrzymają).
    function holdSheet() {
        const d = trace.lot;
        const days = d.label_from ? dm(d.label_from) + (d.label_to !== d.label_from ? '–' + dm(d.label_to) : '') : '—';
        const box = sheet(`<h3>${esc(t('hold_title', {lot: d.lot_code}))}</h3><p>${esc(t('hold_help', {product: d.product, days}))}</p>
            <label class="field">${esc(t('hold_reason'))}<textarea data-reason maxlength="500"></textarea></label><p class="err" data-error></p>
            <div class="row3"><button type="button" class="btn md" data-close>${esc(t('cancel'))}</button><button type="button" class="btn md is-bad" data-ok>${icon('stop')}${esc(t('hold_confirm'))}</button></div>`);
        box.querySelector('[data-reason]').focus();
        box.querySelector('[data-ok]').addEventListener('click', async () => {
            const reason = box.querySelector('[data-reason]').value.trim();
            if (reason.length < 3) { box.querySelector('[data-reason]').focus(); return; }
            if (busy) return;
            busy = true;
            try {
                trace.lot = await api('POST', `/kiosk/quality-round/trace/${d.id}/hold`, {reason});
                closeSheet();
                toast(t('held_ok'));
                renderLot();
            } catch (err) { box.querySelector('[data-error]').textContent = err.message; } finally { busy = false; }
        });
    }

    function releaseSheet() {
        const d = trace.lot;
        const box = sheet(`<h3>${esc(t('release_title', {lot: d.lot_code}))}</h3>
            <label class="field">${esc(t('release_note'))}<textarea data-note maxlength="500"></textarea></label><p class="err" data-error></p>
            <div class="row3"><button type="button" class="btn md" data-close>${esc(t('cancel'))}</button><button type="button" class="btn md is-good" data-ok>${icon('done')}${esc(t('release_confirm'))}</button></div>`);
        box.querySelector('[data-ok]').addEventListener('click', async () => {
            const note = box.querySelector('[data-note]').value.trim();
            if (note.length < 3) { box.querySelector('[data-note]').focus(); return; }
            if (busy) return;
            busy = true;
            try {
                trace.lot = await api('POST', `/kiosk/quality-round/holds/${d.hold.id}/release`, {note});
                closeSheet();
                toast(t('released_ok'));
                renderLot();
            } catch (err) { box.querySelector('[data-error]').textContent = err.message; } finally { busy = false; }
        });
    }

    // ---------------------------------------------------------------------------------------------- Start
    (async function start() {
        await loadLabels();
        // Bez tokenu w pamięci : serwer zna tablet z ciasteczka parowania (GET /kiosk/device rozstrzyga).
        if (!device) {
            try { await api('GET', '/kiosk/device', undefined, false); } catch (e) { if (e.code === 'DEVICE_UNAUTHENTICATED' || e.code === 'DEVICE_DISABLED') return; }
        }
        renderWho();
    })();
})();
