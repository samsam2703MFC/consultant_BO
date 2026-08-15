/* Cockpit CEO — logique applicative.
 * Port fidèle de la classe Component du prototype Design Component :
 * même état, mêmes calculs, mêmes libellés. Rendu : templates.js (HTML string
 * + délégation d'événements), données : api.js (REST, repli vide hors-ligne).
 * Chaque mutation est répercutée sur l'API quand elle est joignable (source === 'api').
 */
import { load, write, authStatus, authSubmit, authLogout } from './api.js';
import { render as tplRender } from './templates.js';

function escHtml(v){
  return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

class App {
  constructor(root){
    this.root = root;
    this.state = { ready: false, screen: 'taches', bStore: 'cha', tkStore: 'tous', openProjId: null,
      sortKey: 'caPct', sortDir: -1, zoneF: 'Toutes les zones', hmMetric: 'pct', hmYear: 2026, hmHover: null,
      horizon: 'h1', logType: 'Tous les types', logQui: 'Tous les auteurs', logQ: '', rel: null, toast: null,
      sFood: null, sLabour: null, statutOv: {}, familleOv: {}, relanced: {}, logsExtra: [], tpl: {},
      repFreq: {}, repDest: {}, repCc: {}, repPostes: {}, repPrev: null, repPrevTab: 'pdf', alertOn: {},
      np: null, nt: null, encStore: 'cha', encDraft: {}, openCards: {}, openInfo: {}, tkWho: 'all', tkOv: {},
      // Brouillon de validation par tâche : { note, famille, type, commentaire }.
      // Il ne part qu'au clic sur « Valider » — une étoile touchée par erreur
      // ne doit pas clôturer une tâche.
      tkVal: {},
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
      esc: escHtml
    };
    const common = this.renderVals();
    const active = document.activeElement;
    const focusId = active && active.id ? active.id : null;
    const selStart = focusId && active.selectionStart != null ? active.selectionStart : null;
    const mainEl = document.getElementById('main-scroll');
    const scrollTop = mainEl ? mainEl.scrollTop : 0;
    this.root.innerHTML = tplRender(common, x);
    const main2 = document.getElementById('main-scroll');
    if (main2) main2.scrollTop = scrollTop;
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
  api(method, path, payload){ return write(this.source, method, path, payload); }
  notify(msg){ clearTimeout(this._tt); this.setState({ toast: msg }); this._tt = setTimeout(() => this.setState({ toast: null }), 3600); }
  log(type, projet, msg){
    const ts = (this.M ? this.M.TODAY : '2026-07-31') + ' ' + new Date().toTimeString().slice(0, 5);
    this.api('POST', '/journal', { ts: ts + ':00', qui: 'CEO', type, projet: projet || '—', msg });
    this.setState(s => ({ logsExtra: [{ ts, qui: 'CEO', type, projet: projet || '—', msg }, ...s.logsExtra] }));
  }
  fE(n){ return n == null ? '—' : Math.round(n).toLocaleString('fr-BE') + ' €'; }
  fK(n){ return n == null ? '—' : Math.round(n / 1000).toLocaleString('fr-BE') + ' k€'; }
  fM(n){ return (n == null || !isFinite(n)) ? '—' : (n / 1e6).toFixed(1).replace('.', ',') + ' M€'; }
  fP(x, d){ return (x == null || !isFinite(x)) ? '—' : (x * 100).toFixed(d == null ? 1 : d).replace('.', ',') + ' %'; }
  fD(d){ return d ? d.slice(8, 10) + '/' + d.slice(5, 7) : '—'; }
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
  sum(y, m, f){ return this.open().reduce((a, s) => a + (s.perf[y][m][f] || 0), 0); }
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
  poids(){ return { v: 40, m: 40, pos: 20 }; }
  seuilCaEtp(){ const d = (this.meta && this.meta.seuils) || {}; return d.caEtp != null ? +d.caEtp : 13000; }
  seuils(){ const d = (this.meta && this.meta.seuils) || {};
    return { f: this.state.sFood != null ? +this.state.sFood : d.food,
      l: this.state.sLabour != null ? +this.state.sLabour : d.labour, o: d.overhead }; }
  margeAlerts(){ const s = this.seuils(); const out = [];
    for (const st of this.open()){ const r = st.perf[2026][6];
      if (r.food > s.f) out.push({ store: st.nom, lev: 'food-cost', levNom: 'Food Cost', msg: 'food-cost ' + String(r.food).replace('.', ',') + ' % (seuil ' + s.f + ' %)', action: 'Revoir fiches techniques, contrôle réception ProdAtelier et gestion casse.' });
      if (r.labour > s.l) out.push({ store: st.nom, lev: 'labour-cost', levNom: 'Labour Cost', msg: 'labour-cost ' + String(r.labour).replace('.', ',') + ' % (seuil ' + s.l + ' %)', action: 'Adapter les plannings au flux, suivre le ratio CA/ETP par tranche horaire.' });
      const etpN = Math.max(3, Math.round(r.ca / 14200)), ce = r.ca / etpN, sEtp = this.seuilCaEtp();
      if (ce < sEtp) out.push({ store: st.nom, lev: 'labour-cost', levNom: 'Labour Cost', msg: 'CA/ETP ' + this.fE(ce) + ' sous le minimum de ' + this.fE(sEtp), action: 'Revoir le dimensionnement d’équipe et la productivité horaire.' });
      if (r.overhead > s.o) out.push({ store: st.nom, lev: 'overhead-cost', levNom: 'Overhead Cost', msg: 'overhead ' + String(r.overhead).replace('.', ',') + ' % (seuil ' + String(s.o).replace('.', ',') + ' %)', action: 'Auditer loyer, énergies et abonnements ; renégocier les contrats.' }); }
    return out; }
  openRelTask(x){ const tpl = this.state.tpl; const late = x.st === 'En retard'; const base = this.D.emailTemplates[late ? 1 : 0]; const corps = (tpl[base.id] || base.corps);
    const sub = s => s.replace('{tache}', x.t.nom).replace('{projet}', x.p.nom).replace('{echeance}', this.fD(x.t.due) + '/2026').replace('{destinataire}', x.o.nom).replace('{zone}', '');
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
    const titles = { taches: ['Tâches consultants', 'Cochez une tâche rendue, ouvrez la ligne pour la noter de 1 à 5. Sous 4, la validation ouvre un signalement.'], magasins: ['Tableau des magasins', 'Marge, valeur, CA, tickets et panier moyen par magasin — juillet 2026 vs N-1 et vs cibles.'], heatmap: ['Heatmap mensuelle', 'Une ligne par magasin, une colonne par mois. Repérez d’un coup d’œil les sur- et sous-performances.'], budget: ['Suivi budget — magasin', 'Budget validé par le consultant contre réel encodé chaque mois, poste par poste.'], encodage: ['Encodage du budget', 'Saisie du budget annuel d’un magasin : CA mensuel, engagement panier, étude de marché et répartition des charges.'], objectifs: ['Objectifs de CA', 'Cibles par magasin et consolidées réseau, sur 3 horizons : 1 an, 3 ans et 5 ans.'], marge: ['Marge & maîtrise des coûts', 'Marge nette des franchisés et ratios food / labour / overhead, avec alertes par levier.'], projets: ['Projets', 'Suivi des projets de développement : statuts, rétroplanning, coûts, leviers et ROI.'], reporting: ['Reporting automatisé', 'Rapports récurrents générés et envoyés par email (PDF), alertes push paramétrables.'], journal: ['Journal', 'Traçabilité intégrale : chaque action est horodatée avec son auteur. Filtrable et exportable.'], produits: ['Scoring produits', 'Volume, taux de marge et position dans la catégorie : un score unique par référence pour arbitrer la gamme.'], parametres: ['Paramètres', 'Leviers, seuils, modèles d’email, utilisateurs, magasins, zones et intégration TFB.'] };
    common.screenTitle = titles[S.screen][0]; common.screenSub = titles[S.screen][1];
    const mt = this.meta || {};
    common.metaDate = mt.dateLabel || ''; common.metaPeriode = mt.periodeLabel || '';
    common.brandNom = (mt.reseau || {}).nom || ''; common.brandSub = (mt.reseau || {}).sousTitre || '';
    const usr = mt.utilisateur || {};
    common.userInit = usr.initiales || ''; common.userNom = usr.nom || ''; common.userRole = usr.role || '';
    common.canLogout = this.source === 'api';
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
    common.ntOpen = () => this.setState({ nt: { step: 1, reached: 1, projet: D.projects[0].id, nom: '', magasin: '', who: 'c:mj', due: '2026-10-31', col: 'À faire' } });
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
    common.npOpen = () => this.setState({ np: { step: 1, reached: 1, nom: '', lev: 'trafic', axe: 'Ventes', prio: 'Moyenne', debut: M.TODAY, fin: '2026-12-31', valeur: '', valeurTxt: '', kpi: '',
      jalons: [{ nom: '', cible: '2026-12-31' }], taches: [{ nom: '', who: 'c:mj', due: '2026-10-31' }], couts: [{ poste: 'Jours-homme consultants', prevu: '6000' }] } });
    common.npClose = () => this.setState({ np: null });
    const npSet = k => e => this.setState(s2 => ({ np: Object.assign({}, s2.np, { [k]: e.target.value }) }));
    common.npNom = npSet('nom'); common.npLev = npSet('lev'); common.npAxe = npSet('axe'); common.npDebut = npSet('debut'); common.npFin = npSet('fin');
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
      common.npJalAdd = npAdd('jalons', { nom: '', cible: f.fin }); common.npTacheAdd = npAdd('taches', { nom: '', who: 'c:mj', due: f.fin }); common.npCoutAdd = npAdd('couts', { poste: '', prevu: '0' });
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
      ['Performance & marge', [['magasins', 'Tableau des magasins', 0], ['heatmap', 'Heatmap mensuelle', 0], ['objectifs', 'Objectifs de CA', 0], ['budget', 'Suivi budget magasin', 0], ['encodage', 'Encodage du budget', 0], ['marge', 'Marge & coûts', this.margeAlerts().length], ['produits', 'Scoring produits', 0]]],
      ['Projets & contrôle', [['projets', 'Projets', nLate]]],
      ['Administration', [['reporting', 'Reporting', 0], ['journal', 'Journal', 0], ['parametres', 'Paramètres', 0]]]];
    common.nav = navDef.map(g => ({ titre: g[0], items: g[1].map(it => ({ id: it[0], label: it[1], badge: it[2] || false, go: goTo(it[0]),
      st: 'display:flex;align-items:center;justify-content:space-between;gap:8px;width:100%;text-align:left;border:none;cursor:pointer;font-family:var(--font-ui);font-size:13px;padding:8px 10px;border-radius:8px;' + (S.screen === it[0] ? 'background:rgba(141,29,44,0.08);color:var(--color-primary);font-weight:500' : 'background:transparent;color:var(--color-text)') })) }));

    ['isBudget', 'isEncodage', 'isMagasins', 'isHeatmap', 'isObjectifs', 'isMarge', 'isProjets', 'isReporting', 'isJournal', 'isParams', 'isTaches', 'isProduits'].forEach(k => common[k] = false);
    const key = { budget: 'isBudget', encodage: 'isEncodage', taches: 'isTaches', magasins: 'isMagasins', heatmap: 'isHeatmap', objectifs: 'isObjectifs', marge: 'isMarge', produits: 'isProduits', projets: 'isProjets', reporting: 'isReporting', journal: 'isJournal', parametres: 'isParams' }[S.screen];
    common[key] = true;

    // --- magasins
    if (common.isMagasins){
      common.zoneF = S.zoneF; common.setZoneF = e => this.setState({ zoneF: e.target.value });
      common.zoneOptions = ['Toutes les zones'].concat([...new Set(this.open().map(s => s.zone))]);
      const rows = this.open().filter(s => S.zoneF === 'Toutes les zones' || s.zone === S.zoneF).map(s => {
        const r = s.perf[2026][6], n1 = s.perf[2025][6];
        return { s, nom: s.nom, code: s.code, fr: s.fr, _marge: r.marge, _margeVar: r.marge / n1.marge - 1, _val: r.val, _valPct: r.val / s.valT, _ca: r.ca, _caPct: r.ca / r.caT, _tickets: r.tickets, _tick: r.tickets / n1.tickets - 1, _panier: r.panier, _pan: r.panier / n1.panier - 1, _margeN1: n1.marge }; });
      const sk = S.sortKey, dir = S.sortDir;
      rows.sort((a, b) => { const va = a['_' + sk] != null ? a['_' + sk] : a[sk], vb = b['_' + sk] != null ? b['_' + sk] : b[sk]; return (va < vb ? -1 : va > vb ? 1 : 0) * dir; });
      const colDefs = [['nom', 'Magasin', 'left'], ['marge', 'Marge juil.', 'right'], ['margeN1', 'Marge N-1', 'right'], ['margeVar', 'Var.', 'right'], ['val', 'Valeur / cible', 'right'], ['valPct', '% réussite', 'center'], ['ca', 'CA / cible', 'right'], ['caPct', '% atteinte', 'center'], ['tickets', 'Tickets', 'right'], ['panier', 'Panier moyen', 'right']];
      common.storeCols = colDefs.map(c => ({ label: c[1], arrow: sk === c[0] ? (dir > 0 ? ' ↑' : ' ↓') : '', sort: () => this.setState({ sortKey: c[0], sortDir: sk === c[0] ? -dir : -1 }),
        st: 'text-align:' + c[2] + ';font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.05em;color:var(--color-text-muted);padding:12px;border-bottom:0.5px solid var(--color-border-tertiary);cursor:pointer;white-space:nowrap;user-select:none' }));
      common.storeRows = rows.map(r => { const tv = this.trend(1 + r._margeVar, 1), te = this.trend(1 + r._tick, 1), pe = this.trend(1 + r._pan, 1);
        return { nom: r.nom, code: r.code, fr: r.fr, marge: this.fE(r._marge), margeN1: this.fE(r._margeN1), margeVar: tv.txt, margeVarSt: tv.st,
          val: this.fK(r._val), valT: this.fK(r.s.valT), valPct: this.fP(r._valPct, 0), valPctSt: this.pill(r._valPct + 0.08),
          ca: this.fK(r._ca), caT: this.fK(r.s.perf[2026][6].caT), caPct: this.fP(r._caPct, 0), caPctSt: this.pill(r._caPct),
          tickets: r._tickets != null ? r._tickets.toLocaleString('fr-BE') : '—', tickEvo: te.txt + ' vs N-1', tickEvoSt: te.st + ';font-size:10.5px',
          panier: r._panier != null ? r._panier.toFixed(2).replace('.', ',') + ' €' : '—', panEvo: pe.txt + ' vs N-1', panEvoSt: pe.st + ';font-size:10.5px' }; });
    }

    // --- heatmap
    if (common.isHeatmap){
      const year = S.hmYear, metric = year === 2025 ? 'ca' : S.hmMetric;
      const tb = act => 'border:none;cursor:pointer;font-family:var(--font-ui);font-size:12px;font-weight:500;padding:7px 14px;' + (act ? 'background:var(--color-primary);color:#fff' : 'background:var(--color-surface);color:var(--color-text-muted)');
      common.hmBtnCaSt = tb(metric === 'ca'); common.hmBtnPctSt = tb(metric === 'pct'); common.hmBtn25St = tb(year === 2025); common.hmBtn26St = tb(year === 2026);
      common.hmMetricCa = () => this.setState({ hmMetric: 'ca' }); common.hmMetricPct = () => this.setState({ hmMetric: 'pct' });
      common.hmY25 = () => this.setState({ hmYear: 2025 }); common.hmY26 = () => this.setState({ hmYear: 2026 });
      common.hmNote = year === 2025 ? 'Année 2025 : CA constaté (pas d’objectif défini).' : (metric === 'pct' ? 'Cellules colorées selon le % d’atteinte de l’objectif mensuel du magasin.' : 'Cellules colorées du CA le plus faible au plus élevé.');
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
      common.hz1 = () => this.setState({ horizon: 'h1' }); common.hz3 = () => this.setState({ horizon: 'h3' }); common.hz5 = () => this.setState({ horizon: 'h5' });
      common.hz1St = this.tabBtn(hz === 'h1'); common.hz3St = this.tabBtn(hz === 'h3'); common.hz5St = this.tabBtn(hz === 'h5');
      common.isH1 = hz === 'h1'; common.isH35 = hz !== 'h1'; common.hmMois = M.MOIS;
      if (hz === 'h1'){
        let reelT = 0, prorataT = 0, cibleT = 0;
        const rows = this.open().map(s => { const cible = s.perf[2026].reduce((a, r) => a + r.caT, 0);
          let reel = 0, pro = 0; for (let m = 0; m <= 6; m++){ reel += s.perf[2026][m].ca; pro += s.perf[2026][m].caT; }
          reelT += reel; prorataT += pro; cibleT += cible; const att = reel / pro; const t = this.trend(att, 1);
          return { nom: s.nom, cible: this.fK(cible), reel: this.fK(reel), prorata: this.fK(pro), ecart: t.txt, ecartSt: t.st, att: this.fP(att, 0), attSt: this.pill(att), _att: att,
            goBudget: () => this.setState({ bStore: s.id, screen: 'budget' }) }; });
        rows.sort((a, b) => b._att - a._att);
        common.objRows = rows;
        common.objCible = this.fM(((((D.targets || {}).ca) || {}).h1 || {}).cible || 0); common.objReel = this.fM(reelT); common.objProrata = this.fM(prorataT);
        const att = reelT / prorataT; common.objAtt = this.fP(att); common.objAttSt = 'font-weight:700;color:' + (att >= 1 ? '#2d7a3e' : att >= 0.92 ? '#8a5a13' : '#8D1D2C');
        let resteCible = 0; for (let m = 7; m < 12; m++) resteCible += this.sum(2026, m, 'caT');
        common.objProj = this.fM(reelT + resteCible * att + (this.meta.contribOuverture || 0));
        const cumR = [], cumC = []; let ar = 0, ac = 0;
        for (let m = 0; m < 12; m++){ ac += this.sum(2026, m, 'caT'); cumC.push(ac); if (m <= 6){ ar += this.sum(2026, m, 'ca'); cumR.push(ar); } }
        const mxv = ac; const px = m => 20 + m * (620 / 11); const py = v => 195 - v / mxv * 175;
        common.trajCible = cumC.map((v, m) => px(m).toFixed(0) + ',' + py(v).toFixed(0)).join(' ');
        common.trajReel = cumR.map((v, m) => px(m).toFixed(0) + ',' + py(v).toFixed(0)).join(' ');
        const budAn = this.open().reduce((a, s) => a + s.perf[2026].reduce((b, r) => b + (r.caT || 0), 0), 0);
        common.cumBudget = this.fM(budAn); common.cumReel = this.fM(reelT);
        const bMois = ((D.budgets || [])[0] || {}).moisEncodes;
        common.cumLabel = 'Budget validé ' + this.meta.exercice + ' — ' + this.open().length + ' magasins';
        common.cumReelLabel = 'Réel encodé' + (bMois ? ' (' + bMois + ' mois)' : '');
        common.objNote = (this.meta.notes || {}).objectifsOuverture || '';
        const ec = reelT - prorataT;
        common.cumEcart = (ec >= 0 ? '+' : '−') + this.fK(Math.abs(ec)) + ' (' + (ec >= 0 ? '+' : '−') + this.fP(Math.abs(reelT / prorataT - 1)) + ')';
        common.cumEcartSt = 'font-weight:500;color:' + (ec >= 0 ? '#2d7a3e' : '#8D1D2C');
        const sous = this.open().filter(s => { let r = 0, p = 0; for (let m = 0; m <= 6; m++){ r += s.perf[2026][m].ca; p += s.perf[2026][m].caT; } return r < p; }).length;
        common.cumSous = sous + ' / ' + this.open().length;
      } else {
        const cfg = (((D.targets || {}).ca) || {})[hz] || { an: this.meta.exercice, cible: 0 };
        const exp = (((D.targets || {}).expansion) || {})[hz] || { an: this.meta.exercice, cible: 1, reel: 0 };
        let run = 0; for (let m = 0; m <= 6; m++) run += this.sum(2026, m, 'ca'); run = run / 7 * 12;
        const nOuv = (exp.cible || 1) - 1; const contrib = nOuv * ((D.targets || {}).caMoyenOuverture || 0);
        const lfl = cfg.cible - run - contrib;
        common.hzAn = String(cfg.an); common.hzCible = this.fM(cfg.cible); common.hzRunrate = this.fM(run);
        common.hzGap = '+' + this.fM(cfg.cible - run); common.hzOuv = nOuv + ' points de vente'; common.hzContrib = 'env. ' + this.fM(contrib);
        common.hzLfl = '+' + this.fM(Math.max(0, lfl)) + ' à trouver';
        const mkBar = (v, cl) => 'height:100%;border-radius:5px;background:' + cl + ';width:' + Math.min(100, v / cfg.cible * 100).toFixed(1) + '%';
        common.hzBars = [{ label: 'CA actuel (run-rate 9 magasins)', val: this.fM(run), st: mkBar(run, 'var(--color-primary)') },
          { label: '+ Contribution des ' + nOuv + ' ouvertures prévues', val: this.fM(contrib), st: mkBar(contrib, 'var(--color-secondary)') },
          { label: '+ Croissance à périmètre constant requise', val: this.fM(Math.max(0, lfl)), st: mkBar(Math.max(0, lfl), '#c9a06a') }];
        common.hzNote = (cfg.note || '').replace('{ouvertures}', nOuv).replace('{caMoyen}', this.fK((D.targets || {}).caMoyenOuverture || 0));
      }
    }

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
    // --- tâches consultants
    if (common.isTaches) this.valsTaches(common, flat);
    // --- reporting
    if (common.isReporting) this.valsReporting(common, navDef, titles);
    // --- journal
    if (common.isJournal) this.valsJournal(common);
    // --- paramètres
    if (common.isParams) this.valsParams(common);

    // --- fiche projet
    const opP = S.openProjId && D.projects.find(p => p.id === S.openProjId);
    if (opP){
      const st = this.pStatut(opP); const c = this.cout(opP); const bt = this.budgetTot(opP); const dep = c - opP.budget;
      const v = opP.valeurReal || opP.valeurEst; const roi = v ? v - c : null; const av = this.avance(opP);
      common.op = { nom: opP.nom, statut: st, prio: opP.prio, debut: this.fD(opP.debut) + '/26', fin: this.fD(opP.fin) + '/26',
        av: Math.round(av * 100) + ' %', axes: opP.axes.join(' · '), valeur: v ? this.fK(v) : 'étude', valeurTxt: opP.valeurTxt,
        levs: opP.leviers.map(sl => M.LEVIERS.find(l => l.slug === sl)),
        setStatut: e => { const ns = e.target.value; this.setState(s2 => ({ statutOv: Object.assign({}, s2.statutOv, { [opP.id]: ns }) }));
          this.api('PATCH', '/projects/' + opP.id, { statut: ns });
          this.log('Statut', opP.nom, 'Statut passé de « ' + st + ' » à « ' + ns + ' » (fiche projet)'); this.notify('Statut de « ' + opP.nom + ' » : ' + ns); },
        jalons: opP.jalons.map(j => { const done = !!j.reel; const late = !done && j.cible < M.TODAY; const wasLate = done && j.reel > j.cible;
          return { nom: j.nom, cible: this.fD(j.cible) + '/26',
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
    common.bEncodes = (bud.moisEncodes || 0) + ' / ' + (bud.moisTotal || 12);
    common.bDernier = bud.dernierEncodage ? this.fD(bud.dernierEncodage) + '/' + bud.dernierEncodage.slice(0, 4) : '—';
    common.bStore = st.id;
    common.setBStore = e => this.setState({ bStore: e.target.value });
    common.bStoreOpts = this.open().map(x => ({ id: x.id, nom: x.nom }));
    common.bMeta = st.code + ' · ' + st.zone + ' · franchisé ' + st.fr;
    const budgetAn = P.reduce((a, r) => a + (r.caT || 0), 0);
    let reel = 0, pro = 0; for (let m = 0; m <= 6; m++){ reel += P[m].ca; pro += P[m].caT; }
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
      reelC = 0; proC = 0; for (let m = 0; m <= 6; m++){ reelC += Pc[m].ca; proC += Pc[m].caT; }
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
      let r7 = 0, b7 = 0; for (let m = 0; m <= 6; m++){ r7 += x2.P[m].ca; b7 += x2.P[m].caT; }
      const t7 = x2.theoM ? x2.theoM.slice(0, 7).reduce((a, v) => a + v, 0) : null;
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
      valeur: val('ca' + i, Math.round(P[i].caT)), set: set('ca' + i),
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
      });
      this.log('Budget', st.nom, jr);
      this.notify('Budget enregistré — ' + st.nom);
    };
    common.encNote = 'À l’enregistrement, la série validée devient le budget de référence du magasin et la série théorique alimente le CA d’étude de marché : elles servent de référence au suivi mensuel et au calcul des écarts. Le CA théorique et l’étude de marché restent indépendants du budget négocié avec ' + st.fr + '.';
  }

  /* --- scoring produits -------------------------------------------------------- */
  valsProduits(common){
    const S = this.state, D = this.D;
    const W = this.poids(); const wt = (W.v + W.m + W.pos) || 1;
    common.pdPond = 'volume ' + Math.round(100 * W.v / wt) + ' · marge ' + Math.round(100 * W.m / wt) + ' · position ' + Math.round(100 * W.pos / wt);
    const nbOuv = (D.stores || []).filter(s => s.status === 'Ouvert').length || 1;
    const base = (D.products || []).map(p => { const mu = p.prix - p.coutUnit;
      return { nom: p.nom, cat: p.categorie, vol: p.volume, prix: p.prix, tend: p.tendVol, mu, mp: mu / p.prix,
        ca: p.volume * p.prix, mg: p.volume * mu, mags: p.magasins, pen: (p.magasins || 0) / nbOuv }; });
    const maxVol = Math.max.apply(null, base.map(p => p.vol));
    const mps = base.map(p => p.mp), maxMp = Math.max.apply(null, mps), minMp = Math.min.apply(null, mps);
    const cats = {}; base.forEach(p => { (cats[p.cat] = cats[p.cat] || []).push(p); });
    Object.keys(cats).forEach(c2 => { const g = cats[c2].slice().sort((a, b) => b.ca - a.ca); const tot = g.reduce((a, x2) => a + x2.ca, 0);
      g.forEach((p, i) => { p.rang = i + 1; p.nbCat = g.length; p.partCat = p.ca / tot; }); });
    base.forEach(p => { p.sVol = 100 * Math.sqrt(p.vol / maxVol);
      p.sMg = 100 * (p.mp - minMp) / ((maxMp - minMp) || 1);
      p.sPos = 100 * (p.nbCat - p.rang + 1) / p.nbCat;
      p.score = (W.v * p.sVol + W.m * p.sMg + W.pos * p.sPos) / wt; });
    const verdict = s => s >= 68 ? ['Moteur de gamme', '#2d7a3e', 'rgba(45,122,62,0.12)'] : s >= 46 ? ['À conforter', '#8a5a13', 'rgba(193,122,42,0.16)'] : ['À arbitrer', '#8D1D2C', 'rgba(141,29,44,0.10)'];
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
    const eur = v => v.toFixed(2).replace('.', ',') + ' €';
    common.pdRows = rows.map(p => { const vd = verdict(p.score); const t = this.trend(p.tend, 1);
      return { nom: p.nom, cat: p.cat, vol: Math.round(p.vol).toLocaleString('fr-BE'), tend: t.txt, tendSt: t.st + ';font-weight:400',
        prix: eur(p.prix), mu: eur(p.mu), mp: this.fP(p.mp, 0) + ' de marge', mg: this.fK(p.mg),
        pen: this.fP(p.pen, 0), mags: p.mags + ' / ' + nbOuv + ' magasins', partCaRes: this.fP(p.ca / caProd, 1), ca: this.fK(p.ca),
        barPen: bar(100 * p.pen, p.pen >= 0.8 ? '#2d7a3e' : p.pen >= 0.5 ? '#C17A2A' : '#8D1D2C'),
        rang: p.rang + ' / ' + p.nbCat, part: this.fP(p.partCat, 0),
        rangSt: this.pill(p.rang <= Math.ceil(p.nbCat / 3) ? 1 : p.rang <= Math.ceil(2 * p.nbCat / 3) ? 0.95 : 0.8),
        barVol: bar(p.sVol, '#8D1D2C'), barMg: bar(p.sMg, '#2d7a3e'), barPos: bar(p.sPos, '#C17A2A'),
        score: String(Math.round(p.score)), scoreSt: 'font-size:17px;font-weight:500;line-height:1;color:' + vd[1], scoreBar: bar(p.score, vd[1]),
        verdict: vd[0], verdictSt: 'display:inline-block;padding:3px 10px;border-radius:999px;font-size:11.5px;font-weight:500;white-space:nowrap;background:' + vd[2] + ';color:' + vd[1] }; });
    const nMot = base.filter(p => p.score >= 68).length, nArb = base.filter(p => p.score < 46).length;
    const caTot = caProd, mgTot = base.reduce((a, p) => a + p.mg, 0);
    const penMoy = base.reduce((a, p) => a + p.pen, 0) / (base.length || 1);
    const nPart = base.filter(p => p.pen < 0.5).length;
    common.pdKpis = [{ k: 'Références notées', v: String(base.length), s: Object.keys(cats).length + ' catégories — ' + nbOuv + ' magasins ouverts' },
      { k: 'CA produit réseau', v: this.fK(caTot), s: 'Ventes du mois, tous magasins ouverts' },
      { k: 'Marge brute produits', v: this.fK(mgTot), s: this.fP(mgTot / caTot, 1) + ' de taux de marge sur le CA produit' },
      { k: 'Pénétration moyenne', v: this.fP(penMoy, 0), s: nPart + ' références vendues dans moins de la moitié du réseau' },
      { k: 'Moteurs de gamme', v: String(nMot), s: 'Score ≥ 68 : disponibilité et mise en avant à sécuriser' },
      { k: 'À arbitrer', v: String(nArb), s: 'Score < 46 : retrait, repricing ou relance commerciale' }];
    common.pdNote = 'Score sur 100 = moyenne pondérée de trois notes : volume vendu (racine du volume réseau, ramené au best-seller), taux de marge unitaire (normalisé sur la gamme) et position dans la catégorie (rang par CA). Pondération actuelle : ' + common.pdPond + '. La pénétration réseau (nombre de magasins ouverts vendant la référence sur ' + nbOuv + ') et le CA réseau sont affichés à titre de contexte et n\'entrent pas dans le score.';
  }

  /* --- marge & coûts ------------------------------------------------------------ */
  valsMarge(common){
    const s = this.seuils();
    common.sFoodTxt = s.f + ' %'; common.sLabourTxt = s.l + ' %';
    const mg26 = this.sum(2026, 6, 'marge') / this.sum(2026, 6, 'ca'), mg25 = this.sum(2025, 6, 'marge') / this.sum(2025, 6, 'ca');
    common.mgReseau = this.fP(mg26); const tr = this.trend(mg26, mg25); common.mgTr = tr.txt + ' vs N-1'; common.mgTrSt = tr.st;
    const series = []; for (let m = 0; m <= 6; m++) series.push(this.sum(2026, m, 'marge') / this.sum(2026, m, 'ca'));
    common.mgTraj = this.spark(series, 320, 70);
    common.mgAlerts = this.margeAlerts();
    if (!common.mgAlerts.length) common.mgAlerts = [{ store: 'Aucune alerte', lev: 'food-cost', levNom: '—', msg: 'tous les ratios sont sous les seuils', action: '' }];
    const rat = (v, seuil) => { const base = 'display:inline-block;padding:3px 9px;border-radius:999px;font-size:12px;font-weight:500;';
      return v > seuil ? base + 'background:rgba(141,29,44,0.12);color:#8D1D2C' : v > seuil - 1.5 ? base + 'background:rgba(193,122,42,0.16);color:#8a5a13' : base + 'background:rgba(45,122,62,0.10);color:#2d7a3e'; };
    const seuilEtp = this.seuilCaEtp();
    common.mgSeuilEtp = this.fE(seuilEtp);
    const caEtpPill = v => { const base = 'display:inline-block;padding:3px 9px;border-radius:999px;font-size:12px;font-weight:500;';
      return v < seuilEtp ? base + 'background:rgba(141,29,44,0.12);color:#8D1D2C' : v < seuilEtp * 1.08 ? base + 'background:rgba(193,122,42,0.16);color:#8a5a13' : base + 'background:rgba(45,122,62,0.10);color:#2d7a3e'; };
    const rows = this.open().map(st => { const r = st.perf[2026][6], n1 = st.perf[2025][6];
      const mp26 = r.marge / r.ca, mp25 = n1.marge / n1.ca; const tv = this.trend(mp26, mp25);
      const nAl = (r.food > s.f ? 1 : 0) + (r.labour > s.l ? 1 : 0) + (r.overhead > s.o ? 1 : 0);
      const etp = Math.max(3, Math.round(r.ca / 14200));
      return { _mp: mp26, nom: st.nom, marge: this.fP(mp26), var: tv.txt, varSt: tv.st,
        food: String(r.food).replace('.', ',') + ' %', foodSt: rat(r.food, s.f), labour: String(r.labour).replace('.', ',') + ' %', labourSt: rat(r.labour, s.l), ov: String(r.overhead).replace('.', ',') + ' %', ovSt: rat(r.overhead, s.o),
        caEtp: this.fE(r.ca / etp), etp: etp + ' ETP', caEtpSt: caEtpPill(r.ca / etp),
        statut: nAl === 0 ? 'OK' : nAl + (nAl > 1 ? ' leviers à traiter' : ' levier à traiter') + (st.risk ? ' · sous-perf. 3 mois consécutifs' : '') }; });
    rows.sort((a, b) => b._mp - a._mp);
    common.mgRows = rows;
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
        due: note !== null ? 'Validée le ' + this.fD(x.t.done) : done ? 'Rendue le ' + this.fD(x.t.done)
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
    const etats = [['Plan reçu — 4 actions', '#2d7a3e'], ['En attente depuis 6 j', '#8a5a13'], ['Jamais ouvert', '#8D1D2C']];
    common.dlRows = this.open().map((s, i) => { const e = etats[i % 3];
      const url = 'latelierby.be/plan/' + s.nom.split(' — ')[0].toLowerCase().replace(/[^a-z]/g, '') + '-2026s2';
      return { store: s.nom, etat: e[0], etatCol: e[1], url,
        copy: () => { navigator.clipboard && navigator.clipboard.writeText('https://' + url); this.notify('Lien copié — ' + s.nom); },
        relance: () => { this.log('Relance', '—', 'Direct Link plan d’action relancé — ' + s.nom); this.notify('Relance envoyée au franchisé — ' + s.nom); } }; });
    const pById = id => D.people.find(p => p.id === id);
    common.repPeople = D.people.map(p => ({ val: p.id, nom: p.nom + ' — ' + p.role }));
    const posteDefs = navDef.flatMap(g => g[1]).map((it, i) => ({ id: 'p' + (i + 1), tag: 'P' + (i + 1), sid: it[0], label: it[1] }));
    const repUrl = (r, sel, email) => 'https://cockpit.latelierby.be/rapports/rapport.html?id=' + r.id + '&postes=' + sel.join(',') + '&periode={AAAA-MM}&dest=' + (email || '') + '&format=pdf';
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
      return { nom: r.nom, desc: r.desc, dest, cc: cc || '', destEmail: pd ? pd.email : '', ccEmail: pc ? pc.email : '', dernier: this.fD(r.dernier) + '/2026', freq: S.repFreq[r.id] || r.freq,
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

  /* --- paramètres ---------------------------------------------------------------------- */
  valsParams(common){
    const S = this.state, D = this.D, M = this.M;
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
