/*
 * Tabela do wypełnienia / Tableau à remplir : plik Excel z obecnymi danymi pracowni (stanowiska i pojemności,
 * zmiany, zespoły i obsada, trasy i godziny wyjazdu, parametry — m.in. pojemność zamrażarki), potem import
 * wypełnionego pliku : podgląd zmian wiersz po wierszu (nowy / zmiana / bez zmian / błąd), « Zastosuj » zapisuje
 * wszystko albo nic. Wiersz usunięty z pliku nie jest usuwany z ERP.
 */
(async function () {
    'use strict';
    const el = document.getElementById('tfbDataSheet');
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
    let canWrite = false;
    let canParams = false;
    let file = null;      // {name, base64}
    let result = null;    // aperçu ou résultat
    let busy = false;
    let showUnchanged = false;

    const head = () => `<div class="tfb-head"><div><h3>${esc(t('title'))}</h3><p class="text-muted mb-0">${esc(t('subtitle'))}</p></div>
        <div class="tfb-head-actions"><a class="btn btn-outline-secondary btn-sm" href="${esc(api.root)}/workshop/workstations"><i class="bi bi-robot"></i> ${esc(t('link_workstations'))}</a>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-lang-switch>${esc(t('lang_switch'))}</button></div></div>`;

    async function load() {
        el.innerHTML = head() + `<p class="text-muted">${esc(t('loading'))}</p>`;
        try {
            const me = await api.get('/auth/me');
            canWrite = (me.permissions || []).includes('prod.referentiels.write');
            canParams = (me.permissions || []).includes('prod.params.write');
        } catch (err) {
            el.innerHTML = head() + `<div class="alert alert-danger">${esc(err.message)}</div>`;
            bindHead();
            return;
        }
        draw();
    }

    const badge = (action) => ({
        create: `<span class="badge bg-success">${esc(t('action.create'))}</span>`,
        update: `<span class="badge bg-primary">${esc(t('action.update'))}</span>`,
        unchanged: `<span class="badge bg-light text-dark">${esc(t('action.unchanged'))}</span>`,
        error: `<span class="badge bg-danger">${esc(t('action.error'))}</span>`,
    })[action] || '';

    function preview() {
        if (!result) return '';
        const titles = Object.fromEntries((result.sheets || []).map(s => [s.key, s.title]));
        const rows = result.rows.filter(r => showUnchanged || r.action !== 'unchanged');
        const unchanged = result.rows.length - result.rows.filter(r => r.action !== 'unchanged').length;
        const status = result.applied
            ? `<div class="alert alert-success mb-3"><i class="bi bi-check-circle"></i> ${esc(t('applied', {n: result.change_count}))}</div>`
            : result.error_count
                ? `<div class="alert alert-danger mb-3"><i class="bi bi-exclamation-triangle"></i> ${esc(t('has_errors', {n: result.error_count}))}</div>`
                : result.change_count
                    ? `<div class="alert alert-info mb-3">${esc(t('ready', {n: result.change_count}))}</div>`
                    : `<div class="alert alert-secondary mb-3">${esc(t('no_change'))}</div>`;
        return `<div class="card"><div class="card-body">
            <h5>${esc(t('preview_title', {file: file ? file.name : ''}))}</h5>
            ${status}
            <div class="table-responsive"><table class="table table-sm align-middle mb-3">
                <thead><tr><th>${esc(t('col_sheet'))}</th><th class="text-end">${esc(t('action.create'))}</th><th class="text-end">${esc(t('action.update'))}</th>
                    <th class="text-end">${esc(t('action.unchanged'))}</th><th class="text-end">${esc(t('action.error'))}</th></tr></thead>
                <tbody>${Object.entries(result.summary).map(([k, s]) => `<tr><td>${esc(titles[k] || k)}</td><td class="text-end">${s.create}</td><td class="text-end">${s.update}</td>
                    <td class="text-end text-muted">${s.unchanged}</td><td class="text-end ${s.error ? 'text-danger fw-semibold' : ''}">${s.error}</td></tr>`).join('')}</tbody>
            </table></div>
            ${unchanged ? `<label class="form-check mb-2"><input type="checkbox" class="form-check-input" data-unchanged ${showUnchanged ? 'checked' : ''}> ${esc(t('show_unchanged', {n: unchanged}))}</label>` : ''}
            ${rows.length ? `<div class="table-responsive"><table class="table table-sm align-middle mb-3">
                <thead><tr><th>${esc(t('col_sheet'))}</th><th class="text-end">${esc(t('col_row'))}</th><th>${esc(t('col_key'))}</th><th></th><th>${esc(t('col_detail'))}</th></tr></thead>
                <tbody>${rows.map(r => `<tr class="${r.action === 'error' ? 'table-danger' : ''}">
                    <td class="small">${esc(titles[r.sheet] || r.sheet)}</td><td class="text-end small">${r.row}</td><td><strong>${esc(r.key)}</strong></td><td>${badge(r.action)}</td>
                    <td class="small">${r.errors.length ? r.errors.map(m => `<div class="text-danger">${esc(m)}</div>`).join('')
                        : r.changes.map(c => `<div><span class="text-muted">${esc(c.field)} :</span> ${r.action === 'update' ? `<s class="text-muted">${esc(c.before ?? '—')}</s> → ` : ''}<strong>${esc(c.after ?? '—')}</strong></div>`).join('')}</td>
                </tr>`).join('')}</tbody></table></div>` : ''}
            ${!result.applied ? `<div class="d-flex gap-2 flex-wrap">
                <button type="button" class="btn btn-success" data-apply ${busy || result.error_count || !result.change_count ? 'disabled' : ''}><i class="bi bi-check2-all"></i> ${esc(t('apply', {n: result.change_count}))}</button>
                <button type="button" class="btn btn-outline-secondary" data-recheck ${busy ? 'disabled' : ''}><i class="bi bi-arrow-repeat"></i> ${esc(t('recheck'))}</button></div>
                <p class="small text-muted mt-2 mb-0">${esc(t('apply_hint'))}</p>` : ''}
        </div></div>`;
    }

    function draw() {
        el.innerHTML = head() + `
            <div class="card"><div class="card-body">
                <p class="mb-2">${esc(t('intro'))}</p>
                <ul class="mb-2 small">
                    <li><strong>${esc(t('sheet.workstations'))}</strong> — ${esc(t('sheet.workstations_hint'))}</li>
                    <li><strong>${esc(t('sheet.shifts'))}</strong> — ${esc(t('sheet.shifts_hint'))}</li>
                    <li><strong>${esc(t('sheet.teams'))}</strong> — ${esc(t('sheet.teams_hint'))}</li>
                    <li><strong>${esc(t('sheet.tours'))}</strong> — ${esc(t('sheet.tours_hint'))}</li>
                    <li><strong>${esc(t('sheet.params'))}</strong> — ${esc(t('sheet.params_hint'))}</li>
                </ul>
                <p class="small text-muted mb-0">${esc(t('rules'))}</p>
            </div></div>
            <div class="card"><div class="card-body">
                <h5>1. ${esc(t('step_download'))}</h5>
                <p class="small text-muted">${esc(t('step_download_hint'))}</p>
                <button type="button" class="btn btn-primary" data-download ${busy ? 'disabled' : ''}><i class="bi bi-file-earmark-spreadsheet"></i> ${esc(t('download'))}</button>
            </div></div>
            <div class="card"><div class="card-body">
                <h5>2. ${esc(t('step_upload'))}</h5>
                ${canWrite ? `<p class="small text-muted">${esc(t('step_upload_hint'))}${canParams ? '' : ' ' + esc(t('no_params_right'))}</p>
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <input type="file" class="form-control w-auto" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" data-file aria-label="${esc(t('step_upload'))}">
                        <button type="button" class="btn btn-outline-primary" data-check ${busy || !file ? 'disabled' : ''}><i class="bi bi-search"></i> ${esc(t('check'))}</button>
                        ${file ? `<span class="small text-muted">${esc(file.name)}</span>` : ''}
                    </div>` : `<div class="alert alert-warning mb-0">${esc(t('no_write_right'))}</div>`}
            </div></div>
            ${preview()}`;
        bind();
    }

    function bindHead() {
        el.querySelector('[data-lang-switch]').addEventListener('click', async () => { await api.setLang(api.lang === 'pl' ? 'fr' : 'pl'); file = null; result = null; load(); });
    }

    function bind() {
        bindHead();
        el.querySelector('[data-download]').addEventListener('click', download);
        const input = el.querySelector('[data-file]');
        if (input) input.addEventListener('change', () => readFile(input.files[0]));
        const check = el.querySelector('[data-check]');
        if (check) check.addEventListener('click', () => send('preview'));
        const recheck = el.querySelector('[data-recheck]');
        if (recheck) recheck.addEventListener('click', () => send('preview'));
        const apply = el.querySelector('[data-apply]');
        if (apply) apply.addEventListener('click', () => {
            if (window.confirm(t('confirm_apply', {n: result.change_count}))) send('apply');
        });
        const unchanged = el.querySelector('[data-unchanged]');
        if (unchanged) unchanged.addEventListener('change', () => { showUnchanged = unchanged.checked; draw(); });
    }

    async function download() {
        busy = true; draw();
        try {
            const data = await api.get('/referentiels/data-sheet');
            const bytes = Uint8Array.from(atob(data.content_base64), c => c.charCodeAt(0));
            const url = URL.createObjectURL(new Blob([bytes], {type: data.mime}));
            const a = document.createElement('a');
            a.href = url;
            a.download = data.filename;
            document.body.appendChild(a);
            a.click();
            a.remove();
            window.setTimeout(() => URL.revokeObjectURL(url), 2000);
        } catch (err) {
            ui.notify(false, err.message);
        } finally {
            busy = false; draw();
        }
    }

    function readFile(f) {
        result = null;
        if (!f) { file = null; draw(); return; }
        if (f.size > 2000000) { file = null; ui.notify(false, t('too_large')); draw(); return; }
        const reader = new FileReader();
        reader.onload = () => { file = {name: f.name, base64: String(reader.result).replace(/^data:[^,]*,/, '')}; send('preview'); };
        reader.onerror = () => { file = null; ui.notify(false, t('read_error')); draw(); };
        reader.readAsDataURL(f);
    }

    async function send(mode) {
        if (!file) return;
        busy = true; draw();
        try {
            result = await api.post(`/referentiels/data-sheet/${mode}`, {file: file.base64});
            if (mode === 'apply') ui.notify(result.applied, result.applied ? t('applied', {n: result.change_count}) : t('has_errors', {n: result.error_count}));
        } catch (err) {
            result = null;
            ui.notify(false, err.message);
        } finally {
            busy = false; draw();
        }
    }

    load();
})();
