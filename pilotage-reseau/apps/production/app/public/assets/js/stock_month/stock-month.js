// [403] Stany magazynowe na koniec miesiąca (Kierownik produkcji — co miesiąc) : 1. état des produits finis et des surowce
// à la fin du mois écoulé, enregistré comme instantané (l'ERP n'a pas d'état daté : PDF et CSV viennent toujours de
// l'instantané), 2. une décision par ligne signalée (sprawdzone — stan poprawny / do korekty, avec note) puis
// « Przekazane do księgowości » pour l'ensemble (note : komu, jak, kiedy — l'ERP n'envoie rien), 3. récapitulatif et
// validation → la tâche du mois se valide seule. Aucun stock ni prix n'est changé depuis cet écran.
(() => {
    const app = document.getElementById('smApp');
    if (!app || !window.tw) return;
    const {esc} = tw;
    let D = JSON.parse(document.getElementById('smBoot').textContent);
    let step = 1, reached = 1, busy = false;
    // Brouillons de l'étape 2 (décision, note) par ligne, gardés entre deux rendus.
    const draft = {};
    const STEPS = ['Zestawienie', 'Do decyzji i przekazanie', 'Zatwierdź'];
    const DEC_CLS = {confirmed: 'ok', to_correct: 'warn', handed: 'ok'};
    const sel = (k) => CSS.escape(k);
    const zl = (v) => v === null || v === undefined ? '—' : tw.nf(v, 2) + ' zł';
    const link = (l) => `<a class="tw-link" href="${at.root}${esc(l.href)}" target="_blank" rel="noopener" style="padding:0"><i class="bi bi-box-arrow-up-right"></i> ${esc(l.label)}</a>`;
    const links = (i) => i.links.length ? `<div style="display:flex;gap:4px 14px;flex-wrap:wrap">${i.links.map(link).join('')}</div>` : '';
    const sigs = (i) => i.signals.map(s => tw.pill(s.label, s.code === 'VARIANCE' ? 'warn' : 'bad')).join(' ');
    const what = (i) => `<b><i class="bi ${i.kind === 'product' ? 'bi-box-seam' : 'bi-basket'}"></i> ${esc(i.title)}</b><small>${esc(i.kind_label)} · ${esc(i.sub)}</small>`;
    const status = (i) => i.waiting ? tw.pill('do decyzji', 'bad') : (i.decision ? tw.pill(i.decision.label, DEC_CLS[i.decision.code] || '') : tw.pill('bez sygnału', 'ok'));
    const files = () => D.snapshot ? `<div style="display:flex;gap:6px 16px;flex-wrap:wrap">
            <a class="tw-link" style="padding:0" href="${at.root}${esc(D.files.pdf)}"><i class="bi bi-file-earmark-pdf"></i> PDF zestawienia</a>
            <a class="tw-link" style="padding:0" href="${at.root}${esc(D.files.csv)}"><i class="bi bi-filetype-csv"></i> CSV zestawienia</a></div>` : '';

    function step1() {
        const t = D.totals, p = t.products, m = t.materials;
        const snap = D.snapshot
            ? tw.alert(`Zestawienie zapisane <b>${esc(D.snapshot.at)}</b>${D.snapshot.by ? ' · ' + esc(D.snapshot.by) : ''} — PDF i CSV są zawsze z tego zapisu.${D.handed ? ' Przekazane do księgowości : nie można go już odświeżyć.' : ''}`,
                'ok', 'bi-camera', D.handed ? '' : `<button type="button" class="tw-btn ghost sm" data-sm-snap ${busy ? 'disabled' : ''}><i class="bi bi-arrow-clockwise"></i> Odśwież zestawienie</button>`)
            : tw.alert('ERP nie zapisuje stanów na dany dzień : poniżej stan <b>obliczony teraz</b> na koniec miesiąca. Zapisz go — zestawienie, PDF i CSV będą z tego zapisu (powtarzalne).',
                'warn', 'bi-exclamation-triangle', `<button type="button" class="tw-btn sm" data-sm-snap ${busy || !D.available ? 'disabled' : ''}><i class="bi bi-camera"></i> Zapisz zestawienie</button>`);
        const tiles = [
            {label: 'Produkty gotowe', value: D.sources.products ? zl(p.value) : 'brak danych', sub: D.sources.products ? `${p.rows} poz. · ${tw.nf(p.cartons)} kart. · początek ${zl(p.opening_value)}` : 'brak tabeli kartonów'},
            {label: 'Surowce', value: D.sources.materials ? zl(m.value) : 'brak danych', sub: D.sources.materials ? `${m.rows} poz. · początek ${zl(m.opening_value)}` : 'brak inwentaryzacji i przyjęć w bazie'},
            {label: 'Razem', value: zl(t.value), sub: `stan na ${D.end_label}`},
            {label: 'Pozycje z sygnałem', value: String(t.review), sub: `${t.by_code.NEGATIVE} ujemne · ${t.by_code.NO_PRICE + t.by_code.NO_COST} bez ceny · ${t.by_code.VARIANCE} dużych zmian`, cls: t.review ? 'warn' : 'ok'}];
        const head = [{label: 'Pozycja'}, {label: 'Początek'}, {label: 'Koniec'}, {label: 'Wartość', num: true}, {label: 'Sygnały'}];
        const row = (i) => ({cls: i.codes.length ? 'hl' : '', cells: [what(i), esc(i.opening_label), `<b>${esc(i.qty_label)}</b>`, zl(i.value),
            sigs(i) + i.signals.filter(x => x.detail).map(x => `<small>${esc(x.detail)}</small>`).join('')]});
        return `<p class="tw-lead">Miesiąc <b>${esc(D.month_label)}</b> : stany produktów gotowych i surowców na <b>${esc(D.end_label)}</b>, z wartością — zestawienie dla księgowości (Asia).</p>
            ${snap}
            ${tw.tiles(tiles)}
            ${D.inconsistent ? tw.alert(`${D.inconsistent} ${tw.plural(D.inconsistent, 'karton wysłany', 'kartony wysłane', 'kartonów wysłanych')} przed datą etykiety w tym miesiącu — stan produktów może się różnić od Ruchów magazynowych.`, '', 'bi-info-circle') : ''}
            <div class="tw-cap" style="display:flex;justify-content:space-between;gap:12px">Zestawienie (${D.items.length}) ${files()}</div>
            ${tw.table(head, D.items.map(row), 'Brak stanów na koniec miesiąca.')}
            <p class="tw-note">Produkty : kartony wg etykiety (wejście) i wysłanej palety (wyjście), na koniec miesiąca ; wartość wg kosztu surowców karty produktu w chwili zapisu (ERP nie przechowuje historii tego kosztu).
                Surowce : ostatnia inwentaryzacja + przyjęcia − zużycie wg receptur do końca miesiąca (reguła Zaopatrzenia ; bez inwentaryzacji tylko przyjęcia), cena z historii cen na koniec miesiąca, inaczej aktualna.
                Duża zmiana : co najmniej ${tw.nf(D.params.variance_pct)} % i ${tw.nf(D.params.variance_min)} zł wobec początku miesiąca (Ustawienia › Parametry).</p>`;
    }

    function noteBox(key, decision, a, ph) {
        const r = draft[key] ?? (draft[key] = {decision: a ? a.code : decision, note: a ? (a.note || '') : ''});
        const dirty = !a || a.code !== r.decision || (a.note || '') !== r.note;
        return {r, html: `<div style="display:flex;gap:6px"><input class="form-control form-control-sm" data-sm-note="${esc(key)}" maxlength="500" value="${esc(r.note)}" placeholder="${esc(ph)}" style="min-height:36px;border-radius:9px">
            <button type="button" class="tw-btn ghost sm" data-sm-save="${esc(key)}" ${!r.decision || !dirty || busy || r.note.trim().length < 3 ? 'disabled' : ''}>Zapisz</button></div>`};
    }

    function decisionCell(i) {
        const a = i.decision;
        const r = draft[i.key] ?? (draft[i.key] = {decision: a ? a.code : '', note: a ? (a.note || '') : ''});
        const seg = Object.keys(D.decisions).map(k => `<button type="button" class="${r.decision === k ? 'on' : ''}" data-sm-dec="${esc(i.key)}" data-v="${k}" ${D.handed ? 'disabled' : ''}>${esc(D.decisions[k])}</button>`).join('');
        const ph = r.decision === 'to_correct' ? 'Co poprawić (wymagane) : np. brak przyjęcia faktury 12/11' : 'Dlaczego stan jest poprawny (wymagane) : np. inwentaryzacja 30.11';
        const box = noteBox(i.key, '', a, ph).html;
        const saved = a ? `<small>${tw.pill(a.label, DEC_CLS[a.code] || '')} ${esc(a.at)}${a.by ? ' · ' + esc(a.by) : ''}</small>` : '';
        return `<div style="display:grid;gap:6px;min-width:380px"><div class="tw-seg" style="justify-self:start">${seg}</div>${D.handed ? '' : box}${links(i)}${i.reason ? `<small class="tw-red">${esc(i.reason)}</small>` : ''}${saved}</div>`;
    }

    function handCard() {
        const a = D.handed;
        const box = noteBox('statement', 'handed', a, 'Komu, jak i kiedy (wymagane) : np. Asia, e-mail z PDF i CSV 3.12');
        const body = a ? `${tw.pill(a.label, 'ok')} <small>${esc(a.at)}${a.by ? ' · ' + esc(a.by) : ''}${a.note ? ' — ' + esc(a.note) : ''}</small>`
            : (D.can_hand ? `<div style="display:grid;gap:6px;max-width:640px"><small>Przekaż PDF albo CSV księgowości (Asia) — ERP niczego nie wysyła — i zapisz, jak to zrobiono.</small>${box.html}</div>`
                : `<small>${D.snapshot ? 'Najpierw decyzja dla każdej pozycji z sygnałem.' : 'Najpierw zapisz zestawienie (krok 1).'}</small>`);
        return `<div class="tw-cap">Przekazanie zestawienia do księgowości</div>${tw.alert(`<div style="display:grid;gap:8px">${files()}${body}</div>`, a ? 'ok' : '', 'bi-send')}`;
    }

    function step2() {
        const list = D.review, t = D.totals;
        if (!D.snapshot) return tw.alert('Najpierw zapisz zestawienie (krok 1) — decyzje dotyczą zapisanego stanu.', 'warn', 'bi-camera');
        const rows = list.map(i => ({cls: i.waiting ? 'hl' : '', cells: [what(i),
            i.signals.map(s => `<div style="display:flex;gap:6px;align-items:baseline;margin:1px 0"><i class="bi bi-exclamation-circle ${s.code === 'VARIANCE' ? '' : 'tw-red'}"></i><span><b>${esc(s.label)}</b>${s.detail ? ' : ' + esc(s.detail) : ''}</span></div>`).join(''),
            `${esc(i.qty_label)}<small>wartość ${zl(i.value)}</small>`, decisionCell(i)]}));
        return `<p class="tw-lead">Dla każdej pozycji z sygnałem zapisz decyzję z notatką : <b>Sprawdzone — stan poprawny</b> (dlaczego) albo <b>Do korekty</b> (co poprawić — korekta w księgowości albo w ERP w następnym miesiącu).
                Potem zapisz <b>przekazanie</b> zestawienia do księgowości. Krok 3 otworzy się, gdy wszystko jest zapisane.</p>
            ${tw.tiles([{label: 'Z sygnałem', value: String(t.review), sub: `${t.by_code.NEGATIVE} ujemne · ${t.by_code.NO_COUNT} bez inwentaryzacji · ${t.by_code.EXPIRED} przeterminowane`},
                {label: 'Z decyzją', value: `${t.resolved} z ${t.review}`, sub: t.waiting ? `zostało ${t.waiting}` : 'wszystkie z decyzją', cls: t.waiting ? 'warn' : 'ok'},
                {label: 'Sprawdzone / do korekty', value: `${t.confirmed} / ${t.to_correct}`, sub: 'z notatką'},
                {label: 'Przekazane', value: D.handed ? 'tak' : 'nie', sub: D.handed ? D.handed.at : 'księgowość (Asia)', cls: D.handed ? 'ok' : 'warn'}])}
            ${list.length ? tw.table([{label: 'Pozycja'}, {label: 'Sygnały'}, {label: 'Stan'}, {label: 'Decyzja'}], rows) : tw.alert('Brak pozycji z sygnałem.', 'ok', 'bi-check-circle')}
            ${handCard()}
            <p class="tw-note">Asystent niczego nie zmienia w stanach ani w cenach i niczego nie wysyła.</p>`;
    }

    function step3() {
        const t = D.totals;
        if (D.done) return tw.done('Stany magazynowe zestawione · ' + (D.done.at || ''), D.done.summary + ' · zadanie zaliczone');
        return `<p class="tw-lead">Gotowe : <b>${esc(D.summary)}</b>.</p>
            ${tw.tiles([{label: 'Produkty gotowe', value: zl(t.products.value), sub: `${t.products.rows} poz.`}, {label: 'Surowce', value: zl(t.materials.value), sub: `${t.materials.rows} poz.`},
                {label: 'Decyzje', value: `${t.resolved} z ${t.review}`, sub: `${t.confirmed} sprawdzone · ${t.to_correct} do korekty`, cls: 'ok'},
                {label: 'Przekazane', value: D.handed ? D.handed.at : '—', sub: D.handed ? (D.handed.note || '') : '', cls: 'ok'}])}
            ${files()}
            <p class="tw-note">« Zakończ » zalicza zadanie « Stany magazynowe na koniec miesiąca ». Stany i ceny nie są zmieniane, nic nie jest wysyłane.</p>`;
    }

    function render() {
        const top = D.can_finish ? reached : Math.min(reached, D.snapshot ? 2 : 1);
        if (step > top) step = top;
        const body = step === 1 ? step1() : (step === 2 ? step2() : step3());
        const next = step === 1 ? {label: D.snapshot ? 'Dalej : decyzje i przekazanie' : 'Najpierw zapisz zestawienie', attr: 'data-sm-next', disabled: !D.snapshot}
            : step === 2 ? {label: 'Dalej : zatwierdź', attr: 'data-sm-next', disabled: !D.can_finish}
                : (D.done ? null : {label: 'Zakończ', attr: 'data-sm-finish', final: true, disabled: busy || !D.can_finish});
        app.innerHTML = tw.frame({title: 'Stany magazynowe na koniec miesiąca', sub: `${D.month_label} · krok ${step} z 3 · ok. 30 min`, steps: STEPS, step, reached: top, body,
            prev: step > 1 ? 'Wstecz' : null, next, later: !D.done && step < 3});
    }

    const refresh = (key) => {
        const r = draft[key], btn = app.querySelector(`[data-sm-save="${sel(key)}"]`);
        const a = key === 'statement' ? D.handed : (D.review.find(x => x.key === key) || {}).decision;
        if (!btn || !r) return;
        btn.disabled = !r.decision || busy || r.note.trim().length < 3 || (a && a.code === r.decision && (a.note || '') === r.note);
    };
    tw.bind(app, (n) => { step = n === -1 ? Math.max(1, step - 1) : n; render(); });
    app.addEventListener('input', (e) => {
        const n = e.target.closest('[data-sm-note]');
        if (!n) return;
        (draft[n.dataset.smNote] ??= {decision: n.dataset.smNote === 'statement' ? 'handed' : '', note: ''}).note = n.value;
        refresh(n.dataset.smNote);
    });
    app.addEventListener('click', async (e) => {
        if (e.target.closest('[data-sm-next]')) { step = Math.min(3, step + 1); reached = Math.max(reached, step); render(); return; }
        if (e.target.closest('[data-sm-snap]')) {
            if (busy) return;
            busy = true; render();
            const res = await tw.post('/ajax/stock/month-end/snapshot', {});
            busy = false;
            if (res.ok) { at.notify(true, res.message); D = res.data; }
            render();
            return;
        }
        const r = e.target.closest('[data-sm-dec]');
        if (r) { const key = r.dataset.smDec; (draft[key] ??= {decision: '', note: ''}).decision = r.dataset.v; render(); app.querySelector(`[data-sm-note="${sel(key)}"]`)?.focus(); return; }
        const s = e.target.closest('[data-sm-save]');
        if (s) {
            const key = s.dataset.smSave, d = draft[key];
            if (!d || !d.decision || busy) return;
            busy = true; s.disabled = true;
            const res = await tw.post('/ajax/stock/month-end/decision', {key, decision: d.decision, note: d.note});
            busy = false;
            if (res.ok) { D = res.data; delete draft[key]; }
            render();
            return;
        }
        if (e.target.closest('[data-sm-finish]')) {
            if (busy) return;
            busy = true; render();
            const res = await tw.post('/ajax/stock/month-end/finish', {});
            busy = false;
            if (res.ok) { at.notify(true, res.message); D = res.data; }
            render();
        }
    });
    render();
})();
