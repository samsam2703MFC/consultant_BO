// Plan tygodnia stanowiska [219] (makieta 2, tylko administrator) : zadania stałe — dni (Pn–Pt) albo co miesiąc / co kwartał,
// minuty jednego wykonania, link do strony ERP, zaliczenie automatyczne (kreator) ; czas rzeczywisty z rejestratora aktywności
// (średnio z 4 tygodni) i obciążenie dni. Zapis całego planu naraz : PUT /ajax/organization/week-plan/{id}.
(() => {
    const app = document.getElementById('weekPlanApp');
    if (!app) return;
    const esc = at.esc;
    const positionId = app.dataset.position;
    let P = JSON.parse(document.getElementById('weekPlanBoot').textContent);
    let rows = [];
    let removed = new Set();
    let dirty = false;
    let seq = 0;
    const hm = (m) => `${Math.floor(m / 60)} h ${String(m % 60).padStart(2, '0')}`;
    const has = (o, k) => Object.prototype.hasOwnProperty.call(o, k);
    const rowOf = (el) => rows.find(r => r.key === el.closest('[data-row]')?.dataset.row);

    function load(plan) {
        P = plan;
        rows = plan.tasks.map(t => ({...t, key: 't' + t.id, custom: false}));
        removed = new Set();
        dirty = false;
        render();
    }

    function linkOptions(r) {
        const custom = r.custom || (r.link_path && !has(P.links, r.link_path));
        return `<option value="">— bez linku —</option>`
            + Object.entries(P.links).map(([p, l]) => `<option value="${esc(p)}" ${!custom && p === r.link_path ? 'selected' : ''}>${esc(l)}</option>`).join('')
            + `<option value="__custom" ${custom ? 'selected' : ''}>Inny adres…</option>`;
    }

    function realHtml(r) {
        if (r.real === null || r.real === undefined) return '<span class="ad-real" title="Brak czasu aktywnego na tej stronie w ostatnich 4 tygodniach">—</span>';
        const title = `Średni czas aktywny na jedno wykonanie (4 tygodnie)${r.shared ? ' ; strona wspólna z innymi zadaniami — czas dzielony według planu' : ''}`;
        return `<span class="ad-real ${r.real_state}" title="${esc(title)}">${r.real} min${r.real_state === 'over' ? ' ↑' : ''}${r.shared ? '<small>strona wspólna</small>' : ''}</span>`;
    }

    function rowHtml(r) {
        const off = removed.has(r.key);
        const days = P.workdays.map(d => {
            const on = r.period === 'week' && r.days.includes(d);
            return `<button type="button" class="${on ? 'on' : ''}" data-day="${d}" aria-pressed="${on}" title="${on ? 'Usuń dzień' : 'Dodaj dzień'}">${esc(P.day_short[d])}</button>`;
        }).join('');
        const per = [['monthly', 'Mies.', 'Co miesiąc'], ['quarterly', 'Kw.', 'Co kwartał']]
            .map(([p, l, t]) => `<button type="button" class="per ${r.period === p ? 'on' : ''}" data-per="${p}" aria-pressed="${r.period === p}" title="${t}">${l}</button>`).join('');
        const custom = r.custom || (r.link_path && !has(P.links, r.link_path));
        const auto = P.link_auto[r.link_path || ''];
        return `<tr data-row="${r.key}" class="${off ? 'ad-off' : ''}${r.id ? '' : ' ad-new'}">
            <td><textarea class="ad-title" data-f="title_pl" rows="2" maxlength="200" placeholder="Nazwa nowego zadania" aria-label="Zadanie" ${off ? 'disabled' : ''}>${esc(r.title_pl)}</textarea>
                ${r.form_code ? '<small class="ad-auto"><i class="bi bi-journal-text"></i> kończy się protokołem</small>' : ''}</td>
            <td><select class="ad-sel cat" data-f="category_id" aria-label="Kategoria" ${off ? 'disabled' : ''}><option value="">—</option>
                ${P.categories.map(c => `<option value="${c.id}" ${Number(c.id) === r.category_id ? 'selected' : ''}>${esc(c.name_pl)}</option>`).join('')}</select></td>
            <td><span class="ad-days">${days}<span class="sep"></span>${per}</span></td>
            <td class="r"><input class="kw-in ad-min" data-f="minutes" type="number" inputmode="numeric" min="0" max="${P.max_minutes}" step="5" value="${r.minutes ?? ''}" aria-label="Minuty" ${off ? 'disabled' : ''}></td>
            <td><select class="ad-sel" data-f="link" aria-label="Link do strony ERP" ${off ? 'disabled' : ''}>${linkOptions(r)}</select>
                ${custom ? `<input class="form-control form-control-sm ad-link-custom" data-f="link_custom" value="${esc(r.link_path || '')}" placeholder="/strona-w-erp" maxlength="255" aria-label="Adres strony">` : ''}
                ${auto ? `<label class="ad-auto" title="Zalicza się samo ${esc(P.auto[auto])}"><input type="checkbox" data-f="auto" ${r.auto_code ? 'checked' : ''}> zalicza się samo</label>` : ''}</td>
            <td class="r">${realHtml(r)}</td>
            <td class="r"><button type="button" class="kw-btn ghost kw-sm" data-del title="${off ? 'Przywróć' : 'Usuń z planu'}" aria-label="${off ? 'Przywróć' : 'Usuń z planu'}">${off ? '<i class="bi bi-arrow-counterclockwise"></i>' : '<i class="bi bi-x-lg"></i>'}</button></td>
        </tr>`;
    }

    function loadHtml() {
        const per = Object.fromEntries(P.workdays.map(d => [d, 0]));
        rows.forEach(r => {
            if (removed.has(r.key) || r.period !== 'week') return;
            r.days.forEach(d => { if (has(per, d)) per[d] += Number(r.minutes) || 0; });
        });
        const week = Object.values(per).reduce((a, b) => a + b, 0);
        const scale = Math.max(120, ...Object.values(per));
        return `<div class="ad-load" style="--ad-days: ${P.workdays.length}">${P.workdays.map(d => `<div class="${per[d] > 240 ? 'over' : ''}">${esc(P.day_short[d])}<b>${per[d]} min</b>
                <div class="bar"><i style="width: ${Math.round(per[d] / scale * 100)}%"></i></div></div>`).join('')}
            <div>Tydzień<b>${hm(week)}</b>${Math.round(week / P.load.week_minutes * 100)} % z 40 h · reszta : bieżąca praca</div></div>`;
    }

    function render() {
        const users = P.users.map(u => u.displayed_name || u.login).join(' · ');
        app.innerHTML = `<div class="kw"><div class="kw-card">
            <h5>Plan tygodnia stanowiska <span class="kw-badge">${esc(P.position.name_pl)}</span>
                ${users ? `<span class="kw-badge">${esc(users)}</span>` : '<span class="kw-badge warn">nikt nie ma tego stanowiska</span>'}<span class="kw-badge ok">tylko administrator</span></h5>
            <p class="kw-lead">Zadania stałe stanowiska : dzień tygodnia (kilka dni = codziennie w te dni), czas w minutach i link do strony ERP. Kolumna « Rzeczywiście » : średni czas aktywny
                osób na stanowisku na tej stronie z ostatnich 4 tygodni (rejestrator aktywności) — widać, czy minuty są realne. Osoby na stanowisku widzą plan na stronie Dzisiaj.</p>
            <div class="table-responsive"><table class="kw-t" id="adPlan">
                <thead><tr><th>Zadanie</th><th>Kategoria</th><th>Kiedy</th><th class="r">Minuty</th><th>Link (strona ERP)</th><th class="r">Rzeczywiście</th><th></th></tr></thead>
                <tbody>${rows.map(rowHtml).join('') || '<tr><td colspan="7" class="at-empty">Brak zadań — dodaj pierwsze albo skopiuj z innego stanowiska.</td></tr>'}</tbody>
            </table></div>
            <div data-load>${loadHtml()}</div>
            <div class="kw-foot">
                <button type="button" class="kw-btn" data-add><i class="bi bi-plus-lg"></i> Dodaj zadanie</button>
                ${P.others.length ? '<button type="button" class="kw-btn" data-copy><i class="bi bi-files"></i> Kopiuj z innego stanowiska</button>' : '<span></span>'}
                <span class="d-flex gap-2 align-items-center"><span class="at-sub" data-dirty></span><button type="button" class="kw-btn pri" data-save>Zapisz plan tygodnia</button></span>
            </div>
        </div></div>`;
        markDirty(dirty);
    }

    function markDirty(on = true) {
        dirty = on;
        const el = app.querySelector('[data-dirty]');
        if (el) el.textContent = on ? 'Niezapisane zmiany' : '';
    }
    function refreshRow(r) {
        const tr = app.querySelector(`[data-row="${r.key}"]`);
        if (tr) tr.outerHTML = rowHtml(r);
        app.querySelector('[data-load]').innerHTML = loadHtml();
    }

    app.addEventListener('click', (e) => {
        const day = e.target.closest('[data-day]');
        const per = e.target.closest('[data-per]');
        if (day || per) {
            const r = rowOf(e.target);
            if (!r || removed.has(r.key)) return;
            if (day) {
                const d = Number(day.dataset.day);
                if (r.period !== 'week') { r.period = 'week'; r.days = [d]; }
                else r.days = r.days.includes(d) ? r.days.filter(x => x !== d) : [...r.days, d].sort((a, b) => a - b);
            } else if (r.period === per.dataset.per) {
                r.period = 'week';
                r.days = [];
            } else {
                r.period = per.dataset.per;
                r.days = [];
            }
            markDirty();
            refreshRow(r);
            return;
        }
        if (e.target.closest('[data-del]')) {
            const r = rowOf(e.target);
            if (!r) return;
            if (!r.id) rows = rows.filter(x => x !== r);
            else if (removed.has(r.key)) removed.delete(r.key);
            else removed.add(r.key);
            markDirty();
            if (!r.id) render(); else refreshRow(r);
            return;
        }
        if (e.target.closest('[data-add]')) {
            const r = {key: 'n' + (++seq), id: null, title_pl: '', category_id: null, period: 'week', days: [], minutes: null, link_path: null, auto_code: null,
                form_code: null, real: null, real_state: '', shared: false, custom: false};
            rows.push(r);
            markDirty();
            render();
            app.querySelector(`[data-row="${r.key}"] .ad-title`)?.focus();
            return;
        }
        if (e.target.closest('[data-copy]')) return copy();
        if (e.target.closest('[data-save]')) return save(e.target.closest('[data-save]'));
    });

    app.addEventListener('input', (e) => {
        const f = e.target.dataset.f;
        const r = f && rowOf(e.target);
        if (!r) return;
        if (f === 'title_pl') r.title_pl = e.target.value;
        else if (f === 'minutes') { r.minutes = e.target.value === '' ? null : Number(e.target.value); app.querySelector('[data-load]').innerHTML = loadHtml(); }
        else if (f === 'link_custom') r.link_path = e.target.value.trim() || null;
        else return;
        e.target.classList.remove('is-invalid');
        markDirty();
    });

    app.addEventListener('change', (e) => {
        const f = e.target.dataset.f;
        const r = f && rowOf(e.target);
        if (!r) return;
        if (f === 'category_id') r.category_id = e.target.value === '' ? null : Number(e.target.value);
        else if (f === 'auto') r.auto_code = e.target.checked ? (P.link_auto[r.link_path || ''] ?? null) : null;
        else if (f === 'link') {
            if (e.target.value === '__custom') { r.custom = true; r.link_path = null; }
            else { r.custom = false; r.link_path = e.target.value || null; }
            // Kreator zamykający zadanie : domyślnie włączone dla strony, która go ma.
            r.auto_code = P.link_auto[r.link_path || ''] ?? null;
            refreshRow(r);
            if (r.custom) app.querySelector(`[data-row="${r.key}"] [data-f=link_custom]`)?.focus();
        } else return;
        markDirty();
    });

    function invalid(r, selector, message) {
        const el = app.querySelector(`[data-row="${r.key}"] ${selector}`);
        el?.classList.add('is-invalid');
        el?.focus();
        at.notify(false, message);
        return false;
    }
    function check() {
        for (const r of rows) {
            if (removed.has(r.key)) continue;
            if (!r.title_pl.trim()) return invalid(r, '.ad-title', 'Podaj nazwę zadania.');
            if (r.period === 'week' && !r.days.length) return invalid(r, '.ad-days button', `« ${r.title_pl.trim()} » : wybierz dzień albo « Mies. » / « Kw. ».`);
            if (r.minutes !== null && (r.minutes < 0 || r.minutes > P.max_minutes || !Number.isInteger(r.minutes))) return invalid(r, '.ad-min', `Minuty od 0 do ${P.max_minutes}.`);
            if (r.custom && r.link_path && !/^\/[A-Za-z0-9/_\-?=&.%#]*$/.test(r.link_path)) return invalid(r, '[data-f=link_custom]', 'Link musi być ścieżką ERP, np. /purchasing.');
        }
        return true;
    }

    async function save(button) {
        if (!check()) return;
        button.disabled = true;
        const res = await at.send(`/ajax/organization/week-plan/${positionId}`, 'PUT', {
            tasks: rows.filter(r => !removed.has(r.key)).map(r => ({id: r.id, title_pl: r.title_pl.trim(), category_id: r.category_id, period: r.period, days: r.days,
                minutes: r.minutes, link_path: r.link_path, auto_code: r.auto_code})),
            removed: rows.filter(r => removed.has(r.key) && r.id).map(r => r.id),
        });
        button.disabled = false;
        at.notify(res.ok, res.message || (res.ok ? 'Zapisano.' : 'Błąd zapisu.'));
        if (res.ok && res.data) load(res.data);
    }

    async function copy() {
        const options = Object.fromEntries(P.others.map(o => [o.id, `${o.name} (${o.tasks})`]));
        const pick = window.Swal
            ? (await Swal.fire({title: 'Kopiuj z innego stanowiska', text: `Zadania stanowiska zostaną dodane do planu${dirty ? ' (niezapisane zmiany zostaną utracone)' : ''} ; te o tej samej nazwie są pomijane.`,
                input: 'select', inputOptions: options, showCancelButton: true, confirmButtonText: 'Kopiuj', cancelButtonText: 'Anuluj'})).value
            : window.prompt('Numer stanowiska do skopiowania : ' + Object.entries(options).map(([k, v]) => `${k} = ${v}`).join(', '));
        if (!pick) return;
        const res = await at.send(`/ajax/organization/week-plan/${positionId}/copy`, 'POST', {from: Number(pick)});
        at.notify(res.ok, res.message || (res.ok ? 'Skopiowano.' : 'Błąd.'));
        if (res.ok && res.data) load(res.data);
    }

    window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
    load(P);
})();
