/*
 * Paleta mieszana [215] (Kartony i palety › Paleta mieszana, makieta 214) : pełne warstwy kilku produktów klienta jedna na
 * drugiej. Edytor : warstwy − / + (najwyżej zamówione), « Dodaj produkt klienta », kafelki (wysokość, waga, kartony, miejsca
 * w ciężarówce), kontrole, widok z boku i z góry ; serwer liczy każdą zmianę (podgląd bez zapisu). Zatwierdzenie, etykieta
 * PDF, propozycje dnia wysyłki.
 */
(function () {
    'use strict';
    const app = document.getElementById('mixedApp');
    if (!app) return;
    const root = app.dataset.root;
    let D = JSON.parse(document.getElementById('mixedBoot').textContent);
    let items = {};
    let dirty = false;
    const COLORS = ['#c9a36b', '#e6cf9f', '#b9c7a3', '#a9bfd6', '#d9a7a0', '#cdb8de'];
    const DAYS = ['nd', 'pn', 'wt', 'śr', 'czw', 'pt', 'sob'];
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const num = (v, d = 0) => v === null || v === undefined ? '—' : Number(v).toLocaleString('pl-PL', {minimumFractionDigits: d, maximumFractionDigits: d});
    const pl = (n, one, few, many) => n === 1 ? one : ([2, 3, 4].includes(n % 10) && ![12, 13, 14].includes(n % 100) ? few : many);
    const day = (d) => { const t = new Date(d + 'T12:00:00'); return `${DAYS[t.getDay()]} ${t.getDate()}.${String(t.getMonth() + 1).padStart(2, '0')}`; };
    const words = {2: 'dwóch', 3: 'trzech', 4: 'czterech', 5: 'pięciu', 6: 'sześciu'};
    const itemsParam = (map) => Object.entries(map).filter(([, n]) => n > 0).map(([p, n]) => `${p}:${n}`).join(',');

    async function call(method, url, body) {
        const res = await fetch(root + url, {method, credentials: 'same-origin',
            headers: {'Accept': 'application/json', 'Content-Type': 'application/json'}, body: body ? JSON.stringify(body) : undefined});
        const data = await res.json().catch(() => ({message: 'Błąd serwera (' + res.status + ').'}));
        if (!res.ok) throw new Error(data.message || 'Błąd serwera.');
        return data;
    }
    const fail = (err) => window.Swal ? Swal.fire({icon: 'error', title: 'Nie wykonano', text: err.message}) : alert(err.message);

    // --- Pasek : dzień wysyłki, klient ------------------------------------------------------------------
    function renderBar() {
        const dSel = app.querySelector('[data-mx-date]');
        const dates = (D.dates || []).map((d) => d.ship_date);
        if (D.date && !dates.includes(D.date)) dates.unshift(D.date);
        dSel.innerHTML = dates.map((d) => {
            const r = (D.dates || []).find((x) => x.ship_date === d);
            return `<option value="${d}" ${d === D.date ? 'selected' : ''}>${day(d)}.${d.slice(0, 4)}${r ? ' · ' + r.customers + ' ' + pl(Number(r.customers), 'klient', 'klientów', 'klientów') : ''}</option>`;
        }).join('');
        const cSel = app.querySelector('[data-mx-customer]');
        const cur = D.editor ? D.editor.customer.id : null;
        cSel.innerHTML = '<option value="">— wybierz klienta —</option>' + (D.customers || []).map((c) =>
            `<option value="${c.id}" ${c.id === cur ? 'selected' : ''}>${esc(c.name)}${c.town ? ' · ' + esc(c.town) : ''}</option>`).join('');
    }
    app.querySelector('[data-mx-date]').addEventListener('change', (e) => { location.search = '?date=' + e.target.value; });
    app.querySelector('[data-mx-customer]').addEventListener('change', (e) => {
        location.search = '?date=' + D.date + (e.target.value ? '&customer=' + e.target.value : '');
    });

    // --- Widoki palety ----------------------------------------------------------------------------------
    function side(E) {
        const W = 360, x0 = 70, y0 = 520, room = 470;
        const scale = room / Math.max(E.max_height_mm, E.height_mm, 1);
        let y = y0 - E.base_mm * scale;
        let out = `<rect x="${x0}" y="${y}" width="${W}" height="${E.base_mm * scale}" fill="#b8b0a4"/>`;
        [10, W / 2 - 30, W - 70].forEach((dx) => { out += `<rect x="${x0 + dx}" y="${y + 6}" width="60" height="${Math.max(2, E.base_mm * scale - 10)}" fill="#fff" opacity=".7"/>`; });
        E.groups.forEach((g, i) => {
            const start = y;
            const along = Math.max(1, Math.min(4, Math.round(1200 / Math.max(g.length_mm, 1))));
            for (let k = 0; k < g.layers; k++) {
                y -= g.height_mm * scale;
                for (let c = 0; c < along; c++) out += `<rect x="${x0 + c * W / along + 1}" y="${y + 1}" width="${W / along - 2}" height="${Math.max(1, g.height_mm * scale - 2)}" rx="2" fill="${COLORS[i % COLORS.length]}" stroke="#8a6a3a" stroke-width=".8"/>`;
            }
            out += `<line x1="${x0 + W + 10}" x2="${x0 + W + 10}" y1="${start}" y2="${y}" stroke="#555" stroke-width="1.5"/>`
                + `<text x="${x0 + W + 18}" y="${(start + y) / 2 + 4}" font-size="13" font-weight="700">${g.position}. ${g.layers} ${pl(g.layers, 'warstwa', 'warstwy', 'warstw')} · ${num(g.height)} mm</text>`;
        });
        const ymax = y0 - E.max_height_mm * scale;
        out += `<line x1="${x0 - 20}" x2="${x0 + W + 200}" y1="${ymax}" y2="${ymax}" stroke="#9a1730" stroke-dasharray="6 4" stroke-width="1.5"/>`
            + `<text x="${x0 - 20}" y="${ymax - 6}" font-size="12" fill="#9a1730" font-weight="700">maks. ${num(E.max_height_mm)} mm</text>`
            + `<text x="${x0 + W / 2}" y="${y - 8}" font-size="13" font-weight="700" text-anchor="middle">${num(E.height_mm)} mm</text>`;
        for (let m = 0; m * scale <= room - 20; m += 500) out += `<text x="${x0 - 48}" y="${y0 - m * scale + 4}" font-size="11" fill="#777">${num(m / 1000, 1)} m</text>`;
        return `<svg class="mx-side" viewBox="0 0 640 540" role="img" aria-label="Paleta mieszana z boku">${out}</svg>`;
    }
    const top = (g, i) => `<svg viewBox="0 0 240 160" width="240" height="160" role="img" aria-label="Warstwa ${g.position} z góry"><rect x="0" y="0" width="240" height="160" fill="none" stroke="#999"/>
        ${g.rects.map((r) => `<rect x="${r.x * 0.2 + 1}" y="${r.y * 0.2 + 1}" width="${Math.max(1, r.w * 0.2 - 2)}" height="${Math.max(1, r.h * 0.2 - 2)}" rx="3" fill="${COLORS[i % COLORS.length]}" stroke="#8a6a3a"/>`).join('')}</svg>`;

    // --- Edytor -----------------------------------------------------------------------------------------
    function renderEditor() {
        const box = app.querySelector('[data-mx-editor]');
        const E = D.editor;
        if (!E) {
            box.innerHTML = `<div class="mx-card"><h5>Paleta mieszana</h5><p class="mx-lead mb-0">Wybierz klienta dnia albo propozycję poniżej, żeby ułożyć pełne warstwy kilku produktów na jednej palecie.</p></div>`;
            return;
        }
        items = Object.fromEntries(E.groups.map((g) => [g.product_id, g.cartons]));
        const n = E.groups.length;
        const P = E.places;
        const lead = `Zamówienie klienta ${esc(E.customer.name)} na ${day(D.date)}`
            + (P.after < P.now ? ` : zamiast ${words[P.now - P.after + 1] || (P.now - P.after + 1)} niepełnych palet — jedna paleta EUR.` : ' : jedna paleta EUR.')
            + ' Każdy produkt zajmuje całe warstwy ; kolejność ustawiana sama (cięższa warstwa na dole).';
        const rows = E.groups.map((g, i) => `<div class="mx-layer"><span class="no" style="background:${COLORS[i % COLORS.length]}">${g.position}</span>
            <div><b>${esc(g.name)}</b><small>${esc(g.carton || 'karton')} · ${g.per_layer} na warstwę · ${num(g.carton_kg, 2)} kg / karton</small>
              <div class="mx-nums"><span>Kartony <b>${num(g.cartons)}</b> / zamówione ${num(g.ordered)}${g.available < g.ordered ? ` (wolne ${num(g.available)})` : ''}</span><span>Wysokość <b>${num(g.height)} mm</b></span>
                <span>Waga <b>${num(g.kg, 1)} kg</b></span><span>Warstwa <b>${num(g.layer_kg, 1)} kg</b></span></div>
              ${g.partial ? `<small class="mx-note">Ostatnia warstwa niepełna : ${g.partial} z ${g.per_layer} kartonów</small>` : ''}
              ${g.full_pallets ? `<small class="mx-note">Reszta zamówienia : ${g.full_pallets} ${pl(g.full_pallets, 'pełna paleta', 'pełne palety', 'pełnych palet')} osobno (${g.cpp} kart. / paleta)</small>` : ''}</div>
            <div class="mx-step" role="group" aria-label="Warstwy ${esc(g.name)}"><button type="button" data-mx-step="${g.product_id}" data-d="-1" aria-label="Mniej warstw">−</button><span class="v">${g.layers}</span>
              <button type="button" data-mx-step="${g.product_id}" data-d="1" aria-label="Więcej warstw" ${g.cartons >= g.available ? 'disabled' : ''}>+</button></div></div>`).join('');
        const usable = E.options.filter((o) => !o.reason);
        const add = E.options.length ? `<details class="mx-add"><summary>+ Dodaj produkt klienta (pełne warstwy)${usable[0] ? ` — np. ${esc(usable[0].name)}${usable[0].carton ? ' · ' + esc(usable[0].carton) : ''} · ${usable[0].per_layer} na warstwę` : ''}</summary>
            <div class="mx-opts">${E.options.map((o) => `<button type="button" class="mx-opt" data-mx-add="${o.product_id}" data-n="${o.leftover > 0 ? o.leftover : Math.min(o.per_layer, o.available)}" ${o.reason ? 'disabled' : ''}>
                <b>${esc(o.name)}</b><small>${o.reason ? esc(o.reason) : `${esc(o.carton || '')} · ${o.per_layer} na warstwę · reszta ${num(o.leftover)} kart. (zamówione ${num(o.available)})`}</small></button>`).join('')}</div></details>` : '';
        const ok = (c) => ({ok: '✓', warn: '⚠', info: '⚠', error: '✕'}[c.status]);
        const checks = E.checks.map((c) => `<span class="is-${c.status}">${ok(c)} ${esc(c.text)}</span>`).join('');
        const head = n ? `Paleta mieszana : pełne warstwy ${words[n] || n} ${n === 1 ? 'produktu' : 'produktów'}, jedna na drugiej` : 'Paleta mieszana';
        const conf = E.confirmed ? `<span class="mx-badge is-ok">Zatwierdzona ${new Date(E.confirmed.at.replace(' ', 'T')).toLocaleString('pl-PL', {day: 'numeric', month: '2-digit', hour: '2-digit', minute: '2-digit'})}${E.confirmed.who ? ' · ' + esc(E.confirmed.who) : ''}</span>` : '';
        box.innerHTML = `<div class="mx-card"><h5>${head} ${conf}</h5><p class="mx-lead">${lead}</p>
            <div class="mx-grid"><div>
                ${rows || '<p class="at-empty">Brak produktów na palecie — dodaj produkty klienta.</p>'}
                ${add}
                <div class="mx-tiles"><div><span>Wysokość palety</span><b>${n ? num(E.height_mm) + ' mm' : '—'}</b></div><div><span>Waga palety</span><b>${n ? num(E.kg) + ' kg' : '—'}</b></div>
                  <div><span>Kartony</span><b>${num(E.cartons)}</b></div><div><span>Miejsca w ciężarówce</span><b>${num(P.after)} <small class="${P.after < P.now ? 'is-ok' : ''}">${P.after < P.now ? `(było ${P.now})` : '(bez zmian)'}</small></b></div></div>
                <div class="mx-chk">${checks}</div>
              </div>
              <div>${n ? `<div class="mx-tops">${side(E)}</div><div class="mx-tops">${E.groups.map((g, i) => `<div>${top(g, i)}<small>warstwa ${g.position} z góry · ${g.per_layer} ${pl(g.per_layer, 'karton', 'kartony', 'kartonów')}</small></div>`).join('')}</div>` : ''}</div></div>
            <div class="mx-foot"><span class="mx-lead m-0">Etykieta palety : lista produktów i warstw (od dołu) dla magazynu, kierowcy i klienta ; na tablecie pakowania kolejność układania.</span>
              <span class="mx-actions">${E.pallet_id ? `<button type="button" class="mx-btn" data-mx-delete>Usuń paletę mieszaną</button>` : ''}
                <button type="button" class="mx-btn" data-mx-label ${n ? '' : 'disabled'}><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> Etykieta palety (PDF)</button>
                <button type="button" class="mx-btn pri" data-mx-confirm ${E.ok && (!E.pallet_id || dirty) ? '' : 'disabled'}>${E.pallet_id ? 'Zapisz zmiany palety' : 'Zatwierdź paletę mieszaną'}</button></span></div></div>`;
    }

    async function preview() {
        const E = D.editor;
        try {
            const q = new URLSearchParams({date: D.date, customer: E.customer.id, items: itemsParam(items)});
            if (E.pallet_id) q.set('pallet', E.pallet_id);
            const res = await call('GET', '/ajax/products/cartons/mixed/preview?' + q);
            D.editor = res.data;
            dirty = true;
            renderEditor();
        } catch (err) { fail(err); }
    }

    app.addEventListener('click', async (e) => {
        const step = e.target.closest('[data-mx-step]');
        if (step) {
            const g = D.editor.groups.find((x) => x.product_id === Number(step.dataset.mxStep));
            const layers = Math.max(0, g.layers + Number(step.dataset.d));
            items[g.product_id] = Math.min(layers * g.per_layer, g.available);
            return preview();
        }
        const add = e.target.closest('[data-mx-add]');
        if (add) { items[add.dataset.mxAdd] = Number(add.dataset.n); return preview(); }
        if (e.target.closest('[data-mx-label]')) {
            const E = D.editor;
            const url = E.pallet_id && !dirty ? `/products/cartons/mixed/label?pallet=${E.pallet_id}`
                : '/products/cartons/mixed/label?' + new URLSearchParams({date: D.date, customer: E.customer.id, items: itemsParam(items)});
            window.open(root + url, '_blank', 'noopener');
            return;
        }
        const confirmBtn = e.target.closest('[data-mx-confirm]');
        if (confirmBtn) {
            const E = D.editor;
            confirmBtn.disabled = true;
            try {
                const res = await call('POST', '/ajax/products/cartons/mixed', {date: D.date, customer_id: E.customer.id, pallet_id: E.pallet_id,
                    items: Object.entries(items).filter(([, n]) => n > 0).map(([p, n]) => ({product_id: Number(p), cartons: n}))});
                D = res.data;
                dirty = false;
                render();
                history.replaceState(null, '', `?date=${D.date}&customer=${D.editor.customer.id}&pallet=${D.editor.pallet_id}`);
                if (window.Swal) Swal.fire({icon: 'success', title: res.message, text: `Palety klienta w dniu wysyłki : ${D.editor.places.after}. Kolejność układania na etykiecie i na tablecie pakowania.`});
            } catch (err) { fail(err); confirmBtn.disabled = false; }
            return;
        }
        if (e.target.closest('[data-mx-delete]')) {
            const E = D.editor;
            const ok = window.Swal ? (await Swal.fire({icon: 'question', title: 'Usunąć paletę mieszaną ?', text: 'Kartony wrócą na zwykłe palety klienta w dniu wysyłki.',
                showCancelButton: true, confirmButtonText: 'Usuń', cancelButtonText: 'Anuluj', confirmButtonColor: '#8e1f2f'})).isConfirmed : confirm('Usunąć paletę mieszaną ?');
            if (!ok) return;
            try {
                const res = await call('DELETE', `/ajax/products/cartons/mixed/${E.pallet_id}`);
                D = res.data;
                dirty = false;
                render();
                history.replaceState(null, '', `?date=${D.date}&customer=${E.customer.id}`);
            } catch (err) { fail(err); }
            return;
        }
        const open = e.target.closest('[data-mx-open]');
        if (open) {
            const r = D.proposals[Number(open.dataset.mxOpen)];
            const q = new URLSearchParams({date: D.date, customer: r.customer_id});
            if (r.pallet_id) q.set('pallet', r.pallet_id); else q.set('items', itemsParam(r.items));
            location.search = '?' + q;
        }
    });

    // --- Propozycje dnia --------------------------------------------------------------------------------
    function renderProposals() {
        const box = app.querySelector('[data-mx-proposals]');
        const rows = (D.proposals || []).map((r, i) => `<tr>
            <td><b>${esc(r.customer)}</b>${r.confirmed ? ' <span class="mx-badge is-ok">zatwierdzona</span>' : ''}</td>
            <td>${r.products.map((p) => `${esc(p.name)} ${p.layers} w.`).join(' + ')}</td>
            <td class="n">${r.now}</td><td class="n"><b>${r.after}</b></td>
            <td class="n">${r.height_mm !== null ? num(r.height_mm) + ' mm' : '—'}</td><td class="n">${r.kg !== null ? num(r.kg) + ' kg' : '—'}</td>
            <td class="n"><button type="button" class="mx-btn" data-mx-open="${i}">Otwórz</button></td></tr>`).join('');
        box.innerHTML = `<div class="mx-card"><h5>Propozycje palet mieszanych na dzień wysyłki</h5><p class="mx-lead">Klienci z resztkami pełnych warstw kilku produktów — łączenie w jedną paletę oszczędza miejsca w ciężarówce.</p>
            ${rows ? `<div class="table-responsive"><table class="mx-sug"><thead><tr><th>Klient</th><th>Produkty (pełne warstwy)</th><th class="n">Palety teraz</th><th class="n">Po połączeniu</th><th class="n">Wysokość</th><th class="n">Waga</th><th></th></tr></thead>
            <tbody>${rows}</tbody></table></div>` : '<p class="at-empty mb-0">Brak propozycji na ten dzień : resztki klientów nie oszczędzą miejsca w ciężarówce albo produkty nie mają danych kartonu.</p>'}</div>`;
    }

    function render() {
        if (D.error) { app.querySelector('[data-mx-editor]').innerHTML = `<div class="alert alert-danger">${esc(D.error)}</div>`; return; }
        renderBar();
        renderEditor();
        renderProposals();
    }
    render();
})();
