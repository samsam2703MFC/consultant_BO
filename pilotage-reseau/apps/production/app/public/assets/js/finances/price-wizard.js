/*
 * Kreator cen [218] (Finanse › Marże i koszty › Kreator cen, makieta zatwierdzona) → Ścieżka cen [351] (makieta 66 B, co czwartek) :
 *  pasek 4 kroków (price-path.js, ten sam w asystencie faktur) — 1. ceny z faktur KSeF (asystent faktur),
 *  2. sprawdź ceny katalogowe : koszt surowców kartonu przy ostatnim cenniku → dziś, próg ±3 % : ▲ podwyżka, ▼ obniżka
 *     (nowość), = bez zmian ; przy wejściu przeliczenie receptur, gdy ceny surowców się zmieniły ; nic do zmiany →
 *     « Sprawdzone — bez zmian w tym tygodniu » (zadanie czwartkowe) ;
 *  3. zatwierdź zmianę cennika (data, klienci, ceny klientów — rabaty zachowane) ;
 *  4. e-mail do klientów (lista, PDF, « Wyślij teraz » — inaczej e-maile wychodzą same notice_days przed datą).
 *  « Koszty i marże (szczegóły) » = dawny krok 1 (#step=1). Kroki w adresie (#step=1…4). Serwer liczy wszystko od nowa
 *  przy podglądzie i planowaniu.
 */
