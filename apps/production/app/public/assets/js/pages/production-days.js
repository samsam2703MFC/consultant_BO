/*
 * Tablety › Zatwierdzenia produkcji (/workshop/production-days) : dni zatwierdzone przez zespoły na tabletach.
 * Lista dni (grupa, zatwierdzenie, linie), szczegóły dnia jak na tablecie (plan, zrobione, straty ze zdjęciami,
 * powód braku) ; kierownik produkcji poprawia ilość zrobioną i anuluje straty (API modułu, prod.plan.validate).
 * Wydajność dnia (makieta 2) : czas normatywny zrobionych sztuk ÷ czas pracy grupy (odbicia − przerwa 15 min na osobę
 * − sprzątanie 0 / 15 / 30 min × osoby), osoby ze stanowiskiem, podział czasu na stanowiska, produkty z czasem normatywnym.
 */
(function () {
    'use strict';
    const box = document.getElementById('productionDays');
    if (!box || !window.TfbWorkshop) return;
    const {WorkshopApi, ui} = window.TfbWorkshop;
    const api = new WorkshopApi(box);
    const esc = ui.esc;
    const root = box.dataset.root || '';
    const listEl = document.getElementById('pdList');
    const detailEl = document.getElementById('pdDetail');
    const nf = (v, d) => new Intl.NumberFormat('pl-PL', {maximumFractionDigits: d ?? 2}).format(v ?? 0);
    const dmy = (s) => s ? String(s).slice(0, 10).split('-').reverse().join('.') : '';
    const hm = (s) => s ? String(s).slice(11, 16) : '';
    const REASONS = {quality: 'Jakość / wygląd', burnt: 'Przypalone', damaged: 'Uszkodzone', samples: 'Próbki / testy', other: 'Inne'};
    const hmin = (m) => { const t = Math.round(m || 0); return `${Math.floor(t / 60)} h ${String(t % 60).padStart(2, '0')}`; };
    const POS_COLORS = ['#8D1D2C', '#C58B55', '#2F6FB0', '#2E7D4F', '#7A5BA6', '#B7791F', '#5F6B76'];
    const effClass = (pct, target) => pct === null ? '' : (pct >= target ? 'is-ok' : (pct >= target - 15 ? 'is-warn' : 'is-low'));
    const q = (v, item) => item.ppc ? `${nf(v / item.ppc)} kart. (${nf(v, 3)} ${esc(item.unit)})` : `${nf(v, 3)} ${esc(item.unit)}`;
    let days = [];
    let current = null; // {date, group}

    const params = new URLSearchParams(location.search);
    if (params.get('date') && params.get('group')) current = {date: params.get('date'), group: Number(params.get('group'))};

    async function loadList() {
        try {
            await api.init();
            const res = await api.list('/execution/days?days=' + encodeURIComponent(document.getElementById('pdRange').value));
            days = res.data || [];
        } catch (err) {
            listEl.innerHTML = `<p class="text-danger mb-0">${esc(err.status === 403 ? 'Brak uprawnień : zatwierdzenia poprawia kierownik produkcji.' : err.message)}</p>`;
            detailEl.innerHTML = '';
            return;
        }
        if (!days.length) {
            listEl.innerHTML = '<p class="text-muted mb-0">Brak produkcji z tabletów w tym okresie.</p>';
            detailEl.innerHTML = '';
            return;
        }
        if (!current || !days.some(d => d.date === current.date && d.group.id === current.group)) current = {date: days[0].date, group: days[0].group.id};
        drawList();
        loadDay();
    }

    function drawList() {
        listEl.innerHTML = `<ul class="pd-list">${days.map(d => `<li><button type="button" class="pd-day ${current && d.date === current.date && d.group.id === current.group ? 'is-on' : ''}" data-date="${esc(d.date)}" data-group="${d.group.id}">
            <span><strong>${esc(dmy(d.date))}</strong> · ${esc(d.group.name)}</span>
            <span class="pd-day-top"><span class="pd-state ${d.closed_at ? 'is-ok' : 'is-open'}">${d.closed_at ? 'zatwierdzony ' + esc(hm(d.closed_at)) : 'otwarty'}</span>
                ${d.efficiency_pct !== null && d.efficiency_pct !== undefined ? `<span class="pd-eff-badge ${d.efficiency_target_reached ? 'is-ok' : 'is-low'}" title="Wydajność dnia">${nf(d.efficiency_pct, 0)} %</span>` : ''}</span>
            <small class="text-muted">${d.lines_validated} / ${d.products} linii zatwierdzonych</small></button></li>`).join('')}</ul>`;
        listEl.querySelectorAll('[data-date]').forEach(b => b.addEventListener('click', () => {
            current = {date: b.dataset.date, group: Number(b.dataset.group)};
            history.replaceState(null, '', `${location.pathname}?date=${current.date}&group=${current.group}`);
            drawList();
            loadDay();
        }));
    }

    async function loadDay() {
        detailEl.innerHTML = '<p class="text-muted mb-0">Wczytywanie…</p>';
        try { drawDay(await api.get(`/execution/days/${current.date}/${current.group}`)); }
        catch (err) { detailEl.innerHTML = `<p class="text-danger mb-0">${esc(err.message)}</p>`; return; }
        loadEfficiency();
    }

    async function loadEfficiency() {
        const box = document.getElementById('pdEff');
        if (!box) return;
        try { drawEfficiency(await api.get(`/execution/days/${current.date}/${current.group}/efficiency`)); }
        catch (err) { box.innerHTML = `<p class="text-danger small mb-0">Wydajność : ${esc(err.message)}</p>`; }
    }

    function drawEfficiency(e) {
        const box = document.getElementById('pdEff');
        if (!box) return;
        const present = e.people.filter(p => p.clocked_min > 0).length;
        const cls = effClass(e.efficiency_pct, e.target_pct);
        const t = e.totals;
        const cleanBtn = (m) => `<button type="button" class="pd-eff-clean ${e.cleaning_min === m ? 'is-on' : ''}" data-clean="${m}" aria-pressed="${e.cleaning_min === m}">${m ? `Sprzątanie ${m} min × ${present} os.` : 'Bez sprzątania'}</button>`;
        const people = e.people.map(p => `<tr><td><strong>${esc(p.name)}</strong><div class="small text-muted">${esc(p.position ? p.position.name : 'bez stanowiska')}</div></td>
                <td class="text-end text-nowrap">${esc(hm(p.first_in))} – ${p.open ? '<span class="text-muted">w pracy</span>' : (p.last_out ? esc(hm(p.last_out)) : '<span class="pd-tag is-warn">brak wyjścia</span>')}</td>
                <td class="text-end">${hmin(p.clocked_min)}</td><td class="text-end">${p.break_min ? '− ' + nf(p.break_min, 0) : '—'}</td>
                <td class="text-end">${p.cleaning_min ? '− ' + nf(p.cleaning_min, 0) : '—'}</td><td class="text-end"><strong>${hmin(p.work_min)}</strong></td></tr>`).join('');
        const split = e.positions.filter(s => s.work_min > 0);
        const bar = split.map((s, k) => `<i style="width:${s.pct}%;background:${POS_COLORS[k % POS_COLORS.length]}" title="${esc(s.name || 'bez stanowiska')} ${nf(s.pct, 0)} %"></i>`).join('');
        const legend = split.map((s, k) => `<span><i style="background:${POS_COLORS[k % POS_COLORS.length]}"></i>${esc(s.name || 'bez stanowiska')}</span>
                <span class="text-muted text-end">${s.people} os.</span><strong class="text-end">${hmin(s.work_min)} · ${nf(s.pct, 0)} %</strong>`).join('');
        const products = e.products.map(p => `<tr><td><strong>${esc(p.name)}</strong></td><td class="text-end">${nf(p.produced, 3)} ${esc(p.unit)}</td>
                <td class="text-end">${p.min_per_unit !== null ? nf(p.min_per_unit, 2) : '<span class="pd-tag is-warn" title="Brak czasu normatywnego na karcie produktu">brak</span>'}</td>
                <td class="text-end">${p.standard_min !== null ? nf(p.standard_min, 0) : '—'}</td></tr>`).join('');
        const why = e.efficiency_pct === null ? (t.work_min <= 0 ? 'brak czasu odbitego w tym dniu' : 'brak czasu normatywnego zrobionych produktów') : `${hmin(t.standard_min)} normatywne ÷ ${hmin(t.work_min)} pracy`;
        box.innerHTML = `<div class="pd-eff-head">
                <div class="pd-eff-big"><span class="pd-eff-lbl">Wydajność · ${esc(e.group.name)} ${esc(dmy(e.date).slice(0, 5))}</span>
                    <strong class="${cls}">${e.efficiency_pct !== null ? nf(e.efficiency_pct, 0) + ' %' : '—'}</strong><small class="text-muted">${esc(why)}</small></div>
                <div class="pd-eff-cleaning"><span class="pd-eff-lbl">Sprzątanie (odejmowane od czasu pracy)</span>
                    <div class="pd-eff-seg">${e.cleaning_options.map(cleanBtn).join('')}</div>
                    <p class="small text-muted mb-0">Przerwa : ${nf(e.break_min, 0)} min na osobę obecną (bez kontroli). Sprzątanie : 15 albo 30 min × osoby obecne.</p></div>
                <div class="pd-eff-target"><span class="pd-eff-lbl">Cel</span><strong>${nf(e.target_pct, 0)} %</strong>
                    ${e.efficiency_pct !== null ? `<span class="pd-tag ${e.target_reached ? 'is-ok' : 'is-low'}">${e.target_reached ? 'cel osiągnięty' : 'poniżej celu'}</span>` : ''}</div></div>
            <div class="pd-eff-grid">
                <section class="pd-eff-card"><span class="pd-eff-lbl">Czas odbity — osoby</span>
                    ${people ? `<div class="table-responsive"><table class="table table-sm align-middle pd-eff-table"><thead><tr><th>Osoba · stanowisko</th><th class="text-end">Wejście – wyjście</th><th class="text-end">Odbite</th><th class="text-end">Przerwy</th><th class="text-end">Sprząt.</th><th class="text-end">Praca</th></tr></thead>
                        <tbody>${people}</tbody><tfoot><tr><td>Razem</td><td></td><td class="text-end">${hmin(t.clocked_min)}</td><td class="text-end">− ${nf(t.break_min, 0)}</td><td class="text-end">− ${nf(t.cleaning_min, 0)}</td><td class="text-end">${hmin(t.work_min)}</td></tr></tfoot></table></div>`
                        : '<p class="text-muted small mb-0">Brak odbić osób grupy w tym dniu.</p>'}
                    ${split.length ? `<span class="pd-eff-lbl mt-3">Wg stanowisk</span><div class="pd-eff-bar">${bar}</div><div class="pd-eff-legend">${legend}</div>` : ''}</section>
                <section class="pd-eff-card"><span class="pd-eff-lbl">Produkcja — czas normatywny</span>
                    ${products ? `<div class="table-responsive"><table class="table table-sm align-middle pd-eff-table"><thead><tr><th>Produkt</th><th class="text-end">Zrobione</th><th class="text-end">Min / szt.</th><th class="text-end">Min</th></tr></thead>
                        <tbody>${products}</tbody><tfoot><tr><td>Razem</td><td class="text-end">${nf(t.produced, 3)}</td><td></td><td class="text-end">${nf(t.standard_min, 0)}</td></tr></tfoot></table></div>
                        ${t.missing_standard ? `<p class="small text-muted mb-0">« brak » : produkt bez czasu normatywnego (karta produktu : partia — osoby × minuty, albo etapy) — nie wchodzi do wydajności.</p>` : ''}`
                        : '<p class="text-muted small mb-0">Brak partii zrobionych w tym dniu.</p>'}</section>
            </div>`;
        box.querySelectorAll('[data-clean]').forEach(b => b.addEventListener('click', async () => {
            if (Number(b.dataset.clean) === e.cleaning_min) return;
            box.querySelectorAll('[data-clean]').forEach(x => { x.disabled = true; });
            try { drawEfficiency(await api.put(`/execution/days/${current.date}/${current.group}/cleaning`, {minutes: Number(b.dataset.clean)})); loadListQuiet(); }
            catch (err) { ui.notify(false, err.message); box.querySelectorAll('[data-clean]').forEach(x => { x.disabled = false; }); }
        }));
    }

    function drawDay(d) {
        const c = d.closed;
        const rows = d.items.map(i => {
            const live = i.wastes.filter(w => !w.cancelled);
            const line = i.line;
            const short = i.planned !== null && i.produced + 1e-6 < i.planned;
            return `<tr data-item="${i.item_id}" class="${short ? 'pd-short' : ''}">
                <td><strong>${esc(i.name)}</strong>${i.planned === null ? ' <span class="badge bg-light text-muted">poza planem</span>' : ''}
                    ${line ? `<div class="small text-muted">${line.employee ? 'zatwierdził(a) ' + esc(line.employee) + ' ' + esc(hm(line.validated_at)) : ''}${line.corrected_by ? ' · poprawka : ' + esc(line.corrected_by) + ' ' + esc(hm(line.corrected_at)) : ''}</div>` : '<div class="small text-muted">linia niezatwierdzona</div>'}
                    ${line && line.note ? `<div class="small">Powód braku : ${esc(line.note)}</div>` : ''}</td>
                <td class="text-end text-nowrap">${i.planned !== null ? q(i.planned, i) : '—'}</td>
                <td class="text-nowrap"><div class="input-group input-group-sm pd-qty">
                    <input type="number" class="form-control text-end" min="0" step="any" value="${esc(i.produced)}" data-produced aria-label="Zrobione (${esc(i.unit)})">
                    <span class="input-group-text">${esc(i.unit)}</span><button type="button" class="btn btn-outline-primary" data-save>Zapisz</button></div>
                    ${i.ppc ? `<div class="small text-muted">${nf(i.produced / i.ppc)} kart. · ${i.ppc} ${esc(i.unit)}/kart.</div>` : ''}</td>
                <td>${live.length ? `<ul class="pd-wastes">${live.map(w => `<li><span><strong>${nf(w.quantity, 3)} ${esc(i.unit)}</strong> · ${esc(REASONS[w.reason] || w.reason)}${w.note ? ' — ' + esc(w.note) : ''}
                    <small class="text-muted">${esc(hm(w.created_at))}${w.employee ? ' · ' + esc(w.employee) : ''}</small></span>
                    <span class="pd-photos">${(w.photos || []).map(p => `<a href="${esc(root)}/workshop/traceability/photo/${p}" target="_blank" rel="noopener"><img src="${esc(root)}/workshop/traceability/photo/${p}" alt="Zdjęcie straty" loading="lazy"></a>`).join('')}</span>
                    <button type="button" class="btn btn-sm btn-outline-danger" data-cancel-waste="${w.id}">Anuluj</button></li>`).join('')}</ul>` : '<span class="text-muted">—</span>'}</td></tr>`;
        }).join('');
        detailEl.innerHTML = `<div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
                <div><h5 class="m-0">${esc(d.group.name)} · ${esc(dmy(d.date))}</h5>
                <p class="text-muted small mb-0">${c ? `Zatwierdzony ${esc(hm(c.at))}${c.employee ? ' przez ' + esc(c.employee) : ''}${c.note ? ' · notatka : ' + esc(c.note) : ''} — tablet tylko czyta, poprawki tutaj.` : 'Dzień jeszcze niezatwierdzony przez zespół (tablet może zmieniać).'}</p></div>
                <span class="pd-totals small text-muted">Zrobione ${nf(d.totals.produced, 3)} · straty ${nf(d.totals.waste, 3)}${d.totals.waste_pct !== null ? ' (' + nf(d.totals.waste_pct, 1) + ' %)' : ''}</span></div>
            <div id="pdEff" class="pd-eff"><p class="text-muted small mb-0">Wydajność dnia : wczytywanie…</p></div>
            <h6 class="pd-lines-title">Linie dnia i poprawki</h6>
            ${rows ? `<div class="table-responsive"><table class="table table-sm align-middle pd-table"><thead><tr><th>Produkt</th><th class="text-end">Plan</th><th>Zrobione</th><th>Straty i zdjęcia</th></tr></thead><tbody>${rows}</tbody></table></div>`
                : '<p class="text-muted mb-0">Brak produkcji w tym dniu.</p>'}`;
        detailEl.querySelectorAll('tr[data-item]').forEach(tr => {
            tr.querySelector('[data-save]').addEventListener('click', async (e) => {
                const btn = e.currentTarget;
                const v = Number(tr.querySelector('[data-produced]').value);
                if (!Number.isFinite(v) || v < 0) { ui.notify(false, 'Podaj ilość zrobioną (0 lub więcej).'); return; }
                if (!window.confirm('Zapisać poprawioną ilość zrobioną ? Partie dnia zostaną dopasowane, podsumowanie zatwierdzenia przeliczone.')) return;
                btn.disabled = true;
                try {
                    drawDay(await api.patch(`/execution/days/${current.date}/${current.group}/items/${tr.dataset.item}`, {produced: v}));
                    ui.notify(true, 'Poprawka zapisana.');
                    loadListQuiet();
                } catch (err) { btn.disabled = false; ui.notify(false, err.message); }
            });
            tr.querySelectorAll('[data-cancel-waste]').forEach(b => b.addEventListener('click', async () => {
                if (!window.confirm('Anulować tę stratę ? Linia i podsumowanie zatwierdzenia zostaną przeliczone.')) return;
                b.disabled = true;
                try { drawDay(await api.patch(`/execution/waste/${b.dataset.cancelWaste}`, {})); ui.notify(true, 'Strata anulowana.'); }
                catch (err) { b.disabled = false; ui.notify(false, err.message); }
            }));
        });
    }

    async function loadListQuiet() {
        try { days = (await api.list('/execution/days?days=' + encodeURIComponent(document.getElementById('pdRange').value))).data || []; drawList(); } catch (e) { /* lista zostaje */ }
    }

    document.getElementById('pdRange').addEventListener('change', loadList);
    loadList();
})();
