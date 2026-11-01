/*
 * Module RH · Dimona — page autonome (JavaScript sans dépendance ni compilation), servie à /hr/.
 *
 * Toutes les données passent par l'API du module (api/v1/erp/dimona/…, à côté de la page) ; l'identité est
 * celle de la console franchisé (compte franchisé ou siège), dont la page appelle la connexion. Rien de
 * sensible n'est gardé dans le navigateur : ni NISS (affiché au clic, remasqué après 30 s), ni clé (lue du
 * fichier, envoyée une fois au serveur, oubliée).
 *
 * Tout se fait par assistants : « Connexion ONSS » (paramètres), « Engager » (Dimona IN), et depuis la fiche
 * d'une personne : sortie, modification, annulation.
 */
'use strict';

const API = 'api/v1/erp/dimona';
const app = document.getElementById('app');

const S = {
  session: null, societeId: null, view: 'equipe', param: null,
  team: null, worker: null, decls: null, audit: null, filter: '', declFilter: '',
  wiz: null, toast: null, revealed: null, loading: false, loginBo: 'franchisee', loginError: null, loginBusy: false,
  pwd: null,
};

/* ------------------------------------------------------------------ outils */
const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const fmtDate = (d) => (d ? `${d.slice(8, 10)}/${d.slice(5, 7)}/${d.slice(0, 4)}` : '—');
const fmtHour = (h) => (h ? `${String(h).slice(0, 2)}:${String(h).slice(-2)}` : '');
const fmtWhen = (iso) => (iso ? new Date(iso).toLocaleString('fr-BE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—');
const today = () => new Date().toISOString().slice(0, 10);
const digits = (s) => String(s ?? '').replace(/\D/g, '');
const TYPES = { IN: 'Entrée', OUT: 'Sortie', UPDATE: 'Modification', CANCEL: 'Annulation' };
const ST = {
  ACCEPTED: ['ok', 'Acceptée'], WARNING: ['warn', 'Acceptée avec avertissement'], REFUSED: ['ko', 'Refusée'],
  WAITING_SIGEDIS: ['wait', 'En attente (SIGeDIS)'], PENDING: ['wait', 'En traitement à l’ONSS'], ERROR: ['ko', 'Erreur'],
};
const chip = (status) => {
  const [k, l] = ST[status] || ['none', status || '—'];
  return `<span class="st st--${k}${status === 'PENDING' || status === 'WAITING_SIGEDIS' ? ' is-live' : ''}">${esc(l)}</span>`;
};
const TEAM = { declared: ['ok', 'En service'], pending: ['wait', 'Dimona en cours'], problem: ['ko', 'À corriger'], closed: ['none', 'Sortie déclarée'], canceled: ['none', 'Dimona annulée'], none: ['none', 'Pas de Dimona'] };

/** NISS : 11 chiffres, contrôle modulo 97 (« 2 » devant pour les naissances depuis 2000). */
function nissValide(s) {
  const n = digits(s);
  if (n.length !== 11) return false;
  const base = n.slice(0, 9), ctrl = Number(n.slice(9));
  return 97 - (Number(base) % 97) === ctrl || 97 - (Number('2' + base) % 97) === ctrl;
}
const nissFormat = (s) => { const n = digits(s); return n.length === 11 ? `${n.slice(0, 2)}.${n.slice(2, 4)}.${n.slice(4, 6)}-${n.slice(6, 9)}.${n.slice(9)}` : s; };
/** La commission paritaire telle que la Dimona l'attend (instructions ONSS) — même règle que le serveur. */
const cpDimona = (cp) => { const t = digits(cp).slice(0, 3); return (S.session?.jointCommission?.explicit || []).includes(t) ? t : 'XXX'; };

async function api(method, path, body) {
  const headers = { Accept: 'application/json' };
  if (method !== 'GET') { headers['X-HR-Request'] = '1'; headers['Content-Type'] = 'application/json'; }
  const r = await fetch(`${API}${path}`, { method, credentials: 'same-origin', headers, body: body === undefined ? undefined : JSON.stringify(body) });
  let data = null;
  try { data = await r.json(); } catch (e) { data = null; }
  if (!r.ok) {
    const err = new Error((data && data.error) || `Erreur ${r.status}`);
    err.status = r.status; err.fields = (data && data.fields) || {}; err.code = data && data.code;
    if (r.status === 401 && S.session && S.session.identity) { S.session.identity = null; render(); }
    throw err;
  }
  return data;
}

async function consoleCall(bo, action, body, csrf) {
  const base = S.session && S.session.console.api;
  const headers = { 'Content-Type': 'application/json', Accept: 'application/json' };
  if (csrf) headers['X-CSRF-Token'] = csrf;
  const r = await fetch(`${base}/bo/${bo}/${action}`, { method: 'POST', credentials: 'same-origin', headers, body: JSON.stringify(body || {}) });
  let data = null;
  try { data = await r.json(); } catch (e) { data = null; }
  if (!r.ok) throw new Error((data && data.error) || `Erreur ${r.status}`);
  return data;
}

function toast(msg, ko) {
  S.toast = { msg, ko: !!ko };
  renderToast();
  clearTimeout(toast.t);
  toast.t = setTimeout(() => { S.toast = null; renderToast(); }, 4200);
}
function renderToast() {
  let el = document.getElementById('hr-toast');
  if (!S.toast) { if (el) el.remove(); return; }
  if (!el) { el = document.createElement('div'); el.id = 'hr-toast'; el.setAttribute('role', 'status'); document.body.appendChild(el); }
  el.className = 'hr-toast' + (S.toast.ko ? ' is-ko' : '');
  el.textContent = S.toast.msg;
}

const me = () => S.session && S.session.identity;
const canWrite = () => !!(me() && me().canWrite);
const societes = () => (S.session && S.session.employers) || [];
const societe = () => societes().find((e) => e.id === S.societeId) || null;
const envs = () => (S.session && S.session.environments.enabled) || ['SIM'];
const shopName = (id) => ((S.session && S.session.shops) || []).find((s) => s.id === String(id))?.name || `Boutique ${id}`;
function activeCred(soc) { return (soc?.credentials || []).find((c) => c.isActive && envs().includes(c.env)) || null; }

/* ------------------------------------------------------------------ chargement */
async function loadSession() {
  S.session = await api('GET', '/session');
  if (me()) {
    const ids = societes().map((e) => e.id);
    const saved = Number(sessionStorage.getItem('hr.societe'));
    S.societeId = ids.includes(S.societeId) ? S.societeId : (ids.includes(saved) ? saved : (ids[0] ?? null));
  }
}

async function loadView() {
  if (!me()) return;
  const sid = S.societeId;
  S.loading = true; render();
  try {
    if (S.view === 'equipe' && sid) S.team = await api('GET', `/workers?societe_id=${sid}`);
    if (S.view === 'personne' && S.param) S.worker = await api('GET', `/workers/${S.param}`);
    if (S.view === 'declarations' && sid) S.decls = await api('GET', `/declarations?societe_id=${sid}`);
    if (S.view === 'journal' && sid) S.audit = await api('GET', `/audit?societe_id=${sid}`);
    if (S.view === 'engager' && sid && !S.team) S.team = await api('GET', `/workers?societe_id=${sid}`);
  } catch (e) {
    toast(e.message, true);
  }
  S.loading = false; render();
}

function go(hash) { if (location.hash === hash) { route(); } else { location.hash = hash; } }

function route() {
  const [v, p] = (location.hash.replace(/^#/, '') || 'equipe').split('/');
  S.view = ['equipe', 'engager', 'parametres', 'declarations', 'journal', 'personne'].includes(v) ? v : 'equipe';
  S.param = p || null;
  if (S.view === 'parametres' && (!S.wiz || S.wiz.kind !== 'settings')) startSettings();
  if (S.view === 'engager' && (!S.wiz || S.wiz.kind !== 'hire' || S.wiz.step === 4 || (p && Number(p) !== S.wiz.data.employee_id))) startHire(p ? Number(p) : null);
  if (!['parametres', 'engager', 'personne'].includes(S.view)) S.wiz = null;
  if (S.view === 'personne' && S.wiz && !['out', 'update', 'cancel'].includes(S.wiz.kind)) S.wiz = null;
  loadView();
}

/* ------------------------------------------------------------------ rendu : enveloppe */
function render() {
  if (!S.session) { app.innerHTML = '<p class="muted hr-boot">Chargement…</p>'; return; }
  if (!me()) { app.innerHTML = loginView(); return; }
  const soc = societe();
  const cred = activeCred(soc);
  const env = cred ? cred.env : envs()[envs().length - 1];
  const titles = { equipe: 'Mon équipe', engager: 'Engager une personne', parametres: 'Connexion ONSS', declarations: 'Déclarations', journal: 'Journal', personne: S.worker ? S.worker.name : 'Fiche' };
  const nav = (v, label, icon) => `<a class="sidebar__item${S.view === v || (v === 'equipe' && S.view === 'personne') ? ' is-active' : ''}" href="#${v}"${S.view === v ? ' aria-current="page"' : ''}>${icon}${esc(label)}</a>`;
  const ic = (d) => `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${d}</svg>`;
  app.innerHTML = `
  <div class="workspace">
    <nav class="sidebar" aria-label="Module RH">
      <div class="sidebar__head"><img class="sidebar__logo" src="img/logo.png" alt="L’Atelier By" /><div class="sidebar__kicker">Module RH · Dimona</div></div>
      <div class="sidebar__groups">
        <div class="sidebar__group">
          <div class="sidebar__group-label">Personnel</div>
          ${canWrite() && soc ? `<a class="sidebar__action${S.view === 'engager' ? ' is-active' : ''}" href="#engager">+ Engager</a>` : ''}
          ${nav('equipe', 'Mon équipe', ic('<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'))}
          ${nav('declarations', 'Déclarations', ic('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 15l2 2 4-4"/>'))}
        </div>
        <div class="sidebar__group">
          <div class="sidebar__group-label">Paramètres</div>
          ${nav('parametres', 'Connexion ONSS', ic('<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>'))}
          ${nav('journal', 'Journal', ic('<path d="M12 8v4l3 3"/><circle cx="12" cy="12" r="9"/>'))}
        </div>
      </div>
      <div class="sidebar__foot">${cred ? certFoot(cred) : (soc ? 'Connexion ONSS à paramétrer.' : 'Aucune société paramétrée.')}</div>
    </nav>
    <div class="workspace__main">
      <header class="topbar">
        <div>
          <p class="topbar__kicker">${soc ? esc(soc.name) + ' · ' : ''}<span class="hr-sim${env === 'PROD' ? ' hr-sim--prod' : ''}">${env === 'PROD' ? 'Production' : 'Simulation'}</span></p>
          <h1>${esc(titles[S.view])}</h1>
        </div>
        <div class="topbar__actions">
          ${societes().length > 1 ? `<label class="field hr-societe">Société <select data-change="societe">${societes().map((e) => `<option value="${e.id}"${e.id === S.societeId ? ' selected' : ''}>${esc(e.name)}</option>`).join('')}</select></label>` : ''}
          <div class="hr-user"><strong>${esc(me().name)}</strong>${me().role === 'franchisor' ? 'Franchiseur · lecture seule' : 'Franchisé'}</div>
          ${S.session.console.bo ? '<button type="button" class="btn btn--ghost btn--sm" data-act="pwdOpen">Mot de passe</button>' : ''}
          <button type="button" class="btn btn--ghost btn--sm" data-act="logout">Se déconnecter</button>
        </div>
      </header>
      <main class="app__body${S.view === 'equipe' || S.view === 'declarations' || S.view === 'journal' ? ' app__body--wide' : ''}">
        ${body()}
      </main>
    </div>
  </div>${S.pwd ? pwdModal() : ''}`;
}

/** Changer son mot de passe de la console (POST {console}/bo/{bo}/password, jeton CSRF de la session). */
function pwdModal() {
  const p = S.pwd;
  return `<div class="confirm" role="dialog" aria-modal="true" aria-labelledby="hr-pwd-t"><form class="confirm__box card" data-form="pwd" novalidate>
    <h3 id="hr-pwd-t">Changer mon mot de passe</h3>
    <p class="muted">Celui de votre compte de la console franchisé : il vaut ici et dans la console.</p>
    <label class="field field--grow">Mot de passe actuel<input type="password" name="current" autocomplete="current-password" required /></label>
    <label class="field field--grow">Nouveau mot de passe<input type="password" name="new" autocomplete="new-password" minlength="12" required /><span class="hr-hint">12 caractères au moins.</span></label>
    <label class="field field--grow">Le nouveau, une seconde fois<input type="password" name="again" autocomplete="new-password" required /></label>
    ${p.error ? `<p class="error">${esc(p.error)}</p>` : ''}${p.done ? '<p class="muted">Mot de passe changé.</p>' : ''}
    <div class="confirm__actions"><button type="button" class="btn btn--ghost" data-act="pwdClose">${p.done ? 'Fermer' : 'Annuler'}</button>${p.done ? '' : `<button type="submit" class="btn btn--primary"${p.busy ? ' disabled' : ''}>${p.busy ? 'Un instant…' : 'Changer'}</button>`}</div>
  </form></div>`;
}

function certFoot(c) {
  if (c.certificateDaysLeft === null) return `Connexion ${c.env} · ${esc(c.clientId)}`;
  const alert = c.certificateAlert ? ` <span class="st st--${c.certificateDaysLeft < 15 ? 'ko' : 'warn'} hr-badge-days">${c.certificateDaysLeft < 0 ? 'expiré' : 'expire dans ' + c.certificateDaysLeft + ' j'}</span>` : '';
  return `Connexion ${c.env}${c.lastTest?.ok ? ' testée' : ' <strong>non testée</strong>'}<br>Certificat jusqu’au ${fmtWhen(c.certificateExpiresAt).slice(0, 10)}${alert}`;
}

function body() {
  if (S.view === 'parametres') return canWrite() ? settingsWizard() : readOnlyCard('La connexion ONSS se paramètre depuis le compte du franchisé.');
  if (!societe()) return noSociete();
  if (S.view === 'engager') return canWrite() ? hireWizard() : readOnlyCard('Les déclarations se font depuis le compte du franchisé.');
  if (S.view === 'personne') return personView();
  if (S.view === 'declarations') return declsView();
  if (S.view === 'journal') return journalView();
  return teamView();
}

const readOnlyCard = (t) => `<section class="card hr-empty"><h2>Lecture seule</h2><p class="muted">${esc(t)}</p></section>`;

function noSociete() {
  if (!canWrite()) return `<section class="card hr-empty"><h2>Aucune société paramétrée</h2><p class="muted">Aucun franchisé n’a encore relié sa société à l’ONSS dans ce module.</p></section>`;
  return `<section class="card hr-empty">
    <h2>Reliez votre société à l’ONSS</h2>
    <p class="muted">Avant la première Dimona : votre société (numéro d’entreprise, boutiques), votre certificat et le Client ID de votre compte Chaman. Comptez dix minutes, une seule fois.</p>
    <a class="btn btn--primary" href="#parametres">Commencer le paramétrage →</a>
  </section>`;
}

/* ------------------------------------------------------------------ connexion */
function loginView() {
  const c = S.session.console;
  let inner;
  if (!c.configured) inner = `<p class="error">La connexion par la console franchisé n’est pas configurée sur ce serveur.</p><p class="muted hr-login__note">Administrateur : renseigner HR_CONSOLE_API_BASE et HR_CONSOLE_API_INTERNAL (voir docs/dimona.md).</p>`;
  else if (!c.available) inner = `<p class="error">${esc(c.message || 'La console franchisé ne répond pas.')}</p><p class="muted hr-login__note">Les comptes de la console (franchisé et siège) doivent être activés côté console pour ouvrir ce module.</p>`;
  else {
    inner = `
      <div class="hr-login__who" role="radiogroup" aria-label="Type de compte">
        <button type="button" class="choice-pill${S.loginBo === 'franchisee' ? ' is-on' : ''}" data-act="loginBo" data-bo="franchisee" aria-pressed="${S.loginBo === 'franchisee'}">Franchisé</button>
        <button type="button" class="choice-pill${S.loginBo === 'franchisor' ? ' is-on' : ''}" data-act="loginBo" data-bo="franchisor" aria-pressed="${S.loginBo === 'franchisor'}">Siège (franchiseur)</button>
      </div>
      <form data-form="login" novalidate>
        <label class="field field--grow">Adresse e-mail<input type="email" name="email" autocomplete="username" required /></label>
        <label class="field field--grow">Mot de passe<input type="password" name="password" autocomplete="current-password" required /></label>
        ${S.loginError ? `<p class="error">${esc(S.loginError)}</p>` : ''}
        <button type="submit" class="btn btn--primary"${S.loginBusy ? ' disabled' : ''}>${S.loginBusy ? 'Connexion…' : 'Se connecter'}</button>
      </form>
      <p class="muted hr-login__note">Le compte de la console franchisé : le même e-mail, le même mot de passe. Le siège consulte en lecture seule.</p>`;
  }
  return `<div class="hr-login"><div class="hr-login__card"><img src="img/logo.png" alt="L’Atelier By" /><h1>Module RH · Dimona</h1><p class="muted">Déclarez les entrées et sorties de votre personnel à l’ONSS.</p>${inner}</div></div>`;
}

/* ------------------------------------------------------------------ équipe */
function teamView() {
  if (!S.team) return '<p class="muted">Chargement…</p>';
  const soc = societe();
  const cred = activeCred(soc);
  const items = S.team.items.filter((r) => !S.filter || r.name.toLowerCase().includes(S.filter.toLowerCase()));
  const n = (k) => S.team.items.filter((r) => r.dimona === k).length;
  const connection = !cred ? '<span class="st st--ko">À paramétrer</span>' : (cred.lastTest?.ok ? '<span class="st st--ok">Connectée</span>' : '<span class="st st--warn">Non testée</span>');
  return `
  <div class="hr-strip">
    <section class="card"><div class="hr-strip__k">En service</div><div class="hr-strip__v">${n('declared')}</div><div class="hr-strip__s">période Dimona ouverte</div></section>
    <section class="card"><div class="hr-strip__k">En cours</div><div class="hr-strip__v">${n('pending')}</div><div class="hr-strip__s">résultat ONSS attendu</div></section>
    <section class="card"><div class="hr-strip__k">À corriger</div><div class="hr-strip__v">${n('problem')}</div><div class="hr-strip__s">refusée ou en erreur</div></section>
    <section class="card"><div class="hr-strip__k">Connexion ONSS</div><div class="hr-strip__v">${connection}</div><div class="hr-strip__s">${cred ? esc(cred.env === 'PROD' ? 'Production' : 'Simulation') + (cred.certificateAlert ? ' · certificat à renouveler' : '') : '<a href="#parametres">Paramétrer</a>'}</div></section>
  </div>
  <div class="hr-toolbar">
    <label class="field">Rechercher <input type="search" data-f="filter" value="${esc(S.filter)}" placeholder="Nom" /></label>
    <div class="hr-toolbar__grow">${canWrite() ? '<a class="btn btn--primary" href="#engager">+ Engager une personne</a>' : ''}</div>
  </div>
  <section class="card table-card"><div class="table-scroll"><table class="hr-team">
    <thead><tr><th>Personne</th><th>Boutique</th><th>Statut Dimona</th><th>Période</th><th>Type</th><th></th></tr></thead>
    <tbody>${items.length ? items.map(teamRow).join('') : `<tr><td colspan="6"><p class="muted">Personne dans les boutiques de ${esc(soc.name)}${S.filter ? ' pour cette recherche' : ''}.</p></td></tr>`}</tbody>
  </table></div></section>`;
}

function teamRow(r) {
  const [k, l] = TEAM[r.dimona] || TEAM.none;
  const per = r.period ? `${fmtDate(r.period.startDate)} → ${r.period.endDate ? fmtDate(r.period.endDate) : 'sans fin'}` : '—';
  const open = r.workerId ? `#personne/${r.workerId}` : null;
  const action = r.workerId && r.dimona !== 'closed' && r.dimona !== 'canceled' ? `<a class="btn btn--sm" href="#personne/${r.workerId}">Ouvrir</a>`
    : r.workerId && canWrite() ? `<a class="btn btn--sm" href="#engager/${r.employeeId || ''}" data-act="hireWorker" data-id="${r.workerId}">Déclarer une entrée</a>`
    : r.workerId ? `<a class="btn btn--sm" href="#personne/${r.workerId}">Ouvrir</a>`
    : (canWrite() ? `<a class="btn btn--sm" href="#engager/${r.employeeId}">Déclarer l’entrée</a>` : '');
  return `<tr${open ? ` class="is-click" data-act="open" data-href="${open}"` : ''}>
    <td><span class="hr-name">${esc(r.name)}</span>${!r.inPanel ? '<span class="hr-sub">hors panel (embauche)</span>' : (!r.active ? '<span class="hr-sub">inactif au panel</span>' : '')}</td>
    <td>${esc(r.shopName || '—')}</td>
    <td><span class="st st--${k}">${l}</span></td>
    <td>${per}</td>
    <td>${esc(r.workerType || '—')}</td>
    <td class="num">${action}</td>
  </tr>`;
}

/* ------------------------------------------------------------------ fiche */
function personView() {
  const w = S.worker;
  if (!w) return '<p class="muted">Chargement…</p>';
  if (S.wiz && ['out', 'update', 'cancel'].includes(S.wiz.kind)) return smallWizard();
  const p = w.activePeriod;
  const types = Object.fromEntries((S.session.workerTypes || []).map((t) => [t.code, t.label]));
  const shown = S.revealed && S.revealed.id === w.id ? S.revealed.ssin : null;
  return `<div class="hr-person">
    <div>
      <section class="card wizard-card">
        <div class="hr-person__head">
          <div><h2>${esc(w.name)}</h2><p class="muted">${w.shopId ? esc(shopName(w.shopId)) : 'Boutique non précisée'}${w.erpEmployeeId ? ' · employé du panel n° ' + w.erpEmployeeId : ' · hors panel'}</p></div>
          <a class="btn btn--ghost btn--sm" href="#equipe">← L’équipe</a>
        </div>
        <h3 class="section-label">Période Dimona</h3>
        ${p ? `<div class="hr-period">
            <span class="st st--ok">En service</span>
            <div class="hr-period__dates">${fmtDate(p.startDate)}${p.startHour ? ' ' + fmtHour(p.startHour) : ''} → ${p.endDate ? fmtDate(p.endDate) + (p.endHour ? ' ' + fmtHour(p.endHour) : '') : 'sans date de fin'}</div>
            <p class="muted">${esc(types[p.workerType] || p.workerType || '')} · CP déclarée ${esc(p.jointCommission || '—')}${p.plannedHours ? ' · ' + p.plannedHours + ' h planifiées' : ''} · période ONSS n° ${p.dimonaPeriodId}${p.source === 'SYNCED' ? ' · importée de l’ONSS' : ''}</p>
          </div>
          ${canWrite() ? `<div class="hr-actions">
            <button type="button" class="btn btn--primary" data-act="small" data-kind="out">Déclarer la sortie</button>
            <button type="button" class="btn" data-act="small" data-kind="update">Modifier</button>
            <button type="button" class="btn btn--danger" data-act="small" data-kind="cancel">Annuler la Dimona</button>
          </div>` : ''}`
        : `<p class="muted">Aucune période ouverte connue du module.</p>
          ${canWrite() ? `<div class="hr-actions">
            <a class="btn btn--primary" href="#engager/${w.erpEmployeeId || ''}" data-act="hireWorker" data-id="${w.id}">Déclarer l’entrée</a>
            ${w.hasSsin ? '<button type="button" class="btn" data-act="sync">Importer ses périodes depuis l’ONSS</button>' : ''}
          </div><p class="muted hr-hint">Déjà déclarée sur le portail Dimona ? « Importer » retrouve ses périodes à l’ONSS pour pouvoir déclarer sa sortie ici.</p>` : ''}`}
        <h3 class="section-label">Historique des déclarations</h3>
        ${w.declarations.length ? `<ul class="hr-history">${w.declarations.map(historyItem).join('')}</ul>` : '<p class="muted">Aucune déclaration pour cette personne.</p>'}
      </section>
    </div>
    <aside class="hub-aside">
      <section class="card hub-aside__card">
        <span class="hub-aside__kicker">Fiche Dimona</span>
        <dl class="hr-kv">
          <dt>Numéro national</dt><dd><span class="hr-niss"><span>${w.hasSsin ? esc(shown || '••••••.•••.••') : '<em>manquant</em>'}</span>${w.hasSsin ? `<button type="button" class="btn btn--sm" data-act="${shown ? 'hide' : 'reveal'}">${shown ? 'Masquer' : 'Afficher'}</button>` : ''}</span></dd>
          <dt>Naissance</dt><dd>${fmtDate(w.birthDate)}</dd>
          <dt>Type</dt><dd>${esc(types[w.workerType] || w.workerType || '—')}</dd>
          <dt>Commission paritaire</dt><dd>${esc(w.jointCommission || societe()?.jointCommission || '—')}</dd>
        </dl>
        ${w.hasSsin ? '<p class="muted hr-hint">Chaque affichage du numéro national est écrit au journal (qui, quand).</p>' : ''}
      </section>
      ${w.periods.length > 1 || (!p && w.periods.length) ? `<section class="card hub-aside__card"><span class="hub-aside__kicker">Périodes connues</span><ul class="hr-history">${w.periods.map((x) => `<li><span class="st st--${x.status === 'ACTIVE' ? 'ok' : (x.status === 'CANCELED' ? 'ko' : 'none')}">${x.status === 'ACTIVE' ? 'Ouverte' : (x.status === 'CANCELED' ? 'Annulée' : 'Close')}</span> <span class="hr-history__meta">${fmtDate(x.startDate)} → ${x.endDate ? fmtDate(x.endDate) : '…'}</span></li>`).join('')}</ul></section>` : ''}
    </aside>
  </div>`;
}

function historyItem(d) {
  const sev = d.status === 'REFUSED' || d.status === 'ERROR' ? 'is-ko' : (d.status === 'WARNING' ? 'is-warn' : '');
  const anomalies = (d.anomalies || []).map((a) => `<li class="${sev}">${esc(a.fr || a.nl || '')}${a.errorId ? ` <code>${esc(a.errorId)}</code>` : ''}${a.nl && a.fr ? `<small lang="nl">${esc(a.nl)}</small>` : ''}</li>`).join('');
  const pending = d.status === 'PENDING' || d.status === 'WAITING_SIGEDIS' || (d.status === 'ERROR' && !d.dimonaDeclarationId);
  return `<li>
    <div class="hr-history__row">
      <span class="hr-history__type">${esc(TYPES[d.type] || d.type)}</span>${chip(d.status)}
      <span class="hr-history__meta">${fmtWhen(d.createdAt)}${d.submittedBy ? ' · ' + esc(d.submittedBy) : ''}${d.dimonaDeclarationId ? ' · n° ' + d.dimonaDeclarationId : ''} · ${esc(d.env)}</span>
      ${pending ? `<button type="button" class="btn btn--sm" data-act="refresh" data-id="${d.id}">Actualiser</button>` : ''}
    </div>
    ${d.lastError ? `<p class="error">${esc(d.lastError)}</p>` : ''}
    ${anomalies ? `<ul class="hr-anomalies">${anomalies}</ul>` : ''}
  </li>`;
}

/* ------------------------------------------------------------------ déclarations, journal */
function declsView() {
  if (!S.decls) return '<p class="muted">Chargement…</p>';
  const f = S.declFilter;
  const items = S.decls.items.filter((d) => !f || d.status === f);
  const pill = (v, l) => `<button type="button" class="filter${f === v ? ' is-on' : ''}" data-act="declFilter" data-v="${v}">${l}</button>`;
  return `<div class="hr-toolbar">${pill('', 'Toutes')}${pill('PENDING', 'En traitement')}${pill('ACCEPTED', 'Acceptées')}${pill('WARNING', 'Avec avertissement')}${pill('REFUSED', 'Refusées')}${pill('ERROR', 'En erreur')}</div>
  <section class="card table-card"><div class="table-scroll"><table>
    <thead><tr><th>Date</th><th>Personne</th><th>Type</th><th>Statut</th><th>N° ONSS</th><th>Par</th><th></th></tr></thead>
    <tbody>${items.length ? items.map((d) => `<tr class="is-click" data-act="open" data-href="#personne/${d.workerId}">
      <td>${fmtWhen(d.createdAt)}</td><td>${esc(d.workerName)}</td><td>${esc(TYPES[d.type] || d.type)}</td><td>${chip(d.status)}${d.anomalies?.length ? ` <span class="hr-hint">${d.anomalies.length} remarque(s)</span>` : ''}</td>
      <td>${d.dimonaDeclarationId || '—'}</td><td>${esc(d.submittedBy || '')}</td><td class="num"><a class="btn btn--sm" href="#personne/${d.workerId}">Ouvrir</a></td></tr>`).join('')
    : '<tr><td colspan="7"><p class="muted">Aucune déclaration.</p></td></tr>'}</tbody>
  </table></div></section>`;
}

function journalView() {
  if (!S.audit) return '<p class="muted">Chargement…</p>';
  return `<p class="muted">Chaque geste du module : qui, quand, quoi. Jamais une donnée sensible (numéro national, clé, jeton).</p>
  <section class="card table-card"><div class="table-scroll"><table>
    <thead><tr><th>Quand</th><th>Qui</th><th>Action</th><th>Détail</th></tr></thead>
    <tbody>${S.audit.items.map((a) => `<tr><td>${fmtWhen(a.at)}</td><td>${esc(a.user || '—')}${a.role === 'system' ? ' <span class="hr-hint">(automatique)</span>' : ''}</td><td><code>${esc(a.action)}</code></td><td>${esc(a.detail || '')}</td></tr>`).join('') || '<tr><td colspan="4"><p class="muted">Rien encore.</p></td></tr>'}</tbody>
  </table></div></section>`;
}

/* ------------------------------------------------------------------ assistants : cadre commun */
function steps(names, cur, okUpTo) {
  return `<nav class="hub-steps" aria-label="Étapes">${names.map((n, i) => `<div class="hub-steps__item">${i ? `<span class="hub-steps__line${i <= cur ? ' is-done' : ''}" aria-hidden="true"></span>` : ''}<button type="button" class="hub-step${i < cur ? ' is-done' : ''}${i === cur ? ' is-cur' : ''}" data-act="step" data-i="${i}"${i > okUpTo ? ' disabled title="Une étape précédente est incomplète."' : ''}${i === cur ? ' aria-current="step"' : ''}><i>${i < cur ? '✓' : i + 1}</i>${esc(n)}</button></div>`).join('')}</nav>`;
}
function actions(prevLabel, nextLabel, opts = {}) {
  const w = S.wiz;
  return `<div class="wizard-actions">
    <div class="wizard-actions__group"><a class="btn btn--ghost" href="${opts.quit || '#equipe'}" data-act="quit">${esc(opts.quitLabel || 'Quitter')}</a></div>
    <div class="wizard-actions__group wizard-actions__group--nav">
      ${w.note ? `<span class="muted wizard-actions__state">${esc(w.note)}</span>` : ''}
      ${prevLabel ? `<button type="button" class="btn" data-act="prev"${w.busy ? ' disabled' : ''}>← ${esc(prevLabel)}</button>` : ''}
      ${nextLabel ? `<button type="button" class="btn btn--primary${w.busy ? ' is-busy' : ''}" data-act="next"${w.busy || opts.disabled ? ' disabled' : ''}>${w.busy ? 'Un instant…' : esc(nextLabel)}</button>` : ''}
    </div>
  </div>`;
}
const err = (k) => (S.wiz && S.wiz.errors[k] ? `<span class="field__error">${esc(S.wiz.errors[k])}</span>` : '');
const bad = (k) => (S.wiz && S.wiz.errors[k] ? ' is-bad' : '');
const val = (k) => esc(S.wiz.data[k] ?? '');
function input(k, label, attrs = '', hint = '') {
  return `<label class="field field--grow${bad(k)}">${esc(label)}<input data-f="${k}" value="${val(k)}" ${attrs} />${hint ? `<span class="hr-hint">${hint}</span>` : ''}${err(k)}</label>`;
}
function kv(rows) { return `<dl class="hub-kv">${rows.map(([k, v]) => `<div><dt>${esc(k)}</dt><dd>${v}</dd></div>`).join('')}</dl>`; }
function fieldErrors(e, map) {
  const out = {};
  for (const [k, v] of Object.entries(e.fields || {})) out[map && map[k] ? map[k] : k] = v;
  return out;
}

/* ------------------------------------------------------------------ assistant : Connexion ONSS */
function startSettings() {
  const soc = societe();
  const cred = activeCred(soc);
  const myShops = (S.session.shops || []).filter((s) => !s.societeId || s.societeId === soc?.id).map((s) => s.id);
  S.wiz = {
    kind: 'settings', step: 0, errors: {}, busy: false, note: '', result: null,
    data: {
      name: soc?.name || '', enterprise_number: soc?.enterpriseNumberFormatted || '', onss_employer_id: soc?.onssEmployerId || '',
      joint_commission: soc?.jointCommission || '', shops: soc ? [...soc.shops] : myShops,
      keep: !!cred, certificate: null, certificateName: '', private_key: null, keyName: '', passphrase: '',
      client_id: cred?.clientId || '', env: cred?.env || envs()[0], credId: cred?.id || null,
    },
  };
}

function settingsWizard() {
  const w = S.wiz;
  const d = w.data;
  const names = ['Société', 'Certificat', 'Compte Chaman', 'Tester'];
  const soc = societe();
  const cred = activeCred(soc);
  let card = '';
  if (w.step === 0) {
    const shops = (S.session.shops || []);
    card = `<h2>Votre société</h2><p class="muted">L’employeur déclaré à l’ONSS. Une société peut exploiter plusieurs boutiques : cochez celles dont elle emploie le personnel.</p>
      <div class="hr-two">${input('name', 'Raison sociale', 'autocomplete="organization" maxlength="160"')}${input('enterprise_number', 'Numéro d’entreprise (BCE)', 'inputmode="numeric" placeholder="0411.702.543"')}</div>
      <div class="hr-two">${input('onss_employer_id', 'Numéro ONSS (facultatif)', 'inputmode="numeric"', 'Le numéro d’entreprise suffit à l’ONSS.')}${input('joint_commission', 'Commission paritaire par défaut', 'placeholder="118.03"', `Déclarée à l’ONSS sous « ${esc(cpDimona(d.joint_commission))} ».`)}</div>
      <h3 class="section-label">Boutiques de la société</h3>
      <div class="choice-row">${shops.map((s) => {
        const taken = s.societeId && s.societeId !== soc?.id;
        const on = d.shops.includes(s.id);
        return `<button type="button" class="choice-pill${on ? ' is-on' : ''}" data-act="toggleShop" data-id="${esc(s.id)}"${taken ? ' disabled title="Déjà rattachée à une autre société"' : ''} aria-pressed="${on}">${esc(s.name)}</button>`;
      }).join('') || '<p class="muted">Votre compte n’est lié à aucune boutique.</p>'}</div>${err('shops')}`;
  } else if (w.step === 1) {
    card = `<h2>Le certificat de la société</h2><p class="muted">L’ONSS reconnaît votre société à un certificat : vous en gardez la clé privée, vous lui confiez le certificat. Ce module garde la clé chiffrée et ne la montre plus jamais.</p>
      ${cred ? `<div class="choice-row">
          <button type="button" class="choice-card${d.keep ? ' is-on' : ''}" data-act="setKeep" data-v="1"><strong>Garder le certificat actuel</strong><small>${esc(cred.certificateSubject || '')} · jusqu’au ${fmtWhen(cred.certificateExpiresAt).slice(0, 10)}${cred.certificateAlert ? ' · à renouveler' : ''}</small></button>
          <button type="button" class="choice-card${!d.keep ? ' is-on' : ''}" data-act="setKeep" data-v="0"><strong>Déposer un nouveau certificat</strong><small>renouvellement, ou nouveau compte Chaman</small></button>
        </div>` : ''}
      ${!d.keep || !cred ? `
      <h3 class="section-label">1. Créer la paire (une fois, sur votre ordinateur)</h3>
      <p class="muted">Certificat auto-signé, RSA 2048 bits, SHA-256, avec OpenSSL :</p>
      <pre class="hr-code">openssl req -x509 -newkey rsa:2048 -sha256 -days 730 -nodes \\
  -keyout societe-dimona.key -out societe-dimona.crt \\
  -subj "/CN=${esc((d.name || 'Ma société').replace(/["\\]/g, ''))} Dimona"</pre>
      <ol class="hr-steps-list">
        <li>Dans Chaman (gestion des accès de la sécurité sociale), créez le compte REST de la société et téléversez-y <strong>le certificat</strong> (.crt) — jamais la clé.</li>
        <li>Chaman vous donne le <strong>Client ID</strong> du compte : vous le saisirez à l’étape suivante.</li>
        <li>Déposez ici le certificat et la clé privée.</li>
      </ol>
      <h3 class="section-label">2. Déposer les fichiers</h3>
      <div class="hr-files">
        <div class="hr-file${d.certificate ? ' is-done' : ''}"><strong>Certificat</strong><p class="muted">${d.certificate ? esc(d.certificateName) + ' ✓' : '.crt, .pem ou .cer'}</p><label class="btn btn--sm">Choisir le fichier<input type="file" data-file="certificate" accept=".crt,.pem,.cer,.der" /></label>${err('certificate')}</div>
        <div class="hr-file${d.private_key ? ' is-done' : ''}"><strong>Clé privée</strong><p class="muted">${d.private_key ? esc(d.keyName) + ' ✓' : '.key ou .pem — reste confidentielle'}</p><label class="btn btn--sm">Choisir le fichier<input type="file" data-file="private_key" accept=".key,.pem" /></label>${err('private_key')}</div>
      </div>
      ${input('passphrase', 'Phrase de passe de la clé (si elle en a une)', 'type="password" autocomplete="off"')}` : ''}`;
  } else if (w.step === 2) {
    card = `<h2>Le compte Chaman</h2><p class="muted">Le Client ID que Chaman a donné au compte REST de la société, et l’environnement où déclarer.</p>
      ${input('client_id', 'Client ID', 'autocomplete="off" spellcheck="false" placeholder="self_service_expeditor_…"')}
      <h3 class="section-label">Environnement</h3>
      <div class="choice-row">
        <button type="button" class="choice-card${d.env === 'SIM' ? ' is-on' : ''}" data-act="setEnv" data-v="SIM"><strong>Simulation</strong><small>des déclarations d’essai, jamais enregistrées dans le fichier du personnel</small></button>
        <button type="button" class="choice-card${d.env === 'PROD' ? ' is-on' : ''}" data-act="setEnv" data-v="PROD"${envs().includes('PROD') ? '' : ' disabled'}><strong>Production</strong><small>${envs().includes('PROD') ? 'les vraies Dimona' : 'pas encore ouverte sur ce serveur'}</small></button>
      </div>${err('env')}`;
  } else {
    const r = w.result;
    card = `<h2>Tester la connexion</h2><p class="muted">Le module demande un jeton d’accès à l’ONSS avec votre certificat. Rien n’est déclaré.</p>
      <div><button type="button" class="btn btn--primary${w.busy ? ' is-busy' : ''}" data-act="testCred"${w.busy ? ' disabled' : ''}>${w.busy ? 'Test en cours…' : 'Tester la connexion'}</button></div>
      ${r ? `<div class="hr-result ${r.ok ? 'is-ok' : 'is-ko'}"><span class="hr-result__icon" aria-hidden="true">${r.ok ? '✓' : '!'}</span><div><h3>${r.ok ? 'Connexion réussie' : 'Connexion refusée'}</h3><p>${esc(r.message)}</p>${!r.ok ? '<p class="muted">Vérifiez le Client ID, et que c’est bien ce certificat qui est déposé dans Chaman pour ce compte.</p>' : ''}</div></div>` : ''}`;
  }
  const c = activeCred(soc);
  const aside = `<aside class="hub-aside"><section class="card hub-aside__card"><span class="hub-aside__kicker">Résumé</span><h3>La connexion en un coup d’œil</h3>
    ${kv([
      ['Société', esc(d.name || 'à saisir')], ['BCE', esc(d.enterprise_number || 'à saisir')],
      ['Boutiques', d.shops.length ? esc(d.shops.map(shopName).join(', ')) : 'à choisir'],
      ['Certificat', d.keep && c ? `jusqu’au ${fmtWhen(c.certificateExpiresAt).slice(0, 10)}${c.certificateAlert ? ` <span class="st st--warn hr-badge-days">${c.certificateDaysLeft} j</span>` : ''}` : (d.certificate && d.private_key ? 'prêt à déposer' : 'à déposer')],
      ['Client ID', esc(d.client_id || 'à saisir')], ['Environnement', d.env === 'PROD' ? 'Production' : 'Simulation'],
      ['Test', w.result ? (w.result.ok ? '<span class="st st--ok">réussi</span>' : '<span class="st st--ko">échec</span>') : (c?.lastTest ? (c.lastTest.ok ? '<span class="st st--ok">réussi</span> ' + fmtWhen(c.lastTest.at) : '<span class="st st--ko">échec</span>') : 'à faire')],
    ])}</section></aside>`;
  const next = ['Suivant : Certificat →', 'Suivant : Compte Chaman →', 'Suivant : Tester →', 'Terminer'][w.step];
  return `<div class="builder">${steps(names, w.step, w.reach ?? 0)}<div class="hub-layout"><div class="hub-main"><section class="card wizard-card">${card}</section>${actions(w.step ? 'Précédent' : null, next, { quitLabel: 'Quitter', disabled: w.step === 3 && !(w.result && w.result.ok) && !(c && c.lastTest && c.lastTest.ok) })}</div>${aside}</div></div>`;
}

