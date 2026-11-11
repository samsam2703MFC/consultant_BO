/*
 * Pytania przy odbiciu [135, 263, 264] — wspólne dla tabletu biurowego, tabletu grupy i telefonu.
 *   confirm : punkt do potwierdzenia (dotknięcie = « tak ») ; obowiązkowy blokuje « OK, zatwierdzam ».
 *   yes_no  : odpowiedź Tak / Nie (obie poprawne, obowiązkowa = trzeba wybrać) ; (i) pokazuje informację
 *             (np. zasada firmy o towarze) ; « Tak » → co :
 *               picker « products » [264] : szybki wybór kategoria → produkt → liczba sztuk na klawiaturze
 *               (kilka pozycji, « z braków » na pozycję) ; « Inne » = krótki tekst, gdy czegoś nie ma na liście ;
 *               bez pickera : krótki tekst (np. 2 tarty z braków).
 * Użycie : const cq = TfbClockQuestions(questions, {t, esc, catalog, onChange}) ; cq.html w ekranie, cq.bind(kontener),
 * cq.blocked() — czy czegoś brakuje, cq.answers() — [{question_id, yes, note?, items?}] do POST /kiosk/clock.
 * catalog : [{id, name, products: [{id, name}]}] z odpowiedzi serwera (questions.catalog).
 */
