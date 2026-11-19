/*
 * Ścieżka cen [351] (makieta 66 B) : jeden pasek 4 kroków, ten sam w asystencie faktur (Zaopatrzenie › Faktury) i w Kreatorze
 * cen — 1. ceny z faktur KSeF, 2. sprawdź ceny katalogowe, 3. zatwierdź zmianę cennika, 4. e-mail do klientów.
 * Stan kroku 1 : GET /supply/invoices/assistant-status (asystent faktur) albo GET /ajax/price-wizard/path (inv : ten sam
 * wynik) ; kroki 2–4 : GET /ajax/price-wizard/path (zaliczenie tygodnia, publikacja tygodnia, e-maile).
 * window.TfbPricePath.html({cur, root, inv, path, lang, here}) → HTML paska ; load(root, withInv) → stan kroków 2–4.
 */
(function () {
    'use strict';
    const L = {
        pl: {
            where1: 'Zaopatrzenie › Faktury', where: 'Kreator cen',
            s1: 'Ceny z faktur KSeF', s2: 'Sprawdź ceny katalogowe', s3: 'Zatwierdź zmianę cennika', s4: 'Wyślij e-mail do klientów',
            h1: 'pozycje faktur sprawdzone, ceny do kartoteki', h2: 'koszt receptur po nowych cenach vs cennik — próg ±{t} %',
            h3: 'data od kiedy, ceny klientów (rabaty zachowane)', h4: 'cennik PDF w języku klienta · strona franczyzy',
            todo: '{n} poz. do sprawdzenia', ready: '{n} fakt. do zatwierdzenia', done_at: 'sprawdzone · ostatnio {d} {h}', nothing: 'nic do sprawdzenia',
            reviewed: '✓ {s}', planned: 'cennik od {d} · {n} cen', mails_wait: '{n} e-maili · wyjdą {d}', mails_done: '{d} / {n} e-maili w kolejce',
            no_mail: 'publikacja bez e-maila', aria: 'Ścieżka cen',
        },
        fr: {
            where1: 'Approvisionnement › Factures', where: 'Assistant prix',
            s1: 'Prix des factures KSeF', s2: 'Vérifier les prix catalogue', s3: 'Valider le changement de tarif', s4: 'E-mail aux clients',
            h1: 'lignes de factures vérifiées, prix dans la fiche', h2: 'coût des recettes aux nouveaux prix vs tarif — seuil ±{t} %',
            h3: 'date d’effet, prix clients (remises gardées)', h4: 'tarif PDF dans la langue du client · site franchise',
            todo: '{n} lignes à vérifier', ready: '{n} fact. à valider', done_at: 'vérifié · dernière fois {d} {h}', nothing: 'rien à vérifier',
            reviewed: '✓ {s}', planned: 'tarif au {d} · {n} prix', mails_wait: '{n} e-mails · partiront le {d}', mails_done: '{d} / {n} e-mails en file',
            no_mail: 'publication sans e-mail', aria: 'Parcours des prix',
        },
    };
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const fill = (s, p) => String(s).replace(/\{(\w+)\}/g, (m, k) => (p && p[k] !== undefined ? p[k] : m));
    const dm = (iso) => iso ? String(iso).slice(8, 10) + '.' + String(iso).slice(5, 7) : '';

    /** Stan kroku 1 z assistant-status (todo, ready, done, last_done). */
    function invState(inv, T) {
        if (!inv) return {done: false, sub: T.h1};
        if (inv.done) return {done: true, sub: inv.last_done ? fill(T.done_at, {d: dm(inv.last_done), h: String(inv.last_done).slice(11, 16)}) : T.nothing};
        return {done: false, todo: true, sub: [inv.todo ? fill(T.todo, {n: inv.todo}) : '', inv.ready ? fill(T.ready, {n: inv.ready}) : ''].filter(Boolean).join(' · ') || T.h1};
    }

    /**
     * @param {{cur: number, root: string, inv?: object, path?: object, lang?: string, here?: string, threshold?: number}} o
     *   here = 'invoices' (asystent faktur : kroki 2–4 to linki) albo 'prices' (Kreator cen : kroki 2–4 to przyciski data-step).
     */
    function html(o) {
        const T = L[o.lang === 'fr' ? 'fr' : 'pl'];
        const P = o.path || {};
        const pub = P.publication || null;
        const i1 = invState(o.inv, T);
        const st = [
            {done: i1.done, todo: i1.todo, sub: i1.sub},
            {done: !!P.reviewed, sub: P.reviewed ? fill(T.reviewed, {s: P.reviewed.summary || ''}) : fill(T.h2, {t: String(o.threshold ?? 3).replace('.', ',')})},
            {done: !!pub, sub: pub ? fill(T.planned, {d: dm(pub.date), n: pub.products}) : T.h3},
            {done: !!pub && (!pub.notify || (pub.emails > 0 && pub.emails_done >= pub.emails)),
                sub: pub ? (!pub.notify ? T.no_mail : (pub.emails_done ? fill(T.mails_done, {d: pub.emails_done, n: pub.emails}) : fill(T.mails_wait, {n: pub.emails, d: dm(pub.notice_date)}))) : T.h4},
        ];
        const root = o.root || '';
        const cells = [T.s1, T.s2, T.s3, T.s4].map((label, k) => {
            const n = k + 1;
            const s = st[k];
            const cls = [n === o.cur ? 'cur' : '', s.done && n !== o.cur ? 'done' : '', s.todo && n !== o.cur ? 'todo' : ''].filter(Boolean).join(' ');
            const mark = s.done && n !== o.cur ? '✓' : String(n);
            const inner = `<span class="pp-where">${esc(n === 1 ? T.where1 : T.where)}</span><b><span class="pp-n">${mark}</span>${mark === '✓' ? n + '. ' : ''}${esc(label)}</b><small>${esc(s.sub)}</small>`;
            if (n === 1) return o.here === 'invoices' ? `<div class="pp-step ${cls}" aria-current="${n === o.cur ? 'step' : 'false'}">${inner}</div>`
                : `<a class="pp-step ${cls}" href="${root}/workshop/supply#tab=invoices&inv=wizard">${inner}</a>`;
            if (o.here === 'prices') return `<button type="button" class="pp-step ${cls}" data-step="${n}" ${n === o.cur ? 'aria-current="step"' : ''}>${inner}</button>`;
            return `<a class="pp-step ${cls}" href="${root}/finances/price-wizard#step=${n}">${inner}</a>`;
        }).join('');
        return `<nav class="pp" aria-label="${esc(T.aria)}">${cells}</nav>`;
    }

    /** Stan kroków ścieżki (sesja ERP) ; null, gdy brak dostępu do Kreatora cen. */
    async function load(root, withInv) {
        try {
            const res = await fetch((root || '') + '/ajax/price-wizard/path?inv=' + (withInv ? '1' : '0'), {credentials: 'same-origin', headers: {'Accept': 'application/json'}});
            if (!res.ok) return null;
            const out = await res.json();
            return out && out.ok !== false ? out.data : null;
        } catch (e) {
            return null;
        }
    }

    window.TfbPricePath = {html, load};
})();
