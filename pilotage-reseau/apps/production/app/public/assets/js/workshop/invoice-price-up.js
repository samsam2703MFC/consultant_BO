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
        // [257] Faktura źródłowa (KSeF / plik) w oknie : wszystkie pozycje, pozycje z podwyżką podświetlone, pobranie pliku.
        const lines = d.lines || [];
        const upIds = new Set(ups.map(u => u.raw_material_id));
        const linesOf = (u) => lines.filter(l => l.material && l.material.id === u.raw_material_id);
        const isKsef = d.source === 'ksef' || d.source === 'xml';
        const day = (s) => s ? (api.lang === 'fr' ? `${s.slice(8, 10)}/${s.slice(5, 7)}/${s.slice(0, 4)}` : `${s.slice(8, 10)}.${s.slice(5, 7)}.${s.slice(0, 4)}`) : '—';
        const money = (v) => v === null || v === undefined ? '—' : `${f2(v)} ${d.currency || 'PLN'}`;
        const unitPrice = (v) => v === null || v === undefined ? '—'
            : Number(v).toLocaleString(api.lang === 'fr' ? 'fr-FR' : 'pl-PL', {minimumFractionDigits: 2, maximumFractionDigits: 4});
        const vat = (v) => v === null || v === undefined || v === '' ? '—' : (/^\d+([.,]\d+)?$/.test(String(v)) ? `${v} %` : String(v));
        // Na stronie tej faktury w Zaopatrzeniu link « Otwórz » nic nie wnosi.
        const onSupplyInvoice = /\/workshop\/supply$/.test(location.pathname) && new RegExp(`inv=${d.id}(&|$)`).test(location.hash);
        const fromLine = (u) => linesOf(u).map(l => `<button type="button" class="tfb-pu-goto" data-pu-goto="${u.raw_material_id}">${esc(t('pu.from_line', {no: l.line_no, name: l.name}))}</button>`).join('');
        const invoiceView = `<section class="tfb-pu-inv" data-pu-inv hidden aria-label="${esc(t(isKsef ? 'pu.show_invoice_ksef' : 'pu.show_invoice'))}">
                <dl class="tfb-pu-inv-head">
                    <div><dt>${esc(t('inv.col.seller'))}</dt><dd>${esc(d.seller_name || seller)}${d.seller_nip ? `<span>NIP ${esc(d.seller_nip)}</span>` : ''}</dd></div>
                    <div><dt>${esc(t('inv.col.number'))}</dt><dd>${esc(d.number || t('inv.no_number'))}</dd></div>
                    <div><dt>${esc(t('inv.col.date'))}</dt><dd>${esc(day(d.issue_date))}</dd></div>
                    ${d.ksef_number ? `<div><dt>${esc(t('pu.inv.ksef'))}</dt><dd class="tfb-pu-inv-ksef">${esc(d.ksef_number)}</dd></div>` : ''}
                </dl>
                <div class="table-responsive"><table class="table table-sm mb-0 tfb-pu-inv-table">
                    <thead><tr><th class="text-end">${esc(t('pu.inv.lp'))}</th><th>${esc(t('inv.col.line'))}</th><th class="text-end">${esc(t('pu.inv.qty'))}</th><th>${esc(t('inv.col.unit'))}</th>
                        <th class="text-end">${esc(t('inv.col.unit_price'))}</th><th class="text-end">${esc(t('inv.col.net'))}</th><th class="text-end">${esc(t('pu.inv.vat'))}</th></tr></thead>
                    <tbody>${lines.map(l => {
                        const up = l.material && upIds.has(l.material.id);
                        return `<tr data-pu-line="${l.material ? l.material.id : ''}"${up ? ' class="is-up"' : ''}>
                            <td class="text-end tfb-num">${esc(String(l.line_no))}</td>
                            <td>${esc(l.name)}${l.code ? `<span class="tfb-pu-inv-sub">${esc(l.code)}</span>` : ''}${up ? `<span class="tfb-pu-inv-sub">→ ${esc(l.material.name)} · <span class="tag-warn">${esc(t('pu.inv.up_tag'))}</span></span>` : ''}</td>
                            <td class="text-end tfb-num">${esc(f(l.quantity, 3))}</td><td>${esc(l.unit || '')}</td>
                            <td class="text-end tfb-num">${esc(unitPrice(l.unit_price))}</td><td class="text-end tfb-num">${esc(l.net_value === null ? '—' : f2(l.net_value))}</td>
                            <td class="text-end tfb-num">${esc(vat(l.vat_rate))}</td></tr>`;
                    }).join('') || `<tr><td colspan="7" class="text-muted">${esc(t('pu.inv.empty'))}</td></tr>`}</tbody>
                    <tfoot><tr><td colspan="5">${esc(t('pu.inv.total'))}</td><td colspan="2" class="text-end tfb-num">${esc(t('pu.inv.total_net', {v: money(d.total_net)}))}
                        <span class="tfb-pu-inv-sub">${esc(t('pu.inv.total_gross', {v: money(d.total_gross)}))}</span></td></tr></tfoot>
                </table></div>
                <div class="tfb-pu-inv-foot">
                    ${d.file ? `<button type="button" class="btn btn-sm btn-outline-secondary" data-pu-file><i class="bi bi-download"></i> ${esc(t(isKsef ? 'pu.inv.download_xml' : 'pu.inv.download'))}</button>` : ''}
                    ${onSupplyInvoice ? '' : `<a class="btn btn-sm btn-link" href="${api.root}/workshop/supply#tab=invoices&inv=${d.id}" target="_blank" rel="noopener">${esc(t('pu.inv.open'))} <i class="bi bi-box-arrow-up-right"></i></a>`}
                </div>
            </section>`;
        const host = document.createElement('div');
        host.innerHTML = `<div class="modal fade" tabindex="-1" role="dialog" aria-labelledby="tfbPuTitle"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content tfb-pu">
            <div class="modal-header"><h5 class="modal-title" id="tfbPuTitle"><i class="bi bi-graph-up-arrow text-danger"></i> ${esc(t('pu.title', {n: ups.length}))}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="${esc(t('pu.cancel'))}"></button></div>
            <div class="modal-body">
                <div class="tfb-pu-src"><p class="small text-muted mb-0">${esc(seller)} · ${esc(t('pu.invoice', {number: d.number || '#' + d.id}))}</p>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-pu-show aria-expanded="false"><i class="bi bi-receipt"></i> <span>${esc(t(isKsef ? 'pu.show_invoice_ksef' : 'pu.show_invoice'))}</span></button></div>
                ${invoiceView}
                <p class="mb-3">${esc(t('pu.lead'))}</p>
                <div class="tfb-pu-list">${ups.map(u => `<div class="tfb-pu-item" data-pu-item="${u.raw_material_id}">
                    <div class="tfb-pu-name"><strong>${esc(u.name)}</strong><small>${esc(t('inv.price_up.recipes', {n: u.recipes}))} · ${esc(t('pu.qty', {q: qty(u)}))}</small>${fromLine(u)}</div>
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
        // [257] Pokaż / ukryj fakturę ; « poz. N » przy surowcu : otwiera fakturę i miga tą pozycją.
        const invEl = modalEl.querySelector('[data-pu-inv]');
        const showBtn = modalEl.querySelector('[data-pu-show]');
        const toggleInvoice = (open) => {
            invEl.hidden = !open;
            showBtn.setAttribute('aria-expanded', String(open));
            showBtn.querySelector('span').textContent = t(open ? 'pu.hide_invoice' : (isKsef ? 'pu.show_invoice_ksef' : 'pu.show_invoice'));
        };
        showBtn.addEventListener('click', () => toggleInvoice(invEl.hidden));
        modalEl.querySelectorAll('[data-pu-goto]').forEach(b => b.addEventListener('click', () => {
            toggleInvoice(true);
            const rows = [...invEl.querySelectorAll(`tr[data-pu-line="${b.dataset.puGoto}"]`)];
            if (!rows.length) return;
            rows[0].scrollIntoView({block: 'center', behavior: 'smooth'});
            rows.forEach(r => { r.classList.remove('is-flash'); void r.offsetWidth; r.classList.add('is-flash'); });
        }));
        modalEl.querySelector('[data-pu-file]')?.addEventListener('click', async (e) => {
            const b = e.currentTarget;
            b.disabled = true;
            try {
                const file = await api.get(`/supply/invoices/${d.id}/file`);
                const bin = atob(file.content_base64);
                const bytes = new Uint8Array(bin.length);
                for (let k = 0; k < bin.length; k++) bytes[k] = bin.charCodeAt(k);
                const url = URL.createObjectURL(new Blob([bytes], {type: file.mime}));
                const a = Object.assign(document.createElement('a'), {href: url, download: file.filename});
                document.body.appendChild(a);
                a.click();
                a.remove();
                setTimeout(() => URL.revokeObjectURL(url), 10000);
            } catch (err) { ui.notify(false, errText(err)); }
            b.disabled = false;
        });
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
