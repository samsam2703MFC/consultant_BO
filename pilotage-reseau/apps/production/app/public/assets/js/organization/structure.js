/*
 * [368] Administracja › Struktura i obowiązki (makiety 70 i 70 B).
 *  Organigram : stanowiska aktywne w drzewie (przełożony = n_position.id_reports_to). Przeciągnij kartę na inną = nowy
 *  przełożony ; na strefę « góra » = bez przełożonego ; z palety (nieaktywne) = włączenie po potwierdzeniu. Na tablecie
 *  (bez przeciągania) : lista « Podlega pod » w panelu stanowiska. Cykle odrzuca serwer.
 *  Matryca : obowiązek → stanowisko → asystent kontroli (lub asystent « do utworzenia ») → rytm ; panel edycji,
 *  zadanie stanowiska jednym przyciskiem, wczytanie z kart stanowisk albo z pliku Word (.docx).
 */
(function () {
    'use strict';
    const page = document.getElementById('stPage');
    if (!page || !window.at) return;
    const esc = at.esc;
    const body = page.querySelector('[data-st-body]');
    let tab = page.dataset.tab === 'matrix' ? 'matrix' : 'chart';
    let D = null;            // {chart, matrix}
    let selected = null;     // stanowisko wybrane w organigramie
    let duty = null;         // obowiązek w panelu
    const F = {position: '', state: '', rhythm: '', q: ''};
    const fmtDay = (d) => d ? String(d).slice(0, 10).split('-').reverse().join('.') : '—';
    const STATE = {ok: ['✓ kontrolowane', 'ok'], proposed: ['＋ do utworzenia', 'proposed'], none: ['brak asystenta', 'none']};

    async function load() {
        const r = await at.send('/ajax/organization/structure', 'GET');
        if (!r.ok) { body.innerHTML = `<div class="alert alert-danger">${esc(r.message || 'Błąd')}</div>`; return; }
        D = {chart: r.chart, matrix: r.matrix};
        page.querySelector('[data-st-count]').textContent = D.matrix.tiles.duties;
        render();
    }
    function render() {
        page.querySelectorAll('[data-st-tab]').forEach(a => a.classList.toggle('on', a.dataset.stTab === tab));
        if (tab === 'matrix') renderMatrix(); else renderChart();
    }
    page.querySelector('.st-tabs').addEventListener('click', (e) => {
        const a = e.target.closest('[data-st-tab]');
        if (!a) return;
        e.preventDefault();
        tab = a.dataset.stTab;
        history.replaceState(null, '', '?tab=' + tab);
        render();
    });

    // --- Organigram -------------------------------------------------------------------------------------------------
    const byId = () => new Map(D.chart.map(p => [p.id, p]));
    function nodeHtml(p) {
        const cov = p.duties ? Math.round(p.covered / p.duties * 100) : null;
        return `<div class="st-node ${p.kind === 'atelier' ? 'is-atelier' : ''} ${selected === p.id ? 'is-sel' : ''}" data-node="${p.id}" tabindex="0">
            ${p.active ? '' : '<span class="k">nieaktywne</span>'}
            <h4>${esc(p.name)}</h4>
            <div class="m">${p.people} os.${p.duties ? ` · ${p.duties} obow. · ${p.covered} z asystentem` : ''}${p.tasks ? ` · ${p.tasks} zad.` : ''}</div>
            ${cov !== null ? `<div class="bar" title="obowiązki z asystentem kontroli : ${cov} %"><i style="width:${cov}%"></i></div>` : ''}</div>`;
    }
    let zoom = null;          // null = dopasuj do szerokości
    function renderChart() {
        const map = byId();
        const active = D.chart.filter(p => p.active);
        const kids = new Map();
        active.forEach(p => { const b = p.reports_to !== null && map.get(p.reports_to)?.active ? p.reports_to : 0; if (!kids.has(b)) kids.set(b, []); kids.get(b).push(p); });
        // Podwładni bez własnych podwładnych : pionowo (kolumna), żeby organigram nie rozjeżdżał się w szerokość.
        const tree = (pid) => (kids.get(pid) || []).map(p => {
            const ch = kids.get(p.id) || [];
            const stack = ch.length > 1 && ch.every(c => !kids.has(c.id));
            return `<li>${nodeHtml(p)}${ch.length ? `<ul class="${stack ? 'is-stack' : ''}">${tree(p.id)}</ul>` : ''}</li>`;
        }).join('');
        const inactive = D.chart.filter(p => !p.active);
        const sel = selected !== null ? map.get(selected) : null;
        body.innerHTML = `<div class="st-bar2"><span class="st-lbl mb-0">Nieaktywne</span><div class="st-pal">${inactive.length ? inactive.map(nodeHtml).join('') : '<span class="text-muted small">brak</span>'}</div>
                <div class="st-zoom"><button type="button" data-zoom="-">−</button><span data-zoom-label></span><button type="button" data-zoom="+">+</button><button type="button" data-zoom="fit">Dopasuj</button></div></div>
            <div class="st-canvas" data-canvas><div class="st-rootzone" data-root-zone>Upuść tutaj → stanowisko bez przełożonego (góra struktury)</div>
                <ul class="st-tree" data-tree>${tree(0)}</ul></div>
            <p class="st-help"><i class="bi bi-arrows-move"></i> Przeciągnij stanowisko na inne : będzie mu podlegać (zapis od razu). Nieaktywne (u góry) : przeciągnij na organigram, żeby włączyć. Pasek = obowiązki z asystentem kontroli. Ramka przerywana = stanowisko w pracowni. Działa myszką i palcem ; kliknij stanowisko, żeby zobaczyć szczegóły i zmienić « Podlega pod » z listy.</p>
            ${sel ? `<div class="st-drawer2">${sideHtml(sel, map)}</div>` : ''}`;
        applyZoom();
        bindChart();
    }
    // Dopasowanie : organigram mieści się w szerokości ramki (najmniej 45 %) ; − / + ręcznie.
    function applyZoom() {
        const canvas = body.querySelector('[data-canvas]'), t = body.querySelector('[data-tree]');
        if (!canvas || !t) return;
        t.style.zoom = 1;
        const fit = Math.max(0.45, Math.min(1, (canvas.clientWidth - 24) / t.scrollWidth));
        const z = zoom === null ? fit : zoom;
        t.style.zoom = z;
        const l = body.querySelector('[data-zoom-label]');
        if (l) l.textContent = Math.round(z * 100) + ' %';
    }
    window.addEventListener('resize', () => { if (tab === 'chart' && D) applyZoom(); });

    function sideHtml(p, map) {
        const subs = D.chart.filter(x => x.reports_to === p.id && x.active);
        const cov = p.duties ? Math.round(p.covered / p.duties * 100) : 0;
        const options = D.chart.filter(x => x.id !== p.id && x.active).map(x => `<option value="${x.id}" ${p.reports_to === x.id ? 'selected' : ''}>${esc(x.name)}</option>`).join('');
        return `<div class="st-card" style="position:relative"><button type="button" class="st-x" data-close-side aria-label="Zamknij">×</button><div class="st-lbl">Wybrane stanowisko</div><h4 class="mb-0" style="padding-right:34px">${esc(p.name)}</h4><div class="text-muted small">${esc(p.code)}${p.active ? '' : ' · nieaktywne'}</div>
            <div class="d-flex align-items-baseline gap-2 mt-2"><span class="st-big">${p.covered} / ${p.duties}</span><span class="text-muted small">obowiązków z asystentem kontroli</span></div>
            <div class="st-bar"><i style="width:${cov}%"></i></div>
            <dl class="st-kv"><dt>Podlega pod</dt><dd><select class="form-select form-select-sm" data-boss="${p.id}"><option value="">— góra struktury —</option>${options}</select></dd>
                <dt>Podwładni</dt><dd>${subs.length ? subs.map(s => `<span class="st-chip">${esc(s.name)}</span>`).join('') : '—'}</dd>
                <dt>Osoby</dt><dd>${p.people || '<span class="st-st none">nikt — przypisz w karcie</span>'}</dd>
                <dt>Do utworzenia</dt><dd>${p.proposed ? `<b style="color:#9b1c1c">${p.proposed}</b> asystentów` : '—'}</dd></dl>
            <div class="d-flex gap-2 flex-wrap mt-3"><a class="btn btn-sm btn-outline-primary" href="${at.root}/organization/positions/${p.id}">Karta stanowiska →</a>
                <button type="button" class="btn btn-sm btn-outline-primary" data-to-matrix="${p.id}">Matryca (${p.duties}) →</button></div></div>`;
    }
    async function move(id, boss) {
        const p = byId().get(id);
        let activate = false;
        if (p && !p.active) {
            if (!window.confirm(`Włączyć stanowisko « ${p.name} » ?`)) return;
            activate = true;
        }
        const r = await at.send(`/ajax/organization/structure/positions/${id}`, 'PUT', {reports_to_id: boss, activate});
        at.notify(r.ok, r.ok ? r.message : (r.errors?.[0]?.code === 'POSITION_CYCLE' || /cycle|cykl/i.test(r.message || '') ? 'Nie można : stanowisko podlegałoby samemu sobie (pętla).' : r.message || 'Błąd'));
        if (r.ok) { selected = id; await load(); }
    }
    // [369] Glisser-déposer en pointeur (souris, stylet, doigt) : le glisser natif HTML5 ne partait pas sur tous les
    // navigateurs et jamais au doigt. Le geste commence après 6 px ; la cible est l'élément sous le pointeur.
    let drag = null, noClick = false;
    function dropTarget(x, y) {
        const el = document.elementFromPoint(x, y);
        return el ? el.closest('[data-canvas] [data-node], [data-root-zone]') : null;
    }
    function endDrag(drop) {
        if (!drag) return;
        const d = drag;
        drag = null;
        document.removeEventListener('pointermove', onDragMove);
        document.removeEventListener('pointerup', onDragUp);
        document.removeEventListener('pointercancel', onDragCancel);
        document.removeEventListener('keydown', onDragKey);
        d.ghost?.remove();
        d.node.classList.remove('is-drag');
        body.querySelectorAll('.is-over').forEach(x => x.classList.remove('is-over'));
        if (!d.started) return;
        noClick = true;
        setTimeout(() => { noClick = false; }, 0);
        if (!drop || !d.over) return;
        const boss = d.over.dataset.rootZone !== undefined ? null : Number(d.over.dataset.node);
        if (boss === d.id) return;
        const p = byId().get(d.id);
        if (p && p.active && (p.reports_to ?? null) === boss) return; // déjà là
        move(d.id, boss);
    }
    function onDragMove(e) {
        const d = drag;
        if (!d) return;
        if (!d.started) {
            if (Math.hypot(e.clientX - d.x, e.clientY - d.y) < 6) return;
            d.started = true;
            const r = d.node.getBoundingClientRect();
            d.dx = -16; d.dy = -12; // la copie suit le pointeur en bas à droite : la cible surlignée reste visible
            d.ghost = d.node.cloneNode(true);
            d.ghost.classList.remove('is-sel');
            d.ghost.classList.add('st-ghost');
            d.ghost.style.width = r.width + 'px';
            document.body.appendChild(d.ghost);
            d.node.classList.add('is-drag');
        }
        e.preventDefault();
        d.ghost.style.left = (e.clientX - d.dx) + 'px';
        d.ghost.style.top = (e.clientY - d.dy) + 'px';
        let t = dropTarget(e.clientX, e.clientY);
        if (t && Number(t.dataset.node) === d.id) t = null;
        if (t !== d.over) { d.over?.classList.remove('is-over'); t?.classList.add('is-over'); d.over = t; }
        if (e.clientY < 50) window.scrollBy(0, -14); else if (e.clientY > window.innerHeight - 50) window.scrollBy(0, 14);
    }
    function onDragUp() { endDrag(true); }
    function onDragCancel() { endDrag(false); }
    function onDragKey(e) { if (e.key === 'Escape') endDrag(false); }

    function bindChart() {
        body.querySelectorAll('[data-node]').forEach(n => {
            n.addEventListener('pointerdown', (e) => {
                if (e.button !== 0 || drag) return;
                drag = {id: Number(n.dataset.node), node: n, x: e.clientX, y: e.clientY, started: false, over: null, ghost: null};
                document.addEventListener('pointermove', onDragMove);
                document.addEventListener('pointerup', onDragUp);
                document.addEventListener('pointercancel', onDragCancel);
                document.addEventListener('keydown', onDragKey);
            });
            n.addEventListener('dragstart', (e) => e.preventDefault()); // pas de glisser natif (image fantôme du navigateur)
            n.addEventListener('click', () => { if (noClick) return; selected = Number(n.dataset.node); renderChart(); });
        });
        body.querySelector('[data-close-side]')?.addEventListener('click', () => { selected = null; renderChart(); });
        body.querySelectorAll('[data-zoom]').forEach(b => b.addEventListener('click', () => {
            const cur = Number(body.querySelector('[data-tree]').style.zoom) || 1;
            zoom = b.dataset.zoom === 'fit' ? null : Math.max(0.4, Math.min(1.5, cur + (b.dataset.zoom === '+' ? 0.1 : -0.1)));
            applyZoom();
        }));
        body.querySelector('[data-boss]')?.addEventListener('change', (e) => move(Number(e.target.dataset.boss), e.target.value === '' ? null : Number(e.target.value)));
        body.querySelector('[data-to-matrix]')?.addEventListener('click', (e) => { F.position = e.currentTarget.dataset.toMatrix; tab = 'matrix'; history.replaceState(null, '', '?tab=matrix'); render(); });
    }

    // --- Matryca ----------------------------------------------------------------------------------------------------
    const wizOptions = (cur) => `<option value="">— brak —</option>` + D.matrix.wizards.map(w => `<option value="${w.code}" ${cur === w.code ? 'selected' : ''}>${esc(w.name)}</option>`).join('');
    const rhythmOptions = (cur) => Object.entries(D.matrix.rhythms).map(([k, v]) => `<option value="${k}" ${cur === k ? 'selected' : ''}>${esc(v)}</option>`).join('');
    const posOptions = (cur, all) => (all ? '<option value="">Stanowisko : wszystkie</option>' : '') + D.matrix.positions.map(p => `<option value="${p.id}" ${String(cur) === String(p.id) ? 'selected' : ''}>${esc(p.name)}${p.active ? '' : ' (nieaktywne)'}</option>`).join('');
    function rowsFiltered() {
        const q = F.q.trim().toLowerCase();
        return D.matrix.rows.filter(r => (!F.position || String(r.position_id) === String(F.position)) && (!F.state || r.state === F.state)
            && (!F.rhythm || r.rhythm === F.rhythm) && (!q || (r.text + ' ' + (r.number || '') + ' ' + (r.wizard || '') + ' ' + (r.proposed || '')).toLowerCase().includes(q)));
    }
    function renderMatrix() {
        const T = D.matrix.tiles;
        const rows = rowsFiltered();
        let html = '', pos = null, area = null;
        rows.forEach(r => {
            if (r.position_id !== pos) { pos = r.position_id; area = null; html += `<tr class="st-grp"><td colspan="7">${esc(r.position)}${r.position_active ? '' : ' (nieaktywne)'}</td></tr>`; }
            if ((r.area || '') !== (area || '') && r.area) { area = r.area; html += `<tr class="st-area"><td colspan="7">${r.area_no ? r.area_no + '. ' : ''}${esc(r.area)}</td></tr>`; }
            const st = STATE[r.state];
            html += `<tr data-duty="${r.id}" class="${duty === r.id ? 'is-sel' : ''}"><td class="text-muted">${esc(r.number || '')}</td><td>${esc(r.text)}</td>
                <td>${r.wizard ? `<span class="st-chip">✦ ${esc(r.wizard)}</span>` : r.proposed ? `<span class="st-chip" style="background:#fde8e8;color:#9b1c1c">＋ ${esc(r.proposed)}</span>` : '<span class="text-muted">—</span>'}</td>
                <td>${esc(D.matrix.rhythms[r.rhythm] || '—')}</td><td class="text-muted">${r.wizard_code ? (r.last_done ? fmtDay(r.last_done) : 'nigdy') : ''}</td>
                <td>${r.state === 'ok' ? (r.has_task ? '<span class="st-st ok">zadanie ✓</span>' : `<button type="button" class="btn btn-sm btn-outline-primary" data-add-task="${r.id}">+ zadanie</button>`) : ''}</td>
                <td><span class="st-st ${st[1]}">${st[0]}</span></td></tr>`;
        });
        const sel = duty !== null ? D.matrix.rows.find(r => r.id === duty) : null;
        body.innerHTML = `<div class="st-tiles">
                <div class="st-tile"><span>Obowiązki</span><b>${T.duties}</b><small>${T.positions} stanowisk</small></div>
                <div class="st-tile"><span>Z asystentem kontroli</span><b style="color:#1f6b33">${T.ok}</b><small>${T.duties ? Math.round(T.ok / T.duties * 100) : 0} % · bez zadania stanowiska : ${T.missing_task}</small></div>
                <div class="st-tile"><span>Asystent do utworzenia</span><b style="color:#9b1c1c">${T.proposed}</b><small>${esc(T.proposed_names.join(', ') || '—')}</small></div>
                <div class="st-tile"><span>Bez asystenta</span><b style="color:#7a5a00">${T.none}</b><small>kontrola tylko w karcie stanowiska</small></div></div>
            <div class="st-grid"><div class="card"><div class="card-body">
                <div class="st-filters">
                    <select class="form-select form-select-sm" data-f="position">${posOptions(F.position, true)}</select>
                    <select class="form-select form-select-sm" data-f="state"><option value="">Stan : wszystkie</option>${Object.entries(STATE).map(([k, v]) => `<option value="${k}" ${F.state === k ? 'selected' : ''}>${v[0]}</option>`).join('')}</select>
                    <select class="form-select form-select-sm" data-f="rhythm"><option value="">Rytm : wszystkie</option>${rhythmOptions(F.rhythm)}</select>
                    <input class="form-control form-control-sm" type="search" data-f="q" value="${esc(F.q)}" placeholder="Szukaj obowiązku…">
                    <span class="ms-auto d-flex gap-2 flex-wrap">
                        <button type="button" class="btn btn-sm btn-outline-primary" data-new-duty><i class="bi bi-plus-lg"></i> Obowiązek</button>
                        <button type="button" class="btn btn-sm btn-outline-primary" data-import-sheets><i class="bi bi-card-list"></i> Z kart stanowisk</button>
                        <label class="btn btn-sm btn-primary mb-0"><i class="bi bi-file-earmark-word"></i> Wczytaj zakres (.docx)<input type="file" accept=".docx" data-docx hidden></label></span></div>
                <div class="table-responsive"><table class="table table-sm st-table mb-0"><thead><tr><th>Nr</th><th>Obowiązek (zakres prac i obowiązków)</th><th>Asystent kontroli</th><th>Rytm</th><th>Ostatnio</th><th>Zadanie</th><th>Stan</th></tr></thead>
                    <tbody>${html || '<tr><td colspan="7" class="text-muted">Brak obowiązków dla tych filtrów.</td></tr>'}</tbody></table></div></div></div>
                <div class="st-side" data-drawer>${drawerHtml(sel)}</div></div>`;
        bindMatrix();
    }
    function drawerHtml(r) {
        const isNew = r === 'new';
        if (!r) return `<div class="st-card"><div class="st-lbl">Obowiązek</div><p class="text-muted mb-0">Dotknij obowiązku, żeby zmienić stanowisko, asystenta kontroli albo rytm.</p></div>`;
        const v = isNew ? {position_id: F.position || '', number: '', area: '', text: '', wizard_code: '', proposed: '', rhythm: 'none'} : r;
        return `<form class="st-card st-drawer" data-duty-form="${isNew ? '' : r.id}"><div class="st-lbl">${isNew ? 'Nowy obowiązek' : 'Obowiązek ' + esc(r.number || '')}</div>
            <label class="form-label small mb-1">Stanowisko</label><select class="form-select form-select-sm mb-2" name="position_id" required>${isNew && !v.position_id ? '<option value="">— wybierz —</option>' : ''}${posOptions(v.position_id, false)}</select>
            <div class="d-flex gap-2"><div style="width:90px"><label class="form-label small mb-1">Nr</label><input class="form-control form-control-sm mb-2" name="number" value="${esc(v.number || '')}" maxlength="12"></div>
                <div class="flex-fill"><label class="form-label small mb-1">Obszar</label><input class="form-control form-control-sm mb-2" name="area" value="${esc(v.area || '')}" maxlength="160"></div></div>
            <label class="form-label small mb-1">Obowiązek</label><textarea class="form-control form-control-sm mb-2" name="text" required maxlength="2000">${esc(v.text || '')}</textarea>
            <label class="form-label small mb-1">Asystent kontroli</label><select class="form-select form-select-sm mb-2" name="wizard_code" data-nullable>${wizOptions(v.wizard_code)}</select>
            <label class="form-label small mb-1">Brak asystenta ? Asystent do utworzenia (nazwa)</label><input class="form-control form-control-sm mb-2" name="proposed" value="${esc(v.proposed || '')}" maxlength="160" placeholder="np. Klienci bez zamówienia">
            <label class="form-label small mb-1">Rytm kontroli</label><select class="form-select form-select-sm mb-2" name="rhythm">${rhythmOptions(v.rhythm)}</select>
            ${!isNew && r.source ? `<p class="st-help mb-2">Źródło : ${esc(r.source)}</p>` : ''}
            ${!isNew && r.wizard_link ? `<p class="st-help mb-2"><a href="${at.root}${esc(r.wizard_link)}">Otwórz asystenta « ${esc(r.wizard)} » →</a> · ostatnio : ${r.last_done ? fmtDay(r.last_done) : 'nigdy'}</p>` : ''}
            <div class="d-flex gap-2 flex-wrap"><button class="btn btn-sm btn-primary">Zapisz</button>
                ${!isNew && r.state === 'ok' && !r.has_task ? `<button type="button" class="btn btn-sm btn-outline-primary" data-add-task="${r.id}">+ zadanie stanowiska</button>` : ''}
                ${!isNew ? `<button type="button" class="btn btn-sm btn-outline-danger ms-auto" data-del-duty="${r.id}"><i class="bi bi-trash"></i></button>` : ''}</div></form>`;
    }
    async function after(r, keep) {
        at.notify(r.ok, r.message || (r.ok ? 'Zapisano' : 'Błąd'));
        if (!r.ok) return;
        if (keep !== undefined) duty = keep;
        await load();
    }
    function bindMatrix() {
        body.querySelectorAll('[data-f]').forEach(el => el.addEventListener(el.tagName === 'INPUT' ? 'input' : 'change', () => {
            F[el.dataset.f] = el.value;
            if (el.tagName === 'INPUT') { clearTimeout(bindMatrix.t); bindMatrix.t = setTimeout(() => { renderMatrix(); const s = body.querySelector('[data-f="q"]'); s.focus(); s.setSelectionRange(s.value.length, s.value.length); }, 250); }
            else renderMatrix();
        }));
        body.querySelector('tbody').addEventListener('click', (e) => {
            const t = e.target.closest('[data-add-task]');
            if (t) { e.stopPropagation(); return addTask(Number(t.dataset.addTask)); }
            const tr = e.target.closest('[data-duty]');
            if (tr) { duty = Number(tr.dataset.duty); renderMatrix(); body.querySelector('[data-drawer]').scrollIntoView({block: 'nearest', behavior: 'smooth'}); }
        });
        body.querySelector('[data-new-duty]').addEventListener('click', () => { duty = 'new'; body.querySelector('[data-drawer]').innerHTML = drawerHtml('new'); bindDrawer(); });
        body.querySelector('[data-import-sheets]').addEventListener('click', async () => {
            if (!window.confirm('Wczytać obowiązki z kart stanowisk (« Zakres obowiązków ») dla stanowisk, które nie mają jeszcze obowiązków w matrycy ?')) return;
            const r = await at.send('/ajax/organization/duties/import-sheets', 'POST', {});
            at.notify(r.ok, r.ok ? `${r.message} (${r.count})` : r.message);
            if (r.ok) await load();
        });
        body.querySelector('[data-docx]').addEventListener('change', (e) => { const f = e.target.files[0]; e.target.value = ''; if (f) docx(f); });
        bindDrawer();
    }
    function bindDrawer() {
        const form = body.querySelector('[data-duty-form]');
        if (!form) return;
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const data = at.formData(form);
            data.wizard_code = form.wizard_code.value || null;
            data.proposed = form.proposed.value.trim() || null;
            data.position_id = Number(form.position_id.value) || null;
            const id = form.dataset.dutyForm;
            const r = id ? await at.send(`/ajax/organization/duties/${id}`, 'PUT', data) : await at.send('/ajax/organization/duties', 'POST', data);
            await after(r, id ? Number(id) : (r.id || null));
        });
        form.querySelector('[data-del-duty]')?.addEventListener('click', async (e) => {
            if (!window.confirm('Usunąć ten obowiązek z matrycy ?')) return;
            const r = await at.send(`/ajax/organization/duties/${e.currentTarget.dataset.delDuty}`, 'DELETE');
            await after(r, null);
        });
        form.querySelector('[data-add-task]')?.addEventListener('click', (e) => addTask(Number(e.currentTarget.dataset.addTask)));
    }
    async function addTask(id) {
        const r = D.matrix.rows.find(x => x.id === id);
        if (r && !window.confirm(`Dodać stanowisku « ${r.position} » zadanie « ${r.wizard} » (${D.matrix.rhythms[r.rhythm] === '—' ? 'codziennie' : D.matrix.rhythms[r.rhythm]}) ? Zadanie zamyka się samo, gdy asystent jest zakończony.`)) return;
        const res = await at.send(`/ajax/organization/duties/${id}/task`, 'POST', {});
        await after(res, id);
    }

    // --- Wczytanie zakresu obowiązków z pliku Word --------------------------------------------------------------------
    async function docx(file) {
        const fd = new FormData();
        fd.append('file', file);
        let r;
        try {
            const res = await fetch(at.root + '/ajax/organization/duties/docx', {method: 'POST', body: fd, credentials: 'same-origin', headers: {'Accept': 'application/json'}});
            r = {ok: res.ok, ...(await res.json().catch(() => ({message: 'Nieczytelna odpowiedź serwera.'})))};
        } catch (e) { r = {ok: false, message: 'Brak połączenia z serwerem.'}; }
        if (!r.ok) return at.notify(false, r.message || 'Błąd');
        const drawer = body.querySelector('[data-drawer]');
        drawer.innerHTML = `<form class="st-card" data-docx-form><div class="st-lbl">Wczytany plik — sprawdź przed zapisem</div>
            <b>${esc(r.title || file.name)}</b><div class="text-muted small">${r.count} obowiązków w ${r.areas.length} obszarach</div>
            <label class="form-label small mb-1 mt-2">Stanowisko (właściciel obowiązków)</label>
            <select class="form-select form-select-sm" name="position_id" required><option value="">— wybierz —</option>${posOptions(F.position, false)}</select>
            <div class="st-preview">${r.areas.map((a, ai) => `<h6>${a.no}. ${esc(a.title)}</h6>${a.items.map((it, ii) => `<label><input type="checkbox" checked data-item="${ai}:${ii}"> <span><b>${esc(it.number)}</b> ${esc(it.text)}</span></label>`).join('')}`).join('')}</div>
            <div class="d-flex gap-2"><button class="btn btn-sm btn-primary">Zapisz w matrycy</button><button type="button" class="btn btn-sm btn-outline-secondary" data-docx-cancel>Anuluj</button></div></form>`;
        const form = drawer.querySelector('[data-docx-form]');
        drawer.querySelector('[data-docx-cancel]').addEventListener('click', () => renderMatrix());
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const position = Number(form.position_id.value);
            if (!position) return at.notify(false, 'Wybierz stanowisko.');
            const items = [...form.querySelectorAll('[data-item]:checked')].map(cb => {
                const [ai, ii] = cb.dataset.item.split(':').map(Number);
                const a = r.areas[ai];
                return {number: a.items[ii].number, text: a.items[ii].text, area_no: a.no, area: a.title};
            });
            const res = await at.send('/ajax/organization/duties/docx/save', 'POST', {position_id: position, items, source: r.source});
            at.notify(res.ok, res.ok ? `${res.message} (${res.count})` : res.message);
            if (res.ok) { F.position = String(position); duty = null; await load(); }
        });
    }

    load();
})();
