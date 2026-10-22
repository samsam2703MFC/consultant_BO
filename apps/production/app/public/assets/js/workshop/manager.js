/*
 * Kierownik Produkcji i Rozwoju Produktu / Responsable production & développement produits : fiche de poste
 * (français puis polonais), objectifs et paramètres, suivi mensuel, prime du trimestre, préparation d'entretien
 * et audit des compétences. Les chiffres viennent de l'API (GET /manager/…), jamais du navigateur ; les textes
 * des deux langues de resources/lang/ui/{pl,fr}/manager.json (impression : français puis polonais).
 */
(async function () {
    'use strict';
    const el = document.getElementById('tfbManager');
    if (!el) return;
    const {WorkshopApi, ui} = window.TfbWorkshop;
    const api = new WorkshopApi(el);
    const esc = ui.esc;
    try {
        await api.init();
    } catch (e) {
        el.innerHTML = `<div class="alert alert-danger">${esc(e.message)}</div>`;
        return;
    }
    const TABS = ['job', 'settings', 'report', 'bonus', 'interview', 'audit'];
    const L = {pl: null, fr: null};
    async function loadLabels() {
        L[api.lang] = api.labels;
        for (const lang of ['pl', 'fr']) {
            if (L[lang]) continue;
            try {
                const r = await fetch(`${api.root}/resources/lang/ui/${lang}/manager.json`, {credentials: 'same-origin'});
                L[lang] = r.ok ? await r.json() : {};
            } catch (e) { L[lang] = {}; }
        }
    }
    const tr = (lang, key, params) => {
        let s = (L[lang] || {})[key];
        if (s === undefined || s === null) s = key;
        if (typeof s !== 'string') return s;
        for (const [k, v] of Object.entries(params || {})) s = s.split('{' + k + '}').join(String(v ?? '—'));
        return s;
    };
    const t = (k, p) => tr(api.lang, k, p);
    const list = (lang, key) => Array.isArray((L[lang] || {})[key]) ? L[lang][key] : [];
    const n = (v, d, lang = api.lang) => v === null || v === undefined ? '—' : ui.fmt(v, d, lang);
    const money = (lang, v, cur) => v === null || v === undefined ? '—' : `${n(v, 0, lang)} ${cur || 'PLN'}`;
    const pct = (lang, a) => a === null || a === undefined ? '—' : n(a * 100, 1, lang) + ' %';
    function val(lang, unit, v) {
        if (v === null || v === undefined) return '—';
        if (unit === '%') return n(v, 1, lang) + ' %';
        if (['days', 'eur_carton', 'hours_month', 'count_month'].includes(unit)) return tr(lang, 'unit.' + unit, {n: n(v, unit === 'eur_carton' ? 2 : 1, lang)});
        return n(v, 2, lang);
    }
    const today = new Date();
    const ym = (d) => d.toISOString().slice(0, 7);
    const lastMonth = ym(new Date(today.getFullYear(), today.getMonth() - 1, 15));
    const thisQuarter = `${today.getFullYear()}-Q${Math.floor(today.getMonth() / 3) + 1}`;
    let state = readHash();
    const data = {plan: null, report: null, bonus: null, interview: null, audits: null};

    function readHash() {
        const p = new URLSearchParams(location.hash.replace(/^#/, ''));
        return {
            tab: TABS.includes(p.get('tab')) ? p.get('tab') : 'job',
            year: Number(p.get('year')) || today.getFullYear(),
            month: /^\d{4}-\d{2}$/.test(p.get('month') || '') ? p.get('month') : lastMonth,
            quarter: /^\d{4}-Q[1-4]$/.test(p.get('quarter') || '') ? p.get('quarter') : thisQuarter,
        };
    }
    function writeHash() {
        history.replaceState(null, '', '#' + new URLSearchParams({tab: state.tab, year: state.year, month: state.month, quarter: state.quarter}).toString());
    }
    const errText = (err) => ((err.body && err.body.errors) || []).map(x => x.message).join(' ') || err.message;

    // --- Chargement ---------------------------------------------------------------------------------------------
    async function load() {
        el.innerHTML = shell(`<p class="text-muted">${esc(t('loading'))}</p>`);
        bindShell();
        try {
            await loadLabels();
            if (!data.plan || data.plan.year !== state.year) data.plan = await api.get(`/manager/plan?year=${state.year}`);
            if (state.tab === 'report') data.report = await api.get(`/manager/report?month=${state.month}`);
            if (state.tab === 'bonus') data.bonus = await api.get(`/manager/bonus?period=${state.quarter}`);
            if (state.tab === 'interview') data.interview = await api.get(`/manager/interview?month=${state.month}`);
            if (state.tab === 'audit' || state.tab === 'interview') data.audits = await api.get('/manager/audits');
        } catch (err) {
            el.innerHTML = shell(`<div class="alert alert-danger">${esc(errText(err))}</div>`);
            bindShell();
            return;
        }
        writeHash();
        draw();
    }

    function shell(content) {
        return `<div class="tfb-head"><div><h3>${esc(t('title'))}</h3><p class="text-muted mb-0">${esc(t('subtitle'))}</p></div>
            <div class="tfb-head-actions"><button type="button" class="btn btn-outline-primary btn-sm" data-print><i class="bi bi-printer"></i> ${esc(t('print_bilingual'))}</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-lang-switch>${esc(t('lang_switch'))}</button></div></div>
            <ul class="nav nav-tabs tfb-mgr-tabs" role="tablist">${TABS.map(x => `<li class="nav-item"><a class="nav-link ${state.tab === x ? 'active' : ''}" href="#tab=${x}" data-tab="${x}" role="tab">${esc(t('tab.' + x))}</a></li>`).join('')}</ul>
            <div class="tfb-mgr-body">${content}</div><div class="tfb-mgr-print" data-print-area></div>`;
    }

    function draw() {
        const views = {job: jobView, settings: settingsView, report: reportView, bonus: bonusView, interview: interviewView, audit: auditView};
        el.innerHTML = shell(views[state.tab]());
        bindShell();
        bindTab();
    }

    function bindShell() {
        el.querySelectorAll('[data-tab]').forEach(a => a.addEventListener('click', (e) => { e.preventDefault(); state.tab = a.dataset.tab; load(); }));
        el.querySelector('[data-lang-switch]')?.addEventListener('click', async () => { await api.setLang(api.lang === 'pl' ? 'fr' : 'pl'); L[api.lang] = api.labels; draw(); });
        el.querySelector('[data-print]')?.addEventListener('click', printBilingual);
    }

    // Impression : le document de l'onglet en français, puis en polonais.
    function printBilingual() {
        const docs = {job: jobDoc, settings: jobDoc, report: (l) => reportDoc(l, data.report), bonus: (l) => bonusDoc(l, data.bonus),
            interview: (l) => interviewDoc(l, data.interview), audit: (l) => auditDoc(l, (data.audits?.audits || [])[0] || null)};
        const area = el.querySelector('[data-print-area]');
        area.innerHTML = docs[state.tab]('fr') + '<div class="tfb-mgr-pagebreak"></div>' + docs[state.tab]('pl');
        document.body.classList.add('tfb-mgr-printing');
        const done = () => { document.body.classList.remove('tfb-mgr-printing'); area.innerHTML = ''; window.removeEventListener('afterprint', done); };
        window.addEventListener('afterprint', done);
        window.print();
    }

    // --- Fiche de poste -----------------------------------------------------------------------------------------
    function objectiveText(lang, code) {
        const ind = data.plan.indicators.find(i => i.code === code);
        if (!ind || !ind.validated) return tr(lang, 'job.objective_missing');
        const sign = ind.direction === 'higher' ? '≥ ' : '≤ ';
        return sign + val(lang, ind.unit, ind.validated.target);
    }
    function envelopeText(lang) {
        const p = data.plan.plan;
        if (p.envelope_annual === null) return tr(lang, 'job.envelope_missing', {pct: n(p.envelope_pct, 0, lang)});
        return tr(lang, 'job.envelope', {amount: money(lang, p.envelope_annual, p.currency), pct: n(p.envelope_pct, 0, lang), salary: money(lang, p.salary_monthly * 12, p.currency)});
    }
    function jobDoc(lang) {
        const d = data.plan;
        const p = d.plan;
        const T = (k, pr) => tr(lang, k, pr);
        const missing = T('job.to_fill');
        const chars = [
            ['job.c_workplace', p.workplace || T('job.workplace_default')],
            ['job.c_reports_to', T('job.reports_to_value')],
            ['job.c_team', p.team_organisation || missing],
            ['job.c_contract', p.contract_type || missing],
            ['job.c_time', (p.working_time || T('job.working_time_default')) + (p.monthly_hours ? ' · ' + T('job.hours_month', {h: n(p.monthly_hours, 0, lang)}) : '')],
            ['job.c_pay', p.salary_monthly === null ? missing : T('job.pay_value', {fixed: money(lang, p.salary_monthly, p.currency),
                variable: money(lang, p.salary_monthly * p.envelope_pct / 100, p.currency), variable_cap: money(lang, p.monthly_at_cap - p.salary_monthly, p.currency), pct: n(p.envelope_pct, 0, lang),
                total: money(lang, p.monthly_at_target, p.currency), cap: money(lang, p.monthly_at_cap, p.currency)})],
            ['job.c_start', p.holder_start || missing],
            ['job.c_languages', T('job.languages_value')],
        ];
        const rows = d.sections.map(s => `<tr><td>${esc(T('section.' + s.code))}</td><td>${s.indicators.map(i => esc(T('ind.' + i))).join('<br>')}</td>
            <td>${s.indicators.map(i => esc(objectiveText(lang, i))).join('<br>')}</td><td class="text-end">${s.weight} %</td><td>${esc(T(s.months === 6 ? 'job.semiannual' : 'job.quarterly'))}</td></tr>`).join('');
        return `<article class="tfb-mgr-doc" lang="${lang}">
            <header class="tfb-mgr-doc-head"><div class="small text-muted">${esc(T('job.doc_title'))} · L'Atelier By · ${esc(T('lang_name'))}</div>
                <h2>${esc(T('job.position'))}</h2><div class="small text-muted">${esc(T('job.position_other'))}</div></header>
            <h4>${esc(T('job.characteristics'))}</h4>
            <dl class="tfb-mgr-chars">${chars.map(([k, v]) => `<dt>${esc(T(k))}</dt><dd>${esc(v)}</dd>`).join('')}</dl>
            <h4>${esc(T('job.context_title'))}</h4><p>${esc(T('job.context', {team: p.team_organisation || missing}))}</p>
            <h4>${esc(T('job.mission_title'))}</h4><p>${esc(T('job.mission'))}</p>
            <h4>${esc(T('job.sections_title'))} <small class="text-muted">${esc(T('job.hours_total', {h: n(p.mission_hours_total, 0, lang), month: n(p.monthly_hours, 0, lang)}))}</small></h4>
            ${d.missions.map((m, i) => `<section class="tfb-mgr-mission"><h5><span>${i + 1}. ${esc(T('mission.' + m))}</span>
                <span class="tfb-mgr-hours">${p.mission_hours[m] === null ? '—' : esc(T('job.hours_month', {h: n(p.mission_hours[m], 0, lang)}))}</span></h5>
                <ul>${list(lang, 'mission.' + m + '.items').map(x => `<li>${esc(x.replace('{auditor}', p.auditor || missing))}</li>`).join('')}</ul></section>`).join('')}
            <h4>${esc(T('job.skills_title'))}</h4><ul>${list(lang, 'job.skills').map(x => `<li>${esc(x)}</li>`).join('')}</ul>
            <h4>${esc(T('job.kpi_title'))}</h4><p>${esc(envelopeText(lang))}</p>
            <table class="table table-sm tfb-mgr-table"><thead><tr><th>${esc(T('col.section'))}</th><th>${esc(T('col.indicators'))}</th><th>${esc(T('col.objectives'))}</th>
                <th class="text-end">${esc(T('col.weight'))}</th><th>${esc(T('col.payment'))}</th></tr></thead><tbody>${rows}</tbody></table>
            <h4>${esc(T('job.rules_title'))}</h4>
            <ul>${list(lang, 'job.rules').map(x => `<li>${esc(x.replace('{threshold}', p.audit_threshold === null ? missing : n(p.audit_threshold, 0, lang) + ' %'))}</li>`).join('')}</ul>
        </article>`;
    }
    function jobView() {
        return `<p class="small text-muted">${esc(t('job.screen_note'))}</p>${jobDoc('fr')}<hr class="tfb-mgr-sep">${jobDoc('pl')}`;
    }

    // --- Objectifs et paramètres --------------------------------------------------------------------------------
    function settingsView() {
        const d = data.plan;
        const p = d.plan;
        const dis = d.can_write ? '' : 'disabled';
        const input = (field, type = 'text', value = p[field], attrs = '') => `<input class="form-control form-control-sm" type="${type}" data-plan="${field}" value="${esc(value ?? '')}" ${dis} ${attrs}>`;
        const field = (key, html, help = '') => `<div class="col-md-4"><label class="form-label small mb-1">${esc(t(key))}</label>${html}${help ? `<div class="form-text">${esc(help)}</div>` : ''}</div>`;
        const users = `<select class="form-select form-select-sm" data-plan="holder_user_id" ${dis}><option value="">—</option>${d.users.map(u => `<option value="${u.id}" ${p.holder_user_id === u.id ? 'selected' : ''}>${esc(u.name)}</option>`).join('')}</select>`;
        const hours = d.missions.map(m => `<div class="col-6 col-md-2"><label class="form-label small mb-1">${esc(t('mission.' + m))}</label>
            <div class="input-group input-group-sm"><input class="form-control" inputmode="decimal" data-hours="${m}" value="${esc(p.mission_hours[m] ?? '')}" ${dis}><span class="input-group-text">h</span></div></div>`).join('');
        const sectionOf = Object.fromEntries(d.sections.flatMap(s => s.indicators.map(i => [i, s.code])));
        let last = null;
        const objectives = d.indicators.map(i => {
            const head = sectionOf[i.code] !== last ? `<tr class="tfb-week-table-group"><th colspan="7">${esc(t('section.' + sectionOf[i.code]))} · ${d.sections.find(s => s.code === sectionOf[i.code]).weight} %</th></tr>` : '';
            last = sectionOf[i.code];
            const current = i.draft ?? i.validated?.target ?? i.default ?? '';
            return head + `<tr><td><strong>${esc(t('ind.' + i.code))}</strong><div class="small text-muted">${esc(t('ind.' + i.code + '.how'))}</div></td>
                <td class="text-center" title="${esc(t('direction.' + i.direction))}">${i.direction === 'higher' ? '↑' : '↓'}</td>
                <td class="text-end">${i.hint === null ? `<span class="text-muted small">${esc(t('missing.' + i.hint_missing))}</span>` : esc(val(api.lang, i.unit, i.hint))}</td>
                <td class="text-end">${i.validated ? `${esc(val(api.lang, i.unit, i.validated.target))}<div class="small text-muted">${esc(i.validated.validated_at.slice(0, 10))}</div>` : `<span class="badge bg-warning text-dark">${esc(t('obj.none'))}</span>`}</td>
                <td><input class="form-control form-control-sm tfb-mgr-num" inputmode="decimal" data-objective="${i.code}" value="${esc(current)}" ${dis} aria-label="${esc(t('ind.' + i.code))}"></td>
                <td class="small">${esc(t('unit_name.' + i.unit))}</td>
                <td>${i.draft !== null ? `<span class="badge bg-info text-dark">${esc(t('obj.draft'))}</span>` : ''}${i.source !== 'erp' ? ` <span class="badge bg-light text-dark">${esc(t('source.' + i.source))}</span>` : ''}</td></tr>`;
        }).join('');
        return `<div class="card"><div class="card-body">
            <div class="d-flex align-items-center gap-2 mb-3"><h5 class="mb-0">${esc(t('settings.plan_title'))}</h5>
                <select class="form-select form-select-sm w-auto" data-year>${[today.getFullYear() - 1, today.getFullYear(), today.getFullYear() + 1].map(y => `<option ${y === state.year ? 'selected' : ''}>${y}</option>`).join('')}</select>
                ${d.can_write ? '' : `<span class="small text-muted">${esc(t('settings.read_only'))}</span>`}</div>
            <div class="row g-2">
                ${field('plan.holder_user_id', users, t('plan.holder_user_id_help'))}${field('plan.holder_name', input('holder_name'))}${field('plan.holder_start', input('holder_start', 'date'))}
                ${field('plan.salary_monthly', `<div class="input-group input-group-sm">${input('salary_monthly', 'text', p.salary_monthly, 'inputmode="decimal"')}<span class="input-group-text">${esc(p.currency)}</span></div>`,
                    p.salary_monthly === null ? '' : t('plan.salary_help', {target: money(api.lang, p.monthly_at_target, p.currency), cap: money(api.lang, p.monthly_at_cap, p.currency), envelope: money(api.lang, p.envelope_annual, p.currency)}))}
                ${field('plan.envelope_pct', `<div class="input-group input-group-sm">${input('envelope_pct', 'text', p.envelope_pct, 'inputmode="decimal"')}<span class="input-group-text">%</span></div>`)}
                ${field('plan.currency', input('currency'))}
                ${field('plan.margin_threshold_pct', `<div class="input-group input-group-sm">${input('margin_threshold_pct', 'text', p.margin_threshold_pct, 'inputmode="decimal"')}<span class="input-group-text">%</span></div>`, t('plan.margin_threshold_help'))}
                ${field('plan.audit_threshold', `<div class="input-group input-group-sm">${input('audit_threshold', 'text', p.audit_threshold, 'inputmode="decimal"')}<span class="input-group-text">%</span></div>`, t('plan.audit_threshold_help'))}
                ${field('plan.price_reference_date', input('price_reference_date', 'date'), t('plan.price_reference_help'))}
                ${field('plan.auditor', input('auditor'))}${field('plan.workplace', input('workplace', 'text', p.workplace, `placeholder="${esc(t('job.workplace_default'))}"`))}
                ${field('plan.contract_type', input('contract_type'))}${field('plan.working_time', input('working_time', 'text', p.working_time, `placeholder="${esc(t('job.working_time_default'))}"`))}
                ${field('plan.monthly_hours', `<div class="input-group input-group-sm">${input('monthly_hours', 'text', p.monthly_hours, 'inputmode="decimal"')}<span class="input-group-text">h</span></div>`)}
                <div class="col-12"><label class="form-label small mb-1">${esc(t('plan.team_organisation'))}</label><textarea class="form-control form-control-sm" rows="2" data-plan="team_organisation" ${dis}>${esc(p.team_organisation ?? '')}</textarea></div>
            </div>
            <h6 class="mt-3 mb-1">${esc(t('plan.mission_hours'))} <span class="small text-muted" data-hours-total>${esc(t('plan.hours_sum', {sum: n(p.mission_hours_total, 0), month: n(p.monthly_hours, 0)}))}</span>
                ${p.mission_hours_default ? `<span class="badge bg-light text-dark">${esc(t('plan.hours_default'))}</span>` : ''}</h6>
            <div class="row g-2">${hours}</div>
            ${d.can_write ? `<button type="button" class="btn btn-primary btn-sm mt-3" data-save-plan>${esc(t('save'))}</button>` : ''}
        </div></div>
        <div class="card mt-3"><div class="card-body">
            <h5>${esc(t('settings.objectives_title', {year: state.year}))}</h5>
            <p class="small text-muted mb-2">${esc(t('settings.objectives_help', {from: d.hint_period.from, to: d.hint_period.to}))}</p>
            <div class="table-responsive"><table class="table table-sm align-middle tfb-week-table tfb-mgr-table">
                <thead><tr><th>${esc(t('col.indicator'))}</th><th></th><th class="text-end">${esc(t('col.hint'))}</th><th class="text-end">${esc(t('col.validated'))}</th>
                    <th>${esc(t('col.new_target'))}</th><th>${esc(t('col.unit'))}</th><th></th></tr></thead><tbody>${objectives}</tbody></table></div>
            ${d.can_write ? `<div class="d-flex gap-2 flex-wrap"><button type="button" class="btn btn-outline-primary btn-sm" data-save-objectives>${esc(t('settings.save_drafts'))}</button>
                <button type="button" class="btn btn-primary btn-sm" data-validate ${d.drafts ? '' : 'disabled'}>${esc(t('settings.validate', {n: d.drafts}))}</button></div>
                <p class="small text-muted mt-2 mb-0">${esc(t('settings.validate_help'))}</p>` : ''}
        </div></div>`;
    }

    // --- Suivi mensuel ------------------------------------------------------------------------------------------
    function alertText(lang, a) {
        const T = (k, p) => tr(lang, k, p);
        if (a.level === 'haccp') return T('alert.' + a.code, {date: a.date, authority: a.authority || '—', score: n(a.score, 0, lang), threshold: n(a.threshold, 0, lang)});
        return T('alert.BELOW_90', {indicator: T('ind.' + a.indicator), attainment: pct(lang, a.attainment)});
    }
    function missingText(lang, m) {
        const T = (k, p) => tr(lang, k, p);
        const ind = m.indicator ? T('ind.' + m.indicator) : '';
        if (m.code === 'NO_DATA') return T('gap.NO_DATA', {indicator: ind, reason: T('missing.' + m.reason)});
        return T('gap.' + m.code, {indicator: ind, product: m.product || '', from: m.from || '', id: m.event_id || ''});
    }
    const dot = (status) => status ? `<span class="tfb-mgr-dot is-${status}" aria-hidden="true"></span>` : '<span class="tfb-mgr-dot"></span>';
    function originText(lang, r) {
        if (r.origin === null) return '';
        return tr(lang, r.origin === 'manual' ? (r.unverified ? 'origin.unverified' : 'origin.manual') : 'origin.erp');
    }
    function indicatorTable(lang, rows, sections) {
        const T = (k, p) => tr(lang, k, p);
        let last = null;
        return `<table class="table table-sm align-middle tfb-week-table tfb-mgr-table"><thead><tr><th>${esc(T('col.indicator'))}</th><th class="text-end">${esc(T('col.objective'))}</th>
            <th class="text-end">${esc(T('col.realized'))}</th><th class="text-end">${esc(T('col.gap'))}</th><th class="text-end">${esc(T('col.attainment'))}</th><th>${esc(T('col.status'))}</th></tr></thead><tbody>
            ${rows.map(r => {
                const head = r.section !== last ? `<tr class="tfb-week-table-group"><th colspan="6">${esc(T('section.' + r.section))}${sections ? ' · ' + sections[r.section] + ' %' : ''}</th></tr>` : '';
                last = r.section;
                const sign = r.direction === 'higher' ? '≥ ' : '≤ ';
                return head + `<tr><td>${esc(T('ind.' + r.code))}${(r.flags || []).length ? ` <span class="badge bg-warning text-dark" title="${esc(r.flags.map(f => T('gap.' + f, {indicator: ''})).join(' '))}">!</span>` : ''}</td>
                    <td class="text-end">${r.target === null ? `<span class="text-muted">${esc(T('obj.none'))}</span>` : esc(sign + val(lang, r.unit, r.target))}</td>
                    <td class="text-end">${r.value === null ? `<span class="text-muted small">${esc(T('missing.' + r.missing))}</span>` : `<strong>${esc(val(lang, r.unit, r.value))}</strong>`}
                        <div class="small text-muted">${esc(originText(lang, r))}</div></td>
                    <td class="text-end">${r.gap === null || r.gap === undefined ? '—' : esc((r.gap > 0 ? '+' : '') + val(lang, r.unit, r.gap))}</td>
                    <td class="text-end">${esc(pct(lang, r.attainment))}</td><td>${dot(r.status)} ${r.status ? esc(T('status.' + r.status)) : ''}</td></tr>`;
            }).join('')}</tbody></table>`;
    }
    function productsTable(lang, products) {
        const T = (k, p) => tr(lang, k, p);
        if (!products.length) return `<p class="text-muted">${esc(T('report.no_products'))}</p>`;
        return `<table class="table table-sm align-middle tfb-mgr-table"><thead><tr><th>${esc(T('col.product'))}</th><th>${esc(T('col.launch'))}</th>
            <th class="text-end">${esc(T('col.margin'))}</th><th class="text-end">${esc(T('col.volume'))}</th><th>${esc(T('col.recommendation'))}</th><th>${esc(T('col.decision'))}</th></tr></thead><tbody>
            ${products.map(p => `<tr><td>${esc(p.name)}</td><td>${esc(p.launch_date)}<div class="small text-muted">${esc(T('report.weeks', {n: p.weeks}))}${p.launch_from_erp ? ' · ' + esc(T('report.launch_from_erp')) : ''}</div></td>
                <td class="text-end">${esc(p.margin_pct === null ? '—' : n(p.margin_pct, 1, lang) + ' %')}<div class="small text-muted">${esc(T('report.min', {v: p.min_margin_pct === null ? '—' : n(p.min_margin_pct, 1, lang) + ' %'}))}</div></td>
                <td class="text-end">${esc(n(p.per_shop_week, 1, lang))}<div class="small text-muted">${esc(T('report.expected', {v: p.expected_week_shop === null ? '—' : n(p.expected_week_shop, 1, lang), shops: p.shops}))}</div></td>
                <td><span class="tfb-mgr-reco is-${p.recommendation}">${esc(T('reco.' + p.recommendation))}</span></td><td>${p.decision ? esc(T('decision.' + p.decision)) : '—'}${p.decision_note ? `<div class="small text-muted">${esc(p.decision_note)}</div>` : ''}</td></tr>`).join('')}
            </tbody></table>`;
    }
    function actionText(lang, a) {
        if (a.text) return a.text;
        return tr(lang, 'action.suggest_' + (a.kind || 'gap'), {indicator: tr(lang, 'ind.' + a.indicator)});
    }
    function reportDoc(lang, r) {
        if (!r) return '';
        const T = (k, p) => tr(lang, k, p);
        const sections = Object.fromEntries(data.plan.sections.map(s => [s.code, s.weight]));
        return `<article class="tfb-mgr-doc" lang="${lang}">
            <header class="tfb-mgr-doc-head"><div class="small text-muted">${esc(T('report.doc_title'))} · ${esc(T('job.position'))} · ${esc(T('lang_name'))}</div>
                <h2>${esc(T('report.title', {month: r.month}))}</h2>
                <div class="small text-muted">${esc(T('report.period', {from: r.from, to: r.to, extracted: r.extracted_at}))}${r.partial ? ' · ' + esc(T('report.partial')) : ''}</div></header>
            <h4>1. ${esc(T('report.alerts'))}</h4>
            ${r.alerts.length ? `<ul class="tfb-mgr-alerts">${r.alerts.map(a => `<li class="is-${a.level}">${esc(alertText(lang, a))}</li>`).join('')}</ul>` : `<p class="text-success">${esc(T('report.no_alerts'))}</p>`}
            ${r.haccp.events.length ? `<p class="small mb-1"><strong>${esc(T('report.haccp_events'))}</strong></p><ul class="small">${r.haccp.events.map(e => `<li>${esc(haccpText(lang, e))}</li>`).join('')}</ul>` : ''}
            <h4>2. ${esc(T('report.indicators'))}</h4>${indicatorTable(lang, r.indicators, sections)}
            <h4>3. ${esc(T('report.products'))}</h4>${productsTable(lang, r.products)}
            <h4>4. ${esc(T('report.missing'))}</h4>
            ${r.missing.length ? `<ul class="small">${r.missing.map(m => `<li>${esc(missingText(lang, m))}</li>`).join('')}</ul>` : `<p>${esc(T('report.nothing_missing'))}</p>`}
            <h4>5. ${esc(T('report.actions'))}</h4>
            ${r.actions.length ? `<table class="table table-sm tfb-mgr-table"><thead><tr><th>#</th><th>${esc(T('col.action'))}</th><th>${esc(T('col.owner'))}</th><th>${esc(T('col.due'))}</th></tr></thead><tbody>
                ${r.actions.map((a, i) => `<tr><td>${i + 1}</td><td>${esc(actionText(lang, a))}${r.actions_suggested ? ` <span class="badge bg-light text-dark">${esc(T('action.suggested'))}</span>` : ''}</td><td>${esc(a.owner || '—')}</td><td>${esc(a.due_date || '—')}</td></tr>`).join('')}</tbody></table>`
                : `<p class="text-muted">${esc(T('report.no_actions'))}</p>`}
        </article>`;
    }
    function haccpText(lang, e) {
        const T = (k, p) => tr(lang, k, p);
        const parts = [e.event_date, T('haccp.' + e.kind)];
        if (e.authority) parts.push(e.authority);
        if (e.result) parts.push(T('haccp.result.' + e.result));
        if (e.score !== null) parts.push(T('haccp.score', {score: n(e.score, 0, lang)}));
        if (e.kind === 'cold_chain_break') parts.push(T(e.treated ? 'haccp.treated' : 'haccp.untreated'));
        if (e.description) parts.push(e.description);
        if (e.unverified) parts.push(T('origin.unverified'));
        return parts.join(' · ');
    }
    function reportView() {
        const r = data.report;
        const editable = r.can_report;
        const manualRows = r.indicators.filter(x => ['stockouts', 'loss_pct', 'overtime_hours'].includes(x.code));
        const kinds = ['official_control', 'cold_chain_break', 'independent_audit', 'self_check'];
        const actions = [0, 1, 2].map(i => r.actions[i] || {text: '', owner: data.plan.plan.holder_name || '', due_date: '', status: 'open'});
        return `<div class="card tfb-sheet-bar"><div class="card-body d-flex flex-wrap gap-2 align-items-center">
                <label class="small">${esc(t('report.month'))}</label><input type="month" class="form-control form-control-sm w-auto" data-month value="${esc(state.month)}" max="${ym(today)}">
                ${r.plan.exists ? '' : `<span class="badge bg-warning text-dark">${esc(t('gap.NO_PLAN'))}</span>`}</div></div>
            <div class="card"><div class="card-body">${reportDoc(api.lang, r)}</div></div>
            ${editable ? `<div class="card mt-3"><div class="card-body">
                <h5>${esc(t('report.entries_title'))}</h5><p class="small text-muted">${esc(t('report.entries_help'))}</p>
                <h6>${esc(t('report.manual_title'))}</h6>
                <div class="row g-2 mb-3">${manualRows.map(x => `<div class="col-md-4"><label class="form-label small mb-1">${esc(t('ind.' + x.code))}${x.origin === 'erp' ? ` · <span class="text-muted">${esc(t('report.from_erp'))}</span>` : ''}</label>
                    <div class="input-group input-group-sm"><input class="form-control" inputmode="decimal" data-value="${x.code}" value="${x.origin === 'manual' ? esc(x.value) : ''}" ${x.origin === 'erp' ? 'disabled' : ''}>
                    <span class="input-group-text">${esc(t('unit_name.' + x.unit))}</span><button type="button" class="btn btn-outline-primary" data-save-value="${x.code}" ${x.origin === 'erp' ? 'disabled' : ''}>${esc(t('save'))}</button></div></div>`).join('')}</div>
                <h6>${esc(t('report.haccp_title'))}</h6>
                ${r.haccp.events.length ? `<ul class="list-unstyled small">${r.haccp.events.map(e => `<li class="mb-1">${esc(haccpText(api.lang, e))}
                    ${e.kind === 'cold_chain_break' && !e.treated ? `<button type="button" class="btn btn-link btn-sm p-0 ms-2" data-treat="${e.id}">${esc(t('haccp.mark_treated'))}</button>` : ''}
                    ${r.can_write ? `<button type="button" class="btn btn-link btn-sm text-danger p-0 ms-2" data-del-haccp="${e.id}">${esc(t('delete'))}</button>` : ''}</li>`).join('')}</ul>` : ''}
                <div class="row g-2 align-items-end mb-3" data-haccp-form>
                    <div class="col-md-2"><label class="form-label small mb-1">${esc(t('haccp.date'))}</label><input type="date" class="form-control form-control-sm" name="event_date" value="${esc(r.to)}"></div>
                    <div class="col-md-2"><label class="form-label small mb-1">${esc(t('haccp.kind'))}</label><select class="form-select form-select-sm" name="kind">${kinds.map(k => `<option value="${k}">${esc(t('haccp.' + k))}</option>`).join('')}</select></div>
                    <div class="col-md-2"><label class="form-label small mb-1">${esc(t('haccp.authority'))}</label><input class="form-control form-control-sm" name="authority" placeholder="Sanepid / AFSCA"></div>
                    <div class="col-md-2"><label class="form-label small mb-1">${esc(t('haccp.result_label'))}</label><select class="form-select form-select-sm" name="result"><option value="">—</option>
                        <option value="favorable">${esc(t('haccp.result.favorable'))}</option><option value="unfavorable">${esc(t('haccp.result.unfavorable'))}</option></select></div>
                    <div class="col-md-1"><label class="form-label small mb-1">${esc(t('haccp.score_label'))}</label><input class="form-control form-control-sm" name="score" inputmode="decimal"></div>
                    <div class="col-md-3"><label class="form-label small mb-1">${esc(t('haccp.description'))}</label><input class="form-control form-control-sm" name="description"></div>
                    <div class="col-12"><button type="button" class="btn btn-outline-primary btn-sm" data-add-haccp>${esc(t('haccp.add'))}</button></div></div>
                <h6>${esc(t('report.launch_title'))}</h6><p class="small text-muted">${esc(t('report.launch_help'))}</p>
                ${r.products.length ? `<div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>${esc(t('col.product'))}</th><th>${esc(t('col.launch'))}</th><th>${esc(t('launch.target_cost'))}</th>
                    <th>${esc(t('launch.target_price'))}</th><th>${esc(t('launch.min_margin'))}</th><th>${esc(t('launch.expected'))}</th><th>${esc(t('col.decision'))}</th><th></th></tr></thead><tbody>
                    ${r.products.map(p => `<tr data-launch="${p.product_id}"><td class="small">${esc(p.name)}</td><td><input type="date" class="form-control form-control-sm" name="launch_date" value="${esc(p.launch_date)}"></td>
                        <td><input class="form-control form-control-sm tfb-mgr-num" name="target_cost" inputmode="decimal" value="${esc(p.target_cost ?? '')}"></td>
                        <td><input class="form-control form-control-sm tfb-mgr-num" name="target_price" inputmode="decimal" value="${esc(p.target_price ?? '')}"></td>
                        <td><input class="form-control form-control-sm tfb-mgr-num" name="min_margin_pct" inputmode="decimal" value="${esc(p.min_margin_pct ?? '')}"></td>
                        <td><input class="form-control form-control-sm tfb-mgr-num" name="expected_week_shop" inputmode="decimal" value="${esc(p.expected_week_shop ?? '')}"></td>
                        <td><select class="form-select form-select-sm" name="decision"><option value="">—</option>${['keep', 'fix', 'stop'].map(x => `<option value="${x}" ${p.decision === x ? 'selected' : ''}>${esc(t('decision.' + x))}</option>`).join('')}</select></td>
                        <td><button type="button" class="btn btn-outline-primary btn-sm" data-save-launch="${p.product_id}">${esc(t('save'))}</button></td></tr>`).join('')}</tbody></table></div>` : `<p class="small text-muted">${esc(t('report.no_products'))}</p>`}
                <h6>${esc(t('report.actions_title'))}</h6>
                <div data-actions>${actions.map((a, i) => `<div class="row g-2 mb-1" data-action-row><div class="col-md-6"><input class="form-control form-control-sm" name="text" maxlength="500" value="${esc(a.text || (r.actions_suggested && r.actions[i] ? actionText(api.lang, r.actions[i]) : ''))}" placeholder="${esc(t('action.placeholder', {n: i + 1}))}"></div>
                    <div class="col-md-3"><input class="form-control form-control-sm" name="owner" value="${esc(a.owner || '')}" placeholder="${esc(t('col.owner'))}"></div>
                    <div class="col-md-2"><input type="date" class="form-control form-control-sm" name="due_date" value="${esc(a.due_date || '')}"></div>
                    <div class="col-md-1"><select class="form-select form-select-sm" name="status"><option value="open" ${a.status !== 'done' ? 'selected' : ''}>${esc(t('action.open'))}</option><option value="done" ${a.status === 'done' ? 'selected' : ''}>${esc(t('action.done'))}</option></select></div></div>`).join('')}</div>
                <button type="button" class="btn btn-primary btn-sm mt-1" data-save-actions>${esc(t('report.save_actions'))}</button>
            </div></div>` : ''}`;
    }

    // --- Prime --------------------------------------------------------------------------------------------------
    function justification(lang, s, b) {
        const T = (k, p) => tr(lang, k, p);
        const name = T('section.' + s.code);
        const months = s.period.key.includes('-H') ? 6 : 3;
        const base = {section: name, from: s.period.from, to: s.period.to};
        let text;
        if (s.status === 'ok') {
            text = T('just.ok', {...base, attainment: pct(lang, s.attainment), coefficient: pct(lang, s.coefficient), amount: money(lang, s.amount, b.plan.currency),
                envelope: money(lang, b.plan.envelope_annual, b.plan.currency), weight: s.weight, months, presence: n(s.presence, 2, lang)});
        } else if (s.status === 'missing_data') {
            text = T('just.missing_data', {...base, indicators: s.indicators.filter(i => i.value === null || i.attainment === null).map(i => T('ind.' + i.code)).join(', ')});
        } else text = T('just.' + s.status, base);
        return text + (s.provisional ? ' ' + T('just.provisional') : '');
    }
    function bonusDoc(lang, b) {
        if (!b) return '';
        const T = (k, p) => tr(lang, k, p);
        const cur = b.plan.currency;
        return `<article class="tfb-mgr-doc" lang="${lang}">
            <header class="tfb-mgr-doc-head"><div class="small text-muted">${esc(T('bonus.doc_title'))} · ${esc(T('job.position'))} · ${esc(T('lang_name'))}</div>
                <h2>${esc(T('bonus.title', {period: b.period}))}</h2><div class="small text-muted">${esc(T('report.period', {from: b.from, to: b.to, extracted: b.extracted_at}))}</div></header>
            <h4>${esc(T('bonus.haccp'))}</h4>
            ${b.haccp.blocked ? `<div class="alert alert-danger py-2"><strong>${esc(T('bonus.haccp_blocked'))}</strong><ul class="mb-0">${b.sections.flatMap(s => s.haccp_blocking).filter((x, i, all) => all.findIndex(y => y.event_id === x.event_id) === i)
                .map(a => `<li>${esc(alertText(lang, {level: 'haccp', ...a}))}</li>`).join('')}</ul></div>` : `<p class="text-success">${esc(T('bonus.haccp_ok'))}</p>`}
            <h4>${esc(T('bonus.sections'))}</h4>
            <table class="table table-sm align-middle tfb-week-table tfb-mgr-table"><thead><tr><th>${esc(T('col.section'))}</th><th>${esc(T('col.indicators'))}</th><th class="text-end">${esc(T('col.attainment'))}</th>
                <th class="text-end">${esc(T('col.coefficient'))}</th><th class="text-end">${esc(T('col.share'))}</th><th class="text-end">${esc(T('col.amount'))}</th></tr></thead><tbody>
                ${b.sections.map(s => `<tr><td><strong>${esc(T('section.' + s.code))}</strong><div class="small text-muted">${esc(s.period.key)} · ${s.weight} %</div></td>
                    <td class="small">${s.indicators.map(i => `${dot(i.status)} ${esc(T('ind.' + i.code))} : ${esc(i.value === null ? T('missing.' + (i.missing || 'NO_SALES')) : val(lang, ind(i.code).unit, i.value))}
                        / ${esc(i.target === null ? T('obj.none') : val(lang, ind(i.code).unit, i.target))} → ${esc(pct(lang, i.attainment))}${i.unverified ? ' · ' + esc(T('origin.unverified')) : ''}`).join('<br>')}</td>
                    <td class="text-end">${esc(pct(lang, s.attainment))}</td><td class="text-end">${esc(pct(lang, s.coefficient))}</td>
                    <td class="text-end">${esc(money(lang, s.share, cur))}</td><td class="text-end"><strong>${esc(money(lang, s.amount, cur))}</strong></td></tr>
                    <tr class="tfb-mgr-just"><td colspan="6">${esc(justification(lang, s, b))}</td></tr>`).join('')}
            </tbody><tfoot><tr class="tfb-week-table-total"><th colspan="5">${esc(T('bonus.total'))}</th><th class="text-end">${esc(money(lang, b.total, cur))}</th></tr></tfoot></table>
            ${b.missing.length ? `<p class="small mb-1"><strong>${esc(T('report.missing'))}</strong></p><ul class="small">${b.missing.map(m => `<li>${esc(missingText(lang, m))}</li>`).join('')}</ul>` : ''}
            <p class="small text-muted">${esc(T('bonus.rule'))}</p>
        </article>`;
    }
    const ind = (code) => data.plan.indicators.find(i => i.code === code) || {unit: ''};
    function bonusView() {
        const y = Number(state.quarter.slice(0, 4));
        const options = [y - 1, y].flatMap(yy => [1, 2, 3, 4].map(q => `${yy}-Q${q}`));
        return `<div class="card tfb-sheet-bar"><div class="card-body d-flex flex-wrap gap-2 align-items-center"><label class="small">${esc(t('bonus.period'))}</label>
                <select class="form-select form-select-sm w-auto" data-quarter>${options.map(o => `<option ${o === state.quarter ? 'selected' : ''}>${o}</option>`).join('')}</select>
                <span class="small text-muted">${esc(t('bonus.period_help'))}</span></div></div>
            <div class="card"><div class="card-body">${bonusDoc(api.lang, data.bonus)}</div></div>`;
    }

    // --- Entretien ----------------------------------------------------------------------------------------------
    function questions(lang, iv) {
        const T = (k, p) => tr(lang, k, p);
        const out = iv.recurring_gaps.map(g => T('q.gap', {indicator: T('ind.' + g.code), n: g.months}));
        iv.no_data.forEach(c => out.push(T('q.no_data', {indicator: T('ind.' + c)})));
        (iv.competency?.gaps || []).forEach(g => out.push(T('q.competency', {question: T('cq.' + g.question), score: g.score, expected: g.expected})));
        return out.concat(list(lang, 'q.standard'));
    }
    function interviewDoc(lang, iv) {
        if (!iv) return '';
        const T = (k, p) => tr(lang, k, p);
        const c = iv.competency;
        return `<article class="tfb-mgr-doc" lang="${lang}">
            <header class="tfb-mgr-doc-head"><div class="small text-muted">${esc(T('interview.doc_title'))} · ${esc(T('job.position'))} · ${esc(T('lang_name'))}</div>
                <h2>${esc(T('interview.title', {from: iv.months[0], to: iv.months[2]}))}</h2><div class="small text-muted">${esc(T('interview.extracted', {at: iv.extracted_at}))}</div></header>
            <h4>${esc(T('interview.summary'))}</h4>
            <table class="table table-sm align-middle tfb-week-table tfb-mgr-table"><thead><tr><th>${esc(T('col.indicator'))}</th>${iv.months.map(m => `<th class="text-end">${esc(m)}</th>`).join('')}
                <th class="text-end">${esc(T('col.objective'))}</th><th class="text-end">${esc(T('col.proposed'))}</th></tr></thead><tbody>
                ${iv.indicators.map(r => `<tr><td>${esc(T('ind.' + r.code))}</td>${r.series.map(s => `<td class="text-end">${s.value === null ? '<span class="text-muted">—</span>' : esc(val(lang, r.unit, s.value))} ${dot(s.status)}</td>`).join('')}
                    <td class="text-end">${esc(r.current_target === null ? T('obj.none') : val(lang, r.unit, r.current_target))}</td>
                    <td class="text-end">${r.proposed_target === null ? '—' : `<strong>${esc(val(lang, r.unit, r.proposed_target))}</strong><div class="small text-muted">${esc(T('proposal.' + r.proposal_from))}</div>`}</td></tr>`).join('')}
            </tbody></table>
            <h4>${esc(T('interview.strengths'))}</h4>${iv.strengths.length ? `<ul>${iv.strengths.map(c2 => `<li>${esc(T('ind.' + c2))}</li>`).join('')}</ul>` : `<p class="text-muted">${esc(T('interview.none'))}</p>`}
            <h4>${esc(T('interview.gaps'))}</h4>${iv.recurring_gaps.length ? `<ul>${iv.recurring_gaps.map(g => `<li>${esc(T('interview.gap_line', {indicator: T('ind.' + g.code), n: g.months}))}</li>`).join('')}</ul>` : `<p class="text-muted">${esc(T('interview.none'))}</p>`}
            ${iv.no_data.length ? `<p class="small">${esc(T('interview.no_data', {list: iv.no_data.map(x => T('ind.' + x)).join(', ')}))}</p>` : ''}
            <h4>${esc(T('interview.questions'))}</h4><ol>${questions(lang, iv).map(q => `<li>${esc(q)}</li>`).join('')}</ol>
            <h4>${esc(T('interview.competency'))}</h4>
            ${c ? `<p>${esc(T('interview.competency_line', {date: c.audit_date, name: c.assessed_name, score: n(c.score, 2, lang)}))}</p>
                <ul class="small">${Object.entries(c.areas).map(([a, s]) => `<li>${esc(T('area.' + a))} : ${esc(n(s, 2, lang))} / 3</li>`).join('')}</ul>` : `<p class="text-muted">${esc(T('interview.no_audit'))}</p>`}
        </article>`;
    }
    function interviewView() {
        return `<div class="card tfb-sheet-bar"><div class="card-body d-flex flex-wrap gap-2 align-items-center"><label class="small">${esc(t('interview.until'))}</label>
                <input type="month" class="form-control form-control-sm w-auto" data-month value="${esc(state.month)}" max="${ym(today)}"></div></div>
            <div class="card"><div class="card-body">${interviewDoc(api.lang, data.interview)}</div></div>`;
    }

    // --- Audit des compétences ----------------------------------------------------------------------------------
    function auditDoc(lang, a) {
        const T = (k, p) => tr(lang, k, p);
        const q = data.audits;
        const scale = q.scale.map(s => `${s} = ${T('scale.' + s)}`).join(' · ');
        return `<article class="tfb-mgr-doc" lang="${lang}">
            <header class="tfb-mgr-doc-head"><div class="small text-muted">${esc(T('audit.doc_title'))} · ${esc(T('job.position'))} · ${esc(T('lang_name'))}</div>
                <h2>${esc(T('audit.title'))}</h2><div class="small text-muted">${a ? esc(T('audit.of', {name: a.assessed_name, date: a.audit_date, score: n(a.score, 2, lang)})) : esc(T('audit.blank'))}</div></header>
            <p class="small">${esc(T('audit.scale'))} : ${esc(scale)}</p>
            ${Object.entries(q.areas).map(([area, qs]) => `<h5>${esc(T('area.' + area))}${a && a.areas[area] !== null ? ` <small class="text-muted">${esc(n(a.areas[area], 2, lang))} / 3</small>` : ''}</h5>
                <table class="table table-sm tfb-mgr-table"><tbody>${qs.map(x => {
                    const ans = a ? a.answers[x] : null;
                    const low = ans && ans.score < q.expected[x];
                    return `<tr class="${low ? 'table-warning' : ''}"><td>${esc(T('cq.' + x))}<div class="small text-muted">${esc(T('audit.expected', {n: q.expected[x]}))}</div>${ans && ans.comment ? `<div class="small">${esc(ans.comment)}</div>` : ''}</td>
                        <td class="text-end tfb-mgr-score">${ans ? `<strong>${ans.score}</strong> / 3` : '☐ 0 ☐ 1 ☐ 2 ☐ 3'}</td></tr>`;
                }).join('')}</tbody></table>`).join('')}
            ${a && a.comment ? `<p><strong>${esc(T('audit.comment'))}</strong> ${esc(a.comment)}</p>` : ''}
        </article>`;
    }
    function auditView() {
        const q = data.audits;
        const last = q.audits[0] || null;
        const form = q.can_report ? `<div class="card"><div class="card-body"><h5>${esc(t('audit.new'))}</h5>
            <div class="row g-2 mb-2"><div class="col-md-3"><label class="form-label small mb-1">${esc(t('audit.date'))}</label><input type="date" class="form-control form-control-sm" data-audit-date value="${today.toISOString().slice(0, 10)}"></div>
                <div class="col-md-4"><label class="form-label small mb-1">${esc(t('audit.assessed'))}</label><input class="form-control form-control-sm" data-audit-name value="${esc(data.plan.plan.holder_name || '')}"></div></div>
            ${Object.entries(q.areas).map(([area, qs]) => `<fieldset class="tfb-mgr-area"><legend>${esc(t('area.' + area))}</legend>${qs.map(x => `<div class="tfb-mgr-question" data-question="${x}">
                <div class="tfb-mgr-qtext">${esc(t('cq.' + x))}<div class="small text-muted">${esc(t('audit.expected', {n: q.expected[x]}))}</div></div>
                <div class="btn-group btn-group-sm" role="group">${q.scale.map(s => `<input type="radio" class="btn-check" name="q_${x}" id="q_${x}_${s}" value="${s}"><label class="btn btn-outline-primary" for="q_${x}_${s}" title="${esc(t('scale.' + s))}">${s}</label>`).join('')}</div>
                <input class="form-control form-control-sm tfb-mgr-qcomment" data-comment placeholder="${esc(t('audit.comment_placeholder'))}" maxlength="500"></div>`).join('')}</fieldset>`).join('')}
            <label class="form-label small mb-1 mt-2">${esc(t('audit.comment'))}</label><textarea class="form-control form-control-sm" rows="2" data-audit-comment></textarea>
            <button type="button" class="btn btn-primary btn-sm mt-2" data-save-audit>${esc(t('audit.save'))}</button> <span class="small text-muted" data-audit-progress></span>
        </div></div>` : '';
        const history = q.audits.length ? `<div class="card mt-3"><div class="card-body"><h5>${esc(t('audit.history'))}</h5>
            <table class="table table-sm tfb-mgr-table"><thead><tr><th>${esc(t('audit.date'))}</th><th>${esc(t('audit.assessed'))}</th><th class="text-end">${esc(t('audit.score'))}</th>
                ${Object.keys(q.areas).map(a => `<th class="text-end small">${esc(t('area.' + a))}</th>`).join('')}<th>${esc(t('audit.gaps'))}</th></tr></thead><tbody>
                ${q.audits.map(a => `<tr><td>${esc(a.audit_date)}</td><td>${esc(a.assessed_name)}</td><td class="text-end"><strong>${esc(n(a.score, 2))}</strong></td>
                    ${Object.keys(q.areas).map(ar => `<td class="text-end">${esc(n(a.areas[ar], 1))}</td>`).join('')}
                    <td class="small">${a.gaps.map(g => esc(t('cq.' + g.question)) + ` (${g.score}/${g.expected})`).join('<br>') || '—'}</td></tr>`).join('')}</tbody></table></div></div>` : '';
        return form + history + (last ? `<div class="card mt-3"><div class="card-body">${auditDoc(api.lang, last)}</div></div>` : '');
    }

    // --- Actions ------------------------------------------------------------------------------------------------
    async function run(fn, button, message) {
        if (button) button.disabled = true;
        try {
            await fn();
            ui.notify(true, message || t('saved'));
            draw();
        } catch (err) {
            ui.notify(false, errText(err));
            if (button) button.disabled = false;
        }
    }
    const numOrNull = (v) => {
        const x = ui.num(v);
        if (x !== null && (typeof x !== 'number' || Number.isNaN(x))) throw new Error(t('invalid_number'));
        return x;
    };

    function bindTab() {
        const root = el.querySelector('.tfb-mgr-body');
        root.querySelector('[data-year]')?.addEventListener('change', (e) => { state.year = Number(e.target.value); data.plan = null; load(); });
        root.querySelector('[data-month]')?.addEventListener('change', (e) => { if (e.target.value) { state.month = e.target.value; load(); } });
        root.querySelector('[data-quarter]')?.addEventListener('change', (e) => { state.quarter = e.target.value; load(); });
        root.querySelectorAll('[data-hours]').forEach(i => i.addEventListener('input', () => {
            const sum = [...root.querySelectorAll('[data-hours]')].reduce((a, x) => a + (Number(ui.num(x.value)) || 0), 0);
            const month = ui.num(root.querySelector('[data-plan="monthly_hours"]')?.value) || 0;
            root.querySelector('[data-hours-total]').textContent = t('plan.hours_sum', {sum: n(sum, 0), month: n(month, 0)});
        }));
        root.querySelector('[data-save-plan]')?.addEventListener('click', (e) => run(async () => {
            const body = {year: state.year};
            root.querySelectorAll('[data-plan]').forEach(i => {
                const f = i.dataset.plan;
                const v = i.value.trim();
                if (['salary_monthly', 'envelope_pct', 'margin_threshold_pct', 'audit_threshold', 'monthly_hours'].includes(f)) body[f] = v === '' ? (f === 'envelope_pct' ? 20 : null) : numOrNull(v);
                else if (f === 'holder_user_id') body[f] = v === '' ? null : Number(v);
                else if (f === 'currency') body[f] = (v || 'PLN').toUpperCase();
                else body[f] = v === '' ? null : v;
            });
            body.mission_hours = Object.fromEntries([...root.querySelectorAll('[data-hours]')].map(i => [i.dataset.hours, i.value.trim() === '' ? null : numOrNull(i.value)]));
            data.plan = await api.put('/manager/plan', body);
        }, e.target));
        root.querySelector('[data-save-objectives]')?.addEventListener('click', (e) => run(async () => {
            const objectives = {};
            root.querySelectorAll('[data-objective]').forEach(i => {
                const ind2 = data.plan.indicators.find(x => x.code === i.dataset.objective);
                const v = i.value.trim() === '' ? null : numOrNull(i.value);
                if (v === null) { if (ind2.draft !== null) objectives[ind2.code] = null; return; }
                if (ind2.draft === null && ind2.validated && Math.abs(ind2.validated.target - v) < 1e-9) return;
                if (ind2.draft !== null && Math.abs(ind2.draft - v) < 1e-9) return;
                objectives[ind2.code] = v;
            });
            if (!Object.keys(objectives).length) throw new Error(t('settings.nothing_changed'));
            data.plan = await api.put('/manager/objectives', {year: state.year, objectives});
        }, e.target, t('settings.drafts_saved')));
        root.querySelector('[data-validate]')?.addEventListener('click', (e) => {
            if (!confirm(t('settings.validate_confirm'))) return;
            run(async () => { data.plan = await api.post('/manager/objectives/validate', {year: state.year}); }, e.target, t('settings.validated'));
        });
        root.querySelectorAll('[data-save-value]').forEach(b => b.addEventListener('click', () => run(async () => {
            const input = root.querySelector(`[data-value="${b.dataset.saveValue}"]`);
            const v = input.value.trim() === '' ? null : numOrNull(input.value);
            data.report = await api.put('/manager/values', {month: state.month, indicator: b.dataset.saveValue, value: v});
        }, b)));
        root.querySelector('[data-add-haccp]')?.addEventListener('click', (e) => run(async () => {
            const f = root.querySelector('[data-haccp-form]');
            const get = (name) => f.querySelector(`[name="${name}"]`).value.trim();
            const body = {event_date: get('event_date'), kind: get('kind')};
            if (get('authority')) body.authority = get('authority');
            if (get('result')) body.result = get('result');
            if (get('score')) body.score = numOrNull(get('score'));
            if (get('description')) body.description = get('description');
            await api.post('/manager/haccp', body);
            data.report = await api.get(`/manager/report?month=${state.month}`);
        }, e.target));
        root.querySelectorAll('[data-treat]').forEach(b => b.addEventListener('click', () => run(async () => {
            await api.patch(`/manager/haccp/${b.dataset.treat}`, {treated: true, treated_at: new Date().toISOString().slice(0, 10)});
            data.report = await api.get(`/manager/report?month=${state.month}`);
        }, b)));
        root.querySelectorAll('[data-del-haccp]').forEach(b => b.addEventListener('click', () => {
            if (!confirm(t('haccp.delete_confirm'))) return;
            run(async () => { await api.del(`/manager/haccp/${b.dataset.delHaccp}`); data.report = await api.get(`/manager/report?month=${state.month}`); }, b);
        }));
        root.querySelectorAll('[data-save-launch]').forEach(b => b.addEventListener('click', () => run(async () => {
            const row = root.querySelector(`[data-launch="${b.dataset.saveLaunch}"]`);
            const get = (name) => row.querySelector(`[name="${name}"]`).value.trim();
            const body = {launch_date: get('launch_date')};
            for (const f of ['target_cost', 'target_price', 'min_margin_pct', 'expected_week_shop']) body[f] = get(f) === '' ? null : numOrNull(get(f));
            body.decision = get('decision') || null;
            await api.put(`/manager/launches/${b.dataset.saveLaunch}`, body);
            data.report = await api.get(`/manager/report?month=${state.month}`);
        }, b)));
        root.querySelector('[data-save-actions]')?.addEventListener('click', (e) => run(async () => {
            const actions = [...root.querySelectorAll('[data-action-row]')].map(r => ({
                text: r.querySelector('[name="text"]').value.trim(), owner: r.querySelector('[name="owner"]').value.trim() || null,
                due_date: r.querySelector('[name="due_date"]').value || null, status: r.querySelector('[name="status"]').value,
            })).filter(a => a.text !== '');
            data.report = await api.put('/manager/actions', {month: state.month, actions});
        }, e.target));
        root.querySelectorAll('[data-question] input[type=radio]').forEach(r => r.addEventListener('change', () => {
            const total = root.querySelectorAll('[data-question]').length;
            const done = root.querySelectorAll('[data-question] input[type=radio]:checked').length;
            root.querySelector('[data-audit-progress]').textContent = t('audit.progress', {done, total});
        }));
        root.querySelector('[data-save-audit]')?.addEventListener('click', (e) => run(async () => {
            const answers = {};
            root.querySelectorAll('[data-question]').forEach(q => {
                const checked = q.querySelector('input[type=radio]:checked');
                if (!checked) return;
                const comment = q.querySelector('[data-comment]').value.trim();
                answers[q.dataset.question] = comment ? {score: Number(checked.value), comment} : {score: Number(checked.value)};
            });
            data.audits = await api.post('/manager/audits', {audit_date: root.querySelector('[data-audit-date]').value, assessed_name: root.querySelector('[data-audit-name]').value.trim(),
                answers, comment: root.querySelector('[data-audit-comment]').value.trim() || null});
        }, e.target, t('audit.saved')));
    }

    window.addEventListener('hashchange', () => {
        const s = readHash();
        if (s.tab !== state.tab || s.month !== state.month || s.quarter !== state.quarter || s.year !== state.year) { state = s; load(); }
    });
    load();
})();
