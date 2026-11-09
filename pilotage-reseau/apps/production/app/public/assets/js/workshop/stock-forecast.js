/*
 * Magazyn › Prognoza zapasu [216] (makiety 1–3) : zapas potrzebny wg planu logistyki (wyjazd — kalendarz zapisywany w planie
 * logistyki, powrót ciężarówki z dniami blokady, dni pracy, palety, produkty MTS na tydzień), okres stanów wyjątkowych (stany
 * z prognozy do poprawienia, zatwierdzenie, cofnięcie) i lista okresów. Serwer liczy wszystko od nowa po każdej zmianie.
 */
(function () {
    'use strict';
    const app = document.getElementById('stockForecastApp');
    if (!app) return;
    const root = app.dataset.root;
    let D = JSON.parse(document.getElementById('stockForecastBoot').textContent);
    let dirty = false;
    // Wiersze produktów : zapas potrzebny / do produkcji / zapas przed wyjazdem, w kartonach albo paletach (pamięć przeglądarki).
    const pref = (k, def, allowed) => { try { const v = localStorage.getItem('sx.' + k); return allowed.includes(v) ? v : def; } catch (e) { return def; } };
    let view = pref('view', 'need', ['need', 'prod', 'stock']);
    let unit = pref('unit', 'ct', ['ct', 'pal']);
    const VIEWS = {need: ['Zapas potrzebny', 'Razem zapas potrzebny'], prod: ['Do produkcji', 'Razem do produkcji'], stock: ['Zapas przed wyjazdem', 'Razem zapas przed wyjazdem']};
    const DAYS = ['nd', 'pn', 'wt', 'śr', 'czw', 'pt', 'sob'];
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const num = (v, d = 0) => v === null || v === undefined ? '—' : Number(v).toLocaleString('pl-PL', {minimumFractionDigits: d, maximumFractionDigits: d});
    const dt = (d) => { const t = new Date(d + 'T12:00:00'); return `${t.getDate()}.${String(t.getMonth() + 1).padStart(2, '0')}`; };
    const wd = (d) => DAYS[new Date(d + 'T12:00:00').getDay()];
    const full = (d) => d ? `${dt(d)}.${d.slice(0, 4)}` : '—';
    const sign = (v, d = 0) => v === null || v === undefined ? '—' : (v > 0 ? '+' : (v < 0 ? '−' : '')) + num(Math.abs(v), d);
    const pl = (n, one, few, many) => n === 1 ? one : ([2, 3, 4].includes(n % 10) && ![12, 13, 14].includes(n % 100) ? few : many);
    const addDays = (d, n) => new Date(new Date(d + 'T12:00:00').getTime() + n * 864e5).toISOString().slice(0, 10);

    async function call(method, url, body) {
        const res = await fetch(root + url, {method, credentials: 'same-origin',
            headers: {'Accept': 'application/json', 'Content-Type': 'application/json'}, body: body ? JSON.stringify(body) : undefined});
        const data = await res.json().catch(() => ({message: 'Błąd serwera (' + res.status + ').'}));
        if (!res.ok || data.ok === false) throw new Error(data.message || data.error || 'Błąd serwera.');
        return data;
    }
    const fail = (err) => window.Swal ? Swal.fire({icon: 'error', title: 'Nie zapisano', text: err.message}) : alert(err.message);
    const ok = (msg) => { if (window.Swal) Swal.fire({icon: 'success', title: msg, timer: 1600, showConfirmButton: false}); };
    const reload = async (periodId) => {
        const res = await call('GET', '/ajax/stock/forecast' + (periodId ? '?period=' + periodId : ''));
        D = res.data;
        dirty = false;
        render();
    };

    // --- Makieta 1 : zapas potrzebny wg planu logistyki ---------------------------------------------------
    function renderForecast() {
        const F = D.forecast;
        const W = F.weeks;
        const T = F.totals;
        const box = app.querySelector('[data-sx-forecast]');
        const approved = F.approved.all
            ? `<span class="sx-badge ok">Plan zatwierdzony · tyg. ${W[0].iso_week}–${W[W.length - 1].iso_week}${F.approved.last_at ? ' · ' + dt(F.approved.last_at.slice(0, 10)) : ''}</span>`
            : `<span class="sx-badge warn">Plan logistyki : zatwierdzone ${F.approved.weeks} z ${W.length} tygodni — prognoza wstępna</span>`;
        const hot = (v, max) => v !== null && max !== null && v > max;
        const offNames = T.off.map(o => dt(o.date)).join(', ');
        const dayTitle = (w) => w.off.length ? w.off.map(o => `${dt(o.date)} ${o.name}`).join(' ; ') : 'pn–pt';
        const retTitle = (w) => (w.return_from_trip ? 'Powrót z trasy (Kalendarz tras)' : 'Powrót = dostawa + dni drogi')
            + (w.return_blocked.length ? ' ; blokada : ' + w.return_blocked.map(b => `${dt(b.date)} ${b.why}`).join(', ') : '');
        const P = F.pallets;
        const over = (v) => P.places > 0 && v > P.places;
        // Wartość komórki produktu w wybranym widoku i jednostce (palety : kartony ÷ kartony na paletę).
        const val = (p, i) => { const v = p[view][i]; return v === null || v === undefined ? null : (unit === 'pal' ? (p.cpp ? v / p.cpp : null) : v); };
        const fmt = (v) => v === null ? '—' : (unit === 'pal' ? num(v, 1) : num(v));
        const cell = (p, i) => {
            const v = val(p, i);
            const off = W[i].working_days === 0 && view !== 'stock';
            const cls = off ? 'off' : (view === 'need' && hot(p.need[i], p.max) ? 'hot' : (view === 'prod' && v >= (unit === 'pal' ? 0.05 : 1) ? 'pos' : ''));
            return `<td class="${cls}">${off ? '—' : fmt(v)}</td>`;
        };
        const sums = W.map((w, i) => F.products.reduce((a, p) => a + (val(p, i) ?? 0), 0));
        const last = (p) => { if (view === 'need') return [p.peak, 'tydz. ' + (p.peak_week ?? '—')];
            const a = p[view].map((v, i) => val(p, i) ?? 0); return view === 'prod' ? [a.reduce((x, y) => x + y, 0), 'razem'] : [Math.max(...a), 'maks.']; };
        const lastCell = (p) => { const [v, t] = last(p); return `<td><b>${view === 'need' && unit === 'pal' ? (p.cpp ? num(v / p.cpp, 1) : '—') : fmt(v)}</b><small>${t}</small></td>`; };
        const rows = F.products.map(p => `<tr><td><b>${esc(p.name)}</b><small>${num(p.min)} / ${num(p.max)} kart.${p.cpp ? ' · ' + p.cpp + ' kart./pal.' : ''} · dziś ${num(p.stock_now)}${p.period ? ` <span class="sx-badge xm">${esc(p.period)}</span>` : ''}</small></td>
            ${W.map((w, i) => cell(p, i)).join('')}${lastCell(p)}</tr>`).join('');
        const sumLast = view === 'prod' ? sums.reduce((a, b) => a + b, 0) : Math.max(0, ...sums);
        const seg = (name, cur, opts) => `<div class="at-seg" role="group">${opts.map(([v, l]) => `<button type="button" data-sx-${name}="${v}" class="${cur === v ? 'is-on' : ''}" aria-pressed="${cur === v}">${l}</button>`).join('')}</div>`;
        const freezerTile = P.places > 0
            ? `<div><span>Zamrażarka w szczycie</span><b class="${over(P.stock_peak) ? 'sx-up' : ''}">${num(P.stock_peak, 1)} / ${num(P.places)} pal.</b><small>tydz. ${P.stock_peak_week ?? '—'}${P.over.length ? ' · ponad miejsca : tydz. ' + P.over.join(', ') : ' · mieści się'}</small></div>`
            : `<div><span>Zapas w szczycie</span><b>${num(P.stock_peak, 1)} pal.</b><small>tydz. ${P.stock_peak_week ?? '—'} · <a href="${root}/other-settings#stock-params">miejsca zamrażarki</a></small></div>`;
        box.innerHTML = `<div class="sx-card"><h5>Zapas potrzebny wg planu logistyki ${approved}</h5>
            <p class="sx-lead">Wyjazdy i dni pracy z planu 12 tygodni (święta ${esc(F.params.departure_country)}, dni wolne) ; kartony produktu = palety klientów z planu × ich koszyk produktów
                (${F.params.avg_weeks} tygodni) albo zamówienia, gdy już są. Zapas potrzebny przed wyjazdem = kartony wyjazdu + wyjazdy w tygodniach bez produkcji + ${num(F.params.safety_pct)} % bezpieczeństwa.
                Do produkcji = zapas potrzebny − zapas po poprzednim wyjeździe (od stanu dziś). Tylko produkty MTS.</p>
            <div class="sx-tiles six"><div><span>Szczyt zapasu</span><b>${num(T.peak)} kart.</b><small>${num(T.peak_pallets, 1)} pal. · tydz. ${T.peak_week ?? '—'}${T.peak_ship ? ' (' + wd(T.peak_ship) + ' ' + dt(T.peak_ship) + ')' : ''}</small></div>
              <div><span>Stany maks. dziś (razem)</span><b>${num(T.max_now)} kart.</b><small>stany obowiązujące produktów</small></div>
              <div><span>Brakuje w szczycie</span><b class="${T.missing > 0 ? 'sx-up' : ''}">${T.missing > 0 ? '+' + num(T.missing) : '0'} kart.</b><small>${T.missing > 0 && T.peak_ship ? 'do wyprodukowania przed ' + dt(T.peak_ship) : 'stany maks. wystarczą'}</small></div>
              <div><span>Do produkcji (${W.length} tyg.)</span><b>${num(P.prod_total, 1)} pal.</b><small>średnio ${num(P.prod_total / Math.max(1, W.filter(w => w.working_days > 0).length), 1)} pal. na tydzień pracy</small></div>
              ${freezerTile}
              <div><span>Dni pracy</span><b>${T.working_days} z ${T.days}</b><small>${esc(offNames || 'bez świąt')}</small></div></div>
            <div class="sx-bar">${seg('view', view, Object.entries(VIEWS).map(([k, v]) => [k, v[0]]))}${seg('unit', unit, [['ct', 'Kartony'], ['pal', 'Palety']])}
              <span class="sx-lead m-0">Produkty : ${esc(VIEWS[view][0].toLowerCase())} w ${unit === 'pal' ? 'paletach' : 'kartonach'}</span></div>
            <div class="table-responsive"><table class="sx-t"><thead><tr><th>Tydzień</th>${W.map(w => `<th>${w.iso_week}<small>${wd(w.ship_date)} ${dt(w.ship_date)}</small></th>`).join('')}<th>${view === 'need' ? 'Szczyt' : (view === 'prod' ? 'Razem' : 'Maks.')}</th></tr></thead><tbody>
              <tr><td><b>Wyjazd</b><small>kalendarz : dzień tygodnia</small></td>${W.map(w => `<td class="sx-dcell"><input type="date" class="sx-date" data-ship="${w.week_start}" value="${w.ship_date}" min="${w.week_start}" max="${addDays(w.week_start, 6)}" aria-label="Wyjazd tydzień ${w.iso_week}" title="${w.ship_manual ? 'Ustawiony ręcznie' : 'Auto : ' + wd(w.ship_default) + ' ' + dt(w.ship_default)}">
                  <small>${wd(w.ship_date)}${w.ship_manual ? '' : ' · auto'}</small></td>`).join('')}<td></td></tr>
              <tr><td><b>Powrót</b><small>z dniami blokady</small></td>${W.map(w => `<td title="${esc(retTitle(w))}" class="${w.return_blocked.length ? 'late' : ''}">${wd(w.return_date)} ${dt(w.return_date)}${w.return_blocked.length ? `<small>+${w.return_blocked.length} ${pl(w.return_blocked.length, 'dzień', 'dni', 'dni')} blokady</small>` : ''}</td>`).join('')}<td></td></tr>
              <tr><td><b>Dni pracy</b></td>${W.map(w => `<td class="${w.working_days === 0 ? 'off' : (w.working_days < 5 ? 'short' : '')}" title="${esc(dayTitle(w))}">${w.working_days}</td>`).join('')}<td></td></tr>
              <tr class="pal"><td><b>Palety na wyjazd</b><small>plan logistyki</small></td>${W.map(w => `<td>${num(w.pallets)}</td>`).join('')}<td>${num(W.reduce((a, w) => a + w.pallets, 0))}<small>razem</small></td></tr>
              <tr class="pal"><td><b>Produkcja (palety)</b><small>MTS do zrobienia w tygodniu</small></td>${W.map((w, i) => `<td class="${w.working_days === 0 ? 'off' : (P.prod[i] > 0 ? 'pos' : '')}">${w.working_days === 0 ? '—' : num(P.prod[i], 1)}</td>`).join('')}<td>${num(P.prod_total, 1)}<small>razem</small></td></tr>
              <tr class="pal"><td><b>Magazyn (palety)</b><small>przed wyjazdem, z ${num(P.other, 1)} pal. innych${P.places > 0 ? ' · ' + num(P.places) + ' miejsc' : ''}</small></td>${W.map((w, i) => `<td class="${over(P.stock[i]) ? 'hot' : ''}" title="${P.places > 0 ? num(P.stock[i], 1) + ' z ' + num(P.places) + ' miejsc zamrażarki' : 'Miejsca zamrażarki : Ustawienia'}">${num(P.stock[i], 1)}</td>`).join('')}<td>${num(P.stock_peak, 1)}<small>maks.</small></td></tr>
              <tr class="grp"><td colspan="${W.length + 2}">${esc(VIEWS[view][0])} · ${unit === 'pal' ? 'palety' : 'kartony'}</td></tr>
              ${rows || `<tr><td colspan="${W.length + 2}" class="at-empty">Brak produktów MTS z prognozą w planie.</td></tr>`}
              <tr class="sum"><td>${VIEWS[view][1]}</td>${sums.map((v, i) => { const off = W[i].working_days === 0 && view !== 'stock';
                  const hotSum = view === 'need' && unit === 'ct' && F.need[i] > T.max_now;
                  return `<td class="${off ? 'off' : (hotSum ? 'hot' : '')}">${off ? '—' : fmt(v)}</td>`; }).join('')}<td>${fmt(sumLast)}</td></tr>
            </tbody></table></div>
            <div class="sx-foot"><span class="sx-lead m-0">Czerwone : zapas potrzebny ponad stan maks. dziś${P.places > 0 ? ', magazyn ponad miejsca zamrażarki' : ''}. Tydzień bez produkcji : wyjazd z zapasu tygodnia przed. Zmiana dnia wyjazdu zapisuje się w planie logistyki.</span>
              <span class="sx-actions"><a class="sx-btn" href="${root}/logistics/plan#swieta">Plan logistyki</a><a class="sx-btn" href="${root}/workshop/gantt">Do planu produkcji</a>
                <button type="button" class="sx-btn pri" data-sx-new-hot>Utwórz okres wyjątkowy</button></span></div></div>`;
    }

    // --- Makieta 2 : okres wyjątkowy ----------------------------------------------------------------------
    const STATUS = {draft: ['Projekt', 'warn'], planned: ['Zatwierdzony', 'ok'], active: ['Aktywny', 'xm'], ended: ['Zakończony', '']};
    const statusBadge = (p) => {
        const [label, cls] = STATUS[p.status];
        const extra = p.status === 'planned' ? ` · od ${dt(p.date_from)}` : (p.status === 'active' ? ` · do ${dt(p.date_to)}` : '');
        return `<span class="sx-badge ${cls}">${label}${extra}</span>`;
    };
    function renderPeriod() {
        const box = app.querySelector('[data-sx-period]');
        const P = D.period;
        if (!P) { box.innerHTML = ''; return; }
        const T = P.totals;
        const editable = P.status === 'draft';
        const F = D.forecast.params;
        // Oś : od 2 tygodni przed okresem do 2 tygodni po (normalne → wyjątkowe → normalne).
        const a = addDays(P.date_from, -14), b = addDays(P.date_to, 14);
        const span = (new Date(b) - new Date(a)) / 864e5;
        const pos = (d) => Math.max(0, Math.min(100, (new Date(d) - new Date(a)) / 864e5 / span * 100));
        const x0 = pos(P.date_from), x1 = pos(addDays(P.date_to, 1));
        const freezer = P.freezer.places > 0
            ? `<div><span>Zamrażarka</span><b class="${P.freezer.ok ? '' : 'sx-up'}">${num(P.freezer.after, 0)} / ${num(P.freezer.places)} miejsc</b><small>dziś ${num(P.freezer.now, 0)} palet</small></div>`
            : `<div><span>Zamrażarka</span><b>— miejsc</b><small><a href="${root}/other-settings#stock-params">podaj miejsca w Ustawieniach</a> · dziś ${num(P.freezer.now, 0)} palet</small></div>`;
        const notes = [];
        if (P.freezer.places > 0) notes.push(P.freezer.ok
            ? `<div class="sx-note">✓ Zapas wyjątkowy mieści się w zamrażarce (${num(P.freezer.after, 0)} z ${num(P.freezer.places)} miejsc).</div>`
            : `<div class="sx-note bad">✕ Zamrażarka za mała : ${num(P.freezer.after, 0)} palet na ${num(P.freezer.places)} miejsc — obniż stany maks.</div>`);
        P.warnings.forEach(w => notes.push(`<div class="sx-note warn">⚠ ${esc(w.text)}</div>`));
        const rows = P.items.map(i => `<tr data-item="${i.item_id}"><td><b>${esc(i.name)}</b>${i.peak_week ? `<small>szczyt tydz. ${i.peak_week}</small>` : ''}</td>
            <td>${num(i.normal_min)} / ${num(i.normal_max)}</td><td>${num(i.forecast_avg, 1)} / ${num(i.forecast_peak)}</td>
            <td>${editable ? `<input class="sx-in" inputmode="decimal" data-min value="${i.min ?? ''}" aria-label="Min. ${esc(i.name)}"> / <input class="sx-in" inputmode="decimal" data-max value="${i.max ?? ''}" aria-label="Maks. ${esc(i.name)}">`
                : `<b>${num(i.min)} / ${num(i.max)}</b>`}</td>
            <td class="${i.delta > 0 ? 'sx-up' : ''}">${sign(i.delta)}</td></tr>`).join('');
        const actions = editable
            ? `<button type="button" class="sx-btn" data-sx-delete>Usuń</button><button type="button" class="sx-btn" data-sx-recompute>Przelicz z prognozy</button>
               <button type="button" class="sx-btn" data-sx-save>Zapisz projekt</button><button type="button" class="sx-btn pri" data-sx-approve>Zatwierdź stany wyjątkowe</button>`
            : (P.status === 'ended' ? `<button type="button" class="sx-btn" data-sx-delete>Usuń</button>`
                : `<button type="button" class="sx-btn" data-sx-reopen>Cofnij zatwierdzenie</button>`);
        box.innerHTML = `<div class="sx-card" id="sx-period"><h5>Okres wyjątkowy : ${esc(P.name)} ${statusBadge(P)}</h5>
            <p class="sx-lead">Stany min. / maks. z prognozy zatwierdzonego planu logistyki (min. = średnio na wyjazd, maks. = szczyt zapasu potrzebnego) ; po zatwierdzeniu obowiązują od
                ${full(P.date_from)}, od ${full(P.back_to_normal)} wracają stany normalne — samo.${P.approved_at ? ` Zatwierdził ${esc(P.approved_by || '—')} ${dt(P.approved_at.slice(0, 10))}.` : ''}</p>
            <form class="sx-form" data-sx-head><label>Nazwa<input class="form-control sx-field" name="name" maxlength="80" value="${esc(P.name)}" ${editable ? '' : 'disabled'}></label>
              <label>Od<input class="form-control sx-field" type="date" name="date_from" value="${P.date_from}" ${editable ? '' : 'disabled'}></label>
              <label>Do<input class="form-control sx-field" type="date" name="date_to" value="${P.date_to}" ${editable ? '' : 'disabled'}></label>
              <label>Stany wyjątkowe<span class="sx-static">z prognozy planu logistyki (tyg. ${esc(P.weeks.join(', ') || '—')})</span></label></form>
            <div class="sx-time"><div class="bar" style="left:0;width:${x0}%;background:#d9d4cc"></div><div class="lab" style="left:0">Normalne</div>
              <div class="bar" style="left:${x0}%;width:${x1 - x0}%;background:#c9a36b"></div><div class="lab" style="left:${x0}%">${esc(P.name)} · ${dt(P.date_from)} – ${dt(P.date_to)}</div>
              <div class="bar" style="left:${x1}%;width:${100 - x1}%;background:#d9d4cc"></div><div class="lab" style="left:${Math.min(x1, 82)}%">Normalne od ${dt(P.back_to_normal)}</div>
              <span class="tick" style="left:0">${dt(a)}</span><span class="tick" style="left:${x0}%">${dt(P.date_from)}</span><span class="tick" style="left:${Math.min(x1, 90)}%">${dt(P.date_to)}</span></div>
            <div class="sx-grid2"><div class="table-responsive"><table class="sx-t"><thead><tr><th>Produkt</th><th>Normalne<small>min / maks.</small></th><th>Prognoza<small>śr. / szczyt</small></th><th>Wyjątkowe<small>min / maks. (kart.)</small></th><th>Zmiana<small>maks. (kart.)</small></th></tr></thead>
              <tbody>${rows || '<tr><td colspan="5" class="at-empty">Brak produktów.</td></tr>'}
              <tr class="sum"><td>Razem</td><td>${num(T.normal_min)} / ${num(T.normal_max)}</td><td></td><td>${num(T.min)} / ${num(T.max)}</td><td class="${T.extra_cartons > 0 ? 'sx-up' : ''}">${sign(T.extra_cartons)}</td></tr></tbody></table></div>
              <div><div class="sx-tiles two"><div><span>${T.extra_cartons < 0 ? 'Zapas poniżej normalnych' : 'Zapas ponad normalne'} (maks.)</span><b class="${T.extra_cartons > 0 ? 'sx-up' : ''}">${sign(T.extra_cartons)} kart.</b><small>${sign(T.extra_pallets, 1)} palety</small></div>
                <div><span>Wartość zmiany zapasu</span><b>${sign(T.extra_value_eur)} €</b><small>po koszcie surowców</small></div>${freezer}
                <div><span>Produkcja ponad normę</span><b>${T.per_week > 0 ? '+' + num(T.per_week) + ' kart. / tydz.' : '—'}</b><small>${T.per_week > 0 && T.prod_weeks ? T.prod_weeks + ' ' + pl(T.prod_weeks, 'tydzień', 'tygodnie', 'tygodni') + ' do szczytu (tydz. ' + T.peak_week + ')' : (T.per_week > 0 ? 'szczyt na początku planu' : 'stany normalne wystarczą')}</small></div></div>
                ${notes.join('')}</div></div>
            <div class="sx-foot"><span class="sx-lead m-0">Zatwierdzenie : Stan magazynu, plan produkcji i tablety liczą « do produkcji » od stanów wyjątkowych w dniach okresu ; karty produktów zachowują stany normalne.</span>
              <span class="sx-actions">${actions}</span></div></div>`;
    }

    // --- Makieta 3 : okresy -----------------------------------------------------------------------------
    const icon = (p) => { const m = Number(p.date_from.slice(5, 7)); return m >= 11 || m === 1 ? '🎄' : (m >= 3 && m <= 4 ? '🐣' : (m >= 6 && m <= 8 ? '☀️' : '📅')); };
    function renderPeriods() {
        const box = app.querySelector('[data-sx-periods]');
        const list = D.periods.map(p => `<div class="sx-per ${p.status === 'ended' ? 'dim' : ''}"><span class="ic">${icon(p)}</span>
            <div><b>${esc(p.name)}</b><small>${full(p.date_from)} – ${full(p.date_to)} · ${p.products} ${pl(p.products, 'produkt', 'produkty', 'produktów')}</small></div>
            <div>${statusBadge(p)}<small>${p.status === 'draft' ? 'stany z prognozy, do zatwierdzenia' : (p.status === 'ended' ? 'powrót ' + dt(p.back_to_normal) : (p.approved_by ? esc(p.approved_by) + ' · ' + dt(p.approved_at.slice(0, 10)) : ''))}</small></div>
            <div><b>${sign(p.extra_cartons)} kart.</b><small>${p.extra_cartons < 0 ? 'poniżej normalnych' : 'ponad normalne'}</small></div>
            <div><b>${p.status === 'planned' ? 'za ' + p.days_to_start + ' ' + pl(p.days_to_start, 'dzień', 'dni', 'dni') : (p.status === 'active' ? 'trwa' : '—')}</b><small>${p.status === 'active' ? 'do ' + dt(p.date_to) : (p.status === 'planned' ? 'start ' + dt(p.date_from) : '')}</small></div>
            <button type="button" class="sx-btn" data-sx-open="${p.id}">Otwórz</button></div>`).join('');
        box.innerHTML = `<div class="sx-card" id="sx-periods"><h5>Okresy stanów wyjątkowych</h5>
            <p class="sx-lead">Poza okresami obowiązują stany normalne (karta produktu, z historii sprzedaży). W okresie : stany wyjątkowe zatwierdzone ; dzień po końcu — powrót sam.</p>
            <div class="sx-list">${list || '<p class="at-empty mb-0">Brak okresów.</p>'}</div>
            <form class="sx-form sx-new" data-sx-new><label>Nowy okres<input class="form-control sx-field" name="name" maxlength="80" placeholder="np. Boże Narodzenie ${new Date().getFullYear()}" required></label>
              <label>Od<input class="form-control sx-field" type="date" name="date_from" required></label><label>Do<input class="form-control sx-field" type="date" name="date_to" required></label>
              <label>&nbsp;<button type="submit" class="sx-btn pri">+ Utwórz z prognozy</button></label></form></div>`;
    }

    function render() {
        if (D.error) { app.querySelector('[data-sx-forecast]').innerHTML = `<div class="alert alert-danger">${esc(D.error)}</div>`; return; }
        renderForecast();
        renderPeriod();
        renderPeriods();
    }

    // --- Zdarzenia --------------------------------------------------------------------------------------
    app.addEventListener('change', async (e) => {
        const ship = e.target.closest('[data-ship]');
        if (ship) {
            if (ship.value && (ship.value < ship.min || ship.value > ship.max)) { ship.value = ship.defaultValue; return; }
            try {
                await call('PUT', `/ajax/logistics/plan/weeks/${ship.dataset.ship}`, {ship_date: ship.value || null});
                await reload(D.period ? D.period.id : null);
            } catch (err) { ship.value = ship.defaultValue; fail(err); }
            return;
        }
        if (e.target.matches('[data-min], [data-max]')) dirty = true;
    });

    const items = () => Array.from(app.querySelectorAll('#sx-period tr[data-item]')).map(tr => ({
        item_id: Number(tr.dataset.item), min: tr.querySelector('[data-min]').value, max: tr.querySelector('[data-max]').value}));
    const head = () => { const f = app.querySelector('[data-sx-head]'); return {name: f.name.value, date_from: f.date_from.value, date_to: f.date_to.value}; };
    const savePeriod = async (extra = {}) => {
        const P = D.period;
        const h = head();
        const datesChanged = h.date_from !== P.date_from || h.date_to !== P.date_to;
        const res = await call('PUT', `/ajax/stock/periods/${P.id}`, {...h, ...(datesChanged || extra.recompute ? {} : {items: items()}), ...extra});
        D.period = res.data;
        dirty = false;
        return res;
    };

    app.addEventListener('click', async (e) => {
        const btn = e.target.closest('button');
        if (!btn) return;
        if (btn.dataset.sxView || btn.dataset.sxUnit) {
            if (btn.dataset.sxView) view = btn.dataset.sxView; else unit = btn.dataset.sxUnit;
            try { localStorage.setItem('sx.view', view); localStorage.setItem('sx.unit', unit); } catch (err) { /* bez pamięci */ }
            renderForecast();
            return;
        }
        try {
            if (btn.matches('[data-sx-open]')) {
                await reload(Number(btn.dataset.sxOpen));
                document.getElementById('sx-period')?.scrollIntoView({behavior: 'smooth'});
            } else if (btn.matches('[data-sx-new-hot]')) {
                // Okres z tygodni, w których zapas potrzebny przekracza stany maks. (inaczej : szczyt planu).
                const W = D.forecast.weeks;
                const hot = D.forecast.need.map((v, i) => v > D.forecast.totals.max_now ? i : -1).filter(i => i >= 0);
                const f = app.querySelector('[data-sx-new]');
                const i0 = hot.length ? hot[0] : W.findIndex(w => w.iso_week === D.forecast.totals.peak_week);
                const i1 = hot.length ? hot[hot.length - 1] : i0;
                if (i0 >= 0) { f.date_from.value = W[i0].week_start; f.date_to.value = addDays(W[i1].week_start, 6); }
                f.name.value ||= 'Okres szczytu tyg. ' + (i0 >= 0 ? W[i0].iso_week + (i1 !== i0 ? '–' + W[i1].iso_week : '') : '');
                f.scrollIntoView({behavior: 'smooth'});
                f.name.focus();
            } else if (btn.matches('[data-sx-save]')) {
                await savePeriod();
                render();
                ok('Zapisano projekt');
            } else if (btn.matches('[data-sx-recompute]')) {
                await savePeriod({recompute: 1});
                render();
                ok('Stany przeliczone z prognozy');
            } else if (btn.matches('[data-sx-approve]')) {
                const P = D.period;
                const go = window.Swal ? (await Swal.fire({icon: 'question', title: 'Zatwierdzić stany wyjątkowe ?',
                    html: `<p class="mb-1">${esc(P.name)} : ${full(P.date_from)} – ${full(P.date_to)}</p><p class="small text-muted mb-0">Od ${full(P.date_from)} Stan magazynu, plan produkcji i tablety liczą od tych stanów ; od ${full(P.back_to_normal)} wracają normalne.</p>`,
                    showCancelButton: true, confirmButtonText: 'Zatwierdź', cancelButtonText: 'Anuluj', confirmButtonColor: '#8e1f2f'})).isConfirmed : confirm('Zatwierdzić ?');
                if (!go) return;
                await savePeriod();
                await call('POST', `/ajax/stock/periods/${P.id}/approve`);
                await reload(P.id);
                ok('Zatwierdzono stany wyjątkowe');
            } else if (btn.matches('[data-sx-reopen]')) {
                await call('POST', `/ajax/stock/periods/${D.period.id}/reopen`);
                await reload(D.period.id);
            } else if (btn.matches('[data-sx-delete]')) {
                const go = window.Swal ? (await Swal.fire({icon: 'question', title: 'Usunąć okres ?', showCancelButton: true, confirmButtonText: 'Usuń', cancelButtonText: 'Anuluj', confirmButtonColor: '#8e1f2f'})).isConfirmed : confirm('Usunąć ?');
                if (!go) return;
                await call('DELETE', `/ajax/stock/periods/${D.period.id}`);
                history.replaceState(null, '', location.pathname);
                await reload(null);
            }
        } catch (err) { fail(err); }
    });

    app.addEventListener('submit', async (e) => {
        if (!e.target.matches('[data-sx-new]')) return;
        e.preventDefault();
        const f = e.target;
        try {
            const res = await call('POST', '/ajax/stock/periods', {name: f.name.value, date_from: f.date_from.value, date_to: f.date_to.value});
            history.replaceState(null, '', '?period=' + res.data.id);
            await reload(res.data.id);
            document.getElementById('sx-period')?.scrollIntoView({behavior: 'smooth'});
        } catch (err) { fail(err); }
    });

    window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
    render();
})();