async function settingsNext() {
  const w = S.wiz, d = w.data;
  w.errors = {};
  if (w.step === 0) {
    const body = { name: d.name, enterprise_number: d.enterprise_number, onss_employer_id: d.onss_employer_id, joint_commission: d.joint_commission, shops: d.shops };
    const soc = societe();
    const r = soc ? await api('PATCH', `/employers/${soc.id}`, body) : await api('POST', '/employers', body);
    S.societeId = r.id; sessionStorage.setItem('hr.societe', String(r.id));
    await loadSession();
    w.note = 'Société enregistrée.';
  } else if (w.step === 1) {
    const cred = activeCred(societe());
    if (!(d.keep && cred)) {
      if (!d.certificate) w.errors.certificate = 'à déposer';
      if (!d.private_key) w.errors.private_key = 'à déposer';
      if (Object.keys(w.errors).length) return;
    }
    w.note = '';
  } else if (w.step === 2) {
    const cred = activeCred(societe());
    const changed = !(d.keep && cred) || cred.clientId !== d.client_id || cred.env !== d.env;
    if (changed) {
      if (d.keep && cred && !d.certificate) { w.errors.client_id = 'un autre Client ID ou environnement demande de redéposer le certificat (étape 2)'; return; }
      let r;
      try {
        r = await api('POST', '/credentials', { societe_id: S.societeId, env: d.env, client_id: d.client_id, certificate: d.certificate, private_key: d.private_key, passphrase: d.passphrase });
      } catch (e) {
        w.errors = fieldErrors(e);
        if (e.fields.certificate || e.fields.private_key) w.step = 1;
        throw e;
      }
      d.credId = r.id; d.keep = true; d.certificate = null; d.private_key = null; d.passphrase = '';
      await loadSession();
      w.note = 'Certificat et compte enregistrés.';
      w.result = null;
    } else {
      d.credId = cred.id;
    }
  } else {
    go('#equipe');
    return;
  }
  w.step++;
  w.reach = Math.max(w.reach ?? 0, w.step);
}

