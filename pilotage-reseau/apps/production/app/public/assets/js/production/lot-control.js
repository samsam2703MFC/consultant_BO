// [352] Partie i identyfikowalność (makiety 68 i 68 B) : rejestr partii surowców (przełączniki, numer), kontrola wejść
// (szczegół partii), reguły próbek i asystent « Próbki produkcji » (3 kroki : wybierz, drukuj A4, odłóż i zeskanuj).
(() => {
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const dmy = (d) => d ? d.slice(0, 10).split('-').reverse().join('.') : '—';
    const reload = (hash) => { if (hash !== undefined) location.hash = hash; location.reload(); };
    const send = async (url, method, body) => {
        const res = await at.send(url, method, body);
        at.notify(!!res.ok, res.message || (res.ok ? 'Zapisano.' : 'Błąd zapisu.'));
        return res;
    };

    // --- Partie surowców ---------------------------------------------------------------------------------------------
    document.querySelectorAll('[data-lc-track]').forEach(i => i.addEventListener('change', async () => {
        i.disabled = true;
        const res = await send(`/ajax/production/material-lots/material/${i.dataset.lcTrack}`, 'PUT', {tracked: i.checked});
        if (res.ok) reload(); else { i.checked = !i.checked; i.disabled = false; }
    }));
    document.querySelectorAll('[data-lc-active]').forEach(i => i.addEventListener('change', async () => {
        i.disabled = true;
        const res = await send(`/ajax/production/material-lots/${i.dataset.lcActive}`, 'PUT', {active: i.checked});
        if (res.ok) reload(); else { i.checked = !i.checked; i.disabled = false; }
    }));
    document.querySelectorAll('[data-lc-save]').forEach(b => b.addEventListener('click', async () => {
        const tr = b.closest('[data-lot]');
        const lot = tr.querySelector('[data-lc-num]').value.trim();
        if (!lot) { at.notify(false, 'Wpisz numer partii.'); return; }
        b.disabled = true;
        const res = await send(`/ajax/production/material-lots/${tr.dataset.lot}`, 'PUT', {lot_number: lot, best_before: tr.querySelector('[data-lc-bb]').value || null});
        if (res.ok) reload(); else b.disabled = false;
    }));

    // --- Kontrola wejść : szczegół partii ------------------------------------------------------------------------------
    document.querySelectorAll('[data-lc-batch]').forEach(tr => {
        const open = async () => {
            const next = tr.nextElementSibling;
            if (next && next.classList.contains('lc-det')) { next.remove(); return; }
            const det = document.createElement('tr');
            det.className = 'lc-det';
            det.innerHTML = '<td colspan="5" class="text-muted">Ładowanie…</td>';
            tr.after(det);
            try {
                const r = await fetch(`${at.root}/ajax/production/input-check/${tr.dataset.lcBatch}`, {credentials: 'same-origin', headers: {Accept: 'application/json'}});
                const j = await r.json();
                if (!r.ok) throw new Error(j.message || 'Błąd');
                const b = j.data;
                const line = (g) => {
                    let state;
                    if (!g.tracked) state = '<span class="lc-pill lc-g">nieśledzony</span>';
                    else if (g.lot_number) state = `<b>${esc(g.lot_number)}</b>${g.best_before ? ' · DLC ' + dmy(g.best_before) : ''} ${g.from_list ? '<span class="lc-pill lc-r">z listy</span>' : '<span class="lc-pill lc-w">wpisana</span>'}`;
                    else if (g.missing_reason) state = `<span class="lc-pill lc-w">brak : ${esc(g.missing_reason)}</span>${g.photo ? ' <i class="bi bi-camera"></i>' : ''}`;
                    else state = '<span class="lc-pill lc-x">brak partii</span>';
                    return `<li>${esc(g.name)} — ${state}</li>`;
                };
                det.innerHTML = `<td colspan="5"><ul>${b.ingredients.filter(g => !g.packaging).map(line).join('')}
                    ${b.components.map(c => `<li>${esc(c.name)} (${esc(c.producer)}) — ${c.lot_code ? '<b>' + esc(c.lot_code) + '</b>' : '<span class="lc-pill lc-x">brak partii grupy</span>'}</li>`).join('')}</ul>
                    ${b.ingredients.some(g => g.packaging) ? `<p class="small text-muted mt-2 mb-0">Opakowania : ${b.ingredients.filter(g => g.packaging).map(g => esc(g.name) + ' ' + (g.lot_number ? esc(g.lot_number) : '—')).join(' · ')}</p>` : ''}</td>`;
            } catch (e) { det.innerHTML = `<td colspan="5" class="text-danger">${esc(e.message)}</td>`; }
        };
        tr.addEventListener('click', open);
        tr.addEventListener('keydown', (e) => { if (e.key === 'Enter') open(); });
    });

    // --- Próbki : reguły kategorii ------------------------------------------------------------------------------------
    document.querySelectorAll('[data-lc-rule]').forEach(s => s.addEventListener('change', async () => {
        s.disabled = true;
        const res = await send(`/ajax/production/sample-rules/${s.dataset.lcRule}`, 'PUT', {frequency: s.value || null});
        s.disabled = false;
        if (res.ok) reload();
    }));

    // --- Próbka z kodu QR (telefon) -------------------------------------------------------------------------------------
    document.querySelectorAll('[data-lc-store]').forEach(b => b.addEventListener('click', async () => {
        b.disabled = true;
        const res = await send('/ajax/production/samples/store', 'POST', {code: b.dataset.lcStore});
        if (res.ok) reload(); else b.disabled = false;
    }));

    // --- Asystent « Próbki produkcji » ------------------------------------------------------------------------------------
    const app = document.getElementById('lcSamples');
    const boot = document.getElementById('lcBoot');
    if (!app || !boot || !window.tw) return;
    const D = JSON.parse(boot.textContent);
    const STEPS = ['Wybierz partie', 'Drukuj arkusz A4', 'Odłóż i zeskanuj'];
    const FREQ = {lot: 'każda partia', day: 'raz dziennie', week: '1 / produkt / tydz.', month: '1 / produkt / mies.', none: 'bez próbek'};
    const STATUS = {planned: ['do druku', 'warn'], printed: ['wydrukowana', ''], stored: ['odłożona ✓', 'ok']};
    const hashStep = Number((location.hash.match(/step=(\d)/) || [])[1]) || 0;
    let step = Math.min(3, Math.max(1, hashStep || D.step));
    const c = D.counts;
    const pdfUrl = `${at.root}/workshop/traceability/samples/pdf?date=${encodeURIComponent(D.date)}`;
    const reason = (r) => {
        if (r.sample) return tw.pill(STATUS[r.sample.status][0], STATUS[r.sample.status][1]);
        if (r.reason.startsWith('already_')) return tw.pill('próbka w tym ' + ({week: 'tygodniu', month: 'miesiącu', day: 'dniu'}[r.reason.slice(8)] || 'okresie') + ' już jest');
        return r.suggested ? tw.pill(FREQ[r.frequency] || r.frequency, 'warn') : tw.pill(FREQ[r.frequency] || r.frequency);
    };

    function body() {
        if (D.done) return tw.done('Próbki dnia · ' + (D.done.at || '').slice(11, 16), D.done.summary + ' · zadanie zaliczone')
            + (c.samples ? `<p class="tw-note"><a href="${pdfUrl}" target="_blank" rel="noopener"><i class="bi bi-filetype-pdf"></i> Arkusz A4 próbek (PDF)</a></p>` : '');
        if (step === 1) {
            if (!D.rows.length) return `<p class="tw-lead">Dzień ${dmy(D.date)} : brak partii produkcji na tabletach.</p>`;
            const rows = D.rows.map(r => ({cls: r.sample ? 'muted' : '', cells: [
                r.sample ? (r.sample.status !== 'stored' ? `<button type="button" class="tw-btn ghost sm" data-lc-remove="${r.sample.id}" title="Usuń próbkę (pomyłka)"><i class="bi bi-x"></i></button>` : '')
                    : `<input type="checkbox" class="form-check-input" data-lc-pick="${r.output_id}" ${r.suggested ? 'checked' : ''} aria-label="Próbka ${esc(r.lot_code)}">`,
                `<b>${esc(r.lot_code)}</b>`, esc(r.product), esc(r.group || '—') + (r.employee ? ' · ' + esc(r.employee) : ''), dmy(r.keep_until), reason(r)]}));
            return `<p class="tw-lead">${dmy(D.date)} : <b>${c.batches} ${tw.plural(c.batches, 'partia', 'partie', 'partii')}</b>, propozycja : <b>${c.suggested}</b> (częstotliwość wg kategorii — powyżej). Zaznacz partie, z których bierzesz próbkę.</p>
                ${tw.table([{label: ''}, {label: 'Partia'}, {label: 'Produkt'}, {label: 'Grupa · operator'}, {label: 'Przechowywać do'}, {label: 'Powód'}], rows)}
                <p class="tw-note">Przechowywać do = DLC produktu (trwałość z karty produktu, w dniach od produkcji).</p>`;
        }
        if (step === 2) {
            const rows = D.samples.map(s => ({cells: [`<b>${esc(s.lot_code)}</b>`, esc(s.name), dmy(s.keep_until), `<code>${esc(s.code)}</code>`, tw.pill(STATUS[s.status][0], STATUS[s.status][1])]}));
            return `<p class="tw-lead">${c.samples} ${tw.plural(c.samples, 'próbka', 'próbki', 'próbek')} : wydrukuj arkusz A4 (blok z kodem QR na próbkę — wytnij albo włóż do segregatora próbek).</p>
                <p><a class="tw-btn" href="${pdfUrl}" target="_blank" rel="noopener" data-lc-pdf><i class="bi bi-printer"></i> Arkusz A4 z kodami QR (PDF)</a></p>
                ${tw.table([{label: 'Partia'}, {label: 'Produkt'}, {label: 'Przechowywać do'}, {label: 'Kod'}, {label: 'Stan'}], rows, 'Brak próbek — wróć do kroku 1.')}`;
        }
        const rows = D.samples.map(s => ({cells: [`<b>${esc(s.lot_code)}</b>`, esc(s.name), `<code>${esc(s.code)}</code>`,
            s.status === 'stored' ? tw.pill('odłożona ✓ ' + (s.stored_at || '').slice(11, 16), 'ok') : `<button type="button" class="tw-btn ghost sm" data-lc-store-row="${esc(s.code)}">Odłożona</button>`]}));
        return `<p class="tw-lead">Odkładając próbkę do ${esc(D.location)}, zeskanuj jej kod QR (czytnik albo telefon) — albo kliknij « Odłożona ». Odłożone : <b>${c.stored} / ${c.samples}</b>.</p>
            <div class="tw-field" style="max-width:420px;margin-bottom:12px"><label for="lcScan">Kod QR próbki</label><input id="lcScan" class="form-control" autocomplete="off" placeholder="zeskanuj albo wpisz kod" autofocus></div>
            ${tw.table([{label: 'Partia'}, {label: 'Produkt'}, {label: 'Kod'}, {label: 'Stan'}], rows, 'Brak próbek.')}`;
    }

    function next() {
        if (D.done) return null;
        if (step === 1) {
            if (!D.rows.length || (!c.suggested && !c.samples && !D.rows.some(r => !r.sample))) return {label: 'Dzień bez próbek — zalicz zadanie', attr: 'data-lc-finish', final: true};
            return {label: 'Utwórz próbki', attr: 'data-lc-create'};
        }
        if (step === 2) return {label: 'Dalej : odłóż i zeskanuj', attr: 'data-lc-next', disabled: !c.samples};
        return {label: 'Zakończ — zadanie zaliczone', attr: 'data-lc-finish', final: true, disabled: c.stored < c.samples};
    }

    function render() {
        app.innerHTML = tw.frame({title: 'Próbki produkcji', sub: `${dmy(D.date)} · krok ${step} z 3 · ok. 10 min`, steps: STEPS, step, reached: D.done ? 3 : Math.max(D.step, step),
            body: body(), prev: step > 1 && !D.done ? 'Wstecz' : null, next: next(), later: !D.done,
            extra: step === 1 && D.samples.length && !D.done ? `<button type="button" class="tw-link" data-lc-next>Próbki już utworzone : dalej</button>` : ''});
        app.querySelector('#lcScan')?.addEventListener('keydown', async (e) => {
            if (e.key !== 'Enter') return;
            const v = e.target.value.trim();
            if (!v) return;
            e.target.disabled = true;
            const res = await send('/ajax/production/samples/store', 'POST', {code: v});
            if (res.ok) reload('step=3'); else { e.target.disabled = false; e.target.select(); }
        });
    }

    tw.bind(app, (n) => { step = n === -1 ? Math.max(1, step - 1) : n; location.hash = 'step=' + step; render(); });
    app.addEventListener('click', async (e) => {
        const t = e.target.closest('button, a');
        if (!t) return;
        if (t.matches('[data-lc-next]')) { step = Math.min(3, step + 1); location.hash = 'step=' + step; render(); return; }
        if (t.matches('[data-lc-pdf]')) { setTimeout(() => reload('step=2'), 1500); return; }
        if (t.matches('[data-lc-create]')) {
            const ids = [...app.querySelectorAll('[data-lc-pick]:checked')].map(i => Number(i.dataset.lcPick));
            if (!ids.length) { at.notify(false, 'Zaznacz co najmniej jedną partię.'); return; }
            t.disabled = true;
            const res = await send('/ajax/production/samples', 'POST', {date: D.date, outputs: ids});
            if (res.ok) reload('step=2'); else t.disabled = false;
            return;
        }
        if (t.matches('[data-lc-remove]')) {
            if (!window.confirm('Usunąć tę próbkę ?')) return;
            t.disabled = true;
            const res = await send(`/ajax/production/samples/${t.dataset.lcRemove}`, 'DELETE');
            if (res.ok) reload('step=1'); else t.disabled = false;
            return;
        }
        if (t.matches('[data-lc-store-row]')) {
            t.disabled = true;
            const res = await send('/ajax/production/samples/store', 'POST', {code: t.dataset.lcStoreRow});
            if (res.ok) reload('step=3'); else t.disabled = false;
            return;
        }
        if (t.matches('[data-lc-finish]')) {
            t.disabled = true;
            const res = await send('/ajax/production/samples/done', 'POST', {date: D.date});
            if (res.ok) reload('step=3'); else t.disabled = false;
        }
    });
    render();
})();
