/*
 * Kreator « Zamknięcie miesiąca » (/month-close/RRRR-MM, audit 2 P14) : akcje kroków. Każda akcja woła istniejące
 * wywołanie (kalkulator kosztów, marże, karty produktów, raport miesięczny) albo jedno z dwóch własnych (rozpoczęcie
 * miesiąca, kopia kategorii kosztów) i odświeża stronę na tym samym kroku. Stany min. / maks. (krok 6) przez API modułu.
 */
(function () {
    'use strict';
    const box = document.getElementById('monthClose');
    if (!box) return;
    const month = box.dataset.month;
    const monthId = box.dataset.monthId;
    const label = (document.querySelector('.mc-summary strong') || {}).textContent || month;
    const nf = (v, d) => new Intl.NumberFormat('pl-PL', {minimumFractionDigits: d || 0, maximumFractionDigits: d || 0}).format(v ?? 0);

    const reloadAt = (anchor) => {
        history.replaceState(null, '', location.pathname + location.search + (anchor ? '#' + anchor : ''));
        window.setTimeout(() => location.reload(), 600);
    };
    const busy = (btn, on) => {
        btn.disabled = on;
        if (on) { btn.dataset.html = btn.innerHTML; btn.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> ' + btn.textContent.trim(); }
        else if (btn.dataset.html) btn.innerHTML = btn.dataset.html;
    };
    const run = async (btn, url, data, anchor, message) => {
        busy(btn, true);
        const res = await at.send(url, 'POST', data);
        const ok = res.ok && res.success !== false;
        const warnings = (res.warnings || []).join(' ');
        at.notify(ok, ok ? (message || res.message || 'Zapisano.') + (warnings ? ' ' + warnings : '') : (res.message || warnings || res.error || 'Błąd — nic nie zostało zmienione.'));
        if (ok) reloadAt(anchor); else busy(btn, false);
        return ok;
    };

    // Pasek kroków : kliknięcie przewija do karty kroku.
    box.querySelectorAll('[data-go]').forEach(li => {
        const go = () => document.getElementById(li.dataset.go)?.scrollIntoView({behavior: 'smooth', block: 'start'});
        li.addEventListener('click', go);
        li.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); } });
    });
    document.getElementById('mcMonth')?.addEventListener('change', e => { location.href = `${at.root}/month-close/${e.target.value}`; });

    const actions = {
        start: (btn) => run(btn, `/ajax/month-close/${month}/start`, {}, 'mc-cartons'),
        cartons: (btn) => run(btn, `/ajax/cost-calculator/${monthId}/wdt-cartons/sync`, {date_from: box.dataset.from, date_to: box.dataset.to}, 'mc-cartons',
            'Kartony działów pobrane z WDT.'),
        staff: (btn) => {
            const diff = Number(btn.dataset.diff || 0);
            if (diff && !window.confirm(`Koszt ${diff} os. w tym miesiącu różni się od budżetu (korekta miesiąca). Zastąpić kosztami z budżetu ?`)) return;
            return run(btn, `/ajax/month-close/${month}/start`, {}, 'mc-staff', 'Personel i koszty z budżetu.');
        },
        copy: (btn) => run(btn, `/ajax/month-close/${month}/copy/${btn.dataset.category}`, {}, btn.dataset.category === 'media' ? 'mc-media' : 'mc-fixed'),
        margins: (btn) => run(btn, '/ajax/category-margin/recalculate', {}, 'mc-recalc'),
        lock: (btn) => {
            const force = document.getElementById('mcForce');
            if (Number(btn.dataset.open || 0) && force && !force.checked) { at.notify(false, 'Zaznacz potwierdzenie zamknięcia mimo niezakończonych kroków.'); force.focus(); return; }
            if (!window.confirm(`Zamknąć ${label} ? Kartony, personel i koszty miesiąca zostaną zamrożone.`)) return;
            return run(btn, `/ajax/cost-calculator/${monthId}/lock`, {}, 'mc-close', 'Miesiąc zamknięty.');
        },
        report: (btn) => {
            const to = box.dataset.reportTo;
            if (!window.confirm(`Wysłać raport miesięczny za ${label} do : ${to} ?`)) return;
            return run(btn, '/dashboard/monthly/send', {month, lang: box.dataset.reportLang || 'pl', to}, 'mc-close');
        },
    };
    box.querySelectorAll('[data-mc-action]').forEach(btn => btn.addEventListener('click', () => actions[btn.dataset.mcAction]?.(btn)));

    // --- Krok 6 : stany min. / maks. ze sprzedaży (API modułu produkcji) ----------------------------------------
    const stocksEl = document.getElementById('mcStocks');
    if (!stocksEl || !window.TfbWorkshop) return;
    const {WorkshopApi, ui} = window.TfbWorkshop;
    const api = new WorkshopApi(box);
    const esc = ui.esc;
    const range = (a, b) => `${a === null || a === undefined ? '—' : nf(a, a % 1 ? 1 : 0)} → ${nf(b)}`;
    (async () => {
        let d;
        try {
            await api.init();
            d = await api.get('/produits/stock-suggestions');
        } catch (err) {
            stocksEl.innerHTML = `<p class="mc-hint mb-0">${esc(err.status === 403 ? 'Brak uprawnień do kart produktów (stany liczy kierownik produkcji).' : err.message)}</p>`;
            return;
        }
        const p = d.params || {};
        if (!d.eligible) {
            stocksEl.innerHTML = `<p class="mc-line">Brak produktów na magazynie ze sprzedażą w ostatnich ${esc(p.weeks)} tygodniach.</p>`;
            return;
        }
        const rows = d.changes.slice(0, 10).map(c => `<tr><td>${esc(c.name)}</td><td class="text-end">${range(c.old_min_cartons, c.new_min_cartons)}</td>
            <td class="text-end">${range(c.old_max_cartons, c.new_max_cartons)}</td></tr>`).join('');
        stocksEl.innerHTML = `<p class="mc-line">${d.changes.length ? `<strong>${d.changes.length}</strong> zmian z ${d.eligible} produktów` : `Stany ${d.eligible} produktów zgodne ze sprzedażą`}
                (sprzedaż ${esc(p.weeks)} tyg. : min. ${esc(nf(p.min_weeks, 1))} tyg., maks. ${esc(nf(p.max_weeks, 1))} tyg.)${d.no_sales ? ` · ${d.no_sales} MTS bez sprzedaży pominięte` : ''}.</p>
            ${rows ? `<table class="table table-sm mc-table"><thead><tr><th>Produkt</th><th class="text-end">Min. (kartony)</th><th class="text-end">Maks. (kartony)</th></tr></thead><tbody>${rows}</tbody></table>` : ''}
            ${d.changes.length > 10 ? `<p class="mc-hint">… i ${d.changes.length - 10} kolejnych na stronie Produkty.</p>` : ''}
            <div class="mc-actions"><button type="button" class="btn btn-sm btn-primary" data-mc-stocks><i class="bi bi-arrow-repeat"></i> Przelicz stany ${d.eligible} produktów</button>
                <a class="at-link" href="${esc(at.root)}/workshop/products">Produkty <i class="bi bi-arrow-right"></i></a></div>`;
        stocksEl.querySelector('[data-mc-stocks]').addEventListener('click', async (e) => {
            const btn = e.currentTarget;
            if (!window.confirm(`Zapisać stany min. / maks. ze sprzedaży dla ${d.eligible} produktów ?`)) return;
            busy(btn, true);
            try {
                const res = await api.post('/produits/stock-suggestions/apply', {});
                const failed = res.failed.length ? ` Pominięte : ${res.failed.map(f => f.name).join(', ')}.` : '';
                at.notify(!res.failed.length, `Zapisano stany ${res.applied} produktów.${failed}`);
                reloadAt('mc-recalc');
            } catch (err) {
                at.notify(false, err.message);
                busy(btn, false);
            }
        });
    })();
})();
