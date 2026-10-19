/*
 * Optymalizacja zmian / Optimiseur de shifts (P6) : obciążenie grup na zmianę, propozycja dźwigni
 * (MTS przyspieszone, przesunięcie osób, zamknięcie / zmniejszenie / dodanie, nadgodziny), symulacja
 * innej obsady bez zapisu, zastosowanie propozycji i obsada ręczna dnia.
 * API : GET /planning/optimizer, POST /planning/simulate, POST /planning/optimizer/apply, PUT /planning/staffing.
 */
(async function () {
    'use strict';
    const el = document.getElementById('tfbOptimizer');
    if (!el) return;
    const {WorkshopApi, ui} = window.TfbWorkshop;
    const api = new WorkshopApi(el);
    const esc = ui.esc;
    let groups = [];
    let canApply = false;
    let proposal = null;

    try {
        await api.init();
        const [g, me] = await Promise.all([api.list('/referentiels/groups'), api.get('/auth/me')]);
        groups = g.data;
        canApply = me.permissions.includes('prod.plan.validate');
    } catch (e) {
        el.innerHTML = `<div class="alert alert-danger">${esc(e.message)}</div>`;
        return;
    }
    const t = (k, p) => api.t(k, p);
    const fmt = (n, d) => ui.fmt(n, d ?? 1, api.lang);
    const iso = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    const groupName = (id) => (groups.find(g => g.id === id) || {}).name || '#' + id;
    const params = new URLSearchParams(location.hash.replace(/^#\/?\??/, ''));
    let from = /^\d{4}-\d{2}-\d{2}$/.test(params.get('from') || '') ? params.get('from') : iso(new Date(Date.now() + 86400000));
    const toOf = (f) => iso(new Date(new Date(f + 'T00:00:00').getTime() + 6 * 86400000));

    function bar(state) {
        if (!state || state.load_pct === null) return `<span class="text-muted small">${esc(t('status.' + (state ? state.status : 'closed')))}</span>`;
        const width = Math.min(100, state.load_pct / 1.2);
        return `<div class="tfb-opt-bar is-${state.status}" title="${esc(t('hours', {required: fmt(state.required_h, 1), available: fmt(state.available_h, 1)}))}">
            <span style="width:${width}%"></span><i style="left:${90 / 1.2}%"></i><i style="left:${100 / 1.2}%"></i></div>
            <strong class="tfb-opt-pct is-${state.status}">${fmt(state.load_pct, 0)} %</strong>`;
    }

    function action(a) {
        switch (a.lever) {
            case 'advance_mts': return t('act.advance_mts', {item: a.item, qty: fmt(a.quantity, 0), hours: fmt(a.hours, 1)}) + (a.plan_id ? '' : ' — ' + t('act.no_plan'));
            case 'reassign_polyvalent': return a.people > 0 ? t('act.reassign_in', {n: a.people, group: groupName(a.from_group_id)}) : t('act.reassign_out', {n: -a.people, group: groupName(a.to_group_id)});
            case 'open_close_shift': return t('act.' + a.action, {n: Math.abs(a.people)});
            case 'overtime': return t('act.overtime', {hours: fmt(a.hours, 2)});
            default: return a.lever;
        }
    }

    function tiles(summary) {
        const tile = (label, s) => `<div class="at-tile"><span class="at-tile-label">${esc(label)}</span>
            <strong class="at-tile-value">${s.load_pct === null ? '—' : fmt(s.load_pct, 1) + ' %'}</strong>
            <span class="at-tile-meta">${esc(t('green_count', {green: s.green, shifts: s.shifts}))} · ${fmt(s.required_h, 1)} / ${fmt(s.available_h, 1)} h</span></div>`;
        return `<div class="tfb-opt-tiles">${tile(t('now'), summary.before)}${tile(t('proposal'), summary.after)}</div>`;
    }

    async function render() {
        history.replaceState(null, '', '#from=' + from);
        el.innerHTML = `<div class="tfb-head"><div><h3>${esc(t('title'))}</h3><p class="text-muted mb-0">${esc(t('subtitle'))}</p></div>
            <div class="tfb-head-actions"><a class="btn btn-outline-primary btn-sm" href="${esc(api.root)}/workshop/gantt#from=${from}&view=week">${esc(t('gantt'))}</a>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-lang-switch>${esc(t('lang_switch'))}</button></div></div>
            <form class="tfb-filters" data-period><label class="tfb-inline">${esc(t('week_from'))} <input type="date" class="form-control form-control-sm" name="from" value="${from}"></label>
                <button class="btn btn-sm btn-outline-primary">${esc(t('show'))}</button></form>
            <div data-body><p class="text-muted">${esc(t('loading'))}</p></div>`;
        el.querySelector('[data-period]').addEventListener('submit', (e) => { e.preventDefault(); from = e.target.from.value || from; render(); });
        try {
            proposal = await api.get(`/planning/optimizer?from=${from}&to=${toOf(from)}`);
            draw();
        } catch (err) {
            el.querySelector('[data-body]').innerHTML = `<div class="alert alert-danger">${esc(err.message)}</div>`;
        }
    }

    function draw() {
        const rows = proposal.rows;
        const byDate = {};
        for (const r of rows) (byDate[r.date] ??= []).push(r);
        const body = el.querySelector('[data-body]');
        if (!rows.length) { body.innerHTML = `<p class="text-muted">${esc(t('empty'))}</p>`; return; }
        body.innerHTML = tiles(proposal.summary)
            + (proposal.notes || []).map(n => `<div class="alert alert-info py-2 small">${esc(t('note.' + n))}</div>`).join('')
            + `<div class="card"><div class="card-body pt-3"><div class="table-responsive"><table class="table table-sm tfb-opt-table">
                <thead><tr><th>${esc(t('col.group'))}</th><th>${esc(t('col.people'))}</th><th>${esc(t('col.overtime'))}</th><th>${esc(t('col.now'))}</th>
                    <th>${esc(t('col.actions'))}</th><th>${esc(t('col.after'))}</th><th>${esc(t('col.simulation'))}</th></tr></thead>
                ${Object.entries(byDate).map(([date, list]) => `<tbody><tr class="tfb-opt-day"><th colspan="7">${esc(new Date(date + 'T00:00:00').toLocaleDateString(api.lang === 'fr' ? 'fr-FR' : 'pl-PL', {weekday: 'long', day: '2-digit', month: '2-digit'}))}</th></tr>
                    ${list.map(r => `<tr data-key="${esc(r.key)}" data-date="${r.date}" data-group="${r.group_id}" data-shift="${r.shift_id}" style="--group-color:${esc(r.color || '#8D1D2C')}">
                        <td class="tfb-opt-group"><strong>${esc(r.group)}</strong><div class="small text-muted">${esc(r.shift)} · ${esc(r.start.slice(11, 16))}–${esc(r.end.slice(11, 16))}${r.staffing ? ' · ' + esc(t('staffing.' + r.staffing)) : ''}</div></td>
                        <td><input class="form-control form-control-sm tfb-opt-num" type="number" min="0" max="50" step="1" name="headcount" value="${r.headcount}" aria-label="${esc(t('col.people'))}">
                            ${r.base_headcount !== r.headcount ? `<div class="small text-muted">${esc(t('teams', {n: r.base_headcount}))}</div>` : ''}</td>
                        <td><input class="form-control form-control-sm tfb-opt-num" type="number" min="0" max="200" step="0.25" name="overtime_h" value="${r.overtime_h}" aria-label="${esc(t('col.overtime'))}"></td>
                        <td>${bar(r.before)}</td>
                        <td>${r.actions.length ? `<ul class="tfb-opt-actions">${r.actions.map(a => `<li class="is-${esc(a.lever)}">${esc(action(a))}</li>`).join('')}</ul>` : `<span class="text-muted small">${esc(r.before.status === 'ok' ? t('nothing_ok') : t('nothing'))}</span>`}</td>
                        <td>${bar(r.after)}</td>
                        <td data-sim><span class="text-muted small">—</span></td>
                    </tr>`).join('')}</tbody>`).join('')}
            </table></div>
            <div class="tfb-nav"><span class="small text-muted">${esc(t('rules'))}</span>
                <span class="d-flex gap-2 flex-wrap"><button type="button" class="btn btn-outline-primary" data-simulate>${esc(t('simulate'))}</button>
                ${canApply ? `<button type="button" class="btn btn-outline-primary" data-save-staffing disabled>${esc(t('save_staffing'))}</button><button type="button" class="btn btn-primary" data-apply>${esc(t('apply'))}</button>` : ''}</span></div>
            <div data-result></div></div></div>`;
        bind();
    }

    function edited() {
        return [...el.querySelectorAll('tr[data-key]')].map(row => {
            const r = proposal.rows.find(x => x.key === row.dataset.key);
            const headcount = Number(row.querySelector('[name=headcount]').value);
            const overtime = Number(row.querySelector('[name=overtime_h]').value || 0);
            return {row, r, data: {date: r.date, group_id: r.group_id, shift_id: r.shift_id, headcount, overtime_h: overtime}, changed: headcount !== r.headcount || Math.abs(overtime - r.overtime_h) > 0.001};
        });
    }

    function bind() {
        const save = el.querySelector('[data-save-staffing]');
        el.querySelector('.tfb-opt-table').addEventListener('input', () => { if (save) save.disabled = !edited().some(e => e.changed); });
        el.querySelector('[data-simulate]').addEventListener('click', async (e) => {
            e.target.disabled = true;
            try {
                const list = edited();
                const sim = await api.post('/planning/simulate', {from, to: toOf(from), staffing: list.filter(x => x.changed).map(x => x.data)});
                for (const x of list) {
                    const s = sim.rows.find(r => r.key === x.r.key);
                    x.row.querySelector('[data-sim]').innerHTML = s ? bar(s.after) : `<span class="text-muted small">${esc(t('status.closed'))}</span>`;
                }
                el.querySelector('[data-result]').innerHTML = `<div class="alert alert-info mt-3 mb-0">${esc(t('sim_summary', {pct: sim.summary.load_pct === null ? '—' : fmt(sim.summary.load_pct, 1), green: sim.summary.green, shifts: sim.summary.shifts}))}</div>`;
            } catch (err) {
                const box = el.querySelector('.tfb-opt-table');
                const orphans = ui.fieldErrors(box, err);
                ui.notify(false, orphans.length ? orphans.join(' ') : ((err.body && err.body.errors) || []).map(x => x.message).join(' ') || err.message);
            }
            e.target.disabled = false;
        });
        if (save) save.addEventListener('click', async () => {
            save.disabled = true;
            try {
                for (const x of edited().filter(x => x.changed)) await api.put('/planning/staffing', {...x.data, note: null});
                ui.notify(true, t('saved'));
                render();
            } catch (err) { save.disabled = false; ui.notify(false, ((err.body && err.body.errors) || []).map(x => x.message).join(' ') || err.message); }
        });
        const apply = el.querySelector('[data-apply]');
        if (apply) apply.addEventListener('click', async () => {
            if (!window.confirm(t('apply_confirm'))) return;
            apply.disabled = true;
            try {
                const r = await api.post('/planning/optimizer/apply', {from, to: toOf(from)});
                ui.notify(true, t('applied'));
                await render();
                el.querySelector('[data-result]').innerHTML = `<div class="alert ${r.conflicts.length ? 'alert-warning' : 'alert-success'} mt-3 mb-0">
                    ${esc(t('apply_result', {staffing: r.staffing_rows, orders: r.mts_orders, ops: r.operations, before: fmt(r.summary_before.load_pct, 1), after: fmt(r.summary_after.load_pct, 1)}))}
                    ${r.mts_skipped_no_plan ? '<br>' + esc(t('skipped_no_plan', {n: r.mts_skipped_no_plan})) : ''}
                    ${r.mts_not_placed ? '<br>' + esc(t('not_placed', {n: r.mts_not_placed})) : ''}
                    ${r.conflicts.length ? `<ul class="mb-0 mt-1">${r.conflicts.map(c => `<li>${esc(c.message)}</li>`).join('')}</ul>` : ''}</div>`;
            } catch (err) { apply.disabled = false; ui.notify(false, err.message); }
        });
    }

    el.addEventListener('click', async (e) => {
        if (!e.target.closest('[data-lang-switch]')) return;
        await api.setLang(api.lang === 'pl' ? 'fr' : 'pl');
        groups = (await api.list('/referentiels/groups')).data;
        render();
    });
    render();
})();
