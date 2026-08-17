/* Cockpit CEO — logique applicative.
 * Port fidèle de la classe Component du prototype Design Component :
 * même état, mêmes calculs, mêmes libellés. Rendu : templates.js (HTML string
 * + délégation d'événements), données : api.js (REST, repli vide hors-ligne).
 * Chaque mutation est répercutée sur l'API quand elle est joignable (source === 'api').
 */
import { load, write, readOne, API_BASE, authStatus, authSubmit, authLogout, apiTraces, apiTracesRaz } from './api.js';
import { render as tplRender } from './templates.js';

function escHtml(v){
  return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

class App {
  constructor(root){
    this.root = root;
    this.state = { ready: false, screen: 'taches', bStore: 'cha', tkStore: 'tous', openProjId: null,
      sortKey: 'caPct', sortDir: -1, zoneF: 'Toutes les zones', hmMetric: 'pct', hmYear: null, hmHover: null,
      horizon: 'h1', logType: 'Tous les types', logQui: 'Tous les auteurs', logQ: '', rel: null, toast: null,
      sFood: null, sLabour: null, statutOv: {}, familleOv: {}, relanced: {}, logsExtra: [], tpl: {},
      repFreq: {}, repDest: {}, repCc: {}, repPostes: {}, repPrev: null, repPrevTab: 'pdf', alertOn: {},
      np: null, nt: null, encStore: 'cha', encDraft: {}, openCards: {}, openInfo: {}, tkWho: 'all', tkOv: {},
      navOpen: {}, scDraft: {}, pdWaste: null,   // sous-menus ; brouillon scoring ; modale perte par magasin
      userPanel: false, userDraft: {},   // panneau « Mon compte » (identité + compte API)
      // Brouillon de validation par tâche : { note, famille, type, commentaire }.
      // Il ne part qu'au clic sur « Valider » — une étoile touchée par erreur
      // ne doit pas clôturer une tâche.
      tkVal: {},
      // Suivi : la période affichée, les données du serveur, et le
      // brouillon de traitement par signalement.
      suiviPeriode: 'semaine', suiviData: null, suiviNote: {},
      bScope: 'shop', repFFreq: null, repFEtat: null, repFType: null, tplAxe: null,
      pwaType: 'gestion:month', pwaScope: 'all', gate: null,
      pdCat: 'Toutes les catégories', pdSort: 'score' };
    this._h = [];
    this._tt = null;
    this._lastEn = null;
    this.bindEvents();
  }

  async start(){
    // Authentification intégrée : si l'API répond et que la session n'est pas
    // ouverte, afficher l'écran de connexion (ou de premier lancement).
    // API injoignable → mode démo, sans authentification.
    const st = await authStatus();
    if (st && !st.authed){
      this.setState({ gate: { mode: st.setup ? 'login' : 'setup', err: '', busy: false } });
      return;
    }
    await this.loadData();
  }

  async loadData(){
    const p = await load();
    this.M = p.M; this.D = p.D; this.meta = p.meta; this.source = p.source;
    this.setState({ ready: true, gate: null });
  }

  /* --- cycle de rendu ------------------------------------------------------- */
  setState(patch){
    Object.assign(this.state, typeof patch === 'function' ? patch(this.state) : patch);
    this.render();
  }
  forceUpdate(){ this.render(); }

  reg(fn){ this._h.push(fn); return this._h.length - 1; }
  render(){
    this._h = [];
    const x = {
      A: fn => fn ? `data-h="${this.reg(fn)}"` : '',
      C: fn => fn ? `data-c="${this.reg(fn)}"` : '',
      I: fn => fn ? `data-i="${this.reg(fn)}"` : '',
      DS: fn => fn ? `data-ds="${this.reg(fn)}"` : '',
      DP: fn => fn ? `data-dp="${this.reg(fn)}"` : '',
      EN: fn => fn ? `data-en="${this.reg(fn)}"` : '',
      SB: fn => fn ? `data-sb="${this.reg(fn)}"` : '',
      PD: fn => fn ? `data-pd="${this.reg(fn)}"` : '',
      esc: escHtml
    };
    const common = this.renderVals();
    const active = document.activeElement;
    const focusId = active && active.id ? active.id : null;
    const selStart = focusId && active.selectionStart != null ? active.selectionStart : null;
    const mainEl = document.getElementById('main-scroll');
    const scrollTop = mainEl ? mainEl.scrollTop : 0;
    // Tout conteneur qui défile et porte `data-scroll` garde sa position. Sans
    // cela, chaque frappe recrée le nœud et le ramène en haut : dans une modale
    // longue, valider une étape renvoyait le lecteur au titre — le « saut ».
    const gardes = {};
    document.querySelectorAll('[data-scroll]').forEach(e => {
      if (e.scrollTop) { gardes[e.getAttribute('data-scroll')] = e.scrollTop; }
    });
    this.root.innerHTML = tplRender(common, x);
    const main2 = document.getElementById('main-scroll');
    if (main2) main2.scrollTop = scrollTop;
    Object.keys(gardes).forEach(k => {
      const e = document.querySelector('[data-scroll="' + k + '"]');
      if (e) { e.scrollTop = gardes[k]; }
    });
    if (focusId){
      const el = document.getElementById(focusId);
      if (el){ el.focus(); if (selStart != null && el.setSelectionRange) el.setSelectionRange(selStart, selStart); }
    }
  }

  bindEvents(){
    const run = (attr, e) => {
      let el = e.target && e.target.closest ? e.target.closest(`[${attr}]`) : null;
      if (!el) return false;
      const fn = this._h[+el.getAttribute(attr)];
      if (fn) fn(e);
      return true;
    };
    this.root.addEventListener('click', e => run('data-h', e));
    this.root.addEventListener('change', e => { if (!run('data-c', e)) run('data-i', e); });
    this.root.addEventListener('input', e => {
      const el = e.target.closest && e.target.closest('[data-i]');
      if (el){ const fn = this._h[+el.getAttribute('data-i')]; if (fn) fn(e); }
    });
    this.root.addEventListener('submit', e => { e.preventDefault(); run('data-sb', e); });
    // Tracé d'un repère sur une photo : le geste se suit à la souris ET au
    // doigt, d'où pointerdown plutôt que mousedown — les consultants annotent
    // sur tablette en boutique.
    this.root.addEventListener('pointerdown', e => run('data-pd', e));
    this.root.addEventListener('dragstart', e => run('data-ds', e));
    this.root.addEventListener('dragover', e => { if (e.target.closest && e.target.closest('[data-dp]')) e.preventDefault(); });
    this.root.addEventListener('drop', e => run('data-dp', e));
    this.root.addEventListener('mouseover', e => {
      const el = e.target.closest && e.target.closest('[data-en]');
      if (!el) return;
      const idx = +el.getAttribute('data-en');
      if (this._lastEn === idx) return;
      this._lastEn = idx;
      const fn = this._h[idx];
      if (fn) fn(e);
    });
  }

  /* --- utilitaires (identiques au prototype) --------------------------------- */
  /**
   * Écriture vers l'API, avec l'échec RENDU VISIBLE.
   *
   * Toutes les écritures de l'application passent ici. Tant que le refus du
   * serveur était ignoré, un budget encodé pouvait être rejeté en 404 sans que
   * l'écran le dise : l'utilisateur voyait sa saisie, le serveur n'avait rien
   * gardé, et personne ne pouvait le savoir avant de recharger.
   */
  apiBase(){ return API_BASE; }
  api(method, path, payload){
    return write(this.source, method, path, payload).then(r => {
      if (r && r.ok === false) { this.notify('Échec de l’enregistrement — ' + (r.error || 'refusé par le serveur')); }
      return r;
    });
  }
  /** Le premier intervenant réel — jamais un identifiant du jeu de démonstration. */
  premierIntervenant(){
    const c = (this.D.consultants || [])[0];
    if (c) { return 'c:' + c.id; }
    const f = (this.D.suppliers || [])[0];
    return f ? 's:' + f.id : '';
  }

  /** Une échéance relative à la date du jour, jamais une date écrite en dur. */
  dansNJours(n){
    const d = new Date(this.M && this.M.TODAY ? this.M.TODAY : Date.now());
    d.setDate(d.getDate() + n);
    return d.toISOString().slice(0, 10);
  }

  notify(msg){ clearTimeout(this._tt); this.setState({ toast: msg }); this._tt = setTimeout(() => this.setState({ toast: null }), 3600); }
  log(type, projet, msg){
    const ts = (this.M ? this.M.TODAY : '2026-07-31') + ' ' + new Date().toTimeString().slice(0, 5);
    this.api('POST', '/journal', { ts: ts + ':00', qui: 'CEO', type, projet: projet || '—', msg });
    this.setState(s => ({ logsExtra: [{ ts, qui: 'CEO', type, projet: projet || '—', msg }, ...s.logsExtra] }));
  }
  fE(n){ return n == null ? '—' : Math.round(n).toLocaleString('fr-BE') + ' €'; }
  // Prix unitaire : deux décimales. fE() arrondit à l'euro, ce qui ramène un
  // prix d'achat de 2,10 € à « 2 € » et rend toute marge illisible.
  fU(n){ return (n == null || !isFinite(n)) ? '—' : n.toLocaleString('fr-BE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €'; }
  // Montant lisible quel que soit l'ordre de grandeur : le réseau se compte en
  // centaines de milliers, un magasin en dizaines de milliers.
  fMt(n){ return (n == null || !isFinite(n)) ? '—'
    : (Math.abs(n) >= 1e6 ? this.fM(n) : (Math.abs(n) >= 1000 ? this.fK(n) : this.fU(n))); }
  fK(n){ return n == null ? '—' : Math.round(n / 1000).toLocaleString('fr-BE') + ' k€'; }
  fM(n){ return (n == null || !isFinite(n)) ? '—' : (n / 1e6).toFixed(1).replace('.', ',') + ' M€'; }
  fP(x, d){ return (x == null || !isFinite(x)) ? '—' : (x * 100).toFixed(d == null ? 1 : d).replace('.', ',') + ' %'; }
  fD(d){ return d ? d.slice(8, 10) + '/' + d.slice(5, 7) : '—'; }
  fDA(d){ return d ? d.slice(8, 10) + '/' + d.slice(5, 7) + '/' + d.slice(0, 4) : '—'; }   // année RÉELLE de la date, jamais figée
  fDY(d){ return d ? d.slice(8, 10) + '/' + d.slice(5, 7) + '/' + d.slice(2, 4) : '—'; }
  pill(pct){ const base = 'display:inline-block;padding:3px 9px;border-radius:999px;font-size:12px;font-weight:500;';
    if (pct == null || !isFinite(pct)) return base + 'background:var(--color-background-secondary);color:var(--color-text-muted)';
    if (pct >= 1) return base + 'background:rgba(45,122,62,0.12);color:#2d7a3e';
    if (pct >= 0.92) return base + 'background:rgba(193,122,42,0.16);color:#8a5a13';
    return base + 'background:rgba(141,29,44,0.10);color:#8D1D2C'; }
  trend(cur, prev, suffix){ if (cur == null || prev == null || !prev) return { txt: '', st: 'font-size:12px' };
    const v = cur / prev - 1; if (!isFinite(v)) return { txt: '', st: 'font-size:12px' }; const up = v >= 0;
    return { txt: (up ? '▲ +' : '▼ ') + (v * 100).toFixed(1).replace('.', ',') + ' %' + (suffix || ''), st: 'font-size:12px;font-weight:500;color:' + (up ? '#2d7a3e' : '#8D1D2C') }; }
  mix(a, b, t){ const h = x => [parseInt(x.slice(1, 3), 16), parseInt(x.slice(3, 5), 16), parseInt(x.slice(5, 7), 16)]; const A = h(a), B = h(b); return 'rgb(' + A.map((v, i) => Math.round(v + (B[i] - v) * t)).join(',') + ')'; }
  statutStyle(s){ const m = { 'À lancer': ['#EAE4DC', '#666666'], 'En cours': ['#F2C9A0', '#6b4420'], 'En retard': ['#8D1D2C', '#ffffff'], 'En pause': ['#F4EFE8', '#666666'], 'Terminé': ['#2d7a3e', '#ffffff'], 'Abandonné': ['#666666', '#ffffff'] }[s] || ['#F4EFE8', '#666666'];
    return 'display:inline-block;padding:3px 10px;border-radius:999px;font-size:11.5px;font-weight:500;background:' + m[0] + ';color:' + m[1]; }
  pStatut(p){ return this.state.statutOv[p.id] || p.statut; }
  pFamille(p){ return this.state.familleOv[p.id] || p.famille || 'Organisation & coûts'; }
  open(){ return this.D.stores.filter(s => s.status === 'Ouvert'); }
  sum(y, m, f){ return this.open().reduce((a, s) => a + ((s.perf[y] && s.perf[y][m] && s.perf[y][m][f]) || 0), 0); }
  /* Dimension temporelle RÉELLE — plus de « 2026 / juillet » figés de la démo.
     exo = exercice (meta), moisIdx = dernier mois (0-11) avec du CA encodé dans
     l'exercice (réseau entier), nMois = nombre de mois écoulés (prorata). */
  exo(){ return (this.meta && this.meta.exercice) || new Date().getFullYear(); }
  moisIdx(){ const y = this.exo(), st = this.open();
    for (let m = 11; m >= 0; m--){ for (const s of st){ const c = s.perf[y] && s.perf[y][m]; if (c && c.ca != null) return m; } }
    const t = (this.M && this.M.TODAY) ? new Date(this.M.TODAY) : new Date();
    return (t.getFullYear() === y && !isNaN(t)) ? t.getMonth() : 11; }
  nMois(){ return this.moisIdx() + 1; }
  /* Dernier mois COMPLET : les ratios (food/labour/overhead, CA/ETP) et les
     alertes qui en découlent n'ont de sens que sur un mois entier. Sur le mois
     en cours, ventes et plannings ne couvrent qu'une partie des jours et le
     ratio se lit comme une contre-performance. On recule donc d'un mois quand
     le dernier mois encodé est le mois calendaire courant. */
  moisIdxComplet(){
    const mi = this.moisIdx(), y = this.exo();
    const t = (this.M && this.M.TODAY) ? new Date(this.M.TODAY) : new Date();
    const partiel = !isNaN(t) && t.getFullYear() === y && t.getMonth() === mi;
    if (!partiel) { return mi; }
    for (let m = mi - 1; m >= 0; m--) {
      if (this.open().some(s => { const c = s.perf[y] && s.perf[y][m]; return c && c.ca != null; })) { return m; }
    }
    return mi;
  }
  moisPartiel(){ return this.moisIdxComplet() !== this.moisIdx(); }
  moisLabel(){ return (this.M && this.M.MOIS && this.M.MOIS[this.moisIdx()]) || ''; }
  ownerOf(t){ const c = t.owner.t === 'c'; const p = (c ? this.D.consultants : this.D.suppliers).find(x => x.id === t.owner.id); return { nom: p.nom, email: p.email, type: c ? 'Consultant' : 'Fournisseur' }; }
  taskState(t){ if (t.done) return t.done <= t.due ? 'Livrée à temps' : 'Livrée en retard';
    return t.due < this.M.TODAY ? 'En retard' : 'En cours'; }
  taskPillSt(st){ const m = { 'Livrée à temps': ['rgba(45,122,62,0.12)', '#2d7a3e'], 'Livrée en retard': ['rgba(193,122,42,0.16)', '#8a5a13'], 'En retard': ['rgba(141,29,44,0.12)', '#8D1D2C'], 'En cours': ['var(--color-background-secondary)', '#666666'] }[st];
    return 'display:inline-block;padding:3px 9px;border-radius:999px;font-size:11.5px;font-weight:500;white-space:nowrap;background:' + m[0] + ';color:' + m[1]; }
  tasksFlat(){ const out = []; for (const p of this.D.projects) for (const t of p.taches) out.push({ t, p, st: this.taskState(t), o: this.ownerOf(t) }); return out; }
  ownerStats(){ const map = {}; for (const x of this.tasksFlat()){ const k = x.t.owner.t + ':' + x.t.owner.id; map[k] = map[k] || { done: 0, onTime: 0, lateNow: [], lateEver: 0 };
    if (x.t.done){ map[k].done++; if (x.t.done <= x.t.due) map[k].onTime++; else map[k].lateEver++; }
    if (x.st === 'En retard'){ map[k].lateNow.push(x); map[k].lateEver++; } } return map; }
  cout(p){ return p.couts.reduce((a, c) => a + c.reel, 0); }
  budgetTot(p){ return p.couts.reduce((a, c) => a + c.prevu, 0); }
  avance(p){ const st = this.pStatut(p); if (st === 'Terminé') return 1;
    const tot = p.taches.length + p.jalons.length;
    const d = p.taches.filter(t => t.done).length + p.jalons.filter(j => j.reel).length;
    return tot ? d / tot : 0; }
  tabBtn(act){ return 'border:none;cursor:pointer;font-family:var(--font-ui);font-size:12px;font-weight:500;padding:8px 16px;' + (act ? 'background:var(--color-primary);color:#fff' : 'background:var(--color-surface);color:var(--color-text-muted)'); }
  /* Pondération et seuils du scoring produit : RÉGLAGE serveur (Paramètres),
     jamais des constantes d'écran — c'est ce score qui décide de retirer une
     référence de la gamme. Repli sur les valeurs livrées si absent. */
  scoringCfg(){ const c = (this.meta && this.meta.scoring) || {};
    const p = c.poids || {}, s = c.seuils || {}, mg = c.marge || {};
    const n = (v, d) => { const x = Number(v); return isFinite(x) && x >= 0 ? x : d; };
    return { v: n(p.volume, 40), m: n(p.marge, 30), perte: n(p.perte, 20), comptoir: n(p.comptoir, 10),
      moteur: n(s.moteur, 68), conforter: n(s.conforter, 46),
      mBas: n(mg.bas, 20), mBasNote: n(mg.basNote, 20), mHaut: n(mg.haut, 80), mHautNote: n(mg.hautNote, 100) }; }
  poids(){ const c = this.scoringCfg(); return { v: c.v, m: c.m, perte: c.perte, comptoir: c.comptoir }; }
  /* Taux de marge (0..1) → note sur 100, échelle ABSOLUE à deux bornes :
     sous la borne basse on plafonne à sa note, au-dessus de la haute idem,
     linéaire entre les deux. Une note absolue ne bouge pas quand un AUTRE
     produit change — contrairement à la normalisation relative d'avant. */
  noteMarge(tauxPct){
    const c = this.scoringCfg();
    if (tauxPct == null || !isFinite(tauxPct)) return null;
    const b = c.mBas, h = c.mHaut, nb = c.mBasNote, nh = c.mHautNote;
    if (h <= b) return null;
    if (tauxPct <= b) return nb;
    if (tauxPct >= h) return nh;
    return nb + (nh - nb) * (tauxPct - b) / (h - b); }
  seuilCaEtp(){ const d = (this.meta && this.meta.seuils) || {}; return d.caEtp != null ? +d.caEtp : 13000; }
  seuils(){ const d = (this.meta && this.meta.seuils) || {};
    return { f: this.state.sFood != null ? +this.state.sFood : d.food,
      l: this.state.sLabour != null ? +this.state.sLabour : d.labour, o: d.overhead }; }
  margeAlerts(){ const s = this.seuils(); const out = [];
    for (const st of this.open()){ const r = st.perf[this.exo()][this.moisIdxComplet()];
      if (r.food > s.f) out.push({ store: st.nom, lev: 'food-cost', levNom: 'Food Cost', msg: 'food-cost ' + String(r.food).replace('.', ',') + ' % (seuil ' + s.f + ' %)', action: 'Revoir fiches techniques, contrôle réception ProdAtelier et gestion casse.' });
      if (r.labour > s.l) out.push({ store: st.nom, lev: 'labour-cost', levNom: 'Labour Cost', msg: 'labour-cost ' + String(r.labour).replace('.', ',') + ' % (seuil ' + s.l + ' %)', action: 'Adapter les plannings au flux, suivre le ratio CA/ETP par tranche horaire.' });
      // ETP RÉEL (planning du panel : heures du mois ÷ 168). Sans planning
      // connu, aucune alerte : mieux vaut ne rien dire que déclencher une
      // alerte de dimensionnement d'équipe sur un effectif déduit du CA.
      const sEtp = this.seuilCaEtp();
      if (r.etp != null && r.etp > 0 && r.ca != null) {
        const ce = r.ca / r.etp;
        if (ce < sEtp) out.push({ store: st.nom, lev: 'labour-cost', levNom: 'Labour Cost', msg: 'CA/ETP ' + this.fE(ce) + ' sous le minimum de ' + this.fE(sEtp) + ' (' + r.etp.toFixed(1).replace('.', ',') + ' ETP planifiés)', action: 'Revoir le dimensionnement d’équipe et la productivité horaire.' });
      }
      if (r.overhead > s.o) out.push({ store: st.nom, lev: 'overhead-cost', levNom: 'Overhead Cost', msg: 'overhead ' + String(r.overhead).replace('.', ',') + ' % (seuil ' + String(s.o).replace('.', ',') + ' %)', action: 'Auditer loyer, énergies et abonnements ; renégocier les contrats.' }); }
    return out; }
  openRelTask(x){ const tpl = this.state.tpl; const late = x.st === 'En retard'; const base = this.D.emailTemplates[late ? 1 : 0]; const corps = (tpl[base.id] || base.corps);
    const sub = s => s.replace('{tache}', x.t.nom).replace('{projet}', x.p.nom).replace('{echeance}', this.fDA(x.t.due)).replace('{destinataire}', x.o.nom).replace('{zone}', '');
    this.setState({ rel: { kind: 'task', id: x.t.id, projet: x.p.nom, to: x.o.nom, email: x.o.email, sujet: sub(base.sujet), corps: sub(corps) } }); }
  spark(series, w, h){ const vals = series.filter(v => v != null); if (!vals.length) return ''; const mn = Math.min(...vals), mx = Math.max(...vals); const sp = mx - mn || 1;
    return series.map((v, i) => v == null ? null : (4 + i * (w - 8) / (series.length - 1)).toFixed(1) + ',' + (h - 6 - (v - mn) / sp * (h - 12)).toFixed(1)).filter(Boolean).join(' '); }

  /* --- valeurs de rendu (port de renderVals) --------------------------------- */
  renderVals(){
    const S = this.state;
    if (S.gate){
      const g = S.gate;
      return { gate: {
        mode: g.mode, err: g.err, busy: g.busy,
        titre: g.mode === 'setup' ? 'Premier lancement' : 'Cockpit CEO',
        sub: g.mode === 'setup'
          ? 'Définissez le mot de passe qui protégera le cockpit — 8 caractères minimum. Il sera demandé à chaque connexion.'
          : 'Entrez le mot de passe du cockpit pour continuer.',
        bouton: g.busy ? '…' : (g.mode === 'setup' ? 'Définir et entrer' : 'Se connecter'),
        submit: async () => {
          if (g.busy) return;
          const el = document.getElementById('gate-pass');
          const pw = el ? el.value : '';
          this.setState({ gate: Object.assign({}, g, { busy: true, err: '' }) });
          const r = await authSubmit(g.mode, pw);
          if (r && r.ok){ await this.loadData(); }
          else this.setState({ gate: Object.assign({}, g, { busy: false, err: (r && r.error) || 'Échec — réessayez.' }) });
        }
      } };
    }
    const goTo = id => () => this.setState({ screen: id, hmHover: null });
    const common = {
      ready: S.ready, toast: S.toast,
      relClose: () => this.setState({ rel: null }),
      relSujet: e => this.setState({ rel: Object.assign({}, S.rel, { sujet: e.target.value }) }),
      relCorps: e => this.setState({ rel: Object.assign({}, S.rel, { corps: e.target.value }) }),
      relSend: () => { const r = S.rel; if (!r) return;
        if (r.kind === 'task'){ this.setState(s => ({ relanced: Object.assign({}, s.relanced, { [r.id]: '31/07' }) }));
          this.api('POST', '/tasks/' + r.id + '/reminder', { journal: 'Relance manuelle envoyée à ' + r.to + ' — « ' + r.sujet.replace('[L’Atelier by] ', '') + ' »' }); }
        this.log('Relance', r.projet, 'Relance manuelle envoyée à ' + r.to + ' — « ' + r.sujet.replace('[L’Atelier by] ', '') + ' »');
        this.setState({ rel: null }); this.notify('Relance envoyée à ' + r.to + ' (' + r.email + ')'); },
      rel: S.rel && { to: S.rel.to, email: S.rel.email, sujet: S.rel.sujet, corps: S.rel.corps }
    };
    const titles = {
      seuil: ['Références sous seuil', 'Sortir d’un coup toutes les références dont le score passe sous un seuil, pour arbitrer la gamme. Le score est celui de l’écran de scoring — même calcul, même pondération.'],
      diagnostic: ['Diagnostic API', 'Ce que le cockpit ne peut pas afficher, écran par écran, et les appels qui dépassent deux secondes — ceux dont l’API amont doit être améliorée.'],
      caCampagnes: ['Campagnes commerciales', 'Campagnes du cockpit marketing et contrôle des flux fournisseurs. Lecture seule : une campagne ne s’écrit jamais depuis la centrale.'],
      caDemande: ['Demande de prix', 'Négociation fournisseur en quatre étapes : sélection, consolidation, demande, suivi.'],
      caAchats: ['Suivi fournisseurs', 'Commandes fournisseurs, réception et litiges.'],
      caCommandes: ['Commandes franchisés', 'Commandes des magasins, de la préparation à la livraison.'],
      caStock: ['Stock', 'Stock, seuils et ruptures.'],
      caFacturation: ['Facturation magasins', 'Factures des magasins, TVA calculée ligne à ligne, relances.'],
      caReglages: ['Paramètres — Centrale d’achat', 'Moteur de marge (commission de marque, TVA par défaut, objectifs de négociation) et référentiel fournisseurs.'],
      analyse: ['Analyse dans le temps', 'Trois niveaux : le groupe, la catégorie, la référence. Seuls les groupes sont ventil\u00e9s en chiffre d\u2019affaires et détaillables magasin par magasin ; en dessous l\u2019API ne rend qu\u2019un volume réseau. Chaque point est comparé à la même étendue un an plus tôt.'],
      catalogue: ['Catalogue produit', 'Les références du réseau avec leur prix, leur coût matière et leurs DEUX marges : brute, puis nette après commission de marque — celle que pilote la centrale d\u2019achat. Filtrez, puis ouvrez une référence pour compléter sa fiche de production.'],
      assortiment: ['Assortiment obligatoire', 'Les références qu\u2019une boutique doit proposer en permanence, et la quantité minimale à tenir. Cochez une référence pour l\u2019imposer au réseau.'],
      planogramme: ['Planogramme comptoir', 'Où chaque référence se place au comptoir : zone, meuble, niveau. Un emplacement vide se distingue d\u2019une référence jamais placée.'],
      production: ['Suivi de production', 'Ce qui a été produit et ce qui a été jeté, par boutique et par référence. Le taux de perte se calcule sur les ventes, pas sur les fournées déclarées.'],
      exploitation: ['Exploitation', 'Le P&L court de chaque magasin : chiffre d\u2019affaires du jour, de la semaine et du mois, avec le budget en regard du réel.'], taches: ['Tâches consultants', 'Ce qui attend le consultant : tâches photographiées à noter, ses propres tâches, projets en retard, alertes de marge. Puis sa liste, filtrable par intervenant et par magasin.'], magasins: ['Tableau des magasins', 'Marge, valeur, CA, tickets et panier moyen par magasin — dernier mois encodé, vs N-1 et vs cibles.'], heatmap: ['Heatmap mensuelle', 'Une ligne par magasin, une colonne par mois. Repérez d’un coup d’œil les sur- et sous-performances.'], budget: ['Suivi budget — magasin', 'Budget validé par le consultant contre réel encodé chaque mois, poste par poste.'], encodage: ['Encodage du budget', 'Saisie du budget annuel d’un magasin : CA mensuel, engagement panier, étude de marché et répartition des charges.'], objectifs: ['Objectifs de CA', 'Cibles par magasin et consolidées réseau, sur 3 horizons : 1 an, 3 ans et 5 ans.'], marge: ['Marge & maîtrise des coûts', 'Marge nette des franchisés et ratios food / labour / overhead, avec alertes par levier.'], projets: ['Projets', 'Suivi des projets de développement : statuts, rétroplanning, coûts, leviers et ROI.'], suivi: ['Suivi des tâches', 'Ce qui a été validé sur la période, et les signalements à traiter — semaine ou mois.'], controle: ['Contrôle des tâches', 'Tâches et checklists du panel, par boutique : une tâche notée est validée. Ouvrez une tâche pour voir la photo et poser (ou revoir) la note.'], reporting: ['Reporting automatisé', 'Rapports récurrents générés et envoyés par email (PDF), alertes push paramétrables.'], journal: ['Journal', 'Traçabilité intégrale : chaque action est horodatée avec son auteur. Filtrable et exportable.'], produits: ['Scoring produits', 'Volume, marge nette, taux de perte et présence au comptoir : un score unique par référence pour arbitrer la gamme. Cliquez un taux de perte pour le détail magasin par magasin.'], parametres: ['Paramètres', 'Leviers, seuils, modèles d’email, utilisateurs, magasins, zones et intégration TFB.'], scoring: ['Scoring produits — réglages', 'Pondération des quatre critères, seuils de verdict et échelle de la marge nette. Ces réglages pilotent directement l’écran Scoring produits.'] };
    common.screenTitle = titles[S.screen][0]; common.screenSub = titles[S.screen][1];
    const mt = this.meta || {};
    common.metaDate = mt.dateLabel || ''; common.metaPeriode = mt.periodeLabel || '';
    common.brandNom = (mt.reseau || {}).nom || ''; common.brandSub = (mt.reseau || {}).sousTitre || '';
    // Logo de marque (image) plutôt que le nom en texte. Chemin résolu comme
    // l'API : relatif à la page, donc valable à la racine comme sous un
    // sous-répertoire (/consulant_bo/assets/img/logo.png).
    common.brandLogo = ((typeof location !== 'undefined' ? location.pathname.replace(/[^/]*$/, '') : '')) + 'assets/img/logo.png';
    const usr = mt.utilisateur || {};
    // Version affichée dans le rail : comparée à la date de livraison, elle
    // dit d'un coup d'œil si le navigateur sert encore une version périmée.
    common.build = (this.meta && this.meta.build) || '';
    common.userInit = usr.initiales || ''; common.userNom = usr.nom || ''; common.userRole = usr.role || '';
    common.canLogout = this.source === 'api';
    // Panneau utilisateur : identité affichée + compte consultant de l'API.
    common.userOpen = () => this.setState({ userPanel: true });
    this.valsCompteApi(common);
    if (S.userPanel) {
      const ud = S.userDraft || {};
      const uSet = k => e => { const v = e.target.value; this.setState(s2 => ({ userDraft: Object.assign({}, s2.userDraft, { [k]: v }) })); };
      common.userPanel = {
        nom: ud.nom != null ? ud.nom : (usr.nom || ''),
        initiales: ud.initiales != null ? ud.initiales : (usr.initiales || ''),
        role: ud.role != null ? ud.role : (usr.role || ''),
        setNom: uSet('nom'), setInit: uSet('initiales'), setRole: uSet('role'),
        // Le rôle vient du référentiel d'atelierby_db (position / comptes
        // actifs) : un rôle saisi librement ne veut rien dire de commun avec
        // le panel. Si le référentiel est vide, on laisse le champ libre.
        roles: (D.roles || []), aRoles: (D.roles || []).length > 0,
        identMsg: ud.msg || '',
        identMsgSt: 'margin-top:10px;font-size:12px;font-weight:500;color:' + (ud.ok ? '#2d7a3e' : '#8D1D2C'),
        canLogout: this.source === 'api', logout: common.logout,
        close: () => this.setState({ userPanel: false, userDraft: {} }),
        saveIdent: () => {
          const p = this.state.userDraft || {};
          const val = { nom: p.nom != null ? p.nom : (usr.nom || ''),
            initiales: p.initiales != null ? p.initiales : (usr.initiales || ''),
            role: p.role != null ? p.role : (usr.role || '') };
          this.api('PUT', '/parametres/utilisateur', { valeur: val }).then(r => {
            if (r && r.ok === false) { this.setState(s2 => ({ userDraft: Object.assign({}, s2.userDraft, { ok: false, msg: r.error || 'Échec' }) })); return; }
            this.meta.utilisateur = val;
            this.setState(s2 => ({ userDraft: Object.assign({}, s2.userDraft, { ok: true, msg: 'Identité enregistrée.' }) }));
            this.log('Paramètre', null, 'Identité de l’utilisateur mise à jour : ' + val.nom);
          });
        },
      };
    } else { common.userPanel = false; }
    common.logout = async () => { await authLogout();
      this.setState({ ready: false, gate: { mode: 'login', err: '', busy: false } }); };

    if (!S.ready){ common.nav = []; return common; }
    const D = this.D, M = this.M;
    common.np = S.np; common.npLevs = M.LEVIERS; common.npAxes = Object.keys(D.projTemplates); common.npKpis = M.KPI_LIST;
    const addD = (iso, n) => { const dt = new Date(iso + 'T12:00:00'); dt.setDate(dt.getDate() + n); const p = x => String(x).padStart(2, '0'); return dt.getFullYear() + '-' + p(dt.getMonth() + 1) + '-' + p(dt.getDate()); };
    common.npOwners = D.consultants.map(c => ({ val: 'c:' + c.id, nom: c.nom + ' — Consultant' })).concat(D.suppliers.map(s => ({ val: 's:' + s.id, nom: s.nom + ' — Fournisseur' })));
    const wizSteps = (labels, cur, go, maxReached) => labels.map((label, i) => { const n = i + 1, done = n < cur, act = n === cur;
      return { num: done ? '✓' : String(n), label, sep: i < labels.length - 1,
        go: () => { if (n <= maxReached) go(n); },
        btnSt: 'display:flex;align-items:center;gap:9px;border:none;background:transparent;padding:0;font-family:var(--font-ui);cursor:' + (n <= maxReached ? 'pointer' : 'default'),
        dotSt: 'display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:999px;font-size:12px;font-weight:500;flex:0 0 auto;' + (act ? 'background:var(--color-primary);color:#fff' : done ? 'background:rgba(141,29,44,0.12);color:var(--color-primary)' : 'background:var(--color-background-secondary);color:var(--color-text-muted)'),
        labSt: 'font-size:12.5px;white-space:nowrap;font-weight:' + (act ? '500' : '400') + ';color:' + (act ? 'var(--color-text)' : 'var(--color-text-muted)'),
        sepSt: 'flex:1;height:1px;margin:0 14px;background:' + (done ? 'var(--color-primary)' : 'var(--color-border-tertiary)') };
    });

    // --- assistant projet
    if (S.np){
      const f = S.np, st = f.step || 1, reached = f.reached || 1;
      common.npS1 = st === 1; common.npS2 = st === 2; common.npS3 = st === 3; common.npS4 = st === 4;
      common.npSteps = wizSteps(['Cadrage', 'Valeur & priorité', 'Rétroplanning & tâches', 'Budget & récap'], st, n => this.setState(s2 => ({ np: Object.assign({}, s2.np, { step: n }) })), reached);
      common.npStepSub = ['Nom, levier, axe et fenêtre de réalisation du projet.', 'Ce que le projet doit rapporter et son degré de priorité.', 'Jalons du rétroplanning et tâches confiées aux intervenants.', 'Postes budgétés et vérification avant création.'][st - 1];
      const okStep = n => n === 1 ? !!f.nom.trim() : n === 2 ? true : n === 3 ? f.jalons.some(j => j.nom.trim()) || f.taches.some(t => t.nom.trim()) : true;
      common.npCanPrev = st > 1; common.npCanNext = st < 4;
      common.npWarn = st === 1 && !okStep(1) ? 'Nom du projet requis' : st === 3 && !okStep(3) ? 'Au moins un jalon ou une tâche' : '';
      common.npWarnCol = '#8D1D2C';
      common.npPrev = () => this.setState(s2 => ({ np: Object.assign({}, s2.np, { step: Math.max(1, (s2.np.step || 1) - 1) }) }));
      common.npNext = () => { if (!okStep(st)){ this.notify(common.npWarn); return; }
        this.setState(s2 => { const n = Math.min(4, (s2.np.step || 1) + 1); return { np: Object.assign({}, s2.np, { step: n, reached: Math.max(s2.np.reached || 1, n) }) }; }); };
      if (st === 4){
        const lev = M.LEVIERS.find(l => l.slug === f.lev), tot = f.couts.reduce((a, c) => a + (+c.prevu || 0), 0);
        const val = +f.valeur || 0;
        common.npRecap = [
          { k: 'Projet', v: f.nom.trim() || '—' }, { k: 'Axe', v: f.axe }, { k: 'Levier', v: lev ? lev.nom : f.lev }, { k: 'Priorité', v: f.prio },
          { k: 'Fenêtre', v: this.fD(f.debut) + ' → ' + this.fD(f.fin) }, { k: 'Jalons', v: f.jalons.filter(j => j.nom.trim()).length + ' jalon(s)' },
          { k: 'Tâches', v: f.taches.filter(t => t.nom.trim()).length + ' tâche(s)' },
          { k: 'Budget / valeur', v: this.fE(tot) + ' → ' + (val ? this.fE(val) : 'à chiffrer') }];
      }
    }

    // --- assistant tâche
    // Aucune valeur inventée : le projet, l'intervenant et l'échéance viennent
    // des données réelles. « c:mj » et « 2026-10-31 » étaient des restes du jeu
    // de démonstration — sur une base réelle ils désignaient un consultant
    // inexistant et une date arbitraire, et D.projects[0] plantait tout net
    // quand aucun projet n'existait encore.
    common.ntOpen = () => {
      if (!D.projects.length) { this.notify('Créez d’abord un projet — une tâche s’y rattache.'); return; }
      this.setState({ nt: { step: 1, reached: 1, projet: D.projects[0].id, nom: '', magasin: '',
        who: this.premierIntervenant(), due: this.dansNJours(60), col: 'À faire' } });
    };
    common.ntClose = () => this.setState({ nt: null });
    if (S.nt){
      const f = S.nt, st = f.step, set = (k, v) => this.setState(s2 => ({ nt: Object.assign({}, s2.nt, { [k]: v }) }));
      const proj = D.projects.find(p => p.id === f.projet) || D.projects[0];
      const own = D.consultants.concat(D.suppliers).find(x => x.id === f.who.split(':')[1]);
      common.nt = { projet: f.projet, nom: f.nom, magasin: f.magasin, who: f.who, due: f.due };
      common.ntS1 = st === 1; common.ntS2 = st === 2; common.ntS3 = st === 3;
      common.ntSteps = wizSteps(['Tâche', 'Responsable & échéance', 'Récapitulatif'], st, n => set('step', n), f.reached);
      common.ntStepSub = ['Projet de rattachement, magasin concerné et intitulé de la tâche.', 'Qui la porte, pour quand, et dans quelle colonne elle démarre.', 'Vérification avant création.'][st - 1];
      common.ntProjets = D.projects.map(p => ({ val: p.id, nom: p.nom + ' — ' + this.pStatut(p) }));
      common.ntProjet = e => set('projet', e.target.value);
      common.ntMagasins = [{ val: '', nom: 'Aucun — tâche réseau' }].concat(D.stores.map(s => ({ val: s.id, nom: s.nom + ' — ' + s.zone })));
      common.ntMagasin = e => set('magasin', e.target.value);
      const ntMagNom = f.magasin ? (D.stores.find(s => s.id === f.magasin) || {}).nom : null;
      common.ntNom = e => set('nom', e.target.value);
      common.ntWho = e => set('who', e.target.value);
      common.ntDue = e => set('due', e.target.value);
      common.ntObjectif = (D.crm[proj.id] && D.crm[proj.id].objectif) || proj.valeurTxt;
      const cb = act => 'border:0.5px solid ' + (act ? 'var(--color-primary)' : 'var(--color-border-secondary)') + ';cursor:pointer;font-family:var(--font-ui);font-size:12.5px;font-weight:500;padding:8px 16px;border-radius:999px;background:' + (act ? 'var(--color-primary)' : 'transparent') + ';color:' + (act ? '#fff' : 'var(--color-text)');
      common.ntCols = ['À faire', 'En cours', 'En retard'].map(c => ({ nom: c, pick: () => set('col', c), st: cb(f.col === c) }));
      const ch = own && own.charge != null ? own.nom + ' est chargé à ' + own.charge + ' % ce trimestre.' : '';
      const nOpen = this.tasksFlat().filter(x => (x.t.owner.t + ':' + x.t.owner.id) === f.who && !x.t.done).length;
      common.ntCharge = ch + (ch ? ' ' : '') + nOpen + ' tâche(s) déjà ouverte(s) sur son plan de charge.';
      common.ntCanPrev = st > 1; common.ntCanNext = st < 3;
      common.ntWarn = st === 1 && !f.nom.trim() ? 'Intitulé requis' : '';
      common.ntWarnCol = '#8D1D2C';
      common.ntPrev = () => set('step', st - 1);
      common.ntNext = () => { if (st === 1 && !f.nom.trim()){ this.notify('Intitulé de la tâche requis'); return; }
        this.setState(s2 => ({ nt: Object.assign({}, s2.nt, { step: st + 1, reached: Math.max(s2.nt.reached, st + 1) }) })); };
      common.ntRecap = [{ k: 'Tâche', v: f.nom.trim() || '—' }, { k: 'Projet', v: proj.nom }, { k: 'Magasin', v: ntMagNom || 'Réseau' },
        { k: 'Responsable', v: own ? own.nom : '—' }, { k: 'Échéance', v: this.fD(f.due) }, { k: 'Colonne', v: f.col },
        { k: 'Notification', v: own ? own.email : '—' }];
      common.ntCreate = () => { const w = f.who.split(':');
        const id = 'nt' + Date.now().toString().slice(-6);
        proj.taches.push({ id, nom: f.nom.trim(), owner: { t: w[0], id: w[1] }, magasin: f.magasin || null, due: f.due, done: null, relance: null });
        const jr = 'Tâche « ' + f.nom.trim() + ' » créée — ' + (own ? own.nom : '') + (ntMagNom ? ', magasin ' + ntMagNom : '') + ', échéance ' + this.fD(f.due);
        this.api('POST', '/projects/' + proj.id + '/tasks', { id, nom: f.nom.trim(), owner: { t: w[0], id: w[1] }, magasinId: f.magasin || null, due: f.due, journal: jr });
        this.setState(s2 => ({ nt: null, tkOv: Object.assign({}, s2.tkOv, { [id]: f.col }) }));
        this.log('Tâche', proj.nom, jr);
        this.notify('Tâche créée et assignée à ' + (own ? own.nom : '')); };
    }
    common.npOpen = () => this.setState({ np: { step: 1, reached: 1, nom: '',
      lev: (M.LEVIERS[0] || {}).slug || '', axe: 'Ventes', prio: 'Moyenne',
      debut: M.TODAY, fin: this.dansNJours(180), valeur: '', valeurTxt: '', kpi: '',
      // Le rétroplanning du type de projet est posé dès l'ouverture : ouvrir sur
      // une ligne vide obligeait à cliquer « charger le template » pour obtenir
      // ce qui est de toute façon le point de départ attendu.
      jalons: (((D.projTemplates || {})['Ventes'] || {}).jalons || []).length
        ? D.projTemplates['Ventes'].jalons.map(j => ({ nom: j.nom, cible: addD(this.dansNJours(180), j.j) }))
        : [{ nom: '', cible: this.dansNJours(180) }],
      taches: [{ nom: '', who: this.premierIntervenant(), due: this.dansNJours(60) }],
      couts: [{ poste: 'Jours-homme consultants', prevu: '' }] } });
    common.npClose = () => this.setState({ np: null });
    const npSet = k => e => this.setState(s2 => ({ np: Object.assign({}, s2.np, { [k]: e.target.value }) }));
    common.npNom = npSet('nom'); common.npLev = npSet('lev'); common.npDebut = npSet('debut'); common.npFin = npSet('fin');
    // Le levier se choisit sur des pastilles PORTANT SA COULEUR : c'est la même
    // couleur qui identifiera le projet partout ailleurs (cartes, kanban,
    // rapports). Un menu déroulant la cachait, et il fallait créer le projet
    // pour découvrir sa couleur.
    common.npLevChips = (M.LEVIERS || []).map(l => { const on = S.np && S.np.lev === l.slug;
      return { slug: l.slug, nom: l.nom, desc: l.desc || '', couleur: l.color || '#666',
        st: 'display:inline-flex;align-items:center;gap:7px;cursor:pointer;font-family:var(--font-ui);'
          + 'font-size:12.5px;padding:7px 13px;border-radius:999px;'
          + (on ? 'border:1px solid ' + (l.color || '#666') + ';background:' + (l.color || '#666') + '1f;font-weight:500;color:var(--color-text)'
                : 'border:0.5px solid var(--color-border-secondary);background:transparent;color:var(--color-text-muted)'),
        dotSt: 'width:9px;height:9px;border-radius:50%;flex:0 0 auto;background:' + (l.color || '#666'),
        go: () => this.setState(s2 => ({ np: Object.assign({}, s2.np, { lev: l.slug }) })) }; });
    // Changer d'axe recharge le rétroplanning : un template de jalons par TYPE
    // de projet, appliqué sans qu'on ait à y penser. Les jalons déjà saisis à
    // la main ne sont pas écrasés — on ne détruit pas un travail en cours.
    common.npAxe = e => { const ax = e.target.value;
      this.setState(s2 => { const f2 = s2.np || {};
        const tpl = (this.D.projTemplates || {})[ax];
        const saisis = (f2.jalons || []).some(j => (j.nom || '').trim() !== '');
        const jal = (tpl && !saisis)
          ? tpl.jalons.map(j => ({ nom: j.nom, cible: addD(f2.fin, j.j) }))
          : f2.jalons;
        return { np: Object.assign({}, f2, { axe: ax, jalons: jal }) }; }); };
    common.npValeur = npSet('valeur'); common.npValeurTxt = npSet('valeurTxt'); common.npKpi = npSet('kpi');
    const npPrio = p => () => this.setState(s2 => ({ np: Object.assign({}, s2.np, { prio: p }) }));
    const npPrioSt = p => 'cursor:pointer;font-family:var(--font-ui);font-size:12px;font-weight:500;padding:8px 16px;border-radius:999px;' + (S.np && S.np.prio === p ? 'border:none;background:var(--color-primary);color:#fff' : 'border:0.5px solid var(--color-border-secondary);background:transparent;color:var(--color-text-muted)');
    common.npPrioH = npPrio('Haute'); common.npPrioM = npPrio('Moyenne'); common.npPrioB = npPrio('Basse');
    common.npPrioHSt = npPrioSt('Haute'); common.npPrioMSt = npPrioSt('Moyenne'); common.npPrioBSt = npPrioSt('Basse');
    const npRow = (list, i, k) => e => this.setState(s2 => { const arr = s2.np[list].slice(); arr[i] = Object.assign({}, arr[i], { [k]: e.target.value }); return { np: Object.assign({}, s2.np, { [list]: arr }) }; });
    const npAdd = (list, tpl) => () => this.setState(s2 => ({ np: Object.assign({}, s2.np, { [list]: s2.np[list].concat([tpl]) }) }));
    const npDel = (list, i) => () => this.setState(s2 => ({ np: Object.assign({}, s2.np, { [list]: s2.np[list].filter((_, j) => j !== i) }) }));
    if (S.np){ const f = S.np;
      common.npJalons = f.jalons.map((j, i) => ({ nom: j.nom, cible: j.cible, setNom: npRow('jalons', i, 'nom'), setCible: npRow('jalons', i, 'cible'), del: npDel('jalons', i) }));
      common.npTaches = f.taches.map((t, i) => ({ nom: t.nom, who: t.who, due: t.due, setNom: npRow('taches', i, 'nom'), setWho: npRow('taches', i, 'who'), setDue: npRow('taches', i, 'due'), del: npDel('taches', i) }));
      common.npCouts = f.couts.map((c, i) => ({ poste: c.poste, prevu: c.prevu, setPoste: npRow('couts', i, 'poste'), setPrevu: npRow('couts', i, 'prevu'), del: npDel('couts', i) }));
      common.npJalAdd = npAdd('jalons', { nom: '', cible: f.fin }); common.npTacheAdd = npAdd('taches', { nom: '', who: this.premierIntervenant(), due: f.fin }); common.npCoutAdd = npAdd('couts', { poste: '', prevu: '0' });
      common.npLoadJalons = () => { const tpl = D.projTemplates[f.axe]; if (!tpl) return;
        this.setState(s2 => ({ np: Object.assign({}, s2.np, { jalons: tpl.jalons.map(j => ({ nom: j.nom, cible: addD(f.fin, j.j) })) }) }));
        this.notify('Template rétroplanning « ' + f.axe + ' » chargé (' + tpl.jalons.length + ' jalons)'); };
      common.npLoadCouts = () => { const tpl = D.projTemplates[f.axe]; if (!tpl) return;
        this.setState(s2 => ({ np: Object.assign({}, s2.np, { couts: tpl.couts.map(c => ({ poste: c.poste, prevu: String(c.prevu) })) }) }));
        this.notify('Template coûts « ' + f.axe + ' » chargé (' + tpl.couts.length + ' postes)'); };
      common.npBudgetTot = this.fE(f.couts.reduce((a, c) => a + (+c.prevu || 0), 0));
    }
    const FAM_BY_AXE = { 'Ventes': 'Produits', 'Marge nette franchisé': 'Organisation & coûts', 'Développement réseau': 'Développement réseau', 'Produit — Interne (production)': 'Produits', 'Produit — Externe (achat)': 'Produits' };
    common.npCreate = () => { const f = S.np; if (!f) return;
      if (!f.nom.trim()){ this.notify('Donnez un nom au projet.'); return; }
      const id = 'px' + Date.now();
      const couts = f.couts.filter(c => c.poste.trim()).map(c => ({ poste: c.poste.trim(), prevu: +c.prevu || 0, reel: 0 }));
      const budget = couts.reduce((a, c) => a + c.prevu, 0);
      const taches = f.taches.filter(t => t.nom.trim()).map((t, i) => { const w = t.who.split(':'); return { id: 't' + id + i, nom: t.nom.trim(), owner: { t: w[0], id: w[1] }, due: t.due, done: null, relance: null }; });
      const jalons = f.jalons.filter(j => j.nom.trim()).map(j => ({ nom: j.nom.trim(), cible: j.cible, reel: null }));
      const famille = FAM_BY_AXE[f.axe] || 'Organisation & coûts';
      D.projects.push({ id, nom: f.nom.trim(), famille, statut: 'À lancer', prio: f.prio, debut: f.debut, fin: f.fin, axes: [f.axe], leviers: [f.lev], produit: null, categorie: null, budget,
        valeurEst: +f.valeur || null, valeurReal: null, valeurTxt: f.valeurTxt.trim() || 'à chiffrer', kpis: f.kpi.trim() ? [f.kpi.trim()] : [], jalons, taches, couts });
      const jr = 'Projet créé — statut « À lancer », échéance ' + this.fD(f.fin) + ', budget ' + this.fE(budget) + ', ' + taches.length + ' tâche(s), ' + jalons.length + ' jalon(s)';
      this.api('POST', '/projects', { id, nom: f.nom.trim(), famille, statut: 'À lancer', prio: f.prio, debut: f.debut, fin: f.fin,
        axes: [f.axe], leviers: [f.lev], budget, valeurEst: +f.valeur || null, valeurTxt: f.valeurTxt.trim() || 'à chiffrer',
        kpis: f.kpi.trim() ? [f.kpi.trim()] : [], jalons, couts, taches: taches.map(t => ({ id: t.id, nom: t.nom, owner: t.owner, due: t.due })), journal: jr });
      this.log('Création', f.nom.trim(), jr);
      this.setState({ np: null, openProjId: id }); this.notify('Projet « ' + f.nom.trim() + ' » créé'); };
    const flat = this.tasksFlat();
    const lateTasks = flat.filter(x => x.st === 'En retard');
    const projEff = D.projects.map(p => Object.assign({}, p, { statut: this.pStatut(p) }));
    const nLate = projEff.filter(p => p.statut === 'En retard').length;

    const navDef = [['Pilotage', [['taches', 'Tâches consultants', lateTasks.length]]],
      ['Exploitation', [['exploitation', 'P&L magasins', 0]]],
      ['Performance & marge', [['magasins', 'Tableau des magasins', 0], ['heatmap', 'Heatmap mensuelle', 0], ['objectifs', 'Objectifs de CA', 0], ['budget', 'Suivi budget magasin', 0], ['encodage', 'Encodage du budget', 0], ['marge', 'Marge & coûts', this.margeAlerts().length],
        { sub: 'Scoring produits', children: [
          ['produits', 'Scoring des références', 0],
          ['seuil', 'Références sous seuil', 0],
          ['analyse', 'Analyse dans le temps', 0]] }]],
      ['Référentiel produit', [
        { sub: 'Catalogue & comptoir', children: [
          ['catalogue', 'Catalogue produit', 0],
          ['assortiment', 'Assortiment obligatoire', 0],
          ['planogramme', 'Planogramme comptoir', 0]] },
        ['production', 'Suivi de production', 0]]],
      // Sept entrées, pas dix. « Catalogue & marge », « Analyse des ventes » et
      // « Cockpit » doublaient le Référentiel produit, le Tableau des magasins
      // et P&L magasins — mêmes sources, en moins riche. La marge nette qu'ils
      // apportaient a rejoint le catalogue existant.
      ['Centrale d’achat', [
        ['caCampagnes', 'Campagnes commerciales', 0],
        ['caDemande', 'Demande de prix', 0],
        ['caAchats', 'Suivi fournisseurs', 0],
        ['caCommandes', 'Commandes franchisés', 0],
        ['caStock', 'Stock', 0],
        ['caFacturation', 'Facturation magasins', 0]]],
      ['Projets & contrôle', [['projets', 'Projets', nLate],
        // Sous-menu : les deux écrans « tâches consultants » (panel) regroupés.
        { sub: 'Checklists consultants', children: [
          ['suivi', 'Suivi des tâches', S.suiviData ? S.suiviData.ouverts : 0],
          ['controle', 'Contrôle des tâches', ((D.pwaTasks || {}).totals || {}).aValider || 0]] }]],
      ['Administration', [['reporting', 'Reporting', 0], ['journal', 'Journal', 0],
        ['diagnostic', 'Diagnostic API', 0],
        // Les réglages de la centrale rejoignent les Paramètres : un moteur de
        // marge et un référentiel fournisseurs sont des réglages, pas un écran
        // d'exploitation. Les chercher à deux endroits était le vrai défaut.
        { sub: 'Paramètres', children: [['parametres', 'Général', 0], ['scoring', 'Scoring produits', 0],
          ['caReglages', 'Centrale d’achat', 0]] }]]];
    const navSt = (active, indent) => 'display:flex;align-items:center;justify-content:space-between;gap:8px;width:100%;text-align:left;border:none;cursor:pointer;font-family:var(--font-ui);font-size:' + (indent ? '12.5px' : '13px') + ';padding:' + (indent ? '7px 10px 7px 24px' : '8px 10px') + ';border-radius:8px;font-weight:300;' + (active ? 'background:rgba(141,29,44,0.08);color:var(--color-primary);font-weight:500' : 'background:transparent;color:var(--color-text' + (indent ? '-muted' : '') + ')');
    const sumBadge = arr => arr.reduce((a, c) => a + (c[2] || 0), 0);
    common.nav = navDef.map(g => ({ titre: g[0], items: g[1].map(it => {
      if (Array.isArray(it)) return { type: 'leaf', label: it[1], badge: it[2] || false, go: goTo(it[0]), st: navSt(S.screen === it[0], false) };
      const childActive = it.children.some(c => S.screen === c[0]);
      const open = (S.navOpen && S.navOpen[it.sub] != null) ? S.navOpen[it.sub] : childActive;
      return { type: 'sub', label: it.sub, open, chevron: open ? '▾' : '▸',
        badge: open ? false : (sumBadge(it.children) || false),
        toggle: () => { const cur = (S.navOpen && S.navOpen[it.sub] != null) ? S.navOpen[it.sub] : childActive;
          this.setState(s2 => ({ navOpen: Object.assign({}, s2.navOpen, { [it.sub]: !cur }) })); },
        st: navSt(childActive && !open, false),
        children: it.children.map(c => ({ type: 'leaf', label: c[1], badge: c[2] || false, go: goTo(c[0]), st: navSt(S.screen === c[0], true) })) };
    }) }));

    ['isBudget', 'isEncodage', 'isMagasins', 'isHeatmap', 'isObjectifs', 'isMarge', 'isProjets', 'isReporting', 'isJournal', 'isParams', 'isTaches', 'isProduits', 'isSuivi', 'isControle', 'isScoring', 'isExploit', 'isCat', 'isAsso', 'isPlano', 'isProd', 'isAnalyse', 'isCentrale', 'isDiag', 'isSeuil'].forEach(k => common[k] = false);
    const key = { budget: 'isBudget', encodage: 'isEncodage', taches: 'isTaches', magasins: 'isMagasins', heatmap: 'isHeatmap', objectifs: 'isObjectifs', marge: 'isMarge', produits: 'isProduits', projets: 'isProjets', suivi: 'isSuivi', controle: 'isControle', reporting: 'isReporting', journal: 'isJournal', parametres: 'isParams', scoring: 'isScoring', exploitation: 'isExploit', catalogue: 'isCat',
      assortiment: 'isAsso', planogramme: 'isPlano', production: 'isProd',
      analyse: 'isAnalyse', diagnostic: 'isDiag', seuil: 'isSeuil' }[S.screen];
    // Les dix écrans de la centrale partagent un même gabarit : un seul drapeau
    // et une seule fonction de valeurs, l'écran courant étant porté par S.screen.
    if (String(S.screen || '').startsWith('ca') && S.screen !== 'catalogue') { common.isCentrale = true; }
    else if (key) { common[key] = true; }

    // --- magasins
    if (common.isMagasins){
      common.zoneF = S.zoneF; common.setZoneF = e => this.setState({ zoneF: e.target.value });
      common.zoneOptions = ['Toutes les zones'].concat([...new Set(this.open().map(s => s.zone))]);
      const E = this.exo(), MI = this.moisIdx();
      common.storeHdrPeriode = this.moisLabel() + ' ' + E;
      const rows = this.open().filter(s => S.zoneF === 'Toutes les zones' || s.zone === S.zoneF).map(s => {
        const r = s.perf[E][MI], n1 = s.perf[E - 1][MI];
        return { s, nom: s.nom, code: s.code, fr: s.fr, _marge: r.marge, _margeVar: r.marge / n1.marge - 1, _val: r.val, _valPct: r.val / s.valT, _ca: r.ca, _caPct: r.ca / r.caT, _tickets: r.tickets, _tick: r.tickets / n1.tickets - 1, _panier: r.panier, _pan: r.panier / n1.panier - 1, _margeN1: n1.marge }; });
      const sk = S.sortKey, dir = S.sortDir;
      rows.sort((a, b) => { const va = a['_' + sk] != null ? a['_' + sk] : a[sk], vb = b['_' + sk] != null ? b['_' + sk] : b[sk]; return (va < vb ? -1 : va > vb ? 1 : 0) * dir; });
      const colDefs = [['nom', 'Magasin', 'left'], ['marge', 'Marge ' + (this.moisLabel() || 'mois'), 'right'], ['margeN1', 'Marge N-1', 'right'], ['margeVar', 'Var.', 'right'], ['val', 'Valeur / cible', 'right'], ['valPct', '% réussite', 'center'], ['ca', 'CA / cible', 'right'], ['caPct', '% atteinte', 'center'], ['tickets', 'Tickets', 'right'], ['panier', 'Panier moyen', 'right']];
      common.storeCols = colDefs.map(c => ({ label: c[1], arrow: sk === c[0] ? (dir > 0 ? ' ↑' : ' ↓') : '', sort: () => this.setState({ sortKey: c[0], sortDir: sk === c[0] ? -dir : -1 }),
        st: 'text-align:' + c[2] + ';font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.05em;color:var(--color-text-muted);padding:12px;border-bottom:0.5px solid var(--color-border-tertiary);cursor:pointer;white-space:nowrap;user-select:none' }));
      common.storeRows = rows.map(r => { const tv = this.trend(1 + r._margeVar, 1), te = this.trend(1 + r._tick, 1), pe = this.trend(1 + r._pan, 1);
        return { nom: r.nom, code: r.code, fr: r.fr, marge: this.fE(r._marge), margeN1: this.fE(r._margeN1), margeVar: tv.txt, margeVarSt: tv.st,
          val: this.fK(r._val), valT: this.fK(r.s.valT), valPct: this.fP(r._valPct, 0), valPctSt: this.pill(r._valPct + 0.08),
          ca: this.fK(r._ca), caT: this.fK(r.s.perf[E][MI].caT), caPct: this.fP(r._caPct, 0), caPctSt: this.pill(r._caPct),
          tickets: r._tickets != null ? r._tickets.toLocaleString('fr-BE') : '—', tickEvo: te.txt + ' vs N-1', tickEvoSt: te.st + ';font-size:10.5px',
          panier: r._panier != null ? r._panier.toFixed(2).replace('.', ',') + ' €' : '—', panEvo: pe.txt + ' vs N-1', panEvoSt: pe.st + ';font-size:10.5px' }; });
    }

    // --- heatmap
    if (common.isHeatmap){
      const E = this.exo();
      const year = (S.hmYear === E - 1) ? E - 1 : E;
      // Le mode « % d'atteinte » suppose des objectifs encodés. Tant qu'il n'y
      // en a pas, il n'affiche que des cases vides — l'écran paraît mort alors
      // que le CA réel est là. On bascule alors sur le CA, sauf choix explicite.
      const aDesCibles = this.open().some(st => (st.perf[year] || []).some(c => c && c.caT));
      const metric = (year === E - 1 || !aDesCibles) ? (S.hmMetric === 'pct' && aDesCibles ? 'pct' : 'ca') : S.hmMetric;
      common.hmYearCur = String(E); common.hmYearPrev = String(E - 1);
      const tb = act => 'border:none;cursor:pointer;font-family:var(--font-ui);font-size:12px;font-weight:500;padding:7px 14px;' + (act ? 'background:var(--color-primary);color:#fff' : 'background:var(--color-surface);color:var(--color-text-muted)');
      common.hmBtnCaSt = tb(metric === 'ca'); common.hmBtnPctSt = tb(metric === 'pct'); common.hmBtn25St = tb(year === E - 1); common.hmBtn26St = tb(year === E);
      common.hmMetricCa = () => this.setState({ hmMetric: 'ca' }); common.hmMetricPct = () => this.setState({ hmMetric: 'pct' });
      common.hmY25 = () => this.setState({ hmYear: E - 1 }); common.hmY26 = () => this.setState({ hmYear: E });
      common.hmNote = year === E - 1 ? ('Année ' + (E - 1) + ' : CA constaté (pas d’objectif défini).')
        : (!aDesCibles ? 'Aucun objectif encodé pour ' + year + ' : les cellules montrent le CA constaté. Encodez un budget (Encodage du budget) pour activer le % d’atteinte.'
        : (metric === 'pct' ? 'Cellules colorées selon le % d’atteinte de l’objectif mensuel du magasin.' : 'Cellules colorées du CA le plus faible au plus élevé.'));
      common.hmMois = M.MOIS;
      let mn = Infinity, mx = -Infinity;
      if (metric === 'ca') for (const s of this.open()) for (const r of s.perf[year]) if (r.ca != null){ mn = Math.min(mn, r.ca); mx = Math.max(mx, r.ca); }
      const cellBase = 'border-radius:5px;min-height:34px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:500;cursor:default;';
      const mkCell = (nomM, mi, ca, caT) => { let st = cellBase, txt = '—';
        if (ca == null){ st += 'background:var(--color-background-secondary);color:var(--color-text-muted)'; }
        else if (metric === 'ca'){ const t = (ca - mn) / (mx - mn || 1); st += 'background:' + this.mix('#F7F2EA', '#8D1D2C', t) + ';color:' + (t > 0.55 ? '#fff' : '#222'); txt = Math.round(ca / 1000) + 'k'; }
        else { const pct = ca / caT; const t = Math.max(0, Math.min(1, (pct - 0.85) / 0.3));
          st += 'background:' + (t < 0.5 ? this.mix('#8D1D2C', '#EDE7DE', t * 2) : this.mix('#EDE7DE', '#2d7a3e', (t - 0.5) * 2)) + ';color:' + (t < 0.16 || t > 0.86 ? '#fff' : '#222'); txt = Math.round(pct * 100) + '%'; }
        return { txt, st, enter: () => this.setState({ hmHover: { nomM, mi, ca, caT } }) }; };
      common.hmRows = this.open().map(s => ({ nom: s.nom, cells: s.perf[year].map((r, mi) => mkCell(s.nom, mi, r.ca, r.caT)) }));
      const resCells = [];
      for (let mi = 0; mi < 12; mi++){ const ca = this.open().every(s => s.perf[year][mi].ca == null) ? null : this.sum(year, mi, 'ca'); const caT = this.sum(year, mi, 'caT') || null;
        if (metric === 'ca'){ const c2 = mkCell('Réseau', mi, ca == null ? null : ca / this.open().length, caT); if (ca != null) c2.txt = Math.round(ca / 1000) + 'k'; c2.enter = () => this.setState({ hmHover: { nomM: 'Réseau', mi, ca, caT } }); resCells.push(c2); }
        else resCells.push(mkCell('Réseau', mi, ca, caT)); }
      common.hmReseau = resCells;
      const h = S.hmHover;
      common.hmDetail = h ? (h.nomM + ' — ' + M.MOIS[h.mi] + ' ' + year + ' : CA ' + this.fE(h.ca) + (h.caT ? ' · objectif ' + this.fE(h.caT) + ' · écart ' + (h.ca != null ? this.fE(h.ca - h.caT) + ' (' + this.fP(h.ca / h.caT - 1) + ')' : '—') : '')) : 'Survolez une cellule pour le détail (magasin, mois, CA, objectif, écart).';
    }

    // --- objectifs
    if (common.isObjectifs){
      const hz = S.horizon;
      const E = this.exo(), MI = this.moisIdx(), NM = this.nMois();
      common.hz1 = () => this.setState({ horizon: 'h1' }); common.hz3 = () => this.setState({ horizon: 'h3' }); common.hz5 = () => this.setState({ horizon: 'h5' });
      common.hz1St = this.tabBtn(hz === 'h1'); common.hz3St = this.tabBtn(hz === 'h3'); common.hz5St = this.tabBtn(hz === 'h5');
      common.isH1 = hz === 'h1'; common.isH35 = hz !== 'h1'; common.hmMois = M.MOIS;
      common.objExo = String(E); common.objMoisLabel = this.moisLabel();
      const tgCa = (D.targets || {}).ca || {};
      common.hzLabel1 = '1 an — ' + E;
      common.hzLabel3 = '3 ans — ' + ((tgCa.h3 && tgCa.h3.an) || (E + 2));
      common.hzLabel5 = '5 ans — ' + ((tgCa.h5 && tgCa.h5.an) || (E + 4));
      if (hz === 'h1'){
        let reelT = 0, prorataT = 0, cibleT = 0;
        const rows = this.open().map(s => { const cible = s.perf[E].reduce((a, r) => a + r.caT, 0);
          let reel = 0, pro = 0; for (let m = 0; m <= MI; m++){ reel += s.perf[E][m].ca; pro += s.perf[E][m].caT; }
          reelT += reel; prorataT += pro; cibleT += cible; const att = reel / pro; const t = this.trend(att, 1);
          return { nom: s.nom, cible: this.fK(cible), reel: this.fK(reel), prorata: this.fK(pro), ecart: t.txt, ecartSt: t.st, att: this.fP(att, 0), attSt: this.pill(att), _att: att,
            goBudget: () => this.setState({ bStore: s.id, screen: 'budget' }) }; });
        rows.sort((a, b) => b._att - a._att);
        common.objRows = rows;
        common.objCible = this.fM(((((D.targets || {}).ca) || {}).h1 || {}).cible || 0); common.objReel = this.fM(reelT); common.objProrata = this.fM(prorataT);
        const att = reelT / prorataT; common.objAtt = this.fP(att); common.objAttSt = 'font-weight:700;color:' + (att >= 1 ? '#2d7a3e' : att >= 0.92 ? '#8a5a13' : '#8D1D2C');
        let resteCible = 0; for (let m = MI + 1; m < 12; m++) resteCible += this.sum(E, m, 'caT');
        common.objProj = this.fM(reelT + resteCible * att + (this.meta.contribOuverture || 0));
        const cumR = [], cumC = []; let ar = 0, ac = 0;
        for (let m = 0; m < 12; m++){ ac += this.sum(E, m, 'caT'); cumC.push(ac); if (m <= MI){ ar += this.sum(E, m, 'ca'); cumR.push(ar); } }
        // Échelle = max des deux cumuls (cible + réel), plancher 1 : sans objectif
        // encodé la cible cumulée vaut 0 → sans ce plancher, py divise par 0 et le
        // <polyline> reçoit des points NaN/Infinity. On trace quand même le réel.
        const mxv = Math.max(ac, cumR.length ? Math.max.apply(null, cumR) : 0, 1);
        const px = m => 20 + m * (620 / 11); const py = v => 195 - (isFinite(v) ? v : 0) / mxv * 175;
        const pts = arr => arr.map((v, m) => px(m).toFixed(0) + ',' + py(v).toFixed(0)).filter(p => !/NaN|Infinity/.test(p)).join(' ');
        common.trajCible = ac > 0 ? pts(cumC) : '';
        common.trajReel = pts(cumR);
        const budAn = this.open().reduce((a, s) => a + s.perf[E].reduce((b, r) => b + (r.caT || 0), 0), 0);
        common.cumBudget = this.fM(budAn); common.cumReel = this.fM(reelT);
        const bMois = ((D.budgets || [])[0] || {}).moisEncodes;
        common.cumLabel = 'Budget validé ' + this.meta.exercice + ' — ' + this.open().length + ' magasins';
        common.cumReelLabel = 'Réel encodé' + (bMois ? ' (' + bMois + ' mois)' : '');
        common.objNote = (this.meta.notes || {}).objectifsOuverture || '';
        const ec = reelT - prorataT;
        common.cumEcart = (ec >= 0 ? '+' : '−') + this.fK(Math.abs(ec)) + ' (' + (ec >= 0 ? '+' : '−') + this.fP(Math.abs(reelT / prorataT - 1)) + ')';
        common.cumEcartSt = 'font-weight:500;color:' + (ec >= 0 ? '#2d7a3e' : '#8D1D2C');
        const sous = this.open().filter(s => { let r = 0, p = 0; for (let m = 0; m <= MI; m++){ r += s.perf[E][m].ca; p += s.perf[E][m].caT; } return r < p; }).length;
        common.cumSous = sous + ' / ' + this.open().length;
      } else {
        const cfg = (((D.targets || {}).ca) || {})[hz] || { an: this.meta.exercice, cible: 0 };
        const exp = (((D.targets || {}).expansion) || {})[hz] || { an: this.meta.exercice, cible: 1, reel: 0 };
        let run = 0; for (let m = 0; m <= MI; m++) run += this.sum(E, m, 'ca'); run = run / NM * 12;
        const nOuv = (exp.cible || 1) - 1; const contrib = nOuv * ((D.targets || {}).caMoyenOuverture || 0);
        const lfl = cfg.cible - run - contrib;
        common.hzAn = String(cfg.an); common.hzCible = this.fM(cfg.cible); common.hzRunrate = this.fM(run);
        common.hzGap = '+' + this.fM(cfg.cible - run); common.hzOuv = nOuv + ' points de vente'; common.hzContrib = 'env. ' + this.fM(contrib);
        common.hzLfl = '+' + this.fM(Math.max(0, lfl)) + ' à trouver';
        const mkBar = (v, cl) => 'height:100%;border-radius:5px;background:' + cl + ';width:' + Math.min(100, v / cfg.cible * 100).toFixed(1) + '%';
        common.hzBars = [{ label: 'CA actuel (run-rate ' + this.open().length + ' magasins)', val: this.fM(run), st: mkBar(run, 'var(--color-primary)') },
          { label: '+ Contribution des ' + nOuv + ' ouvertures prévues', val: this.fM(contrib), st: mkBar(contrib, 'var(--color-secondary)') },
          { label: '+ Croissance à périmètre constant requise', val: this.fM(Math.max(0, lfl)), st: mkBar(Math.max(0, lfl), '#c9a06a') }];
        common.hzNote = (cfg.note || '').replace('{ouvertures}', nOuv).replace('{caMoyen}', this.fK((D.targets || {}).caMoyenOuverture || 0));
      }
    }

    // --- référentiel produit (partie franchiseur)
    if (common.isCat || common.isAsso || common.isPlano) this.valsReferentiel(common);
    if (common.isPlano) { this.plCharge(); this.valsPlano(common); }
    if (common.isProd) this.valsProduction(common);
    if (common.isAnalyse) { this.anOptions(); this.valsAnalyse(common); }
    if (common.isCentrale) this.valsCentrale(common);
    this.valsLacunes(common);
    if (common.isDiag) this.valsDiag(common);
    if (common.isSeuil) this.valsSeuil(common);
    // --- exploitation (P&L court des magasins)
    if (common.isExploit) this.valsExploitation(common);
    // --- suivi budget magasin
    if (common.isBudget) this.valsBudget(common);
    // --- encodage du budget
    if (common.isEncodage) this.valsEncodage(common);
    // --- scoring produits
    if (common.isProduits) this.valsProduits(common);
    // --- marge
    if (common.isMarge) this.valsMarge(common);
    // --- projets
    if (common.isProjets) this.valsProjets(common, projEff);
    // --- contrôle des tâches (checklists consultants du panel)
    if (common.isControle) { this.iaStatut(); this.valsControle(common); }
    // --- tâches consultants
    if (common.isTaches) this.valsTaches(common, flat);
    // --- reporting
    if (common.isReporting) this.valsReporting(common, navDef, titles);
    // --- suivi des tâches
    if (common.isSuivi) this.valsSuivi(common);
    // --- journal
    if (common.isJournal) this.valsJournal(common);
    // --- paramètres
    if (common.isParams) this.valsParams(common);
    if (common.isScoring) this.valsParams(common);   // même bloc de réglages, écran dédié

    // --- fiche projet
    const opP = S.openProjId && D.projects.find(p => p.id === S.openProjId);
    if (opP){
      const st = this.pStatut(opP); const c = this.cout(opP); const bt = this.budgetTot(opP); const dep = c - opP.budget;
      const v = opP.valeurReal || opP.valeurEst; const roi = v ? v - c : null; const av = this.avance(opP);
      common.op = { nom: opP.nom, statut: st, prio: opP.prio, debut: this.fDY(opP.debut), fin: this.fDY(opP.fin),
        av: Math.round(av * 100) + ' %', axes: opP.axes.join(' · '), valeur: v ? this.fK(v) : 'étude', valeurTxt: opP.valeurTxt,
        levs: opP.leviers.map(sl => M.LEVIERS.find(l => l.slug === sl)),
        setStatut: e => { const ns = e.target.value; this.setState(s2 => ({ statutOv: Object.assign({}, s2.statutOv, { [opP.id]: ns }) }));
          this.api('PATCH', '/projects/' + opP.id, { statut: ns });
          this.log('Statut', opP.nom, 'Statut passé de « ' + st + ' » à « ' + ns + ' » (fiche projet)'); this.notify('Statut de « ' + opP.nom + ' » : ' + ns); },
        jalons: opP.jalons.map(j => { const done = !!j.reel; const late = !done && j.cible < M.TODAY; const wasLate = done && j.reel > j.cible;
          return { nom: j.nom, cible: this.fDY(j.cible),
            dotSt: 'width:11px;height:11px;border-radius:50%;flex:0 0 auto;margin-top:3px;' + (done ? 'background:#2d7a3e' : late ? 'background:#8D1D2C' : 'background:var(--color-surface);border:2px solid var(--color-border-secondary)'),
            etat: done ? ('Atteint le ' + this.fD(j.reel) + (wasLate ? ' — en retard' : ' — à temps')) : late ? ('En retard de ' + Math.round((new Date(M.TODAY) - new Date(j.cible)) / 86400000) + ' j') : 'À venir',
            etatSt: 'font-size:11.5px;margin-top:2px;' + (done && !wasLate ? 'color:#2d7a3e' : late || wasLate ? 'color:#8D1D2C;font-weight:500' : 'color:var(--color-text-muted)') }; }),
        taches: opP.taches.map(t => { const xx = { t, p: opP, st: this.taskState(t), o: this.ownerOf(t) }; const rl = S.relanced[t.id];
          return { nom: t.nom, owner: xx.o.nom, ownerType: xx.o.type, due: this.fD(t.due), statut: xx.st + (xx.st === 'En retard' ? ' · en cause' : ''), statutSt: this.taskPillSt(xx.st),
            relancable: !t.done && !rl, relancer: () => this.openRelTask(xx) }; }),
        couts: opP.couts.map(cc => ({ poste: cc.poste, prevu: this.fE(cc.prevu), reel: this.fE(cc.reel) })),
        budgetTot: this.fE(bt), reelTot: this.fE(c),
        ecartTxt: dep > 0 ? 'Dépassement de budget : +' + this.fE(dep) + ' (+' + this.fP(dep / opP.budget, 0) + ')' : 'Sous le budget : ' + this.fE(dep) + ' (' + this.fP(c / opP.budget, 0) + ' consommé)',
        ecartSt: 'margin-top:6px;font-size:12px;font-weight:500;padding:8px 12px;border-radius:8px;' + (dep > 0 ? 'background:rgba(141,29,44,0.08);color:#8D1D2C' : 'background:rgba(45,122,62,0.08);color:#2d7a3e'),
        roi: roi == null ? '—' : '+' + this.fK(roi), roiCl: roi != null && roi > 0 ? '#2d7a3e' : 'var(--color-text)',
        roiPct: roi == null ? 'valeur non chiffrée' : '+' + this.fP(roi / c, 0), kpis: opP.kpis.join(' · ') };
      common.closeProj = () => this.setState({ openProjId: null });
      common.deleteProj = () => {
        if (typeof confirm === 'function' && !confirm('Supprimer définitivement le projet « ' + opP.nom + ' » et tout son suivi (jalons, tâches, coûts) ?')) return;
        this.api('DELETE', '/projects/' + opP.id);
        this.log('Suppression', opP.nom, 'Projet supprimé');
        this.D.projects = (this.D.projects || []).filter(p => p.id !== opP.id);
        this.setState({ openProjId: null });
        this.notify('Projet « ' + opP.nom + ' » supprimé');
      };
    } else { common.op = false; common.closeProj = () => this.setState({ openProjId: null }); }

    return common;
  }

  /* --- suivi budget magasin -------------------------------------------------- */
  valsBudget(common){
    const S = this.state, D = this.D, M = this.M;
    const st = this.open().find(x => x.id === S.bStore) || this.open()[0];
    const bud = (D.budgets || []).find(b => b.storeId === st.id) || { charges: [] };
    const P = st.perf[this.meta.exercice];
    common.bExercice = this.meta.exercice;
    common.bCumMois = this.moisLabel();
    common.bEncodes = (bud.moisEncodes || 0) + ' / ' + (bud.moisTotal || 12);
    common.bDernier = bud.dernierEncodage ? this.fD(bud.dernierEncodage) + '/' + bud.dernierEncodage.slice(0, 4) : '—';
    common.bStore = st.id;
    common.setBStore = e => this.setState({ bStore: e.target.value });
    common.bStoreOpts = this.open().map(x => ({ id: x.id, nom: x.nom }));
    common.bMeta = st.code + ' · ' + st.zone + ' · franchisé ' + st.fr;
    const budgetAn = P.reduce((a, r) => a + (r.caT || 0), 0);
    let reel = 0, pro = 0; for (let m = 0; m <= this.moisIdx(); m++){ reel += P[m].ca; pro += P[m].caT; }
    common.bBudgetAn = this.fE(budgetAn);
    const sg = v => (v >= 0 ? '+' : '−') + this.fE(Math.abs(v));
    const sgp = v => (v >= 0 ? '+' : '−') + this.fP(Math.abs(v));
    const col = v => v >= 0 ? '#2d7a3e' : '#8D1D2C';
    const theoAn = bud.caTheoriqueAn || null;
    const sais = P.map(r => (r.caT || 0) / (budgetAn || 1));
    const theoM = theoAn ? sais.map(w => theoAn * w) : null;

    const openList = this.open();
    const theoOf = s => { const b = (D.budgets || []).find(x2 => x2.storeId === s.id);
      const Ps = s.perf[this.meta.exercice], ba = Ps.reduce((a, r) => a + (r.caT || 0), 0);
      const an = b && b.caTheoriqueAn ? b.caTheoriqueAn : null;
      return { P: Ps, budgetAn: ba, theoAn: an, theoM: an ? Ps.map(r => an * (r.caT || 0) / (ba || 1)) : null }; };
    const perStore = openList.map(s => Object.assign({ s }, theoOf(s)));
    const scope = S.bScope || 'shop';
    common.bScopeShopSt = this.tabBtn(scope === 'shop'); common.bScopeResSt = this.tabBtn(scope === 'reseau');
    common.bScopeShop = () => this.setState({ bScope: 'shop' }); common.bScopeRes = () => this.setState({ bScope: 'reseau' });
    common.bScopeNom = scope === 'reseau' ? 'Réseau — ' + openList.length + ' magasins ouverts' : st.nom;
    let Pc = P, theoC2 = theoM, budgetAnC = budgetAn, reelC = reel, proC = pro;
    if (scope === 'reseau'){
      Pc = M.MOIS.map((_, i) => ({
        caT: perStore.reduce((a, x2) => a + (x2.P[i].caT || 0), 0),
        ca: perStore.some(x2 => x2.P[i].ca != null) ? perStore.reduce((a, x2) => a + (x2.P[i].ca || 0), 0) : null }));
      theoC2 = perStore.every(x2 => x2.theoM) ? M.MOIS.map((_, i) => perStore.reduce((a, x2) => a + x2.theoM[i], 0)) : null;
      budgetAnC = Pc.reduce((a, r) => a + r.caT, 0);
      reelC = 0; proC = 0; for (let m = 0; m <= this.moisIdx(); m++){ reelC += Pc[m].ca; proC += Pc[m].caT; }
    }
    const theoAnC = theoC2 ? theoC2.reduce((a, v) => a + v, 0) : null;

    const mxB = Math.max(...Pc.map(r => r.caT || 0), ...(theoC2 || [0]));
    const h = v => Math.max(2, Math.round((v || 0) / mxB * 130));
    common.bBars = Pc.map((r, i) => ({
      hasTheo: !!theoC2,
      theoSt: theoC2 ? 'width:16px;height:' + h(theoC2[i]) + 'px;background:var(--pkg-abricot);border-radius:3px 3px 0 0' : '',
      budSt: 'width:16px;height:' + h(r.caT) + 'px;background:#D8CEC2;border-radius:3px 3px 0 0',
      reelSt: r.ca == null ? 'width:16px;height:2px;background:var(--color-border-secondary)' : 'width:16px;height:' + h(r.ca) + 'px;background:var(--color-primary);border-radius:3px 3px 0 0' }));
    common.bMoisCols = M.MOIS.map((nom, i) => ({ nom, st: i <= 6 ? 'color:var(--color-text);font-weight:500' : 'color:var(--color-text-muted)' }));
    const fn = v => v == null ? '—' : Math.round(v).toLocaleString('fr-BE');
    common.bHasTheoChart = !!theoC2;
    common.bLigneTheo = theoC2 ? theoC2.map(v => fn(v)) : [];
    common.bTotTheo = theoC2 ? fn(theoAnC) : '—';
    common.bLigneBud = Pc.map(r => fn(r.caT));
    common.bLigneReel = Pc.map(r => fn(r.ca));
    const ecSt = v => v == null ? 'padding:9px 6px;text-align:right;white-space:nowrap;color:var(--color-text-muted)' : 'padding:9px 6px;text-align:right;white-space:nowrap;font-weight:500;color:' + col(v);
    const pctSt = v => 'font-size:11px;font-weight:400;color:' + (v == null ? 'var(--color-text-muted)' : col(v));
    const ecPair = (a, b) => Pc.map((r, i) => { const av = a(r, i), bv = b(r, i);
      const v = av == null || bv == null ? null : av - bv;
      return { txt: v == null ? '—' : sg(v).replace(' €', ''), st: ecSt(v),
        pct: v == null ? '' : sgp(av / bv - 1), pctSt: pctSt(v) }; });
    common.bLigneEc = ecPair(r => r.ca, r => r.caT);
    common.bEcTheo = theoC2 ? ecPair(r => r.ca, (r, i) => theoC2[i]) : [];
    common.bEcBudTheo = theoC2 ? ecPair(r => r.caT, (r, i) => theoC2[i]) : [];
    const totPair = (av, bv) => { const v = av - bv;
      return { txt: sg(v).replace(' €', ''), st: 'padding:9px 6px 9px 14px;text-align:right;white-space:nowrap;font-weight:500;border-left:0.5px solid var(--color-border-tertiary);color:' + col(v),
        pct: sgp(av / bv - 1), pctSt: pctSt(v) }; };
    const theoCumC = theoC2 ? theoC2.slice(0, 7).reduce((a, v) => a + v, 0) : 0;
    common.bTotEcPair = totPair(reelC, proC);
    common.bTotEcTheo = theoC2 ? totPair(reelC, theoCumC) : null;
    common.bTotEcBudTheo = theoC2 ? totPair(budgetAnC, theoAnC) : null;
    common.bTotBud = fn(budgetAnC); common.bTotReel = fn(reelC);

    const chDefs = (bud.charges || []);
    const monthCharge = (d, i) => {
      if (scope === 'reseau'){
        let v = null;
        perStore.forEach(x2 => { const r = x2.P[i]; if (r.ca == null) return;
          v = (v || 0) + r.ca * (d.champReel ? r[d.champReel] : d.pctBudget) / 100; });
        return v;
      }
      const r = P[i]; return r.ca == null ? null : r.ca * (d.champReel ? r[d.champReel] : d.pctBudget) / 100;
    };
    common.bChRows = chDefs.map(d => {
      let tot = 0, totCa = 0;
      const cells = Pc.map((r, i) => { const v = monthCharge(d, i);
        if (v != null){ tot += v; totCa += r.ca; }
        return { txt: v == null ? '—' : fn(v), pct: v == null ? '' : this.fP(v / r.ca, 1),
          st: 'padding:9px 6px;text-align:right;white-space:nowrap;' + (v == null ? 'color:var(--color-text-muted)' : '') };
      });
      return { nom: d.poste, lev: d.levier, cells, tot: fn(tot), totPct: totCa ? this.fP(tot / totCa, 1) : '—' };
    });
    const chTotM = Pc.map(() => 0); let chTotAll = 0, chTotCa = 0;
    Pc.forEach((r, i) => { let v = null;
      chDefs.forEach(d => { const x2 = monthCharge(d, i); if (x2 != null) v = (v || 0) + x2; });
      chTotM[i] = v; if (v != null){ chTotAll += v; chTotCa += r.ca; } });
    common.bChTotRow = chTotM.map((v, i) => ({ txt: v == null ? '—' : fn(v), pct: v == null ? '' : this.fP(v / Pc[i].ca, 1),
      st: 'padding:10px 6px;text-align:right;white-space:nowrap;font-weight:500;' + (v == null ? 'color:var(--color-text-muted)' : '') }));
    common.bChTotAll = fn(chTotAll); common.bChTotAllPct = chTotCa ? this.fP(chTotAll / chTotCa, 1) : '—';
    common.bMargeRow = chTotM.map((v, i) => { const ca = Pc[i].ca;
      const m = v == null || ca == null ? null : ca - v;
      return { txt: m == null ? '—' : fn(m), pct: m == null ? '' : this.fP(m / ca, 1),
        st: 'padding:10px 6px;text-align:right;white-space:nowrap;font-weight:500;color:' + (m == null ? 'var(--color-text-muted)' : col(m)) }; });
    common.bMargeTot = fn(chTotCa - chTotAll); common.bMargeTotPct = chTotCa ? this.fP((chTotCa - chTotAll) / chTotCa, 1) : '—';

    let mgTotRes = 0, ecTotRes = 0, resReel = 0, resBud = 0, resTheo = 0;
    const sgK = v => (v >= 0 ? '+' : '−') + this.fK(Math.abs(v));
    common.bParMag = perStore.map(x2 => {
      let r7 = 0, b7 = 0; for (let m = 0; m <= this.moisIdx(); m++){ r7 += x2.P[m].ca; b7 += x2.P[m].caT; }
      const t7 = x2.theoM ? x2.theoM.slice(0, this.nMois()).reduce((a, v) => a + v, 0) : null;
      const ecB = r7 - b7, ecT = t7 == null ? null : r7 - t7, mq = ecT != null && ecT < 0 ? -ecT : 0;
      mgTotRes += mq; ecTotRes += ecB; resReel += r7; resBud += b7; resTheo += (t7 || 0);
      return { nom: x2.s.nom, zone: x2.s.zone,
        rowSt: 'border-top:0.5px solid var(--color-border-tertiary);background:' + (x2.s.id === st.id ? 'var(--color-background-secondary)' : 'transparent'),
        reel: this.fK(r7), budget: this.fK(b7), theo: t7 == null ? '—' : this.fK(t7),
        ecB: sgK(ecB), ecBSt: 'padding:9px 6px;text-align:right;white-space:nowrap;font-weight:500;color:' + col(ecB),
        ecBP: sgp(r7 / b7 - 1),
        ecT: ecT == null ? '—' : sgK(ecT), ecTP: ecT == null ? '' : sgp(r7 / t7 - 1),
        real: t7 == null ? '—' : this.fP(r7 / t7, 0),
        realSt: 'padding:9px 6px;text-align:right;white-space:nowrap;font-weight:500;color:' + (t7 == null ? 'var(--color-text-muted)' : col(r7 - t7)),
        ecTSt: 'padding:9px 6px;text-align:right;white-space:nowrap;font-weight:500;color:' + (ecT == null ? 'var(--color-text-muted)' : col(ecT)),
        mq: mq ? this.fK(mq) : '—',
        mqPct: mq && t7 ? this.fP(mq / t7, 1) + ' du théorique' : '',
        mqSt: 'padding:9px 6px 9px 14px;text-align:right;white-space:nowrap;font-weight:500;border-left:0.5px solid var(--color-border-tertiary);color:' + (mq ? 'var(--pkg-abricot)' : 'var(--color-text-muted)'),
        barSt: 'display:block;height:5px;border-radius:999px;background:var(--pkg-abricot);width:' + (t7 ? Math.min(100, Math.round(100 * mq / t7)) : 0) + '%',
        select: () => this.setState({ bStore: x2.s.id }) }; });
    common.bResReal = resTheo ? this.fP(resReel / resTheo, 0) : '—';
    common.bResRealSt = 'padding:10px 6px;text-align:right;white-space:nowrap;font-weight:500;color:' + (resTheo ? col(resReel - resTheo) : 'var(--color-text-muted)');
    common.bResReel = this.fK(resReel); common.bResBud = this.fK(resBud); common.bResTheo = this.fK(resTheo);
    common.bMagTotEc = sgK(ecTotRes); common.bMagTotEcP = sgp(resReel / resBud - 1);
    common.bMagTotEcSt = 'padding:10px 6px;text-align:right;white-space:nowrap;font-weight:500;color:' + col(ecTotRes);
    const ecTRes = resTheo ? resReel - resTheo : null;
    common.bMagTotEcT = ecTRes == null ? '—' : sgK(ecTRes); common.bMagTotEcTP = ecTRes == null ? '' : sgp(resReel / resTheo - 1);
    common.bMagTotEcTSt = 'padding:10px 6px;text-align:right;white-space:nowrap;font-weight:500;color:' + (ecTRes == null ? 'var(--color-text-muted)' : col(ecTRes));
    common.bMagTotMq = mgTotRes ? this.fK(mgTotRes) : '—';
    common.bMagTotMqPct = mgTotRes && resTheo ? this.fP(mgTotRes / resTheo, 1) + ' du théorique' : '';
    common.bMagNote = 'Manque à gagner = part du CA théorique de l’étude de marché non réalisée sur les 7 mois encodés, magasin par magasin. Total réseau : ' + this.fK(mgTotRes) + '.';
  }

  /* --- encodage du budget ----------------------------------------------------- */
  valsEncodage(common){
    const S = this.state, D = this.D, M = this.M;
    const st = this.open().find(x => x.id === S.encStore) || this.open()[0];
    const bud = (D.budgets || []).find(b => b.storeId === st.id) || { charges: [] };
    const P = st.perf[this.meta.exercice];
    const d = S.encDraft[st.id] || {};
    const val = (k, def) => d[k] != null ? d[k] : def;
    const set = k => e => { const v = e.target.value;
      this.setState(s2 => ({ encDraft: Object.assign({}, s2.encDraft, { [st.id]: Object.assign({}, s2.encDraft[st.id], { [k]: v }) }) })); };
    const num = v => { const n = parseFloat(String(v).replace(',', '.')); return isNaN(n) ? 0 : n; };

    common.encStore = st.id; common.setEncStore = e => this.setState({ encStore: e.target.value });
    common.encStoreOpts = this.open().map(x => ({ id: x.id, nom: x.nom }));
    common.encMeta = st.code + ' · ' + st.zone + ' · franchisé ' + st.fr;
    common.encExercice = String(this.meta.exercice);

    const budAnRef = P.reduce((a, r) => a + (r.caT || 0), 0) || 1;
    const theoAnRef = bud.caTheoriqueAn || 0;
    const mois = M.MOIS.map((nom, i) => ({ nom,
      // Sans budget encodé, `caT` est nul : pré-remplir « 0 » donne douze
      // champs à zéro qu'un simple clic sur Enregistrer fige en base — le
      // budget paraît enregistré et vaut zéro partout. Champ VIDE à la place.
      valeur: val('ca' + i, P[i].caT != null ? Math.round(P[i].caT) : ''), set: set('ca' + i),
      theo: val('th' + i, theoAnRef ? Math.round(theoAnRef * (P[i].caT || 0) / budAnRef) : ''), setTheo: set('th' + i) }));
    common.encMois = mois;
    const caTot = mois.reduce((a, m) => a + num(m.valeur), 0);
    const theoTot = mois.reduce((a, m) => a + num(m.theo), 0);
    common.encCaTot = this.fE(caTot);
    common.encTheoTot = this.fE(theoTot);
    const dTheo = theoTot ? caTot - theoTot : null;
    common.encTheoDelta = dTheo == null ? '—' : (dTheo >= 0 ? '+' : '−') + this.fE(Math.abs(dTheo)) + ' (' + (dTheo >= 0 ? '+' : '−') + this.fP(Math.abs(caTot / theoTot - 1), 1) + ')';
    common.encTheoDeltaSt = 'font-size:22px;font-weight:500;color:' + (dTheo == null ? 'var(--color-text-muted)' : dTheo >= 0 ? '#2d7a3e' : '#8D1D2C');
    const inputSt = 'width:100%;font-family:var(--font-ui);font-size:13px;text-align:right;border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:7px 9px;background:var(--color-surface);color:var(--color-text)';
    common.encInputSt = inputSt;

    const em = bud.etudeMarche || {};
    const baseTheo = val('theoBase', theoAnRef ? Math.round(theoAnRef / 0.7) : '');
    common.encTheoBase = baseTheo; common.setEncTheoBase = set('theoBase');
    const rampDef = { a1: 70, a2: 80, a3: 90 };
    const ramp = { a1: num(val('ramp1', rampDef.a1)), a2: num(val('ramp2', rampDef.a2)), a3: num(val('ramp3', rampDef.a3)) };
    common.encRamp = [
      { k: 'Année 1', valeur: val('ramp1', rampDef.a1), set: set('ramp1') },
      { k: 'Année 2', valeur: val('ramp2', rampDef.a2), set: set('ramp2') },
      { k: 'Année 3', valeur: val('ramp3', rampDef.a3), set: set('ramp3') }];
    const anneeEx = String(val('annee', '1'));
    common.encAnnee = anneeEx; common.setEncAnnee = set('annee');
    common.encAnneeOpts = [{ v: '1', nom: 'Année 1 — ouverture' }, { v: '2', nom: 'Année 2' }, { v: '3', nom: 'Année 3' }, { v: '4', nom: 'Régime établi (100 %)' }];
    const coef = anneeEx === '1' ? ramp.a1 : anneeEx === '2' ? ramp.a2 : anneeEx === '3' ? ramp.a3 : 100;
    const theoExercice = num(baseTheo) * coef / 100;
    common.encCoef = String(coef).replace('.', ',') + ' % du potentiel à maturité';
    common.encTheoExercice = this.fE(theoExercice);
    common.encRampNote = 'Potentiel à maturité ' + this.fE(num(baseTheo)) + ' × ' + String(coef).replace('.', ',') + ' % = CA théorique de l’exercice.';

    const poidsDef = P.map(r => 100 * (r.caT || 0) / budAnRef);
    let poidsTot = 0;
    common.encSais = M.MOIS.map((nom, i) => { const v = val('sais' + i, poidsDef[i].toFixed(1).replace('.', ','));
      poidsTot += num(v);
      return { nom, valeur: v, set: set('sais' + i), montant: this.fE(theoExercice * num(v) / 100) }; });
    common.encSaisTot = String(poidsTot.toFixed(1)).replace('.', ',') + ' %';
    common.encSaisTotSt = 'font-size:13px;font-weight:500;color:' + (Math.abs(poidsTot - 100) < 0.6 ? '#2d7a3e' : '#8D1D2C');
    common.encLisser = () => { const upd = {};
      M.MOIS.forEach((_, i) => { const w = num(val('sais' + i, poidsDef[i])); upd['th' + i] = Math.round(theoExercice * w / (poidsTot || 100)); });
      this.setState(s2 => ({ encDraft: Object.assign({}, s2.encDraft, { [st.id]: Object.assign({}, s2.encDraft[st.id], upd) }) }));
      this.notify('CA théorique lissé sur les 12 mois — ' + st.nom); };

    const anxNom = val('anxNom', (em.annexe && em.annexe.nom) || '');
    const anxTaille = val('anxTaille', (em.annexe && em.annexe.taille) || '');
    common.encAnxNom = anxNom;
    common.encAnxMeta = anxNom ? [anxTaille, 'ajoutée le ' + this.fD(M.TODAY)].filter(Boolean).join(' · ') : 'Aucun document joint';
    common.encHasAnx = !!anxNom;
    common.encAnxUrl = val('anxUrl', (em.annexe && em.annexe.url) || ''); common.setEncAnxUrl = set('anxUrl');
    common.encAnxPick = e => { const f = e.target.files && e.target.files[0]; if (!f) return;
      const ko = f.size > 1048576 ? (f.size / 1048576).toFixed(1).replace('.', ',') + ' Mo' : Math.max(1, Math.round(f.size / 1024)) + ' Ko';
      this.setState(s2 => ({ encDraft: Object.assign({}, s2.encDraft, { [st.id]: Object.assign({}, s2.encDraft[st.id], { anxNom: f.name, anxTaille: ko }) }) }));
      this.log('Budget', st.nom, 'Annexe étude de marché ajoutée : ' + f.name + ' (' + ko + ')');
      this.notify('Annexe jointe — ' + f.name); };
    common.encAnxDel = () => { this.setState(s2 => ({ encDraft: Object.assign({}, s2.encDraft, { [st.id]: Object.assign({}, s2.encDraft[st.id], { anxNom: '', anxTaille: '' }) }) }));
      this.notify('Annexe retirée'); };

    common.encEtudeDate = val('etudeDate', em.date || ''); common.setEncEtudeDate = set('etudeDate');
    common.encEtudeSrc = val('etudeSrc', em.source || ''); common.setEncEtudeSrc = set('etudeSrc');
    common.encMenages = val('menages', em.potentielMenages || ''); common.setEncMenages = set('menages');

    let pctTot = 0, pctTotT = 0;
    const pc = v => String(num(v).toFixed(1)).replace('.', ',') + ' %';
    common.encCharges = (bud.charges || []).map((c2, i) => { const v = val('ch' + i, c2.pctBudget), vt = val('cht' + i, c2.pctTheorique != null ? c2.pctTheorique : c2.pctBudget);
      pctTot += num(v); pctTotT += num(vt);
      const ec = num(v) - num(vt);
      return { nom: c2.poste, lev: c2.levier, valeur: v, set: set('ch' + i), valeurT: vt, setT: set('cht' + i),
        montant: this.fE(caTot * num(v) / 100), montantT: this.fE(theoTot * num(vt) / 100),
        ecart: (ec >= 0 ? '+' : '−') + pc(Math.abs(ec)).replace(' %', ' pt'),
        ecartSt: 'padding:9px 0 9px 6px;text-align:right;white-space:nowrap;font-weight:500;color:' + (Math.abs(ec) < 0.05 ? 'var(--color-text-muted)' : ec > 0 ? '#8D1D2C' : '#2d7a3e') }; });
    common.encPctTot = pc(pctTot); common.encPctTotT = pc(pctTotT);
    common.encChTot = this.fE(caTot * pctTot / 100); common.encChTotT = this.fE(theoTot * pctTotT / 100);
    const mgPct = 100 - pctTot, mgPctT = 100 - pctTotT;
    common.encMarge = this.fE(caTot * mgPct / 100);
    common.encMargePct = pc(mgPct) + ' du CA budgété';
    common.encMargeSt = 'font-size:24px;font-weight:500;line-height:1.1;color:' + (mgPct <= 0 ? '#8D1D2C' : mgPct < 12 ? '#8a5a13' : '#2d7a3e');
    common.encMargeT = theoTot ? this.fE(theoTot * mgPctT / 100) : '—';
    common.encMargePctT = theoTot ? pc(mgPctT) + ' du CA théorique' : 'CA théorique non renseigné';
    common.encAlerte = pctTot > 100 ? 'La somme des charges validées dépasse 100 % du CA : le budget est déficitaire.' : false;

    common.encReset = () => this.setState(s2 => ({ encDraft: Object.assign({}, s2.encDraft, { [st.id]: {} }) }));
    common.encSave = () => {
      const jr = 'Budget ' + this.meta.exercice + ' encodé — CA validé ' + this.fE(caTot) + ', CA théorique ' + this.fE(theoTot) + ', charges validées ' + common.encPctTot + ' (théoriques ' + common.encPctTotT + '), marge ' + common.encMargePct;
      if (!caTot) { this.notify('Renseignez au moins un mois de CA avant d’enregistrer.'); return; }
      this.api('PUT', '/stores/' + st.id + '/budget?exercice=' + this.meta.exercice, {
        caMensuel: mois.map(m => num(m.valeur)),
        caTheoriqueMensuel: mois.map(m => num(m.theo)),
        panierEngagement: bud.panierEngagement || null,
        etudeMarche: {
          date: common.encEtudeDate || null, source: common.encEtudeSrc || null,
          potentielMenages: num(common.encMenages) || null, potentielMaturite: num(baseTheo) || null,
          anneeExploitation: +anneeEx, monteeEnRegime: { a1: ramp.a1, a2: ramp.a2, a3: ramp.a3 },
          saisonnalite: common.encSais.map(s => num(s.valeur)),
          annexe: anxNom ? { nom: anxNom, url: common.encAnxUrl || null, taille: anxTaille || null, date: M.TODAY } : null
        },
        charges: common.encCharges.map((c2, i) => ({ poste: c2.nom, levier: c2.lev, pctBudget: num(c2.valeur), pctTheorique: num(c2.valeurT), champReel: (bud.charges[i] || {}).champReel || null })),
        journal: jr
      }).then(r => {
        // Ne jamais annoncer « enregistré » sans la réponse du serveur : un
        // 404/422 se résout normalement côté fetch, et l'écran mentait.
        if (r && r.ok === false) { this.notify('Échec de l’enregistrement : ' + (r.error || 'refusé par le serveur')); return; }
        this.log('Budget', st.nom, jr);
        this.notify('Budget enregistré — ' + st.nom + ' · ' + this.fE(caTot));
        return readOne('/stores/perf?granularite=mois&annees=' + (this.exo() - 1) + ',' + this.exo())
          .then(p => { if (p) { this.D.perfRaw = p; } this.setState({}); });
      });
    };
    common.encNote = 'À l’enregistrement, la série validée devient le budget de référence du magasin et la série théorique alimente le CA d’étude de marché : elles servent de référence au suivi mensuel et au calcul des écarts. Le CA théorique et l’étude de marché restent indépendants du budget négocié avec ' + st.fr + '.';
  }

  /* --- scoring produits -------------------------------------------------------- */
  /* Perte d'une référence, magasin par magasin. Un taux réseau moyen peut
     cacher UN magasin qui jette : la modale montre la dispersion. */
  pdOpenWaste(id, nom){
    const per = (this.meta && this.meta.periodeProduits) || '';
    this.setState({ pdWaste: { id, nom, chargement: true, d: null } });
    readOne('/products/waste?produit=' + encodeURIComponent(id) + (per ? '&periode=' + encodeURIComponent(per) : ''))
      .then(d => this.setState(s => (s.pdWaste && s.pdWaste.id === id)
        ? { pdWaste: Object.assign({}, s.pdWaste, { chargement: false, d: d || null }) } : {}));
  }
  /**
   * Écran Exploitation — le P&L court des magasins.
   *
   * Le serveur a déjà tranché les questions délicates (quel jour montrer,
   * quel objectif au prorata) : on n'en refait aucune ici. L'écran se borne à
   * mettre en forme, et à distinguer trois états que la donnée impose —
   * mois clos, mois partiellement encodé, mois sans budget.
   */
  /**
   * Tableau N vs N-1 de toutes les boutiques.
   * Chargé à l'ouverture de l'écran puis à chaque changement de période ; une
   * seule requête réseau, jamais une par magasin.
   */
  exChargeReseau(per){
    if (this._exReseauEnCours === per) return;
    this._exReseauEnCours = per;
    this.setState({ exReseau: { per, chargement: true, d: null } });
    readOne('/exploitation/reseau?periode=' + per)
      .then(d => { this._exReseauEnCours = null;
        this.setState(s => (s.exReseau && s.exReseau.per === per)
          ? { exReseau: { per, chargement: false, d: d || null } } : {}); });
  }
  /** Ouvre le détail d'un magasin : lecture ponctuelle, hors chargement initial. */
  exOpen(id, nom){
    const per = this.state.exPeriode || 'mois';
    this.setState({ exDetail: { id, nom, per, chargement: true, d: null } });
    readOne('/exploitation/magasin?id=' + encodeURIComponent(id) + '&periode=' + per)
      .then(d => this.setState(s => (s.exDetail && s.exDetail.id === id)
        ? { exDetail: Object.assign({}, s.exDetail, { chargement: false, d: d || null }) } : {}));
  }
  /**
   * Catalogue, assortiment et planogramme : trois lectures d'une même liste.
   *
   * Les filtres et la recherche sont partagés — passer d'un écran à l'autre en
   * gardant sa sélection est le comportement attendu quand on travaille sur un
   * sous-ensemble de références.
   */
  valsReferentiel(common){
    const S = this.state, D = this.D;
    const cat = D.prodCatalogue || [];
    common.refVide = !cat.length;

    const fG = S.refG || 'Tous les groupes';
    const fC = S.refC || 'Toutes les catégories';
    const fP = S.refP || 'Toutes les gammes';
    const q = (S.refQ || '').trim().toLowerCase();
    common.refG = fG; common.refC = fC; common.refP = fP; common.refQ = S.refQ || '';
    common.refSetG = e => this.setState({ refG: e.target.value, refC: 'Toutes les catégories' });
    common.refSetC = e => this.setState({ refC: e.target.value });
    common.refSetP = e => this.setState({ refP: e.target.value });
    common.refSetQ = e => this.setState({ refQ: e.target.value });

    common.refGroupes = ['Tous les groupes'].concat(
      [...new Set(cat.map(p => p.groupe).filter(Boolean))].sort());
    // Les catégories proposées suivent le groupe choisi : offrir les 56 quand
    // un groupe en contient 6 oblige à chercher ce qui ne s'appliquera pas.
    const pourCat = fG === 'Tous les groupes' ? cat : cat.filter(p => p.groupe === fG);
    common.refCategories = ['Toutes les catégories'].concat(
      [...new Set(pourCat.map(p => p.categorie).filter(Boolean))].sort());
    common.refGammes = ['Toutes les gammes'].concat(
      [...new Set(cat.flatMap(p => p.periods || []))].sort());

    let lignes = cat.filter(p =>
      (fG === 'Tous les groupes' || p.groupe === fG) &&
      (fC === 'Toutes les catégories' || p.categorie === fC) &&
      (fP === 'Toutes les gammes' || (p.periods || []).indexOf(fP) >= 0) &&
      (!q || (p.nom || '').toLowerCase().indexOf(q) >= 0 || String(p.ref).indexOf(q) >= 0));

    if (common.isAsso) { lignes = lignes.filter(p => p.must || S.refToutes); }
    if (common.isPlano) { lignes = lignes.filter(p => p.zone || S.refToutes); }
    common.refToutes = !!S.refToutes;
    common.refBascule = () => this.setState({ refToutes: !S.refToutes });
    common.refTotal = cat.length;
    common.refFiltres = lignes.length;

    // Compteurs de couverture : sur ces deux écrans, ce qui manque est
    // l'information principale — pas ce qui est déjà renseigné.
    common.refMust = cat.filter(p => p.must).length;
    common.refPlaces = cat.filter(p => p.zone).length;

    const ed = S.refEdit;
    common.refEdit = ed ? {
      ref: ed.ref, nom: ed.nom, mode: ed.mode, busy: !!ed.busy, err: ed.err || '',
      champs: ed.champs,
      set: k => e => this.setState(s2 => ({ refEdit: Object.assign({}, s2.refEdit,
        { champs: Object.assign({}, s2.refEdit.champs, { [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value }) }) })),
      close: () => this.setState({ refEdit: null }),
      save: () => this.refSave()
    } : null;

    common.refLignes = lignes.slice(0, 400).map(p => ({
      ref: String(p.ref), nom: p.nom, categorie: p.categorie || '—',
      groupe: p.groupe || '—',
      gamme: (p.periods || []).length ? (p.periods.length > 1 ? p.periods.length + ' gammes' : p.periods[0]) : '—',
      prix: this.fEd(p.prix), cout: this.fEd(p.mat),
      // Une marge non publiée n'est pas une marge nulle : le coût est visible,
      // la marge se tait, et la mention dit laquelle des deux on regarde.
      marge: p.margePct == null ? (p.mat != null ? 'non fiable' : '—') : this.fP(p.margePct, 0),
      margeC: this.echelleMarge(p.margePct == null ? null : 100 * p.margePct),
      // Marge NETTE, commission de marque déduite : c'est celle que pilote la
      // centrale d'achat, qui n'a plus d'écran catalogue propre. Deux
      // catalogues sur les mêmes 711 références finissaient par se contredire.
      commission: this.fU(p.commission),
      margeNette: p.margeNettePct == null ? '—' : this.fP(p.margeNettePct, 0),
      margeNetteEur: this.fU(p.margeNette),
      margeNetteC: this.echelleMarge(p.margeNettePct == null ? null : 100 * p.margeNettePct),
      must: !!p.must, qmin: p.qmin || 0,
      // Assortiment : la quantité minimale se saisit EN LIGNE, et le batch de
      // la fiche produit est proposé à côté. Un minimum qui n'est pas un
      // multiple du batch est intenable en production : le four sort des
      // fournées, pas des unités. Le proposer d'un clic évite de le retaper.
      bmin: p.bmin || 0, bmult: p.bmult || 1,
      batchTxt: p.bmin ? (p.bmin + (p.bmult > 1 ? ' × ' + p.bmult : '')) : '',
      qminSet: e => this.refQminPut(p.ref, e.target.value),
      // « Obligatoire » se coche dans la ligne. Un badge en lecture seule
      // renvoyait à la fiche pour un booléen : c'est le geste même de cet
      // écran, il devait s'y faire.
      mustSet: () => this.refMustPut(p.ref, !p.must),
      qminBatch: p.bmin ? () => this.refQminPut(p.ref, p.bmin) : null,
      // Un minimum inférieur au batch ne peut pas être produit tel quel.
      qminSousBatch: !!(p.must && p.bmin && (p.qmin || 0) > 0 && (p.qmin || 0) < p.bmin),
      zone: p.zone || '', meuble: p.meuble || '', niveau: p.niveau || '',
      slot: p.slot == null ? '' : String(p.slot),
      place: !!p.zone,
      dlv: p.dlv ? p.dlv + ' h' : '—',
      parametre: !!p.parametre,
      // Au planogramme, la ligne ouvre la FICHE de présentation : c'est là que
      // se choisit l'emplacement, sur le plan, et non dans un formulaire où il
      // fallait retaper « Vitrine 1 » sans savoir ce qui était libre.
      ouvrir: common.isPlano
        ? () => this.plFicheOuvrir(String(p.ref))
        : () => this.refOpen(p, common.isAsso ? 'asso' : 'fiche')
    }));
    common.refTronque = lignes.length > 400 ? (lignes.length - 400) : 0;
    common.refEchelle = this.paliersMarge();
    common.refSansMarge = cat.filter(p => p.margePct == null).length;
    return common;
  }
  /** Ouvre l'édition d'une référence — fiche de production ou emplacement. */
  refOpen(p, mode){
    this.setState({ refEdit: { ref: String(p.ref), nom: p.nom, mode, busy: false, err: '',
      champs: { must: !!p.must, qmin: p.qmin || 0, prep: p.prep || 0, cuisson: p.cuisson || 0,
        fin: p.fin || 0, bmin: p.bmin || 0, bmult: p.bmult || 1, four: p.four || 0,
        dlv: p.dlv || 0, mat: p.mat == null ? '' : p.mat, prix: p.prix == null ? '' : p.prix,
        profil: p.profil || '',
        zone: p.zone || '', meuble: p.meuble || '', niveau: p.niveau || '',
        slot: p.slot == null ? '' : p.slot } } });
  }
  /** Enregistre la référence en cours d'édition, puis recharge le catalogue. */
  /**
   * Coche ou décoche « obligatoire » depuis la ligne.
   *
   * Décocher remet le minimum à zéro : un minimum sur une référence facultative
   * ne veut rien dire, et le laisser en place ferait réapparaître un chiffre
   * orphelin à la prochaine coche.
   */
  refMustPut(ref, val){
    const cat = (this.D.prodCatalogue || []).find(p => p.ref === ref);
    if (!cat) { return; }
    cat.must = !!val;
    if (!val) { cat.qmin = 0; }
    this.setState({});
    this.api('PUT', '/production/produit/' + encodeURIComponent(ref),
      Object.assign({}, cat, { must: val ? 1 : 0, qmin: val ? (cat.qmin || 0) : 0 }))
      .then(r => { if (!r || r.error) { this.notify('Non enregistré : ' + ((r && r.error) || 'échec')); } });
  }
  /**
   * Écrit la quantité minimale d'assortiment, sans passer par la fiche.
   *
   * Déclarer une référence obligatoire et lui donner son minimum sont le même
   * geste : obliger à rouvrir la fiche pour un entier faisait abandonner la
   * saisie. L'écriture emprunte la route de la fiche, en ne portant que les
   * deux champs concernés — le reste de la fiche n'est pas touché.
   */
  refQminPut(ref, val){
    const n = Math.max(0, Math.round(+val || 0));
    const cat = (this.D.prodCatalogue || []).find(p => p.ref === ref);
    if (!cat) { return; }
    // Optimiste à l'affichage, confirmé au serveur : sans cela le champ se
    // vide sous les doigts à chaque frappe, le temps de l'aller-retour.
    cat.qmin = n;
    if (n > 0) { cat.must = true; }
    this.setState({});
    this.api('PUT', '/production/produit/' + encodeURIComponent(ref),
      Object.assign({}, cat, { qmin: n, must: n > 0 ? 1 : (cat.must ? 1 : 0) }))
      .then(r => { if (!r || r.error) { this.notify('Minimum non enregistré : ' + ((r && r.error) || 'échec')); } });
  }
  refSave(){
    const e = this.state.refEdit; if (!e || e.busy) return;
    this.setState(s => ({ refEdit: Object.assign({}, s.refEdit, { busy: true, err: '' }) }));
    const url = e.mode === 'plano' ? '/production/planogramme/' : '/production/produit/';
    this.api('PUT', url + encodeURIComponent(e.ref), e.champs)
      .then(r => {
        // Ne PAS annoncer un enregistrement que le serveur n'a pas confirmé :
        // le budget avait ce défaut, et le message rassurait à tort.
        if (!r || r.error) {
          this.setState(s => ({ refEdit: Object.assign({}, s.refEdit,
            { busy: false, err: (r && r.error) || 'Échec de l\u2019enregistrement.' }) }));
          return;
        }
        return readOne('/production/catalogue').then(c => {
          if (c) { this.D.prodCatalogue = c; }
          this.setState({ refEdit: null });
          this.notify('« ' + e.nom + ' » enregistré');
        });
      });
  }
  /** Suivi de production — produit, jeté, motifs, par boutique et référence. */
  valsProduction(common){
    const S = this.state;
    const d = S.prodSuivi;
    if (!d && !this._prodEnCours){
      this._prodEnCours = true;
      readOne('/production/suivi').then(r => { this._prodEnCours = false;
        this.setState({ prodSuivi: r || { vide: true } }); });
    }
    common.prChargement = !d;
    if (!d) return common;
    common.prPeriode = (d.du || '') + ' → ' + (d.au || '');
    common.prAvert = d.avertissement || '';
    const r = d.reseau || {};
    common.prReseau = [
      { l: 'vendu', v: (r.vendu || 0).toLocaleString('fr-BE') },
      { l: 'jeté', v: (r.jete || 0).toLocaleString('fr-BE') },
      { l: 'taux de perte', v: r.taux == null ? '—' : this.fP(r.taux, 1) },
      { l: 'fournées déclarées', v: (r.produit || 0).toLocaleString('fr-BE') }
    ];
    const tx = t => t == null ? 'var(--color-text-muted)'
      : (t >= 0.08 ? 'var(--color-primary)' : t >= 0.05 ? '#C17A2A' : '#2d7a3e');
    common.prMagasins = (d.magasins || []).map(m => ({
      magasin: m.magasin, vendu: (m.vendu || 0).toLocaleString('fr-BE'),
      jete: (m.jete || 0).toLocaleString('fr-BE'),
      taux: m.taux == null ? '—' : this.fP(m.taux, 1), col: tx(m.taux),
      // « Journal non tenu » n'est pas « zéro fournée » : la distinction décide
      // de ce qu'on va dire au franchisé.
      note: m.journalTenu ? '' : 'journal des fournées non tenu'
    }));
    const mx = Math.max.apply(null, (d.produits || []).map(p => p.jete || 0).concat([1]));
    common.prProduits = (d.produits || []).slice(0, 20).map(p => ({
      nom: p.nom, jete: (p.jete || 0).toLocaleString('fr-BE'),
      vendu: (p.vendu || 0).toLocaleString('fr-BE'),
      taux: p.taux == null ? '—' : this.fP(p.taux, 1), col: tx(p.taux),
      w: Math.max(2, 100 * (p.jete || 0) / mx).toFixed(0) + '%'
    }));
    common.prMotifs = (d.motifs || []).map(m => ({
      motif: m.motif || '—', quantite: (m.quantite || 0).toLocaleString('fr-BE'),
      lignes: (m.lignes || 0).toLocaleString('fr-BE')
    }));
    return common;
  }
  /**
   * Analyse dans le temps — une catégorie ou une référence, sur un horizon.
   *
   * La série coûte un appel par point : elle n'est donc lancée que sur une
   * sélection explicite, jamais à l'ouverture de l'écran.
   */
  valsAnalyse(common){
    const S = this.state, D = this.D;
    const type = S.anType || 'categorie';
    const gran = S.anGran || 'mois';
    common.anType = type; common.anGran = gran;

    // Le sélecteur est rempli par la SOURCE des chiffres, pas par le catalogue.
    // Les ventes sont ventilées par groupe (12), le catalogue par catégorie
    // (81) : proposer « Boissons chaudes » quand l'API ne connaît que
    // « Boissons » rendait une série vide sans le moindre message d'erreur.
    const O = D.anOptions;
    common.anOptChargement = !O && !!this._anOptEnCours;
    common.anOptErreur = O && O.erreur ? O.erreur : '';
    common.anOptPeriode = O && O.periode ? O.periode : '';
    const pds = (l, u) => (l || []).map(c => ({ id: c.cle,
      nom: c.nom + (c.poids ? ' · ' + Math.round(c.poids).toLocaleString('fr-BE') + u : '') }));
    common.anCategories = pds(O && O.categories, ' €');
    common.anSousCategories = pds(O && O.souscategories, ' u');
    common.anProduits = pds(O && O.produits, ' u');
    const toutes = { categorie: common.anCategories, souscategorie: common.anSousCategories,
      produit: common.anProduits }[type] || [];
    // Recherche insensible aux accents : sur « Viennoiserie réduction » ou
    // « Épicerie », taper au clavier plat ne doit pas rendre la liste muette.
    const norm = v => (v || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    const q = norm((S.anFiltre || '').trim());
    common.anFiltre = S.anFiltre || '';
    common.anFiltrer = e => this.setState({ anFiltre: e.target.value });
    common.anListeTotal = toutes.length;
    // La sélection en cours reste toujours dans la liste, même si elle ne
    // répond plus au filtre : sans quoi le sélecteur afficherait autre chose
    // que le graphique tracé juste en dessous.
    common.anListe = q ? toutes.filter(o => norm(o.nom).includes(q) || o.id === S.anCle) : toutes;

    const onglet = on => 'border:none;cursor:pointer;font-family:var(--font-ui);font-size:12.5px;'
      + 'padding:6px 14px;border-radius:8px;'
      + (on ? 'background:var(--color-primary);color:#fff;font-weight:500'
            : 'background:transparent;color:var(--color-text-muted)');
    common.anTypeBtns = [['categorie', 'Groupe'], ['souscategorie', 'Catégorie'], ['produit', 'Référence']]
      .map(b => ({ label: b[1], st: onglet(type === b[0]),
        go: () => this.setState({ anType: b[0], anCle: '', anData: null, anFiltre: '' }) }));
    // Réseau ou magasin par magasin. La bascule n'a de sens qu'au niveau des
    // groupes : ailleurs l'API ne rend qu'un volume réseau (cf. parMagasin).
    const vue = S.anVue === 'magasin' ? 'magasin' : 'reseau';
    common.anVue = vue;
    common.anVueBtns = [['reseau', 'Réseau'], ['magasin', 'Par magasin']]
      .map(b => ({ label: b[1], st: onglet(vue === b[0]), go: () => this.setState({ anVue: b[0] }) }));
    common.anGranBtns = [['mois', 'Mois'], ['trimestre', 'Trimestre'], ['annee', 'Année']]
      .map(b => ({ label: b[1], st: onglet(gran === b[0]),
        go: () => { this.setState({ anGran: b[0] }); if (S.anCle) this.anCharge(S.anCle, type, b[0]); } }));

    common.anCle = S.anCle || '';
    common.anChoisir = e => { const v = e.target.value;
      this.setState({ anCle: v }); if (v) this.anCharge(v, type, gran); };

    const d = S.anData;
    common.anChargement = !!S.anBusy;
    common.anVide = !S.anCle;
    common.anMotif = d && d.motif ? d.motif : '';
    common.anSource = d && d.source ? d.source : '';
    common.anPlafond = d && d.plafond ? d.plafond : 0;
    common.anMesure = d && d.mesure ? d.mesure : '';
    common.anLibelle = d && d.libelle ? d.libelle : '';
    common.anParMagasin = d ? (d.parMagasin || 'attente') : 'attente';
    common.anParMagasinMotif = d && d.parMagasinMotif ? d.parMagasinMotif : '';
    common.anParMagasinMesure = d && d.parMagasinMesure ? d.parMagasinMesure : '';
    // La bascule ne s'affiche que là où elle mène quelque part.
    common.anVueDispo = common.anParMagasin === 'ok';
    const uMag = d && d.parMagasinUnite === 'u';
    const euro = !d || d.unite !== 'u';
    const fmt = v => Math.round(v).toLocaleString('fr-BE') + (euro ? ' €' : ' u');
    common.anGraphe = null; common.anLignes = null;
    if (d && d.points && d.points.length) {
      const pts = d.points;
      const W = 640, H = 200, PB = 26, PL = 4, sw = (W - PL) / pts.length;
      const parShop = common.anVueDispo && common.anVue === 'magasin';
      const mags = (d.magasins || []);

      // Une échelle unique. Deux axes y feraient tenir n'importe quoi côte à
      // côte : N et N-1 se comparent sur la même graduation, ou pas du tout.
      const fmtM = v => Math.round(v).toLocaleString('fr-BE') + (uMag ? ' u' : ' €');
      const tous = [];
      pts.forEach(p => {
        if (parShop) { mags.forEach(m => { const v = (p.parMagasin || {})[m.id]; if (v != null) tous.push(v); }); }
        else { if (p.valeur != null) tous.push(p.valeur); if (p.n1 != null) tous.push(p.n1); }
      });
      const hi = tous.length ? Math.max.apply(null, tous) * 1.18 : 0;
      const y = v => (H - PB) * (1 - v / hi);
      const labels = pts.map((p, i) => ({ x: (PL + i * sw + sw / 2).toFixed(1), y: H - 9, t: p.libelle,
        c: p.enCours ? 'var(--color-primary)' : 'var(--color-text-muted)' }));
      const grille = hi > 0 ? [0.5, 1].map(f => ({ y: y(hi * f).toFixed(1), w: W })) : [];

      if (parShop) {
        // Cadre PROPRE à cette vue, bien plus large que celui des barres. Le
        // même viewBox de 640 étiré sur 1500 pixels grossissait tout texte de
        // 2,3 fois : les libellés de mois criaient pendant que les courbes
        // chuchotaient. À cette échelle-ci, un corps 11 reste un corps 11.
        const LW = 1180, LH = 380, LB = 42, LL = 44, PR = 150, PD = LW - PR;

        // La période EN COURS est écartée de cette vue. Un mois entamé fait
        // plonger les cinq courbes d'un coup : cette chute occupait la moitié
        // du cadre et écrasait les mois clos — le signal — dans une bande
        // étroite. Le verdict ne compte déjà que les périodes closes ; le
        // graphique montre donc exactement ce que le verdict mesure.
        const iC = pts.map((p, i) => p.enCours ? -1 : i).filter(i => i >= 0);
        const ptsC = iC.map(i => pts[i]);
        const nC = ptsC.length;
        const xi = i => +(LL + i * (PD - LL) / nC + (PD - LL) / nC / 2).toFixed(1);
        const PAL = ['#D55E00', '#0072B2', '#009E73', '#CC79A7', '#E69F00'];

        // Tendance réseau : la MOYENNE par magasin, pas le total. Le total vaut
        // quatre fois une boutique et écraserait les courbes sur le bas du cadre.
        const moy = ptsC.map(p => {
          const v = mags.map(m => (p.parMagasin || {})[m.id]).filter(x => x != null);
          return v.length ? v.reduce((a, b) => a + b, 0) / v.length : null;
        });
        const brut = mags.map(m => ptsC.map(p => { const v = (p.parMagasin || {})[m.id]; return v == null ? null : v; }));

        // SUIVRE LE RÉSEAU N'EST PAS ÊTRE AU NIVEAU DU RÉSEAU. Une boutique
        // trois fois plus grosse que la moyenne peut en épouser parfaitement la
        // tendance. La base 100 ramène chacun à son propre point de départ :
        // ne restent que les formes, et c'est là seulement qu'un décrochage
        // se voit.
        const base100 = S.anBase !== 'valeurs';
        common.anBase = base100 ? '100' : 'valeurs';
        common.anBaseBtns = [['100', 'Tendance base 100'], ['valeurs', 'Valeurs']]
          .map(o => ({ label: o[1], st: onglet((base100 ? '100' : 'valeurs') === o[0]),
            go: () => this.setState({ anBase: o[0] }) }));
        const indexer = a => { const b = a.find(v => v != null && v !== 0);
          return b == null ? a.map(() => null) : a.map(v => v == null ? null : v / b * 100); };
        const aff = base100 ? brut.map(indexer) : brut;
        const affMoy = base100 ? indexer(moy) : moy;

        const tous = [];
        aff.forEach(a => a.forEach(v => { if (v != null) tous.push(v); }));
        affMoy.forEach(v => { if (v != null) tous.push(v); });
        let lo = tous.length ? Math.min.apply(null, tous) : 0;
        let hh = tous.length ? Math.max.apply(null, tous) : 1;
        if (base100) { lo = Math.min(lo, 100); hh = Math.max(hh, 100); }
        const mrg = (hh - lo) * 0.12 || 1;
        lo = base100 ? lo - mrg : 0; hh = hh + mrg;
        const yy = v => +((LH - LB) * (1 - (v - lo) / (hh - lo || 1))).toFixed(1);

        // Graduations CHIFFRÉES. Sans elles on lit des pentes sans savoir de
        // quelle amplitude — et en base 100, la ligne 100 est la référence même.
        const ticks = [];
        if (base100) {
          const pas = (hh - lo) > 60 ? 20 : (hh - lo) > 30 ? 10 : 5;
          for (let v = Math.ceil(lo / pas) * pas; v <= hh; v += pas) {
            ticks.push({ y: yy(v), t: String(v), ref: v === 100 });
          }
          if (!ticks.some(t => t.ref)) { ticks.push({ y: yy(100), t: '100', ref: true }); }
        } else {
          [0.5, 1].forEach(f => ticks.push({ y: yy(lo + (hh - lo) * f), t: this.fK(lo + (hh - lo) * f), ref: false }));
        }

        const evo = a => { const c = a.filter(v => v != null);
          return c.length > 1 && c[0] ? (c[c.length - 1] - c[0]) / c[0] : null; };
        const evoRes = evo(moy);

        // Suivre le réseau, c'est épouser sa TRAJECTOIRE, pas retomber au même
        // endroit. Comparer seulement le premier et le dernier point déclarait
        // « en phase » une boutique qui bondit de +18 puis chute à -6 : le
        // chiffre contredisait le dessin. On mesure donc l'écart moyen à la
        // courbe réseau, période par période, en points d'indice — exactement
        // la distance que l'œil voit entre la courbe et la pointillée.
        const idxRes = indexer(moy);
        const suivi = k => {
          const a = indexer(brut[k]);
          const d = a.map((v, i) => (v == null || idxRes[i] == null) ? null : v - idxRes[i]);
          const co = d.filter(v => v != null);
          if (co.length < 2) { return null; }
          // Le MAXIMUM commande, la moyenne accompagne. Moyenner diluait un
          // décrochage d'un mois sur cinq périodes : Halle tombait à 4,74 et
          // Corbais à 5,06 pour un accident de mai rigoureusement comparable —
          // deux verdicts opposés de part et d'autre du seuil. Et un écart
          // ponctuel est justement ce sur quoi on peut agir, à condition de
          // savoir QUAND il s'est produit.
          let pire = 0, iP = -1;
          d.forEach((v, i) => { if (v != null && Math.abs(v) > Math.abs(pire)) { pire = v; iP = i; } });
          return { moy: co.reduce((x, y) => x + Math.abs(y), 0) / co.length, max: pire,
            quand: iP >= 0 ? ptsC[iP].libelle : null };
        };

        const series = mags.map((m, k) => {
          const pt = [];
          aff[k].forEach((v, i) => { if (v != null) pt.push({ i, v, x: xi(i), y: yy(v) }); });
          const der = pt.length ? pt[pt.length - 1] : null;
          const e = evo(brut[k]);
          const ph = suivi(k);
          return { id: m.id, nom: m.nom,
            court: (m.nom.split(/\s+[-–]\s+/).pop() || m.nom).trim(),
            col: PAL[k % PAL.length],
            d: pt.map((q, j) => (j ? 'L' : 'M') + q.x + ' ' + q.y).join(' '),
            pts: pt.map(q => ({ x: q.x, y: q.y, t: m.nom + ' · ' + ptsC[q.i].libelle + ' : '
              + fmtM(brut[k][q.i]) + (base100 ? ' — base ' + Math.round(q.v) : '') })),
            evo: e == null ? '—' : (e >= 0 ? '+' : '') + this.fP(e, 1),
            phase: ph,
            // Nommer la période du plus grand écart : « s'écarte » invite à
            // chercher, « s'écarte en Mai » dit où regarder.
            // Pas de « ± » : la police de l'application le rend par un « 3 ».
            // Un signe illisible dans un verdict vaut mieux supprimé que subi.
            phaseTxt: ph == null ? '' : 'max ' + (ph.max >= 0 ? '+' : '') + ph.max.toFixed(0) + ' pts'
              + (ph.quand ? ' en ' + ph.quand : '') + ' · moyen ' + ph.moy.toFixed(1).replace('.', ','),
            // Trois paliers calés sur le PIRE écart, celui sur lequel on agit.
            verdict: ph == null ? 'indéterminé' : Math.abs(ph.max) <= 8 ? 'suit le réseau'
              : (Math.abs(ph.max) <= 20 ? 'écart ponctuel' : 'trajectoire propre'),
            vCol: ph == null ? 'var(--color-text-muted)' : Math.abs(ph.max) <= 8 ? '#2d7a3e'
              : (Math.abs(ph.max) <= 20 ? '#B87512' : 'var(--color-primary)'),
            fin: der ? { y: der.y, xd: der.x } : null,
            cells: ptsC.map((p, i) => ({ v: brut[k][i] == null ? '—' : fmtM(brut[k][i]) })) };
        }).filter(s => s.pts.length);

        const mp = [];
        affMoy.forEach((v, i) => { if (v != null) mp.push({ i, v, x: xi(i), y: yy(v) }); });
        const reseau = mp.length ? {
          d: mp.map((q, j) => (j ? 'L' : 'M') + q.x + ' ' + q.y).join(' '),
          pts: mp.map(q => ({ x: q.x, y: q.y, t: 'Moyenne réseau · ' + ptsC[q.i].libelle + ' : '
            + fmtM(moy[q.i]) + (base100 ? ' — base ' + Math.round(q.v) : '') })),
          fin: { xd: mp[mp.length - 1].x, y: mp[mp.length - 1].y },
          evo: evoRes == null ? '—' : (evoRes >= 0 ? '+' : '') + this.fP(evoRes, 1),
          cells: moy.map(v => ({ v: v == null ? '—' : fmtM(v) }))
        } : null;

        // L'étiquette du réseau entre dans le MÊME écartement : traitée à part,
        // elle retombait sur celle d'un magasin (mesuré : 8,6 px pour 12 requis).
        const bouts = series.filter(s => s.fin).map(s => s.fin);
        if (reseau) { bouts.push(reseau.fin); }
        bouts.sort((a, b) => a.y - b.y);
        let prec = -99;
        bouts.forEach(f => { f.ly = Math.max(f.y, prec + 17); prec = f.ly; });

        common.fPct = v => this.fP(v, 1);
        common.anBase100 = base100;
        common.anLignes = { W: LW, H: LH, PD, ticks,
          labels: ptsC.map((p, i) => ({ x: xi(i).toFixed(1), y: LH - 16, t: p.libelle })),
          series, reseau, entetes: ptsC.map(p => ({ t: p.libelle })), nClos: nC,
          exclu: pts.length - nC,
          vide: !series.length ? 'aucun magasin n’a de chiffre sur cette sélection' : '' };
      }

      const barres = [], valeurs = [];
      pts.forEach((p, i) => {
        const cx = PL + i * sw;
        // N-1 en retrait derrière N : deux barres appariées sur la même
        // graduation, la plus pâle portant l'exercice précédent.
        if (p.n1 != null && hi > 0) {
          barres.push({ x: (cx + sw * 0.16).toFixed(1), y: y(p.n1).toFixed(1),
            w: (sw * 0.3).toFixed(1), h: Math.max((H - PB) - y(p.n1), 1).toFixed(1),
            fill: 'var(--color-secondary)', t: 'N-1 · ' + fmt(p.n1) });
        }
        if (p.valeur != null && hi > 0) {
          barres.push({ x: (cx + sw * 0.5).toFixed(1), y: y(p.valeur).toFixed(1),
            w: (sw * 0.3).toFixed(1), h: Math.max((H - PB) - y(p.valeur), 1).toFixed(1),
            // Un point encore en cours n'est pas comparable aux précédents :
            // il est hachuré, comme les mois partiels du graphique budget.
            fill: p.enCours ? 'url(#anhach)' : 'var(--color-primary)',
            t: p.libelle + ' · ' + fmt(p.valeur) });
          valeurs.push({ x: (cx + sw * 0.65).toFixed(1), y: (y(p.valeur) - 5).toFixed(1),
            t: euro ? this.fK(p.valeur) : Math.round(p.valeur).toLocaleString('fr-BE') });
        }
      });
      const vals = pts.map(p => p.valeur).filter(v => v != null);
      const prem = vals.length ? vals[0] : null, der = vals.length ? vals[vals.length - 1] : null;
      const dN1 = pts.some(p => p.n1 != null);
      common.anGraphe = { W, H, barres, labels, valeurs, grille, n1: dN1,
        // L'évolution ne se calcule QUE sur des points clos : comparer un mois
        // entamé au précédent annoncerait une chute qui n'existe pas.
        evolution: (pts.length > 1 && !pts[pts.length - 1].enCours && prem != null && prem !== 0 && der != null)
          ? { txt: (der >= prem ? '+' : '') + (100 * (der - prem) / prem).toFixed(1).replace('.', ',') + ' %',
              col: der >= prem ? '#2d7a3e' : 'var(--color-primary)' }
          : null,
        unite: euro ? '€' : 'unités',
        lignes: pts.map(p => ({ libelle: p.libelle,
          valeur: p.valeur == null ? '—' : fmt(p.valeur),
          n1: p.n1 == null ? '—' : fmt(p.n1),
          delta: p.delta == null ? '—' : (p.delta >= 0 ? '+' : '') + this.fP(p.delta, 1),
          deltaCol: p.delta == null ? 'var(--color-text-muted)'
            : (p.delta >= 0 ? '#2d7a3e' : 'var(--color-primary)'),
          // Un point sans valeur n'est pas un zéro : il dit pourquoi il est
          // vide, sans quoi « pas de réponse » se lit comme « pas de vente ».
          motif: p.valeur == null ? (p.motif || 'aucune donnée')
            : (p.enCours ? 'période en cours' : ''),
          enCours: p.enCours })) };
    }
    return common;
  }
  /**
   * Diagnostic : la vue d'ensemble des manques, et les appels lents.
   *
   * Les deux tiennent ensemble : un écran incomplet et un écran lent se
   * corrigent au même endroit — l'API amont. Réunir les deux listes donne, en
   * une page, ce qu'il faut demander et ce qu'il faut faire accélérer.
   */
  fraicheur(){
    if (this.D.fraicheur || this._frEnCours) { return; }
    this._frEnCours = true;
    readOne('/audit/fraicheur').then(f => { this._frEnCours = false;
      this.D.fraicheur = f || {}; this.setState({}); });
  }
  valsDiag(common){
    this.lacunes(); this.fraicheur();
    const F = this.D.fraicheur || null;
    common.frResume = F && F.resume ? F.resume : '';
    common.frRetard = F ? (F.retardCaisse == null ? null : F.retardCaisse) : null;
    common.frAuj = F && F.aujourdhui ? F.aujourdhui : '';
    common.frSources = (F && F.sources || []).map(x => ({ table: x.table, quoi: x.quoi,
      derniere: x.derniere || (x.erreur || '—'),
      retard: x.retard == null ? '' : x.retard + ' j',
      // Deux jours de décalage sur des avis se comprend ; trente-quatre sur la
      // caisse est un écran qui ment sans le savoir.
      col: x.retard == null ? 'var(--color-text-muted)'
        : (x.retard > 7 ? 'var(--color-primary)' : (x.retard > 2 ? '#B87512' : '#2d7a3e')) }));
    common.frApi = (F && F.api || []).map(a => ({ route: a.route,
      detail: (a.magasins != null ? a.magasins + ' magasin(s)' : '') + (a.ca != null ? ' · ' + this.fMt(a.ca) : ''),
      verdict: a.verdict, ok: /jour même/.test(a.verdict || '') }));
    common.frEcrans = (F && F.ecrans || []).map(e => ({ ecran: e.ecran, route: e.route,
      lit: e.lit, consequence: e.consequence, remplacer: e.remplacer }));
    const L = this.D.lacunes || {};
    const noms = { magasins: 'Tableau des magasins', marge: 'Marge & coûts',
      exploitation: 'P&L magasins', parametres: 'Paramètres', catalogue: 'Catalogue produit',
      produits: 'Scoring des références', analyse: 'Analyse dans le temps',
      controle: 'Contrôle des tâches', centrale: 'Centrale d’achat',
      assortiment: 'Assortiment obligatoire' };
    common.diagGroupes = Object.keys(L).map(k => ({
      ecran: noms[k] || k,
      lignes: (L[k] || []).map(o => ({ champ: o.champ, quoi: o.quoi, source: o.source,
        etiquette: o.type === 'saisie' ? 'à renseigner' : 'manque API', api: o.type !== 'saisie' }))
    })).filter(g => g.lignes.length);
    common.diagNbApi = common.diagGroupes.reduce((a, g) => a + g.lignes.filter(l => l.api).length, 0);
    common.diagNbSaisie = common.diagGroupes.reduce((a, g) => a + g.lignes.filter(l => !l.api).length, 0);
    common.diagChargement = !this.D.lacunes;

    // --- appels lents, mesurés sur la session en cours
    const t = apiTraces();
    common.diagSeuil = (t.seuil / 1000).toFixed(0);
    common.diagTotal = t.total;
    // Regroupées par chemin : c'est la ROUTE qu'on fait améliorer, pas l'appel.
    const par = {};
    t.lentes.forEach(x => { const k = x.path;
      par[k] = par[k] || { path: k, n: 0, max: 0, som: 0, ko: 0 };
      par[k].n++; par[k].som += x.ms; par[k].max = Math.max(par[k].max, x.ms);
      if (!x.ok) { par[k].ko++; } });
    common.diagLentes = Object.values(par).sort((a, b) => b.max - a.max).map(x => ({
      path: x.path, n: x.n, moy: Math.round(x.som / x.n).toLocaleString('fr-BE'),
      max: x.max.toLocaleString('fr-BE'), ko: x.ko,
      col: x.max >= 10000 ? 'var(--color-primary)' : (x.max >= 5000 ? '#B87512' : 'var(--color-text)') }));
    common.diagRaz = () => { apiTracesRaz(); this.setState({}); };
    return common;
  }
  /**
   * Lacunes de l'écran courant : ce qu'il ne peut PAS afficher, et pourquoi.
   *
   * Chargées une fois, détectées côté serveur sur l'état réel des données. Une
   * colonne vide sans explication se lit comme un zéro ou comme une panne ;
   * nommer la source attendue transforme le trou en tâche.
   */
  lacunes(){
    if (this.D.lacunes || this._lacEnCours) { return; }
    this._lacEnCours = true;
    readOne('/lacunes').then(l => { this._lacEnCours = false;
      this.D.lacunes = l || {}; this.setState({}); });
  }
  valsLacunes(common){
    this.lacunes();
    const ecr = { magasins: 'magasins', marge: 'marge', exploitation: 'exploitation',
      parametres: 'parametres', assortiment: 'assortiment', catalogue: 'catalogue',
      produits: 'produits', analyse: 'analyse', controle: 'controle' }[this.state.screen];
    const l = ((this.D.lacunes || {})[ecr] || []);
    common.lacunes = l.map(o => ({ champ: o.champ, quoi: o.quoi, source: o.source,
      // « manque API » quand il faut réclamer, « à renseigner » quand il faut
      // remplir. Confondre les deux envoie chercher une API qui existe déjà.
      etiquette: o.type === 'saisie' ? 'à renseigner' : 'manque API',
      api: o.type !== 'saisie' }));
    return common;
  }
  /**
   * Centrale d'achat — dix écrans, un seul gabarit.
   *
   * Chaque écran charge sa route et rend le MÊME objet : des colonnes, des
   * lignes, un état. Quand la source manque, l'état vaut « attente » et les
   * colonnes portent, à la place des données, le champ attendu et son API.
   * Le tableau devient alors la spécification du branchement — on voit d'un
   * coup d'œil ce qui reste à obtenir, sans rouvrir le document de cadrage.
   */
  caRoute(){
    return { caCampagnes: '/centrale/campagnes', caDemande: '/centrale/demandes',
      caAchats: '/centrale/achats', caCommandes: '/centrale/commandes',
      caStock: '/centrale/stock', caFacturation: '/centrale/facturation',
      caReglages: '/centrale/reglages' }[this.state.screen];
  }
  /* --- planogramme : le comptoir, ses emplacements, ses consignes ------------ */

  /** Charge l'arbre du comptoir. Un seul appel : structure ET occupation. */
  plCharge(force){
    if (this._plEnCours) { return; }
    if (this.D.plano && !force) { return; }
    this._plEnCours = true;
    readOne('/planogramme').then(d => { this._plEnCours = false;
      this.D.plano = d || { etat: 'erreur' }; this.setState({}); });
  }
  valsPlano(common){
    const S = this.state, D = this.D;
    const pl = D.plano;
    common.plChargement = !pl;
    if (!pl) { common.plZones = []; return; }
    common.plManque = (pl.manque || []).map(m => ({ champ: m.champ, quoi: m.quoi, source: m.source, type: m.type }));
    const T = pl.totaux || {};
    common.plTot = { slots: T.slots || 0, libres: T.libres || 0, places: T.places || 0 };
    common.plVide = (T.slots || 0) === 0;

    // Zone affichée : la première déclarée, sauf choix explicite.
    const zones = pl.zones || [];
    const zid = S.plZone && zones.some(z => z.id === S.plZone) ? S.plZone : (zones[0] ? zones[0].id : null);
    common.plZoneId = zid;
    common.plZonesOpts = zones.map(z => ({ id: z.id, nom: z.nom, on: z.id === zid,
      go: () => this.setState({ plZone: z.id }) }));
    const zone = zones.find(z => z.id === zid) || null;

    // Le plan : une colonne par meuble, une ligne par niveau. Les niveaux d'un
    // meuble à l'autre ne coïncident pas forcément — on prend donc le rang
    // maximal et on laisse les cases manquantes vides, plutôt que de forcer
    // une grille qui mentirait sur la forme du comptoir.
    const meubles = zone ? (zone.meubles || []) : [];
    const nMax = meubles.reduce((a, m) => Math.max(a, (m.niveaux || []).length), 0);
    const cible = S.plCible || null;
    common.plMeubles = meubles.map(m => ({ id: m.id, nom: m.nom,
      renommer: () => this.plRenommer('meuble', m.id, m.nom),
      supprimer: () => this.plSupprimer('meuble', m.id, m.nom) }));
    common.plLignes = [];
    for (let i = 0; i < nMax; i++) {
      const noms = meubles.map(m => ((m.niveaux || [])[i] || {}).nom).filter(Boolean);
      common.plLignes.push({
        nom: noms.length ? noms[0] : '—',
        cases: meubles.map(m => {
          const niv = (m.niveaux || [])[i];
          if (!niv) { return { absent: true, slots: [] }; }
          return { absent: false, niveauId: niv.id,
            ajouter: () => this.plAjouterSlots(niv.id, niv.nom),
            slots: (niv.slots || []).map(s => {
              const occ = (s.occupants || [])[0] || null;
              const vise = cible === s.id;
              return { id: s.id, position: s.position, libre: !occ, vise,
                nom: occ ? occ.nom : '', ref: occ ? occ.ref : '',
                detail: occ ? (occ.fronts + ' front' + (occ.fronts > 1 ? 's' : ''))
                  : ((s.largeurMm ? s.largeurMm + ' mm' : '') + (s.capacite ? ' · ' + s.capacite : '')),
                st: 'border-radius:7px;padding:6px 7px;min-height:50px;display:flex;flex-direction:column;'
                  + 'justify-content:space-between;gap:3px;font-size:10.5px;line-height:1.3;cursor:pointer;'
                  + (vise ? 'border:1.5px solid var(--color-primary);background:var(--color-primary);color:#fff;font-weight:600'
                    : occ ? 'border:0.5px solid var(--color-border-tertiary);background:var(--color-background-secondary);color:var(--color-text)'
                          : 'border:1.5px dashed var(--color-primary);background:rgba(141,29,44,0.04);color:var(--color-primary);font-weight:500'),
                clic: occ ? () => this.plFicheOuvrir(occ.ref) : () => this.setState({ plCible: vise ? null : s.id }) };
            }) };
        }) });
    }
    common.plCible = cible;
    const sC = (pl.slots || []).find(s => s.id === cible) || null;
    common.plCibleTxt = sC ? (sC.meuble + ' · ' + sC.niveau + ' · position ' + sC.position) : '';

    // --- la même chose en TABLEAU.
    //
    // Le plan montre la forme du comptoir, le tableau la porte en entier : à
    // douze emplacements le dessin suffit, à deux cents il ne tient plus à
    // l'écran et on ne peut ni trier, ni chercher, ni voir toutes les zones à
    // la fois. Les deux vues lisent la MÊME donnée — aucun calcul n'est refait
    // ici, sinon les deux finiraient par ne plus dire la même chose.
    const vue = S.plVue === 'tableau' ? 'tableau' : 'plan';
    common.plVue = vue;
    common.plVueBtns = [['plan', 'Plan'], ['tableau', 'Tableau']].map(([v, nom]) => ({
      nom, on: vue === v, go: () => this.setState({ plVue: v }) }));

    const libresSeules = !!S.plLibres;
    common.plLibresSeules = libresSeules;
    common.plLibresGo = () => this.setState({ plLibres: !libresSeules });
    const q = (S.plQ || '').trim().toLowerCase();
    common.plQ = S.plQ || '';
    common.plSetQ = e => this.setState({ plQ: e.target.value });

    const tri = S.plTri || 'lieu';
    common.plTri = tri;
    const cols = [['lieu', 'Emplacement'], ['ref', 'Référence'], ['etat', 'État']];
    common.plCols = cols.map(([k, nom]) => ({ nom, k, on: tri === k,
      go: () => this.setState({ plTri: k }) }));

    let rangs = (pl.slots || []).map(s => {
      const occ = (s.occupants || [])[0] || null;
      return { id: s.id, zone: s.zone, meuble: s.meuble, niveau: s.niveau, position: s.position,
        taille: [s.largeurMm ? s.largeurMm + ' mm' : '', s.capacite ? 'cap. ' + s.capacite : ''].filter(Boolean).join(' · ') || '—',
        ref: occ ? occ.ref : '', nom: occ ? occ.nom : '',
        fronts: occ ? String(occ.fronts) : '—', libre: !occ,
        etat: occ ? 'occupé' : 'libre',
        vise: cible === s.id,
        ouvrir: occ ? () => this.plFicheOuvrir(occ.ref) : () => this.setState({ plCible: cible === s.id ? null : s.id }) };
    });
    if (libresSeules) { rangs = rangs.filter(r => r.libre); }
    if (q) {
      rangs = rangs.filter(r => (r.nom + ' ' + r.ref + ' ' + r.zone + ' ' + r.meuble + ' ' + r.niveau)
        .toLowerCase().indexOf(q) >= 0);
    }
    const cmp = { lieu: (a, b) => (a.zone + a.meuble + a.niveau).localeCompare(b.zone + b.meuble + b.niveau) || a.position - b.position,
      ref: (a, b) => (a.nom || 'zzz').localeCompare(b.nom || 'zzz'),
      etat: (a, b) => (a.libre === b.libre ? 0 : (a.libre ? -1 : 1)) };
    rangs.sort(cmp[tri] || cmp.lieu);
    common.plRangs = rangs.map(r => Object.assign({}, r, {
      etatSt: 'display:inline-block;font-size:11px;font-weight:500;padding:2px 9px;border-radius:999px;'
        + (r.libre ? 'background:rgba(141,29,44,0.08);color:var(--color-primary)'
                   : 'background:var(--color-background-secondary);color:var(--color-text-muted)'),
      trSt: 'border-bottom:0.5px solid var(--color-border-tertiary);cursor:pointer;'
        + (r.vise ? 'background:rgba(141,29,44,0.05)' : '') }));
    common.plRangsN = rangs.length;

    // Déclaration de la structure : ouverte tant que rien n'existe, replaçable
    // ensuite — on ne fait pas chercher un formulaire à qui démarre de zéro.
    //
    // Tout se saisit EN LIGNE. La première version passait par window.prompt :
    // une boîte que le navigateur peut bloquer, qui n'affiche pas ce qui existe
    // déjà, et qui ne dit rien quand l'écriture a réussi. Résultat mesuré — deux
    // zones « Tartes » créées coup sur coup, l'écran continuant d'annoncer un
    // comptoir non déclaré parce qu'il ne comptait que les emplacements.
    common.plOrg = S.plOrg == null ? common.plVide : !!S.plOrg;
    common.plOrgGo = () => this.setState({ plOrg: !common.plOrg });

    const champ = (k, val) => ({ val: S[k] == null ? val : S[k],
      set: e => this.setState({ [k]: e.target.value }) });
    common.plNZone = champ('plNZone', '');
    common.plNMeuble = champ('plNMeuble', '');
    common.plNNiveau = champ('plNNiveau', '');
    common.plNSlots = champ('plNSlots', '4');

    common.plZoneAdd = () => this.plAjouter('zone', null, S.plNZone, 'plNZone');
    common.plMeubleAdd = zid ? () => this.plAjouter('meuble', zid, S.plNMeuble, 'plNMeuble') : null;

    // Liste de ce qui existe, éditable sur place : c'est aussi la seule façon de
    // voir — et de corriger — un doublon créé par mégarde.
    common.plZonesListe = zones.map(z => ({ id: z.id, nom: z.nom,
      nMeubles: (z.meubles || []).length,
      on: z.id === zid,
      choisir: () => this.setState({ plZone: z.id, plMeubleSel: null }),
      renommer: e => this.plRenommer('zone', z.id, e.target.value),
      supprimer: () => this.plSupprimer('zone', z.id, z.nom) }));

    common.plMeublesListe = meubles.map(m => ({ id: m.id, nom: m.nom,
      nNiveaux: (m.niveaux || []).length,
      nSlots: (m.niveaux || []).reduce((a, n) => a + (n.slots || []).length, 0),
      renommer: e => this.plRenommer('meuble', m.id, e.target.value),
      supprimer: () => this.plSupprimer('meuble', m.id, m.nom) }));

    const msel = S.plMeubleSel && meubles.some(m => m.id === S.plMeubleSel)
      ? S.plMeubleSel : (meubles[0] ? meubles[0].id : null);
    common.plMeubleSel = msel;
    common.plMeubleOpts = meubles.map(m => ({ id: m.id, nom: m.nom, on: m.id === msel }));
    common.plMeubleSetSel = e => this.setState({ plMeubleSel: +e.target.value });
    common.plNiveauAdd = msel ? () => this.plAjouterNiveau(msel) : null;

    const mSel = meubles.find(m => m.id === msel) || null;
    common.plNiveauxListe = mSel ? (mSel.niveaux || []).map(n => ({ id: n.id, nom: n.nom,
      nSlots: (n.slots || []).length,
      renommer: e => this.plRenommer('niveau', n.id, e.target.value),
      ajouter: () => this.plAjouterSlots(n.id),
      supprimer: () => this.plSupprimer('niveau', n.id, n.nom) })) : [];

    // Où en est la déclaration : dire l'étape suivante plutôt qu'un « pas encore
    // déclaré » qui ne bouge pas quand on avance.
    common.plEtape = !zones.length ? 'zone'
      : (!meubles.length ? 'meuble'
        : (!(mSel && (mSel.niveaux || []).length) ? 'niveau'
          : (common.plTot.slots === 0 ? 'slots' : 'fait')));
    common.plEtapeTxt = {
      zone: 'Commencez par une zone — « vitrine réfrigérée », « comptoir sec », « îlot boissons ».',
      meuble: 'Zone créée. Ajoutez maintenant un meuble : la vitrine, la gondole, le présentoir.',
      niveau: 'Meuble créé. Ajoutez un niveau — haut, médian, bas — avec son nombre d’emplacements.',
      slots: 'Niveau créé, mais sans emplacement. Ajoutez-en pour pouvoir y placer des références.',
      fait: '',
    }[common.plEtape];

    // Références placées / à placer, dans la zone regardée.
    const parRef = {};
    (pl.placements || []).forEach(p => { parRef[p.ref] = p; });
    common.plParRef = parRef;
    common.plFiche = S.plFiche ? this.valsPlFiche(S.plFiche, pl) : false;
  }
  /** La fiche de présentation et de vente d'une référence. */
  valsPlFiche(f, pl){
    const d = f.d || {};
    const cat = d.catalogue || {};
    const n = f.note || {};
    const court = nom => String(nom || '').replace(/^Non conforme\s*[—-]\s*/i, '');
    const slots = (pl.slots || []);
    const place = (pl.placements || []).find(p => p.ref === f.ref) || null;
    const cible = f.cible != null ? f.cible : (place ? place.slotId : null);
    const sC = slots.find(s => s.id === cible) || null;
    // Zone du mini-plan : celle de l'emplacement visé, sinon la première.
    const zones = pl.zones || [];
    const zid = f.zone || (sC ? sC.zoneId : (zones[0] ? zones[0].id : null));
    const zone = zones.find(z => z.id === zid) || null;
    const meubles = zone ? (zone.meubles || []) : [];
    const nMax = meubles.reduce((a, m) => Math.max(a, (m.niveaux || []).length), 0);
    const lignes = [];
    for (let i = 0; i < nMax; i++) {
      lignes.push({ nom: (meubles.map(m => ((m.niveaux || [])[i] || {}).nom).filter(Boolean)[0]) || '—',
        cases: meubles.map(m => { const niv = (m.niveaux || [])[i];
          if (!niv) { return { absent: true, slots: [] }; }
          return { absent: false, slots: (niv.slots || []).map(s => {
            const occ = (s.occupants || []).filter(o => o.ref !== f.ref)[0] || null;
            const vise = cible === s.id;
            return { id: s.id, position: s.position, libre: !occ, vise,
              nom: vise ? 'ici' : (occ ? occ.nom : 'libre'),
              st: 'border-radius:6px;padding:5px 6px;min-height:44px;display:flex;flex-direction:column;'
                + 'justify-content:space-between;font-size:10px;line-height:1.25;cursor:pointer;'
                + (vise ? 'border:1.5px solid var(--color-primary);background:var(--color-primary);color:#fff;font-weight:600'
                  : occ ? 'border:0.5px solid var(--color-border-tertiary);background:var(--color-background-secondary);color:var(--color-text-muted)'
                        : 'border:1.5px dashed var(--color-primary);background:rgba(141,29,44,0.04);color:var(--color-primary);font-weight:500'),
              clic: () => this.setState(s2 => ({ plFiche: Object.assign({}, s2.plFiche, { cible: s.id }) })) };
          }) }; }) });
    }
    return {
      ref: f.ref, nom: cat.nom || f.ref, chargement: !!f.chargement, busy: !!f.busy,
      err: f.err || '', ok: f.ok || '',
      sous: [cat.categorie, cat.groupe, (cat.periods || [])[0]].filter(Boolean).join(' · '),
      placeTxt: place && place.slotId
        ? (place.zone + ' · ' + place.meuble + ' · ' + place.niveau + ' · position ' + place.position)
        : '',
      zonesOpts: zones.map(z => ({ id: z.id, nom: z.nom, on: z.id === zid })),
      zoneSet: e => { const v = +e.target.value;
        this.setState(s2 => ({ plFiche: Object.assign({}, s2.plFiche, { zone: v }) })); },
      meubles: meubles.map(m => m.nom), lignes,
      cibleTxt: sC ? (sC.meuble + ' · ' + sC.niveau + ' · ' + sC.position) : '',
      fronts: f.fronts != null ? f.fronts : (place ? place.fronts : 1),
      ordre: f.ordre != null ? f.ordre : (place ? place.ordre : 1),
      qmin: f.qmin != null ? f.qmin : (cat.qmin || 0),
      set: k => e => { const v = e.target.value;
        this.setState(s2 => ({ plFiche: Object.assign({}, s2.plFiche, { [k]: v }) })); },
      // Vente : les chiffres du référentiel, sans recalcul local.
      vente: [
        { k: 'Prix de vente', v: this.fEd(cat.prix) },
        { k: 'Coût matière', v: this.fEd(cat.mat) },
        { k: 'Marge brute', v: cat.margePct == null ? '—' : this.fP(cat.margePct, 0) },
        { k: 'Marge nette', v: cat.margeNettePct == null ? '—' : this.fP(cat.margeNettePct, 0), aide: 'commission déduite' },
        { k: 'Poids', v: cat.poids ? cat.poids.toLocaleString('fr-BE') + ' g' : '—' },
        { k: 'DLV', v: cat.dlv ? cat.dlv + ' h' : '—' },
      ],
      technique: (d.technique || []).map(t => ({ k: t.champ, v: t.valeur })),
      techniqueVide: !(d.technique || []).length,
      manque: (d.manque || []).map(m => ({ champ: m.champ, quoi: m.quoi, source: m.source })),
      // Consigne de présentation.
      noteTxt: n.texte != null ? n.texte : ((d.note || {}).texte || ''),
      notePin: n.epinglee != null ? !!n.epinglee : !!(d.note || {}).epinglee,
      noteGrav: n.gravite != null ? n.gravite : ((d.note || {}).gravite || 3),
      noteDu: n.du != null ? n.du : ((d.note || {}).du || ''),
      noteAu: n.au != null ? n.au : ((d.note || {}).au || ''),
      noteMaj: (d.note || {}).majLe ? ('modifiée par ' + ((d.note || {}).auteur || '—') + ', ' + (d.note || {}).majLe) : '',
      noteSet: k => e => { const v = e.target.type === 'checkbox' ? e.target.checked : e.target.value;
        this.setState(s2 => ({ plFiche: Object.assign({}, s2.plFiche,
          { note: Object.assign({}, s2.plFiche.note, { [k]: v }) }) })); },
      noteNiveaux: this.zNiveaux().map(lv => ({ n: lv.n, nom: court(lv.nom), couleur: lv.couleur,
        on: lv.n === (n.gravite != null ? n.gravite : ((d.note || {}).gravite || 3)),
        pick: () => this.setState(s2 => ({ plFiche: Object.assign({}, s2.plFiche,
          { note: Object.assign({}, s2.plFiche.note, { gravite: lv.n }) }) })) })),
      placer: cible ? () => this.plPlacer() : null,
      retirer: place && place.slotId ? () => this.plRetirer() : null,
      enregistrerNote: () => this.plNote(),
      close: () => this.setState({ plFiche: null }),
    };
  }
  plFicheOuvrir(ref){
    this.setState({ plFiche: { ref, chargement: true, d: null, note: {} } });
    readOne('/production/produit/fiche?ref=' + encodeURIComponent(ref))
      .then(d => this.setState(s => (s.plFiche && s.plFiche.ref === ref)
        ? { plFiche: Object.assign({}, s.plFiche, { chargement: false, d: d || null }) } : {}));
  }
  plFPatch(patch){
    this.setState(s => ({ plFiche: Object.assign({}, s.plFiche, patch) }));
  }
  /** Place la référence sur l'emplacement visé. Un refus est RENDU tel quel. */
  plPlacer(){
    const f = this.state.plFiche;
    if (!f || f.busy) { return; }
    const pl = this.D.plano || {};
    const place = (pl.placements || []).find(p => p.ref === f.ref) || null;
    const cible = f.cible != null ? f.cible : (place ? place.slotId : null);
    if (!cible) { return; }
    const cat = (f.d || {}).catalogue || {};
    this.plFPatch({ busy: true, err: '', ok: '' });
    write(this.source, 'PUT', '/planogramme/placement/' + encodeURIComponent(f.ref), {
      slotId: cible, nom: cat.nom || f.ref,
      fronts: Math.max(1, Math.round(+f.fronts || (place ? place.fronts : 1))),
      ordre: Math.max(1, Math.round(+f.ordre || (place ? place.ordre : 1))),
      qmin: Math.max(0, Math.round(+(f.qmin != null ? f.qmin : (cat.qmin || 0)) || 0)),
    }).then(r => {
      if (!r || r.ok === false) {
        this.plFPatch({ busy: false, err: (r && r.error) || 'placement refusé' });
        return;
      }
      this.plFPatch({ busy: false, ok: 'Placée au comptoir.' });
      this.plCharge(true);
      this.D.prodCatalogue = null; this.plRechargeCatalogue();
    });
  }
  plRetirer(){
    const f = this.state.plFiche;
    if (!f || f.busy) { return; }
    this.plFPatch({ busy: true, err: '', ok: '' });
    write(this.source, 'PUT', '/planogramme/placement/' + encodeURIComponent(f.ref), { slotId: null })
      .then(r => {
        if (!r || r.ok === false) { this.plFPatch({ busy: false, err: (r && r.error) || 'échec' }); return; }
        this.plFPatch({ busy: false, ok: 'Retirée du comptoir.', cible: null });
        this.plCharge(true); this.D.prodCatalogue = null; this.plRechargeCatalogue();
      });
  }
  /** Le référentiel lit le placement : il doit être relu après une écriture. */
  plRechargeCatalogue(){
    readOne('/production/catalogue').then(c => { if (c) { this.D.prodCatalogue = c; } this.setState({}); });
  }
  plNote(){
    const f = this.state.plFiche;
    if (!f || f.busy) { return; }
    const v = this.valsPlFiche(f, this.D.plano || {});
    this.plFPatch({ busy: true, err: '', ok: '' });
    write(this.source, 'PUT', '/planogramme/note', { cible: 'ref', cibleId: f.ref,
      texte: v.noteTxt, epinglee: v.notePin ? 1 : 0, gravite: v.noteGrav,
      du: v.noteDu || '', au: v.noteAu || '' })
      .then(r => {
        if (!r || r.ok === false) { this.plFPatch({ busy: false, err: (r && r.error) || 'échec' }); return; }
        this.plFPatch({ busy: false, ok: v.noteTxt.trim() ? 'Consigne enregistrée.' : 'Consigne effacée.' });
        this.plCharge(true);
      });
  }
  /* --- déclaration de la structure ------------------------------------------- */

  /**
   * Ajoute un élément de structure, depuis le champ en ligne.
   *
   * Le champ est vidé au succès et l'écran RECHARGÉ : sans confirmation
   * visible, on reclique — deux zones identiques ont été créées ainsi avant que
   * cet écran ne dise ce qu'il avait enregistré.
   */
  plAjouter(type, parentId, nom, champ){
    const v = String(nom || '').trim();
    if (!v) { this.notify('Donnez un nom avant d’ajouter.'); return; }
    write(this.source, 'POST', '/planogramme/' + type, { nom: v, parentId })
      .then(r => {
        if (!r || r.ok === false) { this.notify('Non créé : ' + ((r && r.error) || 'échec')); return; }
        // Une zone créée devient la zone REGARDÉE. Sans cela, le meuble
        // suivant s'ajoutait à la zone restée affichée — mesuré : deux
        // « Gondole A » atterries dans « Tartes » au lieu du comptoir voulu.
        const suite = { [champ]: '' };
        if (type === 'zone' && r.id) { suite.plZone = r.id; suite.plMeubleSel = null; }
        this.setState(suite);
        this.plCharge(true);
        this.notify(type === 'zone' ? 'Zone « ' + v + ' » créée' : 'Meuble « ' + v + ' » créé');
      });
  }
  /**
   * Un niveau naît avec ses emplacements : les créer un par un ferait douze
   * saisies pour une étagère.
   */
  plAjouterNiveau(meubleId){
    const S = this.state;
    const nom = String(S.plNNiveau || '').trim();
    if (!nom) { this.notify('Donnez un nom de niveau (haut, médian, bas…).'); return; }
    const slots = Math.max(0, Math.min(40, parseInt(S.plNSlots == null ? '4' : S.plNSlots, 10) || 0));
    write(this.source, 'POST', '/planogramme/niveau', { nom, parentId: meubleId, slots })
      .then(r => {
        if (!r || r.ok === false) { this.notify('Non créé : ' + ((r && r.error) || 'échec')); return; }
        this.setState({ plNNiveau: '' });
        this.plCharge(true);
        this.notify('Niveau « ' + nom + ' » créé' + (slots ? ' avec ' + slots + ' emplacement(s)' : ''));
      });
  }
  plAjouterSlots(niveauId){
    write(this.source, 'POST', '/planogramme/emplacement', { niveauId, nombre: 1 })
      .then(r => { if (!r || r.ok === false) { this.notify('Non ajouté : ' + ((r && r.error) || 'échec')); return; }
        this.plCharge(true); });
  }
  /** Renomme depuis le champ en ligne — au changement, pas à chaque frappe. */
  plRenommer(type, id, nom){
    const v = String(nom || '').trim();
    if (!v) { this.plCharge(true); return; }   // champ vidé : on remet l'ancien nom
    write(this.source, 'PATCH', '/planogramme/' + type + '/' + id, { nom: v })
      .then(r => { if (!r || r.ok === false) { this.notify('Non renommé : ' + ((r && r.error) || 'échec')); }
        this.plCharge(true); });
  }
  /**
   * Supprimer un élément de structure. Le serveur REFUSE d'abord s'il porte des
   * références ; on demande alors confirmation en disant combien seraient
   * retirées du comptoir, plutôt que de forcer d'emblée.
   */
  plSupprimer(type, id, nom){
    write(this.source, 'DELETE', '/planogramme/' + type + '/' + id, {})
      .then(r => {
        if (r && r.ok !== false) { this.setState({ plCible: null }); this.plCharge(true); return; }
        const msg = (r && r.error) || 'échec';
        if (r && r.status === 409) {
          if (!window.confirm('« ' + nom + ' » : ' + msg + '.\n\nSupprimer quand même ?')) { return; }
          write(this.source, 'DELETE', '/planogramme/' + type + '/' + id, { force: 1 })
            .then(r2 => { if (!r2 || r2.ok === false) { this.notify('Non supprimé : ' + ((r2 && r2.error) || 'échec')); return; }
              this.setState({ plCible: null }); this.plCharge(true); });
          return;
        }
        this.notify('Non supprimé : ' + msg);
      });
  }

  caCharge(){
    const S = this.state, ecr = S.screen, r = this.caRoute();
    if (!r) { return; }
    const per = S.caPeriode || '30j';
    const cle = ecr + '|' + per;
    this._caEnCours = this._caEnCours || {};
    if ((S.caData || {})[cle] || this._caEnCours[cle]) { return; }
    this._caEnCours[cle] = true;
    const q = '';
    readOne(r + q).then(d => { this._caEnCours[cle] = false;
      this.setState(s2 => ({ caData: Object.assign({}, s2.caData, { [cle]: d || { etat: 'erreur', motif: 'API injoignable' } }) })); });
  }
  valsCentrale(common){
    const S = this.state, ecr = S.screen;
    const per = S.caPeriode || '30j';
    this.caCharge();
    const d = (S.caData || {})[ecr + '|' + per];
    common.caEcran = ecr;
    common.caChargement = !d;
    common.caEtat = d ? (d.etat || 'ok') : '';
    common.caMotif = d && d.motif ? d.motif : '';
    common.caSource = d && d.source ? d.source : '';
    common.caTitreSrc = d && d.titre ? d.titre : '';
    common.caManquants = (d && d.manquants) || [];
    common.caPeriode = per;
    const onglet = on => 'border:none;cursor:pointer;font-family:var(--font-ui);font-size:12.5px;'
      + 'padding:6px 14px;border-radius:8px;'
      + (on ? 'background:var(--color-primary);color:#fff;font-weight:500'
            : 'background:transparent;color:var(--color-text-muted)');
    common.caPerBtns = null;

    // --- écrans sans source : la table est rendue, colonne par colonne
    common.caAttendu = (d && d.etat === 'attente' && d.colonnes) ? d.colonnes.map(c => ({
      col: c.col, champ: c.champ, src: c.src, note: c.note || '',
      // Une colonne déjà servie ailleurs n'est pas un manque : la distinguer
      // évite de redemander ce que le cockpit possède déjà.
      dispo: /déjà disponible|déjà connus|déjà|calcul local/i.test(c.src || '') })) : null;

    common.caKpis = null; common.caCols = null; common.caRows = null; common.caParams = null;
    if (!d || d.etat === 'attente') { return common; }

    // Les branches Cockpit, Catalogue et Analyse des ventes ont été retirées
    // avec leurs entrées de rail : elles doublaient P&L magasins, le Référentiel
    // produit et le Tableau des magasins, en rendant moins que ces derniers.
    if (ecr === 'caReglages') {
      common.caParams = d.params || null;
      // Un référentiel vide n'est pas une API manquante : il est vide, et
      // confondre les deux enverrait chercher une source qui existe déjà.
      common.caRien = 'Aucun fournisseur au référentiel du cockpit — la table existe, elle n’est pas remplie.';
      common.caCols = ['Fournisseur', 'Périmètre', 'Courriel', 'RFA %', 'Redevance centrale %'];
      common.caRows = (d.fournisseurs || []).map(f => ({ cells: [
        { t: f.nom }, { t: f.perimetre || '—', mut: true }, { t: f.email || '—', mut: true },
        { t: 'manque API', vide: true }, { t: 'manque API', vide: true } ] }));
    } else if (ecr === 'caDemande') {
      common.caCols = ['Demande', 'Fournisseur', 'Base', 'Période', 'Quantité', 'Prix cible', 'Statut'];
      common.caRows = (d.lignes || []).map(x => ({ cells: [
        { t: '#' + x.id }, { t: x.fournisseur || '—' }, { t: x.base, mut: true },
        { t: (x.du || '—') + ' → ' + (x.au || '—'), mut: true },
        { t: Math.round(x.qte).toLocaleString('fr-BE'), num: true },
        { t: this.fU(x.cible), num: true }, { t: x.statut } ] }));
      common.caRien = 'Aucune demande enregistrée. Le parcours de création en quatre étapes exige les ventes par référence ET par magasin : le volume vendu rendu par l’API est réseau, identique d’un magasin à l’autre — mesuré, 5165 unités dans les quatre boutiques.';
    }
    return common;
  }
  /**
   * Vocabulaire analysable, chargé à l'ouverture de l'écran et une seule fois.
   * Il coûte deux appels amont : hors du chargement général, qui en fait déjà
   * une vingtaine et que personne n'attend pour consulter le tableau de bord.
   */
  anOptions(){
    if (this.D.anOptions || this._anOptEnCours) { return; }
    this._anOptEnCours = true;
    readOne('/produits/analyse/options').then(o => {
      this._anOptEnCours = false;
      this.D.anOptions = o || { categories: [], produits: [], erreur: 'API injoignable' };
      this.setState({});
    });
  }
  /** Charge une série. Un appel par point : jamais automatique. */
  anCharge(cle, type, gran){
    this.setState({ anBusy: true });
    readOne('/produits/analyse?type=' + type + '&cle=' + encodeURIComponent(cle) + '&granularite=' + gran)
      .then(d => this.setState({ anBusy: false, anData: d || { motif: 'API injoignable' } }));
  }
  valsExploitation(common){
    const D = this.D, E = D.exploitation || {};
    const MOIS1 = ['J', 'F', 'M', 'A', 'M', 'J', 'J', 'A', 'S', 'O', 'N', 'D'];
    common.exJour = E.jour || '—';
    common.exAvertissement = E.avertissement || '';
    common.exBase = E.objectifBase || '';
    common.exVide = !(E.magasins || []).length;
    const cumule = this.state.exCumul === true;
    common.exCumul = cumule;
    common.exVueMois = () => this.setState({ exCumul: false });
    common.exVueCumul = () => this.setState({ exCumul: true });
    const ongl = on => 'border:none;cursor:pointer;font-family:var(--font-ui);font-size:12px;'
      + 'padding:5px 12px;border-radius:8px;'
      + (on ? 'background:var(--color-primary);color:#fff;font-weight:500'
            : 'background:transparent;color:var(--color-text-muted)');
    common.exStMois = ongl(!cumule);
    common.exStCumul = ongl(cumule);
    common.exLegendeReel = cumule ? 'réel cumulé' : 'réel mensuel';
    common.exLegendeCible = cumule ? 'cible cumulée' : 'budget encodé';
    // --- N vs N-1, toutes boutiques
    const rp = (this.state.exNvPer || 'mois');
    const rz = this.state.exReseau;
    if (!rz || rz.per !== rp) { setTimeout(() => this.exChargeReseau(rp), 0); }
    const ongN = on => 'border:none;cursor:pointer;font-family:var(--font-ui);font-size:11.5px;'
      + 'padding:4px 11px;border-radius:7px;'
      + (on ? 'background:var(--color-primary);color:#fff;font-weight:500'
            : 'background:transparent;color:var(--color-text-muted)');
    common.exNvBtns = [['jour', 'Jour'], ['semaine', 'Semaine'], ['mois', 'Mois'], ['annee', 'Année']]
      .map(b => ({ label: b[1], st: ongN(rp === b[0]),
        go: () => { this.setState({ exNvPer: b[0] }); this.exChargeReseau(b[0]); } }));
    common.exNv = {
      chargement: !rz || rz.chargement,
      motif: rz && rz.d ? (rz.d.motif || '') : '',
      periode: rz && rz.d ? (rz.d.du || '') + ' → ' + (rz.d.au || '') : '',
      source: rz && rz.d ? (rz.d.source || '') : '',
      lignes: (rz && rz.d && rz.d.magasins ? rz.d.magasins : []).map(m => ({
        magasin: m.magasin,
        n: m.n == null ? '—' : (Math.abs(m.n) >= 100000 ? this.fK(m.n) : this.fE(m.n)),
        n1: m.n1 == null ? '—' : (Math.abs(m.n1) >= 100000 ? this.fK(m.n1) : this.fE(m.n1)),
        // Pas d'écart sans N-1 : une boutique ouverte cette année n'a pas
        // « 0 % de croissance », elle n'a pas d'an dernier.
        ecart: m.ecart == null ? '—' : (m.ecart > 0 ? '+' : '') + m.ecart.toFixed(1).replace('.', ',') + ' %',
        col: m.ecart == null ? 'var(--color-text-muted)' : (m.ecart >= 0 ? '#2d7a3e' : 'var(--color-primary)'),
        pt: m.ecart == null ? 'transparent' : (m.ecart >= 0 ? '#2d7a3e' : 'var(--color-primary)') }))
    };
    // Détail d'un magasin. Chaque bloc porte son état : « ok » quand l'API a
    // répondu, « attente » sinon. Aucun chiffre n'est fabriqué pour combler —
    // un écran qui annonce ce qui lui manque vaut mieux qu'un écran rempli
    // d'une source qu'on n'a pas voulue.
    const S2 = this.state, det = S2.exDetail;
    common.exPer = S2.exPeriode || 'mois';
    common.exSetPer = p => () => {
      this.setState({ exPeriode: p });
      if (S2.exDetail) { this.exOpen(S2.exDetail.id, S2.exDetail.nom); }
    };
    const ongP = on => 'border:none;cursor:pointer;font-family:var(--font-ui);font-size:11.5px;'
      + 'padding:4px 11px;border-radius:7px;'
      + (on ? 'background:var(--color-primary);color:#fff;font-weight:500'
            : 'background:transparent;color:var(--color-text-muted)');
    common.exPerBtns = [['jour', 'Aujourd\u2019hui'], ['semaine', 'Semaine'], ['mois', 'Mois']]
      .map(p => ({ cle: p[0], label: p[1], st: ongP(common.exPer === p[0]), go: common.exSetPer(p[0]) }));
    common.exDetail = det ? {
      nom: det.nom, chargement: det.chargement,
      close: () => this.setState({ exDetail: null }),
      du: det.d ? det.d.du : null, au: det.d ? det.d.au : null,
      blocs: det.d && det.d.blocs ? Object.keys(det.d.blocs).map(k => {
        const b = det.d.blocs[k], D2 = b.donnees;
        const o = { cle: k, titre: b.titre, attente: b.etat !== 'ok',
          motif: b.motif || '', source: b.source || '', avert: b.avertissement || '',
          lignes: [], tuiles: null, cascade: null, cats: null, rang: null };
        if (b.etat !== 'ok') return o;
        if (k === 'kpis'){
          o.tuiles = [
            { l: 'CA de la période', v: this.fE(D2.ca) },
            { l: 'tickets', v: D2.tickets == null ? '—' : D2.tickets.toLocaleString('fr-BE') },
            { l: 'panier moyen', v: this.fEd(D2.panier) },
            { l: 'produits / client', v: D2.produitsParClient == null ? '—' : String(D2.produitsParClient) }];
        } else if (k === 'pnl'){
          const p = x => x && x.pct != null ? x.pct.toFixed(1).replace('.', ',') + ' %' : '—';
          const v = x => x && x.valeur != null ? this.fE(x.valeur) : '—';
          o.cascade = [
            { l: 'Chiffre d\u2019affaires', v: this.fE(D2.ca), p: '', fort: true },
            { l: 'Food cost', v: v(D2.food), p: p(D2.food) },
            { l: 'Main-d\u2019œuvre', v: v(D2.labour), p: p(D2.labour) },
            { l: 'Frais généraux', v: v(D2.overhead), p: p(D2.overhead) },
            { l: 'Résultat', v: v(D2.result), p: p(D2.result), fort: true }];
          // La période RENDUE par l'API, pas celle demandée : si elle diffère,
          // l'écran doit le montrer plutôt que de laisser croire au contraire.
          o.lignes = (D2.du && D2.au) ? [{ k: 'période rendue', v: D2.du + ' → ' + D2.au }] : [];
        } else if (k === 'categories'){
          const mx = Math.max.apply(null, D2.map(c => c.ca || 0).concat([1]));
          // Couleur = niveau de MARGE, largeur = poids dans le CA : deux
          // informations, deux canaux. Sans marge connue, la barre reste
          // neutre — une catégorie grise dit « on ne sait pas », une catégorie
          // verte par défaut dirait « tout va bien ».
          const tm = m => this.echelleMarge(m);
          o.cats = D2.slice(0, 12).map(c => ({ nom: c.categorie, ca: this.fE(c.ca),
            part: c.partCa == null ? '—' : this.fP(c.partCa, 0),
            w: Math.max(2, 100 * (c.ca || 0) / mx).toFixed(0) + '%',
            col: tm(c.margePct),
            marge: c.margePct == null ? '' : 'marge ' + c.margePct.toFixed(0) + ' %',
            delta: c.delta == null ? '' : (c.delta > 0 ? '+' : '') + c.delta.toFixed(1).replace('.', ',') + ' %',
            deltaC: c.delta == null ? 'var(--color-text-muted)' : (c.delta >= 0 ? '#2d7a3e' : 'var(--color-primary)') }));
          o.sansMarge = D2.every(c => c.margePct == null);
          o.echelle = this.paliersMarge();
        } else if (k === 'reseau'){
          const av = D2.filter(r => r.panier != null).map(r => r.panier);
          const moy = av.length ? av.reduce((a, b2) => a + b2, 0) / av.length : null;
          o.rang = { moyenne: this.fEd(moy),
            lignes: D2.slice().sort((a, b2) => (b2.panier || 0) - (a.panier || 0)).map(r => ({
              magasin: r.magasin, moi: r.moi, panier: this.fEd(r.panier),
              ppc: r.produitsParClient == null ? '—' : String(r.produitsParClient) })) };
        }
        return o;
      }) : []
    } : null;
    const an = E.mois ? +E.mois.slice(0, 4) : this.exo();
    const mc = E.mois ? +E.mois.slice(5, 7) : 12;

    // Atteinte : sans objectif on ne rend PAS une pastille rouge — on dit que
    // le budget manque. Un magasin non budgété n'est pas un magasin en échec.
    const att = a => a == null
      ? { txt: 'sans budget', st: 'background:#EDEAE5;color:var(--color-text-muted)' }
      : { txt: this.fP(a, 0),
          st: a >= 1 ? 'background:#E6F2E9;color:#2d7a3e'
             : a >= 0.9 ? 'background:#FBEFE0;color:#C17A2A'
             : 'background:#F7E4E6;color:var(--color-primary)' };
    const jauge = a => {
      if (a == null) return { w: '0%', c: 'transparent' };
      return { w: Math.min(100, 100 * a).toFixed(0) + '%',
        c: a >= 1 ? '#2d7a3e' : a >= 0.9 ? '#C17A2A' : 'var(--color-primary)' };
    };

    const r = E.reseau || {};
    const rm = r.mois || {};
    common.exRes = { ca: this.fE(rm.ca), clients: rm.tickets == null ? '—' : rm.tickets.toLocaleString('fr-BE'),
      panier: rm.panier == null ? '—' : this.fEd(rm.panier), objectif: this.fE(rm.objectif),
      att: att(rm.atteinte), jauge: jauge(rm.atteinte) };

    common.exMagasins = (E.magasins || []).map(m => {
      // Série mensuelle : le réel vient de la perf déjà chargée (une seule
      // source pour tout le cockpit), pas d'un second appel.
      const st = (D.stores || []).find(s => String(s.id) === String(m.shopId));
      const ligne = (st && st.perf && st.perf[an]) ? st.perf[an] : [];
      const reels = [], buds = [];
      for (let i = 0; i < 12; i++){
        const c = ligne[i] || {};
        reels.push(c.ca != null && c.ca > 0 ? c.ca : null);
        // Le budget mensuel est normalisé sous `caT` par joinPerf, pas sous
        // `caBudget` : lire la mauvaise clé ne lève aucune erreur, elle rend
        // simplement « budget non encodé » sur un magasin qui en a un.
        buds.push(c.caT != null && c.caT > 0 ? c.caT : null);
      }
      const nb = buds.filter(b => b != null).length;
      // Vue cumulée. Cumuler huit mois de réel face à deux mois de budget
      // comparerait deux choses différentes : le cumul du budget ne court donc
      // que sur les mois budgétés, et le réel cumulé se restreint aux MÊMES
      // mois dès qu'un budget existe. Sans budget, le cumul du réel garde tout
      // son sens et reste affiché seul.
      const cumR = [], cumB = [];
      let aR = 0, aB = 0;
      for (let i = 0; i < 12; i++){
        // Le réel cumule TOUS les mois connus : c'est la trajectoire du
        // magasin, et la restreindre aux seuls mois budgétés privait celui qui
        // a le plus de données de sa courbe — l'inverse du but recherché.
        // Ne rien reporter en revanche sur un mois sans donnée : prolonger le
        // cumul jusqu'à décembre faisait paraître l'exercice déjà bouclé.
        if (reels[i] != null){ aR += reels[i]; cumR.push(aR); } else { cumR.push(null); }
        // La cible ne court que sur les mois réellement encodés. Elle s'arrête
        // donc visiblement plus tôt que le réel quand le budget est partiel —
        // c'est ce décrochage qui dit de ne pas comparer les deux extrémités,
        // et la mention sous le graphique le nomme.
        if (buds[i] != null){ aB += buds[i]; cumB.push(aB); } else { cumB.push(null); }
      }
      const serie = (rs, bs) => {
        const vals = rs.concat(bs).filter(v => v != null);
        const hi = vals.length ? Math.max.apply(null, vals) * 1.15 : 0;
        const W = 300, H = 70, PB = 12, sw = W / 12;
        const y = v => (H - PB) * (1 - v / hi);
        const barres = [], reperes = [], labels = [];
        for (let i = 0; i < 12; i++){
          const cx = i * sw;
          // Tout mois à partir du dernier mois de caisse est incomplet — pas
          // seulement celui-ci. Une barre pleine sur un mois tronqué se lit
          // comme un effondrement, et c'est le contraire de ce qu'elle dit.
          const partiel = (i + 1) >= mc;
          if (rs[i] != null && rs[i] > 0 && hi > 0){
            barres.push({ x: (cx + sw * 0.22).toFixed(2), y: y(rs[i]).toFixed(2),
              w: (sw * 0.56).toFixed(2), h: Math.max((H - PB) - y(rs[i]), 1).toFixed(2),
              fill: partiel ? 'url(#exhach)' : 'var(--color-primary)' });
          }
          if (bs[i] != null && hi > 0){
            reperes.push({ x1: (cx + sw * 0.10).toFixed(2), x2: (cx + sw * 0.90).toFixed(2),
              y: y(bs[i]).toFixed(2) });
          }
          labels.push({ x: (cx + sw / 2).toFixed(2), y: H - 3,
            t: MOIS1[i], c: (i + 1) === mc ? 'var(--color-primary)' : 'var(--color-text-muted)' });
        }
        return { vide: !vals.length, W: W, H: H,
          grille: hi > 0 ? [0.5, 1].map(f => ({ y: y(hi * f).toFixed(2), w: W })) : [],
          barres: barres, reperes: reperes, labels: labels,
          max: hi > 0 ? this.fK(hi / 1.15) : '—' };
      };
      // Le cumulé se lit en trajectoire, pas en volumes : deux courbes, réel
      // plein et cible pointillée, comme l'écran Objectifs de CA. Empiler des
      // barres cumulées répondait « combien » quand la question est « où en
      // est-on ». Même langage visuel d'un écran à l'autre, sinon deux
      // graphiques qui disent la même chose paraissent dire autre chose.
      const courbes = (rs, bs) => {
        const vals = rs.concat(bs).filter(v => v != null);
        const hi = vals.length ? Math.max.apply(null, vals) * 1.15 : 0;
        const W = 300, H = 70, PB = 12, sw = W / 12;
        const y = v => (H - PB) * (1 - v / hi);
        const pts = arr => arr.map((v, i) => v == null ? null
          : ((i + 0.5) * sw).toFixed(2) + ',' + y(v).toFixed(2))
          .filter(Boolean).join(' ');
        const labels = [];
        for (let i = 0; i < 12; i++){
          labels.push({ x: ((i + 0.5) * sw).toFixed(2), y: H - 3,
            t: MOIS1[i], c: (i + 1) === mc ? 'var(--color-primary)' : 'var(--color-text-muted)' });
        }
        return { vide: !vals.length, W: W, H: H, courbe: true,
          grille: hi > 0 ? [0.5, 1].map(f => ({ y: y(hi * f).toFixed(2), w: W })) : [],
          reel: pts(rs), cible: pts(bs), barres: [], reperes: [], labels: labels,
          base: (H - PB).toFixed(2),
          max: hi > 0 ? this.fK(hi / 1.15) : '—' };
      };
      const g = cumule ? courbes(cumR, cumB) : serie(reels, buds);
      const vals = reels.concat(buds).filter(v => v != null);
      return { shopId: m.shopId, magasin: m.magasin,
        ouvrir: () => this.exOpen(m.shopId, m.magasin),
        objMois: this.fE(m.moisPlein),
        jourCa: this.fE(m.jour.ca), jourClients: (m.jour.tickets || 0).toLocaleString('fr-BE'),
        jourPanier: m.jour.panier == null ? '—' : this.fEd(m.jour.panier),
        semCa: this.fE(m.semaine.ca), semJauge: jauge(m.semaine.atteinte),
        moisCa: this.fE(m.mois.ca), moisJauge: jauge(m.mois.atteinte),
        att: att(m.mois.atteinte),
        gVide: g.vide, gW: g.W, gH: g.H, gCourbe: !!g.courbe,
        gReel: g.reel || '', gCible: g.cible || '', gBase: g.base || 0,
        gGrille: g.grille, gBarres: g.barres, gReperes: g.reperes, gLabels: g.labels,
        gMax: g.max,
        gNote: !nb ? 'budget non encodé'
             : cumule ? ('cible partielle : ' + nb + ' mois encodés sur 12')
             : ('budget encodé sur ' + nb + ' mois'),
        exercice: an };
    });
    return common;
  }
  /**
   * Aplatit une réponse d'API en lignes « libellé / valeur ».
   *
   * La forme exacte des réponses du panel n'est pas encore établie : plutôt
   * que de supposer des noms de clés — la manière la plus sûre d'afficher un
   * chiffre faux — on rend ce qui arrive, tel qu'il arrive. Le câblage fin
   * viendra quand la sonde aura donné les clés réelles.
   */
  exAplat(d, prefixe){
    if (d == null) return [];
    const out = [];
    const pre = prefixe ? prefixe + ' · ' : '';
    if (Array.isArray(d)){
      d.slice(0, 12).forEach((v, i) => out.push.apply(out, this.exAplat(v, pre + '#' + (i + 1))));
      return out;
    }
    if (typeof d !== 'object'){ return [{ k: prefixe || 'valeur', v: String(d) }]; }
    Object.keys(d).slice(0, 24).forEach(k => {
      const v = d[k];
      if (v == null){ out.push({ k: pre + k, v: '—' }); return; }
      if (typeof v === 'object'){ out.push.apply(out, this.exAplat(v, pre + k)); return; }
      out.push({ k: pre + k, v: typeof v === 'number' ? v.toLocaleString('fr-BE') : String(v) });
    });
    return out;
  }
  /**
   * Échelle de marge du réseau : Perte / 0–40 / 40–50 / 50–60 / 60–70 / > 70 %.
   *
   * Une seule définition pour tous les écrans. Deux barèmes différents sur la
   * même notion — l'un à trois niveaux, l'autre à six — feraient qu'une même
   * référence change de couleur selon l'écran où on la regarde.
   * Marge inconnue : gris. Le vert par défaut dirait « tout va bien » là où il
   * faut lire « on ne sait pas ».
   */
  echelleMarge(m){
    if (m == null) return 'var(--color-text-muted)';
    return m < 0 ? '#8B0000' : m < 40 ? '#dc3545' : m < 50 ? '#e67e22'
         : m < 60 ? '#8FA31E' : m < 70 ? '#27ae60' : '#C9A227';
  }
  /** Les six paliers, pour la légende. */
  paliersMarge(){
    return [['Perte', '#8B0000'], ['0–40', '#dc3545'], ['40–50', '#e67e22'],
            ['50–60', '#8FA31E'], ['60–70', '#27ae60'], ['> 70 %', '#C9A227']]
           .map(e => ({ l: e[0], c: e[1] }));
  }
  /** Montant à deux décimales — un panier moyen arrondi à l'euro ne dit rien. */
  fEd(n){ return n == null ? '—' : n.toFixed(2).replace('.', ',') + ' €'; }
  /**
   * Score des références — calcul UNIQUE, partagé par l'écran de scoring et
   * par l'extraction sous seuil. Le dupliquer aurait suffi à le faire
   * dériver : deux écrans annonçant deux scores pour la même référence
   * ne se contredisent pas franchement, ils se contredisent discrètement.
   */
  pdCalcule(){
    const D = this.D;
    const W = this.poids();
    const wtTot = (W.v + W.m + W.perte + W.comptoir) || 1;
    const pc4 = w => Math.round(100 * w / wtTot);
    const _pond = 'volume ' + pc4(W.v) + ' · marge nette ' + pc4(W.m) + ' · perte ' + pc4(W.perte) + ' · comptoir ' + pc4(W.comptoir);
    const _per = 'dernier mois de ventes encodé';
    const nbOuv = (D.stores || []).filter(s => s.status === 'Ouvert').length || 1;
    // Le coût produit n'est pas exposé par la base partagée (API panel uniquement) :
    // quand `coutUnit` est absent, marge unitaire / taux de marge / marge brute
    // restent à null et n'entrent pas dans le score (recalculé sur volume + position).
    const base = (D.products || []).map(p => {
      const _id = p.id;
      const mu = p.coutUnit == null ? null : p.prix - p.coutUnit;
      const mp = (mu == null || !p.prix) ? null : mu / p.prix;
      return { id: _id, nom: p.nom, cat: p.categorie, vol: p.volume, prix: p.prix, tend: p.tendVol, mu, mp,
        perte: p.tauxPerte != null ? p.tauxPerte : null,
        jete: p.jete != null ? p.jete : null, motifPerte: p.motifPerte || '',
        comptoir: p.presenceComptoir != null ? p.presenceComptoir : null,
        ca: p.volume * p.prix, mg: mu == null ? null : p.volume * mu, mags: p.magasins, pen: (p.magasins || 0) / nbOuv }; });
    const maxVol = Math.max.apply(null, base.map(p => p.vol)) || 1;
    const mps = base.map(p => p.mp).filter(v => v != null);
    const maxMp = mps.length ? Math.max.apply(null, mps) : null, minMp = mps.length ? Math.min.apply(null, mps) : null;
    const cats = {}; base.forEach(p => { (cats[p.cat] = cats[p.cat] || []).push(p); });
    Object.keys(cats).forEach(c2 => { const g = cats[c2].slice().sort((a, b) => b.ca - a.ca); const tot = g.reduce((a, x2) => a + x2.ca, 0) || 1;
      g.forEach((p, i) => { p.rang = i + 1; p.nbCat = g.length; p.partCat = p.ca / tot; }); });
    base.forEach(p => {
      p.sVol = 100 * Math.sqrt((p.vol || 0) / maxVol);
      // Marge NETTE sur une échelle absolue (réglage), et non plus relative
      // à la gamme. `mp` reste nul tant que le coût n'est pas disponible.
      p.sMg = this.noteMarge(p.mp == null ? null : p.mp * 100);
      // Perte et présence au comptoir : sans source de données, la note reste
      // nulle et le critère SORT du calcul — on ne remplace pas une donnée
      // manquante par un zéro, qui pénaliserait à tort chaque produit.
      p.sPerte = p.perte == null ? null : Math.max(0, Math.min(100, 100 - p.perte * 100));
      p.sComptoir = p.comptoir == null ? null : Math.max(0, Math.min(100, p.comptoir));
      let num = W.v * p.sVol, den = W.v;
      if (p.sMg != null)       { num += W.m * p.sMg; den += W.m; }
      if (p.sPerte != null)    { num += W.perte * p.sPerte; den += W.perte; }
      if (p.sComptoir != null) { num += W.comptoir * p.sComptoir; den += W.comptoir; }
      p.score = den ? num / den : 0; });
    const SC = this.scoringCfg();
    return { base, cats, nbOuv, W, SC,
      pond: _pond, periode: _per,
      verdict: s => s >= SC.moteur ? ['Moteur de gamme', '#2d7a3e', 'rgba(45,122,62,0.12)']
        : s >= SC.conforter ? ['À conforter', '#8a5a13', 'rgba(193,122,42,0.16)']
        : ['À arbitrer', '#8D1D2C', 'rgba(141,29,44,0.10)'] };
  }
  /**
   * Extraction sous seuil — arbitrer une gamme entière, pas une référence.
   *
   * L'écran de scoring trie ; celui-ci COUPE. On fixe un seuil, on obtient la
   * liste de tout ce qui passe dessous, avec de quoi comprendre pourquoi :
   * le critère le plus faible de chaque référence est nommé. Sans lui, on sait
   * qu'une référence est mauvaise sans savoir sur quoi agir — baisser un coût,
   * corriger une perte ou la déréférencer ne sont pas la même décision.
   */
  valsSeuil(common){
    const S = this.state;
    const _c = this.pdCalcule();
    const { base, SC, verdict } = _c;
    // Défaut : le seuil « à conforter » du réglage de scoring. Reprendre le
    // barème existant évite d'introduire un troisième seuil concurrent.
    const seuil = S.sqSeuil != null ? +S.sqSeuil : Math.round(SC.conforter);
    common.sqSeuil = seuil;
    common.sqSetSeuil = e => { const v = Math.max(0, Math.min(100, +e.target.value || 0));
      this.setState({ sqSeuil: v }); };
    common.sqPond = _c.pond;
    common.sqRepere = 'seuils du réglage : à conforter ' + Math.round(SC.conforter)
      + ' · moteur de gamme ' + Math.round(SC.moteur);
    common.sqTotal = base.length;

    const cats = [...new Set(base.map(p => p.cat).filter(Boolean))].sort();
    common.sqCat = S.sqCat || 'Toutes les catégories';
    common.sqCatOptions = ['Toutes les catégories'].concat(cats);
    common.sqSetCat = e => this.setState({ sqCat: e.target.value });

    const tris = [['score', 'Score croissant'], ['scoreDesc', 'Score décroissant'],
      ['ca', 'CA réseau décroissant'], ['vol', 'Volume décroissant'],
      ['perte', 'Taux de perte décroissant'], ['cat', 'Catégorie']];
    common.sqTri = S.sqTri || 'score';
    common.sqTriOptions = tris.map(t => ({ val: t[0], nom: t[1] }));
    common.sqSetTri = e => this.setState({ sqTri: e.target.value });

    let l = base.filter(p => p.score < seuil
      && (common.sqCat === 'Toutes les catégories' || p.cat === common.sqCat));
    const tri = common.sqTri;
    l.sort((a, b) => tri === 'scoreDesc' ? b.score - a.score
      : tri === 'ca' ? b.ca - a.ca
      : tri === 'vol' ? b.vol - a.vol
      : tri === 'perte' ? ((b.perte == null ? -1 : b.perte) - (a.perte == null ? -1 : a.perte))
      : tri === 'cat' ? (String(a.cat).localeCompare(String(b.cat)) || a.score - b.score)
      : a.score - b.score);

    // Le critère le PLUS FAIBLE, parmi ceux qui sont réellement mesurés. Un
    // critère absent ne peut pas être le point faible : il n'entre pas au score.
    const faible = p => {
      const c = [['volume', p.sVol], ['marge nette', p.sMg], ['perte', p.sPerte], ['comptoir', p.sComptoir]]
        .filter(x => x[1] != null);
      if (!c.length) { return '—'; }
      c.sort((a, b) => a[1] - b[1]);
      return c[0][0] + ' (' + Math.round(c[0][1]) + '/100)';
    };
    const caTot = base.reduce((a, p) => a + p.ca, 0) || 1;
    common.sqCaPart = this.fP(l.reduce((a, p) => a + p.ca, 0) / caTot, 1);
    common.sqNb = l.length;
    common.sqLignes = l.map(p => { const v = verdict(p.score);
      return { nom: p.nom, cat: p.cat || '—',
        score: Math.round(p.score),
        scoreCol: p.score < SC.conforter ? 'var(--color-primary)' : '#B87512',
        verdict: v[0], vFond: v[2], vCol: v[1],
        vol: Math.round(p.vol).toLocaleString('fr-BE'),
        ca: this.fK(p.ca),
        marge: p.mp == null ? 'manque API' : this.fP(p.mp, 0),
        margeVide: p.mp == null,
        perte: p.perte == null ? 'manque API' : this.fP(p.perte, 1),
        perteVide: p.perte == null,
        mags: p.mags + ' / ' + Math.round(p.mags / (p.pen || 1)),
        faible: faible(p) }; });
    // Exporter est le geste attendu : on arbitre une gamme sur un tableur, pas
    // dans un navigateur. Point-virgule et BOM pour qu'Excel FR ouvre droit.
    common.sqExport = () => {
      const t = [['Référence', 'Catégorie', 'Score', 'Verdict', 'Volume', 'CA réseau',
        'Taux de marge', 'Taux de perte', 'Critère le plus faible']];
      common.sqLignes.forEach(r => t.push([r.nom, r.cat, r.score, r.verdict, r.vol, r.ca,
        r.margeVide ? '' : r.marge, r.perteVide ? '' : r.perte, r.faible]));
      const csv = '﻿' + t.map(r => r.map(v => '"' + String(v).replace(/"/g, '""') + '"').join(';')).join('\r\n');
      const a = document.createElement('a');
      a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
      a.download = 'references-sous-' + seuil + '.csv';
      document.body.appendChild(a); a.click(); a.remove();
      this.log('Export', '', 'Références sous seuil ' + seuil + ' — ' + common.sqLignes.length + ' ligne(s)');
    };
    return common;
  }
  valsProduits(common){
    const S = this.state, D = this.D;
    const _c = this.pdCalcule();
    const { base, cats, nbOuv, W, SC } = _c;
    common.pdPond = _c.pond; common.pdPeriode = _c.periode;
    const verdict = _c.verdict;
    common.pdCat = S.pdCat; common.setPdCat = e => this.setState({ pdCat: e.target.value });
    common.pdCatOptions = ['Toutes les catégories'].concat(Object.keys(cats));
    const sorts = [['score', 'Trier par score'], ['volume', 'Trier par volume'], ['pen', 'Trier par pénétration réseau'], ['ca', 'Trier par CA réseau'], ['marge', 'Trier par taux de marge'], ['mg', 'Trier par marge brute'], ['rang', 'Trier par rang catégorie']];
    common.pdSortOptions = sorts.map(s => ({ val: s[0], nom: s[1] }));
    common.pdSort = S.pdSort; common.setPdSort = e => this.setState({ pdSort: e.target.value });
    const rows = base.filter(p => S.pdCat === 'Toutes les catégories' || p.cat === S.pdCat);
    rows.sort((a, b) => S.pdSort === 'volume' ? b.vol - a.vol : S.pdSort === 'pen' ? (b.pen - a.pen || b.score - a.score) : S.pdSort === 'ca' ? b.ca - a.ca
      : S.pdSort === 'marge' ? b.mp - a.mp : S.pdSort === 'mg' ? b.mg - a.mg
      : S.pdSort === 'rang' ? (a.rang - b.rang || b.score - a.score) : b.score - a.score);
    const caProd = base.reduce((a, p) => a + p.ca, 0) || 1;
    const bar = (v, col) => 'display:block;height:5px;border-radius:999px;background:' + col + ';width:' + Math.max(3, Math.min(100, Math.round(v))) + '%';
    const eur = v => v == null ? '—' : v.toFixed(2).replace('.', ',') + ' €';
    common.pdRows = rows.map(p => { const vd = verdict(p.score); const t = this.trend(p.tend, 1);
      return { nom: p.nom, cat: p.cat, vol: Math.round(p.vol).toLocaleString('fr-BE'), tend: t.txt, tendSt: t.st + ';font-weight:400',
        prix: eur(p.prix), mu: eur(p.mu), mp: p.mp == null ? '—' : this.fP(p.mp, 0) + ' de marge', mg: this.fK(p.mg),
        perteTxt: p.perte == null ? '—' : this.fP(p.perte, 1),
        perteSt: p.perte == null ? 'color:var(--color-text-muted)'
          : (p.perte >= 0.10 ? 'color:#8D1D2C;font-weight:600' : p.perte >= 0.05 ? 'color:#8a5a13;font-weight:500' : 'color:#2d7a3e'),
        perteDetail: p.jete != null ? (Math.round(p.jete).toLocaleString('fr-BE') + ' jeté(s)' + (p.motifPerte ? ' · ' + p.motifPerte : '')) : '',
        openWaste: () => this.pdOpenWaste(p.id, p.nom),
        pen: this.fP(p.pen, 0), mags: p.mags + ' / ' + nbOuv + ' magasins', partCaRes: this.fP(p.ca / caProd, 1), ca: this.fK(p.ca),
        barPen: bar(100 * p.pen, p.pen >= 0.8 ? '#2d7a3e' : p.pen >= 0.5 ? '#C17A2A' : '#8D1D2C'),
        rang: p.rang + ' / ' + p.nbCat, part: this.fP(p.partCat, 0),
        rangSt: this.pill(p.rang <= Math.ceil(p.nbCat / 3) ? 1 : p.rang <= Math.ceil(2 * p.nbCat / 3) ? 0.95 : 0.8),
        barVol: bar(p.sVol, '#8D1D2C'), barMg: bar(p.sMg == null ? 0 : p.sMg, '#2d7a3e'),
        barPerte: bar(p.sPerte == null ? 0 : p.sPerte, '#C17A2A'), barComptoir: bar(p.sComptoir == null ? 0 : p.sComptoir, '#6b7fa8'),
        mgDispo: p.sMg != null, perteDispo: p.sPerte != null, comptoirDispo: p.sComptoir != null,
        score: String(Math.round(p.score)), scoreSt: 'font-size:17px;font-weight:500;line-height:1;color:' + vd[1], scoreBar: bar(p.score, vd[1]),
        verdict: vd[0], verdictSt: 'display:inline-block;padding:3px 10px;border-radius:999px;font-size:11.5px;font-weight:500;white-space:nowrap;background:' + vd[2] + ';color:' + vd[1] }; });
    const nMot = base.filter(p => p.score >= SC.moteur).length, nArb = base.filter(p => p.score < SC.conforter).length;
    const mgVals = base.map(p => p.mg).filter(v => v != null);
    const caTot = caProd, mgTot = mgVals.length ? mgVals.reduce((a, v) => a + v, 0) : null;
    const penMoy = base.reduce((a, p) => a + p.pen, 0) / (base.length || 1);
    const nPart = base.filter(p => p.pen < 0.5).length;
    common.pdKpis = [{ k: 'Références notées', v: String(base.length), s: Object.keys(cats).length + ' catégories — ' + nbOuv + ' magasins ouverts' },
      { k: 'CA produit réseau', v: this.fK(caTot), s: 'Ventes du mois, tous magasins ouverts' },
      { k: 'Marge brute produits', v: this.fK(mgTot), s: mgTot == null ? 'Coût produit non exposé par la base partagée — marge indisponible' : this.fP(mgTot / caTot, 1) + ' de taux de marge sur le CA produit' },
      { k: 'Pénétration moyenne', v: this.fP(penMoy, 0), s: nPart + ' références vendues dans moins de la moitié du réseau' },
      { k: 'Moteurs de gamme', v: String(nMot), s: 'Score ≥ ' + SC.moteur + ' : disponibilité et mise en avant à sécuriser' },
      { k: 'À arbitrer', v: String(nArb), s: 'Score < ' + SC.conforter + ' : retrait, repricing ou relance commerciale' }];
    const manque = [];
    if (!base.some(p => p.sMg != null)) manque.push('marge nette (coût matière et main d’œuvre non exposés par la base partagée)');
    if (!base.some(p => p.sPerte != null)) manque.push('taux de perte (aucune source de casse/invendus)');
    if (!base.some(p => p.sComptoir != null)) manque.push('présence au comptoir (attribut produit non renseigné)');
    // --- modale « perte par magasin »
    const pw = S.pdWaste;
    if (pw) {
      const d = pw.d || {};
      const mags = (d.magasins || []);
      const avecTaux = mags.filter(m => m.taux != null);
      common.pdWaste = {
        nom: d.nom || pw.nom || ('#' + pw.id),
        chargement: !!pw.chargement,
        periode: d.du && d.au ? (this.fDA(d.du) + ' → ' + this.fDA(d.au)) : '',
        reseauTaux: (d.reseau && d.reseau.taux != null) ? this.fP(d.reseau.taux, 1) : '—',
        reseauDetail: d.reseau ? ((d.reseau.jete || 0).toLocaleString('fr-BE') + ' jeté(s) · ' + (d.reseau.vendu || 0).toLocaleString('fr-BE') + ' vendu(s)') : '',
        erreur: (d.api && d.api.erreur) || '',
        vide: !pw.chargement && avecTaux.length === 0,
        rows: mags.map(m => ({
          magasin: m.magasin,
          taux: m.taux == null ? '—' : this.fP(m.taux, 1),
          tauxSt: m.taux == null ? 'color:var(--color-text-muted)'
            : (m.taux >= 0.10 ? 'color:#8D1D2C;font-weight:600' : m.taux >= 0.05 ? 'color:#8a5a13;font-weight:600' : 'color:#2d7a3e;font-weight:500'),
          detail: m.taux == null ? 'Référence non proposée ici' : ((m.jete || 0).toLocaleString('fr-BE') + ' jeté(s) / ' + (m.vendu || 0).toLocaleString('fr-BE') + ' vendu(s)'),
          motif: m.motif || '', caPerdu: m.caPerdu != null ? this.fE(m.caPerdu) : '',
          barSt: 'display:block;height:5px;border-radius:999px;background:' + (m.taux == null ? 'var(--color-border-secondary)' : m.taux >= 0.10 ? '#8D1D2C' : m.taux >= 0.05 ? '#C17A2A' : '#2d7a3e')
            + ';width:' + Math.max(m.taux ? 3 : 0, Math.min(100, (m.taux || 0) * 100 * 5)) + '%',
        })),
        note: avecTaux.length > 1
          ? 'Écart réseau : ' + this.fP(Math.max.apply(null, avecTaux.map(m => m.taux)), 1) + ' au plus haut contre ' + this.fP(Math.min.apply(null, avecTaux.map(m => m.taux)), 1) + ' au plus bas. Un taux réseau moyen peut masquer un seul point de vente.'
          : '',
        close: () => this.setState({ pdWaste: null }),
      };
    } else { common.pdWaste = false; }
    common.pdNote = 'Score sur 100 = moyenne pondérée de quatre notes : volume vendu, marge nette, taux de perte et présence au comptoir. Pondération réglée dans Paramètres — ' + common.pdPond + '.'
      + (manque.length ? ' Critère(s) sans donnée aujourd’hui, donc EXCLUS du calcul (le score est repondéré sur les critères disponibles, il n’est pas pénalisé) : ' + manque.join(' ; ') + '.' : '');
  }

  /* --- marge & coûts ------------------------------------------------------------ */
  valsMarge(common){
    const s = this.seuils();
    common.sFoodTxt = s.f + ' %'; common.sLabourTxt = s.l + ' %';
    const E = this.exo(), MI = this.moisIdxComplet();
    const moisNom = (this.M.MOIS && this.M.MOIS[MI]) || '';
    common.mgEvoLabel = 'Évolution mensuelle ' + E + ' (janv. → ' + moisNom + ')';
    common.mgHdrPeriode = moisNom + ' ' + E + (this.moisPartiel() ? ' — dernier mois complet' : '');
    const mg26 = this.sum(E, MI, 'marge') / this.sum(E, MI, 'ca'), mg25 = this.sum(E - 1, MI, 'marge') / this.sum(E - 1, MI, 'ca');
    common.mgReseau = this.fP(mg26); const tr = this.trend(mg26, mg25); common.mgTr = tr.txt + ' vs N-1'; common.mgTrSt = tr.st;
    const series = []; for (let m = 0; m <= MI; m++) series.push(this.sum(E, m, 'marge') / this.sum(E, m, 'ca'));
    common.mgTraj = this.spark(series, 320, 70);
    common.mgAlerts = this.margeAlerts();
    if (!common.mgAlerts.length) common.mgAlerts = [{ store: 'Aucune alerte', lev: 'food-cost', levNom: '—', msg: 'tous les ratios sont sous les seuils', action: '' }];
    // Un ratio absent du P&L (le panel n'expose pas le food cost) s'affichait
    // « null % » : le mot « null » n'est pas une valeur, et une pastille verte
    // sur une donnée manquante se lit comme une performance.
    const pct = v => (v == null || !isFinite(v)) ? '—' : String(v).replace('.', ',') + ' %';
    const rat = (v, seuil) => { const base = 'display:inline-block;padding:3px 9px;border-radius:999px;font-size:12px;font-weight:500;';
      if (v == null || !isFinite(v)) { return base + 'background:var(--color-background-secondary);color:var(--color-text-muted)'; }
      return v > seuil ? base + 'background:rgba(141,29,44,0.12);color:#8D1D2C' : v > seuil - 1.5 ? base + 'background:rgba(193,122,42,0.16);color:#8a5a13' : base + 'background:rgba(45,122,62,0.10);color:#2d7a3e'; };
    const seuilEtp = this.seuilCaEtp();
    common.mgSeuilEtp = this.fE(seuilEtp);
    const caEtpPill = v => { const base = 'display:inline-block;padding:3px 9px;border-radius:999px;font-size:12px;font-weight:500;';
      return v < seuilEtp ? base + 'background:rgba(141,29,44,0.12);color:#8D1D2C' : v < seuilEtp * 1.08 ? base + 'background:rgba(193,122,42,0.16);color:#8a5a13' : base + 'background:rgba(45,122,62,0.10);color:#2d7a3e'; };
    const rows = this.open().map(st => { const r = st.perf[E][MI], n1 = st.perf[E - 1][MI];
      const mp26 = r.marge / r.ca, mp25 = n1.marge / n1.ca; const tv = this.trend(mp26, mp25);
      const nAl = (r.food > s.f ? 1 : 0) + (r.labour > s.l ? 1 : 0) + (r.overhead > s.o ? 1 : 0);
      // ETP RÉEL : heures planifiées du mois ÷ 168 (réglage). Inconnu = « — »,
      // jamais une estimation tirée du CA : le ratio CA/ETP sert à juger un
      // dimensionnement d'équipe, un effectif deviné le rendrait circulaire.
      const etp = (r.etp != null && r.etp > 0) ? r.etp : null;
      const caEtp = (etp && r.ca != null) ? r.ca / etp : null;
      return { _mp: mp26, nom: st.nom, marge: this.fP(mp26), var: tv.txt, varSt: tv.st,
        food: pct(r.food), foodSt: rat(r.food, s.f), labour: pct(r.labour), labourSt: rat(r.labour, s.l), ov: pct(r.overhead), ovSt: rat(r.overhead, s.o),
        caEtp: caEtp == null ? '—' : this.fE(caEtp),
        etp: etp == null ? 'ETP inconnu' : (etp.toFixed(1).replace('.', ',') + ' ETP' + (r.heures != null ? ' · ' + Math.round(r.heures) + ' h' : '')),
        caEtpSt: caEtp == null ? 'display:inline-block;padding:3px 9px;border-radius:999px;font-size:12px;color:var(--color-text-muted)' : caEtpPill(caEtp),
        statut: nAl === 0 ? 'OK' : nAl + (nAl > 1 ? ' leviers à traiter' : ' levier à traiter') + (st.risk ? ' · sous-perf. 3 mois consécutifs' : '') }; });
    rows.sort((a, b) => b._mp - a._mp);
    common.mgRows = rows;
  }

  /* --- contrôle des tâches (checklists consultants du panel) --------------------- */
  ctrlSetDate(d){
    if (!d) return;
    if (this.source !== 'api'){ this.setState({ ctrlShop: 'tous' }); return; }
    readOne('/pwa/tasks?date=' + encodeURIComponent(d)).then(pt => {
      if (pt) this.D.pwaTasks = pt;
      this.setState({ ctrlShop: 'tous' });
    });
  }
  ctrlToggle(shopId, taskId, date, on){
    const pt = this.D.pwaTasks || {};
    const owner = ((this.meta || {}).utilisateur || {}).nom || 'CEO';
    (pt.shops || []).forEach(sh => { if (sh.shopId === shopId) sh.taches.forEach(t => {
      if (t.taskId === taskId && t.date === date){ t.valide = on; t.valideePar = on ? owner : null; t.valideeLe = on ? 'à l’instant' : null; }
    }); });
    if (pt.totals){ const n = (pt.shops || []).reduce((a, sh) => a + sh.taches.filter(t => t.valide).length, 0);
      pt.totals.valides = n; pt.totals.aValider = (pt.totals.taches || 0) - n; }
    this.api('POST', '/pwa/tasks/validate', { shopId, taskId, date, validated: on });
    this.log('Validation', null, (on ? 'Avis validé' : 'Validation retirée') + ' — boutique ' + shopId + ', tâche ' + taskId + ' (' + date + ')');
    this.setState({});
    this.notify(on ? 'Avis validé' : 'Validation retirée');
  }
  /* Ouvre le détail d'une tâche : photo de réalisation + notation. Le détail
     vient de l'API du panel (la base ne porte ni le nom ni la photo). */
  ctrlOpenTask(shopId, taskId, date, tacheNom){
    this.setState({ ctrlDet: { shopId, taskId, date, nom: tacheNom, chargement: true, d: null, note: null, comment: '', envoi: false, rep: [] } });
    readOne('/pwa/tasks/detail?shop=' + encodeURIComponent(shopId) + '&task=' + encodeURIComponent(taskId) + '&date=' + encodeURIComponent(date))
      .then(d => this.setState(s => (s.ctrlDet && s.ctrlDet.taskId === taskId)
        ? { ctrlDet: Object.assign({}, s.ctrlDet, { chargement: false, d: d || null,
            note: (d && d.avis && d.avis.note) || null, comment: (d && d.avis && d.avis.comment) || '',
            // Repères déjà posés : ils reviennent avec le détail, on ne les
            // relit pas dans un second appel.
            rep: ((d && d.reperes) || {}).liste || [] }) }
        : {}));
  }

  /* --- repères sur la photo : cadre numéroté + gravité ------------------------ */

  /** Le barème de gravité, tel que le porte le réglage partagé. */
  zNiveaux(){
    const l = ((this.M || {}).SIGNAL || {}).niveaux || [];
    return l.length ? l : [{ n: 3, nom: 'Non conforme — mineur', couleur: '#D97706' }];
  }
  zNiveau(n){
    const l = this.zNiveaux();
    return l.find(v => v.n === n) || l.find(v => v.n === 3) || l[0];
  }
  /**
   * Épaisseur et style du cadre selon la gravité.
   *
   * La couleur seule ne suffit pas : « majeur » (#C0182B) et « critique »
   * (#8D1D2C) sont deux rouges voisins, indiscernables sur une photo sombre et
   * pour une part des daltoniens. La FORME porte donc la même information —
   * plus épais quand c'est plus grave, doublé quand c'est critique.
   */
  zTrait(n){
    if (n <= 1) { return { w: 4, dbl: true, dash: '' }; }
    if (n === 2) { return { w: 4, dbl: false, dash: '' }; }
    if (n === 3) { return { w: 3, dbl: false, dash: '' }; }
    return { w: 2, dbl: false, dash: '5 4' };     // conforme / exemplaire : constat
  }
  zOpen(){
    this.setState(s => ({ ctrlDet: Object.assign({}, s.ctrlDet,
      { zoom: true, zSel: null, zCompare: false, zBusy: false, zSaved: false, zImgErr: false }) }));
    this.zSurveillePhoto();
  }
  /**
   * Surveille le CHARGEMENT de la photo, et le dit quand il échoue.
   *
   * L'URL de la photo est signée et expire : une modale ouverte longtemps après
   * la lecture du détail se retrouvait avec une image vide, donc une surface de
   * tracé de 0 × 0 — on cliquait dans le vide sans qu'aucun message
   * n'explique pourquoi. On ne peut pas l'apprendre au rendu : le navigateur
   * ne sait lui-même que la photo est perdue qu'à la fin de la requête.
   */
  zSurveillePhoto(){
    setTimeout(() => {
      const img = document.querySelector('[data-zimg]');
      if (!img) { return; }
      const rate = () => { const dt = this.state.ctrlDet;
        if (dt && dt.zoom && !dt.zImgErr) { this.zPatch({ zImgErr: true }); } };
      if (img.complete) { if (!img.naturalWidth) { rate(); } return; }
      img.addEventListener('error', rate, { once: true });
      img.addEventListener('load', () => { const dt = this.state.ctrlDet;
        if (dt && dt.zoom && dt.zImgErr) { this.zPatch({ zImgErr: false }); } }, { once: true });
    }, 0);
  }
  zClose(){
    this.setState(s => ({ ctrlDet: Object.assign({}, s.ctrlDet, { zoom: false, zSel: null }) }));
  }
  zPatch(patch){
    this.setState(s => ({ ctrlDet: Object.assign({}, s.ctrlDet, patch) }));
  }
  /**
   * Pose un repère : on glisse sur la zone, le cadre suit le doigt.
   *
   * L'aperçu est dessiné DIRECTEMENT dans le DOM pendant le geste. Passer par
   * l'état à chaque pointermove relancerait le rendu complet de l'écran
   * plusieurs fois par seconde ; le cadre est donc un élément vivant, et l'état
   * n'est touché qu'au relâchement — un seul rendu par repère.
   */
  zDown(ev){
    const surf = ev.target.closest ? ev.target.closest('[data-zsurf]') : null;
    if (!surf) { return; }
    // Un clic SUR un repère existant le sélectionne — il n'en pose pas un
    // second par-dessus. Sans ce garde-fou, corriger une remarque commencerait
    // par empiler un cadre sur celui qu'on visait.
    if (ev.target.closest('[data-zbox]')) { return; }
    // Pas de repère sur une photo qu'on ne voit pas : le cadre porterait des
    // coordonnées qui ne désignent rien.
    if ((this.state.ctrlDet || {}).zImgErr) { return; }
    ev.preventDefault();
    const r = surf.getBoundingClientRect();
    if (r.width < 20 || r.height < 20) { return; }
    const px = c => Math.max(0, Math.min(1, (c - r.left) / r.width));
    const py = c => Math.max(0, Math.min(1, (c - r.top) / r.height));
    const x0 = px(ev.clientX), y0 = py(ev.clientY);
    const niv = this.state.ctrlDet.zNiv || 3;
    const lv = this.zNiveau(niv), tr = this.zTrait(niv);

    const vue = document.createElement('div');
    vue.style.cssText = 'position:absolute;pointer-events:none;border-radius:5px;box-sizing:border-box;'
      + 'border:' + tr.w + 'px solid ' + lv.couleur + ';outline:2px solid rgba(255,255,255,0.85);outline-offset:0';
    surf.appendChild(vue);

    let x1 = x0, y1 = y0;
    const bouge = e => {
      x1 = px(e.clientX); y1 = py(e.clientY);
      vue.style.left = (Math.min(x0, x1) * 100) + '%';
      vue.style.top = (Math.min(y0, y1) * 100) + '%';
      vue.style.width = (Math.abs(x1 - x0) * 100) + '%';
      vue.style.height = (Math.abs(y1 - y0) * 100) + '%';
    };
    bouge(ev);
    const fini = () => {
      window.removeEventListener('pointermove', bouge);
      window.removeEventListener('pointerup', fini);
      window.removeEventListener('pointercancel', fini);
      if (vue.parentNode) { vue.parentNode.removeChild(vue); }
      // Un clic sec pose un cadre par défaut : viser au pixel n'est pas
      // toujours possible au doigt, et un cadre de zéro n'indiquerait rien.
      let x = Math.min(x0, x1), y = Math.min(y0, y1);
      let l = Math.abs(x1 - x0), h = Math.abs(y1 - y0);
      if (l < 0.03 || h < 0.03) {
        l = Math.max(l, 0.12); h = Math.max(h, 0.12);
        x = Math.max(0, Math.min(1 - l, x0 - l / 2));
        y = Math.max(0, Math.min(1 - h, y0 - h / 2));
      }
      l = Math.min(l, 1 - x); h = Math.min(h, 1 - y);
      this.setState(s => {
        const rep = ((s.ctrlDet || {}).rep || []).slice();
        rep.push({ n: rep.length + 1, x: +x.toFixed(4), y: +y.toFixed(4),
          l: +l.toFixed(4), h: +h.toFixed(4), niveau: niv, txt: '' });
        return { ctrlDet: Object.assign({}, s.ctrlDet, { rep, zSel: rep.length - 1, zSaved: false }) };
      });
      // Le commentaire du repère s'ouvre focalisé : poser un cadre sans dire
      // pourquoi ne sert à rien, autant enchaîner sans chercher le champ.
      setTimeout(() => { const t = document.getElementById('zrep-txt'); if (t) { t.focus(); } }, 0);
    };
    window.addEventListener('pointermove', bouge);
    window.addEventListener('pointerup', fini);
    window.addEventListener('pointercancel', fini);
  }
  zRepSet(i, champ, val){
    this.setState(s => {
      const rep = ((s.ctrlDet || {}).rep || []).slice();
      if (!rep[i]) { return {}; }
      rep[i] = Object.assign({}, rep[i], { [champ]: val });
      return { ctrlDet: Object.assign({}, s.ctrlDet, { rep, zSaved: false }) };
    });
  }
  /** Supprime un repère — et RENUMÉROTE : un 1, 3, 4 ne se lit pas. */
  zRepDel(i){
    this.setState(s => {
      const rep = ((s.ctrlDet || {}).rep || []).filter((_, k) => k !== i)
        .map((r, k) => Object.assign({}, r, { n: k + 1 }));
      const sel = (s.ctrlDet || {}).zSel;
      return { ctrlDet: Object.assign({}, s.ctrlDet,
        { rep, zSaved: false, zSel: sel == null ? null : (sel === i ? null : (sel > i ? sel - 1 : sel)) }) };
    });
  }
  /**
   * Compose le commentaire au franchisé depuis les repères.
   *
   * Chaque ligne porte son numéro et sa gravité : le magasin lit « 2. majeur »
   * en face du cadre 2 sur la photo. Le texte reste modifiable ensuite — c'est
   * une composition, pas un verrou. Un commentaire déjà écrit n'est jamais
   * écrasé sans être proposé : on l'ajoute en dessous.
   */
  zCompose(){
    const dt = this.state.ctrlDet || {};
    const rep = (dt.rep || []).filter(r => String(r.txt || '').trim());
    if (!rep.length) { this.notify('Aucun repère commenté à reprendre.'); return; }
    const court = nom => String(nom || '').replace(/^Non conforme\s*[—-]\s*/i, '').toLowerCase();
    const lignes = rep.map(r => r.n + '. [' + court((this.zNiveau(r.niveau) || {}).nom) + '] ' + String(r.txt).trim());
    const dej = String(dt.comment || '').trim();
    const txt = lignes.join('\n');
    this.zPatch({ comment: dej && dej !== txt ? dej + '\n' + txt : txt });
    this.notify(rep.length + ' repère(s) reportés dans le commentaire');
  }
  /**
   * Enregistre les repères. Une liste vide efface — c'est « tout effacer ».
   *
   * La confirmation reste DANS la modale. Le bandeau de notification s'affiche
   * en bas à droite de l'écran, c'est-à-dire pile sur les boutons de ce
   * panneau : il recouvrait le « Enregistré ✓ » qu'on venait chercher et
   * donnait l'impression que quelque chose sortait de la modale. L'échec suit
   * le même chemin, écrit sous les boutons.
   */
  zSave(){
    const dt = this.state.ctrlDet;
    if (!dt || dt.zBusy) { return; }
    const n = (dt.rep || []).length;
    this.zPatch({ zBusy: true, zErr: '' });
    write(this.source, 'PUT', '/pwa/tasks/annotation', { shopId: dt.shopId, taskId: dt.taskId, date: dt.date,
      reperes: (dt.rep || []).map(r => ({ x: r.x, y: r.y, l: r.l, h: r.h, niveau: r.niveau, txt: r.txt })) })
      .then(r => {
        if (!r || r.ok === false) {
          this.zPatch({ zBusy: false, zSaved: false,
            zErr: 'Non enregistré — ' + ((r && r.error) || 'refusé par le serveur') });
          return;
        }
        this.zPatch({ zBusy: false, zSaved: true,
          zSavedTxt: n ? (n > 1 ? n + ' repères enregistrés' : '1 repère enregistré') : 'Repères effacés' });
      });
  }
  ctrlSendNote(){
    const dt = this.state.ctrlDet;
    if (!dt || !dt.note) { this.notify('Choisissez un niveau de conformité.'); return; }
    // Sous le seuil, le commentaire est obligatoire : une non-conformité sans
    // motif est ininterprétable un mois plus tard.
    const seuil = (this.M.SIGNAL && this.M.SIGNAL.seuil) || 4;
    if (dt.note < seuil && !String(dt.comment || '').trim()) {
      this.notify('Commentaire obligatoire pour une non-conformité.'); return;
    }
    const d = dt.d || {};
    this.setState(s => ({ ctrlDet: Object.assign({}, s.ctrlDet, { envoi: true }) }));
    write(this.source, 'POST', '/pwa/tasks/review', { shopId: dt.shopId, taskId: dt.taskId, date: dt.date,
      note: dt.note, comment: dt.comment, checklistId: d.checklistId || null, completionId: d.completionId || null })
      .then(() => readOne('/pwa/tasks?date=' + encodeURIComponent(dt.date)))
      .then(pt => { if (pt) this.D.pwaTasks = pt;
        this.setState({ ctrlDet: null });
        this.notify('Note ' + dt.note + '/5 envoyée au panel'); })
      .catch(() => { this.setState(s => ({ ctrlDet: Object.assign({}, s.ctrlDet, { envoi: false }) }));
        this.notify('Échec de l’envoi de la note.'); });
  }
  valsControle(common){
    const S = this.state, D = this.D, M = this.M;
    const pt = D.pwaTasks || { shops: [], dates: [], consultants: [], totals: {}, indispo: true };
    const T = pt.totals || {};
    common.ctrlIndispo = !!pt.indispo && (pt.shops || []).length === 0;
    common.ctrlDate = pt.date || '';
    common.ctrlDateLabel = pt.date ? this.fD(pt.date) + '/' + String(pt.date).slice(0, 4) : '—';
    common.ctrlDates = (pt.dates || []).map(d => ({ val: d, label: this.fD(d) + '/' + String(d).slice(0, 4), sel: d === pt.date }));
    common.setCtrlDate = e => this.ctrlSetDate(e.target.value);
    const nMoy = T.noteMoy != null ? String(T.noteMoy).replace('.', ',') + ' / 5' : '—';
    common.ctrlKpis = [
      { k: 'Tâches évaluées', v: String(T.taches || 0), s: (pt.shops || []).length + ' boutique(s) — journée du ' + common.ctrlDateLabel },
      { k: 'À noter', v: String(T.aValider || 0), s: 'Tâches rendues sans évaluation — la note vaut validation' },
      { k: 'Validées', v: String(T.valides || 0), s: 'Tâches évaluées : une note posée vaut validation' },
      { k: 'Non conformes', v: String(T.refuses || 0), s: 'Évaluées sous le seuil de conformité' },
      { k: 'Note moyenne', v: nMoy, s: 'Moyenne des notes consultants du jour' },
    ];
    // Filtre boutique
    const shopOpts = ['Toutes les boutiques'].concat((pt.shops || []).map(s => s.shop));
    common.ctrlShopOptions = shopOpts;
    common.ctrlShop = S.ctrlShop || 'Toutes les boutiques';
    common.setCtrlShop = e => this.setState({ ctrlShop: e.target.value });
    // Filtre statut
    common.ctrlOnly = S.ctrlOnly || 'tous';
    // « À noter seulement » retenait tout ce qui n'était pas noté, y compris ce
    // qui n'est pas notable : une tâche sans photo n'a rien à juger, et une
    // tâche non rendue n'est pas en retard de contrôle. Trois filtres distincts.
    common.ctrlOnlyOptions = [{ val: 'tous', nom: 'Toutes les tâches' },
      { val: 'acontroler', nom: 'À contrôler — photographiées, non notées' },
      { val: 'sansphoto', nom: 'Rendues sans photo' },
      { val: 'avalider', nom: 'Toutes les non notées' },
      { val: 'refuses', nom: 'Non conformes seulement' }];
    common.setCtrlOnly = e => this.setState({ ctrlOnly: e.target.value });

    const seuilC = pt.seuil || 4;
    const noteSt = n => n == null ? 'color:var(--color-text-muted)' : n >= seuilC ? 'color:#2d7a3e;font-weight:600' : n >= seuilC - 1 ? 'color:#8a5a13;font-weight:600' : 'color:#8D1D2C;font-weight:600';
    // Libellé du niveau (Exemplaire, Conforme, NC mineur…) à partir du barème.
    const nomNiveau = n => { const lv = ((M.SIGNAL || {}).niveaux || []).find(l => l.n === n); return lv ? lv.nom : (n + '/5'); };
    const mkRep = r => ({ nom: r.nom, aide: r.aide || '', nb: String(r.nb),
      pct: r.pct + ' %', txt: r.nb + ' · ' + r.pct + ' %',
      dotSt: 'width:9px;height:9px;border-radius:50%;flex:0 0 auto;background:' + r.couleur,
      barSt: 'display:block;height:5px;border-radius:999px;background:' + r.couleur + ';width:' + Math.max(r.nb > 0 ? 3 : 0, Math.min(100, r.pct)) + '%' });
    const shops = (pt.shops || []).filter(s => common.ctrlShop === 'Toutes les boutiques' || s.shop === common.ctrlShop)
      .map(s => {
        const taches = (s.taches || []).filter(t =>
            common.ctrlOnly === 'tous' ? true
          : common.ctrlOnly === 'acontroler' ? (t.statut === 'aControler' && !t.valide)
          : common.ctrlOnly === 'sansphoto' ? (t.statut === 'sansPhoto')
          : common.ctrlOnly === 'avalider' ? !t.valide
          : (t.note != null && t.note < seuilC))
          .map(t => ({
            taskId: t.taskId, tache: t.tache,
            note: t.note == null ? '—' : t.note + ' / 5', noteSt: noteSt(t.note),
            // Le niveau NOMMÉ prime sur le verdict binaire : « Non conforme »
            // sans gravité ne dit pas s'il faut repasser demain ou tout de suite.
            acc: t.note != null ? nomNiveau(t.note) : (t.accepte == null ? '—' : (t.accepte ? 'Conforme' : 'Non conforme')),
            accSt: (t.note != null ? (t.note >= seuilC ? 'color:#2d7a3e;font-weight:500' : 'color:#8D1D2C;font-weight:500')
              : (t.accepte == null ? 'color:var(--color-text-muted)' : (t.accepte ? 'color:#2d7a3e' : 'color:#8D1D2C;font-weight:500'))),
            comment: t.comment || '', hasComment: !!t.comment,
            consultant: t.consultant || '—',
            // La note EST la validation : une tâche notée est validée, une tâche
            // sans note reste à noter. Pas de case à cocher en plus — pour
            // changer un verdict, on renote.
            valide: t.valide,
            valideMeta: t.valide
              ? ('Validé' + (t.valideePar ? ' par ' + t.valideePar : '')
                 + (t.revuePar ? ' · revu par ' + t.revuePar : '')
                 + (t.valideeLe ? ' · ' + t.valideeLe : ''))
              : 'Pas encore noté',
            btnLabel: t.valide ? 'Renoter' : 'Noter',
            btnSt: 'cursor:pointer;font-family:var(--font-ui);font-size:12px;font-weight:500;padding:6px 14px;border-radius:999px;border:0.5px solid ' + (t.valide ? 'var(--color-border-secondary);background:transparent;color:var(--color-text-muted)' : 'transparent;background:var(--color-primary);color:#fff'),
            toggle: () => this.ctrlOpenTask(s.shopId, t.taskId, t.date, t.tache),
            open: () => this.ctrlOpenTask(s.shopId, t.taskId, t.date, t.tache),
          }));
        return { shop: s.shop, shopId: s.shopId, nTaches: (s.taches || []).length,
          nValid: (s.taches || []).filter(x => x.valide).length, taches, vide: taches.length === 0 };
      }).filter(s => !(common.ctrlOnly !== 'tous' && s.vide));
    common.ctrlShops = shops;
    common.ctrlEmpty = shops.length === 0;

    common.ctrlConsultants = (pt.consultants || []).map(c => ({
      nom: c.nom, avis: String(c.avis), refuses: String(c.refuses), valides: String(c.valides),
      noteMoy: c.noteMoy != null ? String(c.noteMoy).replace('.', ',') + ' / 5' : '—',
    }));

    // Répartition par niveau de conformité (Exemplaire … NC critique) — même
    // barème que l'écran de validation, avec effectif et part.
    const rep = pt.repartition || [];
    common.ctrlRepConf = rep.filter(r => r.conforme).map(mkRep);
    common.ctrlRepNc = rep.filter(r => !r.conforme).map(mkRep);
    common.ctrlRepVide = rep.length === 0;
    common.ctrlNotees = String(T.notees || 0);
    common.ctrlNonNotees = String(T.nonNotees || 0);

    // Bandeau si le compte API n'est pas branché : les noms restent « Tâche #… »
    // et les photos sont indisponibles tant qu'il manque.
    const api = pt.api || {};
    common.ctrlApiOff = !api.configure;
    common.ctrlApiErr = api.erreur || '';

    // --- volet détail d'une tâche (photo + notation)
    const dt = S.ctrlDet;
    if (dt) {
      const d = dt.d || {};
      const a = d.avis || {};
      common.ctrlDet = {
        nom: d.tache || dt.nom || ('Tâche #' + dt.taskId),
        checklist: d.checklist || '', date: this.fDA(dt.date),
        chargement: !!dt.chargement,
        photo: d.photo || null,
        photoTxt: d.photo ? '' : (dt.chargement ? 'Chargement de la photo…'
          : (d.api && d.api.configure === false ? 'Compte API non configuré — photo indisponible.'
            : (d.photoRequise === false ? 'Cette tâche n’exige pas de photo.' : 'Aucune photo de réalisation pour cette tâche.'))),
        statut: d.statut || '—', obligatoire: d.obligatoire ? 'Obligatoire' : '',
        avisTxt: a.note != null ? (a.note + '/5 · ' + (a.accepte ? 'conforme' : 'non conforme')
          + (a.consultant ? ' — ' + a.consultant : '')) : 'Pas encore d’avis consultant',
        avisComment: a.comment || '',
        note: dt.note, comment: dt.comment, envoi: !!dt.envoi,
        erreur: (d.api && d.api.erreur) || '',
        // Barème des cinq niveaux — le MÊME référentiel que l'écran de
        // validation (réglage `signalement`), jamais une échelle d'étoiles
        // muette : « majeur » doit vouloir dire la même chose partout.
        niveaux: ((M.SIGNAL || {}).niveaux || []).map(lv => { const on = dt.note === lv.n;
          return { n: lv.n, nom: lv.nom, aide: lv.aide || '',
            conforme: lv.n >= ((M.SIGNAL || {}).seuil || 4),
            st: 'display:flex;align-items:center;gap:10px;width:100%;text-align:left;cursor:pointer;'
              + 'font-family:var(--font-ui);font-size:12.5px;padding:9px 12px;border-radius:9px;margin-bottom:6px;'
              + (on ? 'border:1px solid ' + lv.couleur + ';background:' + lv.couleur + '14;font-weight:600;color:var(--color-text)'
                    : 'border:0.5px solid var(--color-border-secondary);background:transparent;color:var(--color-text)'),
            dotSt: 'width:10px;height:10px;border-radius:50%;flex:0 0 auto;background:' + lv.couleur,
            pick: () => this.setState(s2 => ({ ctrlDet: Object.assign({}, s2.ctrlDet, { note: lv.n }) })) }; }),
        verdict: dt.note == null ? '' : (dt.note >= ((M.SIGNAL || {}).seuil || 4) ? 'Conforme' : 'Non conforme'),
        verdictSt: dt.note == null ? '' : 'display:inline-block;padding:3px 10px;border-radius:999px;font-size:11.5px;font-weight:500;'
          + (dt.note >= ((M.SIGNAL || {}).seuil || 4) ? 'background:rgba(45,122,62,0.12);color:#2d7a3e' : 'background:rgba(141,29,44,0.12);color:#8D1D2C'),
        commentRequis: dt.note != null && dt.note < ((M.SIGNAL || {}).seuil || 4),
        produit: d.produit || '', photoRef: d.photoRef || null,
        photoRefTxt: d.produitId ? (d.photoRef ? '' : 'Fiche technique sans visuel pour ce produit.')
          : 'Cette tâche ne porte pas sur un produit précis — pas de visuel de référence.',
        setComment: e => { const v = e.target.value; this.setState(s2 => ({ ctrlDet: Object.assign({}, s2.ctrlDet, { comment: v }) })); },
        send: () => this.ctrlSendNote(),
        close: () => this.setState({ ctrlDet: null }),
        peutNoter: !dt.chargement && (d.api ? d.api.configure !== false : true),
        // --- assistance IA : proposition, jamais décision
        iaDispo: (this.D.iaStatut || {}).configure === true,
        iaBusy: !!dt.iaBusy,
        iaFait: !!dt.ia,
        iaMotif: (dt.ia && dt.ia.motif) || '',
        iaNom: (dt.ia && dt.ia.nom) || '',
        iaConfiance: (dt.ia && dt.ia.confiance) || '',
        iaModele: (dt.ia && dt.ia.modele) || '',
        iaConstats: (dt.ia && dt.ia.constats) || [],
        iaAvert: (dt.ia && dt.ia.avertissement) || '',
        iaGo: () => this.ctrlIaProposer(),
        // Reprendre ne valide pas : cela remplit le formulaire, l'envoi reste
        // un geste distinct. Le commentaire proposé complète le vôtre sans
        // l'écraser — on ne perd pas ce qui a déjà été écrit.
        iaAppliquer: () => this.setState(s2 => { const p = (s2.ctrlDet || {}).ia || {};
          const dej = ((s2.ctrlDet || {}).comment || '').trim();
          return { ctrlDet: Object.assign({}, s2.ctrlDet, {
            note: p.niveau != null ? p.niveau : (s2.ctrlDet || {}).note,
            comment: dej ? dej : (p.commentaire || '') }) }; }),
        // Repères : l'agrandissement de la photo EST l'écran d'annotation.
        zoomGo: d.photo ? () => this.zOpen() : null,
        nRep: (dt.rep || []).length,
      };
      common.ctrlZoom = dt.zoom && d.photo ? this.valsCtrlZoom(dt, d) : false;
    } else { common.ctrlDet = false; common.ctrlZoom = false; }
  }

  /** L'agrandissement annotable : cadres numérotés + liste des remarques. */
  valsCtrlZoom(dt, d){
    const rep = dt.rep || [];
    const sel = dt.zSel;
    const nivCourant = dt.zNiv || 3;
    const court = nom => String(nom || '').replace(/^Non conforme\s*[—-]\s*/i, '');
    return {
      nom: d.tache || dt.nom || ('Tâche #' + dt.taskId),
      sous: [d.checklist || '', this.fDA(dt.date), (d.avis && d.avis.consultant) ? 'rendue par ' + d.avis.consultant : '']
        .filter(Boolean).join(' · '),
      photo: d.photo,
      imgErr: !!dt.zImgErr,
      imgErrTxt: 'La photo n’a pas pu être chargée depuis le stockage du panel : son lien signé a expiré, '
        + 'ou le stockage est injoignable. Fermez et rouvrez la tâche pour obtenir un lien neuf.',
      close: () => this.zClose(),
      down: e => this.zDown(e),
      n: rep.length,
      busy: !!dt.zBusy, saved: !!dt.zSaved,
      savedTxt: dt.zSavedTxt || '', err: dt.zErr || '',
      // Gravité du PROCHAIN repère : on choisit avant de poser, comme on
      // choisit un feutre avant de tracer.
      niveaux: this.zNiveaux().map(lv => ({
        n: lv.n, nom: court(lv.nom), couleur: lv.couleur, on: lv.n === nivCourant,
        pick: () => this.zPatch({ zNiv: lv.n })
      })),
      // Les cadres, dessinés en pourcentage de la photo : le rendu suit la
      // taille d'affichage sans que les coordonnées ne bougent.
      cadres: rep.map((r, i) => { const lv = this.zNiveau(r.niveau) || {}, tr = this.zTrait(r.niveau);
        const actif = sel === i;
        return { n: r.n, couleur: lv.couleur || '#D97706',
          boxSt: 'position:absolute;box-sizing:border-box;border-radius:5px;cursor:pointer;'
            + 'left:' + (r.x * 100) + '%;top:' + (r.y * 100) + '%;'
            + 'width:' + (r.l * 100) + '%;height:' + (r.h * 100) + '%;'
            + 'border:' + tr.w + 'px ' + (tr.dash ? 'dashed' : 'solid') + ' ' + (lv.couleur || '#D97706') + ';'
            + 'outline:2px solid rgba(255,255,255,' + (actif ? '1' : '0.8') + ');outline-offset:0;'
            + (tr.dbl ? 'box-shadow:inset 0 0 0 3px rgba(255,255,255,0.9), inset 0 0 0 6px ' + (lv.couleur || '#D97706') + ';' : '')
            + (actif ? 'z-index:3' : ''),
          badgeSt: 'position:absolute;left:-3px;top:-15px;min-width:22px;height:22px;padding:0 5px;border-radius:6px;'
            + 'display:flex;align-items:center;justify-content:center;font-family:var(--font-ui);font-size:13px;'
            + 'font-weight:600;color:#fff;background:' + (lv.couleur || '#D97706')
            + ';border:1.5px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,0.35)',
          xSt: 'position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);font-size:24px;line-height:1;'
            + 'font-weight:600;color:' + (lv.couleur || '#D97706')
            + ';text-shadow:0 0 3px #fff,0 0 3px #fff,0 0 5px #fff;pointer-events:none',
          pick: () => this.zPatch({ zSel: i })
        }; }),
      lignes: rep.map((r, i) => { const lv = this.zNiveau(r.niveau) || {};
        return { n: r.n, txt: r.txt || '', actif: sel === i,
          niveauNom: court(lv.nom), couleur: lv.couleur || '#D97706',
          vide: !String(r.txt || '').trim(),
          pastilleSt: 'flex:0 0 auto;width:22px;height:22px;border-radius:6px;display:flex;align-items:center;'
            + 'justify-content:center;font-size:12px;font-weight:600;color:#fff;background:' + (lv.couleur || '#D97706'),
          rowSt: 'display:flex;gap:9px;padding:10px 13px;border-bottom:0.5px solid var(--color-border-tertiary);'
            + 'align-items:flex-start;cursor:pointer;'
            + (sel === i ? 'background:rgba(141,29,44,0.045);box-shadow:inset 3px 0 0 var(--color-primary)' : ''),
          pick: () => this.zPatch({ zSel: i }),
          setTxt: e => this.zRepSet(i, 'txt', e.target.value),
          niveaux: this.zNiveaux().map(lv2 => ({ n: lv2.n, nom: court(lv2.nom), couleur: lv2.couleur,
            on: lv2.n === r.niveau, pick: () => this.zRepSet(i, 'niveau', lv2.n) })),
          del: () => this.zRepDel(i) }; }),
      // Récapitulatif par gravité : « 1 majeur, 2 mineurs » se lit d'un coup
      // d'œil et prépare la note.
      bilan: this.zNiveaux().map(lv => ({ nom: court(lv.nom), couleur: lv.couleur,
        n: rep.filter(r => r.niveau === lv.n).length }))
        .filter(b => b.n > 0),
      undo: rep.length ? () => this.zRepDel(rep.length - 1) : null,
      clear: rep.length ? () => this.zPatch({ rep: [], zSel: null, zSaved: false }) : null,
      save: () => this.zSave(),
      compose: rep.some(r => String(r.txt || '').trim()) ? () => this.zCompose() : null,
      // Option « comparer » : la photo de référence en face. Elle n'existe pas
      // encore côté panel — l'écran le dit au lieu de laisser un cadre noir.
      compare: !!dt.zCompare,
      compareGo: () => this.zPatch({ zCompare: !dt.zCompare }),
      photoRef: d.photoRef || null,
      produit: d.produit || '',
      refManque: !d.photoRef,
      refTxt: d.produitId
        ? 'La fiche technique de ce produit ne porte pas de visuel dans le panel.'
        : 'Cette tâche ne porte pas sur un produit précis, et aucune route du panel n’expose de photo de référence par emplacement de comptoir.',
      refBesoin: 'Donnée à obtenir : une image de référence par produit ou par emplacement de comptoir '
        + '(champ photoRef du détail de tâche — rend null aujourd’hui).',
      envoiBesoin: 'Les repères restent dans le cockpit : /consultant/shops/{id}/task-reviews n’accepte que '
        + 'note, conformité et commentaire, sans pièce jointe. Reportez-les dans le commentaire pour que '
        + 'le franchisé les reçoive.'
    };
  }

  /** CA du mois en cours par magasin — via l'API, une seule fois. */
  tkReseau(){
    if (this.D.tkReseau || this._tkRsEnCours) { return; }
    this._tkRsEnCours = true;
    readOne('/exploitation/reseau?periode=mois').then(r => { this._tkRsEnCours = false;
      this.D.tkReseau = r || { etat: 'erreur' }; this.setState({}); });
  }
  /**
   * État de l'assistance IA, lu une fois. La clé n'arrive jamais au navigateur :
   * la route ne rend qu'une empreinte et le modèle retenu.
   */
  iaStatut(){
    if (this.D.iaStatut || this._iaStEnCours) { return; }
    this._iaStEnCours = true;
    readOne('/ia/statut').then(st => { this._iaStEnCours = false;
      this.D.iaStatut = st || { configure: false }; this.setState({}); });
  }
  /** Demande une proposition d'évaluation sur la photo ouverte. */
  ctrlIaProposer(){
    const dt = this.state.ctrlDet;
    if (!dt || dt.iaBusy) { return; }
    this.setState(s => ({ ctrlDet: Object.assign({}, s.ctrlDet, { iaBusy: true, ia: null }) }));
    readOne('/ia/note?shop=' + encodeURIComponent(dt.shopId) + '&task=' + encodeURIComponent(dt.taskId)
      + '&date=' + encodeURIComponent(dt.date))
      .then(r => this.setState(s => (s.ctrlDet && s.ctrlDet.taskId === dt.taskId)
        ? { ctrlDet: Object.assign({}, s.ctrlDet, { iaBusy: false,
            ia: r || { motif: 'assistance injoignable' } }) } : {}));
  }
  /* --- projets (kanban) ---------------------------------------------------------- */
  valsProjets(common, projEff){
    const S = this.state, D = this.D, M = this.M;
    const mkCard = p => { const av = this.avance(p); const lateT = p.taches.filter(t => this.taskState(t) === 'En retard').length;
      const ouvert = !!S.openCards[p.id], st = this.pStatut(p);
      const quote = p.taches.length ? this.budgetTot(p) / p.taches.length : 0;
      const steps = p.jalons.map((j, i) => ({ k: p.id + ':j' + i, type: 'Jalon', nom: j.nom, due: j.cible, done: j.reel, obj: j, ji: i }))
        .concat(p.taches.map(t => ({ k: p.id + ':' + t.id, type: 'Tâche', nom: t.nom, due: t.due, done: t.done, obj: t })))
        .sort((a, b) => a.due < b.due ? -1 : 1);
      const nReste = steps.filter(e => !e.done).length;
      return { nom: p.nom, levs: p.leviers, fin: this.fD(p.fin), av: Math.round(av * 100) + ' %', avSt: 'height:100%;border-radius:999px;background:var(--color-primary);width:' + (av * 100) + '%',
        statut: st, statutSt: this.statutStyle(st),
        alerte: st === 'En retard' ? 'échéance dépassée' : (lateT ? lateT + ' tâche(s) en retard' : false),
        ouvert, rienReste: ouvert && steps.length === 0,
        resteTxt: nReste ? 'reste ' + nReste + (nReste > 1 ? ' étapes' : ' étape') : 'rien en attente',
        cardSt: 'background:var(--color-surface);border:0.5px solid ' + (ouvert ? 'var(--color-primary)' : 'var(--color-border-tertiary)') + ';border-radius:10px;padding:13px;cursor:pointer',
        chevSt: 'margin-left:auto;font-size:11px;line-height:1.4;color:var(--color-text-muted);transition:transform 0.15s;transform:rotate(' + (ouvert ? '180deg' : '0deg') + ')',
        etapes: steps.map(e => { const done = !!e.done, late = !done && e.due < M.TODAY, info = !!S.openInfo[e.k];
          const own = e.type === 'Tâche' ? this.ownerOf(e.obj) : null;
          const rows = [{ k: 'Type', v: e.type }, { k: 'Porteur', v: own ? own.nom + ' — ' + own.type : 'Jalon projet — pilotage CEO' }];
          if (own) rows.push({ k: 'Contact', v: own.email });
          rows.push({ k: 'Échéance', v: this.fD(e.due) + (done ? ' · livrée le ' + this.fD(e.done) : late ? ' · dépassée' : '') });
          rows.push({ k: 'Budget', v: e.obj.budget != null ? this.fE(e.obj.budget) : e.type === 'Tâche' ? this.fE(Math.round(quote)) + ' (quote-part)' : this.fE(this.budgetTot(p)) + ' (budget projet)' });
          rows.push({ k: 'Description', v: e.obj.desc || 'Aucune description saisie pour cette étape.' });
          return { nom: e.nom, meta: (own ? own.nom : 'Jalon') + ' · ' + (done ? 'livrée' : late ? 'en retard' : 'à faire'), due: this.fD(e.due), done, info, rows,
            rowSt: 'display:flex;align-items:flex-start;gap:8px;padding:7px 8px;border-radius:7px;background:' + (done ? 'rgba(45,122,62,0.09)' : late ? 'rgba(141,29,44,0.06)' : 'transparent'),
            boxSt: 'flex:0 0 auto;width:15px;height:15px;margin-top:1px;border-radius:4px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:10px;line-height:1;border:1px solid ' + (done ? '#2d7a3e' : 'var(--color-border-secondary)') + ';background:' + (done ? '#2d7a3e' : 'transparent') + ';color:#fff',
            boxTxt: done ? '✓' : '',
            nomSt: 'font-size:12px;line-height:1.4;' + (done ? 'color:var(--color-text-muted);text-decoration:line-through' : ''),
            dueSt: 'font-weight:500;white-space:nowrap;color:' + (late ? '#8D1D2C' : 'var(--color-text-muted)'),
            iSt: 'flex:0 0 auto;width:15px;height:15px;margin-top:1px;border-radius:999px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:9.5px;font-style:italic;font-weight:600;font-family:var(--font-ui);border:1px solid ' + (info ? 'var(--color-primary)' : 'var(--color-border-secondary)') + ';background:none;color:' + (info ? 'var(--color-primary)' : 'var(--color-text-muted)'),
            infoT: ev => { ev.stopPropagation(); this.setState(s2 => ({ openInfo: Object.assign({}, s2.openInfo, { [e.k]: !s2.openInfo[e.k] }) })); },
            check: ev => { ev.stopPropagation();
              const nv = done ? null : M.TODAY;
              if (e.type === 'Tâche'){ e.obj.done = nv; this.api('PATCH', '/projects/' + p.id + '/tasks/' + e.obj.id, { done: nv }); }
              else { e.obj.reel = nv; this.api('PATCH', '/projects/' + p.id + '/milestones/' + e.ji, { reel: nv }); }
              this.log('Étape', p.nom, 'Étape « ' + e.nom + (done ? ' » rouverte' : ' » cochée comme faite le ' + this.fD(M.TODAY)));
              this.forceUpdate(); } }; }),
        toggle: () => this.setState(s2 => ({ openCards: Object.assign({}, s2.openCards, { [p.id]: !s2.openCards[p.id] }) })),
        open: e => { e.stopPropagation(); this.setState({ openProjId: p.id }); },
        drag: e => { e.dataTransfer.setData('text/plain', p.id); } }; };
    const cols = M.FAMILLES || ['Produits', 'Services', 'Organisation & coûts', 'Développement réseau'];
    common.kanban = cols.map(fa => { const items = projEff.filter(p => this.pFamille(p) === fa);
      return { nom: fa, n: items.length, items: items.map(mkCard),
        drop: e => { e.preventDefault(); const id = e.dataTransfer.getData('text/plain'); const p = D.projects.find(x2 => x2.id === id); if (!p || this.pFamille(p) === fa) return;
          this.setState(s2 => ({ familleOv: Object.assign({}, s2.familleOv, { [id]: fa }) }));
          this.api('PATCH', '/projects/' + id, { famille: fa });
          this.log('Famille', p.nom, 'Famille passée de « ' + this.pFamille(p) + ' » à « ' + fa + ' » (kanban)'); this.notify('« ' + p.nom + ' » → ' + fa); } }; });
  }

  /* --- tâches consultants --------------------------------------------------------- */
  valsTaches(common, flat){
    const S = this.state, D = this.D, M = this.M;
    // --- Tableau de bord du consultant : ce qui l'attend, en cinq tuiles.
    // Une tuile ne s'allume que si elle demande une action — un cadre coloré
    // devant un compteur à zéro use l'attention pour rien, et le jour où un
    // vrai retard apparaît il ne se distingue plus.
    const PT = (D.pwaTasks || {}).totals || {};
    const enRetard = flat.filter(t => !t.t.done && t.t.due < M.TODAY).length;
    const aNoter = flat.filter(t => !!t.t.done && (t.t.note === null || t.t.note === undefined)).length;
    const nProjLate = (D.projects || []).filter(pr => (pr.taches || [])
      .some(t => !t.done && t.due && t.due < M.TODAY)).length;
    const alertes = this.margeAlerts().length;
    const tuile = (cle, n, lib, sous, ecran, urgent) => ({
      cle, n, lib, sous,
      valeur: n == null ? '—' : Math.round(n).toLocaleString('fr-BE'),
      vif: !!n && urgent,
      col: (!!n && urgent) ? 'var(--color-primary)' : (n ? 'var(--color-text)' : 'var(--color-text-muted)'),
      // La tuile emmène AVEC son filtre : arriver sur la liste complète après
      // avoir cliqué « à contrôler » oblige à refaire à la main le tri qu'on
      // vient de demander.
      go: ecran ? () => this.setState(Object.assign({ screen: ecran },
        cle === 'controler' ? { ctrlOnly: 'acontroler', ctrlShop: 'Toutes les boutiques' }
        : cle === 'sansPhoto' ? { ctrlOnly: 'sansphoto', ctrlShop: 'Toutes les boutiques' } : {})) : null });
    // --- P&L court par magasin : où en est chacun sur le mois EN COURS.
    // La source est l'API, pas la caisse en base : celle-ci s'arrête au dernier
    // jour encodé (mesuré : 34 jours de retard), et un mois clos ne répond pas
    // à « où en sont-ils aujourd'hui ».
    this.tkReseau();
    const R = this.D.tkReseau || null;
    common.tkPnlEtat = R ? (R.etat || 'attente') : 'chargement';
    common.tkPnlPeriode = R && R.du ? (this.fD(R.du) + ' → ' + this.fD(R.au)) : '';
    const budgets = (D.budgets || []);
    const objDe = id => { const b = budgets.find(x => String(x.storeId || x.shopId || x.id) === String(id));
      const v = b ? (b.caObjectifMois != null ? b.caObjectifMois : (b.caT != null ? b.caT : null)) : null;
      return (v != null && isFinite(v) && v > 0) ? +v : null; };
    const mags = ((R && R.magasins) || []).slice().sort((a, b) => (b.n || 0) - (a.n || 0));
    const hi = Math.max.apply(null, mags.map(m => m.n || 0).concat([1]));
    common.tkPnl = mags.map(m => {
      const obj = objDe(m.shopId);
      const att = obj ? (m.n || 0) / obj : null;
      return { nom: m.magasin, ca: this.fMt(m.n),
        // Une barre relative au plus gros magasin, faute d'objectif : elle
        // compare les tailles, et l'écran ne prétend pas mesurer une atteinte
        // qui n'existe pas.
        barre: 'display:block;height:6px;border-radius:999px;background:var(--color-primary);width:'
          + Math.max(3, Math.round(100 * (m.n || 0) / hi)) + '%',
        n1: m.n1 ? this.fMt(m.n1) : '—',
        ecart: m.ecart == null ? '' : (m.ecart >= 0 ? '+' : '') + String(m.ecart).replace('.', ',') + ' %',
        ecartCol: m.ecart == null ? 'var(--color-text-muted)' : (m.ecart >= 0 ? '#2d7a3e' : 'var(--color-primary)'),
        tickets: m.tickets ? Math.round(m.tickets).toLocaleString('fr-BE') : '—',
        panier: m.panier ? this.fU(m.panier) : '—',
        obj: obj ? this.fMt(obj) : null,
        att: att == null ? null : this.fP(att, 0),
        attCol: att == null ? '' : (att >= 0.95 ? '#2d7a3e' : att >= 0.8 ? '#B87512' : 'var(--color-primary)') };
    });
    common.tkPnlSansObj = common.tkPnl.filter(x => !x.obj).length;
    common.tkPnlTotal = common.tkPnl.length;

    common.tkTuiles = [
      tuile('controler', PT.aControler || 0, 'À contrôler',
        'tâches photographiées, en attente de note', 'controle', true),
      tuile('sansPhoto', PT.sansPhoto || 0, 'Rendues sans photo',
        'faites, mais rien à juger', 'controle', false),
      tuile('mesTaches', enRetard, 'Mes tâches en retard',
        'échéance dépassée, non rendues', null, true),
      tuile('aNoter', aNoter, 'À noter',
        'mes tâches rendues, pas encore évaluées', null, false),
      tuile('projets', nProjLate, 'Projets en retard',
        'au moins un jalon dépassé', 'projets', true),
      tuile('marge', alertes, 'Alertes marge',
        'ratios au-delà des seuils', 'marge', true)
    ];

    common.tkWho = S.tkWho;
    common.setTkWho = e => this.setState({ tkWho: e.target.value });
    common.tkPeople = [{ val: 'all', nom: 'Tous les intervenants' }]
      .concat(D.consultants.map(c => ({ val: 'c:' + c.id, nom: c.nom + ' — ' + c.role })))
      .concat(D.suppliers.map(s => ({ val: 's:' + s.id, nom: s.nom + ' — Fournisseur' })));
    common.tkStore = S.tkStore;
    common.setTkStore = e => this.setState({ tkStore: e.target.value });
    common.tkStores = [{ val: 'tous', nom: 'Tous les magasins' }, { val: 'reseau', nom: 'Tâches réseau (sans magasin)' }]
      .concat(D.stores.map(s => ({ val: s.id, nom: s.nom })));
    const mine = flat.filter(x => (S.tkWho === 'all' || (x.t.owner.t + ':' + x.t.owner.id) === S.tkWho)
      && (S.tkStore === 'tous' || (S.tkStore === 'reseau' ? !x.t.magasin : x.t.magasin === S.tkStore)));
    const SG = M.SIGNAL || { seuil: 4, niveaux: [], familles: [] };
    const seuil = SG.seuil || 4;
    const niv = n => (SG.niveaux || []).find(l => l.n === n) || { n, nom: n + '/5', couleur: '#666', aide: '' };
    // Une tâche livrée mais pas encore notée attend une décision : c'est le
    // seul état que la case à cocher ne savait pas exprimer.
    const aValider = x => !!x.t.done && (x.t.note === null || x.t.note === undefined);
    const validee = x => x.t.note !== null && x.t.note !== undefined;
    const nValid = mine.filter(validee).length;
    const nAttente = mine.filter(aValider).length;
    const nRetard = mine.filter(x => !x.t.done && x.t.due < M.TODAY).length;
    const notes = mine.filter(validee).map(x => x.t.note);
    const moy = notes.length ? (notes.reduce((a, b) => a + b, 0) / notes.length) : null;
    const nSignal = mine.filter(x => x.t.signalement && x.t.signalement.ouvert).length;
    common.tkResume = mine.length + ' tâches · ' + nAttente + ' à valider · ' + nValid + ' validées'
      + (moy !== null ? ' · note moyenne ' + moy.toFixed(1).replace('.', ',') : '')
      + (nSignal ? ' · ' + nSignal + ' signalement' + (nSignal > 1 ? 's' : '') + ' ouvert' + (nSignal > 1 ? 's' : '') : '')
      + (nRetard ? ' · ' + nRetard + ' en retard' : '');
    common.tkVide = mine.length === 0;
    const ordre = mine.slice().sort((a, b) => (validee(a) ? 1 : 0) - (validee(b) ? 1 : 0) || (a.t.due < b.t.due ? -1 : 1));
    const mk = (x, i) => { const done = !!x.t.done, late = !done && x.t.due < M.TODAY, ouvert = !!S.openInfo['tk:' + x.t.id];
      const crm = D.crm[x.p.id];
      const mag = x.t.magasin ? (D.stores.find(s => s.id === x.t.magasin) || {}).nom : null;
      const note = validee(x) ? x.t.note : null, sig = x.t.signalement;
      const l = note !== null ? niv(note) : null;
      // Le brouillon de la ligne : la note ne part qu'au clic sur « Valider ».
      const d = S.tkVal['tk:' + x.t.id] || { note: note, famille: '', type: '', commentaire: '' };
      const dn = d.note, dl = dn ? niv(dn) : null, sous = !!dn && dn < seuil;
      const fams = SG.familles || [];
      const famCour = d.famille || (fams[0] || {}).nom || '';
      const typs = ((fams.find(f => f.nom === famCour) || {}).types) || [];
      const maj = f => this.setState(s2 => ({ tkVal: Object.assign({}, s2.tkVal, { ['tk:' + x.t.id]: Object.assign({}, d, f) }) }));
      // Le passif de l'intervenant : valider sans lui, c'est valider de mémoire.
      const sien = mine.filter(y => y.o.nom === x.o.nom);
      const sesNotes = sien.filter(validee).map(y => y.t.note);
      const sesSig = sien.filter(y => y.t.signalement && y.t.signalement.ouvert).length;
      return { nom: x.t.nom, qui: x.o.nom, projet: x.p.nom, ouvert, hasMag: !!mag, magasin: mag || '',
        due: note !== null ? 'Validée le ' + this.fD(x.t.done) : done ? (x.t.renduePar ? 'Annoncée le ' : 'Cochée le ') + this.fD(x.t.done)
          : (late ? 'En retard depuis le ' : 'Pour le ') + this.fD(x.t.due),
        rowSt: i === 0 ? '' : 'border-top:0.5px solid var(--color-border-tertiary)',
        nomSt: 'font-size:13px;font-weight:500;line-height:1.4;' + (note !== null ? 'color:var(--color-text-muted)' : ''),
        dueSt: 'flex:0 0 auto;font-size:11.5px;font-weight:500;white-space:nowrap;margin-top:1px;color:' + (late ? '#8D1D2C' : note !== null ? l.couleur : 'var(--color-text-muted)'),
        // La case garde son sens d'origine — « livrée » — et la note s'y ajoute.
        boxSt: 'flex:0 0 auto;width:17px;height:17px;margin-top:1px;border-radius:5px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:11px;line-height:1;border:1px ' + (done && note === null ? 'dashed #8D1D2C' : 'solid ' + (done ? '#2d7a3e' : 'var(--color-border-secondary)')) + ';background:' + (note !== null ? '#2d7a3e' : 'transparent') + ';color:' + (note !== null ? '#fff' : '#8D1D2C'),
        boxTxt: note !== null ? '✓' : done ? '?' : '',
        // La pastille de niveau, sur la ligne repliée.
        hasLvl: note !== null,
        lvlTxt: note !== null ? l.nom : '',
        lvlSt: note !== null ? 'display:inline-flex;align-items:center;gap:5px;font-size:10.5px;font-weight:600;border-radius:999px;padding:2px 9px 2px 4px;white-space:nowrap;background:' + l.couleur + '1f;color:' + l.couleur : '',
        lvlNum: note !== null ? String(note) : '',
        lvlNumSt: note !== null ? 'width:15px;height:15px;border-radius:50%;color:#fff;font-size:9.5px;display:flex;align-items:center;justify-content:center;background:' + l.couleur : '',
        hasSig: !!(sig && sig.ouvert),
        sigTxt: sig && sig.ouvert ? 'Signalement · ' + sig.famille + ' · ' + sig.type : '',
        sigSt: 'display:inline-flex;align-items:center;font-size:10.5px;font-weight:600;border-radius:999px;padding:2px 9px;white-space:nowrap;background:rgba(141,29,44,.10);color:#8D1D2C',
        chevSt: 'flex:0 0 auto;font-size:11px;color:var(--color-text-muted);transition:transform 0.15s;transform:rotate(' + (ouvert ? '180deg' : '0deg') + ')',
        rows: [{ k: 'Attendu', v: x.t.desc || crm && crm.objectif || x.p.valeurTxt || '—' },
          { k: 'Intervenant', v: x.o.nom + ' — ' + x.o.type }, { k: 'Contact', v: x.o.email },
          { k: 'Échéance', v: this.fD(x.t.due) + (done ? ' · rendue le ' + this.fD(x.t.done) : late ? ' · dépassée' : '') },
          { k: 'Budget', v: x.t.budget !== null && x.t.budget !== undefined ? this.fE(x.t.budget) : '—' },
          { k: 'Remise', v: done ? (x.t.renduePar ? 'Annoncée par ' + x.t.renduePar + ' le ' + this.fD(x.t.done) : 'Cochée par la direction le ' + this.fD(x.t.done)) : 'Pas encore annoncée' },
          { k: 'Mot du consultant', v: x.t.noteRemise || '—' },
          { k: 'Relance', v: x.t.relance ? 'Envoyée le ' + this.fD(x.t.relance) : 'Aucune' },
          { k: 'Projet', v: x.p.nom }, { k: 'Magasin', v: mag || 'Réseau — aucun magasin' }],
        histo: [{ k: 'Ce mois', v: sesNotes.length ? sesNotes.length + ' tâche' + (sesNotes.length > 1 ? 's' : '') + ' validée' + (sesNotes.length > 1 ? 's' : '') + ' · note moyenne ' + (sesNotes.reduce((a, b) => a + b, 0) / sesNotes.length).toFixed(1).replace('.', ',') : 'Aucune tâche validée' },
          { k: 'Signalements', v: sesSig ? sesSig + ' ouvert' + (sesSig > 1 ? 's' : '') : 'Aucun ouvert' }],
        // --- le panneau de validation
        vOuvert: done,
        vNote: dn || 0,
        starSt: n => 'border:0;background:none;padding:0 1px;font-size:25px;line-height:1;cursor:pointer;color:' + (dn && n <= dn ? dl.couleur : '#d9d2c8'),
        setNote: n => maj({ note: n === dn ? null : n }),
        hasLvb: !!dn,
        lvbTxt: dl ? dl.nom : '', lvbNum: dn ? String(dn) : '', lvbAide: dl ? dl.aide : '',
        lvbSt: dl ? 'margin-top:7px;border-radius:8px;padding:7px 10px;display:flex;align-items:center;gap:7px;font-size:12.5px;font-weight:600;background:' + dl.couleur + '1f;color:' + dl.couleur : '',
        lvbNumSt: dl ? 'width:19px;height:19px;border-radius:50%;color:#fff;font-style:normal;font-size:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;background:' + dl.couleur : '',
        sousSeuil: sous,
        sousTxt: sous ? dl.nom.replace('Non conforme — ', '') + ' — ' + dn + '/5' : '',
        fams: fams.map(f => f.nom), famCour,
        // Changer de famille remet le type à zéro : « Cuisson » sous « Budget »
        // n'existe pas, et un couple impossible passerait sans bruit.
        setFam: e => maj({ famille: e.target.value, type: '' }),
        typs, typCour: d.type || typs[0] || '',
        setTyp: e => maj({ type: e.target.value }),
        commentaire: d.commentaire || '',
        setCom: e => maj({ commentaire: e.target.value }),
        boutonTxt: sous ? 'Valider et signaler' : 'Valider la tâche',
        peutValider: !!dn && (!sous || (famCour && (d.type || typs[0]))),
        valider: e => { e.stopPropagation();
          if (!dn) { this.notify('Choisissez une note avant de valider'); return; }
          const fa = sous ? famCour : null, ty = sous ? (d.type || typs[0]) : null;
          if (sous && (!fa || !ty)) { this.notify('Famille et type de problème obligatoires'); return; }
          x.t.note = dn; x.t.done = x.t.done || M.TODAY;
          if (sous) { x.t.signalement = { note: dn, famille: fa, type: ty, comment: d.commentaire || null, statut: 'nouveau', ouvert: true, creeLe: M.TODAY, creePar: 'CEO' }; }
          this.api('PATCH', '/projects/' + x.p.id + '/tasks/' + x.t.id,
            { note: dn, famille: fa, type: ty, commentaire: d.commentaire || null, done: x.t.done, par: 'CEO' });
          this.log('Validation', x.p.nom, 'Tâche « ' + x.t.nom + ' » validée ' + dn + '/5 — ' + dl.nom + ' (' + x.o.nom + ')');
          if (sous) { this.log('Signalement', x.p.nom, 'Signalement ouvert sur « ' + x.t.nom + ' » — ' + fa + ' · ' + ty); }
          this.setState(s2 => ({ tkVal: Object.assign({}, s2.tkVal, { ['tk:' + x.t.id]: undefined }),
            openInfo: Object.assign({}, s2.openInfo, { ['tk:' + x.t.id]: false }) }));
          this.notify('« ' + x.t.nom + ' » ' + dn + '/5 — ' + dl.nom); this.forceUpdate(); },
        toggleOpen: () => this.setState(s2 => ({ openInfo: Object.assign({}, s2.openInfo, { ['tk:' + x.t.id]: !s2.openInfo['tk:' + x.t.id] }) })),
        check: e => { e.stopPropagation();
          // La case ne dit plus que « rendue » : dénoter se fait par les étoiles.
          const nv = done ? null : M.TODAY;
          x.t.done = nv; if (nv === null) { x.t.note = null; }
          this.api('PATCH', '/projects/' + x.p.id + '/tasks/' + x.t.id, nv === null ? { done: null, note: null } : { done: nv });
          this.log('Tâche', x.p.nom, 'Tâche « ' + x.t.nom + ' » ' + (done ? 'rouverte' : 'rendue le ' + this.fD(M.TODAY)) + ' (' + x.o.nom + ')');
          this.notify('« ' + x.t.nom + ' » ' + (done ? 'rouverte' : 'rendue')); this.forceUpdate(); } }; };
    const grp = [['En retard', '#8D1D2C', x => !x.t.done && x.t.due < M.TODAY],
      ['À valider', '#8D1D2C', aValider],
      ['À faire', '#8a5a13', x => !x.t.done && x.t.due >= M.TODAY],
      ['Validées', '#2d7a3e', validee]];
    common.tkGroups = grp.map(([nom, couleur, f]) => { const items = ordre.filter(f);
      return { nom, couleur, n: items.length, items: items.map((x, i) => mk(x, i)),
        dotSt: 'width:6px;height:6px;border-radius:999px;background:' + couleur }; }).filter(g => g.n > 0);
  }

  /* --- suivi des tâches ------------------------------------------------------------- */
  /**
   * Ce qui a été validé sur la période, et les signalements à traiter.
   *
   * Les chiffres viennent du serveur (`/taches/suivi`) plutôt que d'un calcul
   * sur les projets déjà chargés : le même calcul doit servir au rapport
   * hebdomadaire et au rapport mensuel, et deux implémentations d'une même
   * moyenne finissent par ne plus donner le même nombre.
   */
  valsSuivi(common){
    const S = this.state, D = this.D, M = this.M;
    const SG = M.SIGNAL || { seuil: 4, niveaux: [] };
    const niv = n => (SG.niveaux || []).find(l => l.n === n) || { n, nom: n + '/5', couleur: '#666' };
    const nomDe = o => { if (!o) return '—';
      const l = (o.t === 'c' ? D.consultants : D.suppliers) || [];
      return (l.find(x => x.id === o.id) || {}).nom || o.id; };

    common.suPeriode = S.suiviPeriode;
    common.suOnglets = [['semaine', 'Semaine'], ['mois', 'Mois']].map(([v, nom]) => ({
      nom, actif: S.suiviPeriode === v,
      go: () => { this.setState({ suiviPeriode: v, suiviData: null }); this.chargerSuivi(v); },
      st: 'border:0.5px solid ' + (S.suiviPeriode === v ? 'var(--color-primary)' : 'var(--color-border-secondary)')
        + ';background:' + (S.suiviPeriode === v ? 'var(--color-primary)' : 'transparent')
        + ';color:' + (S.suiviPeriode === v ? '#fff' : 'var(--color-text)')
        + ';border-radius:999px;padding:5px 14px;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer' }));

    const d = S.suiviData;
    if (d === null) { this.chargerSuivi(S.suiviPeriode); common.suChargement = true; return; }
    common.suChargement = false;
    common.suVide = d.validees === 0 && d.signalements.length === 0;

    const lib = S.suiviPeriode === 'semaine' ? '7 derniers jours' : '30 derniers jours';
    common.suTuiles = [
      { k: 'Validées', v: String(d.validees), s: lib, c: 'var(--color-text)' },
      { k: 'Note moyenne', v: d.moyenne === null ? '—' : String(d.moyenne).replace('.', ','), s: 'sur 5', c: d.moyenne === null ? 'var(--color-text-muted)' : niv(Math.round(d.moyenne)).couleur },
      { k: 'À traiter', v: String(d.ouverts), s: 'signalements ouverts', c: d.ouverts ? '#8D1D2C' : '#2d7a3e' },
      { k: 'Traités', v: String(d.traites), s: lib, c: '#2d7a3e' }
    ].map(t => ({ ...t, vSt: 'font-size:26px;font-weight:500;line-height:1.1;color:' + t.c }));

    // La répartition : une moyenne de 4,0 peut cacher deux 5 et deux 3.
    const tot = Object.values(d.repartition).reduce((a, b) => a + b, 0) || 1;
    common.suBarres = [5, 4, 3, 2, 1].map(n => { const l = niv(n), v = d.repartition[n] || 0;
      return { nom: l.nom, n: String(v), pct: Math.round(v / tot * 100),
        barSt: 'height:7px;border-radius:999px;background:' + l.couleur + ';width:' + Math.round(v / tot * 100) + '%',
        libSt: 'font-size:11.5px;font-weight:500;color:' + l.couleur }; });

    common.suGens = d.parIntervenant.map(x => ({
      nom: nomDe(x.owner), validees: String(x.validees),
      moyenne: x.moyenne === null ? '—' : String(x.moyenne).replace('.', ','),
      moySt: 'font-size:12px;font-weight:500;color:' + (x.moyenne === null ? 'var(--color-text-muted)' : niv(Math.round(x.moyenne)).couleur),
      ouverts: x.ouverts ? String(x.ouverts) : '—',
      ouvSt: 'font-size:12px;font-weight:500;color:' + (x.ouverts ? '#8D1D2C' : 'var(--color-text-muted)')
    })).sort((a, b) => Number(b.ouverts === '—' ? 0 : b.ouverts) - Number(a.ouverts === '—' ? 0 : a.ouverts));

    const jours = iso => { if (!iso) return ''; const j = Math.floor((new Date(M.TODAY) - new Date(iso.slice(0, 10))) / 86400000);
      return j <= 0 ? "aujourd'hui" : j === 1 ? 'hier' : 'depuis ' + j + ' jours'; };

    common.suSignalements = d.signalements.map(g => { const l = niv(g.note);
      const brouillon = S.suiviNote['sg:' + g.id] || '';
      const majNote = e => this.setState(s2 => ({ suiviNote: Object.assign({}, s2.suiviNote, { ['sg:' + g.id]: e.target.value }) }));
      const acte = (statut, exigeNote) => () => {
        if (exigeNote && brouillon.trim() === '') { this.notify('Dites ce qui a été fait avant de clore'); return; }
        this.api('PATCH', '/task-issues/' + g.id, { statut, commentaire: brouillon, par: 'CEO' });
        this.log('Signalement', g.projet, 'Signalement sur « ' + g.tache + ' » '
          + ({ nouveau: 'rouvert', vu: 'marqué vu', traite: 'traité' })[statut]
          + (brouillon ? ' — ' + brouillon : ''));
        this.notify('Signalement ' + ({ nouveau: 'rouvert', vu: 'marqué vu', traite: 'traité' })[statut]);
        this.setState(s2 => ({ suiviNote: Object.assign({}, s2.suiviNote, { ['sg:' + g.id]: '' }), suiviData: null }));
        this.chargerSuivi(S.suiviPeriode);
      };
      const etat = g.ouvert ? (g.statut === 'vu' ? 'Vu' : 'Nouveau') : 'Traité';
      const etatC = g.ouvert ? (g.statut === 'vu' ? '#8a5a13' : '#8D1D2C') : '#2d7a3e';
      return {
        tache: g.tache, projet: g.projet, qui: nomDe(g.owner),
        quoi: g.famille + ' · ' + g.type,
        commentaire: (g.comment || '').trim(),
        aCommentaire: (g.comment || '').trim() !== '',
        age: jours(g.creeLe), ouvert: g.ouvert,
        lvlTxt: l.nom, lvlNum: String(g.note),
        lvlSt: 'display:inline-flex;align-items:center;gap:5px;font-size:10.5px;font-weight:600;border-radius:999px;padding:2px 9px 2px 4px;white-space:nowrap;background:' + l.couleur + '1f;color:' + l.couleur,
        lvlNumSt: 'width:15px;height:15px;border-radius:50%;color:#fff;font-size:9.5px;display:flex;align-items:center;justify-content:center;background:' + l.couleur,
        etat, etatSt: 'font-size:10.5px;font-weight:600;border-radius:999px;padding:2px 9px;background:' + etatC + '1f;color:' + etatC,
        rowSt: 'display:flex;gap:12px;padding:12px 16px;border-bottom:0.5px solid var(--color-border-tertiary)'
          + (g.ouvert ? ';border-left:3px solid ' + l.couleur : ''),
        note: brouillon, majNote,
        vu: acte('vu', false), traiter: acte('traite', true), rouvrir: acte('nouveau', false)
      }; });
  }

  /** Recharge le suivi sans rejouer tout le chargement du cockpit. */
  chargerSuivi(periode){
    if (this._suiviEnCours === periode) { return; }
    this._suiviEnCours = periode;
    readOne('/taches/suivi?periode=' + encodeURIComponent(periode)).then(d => {
      this._suiviEnCours = null;
      // Une réponse en retard ne doit pas écraser une autre période.
      if (this.state.suiviPeriode !== periode) { return; }
      this.setState({ suiviData: d || { periode, validees: 0, moyenne: null, repartition: {}, ouverts: 0, traites: 0, signalements: [], parIntervenant: [], taches: [] } });
    });
  }

  /* --- reporting -------------------------------------------------------------------- */
  valsReporting(common, navDef, titles){
    const S = this.state, D = this.D, M = this.M;
    const consultLev = c => { const ls = []; for (const p of D.projects) if (p.taches.some(t => t.owner.t === 'c' && t.owner.id === c.id && !t.done)) p.leviers.forEach(l => ls.indexOf(l) < 0 && ls.push(l)); return ls; };
    common.distRows = D.consultants.map(c => { const st = [...new Set(c.visites.map(v => v.store.split(' — ')[0]))];
      const levs = consultLev(c).map(sl => { const l = M.LEVIERS.find(x2 => x2.slug === sl); return { id: sl, nom: l ? l.nom : sl }; });
      return { nom: c.nom + ' — ' + c.role, stores: st.length ? st.join(' · ') : 'Aucun magasin visité',
        leviers: levs.length ? levs : [{ id: 'xp', nom: 'Aucun levier ouvert' }],
        send: () => { this.log('Rapport', '—', 'Rapport district envoyé à ' + c.nom + ' (' + c.email + ') — ' + st.length + ' magasin(s), ' + levs.length + ' levier(s)');
          this.notify('Rapport district envoyé à ' + c.nom); } }; });
    // Statut d'ouverture des plans par le franchisé : pas de source réelle en
    // base (le panel ne l'expose pas) → état neutre, aucune donnée inventée.
    // Lien de plan : base d'URL du panel (réglage pwaBase), période dérivée de
    // la date du jour — plus de « 2026s2 » ni de statut fabriqué.
    const basePlan = ((this.D.pwaReports || {}).base || '').replace(/\/$/, '');
    const _t = new Date(M.TODAY), _ok = !isNaN(_t);
    const sem = (_ok ? _t.getMonth() : this.moisIdx()) < 6 ? 's1' : 's2';
    const yr = _ok ? _t.getFullYear() : this.exo();
    common.dlRows = this.open().map((s) => {
      const url = (basePlan || 'plan') + '/plan/' + s.nom.split(' — ')[0].toLowerCase().replace(/[^a-z]/g, '') + '-' + yr + sem;
      return { store: s.nom, etat: 'Statut non suivi', etatCol: 'var(--color-text-muted)', url,
        copy: () => { navigator.clipboard && navigator.clipboard.writeText('https://' + url); this.notify('Lien copié — ' + s.nom); },
        relance: () => { this.log('Relance', '—', 'Direct Link plan d’action relancé — ' + s.nom); this.notify('Relance envoyée au franchisé — ' + s.nom); } }; });
    const pById = id => D.people.find(p => p.id === id);
    common.repPeople = D.people.map(p => ({ val: p.id, nom: p.nom + ' — ' + p.role + (p.email ? '' : ' (adresse manquante)') }));
    // Ordre FIGÉ, indépendant du menu : `postes_json` stocke « p1 », « p3 »…
    // Tant que la numérotation venait de l'index dans navDef, ajouter un écran
    // au milieu redirigeait silencieusement tous les rapports enregistrés vers
    // d'autres écrans. Un nouvel écran s'ajoute EN FIN de liste, jamais ailleurs.
    const POSTES = ['taches', 'magasins', 'heatmap', 'objectifs', 'budget', 'encodage',
      'marge', 'produits', 'projets', 'reporting', 'journal', 'parametres', 'suivi'];
    const labelDe = sid => (navDef.flatMap(g => g[1]).find(it => it[0] === sid) || [sid, sid])[1];
    const posteDefs = POSTES.map((sid, i) => ({ id: 'p' + (i + 1), tag: 'P' + (i + 1), sid, label: labelDe(sid) }));
    // La base d'URL est celle d'où l'application est servie : un domaine écrit
    // en dur donne un lien mort dès que le cockpit change d'adresse.
    const baseCockpit = (typeof location !== 'undefined' ? location.origin + location.pathname.replace(/\/[^/]*$/, '') : '');
    const repUrl = (r, sel, email) => baseCockpit + '/rapports/rapport.html?id=' + r.id + '&postes=' + sel.join(',') + '&periode={AAAA-MM}&dest=' + (email || '') + '&format=pdf';
    const isOn = r => S.alertOn['rep:' + r.id] != null ? S.alertOn['rep:' + r.id] : r.actif;
    const fFreq = S.repFFreq || 'Toutes les fréquences', fEtat = S.repFEtat || 'tous', fType = S.repFType || 'Tous les types';
    common.repFFreq = fFreq; common.setRepFFreq = e => this.setState({ repFFreq: e.target.value });
    common.repFFreqOpts = ['Toutes les fréquences', 'Hebdomadaire', 'Mensuel', 'Trimestriel', 'Annuel'];
    common.repFType = fType; common.setRepFType = e => this.setState({ repFType: e.target.value });
    common.repFTypeOpts = ['Tous les types'].concat(M.REPORT_TYPES || []);
    const etatBtn = (v, nom) => ({ nom, st: 'border:none;cursor:pointer;font-family:var(--font-ui);font-size:11.5px;font-weight:500;padding:6px 13px;' + (fEtat === v ? 'background:var(--color-primary);color:#fff' : 'background:var(--color-surface);color:var(--color-text-muted)'),
      go: () => this.setState({ repFEtat: v }) });
    common.repEtatBtns = [etatBtn('tous', 'Tous'), etatBtn('actif', 'Actifs'), etatBtn('inactif', 'Inactifs')];
    const repList = D.reports.filter(r => (fFreq === 'Toutes les fréquences' || (S.repFreq[r.id] || r.freq) === fFreq)
      && (fType === 'Tous les types' || r.type === fType)
      && (fEtat === 'tous' || (fEtat === 'actif' ? isOn(r) : !isOn(r))));
    common.repCount = repList.length + ' rapport' + (repList.length > 1 ? 's' : '') + ' sur ' + D.reports.length
      + ' · ' + D.reports.filter(isOn).length + ' actifs';
    common.repVide = repList.length === 0;
    common.repRows = repList.map(r => { const dest = S.repDest[r.id] != null ? S.repDest[r.id] : r.destId; const cc = S.repCc[r.id] != null ? S.repCc[r.id] : r.ccId;
      const pd = pById(dest), pc = pById(cc);
      const sel = S.repPostes[r.id] || r.postes;
      const url = repUrl(r, sel, pd && pd.email);
      const on = isOn(r);
      return { nom: r.nom, desc: r.desc, dest, cc: cc || '', destEmail: pd ? (pd.email || 'adresse manquante') : '', ccEmail: pc ? (pc.email || 'adresse manquante') : '', destSt: 'font-size:10.5px;white-space:nowrap;color:' + (pd && !pd.email ? '#8D1D2C' : 'var(--color-text-muted)'), ccSt: 'font-size:10.5px;white-space:nowrap;color:' + (pc && !pc.email ? '#8D1D2C' : 'var(--color-text-muted)'), dernier: this.fDA(r.dernier), freq: S.repFreq[r.id] || r.freq,
        type: r.type || '—', actif: on, actifTxt: on ? 'Actif' : 'Inactif',
        actifSt: 'display:inline-block;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:500;cursor:pointer;' + (on ? 'background:rgba(45,122,62,0.10);color:#2d7a3e' : 'background:var(--color-background-secondary);color:var(--color-text-muted)'),
        toggleActif: () => { this.setState(s2 => ({ alertOn: Object.assign({}, s2.alertOn, { ['rep:' + r.id]: !on }) }));
          this.api('PATCH', '/reporting/reports/' + r.id, { actif: !on });
          this.notify('« ' + r.nom + ' » ' + (on ? 'désactivé' : 'activé')); },
        url,
        postes: posteDefs.map(p => { const on2 = sel.includes(p.id);
          return { tag: p.tag, label: p.label, on: on2,
            st: 'border-radius:999px;padding:3px 8px;font-family:var(--font-ui);font-size:10px;font-weight:600;cursor:pointer;' + (on2 ? 'border:0.5px solid var(--color-primary);background:var(--color-primary);color:#fff' : 'border:0.5px solid var(--color-border-secondary);background:transparent;color:var(--color-text-muted)'),
            toggle: () => { const next = on2 ? sel.filter(x2 => x2 !== p.id) : posteDefs.filter(q => sel.includes(q.id) || q.id === p.id).map(q => q.id);
              this.setState(s2 => ({ repPostes: Object.assign({}, s2.repPostes, { [r.id]: next }) }));
              this.api('PATCH', '/reporting/reports/' + r.id, { postes: next }); } }; }),
        copy: () => { try { navigator.clipboard.writeText(url); } catch (e) {} this.notify('URL du rapport copiée'); },
        prev: () => this.setState({ repPrev: r.id, repPrevTab: 'pdf' }),
        setDest: e => { this.setState(s2 => ({ repDest: Object.assign({}, s2.repDest, { [r.id]: e.target.value }) })); this.api('PATCH', '/reporting/reports/' + r.id, { destId: e.target.value }); },
        setCc: e => { this.setState(s2 => ({ repCc: Object.assign({}, s2.repCc, { [r.id]: e.target.value }) })); this.api('PATCH', '/reporting/reports/' + r.id, { ccId: e.target.value }); },
        setFreq: e => { this.setState(s2 => ({ repFreq: Object.assign({}, s2.repFreq, { [r.id]: e.target.value }) })); this.api('PATCH', '/reporting/reports/' + r.id, { freq: e.target.value }); this.notify('Fréquence de « ' + r.nom + ' » : ' + e.target.value); },
        gen: () => { this.api('POST', '/reporting/reports/' + r.id + '/send', { journal: 'Rapport « ' + r.nom + ' » généré manuellement (PDF)' }); this.log('Rapport', '—', 'Rapport « ' + r.nom + ' » généré manuellement (PDF)'); this.notify('PDF généré — « ' + r.nom + ' »'); },
        send: () => { const to = pd ? pd.nom + ' <' + pd.email + '>' : '—'; const ccTxt = pc ? pc.nom + ' <' + pc.email + '>' : '';
          const jr = 'Rapport « ' + r.nom + ' » envoyé à : ' + to + (ccTxt ? ' — copie : ' + ccTxt : '');
          this.api('POST', '/reporting/reports/' + r.id + '/send', { journal: jr });
          this.log('Rapport', '—', jr);
          this.notify('Rapport envoyé à ' + (pd ? pd.nom : '—') + (pc ? ' (cc ' + pc.nom + ')' : '')); } }; });
    common.alertRows = D.alertRules.map(a => ({ nom: a.nom, canal: a.canal, actif: S.alertOn[a.id] != null ? S.alertOn[a.id] : a.actif,
      toggle: e => { const on = e.target.checked; this.setState(s2 => ({ alertOn: Object.assign({}, s2.alertOn, { [a.id]: on }) }));
        this.api('PATCH', '/reporting/alerts/' + a.id, { actif: on });
        this.notify('Alerte « ' + a.nom + ' » ' + (on ? 'activée' : 'désactivée')); } }));
    common.repPrevClose = () => this.setState({ repPrev: null });
    const rp = S.repPrev && D.reports.find(r => r.id === S.repPrev);
    common.repPrevTabPdf = () => this.setState({ repPrevTab: 'pdf' });
    common.repPrevTabCode = () => this.setState({ repPrevTab: 'code' });
    if (rp){ const dest = S.repDest[rp.id] != null ? S.repDest[rp.id] : rp.destId; const cc = S.repCc[rp.id] != null ? S.repCc[rp.id] : rp.ccId;
      const pd = pById(dest), pc = pById(cc); const sel = S.repPostes[rp.id] || rp.postes;
      const secs = posteDefs.filter(p => sel.includes(p.id)).map(p => ({ tag: p.tag, label: p.label, desc: (titles[p.sid] && titles[p.sid][1]) || '' }));
      const tab = S.repPrevTab || 'pdf';
      const tabSt = on => 'border:none;cursor:pointer;font-family:var(--font-ui);font-size:11.5px;font-weight:500;padding:6px 14px;' + (on ? 'background:var(--color-primary);color:#fff' : 'background:transparent;color:var(--color-text-muted)');
      const htmlCode = ['<!DOCTYPE html>', '<html lang="fr">', '<head>', '  <meta charset="utf-8">', '  <title>' + rp.nom + ' — {periode}</title>', '  <link rel="stylesheet" href="rapport.css">', '</head>', '<body>', '  <header class="entete">', '    <span class="marque">L’Atelier by</span>', '    <span class="periode">Rapport automatique · {periode}</span>', '  </header>', '  <h1>' + rp.nom + '</h1>', '  <p class="destinataires">À : {dest} · Cc : {cc} · Fréquence : ' + (S.repFreq[rp.id] || rp.freq) + '</p>']
        .concat(secs.flatMap(s => ['  <section class="poste" data-poste="' + s.tag.toLowerCase() + '">', '    <h2><span class="tag">' + s.tag + '</span> ' + s.label + '</h2>', '    <p class="desc">' + s.desc + '</p>', '    <div class="donnees" data-source="cockpit/' + s.tag.toLowerCase() + '" data-periode="{periode}">', '      <!-- tableaux & graphes du poste, injectés à la génération -->', '    </div>', '  </section>']))
        .concat(['  <footer>Généré le {date_generation} — cockpit L’Atelier by</footer>', '</body>', '</html>']).join('\n');
      const cssCode = ['/* rapport.css — mise en forme PDF (A4, Chromium headless) */', '@page { size: A4 portrait; margin: 20mm 18mm; }', 'body { font-family: "Gotham", sans-serif; color: #222222; font-size: 11pt; line-height: 1.5; margin: 0; }', '', '.entete { display: flex; justify-content: space-between; align-items: baseline;', '  border-bottom: 1.5pt solid #222222; padding-bottom: 8pt; }', '.entete .marque { font-size: 14pt; font-weight: 500; }', '.entete .periode { font-size: 8pt; color: #666666; text-transform: uppercase; letter-spacing: 0.08em; }', '', 'h1 { font-size: 20pt; font-weight: 500; margin: 18pt 0 4pt; }', '.destinataires { font-size: 9pt; color: #666666; margin: 0 0 16pt; }', '', '.poste { margin-top: 14pt; break-inside: avoid; }', '.poste h2 { font-size: 12pt; font-weight: 600; border-bottom: 0.5pt solid #dddddd;', '  padding-bottom: 4pt; margin: 0 0 6pt; }', '.poste .tag { color: #8D1D2C; font-size: 8pt; font-weight: 600; margin-right: 6pt; }', '.poste .desc { font-size: 9pt; color: #555555; margin: 0 0 8pt; }', '.poste .donnees table { width: 100%; border-collapse: collapse; font-size: 9pt; }', '.poste .donnees th, .poste .donnees td { border-bottom: 0.5pt solid #eeeeee;', '  padding: 4pt 6pt; text-align: left; }', '', 'footer { position: running(footer); font-size: 7.5pt; color: #999999;', '  border-top: 0.5pt solid #dddddd; padding-top: 6pt; margin-top: 20pt; }'].join('\n');
      common.repPrev = { nom: rp.nom, freq: S.repFreq[rp.id] || rp.freq, url: repUrl(rp, sel, pd && pd.email),
        periodeLabel: this.moisLabel() + ' ' + this.exo(), dateGenLabel: this.fDA(M.TODAY),
        to: pd ? pd.nom + ' <' + pd.email + '>' : '—', ccTxt: pc ? ' · Cc : ' + pc.nom + ' <' + pc.email + '>' : '',
        isPdf: tab === 'pdf', isCode: tab === 'code', tabPdfSt: tabSt(tab === 'pdf'), tabCodeSt: tabSt(tab === 'code'),
        htmlCode, cssCode,
        copyHtml: () => { try { navigator.clipboard.writeText(htmlCode); } catch (e) {} this.notify('Structure HTML copiée'); },
        copyCss: () => { try { navigator.clipboard.writeText(cssCode); } catch (e) {} this.notify('CSS copié'); },
        sections: secs };
    } else common.repPrev = null;
    common.eqRep = null; common.eqClose = () => {};

    // --- rapports du panel consultant (pwa_consultant) : générer + récupérer
    const pwa = D.pwaReports || { base: '', magasins: [], partages: [] };
    common.pwaHasBase = !!pwa.base;
    common.pwaBase = pwa.base || 'Base d’URL du panel non configurée (paramètre pwaBase)';
    const [pwaKind, pwaPer] = (S.pwaType || 'gestion:month').split(':');
    common.pwaTypes = [
      { val: 'gestion:week', nom: 'Gestion — hebdomadaire' }, { val: 'gestion:month', nom: 'Gestion — mensuel' },
      { val: 'checklist:week', nom: 'Checklist tâches — semaine' }, { val: 'checklist:month', nom: 'Checklist tâches — mois' }];
    common.pwaType = S.pwaType;
    common.setPwaType = e => { const v = e.target.value;
      // la checklist n'existe que par boutique : quitter « Réseau » si besoin
      this.setState(s2 => ({ pwaType: v, pwaScope: v.startsWith('checklist') && s2.pwaScope === 'all' ? ((pwa.magasins[0] || {}).id || 'all') : s2.pwaScope })); };
    const magOk = pwa.magasins.filter(m => m.pwaId != null);
    common.pwaScopes = (pwaKind === 'gestion' ? [{ val: 'all', nom: 'Réseau — toutes les boutiques' }] : [])
      .concat(magOk.map(m => ({ val: m.id, nom: m.nom })));
    common.pwaScope = S.pwaScope;
    common.setPwaScope = e => this.setState({ pwaScope: e.target.value });
    const pwaMag = magOk.find(m => m.id === S.pwaScope);
    const pwaScopeArg = S.pwaScope === 'all' ? 'all' : (pwaMag ? String(pwaMag.pwaId) : 'all');
    const pwaPath = pwaKind === 'gestion'
      ? '/reports/view?type=' + pwaPer + '&scope=' + pwaScopeArg
      : '/reports/checklist/' + pwaPer + '?scope=' + pwaScopeArg;
    const pwaUrl = (pwa.base || '') + pwaPath;
    common.pwaUrl = pwaUrl;
    const pwaTypeNom = (common.pwaTypes.find(t => t.val === S.pwaType) || {}).nom || '';
    const pwaScopeNom = S.pwaScope === 'all' ? 'Réseau' : (pwaMag ? pwaMag.nom : '');
    common.pwaNote = 'Le rapport est construit par le panel à l’ouverture (imprimable / « Enregistrer en PDF »). '
      + 'Les liens figés ci-contre sont ceux partagés par les consultants — récupérés de mac_report_share, avec ouvertures et expiration.';
    common.pwaGen = () => { if (!pwa.base){ this.notify('Configurez la base d’URL du panel (paramètre pwaBase).'); return; }
      window.open(pwaUrl, '_blank', 'noopener');
      this.log('Rapport', '—', 'Rapport du panel consultant généré — ' + pwaTypeNom + ' · ' + pwaScopeNom);
      this.notify('Rapport du panel ouvert — ' + pwaTypeNom); };
    common.pwaCopy = () => { try { navigator.clipboard.writeText(pwaUrl); } catch (e) {} this.notify('URL du rapport du panel copiée'); };
    const etatCl = { 'Actif': ['rgba(45,122,62,0.10)', '#2d7a3e'], 'Expiré': ['rgba(193,122,42,0.16)', '#8a5a13'], 'Révoqué': ['rgba(141,29,44,0.10)', '#8D1D2C'] };
    common.pwaShares = (pwa.partages || []).map(p => { const cl = etatCl[p.etat] || etatCl['Expiré'];
      return { label: p.label, magasin: p.magasin, ym: p.ym, consultant: p.consultant || '—', url: p.url,
        meta: (p.consultant ? p.consultant + ' · ' : '') + 'créé le ' + this.fD(p.cree) + ' · expire le ' + this.fD(p.expire)
          + ' · ' + p.opens + ' ouverture' + (p.opens > 1 ? 's' : '') + (p.derniereOuverture ? ' (dern. ' + this.fD(p.derniereOuverture) + ')' : ''),
        etat: p.etat, etatSt: 'display:inline-block;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:500;white-space:nowrap;background:' + cl[0] + ';color:' + cl[1],
        actif: p.etat === 'Actif',
        open: () => { window.open(p.url, '_blank', 'noopener');
          this.log('Rapport', '—', 'Rapport partagé du panel ouvert — ' + p.label); },
        copy: () => { try { navigator.clipboard.writeText(p.url); } catch (e) {} this.notify('Lien de partage copié — ' + p.magasin); } }; });
    common.pwaSharesVide = common.pwaShares.length === 0;
  }

  /* --- journal ------------------------------------------------------------------------ */
  valsJournal(common){
    const S = this.state, D = this.D;
    const all = S.logsExtra.concat(D.logs);
    common.logTypes = ['Tous les types'].concat([...new Set(all.map(l => l.type))]);
    common.logQuis = ['Tous les auteurs'].concat([...new Set(all.map(l => l.qui))]);
    common.logType = S.logType; common.logQui = S.logQui; common.logQ = S.logQ;
    common.setLogType = e => this.setState({ logType: e.target.value });
    common.setLogQui = e => this.setState({ logQui: e.target.value });
    common.setLogQ = e => this.setState({ logQ: e.target.value });
    const q = S.logQ.toLowerCase();
    const rows = all.filter(l => (S.logType === 'Tous les types' || l.type === S.logType) && (S.logQui === 'Tous les auteurs' || l.qui === S.logQui) && (!q || (l.msg + ' ' + l.projet).toLowerCase().includes(q)));
    const tCl = { 'Alerte': ['rgba(141,29,44,0.12)', '#8D1D2C'], 'Relance': ['rgba(99,102,241,0.12)', '#4649c4'], 'Statut': ['rgba(193,122,42,0.16)', '#8a5a13'], 'Rapport': ['rgba(45,122,62,0.10)', '#2d7a3e'] };
    common.logRows = rows.map(l => { const c = tCl[l.type] || ['var(--color-background-secondary)', '#666666'];
      return { ts: l.ts, qui: l.qui, type: l.type, projet: l.projet, msg: l.msg, typeSt: 'display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:500;background:' + c[0] + ';color:' + c[1] }; });
    common.exportCsv = () => { const csv = 'horodatage;auteur;type;projet;evenement\n' + rows.map(l => [l.ts, l.qui, l.type, l.projet, '"' + l.msg.replace(/"/g, '""') + '"'].join(';')).join('\n');
      const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' })); a.download = 'journal-atelier-by.csv'; a.click();
      this.notify('Journal exporté — ' + rows.length + ' événements (CSV)'); };
  }

  /* --- compte consultant de l'API panel (panneau utilisateur) ------------------ */
  valsCompteApi(common){
    const S = this.state, D = this.D;
    // --- Compte consultant utilisé pour l'API du panel (noms de tâches, photos,
    //     notation). Le mot de passe n'est jamais relu : on n'affiche que son
    //     existence, et le laisser vide conserve celui déjà enregistré.
    const pa = S.paCompte || {};
    const paSt = D.pwaCompte || { base: '', phone: '', motDePasseDefini: false, configure: false };
    common.paBase = pa.base != null ? pa.base : (paSt.base || '');
    common.paPhone = pa.phone != null ? pa.phone : (paSt.phone || '');
    common.paPass = pa.password || '';
    common.paPassPlaceholder = paSt.motDePasseDefini ? '•••••••• (inchangé)' : 'Mot de passe du compte';
    common.paEtat = paSt.configure ? 'Compte configuré' : 'Compte non configuré';
    common.paEtatCourt = paSt.configure ? '' : 'compte API à configurer';
    common.paEtatSt = 'display:inline-block;padding:3px 10px;border-radius:999px;font-size:11.5px;font-weight:500;'
      + (paSt.configure ? 'background:rgba(45,122,62,0.12);color:#2d7a3e' : 'background:rgba(193,122,42,0.16);color:#8a5a13');
    common.paMsg = pa.msg || '';
    common.paMsgSt = 'margin-top:10px;font-size:12px;font-weight:500;color:' + (pa.ok ? '#2d7a3e' : '#8D1D2C');
    common.paBusy = !!pa.busy;
    const paSet = k => e => { const v = e.target.value; this.setState(s2 => ({ paCompte: Object.assign({}, s2.paCompte, { [k]: v }) })); };
    common.setPaBase = paSet('base'); common.setPaPhone = paSet('phone'); common.setPaPass = paSet('password');
    const paRefresh = () => readOne('/pwa/compte').then(st => { if (st) this.D.pwaCompte = st; this.setState({}); });
    common.paSave = () => {
      const p = this.state.paCompte || {};
      this.setState(s2 => ({ paCompte: Object.assign({}, s2.paCompte, { busy: true, msg: '' }) }));
      fetch(this.apiBase() + '/pwa/compte', { method: 'PUT', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ base: p.base || '', phone: p.phone || '', password: p.password || '' }) })
        .then(r => r.json())
        .then(r => { this.setState(s2 => ({ paCompte: Object.assign({}, s2.paCompte, { busy: false, password: '',
            ok: !!r.testOk, msg: r.message || (r.testOk ? 'Connexion réussie.' : 'Enregistré.') }) }));
          this.log('Paramètre', null, 'Compte consultant de l’API panel mis à jour');
          return paRefresh(); })
        .catch(() => this.setState(s2 => ({ paCompte: Object.assign({}, s2.paCompte, { busy: false, ok: false, msg: 'Échec de l’enregistrement.' }) })));
    };
    common.paTest = () => {
      this.setState(s2 => ({ paCompte: Object.assign({}, s2.paCompte, { busy: true, msg: '' }) }));
      fetch(this.apiBase() + '/pwa/compte/test', { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(r => r.json())
        .then(r => this.setState(s2 => ({ paCompte: Object.assign({}, s2.paCompte, { busy: false, ok: !!r.ok, msg: r.message || '' }) })))
        .catch(() => this.setState(s2 => ({ paCompte: Object.assign({}, s2.paCompte, { busy: false, ok: false, msg: 'Test impossible.' }) })));
    };
  }

  /* --- paramètres ---------------------------------------------------------------------- */
  valsParams(common){
    const S = this.state, D = this.D, M = this.M;
    common.paramExo = String(this.meta.exercice);
    // --- Scoring produits : pondération des 4 critères, seuils de verdict et
    //     échelle absolue de la marge nette. Écran dédié (sous-menu Paramètres).
    const scd = S.scDraft || {};
    const SCc = this.scoringCfg();
    const scv = (k, def) => scd[k] != null ? scd[k] : String(def);
    const scSet = k => e => { const v = e.target.value; this.setState(s2 => ({ scDraft: Object.assign({}, s2.scDraft, { [k]: v }) })); };
    const scNum = (v, d) => { const n = parseFloat(String(v).replace(',', '.')); return isFinite(n) && n >= 0 ? n : d; };
    const pv = scNum(scv('volume', SCc.v), SCc.v), pm = scNum(scv('marge', SCc.m), SCc.m);
    const pp = scNum(scv('perte', SCc.perte), SCc.perte), pc = scNum(scv('comptoir', SCc.comptoir), SCc.comptoir);
    const somme = pv + pm + pp + pc;
    common.scVolume = scv('volume', SCc.v); common.scMarge = scv('marge', SCc.m);
    common.scPerte = scv('perte', SCc.perte); common.scComptoir = scv('comptoir', SCc.comptoir);
    common.scMoteur = scv('moteur', SCc.moteur); common.scConforter = scv('conforter', SCc.conforter);
    common.scMBas = scv('mBas', SCc.mBas); common.scMBasNote = scv('mBasNote', SCc.mBasNote);
    common.scMHaut = scv('mHaut', SCc.mHaut); common.scMHautNote = scv('mHautNote', SCc.mHautNote);
    common.setScVolume = scSet('volume'); common.setScMarge = scSet('marge');
    common.setScPerte = scSet('perte'); common.setScComptoir = scSet('comptoir');
    common.setScMoteur = scSet('moteur'); common.setScConforter = scSet('conforter');
    common.setScMBas = scSet('mBas'); common.setScMBasNote = scSet('mBasNote');
    common.setScMHaut = scSet('mHaut'); common.setScMHautNote = scSet('mHautNote');
    // Les poids sont relatifs : montrer la part effective, pour que « 40 » ne se
    // lise pas « 40 % » quand la somme ne fait pas 100.
    const part = w => somme > 0 ? Math.round(100 * w / somme) + ' %' : '—';
    common.scCriteres = [
      { k: 'volume', nom: 'Volume de ventes', aide: 'Médiane des 6 dernières semaines, par période.', val: common.scVolume, set: common.setScVolume, part: part(pv) },
      { k: 'marge', nom: 'Marge nette', aide: 'Prix de vente moins matière et coût main d’œuvre.', val: common.scMarge, set: common.setScMarge, part: part(pm) },
      { k: 'perte', nom: 'Taux de perte', aide: 'Pénalise les produits jetés en fin de journée.', val: common.scPerte, set: common.setScPerte, part: part(pp) },
      { k: 'comptoir', nom: 'Présence au comptoir', aide: 'Poids donné au rôle d’image du produit.', val: common.scComptoir, set: common.setScComptoir, part: part(pc) },
    ];
    const sMot = scNum(scv('moteur', SCc.moteur), SCc.moteur), sCon = scNum(scv('conforter', SCc.conforter), SCc.conforter);
    const mb = scNum(scv('mBas', SCc.mBas), SCc.mBas), mh = scNum(scv('mHaut', SCc.mHaut), SCc.mHaut);
    const mbn = scNum(scv('mBasNote', SCc.mBasNote), SCc.mBasNote), mhn = scNum(scv('mHautNote', SCc.mHautNote), SCc.mHautNote);
    common.scAlerte = somme <= 0 ? 'Somme des poids nulle : le score ne peut pas être calculé.'
      : (sCon >= sMot ? 'Le seuil « à conforter » doit rester sous le seuil « moteur de gamme ».'
      : (sMot > 100 || sCon < 0 ? 'Les seuils s’expriment sur une échelle de 0 à 100.'
      : (mh <= mb ? 'La borne haute de marge doit être supérieure à la borne basse.' : '')));
    common.scMargeApercu = 'Marge ' + mb + ' % → ' + mbn + ' pts · ' + mh + ' % → ' + mhn + ' pts (linéaire entre les deux, plafonné au-delà).';
    common.scMsg = scd.msg || '';
    common.scMsgSt = 'margin-top:10px;font-size:12px;font-weight:500;color:' + (scd.ok ? '#2d7a3e' : '#8D1D2C');
    common.scSave = () => {
      if (common.scAlerte) { this.notify(common.scAlerte); return; }
      const val = { poids: { volume: pv, marge: pm, perte: pp, comptoir: pc },
        seuils: { moteur: sMot, conforter: sCon },
        marge: { bas: mb, basNote: mbn, haut: mh, hautNote: mhn } };
      this.api('PUT', '/parametres/scoring', { valeur: val }).then(r => {
        if (r && r.ok === false) { this.setState(s2 => ({ scDraft: Object.assign({}, s2.scDraft, { ok: false, msg: r.error || 'Échec' }) })); return; }
        this.meta.scoring = val;
        this.setState(s2 => ({ scDraft: Object.assign({}, s2.scDraft, { ok: true, msg: 'Pondération enregistrée — le scoring est recalculé.' }) }));
        this.log('Paramètre', null, 'Scoring produits : poids ' + pv + '/' + pm + '/' + pp + '/' + pc + ', seuils ' + sMot + ' / ' + sCon);
      });
    };
    common.scReset = () => this.setState({ scDraft: {} });
    // Comptes RÉELS (plus de « 4 consultants · 10 magasins · 12 zones » inventés).
    const nCons = (D.consultants || []).length, nFour = (D.suppliers || []).length, nPers = (D.people || []).length;
    common.paramIntervenants = [nCons + ' consultant' + (nCons > 1 ? 's' : ''),
      nFour + ' fournisseur' + (nFour > 1 ? 's' : ''), nPers + ' interne' + (nPers > 1 ? 's' : '')].join(' · ');
    const zones = [...new Set((D.stores || []).map(s2 => s2.zone).filter(Boolean))].length;
    const nMag = (D.stores || []).length;
    common.paramMagasins = nMag + ' magasin' + (nMag > 1 ? 's' : '') + ' · ' + zones + ' zone' + (zones > 1 ? 's' : '');
    const s = this.seuils();
    common.paramLeviers = M.LEVIERS;
    const ax = S.tplAxe || common.npAxes[0]; const tpl = (ax && D.projTemplates[ax]) || { jalons: [], couts: [] };
    common.tplAxe = ax || ''; common.setTplAxe = e => this.setState({ tplAxe: e.target.value });
    const persistTpl = () => this.api('PUT', '/parametres/template-' + encodeURIComponent(ax), { jalons: tpl.jalons, couts: tpl.couts });
    const mut = fn => { fn(); persistTpl(); this.forceUpdate(); };
    common.tplJalons = tpl.jalons.map((j, i) => ({ nom: j.nom, j: j.j,
      setNom: e => mut(() => { j.nom = e.target.value; }), setJ: e => mut(() => { j.j = Math.min(0, +e.target.value || 0); }),
      del: () => mut(() => tpl.jalons.splice(i, 1)) }));
    common.tplJalAdd = () => mut(() => tpl.jalons.push({ nom: '', j: -30 }));
    common.tplCouts = tpl.couts.map((c, i) => ({ poste: c.poste, prevu: c.prevu,
      setPoste: e => mut(() => { c.poste = e.target.value; }), setPrevu: e => mut(() => { c.prevu = +e.target.value || 0; }),
      del: () => mut(() => tpl.couts.splice(i, 1)) }));
    common.tplCoutAdd = () => mut(() => tpl.couts.push({ poste: '', prevu: 0 }));
    common.sFoodVal = s.f; common.sLabourVal = s.l;
    common.setSFood = e => { this.setState({ sFood: e.target.value }); this.api('PUT', '/parametres/seuil-food', { valeur: +e.target.value }); };
    common.setSLabour = e => { this.setState({ sLabour: e.target.value }); this.api('PUT', '/parametres/seuil-labour', { valeur: +e.target.value }); };
    common.paramTpls = D.emailTemplates.map(t => ({ nom: t.nom, sujet: t.sujet, corps: S.tpl[t.id] != null ? S.tpl[t.id] : t.corps,
      set: e => { this.setState(s2 => ({ tpl: Object.assign({}, s2.tpl, { [t.id]: e.target.value }) })); this.api('PUT', '/parametres/email-' + t.id, { corps: e.target.value }); } }));
  }
}

const app = new App(document.getElementById('app'));
app.start();