/* ------------------------------------------------------------------ assistant : Engager (Dimona IN) */
function startHire(employeeId, workerId) {
  const soc = societe();
  S.wiz = {
    kind: 'hire', step: 0, errors: {}, busy: false, note: '', mode: 'team', pickFilter: '', decl: null,
    data: {
      employee_id: employeeId || null, worker_id: workerId || null, given_name: '', family_name: '', shop_id: soc?.shops[0] || '',
      ssin: '', ssin_source: 'input', profileSsin: 'none', existingSsin: false, birth_date: '',
      worker_type: 'OTH', joint_commission: soc?.jointCommission || '', start_date: today(), start_hour: '', end_date: '', end_hour: '', planned_hours: '',
    },
  };
  if (employeeId) pickEmployee(employeeId, true);
  if (workerId) pickWorker(workerId);
}

async function pickEmployee(id, silent) {
  const w = S.wiz, d = w.data;
  d.employee_id = id; d.worker_id = null; w.mode = 'team';
  try {
    const p = await api('GET', `/prefill?societe_id=${S.societeId}&employee_id=${id}`);
    d.given_name = p.givenName || ''; d.family_name = p.familyName || ''; d.birth_date = p.birthDate || ''; d.shop_id = p.shopId;
    d.profileSsin = p.profileSsin; d.ssin_source = p.profileSsin === 'valid' ? 'profile' : 'input';
    if (p.worker) {
      d.worker_id = p.worker.id; d.existingSsin = p.worker.hasSsin; if (p.worker.hasSsin) d.ssin_source = 'existing';
      if (p.worker.workerType) d.worker_type = p.worker.workerType;
      if (p.worker.jointCommission) d.joint_commission = p.worker.jointCommission;
      if (p.worker.plannedHours) d.planned_hours = p.worker.plannedHours;
      if (p.worker.birthDate) d.birth_date = p.worker.birthDate;
    } else { d.existingSsin = false; }
    if (p.profileStatus === 'etudiant' && !p.worker) d.worker_type = 'STU';
    if (p.profileStatus === 'flexi' && !p.worker) d.worker_type = 'FLX';
    if (!silent) { w.step = 1; w.reach = Math.max(w.reach ?? 0, 1); }
  } catch (e) { toast(e.message, true); }
  render();
}

