// [353] Procedura asystowana (makieta 69 B) : wstęp → etapy jeden po drugim (zdjęcie, polecenie, potwierdzenie : odhaczenie,
// wartość w zakresie, zdjęcie przy wykonaniu ; bez potwierdzenia nie ma « Dalej ») → podsumowanie i zapis wykonania.
// W panelu bocznym (embed) : po zapisie wiadomość « sop-done » do strony, panel zamyka się sam.
(() => {
    const app = document.getElementById('sopRun');
    const boot = document.getElementById('sopRunBoot');
    if (!app || !boot) return;
    const B = JSON.parse(boot.textContent);
    const S = B.sop;
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const num = (v) => { const n = Number(String(v ?? '').replace(',', '.').replace(/\s/g, '')); return String(v ?? '').trim() !== '' && Number.isFinite(n) ? n : null; };
    const fmt = (v) => v === null || v === undefined ? '' : String(v).replace('.', ',');
    const TAGS = {bhp: 'BHP', haccp: 'HACCP', quality: 'Jakość'};
    const res = S.steps.map(s => ({step_id: s.id, done: false, value: '', photo: null}));
    let at0 = new Date();
    let cur = -1; // -1 wstęp, S.steps.length podsumowanie
    let saved = null;

    const inRange = (s, v) => v !== null && (s.value_min === null || v >= s.value_min) && (s.value_max === null || v <= s.value_max);
    const confirmed = (i) => {
        const s = S.steps[i], r = res[i];
        if (s.confirm === 'check') return r.done;
        if (s.confirm === 'photo') return !!r.photo;
        return num(r.value) !== null;
    };
    const range = (s) => (s.value_min !== null ? 'od ' + fmt(s.value_min) + ' ' : '') + (s.value_max !== null ? 'do ' + fmt(s.value_max) + ' ' : '') + (s.value_unit || '');

    async function shot(file) {
        const bmp = await createImageBitmap(file);
        const scale = Math.min(1, 1600 / Math.max(bmp.width, bmp.height));
        const c = document.createElement('canvas');
        c.width = Math.round(bmp.width * scale); c.height = Math.round(bmp.height * scale);
        c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
        return c.toDataURL('image/jpeg', 0.8);
    }

    function dots() {
        return `<div class="sr-dots">${S.steps.map((s, i) => `<i class="${i === cur ? 'c' : (confirmed(i) ? (s.confirm === 'value' && !inRange(s, num(res[i].value)) ? 'x' : 'd') : '')}"></i>`).join('')}</div>`;
    }

    function intro() {
        const info = [['Cel', S.purpose], ['Obszar', S.area], ['Kto', S.performer], ['Środki ochrony', S.ppe]].filter(x => x[1]);
        return `<div class="sr-body" style="grid-template-columns:1fr"><div class="sr-intro">
            <h4 style="margin:0">${esc(S.title)}</h4><small class="text-muted">${esc(S.code)} · ${S.version ? 'v' + S.version : 'szkic'} · ${S.steps.length} ${S.steps.length === 1 ? 'etap' : 'etapów'}</small>
            ${info.length ? `<dl>${info.map(([k, v]) => `<dt>${esc(k)}</dt><dd>${esc(v)}</dd>`).join('')}</dl>` : ''}
            ${S.status === 'draft' ? '<p class="text-muted small">Szkic — procedura jeszcze nie zatwierdzona.</p>' : ''}
            <ol class="sr-sum">${S.steps.map(s => `<li>${esc(s.title)}${s.tag ? ` <span class="sr-tag sr-${s.tag}">${TAGS[s.tag]}</span>` : ''}</li>`).join('')}</ol>
            ${B.runs && B.runs.length ? `<p class="text-muted small">Ostatnio : ${esc(B.runs[0].finished_at.slice(0, 16).replace('T', ' '))}${B.runs[0].user ? ' · ' + esc(B.runs[0].user) : ''} · ${B.runs[0].result === 'ok' ? 'OK' : 'odchylenie'}</p>` : ''}
        </div></div>`;
    }

    function stepView(i) {
        const s = S.steps[i], r = res[i];
        let c = '';
        if (s.confirm === 'check') c = `<div class="sr-check ${r.done ? 'on' : ''}" data-check role="checkbox" aria-checked="${r.done}" tabindex="0"><i class="bi ${r.done ? 'bi-check-circle-fill' : 'bi-circle'}"></i> Zrobione</div>`;
        if (s.confirm === 'value') {
            const v = num(r.value), ok = inRange(s, v);
            c = `<div style="font-weight:600;margin-bottom:6px">Wartość — wymagane ${esc(range(s))}</div><div class="sr-val ${v === null ? '' : (ok ? 'ok' : 'bad')}"><input inputmode="decimal" data-value value="${esc(r.value)}" aria-label="Wartość"> <span>${esc(s.value_unit || '')}</span>
                ${v === null ? '' : (ok ? '<b style="color:#1f6b33">✓ w zakresie</b>' : '<b style="color:#9b1c1c">poza zakresem — niezgodność</b>')}</div>`;
        }
        if (s.confirm === 'photo') c = `<label class="sr-btn alt" style="display:inline-flex;gap:8px;cursor:pointer"><i class="bi bi-camera"></i> ${r.photo ? 'Inne zdjęcie' : 'Zrób zdjęcie'}<input type="file" accept="image/*" capture="environment" data-photo hidden></label>
            ${r.photo ? `<div class="sr-shot"><img src="${r.photo}" alt=""></div>` : ''}`;
        return `<div class="sr-body"><div class="sr-ph">${s.photo_id ? `<img src="${at.root}/procedure/photo/${s.photo_id}" alt="">` : '<span><i class="bi bi-image"></i> bez zdjęcia</span>'}</div>
            <div><div><span class="sr-no">${i + 1}</span>${s.tag ? `<span class="sr-tag sr-${s.tag}">${TAGS[s.tag]}</span>` : ''}${s.minutes ? `<span class="sr-tag sr-min">⏱ ${s.minutes} min</span>` : ''}</div>
                <div class="sr-title">${esc(s.title)}</div>${s.body ? `<div class="sr-text">${esc(s.body)}</div>` : ''}<div class="sr-confirm">${c}</div></div></div>`;
    }

    function summary() {
        if (saved) return `<div class="sr-body" style="grid-template-columns:1fr"><div>${saved.result === 'ok'
            ? '<h4 style="color:#1f6b33"><i class="bi bi-check-circle-fill"></i> Procedura wykonana — zapisano</h4>'
            : `<h4 style="color:#9b1c1c"><i class="bi bi-exclamation-triangle-fill"></i> Zapisano z odchyleniem</h4><p>${saved.deviations.map(esc).join('<br>')}</p><p class="text-muted">Niezgodność utworzona w rejestrze NC.</p>`}</div></div>`;
        const bad = S.steps.map((s, i) => [s, i]).filter(([s, i]) => s.confirm === 'value' && !inRange(s, num(res[i].value)));
        return `<div class="sr-body" style="grid-template-columns:1fr"><div><h4>Podsumowanie</h4>
            <ol class="sr-sum">${S.steps.map((s, i) => `<li>${esc(s.title)} — ${s.confirm === 'value' ? `<b>${esc(fmt(num(res[i].value)))} ${esc(s.value_unit || '')}</b> ${inRange(s, num(res[i].value)) ? '✓' : '<span style="color:#9b1c1c">poza zakresem</span>'}` : (s.confirm === 'photo' ? 'zdjęcie ✓' : '✓')}</li>`).join('')}</ol>
            ${bad.length ? `<p style="color:#9b1c1c">${bad.length} ${bad.length === 1 ? 'wartość' : 'wartości'} poza zakresem : zapis utworzy niezgodność.</p>` : ''}</div></div>`;
    }

    function render() {
        const n = S.steps.length;
        const pct = cur < 0 ? 0 : Math.round(Math.min(cur, n) / n * 100);
        let main, foot;
        if (cur < 0) {
            main = intro();
            foot = `<span>${B.can_edit && !B.embed ? `<a class="sr-btn alt" href="${at.root}/sop/${S.id}/edit">Edytuj</a> ` : ''}<a class="sr-btn alt" href="${at.root}/procedure/${S.id}/pdf" target="_blank" rel="noopener">PDF</a></span>
                <button type="button" class="sr-btn" data-start ${n ? '' : 'disabled'}>Rozpocznij ›</button>`;
        } else if (cur < n) {
            main = stepView(cur);
            foot = `${dots()}<span style="display:flex;gap:8px"><button type="button" class="sr-btn alt" data-prev>‹ Wstecz</button><button type="button" class="sr-btn" data-next ${confirmed(cur) ? '' : 'disabled'}>${cur === n - 1 ? 'Podsumowanie ›' : 'Następny etap ›'}</button></span>`;
        } else {
            main = summary();
            foot = saved ? `<span></span><a class="sr-btn alt" href="${B.from ? at.root + B.from : at.root + '/today'}" ${B.embed ? 'target="_top"' : ''}>Wróć</a>`
                : `${dots()}<span style="display:flex;gap:8px"><button type="button" class="sr-btn alt" data-prev>‹ Wstecz</button><button type="button" class="sr-btn" data-save>Zapisz wykonanie</button></span>`;
        }
        app.innerHTML = `<div class="sr-card"><div class="sr-top"><div><b>${esc(S.title)}</b><br><small>${esc(S.code)} ${S.version ? 'v' + S.version : 'szkic'}${S.area ? ' · ' + esc(S.area) : ''}</small></div>
            ${cur >= 0 && cur < n ? `<div style="display:flex;gap:10px;align-items:center"><div class="sr-pb"><i style="width:${pct}%"></i></div><b>Etap ${cur + 1} z ${n}</b></div>` : ''}</div>${main}<div class="sr-foot">${foot}</div></div>`;
        app.querySelector('[data-value]')?.focus();
    }

    app.addEventListener('click', async (e) => {
        const t = e.target.closest('[data-start],[data-next],[data-prev],[data-save],[data-check]');
        if (!t) return;
        if (t.matches('[data-start]')) { at0 = new Date(); cur = 0; render(); return; }
        if (t.matches('[data-next]')) { cur++; render(); return; }
        if (t.matches('[data-prev]')) { cur = Math.max(-1, cur - 1); render(); return; }
        if (t.matches('[data-check]')) { res[cur].done = !res[cur].done; render(); return; }
        if (t.matches('[data-save]')) {
            t.disabled = true;
            const p = new Date(at0.getTime() - at0.getTimezoneOffset() * 60000).toISOString().slice(0, 19).replace('T', ' ');
            const r = await at.send(`/ajax/procedure/${S.id}/runs`, 'POST', {started_at: p, source_path: B.from, steps: res});
            at.notify(!!r.ok, r.message || (r.ok ? 'Zapisano.' : 'Błąd zapisu.'));
            if (!r.ok) { t.disabled = false; return; }
            saved = r.data;
            render();
            if (B.embed && window.parent !== window) window.parent.postMessage('sop-done', location.origin);
        }
    });
    app.addEventListener('keydown', (e) => { if (e.target.matches('[data-check]') && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); res[cur].done = !res[cur].done; render(); } });
    app.addEventListener('input', (e) => {
        if (!e.target.matches('[data-value]')) return;
        res[cur].value = e.target.value;
        const s = S.steps[cur], v = num(e.target.value), box = e.target.closest('.sr-val');
        box.classList.toggle('ok', v !== null && inRange(s, v));
        box.classList.toggle('bad', v !== null && !inRange(s, v));
        const b = box.querySelector('b');
        if (b) b.remove();
        if (v !== null) box.insertAdjacentHTML('beforeend', inRange(s, v) ? '<b style="color:#1f6b33">✓ w zakresie</b>' : '<b style="color:#9b1c1c">poza zakresem — niezgodność</b>');
        app.querySelector('[data-next]')?.toggleAttribute('disabled', v === null);
        app.querySelectorAll('.sr-dots i')[cur]?.classList.toggle('x', v !== null && !inRange(s, v));
    });
    app.addEventListener('change', async (e) => {
        if (!e.target.matches('[data-photo]') || !e.target.files[0]) return;
        res[cur].photo = await shot(e.target.files[0]);
        render();
    });
    render();
})();
