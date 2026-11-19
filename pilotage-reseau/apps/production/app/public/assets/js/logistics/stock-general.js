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
})();
