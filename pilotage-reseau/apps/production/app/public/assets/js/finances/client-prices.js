/*
 * Ceny klientów wg marży [212] (Marża kategorii › Ceny klientów) : przełącznik widoku (obecna · nowa · różnica /
 * tylko zmiany / wykres), wybór podwyżek, « Zastosuj u klienta » (potwierdzenie, serwer liczy od nowa — tylko w górę)
 * i parametry reguły (administrator).
 */
(function () {
    'use strict';
    const app = document.getElementById('clientPrices');
    if (!app) return;
    const root = app.dataset.root;
    const main = app.querySelector('.cp-main');
    const fmt0 = (v) => Math.round(v).toLocaleString('pl-PL');
    const ceny = (n) => n === 1 ? 'cena' : ([2, 3, 4].includes(n % 10) && ![12, 13, 14].includes(n % 100) ? 'ceny' : 'cen');
    const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));

    async function send(method, url, body) {
        const res = await fetch(root + url, {method, credentials: 'same-origin',
            headers: {'Accept': 'application/json', 'Content-Type': 'application/json'}, body: JSON.stringify(body)});
        const data = await res.json().catch(() => ({ok: false, error: 'Błąd serwera (' + res.status + ').'}));
        if (!res.ok || !data.ok) throw new Error(data.error || 'Błąd zapisu.');
        return data;
    }

    // Widok cennika : wszystkie wiersze / tylko podwyżki / wykres efektu.
    app.querySelectorAll('[data-cp-mode]').forEach((b) => b.addEventListener('click', () => {
        main.dataset.mode = b.dataset.cpMode;
        app.querySelectorAll('[data-cp-mode]').forEach((x) => {
            x.classList.toggle('is-on', x === b);
            x.setAttribute('aria-selected', x === b ? 'true' : 'false');
        });
    }));

    // Zaznaczone podwyżki : liczba i efekt na miesiąc pod przyciskiem.
    const picks = () => Array.from(app.querySelectorAll('[data-cp-pick]'));
    const refresh = () => {
        const on = picks().filter((x) => x.checked);
        const sum = on.reduce((s, x) => s + Number(x.closest('tr').dataset.month || 0), 0);
        const count = app.querySelector('[data-cp-count]');
        const total = app.querySelector('[data-cp-sum]');
        const apply = app.querySelector('[data-cp-apply]');
        if (count) count.textContent = '(' + on.length + ')';
        if (total) total.textContent = '+' + fmt0(sum) + ' €';
        if (apply) apply.disabled = on.length === 0;
        const all = app.querySelector('[data-cp-all]');
        if (all) { all.checked = on.length === picks().length; all.indeterminate = on.length > 0 && on.length < picks().length; }
        app.querySelectorAll('[data-cp-bar]').forEach((bar) => {
            const pick = app.querySelector(`[data-cp-pick][value="${bar.dataset.cpBar}"]`);
            bar.classList.toggle('is-off', !!pick && !pick.checked);
        });
    };
    app.addEventListener('change', (e) => {
        if (e.target.matches('[data-cp-all]')) picks().forEach((x) => { x.checked = e.target.checked; });
        if (e.target.matches('[data-cp-all], [data-cp-pick]')) refresh();
    });

    const apply = app.querySelector('[data-cp-apply]');
    if (apply) apply.addEventListener('click', async () => {
        const on = picks().filter((x) => x.checked);
        if (!on.length) return;
        const name = main.querySelector('.cp-h').textContent.trim();
        const sum = on.reduce((s, x) => s + Number(x.closest('tr').dataset.month || 0), 0);
        const ok = await Swal.fire({
            icon: 'question', title: 'Nowe ceny dla ' + name + ' ?',
            html: `<p class="mb-1"><b>${on.length}</b> ${ceny(on.length)} w górę · efekt ok. <b>+${fmt0(sum)} €</b> / mies.</p>`
                + '<p class="small text-muted mb-0">Ceny obowiązują od razu w cenniku klienta (nowe zamówienia). Nigdy niżej niż obecne ; każda zmiana w historii cen produktu.</p>',
            showCancelButton: true, confirmButtonText: 'Zastosuj', cancelButtonText: 'Anuluj', confirmButtonColor: '#8e1f2f',
        });
        if (!ok.isConfirmed) return;
        apply.disabled = true;
        try {
            const res = await send('POST', '/ajax/category-margin/client-prices', {
                customer_id: Number(app.dataset.client), months: Number(app.dataset.months), product_ids: on.map((x) => Number(x.value))});
            await Swal.fire({icon: 'success', title: 'Zapisano', html: esc(res.message)
                + `<p class="mt-2 mb-0"><a href="${root}/ajax/clients/${app.dataset.client}/price-list" target="_blank" rel="noopener">Nowy cennik PDF klienta</a></p>`});
            location.reload();
        } catch (err) {
            Swal.fire({icon: 'error', title: 'Nie zapisano', text: err.message});
            apply.disabled = false;
        }
    });

    // Parametry reguły (administrator) : limit, zaokrąglenie, rabat.
    const paramsBtn = app.querySelector('[data-cp-params]');
    const modalEl = document.getElementById('cpParamsModal');
    if (paramsBtn && modalEl) {
        // Bootstrap ładuje się po skryptach strony : okno tworzone przy pierwszym kliknięciu.
        let modal = null;
        paramsBtn.addEventListener('click', () => (modal = modal || new window.bootstrap.Modal(modalEl)).show());
        modalEl.querySelector('[data-cp-params-form]').addEventListener('submit', async (e) => {
            e.preventDefault();
            const f = e.target;
            const btn = f.querySelector('[type=submit]');
            btn.disabled = true;
            try {
                await send('PUT', '/ajax/category-margin/client-prices/params', {
                    cap_pct: f.cap_pct.value, round_eur: f.round_eur.value, interval_days: f.interval_days.value, keep_discount: f.keep_discount.checked});
                location.reload();
            } catch (err) {
                Swal.fire({icon: 'error', title: 'Nie zapisano', text: err.message});
                btn.disabled = false;
            }
        });
    }
    refresh();
})();
