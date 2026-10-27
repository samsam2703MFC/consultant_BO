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
    // Tablette générique (atelier sans groupe, 27/10/2026) : le groupe touché à l'accueil, gardé sur l'appareil.
    const GROUP_KEY = 'tfb.kiosk.group';
    const store = {
        get(k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
        set(k, v) { try { v === null ? window.localStorage.removeItem(k) : window.localStorage.setItem(k, v); } catch (e) { /* stockage indisponible */ } },
    };
    let lang = store.get(LANG_KEY) === 'fr' ? 'fr' : 'pl';
    let labels = {};
    let device = store.get(DEVICE_KEY);
    // Appairage : jeton local + cookie HttpOnly posé par le serveur (renouvelé à chaque appel). Si le navigateur vide
    // le stockage local, le cookie suffit : la tablette reste appairée ; le jeton n'est jamais effacé par le client.
    let paired = !!device;
    let session = null; // {token, name, id, exp}
    // Équipe dans l'adresse (27/10/2026) : /tablet/production?team=3 (aussi ?team-3) ; sans elle, le groupe de la tablette.
    const urlTeam = () => {
        const q = new URLSearchParams(location.search);
        const v = Number(q.get('team')) || Number(([...q.keys()].find(k => /^team-\d+$/.test(k)) || '').slice(5));
        return v > 0 ? v : null;
    };
    let group = urlTeam() || Number(store.get(GROUP_KEY)) || null; // groupe choisi : en-tête X-Kiosk-Group
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
        // Une tablette de groupe ignore cet en-tête ; une tablette générique y lit le groupe touché à l'accueil.
        if (group) headers['X-Kiosk-Group'] = String(group);
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
        err.fields = (json.errors || []).map(x => x.field);
        if (['DEVICE_UNAUTHENTICATED', 'DEVICE_DISABLED'].includes(err.code)) {
            if (err.code === 'DEVICE_UNAUTHENTICATED') paired = false;
            session = null;
            renderPairing(err.message);
        } else if (['EMPLOYEE_SESSION_EXPIRED', 'EMPLOYEE_UNAUTHENTICATED'].includes(err.code) && withEmployee) {
            logout(err.message);
        }
        throw err;
    }

    // Ikony Lucide (lucide.dev, licencja ISC) wbudowane w skrypt : linia, bez emoji, działają offline.
    const ICONS = {
        'moon': '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z" />',
        'trophy': '<path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6" /><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18" /><path d="M4 22h16" /><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22" /><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22" /><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z" />',
        'users': '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M22 21v-2a4 4 0 0 0-3-3.87" /><path d="M16 3.13a4 4 0 0 1 0 7.75" />',
        'list-ordered': '<path d="M10 12h11" /><path d="M10 18h11" /><path d="M10 6h11" /><path d="M4 10h2" /><path d="M4 6h1v4" /><path d="M6 18H4c0-1 2-2 2-3s-1-1.5-2-1" />',
        'clock': '<circle cx="12" cy="12" r="10" /><polyline points="12 6 12 12 16 14" />',
        'package': '<path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z" /><path d="M12 22V12" /><path d="m3.3 7 7.703 4.734a2 2 0 0 0 1.994 0L20.7 7" /><path d="m7.5 4.27 9 5.15" />',
        'shield-check': '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z" /><path d="m9 12 2 2 4-4" />',
        'book-open': '<path d="M12 7v14" /><path d="M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z" />',
        'bot': '<path d="M12 8V4H8" /><rect width="16" height="12" x="4" y="8" rx="2" /><path d="M2 14h2" /><path d="M20 14h2" /><path d="M15 13v2" /><path d="M9 13v2" />',
        'flask-conical': '<path d="M10 2v7.527a2 2 0 0 1-.211.896L4.72 20.55a1 1 0 0 0 .9 1.45h12.76a1 1 0 0 0 .9-1.45l-5.069-10.127A2 2 0 0 1 14 9.527V2" /><path d="M8.5 2h7" /><path d="M7 16h10" />',
        'log-out': '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" /><polyline points="16 17 21 12 16 7" /><line x1="21" x2="9" y1="12" y2="12" />',
        'log-in': '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4" /><polyline points="10 17 15 12 10 7" /><line x1="15" x2="3" y1="12" y2="12" />',
        'pen-line': '<path d="M12 20h9" /><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.854z" />',
        'id-card': '<path d="M16 10h2" /><path d="M16 14h2" /><path d="M6.17 15a3 3 0 0 1 5.66 0" /><circle cx="9" cy="11" r="2" /><rect x="2" y="5" width="20" height="14" rx="2" />',
        'calendar-days': '<path d="M8 2v4" /><path d="M16 2v4" /><rect width="18" height="18" x="3" y="4" rx="2" /><path d="M3 10h18" /><path d="M8 14h.01" /><path d="M12 14h.01" /><path d="M16 14h.01" /><path d="M8 18h.01" /><path d="M12 18h.01" /><path d="M16 18h.01" />',
        'truck': '<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2" /><path d="M15 18H9" /><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14" /><circle cx="17" cy="18" r="2" /><circle cx="7" cy="18" r="2" />',
        'delete': '<path d="M10 5a2 2 0 0 0-1.344.519l-6.328 5.74a1 1 0 0 0 0 1.481l6.328 5.741A2 2 0 0 0 10 19h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2z" /><path d="m12 9 6 6" /><path d="m18 9-6 6" />',
        'camera': '<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z" /><circle cx="12" cy="13" r="3" />',
        'scan-line': '<path d="M3 7V5a2 2 0 0 1 2-2h2" /><path d="M17 3h2a2 2 0 0 1 2 2v2" /><path d="M21 17v2a2 2 0 0 1-2 2h-2" /><path d="M7 21H5a2 2 0 0 1-2-2v-2" /><path d="M7 12h10" />',
        'clapperboard': '<path d="M20.2 6 3 11l-.9-2.4c-.3-1.1.3-2.2 1.3-2.5l13.5-4c1.1-.3 2.2.3 2.5 1.3Z" /><path d="m6.2 5.3 3.1 3.9" /><path d="m12.4 3.4 3.1 4" /><path d="M3 11h18v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z" />',
        'notebook-pen': '<path d="M13.4 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-7.4" /><path d="M2 6h4" /><path d="M2 10h4" /><path d="M2 14h4" /><path d="M2 18h4" /><path d="M21.378 5.626a1 1 0 1 0-3.004-3.004l-5.01 5.012a2 2 0 0 0-.506.854l-.837 2.87a.5.5 0 0 0 .62.62l2.87-.837a2 2 0 0 0 .854-.506z" />',
        'search': '<circle cx="11" cy="11" r="8" /><path d="m21 21-4.3-4.3" />',
        'warehouse': '<path d="M22 8.35V20a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8.35A2 2 0 0 1 3.26 6.5l8-3.2a2 2 0 0 1 1.48 0l8 3.2A2 2 0 0 1 22 8.35Z" /><path d="M6 18h12" /><path d="M6 14h12" /><rect width="12" height="12" x="6" y="10" />',
        'clipboard-check': '<rect width="8" height="4" x="8" y="2" rx="1" ry="1" /><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2" /><path d="m9 14 2 2 4-4" />',
        'layout-grid': '<rect width="7" height="7" x="3" y="3" rx="1" /><rect width="7" height="7" x="14" y="3" rx="1" /><rect width="7" height="7" x="14" y="14" rx="1" /><rect width="7" height="7" x="3" y="14" rx="1" />',
        'chevron-right': '<path d="m9 18 6-6-6-6" />',
        'plus': '<path d="M5 12h14" /><path d="M12 5v14" />',
        'minus': '<path d="M5 12h14" />',
        'trash-2': '<path d="M3 6h18" /><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6" /><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2" /><line x1="10" x2="10" y1="11" y2="17" /><line x1="14" x2="14" y1="11" y2="17" />',
        'flame': '<path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z" />',
        'package-x': '<path d="M21 10V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l2-1.14" /><path d="m7.5 4.27 9 5.15" /><polyline points="3.29 7 12 12 20.71 7" /><line x1="12" x2="12" y1="22" y2="12" /><path d="m17 13 5 5m-5 0 5-5" />',
        'pencil': '<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z" /><path d="m15 5 4 4" />',
        'wrench': '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z" />',
        'settings-2': '<path d="M20 7h-9" /><path d="M14 17H5" /><circle cx="17" cy="17" r="3" /><circle cx="7" cy="7" r="3" />',
        'hourglass': '<path d="M5 22h14" /><path d="M5 2h14" /><path d="M17 22v-4.172a2 2 0 0 0-.586-1.414L12 12l-4.414 4.414A2 2 0 0 0 7 17.828V22" /><path d="M7 2v4.172a2 2 0 0 0 .586 1.414L12 12l4.414-4.414A2 2 0 0 0 17 6.172V2" />',
        'spray-can': '<path d="M3 3h.01" /><path d="M7 5h.01" /><path d="M11 7h.01" /><path d="M3 7h.01" /><path d="M7 9h.01" /><path d="M3 11h.01" /><rect width="4" height="4" x="15" y="5" /><path d="m19 9 2 2v10c0 .6-.4 1-1 1h-6c-.6 0-1-.4-1-1V11l2-2" /><path d="m13 14 8-2" /><path d="m13 19 8-2" />',
        'coffee': '<path d="M10 2v2" /><path d="M14 2v2" /><path d="M16 8a1 1 0 0 1 1 1v8a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4V9a1 1 0 0 1 1-1h14a4 4 0 1 1 0 8h-1" /><path d="M6 2v2" />',
        'pause': '<rect x="14" y="4" width="4" height="16" rx="1" /><rect x="6" y="4" width="4" height="16" rx="1" />',
        'square': '<rect width="18" height="18" x="3" y="3" rx="2" />',
        'play': '<polygon points="6 3 20 12 6 21 6 3" />',
        'triangle-alert': '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3" /><path d="M12 9v4" /><path d="M12 17h.01" />',
        'check': '<path d="M20 6 9 17l-5-5" />',
        'x': '<path d="M18 6 6 18" /><path d="m6 6 12 12" />',
        'circle-check': '<circle cx="12" cy="12" r="10" /><path d="m9 12 2 2 4-4" />',
        'circle-x': '<circle cx="12" cy="12" r="10" /><path d="m15 9-6 6" /><path d="m9 9 6 6" />',
        'chevron-left': '<path d="m15 18-6-6 6-6" />',
        'image': '<rect width="18" height="18" x="3" y="3" rx="2" ry="2" /><circle cx="9" cy="9" r="2" /><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21" />',
        'smartphone': '<rect width="14" height="20" x="5" y="2" rx="2" ry="2" /><path d="M12 18h.01" />',
        'nfc': '<path d="M6 8.32a7.43 7.43 0 0 1 0 7.36" /><path d="M9.46 6.21a11.76 11.76 0 0 1 0 11.58" /><path d="M12.91 4.1a15.91 15.91 0 0 1 .01 15.8" /><path d="M16.37 2a20.16 20.16 0 0 1 0 20" />',
    };
    const icon = (name, cls) => `<svg class="k-ico ${cls || ''}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ICONS[name] || ''}</svg>`;

    // Avatar : initiales et ton de la maison (rubis, abricot, ambre, encre, beige) stable par nom.
    const initials = (name) => String(name || '?').trim().split(/\s+/).slice(0, 2).map(w => w.charAt(0)).join('').toUpperCase() || '?';
    const hue = (name) => [...String(name || '')].reduce((h, c) => (h * 31 + c.charCodeAt(0)) % 360, 7);
    const avatar = (name, cls) => `<span class="kiosk-avatar is-tone-${hue(name) % 5} ${cls || ''}" aria-hidden="true">${esc(initials(name))}</span>`;

    // En-tête : retour (lead) éventuel, marque et date, heure, langue ; personne connectée et sortie.
    // nav = menu en bas aussi sur un écran de détail (partie, recette, nouveau produit).
    function header(lead, nav) {
        return `<header class="kiosk-head">${lead || ''}<div class="kiosk-brand"><span class="kiosk-logo" aria-hidden="true">LA</span><div><h1>${esc(t('title'))}</h1>
                <p class="kiosk-muted">${esc(new Date().toLocaleDateString(lang === 'fr' ? 'fr-FR' : 'pl-PL', {weekday: 'long', day: 'numeric', month: 'long'}))}</p></div></div>
            <div class="kiosk-head-tools"><span class="kiosk-clock" data-clock>${new Date().toTimeString().slice(0, 5)}</span>
                <button type="button" class="kiosk-chip" data-lang><span class="kiosk-lang-long">${esc(t('lang_switch'))}</span><span class="kiosk-lang-short" aria-hidden="true">${lang === 'pl' ? 'FR' : 'PL'}</span></button>
                ${session ? `<button type="button" class="kiosk-btn is-small kiosk-icon-btn" data-switch aria-label="${esc(t('switch_person'))}">${icon('users')}<span class="kiosk-btn-label">${esc(t('switch_person'))}</span></button>
                <span class="kiosk-who">${avatar(session.name)}<span class="kiosk-who-name">${esc(session.name)}</span></span>
                <button type="button" class="kiosk-btn is-small kiosk-icon-btn" data-logout aria-label="${esc(t('logout'))}">${icon('log-out')}<span class="kiosk-btn-label">${esc(t('logout'))}</span></button>` : ''}</div></header>`
            + (nav && session ? tabs(session.tab || 'clock') : '');
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
                paired = true;
                deviceInfo = null;
                store.set(DEVICE_KEY, device);
                persist();
                if (r.device.mode === 'driver' || r.device.mode === 'office') { location.replace(root + '/' + r.device.mode); return; }
                toast(t('paired', {name: r.device.name}));
                location.hash === '#week' ? renderWeek(null, true) : renderHome();
            } catch (err) { el.querySelector('[data-error]').textContent = err.message; }
        });
        el.querySelector('[name=code]').focus();
    }

    // --- Accueil -------------------------------------------------------------------------------------------
    // Façon iPad : l'heure en grand sur un fond doux, des widgets (équipe en icônes rondes avec anneau vert pour
    // les présents, aujourd'hui, nouveaux produits du groupe, QR du téléphone, livraisons) et un dock (badge, NFC,
    // semaine). Tablette sans groupe (bureau) : le QR en grand et les noms. Un nouveau produit touché : la personne
    // choisit son nom, tape son PIN et arrive directement sur la fiche du produit.
    let deviceInfo = null;
    let groupBonus = false; // onglet « Premia » du groupe de la tablette (ERP › Zespół › Premie zespołów)
    let afterLogin = null; // après le PIN : id produit (widget « Nouveaux produits ») ou 'stock' (widget « Magazyn grupy »)
    async function renderHome() {
        if (!paired) return renderPairing();
        stopWeek();
        afterLogin = null;
        // Rodzaj tabletu odczytany przy każdym powrocie : zmiana w ERP (np. tablet grupy → biurowy) działa bez nowego parowania.
        try { deviceInfo = await api('GET', '/kiosk/device'); } catch (err) { if (deviceInfo === null) deviceInfo = {group_id: null}; }
        // Tablet kierowcy i tablet biurowy : własna strona (trasa dnia ; dział → nazwisko → osobisty kod QR).
        if (deviceInfo.mode === 'driver' || deviceInfo.mode === 'office') { location.replace(root + '/tablet/' + deviceInfo.mode); return; }
        store.set('tfb.kiosk.home', null); // dawne przekierowanie na ekran biurowy : adres mówi teraz, co pokazać
        // Tablette générique : groupe → personne → PIN ; tablette de groupe : son groupe, ou l'équipe de l'adresse (?team=N).
        const generic = !!deviceInfo.generic;
        const own = deviceInfo.home_group_id || null;
        if (own && !urlTeam()) group = null;
        let groups = [];
        try { groups = await api('GET', '/kiosk/groups'); } catch (err) { groups = []; }
        if (!el.isConnected) return;
        if (group && groups.length && !groups.some(g => g.id === group)) setGroup(null);
        groupBonus = groups.some(g => g.id === deviceInfo.group_id && g.bonus);
        if (generic && !group) return renderGroups(groups);
        const office = !generic && !deviceInfo.group_id;
        const now = new Date();
        const widgetHead = (ic, cls, title, extra) => `<header class="kiosk-widget-head"><span class="kiosk-widget-icon ${cls}">${icon(ic)}</span><h2>${title}</h2>${extra || ''}</header>`;
        const peopleWidget = `<section class="kiosk-widget is-team" data-team-widget>${widgetHead('users', 'is-soft', esc(t(office ? 'office_people' : 'home_people_title')), '<span class="kiosk-widget-meta" data-present></span>')}
                <div class="kiosk-apps" data-people><p class="kiosk-muted">${esc(t('loading'))}</p></div>
                <p class="kiosk-widget-hint" data-team-hint>${esc(t('home_team_hint'))}</p></section>`;
        const qrWidget = `<section class="kiosk-widget is-qr" data-qr-card hidden><canvas data-qr width="220" height="220" role="img" aria-label="${esc(t('qr_title'))}"></canvas>
                <div class="kiosk-qr-text"><h2>${esc(t(office ? 'office_title' : 'home_qr_title'))}</h2>
                ${office ? `<ol class="kiosk-steps"><li>${esc(t('office_step1'))}</li><li>${esc(t('office_step2'))}</li><li>${esc(t('office_step3'))}</li></ol>` : `<p class="kiosk-muted">${esc(t('home_qr_help'))}</p>`}</div></section>`;
        const dockApp = (attr, ic, cls, label) => `<button type="button" class="kiosk-dock-app" ${attr}><span class="kiosk-dock-icon ${cls}">${icon(ic)}</span><span class="kiosk-dock-label">${esc(label)}</span></button>`;
        el.innerHTML = `<div class="kiosk-ipad ${office ? 'is-office' : ''}">
            <div class="kiosk-ipad-bar"><span class="kiosk-logo" aria-hidden="true">LA</span><span class="kiosk-ipad-name" data-team-name>${esc(t('title'))}</span>
                <button type="button" class="kiosk-chip is-glass" data-lang><span class="kiosk-lang-long">${esc(t('lang_switch'))}</span><span class="kiosk-lang-short" aria-hidden="true">${lang === 'pl' ? 'FR' : 'PL'}</span></button></div>
            <section class="kiosk-ipad-hero"><div class="kiosk-ipad-time" data-clock>${now.toTimeString().slice(0, 5)}</div>
                <div class="kiosk-ipad-date">${esc(now.toLocaleDateString(lang === 'fr' ? 'fr-FR' : 'pl-PL', {weekday: 'long', day: 'numeric', month: 'long'}))}</div>
                <p class="kiosk-ipad-greet" data-hero-title>${esc(t(office ? 'office_hero' : 'home_tap_title'))}</p></section>
            <div class="kiosk-widgets">${office ? qrWidget + peopleWidget : `${peopleWidget}
                <section class="kiosk-widget is-today" data-today>${widgetHead('calendar-days', 'is-abricot', esc(t('home_today_title')), `<button type="button" class="kiosk-widget-link" data-week-open>${esc(t('home_week_all'))} ›</button>`)}
                    <div class="kiosk-widget-stats" data-stats></div><div data-today-list><p class="kiosk-muted">${esc(t('loading'))}</p></div></section>
                <section class="kiosk-widget is-stock" data-stock-widget hidden>${widgetHead('warehouse', 'is-soft', esc(t('kv_stock')), `<button type="button" class="kiosk-widget-link" data-stock-open>${esc(t('home_stock_all'))} ›</button>`)}
                    <div class="kiosk-widget-stats" data-stock-stats></div><div data-stock-list></div></section>
                <section class="kiosk-widget is-dev" data-dev-widget hidden>${widgetHead('flask-conical', 'is-ruby', esc(t('home_dev_title')), '<span class="kiosk-widget-meta" data-dev-count></span>')}
                    <div class="kiosk-dev-minis" data-dev-list></div></section>
                <section class="kiosk-widget is-deliveries" data-deliveries hidden>${widgetHead('truck', 'is-soft', esc(t('home_deliveries_title')))}<div data-delivery-list></div></section>`}</div>
            ${office ? `<nav class="kiosk-dock" aria-label="${esc(t('home_dock'))}">
                ${dockApp('data-dock-badge', 'id-card', 'is-ruby', t('home_dock_badge'))}
                ${'NDEFReader' in window ? dockApp('data-nfc', 'nfc', 'is-ink', t('nfc')) : ''}
                ${dockApp('data-dock-qr', 'smartphone', 'is-soft', t('home_dock_phone'))}
            </nav>` : homeMenu()}
            <input class="kiosk-badge-input" data-badge autocomplete="off" aria-label="${esc(t('badge'))}"></div>`;
        const badge = el.querySelector('[data-badge]');
        const focusBadge = () => { if (document.activeElement !== badge && !el.querySelector('input:not([data-badge])')) badge.focus({preventScroll: true}); };
        focusBadge();
        badge.addEventListener('keydown', (e) => { if (e.key === 'Enter' && badge.value.trim()) { e.preventDefault(); const v = badge.value.trim(); badge.value = ''; renderPin({badge: v}); } });
        el.addEventListener('click', focusBadge, {once: true});
        el.querySelector('[data-dock-badge]')?.addEventListener('click', () => { toast(t('badge_hint')); badge.focus({preventScroll: true}); });
        el.querySelector('[data-dock-qr]')?.addEventListener('click', () => {
            const card = el.querySelector('[data-qr-card]');
            if (card && !card.hidden) { card.scrollIntoView({behavior: 'smooth', block: 'center'}); card.classList.remove('is-flash'); void card.offsetWidth; card.classList.add('is-flash'); }
            else toast(t('home_qr_help'));
        });
        const nfc = el.querySelector('[data-nfc]');
        if (nfc) nfc.addEventListener('click', async () => {
            try {
                const reader = new window.NDEFReader();
                await reader.scan();
                toast(t('nfc_ready'));
                reader.addEventListener('reading', (ev) => { if (!session) renderPin({badge: ev.serialNumber}); }, {once: true});
            } catch (e) { toast(t('nfc_error'), true); }
        });
        el.querySelectorAll('[data-week-open]').forEach(b => b.addEventListener('click', () => renderWeek(null, false)));
        el.querySelectorAll('[data-group]').forEach(b => b.addEventListener('click', () => { setGroup(Number(b.dataset.group)); renderHome(); }));
        if (office) drawQr();
        const [people, w, dev, stock] = await Promise.all([
            api('GET', '/kiosk/people').catch((err) => { if (!err.code) toast(err.message, true); return null; }),
            office ? Promise.resolve(null) : api('GET', '/kiosk/week').catch(() => null),
            office ? Promise.resolve(null) : api('GET', '/kiosk/development/summary').catch(() => null),
            office ? Promise.resolve(null) : api('GET', '/kiosk/stock').catch(() => null),
        ]);
        const box = el.querySelector('[data-people]');
        if (!box) return;
        const list = people || [];
        const present = list.filter(p => p.clocked_in);
        // Tablette de production (27/10/2026) : l'entrée se fait au bureau ; ici seulement les présents du groupe,
        // un toucher → le menu (sans PIN). « Inna osoba (PIN) » : tout le groupe, avec PIN (responsable, oubli d'entrée).
        const personBtn = (p, withPin) => `<button type="button" class="kiosk-app-person ${p.clocked_in ? 'is-in' : ''} ${p.has_pin || !withPin ? '' : 'is-nopin'}" data-person="${p.id}" data-name="${esc(p.name)}" data-pin="${withPin ? 1 : ''}">
                ${avatar(p.name, 'is-app')}<span class="kiosk-app-name">${esc(p.name)}</span>
                <small>${esc(p.clocked_in ? t('home_on_shift') : (p.has_pin ? t('absent') : t('home_no_pin_short')))}</small></button>`;
        const drawPeople = (all) => {
            const shown = office || all ? list : present;
            box.innerHTML = (shown.length ? shown.map(p => personBtn(p, office || all)).join('') : `<p class="kiosk-widget-empty">${esc(t(list.length ? 'home_nobody_in' : 'nobody'))}</p>`)
                + (office || all || !list.length ? '' : `<button type="button" class="kiosk-link kiosk-other-person" data-other-person>${esc(t('home_other_person'))}</button>`);
        };
        drawPeople(false);
        homePresent = present;
        box.addEventListener('click', (e) => {
            if (e.target.closest('[data-other-person]')) { drawPeople(true); el.querySelector('[data-team-hint]').textContent = t('home_team_hint'); return; }
            const b = e.target.closest('[data-person]');
            if (!b) return;
            if (b.dataset.pin) renderPin({employee_id: Number(b.dataset.person), name: b.dataset.name});
            else tapLogin({employee_id: Number(b.dataset.person), name: b.dataset.name});
        });
        el.querySelector('[data-present]').textContent = t('home_present_of', {n: present.length, total: list.length});
        if (office) return;
        el.querySelector('[data-team-hint]').textContent = t(present.length ? 'home_tap_hint' : 'home_tap_office');
        const team = w && w.teams && w.teams[0];
        const day = team ? (team.days.find(d => d.today) || null) : null;
        const blocks = day ? day.blocks : [];
        const deliveries = day ? day.deliveries : [];
        const hours = Math.round(blocks.reduce((sum, b) => sum + (b.hours || 0), 0) * 10) / 10;
        const stat = (value, label, cls) => `<div class="kiosk-mini-stat ${cls || ''}"><strong>${esc(value)}</strong><span>${esc(label)}</span></div>`;
        el.querySelector('[data-stats]').innerHTML = stat(String(blocks.length), t('home_products_today')) + stat(fmt(hours) + ' h', t('home_hours'))
            + stat(String(deliveries.length), t('home_deliveries'), deliveries.length ? 'is-info' : '');
        if (team && team.name) {
            el.querySelector('[data-team-name]').textContent = t('home_team', {team: team.name});
            el.querySelector('[data-hero-title]').textContent = t('home_greet_tap', {team: team.name});
        }
        const todayBox = el.querySelector('[data-today-list]');
        const shown = blocks.slice(0, 5);
        const timed = blocks.some(b => b.time);
        todayBox.innerHTML = blocks.length ? `<ul class="kiosk-today-list ${timed ? '' : 'is-untimed'}">${shown.map(b => `<li>${timed ? `<span class="kiosk-today-time">${esc(b.time || '')}</span>` : ''}
                <span class="kiosk-today-name">${esc(b.name)}</span><span class="kiosk-today-qty">${esc(fmt(b.quantity / (b.factor || 1)))} ${esc(b.unit)}${b.cartons ? ` · ${esc(fmt(b.cartons))} ${esc(t('week_cartons'))}` : ''}</span></li>`).join('')}</ul>
                ${blocks.length > shown.length ? `<p class="kiosk-muted">${esc(t('home_more', {n: blocks.length - shown.length}))}</p>` : ''}`
            : `<p class="kiosk-widget-empty">${esc(t('home_today_empty'))}</p>`;
        if (deliveries.length) {
            el.querySelector('[data-deliveries]').hidden = false;
            el.querySelector('[data-delivery-list]').innerHTML = deliveries.map(d => `<div class="kiosk-delivery"><strong>${esc(d.supplier || '—')}</strong>
                <span class="kiosk-muted">${esc(d.materials.slice(0, 4).join(', '))}${d.materials.length > 4 ? '…' : ''}</span></div>`).join('');
        }
        // Stock du groupe (27/10/2026) : sous le minimum d'abord ; « Tout le stock » → nom + PIN → onglet Magazyn grupy.
        if (stock && stock.items && stock.items.length) {
            const T = stock.totals;
            el.querySelector('[data-stock-widget]').hidden = false;
            el.querySelector('[data-stock-stats]').innerHTML = stat(String(T.below_min), t('kv_tile_below'), T.below_min ? 'is-danger' : '')
                + stat(fmt(T.allowed_cartons) + ' ' + t('prod_cartons_short'), t('kv_tile_allowed')) + stat(String(T.products), t('kv_tile_products'));
            el.querySelector('[data-stock-list]').innerHTML = `<ul class="kiosk-today-list is-untimed">${stock.items.slice(0, 4).map(r => `<li class="${r.below_min ? 'is-low' : ''}">
                <span class="kiosk-today-name">${esc(r.name)}</span><span class="kiosk-today-qty">${esc(r.min_cartons === null ? t('home_stock_line_nomin', {stock: fmt(r.stock_cartons)}) : t('home_stock_line', {stock: fmt(r.stock_cartons), min: fmt(r.min_cartons)}))}</span></li>`).join('')}</ul>`;
            el.querySelector('[data-stock-open]').addEventListener('click', () => {
                afterLogin = 'stock';
                if (homePresent.length === 1) { tapLogin({employee_id: homePresent[0].id, name: homePresent[0].name}); return; }
                const teamWidget = el.querySelector('[data-team-widget]');
                teamWidget.classList.remove('is-pick'); void teamWidget.offsetWidth; teamWidget.classList.add('is-pick');
                el.querySelector('[data-team-hint]').textContent = t('home_stock_who');
                teamWidget.scrollIntoView({behavior: 'smooth', block: 'nearest'});
            });
        }
        // Nouveaux produits du groupe : étape (anneau de progression) et prochaine action ; toucher → nom + PIN → fiche.
        const products = (dev && dev.products) || [];
        if (products.length) {
            el.querySelector('[data-dev-widget]').hidden = false;
            el.querySelector('[data-dev-count]').textContent = String(products.length);
            el.querySelector('[data-dev-list]').innerHTML = products.slice(0, 4).map(p => `<button type="button" class="kiosk-dev-mini" data-home-dev="${p.id}">
                    <span class="kiosk-ring" style="--p:${p.step ? Math.round(p.step / p.steps * 100) : 0}"><span>${p.step || '–'}/${p.steps}</span></span>
                    <span class="kiosk-dev-mini-text"><strong>${esc(p.name)}</strong><small>${esc(p.stage_label)}</small>
                    ${p.next ? `<em class="${p.next.kind === 'office' ? '' : 'is-action'}">${esc(p.next.label)}</em>` : ''}</span></button>`).join('')
                + (products.length > 4 ? `<p class="kiosk-muted">${esc(t('home_more_dev', {n: products.length - 4}))}</p>` : '');
            el.querySelector('[data-dev-list]').addEventListener('click', (e) => {
                const b = e.target.closest('[data-home-dev]');
                if (!b) return;
                afterLogin = Number(b.dataset.homeDev);
                el.querySelectorAll('[data-home-dev]').forEach(x => x.classList.toggle('is-picked', x === b));
                const teamWidget = el.querySelector('[data-team-widget]');
                teamWidget.classList.remove('is-pick'); void teamWidget.offsetWidth; teamWidget.classList.add('is-pick');
                el.querySelector('[data-team-hint]').textContent = t('home_dev_who', {name: b.querySelector('strong').textContent});
                teamWidget.scrollIntoView({behavior: 'smooth', block: 'nearest'});
            });
        }
    }

    function setGroup(id) {
        group = id || null;
        store.set(GROUP_KEY, group ? String(group) : null);
        // L'adresse suit l'équipe touchée : /tablet/production?team=N (lien à garder ou à installer).
        try {
            const u = new URL(location.href);
            [...u.searchParams.keys()].filter(k => k === 'team' || /^team-\d+$/.test(k)).forEach(k => u.searchParams.delete(k));
            if (group) u.searchParams.set('team', String(group));
            if (u.pathname.endsWith('/kiosk')) u.pathname = root + '/tablet/production';
            history.replaceState(null, '', u.pathname + u.search + u.hash);
        } catch (e) { /* adresse inchangée */ }
    }

    // Tablette générique : les groupes de production en grand ; un toucher → les personnes du groupe → PIN.
    function renderGroups(groups) {
        el.innerHTML = header() + `<section class="kiosk-card kiosk-groups"><h2>${esc(t('groups_title'))}</h2><p class="kiosk-muted">${esc(t('groups_help'))}</p>
            <div class="kiosk-group-grid">${groups.length ? groups.map(g => `<button type="button" class="kiosk-group-tile" data-group="${g.id}" style="--g:${/^#[0-9a-f]{6}$/i.test(g.color || '') ? g.color : '#8D1D2C'}">
                <span class="kiosk-group-name">${esc(g.name)}</span><small>${esc(t('group_present', {n: g.present, total: g.people}))}</small></button>`).join('')
                : `<p class="kiosk-muted">${esc(t('groups_none'))}</p>`}</div></section>`;
        el.querySelectorAll('[data-group]').forEach(b => b.addEventListener('click', () => { setGroup(Number(b.dataset.group)); renderHome(); }));
    }

    // Changer de personne sans quitter l'écran (27/10/2026) : les présents du groupe, un toucher → PIN → même onglet.
    async function renderSwitch() {
        const back = session ? session.tab || 'production' : 'production';
        let people = [];
        try { people = await api('GET', '/kiosk/people', undefined, true); } catch (err) { if (!err.code) toast(err.message, true); }
        const others = people.filter(p => p.clocked_in && (!session || p.id !== session.id));
        el.innerHTML = header() + `<section class="kiosk-card kiosk-switch"><h2>${esc(t('switch_title'))}</h2><p class="kiosk-muted">${esc(t('switch_help'))}</p>
            <div class="kiosk-apps">${others.length ? others.map(p => `<button type="button" class="kiosk-app-person is-in" data-person="${p.id}" data-name="${esc(p.name)}">
                ${avatar(p.name, 'is-app')}<span class="kiosk-app-name">${esc(p.name)}</span><small>${esc(t('home_on_shift'))}</small></button>`).join('')
                : `<p class="kiosk-muted">${esc(t('switch_none'))}</p>`}</div>
            <button type="button" class="kiosk-link" data-switch-cancel>${esc(t('cancel'))}</button></section>`;
        el.querySelector('[data-switch-cancel]').addEventListener('click', () => openTab(back));
        el.querySelector('.kiosk-apps').addEventListener('click', (e) => { const b = e.target.closest('[data-person]'); if (b && !b.disabled) tapLogin({employee_id: Number(b.dataset.person), name: b.dataset.name, tab: back}); });
        resetIdle();
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
        const delivery = (o) => `<div class="kiosk-plan-delivery"><span>${icon('truck')}</span><div><h3>${esc(o.supplier)}${o.date ? '' : ' · ' + esc(t('week_undated'))}</h3>
            ${o.lines.map(l => `<p>${esc(l.name)} — ${esc(wq(l.quantity, l.unit, l.factor))} <span class="kiosk-muted">(${esc(t('week_delivery_' + deliveryState(l)))} · ${esc(t('week_need', {qty: wq(l.need, l.unit, l.factor)}))})</span></p>`).join('')}</div></div>`;
        const dayDeliveries = team.deliveries.filter(o => o.date === d.date);
        el.innerHTML = header(`<button type="button" class="kiosk-btn is-small kiosk-back" data-home>‹ ${esc(t('week_back'))}</button>`) + `
            <section class="kiosk-card kiosk-week" style="--team:${color}"><div class="kiosk-week-head"><div><h2>${esc(team.name || '—')}</h2>
                <p class="kiosk-muted">${esc(t('week_label', {week: w.iso_week, from: dm(w.week_start), to: dm(w.week_end)}))}</p></div>
                <div class="kiosk-row"><button type="button" class="kiosk-btn is-small" data-wk="-7" aria-label="${esc(t('week_prev'))}">‹</button>
                    ${current ? '' : `<button type="button" class="kiosk-btn is-small" data-wk="0">${esc(t('week_this'))}</button>`}
                    <button type="button" class="kiosk-btn is-small" data-wk="7" aria-label="${esc(t('week_next'))}">›</button></div></div>
                <nav class="kiosk-days" style="grid-template-columns:repeat(${team.days.length}, minmax(92px, 1fr))" aria-label="${esc(t('week_days'))}">${team.days.map(x => `<button type="button" class="kiosk-day ${x.date === d.date ? 'is-active' : ''} ${x.today ? 'is-today' : ''}" data-day="${x.date}">
                    <strong>${esc(t('weekday_' + x.weekday))} ${esc(dm(x.date))}</strong><small>${x.blocks.length ? esc(t('week_blocks', {n: x.blocks.length, hours: fmt(x.required_h)})) : '—'}${x.deliveries.length ? ' · ' + icon('truck', 'is-sm') : ''}</small></button>`).join('')}</nav></section>
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
    // Session ouverte (PIN, badge ou toucher d'un présent) : l'onglet demandé, sinon Produkcja (l'entrée se fait au bureau).
    function startSession(r, who) {
        const prev = session;
        session = {token: r.employee_token, id: r.employee.id, name: r.employee.name, exp: Date.now() + r.expires_in * 1000, group: r.group_id || null, bonus: groupBonus};
        // Badge passé avant de toucher un groupe : la tablette générique suit le groupe principal de la personne.
        if (deviceInfo && deviceInfo.generic && !group && r.group_id) setGroup(r.group_id);
        // Bascule entre présents : l'onglet où l'on encodait, avec ce que la tablette sait déjà du groupe.
        if (who.tab && prev) { session.machines = prev.machines; session.dev = prev.dev; openTab(who.tab); return; }
        loadTabExtras();
        const target = afterLogin;
        afterLogin = null;
        // Widget « Nouveaux produits » : directement la fiche du produit touché ; menu du bas : l'onglet touché.
        if (typeof target === 'number') { session.dev = 1; session.tab = 'dev'; renderDevProduct(target); return; }
        openTab(target || 'production');
    }

    // Onglets Maszyny et Nowe produkty : seulement si le groupe a des machines TRS / des produits montrés sur la tablette.
    async function loadTabExtras() {
        const s = session;
        const [machines, dev] = await Promise.all([api('GET', '/kiosk/machines', undefined, true).catch(() => null), api('GET', '/kiosk/development', undefined, true).catch(() => null)]);
        if (session !== s || !s) return;
        s.machines = machines ? machines.machines.length : 0;
        s.dev = dev ? dev.products.length : 0;
        const bar = el.querySelector('.kiosk-tabs:not(.is-home)');
        if (bar && (s.machines || s.dev)) bar.outerHTML = tabs(s.tab || 'production');
    }

    // Présent du groupe (entré au bureau) : un toucher suffit ; sinon (absent, paramètre coupé) le PIN.
    async function tapLogin(who) {
        try {
            startSession(await api('POST', '/kiosk/identify', {employee_id: who.employee_id}), who);
        } catch (err) {
            if (err.code === 'EMPLOYEE_NOT_PRESENT') toast(err.message, true);
            renderPin(who);
        }
    }

    // Menu du bas de l'accueil : le même que celui d'une personne ; toucher → « qui ? » (ou directement le seul présent).
    function homeMenu() {
        const items = [['production', 'package', 'tab_production'], ['stock', 'warehouse', 'tab_stock'], ['quality', 'shield-check', 'tab_quality'], ['recipes', 'book-open', 'tab_recipes'],
            ...(groupBonus ? [['bonus', 'trophy', 'tab_bonus']] : [])];
        return `<nav class="kiosk-tabs is-home" aria-label="${esc(t('tabs'))}">${items.map(([k, ic, label]) => `<button type="button" class="kiosk-tab" data-home-tab="${k}">
            <span class="kiosk-tab-icon">${icon(ic)}</span><span class="kiosk-tab-label">${esc(t(label))}</span><span class="kiosk-tab-short" aria-hidden="true">${esc(t(label + '_short'))}</span></button>`).join('')}
            <button type="button" class="kiosk-tab" data-week-open><span class="kiosk-tab-icon">${icon('calendar-days')}</span><span class="kiosk-tab-label">${esc(t('home_week_all'))}</span><span class="kiosk-tab-short" aria-hidden="true">${esc(t('home_week_short'))}</span></button></nav>`;
    }
    let homePresent = [];
    el.addEventListener('click', (e) => {
        const b = e.target.closest('[data-home-tab]');
        if (!b || session) return;
        afterLogin = b.dataset.homeTab;
        if (homePresent.length === 1) { tapLogin({employee_id: homePresent[0].id, name: homePresent[0].name}); return; }
        const teamWidget = el.querySelector('[data-team-widget]');
        if (!teamWidget) return;
        teamWidget.classList.remove('is-pick'); void teamWidget.offsetWidth; teamWidget.classList.add('is-pick');
        el.querySelector('[data-team-hint]').textContent = t(homePresent.length ? 'home_tab_who' : 'home_tap_office', {tab: b.querySelector('.kiosk-tab-label').textContent});
        teamWidget.scrollIntoView({behavior: 'smooth', block: 'nearest'});
    });

    function renderPin(who) {
        let pin = '';
        el.innerHTML = header() + `<section class="kiosk-card kiosk-pin">${who.name ? avatar(who.name, 'is-lg') : `<span class="kiosk-badge-icon">${icon('id-card')}</span>`}<h2>${esc(who.name || t('badge_read'))}</h2><p class="kiosk-muted">${esc(t('pin_help'))}</p>
            <div class="kiosk-dots" data-dots aria-hidden="true"></div><p class="kiosk-error" data-error></p>
            <div class="kiosk-pad">${[1, 2, 3, 4, 5, 6, 7, 8, 9].map(d => `<button type="button" class="kiosk-btn" data-digit="${d}">${d}</button>`).join('')}
                <button type="button" class="kiosk-btn" data-back aria-label="${esc(t('erase'))}">${icon('delete')}</button><button type="button" class="kiosk-btn" data-digit="0">0</button>
                <button type="button" class="kiosk-btn is-primary" data-ok>OK</button></div>
            <button type="button" class="kiosk-link" data-cancel>${esc(t('cancel'))}</button></section>`;
        const dots = () => { el.querySelector('[data-dots]').innerHTML = Array.from({length: Math.max(4, pin.length)}, (_, i) => `<span class="${i < pin.length ? 'is-on' : ''}"></span>`).join(''); };
        dots();
        const submit = async () => {
            if (pin.length < 4) return;
            const ok = el.querySelector('[data-ok]');
            ok.disabled = true;
            try {
                startSession(await api('POST', '/kiosk/identify', {...(who.badge ? {badge: who.badge} : {employee_id: who.employee_id}), pin}), who);
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
        // Bascule annulée : retour à l'onglet de la personne encore connectée.
        el.querySelector('[data-cancel]').addEventListener('click', () => (who.tab && session ? openTab(who.tab) : renderHome()));
        window.clearTimeout(idleTimer);
        idleTimer = window.setTimeout(() => (who.tab && session ? logout(t('idle_logout')) : renderHome()), 30000);
    }

    // --- Menu osoby : Czas pracy / Produkcja / Kontrola jakości / Receptury ---------------------------
    const TABS = [['clock', 'clock', 'tab_clock'], ['production', 'package', 'tab_production'], ['stock', 'warehouse', 'tab_stock'], ['quality', 'shield-check', 'tab_quality'], ['recipes', 'book-open', 'tab_recipes'], ['machines', 'bot', 'tab_machines'], ['dev', 'flask-conical', 'tab_dev'], ['bonus', 'trophy', 'tab_bonus']];
    const personHeader = () => header();
    function tabs(active) {
        if (session) session.tab = active;
        // Zakładki Maszyny (TRS) i Nowe produkty tylko, gdy grupa tabletu ma maszyny z TRS / produkty pokazane na tablecie.
        const shown = ([k]) => (k !== 'machines' || (session && session.machines > 0)) && (k !== 'dev' || (session && session.dev > 0))
            && (k !== 'clock' || !(deviceInfo && deviceInfo.mode === 'atelier')) && (k !== 'bonus' || (session && session.bonus));
        return `<nav class="kiosk-tabs" role="tablist" aria-label="${esc(t('tabs'))}">${TABS.filter(shown).map(([k, ic, label]) => `<button type="button" class="kiosk-tab ${k === active ? 'is-active' : ''}"
            role="tab" aria-selected="${k === active}" data-tab="${k}"><span class="kiosk-tab-icon">${icon(ic)}</span><span class="kiosk-tab-label">${esc(t(label))}</span><span class="kiosk-tab-short" aria-hidden="true">${esc(t(label + '_short'))}</span></button>`).join('')}</nav>`;
    }
    function openTab(k, message) {
        if (k === 'recipes') return renderRecipes(message);
        if (k === 'machines') return renderMachines(message);
        if (k === 'dev') return renderDev(message);
        if (k === 'stock') return renderStock(message);
        if (k === 'bonus') return renderBonus(message);
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
            <section class="kiosk-card kiosk-clockcard ${me.clocked_in ? 'is-in' : ''}">
                <div class="kiosk-clockcard-head">${avatar(me.employee.name, 'is-lg')}<div><h2>${esc(t('hello', {name: me.employee.name}))}</h2>
                    <p class="kiosk-status">${me.clocked_in ? esc(t('since', {time: hhmm(me.clock.clock_in), hours: fmt(me.clock.hours)})) : esc(t('not_in'))}</p></div>
                    <span class="kiosk-pill ${me.clocked_in ? 'is-in' : ''}">${esc(me.clocked_in ? t('present') : t('absent'))}</span></div>
                <button type="button" class="kiosk-btn kiosk-big ${me.clocked_in ? 'is-danger' : 'is-ok'}" data-clock-tap>
                    <span class="kiosk-big-icon">${icon(me.clocked_in ? 'log-out' : 'log-in')}</span>${esc(me.clocked_in ? t('clock_out') : t('clock_in'))}</button>
                <div class="kiosk-row"><button type="button" class="kiosk-btn is-small" data-sign-month>${icon('pen-line')} ${esc(t('sign_month'))}</button></div></section>`;
        if (message) toast(message);
        el.querySelector('[data-clock-tap]').addEventListener('click', (e) => validateClock(me, me.clocked_in ? 'out' : 'in', e.currentTarget));
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
        if (o.status === 'done') return `<span class="kiosk-muted">${icon('check', 'is-sm')} ${esc(fmt(o.qty_good ?? 0))}</span>`;
        const out = [];
        if (!mine) out.push(`<button type="button" class="kiosk-btn is-ok is-small" data-action="start" ${clockedIn ? '' : 'disabled'}>${esc(o.status === 'planned' ? t('start') : t('resume'))}</button>`);
        if (mine) out.push(`<button type="button" class="kiosk-btn is-small" data-action="pause">${esc(t('pause'))}</button>`);
        if (o.status !== 'planned') out.push(`<button type="button" class="kiosk-btn is-primary is-small" data-action="finish">${esc(t('finish'))}</button>`);
        return out.join('');
    }

    // Wejście / wyjście : ekran zatwierdzenia, gdy są pytania (ustawiane w zarządzaniu tabletami) albo podpis
    // (kiosk.signature_on_clock_in / _out) ; inaczej jedno dotknięcie.
    function validateClock(me, direction, button) {
        const questions = me.questions?.[direction] || [];
        const sign = !!me.signature?.[direction];
        if (questions.length || sign) renderQuestions(questions, direction, null, sign);
        else clockTap(button, direction, [], null);
    }

    // Ekran zatwierdzenia : dotknięcie potwierdza punkt, podpis palcem w ramce, « OK, zatwierdzam » gdy obowiązkowe
    // punkty są potwierdzone i podpis złożony — bez podpisu nie ma wejścia ani wyjścia.
    function renderQuestions(questions, direction, error, sign) {
        resetIdle();
        const yes = new Set();
        el.innerHTML = personHeader() + `<section class="kiosk-card kiosk-confirm">
                <h2>${icon(direction === 'in' ? 'log-in' : 'log-out')} ${esc(t(direction === 'in' ? 'confirm_in' : 'confirm_out'))}</h2>
                ${questions.length ? `<p class="kiosk-muted">${esc(t('confirm_help'))}</p>
                <div class="kiosk-checks">${questions.map(q => `<button type="button" class="kiosk-check" data-q="${q.id}" aria-pressed="false">
                    <span class="kiosk-check-box" aria-hidden="true"></span><span>${esc(q.text)}${q.required ? '' : `<small>${esc(t('optional'))}</small>`}</span></button>`).join('')}</div>` : ''}
                ${sign ? `<div class="kiosk-confirm-sign"><p class="kiosk-status">${esc(t(direction === 'in' ? 'sign_in_here' : 'sign_out_here'))}</p>
                    <canvas class="kiosk-sign" data-pad width="900" height="240" aria-label="${esc(t('signature'))}"></canvas>
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
            resetIdle();
            const id = Number(b.dataset.q);
            if (yes.has(id)) yes.delete(id); else yes.add(id);
            b.classList.toggle('is-on', yes.has(id));
            b.setAttribute('aria-pressed', String(yes.has(id)));
            refresh();
        });
        el.querySelector('[data-clear]')?.addEventListener('click', () => { pad.clear(); refresh(); });
        ok.addEventListener('click', () => clockTap(ok, direction, questions.map(q => ({question_id: q.id, yes: yes.has(q.id)})), pad ? pad.image() : null));
        el.querySelector('[data-back]').addEventListener('click', () => renderMe());
    }

    // Ramka podpisu palcem (biały PNG) : empty() — nic nie narysowano, image() — data URL do zapisania.
    function signaturePad(canvas, onDraw) {
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.lineWidth = 3; ctx.lineCap = 'round'; ctx.strokeStyle = '#111';
        let drawing = false;
        let strokes = 0;
        const point = (e) => { const r = canvas.getBoundingClientRect(); return [(e.clientX - r.left) * canvas.width / r.width, (e.clientY - r.top) * canvas.height / r.height]; };
        canvas.addEventListener('pointerdown', (e) => { drawing = true; strokes++; canvas.setPointerCapture(e.pointerId); const [x, y] = point(e); ctx.beginPath(); ctx.moveTo(x, y); resetIdle(); });
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
            const r = await api('POST', '/kiosk/clock', {direction, client_uuid: pending.uuid, answers, ...(signature ? {signature} : {})}, true);
            pending = null;
            // Wyjście podpisane : koniec sesji na tablecie (następna osoba).
            if (direction === 'out' && signature) return logout(t('bye', {hours: fmt(r.clock.hours)}));
            renderMe(direction === 'in' ? t('welcome') : t('bye', {hours: fmt(r.clock.hours)}));
        } catch (err) {
            button.disabled = false;
            // Pytania albo podpis zmienione w międzyczasie : aktualny ekran zatwierdzenia z komunikatem.
            if (err.code === 'CLOCK_QUESTIONS_REQUIRED' || err.fields?.includes('signature')) {
                const me = await api('GET', '/kiosk/me', undefined, true).catch(() => null);
                if (me) return renderQuestions(me.questions?.[direction] || [], direction, err.message, !!me.signature?.[direction]);
            }
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
        let day, me, w;
        try {
            [day, me, w] = await Promise.all([api('GET', '/kiosk/production', undefined, true), api('GET', '/kiosk/me', undefined, true).catch(() => null),
                api('GET', '/kiosk/week').catch(() => null)]);
        } catch (err) { if (!err.code || err.code === 'DEVICE_WITHOUT_GROUP') toast(err.message, true); return; }
        prodWeek = w && w.teams && w.teams[0] ? {today: w.today, deliveries: w.teams[0].deliveries || []} : null;
        drawProduction(day, me, message);
    }

    // Dostawy surowców zespołu : zamówienia u dostawców w tygodniu z surowcami, których zespół potrzebuje
    // (kto, kiedy, co, ile, dla jakich produktów) ; przyjęte dawniej niż dziś pominięte.
    let prodWeek = null;
    function deliveriesCard() {
        if (!prodWeek) return '';
        const today = prodWeek.today;
        const tomorrow = isoDay(today, 1);
        const state = (l) => l.received >= l.quantity - 0.0005 ? 'received' : (l.received > 0 ? 'partial' : 'expected');
        const list = prodWeek.deliveries
            .map(o => ({...o, done: o.lines.every(l => state(l) === 'received'), late: o.date !== null && o.date < today && !o.lines.every(l => state(l) === 'received')}))
            .filter(o => !(o.done && o.date !== null && o.date < today))
            .sort((a, b) => (a.date || '9999') < (b.date || '9999') ? -1 : 1);
        const when = (o) => o.date === null ? t('week_undated') : (o.date === today ? t('delivery_today') : (o.date === tomorrow ? t('delivery_tomorrow')
            : `${t('weekday_' + (((new Date(o.date + 'T12:00:00').getDay() + 6) % 7) + 1))} ${dm(o.date)}`));
        return `<section class="kiosk-card kiosk-prod-deliveries"><h2>${icon('truck')} ${esc(t('prod_deliveries_title'))}</h2>
            ${list.length ? list.map(o => `<div class="kiosk-plan-delivery ${o.late ? 'is-late' : ''} ${o.date === today ? 'is-today' : ''}"><span>${icon('truck')}</span><div>
                <h3>${esc(o.supplier || '—')} <span class="kiosk-tag ${o.late ? 'is-late' : (o.date === today ? 'is-ship' : '')}">${esc(o.late ? t('delivery_late', {date: dm(o.date)}) : when(o))}</span></h3>
                ${o.lines.map(l => `<p>${esc(l.name)} — ${esc(wq(l.quantity, l.unit, l.factor))} <span class="kiosk-muted">(${esc(t('week_delivery_' + state(l)))} · ${esc(t('week_need', {qty: wq(l.need, l.unit, l.factor)}))})</span>
                    ${l.products && l.products.length ? `<br><small class="kiosk-muted">${esc(t('delivery_for', {products: l.products.slice(0, 3).join(', ') + (l.products.length > 3 ? '…' : '')}))}</small>` : ''}</p>`).join('')}
                ${!o.done && ['sent', 'partial'].includes(o.status) ? `<button type="button" class="kiosk-btn is-primary is-small" data-receive-order="${o.order_id}">${icon('package')} ${esc(t('rx_open'))}</button>` : ''}
            </div></div>`).join('') : `<p class="kiosk-muted">${esc(t('prod_deliveries_none'))}</p>`}</section>`;
    }

    // Przyjęcie dostawy (asystent, 5 kroków) : 1 dostawa → 2 ilości (z zamówienia) → 3 partia i data ważności (wpisane,
    // zdjęcie albo skan etykiety) → 4 problem ? (niezgodność dostawcy) → 5 zatwierdzenie. Bez cen (cena z zamówienia).
    const RX_STEPS = 5;
    const rxNum = (v) => { const n = Number(String(v ?? '').replace(',', '.').replace(/\s/g, '')); return String(v ?? '').trim() !== '' && Number.isFinite(n) ? n : null; };
    const rxFmt = (v) => Number(v).toLocaleString(lang === 'fr' ? 'fr-FR' : 'pl-PL', {maximumFractionDigits: 3, useGrouping: false});
    async function renderReceive(orderId) {
        stopScan();
        longIdle = true;
        resetIdle();
        let o;
        try { o = await api('GET', `/kiosk/deliveries/${orderId}`, undefined, true); } catch (err) { toast(err.offline ? t('offline_retry') : err.message, true); return; }
        if (!o.receivable) { toast(t('rx_not_receivable'), true); return; }
        const rx = {step: 1, qty: {}, lot: {}, bb: {}, pb: {}};
        o.lines.filter(l => l.remaining > 0).forEach(l => { rx.qty[l.id] = rxFmt(l.remaining / l.factor); });
        drawReceive(o, rx);
    }

    function drawReceive(o, rx) {
        resetIdle();
        const open = o.lines.filter(l => l.remaining > 0);
        const taken = open.filter(l => (rxNum(rx.qty[l.id]) || 0) > 0);
        const step = rx.step;
        const bar = `<ol class="kiosk-eod-steps">${Array.from({length: RX_STEPS}, (_, k) => k + 1).map(n => `<li class="${n === step ? 'is-on' : (n < step ? 'is-done' : '')}"><span>${n}</span>${esc(t('rx_step_' + n))}</li>`).join('')}</ol>`;
        let body = '';
        if (step === 1) {
            body = `<p class="kiosk-status">${esc(o.supplier || '—')} · ${esc(o.code)}${o.delivery_date ? ' · ' + esc(t('rx_planned', {date: dm(o.delivery_date)})) : ''}</p>
                <p class="kiosk-muted">${esc(t('rx_check_help'))}</p>
                <ul class="kiosk-qc">${open.map(l => `<li class="kiosk-qc-row"><span><strong>${esc(l.name)}</strong></span><span>${esc(wq(l.remaining, l.unit, l.factor))}${l.received_qty > 0 ? ` <small class="kiosk-muted">(${esc(t('rx_already', {qty: wq(l.received_qty, l.unit, l.factor)}))})</small>` : ''}</span></li>`).join('')}</ul>`;
        } else if (step === 2) {
            body = `<p class="kiosk-muted">${esc(t('rx_qty_help'))}</p><div class="kiosk-rx-lines">${open.map(l => `<div class="kiosk-card kiosk-rx-line" data-rxline="${l.id}">
                <div><h3>${esc(l.name)}</h3><span class="kiosk-muted">${esc(t('rx_ordered', {qty: wq(l.remaining, l.unit, l.factor)}))}</span></div>
                <label class="kiosk-rx-qty"><input inputmode="decimal" autocomplete="off" data-rxqty value="${esc(rx.qty[l.id] ?? '')}"> <span>${esc(l.unit)}</span></label>
                <div class="kiosk-row"><button type="button" class="kiosk-btn is-small" data-rxall>${esc(t('rx_all'))}</button><button type="button" class="kiosk-btn is-small" data-rxnone>${esc(t('rx_none'))}</button></div></div>`).join('')}</div>
                <p class="kiosk-error" data-error></p>`;
        } else if (step === 3) {
            body = `<p class="kiosk-muted">${esc(t('rx_lots_help'))}</p>${taken.map(l => `<article class="kiosk-card kiosk-lot ${rx.lot[l.id] ? 'is-ok' : ''}" data-rxlot="${l.id}">
                <div class="kiosk-lot-head"><div><h3>${esc(l.name)}</h3><span class="kiosk-muted">${esc(rx.qty[l.id])} ${esc(l.unit)}</span></div></div>
                <div class="kiosk-lot-fields">
                    <label>${esc(t('lot_number'))}<input name="lot" autocomplete="off" autocapitalize="characters" maxlength="64" value="${esc(rx.lot[l.id] || '')}"></label>
                    <label>${esc(t('lot_best_before'))}<input name="bb" type="date" value="${esc(rx.bb[l.id] || '')}"></label>
                    ${canScan() ? `<button type="button" class="kiosk-btn is-small is-primary" data-scan>${icon('scan-line')} ${esc(t('lot_scan'))}</button>` : ''}
                    <label class="kiosk-btn is-small kiosk-photo">${icon('camera')} ${esc(t('lot_photo'))}<input type="file" accept="image/*" capture="environment" data-photo hidden></label></div>
                <div class="kiosk-ocr" data-ocr hidden></div></article>`).join('')}`;
        } else if (step === 4) {
            body = `<p class="kiosk-muted">${esc(t('rx_problem_help'))}</p>${open.map(l => { const pb = rx.pb[l.id] || {}; return `<div class="kiosk-card kiosk-rx-pb ${pb.on ? 'is-on' : ''}" data-rxpb="${l.id}">
                <label class="kiosk-rx-check"><input type="checkbox" data-pon ${pb.on ? 'checked' : ''}> <strong>${esc(l.name)}</strong></label>
                <div data-pfields ${pb.on ? '' : 'hidden'}><label class="is-wide">${esc(t('rx_problem_desc'))}<input data-pdesc maxlength="500" value="${esc(pb.desc || '')}" placeholder="${esc(t('rx_problem_ph'))}"></label>
                    <div class="kiosk-row">${['minor', 'major', 'critical'].map(v => `<button type="button" class="kiosk-btn is-small ${(pb.sev || 'minor') === v ? 'is-primary' : ''}" data-psev="${v}">${esc(t('rx_sev_' + v))}</button>`).join('')}
                    <label class="kiosk-rx-qty">${esc(t('rx_problem_qty'))} <input inputmode="decimal" data-pqty value="${esc(pb.qty || '')}"> <span>${esc(l.unit)}</span></label></div></div></div>`; }).join('')}
                <p class="kiosk-error" data-error></p>`;
        } else {
            const pbs = open.filter(l => rx.pb[l.id] && rx.pb[l.id].on);
            body = `<p class="kiosk-muted">${esc(t('rx_confirm_help'))}</p><table class="kiosk-table"><thead><tr><th>${esc(t('rx_col_material'))}</th><th>${esc(t('rx_col_qty'))}</th><th>${esc(t('lot_number'))}</th><th>${esc(t('lot_best_before'))}</th></tr></thead>
                <tbody>${taken.map(l => `<tr><td>${esc(l.name)}</td><td><strong>${esc(rx.qty[l.id])} ${esc(l.unit)}</strong></td><td>${rx.lot[l.id] ? esc(rx.lot[l.id]) : `<span class="kiosk-muted">${esc(t('rx_no_lot'))}</span>`}</td><td>${rx.bb[l.id] ? esc(dm(rx.bb[l.id]) + '.' + rx.bb[l.id].slice(0, 4)) : '—'}</td></tr>`).join('')}</tbody></table>
                ${pbs.length ? `<p class="kiosk-error">${icon('triangle-alert', 'is-sm')} ${esc(t('rx_nc_count', {n: pbs.length}))}</p>` : ''}
                ${taken.length < open.length ? `<p class="kiosk-muted">${esc(t('rx_partial', {n: open.length - taken.length}))}</p>` : ''}
                <p class="kiosk-error" data-error></p>`;
        }
        el.innerHTML = header() + `<section class="kiosk-card kiosk-eod kiosk-rx"><h2>${icon('truck')} ${esc(t('rx_title', {supplier: o.supplier || o.code}))}</h2>${bar}
            <h3 class="kiosk-eod-step">${esc(t('rx_step_' + step))}</h3>${body}
            <div class="kiosk-row is-end"><button type="button" class="kiosk-btn" data-rxback>${esc(step > 1 ? t('back') : t('cancel'))}</button>
                <button type="button" class="kiosk-btn is-primary" data-rxnext>${esc(step < RX_STEPS ? t('eod_next') : t('rx_save'))}</button></div></section>`;
        // Wartości z bieżącego kroku zostają przy przejściu dalej / wstecz.
        const keep = () => {
            el.querySelectorAll('[data-rxline]').forEach(c => { rx.qty[c.dataset.rxline] = c.querySelector('[data-rxqty]').value.trim(); });
            el.querySelectorAll('[data-rxlot]').forEach(c => { rx.lot[c.dataset.rxlot] = c.querySelector('[name=lot]').value.trim().replace(/\s+/g, ' ').toUpperCase(); rx.bb[c.dataset.rxlot] = c.querySelector('[name=bb]').value; });
            el.querySelectorAll('[data-rxpb]').forEach(c => {
                const pb = rx.pb[c.dataset.rxpb] || (rx.pb[c.dataset.rxpb] = {});
                pb.on = c.querySelector('[data-pon]').checked;
                pb.desc = c.querySelector('[data-pdesc]').value.trim();
                pb.qty = c.querySelector('[data-pqty]').value.trim();
            });
        };
        const error = (m) => { const e = el.querySelector('[data-error]'); if (e) e.textContent = m; else toast(m, true); };
        el.querySelector('section').addEventListener('input', resetIdle);
        el.querySelectorAll('[data-rxline]').forEach(c => {
            const l = o.lines.find(x => x.id === Number(c.dataset.rxline));
            c.querySelector('[data-rxall]').addEventListener('click', () => { c.querySelector('[data-rxqty]').value = rxFmt(l.remaining / l.factor); });
            c.querySelector('[data-rxnone]').addEventListener('click', () => { c.querySelector('[data-rxqty]').value = '0'; });
        });
        el.querySelectorAll('[data-rxlot]').forEach(card => {
            const slot = {};
            card.querySelector('[data-photo]')?.addEventListener('change', async (e) => {
                const file = e.target.files && e.target.files[0];
                e.target.value = '';
                busy = false;
                if (file) await photo(card, file, slot);
            });
            card.querySelector('.kiosk-photo')?.addEventListener('click', () => { stopScan(); busy = true; resetIdle(); });
            card.querySelector('[data-scan]')?.addEventListener('click', () => scan(card, slot));
        });
        el.querySelectorAll('[data-rxpb]').forEach(c => {
            const pb = rx.pb[c.dataset.rxpb] || (rx.pb[c.dataset.rxpb] = {});
            c.querySelector('[data-pon]').addEventListener('change', (e) => { c.querySelector('[data-pfields]').hidden = !e.target.checked; c.classList.toggle('is-on', e.target.checked); if (e.target.checked) c.querySelector('[data-pdesc]').focus(); });
            c.querySelectorAll('[data-psev]').forEach(b => b.addEventListener('click', () => { pb.sev = b.dataset.psev; c.querySelectorAll('[data-psev]').forEach(x => x.classList.toggle('is-primary', x === b)); }));
        });
        el.querySelector('[data-rxback]').addEventListener('click', () => { stopScan(); keep(); if (step > 1) { rx.step = step - 1; drawReceive(o, rx); } else renderProduction(); });
        el.querySelector('[data-rxnext]').addEventListener('click', async (e) => {
            stopScan();
            keep();
            if (step === 2) {
                const bad = open.find(l => rx.qty[l.id] !== '' && (rxNum(rx.qty[l.id]) === null || rxNum(rx.qty[l.id]) < 0));
                if (bad) return error(t('rx_qty_invalid', {name: bad.name}));
                if (!open.some(l => (rxNum(rx.qty[l.id]) || 0) > 0)) return error(t('rx_nothing'));
            }
            if (step === 4) {
                const bad = open.find(l => rx.pb[l.id] && rx.pb[l.id].on && !rx.pb[l.id].desc);
                if (bad) return error(t('rx_problem_missing', {name: bad.name}));
            }
            if (step < RX_STEPS) { rx.step = step + 1; drawReceive(o, rx); window.scrollTo(0, 0); return; }
            const body = {lines: taken.map(l => ({id: l.id, quantity: Math.round(rxNum(rx.qty[l.id]) * l.factor * 1000) / 1000, lot_number: rx.lot[l.id] || null, best_before: rx.bb[l.id] || null})),
                problems: open.filter(l => rx.pb[l.id] && rx.pb[l.id].on).map(l => { const q = rxNum(rx.pb[l.id].qty); return {line_id: l.id, description: rx.pb[l.id].desc, severity: rx.pb[l.id].sev || 'minor', quantity: q && q > 0 ? Math.round(q * l.factor * 1000) / 1000 : null}; })};
            e.target.disabled = true;
            try {
                await api('POST', `/kiosk/deliveries/${o.id}/receive`, body, true);
                renderProduction(body.problems.length ? t('rx_saved_nc', {n: body.problems.length}) : t('rx_saved', {supplier: o.supplier || o.code}));
            } catch (err) { e.target.disabled = false; error(err.offline ? t('offline_retry') : err.message); }
        });
    }

    // Accueil Produkcja : carte du plan (→ page de validation), plan du jour et pertes, raccourcis (encoder sans plan,
    // stock du groupe, liste de production), puis les lots du jour (numéros de lots matières) et les livraisons.
    function drawProduction(day, me, message) {
        const tot = day.totals || {};
        const closed = day.closed;
        const locked = !!(closed && closed.locked);
        const planned = day.items.filter(i => i.planned !== null);
        const lines = day.items.filter(i => i.line).length;
        const lost = day.items.filter(i => i.waste > 0);
        const pct = planned.length ? Math.round(planned.filter(i => i.line || i.produced + 1e-6 >= i.planned).length / planned.length * 100) : 0;
        const plannedCartons = planned.reduce((a, i) => a + (i.ppc ? i.planned / i.ppc : 0), 0);
        const head = planned.length
            ? `<section class="kiosk-card kv-plan ${locked ? 'is-closed' : ''}" data-open-validate role="button" tabindex="0">
                <span class="kv-plan-icon">${icon(locked ? 'circle-check' : 'clipboard-check')}</span>
                <div class="kv-plan-body"><h2>${esc(t(locked ? 'kv_plan_closed_title' : 'kv_plan_title'))}</h2>
                    <p class="kiosk-muted">${esc(t('kv_plan_meta', {n: planned.length, cartons: fmt(Math.round(plannedCartons)), done: lines, total: day.items.length}))}${tot.waste ? ' · ' + esc(t('day_waste', {q: fmt(tot.waste), pct: fmt(tot.waste_pct ?? 0)})) : ''}</p>
                    ${locked ? `<p class="kiosk-dayhead-state">${icon('circle-check', 'is-sm')} ${esc(t('day_closed', {time: hhmm(closed.at), name: closed.employee || ''}))}${closed.note ? ' · ' + esc(closed.note) : ''}</p>` : `<div class="kiosk-bar"><span style="width:${pct}%"></span></div>`}</div>
                <button type="button" class="kiosk-btn ${locked ? '' : 'is-primary'} kv-plan-go" data-open-validate>${esc(t(locked ? 'kv_plan_view' : 'kv_plan_go'))} ${icon('chevron-right')}</button></section>`
            : `<section class="kiosk-card kv-plan is-empty ${locked ? 'is-closed' : ''}"><span class="kv-plan-icon">${icon('layout-grid')}</span>
                <div class="kv-plan-body"><h2>${esc(t(locked ? 'kv_plan_closed_title' : 'kv_noplan_title'))}</h2><p class="kiosk-muted">${esc(t(locked ? 'kv_locked_help' : 'kv_noplan_help'))}</p></div>
                <button type="button" class="kiosk-btn ${locked ? '' : 'is-primary'} kv-plan-go" data-open-encode>${esc(t(locked ? 'kv_plan_view' : 'kv_encode'))} ${icon('chevron-right')}</button></section>`;
        const planList = planned.length ? `<section class="kiosk-card"><h2 class="kv-h">${esc(t('kv_plan_day'))}</h2><ul class="kv-list">${planned.map(i => {
            const done = i.line ? i.line.produced : i.produced;
            const cls = i.line ? 'is-ok' : (done > 0 ? 'is-warn' : '');
            return `<li><span>${i.line ? icon('circle-check', 'is-sm') + ' ' : ''}${esc(i.name)}</span><span class="kiosk-pill ${cls}">${esc(cq(done, i, true))} / ${esc(cq(i.planned, i))}</span></li>`;
        }).join('')}${day.items.filter(i => i.planned === null).map(i => `<li><span>${esc(i.name)}</span><span class="kiosk-pill is-warn">${esc(t('kv_off_plan_q', {q: cq(i.produced, i)}))}</span></li>`).join('')}</ul></section>` : '';
        const lostList = `<section class="kiosk-card"><h2 class="kv-h">${esc(t('kv_waste_today'))}</h2>${lost.length ? `<ul class="kv-list">${lost.flatMap(i => i.wastes.filter(w => !w.cancelled).map(w =>
            `<li><span>${esc(i.name)} · ${esc(t('waste_' + w.reason))}</span><span class="kiosk-pill is-danger">${esc(fmt(w.quantity))} ${esc(i.unit)}${w.photos && w.photos.length ? ' · ' + w.photos.length + ' ' + icon('camera', 'is-sm') : ''}</span></li>`)).join('')}</ul>`
            : `<p class="kiosk-muted">${esc(t('kv_waste_none'))}</p>`}<p class="kiosk-muted kv-small">${esc(t(day.waste_photo && day.waste_photo.required ? 'kv_waste_rule_photo' : 'kv_waste_rule'))}</p></section>`;
        const batches = day.items.filter(i => i.batches.length);
        el.innerHTML = personHeader() + tabs('production') + head + `
            <div class="kv-grid2">${planList || lostList}${planList ? lostList : ''}</div>
            <div class="kv-shortcuts">
                <button type="button" class="kv-shortcut" data-open-encode><span class="kv-shortcut-icon">${icon('layout-grid')}</span><strong>${esc(t('kv_encode'))}</strong><span>${esc(t('kv_encode_help'))}</span></button>
                <button type="button" class="kv-shortcut" data-open-stock="stock"><span class="kv-shortcut-icon">${icon('warehouse')}</span><strong>${esc(t('kv_stock'))}</strong><span>${esc(t('kv_stock_help'))}</span></button>
                <button type="button" class="kv-shortcut" data-open-stock="list"><span class="kv-shortcut-icon">${icon('list-ordered')}</span><strong>${esc(t('kv_list'))}</strong><span>${esc(t('kv_list_help'))}</span></button>
            </div>
            ${batches.length ? `<h2 class="kv-section">${esc(t('kv_batches_title'))}</h2><p class="kiosk-muted kv-small">${esc(t('kv_batches_help'))}</p>
            <div class="kiosk-prod">${batches.map(i => `<article class="kiosk-card kiosk-prod-item" data-item="${i.item_id}">
                <div class="kiosk-prod-head"><div><h3>${esc(i.name)}</h3><div class="kiosk-muted">${esc(t('prod_done', {q: qty(i.produced, i)}))}${i.waste ? ' · ' + esc(t('prod_waste', {q: qty(i.waste, i)})) : ''}</div></div>
                    ${day.editable ? `<div class="kiosk-prod-actions"><button type="button" class="kiosk-btn is-small" data-new="${i.item_id}">＋ ${esc(t('prod_batch'))}</button></div>` : ''}</div>
                ${batchTiles(i)}
                <ul class="kiosk-batches">${i.batches.map(b => `<li class="${b.cancelled ? 'is-cancelled' : ''}">
                    <button type="button" class="kiosk-batch" data-batch="${b.id}"><strong>${esc(b.lot_code)}</strong>
                    <span>${esc(qty(b.quantity, i))}</span><span class="kiosk-muted">${esc(hhmm(b.created_at))}${b.employee ? ' · ' + esc(b.employee) : ''}</span>
                    <span class="kiosk-lots ${b.cancelled ? '' : (b.lots_total && b.lots_done >= b.lots_total ? 'is-ok' : 'is-todo')}">${b.cancelled ? esc(t('prod_cancelled')) : esc(t('prod_lots', {done: b.lots_done ?? 0, total: b.lots_total ?? 0}))}</span></button></li>`).join('')}</ul>
            </article>`).join('')}</div>` : ''}
            ${day.editable && day.items.length ? `<div class="kiosk-row is-end"><button type="button" class="kiosk-btn is-small" data-close-day>${icon('moon')} ${esc(t('kv_end_day'))}</button></div>` : ''}
            ${deliveriesCard()}
            ${operationsCard(me)}`;
        if (message) toast(message);
        const find = (id) => day.items.find(i => i.item_id === id) || day.catalog.find(c => c.item_id === id);
        bindOperations(me);
        el.querySelectorAll('[data-open-validate]').forEach(b => b.addEventListener('click', (e) => { e.stopPropagation(); renderLines(day, me, 'plan'); }));
        el.querySelector('section[data-open-validate]')?.addEventListener('keydown', (e) => { if (e.key === 'Enter') renderLines(day, me, 'plan'); });
        el.querySelectorAll('[data-open-encode]').forEach(b => b.addEventListener('click', () => renderLines(day, me, 'encode')));
        el.querySelectorAll('[data-open-stock]').forEach(b => b.addEventListener('click', () => { if (session) session.stockView = b.dataset.openStock; openTab('stock'); }));
        el.querySelectorAll('[data-receive-order]').forEach(b => b.addEventListener('click', () => renderReceive(Number(b.dataset.receiveOrder))));
        el.querySelectorAll('[data-new]').forEach(b => b.addEventListener('click', () => renderNewBatch(find(Number(b.dataset.new)))));
        el.querySelectorAll('[data-batch]').forEach(b => b.addEventListener('click', () => renderBatch(Number(b.dataset.batch))));
        el.querySelector('[data-close-day]')?.addEventListener('click', () => renderEndDay(day, me, 1));
        // Retour d'une action ouverte depuis l'assistant « Koniec dnia » (lots, NC, perte) : on reprend à son étape.
        if (resumeEndDay) { const back = resumeEndDay; resumeEndDay = null; renderEndDay(day, me, back); }
    }

    // Quantité en cartons (fiche avec pièces par carton) sinon dans l'unité de la fiche.
    const cq = (q, item, bare) => {
        if (q === null || q === undefined) return '—';
        return item.ppc ? (bare ? fmt(Math.round(q / item.ppc * 100) / 100) : `${fmt(Math.round(q / item.ppc * 100) / 100)} ${t('prod_cartons_short')}`) : (bare ? fmt(q) : `${fmt(q)} ${item.unit}`);
    };
    const r3 = (v) => Math.round(v * 1000) / 1000;
    // « Zrobione » en pièces (27/10/2026) : combien de cartons ça fait — cartons pleins + pièces restantes.
    const cartonsOf = (q, item) => {
        if (!item.ppc || !(q > 0)) return '';
        const full = Math.floor(q / item.ppc + 1e-9);
        const rest = r3(q - full * item.ppc);
        return rest > 1e-6 ? t('kv_cartons_rest', {c: fmt(full), r: fmt(rest), u: item.unit}) : t('kv_cartons_eq', {c: fmt(full)});
    };

    // Perte : l'ancien formulaire séparé est remplacé par la ligne de validation (quantité, motif, photos).
    function renderWaste(item) {
        api('GET', '/kiosk/production', undefined, true).then(day => renderLines(day, null, 'plan', item.item_id)).catch(err => toast(err.message, true));
    }

    // --- Validation de la production du jour : une ligne par produit (fait | perte + photos + motif) ---------------
    // mode « plan » : lignes du plan (+ produits hors plan) ; mode « encode » : produits commandés en boutons (le reste
    // par la barre de recherche), et les mêmes lignes à droite. Lignes gardées dans vstate (saisies non validées).
    let vstate = null;
    function lineState(i) {
        return {
            id: i.item_id, produced: i.line ? i.line.produced : (i.planned !== null ? Math.max(i.planned, i.produced) : i.produced),
            waste: 0, reason: null, wasteNote: '', note: i.line && i.line.note ? i.line.note : '', photos: [], editing: !i.line, error: '', uuid: null, busy: false,
        };
    }
    function renderLines(day, me, mode, focus) {
        resetIdle();
        longIdle = true;
        if (!vstate || vstate.date !== day.date) vstate = {date: day.date, lines: {}, added: [], search: '', closeNote: '', closeError: '', gaps: false};
        vstate.mode = mode;
        vstate.me = me || vstate.me;
        const locked = !!(day.closed && day.closed.locked);
        const items = () => {
            const ids = new Set();
            const list = [];
            day.items.forEach(i => { if (i.planned !== null || i.produced > 0 || i.line || i.waste > 0) { ids.add(i.item_id); list.push(i); } });
            vstate.added.forEach(id => { if (!ids.has(id)) { const c = day.catalog.find(x => x.item_id === id); if (c) list.push({...c, planned: null, produced: 0, waste: 0, good: 0, batches: [], wastes: [], line: null}); } });
            return list;
        };
        items().forEach(i => { if (!vstate.lines[i.item_id]) vstate.lines[i.item_id] = lineState(i); });
        const all = items();
        const done = all.filter(i => i.line && !vstate.lines[i.item_id].editing).length;
        const sumCartons = (f) => all.reduce((a, i) => a + (i.ppc ? f(i) / i.ppc : 0), 0);
        const plannedCartons = sumCartons(i => i.planned || 0);
        const producedCartons = sumCartons(i => vstate.lines[i.item_id].produced || 0);
        const wasteQ = all.reduce((a, i) => a + i.waste + (vstate.lines[i.item_id].waste || 0), 0);
        const producedQ = all.reduce((a, i) => a + (vstate.lines[i.item_id].produced || 0), 0);
        const lineHtml = (i) => drawLine(day, i, vstate.lines[i.item_id], locked);
        const back = `<button type="button" class="kiosk-btn is-small kv-back" data-kv-back>${icon('chevron-left')} ${esc(t(mode === 'encode' && vstate.from === 'plan' ? 'kv_back_validate' : 'tab_production'))}</button>`;
        const lockedNote = locked ? `<p class="kiosk-status kv-locked">${icon('circle-check', 'is-sm')} ${esc(t('day_closed', {time: hhmm(day.closed.at), name: day.closed.employee || ''}))} — ${esc(t('kv_locked_help'))}</p>` : '';
        const closeBox = vstate.gaps ? `<section class="kiosk-card kv-closebox"><p class="kiosk-error">${icon('triangle-alert', 'is-sm')} ${esc(vstate.closeError)}</p>
            <label class="kv-field">${esc(t('eod_note_required'))}<input name="close_note" maxlength="255" value="${esc(vstate.closeNote)}"></label>
            <div class="kiosk-row"><button type="button" class="kiosk-btn" data-kv-eod>${icon('moon')} ${esc(t('kv_end_day'))}</button>
            <button type="button" class="kiosk-btn is-primary" data-kv-close-note>${icon('check')} ${esc(t('day_confirm'))}</button></div></section>` : '';
        const foot = `<div class="kv-foot"><div class="kv-sum"><div><span class="kiosk-muted">${esc(t('day_col_done'))}</span><strong>${esc(fmt(Math.round(producedCartons * 10) / 10))}${plannedCartons ? ' / ' + esc(fmt(Math.round(plannedCartons * 10) / 10)) : ''} ${esc(t('prod_cartons_short'))}</strong></div>
            <div><span class="kiosk-muted">${esc(t('day_col_waste'))}</span><strong class="${wasteQ ? 'kv-danger' : ''}">${esc(fmt(wasteQ))}${producedQ ? ' · ' + esc(fmt(Math.round(wasteQ / producedQ * 1000) / 10)) + ' %' : ''}</strong></div>
            <div><span class="kiosk-muted">${esc(t('kv_validated'))}</span><strong>${done} / ${all.length}</strong></div></div>
            ${locked ? '' : `${mode === 'plan' ? `<button type="button" class="kiosk-btn" data-kv-encode>＋ ${esc(t('kv_off_plan'))}</button>` : ''}
            <button type="button" class="kiosk-btn is-primary" data-kv-all ${all.length ? '' : 'disabled'}>${icon('check')} ${esc(t('kv_validate_day'))}</button>`}</div>`;
        let body;
        if (mode === 'encode') {
            body = `<div class="kv-crumb">${back}<div><h2>${esc(t('kv_encode_title'))}</h2><p class="kiosk-muted">${esc(t('kv_encode_page_help'))}</p></div></div>${lockedNote}
                <div class="kv-encode">
                    <div class="kv-pick"><label class="kv-search">${icon('search', 'is-sm')}<input type="search" data-kv-search value="${esc(vstate.search)}" placeholder="${esc(t('kv_search_ph', {n: day.catalog.length}))}" autocomplete="off" enterkeyhint="search"></label>
                        <div data-kv-picklist>${pickList(day, all, locked)}</div></div>
                    <section class="kiosk-card kv-panel"><h2>${esc(t('kv_day_lines'))}</h2><p class="kiosk-muted kv-small">${esc(t('kv_day_lines_help', {n: all.length}))}</p>
                        <div class="kv-lines is-compact">${all.map(lineHtml).join('') || `<p class="kiosk-muted">${esc(t('kv_pick_product'))}</p>`}</div></section>
                </div>${closeBox}${foot}`;
        } else {
            body = `<div class="kv-crumb">${back}<div><h2>${esc(t('kv_validate_title', {date: dateFmt(day.date)}))}</h2><p class="kiosk-muted">${esc(t('kv_validate_help'))}</p></div></div>${lockedNote}
                <div class="kv-vhead"><span>${esc(t('kv_col_product'))}</span><span>${esc(t('day_col_done'))}</span><span>${esc(t('kv_col_waste'))}</span><span></span></div>
                <div class="kv-lines">${all.map(lineHtml).join('') || `<section class="kiosk-card"><p class="kiosk-muted">${esc(t('kv_nothing'))}</p></section>`}</div>${closeBox}${foot}`;
        }
        el.innerHTML = header(null, true) + body;
        bindLines(day, mode, all);
        if (focus) el.querySelector(`[data-line="${focus}"]`)?.scrollIntoView({block: 'center'});
    }

    // Boutons « Zakoduj produkcję » : sans recherche — produits du groupe commandés (wysyłka najbliższa najpierw) ;
    // recherche — tous les produits du groupe (nom sans accents, tous les mots).
    const foldTxt = (v) => String(v || '').toLowerCase().replace(/ł/g, 'l').normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    function pickList(day, all, locked) {
        const inList = new Set(all.map(i => i.item_id));
        const byId = new Map(day.catalog.map(c => [c.item_id, c]));
        const q = foldTxt(vstate.search).split(/\s+/).filter(Boolean);
        const button = (c, sub) => `<button type="button" class="kv-prod ${inList.has(c.item_id) ? 'is-added' : ''}" data-kv-add="${c.item_id}" ${locked ? 'disabled' : ''}>
            <strong>${esc(c.name)}</strong><small>${inList.has(c.item_id) ? icon('check', 'is-sm') + ' ' + esc(t('kv_in_list')) + ' · ' : ''}${sub}</small></button>`;
        const unitText = (c) => c.ppc ? esc(t('kv_ppc', {n: c.ppc})) : esc(c.unit);
        if (q.length) {
            const found = day.catalog.filter(c => { const n = foldTxt(c.name + ' ' + (c.category || '')); return q.every(w => n.includes(w)); }).slice(0, 60);
            return `<h3 class="kv-pick-title">${esc(t('kv_search_found', {n: found.length}))}</h3>
                <div class="kv-prods">${found.map(c => button(c, `${c.category ? esc(c.category) + ' · ' : ''}${unitText(c)}`)).join('') || `<p class="kiosk-muted">${esc(t('kv_search_none'))}</p>`}</div>`;
        }
        const ordered = (day.ordered || []).filter(o => byId.has(o.item_id));
        return `<h3 class="kv-pick-title">${esc(t('kv_ordered_title', {date: dateFmt(day.ordered_to).slice(0, 5)}))}</h3>
            <div class="kv-prods">${ordered.map(o => button(byId.get(o.item_id), `${esc(t('kv_ordered_q', {n: fmt(o.cartons)}))} · ${esc(t('kv_ordered_ship', {date: dateFmt(o.next_ship).slice(0, 5)}))}`)).join('')
                || `<p class="kiosk-muted">${esc(t('kv_ordered_none'))}</p>`}</div>
            <p class="kiosk-muted kv-small">${icon('search', 'is-sm')} ${esc(t('kv_search_help'))}</p>`;
    }

    const recipeBtn = (i) => i.has_recipe === false ? '' : `<button type="button" class="kv-recipe-btn" data-kv-recipe="${i.item_id}">${icon('book-open', 'is-sm')} ${esc(t('or_open'))}</button>`;

    // Receptura na zamówienie (27/10/2026, makieta 1) : zamówione kartony × sztuki w kartonie + strata produkcyjna
    // (0 / 2 / 5 % albo inna) → sztuki do zrobienia i składniki ; opakowania na zamówienie (bez straty).
    // « Zatwierdź » : partia z ilością do zrobienia, potem numer partii każdego składnika (zdjęcie worka, OCR albo ręcznie).
    async function renderOrderRecipe(day, i) {
        longIdle = true;
        resetIdle();
        let r;
        try { r = await api('GET', `/kiosk/recipes/${i.item_id}`, undefined, true); } catch (err) { toast(err.message, true); return; }
        const ppc = r.basis === 'carton' ? r.ppc : null;
        const ordered = (day.ordered || []).filter(o => o.item_id === i.item_id).reduce((s, o) => s + (Number(o.cartons) || 0), 0);
        const st = {
            cartons: ppc ? (ordered > 0 ? ordered : (i.planned ? Math.max(1, Math.ceil(i.planned / ppc - 1e-9)) : 1)) : null,
            qty: ppc ? null : (i.planned || (r.basis === 'kg' ? r.recipe_yield : 1) || 1),
            loss: Number(day.recipe_loss_pct ?? 2),
        };
        const back = () => renderLines(day, vstate.me, vstate.mode, i.item_id);
        const base = () => ppc ? st.cartons * ppc : st.qty;
        const toMake = () => {
            const q = base() * (1 + st.loss / 100);
            return ppc || r.unit === 'szt' ? Math.ceil(q - 1e-9) : Math.round(q * 1000) / 1000;
        };
        // Mnożnik drzewa : karton (sztuki ÷ sztuki w kartonie), kg albo liczba receptur.
        const fOf = (q) => ppc ? q / ppc : q;
        const draw = () => {
            const make = toMake();
            const fI = fOf(make);
            const fP = fOf(base());
            const subs = r.tree.lines.filter(l => l.kind === 'recipe');
            const mats = r.tree.lines.filter(l => l.kind === 'material' && !l.packaging);
            const pack = r.tree.lines.filter(l => l.kind === 'material' && l.packaging);
            const lossChip = (v) => `<button type="button" class="kv-chip ${Math.abs(st.loss - v) < 1e-9 ? 'is-on' : ''}" data-or-loss="${v}">${v ? '+' + fmt(v) : '0'} %</button>`;
            const custom = ![0, 2, 5].some(v => Math.abs(st.loss - v) < 1e-9);
            el.querySelector('[data-or-body]').innerHTML = `
                <section class="kiosk-card or-head">
                    <div class="or-col"><span class="kv-lbl">${esc(t('or_order'))}</span>
                        ${ppc ? `<div class="kv-step"><button type="button" data-or-step="-1" aria-label="−">${icon('minus')}</button><input class="kv-num" data-or-cartons inputmode="numeric" value="${st.cartons}">
                            <button type="button" data-or-step="1" aria-label="+">${icon('plus')}</button><small>${esc(t('prod_cartons_short'))}</small></div>
                            <p class="or-calc">${esc(t('or_calc', {c: fmt(st.cartons), ppc, q: fmt(base()), u: r.unit}))}</p>`
                        : `<div class="kv-step"><input class="kv-num" data-or-qty inputmode="decimal" value="${String(st.qty).replace('.', ',')}"><small>${esc(r.basis === 'kg' ? 'kg' : (r.basis === 'recipe' ? t('or_recipes') : r.unit))}</small></div>`}
                        ${ordered > 0 ? `<p class="kiosk-muted kv-small">${esc(t('or_ordered', {c: fmt(ordered)}))}</p>` : ''}</div>
                    <div class="or-col"><span class="kv-lbl">${esc(t('or_loss'))}</span>
                        <div class="kv-reasons">${lossChip(0)}${lossChip(2)}${lossChip(5)}
                            <label class="kv-chip or-custom ${custom ? 'is-on' : ''}">${esc(t('or_other'))} <input data-or-loss-custom inputmode="decimal" value="${custom ? String(st.loss).replace('.', ',') : ''}" placeholder="%"></label></div></div>
                    <div class="or-col or-make"><span class="kv-lbl">${esc(t('or_to_make'))}</span><strong>${esc(fmt(make))} ${esc(ppc ? r.unit : (r.basis === 'kg' ? 'kg' : (r.basis === 'recipe' ? t('or_recipes') : r.unit)))}</strong>
                        ${ppc ? `<small class="kiosk-muted">${esc(cartonsOf(make, {ppc, unit: r.unit}))}</small>` : ''}</div>
                </section>
                <div class="or-grid">
                    <section class="kiosk-card"><h2>${esc(t('or_ingredients'))}</h2>
                        ${subs.map(l => recBlock(l, fI, 0)).join('')}
                        ${mats.length ? `<ul class="kiosk-rec-lines">${mats.map(l => lineRow(l, fI)).join('')}</ul>` : ''}
                        ${!subs.length && !mats.length ? `<p class="kiosk-muted">${esc(t('rec_no_tree'))}</p>` : ''}</section>
                    ${pack.length ? `<section class="kiosk-card"><h2>${esc(t('or_packaging'))}</h2><ul class="kiosk-rec-lines">${pack.map(l => lineRow(l, fP)).join('')}</ul>
                        <p class="kiosk-muted kv-small">${esc(t('or_packaging_help'))}</p></section>` : ''}
                </div>`;
        };
        el.innerHTML = header(`<button type="button" class="kiosk-btn is-small kiosk-back" data-back>‹ ${esc(t('back'))}</button>`, true) + `
            <section class="kiosk-card or-title"><div><h2>${esc(i.name)}</h2><span class="or-pill">${esc(t('or_title'))}</span></div>
                <button type="button" class="kiosk-btn is-primary" data-or-go>${icon('check')} ${esc(t('or_validate'))}</button></section>
            <div data-or-body></div>
            <p class="kiosk-muted or-foot">${esc(t('or_validate_help'))}</p>`;
        draw();
        el.querySelector('[data-back]').addEventListener('click', back);
        const body = el.querySelector('[data-or-body]');
        let batch = null;
        const id = uuid();
        // Zatwierdź (przycisk albo dotknięcie składnika) : partia z ilością do zrobienia, potem numer partii składnika.
        const go = async (material, btn) => {
            if (batch) return renderBatch(batch.id, null, null, material);
            const q = toMake();
            if (!window.confirm(t('or_confirm', {q: fmt(q), u: ppc ? r.unit : (r.basis === 'kg' ? 'kg' : r.unit), name: i.name}))) return;
            if (btn) btn.disabled = true;
            try {
                batch = await api('POST', '/kiosk/production', {item_id: i.item_id, quantity: Math.round(q * 1000) / 1000, client_uuid: id}, true);
                renderBatch(batch.id, t('or_saved', {lot: batch.lot_code}), batch, material);
            } catch (err) { if (btn) btn.disabled = false; toast(err.offline ? t('offline_retry') : err.message, true); }
        };
        body.addEventListener('click', (e) => {
            resetIdle();
            const line = e.target.closest('[data-mat]');
            if (line) return go(Number(line.dataset.mat), null);
            const step = e.target.closest('[data-or-step]');
            if (step) { st.cartons = Math.max(1, st.cartons + Number(step.dataset.orStep)); return draw(); }
            const loss = e.target.closest('[data-or-loss]');
            if (loss) { st.loss = Number(loss.dataset.orLoss); return draw(); }
        });
        body.addEventListener('change', (e) => {
            const n = (v) => Number(String(v).replace(',', '.').replace(/\s/g, ''));
            if (e.target.matches('[data-or-cartons]')) { const v = Math.round(n(e.target.value)); if (Number.isFinite(v) && v > 0) st.cartons = v; return draw(); }
            if (e.target.matches('[data-or-qty]')) { const v = n(e.target.value); if (Number.isFinite(v) && v > 0) st.qty = v; return draw(); }
            if (e.target.matches('[data-or-loss-custom]')) { const v = n(e.target.value); if (Number.isFinite(v) && v >= 0 && v <= 50) st.loss = v; return draw(); }
        });
        el.querySelector('[data-or-go]').addEventListener('click', (e) => go(null, e.currentTarget));
    }

    function drawLine(day, i, L, locked) {
        const unitDone = i.unit;
        const shortQ = i.planned !== null ? r3(i.planned - L.produced) : 0;
        const editing = L.editing && !locked;
        const live = i.wastes.filter(w => !w.cancelled);
        const wastes = live.length ? `<ul class="kv-wastes">${live.map(w => `<li>${icon('trash-2', 'is-sm')} ${esc(fmt(w.quantity))} ${esc(i.unit)} · ${esc(t('waste_' + w.reason))}${w.photos && w.photos.length ? ` · ${w.photos.length} ${icon('camera', 'is-sm')}` : ''}${w.note ? ' — ' + esc(w.note) : ''}</li>`).join('')}</ul>` : '';
        const planMeta = i.planned !== null ? t('kv_plan_q', {q: cq(i.planned, i)}) + (i.ppc ? ' · ' + fmt(i.planned) + ' ' + i.unit : '') : t('kv_off_plan_short');
        if (!editing) {
            const ok = !!i.line;
            return `<article class="kiosk-card kv-line ${ok ? 'is-ok' : ''}" data-line="${i.item_id}">
                <div class="kv-prod-name"><h3>${esc(i.name)}</h3><p class="kiosk-muted">${esc(planMeta)}</p>${recipeBtn(i)}${i.line && i.line.corrected_by ? `<p class="kiosk-muted kv-small">${icon('pencil', 'is-sm')} ${esc(t('kv_corrected', {name: i.line.corrected_by}))}</p>` : ''}</div>
                <div class="kv-col"><span class="kv-lbl">${esc(t('day_col_done'))}</span><strong class="kv-big">${esc(fmt(ok ? i.line.produced : i.produced))} ${esc(i.unit)}</strong>
                    ${i.ppc && (ok ? i.line.produced : i.produced) > 0 ? `<p class="kv-cartons">${esc(cartonsOf(ok ? i.line.produced : i.produced, i))}</p>` : ''}
                    ${ok && i.line.note ? `<p class="kiosk-muted kv-small">${esc(i.line.note)}</p>` : ''}</div>
                <div class="kv-col"><span class="kv-lbl">${esc(t('kv_col_waste'))}</span>${wastes || `<span class="kiosk-muted">—</span>`}</div>
                <div class="kv-end">${ok ? `<span class="kv-check is-ok" title="${esc(t('kv_line_ok', {name: i.line.employee || '', time: hhmm(i.line.validated_at)}))}">${icon('check')}</span>` : ''}
                    ${!locked && day.editable ? `<button type="button" class="kv-edit" data-kv-edit="${i.item_id}" aria-label="${esc(t('kv_edit'))}" title="${esc(t('kv_edit'))}">${icon('pencil')}</button>` : ''}
</div>
            </article>`;
        }
        const reasons = ['quality', 'burnt', 'damaged', 'samples', 'other'];
        const photoRequired = !!(day.waste_photo && day.waste_photo.required);
        const needPhoto = L.waste > 0 && photoRequired && !L.photos.length;
        const doneVal = r3(L.produced);
        return `<article class="kiosk-card kv-line is-edit ${shortQ > 1e-6 ? 'is-short' : ''}" data-line="${i.item_id}">
            <div class="kv-prod-name"><h3>${esc(i.name)}</h3><p class="kiosk-muted">${esc(planMeta)}${i.produced > 0 ? ' · ' + esc(t('kv_recorded', {q: cq(i.produced, i)})) : ''}</p>${recipeBtn(i)}</div>
            <div class="kv-col"><span class="kv-lbl">${esc(t('day_col_done'))}</span>
                <div class="kv-step"><button type="button" data-kv-step="produced" data-d="-1" aria-label="−">${icon('minus')}</button>
                    <input class="kv-num" data-kv-field="produced" inputmode="decimal" value="${esc(String(doneVal).replace('.', ','))}" aria-label="${esc(t('day_col_done'))}">
                    <button type="button" data-kv-step="produced" data-d="1" aria-label="+">${icon('plus')}</button><small>${esc(unitDone)}</small></div>
                ${i.ppc ? `<p class="kv-cartons" data-kv-cartons>${esc(cartonsOf(L.produced, i) || t('kv_cartons_eq', {c: 0}))} <span class="kiosk-muted">· ${esc(t('kv_ppc', {n: i.ppc}))}</span></p>` : ''}
                ${i.planned !== null ? (shortQ > 1e-6 ? `<p class="kv-hint is-warn">${esc(t('kv_short', {q: cq(shortQ, i)}))}</p>
                    <input class="kv-text" data-kv-field="note" maxlength="255" placeholder="${esc(t('kv_short_reason'))}" value="${esc(L.note)}">`
                    : (Math.abs(shortQ) < 1e-6 ? `<p class="kv-hint is-ok">${icon('check', 'is-sm')} ${esc(t('kv_as_planned'))}</p>` : `<p class="kv-hint">${esc(t('kv_over', {q: cq(-shortQ, i)}))}</p>`)) : ''}</div>
            <div class="kv-col"><span class="kv-lbl">${esc(t('kv_col_waste'))} (${esc(i.unit)})</span>
                <div class="kv-waste"><div class="kv-step ${L.waste > 0 ? 'is-waste' : ''}"><button type="button" data-kv-step="waste" data-d="-1" aria-label="−">${icon('minus')}</button>
                    <input class="kv-num" data-kv-field="waste" inputmode="decimal" value="${esc(String(r3(L.waste)).replace('.', ','))}" aria-label="${esc(t('kv_col_waste'))}">
                    <button type="button" data-kv-step="waste" data-d="1" aria-label="+">${icon('plus')}</button></div>
                    <div class="kv-photos">${L.photos.map((p, k) => `<span class="kv-thumb"><img src="${p.src}" alt=""><button type="button" data-kv-unphoto="${k}" aria-label="${esc(t('kv_photo_remove'))}">${icon('x', 'is-sm')}</button></span>`).join('')}
                        ${L.photos.length < (day.waste_photo ? day.waste_photo.max : 4) ? `<label class="kv-cam ${needPhoto ? 'is-required' : ''}" aria-label="${esc(t('kv_photo_add'))}">${L.busy ? '…' : icon('camera')}<input type="file" accept="image/*" capture="environment" data-kv-photo hidden></label>` : ''}</div></div>
                ${L.waste > 0 ? `<div class="kv-reasons">${reasons.map(k => `<button type="button" class="kv-chip ${L.reason === k ? 'is-on' : ''}" data-kv-reason="${k}">${esc(t('waste_' + k))}</button>`).join('')}</div>
                    ${L.reason === 'other' ? `<input class="kv-text" data-kv-field="wasteNote" maxlength="255" placeholder="${esc(t('waste_note'))}" value="${esc(L.wasteNote)}">` : ''}
                    ${needPhoto ? `<p class="kv-hint is-bad">${icon('triangle-alert', 'is-sm')} ${esc(t('kv_photo_required'))}</p>` : ''}` : ''}
                ${wastes}</div>
            <div class="kv-end"><button type="button" class="kv-check" data-kv-ok="${i.item_id}" aria-label="${esc(t('kv_validate_line'))}" ${L.busy ? 'disabled' : ''}>${icon('check')}</button></div>
            ${L.error ? `<p class="kiosk-error kv-line-error">${esc(L.error)}</p>` : ''}
        </article>`;
    }

    // Photo de perte : réduite (1280 px, JPEG) puis envoyée tout de suite ; l'identifiant part avec la ligne.
    async function wastePhoto(file) {
        const bitmap = await loadImage(file);
        const scale = Math.min(1, 1280 / Math.max(bitmap.width, bitmap.height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(bitmap.width * scale);
        canvas.height = Math.round(bitmap.height * scale);
        canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        const src = canvas.toDataURL('image/jpeg', 0.72);
        const r = await api('POST', '/kiosk/photos', {photo: src}, true);
        return {id: r.id, src};
    }

    function lineCheck(day, i, L) {
        if (i.planned !== null && L.produced + 1e-6 < i.planned && !L.note.trim()) return t('kv_short_reason_required');
        if (L.waste > 0 && !L.reason) return t('waste_reason_required');
        if (L.waste > 0 && L.reason === 'other' && !L.wasteNote.trim()) return t('waste_note_required');
        if (L.waste > 0 && day.waste_photo && day.waste_photo.required && !L.photos.length) return t('kv_photo_required');
        if (i.waste + L.waste > L.produced + 1e-6) return t('kv_waste_over');
        return '';
    }

    async function sendLine(day, i, L) {
        L.uuid = L.uuid || uuid();
        const body = {date: day.date, item_id: i.item_id, produced: r3(L.produced), note: L.note.trim() || null, client_uuid: L.uuid};
        if (L.waste > 0) Object.assign(body, {waste_quantity: r3(L.waste), waste_reason: L.reason, waste_note: L.wasteNote.trim() || null, photo_ids: L.photos.map(p => p.id)});
        return api('POST', '/kiosk/production/lines', body, true);
    }

    function bindLines(day, mode, all) {
        const redraw = (focus) => renderLines(day, vstate.me, vstate.mode, focus);
        const byId = (id) => all.find(i => i.item_id === Number(id));
        el.querySelector('[data-kv-back]')?.addEventListener('click', () => {
            if (mode === 'encode' && vstate.from === 'plan') { vstate.from = null; return renderLines(day, vstate.me, 'plan'); }
            renderProduction();
        });
        el.querySelector('[data-kv-encode]')?.addEventListener('click', () => { vstate.from = 'plan'; renderLines(day, vstate.me, 'encode'); });
        const bindAdd = () => el.querySelectorAll('[data-kv-add]').forEach(b => b.addEventListener('click', () => {
            const id = Number(b.dataset.kvAdd);
            if (!vstate.added.includes(id) && !all.some(i => i.item_id === id)) vstate.added.push(id);
            redraw(id);
        }));
        bindAdd();
        // Recherche : seule la liste des boutons est redessinée (le clavier de la tablette reste ouvert).
        const search = el.querySelector('[data-kv-search]');
        search?.addEventListener('input', () => {
            resetIdle();
            vstate.search = search.value;
            el.querySelector('[data-kv-picklist]').innerHTML = pickList(day, all, !!(day.closed && day.closed.locked));
            bindAdd();
        });
        el.querySelectorAll('[data-line]').forEach(card => {
            const i = byId(card.dataset.line);
            const L = vstate.lines[i.item_id];
            card.addEventListener('click', async (e) => {
                resetIdle();
                const step = e.target.closest('[data-kv-step]');
                if (step) {
                    const f = step.dataset.kvStep;
                    const unit = f === 'produced' && i.ppc ? i.ppc : 1;
                    L[f] = Math.max(0, r3(L[f] + Number(step.dataset.d) * unit));
                    if (f === 'waste' && L.waste === 0) L.reason = null;
                    L.error = '';
                    return redraw(i.item_id);
                }
                if (e.target.closest('[data-kv-recipe]')) return renderOrderRecipe(day, i);
                const reason = e.target.closest('[data-kv-reason]');
                if (reason) { L.reason = reason.dataset.kvReason; L.error = ''; return redraw(i.item_id); }
                const un = e.target.closest('[data-kv-unphoto]');
                if (un) { L.photos.splice(Number(un.dataset.kvUnphoto), 1); return redraw(i.item_id); }
                if (e.target.closest('[data-kv-edit]')) { L.editing = true; return redraw(i.item_id); }
                const ok = e.target.closest('[data-kv-ok]');
                if (ok) {
                    L.error = lineCheck(day, i, L);
                    if (L.error) return redraw(i.item_id);
                    L.busy = true;
                    ok.disabled = true;
                    try {
                        day = await sendLine(day, i, L);
                        const fresh = day.items.find(x => x.item_id === i.item_id);
                        vstate.lines[i.item_id] = fresh ? lineState(fresh) : lineState(i);
                        vstate.added = vstate.added.filter(x => x !== i.item_id);
                        toast(t('kv_line_saved', {name: i.name}));
                    } catch (err) { L.busy = false; L.error = err.offline ? t('offline_retry') : err.message; }
                    return renderLines(day, vstate.me, vstate.mode, i.item_id);
                }
            });
            card.addEventListener('change', async (e) => {
                const input = e.target.closest('[data-kv-photo]');
                if (input && input.files && input.files[0]) {
                    L.busy = true;
                    redraw(i.item_id);
                    try { L.photos.push(await wastePhoto(input.files[0])); L.error = ''; }
                    catch (err) { L.error = err.offline ? t('offline_retry') : (err.message || t('photo_error')); }
                    L.busy = false;
                    return redraw(i.item_id);
                }
                const field = e.target.closest('[data-kv-field]');
                if (!field) return;
                const f = field.dataset.kvField;
                if (f === 'produced' || f === 'waste') {
                    const n = Number(String(field.value).replace(',', '.').replace(/\s/g, ''));
                    if (Number.isFinite(n) && n >= 0) L[f] = r3(n);
                    if (f === 'waste' && L.waste === 0) L.reason = null;
                    L.error = '';
                    return redraw(i.item_id);
                }
                L[f] = field.value;
            });
            card.addEventListener('input', (e) => {
                resetIdle();
                // Pièces tapées : les cartons se mettent à jour pendant la frappe.
                const done = e.target.closest('[data-kv-field="produced"]');
                const box = card.querySelector('[data-kv-cartons]');
                if (done && box) {
                    const n = Number(String(done.value).replace(',', '.').replace(/\s/g, ''));
                    box.firstChild.textContent = (Number.isFinite(n) && n > 0 ? cartonsOf(n, i) : '') || t('kv_cartons_eq', {c: 0});
                    box.firstChild.textContent += ' ';
                }
                const field = e.target.closest('[data-kv-field="note"], [data-kv-field="wasteNote"]');
                if (field) L[field.dataset.kvField] = field.value;
            });
        });
        el.querySelector('[name=close_note]')?.addEventListener('input', (e) => { vstate.closeNote = e.target.value; resetIdle(); });
        el.querySelector('[data-kv-eod]')?.addEventListener('click', () => renderEndDay(day, vstate.me, 1));
        const close = async (btn) => {
            btn.disabled = true;
            try {
                await api('POST', '/kiosk/production/close', {date: day.date, note: vstate.closeNote.trim() || null}, true);
                vstate = null;
                renderProduction(t('day_closed_done'));
            } catch (err) {
                btn.disabled = false;
                if (err.code === 'DAY_GAPS_NOTE_REQUIRED') { vstate.gaps = true; vstate.closeError = err.message; return redraw(); }
                toast(err.offline ? t('offline_retry') : err.message, true);
            }
        };
        el.querySelector('[data-kv-close-note]')?.addEventListener('click', (e) => {
            if (!vstate.closeNote.trim()) { el.querySelector('[name=close_note]').focus(); return; }
            close(e.currentTarget);
        });
        // Valider la journée : les lignes pas encore validées partent une par une (contrôles locaux d'abord), puis la journée.
        el.querySelector('[data-kv-all]')?.addEventListener('click', async (e) => {
            const btn = e.currentTarget;
            const todo = all.filter(i => !i.line || vstate.lines[i.item_id].editing);
            for (const i of todo) {
                const L = vstate.lines[i.item_id];
                L.error = lineCheck(day, i, L);
                if (L.error) { toast(t('kv_fix_line', {name: i.name}), true); return redraw(i.item_id); }
            }
            btn.disabled = true;
            for (const i of todo) {
                const L = vstate.lines[i.item_id];
                try {
                    day = await sendLine(day, i, L);
                    const fresh = day.items.find(x => x.item_id === i.item_id);
                    vstate.lines[i.item_id] = fresh ? lineState(fresh) : lineState(i);
                    vstate.added = vstate.added.filter(x => x !== i.item_id);
                } catch (err) {
                    L.error = err.offline ? t('offline_retry') : err.message;
                    return renderLines(day, vstate.me, vstate.mode, i.item_id);
                }
            }
            close(btn);
        });
    }

    // --- Magazyn grupy : stan, min. / maks., ile wolno jeszcze zrobić ; lista produkcji tygodnia ------------------
    async function renderStock(message) {
        longIdle = true;
        resetIdle();
        const view = (session && session.stockView) || 'stock';
        const head = (title, sub) => `<div class="kv-crumb is-split"><div><h2>${esc(title)}</h2><p class="kiosk-muted">${esc(sub)}</p></div>
            <div class="kv-switch" role="tablist"><button type="button" class="${view === 'stock' ? 'is-on' : ''}" data-kv-view="stock">${icon('warehouse', 'is-sm')} ${esc(t('kv_stock_view'))}</button>
            <button type="button" class="${view === 'list' ? 'is-on' : ''}" data-kv-view="list">${icon('list-ordered', 'is-sm')} ${esc(t('kv_list'))}</button></div></div>`;
        const tile = (label, value, sub, cls) => `<div class="kv-tile ${cls || ''}"><span>${esc(label)}</span><strong>${esc(value)}</strong><small>${esc(sub)}</small></div>`;
        let body = '';
        try {
            if (view === 'stock') {
                const s = await api('GET', '/kiosk/stock', undefined, true);
                const T = s.totals;
                const c = (v) => v === null || v === undefined ? '—' : fmt(v);
                const rows = s.items.map(r => {
                    const max = r.max_cartons || Math.max(r.min_cartons || 0, r.stock_cartons, 1);
                    const w = Math.min(100, Math.round(r.stock_cartons / max * 100));
                    const m = r.min_cartons !== null && r.max_cartons ? Math.min(100, Math.round(r.min_cartons / r.max_cartons * 100)) : null;
                    const cap = r.below_min ? t('kv_cap_below') : (r.mode !== 'MTS' ? t('kv_cap_mto') : (r.no_max ? t('kv_cap_nomax') : (r.allowed_cartons === 0 ? t('kv_cap_full') : '')));
                    return `<tr class="${r.below_min ? 'is-low' : ''}"><td><strong>${esc(r.name)}</strong><div class="kv-cap">${esc(r.category || '')}${r.today_cartons ? ' · ' + esc(t('kv_today_done', {q: fmt(r.today_cartons)})) : ''}${r.next_shipping ? ' · ' + esc(t('kv_next_ship', {date: dateFmt(r.next_shipping).slice(0, 5)})) : ''}</div></td>
                        <td class="n">${c(r.stock_cartons)}</td><td class="n">${c(r.ordered_cartons)}</td><td class="n">${c(r.min_cartons)}</td><td class="n">${c(r.max_cartons)}</td>
                        <td>${r.max_cartons ? `<div class="kv-gauge"><i class="${r.below_min ? 'is-low' : ''}" style="width:${w}%"></i>${m !== null ? `<b style="left:${m}%"></b>` : ''}</div>` : `<span class="kiosk-muted kv-small">${esc(t(r.mode === 'MTS' ? 'kv_no_max' : 'kv_cap_mto'))}</span>`}</td>
                        <td class="n">${r.mode !== 'MTS' && !r.allowed_cartons ? '<span class="kiosk-muted">—</span>' : `<span class="kv-max">${esc(fmt(r.allowed_cartons))} ${esc(t('prod_cartons_short'))}</span>${cap && r.mode === 'MTS' ? `<div class="kv-cap">${esc(cap)}</div>` : (r.mode !== 'MTS' ? `<div class="kv-cap">${esc(t('kv_cap_orders'))}</div>` : '')}`}</td></tr>`;
                }).join('');
                body = head(t('kv_stock_title', {group: s.group ? s.group.name : ''}), t('kv_stock_sub'))
                    + `<div class="kv-tiles">${tile(t('kv_tile_products'), fmt(T.products), t('kv_tile_mts', {n: T.mts}))}${tile(t('kv_tile_below'), fmt(T.below_min), t('kv_tile_below_sub'), T.below_min ? 'is-danger' : '')}
                        ${tile(t('kv_tile_allowed'), fmt(T.allowed_cartons) + ' ' + t('prod_cartons_short'), t('kv_tile_allowed_sub'))}${tile(t('kv_tile_ordered'), fmt(T.ordered_cartons) + ' ' + t('prod_cartons_short'), t('kv_tile_ordered_sub'))}</div>
                    <section class="kiosk-card kv-tablecard">${rows ? `<div class="kiosk-table-wrap"><table class="kv-table"><thead><tr><th>${esc(t('kv_col_name'))}</th><th class="n">${esc(t('kv_col_stock'))}</th><th class="n">${esc(t('kv_col_ordered'))}</th>
                        <th class="n">${esc(t('kv_col_min'))}</th><th class="n">${esc(t('kv_col_max'))}</th><th>${esc(t('kv_col_gauge'))}</th><th class="n">${esc(t('kv_col_allowed'))}</th></tr></thead><tbody>${rows}</tbody></table></div>
                        <div class="kv-legend"><span><i class="is-low"></i>${esc(t('kv_leg_below'))}</span><span><i></i>${esc(t('kv_leg_ok'))}</span><span><i class="is-min"></i>${esc(t('kv_leg_min'))}</span><span>${esc(t('kv_leg_full'))}</span></div>`
                        : `<p class="kiosk-muted">${esc(t('kv_stock_empty'))}</p>`}</section>`;
            } else {
                const [w, day] = await Promise.all([api('GET', '/kiosk/week'), api('GET', '/kiosk/production', undefined, true).catch(() => null)]);
                const team = w.teams[0] || {days: [], name: ''};
                const byItem = {};
                (day ? day.items : []).forEach(i => { byItem[i.item_id] = i; });
                const cartons = (b) => b.cartons !== null && b.cartons !== undefined ? b.cartons : null;
                const all = team.days.flatMap(d => d.blocks);
                const total = all.reduce((a, b) => a + (cartons(b) || 0), 0);
                const ratio = (b, k) => b.quantity > 0 ? (b[k] || 0) / b.quantity : 0;
                const toOrders = all.reduce((a, b) => a + (cartons(b) || 0) * ratio(b, 'direct_qty'), 0);
                const toStock = all.reduce((a, b) => a + (cartons(b) || 0) * ratio(b, 'stock_qty'), 0);
                const doneToday = (day ? day.items : []).reduce((a, i) => a + (i.ppc ? (i.line ? i.line.produced : i.produced) / i.ppc : 0), 0);
                body = head(t('kv_list_title', {group: team.name || ''}), t('kv_list_sub', {from: dm(w.week_start), to: dm(w.week_end)}))
                    + `<div class="kv-tiles">${tile(t('kv_tile_week'), fmt(Math.round(total)) + ' ' + t('prod_cartons_short'), t('kv_tile_week_sub', {n: all.length}))}
                        ${tile(t('kv_tile_today'), fmt(Math.round(doneToday * 10) / 10) + ' ' + t('prod_cartons_short'), t('kv_tile_today_sub'))}
                        ${tile(t('kv_tile_orders'), fmt(Math.round(toOrders)) + ' ' + t('prod_cartons_short'), t('kv_tile_orders_sub'))}
                        ${tile(t('kv_tile_stock'), fmt(Math.round(toStock)) + ' ' + t('prod_cartons_short'), t('kv_tile_stock_sub'))}</div>`
                    + (team.days.filter(d => d.blocks.length).map(d => `<section class="kiosk-card kv-day ${d.today ? 'is-today' : ''}"><h2>${esc(t('weekday_long_' + d.weekday))} ${esc(dm(d.date))} ${d.today ? `<span class="kiosk-pill is-warn">${esc(t('kv_today'))}</span>` : ''}
                        <span class="kiosk-muted kv-day-total">${esc(fmt(Math.round(d.blocks.reduce((a, b) => a + (cartons(b) || 0), 0))))} ${esc(t('prod_cartons_short'))}</span></h2>
                        <table class="kv-table"><tbody>${d.blocks.map(b => {
                            const i = d.today ? byItem[b.item_id] : null;
                            const doneQ = i ? (i.line ? i.line.produced : i.produced) : null;
                            const src = [b.direct_qty > 0 ? t('kv_src_orders') : null, b.stock_qty > 0 ? t('kv_src_stock') : null].filter(Boolean).join(' + ');
                            const amount = cartons(b) !== null ? `${fmt(cartons(b))} ${t('prod_cartons_short')}` : wq(b.quantity, b.unit, b.factor);
                            return `<tr><td class="kv-w"><strong>${esc(b.name)}</strong>${b.late ? ` <span class="kiosk-tag is-late">${esc(t('week_late'))}</span>` : ''}</td><td class="kiosk-muted">${esc(src)}${b.time ? ' · ' + esc(b.time) : ''}</td>
                                <td class="n">${doneQ !== null && i ? `<span class="kiosk-pill ${i.line ? 'is-ok' : (doneQ > 0 ? 'is-warn' : '')}">${esc(cq(doneQ, i, true))} / ${esc(amount)}</span>` : esc(amount)}</td></tr>`;
                        }).join('')}</tbody></table></section>`).join('') || `<section class="kiosk-card"><p class="kiosk-muted">${esc(t('week_team_empty'))}</p></section>`);
            }
        } catch (err) { if (!err.code || err.code === 'DEVICE_WITHOUT_GROUP') toast(err.message, true); return; }
        el.innerHTML = personHeader() + tabs('stock') + body;
        if (message) toast(message);
        el.querySelectorAll('[data-kv-view]').forEach(b => b.addEventListener('click', () => { if (session) session.stockView = b.dataset.kvView; renderStock(); }));
    }

    // --- Premia : ventes du groupe par personne dans le mois face aux paliers (ERP › Zespół › Premie zespołów) ---------
    async function renderBonus(message) {
        longIdle = true;
        resetIdle();
        let b;
        try { b = await api('GET', '/kiosk/bonus', undefined, true); } catch (err) { if (!err.code || err.code === 'DEVICE_WITHOUT_GROUP') toast(err.message, true); return; }
        if (!b.visible) { if (session) session.bonus = false; openTab('production', t('bonus_hidden')); return; }
        const zl = (v) => v === null || v === undefined ? '—' : `${fmt(Math.round(v))} zł`;
        const month = (ym) => { const d = new Date(ym + '-01T12:00:00'); const m = d.toLocaleDateString(lang === 'fr' ? 'fr-FR' : 'pl-PL', {month: 'long', year: 'numeric'}); return m.charAt(0).toUpperCase() + m.slice(1); };
        const c = b.current;
        const tile = (label, value, sub, cls) => `<div class="kv-tile ${cls || ''}"><span>${esc(label)}</span><strong>${esc(value)}</strong><small>${esc(sub)}</small></div>`;
        const groupName = lang === 'fr' ? b.group.name_fr : b.group.name_pl;
        let body = `<div class="kv-crumb"><div><h2>${esc(t('bonus_title', {group: groupName}))}</h2><p class="kiosk-muted">${esc(t('bonus_sub', {month: month(b.ym), pct: Math.round(b.elapsed * 100)}))}</p></div></div>`;
        if (!c.available) {
            body += `<section class="kiosk-card"><p class="kiosk-muted">${esc(t('bonus_none'))}</p></section>`;
        } else {
            const tiers = b.tiers.filter(x => !x.below_min);
            const top = tiers.length ? tiers[tiers.length - 1].from : b.min;
            const scale = Math.max(top * 1.12, (c.projection || 0) * 1.04, 1);
            const pos = (v) => Math.min(100, Math.max(0, v / scale * 100));
            const marks = tiers.map(x => `<span class="kb-mark ${x.projected ? 'is-on' : ''}" style="left:${pos(x.from)}%"><b>${esc(zl(x.from))}</b><i>+${esc(zl(x.amount))}</i></span>`).join('');
            const tierNo = c.tier_projection !== null ? tiers.findIndex(x => x.from === b.tiers[c.tier_projection].from) + 1 : 0;
            body += `<section class="kiosk-card kb-hero"><div class="kb-figures">
                    <div><span class="kb-label">${esc(t('bonus_per_person'))}</span><strong class="kb-big">${esc(zl(c.per_person))}</strong><small class="kiosk-muted">${esc(t('bonus_per_person_sub', {revenue: zl(c.revenue_pln), people: fmt(c.people)}))}</small></div>
                    <div class="is-right"><span class="kb-label">${esc(t('bonus_projection'))}</span><strong class="kb-big is-primary">${esc(zl(c.projection))}</strong><small class="kiosk-muted">${esc(t('bonus_projection_sub'))}</small></div></div>
                <div class="kb-bar" role="img" aria-label="${esc(t('bonus_per_person'))} ${esc(zl(c.per_person))}"><i class="kb-now" style="width:${pos(c.per_person)}%"></i><i class="kb-pace" style="left:${pos(c.per_person)}%;width:${Math.max(0, pos(c.projection) - pos(c.per_person))}%"></i>${marks}</div></section>`;
            const next = b.next;
            body += `<div class="kv-tiles kb-tiles">
                ${c.bonus_projection > 0 ? tile(t('bonus_tile_bonus'), zl(c.bonus_projection), t('bonus_tile_bonus_sub', {n: tierNo}), 'is-ok') : tile(t('bonus_tile_bonus'), '0 zł', t('bonus_tile_bonus_none', {min: zl(b.threshold)}))}
                ${next ? tile(t('bonus_tile_next', {amount: zl(next.amount)}), zl(next.missing_group), next.per_day !== null && b.remaining_days > 0 ? t('bonus_tile_next_day', {value: zl(next.per_day), days: b.remaining_days}) : t('bonus_tile_next_sub'))
                    : tile(t('bonus_tile_top'), zl(c.bonus_projection), t('bonus_tile_top_sub'), 'is-ok')}
                ${tile(t('bonus_tile_min'), zl(b.threshold), t('bonus_tile_min_sub'))}
                ${b.previous.available ? tile(t('bonus_tile_prev', {month: month(b.previous_ym)}), zl(b.previous.per_person), b.previous.bonus > 0 ? t('bonus_tile_prev_sub', {amount: zl(b.previous.bonus)}) : t('bonus_tile_prev_none')) : ''}</div>`;
            if (b.persons.length) {
                const rows = b.persons.map(p => `<tr class="${p.me ? 'is-me' : ''}"><td><strong>${esc(p.name)}</strong>${p.me ? ` <span class="kiosk-pill">${esc(t('bonus_me'))}</span>` : ''}</td>
                    <td class="n">${b.prorata ? esc(t('bonus_days', {days: p.days, total: b.group_days})) : ''}</td><td class="n"><strong>${esc(zl(p.amount))}</strong></td></tr>`).join('');
                body += `<section class="kiosk-card kv-tablecard"><h2>${esc(t(b.show_people ? 'bonus_persons' : 'bonus_persons_me'))}</h2><table class="kv-table"><tbody>${rows}</tbody></table>
                    <p class="kiosk-muted kv-small">${esc(b.prorata ? t('bonus_prorata_note', {total: b.group_days}) : t('bonus_full_note'))}</p></section>`;
            }
        }
        body += `<p class="kiosk-muted kv-small kb-basis">${esc(t('bonus_basis', {rate: fmt(b.rate)}))}</p>`;
        el.innerHTML = personHeader() + tabs('bonus') + body;
        if (message) toast(message);
    }

    // Koniec dnia : assistant en 6 étapes — 1 productions sans tous leurs lots matières → 2 sans contrôle qualité (OK
    // en un geste ou NC) → 3 pertes → 4 machines encore en marche → 5 validation (note obligatoire s'il reste des
    // manques) → 6 sortie (questions et signature, comme d'habitude). Une action ouverte depuis une étape (lots, NC,
    // perte) revient à cette étape (resumeEndDay).
    let resumeEndDay = null;
    const EOD_STEPS = 6;
    async function renderEndDay(day, me, step) {
        resetIdle();
        longIdle = true;
        const steps = Array.from({length: EOD_STEPS}, (_, k) => k + 1);
        const bar = `<ol class="kiosk-eod-steps">${steps.map(n => `<li class="${n === step ? 'is-on' : (n < step ? 'is-done' : '')}"><span>${n}</span>${esc(t('eod_step_' + n))}</li>`).join('')}</ol>`;
        const batchesById = {};
        day.items.forEach(i => i.batches.forEach(b => { batchesById[b.id] = {...b, item: i}; }));
        const nav = (back, next, nextLabel) => `<div class="kiosk-row is-end">
            <button type="button" class="kiosk-btn" data-eod-back>${esc(back ? t('back') : t('cancel'))}</button>
            ${next ? `<button type="button" class="kiosk-btn is-primary" data-eod-next>${esc(nextLabel || t('eod_next'))}</button>` : ''}</div>`;
        let body = '';
        let q = null, mach = null;
        if (step === 1) {
            const rows = day.gaps.lots.map(id => batchesById[id]).filter(Boolean);
            body = `<p class="kiosk-muted">${esc(t('eod_lots_help'))}</p>` + (rows.length ? `<ul class="kiosk-qc">${rows.map(b => `<li class="kiosk-qc-row">
                <div><strong>${esc(b.item.name)}</strong> · ${esc(b.lot_code)}<div class="kiosk-muted">${esc(t('prod_lots', {done: b.lots_done ?? 0, total: b.lots_total ?? 0}))} · ${esc(hhmm(b.created_at))}</div></div>
                <button type="button" class="kiosk-btn is-ok" data-eod-lots="${b.id}">${icon('pencil')} ${esc(t('eod_fill'))}</button></li>`).join('')}</ul>`
                : `<p class="kiosk-status">${icon('circle-check', 'is-sm')} ${esc(t('eod_lots_ok'))}</p>`);
        } else if (step === 2) {
            try { q = await api('GET', '/kiosk/quality', undefined, true); } catch (err) { toast(err.message, true); return; }
            const todo = q.batches.filter(b => !b.qc);
            body = `<p class="kiosk-muted">${esc(t('eod_qc_help'))}</p>` + (todo.length ? `<ul class="kiosk-qc">${todo.map(b => `<li class="kiosk-qc-row">
                <div><strong>${esc(b.name)}</strong> · ${esc(b.lot_code)}<div class="kiosk-muted">${esc(qty(b.quantity, b))} · ${esc(hhmm(b.created_at))}</div></div>
                <div class="kiosk-qc-actions"><button type="button" class="kiosk-btn is-ok" data-eod-qc-ok="${b.id}">${icon('check')} ${esc(t('qc_ok'))}</button>
                <button type="button" class="kiosk-btn is-danger-soft is-small" data-eod-qc-nc="${b.id}">${icon('x')} ${esc(t('qc_nc'))}</button></div></li>`).join('')}</ul>`
                : `<p class="kiosk-status">${icon('circle-check', 'is-sm')} ${esc(t('eod_qc_ok'))}</p>`);
        } else if (step === 3) {
            const withGood = day.items.filter(i => i.good > 0);
            const lost = day.items.filter(i => i.waste > 0);
            body = `<p class="kiosk-muted">${esc(t('eod_waste_help'))}</p>` + (lost.length ? `<table class="kiosk-table"><tbody>${lost.map(i => `<tr><td>${esc(i.name)}</td><td><strong>${esc(qty(i.waste, i))}</strong></td>
                <td class="kiosk-muted">${esc(i.wastes.filter(w => !w.cancelled).map(w => t('waste_' + w.reason)).join(', '))}</td></tr>`).join('')}</tbody></table>`
                : `<p class="kiosk-status">${esc(t('eod_waste_none'))}</p>`)
                + (withGood.length ? `<div class="kiosk-row">${withGood.map(i => `<button type="button" class="kiosk-btn is-danger-soft is-small" data-eod-waste="${i.item_id}">− ${esc(i.name)}</button>`).join('')}</div>` : '');
        } else if (step === 4) {
            try { mach = await api('GET', '/kiosk/machines', undefined, true); } catch (err) { mach = {machines: []}; }
            const on = mach.machines.filter(m => m.state);
            body = `<p class="kiosk-muted">${esc(t('eod_mach_help'))}</p>` + (on.length ? `<ul class="kiosk-qc">${on.map(m => `<li class="kiosk-qc-row">
                <div><strong>${esc(m.name)}</strong><div class="kiosk-muted">${m.state.state === 'run' ? esc(t('mach_running', {time: hhmm(m.state.since)})) : esc(t('mach_stopped', {reason: t('reason.' + m.state.reason), time: hhmm(m.state.since)}))}</div></div>
                <button type="button" class="kiosk-btn" data-eod-off="${m.id}">${icon('square')} ${esc(t('mach_off'))}</button></li>`).join('')}</ul>`
                : `<p class="kiosk-status">${icon('circle-check', 'is-sm')} ${esc(t('eod_mach_ok'))}</p>`);
        } else if (step === 5) {
            const short = day.items.filter(i => i.planned !== null && i.produced + 1e-6 < i.planned);
            const gaps = day.gaps.lots.length || day.gaps.qc.length;
            body = `<p class="kiosk-muted">${esc(t('day_close_help'))}</p>
                <table class="kiosk-table"><thead><tr><th>${esc(t('day_col_product'))}</th><th>${esc(t('day_col_planned'))}</th><th>${esc(t('day_col_done'))}</th><th>${esc(t('day_col_waste'))}</th><th>${esc(t('day_col_good'))}</th></tr></thead>
                <tbody>${day.items.map(i => `<tr class="${i.planned !== null && i.produced + 1e-6 < i.planned ? 'is-short' : ''}"><td>${esc(i.name)}</td><td>${i.planned !== null ? esc(qty(i.planned, i)) : '—'}</td>
                    <td>${esc(qty(i.produced, i))}</td><td>${i.waste ? esc(qty(i.waste, i)) : '—'}</td><td><strong>${esc(qty(i.good, i))}</strong></td></tr>`).join('')}</tbody></table>
                ${short.length ? `<p class="kiosk-error">${icon('triangle-alert', 'is-sm')} ${esc(t('day_short', {n: short.length}))}</p>` : ''}
                ${gaps ? `<p class="kiosk-error">${icon('triangle-alert', 'is-sm')} ${esc(t('eod_gaps', {lots: day.gaps.lots.length, qc: day.gaps.qc.length}))}</p>` : ''}
                <form class="kiosk-form" data-close-form><label class="is-wide">${esc(t(gaps ? 'eod_note_required' : 'day_note'))}<input name="note" maxlength="255" value="${esc(day.closed && day.closed.note || '')}" ${gaps ? 'required' : ''}></label></form>
                <p class="kiosk-error" data-error></p>`;
        } else {
            body = `<p class="kiosk-status">${icon('circle-check', 'is-sm')} ${esc(t('eod_done'))}</p>
                ${me && me.clocked_in ? `<p class="kiosk-muted">${esc(t('eod_exit_help'))}</p>
                <button type="button" class="kiosk-btn kiosk-big is-danger" data-eod-exit><span class="kiosk-big-icon">${icon('log-out')}</span>${esc(t('clock_out'))}</button>` : ''}
                <div class="kiosk-row is-end"><button type="button" class="kiosk-btn" data-eod-production>${esc(t('eod_back_production'))}</button></div>`;
        }
        el.innerHTML = header() + `<section class="kiosk-card kiosk-eod"><h2>${icon('moon')} ${esc(t('eod_title', {group: day.group.name}))}</h2>${bar}
            <h3 class="kiosk-eod-step">${esc(t('eod_step_' + step))}</h3>${body}
            ${step < 5 ? nav(step > 1, true) : (step === 5 ? nav(true, true, t('day_confirm')) : '')}</section>`;
        const go = (n) => renderEndDay(day, me, n);
        const reload = async (n) => {
            try { day = await api('GET', '/kiosk/production', undefined, true); } catch (err) { toast(err.message, true); return; }
            go(n);
        };
        el.querySelector('[data-eod-back]')?.addEventListener('click', () => step > 1 ? go(step - 1) : drawProduction(day, me));
        el.querySelector('[data-eod-production]')?.addEventListener('click', () => renderProduction());
        el.querySelectorAll('[data-eod-lots]').forEach(b => b.addEventListener('click', () => { resumeEndDay = 1; renderBatch(Number(b.dataset.eodLots)); }));
        el.querySelectorAll('[data-eod-qc-ok]').forEach(b => b.addEventListener('click', async () => {
            b.disabled = true;
            try { await api('POST', `/kiosk/quality/batches/${b.dataset.eodQcOk}`, {result: 'ok'}, true); toast(t('qc_saved_ok')); reload(2); }
            catch (err) { b.disabled = false; toast(err.offline ? t('offline_retry') : err.message, true); }
        }));
        el.querySelectorAll('[data-eod-qc-nc]').forEach(b => b.addEventListener('click', () => { resumeEndDay = 2; renderNcForm(q, q.batches.find(x => x.id === Number(b.dataset.eodQcNc))); }));
        el.querySelectorAll('[data-eod-waste]').forEach(b => b.addEventListener('click', () => { resumeEndDay = 3; renderWaste(day.items.find(i => i.item_id === Number(b.dataset.eodWaste))); }));
        el.querySelectorAll('[data-eod-off]').forEach(b => b.addEventListener('click', async () => {
            const m = mach.machines.find(x => x.id === Number(b.dataset.eodOff));
            if (!window.confirm(t('mach_off_confirm', {name: m.name}))) return;
            b.disabled = true;
            try { await api('POST', `/kiosk/machines/${m.id}/state`, {state: 'off', client_uuid: uuid()}, true); toast(t('mach_saved_off', {name: m.name})); go(4); }
            catch (err) { b.disabled = false; toast(err.offline ? t('offline_retry') : err.message, true); }
        }));
        el.querySelector('[data-close-form]')?.addEventListener('input', resetIdle);
        el.querySelector('[data-eod-exit]')?.addEventListener('click', (e) => validateClock(me, 'out', e.currentTarget));
        el.querySelector('[data-eod-next]')?.addEventListener('click', async (e) => {
            if (step < 5) return step === 1 || step === 2 ? reload(step + 1) : go(step + 1);
            const note = el.querySelector('[name=note]').value.trim();
            if ((day.gaps.lots.length || day.gaps.qc.length) && !note) { el.querySelector('[data-error]').textContent = t('eod_note_missing'); el.querySelector('[name=note]').focus(); return; }
            e.target.disabled = true;
            try {
                day = await api('POST', '/kiosk/production/close', {date: day.date, note: note || null}, true);
                toast(t('day_closed_done'));
                go(6);
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
        const components = r.components || [];
        const tile = (i) => `<button type="button" class="kiosk-card kiosk-recipe-tile" data-recipe="${i.item_id}"><strong>${esc(i.name)}</strong>
            <span class="kiosk-muted">${i.planned ? esc(t('prod_planned', {q: qty(i.planned, i)})) : esc(t(i.type === 'semi' ? 'rec_semi' : 'rec_product'))}</span></button>`;
        const compTile = (c) => `<button type="button" class="kiosk-card kiosk-recipe-tile is-component" data-component="${c.id}"><strong>${esc(c.name)}</strong>
            <span class="kiosk-muted">${c.for_groups ? esc(t('rec_for_groups', {groups: c.for_groups})) : esc(t('rec_component'))}</span></button>`;
        el.innerHTML = personHeader() + tabs('recipes') + `
            <section class="kiosk-card"><h2>${esc(t('rec_title'))}</h2><p class="kiosk-muted">${esc(t('rec_help'))}</p>
                <input class="kiosk-search" type="search" data-rec-search placeholder="${esc(t('rec_search'))}" autocomplete="off"></section>
            ${today.length ? `<h2 class="kiosk-section">${esc(t('rec_today'))}</h2><div class="kiosk-recipe-grid">${today.map(tile).join('')}</div>` : ''}
            ${components.length ? `<h2 class="kiosk-section">${icon('users')} ${esc(t('rec_components'))}</h2><div class="kiosk-recipe-grid">${components.map(compTile).join('')}</div>` : ''}
            ${rest.length ? `<h2 class="kiosk-section">${esc(t('rec_all'))}</h2><div class="kiosk-recipe-grid">${rest.map(tile).join('')}</div>` : ''}
            ${r.items.length || components.length ? '' : `<section class="kiosk-card"><p class="kiosk-muted">${esc(t('rec_none'))}</p></section>`}`;
        if (message) toast(message);
        el.querySelectorAll('[data-recipe]').forEach(b => b.addEventListener('click', () => renderRecipe(Number(b.dataset.recipe))));
        el.querySelectorAll('[data-component]').forEach(b => b.addEventListener('click', () => renderRecipe(Number(b.dataset.component), true)));
        el.querySelector('[data-rec-search]').addEventListener('input', (e) => {
            resetIdle();
            const q = e.target.value.trim().toLowerCase();
            el.querySelectorAll('[data-recipe], [data-component]').forEach(b => { b.hidden = q !== '' && !b.textContent.toLowerCase().includes(q); });
        });
    }

    // Ilość : gramy / mililitry od 1000 jako kg / l.
    const measure = (q, unit) => {
        if ((unit === 'g' || unit === 'ml') && Math.abs(q) >= 1000) return `${amount(q / 1000)} ${unit === 'g' ? 'kg' : 'l'}`;
        return `${amount(q)} ${unit || ''}`.trim();
    };

    // Grupa, która przygotowuje półprodukt (inna grupa : wyróżniona).
    const producerBadge = (p) => p ? `<span class="kiosk-rec-group ${p.other ? 'is-other' : ''}" style="--g:${esc(p.color || '#8D1D2C')}">${icon('users', 'is-sm')} ${esc(p.other ? t('rec_made_by', {group: p.name}) : t('rec_made_here', {group: p.name}))}</span>` : '';
    const lineRow = (l, f) => `<li ${l.material_id ? `data-mat="${l.material_id}"` : ''}><span>${esc(l.name)}</span><span class="kiosk-rec-q">${esc(measure(l.quantity * f, l.unit))}</span></li>`;

    // Półprodukt / kompozycja : karta z ilością, grupą, etapami przygotowania i składnikami (zagnieżdżone półprodukty niżej).
    // Przygotowuje inna grupa : karta zwinięta, bo grupa bierze gotowe.
    function recBlock(l, f, depth) {
        const n = l.node;
        const materials = n ? n.lines.filter(x => x.kind === 'material' && !x.packaging) : [];
        const subs = n ? n.lines.filter(x => x.kind === 'recipe') : [];
        const other = l.producer && l.producer.other;
        return `<details class="kiosk-rec-block ${depth ? 'is-nested' : ''} ${other ? 'is-other' : ''}" ${other ? '' : 'open'}>
            <summary><span class="kiosk-rec-title">${esc(l.name)}</span>${producerBadge(l.producer)}<span class="kiosk-rec-q">${esc(measure(l.quantity * f, l.unit))}</span></summary>
            <div class="kiosk-rec-block-body">
                ${other ? `<p class="kiosk-rec-note">${icon('users', 'is-sm')} ${esc(t('rec_ready_from', {group: l.producer.name}))}</p>` : ''}
                ${n && n.steps.length ? `<ol class="kiosk-rec-steps">${n.steps.map(st => `<li>${esc(st)}</li>`).join('')}</ol>` : ''}
                ${materials.length ? `<ul class="kiosk-rec-lines">${materials.map(x => lineRow(x, f)).join('')}</ul>` : ''}
                ${subs.map(x => recBlock(x, f, depth + 1)).join('')}
                ${!n ? `<p class="kiosk-muted">${esc(t('rec_no_tree'))}</p>` : ''}
            </div></details>`;
    }

    // Drzewo receptury : półprodukty jako karty, potem surowce pierwszego poziomu i opakowania.
    function recTree(tree, f) {
        const subs = tree.lines.filter(l => l.kind === 'recipe');
        const materials = tree.lines.filter(l => l.kind === 'material' && !l.packaging);
        const pack = tree.lines.filter(l => l.kind === 'material' && l.packaging);
        return `${tree.steps.length ? `<section class="kiosk-card"><h2>${icon('list-ordered')} ${esc(t('rec_preparation'))}</h2><ol class="kiosk-rec-steps">${tree.steps.map(st => `<li>${esc(st)}</li>`).join('')}</ol></section>` : ''}
            ${subs.length ? `<section class="kiosk-card"><h2>${esc(t('rec_components_of'))}</h2>${subs.map(l => recBlock(l, f, 0)).join('')}</section>` : ''}
            ${materials.length ? `<section class="kiosk-card"><h2>${esc(t('rec_materials'))}</h2><ul class="kiosk-rec-lines">${materials.map(l => lineRow(l, f)).join('')}</ul></section>` : ''}
            ${pack.length ? `<section class="kiosk-card"><details class="kiosk-rec-pack"><summary>${esc(t('lot_packaging', {n: pack.length}))}</summary>
                <ul class="kiosk-rec-lines">${pack.map(l => lineRow(l, f)).join('')}</ul></details></section>` : ''}`;
    }

    // Zdjęcie na cały ekran (dotknięcie zamyka).
    function zoom(src) {
        const box = document.createElement('div');
        box.className = 'kiosk-zoom';
        box.innerHTML = `<img src="${src}" alt="">`;
        box.addEventListener('click', () => box.remove());
        document.body.appendChild(box);
    }

    async function renderRecipe(id, component) {
        longIdle = true;
        resetIdle();
        let r;
        try { r = await api('GET', component ? `/kiosk/recipes/components/${id}` : `/kiosk/recipes/${id}`, undefined, true); } catch (err) { toast(err.message, true); return; }
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
        const media = r.media || {photo: null, steps: []};
        const draw = () => {
            el.querySelector('[data-rec-tree]').innerHTML = recTree(r.tree, factor);
            const pieces = r.basis === 'carton' ? factor * r.ppc : null;
            el.querySelector('[data-rec-for]').textContent = r.basis === 'carton' ? t('rec_for_pieces', {q: qty(pieces, r)})
                : (r.basis === 'kg' ? t('rec_for_kg', {q: fmt(factor)}) : t('rec_for_times', {q: fmt(factor)}));
        };
        const meta = [r.ppc ? t('rec_ppc', {ppc: r.ppc}) : null, component ? t('rec_component') : null].filter(Boolean).join(' · ');
        el.innerHTML = header(`<button type="button" class="kiosk-btn is-small kiosk-back" data-back>‹ ${esc(t('back'))}</button>`, true) + `
            <section class="kiosk-card kiosk-rec-hero ${media.photo ? '' : 'no-photo'}">
                ${media.photo ? `<img class="kiosk-rec-photo" src="${media.photo}" alt="${esc(r.name)}" data-zoom>` : (component ? '' : `<div class="kiosk-rec-photo is-empty" aria-hidden="true">${icon('image')}</div>`)}
                <div class="kiosk-rec-hero-body"><h2>${esc(r.name)}</h2>
                    ${meta ? `<p class="kiosk-muted">${esc(meta)}</p>` : ''}${r.producer ? `<p>${producerBadge(r.producer)}</p>` : ''}
                    <p class="kiosk-rec-ask">${esc(t('rec_how_much'))}</p>
                    <div class="kiosk-row kiosk-rec-presets" data-presets>${presets.map(p => `<button type="button" class="kiosk-btn" data-f="${p.f}">${esc(p.label)}</button>`).join('')}
                        <label class="kiosk-rec-custom">${esc(customLabel)}<input data-custom inputmode="decimal"></label></div>
                    <p class="kiosk-status" data-rec-for></p></div></section>
            ${media.steps.length ? `<section class="kiosk-card"><h2>${icon('camera')} ${esc(t('rec_steps_photos'))}</h2><div class="kiosk-rec-stepgrid">${media.steps.map(st =>
                `<figure class="kiosk-rec-step">${st.src ? `<img src="${st.src}" alt="" data-zoom>` : `<div class="kiosk-rec-step-empty">${icon('image')}</div>`}
                    <figcaption><span class="kiosk-rec-stepno">${st.no}</span><span>${esc(st.text)}</span></figcaption></figure>`).join('')}</div></section>` : ''}
            <div class="kiosk-rec-tree" data-rec-tree></div>
            ${r.routing.length ? `<section class="kiosk-card"><h2>${esc(t('rec_routing'))}</h2><ol class="kiosk-rec-steps">${r.routing.map(st =>
                `<li><strong>${esc(st.name)}</strong>${st.workstation ? ' · ' + esc(st.workstation) : ''}${st.temperature_c !== null ? ' · ' + esc(fmt(st.temperature_c)) + ' °C' : ''}</li>`).join('')}</ol></section>` : ''}`;
        const mark = () => el.querySelectorAll('[data-f]').forEach(b => b.classList.toggle('is-primary', Math.abs(Number(b.dataset.f) - factor) < 1e-9));
        el.querySelector('[data-back]').addEventListener('click', () => renderRecipes());
        el.addEventListener('click', (e) => { const img = e.target.closest('[data-zoom]'); if (img) zoom(img.src); });
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

    // --- Nowe produkty : rozwój od pomysłu do wprowadzenia. Test (zdjęcia, ilość, wynik, co zmienić) i receptura
    // potwierdzane tutaj JEDEN raz — biuro widzi wynik bez ponownego wpisywania. Karta : etap, testy, notatki, film,
    // zdjęcia wzorcowe, receptura. Tablet widzi tylko produkty swojej grupy. -------------------------------------------
    const dateFmt = (d) => d ? d.split('-').reverse().join('.') : '';
    const dayTime = (s) => s ? `${dateFmt(String(s).slice(0, 10)).slice(0, 5)} ${hhmm(s)}` : '';
    const RESULT_CLS = {ok: 'is-in', fix: 'is-warn', ko: 'is-bad'};
    const NEXT_ICON = {trial: 'flask-conical', recipe: 'book-open', office: 'hourglass'};
    const devProgress = (dev) => dev.step ? `<span class="kiosk-dev-progress" aria-hidden="true"><span style="width:${Math.round(dev.step / dev.steps * 100)}%"></span></span>` : '';
    const devNext = (dev) => dev.next ? `<span class="kiosk-dev-next ${dev.next.kind === 'office' ? '' : 'is-action'}">${icon(NEXT_ICON[dev.next.kind], 'is-sm')} ${esc(dev.next.label)}</span>` : '';

    async function renderDev(message) {
        longIdle = true;
        resetIdle();
        let d;
        try { d = await api('GET', '/kiosk/development', undefined, true); } catch (err) { if (!err.code || err.code === 'DEVICE_WITHOUT_GROUP') toast(err.message, true); return; }
        if (session) session.dev = d.products.length;
        el.innerHTML = personHeader() + tabs('dev') + `
            <section class="kiosk-card"><h2>${esc(t('dev_title'))}</h2><p class="kiosk-muted">${esc(t('dev_help'))}</p></section>
            ${d.products.length ? `<div class="kiosk-recipe-grid">${d.products.map(p => `<button type="button" class="kiosk-card kiosk-recipe-tile kiosk-dev-tile" data-dev="${p.id}">
                <span class="kiosk-dev-stage">${esc(p.dev.stage_label)}</span>
                <strong>${esc(p.name)}</strong>
                ${devProgress(p.dev)}${devNext(p.dev)}
                <span class="kiosk-dev-badges">${p.dev.last_result ? `<span class="kiosk-pill ${RESULT_CLS[p.dev.last_result.code]}">${esc(p.dev.last_result.label)}</span>` : ''}${p.recipe_id ? icon('book-open') : ''}${p.has_video ? icon('clapperboard') : ''}${p.photo_count ? icon('image') + '<small>' + p.photo_count + '</small>' : ''}${p.has_notes ? icon('notebook-pen') : ''}</span>
                ${p.dev.target_date ? `<span class="kiosk-muted">${esc(t('dev_target', {date: dateFmt(p.dev.target_date)}))}</span>` : ''}</button>`).join('')}</div>`
                : `<section class="kiosk-card"><p class="kiosk-muted">${esc(t('dev_none'))}</p></section>`}`;
        if (message) toast(message);
        el.querySelectorAll('[data-dev]').forEach(b => b.addEventListener('click', () => renderDevProduct(Number(b.dataset.dev))));
    }

    async function renderDevProduct(id, message) {
        longIdle = true;
        resetIdle();
        let p;
        try { p = await api('GET', `/kiosk/development/${id}`, undefined, true); } catch (err) { toast(err.message, true); return; }
        let factor = 1;
        const dev = p.dev;
        const planned = p.trials.find(tr => tr.status === 'planned');
        const done = p.trials.filter(tr => tr.status === 'done');
        const v = p.video;
        const video = !v ? '' : (v.embed
            ? `<div class="kiosk-dev-video"><iframe src="${esc(v.embed)}" title="${esc(p.name)}" allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen loading="lazy"></iframe></div>`
            : (v.kind === 'file' ? `<video class="kiosk-dev-file" src="${esc(v.url)}" controls playsinline preload="metadata"></video>` : ''))
            + `<p><a class="kiosk-btn is-small" href="${esc(v.url)}" target="_blank" rel="noopener">${icon('play')} ${esc(t('dev_open_video'))}</a></p>`;
        // Następny krok : test (aparat), receptura do zatwierdzenia albo czekamy na biuro.
        let action = '';
        if (dev.next && dev.next.kind === 'trial') {
            action = `<button type="button" class="kiosk-btn is-primary kiosk-dev-cta" data-trial>${icon('camera')} ${esc(dev.next.label)}</button>`;
        } else if (dev.next && dev.next.kind === 'recipe') {
            action = (p.recipe_id ? `<button type="button" class="kiosk-btn is-ok kiosk-dev-cta" data-confirm-recipe>${icon('circle-check')} ${esc(t('dev_confirm_recipe'))}</button>`
                : `<p class="kiosk-status">${icon('triangle-alert')} ${esc(t('dev_recipe_missing'))}</p>`)
                + `<button type="button" class="kiosk-btn is-small" data-trial>${icon('flask-conical')} ${esc(t('dev_another_trial'))}</button>`;
        } else if (dev.next) {
            action = `<p class="kiosk-status">${icon('hourglass')} ${esc(t('dev_wait_office'))}</p>`;
        }
        const photosOf = {};
        done.forEach(tr => tr.photos.forEach(ph => { photosOf[ph.id] = ph.src; }));
        p.photos.forEach(ph => { photosOf['ref' + ph.id] = ph.src; });
        el.innerHTML = header(`<button type="button" class="kiosk-btn is-small kiosk-back" data-back>‹ ${esc(t('back'))}</button>`, true) + `
            <section class="kiosk-card kiosk-dev-hero"><span class="kiosk-dev-stage">${esc(dev.stage_label)}</span><h2>${esc(p.name)}</h2>
                <p class="kiosk-muted">${esc([...new Set([dev.group && dev.group.name, p.section, dev.target_date && t('dev_target', {date: dateFmt(dev.target_date)})].filter(Boolean))].join(' · '))}</p>
                <ol class="kiosk-dev-flow">${p.flow.map((f, i) => `<li class="is-${f.state}"><span>${f.state === 'done' ? icon('check', 'is-sm') : i + 1}</span><em>${esc(f.label)}</em></li>`).join('')}</ol>
                ${planned ? `<div class="kiosk-dev-brief"><strong>${icon('notebook-pen', 'is-sm')} ${esc(t('dev_brief', {n: planned.no}))}${planned.planned_date ? ' · ' + esc(dateFmt(planned.planned_date)) : ''}</strong>
                    ${planned.instructions ? `<p class="kiosk-dev-text">${esc(planned.instructions)}</p>` : ''}</div>` : ''}
                ${p.recipe_confirmed ? `<p class="kiosk-status kiosk-dev-ok">${icon('circle-check')} ${esc(t('dev_recipe_confirmed', {date: dayTime(p.recipe_confirmed.at), name: p.recipe_confirmed.by || '—'}))}</p>` : ''}
                ${action ? `<div class="kiosk-row kiosk-dev-actions">${action}</div>` : ''}</section>
            ${done.length ? `<section class="kiosk-card"><h2>${icon('flask-conical')} ${esc(t('dev_trials', {n: done.length}))}</h2><div class="kiosk-dev-trials">${done.slice().reverse().map(tr => `<article class="kiosk-dev-trial">
                <div class="kiosk-dev-trial-head"><strong>${esc(t('dev_trial_no', {n: tr.no}))}</strong><span class="kiosk-pill ${RESULT_CLS[tr.result] || ''}">${esc(tr.result_label || '')}</span>
                <span class="kiosk-muted">${esc([dayTime(tr.done_at), tr.employee, tr.quantity !== null ? t('dev_qty', {q: fmt(tr.quantity)}) : ''].filter(Boolean).join(' · '))}</span></div>
                ${tr.instructions ? `<p class="kiosk-muted kiosk-dev-text">${esc(t('dev_brief_short'))} ${esc(tr.instructions)}</p>` : ''}
                ${tr.notes ? `<p class="kiosk-dev-text">${esc(tr.notes)}</p>` : ''}
                ${tr.photos.length ? `<div class="kiosk-dev-photos is-small">${tr.photos.map(ph => `<button type="button" class="kiosk-dev-photo" data-zoom="${ph.id}"><img src="${ph.src}" alt="${esc(t('dev_trial_no', {n: tr.no}))}"><span>${esc(hhmm(ph.taken_at))}</span></button>`).join('')}</div>` : ''}
                </article>`).join('')}</div></section>` : ''}
            ${p.notes ? `<section class="kiosk-card"><h2>${icon('notebook-pen')} ${esc(t('dev_notes'))}</h2><p class="kiosk-dev-text">${esc(p.notes)}</p></section>` : ''}
            ${v ? `<section class="kiosk-card"><h2>${icon('clapperboard')} ${esc(t('dev_video'))}</h2>${video}</section>` : ''}
            ${p.photos.length ? `<section class="kiosk-card"><h2>${icon('image')} ${esc(t('dev_photos', {n: p.photos.length}))}</h2><div class="kiosk-dev-photos">${p.photos.map(ph =>
                `<button type="button" class="kiosk-dev-photo" data-zoom="ref${ph.id}"><img src="${ph.src}" alt="${esc(ph.caption || p.name)}">${ph.caption ? `<span>${esc(ph.caption)}</span>` : ''}</button>`).join('')}</div></section>` : ''}
            ${p.recipe ? `<section class="kiosk-card"><h2>${icon('book-open')} ${esc(t('dev_recipe', {name: p.recipe.name}))}</h2>
                <div class="kiosk-row kiosk-rec-presets"><label class="kiosk-rec-custom">${esc(t('rec_custom_times'))}<input data-times inputmode="decimal" value="1"></label></div>
                <p class="kiosk-status" data-dev-for></p></section><div class="kiosk-rec-tree" data-dev-tree></div>`
                : `<section class="kiosk-card"><p class="kiosk-muted">${esc(t('dev_no_recipe'))}</p></section>`}
            ${p.stages.length ? `<section class="kiosk-card"><details><summary>${esc(t('dev_history', {n: p.stages.length}))}</summary><ul class="kiosk-dev-stages">${p.stages.map(s =>
                `<li><strong>${esc(s.name || '')}</strong>${s.deadline ? ' · ' + esc(dateFmt(s.deadline)) : ''}${s.description ? `<div class="kiosk-muted">${esc(s.description)}</div>` : ''}</li>`).join('')}</ul></details></section>` : ''}`;
        if (message) toast(message);
        el.querySelector('[data-back]').addEventListener('click', () => renderDev());
        el.querySelectorAll('[data-trial]').forEach(b => b.addEventListener('click', () => renderDevTrial(p, planned)));
        el.querySelector('[data-confirm-recipe]')?.addEventListener('click', () => confirmDevRecipe(p));
        const draw = () => {
            if (!p.recipe) return;
            el.querySelector('[data-dev-tree]').innerHTML = recTree(p.recipe, factor);
            el.querySelector('[data-dev-for]').textContent = t('dev_for', {q: fmt(factor), yield: measure(p.recipe.yield * factor, p.recipe.unit)});
        };
        el.querySelector('[data-times]')?.addEventListener('input', (e) => {
            resetIdle();
            const n = Number(String(e.target.value).replace(',', '.').replace(/\s/g, ''));
            if (Number.isFinite(n) && n > 0) { factor = n; draw(); }
        });
        el.querySelectorAll('[data-zoom]').forEach(b => b.addEventListener('click', () => { resetIdle(); zoom(photosOf[b.dataset.zoom]); }));
        draw();
    }

    // Receptura zatwierdzona przez zespół (po udanym teście 2) : potwierdzenie i zapis z nazwiskiem osoby.
    function confirmDevRecipe(p) {
        const box = document.createElement('div');
        box.className = 'kiosk-sheet-backdrop';
        box.innerHTML = `<div class="kiosk-sheet" role="dialog" aria-modal="true"><h2>${icon('circle-check')} ${esc(t('dev_confirm_recipe'))}</h2>
            <p class="kiosk-dev-text">${esc(t('dev_confirm_recipe_help', {name: p.recipe_name || p.name}))}</p>
            <div class="kiosk-row"><button type="button" class="kiosk-btn" data-cancel>${esc(t('cancel'))}</button><button type="button" class="kiosk-btn is-ok" data-ok>${icon('check')} ${esc(t('dev_confirm_yes'))}</button></div></div>`;
        document.body.appendChild(box);
        const close = () => box.remove();
        box.addEventListener('click', (e) => { if (e.target === box) close(); });
        box.querySelector('[data-cancel]').addEventListener('click', close);
        box.querySelector('[data-ok]').addEventListener('click', async (e) => {
            const btn = e.currentTarget;
            btn.disabled = true;
            try {
                await api('POST', `/kiosk/development/${p.id}/recipe`, {}, true);
                close();
                renderDevProduct(p.id, t('dev_recipe_saved'));
            } catch (err) {
                btn.disabled = false;
                if (!session) close(); else toast(err.message, true);
            }
        });
    }

    // Zdjęcie testu : zmniejszone (najdłuższy bok 1280 px), data, godzina i nazwa produktu wpisane w obraz.
    async function devShot(file, caption) {
        const taken = file.lastModified && Math.abs(Date.now() - file.lastModified) < 6 * 3600 * 1000 ? new Date(file.lastModified) : new Date();
        const img = await loadImage(file);
        const scale = Math.min(1, 1280 / Math.max(img.width, img.height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(img.width * scale);
        canvas.height = Math.round(img.height * scale);
        const ctx = canvas.getContext('2d');
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
        const band = Math.max(26, Math.round(canvas.height * 0.06));
        ctx.fillStyle = 'rgba(0, 0, 0, 0.6)';
        ctx.fillRect(0, canvas.height - band, canvas.width, band);
        ctx.fillStyle = '#fff';
        ctx.font = `600 ${Math.round(band * 0.5)}px system-ui, sans-serif`;
        ctx.textBaseline = 'middle';
        const pad = (n) => String(n).padStart(2, '0');
        const local = `${taken.getFullYear()}-${pad(taken.getMonth() + 1)}-${pad(taken.getDate())} ${pad(taken.getHours())}:${pad(taken.getMinutes())}:${pad(taken.getSeconds())}`;
        ctx.fillText(`${pad(taken.getDate())}.${pad(taken.getMonth() + 1)}.${taken.getFullYear()} ${pad(taken.getHours())}:${pad(taken.getMinutes())} · ${caption}`, Math.round(band * 0.4), canvas.height - band / 2, canvas.width - band);
        return {image: canvas.toDataURL('image/jpeg', 0.75), taken_at: local};
    }

    // Test na tablecie : zdjęcia (aparat), ilość, wynik (OK / do poprawy / nie), co zmienić → jeden zapis.
    function renderDevTrial(p, planned) {
        longIdle = true;
        resetIdle();
        const no = planned ? planned.no : (p.trials.length ? Math.max(...p.trials.map(tr => tr.no)) + 1 : 1);
        const max = p.max_trial_photos || 6;
        const shots = [];
        let result = null;
        el.innerHTML = header(`<button type="button" class="kiosk-btn is-small kiosk-back" data-back>‹ ${esc(t('back'))}</button>`, true) + `
            <section class="kiosk-card kiosk-dev-hero"><span class="kiosk-dev-stage">${esc(t('dev_trial_no', {n: no}))}</span><h2>${esc(p.name)}</h2>
                ${planned && planned.instructions ? `<div class="kiosk-dev-brief"><strong>${icon('notebook-pen', 'is-sm')} ${esc(t('dev_brief', {n: no}))}</strong><p class="kiosk-dev-text">${esc(planned.instructions)}</p></div>` : ''}
                <p class="kiosk-muted">${esc(t('dev_trial_help'))}</p></section>
            <section class="kiosk-card"><h2>${icon('camera')} ${esc(t('dev_trial_photos'))} <span class="kiosk-muted" data-count></span></h2>
                <div class="kiosk-dev-shots" data-shots></div>
                <label class="kiosk-btn is-primary kiosk-photo" data-add-photo>${icon('camera')} ${esc(t('dev_take_photo'))}<input type="file" accept="image/*" capture="environment" multiple data-photo hidden></label></section>
            <section class="kiosk-card"><form class="kiosk-form" data-trial-form>
                <label>${esc(t('dev_trial_qty'))}<input name="quantity" inputmode="decimal" autocomplete="off"></label>
                <div class="is-wide"><span class="kiosk-label">${esc(t('dev_trial_result'))}</span><div class="kiosk-dev-results" role="radiogroup">
                    <button type="button" class="kiosk-btn kiosk-dev-result is-ok-choice" data-result="ok" role="radio" aria-checked="false">${icon('circle-check')} ${esc(t('dev_result_ok'))}</button>
                    <button type="button" class="kiosk-btn kiosk-dev-result is-fix-choice" data-result="fix" role="radio" aria-checked="false">${icon('wrench')} ${esc(t('dev_result_fix'))}</button>
                    <button type="button" class="kiosk-btn kiosk-dev-result is-ko-choice" data-result="ko" role="radio" aria-checked="false">${icon('circle-x')} ${esc(t('dev_result_ko'))}</button></div></div>
                <label class="is-wide"><span data-notes-label>${esc(t('dev_trial_notes'))}</span><textarea name="notes" rows="3" maxlength="2000"></textarea></label>
                <p class="kiosk-error is-wide" data-error></p>
                <div class="kiosk-row is-wide"><button type="submit" class="kiosk-btn is-ok kiosk-dev-cta" data-save>${icon('check')} ${esc(t('dev_trial_save'))}</button></div></form></section>`;
        const form = el.querySelector('[data-trial-form]');
        const errorBox = el.querySelector('[data-error]');
        const drawShots = () => {
            el.querySelector('[data-shots]').innerHTML = shots.map((s, i) => `<figure class="kiosk-dev-shot"><img src="${s.image}" alt=""><figcaption>${esc(hhmm(s.taken_at))}</figcaption>
                <button type="button" class="kiosk-dev-shot-del" data-del="${i}" aria-label="${esc(t('dev_delete_photo'))}">${icon('x', 'is-sm')}</button></figure>`).join('');
            el.querySelector('[data-count]').textContent = `${shots.length} / ${max}`;
            el.querySelector('[data-add-photo]').hidden = shots.length >= max;
        };
        drawShots();
        el.querySelector('[data-back]').addEventListener('click', () => {
            if ((shots.length || result) && !window.confirm(t('dev_trial_leave'))) return;
            renderDevProduct(p.id);
        });
        el.querySelector('[data-shots]').addEventListener('click', (e) => {
            const b = e.target.closest('[data-del]');
            if (b) { shots.splice(Number(b.dataset.del), 1); drawShots(); }
        });
        el.querySelector('[data-photo]').addEventListener('change', async (e) => {
            const files = [...e.target.files].slice(0, max - shots.length);
            e.target.value = '';
            busy = true;
            for (const f of files) {
                try { shots.push(await devShot(f, p.name)); } catch (err) { toast(t('photo_error'), true); }
            }
            busy = false;
            resetIdle();
            drawShots();
        });
        const results = el.querySelectorAll('[data-result]');
        results.forEach(b => b.addEventListener('click', () => {
            result = b.dataset.result;
            results.forEach(x => { x.classList.toggle('is-on', x === b); x.setAttribute('aria-checked', String(x === b)); });
            el.querySelector('[data-notes-label]').textContent = t(result === 'ok' ? 'dev_trial_notes' : 'dev_trial_notes_required');
        }));
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            errorBox.textContent = '';
            if (!shots.length) { errorBox.textContent = t('dev_trial_need_photo'); return; }
            if (!result) { errorBox.textContent = t('dev_trial_need_result'); return; }
            const notes = form.notes.value.trim();
            if (result !== 'ok' && !notes) { errorBox.textContent = t('dev_trial_need_notes'); form.notes.focus(); return; }
            const raw = form.quantity.value.replace(/\s/g, '').replace(',', '.');
            const quantity = raw === '' ? null : Number(raw);
            if (quantity !== null && !(quantity >= 0)) { errorBox.textContent = t('dev_trial_bad_qty'); return; }
            const btn = el.querySelector('[data-save]');
            btn.disabled = true;
            busy = true;
            try {
                await api('POST', `/kiosk/development/${p.id}/trials`, {quantity, result, notes: notes || null, photos: shots}, true);
                busy = false;
                renderDevProduct(p.id, t('dev_trial_saved', {n: no}));
            } catch (err) {
                busy = false;
                btn.disabled = false;
                errorBox.textContent = err.message;
            }
        });
    }

    // --- Maszyny (TRS) : stan maszyny jednym dotknięciem — praca, postój z powodem, koniec pracy ; TRS dnia ----------
    const REASON_ICON = {breakdown: 'wrench', setup: 'settings-2', waiting: 'hourglass', cleaning: 'spray-can', break: 'coffee', other: 'pencil'};
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
        const status = !st ? icon('square') + ' ' + esc(t('mach_off_state')) : (st.state === 'run' ? icon('play') + ' ' + esc(t('mach_running', {time: hhmm(st.since)}))
            : icon(REASON_ICON[st.reason] || 'pause') + ' ' + esc(t('mach_stopped', {reason: t('reason.' + st.reason), time: hhmm(st.since)}) + (st.note ? ' — ' + st.note : '')));
        const tiles = [['availability', 'kpi_availability'], ['performance', 'kpi_performance'], ['quality', 'kpi_quality'], ['oee', 'kpi_oee']];
        return `<article class="kiosk-card kiosk-machine ${cls}" data-machine="${m.id}">
            <div class="kiosk-machine-head"><div><h3>${m.collaborative ? icon('bot', 'is-sm') + ' ' : ''}${esc(m.name)}</h3><div class="kiosk-muted">${esc(m.code)}</div></div>
                <div class="kiosk-machine-state">${status}${st && st.stale ? `<div class="kiosk-error">${icon('triangle-alert', 'is-sm')} ${esc(t('mach_stale'))}</div>` : ''}</div></div>
            <div class="kiosk-machine-actions">
                ${!st || st.state !== 'run' ? `<button type="button" class="kiosk-btn is-ok" data-run>${icon('play')} ${esc(t('mach_run'))}</button>` : ''}
                <button type="button" class="kiosk-btn is-danger-soft" data-stop>${icon('pause')} ${esc(t(st && st.state === 'stop' ? 'mach_stop_change' : 'mach_stop'))}</button>
                ${st ? `<button type="button" class="kiosk-btn" data-off>${icon('square')} ${esc(t('mach_off'))}</button>` : ''}</div>
            <div class="kiosk-reasons" data-reasons hidden>${reasons.map(r => `<button type="button" class="kiosk-btn kiosk-reason" data-reason="${r}">${icon(REASON_ICON[r])} ${esc(t('reason.' + r))}</button>`).join('')}
                <label class="kiosk-reason-note">${esc(t('mach_note'))}<input data-note maxlength="255"></label></div>
            <div class="kiosk-oee">${tiles.map(([k, label]) => `<div class="kiosk-oee-tile ${k === 'oee' ? 'is-main ' : ''}${level(m[k])}"><span>${esc(t(label))}</span><strong>${pct(m[k])}</strong></div>`).join('')}</div>
            <p class="kiosk-muted">${esc(t('mach_details', {run: fmt(m.run_h), stop: fmt(m.stop_h), planned: fmt(m.planned_stop_h), pieces: fmt(m.pieces), ideal: fmt(m.ideal_h)}))}
                ${m.performance_raw !== null && m.performance_raw > 110 ? ' · ' + icon('triangle-alert', 'is-sm') + ' ' + esc(t('mach_perf_over', {pct: fmt(m.performance_raw)})) : ''}</p>
            ${m.has_routing ? '' : `<p class="kiosk-muted">${esc(t('mach_no_routing'))}</p>`}
            ${timeline(m)}
            ${m.stops.length ? `<ul class="kiosk-stops">${m.stops.map(x => `<li>${icon(REASON_ICON[x.reason] || 'pause', 'is-sm')} ${esc(t('reason.' + x.reason))}${x.planned ? ' <span class="kiosk-muted">(' + esc(t('mach_planned')) + ')</span>' : ''} · <strong>${x.minutes < 1 ? '&lt; 1' : fmt(x.minutes)} min</strong></li>`).join('')}</ul>` : ''}
        </article>`;
    }

    function drawMachines(d, message) {
        el.innerHTML = personHeader() + tabs('machines') + `
            <section class="kiosk-card"><h2>${esc(t('mach_title'))}</h2><p class="kiosk-muted">${esc(t('mach_help'))}</p></section>
            ${d.machines.length ? d.machines.map(m => machineCard(m, d.stop_reasons)).join('') : `<section class="kiosk-card"><p class="kiosk-muted">${esc(t('mach_empty'))}</p></section>`}`;
        if (message) toast(message);
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
            `<span class="kiosk-batch-tile ${k < done ? 'is-done' : ''}">${k < done ? icon('check', 'is-sm') + ' ' : ''}${k + 1}/${total}</span>`).join('')}
            <span class="kiosk-muted">${esc(t('prod_batch_size', {size: qty(i.batch, i)}))}</span></div>`;
    }

    // --- Kontrola jakości : każda partia dnia OK albo niezgodność (rejestr niezgodności ERP) -------------
    async function renderQuality(message) {
        // Retour d'une non-conformité ouverte depuis « Koniec dnia » : reprise de l'assistant (via la production du jour).
        if (resumeEndDay) return renderProduction(message);
        longIdle = true;
        resetIdle();
        let q;
        try { q = await api('GET', '/kiosk/quality', undefined, true); } catch (err) { if (!err.code || err.code === 'DEVICE_WITHOUT_GROUP') toast(err.message, true); return; }
        const todo = q.batches.filter(b => !b.qc).length;
        el.innerHTML = personHeader() + tabs('quality') + `
            <section class="kiosk-card"><h2>${esc(t('qc_title', {group: q.group.name}))}</h2>
                <p class="kiosk-muted">${esc(t('qc_help'))}</p>
                <div class="kiosk-row"><button type="button" class="kiosk-btn is-danger" data-report>${icon('triangle-alert')} ${esc(t('qc_report'))}</button></div></section>
            <section class="kiosk-card"><h2>${esc(t('qc_batches', {todo, total: q.batches.length}))}</h2>
                ${q.batches.length ? `<ul class="kiosk-qc">${q.batches.map(b => `<li class="kiosk-qc-row ${b.qc ? 'is-' + esc(b.qc.status) : ''}">
                    <div><strong>${esc(b.lot_code)}</strong> · ${esc(b.name)}
                        <div class="kiosk-muted">${esc(qty(b.quantity, b))} · ${esc(hhmm(b.created_at))}${b.employee ? ' · ' + esc(b.employee) : ''}</div>
                        ${b.qc ? `<div class="kiosk-qc-state">${b.qc.status === 'ok' ? icon('circle-check', 'is-sm') + ' ' + esc(t('qc_done_ok')) : icon('circle-x', 'is-sm') + ' ' + esc(t('qc_done_nc', {number: b.qc.nc_number || ''}))} · ${esc(hhmm(b.qc.at))}${b.qc.employee ? ' · ' + esc(b.qc.employee) : ''}${b.qc.note ? ' · ' + esc(b.qc.note) : ''}</div>` : ''}</div>
                    <div class="kiosk-qc-actions">${b.qc && b.qc.status === 'nc' ? '' : `${b.qc ? '' : `<button type="button" class="kiosk-btn is-ok" data-qc-ok="${b.id}">${icon('check')} ${esc(t('qc_ok'))}</button>`}
                        <button type="button" class="kiosk-btn is-danger-soft is-small" data-qc-nc="${b.id}">${icon('x')} ${esc(t('qc_nc'))}</button>`}</div></li>`).join('')}</ul>`
                    : `<p class="kiosk-muted">${esc(t('qc_no_batches'))}</p>`}</section>
            <section class="kiosk-card"><h2>${esc(t('qc_reports'))}</h2>
                ${q.reports.length ? `<ul class="kiosk-qc">${q.reports.map(r => `<li class="kiosk-qc-row is-report">
                    <div><strong>${esc(r.number)}</strong> · ${esc(r.reported_at)}${r.product ? ' · ' + esc(r.product) : ''}<div class="kiosk-muted">${esc(r.description)}</div></div>
                    <span class="kiosk-qc-badge is-${esc(r.severity)}">${esc(t('sev_' + r.severity))} · ${esc(t('nc_' + r.status))}</span></li>`).join('')}</ul>`
                    : `<p class="kiosk-muted">${esc(t('qc_no_reports'))}</p>`}</section>`;
        if (message) toast(message);
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
    async function renderBatch(id, message, preloaded, focus) {
        stopScan();
        longIdle = true;
        resetIdle();
        let b = preloaded;
        if (!b) { try { b = await api('GET', `/kiosk/production/${id}`, undefined, true); } catch (err) { if (!err.code) toast(err.message, true); return; } }
        const pending = {}; // materiał => {photo, ocr}
        const item = b.item || {unit: '', ppc: null, name: ''};
        // Jedna linia = jeden składnik : dotknięcie otwiera numer partii (ręcznie, zdjęcie worka z OCR, skan) ; otwarta jest
        // wskazana linia, inaczej pierwszy składnik bez numeru partii.
        const order = [...b.ingredients.filter(g => !g.packaging), ...b.ingredients.filter(g => g.packaging)];
        const open = b.editable ? (focus !== undefined && focus !== null ? focus : (order.find(g => !g.lot_number) || {}).material_id ?? null) : null;
        const row = (g) => `<article class="kiosk-card kiosk-lot ${g.lot_number ? 'is-ok' : ''} ${open === g.material_id ? 'is-open' : ''}" data-material="${g.material_id}">
            <div class="kiosk-lot-head" ${b.editable ? 'data-lot-toggle role="button" tabindex="0"' : ''}><div><h3>${esc(g.name)}</h3><span class="kiosk-muted">${g.quantity !== null ? esc(amount(g.quantity) + ' ' + (g.unit || '')) : esc(amount(g.per_unit) + ' ' + (g.unit || '') + ' / ' + t('prod_per_recipe'))}</span></div>
                <span class="kiosk-lot-state">${g.lot_number ? icon('circle-check', 'is-sm') + ' ' + esc(g.lot_number) + (g.photo ? ' ' + icon('camera', 'is-sm') : '') : esc(t('lot_missing'))}</span></div>
            ${b.editable ? `<div class="kiosk-lot-body" ${open === g.material_id ? '' : 'hidden'}><div class="kiosk-lot-fields">
                <label>${esc(t('lot_number'))}<input name="lot" autocomplete="off" autocapitalize="characters" maxlength="64" value="${esc(g.lot_number || '')}"></label>
                <label>${esc(t('lot_best_before'))}<input name="bb" type="date" value="${esc(g.best_before || '')}"></label>
                ${canScan() ? `<button type="button" class="kiosk-btn is-small is-primary" data-scan>${icon('scan-line')} ${esc(t('lot_scan'))}</button>` : ''}
                <label class="kiosk-btn is-small kiosk-photo">${icon('camera')} ${esc(t('lot_photo'))}<input type="file" accept="image/*" capture="environment" data-photo hidden></label>
                ${(g.received || []).filter(r => r.lot_number !== g.lot_number).map((r, k) => `<button type="button" class="kiosk-btn is-small" data-fefo="${k}">${esc(t('lot_received', {lot: r.lot_number, date: r.best_before ? r.best_before.split('-').reverse().join('.') : '—'}))}</button>`).join('')}
                ${g.last && g.last.lot_number !== g.lot_number && !(g.received || []).some(r => r.lot_number === g.last.lot_number) ? `<button type="button" class="kiosk-btn is-small" data-same>${esc(t('lot_same', {lot: g.last.lot_number}))}</button>` : ''}
                <button type="button" class="kiosk-btn is-primary is-small" data-save-lot>${esc(t('lot_save'))}</button></div>
            <div class="kiosk-ocr" data-ocr hidden></div></div>` : ''}</article>`;
        const ingredients = b.ingredients.filter(g => !g.packaging);
        const packaging = b.ingredients.filter(g => g.packaging);
        el.innerHTML = header(`<button type="button" class="kiosk-btn is-small kiosk-back" data-back>‹ ${esc(t('back'))}</button>`, true) + `
            <section class="kiosk-card kiosk-batch-head ${b.cancelled ? 'is-cancelled' : ''}"><div><h2>${esc(item.name)}</h2>
                <p class="kiosk-status">${esc(t('prod_batch_line', {lot: b.lot_code, q: qty(b.quantity, item)}))}${b.cancelled ? ' · ' + esc(t('prod_cancelled')) : ''}</p>
                <p class="kiosk-muted">${esc(t('prod_lots', {done: b.lots_done, total: b.lots_total}))}${b.employee ? ' · ' + esc(b.employee) : ''} · ${esc(hhmm(b.created_at))}</p></div>
                ${b.editable ? `<button type="button" class="kiosk-btn is-small is-danger-soft" data-cancel-batch>${esc(t('prod_cancel'))}</button>` : ''}</section>
            ${b.ingredients.length ? '' : `<section class="kiosk-card"><p class="kiosk-muted">${esc(t('prod_no_recipe'))}</p></section>`}
            ${ingredients.length ? `<h2 class="kiosk-section">${esc(t('lot_ingredients'))}</h2>${ingredients.map(row).join('')}` : ''}
            ${packaging.length ? `<details class="kiosk-section" ${packaging.some(g => g.lot_number || g.material_id === open) ? 'open' : ''}><summary>${esc(t('lot_packaging', {n: packaging.length}))}</summary>${packaging.map(row).join('')}</details>` : ''}`;
        if (message) toast(message);
        if (open !== null) el.querySelector(`[data-material="${open}"]`)?.scrollIntoView({block: 'center'});
        el.querySelectorAll('[data-lot-toggle]').forEach(h => h.addEventListener('click', () => {
            const card = h.closest('[data-material]');
            const was = card.classList.contains('is-open');
            el.querySelectorAll('.kiosk-lot.is-open').forEach(c => { c.classList.remove('is-open'); c.querySelector('.kiosk-lot-body').hidden = true; });
            if (!was) { card.classList.add('is-open'); card.querySelector('.kiosk-lot-body').hidden = false; card.scrollIntoView({block: 'center', behavior: 'smooth'}); }
            resetIdle();
        }));
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
            card.querySelectorAll('[data-fefo]').forEach(btn => btn.addEventListener('click', () => {
                const r = (g.received || []).filter(x => x.lot_number !== g.lot_number)[Number(btn.dataset.fefo)];
                if (!r) return;
                card.querySelector('[name=lot]').value = r.lot_number;
                card.querySelector('[name=bb]').value = r.best_before || '';
            }));
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
                    const r = await api('PUT', `/kiosk/production/${b.id}/lots/${m}`, {lot_number: lot || null, best_before: bb, ...(p.photo ? {photo: p.photo} : {}), ...(p.ocr ? {ocr_text: p.ocr} : {})}, true);
                    // Następny składnik bez numeru partii otwiera się sam (po kolei : składniki, potem opakowania).
                    const fresh = [...r.ingredients.filter(x => !x.packaging), ...r.ingredients.filter(x => x.packaging)];
                    const at = fresh.findIndex(x => x.material_id === m);
                    const next = [...fresh.slice(at + 1), ...fresh.slice(0, at)].find(x => !x.lot_number);
                    await renderBatch(b.id, t('lot_saved', {name: g.name}), r, next ? next.material_id : -1);
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
            <div class="kiosk-row"><button type="button" class="kiosk-btn is-small" data-scan-stop>${icon('square')} ${esc(t('scan_stop'))}</button></div>`;
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
    function renderSignature(purpose, ref, help) {
        resetIdle();
        el.innerHTML = header() + `<section class="kiosk-card"><h2>${esc(t('signature'))}</h2><p class="kiosk-status">${esc(help)}</p>
            <canvas class="kiosk-sign" data-pad width="900" height="260" aria-label="${esc(t('signature'))}"></canvas><p class="kiosk-error" data-error></p>
            <div class="kiosk-row is-end"><button type="button" class="kiosk-btn" data-clear>${esc(t('clear'))}</button>
                <button type="button" class="kiosk-btn" data-skip>${esc(t('cancel'))}</button>
                <button type="button" class="kiosk-btn is-primary" data-save>${esc(t('sign'))}</button></div></section>`;
        const pad = signaturePad(el.querySelector('[data-pad]'));
        el.querySelector('[data-clear]').addEventListener('click', () => pad.clear());
        el.querySelector('[data-skip]').addEventListener('click', () => renderMe());
        el.querySelector('[data-save]').addEventListener('click', async (e) => {
            if (pad.empty()) { el.querySelector('[data-error]').textContent = t('sign_first'); return; }
            e.target.disabled = true;
            try {
                await api('POST', '/kiosk/signatures', {purpose, ref, image: pad.image()}, true);
                renderMe(t('signed'));
            } catch (err) { e.target.disabled = false; el.querySelector('[data-error]').textContent = err.message; }
        });
    }

    // --- Commun ----------------------------------------------------------------------------------------
    // Menu en bas et sortie : délégation (le menu est redessiné avec chaque écran). Onglet actif touché = retour à sa page.
    el.addEventListener('click', (e) => {
        const tab = e.target.closest('[data-tab]');
        if (tab && session) { stopScan(); openTab(tab.dataset.tab); return; }
        if (e.target.closest('[data-logout]')) logout();
        if (e.target.closest('[data-switch]') && session) { stopScan(); renderSwitch(); }
    });
    // Espace réservé au menu fixe en bas (toast au-dessus du menu).
    new MutationObserver(() => el.classList.toggle('has-nav', !!el.querySelector('.kiosk-tabs'))).observe(el, {childList: true});
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
    // Stockage « persistant » : le navigateur ne l'efface plus de lui-même (manque de place, inactivité).
    function persist() {
        try { if (navigator.storage && navigator.storage.persist) navigator.storage.persist().catch(() => {}); } catch (e) { /* non pris en charge */ }
    }

    // Démarrage : sans jeton local, le cookie de l'appairage dit si la tablette est déjà appairée.
    async function detectPairing() {
        if (paired) return;
        try {
            const res = await fetch(base + '/kiosk/device', {headers: {'Accept': 'application/json', 'Accept-Language': lang}, credentials: 'same-origin'});
            await res.json().catch(() => null);
            paired = res.ok;
        } catch (e) { /* hors ligne : écran d'appairage */ }
    }

    window.addEventListener('hashchange', () => { if (location.hash === '#week' && paired && !session) renderWeek(null, true); });
    Promise.all([loadLabels(), detectPairing()]).then(() => {
        if (!paired) return renderPairing();
        persist();
        location.hash === '#week' ? renderWeek(null, true) : renderHome();
    });
})();
