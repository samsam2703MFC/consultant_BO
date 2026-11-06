// Ocena dostawców [230] (makieta 6) : 1. Oceny kwartału (A / B / C, decyzja + notatka) ; 2. Surowce do zapytania
// (+5 % ceny, dostawca « zmień », kluczowy z < 3 dostawcami) ; 3. Zapytanie ofertowe (szkic w Zapytaniach) →
// « Zatwierdź ocenę » = zadanie zaliczone. Nic nie jest wysyłane do dostawców z tej strony.
(() => {
    const app = document.getElementById('srApp');
    if (!app) return;
    const esc = at.esc;
    let D = JSON.parse(document.getElementById('srBoot').textContent);
    let step = 1;
    let showAll = false;
    let tender = null;   // {id, code}
    const LIMIT = 6;
    const decisions = new Map(D.suppliers.map(s => [s.id, {decision: s.decision, note: s.note || ''}]));
    let mats = new Set(D.materials.map(m => m.id));
    let sups = null;
    const money = (v) => Number(v || 0).toLocaleString('pl-PL', {maximumFractionDigits: 0}) + ' zł';
    const pct = (v) => v === null || v === undefined ? '—' : (v > 0 ? '+' : (v < 0 ? '−' : '')) + Math.abs(v).toLocaleString('pl-PL', {minimumFractionDigits: 1, maximumFractionDigits: 1}) + ' %';
    const plural = (n, one, few, many) => n === 1 ? one : ([2, 3, 4].includes(n % 10) && ![12, 13, 14].includes(n % 100) ? few : many);
    const DEC_CLASS = {keep: 'a', watch: 'b', change: 'c'};

    // Dostawcy do zapytania : obecni dostawcy wybranych surowców + dostawcy tych samych kategorii (z e-mailem).
    function candidates() {
        const chosen = D.materials.filter(m => mats.has(m.id));
        const cats = new Set(chosen.map(m => m.id_category).filter(Boolean));
        const ids = new Set(chosen.map(m => m.id));
        return D.candidates.filter(c => c.materials.some(m => ids.has(m)) || c.categories.some(k => cats.has(k)))
            .map(c => ({...c, current: c.materials.some(m => ids.has(m))})).sort((a, b) => (b.current - a.current) || a.name.localeCompare(b.name, 'pl')).slice(0, 30);
    }
    function supSet() {
        if (sups === null) sups = new Set(candidates().filter(c => (decisions.get(c.id)?.decision || c.decision) !== 'change').map(c => c.id));
        return sups;
    }

    function steps() {
        const s = (n, label, sub) => `<button type="button" class="s ${step === n ? 'on' : (step > n ? 'ok' : '')}" data-step="${n}"><span class="n">${step > n ? '✓' : n}</span><span>${label}<small>${sub}</small></span></button>`;
        return `<div class="rcv-steps">${s(1, 'Oceny', `${D.suppliers.length} ${plural(D.suppliers.length, 'dostawca', 'dostawców', 'dostawców')}`)}
            ${s(2, 'Surowce do zapytania', `propozycja : ${D.materials.length}`)}${s(3, 'Zapytanie ofertowe', tender ? tender.code : 'szkic → Zapytania')}</div>`;
    }

    function side() {
        const chosen = D.materials.filter(m => mats.has(m.id)).slice(0, 7);
        const why = (m) => [m.change !== null && m.change >= 5 ? `<span class="rcv-up">${pct(m.change)}</span>` : '', m.reasons.includes('supplier') ? 'dostawca C' : '',
            m.reasons.includes('few') ? `${m.suppliers} ${plural(m.suppliers, 'dostawca', 'dostawców', 'dostawców')}` : '', m.share >= 10 ? `${String(m.share).replace('.', ',')} % zakupów` : ''].filter(Boolean).join(' · ');
        return `<div class="rcv-side"><div class="kw-card"><h5 class="rcv-h">Krok 2 · Propozycja : ${D.materials.length} ${plural(D.materials.length, 'surowiec', 'surowce', 'surowców')}</h5><div class="rcv-list">
                ${chosen.map(m => `<span>✓ ${esc(m.name)} · ${why(m)}</span>`).join('') || '<span class="at-sub">Brak surowców spełniających regułę.</span>'}
                <span class="at-sub">Reguła : cena +5 % w kwartale, dostawca C albo kluczowy surowiec (≥ 5 % zakupów) z mniej niż 3 dostawcami.</span></div></div>
            <div class="kw-card"><h5 class="rcv-h">Krok 3 · Zapytanie</h5><div class="rcv-list">
                <span>${tender ? esc(tender.code) + ' · ' : ''}${mats.size} ${plural(mats.size, 'surowiec', 'surowce', 'surowców')} · ${supSet().size} ${plural(supSet().size, 'dostawca', 'dostawców', 'dostawców')} (obecni + z katalogu)</span>
                <span>Odpowiedź do ${esc(D.reply_deadline.slice(8, 10) + '.' + D.reply_deadline.slice(5, 7))} · e-mail po polsku (język do zmiany w Zapytaniach)</span>
                <span class="at-sub">Tworzy szkic w Zapytaniach ofertowych — wysyłasz po sprawdzeniu.</span></div></div>
            ${D.done ? `<div class="kw-card"><h5 class="rcv-h"><i class="bi bi-check-circle"></i> Zadanie zaliczone</h5><div class="at-sub">${esc(D.done.summary)}</div></div>` : ''}</div>`;
    }

    function stepGrades() {
        const g = D.grades;
        const rows = (showAll ? D.suppliers : D.suppliers.slice(0, LIMIT)).map(s => {
            const d = decisions.get(s.id);
            const opts = Object.entries(D.decisions).map(([k, l]) => `<option value="${k}" ${d.decision === k ? 'selected' : ''}>${esc(l)}</option>`).join('');
            return `<tr data-id="${s.id}"><td><span class="rcv-grade ${(s.grade || '').toLowerCase()}" title="${s.score === null ? 'brak danych' : 'ocena ' + String(s.score).replace('.', ',')}">${s.grade || '—'}</span></td>
                <td><b>${esc(s.name)}</b><small>${esc(s.material_names.join(', ').toLowerCase())}${s.materials ? ' · ' + s.materials : ''}</small></td>
                <td class="r">${s.value ? money(s.value) : '—'}</td><td class="r">${s.deliveries || '—'}</td><td class="r">${s.on_time === null ? '—' : String(Math.round(s.on_time)) + ' %'}</td>
                <td class="r">${s.nc}</td><td class="r">${s.price === null ? '—' : `<span class="${s.price > 0 ? 'rcv-up' : (s.price < 0 ? 'rcv-down' : '')}">${pct(s.price)}</span>`}</td>
                <td><select class="form-select rcv-dec ${DEC_CLASS[d.decision]}" data-dec aria-label="Decyzja ${esc(s.name)}">${opts}</select>
                    <input class="form-control mt-1" data-note maxlength="500" value="${esc(d.note)}" placeholder="Notatka (opcjonalnie)" style="min-height:36px;font-size:12.5px"></td></tr>`;
        }).join('');
        const more = !showAll && D.suppliers.length > LIMIT ? `<tr><td colspan="8"><button type="button" class="kw-btn ghost" data-more>+ ${D.suppliers.length - LIMIT} ${plural(D.suppliers.length - LIMIT, 'dostawca', 'dostawców', 'dostawców')} (pokaż)</button></td></tr>` : '';
        return `<div class="kw-card"><h5 class="rcv-h">Oceny kwartału <span class="kw-badge ok">${g.A} A</span><span class="kw-badge warn">${g.B} B</span><span class="kw-badge bad">${g.C} C</span><span class="rcv-sp"></span>
                <span class="at-sub">ocena = terminowość ${D.weights.on_time} % · niezgodności ${D.weights.nc} % · ceny ${D.weights.price} %</span></h5>
            ${D.suppliers.length ? `<div class="table-responsive"><table class="rcv-tbl"><thead><tr><th></th><th>Dostawca</th><th class="r">Zakupy kw.</th><th class="r">Dostawy</th><th class="r">Na czas</th><th class="r">NC</th><th class="r">Ceny</th><th>Decyzja</th></tr></thead>
                <tbody>${rows}${more}</tbody></table></div>` : '<p class="at-empty">Brak danych dostawców w tym kwartale (przyjęcia, faktury, niezgodności, ceny).</p>'}
            <div class="rcv-row" style="margin-top:10px"><span class="at-sub">Decyzja z oceny (C → zmień dostawcę) — popraw, gdy trzeba. Notatka zapisuje się przy dostawcy.</span><span class="rcv-sp"></span>
                <button type="button" class="kw-btn pri" data-next1>Dalej : surowce do zapytania <i class="bi bi-arrow-right"></i></button></div></div>`;
    }

    function stepMaterials() {
        const reason = {price: 'cena +5 %', supplier: 'dostawca C', few: '< 3 dostawców'};
        const rows = D.materials.map(m => `<tr data-mat="${m.id}" class="${mats.has(m.id) ? '' : 'is-off'}"><td><input type="checkbox" data-pick-mat ${mats.has(m.id) ? 'checked' : ''} aria-label="${esc(m.name)}"></td>
            <td><b>${esc(m.name)}</b><small>${esc(m.category || '')}</small></td><td class="r">${m.change === null ? '—' : `<span class="${m.change > 0 ? 'rcv-up' : 'rcv-down'}">${pct(m.change)}</span>`}</td>
            <td class="r">${m.share ? String(m.share).replace('.', ',') + ' %' : '—'}</td><td class="r">${m.suppliers}</td><td>${m.reasons.map(r => `<span class="kw-badge ${r === 'price' ? 'bad' : 'warn'}">${reason[r]}</span>`).join(' ')}</td></tr>`).join('');
        return `<div class="kw-card"><h5 class="rcv-h">Surowce do zapytania <span class="kw-badge">${mats.size} z ${D.materials.length}</span></h5>
            ${rows ? `<div class="table-responsive"><table class="rcv-tbl"><thead><tr><th></th><th>Surowiec</th><th class="r">Cena w kw.</th><th class="r">Udział w zakupach</th><th class="r">Dostawcy</th><th>Dlaczego</th></tr></thead><tbody>${rows}</tbody></table></div>`
                : '<p class="at-empty">Żaden surowiec nie spełnia reguły — można zatwierdzić ocenę bez zapytania.</p>'}
            <div class="rcv-row" style="margin-top:10px"><button type="button" class="kw-btn ghost" data-go="1"><i class="bi bi-arrow-left"></i> Oceny</button><span class="rcv-sp"></span>
                ${rows ? '<button type="button" class="kw-btn pri" data-go="3">Dalej : zapytanie ofertowe <i class="bi bi-arrow-right"></i></button>' : '<button type="button" class="kw-btn pri" data-finish><i class="bi bi-check-lg"></i> Zatwierdź ocenę (bez zapytania)</button>'}</div></div>`;
    }

    function stepTender() {
        const list = candidates();
        const s = supSet();
        const rows = list.map(c => `<tr data-sup="${c.id}" class="${s.has(c.id) ? '' : 'is-off'}"><td><input type="checkbox" data-pick-sup ${s.has(c.id) ? 'checked' : ''} ${tender ? 'disabled' : ''} aria-label="${esc(c.name)}"></td>
            <td><b>${esc(c.name)}</b><small>${c.current ? 'obecny dostawca' : 'z katalogu (ta sama kategoria)'}</small></td><td>${c.grade ? `<span class="rcv-grade ${c.grade.toLowerCase()}">${c.grade}</span>` : '—'}</td>
            <td>${(decisions.get(c.id)?.decision || c.decision) === 'change' ? '<span class="kw-badge bad">zmień dostawcę</span>' : ''}</td></tr>`).join('');
        return `<div class="kw-card"><h5 class="rcv-h">Zapytanie ofertowe ${tender ? `<span class="kw-badge ok">${esc(tender.code)} · szkic</span>` : ''}</h5>
            <div class="row g-2"><div class="col-md-8"><label class="form-label small">Tytuł</label><input class="form-control" data-title value="${esc(D.title)}" maxlength="200" ${tender ? 'disabled' : ''}></div>
                <div class="col-md-4"><label class="form-label small">Odpowiedź do</label><input type="date" class="form-control" data-deadline value="${esc(D.reply_deadline)}" ${tender ? 'disabled' : ''}></div></div>
            <div class="table-responsive mt-2"><table class="rcv-tbl"><thead><tr><th></th><th>Dostawca (z e-mailem)</th><th>Ocena</th><th></th></tr></thead><tbody>${rows || '<tr><td colspan="4" class="at-empty">Brak dostawców z adresem e-mail dla tych surowców.</td></tr>'}</tbody></table></div>
            <div class="rcv-row" style="margin-top:10px"><button type="button" class="kw-btn ghost" data-go="2"><i class="bi bi-arrow-left"></i> Surowce</button><span class="rcv-sp"></span>
                ${tender ? `<a class="kw-btn" href="${at.root}/purchasing/tenders/${tender.id}" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> Otwórz ${esc(tender.code)}</a>`
                    : `<button type="button" class="kw-btn" data-tender ${mats.size && s.size ? '' : 'disabled'}><i class="bi bi-envelope-paper"></i> Utwórz szkic zapytania</button>`}
                <button type="button" class="kw-btn pri" data-finish><i class="bi bi-check-lg"></i> Zatwierdź ocenę</button></div></div>`;
    }

    function render() {
        const main = step === 1 ? stepGrades() : (step === 2 ? stepMaterials() : stepTender());
        app.innerHTML = `<div class="rcv-wz">${steps()}<div class="rcv-mainc">${main}</div>${side()}</div>`;
        bind();
    }

    const rowsPayload = () => [...decisions.entries()].map(([id, d]) => ({supplier_id: id, decision: d.decision, note: d.note}));

    function bind() {
        app.querySelectorAll('[data-step]').forEach(b => b.addEventListener('click', () => { step = Number(b.dataset.step); render(); }));
        app.querySelectorAll('[data-go]').forEach(b => b.addEventListener('click', () => { step = Number(b.dataset.go); render(); }));
        const more = app.querySelector('[data-more]');
        if (more) more.addEventListener('click', () => { showAll = true; render(); });
        app.querySelectorAll('[data-dec]').forEach(sel => sel.addEventListener('change', () => {
            decisions.get(Number(sel.closest('tr').dataset.id)).decision = sel.value;
            sel.className = `form-select rcv-dec ${DEC_CLASS[sel.value]}`;
        }));
        app.querySelectorAll('[data-note]').forEach(i => i.addEventListener('input', () => { decisions.get(Number(i.closest('tr').dataset.id)).note = i.value; }));
        const n1 = app.querySelector('[data-next1]');
        if (n1) n1.addEventListener('click', async () => {
            n1.disabled = true;
            const r = await at.send('/ajax/purchasing/supplier-review/grades', 'POST', {quarter: D.quarter.key, rows: rowsPayload()});
            if (!r.ok) { at.notify(false, r.message); n1.disabled = false; return; }
            D = r.data; mats = new Set(D.materials.map(m => m.id)); sups = null; step = 2; render();
        });
        app.querySelectorAll('[data-pick-mat]').forEach(cb => cb.addEventListener('change', () => {
            const id = Number(cb.closest('tr').dataset.mat);
            if (cb.checked) mats.add(id); else mats.delete(id);
            sups = null; render();
        }));
        app.querySelectorAll('[data-pick-sup]').forEach(cb => cb.addEventListener('change', () => {
            const id = Number(cb.closest('tr').dataset.sup);
            if (cb.checked) supSet().add(id); else supSet().delete(id);
            render();
        }));
        const tb = app.querySelector('[data-tender]');
        if (tb) tb.addEventListener('click', async () => {
            tb.disabled = true;
            const r = await at.send('/ajax/purchasing/supplier-review/tender', 'POST', {title: app.querySelector('[data-title]').value, reply_deadline: app.querySelector('[data-deadline]').value,
                material_ids: [...mats], supplier_ids: [...supSet()]});
            at.notify(r.ok, r.message);
            if (r.ok) tender = r.tender;
            render();
        });
        const fin = app.querySelector('[data-finish]');
        if (fin) fin.addEventListener('click', async () => {
            fin.disabled = true;
            const r = await at.send('/ajax/purchasing/supplier-review/finish', 'POST', {quarter: D.quarter.key, rows: rowsPayload(), tender_id: tender ? tender.id : null});
            at.notify(r.ok, r.message);
            if (r.ok) D = r.data;
            render();
        });
    }

    render();
})();
