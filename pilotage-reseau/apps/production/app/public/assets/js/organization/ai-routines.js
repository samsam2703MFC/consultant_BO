// [387] Ustawienia › Asystent AI › Rutyny poranne (administrator ; makieta ai_mock_settings) : godzina i wyłącznik każdego
// asystenta pilota, wyłącznik główny ai.routines_enabled (domyślnie wyłączony), model, budżet miesięczny i kurs USD → PLN,
// zużycie miesiąca, ostatnie przebiegi, podgląd zanonimizowanego ładunku dnia (bez wywołania API) i « Uruchom teraz »
// (jedno prawdziwe wywołanie, tylko gdy rutyna jest włączona). Każda zmiana zapisuje się od razu (PUT).
(() => {
    const app = document.getElementById('aiRoutines');
    const boot = document.getElementById('aiRoutinesBoot');
    if (!app || !boot) return;
    let s = JSON.parse(boot.textContent);
    if (!s) return;
    const esc = at.esc;
    const pl = (n) => Number(n).toLocaleString('pl-PL', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    const keyLine = (k) => !k.configured ? '<span class="ai-pill bad">brak klucza</span> dodaj « anthropic_api_key » w erp_cles (portal commun) — bez klucza przebieg kończy się błędem'
        : (k.source === 'erp_cles' ? `<span class="ai-pill">erp_cles</span> anthropic_api_key …${esc(k.hint || '')}`
            : (k.source === 'env' ? '<span class="ai-pill">.env</span> ANTHROPIC_API_KEY serwera' : `<span class="ai-pill w">Ustawienia</span> klucz …${esc(k.hint || '')} (zalecane : erp_cles)`));
    let previewCode = s.assistants.length ? s.assistants[0].code : null;
    let preview = null;
    let busy = false;

    const sw = (attrs, on) => `<label class="ai-sw"><input type="checkbox" ${attrs} ${on ? 'checked' : ''} ${busy ? 'disabled' : ''}><span></span></label>`;
    const last = (r) => r ? `<span class="ai-pill ${r.cls === 'ok' ? '' : esc(r.cls)}" title="${esc(r.model || '')}">${esc(r.when)} · ${esc(r.text)}</span>` : '<span class="ai-muted">jeszcze nie działała</span>';

    function render() {
        const rows = s.assistants.map(a => `<tr><td><b>${esc(a.label)}</b></td><td>${esc(a.position)}</td>
            <td><input type="time" class="form-control form-control-sm" data-rt-time="${esc(a.code)}" value="${esc(a.time)}" step="300" ${busy ? 'disabled' : ''}></td>
            <td>${sw(`data-rt-on="${esc(a.code)}"`, a.on)}</td><td>${last(a.last)}</td>
            <td class="text-nowrap"><button type="button" class="ai-run" data-rt-preview="${esc(a.code)}" ${busy ? 'disabled' : ''}>Podgląd danych</button>
                <button type="button" class="ai-run" data-rt-run="${esc(a.code)}" ${busy ? 'disabled' : ''} title="${s.enabled && a.on ? 'Jedno wywołanie API teraz (koszt w budżecie)' : 'Rutyna wyłączona — przebieg zostanie pominięty'}">Uruchom teraz</button></td></tr>`).join('');
        const recent = s.recent.length ? s.recent.map(r => `<tr><td class="text-nowrap">${esc(r.when)}</td><td>${esc(r.label)}</td>
            <td>${esc(r.text)}${r.cost_label ? ' · ' + esc(r.cost_label) : ''}${r.trigger === 'manual' ? ' <span class="ai-muted">(ręcznie)</span>' : ''}</td></tr>`).join('')
            : '<tr><td colspan="3" class="ai-muted">Brak przebiegów.</td></tr>';
        const p = preview;
        app.innerHTML = `<div class="ai-rt-head"><h4>Rutyny poranne</h4><span class="ai-muted">Co rano AI czyta dane asystentów i przygotowuje propozycje decyzji. Decyzje zapisuje zawsze człowiek
                (« Przyjmij » wypełnia formularz, zapis przyciskiem « Zapisz »). AI niczego nie wysyła i nie zapisuje w ERP.</span></div>
            <div class="row g-3"><div class="col-xl-7">
                <div class="dc-card"><div class="ai-lbl">Asystenci w pilocie</div>
                    <table><tr><th>Asystent</th><th>Stanowisko</th><th>Godzina</th><th>Włączony</th><th>Ostatnio</th><th></th></tr>${rows}
                    <tr><td class="ai-muted">Pozostałe asystenci (${Number(s.others)})</td><td class="ai-muted">—</td><td class="ai-muted">—</td><td>${sw('disabled', false)}</td><td class="ai-muted" colspan="2">po pilocie</td></tr></table>
                    <p class="dc-hint mb-0 mt-2">Cron co 10 minut (dni robocze) : asystent, którego godzina minęła i który dziś jeszcze nie działał. Bez sygnałów — bez wywołania API.</p></div>
                <div class="dc-card"><div class="ai-lbl">Co wysyłamy do AI${p ? ` (dziś : ${esc(p.label)})` : ''}</div>
                    ${p ? `<pre data-rt-payload>${esc(p.payload)}</pre><p class="dc-hint mb-0 mt-2">${p.items} ${p.items === 1 ? 'pozycja' : 'pozycji'} · ok. ${Number(p.est_input_tokens).toLocaleString('pl-PL')} tokenów wejścia,
                        ${Number(p.est_output_tokens).toLocaleString('pl-PL')} wyjścia · szacunek ${esc(p.est_cost_label)} (${esc(p.model_label)}). Podgląd bez wywołania API.</p>`
                        : '<p class="ai-muted mb-0">Wczytywanie podglądu…</p>'}
                    <p class="dc-hint mb-0 mt-1">Bez nazw klientów, osób, e-maili, telefonów, adresów i NIP. ERP zamienia « K1 » z powrotem na nazwę dopiero na ekranie asystenta.</p></div>
            </div><div class="col-xl-5">
                <div class="dc-card"><div class="ai-lbl">Model i koszty</div><table>
                    <tr><td>Wyłącznik</td><td>${sw('data-rt-enabled', s.enabled)} <code>ai.routines_enabled</code> ${s.enabled ? '' : '<span class="ai-pill w">wyłączone — nic nie idzie do AI</span>'}</td></tr>
                    <tr><td>Model rutyn</td><td>${s.models.map(m => `<label class="me-3 text-nowrap"><input type="radio" name="rtModel" value="${esc(m.code)}" ${m.code === s.model ? 'checked' : ''} ${busy ? 'disabled' : ''}> ${esc(m.label)}</label>`).join('<br>')}</td></tr>
                    <tr><td>Budżet miesięczny</td><td><input class="form-control form-control-sm ai-num" inputmode="decimal" data-rt-budget value="${esc(String(s.budget_pln).replace('.', ','))}" ${busy ? 'disabled' : ''}> zł
                        <span class="ai-muted">— po wyczerpaniu rutyny się zatrzymują</span></td></tr>
                    <tr><td>Zużyto (${esc(s.month.label)})</td><td><b>${esc(s.month.spent_label)}</b> · ${s.month.runs} ${s.month.runs === 1 ? 'uruchomienie' : 'uruchomień'} · zostało ${pl(s.month.left_pln)} zł</td></tr>
                    <tr><td>Kurs USD → PLN</td><td><input class="form-control form-control-sm ai-num" inputmode="decimal" data-rt-usd value="${esc(String(s.usd_pln).replace('.', ','))}" ${busy ? 'disabled' : ''}>
                        <span class="ai-muted">cennik Anthropic w USD</span></td></tr>
                    <tr><td>Klucz</td><td>${keyLine(s.key)}</td></tr></table>
                    <p class="dc-hint mb-0 mt-2">Wysiłek « ${esc(s.effort)} », odpowiedź w schemacie JSON. Cennik za 1 mln tokenów : Opus 5.5 4 $ / 20 $, Sonnet 5.5 2 $ / 10 $.</p></div>
                <div class="dc-card"><div class="ai-lbl">Ostatnie uruchomienia</div><table><tr><th>Kiedy</th><th>Asystent</th><th>Wynik</th></tr>${recent}</table></div>
            </div></div>`;
    }

    const save = (data) => {
        busy = true;
        render();
        return at.send('/ajax/settings/ai/routines', 'PUT', data).then(res => {
            busy = false;
            if (res.ok) { s = res.data; at.notify(true, 'Zapisano.'); } else at.notify(false, res.message || 'Nie zapisano.');
            render();
        });
    };
    const loadPreview = (code) => {
        previewCode = code;
        preview = null;
        render();
        at.send(`/ajax/settings/ai/routines/${encodeURIComponent(code)}/preview`, 'GET').then(res => {
            if (previewCode !== code) return;
            if (!res.ok) { at.notify(false, res.message || 'Podgląd niedostępny.'); return; }
            preview = res.data;
            render();
        });
    };

    app.addEventListener('change', (e) => {
        const t = e.target;
        if (t.matches('[data-rt-enabled]')) save({enabled: t.checked});
        else if (t.matches('[data-rt-on]')) save({assistants: {[t.dataset.rtOn]: {on: t.checked}}});
        else if (t.matches('[data-rt-time]')) save({assistants: {[t.dataset.rtTime]: {time: t.value}}});
        else if (t.matches('[name=rtModel]')) save({model: t.value});
        else if (t.matches('[data-rt-budget]')) save({budget_pln: t.value.replace(/\s/g, '').replace(',', '.')});
        else if (t.matches('[data-rt-usd]')) save({usd_pln: t.value.replace(/\s/g, '').replace(',', '.')});
    });
    app.addEventListener('click', (e) => {
        const pv = e.target.closest('[data-rt-preview]'), run = e.target.closest('[data-rt-run]');
        if (pv) loadPreview(pv.dataset.rtPreview);
        if (!run) return;
        const a = s.assistants.find(x => x.code === run.dataset.rtRun);
        if (!a || !window.confirm(`Uruchomić teraz rutynę « ${a.label} » ? ${s.enabled && a.on ? 'Jedno wywołanie API (koszt w budżecie).' : 'Rutyna jest wyłączona — przebieg zostanie tylko zapisany jako pominięty.'}`)) return;
        busy = true;
        render();
        at.send(`/ajax/settings/ai/routines/${encodeURIComponent(a.code)}/run`, 'POST', {}).then(res => {
            busy = false;
            if (res.ok) {
                s = res.data.state;
                const r = res.data.run;
                at.notify(r.status === 'ok' || r.status === 'no_signals', `${a.label} : ${r.status_label}${r.status === 'ok' ? ` — ${r.proposals} z ${r.signals}` : ''}${r.error ? ' — ' + r.error : ''}`);
            } else at.notify(false, res.message || 'Nie udało się uruchomić.');
            render();
        });
    });
    render();
    if (previewCode) loadPreview(previewCode);
})();
