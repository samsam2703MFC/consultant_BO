/*
 * [275] Wejście do ERP przez QR (makieta 40). Ekran « Dzień dobry » (#erpEntry) : kod osobisty na dziś, odnawiany sam ;
 * po odbiciu wejścia telefonem → zielone « Wejście 07:58 » (2 s) → ERP. Pasek u góry (#erpDay) : « W pracy od … » i
 * « Zakończ dzień » → okno z czasem dnia i tym samym kodem → po odbiciu wyjścia telefonem (pytanie o towar) ERP się wylogowuje.
 */
(function () {
    'use strict';
    const pad = (n) => String(n).padStart(2, '0');
    const hm = (m) => Math.floor(m / 60) + ' h ' + pad(m % 60);

    function draw(canvas, q) {
        const quiet = 4, total = q.size + 2 * quiet, scale = Math.max(2, Math.floor(560 / total));
        canvas.width = canvas.height = total * scale;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = '#000';
        q.rows.forEach((row, y) => { for (let x = 0; x < row.length; x++) if (row[x] === '1') ctx.fillRect((x + quiet) * scale, (y + quiet) * scale, scale, scale); });
    }

    async function get(root, path) {
        const r = await fetch(root + path, {credentials: 'same-origin', headers: {'Accept': 'application/json'}});
        const j = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(j.message || ('Błąd ' + r.status));
        return j.data;
    }

    /** Kod + oczekiwanie na odbicie ; onDone(status) po odbiciu w oczekiwanym kierunku. Zwraca funkcję zatrzymującą. */
    function watch(root, scope, want, onDone) {
        const canvas = scope.querySelector('[data-ee-qr]');
        const wait = scope.querySelector('[data-ee-wait]');
        const meta = scope.querySelector('[data-ee-meta]');
        const base = new URL(root + '/', window.location.href).href.replace(/\/$/, '');
        let since = null, expires = 0, stop = false, timer = null, poll = null;
        async function refresh() {
            try {
                const q = await get(root, '/ajax/erp-entry/qr?base=' + encodeURIComponent(base));
                if (stop) return;
                if (!since) since = q.since;
                draw(canvas, q);
                expires = Date.now() + q.expires_in * 1000;
                clearTimeout(timer);
                timer = setTimeout(refresh, q.refresh_s * 1000);
                wait.classList.remove('ee-err');
            } catch (e) { wait.textContent = e.message; wait.classList.add('ee-err'); timer = setTimeout(refresh, 10000); }
        }
        async function check() {
            if (stop || !since) return;
            try {
                const s = await get(root, '/ajax/erp-entry/status?since=' + encodeURIComponent(since));
                if (s.clocked && s.direction === want) { stop = true; clearTimeout(timer); clearInterval(poll); onDone(s); }
            } catch (e) { /* następna próba */ }
        }
        const tick = setInterval(() => {
            if (stop) { clearInterval(tick); return; }
            const left = Math.max(0, Math.round((expires - Date.now()) / 1000));
            if (meta && expires) meta.textContent = 'kod ważny jeszcze ' + Math.floor(left / 60) + ':' + pad(left % 60);
        }, 1000);
        refresh();
        poll = setInterval(check, 2000);
        return () => { stop = true; clearTimeout(timer); clearInterval(poll); clearInterval(tick); };
    }

    // --- Ekran « Dzień dobry » (wejście) ---
    const entry = document.getElementById('erpEntry');
    if (entry) {
        const root = (entry.dataset.root || '').replace(/\/$/, '');
        const next = entry.dataset.next || '/home';
        const now = new Date();
        const days = ['niedz.', 'pon.', 'wt.', 'śr.', 'czw.', 'pt.', 'sob.'];
        const months = ['stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'];
        entry.querySelector('[data-ee-wait]').textContent = 'Czekam na skan · ' + days[now.getDay()] + ' ' + now.getDate() + ' ' + months[now.getMonth()] + ' ' + now.getFullYear();
        watch(root, entry, 'in', (s) => {
            const done = entry.querySelector('[data-ee-done]');
            entry.querySelector('[data-ee-done-text]').textContent = 'Wejście ' + (s.at || '');
            done.hidden = false;
            entry.querySelector('[data-ee-wait]').textContent = 'Wejście zapisane — otwieram ERP…';
            setTimeout(() => { window.location.href = root + next; }, 2000);
        });
    }

    // --- Pasek u góry : « W pracy od … » + « Zakończ dzień » ---
    const bar = document.getElementById('erpDay');
    if (bar) {
        const root = (bar.dataset.root || '').replace(/\/$/, '');
        bar.querySelector('[data-ee-end]').addEventListener('click', () => endDay(root).catch((e) => alert(e.message)));
    }

    async function endDay(root) {
        const st = await get(root, '/ajax/erp-entry/state');
        let dlg = document.querySelector('.ee-dialog');
        if (!dlg) { dlg = document.createElement('dialog'); dlg.className = 'ee-dialog'; document.body.appendChild(dlg); }
        dlg.innerHTML = `<div class="ee-dlg"><div><h4>Zakończ dzień</h4>
                <dl><dt>Wejście</dt><dd>${st.since || '—'}</dd><dt>Teraz</dt><dd>${st.now}</dd><dt>Dziś</dt><dd>${hm(st.minutes)}${st.overtime_minutes ? ' <span style="font-weight:400;color:#777">· nadgodziny ' + hm(st.overtime_minutes) + '</span>' : ''}</dd></dl>
                <p>Zeskanuj kod telefonem i potwierdź wyjście (pytanie o towar). Potem ERP się wyloguje.</p>
                <p class="ee-wait" data-ee-wait>Czekam na skan…</p><p class="ee-meta" data-ee-meta></p>
                <button type="button" class="ee-cancel" data-ee-cancel>Anuluj</button></div>
            <div class="ee-qr"><canvas data-ee-qr aria-label="Kod QR wyjścia"></canvas><div class="ee-done is-out" data-ee-done hidden><span>✓</span><b data-ee-done-text>Wyjście</b></div></div></div>`;
        const stop = watch(root, dlg, 'out', (s) => {
            dlg.querySelector('[data-ee-done-text]').textContent = 'Wyjście ' + (s.at || '');
            dlg.querySelector('[data-ee-done]').hidden = false;
            dlg.querySelector('[data-ee-wait]').textContent = 'Do jutra ! Wylogowuję…';
            setTimeout(() => { window.location.href = root + '/logout'; }, 2000);
        });
        dlg.querySelector('[data-ee-cancel]').addEventListener('click', () => { stop(); dlg.close(); });
        dlg.addEventListener('close', stop, {once: true});
        dlg.showModal();
    }
})();
