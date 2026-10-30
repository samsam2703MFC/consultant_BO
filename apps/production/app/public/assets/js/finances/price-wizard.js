/*
 * Kreator cen [218] (Finanse › Marże i koszty › Kreator cen, makieta zatwierdzona) :
 *  1. koszty i marże po fakturach (przeliczenie receptur, marża przed → po, przyczyny),
 *  2. nowe ceny standardowe (marża bazowa + współczynnik surowców kategorii, nigdy niżej, limit +X %, zaokrąglenie),
 *  3. publikacja od daty (ceny klientów, strona franczyzy, e-mail z cennikiem przed datą) + lista publikacji.
 * Kroki w adresie (#step=1…3). Serwer liczy wszystko od nowa przy podglądzie i planowaniu.
 */
(function () {
    'use strict';
    const app = document.getElementById('priceWizardApp');
    if (!app) return;
    const root = app.dataset.root;
    const body = app.querySelector('[data-pw-body]');
    let boot = {};
    try { boot = JSON.parse(document.getElementById('priceWizardBoot').textContent || '{}'); } catch (e) { boot = {}; }

    const S = {step: 1, costs: null, prices: null, preview: null, pubs: null, view1: 'cat', filter: 'todo', q: '', open: {}, sel: {}, price: {},
        manual: {}, rules: {}, date: null, notify: true, exclude: {}, busy: false, done: null};

    // ── Pomocnicze ─────────────────────────────────────────────────────────────────────────────────────────
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const f = (v, d = 0) => v === null || v === undefined || isNaN(v) ? '—' : Number(v).toLocaleString('pl-PL', {minimumFractionDigits: d, maximumFractionDigits: d, useGrouping: Math.abs(v) >= 10000});
    const sg = (v, d = 1) => (v > 0.00001 ? '+' : (v < -0.00001 ? '−' : '')) + f(Math.abs(v), d);
    const dmy = (iso) => iso ? String(iso).slice(8, 10) + '.' + String(iso).slice(5, 7) + '.' + String(iso).slice(0, 4) : '—';
    const dm = (iso) => iso ? String(iso).slice(8, 10) + '.' + String(iso).slice(5, 7) : '—';
    const hm = (iso) => iso ? String(iso).slice(11, 16) : '';
    const DAYS = ['nd.', 'pon.', 'wt.', 'śr.', 'czw.', 'pt.', 'sob.'];
    const day = (iso) => DAYS[new Date(iso + 'T12:00:00').getDay()] + ' ' + dm(iso);
    const isoAdd = (iso, n) => { const d = new Date(iso + 'T12:00:00'); d.setDate(d.getDate() + n); return d.toISOString().slice(0, 10); };
    const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    const plural = (n, one, few, many) => n === 1 ? one : ([2, 3, 4].includes(n % 10) && ![12, 13, 14].includes(n % 100) ? few : many);
    const ceny = (n) => plural(n, 'cena', 'ceny', 'cen');
    const sklepy = (n) => n + ' ' + plural(n, 'sklep', 'sklepy', 'sklepów');
    const klientow = (n) => n + ' ' + plural(n, 'klient', 'klientów', 'klientów');
    const surowce = (n) => n + ' ' + plural(n, 'surowiec zmienił cenę', 'surowce zmieniły cenę', 'surowców zmieniło cenę');
    const num = (s) => { const v = parseFloat(String(s ?? '').replace(/\s/g, '').replace(',', '.')); return isNaN(v) ? null : v; };
    const roundUp = (v, step) => Math.round(Math.ceil(Math.round(v / step * 1e6) / 1e6) * step * 100) / 100;

    async function api(method, url, data) {
        const opt = {method, credentials: 'same-origin', headers: {'Accept': 'application/json'}};
        if (data !== undefined) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(data); }
        const res = await fetch(root + url, opt);
        const out = await res.json().catch(() => ({message: 'Błąd serwera (' + res.status + ').'}));
        if (!res.ok || out.ok === false) throw new Error(out.message || out.error || 'Błąd serwera (' + res.status + ').');
        return out;
    }
    const toast = (icon, text) => window.Swal ? Swal.fire({icon, text, timer: icon === 'success' ? 2600 : undefined, showConfirmButton: icon !== 'success'}) : alert(text);
    async function busy(fn) {
        if (S.busy) return;
        S.busy = true;
        body.classList.add('kw-busy');
        try { await fn(); } catch (e) { toast('error', e.message); } finally { S.busy = false; body.classList.remove('kw-busy'); }
    }

    // ── Kroki ────────────────────────────────────────────────────────────────────────────────────────────
    const STEPS = [['Koszty i marże', 'przelicz po fakturach'], ['Nowe ceny', 'marża bazowa + współczynnik kategorii'], ['Publikacja', 'od daty, na stronie i u klientów']];
    function steps() {
        const planned = (S.pubs || []).filter((p) => p.status === 'planned');
        return `<ul class="kw-steps">${STEPS.map(([a, b], i) => `<li class="${i + 1 < S.step ? 'done' : (i + 1 === S.step ? 'cur' : '')}">
            <button type="button" data-step="${i + 1}"><b>${i + 1 < S.step ? '✓' : i + 1}</b><span>${a}<small>${b}</small></span></button></li>`).join('')}</ul>
            ${planned.length ? `<div class="pw-planned"><span class="kw-badge new">Zaplanowane : ${planned.map((p) => 'od ' + dmy(p.effective_date) + ' (' + p.products + ' ' + ceny(+p.products) + ')').join(' · ')}</span>
            <button type="button" class="kw-btn ghost kw-sm" data-go-pubs>Publikacje →</button></div>` : ''}`;
    }
    function go(step) {
        S.step = Math.max(1, Math.min(3, step));
        if (S.step < 3 && S.done) { S.done = null; S.preview = null; }
        if (S.step === 3 && !S.done) S.preview = null;
        if (location.hash !== '#step=' + S.step) history.replaceState(null, '', '#step=' + S.step);
        load();
    }

    async function load() {
        render();
        try {
            if (S.pubs === null) S.pubs = (await api('GET', '/ajax/price-wizard/publications')).data;
            if (S.step === 1 && !S.costs) S.costs = (await api('GET', '/ajax/price-wizard/costs')).data;
            if (S.step >= 2 && !S.prices) setPrices((await api('GET', '/ajax/price-wizard/prices')).data);
            if (S.step === 3 && !S.done) {
                const items = selectedItems();
                if (!items.length) { S.preview = {error: 'Nie wybrano żadnej nowej ceny — wróć do kroku 2.'}; }
                else {
                    try { S.preview = (await api('POST', '/ajax/price-wizard/preview', {items})).data; }
                    catch (e) { S.preview = {error: e.message}; }
                    if (S.preview && !S.preview.error && !S.date) S.date = S.preview.default_date;
                }
            }
        } catch (e) {
            body.innerHTML = steps() + `<div class="kw-card"><div class="kw-note bad">${esc(e.message)}</div></div>`;
            return;
        }
        render();
    }

    function render() {
        let html = steps();
        if (S.step === 1) html += S.costs ? step1() : '<div class="kw-card"><p class="at-empty">Ładowanie kosztów i marż…</p></div>';
        if (S.step === 2) html += S.prices ? step2() : '<div class="kw-card"><p class="at-empty">Ładowanie cen…</p></div>';
        if (S.step === 3) html += S.preview ? step3() : '<div class="kw-card"><p class="at-empty">Liczenie cen klientów…</p></div>';
        if (S.step === 3) html += pubsCard();
        body.innerHTML = html;
    }

    // ── Krok 1 : koszty i marże ──────────────────────────────────────────────────────────────────────────
    function step1() {
        const c = S.costs;
        const t = c.totals;
        const inv = c.invoices;
        const run = c.last_run;
        const gate = inv.invoices > 0
            ? `<a class="kw-badge warn" href="${root}/workshop/supply#tab=invoices&inv=wizard">Faktury : ${inv.invoices} otwartych · ${inv.todo} pozycji do sprawdzenia →</a>`
            : '<span class="kw-badge ok">✓ Faktury : wszystkie pozycje sprawdzone</span>';
        const pp = t.margin_after !== null && t.margin_before !== null ? t.margin_after - t.margin_before : 0;
        const winTxt = c.window.from ? 'od ' + dmy(c.window.from) + ' ' + hm(c.window.from) : '';
        let table = '';
        if (S.view1 === 'cat') {
            table = `<div class="table-responsive"><table class="kw-t"><thead><tr><th>Kategoria</th><th class="r">Produkty</th><th class="r">Zmiana kosztu</th><th class="r">Marża przed → po</th><th class="r">Marża bazowa</th><th>Do celu</th><th class="r">Efekt / mies.</th></tr></thead><tbody>
            ${c.categories.map((k) => `<tr><td><b>${esc(k.name)}</b></td><td class="r">${k.products}</td><td class="r">${k.changed || '—'}</td>
                <td class="r">${f(k.before, 1)} %<span class="pw-arrow">→</span><b>${f(k.after, 1)} %</b></td><td class="r">${k.target > 0 ? f(k.target, 0) + ' %' : '—'}</td>
                <td>${k.gap === null ? '<span class="kw-badge">brak celu</span>' : (k.gap >= 0 ? '<span class="kw-badge ok">✓ powyżej celu</span>' : `<span class="kw-badge ${k.gap < -3 ? 'bad' : 'warn'}">${sg(k.gap)} pp</span>`)}</td>
                <td class="r ${k.month_eur < 0 ? 'kw-up' : (k.month_eur > 0 ? 'kw-down' : '')}">${k.month_eur ? sg(k.month_eur, 0) + ' €' : '—'}</td></tr>`).join('')}
            </tbody></table></div>`;
        } else if (S.view1 === 'prod') {
            table = c.products.length ? `<div class="table-responsive"><table class="kw-t"><thead><tr><th>Produkt</th><th>Przyczyna (ceny surowców)</th><th class="r">Koszt surowców przed → po</th><th class="r">Zmiana</th><th class="r">Kartony / mies.</th><th class="r">Marża przed → po</th><th class="r">Efekt / mies.</th></tr></thead><tbody>
            ${c.products.map((p) => `<tr><td><b>${esc(p.name)}</b><small>${esc(p.category)}</small></td>
                <td>${p.reasons.length ? p.reasons.map((r) => `<span class="kw-badge ${r.pct > 0 ? 'warn' : 'ok'}">${esc(r.name)} ${r.pct !== null ? sg(r.pct) + ' %' : ''}</span>`).join(' ') : '<span class="text-muted">receptura / przelicznik</span>'}</td>
                <td class="r">${f(p.old_eur, 2)}<span class="pw-arrow">→</span><b>${f(p.new_eur, 2)} €</b></td><td class="r ${p.pct > 0 ? 'kw-up' : 'kw-down'}">${p.pct !== null ? sg(p.pct) + ' %' : '—'}</td>
                <td class="r">${f(p.qty, 1)}</td><td class="r">${f(p.margin_before, 1)}<span class="pw-arrow">→</span>${f(p.margin_after, 1)} %</td>
                <td class="r ${p.month_eur < 0 ? 'kw-up' : 'kw-down'}">${p.month_eur ? sg(p.month_eur, 0) + ' €' : '—'}</td></tr>`).join('')}
            </tbody></table></div>` : '<p class="at-empty">Ostatnie przeliczenie nie zmieniło kosztu żadnego produktu.</p>';
        } else {
            table = c.materials.list.length ? `<div class="table-responsive"><table class="kw-t"><thead><tr><th>Surowiec</th><th class="r">Cena za jedn. bazową przed → po</th><th class="r">Zmiana</th><th>Ostatnia zmiana</th></tr></thead><tbody>
            ${c.materials.list.map((m) => `<tr><td><b>${esc(m.name)}</b></td><td class="r">${m.old !== null ? f(m.old, 4) : '—'}<span class="pw-arrow">→</span><b>${f(m.new, 4)} zł</b></td>
                <td class="r ${m.pct > 0 ? 'kw-up' : 'kw-down'}">${m.pct !== null ? sg(m.pct) + ' %' : 'nowy'}</td><td>${dmy(m.at)} ${hm(m.at)}</td></tr>`).join('')}
            </tbody></table></div>` : '<p class="at-empty">Brak zmian cen surowców w tym okresie.</p>';
        }
        return `<div class="kw-card"><h5>Koszty i marże po fakturach ${gate}${run ? `<span class="kw-badge">ostatnie przeliczenie ${dmy(run.run_at)} ${hm(run.run_at)}${run.by_name ? ' · ' + esc(run.by_name) : ''}</span>` : '<span class="kw-badge warn">jeszcze nie przeliczono</span>'}</h5>
            <p class="kw-lead">Najpierw zatwierdź faktury (kreator faktur) — nowe ceny surowców trafiają do kartoteki. Potem jedno kliknięcie : koszt surowców wszystkich receptur po bieżących cenach (najwyższa zatwierdzona cena), koszt pełny (działy + logistyka) i marża przy obecnych cenach sprzedaży. Ceny sprzedaży jeszcze się nie zmieniają.</p>
            ${c.window.pending ? `<div class="kw-note warn">${surowce(c.materials.up + c.materials.down)} po ostatnim przeliczeniu (${c.materials.up} w górę, ${c.materials.down} w dół) — przelicz koszty, zanim ustalisz nowe ceny.</div>` : ''}
            <div class="kw-tiles"><div><span>Faktury z cenami ${esc(winTxt)}</span><b>${c.invoices_with_prices}</b><small>ceny surowców : ${c.materials.up} w górę · ${c.materials.down} w dół</small></div>
                <div><span>Receptury przeliczone</span><b>${run ? f(run.recipes) : '—'}</b><small>${run ? dmy(run.run_at) + ' ' + hm(run.run_at) : 'brak przeliczenia'}</small></div>
                <div class="${t.changed ? 'todo' : ''}"><span>Produkty ze zmianą kosztu</span><b>${t.changed}</b><small>z ${t.products} z ceną</small></div>
                <div class="${pp < -0.05 ? 'todo' : ''}"><span>Marża średnia</span><b>${f(t.margin_after, 1)} %</b><small>${Math.abs(pp) >= 0.05 ? sg(pp) + ' pp · było ' + f(t.margin_before, 1) + ' %' : 'bez zmiany'}</small></div>
                <div class="${t.month_eur < 0 ? 'todo' : ''}"><span>Efekt na marży / mies.</span><b>${t.month_eur ? sg(t.month_eur, 0) + ' €' : '—'}</b><small>przy obecnych cenach</small></div></div>
            <div class="kw-bar"><span class="at-seg">${[['cat', 'Kategorie'], ['prod', 'Produkty (' + c.products.length + ')'], ['mat', 'Surowce (' + c.materials.list.length + ')']]
                .map(([k, l]) => `<button type="button" class="${S.view1 === k ? 'is-on' : ''}" data-view1="${k}">${l}</button>`).join('')}</span>
                <span class="pw-grow"></span><button type="button" class="kw-btn pri" data-recalc>↻ Przelicz koszty i marże</button></div>
            ${table}
            <div class="kw-foot"><a class="kw-btn ghost" href="${root}/finances/category-margin">Marża kategorii (pełny widok)</a><button type="button" class="kw-btn pri" data-step="2">Dalej : nowe ceny →</button></div></div>`;
    }

    // ── Krok 2 : nowe ceny ───────────────────────────────────────────────────────────────────────────────
    const SELECTABLE = ['up', 'cap', 'recent'];
    function setPrices(data) {
        S.prices = data;
        S.sel = {}; S.price = {}; S.manual = {}; S.rules = {};
        const first = data.categories.find((c) => c.products.some((p) => p.status === 'up' || p.status === 'cap'));
        if (first && !Object.keys(S.open).length) S.open[first.id] = true;
        data.categories.forEach((c) => c.products.forEach((p) => {
            S.price[p.id] = p.new;
            if (p.status === 'up' || p.status === 'cap') S.sel[p.id] = true;
        }));
        S.preview = null;
    }
    const allProducts = () => S.prices.categories.flatMap((c) => c.products.map((p) => ({...p, cat: c})));
    function selectedItems() {
        if (!S.prices) return [];
        return allProducts().filter((p) => S.sel[p.id] && SELECTABLE.includes(p.status) && (S.price[p.id] ?? 0) > p.cur + 0.004)
            .map((p) => ({product_id: p.id, price: S.price[p.id], manual: !!S.manual[p.id]}));
    }
    function statusBadge(p) {
        const P = S.prices.params;
        if (p.status === 'keep') return '<span class="kw-badge ok">bez zmian — cena już wyższa</span>';
        if (p.status === 'none') return '<span class="kw-badge">brak marży i współczynnika</span>';
        if (p.status === 'planned') return `<span class="kw-badge new">zaplanowana od ${dmy(p.planned.date)}</span>`;
        if (p.status === 'recent') return `<span class="kw-badge warn">podniesiona ${dmy(p.recent)} — kolejna po ${P.interval_days} dniach</span>`;
        if (p.status === 'cap') return `<span class="kw-badge warn">limit +${f(P.cap_pct, 0)} % · do celu ${sg(p.gap_pct)} %</span>`;
        return '<span class="kw-badge new">do ceny celu</span>';
    }
    function catStats(c) {
        let n = 0, month = 0;
        c.products.forEach((p) => {
            if (!S.sel[p.id] || !SELECTABLE.includes(p.status)) return;
            const d = (S.price[p.id] ?? p.cur) - p.cur;
            if (d <= 0.004) return;
            n++; month += d * p.qty;
        });
        return {n, month};
    }
    function filterOk(p) {
        if (S.q && !p.name.toLowerCase().includes(S.q)) return false;
        if (S.filter === 'todo') return SELECTABLE.includes(p.status);
        if (S.filter === 'same') return !SELECTABLE.includes(p.status);
        return true;
    }
    function productRow(p, c) {
        const P = S.prices.params;
        const selectable = SELECTABLE.includes(p.status);
        const price = S.price[p.id] ?? p.cur;
        const d = price - p.cur;
        const over = price > p.max + 0.004;
        const mNew = price > 0 ? (price - p.full) / price * 100 : null;
        const coef = c.coef_effective;
        return `<tr class="pw-sub ${S.sel[p.id] && selectable ? '' : 'pw-off'}" data-pid="${p.id}"><td>${esc(p.name)}</td>
            <td class="r">${f(p.food, 2)}<small>${coef ? '× ' + f(coef, 1) + ' = ' + f(p.by_coef, 2) : '—'}</small></td>
            <td class="r">${f(p.full, 2)}<small>${c.margin > 0 ? '÷ (1 − ' + f(c.margin, 0) + ' %) = ' + f(p.by_margin, 2) : 'bez marży bazowej'}</small></td>
            <td class="r">${f(p.cur, 2)} €<small>marża ${f(p.margin_now, 1)} %</small></td>
            <td class="r">${selectable ? `<input class="kw-in pw-price ${S.manual[p.id] ? 'pw-edit' : ''} ${over ? 'pw-bad' : ''}" inputmode="decimal" value="${f(price, 2)}" data-price="${p.id}" aria-label="Nowa cena ${esc(p.name)}">` : `<b>${f(p.cur, 2)}</b>`}
                <small>${over ? `<span class="kw-up">najwyżej ${f(p.max, 2)} (+${f(P.cap_pct, 0)} %)</span>` : 'marża ' + f(mNew, 1) + ' %'}</small></td>
            <td class="r ${d > 0.004 ? 'kw-up' : ''}">${d > 0.004 ? sg(d / p.cur * 100) + ' %' : '—'}</td>
            <td>${statusBadge(p)}${S.manual[p.id] ? ' <span class="kw-badge">ręcznie</span>' : ''}</td>
            <td class="r">${selectable ? `<input type="checkbox" class="kw-box" data-pick="${p.id}" ${S.sel[p.id] ? 'checked' : ''} aria-label="Zmień cenę ${esc(p.name)}">` : ''}</td></tr>`;
    }
    function step2() {
        const D = S.prices;
        const P = D.params;
        const t = D.totals;
        const items = selectedItems();
        let rows = '';
        D.categories.forEach((c) => {
            const prods = c.products.filter(filterOk);
            if (!prods.length && (S.q || S.filter !== 'all')) return;
            const st = catStats(c);
            const r = S.rules[c.id] || {};
            const open = !!S.open[c.id] || !!S.q;
            rows += `<tr class="pw-cat ${open ? 'open' : ''}" data-cat="${c.id}"><td><button type="button" class="pw-tog" data-open="${c.id}" aria-expanded="${open}">${open ? '▾' : '▸'} <b>${esc(c.name)}</b></button><small>${c.products.length} produktów</small></td>
                <td class="r"><input class="kw-in pw-rule ${r.coef !== undefined ? 'pw-edit' : ''}" inputmode="decimal" data-rule-coef="${c.id}" value="${r.coef !== undefined ? esc(r.coef) : (c.coef !== null ? f(c.coef, 2) : '')}" placeholder="× ${f(P.material_coef_default, 1)}" aria-label="Współczynnik surowców ${esc(c.name)}"></td>
                <td class="r"><input class="kw-in pw-rule ${r.margin !== undefined ? 'pw-edit' : ''}" inputmode="decimal" data-rule-margin="${c.id}" value="${r.margin !== undefined ? esc(r.margin) : f(c.margin, 0)}" aria-label="Marża bazowa ${esc(c.name)} (%)"> %</td>
                <td></td><td></td><td class="r"><b>${st.n}</b> ${st.n === 1 ? 'zmiana' : 'zmian'}</td><td class="r ${st.month > 0 ? 'kw-down' : ''}">${st.month > 0 ? '+' + f(st.month, 0) + ' € / mies.' : ''}</td><td></td></tr>`;
            if (open) rows += prods.map((p) => productRow(p, c)).join('');
        });
        // Podsumowanie wybranych : średnia podwyżka, marża (wszystkie produkty z ceną), efekt / mies., Belgia.
        const all = allProducts();
        const picked = items.map((i) => all.find((p) => p.id === i.product_id));
        const avgPct = picked.length ? picked.reduce((s, p) => s + (S.price[p.id] - p.cur) / p.cur * 100, 0) / picked.length : 0;
        const month = picked.reduce((s, p) => s + (S.price[p.id] - p.cur) * p.qty, 0);
        const pickedIds = new Set(items.map((i) => i.product_id));
        const mNow = all.reduce((s, p) => s + p.margin_now, 0) / Math.max(1, all.length);
        const mNew = all.reduce((s, p) => { const pr = pickedIds.has(p.id) ? S.price[p.id] : p.cur; return s + (pr - p.full) / pr * 100; }, 0) / Math.max(1, all.length);
        const be = picked.filter((p) => p.be !== null).map((p) => ({name: p.name, coef: p.be * p.cur / S.price[p.id]})).filter((x) => x.coef < D.be_params.multiplier);
        const dirty = Object.keys(S.rules).length;
        const overAny = picked.some((p) => S.price[p.id] > p.max + 0.004);
        return `<div class="kw-card"><h5>Nowe ceny fabryczne <span class="kw-badge">cennik standardowy · EUR / karton</span>${boot.is_admin ? '<button type="button" class="kw-btn ghost kw-sm" data-params>⚙ Parametry reguły</button>' : ''}</h5>
            <p class="kw-lead">Każda kategoria ma marżę bazową (na koszt pełny) i współczynnik (na koszt surowców). Cena celu = wyższa z dwóch ; nowa cena nigdy niższa od obecnej, podwyżka najwyżej +${f(P.cap_pct, 0)} % naraz (reszta przy następnej zmianie), zaokrąglenie w górę do ${f(P.round_eur, 2)} €. Każdą cenę można poprawić ręcznie (w tych granicach).</p>
            <div class="pw-rule3"><div><b>Marża bazowa</b>koszt pełny ÷ (1 − marża) — pokrywa surowce, działy, logistykę i zysk</div><div><b>Współczynnik surowców</b>koszt surowców × współczynnik — minimum, gdy surowce drożeją szybciej niż reszta ; puste = domyślny × ${f(P.material_coef_default, 1)}</div>
                <div><b>Klienci</b>ceny równe standardowej idą za nią ; wynegocjowane rosną o ten sam % (rabat zachowany), ten sam limit +${f(P.cap_pct, 0)} %</div></div>
            <div class="kw-bar"><span class="at-seg">${[['todo', 'Do zmiany (' + (t.up + t.cap + t.recent) + ')'], ['all', 'Wszystkie (' + t.products + ')'], ['same', 'Bez zmian (' + (t.keep + t.none + t.planned) + ')']]
                .map(([k, l]) => `<button type="button" class="${S.filter === k ? 'is-on' : ''}" data-filter="${k}">${l}</button>`).join('')}</span>
                <input type="search" class="form-control pw-q" placeholder="Szukaj produktu…" value="${esc(S.q)}" data-q aria-label="Szukaj produktu">
                <button type="button" class="kw-btn" data-openall>${Object.keys(S.open).length ? 'Zwiń wszystkie' : 'Rozwiń wszystkie'}</button>
                <button type="button" class="kw-btn ${dirty ? 'pri' : ''}" data-save-rules ${dirty ? '' : 'disabled'}>Zapisz marże i współczynniki${dirty ? ' (' + dirty + ')' : ''}</button></div>
            <div class="table-responsive"><table class="kw-t pw-t"><thead><tr><th>Kategoria / produkt</th><th class="r">Współczynnik · surowce</th><th class="r">Marża bazowa · koszt pełny</th><th class="r">Cena obecna</th><th class="r">Nowa cena</th><th class="r">Zmiana</th><th>Reguła</th><th class="r"></th></tr></thead>
            <tbody>${rows || '<tr><td colspan="8" class="at-empty">Brak produktów dla tego filtra.</td></tr>'}</tbody></table></div>
            <div class="kw-note">Wybrane : <b>${items.length}</b> nowych cen standardowych${items.length ? ' (średnio ' + sg(avgPct) + ' %)' : ''} — marża średnia ${f(mNow, 1)} % → ${f(mNew, 1)} % ; obrót ${month > 0 ? '+' + f(month, 0) : '0'} € / mies. przy obecnej sprzedaży (wszyscy klienci). Ceny klientów : w kroku 3.</div>
            ${be.length ? `<div class="kw-note warn">Belgia (franczyza) : ${be.length} ${be.length === 1 ? 'produkt' : 'produktów'} po zmianie poniżej × ${f(D.be_params.multiplier, 1)} ceny w sklepie (${be.slice(0, 4).map((x) => esc(x.name) + ' × ' + f(x.coef, 2)).join(' · ')}${be.length > 4 ? ' …' : ''}) — sprawdź przed publikacją.</div>` : ''}
            ${dirty ? '<div class="kw-note warn">Marże lub współczynniki zmienione — zapisz, aby przeliczyć propozycje cen.</div>' : ''}
            <div class="kw-foot"><button type="button" class="kw-btn" data-step="1">← Koszty i marże</button><button type="button" class="kw-btn pri" data-step="3" ${items.length && !overAny && !dirty ? '' : 'disabled'}>Dalej : publikacja (${items.length} ${ceny(items.length)}) →</button></div></div>`;
    }

    // ── Krok 3 : publikacja ──────────────────────────────────────────────────────────────────────────────
    function step3() {
        if (S.done) {
            const D = S.done;
            return `<div class="kw-card"><h5>✓ Nowe ceny zaplanowane od ${dmy(D.date)}</h5>
                <p class="kw-lead">${D.products} ${D.products === 1 ? 'cena standardowa' : 'cen standardowych'} i ${D.client_prices} cen klientów zmienią się ${dmy(D.date)} o 00:05.
                ${D.emails ? `E-mail z cennikiem : ${klientow(D.emails)} (${dmy(D.notice_date)}).` : 'Bez e-maila do klientów.'} ${D.sites ? 'Strona franczyzy : ' + sklepy(D.sites) + ' w dniu zmiany.' : ''}
                Do ${dmy(isoAdd(D.date, -1))} publikację można anulować (lista poniżej).</p>
                <div class="kw-foot"><button type="button" class="kw-btn" data-step="1">Koszty i marże</button><button type="button" class="kw-btn pri" data-again>Nowe ceny pozostałych produktów →</button></div></div>`;
        }
        const V = S.preview;
        if (V.error) return `<div class="kw-card"><div class="kw-note bad">${esc(V.error)}</div><div class="kw-foot"><button type="button" class="kw-btn" data-step="2">← Nowe ceny</button></div></div>`;
        const P = V.params;
        const date = S.date || V.default_date;
        const min = today();
        const notice = isoAdd(date, -P.notice_days) < min ? min : isoAdd(date, -P.notice_days);
        const lead = Math.round((new Date(date + 'T12:00:00') - new Date(min + 'T12:00:00')) / 86400000);
        const clients = V.clients;
        const on = clients.filter((c) => !S.exclude[c.id]);
        const emails = on.filter((c) => c.email).length;
        const sites = on.filter((c) => c.franchise).length;
        const month = on.reduce((s, c) => s + c.month_eur, 0);
        const prices = on.reduce((s, c) => s + c.prices, 0);
        const d0 = new Date(min + 'T12:00:00');
        let nextMonth = new Date(d0.getFullYear(), d0.getMonth() + 1, 1, 12).toISOString().slice(0, 10);
        while (new Date(nextMonth + 'T12:00:00').getDay() !== 1) nextMonth = isoAdd(nextMonth, 1);
        const chips = [...new Set([V.default_date, isoAdd(V.default_date, 7), nextMonth])].sort();
        return `<div class="pw-pub">
            <div class="kw-card"><h5>Od kiedy obowiązują nowe ceny</h5>
                <p class="kw-lead">Ceny zmieniają się w ERP i cennik idzie na stronę franczyzy tego samego dnia o 00:05 — zamówienia z dostawą od tej daty po nowych cenach. Klienci dostają cennik wcześniej (${P.notice_days} dni przed).</p>
                <div class="pw-date"><input type="date" class="form-control pw-dateinput" min="${min}" value="${esc(date)}" data-date aria-label="Data zmiany cen">
                    ${chips.map((c) => `<button type="button" class="kw-chip ${c === date ? 'on' : ''}" data-chip="${c}">${day(c)}</button>`).join('')}</div>
                <div class="kw-note ${lead < P.notice_days ? 'warn' : ''}">Wyprzedzenie ${lead} ${lead === 1 ? 'dzień' : 'dni'}${lead < P.notice_days ? ` — krócej niż ${P.notice_days} dni : e-mail wyjdzie od razu` : ` (minimum ${P.notice_days})`}.</div>
                <label class="kw-check"><input type="checkbox" data-notify ${S.notify ? 'checked' : ''}> E-mail z cennikiem od tej daty do klientów (PDF w języku klienta, adres z Dystrybucji dokumentów)</label>
                <ul class="pw-time">
                    <li class="done"><b>${day(min)}</b><i></i><span>Zatwierdzenie kreatora<small>ceny zaplanowane, jeszcze nie w cenniku — można anulować do ${dmy(isoAdd(date, -1))}</small></span></li>
                    ${S.notify ? `<li><b>${day(notice)}</b><i></i><span>E-mail do klientów z cennikiem PDF (od ${dm(date)})<small>${klientow(emails)} · w języku klienta · kopia w Poczcie / Stan wysyłek</small></span></li>` : ''}
                    <li><b>${day(date)} 00:05</b><i></i><span>Nowe ceny w ERP + cennik na stronę franczyzy<small>ceny standardowe, ceny klientów, historia zmian cen produktu</small></span></li>
                    <li><b>${day(date)}</b><i></i><span>Potwierdzenie ze strony (wersja cennika)<small>błąd → ponowienie co 5 min, stan w publikacjach poniżej</small></span></li></ul></div>
            <div class="kw-card"><h5>Gdzie i komu <span class="kw-badge new">${on.length} z ${clients.length} klientów</span></h5>
                ${clients.length ? `<div class="table-responsive"><table class="kw-t"><thead><tr><th></th><th>Klient</th><th>Kanał</th><th class="r">Ceny</th><th class="r">Średnio</th><th class="r">Obrót / mies.</th><th></th></tr></thead><tbody>
                ${clients.map((c) => `<tr class="${S.exclude[c.id] ? 'pw-off' : ''}"><td><input type="checkbox" class="kw-box" data-client="${c.id}" ${S.exclude[c.id] ? '' : 'checked'} aria-label="${esc(c.name)}"></td>
                    <td><b>${esc(c.name)}</b><small>${c.franchise ? 'franczyza — cennik na stronę' : 'cennik klienta'}</small></td>
                    <td>${c.franchise ? '<span class="kw-badge ok">Strona franczyzy</span> ' : ''}${S.notify ? (c.email ? `<span class="kw-badge">E-mail ${esc(c.lang.toUpperCase())}</span>` : '<span class="kw-badge warn">brak adresu e-mail</span>') : ''}</td>
                    <td class="r">${c.prices}<small>${c.followers} za standardową</small></td><td class="r kw-up">${sg(c.avg_pct)} %</td><td class="r kw-down">${c.month_eur > 0 ? '+' + f(c.month_eur, 0) + ' €' : '—'}</td>
                    <td class="r"><button type="button" class="kw-btn ghost kw-sm" data-pdf="${c.id}" title="Podgląd cennika od ${dmy(date)}">PDF</button></td></tr>`).join('')}
                </tbody></table></div>` : '<p class="at-empty">Żaden klient nie ma tych produktów w cenniku — zmienią się tylko ceny standardowe.</p>'}
                <div class="pw-sum"><span>Ceny standardowe</span><b>${V.totals.products} ${V.totals.products === 1 ? 'produkt' : 'produktów'} · średnio ${sg(V.totals.avg_pct)} %</b>
                    <span>Ceny klientów</span><b>${prices} (wyłączeni klienci : ceny bez zmian)</b>
                    <span>Strona franczyzy / e-mail</span><b>${sites} / ${S.notify ? emails : 0}</b>
                    <span>Obrót / mies.</span><b class="kw-down">+${f(month, 0)} €</b></div>
                <div class="kw-foot"><button type="button" class="kw-btn" data-step="2">← Nowe ceny</button><button type="button" class="kw-btn pri" data-plan>Zaplanuj publikację na ${dmy(date)}</button></div></div>
        </div>`;
    }

    // ── Publikacje ───────────────────────────────────────────────────────────────────────────────────────
    function pubsCard() {
        const L = S.pubs || [];
        const badge = (p) => p.status === 'planned' ? '<span class="kw-badge new">zaplanowana</span>' : (p.status === 'applied'
            ? `<span class="kw-badge ok">✓ opublikowana ${dmy(p.applied_at)}</span>${+p.skipped ? ` <span class="kw-badge warn">${p.skipped} pominiętych (cena zmieniona)</span>` : ''}`
            : `<span class="kw-badge">anulowana${p.cancelled_name ? ' · ' + esc(p.cancelled_name) : ''}</span>`);
        return `<div class="kw-card" id="pwPubs"><h5>Zaplanowane i wysłane publikacje</h5>
            ${L.length ? `<div class="table-responsive"><table class="kw-t"><thead><tr><th>Od</th><th>Ceny</th><th>Kto</th><th>Strona franczyzy</th><th>E-maile</th><th class="r">Stan</th><th></th></tr></thead><tbody>
            ${L.map((p) => `<tr class="${p.status === 'cancelled' ? 'pw-off' : ''}"><td><b>${dmy(p.effective_date)}</b></td><td>${p.products} standardowych · ${p.client_prices} klientów<small>${p.avg_pct !== null ? 'średnio ' + sg(+p.avg_pct) + ' %' : ''}${p.month_eur !== null ? ' · +' + f(+p.month_eur, 0) + ' € / mies.' : ''}</small></td>
                <td>${esc(p.by_name || '—')}<small>${dmy(p.created_at)}</small></td>
                <td>${+p.sites ? (p.status === 'applied' ? `${p.sites_sent} / ${p.sites} potwierdzone${+p.sites_failed ? ` <span class="kw-badge bad">${p.sites_failed} bez połączenia</span>` : ''}` : sklepy(+p.sites)) : '—'}</td>
                <td>${+p.notify_email ? (+p.emails_queued ? p.emails_queued + ' / ' + p.emails + ' wysłane' : p.emails + ' · ' + dmy(p.notice_date)) : 'bez e-maila'}</td>
                <td class="r">${badge(p)}</td>
                <td class="r"><button type="button" class="kw-btn ghost kw-sm" data-detail="${p.id}">Szczegóły</button>${p.status === 'planned' ? `<button type="button" class="kw-btn ghost kw-sm" data-cancel="${p.id}">Anuluj</button>` : ''}</td></tr>`).join('')}
            </tbody></table></div>` : '<p class="at-empty">Jeszcze żadnej publikacji.</p>'}</div>`;
    }

    async function showDetail(id) {
        const d = (await api('GET', '/ajax/price-wizard/publications/' + id)).data;
        const p = d.publication;
        const st = (s) => ({applied: '✓', skipped: 'pominięta', planned: ''}[s] ?? s);
        const site = (c) => !+c.site ? '—' : ({sent: '✓ potwierdzony (v' + (c.site_version ?? '?') + ')', retry: 'ponowienie (' + c.site_attempts + ')', failed: 'błąd', no_site: 'brak połączenia'}[c.site_status] || 'czeka na ' + dmy(p.effective_date));
        const mail = (c) => !+c.email ? '—' : ({queued: '✓ ' + dmy(c.email_at), skipped: 'pominięty', failed: 'błąd'}[c.email_status] || 'czeka');
        const html = `<div class="pw-detail"><p>Od <b>${dmy(p.effective_date)}</b> · ${d.standard.length} cen standardowych</p>
            <table class="kw-t"><thead><tr><th>Produkt</th><th class="r">Obecna → nowa</th><th></th></tr></thead><tbody>
            ${d.standard.map((i) => `<tr><td>${esc(i.name)}</td><td class="r">${f(+i.old_price, 2)} → <b>${f(+i.new_price, 2)} €</b>${+i.manual ? ' <span class="kw-badge">ręcznie</span>' : ''}</td><td>${st(i.status)}</td></tr>`).join('')}</tbody></table>
            <table class="kw-t mt-3"><thead><tr><th>Klient</th><th class="r">Ceny</th><th>Strona</th><th>E-mail</th><th></th></tr></thead><tbody>
            ${d.clients.map((c) => `<tr><td>${esc(c.label)}</td><td class="r">${c.prices}</td><td title="${esc(c.site_note || '')}">${site(c)}</td><td title="${esc(c.email_note && c.email_status !== 'queued' ? c.email_note : '')}">${mail(c)}</td>
                <td><a href="${root}/finances/price-wizard/publications/${p.id}/pdf?customer=${c.id_customer}" target="_blank" rel="noopener">PDF</a></td></tr>`).join('')}</tbody></table></div>`;
        if (window.Swal) Swal.fire({title: 'Publikacja cen', html, width: 900, confirmButtonText: 'Zamknij'});
    }

    async function pdfPreview(customer) {
        const res = await fetch(root + '/ajax/price-wizard/preview-pdf', {method: 'POST', credentials: 'same-origin',
            headers: {'Content-Type': 'application/json'}, body: JSON.stringify({items: selectedItems(), customer, date: S.date})});
        if (!res.ok) { const e = await res.json().catch(() => ({})); throw new Error(e.message || 'Nie udało się przygotować PDF.'); }
        const url = URL.createObjectURL(await res.blob());
        window.open(url, '_blank', 'noopener');
        setTimeout(() => URL.revokeObjectURL(url), 60000);
    }

    // ── Zdarzenia ────────────────────────────────────────────────────────────────────────────────────────
    body.addEventListener('click', (e) => {
        const t = e.target.closest('button, a[data-step]');
        if (!t || t.disabled) return;
        if (t.dataset.step) { e.preventDefault(); go(+t.dataset.step); return; }
        if (t.dataset.view1) { S.view1 = t.dataset.view1; render(); return; }
        if (t.dataset.filter) { S.filter = t.dataset.filter; render(); return; }
        if (t.hasAttribute('data-go-pubs')) { if (S.step !== 3) go(3); else document.getElementById('pwPubs')?.scrollIntoView({behavior: 'smooth'}); return; }
        if (t.hasAttribute('data-recalc')) {
            busy(async () => {
                const r = await api('POST', '/ajax/price-wizard/recalculate');
                S.costs = r.data; S.prices = null;
                render(); toast('success', r.message);
            });
            return;
        }
        if (t.dataset.open) { const id = +t.dataset.open; if (S.open[id]) delete S.open[id]; else S.open[id] = true; render(); return; }
        if (t.hasAttribute('data-openall')) {
            if (Object.keys(S.open).length) S.open = {}; else S.prices.categories.forEach((c) => { S.open[c.id] = true; });
            render(); return;
        }
        if (t.hasAttribute('data-save-rules')) {
            busy(async () => {
                const rows = Object.entries(S.rules).map(([id, r]) => {
                    const c = S.prices.categories.find((x) => x.id === +id);
                    return {id: +id, margin: r.margin !== undefined ? r.margin : c.margin, coef: r.coef !== undefined ? r.coef : (c.coef ?? '')};
                });
                const out = await api('PUT', '/ajax/price-wizard/categories', {rows});
                setPrices(out.data); render(); toast('success', out.message);
            });
            return;
        }
        if (t.hasAttribute('data-params')) { editParams(); return; }
        if (t.dataset.chip) { S.date = t.dataset.chip; render(); return; }
        if (t.dataset.pdf) { busy(() => pdfPreview(+t.dataset.pdf)); return; }
        if (t.hasAttribute('data-plan')) { plan(); return; }
        if (t.hasAttribute('data-again')) { S.done = null; go(2); return; }
        if (t.dataset.detail) { busy(() => showDetail(+t.dataset.detail)); return; }
        if (t.dataset.cancel) { cancel(+t.dataset.cancel); return; }
    });
    body.addEventListener('change', (e) => {
        const t = e.target;
        if (t.dataset.pick) { if (t.checked) S.sel[+t.dataset.pick] = true; else delete S.sel[+t.dataset.pick]; render(); return; }
        if (t.dataset.client) { if (t.checked) delete S.exclude[+t.dataset.client]; else S.exclude[+t.dataset.client] = true; render(); return; }
        if (t.hasAttribute('data-notify')) { S.notify = t.checked; render(); return; }
        if (t.hasAttribute('data-date')) { if (t.value) { S.date = t.value; render(); } return; }
        if (t.dataset.price) {
            const id = +t.dataset.price;
            const p = allProducts().find((x) => x.id === id);
            const v = num(t.value);
            if (v !== null && p) {
                S.price[id] = Math.round(v * 100) / 100;
                S.manual[id] = Math.abs(S.price[id] - p.new) >= 0.005;
                if (!S.manual[id]) delete S.manual[id];
                if (S.price[id] > p.cur + 0.004) S.sel[id] = true;
            }
            render();
            return;
        }
        if (t.dataset.ruleCoef || t.dataset.ruleMargin) {
            const id = +(t.dataset.ruleCoef || t.dataset.ruleMargin);
            S.rules[id] = S.rules[id] || {};
            S.rules[id][t.dataset.ruleCoef ? 'coef' : 'margin'] = t.value.trim();
            render();
        }
    });
    let qTimer = null;
    body.addEventListener('input', (e) => {
        if (!e.target.hasAttribute('data-q')) return;
        clearTimeout(qTimer);
        const v = e.target.value;
        qTimer = setTimeout(() => {
            S.q = v.trim().toLowerCase(); render();
            const q = body.querySelector('[data-q]'); if (q) { q.focus(); q.setSelectionRange(v.length, v.length); }
        }, 250);
    });
    body.addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target.matches('.pw-price, .pw-rule')) e.target.blur(); });

    async function editParams() {
        const P = S.prices ? S.prices.params : boot.params;
        if (!window.Swal) return;
        const r = await Swal.fire({title: 'Parametry reguły cen', showCancelButton: true, confirmButtonText: 'Zapisz', cancelButtonText: 'Anuluj', html:
            `<div class="pw-params"><label>Limit podwyżki naraz (%)<input class="form-control" id="pwCap" value="${f(P.cap_pct, 1)}"></label>
            <label>Zaokrąglenie w górę do (€)<input class="form-control" id="pwRound" value="${f(P.round_eur, 2)}"></label>
            <label>Domyślny współczynnik surowców (×)<input class="form-control" id="pwCoef" value="${f(P.material_coef_default, 2)}"></label>
            <label>E-mail z cennikiem — dni przed datą<input class="form-control" id="pwNotice" value="${P.notice_days}"></label>
            <p class="text-muted small mb-0">Limit i zaokrąglenie : te same dla cen standardowych i cen klientów (Ceny klientów wg marży).</p></div>`,
            preConfirm: () => ({cap_pct: document.getElementById('pwCap').value, round_eur: document.getElementById('pwRound').value,
                material_coef_default: document.getElementById('pwCoef').value, notice_days: document.getElementById('pwNotice').value})});
        if (!r.isConfirmed) return;
        busy(async () => {
            const out = await api('PUT', '/ajax/price-wizard/params', r.value);
            boot.params = out.params;
            setPrices((await api('GET', '/ajax/price-wizard/prices')).data);
            render(); toast('success', out.message);
        });
    }

    async function plan() {
        const V = S.preview;
        const date = S.date || V.default_date;
        const on = V.clients.filter((c) => !S.exclude[c.id]);
        const emails = S.notify ? on.filter((c) => c.email).length : 0;
        const sites = on.filter((c) => c.franchise).length;
        const ok = window.Swal ? (await Swal.fire({icon: 'question', title: 'Zaplanować nowe ceny od ' + dmy(date) + ' ?', showCancelButton: true,
            confirmButtonText: 'Zaplanuj', cancelButtonText: 'Wróć',
            html: `${V.totals.products} ${ceny(V.totals.products)} standardowych (średnio ${sg(V.totals.avg_pct)} %) i ceny : ${klientow(on.length)}.<br>
                ${emails ? `E-mail z cennikiem : ${klientow(emails)} (${dmy(isoAdd(date, -V.params.notice_days) < today() ? today() : isoAdd(date, -V.params.notice_days))}).<br>` : ''}
                ${sites ? `Strona franczyzy : ${sklepy(sites)} w dniu zmiany.<br>` : ''}Do ${dmy(isoAdd(date, -1))} można anulować.`})).isConfirmed : confirm('Zaplanować?');
        if (!ok) return;
        busy(async () => {
            const out = await api('POST', '/ajax/price-wizard/publications', {date, items: selectedItems(), exclude: Object.keys(S.exclude).map(Number), notify: S.notify});
            S.pubs = out.publications;
            S.prices = null; S.preview = {}; S.costs = null; S.exclude = {}; S.date = null; S.done = out.data;
            await Swal.fire({icon: 'success', text: out.message});
            render();
            setTimeout(() => document.getElementById('pwPubs')?.scrollIntoView({behavior: 'smooth'}), 200);
        });
    }

    async function cancel(id) {
        const p = (S.pubs || []).find((x) => +x.id === id);
        const ok = window.Swal ? (await Swal.fire({icon: 'warning', title: 'Anulować publikację od ' + dmy(p?.effective_date) + ' ?', text: 'Ceny zostaną bez zmian.',
            showCancelButton: true, confirmButtonText: 'Anuluj publikację', cancelButtonText: 'Wróć'})).isConfirmed : confirm('Anulować?');
        if (!ok) return;
        busy(async () => {
            const out = await api('POST', '/ajax/price-wizard/publications/' + id + '/cancel');
            S.pubs = out.publications; S.prices = null; S.preview = null;
            toast('success', out.message);
            await load();
        });
    }

    const m = /step=(\d)/.exec(location.hash);
    S.step = m ? Math.max(1, Math.min(3, +m[1])) : 1;
    if (S.step === 3) S.step = 2; // krok 3 potrzebuje wyboru cen z kroku 2
    load();
})();
