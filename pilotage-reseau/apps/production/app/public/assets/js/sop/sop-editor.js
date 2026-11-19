// [353] Asystent procedury SOP (makieta 69 · 2–3) : 1 opis, 2 etapy (zdjęcie z aparatu / pliku albo zrzut ekranu wklejony
// Ctrl+V, polecenie, opis, BHP / HACCP / jakość, czas, potwierdzenie : odhaczenie, wartość w zakresie, zdjęcie przy wykonaniu),
// 3 powiązanie (przycisk « Procedura » na stronach / asystentach ERP), 4 zatwierdź (wersja, przegląd, PDF, wykonanie próbne).
(() => {
    const app = document.getElementById('sopEditor');
    const boot = document.getElementById('sopBoot');
    if (!app || !boot || !window.tw) return;
    const B = JSON.parse(boot.textContent);
    const esc = tw.esc;
    const STEPS = ['Opis', 'Etapy', 'Powiązanie', 'Zatwierdź'];
    const blank = () => ({title: '', body: '', tag: '', minutes: '', confirm: 'check', value_min: '', value_max: '', value_unit: '', photo_id: null});
    let S = B.sop ? JSON.parse(JSON.stringify(B.sop)) : {id: null, code: null, title: B.preset?.title || '', purpose: '', area: '', performer: '', ppe: '', status: 'draft',
        version: 0, changed: true, review_months: 12, steps: [blank()], links: B.preset?.link ? [B.preset.link] : [], prefix: 'PROD'};
    if (!S.steps.length) S.steps = [blank()];
    let step = Number((location.hash.match(/step=(\d)/) || [])[1]) || 1;
    let active = 0;
    let dirty = false;

    // --- Zdjęcia : zmniejszenie w przeglądarce (dłuższy bok 1600 px) ; zrzut ekranu PNG zostaje PNG, gdy nie za duży. -----------
    async function toDataUrl(file) {
        const bmp = await createImageBitmap(file);
        const scale = Math.min(1, 1600 / Math.max(bmp.width, bmp.height));
        const c = document.createElement('canvas');
        c.width = Math.round(bmp.width * scale); c.height = Math.round(bmp.height * scale);
        const ctx = c.getContext('2d');
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
        ctx.drawImage(bmp, 0, 0, c.width, c.height);
        if (file.type === 'image/png') { const png = c.toDataURL('image/png'); if (png.length < 3_000_000) return png; }
        return c.toDataURL('image/jpeg', 0.85);
    }
    async function addPhoto(i, file) {
        if (!file || !file.type.startsWith('image/')) { at.notify(false, 'To nie jest obraz.'); return; }
        const res = await tw.post('/ajax/sop/photos', {photo: await toDataUrl(file)});
        if (!res.ok) return;
        S.steps[i].photo_id = res.data.id;
        dirty = true;
        at.notify(true, `Etap ${i + 1} : zdjęcie dodane.`);
        render();
    }
    document.addEventListener('paste', (e) => {
        if (step !== 2) return;
        const item = [...(e.clipboardData?.items || [])].find(x => x.type.startsWith('image/'));
        if (!item) return;
        e.preventDefault();
        addPhoto(active, item.getAsFile());
    });

    const photoUrl = (id) => `${at.root}/procedure/photo/${id}`;
    const field = (label, html, cls = '') => `<div class="tw-field ${cls}"><label>${esc(label)}</label>${html}</div>`;
    const input = (k, v, attrs = '') => `<input class="form-control" data-f="${k}" value="${esc(v ?? '')}" ${attrs}>`;

    function stepCard(s, i) {
        return `<div class="se-step ${i === active ? 'on' : ''}" data-i="${i}">
            <div><span class="se-no">${i + 1}</span><div class="se-move"><button type="button" data-up title="Wyżej">↑</button><button type="button" data-down title="Niżej">↓</button></div></div>
            <div><div class="se-ph">${s.photo_id ? `<img src="${photoUrl(s.photo_id)}" alt=""><button type="button" class="x" data-nophoto>usuń</button>` : 'Zdjęcie albo zrzut ekranu<br><b>Ctrl+V</b> wkleja tutaj'}</div>
                <div class="se-phb"><label class="tw-btn ghost sm"><i class="bi bi-camera"></i> Aparat / plik<input type="file" accept="image/*" data-file hidden></label></div></div>
            <div><input class="form-control mb-2" data-s="title" placeholder="Polecenie : co zrobić (np. Otwórz asystenta « Ceny z faktur »)" value="${esc(s.title)}" maxlength="200">
                <textarea class="form-control" data-s="body" placeholder="Szczegóły, na co uważać…" maxlength="4000">${esc(s.body || '')}</textarea></div>
            <div class="se-side">
                <label>Oznaczenie <select class="form-select form-select-sm" data-s="tag"><option value="">—</option>${[['bhp', 'BHP'], ['haccp', 'HACCP'], ['quality', 'Jakość']].map(([k, l]) => `<option value="${k}" ${s.tag === k ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
                <label>Czas etapu (min) <input class="form-control form-control-sm" data-s="minutes" inputmode="numeric" value="${esc(s.minutes ?? '')}"></label>
                <label>Potwierdzenie <select class="form-select form-select-sm" data-s="confirm">${[['check', 'odhaczenie'], ['value', 'wartość w zakresie'], ['photo', 'zdjęcie przy wykonaniu']].map(([k, l]) => `<option value="${k}" ${s.confirm === k ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
                ${s.confirm === 'value' ? `<div class="row2"><input class="form-control form-control-sm" data-s="value_min" placeholder="min" value="${esc(s.value_min ?? '')}"><input class="form-control form-control-sm" data-s="value_max" placeholder="maks" value="${esc(s.value_max ?? '')}"><input class="form-control form-control-sm" data-s="value_unit" placeholder="°C" value="${esc(s.value_unit ?? '')}"></div><small class="text-muted">poza zakresem : niezgodność w rejestrze NC</small>` : ''}
                <button type="button" class="tw-link" data-del style="color:#9b1c1c;justify-content:flex-start"><i class="bi bi-trash"></i> Usuń etap</button>
            </div></div>`;
    }

    function body() {
        if (step === 1) return `<p class="tw-lead">${S.code ? `<b>${esc(S.code)}</b> · ` : ''}Co, gdzie i kto. Kod nadaje się sam (prefiks działu).</p><div class="se-grid">
            ${!S.id ? field('Dział (prefiks kodu)', `<select class="form-select" data-f="prefix">${Object.entries(B.prefixes).map(([k, l]) => `<option value="${k}" ${S.prefix === k ? 'selected' : ''}>SOP-${k} · ${esc(l)}</option>`).join('')}</select>`) : ''}
            ${field('Tytuł procedury', input('title', S.title, 'maxlength="200" placeholder="np. Mycie i dezynfekcja miksera spiralnego"'), S.id ? 'wide' : '')}
            ${field('Cel (po co)', `<textarea class="form-control" data-f="purpose" maxlength="4000">${esc(S.purpose || '')}</textarea>`, 'wide')}
            ${field('Obszar (grupa, stanowisko, maszyna)', input('area', S.area, 'maxlength="160"'))}
            ${field('Kto wykonuje', input('performer', S.performer, 'maxlength="160"'))}
            ${field('Środki ochrony indywidualnej', input('ppe', S.ppe, 'maxlength="255" placeholder="rękawice, okulary…"'))}
            ${field('Przegląd co (miesiące)', input('review_months', S.review_months, 'inputmode="numeric"'))}</div>`;
        if (step === 2) return `<p class="tw-lead">Jedno polecenie na etap. Zdjęcie : aparat (tablet / telefon), plik albo <b>zrzut ekranu wklejony Ctrl+V</b> do zaznaczonego etapu (ramka).</p>
            ${S.steps.map(stepCard).join('')}<button type="button" class="tw-btn ghost" data-add><i class="bi bi-plus-lg"></i> Dodaj etap</button>`;
        if (step === 3) {
            const pages = Object.entries(B.pages);
            const other = S.links.filter(l => !B.pages[l]);
            return `<p class="tw-lead">Gdzie pokazać przycisk « Procedura » (procedura asystowana) : asystenci i strony ERP.</p>
                <div class="se-links">${pages.map(([p, n]) => tw.check('link:' + p, S.links.includes(p), n, p)).join('')}</div>
                <div class="tw-field" style="max-width:520px;margin-top:12px"><label>Inne strony (ścieżka, np. /workshop/receiving — po jednej w linii)</label>
                <textarea class="form-control" data-f="other_links">${esc(other.join('\n'))}</textarea></div>
                <p class="tw-note">Zadanie z procedurą : w Uprawnienia i menu › Plan tygodnia stanowiska, link zadania = /procedure/${S.id ?? '…'}.</p>`;
        }
        const photos = S.steps.filter(s => s.photo_id).length;
        return `<div class="se-sum"><div><span>Etapy</span><b>${S.steps.length}</b></div><div><span>Ze zdjęciem</span><b>${photos} / ${S.steps.length}</b></div>
                <div><span>Przycisk na</span><b>${S.links.length}</b></div><div><span>Wersja</span><b>${S.version ? 'v' + S.version : '—'}</b><small>${S.status === 'active' ? (S.changed ? 'zmiany do zatwierdzenia' : 'obowiązuje') : 'szkic'}</small></div></div>
            ${photos < S.steps.length ? tw.alert(`${S.steps.length - photos} ${tw.plural(S.steps.length - photos, 'etap', 'etapy', 'etapów')} bez zdjęcia — zrzut ekranu ułatwia wykonanie.`, 'warn', 'bi-camera') : ''}
            <p class="tw-lead">Zatwierdzenie (Kierownik produkcji) : nowa wersja obowiązuje, przegląd za ${S.review_months} mies., zadanie « SOP dnia » zaliczone.</p>
            ${S.id ? `<p><a class="tw-btn ghost" href="${at.root}/procedure/${S.id}" target="_blank" rel="noopener"><i class="bi bi-play-circle"></i> Wykonanie próbne</a>
                <a class="tw-btn ghost" href="${at.root}/procedure/${S.id}/pdf" target="_blank" rel="noopener"><i class="bi bi-filetype-pdf"></i> PDF A4</a>
                ${S.status !== 'archived' ? `<button type="button" class="tw-link" data-archive style="color:#9b1c1c"><i class="bi bi-archive"></i> Do archiwum</button>` : ''}</p>` : ''}`;
    }

    function collect() {
        app.querySelectorAll('[data-f]').forEach(el => {
            const k = el.dataset.f;
            if (k === 'other_links') return;
            S[k] = el.value;
        });
        app.querySelectorAll('.se-step').forEach(card => {
            const s = S.steps[Number(card.dataset.i)];
            card.querySelectorAll('[data-s]').forEach(el => { s[el.dataset.s] = el.value; });
        });
        if (step === 3) {
            const picked = [...app.querySelectorAll('[data-tw-ck^="link:"]')].filter(i => i.checked).map(i => i.dataset.twCk.slice(5));
            const other = (app.querySelector('[data-f="other_links"]')?.value || '').split('\n').map(x => x.trim()).filter(Boolean);
            S.links = [...new Set([...picked, ...other])];
        }
    }

    async function save() {
        collect();
        const res = await tw.post(S.id ? `/ajax/sop/${S.id}` : '/ajax/sop', {title: S.title, purpose: S.purpose, area: S.area, performer: S.performer, ppe: S.ppe,
            review_months: S.review_months, prefix: S.prefix, steps: S.steps, links: S.links}, S.id ? 'PUT' : 'POST');
        if (!res.ok) return false;
        const fresh = res.data;
        if (!S.id) history.replaceState(null, '', `${at.root}/sop/${fresh.id}/edit#step=${step}`);
        S = {...fresh, prefix: S.prefix};
        if (!S.steps.length) S.steps = [blank()];
        dirty = false;
        return true;
    }

    function next() {
        if (step < 4) return {label: step === 3 ? 'Zapisz i dalej' : 'Dalej', attr: 'data-next'};
        return {label: S.status === 'active' && !S.changed ? 'Obowiązuje — brak zmian' : `Zatwierdź wersję v${S.version + 1}`, attr: 'data-approve', final: true,
            disabled: S.status === 'active' && !S.changed};
    }

    function render() {
        app.innerHTML = tw.frame({title: S.id ? `${S.code} · ${S.title}` : 'Nowa procedura (SOP)', sub: `krok ${step} z 4 · ${S.status === 'active' ? 'obowiązuje v' + S.version : 'szkic'}`,
            steps: STEPS, step, reached: S.id ? 4 : step, body: body(), prev: step > 1 ? 'Wstecz' : null, next: next(), later: false,
            extra: `<button type="button" class="tw-link" data-save><i class="bi bi-save"></i> Zapisz szkic</button><a class="tw-link" href="${at.root}/sop">Lista procedur</a>`});
        app.querySelector('.tw-back')?.setAttribute('href', `${at.root}/sop`);
        if (app.querySelector('.tw-back')) app.querySelector('.tw-back').innerHTML = '<i class="bi bi-arrow-left"></i> Procedury';
    }

    async function go(n) {
        collect();
        if (n !== step && (S.id || n > step)) {
            if (!S.title.trim()) { at.notify(false, 'Podaj tytuł procedury.'); step = 1; render(); return; }
            if (!(await save())) return;
        }
        step = Math.max(1, Math.min(4, n));
        location.hash = 'step=' + step;
        render();
    }

    tw.bind(app, (n) => go(n === -1 ? step - 1 : n));
    app.addEventListener('click', async (e) => {
        const card = e.target.closest('.se-step');
        if (card && Number(card.dataset.i) !== active) { collect(); active = Number(card.dataset.i); app.querySelectorAll('.se-step').forEach(c => c.classList.toggle('on', Number(c.dataset.i) === active)); }
        const t = e.target.closest('button, a');
        if (!t) return;
        const i = card ? Number(card.dataset.i) : null;
        if (t.matches('[data-next]')) { go(step + 1); return; }
        if (t.matches('[data-save]')) { if (await save()) { at.notify(true, 'Szkic zapisany.'); render(); } return; }
        if (t.matches('[data-add]')) { collect(); S.steps.push(blank()); active = S.steps.length - 1; dirty = true; render(); return; }
        if (t.matches('[data-del]') && i !== null) { collect(); if (S.steps.length > 1 && !window.confirm(`Usunąć etap ${i + 1} ?`)) return; S.steps.splice(i, 1); if (!S.steps.length) S.steps = [blank()]; active = Math.max(0, Math.min(active, S.steps.length - 1)); dirty = true; render(); return; }
        if (t.matches('[data-up]') && i > 0) { collect(); [S.steps[i - 1], S.steps[i]] = [S.steps[i], S.steps[i - 1]]; active = i - 1; dirty = true; render(); return; }
        if (t.matches('[data-down]') && i !== null && i < S.steps.length - 1) { collect(); [S.steps[i + 1], S.steps[i]] = [S.steps[i], S.steps[i + 1]]; active = i + 1; dirty = true; render(); return; }
        if (t.matches('[data-nophoto]') && i !== null) { collect(); S.steps[i].photo_id = null; dirty = true; render(); return; }
        if (t.matches('[data-approve]')) {
            t.disabled = true;
            if (!(await save())) { t.disabled = false; return; }
            const res = await tw.post(`/ajax/sop/${S.id}/approve`, {});
            if (res.ok) { S = res.data; at.notify(true, res.message); render(); } else t.disabled = false;
            return;
        }
        if (t.matches('[data-archive]')) {
            if (!window.confirm('Przenieść procedurę do archiwum ? Przycisk « Procedura » zniknie ze stron.')) return;
            const res = await tw.post(`/ajax/sop/${S.id}/archive`, {});
            if (res.ok) location.href = `${at.root}/sop`;
        }
    });
    app.addEventListener('change', (e) => {
        dirty = true;
        const f = e.target.closest('[data-file]');
        if (f) { const card = f.closest('.se-step'); collect(); active = Number(card.dataset.i); addPhoto(active, f.files[0]); return; }
        if (e.target.matches('[data-s="confirm"]')) { collect(); render(); }
    });
    window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
    render();
})();
