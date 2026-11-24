// [380] HACCP miesiąca (Jakość, Kierownik produkcji, Brygadzista, Kierowca — co miesiąc) : 1. zdarzenia HACCP, przeglądy SOP,
// odchylenia przy wykonaniu SOP (etapy HACCP) et temperatura w dostawach du mois écoulé (+ mois en cours), 2. une décision par
// élément signalé (wyjaśnione / działanie korygujące avec responsable / NC), 3. récapitulatif, tendance et validation → la
// tâche du mois se valide seule. Rien n'est changé dans les sources depuis cet écran : les liens ouvrent les écrans d'origine.
(() => {
    const app = document.getElementById('hmApp');
    if (!app || !window.tw) return;
    const {esc} = tw;
    let D = JSON.parse(document.getElementById('hmBoot').textContent);
    let step = 1, reached = 1, busy = false;
    // Brouillons de l'étape 2 (décision, note, responsable, poste) par élément, gardés entre deux rendus.
    const draft = {};
    const STEPS = ['Przegląd', 'Do wyjaśnienia', 'Zatwierdź'];
    const DEC_CLS = {explained: 'ok', corrective: 'warn', nc: 'bad'};
    const SIG_CLS = {haccp_untreated: 'bad', haccp_unfavorable: 'bad', haccp_below: 'bad', sop_deviation_haccp: 'bad', delivery_temp: 'warn', sop_deviation: 'warn', sop_review_late: 'warn', sop_review_missing: 'warn',
        hala_chill_warm: 'bad', hala_clean_missing: 'warn'};
    const SHORT = {haccp_untreated: 'nieobsłużone', haccp_unfavorable: 'negatywny', haccp_below: 'poniżej progu', sop_deviation_haccp: 'etap HACCP', delivery_temp: 'temperatura',
        sop_deviation: 'odchylenie', sop_review_late: 'po terminie', sop_review_missing: 'bez daty', hala_chill_warm: 'schładzanie', hala_clean_missing: 'sprzątanie'};
    const KIND_ICON = {haccp_event: 'bi-shield-exclamation', sop_review: 'bi-journal-check', sop_run: 'bi-list-check', delivery_temp: 'bi-thermometer-half',
        hala_chill: 'bi-thermometer-snow', hala_clean: 'bi-stars'};
    const PERIOD = {month: '', current: 'bieżący miesiąc', backlog: 'zaległe', state: ''};
    const canFinish = () => !!D.can_finish;
    const sel = (k) => CSS.escape(k);
    const link = (l) => l ? `<a class="tw-link" href="${at.root}${esc(l.href)}" target="_blank" rel="noopener" style="padding:0"><i class="bi bi-box-arrow-up-right"></i> ${esc(l.label)}</a>` : '';
    const ncLink = () => D.nc_link ? `<a class="tw-link" href="${at.root}${esc(D.nc_link)}" target="_blank" rel="noopener" style="padding:0"><i class="bi bi-clipboard-plus"></i> Rejestr NC — nowa</a>` : '';
    const sigs = (i) => i.signals.map(s => tw.pill(SHORT[s.code] || s.code, SIG_CLS[s.code] || '')).join(' ');
    const what = (i) => `<b><i class="bi ${KIND_ICON[i.kind] || 'bi-dot'}"></i> ${esc(i.title)}</b><small>${esc(i.kind_label)}${i.sub ? ' · ' + esc(i.sub) : ''}</small>`;
    const when = (i) => `${esc(i.date_label)}${PERIOD[i.period] ? `<small>${esc(PERIOD[i.period])}</small>` : ''}`;
    const eff = (i) => i.decision || i.earlier;
    const status = (i) => i.waiting ? tw.pill('do wyjaśnienia', 'bad') : tw.pill(eff(i).label + (i.decision ? '' : ' (' + eff(i).month_label + ')'), DEC_CLS[eff(i).code] || '');
    const srcVal = (k, n) => D.sources[k].available ? String(n) : 'brak danych';
    const srcSub = (k, txt) => D.sources[k].available ? txt : D.sources[k].note;

    function step1() {
        const t = D.totals, m = t.month_by_kind;
        const head = [{label: 'Data'}, {label: 'Element'}, {label: 'Sygnały'}, {label: 'Szczegóły'}, {label: ''}];
        const row = (i) => ({cls: i.waiting ? 'hl' : '', cells: [when(i), what(i), sigs(i),
            i.signals.map(s => `<small>${esc(s.detail)}</small>`).join('') + (i.text ? `<small style="color:#7a6a55">« ${esc(i.text)} »</small>` : '') + (i.link ? `<div>${link(i.link)}</div>` : ''), status(i)]});
        const tiles = [
            {label: 'Zdarzenia HACCP', value: srcVal('haccp', t.month_events), sub: srcSub('haccp', `w miesiącu · ${m.haccp_event} z sygnałem${t.backlog ? ` · ${t.backlog} zaległe` : ''}`), cls: D.sources.haccp.available ? (t.by_kind.haccp_event ? 'warn' : 'ok') : ''},
            {label: 'Przegląd SOP po terminie', value: srcVal('sop', m.sop_review), sub: srcSub('sop', `z ${t.sops_active} obowiązujących procedur`), cls: D.sources.sop.available ? (m.sop_review ? 'warn' : 'ok') : ''},
            {label: 'Odchylenia przy wykonaniu SOP', value: srcVal('sop_run', m.sop_run), sub: srcSub('sop_run', D.sources.sop_haccp.available ? `${t.month_runs_haccp} na etapie HACCP` : 'etapy HACCP : ' + D.sources.sop_haccp.note), cls: D.sources.sop_run.available ? (m.sop_run ? 'warn' : 'ok') : ''},
            {label: 'Temperatura w dostawie', value: srcVal('temp', m.delivery_temp), sub: srcSub('temp', 'zgłoszenia z tabletu kierowcy'), cls: D.sources.temp.available ? (m.delivery_temp ? 'warn' : 'ok') : ''},
            // [409] Tablet hali : schładzanie poza limitem, dni bez pełnego sprzątania.
            {label: 'Schładzanie (tablet hali)', value: srcVal('hala_chill', m.hala_chill), sub: srcSub('hala_chill', 'poza limitem temperatury albo czasu'), cls: D.sources.hala_chill.available ? (m.hala_chill ? 'warn' : 'ok') : ''},
            {label: 'Sprzątanie (tablet hali)', value: srcVal('hala_clean', m.hala_clean), sub: srcSub('hala_clean', 'dni produkcji bez wszystkich stref'), cls: D.sources.hala_clean.available ? (m.hala_clean ? 'warn' : 'ok') : ''},
            {label: 'Do wyjaśnienia', value: String(t.review), sub: t.review ? `${t.resolved} z ${t.review} z decyzją${t.current ? ` · ${t.current} z bieżącego miesiąca` : ''}` : 'brak sygnałów', cls: t.waiting ? 'warn' : 'ok'}];
        const info = D.info.length ? `<div class="tw-cap">Pozostałe zdarzenia HACCP (bez sygnału) : ${D.info.length}</div>`
            + tw.table([{label: 'Data'}, {label: 'Zdarzenie'}, {label: 'Opis'}], D.info.map(i => ({cells: [when(i), what(i), i.text ? esc(i.text) : '—']}))) : '';
        const missing = Object.values(D.sources).filter(s => !s.available);
        return `<p class="tw-lead">Miesiąc <b>${esc(D.month_label)}</b> i bieżący miesiąc do dziś : zdarzenia HACCP z modułu Kierownik produkcji, procedury SOP po terminie przeglądu, odchylenia przy wykonaniu SOP, problemy z temperaturą zgłoszone przez kierowców, schładzanie poza limitem i niepełne sprzątanie z tabletu hali. Każdy element z sygnałem trafia do kroku 2.</p>
            ${tw.tiles(tiles)}
            ${missing.length ? tw.alert(missing.map(s => `<div><b>${esc(s.from)}</b> : ${esc(s.note)}</div>`).join(''), '', 'bi-info-circle') : ''}
            <div class="tw-cap">Do wyjaśnienia (${D.review.length})</div>
            ${tw.table(head, D.review.map(row), 'Brak sygnałów HACCP w miesiącu.')}
            ${info}
            <p class="tw-note">Sygnały : przerwanie łańcucha chłodniczego nieobsłużone (także starsze, ${D.backlog_months} mies. wstecz — haccp_month.backlog_months), kontrola, audyt albo samokontrola z wynikiem negatywnym,
                audyt niezależny poniżej progu planu roku ; obowiązująca procedura SOP po dacie przeglądu ; wykonanie SOP z wartością poza zakresem (etap HACCP osobno) ; zgłoszenie « Temperatura » na tablecie kierowcy.</p>`;
    }

    function decisionCell(i) {
        const a = i.decision;
        const r = draft[i.key] ?? (draft[i.key] = {decision: a ? a.code : '', note: a ? (a.note || '') : '', id_responsible: a && a.id_responsible ? String(a.id_responsible) : '',
            id_position: a && a.id_position ? String(a.id_position) : ''});
        const seg = Object.entries(D.decisions).map(([k, l]) => `<button type="button" class="${r.decision === k ? 'on' : ''}" data-hm-dec="${esc(i.key)}" data-v="${k}">${esc(l)}</button>`).join('');
        const need = D.note_required.includes(r.decision);
        const ph = r.decision === 'corrective' ? 'Działanie (wymagane) : kto, co, do kiedy' : (r.decision === 'nc' ? 'Notatka : numer NC, przyczyna' : 'Notatka : co sprawdzono, dlaczego zamknięte');
        const who = r.decision === 'corrective' ? `<div style="display:flex;gap:6px">
                <select class="form-select form-select-sm" data-hm-sel="id_responsible" data-k="${esc(i.key)}"><option value="">Osoba odpowiedzialna (opcjonalnie)</option>${D.users.map(u => `<option value="${u.id}" ${String(u.id) === r.id_responsible ? 'selected' : ''}>${esc(u.name)}</option>`).join('')}</select>
                <select class="form-select form-select-sm" data-hm-sel="id_position" data-k="${esc(i.key)}"><option value="">Stanowisko (opcjonalnie)</option>${D.positions.map(p => `<option value="${p.id}" ${String(p.id) === r.id_position ? 'selected' : ''}>${esc(p.name)}</option>`).join('')}</select></div>` : '';
        const saved = a ? `<small>${tw.pill(a.label, DEC_CLS[a.code] || '')} ${esc(a.at)}${a.by ? ' · ' + esc(a.by) : ''}${a.responsible ? ' · odp. ' + esc(a.responsible) : ''}${a.position ? ' · ' + esc(a.position) : ''}</small>`
            : (i.earlier ? `<small>${tw.pill(i.earlier.label, DEC_CLS[i.earlier.code] || '')} w przeglądzie « ${esc(i.earlier.month_label)} »${i.earlier.note ? ' — ' + esc(i.earlier.note) : ''}</small>` : '');
        const dirty = !a || a.code !== r.decision || (a.note || '') !== r.note || String(a.id_responsible || '') !== r.id_responsible || String(a.id_position || '') !== r.id_position;
        return `<div style="display:grid;gap:6px;min-width:440px"><div class="tw-seg" style="justify-self:start">${seg}</div>${who}
            <div style="display:flex;gap:6px"><input class="form-control form-control-sm" data-hm-note="${esc(i.key)}" maxlength="500" value="${esc(r.note)}" placeholder="${esc(ph)}" style="min-height:36px;border-radius:9px">
            <button type="button" class="tw-btn ghost sm" data-hm-save="${esc(i.key)}" ${!r.decision || !dirty || busy || (need && r.note.trim().length < 3) ? 'disabled' : ''}>Zapisz</button></div>
            <div style="display:flex;gap:4px 14px;flex-wrap:wrap">${link(i.link)}${r.decision === 'nc' || (a && a.code === 'nc') ? ncLink() : ''}</div>${saved}</div>`;
    }

    function step2() {
        const list = D.review, t = D.totals;
        if (!list.length) return tw.alert('Nic do wyjaśnienia : brak nieobsłużonych zdarzeń HACCP, przeglądy SOP w terminie, brak odchyleń i problemów z temperaturą.', 'ok', 'bi-check-circle');
        const rows = list.map(i => ({cls: i.waiting ? 'hl' : '', cells: [when(i), what(i) + (i.text ? `<small style="color:#7a6a55">« ${esc(i.text)} »</small>` : ''),
            i.signals.map(s => `<div style="display:flex;gap:6px;align-items:baseline;margin:1px 0"><i class="bi bi-exclamation-circle ${SIG_CLS[s.code] === 'bad' ? 'tw-red' : ''}"></i><span><b>${esc(s.label)}</b> : ${esc(s.detail)}</span></div>`).join(''),
            decisionCell(i)]}));
        return `<p class="tw-lead">Dla każdego elementu zapisz decyzję : <b>Wyjaśnione / zamknięte</b> (z notatką), <b>Działanie korygujące</b> (notatka wymagana, osoba lub stanowisko — opcjonalnie)
                albo <b>Niezgodność (NC)</b> — NC zakłada się w rejestrze NC. Krok 3 otworzy się, gdy wszystkie mają decyzję.</p>
            ${tw.tiles([{label: 'Do wyjaśnienia', value: String(t.review), sub: `${t.review} ${tw.plural(t.review, 'element', 'elementy', 'elementów')} z sygnałem`},
                {label: 'Z decyzją', value: `${t.resolved} z ${t.review}`, sub: t.waiting ? `zostało ${t.waiting}` : 'wszystkie wyjaśnione', cls: t.waiting ? 'warn' : 'ok'},
                {label: 'Wyjaśnione / działania', value: `${t.explained} / ${t.corrective}`, sub: t.earlier ? `${t.earlier} z poprzedniego przeglądu` : 'w tym przeglądzie'}, {label: 'NC', value: String(t.nc), sub: 'w rejestrze NC'}])}
            ${tw.table([{label: 'Data'}, {label: 'Element'}, {label: 'Problem'}, {label: 'Decyzja'}], rows)}
            <p class="tw-note">Asystent niczego nie zmienia w źródłach : nie oznacza zdarzeń HACCP jako obsłużone, nie zmienia procedur, nie zakłada NC. Zdarzenie, wykonanie SOP albo dostawa wyjaśnione raz nie wracają ;
                procedura po terminie przeglądu wraca co miesiąc, dopóki nie zostanie przejrzana i zatwierdzona w « Procedury SOP ».</p>`;
    }

    function trendTable() {
        const tr = D.trend;
        const head = [{label: 'Źródło'}].concat(tr.months.map(m => ({label: m.label + (m.current ? ' (do dziś)' : ''), num: true})), [{label: 'Trend', num: true}]);
        const rows = tr.rows.map(r => ({cells: [esc(r.label)].concat(r.values.map((v, k) => v === null ? '<span style="color:#999">brak danych</span>' : (tr.months[k].reviewed ? `<b>${v}</b>` : String(v))),
            [r.dir === null ? '—' : `${r.dir === 'up' ? '<span class="tw-red">▲</span>' : (r.dir === 'down' ? '<span class="tw-green">▼</span>' : '=')} <small>śr. ${String(r.avg_prev).replace('.', ',')}</small>`])}));
        return tw.table(head, rows);
    }

    function step3() {
        const t = D.totals;
        if (D.done) return tw.done('HACCP miesiąca przejrzany · ' + (D.done.at || ''), D.done.summary + ' · zadanie zaliczone');
        const decided = D.review.filter(i => eff(i));
        const SRC = {haccp_event: 'haccp', sop_review: 'sop', sop_run: 'sop_run', delivery_temp: 'temp', hala_chill: 'hala_chill', hala_clean: 'hala_clean'};
        const by = Object.entries(D.kind_labels).map(([k, l]) => ({label: l, value: srcVal(SRC[k], t.by_kind[k]),
            sub: srcSub(SRC[k], k === 'sop_review' ? 'stan na dziś' : `${t.month_by_kind[k]} w miesiącu · ${t.by_kind[k] - t.month_by_kind[k]} poza nim`)}));
        return `<p class="tw-lead">Gotowe : <b>${esc(D.summary)}</b>.</p>
            ${tw.tiles(by.concat([{label: 'Decyzje', value: `${t.resolved} z ${t.review}`, sub: `${t.explained} wyjaśnione · ${t.corrective} działania · ${t.nc} NC`, cls: 'ok'}]))}
            <div class="tw-cap">Trend : ${esc(D.month_label)} wobec ${D.trend_months} poprzednich miesięcy</div>
            ${trendTable()}
            ${decided.length ? `<div class="tw-cap">Decyzje</div>` + tw.table([{label: 'Data'}, {label: 'Element'}, {label: 'Sygnały'}, {label: 'Decyzja'}, {label: 'Notatka'}],
                decided.map(i => ({cells: [when(i), what(i), sigs(i), status(i) + (eff(i).responsible || eff(i).position ? `<small>odp. ${esc([eff(i).responsible, eff(i).position].filter(Boolean).join(' · '))}</small>` : ''), esc(eff(i).note || '—')]}))) : ''}
            <p class="tw-note">« Zakończ » zalicza zadanie « HACCP miesiąca » na ten miesiąc. Zdarzenia HACCP, procedury i dostawy nie są zmieniane, NC nie są zakładane.</p>`;
    }

    function render() {
        const top = canFinish() ? reached : Math.min(reached, 2);
        if (step > top) step = top;
        const body = step === 1 ? step1() : (step === 2 ? step2() : step3());
        const next = step === 1 ? {label: 'Dalej : do wyjaśnienia', attr: 'data-hm-next'}
            : step === 2 ? {label: 'Dalej : zatwierdź', attr: 'data-hm-next', disabled: !canFinish()}
                : (D.done ? null : {label: 'Zakończ', attr: 'data-hm-finish', final: true, disabled: busy || !canFinish()});
        app.innerHTML = tw.frame({title: 'HACCP miesiąca', sub: `${D.month_label} · krok ${step} z 3 · ok. 20 min`, steps: STEPS, step, reached: top, body,
            prev: step > 1 ? 'Wstecz' : null, next, later: !D.done && step < 3});
    }

    const refresh = (key) => {
        const i = D.review.find(x => x.key === key), r = draft[key], a = i && i.decision, btn = app.querySelector(`[data-hm-save="${sel(key)}"]`);
        if (!btn || !i) return;
        const need = D.note_required.includes(r.decision);
        btn.disabled = !r.decision || busy || (need && r.note.trim().length < 3)
            || (a && a.code === r.decision && (a.note || '') === r.note && String(a.id_responsible || '') === r.id_responsible && String(a.id_position || '') === r.id_position);
    };
    tw.bind(app, (n) => { step = n === -1 ? Math.max(1, step - 1) : n; render(); });
    app.addEventListener('input', (e) => {
        const n = e.target.closest('[data-hm-note]');
        if (!n) return;
        (draft[n.dataset.hmNote] ??= {decision: '', note: '', id_responsible: '', id_position: ''}).note = n.value;
        refresh(n.dataset.hmNote);
    });
    app.addEventListener('change', (e) => {
        const s = e.target.closest('[data-hm-sel]');
        if (!s) return;
        draft[s.dataset.k][s.dataset.hmSel] = s.value;
        refresh(s.dataset.k);
    });
    app.addEventListener('click', async (e) => {
        if (e.target.closest('[data-hm-next]')) { step = Math.min(3, step + 1); reached = Math.max(reached, step); render(); return; }
        const r = e.target.closest('[data-hm-dec]');
        if (r) { const key = r.dataset.hmDec; (draft[key] ??= {decision: '', note: '', id_responsible: '', id_position: ''}).decision = r.dataset.v; render(); app.querySelector(`[data-hm-note="${sel(key)}"]`)?.focus(); return; }
        const s = e.target.closest('[data-hm-save]');
        if (s) {
            const key = s.dataset.hmSave, d = draft[key];
            if (!d || !d.decision || busy) return;
            busy = true; s.disabled = true;
            const body = {key, decision: d.decision, note: d.note};
            if (d.decision === 'corrective') { body.id_responsible = d.id_responsible ? Number(d.id_responsible) : null; body.id_position = d.id_position ? Number(d.id_position) : null; }
            const res = await tw.post('/ajax/production/haccp-month/decision', body);
            busy = false;
            if (res.ok) { D = res.data; delete draft[key]; }
            render();
            return;
        }
        if (e.target.closest('[data-hm-finish]')) {
            if (busy) return;
            busy = true; render();
            const res = await tw.post('/ajax/production/haccp-month/finish', {});
            busy = false;
            if (res.ok) { at.notify(true, res.message); D = res.data; }
            render();
        }
    });
    render();
})();
