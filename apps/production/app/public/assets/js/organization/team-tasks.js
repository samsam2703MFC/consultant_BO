// Zadania zespołu [232] (makieta 7 — tablica, makieta 8 — « Przypomnij ») : filtry (okres, ‹ ›, grupa / stanowisko, osoba,
// stan, co, kategoria, szukaj), kafelki, wiersze w sekcjach (tablet grupy, pracownia, stanowiska ERP), zaznaczanie,
// pasek « Przypomnij zaznaczonym » i okno : do kogo (tablet / dzwonek + e-mail), wiadomość z zadania, pilność, kanały.
(() => {
    const app = document.getElementById('ttApp');
    if (!app) return;
    const esc = at.esc;
    let D = JSON.parse(document.getElementById('ttBoot').textContent);
    const picked = new Map();   // key → {task, group_id, due, section}
    const open = new Set();     // wiersze z rozwiniętą historią przypomnień
    let modal = null;
    let searchTimer = null;
    const VIEWS = [['day', 'Dziś'], ['week', 'Tydzień'], ['month', 'Miesiąc']];
    const STATES = [['all', 'Wszystkie'], ['todo', 'Niezrobione'], ['late', 'Spóźnione'], ['done', 'Zrobione']];
    const plural = (n, one, few, many) => n === 1 ? one : ([2, 3, 4].includes(n % 10) && ![12, 13, 14].includes(n % 100) ? few : many);
    const hm = (ts) => ts ? String(ts).slice(11, 16) : '';
    const DAY_ABBR = ['niedz.', 'pn.', 'wt.', 'śr.', 'czw.', 'pt.', 'sob.'];
    const DAY_LONG = ['niedziela', 'poniedziałek', 'wtorek', 'środa', 'czwartek', 'piątek', 'sobota'];
    const dayOf = (ts) => {
        const d = String(ts || '').slice(0, 10);
        if (!d) return '';
        return d === D.today ? 'dziś' : `${DAY_ABBR[new Date(d + 'T12:00:00').getDay()]} ${d.slice(8, 10)}.${d.slice(5, 7)}`;
    };
    const pct = (a, b) => b ? Math.round(a * 100 / b) : 0;

    const sectionOf = (key) => D.sections.find(s => s.key === key);
    const rowOf = (key) => { for (const s of D.sections) { const r = s.rows.find(x => x.key === key); if (r) return [r, s]; } return [null, null]; };

    // --- Filtry ---------------------------------------------------------------------------------------------------
    function filters() {
        const f = D.filters;
        const o = D.options;
        const total = o.groups.reduce((a, g) => a + 1, 0);
        const sel = (name, label, icon, options, value) => `<label class="tt-sel ${value !== '' && value !== null ? 'on' : ''}">${icon ? `<i class="bi ${icon}"></i>` : ''}<span>${label}</span>
            <select name="${name}" aria-label="${esc(label)}">${options.map(([v, l, attrs]) => `<option value="${esc(v)}" ${String(v) === String(value ?? '') ? 'selected' : ''} ${attrs || ''}>${esc(l)}</option>`).join('')}</select></label>`;
        const people = [['', 'Wszyscy']].concat(o.people.map(p => [p.key, `${p.name} — ${p.sub}`]));
        return `<div class="tt-filters">
            <span class="wp-seg" role="group" aria-label="Okres">${VIEWS.map(([v, l]) => `<button type="button" class="${v === f.view ? 'on' : ''}" data-view="${v}" aria-pressed="${v === f.view}">${l}</button>`).join('')}</span>
            <span class="tt-nav"><button type="button" class="kw-btn" data-shift="-1" aria-label="Poprzedni okres"><i class="bi bi-chevron-left"></i></button>
                <button type="button" class="tt-period" data-shift="0" title="${esc(D.period.label)} — wróć do bieżącego">${esc(D.period.short)}</button>
                <button type="button" class="kw-btn" data-shift="1" aria-label="Następny okres"><i class="bi bi-chevron-right"></i></button></span>
            ${sel('group', 'Grupa / stanowisko', 'bi-people', [['', `Wszystkie (${total})`]].concat(o.groups.map(g => [g.key, `${g.name} · ${g.count}`])), f.group)}
            ${sel('person', 'Osoba', 'bi-person', people, f.person)}
            <span class="wp-seg tt-states" role="group" aria-label="Stan">${STATES.map(([v, l]) => `<button type="button" class="${v === f.state ? 'on' : ''}" data-state="${v}" aria-pressed="${v === f.state}">${l} <b>${D.counts[v]}</b></button>`).join('')}</span>
            ${sel('freq', 'Co', null, [['', 'wszystkie']].concat(o.frequencies.map(x => [x.key, x.name])), f.freq)}
            ${sel('category', 'Kategoria', null, [['', 'wszystkie']].concat(o.categories.map(c => [c.id, c.name])), f.category ?? '')}
            <label class="tt-search"><i class="bi bi-search"></i><input type="search" name="q" value="${esc(f.q)}" placeholder="Szukaj zadania…" aria-label="Szukaj zadania"></label>
        </div>`;
    }

    function tiles() {
        const t = D.tiles;
        return `<div class="kw-tiles tt-tiles">
            <div class="ok"><span>Zrobione</span><b>${t.done} z ${t.total}</b><small>${pct(t.done, t.total)} % · cel 90 %</small></div>
            <div class="${t.open ? 'todo' : ''}"><span>Niezrobione</span><b>${t.open}</b><small>${t.open_due} do dziś · ${t.open_later} później</small></div>
            <div class="${t.late ? 'late' : ''}"><span>Spóźnione</span><b>${t.late}</b><small>${t.late_2 ? `${t.late_2} ponad 2 dni` : 'żadne ponad 2 dni'}</small></div>
            <div><span>W toku</span><b>${t.running}</b><small>kreator rozpoczęty</small></div>
            <div><span>Przypomnienia dziś</span><b>${t.reminders}</b><small>${t.reminders_read} ${plural(t.reminders_read, 'przeczytane', 'przeczytane', 'przeczytanych')}</small></div>
        </div>`;
    }

    // --- Tabela ---------------------------------------------------------------------------------------------------
    function badge(r) {
        if (r.state === 'late') return `<span class="kw-badge bad">spóźnione ${r.late_days} ${plural(r.late_days, 'dzień', 'dni', 'dni')}</span>`;
        if (r.state === 'run') return `<span class="kw-badge warn">w toku${r.step ? ` · krok ${r.step} z ${r.steps}` : ''}</span>`;
        if (r.state === 'ok') return '<span class="kw-badge ok">zrobione</span>';
        if (r.state === 'auto') return '<span class="kw-badge ok">zaliczone samo</span>';
        if (r.state === 'plan') return '<span class="kw-badge">zaplanowane</span>';
        return '<span class="kw-badge">do zrobienia</span>';
    }

    function lastReminder(r) {
        const x = r.reminders[0];
        if (!x) return '<span class="tt-rem">—</span>';
        const read = x.read_at ? ` · <b>przeczytane${x.read_via === 'pin' || x.read_via === 'tap' ? '' : ''}</b>` : (x.closed ? ' · zadanie zrobione' : '');
        const how = x.kind === 'tablet' ? 'tablet' : (x.channels.includes('email') ? 'dzwonek + e-mail' : 'dzwonek');
        return `<button type="button" class="tt-rem" data-history="${esc(r.key)}" aria-expanded="${open.has(r.key)}" title="Historia przypomnień">${esc(dayOf(x.sent_at))} ${hm(x.sent_at)} · ${how}${read}${r.reminder_count > 1 ? ` <small>(${r.reminder_count}×)</small>` : ''}</button>`;
    }

    function history(r) {
        return `<tr class="tt-hist"><td></td><td colspan="7"><ul>${r.reminders.map(x => `<li><b>${esc(dayOf(x.sent_at))} ${hm(x.sent_at)}</b> → ${esc(x.to)} (${x.kind === 'tablet' ? 'komunikat na tablecie' : esc(x.channels.replace('bell', 'dzwonek').replace(',', ' + ').replace('email', 'e-mail'))})${x.urgent ? ' <span class="kw-badge bad">pilne</span>' : ''}
            ${x.sent_by ? ` · od ${esc(x.sent_by)}` : ''} · ${x.read_at ? `<span class="ok">przeczytane ${esc(dayOf(x.read_at))} ${hm(x.read_at)}${x.read_by ? ` (${esc(x.read_by)}${x.read_via === 'pin' ? ', PIN' : x.read_via === 'tap' ? ', obecny' : ''})` : (x.read_via === 'bell' ? ' (dzwonek)' : '')}</span>` : (x.closed ? 'zniknęło — zadanie zrobione' : 'nieprzeczytane')}
            <br><small>${esc(x.message)}</small></li>`).join('')}</ul></td></tr>`;
    }

    function row(r, s) {
        const on = picked.has(r.key);
        const title = r.link ? `<a href="${esc(at.root + r.link)}">${esc(r.title)}</a>` : esc(r.title);
        return `<tr class="${r.state === 'late' ? 'late' : ''} ${on ? 'on' : ''}" data-row="${esc(r.key)}">
            <td><input type="checkbox" class="tt-ck" data-pick="${esc(r.key)}" ${on ? 'checked' : ''} ${r.can_remind ? '' : 'disabled'} aria-label="Zaznacz : ${esc(r.title)} ${esc(r.due_label)}"></td>
            <td class="tt-task"><b>${title}</b>${r.hint ? `<small><i class="bi bi-magic"></i> ${esc(r.hint)}</small>` : ''}</td>
            <td><span class="tt-who"><span class="tt-av ${s.kind === 'tablet' ? 'tab' : ''}" style="${s.kind === 'tablet' ? `background:${esc(s.color)}` : ''}">${esc(r.who_initials)}</span>${esc(r.who)}</span></td>
            <td class="tt-due">${esc(r.due_label)}${r.due_time ? `<small>do ${esc(r.due_time)}</small>` : ''}</td>
            <td>${badge(r)}</td>
            <td class="tt-res">${esc(r.summary || r.step_label || '—')}${r.done_by && r.state === 'ok' ? `<small>${esc(r.done_by)} · ${esc(dayOf(r.done_at))} ${hm(r.done_at)}</small>` : ''}</td>
            <td>${lastReminder(r)}</td>
            <td class="r">${r.can_remind ? `<button type="button" class="kw-btn kw-sm tt-bell" data-remind-one="${esc(r.key)}" aria-label="Przypomnij : ${esc(r.title)}" title="Przypomnij"><i class="bi bi-bell"></i></button>` : ''}</td>
        </tr>${open.has(r.key) && r.reminders.length ? history(r) : ''}`;
    }

    function table() {
        if (!D.sections.length) return `<div class="kw-card"><p class="at-empty mb-0"><i class="bi bi-check2-circle"></i> ${D.counts.all ? 'Brak zadań w tym stanie — zmień filtr.' : 'Brak zadań w tym okresie dla wybranych filtrów.'}</p></div>`;
        const body = D.sections.map(s => {
            const remindable = s.rows.filter(r => r.can_remind);
            const allOn = remindable.length && remindable.every(r => picked.has(r.key));
            const head = `<tr class="tt-grp"><td><input type="checkbox" class="tt-ck" data-pick-section="${esc(s.key)}" ${allOn ? 'checked' : ''} ${remindable.length ? '' : 'disabled'} aria-label="Zaznacz wszystkie : ${esc(s.name)}"></td>
                <td colspan="6"><b>${esc(s.name)}</b><span class="tt-gs">${s.sub ? esc(s.sub) + ' · ' : ''}${s.done} z ${s.total} zrobione</span></td>
                <td class="r">${remindable.length ? `<button type="button" class="kw-btn kw-sm" data-remind-section="${esc(s.key)}"><i class="bi bi-bell"></i> ${s.kind === 'tablet' ? 'Przypomnij grupie' : 'Przypomnij'}</button>` : ''}</td></tr>`;
            const rows = s.rows.length ? s.rows.map(r => row(r, s)).join('') : `<tr><td></td><td colspan="7" class="at-sub">Wszystko zrobione w tym okresie.</td></tr>`;
            return head + rows;
        }).join('');
        return `<div class="kw-card tt-card"><div class="table-responsive"><table class="tt-tbl">
            <thead><tr><th class="tt-c"><span class="visually-hidden">Zaznacz</span></th><th>Zadanie</th><th>Kto</th><th>Termin</th><th>Stan</th><th>Wynik</th><th>Ostatnie przypomnienie</th><th></th></tr></thead>
            <tbody>${body}</tbody></table></div></div>`;
    }

    function bar() {
        if (!picked.size) return '';
        const secs = new Set([...picked.values()].map(p => p.section));
        const kinds = [...secs].map(k => sectionOf(k)).filter(Boolean);
        const g = kinds.filter(s => s.kind === 'tablet').length;
        const p = kinds.filter(s => s.kind === 'erp').length;
        const parts = [g ? `${g} ${plural(g, 'grupa', 'grupy', 'grup')}` : '', p ? `${p} ${plural(p, 'stanowisko', 'stanowiska', 'stanowisk')}` : ''].filter(Boolean).join(', ');
        return `<div class="tt-bar" role="region" aria-label="Zaznaczone zadania"><span><b>${picked.size}</b> ${plural(picked.size, 'zaznaczone', 'zaznaczone', 'zaznaczonych')} (${parts})</span>
            <button type="button" class="tt-link" data-clear>Odznacz</button><span class="wk-sp"></span>
            <span class="tt-bar-note">grupy : komunikat na tablecie · osoby : dzwonek + e-mail</span>
            <button type="button" class="kw-btn pri" data-remind-picked><i class="bi bi-bell"></i> Przypomnij zaznaczonym</button></div>`;
    }

    function render() {
        const y = window.scrollY;
        app.innerHTML = `<div class="kw tt">${filters()}${tiles()}${table()}${bar()}</div>${modal ? modalHtml() : ''}`;
        window.scrollTo(0, y);
        if (modal) app.querySelector('[data-tt-msg]')?.focus();
    }

    // --- Okno « Przypomnij » (makieta 8) ----------------------------------------------------------------------------
    function nextHour() {
        const d = new Date();
        const h = d.getHours() + (d.getMinutes() > 0 ? 2 : 1);
        return h >= 18 ? 'jutro rano' : `dziś do ${String(h).padStart(2, '0')}:00`;
    }

    function autoMessage(items) {
        if (items.length !== 1) return `Przypomnienie od kierownika produkcji : zadania niezrobione — proszę zrobić ${nextHour()}.`;
        const [r] = items;
        const d = new Date(r.due + 'T12:00:00');
        const when = r.due === D.today ? 'dziś' : `${DAY_LONG[d.getDay()]} ${r.due.slice(8, 10)}.${r.due.slice(5, 7)}`;
        const status = r.state === 'run' ? `jest w toku${r.step_label ? ` (${r.step_label})` : ''}` : 'nie jest zrobione';
        return `Przypomnienie od kierownika produkcji : « ${r.title} » — ${when} ${status}${r.hint ? ` (${r.hint})` : ''}. Proszę zrobić ${r.due_time && r.due >= D.today ? 'do ' + r.due_time : nextHour()}.`;
    }

    function openModal(keys) {
        const items = keys.map(k => rowOf(k)).filter(([r]) => r && r.can_remind);
        if (!items.length) return at.notify(false, 'Brak niezrobionych zadań do przypomnienia.');
        const message = autoMessage(items.map(([r]) => r));
        modal = {keys: items.map(([r]) => r.key), prefill: message, message, urgent: false, channels: {bell: true, email: true, tablet: true}, sending: false};
        render();
    }

    function recipients() {
        const bySec = new Map();
        modal.keys.forEach(k => { const [r, s] = rowOf(k); if (r) { if (!bySec.has(s.key)) bySec.set(s.key, {s, n: 0}); bySec.get(s.key).n++; } });
        return [...bySec.values()];
    }

    function modalHtml() {
        const rec = recipients();
        const hasTablet = rec.some(x => x.s.kind === 'tablet');
        const hasUser = rec.some(x => x.s.kind === 'erp');
        const chip = (k, label) => `<button type="button" class="tt-chip ${modal.channels[k] ? 'on' : ''}" data-channel="${k}" aria-pressed="${modal.channels[k]}">${label}</button>`;
        const n = modal.keys.length;
        return `<div class="fc-modal tt-modal" data-modal><div class="fc-modal-box tt-modal-box" role="dialog" aria-modal="true" aria-labelledby="ttModalTitle">
            <div class="fc-modal-head"><h4 id="ttModalTitle"><i class="bi bi-bell"></i> Przypomnienie — ${n} ${plural(n, 'zadanie', 'zadania', 'zadań')}</h4><button type="button" class="kw-btn" data-close>Zamknij</button></div>
            <div class="tt-lab">Do kogo</div>
            <div class="tt-to">${rec.map(({s, n: c}) => `<div class="tt-opt"><span class="tt-av ${s.kind === 'tablet' ? 'tab' : ''}" style="${s.kind === 'tablet' ? `background:${esc(s.color)}` : ''}">${esc(s.initials)}</span>
                <div><b>${esc(s.kind === 'tablet' ? s.name : `${s.sub} — ${s.name}`)}</b><small>${esc(s.channel || '')}${s.kind === 'tablet' && s.sub ? ' · ' + esc(s.sub) : ''} · ${c} ${plural(c, 'zadanie', 'zadania', 'zadań')}</small></div></div>`).join('')}</div>
            <label class="tt-lab" for="ttMsg">Wiadomość (uzupełniona z zadania — można zmienić)</label>
            <textarea id="ttMsg" class="form-control tt-msg" rows="3" maxlength="1000" data-tt-msg>${esc(modal.message)}</textarea>
            ${n > 1 ? '<small class="at-sub">Bez zmiany tekstu każdy odbiorca dostaje nazwę swojego zadania i termin.</small>' : ''}
            <div class="tt-opts"><span class="tt-lab">Pilność</span><span class="wp-seg" role="group" aria-label="Pilność">
                <button type="button" class="${modal.urgent ? '' : 'on'}" data-urgent="0" aria-pressed="${!modal.urgent}">Normalna</button>
                <button type="button" class="${modal.urgent ? 'on' : ''}" data-urgent="1" aria-pressed="${modal.urgent}">Pilna (na czerwono)</button></span>
                <span class="wk-sp"></span><span class="tt-lab">Kanały</span>${hasUser ? chip('bell', 'dzwonek') + chip('email', 'e-mail') : ''}${hasTablet ? chip('tablet', 'tablet') : ''}</div>
            <div class="tt-modal-foot"><small class="at-sub">Zapis w historii zadania : kto, kiedy, do kogo, przeczytane.</small><span class="wk-sp"></span>
                <button type="button" class="tt-link" data-close>Anuluj</button>
                <button type="button" class="kw-btn pri" data-send ${modal.sending ? 'disabled' : ''}><i class="bi bi-send"></i> Wyślij przypomnienie</button></div>
        </div></div>`;
    }

    async function send() {
        modal.sending = true;
        app.querySelector('[data-send]').disabled = true;
        const items = modal.keys.map(k => { const [r] = rowOf(k); return {task: r.task, group_id: r.group_id, due: r.due}; });
        const message = modal.message.trim() === modal.prefill.trim() ? '' : modal.message.trim();
        const res = await at.send('/ajax/organization/team-tasks/remind', 'POST', {items, message, urgent: modal.urgent, channels: modal.channels});
        at.notify(res.ok, res.message || (res.ok ? 'Wysłane.' : 'Błąd.'));
        if (!res.ok) { modal.sending = false; render(); return; }
        modal.keys.forEach(k => picked.delete(k));
        modal = null;
        await load(D.filters);
    }

    // --- Dane ---------------------------------------------------------------------------------------------------------
    async function load(f) {
        const q = new URLSearchParams();
        Object.entries(f).forEach(([k, v]) => { if (v !== '' && v !== null && v !== undefined) q.set(k, v); });
        app.querySelectorAll('.tt-filters button, .tt-filters select').forEach(b => { b.disabled = true; });
        const res = await at.send('/ajax/organization/team-tasks?' + q.toString(), 'GET');
        if (!res.ok || !res.data) { at.notify(false, res.message || 'Błąd ładowania.'); render(); return; }
        D = res.data;
        // Zaznaczenia tylko z widocznych, niezrobionych wierszy.
        [...picked.keys()].forEach(k => { const [r] = rowOf(k); if (!r || !r.can_remind) picked.delete(k); });
        render();
        try { history.replaceState(null, '', '?' + q.toString()); } catch (e) { /* bez historii */ }
    }
    const setFilter = (patch) => load({...D.filters, ...patch});

    app.addEventListener('click', (e) => {
        const t = e.target;
        if (t.closest('[data-modal]')) {
            if (t.closest('[data-close]') || t.matches('[data-modal]')) { modal = null; return render(); }
            const u = t.closest('[data-urgent]');
            if (u) { modal.urgent = u.dataset.urgent === '1'; return render(); }
            const c = t.closest('[data-channel]');
            if (c) { modal.channels[c.dataset.channel] = !modal.channels[c.dataset.channel]; return render(); }
            if (t.closest('[data-send]')) return send();
            return;
        }
        const v = t.closest('[data-view]');
        if (v) return setFilter({view: v.dataset.view, offset: 0});
        const sh = t.closest('[data-shift]');
        if (sh) return setFilter({offset: sh.dataset.shift === '0' ? 0 : Number(D.filters.offset) + Number(sh.dataset.shift)});
        const st = t.closest('[data-state]');
        if (st) return setFilter({state: st.dataset.state});
        const h = t.closest('[data-history]');
        if (h) { open.has(h.dataset.history) ? open.delete(h.dataset.history) : open.add(h.dataset.history); return render(); }
        const one = t.closest('[data-remind-one]');
        if (one) return openModal([one.dataset.remindOne]);
        const sec = t.closest('[data-remind-section]');
        if (sec) return openModal(sectionOf(sec.dataset.remindSection).rows.filter(r => r.can_remind).map(r => r.key));
        if (t.closest('[data-remind-picked]')) return openModal([...picked.keys()]);
        if (t.closest('[data-clear]')) { picked.clear(); return render(); }
    });

    app.addEventListener('change', (e) => {
        const t = e.target;
        if (t.matches('[data-pick]')) {
            const [r, s] = rowOf(t.dataset.pick);
            if (t.checked) picked.set(r.key, {section: s.key}); else picked.delete(r.key);
            return render();
        }
        if (t.matches('[data-pick-section]')) {
            sectionOf(t.dataset.pickSection).rows.filter(r => r.can_remind).forEach(r => { if (t.checked) picked.set(r.key, {section: t.dataset.pickSection}); else picked.delete(r.key); });
            return render();
        }
        if (t.matches('.tt-filters select')) return setFilter({[t.name]: t.value});
    });

    app.addEventListener('input', (e) => {
        if (e.target.matches('[data-tt-msg]') && modal) { modal.message = e.target.value; return; }
        if (e.target.matches('.tt-search input')) {
            window.clearTimeout(searchTimer);
            const value = e.target.value;
            searchTimer = window.setTimeout(() => setFilter({q: value}).then(() => {
                const inp = app.querySelector('.tt-search input');
                if (inp) { inp.focus(); inp.setSelectionRange(inp.value.length, inp.value.length); }
            }), 350);
        }
    });

    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && modal) { modal = null; render(); } });

    render();
})();
