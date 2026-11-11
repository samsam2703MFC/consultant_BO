/*
 * Pytania przy odbiciu [135, 263] — wspólne dla tabletu biurowego, tabletu grupy i telefonu.
 *   confirm : punkt do potwierdzenia (dotknięcie = « tak ») ; obowiązkowy blokuje « OK, zatwierdzam ».
 *   yes_no  : odpowiedź Tak / Nie (obie poprawne, obowiązkowa = trzeba wybrać) ; (i) pokazuje informację
 *             (np. zasada firmy o towarze) ; « Tak » → krótko : co (np. 2 tarty z braków), zapisane z odbiciem.
 * Użycie : const cq = TfbClockQuestions(questions, {t, esc, onChange}) ; cq.html w ekranie, cq.bind(kontener),
 * cq.blocked() — czy czegoś brakuje, cq.answers() — [{question_id, yes, note?}] do POST /kiosk/clock.
 */
(function () {
    'use strict';
    window.TfbClockQuestions = function (questions, opts) {
        const t = opts.t;
        const esc = opts.esc;
        const confirm = questions.filter(q => q.kind !== 'yes_no');
        const yesNo = questions.filter(q => q.kind === 'yes_no');
        const checked = new Set();
        const choice = {}; // id → true (Tak) / false (Nie)
        const notes = {};
        const changed = () => { if (opts.onChange) opts.onChange(); };

        const html = (confirm.length ? `<p class="kiosk-muted">${esc(t('confirm_help'))}</p>
            <div class="kiosk-checks">${confirm.map(q => `<button type="button" class="kiosk-check" data-q="${q.id}" aria-pressed="false">
                <span class="kiosk-check-box" aria-hidden="true"></span><span>${esc(q.text)}${q.required ? '' : `<small>${esc(t('optional'))}</small>`}</span></button>`).join('')}</div>` : '')
            + yesNo.map(q => `<div class="kiosk-yn" data-yn="${q.id}">
                <div class="kiosk-yn-q"><span>${esc(q.text)}</span>${q.info ? `<button type="button" class="kiosk-yn-i" data-yn-info="${q.id}" aria-expanded="false" aria-label="${esc(t('yn_info'))}">i</button>` : ''}</div>
                ${q.info ? `<p class="kiosk-yn-text" data-yn-text="${q.id}" hidden>${esc(q.info)}</p>` : ''}
                <div class="kiosk-yn-btns" role="radiogroup" aria-label="${esc(q.text)}">
                    <button type="button" class="kiosk-btn" role="radio" aria-checked="false" data-yn-set="${q.id}" data-value="1">${esc(t('yn_yes'))}</button>
                    <button type="button" class="kiosk-btn" role="radio" aria-checked="false" data-yn-set="${q.id}" data-value="0">${esc(t('yn_no'))}</button></div>
                <label class="kiosk-yn-detail" data-yn-detail="${q.id}" hidden><span>${esc(t('yn_detail'))}</span>
                    <input class="kiosk-input" data-yn-note="${q.id}" maxlength="200" autocomplete="off" placeholder="${esc(t('yn_detail_ph'))}"></label>
            </div>`).join('');

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
                    const detail = box.querySelector('[data-yn-detail]');
                    detail.hidden = !choice[id];
                    if (choice[id]) detail.querySelector('input').focus();
                    changed();
                });
                box.querySelector('[data-yn-note]').addEventListener('input', (e) => { notes[id] = e.target.value.trim(); changed(); });
            });
        }

        return {
            html,
            bind,
            blocked: () => confirm.some(q => q.required && !checked.has(q.id))
                || yesNo.some(q => (q.required && choice[q.id] === undefined) || (choice[q.id] === true && !notes[q.id])),
            answers: () => confirm.map(q => ({question_id: q.id, yes: checked.has(q.id)}))
                .concat(yesNo.filter(q => choice[q.id] !== undefined).map(q => ({question_id: q.id, yes: choice[q.id], ...(choice[q.id] ? {note: notes[q.id] || ''} : {})}))),
        };
    };
})();
