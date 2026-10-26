/*
 * Plan zespołów / Plan des équipes : par équipe et par semaine de production — calendrier (blocs du tableau de la
 * semaine dans l'ordre des heures, effectif, heures), production (total, lots, heures, expédition / stock, jours,
 * reste non réparti) et arrivages de matières utiles à l'équipe (matières de ses recettes). PDF à imprimer, une
 * équipe par page A4 ; sur la tablette de l'équipe, le même plan (accueil › « Plan tygodnia » ou lien …/kiosk#week).
 * En bas, les listes du jour (PDF) d'après les jours d'expédition des commandes en attente : liste de production par
 * section, besoin en matières, liste des retours (ancienne page « Planuj produkcję », redirigée ici).
 */
(async function () {
    'use strict';
    const el = document.getElementById('tfbTeamSheet');
    if (!el) return;
    const {WorkshopApi, ui} = window.TfbWorkshop;
    const api = new WorkshopApi(el);
    const esc = ui.esc;
    try {
        await api.init();
    } catch (e) {
        el.innerHTML = `<div class="alert alert-danger">${esc(e.message)}</div>`;
        return;
    }
    const t = (k, p) => api.t(k, p);
    const n = (v, d) => v === null || v === undefined ? '—' : ui.fmt(v, d ?? 1, api.lang);
    const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    const todayIso = iso(new Date());
    const addDays = (s, k) => { const d = new Date(s + 'T12:00:00'); d.setDate(d.getDate() + k); return iso(d); };
    const day = (s) => s ? `${s.slice(8, 10)}.${s.slice(5, 7)}` : '—';
    const errText = (err) => ((err.body && err.body.errors) || []).map(x => x.message).join(' ') || err.message;
    // Ilość w jednostce wyświetlanej (kg dla g) : baza ÷ factor.
    const qty = (v, unit, factor) => `${n(v / (factor || 1), factor > 1 ? 2 : 1)} ${unit}`;
    const kioskUrl = () => new URL((el.dataset.root || '').replace(/\/$/, '') + '/kiosk#week', location.href).href;

    let state = readHash();
    let data = null;
    let tablet = false;
    // Listy dnia (PDF) : dni wysyłki z oczekującymi zamówieniami, wczytywane przy pierwszym otwarciu.
    const lists = {open: !!state.lists, days: null, max: 4, picked: new Set(), stockMin: false, complaints: null, planDay: null};

    // Adresse : #date=…&group=…&lists=… ; ?date=… (anciens liens « Planuj produkcję ») = jour d'expédition des listes.
    function readHash() {
        const p = new URLSearchParams(location.hash.replace(/^#/, ''));
        const old = new URLSearchParams(location.search).get('date');
        const date = p.get('date') || old || '';
        return {date: /^\d{4}-\d{2}-\d{2}$/.test(date) ? date : todayIso, group: Number(p.get('group')) || null, lists: p.get('lists') || old || null};
    }
    function writeHash() {
        const p = new URLSearchParams({date: state.date});
        if (state.group) p.set('group', state.group);
        if (lists.open) p.set('lists', '1');
        history.replaceState(null, '', location.pathname + '#' + p.toString());
    }

    const head = () => `<div class="tfb-head"><div><h3>${esc(t('title'))}</h3><p class="text-muted mb-0">${esc(t('subtitle'))}</p></div>
        <div class="tfb-head-actions"><button type="button" class="btn btn-outline-secondary btn-sm" data-lang-switch>${esc(t('lang_switch'))}</button></div></div>`;

    async function load() {
        writeHash();
        el.innerHTML = head() + `<p class="text-muted">${esc(t('loading'))}</p>`;
        try {
            data = await api.get(`/planning/team-sheet?date=${state.date}`);
        } catch (err) {
            el.innerHTML = head() + `<div class="alert alert-danger">${esc(errText(err))}</div>`;
            return;
        }
        if (state.group && !data.teams.some(x => x.id === state.group)) state.group = null;
        draw();
    }

    function draw() {
        const y = window.scrollY;
        writeHash();
        const teams = data.teams.filter(x => !state.group || x.id === state.group);
        el.innerHTML = head() + bar() + (tablet ? tabletCard() : '')
            + (teams.length ? teams.map(teamCard).join('') : `<div class="card"><div class="card-body text-muted">${esc(t('empty_week'))}</div></div>`)
            + listsCard();
        bind();
        window.scrollTo(0, y);
        if (lists.open && lists.days === null) loadLists();
    }

    // --- Listy dnia (PDF) -------------------------------------------------------------------------------------
    function listsCard() {
        return `<details class="card tfb-team-lists" data-lists ${lists.open ? 'open' : ''}><summary class="card-header"><i class="bi bi-file-earmark-pdf"></i>
                <strong>${esc(t('lists_title'))}</strong> <span class="small text-muted">${esc(t('lists_summary'))}</span></summary>
            <div class="card-body">${planDayBlock()}${lists.days === null ? `<p class="text-muted mb-0">${esc(t('loading'))}</p>` : listsBody()}</div></details>`;
    }

    // Lista dnia z planu tygodnia (P18) : te same bloki co tablet zespołu i zapotrzebowanie surowców dnia — jedna
    // odpowiedź na « co produkujemy ». Dawne listy z zamówień (dni wysyłki) zostają niżej.
    function planDayBlock() {
        const days = data.days || [];
        if (!days.length) return '';
        const weekday = (iso) => t('weekday_' + (new Date(iso + 'T12:00:00').getDay() || 7));
        const pick = days.some(d => d.date === lists.planDay) ? lists.planDay : (days.find(d => d.today) || days[0]).date;
        lists.planDay = pick;
        const blocks = (date) => data.teams.reduce((a, tm) => a + ((tm.days.find(x => x.date === date) || {blocks: []}).blocks.length), 0);
        return `<div class="tfb-plan-day mb-3"><p class="small mb-2"><strong>${esc(t('plan_day_title'))}</strong> — ${esc(t('plan_day_help'))}</p>
            <div class="tfb-list-days">${days.map(d => `<label class="tfb-list-day ${d.date === pick ? 'is-on' : ''}"><input class="form-check-input" type="radio" name="plan-day" data-plan-day="${esc(d.date)}" ${d.date === pick ? 'checked' : ''}>
                <span><strong>${esc(weekday(d.date))} ${esc(day(d.date))}</strong><small>${esc(t('plan_day_blocks', {n: blocks(d.date)}))}</small></span></label>`).join('')}</div>
            <button type="button" class="btn btn-sm btn-primary mt-2" data-plan-pdf ${blocks(pick) ? '' : 'disabled'}><i class="bi bi-list-check"></i> ${esc(t('plan_day_pdf'))}</button></div>
            <p class="small fw-semibold mb-1">${esc(t('lists_orders_title'))}</p>`;
    }

    async function planDayPdf(btn) {
        btn.disabled = true;
        try {
            const f = await api.get(`/planning/team-sheet/day/pdf?date=${lists.planDay}`);
            const bin = atob(f.content_base64);
            const bytes = new Uint8Array(bin.length);
            for (let k = 0; k < bin.length; k++) bytes[k] = bin.charCodeAt(k);
            download(new Blob([bytes], {type: 'application/pdf'}), f.filename);
            ui.notify(true, t('pdf_ready', {name: f.filename}));
        } catch (err) {
            ui.notify(false, errText(err));
        } finally {
            btn.disabled = false;
        }
    }

    function listsBody() {
        const weekday = (iso) => t('weekday_' + (new Date(iso + 'T12:00:00').getDay() || 7));
        const full = lists.picked.size >= lists.max;
        const days = lists.days.length ? `<p class="small mb-2">${esc(t('lists_help', {max: lists.max}))}</p>
            <div class="tfb-list-days">${lists.days.map(d => {
                const on = lists.picked.has(d.date);
                return `<label class="tfb-list-day ${on ? 'is-on' : ''} ${!on && full ? 'is-off' : ''}"><input class="form-check-input" type="checkbox" data-list-day="${esc(d.date)}" ${on ? 'checked' : ''} ${!on && full ? 'disabled' : ''}>
                    <span><strong>${esc(weekday(d.date))} ${esc(day(d.date))}</strong><small>${esc(t('lists_orders', {n: d.orders.length, kg: n(d.kg, 0)}))}</small></span></label>`;
            }).join('')}</div>
            <label class="form-check form-switch small mt-2"><input class="form-check-input" type="checkbox" data-list-stock ${lists.stockMin ? 'checked' : ''}> ${esc(t('lists_stock_min'))}</label>`
            : `<p class="text-muted mb-2">${esc(t('lists_none'))}</p>`;
        const none = !lists.picked.size;
        return days + `<div class="d-flex flex-wrap gap-2 mt-2">
                <button type="button" class="btn btn-sm btn-primary" data-list-pdf="production" ${none ? 'disabled' : ''}><i class="bi bi-list-check"></i> ${esc(t('list_production'))}</button>
                <button type="button" class="btn btn-sm btn-outline-primary" data-list-pdf="requirement" ${none ? 'disabled' : ''}><i class="bi bi-basket"></i> ${esc(t('list_requirement'))}</button>
                <button type="button" class="btn btn-sm btn-outline-primary" data-list-pdf="complaint"><i class="bi bi-arrow-counterclockwise"></i> ${esc(t('list_complaint'))}</button></div>
            <p class="small text-muted mt-2 mb-0">${lists.complaints === null ? '' : esc(lists.complaints ? t('lists_complaints', {n: lists.complaints}) : t('lists_no_complaints'))} ${esc(t('lists_safe'))}</p>`;
    }

    async function loadLists() {
        const root = (el.dataset.root || '').replace(/\/$/, '');
        try {
            const [d, c] = await Promise.all([
                fetch(root + '/ajax/production-plan/days', {credentials: 'same-origin', headers: {'Accept': 'application/json'}}).then(r => r.ok ? r.json() : Promise.reject(new Error(t('lists_error', {code: r.status})))),
                fetch(root + '/ajax/production-plan/complaints', {credentials: 'same-origin', headers: {'Accept': 'application/json'}}).then(r => r.ok ? r.json() : null).catch(() => null),
            ]);
            lists.days = d.days || [];
            lists.max = d.max_dates || 4;
            lists.complaints = c ? c.products : null;
            const wanted = lists.days.find(x => x.date === state.lists);
            if (!lists.picked.size && lists.days.length) lists.picked.add((wanted || lists.days[0]).date);
        } catch (err) {
            lists.days = [];
            ui.notify(false, err.message);
        }
        draw();
    }

    function download(blob, filename) {
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        window.setTimeout(() => URL.revokeObjectURL(url), 5000);
    }

    // PDF klasyczne (/ajax/production-list/*) : zamówienia wybranych dni wysyłki ; pierwszy dzień = dzień produkcji.
    async function listPdf(kind, btn) {
        const orders = lists.days.filter(d => lists.picked.has(d.date)).flatMap(d => d.orders);
        if (kind !== 'complaint' && !orders.length) { ui.notify(false, t('lists_pick')); return; }
        const stock = lists.stockMin ? 1 : 0;
        const [path, payload] = {
            production: ['/ajax/production-list/standard', {order_id: orders, stock_minimum: stock, production_list: 1}],
            requirement: ['/ajax/production-list/requirement/pdf', {order_id: orders, stock_minimum: stock}],
            complaint: ['/ajax/production-list/complaint', {}],
        }[kind];
        btn.disabled = true;
        try {
            const res = await fetch((el.dataset.root || '').replace(/\/$/, '') + path, {method: 'POST', credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/pdf'}, body: JSON.stringify(payload)});
            if (!res.ok || !(res.headers.get('Content-Type') || '').includes('pdf')) {
                let msg = '';
                try { msg = (await res.json()).message || ''; } catch (e) { /* treść nie-JSON */ }
                throw new Error(msg || t('lists_error', {code: res.status}));
            }
            const m = /filename="?([^";]+)"?/.exec(res.headers.get('Content-Disposition') || '');
            const name = m ? m[1] : kind + '.pdf';
            download(await res.blob(), name);
            ui.notify(true, t('pdf_ready', {name}));
        } catch (err) {
            ui.notify(false, err.message);
        } finally {
            btn.disabled = false;
        }
    }

    function bar() {
        const week = t('week', {week: data.iso_week, from: day(data.week_start), to: day(data.week_end)});
        const current = data.week_start <= todayIso && todayIso <= data.week_end;
        return `<div class="card tfb-supply-bar"><div class="card-body d-flex flex-wrap gap-2 align-items-center">
                <div class="btn-group btn-group-sm" role="group" aria-label="${esc(t('week_nav'))}">
                    <button type="button" class="btn btn-outline-primary" data-week="-7" title="${esc(t('previous'))}"><i class="bi bi-chevron-left"></i></button>
                    <span class="btn btn-outline-primary disabled tfb-team-week">${esc(week)}</span>
                    <button type="button" class="btn btn-outline-primary" data-week="7" title="${esc(t('next'))}"><i class="bi bi-chevron-right"></i></button></div>
                ${current ? '' : `<button type="button" class="btn btn-sm btn-link" data-week="0">${esc(t('this_week'))}</button>`}
                <div class="btn-group btn-group-sm flex-wrap" role="group" aria-label="${esc(t('teams'))}">
                    <button type="button" class="btn ${state.group ? 'btn-outline-primary' : 'btn-primary'}" data-group="">${esc(t('all_teams'))}</button>
                    ${data.teams.map(x => `<button type="button" class="btn ${state.group === x.id ? 'btn-primary' : 'btn-outline-primary'}" data-group="${x.id ?? ''}">${esc(x.name || t('no_group'))}</button>`).join('')}</div>
                <div class="ms-auto d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-sm btn-primary" data-pdf="${state.group ?? ''}" ${data.teams.length ? '' : 'disabled'}><i class="bi bi-printer"></i> ${esc(t(state.group ? 'pdf_team' : 'pdf_all'))}</button>
                    <button type="button" class="btn btn-sm ${tablet ? 'btn-secondary' : 'btn-outline-primary'}" data-tablet><i class="bi bi-tablet"></i> ${esc(t('tablet'))}</button>
                    <a class="btn btn-sm btn-outline-success" href="${esc(api.root)}/workshop/supply#tab=needs&date=${esc(data.week_start)}&weeks=1" title="${esc(t('supply_help'))}"><i class="bi bi-cart3"></i> ${esc(t('supply_next'))}</a></div></div></div>`;
    }

    function tabletCard() {
        const url = kioskUrl();
        return `<div class="card tfb-team-tablet"><div class="card-body">
            <h6 class="mb-1"><i class="bi bi-tablet"></i> ${esc(t('tablet_title'))}</h6>
            <p class="small mb-2">${esc(t('tablet_help'))}</p>
            <div class="input-group input-group-sm tfb-team-link"><input class="form-control" readonly value="${esc(url)}" data-kiosk-url aria-label="${esc(t('tablet_link'))}">
                <button type="button" class="btn btn-outline-primary" data-copy><i class="bi bi-clipboard"></i> ${esc(t('copy'))}</button>
                <a class="btn btn-outline-secondary" href="${esc(url)}" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> ${esc(t('open'))}</a></div>
            <p class="small text-muted mt-2 mb-0">${esc(t('tablet_note'))}</p></div></div>`;
    }

    function teamCard(tm) {
        const color = /^#[0-9a-f]{6}$/i.test(tm.color || '') ? tm.color : '#8d1d2c';
        const block = (b) => `<div class="tfb-team-block ${b.late ? 'is-late' : ''}">${b.time ? `<span class="tfb-team-time">${esc(b.time)}</span> ` : ''}<strong>${esc(b.name)}</strong>
            <div class="small">${esc(qty(b.quantity, b.unit, b.factor))}${b.cartons ? ' · ' + esc(n(b.cartons, 1)) + ' ' + esc(t('cartons')) : ''}${b.batches ? ' · ' + esc(n(b.batches, 2)) + ' ' + esc(t('batches')) : ''} · ${esc(n(b.hours, 1))} h</div>
            ${b.late ? `<span class="badge bg-danger">${esc(t('late'))}</span>` : ''}</div>`;
        const cal = `<div class="tfb-team-cal" style="grid-template-columns:repeat(${tm.days.length}, minmax(130px, 1fr))">${tm.days.map(d => `<div class="tfb-team-day ${d.today ? 'is-today' : ''} ${d.past ? 'is-past' : ''}">
                <div class="tfb-team-day-head"><strong>${esc(t('weekday_' + d.weekday))} ${esc(day(d.date))}</strong>
                    <span class="small text-muted">${d.available_h > 0 || d.required_h > 0 ? esc(t('day_hours', {people: d.people, required: n(d.required_h, 1), available: n(d.available_h, 1)})) : esc(t('no_shift'))}</span></div>
                ${d.blocks.map(block).join('')}
                ${d.deliveries.map(x => `<div class="tfb-team-delivery"><i class="bi bi-truck"></i> <strong>${esc(x.supplier)}</strong><div class="small">${esc(x.materials.join(', '))}</div></div>`).join('')}
                ${d.blocks.length || d.deliveries.length ? '' : '<span class="small text-muted">—</span>'}</div>`).join('')}</div>`;
        const products = tm.products.length ? `<div class="table-responsive"><table class="table table-sm align-middle mb-0 tfb-team-table">
                <thead><tr><th>${esc(t('col_product'))}</th><th class="text-end">${esc(t('col_total'))}</th><th class="text-end">${esc(t('batches'))}</th><th class="text-end">h</th>
                    <th class="text-end">${esc(t('col_ship'))}</th><th class="text-end">${esc(t('col_stock'))}</th>
                    ${tm.days.map(d => `<th class="text-center ${d.today ? 'is-today' : ''}">${esc(t('weekday_' + d.weekday))}<div class="small">${esc(day(d.date))}</div></th>`).join('')}</tr></thead>
                <tbody>${tm.products.map(r => `<tr><td><strong>${esc(r.name)}</strong>${r.unassigned_qty > 0 ? `<div class="small text-danger">${esc(t('waiting', {qty: qty(r.unassigned_qty, r.unit, r.factor)}))}</div>` : ''}
                    ${Math.abs(r.need - r.quantity) > 0.5 ? `<div class="small text-muted" title="${esc(t('need_help'))}">${esc(t('need', {qty: qty(r.need, r.unit, r.factor)}))}</div>` : ''}</td>
                    <td class="text-end text-nowrap">${esc(qty(r.quantity, r.unit, r.factor))}${r.cartons ? `<div class="small text-muted">${esc(n(r.cartons, 1))} ${esc(t('cartons'))}</div>` : ''}</td>
                    <td class="text-end">${r.batches ? esc(n(r.batches, 2)) : '—'}</td><td class="text-end">${esc(n(r.hours, 1))}</td>
                    <td class="text-end text-nowrap">${r.direct > 0 ? esc(qty(r.direct, r.unit, r.factor)) + (r.due_date ? `<div class="small text-muted">${esc(t('due', {day: day(r.due_date)}))}</div>` : '') : '—'}</td>
                    <td class="text-end text-nowrap">${r.stock > 0 ? esc(qty(r.stock, r.unit, r.factor)) : '—'}</td>
                    ${tm.days.map(d => { const x = r.days[d.date]; return `<td class="text-center text-nowrap ${d.today ? 'is-today' : ''}">${x ? (x.time ? `<div class="small text-muted">${esc(x.time)}</div>` : '') + esc(n(x.quantity / (r.factor || 1), r.factor > 1 ? 2 : 1)) : ''}</td>`; }).join('')}</tr>`).join('')}</tbody></table></div>`
            : `<p class="text-muted mb-0">${esc(t('empty_team'))}</p>`;
        const deliveries = tm.deliveries.length ? `<h6 class="tfb-team-title mt-3">${esc(t('deliveries'))}</h6><div class="table-responsive"><table class="table table-sm align-middle mb-0 tfb-team-table">
                <thead><tr><th>${esc(t('col_date'))}</th><th>${esc(t('col_supplier'))}</th><th>${esc(t('col_material'))}</th><th class="text-end">${esc(t('col_quantity'))}</th>
                    <th class="text-end">${esc(t('col_need'))}</th><th>${esc(t('col_for'))}</th></tr></thead>
                <tbody>${tm.deliveries.map(o => o.lines.map((l, k) => {
                    const state2 = l.received >= l.quantity - 0.0005 ? 'received' : (l.received > 0 ? 'partial' : 'expected');
                    return `<tr>${k === 0 ? `<td rowspan="${o.lines.length}">${o.date ? esc(t('weekday_' + (new Date(o.date + 'T12:00:00').getDay() || 7))) + ' ' + esc(day(o.date)) : esc(t('undated'))}</td>
                        <td rowspan="${o.lines.length}">${esc(o.supplier)}<div class="small text-muted">${esc(o.code)}</div></td>` : ''}
                        <td>${esc(l.name)}</td><td class="text-end text-nowrap">${esc(qty(l.quantity, l.unit, l.factor))}<div class="small ${state2 === 'received' ? 'text-success' : 'text-muted'}">${esc(t('delivery_' + state2))}</div></td>
                        <td class="text-end text-nowrap">${esc(qty(l.need, l.unit, l.factor))}</td><td class="small">${esc(l.products.slice(0, 4).join(', '))}${l.products.length > 4 ? ' …' : ''}</td></tr>`;
                }).join('')).join('')}</tbody></table></div>` : '';
        return `<div class="card tfb-team" style="--team:${color}"><div class="card-header d-flex flex-wrap align-items-center gap-2">
                <span class="tfb-team-dot" aria-hidden="true"></span><h5 class="mb-0">${esc(tm.name || t('no_group'))}</h5>
                <span class="small text-muted">${esc(t('team_hours', {people: tm.people, hours: n(tm.hours, 1), available: n(tm.available_h, 1)}))}</span>
                ${tm.unassigned ? `<span class="badge bg-warning text-dark" title="${esc(t('unassigned_help'))}">${esc(t('unassigned', {n: tm.unassigned}))}</span>` : ''}
                <button type="button" class="btn btn-sm btn-outline-primary ms-auto" data-pdf="${tm.id ?? ''}"><i class="bi bi-printer"></i> ${esc(t('pdf_team'))}</button></div>
            <div class="card-body"><h6 class="tfb-team-title">${esc(t('calendar'))}</h6>${cal}
                <h6 class="tfb-team-title mt-3">${esc(t('production'))}</h6>${products}${deliveries}</div></div>`;
    }

    // PDF : le serveur le produit (A4 paysage, une équipe par page) dans la langue de la page.
    async function pdf(groupId, btn) {
        btn.disabled = true;
        try {
            const f = await api.get(`/planning/team-sheet/pdf?date=${state.date}${groupId ? '&group_id=' + groupId : ''}`);
            const bin = atob(f.content_base64);
            const bytes = new Uint8Array(bin.length);
            for (let k = 0; k < bin.length; k++) bytes[k] = bin.charCodeAt(k);
            download(new Blob([bytes], {type: 'application/pdf'}), f.filename);
            ui.notify(true, t('pdf_ready', {name: f.filename}));
        } catch (err) {
            ui.notify(false, errText(err));
        } finally {
            btn.disabled = false;
        }
    }

    function bind() {
        el.querySelectorAll('[data-week]').forEach(b => b.addEventListener('click', () => {
            const k = Number(b.dataset.week);
            state.date = k === 0 ? todayIso : addDays(data.week_start, k);
            load();
        }));
        el.querySelectorAll('[data-group]').forEach(b => b.addEventListener('click', () => { state.group = Number(b.dataset.group) || null; draw(); }));
        el.querySelectorAll('[data-pdf]').forEach(b => b.addEventListener('click', () => pdf(Number(b.dataset.pdf) || null, b)));
        el.querySelector('[data-tablet]')?.addEventListener('click', () => { tablet = !tablet; draw(); });
        el.querySelector('[data-copy]')?.addEventListener('click', async () => {
            const input = el.querySelector('[data-kiosk-url]');
            try { await navigator.clipboard.writeText(input.value); } catch (e) { input.select(); document.execCommand('copy'); }
            ui.notify(true, t('copied'));
        });
        el.querySelector('[data-lists]').addEventListener('toggle', (e) => {
            if (lists.open === e.target.open) return;
            lists.open = e.target.open;
            writeHash();
            if (lists.open && lists.days === null) loadLists();
        });
        el.querySelectorAll('[data-list-day]').forEach(c => c.addEventListener('change', () => {
            if (c.checked) lists.picked.add(c.dataset.listDay); else lists.picked.delete(c.dataset.listDay);
            draw();
        }));
        el.querySelector('[data-list-stock]')?.addEventListener('change', (e) => { lists.stockMin = e.target.checked; });
        el.querySelectorAll('[data-list-pdf]').forEach(b => b.addEventListener('click', () => listPdf(b.dataset.listPdf, b)));
        el.querySelectorAll('[data-plan-day]').forEach(c => c.addEventListener('change', () => { lists.planDay = c.dataset.planDay; draw(); }));
        el.querySelector('[data-plan-pdf]')?.addEventListener('click', (e) => planDayPdf(e.currentTarget));
    }

    el.addEventListener('click', async (e) => {
        if (!e.target.closest('[data-lang-switch]')) return;
        await api.setLang(api.lang === 'pl' ? 'fr' : 'pl');
        load();
    });
    load();
})();
