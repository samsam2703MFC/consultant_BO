// [387] Rutyny poranne AI : propozycje w kroku 2 asystentów pilota (Zamówienia dnia, Opóźnienia dostaw, Faktury i KSeF
// dnia). AI tylko proponuje : « Przyjmij » wpisuje decyzję i notatkę do formularza (zapis dalej przyciskiem « Zapisz » i
// walidacją asystenta), « Popraw » wpisuje i ustawia kursor w notatce, « Odrzuć » chowa propozycję (zapisane w ERP).
// Brak propozycji (D.ai = null) → nic z AI na ekranie.
(() => {
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const plural = (n, one, few, many) => n === 1 ? one : ([2, 3, 4].includes(n % 10) && ![12, 13, 14].includes(n % 100) ? few : many);
    // Stan lokalny strony : odrzucone (do odświeżenia danych) i przyjęte do formularza (czekają na « Zapisz »).
    const rejected = new Set(), taken = new Set();
    const visible = (ai) => ai && Array.isArray(ai.proposals) ? ai.proposals.filter(p => !rejected.has(p.id)) : [];
    const find = (ai, ref) => visible(ai).find(p => p.ref === String(ref)) || null;

    const aiProp = {
        find,
        /** Pasek nad listą kroku 2 (pusty, gdy nie ma propozycji). */
        banner(ai) {
            const list = visible(ai);
            if (!list.length) return '';
            const c = {high: 0, medium: 0, low: 0};
            list.forEach(p => { c[p.confidence] = (c[p.confidence] || 0) + 1; });
            const parts = [c.high ? `${c.high} ${plural(c.high, 'pewna', 'pewne', 'pewnych')}` : '', c.medium ? `${c.medium} ${plural(c.medium, 'średnia', 'średnie', 'średnich')}` : '',
                c.low ? `${c.low} ${plural(c.low, 'niepewna', 'niepewne', 'niepewnych')}` : ''].filter(Boolean).join(', ');
            return `<div class="ai-ban"><span class="ai-star">✦</span><div><b>Asystent AI przygotował propozycje — ${ai.today ? 'dziś' : esc(ai.day)} ${esc(ai.at)}</b>
                <small>${ai.signals} ${plural(ai.signals, 'sygnał', 'sygnały', 'sygnałów')} · ${list.length} ${plural(list.length, 'propozycja', 'propozycje', 'propozycji')} (${parts}).
                Nic nie jest zapisane : każdą propozycję przyjmujesz albo poprawiasz sam. Do AI nie wysłano danych osobowych (klienci jako numery).</small>
                ${ai.summary ? `<small class="ai-sum">${esc(ai.summary)}</small>` : ''}</div>
                <span class="ai-sp"></span><small class="ai-cost">${esc(ai.model_label)} · ${esc(ai.cost_label)}</small></div>`;
        },
        /** Propozycja w komórce wyniku linii (pusta, gdy brak). */
        box(ai, ref, hint = '') {
            const p = find(ai, ref);
            if (!p) return '';
            const done = taken.has(p.id);
            return `<div class="ai-p ${done ? 'taken' : ''}" data-ai-box="${p.id}"><div class="h">✦ Propozycja AI : ${esc(p.label)}<span class="c ${esc(p.confidence)}">pewność ${esc(p.confidence_label)}</span></div>
                ${p.note ? `<div class="t">${esc(p.note)}</div>` : ''}${p.reason ? `<div class="w">Dlaczego : ${esc(p.reason)}</div>` : ''}${hint ? `<div class="w">${esc(hint)}</div>` : ''}
                ${done ? `<div class="w ok">✓ Wpisane do formularza — sprawdź i kliknij « Zapisz ».</div>` : ''}
                <div class="a"><button type="button" class="y" data-ai-act="take" data-ai-id="${p.id}">Przyjmij</button><button type="button" data-ai-act="edit" data-ai-id="${p.id}">Popraw</button>
                <button type="button" data-ai-act="reject" data-ai-id="${p.id}">Odrzuć</button></div></div>`;
        },
        /**
         * Zdarzenia przycisków. opts : {ai: () => D.ai, apply: (p, edit) => …wpisanie do brouillonu + render…, render: () => …}.
         */
        bind(root, opts) {
            root.addEventListener('click', async (e) => {
                const b = e.target.closest('[data-ai-act]');
                if (!b) return;
                const ai = opts.ai(), id = Number(b.dataset.aiId);
                const p = ai && (ai.proposals || []).find(x => x.id === id);
                if (!p) return;
                if (b.dataset.aiAct === 'reject') {
                    b.disabled = true;
                    const res = await at.send(`/ajax/ai/proposals/${id}`, 'POST', {status: 'rejected'});
                    if (!res.ok) { at.notify(false, res.message || 'Nie udało się odrzucić propozycji.'); b.disabled = false; return; }
                    rejected.add(id);
                    taken.delete(id);
                    opts.render();
                    return;
                }
                taken.add(id);
                opts.apply(p, b.dataset.aiAct === 'edit');
            });
        },
        /** Po zapisie linii : propozycja rozstrzygnięta po stronie serwera (dane odświeżone). */
        forget(id) { taken.delete(id); },
    };
    window.aiProp = aiProp;
})();
