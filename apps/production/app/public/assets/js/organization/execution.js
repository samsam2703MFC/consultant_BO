// Kto co zrobił [227] (makieta 3, przełożony) : stanowiska × dni (dziś / tydzień / miesiąc), kropka = zadanie z terminem
// w kolumnie (zrobione, zaliczone samo, w toku, spóźnione, do zrobienia), wiersz grup produkcji (tablety) i wiersz
// « System » (uruchomienia automatyczne), kafelki, lista spóźnionych z « Przypomnij » i szczegóły po kliknięciu kropki.
(() => {
    const app = document.getElementById('execApp');
    if (!app) return;
    const esc = at.esc;
    let D = JSON.parse(document.getElementById('execBoot').textContent);
    let pick = null;
    const reminded = new Set();
    const VIEWS = [['day', 'Dziś'], ['week', 'Tydzień'], ['month', 'Miesiąc']];
    const DAY_ABBR = ['niedz.', 'pn.', 'wt.', 'śr.', 'czw.', 'pt.', 'sob.'];
    const dm = (d) => d ? `${d.slice(8, 10)}.${d.slice(5, 7)}` : '';
    const dayLabel = (d) => d ? `${DAY_ABBR[new Date(d + 'T12:00:00').getDay()]} ${dm(d)}` : '';
    const hmOf = (ts) => ts ? String(ts).slice(11, 16) : '';
    const dur = (m) => !m ? '0' : (m < 60 ? `${m} min` : `${Math.floor(m / 60)} h` + (m % 60 ? ` ${String(m % 60).padStart(2, '0')}` : ''));
    const pct = (a, b) => b ? Math.round(a * 100 / b) : 0;
    const plural = (n, one, few, many) => n === 1 ? one : ([2, 3, 4].includes(n % 10) && ![12, 13, 14].includes(n % 100) ? few : many);

    function title() {
        const c = D.columns;
        if (D.view === 'day') return `Dziś · ${dayLabel(D.today)}`;
        if (D.view === 'week') {
            const w = new Date(c[0].from + 'T12:00:00');
            const jan4 = new Date(w.getFullYear(), 0, 4);
            const week = 1 + Math.round(((w - jan4) / 864e5 - 3 + ((jan4.getDay() + 6) % 7)) / 7);
            return `Tydzień ${week} · ${dm(c[0].from)} – ${dm(c[c.length - 1].to)}`;
        }
        const months = ['styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];
        return `${months[Number(D.today.slice(5, 7)) - 1]} ${D.today.slice(0, 4)}`;
    }

    function glyph(it, system) {
        if (it.state === 'auto') return system ? '<i class="bi bi-robot"></i>' : '✓';
        if (it.state === 'ok') return '✓';
        if (it.state === 'late') return '!';
        if (it.state === 'run') return it.step ? esc(it.step) : (it.closed !== undefined ? `${it.closed}/${it.days}` : '◐');
        return '';
    }
    const dotState = (s) => ({auto: 'ok', ok: 'ok', run: 'run', late: 'late', todo: 'todo', plan: 'plan'}[s] || 'todo');

    function dots(items, ref, system = false) {
        if (!items.length) return '<span class="ex-none">—</span>';
        return `<div class="ex-dots">${items.map((it, i) => `<button type="button" class="ex-dot ${dotState(it.state)}${pick === `${ref}-${i}` ? ' sel' : ''}" data-ref="${ref}-${i}"
            title="${esc(it.title || '')}" aria-label="${esc(it.title || '')}">${glyph(it, system)}</button>`).join('')}</div>`;
    }

    function rateHtml(done, total, extra = '') {
        const p = pct(done, total);
        return `<span class="ex-rate${total && p < 50 ? ' bad' : ''}">${total ? p + ' %' : '—'}<small>${done} z ${total}${extra}</small></span>`;
    }

    function tiles() {
        const t = D.tiles;
        const runsBad = t.runs_failed;
        return `<div class="kw-tiles ex-tiles">
            <div class="ok"><span>Zrobione</span><b>${t.done} z ${t.total}</b><small>${pct(t.done, t.total)} % · cel 90 %</small></div>
            <div><span>W toku</span><b>${t.running}</b><small>kreator rozpoczęty</small></div>
            <div class="${t.late ? 'late' : ''}"><span>Spóźnione</span><b>${t.late}</b><small>${t.escalated ? `${t.escalated} przełożony powiadomiony` : 'bez powiadomień'}</small></div>
            <div class="${runsBad ? 'late' : 'ok'}"><span>Automatycznie (cron)</span><b>${t.runs_ok} z ${t.runs}</b><small>${runsBad ? `${runsBad} ${plural(runsBad, 'błąd', 'błędy', 'błędów')}` : (t.runs ? 'bez błędów' : 'brak uruchomień')}</small></div>
            <div><span>Czas</span><b>${dur(t.done_minutes)}</b><small>z ${dur(t.minutes)} zaplanowanych</small></div>
        </div>`;
    }

    function table() {
        const head = D.columns.map(c => `<th class="c${c.today ? ' today' : ''}">${esc(c.label)}</th>`).join('');
        const body = D.rows.map((r, ri) => `<tr><td class="who"><b>${esc(r.name)}</b><small>${esc(r.people.join(', '))} · ${r.tasks} ${plural(r.tasks, 'zadanie', 'zadania', 'zadań')}</small></td>
            ${r.cells.map((items, ci) => `<td class="${D.columns[ci].today ? 'today' : ''}">${dots(items, `p${ri}-${ci}`)}</td>`).join('')}
            <td class="r">${rateHtml(r.done, r.total, r.late ? ` · ${r.late} ${plural(r.late, 'spóźnione', 'spóźnione', 'spóźnionych')}` : '')}</td></tr>`).join('');
        const tb = D.tablets;
        const tabRow = tb ? `<tr class="ex-sys"><td class="who"><b><i class="bi bi-tablet"></i> Produkcja — grupy (tablety)</b><small>${tb.groups} ${plural(tb.groups, 'grupa', 'grupy', 'grup')} · dzień zatwierdzony</small></td>
            ${tb.cells.map((items, ci) => `<td class="${D.columns[ci].today ? 'today' : ''}">${dots(items, `g-${ci}`)}</td>`).join('')}
            <td class="r"><span class="ex-rate">${tb.done} z ${tb.total}<small>dni zatwierdzone</small></span></td></tr>` : '';
        const runs = D.system.flat();
        const ok = runs.filter(x => x.state === 'auto').length;
        const sysRow = `<tr class="ex-sys"><td class="who"><b><i class="bi bi-robot"></i> System — zadania automatyczne</b><small>cron · ${runs.length} ${plural(runs.length, 'uruchomienie', 'uruchomienia', 'uruchomień')}</small></td>
            ${D.system.map((items, ci) => `<td class="${D.columns[ci].today ? 'today' : ''}">${dots(items, `s-${ci}`, true)}</td>`).join('')}
            <td class="r"><span class="ex-rate${runs.length - ok > 0 ? ' bad' : ''}">${ok} z ${runs.length}<small>${runs.some(x => x.state === 'late') ? 'są błędy' : 'bez błędów'}</small></span></td></tr>`;
        return `<div class="table-responsive"><table class="ex-t"><thead><tr><th>Stanowisko</th>${head}<th class="r">Wykonanie</th></tr></thead>
            <tbody>${body || `<tr><td colspan="${D.columns.length + 2}" class="at-empty">Nikt nie ma przypisanego stanowiska z zadaniami.</td></tr>`}${tabRow}${sysRow}</tbody></table></div>`;
    }

    function item(ref) {
        const [kind, ...rest] = ref.split('-');
        if (kind.startsWith('p')) {
            const ri = Number(kind.slice(1));
            const [ci, ii] = rest.map(Number);
            const r = D.rows[ri];
            return r && {...r.cells[ci][ii], who: r.name, people: r.people};
        }
        const [ci, ii] = rest.map(Number);
        if (kind === 'g') return D.tablets && {...D.tablets.cells[ci][ii], who: 'Produkcja — grupa (tablet)', group: true};
        return {...D.system[ci][ii], who: 'System (cron)', system: true};
    }

    function remindBtn(o) {
        if (!o) return '';
        const sent = reminded.has(o);
        return `<button type="button" class="kw-btn" data-remind="${o}" ${sent ? 'disabled' : ''}>${sent ? '<i class="bi bi-check2"></i> Wysłane' : '<i class="bi bi-bell"></i> Przypomnij'}</button>`;
    }

    function popHtml() {
        const it = pick && item(pick);
        if (!it) return '<div class="ex-pop empty"><span class="at-sub">Kliknij kropkę, aby zobaczyć szczegóły zadania.</span></div>';
        let state = '';
        if (it.system) state = it.state === 'auto' ? `<i class="bi bi-check-circle-fill ok"></i> wykonane automatycznie ${hmOf(it.at)}` : (it.state === 'late' ? `<i class="bi bi-x-circle-fill bad"></i> błąd ${hmOf(it.at)}` : `w toku od ${hmOf(it.at)}`);
        else if (it.group) state = it.state === 'plan' ? 'zaplanowane' : (it.days <= 1 ? (it.closed ? `<i class="bi bi-check-circle-fill ok"></i> dzień zatwierdzony ${esc(it.at || '')}` : 'dzień niezatwierdzony') : `${it.closed} z ${it.days} dni zatwierdzone`);
        else if (it.state === 'auto') state = `<i class="bi bi-check-circle-fill ok"></i> zaliczone samo ${hmOf(it.done_at)}`;
        else if (it.state === 'ok') state = `<i class="bi bi-check-circle-fill ok"></i> zrobione${it.done_by ? ' — ' + esc(it.done_by) : ''} · ${dm(String(it.done_at || '').slice(0, 10))} ${hmOf(it.done_at)}`;
        else if (it.state === 'run') state = `w toku · krok ${esc(it.step || '')}${it.step_label ? ' · ' + esc(it.step_label) : ''}`;
        else if (it.state === 'late') state = `<span class="kw-badge bad">spóźnione ${it.late_days} ${plural(it.late_days, 'dzień', 'dni', 'dni')}</span>${it.escalated ? ' <span class="kw-badge bad">przełożony powiadomiony</span>' : ''}`;
        else state = it.state === 'plan' ? `zaplanowane · termin ${dayLabel(it.due)}` : `do zrobienia · termin ${dayLabel(it.due)}`;
        const actionable = !it.system && !it.group && ['late', 'todo', 'run'].includes(it.state);
        return `<div class="ex-pop"><span class="at-sub">${esc(it.who)}${it.due ? ' · ' + dayLabel(it.due) : ''}</span>
            <b>${esc(it.title || '')}</b><span>${state}</span>
            ${it.people ? `<span class="at-sub">${esc(it.people.join(', '))}${it.minutes ? ' · ' + dur(it.minutes) + ' planowane' : ''}</span>` : ''}
            ${it.summary ? `<span>${esc(it.summary)}</span>` : ''}${it.error ? `<span class="text-danger">${esc(it.error)}</span>` : ''}${it.note && it.state === 'ok' ? `<span class="at-sub">${esc(it.note)}</span>` : ''}
            <span class="ex-pop-acts">${it.link ? `<a href="${esc(at.root + it.link)}">Otwórz ${it.state === 'auto' || it.state === 'ok' ? 'wynik' : 'kreator'} →</a>` : ''}<span class="wk-sp"></span>${actionable ? remindBtn(it.occurrence) : ''}</span></div>`;
    }

    function lateHtml() {
        if (!D.late.length) return '<p class="at-empty mb-0"><i class="bi bi-check2-circle"></i> Nic spóźnionego.</p>';
        return `<div class="ex-late">${D.late.map(it => `<div class="it"><b>${esc(it.title)}</b><span>${esc(it.position)} · termin ${dayLabel(it.due)}${it.count > 1 ? ` · ${it.count}× niezrobione` : ''}</span>
            <span class="ex-row"><span class="kw-badge bad">${it.escalated ? 'przełożony powiadomiony · ' : ''}${it.late_days} ${plural(it.late_days, 'dzień', 'dni', 'dni')}</span><span class="wk-sp"></span>${remindBtn(it.occurrence)}</span></div>`).join('')}</div>`;
    }

    function render() {
        app.innerHTML = `<div class="kw"><div class="kw-card">
            <h5>${esc(title())} <span class="wk-sp"></span><span class="wp-seg" role="group">${VIEWS.map(([v, l]) => `<button type="button" class="${v === D.view ? 'on' : ''}" data-view="${v}" aria-pressed="${v === D.view}">${l}</button>`).join('')}</span></h5>
            ${tiles()}
            <div class="ex-grid"><div>${table()}</div>
                <div class="ex-side"><div class="kw-card ex-lc"><h5>Spóźnione · ${D.late.length}${D.tiles.late > D.late.length ? ` <small class="at-sub">(${D.tiles.late} terminów)</small>` : ''}</h5>${lateHtml()}</div><div data-pop>${popHtml()}</div></div></div>
            <div class="wk-legend"><span><span class="ic ok">✓</span>zrobione / zaliczone samo</span><span><span class="ic ok"><i class="bi bi-robot"></i></span>wykonane automatycznie</span>
                <span><span class="ic run">◐</span>w toku</span><span><span class="ic late">!</span>spóźnione</span><span><span class="ic"></span>do zrobienia</span></div>
        </div></div>`;
    }

    async function setView(view) {
        app.querySelectorAll('[data-view]').forEach(b => { b.disabled = true; });
        const res = await fetch(at.root + '/ajax/organization/execution?view=' + encodeURIComponent(view), {headers: {Accept: 'application/json'}}).then(r => r.json()).catch(() => null);
        if (!res || !res.data) { at.notify(false, res?.message || 'Błąd ładowania.'); render(); return; }
        D = res.data;
        pick = null;
        render();
        try { history.replaceState(null, '', '?view=' + view); } catch (e) { /* bez historii */ }
    }

    app.addEventListener('click', async (e) => {
        const v = e.target.closest('[data-view]');
        if (v) return setView(v.dataset.view);
        const dot = e.target.closest('[data-ref]');
        if (dot) {
            pick = dot.dataset.ref;
            app.querySelectorAll('.ex-dot.sel').forEach(x => x.classList.remove('sel'));
            dot.classList.add('sel');
            app.querySelector('[data-pop]').innerHTML = popHtml();
            if (window.matchMedia('(max-width: 1199.98px)').matches) app.querySelector('[data-pop]').scrollIntoView({behavior: 'smooth', block: 'nearest'});
            return;
        }
        const rb = e.target.closest('[data-remind]');
        if (rb) {
            rb.disabled = true;
            const res = await at.send(`/ajax/organization/execution/remind/${rb.dataset.remind}`, 'POST', {});
            at.notify(res.ok, res.message || (res.ok ? 'Wysłane.' : 'Błąd.'));
            if (res.ok) {
                reminded.add(Number(rb.dataset.remind));
                app.querySelectorAll(`[data-remind="${rb.dataset.remind}"]`).forEach(b => { b.disabled = true; b.innerHTML = '<i class="bi bi-check2"></i> Wysłane'; });
            } else rb.disabled = false;
        }
    });

    render();
})();
