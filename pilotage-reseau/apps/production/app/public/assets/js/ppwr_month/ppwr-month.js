// [397] PPWR i opakowania (Kierownik produkcji — co miesiąc) : 1. braki danych PPWR / BDO des opakowania utilisés et des
// produits actifs, zmiany depuis le dernier contrôle, dowody opakowań des wysyłek du mois écoulé, 2. une décision par élément
// (uzupełnione — sprawdzone / zapytanie do dostawcy / nie dotyczy), 3. récapitulatif et validation → la tâche du mois se valide
// seule. Rien n'est changé dans les données PPWR / BDO depuis cet écran : les liens ouvrent les écrans existants ; la demande au
// fournisseur est un texte à copier (ou un mailto), l'ERP n'envoie rien.
(() => {
    const app = document.getElementById('pmApp');
    if (!app || !window.tw) return;
    const {esc} = tw;
    let D = JSON.parse(document.getElementById('pmBoot').textContent);
    let step = 1, reached = 1, busy = false;
    // Brouillons de l'étape 2 (décision, note) par élément, gardés entre deux rendus.
    const draft = {};
    const STEPS = ['Przegląd', 'Do decyzji', 'Zatwierdź'];
    const DEC_CLS = {checked: 'ok', supplier_asked: 'warn', not_applicable: ''};
    const KIND_ICON = {material: 'bi-box2', product: 'bi-basket', shipment: 'bi-truck'};
    const sel = (k) => CSS.escape(k);
    const canFinish = () => !!D.can_finish;
    const link = (l) => `<a class="tw-link" href="${at.root}${esc(l.href)}" target="_blank" rel="noopener" style="padding:0"><i class="bi bi-box-arrow-up-right"></i> ${esc(l.label)}</a>`;
    const links = (i) => i.links.length ? `<div style="display:flex;gap:4px 14px;flex-wrap:wrap">${i.links.map(link).join('')}</div>` : '';
    const sigs = (i) => i.signals.map(s => tw.pill(s.code === 'CHANGED' ? 'zmiana' : s.label, s.code === 'CHANGED' ? '' : (i.kind === 'shipment' ? 'warn' : 'bad'))).join(' ');
    const what = (i) => `<b><i class="bi ${KIND_ICON[i.kind] || 'bi-dot'}"></i> ${esc(i.title)}</b><small>${esc(i.kind_label)}${i.sub ? ' · ' + esc(i.sub) : ''}${i.supplier ? ' · dostawca ' + esc(i.supplier) : ''}</small>`;
    const eff = (i) => i.decision || i.earlier;
    const status = (i) => i.waiting ? tw.pill(i.signals.some(s => s.code !== 'CHANGED') ? 'do uzupełnienia' : 'do sprawdzenia', 'bad')
        : (eff(i) ? tw.pill(eff(i).label + (i.decision ? '' : ' (' + eff(i).month_label + ')'), DEC_CLS[eff(i).code] || '') : tw.pill('bez braków', 'ok'));
    const srcVal = (k, v) => D.sources[k].available ? v : 'brak danych';
    const srcSub = (k, txt) => D.sources[k].available ? txt : D.sources[k].note;

    function step1() {
        const t = D.totals, m = t.materials, p = t.products, s = t.shipments;
        const tiles = [
            {label: 'Opakowania z brakami', value: srcVal('materials', `${m.incomplete} / ${m.used}`), sub: srcSub('materials', `używane w aktywnych produktach${m.unused_incomplete ? ` · ${m.unused_incomplete} nieużywanych z brakami (bez sygnału)` : ''}`), cls: D.sources.materials.available ? (m.incomplete ? 'warn' : 'ok') : ''},
            {label: 'Produkty z brakami', value: srcVal('products', `${p.incomplete} / ${p.total}`), sub: srcSub('products', 'karton sprzedażowy PPWR, PPC, masa produktu'), cls: D.sources.products.available ? (p.incomplete ? 'warn' : 'ok') : ''},
            {label: 'Zmiany', value: String(t.changed), sub: `od ${D.since_review ? 'ostatniego przeglądu' : 'początku poprzedniego miesiąca'} (${D.since_label})`},
            {label: 'Wysyłki bez kompletu dowodu', value: srcVal('shipments', `${s.missing} / ${s.total}`), sub: srcSub('shipments', D.shipments_label), cls: D.sources.shipments.available ? (s.missing ? 'warn' : 'ok') : ''},
            {label: 'Do decyzji', value: String(t.review), sub: t.review ? `${t.resolved} z ${t.review} z decyzją` : 'brak braków i zmian', cls: t.waiting ? 'warn' : 'ok'}];
        const missing = Object.values(D.sources).filter(x => !x.available);
        const head = [{label: 'Element'}, {label: 'Braki i zmiany'}, {label: 'Gdzie uzupełnić'}, {label: ''}];
        const row = (i) => ({cls: i.waiting ? 'hl' : '', cells: [what(i), sigs(i) + i.signals.filter(x => x.detail).map(x => `<small>${esc(x.detail)}</small>`).join(''), links(i), status(i)]});
        return `<p class="tw-lead">Miesiąc <b>${esc(D.month_label)}</b> : dane PPWR i BDO opakowań używanych w aktywnych produktach, kartony sprzedażowe i masy produktów, zmiany od ostatniego przeglądu
                oraz dowody opakowań wysyłek z miesiąca <b>${esc(D.shipments_label)}</b>. Każdy element z brakiem albo zmianą trafia do kroku 2.</p>
            ${tw.tiles(tiles)}
            ${missing.length ? tw.alert(missing.map(x => `<div><b>${esc(x.from)}</b> : ${esc(x.note)}</div>`).join(''), '', 'bi-info-circle') : ''}
            <div class="tw-cap">Do decyzji (${D.review.length})</div>
            ${tw.table(head, D.review.map(row), 'Brak braków i zmian — dane PPWR i BDO kompletne.')}
            <p class="tw-note">« Kompletne » według reguł ekranów ERP : Rejestr PPWR (numer i jego źródło, masa, poziom i rodzaj opakowania, materiał, recyklat dla tworzyw, recykling, minimalizacja, wymiary opakowań zbiorczych i transportowych, cykle),
                Dane opakowań BDO (kategoria, typ), Kartony sprzedażowe PPWR (receptura, opakowanie w recepturze, karton, PPC), Wagi produktów (masa) i Raporty PPWR (dowód opakowań wysyłki).
                Dokumenty dostawców (deklaracje) nie mają jeszcze miejsca w ERP — nie są sprawdzane.</p>`;
    }

    function askBox(i) {
        if (!i.ask) return '';
        const mail = i.ask.email ? `<a class="tw-link" style="padding:0" href="mailto:${encodeURIComponent(i.ask.email)}?subject=${encodeURIComponent(i.ask.subject)}&body=${encodeURIComponent(i.ask.text)}"><i class="bi bi-envelope"></i> Otwórz w poczcie (${esc(i.ask.email)})</a>` : '';
        return `<div style="display:flex;gap:4px 14px;flex-wrap:wrap;align-items:center"><button type="button" class="tw-btn ghost sm" data-pm-copy="${esc(i.key)}"><i class="bi bi-clipboard"></i> Kopiuj tekst zapytania</button>${mail}</div>`;
    }

    function decisionCell(i) {
        const a = i.decision;
        const r = draft[i.key] ?? (draft[i.key] = {decision: a ? a.code : '', note: a ? (a.note || '') : ''});
        const allowed = D.kind_decisions[i.kind] || [];
        const seg = allowed.map(k => `<button type="button" class="${r.decision === k ? 'on' : ''}" data-pm-dec="${esc(i.key)}" data-v="${k}">${esc(D.decisions[k])}</button>`).join('');
        const need = D.note_required.includes(r.decision);
        const ph = r.decision === 'supplier_asked' ? 'Do kogo i o co (wymagane) : dostawca, dane, data' : (r.decision === 'not_applicable' ? 'Dlaczego nie dotyczy (wymagane)' : 'Notatka (opcjonalnie) : co sprawdzono');
        const saved = a ? `<small>${tw.pill(a.label, DEC_CLS[a.code] || '')} ${esc(a.at)}${a.by ? ' · ' + esc(a.by) : ''}</small>`
            : (i.earlier ? `<small>${tw.pill(i.earlier.label, DEC_CLS[i.earlier.code] || '')} w miesiącu « ${esc(i.earlier.month_label)} »${i.earlier.note ? ' — ' + esc(i.earlier.note) : ''}</small>`
                : (i.previous ? `<small>Poprzednio (${esc(i.previous.month_label)}) : ${esc(i.previous.label)}${i.previous.note ? ' — ' + esc(i.previous.note) : ''}</small>` : ''));
        const dirty = !a || a.code !== r.decision || (a.note || '') !== r.note;
        return `<div style="display:grid;gap:6px;min-width:420px"><div class="tw-seg" style="justify-self:start">${seg}</div>
            <div style="display:flex;gap:6px"><input class="form-control form-control-sm" data-pm-note="${esc(i.key)}" maxlength="500" value="${esc(r.note)}" placeholder="${esc(ph)}" style="min-height:36px;border-radius:9px">
            <button type="button" class="tw-btn ghost sm" data-pm-save="${esc(i.key)}" ${!r.decision || !dirty || busy || (need && r.note.trim().length < 3) ? 'disabled' : ''}>Zapisz</button></div>
            ${links(i)}${r.decision === 'supplier_asked' || (i.kind === 'material' && i.waiting) ? askBox(i) : ''}${i.reason ? `<small class="tw-red">${esc(i.reason)}</small>` : ''}${saved}</div>`;
    }

    function step2() {
        const list = D.review, t = D.totals;
        if (!list.length) return tw.alert('Nic do decyzji : dane PPWR i BDO kompletne, brak zmian, dowody wysyłek kompletne.', 'ok', 'bi-check-circle');
        const rows = list.map(i => ({cls: i.waiting ? 'hl' : '', cells: [what(i),
            i.signals.map(s => `<div style="display:flex;gap:6px;align-items:baseline;margin:1px 0"><i class="bi ${s.code === 'CHANGED' ? 'bi-arrow-repeat' : 'bi-exclamation-circle tw-red'}"></i><span><b>${esc(s.label)}</b>${s.detail ? ' : ' + esc(s.detail) : ''}</span></div>`).join('') || '<small>braki uzupełnione</small>',
            decisionCell(i)]}));
        return `<p class="tw-lead">Dla każdego elementu zapisz decyzję : <b>Uzupełnione — sprawdzone</b> (przyjęte, gdy w ERP nie ma już braków — uzupełnij je na ekranie z linku),
                <b>Zapytanie do dostawcy wysłane</b> (opakowania ; wpisz do kogo i o co — tekst zapytania do skopiowania, ERP niczego nie wysyła) albo <b>Nie dotyczy</b> (z powodem). Krok 3 otworzy się, gdy wszystkie mają decyzję.</p>
            ${tw.tiles([{label: 'Do decyzji', value: String(t.review), sub: `${t.by_kind.material} opakowań · ${t.by_kind.product} produktów · ${t.by_kind.shipment} wysyłek`},
                {label: 'Z decyzją', value: `${t.resolved} z ${t.review}`, sub: t.waiting ? `zostało ${t.waiting}` : 'wszystkie z decyzją', cls: t.waiting ? 'warn' : 'ok'},
                {label: 'Sprawdzone / zapytania', value: `${t.checked} / ${t.supplier_asked}`, sub: t.earlier ? `${t.earlier} z poprzednich miesięcy` : 'w tym przeglądzie'}, {label: 'Nie dotyczy', value: String(t.not_applicable), sub: 'z powodem'}])}
            ${tw.table([{label: 'Element'}, {label: 'Braki i zmiany'}, {label: 'Decyzja'}], rows)}
            <p class="tw-note">Asystent niczego nie zmienia w danych PPWR i BDO. « Nie dotyczy » obowiązuje w kolejnych miesiącach, dopóki braki są te same ; zapytanie do dostawcy wraca co miesiąc, dopóki dane nie zostaną uzupełnione.</p>`;
    }

    function step3() {
        const t = D.totals;
        if (D.done) return tw.done('PPWR i opakowania sprawdzone · ' + (D.done.at || ''), D.done.summary + ' · zadanie zaliczone');
        const decided = D.review.filter(i => eff(i));
        return `<p class="tw-lead">Gotowe : <b>${esc(D.summary)}</b>.</p>
            ${tw.tiles([{label: 'Opakowania', value: `${t.materials.incomplete} z brakami`, sub: `z ${t.materials.used} używanych`},
                {label: 'Produkty', value: `${t.products.incomplete} z brakami`, sub: `z ${t.products.total} aktywnych`},
                {label: 'Wysyłki', value: `${t.shipments.missing} bez kompletu`, sub: `z ${t.shipments.total} (${D.shipments_label})`},
                {label: 'Decyzje', value: `${t.resolved} z ${t.review}`, sub: `${t.checked} sprawdzone · ${t.supplier_asked} zapytania · ${t.not_applicable} nie dotyczy`, cls: 'ok'}])}
            ${decided.length ? `<div class="tw-cap">Decyzje</div>` + tw.table([{label: 'Element'}, {label: 'Braki'}, {label: 'Decyzja'}, {label: 'Notatka'}],
                decided.map(i => ({cells: [what(i), sigs(i) || '<small>uzupełnione</small>', status(i), esc(eff(i).note || '—')]}))) : ''}
            <p class="tw-note">« Zakończ » zalicza zadanie « PPWR i opakowania » na ten miesiąc. Dane PPWR i BDO nie są zmieniane, nic nie jest wysyłane.</p>`;
    }

    function render() {
        const top = canFinish() ? reached : Math.min(reached, 2);
        if (step > top) step = top;
        const body = step === 1 ? step1() : (step === 2 ? step2() : step3());
        const next = step === 1 ? {label: 'Dalej : do decyzji', attr: 'data-pm-next'}
            : step === 2 ? {label: 'Dalej : zatwierdź', attr: 'data-pm-next', disabled: !canFinish()}
                : (D.done ? null : {label: 'Zakończ', attr: 'data-pm-finish', final: true, disabled: busy || !canFinish()});
        app.innerHTML = tw.frame({title: 'PPWR i opakowania', sub: `${D.month_label} · krok ${step} z 3 · ok. 30 min`, steps: STEPS, step, reached: top, body,
            prev: step > 1 ? 'Wstecz' : null, next, later: !D.done && step < 3});
    }

    const refresh = (key) => {
        const i = D.review.find(x => x.key === key), r = draft[key], a = i && i.decision, btn = app.querySelector(`[data-pm-save="${sel(key)}"]`);
        if (!btn || !i) return;
        btn.disabled = !r.decision || busy || (D.note_required.includes(r.decision) && r.note.trim().length < 3) || (a && a.code === r.decision && (a.note || '') === r.note);
    };
    tw.bind(app, (n) => { step = n === -1 ? Math.max(1, step - 1) : n; render(); });
    app.addEventListener('input', (e) => {
        const n = e.target.closest('[data-pm-note]');
        if (!n) return;
        (draft[n.dataset.pmNote] ??= {decision: '', note: ''}).note = n.value;
        refresh(n.dataset.pmNote);
    });
    app.addEventListener('click', async (e) => {
        if (e.target.closest('[data-pm-next]')) { step = Math.min(3, step + 1); reached = Math.max(reached, step); render(); return; }
        const c = e.target.closest('[data-pm-copy]');
        if (c) {
            const i = D.review.find(x => x.key === c.dataset.pmCopy);
            if (i && i.ask) navigator.clipboard?.writeText(i.ask.text).then(() => at.notify(true, 'Skopiowano tekst zapytania.'), () => at.notify(false, 'Nie udało się skopiować — zaznacz tekst ręcznie.'));
            return;
        }
        const r = e.target.closest('[data-pm-dec]');
        if (r) { const key = r.dataset.pmDec; (draft[key] ??= {decision: '', note: ''}).decision = r.dataset.v; render(); app.querySelector(`[data-pm-note="${sel(key)}"]`)?.focus(); return; }
        const s = e.target.closest('[data-pm-save]');
        if (s) {
            const key = s.dataset.pmSave, d = draft[key];
            if (!d || !d.decision || busy) return;
            busy = true; s.disabled = true;
            // seen : braki affichés (si complétés entre-temps dans l'ERP, « uzupełnione » s'enregistre avec eux).
            const it = D.review.find(x => x.key === key);
            const res = await tw.post('/ajax/ppwr/month/decision', {key, decision: d.decision, note: d.note, seen: it ? it.signals.map(x => x.code) : []});
            busy = false;
            if (res.ok) { D = res.data; delete draft[key]; }
            render();
            return;
        }
        if (e.target.closest('[data-pm-finish]')) {
            if (busy) return;
            busy = true; render();
            const res = await tw.post('/ajax/ppwr/month/finish', {});
            busy = false;
            if (res.ok) { at.notify(true, res.message); D = res.data; }
            render();
        }
    });
    render();
})();
