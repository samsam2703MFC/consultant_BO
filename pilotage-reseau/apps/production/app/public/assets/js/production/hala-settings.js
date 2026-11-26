// [409] Produkcja › Tablet hali : kroki dnia grup (« Mój dzień » na tablecie) et strefy sprzątania. Une carte par groupe :
// ścieżka prowadzona, étapes cochées (ordre fixe sur la tablette), strefy (nom PL / FR, icône, retirer / remettre, ajouter).
// Envoi : POST /ajax/production/hala/group et /zone (droit « hala ») ; la page se redessine avec la réponse.
(function () {
    'use strict';
    const app = document.getElementById('hsApp');
    if (!app) return;
    let D = JSON.parse(document.getElementById('hsBoot').textContent);
    const esc = at.esc;
    const STEP = {make: 'Co robię dziś', bags: 'Skanuj worki', chill: 'Schładzanie', clean: 'Sprzątanie', pack: 'Pakuję'};
    const ICON = {table: 'Stół', machine: 'Maszyna', floor: 'Podłoga', bin: 'Kosz', oven: 'Piec', fridge: 'Chłodnia', sink: 'Zlew', tools: 'Narzędzia', other: 'Inne'};

    function draw() {
        app.innerHTML = `<div class="alert alert-info">Schładzanie : najwyżej <b>${esc(String(D.chill.target_c).replace('.', ','))} °C</b> w środku produktu, najpóźniej po <b>${esc(D.chill.max_minutes)} min</b>
            · zmiana ${esc(D.shift.start)}–${esc(D.shift.end)} · « Zmiana » widzą stanowiska : ${esc(D.lead_positions.join(', '))} — Ustawienia › Parametry (hala.*).</div>
            ${D.groups.map(group).join('')}`;
        bind();
    }

    function group(g) {
        const s = g.settings;
        const sw = (k, label) => `<div class="form-check form-switch form-check-inline"><input class="form-check-input" type="checkbox" id="hs-${g.id}-${k}" data-k="${k}" ${s[k] ? 'checked' : ''}>
            <label class="form-check-label" for="hs-${g.id}-${k}">${esc(label)}</label></div>`;
        const zone = (z) => `<tr class="${z.active ? '' : 'text-muted'}" data-zone="${z.id}"><td><input class="form-control form-control-sm" name="name_pl" value="${esc(z.name_pl)}" maxlength="80"></td>
            <td><input class="form-control form-control-sm" name="name_fr" value="${esc(z.name_fr || '')}" maxlength="80"></td>
            <td><select class="form-control form-control-sm" name="icon">${Object.entries(ICON).map(([k, l]) => `<option value="${k}" ${z.icon === k ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select></td>
            <td>${z.active ? '<span class="at-pill at-pill-ok">aktywna</span>' : '<span class="at-pill at-pill-muted">usunięta</span>'}</td>
            <td class="text-end"><button type="button" class="btn btn-sm btn-outline-dark" data-save-zone>Zapisz</button>
                <button type="button" class="btn btn-sm btn-link" data-toggle-zone="${z.active ? 0 : 1}">${z.active ? 'Usuń' : 'Przywróć'}</button></td></tr>`;
        return `<div class="card mb-3" data-group="${g.id}"><div class="card-body">
            <h5 class="mb-2">${esc(g.name)} <small class="text-muted">${esc(g.code)}${g.saved ? '' : ' · ustawienie domyślne'}</small></h5>
            <div class="mb-2">${sw('guided', '« Mój dzień » na tablecie grupy')}</div>
            <div class="mb-2">${D.steps.map(k => sw(k, STEP[k])).join('')}
                <button type="button" class="btn btn-sm btn-primary ms-2" data-save-group>Zapisz kroki</button></div>
            ${s.clean ? `<table class="table table-sm align-middle mb-1"><thead><tr><th>Strefa (PL)</th><th>Strefa (FR)</th><th>Ikona</th><th>Stan</th><th></th></tr></thead>
                <tbody>${g.zones.map(zone).join('') || '<tr><td colspan="5" class="text-muted">Brak stref — krok « Sprzątanie » zalicza się sam.</td></tr>'}
                <tr data-new><td><input class="form-control form-control-sm" name="name_pl" placeholder="np. Stół" maxlength="80"></td>
                    <td><input class="form-control form-control-sm" name="name_fr" placeholder="ex. Table" maxlength="80"></td>
                    <td><select class="form-control form-control-sm" name="icon">${Object.entries(ICON).map(([k, l]) => `<option value="${k}">${esc(l)}</option>`).join('')}</select></td>
                    <td></td><td class="text-end"><button type="button" class="btn btn-sm btn-outline-dark" data-add-zone>Dodaj strefę</button></td></tr></tbody></table>` : ''}
        </div></div>`;
    }

    async function send(url, body, button) {
        if (button) button.disabled = true;
        const r = await at.send(url, 'POST', body);
        if (button) button.disabled = false;
        at.notify(r.ok, r.message || (r.ok ? 'Zapisano.' : 'Błąd.'));
        if (r.ok && r.data) { D = r.data; draw(); }
    }

    function bind() {
        app.querySelectorAll('[data-group]').forEach(card => {
            const gid = Number(card.dataset.group);
            card.querySelector('[data-save-group]').addEventListener('click', (e) => {
                const body = {group_id: gid};
                card.querySelectorAll('[data-k]').forEach(c => { body[c.dataset.k] = c.checked; });
                send('/ajax/production/hala/group', body, e.currentTarget);
            });
            card.querySelectorAll('[data-zone]').forEach(row => {
                const val = (n) => row.querySelector(`[name=${n}]`).value;
                const body = () => ({id: Number(row.dataset.zone), group_id: gid, name_pl: val('name_pl'), name_fr: val('name_fr'), icon: val('icon')});
                row.querySelector('[data-save-zone]').addEventListener('click', (e) => send('/ajax/production/hala/zone', body(), e.currentTarget));
                row.querySelector('[data-toggle-zone]').addEventListener('click', (e) => send('/ajax/production/hala/zone', {...body(), active: e.currentTarget.dataset.toggleZone === '1'}, e.currentTarget));
            });
            const add = card.querySelector('[data-new]');
            add?.querySelector('[data-add-zone]').addEventListener('click', (e) => {
                const v = (n) => add.querySelector(`[name=${n}]`).value;
                send('/ajax/production/hala/zone', {group_id: gid, name_pl: v('name_pl'), name_fr: v('name_fr'), icon: v('icon')}, e.currentTarget);
            });
        });
    }

    draw();
})();
