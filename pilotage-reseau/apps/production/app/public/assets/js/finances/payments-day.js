// Płatności dnia [230] (makieta 5) : 1. Wpłaty — proformy niezapłacone, zaznacz opłacone (data i kwota z wyciągu) ;
// 2. Przypomnienia — na które przyszedł czas (automatyczne wg ustawienia, pozostałe : podgląd i « Wyślij ») ;
// 3. Różnice dostaw — dopłaty, zwroty, problemy dostaw (decyzje na stronie różnic) → « Zakończ » = zadanie zaliczone.
(() => {
    const app = document.getElementById('payApp');
    if (!app) return;
    const esc = at.esc;
    let D = JSON.parse(document.getElementById('payBoot').textContent);
    let step = 1;
    let filter = 'all';
    let showAll = false;
    const picked = new Map();   // id → {paid_on, amount}
    let mailProofs = null;       // Wpłata z e-maila [233] : potwierdzenia z Poczty do sprawdzenia
    const money = (v) => v === null || v === undefined ? '—' : Number(v).toLocaleString('pl-PL', {minimumFractionDigits: 0, maximumFractionDigits: 2}) + ' €';
    const dm = (d) => d ? `${d.slice(8, 10)}.${d.slice(5, 7)}` : '';
    const plural = (n, one, few, many) => n === 1 ? one : ([2, 3, 4].includes(n % 10) && ![12, 13, 14].includes(n % 100) ? few : many);
    const num = (v) => { const x = Number(String(v ?? '').replace(/\s/g, '').replace(',', '.')); return String(v ?? '').trim() === '' || !Number.isFinite(x) ? null : x; };
    const LIMIT = 8;

    function steps() {
        const T = D.totals;
        const s = (n, label, sub) => `<button type="button" class="s ${step === n ? 'on' : (step > n || (n === 3 && D.done) ? 'ok' : '')}" data-step="${n}"><span class="n">${step > n || (D.done && step !== n) ? '✓' : n}</span><span>${label}<small>${sub}</small></span></button>`;
        return `<div class="rcv-steps">${s(1, 'Wpłaty', `${T.count} ${plural(T.count, 'proforma czeka', 'proformy czekają', 'proform czeka')}`)}
            ${s(2, 'Przypomnienia', `${T.reminders_due} do wysłania dziś`)}
            ${s(3, 'Różnice dostaw', `${T.extra} ${plural(T.extra, 'dopłata', 'dopłaty', 'dopłat')} · ${T.refund} ${plural(T.refund, 'zwrot', 'zwroty', 'zwrotów')}${T.delivery ? ' · ' + T.delivery + ' dost.' : ''}`)}</div>`;
    }

    function side() {
        const T = D.totals;
        const rem = D.reminders.slice(0, 5).map(r => `<span><span class="kw-badge ${r.next_level >= 3 ? 'bad' : 'warn'}">poziom ${r.next_level}</span> ${esc(r.number)} · ${esc(r.client)}${r.auto ? ' · auto' : ''}</span>`).join('');
        return `<div class="rcv-side"><div class="kw-card rcv-sum"><h5>Należności</h5>
            <div class="rcv-kv"><span>Niezapłacone</span><b>${money(T.unpaid)}</b></div>
            <div class="rcv-kv"><span>Po terminie (&gt; ${D.overdue_days} dni)</span><b class="is-bad">${money(T.overdue)}</b></div>
            <div class="rcv-kv"><span>Opłacone dziś</span><b class="is-ok">${money(T.paid_today)}</b></div>
            <div class="rcv-kv"><span>Średnio dni do zapłaty</span><b>${T.avg_days === null ? '—' : String(T.avg_days).replace('.', ',')}</b></div></div>
            <div class="kw-card"><h5 class="rcv-h">Krok 2 · Przypomnienia dziś</h5><div class="rcv-list">${rem || '<span class="at-sub">Nic do wysłania dziś.</span>'}
                <span class="at-sub">${D.auto_levels ? `Poziomy 1–${D.auto_levels} automatyczne (ustawienie) ; ${D.auto_levels + 1}–5 : podgląd i « Wyślij » po kolei.` : 'Wysyłka automatyczna wyłączona : każde przypomnienie — podgląd i « Wyślij ».'}</span></div></div>
            ${D.done ? `<div class="kw-card"><h5 class="rcv-h"><i class="bi bi-check-circle"></i> Zadanie zaliczone ${esc(D.done.at)}</h5><div class="at-sub">${esc(D.done.summary)}</div></div>` : ''}</div>`;
    }

    // Wpłata z e-maila [233] (makieta 9) : « Z poczty : potwierdzenia do sprawdzenia » — wiadomości od klientów z proformą
    // niezapłaconą i załącznikiem ; « Sprawdź » = okno odczytu i zatwierdzenia (payment-proof.js).
    function mailCard() {
        if (!mailProofs || !window.tfbPaymentProof) return '';
        const rows = mailProofs.rows;
        const chip = (r) => ({ok: '<span class="kw-badge ok">kwota zgodna</span>', diff: `<span class="kw-badge warn">różnica ${r.diff > 0 ? '+' : ''}${money(r.diff)}</span>`,
            no_amount: '<span class="kw-badge">brak kwoty — do sprawdzenia</span>', no_match: '<span class="kw-badge">do sprawdzenia</span>', unread: '<span class="kw-badge">nieodczytane</span>'}[r.state]);
        const body = rows.map(r => `<tr><td><i class="bi ${r.proof ? (/\.pdf$/i.test(r.proof.filename || '') ? 'bi-file-earmark-pdf' : 'bi-file-earmark-image') : 'bi-envelope-paper'}"></i></td>
            <td><b>${esc(r.subject || '(bez tematu)')}</b><small>${esc(r.customer.name || '')} · ${esc(r.date ? r.date.slice(8, 10) + '.' + r.date.slice(5, 7) + ' ' + r.date.slice(11, 16) : '')}${r.proof ? ' · ' + esc(r.proof.filename || '') : ''}</small></td>
            <td>${r.match.length ? esc(r.match.join(', ')) : `<span class="at-sub">${r.unpaid.count} ${plural(r.unpaid.count, 'proforma', 'proformy', 'proform')} do zapłaty</span>`}
                <small>${r.proof ? (r.proof.amount !== null ? `odczytano : ${money(r.proof.amount)}${r.proof.date ? ' · ' + dm(r.proof.date) : ''}` : 'brak kwoty przelewu') : 'załącznik jeszcze nieodczytany'}</small></td>
            <td class="r"><b>${r.expected !== null ? money(r.expected) : (r.proof ? '—' : money(r.unpaid.sum))}</b><small>${chip(r)}</small></td>
            <td class="r"><button type="button" class="kw-btn ${r.state === 'ok' ? 'pri' : ''}" data-proof-mail="${r.mail_id}" data-proof="${r.proof ? r.proof.id : ''}">Sprawdź</button></td></tr>`).join('');
        return `<div class="kw-card pp-card"><h5 class="rcv-h"><i class="bi bi-envelope-check"></i> Z poczty : ${rows.length} ${plural(rows.length, 'potwierdzenie', 'potwierdzenia', 'potwierdzeń')} do sprawdzenia
                ${rows.length ? '<span class="kw-badge">nowe</span>' : ''}<span class="rcv-sp"></span><a class="at-sub" href="${esc(at.root)}/mail">Poczta</a></h5>
            ${rows.length ? `<div class="table-responsive"><table class="rcv-tbl"><tbody>${body}</tbody></table></div>`
                : `<p class="at-empty">Brak wiadomości od klientów z potwierdzeniem przelewu (ostatnie ${mailProofs.days} dni, Twoja skrzynka).</p>`}</div>`;
    }

    function stepPaid() {
        const T = D.totals;
        let rows = D.proformas;
        if (filter === 'late') rows = rows.filter(p => p.days > D.overdue_days);
        if (filter === 'due') rows = rows.filter(p => p.due);
        const shown = showAll ? rows : rows.slice(0, LIMIT);
        const sum = [...picked.values()].reduce((a, p) => a + (num(p.amount) || 0), 0);
        const seg = [['all', 'Wszystkie'], ['late', 'Po terminie'], ['due', 'Do przypomnienia']].map(([k, l]) => `<button type="button" class="${filter === k ? 'is-on' : ''}" data-filter="${k}">${l}</button>`).join('');
        const body = shown.map(p => {
            const sel = picked.get(p.id);
            const rem = p.level ? `<span class="kw-badge ${p.level >= 2 ? 'bad' : 'warn'}">${p.level} ${plural(p.level, 'wysłane', 'wysłane', 'wysłanych')}${p.last_reminder ? ' ' + dm(p.last_reminder) : ''}</span>` : '<span class="kw-badge">—</span>';
            return `<tr data-id="${p.id}"><td><input type="checkbox" data-pick ${sel ? 'checked' : ''} aria-label="Opłacona ${esc(p.number)}"></td>
                <td><b>${esc(p.number)}</b><small>${dm(p.date)}</small></td><td>${esc(p.client)}</td><td class="r">${money(p.amount)}</td>
                <td class="r">${p.days > D.overdue_days ? `<span class="rcv-up">${p.days} dni</span>` : `${p.days} dni`}</td><td>${rem}</td>
                <td>${sel ? `<div class="rcv-row"><input type="date" class="form-control" data-paid-on value="${esc(sel.paid_on)}" max="${D.day}" aria-label="Data wpłaty" style="max-width:150px">
                    <input class="form-control" inputmode="decimal" data-paid-amount value="${esc(String(sel.amount).replace('.', ','))}" aria-label="Kwota" style="max-width:110px"></div>` : '<span class="at-sub">—</span>'}</td></tr>`;
        }).join('');
        const more = rows.length > shown.length ? `<tr><td colspan="7"><button type="button" class="kw-btn ghost" data-more>+ ${rows.length - shown.length} ${plural(rows.length - shown.length, 'proforma', 'proformy', 'proform')} (pokaż)</button></td></tr>` : '';
        const paidToday = D.paid_today.length ? `<div class="at-sub" style="margin-top:8px">Dziś zapisane : ${D.paid_today.map(p => `${esc(p.number)} ${p.amount ? money(p.amount) : ''}`).join(' · ')}</div>` : '';
        return `${mailCard()}<div class="kw-card"><h5 class="rcv-h">Które proformy są opłacone? <span class="kw-badge">${T.count} ${plural(T.count, 'niezapłacona', 'niezapłacone', 'niezapłaconych')} · ${money(T.unpaid)}</span><span class="rcv-sp"></span><span class="at-seg">${seg}</span></h5>
            ${rows.length ? `<div class="table-responsive"><table class="rcv-tbl"><thead><tr><th></th><th>Proforma</th><th>Klient</th><th class="r">Kwota</th><th class="r">Od wystawienia</th><th>Przypomnienia</th><th>Wpłata</th></tr></thead><tbody>${body}${more}</tbody></table></div>`
                : '<p class="at-empty">Brak proform w tym widoku.</p>'}${paidToday}
            <div class="rcv-row" style="margin-top:10px"><span class="at-sub">Zaznacz opłacone (data i kwota z wyciągu).</span><span class="rcv-sp"></span>
                <button type="button" class="kw-btn ok" data-save-paid ${picked.size ? '' : 'disabled'}>Opłacone : ${picked.size} · ${money(sum)}</button>
                <button type="button" class="kw-btn pri" data-go="2">Dalej : przypomnienia <i class="bi bi-arrow-right"></i></button></div></div>`;
    }

    function stepReminders() {
        const rows = D.reminders.map(r => `<tr><td><span class="kw-badge ${r.next_level >= 3 ? 'bad' : 'warn'}">poziom ${r.next_level}</span></td><td><b>${esc(r.number)}</b><small>${dm(r.date)}</small></td>
            <td>${esc(r.client)}</td><td class="r">${money(r.amount)}</td><td class="r">${r.days} dni</td>
            <td>${r.auto ? '<span class="kw-badge ok">automatycznie (co 5 min)</span>' : `<a class="kw-btn kw-sm" href="${at.root}/communication/compose/reminder/proforma/${r.id}?type=proforma_reminder_${r.next_level}" target="_blank" rel="noopener"><i class="bi bi-eye"></i> Podgląd i wyślij</a>`}</td></tr>`).join('');
        return `<div class="kw-card"><h5 class="rcv-h">Przypomnienia na dziś <span class="kw-badge ${D.reminders.length ? 'warn' : 'ok'}">${D.reminders.length}</span><span class="rcv-sp"></span><span class="at-sub">wysłane dziś : ${D.totals.reminders_sent}</span></h5>
            ${rows ? `<div class="table-responsive"><table class="rcv-tbl"><thead><tr><th>Poziom</th><th>Proforma</th><th>Klient</th><th class="r">Kwota</th><th class="r">Od wystawienia</th><th></th></tr></thead><tbody>${rows}</tbody></table></div>`
                : '<p class="at-empty">Nic do wysłania dziś — poziomy po 3 / 7 / 14 / 21 / 30 dniach od wystawienia.</p>'}
            <p class="at-sub">E-mail w języku klienta (szablony 1–5) ; po wysłaniu poziom proformy rośnie, a przypomnienie znika z tej listy.</p>
            <div class="rcv-row"><button type="button" class="kw-btn ghost" data-go="1"><i class="bi bi-arrow-left"></i> Wpłaty</button><span class="rcv-sp"></span>
                <button type="button" class="kw-btn" data-reload><i class="bi bi-arrow-clockwise"></i> Odśwież</button>
                <button type="button" class="kw-btn pri" data-go="3">Dalej : różnice dostaw <i class="bi bi-arrow-right"></i></button></div></div>`;
    }

    function stepDiffs() {
        const label = {extra: ['dopłata', 'warn'], refund: ['zwrot', 'bad'], delivery: ['dostawa', 'new']};
        const rows = D.differences.map(d => `<tr><td><span class="kw-badge ${label[d.kind][1]}">${label[d.kind][0]}</span></td><td><b>${esc(d.client || '—')}</b><small>${esc(d.wdt || d.reason || '')}</small></td>
            <td class="r">${d.amount === null ? '—' : money(d.amount)}</td><td>${d.status === 'open' ? '<span class="kw-badge bad">do decyzji</span>' : '<span class="kw-badge warn">w realizacji</span>'}</td>
            <td>${d.due_on ? dm(d.due_on) : '—'}</td><td class="at-sub">${esc(d.owner || '—')}</td></tr>`).join('');
        return `<div class="kw-card"><h5 class="rcv-h">Różnice dostaw do rozliczenia <span class="kw-badge">${D.differences.length}</span><span class="rcv-sp"></span>
                <a class="kw-btn kw-sm" href="${at.root}/shipments/differences" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> Decyzje : różnice dostaw</a></h5>
            ${rows ? `<div class="table-responsive"><table class="rcv-tbl"><thead><tr><th></th><th>Klient</th><th class="r">Kwota</th><th>Stan</th><th>Termin</th><th>Odpowiada</th></tr></thead><tbody>${rows}</tbody></table></div>`
                : '<p class="at-empty">Brak otwartych różnic dostaw.</p>'}
            <div class="rcv-row" style="margin-top:10px"><button type="button" class="kw-btn ghost" data-go="2"><i class="bi bi-arrow-left"></i> Przypomnienia</button><span class="rcv-sp"></span>
                <button type="button" class="kw-btn pri" data-finish><i class="bi bi-check-lg"></i> ${D.done ? 'Zaktualizuj wynik dnia' : 'Zakończ : zadanie zaliczone'}</button></div></div>`;
    }

    function render() {
        const main = step === 1 ? stepPaid() : (step === 2 ? stepReminders() : stepDiffs());
        app.innerHTML = `<div class="rcv-wz">${steps()}<div class="rcv-mainc">${main}</div>${side()}</div>`;
        bind();
    }

    async function reload() {
        const r = await at.send('/ajax/finances/payments-day', 'GET');
        if (r.ok) { D = r.data; render(); } else at.notify(false, r.message);
        loadProofs();
    }

    async function loadProofs() {
        if (!window.tfbPaymentProof) return;
        const r = await at.send('/ajax/finances/payment-proofs', 'GET');
        mailProofs = r.ok && r.data ? r.data : {rows: [], days: 45, error: r.message};
        if (step === 1) render();
    }

    function bind() {
        app.querySelectorAll('[data-step]').forEach(b => b.addEventListener('click', () => { step = Number(b.dataset.step); render(); }));
        app.querySelectorAll('[data-go]').forEach(b => b.addEventListener('click', () => { step = Number(b.dataset.go); render(); }));
        app.querySelectorAll('[data-filter]').forEach(b => b.addEventListener('click', () => { filter = b.dataset.filter; render(); }));
        const more = app.querySelector('[data-more]');
        if (more) more.addEventListener('click', () => { showAll = true; render(); });
        app.querySelectorAll('[data-pick]').forEach(cb => cb.addEventListener('change', () => {
            const id = Number(cb.closest('tr').dataset.id);
            const p = D.proformas.find(x => x.id === id);
            if (cb.checked) picked.set(id, {paid_on: D.day, amount: p.amount}); else picked.delete(id);
            render();
        }));
        app.querySelectorAll('[data-paid-on]').forEach(i => i.addEventListener('change', () => { picked.get(Number(i.closest('tr').dataset.id)).paid_on = i.value; }));
        app.querySelectorAll('[data-paid-amount]').forEach(i => i.addEventListener('input', () => {
            picked.get(Number(i.closest('tr').dataset.id)).amount = i.value;
            const sum = [...picked.values()].reduce((a, p) => a + (num(p.amount) || 0), 0);
            app.querySelector('[data-save-paid]').textContent = `Opłacone : ${picked.size} · ${money(sum)}`;
        }));
        const save = app.querySelector('[data-save-paid]');
        if (save) save.addEventListener('click', async () => {
            const items = [...picked.entries()].map(([id, p]) => ({id, paid_on: p.paid_on || D.day, amount: num(p.amount)}));
            if (items.some(i => i.amount === null)) { at.notify(false, 'Wpisz kwotę wpłaty (liczba).'); return; }
            save.disabled = true;
            const r = await at.send('/ajax/finances/payments-day/paid', 'POST', {items});
            at.notify(r.ok, r.message);
            if (r.data) { D = r.data; picked.clear(); }
            render();
        });
        app.querySelectorAll('[data-proof-mail]').forEach(b => b.addEventListener('click', () => {
            const o = {onDone: () => reload()};
            if (b.dataset.proof) window.tfbPaymentProof.openProof(Number(b.dataset.proof), o); else window.tfbPaymentProof.openMail(Number(b.dataset.proofMail), o);
        }));
        const rl = app.querySelector('[data-reload]');
        if (rl) rl.addEventListener('click', reload);
        const fin = app.querySelector('[data-finish]');
        if (fin) fin.addEventListener('click', async () => {
            fin.disabled = true;
            const r = await at.send('/ajax/finances/payments-day/finish', 'POST', {});
            at.notify(r.ok, r.message);
            if (r.data) D = r.data;
            render();
        });
    }

    render();
    loadProofs();
})();
