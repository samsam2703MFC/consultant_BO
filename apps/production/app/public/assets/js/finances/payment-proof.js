// Wpłata z e-maila [233] (makieta 9) : okno « Wpłata — sprawdź i zatwierdź » — wspólne dla Poczty (« Zweryfikuj wpłatę »,
// « Odczytaj » przy załączniku) i Płatności dnia (krok 1 « Z poczty »). Odczyt : PDF z tekstem na serwerze ; skan albo
// zdjęcie → OCR w przeglądarce (Tesseract.js, pdf.js dla skanów PDF) i tekst wysłany na serwer. Zatwierdzenie = proforma
// opłacona (data + kwota), dowód przy proformie, przypomnienia zatrzymane.
(() => {
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const money = (v, cur) => v === null || v === undefined ? '—' : Number(v).toLocaleString('pl-PL', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ' + (cur && cur !== 'EUR' ? cur : '€');
    const dmy = (d) => d ? `${d.slice(8, 10)}.${d.slice(5, 7)}.${d.slice(0, 4)}` : '—';
    const num = (v) => { const s = String(v ?? '').replace(/\s/g, '').replace(',', '.'); const x = Number(s); return s === '' || !Number.isFinite(x) ? null : x; };
    let root = '';
    let box = null;
    let opts = {};
    let state = null; // {view, selected:Set, amount, paid_on}

    async function send(path, method, body) {
        try {
            const r = await fetch(root + path, {method, credentials: 'same-origin',
                headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                body: body === undefined ? undefined : JSON.stringify(body)});
            let j = {};
            try { j = await r.json(); } catch (e) { /* bez JSON */ }
            return {ok: r.ok, data: j.data, message: j.message || (r.ok ? '' : 'Błąd (' + r.status + ')')};
        } catch (e) {
            return {ok: false, message: 'Brak połączenia z serwerem.'};
        }
    }

    function shell(inner, wide) {
        if (!box) {
            box = document.createElement('div');
            box.className = 'fc-modal';
            box.setAttribute('data-pp-modal', '');
            document.body.appendChild(box);
            box.addEventListener('click', onClick);
            box.addEventListener('input', onInput);
            box.addEventListener('change', onInput);
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && box) close(); });
        }
        box.innerHTML = `<div class="fc-modal-box pp-box${wide ? '' : ' pp-narrow'}" role="dialog" aria-modal="true" aria-labelledby="ppT">
            <div class="fc-modal-head"><h4 id="ppT"><i class="bi bi-cash-coin"></i> Wpłata — sprawdź i zatwierdź</h4><button type="button" class="kw-btn" data-pp-close>Zamknij</button></div>${inner}</div>`;
    }

    function close() {
        if (box) { box.remove(); box = null; }
        state = null;
    }

    function busy(text) {
        shell(`<div class="pp-busy"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> <span data-pp-status>${esc(text)}</span></div>`, false);
    }
    const status = (t) => { const s = box && box.querySelector('[data-pp-status]'); if (s) s.textContent = t; };

    function fail(message) {
        shell(`<p class="text-danger mb-0">${esc(message)}</p>`, false);
    }

    // --- OCR (lazy) -------------------------------------------------------------------------------------------------
    function loadScript(src, global) {
        if (window[global]) return Promise.resolve();
        return new Promise((ok, ko) => { const s = document.createElement('script'); s.src = src; s.onload = ok; s.onerror = () => ko(new Error('Nie udało się wczytać ' + src)); document.head.appendChild(s); });
    }
    let worker = null;
    async function ocr(canvases) {
        await loadScript('https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/tesseract.min.js', 'Tesseract');
        let page = 1;
        const log = (m) => {
            if (m.status === 'recognizing text') status(`Odczyt (OCR) strona ${page}/${canvases.length} : ${Math.round(m.progress * 100)} %`);
            else if (/loading|initializ/.test(m.status || '')) status('Przygotowanie odczytu (OCR)…');
        };
        if (!worker) {
            worker = window.Tesseract.createWorker('pol', 1, {logger: (m) => (worker.log || log)(m)}).then(async (w) => {
                await w.setParameters({tessedit_pageseg_mode: '6', preserve_interword_spaces: '1'});
                return w;
            });
            worker.catch(() => { worker = null; });
        }
        worker.log = log;
        const w = await worker;
        const out = [];
        for (const c of canvases) { out.push((await w.recognize(c)).data.text || ''); page++; }
        return out.join('\n\n').slice(0, 100000);
    }
    async function pdfCanvases(buf) {
        await loadScript('https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js', 'pdfjsLib');
        window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        const pdf = await window.pdfjsLib.getDocument({data: new Uint8Array(buf)}).promise;
        const out = [];
        for (let p = 1; p <= Math.min(pdf.numPages, 3); p++) {
            const page = await pdf.getPage(p);
            const vp = page.getViewport({scale: Math.min(4, 2200 / page.getViewport({scale: 1}).width)});
            const c = document.createElement('canvas');
            c.width = Math.round(vp.width); c.height = Math.round(vp.height);
            const ctx = c.getContext('2d');
            ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
            await page.render({canvasContext: ctx, viewport: vp}).promise;
            out.push(c);
        }
        return out;
    }
    async function imageCanvas(blob) {
        const img = await createImageBitmap(blob);
        const scale = Math.min(1, 2400 / img.width) < 0.5 ? 0.5 : Math.min(1, 2400 / img.width);
        const c = document.createElement('canvas');
        c.width = Math.round(img.width * scale); c.height = Math.round(img.height * scale);
        const ctx = c.getContext('2d');
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
        ctx.drawImage(img, 0, 0, c.width, c.height);
        return [c];
    }

    // --- Przepływ ---------------------------------------------------------------------------------------------------
    async function openMail(mailId, o) {
        opts = o || {};
        root = (opts.root ?? (window.at && window.at.root) ?? '').replace(/\/$/, '');
        busy('Wczytywanie wiadomości…');
        const r = await send(`/ajax/finances/payment-proofs/mail/${mailId}`, 'GET');
        if (!r.ok) return fail(r.message);
        const m = r.data;
        const done = m.proofs.find(p => p.status === 'confirmed') || m.proofs.find(p => p.status === 'to_check' && p.index !== null);
        if (done) return openProof(done.id, opts);
        if (m.attachments.length === 1) return read(mailId, m.attachments[0].index, m.attachments[0]);
        shell(`<div class="pp-sub">${esc(m.subject || '(bez tematu)')} · ${esc(m.from || '')}${m.customer ? ' · klient : ' + esc(m.customer.name) : ''}</div>
            ${m.offline ? `<p class="text-danger">${esc(m.offline)}</p>` : ''}
            ${m.attachments.length ? `<p class="mb-1">Który załącznik to potwierdzenie przelewu?</p><div class="pp-files">${m.attachments.map(a => `<div class="pp-file">
                <i class="bi ${/pdf/i.test(a.mime) ? 'bi-file-earmark-pdf' : 'bi-file-earmark-image'}"></i><span><b>${esc(a.filename)}</b><small>${esc(a.mime)} · ${Math.max(1, Math.round(a.size / 1024))} KB</small></span>
                <button type="button" class="kw-btn" data-pp-read="${a.index}" data-mail="${mailId}"><i class="bi bi-eye"></i> Odczytaj</button></div>`).join('')}</div>`
                : '<p class="at-empty">Brak PDF ani zdjęcia w tej wiadomości — nie ma czego odczytać.</p>'}
            <div class="pp-actions"><button type="button" class="tt-link" data-pp-reject-mail="${mailId}">To nie jest wpłata</button></div>`, false);
    }

    async function read(mailId, index, att, o) {
        if (o) { opts = o; root = (opts.root ?? (window.at && window.at.root) ?? '').replace(/\/$/, ''); }
        busy(`Odczyt ${att && att.filename ? att.filename : 'załącznika'}…`);
        let r = await send('/ajax/finances/payment-proofs/read', 'POST', {mail_id: mailId, index});
        if (r.ok && r.data && r.data.need_ocr) {
            try {
                status(r.data.kind === 'pdf' ? 'PDF bez tekstu (skan) — odczyt OCR…' : 'Zdjęcie — odczyt OCR (10–30 s)…');
                const res = await fetch(`${root}/mail/messages/${mailId}/attachments/${index}`, {credentials: 'same-origin'});
                if (!res.ok) throw new Error('Nie udało się pobrać załącznika.');
                const blob = await res.blob();
                const canvases = r.data.kind === 'pdf' ? await pdfCanvases(await blob.arrayBuffer()) : await imageCanvas(blob);
                const text = await ocr(canvases);
                status('Dopasowanie do proform…');
                r = await send('/ajax/finances/payment-proofs/read', 'POST', {mail_id: mailId, index, ocr_text: text.trim() || ' '});
            } catch (e) {
                return fail('Odczyt OCR nie powiódł się : ' + (e.message || e));
            }
        }
        if (!r.ok) return fail(r.message);
        if (r.data.need_ocr) return fail('Nie udało się odczytać tekstu z dokumentu.');
        show(r.data);
    }

    async function openProof(id, o) {
        opts = o || opts || {};
        root = (opts.root ?? (window.at && window.at.root) ?? '').replace(/\/$/, '');
        busy('Wczytywanie potwierdzenia…');
        const r = await send(`/ajax/finances/payment-proofs/${id}`, 'GET');
        if (!r.ok) return fail(r.message);
        show(r.data);
    }

    // --- Okno (makieta 9) -------------------------------------------------------------------------------------------
    function show(v) {
        state = {view: v, selected: new Set(v.suggested), amount: v.read.amount !== null ? String(v.read.amount).replace('.', ',') : '', paid_on: v.read.date || v.today};
        render();
    }

    function chip(ok, label, warn) {
        return `<span class="pp-chip ${ok === true ? 'ok' : (ok === false ? (warn ? 'warn' : 'bad') : '')}">${esc(label)}</span>`;
    }

    function docPane(v) {
        const p = v.proof;
        const file = `${root}/finances/payment-proofs/${p.id}/file`;
        const marks = [];
        if (v.read.amount !== null) marks.push(Number(v.read.amount).toFixed(2).replace('.', '[.,]').replace(/\B(?=(\d{3})+(?!\d))/g, '[ .]?'));
        v.read.numbers.forEach(n => marks.push(n.replace(/[/-]/g, '.').replace(/^FP\./, 'F\\s*P[-\\s]*').replace(/0+(\d)/, '0*$1')));
        if (v.read.date) marks.push(`${v.read.date.slice(8, 10)}[./-]${v.read.date.slice(5, 7)}[./-]${v.read.date.slice(0, 4)}|${v.read.date}`);
        let text = esc(String(p.text || '').replace(/[ \t]{2,}/g, '  ').replace(/\n{3,}/g, '\n\n').trim());
        marks.forEach(m => { try { text = text.replace(new RegExp(`(${m})`, 'g'), '<mark>$1</mark>'); } catch (e) { /* wzorzec */ } });
        const img = /^image\//.test(p.mime || '');
        return `<div class="pp-doc">${img ? `<a href="${esc(file)}" target="_blank" rel="noopener"><img src="${esc(file)}" alt="Potwierdzenie przelewu"></a>` : ''}
            <pre class="pp-text${img ? ' small' : ''}">${text || '<span class="at-sub">brak tekstu</span>'}</pre>
            <div class="pp-doc-foot"><span class="pp-chip ok"><i class="bi bi-check2"></i> ${p.method === 'ocr' ? 'odczyt OCR' : 'PDF tekstowy'}</span>${p.executed ? '<span class="pp-chip ok">zrealizowany</span>' : ''}
                <a href="${esc(file)}" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> ${esc(p.filename || 'dokument')}</a></div></div>`;
    }

    function render() {
        const v = state.view;
        const c = v.checks;
        const sel = v.proformas.filter(p => state.selected.has(p.id));
        const selSum = sel.reduce((a, p) => a + p.amount, 0);
        const amount = num(state.amount);
        const diff = amount !== null && sel.length ? Math.round((amount - selSum) * 100) / 100 : null;
        const decided = v.decided;
        const amountChip = !sel.length ? chip(null, 'wybierz proformę') : (diff === 0 ? chip(true, sel.length > 1 ? '= ' + sel.length + ' proformy' : '= proforma') : chip(false, `różnica ${diff > 0 ? '+' : ''}${money(diff)}`, true));
        const numberChip = {found: chip(true, 'znaleziona'), paid: chip(false, 'już opłacona', true), other_client: chip(false, 'proforma innego klienta'), none: chip(false, 'brak w ERP', true), missing: chip(null, 'brak numeru')}[c.number];
        const rows = [
            ['Kwota', `<div class="pp-in"><input class="form-control" data-pp-amount inputmode="decimal" value="${esc(state.amount)}" ${decided ? 'disabled' : ''}><span>${esc(v.read.currency && v.read.currency !== 'EUR' ? v.read.currency : '€')}</span></div>`,
                amountChip + (c.currency ? '' : chip(false, 'waluta ' + (v.read.currency || '?')))],
            ['Data wpłaty', `<input type="date" class="form-control" data-pp-date value="${esc(state.paid_on)}" max="${esc(v.today)}" ${decided ? 'disabled' : ''}>`, c.date ? '' : chip(null, 'nie odczytano')],
            ['Numer proformy', `<div class="pp-val">${v.read.numbers.length ? v.read.numbers.map(esc).join('<br>') : '—'}</div>`, numberChip],
            ['Rachunek odbiorcy', `<div class="pp-val">${esc(v.read.account || '—')}</div>`, c.account === null ? chip(null, 'brak w dokumencie') : chip(c.account, c.account ? 'nasz rachunek' : 'inny rachunek')],
            ['Płacący', `<div class="pp-val">${esc(v.read.payer || '—')}</div>`, v.read.payer ? chip(c.payer, c.payer ? '= klient e-maila' : '≠ ' + (v.customer ? v.customer.name : 'klient'), true) : ''],
        ].map(([l, i, ch]) => `<div class="pp-row"><label>${l}</label>${i}<span class="pp-chips">${ch}</span></div>`).join('');
        const list = v.proformas.map(p => {
            const can = p.state === 'unpaid' || p.state === 'found';
            const on = state.selected.has(p.id);
            return `<label class="pp-pf ${on ? 'on' : ''} ${can ? '' : 'off'}"><input type="checkbox" data-pp-pick="${p.id}" ${on ? 'checked' : ''} ${can && !decided ? '' : 'disabled'}>
                <span><b>${esc(p.number)}</b>${p.in_document ? ' <span class="pp-chip ok">w dokumencie</span>' : ''}${p.state === 'paid' ? ' <span class="pp-chip warn">już opłacona</span>' : ''}${p.state === 'other_client' ? ` <span class="pp-chip bad">${esc(p.client || 'inny klient')}</span>` : ''}
                <small>wystawiona ${dmy(p.date)} · ${p.days} dni${p.reminders ? ` · ${p.reminders} ${p.reminders === 1 ? 'przypomnienie wysłane' : 'przypomnienia wysłane'}` : ''}</small></span><b class="pp-amt">${money(p.amount)}</b></label>`;
        }).join('') || '<p class="at-empty">Klient nie ma proform do zapłaty.</p>';
        const head = v.mail ? `<div class="pp-sub"><i class="bi bi-envelope"></i> ${esc(v.mail.subject || '(bez tematu)')} · ${esc(v.mail.from || '')}${v.customer ? ' · <b>' + esc(v.customer.name || '') + '</b>' : ''}</div>` : '';
        const banner = decided ? `<div class="pp-done ${decided.status}">${decided.status === 'confirmed'
            ? `<i class="bi bi-check-circle"></i> Wpłata zatwierdzona ${dmy(decided.at ? decided.at.slice(0, 10) : null)} : ${esc(decided.proformas.join(', '))} — ${money(decided.amount)}, opłacona ${dmy(decided.paid_on)}.`
            : '<i class="bi bi-x-circle"></i> Odrzucone : to nie jest wpłata.'}</div>` : '';
        shell(`${head}${banner}<div class="pp-grid">${docPane(v)}<div class="pp-form">${rows}
            <div class="pp-h">Proformy klienta <small>(zaznacz, gdy przelew za kilka albo inny numer)</small></div><div class="pp-list">${list}</div>
            <div class="pp-sum">Zaznaczone : ${sel.length} · ${money(selSum)}${amount !== null && sel.length ? ` · wpłata ${money(amount)}` : ''}</div></div></div>
            ${decided ? '' : `<p class="pp-note">Zatwierdź = proforma « opłacona » (data + kwota, jak w Płatnościach dnia), dowód (PDF / zdjęcie) zapisany przy proformie, przypomnienia zatrzymane, wpis na karcie klienta.</p>
            <div class="pp-actions"><button type="button" class="tt-link" data-pp-reject>To nie jest wpłata</button><button type="button" class="kw-btn pri" data-pp-confirm ${sel.length && amount ? '' : 'disabled'}><i class="bi bi-check2"></i> Zatwierdź wpłatę</button></div>`}`, true);
    }

    function onInput(e) {
        const t = e.target;
        if (!state) return;
        if (t.matches('[data-pp-amount]')) { state.amount = t.value; if (e.type === 'change') render(); else refreshSum(); }
        else if (t.matches('[data-pp-date]')) state.paid_on = t.value;
        else if (t.matches('[data-pp-pick]') && e.type === 'change') { const id = Number(t.dataset.ppPick); if (t.checked) state.selected.add(id); else state.selected.delete(id); render(); }
    }
    function refreshSum() {
        const b = box && box.querySelector('[data-pp-confirm]');
        if (b) b.disabled = !(state.selected.size && num(state.amount));
    }

    async function onClick(e) {
        const t = e.target;
        if (t === box || t.closest('[data-pp-close]')) return close();
        const rd = t.closest('[data-pp-read]');
        if (rd) return read(Number(rd.dataset.mail), Number(rd.dataset.ppRead), {filename: rd.closest('.pp-file').querySelector('b').textContent});
        const rm = t.closest('[data-pp-reject-mail]');
        if (rm) {
            const r = await send(`/ajax/finances/payment-proofs/mail/${rm.dataset.ppRejectMail}/reject`, 'POST', {});
            notify(r.ok, r.message);
            if (r.ok) { close(); if (opts.onDone) opts.onDone({rejected: true}); }
            return;
        }
        if (t.closest('[data-pp-reject]') && state) {
            const r = await send(`/ajax/finances/payment-proofs/${state.view.proof.id}/reject`, 'POST', {});
            notify(r.ok, r.message);
            if (r.ok) { close(); if (opts.onDone) opts.onDone({rejected: true}); }
            return;
        }
        const cf = t.closest('[data-pp-confirm]');
        if (cf && state) {
            cf.disabled = true;
            const r = await send(`/ajax/finances/payment-proofs/${state.view.proof.id}/confirm`, 'POST', {proformas: [...state.selected], paid_on: state.paid_on, amount: num(state.amount)});
            notify(r.ok, r.message);
            if (!r.ok) { cf.disabled = false; return; }
            close();
            if (opts.onDone) opts.onDone(r.data);
        }
    }

    function notify(ok, message) {
        if (!message) return;
        if (window.at && typeof window.at.notify === 'function') return window.at.notify(ok, message);
        const n = document.createElement('div');
        n.className = 'pp-toast ' + (ok ? 'ok' : 'bad');
        n.textContent = message;
        document.body.appendChild(n);
        setTimeout(() => n.remove(), 4000);
    }

    window.tfbPaymentProof = {openMail, openProof, read: (mailId, index, att, o) => read(mailId, index, att, o || {})};
})();
