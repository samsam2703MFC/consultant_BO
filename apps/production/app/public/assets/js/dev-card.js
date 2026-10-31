/*
 * Nowy produkt testowy [196, 197] — jeden kreator (/test-products/new) : karta technologiczna z filmu albo sam pomysł.
 * 1. Przepis i produkt : link Facebook / Pinterest (albo plik wideo, gotowa karta .docx, albo « Sam pomysł » — produkt
 *    od razu, bez karty), nazwa, grupa, sprzedaż ± na tydzień, sezon (kwartały → tabela kwartałów i roku) ; opcjonalnie
 *    kategoria, typ i etykieta (pola dawnego formularza produktu testowego).
 * 2. Generowanie : odczyt linku (serwer, bez logowania), klatki wycięte tutaj w przeglądarce (wideo pobrane przez serwer
 *    z Facebooka / Pinteresta albo plik operatora — nie wysyłamy całego filmu), asystent AI (klucz Anthropic na serwerze).
 * 3. Karta : podgląd A4 / tabletu, poprawki tekstu i zdjęć, .docx / PDF.
 * 4. Koszt i cena [198] : receptura z karty → nasze surowce albo nasze receptury [199] → koszt na sztukę, cena fabryczna (× współczynnik),
 *    cena w sklepie (fabryka × mnożnik), cobot ; zapis → produkt na tablecie grupy albo karta do druku.
 */