async function pickWorker(id) {
  try {
    const x = await api('GET', `/workers/${id}`);
    const d = S.wiz.data;
    Object.assign(d, { worker_id: x.id, employee_id: x.erpEmployeeId, given_name: x.givenName, family_name: x.familyName, shop_id: x.shopId || d.shop_id,
      existingSsin: x.hasSsin, ssin_source: x.hasSsin ? 'existing' : 'input', birth_date: x.birthDate || '', worker_type: x.workerType || 'OTH',
      joint_commission: x.jointCommission || d.joint_commission, planned_hours: x.plannedHours || '' });
    S.wiz.mode = x.erpEmployeeId ? 'team' : 'new';
    S.wiz.step = 1; S.wiz.reach = 1;
  } catch (e) { toast(e.message, true); }
  render();
}

function hireWizard() {
  const w = S.wiz, d = w.data;
  const names = ['Qui', 'Identité', 'Contrat', 'Vérifier', 'Suivi'];
  const typesList = S.session.workerTypes || [];
  const t = typesList.find((x) => x.code === d.worker_type);
  let card = '';
  if (w.step === 0) {
    const cands = (S.team?.items || []).filter((r) => r.inPanel && r.dimona !== 'declared' && r.dimona !== 'pending' && r.active !== false)
      .filter((r) => !w.pickFilter || r.name.toLowerCase().includes(w.pickFilter.toLowerCase()));
    card = `<h2>Qui engagez-vous ?</h2><p class="muted">Un membre du personnel de vos boutiques, ou une personne qui n’est pas encore au panel.</p>
      <nav class="hub-sub"><button type="button" class="hub-sub__tab${w.mode === 'team' ? ' is-on' : ''}" data-act="hireMode" data-v="team">Membre de l’équipe</button><button type="button" class="hub-sub__tab${w.mode === 'new' ? ' is-on' : ''}" data-act="hireMode" data-v="new">Nouvelle personne</button></nav>
      ${w.mode === 'team' ? `<label class="field">Rechercher <input type="search" data-f="pickFilter" data-wizroot="1" value="${esc(w.pickFilter)}" placeholder="Nom" /></label>
        <div class="hr-pick">${cands.map((r) => `<button type="button" class="choice-card${d.employee_id === r.employeeId ? ' is-on' : ''}" data-act="pickEmployee" data-id="${r.employeeId}"><strong>${esc(r.name)}</strong><small>${esc(r.shopName || '')}${r.dimona === 'problem' ? ' · dernière Dimona à corriger' : ''}</small></button>`).join('') || '<p class="muted">Tout le personnel du panel a déjà une Dimona en cours. Pour quelqu’un d’autre : « Nouvelle personne ».</p>'}</div>`
      : `<div class="hr-two">${input('given_name', 'Prénom', 'autocomplete="off" maxlength="48"')}${input('family_name', 'Nom', 'autocomplete="off" maxlength="128"')}</div>
        <label class="field field--grow${bad('shop_id')}">Boutique<select data-f="shop_id">${(societe()?.shops || []).map((s) => `<option value="${esc(s)}"${String(d.shop_id) === String(s) ? ' selected' : ''}>${esc(shopName(s))}</option>`).join('')}</select>${err('shop_id')}</label>`}`;
  } else if (w.step === 1) {
    card = `<h2>Son identité</h2><p class="muted">Le numéro national (NISS) identifie la personne à l’ONSS. Il est gardé chiffré et n’apparaît plus qu’en clair à la demande, écrite au journal.</p>
      <div class="hr-two">${input('given_name', 'Prénom', 'autocomplete="off" maxlength="48"')}${input('family_name', 'Nom', 'autocomplete="off" maxlength="128"')}</div>
      <h3 class="section-label">Numéro national (NISS)</h3>
      <div class="choice-row">
        ${d.existingSsin ? `<button type="button" class="choice-card${d.ssin_source === 'existing' ? ' is-on' : ''}" data-act="ssinSource" data-v="existing"><strong>Le NISS déjà enregistré</strong><small>••••••.•••.••</small></button>` : ''}
        ${d.profileSsin === 'valid' ? `<button type="button" class="choice-card${d.ssin_source === 'profile' ? ' is-on' : ''}" data-act="ssinSource" data-v="profile"><strong>Celui de son profil</strong><small>encodé par l’employé dans l’app</small></button>` : ''}
        ${d.existingSsin || d.profileSsin === 'valid' ? `<button type="button" class="choice-card${d.ssin_source === 'input' ? ' is-on' : ''}" data-act="ssinSource" data-v="input"><strong>Saisir un autre numéro</strong><small>carte d’identité ou de séjour</small></button>` : ''}
      </div>
      ${d.profileSsin === 'invalid' ? '<p class="muted hr-hint">Le numéro de son profil ne passe pas le contrôle : saisissez-le à partir de sa carte.</p>' : ''}
      ${d.ssin_source === 'input' ? input('ssin', 'NISS', 'inputmode="numeric" autocomplete="off" placeholder="00.00.00-000.00"', d.ssin && nissValide(d.ssin) ? '✓ numéro valide' : '11 chiffres, au dos de la carte d’identité') : ''}
      ${err('ssin')}
      ${input('birth_date', 'Date de naissance', 'type="date"')}`;
  } else if (w.step === 2) {
    card = `<h2>Son contrat</h2><p class="muted">Ce que l’ONSS doit savoir de l’occupation : le type de travailleur, la commission paritaire et les dates.</p>
      <h3 class="section-label">Type de travailleur</h3>
      <div class="choice-row">${typesList.map((x) => `<button type="button" class="choice-card${d.worker_type === x.code ? ' is-on' : ''}" data-act="setType" data-v="${x.code}"><strong>${esc(x.label)}</strong><small>${esc(x.code)}</small></button>`).join('')}</div>
      ${t ? `<p class="muted hr-hint">${esc(t.help)}</p>` : ''}${err('worker_type')}
      <div class="hr-two">${input('joint_commission', 'Commission paritaire', 'placeholder="118.03"', `Déclarée à l’ONSS sous « ${esc(cpDimona(d.joint_commission))} ».`)}${t && t.plannedHours ? input('planned_hours', 'Heures planifiées sur la période', 'type="number" min="1" max="999" inputmode="numeric"', 'Obligatoire pour un étudiant.') : ''}</div>
      <div class="hr-two">${input('start_date', 'Date d’entrée', 'type="date"')}${input('start_hour', 'Heure de début (facultative)', 'type="time"')}</div>
      <div class="hr-two">${input('end_date', 'Date de fin (si elle est connue)', 'type="date"')}${input('end_hour', 'Heure de fin (facultative)', 'type="time"')}</div>
      ${d.start_date && d.start_date < today() ? '<p class="muted hr-hint">Une Dimona se fait au plus tard au début de l’occupation : l’ONSS signalera une déclaration tardive.</p>' : ''}`;
  } else if (w.step === 3) {
    const cred = activeCred(societe());
    card = `<h2>Vérifier et déclarer</h2><p class="muted">Relisez : la déclaration part à l’ONSS tout de suite.</p>
      ${kv([
        ['Personne', esc(`${d.given_name} ${d.family_name}`)], ['Boutique', esc(shopName(d.shop_id))],
        ['NISS', d.ssin_source === 'input' ? esc(nissFormat(d.ssin)) : (d.ssin_source === 'profile' ? 'celui de son profil' : 'déjà enregistré')],
        ['Naissance', fmtDate(d.birth_date)], ['Type', esc(t ? t.label + ' (' + t.code + ')' : d.worker_type)],
        ['Commission paritaire', `${esc(d.joint_commission || '—')} → déclarée « ${esc(cpDimona(d.joint_commission))} »`],
        ['Entrée', `${fmtDate(d.start_date)}${d.start_hour ? ' à ' + esc(d.start_hour) : ''}`], ['Fin', d.end_date ? `${fmtDate(d.end_date)}${d.end_hour ? ' à ' + esc(d.end_hour) : ''}` : 'non connue'],
        ...(t && t.plannedHours ? [['Heures planifiées', esc(d.planned_hours)]] : []),
        ['Environnement', cred ? (cred.env === 'PROD' ? '<span class="hr-sim hr-sim--prod">Production</span>' : '<span class="hr-sim">Simulation</span> — aucune déclaration réelle') : '<span class="st st--ko">aucune connexion ONSS</span>'],
      ])}
      ${Object.keys(w.errors).length ? `<p class="error">${esc(Object.values(w.errors).join(' · '))}</p>` : ''}`;
  } else {
    card = trackCard(w.decl, 'L’entrée');
  }
  const next = ['Suivant : Identité →', 'Suivant : Contrat →', 'Suivant : Vérifier →', 'Déclarer l’entrée', null][w.step];
  const aside = w.step < 4 ? `<aside class="hub-aside"><section class="card hub-aside__card"><span class="hub-aside__kicker">Résumé</span><h3>La Dimona en un coup d’œil</h3>${kv([
    ['Personne', esc(`${d.given_name} ${d.family_name}`.trim() || 'à choisir')], ['Type', esc(t ? t.code : '—')],
    ['Entrée', fmtDate(d.start_date)], ['CP déclarée', esc(cpDimona(d.joint_commission))],
  ])}</section></aside>` : '';
  const tail = w.step === 4
    ? `<div class="wizard-actions"><div class="wizard-actions__group"><a class="btn btn--ghost" href="#equipe">Retour à l’équipe</a></div><div class="wizard-actions__group wizard-actions__group--nav">${w.decl && w.decl.workerId ? `<a class="btn" href="#personne/${w.decl.workerId}">Ouvrir la fiche</a>` : ''}<button type="button" class="btn btn--primary" data-act="hireAgain">Engager quelqu’un d’autre</button></div></div>`
    : actions(w.step ? 'Précédent' : null, next, { quitLabel: 'Quitter sans déclarer', disabled: w.step === 0 && w.mode === 'team' && !d.employee_id });
  return `<div class="builder">${steps(names, w.step, w.step === 4 ? 4 : Math.min(w.reach ?? 0, 3))}<div class="hub-layout"><div class="hub-main"><section class="card wizard-card">${card}</section>${tail}</div>${aside}</div></div>`;
}

