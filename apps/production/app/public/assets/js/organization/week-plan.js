// Zadania stanowiska [227] (makieta 2, tylko administrator) : zadanie = kreator (zalicza się samo na ostatnim kroku),
// częstotliwość (dzień / tydzień / miesiąc / kwartał + termin), minuty, automatycznie (cron : kiedy, o której, co robi,
// potem), przypomnienia (osoba w dniu terminu, przełożony po N dniach), kreatory do przypięcia, obciążenie dni.
// Tabela pogrupowana według częstotliwości ; klik w wiersz = panel edycji po prawej. Zapis całego planu naraz :
// PUT /ajax/organization/week-plan/{id} (PositionTaskService::saveWeekPlan).
(() => {
    const app = document.getElementById('weekPlanApp');
    if (!app) return;
    const esc = at.esc;
    const positionId = app.dataset.position;
    let P = JSON.parse(document.getElementById('weekPlanBoot').textContent);
    let rows = [];
    let removed = new Set();
    let dirty = false;
    let seq = 0;
    let sel = null;
    let snapshot = null;
    const DAY7 = {1: 'Pn', 2: 'Wt', 3: 'Śr', 4: 'Cz', 5: 'Pt', 6: 'So', 7: 'Nd'};
    const DAY_LONG = {1: 'poniedziałek', 2: 'wtorek', 3: 'środa', 4: 'czwartek', 5: 'piątek', 6: 'sobota', 7: 'niedziela'};
    const DAY_ABBR = {1: 'pn.', 2: 'wt.', 3: 'śr.', 4: 'czw.', 5: 'pt.', 6: 'sob.', 7: 'niedz.'};
    const MODES = [['day', 'Dzień'], ['week', 'Tydzień'], ['monthly', 'Mies.'], ['quarterly', 'Kw.']];
    const GROUPS = {day: 'Codziennie', week: 'Co tydzień', monthly: 'Co miesiąc', quarterly: 'Co kwartał'};
    const CRON_FREQ = [['daily', 'Codz.'], ['weekly', 'Co tydz.'], ['monthly', 'Co mies.'], ['quarterly', 'Kw.']];
    // Kreator przypięty z katalogu : domyślna częstotliwość (reszta : co tydzień w poniedziałek).
    const WIZ_MODE = {production_day_closed: 'day', day_shipped: 'day', month_closed: 'monthly', services_closed: 'monthly'};
    const dur = (m) => !m ? '0' : (m < 60 ? `${m} min` : `${Math.floor(m / 60)} h` + (m % 60 ? ` ${String(m % 60).padStart(2, '0')}` : ''));
    const has = (o, k) => Object.prototype.hasOwnProperty.call(o, k);
    const wiz = (code) => P.wizards.find(w => w.code === code) || null;
    const rowByKey = (key) => rows.find(r => r.key === key);
    const ruleLabel = (rule) => {
        const m = /^(wd|d)(\d+)$/.exec(rule || '');
        return !m ? 'ostatni dzień' : (m[1] === 'wd' ? `${m[2]}. dzień roboczy` : `${m[2]}. dzień`);
    };

    function modeOf(t) {
        if (t.period === 'monthly' || t.period === 'quarterly') return t.period;
        return t.days.length > 1 ? 'day' : 'week';
    }

    function load(plan) {
        P = plan;
        rows = plan.tasks.map(t => ({...t, key: 't' + t.id, custom: false, mode: modeOf(t)}));
        removed = new Set();
        dirty = false;
        sel = sel && rows.some(r => r.key === sel) ? sel : null;
        snapshot = null;
        render();
    }

    // --- tabela ------------------------------------------------------------------------------------------------

    function whenHtml(r) {
        const time = r.due_time ? ` · do ${esc(r.due_time)}` : '';
        if (r.mode === 'day') {
            return `<div class="wp-when">${P.workdays.map(d => `<span class="d ${r.days.includes(d) ? 'on' : ''}">${esc(DAY7[d])}</span>`).join('')}</div>${r.due_time ? `<div class="wp-when">do ${esc(r.due_time)}</div>` : ''}`;
        }
        if (r.mode === 'week') return `<div class="wp-when">${r.days.length ? esc(DAY_LONG[r.days[0]]) : '<span class="text-danger">wybierz dzień</span>'}${time}</div>`;
        const rule = ruleLabel(r.month_day);
        return `<div class="wp-when">${r.mode === 'quarterly' ? (r.month_day ? `${esc(rule)} kwartału` : 'ostatni dzień kwartału') : esc(rule)}${time}</div>`;
    }

    function cronDays(r) {
        if (r.cron_freq === 'daily') return (r.cron_days || '').split(',').filter(Boolean).map(d => DAY7[d]).join(' ');
        if (r.cron_freq === 'weekly') return DAY_ABBR[r.cron_days] || '';
        return ruleLabel(r.cron_days) ;
    }

    function cronHtml(r) {
        if (!r.cron_on) return '<span class="kw-badge">wyłączone</span>';
        const runs = (r.id && P.runs[r.id]) || [];
        const last = runs[0];
        return `<span class="kw-badge ok"><i class="bi bi-robot"></i> ${esc(cronDays(r))} ${esc(r.cron_time || '')}${r.cron_then === 'wait' ? ' przygotuj' : ''}</span>
            <small class="wp-sub">${r.cron_then === 'wait' ? 'osoba kończy w kreatorze' : 'zalicza się samo'}${last ? (Number(last.ok) === 1 ? ' · ostatnio OK' : (last.ok === null ? ' · w toku' : ' · <b class="text-danger">ostatnio błąd</b>')) : ''}</small>`;
    }

    function wizHtml(r) {
        const w = wiz(r.auto_code);
        if (w) return `<div class="wp-sel"><span>${esc(w.label)}<small>${esc(w.event)}</small></span></div>`;
        if (r.form_code) return '<div class="wp-sel none"><span>— ręcznie —<small>protokół zebrania</small></span></div>';
        if (r.cron_on) return '<div class="wp-sel none"><span>— bez kreatora —<small>w całości automatyczne</small></span></div>';
        return `<div class="wp-sel none"><span>— bez kreatora —<small>${r.link_path ? esc(P.links[r.link_path] || r.link_path) : 'osoba zaznacza « Zrobione »'}</small></span></div>`;
    }

    function rowHtml(r) {
        const off = removed.has(r.key);
        const cat = r.category_id ? (P.categories.find(c => Number(c.id) === r.category_id)?.name_pl || '') : '';
        return `<tr data-row="${r.key}" class="wp-row${sel === r.key ? ' cur' : ''}${off ? ' ad-off' : ''}${r.id ? '' : ' ad-new'}" tabindex="0" aria-label="Edytuj : ${esc(r.title_pl || 'nowe zadanie')}">
            <td class="nm"><b>${esc(r.title_pl || 'Nowe zadanie')}</b><small>${off ? 'do usunięcia przy zapisie' : esc(cat || '—')}</small></td>
            <td>${wizHtml(r)}</td>
            <td>${whenHtml(r)}</td>
            <td class="r"><span class="wp-num">${r.minutes ?? '—'}</span>${r.real ? `<small class="ad-real ${r.real_state}" title="Średni czas aktywny na stronie (4 tygodnie)">rzecz. ${r.real} min</small>` : ''}</td>
            <td>${cronHtml(r)}</td>
        </tr>`;
    }

    function tableHtml() {
        if (!rows.length) return '<p class="at-empty">Brak zadań — dodaj pierwsze, przypnij kreator z listy obok albo skopiuj z innego stanowiska.</p>';
        const out = [];
        for (const [mode, label] of Object.entries(GROUPS)) {
            const list = rows.filter(r => r.mode === mode);
            if (!list.length) continue;
            out.push(`<tr class="grp"><td colspan="5">${label}</td></tr>`, ...list.map(rowHtml));
        }
        return `<div class="table-responsive"><table class="kw-t wp-t" id="adPlan">
            <thead><tr><th style="width:24%">Zadanie</th><th style="width:25%">Kreator (zalicza się, gdy…)</th><th style="width:20%">Kiedy</th><th class="r">Min.</th><th style="width:20%">Automatycznie (cron)</th></tr></thead>
            <tbody>${out.join('')}</tbody></table></div>`;
    }

    function loadHtml() {
        const per = Object.fromEntries(P.workdays.map(d => [d, 0]));
        rows.forEach(r => {
            if (removed.has(r.key) || r.period !== 'week') return;
            r.days.forEach(d => { if (has(per, d)) per[d] += Number(r.minutes) || 0; });
        });
        const week = Object.values(per).reduce((a, b) => a + b, 0);
        const scale = Math.max(120, ...Object.values(per));
        return `<div class="ad-load" style="--ad-days: ${P.workdays.length}">${P.workdays.map(d => `<div class="${per[d] > 240 ? 'over' : ''}">${esc(P.day_short[d])}<b>${per[d]} min</b>
                <div class="bar"><i style="width: ${Math.round(per[d] / scale * 100)}%"></i></div></div>`).join('')}
            <div>Tydzień<b>${dur(week)}</b>${Math.round(week / P.load.week_minutes * 100)} % z 40 h · reszta : bieżąca praca</div></div>`;
    }

    // --- panel edycji ------------------------------------------------------------------------------------------

    function seg(name, items, value) {
        return `<div class="wp-seg" role="group">${items.map(([v, l]) => `<button type="button" class="${v === value ? 'on' : ''}" data-seg="${name}" data-v="${v}" aria-pressed="${v === value}">${l}</button>`).join('')}</div>`;
    }

    function dayButtons(name, list, on, single) {
        return `<div class="wp-days">${list.map(d => `<button type="button" class="${on.includes(d) ? 'on' : ''}" data-dayset="${name}" data-d="${d}" data-single="${single ? 1 : 0}" aria-pressed="${on.includes(d)}">${DAY7[d]}</button>`).join('')}</div>`;
    }

    function ruleInput(name, rule) {
        const m = /^(wd|d)(\d+)$/.exec(rule || '');
        const kind = m ? m[1] : 'last';
        return `<div class="wp-rule"><input class="kw-in" type="number" min="1" max="31" data-rule-n="${name}" value="${m ? m[2] : ''}" ${kind === 'last' ? 'disabled' : ''} aria-label="Który dzień">
            <select class="kw-in" data-rule-kind="${name}" aria-label="Rodzaj dnia"><option value="wd" ${kind === 'wd' ? 'selected' : ''}>dzień roboczy</option><option value="d" ${kind === 'd' ? 'selected' : ''}>dzień miesiąca</option>
            ${name === 'month' ? `<option value="last" ${kind === 'last' ? 'selected' : ''}>ostatni dzień</option>` : ''}</select></div>`;
    }

    function linkOptions(r) {
        const custom = r.custom || (r.link_path && !has(P.links, r.link_path));
        return `<option value="">— bez linku —</option>`
            + Object.entries(P.links).map(([p, l]) => `<option value="${esc(p)}" ${!custom && p === r.link_path ? 'selected' : ''}>${esc(l)}</option>`).join('')
            + `<option value="__custom" ${custom ? 'selected' : ''}>Inny adres…</option>`;
    }

    function runsHtml(r) {
        const runs = (r.id && P.runs[r.id]) || [];
        if (!runs.length) return '<div class="wp-sub">Jeszcze nie uruchomione.</div>';
        return `<div class="wp-sub">Ostatnie : ${runs.map(x => {
            const d = new Date(String(x.started_at).replace(' ', 'T'));
            const secs = x.finished_at ? Math.max(0, Math.round((new Date(String(x.finished_at).replace(' ', 'T')) - d) / 1000)) : null;
            const took = secs === null ? '' : (secs < 60 ? ` · ${secs} s` : ` · ${Math.floor(secs / 60)} min ${secs % 60} s`);
            const label = `${DAY_ABBR[((d.getDay() + 6) % 7) + 1]} ${String(d.getDate()).padStart(2, '0')}.${String(d.getMonth() + 1).padStart(2, '0')} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
            const badge = x.ok === null ? '<span class="kw-badge warn">w toku</span>' : (Number(x.ok) === 1 ? `<span class="kw-badge ok">OK${took}</span>` : `<span class="kw-badge bad" title="${esc(x.error || '')}">błąd</span>`);
            return `${label} ${badge}${x.summary ? ` <span title="${esc(x.summary)}">${esc(x.summary.length > 40 ? x.summary.slice(0, 39) + '…' : x.summary)}</span>` : ''}`;
        }).join(' · ')}</div>`;
    }

    function editorHtml() {
        const r = sel && rowByKey(sel);
        if (!r) {
            return `<div class="kw-card wp-ed empty"><h5>Edycja</h5><p class="at-empty mb-0">Kliknij zadanie w tabeli, aby zmienić kreator, częstotliwość, automatyzację i przypomnienia.</p></div>`;
        }
        const off = removed.has(r.key);
        const w = wiz(r.auto_code);
        const wizOptions = `<option value="">— bez kreatora (ręcznie) —</option>` + P.wizards.map(x => {
            const elsewhere = x.used.filter(u => !u.mine).map(u => u.position);
            return `<option value="${esc(x.code)}" ${x.code === r.auto_code ? 'selected' : ''}>${esc(x.label)} — ${esc(x.event)}${elsewhere.length ? ` (też : ${esc(elsewhere.join(', '))})` : ''}</option>`;
        }).join('');
        const custom = r.custom || (r.link_path && !has(P.links, r.link_path));
        const actions = Object.entries(P.cron_actions).map(([k, l]) => `<option value="${esc(k)}" ${k === r.cron_action ? 'selected' : ''}>${esc(l)}</option>`).join('');
        let cronWhen = '';
        if (r.cron_freq === 'daily') cronWhen = dayButtons('cron', [1, 2, 3, 4, 5, 6, 7], (r.cron_days || '').split(',').map(Number), false);
        else if (r.cron_freq === 'weekly') cronWhen = dayButtons('cron', [1, 2, 3, 4, 5, 6, 7], [Number(r.cron_days)], true);
        else if (r.cron_freq) cronWhen = ruleInput('cron', r.cron_days);
        return `<div class="kw-card wp-ed" data-ed="${r.key}">
            <h5>Edycja <span class="wp-ed-t">${esc(r.title_pl || 'Nowe zadanie')}</span></h5>
            ${off ? '<p class="kw-note">Zadanie zostanie usunięte z planu przy zapisie.</p>' : ''}
            <div class="wp-f"><label for="wpTitle">Nazwa zadania</label><textarea id="wpTitle" class="kw-in" data-f="title_pl" rows="2" maxlength="200" placeholder="Nazwa nowego zadania">${esc(r.title_pl)}</textarea></div>
            <div class="wp-f"><label for="wpCat">Kategoria</label><select id="wpCat" class="kw-in" data-f="category_id"><option value="">—</option>
                ${P.categories.map(c => `<option value="${c.id}" ${Number(c.id) === r.category_id ? 'selected' : ''}>${esc(c.name_pl)}</option>`).join('')}</select></div>
            <div class="wp-f"><label for="wpWiz">Kreator — zadanie zalicza się samo, gdy…</label><select id="wpWiz" class="kw-in" data-f="wizard">${wizOptions}</select>
                ${w ? `<div class="wp-sub"><i class="bi bi-magic"></i> otwiera ${esc(w.link)} · ${w.steps > 1 ? `${w.steps} kroków` : '1 krok'} · zalicza się, gdy ${esc(w.event)}</div>`
                    : `<select class="kw-in mt-1" data-f="link" aria-label="Strona ERP (link)">${linkOptions(r)}</select>
                       ${custom ? `<input class="kw-in mt-1" data-f="link_custom" value="${esc(r.link_path || '')}" placeholder="/strona-w-erp" maxlength="255" aria-label="Adres strony">` : ''}
                       ${r.form_code ? '<div class="wp-sub"><i class="bi bi-journal-text"></i> kończy się protokołem</div>' : '<div class="wp-sub">Bez kreatora : osoba zaznacza « Zrobione ».</div>'}`}</div>
            <div class="wp-f"><label>Częstotliwość i termin</label>${seg('mode', MODES, r.mode)}
                ${r.mode === 'day' ? dayButtons('task', P.workdays, r.days, false) : ''}
                ${r.mode === 'week' ? dayButtons('task', P.workdays, r.days, true) : ''}
                ${r.mode === 'monthly' || r.mode === 'quarterly' ? `<div class="wp-sub">${r.mode === 'quarterly' ? 'W 1. miesiącu kwartału (bez reguły : ostatni dzień kwartału)' : 'Termin w miesiącu'}</div>${ruleInput('month', r.month_day)}` : ''}
                <div class="wp-inline"><span>do</span><input class="kw-in" type="time" data-f="due_time" value="${esc(r.due_time || '')}" aria-label="Termin — godzina"><span>·</span>
                    <input class="kw-in wp-min" type="number" inputmode="numeric" min="0" max="${P.max_minutes}" step="5" data-f="minutes" value="${r.minutes ?? ''}" aria-label="Minuty"><span>min</span>
                    ${r.real ? `<span class="ad-real ${r.real_state}" title="Średni czas aktywny na stronie zadania (4 tygodnie)">rzeczywiście ${r.real} min</span>` : ''}</div></div>
            <div class="wp-box">
                <div class="wp-inline"><b><i class="bi bi-robot"></i> Automatycznie (cron)</b><span class="wk-sp"></span>
                    <button type="button" class="wp-switch ${r.cron_on ? 'on' : ''}" data-f="cron_on" role="switch" aria-checked="${r.cron_on}" aria-label="Automatycznie (cron)"><i></i></button><b class="${r.cron_on ? 'text-success' : 'text-muted'}">${r.cron_on ? 'Tak' : 'Nie'}</b></div>
                ${r.cron_on ? `<div class="wp-kv">
                    <span>Kiedy</span>${seg('cron_freq', CRON_FREQ, r.cron_freq)}
                    <span>${r.cron_freq === 'monthly' || r.cron_freq === 'quarterly' ? 'Dzień' : 'Dni'}</span><div>${cronWhen || '<span class="wp-sub">wybierz « Kiedy »</span>'}</div>
                    <span>O której</span><input class="kw-in wp-time" type="time" data-f="cron_time" value="${esc(r.cron_time || '')}" aria-label="O której">
                    <span>Co robi</span><select class="kw-in" data-f="cron_action"><option value="">— wybierz —</option>${actions}</select>
                    <span>Potem</span><div class="wp-radio">
                        <label><input type="radio" name="wpThen" value="wait" data-f="cron_then" ${r.cron_then !== 'done' ? 'checked' : ''}> czeka na osobę${w ? ' (kreator)' : ''}</label>
                        <label><input type="radio" name="wpThen" value="done" data-f="cron_then" ${r.cron_then === 'done' ? 'checked' : ''}> zalicza się samo</label></div>
                    <span>Gdy błąd</span><span class="wp-sub m-0">powiadomienie w dzwonku · ponowienie co 30 min, najwyżej 3 razy</span>
                </div>${runsHtml(r)}` : '<div class="wp-sub">System nic nie robi sam — zadanie wykonuje osoba.</div>'}
            </div>
            <div class="wp-box">
                <b><i class="bi bi-bell"></i> Przypomnienia</b>
                <div class="wp-inline"><label class="wp-chk"><input type="checkbox" data-f="remind_on" ${r.remind_time ? 'checked' : ''}> w dniu terminu o</label>
                    <input class="kw-in wp-time" type="time" data-f="remind_time" value="${esc(r.remind_time || '09:00')}" ${r.remind_time ? '' : 'disabled'} aria-label="Przypomnienie — godzina"><span>→ osoba</span></div>
                <div class="wp-inline"><label class="wp-chk"><input type="checkbox" data-f="escalate_on" ${r.escalate_days ? 'checked' : ''}> spóźnione o</label>
                    <input class="kw-in wp-min" type="number" min="1" max="30" data-f="escalate_days" value="${r.escalate_days || 2}" ${r.escalate_days ? '' : 'disabled'} aria-label="Dni spóźnienia"><span>dni → przełożony (${esc(P.superior)})</span></div>
            </div>
            <div class="wp-acts"><button type="button" class="kw-btn ghost" data-del>${off ? '<i class="bi bi-arrow-counterclockwise"></i> Przywróć' : '<i class="bi bi-trash"></i> Usuń z planu'}</button><span class="wk-sp"></span>
                <button type="button" class="kw-btn" data-cancel>Anuluj</button><button type="button" class="kw-btn pri" data-save>Zapisz</button></div>
        </div>`;
    }

    function catalogHtml() {
        return `<div class="kw-card"><h5>Kreatory do przypięcia</h5><div class="wp-cat">${P.wizards.map(w => {
            const mine = w.used.some(u => u.mine) || rows.some(r => r.auto_code === w.code && !removed.has(r.key));
            const where = w.used.map(u => u.position);
            return `<div class="it${mine || where.length ? ' used' : ''}"><b>${esc(w.label)}</b><span class="ev">zalicza się : ${esc(w.event)}</span>
                <span class="wp-inline">${mine ? '<span class="kw-badge ok">przypięty tutaj</span>' : (where.length ? `<span class="kw-badge ok">przypięty</span> ${esc([...new Set(where)].join(', '))}` : '<span class="kw-badge warn">wolny</span>')}
                ${mine ? '' : `<span class="wk-sp"></span><button type="button" class="kw-btn" data-pin="${esc(w.code)}"><i class="bi bi-pin-angle"></i> Przypnij tutaj</button>`}</span></div>`;
        }).join('')}</div></div>`;
    }

    function render() {
        const users = P.users.map(u => u.displayed_name || u.login).join(' · ');
        const live = rows.filter(r => !removed.has(r.key));
        const nWiz = live.filter(r => r.auto_code).length;
        const nCron = live.filter(r => r.cron_on).length;
        const nManual = live.filter(r => !r.auto_code && !r.cron_on).length;
        const week = live.filter(r => r.period === 'week').reduce((s, r) => s + (Number(r.minutes) || 0) * r.days.length, 0);
        app.innerHTML = `<p class="wp-lead">${users ? `Osoby : ${esc(users)}` : '<span class="kw-badge warn">nikt nie ma tego stanowiska</span>'} · ${live.length} zadań · ${dur(week)} / tydzień</p>
            <div class="wp-grid"><div class="kw-card">
                <h5>${esc(P.position.name_pl)} <span class="kw-badge ok">${nWiz} z kreatorem — zaliczają się same</span><span class="kw-badge"><i class="bi bi-robot"></i> ${nCron} automatyczne</span><span class="kw-badge">${nManual} ręczne</span>
                    <span class="wk-sp"></span>${P.others.length ? '<button type="button" class="kw-btn" data-copy><i class="bi bi-files"></i> Kopiuj z innego stanowiska</button>' : ''}
                    <button type="button" class="kw-btn pri" data-add><i class="bi bi-plus-lg"></i> Nowe zadanie</button></h5>
                <div data-table>${tableHtml()}</div>
                <h6 class="wk-h">Obciążenie dni (zadania tygodnia)</h6>
                <div data-load>${loadHtml()}</div>
                <div class="kw-foot"><span class="at-sub">Osoby na stanowisku widzą te zadania na stronie Dzisiaj (« Mój tydzień »).</span>
                    <span class="d-flex gap-2 align-items-center"><span class="at-sub" data-dirty></span><button type="button" class="kw-btn pri" data-save>Zapisz zmiany</button></span></div>
            </div>
            <div class="wp-side"><div data-editor>${editorHtml()}</div><div data-catalog>${catalogHtml()}</div></div></div>`;
        markDirty(dirty);
    }

    function refresh(full = false) {
        if (full) return render();
        app.querySelector('[data-table]').innerHTML = tableHtml();
        app.querySelector('[data-load]').innerHTML = loadHtml();
        app.querySelector('[data-catalog]').innerHTML = catalogHtml();
    }
    function refreshEditor(focusSel) {
        app.querySelector('[data-editor]').innerHTML = editorHtml();
        if (focusSel) app.querySelector(`[data-editor] ${focusSel}`)?.focus();
    }
    function markDirty(on = true) {
        dirty = on;
        const el = app.querySelector('[data-dirty]');
        if (el) el.textContent = on ? 'Niezapisane zmiany' : '';
    }
    function touch(r, editor = false) {
        markDirty();
        refresh();
        if (editor) refreshEditor();
    }

    function open(key) {
        sel = key;
        const r = rowByKey(key);
        snapshot = r ? JSON.parse(JSON.stringify(r)) : null;
        refresh();
        refreshEditor();
        if (window.matchMedia('(max-width: 1199.98px)').matches) app.querySelector('[data-editor]').scrollIntoView({behavior: 'smooth', block: 'start'});
    }

    function newRow(extra = {}) {
        const r = {key: 'n' + (++seq), id: null, title_pl: '', category_id: null, period: 'week', days: [1], mode: 'week', minutes: null, link_path: null, auto_code: null,
            form_code: null, due_time: null, month_day: null, cron_on: false, cron_freq: null, cron_days: null, cron_time: null, cron_action: null, cron_then: 'wait',
            remind_time: '09:00', escalate_days: 2, real: null, real_state: '', shared: false, custom: false, ...extra};
        rows.push(r);
        return r;
    }

    function setMode(r, mode) {
        r.mode = mode;
        if (mode === 'day') { r.period = 'week'; r.days = [...P.workdays]; r.month_day = null; }
        else if (mode === 'week') { r.period = 'week'; r.days = [r.days[0] || 1]; r.month_day = null; }
        else { r.period = mode; r.days = []; }
    }

    function readRule(name) {
        const kind = app.querySelector(`[data-rule-kind="${name}"]`)?.value;
        const nEl = app.querySelector(`[data-rule-n="${name}"]`);
        if (!kind || kind === 'last') return null;
        const n = Math.max(1, Math.min(31, Number(nEl?.value) || 1));
        return kind + n;
    }

    app.addEventListener('click', (e) => {
        const tr = e.target.closest('tr.wp-row');
        if (tr) return open(tr.dataset.row);
        const r = sel && rowByKey(sel);
        const segBtn = e.target.closest('[data-seg]');
        if (segBtn && r) {
            const v = segBtn.dataset.v;
            if (segBtn.dataset.seg === 'mode') setMode(r, v);
            else if (segBtn.dataset.seg === 'cron_freq') {
                r.cron_freq = v;
                r.cron_days = v === 'daily' ? '1,2,3,4,5' : (v === 'weekly' ? '1' : 'd1');
            }
            return touch(r, true);
        }
        const dayBtn = e.target.closest('[data-dayset]');
        if (dayBtn && r) {
            const d = Number(dayBtn.dataset.d);
            if (dayBtn.dataset.dayset === 'task') {
                if (dayBtn.dataset.single === '1') r.days = [d];
                else r.days = r.days.includes(d) ? r.days.filter(x => x !== d) : [...r.days, d].sort((a, b) => a - b);
            } else {
                if (dayBtn.dataset.single === '1') r.cron_days = String(d);
                else {
                    const cur = (r.cron_days || '').split(',').filter(Boolean).map(Number);
                    r.cron_days = (cur.includes(d) ? cur.filter(x => x !== d) : [...cur, d]).sort((a, b) => a - b).join(',');
                }
            }
            return touch(r, true);
        }
        if (e.target.closest('[data-f=cron_on]') && r) {
            r.cron_on = !r.cron_on;
            if (r.cron_on && !r.cron_freq) {
                r.cron_freq = r.mode === 'day' ? 'daily' : (r.mode === 'week' ? 'weekly' : r.mode);
                r.cron_days = r.cron_freq === 'daily' ? r.days.join(',') : (r.cron_freq === 'weekly' ? String(r.days[0] || 1) : 'd1');
                r.cron_time = r.cron_time || '06:00';
            }
            return touch(r, true);
        }
        if (e.target.closest('[data-del]') && r) {
            if (!r.id) { rows = rows.filter(x => x !== r); sel = null; }
            else if (removed.has(r.key)) removed.delete(r.key);
            else removed.add(r.key);
            markDirty();
            return render();
        }
        if (e.target.closest('[data-cancel]')) {
            if (r && snapshot) Object.assign(r, JSON.parse(JSON.stringify(snapshot)));
            else if (r && !r.id) rows = rows.filter(x => x !== r);
            sel = null;
            snapshot = null;
            return render();
        }
        if (e.target.closest('[data-add]')) {
            const n = newRow();
            open(n.key);
            markDirty();
            app.querySelector('[data-editor] [data-f=title_pl]')?.focus();
            return;
        }
        const pin = e.target.closest('[data-pin]');
        if (pin) {
            const w = wiz(pin.dataset.pin);
            const n = newRow({title_pl: w.label, auto_code: w.code, link_path: w.link, minutes: 30});
            setMode(n, WIZ_MODE[w.code] || 'week');
            if (n.mode === 'monthly') n.month_day = 'wd3';
            markDirty();
            return open(n.key);
        }
        if (e.target.closest('[data-copy]')) return copy();
        if (e.target.closest('[data-save]')) return save(e.target.closest('[data-save]'));
    });

    app.addEventListener('keydown', (e) => {
        const tr = e.target.closest('tr.wp-row');
        if (tr && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); open(tr.dataset.row); }
    });

    app.addEventListener('input', (e) => {
        const r = sel && rowByKey(sel);
        const f = e.target.dataset.f;
        if (!r) return;
        if (f === 'title_pl') { r.title_pl = e.target.value; app.querySelector('.wp-ed-t').textContent = r.title_pl || 'Nowe zadanie'; }
        else if (f === 'minutes') r.minutes = e.target.value === '' ? null : Number(e.target.value);
        else if (f === 'link_custom') r.link_path = e.target.value.trim() || null;
        else if (f === 'escalate_days') r.escalate_days = e.target.value === '' ? null : Number(e.target.value);
        else if (e.target.dataset.ruleN) { if (e.target.dataset.ruleN === 'month') r.month_day = readRule('month'); else r.cron_days = readRule('cron'); }
        else return;
        e.target.classList.remove('is-invalid');
        markDirty();
        refresh();
    });

    app.addEventListener('change', (e) => {
        const r = sel && rowByKey(sel);
        const f = e.target.dataset.f;
        if (!r) return;
        if (f === 'category_id') r.category_id = e.target.value === '' ? null : Number(e.target.value);
        else if (f === 'wizard') {
            const w = wiz(e.target.value);
            r.auto_code = w ? w.code : null;
            r.link_path = w ? w.link : (r.link_path && wiz(P.link_auto[r.link_path]) ? null : r.link_path);
            r.custom = false;
            if (w && !r.title_pl.trim()) r.title_pl = w.label;
            return touch(r, true);
        } else if (f === 'link') {
            if (e.target.value === '__custom') { r.custom = true; r.link_path = null; }
            else { r.custom = false; r.link_path = e.target.value || null; }
            return touch(r, true);
        } else if (f === 'due_time') r.due_time = e.target.value || null;
        else if (f === 'cron_time') r.cron_time = e.target.value || null;
        else if (f === 'cron_action') r.cron_action = e.target.value || null;
        else if (f === 'cron_then') r.cron_then = e.target.value;
        else if (f === 'remind_on') { r.remind_time = e.target.checked ? (app.querySelector('[data-f=remind_time]').value || '09:00') : null; return touch(r, true); }
        else if (f === 'remind_time') r.remind_time = e.target.value || null;
        else if (f === 'escalate_on') { r.escalate_days = e.target.checked ? (Number(app.querySelector('[data-f=escalate_days]').value) || 2) : null; return touch(r, true); }
        else if (e.target.dataset.ruleKind) {
            const name = e.target.dataset.ruleKind;
            const nEl = app.querySelector(`[data-rule-n="${name}"]`);
            if (nEl) { nEl.disabled = e.target.value === 'last'; if (e.target.value !== 'last' && !nEl.value) nEl.value = name === 'month' ? 3 : 1; }
            if (name === 'month') r.month_day = readRule('month'); else r.cron_days = readRule('cron') || 'd1';
        } else return;
        markDirty();
        refresh();
    });

    function invalid(r, selector, message) {
        if (sel !== r.key) open(r.key);
        const el = app.querySelector(`[data-editor] ${selector}`);
        el?.classList.add('is-invalid');
        el?.focus();
        at.notify(false, `« ${r.title_pl.trim() || 'Nowe zadanie'} » : ${message}`);
        return false;
    }
    const TIME = /^([01]\d|2[0-3]):[0-5]\d$/;
    function check() {
        for (const r of rows) {
            if (removed.has(r.key)) continue;
            if (!r.title_pl.trim()) return invalid(r, '[data-f=title_pl]', 'podaj nazwę zadania.');
            if (r.period === 'week' && !r.days.length) return invalid(r, '[data-dayset=task]', 'wybierz dzień.');
            if (r.minutes !== null && (r.minutes < 0 || r.minutes > P.max_minutes || !Number.isInteger(r.minutes))) return invalid(r, '[data-f=minutes]', `minuty od 0 do ${P.max_minutes}.`);
            if (r.custom && r.link_path && !/^\/[A-Za-z0-9/_\-?=&.%#]*$/.test(r.link_path)) return invalid(r, '[data-f=link_custom]', 'link musi być ścieżką ERP, np. /purchasing.');
            if (r.due_time && !TIME.test(r.due_time)) return invalid(r, '[data-f=due_time]', 'termin w formacie GG:MM.');
            if (r.escalate_days !== null && (r.escalate_days < 1 || r.escalate_days > 30)) return invalid(r, '[data-f=escalate_days]', 'przełożony po 1–30 dniach.');
            if (r.cron_on) {
                if (!r.cron_freq) return invalid(r, '[data-seg=cron_freq]', 'automatycznie — wybierz « Kiedy ».');
                if (r.cron_freq === 'daily' && !(r.cron_days || '').split(',').filter(Boolean).length) return invalid(r, '[data-dayset=cron]', 'automatycznie — wybierz dni.');
                if (!r.cron_time || !TIME.test(r.cron_time)) return invalid(r, '[data-f=cron_time]', 'automatycznie — podaj godzinę.');
                if (!r.cron_action) return invalid(r, '[data-f=cron_action]', 'automatycznie — wybierz, co robi.');
            }
        }
        return true;
    }

    async function save(button) {
        if (!check()) return;
        button.disabled = true;
        const res = await at.send(`/ajax/organization/week-plan/${positionId}`, 'PUT', {
            tasks: rows.filter(r => !removed.has(r.key)).map(r => ({id: r.id, title_pl: r.title_pl.trim(), category_id: r.category_id, period: r.period, days: r.days,
                minutes: r.minutes, link_path: r.link_path, auto_code: r.auto_code, due_time: r.due_time, month_day: r.period === 'week' ? null : r.month_day,
                cron_on: !!r.cron_on, cron_freq: r.cron_freq, cron_days: r.cron_days, cron_time: r.cron_time, cron_action: r.cron_action, cron_then: r.cron_then || 'wait',
                remind_time: r.remind_time, escalate_days: r.escalate_days})),
            removed: rows.filter(r => removed.has(r.key) && r.id).map(r => r.id),
        });
        button.disabled = false;
        at.notify(res.ok, res.message || (res.ok ? 'Zapisano.' : 'Błąd zapisu.'));
        if (res.ok && res.data) {
            // nowe zadanie : po zapisie panel zostaje na tym samym zadaniu (po nazwie)
            const title = sel && rowByKey(sel)?.title_pl.trim();
            sel = null;
            load(res.data);
            const again = title && rows.find(r => r.title_pl === title);
            if (again) { sel = again.key; refresh(); refreshEditor(); }
        }
    }

    async function copy() {
        const options = Object.fromEntries(P.others.map(o => [o.id, `${o.name} (${o.tasks})`]));
        const pick = window.Swal
            ? (await Swal.fire({title: 'Kopiuj z innego stanowiska', text: `Zadania stanowiska zostaną dodane do planu${dirty ? ' (niezapisane zmiany zostaną utracone)' : ''} ; te o tej samej nazwie są pomijane.`,
                input: 'select', inputOptions: options, showCancelButton: true, confirmButtonText: 'Kopiuj', cancelButtonText: 'Anuluj'})).value
            : window.prompt('Numer stanowiska do skopiowania : ' + Object.entries(options).map(([k, v]) => `${k} = ${v}`).join(', '));
        if (!pick) return;
        const res = await at.send(`/ajax/organization/week-plan/${positionId}/copy`, 'POST', {from: Number(pick)});
        at.notify(res.ok, res.message || (res.ok ? 'Skopiowano.' : 'Błąd.'));
        if (res.ok && res.data) load(res.data);
    }

    window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
    load(P);
})();
