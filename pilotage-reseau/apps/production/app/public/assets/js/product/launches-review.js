// [384] Nowe produkty (Kierownik produkcji — co miesiąc) : 1. wprowadzenia ostatnich miesięcy — cele z modułu Kierownik
// produkcji (koszt, cena, marża min., kartony / sklep / tydzień) wobec wyniku (koszt surowców, cena, marża, sprzedaż od
// wprowadzenia) i decyzja modułu, 2. une décision par lancement signalé (zostaje / korekta / wycofać), 3. récapitulatif et
// validation → la tâche du mois se valide seule. Rien n'est changé depuis cet écran : la décision se reporte dans le module.
(() => {
    const app = document.getElementById('lrApp');
    const boot = document.getElementById('lrBoot');
    if (!app || !boot || !window.tw) return;
    const {esc} = tw;
    let D = JSON.parse(boot.textContent);
    let step = 1, reached = 1, busy = false;
    // Brouillons de l'étape 2 (décision, domaine, note) par produit, gardés entre deux rendus.
    const draft = {};
    const STEPS = ['Przegląd', 'Do wyjaśnienia', 'Zatwierdź'];
    const DEC_CLS = {keep: 'ok', fix: 'warn', stop: 'bad'};
    const SIG_CLS = {margin_below: 'bad', cost_over: 'bad', price_under: 'warn', no_sales: 'bad', volume_below: 'warn', no_targets: '', no_decision: ''};
    const SHORT = {margin_below: 'marża', cost_over: 'koszt', price_under: 'cena', no_sales: 'brak sprzedaży', volume_below: 'wolumen', no_targets: 'bez celów', no_decision: 'bez decyzji'};
    const num = (v, d = 2) => v === null || v === undefined ? '—' : Number(v).toFixed(d).replace('.', ',');
    const eur = (v) => v === null || v === undefined ? '—' : num(v) + ' €';
    const pctv = (v) => v === null || v === undefined ? '—' : num(v, 1) + ' %';
    // Écart à l'objectif, signé ; en rouge quand il est défavorable (coût au-dessus, prix / marge / ventes en dessous).
    const signed = (v, suffix, worseAbove = false) => v === null || v === undefined ? '' : `<span class="${(worseAbove ? v > 0 : v < 0) ? 'tw-red' : ''}">(${v > 0 ? '+' : ''}${num(v, 0)}${suffix})</span>`;
    const canFinish = () => !!D.can_finish;
    const link = (l) => l ? `<a class="tw-link" href="${at.root}${esc(l.href)}" target="_blank" rel="noopener" style="padding:0"><i class="bi bi-box-arrow-up-right"></i> ${esc(l.label)}</a>` : '';
    const sigs = (i) => i.signals.map(s => tw.pill(SHORT[s.code] || s.code, SIG_CLS[s.code] || '')).join(' ') || tw.pill('w celu', 'ok');
    const what = (i) => `<b>${esc(i.name)}</b><small>${i.launch_source === 'launch' ? 'wprowadzenie' : 'utworzony w ERP'} ${esc(i.launch_label)} · ${i.weeks} tyg.${i.active ? '' : ' · nieaktywny'}${i.too_early ? ' · za wcześnie na sprzedaż' : ''}</small>`;
    const eff = (i) => i.decision || i.earlier;
    const status = (i) => !i.in_review ? tw.pill('bez sygnału', 'ok') : (i.waiting ? tw.pill('do wyjaśnienia', 'bad')
        : tw.pill(eff(i).label + (i.decision ? '' : ' (' + eff(i).month_label + ')'), DEC_CLS[eff(i).code] || ''));
    const mgr = (i) => i.manager_decision ? `${tw.pill(i.manager_decision.label, DEC_CLS[i.manager_decision.code] || '')}${i.manager_decision.note ? `<small>${esc(i.manager_decision.note)}</small>` : ''}` : '<span style="color:#999">brak</span>';
    const targets = (i) => {
        const t = i.targets;
        const money = D.values ? `<small>koszt ${eur(t.cost)} · cena ${eur(t.price)} · marża min. ${pctv(t.min_margin)}</small>` : '';
        return i.has_targets ? `${money}<small>${num(t.expected, 1)} kart. / sklep / tydz.</small>` : '<span style="color:#999">brak celów</span>';
    };
    const actual = (i) => {
        const a = i.actual, g = i.gap;
        const src = {sales: 'sprzedaż', card: 'karta produktu', list: 'cennik'};
        const money = D.values ? `<small>koszt ${eur(a.cost)}${a.cost_source ? ' (' + src[a.cost_source] + ')' : ''} ${signed(g.cost_pct, ' %', true)}</small>
            <small>cena ${eur(a.price)}${a.price_source ? ' (' + src[a.price_source] + ')' : ''} ${signed(g.price_pct, ' %')}</small>
            <small>marża ${pctv(a.margin)} ${signed(g.margin_pts, ' pkt')}</small>` : '';
        return `${money}<small>${a.cartons ? num(a.per_shop_week, 1) + ' kart. / sklep / tydz. · ' + num(a.cartons, 0) + ' kart. · ' + a.shops + ' ' + tw.plural(a.shops, 'sklep', 'sklepy', 'sklepów') : 'brak sprzedaży'}
            ${a.cartons && g.volume_pct !== null ? signed(g.volume_pct, ' %') : ''}</small>`;
    };
    const srcVal = (k, n) => D.sources[k].available ? String(n) : 'brak danych';

    function step1() {
        const t = D.totals, src = D.sources;
        const head = [{label: 'Produkt'}, {label: 'Cele'}, {label: 'Wynik od wprowadzenia'}, {label: 'Decyzja modułu'}, {label: 'Sygnały'}, {label: ''}];
        const row = (i) => ({cls: i.waiting ? 'hl' : '', cells: [what(i), targets(i), actual(i), mgr(i), sigs(i) + (i.link ? `<div>${link(i.link)}</div>` : ''), status(i)]});
        const tiles = [
            {label: 'Nowe produkty', value: String(t.launches), sub: `${t.from_launch} z modułu · ${t.created} utworzone w ERP (od ${esc(D.since_label)})`},
            {label: 'Z celami', value: srcVal('launch', t.with_targets), sub: src.launch.available ? `z ${t.launches} produktów` : 'cele wprowadzenia', cls: src.launch.available ? '' : 'warn'},
            {label: 'Poza celem', value: String(t.off_target), sub: `${t.with_sales} ze sprzedażą · ${t.too_early} za wcześnie`, cls: t.off_target ? 'warn' : 'ok'},
            {label: 'Decyzja w module', value: `${t.manager_decided} z ${t.launches}`, sub: 'n_mgr_launch.decision', cls: t.manager_decided < t.launches ? 'warn' : 'ok'},
            {label: 'Do wyjaśnienia', value: String(t.review), sub: t.review ? `${t.resolved} z ${t.review} z decyzją` : 'brak sygnałów', cls: t.waiting ? 'warn' : 'ok'}];
        const notes = Object.values(src).filter(s => s.note).map(s => `<div><b>${esc(s.from)}</b> : ${esc(s.note)}</div>`);
        if (src.launch.admin_note) notes.push(`<div><b>n_mgr_launch</b> : ${esc(src.launch.admin_note)}</div>`);
        if (!D.values) notes.push('<div><b>Kwoty</b> : koszty, ceny i marże (z odchyleniami) widoczne z prawem « Marże i koszty » — tu tylko sygnały i sprzedaż w kartonach.</div>');
        return `<p class="tw-lead">Produkty wprowadzone od <b>${esc(D.since_label)}</b> (${D.params.months} mies.) : cele z modułu Kierownik produkcji wobec wyniku — koszt surowców i cena za karton, marża po surowcach i sprzedaż na sklep tygodniowo od wprowadzenia.
                Data wprowadzenia z modułu, a bez niej data utworzenia produktu w ERP.</p>
            ${tw.tiles(tiles)}
            ${notes.length ? tw.alert(notes.join(''), '', 'bi-info-circle') : ''}
            <div class="tw-cap">${src.launch.available ? 'Wprowadzenia' : 'Produkty utworzone ostatnio (bez celów)'} (${D.items.length})</div>
            ${tw.table(head, D.items.map(row), 'Brak nowych produktów w okresie.')}
            <p class="tw-note">Do wyjaśnienia : koszt powyżej celu o ponad ${D.params.cost_over} %, cena poniżej celu o ponad ${D.params.price_under} %, marża poniżej minimum${D.params.margin_gap ? ' o ponad ' + D.params.margin_gap + ' pkt' : ''},
                po ${D.params.min_weeks} tyg. : sprzedaż poniżej oczekiwanej o ponad ${D.params.volume_under} % albo brak sprzedaży ; także każde wprowadzenie bez decyzji w module (launches_review.*).
                Koszt i marża jak w Rentowności i raporcie kierownika (surowce sprzedanych kartonów) ; bez sprzedaży — koszt z karty produktu, cena standardowa.</p>`;
    }

    function decisionCell(i) {
        const a = i.decision;
        const r = draft[i.key] ?? (draft[i.key] = {decision: a ? a.code : '', note: a ? (a.note || '') : '', fix_area: a && a.fix_area ? a.fix_area : ''});
        const seg = Object.entries(D.decisions).map(([k, l]) => `<button type="button" class="${r.decision === k ? 'on' : ''}" data-lr-dec="${esc(i.key)}" data-v="${k}">${esc(l)}</button>`).join('');
        const need = D.note_required.includes(r.decision);
        const ph = r.decision === 'fix' ? 'Korekta (wymagane) : co, kto, do kiedy' : (r.decision === 'stop' ? 'Wycofać (wymagane) : powód, od kiedy' : 'Notatka : dlaczego zostaje');
        const area = r.decision === 'fix' ? `<select class="form-select form-select-sm" data-lr-area="${esc(i.key)}" style="max-width:260px"><option value="">Czego dotyczy (opcjonalnie)</option>
                ${Object.entries(D.fix_areas).map(([k, l]) => `<option value="${k}" ${r.fix_area === k ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select>` : '';
        const saved = a ? `<small>${tw.pill(a.label, DEC_CLS[a.code] || '')}${a.fix_area_label ? ' ' + esc(a.fix_area_label) : ''} ${esc(a.at)}${a.by ? ' · ' + esc(a.by) : ''}</small>`
            : (i.earlier ? `<small>${tw.pill(i.earlier.label, DEC_CLS[i.earlier.code] || '')} w przeglądzie « ${esc(i.earlier.month_label)} »${i.earlier.note ? ' — ' + esc(i.earlier.note) : ''}</small>` : '');
        const dirty = !a || a.code !== r.decision || (a.note || '') !== r.note || (a.fix_area || '') !== r.fix_area;
        return `<div style="display:grid;gap:6px;min-width:400px"><div class="tw-seg" style="justify-self:start">${seg}</div>${area}
            <div style="display:flex;gap:6px"><input class="form-control form-control-sm" data-lr-note="${esc(i.key)}" maxlength="500" value="${esc(r.note)}" placeholder="${esc(ph)}" style="min-height:36px;border-radius:9px">
            <button type="button" class="tw-btn ghost sm" data-lr-save="${esc(i.key)}" ${!r.decision || !dirty || busy || (need && r.note.trim().length < 3) ? 'disabled' : ''}>Zapisz</button></div>
            ${i.link ? `<div>${link(i.link)}</div>` : ''}${saved}</div>`;
    }

    function step2() {
        const list = D.review, t = D.totals;
        if (!list.length) return tw.alert('Nic do wyjaśnienia : wszystkie nowe produkty są w celach i mają decyzję w module.', 'ok', 'bi-check-circle');
        const rows = list.map(i => ({cls: i.waiting ? 'hl' : '', cells: [what(i) + `<small>moduł : ${i.manager_decision ? esc(i.manager_decision.label) : 'bez decyzji'}</small>`,
            i.signals.map(s => `<div style="display:flex;gap:6px;align-items:baseline;margin:1px 0"><i class="bi bi-exclamation-circle ${SIG_CLS[s.code] === 'bad' ? 'tw-red' : ''}"></i><span><b>${esc(s.label)}</b> : ${esc(s.detail)}</span></div>`).join(''),
            decisionCell(i)]}));
        const where = D.manager_link ? `Decyzję przenieś do modułu : ${link(D.manager_link)}.` : `Decyzję w module (${esc(D.manager_where)}) wpisuje administrator — strona tylko dla administratora.`;
        return `<p class="tw-lead">Dla każdego produktu z sygnałem zapisz decyzję : <b>Zostaje</b> (z notatką), <b>Korekta</b> (notatka wymagana : cena, receptura, koszt…) albo <b>Wycofać</b> (notatka wymagana).
                Krok 3 otworzy się, gdy wszystkie mają decyzję.</p>
            ${tw.tiles([{label: 'Do wyjaśnienia', value: String(t.review), sub: `${t.off_target} poza celem · ${t.review - t.off_target} bez decyzji w module`},
                {label: 'Z decyzją', value: `${t.resolved} z ${t.review}`, sub: t.waiting ? `zostało ${t.waiting}` : 'wszystkie wyjaśnione', cls: t.waiting ? 'warn' : 'ok'},
                {label: 'Zostaje / korekta / wycofać', value: `${t.keep} / ${t.fix} / ${t.stop}`, sub: t.earlier ? `${t.earlier} z poprzedniego przeglądu` : 'w tym przeglądzie'}])}
            ${tw.alert(where + ' Asystent nie zmienia decyzji modułu, produktów, receptur ani cen.', '', 'bi-signpost-split')}
            ${tw.table([{label: 'Produkt'}, {label: 'Problem'}, {label: 'Decyzja'}], rows)}
            <p class="tw-note">Produkt bez odchyleń, zdecydowany w poprzednim przeglądzie, nie wraca ; produkt poza celem wraca co miesiąc, dopóki jest w oknie ${D.params.months} mies.</p>`;
    }

    function step3() {
        const t = D.totals;
        if (D.done) return tw.done('Nowe produkty przejrzane · ' + (D.done.at || ''), D.done.summary + ' · zadanie zaliczone');
        const decided = D.review.filter(i => eff(i));
        return `<p class="tw-lead">Gotowe : <b>${esc(D.summary)}</b>.</p>
            ${tw.tiles([{label: 'Nowe produkty', value: String(t.launches), sub: `${t.with_sales} ze sprzedażą`}, {label: 'Poza celem', value: String(t.off_target), sub: `${t.by_signal.margin_below} marża · ${t.by_signal.cost_over} koszt · ${t.by_signal.no_sales + t.by_signal.volume_below} sprzedaż`},
                {label: 'Decyzje', value: `${t.resolved} z ${t.review}`, sub: `${t.keep} zostaje · ${t.fix} korekta · ${t.stop} wycofać`, cls: 'ok'}])}
            ${decided.length ? `<div class="tw-cap">Decyzje</div>` + tw.table([{label: 'Produkt'}, {label: 'Sygnały'}, {label: 'Decyzja'}, {label: 'Notatka'}],
                decided.map(i => ({cells: [what(i), sigs(i), status(i) + (eff(i).fix_area_label ? `<small>${esc(eff(i).fix_area_label)}</small>` : ''), esc(eff(i).note || '—')]}))) : ''}
            <p class="tw-note">« Zakończ » zalicza zadanie « Nowe produkty » na ten miesiąc. ${D.manager_link ? 'Decyzje przenieś do modułu Kierownik produkcji (' + link(D.manager_link) + ').' : 'Decyzje w module Kierownik produkcji wpisuje administrator.'}</p>`;
    }

    function render() {
        const top = canFinish() ? reached : Math.min(reached, 2);
        if (step > top) step = top;
        const body = step === 1 ? step1() : (step === 2 ? step2() : step3());
        const next = step === 1 ? {label: 'Dalej : do wyjaśnienia', attr: 'data-lr-next'}
            : step === 2 ? {label: 'Dalej : zatwierdź', attr: 'data-lr-next', disabled: !canFinish()}
                : (D.done ? null : {label: 'Zakończ', attr: 'data-lr-finish', final: true, disabled: busy || !canFinish()});
        app.innerHTML = tw.frame({title: 'Nowe produkty', sub: `przegląd ${D.month_label} · krok ${step} z 3 · ok. 20 min`, steps: STEPS, step, reached: top, body,
            prev: step > 1 ? 'Wstecz' : null, next, later: !D.done && step < 3});
    }

    const refresh = (key) => {
        const i = D.review.find(x => x.key === key), r = draft[key], a = i && i.decision, btn = app.querySelector(`[data-lr-save="${CSS.escape(key)}"]`);
        if (!btn || !i) return;
        const need = D.note_required.includes(r.decision);
        btn.disabled = !r.decision || busy || (need && r.note.trim().length < 3) || (a && a.code === r.decision && (a.note || '') === r.note && (a.fix_area || '') === r.fix_area);
    };
    tw.bind(app, (n) => { step = n === -1 ? Math.max(1, step - 1) : n; render(); });
    app.addEventListener('input', (e) => {
        const n = e.target.closest('[data-lr-note]');
        if (!n) return;
        (draft[n.dataset.lrNote] ??= {decision: '', note: '', fix_area: ''}).note = n.value;
        refresh(n.dataset.lrNote);
    });
    app.addEventListener('change', (e) => {
        const s = e.target.closest('[data-lr-area]');
        if (!s) return;
        draft[s.dataset.lrArea].fix_area = s.value;
        refresh(s.dataset.lrArea);
    });
    app.addEventListener('click', async (e) => {
        if (e.target.closest('[data-lr-next]')) { step = Math.min(3, step + 1); reached = Math.max(reached, step); render(); return; }
        const r = e.target.closest('[data-lr-dec]');
        if (r) { const key = r.dataset.lrDec; (draft[key] ??= {decision: '', note: '', fix_area: ''}).decision = r.dataset.v; render(); app.querySelector(`[data-lr-note="${CSS.escape(key)}"]`)?.focus(); return; }
        const s = e.target.closest('[data-lr-save]');
        if (s) {
            const key = s.dataset.lrSave, d = draft[key], it = D.review.find(x => x.key === key);
            if (!d || !d.decision || busy || !it) return;
            busy = true; s.disabled = true;
            const body = {id_product: it.id_product, decision: d.decision, note: d.note};
            if (d.decision === 'fix') body.fix_area = d.fix_area || null;
            const res = await tw.post('/ajax/products/launches-review/decision', body);
            busy = false;
            if (res.ok) { D = res.data; delete draft[key]; }
            render();
            return;
        }
        if (e.target.closest('[data-lr-finish]')) {
            if (busy) return;
            busy = true; render();
            const res = await tw.post('/ajax/products/launches-review/finish', {});
            busy = false;
            if (res.ok) { at.notify(true, res.message); D = res.data; }
            render();
        }
    });
    render();
})();