(function () {
    'use strict';
    const app = document.getElementById('priceWizardApp');
    if (!app) return;
    const root = app.dataset.root;
    const body = app.querySelector('[data-pw-body]');
    let boot = {};
    try { boot = JSON.parse(document.getElementById('priceWizardBoot').textContent || '{}'); } catch (e) { boot = {}; }

    const S = {step: 2, costs: null, prices: null, preview: null, pubs: null, view1: 'cat', sel: {}, price: {}, manual: {}, rules: {},
        date: null, notify: true, exclude: {}, busy: false, done: null, path: null, recalc: null, openKeep: false, showRules: false, pubId: null, mail: null};

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

    // ── Ścieżka [351] : pasek 4 kroków (price-path.js) ───────────────────────────────────────────────────
    const pathParams = () => (S.prices ? S.prices.params : boot.params) || {};
    const pathBar = () => window.TfbPricePath ? window.TfbPricePath.html({cur: S.step === 1 ? 2 : S.step, root, inv: S.path ? S.path.inv : null, path: S.path,
        here: 'prices', threshold: pathParams().up_pct}) : '';
    function steps() {
        const bar = pathBar();
        const planned = (S.pubs || []).filter((p) => p.status === 'planned');
        return `${bar}${planned.length ? `<div class="pw-planned"><span class="kw-badge new">Zaplanowane : ${planned.map((p) => 'od ' + dmy(p.effective_date) + ' (' + p.products + ' ' + ceny(+p.products) + ')').join(' · ')}</span>
            <button type="button" class="kw-btn ghost kw-sm" data-go-pubs>Publikacje →</button></div>` : ''}`;
    }
    async function loadPath() {
        if (!window.TfbPricePath) return;
        S.path = await window.TfbPricePath.load(root, true);
        const n = body.querySelector('.pp');
        if (n) n.outerHTML = pathBar();
    }
    function go(step) {
        step = Math.max(1, Math.min(4, step));
        if (step === 3 && !S.done && !selectedItems().length) { toast('info', 'Najpierw zaznacz ceny do zmiany w kroku 2.'); step = 2; }
        if (step === 4 && !S.pubId) {
            const pub = (S.path && S.path.publication) || (S.pubs || []).find((p) => p.status === 'planned');
            if (pub) S.pubId = +pub.id;
        }
        S.step = step;
        if (S.step === 3) { S.done = null; S.preview = null; }
        if (S.step === 4) S.mail = null;
        if (location.hash !== '#step=' + S.step) history.replaceState(null, '', '#step=' + S.step);
        load();
        window.scrollTo({top: 0});
    }

    async function load() {
        render();
        try {
            if (S.pubs === null) S.pubs = (await api('GET', '/ajax/price-wizard/publications')).data;
            if (S.step === 1 && !S.costs) S.costs = (await api('GET', '/ajax/price-wizard/costs')).data;
            if (S.step >= 2 && S.step <= 3 && !S.prices) {
                // Wejście w krok 2 : przeliczenie receptur, gdy ceny surowców zmieniły się od ostatniego przeliczenia.
                const out = await api('POST', '/ajax/price-wizard/check');
                S.recalc = out.recalc || null;
                setPrices(out.data);
                if (S.recalc) { S.costs = null; loadPath(); }
            }
            if (S.step === 3 && !S.done) {
                const items = selectedItems();
                if (!items.length) { S.preview = {error: 'Nie wybrano żadnej nowej ceny — wróć do kroku 2.'}; }
                else {
                    try { S.preview = (await api('POST', '/ajax/price-wizard/preview', {items})).data; }
                    catch (e) { S.preview = {error: e.message}; }
                    if (S.preview && !S.preview.error && !S.date) S.date = S.preview.default_date;
                }
            }
            if (S.step === 4 && S.pubId && !S.mail) S.mail = (await api('GET', '/ajax/price-wizard/publications/' + S.pubId)).data;
        } catch (e) {
            body.innerHTML = steps() + `<div class="kw-card"><div class="kw-note bad">${esc(e.message)}</div></div>`;
            return;
        }
        render();
    }

    function render() {
        let html = steps();
        if (S.step === 1) html += S.costs ? step1() : '<div class="kw-card"><p class="at-empty">Ładowanie kosztów i marż…</p></div>';
        if (S.step === 2) html += S.prices ? step2() : '<div class="kw-card"><p class="at-empty">Sprawdzanie cen katalogowych (przeliczenie receptur, gdy ceny surowców się zmieniły)…</p></div>';
        if (S.step === 3) html += S.preview ? step3() : '<div class="kw-card"><p class="at-empty">Liczenie cen klientów…</p></div>';
        if (S.step === 4) html += !S.pubId ? step4empty() : (S.mail ? step4() : '<div class="kw-card"><p class="at-empty">Ładowanie e-maili…</p></div>');
        if (S.step >= 3) html += pubsCard();
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
        return `<div class="kw-card"><h5>Koszty i marże (szczegóły) ${gate}${run ? `<span class="kw-badge">ostatnie przeliczenie ${dmy(run.run_at)} ${hm(run.run_at)}${run.by_name ? ' · ' + esc(run.by_name) : ''}</span>` : '<span class="kw-badge warn">jeszcze nie przeliczono</span>'}</h5>
            <p class="kw-lead">Najpierw zatwierdź faktury (asystent faktur) — nowe ceny surowców trafiają do kartoteki. Potem jedno kliknięcie : koszt surowców wszystkich receptur po bieżących cenach (najwyższa zatwierdzona cena), koszt pełny (działy + logistyka) i marża przy obecnych cenach sprzedaży. Ceny sprzedaży jeszcze się nie zmieniają.</p>
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
            <div class="kw-foot"><a class="kw-btn ghost" href="${root}/finances/category-margin">Marża kategorii (pełny widok)</a><button type="button" class="kw-btn pri" data-step="2">← 2. Sprawdź ceny katalogowe</button></div></div>`;
    }

    // ── Krok 2 [351] : sprawdź ceny katalogowe (makieta 66 B) ───────────────────────────────────────────────
    const SELECTABLE = ['up', 'cap', 'recent', 'down'];
    const UP = ['up', 'cap', 'recent'];
    function setPrices(data) {
        S.prices = data;
        S.sel = {}; S.price = {}; S.manual = {}; S.rules = {};
        data.products.forEach((p) => {
            S.price[p.id] = p.new;
            if (p.status === 'up' || p.status === 'cap' || p.status === 'down') S.sel[p.id] = true;
        });
        S.preview = null;
    }
    const allProducts = () => S.prices.products;
    const priceOf = (p) => (S.sel[p.id] && SELECTABLE.includes(p.status) ? (S.price[p.id] ?? p.cur) : p.cur);
    const outside = (p, v) => v > p.max + 0.004 || v < p.min - 0.004;
    function selectedItems() {
        if (!S.prices) return [];
        return allProducts().filter((p) => S.sel[p.id] && SELECTABLE.includes(p.status) && Math.abs((S.price[p.id] ?? p.cur) - p.cur) >= 0.005)
            .map((p) => ({product_id: p.id, price: S.price[p.id], manual: !!S.manual[p.id]}));
    }
    const pctTxt = (v, d = 1) => (v > 0.00001 ? '+' : (v < -0.00001 ? '−' : '')) + f(Math.abs(v), d) + ' %';
    const pill = (v) => Math.abs(v) < 0.05 ? '<span class="pw-pill">0 %</span>' : `<span class="pw-pill ${v > 0 ? 'up' : 'down'}">${pctTxt(v)}</span>`;
    const arrow = (v, good) => Math.abs(v) < 0.005 ? '<span class="pw-ar eq">→</span>'
        : `<span class="pw-ar ${good === undefined ? (v > 0 ? 'up' : 'down') : (good ? 'down' : 'up')}">${v > 0 ? '↗' : '↘'}</span>`;
    const matMargin = (price, food) => price > 0 && S.prices.rate > 0 ? (price * S.prices.rate - food) / (price * S.prices.rate) * 100 : null;
    function tags(p, price, on) {
        const P = S.prices.params;
        const out = [];
        if (p.status === 'cap' && on && Math.abs(price - p.new) < 0.005) out.push(`<span class="pw-tag warn">limit +${f(P.cap_pct, 0)} % — reszta przy następnej zmianie</span>`);
        if (p.status === 'down' && p.limited && on && Math.abs(price - p.new) < 0.005) out.push(`<span class="pw-tag warn">limit −${f(P.cut_pct, 0)} % — reszta przy następnej zmianie</span>`);
        if (p.status === 'down' && p.held === 'target' && on) out.push(`<span class="pw-tag">cena celu ${f(p.target, 2)} € — niżej nie</span>`);
        if (p.status === 'recent') out.push(`<span class="pw-tag warn">podniesiona ${dmy(p.recent)} — kolejna po ${P.interval_days} dniach</span>`);
        if (p.status === 'planned') out.push(`<span class="pw-tag new">zaplanowana od ${dmy(p.planned.date)}</span>`);
        if (p.status === 'none') out.push('<span class="pw-tag">brak kosztu surowców</span>');
        if (p.status === 'keep' && p.held === 'target') out.push(`<span class="pw-tag">koszt spadł, cena celu ${f(p.target, 2)} € — bez obniżki</span>`);
        if (p.status === 'keep' && p.held === 'round') out.push('<span class="pw-tag">zmiana mniejsza niż zaokrąglenie</span>');
        if (SELECTABLE.includes(p.status) && !on) out.push(`<span class="pw-tag">odznaczone : zostaje ${f(p.cur, 2)} €</span>`);
        if (on && S.manual[p.id]) out.push('<span class="pw-tag new">ręcznie</span>');
        if (on && outside(p, price)) out.push(`<span class="pw-tag bad">${price > p.max ? 'najwyżej ' + f(p.max, 2) + ' € (+' + f(P.cap_pct, 0) + ' %)' : 'najniżej ' + f(p.min, 2) + ' € (−' + f(P.cut_pct, 0) + ' %)'}</span>`);
        return out.join('');
    }
    function productRow(p) {
        const selectable = SELECTABLE.includes(p.status);
        const on = selectable && !!S.sel[p.id];
        const price = priceOf(p);
        const dCost = p.food_now - p.food_base;
        const dPrice = price - p.cur;
        const mBefore = p.margin_before;
        const mAfter = matMargin(price, p.food_now);
        const mDiff = mBefore !== null && mAfter !== null ? mAfter - mBefore : 0;
        const why = p.reasons.length ? p.reasons.map((r) => `<span class="pw-chip ${r.pct > 0 ? 'cu' : 'cd'}">${r.pct > 0 ? '▲' : '▼'} ${esc(r.name)} ${pctTxt(r.pct)}</span>`).join('')
            + (p.reasons.some((r) => r.invoice) ? `<small class="pw-src">FV ${p.reasons.filter((r) => r.invoice).map((r) => esc(r.invoice)).filter((v, i, a) => a.indexOf(v) === i).join(' · ')}</small>` : '') : '';
        return `<tr class="${selectable && !on ? 'off' : ''}" data-pid="${p.id}">
            <td>${selectable ? `<input type="checkbox" class="kw-box" data-pick="${p.id}" ${on ? 'checked' : ''} aria-label="Zmień cenę ${esc(p.name)}">` : ''}</td>
            <td class="pw-prod"><b>${esc(p.name)}</b><small>${esc(p.category)} · ${p.qty >= 10 || p.qty === 0 ? f(p.qty, 0) : f(p.qty, 1)} kart. / mies.</small></td>
            <td class="num mut">${f(p.food_base, 2)} zł</td><td class="ar">${arrow(dCost)}</td><td class="num"><b>${f(p.food_now, 2)} zł</b></td>
            <td class="num">${p.cost_pct !== null ? pill(p.cost_pct) : '—'}</td>
            <td class="num mut">${f(p.cur, 2)} €</td><td class="ar">${arrow(dPrice)}</td>
            <td class="num">${on ? `<input class="kw-in pw-price ${S.manual[p.id] ? 'pw-edit' : ''} ${outside(p, price) ? 'pw-bad' : ''}" inputmode="decimal" value="${f(price, 2)}" data-price="${p.id}" aria-label="Nowa cena ${esc(p.name)} (€)">` : `<b>${f(price, 2)} €</b>`}</td>
            <td class="num">${Math.abs(dPrice) >= 0.005 ? pill(dPrice / p.cur * 100) : ''}</td>
            <td class="num mut">${mBefore !== null ? f(mBefore, 1) + ' %' : '—'}</td><td class="ar">${arrow(Math.abs(mDiff) < 0.05 ? 0 : mDiff, mDiff < 0 ? false : true)}</td>
            <td class="num"><b>${mAfter !== null ? f(mAfter, 1) + ' %' : '—'}</b></td>
            <td class="why">${why}${tags(p, price, on)}</td></tr>`;
    }
    function step2() {
        const D = S.prices;
        const P = D.params;
        const t = D.totals;
        const items = selectedItems();
        const all = allProducts();
        const up = all.filter((p) => UP.includes(p.status));
        const down = all.filter((p) => p.status === 'down');
        const same = all.filter((p) => !UP.includes(p.status) && p.status !== 'down');
        const band = Math.abs(P.up_pct - P.down_pct) < 0.001 ? '±' + f(P.up_pct, 1).replace(/,0$/, '') + ' %' : '−' + f(P.down_pct, 1).replace(/,0$/, '') + ' / +' + f(P.up_pct, 1).replace(/,0$/, '') + ' %';
        const pct = (v) => f(v, 1).replace(/,0$/, '');
        const head = `<colgroup><col style="width:34px"><col><col style="width:92px"><col style="width:26px"><col style="width:92px"><col style="width:80px"><col style="width:86px"><col style="width:26px"><col style="width:100px"><col style="width:78px"><col style="width:64px"><col style="width:26px"><col style="width:64px"><col style="width:290px"></colgroup>
            <thead><tr class="h1"><th></th><th></th><th colspan="4" class="c">Koszt surowców / karton</th><th colspan="4" class="c">Cena EUR / karton</th><th colspan="3" class="c">Marża na surowcach</th><th></th></tr>
            <tr><th></th><th>Produkt</th><th class="num">Przed</th><th></th><th class="num">Po</th><th class="num">Zmiana</th><th class="num">Dziś</th><th></th><th class="num">Nowa</th><th class="num">Zmiana</th><th class="num">Przed</th><th></th><th class="num">Po</th><th>Przyczyna</th></tr></thead>`;
        let rows = '';
        if (up.length) rows += `<tr class="grp grp-up"><td colspan="14">▲ Podwyżka — koszt wzrósł ponad próg (${up.length})</td></tr>` + up.map(productRow).join('');
        if (down.length) rows += `<tr class="grp grp-down"><td colspan="14">▼ Obniżka — koszt spadł ponad próg (${down.length})</td></tr>` + down.map(productRow).join('');
        rows += `<tr class="grp grp-same"><td colspan="14"><button type="button" class="pw-tog" data-keep aria-expanded="${S.openKeep}">= Bez zmian — koszt w progu ${band} (${same.length}) · ${S.openKeep ? 'zwiń' : 'rozwiń'}</button></td></tr>`;
        if (S.openKeep) rows += same.map(productRow).join('');
        const picked = items.map((i) => all.find((p) => p.id === i.product_id));
        const nUp = picked.filter((p) => S.price[p.id] > p.cur).length;
        const month = picked.reduce((s, p) => s + (S.price[p.id] - p.cur) * p.qty, 0);
        const be = picked.filter((p) => p.be !== null && S.price[p.id] > p.cur).map((p) => ({name: p.name, coef: p.be * p.cur / S.price[p.id]})).filter((x) => x.coef < D.be_params.multiplier);
        const overAny = picked.some((p) => outside(p, S.price[p.id]));
        const inp = (key, label, unit, d) => `<label>${label} <input class="kw-in" inputmode="decimal" data-param="${key}" value="${f(P[key], d)}" ${boot.is_admin ? '' : 'disabled title="Parametry zmienia administrator"'} aria-label="${label}"> ${unit}</label>`;
        const rev = D.reviewed || (S.path && S.path.reviewed);
        const avgUp = t.up_pct !== null ? 'śr. ' + pctTxt(t.up_pct) + ' ceny' : '—';
        const avgDown = t.down_pct !== null ? 'śr. ' + pctTxt(t.down_pct) + ' ceny' : '—';
        const run = S.costs && S.costs.last_run;
        return `<div class="kw-card"><h5>2. Sprawdź ceny katalogowe <span class="kw-badge">koszt surowców dziś vs przy ostatnim cenniku · EUR / karton</span>
                ${rev ? `<span class="kw-badge ok">✓ ${esc(rev.summary || 'sprawdzone')}</span>` : ''}
                <span class="pw-grow"></span><button type="button" class="kw-btn ghost kw-sm pw-hbtn" data-step="1">Koszty i marże (szczegóły)</button>
                <button type="button" class="kw-btn ghost kw-sm pw-hbtn" data-rules>${S.showRules ? 'Ukryj marże kategorii' : 'Marże bazowe kategorii'}</button>
                ${boot.is_admin ? '<button type="button" class="kw-btn ghost kw-sm pw-hbtn" data-params>⚙ Parametry reguły</button>' : ''}</h5>
            ${S.recalc ? `<div class="kw-note">↻ ${esc(S.recalc.message)}</div>` : ''}
            <div class="pw-prm"><b>Parametry</b>${inp('up_pct', 'Próg wzrostu kosztu', '%', 1)}${inp('down_pct', 'Próg spadku kosztu', '%', 1)}${inp('cap_pct', 'Maks. podwyżka naraz', '%', 1)}
                ${inp('cut_pct', 'Maks. obniżka naraz', '%', 1)}${inp('round_eur', 'Zaokrąglenie', '€', 2)}<span class="pw-mut">— zapisane w Ustawieniach (Kreator cen), te same dla całej ścieżki</span></div>
            <div class="pw-tiles"><div><span>Produkty sprawdzone</span><b>${t.products}</b><small>${run ? 'receptury przeliczone ' + dmy(run.run_at) : 'receptury po cenach z kroku 1'}</small></div>
                <div class="up"><span>▲ Koszt wzrósł &gt; ${pct(P.up_pct)} % → podwyżka</span><b>${up.length}</b><small>${avgUp}</small></div>
                <div class="down"><span>▼ Koszt spadł &gt; ${pct(P.down_pct)} % → obniżka</span><b>${down.length}</b><small>${avgDown}</small></div>
                <div><span>= W progu ${band} → bez zmian</span><b>${same.length}</b><small><button type="button" class="pw-link" data-keep>${S.openKeep ? 'zwiń listę' : 'pokaż listę'}</button></small></div></div>
            <div class="table-responsive"><table class="kw-t pt">${head}<tbody>${rows}</tbody></table></div>
            <div class="pw-yellow">Nowa reguła : cena może też spaść (obniżka, gdy koszt surowców spadł ponad próg od ostatniego cennika). Dotąd Kreator cen nigdy nie obniżał ceny — odznacz produkt, aby zachować obecną cenę. Nowa cena zachowuje marżę % na koszcie pełnym (cena × koszt pełny dziś ÷ koszt pełny przy ostatnim cenniku), z limitem zmiany naraz (+${pct(P.cap_pct)} % / −${pct(P.cut_pct)} %) i zaokrągleniem do ${f(P.round_eur, 2)} € (podwyżka w górę, obniżka w dół — nigdy poniżej ceny celu kategorii). Produkt odznaczony albo w progu zachowuje swój koszt bazowy : zmiana kumuluje się z tygodnia na tydzień.</div>
            ${items.length ? `<div class="pw-sel">Wybrane : <b>${items.length}</b> ${ceny(items.length)} (${nUp} ▲ · ${items.length - nUp} ▼) — obrót ${month >= 0 ? '+' : '−'}${f(Math.abs(month), 0)} € / mies. przy obecnej sprzedaży (ceny standardowe ; ceny klientów w kroku 3).</div>` : ''}
            ${be.length ? `<div class="kw-note warn">Belgia (franczyza) : ${be.length} ${be.length === 1 ? 'produkt' : 'produktów'} po zmianie poniżej × ${f(D.be_params.multiplier, 1)} ceny w sklepie (${be.slice(0, 4).map((x) => esc(x.name) + ' × ' + f(x.coef, 2)).join(' · ')}${be.length > 4 ? ' …' : ''}) — sprawdź przed publikacją.</div>` : ''}
            <div class="kw-foot"><a class="kw-btn" href="${root}/workshop/supply#tab=invoices&inv=wizard">← 1. Ceny z faktur KSeF</a>
                <span class="kw-do">${!items.length ? (rev ? `<span class="kw-badge ok">✓ Sprawdzone w tym tygodniu</span>` : '<button type="button" class="kw-btn ok" data-reviewed>✓ Sprawdzone — bez zmian w tym tygodniu</button>') : ''}
                <button type="button" class="kw-btn pri" data-step="3" ${items.length && !overAny ? '' : 'disabled'}>Dalej : 3. Zatwierdź zmianę cennika (${items.length} ${produktow(items.length)}) →</button></span></div></div>
            ${S.showRules ? rulesCard() : ''}`;
    }
    const produktow = (n) => plural(n, 'produkt', 'produkty', 'produktów');

    /** Marża bazowa i współczynnik kategorii : cena celu = dolna granica obniżki (reguła [218]). */
    function rulesCard() {
        const D = S.prices;
        const P = D.params;
        const dirty = Object.keys(S.rules).length;
        return `<div class="kw-card"><h5>Marże bazowe kategorii <span class="kw-badge">cena celu = dolna granica obniżki</span></h5>
            <p class="kw-lead">Cena celu = wyższa z : koszt pełny ÷ (1 − marża bazowa) i koszt surowców × współczynnik (puste = domyślny × ${f(P.material_coef_default, 1)}). Obniżka nigdy nie schodzi poniżej ceny celu.</p>
            <div class="table-responsive"><table class="kw-t pw-t"><thead><tr><th>Kategoria</th><th class="r">Produkty</th><th class="r">Współczynnik surowców</th><th class="r">Marża bazowa</th></tr></thead><tbody>
            ${D.categories.map((c) => { const r = S.rules[c.id] || {}; return `<tr><td><b>${esc(c.name)}</b></td><td class="r">${c.products}</td>
                <td class="r"><input class="kw-in pw-rule ${r.coef !== undefined ? 'pw-edit' : ''}" inputmode="decimal" data-rule-coef="${c.id}" value="${r.coef !== undefined ? esc(r.coef) : (c.coef !== null ? f(c.coef, 2) : '')}" placeholder="× ${f(P.material_coef_default, 1)}" aria-label="Współczynnik surowców ${esc(c.name)}"></td>
                <td class="r"><input class="kw-in pw-rule ${r.margin !== undefined ? 'pw-edit' : ''}" inputmode="decimal" data-rule-margin="${c.id}" value="${r.margin !== undefined ? esc(r.margin) : f(c.margin, 0)}" aria-label="Marża bazowa ${esc(c.name)} (%)"> %</td></tr>`; }).join('')}
            </tbody></table></div>
            <div class="kw-foot"><span></span><button type="button" class="kw-btn ${dirty ? 'pri' : ''}" data-save-rules ${dirty ? '' : 'disabled'}>Zapisz marże i współczynniki${dirty ? ' (' + dirty + ')' : ''}</button></div></div>`;
    }

    // ── Krok 3 [351] : zatwierdź zmianę cennika (data, klienci, ceny klientów — rabaty zachowane) ──────────────
    function step3() {
        const V = S.preview;
        if (V.error) return `<div class="kw-card"><div class="kw-note bad">${esc(V.error)}</div><div class="kw-foot"><button type="button" class="kw-btn" data-step="2">← 2. Sprawdź ceny katalogowe</button></div></div>`;
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
            <div class="kw-card"><h5>3. Zatwierdź zmianę cennika — od kiedy</h5>
                <p class="mb-2"><b>${V.totals.products} ${produktow(V.totals.products)}</b> · ${V.totals.up} ▲ · ${V.totals.down} ▼ · obowiązuje od <b>${dmy(date)}</b></p>
                <p class="kw-lead">Ceny zmieniają się w ERP i cennik idzie na stronę franczyzy tego samego dnia o 00:05 — zamówienia z dostawą od tej daty po nowych cenach. Klienci dostają cennik wcześniej (${P.notice_days} dni przed).</p>
                <div class="pw-date"><input type="date" class="form-control pw-dateinput" min="${min}" value="${esc(date)}" data-date aria-label="Data zmiany cen">
                    ${chips.map((c) => `<button type="button" class="kw-chip ${c === date ? 'on' : ''}" data-chip="${c}">${day(c)}</button>`).join('')}</div>
                <div class="kw-note ${lead < P.notice_days ? 'warn' : ''}">Wyprzedzenie ${lead} ${lead === 1 ? 'dzień' : 'dni'}${lead < P.notice_days ? ` — krócej niż ${P.notice_days} dni : e-mail wyjdzie od razu` : ` (minimum ${P.notice_days})`}.</div>
                <label class="kw-check"><input type="checkbox" data-notify ${S.notify ? 'checked' : ''}> E-mail z cennikiem od tej daty do klientów (PDF w języku klienta, adres z Dystrybucji dokumentów)</label>
                <ul class="pw-time">
                    <li class="done"><b>${day(min)}</b><i></i><span>Zatwierdzenie asystenta<small>ceny zaplanowane, jeszcze nie w cenniku — można anulować do ${dmy(isoAdd(date, -1))}</small></span></li>
                    ${S.notify ? `<li><b>${day(notice)}</b><i></i><span>E-mail do klientów z cennikiem PDF (od ${dm(date)})<small>${klientow(emails)} · w języku klienta · kopia w Poczcie / Stan wysyłek</small></span></li>` : ''}
                    <li><b>${day(date)} 00:05</b><i></i><span>Nowe ceny w ERP + cennik na stronę franczyzy<small>ceny standardowe, ceny klientów, historia zmian cen produktu</small></span></li>
                    <li><b>${day(date)}</b><i></i><span>Potwierdzenie ze strony (wersja cennika)<small>błąd → ponowienie co 5 min, stan w publikacjach poniżej</small></span></li></ul></div>
            <div class="kw-card"><h5>Gdzie i komu <span class="kw-badge new">${on.length} z ${clients.length} klientów</span></h5>
                ${clients.length ? `<div class="table-responsive"><table class="kw-t"><thead><tr><th></th><th>Klient</th><th>Kanał</th><th class="r">Ceny</th><th class="r">Średnio</th><th class="r">Obrót / mies.</th><th></th></tr></thead><tbody>
                ${clients.map((c) => `<tr class="${S.exclude[c.id] ? 'pw-off' : ''}"><td><input type="checkbox" class="kw-box" data-client="${c.id}" ${S.exclude[c.id] ? '' : 'checked'} aria-label="${esc(c.name)}"></td>
                    <td><b>${esc(c.name)}</b><small>${c.franchise ? 'franczyza — cennik na stronę' : 'cennik klienta'}</small></td>
                    <td>${c.franchise ? '<span class="kw-badge ok">Strona franczyzy</span> ' : ''}${S.notify ? (c.email ? `<span class="kw-badge">E-mail ${esc(c.lang.toUpperCase())}</span>` : '<span class="kw-badge warn">brak adresu e-mail</span>') : ''}</td>
                    <td class="r">${c.prices}<small>${c.followers} za standardową</small></td><td class="r ${c.avg_pct > 0 ? 'kw-up' : 'kw-down'}">${sg(c.avg_pct)} %</td><td class="r">${Math.abs(c.month_eur) >= 0.5 ? sg(c.month_eur, 0) + ' €' : '—'}</td>
                    <td class="r"><button type="button" class="kw-btn ghost kw-sm" data-pdf="${c.id}" title="Podgląd cennika od ${dmy(date)}">PDF</button></td></tr>`).join('')}
                </tbody></table></div>` : '<p class="at-empty">Żaden klient nie ma tych produktów w cenniku — zmienią się tylko ceny standardowe.</p>'}
                <div class="pw-sum"><span>Ceny standardowe</span><b>${V.totals.products} ${produktow(V.totals.products)} · średnio ${sg(V.totals.avg_pct)} %</b>
                    <span>Ceny klientów</span><b>${prices} (wyłączeni klienci : ceny bez zmian)</b>
                    <span>Strona franczyzy / e-mail</span><b>${sites} / ${S.notify ? emails : 0}</b>
                    <span>Obrót / mies.</span><b>${Math.abs(month) >= 0.5 ? sg(month, 0) : '0'} €</b></div>
                <div class="kw-foot"><button type="button" class="kw-btn" data-step="2">← 2. Sprawdź ceny katalogowe</button><button type="button" class="kw-btn pri" data-plan>Zatwierdź cennik od ${dmy(date)} → 4. E-mail</button></div></div>
        </div>`;
    }

    // ── Krok 4 [351] : e-mail do klientów (lista, PDF, « Wyślij teraz ») ─────────────────────────────────────
    function step4empty() {
        return `<div class="kw-card"><h5>4. Wyślij e-mail do klientów</h5><p class="at-empty">Brak zaplanowanej zmiany cennika — najpierw krok 2 i 3.</p>
            <div class="kw-foot"><button type="button" class="kw-btn" data-step="2">← 2. Sprawdź ceny katalogowe</button></div></div>`;
    }
    function step4() {
        const D = S.mail;
        const p = D.publication;
        const planned = p.status === 'planned';
        const notify = !!+p.notify_email;
        const mails = D.clients.filter((c) => +c.email);
        const pending = mails.filter((c) => !c.email_status);
        const langs = {};
        mails.forEach((c) => { langs[c.lang] = (langs[c.lang] || 0) + 1; });
        const notice = p.notice_date < today() ? today() : p.notice_date;
        const ups = D.standard.filter((i) => +i.new_price > +i.old_price).length;
        const status = (c) => {
            if (!+c.email) return `<span class="kw-badge">${notify ? 'bez e-maila (brak adresu)' : 'bez e-maila'}</span>`;
            if (c.email_status === 'queued') return `<span class="kw-badge ok">✓ w kolejce ${dmy(c.email_at)} ${hm(c.email_at)}</span>`;
            if (c.email_status === 'skipped') return `<span class="kw-badge warn" title="${esc(c.email_note || '')}">pominięty — ${esc(c.email_note || '')}</span>`;
            if (c.email_status === 'failed') return `<span class="kw-badge bad" title="${esc(c.email_note || '')}">błąd</span>`;
            return planned ? `<span class="kw-badge new">czeka — wyjdzie sam ${dmy(notice)}</span>` : '<span class="kw-badge">nie wysłany</span>';
        };
        return `<div class="kw-card"><h5>4. Wyślij e-mail do klientów <span class="kw-badge ${planned ? 'new' : 'ok'}">cennik od ${dmy(p.effective_date)}${planned ? '' : ' · ' + (p.status === 'applied' ? 'opublikowany' : 'anulowany')}</span></h5>
            <p class="kw-lead"><b>${mails.length} ${plural(mails.length, 'e-mail', 'e-maile', 'e-maili')}</b> · język klienta (${Object.entries(langs).map(([l, n]) => l.toUpperCase() + ' ' + n).join(' · ') || '—'}) · załącznik : cennik PDF od ${dmy(p.effective_date)} ·
                ${D.standard.length} ${plural(D.standard.length, 'cena standardowa', 'ceny standardowe', 'cen standardowych')} (${ups} ▲ · ${D.standard.length - ups} ▼). E-mail mówi o « nowych cenach od » daty — bez słowa « podwyżka ».</p>
            ${notify ? `<div class="kw-note">Bez kliknięcia e-maile wyjdą same ${dmy(notice)} (${p.notice_days} dni przed zmianą). « Wyślij teraz » — od razu do kolejki wysyłki (Poczta › Stan wysyłek, historia przy kliencie).</div>`
                : '<div class="kw-note warn">Ta publikacja jest bez e-maila do klientów (odznaczone w kroku 3) — cennik tylko w ERP i na stronie franczyzy.</div>'}
            ${D.clients.length ? `<div class="table-responsive"><table class="kw-t"><thead><tr><th>Klient</th><th>Język</th><th>Adres e-mail</th><th class="r">Ceny</th><th>Status</th><th></th></tr></thead><tbody>
            ${D.clients.map((c) => `<tr><td><b class="pw-client">${esc(c.label)}</b><small>${+c.site ? 'franczyza — cennik na stronę w dniu zmiany' : 'cennik klienta'}</small></td><td>${esc(String(c.lang || '').toUpperCase())}</td>
                <td>${c.address ? `<span class="pw-client">${esc(c.address)}</span>` : '—'}</td><td class="r">${c.prices}</td><td>${status(c)}</td>
                <td class="r"><a class="kw-btn ghost kw-sm" href="${root}/finances/price-wizard/publications/${p.id}/pdf?customer=${c.id_customer}" target="_blank" rel="noopener">PDF</a></td></tr>`).join('')}
            </tbody></table></div>` : '<p class="at-empty">Żaden klient nie ma tych produktów w cenniku — zmieniły się tylko ceny standardowe.</p>'}
            <div class="kw-foot"><button type="button" class="kw-btn" data-again>← Nowa zmiana cen (krok 2)</button>
                ${notify && planned ? `<button type="button" class="kw-btn pri" data-send-now="${p.id}" ${pending.length ? '' : 'disabled'}>${pending.length ? 'Wyślij teraz (' + pending.length + ' ' + plural(pending.length, 'e-mail', 'e-maile', 'e-maili') + ')' : '✓ Wszystkie e-maile w kolejce'}</button>` : ''}</div></div>`;
    }

    // ── Publikacje ───────────────────────────────────────────────────────────────────────────────────────
    function pubsCard() {
        const L = S.pubs || [];
        const badge = (p) => p.status === 'planned' ? '<span class="kw-badge new">zaplanowana</span>' : (p.status === 'applied'
            ? `<span class="kw-badge ok">✓ opublikowana ${dmy(p.applied_at)}</span>${+p.skipped ? ` <span class="kw-badge warn">${p.skipped} pominiętych (cena zmieniona)</span>` : ''}`
            : `<span class="kw-badge">anulowana${p.cancelled_name ? ' · ' + esc(p.cancelled_name) : ''}</span>`);
        return `<div class="kw-card" id="pwPubs"><h5>Zaplanowane i wysłane publikacje</h5>
            ${L.length ? `<div class="table-responsive"><table class="kw-t"><thead><tr><th>Od</th><th>Ceny</th><th>Kto</th><th>Strona franczyzy</th><th>E-maile</th><th class="r">Stan</th><th></th></tr></thead><tbody>
            ${L.map((p) => `<tr class="${p.status === 'cancelled' ? 'pw-off' : ''}"><td><b>${dmy(p.effective_date)}</b></td><td>${p.products} standardowych · ${p.client_prices} klientów<small>${p.avg_pct !== null ? 'średnio ' + sg(+p.avg_pct) + ' %' : ''}${p.month_eur !== null ? ' · ' + sg(+p.month_eur, 0) + ' € / mies.' : ''}</small></td>
                <td>${esc(p.by_name || '—')}<small>${dmy(p.created_at)}</small></td>
                <td>${+p.sites ? (p.status === 'applied' ? `${p.sites_sent} / ${p.sites} potwierdzone${+p.sites_failed ? ` <span class="kw-badge bad">${p.sites_failed} bez połączenia</span>` : ''}` : sklepy(+p.sites)) : '—'}</td>
                <td>${+p.notify_email ? (+p.emails_queued ? p.emails_queued + ' / ' + p.emails + ' wysłane' : p.emails + ' · ' + dmy(p.notice_date)) : 'bez e-maila'}</td>
                <td class="r">${badge(p)}</td>
                <td class="r"><button type="button" class="kw-btn ghost kw-sm" data-detail="${p.id}">Szczegóły</button>${p.status === 'planned' ? `${+p.notify_email ? `<button type="button" class="kw-btn ghost kw-sm" data-mails="${p.id}">E-maile</button>` : ''}<button type="button" class="kw-btn ghost kw-sm" data-cancel="${p.id}">Anuluj</button>` : ''}</td></tr>`).join('')}
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
        if (t.hasAttribute('data-go-pubs')) { if (S.step < 3) go(4); else document.getElementById('pwPubs')?.scrollIntoView({behavior: 'smooth'}); return; }
        if (t.hasAttribute('data-keep')) { S.openKeep = !S.openKeep; render(); return; }
        if (t.hasAttribute('data-rules')) { S.showRules = !S.showRules; render(); return; }
        if (t.hasAttribute('data-reviewed')) {
            busy(async () => {
                const out = await api('POST', '/ajax/price-wizard/reviewed');
                S.prices.reviewed = out.data;
                render(); toast('success', out.message); loadPath();
            });
            return;
        }
        if (t.dataset.sendNow) { sendNow(+t.dataset.sendNow); return; }
        if (t.dataset.mails) { S.pubId = +t.dataset.mails; S.mail = null; go(4); return; }
        if (t.hasAttribute('data-recalc')) {
            busy(async () => {
                const r = await api('POST', '/ajax/price-wizard/recalculate');
                S.costs = r.data; S.prices = null;
                render(); toast('success', r.message); loadPath();
            });
            return;
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
        if (t.hasAttribute('data-again')) { S.done = null; S.pubId = null; S.mail = null; S.prices = null; go(2); return; }
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
                if (Math.abs(S.price[id] - p.cur) >= 0.005) S.sel[id] = true;
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
    // [351] Parametry w linii (administrator) : zapis jednego parametru, propozycje liczone od nowa.
    body.addEventListener('change', (e) => {
        const t = e.target;
        if (!t.dataset.param || !boot.is_admin) return;
        e.stopPropagation();
        busy(async () => {
            const out = await api('PUT', '/ajax/price-wizard/params', {[t.dataset.param]: t.value});
            boot.params = out.params;
            setPrices((await api('GET', '/ajax/price-wizard/prices')).data);
            render(); toast('success', out.message); loadPath();
        });
    });
    body.addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target.matches('.pw-price, .pw-rule')) e.target.blur(); });

    async function editParams() {
        const P = S.prices ? S.prices.params : boot.params;
        if (!window.Swal) return;
        const r = await Swal.fire({title: 'Parametry reguły cen', showCancelButton: true, confirmButtonText: 'Zapisz', cancelButtonText: 'Anuluj', html:
            `<div class="pw-params"><label>Próg wzrostu kosztu surowców (%) — od niego podwyżka<input class="form-control" id="pwUp" value="${f(P.up_pct, 1)}"></label>
            <label>Próg spadku kosztu surowców (%) — od niego obniżka<input class="form-control" id="pwDown" value="${f(P.down_pct, 1)}"></label>
            <label>Maks. podwyżka naraz (%)<input class="form-control" id="pwCap" value="${f(P.cap_pct, 1)}"></label>
            <label>Maks. obniżka naraz (%)<input class="form-control" id="pwCut" value="${f(P.cut_pct, 1)}"></label>
            <label>Zaokrąglenie do (€) — podwyżka w górę, obniżka w dół<input class="form-control" id="pwRound" value="${f(P.round_eur, 2)}"></label>
            <label>Domyślny współczynnik surowców (×)<input class="form-control" id="pwCoef" value="${f(P.material_coef_default, 2)}"></label>
            <label>E-mail z cennikiem — dni przed datą<input class="form-control" id="pwNotice" value="${P.notice_days}"></label>
            <p class="text-muted small mb-0">Progi : od 0,5 do 50 %. Limity i zaokrąglenie : te same dla cen standardowych i cen klientów (Ceny klientów wg marży).</p></div>`,
            preConfirm: () => ({up_pct: document.getElementById('pwUp').value, down_pct: document.getElementById('pwDown').value,
                cap_pct: document.getElementById('pwCap').value, cut_pct: document.getElementById('pwCut').value, round_eur: document.getElementById('pwRound').value,
                material_coef_default: document.getElementById('pwCoef').value, notice_days: document.getElementById('pwNotice').value})});
        if (!r.isConfirmed) return;
        busy(async () => {
            const out = await api('PUT', '/ajax/price-wizard/params', r.value);
            boot.params = out.params;
            setPrices((await api('GET', '/ajax/price-wizard/prices')).data);
            render(); toast('success', out.message); loadPath();
        });
    }

    async function plan() {
        const V = S.preview;
        const date = S.date || V.default_date;
        const on = V.clients.filter((c) => !S.exclude[c.id]);
        const emails = S.notify ? on.filter((c) => c.email).length : 0;
        const sites = on.filter((c) => c.franchise).length;
        const ok = window.Swal ? (await Swal.fire({icon: 'question', title: 'Zatwierdzić zmianę cennika od ' + dmy(date) + ' ?', showCancelButton: true,
            confirmButtonText: 'Zatwierdź', cancelButtonText: 'Wróć',
            html: `${V.totals.products} ${ceny(V.totals.products)} standardowych (${V.totals.up} ▲ · ${V.totals.down} ▼, średnio ${sg(V.totals.avg_pct)} %) i ceny : ${klientow(on.length)}.<br>
                ${emails ? `E-mail z cennikiem : ${klientow(emails)} (${dmy(isoAdd(date, -V.params.notice_days) < today() ? today() : isoAdd(date, -V.params.notice_days))} albo od razu : « Wyślij teraz » w kroku 4).<br>` : ''}
                ${sites ? `Strona franczyzy : ${sklepy(sites)} w dniu zmiany.<br>` : ''}Do ${dmy(isoAdd(date, -1))} można anulować.`})).isConfirmed : confirm('Zatwierdzić?');
        if (!ok) return;
        busy(async () => {
            const out = await api('POST', '/ajax/price-wizard/publications', {date, items: selectedItems(), exclude: Object.keys(S.exclude).map(Number), notify: S.notify});
            S.pubs = out.publications;
            S.prices = null; S.preview = null; S.costs = null; S.exclude = {}; S.date = null; S.done = out.data;
            S.pubId = out.data.id; S.mail = null;
            toast('success', out.message);
            loadPath();
            go(4);
        });
    }

    /** [351] Krok 4 : e-maile tej publikacji od razu do kolejki wysyłki. */
    async function sendNow(id) {
        const n = (S.mail ? S.mail.clients : []).filter((c) => +c.email && !c.email_status).length;
        const ok = window.Swal ? (await Swal.fire({icon: 'question', title: 'Wysłać teraz ' + n + ' ' + plural(n, 'e-mail', 'e-maile', 'e-maili') + ' z cennikiem ?',
            text: 'E-maile trafią od razu do kolejki wysyłki (zamiast ' + dmy(S.mail.publication.notice_date) + ').', showCancelButton: true,
            confirmButtonText: 'Wyślij teraz', cancelButtonText: 'Wróć'})).isConfirmed : confirm('Wysłać teraz?');
        if (!ok) return;
        busy(async () => {
            const out = await api('POST', '/ajax/price-wizard/publications/' + id + '/send-now');
            S.mail = out.data; S.pubs = out.publications;
            render(); toast('success', out.message); loadPath();
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

    // #step=1 : koszty i marże (szczegóły) ; brak kroku = krok 2 ścieżki ; krok 3 potrzebuje wyboru cen z kroku 2.
    const m = /step=(\d)/.exec(location.hash);
    S.step = m ? Math.max(1, Math.min(4, +m[1])) : 2;
    if (S.step === 3) S.step = 2;
    if (!m) history.replaceState(null, '', '#step=2');
    load().then(() => {
        if (S.step === 4 && !S.pubId) { go(4); }
    });
    loadPath();
})();