function hireCheck(step) {
  const d = S.wiz.data, e = {};
  if (step === 0 && S.wiz.mode === 'new') {
    if (!d.given_name.trim()) e.given_name = 'obligatoire';
    if (!d.family_name.trim()) e.family_name = 'obligatoire';
  }
  if (step === 1) {
    if (!d.given_name.trim()) e.given_name = 'obligatoire';
    if (!d.family_name.trim()) e.family_name = 'obligatoire';
    if (d.ssin_source === 'input' && !nissValide(d.ssin)) e.ssin = d.ssin ? 'numéro invalide (chiffres de contrôle)' : 'obligatoire pour une Dimona';
  }
  if (step === 2) {
    const t = (S.session.workerTypes || []).find((x) => x.code === d.worker_type);
    if (!t) e.worker_type = 'à choisir';
    if (!d.start_date) e.start_date = 'obligatoire';
    if (d.end_date && d.end_date < d.start_date) e.end_date = 'avant la date d’entrée';
    if (t && t.plannedHours && !(Number(d.planned_hours) >= 1 && Number(d.planned_hours) <= 999)) e.planned_hours = 'entre 1 et 999 heures';
  }
  return e;
}

async function hireNext() {
  const w = S.wiz, d = w.data;
  w.errors = hireCheck(w.step);
  if (Object.keys(w.errors).length) return;
  if (w.step < 3) { w.step++; w.reach = Math.max(w.reach ?? 0, w.step); return; }
  // Étape 3 : enregistrer la fiche, puis déclarer.
  const body = { societe_id: S.societeId, id: d.worker_id || undefined, erp_employee_id: w.mode === 'team' ? d.employee_id : undefined, shop_id: w.mode === 'new' ? d.shop_id : undefined,
    given_name: d.given_name, family_name: d.family_name, birth_date: d.birth_date || undefined, worker_type: d.worker_type,
    joint_commission: d.joint_commission, planned_hours: d.planned_hours === '' ? null : Number(d.planned_hours) };
  if (d.ssin_source === 'input') body.ssin = digits(d.ssin);
  if (d.ssin_source === 'profile') body.use_profile_ssin = true;
  let worker;
  try { worker = await api('POST', '/workers', body); } catch (e) {
    w.errors = fieldErrors(e); w.step = e.fields.ssin || e.fields.given_name || e.fields.family_name ? 1 : (e.fields.shop_id ? 0 : 3);
    throw e;
  }
  d.worker_id = worker.id; d.existingSsin = worker.hasSsin; d.ssin_source = 'existing'; d.ssin = '';
  try {
    w.decl = await api('POST', '/declarations', { type: 'IN', worker_id: worker.id, start_date: d.start_date, start_hour: d.start_hour || null, end_date: d.end_date || null,
      end_hour: d.end_hour || null, worker_type: d.worker_type, joint_commission: d.joint_commission, planned_hours: d.planned_hours === '' ? null : Number(d.planned_hours) });
  } catch (e) {
    w.errors = fieldErrors(e);
    if (e.fields.planned_hours || e.fields.start_date || e.fields.end_date || e.fields.worker_type || e.fields.joint_commission) w.step = 2;
    throw e;
  }
  S.team = null;
  w.step = 4; w.reach = 4;
  track(w.decl.id);
}

