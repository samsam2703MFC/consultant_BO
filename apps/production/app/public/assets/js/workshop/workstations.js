/*
 * Stanowiska i coboty / Postes et cobots : stanowiska grup (typ, pojemność na przejście, koszt godziny, aktywne) ;
 * robot autonomiczny (bez obsługi, także w nocy), z operatorem (osoba na stałe) albo współpracujący — cobot : jedna
 * osoba pilnuje kilku cobotów, część osoby (np. 0,25) = godziny pracy etapu na cobocie. Etap przechodzi na cobota
 * w karcie produktu (« Etapy i czasy » : cobot jako stanowisko alternatywne z własną kadencją).
 */
(async function () {
    'use strict';
    const el = document.getElementById('tfbWorkstations');
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
    const TYPES = ['manuel', 'machine', 'four', 'robot', 'fermentation', 'froid', 'surgelation'];
    const MODES = ['collaboratif', 'avec_operateur', 'autonome'];
    let groups = [];
    let stations = [];
    let units = ['piece'];
    let canWrite = false;
    let editing = null; // {id|null, ...pola}
    let filter = {group: '', type: ''};

    const head = () => `<div class="tfb-head"><div><h3>${esc(t('title'))}</h3><p class="text-muted mb-0">${esc(t('subtitle'))}</p></div>
        <div class="tfb-head-actions">${canWrite ? `<button type="button" class="btn btn-primary btn-sm" data-new="cobot"><i class="bi bi-robot"></i> ${esc(t('new_cobot'))}</button>
            <button type="button" class="btn btn-outline-primary btn-sm" data-new="station"><i class="bi bi-plus"></i> ${esc(t('new_station'))}</button>` : ''}
            <button type="button" class="btn btn-outline-secondary btn-sm" data-lang-switch>${esc(t('lang_switch'))}</button></div></div>`;

    async function load() {
        el.innerHTML = head() + `<p class="text-muted">${esc(t('loading'))}</p>`;
        try {
            const [g, w, me] = await Promise.all([api.list('/referentiels/groups'), api.list('/referentiels/workstations'), api.get('/auth/me')]);
            groups = g.data;
            stations = w.data;
            canWrite = (me.permissions || []).includes('prod.referentiels.write');
            try {
                const p = await api.get('/referentiels/params/workstation.capacity_units');
                const v = typeof p.value === 'string' ? JSON.parse(p.value) : p.value;
                if (Array.isArray(v) && v.length) units = v;
            } catch (e) { /* liste par défaut */ }
        } catch (err) {
            el.innerHTML = head() + `<div class="alert alert-danger">${esc(err.message)}</div>`;
            return;
        }
        draw();
    }

    const modeText = (w) => w.type !== 'robot' ? '' : (w.robot_mode === 'collaboratif'
        ? t('mode_cobot_share', {share: n(w.operator_share, 2), people: n(1 / w.operator_share, 1)}) : t('mode.' + w.robot_mode));
    const typeBadge = (w) => w.collaborative ? `<span class="badge bg-success-subtle text-success-emphasis">🤖 ${esc(t('cobot'))}</span>`
        : `<span class="badge bg-light text-dark">${esc(t('type.' + w.type))}</span>`;

    function draw() {
        const list = stations.filter(w => (!filter.group || w.group_id === Number(filter.group)) && (!filter.type || (filter.type === 'cobot' ? w.collaborative : w.type === filter.type)));
        const cobots = stations.filter(w => w.collaborative && w.active);
        const share = cobots.reduce((a, w) => a + w.operator_share, 0);
        const byGroup = groups.map(g => ({g, rows: list.filter(w => w.group_id === g.id)})).filter(x => x.rows.length);
        el.innerHTML = head() + `
            <div class="card"><div class="card-body">
                <p class="mb-2">${esc(t('intro'))}</p>
                <p class="mb-0 small text-muted">${esc(t('intro_routing'))}</p>
                ${cobots.length ? `<p class="mb-0 mt-2"><strong>${esc(t('cobots_summary', {n: cobots.length, people: n(share, 2)}))}</strong></p>` : ''}
            </div></div>
            ${editing ? form() : ''}
            <div class="card"><div class="card-body">
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <select class="form-select form-select-sm w-auto" data-filter="group" aria-label="${esc(t('col_group'))}"><option value="">${esc(t('all_groups'))}</option>
                        ${groups.map(g => `<option value="${g.id}" ${String(g.id) === filter.group ? 'selected' : ''}>${esc(g.name)}</option>`).join('')}</select>
                    <select class="form-select form-select-sm w-auto" data-filter="type" aria-label="${esc(t('col_type'))}"><option value="">${esc(t('all_types'))}</option>
                        <option value="cobot" ${filter.type === 'cobot' ? 'selected' : ''}>🤖 ${esc(t('cobots'))}</option>
                        ${TYPES.map(k => `<option value="${k}" ${filter.type === k ? 'selected' : ''}>${esc(t('type.' + k))}</option>`).join('')}</select>
                </div>
                ${byGroup.length ? `<div class="table-responsive"><table class="table table-sm align-middle mb-0">
                    <thead><tr><th>${esc(t('col_station'))}</th><th>${esc(t('col_type'))}</th><th>${esc(t('col_mode'))}</th><th class="text-end">${esc(t('col_capacity'))}</th>
                        <th class="text-end">${esc(t('col_cost'))}</th><th>${esc(t('col_active'))}</th><th></th></tr></thead>
                    <tbody>${byGroup.map(({g, rows}) => `<tr class="tfb-week-table-group"><th colspan="7">${esc(g.name)}</th></tr>` + rows.map(w => `
                        <tr class="${w.active ? '' : 'text-muted'}" data-id="${w.id}">
                            <td><strong>${esc(w.name)}</strong><div class="small text-muted">${esc(w.code)}</div></td>
                            <td>${typeBadge(w)}</td>
                            <td>${esc(modeText(w)) || '<span class="text-muted">—</span>'}</td>
                            <td class="text-end">${n(w.capacity_per_pass, 2)} ${esc(t('unit.' + w.capacity_unit))}</td>
                            <td class="text-end">${n(w.hourly_cost, 2)}</td>
                            <td>${w.active ? '✓' : esc(t('inactive'))}</td>
                            <td class="text-end">${canWrite ? `<button type="button" class="btn btn-sm btn-outline-primary" data-edit="${w.id}">${esc(t('edit'))}</button>` : ''}</td>
                        </tr>`).join('')).join('')}</tbody></table></div>` : `<p class="text-muted mb-0">${esc(t('empty'))}</p>`}
            </div></div>`;
        bind();
    }

    function form() {
        const w = editing;
        const robot = w.type === 'robot';
        const cobot = robot && w.robot_mode === 'collaboratif';
        return `<div class="card border-primary"><div class="card-body">
            <h5>${esc(w.id ? t('edit_title', {name: w.name_pl || ''}) : (cobot ? t('new_cobot') : t('new_station')))}</h5>
            <form class="tfb-ws-form" data-form novalidate>
                <label>${esc(t('f.code'))}<input class="form-control form-control-sm" name="code" data-field="code" value="${esc(w.code || '')}" maxlength="32" ${w.id ? 'readonly' : ''} placeholder="COBOT_1"></label>
                <label>${esc(t('f.name_pl'))}<input class="form-control form-control-sm" name="name_pl" data-field="name_pl" value="${esc(w.name_pl || '')}" maxlength="100"></label>
                <label>${esc(t('f.name_fr'))}<input class="form-control form-control-sm" name="name_fr" data-field="name_fr" value="${esc(w.name_fr || '')}" maxlength="100"></label>
                <label>${esc(t('col_group'))}<select class="form-select form-select-sm" name="group_id" data-field="group_id">${groups.map(g => `<option value="${g.id}" ${g.id === w.group_id ? 'selected' : ''}>${esc(g.name)}</option>`).join('')}</select></label>
                <label>${esc(t('col_type'))}<select class="form-select form-select-sm" name="type" data-field="type">${TYPES.map(k => `<option value="${k}" ${k === w.type ? 'selected' : ''}>${esc(t('type.' + k))}</option>`).join('')}</select></label>
                ${robot ? `<label>${esc(t('col_mode'))}<select class="form-select form-select-sm" name="robot_mode" data-field="robot_mode">${MODES.map(k => `<option value="${k}" ${k === w.robot_mode ? 'selected' : ''}>${esc(t('mode.' + k))}</option>`).join('')}</select>
                    <span class="form-text">${esc(t('mode_help.' + (w.robot_mode || 'collaboratif')))}</span></label>` : ''}
                ${cobot ? `<label>${esc(t('f.share'))}<input class="form-control form-control-sm" name="operator_share" data-field="operator_share" inputmode="decimal" value="${w.operator_share ?? ''}" placeholder="0,25">
                    <span class="form-text" data-share-help>${esc(shareHelp(w.operator_share))}</span></label>` : ''}
                <label>${esc(t('col_capacity'))}<div class="input-group input-group-sm"><input class="form-control" name="capacity_per_pass" data-field="capacity_per_pass" inputmode="decimal" value="${w.capacity_per_pass ?? 1}">
                    <select class="form-select" name="capacity_unit" data-field="capacity_unit" aria-label="${esc(t('f.unit'))}">${units.map(u => `<option value="${esc(u)}" ${u === w.capacity_unit ? 'selected' : ''}>${esc(t('unit.' + u))}</option>`).join('')}</select></div></label>
                <label>${esc(t('col_cost'))}<input class="form-control form-control-sm" name="hourly_cost" data-field="hourly_cost" inputmode="decimal" value="${w.hourly_cost ?? 0}"></label>
                <label class="tfb-wide">${esc(t('f.notes'))}<input class="form-control form-control-sm" name="notes" data-field="notes" value="${esc(w.notes || '')}" maxlength="500"></label>
                <label class="form-check"><input class="form-check-input" type="checkbox" name="active" ${w.active === false ? '' : 'checked'}> ${esc(t('col_active'))}</label>
                <div class="tfb-wide d-flex gap-2 justify-content-end"><button type="button" class="btn btn-outline-secondary btn-sm" data-cancel>${esc(t('cancel'))}</button>
                    <button class="btn btn-primary btn-sm">${esc(t('save'))}</button></div>
            </form></div></div>`;
    }

    const shareHelp = (v) => {
        const x = ui.num(String(v ?? ''));
        return typeof x === 'number' && x > 0 && x <= 1 ? t('share_help', {people: n(1 / x, 1), pct: n(x * 100, 0)}) : t('share_hint');
    };

    // Formularz → stan (zmiana typu lub trybu przerysowuje formularz).
    function read(f) {
        const v = (name) => f.elements[name] ? f.elements[name].value.trim() : undefined;
        editing = {...editing, code: v('code') ?? editing.code, name_pl: v('name_pl'), name_fr: v('name_fr'), group_id: Number(v('group_id')), type: v('type'),
            robot_mode: v('robot_mode') ?? editing.robot_mode, operator_share: f.elements.operator_share ? ui.num(f.elements.operator_share.value) : editing.operator_share,
            capacity_per_pass: ui.num(v('capacity_per_pass')), capacity_unit: v('capacity_unit'), hourly_cost: ui.num(v('hourly_cost')), notes: v('notes'), active: f.elements.active.checked};
    }

    function bind() {
        el.querySelector('[data-lang-switch]').addEventListener('click', async () => { await api.setLang(api.lang === 'pl' ? 'fr' : 'pl'); load(); });
        el.querySelectorAll('[data-filter]').forEach(s => s.addEventListener('change', () => { filter[s.dataset.filter] = s.value; draw(); }));
        el.querySelectorAll('[data-new]').forEach(b => b.addEventListener('click', () => {
            const cobot = b.dataset.new === 'cobot';
            editing = {id: null, code: '', name_pl: cobot ? 'Cobot ' : '', name_fr: cobot ? 'Cobot ' : '', group_id: Number(filter.group) || (groups[0] || {}).id,
                type: cobot ? 'robot' : 'manuel', robot_mode: cobot ? 'collaboratif' : null, operator_share: cobot ? 0.25 : null,
                capacity_per_pass: 1, capacity_unit: units[0], hourly_cost: 0, notes: '', active: true};
            draw();
            el.querySelector('[name=code]').focus();
        }));
        el.querySelectorAll('[data-edit]').forEach(b => b.addEventListener('click', () => {
            const w = stations.find(x => x.id === Number(b.dataset.edit));
            editing = {...w};
            draw();
            el.querySelector('[data-form]').scrollIntoView({block: 'center'});
        }));
        const f = el.querySelector('[data-form]');
        if (!f) return;
        // Zmiana typu / trybu : robot domyślnie współpracujący, cobot domyślnie 0,25 osoby.
        f.addEventListener('change', (e) => {
            if (!['type', 'robot_mode'].includes(e.target.name)) return;
            read(f);
            if (editing.type === 'robot' && !editing.robot_mode) editing.robot_mode = 'collaboratif';
            if (editing.robot_mode === 'collaboratif' && (editing.operator_share === null || editing.operator_share === undefined)) editing.operator_share = 0.25;
            draw();
        });
        f.addEventListener('input', (e) => {
            if (e.target.name === 'operator_share') f.querySelector('[data-share-help]').textContent = shareHelp(e.target.value);
        });
        f.querySelector('[data-cancel]').addEventListener('click', () => { editing = null; draw(); });
        f.addEventListener('submit', async (e) => {
            e.preventDefault();
            read(f);
            ui.clearErrors(f);
            const w = editing;
            const body = {name_pl: w.name_pl, name_fr: w.name_fr || w.name_pl, group_id: w.group_id, type: w.type,
                robot_mode: w.type === 'robot' ? w.robot_mode : null, capacity_per_pass: w.capacity_per_pass, capacity_unit: w.capacity_unit,
                hourly_cost: w.hourly_cost ?? 0, notes: w.notes || null, active: w.active};
            if (w.type === 'robot' && w.robot_mode === 'collaboratif') body.operator_share = w.operator_share ?? null;
            const button = f.querySelector('button.btn-primary');
            button.disabled = true;
            try {
                if (w.id) await api.patch(`/referentiels/workstations/${w.id}`, body);
                else await api.post('/referentiels/workstations', {code: w.code, ...body});
                ui.notify(true, t('saved'));
                editing = null;
                await load();
            } catch (err) {
                button.disabled = false;
                const orphans = ui.fieldErrors(f, err);
                ui.notify(false, orphans.length ? orphans.join(' ') : err.message);
            }
        });
    }

    load();
})();
