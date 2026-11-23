// [371] Domówienia dnia (Kierownik operacyjny) : 1. przegląd domówień od ostatniego sprawdzenia i na najbliższy dzień
// roboczy, ze stanem po stronie produkcji, 2. do wyjaśnienia (wynik dla każdego domówienia z sygnałem), 3. zatwierdzenie
// → zadanie stanowiska zalicza się samo. Rien n'est envoyé aux clients ; ni la commande ni le plan ne sont modifiés.
(() => {
    const app = document.getElementById('sdApp');
    if (!app || !window.tw) return;
    const {esc, nf} = tw;
    let D = JSON.parse(document.getElementById('sdBoot').textContent);
    let step = 1, reached = 1, busy = false;
    // Brouillons de l'étape 2 (résultat choisi, note) par domówienie, gardés entre deux rendus.
    const draft = {};
    const STEPS = ['Przegląd', 'Do wyjaśnienia', 'Zatwierdź'];
    const RES_CLS = {sent: 'ok', contact: 'warn', moved: ''};
    const STATE_CLS = {in_plan: 'ok', stock: 'ok', live: '', shipped: '', unplanned: 'bad', missing: 'bad'};
    const PUSH_CLS = {sent: 'ok', pending: 'warn', failed: 'bad'};
    const flagged = () => D.orders.filter(o => o.flags.length);
    const canFinish = () => !!D.can_finish;
    const needsNote = (r) => (D.note_required || []).includes(r);
    const link = (id) => `<a href="${at.root}/client-orders/${id}/edit" target="_blank" rel="noopener">#${id}</a>`;
    const ref = (o) => `${link(o.id)}${o.reference ? ' · ' + esc(o.reference) : ''}${o.freebie ? ' ' + tw.pill('gratis') : ''}${parent(o)}`;
    const ship = (o) => o.shipping_label ? esc(o.shipping_label) + (o.shipping_date === D.today ? '<small>dziś</small>' : (o.shipping_date === D.next_workday ? '<small>następny dzień roboczy</small>' : ''))
        : '<span class="tw-red">brak</span>';
    const cartons = (v) => nf(v, v % 1 ? 1 : 0);

    function parent(o) {
        const p = o.parent;
        if (!p) return '<small>bez zamówienia głównego</small>';
        const when = p.shipping_label ? (p.same_day ? 'ta sama wysyłka' : 'wysyłka ' + esc(p.shipping_label)) : 'bez daty';
        return `<small>${p.kind === 'linked' ? 'do zamówienia' : 'zamówienie tego dnia'} ${link(p.id)} · ${when}${p.open ? '' : ' · ' + esc(p.status_label || 'zamknięte')}</small>`;
    }

    function products(o) {
        if (!o.products.length) return '<span class="tw-red">brak pozycji</span>';
        const list = o.products.slice(0, 4).map(p => `${esc(p.name)} ${cartons(p.cartons)}${o.production.state !== p.state && p.state !== 'shipped' ? ' <i class="' + (STATE_CLS[p.state] === 'bad' ? 'tw-red' : '') + '">(' + esc(D.states[p.state]) + ')</i>' : ''}`);
        return `${cartons(o.cartons)} kart.<small>${list.join(' · ')}${o.products.length > 4 ? ' · …' : ''}</small>`;
    }

    function production(o) {
        return tw.pill(o.production.label, STATE_CLS[o.production.state] ?? '') + (o.production.detail ? `<small>${esc(o.production.detail)}</small>` : '');
    }

    function push(o) {
        if (!o.push) return '—';
        return tw.pill(o.push.label, PUSH_CLS[o.push.status] ?? '') + (o.push.at ? `<small>${esc(o.push.at)}</small>` : '');
    }

    function mark(o) {
        if (o.flags.length) return o.check ? tw.pill('wyjaśnione', 'ok') : tw.pill('do wyjaśnienia' + (o.flags.length > 1 ? ' (' + o.flags.length + ')' : ''), 'bad');
        return o.open ? '' : tw.pill(o.status_label);
    }

    function step1() {
        const t = D.totals, s = D.settings;
        const rows = D.orders.map(o => ({cls: o.flags.length && !o.check ? 'hl' : '', cells: [
            `<b>${esc(o.customer)}</b><small>${o.kind === 'new' ? 'nowe · ' : ''}złożone ${esc(o.created_label)}</small>`, ref(o), ship(o), products(o), production(o), push(o), mark(o)]}));
        return `<p class="tw-lead">Domówienia złożone <b>${esc(D.since.label)}</b> oraz otwarte z wysyłką do <b>${esc(D.next_label)}</b> (najbliższy dzień roboczy). Sprawdź, czy każde jest w produkcji ; domówienia z sygnałem trafiają do kroku 2.</p>
            ${tw.tiles([{label: 'Domówienia', value: String(t.supplements), sub: `${t.new} ${tw.plural(t.new, 'nowe', 'nowe', 'nowych')} · ${t.upcoming} z wysyłką do ${D.next_label}`},
                {label: 'W produkcji', value: `${t.in_production} z ${t.supplements - t.shipped}`, sub: t.shipped ? `otwarte · ${t.shipped} już ${tw.plural(t.shipped, 'wysłane', 'wysłane', 'wysłanych')}` : 'otwarte domówienia', cls: t.not_in_production ? 'warn' : 'ok'},
                {label: 'Do wyjaśnienia', value: String(t.flagged), sub: t.flagged ? `${t.resolved} z ${t.flagged} z wynikiem` : 'brak sygnałów', cls: t.waiting ? 'warn' : 'ok'},
                {label: 'Kartony', value: cartons(t.cartons) + ' kart.', sub: t.push_failed ? `${t.push_failed} z błędem wysłania do sklepu` : 'we wszystkich domówieniach'}])}
            ${tw.table([{label: 'Klient'}, {label: 'Nr / zamówienie główne'}, {label: 'Wysyłka'}, {label: 'Kartony i produkty'}, {label: 'Produkcja'}, {label: 'Panel sklepu'}, {label: ''}],
                rows, 'Brak domówień od ostatniego sprawdzenia i na najbliższy dzień roboczy.')}
            <p class="tw-note">Stan produkcji z planu : <b>w planie</b> — zlecenie planu dla domówienia albo plan dnia wysyłki zatwierdzony po niej ; <b>w propozycji planu</b> — tydzień bez zatwierdzonego planu, liczony na żywo z zamówień ;
                <b>ze stanu</b> — produkt MTS ; <b>poza planem</b> — plan zatwierdzony przed domówieniem albo brak daty ; <b>gratis</b> i <b>bez karty planowania</b> — nie liczą się w planie.
                « Panel sklepu » to wysłanie domówienia do systemu sklepu franczyzy, nie do produkcji. Dni robocze : ${esc(s.workdays_label.replace(/\.$/, ''))}.</p>`;
    }

    function resultCell(o) {
        const d = draft[o.id] ?? (draft[o.id] = {result: o.check ? o.check.result : '', note: o.check ? (o.check.note || '') : ''});
        const seg = Object.entries(D.results).map(([k, l]) => `<button type="button" class="${d.result === k ? 'on' : ''}" data-sd-res="${o.id}" data-v="${k}">${esc(l)}</button>`).join('');
        const saved = o.check ? `<small>${tw.pill(o.check.label, RES_CLS[o.check.result] ?? '')} ${esc(o.check.at || '')}${o.check.by ? ' · ' + esc(o.check.by) : ''}</small>` : '';
        const dirty = !o.check || o.check.result !== d.result || (o.check.note || '') !== d.note;
        const ph = d.result === 'contact' ? 'Notatka (wymagana) : z kim, co ustalono' : (d.result === 'moved' ? 'Notatka (wymagana) : na kiedy albo dlaczego' : 'Notatka (opcjonalnie) : komu w produkcji, kiedy');
        return `<div style="display:grid;gap:6px;min-width:380px"><div class="tw-seg" style="justify-self:start">${seg}</div>
            <div style="display:flex;gap:6px"><input class="form-control form-control-sm" data-sd-note="${o.id}" maxlength="500" value="${esc(d.note)}"
                placeholder="${ph}" style="min-height:36px;border-radius:9px">
            <button type="button" class="tw-btn ghost sm" data-sd-save="${o.id}" ${!d.result || !dirty || busy ? 'disabled' : ''}>Zapisz</button></div>${saved}</div>`;
    }

    function step2() {
        const list = flagged(), t = D.totals;
        if (!list.length) return tw.alert('Nic do wyjaśnienia : każde domówienie jest w produkcji, w terminie i z aktywnymi produktami.', 'ok', 'bi-check-circle');
        const rows = list.map(o => ({cls: o.check ? '' : 'hl', cells: [
            `<b>${esc(o.customer)}</b><small>${link(o.id)}${o.reference ? ' · ' + esc(o.reference) : ''} · ${cartons(o.cartons)} kart.</small>`,
            ship(o), production(o),
            o.flags.map(f => `<div style="display:flex;gap:6px;align-items:baseline;margin:2px 0"><i class="bi bi-exclamation-circle tw-red"></i><span>${esc(f.reason)}</span></div>`).join(''),
            resultCell(o)]}));
        return `<p class="tw-lead">Sygnały automatyczne. Dla każdego domówienia zapisz wynik : <b>Przekazane do produkcji</b>, <b>Kontakt z klientem</b> (z notatką) albo <b>Przesunięte / odrzucone</b> (z notatką).
                Krok 3 otworzy się, gdy wszystkie mają wynik.</p>
            ${tw.tiles([{label: 'Do wyjaśnienia', value: String(t.flagged), sub: 'domówienia z sygnałem'}, {label: 'Z wynikiem', value: `${t.resolved} z ${t.flagged}`, sub: t.waiting ? `zostało ${t.waiting}` : 'wszystkie wyjaśnione', cls: t.waiting ? 'warn' : 'ok'},
                {label: 'Przekazane do produkcji', value: String(t.sent), sub: 'potwierdzone'}, {label: 'Kontakt / przesunięte', value: `${t.contact} / ${t.moved}`, sub: 'z notatką'}])}
            ${tw.table([{label: 'Domówienie'}, {label: 'Wysyłka'}, {label: 'Produkcja'}, {label: 'Dlaczego'}, {label: 'Wynik'}], rows)}
            <p class="tw-note">Sygnały : nie w produkcji, a wysyłka najpóźniej ${esc(D.next_label)} ; złożone po ${esc(D.settings.cutoff)} dnia roboczego przed wysyłką (Ustawienia › Parametry, orders.supplement_cutoff) ;
                brak daty albo data minęła ; produkt nieaktywny. Tylko domówienia otwarte (bez WDT). Wynik tylko zapisuje ustalenie : zamówienie i plan zmieniasz w ich ekranach, nic nie jest wysyłane do klientów.</p>`;
    }

    function step3() {
        const t = D.totals;
        if (D.done) return tw.done('Domówienia dnia sprawdzone · ' + (D.done.at || ''), D.done.summary + ' · zadanie zaliczone');
        const list = flagged().filter(o => o.check);
        return `<p class="tw-lead">Gotowe : <b>${esc(D.summary)}</b>.</p>
            ${tw.tiles([{label: 'Domówienia', value: String(t.supplements), sub: `${t.in_production} w produkcji · ${t.shipped} ${tw.plural(t.shipped, 'wysłane', 'wysłane', 'wysłanych')}`}, {label: 'Wyjaśnione', value: String(t.resolved), sub: 'wszystkie z wynikiem', cls: 'ok'},
                {label: 'Przekazane do produkcji', value: String(t.sent), sub: 'potwierdzone'}, {label: 'Kontakt / przesunięte', value: `${t.contact} / ${t.moved}`, sub: D.label}])}
            ${list.length ? `<div class="tw-cap">Wyniki</div>` + tw.table([{label: 'Klient'}, {label: 'Domówienie'}, {label: 'Wysyłka'}, {label: 'Wynik'}, {label: 'Notatka'}],
                list.map(o => ({cells: [`<b>${esc(o.customer)}</b>`, link(o.id), ship(o), tw.pill(o.check.label, RES_CLS[o.check.result] ?? ''), esc(o.check.note || '—')]}))) : ''}
            <p class="tw-note">« Zakończ » zalicza zadanie « Domówienia dnia » na dziś. Nic nie jest wysyłane do klientów ; zamówienia i plan produkcji pozostają bez zmian.</p>`;
    }

    function render() {
        const top = canFinish() ? reached : Math.min(reached, 2);
        if (step > top) step = top;
        const body = step === 1 ? step1() : (step === 2 ? step2() : step3());
        const next = step === 1 ? {label: 'Dalej : do wyjaśnienia', attr: 'data-sd-next'}
            : step === 2 ? {label: 'Dalej : zatwierdź', attr: 'data-sd-next', disabled: !canFinish()}
                : (D.done ? null : {label: 'Zakończ', attr: 'data-sd-finish', final: true, disabled: busy || !canFinish()});
        app.innerHTML = tw.frame({title: 'Domówienia dnia', sub: `${D.label} · krok ${step} z 3 · ok. 10 min`, steps: STEPS, step, reached: top, body,
            prev: step > 1 ? 'Wstecz' : null, next, later: !D.done && step < 3});
    }

    tw.bind(app, (n) => { step = n === -1 ? Math.max(1, step - 1) : n; render(); });
    app.addEventListener('input', (e) => {
        const n = e.target.closest('[data-sd-note]');
        if (!n) return;
        const id = Number(n.dataset.sdNote);
        (draft[id] ??= {result: '', note: ''}).note = n.value;
        const o = D.orders.find(x => x.id === id), d = draft[id];
        const btn = app.querySelector(`[data-sd-save="${id}"]`);
        if (btn && o) btn.disabled = !d.result || busy || (o.check && o.check.result === d.result && (o.check.note || '') === d.note);
    });
    app.addEventListener('click', async (e) => {
        if (e.target.closest('[data-sd-next]')) { step = Math.min(3, step + 1); reached = Math.max(reached, step); render(); return; }
        const r = e.target.closest('[data-sd-res]');
        if (r) { const id = Number(r.dataset.sdRes); (draft[id] ??= {result: '', note: ''}).result = r.dataset.v; render(); app.querySelector(`[data-sd-note="${id}"]`)?.focus(); return; }
        const s = e.target.closest('[data-sd-save]');
        if (s) {
            const id = Number(s.dataset.sdSave), d = draft[id];
            if (!d || !d.result || busy) return;
            if (needsNote(d.result) && d.note.trim().length < 3) {
                at.notify(false, d.result === 'contact' ? 'Kontakt z klientem : wpisz notatkę (z kim, co ustalono).' : 'Przesunięte / odrzucone : wpisz notatkę (na kiedy albo dlaczego).');
                return;
            }
            busy = true; s.disabled = true;
            const res = await tw.post('/ajax/client-orders/supplements/result', {id_order: id, result: d.result, note: d.note});
            busy = false;
            if (res.ok) { D = res.data; delete draft[id]; if (canFinish()) reached = Math.max(reached, 2); }
            render();
            return;
        }
        if (e.target.closest('[data-sd-finish]')) {
            if (busy) return;
            busy = true; render();
            const res = await tw.post('/ajax/client-orders/supplements/finish', {});
            busy = false;
            if (res.ok) { at.notify(true, res.message); D = res.data; }
            render();
        }
    });
    render();
})();
