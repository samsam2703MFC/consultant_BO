/*
 * Wartości odżywcze z internetu (Open Food Facts) : propozycja dla jednego surowca (kategorie pasujące do nazwy,
 * mediana produktów kategorii, wartości do zaznaczenia) albo dla wszystkich surowców bez wartości (energia 0 lub 1),
 * z zatwierdzeniem przed zapisem. Limit OFF : 10 wyszukiwań na minutę — przerwa po każdym zapytaniu do OFF.
 */
(() => {
    'use strict';
    const modalEl = document.getElementById('nutriModal');
    if (!modalEl) return;
    const root = modalEl.dataset.root || '';
    const titleEl = modalEl.querySelector('[data-nutri-title]');
    const body = modalEl.querySelector('[data-nutri-body]');
    const foot = modalEl.querySelector('[data-nutri-foot]');
    const FIELDS = [
        ['energy_100g', 'Energia', 'kcal', 0], ['fat_100g', 'Tłuszcz', 'g', 1], ['saturated_fat_100g', 'Kwasy nasycone', 'g', 1], ['carbohydrates_100g', 'Węglowodany', 'g', 1],
        ['sugars_100g', 'Cukry', 'g', 1], ['fiber_100g', 'Błonnik', 'g', 1], ['proteins_100g', 'Białko', 'g', 1], ['salt_100g', 'Sól', 'g', 2],
    ];
    const OFF_GAP_MS = 6500;
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const num = (v, d) => v === null || v === undefined ? '—' : Number(v).toLocaleString('pl-PL', {maximumFractionDigits: d});
    const notify = (ok, m) => (typeof showSnack === 'function' ? showSnack(ok, m) : window.alert(m));
    const sleep = (ms) => new Promise((r) => window.setTimeout(r, ms));
    const spinner = (text) => `<p class="text-muted"><span class="spinner-border spinner-border-sm me-2"></span>${esc(text)}</p>`;
    const modal = () => bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
    let run = 0; // przerwanie listy po zamknięciu okna

    async function api(url, options = {}) {
        const res = await fetch(root + url, {credentials: 'same-origin', headers: {'Accept': 'application/json', 'Content-Type': 'application/json'}, ...options});
        const payload = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(payload.message || 'Błąd serwera.');
        return payload.data;
    }
    const lookup = (id, q, tag) => {
        const params = new URLSearchParams();
        if (q) params.set('q', q);
        if (tag) params.set('tag', tag);
        return api(`/ajax/materials/${id}/nutritional-values/lookup?${params}`);
    };
    const apply = (id, tag, values) => api(`/ajax/materials/${id}/nutritional-values/apply`, {method: 'POST', body: JSON.stringify({tag, values})});
    const picked = (chosen, only) => Object.fromEntries(FIELDS.map(([f]) => f).filter((f) => chosen.values[f] !== null && (!only || only.includes(f))).map((f) => [f, chosen.values[f]]));

    // Tabela strony po zapisie : nowe wartości i źródło.
    function updateRow(material, source) {
        document.querySelectorAll(`.nutritional-input[data-material-id="${material.id}"]`).forEach((input) => {
            const v = material[input.dataset.field];
            if (v === undefined) return;
            input.value = Number(v);
            input.classList.add('is-saved');
            window.setTimeout(() => input.classList.remove('is-saved'), 1500);
        });
        const src = document.querySelector(`[data-nutri-src="${material.id}"]`);
        if (src) src.innerHTML = source ? `<i class="bi bi-globe2"></i> Open Food Facts : ${esc(source.label)}` : '';
    }
    const rowName = (id) => document.querySelector(`tr[data-material-id="${id}"] [data-nutri-name]`)?.textContent.trim() || '';

    // --- Jeden surowiec ---------------------------------------------------------------------------------------------
    async function openOne(id, q, tag) {
        run++;
        titleEl.textContent = `Z internetu : ${rowName(id)}`;
        body.innerHTML = spinner('Szukam w Open Food Facts…');
        foot.innerHTML = '<button type="button" class="btn btn-light" data-bs-dismiss="modal">Zamknij</button>';
        modal().show();
        try {
            drawOne(await lookup(id, q, tag));
        } catch (e) {
            body.innerHTML = `<div class="alert alert-danger">${esc(e.message)}</div>`;
        }
    }

    function drawOne(d) {
        const c = d.chosen;
        const pills = d.candidates.map((k) => `<button type="button" class="btn btn-sm ${c && c.tag === k.tag ? 'btn-primary' : 'btn-outline-secondary'}" data-tag="${esc(k.tag)}">${esc(k.name)}${k.lang !== 'pl' ? ` <span class="opacity-75">· ${esc(k.lang)}</span>` : ''}</button>`).join('');
        body.innerHTML = `
            <form class="d-flex gap-2 mb-3" data-q>
                <input class="form-control" name="q" value="${esc(d.query)}" aria-label="Szukana nazwa" maxlength="120">
                <button class="btn btn-outline-primary text-nowrap" type="submit"><i class="bi bi-search"></i> Szukaj</button>
            </form>
            ${d.candidates.length ? `<div class="small text-muted mb-1">Kategorie Open Food Facts</div><div class="d-flex flex-wrap gap-2 mb-3">${pills}</div>`
                : '<p class="text-muted">Nie znaleziono kategorii — wpisz sam rodzaj surowca (np. « masło », « truskawki mrożone », « mąka pszenna »).</p>'}
            ${c ? `<p class="small mb-2">Mediana <strong>${c.sample}</strong> najczęściej skanowanych produktów kategorii
                    <a href="${esc(c.url)}" target="_blank" rel="noopener">${esc(c.name)}</a> (${num(c.products, 0)} produktów w Open Food Facts).</p>
                <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                    <thead><tr><th class="text-center">Zapisz</th><th>Na 100 g</th><th class="text-end">Teraz</th><th class="text-end">Open Food Facts</th></tr></thead>
                    <tbody>${FIELDS.map(([f, label, unit, dec]) => `<tr>
                        <td class="text-center"><input class="form-check-input" type="checkbox" data-f="${f}" ${c.values[f] === null ? 'disabled' : 'checked'} aria-label="${esc(label)}"></td>
                        <td>${esc(label)} <span class="text-muted">${unit}</span></td>
                        <td class="text-end">${num(d.material.values[f], dec)}</td>
                        <td class="text-end"><strong>${num(c.values[f], dec)}</strong></td></tr>`).join('')}</tbody>
                </table></div>` : (d.candidates.length ? '<p class="text-muted">Za mało produktów z wartościami w tej kategorii — wybierz inną.</p>' : '')}`;
        foot.innerHTML = c
            ? `<span class="small text-muted me-auto">Dane : Open Food Facts (licencja ODbL) — wartości typowe, sprawdź przed zapisem.</span>
               <button type="button" class="btn btn-light" data-bs-dismiss="modal">Anuluj</button><button type="button" class="btn btn-primary" data-save>Zapisz zaznaczone</button>`
            : '<button type="button" class="btn btn-light" data-bs-dismiss="modal">Zamknij</button>';
        const id = d.material.id;
        body.querySelector('[data-q]').addEventListener('submit', (e) => { e.preventDefault(); openOne(id, e.target.q.value.trim(), null); });
        body.querySelectorAll('[data-tag]').forEach((b) => b.addEventListener('click', () => openOne(id, d.query, b.dataset.tag)));
        foot.querySelector('[data-save]')?.addEventListener('click', async (e) => {
            const only = [...body.querySelectorAll('[data-f]:checked')].map((x) => x.dataset.f);
            if (!only.length) { notify(false, 'Zaznacz wartości do zapisania.'); return; }
            e.target.disabled = true;
            try {
                const r = await apply(id, c.tag, picked(c, only));
                updateRow(r.material, r.source);
                notify(true, 'Zapisano wartości z Open Food Facts.');
                modal().hide();
            } catch (err) {
                notify(false, err.message);
                e.target.disabled = false;
            }
        });
    }

    // --- Wszystkie bez wartości -------------------------------------------------------------------------------------
    function missingIds() {
        return [...document.querySelectorAll('#nutritional-table tbody tr[data-material-id]')].filter((tr) => {
            const energy = tr.querySelector('.nutritional-input[data-field="energy_100g"]');
            return energy && Number(energy.value || 0) <= 1;
        }).map((tr) => Number(tr.dataset.materialId));
    }

    function openBulk() {
        run++;
        const ids = missingIds();
        titleEl.textContent = 'Uzupełnij z internetu (Open Food Facts)';
        modal().show();
        if (!ids.length) {
            body.innerHTML = '<p class="text-muted mb-0">Wszystkie surowce mają wartość energii.</p>';
            foot.innerHTML = '<button type="button" class="btn btn-light" data-bs-dismiss="modal">Zamknij</button>';
            return;
        }
        body.innerHTML = `<p class="mb-2"><strong>${ids.length}</strong> surowców bez wartości (energia 0 lub 1). Dla każdego szukam kategorii Open Food Facts
                pasującej do nazwy i mediany produktów ; pewne dopasowania są zaznaczone, pozostałe sprawdź (albo szukaj ręcznie ikoną <i class="bi bi-globe2"></i> w tabeli).</p>
            <p class="small text-muted mb-3" data-progress>Open Food Facts pozwala na 10 wyszukiwań na minutę : około ${Math.ceil(ids.length * OFF_GAP_MS / 60000)} min przy pierwszym przejściu (kategorie już pobrane — od razu).</p>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                <thead><tr><th class="text-center">Zapisz</th><th>Surowiec</th><th>Kategoria Open Food Facts</th>${FIELDS.map(([, l, u]) => `<th class="text-end">${esc(l)}<div class="small text-muted">${u}</div></th>`).join('')}</tr></thead>
                <tbody>${ids.map((id) => `<tr data-bulk="${id}"><td class="text-center"><input class="form-check-input" type="checkbox" disabled aria-label="Zapisz"></td>
                    <td>${esc(rowName(id))}</td><td class="text-muted small" colspan="${FIELDS.length + 1}">czeka…</td></tr>`).join('')}</tbody>
            </table></div>`;
        foot.innerHTML = `<button type="button" class="btn btn-light" data-bs-dismiss="modal">Zamknij</button>
            <button type="button" class="btn btn-outline-secondary" data-stop>Zatrzymaj</button>
            <button type="button" class="btn btn-primary" data-save-bulk disabled>Zapisz zaznaczone</button>`;
        const results = new Map();
        const token = run;
        const saveBtn = foot.querySelector('[data-save-bulk]');
        const count = () => { const n = body.querySelectorAll('[data-bulk] input:checked').length; saveBtn.textContent = `Zapisz zaznaczone (${n})`; saveBtn.disabled = !n; };
        let stopped = false;
        foot.querySelector('[data-stop]').addEventListener('click', (e) => { stopped = true; e.target.disabled = true; });
        body.addEventListener('change', count);
        body.addEventListener('click', (e) => {
            const one = e.target.closest('[data-one]');
            if (one) { e.preventDefault(); openOne(Number(one.dataset.one)); }
        });
        saveBtn.addEventListener('click', async () => {
            saveBtn.disabled = true;
            let ok = 0;
            for (const tr of body.querySelectorAll('[data-bulk]')) {
                const box = tr.querySelector('input');
                const d = results.get(Number(tr.dataset.bulk));
                if (!box.checked || !d || !d.chosen) continue;
                try {
                    const r = await apply(d.material.id, d.chosen.tag, picked(d.chosen));
                    updateRow(r.material, r.source);
                    box.checked = false;
                    box.disabled = true;
                    tr.classList.add('table-success');
                    ok++;
                } catch (err) {
                    tr.classList.add('table-danger');
                    notify(false, `${d.material.name} : ${err.message}`);
                }
            }
            notify(true, `Zapisano wartości ${ok} surowców.`);
            count();
        });
        (async () => {
            const progress = body.querySelector('[data-progress]');
            let done = 0;
            let failures = 0;
            for (const id of ids) {
                if (token !== run || stopped) break;
                const tr = body.querySelector(`[data-bulk="${id}"]`);
                tr.cells[2].textContent = 'szukam…';
                let d = null;
                try {
                    d = await lookup(id);
                    failures = 0;
                } catch (e) {
                    tr.cells[2].innerHTML = `<span class="text-danger">${esc(e.message)}</span>`;
                    // OFF przeciążone : przerwa jak po zapytaniu (minuta przy « za dużo zapytań »), po 3 błędach z rzędu koniec.
                    if (++failures >= 3) { stopped = 'off'; done++; break; }
                    await sleep(/za dużo/i.test(e.message) ? 60000 : OFF_GAP_MS);
                }
                done++;
                if (d) {
                    results.set(id, d);
                    const c = d.chosen;
                    const box = tr.querySelector('input');
                    while (tr.cells.length > 3) tr.deleteCell(3);
                    if (c) {
                        tr.cells[2].innerHTML = `<a href="${esc(c.url)}" target="_blank" rel="noopener">${esc(c.name)}</a> <span class="text-muted">· ${c.sample} prod.</span>
                            ${d.confident ? '' : '<div><span class="badge bg-warning text-dark">do sprawdzenia</span></div>'}`;
                        tr.cells[2].removeAttribute('colspan');
                        FIELDS.forEach(([f, , , dec]) => { const td = tr.insertCell(); td.className = 'text-end'; td.textContent = num(c.values[f], dec); });
                        box.disabled = false;
                        box.checked = d.confident;
                    } else {
                        tr.cells[2].innerHTML = `nie znaleziono · <a href="#" data-one="${id}">szukaj ręcznie</a>`;
                    }
                    count();
                }
                progress.textContent = `Sprawdzono ${done} z ${ids.length}.`;
                if (d && d.fetched && done < ids.length) await sleep(OFF_GAP_MS);
            }
            if (token === run) {
                progress.textContent = stopped === 'off'
                    ? `Open Food Facts nie odpowiada — przerwano po ${done} z ${ids.length}. Zapisz znalezione i spróbuj później (kategorie już pobrane pójdą od razu).`
                    : `Sprawdzono ${done} z ${ids.length}. Zaznaczone : pewne dopasowania — sprawdź pozostałe przed zapisem.`;
            }
            foot.querySelector('[data-stop]')?.setAttribute('disabled', '');
        })();
    }

    modalEl.addEventListener('hidden.bs.modal', () => { run++; });
    document.querySelector('[data-nutri-bulk]')?.addEventListener('click', openBulk);
    document.addEventListener('click', (e) => {
        const b = e.target.closest('[data-nutri-lookup]');
        if (b) openOne(Number(b.dataset.nutriLookup));
    });
})();
