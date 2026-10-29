/*
 * Ustawienia › Pozostałe › Wszystkie parametry (audyt 2, N6) : lista parametrów modułu produkcji (n_prod_param) przez
 * API (GET /referentiels/params) i zapis jednej wartości (PATCH /referentiels/params/{kod}) — te same zakresy i reguły
 * co API i import tabeli Excel (App\Domain\Params\ParamRules). JSON : tylko do odczytu.
 */
(function () {
    'use strict';
    const box = document.getElementById('allParams');
    if (!box || !window.TfbWorkshop) return;
    const {WorkshopApi, ui} = window.TfbWorkshop;
    const api = new WorkshopApi(box);
    const esc = ui.esc;
    const filter = document.getElementById('paramFilter');
    let rows = [];

    const input = (p) => {
        if (p.value_type === 'bool') return `<select class="form-select form-select-sm" data-v><option value="1" ${p.value ? 'selected' : ''}>tak</option><option value="0" ${p.value ? '' : 'selected'}>nie</option></select>`;
        if (p.value_type === 'json') return `<code class="small">${esc(JSON.stringify(p.value)).slice(0, 120)}</code>`;
        const type = p.value_type === 'time' ? 'time' : (['int', 'decimal'].includes(p.value_type) ? 'number' : 'text');
        const step = p.value_type === 'decimal' ? 'any' : '1';
        return `<input class="form-control form-control-sm" type="${type}" ${type === 'number' ? `step="${step}" min="0"` : ''} value="${esc(p.value)}" data-v>`;
    };
    const draw = () => {
        const q = (filter.value || '').trim().toLowerCase();
        const list = rows.filter(p => !q || p.code.toLowerCase().includes(q) || String(p.description || '').toLowerCase().includes(q));
        const groups = {};
        list.forEach(p => { (groups[p.code.split('.')[0]] ||= []).push(p); });
        box.innerHTML = Object.keys(groups).sort().map(g => `<h6 class="mt-3 mb-1 text-uppercase small text-muted">${esc(g)}</h6>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0"><tbody>${groups[g].map(p => `<tr data-code="${esc(p.code)}">
                <td style="width:40%"><strong>${esc(p.description || p.code)}</strong><div class="small text-muted"><code>${esc(p.code)}</code>${p.is_default ? '' : ` · domyślnie ${esc(JSON.stringify(p.default_value))}`}</div></td>
                <td style="width:220px">${input(p)}</td><td class="small text-muted" style="width:60px">${esc(p.unit || '')}</td>
                <td class="text-end">${p.value_type === 'json' ? '' : '<button type="button" class="btn btn-sm btn-outline-primary" data-save>Zapisz</button>'}</td></tr>`).join('')}</tbody></table></div>`).join('')
            || '<p class="text-muted mb-0">Brak parametrów.</p>';
        box.querySelectorAll('[data-save]').forEach(b => b.addEventListener('click', async () => {
            const tr = b.closest('tr');
            const p = rows.find(x => x.code === tr.dataset.code);
            const el = tr.querySelector('[data-v]');
            let value = el.value;
            if (p.value_type === 'bool') value = value === '1';
            else if (p.value_type === 'int') value = Number.parseInt(value, 10);
            else if (p.value_type === 'decimal') value = Number(String(value).replace(',', '.'));
            b.disabled = true;
            try {
                const res = await api.patch(`/referentiels/params/${encodeURIComponent(p.code)}`, {value});
                Object.assign(p, res);
                ui.notify(true, `Zapisano : ${p.description || p.code}.`);
                draw();
            } catch (err) { b.disabled = false; ui.notify(false, err.message); }
        }));
    };
    (async () => {
        try {
            await api.init();
            rows = (await api.list('/referentiels/params')).data || [];
            draw();
            if (location.hash === '#params') document.getElementById('params')?.scrollIntoView({block: 'start'});
        } catch (err) {
            box.innerHTML = `<p class="text-danger mb-0">${esc(err.status === 403 ? 'Brak uprawnień do parametrów modułu.' : err.message)}</p>`;
        }
    })();
    filter.addEventListener('input', draw);
})();
