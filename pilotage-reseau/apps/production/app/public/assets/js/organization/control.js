/* [281] Kontrola (profil CEO, makieta 47) : « Przypomnij » (dzwonek osoby — istniejące wywołanie Kto co zrobił),
   klik w komórkę tygodnia → lista zadań dnia (kto, kiedy, uwaga z asystenta), odświeżenie co 5 minut. */
(() => {
    const app = document.getElementById('controlApp');
    if (!app) return;
    const esc = (v) => at.esc(v);
    const weekEl = document.getElementById('ctWeek');
    const rows = weekEl ? JSON.parse(weekEl.textContent || '[]') : [];
    const detail = document.getElementById('ctDetail');
    const STATE = {ok: 'zrobione', auto: 'zaliczone samo', run: 'w toku', late: 'spóźnione', todo: 'do zrobienia', plan: 'zaplanowane'};

    app.addEventListener('click', async (e) => {
        const rb = e.target.closest('[data-remind]');
        if (rb) {
            const ids = String(rb.dataset.remind).split(',').filter(Boolean);
            rb.disabled = true;
            let ok = true, msg = '';
            for (const id of ids) {
                const res = await at.send(`/ajax/organization/execution/remind/${encodeURIComponent(id)}`, 'POST', {});
                if (!res.ok) { ok = false; msg = res.message || 'Nie udało się wysłać przypomnienia.'; break; }
            }
            if (ok) rb.innerHTML = '<i class="bi bi-check2"></i> Wysłane';
            else { rb.disabled = false; at.notify(false, msg); }
            return;
        }
        const cb = e.target.closest('[data-cell]');
        if (cb && detail) {
            const [r, c] = cb.dataset.cell.split('-').map(Number);
            const row = rows[r];
            const cell = row && row.cells[c];
            if (!cell) return;
            const open = detail.dataset.open === cb.dataset.cell && !detail.hidden;
            app.querySelectorAll('[data-cell].on').forEach(b => b.classList.remove('on'));
            if (open) { detail.hidden = true; detail.dataset.open = ''; return; }
            cb.classList.add('on');
            const head = app.querySelectorAll('.ct-week thead th')[c + 1];
            detail.innerHTML = `<div class="ct-detail-head"><b>${esc(row.name)}</b> · ${esc(head ? head.textContent : '')} · ${cell.done}/${cell.total}${cell.late ? ` · ${cell.late} z opóźnieniem` : ''}</div>`
                + (cell.items.length ? '<ul>' + cell.items.map(it => `<li><span class="ct-dot ${esc(it.state)}${it.late ? ' late' : ''}"></span><b>${esc(it.title)}</b>`
                    + `<small>${esc(STATE[it.state] || it.state)}${it.late ? ' (po terminie)' : ''}${it.who ? ' · ' + esc(it.who) : ''}${it.at ? ' · ' + esc(it.at) : ''}${it.note ? ' · « ' + esc(it.note) + ' »' : ''}</small></li>`).join('') + '</ul>'
                    : '<p class="ct-mute m-0">Brak zadań tego dnia.</p>');
            detail.hidden = false;
            detail.dataset.open = cb.dataset.cell;
        }
    });

    // Na żywo : odświeżenie co 5 minut, gdy karta jest widoczna i nic nie jest rozwinięte.
    setInterval(() => {
        if (document.visibilityState === 'visible' && (!detail || detail.hidden)) location.reload();
    }, 5 * 60 * 1000);
})();
