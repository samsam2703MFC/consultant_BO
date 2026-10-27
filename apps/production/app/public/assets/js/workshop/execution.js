/*
 * Hala / Atelier (P7) — kierownik : kokpit dnia i wskaźniki, tablety (parowanie), identyfikatory i PIN
 * osób, pytania przy wejściu / wyjściu, godziny pracy miesiąca (druk karty, wpis ręczny). Wszystko przez API /api/v1/production.
 */
(async function () {
    'use strict';
    const el = document.getElementById('tfbExecution');
    if (!el) return;
    const {WorkshopApi, ui} = window.TfbWorkshop;
    const api = new WorkshopApi(el);
    const esc = ui.esc;
    let groups = [];
    let perms = [];
    try {
        await api.init();
        const [g, me] = await Promise.all([api.list('/referentiels/groups'), api.get('/auth/me')]);
        groups = g.data;
        perms = me.permissions;
    } catch (e) {
        el.innerHTML = `<div class="alert alert-danger">${esc(e.message)}</div>`;
        return;
    }
    const t = (k, p) => api.t(k, p);
    const fmt = (n, d) => ui.fmt(n, d ?? 1, api.lang);
    const pct = (v) => v === null || v === undefined ? '—' : fmt(v, 1) + ' %';
    const iso = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    const canManage = perms.includes('prod.employees.write');
    const tabs = ['cockpit', ...(canManage ? ['devices', 'people', 'questions', 'hours'] : [])];
    let tab = tabs.includes(location.hash.slice(1)) ? location.hash.slice(1) : 'cockpit';
    const groupOptions = (selected, empty) => `<option value="">${esc(empty)}</option>` + groups.map(g => `<option value="${g.id}" ${g.id === selected ? 'selected' : ''}>${esc(g.name)}</option>`).join('');
    const fail = (err, box) => { const o = box ? ui.fieldErrors(box, err) : []; ui.notify(false, o.length ? o.join(' ') : (((err.body && err.body.errors) || []).map(x => x.message).join(' ') || err.message)); };

    function frame(body) {
        el.innerHTML = `<div class="tfb-head"><div><h3>${esc(t('title'))}</h3><p class="text-muted mb-0">${esc(t('subtitle'))}</p></div>
            <div class="tfb-head-actions"><a class="btn btn-outline-primary btn-sm" href="${esc(api.root)}/kiosk" target="_blank" rel="noopener">${esc(t('open_kiosk'))}</a>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-lang-switch>${esc(t('lang_switch'))}</button></div></div>
            <ul class="nav nav-tabs mb-3" role="tablist">${tabs.map(k => `<li class="nav-item"><a class="nav-link ${k === tab ? 'active' : ''}" href="#${k}" data-tab="${k}">${esc(t('tab.' + k))}</a></li>`).join('')}</ul>
            <div data-body>${body || `<p class="text-muted">${esc(t('loading'))}</p>`}</div>`;
    }

    async function render() {
        frame();
        try { await ({cockpit, devices, people, questions, hours})[tab](); } catch (err) { el.querySelector('[data-body]').innerHTML = `<div class="alert alert-danger">${esc(err.message)}</div>`; }
    }

    // --- Kokpit i wskaźniki -------------------------------------------------------------------------
    async function cockpit() {
        const from = iso(new Date(Date.now() - ((new Date().getDay() + 6) % 7) * 86400000));
        const c = await api.get('/execution/cockpit');
        const body = el.querySelector('[data-body]');
        body.innerHTML = `<div class="tfb-opt-tiles tfb-exec-tiles">
                <div class="at-tile"><span class="at-tile-label">${esc(t('present_now'))}</span><strong class="at-tile-value">${c.people_present}</strong><span class="at-tile-meta">${c.people_by_group.map(g => esc(g.group) + ': ' + g.count).join(' · ') || '—'}</span></div>
                <div class="at-tile ${c.operations.late ? 'at-tile-danger' : ''}"><span class="at-tile-label">${esc(t('ops_today'))}</span><strong class="at-tile-value">${c.operations.done} / ${c.operations.total}</strong>
                    <span class="at-tile-meta">${esc(t('ops_detail', {progress: c.operations.in_progress, late: c.operations.late}))}</span></div>
                <div class="at-tile ${c.mto_at_risk.length ? 'at-tile-danger' : 'at-tile-ok'}"><span class="at-tile-label">${esc(t('mto_today'))}</span><strong class="at-tile-value">${c.mto_due_today}</strong>
                    <span class="at-tile-meta">${c.mto_at_risk.length ? esc(t('at_risk', {codes: c.mto_at_risk.map(o => o.code).join(', ')})) : esc(t('no_risk'))}</span></div>
            </div>
            ${c.alerts.map(a => `<div class="alert alert-warning py-2 small">${esc(t('alert.' + a.code, {name: a.name || '', since: (a.since || '').slice(0, 16), order: a.order || '', due: (a.due_at || '').slice(11, 16), count: a.count || ''}))}</div>`).join('')}
            <div class="card"><div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center"><h4 class="card-title mb-0">${esc(t('kpis'))}</h4>
                <form class="tfb-filters mb-0" data-kpi-form><label class="tfb-inline">${esc(t('from'))} <input type="date" class="form-control form-control-sm" name="from" value="${from}"></label>
                <label class="tfb-inline">${esc(t('to'))} <input type="date" class="form-control form-control-sm" name="to" value="${iso(new Date())}"></label>
                <button class="btn btn-sm btn-outline-primary">${esc(t('show'))}</button></form></div>
                <div class="card-body pt-0" data-kpis>${esc(t('loading'))}</div></div>`;
        const form = body.querySelector('[data-kpi-form]');
        const load = async () => {
            try {
                const k = await api.get(`/execution/kpis?from=${form.from.value}&to=${form.to.value}`);
                body.querySelector('[data-kpis]').innerHTML = kpiTable(k);
            } catch (err) { body.querySelector('[data-kpis]').innerHTML = `<div class="alert alert-danger">${esc(err.message)}</div>`; }
        };
        form.addEventListener('submit', (e) => { e.preventDefault(); load(); });
        load();
    }

    function kpiTable(k) {
        const cols = ['punctuality_pct', 'efficiency_pct', 'utilisation_pct', 'attendance_pct', 'scrap_pct'];
        const row = (label, v, extra) => `<tr><td><strong>${esc(label)}</strong>${extra || ''}</td>${cols.map(c => `<td class="text-end">${pct(v[c])}</td>`).join('')}
            <td class="text-end">${fmt(v.present_h, 1)} h</td><td class="text-end">${v.operations_done} / ${v.operations}</td></tr>`;
        return `<div class="tfb-opt-tiles tfb-exec-tiles mb-3"><div class="at-tile"><span class="at-tile-label">${esc(t('otd'))}</span><strong class="at-tile-value">${pct(k.totals.otd_pct)}</strong>
                <span class="at-tile-meta">${esc(t('otd_detail', {ok: k.totals.mto_on_time, late: k.totals.mto_late, total: k.totals.mto_orders}))}</span></div>
                <div class="at-tile"><span class="at-tile-label">${esc(t('kpi.efficiency_pct'))}</span><strong class="at-tile-value">${pct(k.totals.efficiency_pct)}</strong>
                <span class="at-tile-meta">${esc(t('efficiency_detail', {std: fmt(k.totals.standard_h, 1), real: fmt(k.totals.worked_on_done_h, 1)}))}</span></div></div>
            <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>${esc(t('group'))}</th>${cols.map(c => `<th class="text-end">${esc(t('kpi.' + c))}</th>`).join('')}
                <th class="text-end">${esc(t('present_h'))}</th><th class="text-end">${esc(t('ops_done'))}</th></tr></thead>
            <tbody>${k.groups.filter(g => g.operations || g.present_h).map(g => row(g.group, g)).join('') || `<tr><td colspan="8" class="text-muted">${esc(t('no_data'))}</td></tr>`}</tbody>
            <tfoot>${row(t('total'), k.totals)}</tfoot></table></div>
            <p class="at-profit-note">${esc(t('kpi_help'))}</p>`;
    }

    // --- Tablety -----------------------------------------------------------------------------------
    async function devices() {
        const [list, vehicles] = await Promise.all([api.list('/kiosk/devices').then(r => r.data), api.list('/kiosk/devices/vehicles').then(r => r.data).catch(() => [])]);
        const body = el.querySelector('[data-body]');
        // Tablet kierowcy : pojazd domyślny zamiast grupy (strona /driver na tablecie).
        const vehicleOptions = (selected) => `<option value="">${esc(t('no_vehicle'))}</option>` + vehicles.map(v => `<option value="${v.id}" ${v.id === selected ? 'selected' : ''}>${esc(v.label)}${v.carrier ? ' · ' + esc(v.carrier) : ''}${v.driver ? ' · ' + esc(v.driver) : ''}</option>`).join('');
        // Rodzaj tabletu zmieniany na sparowanym tablecie (bez nowego kodu) : tablet grupy → biurowy (wszystkie działy, /office)
        // albo kierowcy ; tablet grupy — jego grupa, tablet kierowcy — pojazd domyślny.
        const modeSelect = (d) => `<select class="form-select form-select-sm d-inline-block w-auto" style="max-width:250px" data-dev-mode aria-label="${esc(t('device_mode'))}">${['atelier', 'office', 'driver']
            .map(m => `<option value="${m}" ${(d.mode || 'atelier') === m ? 'selected' : ''}>${esc(t('mode_' + m))}</option>`).join('')}</select>`;
        const where = (d) => d.mode === 'driver'
            ? `<select class="form-select form-select-sm d-inline-block w-auto" style="max-width:250px" data-vehicle aria-label="${esc(t('vehicle'))}">${vehicleOptions(d.vehicle_id)}</select>`
            : d.mode === 'office' ? `<span class="small text-muted">${esc(t('office_all'))}</span>`
            : `<select class="form-select form-select-sm d-inline-block w-auto" style="max-width:220px" data-dev-group aria-label="${esc(t('group'))}">${groupOptions(d.group_id, t('device_generic'))}</select>`;
        // Plan tabletów : 1 tablet biurowy (wejście / wyjście), 1 tablet kierowcy, 1 tablet na każdą grupę produkcji ;
        // brakujący tablet tworzy się jednym kliknięciem (kod parowania), sparowany pokazuje stan i ostatnie połączenie.
        const state = (d) => !d ? `<span class="badge bg-danger-subtle text-danger-emphasis">${esc(t('plan_missing'))}</span>`
            : !d.active ? `<span class="badge bg-secondary">${esc(t('blocked'))}</span>`
            : d.paired ? `<span class="badge bg-success">${esc(t('paired'))}</span> <span class="text-muted small">${esc((d.last_seen_at || '').slice(0, 16))}</span>`
            : `<span class="badge bg-warning">${esc(d.pairing_pending ? t('waiting_pairing') : t('not_paired'))}</span>`;
        // Kilka tabletów tego samego rodzaju : aktywny i sparowany, ostatnio połączony.
        const pick = (filter) => list.filter(filter).sort((a, b) => (b.active - a.active) || (b.paired - a.paired) || String(b.last_seen_at || '').localeCompare(String(a.last_seen_at || '')))[0];
        const planRows = [
            {kind: t('plan_office'), d: pick(x => x.mode === 'office'), mode: 'office', name: t('plan_office_name'), page: '/office'},
            {kind: t('plan_driver'), d: pick(x => x.mode === 'driver'), mode: 'driver', name: t('plan_driver_name'), page: '/driver'},
            ...groups.filter(g => g.active !== false).map(g => ({kind: t('plan_group', {group: g.name}), d: pick(x => (x.mode || 'atelier') === 'atelier' && x.group_id === g.id), mode: 'atelier', group: g.id, name: t('plan_group_name', {group: g.name}), page: '/kiosk'})),
        ];
        const missing = planRows.filter(r => !r.d).length;
        const plan = `<div class="card mb-3"><div class="card-body"><div class="d-flex flex-wrap align-items-center gap-2 mb-2"><h5 class="mb-0">${esc(t('plan_title'))}</h5>
                <span class="text-muted small">${esc(missing ? t('plan_missing_count', {n: missing}) : t('plan_complete'))}</span></div>
            <div class="table-responsive"><table class="table table-sm mb-0 align-middle"><thead><tr><th>${esc(t('plan_kind'))}</th><th>${esc(t('device'))}</th><th>${esc(t('state'))}</th><th>${esc(t('plan_page'))}</th><th></th></tr></thead>
            <tbody>${planRows.map((r, i) => `<tr><td>${esc(r.kind)}</td><td>${r.d ? `<strong>${esc(r.d.name)}</strong>` : '<span class="text-muted">—</span>'}</td><td>${state(r.d)}</td>
                <td><code>${esc(r.page)}</code></td>
                <td class="text-end">${r.d ? '' : `<button type="button" class="btn btn-sm btn-primary" data-plan-create="${i}">${esc(t('plan_create'))}</button>`}</td></tr>`).join('')}</tbody></table></div>
            <p class="at-profit-note mb-0">${esc(t('plan_help'))}</p></div></div>`;
        body.innerHTML = plan + `<div class="card"><div class="card-body">
            <form class="tfb-filters" data-add-device><input class="form-control form-control-sm" name="name" data-field="name" placeholder="${esc(t('device_name'))}" required maxlength="100">
                <select class="form-select form-select-sm" name="mode" data-field="mode" aria-label="${esc(t('device_mode'))}"><option value="atelier">${esc(t('mode_atelier'))}</option><option value="driver">${esc(t('mode_driver'))}</option><option value="office">${esc(t('mode_office'))}</option></select>
                <select class="form-select form-select-sm" name="group_id" data-field="group_id">${groupOptions(null, t('device_generic'))}</select>
                <select class="form-select form-select-sm" name="vehicle_id" data-field="vehicle_id" hidden aria-label="${esc(t('vehicle'))}">${vehicleOptions(null)}</select>
                <button class="btn btn-sm btn-primary">${esc(t('add_device'))}</button></form>
            <div data-code></div>
            <div class="table-responsive"><table class="table table-sm mb-0 align-middle"><thead><tr><th>${esc(t('device'))}</th><th>${esc(t('plan_kind'))}</th><th>${esc(t('group'))}</th><th>${esc(t('state'))}</th><th>${esc(t('last_seen'))}</th><th></th></tr></thead>
            <tbody>${list.map(d => `<tr data-device="${d.id}"><td><strong>${esc(d.name)}</strong></td><td>${modeSelect(d)}</td><td>${where(d)}</td>
                <td>${!d.active ? `<span class="badge bg-secondary">${esc(t('blocked'))}</span>` : d.paired ? `<span class="badge bg-success">${esc(t('paired'))}</span>` : `<span class="badge bg-warning">${esc(d.pairing_pending ? t('waiting_pairing') : t('not_paired'))}</span>`}</td>
                <td>${esc((d.last_seen_at || '—').slice(0, 16))}</td>
                <td class="text-end"><button type="button" class="btn btn-sm btn-outline-primary" data-repair>${esc(t('new_code'))}</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-toggle>${esc(d.active ? t('block') : t('unblock'))}</button></td></tr>`).join('') || `<tr><td colspan="6" class="text-muted">${esc(t('no_devices'))}</td></tr>`}</tbody></table></div>
            <p class="at-profit-note">${esc(t('device_help'))}</p><p class="at-profit-note">${esc(t('driver_help'))}</p><p class="at-profit-note">${esc(t('office_help'))}</p></div></div>`;
        const form = body.querySelector('[data-add-device]');
        form.mode.addEventListener('change', () => { const driver = form.mode.value === 'driver'; form.group_id.hidden = driver || form.mode.value === 'office'; form.vehicle_id.hidden = !driver; });
        const showCode = (r) => {
            body.querySelector('[data-code]').innerHTML = `<div class="alert alert-success tfb-exec-code"><div>${esc(t('pairing_code'))}</div><strong>${esc(r.pairing_code.slice(0, 4))}-${esc(r.pairing_code.slice(4))}</strong>
                <div class="small">${esc(t('code_valid', {until: r.expires_at.slice(11, 16)}))}</div></div>`;
        };
        body.querySelector('[data-add-device]').addEventListener('submit', async (e) => {
            e.preventDefault();
            try {
                const driver = e.target.mode.value === 'driver';
                const office = e.target.mode.value === 'office';
                const r = await api.post('/kiosk/devices', {name: e.target.name.value.trim(), mode: e.target.mode.value,
                    group_id: driver || office ? null : (Number(e.target.group_id.value) || null), vehicle_id: driver ? (Number(e.target.vehicle_id.value) || null) : null});
                await devices();
                showCode(r);
            } catch (err) { fail(err, e.target); }
        });
        body.querySelectorAll('[data-plan-create]').forEach(b => b.addEventListener('click', async () => {
            const r = planRows[Number(b.dataset.planCreate)];
            b.disabled = true;
            try {
                const created = await api.post('/kiosk/devices', {name: r.name, mode: r.mode, group_id: r.group || null, vehicle_id: null});
                await devices();
                showCode(created);
            } catch (err) { b.disabled = false; fail(err); }
        }));
        body.querySelectorAll('[data-device] [data-dev-mode]').forEach(select => select.addEventListener('change', async () => {
            const d = list.find(x => x.id === Number(select.closest('[data-device]').dataset.device));
            const mode = select.value;
            if (!window.confirm(t('mode_change_confirm', {name: d.name, mode: t('mode_' + mode)}))) { select.value = d.mode || 'atelier'; return; }
            try {
                // Tablet grupy : grupa jak dotąd albo pierwsza aktywna (do zmiany obok).
                const group = mode === 'atelier' ? (d.group_id || (groups.find(g => g.active !== false) || {}).id || null) : null;
                await api.patch(`/kiosk/devices/${d.id}`, {mode, ...(mode === 'atelier' ? {group_id: group} : {})});
                ui.notify(true, t('mode_changed', {mode: t('mode_' + mode)}));
                await devices();
            } catch (err) { select.value = d.mode || 'atelier'; fail(err); }
        }));
        body.querySelectorAll('[data-device] [data-dev-group]').forEach(select => select.addEventListener('change', async () => {
            try {
                await api.patch(`/kiosk/devices/${select.closest('[data-device]').dataset.device}`, {group_id: Number(select.value) || null});
                ui.notify(true, t('saved'));
                await devices();
            } catch (err) { fail(err); }
        }));
        body.querySelectorAll('[data-device] [data-vehicle]').forEach(select => select.addEventListener('change', async () => {
            try {
                await api.patch(`/kiosk/devices/${select.closest('[data-device]').dataset.device}`, {vehicle_id: Number(select.value) || null});
                ui.notify(true, t('saved'));
            } catch (err) { fail(err); }
        }));
        body.querySelectorAll('[data-device]').forEach(row => row.addEventListener('click', async (e) => {
            const id = Number(row.dataset.device);
            const d = list.find(x => x.id === id);
            try {
                if (e.target.closest('[data-repair]')) {
                    if (!window.confirm(t('repair_confirm'))) return;
                    const r = await api.patch(`/kiosk/devices/${id}`, {new_pairing_code: true, active: true});
                    await devices();
                    showCode(r);
                }
                if (e.target.closest('[data-toggle]')) { await api.patch(`/kiosk/devices/${id}`, {active: !d.active}); await devices(); }
            } catch (err) { fail(err); }
        }));
    }

    // --- Osoby : identyfikator, PIN, zgoda -----------------------------------------------------------
    async function people() {
        const list = (await api.list(`/kiosk/employees?month=${iso(new Date()).slice(0, 7)}`)).data;
        const body = el.querySelector('[data-body]');
        body.innerHTML = `<div class="card"><div class="card-body pt-3"><div class="table-responsive"><table class="table table-sm tfb-exec-people">
            <thead><tr><th>${esc(t('person'))}</th><th>${esc(t('badge'))}</th><th>${esc(t('pin'))}</th><th class="text-center">${esc(t('consent'))}</th><th>${esc(t('phones'))}</th><th></th></tr></thead>
            <tbody>${list.map(p => `<tr data-person="${p.employee_id}"><td><strong>${esc(p.name)}</strong><div class="small text-muted">${esc((groups.find(g => g.id === p.group_id) || {}).name || '')}${p.clocked_in ? ' · ' + esc(t('present')) : ''}${p.locked ? ' · <span class="text-danger">' + esc(t('locked')) + '</span>' : ''}</div></td>
                <td><input class="form-control form-control-sm" name="badge" data-field="badge" placeholder="${esc(p.has_badge ? t('badge_set') : t('badge_scan'))}" autocomplete="off"></td>
                <td><input class="form-control form-control-sm tfb-opt-num" name="pin" data-field="pin" inputmode="numeric" maxlength="6" placeholder="${esc(p.has_pin ? '••••' : t('pin_none'))}" autocomplete="new-password"></td>
                <td class="text-center"><input type="checkbox" class="form-check-input" name="biometric_consent" ${p.biometric_consent ? 'checked' : ''} aria-label="${esc(t('consent'))}"></td>
                <td class="text-nowrap">${p.phones ? `<i class="bi bi-phone"></i> ${p.phones} <button type="button" class="btn btn-sm btn-link text-danger p-0 ms-1" data-revoke>${esc(t('revoke_phones'))}</button>` : `<span class="text-muted">—</span>`}</td>
                <td class="text-end text-nowrap"><button type="button" class="btn btn-sm btn-primary" data-save>${esc(t('save'))}</button>
                    ${p.locked ? `<button type="button" class="btn btn-sm btn-outline-danger" data-unlock>${esc(t('unlock'))}</button>` : ''}</td></tr>`).join('')}</tbody></table></div>
            <p class="at-profit-note">${esc(t('people_help'))}</p></div></div>`;
        body.querySelectorAll('[data-person]').forEach(row => row.addEventListener('click', async (e) => {
            const id = Number(row.dataset.person);
            const payload = {};
            if (e.target.closest('[data-revoke]')) {
                if (!window.confirm(t('revoke_confirm'))) return;
                try { await api.del(`/kiosk/employees/${id}/phones`); ui.notify(true, t('revoked')); people(); } catch (err) { fail(err, row); }
                return;
            }
            if (e.target.closest('[data-unlock]')) payload.unlock = true;
            else if (e.target.closest('[data-save]')) {
                if (row.querySelector('[name=badge]').value.trim()) payload.badge = row.querySelector('[name=badge]').value.trim();
                if (row.querySelector('[name=pin]').value.trim()) payload.pin = row.querySelector('[name=pin]').value.trim();
                const consent = row.querySelector('[name=biometric_consent]').checked;
                const before = list.find(p => p.employee_id === id).biometric_consent;
                if (consent !== before) {
                    if (consent && !window.confirm(t('consent_confirm'))) return;
                    payload.biometric_consent = consent;
                }
                if (!Object.keys(payload).length) return;
            } else return;
            try { await api.put(`/kiosk/employees/${id}/credentials`, payload); ui.notify(true, t('saved')); people(); } catch (err) { fail(err, row); }
        }));
    }

    // --- Pytania przy wejściu / wyjściu (« OK, zatwierdzam » na telefonie i tablecie) -------------------
    async function questions() {
        const list = (await api.list('/kiosk/clock-questions')).data;
        const body = el.querySelector('[data-body]');
        const dirOptions = (v) => ['in', 'out', 'both'].map(d => `<option value="${d}" ${d === v ? 'selected' : ''}>${esc(t('q.dir.' + d))}</option>`).join('');
        body.innerHTML = `<div class="card"><div class="card-body pt-3">
            <div class="table-responsive"><table class="table table-sm align-middle tfb-exec-questions">
            <thead><tr><th style="width:90px">${esc(t('q.order'))}</th><th>${esc(t('q.text_pl'))}</th><th>${esc(t('q.text_fr'))}</th><th>${esc(t('q.direction'))}</th>
                <th></th><th></th></tr></thead>
            <tbody>${list.map(q => `<tr data-question="${q.id}" class="${q.active ? '' : 'text-muted'}">
                <td><input class="form-control form-control-sm" style="width:72px" name="sort_order" data-field="sort_order" type="number" min="0" value="${q.sort_order}"></td>
                <td><textarea class="form-control form-control-sm" style="min-width:200px" rows="2" name="text_pl" data-field="text_pl" maxlength="200" required>${esc(q.text_pl)}</textarea></td>
                <td><textarea class="form-control form-control-sm" style="min-width:180px" rows="2" name="text_fr" data-field="text_fr" maxlength="200">${esc(q.text_fr || '')}</textarea></td>
                <td><select class="form-select form-select-sm" style="min-width:140px" name="direction" data-field="direction">${dirOptions(q.direction)}</select></td>
                <td class="text-nowrap small"><label class="d-block"><input type="checkbox" class="form-check-input me-1" name="required" ${q.required ? 'checked' : ''}>${esc(t('q.required'))}</label>
                    <label class="d-block mt-1"><input type="checkbox" class="form-check-input me-1" name="active" ${q.active ? 'checked' : ''}>${esc(t('q.active'))}</label></td>
                <td class="text-end"><div class="d-flex flex-column align-items-end gap-1"><button type="button" class="btn btn-sm btn-primary" data-save>${esc(t('save'))}</button>
                    <button type="button" class="btn btn-sm btn-link text-danger p-0" data-delete>${esc(t('q.delete'))}</button></div></td></tr>`).join('')
                || `<tr><td colspan="6" class="text-muted">${esc(t('q.none'))}</td></tr>`}</tbody></table></div>
            <h6 class="mt-3">${esc(t('q.add_title'))}</h6>
            <form class="tfb-filters" data-add-question>
                <input class="form-control form-control-sm" name="text_pl" data-field="text_pl" maxlength="200" required placeholder="${esc(t('q.text_pl'))}" style="min-width:260px">
                <input class="form-control form-control-sm" name="text_fr" data-field="text_fr" maxlength="200" placeholder="${esc(t('q.text_fr'))}" style="min-width:220px">
                <select class="form-select form-select-sm" name="direction" data-field="direction" style="width:auto">${dirOptions('in')}</select>
                <label class="tfb-inline"><input type="checkbox" class="form-check-input" name="required" checked> ${esc(t('q.required'))}</label>
                <button class="btn btn-sm btn-primary">${esc(t('q.add'))}</button></form>
            <p class="at-profit-note">${esc(t('q.help'))}</p></div></div>`;
        body.querySelector('[data-add-question]').addEventListener('submit', async (e) => {
            e.preventDefault();
            const f = e.target;
            try {
                await api.post('/kiosk/clock-questions', {text_pl: f.text_pl.value.trim(), text_fr: f.text_fr.value.trim() || null, direction: f.direction.value, required: f.required.checked});
                ui.notify(true, t('saved'));
                questions();
            } catch (err) { fail(err, f); }
        });
        body.querySelectorAll('[data-question]').forEach(row => row.addEventListener('click', async (e) => {
            const id = Number(row.dataset.question);
            try {
                if (e.target.closest('[data-delete]')) {
                    if (!window.confirm(t('q.delete_confirm'))) return;
                    await api.del(`/kiosk/clock-questions/${id}`);
                } else if (e.target.closest('[data-save]')) {
                    const v = (n) => row.querySelector(`[name=${n}]`);
                    await api.patch(`/kiosk/clock-questions/${id}`, {sort_order: Number(v('sort_order').value) || 0, text_pl: v('text_pl').value.trim(),
                        text_fr: v('text_fr').value.trim() || null, direction: v('direction').value, required: v('required').checked, active: v('active').checked});
                } else return;
                ui.notify(true, t('saved'));
                questions();
            } catch (err) { fail(err, row); }
        }));
    }

    // --- Godziny pracy ------------------------------------------------------------------------------
    async function hours(month, group) {
        month ??= iso(new Date()).slice(0, 7);
        const list = (await api.list(`/kiosk/employees?month=${month}${group ? '&group_id=' + group : ''}`)).data;
        const body = el.querySelector('[data-body]');
        body.innerHTML = `<div class="card"><div class="card-body">
            <form class="tfb-filters" data-hours-form><label class="tfb-inline">${esc(t('month'))} <input type="month" class="form-control form-control-sm" name="month" value="${month}"></label>
                <select class="form-select form-select-sm" name="group">${groupOptions(group ? Number(group) : null, t('all_groups'))}</select><button class="btn btn-sm btn-outline-primary">${esc(t('show'))}</button></form>
            <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>${esc(t('person'))}</th><th class="text-end">${esc(t('hours'))}</th><th class="text-end">${esc(t('night'))}</th>
                <th class="text-end">${esc(t('days'))}</th><th class="text-end">${esc(t('open'))}</th><th class="text-center">${esc(t('signed_month'))}</th><th></th></tr></thead>
            <tbody>${list.map(p => `<tr><td><strong>${esc(p.name)}</strong></td><td class="text-end">${fmt(p.hours, 2)}</td><td class="text-end">${fmt(p.night_hours, 2)}</td><td class="text-end">${p.days}</td>
                <td class="text-end ${p.open ? 'text-danger' : ''}">${p.open || ''}</td><td class="text-center">${p.month_signed ? '✓' : ''}</td>
                <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="${esc(api.root)}/workshop/timesheet?employee=${p.employee_id}&month=${month}" target="_blank" rel="noopener">${esc(t('print'))}</a></td></tr>`).join('')}</tbody>
            <tfoot><tr class="at-profit-total"><td><strong>${esc(t('total'))}</strong></td><td class="text-end"><strong>${fmt(list.reduce((s, p) => s + p.hours, 0), 2)}</strong></td><td class="text-end">${fmt(list.reduce((s, p) => s + p.night_hours, 0), 2)}</td><td colspan="4"></td></tr></tfoot></table></div>
            <h6 class="mt-4">${esc(t('manual_title'))}</h6>
            <form class="tfb-form" data-manual><label>${esc(t('person'))}<select class="form-select" name="employee_id" data-field="employee_id" required><option value=""></option>${list.map(p => `<option value="${p.employee_id}">${esc(p.name)}</option>`).join('')}</select></label>
                <label>${esc(t('clock_in'))}<input type="datetime-local" class="form-control" name="clock_in" data-field="clock_in" required></label>
                <label>${esc(t('clock_out'))}<input type="datetime-local" class="form-control" name="clock_out" data-field="clock_out" required></label>
                <label>${esc(t('reason'))}<input class="form-control" name="note" data-field="note" required maxlength="255"></label>
                <div class="tfb-nav"><span class="small text-muted">${esc(t('manual_help'))}</span><button class="btn btn-primary">${esc(t('add_entry'))}</button></div></form></div></div>`;
        body.querySelector('[data-hours-form]').addEventListener('submit', (e) => { e.preventDefault(); hours(e.target.month.value, e.target.group.value); });
        body.querySelector('[data-manual]').addEventListener('submit', async (e) => {
            e.preventDefault();
            const f = e.target;
            try {
                await api.post('/kiosk/clocks', {employee_id: Number(f.employee_id.value), clock_in: f.clock_in.value.replace('T', ' '), clock_out: f.clock_out.value.replace('T', ' '), note: f.note.value.trim()});
                ui.notify(true, t('saved'));
                hours(month, group);
            } catch (err) { fail(err, f); }
        });
    }

    el.addEventListener('click', async (e) => {
        const tabLink = e.target.closest('[data-tab]');
        if (tabLink) { e.preventDefault(); tab = tabLink.dataset.tab; history.replaceState(null, '', '#' + tab); render(); return; }
        if (!e.target.closest('[data-lang-switch]')) return;
        await api.setLang(api.lang === 'pl' ? 'fr' : 'pl');
        groups = (await api.list('/referentiels/groups')).data;
        render();
    });
    render();
})();
