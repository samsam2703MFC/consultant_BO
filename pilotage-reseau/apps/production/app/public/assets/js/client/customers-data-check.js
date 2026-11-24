// [385] Dane klientów (Administracja — co miesiąc) : 1. aktywni klienci (zamówienie w ostatnich miesiącach) i braki w ich
// kartach — e-mail, telefon do SMS, język, termin płatności, NIP / VAT UE, adres, cennik — z tym, co każdy brak psuje w ERP,
// 2. une décision par client signalé (poprawione — vérifié sur la fiche au moment du zapis, brak danych od klienta, nie
// dotyczy), 3. récapitulatif et validation → la tâche du mois se valide seule. Rien n'est changé dans la fiche depuis cet
// écran : les liens ouvrent la fiche client et le cennik dans un autre onglet.
(() => {
    const app = document.getElementById('dkApp');
    const boot = document.getElementById('dkBoot');
    if (!app || !boot || !window.tw) return;
    const {esc} = tw;
    let D = JSON.parse(boot.textContent);
    let step = 1, reached = 1, busy = false;
    // Brouillons de l'étape 2 (décision, note) par client, gardés entre deux rendus.
    const draft = {};
    // Codes vus signalés pendant cette visite : un client corrigé dans l'autre onglet sort de la liste du serveur, on le garde
    // ici pour pouvoir enregistrer « Poprawione » (le serveur revérifie la fiche).
    const seen = {};
    const STEPS = ['Przegląd', 'Do wyjaśnienia', 'Zatwierdź'];
    const DEC_CLS = {fixed: 'ok', no_data: 'warn', not_applicable: ''};
    const BAD = ['email_missing', 'email_failed', 'tax_missing', 'vat_eu_invalid', 'price_list_missing', 'phone_missing'];
    const canFinish = () => !!D.can_finish;
    const link = (l, label) => l ? `<a class="tw-link" href="${at.root}${esc(l.href)}" target="_blank" rel="noopener" style="padding:0"><i class="bi bi-box-arrow-up-right"></i> ${esc(label || l.label)}</a>` : '';
    const who = (i) => `<b>${esc(i.name)}</b><small>${i.town ? esc(i.town) + ' · ' : ''}${i.last_order_label ? 'ostatnie zamówienie ' + esc(i.last_order_label) + ' · ' + i.orders + ' ' + tw.plural(i.orders, 'zamówienie', 'zamówienia', 'zamówień') : 'bez zamówienia w okresie'}${i.card_active ? '' : ' · nieaktywny w ERP'}${i.integrated ? ' · sklep zintegrowany' : ''}</small>`;
    const pills = (i) => i.problems.map(p => tw.pill(p.label, BAD.includes(p.code) ? 'bad' : 'warn')).join(' ') || tw.pill('komplet danych', 'ok');
    const eff = (i) => i.decision || i.earlier;
    const status = (i) => i.waiting ? tw.pill('do wyjaśnienia', 'bad')
        : (eff(i) ? tw.pill(eff(i).label + (i.decision ? '' : ' (' + eff(i).month_label + ')'), DEC_CLS[eff(i).code] || '') : tw.pill(i.problems.length ? 'z brakami' : 'komplet', i.problems.length ? 'warn' : 'ok'));
    const problemList = (i) => i.problems.map(p => `<div style="display:flex;gap:6px;align-items:baseline;margin:2px 0"><i class="bi bi-exclamation-circle ${BAD.includes(p.code) ? 'tw-red' : ''}"></i>
        <span><b>${esc(p.label)}</b> : ${esc(p.detail)}<small style="display:block;color:#777">psuje : ${esc(p.breaks)}</small>${p.link ? link(p.link, p.link.label === 'Cennik klienta' ? 'Cennik klienta' : 'Popraw w karcie') : ''}</span></div>`).join('');
    const srcNotes = () => {
        const s = D.sources, out = [];
        for (const k of ['orders', 'wdt', 'price', 'mails']) if (s[k].note) out.push(`<div><b>${esc(s[k].from)}</b> : ${esc(s[k].note)}</div>`);
        for (const c of Object.values(s.card)) if (c.note) out.push(`<div><b>${esc(c.label)}</b> : ${esc(c.note)}</div>`);
        if (s.price.integrated_note && D.totals.integrated) out.push(`<div><b>Cennik</b> : ${esc(s.price.integrated_note)} (${D.totals.integrated}).</div>`);
        return out;
    };
    const trend = () => {
        const p = D.previous;
        if (!p || p.incomplete === null || p.incomplete === undefined) return {value: '—', sub: 'brak przeglądu z poprzedniego miesiąca'};
        const d = p.delta;
        return {value: (d > 0 ? '+' : (d < 0 ? '−' : '')) + Math.abs(d), sub: `${esc(p.month_label)} : ${p.incomplete} z brakami, ${p.complete} kompletnych`, cls: d > 0 ? 'warn' : 'ok'};
    };

    function step1() {
        const t = D.totals;
        const flagged = D.items.filter(i => i.is_active && i.problems.length);
        const head = [{label: 'Klient'}, {label: 'Braki w karcie i co psują'}, {label: ''}];
        const rows = flagged.map(i => ({cls: i.waiting ? 'hl' : '', cells: [who(i) + (i.link ? `<div>${link(i.link)}</div>` : ''), problemList(i), status(i)]}));
        const top = Object.entries(t.by_code).filter(([, n]) => n > 0).sort((a, b) => b[1] - a[1]).slice(0, 3).map(([c, n]) => `${n} ${D.problem_labels[c]}`).join(' · ');
        const tr = trend();
        const tiles = [
            {label: 'Aktywni klienci', value: String(t.active), sub: `zamówienie od ${D.since_label} (${D.params.months} mies.)`},
            {label: 'Komplet danych', value: String(t.complete), sub: t.active ? Math.round(t.complete * 100 / t.active) + ' % aktywnych' : '—', cls: 'ok'},
            {label: 'Z brakami', value: String(t.incomplete), subHtml: esc(top || 'brak'), cls: t.incomplete ? 'warn' : 'ok'},
            {label: 'Zmiana vs poprzedni miesiąc', value: tr.value, subHtml: tr.sub, cls: tr.cls || ''}];
        const notes = srcNotes();
        return `<p class="tw-lead">Karty klientów, którzy zamówili od <b>${esc(D.since_label)}</b> : czego brakuje albo co jest błędne — i co to psuje w ERP (dokumenty, KSeF, powiadomienia, SMS, płynność, ceny).
                Asystent niczego nie zmienia w karcie : poprawki robisz w karcie klienta albo w cenniku klienta.</p>
            ${tw.tiles(tiles)}
            ${notes.length ? tw.alert(notes.join(''), '', 'bi-info-circle') : ''}
            <div class="tw-cap">Klienci z brakami (${flagged.length})</div>
            ${tw.table(head, rows, 'Wszyscy aktywni klienci mają komplet danych.')}
            <p class="tw-note">Sprawdzane : e-mail (główny, handlowca, księgowości — brak, błędny adres, ostatni e-mail z błędem), telefon (brak, nieczytelny dla bramki SMS), język komunikacji, termin płatności,
                NIP / VAT (klient z WDT : VAT UE z prefiksem kraju, jak w KSeF), adres (ulica, numer, kod, miejscowość, kraj), cennik klienta i produkty zamówione w okresie bez ceny klienta (customers_data.active_months).</p>`;
    }

    function decisionCell(i) {
        const a = i.decision;
        const r = draft[i.key] ?? (draft[i.key] = {decision: a ? a.code : (i.fixed_now ? 'fixed' : ''), note: a ? (a.note || '') : ''});
        const allowed = i.problems.length ? ['no_data', 'not_applicable', 'fixed'] : ['fixed'];
        const seg = allowed.map(k => `<button type="button" class="${r.decision === k ? 'on' : ''}" data-dk-dec="${esc(i.key)}" data-v="${k}">${esc(D.decisions[k])}</button>`).join('');
        const need = D.note_required.includes(r.decision);
        const ph = r.decision === 'no_data' ? 'Brak danych (wymagane) : o co i kiedy prosiliście klienta' : (r.decision === 'not_applicable' ? 'Nie dotyczy (wymagane) : dlaczego' : 'Notatka (opcjonalnie)');
        const saved = a ? `<small>${tw.pill(a.label, DEC_CLS[a.code] || '')} ${esc(a.at)}${a.by ? ' · ' + esc(a.by) : ''}${a.note ? ' — ' + esc(a.note) : ''}</small>`
            : (i.earlier ? `<small>${tw.pill(i.earlier.label, '')} w przeglądzie « ${esc(i.earlier.month_label)} »${i.earlier.note ? ' — ' + esc(i.earlier.note) : ''}</small>` : '');
        const dirty = !a || a.code !== r.decision || (a.note || '') !== r.note;
        const hint = r.decision === 'fixed' && i.problems.length ? '<small class="tw-red">Najpierw popraw kartę (link obok) — « Poprawione » zapisze się tylko, gdy braków już nie ma.</small>' : '';
        return `<div style="display:grid;gap:6px;min-width:380px"><div class="tw-seg" style="justify-self:start">${seg}</div>
            <div style="display:flex;gap:6px"><input class="form-control form-control-sm" data-dk-note="${esc(i.key)}" maxlength="500" value="${esc(r.note)}" placeholder="${esc(ph)}" style="min-height:36px;border-radius:9px">
            <button type="button" class="tw-btn ghost sm" data-dk-save="${esc(i.key)}" ${!r.decision || !dirty || busy || (need && r.note.trim().length < 3) ? 'disabled' : ''}>Zapisz</button></div>
            ${hint}${i.reason ? `<small class="tw-red">${esc(i.reason)}</small>` : ''}${saved}</div>`;
    }

    // Liste de l'étape 2 : clients du serveur (signalés ou décidés ce mois) + clients vus signalés et corrigés depuis.
    const remember = () => D.review.forEach(i => { if (i.problems.length) seen[i.key] = i.codes; });
    const reviewList = () => {
        remember();
        const keys = new Set(D.review.map(i => i.key));
        const gone = D.items.filter(i => seen[i.key] && !keys.has(i.key) && !i.problems.length && i.is_active).map(i => ({...i, fixed_now: true}));
        return D.review.concat(gone);
    };

    function step2() {
        const list = reviewList(), t = D.totals;
        if (!list.length) return tw.alert('Nic do wyjaśnienia : wszyscy aktywni klienci mają komplet danych.', 'ok', 'bi-check-circle');
        const rows = list.map(i => ({cls: i.waiting ? 'hl' : '', cells: [who(i) + (i.link ? `<div>${link(i.link)}</div>` : ''),
            i.problems.length ? problemList(i) : `<span style="color:#198754"><i class="bi bi-check-circle"></i> karta bez braków${i.fixed_now ? ' (poprawiona w tym czasie)' : ''}</span>`,
            decisionCell(i)]}));
        return `<p class="tw-lead">Dla każdego klienta z brakami : popraw kartę i zapisz <b>Poprawione</b> (sprawdzane w chwili zapisu), albo <b>Brak danych od klienta</b> / <b>Nie dotyczy</b> (notatka wymagana).
                Krok 3 otworzy się, gdy wszyscy mają decyzję.</p>
            ${tw.tiles([{label: 'Do wyjaśnienia', value: String(t.review), sub: `${t.incomplete} z brakami teraz`},
                {label: 'Z decyzją', value: `${t.resolved} z ${t.review}`, sub: t.waiting ? `zostało ${t.waiting}` : 'wszyscy wyjaśnieni', cls: t.waiting ? 'warn' : 'ok'},
                {label: 'Poprawione / brak danych / nie dotyczy', value: `${t.fixed} / ${t.no_data} / ${t.not_applicable}`, sub: t.earlier ? `${t.earlier} « nie dotyczy » z poprzedniego przeglądu` : 'w tym przeglądzie'}])}
            ${tw.alert('Asystent nie zmienia karty klienta ani cennika : linki otwierają kartę w nowej karcie, po powrocie lista odświeża się sama.', '', 'bi-signpost-split',
                `<button type="button" class="tw-btn ghost sm" data-dk-reload ${busy ? 'disabled' : ''}><i class="bi bi-arrow-clockwise"></i> Sprawdź ponownie</button>`)}
            ${tw.table([{label: 'Klient'}, {label: 'Braki i co psują'}, {label: 'Decyzja'}], rows)}
            <p class="tw-note">« Brak danych od klienta » wraca co miesiąc, dopóki brak jest w karcie ; « Nie dotyczy » nie wraca, dopóki braki są te same.</p>`;
    }

    function step3() {
        const t = D.totals;
        if (D.done) return tw.done('Dane klientów sprawdzone · ' + (D.done.at || ''), D.done.summary + ' · zadanie zaliczone');
        const decided = D.review.filter(i => eff(i));
        const tr = trend();
        return `<p class="tw-lead">Gotowe : <b>${esc(D.summary)}</b>.</p>
            ${tw.tiles([{label: 'Komplet danych', value: `${t.complete} z ${t.active}`, sub: 'aktywnych klientów', cls: 'ok'},
                {label: 'Z brakami', value: String(t.incomplete), sub: `${t.no_data} brak danych od klienta · ${t.not_applicable} nie dotyczy`, cls: t.incomplete ? 'warn' : 'ok'},
                {label: 'Zmiana vs poprzedni miesiąc', value: tr.value, subHtml: tr.sub, cls: tr.cls || ''},
                {label: 'Decyzje', value: `${t.resolved} z ${t.review}`, sub: `${t.fixed} poprawione`, cls: 'ok'}])}
            ${decided.length ? `<div class="tw-cap">Decyzje</div>` + tw.table([{label: 'Klient'}, {label: 'Braki'}, {label: 'Decyzja'}, {label: 'Notatka'}],
                decided.map(i => ({cells: [who(i), i.problems.length ? pills(i) : (eff(i).flag_labels || []).map(l => tw.pill(l + ' → poprawione', 'ok')).join(' '), status(i), esc(eff(i).note || '—')]}))) : ''}
            <p class="tw-note">« Zakończ » zalicza zadanie « Dane klientów » na ten miesiąc.</p>`;
    }

    function render() {
        remember();
        const top = canFinish() ? reached : Math.min(reached, 2);
        if (step > top) step = top;
        const body = step === 1 ? step1() : (step === 2 ? step2() : step3());
        const next = step === 1 ? {label: 'Dalej : do wyjaśnienia', attr: 'data-dk-next'}
            : step === 2 ? {label: 'Dalej : zatwierdź', attr: 'data-dk-next', disabled: !canFinish()}
                : (D.done ? null : {label: 'Zakończ', attr: 'data-dk-finish', final: true, disabled: busy || !canFinish()});
        app.innerHTML = tw.frame({title: 'Dane klientów', sub: `przegląd ${D.month_label} · krok ${step} z 3 · ok. 20 min`, steps: STEPS, step, reached: top, body,
            prev: step > 1 ? 'Wstecz' : null, next, later: !D.done && step < 3});
    }

    const findItem = (key) => reviewList().find(x => x.key === key);
    const refresh = (key) => {
        const i = findItem(key), r = draft[key], a = i && i.decision, btn = app.querySelector(`[data-dk-save="${CSS.escape(key)}"]`);
        if (!btn || !i || !r) return;
        const need = D.note_required.includes(r.decision);
        btn.disabled = !r.decision || busy || (need && r.note.trim().length < 3) || (a && a.code === r.decision && (a.note || '') === r.note);
    };
    const reload = async () => {
        if (busy || D.done) return;
        const res = await at.send('/ajax/clients/data-check', 'GET');
        if (res && res.ok && res.data) { D = res.data; render(); }
    };
    tw.bind(app, (n) => { step = n === -1 ? Math.max(1, step - 1) : n; render(); });
    app.addEventListener('input', (e) => {
        const n = e.target.closest('[data-dk-note]');
        if (!n) return;
        (draft[n.dataset.dkNote] ??= {decision: '', note: ''}).note = n.value;
        refresh(n.dataset.dkNote);
    });
    app.addEventListener('click', async (e) => {
        if (e.target.closest('[data-dk-next]')) { step = Math.min(3, step + 1); reached = Math.max(reached, step); render(); return; }
        if (e.target.closest('[data-dk-reload]')) { await reload(); return; }
        const r = e.target.closest('[data-dk-dec]');
        if (r) { const key = r.dataset.dkDec; (draft[key] ??= {decision: '', note: ''}).decision = r.dataset.v; render(); app.querySelector(`[data-dk-note="${CSS.escape(key)}"]`)?.focus(); return; }
        const s = e.target.closest('[data-dk-save]');
        if (s) {
            const key = s.dataset.dkSave, d = draft[key], it = findItem(key);
            if (!d || !d.decision || busy || !it) return;
            busy = true; s.disabled = true;
            const res = await tw.post('/ajax/clients/data-check/decision', {id_customer: it.id_customer, decision: d.decision, note: d.note, seen: seen[key] || it.codes});
            busy = false;
            if (res.ok) { D = res.data; delete draft[key]; }
            render();
            return;
        }
        if (e.target.closest('[data-dk-finish]')) {
            if (busy) return;
            busy = true; render();
            const res = await tw.post('/ajax/clients/data-check/finish', {});
            busy = false;
            if (res.ok) { at.notify(true, res.message); D = res.data; }
            render();
        }
    });
    // Retour depuis la fiche client (autre onglet) : données relues, la fiche n'est pas touchée.
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') reload(); });
    render();
})();
