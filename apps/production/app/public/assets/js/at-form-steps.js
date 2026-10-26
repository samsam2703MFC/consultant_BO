/*
 * Formularz tworzenia krok po kroku (audyt 2, lot C) : <form data-at-steps> z sekcjami <section data-step="Tytuł" data-lead="…">.
 * Pasek kroków (.at-steps) nad formularzem, « Wstecz » / « Dalej », sprawdzenie pól kroku przed przejściem (wymagane, e-mail,
 * zakresy — reguły HTML formularza, serwer sprawdza jeszcze raz), « Enter » przechodzi dalej zamiast wysyłać, przycisk
 * zapisu ([data-at-steps-submit] albo pierwszy przycisk submit) tylko na ostatnim kroku. Krok « Podsumowanie »
 * (<section data-step data-at-steps-summary>) zbiera wpisane wartości. Po błędzie serwera formularz otwiera krok
 * z pierwszym błędnym polem ([data-at-steps-error="nazwa_pola"] albo .is-invalid), inaczej pierwszy krok.
 */
(function () {
    'use strict';

    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    function fieldsOf(section) {
        return [...section.querySelectorAll('input, select, textarea')].filter((el) => !el.disabled && el.type !== 'hidden' && !el.closest('[hidden]:not(section[data-step])'));
    }

    function labelOf(el) {
        if (el.dataset.summaryLabel) return el.dataset.summaryLabel;
        const id = el.id && el.form ? el.form.querySelector('label[for="' + CSS.escape(el.id) + '"]') : null;
        const text = (id || el.closest('label') || {}).textContent || el.name;
        return text.replace(/\s+/g, ' ').replace(/\*$/, '').trim();
    }

    function valueOf(el) {
        if (el.type === 'checkbox') return el.checked ? (el.dataset.summaryGroup ? (el.closest('label') || {}).textContent.trim() : 'tak') : '';
        if (el.type === 'radio') return el.checked ? labelOf(el) : '';
        if (el.tagName === 'SELECT') return el.value === '' ? '' : [...el.selectedOptions].map((o) => o.textContent.trim()).join(', ');
        return el.value.trim();
    }

    function init(form) {
        const steps = [...form.querySelectorAll('section[data-step]')];
        if (steps.length < 2) return;
        const submit = form.querySelector('[data-at-steps-submit]') || form.querySelector('button:not([type="button"]), input[type="submit"]');
        const bar = document.createElement('ol');
        bar.className = 'at-steps';
        bar.setAttribute('aria-label', 'Kroki');
        bar.innerHTML = steps.map((s, i) => '<li data-step-tab="' + i + '" role="button" tabindex="-1"><span>' + esc(s.dataset.step) + '</span></li>').join('');
        form.parentNode.insertBefore(bar, form);

        const nav = document.createElement('div');
        nav.className = 'at-wizard-nav';
        nav.innerHTML = '<button type="button" class="btn btn-outline-dark" data-at-back><i class="bi bi-arrow-left"></i> Wstecz</button>'
            + '<span class="at-wizard-hint" data-at-hint aria-live="polite"></span>'
            + '<button type="button" class="btn btn-primary at-next" data-at-next>Dalej <i class="bi bi-arrow-right"></i></button>';
        form.appendChild(nav);
        if (submit) { submit.classList.add('at-next'); nav.appendChild(submit); }
        const back = nav.querySelector('[data-at-back]');
        const next = nav.querySelector('[data-at-next]');
        const hint = nav.querySelector('[data-at-hint]');
        let current = 0;
        let reached = 0;

        function summary(section) {
            const rows = [];
            steps.forEach((s) => {
                if (s === section) return;
                const values = fieldsOf(s).filter((el) => el.type !== 'radio' || el.checked)
                    .map((el) => [el.type === 'radio' || el.dataset.summaryGroup ? (el.dataset.summaryGroup || 'Wybór') : labelOf(el), valueOf(el)]).filter(([, v]) => v !== '');
                if (values.length) rows.push('<h6 class="mt-3 mb-1">' + esc(s.dataset.step) + '</h6><dl class="at-steps-summary">'
                    + values.map(([l, v]) => '<dt>' + esc(l) + '</dt><dd>' + esc(v) + '</dd>').join('') + '</dl>');
            });
            const box = section.querySelector('[data-at-steps-summary-body]') || section;
            box.innerHTML = rows.join('') || '<p class="text-muted">Nic nie wpisano.</p>';
        }

        function show(i, focus) {
            current = Math.max(0, Math.min(i, steps.length - 1));
            reached = Math.max(reached, current);
            steps.forEach((s, k) => { s.hidden = k !== current; });
            bar.querySelectorAll('[data-step-tab]').forEach((t, k) => {
                t.classList.toggle('is-active', k === current);
                t.classList.toggle('is-done', k < current);
                t.tabIndex = k <= reached ? 0 : -1;
                if (k === current) t.setAttribute('aria-current', 'step'); else t.removeAttribute('aria-current');
            });
            const last = current === steps.length - 1;
            back.hidden = current === 0;
            next.hidden = last;
            if (submit) submit.hidden = !last;
            hint.textContent = 'Krok ' + (current + 1) + ' z ' + steps.length;
            hint.classList.remove('is-error');
            if (steps[current].hasAttribute('data-at-steps-summary')) summary(steps[current]);
            form.dispatchEvent(new CustomEvent('at-step', { detail: { index: current, section: steps[current] } }));
            if (focus) {
                const first = fieldsOf(steps[current])[0];
                if (first) first.focus({ preventScroll: true });
                bar.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            }
        }

        function valid(i) {
            for (const el of fieldsOf(steps[i])) {
                if (!el.checkValidity()) {
                    show(i);
                    el.reportValidity();
                    hint.textContent = labelOf(el) + ' : ' + el.validationMessage;
                    hint.classList.add('is-error');
                    return false;
                }
            }
            return true;
        }

        next.addEventListener('click', () => { if (valid(current)) show(current + 1, true); });
        back.addEventListener('click', () => show(current - 1, true));
        bar.addEventListener('click', (e) => {
            const tab = e.target.closest('[data-step-tab]');
            if (!tab) return;
            const target = +tab.dataset.stepTab;
            if (target > reached) return;
            for (let k = current; k < target; k++) if (!valid(k)) return;
            show(target, true);
        });
        form.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'BUTTON') return;
            if (current < steps.length - 1) { e.preventDefault(); next.click(); }
        });
        form.addEventListener('submit', (e) => {
            for (let k = 0; k < steps.length; k++) if (!valid(k)) { e.preventDefault(); return; }
        });

        // Po błędzie serwera : krok z pierwszym błędnym polem.
        const errName = form.dataset.atStepsError;
        const bad = (errName && form.querySelector('[name="' + CSS.escape(errName) + '"]')) || form.querySelector('.is-invalid');
        const start = bad ? steps.findIndex((s) => s.contains(bad)) : 0;
        if (start > 0) reached = start;
        show(Math.max(0, start));
    }

    const run = () => document.querySelectorAll('form[data-at-steps]').forEach(init);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run); else run();
})();
