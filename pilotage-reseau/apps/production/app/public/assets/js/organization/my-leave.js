/*
 * [289] Mój urlop (makieta 50) : formularz (rodzaj, od–do, saldo, zastępca z listy — zajęci w tych dniach wyszarzeni),
 * moje urlopy (status, zmiana zastępcy, anuluj), dla CEO / administratora : wnioski do zatwierdzenia (Zatwierdź / Odrzuć)
 * i tabela « kto kogo zastępuje ».
 */
(function () {
    'use strict';
    const app = document.getElementById('leaveApp');
    if (!app) return;
    const root = app.dataset.root;
    const body = app.querySelector('[data-ul-body]');
    let D = JSON.parse(document.getElementById('leaveBoot').textContent);
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const dm = (d) => d ? `${d.slice(8, 10)}.${d.slice(5, 7)}` : '—';
    const full = (d) => d ? `${dm(d)}.${d.slice(0, 4)}` : '—';
    const addDays = (d, n) => { const x = new Date(d + 'T12:00:00'); x.setDate(x.getDate() + n); return x.toISOString().slice(0, 10); };
    const off = new Set(D.holidays || []);
    const workdays = (a, b) => { if (!a || !b || b < a) return 0; let n = 0; for (let d = a; d <= b; d = addDays(d, 1)) { const w = new Date(d + 'T12:00:00').getDay(); if (w && w !== 6 && !off.has(d)) n++; } return n; };
    let form = {type: 'urlop', from: D.today, to: D.today, sub: '', note: ''};

    async function call(method, url, payload) {
        const r = await fetch(root + url, {method, credentials: 'same-origin', headers: {'Accept': 'application/json', 'Content-Type': 'application/json'}, body: payload ? JSON.stringify(payload) : undefined});
        const j = await r.json().catch(() => ({message: 'Błąd serwera (' + r.status + ').'}));
        if (!r.ok) throw new Error(j.message || 'Błąd serwera.');
        return j;
    }
    async function reload(msg) { D = (await call('GET', '/ajax/my-leave')).data; render(msg); }
    const busy = (c) => c.busy.some(b => b.from <= form.to && b.to >= form.from);
    const tag = (s) => `<span class="ul-tag is-${esc(s.status)}">${esc(s.status_label)}</span>`;

    function render(msg) {
        const b = D.balance;
        const n = workdays(form.from, form.to);
        const counts = form.type !== 'inna';
        const opts = D.candidates.map(c => `<option value="${c.id}" ${String(c.id) === String(form.sub) ? 'selected' : ''} ${busy(c) ? 'disabled' : ''}>${esc(c.position)} — ${esc(c.name)}${busy(c) ? ' (urlop w tych dniach)' : ''}</option>`).join('');
        const subName = D.candidates.find(c => String(c.id) === String(form.sub));
        const deleg = D.delegations.length ? `<div class="ul-banner"><i class="bi bi-person-check"></i> <b>Zastępujesz dziś :</b> ${D.delegations.map(x => `${esc(x.position || '')} — ${esc(x.name)} (urlop do ${dm(x.to)})`).join(' · ')}. Ich zadania są w <a href="${root}/today#zadania">Dzisiaj i zadania</a>.</div>` : '';
        const pending = D.can_decide ? `<div class="ul-card" id="wnioski"><h5>Do zatwierdzenia (CEO) <span class="ul-count">${D.pending.length}</span></h5>
            ${D.pending.length ? D.pending.map(p => `<div class="ul-row"><div><b>${esc(p.name)}</b>${p.position ? ' · ' + esc(p.position) : ''}<small>${esc(p.type_label)} · ${esc(p.span)} · ${p.days} dni roboczych · zastępca : ${esc(p.substitute ? p.substitute_position ? p.substitute_position + ' — ' + p.substitute : p.substitute : 'brak')}${p.note ? ' · « ' + esc(p.note) + ' »' : ''}</small></div>
                <span class="ul-sp"></span><input class="form-control form-control-sm ul-reason" data-reason="${p.id}" placeholder="powód odrzucenia" maxlength="255">
                <button type="button" class="btn btn-sm btn-outline-danger" data-reject="${p.id}">Odrzuć</button><button type="button" class="btn btn-sm btn-primary" data-approve="${p.id}">Zatwierdź</button></div>`).join('') : '<p class="ul-mute">Nic nie czeka.</p>'}</div>` : '';
        const board = D.can_decide ? `<div class="ul-card"><h5>Kto kogo zastępuje (zatwierdzone urlopy)</h5>
            ${D.board.length ? `<table class="table ul-table"><thead><tr><th>Osoba (stanowisko)</th><th>Urlop</th><th>Zastępca</th></tr></thead><tbody>${D.board.map(r => `<tr><td>${esc(r.name)}${r.position ? ' (' + esc(r.position) + ')' : ''}</td><td class="ul-num">${esc(r.span)}</td>
                <td>${subSelect(r)}</td></tr>`).join('')}</tbody></table>` : '<p class="ul-mute">Brak zatwierdzonych urlopów.</p>'}
            <p class="ul-mute">Zmiana zastępcy działa od razu — bez zatwierdzenia. Bez zastępcy zadania czekają (albo zastępstwo stałe stanowiska z « Zadania stanowisk »).</p></div>` : '';
        const mine = D.mine.length ? `<table class="table ul-table"><thead><tr><th>Urlop</th><th>Rodzaj</th><th class="ul-num">Dni</th><th>Zastępca</th><th>Status</th><th></th></tr></thead><tbody>${D.mine.map(m => `<tr>
                <td class="ul-num">${esc(m.span)}</td><td>${esc(m.type_label)}</td><td class="ul-num">${m.days}</td>
                <td>${['pending', 'approved'].includes(m.status) ? subSelect(m) : esc(m.substitute || '—')}</td>
                <td>${tag(m)}${m.decision_note ? `<small class="d-block ul-mute">${esc(m.decision_note)}</small>` : ''}</td>
                <td class="text-end">${['pending', 'approved'].includes(m.status) && m.from > D.today ? `<button type="button" class="btn btn-sm btn-link text-danger" data-cancel="${m.id}">Anuluj</button>` : ''}</td></tr>`).join('')}</tbody></table>` : '<p class="ul-mute">Brak urlopów w tym roku.</p>';
        body.innerHTML = `${msg ? `<div class="ul-msg">${esc(msg)}</div>` : ''}${deleg}
            <div class="ul-grid"><div class="ul-card"><h5>Dodaj urlop</h5><p class="ul-mute">zostało ${b.remaining} z ${b.right} dni (${b.year})</p>
                <div class="tm-chips">${Object.entries(D.types).map(([k, v]) => `<label class="tm-chip"><input type="radio" name="ul-type" value="${k}" ${form.type === k ? 'checked' : ''}><span>${esc(v)}</span></label>`).join('')}</div>
                <div class="ul-two"><label>Od<input type="date" class="form-control" data-f="from" value="${form.from}" min="${D.today}"></label><label>Do<input type="date" class="form-control" data-f="to" value="${form.to}" min="${form.from}"></label></div>
                <div class="tm-bal"><div><small>Przysługuje</small><b>${b.right}</b></div><div><small>Wzięte</small><b>${b.used}</b></div><div><small>Ten urlop</small><b>${n}</b></div><div class="${counts && b.remaining - n < 0 ? 'is-bad' : ''}"><small>Zostanie</small><b>${counts ? b.remaining - n : b.remaining}</b></div></div>
                <label class="ul-label">Zastępstwo — kto przejmuje moje obowiązki</label>
                <select class="form-select" data-f="sub"><option value="">— bez zastępcy —</option>${opts}</select>
                ${subName ? `<div class="ul-note">W dni urlopu Twoje zadania (także obowiązkowe) przejmie ${esc(subName.position)} — ${esc(subName.name)}. Wracają do Ciebie po urlopie.</div>` : '<div class="ul-note is-warn">Bez zastępcy zadania będą czekać na Ciebie.</div>'}
                <label class="ul-label">Uwaga<input class="form-control" data-f="note" maxlength="255" value="${esc(form.note)}"></label>
                <div class="ul-actions"><span class="ul-err" data-err></span><button type="button" class="btn btn-primary" data-send ${n ? '' : 'disabled'}>Wyślij do zatwierdzenia</button></div></div>
              <div class="ul-card"><h5>Moje urlopy</h5>${mine}</div></div>
            ${pending}${board}`;
    }

    function subSelect(r) {
        const opts = D.candidates.filter(c => c.id !== r.user_id).map(c => {
            const taken = c.busy.some(x => x.from <= r.to && x.to >= r.from);
            return `<option value="${c.id}" ${c.id === r.substitute_id ? 'selected' : ''} ${taken && c.id !== r.substitute_id ? 'disabled' : ''}>${esc(c.position)} — ${esc(c.name)}</option>`;
        }).join('');
        return `<select class="form-select form-select-sm ul-sub" data-sub="${r.id}"><option value="">— brak —</option>${opts}</select>`;
    }

    body.addEventListener('change', async (e) => {
        const t = e.target;
        if (t.name === 'ul-type') { form.type = t.value; render(); }
        if (t.dataset.f === 'from') { form.from = t.value; if (form.to < form.from) form.to = form.from; render(); }
        if (t.dataset.f === 'to') { form.to = t.value; render(); }
        if (t.dataset.f === 'sub') { form.sub = t.value; render(); }
        if (t.dataset.f === 'note') form.note = t.value;
        if (t.dataset.sub) {
            try { const r = await call('PUT', `/ajax/my-leave/${t.dataset.sub}/substitute`, {substitute_id: t.value ? Number(t.value) : null}); await reload(r.message); }
            catch (x) { alert(x.message); await reload(); }
        }
    });
    body.addEventListener('click', async (e) => {
        const b = e.target.closest('button');
        if (!b) return;
        try {
            if (b.matches('[data-send]')) {
                form.note = (body.querySelector('[data-f="note"]') || {}).value || '';
                b.disabled = true;
                const r = await call('POST', '/ajax/my-leave', {type: form.type, from: form.from, to: form.to, substitute_id: form.sub ? Number(form.sub) : null, note: form.note});
                form = {type: 'urlop', from: D.today, to: D.today, sub: '', note: ''};
                await reload(r.message);
            }
            if (b.matches('[data-approve]')) { b.disabled = true; await reload((await call('POST', `/ajax/my-leave/${b.dataset.approve}/approve`)).message); }
            if (b.matches('[data-reject]')) {
                const reason = body.querySelector(`[data-reason="${b.dataset.reject}"]`).value.trim();
                if (reason.length < 3) throw new Error('Podaj powód odrzucenia.');
                await reload((await call('POST', `/ajax/my-leave/${b.dataset.reject}/reject`, {reason})).message);
            }
            if (b.matches('[data-cancel]') && confirm('Anulować urlop ?')) await reload((await call('DELETE', `/ajax/my-leave/${b.dataset.cancel}`)).message);
        } catch (x) {
            const err = body.querySelector('[data-err]');
            if (b.matches('[data-send]') && err) { err.textContent = x.message; b.disabled = false; } else alert(x.message);
        }
    });
    render();
    if (location.hash === '#wnioski') { const w = document.getElementById('wnioski'); if (w) w.scrollIntoView(); }
})();
