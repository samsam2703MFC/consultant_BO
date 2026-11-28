// [400] Receptury i dane produktów (Kierownik produkcji — co miesiąc) : 1. produits actifs sans recette, avec une recette
// incomplète (sans składnik, sans coût, surowce sans prix, « na 1 sztukę ») ou des données incomplètes (masse, sztuk w
// kartonie, palette, przydatność, kategoria, dział), 2. une décision par produit (uzupełnione — sprawdzone / przekazane do
// uzupełnienia / nie dotyczy), 3. récapitulatif et validation → la tâche du mois se valide seule. Rien n'est changé dans
// les recettes ni les fiches produits depuis cet écran : les liens ouvrent les écrans existants.
(() => {
    const app = document.getElementById('rmApp');
    if (!app || !window.tw) return;
    const {esc} = tw;
    let D = JSON.parse(document.getElementById('rmBoot').textContent);
    let step = 1, reached = 1, busy = false;
    // Brouillons de l'étape 2 (décision, note) par produit, gardés entre deux rendus.
    const draft = {};
    const STEPS = ['Przegląd', 'Do decyzji', 'Zatwierdź'];
    const DEC_CLS = {checked: 'ok', assigned: 'warn', not_applicable: ''};
    const sel = (k) => CSS.escape(k);
    const link = (l) => `<a class="tw-link" href="${at.root}${esc(l.href)}" target="_blank" rel="noopener" style="padding:0"><i class="bi bi-box-arrow-up-right"></i> ${esc(l.label)}</a>`;
    const links = (i) => i.links.length ? `<div style="display:flex;gap:4px 14px;flex-wrap:wrap">${i.links.map(link).join('')}</div>` : '';
    const sigs = (i) => i.signals.map(s => tw.pill(s.label, 'bad')).join(' ');
    const what = (i) => `<b><i class="bi bi-basket"></i> ${esc(i.title)}</b><small>${esc(i.sub || '—')}</small>`;
    const eff = (i) => i.decision || i.earlier;
    const status = (i) => i.waiting ? tw.pill('do uzupełnienia', 'bad')
        : (eff(i) ? tw.pill(eff(i).label + (i.decision ? '' : ' (' + eff(i).month_label + ')'), DEC_CLS[eff(i).code] || '') : tw.pill('bez braków', 'ok'));

    function step1() {
        const t = D.totals, p = t.products, ok = D.source.available;
        const v = (x) => ok ? x : 'brak danych';
        const tiles = [
            {label: 'Produkty z brakami', value: v(`${p.incomplete} / ${p.total}`), sub: ok ? 'aktywne produkty' : D.source.note, cls: ok ? (p.incomplete ? 'warn' : 'ok') : ''},
            {label: 'Bez receptury', value: v(String(p.no_recipe)), sub: 'aktywne, bez przypisanej receptury', cls: ok ? (p.no_recipe ? 'warn' : 'ok') : ''},
            {label: 'Receptury do poprawy', value: v(String(p.recipe)), sub: 'bez składników, bez kosztu, surowce bez ceny, na 1 sztukę', cls: ok ? (p.recipe ? 'warn' : 'ok') : ''},
            {label: 'Dane produktu', value: v(String(p.data)), sub: 'masa, sztuk w kartonie, paleta, przydatność, kategoria, dział', cls: ok ? (p.data ? 'warn' : 'ok') : ''},
            {label: 'Do decyzji', value: String(t.review), sub: t.review ? `${t.resolved} z ${t.review} z decyzją` : 'brak braków', cls: t.waiting ? 'warn' : 'ok'}];
        const head = [{label: 'Produkt'}, {label: 'Braki'}, {label: 'Gdzie uzupełnić'}, {label: ''}];
        const row = (i) => ({cls: i.waiting ? 'hl' : '', cells: [what(i), sigs(i) + i.signals.filter(x => x.detail).map(x => `<small>${esc(x.detail)}</small>`).join(''), links(i), status(i)]});
        const pdf = D.pdf && p.no_recipe ? `<a class="tw-link" style="padding:0" href="${at.root}${esc(D.pdf.href)}" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf"></i> ${esc(D.pdf.label)}</a>` : '';
        return `<p class="tw-lead">Miesiąc <b>${esc(D.month_label)}</b> : receptury i dane aktywnych produktów według reguł ekranów ERP. Każdy produkt z brakiem trafia do kroku 2.</p>
            ${tw.tiles(tiles)}
            ${ok ? '' : tw.alert(esc(D.source.note), '', 'bi-info-circle')}
            ${ok && !D.source.unit_check ? tw.alert('Sprawdzenie « receptura na 1 sztukę » niedostępne — brak tabel w bazie.', '', 'bi-info-circle') : ''}
            <div class="tw-cap" style="display:flex;justify-content:space-between;gap:12px">Do decyzji (${D.review.length}) ${pdf}</div>
            ${tw.table(head, D.review.map(row), 'Brak braków — receptury i dane aktywnych produktów kompletne.')}
            <p class="tw-note">« Kompletne » według ekranów ERP : receptura przypisana, ze składnikami i kosztem kartonu, surowce z ceną, receptura na karton (sprawdzenie « 1 karton »),
                masa produktu (Wagi produktów), sztuk w kartonie i paleta, przydatność do spożycia, kategoria i dział produkcyjny (formularz produktu).
                Alergeny i wartości odżywcze produktu wynikają z surowców — patrz asystent « Kartoteka surowców ».</p>`;
    }

    function decisionCell(i) {
        const a = i.decision;
        const r = draft[i.key] ?? (draft[i.key] = {decision: a ? a.code : '', note: a ? (a.note || '') : ''});
        const seg = Object.keys(D.decisions).map(k => `<button type="button" class="${r.decision === k ? 'on' : ''}" data-rm-dec="${esc(i.key)}" data-v="${k}">${esc(D.decisions[k])}</button>`).join('');
        const need = D.note_required.includes(r.decision);
        const ph = r.decision === 'assigned' ? 'Komu i do kiedy (wymagane) : np. technolog, receptura do 10.12' : (r.decision === 'not_applicable' ? 'Dlaczego nie dotyczy (wymagane)' : 'Notatka (opcjonalnie) : co sprawdzono');
        const saved = a ? `<small>${tw.pill(a.label, DEC_CLS[a.code] || '')} ${esc(a.at)}${a.by ? ' · ' + esc(a.by) : ''}</small>`
            : (i.earlier ? `<small>${tw.pill(i.earlier.label, DEC_CLS[i.earlier.code] || '')} w miesiącu « ${esc(i.earlier.month_label)} »${i.earlier.note ? ' — ' + esc(i.earlier.note) : ''}</small>`
                : (i.previous ? `<small>Poprzednio (${esc(i.previous.month_label)}) : ${esc(i.previous.label)}${i.previous.note ? ' — ' + esc(i.previous.note) : ''}</small>` : ''));
        const dirty = !a || a.code !== r.decision || (a.note || '') !== r.note;
        return `<div style="display:grid;gap:6px;min-width:420px"><div class="tw-seg" style="justify-self:start">${seg}</div>
            <div style="display:flex;gap:6px"><input class="form-control form-control-sm" data-rm-note="${esc(i.key)}" maxlength="500" value="${esc(r.note)}" placeholder="${esc(ph)}" style="min-height:36px;border-radius:9px">
            <button type="button" class="tw-btn ghost sm" data-rm-save="${esc(i.key)}" ${!r.decision || !dirty || busy || (need && r.note.trim().length < 3) ? 'disabled' : ''}>Zapisz</button></div>
            ${links(i)}${i.reason ? `<small class="tw-red">${esc(i.reason)}</small>` : ''}${saved}</div>`;
    }

    function step2() {
        const list = D.review, t = D.totals;
        if (!list.length) return tw.alert('Nic do decyzji : receptury i dane aktywnych produktów kompletne.', 'ok', 'bi-check-circle');
        const rows = list.map(i => ({cls: i.waiting ? 'hl' : '', cells: [what(i),
            i.signals.map(s => `<div style="display:flex;gap:6px;align-items:baseline;margin:1px 0"><i class="bi bi-exclamation-circle tw-red"></i><span><b>${esc(s.label)}</b>${s.detail ? ' : ' + esc(s.detail) : ''}</span></div>`).join('') || '<small>braki uzupełnione</small>',
            decisionCell(i)]}));
        return `<p class="tw-lead">Dla każdego produktu zapisz decyzję : <b>Uzupełnione — sprawdzone</b> (przyjęte, gdy w ERP nie ma już braków — uzupełnij je na ekranie z linku),
                <b>Przekazane do uzupełnienia</b> (komu i do kiedy — wraca w następnym miesiącu, dopóki braki są) albo <b>Nie dotyczy</b> (z powodem). Krok 3 otworzy się, gdy wszystkie mają decyzję.</p>
            ${tw.tiles([{label: 'Do decyzji', value: String(t.review), sub: `${t.products.no_recipe} bez receptury · ${t.products.recipe} receptur do poprawy · ${t.products.data} z danymi`},
                {label: 'Z decyzją', value: `${t.resolved} z ${t.review}`, sub: t.waiting ? `zostało ${t.waiting}` : 'wszystkie z decyzją', cls: t.waiting ? 'warn' : 'ok'},
                {label: 'Sprawdzone / przekazane', value: `${t.checked} / ${t.assigned}`, sub: t.earlier ? `${t.earlier} z poprzednich miesięcy` : 'w tym przeglądzie'}, {label: 'Nie dotyczy', value: String(t.not_applicable), sub: 'z powodem'}])}
            ${tw.table([{label: 'Produkt'}, {label: 'Braki'}, {label: 'Decyzja'}], rows)}
            <p class="tw-note">Asystent niczego nie zmienia w recepturach ani w kartach produktów. « Nie dotyczy » obowiązuje w kolejnych miesiącach, dopóki braki są te same.</p>`;
    }

    function step3() {
        const t = D.totals, p = t.products;
        if (D.done) return tw.done('Receptury i dane produktów sprawdzone · ' + (D.done.at || ''), D.done.summary + ' · zadanie zaliczone');
        const decided = D.review.filter(i => eff(i));
        return `<p class="tw-lead">Gotowe : <b>${esc(D.summary)}</b>.</p>
            ${tw.tiles([{label: 'Produkty', value: `${p.incomplete} z brakami`, sub: `z ${p.total} aktywnych`},
                {label: 'Receptury', value: `${p.no_recipe} bez · ${p.recipe} do poprawy`, sub: 'aktywne produkty'},
                {label: 'Dane produktu', value: String(p.data), sub: 'z brakami'},
                {label: 'Decyzje', value: `${t.resolved} z ${t.review}`, sub: `${t.checked} sprawdzone · ${t.assigned} przekazane · ${t.not_applicable} nie dotyczy`, cls: 'ok'}])}
            ${decided.length ? `<div class="tw-cap">Decyzje</div>` + tw.table([{label: 'Produkt'}, {label: 'Braki'}, {label: 'Decyzja'}, {label: 'Notatka'}],
                decided.map(i => ({cells: [what(i), sigs(i) || '<small>uzupełnione</small>', status(i), esc(eff(i).note || '—')]}))) : ''}
            <p class="tw-note">« Zakończ » zalicza zadanie « Receptury i dane produktów » na ten miesiąc. Receptury i karty produktów nie są zmieniane.</p>`;
    }

    function render() {
        const top = D.can_finish ? reached : Math.min(reached, 2);
        if (step > top) step = top;
        const body = step === 1 ? step1() : (step === 2 ? step2() : step3());
        const next = step === 1 ? {label: 'Dalej : do decyzji', attr: 'data-rm-next'}
            : step === 2 ? {label: 'Dalej : zatwierdź', attr: 'data-rm-next', disabled: !D.can_finish}
                : (D.done ? null : {label: 'Zakończ', attr: 'data-rm-finish', final: true, disabled: busy || !D.can_finish});
        app.innerHTML = tw.frame({title: 'Receptury i dane produktów', sub: `${D.month_label} · krok ${step} z 3 · ok. 30 min`, steps: STEPS, step, reached: top, body,
            prev: step > 1 ? 'Wstecz' : null, next, later: !D.done && step < 3});
    }

    const refresh = (key) => {
        const i = D.review.find(x => x.key === key), r = draft[key], a = i && i.decision, btn = app.querySelector(`[data-rm-save="${sel(key)}"]`);
        if (!btn || !i) return;
        btn.disabled = !r.decision || busy || (D.note_required.includes(r.decision) && r.note.trim().length < 3) || (a && a.code === r.decision && (a.note || '') === r.note);
    };
    tw.bind(app, (n) => { step = n === -1 ? Math.max(1, step - 1) : n; render(); });
    app.addEventListener('input', (e) => {
        const n = e.target.closest('[data-rm-note]');
        if (!n) return;
        (draft[n.dataset.rmNote] ??= {decision: '', note: ''}).note = n.value;
        refresh(n.dataset.rmNote);
    });
    app.addEventListener('click', async (e) => {
        if (e.target.closest('[data-rm-next]')) { step = Math.min(3, step + 1); reached = Math.max(reached, step); render(); return; }
        const r = e.target.closest('[data-rm-dec]');
        if (r) { const key = r.dataset.rmDec; (draft[key] ??= {decision: '', note: ''}).decision = r.dataset.v; render(); app.querySelector(`[data-rm-note="${sel(key)}"]`)?.focus(); return; }
        const s = e.target.closest('[data-rm-save]');
        if (s) {
            const key = s.dataset.rmSave, d = draft[key];
            if (!d || !d.decision || busy) return;
            busy = true; s.disabled = true;
            // seen : braki affichés (si complétés entre-temps dans l'ERP, « uzupełnione » s'enregistre avec eux).
            const it = D.review.find(x => x.key === key);
            const res = await tw.post('/ajax/recipes/month/decision', {key, decision: d.decision, note: d.note, seen: it ? it.signals.map(x => x.code) : []});
            busy = false;
            if (res.ok) { D = res.data; delete draft[key]; }
            render();
            return;
        }
        if (e.target.closest('[data-rm-finish]')) {
            if (busy) return;
            busy = true; render();
            const res = await tw.post('/ajax/recipes/month/finish', {});
            busy = false;
            if (res.ok) { at.notify(true, res.message); D = res.data; }
            render();
        }
    });
    render();
})();
