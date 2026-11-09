// Moce produkcyjne [234] (makieta 10 — wykorzystanie i co jeszcze zmieszczę, makieta 11 — ilość na zmianę i ustawienia maszyny).
// Zakładki : Wykorzystanie (maszyny wg rodzaju, zespoły, mroźnia, dni, panel symulacji), Co jeszcze zmieszczę (symulacja +
// ranking wartości), Ilość na zmianę (produkty : wąskie gardło), Ustawienia maszyn (wsad, cykl, TRS, zmiany, widoczność, mroźnia).
// [240] Dane maszyn (makiety 12–13) : co trzeba uzupełnić + kreator maszyny (produkty → wsad, wsadów / h, osób → gotowe) ;
// także dla kierownika produkcji (stan obecny + zakładka « Dane maszyn »).
(() => {
    const app = document.getElementById('mcApp');
    if (!app) return;
    const esc = at.esc;
    let D = JSON.parse(document.getElementById('mcBoot').textContent);
    const TABS = [['use', 'bi-speedometer2', 'Wykorzystanie'], ['more', 'bi-plus-square', 'Co jeszcze zmieszczę'], ['shift', 'bi-clock-history', 'Ilość na zmianę'], ['settings', 'bi-sliders', 'Ustawienia maszyn']];
    const ICON = {froid: 'bi-thermometer-snow', surgelation: 'bi-snow', four: 'bi-fire', machine: 'bi-gear', fermentation: 'bi-hourglass-split', robot: 'bi-robot', manuel: 'bi-person-workspace'};
    const UNITS = {piece: 'szt.', plaque: 'blach', kg: 'kg', chariot: 'wózków', bac: 'pojemników', litre: 'l'};
    // Produkcja (bez prawa « pełna wersja ») : tylko stan obecny — tydzień bieżący, do odczytu, bez zakładek i symulacji.
    const RO = () => !!D.readonly;
    // [240] Dane maszyn : zakładka « Ustawienia maszyn » (pełna wersja) albo « Dane maszyn » (kierownik produkcji — karty produktów).
    const tabList = () => RO() ? (D.machines_edit ? [['use', 'bi-speedometer2', 'Stan obecny'], ['settings', 'bi-ui-checks-grid', 'Dane maszyn']] : []) : TABS;
    let tab = (location.hash.match(/tab=(\w+)/) || [])[1] || 'use';
    if (!tabList().some(([k]) => k === tab)) tab = 'use';
    let MD = null; // przegląd danych maszyn (ładowany przy otwarciu zakładki)
    let mdLoading = false;
    let filters = {date: D.week ? D.week.start : null, group: D.group ?? '', shifts: []};
    if (D.what_if && D.what_if.afternoon) filters.shifts.push('afternoon');
    let sim = {item: null, qty: '', res: null, busy: false};
    let modal = null;
    const nf = (v, d = 0) => v === null || v === undefined ? '—' : Number(v).toLocaleString('pl-PL', {maximumFractionDigits: d, minimumFractionDigits: 0});
    const cls = (p, target) => p === null || p === undefined ? '' : (p >= 100 ? 'bad' : (p >= (target || 85) ? 'warn' : ''));
    const mins = (s) => { if (!s) return '—'; const m = Math.round(s / 60); return m < 60 ? `${m} min` : `${Math.floor(m / 60)} h${m % 60 ? ' ' + String(m % 60).padStart(2, '0') : ''}`; };

    function bar(p) {
        if (p === null || p === undefined) return '<span class="at-sub">brak zmiany</span>';
        const c = cls(p, D.target);
        return `<div class="mc-u"><span class="tr"><i class="${c}" style="width:${Math.min(100, p)}%"></i><em style="left:${D.target}%"></em></span><b class="${c}">${p >= 999 ? '∞' : nf(p, 0)} %</b></div>`;
    }
    function days(list) {
        return `<div class="mc-days">${list.map(x => {
            const p = x.pct;
            if (p === null) return `<span class="g0${x.date === D.today ? ' today' : ''}" title="brak zmiany">—</span>`;
            const g = p >= 100 ? 'g4' : (p >= D.target ? 'g3' : (p >= 50 ? 'g2' : (p > 0 ? 'g1' : 'g0')));
            const today = x.date === D.today;
            return `<span class="${g}${today ? ' today' : ''}" title="${today ? 'dziś · ' : ''}${p} %">${p >= 999 ? '!' : p}</span>`;
        }).join('')}</div>`;
    }

    // --- Filtry, kafelki ----------------------------------------------------------------------------------------------
    function bar0() {
        const w = D.week;
        if (RO()) {
            return `<div class="mc-bar"><b class="mc-now"><i class="bi bi-broadcast"></i> Stan obecny</b><span class="at-sub">${esc(w.label)}</span>
                <label class="tt-sel ${filters.group !== '' && filters.group !== null ? 'on' : ''}"><i class="bi bi-people"></i><span>Grupa</span>
                    <select name="group" aria-label="Grupa"><option value="">wszystkie</option>${D.groups.map(g => `<option value="${g.id}" ${String(g.id) === String(filters.group) ? 'selected' : ''}>${esc(g.name)}</option>`).join('')}</select></label>
                <span class="at-sub mc-src"><i class="bi bi-calendar-week"></i> plan tygodnia · ${w.planned_items} produktów · zmiany wg zespołów</span></div>`;
        }
        const on = (k) => filters.shifts.includes(k);
        return `<div class="mc-bar">
            <span class="tt-nav"><button type="button" class="kw-btn" data-week="${esc(w.prev)}" aria-label="Poprzedni tydzień"><i class="bi bi-chevron-left"></i></button>
                <span class="tt-period" title="${esc(w.label)}">tydz. ${w.iso}</span>
                <button type="button" class="kw-btn" data-week="${esc(w.next)}" aria-label="Następny tydzień"><i class="bi bi-chevron-right"></i></button></span>
            <span class="at-sub">${esc(w.label)}</span>
            <label class="tt-sel ${filters.group !== '' && filters.group !== null ? 'on' : ''}"><i class="bi bi-people"></i><span>Grupa</span>
                <select name="group" aria-label="Grupa"><option value="">wszystkie</option>${D.groups.map(g => `<option value="${g.id}" ${String(g.id) === String(filters.group) ? 'selected' : ''}>${esc(g.name)}</option>`).join('')}</select></label>
            <span class="wp-seg" role="group" aria-label="Zmiany">
                <button type="button" class="${filters.shifts.length ? '' : 'on'}" data-shifts="" aria-pressed="${!filters.shifts.length}">Zmiany wg zespołów</button>
                <button type="button" class="${on('afternoon') ? 'on' : ''}" data-shift-toggle="afternoon" aria-pressed="${on('afternoon')}">+ popołudniowa</button></span>
            <span class="at-sub mc-src"><i class="bi bi-calendar-week"></i> plan tygodnia · ${D.week.planned_items} produktów</span>
        </div>`;
    }

    function tiles() {
        const t = D.tiles;
        const f = t.freezer;
        const b = t.bottleneck;
        return `<div class="mc-tiles">
            <div class="mc-tile ${b && b.pct >= 100 ? 'bad' : (b && b.pct >= D.target ? 'warn' : '')}"><span>Wąskie gardło</span><b>${b ? `${nf(b.pct)} % · ${esc(b.name)}` : '—'}</b>
                <small>${b ? [b.peak_day ? `szczyt ${esc(b.peak_day.label)} ${b.peak_day.pct >= 999 ? '∞' : b.peak_day.pct} %` : '', b.top ? esc(b.top) : ''].filter(Boolean).join(' · ') : 'brak obciążenia w planie'}</small></div>
            <div class="mc-tile ${f.pct !== null && f.pct >= f.alert_pct ? 'bad' : (f.pct !== null && f.pct >= 75 ? 'warn' : '')}"><span>Mroźnia zapasu</span>
                <b>${f.places ? `${nf(f.now, 1)} / ${f.places} palet` : `${nf(f.now, 1)} palet`}</b>
                <small>${f.places ? `${nf(f.pct)} % · wolne ${nf(f.free, 1)} · + ${nf(f.mts_week, 1)} z produkcji na zapas` : (RO() ? 'miejsca paletowe nieustawione' : `<button type="button" class="tt-link mc-inline" data-freezer>ustaw liczbę miejsc paletowych</button>`)}</small></div>
            <div class="mc-tile"><span>Komora szokowa</span><b>${t.blast ? nf(t.blast.pct) + ' %' : '—'}</b><small>${t.blast ? `wolne ${esc(t.blast.free || '—')}` : 'brak komory w maszynach'}</small></div>
            <div class="mc-tile"><span>Średnio maszyny</span><b>${t.average.pct !== null ? nf(t.average.pct) + ' %' : '—'}</b><small>cel ${D.target} % · ${t.average.below} z ${t.average.count} poniżej</small></div>
            ${RO() ? '' : `<div class="mc-tile ok"><span>Najwięcej miejsca</span><b>${t.best ? '≈ ' + nf(t.best.value) + ' €' : '—'}</b>
                <small>${t.best ? `+ ${nf(t.best.more)} ${esc(t.best.unit)} ${esc(t.best.name)} · limit : ${esc(t.best.limit)}` : 'brak cen standardowych'}</small></div>`}
        </div>`;
    }

    function warn() {
        const n = D.unrouted.length;
        if (!n) return '';
        return `<div class="mc-warn"><i class="bi bi-exclamation-triangle"></i><span><b>${n} z ${D.week.planned_items} produktów planu</b> nie ma etapów w karcie produkcyjnej — ich czas na maszynach nie jest liczony
            (np. ${D.unrouted.slice(0, 3).map(u => esc(u.name)).join(', ')}). <a href="${esc(at.root)}/workshop/products">Uzupełnij etapy i czasy</a>.</span></div>`;
    }

    // --- Wykorzystanie --------------------------------------------------------------------------------------------------
    function machineRow(m) {
        const cap = m.mode === 'delay' ? `${nf(m.capacity_per_pass)} ${esc(m.unit)}<small>miejsca (oczekiwanie)</small>`
            : `${nf(m.capacity_per_pass, 1)} ${esc(m.unit)}<small>${m.cycle_s ? 'cykl ' + mins(m.cycle_s) : (m.no_steps ? 'brak etapów w kartach' : '')}</small>`;
        const per = m.passes_shift ? `${m.passes_shift} wsadów<small>× ${m.shifts_week} zm. w tyg.</small>` : `${m.shifts_week} zm.<small>${esc(m.shift_names.join(', ') || 'brak zmiany')}</small>`;
        const nm = `<i class="bi ${ICON[m.type] || 'bi-gear'}"></i><span><b>${esc(m.name)}</b><small>${esc(m.group || '')}${m.shared && m.shared.length ? ` + ${m.shared.map(esc).join(', ')} <i class="bi bi-share" title="maszyna wspólna"></i>` : ''}${m.efficiency_pct < 100 ? ` · TRS ${nf(m.efficiency_pct)} %` : ''}</small></span>`;
        return `<tr class="${m.pct >= D.target ? 'hot' : ''}" data-machine="${m.id}"><td>${RO() ? `<span class="mc-name">${nm}</span>` : `<button type="button" class="mc-name" data-edit="${m.id}" title="Ustawienia maszyny">${nm}</button>`}</td>
            <td>${cap}</td><td>${per}</td>
            <td class="r">${nf(m.avail_h, 1)} h</td><td class="r"${m.top ? ` title="Najwięcej : ${esc(m.top.name)}"` : ''}>${nf(m.load_h, 1)} h${m.passes ? `<small>${nf(m.passes)} wsadów</small>` : ''}</td>
            <td>${bar(m.pct)}</td><td>${days(m.days)}</td>
            <td class="r mc-free ${m.pct >= 100 ? 'none' : ''}"><b>${nf(m.free_h, 1)} h</b><small>${esc(m.free_label || '')}</small></td></tr>`;
    }

    function freezerRow() {
        const f = D.freezer;
        const p = f.places ? f.pct : null;
        const nm = '<i class="bi bi-snow2"></i><span><b>Mroźnia zapasu</b><small>zapas produktów gotowych (palety)</small></span>';
        return `<tr class="${p !== null && p >= f.alert_pct ? 'hot' : ''}"><td>${RO() ? `<span class="mc-name">${nm}</span>` : `<button type="button" class="mc-name" data-freezer title="Ustawienia mroźni">${nm}</button>`}</td>
            <td>${f.places ? `${f.places} miejsc<small>paletowych</small>` : '<span class="at-sub">nie ustawiono</span>'}</td><td>—</td>
            <td class="r">${f.places ? f.places + ' palet' : '—'}</td><td class="r">${nf(f.now, 1)} palet<small>+ ${nf(f.mts_week, 1)} produkcja na zapas</small></td>
            <td>${p !== null ? bar(p) : `<span class="at-sub">${RO() ? 'miejsca nieustawione' : 'ustaw miejsca'}</span>`}</td><td><small class="at-sub">${f.pct_max !== null ? `po produkcji ≤ ${nf(f.pct_max)} %` : ''}</small></td>
            <td class="r mc-free ${p !== null && p >= 100 ? 'none' : ''}"><b>${f.free !== null ? nf(f.free, 1) + ' palet' : '—'}</b></td></tr>`;
    }

    function teamRows() {
        if (!D.teams.length) return '';
        return `<tr class="grp"><td colspan="8">Zespoły (ludzie)<small>obsada grupy × zmiany × dni — roboczogodziny etapów</small></td></tr>` + D.teams.map(t => `<tr class="${t.pct >= D.target ? 'hot' : ''}">
            <td><span class="mc-name"><i class="bi bi-people"></i><span><b>${esc(t.name)}</b><small>${t.headcount} os. na zmianę</small></span></span></td><td>${t.headcount} os.</td><td>—</td>
            <td class="r">${nf(t.avail_h, 1)} h</td><td class="r">${nf(t.load_h, 1)} h</td><td>${bar(t.pct)}</td><td>${days(t.days)}</td>
            <td class="r mc-free ${t.pct >= 100 ? 'none' : ''}"><b>${nf(t.free_h, 1)} h</b></td></tr>`).join('');
    }

    function useTable() {
        const groupSel = filters.group !== '' && filters.group !== null;
        const sections = D.sections.map(s => `<tr class="grp"><td colspan="8">${esc(s.name)}</td></tr>`
            + (s.name === 'Chłodzenie i mrożenie' && !groupSel ? freezerRow() : '') + s.rows.map(machineRow).join('')).join('');
        const hasCold = D.sections.some(s => s.name === 'Chłodzenie i mrożenie');
        return `<div class="mc-card"><div class="table-responsive"><table class="mc-tbl"><thead><tr><th>Maszyna</th><th>Wsad</th><th>Na zmianę</th><th class="r">Dostępne</th><th class="r">Zaplanowane</th>
            <th>Wykorzystanie <small class="mc-th">(| cel ${D.target} %)</small></th><th><div class="mc-days hd">${D.week.days.map(d => `<span class="${d.date === D.today ? 'today' : ''}">${esc(d.short.replace('.', ''))}</span>`).join('')}</div></th><th class="r">Wolne</th></tr></thead>
            <tbody>${!hasCold && !groupSel ? `<tr class="grp"><td colspan="8">Chłodzenie i mrożenie</td></tr>${freezerRow()}` : ''}${sections}${teamRows()}</tbody></table></div>
            <p class="mc-note">Dostępne = zmiany, na których pracuje maszyna (zespół grupy, zmiany « co jeśli », zmiany włączone na maszynie) × dni produkcji × TRS. Zaplanowane = etapy kart produkcyjnych × ilości planu tygodnia (dni z Planu zespołów ; obramowany = dziś).
            ${RO() ? 'Ustawienia maszyn i symulacje : administrator albo dyrektor zakładu.' : 'Kliknij maszynę, aby zmienić jej ustawienia.'}</p></div>`;
    }

    // --- Symulacja ------------------------------------------------------------------------------------------------------
    function productOptions(selected) {
        return D.products.map(p => `<option value="${p.item_id}" ${p.item_id === selected ? 'selected' : ''}>${esc(p.name)}</option>`).join('');
    }

    function simPanel(big) {
        const r = sim.res;
        const res = r ? `<div class="mc-chain">${r.steps.map(s => `<div><span>${esc(s.workstation)}${s.type === 'delay' ? ' <small class="at-sub">(oczekiwanie)</small>' : ''}</span>
                <span class="u ${s.ok ? 'ok' : 'bad'}">${s.before === null ? '—' : nf(s.before)} → ${s.after === null ? '—' : nf(s.after)} %${s.ok ? '' : ' ✗'}</span></div>`).join('')}</div>
            <div class="mc-res ${r.fits ? '' : 'warn'}"><small>${r.fits ? 'Zmieści się w tym tygodniu' : 'Maksymalnie jeszcze w tym tygodniu'}</small><b>+ ${nf(r.fits ? r.qty : r.more)} ${esc(r.unit)}</b>
                <small>limit : ${esc(r.limit)}${r.extra_shift ? ` · z dodatkową zmianą na wąskim gardle : + ${nf(r.extra_shift)} ${esc(r.unit)}` : ''}${r.value_per_unit ? ` · ≈ ${nf((r.fits ? r.qty : r.more) * r.value_per_unit)} €` : ''}</small></div>
            <a class="kw-btn" href="${esc(at.root)}/workshop/sheet?date=${esc(D.week.start)}"><i class="bi bi-calendar-plus"></i> Dodaj w arkuszu tygodnia</a>` : `<p class="mc-note">Wybierz produkt i ilość — ERP przejdzie przez wszystkie etapy karty produkcyjnej (maszyny, oczekiwanie, ludzie).</p>`;
        return `<div class="mc-panel"><h5><i class="bi bi-plus-square"></i> Co jeszcze zmieszczę ?</h5>
            <div class="mc-in${big ? ' big' : ''}"><select class="form-select" data-sim-item aria-label="Produkt"><option value="">— produkt —</option>${productOptions(sim.item)}</select>
                <input class="form-control" data-sim-qty inputmode="decimal" placeholder="ilość" value="${esc(sim.qty)}" aria-label="Ilość"><button type="button" class="kw-btn pri" data-sim ${sim.busy ? 'disabled' : ''}>Policz</button></div>
            <div class="mc-note">${esc(D.week.label)} · etapy karty produkcyjnej</div>${res}</div>`;
    }

    function ranking(limit) {
        const list = D.products.filter(p => p.more > 0).sort((a, b) => (b.value ?? -1) - (a.value ?? -1) || b.more - a.more).slice(0, limit);
        return `<div class="mc-panel"><h5><i class="bi bi-stars"></i> Najwięcej miejsca na</h5>${list.length ? `<div class="mc-chain">${list.map(p => `<div><button type="button" class="mc-pick" data-pick="${p.item_id}">${esc(p.name)}
            <small class="at-sub">limit : ${esc(p.more_limit)}${p.value !== null ? ` · ≈ ${nf(p.value)} €` : ''}</small></button><span class="u ok">+ ${nf(p.more)} ${esc(p.unit)}</span></div>`).join('')}</div>` : '<p class="mc-note">Brak wolnych mocy w planie albo brak etapów w kartach.</p>'}
            <div class="mc-note">Sortowane wg wartości (cena standardowa ÷ sztuk w kartonie) tego, co jeszcze się zmieści.</div></div>`;
    }

    function moreTab() {
        const rows = D.products.slice().sort((a, b) => (b.value ?? -1) - (a.value ?? -1) || b.more - a.more);
        return `<div class="mc-wrap"><div class="mc-card"><div class="table-responsive"><table class="mc-tbl"><thead><tr><th>Produkt</th><th>Limit</th><th class="r">Zaplanowane</th><th class="r">Jeszcze w tygodniu</th><th class="r">Wartość</th><th></th></tr></thead><tbody>
            ${rows.map(p => `<tr><td><b>${esc(p.name)}</b><small>${p.item_type === 'semi' ? 'półprodukt' : ''}</small></td><td><span class="mc-chip">${esc(p.more_limit)}</span>${p.people_limited ? '<small>ludzie</small>' : ''}</td>
                <td class="r">${nf(p.planned)} ${esc(p.unit)}</td><td class="r mc-free ${p.more <= 0 ? 'none' : ''}"><b>+ ${nf(p.more)} ${esc(p.unit)}</b>${p.more_cartons ? `<small>${nf(p.more_cartons, 1)} kart.</small>` : ''}</td>
                <td class="r">${p.value !== null ? nf(p.value) + ' €' : '—'}</td><td class="r"><button type="button" class="kw-btn kw-sm" data-pick="${p.item_id}">Policz</button></td></tr>`).join('')}</tbody></table></div></div>
            <div class="mc-side">${simPanel(true)}</div></div>`;
    }

    function shiftTab() {
        return `<div class="mc-card"><div class="table-responsive"><table class="mc-tbl"><thead><tr><th>Produkt</th><th>Wąskie gardło</th><th class="r">Na zmianę</th><th class="r">Zmiany / tydz.</th><th class="r">Zaplanowane (tydz.)</th><th>Wąskie gardło zajęte</th><th class="r">Jeszcze w tygodniu</th></tr></thead><tbody>
            ${D.products.map(p => `<tr class="${p.limit_pct >= D.target ? 'hot' : ''}"><td><b>${esc(p.name)}</b><small>${p.item_type === 'semi' ? 'półprodukt' : ''}</small></td>
                <td><button type="button" class="mc-chip" data-edit="${p.limit_id}">${esc(p.limit_name)}</button><small>${esc(p.limit_cycle)}</small></td>
                <td class="r"><b>${nf(p.per_shift)} ${esc(p.unit)}</b>${p.per_shift_cartons ? `<small>${nf(p.per_shift_cartons, 1)} kart.</small>` : ''}</td>
                <td class="r">${p.limit_shifts} zm.</td><td class="r">${nf(p.planned)}</td><td>${bar(p.limit_pct)}</td>
                <td class="r mc-free ${p.more <= 0 ? 'none' : ''}"><b>+ ${nf(p.more)}</b><small>${p.people_limited ? 'limit : ' + esc(p.more_limit) : ''}</small></td></tr>`).join('') || '<tr><td colspan="7" class="at-empty">Brak produktów z etapami w kartach.</td></tr>'}</tbody></table></div>
            <p class="mc-note">Ilość na zmianę = najwolniejszy etap produktu (czas stały ÷ wsad + czas zmienny, × TRS maszyny ; oczekiwanie w komorze : miejsca × czas). Kliknij maszynę, aby zmienić jej ustawienia.</p></div>`;
    }

    // --- Dane maszyn [240] (makieta 12) : co trzeba uzupełnić, maszyny wg grup, kreator maszyny -----------------------
    const pl = (n, a, b, c) => n === 1 ? a : (n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 12 || n % 100 > 14) ? b : c);
    function mdTiles() {
        const t = MD.tiles;
        const pct = (a, b) => b ? Math.round(a / b * 100) : 0;
        const names = t.plan_without_names.map(esc).join(', ');
        return `<div class="mw-prog">
            <div class="mc-tile ${t.machines_with < t.machines ? 'warn' : 'ok'}"><span>Maszyny z produktami</span><b>${t.machines_with} z ${t.machines}</b><div class="mw-bar"><i style="width:${pct(t.machines_with, t.machines)}%"></i></div>
                <small>${t.machines - t.machines_with ? `${t.machines - t.machines_with} ${pl(t.machines - t.machines_with, 'maszyna', 'maszyny', 'maszyn')} bez żadnego produktu` : 'wszystkie maszyny mają produkty'}</small></div>
            <div class="mc-tile ${pct(t.products_with, t.products) < 50 ? 'bad' : 'warn'}"><span>Produkty z danymi maszyn</span><b>${t.products_with} z ${t.products}</b><div class="mw-bar"><i class="bad" style="width:${pct(t.products_with, t.products)}%"></i></div>
                <small>bez nich ERP nie liczy obciążenia</small></div>
            <div class="mc-tile ${t.plan_without ? 'warn' : 'ok'}"><span>Plan tygodnia bez danych</span><b>${t.plan_without} z ${t.plan}</b><div class="mw-bar"><i class="warn" style="width:${pct(t.plan - t.plan_without, t.plan)}%"></i></div>
                <small>${t.plan_without ? names + (t.plan_without > t.plan_without_names.length ? '…' : '') + ' — zacznij od nich' : 'wszystkie produkty planu mają dane'}</small></div>
            <div class="mw-acts">${MD.next ? `<button type="button" class="kw-btn pri mw-start" data-wiz="${MD.next}"><i class="bi bi-magic"></i> Zacznij : następna maszyna bez danych</button>` : '<span class="mw-st ok"><i class="bi bi-check2"></i> każda maszyna ma produkty</span>'}
                <button type="button" class="kw-btn" data-wnew=""><i class="bi bi-plus-lg"></i> Nowa maszyna</button></div></div>`;
    }

    function mdState(m) {
        if (m.state === 'todo') return '<span class="mw-st todo"><i class="bi bi-exclamation-circle"></i> do uzupełnienia</span>';
        const n = `${m.products} ${pl(m.products, 'produkt', 'produkty', 'produktów')}`;
        return m.state === 'part' ? `<span class="mw-st part"><i class="bi bi-dash-circle"></i> ${n} · ${m.incomplete} bez tempa</span>` : `<span class="mw-st ok"><i class="bi bi-check2"></i> ${n}</span>`;
    }

    // Pojemność maszyny zależy od produktu : zakres z etapów kart (np. 216–432 szt. / wsad).
    function mdBatch(m) {
        if (m.batch_min === null || m.batch_min === undefined) return '<span class="at-sub">—</span>';
        const r = m.batch_min === m.batch_max ? nf(m.batch_min) : `${nf(m.batch_min)}–${nf(m.batch_max)}`;
        return `${r} <small class="at-sub">/ wsad</small>`;
    }

    function mdTable() {
        const full = !RO();
        const rows = MD.groups.map(g => `<tr class="grp"><td colspan="5">${esc(g.name)}${g.products ? ` <small>${g.products} ${pl(g.products, 'produkt', 'produkty', 'produktów')} · ${g.products_with} z danymi</small>` : ''}</td></tr>`
            + (g.machines.length ? g.machines.map(m => `<tr class="${m.state === 'todo' && m.id === MD.next && !m.shared_from ? 'hot' : ''}${m.shared_from ? ' mw-shared' : ''}"><td><span class="mc-name"><i class="bi ${m.icon}"></i><span><b>${esc(m.name)}</b><small>${esc(m.type_name)}${m.shared_from ? ` · <span class="mw-sh"><i class="bi bi-share"></i> wspólna — maszyna grupy ${esc(m.shared_from)}</span>` : (m.shared && m.shared.length ? ` · <span class="mw-sh"><i class="bi bi-share"></i> wspólna z : ${m.shared.map(esc).join(', ')}</span>` : '')}</small></span></span></td>
                <td class="r">${mdBatch(m)}</td><td class="r">${m.products}</td><td>${mdState(m)}</td>
                <td class="r mw-btns">${full ? `<button type="button" class="kw-btn kw-sm" data-edit="${m.id}" title="Wsad, cykl, TRS, zmiany"><i class="bi bi-sliders"></i><span class="d-none d-xl-inline"> Ustawienia</span></button>` : ''}
                    <button type="button" class="kw-btn kw-sm ${m.state === 'todo' ? 'pri' : ''}" data-wiz="${m.id}"><i class="bi bi-magic"></i> ${m.state === 'todo' ? 'Uzupełnij' : 'Zmień'}</button></td></tr>`).join('')
                : `<tr><td colspan="4"><span class="mw-st todo"><i class="bi bi-exclamation-circle"></i> brak maszyn w grupie ${esc(g.name)}</span> <small class="at-sub">dodaj maszynę (np. piec, mikser) albo zaznacz maszynę innej grupy jako wspólną</small></td>
                    <td class="r"><button type="button" class="kw-btn kw-sm pri" data-wnew="${g.id ?? ''}"><i class="bi bi-plus-lg"></i> Dodaj maszynę</button></td></tr>`)
            + (g.machines.length && g.id ? `<tr class="mw-addrow"><td colspan="5"><button type="button" class="tt-link" data-wnew="${g.id}"><i class="bi bi-plus-lg"></i> maszyna w grupie ${esc(g.name)}</button></td></tr>` : '')).join('');
        return `<div class="mc-card"><div class="table-responsive"><table class="mc-tbl"><thead><tr><th>Maszyna</th><th class="r">Wsad wg produktu</th><th class="r">Produkty</th><th>Dane</th><th></th></tr></thead><tbody>${rows}</tbody></table></div>
            <p class="mc-note">Kliknij « Uzupełnij » : zaznacz produkty robione na maszynie, potem wpisz ile sztuk w jednym wsadzie, ile wsadów na godzinę i ile osób przy wsadzie. Dane trafiają do etapów kart produkcyjnych.
                « Nowa maszyna » : nazwa, rodzaj, grupa i czy jest wspólna z innymi grupami (których). Maszyna wspólna pokazuje się w każdej z tych grup.</p></div>`;
    }

    function settingsTab() {
        if (!MD) { if (!mdLoading) loadMD(); return '<div class="kw-card"><p class="at-sub mb-0"><span class="spinner-border spinner-border-sm"></span> Ładowanie danych maszyn…</p></div>'; }
        if (RO()) return mdTiles() + mdTable();
        const f = D.freezer;
        return `${mdTiles()}<div class="mc-split">${mdTable()}
            <div class="mc-panel"><h5><i class="bi bi-snow2"></i> Mroźnia zapasu</h5>
                <div class="mc-f"><label>Miejsca paletowe<input class="form-control" data-fz-places inputmode="numeric" value="${f.places || ''}"></label>
                <label>Ostrzeżenie od (%)<input class="form-control" data-fz-alert inputmode="decimal" value="${f.alert_pct}"></label></div>
                <div class="mc-calc">Zapas dziś : <b>${nf(f.now, 1)} palet</b>${f.places ? ` · ${nf(f.pct)} %` : ''}<br>Produkcja na zapas w tygodniu : + ${nf(f.mts_week, 1)} palet (bez wysyłek)</div>
                <div class="mc-actions"><button type="button" class="kw-btn pri" data-fz-save><i class="bi bi-check2"></i> Zapisz</button></div></div></div>`;
    }

    async function loadMD() {
        mdLoading = true;
        const res = await at.send('/ajax/production/capacity/machine-data', 'GET');
        mdLoading = false;
        if (!res.ok || !res.data) { at.notify(false, res.message || 'Błąd ładowania danych maszyn.'); MD = {tiles: {machines_with: 0, machines: 0, products_with: 0, products: 0, plan_without: 0, plan: 0, plan_without_names: []}, groups: [], next: null, todo: 0}; }
        else MD = res.data;
        render();
    }

    // --- Kreator maszyny [240] (makieta 13) : 1 · Produkty › 2 · Wsad i tempo (+ osób) › 3 · Gotowe --------------------
    const num = (v) => { const t = String(v ?? '').replace(/\s/g, '').replace(',', '.'); return t === '' || isNaN(Number(t)) ? null : Number(t); };
    const fmtIn = (v) => v === null || v === undefined ? '' : String(Number(v)).replace('.', ',');

    // [241] Krok « Maszyna » (nowa albo zmiana) : nazwa, rodzaj, tryb robota, grupa, wspólna z grupami, pojemność komory.
    const MTYPES = [['four', 'bi-fire', 'Piec'], ['machine', 'bi-gear', 'Maszyna (mikser, dzielarka…)'], ['robot', 'bi-robot', 'Robot / linia'], ['manuel', 'bi-person-workspace', 'Stanowisko ręczne'],
        ['fermentation', 'bi-hourglass-split', 'Komora rozrostu'], ['froid', 'bi-thermometer-snow', 'Chłodnia'], ['surgelation', 'bi-snow', 'Mrożenie szokowe']];
    const CHAMBER = ['fermentation', 'froid', 'surgelation'];
    const RMODES = [['autonome', 'Sam (bez obsługi)'], ['avec_operateur', 'Z operatorem'], ['collaboratif', 'Cobot (pracuje z osobą)']];
    const formFrom = (m) => ({name: m ? m.name : '', type: m ? m.type : '', robot_mode: m && m.robot_mode ? m.robot_mode : '', operator_share: m && m.operator_share ? fmtIn(m.operator_share) : '0,25',
        capacity: m && CHAMBER.includes(m.type) ? fmtIn(m.capacity) : '', group_id: m ? m.group_id : null, shared: !!(m && m.shared_ids && m.shared_ids.length), share_groups: m && m.shared_ids ? m.shared_ids.slice() : []});

    function openNewMachine(groupId) {
        const f = formFrom(null);
        f.group_id = groupId || null;
        modal = {kind: 'wizard', id: null, step: 0, busy: false, data: null, form: f, sel: [], vals: {}, filter: 'group', q: '', result: null};
        render();
        const n = app.querySelector('[data-mf="name"]');
        if (n) n.focus();
    }

    async function wizSaveMachine() {
        const f = modal.form;
        const body = {name: f.name, type: f.type, robot_mode: f.robot_mode, operator_share: f.operator_share, capacity: f.capacity, group_id: f.group_id, shared: f.shared, share_groups: f.shared ? f.share_groups : []};
        modal.busy = true; render();
        const res = modal.id ? await at.send(`/ajax/production/capacity/machine-data/${modal.id}/machine`, 'PUT', body) : await at.send('/ajax/production/capacity/machine-data', 'POST', body);
        modal.busy = false;
        if (!res.ok || !res.data) { at.notify(false, res.message || 'Błąd zapisu maszyny.'); return render(); }
        at.notify(true, res.message || 'Zapisano.');
        const d = res.data;
        modal.id = d.machine.id;
        modal.data = d;
        modal.form = formFrom(d.machine);
        modal.sel = modal.sel.length ? modal.sel : d.products.filter(p => p.on).map(p => p.id);
        d.products.forEach(p => { if (p.on && !modal.vals[p.id]) modal.vals[p.id] = {per_batch: fmtIn(p.per_batch), per_hour: fmtIn(p.per_hour), people: fmtIn(p.people)}; });
        modal.filter = 'group';
        modal.step = 1;
        MD = null;
        render();
        load();
    }

    function wizStep0() {
        const f = modal.form;
        const groups = (modal.data && modal.data.groups) || (MD && MD.all_groups) || [];
        const chip = (on, attr, label, icon) => `<button type="button" class="mw-chip ${on ? 'on' : ''}" ${attr}>${icon ? `<i class="bi ${icon}"></i> ` : ''}${esc(label)}</button>`;
        const owner = groups.find(g => g.id === f.group_id);
        const others = groups.filter(g => g.id !== f.group_id);
        const t = MTYPES.find(x => x[0] === f.type);
        const m = modal.data ? modal.data.machine : {name: f.name || 'Nowa maszyna', icon: t ? t[1] : 'bi-plus-lg', group: owner ? owner.name : '', type_name: t ? t[2].toLowerCase() : 'nowa maszyna'};
        return `${wizHead(m)}
            <div class="mw-form">
                <label class="mw-fl"><span>Nazwa maszyny</span><input class="form-control" data-mf="name" maxlength="100" value="${esc(f.name)}" placeholder="np. Piec rotacyjny 2" autocomplete="off"></label>
                <div class="mw-fl"><span>Rodzaj</span><div class="mw-filter">${MTYPES.map(([k, ic, l]) => chip(f.type === k, `data-mft="${k}"`, l, ic)).join('')}</div></div>
                ${f.type === 'robot' ? `<div class="mw-fl"><span>Jak pracuje robot ?</span><div class="mw-filter">${RMODES.map(([k, l]) => chip(f.robot_mode === k, `data-mfr="${k}"`, l)).join('')}</div>
                    ${f.robot_mode === 'collaboratif' ? `<label class="mw-in-l">Część czasu jednej osoby <input class="form-control mw-num" data-mf="operator_share" inputmode="decimal" value="${esc(f.operator_share)}"> <small class="at-sub">0,25 = 1 osoba obsługuje 4 coboty</small></label>` : ''}</div>` : ''}
                ${CHAMBER.includes(f.type) ? `<label class="mw-fl"><span>Ile wózków mieści komora ?</span><input class="form-control mw-num" data-mf="capacity" inputmode="decimal" value="${esc(f.capacity)}" placeholder="np. 12"></label>` : ''}
                <div class="mw-fl"><span>Grupa — do kogo należy maszyna</span><div class="mw-filter">${groups.map(g => chip(f.group_id === g.id, `data-mfg="${g.id}"`, g.name)).join('')}</div></div>
                <div class="mw-fl"><span>Czy maszyna jest wspólna z innymi grupami ?</span><div class="mw-filter">
                    ${chip(!f.shared, 'data-mfs="0"', `Nie — tylko ${owner ? owner.name : 'ta grupa'}`)}${chip(f.shared, 'data-mfs="1"', 'Tak, wspólna', 'bi-share')}</div>
                    ${f.shared ? `<div class="mw-sub"><b>Które grupy też z niej korzystają ?</b><div class="mw-filter">${others.map(g => chip(f.share_groups.includes(g.id), `data-mfsg="${g.id}"`, g.name)).join('') || '<span class="at-sub">brak innych grup</span>'}</div>
                        <div class="mc-note">Maszyna pokaże się w każdej z tych grup (Dane maszyn, tablet TRS grupy), pracuje na zmianach każdej z nich, a praca przy ich produktach liczy się ich zespołom.</div></div>` : ''}</div>
            </div>
            <div class="mw-foot"><button type="button" class="tt-link" data-close>Anuluj</button><span class="sp"></span>
                <button type="button" class="kw-btn pri" data-wmsave ${modal.busy ? 'disabled' : ''}>${modal.busy ? '<span class="spinner-border spinner-border-sm"></span> ' : ''}${modal.id ? 'Zapisz' : 'Dodaj maszynę'} i dalej : produkty <i class="bi bi-arrow-right"></i></button></div>`;
    }

    async function openWizard(id) {
        modal = {kind: 'wizard', id, step: 1, busy: true, data: null};
        render();
        const res = await at.send(`/ajax/production/capacity/machine-data/${id}`, 'GET');
        if (!res.ok || !res.data) { modal = null; at.notify(false, res.message || 'Błąd.'); return render(); }
        const d = res.data;
        const sel = d.products.filter(p => p.on).map(p => p.id);
        const vals = {};
        d.products.forEach(p => { if (p.on) vals[p.id] = {per_batch: fmtIn(p.per_batch), per_hour: fmtIn(p.per_hour), people: fmtIn(p.people)}; });
        modal = {kind: 'wizard', id, step: 1, busy: false, data: d, sel, vals, filter: d.machine.group_id ? 'group' : 'all', q: '', result: null, form: formFrom(d.machine)};
        render();
    }

    function wizProducts() {
        const d = modal.data;
        const q = modal.q.trim().toLowerCase();
        return d.products.filter(p => {
            if (q) return p.name.toLowerCase().includes(q);
            if (modal.filter === 'group') return (d.machine.group_ids || [d.machine.group_id]).includes(p.group_id) || modal.sel.includes(p.id);
            if (modal.filter === 'nodata') return !p.has_data || modal.sel.includes(p.id);
            if (modal.filter === 'plan') return p.in_plan || modal.sel.includes(p.id);
            return true;
        });
    }

    function wizHead(m) {
        const st = (n, l) => {
            const cls = modal.step === n ? 'on' : (modal.step > n ? 'done' : '');
            const inner = `${modal.step > n ? '<i class="bi bi-check2"></i> ' : ''}${n + 1} · ${l}`;
            // « Maszyna » : powrót do danych maszyny (nazwa, grupa, wspólna) z kroków 1–2 istniejącej maszyny.
            return n === 0 && modal.id && (modal.step === 1 || modal.step === 2) ? `<button type="button" class="${cls} mw-stepbtn" data-wstep="0" title="Zmień nazwę, rodzaj, grupę, wspólną">${inner} <i class="bi bi-pencil"></i></button>` : `<span class="${cls}">${inner}</span>`;
        };
        const shared = m.shared && m.shared.length ? ` · <span class="mw-sh"><i class="bi bi-share"></i> wspólna z : ${m.shared.map(esc).join(', ')}</span>` : '';
        return `<div class="mw-steps">${st(0, 'Maszyna')}<em>›</em>${st(1, 'Produkty')}<em>›</em>${st(2, 'Wsad i tempo')}<em>›</em>${st(3, 'Gotowe')}</div>
            <div class="mw-head"><i class="big bi ${m.icon}"></i><div><b>${esc(m.name)}</b><small>${esc(m.group || 'bez grupy')} · ${esc(m.type_name)}${modal.step === 0 ? '' : ' · pojemność wg produktu'}${shared}${modal.step === 2 ? ` · zmiana ${nf(m.shift_h, 1)} h · wydajność ${nf(m.efficiency_pct)} %` : ''}</small></div></div>`;
    }

    function wizStep1() {
        const d = modal.data;
        const m = d.machine;
        const gids = m.group_ids || [m.group_id];
        const count = (f) => d.products.filter(p => f === 'group' ? gids.includes(p.group_id) : (f === 'nodata' ? !p.has_data : (f === 'plan' ? p.in_plan : true))).length;
        const chips = [['group', m.shared && m.shared.length ? `Grupy ${[m.group, ...m.shared].join(' + ')}` : `Grupa ${m.group || ''}`], ['nodata', 'Bez danych'], ['plan', 'W planie tygodnia'], ['all', 'Wszystkie']].filter(([k]) => k !== 'group' || m.group_id)
            .map(([k, l]) => `<button type="button" class="mw-chip ${modal.filter === k && !modal.q ? 'on' : ''}" data-wf="${k}">${esc(l)} (${count(k)})</button>`).join('');
        const list = wizProducts();
        const items = list.map(p => {
            const on = modal.sel.includes(p.id);
            const sub = p.on ? 'już na tej maszynie — zmiana w kroku 2' : (p.has_data ? 'już ma etapy : ' + p.others.slice(0, 3).join(', ') + (p.others.length > 3 ? '…' : '') : 'bez danych maszyn');
            return `<button type="button" class="mw-item ${on ? 'on' : ''}${p.on ? ' lock' : ''}" data-wsel="${p.id}" aria-pressed="${on}" ${p.on ? 'aria-disabled="true"' : ''}>
                <span class="ck">${on ? '<i class="bi bi-check"></i>' : ''}</span><span class="nm">${esc(p.name)}<small>${esc(sub)}${p.group && (p.group_id !== m.group_id || (m.shared && m.shared.length)) ? ' · ' + esc(p.group) : ''}</small></span>
                ${p.in_plan ? `<span class="mw-st ${p.has_data ? 'ok' : 'part'}">plan tyg.</span>` : '<span></span>'}</button>`;
        }).join('') || '<p class="at-sub m-2">Brak produktów — zmień filtr albo wyszukaj.</p>';
        return `${wizHead(m)}
            <div><b>Które produkty są robione na tej maszynie ?</b><div class="mc-note">Zaznacz wszystkie — w następnym kroku wpiszesz ile sztuk na raz, ile razy na godzinę i ile osób.</div></div>
            <input class="form-control mw-q" type="search" data-wq placeholder="szukaj produktu…" value="${esc(modal.q)}" aria-label="Szukaj produktu">
            <div class="mw-filter">${chips}</div>
            <div class="mw-lab">Zaznaczone : ${modal.sel.length}</div>
            <div class="mw-list">${items}</div>
            <div class="mw-foot"><button type="button" class="tt-link" data-close>Anuluj</button><span class="sp"></span><small>${modal.sel.length} ${pl(modal.sel.length, 'produkt', 'produkty', 'produktów')}</small>
                <button type="button" class="kw-btn pri" data-wnext ${modal.sel.length ? '' : 'disabled'}>Dalej <i class="bi bi-arrow-right"></i></button></div>`;
    }

    function wizCalc(p) {
        const m = modal.data.machine;
        const v = modal.vals[p.id] || {};
        const b = num(v.per_batch), h = num(v.per_hour), ppl = num(v.people);
        if (!(b > 0) || !(h > 0)) return {hour: '<b>—</b><small>' + (b > 0 || h > 0 ? 'wpisz ' + (b > 0 ? 'tempo' : 'wsad') : '') + '</small>', shift: '<b>—</b>', ok: false};
        const perH = b * h;
        const perShift = perH * m.shift_h * (m.efficiency_pct || 100) / 100;
        const people = ppl === null ? 1 : ppl;
        return {hour: `<b>${nf(perH)} ${esc(p.unit)}</b><small>${nf(60 / h, 1)} min / wsad</small>`,
            shift: `<b>${nf(perShift)} ${esc(p.unit)}</b><small>${p.ppc ? nf(perShift / p.ppc) + ' kart. · ' : ''}${nf(people, 1)} ${pl(Math.round(people), 'osoba', 'osoby', 'osób')}</small>`, ok: true};
    }

    function wizStep2() {
        const d = modal.data;
        const rows = modal.sel.map(id => d.products.find(p => p.id === id)).filter(Boolean);
        const filled = rows.filter(p => wizCalc(p).ok).length;
        const inp = (p, k, unit, ph) => `<span class="mw-cell"><input class="form-control mw-in" data-wv="${k}" data-wid="${p.id}" inputmode="decimal" value="${esc((modal.vals[p.id] || {})[k] || '')}" placeholder="${ph}" aria-label="${esc(p.name)} — ${unit}"><span class="mw-unit">${unit}</span></span>`;
        const body = rows.map(p => { const c = wizCalc(p); return `<tr data-wrow="${p.id}"><td><b>${esc(p.name)}</b><small>${p.in_plan ? 'w planie tygodnia' : (p.on ? 'już na tej maszynie' : esc(p.group || ''))}</small></td>
            <td class="r">${inp(p, 'per_batch', esc(p.unit), '?')}</td><td class="r">${inp(p, 'per_hour', '× / h', '?')}</td><td class="r">${inp(p, 'people', 'os.', '1')}</td>
            <td class="r mw-res ${c.ok ? 'ok' : 'none'}" data-wh>${c.hour}</td><td class="r mw-res ${c.ok ? 'ok' : 'none'}" data-ws>${c.shift}</td></tr>`; }).join('');
        return `${wizHead(d.machine)}
            <div class="mw-hint">Pojemność maszyny zależy od produktu : wpisz ją osobno dla każdego produktu.
                <b>Sztuk w 1 wsadzie</b> = ile tego produktu wchodzi na maszynę na raz (np. 18 blach × 12 bagietek = 216).
                <b>Wsadów na godzinę</b> = ile razy na godzinę maszyna się napełnia (np. 25 min + 5 min załadunku = 30 min → 2).
                <b>Osób przy wsadzie</b> = ile osób pracuje przy tym wsadzie (puste = 1).</div>
            <div class="table-responsive"><table class="mw-tbl"><thead><tr><th>Produkt</th><th class="r">Sztuk w 1 wsadzie</th>
                <th class="r">Wsadów na godzinę <button type="button" class="mw-copy" data-wcopy="per_hour"><i class="bi bi-arrow-down"></i> kopiuj do wszystkich</button></th>
                <th class="r">Osób przy wsadzie <button type="button" class="mw-copy" data-wcopy="people"><i class="bi bi-arrow-down"></i> kopiuj</button></th>
                <th class="r">= na godzinę</th><th class="r">Na zmianę</th></tr></thead><tbody>${body}</tbody></table></div>
            <div class="mc-note">Zapis : w karcie produkcyjnej każdego produktu powstaje (albo zmienia się) etap « ${esc(d.machine.name)} » z tymi liczbami. Puste wiersze nie są zapisywane — możesz wrócić później. Kolejność etapów zmienisz w karcie produktu.</div>
            <div class="mw-foot"><button type="button" class="kw-btn" data-wback><i class="bi bi-arrow-left"></i> Wstecz</button><span class="sp"></span><small data-wfilled>${filled} z ${rows.length} uzupełnione</small>
                <button type="button" class="kw-btn pri" data-wsave ${modal.busy ? 'disabled' : ''}><i class="bi bi-check2"></i> Zapisz</button></div>`;
    }

    function wizStep3() {
        const r = modal.result;
        const m = modal.data.machine;
        const n = r.created + r.updated;
        const nx = r.next_machine;
        return `${wizHead(m)}
            <div class="mw-done"><i class="big bi bi-check2-circle"></i><b class="mw-done-t">${esc(m.name)} : ${n ? `${n} ${pl(n, 'produkt zapisany', 'produkty zapisane', 'produktów zapisanych')}` : 'bez zmian'}</b>
                <div class="mc-note">${r.created ? `${r.created} ${pl(r.created, 'nowy etap', 'nowe etapy', 'nowych etapów')} w kartach` : ''}${r.created && r.updated ? ' · ' : ''}${r.updated ? `${r.updated} ${pl(r.updated, 'etap zmieniony', 'etapy zmienione', 'etapów zmienionych')}` : ''}${r.unchanged ? ` · ${r.unchanged} bez zmian` : ''}
                ${r.empty ? `<br>${r.empty} ${pl(r.empty, 'produkt', 'produkty', 'produktów')} bez danych — wróć później.` : ''}
                ${r.load_pct !== null && r.load_pct !== undefined ? `<br>Obciążenie maszyny w tym tygodniu : <b>${nf(r.load_pct)} %</b>` : ''}</div></div>
            ${nx ? `<div class="mw-lab">Następna maszyna bez danych</div>
                <div class="mw-next"><i class="mi bi ${nx.icon}"></i><div><b>${esc(nx.name)}</b><div class="mc-note">${esc(nx.group || '')} · ${esc(nx.type_name || '')}</div></div>
                    <button type="button" class="kw-btn pri" data-wiz="${nx.id}">Dalej <i class="bi bi-arrow-right"></i></button></div>` : '<div class="mw-lab">Każda maszyna ma już produkty.</div>'}
            <div class="mw-foot"><span class="sp"></span><button type="button" class="kw-btn" data-close>Wróć do listy maszyn</button></div>`;
    }

    function wizHtml() {
        let body;
        if (modal.step === 0) body = wizStep0();
        else if (!modal.data) body = '<p class="at-sub m-3"><span class="spinner-border spinner-border-sm"></span> Ładowanie…</p>';
        else body = modal.step === 1 ? wizStep1() : (modal.step === 2 ? wizStep2() : wizStep3());
        return `<div class="fc-modal" data-modal><div class="fc-modal-box mc-modal-box mw-box" role="dialog" aria-modal="true" aria-label="Kreator maszyny">${body}</div></div>`;
    }

    function wizUpdateRow(id) {
        const tr = app.querySelector(`[data-wrow="${id}"]`);
        const p = modal.data.products.find(x => x.id === id);
        if (!tr || !p) return;
        const c = wizCalc(p);
        const h = tr.querySelector('[data-wh]'), s2 = tr.querySelector('[data-ws]');
        h.innerHTML = c.hour; s2.innerHTML = c.shift;
        h.className = s2.className = `r mw-res ${c.ok ? 'ok' : 'none'}`;
        const rows = modal.sel.map(i => modal.data.products.find(x => x.id === i)).filter(Boolean);
        const f = app.querySelector('[data-wfilled]');
        if (f) f.textContent = `${rows.filter(x => wizCalc(x).ok).length} z ${rows.length} uzupełnione`;
    }

    async function wizSave() {
        const rows = modal.sel.map(id => ({item_id: id, ...(modal.vals[id] || {})}));
        modal.busy = true; render();
        const res = await at.send(`/ajax/production/capacity/machine-data/${modal.id}`, 'PUT', {rows});
        modal.busy = false;
        if (!res.ok) { at.notify(false, res.message || 'Błąd zapisu.'); return render(); }
        at.notify(true, res.message || 'Zapisano.');
        modal.step = 3;
        modal.result = res.data;
        MD = null;
        render();
        load();
    }

    // --- Okno ustawień maszyny (makieta 11) --------------------------------------------------------------------------
    function machineById(id) {
        for (const s of D.sections) for (const m of s.rows) if (m.id === id) return m;
        return (D.hidden || []).find(m => m.id === id) || null;
    }

    // Podgląd « na zmianę » : liczony na żywo przy wpisywaniu (bez przerysowania okna — fokus zostaje w polu).
    function machineCalc(m, v) {
        const shiftS = (D.shifts.find(s => v.shifts[s.id]) || D.shifts.find(s => m.shift_ids.includes(s.id)) || {hours: 8}).hours * 3600;
        const cycle = Number(String(v.cycle_min).replace(',', '.')) * 60 || m.cycle_s || 0;
        const eff = Math.min(100, Math.max(10, Number(String(v.efficiency_pct).replace(',', '.')) || 100)) / 100;
        const cap = Number(String(v.capacity_per_pass).replace(',', '.')) || 0;
        const passes = cycle ? Math.floor(shiftS * eff / cycle) : null;
        const shiftsOn = D.shifts.filter(s => v.shifts[s.id]);
        const u = esc(UNITS[v.capacity_unit] || '');
        return `${passes !== null ? `Na zmianę : ${nf(shiftS / 3600, 1)} h × 60 ÷ ${nf(cycle / 60, 1)} min × ${nf(eff * 100)} % = <b>${passes} wsadów</b> × ${nf(cap, 1)} ${u} = <b>≈ ${nf(passes * cap)} ${u}</b>` : 'Ustaw cykl wsadu albo etapy w kartach produkcyjnych, aby policzyć ilość na zmianę.'}
            <br>Tydzień : ${shiftsOn.length} ${shiftsOn.length === 1 ? 'zmiana' : 'zmiany'} dziennie · wykorzystanie teraz <b>${m.pct === null ? '—' : nf(m.pct) + ' %'}</b>`;
    }

    function updateCalc() {
        const box = app.querySelector('[data-mcalc]');
        const m = modal && modal.kind === 'machine' ? machineById(modal.id) : null;
        if (box && m) box.innerHTML = machineCalc(m, modal.values);
    }

    function modalHtml() {
        if (modal.kind === 'wizard') return wizHtml();
        if (modal.kind === 'freezer') {
            const f = D.freezer;
            return `<div class="fc-modal" data-modal><div class="fc-modal-box mc-modal-box" role="dialog" aria-modal="true" aria-label="Mroźnia zapasu">
                <div class="fc-modal-head"><h4><i class="bi bi-snow2"></i> Mroźnia zapasu — ustawienia</h4><button type="button" class="kw-btn" data-close>Zamknij</button></div>
                <div class="mc-f"><label>Miejsca paletowe<input class="form-control" data-fz-places inputmode="numeric" value="${f.places || ''}"></label>
                <label>Ostrzeżenie od (%)<input class="form-control" data-fz-alert inputmode="decimal" value="${f.alert_pct}"></label></div>
                <div class="mc-calc">Zapas dziś : <b>${nf(f.now, 1)} palet</b> (kartony w magazynie ÷ kartonów na paletę produktu)</div>
                <div class="mc-actions"><button type="button" class="tt-link" data-close>Anuluj</button><button type="button" class="kw-btn pri" data-fz-save><i class="bi bi-check2"></i> Zapisz</button></div></div></div>`;
        }
        const m = machineById(modal.id);
        if (!m) return '';
        const v = modal.values;
        return `<div class="fc-modal" data-modal><div class="fc-modal-box mc-modal-box" role="dialog" aria-modal="true" aria-labelledby="mcModalT">
            <div class="fc-modal-head"><h4 id="mcModalT"><i class="bi bi-sliders"></i> ${esc(m.name)} — ustawienia</h4><button type="button" class="kw-btn" data-close>Zamknij</button></div>
            <div class="mc-f"><label>Rodzaj<input class="form-control" value="${esc(m.type)}" disabled></label><label>Grupa<input class="form-control" value="${esc(m.group || '')}" disabled></label>
                <label>Wsad (pojemność na raz)<input class="form-control" data-mv="capacity_per_pass" inputmode="decimal" value="${esc(v.capacity_per_pass)}"></label>
                <label>Jednostka<select class="form-select" data-mv="capacity_unit">${Object.entries(UNITS).map(([k, l]) => `<option value="${k}" ${k === v.capacity_unit ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
                <label>Cykl wsadu (min) — gdy brak etapów<input class="form-control" data-mv="cycle_min" inputmode="decimal" value="${esc(v.cycle_min)}" placeholder="${m.cycle_s ? Math.round(m.cycle_s / 60) + ' (z kart)' : ''}"></label>
                <label>Wydajność realna (TRS, %)<input class="form-control" data-mv="efficiency_pct" inputmode="decimal" value="${esc(v.efficiency_pct)}"></label></div>
            <div class="mb-lab">Zmiany pracy maszyny <small class="at-sub">(domyślnie wg zespołów grupy — zapisuje się tylko to, co tu przełączysz)</small></div>
            <div class="mc-shifts">${D.shifts.map(s => `<button type="button" class="tt-chip ${v.shifts[s.id] ? 'on' : ''}" data-mshift="${s.id}" aria-pressed="${!!v.shifts[s.id]}">${esc(s.name)} (${nf(s.hours, 1)} h)</button>`).join('')}
                <label class="mc-view"><input type="checkbox" data-mv-view ${v.view ? 'checked' : ''}> pokazuj w mocach</label></div>
            <div class="mc-calc" data-mcalc>${machineCalc(m, v)}</div>
            <div class="mc-actions"><span class="at-sub">Etapy produktów na tej maszynie : karta produkcyjna › Etapy i czasy.</span><span class="wk-sp"></span>
                <button type="button" class="tt-link" data-close>Anuluj</button><button type="button" class="kw-btn pri" data-msave ${modal.busy ? 'disabled' : ''}><i class="bi bi-check2"></i> Zapisz</button></div></div></div>`;
    }

    function openMachine(id) {
        const m = machineById(id);
        if (!m) return;
        const shifts = {};
        D.shifts.forEach(s => { shifts[s.id] = m.shift_ids.includes(s.id); });
        modal = {kind: 'machine', id, busy: false, values: {capacity_per_pass: String(m.capacity_per_pass).replace('.', ','), capacity_unit: m.unit_code,
            cycle_min: m.cycle_set ? String(Math.round(m.cycle_set / 60)) : '', efficiency_pct: String(m.efficiency_pct), shifts, view: m.view}};
        render();
    }

    // --- Render, dane ----------------------------------------------------------------------------------------------------
    function render() {
        if (D.error) { app.innerHTML = `<div class="kw"><div class="kw-card"><p class="text-danger mb-0">${esc(D.error)}</p></div></div>`; return; }
        const y = window.scrollY;
        // Kreator : lista produktów i okno zachowują przewinięcie po przerysowaniu.
        const keep = ['.mw-list', '.mw-box'].map(sel => [sel, app.querySelector(sel)?.scrollTop || 0]);
        let body = '';
        if (RO()) body = tab === 'settings' ? settingsTab() : useTable();
        else if (tab === 'use') body = `<div class="mc-wrap">${useTable()}<div class="mc-side">${simPanel(false)}${ranking(5)}</div></div>`;
        else if (tab === 'more') body = moreTab();
        else if (tab === 'shift') body = shiftTab();
        else body = settingsTab();
        const tl = tabList();
        const badge = (k) => k === 'settings' && MD && MD.todo ? ` <b class="mc-tab-n">${MD.todo}</b>` : '';
        const tabs = tl.length ? `<div class="mc-tabs" role="tablist">${tl.map(([k, ic, l]) => `<button type="button" role="tab" aria-selected="${k === tab}" class="${k === tab ? 'on' : ''}" data-tab="${k}"><i class="bi ${ic}"></i> ${l}${badge(k)}</button>`).join('')}</div>` : '';
        const head = tab === 'settings' ? '' : `${bar0()}${tiles()}${warn()}`;
        app.innerHTML = `<div class="kw mc${RO() ? ' ro' : ''}">${tabs}
            ${head}${body}</div>${modal ? modalHtml() : ''}`;
        keep.forEach(([sel, top]) => { const el = app.querySelector(sel); if (el && top) el.scrollTop = top; });
        window.scrollTo(0, y);
    }

    async function load() {
        const q = new URLSearchParams();
        if (filters.date && !RO()) q.set('date', filters.date);
        if (filters.group !== '' && filters.group !== null) q.set('group', filters.group);
        if (filters.shifts.length && !RO()) q.set('shifts', filters.shifts.join(','));
        app.querySelectorAll('.mc-bar button, .mc-bar select').forEach(b => { b.disabled = true; });
        const res = await at.send('/ajax/production/capacity?' + q.toString(), 'GET');
        if (!res.ok || !res.data) { at.notify(false, res.message || 'Błąd ładowania.'); render(); return; }
        D = res.data;
        sim.res = null;
        render();
        try { history.replaceState(null, '', '?' + q.toString() + '#tab=' + tab); } catch (e) { /* bez historii */ }
    }

    async function runSim() {
        const qty = String(sim.qty).replace(',', '.');
        if (!sim.item || !(Number(qty) > 0)) return at.notify(false, 'Wybierz produkt i ilość.');
        sim.busy = true; render();
        const q = new URLSearchParams({item: sim.item, qty});
        if (filters.date) q.set('date', filters.date);
        if (filters.shifts.length) q.set('shifts', filters.shifts.join(','));
        const res = await at.send('/ajax/production/capacity/simulate?' + q.toString(), 'GET');
        sim.busy = false;
        if (!res.ok) { at.notify(false, res.message || 'Błąd.'); render(); return; }
        sim.res = res.data;
        render();
    }

    app.addEventListener('click', async (e) => {
        const t = e.target;
        if (t.closest('[data-modal]')) {
            if (t.closest('[data-close]') || (t.matches('[data-modal]') && !(modal && modal.kind === 'wizard' && (modal.step === 2 || modal.step === 0)))) { modal = null; return render(); }
            if (modal && modal.kind === 'wizard') {
                const f = modal.form;
                const mt = t.closest('[data-mft]');
                if (mt) { f.type = mt.dataset.mft; if (f.type !== 'robot') f.robot_mode = ''; return render(); }
                const mr = t.closest('[data-mfr]');
                if (mr) { f.robot_mode = mr.dataset.mfr; return render(); }
                const mg = t.closest('[data-mfg]');
                if (mg) { f.group_id = Number(mg.dataset.mfg); f.share_groups = f.share_groups.filter(x => x !== f.group_id); return render(); }
                const ms = t.closest('[data-mfs]');
                if (ms) { f.shared = ms.dataset.mfs === '1'; return render(); }
                const msg = t.closest('[data-mfsg]');
                if (msg) { const g = Number(msg.dataset.mfsg); f.share_groups = f.share_groups.includes(g) ? f.share_groups.filter(x => x !== g) : [...f.share_groups, g]; return render(); }
                if (t.closest('[data-wmsave]')) return wizSaveMachine();
                if (t.closest('[data-wstep]')) { modal.step = 0; return render(); }
                const wn = t.closest('[data-wnew]');
                if (wn) return openNewMachine(wn.dataset.wnew ? Number(wn.dataset.wnew) : null);
                const wz = t.closest('[data-wiz]');
                if (wz) return openWizard(Number(wz.dataset.wiz));
                const ws = t.closest('[data-wsel]');
                if (ws) {
                    const id = Number(ws.dataset.wsel);
                    const p = modal.data.products.find(x => x.id === id);
                    if (p && p.on) return;
                    modal.sel = modal.sel.includes(id) ? modal.sel.filter(x => x !== id) : [...modal.sel, id];
                    return render();
                }
                const wf = t.closest('[data-wf]');
                if (wf) { modal.filter = wf.dataset.wf; modal.q = ''; return render(); }
                if (t.closest('[data-wnext]')) { modal.sel.forEach(id => { modal.vals[id] = modal.vals[id] || {per_batch: '', per_hour: '', people: ''}; }); modal.step = 2; return render(); }
                if (t.closest('[data-wback]')) { modal.step = 1; return render(); }
                const wc = t.closest('[data-wcopy]');
                if (wc) {
                    const k = wc.dataset.wcopy;
                    const src = modal.sel.map(id => (modal.vals[id] || {})[k]).find(v => num(v) !== null);
                    if (src === undefined) return at.notify(false, 'Wpisz wartość w pierwszym wierszu.');
                    modal.sel.forEach(id => { modal.vals[id] = modal.vals[id] || {}; if (num(modal.vals[id][k]) === null) modal.vals[id][k] = src; });
                    return render();
                }
                if (t.closest('[data-wsave]')) return wizSave();
                return;
            }
            const sh = t.closest('[data-mshift]');
            if (sh) { modal.values.shifts[sh.dataset.mshift] = !modal.values.shifts[sh.dataset.mshift]; return render(); }
            if (t.closest('[data-msave]')) {
                const v = modal.values;
                modal.busy = true; render();
                // Tylko zmiany przełączone tutaj : reszta dalej wg zespołów grupy (i « co jeśli »).
                const m0 = machineById(modal.id);
                const changed = {};
                D.shifts.forEach(s => { if (!!v.shifts[s.id] !== m0.shift_ids.includes(s.id)) changed[s.id] = !!v.shifts[s.id]; });
                const res = await at.send(`/ajax/production/capacity/workstations/${modal.id}`, 'PUT', {capacity_per_pass: v.capacity_per_pass, capacity_unit: v.capacity_unit,
                    cycle_min: v.cycle_min === '' ? null : v.cycle_min, efficiency_pct: v.efficiency_pct, view: v.view, shifts: changed});
                at.notify(res.ok, res.message || (res.ok ? 'Zapisano.' : 'Błąd.'));
                if (!res.ok) { modal.busy = false; return render(); }
                modal = null;
                return load();
            }
        }
        if (t.closest('[data-fz-save]')) {
            const box = t.closest('.mc-panel, [data-modal]');
            const res = await at.send('/ajax/production/capacity/freezer', 'PUT', {places: (box.querySelector('[data-fz-places]').value || '0').trim(), alert_pct: box.querySelector('[data-fz-alert]').value.trim()});
            at.notify(res.ok, res.message || (res.ok ? 'Zapisano.' : 'Błąd.'));
            if (res.ok) { modal = null; load(); }
            return;
        }
        const tb = t.closest('[data-tab]');
        if (tb) { tab = tb.dataset.tab; try { history.replaceState(null, '', location.search + '#tab=' + tab); } catch (x) { /* */ } return render(); }
        const wz = t.closest('[data-wiz]');
        if (wz) return openWizard(Number(wz.dataset.wiz));
        const wn = t.closest('[data-wnew]');
        if (wn) return openNewMachine(wn.dataset.wnew ? Number(wn.dataset.wnew) : null);
        const wk = t.closest('[data-week]');
        if (wk) { filters.date = wk.dataset.week; return load(); }
        if (t.closest('[data-shifts]')) { filters.shifts = []; return load(); }
        const st = t.closest('[data-shift-toggle]');
        if (st) { const k = st.dataset.shiftToggle; filters.shifts = filters.shifts.includes(k) ? filters.shifts.filter(x => x !== k) : [...filters.shifts, k]; return load(); }
        const ed = t.closest('[data-edit]');
        if (ed) return openMachine(Number(ed.dataset.edit));
        if (t.closest('[data-freezer]')) { modal = {kind: 'freezer'}; return render(); }
        const pk = t.closest('[data-pick]');
        if (pk) { sim.item = Number(pk.dataset.pick); if (!sim.qty) sim.qty = '1000'; if (tab === 'shift' || tab === 'settings') tab = 'more'; return runSim(); }
        if (t.closest('[data-sim]')) return runSim();
    });
    app.addEventListener('change', (e) => {
        const t = e.target;
        if (t.matches('.mc-bar select[name=group]')) { filters.group = t.value; return load(); }
        if (t.matches('[data-sim-item]')) { sim.item = Number(t.value) || null; sim.res = null; return; }
        if (modal && t.matches('[data-mv]')) { modal.values[t.dataset.mv] = t.value; return updateCalc(); }
        if (modal && t.matches('[data-mv-view]')) { modal.values.view = t.checked; return; }
    });
    app.addEventListener('input', (e) => {
        const t = e.target;
        if (t.matches('[data-sim-qty]')) { sim.qty = t.value; return; }
        if (modal && modal.kind === 'wizard' && t.matches('[data-mf]')) { modal.form[t.dataset.mf] = t.value; return; }
        if (modal && modal.kind === 'wizard' && t.matches('[data-wv]')) {
            const id = Number(t.dataset.wid);
            modal.vals[id] = modal.vals[id] || {};
            modal.vals[id][t.dataset.wv] = t.value;
            return wizUpdateRow(id);
        }
        if (modal && modal.kind === 'wizard' && t.matches('[data-wq]')) {
            modal.q = t.value;
            const pos = t.selectionStart;
            render();
            const q = app.querySelector('[data-wq]');
            if (q) { q.focus(); try { q.setSelectionRange(pos, pos); } catch (x) { /* */ } }
            return;
        }
        if (modal && t.matches('input[data-mv]')) { modal.values[t.dataset.mv] = t.value; updateCalc(); }
    });
    app.addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target.matches('[data-sim-qty]')) { e.preventDefault(); runSim(); } });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && modal) { modal = null; render(); } });

    render();
})();
