/*
 * Tablet pakowania / Tablette d'emballage (PWA, 28/10/2026). Parowanie tym samym kodem co inne tablety (Tablety ›
 * Zarządzanie) ; każdy sparowany tablet, bez PIN. Dzień wysyłki : produkty do spakowania (zamówione − na paletach −
 * na stanie, w kolejności trasy), kartony, sztuki, czas wg normy « Pakowanie », osoby, czy zdążymy przed wyjazdem ;
 * licznik spakowanych kartonów − / + / « Gotowe » / « Cofnij » (informacyjny : nie tworzy kartonów w magazynie).
 * Odświeżanie co 30 s.
 */
(function () {
    'use strict';
    const VERSION = (document.currentScript && new URL(document.currentScript.src, location.href).searchParams.get('v')) || '';
    const el = document.getElementById('tfbPacking');
    if (!el) return;
    const base = (el.dataset.apiBase || '').replace(/\/$/, '');
    const root = (el.dataset.root || '').replace(/\/$/, '');
    // Ten sam token co inne tablety : tablet sparowany raz działa też tutaj.
    const DEVICE_KEY = 'tfb.kiosk.device';
    const LANG_KEY = 'tfb.lang';
    const store = {
        get(k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
        set(k, v) { try { v === null ? window.localStorage.removeItem(k) : window.localStorage.setItem(k, v); } catch (e) { /* stockage indisponible */ } },
    };
    let lang = store.get(LANG_KEY) === 'fr' ? 'fr' : 'pl';
    let labels = {};
    let device = store.get(DEVICE_KEY);
    let data = null;
    let date = (new URLSearchParams(location.search).get('date') || '').match(/^\d{4}-\d{2}-\d{2}$/) ? new URLSearchParams(location.search).get('date') : null;
    let people = null;
    let busy = false;
    let skew = 0;

    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const t = (k, p) => { let s = labels[k] ?? k; for (const [a, b] of Object.entries(p || {})) s = s.split('{' + a + '}').join(String(b)); return s; };
    const fmt = (n) => Number(n || 0).toLocaleString(lang === 'fr' ? 'fr-FR' : 'pl-PL', {maximumFractionDigits: 0});
    const hm = (s) => { const m = Math.max(0, Math.round(s / 60)); return m >= 60 ? `${Math.floor(m / 60)} h ${String(m % 60).padStart(2, '0')}` : `${m} min`; };
    const hhmm = (d) => `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
    const dayLabel = (iso) => new Date(iso + 'T12:00:00').toLocaleDateString(lang === 'fr' ? 'fr-FR' : 'pl-PL', {weekday: 'short', day: 'numeric', month: 'short'});
    const serverNow = () => new Date(Date.now() + skew);

    async function loadLabels() {
        const res = await fetch(`${root}/resources/lang/ui/${lang}/packing.json${VERSION ? '?v=' + encodeURIComponent(VERSION) : ''}`, {credentials: 'same-origin'});
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
        const headers = {'Accept': 'application/json', 'Accept-Language': lang, 'X-Device-Token': device || ''};
        if (body !== undefined) headers['Content-Type'] = 'application/json';
        let res;
        try {
            res = await fetch(base + path, {method, headers, body: body === undefined ? undefined : JSON.stringify(body), credentials: 'same-origin'});
        } catch (e) { throw new Error(t('offline')); }
        const json = await res.json().catch(() => ({}));
        if (res.ok) return json.data;
        const err = new Error(((json.errors || []).map(x => x.message).join(' ')) || json.message || t('error'));
        err.code = json.description;
        if (err.code === 'DEVICE_UNAUTHENTICATED') { device = null; data = null; store.set(DEVICE_KEY, null); renderPairing(); }
        throw err;
    }

    function header() {
        const sub = data ? t('subtitle_day', {day: dayLabel(data.date), time: data.departure}) : t('subtitle');
        return `<header class="kiosk-head"><div class="kiosk-brand"><div class="kiosk-logo">LA</div><div><h1>${esc(t('title'))}</h1>
                <p class="kiosk-muted">${esc(sub)}</p></div></div>
            <div class="kiosk-head-tools">${device || data ? `<button type="button" class="kiosk-chip" data-refresh aria-label="${esc(t('refresh'))}">↻</button>` : ''}
                <button type="button" class="kiosk-chip" data-lang>${esc(t('lang_switch'))}</button></div></header>`;
    }

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
                load();
            } catch (err) { el.querySelector('[data-error]').textContent = err.message; }
        });
        el.querySelector('[name=code]').focus();
    }

    // Bez tokenu w pamięci przeglądarki : serwer zna tablet z ciasteczka parowania (tablet zostaje sparowany).
    async function load() {
        try {
            data = await api('GET', '/kiosk/packing' + (date ? '?date=' + encodeURIComponent(date) : ''));
            date = data.date;
            skew = Date.parse(data.now.replace(' ', 'T')) - Date.now();
            if (people === null) people = Math.max(1, data.people.present || 1);
            render();
        } catch (err) {
            if (err.code === 'DEVICE_UNAUTHENTICATED') return;
            if (data) toast(err.message, true); else el.innerHTML = header() + `<section class="kiosk-card"><p class="kiosk-error">${esc(err.message)}</p>
                <button type="button" class="kiosk-btn" data-refresh>${esc(t('retry'))}</button></section>`;
        }
    }

    function render() {
        const s = data;
        const T = s.totals;
        const finish = new Date(serverNow().getTime() + T.seconds / people * 1000);
        const deadline = Date.parse(`${s.date}T${s.departure}:00`);
        const margin = (deadline - finish.getTime()) / 1000;
        const gone = T.remaining > 0 && deadline < serverNow().getTime();
        const ok = T.remaining === 0 || margin >= 0;
        // Dni : wczoraj … kolejne dni z zamówieniami (najwyżej 5 przycisków).
        const today = new Date(new Date(s.now.slice(0, 10) + 'T12:00:00').getTime() - 86400000).toISOString().slice(0, 10);
        const days = (s.dates || []).filter(d => d.ship_date >= today).slice(0, 5);
        if (!days.some(d => d.ship_date === s.date)) days.unshift({ship_date: s.date});
        const chips = days.map(d => `<button type="button" class="kiosk-chip ${d.ship_date === s.date ? 'is-active' : ''}" data-date="${d.ship_date}">${esc(dayLabel(d.ship_date))}</button>`).join('');
        const kpi = `<section class="pk-kpis">
            <div class="kiosk-card pk-kpi"><span>${esc(t('to_pack'))}</span><strong>${fmt(T.remaining)}</strong><small>${esc(t('cartons'))} · ${esc(t('products_done', {n: T.products, done: T.done}))}</small></div>
            <div class="kiosk-card pk-kpi"><span>${esc(t('pieces'))}</span><strong>${fmt(T.pieces)}</strong><small>${esc(t('pieces_help'))}</small></div>
            <div class="kiosk-card pk-kpi"><span>${esc(t('time'))}</span><strong>${esc(hm(T.seconds / people))}</strong>
                <div class="pk-people"><button type="button" class="kiosk-btn" data-people="-1" aria-label="${esc(t('less_people'))}">−</button>
                <b>${esc(t('people_n', {n: people}))}</b><button type="button" class="kiosk-btn" data-people="1" aria-label="${esc(t('more_people'))}">+</button></div></div>
            <div class="kiosk-card pk-kpi ${gone ? '' : (ok ? 'is-ok' : 'is-late')}"><span>${esc(t('in_time', {time: s.departure}))}</span><strong>${esc(T.remaining === 0 ? t('all_done') : (gone ? '—' : (ok ? t('yes') : t('no'))))}</strong>
                <small>${esc(T.remaining === 0 ? t('nothing_left') : (gone ? t('departed') : t(ok ? 'finish_ok' : 'finish_late', {time: hhmm(finish), margin: hm(Math.abs(margin))})))}</small></div></section>`;
        let cat = null;
        const rows = s.items.map(i => {
            const head = i.category !== cat ? `<li class="pk-cat">${esc(cat = i.category || t('no_category'))}</li>` : '';
            const clients = i.clients.map(c => `${esc(c.name)} ${fmt(c.cartons)}`).join(' · ') || esc(t('stock_labels'));
            return head + `<li class="pk-row ${i.done ? 'is-done' : (i.packed ? 'is-go' : '')}" data-product="${i.product_id}">
                <div class="pk-name"><b>${esc(i.name)}</b><small>${clients}</small></div>
                <div class="pk-num"><strong>${fmt(i.remaining)}</strong><small>${esc(t('cartons_short'))}${i.need !== i.remaining ? ' / ' + fmt(i.need) : ''}</small></div>
                <div class="pk-num"><strong>${fmt(i.pieces)}</strong><small>${esc(t('pieces_short', {n: i.ppc}))}</small></div>
                <div class="pk-num"><strong>${i.done ? '—' : (i.norm ? '' : '≈ ') + esc(hm(i.seconds))}</strong><small>${esc(t(i.norm ? 'norm' : 'no_norm'))}</small></div>
                <div class="pk-ctr">${i.done
                    ? `<span class="pk-done">✓ ${esc(t('packed_n', {n: fmt(i.packed)}))}</span><button type="button" class="kiosk-btn" data-set="0">${esc(t('undo'))}</button>`
                    : `<button type="button" class="kiosk-btn pk-step" data-step="-1" ${i.packed ? '' : 'disabled'} aria-label="${esc(t('less'))}">−</button>
                       <span class="pk-cnt"><b>${fmt(i.packed)}</b> / ${fmt(i.need)}</span>
                       <button type="button" class="kiosk-btn pk-step" data-step="1" aria-label="${esc(t('more'))}">+</button>
                       <button type="button" class="kiosk-btn is-primary" data-set="${i.need}">${esc(t('done'))}</button>`}</div></li>`;
        }).join('');
        el.innerHTML = header() + `<nav class="pk-days" aria-label="${esc(t('day'))}">${chips}</nav>` + kpi
            + (s.items.length ? `<section class="kiosk-card pk-list"><ul>${rows}</ul></section>` : `<section class="kiosk-card"><p class="pk-empty">${esc(t('empty'))}</p></section>`)
            + `<p class="kiosk-muted pk-note">${esc(t('note', {n: T.with_norm, fixed: fmt(s.average_norm.fixed), piece: String(s.average_norm.per_piece).replace('.', ',')}))}</p>`;
        el.querySelectorAll('[data-date]').forEach(b => b.addEventListener('click', () => { date = b.dataset.date; people = null; load(); }));
        el.querySelectorAll('[data-people]').forEach(b => b.addEventListener('click', () => { people = Math.max(1, Math.min(20, people + Number(b.dataset.people))); render(); }));
        el.querySelectorAll('[data-product]').forEach(row => {
            const item = s.items.find(i => i.product_id === Number(row.dataset.product));
            row.querySelectorAll('[data-step]').forEach(b => b.addEventListener('click', () => setPacked(item, Math.max(0, item.packed + Number(b.dataset.step)))));
            row.querySelectorAll('[data-set]').forEach(b => b.addEventListener('click', () => setPacked(item, Number(b.dataset.set))));
        });
    }

    async function setPacked(item, packed) {
        if (busy) return;
        busy = true;
        el.querySelectorAll(`[data-product="${item.product_id}"] button`).forEach(b => { b.disabled = true; });
        try {
            data = await api('PUT', `/kiosk/packing/${item.product_id}`, {date, packed});
            render();
        } catch (err) {
            if (err.code !== 'DEVICE_UNAUTHENTICATED') { toast(err.message, true); render(); }
        } finally { busy = false; }
    }

    el.addEventListener('click', async (e) => {
        if (e.target.closest('[data-lang]')) {
            lang = lang === 'pl' ? 'fr' : 'pl';
            store.set(LANG_KEY, lang);
            await loadLabels();
            data ? render() : load();
        }
        if (e.target.closest('[data-refresh]')) load();
    });
    window.setInterval(() => { if (data && !busy && document.visibilityState === 'visible' && navigator.onLine) load(); }, 30000);

    loadLabels().then(load);
})();
