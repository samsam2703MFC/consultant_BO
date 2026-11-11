/*
 * Czas pracy [262] (makiety 1–4, 11.11.2026) — App\Services\Workshop\Time\TimeService liczy wszystko, strona pokazuje
 * i wysyła decyzje :
 *   Dzień      : odbicia dnia, plan, różnica ; powód nadgodzin / nieobecności (lista), wyjście do uzupełnienia,
 *                odbicie ręczne, zatwierdzenie dnia (zadanie nr 1 kierownika produkcji) ;
 *   Miesiąc    : wszyscy × dni (godziny, nadgodziny, U / L4 / WN, ?), sumy, bank, karty A4 (PDF), zamknięcie miesiąca ;
 *                [266] e-maile pracowników i karta miesiąca e-mailem (PDF w załączniku, kolejka wysyłki) ;
 *   Urlopy     : kalendarz zespołu (paski), pozostały urlop (26 dni), dodaj / usuń wpis, wymiar urlopu osoby ;
 *   Nadgodziny : bank (FIFO, odbiór w 3 miesiące), dzień wolny : propozycja → podpis pracownika (tu albo tablet biurowy).
 */
(function () {
    'use strict';
    const app = document.getElementById('timeApp');
    if (!app) return;
    const root = app.dataset.root;
    const tab = app.dataset.tab;
    const isAdmin = app.dataset.admin === '1';
    const body = app.querySelector('[data-tm-body]');
    const dialog = document.querySelector('[data-tm-dialog]');
    const form = dialog.querySelector('[data-tm-form]');
    let D = JSON.parse(document.getElementById('timeBoot').textContent);
    let showAll = false;

    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const hm = (m, sign) => {
        if (m === null || m === undefined) return '—';
        const s = m < 0 ? '−' : (sign && m > 0 ? '+' : '');
        const a = Math.abs(m);
        return `${s}${Math.floor(a / 60)}:${String(a % 60).padStart(2, '0')}`;
    };
    const dm = (d) => d ? `${d.slice(8, 10)}.${d.slice(5, 7)}` : '—';
    const full = (d) => d ? `${dm(d)}.${d.slice(0, 4)}` : '—';
    const time = (ts) => ts ? ts.slice(11, 16) : '—';
    const pl = (n, one, few, many) => n === 1 ? one : ([2, 3, 4].includes(n % 10) && ![12, 13, 14].includes(n % 100) ? few : many);
    const addDays = (d, n) => { const x = new Date(d + 'T12:00:00'); x.setDate(x.getDate() + n); return x.toISOString().slice(0, 10); };
    const parseHm = (v) => { const m = String(v || '').trim().match(/^(\d{1,2})(?::(\d{2}))?$/); return m ? Number(m[1]) * 60 + Number(m[2] || 0) : null; };

    async function call(method, url, payload) {
        const res = await fetch(root + url, {method, credentials: 'same-origin',
            headers: {'Accept': 'application/json', 'Content-Type': 'application/json'}, body: payload ? JSON.stringify(payload) : undefined});
        const data = await res.json().catch(() => ({message: 'Błąd serwera (' + res.status + ').'}));
        if (!res.ok) throw new Error(data.message || 'Błąd serwera.');
        return data;
    }
    const fail = (err) => window.Swal ? Swal.fire({icon: 'error', title: 'Nie zapisano', text: err.message}) : alert(err.message);
    const ok = (msg) => { if (msg && window.Swal) Swal.fire({icon: 'success', title: msg, timer: 1700, showConfirmButton: false}); };
    const confirmBox = async (text) => window.Swal ? (await Swal.fire({icon: 'question', text, showCancelButton: true, confirmButtonText: 'Tak', cancelButtonText: 'Nie'})).isConfirmed : confirm(text);

    /** Zapis, potem odświeżenie danych zakładki (odpowiedź z danymi albo ponowne pobranie). */
    async function act(btn, method, url, payload, reload) {
        if (btn) btn.disabled = true;
        app.classList.add('tm-busy');
        try {
            const res = await call(method, url, payload);
            if (res.data && reload !== 'fetch' && tab === 'day' && res.data.people) D = res.data;
            else if (res.data && tab === 'month' && res.data.days) D = res.data;
            else await refresh();
            render();
            ok(res.message);
            return true;
        } catch (e) { fail(e); return false; } finally { app.classList.remove('tm-busy'); if (btn) btn.disabled = false; }
    }

    async function refresh() {
        const url = tab === 'day' ? `/ajax/time/day?date=${D.day}` : tab === 'month' ? `/ajax/time/month?month=${D.month}`
            : tab === 'leave' ? `/ajax/time/leave?from=${D.from}&days=${D.days.length}` : '/ajax/time/overtime';
        D = (await call('GET', url)).data;
    }

    // --- Okno dialogowe (formularz) -----------------------------------------------------------------------------
    function openDialog(title, html, onSubmit, submitLabel, extra) {
        form.innerHTML = `<header class="tm-dialog-head"><h4 id="tmDialogTitle">${esc(title)}</h4>
                <button type="button" class="btn-close" data-close aria-label="Zamknij"></button></header>
            <div class="tm-dialog-body">${html}</div><p class="tm-dialog-error" data-error></p>
            <footer class="tm-dialog-foot">${extra || ''}<button type="button" class="btn btn-light" data-close>Anuluj</button>
                ${submitLabel ? `<button type="submit" class="btn btn-primary" data-submit>${esc(submitLabel)}</button>` : ''}</footer>`;
        form.querySelectorAll('[data-close]').forEach(b => b.addEventListener('click', () => dialog.close()));
        form.onsubmit = async (e) => {
            e.preventDefault();
            const btn = e.submitter && e.submitter.matches('[data-alt]') ? e.submitter : form.querySelector('[data-submit]');
            const err = form.querySelector('[data-error]');
            err.textContent = '';
            if (btn) btn.disabled = true;
            try {
                const done = await onSubmit(btn ? btn.dataset.alt || 'main' : 'main');
                if (done !== false) dialog.close();
            } catch (x) { err.textContent = x.message; } finally { if (btn) btn.disabled = false; }
        };
        dialog.showModal();
        const first = form.querySelector('input:not([type=hidden]), select, textarea');
        if (first) first.focus();
    }

    // Ramka podpisu palcem / myszą (biały PNG), jak na tablecie biurowym.
    function signaturePad(canvas) {
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.lineWidth = 2.6; ctx.lineCap = 'round'; ctx.strokeStyle = '#111';
        let drawing = false;
        let strokes = 0;
        const point = (e) => { const r = canvas.getBoundingClientRect(); return [(e.clientX - r.left) * canvas.width / r.width, (e.clientY - r.top) * canvas.height / r.height]; };
        canvas.addEventListener('pointerdown', (e) => { drawing = true; strokes++; canvas.setPointerCapture(e.pointerId); const [x, y] = point(e); ctx.beginPath(); ctx.moveTo(x, y); });
        canvas.addEventListener('pointermove', (e) => { if (!drawing) return; const [x, y] = point(e); ctx.lineTo(x, y); ctx.stroke(); });
        canvas.addEventListener('pointerup', () => { drawing = false; });
        return {empty: () => strokes === 0, clear: () => { ctx.fillRect(0, 0, canvas.width, canvas.height); strokes = 0; }, image: () => canvas.toDataURL('image/png')};
    }

    // =====================================================================================================
    // Makieta 1 · Dzień
    // =====================================================================================================
    function renderDay() {
        const c = D.counts;
        const nav = `<div class="tm-bar">
            <div class="tm-nav"><a class="btn btn-light btn-sm" href="?date=${addDays(D.day, -1)}" aria-label="Poprzedni dzień">‹</a>
                <label class="tm-date"><span>${esc(D.label)}</span><input type="date" value="${D.day}" data-goto-day aria-label="Wybierz dzień"></label>
                <a class="btn btn-light btn-sm" href="?date=${addDays(D.day, 1)}" aria-label="Następny dzień">›</a>
                ${D.day !== D.today ? `<a class="btn btn-link btn-sm" href="?date=${D.today}">Dzisiaj</a>` : ''}</div>
            ${D.validated ? `<span class="at-pill at-pill-ok"><i class="bi bi-check2-circle"></i> zatwierdzony ${esc(D.validated.by || '')} ${dm(D.validated.at)} ${time(D.validated.at)}</span>`
                : D.future ? '<span class="at-pill at-pill-muted">dzień jeszcze nie minął</span>' : '<span class="at-pill at-pill-warn">do zatwierdzenia</span>'}
            ${D.holiday ? `<span class="at-pill at-pill-muted">${esc(D.holiday)}</span>` : ''}
            <span class="tm-prev">${esc(D.previous.label)} : ${D.previous.validated ? `zatwierdzony ${esc(D.previous.validated.by || '')} ${dm(D.previous.validated.at)} ${time(D.previous.validated.at)}`
                : `<a href="?date=${D.previous.day}"><b>do zatwierdzenia</b></a>`}</span>
        </div>`;
        const kpis = `<div class="tm-kpis">
            <div class="tm-kpi is-ok"><small>Obecni</small><b>${c.present} / ${c.total}</b><span>odbicie wejścia i wyjścia</span></div>
            <div class="tm-kpi ${c.open ? 'is-danger' : ''}"><small>Bez wyjścia</small><b>${c.open}</b><span>${c.open ? 'uzupełnij godzinę wyjścia' : 'wszystkie odbicia zamknięte'}</span></div>
            <div class="tm-kpi ${c.need_overtime ? 'is-warn' : ''}"><small>Nadgodziny do uzasadnienia</small><b>${c.need_overtime}</b><span>${hm(c.overtime, true)} razem</span></div>
            <div class="tm-kpi ${c.need_absence ? 'is-danger' : ''}"><small>Nieobecni</small><b>${c.absent}</b><span>${c.leave} w kalendarzu · ${c.need_absence} bez powodu</span></div>
        </div>`;
        const noClock = D.people.filter(p => p.status === 'need_absence');
        const bulk = noClock.length >= 2 && !D.month_closed ? `<div class="tm-bulk"><span><b>${noClock.length}</b> ${pl(noClock.length, 'osoba', 'osoby', 'osób')} bez odbicia i bez powodu — jeden powód dla wszystkich :</span>
            <select class="form-select form-select-sm" data-bulk-reason aria-label="Powód nieobecności dla wszystkich bez odbicia"><option value="">— wybierz —</option>
            ${Object.entries(D.reasons.absence).map(([code, label]) => `<option value="${code}">${esc(label)}</option>`).join('')}</select></div>` : '';
        const problems = D.people.filter(p => p.status !== 'ok' || p.overtime > 0 || p.absence || p.absence_reason || p.diff !== null && Math.abs(p.diff) > D.rules.tolerance);
        const quiet = D.people.filter(p => !problems.includes(p));
        const rows = (showAll ? D.people : problems).map(dayRow).join('');
        const table = D.people.length ? `<div class="tm-card"><table class="table tm-table">
            <thead><tr><th>Pracownik</th><th>Grupa</th><th class="text-center">Wejście</th><th class="text-center">Wyjście</th><th class="text-end">Godziny</th>
                <th class="text-end">Plan</th><th class="text-end">Różnica</th><th class="tm-col-reason">Powód nadgodzin / nieobecności</th><th>Stan</th></tr></thead>
            <tbody>${rows || ''}
            ${quiet.length && !showAll ? `<tr class="tm-quiet"><td colspan="9"><button type="button" class="btn btn-link btn-sm" data-show-all>+ ${quiet.length} ${pl(quiet.length, 'osoba', 'osoby', 'osób')} bez uwag (${hm(D.rules.day_plan)} ± ${D.rules.tolerance} min) — pokaż</button></td></tr>` : ''}
            ${showAll && quiet.length ? `<tr class="tm-quiet"><td colspan="9"><button type="button" class="btn btn-link btn-sm" data-show-all>Ukryj osoby bez uwag</button></td></tr>` : ''}
            </tbody></table></div>`
            : `<div class="tm-card"><p class="at-empty">${D.workday ? 'Brak osób z planem na ten dzień.' : 'Dzień wolny (weekend albo święto) — brak odbić.'}</p></div>`;
        const left = [];
        if (c.open) left.push(`${c.open} ${pl(c.open, 'wyjście', 'wyjścia', 'wyjść')} do uzupełnienia`);
        if (c.need_overtime) left.push(`${c.need_overtime} ${pl(c.need_overtime, 'powód nadgodzin', 'powody nadgodzin', 'powodów nadgodzin')}`);
        if (c.need_absence) left.push(`${c.need_absence} ${pl(c.need_absence, 'powód nieobecności', 'powody nieobecności', 'powodów nieobecności')}`);
        const foot = `<div class="tm-foot">
            <span class="tm-foot-msg">${D.month_closed ? 'Miesiąc zamknięty — zmiany niemożliwe.' : D.validated ? 'Dzień zatwierdzony : jest w karcie miesiąca i w banku nadgodzin. Zmiana odbicia albo powodu cofa zatwierdzenie.'
                : left.length ? `Zostało ${D.blocking} : ${left.join(', ')}. Zatwierdzony dzień trafia do karty miesiąca i do banku nadgodzin.` : D.future ? 'Zatwierdzenie po zakończeniu dnia.' : 'Wszystko sprawdzone — możesz zatwierdzić dzień.'}</span>
            <button type="button" class="btn btn-light" data-print><i class="bi bi-printer"></i> Drukuj listę dnia</button>
            <button type="button" class="btn btn-light" data-add-clock><i class="bi bi-plus-lg"></i> Odbicie ręczne</button>
            ${D.validated ? (D.month_closed ? '' : `<button type="button" class="btn btn-outline-secondary" data-reopen>Cofnij zatwierdzenie</button>`)
                : `<button type="button" class="btn btn-primary" data-validate ${D.can_validate ? '' : 'disabled'}><i class="bi bi-check2"></i> Zatwierdź dzień ${dm(D.day)}</button>`}
        </div>`;
        body.innerHTML = nav + kpis + bulk + table + foot;
    }

    function dayRow(p) {
        const lock = D.month_closed;
        const clocks = p.clocks;
        const inCell = clocks.length ? clocks.map(k => `<button type="button" class="tm-time" data-edit-clock="${k.id}" ${lock ? 'disabled' : ''} title="Popraw odbicie${k.source === 'manual' ? ' (wpis ręczny)' : ''}">${time(k.in)}${k.source === 'manual' ? '<sup>R</sup>' : ''}${k.corrected ? '<sup>K</sup>' : ''}</button>`).join('<br>') : '—';
        const outCell = clocks.length ? clocks.map(k => k.out ? `<button type="button" class="tm-time" data-edit-clock="${k.id}" ${lock ? 'disabled' : ''}>${k.out.slice(0, 10) !== D.day ? dm(k.out) + ' ' : ''}${time(k.out)}</button>`
            : `<button type="button" class="tm-fix" data-close-clock="${k.id}" ${lock ? 'disabled' : ''}>Dodaj wyjście…</button>`).join('<br>') : '—';
        let reason = '';
        if (p.open) {
            reason = `<span class="tm-sub">w pracy od ${time(p.clocks.find(k => !k.out).in)}${p.running !== null ? ` (${hm(p.running)})` : ''} — korekta z powodem, w dzienniku</span>`;
        } else if (p.overtime > 0) {
            reason = reasonSelect('overtime', p, p.overtime_reason, D.reasons.overtime, p.week_extra ? `ponad ${D.rules.week_plan / 60} h w tygodniu : ${hm(p.week_extra)}` : '');
        } else if (p.absence) {
            reason = `<span>${esc(p.absence.label)}${p.absence.type === 'wn' ? ` (${hm(p.absence.minutes)})` : ''}</span> <span class="tm-sub">kalendarz</span>`;
        } else if (p.plan > 0 && !p.clocks.length) {
            reason = reasonSelect('absence', p, p.absence_reason, D.reasons.absence, '') + (lock ? '' : ` <button type="button" class="btn btn-link btn-sm p-0" data-add-clock="${p.employee_id}">zapomniane odbicie…</button>`);
        } else if (p.diff !== null && p.diff < -D.rules.tolerance) {
            reason = `<span class="tm-sub">krócej o ${hm(-p.diff)}</span>`;
        }
        const st = {open: '<span class="at-pill at-pill-danger">Brak wyjścia</span>', need_overtime: '<span class="at-pill at-pill-warn">Powód ?</span>',
            need_absence: '<span class="at-pill at-pill-danger">Powód ?</span>', ok: '<span class="at-pill at-pill-ok">OK</span>'}[p.status];
        const comp = p.comp_proposed.length ? `<span class="tm-sub"><i class="bi bi-pen"></i> dzień wolny ${dm(p.comp_proposed[0].day)} do podpisu</span>` : '';
        return `<tr class="${p.status !== 'ok' ? 'tm-need' : ''}" data-row="${p.employee_id}">
            <td><b>${esc(p.name)}</b>${comp}${p.took ? `<span class="tm-sub tm-took" title="Odpowiedź przy wyjściu (tablet / telefon)"><i class="bi bi-bag"></i> zabiera : ${esc(p.took)}</span>` : ''}</td>
            <td>${p.group ? `<span class="tm-group" style="--g:${esc(p.group_color || '#B8B2A8')}">${esc(p.group)}</span>` : '—'}</td>
            <td class="text-center">${inCell}</td><td class="text-center">${outCell}</td>
            <td class="text-end tm-num">${p.open ? '—' : (p.clocks.length ? hm(p.worked) : '—')}</td>
            <td class="text-end tm-num">${p.plan ? hm(p.plan) : '—'}</td>
            <td class="text-end tm-num ${p.overtime > 0 ? 'tm-ot' : ''}">${p.diff === null ? '—' : hm(p.diff, true)}</td>
            <td class="tm-col-reason">${reason}</td><td>${st}</td></tr>`;
    }

    function reasonSelect(kind, p, current, options, hint) {
        const lock = D.month_closed;
        const opts = Object.entries(options).map(([code, label]) => `<option value="${code}" ${current && current.code === code ? 'selected' : ''}>${esc(label)}</option>`).join('');
        const needNote = current && (current.code === 'inne' || current.code === 'inna');
        return `<div class="tm-reason"><select class="form-select form-select-sm ${current ? '' : 'tm-need-select'}" data-reason="${kind}" data-emp="${p.employee_id}" ${lock ? 'disabled' : ''}
                aria-label="${kind === 'overtime' ? 'Powód nadgodzin' : 'Powód nieobecności'} — ${esc(p.name)}">
            <option value="">${kind === 'overtime' ? '— wybierz powód —' : '— nieobecność —'}</option>${opts}</select>
            ${needNote ? `<input class="form-control form-control-sm" data-reason-note="${kind}" data-emp="${p.employee_id}" value="${esc(current.note || '')}" maxlength="255" placeholder="opis" ${lock ? 'disabled' : ''}>` : ''}
            ${hint ? `<span class="tm-sub">${esc(hint)}</span>` : ''}${current && current.note && !needNote ? `<span class="tm-sub">${esc(current.note)}</span>` : ''}</div>`;
    }

    async function saveBulk(reason) {
        const ids = D.people.filter(p => p.status === 'need_absence').map(p => p.employee_id);
        let note = null;
        if (reason === 'inna') {
            note = window.Swal ? (await Swal.fire({input: 'text', inputLabel: 'Inny powód — krótki opis', showCancelButton: true, confirmButtonText: 'Zapisz', cancelButtonText: 'Anuluj',
                inputValidator: (v) => v.trim() ? undefined : 'Wpisz opis.'})).value : prompt('Inny powód — krótki opis');
            if (!note) { render(); return; }
        }
        const label = D.reasons.absence[reason];
        const extra = ['urlop', 'na_zadanie', 'l4'].includes(reason) ? ' Wpisy trafią do kalendarza urlopów (liczą się do salda).' : '';
        if (!(await confirmBox(`« ${label} » dla ${ids.length} ${pl(ids.length, 'osoby', 'osób', 'osób')} bez odbicia ?${extra}`))) { render(); return; }
        await act(null, 'PUT', '/ajax/time/reasons', {kind: 'absence', employee_ids: ids, day: D.day, reason, note});
    }

    async function saveReason(kind, emp, reason, note) {
        if ((reason === 'inne' || reason === 'inna') && !note) {
            const text = window.Swal ? (await Swal.fire({input: 'text', inputLabel: 'Inny powód — krótki opis', showCancelButton: true, confirmButtonText: 'Zapisz', cancelButtonText: 'Anuluj',
                inputValidator: (v) => v.trim() ? undefined : 'Wpisz opis.'})).value : prompt('Inny powód — krótki opis');
            if (!text) { render(); return; }
            note = text;
        }
        if (['urlop', 'na_zadanie', 'l4'].includes(reason) && !(await confirmBox('Wpis trafi do kalendarza urlopów (liczy się do salda). Zapisać ?'))) { render(); return; }
        await act(null, 'PUT', '/ajax/time/reasons', {kind, employee_id: Number(emp), day: D.day, reason, note: note || null});
    }

    function clockDialog(clockId) {
        const p = D.people.find(x => x.clocks.some(k => k.id === clockId));
        const k = p.clocks.find(x => x.id === clockId);
        const outDefault = k.out ? k.out.slice(0, 16).replace(' ', 'T') : `${k.in.slice(0, 10)}T${String(Math.min(23, Number(k.in.slice(11, 13)) + 8)).padStart(2, '0')}:${k.in.slice(14, 16)}`;
        openDialog(`Odbicie — ${p.name}`, `<div class="tm-grid2">
                <label>Wejście<input type="datetime-local" class="form-control" name="in" value="${k.in.slice(0, 16).replace(' ', 'T')}" required></label>
                <label>Wyjście<input type="datetime-local" class="form-control" name="out" value="${outDefault}" ${k.out ? '' : 'autofocus'} required></label></div>
            <label>Powód korekty (w dzienniku)<input class="form-control" name="note" maxlength="255" required placeholder="np. zapomniane odbicie wyjścia" value="${k.out ? '' : 'Zapomniane odbicie wyjścia'}"></label>
            <p class="tm-sub">Korekta cofa zatwierdzenie dnia (do ponownego zatwierdzenia).</p>`,
            async () => {
                const f = new FormData(form);
                const okd = await act(null, 'PATCH', `/ajax/time/clocks/${clockId}`, {clock_in: f.get('in'), clock_out: f.get('out'), note: f.get('note')});
                return okd;
            }, 'Zapisz odbicie');
    }

    function addClockDialog(empId) {
        const people = D.people.slice().sort((a, b) => a.name.localeCompare(b.name, 'pl'));
        const sel = people.map(p => `<option value="${p.employee_id}" ${Number(empId) === p.employee_id ? 'selected' : ''}>${esc(p.name)}</option>`).join('');
        openDialog('Odbicie ręczne', `<label>Pracownik<select class="form-select" name="emp" required><option value="">—</option>${sel}</select></label>
            <div class="tm-grid2"><label>Wejście<input type="datetime-local" class="form-control" name="in" value="${D.day}T06:00" required></label>
                <label>Wyjście<input type="datetime-local" class="form-control" name="out" value="${D.day}T14:00" required></label></div>
            <label>Powód (w dzienniku)<input class="form-control" name="note" maxlength="255" required value="Zapomniane odbicie"></label>`,
            async () => {
                const f = new FormData(form);
                if (!f.get('emp')) throw new Error('Wybierz pracownika.');
                return act(null, 'POST', '/ajax/time/clocks', {employee_id: Number(f.get('emp')), clock_in: f.get('in'), clock_out: f.get('out'), note: f.get('note')});
            }, 'Dodaj odbicie');
    }

    // =====================================================================================================
    // Makieta 2 · Miesiąc
    // =====================================================================================================
    function renderMonth() {
        const days = D.days;
        const head = days.map(d => `<th class="${d.workday ? '' : 'tm-we'} ${d.validated ? 'tm-v' : ''}" title="${esc(d.holiday || '')}${d.validated ? ' zatwierdzony' : ''}">
            <a href="${root}/time?date=${d.date}">${d.n}<small>${d.dow}</small></a></th>`).join('');
        const rows = D.people.map(p => `<tr><td class="tm-name"><b>${esc(p.name)}</b><span class="tm-sub">${esc(p.group || '')}</span></td>
            ${days.map(d => monthCell(p.cells[d.date], d)).join('')}
            <td class="text-end tm-num"><b>${hm(p.worked)}</b></td><td class="text-end tm-num ${p.overtime ? 'tm-ot' : ''}">${p.overtime ? hm(p.overtime, true) : '0'}</td>
            <td class="text-end tm-num">${p.leave ? p.leave + ' d' : '0'}</td><td class="text-end tm-num">${hm(p.bank)}</td>
            <td class="tm-acts"><a class="btn btn-light btn-sm" href="${root}/time/sheet.pdf?month=${D.month}&employee=${p.employee_id}" target="_blank" rel="noopener">PDF</a>${mailCell(p)}</td></tr>`).join('');
        const t = D.totals;
        body.innerHTML = `<div class="tm-bar">
                <div class="tm-nav"><a class="btn btn-light btn-sm" href="?month=${D.prev}" aria-label="Poprzedni miesiąc">‹</a><span class="tm-date"><span>${esc(D.label)}</span></span>
                    <a class="btn btn-light btn-sm" href="?month=${D.next}" aria-label="Następny miesiąc">›</a></div>
                <span class="tm-prev">zatwierdzone dni : <b>${D.validated}</b> z ${D.to_validate}${D.pending.length ? ` · do zatwierdzenia : ${D.pending.slice(0, 6).map(d => `<a href="${root}/time?date=${d}">${dm(d)}</a>`).join(', ')}${D.pending.length > 6 ? '…' : ''}` : ''}</span>
                <span class="tm-spacer"></span>
                <button type="button" class="btn btn-light btn-sm" data-contacts><i class="bi bi-person-lines-fill"></i> E-maile pracowników</button>
                ${D.people.length ? `<button type="button" class="btn btn-light btn-sm" data-mail-all><i class="bi bi-envelope"></i> Wyślij karty e-mailem…</button>
                <a class="btn btn-light btn-sm" href="${root}/time/sheet.pdf?month=${D.month}" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf"></i> Drukuj wszystkich (PDF)</a>` : ''}
                ${D.closed ? `<span class="at-pill at-pill-ok">zamknięty ${dm(D.closed.at)} ${esc(D.closed.by || '')}</span>${isAdmin ? ' <button type="button" class="btn btn-link btn-sm" data-reopen-month>Otwórz ponownie</button>' : ''}`
                    : `<button type="button" class="btn btn-primary btn-sm" data-close-month ${D.can_close ? '' : 'disabled'} title="${D.can_close ? '' : 'Po ostatnim dniu miesiąca, gdy wszystkie dni są zatwierdzone'}">Zamknij miesiąc…</button>`}
            </div>
            <div class="tm-card tm-scroll">${D.people.length ? `<table class="table tm-mx"><thead><tr><th class="tm-name">Pracownik</th>${head}
                <th class="text-end">Godziny</th><th class="text-end">Nadg.</th><th class="text-end">Urlop</th><th class="text-end">Bank nadg.</th><th></th></tr></thead>
                <tbody>${rows}</tbody><tfoot><tr><td class="tm-name">Razem (${D.people.length} ${pl(D.people.length, 'osoba', 'osoby', 'osób')})</td>${days.map(() => '<td></td>').join('')}
                <td class="text-end tm-num">${hm(t.worked)}</td><td class="text-end tm-num">${hm(t.overtime, true)}</td><td class="text-end tm-num">${t.leave} d</td><td class="text-end tm-num">${hm(t.bank)}</td><td></td></tr></tfoot></table>`
                : '<p class="at-empty">Brak odbić i nieobecności w tym miesiącu.</p>'}</div>
            <div class="tm-legend"><span><i class="tm-ot">9:10</i> nadgodziny (tylko dni zatwierdzone liczą się do sumy i banku)</span><span><i class="tm-a-U">U</i> urlop</span><span><i class="tm-a-L4">L4</i> chorobowe</span>
                <span><i class="tm-a-WN">WN</i> wolne za nadgodziny</span><span><i class="tm-q">?</i> brak wyjścia / brak powodu</span><span><i class="tm-v-dot">3</i> dzień zatwierdzony</span></div>
            <p class="tm-note">« Zamknij miesiąc » : po ostatnim dniu, gdy wszystkie dni są zatwierdzone ; potem bez zmian (karty A4 do podpisu). Bank nadgodzin = nadgodziny jeszcze nieodebrane.</p>`;
    }

    // [266] Karta e-mailem : adres pracownika (zapis zaszyfrowany) i wysyłka karty miesiąca (PDF w załączniku).
    const mailOf = (id) => (D.mail && !Array.isArray(D.mail) ? D.mail[id] : null) || {email: false, sent: null};
    const sentLabel = (s) => !s ? '' : s.status === 'sent' ? `wysłano ${dm(s.at)}` : s.status === 'failed' ? 'błąd wysyłki' : `w kolejce ${dm(s.at)}`;
    function mailCell(p) {
        const m = mailOf(p.employee_id);
        if (!m.email) return ` <button type="button" class="btn btn-link btn-sm tm-nomail" data-contacts="${p.employee_id}" title="Brak adresu e-mail — dodaj">+ e-mail</button>`;
        return ` <button type="button" class="btn btn-light btn-sm ${m.sent ? (m.sent.status === 'failed' ? 'tm-mail-err' : 'tm-mail-ok') : ''}" data-mail-one="${p.employee_id}"
            title="${m.sent ? esc(sentLabel(m.sent)) + ' — wyślij ponownie' : 'Wyślij kartę e-mailem'}" aria-label="Wyślij kartę e-mailem : ${esc(p.name)}"><i class="bi bi-envelope${m.sent && m.sent.status !== 'failed' ? '-check' : ''}"></i></button>`;
    }

    async function contactsDialog(focusId) {
        let list;
        try { list = (await call('GET', `/ajax/time/contacts?month=${D.month}`)).data; } catch (e) { fail(e); return; }
        const rows = list.map(c => `<tr><td><b>${esc(c.name)}</b><span class="tm-sub">${esc(c.group || '')}</span></td>
            <td><input type="email" class="form-control form-control-sm" data-contact="${c.employee_id}" data-was="${esc(c.email || '')}" value="${esc(c.email || '')}"
                maxlength="190" autocomplete="off" inputmode="email" placeholder="imie.nazwisko@…" aria-label="E-mail : ${esc(c.name)}"></td>
            <td class="tm-sent">${esc(sentLabel(c.sent))}</td></tr>`).join('');
        openDialog('E-maile pracowników', `<p class="tm-note">Adres służy do wysyłki karty czasu pracy (PDF). Zapisany w bazie w postaci zaszyfrowanej. Puste pole = bez wysyłki.</p>
            <div class="tm-contacts"><table class="table tm-table"><thead><tr><th>Pracownik</th><th>E-mail</th><th>Karta ${esc(D.label)}</th></tr></thead><tbody>${rows}</tbody></table></div>`,
            async () => {
                const changed = [...form.querySelectorAll('[data-contact]')].filter(i => i.value.trim() !== i.dataset.was)
                    .map(i => ({employee_id: Number(i.dataset.contact), email: i.value.trim()}));
                if (!changed.length) return true;
                const res = await call('PUT', '/ajax/time/contacts', {month: D.month, contacts: changed});
                D.mail = res.data.mail;
                render();
                ok(res.message);
                return true;
            }, 'Zapisz adresy');
        dialog.classList.add('tm-dialog-wide');
        dialog.addEventListener('close', () => dialog.classList.remove('tm-dialog-wide'), {once: true});
        const first = focusId ? form.querySelector(`[data-contact="${focusId}"]`) : null;
        if (first) { first.focus(); first.scrollIntoView({block: 'center'}); }
    }

    async function mailSheets(btn, ids) {
        const withMail = D.people.filter(p => mailOf(p.employee_id).email);
        const one = ids ? D.people.find(p => p.employee_id === ids[0]) : null;
        const open = D.closed ? '' : ` Miesiąc nie jest zamknięty${D.pending.length ? ` (${D.pending.length} ${pl(D.pending.length, 'dzień', 'dni', 'dni')} do zatwierdzenia)` : ''} — karta pokaże stan na dziś.`;
        if (!ids && !withMail.length) { fail(new Error('Nikt z listy nie ma adresu e-mail — « E-maile pracowników ».')); return; }
        const text = one ? `Wysłać kartę za ${D.label} do : ${one.name} ?${open}`
            : `Wysłać karty za ${D.label} do ${withMail.length} ${pl(withMail.length, 'osoby', 'osób', 'osób')} z adresem e-mail${D.people.length > withMail.length ? ` (${D.people.length - withMail.length} bez adresu)` : ''} ?${open}`;
        if (!await confirmBox(text)) return;
        if (btn) btn.disabled = true;
        try {
            const res = await call('POST', `/ajax/time/months/${D.month}/mail`, ids ? {employee_ids: ids} : {});
            D.mail = res.data.mail;
            render();
            ok(res.message);
        } catch (e) { fail(e); } finally { if (btn) btn.disabled = false; }
    }

    function monthCell(c, d) {
        if (!c) return `<td class="${d.workday ? '' : 'tm-we'}"></td>`;
        if (c.a) return `<td class="${d.workday ? '' : 'tm-we'}"><i class="tm-a-${esc(c.a)}">${esc(c.a)}</i></td>`;
        if (c.st === 'open' || c.st === 'need_overtime' || c.st === 'need_absence') return `<td class="tm-q" title="${c.st === 'open' ? 'brak wyjścia' : 'brak powodu'}">${c.w ? hm(c.w) : '?'}</td>`;
        if (c.st === 'none') return `<td class="${d.workday ? '' : 'tm-we'}"></td>`;
        return `<td class="${d.workday ? '' : 'tm-we'} ${c.ot ? 'tm-ot' : ''}">${hm(c.w)}</td>`;
    }

    // =====================================================================================================
    // Makieta 3 · Urlopy i dni wolne
    // =====================================================================================================
    function renderLeave() {
        const days = D.days;
        const head = days.map(d => `<th class="${d.workday ? '' : 'tm-we'} ${d.today ? 'tm-today' : ''}" title="${esc(d.holiday || '')}">${d.n}<small>${d.dow}</small></th>`).join('');
        const rows = D.people.map(p => {
            const cells = [];
            for (let i = 0; i < days.length; i++) {
                const d = days[i].date;
                const a = p.absences.find(x => x.from <= d && x.to >= d);
                if (!a) { cells.push(`<td class="${days[i].workday ? '' : 'tm-we'}"></td>`); continue; }
                let span = 1;
                while (i + span < days.length && days[i + span].date <= a.to) span++;
                cells.push(`<td colspan="${span}" class="tm-bar-cell"><button type="button" class="tm-abs tm-t-${esc(a.type)}" data-absence="${a.id}" title="${esc(a.label)} ${dm(a.from)}–${dm(a.to)}${a.note ? ' — ' + esc(a.note) : ''}">${esc(span > 2 ? `${a.label} ${dm(a.from)}–${dm(a.to)}` : a.short)}</button></td>`);
                i += span - 1;
            }
            const left = p.remaining;
            return `<tr><td class="tm-name"><b>${esc(p.name)}</b><button type="button" class="tm-sub tm-link" data-leave-right="${p.employee_id}" title="Zmień wymiar urlopu">urlop : zostało ${left} z ${p.right} dni${p.planned ? ` (zaplan. ${p.planned})` : ''}${p.bank ? ` · bank ${hm(p.bank)}` : ''}</button></td>${cells.join('')}</tr>`;
        }).join('');
        const counts = days.map(d => `<td class="${d.workday ? '' : 'tm-we'} text-center">${d.workday ? (D.absent[d.date] || 0) : ''}</td>`).join('');
        const warn = D.warnings.length ? `<div class="tm-warn"><i class="bi bi-exclamation-triangle"></i> ${D.warnings.slice(0, 6).map(w => `${dm(w.date)} : ${esc(w.group)} — ${w.count} osoby nieobecne`).join(' · ')}${D.warnings.length > 6 ? ' …' : ''}</div>` : '';
        body.innerHTML = `<div class="tm-bar">
                <div class="tm-nav"><a class="btn btn-light btn-sm" href="?from=${D.prev}&days=${days.length}" aria-label="Wcześniej">‹</a><span class="tm-date"><span>${full(D.from)} – ${full(D.to)}</span></span>
                    <a class="btn btn-light btn-sm" href="?from=${D.next}&days=${days.length}" aria-label="Później">›</a></div>
                <span class="tm-spacer"></span>
                <button type="button" class="btn btn-primary btn-sm" data-add-absence><i class="bi bi-plus-lg"></i> Dodaj urlop / dzień wolny</button></div>
            ${warn}
            <div class="tm-card tm-scroll"><table class="table tm-cal"><thead><tr><th class="tm-name">Pracownik · pozostało (${D.year})</th>${head}</tr></thead>
                <tbody>${rows}</tbody><tfoot><tr><td class="tm-name">Nieobecni danego dnia</td>${counts}</tr></tfoot></table></div>
            <div class="tm-legend"><span><i class="tm-abs tm-t-urlop">U</i> urlop wypoczynkowy</span><span><i class="tm-abs tm-t-na_zadanie">UŻ</i> urlop na żądanie (4 dni w roku)</span>
                <span><i class="tm-abs tm-t-l4">L4</i> chorobowe</span><span><i class="tm-abs tm-t-wn">WN</i> wolne za nadgodziny (z podpisem)</span><span><i class="tm-abs tm-t-inna">N</i> inna nieobecność</span><span><i class="tm-we-dot"></i> weekend i święta PL</span></div>
            <p class="tm-note">Wpis z kalendarza = nieobecność usprawiedliwiona w zakładce Dzień (bez pytania). Ostrzeżenie, gdy w grupie brakuje więcej niż 1 osoby tego samego dnia.</p>`;
    }

    function absenceDialog() {
        const sel = D.people.map(p => `<option value="${p.employee_id}">${esc(p.name)}${p.group ? ' — ' + esc(p.group) : ''}</option>`).join('');
        const types = Object.entries(D.types).map(([k, v]) => `<option value="${k}">${esc(v)}</option>`).join('');
        const start = D.days.find(d => d.today) ? D.days.find(d => d.today).date : D.from;
        openDialog('Dodaj urlop / dzień wolny', `<label>Pracownik<select class="form-select" name="emp" required><option value="">—</option>${sel}</select></label>
            <label>Rodzaj<select class="form-select" name="type">${types}</select></label>
            <div class="tm-grid2"><label>Od<input type="date" class="form-control" name="from" value="${start}" required></label><label>Do<input type="date" class="form-control" name="to" value="${start}" required></label></div>
            <label>Uwagi<input class="form-control" name="note" maxlength="255" placeholder="np. wniosek z 10.11"></label>
            <p class="tm-sub">Dni wolne za nadgodziny : zakładka Nadgodziny (z podpisem pracownika).</p>`,
            async () => {
                const f = new FormData(form);
                if (!f.get('emp')) throw new Error('Wybierz pracownika.');
                return act(null, 'POST', '/ajax/time/absences', {employee_id: Number(f.get('emp')), type: f.get('type'), from: f.get('from'), to: f.get('to'), note: f.get('note')}, 'fetch');
            }, 'Dodaj do kalendarza');
    }

    function absenceDetail(id) {
        let a = null, who = null;
        for (const p of D.people) { const x = p.absences.find(y => y.id === id); if (x) { a = x; who = p; break; } }
        if (!a) return;
        openDialog(`${a.label} — ${who.name}`, `<p><b>${full(a.from)}${a.to !== a.from ? ' – ' + full(a.to) : ''}</b>${a.minutes ? ` · ${hm(a.minutes)}` : ''}</p>
            ${a.note ? `<p class="tm-sub">${esc(a.note)}</p>` : ''}${a.comp ? '<p class="tm-sub">Dzień wolny za nadgodziny (podpisany) : anulować można w zakładce Nadgodziny.</p>' : ''}`,
            async () => act(null, 'DELETE', `/ajax/time/absences/${id}`, null, 'fetch'), a.comp ? null : 'Usuń z kalendarza');
    }

    function leaveRightDialog(empId) {
        const p = D.people.find(x => x.employee_id === empId);
        openDialog(`Wymiar urlopu — ${p.name}`, `<label>Dni urlopu w roku<input type="number" class="form-control" name="days" min="0" max="40" value="${p.right}" required></label>
            <p class="tm-sub">Domyślnie ${D.rules.leave_days} dni dla każdej osoby. Wykorzystane w ${D.year} : ${p.used} d, zaplanowane : ${p.planned} d, na żądanie : ${p.on_demand} z 4.</p>`,
            async () => act(null, 'PUT', `/ajax/time/leave/${empId}`, {days: Number(new FormData(form).get('days'))}, 'fetch'), 'Zapisz');
    }

    // =====================================================================================================
    // Makieta 4 · Nadgodziny
    // =====================================================================================================
    function renderOvertime() {
        const k = D.kpis;
        const rows = D.people.map(p => `<tr>
            <td><b>${esc(p.name)}</b><span class="tm-sub">${esc(p.group || '')}</span></td>
            <td class="text-end tm-num"><b>${hm(p.bank)}</b>${p.reserved ? `<span class="tm-sub">${hm(p.reserved)} do podpisu</span>` : ''}</td>
            <td>${p.oldest ? `${hm(p.oldest.minutes)} z ${dm(p.oldest.day)}` : '—'}</td>
            <td>${p.deadline ? `<span class="${p.overdue ? 'tm-late' : p.days_left <= 30 ? 'tm-soon' : ''}">${full(p.deadline)}</span> <span class="tm-sub">${p.overdue ? `po terminie : ${hm(p.overdue)}` : `${p.days_left} ${pl(p.days_left, 'dzień', 'dni', 'dni')}`}</span>` : '—'}</td>
            <td>${p.reasons.slice(0, 3).map(r => `${esc(r.label)} ×${r.count}`).join(', ') || '—'}</td>
            <td class="text-end">${p.available >= 15 ? `<button type="button" class="btn btn-sm btn-outline-primary" data-comp="${p.employee_id}">Zamień na dzień wolny…</button>` : '<span class="tm-sub">za mało</span>'}</td></tr>`).join('');
        const comps = D.comps.map(c => `<tr><td>${esc(c.name)}</td><td>${full(c.day)}</td><td class="text-end tm-num">${hm(c.minutes)}</td>
            <td>${c.status === 'proposed' ? '<span class="at-pill at-pill-warn">do podpisu (tutaj albo tablet biurowy)</span>' : c.status === 'signed' ? `<span class="at-pill at-pill-ok">podpisany ${dm(c.signed_at)}</span>` : '<span class="at-pill at-pill-muted">anulowany</span>'}</td>
            <td class="text-end">${c.status === 'proposed' ? `<button type="button" class="btn btn-sm btn-primary" data-sign="${c.id}">Podpisz tutaj…</button> ` : ''}
                ${c.status !== 'cancelled' && c.day >= D.today ? `<button type="button" class="btn btn-sm btn-link text-danger" data-cancel-comp="${c.id}">Anuluj</button>` : ''}</td></tr>`).join('');
        body.innerHTML = `<div class="tm-kpis">
                <div class="tm-kpi"><small>Bank nadgodzin (zespół)</small><b>${hm(k.bank)}</b><span>${k.people} ${pl(k.people, 'osoba', 'osoby', 'osób')}, z dni zatwierdzonych</span></div>
                <div class="tm-kpi ${k.expiring ? 'is-warn' : ''}"><small>Termin w ciągu 30 dni</small><b>${hm(k.expiring)}</b><span>${k.expiring_people} ${pl(k.expiring_people, 'osoba', 'osoby', 'osób')} — zaplanuj dni wolne</span></div>
                <div class="tm-kpi ${k.overdue ? 'is-danger' : ''}"><small>Po terminie (${D.rules.recovery_months} mies.)</small><b>${hm(k.overdue)}</b><span>${k.overdue ? 'odbierz jak najszybciej' : 'brak'}</span></div>
                <div class="tm-kpi is-ok"><small>Odebrane w tym miesiącu</small><b>${hm(k.recovered_month)}</b><span>${k.recovered_days} ${pl(k.recovered_days, 'dzień wolny podpisany', 'dni wolne podpisane', 'dni wolnych podpisanych')}</span></div></div>
            <div class="tm-card">${D.people.length ? `<table class="table tm-table"><thead><tr><th>Pracownik</th><th class="text-end">Bank</th><th>Najstarsze godziny</th><th>Odebrać do</th><th>Powody (ostatnie ${D.rules.recovery_months} mies.)</th><th></th></tr></thead>
                <tbody>${rows}</tbody></table>` : '<p class="at-empty">Brak nadgodzin do odbioru (liczą się tylko dni zatwierdzone).</p>'}</div>
            <h5 class="tm-h">Dni wolne za nadgodziny</h5>
            <div class="tm-card">${D.comps.length ? `<table class="table tm-table"><thead><tr><th>Pracownik</th><th>Dzień wolny</th><th class="text-end">Godziny</th><th>Stan</th><th></th></tr></thead><tbody>${comps}</tbody></table>`
                : '<p class="at-empty">Brak dni wolnych do podpisu.</p>'}</div>
            <p class="tm-note">Najpierw odbierane są najstarsze godziny (FIFO). Każda nadgodzina musi być odebrana jako dzień wolny najpóźniej ${D.rules.recovery_months} mies. po dniu pracy.
                Dzień wolny proponuje kierownik ; pracownik podpisuje go tutaj albo przy odbiciu na tablecie biurowym (PIN) — dopiero podpis zmniejsza bank i wpisuje dzień do kalendarza.</p>`;
    }

    function nextWorkday() {
        let d = addDays(D.today, 1);
        for (let i = 0; i < 7; i++) { const n = new Date(d + 'T12:00:00').getDay(); if (n !== 0 && n !== 6) return d; d = addDays(d, 1); }
        return d;
    }

    function compDialog(empId) {
        const p = D.people.find(x => x.employee_id === empId);
        const minutes = Math.min(p.available, D.rules.day_plan);
        const balance = (m) => `Bank ${hm(p.bank)}${p.reserved ? ` (${hm(p.reserved)} już do podpisu)` : ''} → po odbiorze ${m === null ? '—' : hm(p.bank - p.reserved - m)}.`
            + (m !== null && m < D.rules.day_plan ? ` Dzień ma ${hm(D.rules.day_plan)} : brakujące ${hm(D.rules.day_plan - m)} = krótszy dzień pracy.` : '');
        openDialog(`Dzień wolny za nadgodziny — ${p.name}`, `<p class="tm-sub" data-balance>${balance(minutes)}</p>
            <div class="tm-grid2"><label>Dzień<input type="date" class="form-control" name="day" min="${D.today}" value="${nextWorkday()}" required></label>
                <label>Godziny<input class="form-control" name="hours" value="${hm(minutes)}" required pattern="\\d{1,2}(:\\d{2})?" title="np. 8:00 albo 6:20"></label></div>
            <p class="tm-label">Podpis pracownika (tutaj albo na tablecie biurowym)</p>
            <canvas class="tm-pad" data-pad width="900" height="240" aria-label="Podpis pracownika"></canvas>
            <div class="tm-row-end"><button type="button" class="btn btn-link btn-sm" data-clear-pad>Wyczyść</button></div>
            <p class="tm-sub" data-confirm-text></p>`,
            async (which) => {
                const f = new FormData(form);
                const min = parseHm(f.get('hours'));
                if (min === null) throw new Error('Godziny : np. 8:00.');
                if (which === 'main' && pad.empty()) throw new Error('Brak podpisu pracownika — albo « Wyślij do podpisu na tablet ».');
                const res = await call('POST', '/ajax/time/comps', {employee_id: empId, day: f.get('day'), minutes: min});
                if (which === 'main') await call('POST', `/ajax/time/comps/${res.data.id}/sign`, {image: pad.image()});
                await refresh(); render();
                ok(which === 'main' ? 'Podpisane — dzień wolny w kalendarzu.' : 'Wysłane : pracownik podpisze przy następnym odbiciu PIN-em na tablecie biurowym.');
            }, 'Podpisane — zapisz', '<button type="submit" class="btn btn-outline-primary" data-alt="tablet">Wyślij do podpisu na tablet</button>');
        const pad = signaturePad(form.querySelector('[data-pad]'));
        form.querySelector('[data-clear-pad]').addEventListener('click', () => pad.clear());
        const txt = () => { const f = new FormData(form); form.querySelector('[data-balance]').textContent = balance(parseHm(f.get('hours'))); form.querySelector('[data-confirm-text]').textContent = `Pracownik potwierdza : « Odbieram ${f.get('hours')} nadgodzin jako dzień wolny ${full(String(f.get('day')))} ». Podpis z datą i skrótem SHA-256, jak przy odbiciach.`; };
        form.addEventListener('input', txt);
        txt();
    }

    function signDialog(compId) {
        const c = D.comps.find(x => x.id === compId);
        openDialog(`Podpis — ${c.name}`, `<p>Pracownik potwierdza : « Odbieram ${hm(c.minutes)} nadgodzin jako dzień wolny ${full(c.day)} ».</p>
            <canvas class="tm-pad" data-pad width="900" height="240" aria-label="Podpis pracownika"></canvas>
            <div class="tm-row-end"><button type="button" class="btn btn-link btn-sm" data-clear-pad>Wyczyść</button></div>`,
            async () => {
                if (pad.empty()) throw new Error('Brak podpisu.');
                return act(null, 'POST', `/ajax/time/comps/${compId}/sign`, {image: pad.image()}, 'fetch');
            }, 'Podpisane — zapisz');
        const pad = signaturePad(form.querySelector('[data-pad]'));
        form.querySelector('[data-clear-pad]').addEventListener('click', () => pad.clear());
    }

    // =====================================================================================================
    function render() {
        ({day: renderDay, month: renderMonth, leave: renderLeave, overtime: renderOvertime})[tab]();
    }

    body.addEventListener('change', (e) => {
        const t = e.target;
        if (t.matches('[data-goto-day]') && t.value) location.href = `?date=${t.value}`;
        if (t.matches('[data-bulk-reason]') && t.value) saveBulk(t.value);
        if (t.matches('[data-reason]')) {
            const note = body.querySelector(`[data-reason-note="${t.dataset.reason}"][data-emp="${t.dataset.emp}"]`);
            saveReason(t.dataset.reason, t.dataset.emp, t.value, note ? note.value.trim() : '');
        }
        if (t.matches('[data-reason-note]')) {
            const sel = body.querySelector(`[data-reason="${t.dataset.reasonNote}"][data-emp="${t.dataset.emp}"]`);
            if (sel && sel.value && t.value.trim()) saveReason(t.dataset.reasonNote, t.dataset.emp, sel.value, t.value.trim());
        }
    });

    body.addEventListener('click', async (e) => {
        const b = e.target.closest('button, a');
        if (!b) return;
        if (b.matches('[data-show-all]')) { showAll = !showAll; render(); return; }
        if (b.matches('[data-print]')) { showAll = true; render(); window.print(); return; }
        if (b.matches('[data-validate]')) { act(b, 'POST', `/ajax/time/days/${D.day}/validate`); return; }
        if (b.matches('[data-reopen]')) { if (await confirmBox('Cofnąć zatwierdzenie dnia ?')) act(b, 'DELETE', `/ajax/time/days/${D.day}/validate`); return; }
        if (b.matches('[data-edit-clock]')) { clockDialog(Number(b.dataset.editClock)); return; }
        if (b.matches('[data-close-clock]')) { clockDialog(Number(b.dataset.closeClock)); return; }
        if (b.matches('[data-add-clock]')) { addClockDialog(b.dataset.addClock || null); return; }
        if (b.matches('[data-close-month]')) {
            if (await confirmBox(`Zamknąć ${D.label} ? Po zamknięciu nie można zmieniać odbić, powodów ani urlopów tego miesiąca. Karty A4 do podpisu : « Drukuj wszystkich (PDF) ».`)) act(b, 'POST', `/ajax/time/months/${D.month}/close`);
            return;
        }
        if (b.matches('[data-contacts]')) { contactsDialog(b.dataset.contacts || null); return; }
        if (b.matches('[data-mail-all]')) { mailSheets(b, null); return; }
        if (b.matches('[data-mail-one]')) { mailSheets(b, [Number(b.dataset.mailOne)]); return; }
        if (b.matches('[data-reopen-month]')) { if (await confirmBox('Otworzyć ponownie zamknięty miesiąc ?')) act(b, 'DELETE', `/ajax/time/months/${D.month}/close`); return; }
        if (b.matches('[data-add-absence]')) { absenceDialog(); return; }
        if (b.matches('[data-absence]')) { absenceDetail(Number(b.dataset.absence)); return; }
        if (b.matches('[data-leave-right]')) { leaveRightDialog(Number(b.dataset.leaveRight)); return; }
        if (b.matches('[data-comp]')) { compDialog(Number(b.dataset.comp)); return; }
        if (b.matches('[data-sign]')) { signDialog(Number(b.dataset.sign)); return; }
        if (b.matches('[data-cancel-comp]')) { if (await confirmBox('Anulować dzień wolny ?')) act(b, 'DELETE', `/ajax/time/comps/${b.dataset.cancelComp}`, null, 'fetch'); }
    });

    render();
})();
