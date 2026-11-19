/*
 * [300] Logowanie do ERP kodem QR (makieta 55). Strona logowania : kod dla tej przeglądarki (odnawiany sam) ; osoba
 * skanuje go telefonem, odbija wejście i zatwierdza na telefonie → ta strona otwiera jej sesję (konto powiązane z osobą).
 * Ekran po prawej (#loginSections) : sekcja → nazwisko → kod tej osoby (tylko jej telefon go zatwierdza).
 * « Nie mam telefonu » : formularz hasła (zapasowy).
 */
(function () {
    'use strict';
    const box = document.getElementById('loginQr');
    const pw = document.getElementById('loginPassword');
    if (!box || !pw) return;
    const root = (box.dataset.root || '').replace(/\/$/, '');
    const base = (window.location.origin + new URL(root + '/', window.location.href).pathname).replace(/\/$/, '');
    const canvas = box.querySelector('[data-lq-qr]');
    const meta = box.querySelector('[data-lq-meta]');
    const pad = (n) => String(n).padStart(2, '0');
    const choose = !!box.dataset.choose;
    const who = box.querySelector('[data-lq-who]');
    const empty = box.querySelector('[data-lq-empty]');
    const sections = document.getElementById('loginSections');
    let employee = null;
    let timer = null, poll = null, tick = null, expires = 0, done = false;

    function draw(q) {
        const quiet = 4, total = q.size + 2 * quiet, scale = Math.max(2, Math.floor(520 / total));
        canvas.width = canvas.height = total * scale;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = '#000';
        q.rows.forEach((row, y) => { for (let x = 0; x < row.length; x++) if (row[x] === '1') ctx.fillRect((x + quiet) * scale, (y + quiet) * scale, scale, scale); });
    }

    async function get(path) {
        const r = await fetch(root + path, {credentials: 'same-origin', headers: {'Accept': 'application/json'}});
        const j = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(j.message || ('Błąd ' + r.status));
        return j.data;
    }

    async function refresh() {
        try {
            const q = await get('/ajax/login-qr?base=' + encodeURIComponent(base) + (employee ? '&employee=' + employee : ''));
            if (done) return;
            draw(q);
            expires = Date.now() + q.expires_in * 1000;
            clearTimeout(timer);
            timer = setTimeout(refresh, q.refresh_s * 1000);
            meta.classList.remove('is-err');
        } catch (e) {
            meta.textContent = e.message; meta.classList.add('is-err');
            clearTimeout(timer); timer = setTimeout(refresh, 15000);
        }
    }

    async function check() {
        if (done || box.hidden) return;
        try {
            const s = await get('/ajax/login-qr/status');
            if (!s.done) return;
            done = true; stop();
            box.querySelector('[data-lq-done]').hidden = false;
            meta.textContent = 'Zatwierdzone na telefonie — otwieram ERP…';
            setTimeout(() => { window.location.href = s.redirect; }, 900);
        } catch (e) { /* następna próba */ }
    }

    function start() {
        if (done || (choose && !employee)) return;
        refresh();
        poll = setInterval(check, 2000);
        tick = setInterval(() => {
            if (!expires || meta.classList.contains('is-err')) return;
            const left = Math.max(0, Math.round((expires - Date.now()) / 1000));
            meta.textContent = 'Kod odświeża się sam · ważny ' + Math.floor(left / 60) + ':' + pad(left % 60);
        }, 1000);
    }

    function stop() { clearTimeout(timer); clearInterval(poll); clearInterval(tick); }

    box.querySelector('[data-lq-password]').addEventListener('click', () => {
        stop(); box.hidden = true; pw.hidden = false;
        const f = pw.querySelector('input[name=login]'); if (f) f.focus();
    });
    pw.querySelector('[data-lq-back]').addEventListener('click', () => { pw.hidden = true; box.hidden = false; start(); });
    // Sekcja → nazwisko : kod tej osoby po lewej (nowy kod przy zmianie osoby).
    if (sections) {
        sections.addEventListener('click', (e) => {
            const tab = e.target.closest('[data-lq-sec]');
            if (tab) {
                sections.querySelectorAll('[data-lq-sec]').forEach(b => b.classList.toggle('is-on', b === tab));
                sections.querySelectorAll('[data-lq-people]').forEach(d => { d.hidden = d.dataset.lqPeople !== tab.dataset.lqSec; });
                return;
            }
            const b = e.target.closest('[data-lq-person]');
            if (!b || done) return;
            sections.querySelectorAll('[data-lq-person]').forEach(x => x.classList.toggle('is-on', x === b));
            employee = b.dataset.lqPerson;
            who.textContent = 'Kod dla : ' + b.dataset.name + ' · ' + b.dataset.section;
            who.hidden = false; empty.hidden = true;
            pw.hidden = true; box.hidden = false;
            stop(); expires = 0; meta.textContent = 'Wczytywanie kodu…';
            start();
        });
    }
    if (!box.hidden) start();
})();
