/*
 * Zaopatrzenie › Faktury › Kreator [217] (makiety 1–3) : wszystkie otwarte faktury (KSeF, pliki) w jednej tabeli —
 * 1. Pobierz z KSeF : faktury, pozycje sprawdzone automatycznie / do sprawdzenia, uwagi ;
 * 2. Sprawdź pozycje : pozycje wszystkich faktur w jednej tabeli, bieżąca otwarta (surowiec, jednostka, cena wobec ceny
 *    bazowej i zamówienia), Enter zatwierdza i przechodzi do następnej ; nazwa zapamiętana działa też w innych fakturach ;
 * 3. Zatwierdź faktury : ze wszystkimi pozycjami sprawdzonymi — ceny do kartoteki, przyjęcie na stan, akceptacja.
 * API : GET /supply/invoices/wizard, POST /supply/invoices/{id}/lines/{lineId}/check, POST …/wizard/check-safe, POST …/wizard/process.
 * Etykiety kw.* w supply.json.
 */
(function () {
    'use strict';
    const {ui} = window.TfbWorkshop;
    const esc = ui.esc;
    const errText = (err) => ((err.body && err.body.errors) || []).map(x => x.message).join(' ') || err.message;
    const UNITS = {g: [['kg', 1000], ['g', 1]], ml: [['l', 1000], ['ml', 1]], szt: [['szt', 1]]};

    function mount(host, {api, back}) {
        const t = (k, p) => api.t(k, p);
        const n = (v, d = 0) => v === null || v === undefined ? '—' : ui.fmt(v, d, api.lang);
        // Ceny i kwoty zawsze z 2 miejscami (23,50 zł/kg), jak w oknie podwyżki.
        const f2 = (v) => Number(v).toLocaleString(api.lang === 'fr' ? 'fr-FR' : 'pl-PL', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        const money = (v) => v === null || v === undefined ? '—' : `${f2(v)} zł`;
        const day = (s) => s ? `${s.slice(8, 10)}.${s.slice(5, 7)}` : '—';
        const pct = (v) => v === null || v === undefined ? '—' : `${v > 0 ? '+' : (v < 0 ? '−' : '')}${n(Math.abs(v), 1)} %`;
        const hashStep = () => Number(new URLSearchParams(location.hash.replace(/^#/, '')).get('ws')) || 1;
        let W = null;
        let step = Math.min(3, Math.max(1, hashStep()));
        let filter = 'todo';
        let cur = null;            // klucz bieżącej pozycji « fakturaId:liniaId »
        let materials = null;      // wszystkie surowce (lista wyboru), wczytane przy pierwszej potrzebie
        let busy = false;
        const chosen = new Set();  // krok 3 : wybrane faktury (domyślnie wszystkie gotowe)

        const setStep = (s) => {
            step = s;
            const p = new URLSearchParams(location.hash.replace(/^#/, ''));
            p.set('ws', String(s));
            history.replaceState(null, '', '#' + p.toString());
            render();
            host.scrollIntoView({block: 'start'});
        };
        const key = (inv, l) => `${inv.id}:${l.id}`;
        const done = (l) => l.state === 'auto' || l.state === 'checked';
        const lineVisible = (l) => filter === 'all' || (filter === 'todo' ? l.state === 'todo' : (filter === 'checked' ? done(l) : l.state === 'disputed'));
        // Kolejność pozycji (faktura po fakturze) : bieżąca = pierwsza do sprawdzenia, po zatwierdzeniu następna.
        const ordered = () => W.invoices.flatMap(inv => inv.lines.map(l => ({inv, l})));
        const nextTodo = (afterKey) => {
            const all = ordered();
            const i = afterKey ? all.findIndex(x => key(x.inv, x.l) === afterKey) : -1;
            const later = all.slice(i + 1).find(x => x.l.state === 'todo');
            return later || all.find(x => x.l.state === 'todo') || null;
        };
        const find = (k) => ordered().find(x => key(x.inv, x.l) === k) || null;

        async function load(keepCur) {
            try {
                W = await api.get('/supply/invoices/wizard');
            } catch (err) {
                host.innerHTML = `<div class="alert alert-danger">${esc(errText(err))}</div>`;
                return;
            }
            afterData(keepCur);
        }
        function afterData(prevKey) {
            if (!cur || !find(cur) || find(cur).l.state !== 'todo') {
                const nx = nextTodo(prevKey || cur);
                cur = nx ? key(nx.inv, nx.l) : null;
            }
            W.invoices.forEach(i => { if (i.wizard_ready && !chosen.has(-i.id)) chosen.add(i.id); });
            for (const id of [...chosen]) if (id > 0 && !W.invoices.some(i => i.id === id && i.wizard_ready)) chosen.delete(id);
            render();
        }
        async function ensureMaterials() {
            if (materials) return;
            try { materials = (await api.get('/supply/materials')).rows; } catch (err) { materials = []; ui.notify(false, errText(err)); }
            render();
        }

        // --- Wspólne ------------------------------------------------------------------------------------------
        const steps = () => `<ul class="kw-steps" role="tablist">${[1, 2, 3].map(i => `<li class="${i < step ? 'done' : (i === step ? 'cur' : '')}">
            <button type="button" data-kw-step="${i}" role="tab" aria-selected="${i === step}"><b>${i < step ? '✓' : i}</b><span>${esc(t('kw.step' + i))}<small>${esc(t('kw.step' + i + '_help'))}</small></span></button></li>`).join('')}</ul>`;
        const bar = (inv) => { const d = inv.counts.auto + inv.counts.checked; return `<span class="kw-mini"><i style="width:${inv.counts.lines ? d / inv.counts.lines * 100 : 0}%"></i></span><span class="tfb-num">${d} / ${inv.counts.lines}</span>`; };
        const seller = (inv) => inv.supplier ? inv.supplier.name : (inv.seller_name || '—');
        const reasonsOf = (inv) => {
            const r = {new: 0, price: 0, qty: 0, other: 0};
            inv.lines.forEach(l => { if (l.state === 'todo') r[l.reason]++; });
            return r;
        };
        const remarks = (inv) => {
            const r = reasonsOf(inv);
            const out = [];
            if (r.new) out.push(['new', t('kw.r.new', {n: r.new})]);
            if (r.price) out.push(['warn', t('kw.r.price', {n: r.price})]);
            if (r.qty) out.push(['bad', t('kw.r.qty', {n: r.qty})]);
            if (r.other) out.push(['', t('kw.r.other', {n: r.other})]);
            if (inv.counts.disputed) out.push(['bad', t('kw.r.disputed', {n: inv.counts.disputed})]);
            if (!inv.supplier) out.push(['bad', t('kw.no_supplier')]);
            if (!out.length) out.push(['ok', t('kw.all_known')]);
            return out.map(([c, s]) => `<span class="kw-badge ${c}">${esc(s)}</span>`).join('');
        };
        const statusOf = (inv) => inv.wizard_ready ? `<span class="kw-badge ok">✓ ${esc(t('kw.ready'))}</span>`
            : (inv.counts.todo ? `<span class="kw-badge">${esc(t('kw.todo_n', {n: inv.counts.todo}))}</span>`
                : (inv.counts.disputed ? `<span class="kw-badge bad">${esc(t('kw.disputed'))}</span>` : `<span class="kw-badge bad">${esc(t('kw.no_supplier'))}</span>`));
        const supplierPick = (inv) => inv.supplier || !W.can_write ? '' : `<span class="kw-sup"><select class="form-select form-select-sm" data-kw-supplier="${inv.id}" aria-label="${esc(t('kw.pick_supplier'))}">
            <option value="">${esc(t('kw.pick_supplier'))}</option>${inv.supplier_choices.slice(0, 40).map(s => `<option value="${s.id}">${esc(s.name)}${s.score >= 0.5 ? ' · ' + Math.round(s.score * 100) + ' %' : ''}</option>`).join('')}</select></span>`;

        // --- Krok 1 : faktury -------------------------------------------------------------------------------
        function stepOne() {
            const T = W.totals;
            const R = T.reasons;
            const rs = [R.new && t('kw.r.new', {n: R.new}), R.price && t('kw.r.price', {n: R.price}), R.qty && t('kw.r.qty', {n: R.qty}), R.other && t('kw.r.other', {n: R.other})].filter(Boolean).join(' · ');
            const rows = W.invoices.map(i => `<tr class="tfb-supply-click" data-kw-open="${i.id}"><td><b>${esc(i.number || t('inv.no_number'))}</b><small>${esc(t('inv.source.' + i.source))}${i.is_correction ? ' · ' + esc(t('kw.correction')) : ''}</small></td>
                <td>${esc(seller(i))}${!i.supplier && i.seller_nip ? `<small>NIP ${esc(i.seller_nip)}</small>` : ''}</td><td>${day(i.issue_date)}</td><td class="r tfb-num">${money(i.total_net)}</td>
                <td>${i.order ? esc(i.order.code) : '—'}</td><td class="text-nowrap">${bar(i)}</td><td><span class="kw-do">${remarks(i)}</span></td><td class="r">${statusOf(i)}</td></tr>`).join('');
            return `<div class="kw-card"><h5>${esc(t('kw.s1.title'))} ${W.last_ksef ? `<span class="kw-badge ok">${esc(t('kw.last_ksef', {d: day(W.last_ksef.slice(0, 10)), h: W.last_ksef.slice(11, 16)}))}</span>` : ''}</h5>
                <p class="kw-lead">${esc(t('kw.s1.lead'))}</p>
                <div class="kw-tiles"><div><span>${esc(t('kw.t.invoices'))}</span><b>${T.invoices}</b><small>${money(T.net)} ${esc(t('kw.net'))}</small></div>
                    <div><span>${esc(t('kw.t.lines'))}</span><b>${T.lines}</b><small>${esc(t('kw.t.lines_help'))}</small></div>
                    <div class="ok"><span>${esc(t('kw.t.auto'))}</span><b>${T.auto + T.checked}</b><small>${esc(t('kw.t.auto_help', {a: T.auto, c: T.checked}))}</small></div>
                    <div class="${T.todo ? 'todo' : 'ok'}"><span>${esc(t('kw.t.todo'))}</span><b>${T.todo}</b><small>${esc(rs || t('kw.nothing'))}${T.disputed ? ' · ' + esc(t('kw.r.disputed', {n: T.disputed})) : ''}</small></div>
                    <div class="${T.ready ? 'ok' : ''}"><span>${esc(t('kw.t.ready'))}</span><b>${T.ready}</b><small>${money(T.ready_net)}</small></div></div>
                ${W.invoices.length ? `<div class="table-responsive"><table class="kw-t"><thead><tr><th>${esc(t('inv.col.number'))}</th><th>${esc(t('col.supplier'))}</th><th>${esc(t('inv.col.date'))}</th>
                    <th class="r">${esc(t('inv.col.net'))}</th><th>${esc(t('inv.col.order'))}</th><th>${esc(t('kw.col.checked'))}</th><th>${esc(t('kw.col.remarks'))}</th><th></th></tr></thead><tbody>${rows}</tbody></table></div>`
                    : `<p class="at-empty mb-0">${esc(t('kw.empty'))}</p>`}
                <div class="kw-foot"><span class="kw-do">${W.can_write ? `<button type="button" class="kw-btn" data-kw-sync><i class="bi bi-cloud-download"></i> ${esc(t('inv.ksef_sync'))}</button>` : ''}
                    <button type="button" class="kw-btn ghost" data-kw-back><i class="bi bi-list-ul"></i> ${esc(t('kw.list'))}</button></span>
                    <span class="kw-do">${T.ready && W.can_write ? `<button type="button" class="kw-btn ok" data-kw-step="3">${esc(t('kw.approve_ready', {n: T.ready}))}</button>` : ''}
                    <button type="button" class="kw-btn pri" data-kw-step="2" ${T.todo || T.disputed ? '' : 'disabled'}>${esc(t('kw.check_lines', {n: T.todo}))} →</button></span></div></div>`;
        }

        // --- Krok 2 : pozycje ---------------------------------------------------------------------------------
        const badgeOf = (l) => {
            if (l.state === 'auto') return `<span class="kw-badge ok">✓ ${esc(t('kw.auto'))}</span>`;
            if (l.state === 'checked') return `<span class="kw-badge ok" title="${esc(l.check && l.check.at ? l.check.at.slice(0, 16) : '')}">✓ ${esc(l.check && l.check.by ? l.check.by : t('kw.checked'))}</span>`;
            if (l.state === 'disputed') return `<span class="kw-badge bad" title="${esc(l.check && l.check.note || '')}">${esc(t('kw.disputed'))}</span>`;
            const f = l.control.flags;
            const k = l.reason === 'new' ? 'new' : (l.reason === 'price' ? (f.includes('price_up') ? 'up' : (f.includes('price_over') ? 'over' : 'jump'))
                : (l.reason === 'qty' ? (f.includes('not_in_order') ? 'not_in_order' : (f.includes('qty_over_ordered') ? 'qty_ordered' : 'qty_received')) : 'cheaper'));
            return `<span class="kw-badge ${{new: 'new', price: 'warn', qty: 'bad', other: ''}[l.reason]}">${esc(t('kw.flag.' + k))}</span>`;
        };
        const displayOf = (l) => l.material ? {unit: l.material.unit, f: l.material.factor_display} : null;
        const qtyBase = (l) => { const d = displayOf(l); return d && l.control.quantity_base !== null ? `${n(l.control.quantity_base / d.f, d.f > 1 ? 2 : 0)} ${d.unit}` : ''; };
        const perDisplay = (l, v) => { const d = displayOf(l); return d && v !== null && v !== undefined ? `${f2(v * d.f)} zł/${d.unit}` : '—'; };
        const orderTxt = (l) => {
            const d = displayOf(l);
            if (!d || (l.control.ordered === null && l.control.received === null)) return '—';
            return `${esc(t('kw.ordered'))} ${n(l.control.ordered / d.f, d.f > 1 ? 1 : 0)} · ${esc(t('kw.received'))} ${n((l.control.received || 0) / d.f, d.f > 1 ? 1 : 0)}`;
        };
        function lineRow(inv, l, num) {
            const k = key(inv, l);
            const isCur = k === cur;
            const gap = l.control.catalog_gap_pct;
            return `<tr class="${done(l) ? 'okrow' : ''} ${isCur ? 'cur' : ''} tfb-supply-click" data-kw-line="${k}"><td>${done(l) ? '✓' : num}</td>
                <td><b>${esc(l.name)}</b>${l.code ? `<small>${esc(l.code)}</small>` : ''}</td><td class="r tfb-num">${n(l.quantity, l.quantity % 1 ? 2 : 0)} ${esc(l.unit || '')}</td>
                <td>${l.material ? `${esc(l.material.name)}<small>${qtyBase(l)}</small>` : `<span class="kw-badge new">${esc(t('kw.pick_material'))}</span>`}</td>
                <td class="r tfb-num">${l.material ? perDisplay(l, l.control.price_base) : (l.unit_price !== null ? `${f2(l.unit_price)} zł/${esc(l.unit || 'szt')}` : '—')}${l.control.catalog_price ? `<small>${esc(t('kw.base'))} ${perDisplay(l, l.control.catalog_price)}</small>` : ''}</td>
                <td class="r tfb-num ${gap !== null && gap > 3 ? 'kw-up' : (gap !== null && gap < -3 ? 'kw-down' : '')}">${pct(gap)}</td>
                <td><small class="d-inline">${orderTxt(l)}</small></td><td class="r">${badgeOf(l)}</td></tr>`;
        }
        const score = (s) => s.known ? t('kw.known_name') : `${Math.round(s.score * 100)} %`;
        function panel(inv, l) {
            const sug = l.suggestions || [];
            const sel = l.material ? l.material.id : (sug[0] ? sug[0].raw_material_id : null);
            const sugIds = new Set(sug.map(s => s.raw_material_id));
            const rest = (materials || []).filter(m => !sugIds.has(m.id) && (!l.material || m.id !== l.material.id));
            const opts = `<option value="">${esc(t('kw.pick_material'))}</option>`
                + (l.material ? `<option value="${l.material.id}" selected>${esc(l.material.name)}</option>` : '')
                + (sug.length ? `<optgroup label="${esc(t('inv.suggested'))}">${sug.map(s => `<option value="${s.raw_material_id}" data-price="${s.catalog_price ?? ''}" data-base="${esc(s.base_unit)}" ${!l.material && s.raw_material_id === sel ? 'selected' : ''}>${esc(s.name)} · ${esc(score(s))}</option>`).join('')}</optgroup>` : '')
                + (rest.length ? `<optgroup label="${esc(t('inv.all_materials'))}">${rest.map(m => `<option value="${m.id}">${esc(m.name)}</option>`).join('')}</optgroup>` : '')
                + (materials ? '' : `<option value="" disabled>${esc(t('kw.loading_materials'))}</option>`);
            const base = l.material ? l.material.base_unit : (sug[0] ? sug[0].base_unit : 'szt');
            const units = UNITS[base] || [[base, 1]];
            const factor = l.material ? l.factor : (sug[0] ? sug[0].factor : null);
            const [u0, s0] = units[0];
            const up = l.up && !l.up.decision ? l.up : null;
            const c = l.control;
            const odd = suspicious(l);
            const qtyNote = c.ordered !== null ? (l.state === 'todo' && l.reason === 'qty' ? 'warn' : '') : null;
            const write = W.can_write;
            return `<tr class="panel" data-kw-panel="${key(inv, l)}" data-unit-price="${l.unit_price ?? ''}"><td></td><td colspan="7"><div class="kw-p">
                <div><h6>${esc(t('kw.our_material'))}</h6><select class="form-select kw-field" data-kw-mat aria-label="${esc(t('kw.our_material'))}" ${write ? '' : 'disabled'}>${opts}</select>
                    ${sug.length && !l.material ? `<div class="kw-chips">${sug.slice(0, 4).map(s => `<button type="button" class="kw-chip ${s.raw_material_id === sel ? 'on' : ''}" data-kw-sug="${s.raw_material_id}" data-factor="${s.factor ?? ''}">${esc(s.name)} · ${esc(score(s))}</button>`).join('')}</div>` : ''}
                    <label class="kw-check"><input type="checkbox" data-kw-remember checked ${write ? '' : 'disabled'}> ${esc(t('kw.remember', {s: seller(inv)}))}</label></div>
                <div><h6>${esc(t('kw.unit'))}</h6><div class="kw-conv">1 ${esc(l.unit || 'szt')} = <input class="kw-in" inputmode="decimal" data-kw-factor value="${factor ? String(Math.round(factor / s0 * 10000) / 10000).replace('.', ',') : ''}" placeholder="${esc(t('kw.auto_factor'))}" aria-label="${esc(t('inv.factor'))}" ${write ? '' : 'disabled'}>
                    <span class="kw-do">${units.map(([u, s]) => `<button type="button" class="kw-chip ${u === u0 ? 'on' : ''}" data-kw-unit="${s}">${u}</button>`).join('')}</span></div>
                    ${qtyNote !== null ? `<div class="kw-note ${qtyNote}">${esc(t('kw.qty_note', {q: qtyBase(l), o: n(c.ordered / (displayOf(l)?.f || 1), 1), r: n((c.received || 0) / (displayOf(l)?.f || 1), 1), u: displayOf(l)?.unit || ''}))}${inv.order ? ' · ' + esc(inv.order.code) : ''}</div>` : (l.material ? `<div class="kw-note">${esc(t('kw.qty_is', {q: qtyBase(l)}))}${inv.order ? '' : ' · ' + esc(t('kw.no_order'))}</div>` : '')}</div>
                <div><h6>${esc(t('kw.price'))}</h6><div class="kw-price"><span>${esc(t('kw.invoice'))}</span><b>${l.unit_price !== null ? `${f2(l.unit_price)} zł / ${esc(l.unit || 'szt')}` : '—'}</b>
                    ${l.material ? `<span>${esc(t('kw.per_unit', {u: displayOf(l).unit}))}</span><b>${perDisplay(l, c.price_base)}</b>` : ''}
                    ${c.catalog_price ? `<span>${esc(t('kw.base_price'))}</span><b>${perDisplay(l, c.catalog_price)}</b><span>${esc(t('kw.change'))}</span><b class="${c.catalog_gap_pct > 0 ? 'kw-up' : 'kw-down'}">${pct(c.catalog_gap_pct)}${l.up ? ' · ' + esc(t('kw.recipes', {n: l.up.recipes})) : ''}</b>` : ''}
                    ${c.order_price ? `<span>${esc(t('kw.order_price'))}${inv.order ? ' ' + esc(inv.order.code) : ''}</span><b>${perDisplay(l, c.order_price)}</b>` : ''}</div>
                    ${l.material ? '' : '<div data-kw-preview></div>'}
                    ${l.up && l.up.decision ? `<div class="kw-note">${esc(t('kw.up_decided.' + l.up.decision))}</div>` : ''}
                    ${odd ? `<div class="kw-note bad">${esc(t('kw.odd_price', {p: pct(c.catalog_gap_pct)}))}</div>` : ''}</div></div>
                ${write ? `<div class="kw-acts">${l.state === 'todo' || l.state === 'auto' ? (up
                        ? `<button type="button" class="kw-btn ${odd ? '' : 'pri'}" data-kw-check="accept" ${odd ? 'data-kw-odd' : ''}>${esc(t('kw.act.accept'))}${odd ? '' : ' <span class="kw-key">Enter</span>'}</button><button type="button" class="kw-btn" data-kw-check="keep">${esc(t('kw.act.keep'))} <span class="kw-key">K</span></button>`
                        : `<span class="kw-do" data-kw-plain><button type="button" class="kw-btn ${odd ? '' : 'pri'}" data-kw-check="" ${odd ? 'data-kw-odd' : ''}>${esc(t('kw.act.check'))}${odd ? '' : ' <span class="kw-key">Enter</span>'}</button></span>`
                          + (l.material ? '' : `<span class="kw-do d-none" data-kw-pair><button type="button" class="kw-btn pri" data-kw-check="accept">${esc(t('kw.act.accept'))} <span class="kw-key">Enter</span></button><button type="button" class="kw-btn" data-kw-check="keep">${esc(t('kw.act.keep'))} <span class="kw-key">K</span></button></span>`))
                    : `<button type="button" class="kw-btn" data-kw-reset>${esc(t('kw.act.reset'))}</button>`}
                    ${l.state === 'todo' ? `<button type="button" class="kw-btn" data-kw-skip>${esc(t('kw.act.skip'))} <span class="kw-key">→</span></button>` : ''}
                    ${l.state !== 'disputed' ? `<button type="button" class="kw-btn ghost" data-kw-dispute>${esc(t('kw.act.dispute'))}</button>` : ''}
                    <span class="kw-lead m-0 ms-auto">${esc(nextLabel(inv, l))}</span></div>` : ''}</td></tr>`;
        }
        // Zmiana ceny nie do wiary (zwykle zły przelicznik / jednostka) : bez Enter, nowa cena tylko po potwierdzeniu.
        const suspicious = (l) => l.material && l.control.catalog_gap_pct !== null && (l.control.catalog_gap_pct > 100 || l.control.catalog_gap_pct < -60);
        const nextLabel = (inv, l) => { const nx = nextTodo(key(inv, l)); return nx && key(nx.inv, nx.l) !== key(inv, l) ? t('kw.next', {name: nx.l.name.slice(0, 48)}) : t('kw.last'); };
        function stepTwo() {
            const T = W.totals;
            const doneN = T.auto + T.checked;
            const counts = {todo: T.todo, all: T.lines, checked: doneN, disputed: T.disputed};
            let num = 0;
            const groups = W.invoices.map(inv => {
                const vis = inv.lines.filter(l => lineVisible(l) || key(inv, l) === cur);
                if (!vis.length) return '';
                return `<tr class="inv"><td colspan="8"><span>${esc(inv.number || t('inv.no_number'))} · ${esc(seller(inv))}</span><small>${day(inv.issue_date)} · ${money(inv.total_net)} ${esc(t('kw.net'))}${inv.order ? ' · ' + esc(t('kw.order_short', {c: inv.order.code})) : ''} · ${esc(t('kw.checked_of', {d: inv.counts.auto + inv.counts.checked, n: inv.counts.lines}))}</small>${supplierPick(inv)}</td></tr>`
                    + vis.map(l => { if (!done(l)) num++; return lineRow(inv, l, num) + (key(inv, l) === cur ? panel(inv, l) : ''); }).join('');
            }).join('');
            return `<div class="kw-card"><h5>${esc(t('kw.s2.title'))} <span class="kw-badge">${esc(t('kw.s2.badge', {i: T.invoices, n: T.lines}))}</span></h5>
                <p class="kw-lead">${esc(t('kw.s2.lead'))}</p>
                <div class="kw-bar"><div class="at-seg" role="group">${['todo', 'all', 'checked', 'disputed'].map(f => `<button type="button" class="${filter === f ? 'is-on' : ''}" data-kw-filter="${f}" aria-pressed="${filter === f}">${esc(t('kw.f.' + f))} <span class="tfb-num">${counts[f]}</span></button>`).join('')}</div>
                    <span class="kw-prog" title="${doneN} / ${T.lines}"><i style="width:${T.lines ? doneN / T.lines * 100 : 0}%;background:#2e7d4f"></i><i style="width:${T.lines ? T.disputed / T.lines * 100 : 0}%;background:#8e1f2f"></i></span>
                    <b class="tfb-num">${esc(t('kw.progress', {d: doneN, n: T.lines}))}</b>
                    ${T.safe && W.can_write ? `<button type="button" class="kw-btn" data-kw-safe title="${esc(t('kw.safe_help'))}">${esc(t('kw.safe', {n: T.safe}))}</button>` : ''}</div>
                ${groups ? `<div class="table-responsive"><table class="kw-t"><thead><tr><th style="width:34px"></th><th>${esc(t('kw.col.line'))}</th><th class="r">${esc(t('kw.col.qty'))}</th><th>${esc(t('kw.our_material'))}</th>
                    <th class="r">${esc(t('kw.col.price'))}</th><th class="r">${esc(t('kw.change'))}</th><th>${esc(t('inv.col.order'))}</th><th class="r">${esc(t('col.status'))}</th></tr></thead><tbody>${groups}</tbody></table></div>`
                    : `<div class="kw-note">${esc(filter === 'todo' ? t('kw.all_done') : t('kw.nothing'))}</div>`}
                <div class="kw-foot"><button type="button" class="kw-btn" data-kw-step="1">← ${esc(t('kw.step1'))}</button>
                    <button type="button" class="kw-btn pri" data-kw-step="3">${esc(t('kw.to_step3', {n: T.ready}))} →</button></div></div>`;
        }

        // --- Krok 3 : zatwierdzenie -----------------------------------------------------------------------------
        const WARN = (w) => esc(t('inv.warn.' + w));
        function stepThree() {
            const ready = W.invoices.filter(i => i.wizard_ready);
            const waiting = W.invoices.filter(i => !i.wizard_ready);
            const sel = ready.filter(i => chosen.has(i.id));
            const sum = sel.reduce((a, i) => a + (i.total_net || 0), 0);
            const prices = sel.reduce((a, i) => a + i.plan.prices.filter(p => !p.kept).length, 0);
            const receive = sel.filter(i => i.plan.receive).length;
            const priceTxt = (i) => i.plan.prices_done ? t('kw.prices_done') : (i.plan.prices.length
                ? i.plan.prices.slice(0, 3).map(p => `${p.name.slice(0, 22)} ${p.kept ? t('kw.kept') : pct(p.pct)}`).join(' · ') + (i.plan.prices.length > 3 ? ` · +${i.plan.prices.length - 3}` : '') : t('kw.prices_same'));
            const recvTxt = (i) => i.plan.receive === 'order' ? t('kw.recv_order', {c: i.order.code}) : (i.plan.receive === 'invoice' ? t('kw.recv_invoice') : t('kw.recv_none'));
            const row = (i, ok) => `<tr class="${ok ? '' : 'okrow'}"><td>${ok ? `<input type="checkbox" class="form-check-input kw-box" data-kw-pick="${i.id}" ${chosen.has(i.id) ? 'checked' : ''} aria-label="${esc(i.number || '')}" ${W.can_write ? '' : 'disabled'}>` : ''}</td>
                <td><b>${esc(i.number || t('inv.no_number'))}</b><small>${day(i.issue_date)}</small></td><td>${esc(seller(i))}</td><td class="r tfb-num">${money(i.total_net)}</td><td class="text-nowrap">${bar(i)}</td>
                <td>${ok ? esc(priceTxt(i)) : ''}</td><td>${ok ? esc(recvTxt(i)) : ''}${ok && i.warnings.length ? `<div class="kw-do mt-1">${i.warnings.map(w => `<span class="kw-badge warn">${WARN(w)}</span>`).join('')}</div>` : ''}</td>
                <td class="r">${ok ? `<span class="kw-badge ok">${esc(t('kw.will_accept'))}</span>` : `<button type="button" class="kw-btn ghost kw-sm" data-kw-goto="${i.id}">${statusOf(i)}</button>`}</td></tr>`;
            return `<div class="kw-card"><h5>${esc(t('kw.s3.title'))} <span class="kw-badge ok">${esc(t('kw.s3.ready', {n: ready.length}))}</span>${waiting.length ? `<span class="kw-badge">${esc(t('kw.s3.waiting', {n: waiting.length}))}</span>` : ''}</h5>
                <p class="kw-lead">${esc(t('kw.s3.lead'))}</p>
                ${W.invoices.length ? `<div class="table-responsive"><table class="kw-t"><thead><tr><th style="width:34px"></th><th>${esc(t('inv.col.number'))}</th><th>${esc(t('col.supplier'))}</th><th class="r">${esc(t('inv.col.net'))}</th>
                    <th>${esc(t('kw.col.checked'))}</th><th>${esc(t('kw.col.prices'))}</th><th>${esc(t('kw.col.receive'))}</th><th class="r">${esc(t('kw.col.result'))}</th></tr></thead>
                    <tbody>${ready.map(i => row(i, true)).join('')}${waiting.map(i => row(i, false)).join('')}</tbody></table></div>` : `<p class="at-empty mb-0">${esc(t('kw.empty'))}</p>`}
                ${sel.length ? `<div class="kw-note">${esc(t('kw.s3.summary', {p: prices, r: receive, n: sel.length}))}</div>` : ''}
                <div class="kw-foot"><button type="button" class="kw-btn" data-kw-step="2">← ${esc(t('kw.back_lines'))}</button>
                    ${W.can_write ? `<button type="button" class="kw-btn pri" data-kw-process ${sel.length ? '' : 'disabled'}>${esc(t('kw.process', {n: sel.length, s: money(sum)}))}</button>` : ''}</div></div>`;
        }

        function render() {
            if (!W) { host.innerHTML = `<p class="text-muted">${esc(t('loading'))}</p>`; return; }
            const y = window.scrollY;
            host.innerHTML = `<div class="kw">${steps()}${step === 1 ? stepOne() : (step === 2 ? stepTwo() : stepThree())}</div>`;
            window.scrollTo(0, y);
            if (step === 2 && cur) {
                const x = find(cur);
                if (x && !x.l.material && !materials) ensureMaterials();
                const p = host.querySelector('[data-kw-panel]');
                if (p) preview(p);
                if (p && !isVisible(p)) p.scrollIntoView({block: 'center'});
            }
        }
        const isVisible = (n) => { const r = n.getBoundingClientRect(); return r.top >= 0 && r.bottom <= window.innerHeight; };

        // --- Akcje ------------------------------------------------------------------------------------------
        async function act(fn, okMsg, prevKey = cur) {
            if (busy) return;
            busy = true;
            host.classList.add('kw-busy');
            try {
                const res = await fn();
                if (res && res.invoices) { W = res; afterData(prevKey); }
                if (okMsg) ui.notify(true, typeof okMsg === 'function' ? okMsg(res) : okMsg);
                return res;
            } catch (err) {
                ui.notify(false, errText(err));
            } finally {
                busy = false;
                host.classList.remove('kw-busy');
            }
        }
        function panelBody(p) {
            const mid = Number(p.querySelector('[data-kw-mat]').value) || null;
            const fIn = p.querySelector('[data-kw-factor]');
            const scale = Number(p.querySelector('[data-kw-unit].on')?.dataset.kwUnit || 1);
            const raw = fIn.value.trim().replace(/\s/g, '').replace(',', '.');
            const factor = raw === '' ? null : Number(raw) * scale;
            return {mid, factor, remember: p.querySelector('[data-kw-remember]').checked, valid: raw === '' || (isFinite(factor) && factor > 0)};
        }
        async function check(price) {
            const x = find(cur);
            const p = host.querySelector('[data-kw-panel]');
            if (!x || !p) return;
            const b = panelBody(p);
            if (!b.valid) { ui.notify(false, t('kw.bad_factor')); return; }
            if (!b.mid && !x.l.material) { ui.notify(false, t('kw.need_material')); return; }
            const body = {action: 'check', remember: b.remember, price: price || null};
            if (b.mid && (!x.l.material || b.mid !== x.l.material.id || (b.factor !== null && Math.abs(b.factor - (x.l.factor || 0)) > 1e-9))) { body.raw_material_id = b.mid; if (b.factor !== null) body.factor = b.factor; }
            const prev = cur;
            cur = null;
            const res = await act(() => api.post(`/supply/invoices/${x.inv.id}/lines/${x.l.id}/check`, body), null, prev);
            // Błąd (np. podwyżka ujawniona przez nowe przypisanie — przypisanie zostaje) : linia odświeżona, pytanie o cenę.
            if (!res) { cur = prev; await load(); return; }
            // Po zmianie surowca podwyżka mogła się pojawić dopiero teraz : linia zostaje bieżąca z pytaniem o cenę.
            const again = find(prev);
            if (again && again.l.state === 'todo' && again.l.reason === 'price' && !price) { cur = prev; render(); ui.notify(false, t('kw.price_question')); }
        }
        async function dispute() {
            const x = find(cur);
            if (!x) return;
            let note = null;
            if (window.Swal) {
                const r = await Swal.fire({title: t('kw.dispute_title'), input: 'text', inputPlaceholder: t('kw.dispute_ph'), showCancelButton: true, confirmButtonText: t('kw.act.dispute'), cancelButtonText: t('act.cancel'), confirmButtonColor: '#8e1f2f'});
                if (!r.isConfirmed) return;
                note = r.value || null;
            } else {
                note = window.prompt(t('kw.dispute_title'));
                if (note === null) return;
            }
            const prev = cur;
            cur = null;
            const res = await act(() => api.post(`/supply/invoices/${x.inv.id}/lines/${x.l.id}/check`, {action: 'dispute', note}), t('kw.disputed_saved'), prev);
            if (!res) { cur = prev; render(); }
        }
        // Linia bez surowca : podgląd ceny z wybranej podpowiedzi (cena bazowa, zmiana) jeszcze przed zatwierdzeniem —
        // zła jednostka widoczna od razu (zmiana nie do wiary : bez Enter, jak po przypisaniu).
        function preview(p) {
            const box = p.querySelector('[data-kw-preview]');
            if (!box) return;
            const opt = p.querySelector('[data-kw-mat]').selectedOptions[0];
            const price = Number(opt?.dataset.price || 0);
            const unitPrice = Number(p.dataset.unitPrice || 0);
            const scale = Number(p.querySelector('[data-kw-unit].on')?.dataset.kwUnit || 1);
            const raw = p.querySelector('[data-kw-factor]').value.trim().replace(/\s/g, '').replace(',', '.');
            const factor = raw === '' ? null : Number(raw) * scale;
            if (!price || !unitPrice || !factor || !isFinite(factor)) {
                box.innerHTML = '';
                p.querySelector('[data-kw-plain]')?.classList.remove('d-none');
                p.querySelector('[data-kw-pair]')?.classList.add('d-none');
                return;
            }
            const base = opt.dataset.base;
            const [unit, f] = base === 'g' ? ['kg', 1000] : (base === 'ml' ? ['l', 1000] : [base || 'szt', 1]);
            const gap = (unitPrice / factor - price) / price * 100;
            const odd = gap > 100 || gap < -60;
            box.innerHTML = `<div class="kw-price mt-1"><span>${esc(t('kw.per_unit', {u: unit}))}</span><b>≈ ${f2(unitPrice / factor * f)} zł/${unit}</b>
                <span>${esc(t('kw.base_price'))}</span><b>${f2(price * f)} zł/${unit}</b><span>${esc(t('kw.change'))}</span><b class="${gap > 0 ? 'kw-up' : 'kw-down'}">≈ ${pct(gap)}</b></div>`
                + (odd ? `<div class="kw-note bad">${esc(t('kw.odd_price', {p: pct(gap)}))}</div>` : '');
            // Podwyżka przewidziana (ponad próg supply.invoice_price_up_pct) : od razu « nowa cena / bez zmian ».
            const up = gap > (W.auto && W.auto.price_up_pct ? W.auto.price_up_pct : 0) + 0.05;
            p.querySelector('[data-kw-plain]')?.classList.toggle('d-none', up);
            p.querySelector('[data-kw-pair]')?.classList.toggle('d-none', !up);
            const btn = p.querySelector(up ? '[data-kw-pair] [data-kw-check="accept"]' : '[data-kw-plain] [data-kw-check]');
            if (btn) {
                btn.toggleAttribute('data-kw-odd', odd);
                btn.classList.toggle('pri', !odd);
                btn.querySelector('.kw-key')?.classList.toggle('d-none', odd);
            }
        }
        // Po wyborze podpowiedzi, jednostki albo surowca : fokus na « Zatwierdź » — Enter zatwierdza od razu.
        const focusCheck = (p) => { preview(p); [...p.querySelectorAll('[data-kw-check]')].find(b => !b.closest('.d-none'))?.focus({preventScroll: true}); };
        function skip() {
            const nx = nextTodo(cur);
            if (nx) { cur = key(nx.inv, nx.l); render(); }
        }

        host.addEventListener('click', async (e) => {
            const b = e.target.closest('button, [data-kw-open], [data-kw-line]');
            if (!b || !host.contains(b)) return;
            if (b.dataset.kwStep) return setStep(Number(b.dataset.kwStep));
            if (b.matches('[data-kw-back]')) return back();
            if (b.dataset.kwFilter) { filter = b.dataset.kwFilter; return render(); }
            if (b.dataset.kwOpen) {
                const inv = W.invoices.find(i => i.id === Number(b.dataset.kwOpen));
                const l = inv && (inv.lines.find(x => x.state === 'todo') || inv.lines.find(x => x.state === 'disputed') || inv.lines[0]);
                if (l) { cur = key(inv, l); filter = l.state === 'todo' ? 'todo' : 'all'; }
                return setStep(2);
            }
            if (b.dataset.kwGoto) {
                const inv = W.invoices.find(i => i.id === Number(b.dataset.kwGoto));
                const l = inv && (inv.lines.find(x => x.state === 'todo') || inv.lines.find(x => x.state === 'disputed'));
                if (l) { cur = key(inv, l); filter = 'all'; }
                return setStep(2);
            }
            if (b.dataset.kwLine && !e.target.closest('a, select, input')) {
                cur = cur === b.dataset.kwLine ? null : b.dataset.kwLine;
                return render();
            }
            if (b.dataset.kwSug) {
                const p = b.closest('[data-kw-panel]');
                const sel = p.querySelector('[data-kw-mat]');
                sel.value = b.dataset.kwSug;
                p.querySelectorAll('[data-kw-sug]').forEach(c => c.classList.toggle('on', c === b));
                const scale = Number(p.querySelector('[data-kw-unit].on')?.dataset.kwUnit || 1);
                if (b.dataset.factor) p.querySelector('[data-kw-factor]').value = String(Math.round(Number(b.dataset.factor) / scale * 10000) / 10000).replace('.', ',');
                return focusCheck(p);
            }
            if (b.dataset.kwUnit) {
                const p = b.closest('[data-kw-panel]');
                const was = Number(p.querySelector('[data-kw-unit].on')?.dataset.kwUnit || 1);
                const now = Number(b.dataset.kwUnit);
                const fIn = p.querySelector('[data-kw-factor]');
                const v = Number(fIn.value.replace(/\s/g, '').replace(',', '.'));
                if (fIn.value.trim() && isFinite(v)) fIn.value = String(Math.round(v * was / now * 10000) / 10000).replace('.', ',');
                p.querySelectorAll('[data-kw-unit]').forEach(c => c.classList.toggle('on', c === b));
                return focusCheck(p);
            }
            if (b.matches('[data-kw-check]')) {
                if (b.hasAttribute('data-kw-odd')) {
                    const x = find(cur);
                    const msg = t('kw.odd_confirm', {p: pct(x.l.control.catalog_gap_pct)});
                    const go = window.Swal ? (await Swal.fire({icon: 'warning', title: msg, showCancelButton: true, confirmButtonText: t('kw.act.accept'), cancelButtonText: t('act.cancel'), confirmButtonColor: '#8e1f2f'})).isConfirmed : window.confirm(msg);
                    if (!go) return;
                }
                return check(b.dataset.kwCheck || null);
            }
            if (b.matches('[data-kw-skip]')) return skip();
            if (b.matches('[data-kw-dispute]')) return dispute();
            if (b.matches('[data-kw-reset]')) {
                const x = find(cur);
                return act(() => api.post(`/supply/invoices/${x.inv.id}/lines/${x.l.id}/check`, {action: 'reset'}));
            }
            if (b.matches('[data-kw-safe]')) return act(() => api.post('/supply/invoices/wizard/check-safe', {}), (r) => t('kw.safe_done', {n: r.checked}));
            if (b.matches('[data-kw-sync]')) {
                b.disabled = true;
                b.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span>${esc(t('inv.ksef_syncing'))}`;
                try {
                    const res = await api.post('/supply/invoices/ksef/sync', {});
                    ui.notify(!res.errors.length, t('inv.ksef_synced', {n: res.imported.length, found: res.found, from: day(res.from), to: day(res.to)}));
                } catch (err) { ui.notify(false, errText(err)); }
                return load();
            }
            if (b.matches('[data-kw-process]')) {
                const ids = W.invoices.filter(i => i.wizard_ready && chosen.has(i.id)).map(i => i.id);
                if (!ids.length) return;
                const go = window.Swal ? (await Swal.fire({icon: 'question', title: t('kw.confirm_title', {n: ids.length}), text: t('kw.confirm_text'), showCancelButton: true,
                    confirmButtonText: t('kw.confirm_ok'), cancelButtonText: t('act.cancel'), confirmButtonColor: '#8e1f2f'})).isConfirmed : window.confirm(t('kw.confirm_title', {n: ids.length}));
                if (!go) return;
                const res = await act(() => api.post('/supply/invoices/wizard/process', {ids}));
                if (res) {
                    ui.notify(!res.errors.length, t('kw.processed', {n: res.processed.length}) + (res.errors.length ? ' ' + t('kw.process_errors', {list: res.errors.map(x => (x.number || '#' + x.id) + ' (' + x.code + ')').join(', ')}) : ''));
                }
            }
        });
        host.addEventListener('change', async (e) => {
            if (e.target.matches('[data-kw-pick]')) {
                const id = Number(e.target.dataset.kwPick);
                if (e.target.checked) { chosen.add(id); chosen.delete(-id); } else { chosen.delete(id); chosen.add(-id); }
                return render();
            }
            if (e.target.matches('[data-kw-supplier]') && e.target.value) {
                const id = Number(e.target.dataset.kwSupplier);
                await act(() => api.patch(`/supply/invoices/${id}`, {supplier_id: Number(e.target.value)}), t('kw.supplier_saved'));
                return load();
            }
            if (e.target.matches('[data-kw-mat]')) {
                const p = e.target.closest('[data-kw-panel]');
                p.querySelectorAll('[data-kw-sug]').forEach(c => c.classList.toggle('on', c.dataset.kwSug === e.target.value));
                const s = p.querySelector(`[data-kw-sug="${e.target.value}"]`);
                p.querySelector('[data-kw-factor]').value = s && s.dataset.factor ? String(Math.round(Number(s.dataset.factor) / Number(p.querySelector('[data-kw-unit].on')?.dataset.kwUnit || 1) * 10000) / 10000).replace('.', ',') : '';
                focusCheck(p);
            }
        });
        host.addEventListener('focusin', (e) => { if (e.target.matches('[data-kw-mat]')) ensureMaterials(); });
        host.addEventListener('input', (e) => { if (e.target.matches('[data-kw-factor]')) preview(e.target.closest('[data-kw-panel]')); });
        // Klawiatura (krok 2) : Enter — zatwierdź, K — cena bez zmian, → — pomiń ; nie w polach wyboru.
        const onKey = (e) => {
            if (!document.body.contains(host)) { document.removeEventListener('keydown', onKey); return; }
            if (step !== 2 || !cur || busy || e.ctrlKey || e.metaKey || e.altKey || document.querySelector('.modal.show, .swal2-container')) return;
            const tag = e.target.tagName;
            if (tag === 'SELECT' || tag === 'TEXTAREA' || (tag === 'INPUT' && e.key !== 'Enter') || (tag === 'BUTTON' && e.key === 'Enter')) return;
            const p = host.querySelector('[data-kw-panel]');
            if (!p) return;
            const shown = [...p.querySelectorAll('[data-kw-check]')].filter(b => !b.closest('.d-none'));
            if (e.key === 'Enter') { const b = shown.find(x => !x.hasAttribute('data-kw-odd') && x.dataset.kwCheck !== 'keep'); if (b) { e.preventDefault(); b.click(); } }
            else if (e.key === 'k' || e.key === 'K') { const b = shown.find(x => x.dataset.kwCheck === 'keep'); if (b) { e.preventDefault(); b.click(); } }
            else if (e.key === 'ArrowRight') { e.preventDefault(); skip(); }
        };
        document.addEventListener('keydown', onKey);
        render();
        load();
    }

    window.TfbInvoiceWizard = {mount};
})();
