/*
 * [271] HR (makiety 36–38) : Pracownicy (tabela, filtry, karta pracownika), Dokumenty, Notatki miesiąca, Ustawienia HR.
 * Karta : dane i zatrudnienie (zapis), dokumenty (PDF, zdjęcie, DOCX — zaszyfrowane na serwerze), notatki
 * (prywatna = autor i administrator, publiczna = też kierownik produkcji i administracja, « do administratora » = dzwonek).
 */
(function () {
    'use strict';
    const app = document.getElementById('hrApp');
    if (!app) return;
    const root = (app.dataset.root || '').replace(/\/$/, '');
    const tab = app.dataset.tab;
    const admin = app.dataset.admin === '1';
    const canPay = app.dataset.pay === '1';   // [277] wynagrodzenie, rachunek, PESEL, adres, szablony umów
    const body = app.querySelector('[data-hr-body]');
    const dialog = document.querySelector('[data-hr-dialog]');
    const card = dialog.querySelector('[data-hr-card]');
    let data = null;
    let filter = 'all';
    let q = '';

    const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const d = (v) => v ? v.slice(8, 10) + '.' + v.slice(5, 7) + '.' + v.slice(0, 4) : '';
    const size = (n) => n > 1048576 ? (n / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.max(1, Math.round(n / 1024)) + ' kB';
    const fold = (s) => String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/ł/g, 'l');
    const missing = '<span class="hr-miss">brak</span>';

    async function api(method, path, payload) {
        const r = await fetch(root + path, {method, credentials: 'same-origin', headers: {'Accept': 'application/json', 'Content-Type': 'application/json'},
            body: payload === undefined ? undefined : JSON.stringify(payload)});
        const j = await r.json().catch(() => ({}));
        if (!r.ok) { const e = new Error(j.message || ('Błąd ' + r.status)); e.fields = (j.errors && j.errors.fields) || j.fields || null; throw e; }
        return j.data !== undefined ? j.data : j;
    }

    // ================================ Pracownicy (makieta 36) ================================
    async function people() {
        data = await api('GET', '/ajax/hr/people');
        const k = data.kpi;
        const groups = [...new Set(data.people.filter(p => p.active && p.group).map(p => p.group))];
        body.innerHTML = `<div class="tm-kpis">
                <div class="tm-kpi"><small>Pracownicy aktywni</small><b>${k.active}</b><span>${k.groups} grup</span></div>
                <div class="tm-kpi"><small>Średni staż</small><b>${k.avg_seniority == null ? '—' : esc(span(k.avg_seniority))}</b><span>${k.max_seniority == null ? 'brak dat zatrudnienia' : 'najdłużej ' + esc(span(k.max_seniority))}</span></div>
                <div class="tm-kpi"><small>Umowy kończące się ≤ 90 dni</small><b>${k.contracts_ending}</b><span>przypomnienie ${data.rules.alert_days} dni wcześniej</span></div>
                <div class="tm-kpi"><small>Emerytura ≤ 2 lata</small><b>${k.retire_soon}</b><span>wiek ${data.rules.retire_k} K / ${data.rules.retire_m} M</span></div></div>
            <div class="tm-bar hr-bar"><input type="search" class="form-control form-control-sm hr-search" placeholder="Szukaj : nazwisko, e-mail, telefon…" data-q value="${esc(q)}">
                <div class="hr-chips">${[['all', 'Wszyscy'], ...groups.map(g => ['g:' + g, g]), ['missing', 'Brak danych (' + k.missing + ')'], ['contract', 'Umowa się kończy'], ['retire', 'Emerytura ≤ 2 lata'], ['inactive', 'Nieaktywni']]
                    .map(([v, l]) => `<button type="button" class="hr-chip ${filter === v ? 'is-on' : ''}" data-filter="${esc(v)}">${esc(l)}</button>`).join('')}</div>
                <span class="tm-spacer"></span><span class="hr-lock"><i class="bi bi-lock"></i> dane osobowe</span>
                <button type="button" class="btn btn-primary btn-sm" data-quick>＋ Szybka notatka</button>
                ${admin ? '<button type="button" class="btn btn-light btn-sm" data-csv><i class="bi bi-filetype-csv"></i> CSV</button>' : ''}</div>
            <div class="table-responsive at-card hr-card"><table class="table tm-table hr-table"><thead><tr><th>Pracownik</th><th>E-mail</th><th class="text-end">Telefon</th>
                <th class="text-end">Zatrudniony · staż</th><th>Umowa</th><th class="text-end">Emerytura (rok, wiek)</th><th class="text-end">Dokumenty</th><th class="text-end">Notatki (mies.)</th></tr></thead>
                <tbody data-rows></tbody></table></div>
            <p class="hr-note">Klik w wiersz → karta pracownika. « brak » = do uzupełnienia. E-mail ten sam co w Czas pracy › E-maile pracowników.
                Emerytura : data urodzenia + wiek emerytalny (${data.rules.retire_k} kobiety / ${data.rules.retire_m} mężczyźni, Ustawienia HR).</p>`;
        rows();
        body.querySelector('[data-q]').addEventListener('input', (e) => { q = e.target.value; rows(); });
        body.querySelectorAll('[data-filter]').forEach(b => b.addEventListener('click', () => { filter = b.dataset.filter; body.querySelectorAll('[data-filter]').forEach(x => x.classList.toggle('is-on', x === b)); rows(); }));
        const csv = body.querySelector('[data-csv]');
        if (csv) csv.addEventListener('click', exportCsv);
        body.querySelector('[data-quick]').addEventListener('click', () => quickNote(null, () => people()));
        const want = Number(new URLSearchParams(location.search).get('employee'));
        if (want) openCard(want);
    }

    function span(m) { const y = Math.floor(m / 12); return (y ? y + ' l. ' : '') + (m % 12) + ' m.'; }

    function visible() {
        const f = fold(q.trim());
        return data.people.filter(p => {
            if (filter === 'inactive' ? p.active : !p.active) return false;
            if (filter.startsWith('g:') && p.group !== filter.slice(2)) return false;
            if (filter === 'missing' && !p.missing) return false;
            if (filter === 'contract' && !p.contract_soon) return false;
            if (filter === 'retire' && !p.retire_soon) return false;
            return !f || [p.name, p.email, p.phone, p.group, p.position].some(v => fold(v).includes(f));
        });
    }

    function rows() {
        const list = visible();
        body.querySelector('[data-rows]').innerHTML = list.length ? list.map(p => `<tr data-id="${p.id}" class="hr-row">
                <td><b>${esc(p.name)}</b><div class="tm-sub">${esc([p.group, p.position].filter(Boolean).join(' · '))}</div></td>
                <td>${p.email ? esc(p.email) : missing}</td><td class="text-end tm-num">${p.phone ? esc(p.phone) : missing}</td>
                <td class="text-end tm-num">${p.hire_date ? d(p.hire_date) + `<div class="tm-sub">${esc(p.seniority)}</div>` : missing}</td>
                <td>${p.contract_label ? esc(p.contract_label) + (p.contract_end ? ' do ' + d(p.contract_end) : '') : '—'}${p.contract_soon ? ' <span class="tm-tag tm-tag-warn">kończy się</span>' : ''}</td>
                <td class="text-end tm-num">${p.retire ? p.retire.year + ' (' + p.retire.age + ')' + (p.retire_soon ? ` <span class="tm-tag tm-tag-warn">${p.retire.months_left <= 12 ? 'za ≤ 1 rok' : 'za ≤ 2 lata'}</span>` : '') : '—'}</td>
                <td class="text-end tm-num">${p.documents}</td><td class="text-end tm-num">${p.notes_month}</td></tr>`).join('')
            : '<tr><td colspan="8" class="at-empty">Nikt nie pasuje.</td></tr>';
        body.querySelectorAll('[data-id]').forEach(tr => tr.addEventListener('click', () => openCard(Number(tr.dataset.id))));
    }

    function exportCsv() {
        const head = ['Pracownik', 'Grupa', 'E-mail', 'Telefon', 'Zatrudniony', 'Staż', 'Umowa', 'Koniec umowy', 'Emerytura', 'Dokumenty'];
        const lines = [head].concat(visible().map(p => [p.name, p.group || '', p.email || '', p.phone || '', p.hire_date || '', p.seniority || '', p.contract_label || '', p.contract_end || '',
            p.retire ? p.retire.date : '', p.documents]));
        const csv = lines.map(l => l.map(v => '"' + String(v).replace(/"/g, '""') + '"').join(';')).join('\r\n');
        const a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob(['﻿' + csv], {type: 'text/csv;charset=utf-8'}));
        a.download = 'hr-pracownicy.csv';
        a.click();
        URL.revokeObjectURL(a.href);
    }

    // ================================ Karta pracownika (makieta 37) ================================
    async function openCard(id) {
        card.innerHTML = '<p class="at-empty p-4">Ładowanie…</p>';
        if (!dialog.open) dialog.showModal();
        let c;
        try { c = await api('GET', '/ajax/hr/people/' + id); } catch (e) { card.innerHTML = `<p class="tm-dialog-error p-4">${esc(e.message)}</p>`; return; }
        const p = c.person;
        const kinds = data ? data.kinds : {umowa: 'Umowa', aneks: 'Aneks', badania: 'Badania lekarskie', notatka: 'Notatka', inne: 'Inne'};
        const contracts = data ? data.contracts : {};
        const opt = (o, cur) => Object.entries(o).map(([k, l]) => `<option value="${k}" ${k === cur ? 'selected' : ''}>${esc(l)}</option>`).join('');
        const f = (label, name, value, type) => `<label class="hr-f"><span>${label}</span><input class="form-control form-control-sm" name="${name}" type="${type || 'text'}" value="${esc(value || '')}"></label>`;
        card.innerHTML = `<header class="tm-dialog-head"><h4 id="hrDialogTitle">${esc(p.name)} <span class="tm-tag ${p.active ? 'tm-tag-ok' : ''}">${p.active ? 'aktywny' : 'nieaktywny'}</span></h4>
                <button type="button" class="btn-close" data-close aria-label="Zamknij"></button></header>
            <div class="tm-dialog-body hr-grid"><div>
                <form class="hr-sec" data-form><h5>Dane i zatrudnienie</h5><p class="tm-sub">${esc([p.group, p.position].filter(Boolean).join(' · '))}${p.seniority ? ' · staż ' + esc(p.seniority) : ''}${p.retire ? ' · emerytura ' + d(p.retire.date) : ''}</p>
                    <div class="hr-fields">${f('E-mail', 'email', p.email, 'email')}${f('Telefon', 'phone', p.phone, 'tel')}${f('Data urodzenia', 'birth_date', p.birth_date, 'date')}
                    <label class="hr-f"><span>Płeć (wiek emerytalny)</span><select class="form-select form-select-sm" name="gender"><option value="">—</option>${opt({K: 'kobieta', M: 'mężczyzna'}, p.gender)}</select></label>
                    ${f('Zatrudniony od', 'hire_date', p.hire_date, 'date')}
                    <label class="hr-f"><span>Umowa</span><select class="form-select form-select-sm" name="contract_type"><option value="">—</option>${opt(contracts, p.contract_type)}</select></label>
                    ${f('Koniec umowy', 'contract_end', p.contract_end, 'date')}${f('Wymiar', 'work_time', p.work_time)}${f('Badania lekarskie do', 'medical_until', p.medical_until, 'date')}
                    ${f('Kontakt w nagłych wypadkach', 'emergency', p.emergency)}</div>
                    ${p.pay ? payHtml(p.pay, f, opt) : ''}
                    <div class="hr-actions"><button type="submit" class="btn btn-primary btn-sm">Zapisz</button><span class="hr-msg" data-msg></span></div></form>
                <div class="hr-sec"><h5>Dokumenty (${c.documents.length})</h5>
                    <table class="table tm-table hr-docs"><tbody>${c.documents.map(x => `<tr><td><span class="tm-tag">${esc(x.kind_label)}</span> <a href="${root}/hr/document/${x.id}?inline=1" target="_blank" rel="noopener">${esc(x.title)}</a>
                        <div class="tm-sub">${esc(x.filename)} · ${size(x.size)}${x.by ? ' · ' + esc(x.by) : ''}</div></td><td class="text-end tm-num">${d(x.date)}</td>
                        <td class="text-end"><a class="btn btn-light btn-sm" href="${root}/hr/document/${x.id}">Pobierz</a>${x.can_delete ? ` <button type="button" class="btn btn-link btn-sm text-danger" data-del-doc="${x.id}">Usuń</button>` : ''}</td></tr>`).join('')
                        || '<tr><td class="at-empty">Brak dokumentów.</td></tr>'}</tbody></table>
                    <form class="hr-upload" data-upload><select class="form-select form-select-sm" name="kind" required><option value="">Rodzaj…</option>${opt(kinds, '')}</select>
                        <input class="form-control form-control-sm" type="date" name="doc_date" aria-label="Data dokumentu">
                        <input class="form-control form-control-sm" type="file" name="file" accept="application/pdf,image/jpeg,image/png,.docx" required>
                        <button type="submit" class="btn btn-light btn-sm"><i class="bi bi-upload"></i> Dodaj</button><span class="hr-msg" data-upmsg></span></form>
                    <p class="tm-sub">PDF, zdjęcie albo DOCX, do 8 MB — plik jest zaszyfrowany na serwerze.</p></div>
            </div><div class="hr-sec"><h5>Notatki <button type="button" class="btn btn-light btn-sm hr-quick-in" data-quick-card>＋ Szybka notatka</button></h5>
                <form class="hr-compose" data-note><textarea class="form-control" name="body" rows="3" maxlength="2000" placeholder="Nowa notatka…" required></textarea>
                    <div class="hr-compose-bar"><span class="hr-seg"><button type="button" class="is-on" data-vis="private">Prywatna</button><button type="button" data-vis="public">Publiczna</button></span>
                    <label class="tm-sub"><input type="checkbox" class="form-check-input" name="to_admin"> Wyślij do administratora</label><span class="tm-spacer"></span>
                    <button type="submit" class="btn btn-primary btn-sm">Dodaj</button></div><span class="hr-msg" data-notemsg></span></form>
                <div class="hr-notes">${c.notes.map(n => noteHtml(n)).join('') || '<p class="at-empty">Brak notatek.</p>'}</div>
                <p class="tm-sub"><b>Publiczna</b> : widzi ją kierownik produkcji i administracja (i trafia do podsumowania miesiąca). <b>Prywatna</b> : tylko autor i administrator.
                    <b>Do administratora</b> : od razu w dzwonku administratora.</p></div></div>`;
        card.querySelector('[data-close]').addEventListener('click', () => dialog.close());
        card.querySelector('[data-quick-card]').addEventListener('click', () => quickNote(id, () => { openCard(id); refresh(); }));
        bindFollowups(card, () => openCard(id));
        const form = card.querySelector('[data-form]');
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const msg = card.querySelector('[data-msg]');
            form.querySelectorAll('.is-invalid').forEach(x => x.classList.remove('is-invalid'));
            const payload = Object.fromEntries(['email', 'phone', 'birth_date', 'gender', 'hire_date', 'contract_type', 'contract_end', 'work_time', 'medical_until', 'emergency'].map(k => [k, form[k].value]));
            if (p.pay) {
                ['pay_type', 'pay_amount', 'bank', 'address', 'pesel'].forEach(k => { payload[k] = form[k].value; });
                ['pit2', 'kup_raised', 'young_relief', 'student', 'sick_insurance'].forEach(k => { payload[k] = form[k].checked; });
            }
            try { await api('PUT', '/ajax/hr/people/' + id, payload); msg.textContent = 'Zapisano.'; refresh(); }
            catch (x) { msg.textContent = x.message; Object.keys(x.fields || {}).forEach(k => { if (form[k]) { form[k].classList.add('is-invalid'); form[k].title = x.fields[k]; } }); }
        });
        let vis = 'private';
        card.querySelectorAll('[data-vis]').forEach(b => b.addEventListener('click', () => { vis = b.dataset.vis; card.querySelectorAll('[data-vis]').forEach(x => x.classList.toggle('is-on', x === b)); }));
        card.querySelector('[data-note]').addEventListener('submit', async (e) => {
            e.preventDefault();
            const f2 = e.target;
            try { await api('POST', '/ajax/hr/people/' + id + '/notes', {body: f2.body.value, visibility: vis, to_admin: f2.to_admin.checked}); openCard(id); refresh(); }
            catch (x) { card.querySelector('[data-notemsg]').textContent = x.message; }
        });
        card.querySelector('[data-upload]').addEventListener('submit', async (e) => {
            e.preventDefault();
            const f3 = e.target;
            const file = f3.file.files[0];
            const msg = card.querySelector('[data-upmsg]');
            if (!file) return;
            if (file.size > 8 * 1024 * 1024) { msg.textContent = 'Plik większy niż 8 MB.'; return; }
            msg.textContent = 'Wysyłanie…';
            try {
                const b64 = await new Promise((ok, ko) => { const r = new FileReader(); r.onload = () => ok(String(r.result).split(',')[1] || ''); r.onerror = ko; r.readAsDataURL(file); });
                await api('POST', '/ajax/hr/people/' + id + '/documents', {kind: f3.kind.value, doc_date: f3.doc_date.value, filename: file.name, title: file.name.replace(/\.[^.]+$/, ''), content_base64: b64});
                openCard(id); refresh();
            } catch (x) { msg.textContent = x.message; }
        });
        card.querySelectorAll('[data-del-doc]').forEach(b => b.addEventListener('click', async () => {
            if (!confirm('Usunąć dokument ?')) return;
            try { await api('DELETE', '/ajax/hr/documents/' + b.dataset.delDoc); openCard(id); refresh(); } catch (x) { alert(x.message); }
        }));
        card.querySelectorAll('[data-del-note]').forEach(b => b.addEventListener('click', async () => {
            if (!confirm('Usunąć notatkę ?')) return;
            try { await api('DELETE', '/ajax/hr/notes/' + b.dataset.delNote); openCard(id); refresh(); } catch (x) { alert(x.message); }
        }));
    }

    // [277] Płace i umowy w karcie : tylko administrator i finanse (dostęp « Lista płac »).
    function payHtml(pay, f, opt) {
        const chk = (name, label, on, hint) => `<label class="hr-chk"><input type="checkbox" class="form-check-input" name="${name}" ${on ? 'checked' : ''}> ${label}${hint ? ` <span class="tm-sub">${hint}</span>` : ''}</label>`;
        return `<h5 class="hr-subhead">Płace i umowy <span class="hr-lock"><i class="bi bi-lock"></i> administrator i finanse</span></h5>
            <div class="hr-fields">
                <label class="hr-f"><span>Wynagrodzenie</span><span class="hr-pay"><select class="form-select form-select-sm" name="pay_type"><option value="">—</option>${opt({monthly: 'miesięcznie', hourly: 'za godzinę'}, pay.type)}</select>
                    <input class="form-control form-control-sm" name="pay_amount" inputmode="decimal" value="${pay.amount == null ? '' : String(pay.amount).replace('.', ',')}" placeholder="brutto zł"></span></label>
                ${f('Rachunek bankowy', 'bank', pay.bank)}${f('Adres zamieszkania', 'address', pay.address)}${f('PESEL', 'pesel', pay.pesel)}</div>
            <div class="hr-checks">${chk('pit2', 'PIT-2 (kwota zmniejszająca 300 zł)', pay.pit2)}${chk('kup_raised', 'Podwyższone koszty (dojazd)', pay.kup_raised)}
                ${chk('young_relief', 'Ulga dla młodych', pay.young_relief, 'gdy < 26 lat')}${chk('student', 'Student / uczeń', pay.student, 'zlecenie : bez ZUS')}
                ${chk('sick_insurance', 'Dobrowolne chorobowe', pay.sick_insurance, 'zlecenie')}</div>`;
    }

    function followHtml(f, id) {
        if (!f) return '';
        if (f.done_at) return `<div class="hr-fu is-done"><i class="bi bi-check2-circle"></i> follow-up zrobiony ${d(f.done_at.slice(0, 10))}${f.done_by ? ' · ' + esc(f.done_by) : ''}${f.done_note ? ' — ' + esc(f.done_note) : ''}</div>`;
        return `<div class="hr-fu ${f.late ? 'is-late' : ''}"><i class="bi bi-flag"></i> follow-up do ${d(f.due)}${f.who ? ' · ' + esc(f.who) : ''}${f.late ? ' · <b>po terminie</b>' : ''}
            ${f.can_close ? ` <button type="button" class="btn btn-link btn-sm" data-fu-done="${id}">Zrobione</button>` : ''}</div>`;
    }

    function noteHtml(n, withName) {
        return `<div class="hr-n" id="note-${n.id}"><div class="hr-n-meta">${n.kind_label ? `<span class="hr-kind hr-k-${esc(n.kind)}">${esc(n.kind_label)}</span>` : ''}${withName ? `<b>${esc(n.name)}</b> · ` : ''}${d(n.at.slice(0, 10))} ${n.at.slice(11, 16)}${n.by ? ' · ' + esc(n.by) : ''}
                <span class="tm-tag ${n.visibility === 'public' ? 'tm-tag-ok' : ''}">${n.visibility === 'public' ? 'publiczna' : 'prywatna'}</span>${n.to_admin ? '<span class="tm-tag tm-tag-warn">do administratora</span>' : ''}
                ${n.can_delete ? `<button type="button" class="btn btn-link btn-sm text-danger hr-n-del" data-del-note="${n.id}">Usuń</button>` : ''}</div>
            ${n.topic ? `<div class="hr-n-topic">${esc(n.topic)}</div>` : ''}${n.topic && n.body === n.topic ? '' : `<div class="hr-n-body">${esc(n.body)}</div>`}
            ${n.audience && n.audience.length ? `<div class="tm-sub">dla : ${esc(n.audience.join(', '))}</div>` : ''}${followHtml(n.followup, n.id)}</div>`;
    }

    function refresh() { if (tab === 'people') people().catch(() => {}); }

    // Follow-up : « Zrobione » (krótka uwaga opcjonalna).
    function bindFollowups(scope, after) {
        scope.querySelectorAll('[data-fu-done]').forEach(b => b.addEventListener('click', async (e) => {
            e.preventDefault();
            const note = prompt('Follow-up zrobiony — krótka uwaga (opcjonalnie) :', '');
            if (note === null) return;
            try { await api('POST', '/ajax/hr/followups/' + b.dataset.fuDone + '/done', {note}); after(); } catch (x) { alert(x.message); }
        }));
    }

    // ================================ Szybka notatka (makieta 44) ================================
    let quickOpts = null;
    async function quickNote(employeeId, after) {
        quickOpts = quickOpts || await api('GET', '/ajax/hr/quick-note');
        const o = quickOpts;
        const today = new Date().toISOString().slice(0, 10);
        const st = {kind: o.kinds[0].code, audience: ['admin'], channels: ['bell'], followup: false, due: '', who: String((o.users.find(u => u.id === o.me) || o.users[0] || {}).id || '')};
        const chip = (attr, v, l, on) => `<button type="button" class="hr-chip ${on ? 'is-on' : ''}" data-${attr}="${esc(v)}">${esc(l)}</button>`;
        card.innerHTML = `<header class="tm-dialog-head"><h4 id="hrDialogTitle">Szybka notatka <span class="tm-sub">trafia do karty pracownika i do podsumowania miesiąca</span></h4>
                <button type="button" class="btn-close" data-close aria-label="Zamknij"></button></header>
            <form class="tm-dialog-body hr-qn" data-qn><div class="hr-qn-l">
                <div class="hr-lab">Rodzaj</div><div class="hr-chips" data-kinds>${o.kinds.map((k, i) => chip('kind', k.code, k.label, i === 0)).join('')}</div>
                <div class="hr-qn-2"><label class="hr-f"><span>Dotyczy pracownika</span><select class="form-select form-select-sm" name="employee_id" required><option value="">— wybierz —</option>
                    ${o.people.map(p => `<option value="${p.id}" ${p.id === employeeId ? 'selected' : ''}>${esc(p.name)}${p.group ? ' · ' + esc(p.group) : ''}</option>`).join('')}</select></label>
                <label class="hr-f"><span>Temat</span><input class="form-control form-control-sm" name="topic" maxlength="160" required list="hrTopics"><datalist id="hrTopics"></datalist></label></div>
                <div class="hr-topics" data-topics></div>
                <label class="hr-f"><span>Treść</span><textarea class="form-control" name="body" rows="3" maxlength="2000"></textarea></label>
                <div class="hr-lab">Dla kogo (kto widzi)</div><div class="hr-chips" data-auds>${o.audience.map(a => chip('aud', a.code, a.label + (a.code !== 'me' && a.code !== 'admin' ? ' (' + a.count + ')' : ''), a.code === 'admin')).join('')}</div>
                <div class="hr-lab">Follow-up ?</div><div class="hr-fu-box"><span class="hr-seg"><button type="button" data-fu="1">Tak</button><button type="button" class="is-on" data-fu="0">Nie</button></span>
                    <input class="form-control form-control-sm" type="date" name="followup_due" min="${today}" disabled>
                    <select class="form-select form-select-sm" name="followup_user" disabled>${o.users.map(u => `<option value="${u.id}" ${String(u.id) === st.who ? 'selected' : ''}>kto : ${esc(u.name)}${u.position ? ' · ' + esc(u.position) : ''}</option>`).join('')}</select></div>
                <div class="hr-lab">Powiadom teraz</div><div class="hr-chips" data-chans>${o.channels.map(c => chip('chan', c.code, c.label + (c.code === 'sms' && !o.sms_delivers ? ' (bez bramki : zapis)' : ''), c.code === 'bell')).join('')}</div>
                <p class="tm-sub">E-mail i SMS : kto, rodzaj, temat i link — bez treści notatki (dane osobowe zostają w ERP).</p>
            </div><aside class="hr-qn-r"><h6>Co się stanie</h6><ul data-sum></ul><div class="hr-qn-pv" data-pv></div></aside>
            <footer class="hr-qn-foot"><span class="hr-msg" data-qmsg></span><span class="tm-spacer"></span><button type="button" class="btn btn-light btn-sm" data-close2>Anuluj</button>
                <button type="button" class="btn btn-light btn-sm" data-again>Zapisz i dodaj następną</button><button type="submit" class="btn btn-primary btn-sm">Zapisz notatkę</button></footer></form>`;
        if (!dialog.open) dialog.showModal();
        const f = card.querySelector('[data-qn]');
        const close = () => { dialog.close(); if (after) after(); };
        card.querySelector('[data-close]').addEventListener('click', () => dialog.close());
        card.querySelector('[data-close2]').addEventListener('click', () => dialog.close());
        const kindOf = () => o.kinds.find(k => k.code === st.kind);
        function topics() {
            const k = kindOf();
            card.querySelector('#hrTopics').innerHTML = k.topics.map(t => `<option value="${esc(t)}">`).join('');
            card.querySelector('[data-topics]').innerHTML = k.topics.map(t => `<button type="button" class="hr-topic" data-topic="${esc(t)}">${esc(t)}</button>`).join('');
            card.querySelectorAll('[data-topic]').forEach(b => b.addEventListener('click', () => { f.topic.value = b.dataset.topic; summary(); }));
        }
        function summary() {
            const emp = f.employee_id.selectedOptions[0];
            const name = f.employee_id.value ? emp.textContent.split(' · ')[0] : 'pracownik';
            const priv = st.audience.length === 1 && st.audience[0] === 'me';
            const auds = o.audience.filter(a => st.audience.includes(a.code) && a.code !== 'me');
            const nRec = auds.reduce((s, a) => s + (a.code === 'admin' ? 1 : a.count), 0);
            const ch = o.channels.filter(c => st.channels.includes(c.code) && c.code !== 'task').map(c => c.label.toLowerCase());
            const who = f.followup_user.selectedOptions[0] ? f.followup_user.selectedOptions[0].textContent.replace('kto : ', '') : '';
            card.querySelector('[data-sum]').innerHTML = [
                `Notatka <span class="hr-kind hr-k-${esc(st.kind)}">${esc(kindOf().label)}</span> w karcie <b>${esc(name)}</b>, ${priv ? 'prywatna (Ty i administrator)' : 'widoczna dla : <b>' + esc(auds.map(a => a.label).join(' + ') || '—') + '</b>'}.`,
                priv || !ch.length ? 'Bez powiadomień.' : esc(ch.join(' + ')) + ' do : ' + esc(auds.map(a => a.label).join(', ')) + ` (${nRec} ${nRec === 1 ? 'osoba' : 'osób'}).`,
                st.followup ? `Follow-up do <b>${f.followup_due.value ? d(f.followup_due.value) : '…'}</b> → ${esc(who)}${st.channels.includes('task') ? ', zadanie w « Mój tydzień »' : ''}, przypomnienie w dniu terminu, czerwone po terminie.` : 'Bez follow-upu.',
                'W podsumowaniu miesiąca : 1 notatka więcej.'].map(x => `<li>${x}</li>`).join('');
            card.querySelector('[data-pv]').innerHTML = st.channels.includes('email') && !priv ? `<b>Podgląd e-maila</b>HR · nowa notatka (${esc(kindOf().label)}) — ${esc(name)} : « ${esc(f.topic.value || '…')} ».${st.followup && f.followup_due.value ? ' Follow-up do ' + d(f.followup_due.value) + '.' : ''} Otwórz w ERP →` : '';
        }
        const toggle = (sel, key, multi) => card.querySelectorAll(sel).forEach(b => b.addEventListener('click', () => {
            const v = b.dataset[key];
            if (!multi) { st.kind = v; card.querySelectorAll(sel).forEach(x => x.classList.toggle('is-on', x === b)); topics(); summary(); return; }
            const arr = multi === 'aud' ? st.audience : st.channels;
            if (multi === 'aud' && v === 'me') arr.splice(0, arr.length, 'me');
            else { const i = arr.indexOf(v); if (i >= 0) arr.splice(i, 1); else arr.push(v); if (multi === 'aud') { const m = arr.indexOf('me'); if (m >= 0) arr.splice(m, 1); } }
            card.querySelectorAll(sel).forEach(x => x.classList.toggle('is-on', arr.includes(x.dataset[key])));
            summary();
        }));
        toggle('[data-kind]', 'kind', false);
        toggle('[data-aud]', 'aud', 'aud');
        toggle('[data-chan]', 'chan', 'chan');
        card.querySelectorAll('[data-fu]').forEach(b => b.addEventListener('click', () => {
            st.followup = b.dataset.fu === '1';
            card.querySelectorAll('[data-fu]').forEach(x => x.classList.toggle('is-on', x === b));
            f.followup_due.disabled = f.followup_user.disabled = !st.followup;
            if (st.followup && !f.followup_due.value) f.followup_due.value = new Date(Date.now() + 7 * 864e5).toISOString().slice(0, 10);
            summary();
        }));
        ['employee_id', 'topic', 'followup_due', 'followup_user'].forEach(k => f[k].addEventListener('input', summary));
        f.employee_id.addEventListener('change', summary);
        topics();
        summary();
        async function save(again) {
            const msg = card.querySelector('[data-qmsg]');
            f.querySelectorAll('.is-invalid').forEach(x => x.classList.remove('is-invalid'));
            try {
                const r = await api('POST', '/ajax/hr/quick-note', {employee_id: Number(f.employee_id.value), kind: st.kind, topic: f.topic.value, body: f.body.value,
                    audience: st.audience, channels: st.channels, followup: st.followup, followup_due: f.followup_due.value, followup_user: Number(f.followup_user.value)});
                if (again) { f.topic.value = ''; f.body.value = ''; msg.textContent = r.message; summary(); if (after) after(); quickNote(null, after); setTimeout(() => { const m = card.querySelector('[data-qmsg]'); if (m) m.textContent = r.message; }, 0); }
                else { close(); }
            } catch (x) {
                msg.textContent = x.message;
                Object.keys(x.fields || {}).forEach(k => { if (f[k]) f[k].classList.add('is-invalid'); });
            }
        }
        f.addEventListener('submit', (e) => { e.preventDefault(); save(false); });
        card.querySelector('[data-again]').addEventListener('click', () => save(true));
        (employeeId ? f.topic : f.employee_id).focus();
    }

    // ================================ Dokumenty ================================
    async function documents() {
        const r = await api('GET', '/ajax/hr/documents');
        let kind = '';
        const draw = () => {
            const list = r.documents.filter(x => !kind || x.kind === kind);
            body.querySelector('[data-rows]').innerHTML = list.map(x => `<tr><td><a href="#" data-open="${x.employee_id}">${esc(x.name)}</a></td><td><span class="tm-tag">${esc(x.kind_label)}</span></td>
                <td><a href="${root}/hr/document/${x.id}?inline=1" target="_blank" rel="noopener">${esc(x.title)}</a><div class="tm-sub">${esc(x.filename)} · ${size(x.size)}</div></td>
                <td class="text-end tm-num">${d(x.date)}</td></tr>`).join('') || '<tr><td colspan="4" class="at-empty">Brak dokumentów.</td></tr>';
            body.querySelectorAll('[data-open]').forEach(a => a.addEventListener('click', (e) => { e.preventDefault(); openCard(Number(a.dataset.open)); }));
        };
        body.innerHTML = `<div class="tm-bar"><div class="hr-chips">${[['', 'Wszystkie'], ...Object.entries(r.kinds)].map(([k, l]) => `<button type="button" class="hr-chip ${k === '' ? 'is-on' : ''}" data-kind="${k}">${esc(l)}</button>`).join('')}</div></div>
            <div class="table-responsive at-card hr-card"><table class="table tm-table"><thead><tr><th>Pracownik</th><th>Rodzaj</th><th>Dokument</th><th class="text-end">Data</th></tr></thead><tbody data-rows></tbody></table></div>
            <p class="hr-note">Nowy dokument : w karcie pracownika (zakładka Pracownicy → wiersz → Dokumenty).</p>`;
        body.querySelectorAll('[data-kind]').forEach(b => b.addEventListener('click', () => { kind = b.dataset.kind; body.querySelectorAll('[data-kind]').forEach(x => x.classList.toggle('is-on', x === b)); draw(); }));
        draw();
    }

    // ================================ Notatki miesiąca (makieta 38) ================================
    async function notes(month) {
        month = month || new Date().toISOString().slice(0, 7);
        const [r, fu] = await Promise.all([api('GET', '/ajax/hr/notes?month=' + month), api('GET', '/ajax/hr/followups')]);
        const shift = (m, k) => { const [y, mo] = m.split('-').map(Number); const t = new Date(y, mo - 1 + k, 1); return t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0'); };
        const c = r.counts;
        body.innerHTML = `<div class="tm-bar"><div class="tm-nav"><button type="button" class="btn btn-light btn-sm" data-m="${shift(month, -1)}">‹</button>
                <b class="hr-month">${esc(r.month_label)}</b><button type="button" class="btn btn-light btn-sm" data-m="${shift(month, 1)}">›</button></div><span class="tm-spacer"></span>
                ${r.summary ? `<span class="tm-sub">podsumowanie wysłane ${d(r.summary.sent_at.slice(0, 10))} ${r.summary.sent_at.slice(11, 16)}${r.summary.auto ? ' (automatycznie)' : ''}</span>` : '<span class="tm-sub">podsumowanie : ostatniego dnia miesiąca o 18:00, e-mailem do administratora</span>'}
                <button type="button" class="btn btn-primary btn-sm" data-quick>＋ Szybka notatka</button>
                ${admin ? `<button type="button" class="btn btn-light btn-sm" data-send ${c.all ? '' : 'disabled title="Brak notatek w tym miesiącu — nie ma czego wysłać."'}>Wyślij podsumowanie teraz</button>` : ''}</div>
            <div class="tm-kpis"><div class="tm-kpi"><small>Notatki w miesiącu</small><b>${c.all}</b><span>${c.people} os.</span></div><div class="tm-kpi"><small>Publiczne</small><b>${c.public}</b></div>
                <div class="tm-kpi"><small>Prywatne</small><b>${c.private}</b><span>${admin ? 'wszystkie' : 'tylko Twoje'}</span></div><div class="tm-kpi"><small>Do administratora</small><b>${c.to_admin}</b></div>
                <div class="tm-kpi ${fu.late ? 'is-danger' : (fu.open ? 'is-warn' : '')}"><small>Follow-upy otwarte</small><b>${fu.open}</b><span>${fu.late ? fu.late + ' po terminie' : 'w terminie'}</span></div></div>
            ${fu.items.length ? `<div class="at-card hr-card hr-fu-list"><h5>Follow-upy otwarte</h5><table class="table tm-table"><tbody>${fu.items.map(x => `<tr class="${x.late ? 'is-late' : ''}">
                <td><span class="hr-kind hr-k-${esc(x.kind)}">${esc(x.kind_label)}</span> <a href="#" data-open="${x.employee_id}">${esc(x.name)}</a> — ${esc(x.topic)}</td>
                <td class="text-end tm-num">${d(x.due)}${x.late ? ' <span class="tm-tag tm-tag-warn">po terminie</span>' : ''}</td><td>${esc(x.who || '')}</td>
                <td class="text-end">${x.can_close ? `<button type="button" class="btn btn-light btn-sm" data-fu-done="${x.id}">Zrobione</button>` : ''}</td></tr>`).join('')}</tbody></table></div>` : ''}
            <div class="at-card hr-card hr-month-list">${r.people.map(p => `<section class="hr-person"><h5><a href="#" data-open="${p.id}">${esc(p.name)}</a> <span class="tm-sub">${esc(p.group || '')}</span></h5>
                ${p.notes.map(n => noteHtml(n)).join('')}</section>`).join('') || '<p class="at-empty p-3">Brak notatek w tym miesiącu.</p>'}</div>
            <p class="hr-note" data-sendmsg></p>`;
        body.querySelectorAll('[data-m]').forEach(b => b.addEventListener('click', () => notes(b.dataset.m)));
        body.querySelector('[data-quick]').addEventListener('click', () => quickNote(null, () => notes(month)));
        bindFollowups(body, () => notes(month));
        const want = new URLSearchParams(location.search).get('note');
        if (want) { const el = document.getElementById('note-' + want); if (el) { el.classList.add('is-target'); el.scrollIntoView({block: 'center'}); } }
        body.querySelectorAll('[data-open]').forEach(a => a.addEventListener('click', (e) => { e.preventDefault(); openCard(Number(a.dataset.open)); }));
        body.querySelectorAll('[data-del-note]').forEach(b => b.addEventListener('click', async () => {
            if (!confirm('Usunąć notatkę ?')) return;
            try { await api('DELETE', '/ajax/hr/notes/' + b.dataset.delNote); notes(month); } catch (x) { alert(x.message); }
        }));
        const send = body.querySelector('[data-send]');
        if (send) send.addEventListener('click', async () => {
            send.disabled = true;
            try { const x = await api('POST', '/ajax/hr/notes/summary', {month}); body.querySelector('[data-sendmsg]').textContent = x.message; }
            catch (x) { body.querySelector('[data-sendmsg]').textContent = x.message; send.disabled = false; }
        });
    }

    // ================================ Ustawienia HR ================================
    async function settings() {
        const r = await api('GET', '/ajax/hr/rules');
        const f = (label, name, v, unit) => `<label class="hr-f"><span>${label}</span><span class="hr-unit"><input class="form-control form-control-sm" type="number" name="${name}" value="${v}" ${admin ? '' : 'disabled'}> ${unit}</span></label>`;
        body.innerHTML = `<form class="at-card hr-card hr-sec hr-settings" data-rules><h5>Ustawienia HR</h5><div class="hr-fields">
                ${f('Wiek emerytalny — kobiety', 'retire_k', r.retire_k, 'lat')}${f('Wiek emerytalny — mężczyźni', 'retire_m', r.retire_m, 'lat')}
                ${f('Przypomnienie przed końcem umowy i badań', 'alert_days', r.alert_days, 'dni')}</div>
                ${admin ? '<div class="hr-actions"><button type="submit" class="btn btn-primary btn-sm">Zapisz</button><span class="hr-msg" data-msg></span></div>' : '<p class="tm-sub">Ustawienia zmienia administrator.</p>'}
                <p class="tm-sub">Podsumowanie notatek : ostatniego dnia miesiąca o 18:00, e-mailem do administratora (kto, ile notatek ; treść tylko w ERP).</p></form>`;
        const form = body.querySelector('[data-rules]');
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            try { await api('PUT', '/ajax/hr/rules', {retire_k: form.retire_k.value, retire_m: form.retire_m.value, alert_days: form.alert_days.value}); form.querySelector('[data-msg]').textContent = 'Zapisano.'; }
            catch (x) { form.querySelector('[data-msg]').textContent = x.message; }
        });
    }

    // ================================ Szablony umów (makieta 42) ================================
    async function templates() {
        if (!canPay) { body.innerHTML = '<div class="alert alert-warning">Szablony umów (wynagrodzenie, PESEL, adres) : administrator i finanse.</div>'; return; }
        const [t, ppl] = await Promise.all([api('GET', '/ajax/hr/templates'), api('GET', '/ajax/hr/people')]);
        const people = ppl.people.filter(p => p.active);
        let code = t.templates[0] ? t.templates[0].code : null;
        let emp = people[0] ? people[0].id : null;
        const extra = {sign_date: new Date().toISOString().slice(0, 10)};
        body.innerHTML = `<div class="hr-tpl"><div class="hr-tpl-list">${t.templates.map(x => `<button type="button" class="hr-tpl-item" data-code="${esc(x.code)}"><b>${esc(x.title)}</b><span class="tm-sub">${esc(x.legal || '')}</span></button>`).join('')}
                <p class="hr-note">Pola : ${Object.keys(t.fields).map(k => '{' + k + '}').join(' ')}. Wzory do sprawdzenia przez prawnika / biuro kadrowe przed pierwszym użyciem.</p></div>
            <div class="hr-tpl-main"><div class="tm-bar hr-tpl-bar"><select class="form-select form-select-sm" data-emp>${people.map(p => `<option value="${p.id}">${esc(p.name)}${p.group ? ' · ' + esc(p.group) : ''}</option>`).join('')}</select>
                    <span class="tm-spacer"></span><a class="btn btn-light btn-sm" data-docx href="#"><i class="bi bi-file-earmark-word"></i> Pobierz DOCX</a>
                    <button type="button" class="btn btn-primary btn-sm" data-pdf><i class="bi bi-file-earmark-pdf"></i> Zapisz PDF w dokumentach</button>
                    ${t.is_admin ? '<button type="button" class="btn btn-link btn-sm" data-edit>Edytuj treść</button>' : ''}</div>
                <div class="hr-tpl-extra">${[['sign_date', 'Data zawarcia', 'date'], ['date_from', 'Od (puste = z karty)', 'date'], ['date_to', 'Do (puste = z karty)', 'date'], ['salary', 'Wynagrodzenie (puste = z karty)', 'text'],
                    ['position', 'Stanowisko (puste = z karty)', 'text'], ['work_time', 'Wymiar (puste = z karty)', 'text'], ['subject', 'Przedmiot (zlecenie, dzieło)', 'text'], ['reason', 'Przyczyna (wypowiedzenie, świadectwo)', 'text']]
                    .map(([k, l, ty]) => `<label class="hr-f" data-extra-f="${k}"><span>${l}</span><input class="form-control form-control-sm" type="${ty}" data-extra="${k}" value="${esc(extra[k] || '')}"></label>`).join('')}</div>
                <div class="hr-doc" data-preview><p class="at-empty">Ładowanie…</p></div><p class="hr-msg" data-msg></p></div></div>`;
        const sel = body.querySelector('[data-emp]');
        if (emp) sel.value = emp;
        const preview = body.querySelector('[data-preview]');
        const msg = body.querySelector('[data-msg]');
        const mark = (s) => esc(s).replace(/⟦(.*?)⟧/g, '<span class="hr-missing">$1</span>').replace(/\n/g, '<br>');
        let timer = null;
        async function draw() {
            body.querySelectorAll('[data-code]').forEach(b => b.classList.toggle('is-on', b.dataset.code === code));
            const fields = (t.templates.find(x => x.code === code) || {fields: []}).fields;
            body.querySelectorAll('[data-extra-f]').forEach(l => {
                const k = l.dataset.extraF;
                const map = {date_from: 'data_od', date_to: 'data_do', salary: 'wynagrodzenie', position: 'stanowisko', work_time: 'wymiar', subject: 'przedmiot', reason: 'przyczyna', sign_date: 'data_zawarcia'};
                l.hidden = !(fields.includes(map[k]) || (k === 'salary' && fields.includes('stawka_godzinowa')));
            });
            try {
                const r = await api('POST', '/ajax/hr/templates/' + code + '/render', {employee_id: emp, extra});
                preview.innerHTML = `<h4>${esc(r.heading)}</h4>${r.paragraphs.map(x => `<p>${mark(x)}</p>`).join('')}
                    <div class="hr-sign"><span>…………………… ${esc(r.parties[0])}</span><span>…………………… ${esc(r.parties[1])}</span></div>`;
                msg.textContent = r.missing.length ? 'Brakujące dane (na czerwono) : ' + r.missing.join(', ') + ' — uzupełnij w karcie HR albo powyżej.' : '';
                body.querySelector('[data-pdf]').disabled = r.missing.length > 0;
                const q = new URLSearchParams({employee: emp});
                Object.entries(extra).forEach(([k, v]) => { if (v) q.append('extra[' + k + ']', v); });
                const a = body.querySelector('[data-docx]');
                a.href = r.missing.length ? '#' : root + '/hr/templates/' + code + '/docx?' + q;
                a.classList.toggle('disabled', r.missing.length > 0);
            } catch (e) { preview.innerHTML = `<div class="alert alert-danger">${esc(e.message)}</div>`; }
        }
        body.querySelectorAll('[data-code]').forEach(b => b.addEventListener('click', () => { code = b.dataset.code; draw(); }));
        sel.addEventListener('change', () => { emp = Number(sel.value); draw(); });
        body.querySelectorAll('[data-extra]').forEach(i => i.addEventListener('input', () => { extra[i.dataset.extra] = i.value; clearTimeout(timer); timer = setTimeout(draw, 300); }));
        body.querySelector('[data-pdf]').addEventListener('click', async () => {
            try { const r = await api('POST', '/ajax/hr/templates/' + code + '/pdf', {employee_id: emp, extra}); msg.textContent = r.message; } catch (e) { msg.textContent = e.message; }
        });
        const edit = body.querySelector('[data-edit]');
        if (edit) edit.addEventListener('click', () => {
            const x = t.templates.find(y => y.code === code);
            card.innerHTML = `<header class="tm-dialog-head"><h4 id="hrDialogTitle">Treść szablonu</h4><button type="button" class="btn-close" data-close aria-label="Zamknij"></button></header>
                <form class="tm-dialog-body" data-tpl><label class="hr-f"><span>Tytuł</span><input class="form-control form-control-sm" name="title" value="${esc(x.title)}"></label>
                <label class="hr-f"><span>Treść (pierwszy akapit = nagłówek ; pusta linia = nowy akapit)</span><textarea class="form-control hr-tpl-edit" name="body" rows="18">${esc(x.body)}</textarea></label>
                <div class="hr-actions"><button type="submit" class="btn btn-primary btn-sm">Zapisz</button><span class="hr-msg" data-tmsg></span></div></form>`;
            dialog.showModal();
            card.querySelector('[data-close]').addEventListener('click', () => dialog.close());
            card.querySelector('[data-tpl]').addEventListener('submit', async (e) => {
                e.preventDefault();
                try { t.templates = (await api('PUT', '/ajax/hr/templates/' + code, {title: e.target.title.value, body: e.target.body.value})); dialog.close(); templates(); }
                catch (z) { card.querySelector('[data-tmsg]').textContent = z.message; }
            });
        });
        if (code && emp) draw(); else preview.innerHTML = '<p class="at-empty">Brak aktywnych pracowników.</p>';
    }

    dialog.addEventListener('click', (e) => { if (e.target === dialog) dialog.close(); });
    ({people, documents, notes, settings, templates}[tab] || people)().catch(e => { body.innerHTML = `<div class="alert alert-danger">${esc(e.message)}</div>`; });
})();
