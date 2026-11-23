// [372] SMS de commande au client (un clic, texte relu) : fenêtre commune aux assistants « Zamówienia dnia » et
// « Klienci bez zamówienia ». orderSms.open({kinds: ['order_confirm', 'order_changed'], id, hints: {expected, detail, date}, onSent})
(() => {
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const LABEL = {order_missing: 'Przypomnienie : brak zamówienia', order_confirm: 'Potwierdzenie zamówienia', order_changed: 'Zmiana zamówienia'};
    // Segments SMS : 160 / 153 caractères en GSM, 70 / 67 dès qu'un caractère sort de l'alphabet GSM (ą, ł, ś…).
    const GSM = /^[@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&'()*+,\-./0-9:;<=>?¡A-ZÄÖÑÜ§¿a-zäöñüà^{}\\[~\]|€]*$/;
    const parts = (t) => { const g = GSM.test(t), n = t.length; return n === 0 ? 0 : (g ? (n <= 160 ? 1 : Math.ceil(n / 153)) : (n <= 70 ? 1 : Math.ceil(n / 67))); };
    const q = (o) => Object.entries(o).filter(([, v]) => v !== null && v !== undefined && v !== '').map(([k, v]) => `${k}=${encodeURIComponent(v)}`).join('&');

    window.orderSms = {
        /** Badge du dernier SMS d'une ligne. */
        badge(s) {
            if (!s) return '';
            const cls = s.status === 'failed' ? 'bad' : (s.status === 'sent' ? 'ok' : '');
            return `<span class="tw-pill ${cls}" title="${esc(s.label)}${s.status === 'logged' ? ' — zapisany, nie wysłany (bramka SMS)' : ''}"><i class="bi bi-chat-dots"></i> SMS ${esc(s.at)}</span>`;
        },
        button: (attr, disabled, title) => `<button type="button" class="tw-btn ghost sm" ${attr} ${disabled ? 'disabled' : ''} title="${esc(title || 'Wyślij SMS do klienta')}"><i class="bi bi-chat-dots"></i> SMS</button>`,

        async open({kinds, id, hints = {}, onSent}) {
            let kind = kinds[0], d = null;
            const load = async () => {
                const r = await at.send(`/ajax/client-orders/sms/draft?${q({kind, id, expected: hints.expected, detail: hints.detail, date: hints.date})}`, 'GET');
                if (!r.ok) { at.notify(false, r.message || 'Nie udało się przygotować SMS.'); return false; }
                d = r.data;
                return true;
            };
            if (!await load()) return;
            const meta = () => `<div class="osms-to"><b>${esc(d.customer)}</b> · ${d.phone ? esc(d.phone) : '<span class="text-danger">brak numeru telefonu (fiche klienta)</span>'} · język ${esc(d.language.toUpperCase())}</div>
                ${d.delivers ? '' : '<div class="osms-warn"><i class="bi bi-exclamation-triangle"></i> Bramka SMS nie wysyła (Ustawienia › SMS) : SMS zostanie tylko zapisany w historii klienta.</div>'}
                ${d.last ? `<div class="osms-last">Ostatni taki SMS : ${esc(d.last.at)}${d.last.status === 'logged' ? ' (zapisany)' : ''}</div>` : ''}`;
            const counter = (t) => `${t.length} / ${d.max} znaków · ${parts(t)} SMS`;
            const seg = kinds.length > 1 ? `<div class="osms-seg">${kinds.map(k => `<button type="button" data-osms-kind="${k}" class="${k === kind ? 'on' : ''}">${esc(LABEL[k])}</button>`).join('')}</div>` : '';
            const res = await Swal.fire({
                title: 'SMS do klienta',
                html: `<style>.osms{text-align:left;font-size:13.5px}.osms-seg{display:flex;gap:0;margin:0 0 10px;border:1px solid #e0d6cb;border-radius:9px;overflow:hidden}
                    .osms-seg button{flex:1;border:0;background:#fff;padding:7px 8px;font-size:12.5px}.osms-seg button.on{background:var(--at-brand,#8D1D2C);color:#fff;font-weight:600}
                    .osms-to{margin:0 0 6px}.osms-warn{background:#fff3d6;color:#7a5a00;border-radius:8px;padding:6px 9px;margin:0 0 6px;font-size:12.5px}.osms-last{color:#7a716b;font-size:12px;margin:0 0 6px}
                    .osms textarea{width:100%;min-height:120px;border:1px solid #e0d6cb;border-radius:10px;padding:9px 10px;font-size:14px}.osms small{color:#7a716b}</style>
                    <div class="osms">${seg}<div data-osms-meta>${meta()}</div>
                    <textarea data-osms-text maxlength="${d.max}">${esc(d.text)}</textarea><small data-osms-count>${counter(d.text)}</small></div>`,
                showCancelButton: true, confirmButtonText: '<i class="bi bi-send"></i> Wyślij SMS', cancelButtonText: 'Anuluj', focusConfirm: false, width: 560,
                confirmButtonColor: '#8D1D2C', showLoaderOnConfirm: true,
                didOpen: (pop) => {
                    const ta = pop.querySelector('[data-osms-text]'), cnt = pop.querySelector('[data-osms-count]');
                    const sync = () => { cnt.textContent = counter(ta.value); Swal.getConfirmButton().disabled = !d.phone || ta.value.trim().length < 10; };
                    ta.addEventListener('input', sync);
                    pop.querySelectorAll('[data-osms-kind]').forEach(b => b.addEventListener('click', async () => {
                        if (b.dataset.osmsKind === kind) return;
                        kind = b.dataset.osmsKind;
                        if (!await load()) return;
                        pop.querySelectorAll('[data-osms-kind]').forEach(x => x.classList.toggle('on', x.dataset.osmsKind === kind));
                        pop.querySelector('[data-osms-meta]').innerHTML = meta();
                        ta.value = d.text;
                        sync();
                    }));
                    sync();
                    // Curseur sur « … » à compléter (modification de commande).
                    const k = ta.value.indexOf('…');
                    if (k >= 0) { ta.focus(); ta.setSelectionRange(k, k + 1); }
                },
                preConfirm: async () => {
                    const text = Swal.getPopup().querySelector('[data-osms-text]').value;
                    const r = await at.send('/ajax/client-orders/sms/send', 'POST', {kind, id, text});
                    if (!r.ok) { Swal.showValidationMessage(r.message || 'SMS nie wysłany.'); return false; }
                    return r;
                },
                allowOutsideClick: () => !Swal.isLoading(),
            });
            if (res.isConfirmed && res.value) {
                at.notify(true, res.value.message);
                if (onSent) await onSent(res.value);
            }
        },
    };
})();
