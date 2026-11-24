/*
 * Tablet hali « Mój dzień » [409–411] — makieta des asystenci stanowisk produkcji, ekrany A–H.
 * Une étape à la fois : accueil (kafelki dans l'ordre fixe, seul « Teraz » actif) → Co robię dziś (plan de l'équipe, photo,
 * grande liczba, compteur « zrobione », Zepsute) → Skanuj worki (code du sac, STOP rouge pour un mauvais sac) →
 * Schładzanie (groupes réglés pour : thermomètre + minutnik) → Sprzątanie (strefy du groupe) → Pakuję (groupe d'emballage)
 * → Koniec dnia (« Zatwierdź dzień » = validation du jour du groupe). « Pomoc » sur chaque écran : wezwanie au brygadzista,
 * dont la vue « Zmiana » (H) montre l'état des groupes. Tout passe par l'API (/kiosk/hala/*, /kiosk/waste, /kiosk/pallets),
 * avec la session de la tablette (kiosk.js : objet « bridge »). Design system L'Atelier By (hala.css), icônes SVG linéaires.
 */
(function () {
    'use strict';
    let B = null; // bridge kiosk.js
    let D = null; // GET /kiosk/hala/day
    let view = 'home';
    let selected = null; // produit choisi sur « Co robię » / « Worki » / « Schładzanie »
    const pending = {}; // compteur : article => {value, timer}
    let poll = null;
    let cam = null; // {stream, timer}
    let tick = null;
    const photos = {};
    let packState = null; // {customer, data}

    const SVG = {
        help: '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="4"/><path d="M5.6 5.6l3.6 3.6M14.8 14.8l3.6 3.6M18.4 5.6l-3.6 3.6M9.2 14.8l-3.6 3.6"/>',
        user: '<path d="M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 21v-1a6 6 0 0 1 12 0v1"/>',
        list: '<path d="M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
        qr: '<path d="M3 3h6v6H3zM15 3h6v6h-6zM3 15h6v6H3zM15 15h2v2h-2zM19 19h2v2h-2zM15 19h2M19 15h2"/>',
        temp: '<path d="M14 14.8V4a2 2 0 0 0-4 0v10.8a4 4 0 1 0 4 0zM12 9v8"/>',
        clean: '<path d="M14 3l-4 9M7 12h10l2 9H5zM9 21l1-5M15 21l-1-5"/>',
        done: '<circle cx="12" cy="12" r="9"/><path d="M8 12l3 3 5-6"/>',
        stop: '<path d="M8 3h8l5 5v8l-5 5H8l-5-5V8zM12 8v5M12 16h.01"/>',
        alert: '<path d="M12 3l10 18H2zM12 10v5M12 18h.01"/>',
        plus: '<path d="M12 5v14M5 12h14"/>',
        minus: '<path d="M5 12h14"/>',
        trash: '<path d="M3 6h18M8 6V4h8v2M6 6l1 15h10l1-15M10 11v6M14 11v6"/>',
        next: '<path d="M5 12h14M12 5l7 7-7 7"/>',
        back: '<path d="M19 12H5M12 19l-7-7 7-7"/>',
        img: '<path d="M3 5h18v14H3zM3 15l5-5 4 4 3-3 6 6M15.5 9a1.5 1.5 0 1 0 0-.01"/>',
        bag: '<path d="M6 7h12l1 14H5zM9 7V5a3 3 0 0 1 6 0v2"/>',
        clock: '<path d="M12 8v4l3 3M3 12a9 9 0 1 0 9-9 9 9 0 0 0-9 9"/>',
        box: '<path d="M21 8l-9-5-9 5 9 5 9-5zM3 8v8l9 5 9-5V8M12 13v8"/>',
        gift: '<path d="M3 9h18v4H3zM5 13v8h14v-8M12 9v12M12 9c-2-4-6-4-6-1s6 1 6 1 6 2 6-1-4-3-6 1"/>',
        team: '<path d="M17 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.9"/>',
        table: '<path d="M3 8h18M5 8v12M19 8v12M3 4h18v4H3z"/>',
        machine: '<path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 0 1-4 0v-.1A1.7 1.7 0 0 0 9 19.4a1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 0 1 0-4h.1A1.7 1.7 0 0 0 4.6 9a1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 0 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 0 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        floor: '<path d="M3 20h18M6 20l3-9h6l3 9M12 3v8"/>',
        bin: '<path d="M4 7h16M6 7l1 14h10l1-14M9 7V4h6v3"/>',
        oven: '<path d="M3 4h18v16H3zM3 9h18M7 6.5h.01M11 6.5h.01M7 13h10v4H7z"/>',
        fridge: '<path d="M6 2h12v20H6zM6 10h12M9 6v2M9 13v3"/>',
        sink: '<path d="M3 12h18l-2 7H5zM12 12V6a2 2 0 0 1 4 0M8 12V9"/>',
        tools: '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.6 2.6-2.1-.5-.5-2.1z"/>',
        other: '<circle cx="12" cy="12" r="9"/><path d="M8 12h8"/>',
        grid: '<path d="M3 3h7v7H3zM14 3h7v7h-7zM14 14h7v7h-7zM3 14h7v7H3z"/>',
        out: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
    };
    const ICON_OF = {make: 'list', bags: 'qr', chill: 'temp', clean: 'clean', pack: 'box', end: 'done'};
    const i = (name, cls) => `<svg class="h-i ${cls || ''}" viewBox="0 0 24 24" aria-hidden="true">${SVG[name] || SVG.other}</svg>`;
    const esc = (v) => B.esc(v);
    const t = (k, p) => B.t(k, p);
    const lang = () => B.lang();
    // Grande liczba lisible de loin : milliers toujours séparés (1 044), virgule décimale (PL et FR).
    const fmt = (n) => {
        const v = Math.round(Number(n || 0) * 10) / 10;
        const [int, dec] = String(Math.abs(v)).split('.');
        return (v < 0 ? '−' : '') + int.replace(/\B(?=(\d{3})+(?!\d))/g, '\u00a0') + (dec ? ',' + dec : '');
    };
    const hhmm = (s) => { const v = String(s || '').slice(11, 16); return v.startsWith('0') ? v.slice(1) : v; };
    const now5 = () => { const v = new Date().toTimeString().slice(0, 5); return v.startsWith('0') ? v.slice(1) : v; };
    // Pluriel polonais : 1 / 2–4 (sauf 12–14) / reste ; le français : 1 / reste.
    const plural = (n, key) => t(key + (n === 1 ? '_1' : (lang() === 'pl' && [2, 3, 4].includes(n % 10) && ![12, 13, 14].includes(n % 100) ? '_few' : '_many')), {n: fmt(n)});
    const dateLabel = () => new Date().toLocaleDateString(lang() === 'fr' ? 'fr-FR' : 'pl-PL', {weekday: 'short', day: '2-digit', month: '2-digit'}).replace(',', '');

    // Écran du tablet hali encore affiché, personne encore identifiée : sinon les minuteries s'arrêtent.
    const alive = () => !!(B && B.session() && B.el.querySelector('.hala'));

    function stopAll() {
        window.clearInterval(poll); poll = null;
        window.clearInterval(tick); tick = null;
        stopCam();
        Object.values(pending).forEach(p => window.clearTimeout(p.timer));
    }

    // --- Ouverture (onglet « day » de kiosk.js) ---------------------------------------------------------------------------
    async function open(bridge, message) {
        B = bridge;
        stopAll();
        try {
            D = await B.api('GET', '/kiosk/hala/day', undefined, true);
        } catch (err) {
            // Tablette sans groupe, réseau absent… : les écrans habituels de la tablette (Produkcja).
            const s = B.session();
            if (s) s.hala = false;
            if (!err.code) B.toast(err.message, true);
            return B.session() ? B.openTab('production', message) : undefined;
        }
        const s = B.session();
        if (!s) return;
        s.tab = 'day';
        s.hala = D.guided || D.person.lead;
        if (!D.guided && !D.person.lead) return B.openTab('production', message);
        B.setLongIdle(true);
        if (D.person.lead) return renderShift(message);
        renderHome(message);
    }

    async function reload() {
        D = await B.api('GET', '/kiosk/hala/day', undefined, true);
        return D;
    }

    // --- En-tête, barre de progression, Pomoc ------------------------------------------------------------------------------
    function top(title, sub, opts) {
        opts = opts || {};
        const h = D && D.help;
        const help = opts.noHelp ? (opts.right || '') : `<button type="button" class="h-help ${h ? (h.status === 'taken' ? 'is-taken' : 'is-called') : ''}" data-help>${i('help')}<span>${esc(h ? (h.status === 'taken' ? t('hala_help_taken') : t('hala_help_called')) : t('hala_help'))}</span></button>`;
        return `<header class="h-top"><button type="button" class="h-av" data-av aria-label="${esc(opts.home ? t('hala_menu') : t('hala_back_home'))}">${i(opts.icon || (opts.home ? 'user' : 'back'))}</button>
            <div class="h-who"><b>${esc(title)}</b><small>${esc(sub)}</small></div><span class="h-sp"></span><span class="h-clock" data-clock>${esc(now5())}</span>${help}</header>`;
    }

    function progress(current) {
        const steps = D.steps;
        return `<div class="h-prog">${steps.map((s, k) => `${k ? '<span class="h-ln"></span>' : ''}<span class="h-st ${s.key === current ? 'is-c' : (s.state === 'done' ? 'is-d' : '')}"><i>${s.state === 'done' && s.key !== current ? '✓' : k + 1}</i>${esc(t('hala_short_' + s.key))}</span>`).join('')}</div>`;
    }

    function paint(html, cls) {
        B.el.innerHTML = `<div class="hala ${cls || ''}">${html}</div>`;
        const root = B.el.querySelector('.hala');
        root.querySelector('[data-help]')?.addEventListener('click', () => callHelp(view));
        root.querySelector('[data-av]')?.addEventListener('click', () => (view === 'home' || view === 'shift' ? menu() : go('home')));
        return root;
    }

    async function callHelp(step, reason, note) {
        try {
            const r = await B.api('POST', '/kiosk/hala/help', {step: step || 'home', reason: reason || 'help', note: note || null}, true);
            D.help = r.help;
            B.toast(t('hala_help_sent'));
            const btn = B.el.querySelector('[data-help]');
            if (btn) { btn.classList.add('is-called'); btn.querySelector('span').textContent = t(r.help.status === 'taken' ? 'hala_help_taken' : 'hala_help_called'); }
            watchHelp(r.poll_s || D.poll_s);
        } catch (err) { B.toast(err.offline ? t('offline_retry') : err.message, true); }
    }

    // Wezwanie ouvert : l'écran suit son état (pris par le brygadzista, fermé).
    function watchHelp(seconds) {
        window.clearInterval(poll);
        poll = window.setInterval(async () => {
            if (!alive()) { stopAll(); return; }
            if (document.hidden) return;
            try {
                const r = await B.api('GET', '/kiosk/hala/help', undefined, true);
                D.help = r.help;
                const btn = B.el.querySelector('[data-help]');
                if (btn) {
                    btn.classList.toggle('is-called', !!r.help && r.help.status === 'open');
                    btn.classList.toggle('is-taken', !!r.help && r.help.status === 'taken');
                    btn.querySelector('span').textContent = t(!r.help ? 'hala_help' : (r.help.status === 'taken' ? 'hala_help_taken' : 'hala_help_called'));
                }
                if (!r.help) window.clearInterval(poll);
            } catch (e) { /* réseau : prochain essai */ }
        }, Math.max(5, seconds || 15) * 1000);
    }

    // Menu de la personne (accueil) : tous les écrans de la tablette, sortie.
    function menu() {
        const sheet = document.createElement('div');
        sheet.className = 'h-sheet';
        sheet.innerHTML = `<div class="h-sheet-box"><p class="h-ask is-s">${esc(B.session() ? B.session().name : '')}</p>
            <button type="button" class="h-btn" data-all>${i('grid')}${esc(t('hala_all_screens'))}</button>
            <button type="button" class="h-btn" data-out>${i('out')}${esc(t('logout'))}</button>
            <button type="button" class="h-btn is-ghost" data-close>${esc(t('cancel'))}</button></div>`;
        B.el.querySelector('.hala').appendChild(sheet);
        sheet.querySelector('[data-all]').addEventListener('click', () => { stopAll(); B.openTab('production'); });
        sheet.querySelector('[data-out]').addEventListener('click', () => { stopAll(); B.logout(); });
        sheet.querySelector('[data-close]').addEventListener('click', () => sheet.remove());
        sheet.addEventListener('click', (e) => { if (e.target === sheet) sheet.remove(); });
    }

    async function go(v, message) {
        stopCam();
        window.clearInterval(tick);
        view = v;
        if (v === 'home') return renderHome(message);
        if (v === 'make') return renderMake();
        if (v === 'bags') return renderBags();
        if (v === 'chill') return renderChill();
        if (v === 'clean') return renderClean();
        if (v === 'pack') return renderPack();
        if (v === 'end') return renderEnd();
        if (v === 'shift') return renderShift();
    }

    // « Dalej » : l'étape est confirmée (le serveur vérifie la condition), retour à l'accueil.
    async function next(step, button) {
        if (button) button.disabled = true;
        await flushCounters();
        try {
            D = await B.api('POST', '/kiosk/hala/steps', {step}, true);
            go('home');
        } catch (err) {
            if (button) button.disabled = false;
            B.toast(err.offline ? t('offline_retry') : err.message, true);
        }
    }

    // --- A. Accueil « Mój dzień » -------------------------------------------------------------------------------------------
    function renderHome(message) {
        view = 'home';
        const name = (D.person.first_name || D.person.name || '').trim();
        const finished = D.end.finished;
        const tiles = D.steps.map((s, k) => {
            const cls = s.state === 'done' ? 'is-done' : (s.state === 'now' ? 'is-now' : 'is-later');
            const label = s.state === 'done' ? t(s.key === 'make' ? 'hala_seen' : (s.key === 'end' ? 'hala_closed' : 'hala_done')) : (s.state === 'now' ? t('hala_now') : t('hala_later'));
            return `<button type="button" class="h-tile ${cls}" data-step="${s.key}" ${s.state === 'now' ? '' : 'tabindex="-1" aria-disabled="true"'}>
                <span class="h-num">${s.state === 'done' ? '✓' : k + 1}</span>${i(ICON_OF[s.key], 'h-tile-ic')}<span class="h-tile-l">${esc(t('hala_step_' + s.key))}</span>
                <span class="h-tile-s ${s.state === 'done' ? 'h-ok' : ''}">${esc(label)}</span></button>`;
        }).join('');
        const root = paint(top(t('hala_hello', {name}), t('hala_shift_sub', {group: D.group.name, start: D.shift.start, end: D.shift.end}), {home: true}) + `
            <div class="h-body is-home"><p class="h-ask">${esc(finished ? t('hala_home_done') : t('hala_ask_now'))}</p>
            <p class="h-sub">${esc(finished ? t('hala_home_done_sub', {time: hhmm(D.end.finished_at)}) : t('hala_ask_now_sub'))}</p>
            <div class="h-tiles" style="--n:${D.steps.length}">${tiles}</div></div>`, 'is-home');
        root.querySelectorAll('.h-tile.is-now').forEach(b => b.addEventListener('click', () => go(b.dataset.step)));
        if (message) B.toast(message);
        if (D.help) watchHelp(D.poll_s);
    }

    // --- B. Co robię dziś ---------------------------------------------------------------------------------------------------
    const step1 = (it) => (it.ppc ? it.ppc : 1);
    const cartons = (q, it) => (it.ppc ? Math.round(q / it.ppc * 10) / 10 : null);

    function renderMake() {
        const m = D.make;
        const items = m.items;
        if (selected === null || !items.some(x => x.item_id === selected)) selected = m.now_item ?? (items[0] ? items[0].item_id : null);
        const card = (it) => {
            const sel = it.item_id === selected;
            const value = pending[it.item_id] ? pending[it.item_id].value : it.made;
            const big = it.planned !== null ? it.planned : value;
            const c = cartons(big, it);
            let badge;
            if (it.planned === null) badge = `<span class="h-badge is-neu">${esc(t('hala_off_plan', {q: fmt(value)}))}</span>`;
            else if (value + 1e-6 >= it.planned) badge = `<span class="h-badge is-ok">${i('done')}${esc(t('hala_ready', {made: fmt(value), planned: fmt(it.planned)}))}</span>`;
            else if (sel || it.state === 'now') badge = `<span class="h-badge is-warn">${esc(t('hala_left', {n: fmt(it.planned - value)}))}</span>`;
            else badge = `<span class="h-badge is-neu">${i('clock')}${esc(it.start ? t('hala_from', {time: it.start.replace(/^0/, '')}) : t('hala_later'))}</span>`;
            return `<article class="h-card h-pc ${sel ? 'is-now' : ''} ${it.done && !sel ? 'is-fin' : ''}" data-item="${it.item_id}">
                <div class="h-ph" data-photo="${it.item_id}">${it.has_photo && photos[it.item_id] ? `<img src="${photos[it.item_id]}" alt="">` : `<span>${i('img')}${esc(t('hala_photo_none'))}</span>`}</div>
                <div class="h-nm">${esc(it.name)}</div>
                <div class="h-q"><b>${esc(fmt(big))}</b><span>${esc(c !== null ? t('hala_qty_sub', {unit: it.unit, c: fmt(c)}) : it.unit)}</span></div>
                ${sel && !m.locked ? `<div class="h-cnt"><button type="button" class="h-btn" data-step="-1" aria-label="−">${i('minus')}</button>
                    <button type="button" class="h-v" data-type aria-label="${esc(t('hala_type_number'))}">${esc(fmt(value))}</button>
                    <button type="button" class="h-btn" data-step="1" aria-label="+">${i('plus')}</button></div>` : ''}
                ${badge}</article>`;
        };
        const root = paint(top(t('hala_step_make'), `${D.group.name} · ${dateLabel()}`) + progress('make') + `
            <div class="h-body"><p class="h-ask">${esc(items.length ? plural(items.length, 'hala_make_ask') : t('hala_make_empty'))}</p>
            <div class="h-prods">${items.map(card).join('')}</div>
            <div class="h-actions"><button type="button" class="h-btn" data-waste ${items.length && !m.locked ? '' : 'disabled'}>${i('trash')}${esc(t('hala_waste'))}</button>
            <button type="button" class="h-btn is-primary" data-next>${esc(t('hala_next'))} ${i('next')}</button></div></div>`);
        root.querySelectorAll('[data-item]').forEach(c => c.addEventListener('click', (e) => {
            if (e.target.closest('button')) return;
            const id = Number(c.dataset.item);
            if (id !== selected) { selected = id; renderMake(); }
        }));
        root.querySelectorAll('[data-step]').forEach(b => b.addEventListener('click', () => bump(Number(b.dataset.step))));
        const typeBtn = root.querySelector('[data-type]');
        if (typeBtn) {
            // Toucher : saisie de la liczba (clavier numérique) ; le +/- ajoute un carton.
            typeBtn.addEventListener('click', () => numberSheet(t('hala_type_number'), (pending[selected] || {}).value ?? items.find(x => x.item_id === selected).made, (v) => setCounter(selected, v)));
        }
        root.querySelector('[data-waste]')?.addEventListener('click', () => wasteSheet());
        root.querySelector('[data-next]').addEventListener('click', (e) => next('make', e.currentTarget));
        loadPhotos(items);
    }

    async function loadPhotos(items) {
        for (const it of items) {
            if (!it.has_photo || photos[it.item_id]) continue;
            try {
                const r = await B.api('GET', `/kiosk/hala/photos/${it.item_id}`);
                photos[it.item_id] = r.src;
                const box = B.el.querySelector(`[data-photo="${it.item_id}"]`);
                if (box) box.innerHTML = `<img src="${r.src}" alt="">`;
            } catch (e) { /* sans photo : le cadre reste */ }
        }
    }

    function bump(dir) {
        const it = D.make.items.find(x => x.item_id === selected);
        if (!it) return;
        const cur = pending[it.item_id] ? pending[it.item_id].value : it.made;
        setCounter(it.item_id, Math.max(0, Math.round((cur + dir * step1(it)) * 1000) / 1000));
    }

    // Compteur : affiché tout de suite, envoyé 1,2 s après le dernier toucher (et avant de quitter l'écran).
    function setCounter(id, value) {
        const p = pending[id] || (pending[id] = {value: 0, timer: null});
        p.value = value;
        window.clearTimeout(p.timer);
        p.timer = window.setTimeout(() => sendCounter(id), 1200);
        if (view === 'make') renderMake();
    }

    async function sendCounter(id) {
        const p = pending[id];
        if (!p) return;
        delete pending[id];
        window.clearTimeout(p.timer);
        try {
            D = await B.api('PUT', `/kiosk/hala/made/${id}`, {made: p.value}, true);
        } catch (err) {
            B.toast(err.offline ? t('offline_retry') : err.message, true);
            try { await reload(); } catch (e) { /* hors ligne */ }
        }
        if (view === 'make') renderMake();
    }

    async function flushCounters() {
        await Promise.all(Object.keys(pending).map(id => sendCounter(Number(id))));
    }

    // Clavier numérique (liczba ou température) : grandes touches, virgule, effacer.
    function numberSheet(title, value, done, decimals) {
        let v = value === null || value === undefined ? '' : String(value).replace('.', ',');
        const sheet = document.createElement('div');
        sheet.className = 'h-sheet';
        const keys = ['1', '2', '3', '4', '5', '6', '7', '8', '9', decimals ? ',' : '', '0', '⌫'];
        sheet.innerHTML = `<div class="h-sheet-box"><p class="h-ask is-s">${esc(title)}</p><div class="h-numv" data-v></div>
            <div class="h-keys">${keys.map(k => k ? `<button type="button" class="h-btn" data-k="${k}">${k}</button>` : '<span></span>').join('')}</div>
            <div class="h-actions"><button type="button" class="h-btn" data-close>${esc(t('cancel'))}</button><button type="button" class="h-btn is-primary" data-ok>OK</button></div></div>`;
        B.el.querySelector('.hala').appendChild(sheet);
        const show = () => { sheet.querySelector('[data-v]').textContent = v || '0'; };
        show();
        sheet.querySelectorAll('[data-k]').forEach(b => b.addEventListener('click', () => {
            const k = b.dataset.k;
            if (k === '⌫') v = v.slice(0, -1);
            else if (k === ',') { if (!v.includes(',')) v = (v || '0') + ','; }
            else if (v.length < 7) v = v === '0' ? k : v + k;
            show();
        }));
        sheet.querySelector('[data-close]').addEventListener('click', () => sheet.remove());
        sheet.querySelector('[data-ok]').addEventListener('click', () => {
            const n = Number(v.replace(',', '.'));
            if (v === '' || !Number.isFinite(n)) return;
            sheet.remove();
            done(n);
        });
    }

    // « Zepsute » : trois grands motifs (spalone, zły kształt, upadło), la quantité, le produit choisi.
    function wasteSheet() {
        const items = D.make.items.filter(x => x.made > 0);
        let item = items.find(x => x.item_id === selected) || items[0];
        if (!item) { B.toast(t('hala_waste_none'), true); return; }
        let reason = null;
        let q = 1;
        const sheet = document.createElement('div');
        sheet.className = 'h-sheet';
        const draw = () => {
            sheet.innerHTML = `<div class="h-sheet-box is-wide"><p class="h-ask is-s">${esc(t('hala_waste_title'))}</p>
                ${items.length > 1 ? `<div class="h-chips">${items.map(x => `<button type="button" class="h-chip ${x.item_id === item.item_id ? 'is-on' : ''}" data-it="${x.item_id}">${esc(x.name)}</button>`).join('')}</div>` : `<p class="h-sub">${esc(item.name)}</p>`}
                <div class="h-reasons">${[['burnt', 'temp'], ['quality', 'alert'], ['damaged', 'trash']].map(([k, ic]) => `<button type="button" class="h-btn h-reason ${reason === k ? 'is-on' : ''}" data-r="${k}">${i(ic)}${esc(t('hala_w_' + k))}</button>`).join('')}</div>
                <div class="h-cnt is-w"><button type="button" class="h-btn" data-q="-1" aria-label="−">${i('minus')}</button><span class="h-v">${esc(fmt(q))} <small>${esc(item.unit)}</small></span>
                    <button type="button" class="h-btn" data-q="1" aria-label="+">${i('plus')}</button></div>
                <div class="h-actions"><button type="button" class="h-btn" data-close>${esc(t('cancel'))}</button>
                    <button type="button" class="h-btn is-primary" data-save ${reason ? '' : 'disabled'}>${esc(t('hala_waste_save'))}</button></div></div>`;
            sheet.querySelectorAll('[data-it]').forEach(b => b.addEventListener('click', () => { item = items.find(x => x.item_id === Number(b.dataset.it)); draw(); }));
            sheet.querySelectorAll('[data-r]').forEach(b => b.addEventListener('click', () => { reason = b.dataset.r; draw(); }));
            sheet.querySelectorAll('[data-q]').forEach(b => b.addEventListener('click', () => { q = Math.max(1, q + Number(b.dataset.q)); draw(); }));
            sheet.querySelector('[data-close]').addEventListener('click', () => sheet.remove());
            sheet.querySelector('[data-save]').addEventListener('click', async (e) => {
                e.currentTarget.disabled = true;
                try {
                    await flushCounters();
                    await B.api('POST', '/kiosk/waste', {item_id: item.item_id, quantity: q, reason, client_uuid: B.uuid()}, true);
                    sheet.remove();
                    await reload();
                    B.toast(t('hala_waste_saved', {q: fmt(q), name: item.name}));
                    renderMake();
                } catch (err) { e.currentTarget.disabled = false; B.toast(err.offline ? t('offline_retry') : err.message, true); }
            });
        };
        draw();
        B.el.querySelector('.hala').appendChild(sheet);
    }

    // --- C. Skanuj worki ----------------------------------------------------------------------------------------------------
    let lastScan = null; // {result, material, best_before}

    function renderBags() {
        const b = D.bags;
        const prods = b.products;
        if (!prods.some(p => p.item_id === selected)) selected = b.focus;
        const p = prods.find(x => x.item_id === selected) || null;
        const res = lastScan && lastScan.result === 'ok'
            ? `<div class="h-res is-ok">${i('done')}<div><b>${esc(t('hala_scan_ok'))}</b><span>${esc(lastScan.best_before ? t('hala_scan_ok_sub', {material: lastScan.material, date: dmy(lastScan.best_before)}) : lastScan.material)}</span></div></div>`
            : `<div class="h-res is-neu">${i('qr')}<div><b>${esc(t('hala_scan_wait'))}</b><span>${esc(p ? t('hala_scan_wait_sub', {name: p.name}) : '')}</span></div></div>`;
        const list = p ? [...p.ingredients.map(x => `<div class="h-li">${i('bag')}<span>${esc(x.name)}</span>${!x.tracked ? `<span class="h-r h-muted">${esc(t('hala_no_bag'))}</span>`
                : (x.done ? `<span class="h-r h-badge is-ok">✓</span>` : `<span class="h-r h-badge is-warn">${esc(t('hala_scan_todo'))}</span>`)}</div>`),
            ...p.components.map(x => `<button type="button" class="h-li is-btn" data-batch="${p.batch_id}">${i('box')}<span>${esc(x.name)}<small>${esc(t('hala_component', {group: x.producer}))}</small></span>
                ${x.done ? `<span class="h-r h-badge is-ok">✓</span>` : `<span class="h-r h-badge is-warn">${esc(t('hala_component_pick'))}</span>`}</button>`)].join('') : '';
        const root = paint(top(t('hala_step_bags'), p ? p.name : D.group.name) + progress('bags') + `
            <div class="h-body"><div class="h-two">
                <section class="h-card h-panel"><p class="h-ask is-m">${esc(t('hala_bags_ask'))}</p>
                    <div class="h-cam" data-cam><video data-video autoplay playsinline muted hidden></video><div class="h-fr"></div><div class="h-cam-h">${esc(t('hala_bags_cam'))}</div></div>
                    <div class="h-row"><input class="h-input" data-code autocomplete="off" autocapitalize="characters" placeholder="${esc(t('hala_bags_type'))}" aria-label="${esc(t('hala_bags_type'))}">
                    <button type="button" class="h-btn is-s" data-code-ok>${i('next')}</button></div></section>
                <section class="h-card h-panel">${prods.length > 1 ? `<div class="h-chips">${prods.map(x => `<button type="button" class="h-chip ${x.item_id === selected ? 'is-on' : ''} ${x.complete ? 'is-ok' : ''}" data-prod="${x.item_id}">${esc(x.name)}</button>`).join('')}</div>` : ''}
                    ${p ? res + `<div class="h-list">${list}</div>` : `<div class="h-res is-warn">${i('alert')}<div><b>${esc(t('hala_bags_none'))}</b><span>${esc(t('hala_bags_none_sub'))}</span></div></div>`}
                    <button type="button" class="h-btn is-primary h-push" data-next ${b.complete ? '' : 'disabled'}>${esc(t('hala_next'))} ${i('next')}</button></section>
            </div></div>`);
        root.querySelectorAll('[data-prod]').forEach(x => x.addEventListener('click', () => { selected = Number(x.dataset.prod); lastScan = null; renderBags(); }));
        root.querySelectorAll('[data-batch]').forEach(x => x.addEventListener('click', () => { stopAll(); B.renderBatch(Number(x.dataset.batch)); }));
        root.querySelector('[data-next]').addEventListener('click', (e) => next('bags', e.currentTarget));
        const input = root.querySelector('[data-code]');
        const submit = () => { const v = input.value.trim(); if (v) { input.value = ''; scan(v); } };
        input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); submit(); } });
        root.querySelector('[data-code-ok]').addEventListener('click', submit);
        input.focus({preventScroll: true});
        if (p) startCam(root.querySelector('[data-cam]'));
    }
    const dmy = (d) => String(d || '').slice(0, 10).split('-').reverse().join('.');

    // Caméra : BarcodeDetector (QR, codes-barres) quand le navigateur l'a ; sinon le lecteur (clavier) ou la saisie.
    async function startCam(box) {
        stopCam();
        if (!('BarcodeDetector' in window) || !navigator.mediaDevices) return;
        let stream;
        try { stream = await navigator.mediaDevices.getUserMedia({video: {facingMode: {ideal: 'environment'}}, audio: false}); } catch (e) { return; }
        if (view !== 'bags' || !box.isConnected) { stream.getTracks().forEach(tr => tr.stop()); return; }
        const video = box.querySelector('[data-video]');
        video.srcObject = stream;
        video.hidden = false;
        let detector;
        try { detector = new window.BarcodeDetector(); } catch (e) { stream.getTracks().forEach(tr => tr.stop()); return; }
        cam = {stream, timer: null, last: ''};
        const loop = async () => {
            if (!cam || cam.stream !== stream) return;
            try {
                const codes = await detector.detect(video);
                const v = codes[0] && codes[0].rawValue ? codes[0].rawValue.trim() : '';
                if (v && v !== cam.last) { cam.last = v; scan(v); return; }
            } catch (e) { /* image pas prête */ }
            cam.timer = window.setTimeout(loop, 350);
        };
        cam.timer = window.setTimeout(loop, 500);
    }
    function stopCam() {
        if (!cam) return;
        window.clearTimeout(cam.timer);
        cam.stream.getTracks().forEach(tr => tr.stop());
        cam = null;
    }

    async function scan(code, materialId) {
        if (!selected) return;
        try {
            const r = await B.api('POST', '/kiosk/hala/bags', {item_id: selected, code, ...(materialId ? {material_id: materialId} : {})}, true);
            D = r.day;
            lastScan = r;
            if (r.result === 'ok') { renderBags(); return; }
            stopCam();
            if (r.result === 'unknown') return unknownSheet(r);
            renderStop(r);
        } catch (err) { B.toast(err.offline ? t('offline_retry') : err.message, true); }
    }

    function unknownSheet(r) {
        const sheet = document.createElement('div');
        sheet.className = 'h-sheet';
        sheet.innerHTML = `<div class="h-sheet-box is-wide"><div class="h-res is-warn">${i('alert')}<div><b>${esc(t('hala_unknown', {code: r.code}))}</b><span>${esc(t('hala_unknown_sub'))}</span></div></div>
            <div class="h-chips is-big">${r.choices.map(c => `<button type="button" class="h-chip" data-m="${c.material_id}">${esc(c.name)}</button>`).join('')}</div>
            <div class="h-actions"><button type="button" class="h-btn" data-close>${esc(t('hala_scan_other'))}</button></div></div>`;
        B.el.querySelector('.hala').appendChild(sheet);
        sheet.querySelectorAll('[data-m]').forEach(b => b.addEventListener('click', () => { sheet.remove(); scan(r.code, Number(b.dataset.m)); }));
        sheet.querySelector('[data-close]').addEventListener('click', () => { sheet.remove(); renderBags(); });
    }

    // STOP : mauvais sac ou date dépassée — rouge, une phrase, deux boutons ; rien n'est enregistré.
    function renderStop(r) {
        const msg = r.result === 'expired' ? t('hala_stop_expired_sub', {material: r.material, date: dmy(r.best_before)})
            : t('hala_stop_wrong_sub', {material: r.material, expected: (r.expected || []).join(', ')});
        const root = paint(`<div class="h-stop">${i('stop', 'h-stop-ic')}<div><b>${esc(t(r.result === 'expired' ? 'hala_stop_expired' : 'hala_stop_wrong'))}</b><span>${esc(msg)}</span>
            <div class="h-row"><button type="button" class="h-help is-big" data-call>${i('help')}${esc(t('hala_call_lead'))}</button>
            <button type="button" class="h-btn is-m" data-again>${i('back')}${esc(t('hala_scan_other'))}</button></div></div></div>`, 'is-stop');
        root.querySelector('[data-call]').addEventListener('click', () => callHelp('bags', 'bag', `${r.material} — ${r.code}${r.result === 'expired' ? ' (' + dmy(r.best_before) + ')' : ''}`));
        root.querySelector('[data-again]').addEventListener('click', () => { lastScan = null; renderBags(); });
    }

    // --- D. Schładzanie ------------------------------------------------------------------------------------------------------
    const mmss = (min) => `${Math.floor(min / 60)}:${String(min % 60).padStart(2, '0')}`;
    function renderChill() {
        const c = D.chill;
        const rows = c.rows;
        // Minutnik : la sortie de four la plus pressée ; panneau de gauche : la ligne touchée (sinon celle à mesurer).
        const running = rows.filter(r => r.state === 'running').sort((a, b) => a.left - b.left);
        let cur = rows.find(r => r.id === selected) || running[0] || rows[rows.length - 1] || null;
        if (cur) selected = cur.id;
        const target = fmt(c.limits.target_c);
        let res = '';
        if (cur && cur.temp_c !== null) {
            res = cur.state === 'ok' ? `<div class="h-res is-ok">${i('done')}<div><b>${esc(t('hala_chill_ok'))}</b><span>${esc(t('hala_chill_ok_sub', {t: target, time: hhmm(cur.measured_at)}))}</span></div></div>`
                : `<div class="h-res is-stop">${i('stop')}<div><b>${esc(t(cur.state === 'warm' ? 'hala_chill_warm' : 'hala_chill_late'))}</b><span>${esc(t('hala_chill_bad_sub', {t: target}))}</span></div></div>`;
        } else if (cur) {
            res = `<button type="button" class="h-btn is-m h-wide" data-measure>${i('temp')}${esc(t('hala_chill_measure'))}</button>`;
        }
        const badge = (r) => {
            if (r.state === 'ok') return `<span class="h-r h-badge is-ok">${esc(fmt(r.temp_c))} °C ✓</span>`;
            if (r.state === 'warm') return `<span class="h-r h-badge is-stop">${esc(t('hala_chill_badge_warm', {t: fmt(r.temp_c)}))}</span>`;
            if (r.state === 'late') return `<span class="h-r h-badge is-stop">${esc(r.temp_c !== null ? t('hala_chill_badge_late_t', {t: fmt(r.temp_c)}) : t('hala_chill_badge_late'))}</span>`;
            return `<span class="h-r h-badge is-warn">${esc(t('hala_chill_badge_in', {time: mmss(r.left)}))}</span>`;
        };
        const timer = running.length ? mmss(running[0].left) : '0:00';
        const root = paint(top(t('hala_step_chill'), `${D.group.name}${cur ? ' · ' + cur.name : ''}`) + progress('chill') + `
            <div class="h-body"><div class="h-two">
                <section class="h-card h-panel is-center"><p class="h-ask is-m">${esc(t('hala_chill_ask'))}</p>${i('temp', 'h-thermo')}
                    <div class="h-big ${cur && cur.temp_c !== null ? (cur.state === 'ok' ? 'is-ok' : 'is-stop') : ''}">${cur && cur.temp_c !== null ? esc(fmt(cur.temp_c)) : '—'} <small>°C</small></div>${res}</section>
                <section class="h-card h-panel"><p class="h-ask is-m is-ic">${i('clock')}${esc(t('hala_chill_timer'))}</p>
                    <div class="h-timer" data-timer>${esc(timer)}</div><p class="h-sub is-c">${esc(running.length ? t('hala_chill_timer_sub', {name: running[0].name, h: c.limits.max_minutes % 60 ? c.limits.max_minutes + ' min' : c.limits.max_minutes / 60 + ' h'}) : t('hala_chill_timer_none'))}</p>
                    <div class="h-list">${rows.map(r => `<button type="button" class="h-li is-btn ${r.id === selected ? 'is-sel' : ''}" data-row="${r.id}"><span>${esc(r.name)}<small>${esc(t('hala_chill_out', {time: hhmm(r.out_at)}))}</small></span>${badge(r)}</button>`).join('')
                        || `<div class="h-li h-muted">${esc(t('hala_chill_empty'))}</div>`}</div>
                    <div class="h-row"><button type="button" class="h-btn is-m" data-start>${i('plus')}${esc(t('hala_chill_start'))}</button>
                    <button type="button" class="h-btn is-primary is-grow" data-next ${c.complete ? '' : 'disabled'}>${esc(t('hala_next'))} ${i('next')}</button></div></section>
            </div></div>`);
        root.querySelectorAll('[data-row]').forEach(b => b.addEventListener('click', () => { selected = Number(b.dataset.row); renderChill(); }));
        root.querySelector('[data-measure]')?.addEventListener('click', () => numberSheet(t('hala_chill_measure_title', {name: cur.name}), '', async (v) => {
            try { D = await B.api('PUT', `/kiosk/hala/chill/${cur.id}`, {temp_c: v}, true); renderChill(); }
            catch (err) { B.toast(err.offline ? t('offline_retry') : err.message, true); }
        }, true));
        root.querySelector('[data-start]').addEventListener('click', () => chooser(t('hala_chill_start_title'), c.products, async (id) => {
            try { D = await B.api('POST', '/kiosk/hala/chill', {item_id: id, client_uuid: B.uuid()}, true); selected = null; renderChill(); }
            catch (err) { B.toast(err.offline ? t('offline_retry') : err.message, true); }
        }));
        root.querySelector('[data-next]').addEventListener('click', (e) => next('chill', e.currentTarget));
        // Minutnik : l'heure avance à l'écran ; un rechargement toutes les minutes met les états à jour.
        tick = window.setInterval(() => { if (!alive()) return stopAll(); if (view === 'chill' && !document.hidden) reload().then(renderChill).catch(() => {}); }, 60000);
    }

    function chooser(title, list, done) {
        const sheet = document.createElement('div');
        sheet.className = 'h-sheet';
        sheet.innerHTML = `<div class="h-sheet-box is-wide"><p class="h-ask is-s">${esc(title)}</p><div class="h-chips is-big">${list.map(x => `<button type="button" class="h-chip" data-id="${x.item_id}">${esc(x.name)}</button>`).join('')
            || `<p class="h-sub">${esc(t('hala_make_empty'))}</p>`}</div><div class="h-actions"><button type="button" class="h-btn" data-close>${esc(t('cancel'))}</button></div></div>`;
        B.el.querySelector('.hala').appendChild(sheet);
        sheet.querySelectorAll('[data-id]').forEach(b => b.addEventListener('click', () => { sheet.remove(); done(Number(b.dataset.id)); }));
        sheet.querySelector('[data-close]').addEventListener('click', () => sheet.remove());
    }

    // --- E. Sprzątanie -----------------------------------------------------------------------------------------------------
    function renderClean() {
        const c = D.clean;
        const root = paint(top(t('hala_step_clean'), `${D.group.name} · ${t('hala_clean_when')}`) + progress('clean') + `
            <div class="h-body"><p class="h-ask">${esc(t('hala_clean_ask'))}</p><p class="h-sub">${esc(t('hala_clean_sub', {done: c.done, total: c.total}))}</p>
            <div class="h-clean" style="--n:${Math.min(4, Math.max(1, c.zones.length))}">${c.zones.map(z => `<button type="button" class="h-card h-cl ${z.done ? 'is-ok' : ''}" data-zone="${z.id}" data-done="${z.done ? 1 : 0}">
                ${i(z.icon, 'h-cl-ic')}<span class="h-cl-l">${esc(z.name)}</span>
                <span class="h-cl-b ${z.done ? 'h-ok' : 'h-neu'}">${esc(z.done ? t(z.icon === 'bin' ? 'hala_clean_done_bin' : 'hala_clean_done') : t(z.icon === 'bin' ? 'hala_clean_todo_bin' : 'hala_clean_todo'))}</span></button>`).join('')
                || `<p class="h-sub">${esc(t('hala_clean_none'))}</p>`}</div>
            <button type="button" class="h-btn is-primary h-full" data-next ${c.complete ? '' : 'disabled'}>${esc(t('hala_next'))} ${i('next')}</button></div>`);
        root.querySelectorAll('[data-zone]').forEach(b => b.addEventListener('click', async () => {
            const done = b.dataset.done !== '1';
            if (!done && !window.confirm(t('hala_clean_undo'))) return;
            b.disabled = true;
            try { D = await B.api('PUT', `/kiosk/hala/clean/${b.dataset.zone}`, {done}, true); renderClean(); }
            catch (err) { b.disabled = false; B.toast(err.offline ? t('offline_retry') : err.message, true); }
        }));
        root.querySelector('[data-next]').addEventListener('click', (e) => next('clean', e.currentTarget));
    }

    // --- G. Pakowacz : Pakuję (tablette d'emballage : GET /kiosk/pallets, POST /kiosk/pallets/entries) ---------------------
    async function renderPack(customer) {
        let data;
        try {
            data = await B.api('GET', '/kiosk/pallets' + (customer ? `?customer_id=${customer}` : ''));
        } catch (err) { B.toast(err.message, true); return; }
        packState = {customer: data.customer_id, data};
        const clients = data.clients.filter(x => x.ordered > 0);
        const k = clients.findIndex(x => x.id === data.customer_id);
        const c = clients[k] || null;
        const boxes = c ? Math.min(c.ordered, 40) : 0;
        const missing = c ? c.lines.filter(l => l.left > 0) : [];
        const free = c ? c.lines.filter(l => l.free > 0) : [];
        const allDone = clients.length > 0 && clients.every(x => x.left === 0);
        const root = paint(top(t('hala_step_pack'), t('hala_pack_sub', {group: D.group.name, date: dmy(data.date)})) + progress('pack') + `
            <div class="h-body"><div class="h-two">
                <section class="h-card h-panel is-center">${c ? `<p class="h-ask is-m">${esc(c.name)}</p><p class="h-sub">${esc(t('hala_pack_order', {n: k + 1, total: clients.length}))}</p>
                    <div class="h-ctr">${esc(fmt(c.on_pallet))}<small> / ${esc(fmt(c.ordered))}</small></div><p class="h-sub">${esc(t('hala_pack_scanned'))}</p>
                    <div class="h-boxes">${Array.from({length: boxes}, (_, n) => n < c.on_pallet ? '<i class="is-d">✓</i>' : `<i>${i('box')}</i>`).join('')}</div>`
                    : `<p class="h-ask is-m">${esc(t('hala_pack_none'))}</p>`}</section>
                <section class="h-card h-panel"><p class="h-ask is-m">${esc(t('hala_pack_missing'))}</p>
                    <div class="h-list">${missing.map(l => `<div class="h-li">${i('box')}<span>${esc(l.name)}</span><span class="h-r h-badge is-warn">${esc(plural(l.left, 'hala_cartons'))}</span>
                        <button type="button" class="h-btn is-xs" data-plus="${l.product_id}" aria-label="${esc(t('hala_pack_add'))}">${i('plus')}</button></div>`).join('')
                        || `<div class="h-li">${i('done')}<span>${esc(t('hala_pack_all'))}</span></div>`}</div>
                    ${free.length ? `<div class="h-res is-neu is-s">${i('gift')}<div><b>${esc(t('hala_gratis'))}</b><span>${esc(t('hala_gratis_sub', {names: free.map(l => l.name).join(', ')}))}</span></div></div>` : ''}
                    <button type="button" class="h-btn is-primary h-push" data-next ${c && c.left === 0 ? '' : 'disabled'}>${esc(allDone ? t('hala_pack_finish') : t('hala_next'))} ${i('next')}</button></section>
            </div></div>`);
        root.querySelectorAll('[data-plus]').forEach(b => b.addEventListener('click', async () => {
            b.disabled = true;
            try {
                await B.api('POST', '/kiosk/pallets/entries', {date: data.date, customer_id: c.id, product_id: Number(b.dataset.plus), cartons: 1, employee_id: B.session().id});
                renderPack(c.id);
            } catch (err) { b.disabled = false; B.toast(err.offline ? t('offline_retry') : err.message, true); }
        }));
        root.querySelector('[data-next]').addEventListener('click', (e) => {
            const nextClient = clients.slice(k + 1).concat(clients.slice(0, k)).find(x => x.left > 0);
            if (nextClient) return renderPack(nextClient.id);
            next('pack', e.currentTarget);
        });
        // Les cartons sont scannés ailleurs (lecteur de palettes) : le compteur se met à jour tout seul.
        window.clearInterval(tick);
        tick = window.setInterval(() => { if (!alive()) return stopAll(); if (view === 'pack' && !document.hidden && !B.el.querySelector('.h-sheet')) renderPack(packState.customer); }, Math.max(5, D.poll_s || 15) * 1000);
    }

    // --- F. Koniec dnia ------------------------------------------------------------------------------------------------------
    function renderEnd() {
        const e = D.end;
        const card = (ic, label, value, ok, step) => `<button type="button" class="h-card h-sm" ${step ? `data-goto="${step}"` : 'disabled'}>${i(ic)}<span class="h-sm-l">${esc(label)}</span>
            <span class="h-badge ${ok ? 'is-ok' : 'is-warn'}">${esc(value)}</span></button>`;
        const cards = [
            e.make ? card('list', t('hala_end_made'), t('hala_x_of_y', {x: e.make.done, y: e.make.total}), e.make.done >= e.make.total, 'make') : '',
            e.waste !== null ? card('trash', t('hala_end_waste'), t('hala_pcs', {n: fmt(e.waste)}), !e.waste, 'make') : '',
            e.scans ? card('qr', t('hala_end_bags'), plural(e.scans.scans, 'hala_scans'), e.scans.complete, 'bags') : '',
            e.chill ? card('temp', t('hala_end_chill'), e.chill.bad ? t('hala_end_chill_bad', {n: e.chill.bad}) : t('hala_end_chill_ok', {n: e.chill.ok}), !e.chill.bad, 'chill') : '',
            e.clean ? card('clean', t('hala_end_clean'), t('hala_x_of_y', {x: e.clean.done, y: e.clean.total}), e.clean.done >= e.clean.total, 'clean') : '',
            e.pack ? card('box', t('hala_end_pack'), t('hala_x_of_y', {x: e.pack.done, y: e.pack.total}), e.pack.done >= e.pack.total, 'pack') : '',
        ].filter(Boolean);
        const root = paint(top(t('hala_step_end'), `${D.group.name} · ${dateLabel()}`) + progress('end') + `
            <div class="h-body"><p class="h-ask">${esc(e.finished ? t('hala_end_done') : t('hala_end_ask'))}</p><p class="h-sub">${esc(e.finished ? t('hala_end_done_sub', {time: hhmm(e.finished_at), name: e.finished_by || ''}) : t('hala_end_sub'))}</p>
            <div class="h-sum" style="--n:${Math.min(4, cards.length)}">${cards.join('')}</div>
            ${e.finished ? `<button type="button" class="h-btn h-close" data-out>${i('out')}${esc(t('logout'))}</button>`
                : `<button type="button" class="h-btn is-primary h-close" data-close ${e.can_close ? '' : 'disabled'}>${i('done')}${esc(t('hala_close'))}</button>`}
            <p class="h-sub is-c">${esc(t('hala_close_sub'))}</p></div>`);
        root.querySelectorAll('[data-goto]').forEach(b => b.addEventListener('click', () => go(b.dataset.goto)));
        root.querySelector('[data-out]')?.addEventListener('click', () => { stopAll(); B.logout(); });
        root.querySelector('[data-close]')?.addEventListener('click', async (ev) => {
            ev.currentTarget.disabled = true;
            try { D = await B.api('POST', '/kiosk/hala/close', undefined, true); B.toast(t('hala_closed_toast')); renderEnd(); }
            catch (err) { ev.currentTarget.disabled = false; B.toast(err.offline ? t('offline_retry') : err.message, true); }
        });
    }

    // --- H. Brygadzista : Zmiana ---------------------------------------------------------------------------------------------
    async function renderShift(message) {
        view = 'shift';
        let S;
        try { S = await B.api('GET', '/kiosk/hala/shift', undefined, true); } catch (err) { if (!err.code) B.toast(err.message, true); return; }
        const calls = S.calls;
        const reasonTxt = (c) => c.note || t('hala_reason_' + c.reason);
        const group = (g) => {
            const badges = [];
            if (g.calls) badges.push(`<span class="h-badge is-stop is-xs">${esc(plural(g.calls, 'hala_calls'))}</span>`);
            if (g.chill_bad) badges.push(`<span class="h-badge is-stop is-xs">${esc(t('hala_g_warm', {n: g.chill_bad}))}</span>`);
            if (g.delay_min) badges.push(`<span class="h-badge is-warn is-xs">${esc(t('hala_g_delay', {m: g.delay_min}))}</span>`);
            if (g.closed_at) badges.push(`<span class="h-badge is-ok is-xs">${esc(t('hala_g_closed', {time: hhmm(g.closed_at)}))}</span>`);
            if (!badges.length) badges.push(`<span>${esc([g.bags_ok ? t('hala_g_bags') : '', g.chill_ok ? t('hala_g_temp') : ''].filter(Boolean).join(' · '))}</span>`);
            const color = g.status === 'red' ? 'var(--h-stop)' : (g.status === 'gold' ? 'var(--h-prize)' : 'var(--h-green)');
            return `<article class="h-card h-gr is-${g.status}"><h3>${esc(g.name)}</h3><div class="h-bar"><i style="width:${Math.max(0, Math.min(100, g.pct))}%;background:${color}"></i></div>
                <div class="h-gr-row"><span>${esc(g.kind === 'pack' ? t('hala_g_orders', {d: g.orders.done, n: g.orders.total}) : t('hala_g_pct', {p: g.pct}))}</span>${badges.join(' ')}</div></article>`;
        };
        const callRow = (c) => `<div class="h-res is-stop h-call">${i('help')}<div><b>${esc(t('hala_call_title', {group: c.group || '—', what: reasonTxt(c)}))}</b>
            <span>${esc(t('hala_call_sub', {time: hhmm(c.created_at), group: c.group || '—', name: c.employee || ''}))}${c.status === 'taken' ? ' · ' + esc(t('hala_call_taken', {name: c.taken_by || ''})) : ''}</span></div>
            <button type="button" class="h-btn ${c.status === 'open' ? 'is-primary' : ''} is-m h-call-b" data-act="${c.status === 'open' ? 'take' : 'close'}" data-id="${c.id}">${esc(c.status === 'open' ? t('hala_call_go') : t('hala_call_done'))}</button></div>`;
        const right = `<span class="h-badge ${calls.length ? 'is-warn' : 'is-ok'} is-lg">${i(calls.length ? 'alert' : 'done')}${esc(calls.length ? plural(calls.length, 'hala_calls') : t('hala_calls_none'))}</span>`;
        const root = paint(top(t('hala_shift_title', {start: S.shift.start, end: S.shift.end}), t('hala_shift_lead', {date: dateLabel()}), {home: true, icon: 'team', noHelp: true, right}) + `
            <div class="h-body is-shift">${calls.map(callRow).join('')}
            <div class="h-groups">${S.groups.map(group).join('') || `<p class="h-sub">${esc(t('hala_shift_empty'))}</p>`}</div>
            ${S.validated ? `<div class="h-res is-ok h-full">${i('done')}<div><b>${esc(t('hala_shift_validated', {time: hhmm(S.validated.at), name: S.validated.by || ''}))}</b><span>${esc(S.validated.summary || '')}</span></div></div>`
                : `<button type="button" class="h-btn is-primary h-full" data-validate ${S.can_validate ? '' : 'disabled'}>${esc(t('hala_shift_validate'))}</button>`}</div>`, 'is-shift');
        root.querySelectorAll('[data-act]').forEach(b => b.addEventListener('click', async () => {
            b.disabled = true;
            try { await B.api('PATCH', `/kiosk/hala/help/${b.dataset.id}`, {action: b.dataset.act}, true); renderShift(); }
            catch (err) { b.disabled = false; B.toast(err.message, true); }
        }));
        root.querySelector('[data-validate]')?.addEventListener('click', async (e) => {
            e.currentTarget.disabled = true;
            try { await B.api('POST', '/kiosk/hala/shift/validate', undefined, true); B.toast(t('hala_shift_done')); renderShift(); }
            catch (err) { e.currentTarget.disabled = false; B.toast(err.message, true); }
        });
        if (message) B.toast(message);
        // Vue du chef : rafraîchie toutes les poll_s secondes (nouveaux wezwania), la session reste ouverte tant qu'elle est affichée.
        window.clearInterval(poll);
        poll = window.setInterval(() => {
            if (!alive()) return stopAll();
            if (view !== 'shift' || !B.el.querySelector('.hala.is-shift') || document.hidden) return;
            if (B.el.querySelector('.h-sheet')) return;
            B.resetIdle();
            renderShift();
        }, Math.max(5, S.poll_s || 15) * 1000);
    }

    window.tfbHala = {open};
})();
