/*
 * Czas pracy [262] (makiety 1–4, 11.11.2026) — App\Services\Workshop\Time\TimeService liczy wszystko, strona pokazuje
 * i wysyła decyzje :
 *   Dzień      : odbicia dnia, plan, różnica ; powód nadgodzin / nieobecności (lista), wyjście do uzupełnienia,
 *                odbicie ręczne, zatwierdzenie dnia (zadanie nr 1 kierownika produkcji) ;
 *   Miesiąc    : wszyscy × dni (godziny, nadgodziny, U / L4 / WN, ?), sumy, bank, karty A4 (PDF), zamknięcie miesiąca ;
 *                [266] e-maile pracowników i karta miesiąca e-mailem (PDF w załączniku, kolejka wysyłki) ;
 *   Urlopy     : kalendarz zespołu (paski), pozostały urlop (26 dni), dodaj / usuń wpis, wymiar urlopu osoby ;
 *   Nadgodziny : bank (FIFO, odbiór w 3 miesiące), dzień wolny : propozycja → podpis pracownika (tu albo tablet biurowy) ;
 *   Koszty i premie [267] : do odczytu — koszt pracodawcy grup i osób, progi i premie, brakująca sprzedaż, premia kierownika.
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
            : tab === 'leave' ? `/ajax/time/leave?from=${D.from}&days=${D.days.length}` : tab === 'costs' ? '/ajax/time/costs' : '/ajax/time/overtime';
        D = (await call('GET', url)).data;
    }

    // --- Okno dialogowe (formularz) -----------------------------------------------------------------------------
    function openDialog(title, html, onSubmit, submitLabel, extra) {
        form.innerHTML = `<header class="tm-dialog-head"><h4 id="tmDialogTitle">${esc(title)}</h4>
                <button type="button" class="btn-close" data-close aria-label="Zamknij"></button></header>
            <div class="tm-dialog-body">${html}</div><p class="tm-dialog-error" data-error></p>
            <footer class="tm-dialog-foot">${extra || ''}<button type="button" class="btn btn-light" data-close>${submitLabel ? 'Anuluj' : 'Zamknij'}</button>
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
            <td><b>${esc(p.name)}</b>${p.took ? ` ${basket(p.employee_id, D.day, p.took)}` : ''}${comp}</td>
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
            ${days.map(d => monthCell(p.cells[d.date], d, p.employee_id)).join('')}
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

    // [269] Towar zabrany przy wyjściu : mała ikona koszyka w tabeli (Dzień, Miesiąc) → okno z tym, co zostało zaznaczone.
    function basket(empId, day, title, small) {
        return `<button type="button" class="tm-basket ${small ? 'is-small' : ''}" data-goods="${empId}" data-goods-day="${day}" title="${esc(title)}" aria-label="Towar zabrany przy wyjściu : ${esc(title)}"><i class="bi bi-basket2"></i></button>`;
    }

    async function goodsDialog(empId, day) {
        let g;
        try { g = (await call('GET', `/ajax/time/goods?employee=${empId}&day=${day}`)).data; } catch (e) { fail(e); return; }
        const qty = (n) => String(Math.round(n * 100) / 100).replace('.', ',');
        const exits = g.exits.map(x => {
            const lines = x.lines.length ? `<table class="table tm-table tm-goods"><thead><tr><th>Produkt</th><th class="text-end">Sztuki</th><th></th></tr></thead><tbody>${x.lines.map(l => l.other
                ? `<tr><td colspan="2"><span class="tm-sub">inne :</span> ${esc(l.product)}</td><td></td></tr>`
                : `<tr><td>${esc(l.product)}</td><td class="text-end tm-num"><b>${qty(l.quantity)}</b></td><td>${l.waste ? '<span class="tm-tag tm-tag-warn">z braków</span>' : ''}</td></tr>`).join('')}</tbody></table>`
                : `<p class="tm-goods-note">${esc(x.note || 'Tak (bez szczegółów)')}</p>`;
            return `<div class="tm-goods-exit"><div class="tm-sub">Wyjście ${x.clock_out ? time(x.clock_out) : '—'} · ${esc({kiosk: 'tablet / telefon', manual: 'ręcznie'}[x.source] || '')}</div>${lines}</div>`;
        }).join('') || '<p class="at-empty">Brak deklaracji tego dnia.</p>';
        openDialog(`Towar zabrany — ${g.employee.name}, ${full(g.day)}`, `${exits}
            ${g.pieces ? `<p class="tm-note">Razem : <b>${qty(g.pieces)} szt.</b> produktów. Braki (odpady) trzeba wpisać ; prośby na imprezę — przez biuro.</p>` : ''}`, null, null);
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

    function monthCell(c, d, empId) {
        if (!c) return `<td class="${d.workday ? '' : 'tm-we'}"></td>`;
        const g = c.g ? basket(empId, d.date, 'Towar zabrany przy wyjściu', true) : '';
        const inner = monthCellInner(c, d);
        return g ? inner.replace(/<\/td>$/, `${g}</td>`) : inner;
    }

    function monthCellInner(c, d) {
        if (c.a) return `<td class="${d.workday ? '' : 'tm-we'}"><i class="tm-a-${esc(c.a)}">${esc(c.a)}</i></td>`;
        if (c.st === 'open' || c.st === 'need_overtime' || c.st === 'need_absence') return `<td class="tm-q" title="${c.st === 'open' ? 'brak wyjścia' : 'brak powodu'}">${c.w ? hm(c.w) : '?'}</td>`;
        if (c.st === 'none') return `<td class="${d.workday ? '' : 'tm-we'}"></td>`;
        return `<td class="${d.workday ? '' : 'tm-we'} ${c.ot ? 'tm-ot' : ''}">${hm(c.w)}</td>`;
    }

    // =====================================================================================================
    // Makieta 3 · Urlopy i dni wolne
    // =====================================================================================================
    // [288] Makieta 48 : klik w pustą komórkę → « Dodaj urlop » (start = kliknięty dzień) ; klik w urlop → szczegóły ;
    // klik w wniosek z telefonu (kreskowany) → Zatwierdź / Odrzuć (powód). Saldo : przysługuje (+ zaległy do 01.06), wzięte, zostało.
    const workdays = (from, to) => {
        if (!from || !to || to < from) return 0;
        const off = new Set(D.holiday_dates || []);
        let n = 0;
        for (let d = from; d <= to; d = addDays(d, 1)) { const w = new Date(d + 'T12:00:00').getDay(); if (w !== 0 && w !== 6 && !off.has(d)) n++; }
        return n;
    };
    const leaveSub = (p) => {
        const bits = [`urlop : zostało ${p.remaining} z ${p.right + (p.adjust || 0)}`];
        if (p.carry_left) bits.push(`zaległy ${D.year - 1} : ${p.carry_left} (do ${dm(p.carry_deadline)})`);
        else if (p.carry_lapsed) bits.push(`zaległy ${D.year - 1} : przepadło ${p.carry_lapsed} (${dm(p.carry_deadline)})`);
        if (p.pending_days) bits.push(`wnioski ${p.pending_days} d`);
        if (p.bank) bits.push(`bank ${hm(p.bank)}`);
        return bits.join(' · ');
    };

    function renderLeave() {
        const days = D.days;
        const head = days.map(d => `<th class="${d.workday ? '' : 'tm-we'} ${d.today ? 'tm-today' : ''}" title="${esc(d.holiday || '')}">${d.n}<small>${d.dow}</small></th>`).join('');
        const rows = D.people.map(p => {
            const cells = [];
            const items = p.absences.map(a => ({...a, kind: 'abs'})).concat((p.requests || []).map(r => ({...r, kind: 'req'})));
            for (let i = 0; i < days.length; i++) {
                const d = days[i].date;
                const a = items.find(x => x.from <= d && x.to >= d);
                if (!a) { cells.push(`<td class="tm-cell-add ${days[i].workday ? '' : 'tm-we'}" data-add-day="${d}" data-add-emp="${p.employee_id}" title="Dodaj urlop : ${esc(p.name)} ${dm(d)}"></td>`); continue; }
                let span = 1;
                while (i + span < days.length && days[i + span].date <= a.to) span++;
                const label = span > 2 ? `${a.kind === 'req' ? 'Wniosek · ' : ''}${a.label} ${dm(a.from)}–${dm(a.to)}` : a.short;
                cells.push(a.kind === 'req'
                    ? `<td colspan="${span}" class="tm-bar-cell"><button type="button" class="tm-abs tm-req" data-request="${a.id}" title="Wniosek z telefonu — do zatwierdzenia : ${esc(a.label)} ${dm(a.from)}–${dm(a.to)}">${esc(label)}</button></td>`
                    : `<td colspan="${span}" class="tm-bar-cell"><button type="button" class="tm-abs tm-t-${esc(a.type)}" data-absence="${a.id}" title="${esc(a.label)} ${dm(a.from)}–${dm(a.to)}${a.note ? ' — ' + esc(a.note) : ''}">${esc(label)}</button></td>`);
                i += span - 1;
            }
            return `<tr><td class="tm-name"><b>${esc(p.name)}</b><button type="button" class="tm-sub tm-link" data-leave-right="${p.employee_id}" title="Wymiar urlopu i saldo">${esc(leaveSub(p))}</button></td>${cells.join('')}</tr>`;
        }).join('');
        const counts = days.map(d => `<td class="${d.workday ? '' : 'tm-we'} text-center">${d.workday ? (D.absent[d.date] || 0) : ''}</td>`).join('');
        const warn = D.warnings.length ? `<div class="tm-warn"><i class="bi bi-exclamation-triangle"></i> ${D.warnings.slice(0, 6).map(w => `${dm(w.date)} : ${esc(w.group)} — ${w.count} osoby nieobecne`).join(' · ')}${D.warnings.length > 6 ? ' …' : ''}</div>` : '';
        const pend = (D.pending || []).length;
        body.innerHTML = `<div class="tm-bar">
                <div class="tm-nav"><a class="btn btn-light btn-sm" href="?from=${D.prev}&days=${days.length}" aria-label="Wcześniej">‹</a><span class="tm-date"><span>${full(D.from)} – ${full(D.to)}</span></span>
                    <a class="btn btn-light btn-sm" href="?from=${D.next}&days=${days.length}" aria-label="Później">›</a></div>
                <span class="tm-spacer"></span>
                <button type="button" class="btn btn-sm ${pend ? 'btn-outline-warning tm-pending-on' : 'btn-light'}" data-pending-list ${pend ? '' : 'disabled'}><i class="bi bi-phone"></i> Wnioski z telefonu : <b>${pend}</b> do zatwierdzenia</button>
                <button type="button" class="btn btn-primary btn-sm" data-add-absence><i class="bi bi-plus-lg"></i> Dodaj urlop / dzień wolny</button></div>
            ${warn}
            <div class="tm-card tm-scroll"><table class="table tm-cal"><thead><tr><th class="tm-name">Pracownik · pozostało (${D.year})</th>${head}</tr></thead>
                <tbody>${rows}</tbody><tfoot><tr><td class="tm-name">Nieobecni danego dnia</td>${counts}</tr></tfoot></table></div>
            <div class="tm-legend"><span><i class="tm-abs tm-t-urlop">U</i> urlop wypoczynkowy</span><span><i class="tm-abs tm-t-na_zadanie">UŻ</i> urlop na żądanie (4 dni w roku)</span>
                <span><i class="tm-abs tm-req">W</i> wniosek z telefonu — do zatwierdzenia</span>
                <span><i class="tm-abs tm-t-l4">L4</i> chorobowe</span><span><i class="tm-abs tm-t-wn">WN</i> wolne za nadgodziny (z podpisem)</span><span><i class="tm-abs tm-t-inna">N</i> inna nieobecność</span><span><i class="tm-we-dot"></i> weekend i święta PL</span></div>
            <p class="tm-note">Klik w pustą komórkę osoby → « Dodaj urlop » od tego dnia. Klik w urlop → szczegóły. Wnioski z telefonu (po odbiciu QR) zatwierdza kierownik produkcji.
                Zaległy urlop z ${D.year - 1} : do wykorzystania do ${dm(D.carry_deadline)} (urlop od 1.01 najpierw zmniejsza zaległy, reszta potem przepada — Ustawienia › Parametry).
                Ostrzeżenie, gdy w grupie brakuje więcej niż 1 osoby tego samego dnia.</p>`;
    }

    /** Kafelki salda : przysługuje / wzięte / ten wniosek / zostanie (+ zaległy). */
    function balanceTiles(p, days, typeOnDemand) {
        const entitled = (p.right + (p.adjust || 0)) + (p.carry || 0) - (p.carry_lapsed || 0);
        const after = p.remaining - days;
        const od = typeOnDemand ? `<p class="tm-sub">Na żądanie : wykorzystane ${p.on_demand} z 4.</p>` : '';
        const carry = p.carry_left ? `<p class="tm-sub tm-carry">Najpierw zaległy z ${D.year - 1} : ${p.carry_left} d — do wykorzystania do ${full(p.carry_deadline)}, potem przepada.</p>`
            : (p.carry_lapsed ? `<p class="tm-sub">Zaległy z ${D.year - 1} (${p.carry_lapsed} d) przepadł ${full(p.carry_deadline)}.</p>` : '');
        return `<div class="tm-bal"><div><small>Przysługuje</small><b>${entitled}</b></div><div><small>Wzięte</small><b>${p.used + p.planned}</b></div>
            <div><small>Ten wniosek</small><b data-bal-days>${days}</b></div><div class="${after < 0 ? 'is-bad' : ''}"><small>Zostanie</small><b data-bal-after>${after}</b></div></div>${carry}${od}`;
    }

    function absenceDialog(empId, day) {
        const sel = D.people.map(p => `<option value="${p.employee_id}" ${p.employee_id === empId ? 'selected' : ''}>${esc(p.name)}${p.group ? ' — ' + esc(p.group) : ''}</option>`).join('');
        const types = Object.entries(D.types).map(([k, v], i) => `<label class="tm-chip"><input type="radio" name="type" value="${k}" ${i === 0 ? 'checked' : ''}><span>${esc(v)}</span></label>`).join('');
        const start = day || (D.days.find(d => d.today) ? D.days.find(d => d.today).date : D.from);
        const end = day && D.days.find(d => d.date === day) ? start : start;
        const who = empId ? D.people.find(x => x.employee_id === empId) : null;
        openDialog(who ? `Dodaj urlop · ${who.name}` : 'Dodaj urlop / dzień wolny', `${who ? `<p class="tm-sub">klik : ${full(start)}</p><input type="hidden" name="emp" value="${empId}">` : `<label>Pracownik<select class="form-select" name="emp" required><option value="">—</option>${sel}</select></label>`}
            <div class="tm-chips">${types}</div>
            <div class="tm-grid2"><label>Od<input type="date" class="form-control" name="from" value="${start}" required></label><label>Do<input type="date" class="form-control" name="to" value="${end}" required></label></div>
            <div data-bal></div>
            <label>Uwagi<input class="form-control" name="note" maxlength="255" placeholder="Uwaga (opcjonalnie)"></label>
            <p class="tm-sub">Dni wolne za nadgodziny : zakładka Nadgodziny (z podpisem pracownika).</p>`,
            async () => {
                const f = new FormData(form);
                if (!f.get('emp')) throw new Error('Wybierz pracownika.');
                return act(null, 'POST', '/ajax/time/absences', {employee_id: Number(f.get('emp')), type: f.get('type'), from: f.get('from'), to: f.get('to'), note: f.get('note')}, 'fetch');
            }, 'Dodaj urlop');
        const upd = () => {
            const f = new FormData(form);
            const p = D.people.find(x => x.employee_id === Number(f.get('emp')));
            const box = form.querySelector('[data-bal]');
            if (!p) { box.innerHTML = ''; return; }
            if (form.to.value < form.from.value) form.to.value = form.from.value;
            const type = f.get('type');
            const n = ['urlop', 'na_zadanie'].includes(type) ? workdays(form.from.value, form.to.value) : 0;
            box.innerHTML = ['urlop', 'na_zadanie'].includes(type) ? balanceTiles(p, n, type === 'na_zadanie') : `<p class="tm-sub">${workdays(form.from.value, form.to.value)} dni roboczych — nie zmniejsza urlopu.</p>`;
        };
        form.addEventListener('input', upd);
        form.addEventListener('change', upd);
        upd();
    }

    function absenceDetail(id) {
        let a = null, who = null;
        for (const p of D.people) { const x = p.absences.find(y => y.id === id); if (x) { a = x; who = p; break; } }
        if (!a) return;
        const n = workdays(a.from, a.to);
        openDialog(`${a.label} — ${who.name}`, `<p><b>${full(a.from)}${a.to !== a.from ? ' – ' + full(a.to) : ''}</b>${a.minutes ? ` · ${hm(a.minutes)}` : ` · ${n} ${pl(n, 'dzień roboczy', 'dni robocze', 'dni roboczych')}`}</p>
            ${a.note ? `<p class="tm-sub">${esc(a.note)}</p>` : ''}${a.comp ? '<p class="tm-sub">Dzień wolny za nadgodziny (podpisany) : anulować można w zakładce Nadgodziny.</p>' : ''}
            ${['urlop', 'na_zadanie'].includes(a.type) ? `<p class="tm-sub">Saldo ${D.year} : ${esc(leaveSub(who))}</p>` : ''}`,
            async () => act(null, 'DELETE', `/ajax/time/absences/${id}`, null, 'fetch'), a.comp ? null : 'Usuń z kalendarza');
    }

    /** Wniosek z telefonu : saldo osoby, ostrzeżenie o zaległym, Odrzuć (powód) / Zatwierdź. */
    function requestDetail(id) {
        const r = (D.pending || []).find(x => x.id === id) || D.people.flatMap(p => p.requests || []).find(x => x.id === id);
        if (!r) return;
        const p = D.people.find(x => x.employee_id === r.employee_id);
        const others = p ? Math.max(0, (p.pending_days || 0) - r.days) : 0;
        const bal = p ? {...p, remaining: p.remaining - others} : null;
        openDialog(`Wniosek z telefonu · ${r.name || (p && p.name) || ''}`, `<p><b>${full(r.from)}${r.to !== r.from ? ' – ' + full(r.to) : ''}</b> · ${r.days} ${pl(r.days, 'dzień roboczy', 'dni robocze', 'dni roboczych')} · ${esc(r.label)}</p>
            <p class="tm-sub">złożony ${full(String(r.created_at).slice(0, 10))}, ${String(r.created_at).slice(11, 16)} (telefon po QR)${r.note ? ' — « ' + esc(r.note) + ' »' : ''}</p>
            ${bal ? balanceTiles(bal, r.days, r.type === 'na_zadanie') : ''}${others ? `<p class="tm-sub">Inne wnioski tej osoby do zatwierdzenia : ${others} d (już odjęte).</p>` : ''}
            <label>Powód odrzucenia (pracownik zobaczy go w telefonie)<input class="form-control" name="reason" maxlength="255" placeholder="np. za dużo osób w grupie tego dnia"></label>`,
            async (which) => {
                if (which === 'reject') {
                    const reason = form.reason.value.trim();
                    if (reason.length < 3) throw new Error('Podaj powód odrzucenia.');
                    return act(null, 'POST', `/ajax/time/leave-requests/${id}/reject`, {reason}, 'fetch');
                }
                return act(null, 'POST', `/ajax/time/leave-requests/${id}/approve`, {}, 'fetch');
            }, 'Zatwierdź', '<button type="submit" class="btn btn-outline-danger me-auto" data-alt="reject">Odrzuć (powód)</button>');
    }

    function pendingList() {
        const list = D.pending || [];
        openDialog(`Wnioski z telefonu (${list.length})`, list.length ? `<table class="table tm-table"><tbody>${list.map(r => `<tr><td><b>${esc(r.name)}</b><div class="tm-sub">${esc(r.label)}${r.note ? ' — ' + esc(r.note) : ''}</div></td>
                <td class="text-end tm-num">${dm(r.from)}${r.to !== r.from ? '–' + dm(r.to) : ''}<div class="tm-sub">${r.days} d</div></td>
                <td class="text-end"><button type="button" class="btn btn-sm btn-primary" data-open-request="${r.id}" data-from="${r.from}">Otwórz</button></td></tr>`).join('')}</tbody></table>` : '<p class="tm-sub">Brak wniosków.</p>', null, null);
        form.querySelectorAll('[data-open-request]').forEach(b => b.addEventListener('click', () => {
            const id = Number(b.dataset.openRequest);
            if (D.people.some(p => (p.requests || []).some(x => x.id === id))) { dialog.close(); requestDetail(id); return; }
            const from = b.dataset.from;
            const monday = addDays(from, -((new Date(from + 'T12:00:00').getDay() + 6) % 7));
            location.href = `?from=${monday}&days=${D.days.length}&request=${id}`;
        }));
    }

    function leaveRightDialog(empId) {
        const p = D.people.find(x => x.employee_id === empId);
        openDialog(`Urlop ${D.year} — ${p.name}`, `<div class="tm-grid2"><label>Dni urlopu w roku (wymiar)<input type="number" class="form-control" name="days" min="0" max="40" value="${p.right}" required></label>
                <label>Zostało w ${D.year} (saldo)<input type="number" class="form-control" name="remaining" min="0" max="80" value="${p.remaining}" required></label></div>
            ${balanceTiles(p, 0, false)}
            <p class="tm-sub">Domyślnie ${D.rules.leave_days} dni dla każdej osoby. Wzięte w ${D.year} : ${p.used} d, zaplanowane : ${p.planned} d, na żądanie : ${p.on_demand} z 4${p.adjust ? `, korekta salda ${p.adjust > 0 ? '+' : ''}${p.adjust} d` : ''}.
                « Zostało » : wpisz saldo z kadr, ERP zapisze korektę (to samo pole co w HR).</p>`,
            async () => {
                const f = new FormData(form);
                const days = Number(f.get('days'));
                const remaining = Number(f.get('remaining'));
                if (days !== p.right) await call('PUT', `/ajax/time/leave/${empId}`, {days});
                if (remaining !== p.remaining || days !== p.right) {
                    if (days !== p.right && remaining === p.remaining) { await refresh(); render(); ok('Zapisano wymiar urlopu.'); return true; }
                    return act(null, 'PUT', `/ajax/time/leave/${empId}/remaining`, {remaining}, 'fetch');
                }
                return true;
            }, 'Zapisz');
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
    // =====================================================================================================
    // [267] Koszty i premie (makieta 35) — do odczytu : koszt pracodawcy grup i osób, próg i premia, brakująca sprzedaż,
    // premia kierownika produkcji (% sprzedaży firmy w miesiącu). Premie tylko, gdy administrator je pokazuje.
    // =====================================================================================================
    const zl = (n) => n === null || n === undefined ? '—' : `${String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, '\u00a0')} zł`;
    const pct = (n) => n === null || n === undefined ? '—' : `${String(Math.round(n * 10) / 10).replace('.', ',')} %`;
    const openGroups = new Set();
    function renderCosts() {
        const c = D.company;
        const B = D.show_bonuses;
        const cols = B ? 7 : 5;
        const rows = D.groups.map(g => {
            const open = openGroups.has(g.id);
            const tier = !B ? '' : g.tier ? `<span class="tm-tag tm-tag-ok">próg ${g.tier.index} · ${zl(g.tier.from)}</span>`
                : g.below_min ? `<span class="tm-tag tm-tag-warn">poniżej minimum ${zl(D.min)}</span>` : '<span class="tm-sub">—</span>';
            const next = !B ? '' : g.next ? `<b class="${g.tier ? '' : 'tm-late'}">${zl(g.next.missing)}</b>${g.tier ? ' (następny próg)' : ''}` : (g.tier ? 'najwyższy próg' : '');
            const head = `<tr class="tm-grp" data-group="${g.id}"><td><button type="button" class="tm-grp-btn" data-toggle-group="${g.id}" aria-expanded="${open}">
                    <i class="tm-dot" style="background:${esc(g.color || '#999')}"></i><b>${esc(g.name)}</b><i class="bi bi-chevron-${open ? 'down' : 'right'}" aria-hidden="true"></i></button></td>
                <td class="text-end tm-num">${g.people}</td><td class="text-end tm-num"><b>${zl(g.cost)}</b></td>
                <td class="text-end tm-num">${zl(g.revenue_projection)}<span class="tm-sub">dziś : ${zl(g.revenue)} · na osobę : ${zl(g.per_person_projection)}</span></td>
                <td class="text-end tm-num">${g.missing_target ? `<span class="tm-late">${zl(g.missing_target)}</span>` : g.missing_target === 0 ? '<span class="tm-tag tm-tag-ok">cel osiągnięty</span>' : '—'}${g.staff_target !== null ? `<span class="tm-sub">cel ${pct(g.staff_target)} kosztu personelu</span>` : ''}</td>
                ${B ? `<td>${tier}<span class="tm-sub">${g.next ? 'do progu brakuje : ' : ''}${next}</span></td><td class="text-end tm-num"><b>${zl(g.bonus_person)}</b><span class="tm-sub">grupa : ${zl(g.bonus_group)}</span></td>` : ''}</tr>`;
            const people = !open ? '' : g.persons.map(p => `<tr class="tm-per"><td>${esc(p.name)}</td><td></td><td class="text-end tm-num">${zl(p.cost)}</td>
                <td colspan="2"><span class="tm-sub">${g.group_days ? `obecność ${p.days} / ${g.group_days} dni` : 'brak odbić w tym miesiącu'}</span></td>
                ${B ? `<td></td><td class="text-end tm-num">${zl(p.bonus)}</td>` : ''}</tr>`).join('');
            return head + people;
        }).join('');
        body.innerHTML = `<div class="tm-bar"><span class="tm-date"><span>${esc(D.label)}</span></span>
                <span class="tm-prev">stan na dziś (${D.day}. dzień z ${D.days}) i prognoza w obecnym tempie · tylko do odczytu : płace i progi ustala administrator</span></div>
            <div class="tm-kpis">
                <div class="tm-kpi"><small>Sprzedaż firmy</small><b>${zl(c.revenue)}</b><span>prognoza miesiąca : ${zl(c.projection)}</span></div>
                <div class="tm-kpi"><small>Koszt personelu / mies.</small><b>${zl(c.staff)}</b><span>${c.staff_pct !== null ? `${pct(c.staff_pct)} prognozy sprzedaży · ` : ''}cel ${pct(c.staff_target)}</span></div>
                <div class="tm-kpi ${c.missing ? 'is-danger' : 'is-ok'}"><small>Brakuje sprzedaży do celu</small><b>${zl(c.missing)}</b><span>żeby koszt personelu = ${pct(c.staff_target)} sprzedaży</span></div>
                ${B && D.manager ? `<div class="tm-kpi tm-kpi-me"><small>Twoja premia · ${String(D.manager.pct).replace('.', ',')} % sprzedaży firmy</small><b>${zl(D.manager.projection)}</b><span>prognoza miesiąca ; stan na dziś : ${zl(D.manager.now)}</span></div>` : ''}
            </div>
            <div class="tm-card tm-scroll"><table class="table tm-table tm-costs"><thead><tr><th>Grupa / osoba</th><th class="text-end">Osoby</th><th class="text-end">Koszt pracodawcy / mies.</th>
                <th class="text-end">Sprzedaż grupy (prognoza)</th><th class="text-end">Brakuje sprzedaży do celu</th>
                ${B ? '<th>Próg premii</th><th class="text-end">Premia / osobę</th>' : ''}</tr></thead>
                <tbody>${rows || `<tr><td colspan="${cols}" class="at-empty">Brak grup.</td></tr>`}</tbody>
                <tfoot><tr><td>Razem</td><td class="text-end tm-num">${D.totals.people}</td><td class="text-end tm-num">${zl(D.totals.cost)}</td>
                    <td class="text-end tm-num">${zl(c.projection)}</td><td class="text-end tm-num">${zl(c.missing)}</td>
                    ${B ? `<td></td><td class="text-end tm-num">${zl(D.totals.bonus)}</td>` : ''}</tr></tfoot></table></div>
            <p class="tm-note">Koszt pracodawcy = miesięczny koszt osoby z karty płac. « Brakuje sprzedaży do celu » = ile sprzedaży grupy brakuje, żeby jej koszt personelu nie przekraczał celu (strona Rentowność).
                ${B ? `Premia osoby = premia progu × dni obecności ÷ dni pracy grupy (odbicia z tabletu biurowego) ; « Brakuje do progu » = sprzedaż grupy potrzebna do końca miesiąca (${D.remaining_days} dni roboczych), żeby osiągnąć próg.` : 'Premie pokazuje administrator (Premie zespołów › Kierownik produkcji).'}</p>
            ${(D.warnings || []).map(w => `<p class="tm-note">${esc(w)}</p>`).join('')}`;
    }

    function render() {
        ({day: renderDay, month: renderMonth, leave: renderLeave, overtime: renderOvertime, costs: renderCosts})[tab]();
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
        if (b.matches('[data-goods]')) { goodsDialog(Number(b.dataset.goods), b.dataset.goodsDay); return; }
        if (b.matches('[data-toggle-group]')) { const id = Number(b.dataset.toggleGroup); if (openGroups.has(id)) openGroups.delete(id); else openGroups.add(id); render(); return; }
        if (b.matches('[data-contacts]')) { contactsDialog(b.dataset.contacts || null); return; }
        if (b.matches('[data-mail-all]')) { mailSheets(b, null); return; }
        if (b.matches('[data-mail-one]')) { mailSheets(b, [Number(b.dataset.mailOne)]); return; }
        if (b.matches('[data-reopen-month]')) { if (await confirmBox('Otworzyć ponownie zamknięty miesiąc ?')) act(b, 'DELETE', `/ajax/time/months/${D.month}/close`); return; }
        if (b.matches('[data-add-absence]')) { absenceDialog(null, null); return; }
        if (b.matches('[data-request]')) { requestDetail(Number(b.dataset.request)); return; }
        if (b.matches('[data-pending-list]')) { pendingList(); return; }
        if (b.matches('[data-absence]')) { absenceDetail(Number(b.dataset.absence)); return; }
        if (b.matches('[data-leave-right]')) { leaveRightDialog(Number(b.dataset.leaveRight)); return; }
        if (b.matches('[data-comp]')) { compDialog(Number(b.dataset.comp)); return; }
        if (b.matches('[data-sign]')) { signDialog(Number(b.dataset.sign)); return; }
        if (b.matches('[data-cancel-comp]')) { if (await confirmBox('Anulować dzień wolny ?')) act(b, 'DELETE', `/ajax/time/comps/${b.dataset.cancelComp}`, null, 'fetch'); }
    });

    // [288] Klik w pustą komórkę kalendarza urlopów → « Dodaj urlop » dla tej osoby od tego dnia.
    body.addEventListener('click', (e) => {
        const td = e.target.closest('td[data-add-day]');
        if (td && tab === 'leave') absenceDialog(Number(td.dataset.addEmp), td.dataset.addDay);
    });

    render();
    // Link z dzwonka / listy wniosków : ?request=ID otwiera wniosek.
    const want = Number(new URLSearchParams(location.search).get('request'));
    if (tab === 'leave' && want) requestDetail(want);
})();
