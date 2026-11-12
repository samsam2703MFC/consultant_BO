/*
 * [277] Lista płac (makieta 41) : 5 kroków (Czas pracy zamknięty → dane HR → zatwierdzenie → plik XML do banku → CSV
 * dla biura rachunkowego), tabela osób, przelewy / ZUS / US, karta osoby (pasek + nadgodziny, premia, potrącenia).
 * Ustawienia płac : stawki roku (ze źródłem), rachunek firmy, dane do umów.
 */
(function () {
    'use strict';
    const app = document.getElementById('payApp');
    if (!app) return;
    const root = (app.dataset.root || '').replace(/\/$/, '');
    const tab = app.dataset.tab;
    const admin = app.dataset.admin === '1';
    const body = app.querySelector('[data-pay-body]');
    const dialog = document.querySelector('[data-pay-dialog]');
    const card = dialog.querySelector('[data-pay-card]');
    let data = null;

    const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const zl = (v) => Number(v || 0).toLocaleString('pl-PL', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    const d = (v) => v ? v.slice(8, 10) + '.' + v.slice(5, 7) + '.' + v.slice(0, 4) : '';
    const h = (v) => String(Math.round(Number(v || 0) * 100) / 100).replace('.', ',');

    async function api(method, path, payload) {
        const r = await fetch(root + path, {method, credentials: 'same-origin', headers: {'Accept': 'application/json', 'Content-Type': 'application/json'},
            body: payload === undefined ? undefined : JSON.stringify(payload)});
        const j = await r.json().catch(() => ({}));
        if (!r.ok) { const e = new Error(j.message || ('Błąd ' + r.status)); e.fields = j.fields || null; throw e; }
        return j.data !== undefined ? j.data : j;
    }

    const lastMonth = () => { const t = new Date(); t.setDate(1); t.setMonth(t.getMonth() - 1); return t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0'); };
    let month = new URLSearchParams(location.search).get('month') || lastMonth();

    // ================================ Lista płac ================================
    async function list() {
        try { data = await api('GET', '/ajax/payroll/month?month=' + month); }
        catch (e) { body.innerHTML = `<div class="alert alert-danger">${esc(e.message)}</div>`; return; }
        const s = data.steps;
        const t = data.totals;
        const step = (ok, on, label) => `<span class="pay-step ${ok ? 'is-done' : (on ? 'is-on' : '')}">${ok ? '✓ ' : ''}${label}</span>`;
        const draft = data.status === 'draft';
        body.innerHTML = `<div class="tm-bar"><div class="tm-nav"><button type="button" class="btn btn-light btn-sm" data-m="${data.prev}">‹</button><b class="pay-month">${esc(data.label)}</b>
                <button type="button" class="btn btn-light btn-sm" data-m="${data.next}">›</button></div><span class="tm-spacer"></span>
                <span class="tm-tag ${draft ? 'tm-tag-warn' : 'tm-tag-ok'}">${draft ? 'projekt — liczone na żywo' : 'zatwierdzona ' + d((data.approved.at || '').slice(0, 10)) + (data.approved.by ? ' · ' + esc(data.approved.by) : '')}</span></div>
            <div class="pay-flow">${step(s.time, !s.time, '1. Czas pracy ' + esc(data.label) + (s.time ? ' zamknięty' : ' — do zamknięcia'))}
                ${step(s.hr.complete === s.hr.all && s.hr.all > 0, s.time && s.hr.complete < s.hr.all, '2. Dane HR kompletne (' + s.hr.complete + '/' + s.hr.all + ')')}
                ${step(s.approved, s.time && !s.approved, '3. Sprawdź i zatwierdź listę płac')}
                ${step(s.xml, s.approved && !s.xml, '4. Plik XML do banku (do ' + d(data.pay_by) + ')')}
                ${step(false, s.approved, '5. CSV dla biura rachunkowego')}</div>
            <div class="table-responsive at-card pay-card"><table class="table tm-table pay-table"><thead><tr><th>Pracownik</th><th>Umowa</th><th class="text-end">Wynagrodzenie</th><th class="text-end">Dodatki</th>
                <th class="text-end">Brutto</th><th class="text-end">ZUS prac.</th><th class="text-end">Zdrowotna</th><th class="text-end">Zaliczka PIT</th><th class="text-end">Do wypłaty</th><th class="text-end">Koszt firmy</th></tr></thead>
                <tbody>${data.lines.map(row).join('') || '<tr><td colspan="10" class="at-empty">Nikt nie ma jeszcze umowy i wynagrodzenia w karcie HR.</td></tr>'}
                ${data.lines.length ? `<tr class="pay-total"><td>Razem (${t.people})</td><td></td><td></td><td></td><td class="text-end tm-num">${zl(t.gross)}</td><td class="text-end tm-num">${zl(t.zus_emp)}</td>
                    <td class="text-end tm-num">${zl(t.health)}</td><td class="text-end tm-num">${zl(t.pit)}</td><td class="text-end tm-num">${zl(t.net)}</td><td class="text-end tm-num">${zl(t.cost)}</td></tr>` : ''}</tbody></table></div>
            ${data.incomplete.length ? `<details class="pay-missing"><summary><b>Bez listy płac : ${data.incomplete.length} os.</b> <span class="tm-sub">brak umowy lub wynagrodzenia w karcie HR — pokaż</span></summary>
                <ul>${data.incomplete.map(p => `<li><a href="${root}/hr?employee=${p.employee_id}">${esc(p.name)}</a> <span class="tm-sub">brak : ${esc(p.missing.join(', '))}</span></li>`).join('')}</ul></details>` : ''}
            <div class="tm-kpis pay-kpis"><div class="tm-kpi is-accent"><small>Przelewy wynagrodzeń</small><b>${zl(t.net)} zł</b><span>${t.transfers} przelewów na rachunki z karty HR · do ${d(data.pay_by)}</span></div>
                <div class="tm-kpi"><small>ZUS (mikrorachunek)</small><b>${zl(t.zus_all)} zł</b><span>społeczne ${zl(t.zus_social)} + zdrowotna ${zl(t.health)} · do ${d(data.zus_by)}</span></div>
                <div class="tm-kpi"><small>Urząd skarbowy (PIT-4R)</small><b>${zl(t.pit)} zł</b><span>mikrorachunek podatkowy · do ${d(data.tax_by)}</span></div>
                <div class="tm-kpi"><small>Koszt pracodawcy</small><b>${zl(t.cost)} zł</b><span>brutto + składki pracodawcy ${zl(t.employer)}</span></div></div>
            <div class="pay-actions">${draft
                ? `<button type="button" class="btn btn-primary btn-sm" data-approve ${s.time && data.lines.length ? '' : 'disabled'}>Zatwierdź listę płac</button>${s.time ? '' : ' <span class="tm-sub">najpierw zamknij miesiąc w <a href="' + root + '/time/month?month=' + month + '">Czas pracy</a></span>'}`
                : `${admin && data.can_reopen ? '<button type="button" class="btn btn-light btn-sm" data-reopen>Otwórz ponownie</button>' : ''}`}
                <a class="btn btn-light btn-sm" target="_blank" rel="noopener" href="${root}/payroll/payslips?month=${month}"><i class="bi bi-printer"></i> Paski PDF (${data.lines.length})</a>
                ${draft ? '' : `<label class="pay-date">data przelewu <input type="date" class="form-control form-control-sm" data-exec value="${data.xml ? data.xml.exec_date : data.pay_by}"></label>
                    <a class="btn btn-primary btn-sm" data-xml href="#"><i class="bi bi-filetype-xml"></i> Plik XML do banku</a>`}
                <a class="btn btn-light btn-sm" href="${root}/payroll/csv?month=${month}"><i class="bi bi-filetype-csv"></i> CSV dla biura rachunkowego</a>
                <span class="hr-msg" data-msg>${data.xml ? 'Plik XML pobrany ' + d(data.xml.at.slice(0, 10)) + ' ' + data.xml.at.slice(11, 16) + ' (przelew ' + d(data.xml.exec_date) + ').' : ''}</span></div>
            <p class="hr-note">Plik XML : ISO 20022 pain.001 (« paczka płacowa », import w bankowości firmowej — ING Business : Administracja i Narzędzia › Import/Export).
                ERP nic nie wysyła do banku : przelewy zatwierdza osoba uprawniona w banku. ZUS i podatek : osobne przelewy na mikrorachunki.
                Lista płac do sprawdzenia przez biuro rachunkowe przed pierwszą wypłatą.</p>`;
        body.querySelectorAll('[data-m]').forEach(b => b.addEventListener('click', () => { month = b.dataset.m; history.replaceState(null, '', '?month=' + month); list(); }));
        body.querySelectorAll('[data-id]').forEach(tr => tr.addEventListener('click', () => openLine(Number(tr.dataset.id))));
        const msg = body.querySelector('[data-msg]');
        const ap = body.querySelector('[data-approve]');
        if (ap) ap.addEventListener('click', async () => {
            if (!confirm('Zatwierdzić listę płac ' + data.label + ' ? Kwoty zostaną zapisane (paski, przelewy, narastająco w roku).')) return;
            ap.disabled = true;
            try { await api('POST', '/ajax/payroll/approve?month=' + month); list(); } catch (e) { msg.textContent = e.message; ap.disabled = false; }
        });
        const ro = body.querySelector('[data-reopen]');
        if (ro) ro.addEventListener('click', async () => {
            if (!confirm('Otworzyć listę płac ponownie ? Zapisane kwoty zostaną usunięte, plik XML trzeba będzie pobrać jeszcze raz.')) return;
            try { await api('POST', '/ajax/payroll/reopen?month=' + month); list(); } catch (e) { msg.textContent = e.message; }
        });
        const xml = body.querySelector('[data-xml]');
        if (xml) xml.addEventListener('click', async (e) => {
            e.preventDefault();
            const date = body.querySelector('[data-exec]').value;
            // Sprawdzenie przed pobraniem : komunikat błędu zamiast pustego pliku.
            const r = await fetch(root + '/payroll/bank-file?month=' + month + '&date=' + date, {credentials: 'same-origin'});
            if (!r.ok) { msg.textContent = await r.text(); return; }
            const blob = await r.blob();
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'przelewy_wynagrodzenia_' + month + '.xml';
            a.click();
            URL.revokeObjectURL(a.href);
            list();
        });
    }

    function row(l) {
        const c = l.calc;
        const base = c.earnings[0];
        const extra = c.earnings.slice(1).reduce((s, e) => s + Number(e.amount), 0);
        const warn = c.warnings.length ? ` <span class="tm-tag tm-tag-warn" title="${esc(c.warnings.join(' '))}">${c.warnings.length} uwag${c.warnings.length === 1 ? 'a' : 'i'}</span>` : '';
        return `<tr class="pay-row" data-id="${l.employee_id}"><td><b>${esc(l.name)}</b>${warn}<div class="tm-sub">${esc(l.tags.join(' · '))}</div></td><td>${esc(l.contract)}</td>
            <td class="text-end tm-num">${base ? zl(base.amount) : ''}</td><td class="text-end tm-num">${extra ? zl(extra) : '—'}</td><td class="text-end tm-num">${zl(c.gross)}</td>
            <td class="text-end tm-num">${zl(c.zus_total)}</td><td class="text-end tm-num">${zl(c.health)}</td><td class="text-end tm-num">${zl(c.pit)}</td>
            <td class="text-end tm-num"><b>${zl(c.net)}</b></td><td class="text-end tm-num">${zl(c.cost)}</td></tr>`;
    }

    // Karta osoby : pasek + korekty miesiąca (projekt).
    function openLine(id) {
        const l = data.lines.find(x => x.employee_id === id);
        if (!l) return;
        const c = l.calc;
        const a = l.adjust || {};
        const draft = data.status === 'draft';
        const tr = (label, v, cls) => `<tr class="${cls || ''}"><td>${esc(label)}</td><td class="text-end tm-num">${typeof v === 'number' ? zl(v) : esc(v)}</td></tr>`;
        card.innerHTML = `<header class="tm-dialog-head"><h4 id="payDialogTitle">${esc(l.name)} <span class="tm-sub">${esc(l.contract)}${l.work_time ? ' · ' + esc(l.work_time) : ''}</span></h4>
                <button type="button" class="btn-close" data-close aria-label="Zamknij"></button></header>
            <div class="tm-dialog-body pay-grid"><div><table class="table tm-table pay-slip"><tbody>
                <tr class="pay-sec"><td colspan="2">Wynagrodzenie</td></tr>${c.earnings.map(e => tr(e.label, Number(e.amount))).join('')}${tr('Brutto', c.gross, 'pay-sum')}
                <tr class="pay-sec"><td colspan="2">Składki pracownika i zdrowotna</td></tr>${tr('Emerytalna (podstawa ' + zl(c.pension_base) + ')', c.zus.pension)}${tr('Rentowa', c.zus.disability)}${tr('Chorobowa', c.zus.sickness)}
                ${tr('Zdrowotna 9 % (od ' + zl(c.health_base) + ')', c.health)}
                <tr class="pay-sec"><td colspan="2">Podatek</td></tr>${c.exempt_revenue ? tr('Przychód zwolniony (ulga dla młodych)', c.exempt_revenue) : ''}${tr('Koszty uzyskania przychodu', c.kup)}
                ${tr('Podstawa (zaokr.)', String(c.tax_base))}${tr('Zaliczka PIT' + (c.reduction ? ' (− ' + zl(c.reduction) + ' PIT-2)' : ''), c.pit)}${c.deduction ? tr('Potrącenia', c.deduction) : ''}
                ${tr('Do wypłaty', c.net, 'pay-net')}
                <tr class="pay-sec"><td colspan="2">Pracodawca</td></tr>${tr('Emerytalna · rentowa', zl(c.employer.pension) + ' · ' + zl(c.employer.disability))}
                ${tr('Wypadkowa · FP · FGŚP', zl(c.employer.accident) + ' · ' + zl(c.employer.fp) + ' · ' + zl(c.employer.fgsp))}${tr('Koszt pracodawcy', c.cost, 'pay-sum')}</tbody></table>
                <p class="tm-sub">Rachunek : ${esc(l.account || 'brak w karcie HR')}. Godziny w Czasie pracy : ${h(l.hours)} h · dni robocze w miesiącu : ${l.workdays}.</p></div>
            <div>${c.warnings.length ? `<div class="pay-warn">${c.warnings.map(w => `<p>${esc(w)}</p>`).join('')}</div>` : ''}
                <form class="hr-sec" data-adj><h5>Korekty miesiąca</h5>
                    <div class="hr-fields">${[['overtime50_h', 'Nadgodziny płatne 50 % (h)'], ['overtime100_h', 'Nadgodziny płatne 100 % (h)'], ['bonus', 'Premia (zł brutto)'], ['deduction', 'Potrącenie z netto (zł)']]
                        .map(([k, lab]) => `<label class="hr-f"><span>${lab}</span><input class="form-control form-control-sm" name="${k}" inputmode="decimal" value="${a[k] ? String(a[k]).replace('.', ',') : ''}" ${draft ? '' : 'disabled'}></label>`).join('')}
                    <label class="hr-f"><span>Notatka</span><input class="form-control form-control-sm" name="note" value="${esc(a.note || '')}" ${draft ? '' : 'disabled'}></label></div>
                    ${draft ? '<div class="hr-actions"><button type="submit" class="btn btn-primary btn-sm">Przelicz</button><span class="hr-msg" data-amsg></span></div>' : '<p class="tm-sub">Lista zatwierdzona — korekty po ponownym otwarciu.</p>'}
                    <p class="tm-sub">Nadgodziny z Czasu pracy odbierane są dniem wolnym ; tu tylko te, które firma płaci.</p></form>
                <a class="btn btn-light btn-sm" target="_blank" rel="noopener" href="${root}/payroll/payslips?month=${month}&employee=${id}"><i class="bi bi-printer"></i> Pasek PDF</a>
                <a class="btn btn-link btn-sm" href="${root}/hr?employee=${id}">Karta HR</a></div></div>`;
        if (!dialog.open) dialog.showModal();
        card.querySelector('[data-close]').addEventListener('click', () => dialog.close());
        const f = card.querySelector('[data-adj]');
        if (draft) f.addEventListener('submit', async (e) => {
            e.preventDefault();
            f.querySelectorAll('.is-invalid').forEach(x => x.classList.remove('is-invalid'));
            try {
                data = await api('PUT', '/ajax/payroll/adjust/' + id + '?month=' + month, Object.fromEntries(['overtime50_h', 'overtime100_h', 'bonus', 'deduction', 'note'].map(k => [k, f[k].value])));
                openLine(id);
                list();
            } catch (x) { card.querySelector('[data-amsg]').textContent = x.message; Object.keys(x.fields || {}).forEach(k => f[k] && f[k].classList.add('is-invalid')); }
        });
    }

    // ================================ Ustawienia płac ================================
    async function settings(year) {
        year = year || new Date().getFullYear();
        let r;
        try { r = await api('GET', '/ajax/payroll/rates?year=' + year); } catch (e) { body.innerHTML = `<div class="alert alert-danger">${esc(e.message)}</div>`; return; }
        const rt = r.rates;
        const st = r.settings;
        const dis = r.is_admin ? '' : 'disabled';
        body.innerHTML = `<div class="tm-bar"><div class="tm-nav"><button type="button" class="btn btn-light btn-sm" data-y="${year - 1}">‹</button><b class="pay-month">Stawki ${year}</b>
                <button type="button" class="btn btn-light btn-sm" data-y="${year + 1}">›</button></div><span class="tm-spacer"></span>
                ${rt.own ? '' : `<span class="tm-tag tm-tag-warn">brak stawek ${year} — pokazane z ${rt.from_year}</span>${r.is_admin ? ' <button type="button" class="btn btn-primary btn-sm" data-copy>Skopiuj stawki ' + rt.from_year + ' na ' + year + '</button>' : ''}`}</div>
            <form class="at-card pay-card" data-rates><table class="table tm-table pay-rates"><thead><tr><th>Stawka</th><th class="text-end">Wartość</th><th>Jedn.</th><th>Źródło</th></tr></thead><tbody>
                ${rt.rates.map(x => `<tr><td>${esc(x.label)}</td><td class="text-end"><input class="form-control form-control-sm text-end" name="v_${x.code}" value="${x.value == null ? '' : String(x.value).replace('.', ',')}" ${dis}></td>
                    <td class="tm-sub">${esc(x.unit)}</td><td><input class="form-control form-control-sm" name="s_${x.code}" value="${esc(x.source || '')}" ${dis}></td></tr>`).join('')}</tbody></table>
                ${r.is_admin ? `<div class="hr-actions p-3"><button type="submit" class="btn btn-primary btn-sm">Zapisz stawki ${year}</button><span class="hr-msg" data-rmsg></span></div>` : '<p class="tm-sub p-3">Stawki zmienia administrator.</p>'}</form>
            <form class="at-card hr-sec pay-card" data-company><h5>Firma i umowy</h5><div class="hr-fields">
                <label class="hr-f"><span>Rachunek do przelewów wynagrodzeń</span><input class="form-control form-control-sm" name="debtor_iban" value="${esc(st.debtor_iban)}" placeholder="${esc(st.company_bank ? 'z danych firmy : ' + st.company_bank : 'PL…')}" ${dis}></label>
                <label class="hr-f"><span>Miejscowość zawarcia umów</span><input class="form-control form-control-sm" name="city" value="${esc(st.city)}" ${dis}></label>
                <label class="hr-f"><span>Reprezentant pracodawcy</span><input class="form-control form-control-sm" name="rep" value="${esc(st.rep)}" ${dis}></label>
                <label class="hr-f"><span>Miejsce wykonywania pracy</span><input class="form-control form-control-sm" name="workplace" value="${esc(st.workplace)}" ${dis}></label></div>
                ${r.is_admin ? '<div class="hr-actions"><button type="submit" class="btn btn-primary btn-sm">Zapisz</button><span class="hr-msg" data-cmsg></span></div>' : ''}</form>
            <p class="hr-note">Stawki zmieniają się co rok (płaca minimalna, limit 30-krotności, stopa wypadkowa firmy) : 1 stycznia skopiuj stawki i popraw te, które się zmieniły, zawsze ze źródłem.</p>`;
        body.querySelectorAll('[data-y]').forEach(b => b.addEventListener('click', () => settings(Number(b.dataset.y))));
        const cp = body.querySelector('[data-copy]');
        if (cp) cp.addEventListener('click', async () => { try { await api('POST', '/ajax/payroll/rates/copy?year=' + year); settings(year); } catch (e) { alert(e.message); } });
        const fr = body.querySelector('[data-rates]');
        fr.addEventListener('submit', async (e) => {
            e.preventDefault();
            const rates = {};
            rt.rates.forEach(x => { rates[x.code] = {value: fr['v_' + x.code].value, source: fr['s_' + x.code].value}; });
            fr.querySelectorAll('.is-invalid').forEach(x => x.classList.remove('is-invalid'));
            try { await api('PUT', '/ajax/payroll/rates?year=' + year, {rates}); body.querySelector('[data-rmsg]').textContent = 'Zapisano.'; }
            catch (x) { body.querySelector('[data-rmsg]').textContent = x.message; Object.keys(x.fields || {}).forEach(k => fr['v_' + k] && fr['v_' + k].classList.add('is-invalid')); }
        });
        const fc = body.querySelector('[data-company]');
        fc.addEventListener('submit', async (e) => {
            e.preventDefault();
            try { await api('PUT', '/ajax/payroll/settings', {debtor_iban: fc.debtor_iban.value, city: fc.city.value, rep: fc.rep.value, workplace: fc.workplace.value}); body.querySelector('[data-cmsg]').textContent = 'Zapisano.'; }
            catch (x) { body.querySelector('[data-cmsg]').textContent = x.message; }
        });
    }

    dialog.addEventListener('click', (e) => { if (e.target === dialog) dialog.close(); });
    (tab === 'settings' ? settings : list)();
})();
