// Koszty surowców i oszczędności [247] (makieta 22) : 1. co zdrożało (wpływ zł / mies.), 2. tańsze opcje i decyzja
// (zmień — tylko z ofertą / zapytaj / zostaw), 3. decyzje : oferty przyjęte, szkic zapytania ofertowego → zadanie zaliczone.
(() => {
    const app = document.getElementById('crApp');
    if (!app || !window.tw) return;
    const {esc, nf} = tw;
    let D = JSON.parse(document.getElementById('crBoot').textContent);
    let step = 1, reached = 1, busy = false, result = null;
    const STEPS = ['Co zdrożało', 'Tańsze opcje', 'Decyzje'];
    const dec = new Map(D.rows.map(r => [r.id, r.decision]));
    const price = (v, u) => `${nf(v, v < 10 ? 2 : 2)} zł/${esc(u)}`;

    function step1() {
        const t = D.totals;
        const rows = D.rows.map(r => ({cls: r.impact > 0 && r === D.rows[0] ? 'hl' : '', cells: [`<b>${esc(r.name)}</b><small>${esc(r.category || '')}</small>`, esc(r.supplier || '—'),
            `${nf(r.qty, r.qty < 10 ? 1 : 0)} ${esc(r.unit)}`, price(r.price, r.unit), price(r.price_old, r.unit),
            r.change_pct > 0 ? `<span class="${r.change_pct >= 5 ? 'tw-red' : ''}">${tw.signed(r.change_pct, 1, ' %')}</span>` : `<span class="tw-green">${tw.signed(r.change_pct, 1, ' %')}</span>`,
            `<b>${tw.signed(r.impact, 0, ' zł')}</b>`]}));
        return `${tw.tiles([{label: 'Zakupy surowców / mies.', value: tw.zl(t.spend), sub: `zużycie z zamówień × cena bazowa · ${t.materials} surowców`},
                {label: 'Wzrost cen', value: tw.signed(t.increase, 0, ' zł/mies.'), sub: t.top_name ? `w tym ${t.top_name} : ${t.top_share} %` : 'brak wzrostów', cls: t.increase > 0 ? 'warn' : 'ok'},
                {label: 'Top 10 surowców', value: t.top10_pct === null ? '—' : t.top10_pct + ' %', sub: 'wydatków na surowce'},
                {label: 'Zmiany cen', value: String(t.up), sub: `${tw.plural(t.up, 'surowiec zdrożał', 'surowce zdrożały', 'surowców zdrożało')} o ≥ 1 %`}])}
            ${tw.table([{label: 'Surowiec'}, {label: 'Dostawca'}, {label: 'Zużycie / mies.', num: true}, {label: 'Cena teraz', num: true}, {label: '3 mies. temu', num: true}, {label: 'Zmiana', num: true}, {label: 'Wpływ / mies.', num: true}],
                rows, 'Ceny surowców nie zmieniły się o więcej niż 1 % w ostatnich 3 miesiącach.')}
            <p class="tw-note">Cena = cena bazowa receptur (najwyższa zatwierdzona) ; 3 mies. temu = cena z dziennika cen ; zużycie = zamówienia klientów × receptury, średnio z 3 miesięcy. Sortowane po wpływie w zł.</p>`;
    }

    function seg(r) {
        return `<span class="tw-seg">${Object.entries(D.decisions).map(([k, l]) => {
            const dis = k === 'change' && !(r.alt && r.alt.offer_id);
            return `<button type="button" class="${dec.get(r.id) === k ? 'on' : ''}" data-cr-dec="${r.id}" data-v="${k}" ${dis ? 'disabled title="tylko z ważną ofertą"' : ''}>${esc(l)}</button>`;
        }).join('')}</span>`;
    }

    function step2() {
        const rows = D.rows.filter(r => r.impact > 0).map(r => ({cls: dec.get(r.id) === 'change' ? 'hl' : '', cells: [`<b>${esc(r.name)}</b><small>${esc(r.supplier || '')} · ${price(r.price, r.unit)}</small>`,
            r.alt ? `${esc(r.alt.supplier)} · ${price(r.alt.price, r.unit)}<small>${r.alt.source === 'offer' ? 'oferta od ' + tw.dm(r.alt.at) : 'ostatnia cena ' + tw.dm(r.alt.at) + ' (bez oferty)'}</small>` : '<span class="tw-note">brak tańszej oferty</span>',
            r.alt ? `<b>${tw.zl(r.alt.saving)}</b>/mies.` : '—', seg(r)]}));
        return `<p class="tw-lead">Dla każdego surowca, który zdrożał : jedna decyzja. « Zmień » przyjmuje ważną ofertę (nowy dostawca bieżący), « Zapytaj » dodaje surowiec do zapytania ofertowego.</p>
            ${tw.table([{label: 'Surowiec'}, {label: 'Inna opcja'}, {label: 'Oszczędność', num: true}, {label: 'Decyzja'}], rows, 'Żaden surowiec nie zdrożał — nic do decyzji.')}
            <p class="tw-note">Oferty : Oferty dostawców (ważne dziś) ; bez oferty — ostatnia cena innego dostawcy z dziennika cen (12 mies.), wtedy « Zapytaj ».</p>`;
    }

    function step3() {
        if (D.done && !result) return tw.done('Decyzje zapisane', D.done.summary + ' · zadanie zaliczone') + `<p class="tw-note"><a href="${at.root}/purchasing/tenders">Zapytania ofertowe</a> · <a href="${at.root}/purchasing">Koszty surowców</a></p>`;
        if (result) return tw.done('Decyzje zapisane', result.summary + ' · zadanie zaliczone')
            + (result.tender ? `<p class="tw-note">Szkic zapytania <b>${esc(result.tender.code || '')}</b> (${result.tender.suppliers} dostawców) — <a href="${at.root}/purchasing/tenders">sprawdź i wyślij w Zapytaniach ofertowych</a>.</p>` : '');
        const list = D.rows.filter(r => r.impact > 0);
        const ch = list.filter(r => dec.get(r.id) === 'change'), ask = list.filter(r => dec.get(r.id) === 'ask');
        const saving = ch.reduce((s, r) => s + (r.alt ? r.alt.saving : 0), 0);
        return `${tw.tiles([{label: 'Zmiana dostawcy', value: String(ch.length), sub: ch.length ? '−' + tw.zl(saving) + '/mies.' : 'brak', cls: ch.length ? 'ok' : ''},
                {label: 'Zapytania', value: String(ask.length), sub: ask.length ? 'jeden szkic zapytania' : 'brak'},
                {label: 'Zostaw', value: String(list.length - ch.length - ask.length), sub: 'bez zmian'}], 3)}
            ${ch.map(r => `<div class="tw-ck" style="cursor:default"><i class="bi bi-arrow-left-right" style="color:#1f5a37"></i><span>${esc(r.name)} : ${esc(r.alt.supplier)} od następnego zamówienia<small>oferta przyjęta · −${tw.zl(r.alt.saving)}/mies. ; cena bazowa receptur = najwyższa zatwierdzona</small></span></div>`).join('')}
            ${ask.length ? `<div class="tw-ck" style="cursor:default"><i class="bi bi-envelope-paper" style="color:#8b857e"></i><span>Zapytanie ofertowe : ${ask.map(r => esc(r.name)).join(', ')}<small>szkic w Zapytaniach ofertowych — obecni dostawcy + dostawcy surowca z e-mailem ; wysyłasz po sprawdzeniu</small></span></div>` : ''}`;
    }

    function render() {
        const body = step === 1 ? step1() : (step === 2 ? step2() : step3());
        const next = step < 3 ? {label: step === 1 ? 'Dalej : tańsze opcje' : 'Dalej : decyzje', attr: 'data-cr-next'}
            : ((D.done || result) ? null : {label: 'Zapisz decyzje i zalicz', attr: 'data-cr-finish', final: true, disabled: busy});
        app.innerHTML = tw.frame({title: 'Koszty surowców', sub: `${D.month.label} · krok ${step} z 3 · ok. 20 min`, steps: STEPS, step, reached, body, prev: step > 1 && !result ? 'Wstecz' : null, next, later: !D.done && !result});
    }

    tw.bind(app, (n) => { step = n === -1 ? Math.max(1, step - 1) : n; render(); });
    app.addEventListener('click', async (e) => {
        if (e.target.closest('[data-cr-next]')) { step = Math.min(3, step + 1); reached = Math.max(reached, step); render(); return; }
        const b = e.target.closest('[data-cr-dec]');
        if (b && !b.disabled) { dec.set(Number(b.dataset.crDec), b.dataset.v); render(); return; }
        if (e.target.closest('[data-cr-finish]') && !busy) {
            busy = true; render();
            const rows = D.rows.filter(r => r.impact > 0).map(r => ({id: r.id, decision: dec.get(r.id)}));
            const res = await tw.post('/ajax/purchasing/cost-review/finish', {m: D.month.key, rows});
            busy = false;
            if (res.ok) { at.notify(true, res.message); result = res.result; D = res.data; }
            render();
        }
    });
    render();
})();