(function () {
    'use strict';
    const MAX_QTY = 999;

    window.TfbClockQuestions = function (questions, opts) {
        const t = opts.t;
        const esc = opts.esc;
        const catalog = Array.isArray(opts.catalog) ? opts.catalog : [];
        const confirm = questions.filter(q => q.kind !== 'yes_no');
        const yesNo = questions.filter(q => q.kind === 'yes_no');
        const picks = (q) => q.picker === 'products' && catalog.length > 0;
        const checked = new Set();
        const choice = {}; // id → true (Tak) / false (Nie)
        const notes = {};
        const items = {}; // id → [{product_id, name, quantity, waste}]
        const changed = () => { if (opts.onChange) opts.onChange(); };

        const pickerHtml = (q) => `<div class="kiosk-gp" data-gp="${q.id}" hidden>
                <div class="kiosk-gp-lines" data-gp-lines aria-live="polite"></div>
                <p class="kiosk-gp-help">${esc(t('gp_help'))}</p>
                <div class="kiosk-gp-cats" role="tablist" aria-label="${esc(t('gp_categories'))}">${catalog.map(c =>
                    `<button type="button" class="kiosk-gp-cat" role="tab" aria-selected="false" data-gp-cat="${c.id}">${esc(c.name)}</button>`).join('')}
                    <button type="button" class="kiosk-gp-cat is-other" data-gp-other aria-expanded="false">${esc(t('gp_other'))}</button></div>
                <div class="kiosk-gp-prods" data-gp-prods></div>
                <label class="kiosk-yn-detail" data-gp-other-box hidden><span>${esc(t('gp_other_label'))}</span>
                    <input class="kiosk-input" data-yn-note="${q.id}" maxlength="200" autocomplete="off" placeholder="${esc(t('gp_other_ph'))}"></label>
            </div>`;

        const html = (confirm.length ? `<p class="kiosk-muted">${esc(t('confirm_help'))}</p>
            <div class="kiosk-checks">${confirm.map(q => `<button type="button" class="kiosk-check" data-q="${q.id}" aria-pressed="false">
                <span class="kiosk-check-box" aria-hidden="true"></span><span>${esc(q.text)}${q.required ? '' : `<small>${esc(t('optional'))}</small>`}</span></button>`).join('')}</div>` : '')
            + yesNo.map(q => `<div class="kiosk-yn" data-yn="${q.id}">
                <div class="kiosk-yn-q"><span>${esc(q.text)}</span>${q.info ? `<button type="button" class="kiosk-yn-i" data-yn-info="${q.id}" aria-expanded="false" aria-label="${esc(t('yn_info'))}">i</button>` : ''}</div>
                ${q.info ? `<p class="kiosk-yn-text" data-yn-text="${q.id}" hidden>${esc(q.info)}</p>` : ''}
                <div class="kiosk-yn-btns" role="radiogroup" aria-label="${esc(q.text)}">
                    <button type="button" class="kiosk-btn" role="radio" aria-checked="false" data-yn-set="${q.id}" data-value="1">${esc(t('yn_yes'))}</button>
                    <button type="button" class="kiosk-btn" role="radio" aria-checked="false" data-yn-set="${q.id}" data-value="0">${esc(t('yn_no'))}</button></div>
                ${picks(q) ? pickerHtml(q) : `<label class="kiosk-yn-detail" data-yn-detail="${q.id}" hidden><span>${esc(t('yn_detail'))}</span>
                    <input class="kiosk-input" data-yn-note="${q.id}" maxlength="200" autocomplete="off" placeholder="${esc(t('yn_detail_ph'))}"></label>`}
            </div>`).join('');

        // Klawiatura liczby sztuk : nakładka na całym ekranie (tablet i telefon), zamykana po « Dodaj » albo « Anuluj ».
        function openPad(product, onAdd) {
            let value = '';
            let waste = false;
            const pad = document.createElement('div');
            pad.className = 'kiosk-gp-pad';
            pad.setAttribute('role', 'dialog');
            pad.setAttribute('aria-modal', 'true');
            pad.setAttribute('aria-label', product.name);
            pad.innerHTML = `<div class="kiosk-gp-pad-card">
                    <h3>${esc(product.name)}</h3>
                    <p class="kiosk-gp-qty"><output data-qty>1</output><span>${esc(t('gp_pieces'))}</span></p>
                    <div class="kiosk-pad">${[1, 2, 3, 4, 5, 6, 7, 8, 9].map(d => `<button type="button" class="kiosk-btn" data-d="${d}">${d}</button>`).join('')}
                        <button type="button" class="kiosk-btn" data-back aria-label="${esc(t('erase'))}">⌫</button><button type="button" class="kiosk-btn" data-d="0">0</button><span></span></div>
                    <button type="button" class="kiosk-gp-waste" data-waste aria-pressed="false"><span class="kiosk-check-box" aria-hidden="true"></span>${esc(t('gp_waste'))}</button>
                    <div class="kiosk-gp-pad-acts"><button type="button" class="kiosk-btn" data-cancel>${esc(t('gp_cancel'))}</button>
                        <button type="button" class="kiosk-btn is-ok" data-add>${esc(t('gp_add'))}</button></div>
                </div>`;
            const out = pad.querySelector('[data-qty]');
            const add = pad.querySelector('[data-add]');
            const show = () => { out.textContent = value === '' ? '1' : value; out.classList.toggle('is-default', value === ''); add.disabled = value !== '' && Number(value) < 1; };
            const close = () => { document.removeEventListener('keydown', onKey); pad.remove(); };
            const digit = (d) => { if (value.length < 3 && !(value === '' && d === '0')) { value += d; show(); } };
            const done = () => { const n = value === '' ? 1 : Number(value); if (n >= 1 && n <= MAX_QTY) { close(); onAdd(n, waste); } };
            function onKey(e) {
                if (e.key === 'Escape') close();
                else if (e.key === 'Enter') done();
                else if (e.key === 'Backspace') { value = value.slice(0, -1); show(); }
                else if (/^\d$/.test(e.key)) digit(e.key);
            }
            pad.addEventListener('click', (e) => {
                if (e.target === pad || e.target.closest('[data-cancel]')) { close(); return; }
                const d = e.target.closest('[data-d]');
                if (d) { digit(d.dataset.d); return; }
                if (e.target.closest('[data-back]')) { value = value.slice(0, -1); show(); return; }
                const w = e.target.closest('[data-waste]');
                if (w) { waste = !waste; w.classList.toggle('is-on', waste); w.setAttribute('aria-pressed', String(waste)); return; }
                if (e.target.closest('[data-add]')) done();
            });
            document.addEventListener('keydown', onKey);
            document.body.appendChild(pad);
            show();
            add.focus();
        }

        function bindPicker(box, id) {
            const gp = box.querySelector('[data-gp]');
            const lines = gp.querySelector('[data-gp-lines]');
            const prods = gp.querySelector('[data-gp-prods]');
            const otherBox = gp.querySelector('[data-gp-other-box]');
            const otherBtn = gp.querySelector('[data-gp-other]');
            items[id] = [];
            const drawLines = () => {
                lines.innerHTML = items[id].map((l, i) => `<span class="kiosk-gp-line"><b>${l.quantity} ×</b> ${esc(l.name)}${l.waste ? ` <em>${esc(t('gp_waste_tag'))}</em>` : ''}
                    <button type="button" data-gp-del="${i}" aria-label="${esc(t('gp_remove'))} : ${esc(l.name)}">✕</button></span>`).join('');
            };
            const showCategory = (catId) => {
                const cat = catalog.find(c => c.id === catId);
                gp.querySelectorAll('[data-gp-cat]').forEach(b => { const on = Number(b.dataset.gpCat) === catId; b.classList.toggle('is-on', on); b.setAttribute('aria-selected', String(on)); });
                prods.innerHTML = cat ? cat.products.map(p => `<button type="button" class="kiosk-gp-prod" data-gp-prod="${p.id}">${esc(p.name)}</button>`).join('') : '';
            };
            gp.addEventListener('click', (e) => {
                const del = e.target.closest('[data-gp-del]');
                if (del) { items[id].splice(Number(del.dataset.gpDel), 1); drawLines(); changed(); return; }
                const cat = e.target.closest('[data-gp-cat]');
                if (cat) { showCategory(Number(cat.dataset.gpCat)); return; }
                if (e.target.closest('[data-gp-other]')) {
                    otherBox.hidden = !otherBox.hidden;
                    otherBtn.classList.toggle('is-on', !otherBox.hidden);
                    otherBtn.setAttribute('aria-expanded', String(!otherBox.hidden));
                    if (!otherBox.hidden) otherBox.querySelector('input').focus();
                    return;
                }
                const prod = e.target.closest('[data-gp-prod]');
                if (!prod) return;
                const pid = Number(prod.dataset.gpProd);
                const product = catalog.flatMap(c => c.products).find(p => p.id === pid);
                if (!product) return;
                openPad(product, (quantity, waste) => {
                    // Ten sam produkt z tego samego źródła : ilości się sumują (jak po stronie serwera).
                    const same = items[id].find(l => l.product_id === pid && l.waste === waste);
                    if (same) same.quantity = Math.min(MAX_QTY, same.quantity + quantity);
                    else items[id].push({product_id: pid, name: product.name, quantity, waste});
                    drawLines();
                    changed();
                });
            });
        }

        function bind(root) {
            root.querySelector('.kiosk-checks')?.addEventListener('click', (e) => {
                const b = e.target.closest('[data-q]');
                if (!b) return;
                const id = Number(b.dataset.q);
                if (checked.has(id)) checked.delete(id); else checked.add(id);
                b.classList.toggle('is-on', checked.has(id));
                b.setAttribute('aria-pressed', String(checked.has(id)));
                changed();
            });
            root.querySelectorAll('[data-yn]').forEach((box) => {
                const id = Number(box.dataset.yn);
                const q = yesNo.find(x => x.id === id);
                const picker = picks(q);
                if (picker) bindPicker(box, id);
                box.addEventListener('click', (e) => {
                    const info = e.target.closest('[data-yn-info]');
                    if (info) {
                        const text = box.querySelector('[data-yn-text]');
                        text.hidden = !text.hidden;
                        info.setAttribute('aria-expanded', String(!text.hidden));
                        return;
                    }
                    const set = e.target.closest('[data-yn-set]');
                    if (!set) return;
                    choice[id] = set.dataset.value === '1';
                    box.querySelectorAll('[data-yn-set]').forEach(x => {
                        const on = x === set;
                        x.classList.toggle('is-on', on);
                        x.classList.toggle(x.dataset.value === '1' ? 'is-warn' : 'is-ok', on);
                        x.setAttribute('aria-checked', String(on));
                    });
                    const detail = box.querySelector(picker ? '[data-gp]' : '[data-yn-detail]');
                    detail.hidden = !choice[id];
                    if (choice[id] && !picker) detail.querySelector('input').focus();
                    changed();
                });
                box.querySelector('[data-yn-note]').addEventListener('input', (e) => { notes[id] = e.target.value.trim(); changed(); });
            });
        }

        const hasDetail = (q) => picks(q) ? ((items[q.id] || []).length > 0 || !!notes[q.id]) : !!notes[q.id];
        return {
            html,
            bind,
            blocked: () => confirm.some(q => q.required && !checked.has(q.id))
                || yesNo.some(q => (q.required && choice[q.id] === undefined) || (choice[q.id] === true && !hasDetail(q))),
            answers: () => confirm.map(q => ({question_id: q.id, yes: checked.has(q.id)}))
                .concat(yesNo.filter(q => choice[q.id] !== undefined).map(q => {
                    const a = {question_id: q.id, yes: choice[q.id]};
                    if (!choice[q.id]) return a;
                    if (notes[q.id]) a.note = notes[q.id];
                    if (picks(q)) a.items = (items[q.id] || []).map(l => ({product_id: l.product_id, quantity: l.quantity, waste: l.waste}));
                    else a.note = notes[q.id] || '';
                    return a;
                })),
        };
    };
})();