/* ------------------------------------------------------------------ suivi d'une déclaration envoyée */
function trackCard(dcl, what) {
  if (!dcl) return '<p class="muted">Envoi…</p>';
  const s = dcl.status;
  const cls = s === 'ACCEPTED' ? 'is-ok' : (s === 'WARNING' ? 'is-warn' : (s === 'REFUSED' || s === 'ERROR' ? 'is-ko' : ''));
  const title = { PENDING: `${what} est reçue par l’ONSS`, WAITING_SIGEDIS: 'En attente de l’identification (SIGeDIS)', ACCEPTED: `${what} est acceptée`, WARNING: `${what} est acceptée, avec un avertissement`, REFUSED: `${what} est refusée`, ERROR: 'La déclaration n’a pas abouti' }[s] || s;
  const text = {
    PENDING: 'L’ONSS la traite (en général en quelques secondes, parfois davantage). Cette page relit le résultat ; vous pouvez la quitter, le module continue seul.',
    WAITING_SIGEDIS: 'La personne doit encore être identifiée par SIGeDIS. Le module continue de relire le résultat pendant 24 h.',
    ACCEPTED: 'La période Dimona est ouverte dans le fichier du personnel.', WARNING: 'La période est ouverte. Lisez la remarque de l’ONSS ci-dessous.',
    REFUSED: 'Aucune période n’a été créée. Corrigez ce que l’ONSS signale, puis déclarez à nouveau.', ERROR: dcl.lastError || '',
  }[s] || '';
  const anomalies = (dcl.anomalies || []).map((a) => `<li class="${s === 'REFUSED' ? 'is-ko' : 'is-warn'}">${esc(a.fr || a.nl || '')}${a.errorId ? ` <code>${esc(a.errorId)}</code>` : ''}${a.nl && a.fr ? `<small lang="nl">${esc(a.nl)}</small>` : ''}</li>`).join('');
  return `<h2>Suivi</h2>
    <div class="hr-result ${cls}"><span class="hr-result__icon" aria-hidden="true">${{ 'is-ok': '✓', 'is-warn': '!', 'is-ko': '✕' }[cls] || '…'}</span><div><h3>${esc(title)}</h3><p>${esc(text)}</p></div></div>
    ${kv([['Statut', chip(s)], ['N° de déclaration ONSS', dcl.dimonaDeclarationId ? String(dcl.dimonaDeclarationId) : '—'], ['N° de période', dcl.dimonaPeriodId ? String(dcl.dimonaPeriodId) : '—'], ['Relectures', String(dcl.attempts)], ['Environnement', esc(dcl.env)]])}
    ${anomalies ? `<h3 class="section-label">Remarques de l’ONSS</h3><ul class="hr-anomalies">${anomalies}</ul>` : ''}
    ${s === 'PENDING' || s === 'WAITING_SIGEDIS' || (s === 'ERROR' && !dcl.dimonaDeclarationId) ? `<div><button type="button" class="btn" data-act="refresh" data-id="${dcl.id}">Actualiser maintenant</button></div>` : ''}`;
}

