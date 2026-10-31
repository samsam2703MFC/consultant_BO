/*
 * Podwyżka ceny surowca na fakturze [210] : okno « nowa cena bazowa ? » — cena z faktury wyższa niż cena bazowa (kartoteka,
 * kalkulacja receptur). Na surowiec : « Nowa cena bazowa » (zaznaczone) albo « Bez zmian » ; zapis POST …/price-decisions,
 * potem dalszy krok (zatwierdzenie faktury, import cen) w miejscu wywołania. Wspólne dla Zaopatrzenia i Finansów.
 * open() → szczegóły faktury po zapisie albo null (anulowane / nic do decyzji). Etykiety pu.* i inv.price_up.* w supply.json.
 */
(function () {
    'use strict';
    const {ui} = window.TfbWorkshop;
    const esc = ui.esc;
    const errText = (err) => ((err.body && err.body.errors) || []).map(x => x.message).join(' ') || err.message;

    /** Błąd API « podwyżka bez decyzji » (INVOICE_PRICE_UP_PENDING). */
    const isPending = (err) => !!(err && err.body && err.body.description === 'INVOICE_PRICE_UP_PENDING');

    /**
     * @param {{api: object, invoiceId: number, materialIds?: number[], next?: string}} o
     *   materialIds : tylko te surowce (np. jeden wiersz) ; next : etykieta dalszego kroku pod przyciskiem (np. zatwierdzenie faktury).
     */
    async function open({api, invoiceId, materialIds = null, next = ''}) {
        const t = (k, p) => api.t(k, p);
        let d;
        try {
            d = await api.get(`/supply/invoices/${invoiceId}`);
        } catch (err) {
            ui.notify(false, errText(err));
            return null;
        }
        const ups = (d.price_ups || []).filter(u => !u.decision && (!materialIds || materialIds.includes(u.raw_material_id)));
        if (!ups.length) { ui.notify(true, t('pu.none')); return d; }
        const f = (v, n = 2) => ui.fmt(v, n, api.lang);
        // Ceny i kwoty zawsze z 2 miejscami (5,00 zł/kg), wyrównane.
        const f2 = (v) => Number(v).toLocaleString(api.lang === 'fr' ? 'fr-FR' : 'pl-PL', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        const price = (u, p) => `${f2(p * u.factor)} zł/${u.unit}`;
        const qty = (u) => `${f(u.quantity / u.factor, u.factor > 1 ? 2 : 0)} ${u.unit}`;
        const seller = d.supplier ? d.supplier.name : (d.seller_name || '—');
        const host = document.createElement('div');
        host.innerHTML = `<div class="modal fade" tabindex="-1" role="dialog" aria-labelledby="tfbPuTitle"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content tfb-pu">
            <div class="modal-header"><h5 class="modal-title" id="tfbPuTitle"><i class="bi bi-graph-up-arrow text-danger"></i> ${esc(t('pu.title', {n: ups.length}))}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="${esc(t('pu.cancel'))}"></button></div>
            <div class="modal-body">
                <p class="small text-muted mb-1">${esc(seller)} · ${esc(t('pu.invoice', {number: d.number || '#' + d.id}))}</p>
                <p class="mb-3">${esc(t('pu.lead'))}</p>
                <div class="tfb-pu-list">${ups.map(u => `<div class="tfb-pu-item" data-pu-item="${u.raw_material_id}">
                    <div class="tfb-pu-name"><strong>${esc(u.name)}</strong><small>${esc(t('inv.price_up.recipes', {n: u.recipes}))} · ${esc(t('pu.qty', {q: qty(u)}))}</small></div>
                    <div class="tfb-pu-prices">
                        <span><small>${esc(t('inv.price_up.base'))}</small><b>${esc(price(u, u.base_price))}</b></span>
                        <i class="bi bi-arrow-right" aria-hidden="true"></i>
                        <span><small>${esc(t('inv.price_up.new'))}</small><b class="text-danger">${esc(price(u, u.new_price))}</b></span>
                        <span class="tfb-pu-badge">+${esc(f(u.change_pct, 1))} %</span>
                        <span><small>${esc(t('inv.price_up.extra'))}</small><b>+${esc(f2(u.extra))} zł</b></span>
                    </div>
                    <div class="tfb-pu-choice" role="radiogroup" aria-label="${esc(t('inv.price_up.decision'))} ${esc(u.name)}">
                        <label><input type="radio" name="pu-${u.raw_material_id}" value="accept" checked> <span><i class="bi bi-check2"></i> ${esc(t('inv.price_up.accept'))}</span></label>
                        <label><input type="radio" name="pu-${u.raw_material_id}" value="keep"> <span>${esc(t('inv.price_up.keep'))}</span></label>
                    </div></div>`).join('')}</div>
                <p class="small text-muted mt-3 mb-0">${esc(t('pu.help'))}</p>
            </div>
            <div class="modal-footer"><span class="small text-muted me-auto" data-pu-next>${esc(next)}</span>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">${esc(t('pu.cancel'))}</button>
                <button type="button" class="btn btn-danger" data-pu-submit></button></div>
        </div></div></div>`;
        const modalEl = host.firstElementChild;
        document.body.appendChild(modalEl);
        const modal = new window.bootstrap.Modal(modalEl);
        const submit = modalEl.querySelector('[data-pu-submit]');
        const choices = () => ups.map(u => ({id: u.raw_material_id, decision: modalEl.querySelector(`input[name="pu-${u.raw_material_id}"]:checked`).value}));
        const refresh = () => {
            const c = choices();
            const acc = c.filter(x => x.decision === 'accept').length;
            submit.textContent = acc === c.length ? t('pu.submit_accept', {n: acc}) : acc === 0 ? t('pu.submit_keep', {n: c.length}) : t('pu.submit_mixed', {a: acc, k: c.length - acc});
            submit.className = 'btn ' + (acc ? 'btn-danger' : 'btn-secondary');
        };
        modalEl.querySelectorAll('.tfb-pu-choice input').forEach(x => x.addEventListener('change', refresh));
        refresh();
        let result = null;
        const done = new Promise(resolve => modalEl.addEventListener('hidden.bs.modal', () => { modal.dispose(); modalEl.remove(); resolve(result); }));
        submit.addEventListener('click', async () => {
            submit.disabled = true;
            const c = choices();
            try {
                let res = null;
                for (const decision of ['accept', 'keep']) {
                    const ids = c.filter(x => x.decision === decision).map(x => x.id);
                    if (ids.length) res = await api.post(`/supply/invoices/${d.id}/price-decisions`, {decision, raw_material_ids: ids});
                }
                const acc = c.filter(x => x.decision === 'accept').length;
                ui.notify(true, [acc ? t('inv.price_up.accept_ok', {n: acc}) : '', c.length - acc ? t('inv.price_up.keep_ok', {n: c.length - acc}) : ''].filter(Boolean).join(' '));
                result = res;
                modal.hide();
            } catch (err) {
                ui.notify(false, errText(err));
                submit.disabled = false;
            }
        });
        modal.show();
        return done;
    }

    window.TfbInvoicePriceUp = {open, isPending};
})();
