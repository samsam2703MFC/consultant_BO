// [440] Różnice kursowe (Finanse) — makieta « Różnice kursowe » : wpłaty klientów w walucie z wyciągów ING, kurs wpłaty
// (konto EUR : NBP z dnia roboczego przed wpłatą ; konto PLN : kurs ING), faktura i kurs faktury (NBP z dnia roboczego przed
// fakturą), różnica na fakturę, « do potwierdzenia » (wybór dokumentu), « czeka na fakturę », wyciągi (PDF zaszyfrowany,
// wpłaty wpisane ręcznie), kursy NBP, zestawienie miesiąca PDF / CSV. Dane : App\Services\Finances\Fx\FxDifferenceService.
(() => {
    const app = document.getElementById('fxApp');
    if (!app) return;
    const esc = at.esc;
    let D = JSON.parse(document.getElementById('fxBoot').textContent);
    const BASE = '/ajax/finances/fx-differences';
    const picked = {};             // id wpłaty → klucz dokumentu wybranego (« wdt:12 »)
    let modal = null;               // okno otwarte (wyciąg / wpłata)

    const group = (s) => s.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    const fix = (v, d) => { const [i, f] = Math.abs(Number(v)).toFixed(d).split('.'); return (Number(v) < 0 ? '−' : '') + group(i) + (f ? ',' + f : ''); };
    const money = (v) => v === null || v === undefined ? '—' : fix(v, 2);
    const rate = (v) => v === null || v === undefined ? '—' : fix(v, 4);
    const signed = (v) => v === null || v === undefined ? '—' : (v > 0 ? '+' : '') + fix(v, 2);
    const tone = (v) => v > 0 ? 'fx-pos' : (v < 0 ? 'fx-neg' : '');
    const dm = (d) => d ? `${d.slice(8, 10)}.${d.slice(5, 7)}` : '';
    const plural = (n, one, few, many) => n === 1 ? one : ([2, 3, 4].includes(n % 10) && ![12, 13, 14].includes(n % 100) ? few : many);
    const qty = (v) => { const s = fix(v, 2); return s.endsWith(',00') ? s.slice(0, -3) : s; };
    const url = (path) => `${at.root}${BASE}${path}${path.includes('?') ? '&' : '?'}month=${encodeURIComponent(D.month)}`;

    function header() {
        const seg = D.nav.map(n => n.on ? `<span class="is-on">${esc(n.label)}</span>`
            : (n.disabled ? `<span class="is-off">${esc(n.label)}</span>` : `<a href="?month=${esc(n.month)}">${esc(n.label)}</a>`)).join('');
        const acts = D.values ? `<button type="button" class="fx-btn" data-fx-upload><i class="bi bi-upload"></i> Wgraj wyciąg ING (PDF)</button>
            <button type="button" class="fx-btn fx-btn--primary" data-fx-menu><i class="bi bi-file-earmark-text"></i> Zestawienie miesiąca (PDF / CSV)</button>
            <div class="fx-menu" data-fx-menu-box><a href="${esc(url('/pdf'))}"><i class="bi bi-filetype-pdf"></i> PDF dla księgowości</a>
                <a href="${esc(url('/csv'))}"><i class="bi bi-filetype-csv"></i> CSV (jedna linia na fakturę)</a></div>` : '';
        return `<header class="fx-top"><div><p class="fx-top__kicker">Finanse</p><h1>Różnice kursowe</h1></div>
            <div class="fx-acts"><nav class="fx-seg" aria-label="Miesiąc">${seg}</nav>${acts}</div></header>`;
    }

    function kpis() {
        const K = D.kpi;
        const paid = K.paid.length ? K.paid.map(p => `${money(p.amount)} ${esc(p.currency)}`).join(' · ') : '0,00 EUR';
        const parts = [];
        const st = K.open_by_state || {};
        if (st.to_confirm) parts.push(`${st.to_confirm} do potwierdzenia`);
        if (st.waiting) parts.push(`${st.waiting} czeka na fakturę`);
        if (st.no_rate) parts.push(`${st.no_rate} bez kursu`);
        if (st.overpaid) parts.push(`${st.overpaid} z nadwyżką`);
        const k = (label, value, sub, cls = '') => `<div class="fx-kpi"><div class="fx-kpi__l">${label}</div><div class="fx-kpi__v ${cls}">${value}</div><div class="fx-kpi__s">${sub}</div></div>`;
        return `<div class="fx-kpis">
            ${k('Wpłaty w walucie', paid, `${K.payments} ${plural(K.payments, 'wpłata', 'wpłaty', 'wpłat')}${K.statements ? ` z ${K.statements} ${plural(K.statements, 'wyciągu', 'wyciągów', 'wyciągów')}` : ''}`)}
            ${k('Policzone', K.counted, `${K.invoices} ${plural(K.invoices, 'faktura', 'faktury', 'faktur')}`)}
            ${k('Do wyjaśnienia', K.open, parts.join(' · ') || 'nic', K.open ? 'fx-warn' : '')}
            ${k('Różnice dodatnie', `${signed(K.positive)} zł`, `${K.positive_rows} ${plural(K.positive_rows, 'pozycja', 'pozycje', 'pozycji')}`, 'fx-pos')}
            ${k('Różnice ujemne · saldo', `${signed(K.negative)} zł`, `saldo <b class="${tone(K.balance)}">${signed(K.balance)} zł</b>`, 'fx-neg')}</div>`;
    }

    function chip(label, t) { return `<span class="fx-chip fx-chip--${t}">${t === 'ok' ? '<i class="bi bi-check2"></i>' : ''}${esc(label)}</span>`; }

    function payCell(r) {
        if (!r.pay_rate) return `—<span class="fx-sub">${r.account === 'PLN' ? 'brak kursu ING' : 'brak kursu NBP'}</span>`;
        return `${rate(r.pay_rate.rate)}<span class="fx-sub">${r.pay_rate.source === 'bank' ? 'kurs ING (przewalutowanie)' : 'NBP ' + dm(r.pay_rate.date)}</span>`;
    }

    function docCell(r) {
        if (r.state === 'to_confirm') {
            const c = r.candidates || [];
            const list = c.map(x => `<label><input type="radio" name="fx-pick-${r.id}" value="${esc(x.key)}" ${picked[r.id] === x.key ? 'checked' : ''} data-fx-pick="${r.id}">
                ${esc(x.number)} · ${money(x.open)} ${esc(r.currency)}${x.open < x.total ? ` <span class="fx-sub">z ${money(x.total)}</span>` : ''}</label>`).join('');
            return `Do potwierdzenia :${r.note ? `<span class="fx-sub">${esc(r.note)}</span>` : ''}
                ${c.length ? `<div class="fx-pick">${list}</div>` : `<span class="fx-sub">${r.customer ? 'brak otwartych dokumentów klienta w ' + esc(r.currency) : 'wskaż klienta : « Popraw »'}</span>`}`;
        }
        const pieces = r.pieces.map(p => {
            const head = r.pieces.length > 1 ? `${esc(p.invoice.number)} · ${qty(p.amount)} ${esc(r.currency)}` : `${esc(p.invoice.number)} · ${dm(p.invoice.date)}`;
            const inv = p.inv_rate ? `${rate(p.inv_rate.rate)} (NBP ${dm(p.inv_rate.date)}${p.inv_rate.date && p.invoice.date && holidayGap(p.inv_rate.date, p.invoice.date) ? ' ; ' + holidayGap(p.inv_rate.date, p.invoice.date) : ''})` : 'brak kursu NBP';
            const pf = p.proforma ? `<span class="fx-sub">przedpłata ${esc(p.proforma.number)}${p.proforma.pd_paid_on ? ' · Płatności dnia : ' + dm(p.proforma.pd_paid_on) : ''}</span>` : '';
            const other = !p.in_month ? `<span class="fx-sub">liczy się : ${esc(p.month_label)}</span>` : '';
            return `<div class="fx-piece">${head}<span class="fx-sub">${inv}</span>${pf}${other}</div>`;
        }).join('');
        const waiting = r.waiting.map(w => `<div class="fx-piece">Faktura jeszcze nie wystawiona<span class="fx-sub">${esc(w.proforma.number)} · ${qty(w.amount)} ${esc(r.currency)} — policzy się w dniu faktury</span></div>`).join('');
        const rest = r.remainder ? `<div class="fx-piece fx-warn">Nadwyżka ${money(r.remainder)} ${esc(r.currency)}<span class="fx-sub">bez dokumentu — do wyjaśnienia</span></div>` : '';
        return pieces + waiting + rest || '—';
    }

    // « NBP 10.11 ; 11.11 święto » : jour(s) ouvré(s) sans table entre le kurs et la facture (hors week-end).
    function holidayGap(rateDay, docDay) {
        const out = [];
        for (let d = new Date(rateDay + 'T12:00:00Z'); ; ) {
            d.setUTCDate(d.getUTCDate() + 1);
            const s = d.toISOString().slice(0, 10);
            if (s >= docDay) break;
            if (d.getUTCDay() !== 0 && d.getUTCDay() !== 6) out.push(dm(s));
        }
        return out.length ? out.join(', ') + ' święto' : '';
    }

    function diffCell(r) {
        const counted = r.pieces.filter(p => p.state === 'counted' && p.in_month);
        if (!counted.length) return '—';
        const calc = counted.length === 1
            ? `${qty(counted[0].amount)} × (${rate(r.pay_rate.rate)} − ${rate(counted[0].inv_rate.rate)})`
            : counted.map(p => signed(p.diff)).join(' / ');
        return `<b class="${tone(r.diff)}">${signed(r.diff)} zł</b><span class="fx-calc">${calc}</span>`;
    }

    function stateCell(r) {
        let html = chip(r.state_label, r.tone);
        if (r.state === 'to_confirm' && (r.candidates || []).length) html += `<div class="fx-rowacts"><button type="button" class="fx-btn fx-btn--sm" data-fx-allocate="${r.id}" ${picked[r.id] ? '' : 'disabled'}>Zapisz</button></div>`;
        html += `<div class="fx-rowacts fx-rowacts--links"><button type="button" class="fx-link" data-fx-edit="${r.id}">Popraw</button>
            <button type="button" class="fx-link" data-fx-ignore="${r.id}" title="To nie jest wpłata klienta za dokument w walucie">Nie dotyczy</button></div>`;
        return html;
    }

    function table() {
        const rows = D.rows.map(r => {
            const pln = r.account === 'PLN' && r.amount_pln ? ` · ING : ${money(r.amount_pln)} zł` : '';
            const title = r.title ? esc(r.title) : (r.refs.length ? esc(r.refs.join(', ')) : '<i>bez tytułu</i>');
            return `<tr class="${r.earlier ? 'fx-earlier' : ''}" data-fx-row="${r.id}">
                <td>${dm(r.booked_on)}${r.earlier ? `<span class="fx-sub">${r.booked_on.slice(0, 4)}</span>` : ''}<br><span class="fx-acc">${esc(r.account)}</span></td>
                <td>${r.customer ? esc(r.customer.name) : '<span class="fx-warn">klient nieznany</span>'}<span class="fx-sub">${title}${pln}</span>
                    ${r.earlier ? '<span class="fx-sub">wpłata z wcześniejszego miesiąca</span>' : ''}</td>
                <td class="n">${money(r.amount)} ${esc(r.currency)}</td>
                <td>${payCell(r)}</td>
                <td>${docCell(r)}</td>
                <td class="n">${diffCell(r)}</td>
                <td>${stateCell(r)}</td></tr>`;
        }).join('');
        const T = D.totals;
        const empty = `<tr><td colspan="7"><p class="fx-empty">Brak wpłat klientów w walucie w tym miesiącu — wgraj wyciąg ING albo dodaj wpłatę ręcznie.</p></td></tr>`;
        return `<section class="fx-card"><h2>Wpłaty klientów w walucie — ${esc(D.month_label)}</h2>
            <div class="fx-scroll"><table class="fx-table"><thead><tr><th>Wpłata</th><th>Klient · tytuł</th><th class="n">Kwota</th><th>Kurs wpłaty</th>
                <th>Faktura · kurs faktury</th><th class="n">Różnica</th><th>Stan</th></tr></thead>
            <tbody>${rows || empty}
                <tr class="fx-tot"><td colspan="5">Razem ${esc(D.month_label)} (policzone)</td>
                    <td class="n"><span class="fx-pos">${signed(T.positive)}</span> / <span class="fx-neg">${signed(T.negative)}</span><span class="fx-sub">saldo ${signed(T.balance)} zł</span></td><td></td></tr>
            </tbody></table></div>
            <p class="fx-note">Kurs faktury : średni NBP z ostatniego dnia roboczego przed datą faktury. Kurs wpłaty : konto EUR — średni NBP z dnia roboczego przed wpłatą ;
                konto PLN — kurs zastosowany przez ING (z wyciągu). Różnica = kwota w walucie × (kurs wpłaty − kurs faktury) : plus = dodatnia, minus = ujemna.
                Przedpłata przed fakturą liczy się w dniu wystawienia faktury.</p></section>`;
    }

    function statements() {
        const list = D.statements.map(s => `<div class="fx-li"><div><b>${esc(s.label)}</b>
                <span class="fx-sub">${s.customers} ${plural(s.customers, 'wpłata klienta', 'wpłaty klientów', 'wpłat klientów')}${s.other ? ` · ${s.other} ${plural(s.other, 'inna linia', 'inne linie', 'innych linii')}` : ''}</span>
                ${s.status === 'unsupported' ? `<span class="fx-sub" title="${esc(s.message || '')}">format ING w przygotowaniu — wpłaty wpisane ręcznie</span>` : ''}
                ${s.status === 'no_text' ? `<span class="fx-sub">${esc(s.message || '')}</span>` : ''}
                <span class="fx-sub">${s.has_file ? `<a class="fx-link" href="${esc(at.root + BASE + '/statements/' + s.id + '/file')}" target="_blank" rel="noopener">PDF</a>` : ''}
                    <button type="button" class="fx-link" data-fx-del-statement="${s.id}">Usuń</button></span></div>${chip(s.chip[0], s.chip[1])}</div>`).join('');
        return `<section class="fx-card"><h2>Wyciągi ING</h2><div class="fx-list">${list || '<p class="fx-empty">Brak wyciągów w tym miesiącu.</p>'}</div>
            <button type="button" class="fx-drop" data-fx-drop>Przeciągnij PDF wyciągu ING tutaj<br>konto EUR albo PLN · plik zapisywany zaszyfrowany</button>
            <div class="fx-rowacts"><button type="button" class="fx-btn fx-btn--sm" data-fx-add><i class="bi bi-plus-lg"></i> Dodaj wpłatę ręcznie</button></div></section>`;
    }

    function rates() {
        const R = D.rates;
        const list = R.list.map(r => `<div class="fx-li"><span>${dm(r.date)} (${esc(r.table)})</span><b>${rate(r.mid)}</b></div>`).join('');
        const last = R.last_check ? (R.last_check.slice(0, 10) === D.today ? 'dziś ' + R.last_check.slice(11, 16) : dm(R.last_check.slice(0, 10)) + ' ' + R.last_check.slice(11, 16)) : 'jeszcze nie';
        return `<section class="fx-card"><h2>Kursy NBP (tabela A, ${esc(R.currency)})</h2><div class="fx-list">${list || '<p class="fx-empty">Brak pobranych kursów.</p>'}</div>
            <p class="fx-note">${R.host ? `Pobierane co dzień z ${esc(R.host)} ; ostatnio ${esc(last)}.` : 'Brak adresu API NBP (Ustawienia › Parametry : fx.nbp_api_url).'}
                Dzień bez tabeli (weekend, święto) : poprzedni dzień roboczy.</p>
            ${R.error ? `<p class="fx-error">${esc(R.error)} — brakujące kursy zostaną pobrane później.</p>` : ''}</section>`;
    }

    function render() {
        if (!D.values) {
            app.innerHTML = header() + `<section class="fx-card"><div class="fx-lock"><i class="bi bi-lock"></i><div><b>Wpłaty i kwoty klientów : profil Finanse, Kierownik operacyjny albo CEO.</b>
                <p class="fx-note">Wybierz profil Finanse albo Kierownik operacyjny pod awatarem, żeby zobaczyć wpłaty w walucie, różnice kursowe i zestawienie miesiąca.</p></div></div></section>`;
            return;
        }
        app.innerHTML = header() + kpis() + `<div class="fx-grid"><div>${table()}</div><div>${statements()}${rates()}</div></div>`;
    }

    // --- Okna : wyciąg (PDF) i wpłata ręcznie ------------------------------------------------------------------------

    function close() { if (modal) { modal.remove(); modal = null; } }

    function open(html, onSubmit) {
        close();
        modal = document.createElement('div');
        modal.className = 'fx-modal';
        modal.innerHTML = `<div class="fx-modal__box" role="dialog" aria-modal="true">${html}<p class="fx-error" data-fx-err hidden></p>
            <div class="fx-modal__acts"><button type="button" class="fx-btn fx-btn--ghost" data-fx-close>Anuluj</button><button type="submit" form="fxForm" class="fx-btn fx-btn--primary">Zapisz</button></div></div>`;
        document.body.appendChild(modal);
        modal.addEventListener('click', (e) => { if (e.target === modal || e.target.closest('[data-fx-close]')) close(); });
        const form = modal.querySelector('#fxForm');
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = modal.querySelector('button[type=submit]');
            btn.disabled = true;
            const res = await onSubmit(form);
            if (!modal) return;
            btn.disabled = false;
            if (res && !res.ok) { const p = modal.querySelector('[data-fx-err]'); p.hidden = false; p.textContent = res.message || 'Błąd'; }
        });
        (form.querySelector('[autofocus]') || form.querySelector('input, select'))?.focus();
    }

    function uploadDialog(file) {
        const acc = Object.entries(D.accounts).map(([k, v]) => `<option value="${k}">${esc(v)}${k === 'PLN' ? ' (bank przewalutował)' : ' (walutowy)'}</option>`).join('');
        open(`<h3>Wgraj wyciąg ING (PDF)</h3><form id="fxForm" class="fx-form">
            <label class="fx-wide">Plik PDF<input type="file" name="file" accept="application/pdf,.pdf" required></label>
            <label class="fx-wide">Rachunek<select name="account" required>${acc}</select></label>
            <label>Okres od<input type="date" name="period_from"></label><label>Okres do<input type="date" name="period_to"></label>
            <p class="fx-note fx-wide">Plik jest zapisywany zaszyfrowany. Format wyciągu ING jest jeszcze w przygotowaniu : jeśli nie da się go odczytać, wpisz wpłaty ręcznie (« Dodaj wpłatę »).</p></form>`,
            async (form) => {
                const fd = new FormData(form);
                fd.append('month', D.month);
                const res = await fetch(at.root + BASE + '/statements', {method: 'POST', credentials: 'same-origin', headers: {'Accept': 'application/json'}, body: fd})
                    .then(r => r.json().then(b => ({ok: r.ok, ...b})), () => ({ok: false, message: 'Brak połączenia z serwerem.'}));
                if (res.ok) { D = res.data; close(); render(); at.notify(true, res.message); }
                return res;
            });
        if (file) { const dt = new DataTransfer(); dt.items.add(file); modal.querySelector('input[type=file]').files = dt.files; }
    }

    async function lineDialog(id) {
        let L = {account: 'EUR', currency: 'EUR', booked_on: '', amount: '', id_statement: null, id_customer: null, payer: '', title: '', bank_rate: '', amount_pln: ''};
        if (id) {
            const res = await at.send(`${BASE}/lines/${id}`, 'GET');
            if (!res.ok) { at.notify(false, res.message); return; }
            L = res.line;
        }
        const val = (v) => v === null || v === undefined ? '' : esc(String(v).replace('.', ','));
        const st = D.statement_choices.map(s => `<option value="${s.id}" data-acc="${s.account}" ${L.id_statement === s.id ? 'selected' : ''}>${esc(s.label)}</option>`).join('');
        const cu = D.customers.map(c => `<option value="${c.id}" ${L.id_customer === c.id ? 'selected' : ''}>${esc(c.name)}${c.currency !== 'EUR' ? ' (' + esc(c.currency) + ')' : ''}</option>`).join('');
        open(`<h3>${id ? 'Popraw wpłatę' : 'Dodaj wpłatę ręcznie'}</h3><form id="fxForm" class="fx-form">
            <label>Rachunek<select name="account">${Object.entries(D.accounts).map(([k, v]) => `<option value="${k}" ${L.account === k ? 'selected' : ''}>${esc(v)}</option>`).join('')}</select></label>
            <label>Wyciąg<select name="id_statement"><option value="">— bez wyciągu —</option>${st}</select></label>
            <label>Data wpłaty<input type="date" name="booked_on" value="${esc(L.booked_on || '')}" max="${esc(D.today)}" required autofocus></label>
            <label>Kwota<span style="display:flex;gap:6px"><input type="text" inputmode="decimal" name="amount" value="${val(L.amount)}" required style="flex:1">
                <input type="text" name="currency" value="${esc(L.currency || 'EUR')}" maxlength="3" style="width:64px"></span></label>
            <label class="fx-wide">Klient<select name="id_customer"><option value="">— rozpoznaj z płacącego / numeru —</option>${cu}</select></label>
            <label>Płacący (z wyciągu)<input type="text" name="payer" value="${esc(L.payer || '')}" maxlength="300"></label>
            <label>Tytuł przelewu<input type="text" name="title" value="${esc(L.title || '')}" maxlength="600" placeholder="np. FV/2026/11/0142"></label>
            <label data-fx-pln>Kurs ING (konto PLN)<input type="text" inputmode="decimal" name="bank_rate" value="${val(L.bank_rate)}" placeholder="np. 4,2105"></label>
            <label data-fx-pln>albo kwota w zł z wyciągu<input type="text" inputmode="decimal" name="amount_pln" value="${val(L.amount_pln)}"></label>
            <p class="fx-note fx-wide">Dopasowanie : numer faktury albo proformy w tytule, inaczej klient i kwota ; niepewne — « do potwierdzenia ».</p></form>`,
            async (form) => {
                const data = at.formData(form);
                data.month = D.month;
                const res = await at.send(`${BASE}/lines${id ? '/' + id : ''}`, 'POST', data);
                if (res.ok) { D = res.data; close(); render(); at.notify(true, res.message); }
                return res;
            });
        const form = modal.querySelector('#fxForm');
        const sync = () => {
            const acc = form.account.value;
            form.querySelectorAll('[data-fx-pln]').forEach(el => { el.hidden = acc !== 'PLN'; });
            [...form.id_statement.options].forEach(o => { if (o.value) o.hidden = o.dataset.acc !== acc; });
            if (form.id_statement.selectedOptions[0]?.hidden) form.id_statement.value = '';
        };
        form.account.addEventListener('change', sync);
        sync();
    }

    // --- Zdarzenia -----------------------------------------------------------------------------------------------------

    app.addEventListener('change', (e) => {
        const r = e.target.closest('[data-fx-pick]');
        if (!r) return;
        picked[r.dataset.fxPick] = r.value;
        const btn = app.querySelector(`[data-fx-allocate="${r.dataset.fxPick}"]`);
        if (btn) btn.disabled = false;
    });

    app.addEventListener('click', async (e) => {
        const t = e.target.closest('button, a');
        if (!t) { app.querySelector('[data-fx-menu-box]')?.classList.remove('is-open'); return; }
        if (t.matches('[data-fx-menu]')) { app.querySelector('[data-fx-menu-box]').classList.toggle('is-open'); return; }
        if (t.matches('[data-fx-upload], [data-fx-drop]')) { uploadDialog(null); return; }
        if (t.matches('[data-fx-add]')) { lineDialog(null); return; }
        if (t.matches('[data-fx-edit]')) { lineDialog(Number(t.dataset.fxEdit)); return; }
        const post = async (path, body, confirmText) => {
            if (confirmText && !window.confirm(confirmText)) return;
            t.disabled = true;
            const res = await at.send(BASE + path, 'POST', {...body, month: D.month});
            t.disabled = false;
            at.notify(res.ok, res.message);
            if (res.ok) { D = res.data; render(); }
        };
        if (t.matches('[data-fx-allocate]')) {
            const id = t.dataset.fxAllocate;
            if (picked[id]) await post(`/lines/${id}/allocate`, {docs: [picked[id]]});
        } else if (t.matches('[data-fx-ignore]')) {
            await post(`/lines/${t.dataset.fxIgnore}/ignore`, {ignore: true}, 'To nie jest wpłata klienta za fakturę w walucie — usunąć ją z różnic kursowych ?');
        } else if (t.matches('[data-fx-del-statement]')) {
            await post(`/statements/${t.dataset.fxDelStatement}/delete`, {}, 'Usunąć wyciąg razem z jego wpłatami ?');
        }
    });

    // Przeciągnij PDF na pole wyciągu : okno z wybranym plikiem (rachunek EUR / PLN do wyboru).
    app.addEventListener('dragover', (e) => { const z = e.target.closest('[data-fx-drop]'); if (z) { e.preventDefault(); z.classList.add('is-over'); } });
    app.addEventListener('dragleave', (e) => { e.target.closest('[data-fx-drop]')?.classList.remove('is-over'); });
    app.addEventListener('drop', (e) => {
        const z = e.target.closest('[data-fx-drop]');
        if (!z) return;
        e.preventDefault();
        z.classList.remove('is-over');
        const f = e.dataTransfer.files[0];
        if (f) uploadDialog(f);
    });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });

    render();
})();
