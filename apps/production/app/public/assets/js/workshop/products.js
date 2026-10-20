/*
 * Karty produkcyjne / Fiches de production (P2) : liste, nouvelle fiche depuis l'ERP, assistant
 * en 7 étapes (article et logistique, mode et stocks, groupe, cuisson et durées de vie,
 * nomenclature et pertes, gamme, contrôle des temps avec chronométrages, validation).
 * Tout passe par l'API.
 */
(async function () {
    'use strict';
    const el = document.getElementById('tfbProducts');
    if (!el) return;
    const {WorkshopApi, ui} = window.TfbWorkshop;
    const api = new WorkshopApi(el);
    const esc = ui.esc;
    const state = {groups: [], workstations: [], item: null, studies: {}};

    try {
        await api.init();
    } catch (e) {
        el.innerHTML = `<div class="alert alert-danger">${esc(e.message)}</div>`;
        return;
    }

    const t = (k, p) => api.t(k, p);
    // Postes où l'étape a une température : four, pousse, froid, surgélation (comme l'API).
    const TEMPERATURE_TYPES = ['four', 'fermentation', 'froid', 'surgelation'];
    const wsType = (id) => (state.workstations.find(w => w.id === id) || {}).type;
    const groupName = (id) => (state.groups.find(g => g.id === id) || {}).name || '—';
    const wsOptions = (selected, filter) => state.workstations.filter(w => !filter || filter(w))
        .map(w => `<option value="${w.id}" ${w.id === selected ? 'selected' : ''}>${esc(w.code)} — ${esc(w.name)}</option>`).join('');
    const fail = (container, err) => {
        if (err.status === 401 || err.status === 403) { ui.notify(false, err.message); return; }
        const orphans = container ? ui.fieldErrors(container, err) : [];
        ui.notify(false, orphans.length ? orphans.join(' ') : err.message);
    };

    async function loadReferences() {
        const [groups, workstations] = await Promise.all([api.list('/referentiels/groups?active=true'), api.list('/referentiels/workstations?active=true')]);
        state.groups = groups.data;
        state.workstations = workstations.data;
    }

    function header(extra) {
        return `<div class="tfb-head">
            <div><h3>${esc(t('title'))}</h3><p class="text-muted mb-0">${esc(t('subtitle'))}</p></div>
            <div class="tfb-head-actions">${extra || ''}<button type="button" class="btn btn-outline-secondary btn-sm" data-lang-switch>${esc(t('lang_switch'))}</button></div>
        </div>`;
    }

    // --- Liste ------------------------------------------------------------------
    async function renderList() {
        const params = new URLSearchParams(location.hash.split('?')[1] || '');
        const query = new URLSearchParams();
        for (const k of ['search', 'status', 'item_type', 'group_id']) if (params.get(k)) query.set(k, params.get(k));
        const res = await api.list('/produits/items' + (query.toString() ? '?' + query : ''));
        el.innerHTML = header(`<a class="btn btn-primary btn-sm" href="#/new"><i class="bi bi-plus"></i> ${esc(t('new_sheet'))}</a>`) + `
            <form class="tfb-filters" data-filters>
                <input class="form-control form-control-sm" name="search" placeholder="${esc(t('search'))}" value="${esc(params.get('search') || '')}">
                <select class="form-select form-select-sm" name="status"><option value="">${esc(t('col.status'))}: ${esc(t('all'))}</option>
                    ${['draft', 'validated'].map(s => `<option value="${s}" ${params.get('status') === s ? 'selected' : ''}>${esc(t('status.' + s))}</option>`).join('')}</select>
                <select class="form-select form-select-sm" name="item_type"><option value="">${esc(t('col.type'))}: ${esc(t('all'))}</option>
                    ${['product', 'semi'].map(s => `<option value="${s}" ${params.get('item_type') === s ? 'selected' : ''}>${esc(t('type.' + s))}</option>`).join('')}</select>
                <select class="form-select form-select-sm" name="group_id"><option value="">${esc(t('col.group'))}: ${esc(t('all'))}</option>
                    ${state.groups.map(g => `<option value="${g.id}" ${params.get('group_id') === String(g.id) ? 'selected' : ''}>${esc(g.name)}</option>`).join('')}</select>
            </form>
            <div class="card"><div class="card-body pt-3"><div class="table-responsive"><table class="table table-hover mb-0">
                <thead><tr><th>${esc(t('col.name'))}</th><th>${esc(t('col.type'))}</th><th>${esc(t('col.group'))}</th><th>${esc(t('col.mode'))}</th><th class="text-end">${esc(t('col.steps'))}</th><th>${esc(t('col.status'))}</th></tr></thead>
                <tbody>${res.data.map(i => `<tr>
                    <td><a href="#/item/${i.id}/${i.settings.status === 'validated' ? 6 : Math.max(2, i.settings.wizard_step)}"><strong>${esc(i.name)}</strong></a>${i.erp.active ? '' : ` <span class="badge bg-secondary">${esc(t('erp.inactive'))}</span>`}</td>
                    <td>${esc(t('type.' + i.item_type))}</td>
                    <td>${esc(groupName(i.settings.primary_group_id))}</td>
                    <td>${esc(t('mode.' + i.settings.mode))}${i.settings.never_frozen ? ' <i class="bi bi-snow" title="' + esc(t('f.never_frozen')) + '"></i>' : ''}</td>
                    <td class="text-end">${i.step_count}</td>
                    <td><span class="badge ${i.settings.status === 'validated' ? 'bg-success' : 'bg-warning'}">${esc(t('status.' + i.settings.status))}</span></td>
                </tr>`).join('') || `<tr><td colspan="6" class="text-muted">${esc(t('empty_list'))}</td></tr>`}</tbody>
            </table></div></div></div>`;
        const form = el.querySelector('[data-filters]');
        const apply = () => {
            const q = new URLSearchParams();
            new FormData(form).forEach((v, k) => { if (v) q.set(k, v); });
            location.hash = '#/' + (q.toString() ? '?' + q : '');
        };
        form.addEventListener('change', apply);
        form.addEventListener('submit', (e) => { e.preventDefault(); apply(); });
    }

    // --- Nouvelle fiche (étape 1) ---------------------------------------------------
    async function renderNew() {
        el.innerHTML = header(`<a class="btn btn-outline-primary btn-sm" href="#/">${esc(t('back_to_list'))}</a>`) + `
            <div class="card"><div class="card-body">
                <h4>${esc(t('new.title'))}</h4><p class="text-muted">${esc(t('new.help'))}</p>
                <div class="tfb-filters">
                    <select class="form-select form-select-sm" data-type>${['product', 'semi'].map(s => `<option value="${s}">${esc(t('type.' + s))}</option>`).join('')}</select>
                    <input class="form-control form-control-sm" data-search placeholder="${esc(t('search'))}">
                </div>
                <div data-candidates class="tfb-candidates"></div>
            </div></div>`;
        const type = el.querySelector('[data-type]');
        const search = el.querySelector('[data-search]');
        const box = el.querySelector('[data-candidates]');
        let timer = null;
        const load = async () => {
            const res = await api.list(`/produits/candidates?item_type=${type.value}&search=${encodeURIComponent(search.value.trim())}`);
            box.innerHTML = res.data.map(c => `<button type="button" class="tfb-candidate" data-id="${c.id}">
                <strong>${esc(c.name)}</strong><span class="text-muted">${c.ppc ? esc(t('erp.ppc')) + ': ' + c.ppc : ''}</span></button>`).join('')
                || `<p class="text-muted">${esc(t('new.none'))}</p>`;
        };
        type.addEventListener('change', load);
        search.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(load, 250); });
        box.addEventListener('click', async (e) => {
            const b = e.target.closest('[data-id]');
            if (!b) return;
            b.disabled = true;
            try {
                const body = type.value === 'product' ? {item_type: 'product', final_product_id: Number(b.dataset.id)} : {item_type: 'semi', recipe_id: Number(b.dataset.id)};
                const item = await api.post('/produits/items', body);
                location.hash = `#/item/${item.id}/2`;
            } catch (err) { b.disabled = false; fail(null, err); }
        });
        await load();
    }

    // --- Fiche : assistant -------------------------------------------------------------
    async function renderItem(id, step) {
        state.item = await api.get(`/produits/items/${id}`);
        const item = state.item;
        const ro = !item.can_edit;
        el.innerHTML = header(`<a class="btn btn-outline-primary btn-sm" href="#/">${esc(t('back_to_list'))}</a>`) + `
            <div class="tfb-item-title"><h4>${esc(item.name)}</h4>
                <span class="badge ${item.settings.status === 'validated' ? 'bg-success' : 'bg-warning'}">${esc(t('status.' + item.settings.status))}</span>
                <span class="text-muted">${esc(t('type.' + item.item_type))} · ${esc(groupName(item.settings.primary_group_id))}</span></div>
            ${ro ? `<div class="alert alert-info">${esc(t('read_only'))}</div>` : ''}
            <ol class="tfb-stepper">${[1, 2, 3, 4, 5, 6, 7].map(n => `<li class="${n === step ? 'is-current' : ''} ${n < item.settings.wizard_step || item.settings.status === 'validated' ? 'is-done' : ''}">
                <a href="#/item/${id}/${n}"><span>${n}</span>${esc(t('step.' + n))}</a></li>`).join('')}</ol>
            <div class="card"><div class="card-body" data-step-body></div></div>`;
        const body = el.querySelector('[data-step-body]');
        const renderers = {1: stepArticle, 2: stepMode, 3: stepGroup, 4: stepBom, 5: stepRouting, 6: stepTimes, 7: stepValidate};
        await renderers[step](body, item, ro);
        if (ro) body.querySelectorAll('input, select, textarea, button[data-write]').forEach(x => x.disabled = true);
    }

    function nav(step, saveLabel) {
        return `<div class="tfb-nav">
            ${step > 1 ? `<a class="btn btn-outline-secondary" href="#/item/${state.item.id}/${step - 1}">${esc(t('back'))}</a>` : '<span></span>'}
            ${saveLabel === false ? (step < 7 ? `<a class="btn btn-primary" href="#/item/${state.item.id}/${step + 1}">${esc(t('next'))}</a>` : '')
                : `<button type="submit" class="btn btn-primary" data-write>${esc(saveLabel || t('save_next'))}</button>`}
        </div>`;
    }

    async function saveSettings(form, data, nextStep) {
        ui.clearErrors(form);
        try {
            await api.patch(`/produits/items/${state.item.id}/settings`, {...data, wizard_step: Math.max(state.item.settings.wizard_step, nextStep)});
            ui.notify(true, t('saved'));
            location.hash = `#/item/${state.item.id}/${nextStep}`;
        } catch (err) { fail(form, err); }
    }

    function stepArticle(body, item) {
        const info = `<dl class="tfb-dl">
            <dt>${esc(t('col.name'))}</dt><dd>${esc(item.name)}</dd>
            <dt>${esc(t('col.type'))}</dt><dd>${esc(t('type.' + item.item_type))}</dd>
            <dt>${esc(t('erp.unit'))}</dt><dd>${esc(item.base_unit)}</dd>
        </dl>`;
        if (item.item_type !== 'product') {
            body.innerHTML = `${info}${item.erp.weight ? `<p>${esc(t('erp.weight'))}: ${ui.fmt(item.erp.weight, 1, api.lang)}</p>` : ''}<p class="text-muted">${esc(t('new.help'))}</p>${nav(1, false)}`;
            return;
        }
        const e = item.erp;
        const lg = item.logistics || {computed: {}};
        const c = lg.computed || {};
        const field = (name, value) => `<label>${esc(t('l.' + name))}<input class="form-control" name="${name}" data-field="${name}" inputmode="numeric" value="${value ? value : ''}"></label>`;
        const choice = (name, values, current) => `<label>${esc(t('l.' + name))}<select class="form-select" name="${name}" data-field="${name}"><option value="">—</option>
            ${values.map(v => `<option value="${v}" ${current === v ? 'selected' : ''}>${esc(t('l.' + name + '.' + v))}</option>`).join('')}</select></label>`;
        const out = (label, value) => `<div class="tfb-calc"><span>${esc(label)}</span><strong>${value}</strong></div>`;
        const kg = (v) => v === null || v === undefined ? '—' : ui.fmt(v, v < 10 ? 2 : 1, api.lang) + ' kg';
        body.innerHTML = `${info}<form class="tfb-form" data-form>
            <h5 class="tfb-full">${esc(t('l.title_units'))}</h5>
            ${field('weight', e.weight)}${field('ppb', e.ppb)}${field('bpc', e.bpc)}${field('ppc', e.ppc)}
            <h5 class="tfb-full">${esc(t('l.title_carton'))}</h5>
            ${field('carton_length_mm', lg.carton_length_mm)}${field('carton_width_mm', lg.carton_width_mm)}${field('carton_height_mm', lg.carton_height_mm)}
            ${field('carton_tare_g', lg.carton_tare_g)}${choice('carton_type', ['klapowy', 'fasonowy', 'inny'], lg.carton_type)}
            <label>${esc(t('l.carton_ean'))}<input class="form-control" name="carton_ean" data-field="carton_ean" inputmode="numeric" maxlength="14" value="${esc(lg.carton_ean || '')}"></label>
            <h5 class="tfb-full">${esc(t('l.title_pallet'))}</h5>
            ${field('cpl', e.cpl)}${field('lpp', e.lpp)}${field('cpp', e.cpp)}
            ${choice('pallet_type', ['eur', 'industrial', 'other'], lg.pallet_type)}${choice('storage', ['frozen', 'chilled', 'ambient'], lg.storage)}
            <div class="tfb-full tfb-calcs">
                ${out(t('l.carton_net'), kg(c.carton_net_kg))}${out(t('l.carton_gross'), kg(c.carton_gross_kg))}
                ${out(t('l.pallet_units'), c.pallet_units ? ui.fmt(c.pallet_units, 0, api.lang) : '—')}
                ${out(t('l.pallet_gross'), kg(c.pallet_gross_kg ?? c.pallet_net_kg))}
                ${out(t('l.pallet_height'), c.pallet_height_mm ? ui.fmt(c.pallet_height_mm, 0, api.lang) + ' mm' : '—')}
                ${out(t('l.labour_carton'), c.labour_s_per_carton ? ui.duration(c.labour_s_per_carton, api) : '—')}
            </div>
            <p class="form-text tfb-full">${esc(t('help.logistics'))}</p>
            ${nav(1)}</form>`;
        const form = body.querySelector('[data-form]');
        // Cartons par palette proposés = couches × cartons par couche, tant que l'utilisateur ne l'a pas saisi.
        const syncPallet = () => {
            const cpl = ui.num(form.cpl.value);
            const lpp = ui.num(form.lpp.value);
            if (cpl && lpp && !form.cpp.dataset.touched) form.cpp.value = cpl * lpp;
        };
        form.cpp.addEventListener('input', () => { form.cpp.dataset.touched = '1'; });
        form.cpl.addEventListener('input', syncPallet);
        form.lpp.addEventListener('input', syncPallet);
        form.addEventListener('submit', async (ev) => {
            ev.preventDefault();
            ui.clearErrors(form);
            const data = {};
            // Champs de l'ERP : un champ vide garde la valeur de l'ERP ; champs de la fiche : vide = effacé.
            for (const f of ['weight', 'ppb', 'bpc', 'ppc', 'cpl', 'lpp', 'cpp']) { const v = ui.num(form[f].value); if (v !== null) data[f] = v; }
            for (const f of ['carton_length_mm', 'carton_width_mm', 'carton_height_mm', 'carton_tare_g']) data[f] = ui.num(form[f].value);
            for (const f of ['carton_type', 'pallet_type', 'storage']) data[f] = form[f].value || null;
            data.carton_ean = form.carton_ean.value.trim() || null;
            try {
                await api.put(`/produits/items/${item.id}/logistics`, data);
                await saveSettings(form, {}, 2);
            } catch (err) { fail(form, err); }
        });
    }

    function stepMode(body, item) {
        const s = item.settings;
        body.innerHTML = `<form class="tfb-form" data-form>
            <div class="tfb-full tfb-mode" data-field="mode">
                <span class="tfb-mode-label" data-mode-label="MTO">${esc(t('mode.MTO'))}</span>
                <label class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" role="switch" name="mts" aria-label="${esc(t('f.mode'))}: ${esc(t('mode.MTS'))}" ${s.mode === 'MTS' ? 'checked' : ''}></label>
                <span class="tfb-mode-label" data-mode-label="MTS">${esc(t('mode.MTS'))}</span>
                <span class="form-text" data-mode-help></span>
            </div>
            <label class="form-check tfb-full"><input class="form-check-input" type="checkbox" name="never_frozen" data-field="never_frozen" ${s.never_frozen ? 'checked' : ''}> ${esc(t('f.never_frozen'))}
                <span class="form-text d-block">${esc(t('help.never_frozen'))}</span></label>
            ${['stock_min', 'stock_max', 'coverage_days', 'batch_min', 'batch_multiple'].map(f => `<label>${esc(t('f.' + f))}
                <input class="form-control" name="${f}" data-field="${f}" inputmode="decimal" value="${s[f] ?? ''}"></label>`).join('')}
            <p class="form-text tfb-full">${esc(t('help.mts'))}</p>
            ${nav(2)}</form>`;
        const form = body.querySelector('[data-form]');
        const sync = () => {
            const never = form.never_frozen.checked;
            form.mts.disabled = never;
            if (never) form.mts.checked = false;
            const mts = form.mts.checked;
            form.querySelectorAll('[data-mode-label]').forEach(x => x.classList.toggle('is-on', (x.dataset.modeLabel === 'MTS') === mts));
            form.querySelector('[data-mode-help]').textContent = t(mts ? 'help.mode_mts' : 'help.mode_mto');
            ['stock_min', 'stock_max', 'coverage_days'].forEach(f => { form[f].closest('label').hidden = !mts; });
        };
        form.addEventListener('change', sync);
        sync();
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            const data = {mode: form.mts.checked ? 'MTS' : 'MTO', never_frozen: form.never_frozen.checked};
            for (const f of ['stock_min', 'stock_max', 'coverage_days', 'batch_min', 'batch_multiple']) data[f] = ui.num(form[f].value);
            saveSettings(form, data, 3);
        });
    }

    function stepGroup(body, item) {
        const s = item.settings;
        const product = item.item_type === 'product';
        const equipment = item.equipment.length ? `<ul class="tfb-equipment">${item.equipment.map(w => `<li class="${w.type === 'four' ? 'is-oven' : ''}">
                <strong>${esc(w.name)}</strong> <span class="badge bg-light text-dark">${esc(t('ws.' + w.type))}</span>
                <span class="text-muted">${esc(t('eq.steps', {steps: w.steps.join(', ')}))}${w.temperatures_c.length ? ' · ' + w.temperatures_c.map(x => ui.fmt(x, 0, api.lang) + ' °C').join(', ') : ''}</span></li>`).join('')}</ul>`
            : `<p class="text-muted">${esc(t('eq.none'))}</p>`;
        body.innerHTML = `<form class="tfb-form" data-form>
            <label>${esc(t('f.primary_group_id'))}<select class="form-select" name="primary_group_id" data-field="primary_group_id">
                <option value="">—</option>${state.groups.map(g => `<option value="${g.id}" ${s.primary_group_id === g.id ? 'selected' : ''}>${esc(g.name)}</option>`).join('')}</select></label>
            ${product ? `<label>${esc(t('f.bake_state'))}<select class="form-select" name="bake_state" data-field="bake_state"><option value="">—</option>
                ${['none', 'par_baked', 'baked'].map(b => `<option value="${b}" ${s.bake_state === b ? 'selected' : ''}>${esc(t('bake.' + b))}</option>`).join('')}</select></label>` : ''}
            ${['shelf_life_workshop_days', 'shelf_life_shop_days'].map(f => `<label>${esc(t('f.' + f))}
                <input class="form-control" name="${f}" data-field="${f}" inputmode="decimal" value="${s[f] ?? ''}"></label>`).join('')}
            <div class="tfb-full"><h5>${esc(t('eq.title'))}</h5>${equipment}
                <p class="form-text">${esc(t('eq.help'))} <a href="#/item/${item.id}/5">${esc(t('step.5'))}</a></p></div>
            ${nav(3)}</form>`;
        const form = body.querySelector('[data-form]');
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            const data = {
                primary_group_id: form.primary_group_id.value ? Number(form.primary_group_id.value) : null,
                shelf_life_workshop_days: ui.num(form.shelf_life_workshop_days.value),
                shelf_life_shop_days: ui.num(form.shelf_life_shop_days.value),
            };
            if (product) data.bake_state = form.bake_state.value || null;
            saveSettings(form, data, 4);
        });
    }

    function stepBom(body, item) {
        if (!item.bom.length) {
            body.innerHTML = `<p class="text-muted">${esc(t('bom.empty'))}</p>${nav(4, false)}`;
            return;
        }
        body.innerHTML = `<form data-form><div class="table-responsive"><table class="table">
            <thead><tr><th>${esc(t('bom.component'))}</th><th class="text-end">${esc(t('bom.quantity'))}</th><th style="width:140px">${esc(t('bom.loss'))}</th></tr></thead>
            <tbody>${item.bom.map((b, i) => `<tr data-row="${i}">
                <td>${esc(b.name)} <span class="text-muted">(${esc(t('bom.kind.' + b.child_kind))})</span></td>
                <td class="text-end">${ui.fmt(b.quantity, 3, api.lang)} ${esc(b.unit || '')}</td>
                <td><input class="form-control form-control-sm" inputmode="decimal" data-field="losses.${i}.loss_pct" value="${b.loss_pct}"></td></tr>`).join('')}</tbody>
            </table></div>${nav(4)}</form>`;
        const form = body.querySelector('[data-form]');
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            ui.clearErrors(form);
            const losses = item.bom.map((b, i) => ({child_kind: b.child_kind, child_id: b.child_id, loss_pct: ui.num(form.querySelector(`[data-field="losses.${i}.loss_pct"]`).value) ?? 0}));
            try {
                await api.put(`/produits/items/${item.id}/bom-losses`, {losses});
                await saveSettings(form, {}, 5);
            } catch (err) { fail(form, err); }
        });
    }

    // --- Gamme -------------------------------------------------------------------------
    function stepRouting(body, item) {
        let steps = item.routing.map(s => ({...s, alternatives: s.alternatives.map(a => ({...a}))}));
        const delayFilter = (w) => ['fermentation', 'froid', 'surgelation'].includes(w.type);
        const draw = () => {
            body.innerHTML = `<p class="form-text">${esc(t('r.help'))}</p><form data-form>
                ${steps.length ? '' : `<p class="text-muted">${esc(t('r.empty'))}</p>`}
                ${steps.map((s, i) => `<div class="tfb-step" data-index="${i}">
                    <div class="tfb-step-head"><strong>${i + 1}.</strong>
                        <input class="form-control form-control-sm" data-k="name_pl" data-field="steps.${i}.name_pl" value="${esc(s.name_pl || '')}" placeholder="${esc(t('r.name_pl'))}" maxlength="100">
                        <input class="form-control form-control-sm" data-k="name_fr" data-field="steps.${i}.name_fr" value="${esc(s.name_fr || '')}" placeholder="${esc(t('r.name_fr'))}" maxlength="100">
                        <span class="tfb-step-tools">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-move="-1" data-write title="${esc(t('r.up'))}" ${i === 0 ? 'disabled' : ''}>↑</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-move="1" data-write title="${esc(t('r.down'))}" ${i === steps.length - 1 ? 'disabled' : ''}>↓</button>
                            <button type="button" class="btn btn-sm btn-outline-danger" data-remove data-write>${esc(t('r.remove'))}</button></span>
                    </div>
                    <div class="tfb-step-grid">
                        <label>${esc(t('r.workstation'))}${s.workstation_id ? ` <span class="badge bg-light text-dark">${esc(t('ws.' + wsType(s.workstation_id)))}</span>` : ''}<select class="form-select form-select-sm" data-k="workstation_id" data-field="steps.${i}.workstation_id"><option value="">—</option>${wsOptions(s.workstation_id)}</select></label>
                        ${TEMPERATURE_TYPES.includes(wsType(s.workstation_id)) ? `<label>${esc(t('r.temperature'))}<input class="form-control form-control-sm" inputmode="decimal" data-k="temperature_c" data-field="steps.${i}.temperature_c" value="${s.temperature_c ?? ''}"></label>` : ''}
                        <label>${esc(t('r.fixed'))}<input class="form-control form-control-sm" inputmode="numeric" data-k="fixed_time_s" data-field="steps.${i}.fixed_time_s" value="${s.fixed_time_s ?? 0}"></label>
                        <label>${esc(t('r.variable'))}<input class="form-control form-control-sm" inputmode="decimal" data-k="variable_time_s" data-field="steps.${i}.variable_time_s" value="${s.variable_time_s ?? 0}"></label>
                        <label>${esc(t('r.capacity'))}<input class="form-control form-control-sm" inputmode="decimal" data-k="capacity_per_pass" data-field="steps.${i}.capacity_per_pass" value="${s.capacity_per_pass ?? ''}"></label>
                        <label>${esc(t('r.operators'))}<input class="form-control form-control-sm" inputmode="decimal" data-k="operators_required" data-field="steps.${i}.operators_required" value="${s.operators_required ?? 1}"></label>
                        <label>${esc(t('r.level'))}<input class="form-control form-control-sm" inputmode="numeric" data-k="min_level_order" data-field="steps.${i}.min_level_order" value="${s.min_level_order ?? ''}"></label>
                        <label>${esc(t('r.delay'))}<input class="form-control form-control-sm" inputmode="decimal" data-k="delay_min" data-field="steps.${i}.delay_after_s" value="${s.delay_after_s ? s.delay_after_s / 60 : ''}"></label>
                        <label>${esc(t('r.delay_ws'))}<select class="form-select form-select-sm" data-k="delay_workstation_id" data-field="steps.${i}.delay_workstation_id"><option value="">${esc(t('r.none_ws'))}</option>${wsOptions(s.delay_workstation_id, delayFilter)}</select></label>
                        <label>${esc(t('r.cleaning'))}<input class="form-control form-control-sm" inputmode="decimal" data-k="cleaning_min" data-field="steps.${i}.cleaning_time_s" value="${s.cleaning_time_s ? s.cleaning_time_s / 60 : ''}"></label>
                        <label class="tfb-wide">${esc(t('r.alternatives'))}<select class="form-select form-select-sm" multiple size="3" data-k="alternatives" data-field="steps.${i}.alternatives">${wsOptions(null, w => w.id !== s.workstation_id).replace(/<option value="(\d+)"/g, (m, id) => s.alternatives.some(a => a.workstation_id === Number(id)) ? `<option value="${id}" selected` : m)}</select></label>
                    </div></div>`).join('')}
                <button type="button" class="btn btn-outline-primary btn-sm" data-add data-write><i class="bi bi-plus"></i> ${esc(t('r.add_step'))}</button>
                ${nav(5)}</form>`;
            const form = body.querySelector('[data-form]');
            form.addEventListener('input', collect);
            form.addEventListener('change', (e) => { collect(); if (e.target.dataset.k === 'workstation_id') draw(); });
            form.querySelector('[data-add]').addEventListener('click', () => { collect(); steps.push({name_pl: '', name_fr: '', fixed_time_s: 0, variable_time_s: 0, operators_required: 1, alternatives: []}); draw(); });
            form.querySelectorAll('[data-remove]').forEach(b => b.addEventListener('click', () => { collect(); steps.splice(Number(b.closest('[data-index]').dataset.index), 1); draw(); }));
            form.querySelectorAll('[data-move]').forEach(b => b.addEventListener('click', () => {
                collect();
                const i = Number(b.closest('[data-index]').dataset.index);
                const j = i + Number(b.dataset.move);
                [steps[i], steps[j]] = [steps[j], steps[i]];
                draw();
            }));
            form.addEventListener('submit', save);
            if (!item.can_edit) form.querySelectorAll('input, select, button[data-write]').forEach(x => x.disabled = true);
        };
        const collect = () => {
            body.querySelectorAll('[data-index]').forEach(card => {
                const s = steps[Number(card.dataset.index)];
                card.querySelectorAll('[data-k]').forEach(input => {
                    const k = input.dataset.k;
                    if (k === 'alternatives') {
                        const chosen = [...input.selectedOptions].map(o => Number(o.value));
                        s.alternatives = chosen.map(id => s.alternatives.find(a => a.workstation_id === id) || {workstation_id: id});
                    } else if (k === 'workstation_id' || k === 'delay_workstation_id') {
                        s[k] = input.value ? Number(input.value) : null;
                    } else if (k === 'delay_min') {
                        s.delay_after_s = input.value === '' ? 0 : Math.round(ui.num(input.value) * 60);
                    } else if (k === 'cleaning_min') {
                        s.cleaning_time_s = input.value === '' ? 0 : Math.round(ui.num(input.value) * 60);
                    } else if (k === 'name_pl' || k === 'name_fr') {
                        s[k] = input.value;
                    } else {
                        s[k] = input.value === '' ? null : ui.num(input.value);
                    }
                });
            });
        };
        const save = async (e) => {
            e.preventDefault();
            collect();
            const form = body.querySelector('[data-form]');
            ui.clearErrors(form);
            const payload = steps.map(s => {
                const out = {
                    name_pl: s.name_pl, workstation_id: s.workstation_id,
                    fixed_time_s: s.fixed_time_s ?? 0, variable_time_s: s.variable_time_s ?? 0,
                    operators_required: s.operators_required ?? 1, delay_after_s: s.delay_after_s || 0,
                    delay_workstation_id: s.delay_workstation_id ?? null,
                    cleaning_required: (s.cleaning_time_s || 0) > 0, cleaning_time_s: s.cleaning_time_s || 0,
                    min_level_order: s.min_level_order ?? null,
                    temperature_c: TEMPERATURE_TYPES.includes(wsType(s.workstation_id)) ? (s.temperature_c ?? null) : null,
                    alternatives: s.alternatives.map(a => {
                        const alt = {workstation_id: a.workstation_id};
                        for (const k of ['fixed_time_s', 'variable_time_s', 'capacity_per_pass', 'priority']) if (a[k] !== undefined) alt[k] = a[k];
                        return alt;
                    }),
                };
                if (s.id) out.id = s.id;
                if (s.name_fr) out.name_fr = s.name_fr;
                if (s.capacity_per_pass !== null && s.capacity_per_pass !== undefined && s.capacity_per_pass !== '') out.capacity_per_pass = s.capacity_per_pass;
                return out;
            });
            try {
                await api.put(`/produits/items/${item.id}/routing`, {steps: payload});
                await saveSettings(form, {}, 6);
            } catch (err) { fail(form, err); }
        };
        draw();
    }

    // --- Contrôle des temps et chronométrages --------------------------------------------
    function durationTable(d) {
        const steps = state.item.routing;
        return `<div class="table-responsive"><table class="table table-sm tfb-durations"><thead><tr><th>#</th><th class="text-end">${esc(t('t.passes'))}</th><th class="text-end">${esc(t('t.work'))}</th>
            <th class="text-end">${esc(t('t.cleaning'))}</th><th class="text-end">${esc(t('t.delay'))}</th><th class="text-end">${esc(t('t.labour'))}</th></tr></thead>
            <tbody>${d.steps.map((s, i) => `<tr><td>${s.step_no}. ${esc((steps[i] || {}).name || '')}</td><td class="text-end">${s.passes}</td>
                <td class="text-end">${ui.duration(s.work_s, api)}</td><td class="text-end">${s.cleaning_s ? ui.duration(s.cleaning_s, api) : '—'}</td>
                <td class="text-end">${s.delay_s ? ui.duration(s.delay_s, api) : '—'}</td><td class="text-end">${ui.duration(s.labour_s, api)}</td></tr>`).join('')}</tbody>
            <tfoot><tr><th>${esc(t('t.total'))}</th><th></th><th class="text-end">${ui.duration(d.work_s, api)}</th><th></th><th></th><th class="text-end">${ui.duration(d.labour_s, api)}</th></tr></tfoot></table></div>
            <p class="text-muted small">${esc(t('t.elapsed'))}: <strong>${ui.duration(d.elapsed_s, api)}</strong> · ${esc(t('t.per_unit'))}: <strong>${ui.fmt(d.labour_s_per_unit, 1, api.lang)} ${esc(t('unit.s'))}</strong></p>`;
    }

    async function stepTimes(body, item) {
        if (!item.routing.length) {
            body.innerHTML = `<p class="text-muted">${esc(t('r.empty'))}</p>${nav(6, false)}`;
            return;
        }
        body.innerHTML = `<div class="tfb-samples">${item.durations.map(d => `<div class="tfb-sample"><h6>${esc(t('t.quantity'))}: ${ui.fmt(d.quantity, 0, api.lang)} ${esc(item.base_unit)}</h6>${durationTable(d)}</div>`).join('')}</div>
            <form class="tfb-filters" data-qty><input class="form-control form-control-sm" inputmode="decimal" name="q" placeholder="${esc(t('t.quantity'))}">
                <button class="btn btn-sm btn-outline-primary">${esc(t('t.compute'))}</button></form><div data-custom></div>
            <h5 class="mt-4">${esc(t('t.studies'))}</h5>
            ${item.routing.map(s => `<details class="tfb-study" data-step="${s.id}"><summary><strong>${s.step_no}. ${esc(s.name)}</strong>
                <span class="text-muted">${esc(s.workstation.name)} · ${s.fixed_time_s} ${esc(t('unit.s'))} ${esc(t('t.component.fixed'))} · ${ui.fmt(s.variable_time_s, 3, api.lang)} ${esc(t('unit.s'))} ${esc(t('t.component.variable'))}</span></summary>
                <div data-study-body>${esc(t('loading'))}</div></details>`).join('')}
            ${nav(6, false)}`;
        body.querySelector('[data-qty]').addEventListener('submit', async (e) => {
            e.preventDefault();
            try {
                const d = await api.get(`/produits/items/${item.id}/duration?quantity=${encodeURIComponent(ui.num(e.target.q.value))}`);
                body.querySelector('[data-custom]').innerHTML = `<div class="tfb-sample"><h6>${esc(t('t.quantity'))}: ${ui.fmt(d.quantity, 0, api.lang)}</h6>${durationTable(d)}</div>`;
            } catch (err) { fail(null, err); }
        });
        body.querySelectorAll('details[data-step]').forEach(d => d.addEventListener('toggle', () => { if (d.open) loadStudies(d); }));
    }

    async function loadStudies(details) {
        const stepId = Number(details.dataset.step);
        const box = details.querySelector('[data-study-body]');
        let data;
        try { data = await api.get(`/produits/routing-steps/${stepId}/time-studies`); } catch (err) { box.textContent = err.message; return; }
        const canEdit = state.item.can_edit;
        const now = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16);
        box.innerHTML = `<div class="tfb-proposals">${data.proposals.map(p => `<div class="tfb-proposal ${p.alert ? 'is-alert' : ''}">
                <strong>${esc(t('t.proposal'))} (${esc(t('t.component.' + p.component))})</strong>
                <span>${esc(t('t.current'))}: ${ui.fmt(p.current_s, 3, api.lang)} ${esc(t('unit.s'))}</span>
                <span>${p.proposed_s === null ? '—' : ui.fmt(p.proposed_s, 3, api.lang) + ' ' + esc(t('unit.s'))}${p.deviation_pct !== null ? ' · ' + esc(t('t.deviation', {pct: ui.fmt(p.deviation_pct, 1, api.lang)})) : ''}</span>
                ${p.ready ? (canEdit ? `<button type="button" class="btn btn-sm btn-primary" data-apply="${p.component}">${esc(t('t.apply'))}</button>` : '')
                    : `<span class="text-muted">${esc(t('t.not_ready', {count: p.count, min: p.min_measurements}))}</span>`}
            </div>`).join('')}</div>
            <form class="tfb-measure" data-measure>
                <label>${esc(t('t.quantity'))}<input class="form-control form-control-sm" inputmode="decimal" name="quantity" data-field="quantity" required></label>
                <label>${esc(t('t.duration_min'))}<input class="form-control form-control-sm" inputmode="decimal" name="minutes" data-field="duration_s" required></label>
                <label>${esc(t('t.measured_at'))}<input class="form-control form-control-sm" type="datetime-local" name="measured_at" data-field="measured_at" value="${now}"></label>
                <label>${esc(t('t.note'))}<input class="form-control form-control-sm" name="note" data-field="note" maxlength="500"></label>
                <button class="btn btn-sm btn-outline-primary">${esc(t('t.add_measure'))}</button>
            </form>
            ${data.measurements.length ? `<table class="table table-sm"><thead><tr><th>${esc(t('t.measured_at'))}</th><th class="text-end">${esc(t('t.quantity'))}</th><th class="text-end">${esc(t('t.passes'))}</th>
                <th class="text-end">${esc(t('t.work'))}</th><th class="text-end">${esc(t('t.unit_time'))}</th><th></th></tr></thead><tbody>
                ${data.measurements.map(m => `<tr class="${m.excluded ? 'text-muted text-decoration-line-through' : ''}"><td>${esc(m.measured_at.slice(0, 16))}${m.employee ? '<br><small>' + esc(m.employee) + '</small>' : ''}</td>
                    <td class="text-end">${ui.fmt(m.quantity, 1, api.lang)}</td><td class="text-end">${m.passes}</td><td class="text-end">${ui.duration(m.duration_s, api)}</td>
                    <td class="text-end">${ui.fmt(m.unit_time_s, 3, api.lang)} <small>(${esc(t('t.component.' + m.component))})</small></td>
                    <td class="text-end"><button type="button" class="btn btn-sm btn-link" data-exclude="${m.id}" data-value="${m.excluded ? 0 : 1}">${esc(t(m.excluded ? 't.include' : 't.exclude'))}</button></td></tr>`).join('')}
                </tbody></table>` : `<p class="text-muted">${esc(t('t.no_studies'))}</p>`}`;
        const form = box.querySelector('[data-measure]');
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            ui.clearErrors(form);
            const payload = {quantity: ui.num(form.quantity.value), duration_s: Math.round(ui.num(form.minutes.value) * 60)};
            if (form.measured_at.value) payload.measured_at = form.measured_at.value.replace('T', ' ');
            if (form.note.value.trim()) payload.note = form.note.value.trim();
            try { await api.post(`/produits/routing-steps/${stepId}/time-studies`, payload); ui.notify(true, t('saved')); loadStudies(details); }
            catch (err) { fail(form, err); }
        });
        box.querySelectorAll('[data-exclude]').forEach(b => b.addEventListener('click', async () => {
            try { await api.patch(`/produits/time-studies/${b.dataset.exclude}`, {excluded: b.dataset.value === '1'}); loadStudies(details); } catch (err) { fail(null, err); }
        }));
        box.querySelectorAll('[data-apply]').forEach(b => b.addEventListener('click', async () => {
            try {
                await api.post(`/produits/routing-steps/${stepId}/apply-standard`, {component: b.dataset.apply});
                ui.notify(true, t('saved'));
                location.hash = `#/item/${state.item.id}/6?r=${Date.now()}`;
            } catch (err) { fail(null, err); }
        }));
    }

    function stepValidate(body, item) {
        const label = (c) => c.field.startsWith('routing.') ? t('check.step_time', {no: c.field.split('.')[1]})
            : c.code === 'BAKE_WITHOUT_OVEN' ? t('check.bake_oven') : t('check.' + c.field);
        const ready = item.checklist.every(c => c.ok);
        body.innerHTML = `<ul class="tfb-checklist">${item.checklist.map(c => `<li class="${c.ok ? 'ok' : 'ko'}"><i class="bi ${c.ok ? 'bi-check-circle' : 'bi-x-circle'}"></i> ${esc(label(c))}</li>`).join('')}</ul>
            ${item.settings.status === 'validated' ? `<p class="text-success">${esc(t('v.validated', {date: (item.settings.validated_at || '').slice(0, 16)}))}</p>` : (ready ? `<p>${esc(t('v.ready'))}</p>` : '')}
            <form data-form>${nav(7, t('v.validate'))}</form>`;
        const form = body.querySelector('[data-form]');
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            try { await api.post(`/produits/items/${item.id}/validate`); ui.notify(true, t('saved')); location.hash = `#/item/${item.id}/7?r=${Date.now()}`; }
            catch (err) { fail(form, err); }
        });
    }

    // --- Routage par ancre -----------------------------------------------------------
    async function route() {
        const hash = location.hash.replace(/^#/, '') || '/';
        const path = hash.split('?')[0];
        el.setAttribute('aria-busy', 'true');
        try {
            let m;
            if (path === '/new') await renderNew();
            else if ((m = path.match(/^\/item\/(\d+)\/([1-7])$/))) await renderItem(Number(m[1]), Number(m[2]));
            else await renderList();
        } catch (err) {
            el.innerHTML = header() + `<div class="alert alert-danger">${esc(err.message)}</div>`;
        } finally {
            el.removeAttribute('aria-busy');
        }
    }

    el.addEventListener('click', async (e) => {
        if (!e.target.closest('[data-lang-switch]')) return;
        await api.setLang(api.lang === 'pl' ? 'fr' : 'pl');
        await loadReferences();
        route();
    });
    window.addEventListener('hashchange', route);
    try {
        await loadReferences();
    } catch (err) {
        el.innerHTML = header() + `<div class="alert alert-danger">${esc(err.message)}</div>`;
        return;
    }
    route();
})();
