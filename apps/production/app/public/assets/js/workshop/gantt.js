/*
 * Gantt pracowni / Gantt de l'atelier (P5) : opérations planifiées par groupe et par poste,
 * charge des équipes par shift, déplacement (glisser ou fiche) et verrouillage.
 * Lecture : GET /planning/operations et /planning/load ; écriture : PATCH /planning/operations/{id}
 * (prod.gantt.move — le serveur refuse tout ce qui casse une règle : échéance camion, ordre des
 * étapes, shift sans monde, poste occupé, chambre pleine).
 * Vue « Tydzień » : production à la semaine, une ligne par groupe, un bloc = un produit sur un jour
 * (GET /planning/week) ; glisser un produit sur un autre jour ou dans « Do rozplanowania »
 * (POST /planning/week/move), « Rozdziel na dni » (POST /planning/week/distribute).
 */
(async function () {
    'use strict';
    const el = document.getElementById('tfbGantt');
    if (!el) return;
    const {WorkshopApi, ui} = window.TfbWorkshop;
    const api = new WorkshopApi(el);
    const esc = ui.esc;
    const VIEWS = {day: {days: 1, pph: 64, tick: 1}, week: {days: 7, pph: 10, tick: 6}};
    const SNAP_MIN = 5;
    let groups = [];
    let workstations = [];
    let canMove = false;
    let state = readHash();
    let ops = [];

    try {
        await api.init();
        const [g, w, me] = await Promise.all([api.list('/referentiels/groups'), api.list('/referentiels/workstations'), api.get('/auth/me')]);
        groups = g.data.filter(x => x.active);
        workstations = w.data.filter(x => x.active);
        canMove = me.permissions.includes('prod.gantt.move');
    } catch (e) {
        el.innerHTML = `<div class="alert alert-danger">${esc(e.message)}</div>`;
        return;
    }
    const t = (k, p) => api.t(k, p);
    const fmt = (n, d) => ui.fmt(n, d ?? 1, api.lang);
    const iso = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    const parse = (s) => new Date(String(s).replace(' ', 'T'));
    const local = (d) => `${iso(d)} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
    const hhmm = (s) => String(s).slice(11, 16);

    function readHash() {
        const p = new URLSearchParams(location.hash.replace(/^#\/?\??/, ''));
        const from = /^\d{4}-\d{2}-\d{2}$/.test(p.get('from') || '') ? p.get('from') : null;
        return {from: from || null, view: VIEWS[p.get('view')] ? p.get('view') : 'week', group: Number(p.get('group')) || 0};
    }
    function writeHash() {
        const p = new URLSearchParams({from: state.from, view: state.view});
        if (state.group) p.set('group', state.group);
        history.replaceState(null, '', '#' + p.toString());
    }

    function header() {
        const isWeek = state.view === 'week';
        return `<div class="tfb-head"><div><h3>${esc(t(isWeek ? 'week.page_title' : 'title'))}</h3><p class="text-muted mb-0">${esc(t(isWeek ? 'week.page_subtitle' : 'subtitle'))}</p></div>
            <div class="tfb-head-actions"><button type="button" class="btn btn-outline-secondary btn-sm" data-lang-switch>${esc(t('lang_switch'))}</button></div></div>`;
    }

    function filters() {
        return `<form class="tfb-filters tfb-gantt-filters" data-filters>
                <div class="btn-group btn-group-sm" role="group" aria-label="${esc(t('period'))}">
                    <button type="button" class="btn btn-outline-primary" data-shift="-1" aria-label="${esc(t('previous'))}">‹</button>
                    <button type="button" class="btn btn-outline-primary" data-today>${esc(t('today'))}</button>
                    <button type="button" class="btn btn-outline-primary" data-shift="1" aria-label="${esc(t('next'))}">›</button>
                </div>
                <label class="tfb-inline">${esc(t('from'))} <input type="date" class="form-control form-control-sm" name="from" value="${state.from}"></label>
                <select class="form-select form-select-sm" name="view" aria-label="${esc(t('view'))}">${Object.keys(VIEWS).map(v => `<option value="${v}" ${v === state.view ? 'selected' : ''}>${esc(t('view.' + v))}</option>`).join('')}</select>
                <select class="form-select form-select-sm" name="group" aria-label="${esc(t('group'))}"><option value="0">${esc(t('all_groups'))}</option>${groups.map(g => `<option value="${g.id}" ${g.id === state.group ? 'selected' : ''}>${esc(g.name)}</option>`).join('')}</select>
            </form>`;
    }

    async function render() {
        state.from ??= iso(new Date());
        if (state.view === 'week') return renderWeek();
        writeHash();
        const view = VIEWS[state.view];
        const start = parse(state.from + ' 00:00:00');
        const to = iso(new Date(start.getTime() + (view.days - 1) * 86400000));
        el.innerHTML = header() + filters() + `
            <ul class="tfb-gantt-legend">
                <li><span class="tfb-bar-sample is-MTO"></span>${esc(t('origin.MTO'))}</li><li><span class="tfb-bar-sample is-MTS"></span>${esc(t('origin.MTS'))}</li>
                <li><span class="tfb-bar-sample is-SEMI"></span>${esc(t('origin.SEMI'))}</li><li><span class="tfb-bar-sample is-cleaning"></span>${esc(t('kind.cleaning'))}</li>
                <li><span class="tfb-bar-sample is-delay"></span>${esc(t('kind.delay'))}</li><li><i class="bi bi-lock-fill"></i> ${esc(t('locked'))}</li>
                <li><span class="tfb-load-sample is-under"></span>${esc(t('load.under'))}</li><li><span class="tfb-load-sample is-ok"></span>${esc(t('load.ok'))}</li><li><span class="tfb-load-sample is-over"></span>${esc(t('load.over'))}</li>
            </ul>
            <div class="card"><div class="card-body p-0" data-chart><p class="text-muted p-3 mb-0">${esc(t('loading'))}</p></div></div>
            <div data-detail></div>`;
        bindFilters();
        try {
            const query = `from=${state.from}&to=${to}`;
            const [o, l] = await Promise.all([
                api.list(`/planning/operations?${query}${state.group ? '&group_id=' + state.group : ''}`),
                api.list(`/planning/load?${query}`),
            ]);
            ops = o.data;
            el.querySelector('[data-chart]').innerHTML = chart(start, view, ops, l.data);
            bindChart(start, view);
        } catch (err) {
            el.querySelector('[data-chart]').innerHTML = `<div class="alert alert-danger m-3">${esc(err.message)}</div>`;
        }
    }

    function bindFilters() {
        const form = el.querySelector('[data-filters]');
        form.addEventListener('change', () => {
            state = {from: form.from.value || state.from, view: form.view.value, group: Number(form.group.value) || 0};
            render();
        });
        form.querySelectorAll('[data-shift]').forEach(b => b.addEventListener('click', () => {
            const d = parse(state.from + ' 00:00:00');
            d.setDate(d.getDate() + Number(b.dataset.shift) * VIEWS[state.view].days);
            state.from = iso(d);
            render();
        }));
        form.querySelector('[data-today]').addEventListener('click', () => { state.from = iso(new Date()); render(); });
    }

    function chart(start, view, list, load) {
        const hours = view.days * 24;
        const width = hours * view.pph;
        const x = (s) => (parse(s) - start) / 3600000 * view.pph;
        const shown = groups.filter(g => !state.group || g.id === state.group);
        let ticks = '';
        for (let h = 0; h < hours; h += view.tick) {
            const d = new Date(start.getTime() + h * 3600000);
            const day = d.getHours() === 0;
            ticks += `<span class="tfb-tick ${day ? 'is-day' : ''}" style="left:${h * view.pph}px">${day ? esc(d.toLocaleDateString(api.lang === 'fr' ? 'fr-FR' : 'pl-PL', {weekday: 'short', day: '2-digit', month: '2-digit'})) : String(d.getHours()).padStart(2, '0') + ':00'}</span>`;
        }
        const now = (Date.now() - start) / 3600000 * view.pph;
        const nowLine = now > 0 && now < width ? `<span class="tfb-now" style="left:${now}px" title="${esc(t('now'))}"></span>` : '';
        const rows = shown.map(g => {
            const wsList = workstations.filter(w => w.group_id === g.id);
            const groupOps = list.filter(o => o.group_id === g.id);
            if (!wsList.length && !groupOps.length) return '';
            const cells = load.filter(c => c.group_id === g.id).map(c => {
                const left = Math.max(0, x(c.start));
                const right = Math.min(width, x(c.end));
                if (right <= left) return '';
                const label = c.load_pct === null ? t('load.no_team') : `${fmt(c.load_pct, 0)} %`;
                return `<span class="tfb-load is-${c.status}" style="left:${left}px;width:${right - left}px" title="${esc(t('load_title', {shift: c.shift, date: c.date, required: fmt(c.required_h, 1), available: fmt(c.available_h, 1), people: c.headcount}))}">${esc(c.shift)} ${esc(label)}</span>`;
            }).join('');
            return `<div class="tfb-gantt-group" style="--group-color:${esc(g.color || '#8D1D2C')}">
                <div class="tfb-gantt-label is-group">${esc(g.name)}</div><div class="tfb-gantt-lane is-group" style="width:${width}px">${cells}${nowLine}</div></div>`
                + wsList.map(w => `<div class="tfb-gantt-row" data-ws="${w.id}" data-group="${g.id}">
                    <div class="tfb-gantt-label" title="${esc(w.name)}"><strong>${esc(w.code)}</strong> <span class="text-muted">${esc(w.name)}</span></div>
                    <div class="tfb-gantt-lane" style="width:${width}px">${groupOps.filter(o => o.workstation_id === w.id).map(o => bar(o, x)).join('')}${nowLine}</div></div>`).join('');
        }).join('');
        if (!rows) return `<p class="text-muted p-3 mb-0">${esc(t('empty'))}</p>`;
        return `<div class="tfb-gantt" data-gantt><div class="tfb-gantt-row is-axis"><div class="tfb-gantt-label"></div><div class="tfb-gantt-lane is-axis" style="width:${width}px">${ticks}</div></div>${rows}</div>
            ${list.length ? '' : `<p class="text-muted px-3 pb-3 mb-0">${esc(t('no_operations'))}</p>`}`;
    }

    function bar(o, x) {
        const left = x(o.start);
        const width = Math.max(3, x(o.end) - left);
        const late = o.due_at && o.end > o.due_at;
        const movable = canMove && o.status === 'planned';
        const label = o.kind === 'work' ? `${o.order_code} · ${o.item}` : (o.kind === 'cleaning' ? t('kind.cleaning') : `${t('kind.delay')} · ${o.item}`);
        const title = `${o.order_code} · ${o.item} (${fmt(o.quantity, o.unit === 'kg' ? 1 : 0)} ${o.unit})\n${o.step_no ? o.step_no + '. ' : ''}${o.step || t('kind.' + o.kind)} · ${o.workstation}\n${hhmm(o.start)}–${hhmm(o.end)} · ${t('due')} ${String(o.due_at || '').slice(0, 16)}${o.team ? '\n' + t('team') + ' ' + o.team : ''}`;
        return `<button type="button" class="tfb-bar is-${esc(o.origin)} is-${esc(o.kind)} ${o.locked ? 'is-locked' : ''} ${late ? 'is-late' : ''} ${movable ? 'is-movable' : ''}"
            style="left:${left}px;width:${width}px" data-op="${o.id}" title="${esc(title)}" aria-label="${esc(title.replace(/\n/g, ' · '))}">
            ${o.locked ? '<i class="bi bi-lock-fill"></i>' : ''}<span>${esc(label)}</span></button>`;
    }

    function bindChart(start, view) {
        const gantt = el.querySelector('[data-gantt]');
        if (!gantt) return;
        let drag = null;
        let dragged = false;
        gantt.addEventListener('click', (e) => {
            const b = e.target.closest('.tfb-bar');
            if (!b) return;
            if (dragged) { dragged = false; return; }
            openDetail(Number(b.dataset.op));
        });
        gantt.addEventListener('pointerdown', (e) => {
            const b = e.target.closest('.tfb-bar.is-movable');
            if (!b || e.button !== 0) return;
            drag = {bar: b, x0: e.clientX, y0: e.clientY, left0: parseFloat(b.style.left), moved: false, row: b.closest('.tfb-gantt-row')};
            b.setPointerCapture(e.pointerId);
        });
        let lastPoint = {x: 0, y: 0};
        gantt.addEventListener('pointermove', (e) => {
            if (!drag) return;
            lastPoint = {x: e.clientX, y: e.clientY};
            const dx = e.clientX - drag.x0;
            if (!drag.moved && Math.abs(dx) < 4 && Math.abs(e.clientY - drag.y0) < 4) return;
            drag.moved = true;
            const step = SNAP_MIN / 60 * view.pph;
            drag.bar.style.left = `${drag.left0 + Math.round(dx / step) * step}px`;
            drag.bar.classList.add('is-dragging');
            gantt.querySelectorAll('.tfb-gantt-row.is-target').forEach(r => r.classList.remove('is-target'));
            const over = document.elementsFromPoint(e.clientX, e.clientY).map(n => n.closest && n.closest('.tfb-gantt-row[data-ws]')).find(Boolean);
            if (over && over !== drag.row && over.dataset.group === drag.row.dataset.group) over.classList.add('is-target');
        });
        const finish = async (e) => {
            if (!drag) return;
            const d = drag;
            drag = null;
            d.bar.classList.remove('is-dragging');
            const target = gantt.querySelector('.tfb-gantt-row.is-target');
            gantt.querySelectorAll('.tfb-gantt-row.is-target').forEach(r => r.classList.remove('is-target'));
            if (!d.moved) return;
            dragged = true;
            const op = ops.find(o => o.id === Number(d.bar.dataset.op));
            const minutes = Math.round((parseFloat(d.bar.style.left) - d.left0) / view.pph * 60 / SNAP_MIN) * SNAP_MIN;
            const body = {};
            if (minutes) body.start = local(new Date(parse(op.start).getTime() + minutes * 60000));
            if (target) body.workstation_id = Number(target.dataset.ws);
            if (!Object.keys(body).length) { d.bar.style.left = `${d.left0}px`; return; }
            await save(op, body, () => { d.bar.style.left = `${d.left0}px`; });
        };
        gantt.addEventListener('pointerup', finish);
        gantt.addEventListener('pointercancel', () => { if (drag) { drag.bar.style.left = `${drag.left0}px`; drag.bar.classList.remove('is-dragging'); drag = null; } });
        // Clavier et tablette : une barre est un bouton, Entrée / un appui ouvre la fiche (le glisser n'est qu'un raccourci).
    }

    async function save(op, body, revert, container) {
        try {
            await api.patch(`/planning/operations/${op.id}`, body);
            ui.notify(true, t('saved'));
            render();
            return true;
        } catch (err) {
            if (revert) revert();
            const orphans = container ? ui.fieldErrors(container, err) : [];
            const messages = container ? orphans : ((err.body && err.body.errors) || []).map(x => x.message);
            if (!container || messages.length) ui.notify(false, messages.length ? messages.join(' ') : err.message);
            return false;
        }
    }

    function openDetail(id) {
        const o = ops.find(x => x.id === id);
        const box = el.querySelector('[data-detail]');
        if (!o) return;
        const movable = canMove && o.status === 'planned';
        const sameGroup = workstations.filter(w => w.group_id === o.group_id && w.type === o.workstation_type);
        box.innerHTML = `<div class="card tfb-gantt-detail"><div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h4 class="card-title mb-0">${esc(o.order_code)} · ${esc(o.item)}</h4>
                <button type="button" class="btn-close" data-close aria-label="${esc(t('close'))}"></button></div>
            <div class="card-body pt-0">
                <dl class="tfb-dl">
                    <dt>${esc(t('step'))}</dt><dd>${o.step_no ? o.step_no + '. ' : ''}${esc(o.step || t('kind.' + o.kind))}</dd>
                    <dt>${esc(t('quantity'))}</dt><dd>${fmt(o.quantity, o.unit === 'kg' ? 1 : 0)} ${esc(o.unit)} · ${esc(t('origin.' + o.origin))}</dd>
                    <dt>${esc(t('when'))}</dt><dd>${esc(o.start.slice(0, 16))} → ${esc(hhmm(o.end))}</dd>
                    <dt>${esc(t('due_title'))}</dt><dd>${esc(String(o.due_at || '').slice(0, 16))}</dd>
                    <dt>${esc(t('workstation'))}</dt><dd>${esc(o.workstation)} · ${esc(o.workstation_name)}</dd>
                    <dt>${esc(t('team_title'))}</dt><dd>${esc(o.team || '—')} · ${fmt(o.operators, 1)} ${esc(t('people'))}</dd>
                    <dt>${esc(t('status'))}</dt><dd>${esc(t('status.' + o.status))}${o.locked ? ' · <i class="bi bi-lock-fill"></i> ' + esc(t('locked')) : ''}</dd>
                </dl>
                ${movable ? `<form class="tfb-form tfb-gantt-move" data-move>
                    <label>${esc(t('start'))}<input type="datetime-local" class="form-control" name="start" data-field="start" step="60" value="${esc(o.start.slice(0, 16).replace(' ', 'T'))}" required></label>
                    <label>${esc(t('workstation'))}<select class="form-select" name="workstation_id" data-field="workstation_id">${sameGroup.map(w => `<option value="${w.id}" ${w.id === o.workstation_id ? 'selected' : ''}>${esc(w.code)} · ${esc(w.name)}</option>`).join('')}</select></label>
                    <label class="form-check tfb-check"><input type="checkbox" class="form-check-input" name="locked" data-field="locked" ${o.locked ? 'checked' : ''}> ${esc(t('lock_hint'))}</label>
                    <div class="tfb-nav"><span class="text-muted small">${esc(t('rules'))}</span><button class="btn btn-primary">${esc(t('save'))}</button></div>
                </form>` : (canMove ? `<p class="text-muted mb-0">${esc(t('not_movable'))}</p>` : `<p class="text-muted mb-0">${esc(t('read_only'))}</p>`)}
            </div></div>`;
        box.querySelector('[data-close]').addEventListener('click', () => { box.innerHTML = ''; });
        const form = box.querySelector('[data-move]');
        if (form) form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const body = {};
            const start = form.start.value.replace('T', ' ');
            if (start !== o.start.slice(0, 16)) body.start = start;
            if (Number(form.workstation_id.value) !== o.workstation_id) body.workstation_id = Number(form.workstation_id.value);
            if (form.locked.checked !== o.locked && !body.start && !body.workstation_id) body.locked = form.locked.checked;
            if (!Object.keys(body).length) { box.innerHTML = ''; return; }
            const ok = await save(o, body, null, form);
            if (ok && body.start && !form.locked.checked) await api.patch(`/planning/operations/${o.id}`, {locked: false}).then(render).catch(() => {});
        });
        box.scrollIntoView({behavior: 'smooth', block: 'nearest'});
    }

    // --- Vue « Tydzień » : produits de la semaine sur les jours, par groupe -------------------------
    let week = null;
    const dayLabel = (s, long) => parse(s + ' 00:00:00').toLocaleDateString(api.lang === 'fr' ? 'fr-FR' : 'pl-PL', long ? {weekday: 'long', day: '2-digit', month: '2-digit'} : {weekday: 'short', day: '2-digit', month: '2-digit'});
    const hrs = (n) => `${fmt(n, 1)} h`;
    // Partia produkcyjna (karta produktu, krok « Etapy i czasy ») : liczba partii, z ułamkiem tylko gdy niepełna.
    const batchText = (p, q) => {
        const n = q / p.batch;
        return t('week.batches', {n: fmt(n, Math.abs(n - Math.round(n)) < 0.01 ? 0 : 1)});
    };
    const qtyText = (p, q) => (p.ppc ? `${fmt(q, 0)} ${p.unit} · ${fmt(q / p.ppc, q / p.ppc >= 10 ? 0 : 1)} ${t('week.cartons')}` : `${fmt(q, p.unit === 'kg' ? 1 : 0)} ${p.unit}`)
        + (p.batch ? ` · ${batchText(p, q)}` : '');

    async function renderWeek() {
        el.innerHTML = header() + filters() + `<div data-week-root><p class="text-muted">${esc(t('loading'))}</p></div><div data-detail></div>`;
        bindFilters();
        try {
            week = await api.get(`/planning/week?date=${state.from}`);
        } catch (err) {
            el.querySelector('[data-week-root]').innerHTML = `<div class="alert alert-danger">${esc(err.message)}</div>`;
            return;
        }
        // Tydzień produkcji od dnia z parametru week.start_day (w pracowni środa) : serwer zwraca jego początek.
        if (state.from !== week.week_start) {
            state.from = week.week_start;
            writeHash();
            const input = el.querySelector('input[name=from]');
            if (input) input.value = state.from;
        }
        drawWeek();
    }

    // « Do zrobienia » produktu : to, co jeszcze nie jest na dniach — najpierw wysyłka (przydziały pokrywają
    // najpierw zamówienia), reszta to uzupełnienie magazynu.
    function waitingParts(p) {
        const assigned = p.slots.reduce((a, s) => a + s.quantity, 0);
        const direct = Math.max(0, p.direct - assigned);
        return {direct: Math.min(direct, p.unassigned_qty), stock: Math.max(0, p.unassigned_qty - direct)};
    }
    const cartonsText = (p, q) => p.ppc ? `${fmt(q / p.ppc, q / p.ppc >= 10 ? 0 : 1)} ${t('week.cart')}` : `${fmt(q, p.unit === 'kg' ? 1 : 0)} ${p.unit}`;
    const partText = (p, q) => p.batch ? `${batchText(p, q)} · ${cartonsText(p, q)}` : cartonsText(p, q);

    // Jedna kafelka na partię produkcyjną : pełne partie + ewentualna reszta (niepełna partia). Przy wielu partiach
    // jednego produktu w jednym miejscu ostatnia kafelka zbiera resztę. Numeracja w tygodniu produktu : dni po
    // kolei, potem « Do zrobienia » (offset = ilość przed kafelkami).
    const MAX_TILES = 6;
    function batchTiles(p, qty, offset) {
        if (!p.batch || qty <= 0.0005) return [{qty, label: null}];
        const n = Math.floor(qty / p.batch + 1e-6);
        const parts = Array(n).fill(p.batch);
        const rest = Math.round((qty - n * p.batch) * 1000) / 1000;
        if (rest > 0.0005) parts.push(rest);
        if (parts.length > MAX_TILES) parts.push(Math.round(parts.splice(MAX_TILES - 1).reduce((a, b) => a + b, 0) * 1000) / 1000);
        const total = Math.max(1, Math.ceil(p.quantity / p.batch - 1e-6));
        let a = offset;
        return parts.map(q => {
            const first = Math.floor(a / p.batch + 1e-6) + 1;
            const last = Math.max(first, Math.ceil((a + q) / p.batch - 1e-6));
            a += q;
            return {qty: q, label: `${first === last ? first : first + '–' + last}/${total}`, partial: q < p.batch - 0.0005 && first === last};
        });
    }
    const slotOffset = (p, day) => p.slots.filter(x => x.day < day).reduce((a, x) => a + x.quantity, 0);

    function drawWeek() {
        const root = el.querySelector('[data-week-root]');
        if (!root || !week) return;
        const w = week;
        const tot = w.totals;
        const worst = w.groups.filter(g => g.load_pct !== null).sort((a, b) => b.load_pct - a.load_pct)[0];
        const plan = w.source.plans[0];
        const source = w.source.type === 'plan'
            ? t('week.source_plan', {version: plan.version, from: plan.period_start.slice(8, 10) + '.' + plan.period_start.slice(5, 7), to: plan.period_end.slice(8, 10) + '.' + plan.period_end.slice(5, 7), status: t('week.plan_status.' + plan.status)})
            : t('week.source_demand');
        const behind = tot.expected_h - tot.done_h;
        root.innerHTML = `
            <div class="tfb-week-head">
                <div><h4 class="mb-0">${esc(t('week.title', {week: w.iso_week, from: dayLabel(w.days[0].date).replace(/^\S+\s/, ''), to: dayLabel(w.days[w.days.length - 1].date).replace(/^\S+\s/, '')}))}</h4>
                    <p class="text-muted mb-0 small">${esc(source)}</p></div>
                <div class="tfb-head-actions">
                    ${w.can_move ? `<button type="button" class="btn btn-primary btn-sm" data-distribute ${tot.unassigned ? '' : 'disabled'} title="${esc(t('week.arrange_help', {days: stockDays(w)}))}"><i class="bi bi-magic"></i> ${esc(t('week.distribute'))}</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-redistribute>${esc(t('week.redistribute'))}</button>
                        <button type="button" class="btn btn-outline-danger btn-sm" data-clear-week title="${esc(t('week.clear_help'))}"><i class="bi bi-x-circle"></i> ${esc(t('week.clear'))}</button>` : ''}
                    ${w.can_schedule && w.source.type === 'plan' ? `<button type="button" class="btn btn-outline-primary btn-sm" data-schedule title="${esc(t('week.schedule_hint'))}"><i class="bi bi-diagram-3"></i> ${esc(t('week.schedule'))}</button>` : ''}
                </div>
            </div>
            <div class="tfb-week-kpis">
                <div class="tfb-week-kpi is-direct"><span>🚚 ${esc(t('week.kpi_direct'))}</span><strong>${fmt(tot.direct_cartons, 0)} ${esc(t('week.cart'))}</strong>
                    <small>${esc(t('week.kpi_split_sub', {hours: fmt(tot.direct_h, 1), count: tot.direct_products}))}</small></div>
                <div class="tfb-week-kpi is-stock"><span>📦 ${esc(t('week.kpi_stock', {days: stockDays(w)}))}</span><strong>${fmt(tot.stock_cartons, 0)} ${esc(t('week.cart'))}</strong>
                    <small>${esc(t('week.kpi_split_sub', {hours: fmt(tot.stock_h, 1), count: tot.stock_products}))}</small></div>
                <div class="tfb-week-kpi"><span>${esc(t('week.kpi_available'))}</span><strong>${hrs(tot.available_h)}</strong><small>${esc(t('week.kpi_available_sub', {days: w.days.length}))}</small></div>
                <div class="tfb-week-kpi ${tot.load_pct > 100 ? 'is-bad' : ''}"><span>${esc(t('week.kpi_load'))}</span><strong>${tot.load_pct === null ? '—' : fmt(tot.load_pct, 0) + ' %'}</strong>
                    <small>${worst ? esc(t('week.kpi_load_sub', {group: worst.name || t('week.no_group'), pct: fmt(worst.load_pct, 0)})) : ''}</small></div>
                <div class="tfb-week-kpi ${behind > 0.5 ? 'is-bad' : (tot.done_h > 0 ? 'is-ok' : '')}"><span>${esc(t('week.kpi_done'))}</span>
                    <strong>${hrs(tot.done_h)}${tot.done_pct === null ? '' : ' · ' + fmt(tot.done_pct, 0) + ' %'}</strong>
                    <small>${esc(t('week.kpi_done_sub', {expected: fmt(tot.expected_h, 1), delta: (behind > 0 ? '−' : '+') + fmt(Math.abs(behind), 1)}))}${w.source.type === 'plan' ? '' : ' · ' + esc(t('week.kpi_done_tablets'))}</small></div>
            </div>
            ${w.warnings.length ? `<ul class="tfb-week-warnings">${w.warnings.map(x => `<li class="is-${esc(x.code)}">${esc(x.message)}</li>`).join('')}</ul>` : ''}
            <div class="card"><div class="card-body">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                    <h5 class="mb-0">${esc(t('week.gantt'))}</h5>
                    <ul class="tfb-gantt-legend mb-0">
                        <li><span class="tfb-week-sample"></span>${esc(t('week.legend_product'))}</li>
                        <li><span class="tfb-week-sample is-done"></span>${esc(t('week.legend_done'))}</li>
                        <li><span class="tfb-week-sample is-late"></span>${esc(t('week.legend_late'))}</li>
                        <li><span class="tfb-week-sample is-now"></span>${esc(t('now'))}</li>
                    </ul>
                </div>
                <div class="tfb-week-scroll">${board(w)}</div>
                <p class="small mb-1 mt-2"><strong>${esc(t('week.rule', {days: stockDays(w)}))}</strong></p>
                <p class="text-muted small mb-0">${esc(t(w.can_move ? 'week.help_move' : 'week.help_read'))}</p>
                ${pinsList(w)}
            </div></div>
            ${productTable(w)}`;
        bindWeek(root);
    }

    // Dni magazynowe tygodnia (np. « śr, czw, pt ») do opisów.
    function stockDays(w) {
        return w.days.filter(d => d.stock_day).map(d => dayLabel(d.date).split(',')[0].split(' ')[0]).join(', ') || '—';
    }

    function board(w) {
        const shown = w.groups.filter(g => !state.group || g.id === state.group);
        if (!shown.length) return `<p class="text-muted mb-0">${esc(t('week.empty'))}</p>`;
        const head = `<div class="tfb-week-row is-head" style="--days:${w.days.length}"><div class="tfb-week-label">${esc(t('week.group_hours'))}</div>
            <div class="tfb-week-dayhead tfb-week-todohead">${esc(t('week.todo'))}<div class="tfb-week-daykind">${esc(t('week.todo_sub'))}</div></div>
            ${w.days.map(d => `<div class="tfb-week-dayhead ${d.today ? 'is-today' : ''} ${d.past ? 'is-past' : ''} ${d.stock_day ? 'is-stock-day' : ''}">
                <a href="#from=${d.date}&view=day" data-day-view="${d.date}">${esc(dayLabel(d.date))}</a>${d.today ? ` <span>${esc(t('week.today'))}</span>` : ''}
                <div class="tfb-week-daykind">${d.stock_day ? '🚚📦 ' + esc(t('week.day_stock')) : '🚚 ' + esc(t('week.day_direct'))}</div></div>`).join('')}</div>`;
        return head + shown.map(g => {
            const products = w.products.filter(p => (p.group_id ?? null) === (g.id ?? null));
            const waiting = products.filter(p => p.unassigned_qty > 0);
            const color = g.color || '#8D1D2C';
            const cells = g.days.map((d, i) => {
                const day = w.days[i];
                const blocks = [];
                for (const p of products) for (const s of p.slots) if (s.day === d.date) {
                    const tiles = batchTiles(p, s.quantity, slotOffset(p, s.day));
                    tiles.forEach((tile, i) => blocks.push({p, s, tile: tiles.length > 1 || tile.label ? tile : null, split: tiles.length > 1, first: i === 0}));
                }
                // Tydzień typowy : najpierw bloki z godziną startu (po godzinie), potem bez godziny.
                blocks.sort((a, b) => (a.s.time || '99').localeCompare(b.s.time || '99'));
                const scale = Math.max(d.available_h, d.required_h, 0.01);
                const over = d.required_h - d.available_h;
                const now = day.today && d.elapsed > 0 && d.elapsed < 1 ? `<span class="tfb-week-now" style="left:${(d.elapsed * Math.min(1, d.available_h / scale) * 100).toFixed(2)}%" title="${esc(t('now'))}"></span>` : '';
                const cap = d.available_h > 0 ? `${fmt(d.required_h, 1)} / ${fmt(d.available_h, 1)} h` : (d.required_h > 0 ? t('week.no_team') : t('week.no_shift'));
                return `<div class="tfb-week-cell is-${esc(d.status)} ${day.past ? 'is-past' : ''} ${day.today ? 'is-today' : ''}" data-drop-day="${d.date}" data-group="${g.id ?? 0}">
                    <div class="tfb-week-cap"><span>${esc(cap)}</span>${over > 0.05 && d.available_h > 0 ? `<b>+${fmt(over, 1)} h</b>` : (d.available_h - d.required_h > 0.05 && d.required_h > 0 ? `<em>${esc(t('week.free', {hours: fmt(d.available_h - d.required_h, 1)}))}</em>` : '')}</div>
                    <div class="tfb-week-load">${blocks.filter(x => x.first).map(({p, s}) => `<span class="${s.late || s.behind ? 'is-late' : ''}" style="width:${(s.hours / scale * 100).toFixed(2)}%" title="${esc(p.name)} · ${hrs(s.hours)}"></span>`).join('')}
                        ${d.available_h > 0 && d.required_h > d.available_h ? `<span class="tfb-week-capline" style="left:${(d.available_h / scale * 100).toFixed(2)}%"></span>` : ''}${now}</div>
                    <div class="tfb-week-track">${blocks.map(({p, s, tile, split}) => block(p, s, scale, color, tile, split)).join('')}</div>
                </div>`;
            }).join('');
            // Do zrobienia : najpierw wysyłka (najbliższy termin), potem magazyn ; ilości w kartonach.
            const todo = waiting.map(p => ({p, ...waitingParts(p)}))
                .sort((a, b) => (b.direct > 0) - (a.direct > 0) || String(a.p.due_date || '9').localeCompare(String(b.p.due_date || '9')) || a.p.name.localeCompare(b.p.name));
            const todoCartons = todo.reduce((a, x) => a + (x.p.ppc ? x.p.unassigned_qty / x.p.ppc : 0), 0);
            const tray = `<div class="tfb-week-cell tfb-week-todo ${todo.length ? '' : 'is-empty'}" data-drop-day="" data-group="${g.id ?? 0}">
                <div class="tfb-week-cap"><span>${todo.length ? esc(t('week.todo_total', {cartons: fmt(todoCartons, 0), hours: fmt(g.unassigned_h, 1)})) : esc(t('week.todo_empty'))}</span></div>
                <div class="tfb-week-track">${todo.map(x => x.p.batch ? batchChips(x, color) : `<button type="button" class="tfb-week-chip ${x.p.movable ? 'is-movable' : ''} ${x.direct > 0 ? 'is-direct' : 'is-stock'}" style="--group-color:${esc(color)}" data-item="${x.p.item_id}" data-from=""
                    title="${esc([x.p.name, x.direct > 0 ? '🚚 ' + t('week.direct_part', {qty: qtyText(x.p, x.direct)}) + (x.p.due_date ? ' · ' + t('week.due') + ' ' + dayLabel(x.p.due_date) : '') : null, x.stock > 0 ? '📦 ' + t('week.stock_part', {qty: qtyText(x.p, x.stock)}) : null, hrs(x.p.unassigned_h)].filter(Boolean).join(' · '))}">
                    <span class="tfb-week-chip-name">${esc(x.p.name)}</span>
                    <span class="tfb-week-chip-qty">${x.direct > 0 ? '🚚 ' + esc(cartonsText(x.p, x.direct)) : ''}${x.direct > 0 && x.stock > 0 ? ' · ' : ''}${x.stock > 0 ? '📦 ' + esc(cartonsText(x.p, x.stock)) : ''} <small>${hrs(x.p.unassigned_h)}</small></span></button>`).join('')}</div>
            </div>`;
            return `<div class="tfb-week-group" style="--group-color:${esc(color)}">
                <div class="tfb-week-row" style="--days:${w.days.length}">
                    <div class="tfb-week-label" title="${esc(t('week.group_hours_title', {people: g.people, hours: fmt(g.available_h, 0)}))}"><strong>${esc(g.name || t('week.no_group'))}</strong>
                        <span>${fmt(g.available_h, 0)} h</span>
                    </div>${tray}${cells}
                </div></div>`;
        }).join('');
    }

    // « Do zrobienia » produktu z partią : jedna kafelka na partię (najpierw na wysyłkę — partie, które pokrywają
    // zamówienia — potem na magazyn) ; każdą przeciąga się osobno na dzień.
    function batchChips({p, direct}, color) {
        const offset = p.slots.reduce((a, x) => a + x.quantity, 0);
        const tiles = batchTiles(p, p.unassigned_qty, offset);
        let a = 0;
        return tiles.map(tile => {
            const isDirect = a < direct - 0.0005;
            a += tile.qty;
            const h = p.unassigned_qty > 0 ? p.unassigned_h * tile.qty / p.unassigned_qty : 0;
            const title = [p.name, t('week.batch_no', {n: tile.label}) + (tile.partial ? ' (' + t('week.batch_partial') + ')' : ''), qtyText(p, tile.qty), hrs(h),
                isDirect ? '🚚 ' + t('week.batch_direct') + (p.due_date ? ' · ' + t('week.due') + ' ' + dayLabel(p.due_date) : '') : '📦 ' + t('week.batch_stock')].join(' · ');
            return `<button type="button" class="tfb-week-chip is-batch ${p.movable ? 'is-movable' : ''} ${isDirect ? 'is-direct' : 'is-stock'}" style="--group-color:${esc(color)}" data-item="${p.item_id}" data-from=""
                ${tiles.length > 1 ? `data-qty="${tile.qty}"` : ''} title="${esc(title)}" aria-label="${esc(title)}">
                <span class="tfb-week-chip-name">${esc(p.name)}</span>
                <span class="tfb-week-chip-qty">${isDirect ? '🚚' : '📦'} <b class="tfb-week-batchno ${tile.partial ? 'is-partial' : ''}">${esc(t('week.batch_no', {n: tile.label}))}</b> · ${esc(p.ppc ? cartonsText(p, tile.qty) : fmt(tile.qty, p.unit === 'kg' ? 1 : 0) + ' ' + p.unit)} <small>${hrs(h)}</small></span></button>`;
        }).join('');
    }

    function block(p, s, scale, color, tile = null, split = false) {
        const tileH = tile ? s.hours * tile.qty / Math.max(s.quantity, 0.001) : s.hours;
        const width = Math.min(100, Math.max(0, tileH / scale * 100));
        const done = s.hours > 0 ? Math.min(100, s.done_h / s.hours * 100) : (p.state === 'done' ? 100 : 0);
        const finished = done >= 99.9;
        const kind = s.direct_qty > 0 && s.stock_qty > 0 ? 'is-mixed' : (s.stock_qty > 0 ? 'is-stock' : 'is-direct');
        const icon = kind === 'is-mixed' ? '🚚📦' : (kind === 'is-stock' ? '📦' : '🚚');
        const pinned = (p.pins || []).some(x => x.day === s.day);
        const title = [p.name, tile ? t('week.batch_no', {n: tile.label}) + (tile.partial ? ' (' + t('week.batch_partial') + ')' : '') : null,
            qtyText(p, tile ? tile.qty : s.quantity), hrs(tileH), tile && split ? t('week.batch_day', {qty: qtyText(p, s.quantity)}) : null,
            s.direct_qty > 0 ? '🚚 ' + t('week.direct_part', {qty: qtyText(p, s.direct_qty)}) : null, s.stock_qty > 0 ? '📦 ' + t('week.stock_part', {qty: qtyText(p, s.stock_qty)}) : null,
            p.due_date ? t('week.due') + ' ' + dayLabel(p.due_date) : null, s.late ? t('week.late') : null, s.behind ? t('week.behind') : null,
            s.time ? t('week.at', {time: s.time}) : null, pinned ? '📌 ' + t('week.pinned') : null,
            done > 0 ? t('week.done_part', {pct: fmt(done, 0)}) : null].filter(Boolean).join(' · ');
        const pinTitle = pinned ? t('week.toggle_on', {day: weekdayName(s.day), time: s.time ? ' ' + t('week.at', {time: s.time}) : ''}) : t('week.toggle_off', {day: weekdayName(s.day)});
        const toggle = p.movable
            ? `<button type="button" class="tfb-week-pintoggle ${pinned ? 'is-on' : ''}" data-pin-toggle data-pin-item="${p.item_id}" data-day="${s.day}" aria-pressed="${pinned}" title="${esc(pinTitle)}" aria-label="${esc(pinTitle)}">📌</button>`
            : (pinned ? `<span class="tfb-week-pintoggle is-on is-static" title="${esc(pinTitle)}">📌</span>` : '');
        return `<div class="tfb-week-blockrow"><button type="button" class="tfb-week-block ${kind} ${p.movable ? 'is-movable' : ''} ${s.late ? 'is-late' : ''} ${s.behind ? 'is-behind' : ''} ${finished ? 'is-finished' : ''} ${pinned ? 'is-pinned' : ''}"
            style="--group-color:${esc(color)};--share:${width.toFixed(2)}%" data-item="${p.item_id}" data-from="${s.day}" ${split ? `data-qty="${tile.qty}"` : ''} title="${esc(title)}" aria-label="${esc(title)}">
            <span class="tfb-week-block-text">${finished ? '✓ ' : ''}${s.time ? `<span class="tfb-week-time">${esc(s.time)}</span>` : ''}<span class="tfb-week-block-icon">${icon}</span> ${esc(p.name)}</span><span class="tfb-week-block-h">${tile ? `<b class="tfb-week-batchno ${tile.partial ? 'is-partial' : ''}">${esc(tile.label)}</b> · ` : (s.cartons !== null ? fmt(s.cartons, 0) + ' ' + esc(t('week.cart')) + ' · ' : '')}${fmt(tileH, 1)} h</span>
            ${done > 0 ? `<span class="tfb-week-block-done" style="width:${done.toFixed(1)}%"></span>` : ''}</button>${toggle}</div>`;
    }

    // 📌 Stałe partie co tydzień : lista pod tablicą (dzień tygodnia, ilość, partie).
    const weekdayName = (day) => dayLabel(day, true).split(',')[0];
    const pinQty = (x) => (x.batch ? batchText(x, x.quantity) + ' · ' : '') + (x.ppc ? cartonsText(x, x.quantity) : `${fmt(x.quantity, x.unit === 'kg' ? 1 : 0)} ${x.unit}`);
    function pinsList(w) {
        const pins = (w.pins || []).filter(x => !state.group || x.group_id === state.group);
        if (!pins.length) return `<p class="text-muted small mb-0 mt-1">📌 ${esc(t('week.pins_none'))}</p>`;
        const groupName = (id) => (w.groups.find(g => g.id === id) || {}).name || t('week.no_group');
        return `<details class="tfb-week-pins mt-2"><summary>📌 ${esc(t('week.pins_title', {n: pins.length}))}</summary>
            <ul>${[...pins].sort((a, b) => a.day.localeCompare(b.day) || (a.time || '99').localeCompare(b.time || '99') || a.name.localeCompare(b.name)).map(x => `<li><strong>${esc(weekdayName(x.day))}${x.time ? ' ' + esc(x.time) : ''}</strong> · ${esc(x.name)}
                <span class="text-muted">· ${esc(groupName(x.group_id))} · ${esc(pinQty(x))}</span></li>`).join('')}</ul></details>`;
    }

    // Szczegóły produktu : stałe partie produktu i przycisk 📌 dla wybranego dnia.
    function pinsDetail(p, from) {
        const pins = p.pins || [];
        const slot = from ? p.slots.find(s => s.day === from) : null;
        const onDay = from ? pins.find(x => x.day === from) : null;
        const list = pins.map(x => `<div>📌 <strong>${esc(weekdayName(x.day))}${x.time ? ' ' + esc(x.time) : ''}</strong> · ${esc(pinQty({...p, quantity: x.quantity}))}
            ${p.movable ? `<button type="button" class="btn btn-link btn-sm p-0 ms-1" data-unpin="${x.day}">${esc(t('week.unpin'))}</button>` : ''}</div>`).join('');
        const action = p.movable && slot && !onDay
            ? `<button type="button" class="btn btn-sm btn-outline-primary mt-1" data-pin="${slot.day}">📌 ${esc(t('week.pin', {day: weekdayName(slot.day), qty: pinQty({...p, quantity: slot.quantity})}))}</button>
               <div class="text-muted small">${esc(t('week.pin_help'))}</div>` : '';
        return `<dt>${esc(t('week.pins'))}</dt><dd>${list || `<span class="text-muted">${esc(t('week.pins_product_none'))}</span>`}${action}</dd>`;
    }

    async function pinProduct(itemId, day, pinned, openDetail = true) {
        try {
            week = await api.post('/planning/week/pin', {date: week.week_start, item_id: itemId, day, pinned});
            ui.notify(true, t(pinned ? 'week.pin_saved' : 'week.unpin_saved'));
            drawWeek();
            if (openDetail) openProduct(itemId, day);
            return true;
        } catch (err) {
            ui.notify(false, ((err.body && err.body.errors) || []).map(x => x.message).join(' ') || err.message);
            drawWeek();
            return false;
        }
    }

    // Godzina startu bloku w dniu (i stałej partii 📌 tego dnia tygodnia).
    function timeDetail(p, from) {
        const slot = from ? p.slots.find(s => s.day === from) : null;
        if (!slot) return '';
        const pinned = (p.pins || []).some(x => x.day === from);
        return `<dt>${esc(t('week.start_time'))}</dt><dd><div class="d-flex align-items-center gap-2 flex-wrap">
            <input type="time" class="form-control form-control-sm" style="width:auto" data-slot-time="${slot.day}" value="${esc(slot.time || '')}" ${p.movable ? '' : 'disabled'} aria-label="${esc(t('week.start_time'))}">
            <span class="text-muted small">${esc(dayLabel(slot.day, true))}${pinned ? ' · 📌 ' + esc(t('week.time_every_week')) : ''}</span></div></dd>`;
    }

    // Kolumny partii : sztuk w partii (karta, « Etapy i czasy ») i liczba partii w tygodniu (w górę do pełnych),
    // z tym, ile już jest na dniach ; bez partii — « — » z odnośnikiem do karty.
    function batchCells(p) {
        if (!p.batch) return `<td class="text-end"><a class="text-muted small" href="${esc(api.root)}/workshop/products#/item/${p.item_id}/6" title="${esc(t('week.batch_set'))}">—</a></td><td class="text-end text-muted">—</td>`;
        const total = Math.ceil(p.quantity / p.batch - 1e-6);
        const placed = p.slots.reduce((a, x) => a + x.quantity, 0) / p.batch;
        const partial = p.quantity / p.batch % 1 > 0.001 && p.quantity / p.batch % 1 < 0.999;
        return `<td class="text-end">${fmt(p.batch, p.unit === 'kg' ? 1 : 0)} ${esc(p.unit)}${p.ppc ? `<div class="text-muted small">${esc(cartonsText(p, p.batch))}</div>` : ''}</td>
            <td class="text-end"><strong>${fmt(total, 0)}</strong>${partial ? ` <span class="text-warning small" title="${esc(t('week.batch_partial_help'))}">(${esc(t('week.batch_partial'))})</span>` : ''}
                <div class="text-muted small">${esc(t('week.batches_placed', {n: fmt(Math.min(placed, total), placed % 1 > 0.001 ? 1 : 0)}))}</div></td>`;
    }

    function productTable(w) {
        const rows = w.products.filter(p => !state.group || p.group_id === state.group);
        if (!rows.length) return '';
        const groupName = (id) => (w.groups.find(g => g.id === id) || {}).name || t('week.no_group');
        // Godziny tygodnia grupy (to, co trzeba wyprodukować : zamówienia + uzupełnienie stanu po odjęciu tego,
        // co jest na magazynie) i ETP = godziny ÷ godziny etatu (week.fte_hours) ; obok dostępne godziny i osoby.
        const fteH = w.fte_hours || 40;
        const hoursOf = {};
        for (const p of rows) hoursOf[p.group_id ?? 0] = (hoursOf[p.group_id ?? 0] || 0) + p.hours;
        const need = (h) => esc(t('week.group_need', {hours: fmt(h, 1), fte: fmt(h / fteH, 1), base: fmt(fteH, 0)}));
        const groupLine = (id) => {
            const g = w.groups.find(x => (x.id ?? null) === (id ?? null));
            const h = hoursOf[id ?? 0] || 0;
            const short = g && g.available_h > 0 && h > g.available_h + 0.05;
            return `<span class="tfb-week-fte ${short ? 'is-short' : ''}">${need(h)}${g ? ' · ' + esc(t('week.group_has', {hours: fmt(g.available_h, 0), people: g.people, fte: fmt(g.available_h / fteH, 1)})) : ''}</span>`;
        };
        let last;
        const body = rows.map(p => {
            const sep = p.group_id !== last ? `<tr class="tfb-week-table-group"><th colspan="9">${esc(groupName(p.group_id))} ${groupLine(p.group_id)}</th></tr>` : '';
            last = p.group_id;
            const days = p.slots.map(s => `<span class="${s.late ? 'text-danger' : ''}">${esc(dayLabel(s.day).split(',')[0].replace('.', ''))}</span>`).join(', ');
            return sep + `<tr data-item-row="${p.item_id}">
                <td><button type="button" class="btn btn-link p-0 text-start" data-open-item="${p.item_id}">${esc(p.name)}</button></td>
                <td class="text-end">${p.direct > 0 ? `<strong>🚚 ${esc(qtyText(p, p.direct))}</strong>${p.direct_parts.map(d => `<div class="text-muted small">${esc(t('week.truck', {day: dayLabel(d.due_date)}))}${p.direct_parts.length > 1 ? ' · ' + esc(qtyText(p, d.quantity)) : ''}</div>`).join('')}` : '—'}</td>
                <td class="text-end">${p.stock > 0 ? `<strong>📦 ${esc(qtyText(p, p.stock))}</strong>` : '—'}</td>
                <td class="text-end">${esc(qtyText(p, p.quantity))}${p.item_type === 'semi' ? `<div class="text-muted small">${esc(t('week.mode.semi'))}</div>` : ''}</td>
                ${batchCells(p)}
                <td class="text-end">${hrs(p.hours)}</td>
                <td>${days || ''}${p.unassigned_qty > 0 ? `<div class="text-warning small">${esc(t('week.waiting', {qty: qtyText(p, p.unassigned_qty)}))}</div>` : ''}</td>
                <td class="tfb-week-progress"><span class="tfb-week-progress-bar"><span style="width:${p.done_pct ?? 0}%"></span></span>
                    ${hrs(p.done_h)} · ${p.done_pct === null ? '—' : fmt(p.done_pct, 0) + ' %'}</td></tr>`;
        }).join('');
        return `<div class="card"><div class="card-body">
            <h5>${esc(t('week.table_title'))}</h5>
            <div class="table-responsive"><table class="table table-sm mb-0 tfb-week-table">
                <thead><tr><th>${esc(t('week.col_product'))}</th><th class="text-end">${esc(t('week.col_direct'))}</th><th class="text-end">${esc(t('week.col_stock'))}</th><th class="text-end">${esc(t('week.col_qty'))}</th>
                    <th class="text-end">${esc(t('week.col_batch_size'))}</th><th class="text-end" title="${esc(t('week.col_batches_help'))}">${esc(t('week.col_batches'))}</th><th class="text-end">${esc(t('week.col_hours'))}</th><th>${esc(t('week.col_days'))}</th><th>${esc(t('week.col_done'))}</th></tr></thead>
                <tbody>${body}</tbody>
                <tfoot><tr class="tfb-week-table-total"><th colspan="9">${esc(t('week.total_need'))} ${(() => { const h = Object.values(hoursOf).reduce((a, b) => a + b, 0);
                    return `<span class="tfb-week-fte">${need(h)}</span>`; })()}</th></tr></tfoot></table></div>
            <p class="text-muted small mb-0 mt-2">${esc(t('week.table_note'))}</p></div></div>`;
    }

    async function moveProduct(itemId, from, to, quantity) {
        try {
            week = await api.post('/planning/week/move', {date: week.week_start, item_id: itemId, from: from || null, to: to || null, ...(quantity ? {quantity} : {})});
            const p = week.products.find(x => x.item_id === itemId);
            const late = p && to && p.slots.some(s => s.day === to && s.late);
            ui.notify(true, late ? t('week.moved_late') : t('saved'));
            drawWeek();
            return true;
        } catch (err) {
            ui.notify(false, ((err.body && err.body.errors) || []).map(x => x.message).join(' ') || err.message);
            drawWeek();
            return false;
        }
    }

    function bindWeek(root) {
        root.querySelector('[data-distribute]')?.addEventListener('click', () => distribute(false));
        root.querySelector('[data-redistribute]')?.addEventListener('click', () => { if (window.confirm(t('week.redistribute_confirm'))) distribute(true); });
        root.querySelector('[data-clear-week]')?.addEventListener('click', async (e) => {
            if (!window.confirm(t('week.clear_confirm'))) return;
            e.target.disabled = true;
            try {
                week = await api.post('/planning/week/clear', {date: week.week_start});
                ui.notify(true, t('week.cleared'));
            } catch (err) { ui.notify(false, err.message); }
            drawWeek();
        });
        root.querySelector('[data-schedule]')?.addEventListener('click', async (e) => {
            e.target.disabled = true;
            try {
                let ops = 0, conflicts = 0;
                for (const plan of week.source.plans.filter(p => p.status === 'validated' || p.status === 'frozen')) {
                    const r = await api.post(`/planning/plans/${plan.id}/schedule`);
                    ops += r.operations;
                    conflicts += r.conflicts.length;
                }
                ui.notify(conflicts === 0, t('week.scheduled', {operations: ops, conflicts}));
            } catch (err) {
                ui.notify(false, err.message);
            }
            e.target.disabled = false;
        });
        root.querySelectorAll('[data-day-view]').forEach(a => a.addEventListener('click', (e) => {
            e.preventDefault();
            state = {from: a.dataset.dayView, view: 'day', group: state.group};
            render();
        }));
        root.querySelectorAll('[data-open-item]').forEach(b => b.addEventListener('click', () => openProduct(Number(b.dataset.openItem))));

        // Glisser-déposer (souris, doigt, stylet) ; un appui simple ou Entrée ouvre la fiche du produit.
        const gantt = root.querySelector('.tfb-week-scroll');
        let drag = null;
        gantt.addEventListener('click', (e) => {
            const tog = e.target.closest('[data-pin-toggle]');
            if (tog) {
                tog.disabled = true;
                pinProduct(Number(tog.dataset.pinItem), tog.dataset.day, tog.getAttribute('aria-pressed') !== 'true', false);
                return;
            }
            const b = e.target.closest('[data-item]');
            if (!b) return;
            if (drag && drag.moved) return;
            openProduct(Number(b.dataset.item), b.dataset.from || null);
        });
        gantt.addEventListener('pointerdown', (e) => {
            const b = e.target.closest('[data-item].is-movable');
            if (!b || e.button !== 0) return;
            drag = {el: b, item: Number(b.dataset.item), from: b.dataset.from || '', qty: b.dataset.qty ? Number(b.dataset.qty) : null,
                group: b.closest('[data-group]').dataset.group, x0: e.clientX, y0: e.clientY, moved: false, ghost: null};
            b.setPointerCapture(e.pointerId);
        });
        let lastPoint = {x: 0, y: 0};
        gantt.addEventListener('pointermove', (e) => {
            if (!drag) return;
            lastPoint = {x: e.clientX, y: e.clientY};
            if (!drag.moved && Math.abs(e.clientX - drag.x0) < 5 && Math.abs(e.clientY - drag.y0) < 5) return;
            if (!drag.moved) {
                drag.moved = true;
                drag.ghost = drag.el.cloneNode(true);
                drag.ghost.classList.add('tfb-week-ghost');
                drag.ghost.style.width = `${Math.min(220, Math.max(90, drag.el.offsetWidth))}px`;
                document.body.appendChild(drag.ghost);
                drag.el.classList.add('is-dragging');
                gantt.classList.add('is-dragging');
            }
            drag.ghost.style.left = `${e.clientX + 8}px`;
            drag.ghost.style.top = `${e.clientY + 8}px`;
            gantt.querySelectorAll('.is-target').forEach(c => c.classList.remove('is-target'));
            const over = document.elementsFromPoint(e.clientX, e.clientY).map(n => n.closest && n.closest('[data-drop-day]')).find(Boolean);
            if (over && over.dataset.group === drag.group && over.dataset.dropDay !== drag.from && !over.classList.contains('is-past')) over.classList.add('is-target');
        });
        const finish = async () => {
            if (!drag) return;
            const d = drag;
            const target = gantt.querySelector('.is-target');
            gantt.querySelectorAll('.is-target').forEach(c => c.classList.remove('is-target'));
            gantt.classList.remove('is-dragging');
            d.el.classList.remove('is-dragging');
            d.ghost?.remove();
            if (!d.moved) { drag = null; return; }
            window.setTimeout(() => { drag = null; }, 0);
            if (target) await dropOn(d, target, lastPoint);
        };
        gantt.addEventListener('pointerup', finish);
        gantt.addEventListener('pointercancel', finish);
    }

    // Upuszczenie na dzień : gdy produkt nie mieści się w wolnych godzinach grupy, wybór « całość » albo
    // « tyle, ile się mieści » (pełne partie produkcyjne, inaczej pełne kartony) ; z powrotem do « Do zrobienia » — zawsze całość.
    async function dropOn(d, target, point) {
        const day = target.dataset.dropDay;
        const p = week.products.find(x => x.item_id === d.item);
        // Kafelka partii : jedna partia przenosi się w całości (na dzień albo z powrotem do « Do zrobienia »).
        if (d.qty) return moveProduct(d.item, d.from, day, d.qty);
        if (!day || !p) return moveProduct(d.item, d.from, day);
        if (d.from && (p.pins || []).some(x => x.day === d.from)) return dropPinned(d, p, day, point);
        const qty = d.from ? (p.slots.find(s => s.day === d.from)?.quantity ?? 0) : p.unassigned_qty;
        const hours = d.from ? (p.slots.find(s => s.day === d.from)?.hours ?? 0) : p.unassigned_h;
        const g = week.groups.find(x => String(x.id ?? 0) === target.dataset.group);
        const dayRow = g ? g.days.find(x => x.date === day) : null;
        const free = dayRow ? dayRow.available_h - dayRow.required_h : 0;
        const unit = p.batch || p.ppc || (p.unit === 'kg' ? 0.1 : 1);
        const fits = hours > 0 && free > 0 ? Math.floor(qty * Math.min(1, free / hours) / unit) * unit : 0;
        if (hours <= free + 0.05 || fits <= 0 || fits >= qty) return moveProduct(d.item, d.from, day);
        document.querySelector('.tfb-week-dropmenu')?.remove();
        const menu = document.createElement('div');
        menu.className = 'tfb-week-dropmenu';
        menu.style.left = `${Math.min(point.x, window.innerWidth - 280)}px`;
        menu.style.top = `${point.y + 6}px`;
        menu.innerHTML = `<div class="small text-muted mb-1">${esc(t('week.drop_full', {day: dayLabel(day), free: fmt(Math.max(0, free), 1), hours: fmt(hours, 1)}))}</div>
            <button type="button" class="btn btn-sm btn-primary" data-qty="${fits}">${esc(t('week.drop_fit', {qty: partText(p, fits)}))}</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-qty="all">${esc(t('week.drop_all', {qty: partText(p, qty)}))}</button>`;
        document.body.appendChild(menu);
        const close = () => { menu.remove(); document.removeEventListener('pointerdown', outside, true); };
        const outside = (e) => { if (!menu.contains(e.target)) close(); };
        setTimeout(() => document.addEventListener('pointerdown', outside, true), 0);
        menu.addEventListener('click', async (e) => {
            const b = e.target.closest('[data-qty]');
            if (!b) return;
            close();
            await moveProduct(d.item, d.from, day, b.dataset.qty === 'all' ? null : Number(b.dataset.qty));
        });
    }

    // Przypięty blok (📌) na inny dzień : tylko w tym tygodniu (reguła zostaje) albo co tydzień w nowy dzień.
    function dropPinned(d, p, day, point) {
        document.querySelector('.tfb-week-dropmenu')?.remove();
        const menu = document.createElement('div');
        menu.className = 'tfb-week-dropmenu';
        menu.style.left = `${Math.min(point.x, window.innerWidth - 280)}px`;
        menu.style.top = `${point.y + 6}px`;
        menu.innerHTML = `<div class="small text-muted mb-1">${esc(t('week.drop_pinned', {name: p.name, from: weekdayName(d.from), to: weekdayName(day)}))}</div>
            <button type="button" class="btn btn-sm btn-primary" data-choice="every">📌 ${esc(t('week.drop_pinned_every', {day: weekdayName(day)}))}</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-choice="once">${esc(t('week.drop_pinned_once'))}</button>`;
        document.body.appendChild(menu);
        const close = () => { menu.remove(); document.removeEventListener('pointerdown', outside, true); };
        const outside = (e) => { if (!menu.contains(e.target)) close(); };
        setTimeout(() => document.addEventListener('pointerdown', outside, true), 0);
        menu.addEventListener('click', async (e) => {
            const b = e.target.closest('[data-choice]');
            if (!b) return;
            close();
            if (!await moveProduct(d.item, d.from, day)) return;
            if (b.dataset.choice === 'every' && await pinProduct(d.item, day, true, false)) await pinProduct(d.item, d.from, false, false);
        });
    }

    async function distribute(reset) {
        const buttons = el.querySelectorAll('[data-distribute], [data-redistribute]');
        buttons.forEach(b => { b.disabled = true; });
        try {
            week = await api.post('/planning/week/distribute', {date: week.week_start, reset});
            ui.notify(true, t('week.distributed'));
        } catch (err) {
            ui.notify(false, err.message);
        }
        drawWeek();
    }

    function openProduct(itemId, fromDay) {
        const p = week.products.find(x => x.item_id === itemId);
        const box = el.querySelector('[data-detail]');
        if (!p || !box) return;
        const days = week.days.filter(d => !d.past);
        const from = fromDay ?? (p.slots[0]?.day || '');
        const available = from ? (p.slots.find(s => s.day === from)?.quantity ?? 0) : p.unassigned_qty;
        const sources = [...p.slots.map(s => ({value: s.day, label: `${dayLabel(s.day)} · ${qtyText(p, s.quantity)}`})),
            ...(p.unassigned_qty > 0 ? [{value: '', label: `${t('week.tray_short')} · ${qtyText(p, p.unassigned_qty)}`}] : [])];
        box.innerHTML = `<div class="card tfb-gantt-detail"><div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h4 class="card-title mb-0">${esc(p.name)}</h4>
                <button type="button" class="btn-close" data-close aria-label="${esc(t('close'))}"></button></div>
            <div class="card-body pt-0">
                <dl class="tfb-dl">
                    <dt>${esc(t('week.col_mode'))}</dt><dd>${esc(t('week.mode.' + (p.item_type === 'semi' ? 'semi' : p.mode)))}${p.due_date ? ' · ' + esc(t('week.due')) + ' ' + esc(dayLabel(p.due_date, true)) : ''}</dd>
                    <dt>${esc(t('week.col_qty'))}</dt><dd>${esc(qtyText(p, p.quantity))} · ${hrs(p.hours)}</dd>
                    ${p.batch ? `<dt>${esc(t('week.batch'))}</dt><dd>${fmt(p.batch, p.unit === 'kg' ? 1 : 0)} ${esc(p.unit)}${p.ppc ? ' · ' + esc(cartonsText(p, p.batch)) : ''}</dd>` : ''}
                    <dt>${esc(t('week.col_days'))}</dt><dd>${p.slots.map(s => `${esc(dayLabel(s.day, true))}: ${esc(qtyText(p, s.quantity))} · ${hrs(s.hours)}${s.late ? ' · <span class="text-danger">' + esc(t('week.late')) + '</span>' : ''}`).join('<br>') || '—'}
                        ${p.unassigned_qty > 0 ? `<div class="text-warning">${esc(t('week.waiting', {qty: qtyText(p, p.unassigned_qty)}))}</div>` : ''}</dd>
                    <dt>${esc(t('week.col_done'))}</dt><dd>${hrs(p.done_h)} · ${p.done_pct === null ? '—' : fmt(p.done_pct, 0) + ' %'} · ${esc(t('week.state.' + p.state))}</dd>
                    ${timeDetail(p, from)}
                    ${pinsDetail(p, from)}
                </dl>
                ${p.movable && sources.length && days.length ? `<form class="tfb-form tfb-week-move" data-move>
                    <label>${esc(t('week.move_from'))}<select class="form-select" name="from">${sources.map(o => `<option value="${o.value}" ${o.value === from ? 'selected' : ''}>${esc(o.label)}</option>`).join('')}</select></label>
                    <label>${esc(t('week.move_to'))}<select class="form-select" name="to">${days.map(d => `<option value="${d.date}" ${d.date === (days.find(x => x.date !== from) || {}).date ? 'selected' : ''}>${esc(dayLabel(d.date, true))}</option>`).join('')}<option value="">${esc(t('week.tray_short'))}</option></select></label>
                    <label>${esc(t('week.move_qty', {unit: p.unit}))}<input type="number" class="form-control" name="quantity" min="0" step="${p.unit === 'kg' ? 0.1 : 1}" value="${available}"></label>
                    <div class="tfb-nav"><span class="text-muted small">${esc(t('week.move_hint'))}</span><button class="btn btn-primary">${esc(t('week.move'))}</button></div>
                </form>` : ''}
                <a class="btn btn-sm btn-outline-secondary mt-2" href="${esc(api.root)}/workshop/products#/item/${p.item_id}/1">${esc(t('week.open_card'))}</a>
            </div></div>`;
        box.querySelector('[data-close]').addEventListener('click', () => { box.innerHTML = ''; });
        box.querySelectorAll('[data-pin]').forEach(b => b.addEventListener('click', () => pinProduct(p.item_id, b.dataset.pin, true)));
        box.querySelector('[data-slot-time]')?.addEventListener('change', async (e) => {
            try {
                week = await api.post('/planning/week/time', {date: week.week_start, item_id: p.item_id, day: e.target.dataset.slotTime, time: e.target.value || null});
                ui.notify(true, t('saved'));
                drawWeek();
                openProduct(p.item_id, e.target.dataset.slotTime);
            } catch (err) { ui.notify(false, ((err.body && err.body.errors) || []).map(x => x.message).join(' ') || err.message); }
        });
        box.querySelectorAll('[data-unpin]').forEach(b => b.addEventListener('click', () => pinProduct(p.item_id, b.dataset.unpin, false)));
        const form = box.querySelector('[data-move]');
        if (form) {
            form.from.addEventListener('change', () => {
                form.quantity.value = form.from.value ? (p.slots.find(s => s.day === form.from.value)?.quantity ?? 0) : p.unassigned_qty;
            });
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                const q = Number(form.quantity.value);
                const max = form.from.value ? (p.slots.find(s => s.day === form.from.value)?.quantity ?? 0) : p.unassigned_qty;
                if (await moveProduct(p.item_id, form.from.value, form.to.value, q && q < max ? q : null)) openProduct(p.item_id, form.to.value || null);
            });
        }
        box.scrollIntoView({behavior: 'smooth', block: 'nearest'});
    }

    el.addEventListener('click', async (e) => {
        if (!e.target.closest('[data-lang-switch]')) return;
        await api.setLang(api.lang === 'pl' ? 'fr' : 'pl');
        const [g, w] = await Promise.all([api.list('/referentiels/groups'), api.list('/referentiels/workstations')]);
        groups = g.data.filter(x => x.active);
        workstations = w.data.filter(x => x.active);
        render();
    });
    window.addEventListener('hashchange', () => { state = readHash(); render(); });
    render();
})();
