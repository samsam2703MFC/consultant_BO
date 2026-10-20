/*
 * Plan pracowni / Plan de l'atelier (P3) : besoins MTO / MTS / semi-finis d'une période,
 * création d'un plan proposé, ajustement des quantités (avec motif), validation et OF.
 * Tout passe par l'API /api/v1/production/planning.
 */
(async function () {
    'use strict';
    const el = document.getElementById('tfbPlanning');
    if (!el) return;
    const {WorkshopApi, ui} = window.TfbWorkshop;
    const api = new WorkshopApi(el);
    const esc = ui.esc;
    let groups = [];
    let canWrite = false;

    try {
        await api.init();
        const [g, me] = await Promise.all([api.list('/referentiels/groups'), api.get('/auth/me')]);
        groups = g.data;
        canWrite = me.permissions.includes('prod.plan.validate');
    } catch (e) {
        el.innerHTML = `<div class="alert alert-danger">${esc(e.message)}</div>`;
        return;
    }
    const t = (k, p) => api.t(k, p);
    const fmt = (n, d) => ui.fmt(n, d ?? 1, api.lang);
    const groupName = (id) => id ? ((groups.find(g => g.id === id) || {}).name || '—') : t('no_group');
    const today = new Date();
    const iso = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    const fail = (container, err) => { const o = container ? ui.fieldErrors(container, err) : []; ui.notify(false, o.length ? o.join(' ') : err.message); };

    function header(extra) {
        return `<div class="tfb-head"><div><h3>${esc(t('title'))}</h3><p class="text-muted mb-0">${esc(t('subtitle'))}</p></div>
            <div class="tfb-head-actions">${extra || ''}<button type="button" class="btn btn-outline-secondary btn-sm" data-lang-switch>${esc(t('lang_switch'))}</button></div></div>`;
    }

    function hoursTable(rows) {
        const total = rows.reduce((s, r) => s + r.hours, 0);
        return `<table class="table table-sm mb-0"><tbody>${rows.map(r => `<tr><td>${esc(groupName(r.group_id))}</td><td class="text-end">${fmt(r.hours, 1)} ${esc(t('unit.h'))}</td></tr>`).join('')}
            <tr><th>${esc(t('total'))}</th><th class="text-end">${fmt(total, 1)} ${esc(t('unit.h'))}</th></tr></tbody></table>`;
    }

    // --- Accueil : période, besoins, plans ------------------------------------------------
    async function renderHome() {
        const params = new URLSearchParams(location.hash.split('?')[1] || '');
        const from = params.get('from') || iso(today);
        const to = params.get('to') || iso(new Date(today.getTime() + 6 * 86400000));
        el.innerHTML = header() + `
            <form class="tfb-filters" data-period>
                <label class="tfb-inline">${esc(t('from'))} <input type="date" class="form-control form-control-sm" name="from" data-field="from" value="${from}"></label>
                <label class="tfb-inline">${esc(t('to'))} <input type="date" class="form-control form-control-sm" name="to" data-field="to" value="${to}"></label>
                <button class="btn btn-sm btn-outline-primary">${esc(t('show_demand'))}</button>
                ${canWrite ? `<button type="button" class="btn btn-sm btn-primary" data-create>${esc(t('create_plan'))}</button>` : ''}
            </form>
            <div class="row"><div class="col-12 col-xl-9"><div class="card"><div class="card-header"><h4 class="card-title">${esc(t('demand'))}</h4></div><div class="card-body pt-0" data-demand>${esc(t('loading'))}</div></div></div>
            <div class="col-12 col-xl-3"><div class="card"><div class="card-header"><h4 class="card-title">${esc(t('plans'))}</h4></div><div class="card-body pt-0" data-plans>${esc(t('loading'))}</div></div></div></div>`;
        const form = el.querySelector('[data-period]');
        form.addEventListener('submit', (e) => { e.preventDefault(); location.hash = `#/?from=${form.from.value}&to=${form.to.value}`; });
        const create = el.querySelector('[data-create]');
        if (create) create.addEventListener('click', async () => {
            create.disabled = true;
            try {
                const plan = await api.post('/planning/plans', {from: form.from.value, to: form.to.value});
                location.hash = `#/plan/${plan.id}`;
            } catch (err) { create.disabled = false; fail(form, err); }
        });
        const plans = await api.list('/planning/plans');
        el.querySelector('[data-plans]').innerHTML = plans.data.length ? `<ul class="tfb-plan-list">${plans.data.map(p => `<li><a href="#/plan/${p.id}">
            <strong>${esc(p.period_start)} → ${esc(p.period_end)}</strong> <span class="badge ${p.status === 'validated' ? 'bg-success' : 'bg-warning'}">${esc(t('status.' + p.status))}</span>
            <span class="text-muted d-block small">v${p.version} · ${p.line_count} ${esc(t('col.lines')).toLowerCase()} · ${fmt(p.standard_hours, 1)} ${esc(t('unit.h'))}</span></a></li>`).join('')}</ul>` : `<p class="text-muted">${esc(t('no_plans'))}</p>`;
        try {
            const d = await api.get(`/planning/demand?from=${from}&to=${to}`);
            el.querySelector('[data-demand]').innerHTML = demandTable(d);
        } catch (err) { el.querySelector('[data-demand]').innerHTML = `<div class="alert alert-danger">${esc(err.message)}</div>`; }
    }

    function demandTable(d) {
        if (!d.lines.length) return `<p class="text-muted">${esc(t('no_demand'))}</p>` + warnings(d.warnings);
        const names = Object.fromEntries(d.lines.map(l => [l.item_id, l.name]));
        const byDate = {};
        const hours = {};
        for (const l of d.lines) {
            (byDate[l.due_date] ??= []).push(l);
            hours[l.group_id || 0] = (hours[l.group_id || 0] || 0) + l.standard_hours;
        }
        return warnings(d.warnings) + Object.entries(byDate).map(([date, lines]) => `<h6 class="mt-3">${esc(date)} <span class="text-muted small">· ${esc(t('col.due'))} ${esc((lines[0].due_at || '').slice(11, 16))}</span></h6>
            <div class="table-responsive"><table class="table table-sm tfb-plan-table"><thead><tr><th>${esc(t('col.item'))}</th><th>${esc(t('col.mode'))}</th>
            <th class="text-end">${esc(t('col.mto'))}</th><th class="text-end">${esc(t('col.mts'))}</th><th class="text-end">${esc(t('col.semi'))}</th>
            <th class="text-end">${esc(t('col.proposed'))}</th><th class="text-end">${esc(t('col.hours'))}</th></tr></thead><tbody>
            ${lines.map(l => `<tr><td><strong>${esc(l.name)}</strong><div class="small text-muted">${esc(groupName(l.group_id))}
                ${l.late_orders ? ' · <span class="text-danger">' + esc(t('late', {n: l.late_orders})) + '</span>' : ''}
                ${l.mts ? ' · ' + esc(t('mts_info', {projected: fmt(l.mts.projected, 0), target: fmt(l.mts.target, 0), daily: fmt(l.mts.daily, 1)})) + (l.mts.capped ? ' · ' + esc(t('mts_capped')) : '') : ''}
                ${l.parents.length ? ' · ' + esc(t('for_parents', {names: [...new Set(l.parents.map(p => names[p.item_id] || '#' + p.item_id))].join(', ')})) : ''}</div></td>
                <td>${esc(l.item_type === 'semi' ? t('origin.SEMI') : t('mode.' + l.mode))}</td>
                <td class="text-end">${l.qty_mto ? fmt(l.qty_mto, 0) : ''}</td><td class="text-end">${l.qty_mts ? fmt(l.qty_mts, 0) : ''}</td><td class="text-end">${l.qty_semi ? fmt(l.qty_semi, 2) : ''}</td>
                <td class="text-end"><strong>${fmt(l.qty_proposed, l.unit === 'kg' ? 1 : 0)}</strong> ${esc(l.unit)}${cartons(l.qty_proposed, l)}</td><td class="text-end">${fmt(l.standard_hours, 2)}</td></tr>`).join('')}
            </tbody></table></div>`).join('') + `<h6 class="mt-3">${esc(t('hours_by_group'))}</h6>` + hoursTable(Object.entries(hours).map(([g, h]) => ({group_id: Number(g) || null, hours: h})));
    }


    // Sztuki → kartony (produkty) : klient zamawia kartony, w kartonie są sztuki do produkcji.
    function cartons(q, l) {
        if (!l.ppc || l.item_type === 'semi' || !q) return '';
        return `<div class="small text-muted">= ${fmt(q / l.ppc, 1)} ${esc(t('cartons_short'))} × ${l.ppc}</div>`;
    }

    // Ilość surowca : gramy / mililitry powyżej 1000 jako kg / l.
    function amount(q, unit) {
        if (unit === 'g' && Math.abs(q) >= 1000) return fmt(q / 1000, 1) + ' kg';
        if (unit === 'ml' && Math.abs(q) >= 1000) return fmt(q / 1000, 1) + ' l';
        return fmt(q, q >= 100 ? 0 : 2) + ' ' + (unit || '');
    }

    // Surowce i opakowania planu : sztuki ÷ szt./karton × receptura na karton.
    function materialsCard(list) {
        const table = (rows) => `<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>${esc(t('mat.name'))}</th>
            <th class="text-end">${esc(t('mat.quantity'))}</th><th class="text-end">${esc(t('mat.value'))}</th><th class="text-end">${esc(t('mat.products'))}</th></tr></thead>
            <tbody>${rows.map(m => `<tr><td>${esc(m.name)}</td><td class="text-end text-nowrap">${esc(amount(m.quantity, m.unit))}</td>
                <td class="text-end text-nowrap">${m.value !== null ? fmt(m.value, 0) + ' zł' : '—'}</td><td class="text-end">${m.products}</td></tr>`).join('')}</tbody>
            <tfoot><tr><th>${esc(t('total'))}</th><th></th><th class="text-end text-nowrap">${fmt(rows.reduce((a, m) => a + (m.value || 0), 0), 0)} zł</th><th></th></tr></tfoot></table></div>`;
        const raw = list.filter(m => !m.packaging);
        const pack = list.filter(m => m.packaging);
        return `<div class="card"><div class="card-header"><h4 class="card-title">${esc(t('mat.title'))}</h4>
            <p class="text-muted small mb-0">${esc(t('mat.help'))}</p></div><div class="card-body pt-0">
            ${list.length ? `${raw.length ? `<h6>${esc(t('mat.raw', {n: raw.length}))}</h6>${table(raw)}` : ''}
                ${pack.length ? `<details class="mt-3"><summary class="fw-semibold">${esc(t('mat.packaging', {n: pack.length}))}</summary>${table(pack)}</details>` : ''}`
                : `<p class="text-muted mb-0">${esc(t('mat.empty'))}</p>`}</div></div>`;
    }

    function warnings(list) {
        return (list || []).map(w => `<div class="alert alert-warning py-2 small mb-2">${esc(w.message)}</div>`).join('');
    }

    // --- Plan : lignes ajustables, validation, OF ---------------------------------------------
    async function renderPlan(id) {
        const plan = await api.get(`/planning/plans/${id}`);
        const editable = canWrite && plan.status === 'proposed';
        el.innerHTML = header(`<a class="btn btn-outline-primary btn-sm" href="#/?from=${plan.period_start}&to=${plan.period_end}">${esc(t('back'))}</a>`) + `
            <div class="tfb-item-title"><h4>${esc(plan.period_start)} → ${esc(plan.period_end)}</h4>
                <span class="badge ${plan.status === 'validated' ? 'bg-success' : 'bg-warning'}">${esc(t('status.' + plan.status))}</span>
                <span class="text-muted">v${plan.version}${plan.created_by ? ' · ' + esc(plan.created_by) : ''}${plan.validated_by ? ' · ✓ ' + esc(plan.validated_by) + ' ' + esc((plan.validated_at || '').slice(0, 16)) : ''}</span></div>
            ${!canWrite ? `<div class="alert alert-info">${esc(t('read_only'))}</div>` : ''}
            <div class="row"><div class="col-12"><div class="card"><div class="card-body" data-lines>
                <div class="table-responsive"><table class="table table-sm tfb-plan-table"><thead><tr><th>${esc(t('col.due'))}</th><th>${esc(t('col.item'))}</th>
                    <th class="text-end">${esc(t('col.mto'))}</th><th class="text-end">${esc(t('col.mts'))}</th><th class="text-end">${esc(t('col.semi'))}</th>
                    <th class="text-end">${esc(t('col.proposed'))}</th><th style="min-width:120px">${esc(t('col.final'))}</th><th style="min-width:160px">${esc(t('col.reason'))}</th><th class="text-end">${esc(t('col.hours'))}</th></tr></thead>
                <tbody>${plan.lines.map(l => `<tr data-line="${l.id}" data-proposed="${l.qty_proposed}">
                    <td class="text-nowrap">${esc(l.due_date)}</td><td><strong>${esc(l.name)}</strong><div class="small text-muted">${esc(groupName(l.group_id))}</div></td>
                    <td class="text-end">${l.qty_mto ? fmt(l.qty_mto, 0) : ''}</td><td class="text-end">${l.qty_mts ? fmt(l.qty_mts, 0) : ''}</td><td class="text-end">${l.qty_semi ? fmt(l.qty_semi, 2) : ''}</td>
                    <td class="text-end">${fmt(l.qty_proposed, l.unit === 'kg' ? 1 : 0)} ${esc(l.unit)}${cartons(l.qty_proposed, l)}</td>
                    <td>${editable ? `<input class="form-control form-control-sm" inputmode="decimal" data-field="qty_final" value="${l.qty_final}">` : `<strong>${fmt(l.qty_final, l.unit === 'kg' ? 1 : 0)}</strong>`}${cartons(l.qty_final, l)}</td>
                    <td>${editable ? `<input class="form-control form-control-sm" data-field="adjust_reason" value="${esc(l.adjust_reason || '')}" maxlength="255">` : esc(l.adjust_reason || '')}</td>
                    <td class="text-end">${fmt(l.standard_hours, 2)}</td></tr>`).join('')}</tbody></table></div>
                ${editable ? `<div class="tfb-nav"><span></span><button type="button" class="btn btn-primary" data-validate>${esc(t('validate'))}</button></div>` : ''}
                ${canWrite && ['validated', 'frozen'].includes(plan.status) ? `<div class="tfb-nav"><a class="btn btn-outline-primary" href="${esc(api.root)}/workshop/gantt#from=${plan.period_start}&view=week">${esc(t('open_gantt'))}</a>
                    <button type="button" class="btn btn-primary" data-schedule>${esc(t('schedule'))}</button></div><div data-schedule-result></div>` : ''}
            </div></div></div>
            <div class="col-12 col-lg-6 col-xl-4"><div class="card"><div class="card-header"><h4 class="card-title">${esc(t('hours_by_group'))}</h4></div><div class="card-body pt-0">
                ${hoursTable(plan.hours_by_group.map(h => ({group_id: h.group_id, hours: h.standard_hours})))}</div></div></div>
            <div class="col-12 col-lg-6 col-xl-8">${materialsCard(plan.materials || [])}</div></div>
            ${plan.orders.length ? `<div class="card"><div class="card-header"><h4 class="card-title">${esc(t('orders'))}</h4></div><div class="card-body pt-0"><div class="table-responsive">
                <table class="table table-sm"><thead><tr><th>${esc(t('col.code'))}</th><th>${esc(t('col.item'))}</th><th>${esc(t('col.origin'))}</th><th class="text-end">${esc(t('col.qty'))}</th><th>${esc(t('col.due'))}</th><th class="text-end">${esc(t('col.customers'))}</th></tr></thead>
                <tbody>${plan.orders.map(o => `<tr><td><strong>${esc(o.code)}</strong></td><td>${esc(o.name)}${o.parent_order_id ? ` <span class="small text-muted">← ${esc((plan.orders.find(p => p.id === o.parent_order_id) || {}).code || '')}</span>` : ''}</td>
                    <td>${esc(t('origin.' + o.origin))}</td><td class="text-end">${fmt(o.quantity, o.unit === 'kg' ? 1 : 0)} ${esc(o.unit)}</td><td class="text-nowrap">${esc(o.due_at.slice(0, 16))}</td><td class="text-end">${o.customer_orders || ''}</td></tr>`).join('')}</tbody></table></div></div></div>` : ''}`;
        const schedule = el.querySelector('[data-schedule]');
        if (schedule) schedule.addEventListener('click', async () => {
            schedule.disabled = true;
            try {
                const r = await api.post(`/planning/plans/${id}/schedule`);
                ui.notify(true, t('schedule_done', {n: r.operations}));
                el.querySelector('[data-schedule-result]').innerHTML = `<div class="alert ${r.conflicts.length ? 'alert-warning' : 'alert-success'} mt-3 mb-0">
                    <strong>${esc(t('schedule_done', {n: r.operations}))}</strong>
                    ${r.conflicts.length ? `<div class="mt-1">${esc(t('conflicts'))}:</div><ul class="mb-0">${r.conflicts.map(c => `<li>${esc(c.message)}</li>`).join('')}</ul>` : ''}</div>`;
            } catch (err) { fail(null, err); }
            schedule.disabled = false;
        });
        if (!editable) return;
        el.querySelectorAll('[data-line]').forEach(row => row.addEventListener('change', async () => {
            const qty = ui.num(row.querySelector('[data-field="qty_final"]').value);
            const reason = row.querySelector('[data-field="adjust_reason"]').value.trim();
            if (Number(qty) !== Number(row.dataset.proposed) && !reason) { row.querySelector('[data-field="adjust_reason"]').focus(); ui.notify(false, t('reason_needed')); return; }
            ui.clearErrors(row);
            try {
                await api.patch(`/planning/plans/${id}/lines/${row.dataset.line}`, {qty_final: qty, adjust_reason: reason || null});
                ui.notify(true, t('saved'));
                renderPlan(id);
            } catch (err) { fail(row, err); }
        }));
        el.querySelector('[data-validate]').addEventListener('click', async (e) => {
            if (!window.confirm(t('validate_confirm'))) return;
            e.target.disabled = true;
            try { await api.post(`/planning/plans/${id}/validate`); ui.notify(true, t('saved')); renderPlan(id); }
            catch (err) { e.target.disabled = false; fail(null, err); }
        });
    }

    async function route() {
        const path = (location.hash.replace(/^#/, '') || '/').split('?')[0];
        try {
            const m = path.match(/^\/plan\/(\d+)$/);
            if (m) await renderPlan(Number(m[1])); else await renderHome();
        } catch (err) {
            el.innerHTML = header() + `<div class="alert alert-danger">${esc(err.message)}</div>`;
        }
    }
    el.addEventListener('click', async (e) => {
        if (!e.target.closest('[data-lang-switch]')) return;
        await api.setLang(api.lang === 'pl' ? 'fr' : 'pl');
        groups = (await api.list('/referentiels/groups')).data;
        route();
    });
    window.addEventListener('hashchange', route);
    route();
})();