(function () {
    const root = document.getElementById('devCard');
    if (!root) return;
    const boot = JSON.parse(document.getElementById('devCardBoot').textContent || '{}');
    const view = root.querySelector('[data-view]');
    const esc = at.esc;
    const S = { groups: boot.groups || [], categories: boot.categories || [], types: boot.types || [], drafts: boot.drafts || [], ai: boot.ai || {},
        card: boot.card || null, mode: new URLSearchParams(location.search).get('mode') === 'idea' ? 'idea' : 'link', file: null, preview: 'a4', editing: false };
    const Q = { 1: 'sty–mar', 2: 'kwi–cze', 3: 'lip–wrz', 4: 'paź–gru' };
    const fmt = (n) => String(Math.round(Number(n || 0))).replace(/\B(?=(\d{3})+(?!\d))/g, ' '); // 1 560 : spacja co 3 cyfry (także 4-cyfrowe)
    const BATCH = 6;
    const MAX_FRAMES = 40;

    const steps = (n) => root.querySelectorAll('[data-steps] li').forEach(li => {
        const s = Number(li.dataset.step);
        li.classList.toggle('is-active', s === n);
        li.classList.toggle('is-done', s < n);
    });
    const groupName = (id) => (S.groups.find(g => Number(g.id) === Number(id)) || {}).name_pl || '';
    const groupColor = (id) => (S.groups.find(g => Number(g.id) === Number(id)) || {}).color || '#999';
    const api = (url, method, data) => at.send(url, method, data);
    const kindOf = (url) => /(^|\.)(facebook\.com|fb\.watch|fb\.com)(\/|$)/i.test(hostOf(url)) ? 'facebook'
        : (/(^|\.)pin\.it$/i.test(hostOf(url)) || /(^|\.)pinterest\.[a-z.]+$/i.test(hostOf(url)) ? 'pinterest' : null);
    function hostOf(url) { try { return new URL(url).hostname; } catch (e) { return ''; } }

    // --- Krok 1 : film i produkt ------------------------------------------------------------------------

    function forecastRows(weekly, seasonal, quarters) {
        const active = seasonal ? quarters : [1, 2, 3, 4];
        const per = [1, 2, 3, 4].map(q => active.includes(q) ? weekly * 13 : 0);
        return `<table class="dc-q"><thead><tr><th></th>${[1, 2, 3, 4].map(q => `<th>Q${q}</th>`).join('')}<th>Rok</th></tr></thead>
            <tbody><tr><td>Sztuk (${fmt(weekly)} × 13 tygodni)</td>${per.map(v => `<td class="${v ? 'on' : 'off'}">${v ? fmt(v) : '—'}</td>`).join('')}
            <td class="tot">${fmt(per.reduce((a, b) => a + b, 0))}</td></tr></tbody></table>`;
    }

    function renderForm(opts = {}) {
        const edit = !!opts.edit;
        const c = S.card || {};
        steps(edit ? 3 : 1);
        const weekly = c.weekly_sales ?? 100;
        const seasonal = !!c.seasonal;
        const quarters = c.season_quarters || [1, 2, 3, 4];
        const ai = S.ai;
        view.innerHTML = `<form class="dc-grid" data-form novalidate><div>
            ${edit ? '' : `<div class="dc-card"><h4 class="dc-h"><span class="dc-num">1</span>Skąd przepis ?</h4>
                <div class="dc-seg dc-seg-wrap" role="radiogroup" aria-label="Źródło">
                    <label><input type="radio" name="mode" value="link" ${S.mode === 'link' ? 'checked' : ''}><span><i class="bi bi-link-45deg"></i> Link do filmu</span></label>
                    <label><input type="radio" name="mode" value="file" ${S.mode === 'file' ? 'checked' : ''}><span><i class="bi bi-film"></i> Plik wideo</span></label>
                    <label><input type="radio" name="mode" value="docx" ${S.mode === 'docx' ? 'checked' : ''}><span><i class="bi bi-file-earmark-word"></i> Gotowa karta .docx</span></label>
                    <label><input type="radio" name="mode" value="idea" ${S.mode === 'idea' ? 'checked' : ''}><span><i class="bi bi-lightbulb"></i> Sam pomysł</span></label>
                </div>
                <div class="dc-field" data-only="link"><label class="dc-label" for="dcUrl">Link do filmu (Facebook reel albo pin Pinterest)</label>
                    <div class="dc-input-wrap"><i class="bi bi-link-45deg"></i><input class="form-control dc-input" id="dcUrl" name="url" type="url" inputmode="url" placeholder="https://www.facebook.com/reel/…" autocomplete="off"><span class="dc-pill" data-kind hidden></span></div>
                    <button type="button" class="dc-link" data-toggle-text><i class="bi bi-chevron-down"></i> Facebook prosi o logowanie ? Wklej opis ręcznie</button>
                    <textarea class="form-control dc-text" name="text" rows="5" maxlength="20000" placeholder="Opis z filmu : składniki z gramaturami i sposób wykonania (dowolny język)" hidden></textarea></div>
                <div class="dc-field" data-only="file" hidden><label class="dc-label" for="dcFile">Plik wideo z telefonu albo komputera (.mp4, .mov)</label>
                    <input class="form-control dc-input" id="dcFile" name="video" type="file" accept="video/*">
                    <p class="dc-hint">Film zostaje na Twoim komputerze : ERP wycina z niego tylko klatki (zdjęcia etapów).</p>
                    <textarea class="form-control dc-text" name="text_file" rows="4" maxlength="20000" placeholder="Opis przepisu (jeśli jest) — składniki, gramatury, kroki"></textarea></div>
                <div class="dc-field" data-only="docx" hidden><label class="dc-label" for="dcDocx">Karta technologiczna w Wordzie (np. 01_Pogacza_sucuk_kaszar.docx)</label>
                    <input class="form-control dc-input" id="dcDocx" name="docx" type="file" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
                    <p class="dc-hint">Karta jest wczytywana bez asystenta AI : tytuł, wydajność, pieczenie, czas, składniki, kroki i zdjęcia etapów.</p></div>
                <div class="dc-field" data-only="idea" hidden><label class="dc-label" for="dcNotes">Pomysł, cel, na co uważać (widzi zespół na tablecie) — opcjonalnie</label>
                    <textarea class="form-control dc-text" id="dcNotes" name="notes" rows="4" maxlength="5000" placeholder="np. bułeczka z sezonowym owocem, cena docelowa, inspiracja…"></textarea>
                    <div class="dc-row dc-tight dc-mt"><label class="dc-label dc-m0" for="dcTarget">Data docelowa (wprowadzenie)</label>
                        <input class="form-control dc-date" id="dcTarget" name="target_date" type="date"></div>
                    <p class="dc-hint">Produkt powstaje od razu (etap Pomysł, tablet grupy) — bez karty. Kartę z filmu albo .docx można dodać później.</p></div>
                <div class="dc-prompt" data-only="link file"><i class="bi bi-file-earmark-text"></i><div><strong>Prompt : Karta technologiczna z reela</strong>
                    <small>Odczyt linku → zdjęcia etapów → przepis po polsku → karta A4 · ${ai.prompt && ai.prompt.created_at ? 'wersja z ' + esc(dmy(ai.prompt.created_at)) : 'wersja domyślna'}</small></div>
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-show-prompt>Pokaż</button></div>
                <pre class="dc-prompt-body" data-prompt hidden>${esc(ai.prompt ? ai.prompt.body : '')}</pre>
                ${ai.is_admin ? `<p class="dc-hint" data-only="link file"><a href="${at.root}/settings/ai">Zmień prompt lub model w Ustawieniach › Asystent AI</a></p>` : ''}
            </div>`}
            <div class="dc-card"><h4 class="dc-h"><span class="dc-num">${edit ? '1' : '2'}</span>Produkt</h4>
                <div class="dc-field"><label class="dc-label" for="dcName">Nazwa produktu (w ERP i na tablecie)</label>
                    <input class="form-control dc-input" id="dcName" name="name" maxlength="255" value="${esc(c.name || '')}" placeholder="np. Pogacza skręcana z sucukiem" required></div>
                <div class="dc-field"><span class="dc-label">Grupa, która robi test i produkcję</span>
                    <div class="dc-chips" data-field="id_group">${S.groups.map(g => `<label class="dc-chip"><input type="radio" name="id_group" value="${g.id}" ${Number(c.id_group) === Number(g.id) ? 'checked' : ''}>
                        <span><i style="background:${esc(g.color || '#999')}"></i>${esc(g.name_pl)}</span></label>`).join('')}</div></div>
                <details class="dc-more" ${c.id_category || Number(c.id_type || 1) !== 1 || Number(c.label_type || 0) === 1 ? 'open' : ''}><summary>Kategoria, etykieta, typ <small>(opcjonalnie)</small></summary>
                    <div class="dc-field"><label class="dc-label" for="dcCat">Kategoria produktu</label>
                        <select class="form-select dc-input" id="dcCat" name="id_category"><option value="">— wybierzesz przy wprowadzeniu —</option>
                        ${S.categories.map(k => `<option value="${k.id}" ${Number(c.id_category) === Number(k.id) ? 'selected' : ''}>${esc(k.name)}</option>`).join('')}</select></div>
                    <div class="dc-row"><div><span class="dc-label">Etykieta</span><div class="dc-seg dc-seg-sm">
                        <label><input type="radio" name="label_type" value="0" ${Number(c.label_type || 0) === 0 ? 'checked' : ''}><span>Mała</span></label>
                        <label><input type="radio" name="label_type" value="1" ${Number(c.label_type || 0) === 1 ? 'checked' : ''}><span>Duża</span></label></div></div>
                    <div><span class="dc-label">Typ</span><div class="dc-chips" data-field="id_type">${S.types.map(t => `<label class="dc-chip"><input type="radio" name="id_type" value="${t.id}" ${Number(c.id_type || 1) === Number(t.id) ? 'checked' : ''}>
                        <span>${esc(t.name)}</span></label>`).join('')}</div></div></div>
                </details>
            </div>
            <div class="dc-card"><h4 class="dc-h"><span class="dc-num">${edit ? '2' : '3'}</span>Sprzedaż (szacunkowo)</h4>
                <div class="dc-row">
                    <div><span class="dc-label">Ile sztuk na tydzień (±)</span><div class="dc-row dc-tight">
                        <div class="dc-stepper"><button type="button" data-step="-10" aria-label="Mniej">−</button><input name="weekly_sales" inputmode="numeric" value="${weekly}" aria-label="Sztuk na tydzień"><button type="button" data-step="10" aria-label="Więcej">+</button></div>
                        <span class="text-muted">szt. / tydzień</span></div></div>
                    <div><span class="dc-label">Szybki wybór</span><div class="dc-chips">${[50, 100, 200, 500].map(n => `<button type="button" class="dc-chip-btn" data-quick="${n}">${n}</button>`).join('')}</div></div>
                </div>
                <div class="dc-row dc-mt">
                    <div><span class="dc-label">Produkt sezonowy ?</span><div class="dc-seg dc-seg-sm">
                        <label><input type="radio" name="seasonal" value="0" ${seasonal ? '' : 'checked'}><span>Nie</span></label>
                        <label><input type="radio" name="seasonal" value="1" ${seasonal ? 'checked' : ''}><span>Tak</span></label></div></div>
                    <div data-quarters ${seasonal ? '' : 'hidden'}><span class="dc-label">W których kwartałach się sprzedaje ?</span><div class="dc-chips" data-field="quarters">
                        ${[1, 2, 3, 4].map(q => `<label class="dc-chip"><input type="checkbox" name="quarters[]" value="${q}" ${quarters.includes(q) && seasonal ? 'checked' : ''}><span>Q${q} <small>${Q[q]}</small></span></label>`).join('')}</div></div>
                </div>
                <div data-forecast></div>
                <p class="dc-hint">Kwartał = 13 tygodni. Produkt całoroczny (« Nie ») : ta sama sprzedaż w każdym kwartale.</p>
            </div>
            <div class="dc-nav"><a class="btn btn-outline-secondary dc-big" href="${edit ? '#' : at.root + '/test-products/pipeline'}" data-cancel>Anuluj</a><span class="dc-sp"></span>
                ${edit ? '' : `<span class="dc-status" data-ai-hint></span>`}
                <button type="submit" class="btn btn-primary dc-big" data-submit>${edit ? 'Zapisz zmiany' : '<i class="bi bi-stars"></i> Generuj kartę'}</button></div>
        </div>
        <aside>${edit ? summaryAside() : `
            <div class="dc-card"><h4 class="dc-h">Co powstanie</h4><ul class="dc-list">
                <li data-only="link file docx"><i class="bi bi-file-earmark-richtext"></i><div><strong>Karta technologiczna A4</strong><small>.docx do edycji + PDF, styl L’Atelier By (jak karty testowe)</small></div></li>
                <li data-only="link file docx"><i class="bi bi-tablet"></i><div><strong>Na tablecie grupy albo do druku</strong><small>do wyboru na końcu : tablet (zakładka « Nowe produkty » : składniki, kroki, zdjęcia etapów, film) albo karta A4 na papier</small></div></li>
                <li data-only="idea"><i class="bi bi-tablet"></i><div><strong>Na tablecie grupy od razu</strong><small>zakładka « Nowe produkty » : nazwa, opis, data ; kartę z filmu albo .docx dodasz później</small></div></li>
                <li><i class="bi bi-kanban"></i><div><strong>Nowy produkt w etapach</strong><small>Pomysł → Test 1 (zespół robi test na tablecie i dodaje zdjęcia)</small></div></li>
                <li><i class="bi bi-graph-up"></i><div><strong>Prognoza sprzedaży</strong><small data-forecast-text></small></div></li></ul></div>
            <div class="dc-card" data-only="link file"><h4 class="dc-h">Asystent AI</h4><dl class="dc-kv">
                <dt>Klucz Anthropic</dt><dd>${ai.configured ? '<span class="dc-pill ok">✓ zapisany</span>' : '<span class="dc-pill w">brak klucza</span>'}</dd>
                <dt>Model</dt><dd>${esc(ai.model_label || ai.model || '')}</dd>
                <dt>Prompt</dt><dd>${ai.prompt && ai.prompt.created_at ? 'wersja z ' + esc(dmy(ai.prompt.created_at)) : 'domyślny'}</dd></dl>
                <p class="dc-hint">${ai.configured ? 'Klucz jest tylko na serwerze : nie ma go w przeglądarce ani w kodzie.'
                    : 'Bez klucza karta z filmu nie powstanie — gotową kartę .docx można wczytać. ' + (ai.is_admin ? `<a href="${at.root}/settings/ai">Dodaj klucz w Ustawieniach › Asystent AI</a>` : 'Klucz dodaje administrator (Ustawienia › Asystent AI).')}</p></div>
            ${S.drafts.length ? `<div class="dc-card"><h4 class="dc-h">Szkice do dokończenia</h4><ul class="dc-drafts">${S.drafts.map(d =>
                `<li><a href="${at.root}/test-products/new/${d.id}">${esc(d.name)}</a><small>${esc(dmy(d.updated_at))} · ${d.status === 'ready' ? 'karta gotowa' : 'szkic'}</small></li>`).join('')}</ul></div>` : ''}`}
        </aside></form>`;
        const form = view.querySelector('[data-form]');
        const refresh = () => {
            const w = Math.max(0, parseInt(String(form.elements.weekly_sales.value).replace(/\D/g, ''), 10) || 0);
            const seas = form.querySelector('[name=seasonal]:checked').value === '1';
            const qs = [...form.querySelectorAll('[name="quarters[]"]:checked')].map(i => Number(i.value));
            form.querySelector('[data-quarters]').hidden = !seas;
            form.querySelector('[data-forecast]').innerHTML = forecastRows(w, seas, qs);
            const ft = form.querySelector('[data-forecast-text]');
            const year = (seas ? qs.length : 4) * w * 13;
            if (ft) ft.textContent = `${fmt(w)} szt./tydz. · ${seas ? (qs.length ? 'sezon ' + qs.map(q => 'Q' + q).join(' + ') : 'zaznacz kwartały') : 'cały rok'} · ${fmt(year)} szt./rok`;
            if (!edit) {
                const mode = form.querySelector('[name=mode]:checked').value;
                S.mode = mode;
                form.querySelectorAll('[data-only]').forEach(el => { el.hidden = !el.dataset.only.split(' ').includes(mode); });
                const btn = form.querySelector('[data-submit]');
                const hint = form.querySelector('[data-ai-hint]');
                const needsAi = mode === 'link' || mode === 'file';
                btn.innerHTML = mode === 'docx' ? '<i class="bi bi-file-earmark-arrow-up"></i> Wczytaj kartę'
                    : mode === 'idea' ? '<i class="bi bi-check-lg"></i> Utwórz produkt' : '<i class="bi bi-stars"></i> Generuj kartę';
                btn.disabled = needsAi && !S.ai.configured;
                hint.innerHTML = needsAi ? (S.ai.configured ? 'Klucz Anthropic ✓ · zwykle 1–2 min' : '<span class="text-danger">Brak klucza Anthropic</span>')
                    : mode === 'idea' ? 'Bez karty · od razu na tablecie grupy' : 'Bez asystenta AI';
                const kind = kindOf(form.elements.url.value.trim());
                const pill = form.querySelector('[data-kind]');
                pill.hidden = !form.elements.url.value.trim();
                pill.className = 'dc-pill ' + (kind === 'facebook' ? 'fb' : kind === 'pinterest' ? 'pin' : 'w');
                pill.textContent = kind === 'facebook' ? 'Facebook' : kind === 'pinterest' ? 'Pinterest' : 'nieobsługiwany link';
            }
        };
        form.addEventListener('input', refresh);
        form.addEventListener('change', refresh);
        form.querySelectorAll('[data-step]').forEach(b => b.addEventListener('click', () => {
            const v = Math.max(0, (parseInt(form.elements.weekly_sales.value, 10) || 0) + Number(b.dataset.step));
            form.elements.weekly_sales.value = v;
            refresh();
        }));
        form.querySelectorAll('[data-quick]').forEach(b => b.addEventListener('click', () => { form.elements.weekly_sales.value = b.dataset.quick; refresh(); }));
        form.querySelector('[data-toggle-text]')?.addEventListener('click', (e) => {
            const ta = form.querySelector('[name=text]');
            ta.hidden = !ta.hidden;
            e.currentTarget.querySelector('i').className = ta.hidden ? 'bi bi-chevron-down' : 'bi bi-chevron-up';
            if (!ta.hidden) ta.focus();
        });
        form.querySelector('[data-show-prompt]')?.addEventListener('click', (e) => {
            const pre = form.querySelector('[data-prompt]');
            pre.hidden = !pre.hidden;
            e.currentTarget.textContent = pre.hidden ? 'Pokaż' : 'Ukryj';
        });
        if (edit) form.querySelector('[data-cancel]').addEventListener('click', (e) => { e.preventDefault(); renderResult(); });
        form.addEventListener('submit', (e) => { e.preventDefault(); submit(form, edit); });
        refresh();
        if (!edit) (S.mode === 'link' ? form.elements.url : form.elements.name).focus();
    }

    function fields(form) {
        const seasonal = form.querySelector('[name=seasonal]:checked').value === '1';
        return {
            name: form.elements.name.value.trim(),
            id_group: (form.querySelector('[name=id_group]:checked') || {}).value || null,
            weekly_sales: String(form.elements.weekly_sales.value).replace(/\D/g, '') || '0',
            seasonal,
            quarters: seasonal ? [...form.querySelectorAll('[name="quarters[]"]:checked')].map(i => Number(i.value)) : [],
            id_category: form.elements.id_category.value || null,
            label_type: (form.querySelector('[name=label_type]:checked') || {}).value || '0',
            id_type: (form.querySelector('[name=id_type]:checked') || {}).value || '1',
        };
    }

    function fieldErrors(form, res) {
        form.querySelectorAll('.dc-invalid').forEach(el => el.classList.remove('dc-invalid'));
        form.querySelectorAll('.dc-err').forEach(el => el.remove());
        Object.entries(res.fields || {}).forEach(([name, msg]) => {
            const el = form.querySelector(`[data-field="${name}"]`) || form.querySelector(`[name="${name}"]`) || (name === 'url' ? form.querySelector('[name=url]') : null);
            if (!el) return;
            el.classList.add('dc-invalid');
            const m = document.createElement('div');
            m.className = 'dc-err';
            m.textContent = msg;
            (el.closest('.dc-input-wrap, .dc-stepper') || el).insertAdjacentElement('afterend', m);
        });
        at.notify(false, res.message || 'Błąd');
    }

    async function submit(form, edit) {
        const btn = form.querySelector('[data-submit]');
        const data = fields(form);
        btn.disabled = true;
        try {
            if (edit) {
                const res = await api(`/ajax/dev-cards/${S.card.id}`, 'PUT', data);
                if (!res.ok) return fieldErrors(form, res);
                S.card = res.data;
                at.notify(true, 'Zapisano.');
                return renderResult();
            }
            const mode = form.querySelector('[name=mode]:checked').value;
            if (mode === 'idea') {
                const res = await api('/ajax/dev-cards/product', 'POST', { ...data, notes: form.elements.notes.value.trim(), target_date: form.elements.target_date.value || null });
                if (!res.ok) return fieldErrors(form, res);
                at.notify(true, 'Utworzono produkt — jest na tablecie grupy (etap Pomysł).');
                location.href = at.root + res.data.url;
                return;
            }
            if (mode === 'docx') {
                const file = form.elements.docx.files[0];
                if (!file) return fieldErrors(form, { message: 'Wybierz plik .docx.', fields: {} });
                const fd = new FormData();
                Object.entries(data).forEach(([k, v]) => fd.append(k, Array.isArray(v) ? v.join(',') : (v === true ? '1' : v === false ? '0' : (v ?? ''))));
                fd.append('file', file);
                const r = await fetch(at.root + '/ajax/dev-cards/import', { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json' } });
                const res = await r.json().catch(() => ({ message: `Błąd serwera (HTTP ${r.status}).` }));
                if (!r.ok) return fieldErrors(form, res);
                S.card = res.data;
                history.replaceState(null, '', `${at.root}/test-products/new/${S.card.id}`);
                at.notify(true, 'Wczytano kartę.');
                return renderResult();
            }
            if (mode === 'file') {
                S.file = form.elements.video.files[0] || null;
                if (!S.file) return fieldErrors(form, { message: 'Wybierz plik wideo.', fields: {} });
                data.file = true;
                data.text = form.elements.text_file.value.trim();
            } else {
                data.url = form.elements.url.value.trim();
                data.text = form.elements.text.value.trim();
            }
            const res = await api('/ajax/dev-cards', 'POST', data);
            if (!res.ok) return fieldErrors(form, res);
            S.card = res.data;
            history.replaceState(null, '', `${at.root}/test-products/new/${S.card.id}`);
            run();
        } finally {
            btn.disabled = false;
        }
    }

    // --- Krok 2 : generowanie ---------------------------------------------------------------------------

    const ROWS = [['read', 'Odczyt linku'], ['frames', 'Wideo i klatki'], ['ai', 'Asystent AI : przepis po polsku i zdjęcia etapów'], ['card', 'Karta .docx, PDF i tablet']];

    function renderProgress() {
        steps(2);
        view.innerHTML = `<div class="dc-grid"><div><div class="dc-card"><h4 class="dc-h">${esc(S.card.name)} <span class="dc-sub">${esc(S.card.group || '')}</span></h4>
            <ul class="dc-check">${ROWS.map(([k, label]) => `<li data-row="${k}" class="is-todo"><span class="dc-num">${ROWS.findIndex(r => r[0] === k) + 1}</span>
                <div><strong>${esc(label)}</strong><small data-msg></small></div><span class="dc-t" data-t></span></li>`).join('')}</ul>
            <div class="dc-nav" data-actions></div></div></div>
            <aside><div class="dc-card"><h4 class="dc-h">Co się dzieje ?</h4><p class="dc-hint">ERP czyta stronę filmu bez logowania, wycina klatki z filmu
                (tutaj, w przeglądarce) i wysyła opis z klatkami do asystenta AI. Asystent tłumaczy przepis na polski, porządkuje składniki i kroki
                i wybiera 6–9 zdjęć etapów. Nie zamykaj tej strony — zwykle 1–2 minuty.</p></div></aside></div>`;
    }

    function row(k, state, msg, t) {
        const li = view.querySelector(`[data-row="${k}"]`);
        if (!li) return;
        li.className = 'is-' + state;
        li.querySelector('.dc-num').innerHTML = state === 'done' ? '✓' : state === 'skip' ? '–' : state === 'error' ? '!' : state === 'run' ? '<span class="dc-spin"></span>' : String(ROWS.findIndex(r => r[0] === k) + 1);
        if (msg !== undefined) li.querySelector('[data-msg]').innerHTML = msg;
        if (t !== undefined) li.querySelector('[data-t]').textContent = t;
    }

    function actions(html) {
        const box = view.querySelector('[data-actions]');
        box.innerHTML = html;
        return box;
    }

    let running = false;
    async function run(opts = {}) {
        if (running) return;
        running = true;
        renderProgress();
        try {
            const c = S.card;
            // 1. Odczyt linku
            if (['facebook', 'pinterest'].includes(c.source.kind) && (!c.source.info || opts.reread)) {
                row('read', 'run', 'Otwieram stronę…');
                const t0 = Date.now();
                const res = await api(`/ajax/dev-cards/${c.id}/read`, 'POST', {});
                if (!res.ok) { row('read', 'error', esc(res.message)); return stop('read'); }
                S.card = res.data;
                row('read', 'done', readSummary(S.card), secs(t0));
            } else if (['facebook', 'pinterest'].includes(c.source.kind)) {
                row('read', 'done', readSummary(c));
            } else {
                row('read', 'skip', c.source.kind === 'file' ? 'Plik wideo z komputera' : 'Opis wklejony ręcznie');
            }
            // 2. Klatki
            const frames = S.card.frames.filter(f => f.kind === 'frame').length;
            if (frames && !opts.reframe) {
                row('frames', 'done', `${frames} klatek z filmu`);
            } else if (S.file || S.card.source.has_video) {
                const ok = await framesStep();
                if (!ok) return;
            } else {
                row('frames', 'skip', S.card.frames.length ? 'Brak wideo — zdjęcie okładki' : 'Brak wideo — karta bez zdjęć etapów (można je dodać później)');
            }
            // 3. Asystent AI
            row('ai', 'run', 'Asystent AI pracuje… zwykle 30–90 s');
            const t0 = Date.now();
            const tick = setInterval(() => row('ai', 'run', undefined, secs(t0)), 1000);
            const res = await api(`/ajax/dev-cards/${S.card.id}/generate`, 'POST', {});
            clearInterval(tick);
            if (!res.ok) { row('ai', 'error', esc(res.message)); return stop('ai'); }
            S.card = res.data;
            const card = S.card.card;
            row('ai', 'done', `${card.ingredients.reduce((n, g) => n + g.items.length, 0)} składników · ${card.steps.length} kroków · ${card.photos.length} zdjęć etapów`, secs(t0));
            row('card', 'done', 'Gotowe — sprawdź kartę');
            setTimeout(renderResult, 600);
        } finally {
            running = false;
        }
    }

    function stop(step) {
        const box = actions(`<button type="button" class="btn btn-outline-secondary dc-big" data-back>‹ Popraw dane</button><span class="dc-sp"></span>
            ${step === 'frames' ? '<button type="button" class="btn btn-outline-secondary dc-big" data-skip>Kontynuuj bez klatek</button>' : ''}
            <button type="button" class="btn btn-primary dc-big" data-retry>↻ Spróbuj ponownie</button>`);
        box.querySelector('[data-back]').addEventListener('click', () => { S.mode = S.card.source.kind === 'file' ? 'file' : 'link'; renderForm(); prefill(); });
        box.querySelector('[data-retry]').addEventListener('click', () => run({ reread: step === 'read', reframe: step === 'frames' }));
        box.querySelector('[data-skip]')?.addEventListener('click', () => { S.file = null; S.card.source.has_video = false; run(); });
    }

    /** Formularz po « Popraw dane » : szkic zaczyna się od nowa z tymi samymi polami. */
    function prefill() {
        const form = view.querySelector('[data-form]');
        if (!form || !S.card) return;
        if (S.card.source.url) form.elements.url.value = S.card.source.url;
        if (S.card.source.text && form.elements.text) { form.elements.text.value = S.card.source.text; form.elements.text.hidden = false; }
        form.dispatchEvent(new Event('input'));
    }

    function readSummary(c) {
        const i = c.source.info || {};
        const parts = [c.source.author ? esc(c.source.author) : null, i.has_text ? 'pełny opis' : 'bez opisu', i.has_video ? 'wideo ✓' : 'bez wideo'].filter(Boolean);
        const notes = (i.notes || []).map(n => `<span class="dc-note">${esc(n)}</span>`).join('');
        return parts.join(' · ') + notes;
    }

    async function framesStep() {
        row('frames', 'run', S.file ? 'Czytam plik wideo…' : 'Pobieram wideo z serwera…');
        const t0 = Date.now();
        let src = null;
        try {
            if (S.file) {
                src = URL.createObjectURL(S.file);
            } else {
                const r = await fetch(`${at.root}/ajax/dev-cards/${S.card.id}/video`, { credentials: 'same-origin' });
                if (!r.ok) {
                    const body = await r.json().catch(() => ({}));
                    row('frames', 'error', esc(body.message || `Nie udało się pobrać wideo (HTTP ${r.status}).`));
                    stop('frames');
                    return false;
                }
                src = URL.createObjectURL(await r.blob());
            }
            const frames = await extract(src, (i, n) => row('frames', 'run', `Wycinam klatki : ${i} / ${n}`));
            for (let i = 0; i < frames.length; i += BATCH) {
                row('frames', 'run', `Wysyłam klatki : ${Math.min(i + BATCH, frames.length)} / ${frames.length}`);
                const res = await api(`/ajax/dev-cards/${S.card.id}/frames`, 'POST', { replace: i === 0, frames: frames.slice(i, i + BATCH) });
                if (!res.ok) { row('frames', 'error', esc(res.message)); stop('frames'); return false; }
                S.card = res.data;
            }
            row('frames', 'done', `${frames.length} klatek z filmu`, secs(t0));
            return true;
        } catch (err) {
            row('frames', 'error', esc(err.message || 'Nie udało się odczytać wideo.'));
            stop('frames');
            return false;
        } finally {
            if (src) URL.revokeObjectURL(src);
        }
    }

    /** Klatki co 1 s (≤ 15 s), co 1,5 s (15–40 s), co 3 s (dłużej) ; najwyżej 40. JPEG, dłuższy bok ≤ 1280 px. */
    function extract(src, progress) {
        return new Promise((resolve, reject) => {
            const v = document.createElement('video');
            v.muted = true; v.playsInline = true; v.preload = 'auto'; v.crossOrigin = 'anonymous';
            const fail = () => reject(new Error('Przeglądarka nie odtwarza tego wideo — użyj Chrome / Edge albo kontynuuj bez klatek.'));
            v.addEventListener('error', fail, { once: true });
            v.addEventListener('loadedmetadata', async () => {
                try {
                    let dur = v.duration;
                    if (!Number.isFinite(dur) || dur <= 0) dur = 60;
                    const step = dur <= 15 ? 1 : (dur <= 40 ? 1.5 : 3);
                    let times = [];
                    for (let t = Math.min(0.5, dur / 2); t < dur; t += step) times.push(t);
                    if (times.length > MAX_FRAMES) times = Array.from({ length: MAX_FRAMES }, (_, i) => (i + 0.5) * dur / MAX_FRAMES);
                    const scale = Math.min(1, 1280 / Math.max(v.videoWidth || 1, v.videoHeight || 1));
                    const canvas = document.createElement('canvas');
                    canvas.width = Math.round((v.videoWidth || 720) * scale);
                    canvas.height = Math.round((v.videoHeight || 1280) * scale);
                    const ctx = canvas.getContext('2d');
                    const out = [];
                    for (let i = 0; i < times.length; i++) {
                        await seek(v, times[i]);
                        ctx.drawImage(v, 0, 0, canvas.width, canvas.height);
                        out.push({ image: canvas.toDataURL('image/jpeg', 0.82), at_ms: Math.round(times[i] * 1000) });
                        progress(i + 1, times.length);
                    }
                    resolve(out);
                } catch (e) { reject(e); }
            }, { once: true });
            v.src = src;
        });
    }

    function seek(v, t) {
        return new Promise((resolve, reject) => {
            const timer = setTimeout(() => reject(new Error('Wideo nie odpowiada przy przewijaniu.')), 15000);
            v.addEventListener('seeked', () => { clearTimeout(timer); resolve(); }, { once: true });
            v.currentTime = t;
        });
    }

    // --- Krok 3 : karta i tablet ------------------------------------------------------------------------

    function summaryAside() {
        const c = S.card;
        const f = c.forecast || {};
        return `<div class="dc-card"><h4 class="dc-h">Sprzedaż</h4><dl class="dc-kv">
            <dt>Na tydzień</dt><dd>${fmt(c.weekly_sales)} szt.</dd>
            <dt>Sezon</dt><dd>${c.seasonal ? (f.quarters || []).map(q => 'Q' + q).join(' + ') : 'cały rok'}</dd>
            <dt>Kwartał</dt><dd>${fmt((c.weekly_sales || 0) * 13)} szt.</dd>
            <dt>Rok</dt><dd><b>${fmt(f.year)} szt.</b></dd></dl></div>`;
    }

    function renderResult() {
        steps(3);
        const c = S.card;
        const card = c.card;
        if (!card) return renderDraft();
        const saved = c.status === 'saved';
        const frames = Object.fromEntries(c.frames.map(f => [f.id, f]));
        const notes = card.notes || [];
        view.innerHTML = `<div class="dc-grid dc-grid-result"><div>
            <div class="dc-card"><h4 class="dc-h"><span class="dc-num is-ok">✓</span>${saved ? 'Zapisano przy produkcie' : 'Karta gotowa'}</h4>
                ${saved ? (c.on_tablet ? `<p class="dc-saved"><i class="bi bi-tablet"></i> Produkt « ${esc(c.product_name || c.name)} » jest na tablecie grupy ${esc(c.group || '')} (Nowe produkty → Pomysł).</p>`
                    : `<p class="dc-saved"><i class="bi bi-printer"></i> Produkt « ${esc(c.product_name || c.name)} » zapisany w Nowe produkty → Pomysł, bez tabletu (karta do druku).</p>`) : ''}
                <dl class="dc-kv">
                    <dt>Źródło</dt><dd>${c.source.url ? `<a href="${esc(c.source.url)}" target="_blank" rel="noopener noreferrer">${esc(c.source.kind === 'pinterest' ? 'Pinterest' : 'Facebook')}</a>` : esc({ file: 'Plik wideo', docx: 'Karta .docx', text: 'Opis wklejony' }[c.source.kind] || '')}${c.source.author ? ' · ' + esc(c.source.author) : ''}</dd>
                    ${c.model ? `<dt>Asystent AI</dt><dd>${esc(c.model)}${c.generation_ms ? ' · ' + Math.round(c.generation_ms / 1000) + ' s' : ''}</dd>` : ''}
                    <dt>Składniki</dt><dd>${card.ingredients.reduce((n, g) => n + g.items.length, 0)} w ${card.ingredients.length} częściach</dd>
                    <dt>Kroki</dt><dd>${card.steps.length}</dd></dl>
                ${notes.length ? `<ul class="dc-notes">${notes.map(n => `<li>${esc(n)}</li>`).join('')}</ul>` : ''}</div>
            <div class="dc-card"><h4 class="dc-h">Zdjęcia etapów <span class="dc-sub">dotknij zdjęcie, aby wybrać inną klatkę</span></h4>
                <div class="dc-thumbs">${card.photos.map((p, i) => {
                    const f = frames[p.frame_id];
                    return `<figure data-photo="${i}"><button type="button" class="dc-thumb" data-pick="${i}" title="Zmień klatkę"><img src="${f ? at.root + f.src : ''}" alt=""><b>${i + 1}</b></button>
                        <input class="dc-cap" data-cap="${i}" value="${esc(p.caption)}" maxlength="60" aria-label="Podpis zdjęcia ${i + 1}">
                        <button type="button" class="dc-x" data-remove="${i}" aria-label="Usuń zdjęcie">×</button></figure>`;
                }).join('')}${card.photos.length < 12 && c.frames.length ? `<button type="button" class="dc-add" data-pick="new">+ klatka<br>z filmu</button>` : ''}</div>
                ${!c.frames.length ? '<p class="dc-hint">Brak klatek z filmu.</p>' : ''}</div>
            <div class="dc-card"><div class="dc-row dc-between"><h4 class="dc-h dc-m0">Produkt</h4><button type="button" class="btn btn-outline-secondary btn-sm" data-edit-product>Zmień</button></div>
                <dl class="dc-kv"><dt>Nazwa</dt><dd>${esc(c.name)}</dd>
                <dt>Grupa</dt><dd><span class="dc-chip-static"><i style="background:${esc(c.group_color || groupColor(c.id_group))}"></i>${esc(c.group || groupName(c.id_group))}</span></dd>
                <dt>Sprzedaż</dt><dd>${fmt(c.weekly_sales)} szt./tydz.</dd>
                <dt>Sezon</dt><dd>${c.seasonal ? (c.forecast.quarters || []).map(q => 'Q' + q).join(' + ') + ' · ' + fmt((c.weekly_sales || 0) * 13) + ' szt./kwartał' : 'cały rok'}</dd>
                <dt>Rok</dt><dd><b>${fmt(c.forecast.year)} szt.</b></dd>
                ${c.category ? `<dt>Kategoria</dt><dd>${esc(c.category)}</dd>` : ''}
                ${Number(c.id_type || 1) !== 1 ? `<dt>Typ</dt><dd>${esc(c.type || '')}</dd>` : ''}
                ${Number(c.label_type) === 1 ? '<dt>Etykieta</dt><dd>duża</dd>' : ''}
                ${pricingRows(c.pricing)}</dl></div>
            <div class="dc-act">
                ${saved ? `<a class="btn btn-primary dc-big" href="${at.root}/test-products/${c.product_id}/tablet"><i class="bi bi-box-arrow-up-right"></i> Otwórz produkt</a>
                    <div class="dc-choice">${c.on_tablet ? '' : `<button type="button" class="btn btn-outline-primary dc-big" data-save="tablet"><i class="bi bi-tablet"></i> Wyślij też na tablet</button>`}
                        <button type="button" class="btn btn-outline-primary dc-big" data-print><i class="bi bi-printer"></i> Drukuj kartę</button>
                        <button type="button" class="btn btn-outline-primary dc-big" data-cost><i class="bi bi-calculator"></i> Koszt i cena</button></div>`
                    : `<button type="button" class="btn btn-primary dc-big" data-cost>Dalej : koszt i cena <i class="bi bi-arrow-right"></i></button>
                    <p class="dc-hint dc-m0">Ostatni krok : surowce z karty → nasze surowce, ceny, cobot ; potem tablet albo druk.</p>`}
                <div class="dc-act-row"><a class="btn btn-outline-secondary" href="${at.root}/test-products/cards/${c.id}/card.docx"><i class="bi bi-file-earmark-word"></i> .docx</a>
                    <a class="btn btn-outline-secondary" href="${at.root}/test-products/cards/${c.id}/card.pdf?inline=1" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
                    ${c.source.kind !== 'docx' ? `<button type="button" class="btn btn-outline-secondary" data-regen ${S.ai.configured ? '' : 'disabled'}>↻ Jeszcze raz</button>` : ''}</div>
                ${saved ? '' : '<button type="button" class="dc-link dc-danger" data-delete>Usuń szkic</button>'}
            </div>
        </div>
        <div>
            <div class="dc-paper-bar"><div class="dc-seg dc-seg-sm">
                <label><input type="radio" name="pv" value="a4" ${S.preview === 'a4' ? 'checked' : ''}><span>Karta A4</span></label>
                <label><input type="radio" name="pv" value="tablet" ${S.preview === 'tablet' ? 'checked' : ''}><span>Widok tabletu</span></label></div>
                <span class="dc-sp"></span><span class="dc-pill w">Gramatury do sprawdzenia w teście</span>
                <button type="button" class="btn btn-outline-secondary" data-edit-text><i class="bi bi-pencil"></i> Popraw tekst</button></div>
            <div data-preview></div>
        </div></div>`;
        drawPreview();
        view.querySelectorAll('[name=pv]').forEach(r => r.addEventListener('change', () => { S.preview = r.value; drawPreview(); }));
        view.querySelector('[data-edit-text]').addEventListener('click', () => { S.editing = !S.editing; drawPreview(); });
        view.querySelector('[data-edit-product]').addEventListener('click', () => renderForm({ edit: true }));
        view.querySelectorAll('[data-pick]').forEach(b => b.addEventListener('click', () => picker(b.dataset.pick)));
        // Zawsze aktualna lista (S.card) : podpis zapisany chwilę wcześniej nie może zginąć przy usunięciu zdjęcia.
        view.querySelectorAll('[data-remove]').forEach(b => b.addEventListener('click', () => {
            const photos = S.card.card.photos.filter((_, i) => i !== Number(b.dataset.remove));
            savePhotos(photos);
        }));
        view.querySelectorAll('[data-cap]').forEach(inp => inp.addEventListener('change', () => {
            const photos = S.card.card.photos.map((p, i) => i === Number(inp.dataset.cap) ? { ...p, caption: inp.value } : p);
            savePhotos(photos, true);
        }));
        view.querySelectorAll('[data-save]').forEach(b => b.addEventListener('click', () => save(b.dataset.save)));
        view.querySelectorAll('[data-cost]').forEach(b => b.addEventListener('click', openCost));
        view.querySelector('[data-print]')?.addEventListener('click', printCard);
        view.querySelector('[data-regen]')?.addEventListener('click', () => {
            if (!window.confirm('Wygenerować kartę ponownie ? Poprawki tekstu i zdjęć zostaną zastąpione.')) return;
            run();
        });
        view.querySelector('[data-delete]')?.addEventListener('click', async () => {
            if (!window.confirm('Usunąć ten szkic karty ?')) return;
            const res = await api(`/ajax/dev-cards/${c.id}`, 'DELETE');
            if (!res.ok) return at.notify(false, res.message);
            location.href = at.root + '/test-products/new';
        });
    }

    /** Koszt i cena (krok 4) w podsumowaniu produktu : koszt / szt., fabryka, sklep, cobot. */
    function pricingRows(p) {
        if (!p) return '';
        const cob = { yes: 'tak', no: 'nie', check: 'do sprawdzenia' }[p.cobot];
        return `${p.cost_pc_pln !== null ? `<dt>Koszt / szt.</dt><dd><b>${money(p.cost_pc_pln, 'zł')}</b></dd>` : ''}
            ${p.factory_eur !== null ? `<dt>Fabryka</dt><dd><b>${money(p.factory_eur, '€')}</b> (× ${num(p.factory_coef, 1)})</dd>` : ''}
            ${p.store_eur !== null ? `<dt>Sklep</dt><dd><b>${money(p.store_eur, '€')}</b>${p.ratio !== null ? ` <span class="dc-pill ${p.verdict === 'ok' ? 'ok' : 'w'}">× ${num(p.ratio)}</span>` : ''}</dd>` : ''}
            ${cob ? `<dt>Cobot</dt><dd>${cob}${p.cobot_steps.length ? ' — ' + esc(p.cobot_steps.join(', ')) : ''}</dd>` : ''}`;
    }

    /** Szkic bez karty (np. przerwane generowanie) : kontynuacja od brakującego kroku. */
    function renderDraft() {
        renderProgress();
        const c = S.card;
        if (['facebook', 'pinterest'].includes(c.source.kind)) row('read', c.source.info ? 'done' : 'todo', c.source.info ? readSummary(c) : '');
        else row('read', 'skip', c.source.kind === 'file' ? 'Plik wideo z komputera' : 'Opis wklejony ręcznie');
        const n = c.frames.filter(f => f.kind === 'frame').length;
        row('frames', n ? 'done' : 'todo', n ? `${n} klatek z filmu` : (c.source.kind === 'file' ? 'Wybierz plik wideo ponownie, aby wyciąć klatki' : ''));
        const box = actions(`<a class="btn btn-outline-secondary dc-big" href="${at.root}/test-products/new">Nowa karta</a><span class="dc-sp"></span>
            ${c.source.kind === 'file' && !n ? '<input type="file" accept="video/*" class="form-control dc-input" data-refile>' : ''}
            <button type="button" class="btn btn-primary dc-big" data-continue ${S.ai.configured ? '' : 'disabled'}><i class="bi bi-stars"></i> Kontynuuj</button>`);
        box.querySelector('[data-refile]')?.addEventListener('change', (e) => { S.file = e.target.files[0] || null; });
        box.querySelector('[data-continue]').addEventListener('click', () => run());
    }

    async function savePhotos(photos, quiet) {
        const res = await api(`/ajax/dev-cards/${S.card.id}`, 'PUT', { card: { photos } });
        if (!res.ok) return at.notify(false, res.message);
        S.card = res.data;
        if (!quiet) renderResult(); else drawPreview();
    }

    function picker(which) {
        const c = S.card;
        const box = document.createElement('div');
        box.className = 'dc-modal';
        box.innerHTML = `<div class="dc-modal-box" role="dialog" aria-modal="true" aria-label="Wybierz klatkę"><div class="dc-row dc-between"><h4 class="dc-h dc-m0">Wybierz klatkę z filmu</h4>
            <button type="button" class="btn btn-outline-secondary" data-close>Zamknij</button></div>
            <div class="dc-frames">${c.frames.map(f => `<button type="button" data-frame="${f.id}"><img src="${at.root + f.src}" alt="" loading="lazy">
                <small>${f.kind === 'cover' ? 'okładka' : (f.at_ms !== null ? clock(f.at_ms) : '#' + f.position)}</small></button>`).join('')}</div></div>`;
        document.body.appendChild(box);
        const close = () => box.remove();
        box.addEventListener('click', (e) => { if (e.target === box) close(); });
        box.querySelector('[data-close]').addEventListener('click', close);
        box.querySelectorAll('[data-frame]').forEach(b => b.addEventListener('click', () => {
            const id = Number(b.dataset.frame);
            const photos = S.card.card.photos.slice();
            if (which === 'new') photos.push({ frame_id: id, caption: '' });
            else photos[Number(which)] = { ...photos[Number(which)], frame_id: id };
            close();
            savePhotos(photos);
        }));
    }

    // « tablet » : produkt na tablecie grupy ; « print » : produkt bez tabletu i karta A4 od razu do druku.
    async function save(mode) {
        const btns = view.querySelectorAll('[data-save]');
        btns.forEach(b => { b.disabled = true; });
        const res = await api(`/ajax/dev-cards/${S.card.id}/save`, 'POST', { tablet: mode === 'tablet' });
        btns.forEach(b => { b.disabled = false; });
        if (!res.ok) return at.notify(false, res.message);
        S.card = res.data;
        if (mode === 'print') {
            at.notify(true, 'Zapisano — karta otwiera się do druku (produkt bez tabletu).');
            printCard();
        } else at.notify(true, `Zapisano — produkt jest na tablecie grupy ${S.card.group || ''}.`);
        renderResult();
    }

    // Druk karty A4 (PDF) : okno drukowania przeglądarki ; gdy przeglądarka go nie otworzy — PDF w nowej karcie.
    function printCard() {
        const url = `${at.root}/test-products/cards/${S.card.id}/card.pdf?inline=1`;
        document.querySelector('iframe.dc-print')?.remove();
        const frame = document.createElement('iframe');
        frame.className = 'dc-print';
        frame.setAttribute('aria-hidden', 'true');
        frame.addEventListener('load', () => {
            setTimeout(() => {
                try { frame.contentWindow.focus(); frame.contentWindow.print(); } catch { window.open(url, '_blank', 'noopener'); }
            }, 300);
        });
        frame.src = url;
        document.body.appendChild(frame);
    }

    function drawPreview() {
        const box = view.querySelector('[data-preview]');
        if (!box) return;
        const c = S.card;
        const card = c.card;
        const frames = Object.fromEntries(c.frames.map(f => [f.id, f]));
        const photos = card.photos.map((p, i) => ({ ...p, n: i + 1, src: frames[p.frame_id] ? at.root + frames[p.frame_id].src : '' }));
        const origin = [card.original_name, card.author ? (card.original_name ? '— ' : '') + 'wg ' + card.author : ''].filter(Boolean).join(' ');
        if (S.editing) {
            box.innerHTML = `<form class="dc-card dc-edit" data-edit>
                <div class="dc-field"><label class="dc-label">Tytuł karty</label><input class="form-control" name="title" value="${esc(card.title)}" maxlength="160"></div>
                <div class="dc-row dc-tight"><div class="dc-field dc-grow"><label class="dc-label">Oryginalna nazwa</label><input class="form-control" name="original_name" value="${esc(card.original_name)}" maxlength="160"></div>
                    <div class="dc-field dc-grow"><label class="dc-label">Autor</label><input class="form-control" name="author" value="${esc(card.author)}" maxlength="120"></div></div>
                <div class="dc-row dc-tight">${[['yield', 'Wydajność'], ['baking', 'Pieczenie'], ['time', 'Czas']].map(([k, l]) =>
                    `<div class="dc-field dc-grow"><label class="dc-label">${l}</label><input class="form-control" name="${k}" value="${esc(card[k])}" maxlength="200"></div>`).join('')}</div>
                <div class="dc-row dc-tight dc-top"><div class="dc-field dc-grow"><label class="dc-label">Składniki — nagłówek części w osobnej linii (np. « CIASTO: »), potem « 500 g mąki »</label>
                    <textarea class="form-control dc-mono" name="ingredients_text" rows="16">${esc(c.card.ingredients_text || '')}</textarea></div>
                    <div class="dc-field dc-grow"><label class="dc-label">Sposób wykonania — jeden krok w linii</label>
                    <textarea class="form-control" name="steps_text" rows="16">${esc(c.card.steps_text || '')}</textarea></div></div>
                <div class="dc-nav"><button type="button" class="btn btn-outline-secondary dc-big" data-cancel-edit>Anuluj</button><span class="dc-sp"></span>
                    <button type="submit" class="btn btn-primary dc-big">Zapisz poprawki</button></div></form>`;
            const form = box.querySelector('[data-edit]');
            form.querySelector('[data-cancel-edit]').addEventListener('click', () => { S.editing = false; drawPreview(); });
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                const data = {};
                ['title', 'original_name', 'author', 'yield', 'baking', 'time', 'ingredients_text', 'steps_text'].forEach(k => { data[k] = form.elements[k].value; });
                const res = await api(`/ajax/dev-cards/${S.card.id}`, 'PUT', { card: data });
                if (!res.ok) return at.notify(false, res.message);
                S.card = res.data;
                S.editing = false;
                at.notify(true, 'Zapisano poprawki.');
                renderResult();
            });
            return;
        }
        if (S.preview === 'tablet') {
            box.innerHTML = `<div class="dc-tab"><div class="dc-tab-head">${photos.length ? `<img src="${photos[photos.length - 1].src}" alt="">` : ''}<div>
                    <span class="dc-tab-stage">Pomysł · test 1 do zrobienia</span><h2>${esc(c.name)}</h2><p>${esc(['Karta technologiczna z filmu', origin].filter(Boolean).join(' · '))}</p></div></div>
                <div class="dc-tab-facts">${[['Wydajność', card.yield], ['Pieczenie', card.baking], ['Czas', card.time]].map(([l, v]) => `<div><small>${l}</small><b>${esc(v || '—')}</b></div>`).join('')}</div>
                <div class="dc-tab-cols"><div><h3>Składniki</h3>${card.ingredients.map(g => `${g.group ? `<div class="dc-tab-sub">${esc(g.group)}</div>` : ''}<table>${g.items.map(it =>
                    `<tr><td>${esc(it.qty)}</td><td>${esc(it.name)}</td></tr>`).join('')}</table>`).join('')}</div>
                    <div><h3>Sposób wykonania</h3><ol>${card.steps.map(s => `<li>${esc(s)}</li>`).join('')}</ol></div></div>
                ${photos.length ? `<h3>Etapy – zdjęcia</h3><div class="dc-tab-photos">${photos.map(p => `<figure><img src="${p.src}" alt=""><figcaption><b>${p.n}.</b> ${esc(p.caption)}</figcaption></figure>`).join('')}</div>` : ''}</div>`;
            return;
        }
        box.innerHTML = `<div class="dc-paper"><article class="dc-a4">
            <h1>${esc(card.title || c.name)}</h1>
            <div class="dc-a4-sub"><span>${esc(origin)}</span><span>L’Atelier By · Karta technologiczna</span></div>
            <div class="dc-a4-facts">${[['WYDAJNOŚĆ', card.yield], ['PIECZENIE', card.baking], ['CZAS', card.time]].map(([l, v]) => `<div><small>${l}</small>${esc(v || '—')}</div>`).join('')}</div>
            <div class="dc-a4-cols"><div><h2>Składniki</h2>${card.ingredients.map(g => `${g.group ? `<h4>${esc(g.group)}</h4>` : ''}<ul>${g.items.map(it => `<li>${esc((it.qty + ' ' + it.name).trim())}</li>`).join('')}</ul>`).join('')}</div>
                <div><h2>Sposób wykonania</h2><ol>${card.steps.map(s => `<li>${esc(s)}</li>`).join('')}</ol></div></div>
            ${photos.length ? `<h2>Etapy – zdjęcia</h2><div class="dc-a4-photos">${photos.map(p => `<figure><img src="${p.src}" alt=""><figcaption><b>${p.n}.</b> ${esc(p.caption)}</figcaption></figure>`).join('')}</div>` : ''}
            <p class="dc-a4-foot">${esc(c.footer || '')}</p></article></div>`;
    }

    // --- Krok 4 : koszt i cena [198] --------------------------------------------------------------------
    // Receptura z karty → nasze surowce albo receptury (kompozycje, półprodukty) [199] — podpowiedzi serwera, wyszukiwarka,
    // wybór operatora zapamiętany ; linia wskazuje « m12 » (surowiec) albo « r45 » (receptura) ; ilość w jednostce surowca,
    // koszt partii i sztuki ; cena fabryczna = koszt × współczynnik ; cena w sklepie netto = fabryka × mnożnik ; cobot.

    const ST = { auto: ['ok', '✓ auto'], ok: ['ok', '✓'], check: ['w', '? sprawdź'], missing: ['bad', '✗ brak'], estimate: ['w', '≈ szacunek'], free: ['', 'bez kosztu'] };
    const num = (n, d = 2) => Number(n || 0).toLocaleString('pl-PL', { minimumFractionDigits: d, maximumFractionDigits: d }).replace(/ /g, ' ');
    const money = (n, unit) => n === null || n === undefined || !Number.isFinite(Number(n)) ? '—' : num(n) + ' ' + unit;
    const parseNum = (v) => { const x = parseFloat(String(v ?? '').replace(/\s/g, '').replace(',', '.')); return Number.isFinite(x) ? x : null; };
    const qtyText = (q) => String(Math.round(Number(q || 0) * 100) / 100).replace('.', ',');
    const fold = (v) => String(v || '').toLowerCase().replace(/ł/g, 'l').normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    const roundTo = (v, step) => v === null ? null : (step > 0 ? Math.round(Math.round(v / step) * step * 100) / 100 : Math.round(v * 100) / 100);
    let C = null;
    let dirty = false;
    window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

    async function openCost() {
        steps(4);
        view.innerHTML = '<p class="at-empty">Liczę koszt surowców…</p>';
        const res = await api(`/ajax/dev-cards/${S.card.id}/cost`, 'GET');
        if (!res.ok) { at.notify(false, res.message); return renderResult(); }
        setCost(res.data);
        history.replaceState(null, '', `${at.root}/test-products/new/${S.card.id}#koszt`);
        renderCost();
    }

    function setCost(data) {
        C = data;
        C.items = [...C.materials.map(m => ({ ...m, kind: 'm', type: 'Surowiec' })), ...(C.recipes || []).map(r => ({ ...r, kind: 'r' }))];
        C.items.forEach(x => { x.w = fold(x.name).split(/[^a-z0-9]+/).filter(Boolean); });
        C.byRef = Object.fromEntries(C.items.map(x => [x.ref, x]));
        C.factory.coefs = [...new Set([...C.factory.coefs, C.factory.coef].map(Number))].sort((a, b) => a - b);
        C.store.mults = [...new Set([...C.store.mults, C.store.mult].map(Number))].sort((a, b) => a - b);
        dirty = false;
    }

    function lineCost(l) {
        if (l.ref) { const m = C.byRef[l.ref]; return m ? l.qty * m.price : 0; }
        return l.status === 'estimate' && l.price_kg !== null ? l.qty * l.price_kg / 1000 : 0;
    }

    function totals() {
        const total = C.lines.reduce((a, l) => a + lineCost(l), 0);
        const pc = C.yield_pcs > 0 ? total / C.yield_pcs : null;
        const rate = C.rate;
        const fEur = pc !== null && rate ? pc * C.factory.coef / rate : null;
        const sEur = fEur !== null ? fEur * C.store.mult : null;
        const final = C.store.manual ? C.store.final_eur : roundTo(sEur, C.store.round);
        const ratio = fEur && final ? final / fEur : null;
        return { total, pc, pcEur: pc !== null && rate ? pc / rate : null, fPln: pc !== null ? pc * C.factory.coef : null, fEur, sEur, final, ratio,
            verdict: ratio === null ? 'none' : ratio >= C.sellability.multiplier ? 'ok' : (ratio >= C.sellability.limit ? 'warn' : 'bad'),
            shareF: 100 / C.factory.coef, shareS: pc !== null && rate && final ? pc / rate / final * 100 : null };
    }

    const itemLabel = (m) => m.kind === 'r' ? `${m.name} · ${m.type.toLowerCase()}` : m.name;

    function lineRow(i) {
        const l = C.lines[i];
        const sugg = (l.suggestions || []).filter(r => C.byRef[r]);
        const m = l.ref ? C.byRef[l.ref] : null;
        const opt = (x) => `<option value="${x.ref}" ${l.ref === x.ref ? 'selected' : ''}>${esc(itemLabel(x))}</option>`;
        const [cls, label] = ST[l.status] || ST.check;
        const price = m ? `<span class="dc-price">${esc(m.price_label)}</span>` : (l.status === 'free' ? ''
            : `<span class="dc-qty dc-pkg"><input class="form-control" data-pkg inputmode="decimal" value="${l.price_kg !== null ? qtyText(l.price_kg) : ''}" placeholder="cena" aria-label="Cena szacunkowa zł/kg"><small>zł/kg</small></span>`);
        return `<tr data-line="${i}" class="st-${l.status}">
            <td class="src"><b>${esc(l.qty_text)}</b> ${esc(l.name)}${l.note ? `<span class="dc-why">${esc(l.note)}</span>` : ''}</td>
            <td><div class="dc-sel"><select class="form-select" data-mat aria-label="Surowiec albo receptura : ${esc(l.src)}">
                    <option value="" ${!l.ref && l.status !== 'free' ? 'selected' : ''}>— brak w ERP (cena szacunkowa) —</option>
                    <option value="free" ${l.status === 'free' ? 'selected' : ''}>— bez kosztu (woda) —</option>
                    ${sugg.length ? `<optgroup label="Podpowiedzi">${sugg.map(r => opt(C.byRef[r])).join('')}</optgroup>` : ''}
                    ${m && !sugg.includes(l.ref) ? `<optgroup label="Wybrane">${opt(m)}</optgroup>` : ''}
                    <option value="search">🔍 Szukaj : wszystkie surowce i receptury…</option></select>
                <div class="dc-sel-meta"><button type="button" class="dc-st ${cls}" data-st title="${['check', 'auto'].includes(l.status) ? 'Dotknij, aby potwierdzić' : ''}">${label}</button>${
                    m && m.kind === 'r' ? `<span class="dc-kind">${esc(m.type)}</span>` : ''}${price}</div></div></td>
            <td class="n"><span class="dc-qty"><input class="form-control" data-qty inputmode="decimal" value="${qtyText(l.qty)}" aria-label="Ilość"><small>${esc(m ? m.unit : 'g')}</small></span></td>
            <td class="n" data-cost></td></tr>`;
    }

    // Wyszukiwarka : surowce i receptury (kompozycje, półprodukty) ; każde słowo = początek słowa nazwy, bez końcówki
    // (« ciasta » → « cias… » : Ciasto) ; najpierw nazwy z pełnymi słowami.
    function searchPick(i, done) {
        const l = C.lines[i];
        const box = document.createElement('div');
        box.className = 'dc-modal';
        const KINDS = [['', 'Wszystko'], ['m', 'Surowce'], ['r', 'Nasze receptury']];
        let kind = '';
        box.innerHTML = `<div class="dc-modal-box dc-find" role="dialog" aria-modal="true" aria-label="Szukaj surowca albo receptury">
            <div class="dc-row dc-between"><h4 class="dc-h dc-m0">Nasz surowiec dla « ${esc(l.src)} »</h4>
                <button type="button" class="btn btn-outline-secondary" data-close>Zamknij</button></div>
            <input class="form-control dc-find-q" type="search" data-q placeholder="Szukaj : nazwa surowca albo receptury" aria-label="Szukaj" autocomplete="off">
            <div class="dc-seg dc-seg-sm" role="radiogroup" aria-label="Rodzaj">${KINDS.map(([v, t]) =>
                `<label><input type="radio" name="find_kind" value="${v}" ${v === '' ? 'checked' : ''}><span>${t}</span></label>`).join('')}</div>
            <div class="dc-find-list" data-list role="listbox"></div></div>`;
        document.body.appendChild(box);
        const q = box.querySelector('[data-q]');
        const list = box.querySelector('[data-list]');
        const LIMIT = 60;
        const draw = () => {
            const full = fold(q.value).split(/[^a-z0-9]+/).filter(Boolean);
            const words = full.map(w => w.length > 4 ? w.slice(0, w.length - 2) : w);
            const score = (x) => words.every(w => x.w.some(v => v.startsWith(w))) ? full.filter(w => x.w.some(v => v.startsWith(w))).length : -1;
            const pool = C.items.filter(x => !kind || x.kind === kind).map(x => [x, score(x)]).filter(([, n]) => n >= 0)
                .sort((a, b) => b[1] - a[1]).map(([x]) => x);
            const sugg = words.length ? [] : (l.suggestions || []).map(r => C.byRef[r]).filter(x => x && (!kind || x.kind === kind));
            const rest = pool.filter(x => !sugg.includes(x));
            const row = (x) => `<button type="button" class="dc-find-item ${x.ref === l.ref ? 'on' : ''}" data-ref="${x.ref}" role="option">
                <span>${esc(x.name)}</span><span class="dc-kind ${x.kind === 'm' ? 'm' : ''}">${esc(x.type)}</span><span class="dc-price">${esc(x.price_label)}</span></button>`;
            list.innerHTML = (sugg.length ? `<p class="dc-label">Podpowiedzi</p>${sugg.map(row).join('')}<p class="dc-label">Wszystkie</p>` : '')
                + (rest.length ? rest.slice(0, LIMIT).map(row).join('') : '<p class="at-empty">Nic nie znaleziono — zmień słowo albo rodzaj.</p>')
                + (rest.length > LIMIT ? `<p class="dc-hint">… i ${rest.length - LIMIT} więcej — zawęź wyszukiwanie.</p>` : '');
        };
        const close = () => { box.remove(); document.removeEventListener('keydown', onKey); };
        const pick = (ref) => { close(); done(ref); };
        const onKey = (e) => {
            if (e.key === 'Escape') close();
            else if (e.key === 'Enter' && document.activeElement === q) { const b = list.querySelector('[data-ref]'); if (b) pick(b.dataset.ref); }
        };
        document.addEventListener('keydown', onKey);
        box.addEventListener('click', (e) => {
            if (e.target === box || e.target.closest('[data-close]')) return close();
            const b = e.target.closest('[data-ref]');
            if (b) pick(b.dataset.ref);
        });
        q.addEventListener('input', draw);
        box.querySelectorAll('[name=find_kind]').forEach(r => r.addEventListener('change', () => { kind = r.value; draw(); q.focus(); }));
        draw();
        q.focus();
    }

    function renderCost() {
        steps(4);
        const c = S.card;
        const saved = c.status === 'saved';
        const groups = [];
        C.lines.forEach((l, i) => {
            if (!groups.length || groups[groups.length - 1].name !== l.group) groups.push({ name: l.group, rows: [] });
            groups[groups.length - 1].rows.push(i);
        });
        view.innerHTML = `<div class="dc-grid dc-grid-cost"><div>
            <div class="dc-card"><h4 class="dc-h"><span class="dc-num">1</span>Surowce : karta → nasze surowce i receptury <span class="dc-pill ok dc-ml" data-progress-label></span></h4>
                <div class="dc-prog"><i data-progress></i></div>
                <div class="dc-map-wrap"><table class="dc-map"><thead><tr><th>Z karty</th><th>Nasz surowiec / receptura</th><th class="n">Ilość</th><th class="n">Koszt</th></tr></thead>
                <tbody>${groups.map(g => `${g.name ? `<tr class="grp"><td colspan="4">${esc(g.name)}</td></tr>` : ''}${g.rows.map(lineRow).join('')}`).join('')}</tbody>
                <tfoot><tr><td colspan="3"><label class="dc-yield">Partia : <input class="form-control" name="yield_pcs" inputmode="numeric" value="${C.yield_pcs ?? ''}" aria-label="Sztuk z partii"> szt.</label>
                    ${C.yield_text ? `<small>z karty : « ${esc(C.yield_text)} »</small>` : ''}</td><td class="n" data-total></td></tr>
                    <tr class="pc"><td colspan="3">Koszt surowców na sztukę</td><td class="n" data-pc></td></tr></tfoot></table></div>
                <p class="dc-hint">Podpowiedzi według nazw, wybór zapamiętany dla następnych kart. Cena surowca = najwyższa zatwierdzona (jak w kalkulacji receptur) ;
                    nasza receptura (kompozycja, półprodukt) = koszt jej surowców ÷ wydajność.
                    Brak surowca : « 🔍 Szukaj » (surowce i receptury), wpisz cenę szacunkową albo <a href="${at.root}/materials/new" target="_blank" rel="noopener">dodaj surowiec</a>
                    i <button type="button" class="dc-link dc-inline" data-reload>odśwież listę</button>.</p></div>
            <div class="dc-card"><h4 class="dc-h"><span class="dc-num">2</span>Cobot</h4>
                <span class="dc-label">Czy produkt jest cobotyzowalny ?</span>
                <div class="dc-seg dc-seg-sm" role="radiogroup" aria-label="Cobot">${[['yes', 'Tak'], ['no', 'Nie'], ['check', 'Do sprawdzenia']].map(([v, l]) =>
                    `<label><input type="radio" name="cobot" value="${v}" ${C.cobot === v ? 'checked' : ''}><span>${l}</span></label>`).join('')}</div>
                <div data-cobot-steps ${C.cobot === 'yes' ? '' : 'hidden'}><span class="dc-label dc-mt">Które etapy może robić cobot ?</span>
                    <div class="dc-chips">${C.step_choices.map(s => `<label class="dc-chip"><input type="checkbox" name="cobot_steps" value="${esc(s)}" ${C.cobot_steps.includes(s) ? 'checked' : ''}><span>${esc(s)}</span></label>`).join('')}</div></div>
                <p class="dc-hint">Widoczne przy produkcie. Stanowisko z cobotem i kadencję ustawisz po teście (Ustawienia › Stanowiska robocze i coboty).</p></div>
            <div class="dc-nav"><button type="button" class="btn btn-outline-secondary dc-big" data-back-card>‹ Karta</button></div>
        </div>
        <aside><div class="dc-card dc-sticky"><h4 class="dc-h"><i class="bi bi-calculator"></i> Cena</h4>
            <div class="dc-tiles">
                <div class="dc-tile"><small>Koszt surowców / szt.</small><b data-k="pc"></b><span data-k="pcEur"></span></div>
                <div class="dc-tile"><small>Cena fabryczna / szt. = koszt × współczynnik</small>
                    <div class="dc-xrow">${C.factory.coefs.map(v => `<button type="button" class="dc-coef" data-coef="${v}">${num(v, 1)}</button>`).join('')}</div>
                    <b data-k="f"></b><span data-k="fPln"></span></div>
                <div class="dc-tile"><small>Cena w sklepie netto / szt. = fabryka × mnożnik</small>
                    <div class="dc-xrow">${C.store.mults.map(v => `<button type="button" class="dc-coef" data-mult="${v}">× ${num(v, v % 1 ? 1 : 0)}</button>`).join('')}</div>
                    <b data-k="s"></b></div>
                <div class="dc-tile dc-tile-hl"><small>Cena końcowa w sklepie (zaokrąglona, do zmiany)</small>
                    <span class="dc-final"><input class="form-control" data-final inputmode="decimal" aria-label="Cena końcowa w sklepie (€)"><b>€</b></span>
                    <span class="dc-pill" data-verdict></span></div>
            </div>
            <div class="dc-kpis"><span class="dc-pill" data-k="shareF"></span><span class="dc-pill" data-k="shareS"></span></div>
            <p class="dc-hint">Współczynniki i mnożniki : ${S.ai.is_admin ? `<a href="${at.root}/other-settings#new-product-params">Ustawienia › Pozostałe › Ceny nowych produktów</a>` : 'Ustawienia › Pozostałe › Ceny nowych produktów'}
                · kurs EUR ${C.rate ? num(C.rate) : '— (nieustawiony)'} · sprzedawalność : sklep ÷ fabryka ≥ ${num(C.sellability.multiplier, 1)}.</p>
            <div class="dc-act">${saved ? `<button type="button" class="btn btn-primary dc-big" data-save-cost><i class="bi bi-check-lg"></i> Zapisz koszt i cenę</button>
                    <a class="btn btn-outline-primary dc-big" href="${at.root}/test-products/${c.product_id}/tablet"><i class="bi bi-box-arrow-up-right"></i> Otwórz produkt</a>`
                : `<p class="dc-label dc-m0">Zapisz produkt i kartę :</p>
                    <div class="dc-choice"><button type="button" class="btn btn-primary dc-big" data-finish="tablet"><i class="bi bi-tablet"></i> Wyślij na tablet ${esc(c.group || '')}</button>
                    <button type="button" class="btn btn-outline-primary dc-big" data-finish="print"><i class="bi bi-printer"></i> Drukuj kartę</button></div>
                    <p class="dc-hint dc-m0">Koszt i ceny zapisują się razem z produktem (Nowe produkty → Pomysł).</p>`}</div>
        </div></aside></div>`;
        bindCost();
        refreshCost();
    }

    function refreshCost() {
        const t = totals();
        view.querySelectorAll('[data-line]').forEach(tr => { tr.querySelector('[data-cost]').textContent = money(lineCost(C.lines[Number(tr.dataset.line)]), 'zł'); });
        const done = C.lines.filter(l => ['auto', 'ok', 'free'].includes(l.status)).length;
        const auto = C.lines.filter(l => l.status === 'auto').length;
        view.querySelector('[data-progress]').style.width = (C.lines.length ? done / C.lines.length * 100 : 0) + '%';
        view.querySelector('[data-progress-label]').textContent = `${done} z ${C.lines.length} gotowe` + (auto ? ` · ${auto} automatycznie` : '');
        view.querySelector('[data-total]').textContent = money(t.total, 'zł');
        view.querySelector('[data-pc]').textContent = t.pc !== null ? money(t.pc, 'zł') : 'podaj liczbę sztuk';
        const k = (name, v) => { const el = view.querySelector(`[data-k="${name}"]`); if (el) el.textContent = v; };
        k('pc', t.pc !== null ? money(t.pc, 'zł') : '—');
        k('pcEur', t.pcEur !== null ? money(t.pcEur, '€') : '');
        k('f', money(t.fEur, '€'));
        k('fPln', t.fPln !== null ? money(t.fPln, 'zł') : '');
        k('s', money(t.sEur, '€'));
        k('shareF', `Surowce ${num(t.shareF, 0)} % ceny fabrycznej`);
        k('shareS', t.shareS !== null ? `${num(t.shareS, 1)} % ceny w sklepie` : '— % ceny w sklepie');
        view.querySelectorAll('[data-coef]').forEach(b => b.classList.toggle('on', Number(b.dataset.coef) === Number(C.factory.coef)));
        view.querySelectorAll('[data-mult]').forEach(b => b.classList.toggle('on', Number(b.dataset.mult) === Number(C.store.mult)));
        const fin = view.querySelector('[data-final]');
        if (document.activeElement !== fin) fin.value = t.final !== null ? num(t.final) : '';
        const v = view.querySelector('[data-verdict]');
        v.className = 'dc-pill ' + ({ ok: 'ok', warn: 'w', bad: 'bad' }[t.verdict] || '');
        v.textContent = t.ratio === null ? 'sprzedawalność : —' : `${t.verdict === 'ok' ? '✓' : (t.verdict === 'warn' ? 'na granicy' : 'za drogo')} × ${num(t.ratio)}`;
        v.title = `Sklep ÷ fabryka ; cel ≥ ${num(C.sellability.multiplier, 1)}, na granicy od ${num(C.sellability.limit, 1)}`;
    }

    // Tabela : zdarzenia delegowane (wiersz przerysowany po zmianie surowca nie potrzebuje nowych nasłuchów).
    function bindCost() {
        const touch = () => { dirty = true; refreshCost(); };
        const tbody = view.querySelector('.dc-map tbody');
        const lineOf = (el) => Number(el.closest('tr').dataset.line);
        const redraw = (i) => { view.querySelector(`[data-line="${i}"]`).outerHTML = lineRow(i); touch(); };
        tbody.addEventListener('change', (e) => {
            const el = e.target;
            if (el.matches('[data-mat]')) {
                const i = lineOf(el);
                const l = C.lines[i];
                const choose = (v) => {
                    if (v === 'free') Object.assign(l, { ref: null, status: 'free', note: null });
                    else if (v === '') Object.assign(l, { ref: null, status: l.price_kg !== null ? 'estimate' : 'missing', note: null });
                    else if (C.byRef[v]) {
                        // Inna jednostka : g ↔ kg, ml ↔ l przeliczone ; na sztuki (albo ze sztuk) — ilość do sprawdzenia.
                        const BASE = { g: 1, ml: 1, kg: 1000, l: 1000 };
                        const from = l.ref && C.byRef[l.ref] ? C.byRef[l.ref].unit : 'g';
                        const to = C.byRef[v].unit;
                        let note = null;
                        if (from !== to && BASE[from] && BASE[to]) l.qty = Math.round(l.qty * BASE[from] / BASE[to] * 1000) / 1000;
                        else if (from !== to) note = `Sprawdź ilość : jednostka ${to}`;
                        Object.assign(l, { ref: v, status: 'ok', note });
                    }
                    redraw(i);
                };
                if (el.value === 'search') {
                    el.value = l.ref || (l.status === 'free' ? 'free' : ''); // bez wyboru w wyszukiwarce nic się nie zmienia
                    searchPick(i, choose);
                } else choose(el.value);
            } else if (el.matches('[data-qty]')) {
                // Ilość wpisana : linia sprawdzona, uwaga « Podaj ilość » znika.
                const i = lineOf(el);
                const l = C.lines[i];
                const was = [l.status, l.note];
                if (l.qty > 0 && /^Podaj/.test(l.note || '')) l.note = null;
                if (l.ref && l.status === 'check') l.status = 'ok';
                if (was[0] !== l.status || was[1] !== l.note) redraw(i);
            }
        });
        tbody.addEventListener('input', (e) => {
            const el = e.target;
            if (el.matches('[data-qty]')) {
                C.lines[lineOf(el)].qty = Math.max(0, parseNum(el.value) ?? 0);
                touch();
            } else if (el.matches('[data-pkg]')) {
                const tr = el.closest('tr');
                const l = C.lines[lineOf(el)];
                l.price_kg = parseNum(el.value);
                l.status = l.price_kg !== null ? 'estimate' : 'missing';
                tr.className = 'st-' + l.status;
                const [cls, label] = ST[l.status];
                const st = tr.querySelector('[data-st]');
                st.className = 'dc-st ' + cls;
                st.textContent = label;
                touch();
            }
        });
        tbody.addEventListener('click', (e) => {
            const b = e.target.closest('[data-st]');
            if (!b) return;
            const i = lineOf(b);
            const l = C.lines[i];
            if (['check', 'auto'].includes(l.status) && l.ref) { l.status = 'ok'; redraw(i); }
        });
        view.querySelector('[name=yield_pcs]').addEventListener('input', (e) => { const n = parseInt(String(e.target.value).replace(/\D/g, ''), 10); C.yield_pcs = n > 0 ? n : null; touch(); });
        view.querySelectorAll('[name=cobot]').forEach(r => r.addEventListener('change', () => {
            C.cobot = r.value;
            view.querySelector('[data-cobot-steps]').hidden = C.cobot !== 'yes';
            dirty = true;
        }));
        view.querySelectorAll('[name=cobot_steps]').forEach(cb => cb.addEventListener('change', () => {
            C.cobot_steps = [...view.querySelectorAll('[name=cobot_steps]:checked')].map(x => x.value);
            dirty = true;
        }));
        view.querySelectorAll('[data-coef]').forEach(b => b.addEventListener('click', () => { C.factory.coef = Number(b.dataset.coef); C.store.manual = false; touch(); }));
        view.querySelectorAll('[data-mult]').forEach(b => b.addEventListener('click', () => { C.store.mult = Number(b.dataset.mult); C.store.manual = false; touch(); }));
        const fin = view.querySelector('[data-final]');
        fin.addEventListener('input', () => { C.store.final_eur = parseNum(fin.value); C.store.manual = C.store.final_eur !== null; touch(); });
        fin.addEventListener('blur', refreshCost);
        view.querySelector('[data-back-card]').addEventListener('click', async () => {
            if (dirty && !(await saveCost(true))) return;
            const res = await api(`/ajax/dev-cards/${S.card.id}`, 'GET');
            if (res.ok) S.card = res.data;
            history.replaceState(null, '', `${at.root}/test-products/new/${S.card.id}`);
            renderResult();
        });
        view.querySelector('[data-reload]').addEventListener('click', async () => {
            if (dirty && !(await saveCost(true))) return;
            openCost();
        });
        view.querySelector('[data-save-cost]')?.addEventListener('click', async (e) => {
            const btn = e.currentTarget;
            btn.disabled = true;
            if (await saveCost(false)) renderCost();
            else btn.disabled = false;
        });
        view.querySelectorAll('[data-finish]').forEach(b => b.addEventListener('click', () => finish(b.dataset.finish)));
    }

    function costPayload() {
        const t = totals();
        const id = (l, k) => l.ref && l.ref[0] === k ? Number(l.ref.slice(1)) : null;
        return { lines: C.lines.map(l => ({ key: l.key, id_raw_material: id(l, 'm'), id_recipe: id(l, 'r'), qty: l.qty, price_kg: l.price_kg, status: l.status })),
            yield_pcs: C.yield_pcs, factory_coef: C.factory.coef, store_mult: C.store.mult, store_price_eur: t.final,
            cobot: C.cobot || null, cobot_steps: C.cobot === 'yes' ? C.cobot_steps : [] };
    }

    async function saveCost(quiet) {
        const res = await api(`/ajax/dev-cards/${S.card.id}/cost`, 'PUT', costPayload());
        if (!res.ok) {
            const f = Object.values(res.fields || {});
            at.notify(false, (res.message || 'Błąd') + (f.length ? ' ' + f.join(' ') : ''));
            return false;
        }
        setCost(res.data);
        if (!quiet) at.notify(true, 'Zapisano koszt i cenę.');
        return true;
    }

    // Zapis kroku 4 + produktu : « tablet » albo « print » (jak w kroku 3 dawniej).
    async function finish(mode) {
        const btns = view.querySelectorAll('[data-finish]');
        btns.forEach(b => { b.disabled = true; });
        try {
            if (!(await saveCost(true))) return;
            const res = await api(`/ajax/dev-cards/${S.card.id}/save`, 'POST', { tablet: mode === 'tablet' });
            if (!res.ok) return at.notify(false, res.message);
            S.card = res.data;
            if (mode === 'print') {
                at.notify(true, 'Zapisano — karta otwiera się do druku (produkt bez tabletu).');
                printCard();
            } else at.notify(true, `Zapisano — produkt jest na tablecie grupy ${S.card.group || ''}.`);
            renderCost();
        } finally {
            btns.forEach(b => { b.disabled = false; });
        }
    }

    // --- Pomocnicze ------------------------------------------------------------------------------------

    function secs(t0) { return Math.round((Date.now() - t0) / 1000) + ' s'; }
    function dmy(s) { const m = String(s || '').match(/^(\d{4})-(\d{2})-(\d{2})/); return m ? `${m[3]}.${m[2]}.${m[1]}` : ''; }
    function clock(ms) { const s = Math.floor(ms / 1000); return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`; }

    // Start : nowa karta albo szkic / karta z adresu (/test-products/new/{id}, krok 4 : #koszt).
    if (S.card && S.card.card && location.hash === '#koszt') openCost();
    else if (S.card) renderResult(); else renderForm();
})();
