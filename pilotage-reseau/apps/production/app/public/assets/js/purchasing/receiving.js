/*
 * Kontrola przyjęć dnia [230] (ERP) : szkic e-maila do dostawcy — « Kopiuj » (temat + treść) i « Otwórz w poczcie »
 * (mailto z bieżącym tematem i treścią). Nic nie jest wysyłane z ERP.
 */
(function () {
    'use strict';
    document.querySelectorAll('[data-mail]').forEach((box) => {
        const subject = box.querySelector('[data-mail-subject]');
        const body = box.querySelector('[data-mail-body]');
        const open = box.querySelector('[data-mail-open]');
        const sync = () => {
            if (!open) return;
            const to = open.getAttribute('href').replace(/^mailto:/, '').split('?')[0];
            open.setAttribute('href', `mailto:${to}?subject=${encodeURIComponent(subject.value)}&body=${encodeURIComponent(body.value)}`);
        };
        subject.addEventListener('input', sync);
        body.addEventListener('input', sync);
        sync();
        const copy = box.querySelector('[data-mail-copy]');
        copy.addEventListener('click', async () => {
            const text = subject.value + '\n\n' + body.value;
            try { await navigator.clipboard.writeText(text); } catch (e) {
                body.select(); document.execCommand('copy');
            }
            const label = copy.innerHTML;
            copy.innerHTML = '<i class="bi bi-check-lg"></i> Skopiowano';
            setTimeout(() => { copy.innerHTML = label; }, 1800);
        });
    });
})();