/** Relit le résultat pendant que l'écran est ouvert (5 s, 15 s, 30 s, puis toutes les 30 s pendant 3 min) ; le cron prend le relais. */
function track(id) {
  clearTimeout(track.t);
  const delays = [5000, 10000, 15000, 30000, 30000, 30000, 30000, 30000];
  let i = 0;
  const tick = async () => {
    const w = S.wiz;
    if (!w || !w.decl || w.decl.id !== id) return;
    if (!['PENDING', 'WAITING_SIGEDIS'].includes(w.decl.status)) return;
    try { w.decl = await api('POST', `/declarations/${id}/refresh`); } catch (e) { /* le cron relira */ }
    render();
    if (i < delays.length && ['PENDING', 'WAITING_SIGEDIS'].includes(w.decl.status)) track.t = setTimeout(tick, delays[i++]);
  };
  track.t = setTimeout(tick, delays[i++]);
}

/* ------------------------------------------------------------------ petits assistants : sortie, modification, annulation */
function startSmall(kind) {
  const p = S.worker.activePeriod;
  S.wiz = { kind, step: 0, errors: {}, busy: false, note: '', decl: null,
    data: { end_date: kind === 'out' ? today() : '', start_date: '', start_hour: '', end_hour: '', planned_hours: '', period: p } };
}

function smallWizard() {
  const w = S.wiz, d = w.data, p = d.period, wk = S.worker;
  const label = { out: 'la sortie', update: 'la modification', cancel: 'l’annulation' }[w.kind];
  const names = w.kind === 'cancel' ? ['Confirmer', 'Suivi'] : ['Saisie', 'Vérifier', 'Suivi'];
  const last = names.length - 1;
  const per = `${fmtDate(p.startDate)} → ${p.endDate ? fmtDate(p.endDate) : 'sans fin'}`;
  let card = '';
  if (w.step === last) card = trackCard(w.decl, { out: 'La sortie', update: 'La modification', cancel: 'L’annulation' }[w.kind]);
  else if (w.kind === 'cancel') {
    card = `<h2>Annuler la Dimona de ${esc(wk.name)}</h2><p class="muted">À faire quand la personne n’est finalement pas entrée en service. La période ${esc(per)} (n° ${p.dimonaPeriodId}) sera annulée à l’ONSS.</p>
      <p class="muted">Si elle a travaillé, ce n’est pas une annulation : déclarez sa sortie.</p>`;
  } else if (w.step === 0 && w.kind === 'out') {
    card = `<h2>Déclarer la sortie de ${esc(wk.name)}</h2><p class="muted">Période en cours : ${esc(per)}. La date de sortie est le dernier jour de l’occupation.</p>${input('end_date', 'Date de sortie', 'type="date"')}`;
  } else if (w.step === 0) {
    card = `<h2>Modifier la Dimona de ${esc(wk.name)}</h2><p class="muted">Période en cours : ${esc(per)}. Ne remplissez que ce qui change.</p>${err('changes')}
      <div class="hr-two">${input('start_date', 'Nouvelle date d’entrée', 'type="date"')}${input('start_hour', 'Nouvelle heure de début', 'type="time"')}</div>
      <div class="hr-two">${input('end_date', 'Nouvelle date de fin', 'type="date"')}${input('end_hour', 'Nouvelle heure de fin', 'type="time"')}</div>
      ${input('planned_hours', 'Heures planifiées', 'type="number" min="1" max="999"', p.plannedHours ? `Actuellement ${p.plannedHours} h.` : '')}`;
  } else {
    const rows = w.kind === 'out' ? [['Sortie', fmtDate(d.end_date)]] : [['Entrée', d.start_date ? fmtDate(d.start_date) : 'inchangée'], ['Heure de début', d.start_hour || 'inchangée'], ['Fin', d.end_date ? fmtDate(d.end_date) : 'inchangée'], ['Heure de fin', d.end_hour || 'inchangée'], ['Heures planifiées', d.planned_hours || 'inchangées']];
    card = `<h2>Vérifier ${esc(label)}</h2>${kv([['Personne', esc(wk.name)], ['Période', `${esc(per)} · n° ${p.dimonaPeriodId}`], ...rows])}${Object.keys(w.errors).length ? `<p class="error">${esc(Object.values(w.errors).join(' · '))}</p>` : ''}`;
  }
  const nextLabel = w.step === last ? null : (w.step === last - 1 ? { out: 'Déclarer la sortie', update: 'Déclarer la modification', cancel: 'Annuler la Dimona' }[w.kind] : 'Suivant : Vérifier →');
  const tail = w.step === last
    ? `<div class="wizard-actions"><div class="wizard-actions__group"></div><div class="wizard-actions__group wizard-actions__group--nav"><button type="button" class="btn btn--primary" data-act="smallDone">Retour à la fiche</button></div></div>`
    : actions(w.step ? 'Précédent' : null, nextLabel, { quit: `#personne/${wk.id}`, quitLabel: 'Ne rien déclarer' });
  return `<div class="builder">${steps(names, w.step, w.step)}<section class="card wizard-card">${card}</section>${tail}</div>`;
}

