// [401] Kartoteka surowców (Kierownik produkcji — co miesiąc) : 1. surowce des recettes actives dont la fiche est incomplète
// (kategoria, jednostka, cena, wielkość opakowania, dostawca, wartości odżywcze) et nowe surowce depuis le dernier contrôle,
// 2. une décision par surowiec (uzupełnione — sprawdzone / zapytanie do dostawcy / nie dotyczy), 3. récapitulatif et
// validation → la tâche du mois se valide seule. Rien n'est changé dans les fiches depuis cet écran (liens vers les écrans
// existants) et rien n'est envoyé : la demande au dostawca est faite par l'humain.
(() => {
    const app = document.getElementById('mmApp');
    if (!app || !window.tw) return;
    const {esc} = tw;
    let D = JSON.parse(document.getElementById('mmBoot').textContent);
    let step = 1, reached = 1, busy = false;
    // Brouillons de l'étape 2 (décision, note) par surowiec, gardés entre deux rendus.
    const draft = {};
    const STEPS = ['Przegląd', 'Do decyzji', 'Zatwierdź'];
    const DEC_CLS = {checked: 'ok', supplier_asked: 'warn', not_applicable: ''};
    const sel = (k) => CSS.escape(k);
    const link = (l) => `<a class="tw-link" href="${at.root}${esc(l.href)}" target="_blank" rel="noopener" style="padding:0"><i class="bi bi-box-arrow-up-right"></i> ${esc(l.label)}</a>`;
    const links = (i) => i.links.length ? `<div style="display:flex;gap:4px 14px;flex-wrap:wrap">${i.links.map(link).join('')}</div>` : '';
    const sigs = (i) => i.signals.map(s => tw.pill(s.code === 'NEW' ? 'nowy' : s.label, s.code === 'NEW' ? '' : 'bad')).join(' ');
    const what = (i) => `<b><i class="bi ${i.food ? 'bi-egg' : 'bi-box2'}"></i> ${esc(i.title)}</b><small>${esc(i.sub || '—')}</small>`;
    const eff = (i) => i.decision || i.earlier;
    const status = (i) => i.waiting ? tw.pill(i.codes.length ? 'do uzupełnienia' : 'do sprawdzenia', 'bad')
        : (eff(i) ? tw.pill(eff(i).label + (i.decision ? '' : ' (' + eff(i).month_label + ')'), DEC_CLS[eff(i).code] || '') : tw.pill('bez braków', 'ok'));

    function step1() {
        const t = D.totals, m = t.materials, ok = D.source.available;
        const v = (x) => ok ? x : 'brak danych';
        const tiles = [
            {label: 'Surowce z brakami', value: v(`${m.incomplete} / ${m.used}`), sub: ok ? `używane w aktywnych recepturach${m.unused_incomplete ? ` · ${m.unused_incomplete} nieużywanych z brakami (bez sygnału)` : ''}` : D.source.note, cls: ok ? (m.incomplete ? 'warn' : 'ok') : ''},
            {label: 'Nowe surowce', value: D.new_rule ? v(String(m.new)) : 'brak danych', sub: D.new_rule === 'mark' ? `od ostatniego przeglądu (${D.new_since_label})` : (D.new_rule ? `pierwsza cena od ${D.new_since_label}` : 'brak historii cen — od następnego przeglądu')},
            {label: 'Bez ceny', value: v(String(t.by_code.NO_PRICE)), sub: 'cena aktualna surowca', cls: ok ? (t.by_code.NO_PRICE ? 'warn' : 'ok') : ''},
            {label: 'Bez wartości odżywczych', value: D.references.nutrition ? v(String(t.by_code.NO_NUTRITION)) : 'brak danych', sub: 'surowce spożywcze', cls: ok && D.references.nutrition ? (t.by_code.NO_NUTRITION ? 'warn' : 'ok') : ''},
            {label: 'Do decyzji', value: String(t.review), sub: t.review ? `${t.resolved} z ${t.review} z decyzją` : 'brak braków i nowych', cls: t.waiting ? 'warn' : 'ok'}];
        const head = [{label: 'Surowiec'}, {label: 'Braki i nowe'}, {label: 'Gdzie uzupełnić'}, {label: ''}];
        const row = (i) => ({cls: i.waiting ? 'hl' : '', cells: [what(i), sigs(i) + i.signals.filter(x => x.detail).map(x => `<small>${esc(x.detail)}</small>`).join(''), links(i), status(i)]});
        const allergen = D.references.allergens && ok ? tw.alert(`Alergeny : ${m.no_allergen} ${tw.plural(m.no_allergen, 'surowiec spożywczy', 'surowce spożywcze', 'surowców spożywczych')} bez żadnego wpisanego alergenu.
            ERP nie odróżnia « bez alergenów » od « nie wpisano » — nie są sygnalizowane ; sprawdź je przy karcie surowca, jeśli są nowe.`, '', 'bi-info-circle') : '';
        return `<p class="tw-lead">Miesiąc <b>${esc(D.month_label)}</b> : karty surowców używanych w aktywnych recepturach i nowe surowce. Każdy surowiec z brakiem albo nowy trafia do kroku 2.</p>
            ${tw.tiles(tiles)}
            ${ok ? '' : tw.alert(esc(D.source.note), '', 'bi-info-circle')}
            ${allergen}
            <div class="tw-cap">Do decyzji (${D.review.length})</div>
            ${tw.table(head, D.review.map(row), 'Brak braków i nowych surowców — karty kompletne.')}
            <p class="tw-note">« Kompletne » według karty surowca w ERP : kategoria, jednostka bazowa, aktualna cena i wielkość opakowania, dostawca (aktywny), wartości odżywcze (surowce spożywcze — ekran Wartości odżywcze).
                Ceny dostawców i ich zmiany sprawdza asystent « Koszty surowców », dane PPWR opakowań — asystent « PPWR i opakowania ».</p>`;
    }

    function decisionCell(i) {
        const a = i.decision;
        const r = draft[i.key] ?? (draft[i.key] = {decision: a ? a.code : '', note: a ? (a.note || '') : ''});
        const seg = Object.keys(D.decisions).map(k => `<button type="button" class="${r.decision === k ? 'on' : ''}" data-mm-dec="${esc(i.key)}" data-v="${k}">${esc(D.decisions[k])}</button>`).join('');
        const need = D.note_required.includes(r.decision);
        const ph = r.decision === 'supplier_asked' ? 'Do kogo i o co (wymagane) : dostawca, cena, wartości odżywcze, data' : (r.decision === 'not_applicable' ? 'Dlaczego nie dotyczy (wymagane)' : 'Notatka (opcjonalnie) : co sprawdzono');
        const saved = a ? `<small>${tw.pill(a.label, DEC_CLS[a.code] || '')} ${esc(a.at)}${a.by ? ' · ' + esc(a.by) : ''}</small>`
            : (i.earlier ? `<small>${tw.pill(i.earlier.label, DEC_CLS[i.earlier.code] || '')} w miesiącu « ${esc(i.earlier.month_label)} »${i.earlier.note ? ' — ' + esc(i.earlier.note) : ''}</small>`
                : (i.previous ? `<small>Poprzednio (${esc(i.previous.month_label)}) : ${esc(i.previous.label)}${i.previous.note ? ' — ' + esc(i.previous.note) : ''}</small>` : ''));
        const dirty = !a || a.code !== r.decision || (a.note || '') !== r.note;
        return `<div style="display:grid;gap:6px;min-width:420px"><div class="tw-seg" style="justify-self:start">${seg}</div>
            <div style="display:flex;gap:6px"><input class="form-control form-control-sm" data-mm-note="${esc(i.key)}" maxlength="500" value="${esc(r.note)}" placeholder="${esc(ph)}" style="min-height:36px;border-radius:9px">
            <button type="button" class="tw-btn ghost sm" data-mm-save="${esc(i.key)}" ${!r.decision || !dirty || busy || (need && r.note.trim().length < 3) ? 'disabled' : ''}>Zapisz</button></div>
            ${links(i)}${i.reason ? `<small class="tw-red">${esc(i.reason)}</small>` : ''}${saved}</div>`;
    }

    function step2() {
        const list = D.review, t = D.totals;
        if (!list.length) return tw.alert('Nic do decyzji : karty surowców kompletne, brak nowych surowców.', 'ok', 'bi-check-circle');
        const rows = list.map(i => ({cls: i.waiting ? 'hl' : '', cells: [what(i),
            i.signals.map(s => `<div style="display:flex;gap:6px;align-items:baseline;margin:1px 0"><i class="bi ${s.code === 'NEW' ? 'bi-plus-circle' : 'bi-exclamation-circle tw-red'}"></i><span><b>${esc(s.label)}</b>${s.detail ? ' : ' + esc(s.detail) : ''}</span></div>`).join('') || '<small>braki uzupełnione</small>',
            decisionCell(i)]}));
        return `<p class="tw-lead">Dla każdego surowca zapisz decyzję : <b>Uzupełnione — sprawdzone</b> (przyjęte, gdy w karcie nie ma już braków — uzupełnij je na ekranie z linku ; nowy surowiec bez braków : sprawdzony),
                <b>Zapytanie do dostawcy wysłane</b> (do kogo i o co — wraca w następnym miesiącu, dopóki braki są ; ERP niczego nie wysyła) albo <b>Nie dotyczy</b> (z powodem). Krok 3 otworzy się, gdy wszystkie mają decyzję.</p>
            ${tw.tiles([{label: 'Do decyzji', value: String(t.review), sub: `${t.materials.incomplete} z brakami · ${t.materials.new} ${tw.plural(t.materials.new, 'nowy', 'nowe', 'nowych')}`},
                {label: 'Z decyzją', value: `${t.resolved} z ${t.review}`, sub: t.waiting ? `zostało ${t.waiting}` : 'wszystkie z decyzją', cls: t.waiting ? 'warn' : 'ok'},
                {label: 'Sprawdzone / zapytania', value: `${t.checked} / ${t.supplier_asked}`, sub: t.earlier ? `${t.earlier} z poprzednich miesięcy` : 'w tym przeglądzie'}, {label: 'Nie dotyczy', value: String(t.not_applicable), sub: 'z powodem'}])}
            ${tw.table([{label: 'Surowiec'}, {label: 'Braki i nowe'}, {label: 'Decyzja'}], rows)}
            <p class="tw-note">Asystent niczego nie zmienia w kartach surowców i niczego nie wysyła. « Nie dotyczy » obowiązuje w kolejnych miesiącach, dopóki braki są te same ; zapytanie do dostawcy wraca co miesiąc, dopóki karta nie jest uzupełniona.</p>`;
    }

    function step3() {
        const t = D.totals, m = t.materials;
        if (D.done) return tw.done('Kartoteka surowców sprawdzona · ' + (D.done.at || ''), D.done.summary + ' · zadanie zaliczone');
        const decided = D.review.filter(i => eff(i));
        return `<p class="tw-lead">Gotowe : <b>${esc(D.summary)}</b>.</p>
            ${tw.tiles([{label: 'Surowce', value: `${m.incomplete} z brakami`, sub: `z ${m.used} używanych`},
                {label: 'Nowe', value: String(m.new), sub: D.new_rule ? 'od ostatniego przeglądu' : 'brak historii'},
                {label: 'Wszystkie w ERP', value: String(m.total), sub: m.unused_incomplete ? `${m.unused_incomplete} nieużywanych z brakami` : 'karty'},
                {label: 'Decyzje', value: `${t.resolved} z ${t.review}`, sub: `${t.checked} sprawdzone · ${t.supplier_asked} zapytania · ${t.not_applicable} nie dotyczy`, cls: 'ok'}])}
            ${decided.length ? `<div class="tw-cap">Decyzje</div>` + tw.table([{label: 'Surowiec'}, {label: 'Braki'}, {label: 'Decyzja'}, {label: 'Notatka'}],
                decided.map(i => ({cells: [what(i), sigs(i) || '<small>uzupełnione</small>', status(i), esc(eff(i).note || '—')]}))) : ''}
            <p class="tw-note">« Zakończ » zalicza zadanie « Kartoteka surowców » na ten miesiąc i zapamiętuje, które surowce już są — następny przegląd pokaże tylko nowsze. Karty nie są zmieniane, nic nie jest wysyłane.</p>`;
    }

    function render() {
        const top = D.can_finish ? reached : Math.min(reached, 2);
        if (step > top) step = top;
        const body = step === 1 ? step1() : (step === 2 ? step2() : step3());
        const next = step === 1 ? {label: 'Dalej : do decyzji', attr: 'data-mm-next'}
            : step === 2 ? {label: 'Dalej : zatwierdź', attr: 'data-mm-next', disabled: !D.can_finish}
                : (D.done ? null : {label: 'Zakończ', attr: 'data-mm-finish', final: true, disabled: busy || !D.can_finish});
        app.innerHTML = tw.frame({title: 'Kartoteka surowców', sub: `${D.month_label} · krok ${step} z 3 · ok. 30 min`, steps: STEPS, step, reached: top, body,
            prev: step > 1 ? 'Wstecz' : null, next, later: !D.done && step < 3});
    }

    const refresh = (key) => {
        const i = D.review.find(x => x.key === key), r = draft[key], a = i && i.decision, btn = app.querySelector(`[data-mm-save="${sel(key)}"]`);
        if (!btn || !i) return;
        btn.disabled = !r.decision || busy || (D.note_required.includes(r.decision) && r.note.trim().length < 3) || (a && a.code === r.decision && (a.note || '') === r.note);
    };
    tw.bind(app, (n) => { step = n === -1 ? Math.max(1, step - 1) : n; render(); });
    app.addEventListener('input', (e) => {
        const n = e.target.closest('[data-mm-note]');
        if (!n) return;
        (draft[n.dataset.mmNote] ??= {decision: '', note: ''}).note = n.value;
        refresh(n.dataset.mmNote);
    });
    app.addEventListener('click', async (e) => {
        if (e.target.closest('[data-mm-next]')) { step = Math.min(3, step + 1); reached = Math.max(reached, step); render(); return; }
        const r = e.target.closest('[data-mm-dec]');
        if (r) { const key = r.dataset.mmDec; (draft[key] ??= {decision: '', note: ''}).decision = r.dataset.v; render(); app.querySelector(`[data-mm-note="${sel(key)}"]`)?.focus(); return; }
        const s = e.target.closest('[data-mm-save]');
        if (s) {
            const key = s.dataset.mmSave, d = draft[key];
            if (!d || !d.decision || busy) return;
            busy = true; s.disabled = true;
            // seen : braki affichés (si complétés entre-temps dans l'ERP, « uzupełnione » s'enregistre avec eux).
            const it = D.review.find(x => x.key === key);
            const res = await tw.post('/ajax/materials/month/decision', {key, decision: d.decision, note: d.note, seen: it ? it.signals.map(x => x.code) : []});
            busy = false;
            if (res.ok) { D = res.data; delete draft[key]; }
            render();
            return;
        }
        if (e.target.closest('[data-mm-finish]')) {
            if (busy) return;
            busy = true; render();
            const res = await tw.post('/ajax/materials/month/finish', {});
            busy = false;
            if (res.ok) { at.notify(true, res.message); D = res.data; }
            render();
        }
    });
    render();
})();
