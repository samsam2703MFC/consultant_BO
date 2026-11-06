/*
 * Historia cen surowca za kg (l, szt) według dostawcy — wspólny widok (Zaopatrzenie › Faktury i « Ceny i dostawcy »,
 * Zakupy › Ceny surowców) : wykres (Chart.js, jedna linia na dostawcę ; punkt ● faktura, ■ przyjęcie, ◆ zmiana w
 * kartotece), dostawcy (ostatnia cena, min., maks., średnia ważona ilością, zmiana, ilość), wszystkie punkty w tabeli.
 * Dane : GET /supply/materials/{id}/prices (ceny za jednostkę bazową, factor = jednostki bazowe w kg / l).
 * TfbPriceHistory.open({title, lang, load: (months) => Promise<dane>}). Etykiety PL / FR wbudowane.
 */
(function () {
    'use strict';
    const L = {
        pl: {
            loading: 'Wczytywanie…', error: 'Nie udało się wczytać historii cen.', close: 'Zamknij', empty: 'Brak cen w tym okresie.',
            months: '{n} mies.', catalog: 'W kartotece', best: 'Najtaniej (12 mies.)', supplier: 'Dostawca', last: 'Ostatnia', day: 'Data',
            min: 'Min.', max: 'Maks.', avg: 'Średnia', change: 'Zmiana', qty: 'Kupiono', count: 'Ceny', current: 'bieżący',
            points: 'Wszystkie ceny ({n})', price: 'Cena', source: 'Źródło', kind: {invoice: 'faktura', receipt: 'przyjęcie', catalog: 'kartoteka'},
            ref: {offer: 'oferta', invoice: 'faktura', manual: 'ręcznie', new: 'nowy surowiec'}, no_supplier: 'bez dostawcy',
            legend: '● faktura · ■ przyjęcie · ◆ zmiana ceny w kartotece', chart: 'Cena {unit} według dostawcy', old: 'brak zakupów od 12 mies.',
            avg_help: 'średnia ważona kupioną ilością (bez ilości : zwykła)',
        },
        fr: {
            loading: 'Chargement…', error: "Impossible de charger l'historique des prix.", close: 'Fermer', empty: 'Aucun prix sur la période.',
            months: '{n} mois', catalog: 'Fiche matière', best: 'Le moins cher (12 mois)', supplier: 'Fournisseur', last: 'Dernier', day: 'Date',
            min: 'Min.', max: 'Max.', avg: 'Moyenne', change: 'Variation', qty: 'Acheté', count: 'Prix', current: 'actuel',
            points: 'Tous les prix ({n})', price: 'Prix', source: 'Source', kind: {invoice: 'facture', receipt: 'réception', catalog: 'fiche matière'},
            ref: {offer: 'offre', invoice: 'facture', manual: 'manuel', new: 'nouvelle matière'}, no_supplier: 'sans fournisseur',
            legend: '● facture · ■ réception · ◆ changement de prix de la fiche', chart: 'Prix {unit} par fournisseur', old: "pas d'achat depuis 12 mois",
            avg_help: 'moyenne pondérée par la quantité achetée (sans quantité : simple)',
        },
    };
    // Kolejność stała (paleta kategoryczna sprawdzona pod daltonizm) : kolor należy do dostawcy, nie do miejsca w rankingu.
    const COLORS = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'];
    const POINT = {invoice: 'circle', receipt: 'rect', catalog: 'rectRot'};
    const MONTHS = [6, 12, 24, 36];
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const fill = (s, p) => String(s).replace(/\{(\w+)\}/g, (m, k) => p && p[k] !== undefined ? p[k] : m);
    const num = (v, d, lang) => Number(v).toLocaleString(lang === 'fr' ? 'fr-FR' : 'pl-PL', {minimumFractionDigits: d, maximumFractionDigits: d});
    const fullDay = (s) => s ? `${s.slice(8, 10)}.${s.slice(5, 7)}.${s.slice(0, 4)}` : '—';
    let chart = null;

    const loadChart = () => window.Chart ? Promise.resolve() : new Promise((resolve, reject) => {
        const tag = document.createElement('script');
        tag.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js';
        tag.onload = resolve;
        tag.onerror = reject;
        document.head.appendChild(tag);
    });

    function style() {
        if (document.getElementById('tfbPhStyle')) return;
        const s = document.createElement('style');
        s.id = 'tfbPhStyle';
        s.textContent = `.tfb-ph-overlay{position:fixed;inset:0;background:rgba(20,20,20,.45);z-index:2000;display:flex;align-items:flex-start;justify-content:center;padding:4vh 16px;overflow:auto}
.tfb-ph-dialog{background:#fff;border-radius:12px;max-width:1100px;width:100%;box-shadow:0 10px 40px rgba(0,0,0,.2)}
.tfb-ph-dialog>header{display:flex;align-items:center;gap:8px;padding:14px 18px;border-bottom:1px solid #eee}
.tfb-ph-dialog>header h5{margin:0;font-size:1.05rem}.tfb-ph-dialog>header button{margin-left:auto}
.tfb-ph-body{padding:14px 18px}.tfb-ph-chart{position:relative;height:300px;margin:6px 0 4px}
.tfb-ph-kpis{display:flex;flex-wrap:wrap;gap:8px 24px;margin-bottom:8px}.tfb-ph-kpis div{font-size:.85rem;color:#52514e}.tfb-ph-kpis strong{display:block;font-size:1.05rem;color:#0b0b0b}
.tfb-ph-swatch{display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:6px;vertical-align:middle}
.tfb-ph-legend{font-size:.8rem;color:#52514e}.tfb-ph-old{opacity:.65}`;
        document.head.appendChild(s);
    }

    function open(opts) {
        style();
        const lang = opts.lang === 'fr' ? 'fr' : 'pl';
        const T = L[lang];
        const box = document.createElement('div');
        box.className = 'tfb-ph-overlay';
        box.setAttribute('role', 'dialog');
        box.setAttribute('aria-modal', 'true');
        box.innerHTML = `<div class="tfb-ph-dialog"><header><i class="bi bi-graph-up"></i><h5 id="tfbPhTitle">${esc(opts.title || '')}</h5>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-ph-close>${esc(T.close)}</button></header><div class="tfb-ph-body" data-ph-body></div></div>`;
        box.setAttribute('aria-labelledby', 'tfbPhTitle');
        document.body.appendChild(box);
        const close = () => { if (chart) { chart.destroy(); chart = null; } box.remove(); document.removeEventListener('keydown', onKey); };
        const onKey = (e) => { if (e.key === 'Escape') close(); };
        document.addEventListener('keydown', onKey);
        box.addEventListener('click', (e) => { if (e.target === box || e.target.closest('[data-ph-close]')) close(); });
        box.querySelector('[data-ph-close]').focus();
        const body = box.querySelector('[data-ph-body]');
        let months = opts.months || 24;
        const show = async () => {
            body.innerHTML = `<p class="text-muted mb-0">${esc(T.loading)}</p>`;
            try {
                const d = await opts.load(months);
                render(body, d, lang, (m) => { months = m; show(); });
            } catch (e) {
                body.innerHTML = `<p class="text-danger mb-0">${esc(T.error)}</p>`;
            }
        };
        show();
        return {close};
    }

    function render(body, d, lang, onMonths) {
        const T = L[lang];
        const m = d.material;
        const per = (p) => p === null || p === undefined ? '—' : `${num(p * m.factor, 2, lang)} zł/${m.unit}`;
        const qty = (v) => v === null || v === undefined ? '—' : `${num(v / m.factor, m.factor > 1 ? 1 : 0, lang)} ${m.unit}`;
        const pct = (v) => v === null || v === undefined ? '—' : `${v > 0 ? '+' : ''}${num(v, 1, lang)} %`;
        const name = (s) => s.supplier || T.no_supplier;
        const color = new Map(d.suppliers.map((s, k) => [s.supplier_id ?? 0, COLORS[k % COLORS.length]]));
        const kpis = [`<div>${esc(T.catalog)}<strong>${esc(per(m.catalog_price))}</strong>${m.current_supplier ? esc(m.current_supplier) : ''}</div>`];
        if (d.best) kpis.push(`<div>${esc(T.best)}<strong>${esc(per(d.best.price))}</strong>${esc(d.best.supplier || T.no_supplier)} · ${esc(fullDay(d.best.day))}</div>`);
        const switcher = onMonths ? `<div class="btn-group btn-group-sm ms-auto" role="group">${MONTHS.map(x => `<button type="button" class="btn ${x === d.months ? 'btn-primary' : 'btn-outline-primary'}" data-ph-months="${x}">${esc(fill(T.months, {n: x}))}</button>`).join('')}</div>` : '';
        if (!d.points.length) {
            body.innerHTML = `<div class="d-flex flex-wrap align-items-start gap-2"><div class="tfb-ph-kpis">${kpis.join('')}</div>${switcher}</div><p class="text-muted mb-0">${esc(T.empty)}</p>`;
            bindMonths(body, onMonths);
            return;
        }
        const suppliers = `<div class="table-responsive"><table class="table table-sm align-middle mb-2"><thead><tr><th>${esc(T.supplier)}</th>
                <th class="text-end">${esc(T.last)}</th><th>${esc(T.day)}</th><th class="text-end">${esc(T.min)}</th><th class="text-end">${esc(T.max)}</th>
                <th class="text-end" title="${esc(T.avg_help)}">${esc(T.avg)}</th><th class="text-end">${esc(T.change)}</th><th class="text-end">${esc(T.qty)}</th><th class="text-end">${esc(T.count)}</th></tr></thead>
            <tbody>${d.suppliers.map(s => `<tr class="${s.recent ? '' : 'tfb-ph-old'}" ${s.recent ? '' : `title="${esc(T.old)}"`}>
                <td><span class="tfb-ph-swatch" style="background:${color.get(s.supplier_id ?? 0)}"></span><strong>${esc(name(s))}</strong>${s.current ? ` <span class="badge bg-light text-dark">${esc(T.current)}</span>` : ''}</td>
                <td class="text-end text-nowrap"><strong>${esc(per(s.last_price))}</strong></td><td class="text-nowrap">${esc(fullDay(s.last_day))}</td>
                <td class="text-end text-nowrap">${esc(per(s.min))}</td><td class="text-end text-nowrap">${esc(per(s.max))}</td><td class="text-end text-nowrap">${esc(per(s.avg))}</td>
                <td class="text-end text-nowrap">${esc(pct(s.change_pct))}</td><td class="text-end text-nowrap">${esc(qty(s.qty))}</td><td class="text-end">${s.count}</td></tr>`).join('')}</tbody></table></div>`;
        const refText = (p) => p.kind === 'catalog' ? (T.ref[p.ref] || p.ref || '') : (p.ref || '');
        const points = `<details><summary class="small">${esc(fill(T.points, {n: d.points.length}))}</summary><div class="table-responsive"><table class="table table-sm align-middle mb-0 mt-2"><thead><tr>
                <th>${esc(T.day)}</th><th>${esc(T.supplier)}</th><th class="text-end">${esc(T.price)}</th><th class="text-end">${esc(T.qty)}</th><th>${esc(T.source)}</th></tr></thead>
            <tbody>${d.points.slice().reverse().map(p => `<tr><td class="text-nowrap">${esc(fullDay(p.day))}</td><td>${esc(name(p))}</td><td class="text-end text-nowrap">${esc(per(p.price))}</td>
                <td class="text-end text-nowrap">${esc(qty(p.qty))}</td><td>${esc(T.kind[p.kind])}${refText(p) ? ` · <span class="text-muted">${esc(refText(p))}</span>` : ''}${p.user ? ` · <span class="text-muted">${esc(p.user)}</span>` : ''}</td></tr>`).join('')}</tbody></table></div></details>`;
        body.innerHTML = `<div class="d-flex flex-wrap align-items-start gap-2"><div class="tfb-ph-kpis">${kpis.join('')}</div>${switcher}</div>
            <div class="tfb-ph-chart"><canvas aria-label="${esc(fill(T.chart, {unit: '/' + m.unit}))}" role="img"></canvas></div>
            <p class="tfb-ph-legend mb-2">${esc(T.legend)}</p>${suppliers}${points}`;
        bindMonths(body, onMonths);
        loadChart().then(() => draw(body.querySelector('canvas'), d, color, per, name, T, lang)).catch(() => { body.querySelector('.tfb-ph-chart').remove(); });
    }

    function bindMonths(body, onMonths) {
        body.querySelectorAll('[data-ph-months]').forEach(b => b.addEventListener('click', () => onMonths(Number(b.dataset.phMonths))));
    }

    function draw(canvas, d, color, per, name, T, lang) {
        if (!canvas) return;
        const m = d.material;
        const ts = (day) => new Date(day + 'T12:00:00').getTime();
        const datasets = d.suppliers.map(s => {
            const pts = d.points.filter(p => (p.supplier_id ?? 0) === (s.supplier_id ?? 0));
            const c = color.get(s.supplier_id ?? 0);
            return {label: name(s), data: pts.map(p => ({x: ts(p.day), y: Math.round(p.price * m.factor * 100) / 100, p})), borderColor: c, backgroundColor: c,
                borderWidth: 2, pointRadius: 4, pointHoverRadius: 6, pointBorderColor: '#fff', pointBorderWidth: 1.5,
                pointStyle: pts.map(p => POINT[p.kind] || 'circle'), tension: 0, stepped: false};
        });
        // Oś czasu : marginesy po bokach ; krótki okres — dzień.miesiąc, dłuższy — miesiąc.rok.
        const xs = d.points.map(p => ts(p.day));
        const minX = Math.min(...xs), maxX = Math.max(...xs);
        const span = Math.max(maxX - minX, 14 * 864e5);
        const short = span < 120 * 864e5;
        const two = (v) => String(v).padStart(2, '0');
        if (chart) chart.destroy();
        chart = new window.Chart(canvas, {
            type: 'line',
            data: {datasets},
            options: {
                responsive: true, maintainAspectRatio: false, interaction: {mode: 'nearest', intersect: false},
                scales: {
                    x: {type: 'linear', min: minX - span * 0.05, max: maxX + span * 0.05, grid: {display: false},
                        ticks: {maxTicksLimit: 8, callback: (v) => { const x = new Date(v); return short ? `${two(x.getDate())}.${two(x.getMonth() + 1)}` : `${two(x.getMonth() + 1)}.${String(x.getFullYear()).slice(2)}`; }}},
                    y: {ticks: {callback: (v) => `${num(v, 2, lang)} zł`}, grid: {color: 'rgba(0,0,0,.06)'}},
                },
                plugins: {
                    legend: {position: 'bottom', labels: {usePointStyle: true, pointStyle: 'circle', boxWidth: 8}},
                    tooltip: {callbacks: {
                        title: (items) => fullDay(items[0].raw.p.day),
                        label: (c) => `${c.dataset.label} : ${per(c.raw.p.price)} — ${T.kind[c.raw.p.kind]}${c.raw.p.ref ? ' ' + (c.raw.p.kind === 'catalog' ? (T.ref[c.raw.p.ref] || c.raw.p.ref) : c.raw.p.ref) : ''}`,
                    }},
                },
            },
        });
    }

    window.TfbPriceHistory = {open, render};
})();
