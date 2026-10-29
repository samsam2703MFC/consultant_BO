/*
 * Rodzaj faktury zakupowej (makieta 2) : okno « co zrobić z tą fakturą ? » — koszt firmy → Finanse (kategoria kalkulatora
 * kosztów, miesiąc kosztu) albo odrzuć (nie dotyczy firmy), z regułą « zawsze dla tego dostawcy » (NIP) stosowaną też do
 * innych otwartych faktur i przy kolejnych importach z KSeF. Wspólne dla Zaopatrzenia i Finansów : POST …/{id}/classify
 * (path : /supply/invoices albo /finance/services). Etykiety cls.* w supply.json.
 */
(function () {
    'use strict';
    const {ui} = window.TfbWorkshop;
    const esc = ui.esc;
    const fullDay = (s) => s ? `${s.slice(8, 10)}.${s.slice(5, 7)}.${s.slice(0, 4)}` : '—';
    const errText = (err) => ((err.body && err.body.errors) || []).map(x => x.message).join(' ') || err.message;
    const MONTHS = {pl: ['styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'],
        fr: ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre']};
    const monthLabel = (api, ym) => ym ? `${(MONTHS[api.lang] || MONTHS.pl)[Number(ym.slice(5, 7)) - 1]} ${ym.slice(0, 4)}` : '';

    /** Komunikat po klasyfikacji (usługa : kategoria i miesiąc ; reguła : ile innych faktur). */
    function doneText(api, res, body) {
        const t = (k, p) => api.t(k, p);
        const cat = res.category ? res.category.label : '';
        const base = body.action === 'service' ? t('cls.done_service', {category: cat, month: monthLabel(api, res.service_month)})
            : body.action === 'reject' ? t('cls.done_reject') : t('cls.done_goods');
        return base + (res.applied && res.applied.length ? ' ' + t('cls.applied', {n: res.applied.length}) : '');
    }

    /** Z powrotem do surowców i opakowań (z potwierdzeniem ; rule : usuwa regułę dostawcy). */
    async function toGoods({api, invoice, path = '/supply/invoices', rule = false, onDone}) {
        const t = (k, p) => api.t(k, p);
        if (!window.confirm(t('cls.confirm_goods', {number: invoice.number || '#' + invoice.id}) + (rule ? t('cls.confirm_goods_rule') : '') + ' ?')) return null;
        const body = {action: 'goods', rule};
        try {
            const res = await api.post(`${path}/${invoice.id}/classify`, body);
            ui.notify(true, doneText(api, res, body));
            if (onDone) onDone(res);
            return res;
        } catch (err) {
            ui.notify(false, errText(err));
            return null;
        }
    }

    async function open({api, invoiceId, action = 'service', path = '/supply/invoices', onDone}) {
        const t = (k, p) => api.t(k, p);
        let d;
        try {
            d = await api.get(`/supply/invoices/${invoiceId}`);
        } catch (err) {
            ui.notify(false, errText(err));
            return;
        }
        const c = d.classify;
        const seller = d.supplier ? d.supplier.name : (d.seller_name || '—');
        const money = (v) => v === null || v === undefined ? '—' : `${ui.fmt(v, 2, api.lang)} ${!d.currency || d.currency === 'PLN' ? 'zł' : d.currency}`;
        const groups = {};
        c.categories.forEach(x => { (groups[x.group_label] = groups[x.group_label] || []).push(x); });
        const preset = (c.rule && c.rule.cost_category) || d.cost_category || (c.guess && c.guess.code) || '';
        const month = d.service_month || c.default_month;
        const lines = d.lines.slice(0, 5);
        const ruleNow = c.rule ? (c.rule.action === 'service'
            ? t('cls.rule_exists_service', {category: (c.categories.find(x => x.code === c.rule.cost_category) || {}).label || c.rule.cost_category}) : t('cls.rule_exists_reject')) : '';
        const host = document.createElement('div');
        host.innerHTML = `<div class="modal fade" tabindex="-1" role="dialog" aria-labelledby="tfbClsTitle"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content tfb-cls">
            <div class="modal-header"><h5 class="modal-title" id="tfbClsTitle">${esc(t('cls.title', {seller}))}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="${esc(t('cls.cancel'))}"></button></div>
            <div class="modal-body">
                <p class="small text-muted mb-2">${esc(d.number || '—')} · ${esc(fullDay(d.issue_date))}${d.seller_nip ? ' · NIP ' + esc(d.seller_nip) : ''} · <strong class="text-body">${esc(t('cls.net', {v: money(d.total_net)}))}</strong></p>
                <table class="table table-sm tfb-cls-lines mb-3"><tbody>${lines.map(l => `<tr><td>${esc(l.name)}</td><td class="text-end text-nowrap">${esc(ui.fmt(l.quantity, 3, api.lang))} ${esc(l.unit || '')}</td>
                    <td class="text-end text-nowrap">${esc(money(l.net_value))}</td></tr>`).join('')}
                    ${d.lines.length > lines.length ? `<tr><td colspan="3" class="small text-muted">${esc(t('cls.lines_more', {n: d.lines.length - lines.length}))}</td></tr>` : ''}</tbody></table>
                <div class="tfb-cls-options" role="radiogroup">
                    <label class="tfb-cls-option"><input class="form-check-input" type="radio" name="cls" value="service" ${action !== 'reject' ? 'checked' : ''}>
                        <span><strong>${esc(t('cls.service'))}</strong><small>${esc(t('cls.service_help'))}</small>
                        <span class="tfb-cls-fields" data-cls-service>
                            <span><span class="form-label small mb-0 d-block">${esc(t('cls.category'))}</span><select class="form-select form-select-sm" data-cls-category data-field="cost_category">
                                <option value="">${esc(t('cls.pick_category'))}</option>
                                ${Object.entries(groups).map(([g, list]) => `<optgroup label="${esc(g)}">${list.map(x => `<option value="${esc(x.code)}" ${x.code === preset ? 'selected' : ''}>${esc(x.label)}</option>`).join('')}</optgroup>`).join('')}
                            </select>${c.guess ? `<small class="text-success">${esc(t('cls.guess', {label: c.guess.label}))}</small>` : ''}</span>
                            <span><span class="form-label small mb-0 d-block">${esc(t('cls.month'))}</span><input class="form-control form-control-sm" type="month" data-cls-month data-field="service_month" value="${esc(month)}"></span>
                        </span></span></label>
                    <label class="tfb-cls-option"><input class="form-check-input" type="radio" name="cls" value="reject" ${action === 'reject' ? 'checked' : ''}>
                        <span><strong>${esc(t('cls.reject'))}</strong><small>${esc(t('cls.reject_help'))}</small>
                        <span class="tfb-cls-fields" data-cls-reject><input class="form-control form-control-sm" data-cls-note data-field="note" maxlength="500" placeholder="${esc(t('cls.note'))}"></span></span></label>
                </div>
                <div class="form-check mt-3">
                    <input class="form-check-input" type="checkbox" id="tfbClsRule" data-cls-rule ${d.seller_nip ? '' : 'disabled'} ${c.rule ? 'checked' : ''}>
                    <label class="form-check-label" for="tfbClsRule"><strong>${esc(d.seller_nip ? t('cls.rule', {seller, nip: d.seller_nip}) : t('cls.rule_no_nip'))}</strong>
                        <small class="d-block text-muted">${esc(t('cls.rule_help'))}${c.same_nip_open ? ' ' + esc(t('cls.rule_others', {n: c.same_nip_open})) : ''}</small>
                        ${ruleNow ? `<small class="d-block">${esc(ruleNow)}</small>` : ''}</label>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">${esc(t('cls.cancel'))}</button>
                <button type="button" class="btn btn-primary" data-cls-submit></button></div>
        </div></div></div>`;
        const modalEl = host.firstElementChild;
        document.body.appendChild(modalEl);
        const modal = new window.bootstrap.Modal(modalEl);
        const q = (s) => modalEl.querySelector(s);
        const submit = q('[data-cls-submit]');
        const current = () => modalEl.querySelector('input[name="cls"]:checked').value;
        const refresh = () => {
            const a = current();
            q('[data-cls-service]').hidden = a !== 'service';
            q('[data-cls-reject]').hidden = a !== 'reject';
            submit.textContent = q('[data-cls-rule]').checked && d.seller_nip ? t('cls.submit_rule') : t(a === 'service' ? 'cls.submit_service' : 'cls.submit_reject');
            submit.className = 'btn ' + (a === 'service' ? 'btn-primary' : 'btn-outline-danger');
        };
        modalEl.querySelectorAll('input[name="cls"], [data-cls-rule]').forEach(x => x.addEventListener('change', refresh));
        refresh();
        submit.addEventListener('click', async () => {
            const a = current();
            const body = {action: a, rule: q('[data-cls-rule]').checked && !!d.seller_nip};
            if (a === 'service') {
                body.cost_category = q('[data-cls-category]').value || null;
                body.service_month = q('[data-cls-month]').value || null;
            } else {
                body.note = q('[data-cls-note]').value.trim() || null;
            }
            submit.disabled = true;
            try {
                const res = await api.post(`${path}/${d.id}/classify`, body);
                modal.hide();
                ui.notify(true, doneText(api, res, body));
                if (onDone) onDone(res);
            } catch (err) {
                const orphans = ui.fieldErrors(modalEl, err);
                ui.notify(false, orphans.join(' ') || errText(err));
                submit.disabled = false;
            }
        });
        modalEl.addEventListener('hidden.bs.modal', () => { modal.dispose(); modalEl.remove(); });
        modal.show();
    }

    window.TfbInvoiceClassify = {open, toGoods};
})();
