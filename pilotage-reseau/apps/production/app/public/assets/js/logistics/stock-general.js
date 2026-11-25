/**
 * Stany i palety › Stan ogólny : tryb produktu (na zamówienie MTO / na stan MTS) i stan maks. w kartonach, zapisywane
 * na karcie produktu przez API kart (te same reguły i uprawnienia co strona Produkty : MTS wymaga stanu maks.).
 * Produkt bez karty : karta tworzona przy pierwszej zmianie. Po zapisie strona się odświeża (wskaźniki liczy serwer).
 */
(() => {
    const box = document.getElementById('spGeneral');
    if (!box || !window.TfbWorkshop) return;
    const {WorkshopApi, ui} = window.TfbWorkshop;
    const api = new WorkshopApi(box);
    const ready = api.init();

    const fail = (err) => {
        const fields = ((err && err.body && err.body.errors) || []).map(e => e.message).filter(Boolean);
        ui.notify(false, fields.length ? fields.join(' · ') : (err && err.message) || 'Błąd zapisu.');
    };
    const reload = () => { sessionStorage.setItem('spGeneralY', String(window.scrollY)); window.location.reload(); };
    const y = sessionStorage.getItem('spGeneralY');
    if (y !== null) { sessionStorage.removeItem('spGeneralY'); window.scrollTo(0, Number(y)); }

    async function card(tr) {
        if (tr.dataset.item) return Number(tr.dataset.item);
        const item = await api.post('/produits/items', {item_type: 'product', final_product_id: Number(tr.dataset.fp)});
        tr.dataset.item = String(item.id);
        return item.id;
    }
    async function save(tr, body, ok, controls) {
        controls.forEach(c => { c.disabled = true; });
        try {
            await ready;
            await api.patch(`/produits/items/${await card(tr)}/settings`, body);
            ui.notify(true, ok);
            reload();
        } catch (err) {
            fail(err);
            controls.forEach(c => { c.disabled = false; });
        }
    }
    const cartons = (input) => {
        const n = ui.num(input.value);
        return typeof n === 'number' && n > 0 ? Math.round(n) : null;
    };

    box.querySelectorAll('tr[data-gen-row]').forEach(tr => {
        const ppc = Number(tr.dataset.ppc) || 0;
        const name = tr.dataset.name;
        const buttons = [...tr.querySelectorAll('[data-gen-mode]')];
        buttons.forEach(b => b.addEventListener('click', () => {
            const mode = b.dataset.genMode;
            if (mode === tr.dataset.mode) return;
            if (mode === 'MTO') { save(tr, {mode: 'MTO'}, `${name} : na zamówienie.`, buttons); return; }
            // Na stan : stan maks. obowiązkowy — podpowiedź = sprzedaż z 6 tygodni na tydzień × 2 (do poprawienia).
            if (!ppc) { ui.notify(false, 'Brak liczby sztuk w kartonie — uzupełnij kartę produktu.'); return; }
            const hint = tr.dataset.max || Math.max(1, Math.ceil((Number(tr.dataset.week) || 0) * 2));
            const v = window.prompt(`${name}\nNa stan (MTS) : stan maksymalny w kartonach ?`, String(hint));
            if (v === null) return;
            const n = ui.num(v);
            if (typeof n !== 'number' || n <= 0) { ui.notify(false, 'Podaj liczbę kartonów większą od 0.'); return; }
            save(tr, {mode: 'MTS', stock_max: Math.round(n) * ppc}, `${name} : na stan, maks. ${Math.round(n)} kart.`, buttons);
        }));
        const max = tr.querySelector('[data-gen-max]');
        if (max) max.addEventListener('change', () => {
            const c = cartons(max);
            if (c === null) { ui.notify(false, 'Produkt na stan potrzebuje stanu maks. (kartony > 0).'); max.value = tr.dataset.max; return; }
            if (!ppc) { ui.notify(false, 'Brak liczby sztuk w kartonie — uzupełnij kartę produktu.'); return; }
            save(tr, {stock_max: c * ppc}, `${name} : maks. ${c} kart.`, [max, ...buttons]);
        });
    });

    // [430] Clic sur « Zamówione » : commandes ouvertes du produit — en retard (avant la semaine), cette semaine, plus tard.
    const esc = ui.esc || ((v) => String(v ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c])));
    const root = (box.dataset.root || '').replace(/\/$/, '');
    const day = (d) => d ? `${d.slice(8, 10)}.${d.slice(5, 7)}` : 'bez daty';
    const nb = (v) => String(Math.round(v * 100) / 100).replace('.', ',');
    let modal = null;
    async function orders(id) {
        if (!modal) {
            document.body.insertAdjacentHTML('beforeend', `<div class="modal fade" id="spOrders" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
                <div class="modal-header"><div><h5 class="modal-title" data-o-title></h5><div class="small text-muted" data-o-sub></div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button></div>
                <div class="modal-body" data-o-body></div></div></div></div>`);
            modal = new window.bootstrap.Modal(document.getElementById('spOrders'));
        }
        const m = document.getElementById('spOrders');
        const body = m.querySelector('[data-o-body]');
        m.querySelector('[data-o-title]').textContent = 'Zamówienia';
        m.querySelector('[data-o-sub]').textContent = '';
        body.innerHTML = '<div class="small text-muted">Wczytywanie…</div>';
        modal.show();
        try {
            const res = await fetch(`${root}/ajax/logistics/stock/orders/${id}`, {credentials: 'same-origin', headers: {'Accept': 'application/json'}});
            const o = await res.json();
            if (!res.ok) throw new Error((o && (o.message || (o.errors && o.errors[0]))) || `Błąd ${res.status}`);
            m.querySelector('[data-o-title]').textContent = `Zamówienia : ${o.name}`;
            m.querySelector('[data-o-sub]').textContent = `tydzień ${o.week_label} · ten tydzień ${nb(o.totals.week)} kart. · zaległe ${nb(o.totals.late)} · później ${nb(o.totals.later)}`;
            const group = (key, title, hint, cls) => {
                const rows = o.groups[key];
                if (!rows.length) return '';
                // [442] Zaległe : cases à cocher + fermeture en lot (wysłane / zarchiwizowane), avec confirmation.
                const pick = key === 'late';
                return `<div class="sp-ord-grp ${cls}">${esc(title)} · <b>${nb(o.totals[key])} kart.</b>${o.ppc ? ` (${nb(o.totals[key] * o.ppc)} szt.)` : ''} <span class="text-muted" style="text-transform:none;letter-spacing:0">— ${esc(hint)}</span></div>
                    <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr>${pick ? '<th style="width:28px"><input type="checkbox" class="form-check-input" data-late-all aria-label="Zaznacz wszystkie"></th>' : ''}<th>Klient</th><th>Wysyłka</th><th class="text-end">Kart.</th><th></th><th class="text-end">Zamówienie</th></tr></thead><tbody>
                    ${rows.map(r => `<tr>${pick ? `<td><input type="checkbox" class="form-check-input" data-late-id="${r.order_id}" aria-label="#${r.order_id}"></td>` : ''}<td><strong>${esc(r.customer || `Klient #${r.customer_id ?? '—'}`)}</strong>${r.reference ? `<div class="small text-muted">${esc(r.reference)}</div>` : ''}</td>
                        <td>${esc(day(r.due))}${r.late_days ? `<div class="small text-danger">${r.late_days} dni przed tygodniem</div>` : ''}</td><td class="text-end">${esc(nb(r.cartons))}</td>
                        <td>${r.freebie ? '<span class="badge bg-success">GRATIS</span> ' : ''}${r.supplement ? '<span class="badge bg-secondary">domówienie</span>' : ''}</td>
                        <td class="text-end"><a href="${root}/client-orders/${r.order_id}/edit" target="_blank" rel="noopener">#${r.order_id}</a></td></tr>`).join('')}</tbody></table></div>`
                    + (pick ? `<div class="d-flex flex-wrap gap-2 align-items-center my-2" data-late-bar><span class="small text-muted me-auto" data-late-count>Zaznacz zamówienia, które już wyjechały albo nie wyjadą.</span>
                        <button type="button" class="btn btn-sm btn-outline-primary" data-late-close="shipped" disabled><i class="bi bi-truck"></i> Oznacz jako wysłane</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-late-close="archived" disabled><i class="bi bi-archive"></i> Zarchiwizuj</button></div>` : '');
            };
            const html = group('late', 'Zaległe', 'data wysyłki przed tym tygodniem, nadal otwarte bez WDT : wysłane bez WDT albo do zamknięcia — nie liczą się w « Zamówione »', 'text-danger')
                + group('week', 'Ten tydzień', 'liczą się w « Zamówione » i w « Zostaje po wysyłce »', '')
                + group('later', 'Później', 'kolejne tygodnie albo bez daty — osobno', '');
            body.innerHTML = html || '<p class="text-muted mb-0">Brak otwartych zamówień tego produktu.</p>';
            lateBar(body);
        } catch (err) {
            body.innerHTML = `<div class="alert alert-danger mb-0">${esc(err.message)}</div>`;
        }
    }
    // [442] Fermeture en lot des commandes en retard : POST /ajax/logistics/stock/orders/close, puis la page se recalcule.
    function lateBar(body) {
        const bar = body.querySelector('[data-late-bar]');
        if (!bar) return;
        const boxes = () => [...body.querySelectorAll('[data-late-id]')];
        const picked = () => boxes().filter(b => b.checked).map(b => Number(b.dataset.lateId));
        const refresh = () => {
            const n = picked().length;
            bar.querySelectorAll('[data-late-close]').forEach(b => { b.disabled = n === 0; });
            bar.querySelector('[data-late-count]').textContent = n ? `Zaznaczone : ${n} zam.` : 'Zaznacz zamówienia, które już wyjechały albo nie wyjadą.';
        };
        const all = body.querySelector('[data-late-all]');
        if (all) all.addEventListener('change', () => { boxes().forEach(b => { b.checked = all.checked; }); refresh(); });
        boxes().forEach(b => b.addEventListener('change', refresh));
        bar.querySelectorAll('[data-late-close]').forEach(btn => btn.addEventListener('click', async () => {
            const ids = picked();
            const action = btn.dataset.lateClose;
            const what = action === 'archived' ? 'zarchiwizować (nie wyjadą)' : 'oznaczyć jako wysłane (wyjechały bez WDT)';
            if (!ids.length || !window.confirm(`Na pewno ${what} ${ids.length} zam. ?\nZamówienia znikną z « Zaległe » ; zmiana jest zapisana w historii zamówienia.`)) return;
            bar.querySelectorAll('button').forEach(b => { b.disabled = true; });
            try {
                const res = await fetch(`${root}/ajax/logistics/stock/orders/close`, {method: 'POST', credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json', 'Accept': 'application/json'}, body: JSON.stringify({ids, action})});
                const r = await res.json().catch(() => ({}));
                if (!res.ok) throw new Error((r && (r.message || (r.errors && (r.errors[0]?.message || r.errors[0])))) || `Błąd ${res.status}`);
                ui.notify(true, r.message || 'Zapisano.');
                reload();
            } catch (err) {
                ui.notify(false, err.message);
                refresh();
            }
        }));
    }
    box.querySelectorAll('[data-gen-orders]').forEach(b => b.addEventListener('click', () => orders(Number(b.dataset.genOrders))));
})();
