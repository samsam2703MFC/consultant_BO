/*
 * Produkcja › Prognoza 13 tygodni [220] (makiety 1–3) : Prognoza (kartony na produkt i tydzień, gamy sezonowe na osi, palety,
 * godziny wobec zdolności, zatwierdzenie w poniedziałek), Sezony z panelu (gamy TF Buddy → nasze produkty, połączenie
 * produktu gamy, odczyt panelu GET), Zakupy 13 tygodni (surowce z receptur, stan, w drodze, zamówić do). Serwer liczy
 * wszystko ; strona tylko pokazuje i wysyła decyzje (zatwierdź, cofnij, połącz, odśwież).
 */
(function () {
    'use strict';
    const app = document.getElementById('forecastApp');
    if (!app) return;
    const root = app.dataset.root;
    let D = JSON.parse(document.getElementById('forecastBoot').textContent);
    const OPTIONS = D.product_options || [];
    const pref = (k, def, allowed) => { try { const v = localStorage.getItem('fc.' + k); return allowed.includes(v) ? v : def; } catch (e) { return def; } };
    const save = (k, v) => { try { localStorage.setItem('fc.' + k, v); } catch (e) { /* bez pamięci */ } };
    let tab = (location.hash.match(/tab=(forecast|seasons|buy)/) || [])[1] || pref('tab', 'forecast', ['forecast', 'seasons', 'buy']);
    let season = null;
    let showAll = false;
    let showAllBuy = false;
    let buyView = pref('buy', 'order', ['order', 'weeks']);
    let editing = null;
    let filter = '';
    const LIMIT = 40;
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const num = (v, d = 0) => v === null || v === undefined ? '—' : Number(v).toLocaleString('pl-PL', {minimumFractionDigits: d, maximumFractionDigits: d});
    const dm = (d) => d ? `${d.slice(8, 10)}.${d.slice(5, 7)}` : '—';
    const full = (d) => d ? `${dm(d)}.${d.slice(0, 4)}` : '—';
    const DAYS = ['nd.', 'pn.', 'wt.', 'śr.', 'czw.', 'pt.', 'sob.'];
    const when = (ts) => ts ? `${DAYS[new Date(ts.replace(' ', 'T')).getDay()]} ${dm(ts.slice(0, 10))}, ${ts.slice(11, 16)}` : '—';
    const pl = (n, one, few, many) => n === 1 ? one : ([2, 3, 4].includes(n % 10) && ![12, 13, 14].includes(n % 100) ? few : many);
    const qty = (v, f) => v === null || v === undefined ? '—' : num(v / f, v / f >= 100 ? 0 : 1);

    async function call(method, url, body) {
        const res = await fetch(root + url, {method, credentials: 'same-origin',
            headers: {'Accept': 'application/json', 'Content-Type': 'application/json'}, body: body ? JSON.stringify(body) : undefined});
        const data = await res.json().catch(() => ({message: 'Błąd serwera (' + res.status + ').'}));
        if (!res.ok || data.ok === false) throw new Error(data.message || data.error || 'Błąd serwera.');
        return data;
    }
    const fail = (err) => window.Swal ? Swal.fire({icon: 'error', title: 'Nie zapisano', text: err.message}) : alert(err.message);
    const ok = (msg) => { if (window.Swal) Swal.fire({icon: 'success', title: msg, timer: 1600, showConfirmButton: false}); };
    async function act(btn, method, url, body) {
        editing = null;
        if (btn) btn.disabled = true;
        app.classList.add('fc-busy');
        try {
            const res = await call(method, url, body);
            D = Object.assign(res.data, {product_options: OPTIONS});
            render();
            if (res.message) ok(res.message);
        } catch (e) { fail(e); } finally { app.classList.remove('fc-busy'); if (btn) btn.disabled = false; }
    }

    const weekHead = () => D.weeks.map(w => `<th title="${w.off.length ? esc(w.off.map(o => dm(o.date) + ' ' + o.name).join(' ; ')) : 'pn–pt'}"><b>${w.iso_week}</b>${dm(w.week_start)}${w.off.length ? ' <span class="fc-off">' + (5 - w.off.length) + ' dni</span>' : ''}</th>`).join('');
    const seasonById = () => Object.fromEntries(D.seasons.map(s => [s.id, s]));
    const trendTxt = () => D.trend.value === null ? 'brak danych rok temu — bez trendu (× 1)' : `× ${num(D.trend.value, 2)}`;

    // --- Makieta 1 : prognoza 13 tygodni -----------------------------------------------------------------------
    function band() {
        const n = D.weeks.length;
        return D.seasons.filter(s => s.week_index.length).map(s => {
            const a = s.week_index[0], b = s.week_index[s.week_index.length - 1];
            return `<div class="fc-band" style="grid-template-columns: 250px repeat(${n}, minmax(0, 1fr)) 70px"><span class="at-sub">${esc(s.name)} <small>(panel : ${dm(s.from)} – ${dm(s.to)})</small></span>
                <span class="bar" style="grid-column: ${a + 2} / span ${b - a + 1}; background:${s.color}" title="${esc(s.name)} : ${s.ours} ${pl(s.ours, 'nasz produkt', 'nasze produkty', 'naszych produktów')} · ${num(s.cartons)} kart.">${esc(s.name)}</span></div>`;
        }).join('');
    }
    function srcBadge(p) {
        if (p.kind === 'season') return '<span class="fc-src season">sezon panel</span>';
        const counts = {};
        p.sources.forEach(s => { counts[s] = (counts[s] || 0) + 1; });
        const main = Object.entries(counts).filter(([s]) => s !== 'none').sort((a, b) => b[1] - a[1])[0];
        if (!main) return '';
        if (main[0] === 'orders') return '<span class="fc-src order">zamówienia</span>';
        if (main[0] === 'n1') return '';
        return `<span class="fc-src" title="Tygodnie bez danych rok temu : średnia ostatnich ${D.trend.weeks} tygodni">średnia ${D.trend.weeks} tyg.</span>`;
    }
    function productRow(p, S) {
        const dot = p.kind === 'season' && p.seasons.length ? `<span class="fc-dot" style="background:${S[p.seasons[0]]?.color || '#999'}"></span>` : '';
        const cc = p.cost_per_carton;
        const title = `Wydatki na karton ${num(cc.total, 2)} zł (surowce ${num(cc.raw, 2)}, płace ${num(cc.labor, 2)}, media ${num(cc.media, 2)}, wspólne działy ${num(cc.shared, 2)}) · ${D.weeks.length} tyg. : ${num(p.total * cc.total)} zł\n`
            + p.sources.map((s, i) => `${D.weeks[i].iso_week} : ${({orders: 'zamówienia', n1: 'N-1 × trend', avg: 'średnia', season: 'gama N-1 × trend', none: '—'})[s]}`).join('\n');
        const note = p.all_year_in ? ` <span class="at-sub" title="Sprzedawany w ostatnich tygodniach poza gamą — liczony jak całoroczny">(w gamie ${esc(p.all_year_in.join(', '))}, sprzedaż cały rok)</span>` : '';
        return `<tr title="${esc(title)}"><td>${dot}${esc(p.name)}${srcBadge(p)}${note}</td>
            ${p.values.map((v, i) => `<td class="${v ? '' : 'z'}${p.kind === 'season' && v ? ' s' : ''}${p.sources[i] === 'orders' && v ? ' o' : ''}">${v ? num(v) : '—'}</td>`).join('')}<td class="s">${num(p.total)}</td></tr>`;
    }
    function renderForecast() {
        const T = D.totals, W = D.weeks, S = seasonById();
        const q = filter.trim().toLowerCase();
        const match = (p) => !q || p.name.toLowerCase().includes(q);
        const regular = D.products.filter(p => p.kind === 'regular' && match(p));
        const seasonal = D.products.filter(p => p.kind === 'season' && match(p) && (p.total > 0 || showAll));
        const shown = showAll || q ? regular : regular.slice(0, LIMIT);
        const cap = D.capacity;
        const capTxt = cap.source === 'param' ? `${num(cap.hours)} h (parametr)` : `${cap.people} ${pl(cap.people, 'pracownik', 'pracowników', 'pracowników')} × ${num(cap.fte_hours)} h = ${num(cap.hours)} h`;
        const seasonsIn = D.seasons.filter(s => s.week_index.length);
        const P = D.purchases.totals;
        const hoursNote = T.no_hours ? `godziny : ${T.products - T.no_hours} z ${T.products} produktów z czasem partii / marszruty, pozostałe szacowane średnią ${num(T.hours_avg_per_carton, 2)} h / karton (${num(T.estimated_hours)} h)` : 'godziny z czasu partii / marszruty wszystkich produktów';
        const hot = W.filter(w => w.load_pct !== null && w.load_pct >= 95);
        const appr = D.approval;
        const sum = (f) => W.map((w) => f(w));
        const C = D.cost;
        const mln = (v) => v >= 1e6 ? num(v / 1e6, 2) + ' mln' : num(v / 1000, 0) + ' tys.';
        const costTitle = `Wydatki : kartony × (surowce i opakowania + płace i media działu produkcji + wspólne działy produkcji${C.shared_sections.length ? ' : ' + C.shared_sections.join(', ') : ''}), z ${C.basis || 'brak miesięcy kosztowych'} : surowce ${num(C.raw)} zł, płace ${num(C.labor)} zł, media ${num(C.media)} zł, wspólne działy ${num(C.shared)} zł. Poza wydatkami : inwestycje ${num(C.excluded.investment)} zł (amortyzacja ${num(C.excluded.depreciation)} zł, kredyt i leasing ${num(C.excluded.financing)} zł), pozostałe koszty stałe (Finanse) ${num(C.excluded.fixed)} zł, ${C.admin_sections.length ? C.admin_sections.join(', ') : 'Administracja'} ${num(C.excluded.admin)} zł ; logistyka osobno ${num(C.logistics)} zł.`;
        const wTitle = (w) => `${num(w.cost)} zł : surowce ${num(w.cost_raw)}, płace ${num(w.cost_labor)}, media ${num(w.cost_media)}, wspólne działy ${num(w.cost_shared)}`;
        return `<div class="kw"><div class="kw-card">
            <h5>Prognoza 13 tygodni <span class="kw-badge">kroczące : tydz. ${W[0].iso_week} / ${W[0].iso_year} → tydz. ${W[W.length - 1].iso_week} / ${W[W.length - 1].iso_year}</span>
                ${appr ? `<span class="kw-badge ok">zatwierdzona : ${when(appr.approved_at)}${appr.by ? ' — ' + esc(appr.by) : ''}</span>${appr.changed ? ' <span class="kw-badge warn">liczby zmieniły się od zatwierdzenia</span>' : ''}` : '<span class="kw-badge warn">do zatwierdzenia w tym tygodniu</span>'}</h5>
            <p class="kw-lead">Co tydzień te same ${W.length} tygodni do przodu. Kartony na tydzień : zamówienia już złożone (tydzień bieżący : tylko zamówienia), inaczej sprzedaż ostatnich ${D.trend.weeks} tygodni × kształt tego samego tygodnia rok temu (N-1 × trend), a produkty sezonowe z gam panelu TF Buddy (daty gamy w tym roku + nasze kartony w tej samej gamie rok temu × trend sieci). Z prognozy : palety (plan logistyki), godziny produkcji, pieniądze na produkcję (zł) i zakupy surowców.</p>
            <div class="kw-tiles">
                <div><span>Kartony ${W.length} tygodni</span><b>${num(T.cartons)}</b><small>w tym sezonowe ${num(T.season_cartons)}${T.cartons ? ` (${Math.round(T.season_cartons / T.cartons * 100)} %)` : ''}</small></div>
                <div><span>Palety</span><b>${num(T.pallets)}</b><small>średnio ${num(T.pallets / W.length, 1)} na tydzień${T.no_cpp ? ` · ${T.no_cpp} ${pl(T.no_cpp, 'produkt', 'produkty', 'produktów')} bez kart./pal.` : ''}</small></div>
                <div class="${T.over.length ? 'todo' : 'ok'}"><span>Szczyt produkcji</span><b>${T.peak_week !== null ? `tydz. ${T.peak_week} — ${T.peak_pct ?? '—'} %` : '—'}</b><small>${num(T.peak_hours)} h z ${num(T.peak_capacity)} h zdolności · ${T.over.length} tyg. ponad 100 %</small></div>
                <div><span>Sezony w oknie (panel)</span><b>${seasonsIn.length}</b><small>${seasonsIn.length ? esc(seasonsIn.map(s => s.name).join(', ')) : (D.panel_status.read_at ? 'brak gam sezonowych w tych tygodniach' : 'panel jeszcze nie odczytany')}</small></div>
                <div class="fc-money"><span>Pieniądze na produkcję ${W.length} tyg.</span><b>${num(C.total)} zł</b><small title="${esc(costTitle)}">wydatki : surowce ${mln(C.raw)} · płace ${mln(C.labor)} · media ${mln(C.media)} · ${C.shared_sections.length ? esc(C.shared_sections.join(', ').toLowerCase()) : 'wspólne działy'} ${mln(C.shared)}</small></div>
                <div><span>Zakupy surowców ${W.length} tyg.</span><b>${num(P.value)} zł</b><small>${P.season_value ? `w tym na sezon ${num(P.season_value)} zł · ` : ''}<a href="#tab=buy" data-fc-go="buy">Zakupy ${W.length} tyg.</a></small></div>
            </div>
            <div class="fc-ok"><b>Zadanie co poniedziałek</b> « Zatwierdź prognozę 13 tygodni » — Kierownik produkcji, 30 min, zalicza się samo po zatwierdzeniu. <span class="kw-badge">trend sieci ${trendTxt()} : ${num(D.trend.now)} ${D.trend.unit} ÷ ${num(D.trend.last)} rok temu (${D.trend.used} z ${D.trend.weeks} tyg.${D.trend.skipped.length ? ' ; tyg. ' + D.trend.skipped.join(', ') + ' rok temu bez danych' : ''}) — ${D.trend.source === 'panel' ? 'sprzedaż naszych produktów w sklepach (panel)' : 'wysyłki ERP'}</span></div>
            ${band()}
            <div class="fc-filter"><input type="search" class="form-control" placeholder="Szukaj produktu…" value="${esc(filter)}" data-fc-filter aria-label="Szukaj produktu">
                <span class="at-sub">Całoroczne : sprzedaż ostatnich ${D.trend.weeks} tyg. × kształt N-1 (trend ${trendTxt()}) ; <span class="fc-o">zielone</span> = zamówienia już złożone ; <b>pogrubione</b> = gama sezonowa. Najedź na wiersz : źródło każdego tygodnia.</span></div>
            <div class="table-responsive"><table class="fc-t"><thead><tr><th>Produkt <span class="at-sub">(kartony)</span></th>${weekHead()}<th>Razem</th></tr></thead><tbody>
                <tr class="grp"><td colspan="${W.length + 2}">Całoroczne · ${regular.length} ${pl(regular.length, 'produkt', 'produkty', 'produktów')}</td></tr>
                ${shown.map(p => productRow(p, S)).join('') || `<tr><td colspan="${W.length + 2}" class="at-sub">Brak produktów.</td></tr>`}
                ${!showAll && !q && regular.length > LIMIT ? `<tr><td colspan="${W.length + 2}"><button type="button" class="kw-btn kw-sm" data-fc-all>Pokaż wszystkie (${regular.length})</button> <span class="at-sub">pozostałe ${regular.length - LIMIT} są w sumach</span></td></tr>` : ''}
                <tr class="grp"><td colspan="${W.length + 2}">Sezonowe — gamy panelu TF Buddy · ${seasonal.length}</td></tr>
                ${seasonal.map(p => productRow(p, S)).join('') || `<tr><td colspan="${W.length + 2}" class="at-sub">${D.panel_status.read_at ? 'Brak naszych produktów w gamach sezonowych tych tygodni.' : 'Panel TF Buddy jeszcze nie odczytany — zakładka « Sezony z panelu ».'}</td></tr>`}
                <tr class="tot"><td>Razem kartony</td>${sum(w => `<td>${num(w.cartons)}</td>`).join('')}<td>${num(T.cartons)}</td></tr>
                <tr><td>Palety</td>${sum(w => `<td>${num(w.pallets, 1)}</td>`).join('')}<td>${num(T.pallets, 0)}</td></tr>
                <tr class="fc-cost"><td>Pieniądze na produkcję <span class="at-sub">(zł)</span></td>${sum(w => `<td title="${esc(wTitle(w))}">${num(w.cost / 1000, 0)} tys.</td>`).join('')}<td>${mln(C.total)}</td></tr>
                <tr><td class="fc-sub">w tym surowce i opakowania</td>${sum(w => `<td>${num(w.cost_raw / 1000, 0)} tys.</td>`).join('')}<td>${mln(C.raw)}</td></tr>
                <tr><td>Godziny produkcji <span class="at-sub">/ zdolność</span></td>${sum(w => `<td class="${w.load_pct > 100 ? 'over' : (w.load_pct >= 90 ? 'hi' : '')}" title="${num(w.hours)} h z ${num(w.capacity)} h${w.estimated_hours ? ' (szacunek ' + num(w.estimated_hours) + ' h)' : ''}">${w.load_pct !== null ? w.load_pct + ' %' : '—'}</td>`).join('')}<td>${num(T.hours)} h</td></tr>
            </tbody></table></div>
            <div class="kw-foot"><span class="at-sub">${hot.length ? `Tydz. ${hot.map(w => `${w.iso_week} (${w.load_pct} %)`).join(', ')} : blisko albo ponad zdolność — produkcja wcześniej na zapas (Prognoza zapasu) albo dodatkowa zmiana.` : 'Wszystkie tygodnie w zdolności produkcji.'} Zdolność : ${capTxt} na tydzień, mniej w tygodniach ze świętami ; ${hoursNote}. Pieniądze na produkcję = wydatki : kartony × (surowce + płace i media działu produkcji + wspólne działy produkcji), z ${esc(C.basis || '—')} ; bez inwestycji (amortyzacja, kredyt i leasing), pozostałych kosztów stałych (Finanse) i Administracji ; logistyka osobno : ${num(C.logistics)} zł${C.no_raw ? ` ; ${C.no_raw} ${pl(C.no_raw, 'produkt', 'produkty', 'produktów')} bez kosztu surowców` : ''}${C.no_section ? ` ; ${C.no_section} bez działu (surowce i wspólne działy)` : ''}.</span>
                <span class="d-flex gap-2 flex-wrap"><button type="button" class="kw-btn" data-fc-go="seasons">Sezony z panelu</button><button type="button" class="kw-btn" data-fc-go="buy">Zakupy 13 tygodni</button>
                ${appr ? '<button type="button" class="kw-btn" data-fc-reopen>Cofnij zatwierdzenie</button><button type="button" class="kw-btn pri" data-fc-approve>Zatwierdź ponownie</button>' : '<button type="button" class="kw-btn pri" data-fc-approve>Zatwierdź prognozę</button>'}</span></div>
            ${D.runs.length ? `<div class="fc-runs"><h6>Ostatnie zatwierdzenia</h6><table class="kw-t"><thead><tr><th>Tydzień</th><th>Zatwierdzona</th><th class="r">Kartony</th><th class="r">w tym sezon</th><th class="r">Godziny</th><th class="r">Pieniądze na produkcję</th><th class="r">Zakupy</th></tr></thead><tbody>
                ${D.runs.map(r => `<tr><td>tydz. ${r.iso_week} · ${full(r.week_start)}</td><td>${when(r.approved_at)}${r.by ? ' — ' + esc(r.by) : ''}</td><td class="r">${num(r.cartons)}</td><td class="r">${num(r.season_cartons)}</td><td class="r">${r.hours !== null ? num(r.hours) + ' h' : '—'}</td><td class="r">${r.production_cost !== null ? num(r.production_cost) + ' zł' : '—'}</td><td class="r">${r.purchase_value !== null ? num(r.purchase_value) + ' zł' : '—'}</td></tr>`).join('')}
            </tbody></table></div>` : ''}
        </div></div>`;
    }

    // --- Makieta 2 : sezony z panelu ------------------------------------------------------------------------------
    function spark(values) {
        const max = Math.max(1, ...values);
        return `<span class="fc-spark">${values.map(v => `<i style="height:${Math.max(2, v / max * 26)}px" title="${num(v)}"></i>`).join('')}</span>`;
    }
    function linkCell(p) {
        const hints = (p.hints || []).filter(h => h.id !== p.product_id);
        const pick = (label) => `<select class="form-select fc-pick" data-fc-pick="${p.panel_id}" aria-label="Nasz produkt dla ${esc(p.panel_name)}"><option value="">${label}</option>
            ${hints.map(h => `<option value="${h.id}">${esc(h.name)} (${Math.round(h.score * 100)} %)${h.active ? '' : ' — nieaktywny'}</option>`).join('')}<option value="other">Inny produkt…</option></select>`;
        if (p.status === 'ignored') return `<span class="at-sub">nie od nas — pominięty</span> <button type="button" class="fc-lnk" data-fc-auto="${p.panel_id}">przywróć</button>`;
        if (!p.product_id) return `<div class="fc-linkrow">${pick('Połącz z naszym produktem…')}<button type="button" class="fc-lnk" data-fc-ignore="${p.panel_id}">nie od nas</button></div>`;
        const badge = p.status === 'auto' ? `<span class="kw-badge new" title="Podpowiedź z nazwy — wchodzi do prognozy ; potwierdź albo zmień">auto ${Math.round(p.score * 100)} %</span>` : '<span class="kw-badge ok">połączony</span>';
        return `<b>${esc(p.product_name)}</b> <span class="at-sub">SKU ${p.product_id}</span> ${badge}${p.product_active === false ? ' <span class="kw-badge warn">nieaktywny w ERP</span>' : ''}
            <div class="fc-linkrow">${editing === p.panel_id ? pick('Wybierz…') : `${p.status === 'auto' ? `<button type="button" class="fc-lnk" data-fc-confirm="${p.panel_id}" data-pid="${p.product_id}">potwierdź</button>` : ''}<button type="button" class="fc-lnk" data-fc-edit="${p.panel_id}">zmień</button>`}
            <button type="button" class="fc-lnk" data-fc-ignore="${p.panel_id}">nie od nas</button>${p.status === 'linked' ? `<button type="button" class="fc-lnk" data-fc-auto="${p.panel_id}">podpowiedź</button>` : ''}</div>`;
    }
    function renderSeasons() {
        const ps = D.panel_status;
        const list = D.seasons;
        if (season === null || !list.find(s => s.id === season)) season = ([...list].sort((a, b) => b.cartons - a.cartons || b.ours - a.ours)[0] || {}).id ?? null;
        const s = list.find(x => x.id === season);
        const today = D.today;
        const rel = (x) => x.to < today ? 'skończyła się' : (x.from <= today ? 'trwa do ' + dm(x.to) : 'za ' + Math.max(1, Math.round((new Date(x.from) - new Date(today)) / 6048e5)) + ' tyg.');
        const head = `<h5>Sezony z panelu TF Buddy <span class="kw-badge">gamy sklepów → nasze produkty (SKU) → kartony</span>
            ${ps.read_at ? `<span class="kw-badge ok">odczyt : ${when(ps.read_at)}${ps.read_by ? ' — ' + esc(ps.read_by) : ''}</span>` : '<span class="kw-badge warn">panel jeszcze nie odczytany</span>'}
            ${ps.error ? `<span class="kw-badge bad" title="${esc(ps.error)}">ostatni odczyt nie udał się</span>` : ''}</h5>
            <p class="kw-lead">Gamy sezonowe panelu z datami w tym roku (bez gam całorocznych). Dla każdej gamy : produkty sklepów połączone z naszymi produktami (SKU = numer produktu ERP, tylko katalog SL Food), sprzedane w sklepach w tej samej gamie rok temu (panel, sztuki ÷ sztuk w kartonie ; tydzień bez odczytu — nasze wysyłki z ERP) × trend sieci ${trendTxt()} — rozłożone na tygodnie gamy jak rok temu. Produkty innych dostawców : « nie od nas ». Podpowiedź z nazwy (auto) wchodzi do prognozy od razu — potwierdź albo zmień.</p>`;
        if (!list.length) {
            return `<div class="kw"><div class="kw-card">${head}<p class="at-empty">${ps.configured ? (ps.read_at ? 'Brak gam sezonowych w najbliższych 13 tygodniach.' : 'Odśwież z panelu, aby odczytać gamy.') : 'Konto API panelu TF Buddy nie jest skonfigurowane.'}</p>
                ${apiNote()}<div class="kw-foot"><span></span>${ps.configured ? '<button type="button" class="kw-btn pri" data-fc-refresh>Odśwież z panelu</button>' : ''}</div></div></div>`;
        }
        const cards = list.map(x => `<button type="button" class="fc-se ${x.id === season ? 'on' : ''}" data-fc-season="${x.id}"><span class="at-sub">${rel(x)}</span><b>${esc(x.name)}</b>
            <span class="bar" style="background:${x.color}"></span><small>w tym roku ${dm(x.from)} – ${dm(x.to)}${x.n1_from ? ` · rok temu ${dm(x.n1_from)} – ${dm(x.n1_to)}` : ''}</small>
            <small>${x.ours} ${pl(x.ours, 'nasz produkt', 'nasze produkty', 'naszych produktów')} · ${num(x.cartons)} kart.${x.unlinked ? ` · <span class="fc-warn">${x.unlinked} bez pary</span>` : ''}</small></button>`).join('');
        const idx = s.week_index;
        const rows = s.products.map(p => {
            const dim = p.status === 'ignored' || p.status === 'none';
            const part = !dim && p.n1_weeks && p.n1_weeks_read < p.n1_weeks ? ` · ${p.n1_weeks - p.n1_weeks_read} tyg. z ERP` : '';
            return `<tr class="${p.status === 'ignored' ? 'okrow' : ''}"><td><b>${esc(p.panel_name)}</b><small>panel #${p.panel_id}</small></td><td>${linkCell(p)}</td>
                <td class="r">${dim || p.n1_pieces === null ? '—' : num(p.n1_pieces)}</td>
                <td class="r">${dim || p.n1_cartons === null ? '—' : `<b>${num(p.n1_cartons)}</b><small>${p.ppc ? '÷ ' + p.ppc + ' szt. · ' : ''}wysłane ${num(p.n1_shipped)}${part}</small>`}</td>
                <td class="r"><b>${dim || p.forecast === null ? '—' : num(p.forecast)}</b>${dim ? '' : (p.n1_cartons === 0 ? '<small>brak sprzedaży rok temu</small>' : `<small>N-1 ${trendTxt()}</small>`)}</td>
                <td>${!dim && p.weeks.length ? spark(p.weeks) : ''}</td></tr>`;
        }).join('');
        return `<div class="kw"><div class="kw-card">${head}
            <div class="fc-cards">${cards}</div>
            <div class="table-responsive"><table class="kw-t fc-se-t"><thead><tr><th>Produkt gamy « ${esc(s.name)} » (panel)</th><th>Nasz produkt (SKU)</th><th class="r">Sprzedane w sklepach N-1 (szt.)</th><th class="r">Kartony N-1</th><th class="r">Prognoza (kart.)</th>
                <th>Tyg. ${idx.length ? D.weeks[idx[0]].iso_week + '–' + D.weeks[idx[idx.length - 1]].iso_week : '—'}</th></tr></thead><tbody>${rows}
                <tr class="inv"><td>Razem « ${esc(s.name)} » — nasze produkty</td><td>${s.ours}</td><td></td><td class="r">${num(s.n1_cartons)}</td><td class="r">${num(s.cartons)}</td><td></td></tr></tbody></table></div>
            ${s.unlinked ? `<div class="kw-note warn">${s.unlinked} ${pl(s.unlinked, 'produkt gamy', 'produkty gamy', 'produktów gamy')} bez naszego produktu — połącz albo oznacz « nie od nas » (bez połączenia nie wchodzi do prognozy).</div>` : ''}
            ${apiNote()}
            <div class="kw-foot"><span class="at-sub">Gamy całoroczne (np. « Gamme Standard ») nie są sezonowe ; produkt gamy sprzedawany w ostatnich tygodniach poza gamą liczy się jak całoroczny.</span>
                <span class="d-flex gap-2">${ps.configured ? '<button type="button" class="kw-btn" data-fc-refresh>Odśwież z panelu</button>' : ''}<button type="button" class="kw-btn pri" data-fc-go="forecast">Do prognozy 13 tygodni</button></span></div>
        </div></div>`;
    }
    const apiNote = () => `<div class="fc-api">Źródło — API panelu (konto konsultanta), <b>tylko odczyt (GET)</b>, nic nie jest zmieniane w panelu :
        <code>/product-availability-periods</code> gamy i daty · <code>/product-availability-periods/{id}/products</code> produkty gamy ·
        <code>/material-suppliers</code> → <code>/material-suppliers/{SL Food}/catalog/products</code> nasze SKU ·
        <code>/material-suppliers/{SL Food}/analytics/raw-material-sales-all-shops?from=…&amp;to=…</code> nasze produkty sprzedane we wszystkich sklepach (tydzień po tygodniu).
        Odczyt w poniedziałek o 6:10 i na żądanie ; tydzień już odczytany nie jest pytany drugi raz${D.panel_status.weeks ? ` (w pamięci : ${D.panel_status.weeks} tyg.${D.panel_status.failed ? `, ${D.panel_status.failed} do ponowienia` : ''})` : ''}.</div>`;

    // --- Makieta 3 : zakupy 13 tygodni ---------------------------------------------------------------------------
    function renderBuy() {
        const P = D.purchases, T = P.totals, W = D.weeks;
        const rows = showAllBuy ? P.rows : P.rows.slice(0, 30);
        const seg = `<div class="at-seg fc-seg" role="group">${[['order', 'Do zamówienia'], ['weeks', 'Tydzień po tygodniu']].map(([v, l]) => `<button type="button" data-fc-buyview="${v}" class="${buyView === v ? 'is-on' : ''}" aria-pressed="${buyView === v}">${l}</button>`).join('')}</div>`;
        const appr = D.approval;
        const urgent = P.rows.filter(r => ['now', 'soon'].includes(r.urgency) && r.to_buy > 0);
        const due = (r) => r.urgency === 'unknown' ? '<span class="at-sub">brak inwentaryzacji</span>' : (r.order_by === null ? '<span class="at-sub">wystarczy</span>'
            : (r.urgency === 'now' ? `<span class="fc-due now">teraz</span> <small>(${dm(r.order_by)})</small>` : `<span class="fc-due">do ${DAYS[new Date(r.order_by).getDay()]} ${dm(r.order_by)}</span>`));
        return `<div class="kw"><div class="kw-card">
            <h5>Zakupy 13 tygodni <span class="kw-badge">${appr ? 'z prognozy zatwierdzonej ' + when(appr.approved_at) : 'z prognozy (jeszcze nie zatwierdzonej)'}</span><span class="kw-badge">receptury × kartony</span></h5>
            <p class="kw-lead">Zapotrzebowanie surowców i opakowań na każdy tydzień z prognozy (receptura na karton × kartony), stan dziś (inwentaryzacja + przyjęcia − zużycie) i w drodze. « Zamówić do » = poniedziałek pierwszego tygodnia braku − czas dostawy (z oferty dostawcy, inaczej ${D.purchases.rows[0]?.lead_known === false ? D.purchases.rows[0].lead_days : '7'} dni). Surowce tylko produktów sezonowych oznaczone « sezon ».</p>
            <div class="kw-tiles">
                <div><span>Do kupienia ${W.length} tyg.</span><b>${num(T.value)} zł</b><small>${T.to_buy} ${pl(T.to_buy, 'pozycja', 'pozycje', 'pozycji')}${T.season_value ? ` · w tym sezon ${num(T.season_value)} zł` : ''}</small></div>
                <div class="${urgent.length ? 'todo' : 'ok'}"><span>Zamówić w ciągu 7 dni</span><b>${urgent.length} ${pl(urgent.length, 'pozycja', 'pozycje', 'pozycji')}</b><small>${urgent.length ? esc(urgent.slice(0, 4).map(r => r.name).join(', ')) + (urgent.length > 4 ? '…' : '') : 'nic pilnego'}</small></div>
                <div><span>Tylko sezonowe</span><b>${T.season}</b><small>surowce używane tylko w produktach z gam</small></div>
                <div><span>Szczyt zapotrzebowania</span><b>${T.peak_week !== null ? 'tydz. ' + W[T.peak_week].iso_week : '—'}</b><small>${num(T.peak_value)} zł surowców w tygodniu</small></div>
                <div class="${T.unknown_stock ? 'todo' : 'ok'}"><span>Bez inwentaryzacji</span><b>${T.unknown_stock}</b><small>${T.no_price || T.no_supplier ? `bez ceny ${T.no_price} · bez dostawcy ${T.no_supplier}` : 'stan nieznany = całe zapotrzebowanie do kupienia'}</small></div>
            </div>
            ${P.no_recipe.length ? `<div class="kw-note warn">${P.no_recipe.length} ${pl(P.no_recipe.length, 'produkt', 'produkty', 'produktów')} prognozy bez receptury (nie liczy się w zakupach) : ${esc(P.no_recipe.slice(0, 6).join(', '))}${P.no_recipe.length > 6 ? '…' : ''}</div>` : ''}
            ${seg}
            ${buyView === 'weeks' ? `<div class="table-responsive"><table class="fc-t"><thead><tr><th>Surowiec / opakowanie</th>${weekHead()}<th>Razem</th></tr></thead><tbody>
                ${rows.map(r => `<tr><td>${esc(r.name)} <span class="at-sub">${esc(r.unit)}</span>${r.season ? '<span class="fc-flag">sezon</span>' : ''}</td>${r.need_weeks.map((v, i) => `<td class="${v ? '' : 'z'}${r.short_week !== null && i >= r.short_week && r.urgency !== 'unknown' ? ' over' : ''}">${v ? qty(v, r.factor) : '—'}</td>`).join('')}<td class="s">${qty(r.need, r.factor)}</td></tr>`).join('')}
            </tbody></table></div>` : `<div class="table-responsive"><table class="kw-t"><thead><tr><th>Surowiec</th><th class="r">Stan dziś</th><th class="r">W drodze</th><th class="r">Potrzeba ${W.length} tyg.</th><th class="r">Brak od</th><th>Zamówić do</th><th class="r">Do kupienia</th><th>Dostawca (dni)</th><th class="r">Wartość</th></tr></thead><tbody>
                ${rows.map(r => `<tr><td><b>${esc(r.name)}</b>${r.season ? '<span class="fc-flag">sezon</span>' : ''}<small>${r.products} ${pl(r.products, 'produkt', 'produkty', 'produktów')}</small></td>
                    <td class="r">${r.stock !== null ? qty(r.stock, r.factor) + ' ' + esc(r.unit) : '—'}</td><td class="r">${r.on_order ? qty(r.on_order, r.factor) + ' ' + esc(r.unit) : '—'}</td>
                    <td class="r">${qty(r.need, r.factor)} ${esc(r.unit)}</td><td class="r">${r.short_week === null ? '—' : 'tydz. ' + W[r.short_week].iso_week}</td><td>${due(r)}</td>
                    <td class="r"><b>${r.to_buy ? qty(r.to_buy, r.factor) + ' ' + esc(r.unit) : '—'}</b>${r.packages && r.packages * r.factor < r.to_buy - 1e-6 ? `<small>${num(r.packages)} opak.</small>` : ''}</td>
                    <td>${r.supplier ? esc(r.supplier) : '<span class="kw-badge bad">bez dostawcy</span>'} <span class="at-sub">(${r.lead_days}${r.lead_known ? '' : ' dom.'})</span></td>
                    <td class="r">${r.value !== null ? num(r.value) + ' zł' : (r.to_buy ? '<span class="kw-badge warn">bez ceny</span>' : '—')}</td></tr>`).join('')}
            </tbody></table></div>`}
            ${!showAllBuy && P.rows.length > 30 ? `<button type="button" class="kw-btn kw-sm mt-2" data-fc-allbuy>Pokaż wszystkie (${P.rows.length})</button>` : ''}
            <div class="kw-foot"><span class="at-sub">Zamówienia tygodnia (i zapytania ofertowe) idą przez « Zakupy tygodnia » ; surowce sezonowe — jedno zapytanie na całą gamę.</span>
                <span class="d-flex gap-2"><a class="kw-btn" href="${root}/purchasing/tenders">Zapytanie ofertowe</a><a class="kw-btn pri" href="${root}/workshop/buy">Zakupy tygodnia →</a></span></div>
        </div></div>`;
    }

    function render() {
        const body = app.querySelector('[data-fc-body]');
        if (D.error) { body.innerHTML = `<div class="alert alert-danger">${esc(D.error)}</div>`; return; }
        app.querySelectorAll('[data-fc-tab]').forEach(b => b.classList.toggle('active', b.dataset.fcTab === tab));
        const unlinked = D.seasons.reduce((a, s) => a + s.unlinked, 0);
        const cs = app.querySelector('[data-fc-count="seasons"]');
        cs.textContent = unlinked || ''; cs.classList.toggle('at-tab-count-hot', unlinked > 0); cs.hidden = !unlinked;
        const urgent = D.purchases.rows.filter(r => ['now', 'soon'].includes(r.urgency) && r.to_buy > 0).length;
        const cb = app.querySelector('[data-fc-count="buy"]');
        cb.textContent = urgent || ''; cb.classList.toggle('at-tab-count-hot', urgent > 0); cb.hidden = !urgent;
        const a = D.approval;
        app.querySelector('[data-fc-approval]').innerHTML = a ? `<span class="kw-badge ok">zatwierdzona ${when(a.approved_at)}</span>` : '<span class="kw-badge warn">tydzień niezatwierdzony</span>';
        body.innerHTML = tab === 'seasons' ? renderSeasons() : (tab === 'buy' ? renderBuy() : renderForecast());
    }

    function setTab(t) {
        tab = t; save('tab', t);
        history.replaceState(null, '', '#tab=' + t);
        render();
        window.scrollTo({top: 0, behavior: 'smooth'});
    }
    async function pickOther(panelId) {
        const name = window.Swal ? (await Swal.fire({title: 'Nasz produkt', input: 'text', inputPlaceholder: 'Szukaj : nazwa albo SKU',
            inputAttributes: {list: 'fcProductList', autocomplete: 'off'}, showCancelButton: true, confirmButtonText: 'Połącz', cancelButtonText: 'Anuluj',
            didOpen: () => { if (!document.getElementById('fcProductList')) { const dl = document.createElement('datalist'); dl.id = 'fcProductList'; dl.innerHTML = OPTIONS.map(o => `<option value="${o.id} — ${esc(o.name)}">`).join(''); document.body.appendChild(dl); } }})).value
            : prompt('SKU naszego produktu');
        if (!name) return;
        const id = parseInt(String(name), 10);
        if (!id) { fail(new Error('Wybierz produkt z listy (SKU — nazwa).')); return; }
        act(null, 'POST', `/ajax/forecast/panel-links/${panelId}`, {mode: 'product', product_id: id});
    }

    app.addEventListener('click', async (e) => {
        const t = e.target.closest('button, a');
        if (!t) return;
        if (t.dataset.fcTab) return setTab(t.dataset.fcTab);
        if (t.dataset.fcGo) { e.preventDefault(); return setTab(t.dataset.fcGo); }
        if (t.dataset.fcSeason) { season = parseInt(t.dataset.fcSeason, 10); return render(); }
        if (t.hasAttribute('data-fc-all')) { showAll = true; return render(); }
        if (t.hasAttribute('data-fc-allbuy')) { showAllBuy = true; return render(); }
        if (t.dataset.fcBuyview) { buyView = t.dataset.fcBuyview; save('buy', buyView); return render(); }
        if (t.dataset.fcEdit) { editing = parseInt(t.dataset.fcEdit, 10); return render(); }
        if (t.hasAttribute('data-fc-refresh')) {
            if (window.Swal) Swal.fire({title: 'Odczyt panelu…', text: 'Gamy i sprzedaż sklepów tydzień po tygodniu — pierwszy odczyt może potrwać do 2 minut.', allowOutsideClick: false, didOpen: () => Swal.showLoading()});
            return act(t, 'POST', '/ajax/forecast/panel-refresh');
        }
        if (t.dataset.fcIgnore) return act(t, 'POST', `/ajax/forecast/panel-links/${t.dataset.fcIgnore}`, {mode: 'ignore'});
        if (t.dataset.fcAuto) return act(t, 'POST', `/ajax/forecast/panel-links/${t.dataset.fcAuto}`, {mode: 'auto'});
        if (t.dataset.fcConfirm) return act(t, 'POST', `/ajax/forecast/panel-links/${t.dataset.fcConfirm}`, {mode: 'product', product_id: parseInt(t.dataset.pid, 10)});
        if (t.hasAttribute('data-fc-reopen')) {
            if (window.Swal && !(await Swal.fire({icon: 'question', title: 'Cofnąć zatwierdzenie?', text: 'Zadanie tygodnia wróci do zrobienia.', showCancelButton: true, confirmButtonText: 'Cofnij', cancelButtonText: 'Anuluj'})).isConfirmed) return;
            return act(t, 'POST', '/ajax/forecast/reopen');
        }
        if (t.hasAttribute('data-fc-approve')) {
            const T = D.totals;
            const res = window.Swal ? await Swal.fire({icon: 'question', title: 'Zatwierdzić prognozę?', html: `${num(T.cartons)} kartonów, ${num(T.pallets)} palet, ${num(T.hours)} h produkcji, pieniądze na produkcję ${num(D.cost.total)} zł, zakupy ${num(D.purchases.totals.value)} zł — tydz. ${D.weeks[0].iso_week}–${D.weeks[D.weeks.length - 1].iso_week}.`,
                input: 'text', inputPlaceholder: 'Uwaga (opcjonalnie)', showCancelButton: true, confirmButtonText: 'Zatwierdź', cancelButtonText: 'Anuluj'}) : {isConfirmed: confirm('Zatwierdzić prognozę?'), value: ''};
            if (!res.isConfirmed) return;
            return act(t, 'POST', '/ajax/forecast/approve', {note: res.value || null});
        }
    });
    app.addEventListener('change', (e) => {
        const s = e.target.closest('[data-fc-pick]');
        if (!s || !s.value) return;
        const id = s.dataset.fcPick;
        if (s.value === 'other') { s.value = ''; return pickOther(id); }
        act(s, 'POST', `/ajax/forecast/panel-links/${id}`, {mode: 'product', product_id: parseInt(s.value, 10)});
    });
    let timer = null;
    app.addEventListener('input', (e) => {
        if (!e.target.matches('[data-fc-filter]')) return;
        clearTimeout(timer);
        timer = setTimeout(() => {
            filter = e.target.value;
            const pos = e.target.selectionStart;
            render();
            const f = app.querySelector('[data-fc-filter]');
            if (f) { f.focus(); f.setSelectionRange(pos, pos); }
        }, 200);
    });
    render();
})();
