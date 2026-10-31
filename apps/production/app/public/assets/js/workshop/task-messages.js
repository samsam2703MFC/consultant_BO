/*
 * Komunikaty kierownika produkcji na tabletach [232] (tablica « Zadania zespołu », makieta 8) — wspólny moduł tabletu grupy,
 * tabletu pakowania i tabletu kierowcy : baner « Od kierownika produkcji · 10:42 » z wiadomością i « Przeczytane (PIN) » →
 * osoba (obecni najpierw) → PIN (osoba bez PIN-u, obecna : dotknięcie). Komunikat znika po potwierdzeniu albo sam, gdy
 * zadanie się zaliczy (serwer zamyka go przy odczycie).
 *
 *   window.tfbTaskMessages.refresh({host, api, audience, lang, people, toast})
 *     host     : element, do którego wstawić banery (pusty = brak komunikatów)
 *     api      : (method, path, body) => Promise<data> — fetch tabletu (token urządzenia, grupa)
 *     audience : 'group' | 'packing' | 'driver'
 *     people   : () => Promise<[{id, name, has_pin, clocked_in}]> osoby do wyboru
 *     toast    : (message, isError) => void
 */
(function () {
    'use strict';
    const L = {
        pl: {from: 'Od kierownika produkcji', read: 'Przeczytane (PIN)', who: 'Kto przeczytał ?', pin: 'Wpisz PIN', cancel: 'Anuluj', erase: 'Usuń',
            ok: 'Dziękujemy — przeczytane ({name}).', other: 'Inna osoba', more: 'Pokaż wszystkie komunikaty ({n})', present: 'w pracy', absent: 'nieobecny', nopin: 'bez PIN-u', urgent: 'PILNE', due: 'termin', nobody: 'Brak osób na liście.',
            needpin: 'Ta osoba nie ma PIN-u i nie jest dziś w pracy — potwierdza ktoś inny.'},
        fr: {from: 'Du responsable de production', read: 'Lu (PIN)', who: 'Qui a lu ?', pin: 'Saisir le PIN', cancel: 'Annuler', erase: 'Effacer',
            ok: 'Merci — lu ({name}).', other: 'Autre personne', more: 'Voir tous les messages ({n})', present: 'au travail', absent: 'absent', nopin: 'sans PIN', urgent: 'URGENT', due: 'échéance', nobody: 'Personne dans la liste.',
            needpin: 'Cette personne n\'a pas de PIN et n\'est pas au travail aujourd\'hui — quelqu\'un d\'autre confirme.'},
    };
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const initials = (n) => String(n || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(x => x[0]).join('').toUpperCase();
    const BELL = '<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>';
    let busy = false;

    async function refresh(o) {
        if (!o.host || busy || document.querySelector('.tm-sheet')) return;
        const t = L[o.lang === 'fr' ? 'fr' : 'pl'];
        let list = [];
        try { list = await o.api('GET', '/kiosk/messages?for=' + encodeURIComponent(o.audience || 'group')); } catch (e) { return; }
        if (!o.host.isConnected) return;
        if (!list || !list.length) { o.host.innerHTML = ''; o.host.hidden = true; return; }
        o.host.hidden = false;
        // Nagłówek mówi już « Od kierownika produkcji » : bez powtórzenia na początku wiadomości ; nazwa zadania pod spodem, gdy jej nie ma w tekście.
        const text = (m) => String(m.message || '').replace(/^\s*Przypomnienie od kierownika produkcji\s*:\s*/i, '');
        // Najwyżej 2 banery naraz (pilne najpierw) ; reszta po « Pokaż wszystkie ».
        const shown = o.all ? list : list.slice(0, 2);
        o.host.innerHTML = shown.map(m => `<article class="tm-msg ${m.urgent ? 'is-urgent' : ''}" role="status">
            <span class="tm-icon">${BELL}</span>
            <div class="tm-text"><b>${m.urgent ? `<span class="tm-urgent">${esc(t.urgent)}</span> ` : ''}${esc(t.from)} · ${esc(String(m.sent_at || '').slice(11, 16))}</b>
                <p>${esc(text(m))}</p>${text(m).includes(m.title) ? '' : `<small>« ${esc(m.title)} » · ${esc(t.due)} ${esc(m.due_label)}</small>`}</div>
            <button type="button" class="kiosk-btn is-primary tm-read" data-tm-read="${m.id}">${esc(t.read)}</button></article>`).join('');
        if (list.length > shown.length) o.host.insertAdjacentHTML('beforeend', `<button type="button" class="kiosk-link tm-more" data-tm-more>${esc(t.more.replace('{n}', list.length))}</button>`);
        o.host.querySelector('[data-tm-more]')?.addEventListener('click', () => refresh({...o, all: true}));
        o.host.querySelectorAll('[data-tm-read]').forEach(b => b.addEventListener('click', () => confirmRead(o, Number(b.dataset.tmRead))));
    }

    async function confirmRead(o, id) {
        const t = L[o.lang === 'fr' ? 'fr' : 'pl'];
        let people = [];
        try { people = (await o.people()) || []; } catch (e) { people = []; }
        people = [...people].sort((a, b) => (b.clocked_in ? 1 : 0) - (a.clocked_in ? 1 : 0));
        const present = people.filter(p => p.clocked_in);
        const personBtn = (p) => `<button type="button" class="tm-person ${p.clocked_in ? 'is-in' : ''}" data-tm-person="${p.id}">
                <span class="tm-av">${esc(initials(p.name))}</span><span><b>${esc(p.name)}</b><small>${esc(p.clocked_in ? t.present : t.absent)}${p.has_pin ? '' : ' · ' + esc(t.nopin)}</small></span></button>`;
        // Obecni najpierw (jak na ekranie startowym) ; « Inna osoba » — wszyscy z listy tabletu.
        const listHtml = (all) => {
            const shown = all || !present.length ? people : present;
            return (shown.length ? shown.map(personBtn).join('') : `<p class="kiosk-muted">${esc(t.nobody)}</p>`)
                + (!all && present.length && people.length > present.length ? `<button type="button" class="kiosk-link" data-tm-all>${esc(t.other)}</button>` : '');
        };
        const sheet = document.createElement('div');
        sheet.className = 'kiosk-sheet-backdrop tm-sheet';
        sheet.innerHTML = `<div class="kiosk-sheet" role="dialog" aria-modal="true" aria-label="${esc(t.who)}"><h2>${BELL} <span data-tm-title>${esc(t.who)}</span></h2>
            <div class="tm-people" data-tm-people>${listHtml(false)}</div>
            <div data-tm-pin hidden></div><p class="kiosk-error" data-tm-error></p>
            <div class="kiosk-row"><button type="button" class="kiosk-btn" data-tm-cancel>${esc(t.cancel)}</button></div></div>`;
        document.body.appendChild(sheet);
        const close = () => { document.removeEventListener('keydown', onKey); sheet.remove(); };
        const err = (m) => { sheet.querySelector('[data-tm-error]').textContent = m || ''; };
        let pin = '';
        let who = null;
        const send = async (body) => {
            busy = true;
            try {
                const r = await o.api('POST', `/kiosk/messages/${id}/read`, {for: o.audience || 'group', ...body});
                close();
                o.toast(t.ok.replace('{name}', r && r.by ? r.by : (who ? who.name : '')));
                busy = false;
                refresh(o);
            } catch (e) {
                busy = false;
                pin = ''; dots();
                err(e.message);
            }
        };
        const dots = () => { const d = sheet.querySelector('[data-tm-dots]'); if (d) d.innerHTML = Array.from({length: Math.max(4, pin.length)}, (_, i) => `<span class="${i < pin.length ? 'is-on' : ''}"></span>`).join(''); };
        const onKey = (e) => {
            if (!who || sheet.querySelector('[data-tm-pin]').hidden) return;
            if (/^\d$/.test(e.key) && pin.length < 6) { pin += e.key; dots(); }
            if (e.key === 'Backspace') { pin = pin.slice(0, -1); dots(); }
            if (e.key === 'Enter' && pin.length >= 4) send({employee_id: who.id, pin});
        };
        document.addEventListener('keydown', onKey);
        sheet.addEventListener('click', (e) => {
            if (e.target === sheet || e.target.closest('[data-tm-cancel]')) return close();
            if (e.target.closest('[data-tm-all]')) { sheet.querySelector('[data-tm-people]').innerHTML = listHtml(true); return; }
            const p = e.target.closest('[data-tm-person]');
            if (p) {
                who = people.find(x => x.id === Number(p.dataset.tmPerson));
                err('');
                if (!who.has_pin) {
                    if (!who.clocked_in) return err(t.needpin);
                    return send({employee_id: who.id});
                }
                pin = '';
                sheet.querySelector('[data-tm-people]').hidden = true;
                sheet.querySelector('[data-tm-title]').textContent = `${who.name} — ${t.pin}`;
                const box = sheet.querySelector('[data-tm-pin]');
                box.hidden = false;
                box.innerHTML = `<div class="kiosk-dots" data-tm-dots aria-hidden="true"></div>
                    <div class="kiosk-pad">${[1, 2, 3, 4, 5, 6, 7, 8, 9].map(d => `<button type="button" class="kiosk-btn" data-tm-digit="${d}">${d}</button>`).join('')}
                    <button type="button" class="kiosk-btn" data-tm-back aria-label="${esc(t.erase)}">⌫</button><button type="button" class="kiosk-btn" data-tm-digit="0">0</button>
                    <button type="button" class="kiosk-btn is-primary" data-tm-ok>OK</button></div>`;
                dots();
                return;
            }
            const d = e.target.closest('[data-tm-digit]');
            if (d && pin.length < 6) { pin += d.dataset.tmDigit; dots(); return; }
            if (e.target.closest('[data-tm-back]')) { pin = pin.slice(0, -1); dots(); return; }
            if (e.target.closest('[data-tm-ok]') && who && pin.length >= 4) send({employee_id: who.id, pin});
        });
    }

    window.tfbTaskMessages = {refresh};
})();
