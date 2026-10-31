// Moce produkcyjne [234] (makieta 10 — wykorzystanie i co jeszcze zmieszczę, makieta 11 — ilość na zmianę i ustawienia maszyny).
// Zakładki : Wykorzystanie (maszyny wg rodzaju, zespoły, mroźnia, dni, panel symulacji), Co jeszcze zmieszczę (symulacja +
// ranking wartości), Ilość na zmianę (produkty : wąskie gardło), Ustawienia maszyn (wsad, cykl, TRS, zmiany, widoczność, mroźnia).
(() => {
    const app = document.getElementById('mcApp');
    if (!app) return;
    const esc = at.esc;
    let D = JSON.parse(document.getElementById('mcBoot').textContent);
    const TABS = [['use', 'bi-speedometer2', 'Wykorzystanie'], ['more', 'bi-plus-square', 'Co jeszcze zmieszczę'], ['shift', 'bi-clock-history', 'Ilość na zmianę'], ['settings', 'bi-sliders', 'Ustawienia maszyn']];
    const ICON = {froid: 'bi-thermometer-snow', surgelation: 'bi-snow', four: 'bi-fire', machine: 'bi-gear', fermentation: 'bi-hourglass-split', robot: 'bi-robot', manuel: 'bi-person-workspace'};
    const UNITS = {piece: 'szt.', plaque: 'blach', kg: 'kg', chariot: 'wózków', bac: 'pojemników', litre: 'l'};
    let tab = (location.hash.match(/tab=(\w+)/) || [])[1] || 'use';
    if (!TABS.some(([k]) => k === tab)) tab = 'use';
    let filters = {date: D.week ? D.week.start : null, group: D.group ?? '', shifts: []};
    if (D.what_if) { if (D.what_if.afternoon) filters.shifts.push('afternoon'); if (D.what_if.night) filters.shifts.push('night'); }
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
            if (p === null) return '<span class="g0" title="brak zmiany">—</span>';
            const g = p >= 100 ? 'g4' : (p >= D.target ? 'g3' : (p >= 50 ? 'g2' : (p > 0 ? 'g1' : 'g0')));
            return `<span class="${g}" title="${p} %">${p >= 999 ? '!' : p}</span>`;
        }).join('')}</div>`;
    }

    // --- Filtry, kafelki ----------------------------------------------------------------------------------------------
    function bar0() {
        const w = D.week;
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
                <button type="button" class="${on('afternoon') ? 'on' : ''}" data-shift-toggle="afternoon" aria-pressed="${on('afternoon')}">+ popołudniowa</button>
                <button type="button" class="${on('night') ? 'on' : ''}" data-shift-toggle="night" aria-pressed="${on('night')}">+ nocna</button></span>
            <span class="at-sub mc-src"><i class="bi bi-calendar-week"></i> plan tygodnia · ${D.week.planned_items} produktów</span>
        </div>`;
    }

    function tiles() {
        const t = D.tiles;
        const f = t.freezer;
        const b = t.bottleneck;
        return `<div class="mc-tiles">
            <div class="mc-tile ${b && b.pct >= 100 ? 'bad' : (b && b.pct >= D.target ? 'warn' : '')}"><span>Wąskie gardło</span><b>${b ? `${nf(b.pct)} % · ${esc(b.name)}` : '—'}</b>
                <small>${b ? `${b.peak_day ? `szczyt ${esc(b.peak_day.label)} ${b.peak_day.pct >= 999 ? '∞' : b.peak_day.pct} %` : ''}${b.top ? ' · ' + esc(b.top) : ''}` : 'brak obciążenia w planie'}</small></div>
            <div class="mc-tile ${f.pct !== null && f.pct >= f.alert_pct ? 'bad' : (f.pct !== null && f.pct >= 75 ? 'warn' : '')}"><span>Mroźnia zapasu</span>
                <b>${f.places ? `${nf(f.now, 1)} / ${f.places} palet` : `${nf(f.now, 1)} palet`}</b>
                <small>${f.places ? `${nf(f.pct)} % · wolne ${nf(f.free, 1)} · + ${nf(f.mts_week, 1)} z produkcji na zapas` : `<button type="button" class="tt-link mc-inline" data-freezer>ustaw liczbę miejsc paletowych</button>`}</small></div>
            <div class="mc-tile"><span>Komora szokowa</span><b>${t.blast ? nf(t.blast.pct) + ' %' : '—'}</b><small>${t.blast ? `wolne ${esc(t.blast.free || '—')}` : 'brak komory w maszynach'}</small></div>
            <div class="mc-tile"><span>Średnio maszyny</span><b>${t.average.pct !== null ? nf(t.average.pct) + ' %' : '—'}</b><small>cel ${D.target} % · ${t.average.below} z ${t.average.count} poniżej</small></div>
            <div class="mc-tile ok"><span>Najwięcej miejsca</span><b>${t.best ? '≈ ' + nf(t.best.value) + ' €' : '—'}</b>
                <small>${t.best ? `+ ${nf(t.best.more)} ${esc(t.best.unit)} ${esc(t.best.name)} · limit : ${esc(t.best.limit)}` : 'brak cen standardowych'}</small></div>
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
        return `<tr class="${m.pct >= D.target ? 'hot' : ''}" data-machine="${m.id}"><td><button type="button" class="mc-name" data-edit="${m.id}" title="Ustawienia maszyny">
                <i class="bi ${ICON[m.type] || 'bi-gear'}"></i><span><b>${esc(m.name)}</b><small>${esc(m.group || '')}${m.efficiency_pct < 100 ? ` · TRS ${nf(m.efficiency_pct)} %` : ''}</small></span></button></td>
            <td>${cap}</td><td>${per}</td>
            <td class="r">${nf(m.avail_h, 1)} h</td><td class="r"${m.top ? ` title="Najwięcej : ${esc(m.top.name)}"` : ''}>${nf(m.load_h, 1)} h${m.passes ? `<small>${nf(m.passes)} wsadów</small>` : ''}</td>
            <td>${bar(m.pct)}</td><td>${days(m.days)}</td>
            <td class="r mc-free ${m.pct >= 100 ? 'none' : ''}"><b>${nf(m.free_h, 1)} h</b><small>${esc(m.free_label || '')}</small></td></tr>`;
    }

    function freezerRow() {
        const f = D.freezer;
        const p = f.places ? f.pct : null;
        return `<tr class="${p !== null && p >= f.alert_pct ? 'hot' : ''}"><td><button type="button" class="mc-name" data-freezer title="Ustawienia mroźni"><i class="bi bi-snow2"></i><span><b>Mroźnia zapasu</b><small>zapas produktów gotowych (palety)</small></span></button></td>
            <td>${f.places ? `${f.places} miejsc<small>paletowych</small>` : '<span class="at-sub">nie ustawiono</span>'}</td><td>—</td>
            <td class="r">${f.places ? f.places + ' palet' : '—'}</td><td class="r">${nf(f.now, 1)} palet<small>+ ${nf(f.mts_week, 1)} produkcja na zapas</small></td>
            <td>${p !== null ? bar(p) : '<span class="at-sub">ustaw miejsca</span>'}</td><td><small class="at-sub">${f.pct_max !== null ? `po produkcji ≤ ${nf(f.pct_max)} %` : ''}</small></td>
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
            <th>Wykorzystanie <small class="mc-th">(| cel ${D.target} %)</small></th><th>${D.week.days.map(d => esc(d.short.replace('.', ''))).join(' ')}</th><th class="r">Wolne</th></tr></thead>
            <tbody>${!hasCold && !groupSel ? `<tr class="grp"><td colspan="8">Chłodzenie i mrożenie</td></tr>${freezerRow()}` : ''}${sections}${teamRows()}</tbody></table></div>
            <p class="mc-note">Dostępne = zmiany, na których pracuje maszyna (zespół grupy, zmiany « co jeśli », zmiany włączone na maszynie) × dni produkcji × TRS. Zaplanowane = etapy kart produkcyjnych × ilości planu tygodnia (dni z Planu zespołów). Kliknij maszynę, aby zmienić jej ustawienia.</p></div>`;
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

    function settingsTab() {
        const all = [];
        D.sections.forEach(s => s.rows.forEach(m => all.push(m)));
        const hidden = D.hidden || [];
        const f = D.freezer;
        return `<div class="mc-split"><div class="mc-card"><div class="table-responsive"><table class="mc-tbl"><thead><tr><th>Maszyna</th><th>Rodzaj</th><th>Wsad</th><th>Cykl</th><th>TRS</th><th>Zmiany</th><th></th></tr></thead><tbody>
            ${all.concat(hidden).map(m => `<tr><td><span class="mc-name"><i class="bi ${ICON[m.type] || 'bi-gear'}"></i><span><b>${esc(m.name)}</b><small>${esc(m.group || '')}${m.view ? '' : ' · ukryta'}</small></span></span></td>
                <td>${esc(m.type)}</td><td>${nf(m.capacity_per_pass, 1)} ${esc(m.unit)}</td><td>${m.cycle_s ? mins(m.cycle_s) : '—'}</td><td>${nf(m.efficiency_pct)} %</td><td>${esc(m.shift_names.join(', ') || '—')}</td>
                <td class="r"><button type="button" class="kw-btn kw-sm" data-edit="${m.id}"><i class="bi bi-sliders"></i> Ustawienia</button></td></tr>`).join('')}</tbody></table></div>
            <p class="mc-note">Nowa maszyna (np. piec piętrowy do bagietek) : <a href="${esc(at.root)}/workshop/workstations">Stanowiska</a>, potem jej etapy w kartach produkcyjnych (Etapy i czasy).</p></div>
            <div class="mc-panel"><h5><i class="bi bi-snow2"></i> Mroźnia zapasu</h5>
                <div class="mc-f"><label>Miejsca paletowe<input class="form-control" data-fz-places inputmode="numeric" value="${f.places || ''}"></label>
                <label>Ostrzeżenie od (%)<input class="form-control" data-fz-alert inputmode="decimal" value="${f.alert_pct}"></label></div>
                <div class="mc-calc">Zapas dziś : <b>${nf(f.now, 1)} palet</b>${f.places ? ` · ${nf(f.pct)} %` : ''}<br>Produkcja na zapas w tygodniu : + ${nf(f.mts_week, 1)} palet (bez wysyłek)</div>
                <div class="mc-actions"><button type="button" class="kw-btn pri" data-fz-save><i class="bi bi-check2"></i> Zapisz</button></div></div></div>`;
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
        let body = '';
        if (tab === 'use') body = `<div class="mc-wrap">${useTable()}<div class="mc-side">${simPanel(false)}${ranking(5)}</div></div>`;
        else if (tab === 'more') body = moreTab();
        else if (tab === 'shift') body = shiftTab();
        else body = settingsTab();
        app.innerHTML = `<div class="kw mc"><div class="mc-tabs" role="tablist">${TABS.map(([k, ic, l]) => `<button type="button" role="tab" aria-selected="${k === tab}" class="${k === tab ? 'on' : ''}" data-tab="${k}"><i class="bi ${ic}"></i> ${l}</button>`).join('')}</div>
            ${bar0()}${tiles()}${warn()}${body}</div>${modal ? modalHtml() : ''}`;
        window.scrollTo(0, y);
    }

    async function load() {
        const q = new URLSearchParams();
        if (filters.date) q.set('date', filters.date);
        if (filters.group !== '' && filters.group !== null) q.set('group', filters.group);
        if (filters.shifts.length) q.set('shifts', filters.shifts.join(','));
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
            if (t.closest('[data-close]') || t.matches('[data-modal]')) { modal = null; return render(); }
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
        if (modal && t.matches('input[data-mv]')) { modal.values[t.dataset.mv] = t.value; updateCalc(); }
    });
    app.addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target.matches('[data-sim-qty]')) { e.preventDefault(); runSim(); } });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && modal) { modal = null; render(); } });

    render();
})();