async function smallNext() {
  const w = S.wiz, d = w.data;
  const last = w.kind === 'cancel' ? 1 : 2;
  w.errors = {};
  if (w.step === 0 && w.kind === 'out' && !d.end_date) { w.errors.end_date = 'obligatoire'; return; }
  if (w.step === 0 && w.kind === 'update' && !['start_date', 'start_hour', 'end_date', 'end_hour', 'planned_hours'].some((k) => d[k])) { w.errors.changes = 'indiquez au moins une donnée à modifier'; return; }
  if (w.step < last - 1) { w.step++; return; }
  const type = { out: 'OUT', update: 'UPDATE', cancel: 'CANCEL' }[w.kind];
  const body = { type, worker_id: S.worker.id, period_id: d.period.id };
  if (w.kind === 'out') body.end_date = d.end_date;
  if (w.kind === 'update') for (const k of ['start_date', 'start_hour', 'end_date', 'end_hour', 'planned_hours']) if (d[k]) body[k] = d[k];
  try { w.decl = await api('POST', '/declarations', body); } catch (e) { w.errors = fieldErrors(e); w.step = w.kind === 'cancel' ? 0 : 1; throw e; }
  w.step = last;
  track(w.decl.id);
}

/* ------------------------------------------------------------------ gestes */
async function act(name, ds, el) {
  const w = S.wiz;
  try {
    switch (name) {
      case 'loginBo': S.loginBo = ds.bo; S.loginError = null; render(); break;
      case 'logout': {
        const c = S.session.console;
        try { if (c.bo) await consoleCall(c.bo, 'logout', {}, c.csrf); } catch (e) { /* la session est peut-être déjà close */ }
        S.wiz = null; S.team = null; S.worker = null; sessionStorage.removeItem('hr.societe');
        await loadSession(); render(); break;
      }
      case 'open': go(ds.href); break;
      case 'pwdOpen': S.pwd = { error: null, busy: false, done: false }; render(); app.querySelector('[data-form="pwd"] input')?.focus(); break;
      case 'pwdClose': S.pwd = null; render(); break;
      case 'step': if (w && Number(ds.i) <= (w.reach ?? w.step)) { w.step = Number(ds.i); w.errors = {}; render(); } break;
      case 'prev': if (w && w.step > 0) { w.step--; w.errors = {}; render(); } break;
      case 'quit': S.wiz = null; break;
      case 'next':
        if (!w || w.busy) return;
        w.busy = true; render();
        try {
          if (w.kind === 'settings') await settingsNext();
          else if (w.kind === 'hire') await hireNext();
          else await smallNext();
        } catch (e) {
          if (w.kind === 'settings' && e.fields && !Object.keys(w.errors).length) w.errors = fieldErrors(e);
          toast(e.message, true);
        }
        w.busy = false; render(); break;
      case 'toggleShop': { const i = w.data.shops.indexOf(ds.id); if (i >= 0) w.data.shops.splice(i, 1); else w.data.shops.push(ds.id); render(); break; }
      case 'setKeep': w.data.keep = ds.v === '1'; render(); break;
      case 'setEnv': w.data.env = ds.v; render(); break;
      case 'testCred': {
        w.busy = true; render();
        try { w.result = await api('POST', `/credentials/${w.data.credId}/test`); await loadSession(); } catch (e) { w.result = { ok: false, message: e.message }; }
        w.busy = false; w.reach = 3; render(); break;
      }
      case 'hireMode': w.mode = ds.v; if (ds.v === 'new') { Object.assign(w.data, { employee_id: null, worker_id: null, given_name: '', family_name: '', birth_date: '', profileSsin: 'none', existingSsin: false, ssin_source: 'input' }); } render(); break;
      case 'pickEmployee': await pickEmployee(Number(ds.id)); break;
      case 'ssinSource': w.data.ssin_source = ds.v; render(); break;
      case 'setType': w.data.worker_type = ds.v; render(); break;
      case 'hireAgain': S.wiz = null; go('#engager'); startHire(null); render(); break;
      case 'hireWorker': S.wiz = null; location.hash = '#engager'; startHire(null, Number(ds.id)); break;
      case 'small': startSmall(ds.kind); render(); break;
      case 'smallDone': S.wiz = null; loadView(); break;
      case 'refresh': {
        const d = await api('POST', `/declarations/${ds.id}/refresh`);
        if (w && w.decl && w.decl.id === d.id) w.decl = d;
        if (S.view === 'personne') S.worker = await api('GET', `/workers/${S.param}`);
        toast(`Statut : ${(ST[d.status] || [0, d.status])[1]}`);
        render(); break;
      }
      case 'sync': {
        const r = await api('POST', '/periods/sync', { worker_id: S.worker.id });
        toast(`${r.items.length} période(s) connue(s) de l’ONSS.`);
        S.worker = await api('GET', `/workers/${S.worker.id}`); render(); break;
      }
      case 'reveal': {
        const r = await api('POST', `/workers/${S.worker.id}/ssin`);
        S.revealed = { id: S.worker.id, ssin: r.ssin };
        clearTimeout(act.t); act.t = setTimeout(() => { S.revealed = null; render(); }, 30000);
        render(); break;
      }
      case 'hide': S.revealed = null; render(); break;
      case 'declFilter': S.declFilter = ds.v; render(); break;
      default: break;
    }
  } catch (e) {
    toast(e.message, true);
  }
}

app.addEventListener('click', (e) => {
  const t = e.target.closest('[data-act]');
  if (!t || t.disabled) return;
  const a = t.dataset.act;
  if (t.tagName === 'A' && (a === 'quit')) { act(a, t.dataset, t); return; }
  if (t.tagName === 'A' && a === 'hireWorker') { e.preventDefault(); act(a, t.dataset, t); return; }
  if (t.tagName === 'A') return;
  e.preventDefault();
  act(a, t.dataset, t);
});

app.addEventListener('input', (e) => {
  const t = e.target;
  const k = t.dataset && t.dataset.f;
  if (!k) return;
  if (k === 'filter') { S.filter = t.value; const pos = t.selectionStart; render(); const n = app.querySelector('[data-f="filter"]'); if (n) { n.focus(); n.setSelectionRange(pos, pos); } return; }
  if (k === 'pickFilter') { S.wiz.pickFilter = t.value; const pos = t.selectionStart; render(); const n = app.querySelector('[data-f="pickFilter"]'); if (n) { n.focus(); n.setSelectionRange(pos, pos); } return; }
  if (S.wiz) {
    S.wiz.data[k] = t.value;
    if (S.wiz.errors[k]) { delete S.wiz.errors[k]; const f = t.closest('.field, .field--grow'); if (f) { f.classList.remove('is-bad'); const er = f.querySelector('.field__error'); if (er) er.remove(); } }
    if (k === 'joint_commission' || k === 'ssin') {
      const hint = t.parentElement.querySelector('.hr-hint');
      if (hint && k === 'joint_commission') hint.textContent = `Déclarée à l’ONSS sous « ${cpDimona(t.value)} ».`;
      if (hint && k === 'ssin') hint.textContent = nissValide(t.value) ? '✓ numéro valide' : '11 chiffres, au dos de la carte d’identité';
    }
  }
});

app.addEventListener('change', async (e) => {
  const t = e.target;
  if (t.dataset.change === 'societe') {
    S.societeId = Number(t.value); sessionStorage.setItem('hr.societe', t.value);
    S.team = null; S.decls = null; S.audit = null; S.wiz = null; route(); return;
  }
  if (t.dataset.f && t.tagName === 'SELECT' && S.wiz) { S.wiz.data[t.dataset.f] = t.value; return; }
  const f = t.dataset.file;
  if (!f || !t.files || !t.files[0]) return;
  const file = t.files[0];
  if (file.size > 64 * 1024) { toast('Fichier trop gros pour un certificat ou une clé.', true); return; }
  const buf = new Uint8Array(await file.arrayBuffer());
  const text = new TextDecoder('utf-8', { fatal: false }).decode(buf);
  // PEM : le texte tel quel ; DER (binaire) : en base64, le serveur le convertit.
  const content = text.includes('-----BEGIN ') ? text : btoa(String.fromCharCode(...buf));
  S.wiz.data[f] = content;
  S.wiz.data[f === 'certificate' ? 'certificateName' : 'keyName'] = file.name;
  delete S.wiz.errors[f];
  t.value = '';
  render();
});

app.addEventListener('submit', async (e) => {
  const form = e.target;
  if (form.dataset.form === 'pwd') {
    e.preventDefault();
    const fd = new FormData(form);
    const nw = String(fd.get('new') || '');
    S.pwd.error = null;
    if (nw.length < 12) S.pwd.error = 'Le nouveau mot de passe doit faire 12 caractères au moins.';
    else if (nw !== String(fd.get('again') || '')) S.pwd.error = 'Les deux saisies du nouveau mot de passe diffèrent.';
    if (S.pwd.error) { render(); return; }
    S.pwd.busy = true; render();
    try {
      const c = S.session.console;
      await consoleCall(c.bo, 'password', { current: fd.get('current'), new: nw }, c.csrf);
      S.pwd = { done: true };
    } catch (err) {
      S.pwd = { error: err.message, busy: false, done: false };
    }
    render();
    return;
  }
  if (form.dataset.form !== 'login') return;
  e.preventDefault();
  const fd = new FormData(form);
  S.loginBusy = true; S.loginError = null; render();
  try {
    await consoleCall(S.loginBo, 'login', { email: fd.get('email'), password: fd.get('password') });
    await loadSession();
    if (!me()) S.loginError = 'Connexion acceptée par la console, mais la session n’est pas lisible ici : la page doit être ouverte en https.';
  } catch (err) {
    S.loginError = err.message;
  }
  S.loginBusy = false;
  render();
  if (me()) route();
});

window.addEventListener('hashchange', route);

(async () => {
  try { await loadSession(); } catch (e) { app.innerHTML = `<p class="error hr-boot">Le module RH ne répond pas : ${esc(e.message)}</p>`; return; }
  render();
  if (me()) route();
})();
