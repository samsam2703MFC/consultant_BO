/*
 * Gantt pracowni / Gantt de l'atelier (P5) : opérations planifiées par groupe et par poste,
 * charge des équipes par shift, déplacement (glisser ou fiche) et verrouillage.
 * Lecture : GET /planning/operations et /planning/load ; écriture : PATCH /planning/operations/{id}
 * (prod.gantt.move — le serveur refuse tout ce qui casse une règle : échéance camion, ordre des
 * étapes, shift sans monde, poste occupé, chambre pleine).
 */
(async function () {
    'use strict';
    const el = document.getElementById('tfbGantt');
    if (!el) return;
    const {WorkshopApi, ui} = window.TfbWorkshop;
    const api = new WorkshopApi(el);
    const esc = ui.esc;
    const VIEWS = {day: {days: 1, pph: 64, tick: 1}, three: {days: 3, pph: 24, tick: 3}, week: {days: 7, pph: 10, tick: 6}};
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
        return {from: from || null, view: VIEWS[p.get('view')] ? p.get('view') : 'three', group: Number(p.get('group')) || 0};
    }
    function writeHash() {
        const p = new URLSearchParams({from: state.from, view: state.view});
        if (state.group) p.set('group', state.group);
        history.replaceState(null, '', '#' + p.toString());
    }

    function header() {
        return `<div class="tfb-head"><div><h3>${esc(t('title'))}</h3><p class="text-muted mb-0">${esc(t('subtitle'))}</p></div>
            <div class="tfb-head-actions"><a class="btn btn-outline-primary btn-sm" href="${esc(api.root)}/workshop/planning">${esc(t('plans'))}</a>
            <a class="btn btn-outline-primary btn-sm" href="${esc(api.root)}/workshop/optimizer#from=${esc(state.from || '')}">${esc(t('optimizer'))}</a>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-lang-switch>${esc(t('lang_switch'))}</button></div></div>`;
    }

    async function render() {
        state.from ??= iso(new Date());
        writeHash();
        const view = VIEWS[state.view];
        const start = parse(state.from + ' 00:00:00');
        const to = iso(new Date(start.getTime() + (view.days - 1) * 86400000));
        el.innerHTML = header() + `
            <form class="tfb-filters tfb-gantt-filters" data-filters>
                <div class="btn-group btn-group-sm" role="group" aria-label="${esc(t('period'))}">
                    <button type="button" class="btn btn-outline-primary" data-shift="-1" aria-label="${esc(t('previous'))}">‹</button>
                    <button type="button" class="btn btn-outline-primary" data-today>${esc(t('today'))}</button>
                    <button type="button" class="btn btn-outline-primary" data-shift="1" aria-label="${esc(t('next'))}">›</button>
                </div>
                <label class="tfb-inline">${esc(t('from'))} <input type="date" class="form-control form-control-sm" name="from" value="${state.from}"></label>
                <select class="form-select form-select-sm" name="view" aria-label="${esc(t('view'))}">${Object.keys(VIEWS).map(v => `<option value="${v}" ${v === state.view ? 'selected' : ''}>${esc(t('view.' + v))}</option>`).join('')}</select>
                <select class="form-select form-select-sm" name="group" aria-label="${esc(t('group'))}"><option value="0">${esc(t('all_groups'))}</option>${groups.map(g => `<option value="${g.id}" ${g.id === state.group ? 'selected' : ''}>${esc(g.name)}</option>`).join('')}</select>
            </form>
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
        gantt.addEventListener('pointermove', (e) => {
            if (!drag) return;
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
