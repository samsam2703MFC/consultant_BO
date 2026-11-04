/*
 * [243] Reklamacje › przełącznik « Lista / Wykres ». Wykres ma dwie zakładki :
 *  - « Przegląd » (makieta 15) : kafelki, reklamacje miesięcznie wg decyzji, wartość przyjęta miesięcznie, przyczyny,
 *    grupy produkcji (klik = filtr grupy), produkty z najwięcej reklamacjami ;
 *  - « Oś czasu » (makieta 16) : wszystkie reklamacje na osi czasu.
 * Wiersz = grupa produkcji produktu, położenie = dzień zgłoszenia, wielkość = wartość (ilość × cena kartonu z WDT),
 * kolor = decyzja (przyjęta / odrzucona / otwarta). Najechanie → szczegóły, kliknięcie → reklamacja na liście.
 * Filtry : okres (3 mies., 12 mies., ten rok, wszystko), klient, grupa, decyzja. Pod osią : liczba reklamacji na miesiąc.
 */
(function () {
    'use strict';
    const dataEl = document.getElementById('rk-chart-data');
    const root = document.getElementById('rk-chart');
    const list = document.getElementById('rk-list');
    if (!dataEl || !root || !list) return;

    let D;
    try { D = JSON.parse(dataEl.textContent || '{}'); } catch (e) { return; }
    const ALL = (D.complaints || []).filter(c => /^\d{4}-\d{2}-\d{2}$/.test(c.day));
    const GROUPS = D.groups || [];
    const TODAY = parseDay(D.today || new Date().toISOString().slice(0, 10));

    const ST = {acc: ['Przyjęte', 'var(--rk-acc)', 'Przyjęta'], rej: ['Odrzucone', 'var(--rk-rej)', 'Odrzucona'], open: ['Otwarte', 'var(--rk-open)', 'Otwarta']};
    const OPEN_LABEL = {NEW: 'nowa', IN_REVIEW: 'w weryfikacji'};
    const MN = ['sty', 'lut', 'mar', 'kwi', 'maj', 'cze', 'lip', 'sie', 'wrz', 'paź', 'lis', 'gru'];
    const PERIODS = [['3m', '3 mies.'], ['12m', '12 mies.'], ['year', 'Ten rok'], ['all', 'Wszystko']];
    const SVGNS = 'http://www.w3.org/2000/svg';

    const S = {tab: 'overview', period: '12m', customer: '', group: '', state: ''};
    try {
        const saved = JSON.parse(localStorage.getItem('complaints_chart_filters') || 'null');
        if (saved && typeof saved === 'object') Object.assign(S, saved);
    } catch (e) { /* bez pamięci */ }

    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, ch => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[ch]));
    const nf = (v, d = 0) => Number(v || 0).toLocaleString('pl-PL', {minimumFractionDigits: d, maximumFractionDigits: d}).replace(/ /g, ' ');
    const plural = (n, a, b, c) => n === 1 ? a : (n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 12 || n % 100 > 14) ? b : c);
    function parseDay(s) { const [y, m, d] = s.split('-').map(Number); return new Date(Date.UTC(y, m - 1, d)); }
    function fmtDay(d) { return `${String(d.getUTCDate()).padStart(2, '0')}.${String(d.getUTCMonth() + 1).padStart(2, '0')}.${d.getUTCFullYear()}`; }
    const monthStart = (d) => new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), 1));
    const addMonths = (d, n) => new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + n, 1));
    const DAY = 86400000;

    function rowsDef() {
        const out = GROUPS.map(g => ({key: 'g' + g.id, name: g.name}));
        out.push({key: 'none', name: 'bez grupy'}, {key: 'other', name: 'bez produktu'});
        return out;
    }

    function periodStart() {
        const m0 = monthStart(TODAY);
        if (S.period === '3m') return addMonths(m0, -2);
        if (S.period === '12m') return addMonths(m0, -11);
        if (S.period === 'year') return new Date(Date.UTC(TODAY.getUTCFullYear(), 0, 1));
        return ALL.length ? monthStart(parseDay(ALL[0].day)) : addMonths(m0, -11);
    }

    function filtered() {
        const from = periodStart();
        return ALL.filter(c => {
            const d = parseDay(c.day);
            if (d < from || d > TODAY) return false;
            if (S.customer && String(c.customer_id) !== S.customer) return false;
            if (S.group && c.row !== S.group) return false;
            if (S.state && c.state !== S.state) return false;
            return true;
        });
    }

    // --- Filtry (jeden wiersz nad wykresem) ---------------------------------------------------------------------------
    function renderFilters() {
        const box = root.querySelector('[data-rk-filters]');
        const customers = new Map();
        ALL.forEach(c => { if (c.customer_id !== null) customers.set(String(c.customer_id), c.customer); });
        const custOpts = [...customers.entries()].sort((a, b) => a[1].localeCompare(b[1], 'pl'));
        const rowsUsed = new Set(ALL.map(c => c.row));
        const groupOpts = rowsDef().filter(r => rowsUsed.has(r.key));
        if (S.customer && !customers.has(S.customer)) S.customer = '';
        if (S.group && !rowsUsed.has(S.group)) S.group = '';
        box.innerHTML = `<span class="rk-lab">Okres</span>
            ${PERIODS.map(([k, l]) => `<button type="button" class="rk-chip${S.period === k ? ' on' : ''}" data-rk-period="${k}" aria-pressed="${S.period === k}">${l}</button>`).join('')}
            <span class="rk-sp"></span>
            <label class="rk-sel"><i class="bi bi-shop" aria-hidden="true"></i><span class="visually-hidden">Klient</span>
                <select data-rk-f="customer"><option value="">Wszyscy klienci</option>${custOpts.map(([id, n]) => `<option value="${esc(id)}"${S.customer === id ? ' selected' : ''}>${esc(n)}</option>`).join('')}</select></label>
            <label class="rk-sel"><i class="bi bi-diagram-3" aria-hidden="true"></i><span class="visually-hidden">Grupa</span>
                <select data-rk-f="group"><option value="">Wszystkie grupy</option>${groupOpts.map(r => `<option value="${r.key}"${S.group === r.key ? ' selected' : ''}>${esc(r.name)}</option>`).join('')}</select></label>
            <label class="rk-sel"><i class="bi bi-flag" aria-hidden="true"></i><span class="visually-hidden">Decyzja</span>
                <select data-rk-f="state"><option value="">Wszystkie decyzje</option>${Object.entries(ST).map(([k, v]) => `<option value="${k}"${S.state === k ? ' selected' : ''}>${v[0]}</option>`).join('')}</select></label>`;
    }

    function save() { try { localStorage.setItem('complaints_chart_filters', JSON.stringify(S)); } catch (e) { /* */ } }

    // --- Zakładki : Przegląd / Oś czasu -------------------------------------------------------------------------------
    const NOTES = {
        overview: '<i class="bi bi-info-circle"></i> Najedź na słupek → liczby miesiąca ; kliknij grupę → filtr tej grupy. Wartość przyjęta = ilość przyjęta × cena kartonu z faktury WDT (jak raport miesięczny).',
        timeline: '<i class="bi bi-info-circle"></i> Najedź na punkt → szczegóły ; kliknij → reklamacja otwiera się na liście. Kilka reklamacji tego samego dnia w grupie = punkty jeden nad drugim.',
    };
    function render() {
        hideTip();
        if (S.tab !== 'timeline') S.tab = 'overview';
        root.querySelectorAll('[data-rk-tab]').forEach(b => { const on = b.dataset.rkTab === S.tab; b.classList.toggle('on', on); b.setAttribute('aria-selected', on ? 'true' : 'false'); });
        root.querySelectorAll('[data-rk-pane]').forEach(p => { p.hidden = p.dataset.rkPane !== S.tab; });
        const note = root.querySelector('[data-rk-note]');
        if (note) note.innerHTML = NOTES[S.tab];
        if (S.tab === 'timeline') renderTimeline(); else renderOverview();
    }

    // Miesiące wykresu : od początku okresu, bez pustych miesięcy przed pierwszą reklamacją (min. 3 miesiące), do dziś.
    function monthsFor(items) {
        let from = periodStart();
        if (items.length) { const f = monthStart(parseDay(items[0].day)); if (f > from) from = f; }
        const minFrom = addMonths(monthStart(TODAY), -2);
        if (from > minFrom) from = minFrom;
        const out = [];
        for (let m = new Date(from); m <= TODAY; m = addMonths(m, 1)) out.push(m);
        return out;
    }
    const mKey = (m) => `${m.getUTCFullYear()}-${String(m.getUTCMonth() + 1).padStart(2, '0')}`;
    const niceMax = (v) => { for (const m of [2, 4, 5, 6, 8, 10, 12, 15, 20, 25, 30, 40, 50, 60, 80, 100]) if (v <= m) return m; return Math.ceil(v / 50) * 50; };
    const colPath = (x, bw, y0, y1, r) => { r = Math.min(r, (y0 - y1) / 2, bw / 2); return `M${x.toFixed(1)},${y0.toFixed(1)} V${(y1 + r).toFixed(1)} Q${x.toFixed(1)},${y1.toFixed(1)} ${(x + r).toFixed(1)},${y1.toFixed(1)} H${(x + bw - r).toFixed(1)} Q${(x + bw).toFixed(1)},${y1.toFixed(1)} ${(x + bw).toFixed(1)},${(y1 + r).toFixed(1)} V${y0.toFixed(1)} Z`; };

    // --- Przegląd (makieta 15) -----------------------------------------------------------------------------------------
    let OV = {months: [], per: {}};
    function renderOverview() {
        const pane = root.querySelector('[data-rk-pane="overview"]');
        const items = filtered();
        const n = items.length;
        const cnt = {acc: 0, rej: 0, open: 0};
        items.forEach(c => { cnt[c.state]++; });
        const accVal = items.reduce((a, c) => a + (c.accepted_value || 0), 0);
        const dd = items.map(c => c.decision_days).filter(v => v !== null && v !== undefined);
        const avgDd = dd.length ? dd.reduce((a, b) => a + b, 0) / dd.length : null;
        const decided = cnt.acc + cnt.rej;
        const months = monthsFor(items);
        const curM = mKey(monthStart(TODAY));
        const tiles = `<div class="rk-tiles">
            <div class="rk-tile"><span>Reklamacje</span><b>${n}</b><small>${cnt.open} ${plural(cnt.open, 'otwarta', 'otwarte', 'otwartych')} · ${items.filter(c => c.day.slice(0, 7) === curM).length} w tym miesiącu</small></div>
            <div class="rk-tile"><span>Wartość przyjęta</span><b>${nf(accVal)} €</b><small>ilość przyjęta × cena kartonu (WDT)</small></div>
            <div class="rk-tile"><span>Przyjęte</span><b>${decided ? Math.round(cnt.acc / decided * 100) + ' %' : '—'}</b><small>${cnt.acc} ${plural(cnt.acc, 'przyjęta', 'przyjęte', 'przyjętych')} · ${cnt.rej} ${plural(cnt.rej, 'odrzucona', 'odrzucone', 'odrzuconych')}</small></div>
            <div class="rk-tile"><span>Czas decyzji</span><b>${avgDd === null ? '—' : nf(avgDd, 1) + ' ' + (Math.abs(avgDd - 1) < .05 ? 'dzień' : 'dnia')}</b><small>średnio od zgłoszenia do decyzji${dd.length ? ` · ${dd.length} ${plural(dd.length, 'reklamacja', 'reklamacje', 'reklamacji')}` : ''}</small></div></div>`;
        if (!n) {
            pane.innerHTML = tiles + '<div class="rk-empty"><i class="bi bi-inbox"></i> Brak reklamacji w tym okresie i przy tych filtrach.</div>';
            return;
        }
        const per = {};
        months.forEach(m => { per[mKey(m)] = {acc: 0, rej: 0, open: 0, n: 0, val: 0}; });
        items.forEach(c => { const p = per[c.day.slice(0, 7)]; if (p) { p[c.state]++; p.n++; p.val += c.accepted_value || 0; } });
        OV = {months, per};
        const agg = (key, label) => {
            const m = new Map();
            items.forEach(c => { const k = key(c); const e = m.get(k) || {k, name: label(c), n: 0, v: 0}; e.n++; e.v += c.accepted_value || 0; m.set(k, e); });
            return [...m.values()].sort((a, b) => b.n - a.n || b.v - a.v);
        };
        const rowName = {};
        rowsDef().forEach(r => { rowName[r.key] = r.name; });
        const bars = (list, head, opts = {}) => {
            const mx = Math.max(1, ...list.map(e => e.n));
            return `<div class="rk-bars"><div class="rk-bar hd"><span>${head}</span><span></span><span class="n">Liczba</span><span class="v">Wartość</span></div>`
                + list.map(e => `<${opts.click ? `button type="button" data-rk-group="${esc(e.k)}"` : 'div'} class="rk-bar${opts.click && S.group === e.k ? ' on' : ''}"${opts.click ? ` title="Filtr : ${esc(e.name)}"` : ''}>`
                    + `<span class="nm" title="${esc(e.name)}">${esc(e.name)}</span><span class="tr"><i style="width:${(e.n / mx * 100).toFixed(1)}%"></i></span>`
                    + `<span class="n">${e.n}</span><span class="v">${e.v ? nf(e.v) + ' €' : '—'}</span></${opts.click ? 'button' : 'div'}>`).join('') + '</div>';
        };
        pane.innerHTML = tiles + `<div class="rk-grid">
            <div class="rk-col">
              <div class="rk-card2"><h5>Reklamacje miesięcznie</h5><div class="rk-sub">liczba reklamacji wg decyzji</div>
                <div class="rk-legend">${Object.entries(ST).map(([k, v]) => `<span><i class="sq" style="background:${v[1]}"></i>${v[0]}</span>`).join('')}</div>
                <div data-rk-months></div></div>
              <div class="rk-card2"><h5>Wartość przyjętych reklamacji</h5><div class="rk-sub">€ miesięcznie</div><div data-rk-values></div></div>
            </div>
            <div class="rk-col">
              <div class="rk-card2"><h5>Przyczyny</h5><div class="rk-sub">wszystkie reklamacje okresu</div>${bars(agg(c => c.reason || '—', c => c.reason || '—'), 'Przyczyna')}</div>
              <div class="rk-card2"><h5>Grupy produkcji</h5><div class="rk-sub">grupa, która robi produkt · kliknij → filtr grupy</div>${bars(agg(c => c.row, c => rowName[c.row] || c.row), 'Grupa', {click: true})}</div>
              <div class="rk-card2"><h5>Produkty — najwięcej reklamacji</h5><div class="rk-sub">6 pierwszych</div>${bars(agg(c => c.product, c => c.product).slice(0, 6), 'Produkt')}</div>
            </div></div>`;
        drawMonths(pane.querySelector('[data-rk-months]'), months, per);
        drawValues(pane.querySelector('[data-rk-values]'), months, per);
    }

    function drawMonths(box, months, per) {
        const W = Math.max(300, box.clientWidth || 600), H = 230, L = 34, B = 26, T = 20, R = 6;
        const mx = niceMax(Math.max(1, ...months.map(m => per[mKey(m)].n)));
        const step = mx <= 6 ? 1 : (mx <= 12 ? 2 : (mx <= 30 ? 5 : 10));
        const ph = H - B - T, slot = (W - L - R) / months.length, bw = Math.min(24, slot * .55);
        const y = (v) => T + ph - v / mx * ph;
        const best = months.reduce((a, m) => per[mKey(m)].n > per[mKey(a)].n ? m : a, months[0]);
        const out = [`<svg class="rk-svg" width="${W}" height="${H}" viewBox="0 0 ${W} ${H}" role="img" aria-label="Reklamacje miesięcznie wg decyzji">`];
        for (let t = 0; t <= mx; t += step) out.push(`<line x1="${L}" x2="${W - R}" y1="${y(t).toFixed(1)}" y2="${y(t).toFixed(1)}" class="rk-grid"/><text class="rk-ax" x="${L - 6}" y="${(y(t) + 4).toFixed(1)}" text-anchor="end">${t}</text>`);
        months.forEach((m, i) => {
            const p = per[mKey(m)], cx = L + slot * i + slot / 2, x = cx - bw / 2;
            const segs = ['acc', 'rej', 'open'].filter(k => p[k]);
            let base = 0;
            segs.forEach((k, j) => {
                const y0 = y(base), y1 = y(base + p[k]), top = j === segs.length - 1;
                out.push(top ? `<path d="${colPath(x, bw, y0, y1, 4)}" fill="${ST[k][1]}"/>` : `<rect x="${x.toFixed(1)}" y="${(y1 + 2).toFixed(1)}" width="${bw.toFixed(1)}" height="${Math.max(0, y0 - y1 - 2).toFixed(1)}" fill="${ST[k][1]}"/>`);
                base += p[k];
            });
            if (p.n && (m === best || i === months.length - 1)) out.push(`<text class="rk-dl" x="${cx.toFixed(1)}" y="${(y(p.n) - 6).toFixed(1)}" text-anchor="middle">${p.n}</text>`);
            out.push(`<text class="rk-ax" x="${cx.toFixed(1)}" y="${H - 8}" text-anchor="middle">${MN[m.getUTCMonth()]}${m.getUTCMonth() === 0 || i === 0 ? ' ' + String(m.getUTCFullYear()).slice(2) : ''}</text>`);
            out.push(`<rect class="rk-hitcol" data-rk-m="${i}" x="${(L + slot * i).toFixed(1)}" y="${T}" width="${slot.toFixed(1)}" height="${ph}" tabindex="0" role="button" aria-label="${MN[m.getUTCMonth()]} ${m.getUTCFullYear()} : ${p.n}"/>`);
        });
        out.push(`<line x1="${L}" x2="${W - R}" y1="${y(0).toFixed(1)}" y2="${y(0).toFixed(1)}" class="rk-base"/></svg>`);
        box.innerHTML = out.join('');
    }

    function drawValues(box, months, per) {
        const W = Math.max(300, box.clientWidth || 600), H = 150, L = 64, B = 22, T = 18, R = 6;
        const raw = Math.max(1, ...months.map(m => per[mKey(m)].val));
        const p10 = Math.pow(10, Math.floor(Math.log10(raw)));
        const mx = Math.ceil(raw / p10) * p10;
        const ph = H - B - T, slot = (W - L - R) / months.length, bw = Math.min(24, slot * .55);
        const y = (v) => T + ph - v / mx * ph;
        const lab = (v) => v >= 1000 ? nf(v / 1000, v % 1000 ? 1 : 0) + ' tys. €' : nf(v) + ' €';
        const best = months.reduce((a, m) => per[mKey(m)].val > per[mKey(a)].val ? m : a, months[0]);
        const out = [`<svg class="rk-svg" width="${W}" height="${H}" viewBox="0 0 ${W} ${H}" role="img" aria-label="Wartość przyjętych reklamacji miesięcznie">`];
        [0, mx / 2, mx].forEach(t => out.push(`<line x1="${L}" x2="${W - R}" y1="${y(t).toFixed(1)}" y2="${y(t).toFixed(1)}" class="rk-grid"/><text class="rk-ax" x="${L - 6}" y="${(y(t) + 4).toFixed(1)}" text-anchor="end">${t ? lab(t) : '0 €'}</text>`));
        months.forEach((m, i) => {
            const v = per[mKey(m)].val, cx = L + slot * i + slot / 2;
            if (v > 0) out.push(`<path d="${colPath(cx - bw / 2, bw, y(0), y(v), 4)}" class="rk-bar"/>`);
            if (v > 0 && (m === best || i === months.length - 1)) out.push(`<text class="rk-dl" x="${cx.toFixed(1)}" y="${(y(v) - 6).toFixed(1)}" text-anchor="middle">${nf(v)} €</text>`);
            out.push(`<text class="rk-ax" x="${cx.toFixed(1)}" y="${H - 6}" text-anchor="middle">${MN[m.getUTCMonth()]}</text>`);
        });
        out.push(`<line x1="${L}" x2="${W - R}" y1="${y(0).toFixed(1)}" y2="${y(0).toFixed(1)}" class="rk-base"/></svg>`);
        box.innerHTML = out.join('');
    }

    function showMonthTip(i, el) {
        const m = OV.months[i];
        if (!m || !tip) return;
        const p = OV.per[mKey(m)];
        tip.innerHTML = `<b>${['styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'][m.getUTCMonth()]} ${m.getUTCFullYear()} · ${p.n} ${plural(p.n, 'reklamacja', 'reklamacje', 'reklamacji')}</b>`
            + Object.entries(ST).map(([k, v]) => `<div class="r"><span><i style="background:${v[1]}"></i>${v[0]}</span><b>${p[k]}</b></div>`).join('')
            + `<div class="r"><span>Wartość przyjęta</span><b>${nf(p.val)} €</b></div>`;
        tip.hidden = false;
        const host = root.getBoundingClientRect(), r = el.getBoundingClientRect();
        const tw = tip.offsetWidth;
        let left = r.left - host.left + r.width / 2 + 12;
        if (left + tw > host.width - 8) left = r.left - host.left + r.width / 2 - 12 - tw;
        tip.style.left = Math.max(8, left) + 'px';
        tip.style.top = (r.top - host.top + 8) + 'px';
    }

    // --- Oś czasu ------------------------------------------------------------------------------------------------------
    let pts = [];
    function renderTimeline() {
        const plot = root.querySelector('[data-rk-plot]');
        const legend = root.querySelector('[data-rk-legend]');
        const items = filtered();
        const count = {acc: 0, rej: 0, open: 0};
        items.forEach(c => { count[c.state]++; });
        const maxv = Math.max(1, ...items.map(c => c.value || 0));
        const rad = (v) => 5 + 11 * Math.sqrt(Math.max(0, v || 0) / maxv);
        const sizeVals = [...new Set([maxv / 25, maxv / 4, maxv].map(v => {
            const p = Math.pow(10, Math.floor(Math.log10(Math.max(v, 1))));
            return Math.max(1, Math.round(v / p) * p);
        }))];
        legend.innerHTML = Object.entries(ST).map(([k, v]) => `<span><i class="dot" style="background:${v[1]}"></i>${v[0]} <b>${count[k]}</b></span>`).join('')
            + `<span class="rk-sp"></span>${items.length ? `<span class="rk-muted">wartość :</span>${sizeVals.map(v => `<span class="rk-size"><svg width="${(2 * rad(v) + 4).toFixed(0)}" height="34" aria-hidden="true"><circle cx="${(rad(v) + 2).toFixed(1)}" cy="17" r="${rad(v).toFixed(1)}" fill="#c9c3ba"/></svg>${nf(v)} €</span>`).join('')}` : ''}`;
        if (!items.length) {
            plot.innerHTML = '<div class="rk-empty"><i class="bi bi-inbox"></i> Brak reklamacji w tym okresie i przy tych filtrach.</div>';
            pts = [];
            return;
        }
        // Zakres : początek okresu, ale bez pustych miesięcy przed pierwszą reklamacją (min. 3 miesiące).
        let from = periodStart();
        const firstM = monthStart(parseDay(items[0].day));
        if (firstM > from) from = firstM;
        const minFrom = addMonths(monthStart(TODAY), -2);
        if (from > minFrom) from = minFrom;
        const to = new Date(Date.UTC(TODAY.getUTCFullYear(), TODAY.getUTCMonth() + 1, 1));

        const W = Math.max(320, plot.clientWidth || 900);
        const narrow = W < 640;
        const LBL = narrow ? 92 : 130, R = 14, TOP = 26;
        const xs = (d) => LBL + (d - from) / (to - from) * (W - LBL - R);

        // Wiersze : grupy z reklamacjami (kolejność menu), potem bez grupy, bez produktu.
        const used = new Set(items.map(c => c.row));
        const rows = rowsDef().filter(r => used.has(r.key));
        const byKey = {};
        items.forEach(c => { (byKey[c.row + '|' + c.day] = byKey[c.row + '|' + c.day] || []).push(c); });
        const stack = {};
        Object.entries(byKey).forEach(([k, l]) => { const r = k.split('|')[0]; stack[r] = Math.max(stack[r] || 1, l.length); });
        let y = TOP;
        const rowY = {};
        rows.forEach(r => { const h = Math.max(58, (stack[r.key] || 1) * 14 + 30); rowY[r.key] = {top: y, h}; y += h; });
        const H = y + 8;

        const svg = [`<svg class="rk-svg" width="${W}" height="${H}" viewBox="0 0 ${W} ${H}" role="img" aria-label="Oś czasu reklamacji : ${items.length} ${plural(items.length, 'reklamacja', 'reklamacje', 'reklamacji')}">`];
        for (let m = new Date(from); m < to; m = addMonths(m, 1)) {
            const x = xs(m);
            const lab = narrow ? MN[m.getUTCMonth()] : `${MN[m.getUTCMonth()]} ${m.getUTCFullYear()}`;
            svg.push(`<line x1="${x.toFixed(1)}" x2="${x.toFixed(1)}" y1="${TOP - 6}" y2="${y}" class="rk-grid"/><text class="rk-ax" x="${(x + 4).toFixed(1)}" y="${TOP - 10}">${lab}</text>`);
        }
        rows.forEach(r => {
            const {top, h} = rowY[r.key];
            const n = items.filter(c => c.row === r.key).length;
            svg.push(`<line x1="${LBL}" x2="${W - R}" y1="${top + h}" y2="${top + h}" class="rk-grid"/>`
                + `<text class="rk-rowname" x="0" y="${(top + h / 2 - 2).toFixed(1)}">${esc(r.name)}</text>`
                + `<text class="rk-ax" x="0" y="${(top + h / 2 + 14).toFixed(1)}">${n} ${plural(n, 'reklamacja', 'reklamacje', 'reklamacji')}</text>`);
        });
        pts = [];
        Object.entries(byKey).forEach(([k, l]) => {
            const [row, day] = k.split('|');
            const {top, h} = rowY[row];
            const yc = top + h / 2;
            const sorted = l.slice().sort((a, b) => (b.value || 0) - (a.value || 0));
            sorted.forEach((c, j) => {
                const off = sorted.length > 1 ? (j - (sorted.length - 1) / 2) * 14 : 0;
                pts.push({c, x: xs(parseDay(day)) + DAY / (to - from) * (W - LBL - R) / 2, y: yc + off, r: rad(c.value)});
            });
        });
        pts.sort((a, b) => b.r - a.r).forEach((p, i) => {
            const st = ST[p.c.state];
            const label = `${p.c.product}, ${fmtDay(parseDay(p.c.day))}, ${p.c.customer}, ${nf(p.c.value)} €, ${st[2]}`;
            svg.push(`<g class="rk-pt" data-i="${i}" tabindex="0" role="button" aria-label="${esc(label)}">`
                + `<circle class="rk-hit" cx="${p.x.toFixed(1)}" cy="${p.y.toFixed(1)}" r="${(p.r + 3).toFixed(1)}"/>`
                + `<circle class="rk-dot" cx="${p.x.toFixed(1)}" cy="${p.y.toFixed(1)}" r="${p.r.toFixed(1)}" fill="${st[1]}"/></g>`);
        });
        svg.push('</svg>');

        // Pasek : liczba reklamacji na miesiąc (ta sama oś czasu).
        const perM = {};
        items.forEach(c => { const k = c.day.slice(0, 7); perM[k] = (perM[k] || 0) + 1; });
        const maxM = Math.max(1, ...Object.values(perM));
        const SH = 82, base = 62;
        const strip = [`<svg class="rk-svg" width="${W}" height="${SH}" viewBox="0 0 ${W} ${SH}" aria-hidden="true">`];
        for (let m = new Date(from); m < to; m = addMonths(m, 1)) {
            const k = `${m.getUTCFullYear()}-${String(m.getUTCMonth() + 1).padStart(2, '0')}`;
            const v = perM[k] || 0;
            const cx = (xs(m) + xs(addMonths(m, 1))) / 2;
            const hgt = v / maxM * 40;
            const bw = Math.min(24, (xs(addMonths(m, 1)) - xs(m)) * .5);
            if (v) {
                const r = Math.min(4, hgt / 2);
                strip.push(`<path d="M${(cx - bw / 2).toFixed(1)},${base} V${(base - hgt + r).toFixed(1)} Q${(cx - bw / 2).toFixed(1)},${(base - hgt).toFixed(1)} ${(cx - bw / 2 + r).toFixed(1)},${(base - hgt).toFixed(1)} H${(cx + bw / 2 - r).toFixed(1)} Q${(cx + bw / 2).toFixed(1)},${(base - hgt).toFixed(1)} ${(cx + bw / 2).toFixed(1)},${(base - hgt + r).toFixed(1)} V${base} Z" class="rk-bar"/>`
                    + `<text class="rk-dl" x="${cx.toFixed(1)}" y="${(base - hgt - 5).toFixed(1)}" text-anchor="middle">${v}</text>`);
            }
            strip.push(`<text class="rk-ax" x="${cx.toFixed(1)}" y="${SH - 4}" text-anchor="middle">${MN[m.getUTCMonth()]}</text>`);
        }
        strip.push(`<line x1="${LBL}" x2="${W - R}" y1="${base}" y2="${base}" class="rk-base"/><text class="rk-ax" x="0" y="${base - 14}">liczba / miesiąc</text></svg>`);
        plot.innerHTML = svg.join('') + strip.join('');
    }

    // --- Dymek ---------------------------------------------------------------------------------------------------------
    const tip = root.querySelector('[data-rk-tip]');
    function showTip(i) {
        const p = pts[i];
        if (!p || !tip) return;
        const c = p.c, st = ST[c.state];
        const decision = c.state === 'open' ? `${st[2]}${OPEN_LABEL[c.status] ? ' · ' + OPEN_LABEL[c.status] : ''}` : st[2];
        tip.innerHTML = `<b>${esc(c.product)}</b>
            <div class="r"><span>Zgłoszona</span><b>${fmtDay(parseDay(c.day))}</b></div>
            <div class="r"><span>Klient</span><b>${esc(c.customer)}</b></div>
            ${c.qty ? `<div class="r"><span>Ilość</span><b>${nf(c.qty, c.qty % 1 ? 1 : 0)} kart.</b></div>` : ''}
            <div class="r"><span>Wartość</span><b>${nf(c.value)} €</b></div>
            ${c.accepted_value !== null && c.accepted_value !== undefined ? `<div class="r"><span>Przyjęte</span><b>${nf(c.accepted_value)} €</b></div>` : ''}
            <div class="r"><span>Przyczyna</span><b>${esc(c.reason || '—')}</b></div>
            <div class="r"><span>Decyzja</span><b><i style="background:${st[1]}"></i>${esc(decision)}</b></div>
            <div class="go">kliknij → otwórz reklamację</div>`;
        tip.hidden = false;
        const box = root.getBoundingClientRect();
        const plot = root.querySelector('[data-rk-plot]').getBoundingClientRect();
        const tw = tip.offsetWidth, th = tip.offsetHeight;
        let left = plot.left - box.left + p.x + p.r + 12;
        if (left + tw > box.width - 8) left = plot.left - box.left + p.x - p.r - 12 - tw;
        let top = plot.top - box.top + p.y - th / 2;
        top = Math.max(8, Math.min(top, box.height - th - 8));
        tip.style.left = Math.max(8, left) + 'px';
        tip.style.top = top + 'px';
    }
    function hideTip() { if (tip) tip.hidden = true; }

    // --- Otwórz reklamację na liście -----------------------------------------------------------------------------------
    function openComplaint(id) {
        setView('list');
        if (typeof window.applyStatusFilter === 'function') window.applyStatusFilter('ALL');
        const col = document.getElementById('collapse-' + id);
        const item = col ? col.closest('.complaint-item') : null;
        if (col && !col.classList.contains('show')) {
            // Przycisk nagłówka akordeonu (ten sam co ręczne kliknięcie ; działa w każdej wersji Bootstrapa).
            const btn = document.querySelector(`[data-bs-target="#collapse-${id}"]`);
            if (btn) btn.click(); else col.classList.add('show');
        }
        if (item) {
            item.scrollIntoView({behavior: 'smooth', block: 'start'});
            item.classList.add('rk-flash');
            setTimeout(() => item.classList.remove('rk-flash'), 2200);
        }
    }

    // --- Przełącznik Lista / Wykres ------------------------------------------------------------------------------------
    function setView(v) {
        const chart = v === 'chart';
        root.hidden = !chart;
        list.hidden = chart;
        document.querySelectorAll('[data-rk-view]').forEach(b => {
            const on = b.dataset.rkView === v;
            b.classList.toggle('on', on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        try { localStorage.setItem('complaints_view', v); } catch (e) { /* */ }
        if (chart) { renderFilters(); render(); }
    }

    document.addEventListener('click', (e) => {
        const vb = e.target.closest('[data-rk-view]');
        if (vb) return setView(vb.dataset.rkView);
        if (!root.contains(e.target)) return;
        const per = e.target.closest('[data-rk-period]');
        if (per) { S.period = per.dataset.rkPeriod; save(); renderFilters(); return render(); }
        const tb = e.target.closest('[data-rk-tab]');
        if (tb) { S.tab = tb.dataset.rkTab; save(); return render(); }
        const gb = e.target.closest('[data-rk-group]');
        if (gb) { S.group = S.group === gb.dataset.rkGroup ? '' : gb.dataset.rkGroup; save(); renderFilters(); return render(); }
        const pt = e.target.closest('.rk-pt');
        if (pt && pts[pt.dataset.i]) openComplaint(pts[pt.dataset.i].c.id);
    });
    root.addEventListener('change', (e) => {
        const f = e.target.closest('[data-rk-f]');
        if (!f) return;
        S[f.dataset.rkF] = f.value;
        save();
        render();
    });
    root.addEventListener('pointerover', (e) => {
        const pt = e.target.closest('.rk-pt');
        if (pt) return showTip(Number(pt.dataset.i));
        const mc = e.target.closest('[data-rk-m]');
        if (mc) showMonthTip(Number(mc.dataset.rkM), mc);
    });
    root.addEventListener('pointerout', (e) => { const pt = e.target.closest('.rk-pt, [data-rk-m]'); if (pt && !pt.contains(e.relatedTarget)) hideTip(); });
    root.addEventListener('focusin', (e) => {
        const pt = e.target.closest('.rk-pt');
        if (pt) return showTip(Number(pt.dataset.i));
        const mc = e.target.closest('[data-rk-m]');
        if (mc) showMonthTip(Number(mc.dataset.rkM), mc);
    });
    root.addEventListener('focusout', (e) => { if (e.target.closest('.rk-pt, [data-rk-m]')) hideTip(); });
    root.addEventListener('keydown', (e) => {
        const pt = e.target.closest('.rk-pt');
        if (pt && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); openComplaint(pts[pt.dataset.i].c.id); }
        if (e.key === 'Escape') hideTip();
    });
    let rt = null;
    window.addEventListener('resize', () => { if (root.hidden) return; clearTimeout(rt); rt = setTimeout(render, 150); });

    let start = 'list';
    if (location.hash === '#wykres') start = 'chart';
    else { try { start = localStorage.getItem('complaints_view') === 'chart' ? 'chart' : 'list'; } catch (e) { /* */ } }
    if (start === 'chart') setView('chart');
})();
