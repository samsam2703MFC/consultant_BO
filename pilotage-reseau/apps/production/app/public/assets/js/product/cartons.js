/*
 * Kartony i palety [205] (rail Produkt) : rodzaje kartonów — wymiary zewnętrzne, waga kartonu, maks. ładunek, rodzaj ;
 * układ na palecie EUR / przemysłowej (warstwa, warstwy do maks. wysokości) ; produkty w kartonie z kontrolą wagi
 * (zawartość ≤ maks. ładunek) i układu z ERP (kartony na warstwę, wysokość, waga palety). Karta kartonu : zmiana
 * wymiarów od razu przelicza paletę (przed zapisem).
 */
(function () {
    const root = document.getElementById('cartonsApp');
    if (!root) return;
    const esc = at.esc;
    const PV = window.PalletViews;
    const num = (v, d = 0) => v === null || v === undefined ? '—' : Number(v).toLocaleString('pl-PL', { minimumFractionDigits: d, maximumFractionDigits: d });
    const KIND = { klapowy: 'klapowy', fasonowy: 'fasonowy', klejony: 'klejony', inny: 'inny' };
    const PALLET = { eur: 'EUR 1200 × 800', industrial: 'Przemysłowa 1200 × 1000' };
    const SRC = { card: 'karta produktu', ppwr: 'karton sprzedażowy (PPWR)', recipe: 'receptura' };
    let D = JSON.parse(document.getElementById('cartonsBoot').textContent);
    let filter = 'active', pallet = 'eur', search = '';
    const view = root.querySelector('[data-view]');

    const issueText = (i) => ({
        LAYER_TOO_MANY: `${i.params.cpl} na warstwę — mieści się ${i.params.max}`,
        TOO_HIGH: `${num(i.params.height)} mm > maks. ${num(i.params.max)} mm`,
        TOO_HEAVY: `${num(i.params.kg, 1)} kg > maks. ${num(i.params.max)} kg`,
    }[i.code] || i.code);
    const dims = (c) => c.length_mm && c.width_mm && c.height_mm ? `${num(c.length_mm)} × ${num(c.width_mm)} × ${num(c.height_mm)}` : '<span class="ct-miss">brak wymiarów</span>';

    function renderKpis() {
        const active = D.cartons.filter(c => c.active);
        const withCarton = D.products_total - D.without_carton;
        const issues = D.cartons.reduce((s, c) => s + c.issue_count, 0);
        const tile = (label, value, sub, cls = '') => `<div class="ct-kpi ${cls}"><span>${label}</span><b>${value}</b><small>${sub}</small></div>`;
        root.querySelector('[data-kpis]').innerHTML =
            tile('Rodzaje kartonów', num(active.length), `${D.cartons.length - active.length} w archiwum`)
            + tile('Produkty w kartonach', num(withCarton), `z ${num(D.products_total)} aktywnych produktów`)
            + tile('Bez kartonu', num(D.without_carton), 'karton w recepturze albo w kroku « Paleta »', D.without_carton ? 'is-warn' : 'is-ok')
            + tile('Do sprawdzenia', num(issues), 'za ciężkie albo układ z ERP nie pasuje', issues ? 'is-bad' : 'is-ok')
            + tile('Maks. wysokość palety', num(D.limits.max_height_mm) + ' mm', `waga brutto ≤ ${num(D.limits.max_kg)} kg · parametry`);
    }

    function render() {
        renderKpis();
        const q = search.trim().toLowerCase();
        const rows = D.cartons.filter(c => (filter === 'all' || (filter === 'active') === c.active) && (!q || c.name.toLowerCase().includes(q)));
        const seg = (attr, cur, opts) => `<div class="ct-seg" role="group">${Object.entries(opts).map(([k, l]) => `<button type="button" data-${attr}="${k}" class="${cur === k ? 'on' : ''}" aria-pressed="${cur === k}">${l}</button>`).join('')}</div>`;
        const body = rows.map(c => {
            const p = c.plans[pallet];
            return `<tr data-open="${c.id}" class="${c.active ? '' : 'ct-dim'}" tabindex="0">
                <td><b>${esc(c.name)}</b>${c.kind ? ` <span class="ct-badge">${esc(KIND[c.kind] || c.kind)}</span>` : ''}${c.active ? '' : ' <span class="ct-badge is-off">archiwum</span>'}<small>${esc(c.category || '')}</small></td>
                <td class="n">${dims(c)}<small>${c.volume_l !== null ? num(c.volume_l, 1) + ' l' : ''}</small></td>
                <td class="n">${c.tare_g !== null ? num(c.tare_g) + ' g' : '<span class="ct-miss">—</span>'}</td>
                <td class="n">${c.max_load_kg !== null ? num(c.max_load_kg, 1) + ' kg' : '<span class="ct-miss">—</span>'}${c.heaviest_kg !== null ? `<small class="${c.max_load_kg !== null && c.heaviest_kg > c.max_load_kg ? 'ct-bad' : ''}">najcięższy ${num(c.heaviest_kg, 1)} kg</small>` : ''}</td>
                <td class="n">${c.price !== null ? num(c.price, 2) + ' zł' : '—'}</td>
                <td class="n">${p ? `<b>${p.per_layer} × ${p.layers} = ${p.cartons}</b><small>${num(p.height_mm)} mm · podłoga ${num(p.floor_pct)} %</small>` : '<span class="ct-miss">—</span>'}</td>
                <td class="n">${c.product_count ? num(c.product_count) : '—'}${c.issue_count ? ` <span class="ct-badge is-bad">⚠ ${c.issue_count}</span>` : ''}</td>
                <td class="n"><i class="bi bi-chevron-right" aria-hidden="true"></i></td></tr>`;
        }).join('');
        view.innerHTML = `<div class="ct-toolbar">
                <input class="form-control ct-search" type="search" placeholder="Szukaj kartonu…" value="${esc(search)}" data-search aria-label="Szukaj kartonu">
                ${seg('filter', filter, { active: 'Aktywne', archived: 'Archiwum', all: 'Wszystkie' })}
                ${seg('pallet', pallet, PALLET)}
                <a class="btn btn-outline-primary ct-big" href="${root.dataset.root}/products/cartons/mixed"><i class="bi bi-layers"></i> Paleta mieszana</a>
                <button type="button" class="btn btn-primary ct-big" data-add><i class="bi bi-plus-lg"></i> Dodaj karton</button></div>
            <div class="ct-card"><div class="ct-scroll"><table class="ct-table"><thead><tr><th>Karton</th><th class="n">Wymiary zewn. (mm)</th><th class="n">Waga kartonu</th>
                <th class="n">Maks. ładunek</th><th class="n">Cena</th><th class="n">Paleta ${esc(PALLET[pallet].split(' ')[0])} : warstwa × warstwy</th><th class="n">Produkty</th><th></th></tr></thead>
                <tbody>${body || '<tr><td colspan="8" class="at-empty">Brak kartonów.</td></tr>'}</tbody></table></div></div>
            <p class="ct-hint">Karton = opakowanie z listy surowców (cena, dostawca, waga w BDO). Paleta : najwięcej kartonów na warstwie (karton wzdłuż, w poprzek albo dwa bloki),
                warstwy do ${num(D.limits.max_height_mm)} mm z paletą. Produkty : karton wybrany w karcie produktu (krok « Paleta »), karton sprzedażowy PPWR albo karton z receptury.</p>`;
        const s = view.querySelector('[data-search]');
        s.addEventListener('input', () => { search = s.value; const pos = s.selectionStart; render(); const n = view.querySelector('[data-search]'); n.focus(); n.setSelectionRange(pos, pos); });
    }

    view.addEventListener('click', (e) => {
        const b = e.target.closest('[data-filter], [data-pallet], [data-add], [data-open]');
        if (!b) return;
        if (b.dataset.filter) { filter = b.dataset.filter; render(); }
        else if (b.dataset.pallet) { pallet = b.dataset.pallet; render(); }
        else if (b.matches('[data-add]')) openAdd();
        else if (b.dataset.open) openCarton(Number(b.dataset.open));
    });
    view.addEventListener('keydown', (e) => { const r = e.target.closest('[data-open]'); if (r && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); openCarton(Number(r.dataset.open)); } });

    function overlay(html, label) {
        const box = document.createElement('div');
        box.className = 'ct-modal';
        box.innerHTML = `<div class="ct-modal-box" role="dialog" aria-modal="true" aria-label="${esc(label)}">${html}</div>`;
        document.body.appendChild(box);
        const close = () => { box.remove(); document.removeEventListener('keydown', onKey); };
        const onKey = (e) => { if (e.key === 'Escape') close(); };
        document.addEventListener('keydown', onKey);
        box.addEventListener('click', (e) => { if (e.target === box || e.target.closest('[data-close]')) close(); });
        return { box, close };
    }

    async function reload() {
        const res = await at.send('/ajax/products/cartons', 'GET');
        if (res.ok) { D = res.data; render(); }
    }

    async function openAdd() {
        const res = await at.send('/ajax/products/cartons/candidates', 'GET');
        if (!res.ok) { at.notify(false, res.message || 'Błąd'); return; }
        const m = overlay(`<form class="ct-form" data-add-form><div class="ct-modal-head"><h4>Dodaj karton do katalogu</h4><button type="button" class="btn btn-outline-secondary" data-close>Zamknij</button></div>
            <label>Opakowanie z listy surowców<select class="form-select" name="raw_material_id" required><option value="">Wybierz…</option>
                ${res.data.map(c => `<option value="${c.id}">${esc(c.name)}${c.category ? ' · ' + esc(c.category) : ''}</option>`).join('')}</select></label>
            <p class="ct-hint">Nowe opakowanie dodaje się najpierw w <a href="${at.root}/materials/all">Surowce</a> (cena, dostawca). Wymiary : z ERP albo PPWR, jeśli są.</p>
            <div class="ct-modal-foot"><button type="submit" class="btn btn-primary ct-big">Dodaj</button></div></form>`, 'Dodaj karton');
        const f = m.box.querySelector('form');
        f.addEventListener('submit', async (e) => {
            e.preventDefault();
            const r = await at.send('/ajax/products/cartons', 'POST', { raw_material_id: f.raw_material_id.value });
            if (!r.ok) { at.notify(false, r.message || 'Błąd'); return; }
            at.notify(true, r.message);
            m.close();
            await reload();
            openCarton(r.data.id);
        });
    }

    // API warsztatu (jak karta produktu) : opakowanie i układ palety produktu zapisane prosto z okna kartonu [211].
    let wapi = null;
    const api = async () => {
        if (!wapi) { wapi = new window.TfbWorkshop.WorkshopApi(root); await wapi.init(); }
        return wapi;
    };
    const apiErr = (err) => ((err.body && err.body.errors) || []).map(x => x.message).join(' ') || err.message || 'Błąd';
    const LAYOUT_CODES = ['LAYER_TOO_MANY', 'TOO_HIGH', 'TOO_HEAVY'];
    const fits = (p) => p.best && p.best.cartons > 0 && (p.erp.cpl !== p.best.per_layer || p.erp.lpp !== p.best.layers || p.erp.cpp !== p.best.cartons);

    async function openCarton(id, keepScroll) {
        const res = await at.send(`/ajax/products/cartons/${id}`, 'GET');
        if (!res.ok) { at.notify(false, res.message || 'Błąd'); return; }
        const c = res.data;
        const dflt = c.defaults || D.limits || {};
        let pal = pallet;
        const field = (name, label, value, unit, extra = '') => `<label>${label}<div class="ct-unit"><input class="form-control" name="${name}" inputmode="decimal" value="${value ?? ''}" ${extra}><span>${unit}</span></div></label>`;
        const cell = (p, name, value, label, w = 64) => `<input class="form-control form-control-sm ct-in" style="width:${w}px" name="${name}" inputmode="numeric" value="${value ?? ''}" aria-label="${esc(label + ' ' + p.name)}" ${p.item_id ? '' : 'disabled'}>`;
        const badFix = c.products.filter(p => p.item_id && fits(p) && p.issues.some(i => LAYOUT_CODES.includes(i.code)));
        const products = c.products.map(p => `<tr class="${p.over_load || p.issues.length ? 'ct-warnrow' : ''}" data-prow="${p.id}">
            <td>${p.item_id ? `<a href="${at.root}/workshop/products#/item/${p.item_id}/3">${esc(p.name)}</a>` : esc(p.name)}<small>${esc(SRC[p.source] || '')}${p.limits && p.limits.own ? ' · własne limity palety' : ''}</small></td>
            <td class="n"><span class="ct-edit">${cell(p, 'ppc', p.ppc, 'Sztuk w kartonie')} szt.</span><small class="ct-edit">${cell(p, 'weight', p.weight_g, 'Waga sztuki', 70)} g / szt.</small></td>
            <td class="n ${p.over_load ? 'ct-bad' : ''}" data-net>${p.net_kg !== null ? num(p.net_kg, 2) + ' kg' : '—'}${p.over_load ? '<small>> maks. ładunek</small>' : ''}</td>
            <td class="n" data-gross>${p.gross_kg !== null ? num(p.gross_kg, 2) + ' kg' : '—'}</td>
            <td class="n"><span class="ct-edit">${cell(p, 'cpl', p.erp.cpl || '', 'Kartony na warstwę', 52)} × ${cell(p, 'lpp', p.erp.lpp || '', 'Warstwy', 52)} = <b data-cpp title="kartony na paletę w ERP">${p.erp.cpp || '—'}</b></span>
                ${p.best ? `<small>mieści się ${p.best.per_layer} × ${p.best.layers} = ${p.best.cartons} · ${num(p.best.height_mm)} mm${p.best.gross_kg !== null ? ' · ' + num(p.best.gross_kg) + ' kg' : ''}${p.best.by_weight ? ' · <b>warstwy wg wagi</b>' : ''}
                    ${p.item_id && fits(p) ? `<button type="button" class="ct-link" data-fit="${p.id}">↧ zastosuj</button>` : ''}</small>` : ''}</td>
            <td>${p.issues.map(i => `<span class="ct-badge is-bad">${esc(issueText(i))}</span>`).join(' ') || (p.over_load ? '' : '<span class="ct-badge is-ok">OK</span>')}</td>
            <td>${p.item_id ? `<button type="button" class="btn btn-sm btn-outline-primary ct-save" data-psave="${p.id}" disabled>Zapisz</button>` : '<small class="ct-miss">brak karty</small>'}</td></tr>`).join('');
        const m = overlay(`<div class="ct-modal-head"><h4>${esc(c.name)}</h4><button type="button" class="btn btn-outline-secondary" data-close>Zamknij</button></div>
            <div class="ct-detail">
                <form class="ct-form" data-carton-form>
                    <h6>Wymiary zewnętrzne</h6>
                    <div class="ct-grid3">${field('length_mm', 'Długość', c.length_mm, 'mm')}${field('width_mm', 'Szerokość', c.width_mm, 'mm')}${field('height_mm', 'Wysokość', c.height_mm, 'mm')}</div>
                    <h6>Waga</h6>
                    <div class="ct-grid2">${field('tare_g', 'Waga pustego kartonu', c.tare_g !== null ? num(c.tare_g, 0).replace(/\s/g, '') : '', 'g')}${field('max_load_kg', 'Maks. ładunek (zawartość)', c.max_load_kg !== null ? String(c.max_load_kg).replace('.', ',') : '', 'kg')}</div>
                    <h6>Paleta z tym kartonem</h6>
                    <div class="ct-grid2">${field('pallet_max_height_mm', 'Maks. wysokość palety', c.pallet_max_height_mm ?? '', 'mm', `placeholder="${dflt.max_height_mm ?? ''}"`)}${field('pallet_max_kg', 'Maks. waga palety', c.pallet_max_kg ?? '', 'kg', `placeholder="${dflt.max_kg ?? ''}"`)}</div>
                    <p class="ct-hint">Puste = parametry logistyki (${num(dflt.max_height_mm)} mm, ${num(dflt.max_kg)} kg). Warstwy każdego produktu liczone z jego wagi : cięższy produkt = mniej warstw.</p>
                    <div class="ct-grid2"><label>Rodzaj<select class="form-select" name="kind"><option value="">—</option>${Object.keys(KIND).map(k => `<option value="${k}" ${c.kind === k ? 'selected' : ''}>${k}</option>`).join('')}</select></label>
                        <label class="ct-switch"><input type="checkbox" name="active" ${c.active ? 'checked' : ''}> Aktywny (do wyboru w karcie produktu)</label></div>
                    <label>Notatka<input class="form-control" name="note" maxlength="255" value="${esc(c.note || '')}" placeholder="np. 5-warstwowy, do mrożonek"></label>
                    <p class="ct-hint">Waga kartonu = ta sama co w Opakowaniach BDO. Cena i dostawca : <a href="${at.root}/materials/all">Surowce</a>.</p>
                    <div class="ct-modal-foot"><button type="submit" class="btn btn-primary ct-big">Zapisz</button></div>
                </form>
                <div class="ct-views">
                    <div class="ct-seg" role="group">${Object.entries(PALLET).map(([k, l]) => `<button type="button" data-mpal="${k}" class="${pal === k ? 'on' : ''}">${l}</button>`).join('')}</div>
                    <div data-plan></div>
                </div>
            </div>
            <div class="ct-sub-row"><h6 class="ct-sub">Produkty w tym kartonie (${c.products.length})</h6>
                ${badFix.length ? `<button type="button" class="btn btn-sm btn-primary" data-fit-all>↧ Dopasuj układ palety dla ${badFix.length} produktów z problemem</button>` : ''}</div>
            ${c.products.length ? `<p class="ct-hint">Popraw prosto w tabeli : sztuki w kartonie, waga sztuki, kartony na warstwę × warstwy (kartony na paletę = iloczyn) — zapis do karty produktu.</p>
                <div class="ct-scroll"><table class="ct-table ct-prod"><thead><tr><th>Produkt</th><th class="n">W kartonie</th><th class="n">Zawartość</th><th class="n">Z kartonem</th><th class="n">Układ w ERP</th><th>Kontrola</th><th></th></tr></thead><tbody>${products}</tbody></table></div>`
                : '<p class="at-empty">Żaden aktywny produkt nie używa tego kartonu.</p>'}`, c.name);
        const f = m.box.querySelector('[data-carton-form]');
        const planBox = m.box.querySelector('[data-plan]');
        const scroller = m.box.querySelector('.ct-modal-box');
        if (keepScroll && scroller) scroller.scrollTop = keepScroll;
        const reopen = async () => { const top = scroller ? scroller.scrollTop : 0; m.close(); await reload(); openCarton(id, top); };
        let timer = null;
        const showPlan = async () => {
            const v = (n) => (f[n].value || '').replace(',', '.').replace(/\s/g, '');
            if (!(v('length_mm') > 0 && v('width_mm') > 0 && v('height_mm') > 0)) { planBox.innerHTML = '<p class="at-empty">Podaj wymiary kartonu, aby zobaczyć paletę.</p>'; return; }
            const qs = new URLSearchParams({ length_mm: v('length_mm'), width_mm: v('width_mm'), height_mm: v('height_mm'), tare_g: v('tare_g'), pallet: pal,
                max_height_mm: v('pallet_max_height_mm') || dflt.max_height_mm || '', max_kg: v('pallet_max_kg') || dflt.max_kg || '' });
            const r = await at.send('/ajax/pallet/plan?' + qs, 'GET');
            if (!r.ok) { planBox.innerHTML = `<p class="at-empty">${esc(r.message || 'Błąd')}</p>`; return; }
            const p = r.data;
            planBox.innerHTML = `<div class="ct-tiles"><div><span>Na warstwę</span><b>${p.per_layer}</b></div><div><span>Warstw</span><b>${p.max.layers}</b></div>
                <div><span>Na paletę</span><b>${p.max.cartons}</b></div><div><span>Wysokość</span><b>${num(p.max.height_mm)} mm</b></div><div><span>Podłoga</span><b>${num(p.floor_pct)} %</b></div></div>
                <div class="ct-pics"><div>${PV.top(p, 330)}<small>warstwa z góry</small></div><div>${PV.side(p, p.max.layers, { width: 300, stack: false })}<small>z boku · maks. ${num(Number(v('pallet_max_height_mm')) || dflt.max_height_mm)} mm</small></div></div>`;
        };
        f.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(showPlan, 300); });
        m.box.querySelectorAll('[data-mpal]').forEach(b => b.addEventListener('click', () => {
            pal = b.dataset.mpal;
            m.box.querySelectorAll('[data-mpal]').forEach(x => x.classList.toggle('on', x === b));
            showPlan();
        }));
        f.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = f.querySelector('[type=submit]');
            btn.disabled = true;
            const val = (n) => f[n].value.trim() === '' ? null : f[n].value.trim();
            const r = await at.send(`/ajax/products/cartons/${id}`, 'PUT', { length_mm: val('length_mm'), width_mm: val('width_mm'), height_mm: val('height_mm'),
                tare_g: val('tare_g'), max_load_kg: val('max_load_kg'), pallet_max_height_mm: val('pallet_max_height_mm'), pallet_max_kg: val('pallet_max_kg'),
                kind: f.kind.value || null, note: f.note.value, active: f.active.checked });
            btn.disabled = false;
            f.querySelectorAll('.is-invalid').forEach(x => x.classList.remove('is-invalid'));
            if (!r.ok) { at.notify(false, r.message || 'Błąd'); return; }
            at.notify(true, r.message);
            await reopen();
        });
        // Produkty : edycja w wierszu (zmiana → przycisk Zapisz), « zastosuj » = układ, który się mieści (wysokość i waga palety).
        const prow = (pid) => m.box.querySelector(`[data-prow="${pid}"]`);
        const intOf = (input) => { const v = input.value.replace(/\s/g, '').replace(',', '.'); return v === '' ? null : Number(v); };
        const refreshRow = (tr) => {
            const p = c.products.find(x => x.id === Number(tr.dataset.prow));
            const ppc = intOf(tr.querySelector('[name=ppc]')), w = intOf(tr.querySelector('[name=weight]'));
            const cpl = intOf(tr.querySelector('[name=cpl]')), lpp = intOf(tr.querySelector('[name=lpp]'));
            const layoutChanged = cpl !== (p.erp.cpl || null) || lpp !== (p.erp.lpp || null);
            tr.querySelector('[data-cpp]').textContent = layoutChanged ? (cpl && lpp ? String(cpl * lpp) : '—') : String(p.erp.cpp || '—');
            if (ppc && w) {
                const net = ppc * w / 1000;
                tr.querySelector('[data-net]').innerHTML = num(net, 2) + ' kg' + (c.max_load_kg !== null && net > c.max_load_kg ? '<small>> maks. ładunek</small>' : '');
                tr.querySelector('[data-gross]').textContent = num(net + (c.tare_g || 0) / 1000, 2) + ' kg';
            }
            const changed = ppc !== p.ppc || w !== p.weight_g || cpl !== (p.erp.cpl || null) || lpp !== (p.erp.lpp || null);
            tr.querySelector('[data-psave]').disabled = !changed;
            tr.classList.toggle('ct-dirty', changed);
        };
        const bodyOf = (tr) => {
            const p = c.products.find(x => x.id === Number(tr.dataset.prow));
            const ppc = intOf(tr.querySelector('[name=ppc]')), w = intOf(tr.querySelector('[name=weight]'));
            const cpl = intOf(tr.querySelector('[name=cpl]')), lpp = intOf(tr.querySelector('[name=lpp]'));
            const body = {};
            if (ppc !== p.ppc) body.ppc = ppc;
            if (w !== p.weight_g) body.weight = w;
            if (cpl !== (p.erp.cpl || null) || lpp !== (p.erp.lpp || null)) { body.cpl = cpl ?? 0; body.lpp = lpp ?? 0; body.cpp = (cpl || 0) * (lpp || 0); }
            return body;
        };
        const save = async (p, body) => (await api()).put(`/produits/items/${p.item_id}/logistics`, body);
        m.box.querySelectorAll('[data-prow]').forEach(tr => tr.querySelectorAll('.ct-in').forEach(i => i.addEventListener('input', () => refreshRow(tr))));
        m.box.querySelectorAll('[data-fit]').forEach(b => b.addEventListener('click', () => {
            const p = c.products.find(x => x.id === Number(b.dataset.fit));
            const tr = prow(p.id);
            tr.querySelector('[name=cpl]').value = p.best.per_layer;
            tr.querySelector('[name=lpp]').value = p.best.layers;
            refreshRow(tr);
        }));
        m.box.querySelectorAll('[data-psave]').forEach(b => b.addEventListener('click', async () => {
            const tr = prow(Number(b.dataset.psave));
            const p = c.products.find(x => x.id === Number(b.dataset.psave));
            b.disabled = true;
            try {
                await save(p, bodyOf(tr));
                at.notify(true, `Zapisano : ${p.name}`);
                await reopen();
            } catch (err) { at.notify(false, `${p.name} : ${apiErr(err)}`); b.disabled = false; }
        }));
        m.box.querySelector('[data-fit-all]')?.addEventListener('click', async (e) => {
            if (!window.confirm(`Ustawić układ palety, który się mieści (wysokość i waga palety), dla ${badFix.length} produktów ? Kartony na warstwę × warstwy = kartony na paletę w karcie produktu.`)) return;
            e.currentTarget.disabled = true;
            let ok = 0;
            const errs = [];
            for (const p of badFix) {
                try { await save(p, { cpl: p.best.per_layer, lpp: p.best.layers, cpp: p.best.cartons }); ok++; } catch (err) { errs.push(`${p.name} : ${apiErr(err)}`); }
            }
            at.notify(!errs.length, `Układ palety zapisany : ${ok} produktów.` + (errs.length ? ' Błędy : ' + errs.join(' · ') : ''));
            await reopen();
        });
        showPlan();
    }

    render();
    const open = Number(new URLSearchParams(location.search).get('id'));
    if (open) openCarton(open);
})();
