/* Cockpit CEO — logique applicative.
 * Port fidèle de la classe Component du prototype Design Component :
 * même état, mêmes calculs, mêmes libellés. Rendu : templates.js (HTML string
 * + délégation d'événements), données : api.js (REST, repli vide hors-ligne).
 * Chaque mutation est répercutée sur l'API quand elle est joignable (source === 'api').
 */
import { load, write, readOne, API_BASE, authStatus, authSubmit, authLogout, apiTraces, apiTracesRaz, joinPerf } from './api.js';
import { render as tplRender } from './templates.js';

function escHtml(v){
  return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

/* --- fusion de l'arbre rendu dans l'arbre vivant ----------------------------
 *
 * Le rendu produit une chaîne HTML. La poser avec `innerHTML = …` DÉTRUIT puis
 * recrée tous les nœuds — à chaque frappe au clavier. Conséquences mesurées
 * dans le navigateur : la photo d'une tâche repart à zéro (élément neuf, non
 * décodé : le navigateur peint une case vide avant l'image, c'est le « flash
 * blanc »), les conteneurs qui défilent reviennent en haut, et l'écran entier
 * se reconstruit — 2 051 nœuds pour la journée complète du contrôle — ce qui
 * donne l'impression d'un rechargement de page à chaque lettre tapée.
 *
 * On fusionne donc : on parcourt les deux arbres en parallèle et on ne touche
 * QUE ce qui diffère. Les nœuds qui ne changent pas survivent — avec leur
 * image décodée, leur défilement, leur curseur et leur sélection.
 *
 * La comparaison se fait par POSITION, sans clé : le rendu est déterministe et
 * produit la même structure pour le même état. Une position qui change de type
 * de balise est remplacée, pas rafistolée.
 */
function fusionneAttributs(v, n){
  const av = v.attributes;
  for (let i = av.length - 1; i >= 0; i--) {
    const a = av[i];
    if (!n.hasAttribute(a.name)) { v.removeAttribute(a.name); }
  }
  const an = n.attributes;
  for (let i = 0; i < an.length; i++) {
    const a = an[i];
    if (v.getAttribute(a.name) !== a.value) { v.setAttribute(a.name, a.value); }
  }
}

/**
 * Les enfants seulement — les attributs du conteneur ne sont pas touchés.
 *
 * C'est par là qu'on entre : la racine de l'application est un nœud du
 * document, pas un nœud du rendu. Lui appliquer les attributs du <div>
 * temporaire lui retirait son `id` (mesuré : `document.getElementById('app')`
 * rendait null après le premier rendu).
 */
/**
 * Un nœud qui s'en va ne doit plus déclencher de geste.
 *
 * Mesuré : poser un repère pendant qu'on écrit dans le précédent rendait la
 * main au précédent — la remarque suivante s'ajoutait à la fin de l'ancienne.
 * Le champ qui disparaît perd le focus, le navigateur émet alors `change`, et
 * cet événement retombait sur l'index d'un AUTRE geste, puisque les index sont
 * republiés à chaque rendu.
 *
 * On retire donc les attributs de geste AVANT de rendre la main au navigateur,
 * puis on relâche le focus : l'événement tardif ne trouve plus rien à appeler.
 */
const GESTES = ['data-h', 'data-c', 'data-i', 'data-sb', 'data-pd', 'data-ds', 'data-dp', 'data-en'];
function neutralise(el){
  if (!el || el.nodeType !== 1) { return; }
  const nu = n2 => GESTES.forEach(a => n2.removeAttribute(a));
  nu(el);
  if (el.querySelectorAll) { el.querySelectorAll('[' + GESTES.join('],[') + ']').forEach(nu); }
  const actif = document.activeElement;
  if (actif && actif !== document.body && (el === actif || (el.contains && el.contains(actif)))) {
    try { actif.blur(); } catch (e) { /* nœud déjà détaché */ }
  }
}

function fusionneEnfants(v, n){
  let ev = v.firstChild, en = n.firstChild;
  while (en) {
    const suivN = en.nextSibling;
    if (!ev) { v.appendChild(en); }
    else { const suivV = ev.nextSibling; fusionne(ev, en); ev = suivV; }
    en = suivN;
  }
  while (ev) { const s = ev.nextSibling; neutralise(ev); v.removeChild(ev); ev = s; }
}

function fusionne(v, n){
  if (v.nodeType !== n.nodeType || v.nodeName !== n.nodeName) { neutralise(v); v.replaceWith(n); return; }
  // texte et commentaires : la valeur, rien d'autre.
  if (v.nodeType === 3 || v.nodeType === 8) {
    if (v.nodeValue !== n.nodeValue) { v.nodeValue = n.nodeValue; }
    return;
  }
  if (v.nodeType !== 1) { return; }
  fusionneAttributs(v, n);

  const nom = v.nodeName;
  // La VALEUR d'un champ ne vit pas dans un attribut : la recopier suppose de
  // la lire sur la propriété. Deux pièges se répondent :
  //
  //  — écraser systématiquement la valeur remet le curseur à la fin, et
  //    annule ce qui est tapé dans un champ que l'écran ne relit qu'à la
  //    validation (l'assistant meuble lit le DOM au moment d'envoyer) ;
  //  — ne jamais l'écraser empêche l'application d'effacer un champ qu'elle
  //    tient (Échap sur la recherche laissait le texte à l'écran).
  //
  // On tranche sur ce que le RENDU dit : si la valeur rendue a changé depuis
  // la passe précédente, c'est l'application qui décide et on l'applique ;
  // sinon on laisse la frappe tranquille.
  const focus = document.activeElement === v;
  const pose = (val) => {
    if (val == null) { return; }
    const rendUChange = v.__rendu !== val;
    v.__rendu = val;
    if (v.value === val) { return; }
    if (!focus || rendUChange) {
      const fin = v.selectionStart;
      v.value = val;
      if (focus && v.setSelectionRange) {
        const pos = Math.min(fin == null ? val.length : fin, val.length);
        try { v.setSelectionRange(pos, pos); } catch (e) { /* champ sans sélection */ }
      }
    }
  };
  if (nom === 'TEXTAREA') { pose(n.textContent); }
  else if (nom === 'INPUT') {
    const t = String(v.type || '').toLowerCase();
    if (t === 'checkbox' || t === 'radio') {
      const coche = n.hasAttribute('checked');
      if (v.checked !== coche) { v.checked = coche; }
    } else { pose(n.getAttribute('value')); }
  }

  fusionneEnfants(v, n);

  // Un <select> prend sa valeur de l'option marquée : après fusion des
  // options, on la repose, sinon le menu affiche l'ancienne.
  if (nom === 'SELECT' && !focus) {
    const opt = v.querySelector('option[selected]');
    const val = opt ? (opt.hasAttribute('value') ? opt.getAttribute('value') : opt.textContent) : null;
    if (val != null && v.value !== val) { v.value = val; }
  }
}

class App {
  constructor(root){
    this.root = root;
    this.state = { ready: false, screen: 'taches', bStore: 'cha', tkStore: 'tous', openProjId: null,
      sortKey: 'caPct', sortDir: -1, zoneF: 'Toutes les zones', hmMetric: 'pct', hmYear: null, hmHover: null,
      horizon: 'h1', logType: 'Tous les types', logQui: 'Tous les auteurs', logQ: '', rel: null, toast: null,
      sFood: null, sLabour: null, statutOv: {}, familleOv: {}, relanced: {}, logsExtra: [], tpl: {},
      repFreq: {}, repDest: {}, repCc: {}, repPostes: {}, repPrev: null, repPrevTab: 'pdf', alertOn: {},
      np: null, nt: null, mkTypeForm: null, encStore: 'cha', encDraft: {}, openCards: {}, openInfo: {}, tkWho: 'all', tkOv: {},
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
      repCible: null, repRech: null, repBusy: false, pdCat: 'Toutes les catégories', pdSort: 'score' };
    this._h = [];
    this._tt = null;
    this._lastEn = null;
    // L'adresse d'abord : ouvrir « …/#/planogramme » doit ouvrir le
    // planogramme, pas l'accueil.
    const depart = this.ecranDeAdresse();
    if (depart) {
      this.state.screen = depart;
      // Arrivé par une adresse retirée, l'URL se remet d'aplomb : on ne laisse
      // pas « #/stock » dans la barre d'un écran qui n'est plus le stock.
      const juste = this.adresseDe(depart);
      if (location.hash !== juste) { this._hashInterne = juste; location.hash = juste; }
    }
    // Reculer dans l'historique doit reculer d'écran. Le changement qu'on
    // provoque nous-même est ignoré : sinon chaque navigation se rejouerait.
    window.addEventListener('hashchange', () => {
      if (this._hashInterne === location.hash) { this._hashInterne = null; return; }
      const id = this.ecranDeAdresse();
      if (!id) { return; }
      if (id !== this.state.screen) { this.setState({ screen: id, hmHover: null }); return; }
      // Même écran, mais adresse retirée (« #/stock » alors qu'on est déjà sur
      // les commandes) : la barre d'adresse se remet d'aplomb, sans quoi elle
      // afficherait un écran qui n'existe plus.
      const juste = this.adresseDe(id);
      if (location.hash !== juste) { this._hashInterne = juste; location.hash = juste; }
    });
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
  /**
   * L'adresse dit OÙ l'on est.
   *
   * Sans cela, un écran ne se partage pas : on envoyait « va dans Centrale
   * d'achat puis Commandes », et un rafraîchissement ramenait à l'accueil.
   * Chaque écran a donc son mot dans l'ancre — `#/planogramme` — choisi
   * lisible plutôt que technique. Un identifiant sans entrée ici garde le
   * sien : rien ne se casse si un écran naît sans qu'on y pense.
   */
  static get ADRESSES(){
    return {
      taches: 'taches-consultants', resultatJour: 'resultat-du-jour', exploitation: 'pl-magasins',
      magasins: 'magasins', heatmap: 'heatmap', objectifs: 'objectifs', marge: 'marge',
      reputation: 'reputation', budget: 'budget', encodage: 'budget-encodage',
      budgetparam: 'budget-parametres', catalogue: 'catalogue', assortiment: 'assortiment',
      planogramme: 'planogramme', produits: 'scoring', seuil: 'sous-seuil', analyse: 'analyse',
      usage: 'usage-catalogue',
      caAchats: 'commandes', caFacturation: 'facturation',
      caReglages: 'centrale-reglages', caDemande: 'demandes', caCampagnes: 'centrale-campagnes',
      projets: 'projets', fonds: 'fonds', mktCalendrier: 'calendrier', mktCampagnes: 'campagnes',
      bxcampagnes: 'budget-campagnes', mesure: 'mesure-campagnes', mktTypes: 'types-campagne',
      suivi: 'suivi-taches', controle: 'controle-taches', reporting: 'reporting',
      // La clé est l'IDENTIFIANT de l'écran, pas son adresse : « params »
      // n'existe nulle part ailleurs dans l'application, si bien que rouvrir
      // #/parametres depuis un signet résolvait vers un écran inconnu et
      // l'affichage tombait — « rendu impossible », page blanche.
      diagnostic: 'diagnostic', parametres: 'parametres',
    };
  }
  /**
   * Les adresses d'écrans RETIRÉS. Un signet ou un lien envoyé par courriel
   * survit à une réorganisation : il mène là où le contenu a déménagé, plutôt
   * que de rouvrir l'accueil sans rien dire.
   */
  static get ADRESSES_RETIREES(){
    return { stock: 'caAchats', 'commandes-franchises': 'caAchats' };
  }
  /** L'écran désigné par l'adresse — ou rien si elle ne désigne personne. */
  ecranDeAdresse(){
    const brut = String(location.hash || '').replace(/^#\/?/, '').split('?')[0].trim();
    if (!brut) { return ''; }
    const t = App.ADRESSES;
    for (const id of Object.keys(t)) { if (t[id] === brut) { return id; } }
    if (App.ADRESSES_RETIREES[brut]) { return App.ADRESSES_RETIREES[brut]; }
    // Un identifiant technique reste accepté : les liens d'avant continuent
    // de fonctionner.
    return Object.prototype.hasOwnProperty.call(t, brut) ? brut : '';
  }
  /** L'adresse d'un écran, pour un lien à copier. */
  adresseDe(id){
    return '#/' + (App.ADRESSES[id] || id);
  }
  setState(patch){
    const avant = this.state.screen;
    Object.assign(this.state, typeof patch === 'function' ? patch(this.state) : patch);
    // L'ancre suit l'écran — et l'historique avec elle : le bouton « retour »
    // du navigateur ramène à l'écran précédent, ce qu'on attend d'une adresse.
    if (this.state.screen !== avant) {
      const cible = this.adresseDe(this.state.screen);
      if (location.hash !== cible) { this._hashInterne = cible; location.hash = cible; }
    }
    this.render();
  }
  forceUpdate(){ this.render(); }

  reg(fn){ this._h.push(fn); return this._h.length - 1; }
  /**
   * Un rendu à la fois. Un second rendu déclenché PENDANT le premier est
   * différé, pas imbriqué.
   *
   * Le DOM porte des index (`data-h="62"`) qui désignent des positions dans le
   * tableau des gestionnaires. Les deux ne valent que s'ils sortent de la MÊME
   * passe. Or la fin du rendu rend le focus au champ actif, et rendre le focus
   * fait sortir le champ précédent — ce qui déclenche son `change`, donc un
   * `setState`, donc un rendu imbriqué. Après quoi le DOM affiché et le tableau
   * des gestionnaires venaient de deux passes différentes : les boutons du bas
   * de l'écran ne répondaient plus, en silence.
   *
   * Mesuré : après avoir touché au nombre d'emplacements, « Ajouter » ne
   * réagissait plus — ni pour le niveau, ni pour la zone — alors que la bascule
   * Plan/Tableau, enregistrée plus tôt dans la passe, marchait encore.
   */
  render(){
    if (this._rendu) { this._rendUnDeplus = true; return; }
    this._rendu = true;
    try { this.rendreMaintenant(); } finally { this._rendu = false; }
    if (this._rendUnDeplus) { this._rendUnDeplus = false; this.render(); }
  }
  /**
   * Les gestionnaires ne remplacent les anciens QUE si le rendu aboutit.
   *
   * `this._h` était vidé en tout premier. Si la construction de l'écran
   * échouait ensuite — une donnée d'API d'une forme inattendue suffit —, on se
   * retrouvait avec un DOM intact mais un tableau de gestionnaires vide : plus
   * un seul bouton ne répondait, sans message. C'est le « écran figé, puis
   * blanc » d'après une validation de tâche.
   *
   * Le rendu écrit donc dans un tableau neuf et ne le publie qu'avec le HTML,
   * d'un seul geste. Un échec laisse l'écran précédent ENTIER — affichage et
   * gestes — et se raconte au lieu de se taire.
   */
  /**
   * Une ouverture d'écran, comptée.
   *
   * Posé au rendu plutôt que sur le clic du rail : on entre aussi dans un
   * écran par un lien, une recherche ou un retour — et c'est l'usage réel
   * qu'on veut mesurer, pas le rail seul. Un même écran ne compte qu'une fois
   * tant qu'on n'en sort pas : un redessin n'est pas une ouverture.
   */
  vueEcran(id){
    if (!id || this._ecranVu === id) { return; }
    this._ecranVu = id;
    const qui = (this.meta && this.meta.utilisateur && this.meta.utilisateur.nom) || '';
    try { this.api('POST', '/ecrans/vue', { ecran: id, qui }); } catch (e) { /* la mesure ne casse rien */ }
  }

  /** Les ouvertures d'écran, lues à l'ouverture du Journal. */
  vuesCharge(force){
    if (this._vuesEnCours) { return; }
    if (!force && this.state.vues) { return; }
    this._vuesEnCours = true;
    this.setState({ vues: { chargement: true, d: null } });
    readOne('/ecrans/vues?jours=30').then(d => { this._vuesEnCours = false;
      this.setState({ vues: { chargement: false, d: d || null } }); })
      .catch(() => { this._vuesEnCours = false; this.setState({ vues: { chargement: false, d: null } }); });
  }

  rendreMaintenant(){
    const h = [];
    const reg = fn => { h.push(fn); return h.length - 1; };
    const x = {
      A: fn => fn ? `data-h="${reg(fn)}"` : '',
      C: fn => fn ? `data-c="${reg(fn)}"` : '',
      I: fn => fn ? `data-i="${reg(fn)}"` : '',
      DS: fn => fn ? `data-ds="${reg(fn)}"` : '',
      DP: fn => fn ? `data-dp="${reg(fn)}"` : '',
      EN: fn => fn ? `data-en="${reg(fn)}"` : '',
      SB: fn => fn ? `data-sb="${reg(fn)}"` : '',
      PD: fn => fn ? `data-pd="${reg(fn)}"` : '',
      esc: escHtml
    };
    this.vueEcran(this.state.screen);
    let common; let html;
    try {
      common = this.renderVals();
      html = tplRender(common, x);
    } catch (e) {
      this.panneRendu(e);
      return;
    }
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
    // Publication d'un seul geste : le HTML et les gestes qui vont avec.
    this._h = h;
    // On FUSIONNE au lieu de remplacer (voir `fusionne`). Si la fusion échoue
    // pour une raison quelconque, on retombe sur le remplacement complet :
    // un écran reconstruit vaut mieux qu'un écran figé.
    const neuf = document.createElement('div');
    neuf.innerHTML = html;
    let fondu = false;
    try { fusionneEnfants(this.root, neuf); fondu = true; }
    catch (e) { console.error('[cockpit] fusion impossible, remplacement complet :', e);
      this.root.innerHTML = html; }
    const banniere = document.getElementById('panne-rendu');
    if (banniere) { banniere.remove(); }
    const main2 = document.getElementById('main-scroll');
    if (main2) main2.scrollTop = scrollTop;
    Object.keys(gardes).forEach(k => {
      const e = document.querySelector('[data-scroll="' + k + '"]');
      if (e) { e.scrollTop = gardes[k]; }
    });
    // La fusion garde le nœud qui avait le focus : il n'y a plus rien à
    // restaurer. Le repli, lui, a tout recréé — et là seulement on remet le
    // curseur là où il était.
    if (focusId && !fondu){
      const el = document.getElementById(focusId);
      if (el && el !== document.activeElement){
        el.focus(); if (selStart != null && el.setSelectionRange) el.setSelectionRange(selStart, selStart);
      }
    }
  }
  /**
   * Un rendu qui échoue le DIT, au lieu de laisser un écran muet.
   *
   * La bannière se pose PAR-DESSUS l'écran précédent sans le remplacer : ce qui
   * est affiché reste lisible, et le message porte de quoi rapporter la panne
   * plutôt que « ça a fait un écran blanc ».
   */
  panneRendu(e){
    // La pile est GARDÉE : sans elle, « lecture de 'du' impossible » n'indique
    // pas quel écran a échoué, et on cherche à l'aveugle.
    try { window.__pileRendu = (e && e.stack) ? String(e.stack) : String(e); } catch (e2) { /* sans fenêtre */ }
    const msg = (e && e.message) ? String(e.message) : String(e);
    console.error('[cockpit] rendu impossible :', e);
    let el = document.getElementById('panne-rendu');
    if (!el) {
      el = document.createElement('div');
      el.id = 'panne-rendu';
      el.style.cssText = 'position:fixed;left:0;right:0;bottom:0;z-index:200;padding:12px 18px;'
        + 'background:#8D1D2C;color:#fff;font-family:var(--font-ui),sans-serif;font-size:12.5px;line-height:1.5';
      document.body.appendChild(el);
    }
    el.textContent = 'L’écran n’a pas pu être rafraîchi — ' + msg
      + ' · l’affichage précédent reste utilisable ; rechargez la page si le problème persiste.';
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
    // La case qui recevrait s'allume, et s'éteint quand on la quitte. La classe
    // est posée DIRECTEMENT sur le nœud : passer par l'état redessinerait
    // l'écran au milieu du geste, et le navigateur lâcherait ce qu'on tient.
    let survol = null;
    const eteint = () => { if (survol) { survol.classList.remove('depot'); survol = null; } };
    this.root.addEventListener('dragover', e => {
      const el = e.target.closest && e.target.closest('[data-dp]');
      if (!el) { eteint(); return; }
      e.preventDefault();
      if (survol !== el) { eteint(); survol = el; el.classList.add('depot'); }
    });
    this.root.addEventListener('dragleave', e => { if (e.target === survol) { eteint(); } });
    this.root.addEventListener('dragend', eteint);
    this.root.addEventListener('drop', e => { eteint(); run('data-dp', e); });
    // Échap ferme la recherche du rail. Le panneau de résultats recouvre le
    // rail : sans sortie au clavier, il fallait viser la croix pour retrouver
    // la navigation.
    this.root.addEventListener('keydown', e => {
      if (e.key !== 'Escape') { return; }
      if (this.state.gq) { this.setState({ gq: '' }); e.stopPropagation(); }
    });
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
  openRelTask(x){ const tpl = this.state.tpl; const late = x.st === 'En retard'; const base = this.D.emailTemplates[late ? 1 : 0]; const corps = (tpl[base.id] || base.corps);
    const sub = s => s.replace('{tache}', x.t.nom).replace('{projet}', x.p.nom).replace('{echeance}', this.fDA(x.t.due)).replace('{destinataire}', x.o.nom).replace('{zone}', '');
    this.setState({ rel: { kind: 'task', id: x.t.id, projet: x.p.nom, to: x.o.nom, email: x.o.email, sujet: sub(base.sujet), corps: sub(corps) } }); }
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
      usage: ['Usage du catalogue', 'Ce que chaque magasin vend du catalogue réseau, mois par mois. Ouvrez un magasin, puis un groupe, puis une sous-catégorie : les références qu’il vend, celles qui lui manquent, et par combien d’autres magasins chaque absente est vendue.'],
      seuil: ['Références sous seuil', 'Sortir d’un coup toutes les références dont le score passe sous un seuil, pour arbitrer la gamme. Le score est celui de l’écran de scoring — même calcul, même pondération.'],
      diagnostic: ['Diagnostic API', 'Ce que le cockpit ne peut pas afficher, écran par écran, et les appels qui dépassent deux secondes — ceux dont l’API amont doit être améliorée.'],
      caCampagnes: ['Campagnes commerciales', 'Campagnes du cockpit marketing et contrôle des flux fournisseurs. Lecture seule : une campagne ne s’écrit jamais depuis la centrale.'],
      caDemande: ['Demande de prix', 'Négociation fournisseur en quatre étapes : sélection, consolidation, demande, suivi.'],
      caAchats: ['Commandes', 'Les commandes des magasins chez leurs fournisseurs, de l’envoi à la livraison. Un fournisseur se relance par courrier à tout moment.'],
      caCommandes: ['Commandes', 'Les commandes des magasins chez leurs fournisseurs.'],
      caFacturation: ['Facturation magasins', 'Factures des magasins, TVA calculée ligne à ligne, relances.'],
      caReglages: ['Paramètres — Centrale d’achat', 'Moteur de marge (commission de marque, TVA par défaut, objectifs de négociation) et référentiel fournisseurs.'],
      analyse: ['Analyse dans le temps', 'Trois niveaux : le groupe, la catégorie, la référence. Seuls les groupes sont ventil\u00e9s en chiffre d\u2019affaires et détaillables magasin par magasin ; en dessous l\u2019API ne rend qu\u2019un volume réseau. Chaque point est comparé à la même étendue un an plus tôt.'],
      catalogue: ['Catalogue produit', 'Les références du réseau avec leur prix, leur coût matière et leurs DEUX marges : brute, puis nette après commission de marque — celle que pilote la centrale d\u2019achat. Filtrez, puis ouvrez une référence pour compléter sa fiche de production.'],
      assortiment: ['Assortiment obligatoire', 'Les références qu\u2019une boutique doit proposer en permanence, et la quantité minimale à tenir. Cochez une référence pour l\u2019imposer au réseau.'],
      mktCalendrier: ['Calendrier marketing', 'Les campagnes posées sur l\u2019année : qui occupe quel mois, à quel statut. Repris du module marketing — les données vivent dans les mêmes tables.'],
      mktCampagnes: ['Campagnes', 'Les campagnes du réseau : type, période, budget, statut. Créées et corrigées ici — le module marketing autonome disparaît.'],
      resultatJour: ['Résultat du jour', 'Le compte de résultat d\u2019une journée, magasin par magasin : ventes, coût matière, main-d\u2019œuvre, frais généraux et résultat. Ouvrez une ligne pour la cascade du magasin, sa ventilation par catégorie et la place du jour dans le mois.'],
      reputation: ['Réputation digitale', 'Ce que Google dit de chaque magasin : note, nombre d\u2019avis, les cinq derniers reçus, et le nombre d\u2019avis 5 étoiles qu\u2019il faudrait pour revenir à la cible.'],
      mesure: ['Mesure des campagnes', 'Ce qu’une campagne a changé, magasin par magasin : la période de campagne et celle d’avant, chacune comparée aux mêmes semaines de l’an dernier. L’effet net retire ce qui montait déjà ; la ligne « réseau hors campagne » donne le bruit de fond.'],
      bxcampagnes: ['Budget × Campagnes', 'Ce que la campagne devrait rapporter, magasin par magasin : le panier moyen récent multiplié par les clients en plus visés, ajouté au chiffre de l’an dernier — et le budget en regard.'], mktTypes: ['Types de campagne', 'Le référentiel tel que l\u2019assistant l\u2019affiche : nom, description, couleur, icône, levier lié et KPI attendu. L\u2019ordre est celui de la grille de la première étape. Un type porté par des campagnes se désactive, il ne s\u2019efface pas.'],
      fonds: ['Fonds & Royalties', 'Le fonds marketing du réseau — ce qui l\u2019alimente, ce qu\u2019il finance — et les redevances par magasin. Tout se saisit ici : le module marketing tient le grand livre, le cockpit y écrit sans qu\u2019on change d\u2019application.'],
      planogramme: ['Planogramme comptoir', 'Où chaque référence se place au comptoir : zone, meuble, niveau. Un emplacement vide se distingue d\u2019une référence jamais placée.'],
      production: ['Suivi de production', 'Ce qui a été produit et ce qui a été jeté, par boutique et par référence. Le taux de perte se calcule sur les ventes, pas sur les fournées déclarées.'],
      exploitation: ['Exploitation', 'Le P&L court de chaque magasin : chiffre d\u2019affaires du jour, de la semaine et du mois, avec le budget en regard du réel.'], taches: ['Tâches consultants', 'Ce qui attend le consultant : tâches photographiées à noter, ses propres tâches, projets en retard, alertes de marge. Puis sa liste, filtrable par intervenant et par magasin.'], magasins: ['Tableau des magasins', 'Marge, valeur, CA, tickets et panier moyen par magasin — dernier mois encodé, vs N-1 et vs cibles.'], heatmap: ['Heatmap mensuelle', 'Une ligne par magasin, une colonne par mois. Repérez d’un coup d’œil les sur- et sous-performances.'], budget: ['Suivi budget — magasin', 'Budget validé par le consultant contre réel encodé chaque mois, poste par poste.'], encodage: ['Encodage du budget', 'Saisie du mois : chiffre d’affaires budgété et charges réellement encodées, magasin par magasin.'], budgetparam: ['Paramètres du budget', 'Ce qui se décide une fois par an : l’étude de marché d’un magasin (potentiel, montée en régime, saisonnalité) et les taux de charges du réseau.'], objectifs: ['Objectifs de CA', 'Cibles par magasin et consolidées réseau, sur 3 horizons : 1 an, 3 ans et 5 ans.'], marge: ['Marge & maîtrise des coûts', 'Marge nette des franchisés et ratios food / labour / overhead, avec alertes par levier.'], projets: ['Projets', 'Suivi des projets de développement : statuts, rétroplanning, coûts, leviers et ROI.'], suivi: ['Suivi des tâches', 'Ce qui a été validé sur la période, et les signalements à traiter — semaine ou mois.'], controle: ['Contrôle des tâches', 'Tâches et checklists du panel, par boutique : une tâche notée est validée. Ouvrez une tâche pour voir la photo et poser (ou revoir) la note.'], reporting: ['Reporting automatisé', 'Rapports récurrents générés et envoyés par email (PDF), alertes push paramétrables.'], journal: ['Journal', 'Traçabilité intégrale : chaque action est horodatée avec son auteur. Filtrable et exportable.'], produits: ['Scoring produits', 'Volume, marge nette, taux de perte et présence au comptoir : un score unique par référence pour arbitrer la gamme. Cliquez un taux de perte pour le détail magasin par magasin.'], parametres: ['Paramètres', 'Leviers, seuils, modèles d’email, utilisateurs, magasins, zones et intégration TFB.'], scoring: ['Scoring produits — réglages', 'Pondération des quatre critères, seuils de verdict et échelle de la marge nette. Ces réglages pilotent directement l’écran Scoring produits.'] };
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
        // `this.D` et non `D` : la constante locale n'est déclarée que plus
        // bas dans la fonction, et l'y lire jetait « Cannot access 'D' before
        // initialization » — ouvrir le panneau utilisateur figeait l'écran.
        roles: (this.D.roles || []), aRoles: (this.D.roles || []).length > 0,
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
      couts: [{ poste: 'Jours-homme consultants', prevu: '' }],
      // Hypothèses économiques : ce que le projet doit rapporter, et à qui.
      // Vides au départ — un projet dont on n'a pas encore chiffré l'économie
      // doit pouvoir se créer, et des zéros pré-remplis se seraient enregistrés
      // comme des hypothèses posées.
      margeCible: '', prixVente: '', volMag: '', volReseau: '', royaltiesTaux: '',
      royaltiesEuro: '', margeMagAn: '' } });
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

      // --- l'économie du projet : ce qu'il rapporte, et à qui.
      //
      // Trois saisies suffisent — marge visée, prix de vente, volume par
      // magasin. Le reste se déduit, s'affiche, et reste MODIFIABLE : le calcul
      // est une proposition, pas une contrainte. Un chiffre corrigé à la main
      // n'est plus recalculé, sinon on écraserait ce que quelqu'un a voulu
      // poser.
      const nb = v => { const n = parseFloat(String(v).replace(',', '.')); return isFinite(n) ? n : 0; };
      const nOuv = this.open().length || 1;
      const tauxRoy = f.royaltiesTaux !== '' ? nb(f.royaltiesTaux)
        : ((this.meta && this.meta.seuils && this.meta.seuils.royalties) || 0);
      const volMag = nb(f.volMag);
      const volRes = f.volReseau !== '' ? nb(f.volReseau) : volMag * nOuv;
      const prix = nb(f.prixVente);
      const caRes = prix * volRes;
      const caMag = prix * volMag;
      const margeMag = f.margeMagAn !== '' ? nb(f.margeMagAn) : caMag * nb(f.margeCible) / 100;
      const roy = f.royaltiesEuro !== '' ? nb(f.royaltiesEuro) : caRes * tauxRoy / 100;
      const npEcoSet = k => e => this.setState(s2 => ({ np: Object.assign({}, s2.np, { [k]: e.target.value }) }));
      common.npEco = {
        margeCible: f.margeCible, setMargeCible: npEcoSet('margeCible'),
        prixVente: f.prixVente, setPrixVente: npEcoSet('prixVente'),
        volMag: f.volMag, setVolMag: npEcoSet('volMag'),
        // Le volume réseau se déduit du volume magasin ; le corriger le fige.
        volReseau: f.volReseau !== '' ? f.volReseau : (volMag ? String(Math.round(volRes)) : ''),
        setVolReseau: npEcoSet('volReseau'),
        volReseauAuto: f.volReseau === '' && volMag > 0,
        volReseauAide: nOuv + ' boutique(s) ouvertes',
        royaltiesTaux: f.royaltiesTaux !== '' ? f.royaltiesTaux : (tauxRoy ? String(tauxRoy) : ''),
        setRoyaltiesTaux: npEcoSet('royaltiesTaux'),
        royaltiesEuro: f.royaltiesEuro !== '' ? f.royaltiesEuro : (roy ? String(Math.round(roy)) : ''),
        setRoyaltiesEuro: npEcoSet('royaltiesEuro'),
        royaltiesAuto: f.royaltiesEuro === '' && roy > 0,
        margeMagAn: f.margeMagAn !== '' ? f.margeMagAn : (margeMag ? String(Math.round(margeMag)) : ''),
        setMargeMagAn: npEcoSet('margeMagAn'),
        margeMagAuto: f.margeMagAn === '' && margeMag > 0,
        caReseau: caRes ? this.fE(caRes) : '—',
        caMagasin: caMag ? this.fE(caMag) : '—',
        // Une ligne de lecture, en français : c'est elle qu'on relira dans six
        // mois pour savoir ce qui avait été promis.
        resume: (prix && volMag)
          ? 'Prix ' + this.fE(prix) + ' × ' + Math.round(volMag) + ' pièces par boutique = '
            + this.fE(caMag) + ' de CA magasin, ' + this.fE(caRes) + ' réseau'
            + (nb(f.margeCible) ? ' · marge visée ' + String(f.margeCible).replace('.', ',') + ' % → ' + this.fE(margeMag) + ' par boutique' : '')
            + (roy ? ' · royalties marque ' + this.fE(roy) : '')
          : 'Renseignez le prix et le volume par boutique pour chiffrer le projet.',
      };

      // --- jalons : plusieurs modèles selon le type de développement.
      //
      // Un projet produit et un projet d'ouverture ne se jalonnent pas pareil,
      // et un même projet peut emprunter aux deux. Les modèles s'AJOUTENT donc
      // au rétroplanning au lieu de le remplacer — sauf quand il est vide.
      common.npJalonModeles = Object.keys(D.projTemplates || {}).map(ax => {
        const tpl = (D.projTemplates || {})[ax] || {};
        return { axe: ax, n: (tpl.jalons || []).length,
          charger: () => this.setState(s2 => { const f2 = s2.np || {};
            const vides = (f2.jalons || []).filter(j => (j.nom || '').trim() !== '');
            const neufs = (tpl.jalons || []).map(j => ({ nom: j.nom, cible: addD(f2.fin, j.j) }));
            const fusion = vides.concat(neufs.filter(n2 => !vides.some(v => v.nom === n2.nom)));
            this.notify(neufs.length + ' jalon(s) « ' + ax + ' » ajouté(s)'
              + (vides.length ? ' au rétroplanning existant' : ''));
            return { np: Object.assign({}, f2, { jalons: fusion.length ? fusion : neufs }) }; }) };
      }).filter(m => m.n > 0);
    }
    const FAM_BY_AXE = { 'Ventes': 'Produits', 'Marge nette franchisé': 'Organisation & coûts', 'Développement réseau': 'Développement réseau', 'Produit — Interne (production)': 'Produits', 'Produit — Externe (achat)': 'Produits' };
    common.npCreate = () => { const f = S.np; if (!f) return;
      if (!f.nom.trim()){ this.notify('Donnez un nom au projet.'); return; }
      // Les valeurs déduites partent telles qu'elles sont AFFICHÉES : ce que
      // l'écran montre est ce qui s'enregistre.
      const eco = common.npEco || {};
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
        kpis: f.kpi.trim() ? [f.kpi.trim()] : [], jalons, couts, taches: taches.map(t => ({ id: t.id, nom: t.nom, owner: t.owner, due: t.due })),
        economie: {
          margeCible: f.margeCible === '' ? null : +String(f.margeCible).replace(',', '.'),
          prixVente: f.prixVente === '' ? null : +String(f.prixVente).replace(',', '.'),
          volMagasin: f.volMag === '' ? null : +String(f.volMag).replace(',', '.'),
          volReseau: eco.volReseau === '' ? null : +String(eco.volReseau).replace(',', '.'),
          royaltiesTaux: eco.royaltiesTaux === '' ? null : +String(eco.royaltiesTaux).replace(',', '.'),
          royaltiesEuro: eco.royaltiesEuro === '' ? null : +String(eco.royaltiesEuro).replace(',', '.'),
          margeMagasinAn: eco.margeMagAn === '' ? null : +String(eco.margeMagAn).replace(',', '.'),
        },
        journal: jr });
      this.log('Création', f.nom.trim(), jr);
      this.setState({ np: null, openProjId: id }); this.notify('Projet « ' + f.nom.trim() + ' » créé'); };
    const flat = this.tasksFlat();
    const lateTasks = flat.filter(x => x.st === 'En retard');
    const projEff = D.projects.map(p => Object.assign({}, p, { statut: this.pStatut(p) }));
    const nLate = projEff.filter(p => p.statut === 'En retard').length;

    // Le rail suit le geste, pas l'organigramme : on pilote sa journée, puis on
    // regarde la performance des magasins, puis les produits qui la font, puis
    // les achats, puis ce que la marque investit et ce qu'elle met en avant,
    // puis ce qu'on contrôle.
    // « Exploitation » et « Réseau & marque » ne portaient qu'une entrée
    // chacune : une section d'un seul item coûte un titre pour rien.
    const navDef = [
      ['Pilotage', [
        ['taches', 'Tâches consultants', lateTasks.length],
        // Le résultat du JOUR précède le P&L : c'est ce qu'on regarde le matin,
        // magasin par magasin, avant de dérouler le mois.
        ['resultatJour', 'Résultat du jour', 0],
        ['exploitation', 'P&L magasins', 0]]],
      ['Performance magasins', [
        ['magasins', 'Tableau des magasins', 0],
        ['heatmap', 'Heatmap mensuelle', 0],
        ['objectifs', 'Objectifs de CA', 0],
        ['marge', 'Marge & coûts', 0],
        // Le badge compte les magasins sous la cible : il n'apparaît qu'une
        // fois l'écran ouvert une première fois, la lecture étant paresseuse.
        ['reputation', 'Réputation digitale', ((this.D.reput || {}).reseau || {}).sousCible || 0]]],
      // Le budget est une affaire de finance, pas de performance : il a sa
      // section, demandée telle quelle, et garde son sous-menu.
      ['Finance', [
        { sub: 'Budget magasin', children: [
          ['budget', 'Suivi du budget', 0],
          ['encodage', 'Encodage du budget', 0],
          // L'étude de marché et les taux de charges se règlent une fois par
          // an (ou à l'ouverture d'un magasin) : ils quittent l'écran de
          // saisie mensuelle pour ne pas encombrer ce qu'on fait chaque mois.
          ['budgetparam', 'Paramètres du budget', 0]] }]],
      // Le produit d'abord tel qu'il est (catalogue, comptoir), ensuite ce
      // qu'il vaut (scoring), enfin ce qu'on en produit.
      ['Produits', [
        { sub: 'Catalogue & comptoir', children: [
          ['catalogue', 'Catalogue produit', 0],
          ['assortiment', 'Assortiment obligatoire', 0],
          ['planogramme', 'Planogramme comptoir', 0]] },
        { sub: 'Scoring & analyse', children: [
          ['produits', 'Scoring des références', 0],
          ['seuil', 'Références sous seuil', 0],
          ['analyse', 'Analyse dans le temps', 0],
          ['usage', 'Usage du catalogue', 0]] }]],
      // « Suivi de production » a quitté le rail : les fournées déclarées sont
      // trop lacunaires pour en tirer quoi que ce soit (une boutique sur
      // quatre n'en déclare aucune), et le taux de perte se lit déjà au
      // scoring. L'écran reste dans le code, il ne s'atteint plus depuis la
      // navigation.

      // « Campagnes commerciales » et « Demande de prix » ont quitté le rail à
      // la demande — les écrans restent dans le code, comme le suivi de
      // production, ils ne s'atteignent plus depuis la navigation.
      ['Centrale d’achat', [
        ['caAchats', 'Commandes', 0],
        ['caFacturation', 'Facturation magasins', 0]]],
      // Ce que la marque investit et ce qu'elle en retire, au même endroit : le
      // fonds finance les projets de développement, et l'un ne se lit pas sans
      // l'autre.
      ['Marque & développement', [
        ['projets', 'Projets de développement', nLate],
        ['fonds', 'Fonds & Royalties', 0]]],
      // Le marketing suit la marque : le fonds finance les campagnes, et une
      // campagne se lit à côté de ce qu'elle a coûté. Repris du module
      // marketing autonome, qui disparaît — le cockpit lit et écrit les tables
      // mar_* directement, comme il le fait pour pla_*.
      // « Types de campagne » vient d'Administration : c'est un réglage, mais
      // celui-là s'ouvre en écrivant une campagne, pas en administrant l'appli.
      ['Marketing', [
        ['mktCalendrier', 'Calendrier marketing', 0],
        ['mktCampagnes', 'Campagnes', 0],
        // Le budget dit ce qu'un magasin doit faire, la campagne ce qu'on lui
        // demande en plus : les deux se lisent enfin sur le même écran.
        ['bxcampagnes', 'Budget × Campagnes', 0],
        // Ce qu'une campagne a vraiment donné : avant / après, net du témoin.
        ['mesure', 'Mesure des campagnes', 0],
        ['mktTypes', 'Types de campagne', 0]]],
      // Le reporting EST un contrôle : il rend compte de ce qui a été fait, au
      // même endroit que les checklists. Le journal, lui, est une trace
      // d'administration — il rejoint les paramètres.
      ['Contrôle', [
        { sub: 'Checklists consultants', children: [
          ['suivi', 'Suivi des tâches', S.suiviData ? S.suiviData.ouverts : 0],
          ['controle', 'Contrôle des tâches', ((D.pwaTasks || {}).totals || {}).aValider || 0]] },
        ['reporting', 'Reporting automatisé', 0]]],
      ['Administration', [
        ['diagnostic', 'Diagnostic API', 0],
        { sub: 'Paramètres', children: [['parametres', 'Général', 0], ['scoring', 'Scoring produits', 0],
          ['caReglages', 'Centrale d’achat', 0],
          ['journal', 'Journal', 0]] }]]];
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

    this.valsRecherche(common, navDef, goTo, titles);
    // Le rail sert aussi à NOMMER les écrans dans la heatmap du journal : sans
    // lui, la mesure ne rendrait que des identifiants.
    this._navDef = navDef;

    ['isBudget', 'isEncodage', 'isMagasins', 'isHeatmap', 'isObjectifs', 'isMarge', 'isProjets', 'isReporting', 'isJournal', 'isParams', 'isTaches', 'isProduits', 'isSuivi', 'isControle', 'isScoring', 'isExploit', 'isCat', 'isAsso', 'isPlano', 'isProd', 'isAnalyse', 'isCentrale', 'isDiag', 'isSeuil', 'isFonds', 'isMktCal', 'isMktCamp', 'isMktTypes', 'isReput', 'isRJour', 'isBudgetParam', 'isBxc', 'isMesure', 'isUsage'].forEach(k => common[k] = false);
    const key = { budget: 'isBudget', encodage: 'isEncodage', budgetparam: 'isBudgetParam', taches: 'isTaches', magasins: 'isMagasins', heatmap: 'isHeatmap', objectifs: 'isObjectifs', marge: 'isMarge', produits: 'isProduits', projets: 'isProjets', suivi: 'isSuivi', controle: 'isControle', reporting: 'isReporting', journal: 'isJournal', parametres: 'isParams', scoring: 'isScoring', exploitation: 'isExploit', catalogue: 'isCat',
      assortiment: 'isAsso', planogramme: 'isPlano', production: 'isProd', fonds: 'isFonds',
      mktCalendrier: 'isMktCal', mktCampagnes: 'isMktCamp', mktTypes: 'isMktTypes', bxcampagnes: 'isBxc', mesure: 'isMesure', reputation: 'isReput', resultatJour: 'isRJour',
      analyse: 'isAnalyse', diagnostic: 'isDiag', seuil: 'isSeuil', usage: 'isUsage' }[S.screen];
    // Les dix écrans de la centrale partagent un même gabarit : un seul drapeau
    // et une seule fonction de valeurs, l'écran courant étant porté par S.screen.
    if (String(S.screen || '').startsWith('ca') && S.screen !== 'catalogue') { common.isCentrale = true; }
    else if (key) { common[key] = true; }
    // Quitter un écran arme sa prochaine relecture : y revenir doit relire la
    // base, pas réafficher ce qui avait été lu la première fois.
    if (this._perfEcran && this._perfEcran !== S.screen) { this._perfEcran = null; }

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

      // --- trois tableaux annuels (une colonne par mois), servis par l'API :
      // clients/jour, ticket moyen, articles par ticket. Lecture paresseuse.
      const anz = S.mgAn;
      if (!anz) { setTimeout(() => this.mgAnCharge(), 0); }
      const ad = anz && anz.d;
      const nMois = ad ? ad.moisMax : 0;
      const fN = (v, dec) => v == null ? '—' : v.toLocaleString('fr-BE', { minimumFractionDigits: dec, maximumFractionDigits: dec });
      // Le CHIFFRE se colore (pas la cellule) selon son écart à la moyenne
      // réseau du mois, par paliers : +5 % vert clair, +10 % vert foncé,
      // +20 % doré ; −5 % orange, −10 % rouge, −20 % rouge vif. Entre −5 et
      // +5 %, couleur normale. La référence du panier et des articles est la
      // ligne réseau elle-même (pondérée par les tickets) ; pour les
      // clients/jour la ligne réseau est un TOTAL — la référence est donc ce
      // total ramené au nombre de magasins actifs du mois.
      const teinteEcart = (v, ref) => {
        if (v == null) { return 'color:var(--color-text-muted)'; }
        if (ref == null || ref <= 0) { return ''; }
        const e = v / ref - 1;
        const c = e >= 0.20 ? '#C9A227' : e >= 0.10 ? '#2d7a3e' : e >= 0.05 ? '#7CB342'
          : e <= -0.20 ? '#E0261A' : e <= -0.10 ? '#8D1D2C' : e <= -0.05 ? '#C17A2A' : null;
        return c ? 'color:' + c + ';font-weight:600' : '';
      };
      common.mgAn = {
        chargement: !anz || anz.chargement,
        indispo: ad && ad.indispo ? (ad.motif || 'API indisponible')
          : (anz && !anz.chargement && !ad ? 'La lecture de /stores/kpis-annuels a échoué — voir Diagnostic API.' : ''),
        annee: ad ? String(ad.annee) : '',
        moisLabels: ad ? M.MOIS.slice(0, nMois) : [],
        source: ad ? (ad.source || '')
          + ' · ligne Réseau : totaux réels (panier et articles pondérés par les tickets)'
          + ' · couleur du chiffre : écart à la moyenne réseau du mois — +5 % vert clair, +10 % vert foncé, +20 % doré · −5 % orange, −10 % rouge, −20 % rouge vif' : '',
        tables: !ad || ad.indispo ? [] : [
          ['Clients par jour', 'moyenne journalière du mois (tickets ÷ jours)', 'clientsJour', 1, ''],
          ['Ticket moyen', 'CA du mois ÷ tickets du mois', 'panier', 2, ' €'],
          ['Articles par ticket', 'produits vendus ÷ tickets du mois', 'items', 2, ''],
        ].map(t => {
          const refMois = Array.from({ length: nMois }, (_, i) => {
            const r = ((ad.reseau || {})[i + 1] || {})[t[2]];
            if (r == null) { return null; }
            if (t[2] !== 'clientsJour') { return r; }
            const actifs = (ad.magasins || []).filter(m => ((m.mois[i + 1] || {})[t[2]]) != null && ((m.mois[i + 1] || {})[t[2]]) > 0).length;
            return actifs > 0 ? r / actifs : null;
          });
          return { titre: t[0], sous: t[1],
            rows: (ad.magasins || []).map(m => ({ nom: m.nom, reseau: false,
              cells: Array.from({ length: nMois }, (_, i) => { const v = (m.mois[i + 1] || {})[t[2]];
                return { t: fN(v, t[3]) + (v != null ? t[4] : ''), st: teinteEcart(v, refMois[i]) }; }) }))
              .concat([{ nom: 'Réseau', reseau: true,
                cells: Array.from({ length: nMois }, (_, i) => { const v = ((ad.reseau || {})[i + 1] || {})[t[2]];
                  return { t: fN(v, t[3]) + (v != null ? t[4] : ''), st: v == null ? 'color:var(--color-text-muted)' : '' }; }) }]) };
        }),
      };
    }

    // --- heatmap
    if (common.isHeatmap){
      const E = this.exo();
      const year = (S.hmYear === E - 1) ? E - 1 : E;
      // Le mode « % d'atteinte » suppose des objectifs encodés. Tant qu'il n'y
      // en a pas, il n'affiche que des cases vides — l'écran paraît mort alors
      // que le CA réel est là. On bascule alors sur le CA, sauf choix explicite.
      // Un objectif, c'est le budget validé — et à défaut le CA théorique de
      // l'étude de marché. Sans ce repli, un magasin qui a une étude mais pas
      // encore de budget négocié (Sombreffe) n'avait aucun chiffre à donner.
      const cibleDe = c => (c && c.caT) ? c.caT : ((c && c.theo) ? c.theo : null);
      const aDesCibles = this.open().some(st => (st.perf[year] || []).some(c => cibleDe(c)));
      const metric = (year === E - 1 || !aDesCibles) ? (S.hmMetric === 'pct' && aDesCibles ? 'pct' : 'ca') : S.hmMetric;
      common.hmYearCur = String(E); common.hmYearPrev = String(E - 1);
      const tb = act => 'border:none;cursor:pointer;font-family:var(--font-ui);font-size:12px;font-weight:500;padding:7px 14px;' + (act ? 'background:var(--color-primary);color:#fff' : 'background:var(--color-surface);color:var(--color-text-muted)');
      common.hmBtnCaSt = tb(metric === 'ca'); common.hmBtnPctSt = tb(metric === 'pct'); common.hmBtn25St = tb(year === E - 1); common.hmBtn26St = tb(year === E);
      common.hmMetricCa = () => this.setState({ hmMetric: 'ca' }); common.hmMetricPct = () => this.setState({ hmMetric: 'pct' });
      common.hmY25 = () => this.setState({ hmYear: E - 1 }); common.hmY26 = () => this.setState({ hmYear: E });
      common.hmNote = year === E - 1 ? ('Année ' + (E - 1) + ' : CA constaté (pas d’objectif défini).')
        : (!aDesCibles ? 'Aucun objectif encodé pour ' + year + ' : les cellules montrent le CA constaté. Encodez un budget (Encodage du budget) pour activer le % d’atteinte.'
        : (metric === 'pct'
          ? 'Atteinte de l’objectif mensuel — le budget validé, ou le CA théorique de l’étude à défaut : or dès 100 %, puis orange à −5 %, rouge à −10 %, rouge foncé à −15 %, noir au-delà. Un mois sans l’un ni l’autre reste vide.'
          : 'Cellules colorées du CA le plus faible au plus élevé.'));
      common.hmMois = M.MOIS;
      let mn = Infinity, mx = -Infinity;
      if (metric === 'ca') for (const s of this.open()) for (const r of s.perf[year]) if (r.ca != null){ mn = Math.min(mn, r.ca); mx = Math.max(mx, r.ca); }
      const cellBase = 'border-radius:5px;min-height:34px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:500;cursor:default;';
      const mkCell = (nomM, mi, ca, caT, theorique) => { let st = cellBase, txt = '—';
        if (ca == null){ st += 'background:var(--color-background-secondary);color:var(--color-text-muted)'; }
        else if (metric === 'ca'){ const t = (ca - mn) / (mx - mn || 1); st += 'background:' + this.mix('#F7F2EA', '#8D1D2C', t) + ';color:' + (t > 0.55 ? '#fff' : '#222'); txt = Math.round(ca / 1000) + 'k'; }
        else if (!caT && !theorique) {
          // Pas d'objectif encodé pour ce mois : « Infinity % » s'affichait,
          // c'est-à-dire un CA divisé par zéro. Une case sans objectif n'a
          // pas de taux d'atteinte — elle reste vide et le dit au survol.
          st += 'background:var(--color-background-secondary);color:var(--color-text-muted)';
        }
        else { const cible = caT || theorique, pct = 100 * ca / cible, ecart = pct - 100;
          // L'échelle demandée : l'objectif atteint est de l'OR, et le retard
          // s'assombrit par paliers de cinq points — orange, rouge, rouge
          // foncé, noir. Pas de dégradé continu : on doit pouvoir nommer la
          // couleur d'une case, et savoir ce qu'elle vaut.
          const c3 = ecart >= 0 ? ['#C9A227', '#221E1A']
            : (ecart >= -5 ? ['#C17A2A', '#ffffff']
            : (ecart >= -10 ? ['#C0182B', '#ffffff']
            : (ecart >= -15 ? ['#7E1220', '#ffffff'] : ['#151515', '#ffffff'])));
          st += 'background:' + c3[0] + ';color:' + c3[1]; txt = Math.round(pct) + ' %'; }
        return { txt, st,
          enter: () => this.setState({ hmHover: { nomM, mi, ca, caT, theo: theorique } }),
          // Le survol donne la ligne du bas ; le CLIC ouvre le détail — budget,
          // théorique, réel — parce qu'une case colorée ne dit pas d'où vient
          // sa couleur, et que c'est la première question qu'on se pose.
          clic: () => this.setState({ hmDet: { nomM, mi, ca, caT, theo: theorique, an: year } }) }; };
      common.hmRows = this.open().map(s => ({ nom: s.nom, cells: s.perf[year].map((r, mi) => mkCell(s.nom, mi, r.ca, r.caT, r.theo)) }));
      const resCells = [];
      for (let mi = 0; mi < 12; mi++){ const ca = this.open().every(s => s.perf[year][mi].ca == null) ? null : this.sum(year, mi, 'ca');
        // La cible du réseau additionne, magasin par magasin, ce qui fait
        // référence chez lui : son budget, ou son théorique.
        const cible = this.open().reduce((a, s2) => a + (cibleDe(s2.perf[year][mi]) || 0), 0) || null;
        if (metric === 'ca'){ const c2 = mkCell('Réseau', mi, ca == null ? null : ca / this.open().length, cible, null); if (ca != null) c2.txt = Math.round(ca / 1000) + 'k'; c2.enter = () => this.setState({ hmHover: { nomM: 'Réseau', mi, ca, caT: cible } }); resCells.push(c2); }
        else resCells.push(mkCell('Réseau', mi, ca, cible, null)); }
      common.hmReseau = resCells;
      const h = S.hmHover;
      const cibleH = h ? (h.caT || h.theo || null) : null;
      // ── La modale d'une case : les trois séries, l'écart et l'atteinte.
      const dt = S.hmDet;
      common.hmDet = dt ? (() => {
        const cible = dt.caT || dt.theo || null;
        const src = dt.caT ? 'budget validé' : (dt.theo ? 'CA théorique de l’étude' : null);
        const ecart = (dt.ca != null && cible) ? dt.ca - cible : null;
        const att = (dt.ca != null && cible) ? dt.ca / cible : null;
        return {
          titre: M.MOIS[dt.mi] + ' ' + dt.an,
          magasin: dt.nomM,
          sous: src ? 'objectif : ' + src : 'aucun objectif encodé pour ce mois',
          lignes: [
            { l: 'Budget validé', v: dt.caT ? this.fE(dt.caT) : '—',
              aide: dt.caT ? '' : 'non encodé pour ce mois' },
            { l: 'CA théorique (étude de marché)', v: dt.theo ? this.fE(dt.theo) : '—',
              aide: dt.theo ? '' : 'aucune étude projetée sur ce mois' },
            { l: 'Réel encaissé', v: dt.ca != null ? this.fE(dt.ca) : '—',
              aide: dt.ca == null ? 'mois sans remontée de caisse' : '' },
          ],
          ecart: ecart == null ? '—' : (ecart >= 0 ? '+' : '−') + this.fE(Math.abs(ecart)),
          ecartSt: 'font-weight:600;color:' + (ecart == null ? 'var(--color-text-muted)' : (ecart >= 0 ? '#2d7a3e' : '#8D1D2C')),
          att: att == null ? '—' : Math.round(att * 100) + ' %',
          attSt: 'font-weight:600;color:' + (att == null ? 'var(--color-text-muted)'
            : (att >= 1 ? '#8a6a10' : (att >= 0.95 ? '#8a5a13' : (att >= 0.9 ? '#C0182B' : (att >= 0.85 ? '#7E1220' : '#151515'))))),
          cibleTxt: cible ? this.fE(cible) : '—',
          close: () => this.setState({ hmDet: null }),
        };
      })() : null;
      common.hmDetail = h
        ? (h.nomM + ' — ' + M.MOIS[h.mi] + ' ' + year + ' : CA ' + (h.ca != null ? this.fE(h.ca) : '—')
          + (cibleH ? ' · objectif ' + this.fE(cibleH) + (h.caT ? '' : ' (théorique — budget non encodé)')
              + ' · écart ' + (h.ca != null ? this.fE(h.ca - cibleH) + ' (' + this.fP(h.ca / cibleH - 1) + ')' : '—')
            : ' · aucun objectif encodé pour ce mois'))
        : 'Survolez une cellule pour le détail (magasin, mois, CA, objectif, écart).';
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
        // Une cible, c'est le budget validé du mois — et à défaut le CA
        // théorique de l'étude. Sans ce repli, Corbais affichait 499 %
        // d'atteinte : deux mois de budget encodés contre huit mois de réel,
        // soit une performance contre presque rien.
        const cibleDe = c => (c && c.caT) ? c.caT : ((c && c.theo) ? c.theo : 0);
        let reelT = 0, prorataT = 0, cibleT = 0;
        const rows = this.open().map(s => { const cible = s.perf[E].reduce((a, r) => a + cibleDe(r), 0);
          let reel = 0, pro = 0; for (let m = 0; m <= MI; m++){ reel += s.perf[E][m].ca; pro += cibleDe(s.perf[E][m]); }
          reelT += reel; prorataT += pro; cibleT += cible; const att = pro > 0 ? reel / pro : null;
          const t = att == null ? { txt: '—', st: 'color:var(--color-text-muted)' } : this.trend(att, 1);
          // D'où vient la cible : validée, ou reprise de l'étude. Les deux ne
          // se valent pas, et la ligne doit pouvoir le dire.
          const nBud = s.perf[E].filter(r => r.caT).length;
          return { nom: s.nom, cible: cible > 0 ? this.fK(cible) : '—',
            source: nBud === 12 ? '' : (nBud > 0 ? nBud + ' mois validés, le reste théorique' : 'théorique'),
            reel: this.fK(reel), prorata: pro > 0 ? this.fK(pro) : '—', ecart: t.txt, ecartSt: t.st,
            att: att == null ? '—' : this.fP(att, 0), attSt: att == null ? 'color:var(--color-text-muted)' : this.pill(att), _att: att || 0,
            goBudget: () => this.setState({ bStore: s.id, screen: 'budget' }) }; });
        rows.sort((a, b) => b._att - a._att);
        common.objRows = rows;
        // La cible RÉSEAU vient des objectifs encodés (écran Objectifs) ; sans
        // eux, elle valait « 0,0 M€ » à côté d'un tableau qui, lui, affichait
        // 2,5 M€ de cibles magasins. On additionne alors les magasins, et on
        // dit d'où vient le chiffre.
        const cibleReseau = ((((D.targets || {}).ca) || {}).h1 || {}).cible || 0;
        common.objCible = this.fM(cibleReseau > 0 ? cibleReseau : cibleT);
        common.objCibleSrc = cibleReseau > 0 ? '' : 'somme des magasins — aucune cible réseau encodée';
        common.objReel = this.fM(reelT); common.objProrata = this.fM(prorataT);
        const att = reelT / prorataT; common.objAtt = this.fP(att); common.objAttSt = 'font-weight:700;color:' + (att >= 1 ? '#2d7a3e' : att >= 0.92 ? '#8a5a13' : '#8D1D2C');
        let resteCible = 0;
        for (let m = MI + 1; m < 12; m++) {
          resteCible += this.open().reduce((a, s2) => a + cibleDe(s2.perf[E][m]), 0);
        }
        common.objProj = this.fM(reelT + resteCible * att + (this.meta.contribOuverture || 0));
        const cumR = [], cumC = []; let ar = 0, ac = 0;
        for (let m = 0; m < 12; m++){
          ac += this.open().reduce((a, s2) => a + cibleDe(s2.perf[E][m]), 0); cumC.push(ac);
          if (m <= MI){ ar += this.sum(E, m, 'ca'); cumR.push(ar); }
        }
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
        // Le nombre de mois de RÉEL, pas les mois de budget du premier magasin
        // venu : l'étiquette disait « 2 mois » sous un réel qui en couvrait huit.
        const moisReel = M.MOIS.filter((_, m) => this.open().some(s2 => s2.perf[E][m].ca != null)).length;
        common.cumLabel = 'Budget validé ' + this.meta.exercice + ' — ' + this.open().length + ' magasins';
        common.cumReelLabel = 'Réel encodé' + (moisReel ? ' (' + moisReel + ' mois)' : '');
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
    // Le planogramme est lu dès qu'une fiche de présentation est ouverte, d'où
    // qu'elle vienne : l'assortiment ouvre la même fiche, et sans le plan elle
    // n'aurait aucun emplacement à proposer.
    if (common.isPlano || this.state.plFiche) { this.plCharge(); this.valsPlano(common); }
    if (common.isFonds) this.valsFonds(common);
    if (common.isMktCal || common.isMktCamp || common.isMktTypes) this.valsMkt(common);
    if (common.isProd) this.valsProduction(common);
    if (common.isAnalyse) { this.anOptions(); this.valsAnalyse(common); }
    if (common.isCentrale) this.valsCentrale(common);
    this.valsLacunes(common);
    if (common.isDiag) { this.coCharge(); this.valsDiag(common); }
    if (common.isSeuil) this.valsSeuil(common);
    // --- exploitation (P&L court des magasins)
    if (common.isExploit) this.valsExploitation(common);
    // --- réputation digitale (lecture paresseuse : l'écran la demande en s'ouvrant)
    if (common.isReput) { this.repCharge(); this.valsReputation(common); }
    // --- résultat du jour (lecture paresseuse, une volée d'appels par date)
    if (common.isRJour) { this.rjCharge(false); this.valsResultatJour(common); }
    // --- suivi budget magasin
    // Relu à CHAQUE ouverture de l'écran : ce tableau est la lecture du budget,
    // et l'instantané pris au chargement de la page vieillit dès qu'un mois est
    // encodé — ici ou depuis un autre poste. Une relecture, groupée, à l'entrée.
    if (common.isBudget) { this.relirePerf('budget'); this.valsBudget(common); }
    // --- encodage du budget
    // Les deux écrans du budget partagent leurs valeurs : l'encodage mensuel
    // et les réglages annuels lisent le même magasin, le même exercice et le
    // même modèle de charges. Une seule fonction, deux gabarits.
    if (common.isEncodage || common.isBudgetParam) this.valsEncodage(common);
    if (common.isBxc) this.valsBxc(common);
    if (common.isUsage) { this.usageCharge(); this.valsUsage(common); }
    if (common.isMesure) {
      // L'écran s'ouvre sur la LECTURE ; le paramétrage complet reste à un clic.
      common.mesSimple = this.state.mesSimple !== false;
      if (common.mesSimple) { this.valsMesureComp(common); }
      else { this.valsMesure(common); common.mesRetour = () => this.setState({ mesSimple: true }); }
    }
    // --- scoring produits
    if (common.isProduits) this.valsProduits(common);
    // --- marge — aussi sur le P&L magasins : la carte des ratios y est reprise
    if (common.isMarge || common.isExploit) this.valsMarge(common);
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
    // Le théorique ENCODÉ mois par mois d'abord. La répartition au prorata du
    // budget validé ne vaut que pour les magasins d'avant l'étude : un magasin
    // sans budget négocié y donnait douze zéros — c'est ce que montrait
    // Sombreffe, dont l'étude porte pourtant 672 000 €.
    const theoEncode = P.some(r => r.theo != null && r.theo > 0);
    const sais = P.map(r => (r.caT || 0) / (budgetAn || 1));
    // Un mois sans théorique encodé vaut NULL, pas zéro : « 0 € de théorique »
    // se lirait comme un objectif nul, alors que c'est une absence. Corbais
    // n'a que mai et juin — les dix autres cases doivent rester vides.
    const theoM = theoEncode ? P.map(r => (r.theo != null && r.theo > 0) ? r.theo : null)
      : (theoAn ? sais.map(w => (w > 0 ? theoAn * w : null)) : null);

    const openList = this.open();
    const theoOf = s => { const b = (D.budgets || []).find(x2 => x2.storeId === s.id);
      const Ps = s.perf[this.meta.exercice], ba = Ps.reduce((a, r) => a + (r.caT || 0), 0);
      const an = b && b.caTheoriqueAn ? b.caTheoriqueAn : null;
      const enc = Ps.some(r => r.theo != null && r.theo > 0);
      return { P: Ps, budgetAn: ba, theoAn: an || (enc ? Ps.reduce((a2, r) => a2 + (r.theo || 0), 0) : null),
        theoM: enc ? Ps.map(r => (r.theo != null && r.theo > 0) ? r.theo : null)
          : (an ? Ps.map(r => (r.caT ? an * r.caT / (ba || 1) : null)) : null) }; };
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
      theoC2 = perStore.some(x2 => x2.theoM) ? M.MOIS.map((_, i) => {
        const vs = perStore.map(x2 => (x2.theoM || [])[i]).filter(v => v != null);
        return vs.length ? vs.reduce((a, v) => a + v, 0) : null;
      }) : null;
      budgetAnC = Pc.reduce((a, r) => a + r.caT, 0);
      reelC = 0; proC = 0; for (let m = 0; m <= this.moisIdx(); m++){ reelC += Pc[m].ca; proC += Pc[m].caT; }
    }
    const theoAnC = theoC2 ? theoC2.reduce((a, v) => a + (v || 0), 0) : null;

    const mxB = Math.max(...Pc.map(r => r.caT || 0), ...(theoC2 || [0]));
    const h = v => Math.max(2, Math.round((v || 0) / mxB * 130));
    common.bBars = Pc.map((r, i) => ({
      hasTheo: !!theoC2,
      theoSt: theoC2 ? (theoC2[i] ? 'width:16px;height:' + h(theoC2[i]) + 'px;background:var(--pkg-abricot);border-radius:3px 3px 0 0'
        : 'width:16px;height:2px;background:var(--color-border-secondary)') : '',
      budSt: r.caT ? 'width:16px;height:' + h(r.caT) + 'px;background:#D8CEC2;border-radius:3px 3px 0 0'
        : 'width:16px;height:2px;background:var(--color-border-secondary)',
      reelSt: r.ca == null ? 'width:16px;height:2px;background:var(--color-border-secondary)' : 'width:16px;height:' + h(r.ca) + 'px;background:var(--color-primary);border-radius:3px 3px 0 0' }));
    common.bMoisCols = M.MOIS.map((nom, i) => ({ nom, st: i <= 6 ? 'color:var(--color-text);font-weight:500' : 'color:var(--color-text-muted)' }));
    // Zéro n'est pas une valeur ici : un mois budgété à 0 € n'existe pas en
    // boutique, c'est un mois qu'on n'a pas encodé. On l'affiche comme tel.
    const fn = v => (v == null || v === 0) ? '—' : Math.round(v).toLocaleString('fr-BE');
    common.bHasTheoChart = !!theoC2;
    common.bLigneTheo = theoC2 ? theoC2.map(v => fn(v)) : [];
    common.bTotTheo = theoC2 ? fn(theoAnC) : '—';
    common.bLigneBud = Pc.map(r => fn(r.caT));
    common.bLigneReel = Pc.map(r => fn(r.ca));
    const ecSt = v => v == null ? 'padding:9px 6px;text-align:right;white-space:nowrap;color:var(--color-text-muted)' : 'padding:9px 6px;text-align:right;white-space:nowrap;font-weight:500;color:' + col(v);
    const pctSt = v => 'font-size:11px;font-weight:400;color:' + (v == null ? 'var(--color-text-muted)' : col(v));
    // Un écart contre un mois NON ENCODÉ n'est pas un écart : « +167 353 € »
    // contre un budget absent laissait croire à une performance, alors qu'il
    // n'y avait rien à battre. Zéro compte donc comme une absence, ici aussi.
    const ecPair = (a, b) => Pc.map((r, i) => { const av = a(r, i), bv = b(r, i);
      const v = (av == null || bv == null || bv === 0) ? null : av - bv;
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
    // Ce qui a été ENCODÉ pour ce mois, s'il l'a été. À défaut, la projection
    // du taux sur le chiffre d'affaires — mais l'écran doit dire laquelle des
    // deux il montre : « frais encodés » sur une projection était un titre qui
    // mentait.
    const encDe = (b2, i, id) => (((b2 || {}).chargesMois || {})[String(i + 1)] || {})[id];
    let nEnc = 0, nProj = 0;
    const monthCharge = (d, i) => {
      if (scope === 'reseau'){
        let v = null;
        perStore.forEach(x2 => { const r = x2.P[i]; if (r.ca == null) return;
          const b2 = (D.budgets || []).find(z => z.storeId === x2.id);
          const e = d.id ? encDe(b2, i, d.id) : undefined;
          if (e != null) { v = (v || 0) + e; nEnc++; }
          else { v = (v || 0) + r.ca * (d.champReel ? r[d.champReel] : d.pctBudget) / 100; nProj++; }
        });
        return v;
      }
      const e = d.id ? encDe(bud, i, d.id) : undefined;
      if (e != null) { nEnc++; return e; }
      const r = P[i];
      if (r.ca == null) { return null; }
      nProj++;
      return r.ca * (d.champReel ? r[d.champReel] : d.pctBudget) / 100;
    };
    // Le détail des groupes de frais est REPLIÉ par défaut : neuf lignes de
    // douze mois passent avant le total et la marge, qui sont ce qu'on vient
    // lire. On l'ouvre quand on cherche d'où vient un écart.
    common.bChOuvert = !!S.bChOuvert;
    common.bChToggle = () => this.setState({ bChOuvert: !S.bChOuvert });
    // Compté APRÈS la construction des lignes : on dit combien de cases sont
    // encodées et combien restent projetées, pour qu'aucun chiffre ne se fasse
    // passer pour un relevé.
    common.bChSource = () => nEnc === 0
      ? 'Aucune charge encodée sur la période : les montants sont projetés depuis le taux du modèle appliqué au CA réel.'
      : (nProj === 0 ? 'Tous les montants viennent des charges encodées mois par mois.'
        : nEnc + ' case(s) encodée(s), ' + nProj + ' projetée(s) depuis le taux du modèle — les secondes sont une estimation, pas un relevé.');
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

  /* --- Budget × Campagnes ------------------------------------------------------
     Le calendrier des campagnes posé sur la courbe du budget, puis le détail
     magasin par magasin de la campagne regardée : ce qu'elle vise, ce qu'elle
     a donné. Les objectifs s'écrivent à la saisie, comme le budget. */
  valsBxc(common){
    const S = this.state, M = this.M;
    this.bxcCharge(false);
    const b = S.bxc || {};
    const d = b.d || {};
    common.bxcChargement = !!b.chargement && !b.d;
    common.bxcIndispo = d.indispo ? (d.raison || 'Module marketing absent') : '';
    common.bxcExo = String(S.bxcExo || this.exo());
    common.bxcExoOpts = [-1, 0, 1].map(k => ({ v: String(this.exo() + k), nom: String(this.exo() + k) + (k === 0 ? ' · en cours' : '') }));
    common.setBxcExo = e => this.setState({ bxcExo: e.target.value, bxcCamp: 0 });

    // La courbe des douze mois et les bandes de campagne ont été RETIRÉES :
    // douze barres de budget ne disaient rien de la campagne qu'on regarde, et
    // la bande servait de sélecteur là où la liste déroulante suffit. L'écran
    // répond maintenant à une seule question — cette campagne suffit-elle à
    // tenir le budget de chaque magasin.
    common.bxcCampOpts = (d.campagnes || []).map(c2 => ({ v: String(c2.id),
      nom: c2.nom + ' · ' + (c2.debut || '').slice(5) + ' → ' + (c2.fin || '').slice(5) }));
    common.bxcCampSel = String((d.campagne || {}).id || '');
    common.setBxcCamp = e => this.setState({ bxcCamp: parseInt(e.target.value, 10) || 0 });
    common.bxcCampNom = (d.campagne || {}).nom || '';
    common.bxcCampPeriode = d.campagne ? 'du ' + d.campagne.debut.split('-').reverse().join('/') + ' au ' + d.campagne.fin.split('-').reverse().join('/') : '';
    common.bxcRealiseNote = d.campagne
      ? (d.realiseDispo
        ? 'Réalisé lu jusqu’au ' + String(d.realiseJusquau || '').split('-').reverse().join('/') + ' (panel).'
        : 'Aucun réalisé : la campagne n’a pas commencé, ou le compte du panel n’est pas configuré.')
      : '';

    // Le tableau : une ligne par magasin du périmètre.
    const dr = S.bxcDraft || {};
    const cle = sid => (d.campagne || {}).id + ':' + sid;
    const num = v => { const n2 = parseFloat(String(v).replace(',', '.')); return isNaN(n2) ? 0 : n2; };
    let tB = 0, tO = 0, tR = 0, aucunR = true;
    common.bxcLignes = (d.lignes || []).map(l => {
      const saisi = dr[cle(l.shopId)];
      const o = saisi != null ? (String(saisi).trim() === '' ? null : num(saisi)) : l.objectif;
      const pct = o != null && l.budgetPeriode ? Math.round(1000 * (o / l.budgetPeriode - 1)) / 10 : null;
      const ec = o != null && l.realise != null ? l.realise - o : null;
      const att = o != null && l.realise != null && o > 0 ? Math.round(100 * l.realise / o) : null;
      tB += l.budgetPeriode || 0; tO += o || 0; tR += l.realise || 0;
      if (l.realise != null) { aucunR = false; }
      // La cible de la jauge : l'objectif saisi s'il existe, le budget de la
      // période sinon. Le trait doit répondre à « la campagne suffit-elle à
      // tenir ce qu'on attend du magasin », et c'est cela qu'on attend.
      const cible = o != null ? o : (l.budgetPeriode || null);
      const attendu = l.attendu != null ? l.attendu : null;
      const attAtt = (attendu != null && cible) ? Math.round(100 * attendu / cible) : null;
      return {
        nom: l.nom,
        budget: l.budgetPeriode != null ? this.fE(l.budgetPeriode) : '—',
        // L'effet attendu, terme par terme — un total sans ses termes ne se
        // discute pas.
        panier: l.panier != null ? this.fEd(l.panier) : '—',
        panierRepli: l.baseSource === 'repli',
        plus: l.clientsJourPlus != null
          ? (l.clientsJourPlus >= 0 ? '+' : '−') + Math.abs(l.clientsJourPlus).toLocaleString('fr-BE', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) : '—',
        // Le nombre de CLIENTS sur la période : c'est ce qu'on annonce en
        // boutique. « 145 par jour » ne se retient pas, « 4 350 en septembre »
        // si — et l'écart entre les deux colonnes est ce que la campagne doit
        // aller chercher.
        clientsA1: l.clientsA1 != null ? l.clientsA1.toLocaleString('fr-BE') : '—',
        clientsA1Note: l.clientsA1Source === 'repli' ? '3 derniers mois' : '',
        clientsPrevus: l.clientsPrevus != null ? l.clientsPrevus.toLocaleString('fr-BE') : '—',
        base: l.base != null ? this.fE(l.base) : '—',
        baseNote: l.baseSource === 'repli' ? '3 derniers mois' : '',
        gain: l.gain != null ? '+' + this.fE(l.gain) : '—',
        attendu: attendu != null ? this.fE(attendu) : '—',
        attAtt: attAtt == null ? '' : attAtt + ' %',
        attAttSt: attAtt == null ? 'color:var(--color-text-muted)'
          : (attAtt >= 100 ? 'background:rgba(45,122,62,.12);color:#2d7a3e' : 'background:rgba(141,29,44,.12);color:#8D1D2C')
            + ';font-weight:600;padding:2px 9px;border-radius:999px;font-size:10.5px',
        source: l.source === 'theorique' ? 'théorique' : (l.source === 'budget' ? '' : 'non encodé'),
        objectif: saisi != null ? saisi : (l.objectif != null ? Math.round(l.objectif) : ''),
        setObjectif: e => { const v = e.target.value;
          this.setState(s2 => ({ bxcDraft: Object.assign({}, s2.bxcDraft, { [cle(l.shopId)]: v }) }));
          this.autoEnreg('bxc' + (d.campagne || {}).id, () => this.bxcEcrire(d.campagne.id)); },
        pct: pct == null ? '' : (pct >= 0 ? '+' : '−') + String(Math.abs(pct)).replace('.', ',') + ' %',
        realise: l.realise != null ? this.fE(l.realise) : '—',
        ecart: ec == null ? '' : (ec >= 0 ? '+' : '−') + this.fE(Math.abs(ec)),
        ecartCol: ec == null ? 'var(--color-text-muted)' : (ec >= 0 ? '#2d7a3e' : '#8D1D2C'),
        atteinte: att == null ? '' : att + ' %',
        barre: att == null ? 0 : Math.max(2, Math.min(100, att)),
        barreCol: att == null ? '#DED6C9' : (att >= 100 ? '#2d7a3e' : (att >= 95 ? '#C17A2A' : '#8D1D2C')),
      };
    });
    // Les jauges : une échelle COMMUNE à tous les magasins. Cadrer chacun sur
    // son propre objectif comparerait des atteintes, pas des volumes — et le
    // réseau ne saurait plus lequel pèse.
    const ef = d.effet || {};
    const maxi = Math.max.apply(null, (d.lignes || []).map(l =>
      Math.max(l.attendu || 0, l.budgetPeriode || 0, dr[cle(l.shopId)] != null ? num(dr[cle(l.shopId)]) : (l.objectif || 0))).concat([1])) * 1.04;
    common.bxcJauges = (d.lignes || []).map(l => {
      const saisi = dr[cle(l.shopId)];
      const o = saisi != null ? (String(saisi).trim() === '' ? null : num(saisi)) : l.objectif;
      const cible = o != null ? o : (l.budgetPeriode || null);
      const pc = v => Math.max(0, Math.min(100, 100 * (v || 0) / maxi));
      return {
        nom: l.nom,
        vide: l.attendu == null,
        base: pc(l.base), gain: pc(l.gain), gaugeGauche: pc(l.base),
        obj: cible ? pc(cible) : null,
        objTxt: cible ? 'objectif ' + this.fE(cible) : '',
        detail: l.base != null && l.gain != null
          ? this.fE(l.base) + ' + ' + this.fE(l.gain) + ' = ' + this.fE(l.attendu)
          : 'effet non calculable — relevé manquant',
      };
    });
    common.bxcEffet = {
      base: ef.base != null ? this.fE(ef.base) : '—',
      gain: ef.gain != null ? '+' + this.fE(ef.gain) : '—',
      attendu: ef.attendu != null ? this.fE(ef.attendu) : '—',
      objectif: tB ? this.fE(tB) : '—',
      clientsA1: ef.clientsA1 != null ? ef.clientsA1.toLocaleString('fr-BE') : '—',
      clientsPrevus: ef.clientsPrevus != null ? ef.clientsPrevus.toLocaleString('fr-BE') : '—',
      pct: ef.pct != null ? (ef.pct >= 0 ? '+' : '−') + Math.abs(ef.pct) + ' %' : '—',
      jours: ef.jours || 0,
      note: 'Panier moyen des 3 derniers mois clos'
        + (ef.panierDu ? ' (' + this.fD(ef.panierDu) + ' → ' + this.fD(ef.panierAu) + ')' : '')
        + ', lu sur la caisse au jour le jour. Le gain attendu est ce panier multiplié par les clients par jour visés en plus, sur les '
        + (ef.jours || 0) + ' jours de la campagne — pas une prévision de saison.',
      sansBase: ef.sansBase || 0,
    };
    common.bxcTotBudget = tB ? this.fE(tB) : '—';
    common.bxcTotObjectif = tO ? this.fE(tO) : '—';
    common.bxcTotRealise = aucunR ? '—' : this.fE(tR);
    common.bxcTotEcart = (!aucunR && tO) ? ((tR - tO >= 0 ? '+' : '−') + this.fE(Math.abs(tR - tO))) : '';
    common.bxcTotEcartCol = (tR - tO) >= 0 ? '#2d7a3e' : '#8D1D2C';
    common.bxcTotPct = tO && tB ? ((tO / tB - 1) >= 0 ? '+' : '−') + this.fP(Math.abs(tO / tB - 1), 1) : '';
    common.bxcEtat = this.autoTxt('bxc' + ((d.campagne || {}).id || 0));
    common.bxcAucune = !d.campagne && !common.bxcChargement && !common.bxcIndispo;
  }

  /** Les objectifs saisis, écrits en base sans ligne de journal. */
  bxcEcrire(campagneId){
    const dr = this.state.bxcDraft || {};
    const objectifs = {};
    Object.keys(dr).forEach(k => {
      const [cid, sid] = k.split(':');
      if (String(cid) === String(campagneId)) { objectifs[sid] = dr[k]; }
    });
    if (!Object.keys(objectifs).length) { return true; }
    return this.api('PUT', '/marketing/campagnes/' + campagneId + '/objectifs?journal=0', { objectifs })
      .then(r => !(!r || r.ok === false));
  }

  /* --- encodage du budget ----------------------------------------------------- */
  valsEncodage(common){
    const S = this.state, D = this.D, M = this.M;
    const st = this.open().find(x => x.id === S.encStore) || this.open()[0];
    // L'EXERCICE encodé : celui du cockpit par défaut, mais on peut en ouvrir
    // un autre — corriger l'année passée, préparer la suivante. Les données de
    // l'exercice courant sont déjà chargées ; les autres se lisent à la
    // demande et se gardent dans un cache par année, sans toucher au reste du
    // cockpit (qui, lui, parle bien de l'exercice en cours).
    const anEnc = parseInt(S.encExo || this.exo(), 10) || this.exo();
    const enCours = anEnc === this.exo();
    const cache = (D.encAn || {})[anEnc] || null;
    if (!enCours && !cache && !this._encAnBusy) {
      this._encAnBusy = true;
      Promise.all([
        readOne('/stores/perf?granularite=mois&annees=' + (anEnc - 1) + ',' + anEnc),
        readOne('/stores/budgets?exercice=' + anEnc),
      ]).then(([pf, bs]) => {
        this.D.encAn = Object.assign({}, this.D.encAn, { [anEnc]: { perf: pf || [], budgets: bs || [] } });
        this._encAnBusy = false;
        this.setState({});
      }).catch(() => { this._encAnBusy = false; });
    }
    const vide12 = () => Array.from({ length: 12 }, () => ({ ca: null, caT: null, theo: null }));
    // Une relecture range les budgets dans le bon casier : l'exercice en cours
    // est celui du cockpit entier, les autres vivent dans leur cache.
    const poseBudgets = bs => {
      if (enCours) { this.D.budgets = bs; return; }
      this.D.encAn = Object.assign({}, this.D.encAn,
        { [anEnc]: Object.assign({}, (this.D.encAn || {})[anEnc] || { perf: [] }, { budgets: bs }) });
    };
    const bud = (enCours ? (D.budgets || []) : ((cache && cache.budgets) || []))
      .find(b => b.storeId === st.id) || { charges: [] };
    const P = enCours ? st.perf[this.exo()]
      : (cache ? Array.from({ length: 12 }, (_, i) => {
          const r = (cache.perf || []).find(x2 => String(x2.storeId) === String(st.id)
            && parseInt(x2.annee, 10) === anEnc && parseInt(x2.mois, 10) === i + 1) || {};
          return { ca: r.ca != null ? r.ca : null, caT: r.caBudget != null ? r.caBudget : null,
            theo: r.caTheorique != null ? r.caTheorique : null };
        }) : vide12());
    common.encChargement = !enCours && !cache;
    const cleD = st.id + '@' + anEnc;
    const d = S.encDraft[cleD] || {};
    const val = (k, def) => d[k] != null ? d[k] : def;
    // Chaque saisie part en base après une pause de frappe. Le bouton
    // « Enregistrer le budget » reste — il journalise et confirme —, mais plus
    // personne ne perd une grille remplie faute d'avoir cliqué : c'est arrivé
    // sur douze mois de budget, jamais écrits.
    const set = k => e => { const v = e.target.value;
      this.setState(s2 => ({ encDraft: Object.assign({}, s2.encDraft, { [cleD]: Object.assign({}, s2.encDraft[cleD], { [k]: v }) }) }));
      this.encAuto(st.id, anEnc); };
    const num = v => { const n = parseFloat(String(v).replace(',', '.')); return isNaN(n) ? 0 : n; };

    common.encStore = st.id; common.setEncStore = e => this.setState({ encStore: e.target.value });
    common.encStoreOpts = this.open().map(x => ({ id: x.id, nom: x.nom }));
    common.encMeta = st.code + ' · ' + st.zone + ' · franchisé ' + st.fr;
    common.encExercice = String(anEnc);
    // Un an en arrière (corriger), trois en avant (préparer la montée en
    // régime que l'étude de marché vient d'écrire).
    common.encExoOpts = [-1, 0, 1, 2, 3].map(k => {
      const y = this.exo() + k;
      return { v: String(y), nom: String(y) + (k === 0 ? ' · en cours' : '') };
    });
    common.encExoSel = String(anEnc);
    common.setEncExo = e => this.setState({ encExo: e.target.value });

    const budAnRef = P.reduce((a, r) => a + (r.caT || 0), 0) || 1;
    const theoAnRef = bud.caTheoriqueAn || 0;
    const mois = M.MOIS.map((nom, i) => ({ nom,
      // Sans budget encodé, `caT` est nul : pré-remplir « 0 » donne douze
      // champs à zéro qu'un simple clic sur Enregistrer fige en base — le
      // budget paraît enregistré et vaut zéro partout. Champ VIDE à la place.
      valeur: val('ca' + i, P[i].caT != null ? Math.round(P[i].caT) : ''), set: set('ca' + i),
      // Le théorique ENCODÉ d'abord (l'étude l'a écrit mois par mois) ; la
      // déduction depuis le total annuel ne sert que pour les magasins d'avant.
      theo: val('th' + i, P[i].theo != null && P[i].theo > 0 ? Math.round(P[i].theo)
        : (theoAnRef ? Math.round(theoAnRef * (P[i].caT || 0) / budAnRef) : '')), setTheo: set('th' + i) }));
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
    // L'étude ENREGISTRÉE d'abord, la déduction ensuite : le potentiel, la
    // montée en régime et l'année d'exploitation sont écrits en base, mais
    // l'écran les recalculait ou les inventait (70/80/90, « année 1 »). Un
    // rechargement effaçait donc à l'œil une étude bien présente — et le
    // bouton refusait d'enregistrer, faute de potentiel.
    const baseTheo = val('theoBase', em.potentielMaturite != null && em.potentielMaturite > 0
      ? Math.round(em.potentielMaturite)
      : (theoAnRef ? Math.round(theoAnRef / 0.7) : ''));
    common.encTheoBase = baseTheo; common.setEncTheoBase = set('theoBase');
    const mr = em.monteeEnRegime || {};
    const rampDef = { a1: num(mr.a1) || 70, a2: num(mr.a2) || 80, a3: num(mr.a3) || 90 };
    const ramp = { a1: num(val('ramp1', rampDef.a1)), a2: num(val('ramp2', rampDef.a2)), a3: num(val('ramp3', rampDef.a3)) };
    common.encRamp = [
      { k: 'Année 1', valeur: val('ramp1', rampDef.a1), set: set('ramp1') },
      { k: 'Année 2', valeur: val('ramp2', rampDef.a2), set: set('ramp2') },
      { k: 'Année 3', valeur: val('ramp3', rampDef.a3), set: set('ramp3') }];
    const anneeEx = String(val('annee', em.anneeExploitation ? String(em.anneeExploitation) : '1'));
    common.encAnnee = anneeEx; common.setEncAnnee = set('annee');
    common.encAnneeOpts = [{ v: '1', nom: 'Année 1 — ouverture' }, { v: '2', nom: 'Année 2' }, { v: '3', nom: 'Année 3' }, { v: '4', nom: 'Régime établi (100 %)' }];
    const coef = anneeEx === '1' ? ramp.a1 : anneeEx === '2' ? ramp.a2 : anneeEx === '3' ? ramp.a3 : 100;
    const theoExercice = num(baseTheo) * coef / 100;

    // ── La PROJECTION : le même potentiel vu sur quatre exercices.
    //    L'année d'exploitation avance d'un cran par exercice jusqu'au régime
    //    établi ; la variation par mois du magasin reste la même. C'est un
    //    APERÇU : seul l'exercice courant est encodé en base, et changer
    //    d'exercice dans la liste n'écrit rien.
    const anNum = parseInt(anneeEx, 10) || 1;
    const coefDe = an => an === 1 ? ramp.a1 : an === 2 ? ramp.a2 : an === 3 ? ramp.a3 : 100;
    const projs = [0, 1, 2, 3].map(k => {
      const an = Math.min(4, anNum + k);
      const c2 = coefDe(an);
      return { k, exercice: this.exo() + k, annee: an, coef: c2, ca: num(baseTheo) * c2 / 100 };
    });
    const iProj = Math.max(0, Math.min(3, parseInt(S.encProj || 0, 10) || 0));
    const proj = projs[iProj];
    const nomAn = p2 => p2.annee >= 4 ? 'régime établi' : 'année ' + p2.annee;
    common.encProjOpts = projs.map(p2 => ({ v: String(p2.k),
      nom: p2.exercice + ' · ' + nomAn(p2) + ' — ' + String(p2.coef).replace('.', ',') + ' %' }));
    common.encProjSel = String(iProj);
    common.setEncProj = e => this.setState({ encProj: e.target.value });
    common.encProjLignes = projs.map(p2 => ({ exercice: String(p2.exercice), annee: nomAn(p2),
      coef: String(p2.coef).replace('.', ',') + ' %', ca: this.fE(p2.ca),
      courant: p2.k === 0, choisi: p2.k === iProj }));
    common.encProjNote = iProj === 0
      ? 'Exercice en cours : c’est celui qui est encodé et qui sert de référence au suivi.'
      : 'Projection ' + proj.exercice + ' — rien n’est écrit : seul l’exercice ' + this.exo() + ' est encodé.';

    common.encCoef = String(proj.coef).replace('.', ',') + ' % du potentiel à maturité';
    common.encTheoExercice = this.fE(proj.ca);
    common.encRampNote = 'Potentiel à maturité ' + this.fE(num(baseTheo)) + ' × ' + String(proj.coef).replace('.', ',')
        + ' % = CA théorique ' + proj.exercice + '.';

    // La variation par mois est PROPRE AU MAGASIN et enregistrée telle quelle
    // (colonne saisonnalite de son budget). On la relit d'abord : sans cela,
    // l'écran la recalculait depuis les CA théoriques mensuels — mêmes
    // chiffres quand ils existent, mais des cases à zéro pour un magasin dont
    // l'étude est encodée alors que les mois ne le sont pas encore.
    const saisEnr = Array.isArray(em.saisonnalite) ? em.saisonnalite : [];
    const aSaisEnr = saisEnr.some(w => num(w) > 0);
    // La courbe du RÉSEAU sert de repli avant la déduction : celle-ci répartit
    // au prorata des mois déjà budgétés et donne n'importe quoi quand il n'y
    // en a que deux (0 · 0 · 51,7 · 48,3 · 0 …).
    const saisRes = (bud.saisonnaliteReseau || []).map(num);
    const aSaisRes = saisRes.length === 12 && saisRes.some(w => w > 0);
    const poidsDef = M.MOIS.map((_, i) => aSaisEnr
      ? num(saisEnr[i] || 0)
      : (aSaisRes ? saisRes[i] : 100 * ((P[i] || {}).caT || 0) / budAnRef));
    common.encSaisSource = aSaisEnr ? 'magasin' : (aSaisRes ? 'reseau' : 'deduit');
    common.encSaisSourceTxt = aSaisEnr
      ? 'Courbe propre à ce magasin.'
      : (aSaisRes
        ? 'Aucune courbe encodée pour ce magasin : celle du réseau est proposée. Modifier une case la rend propre au magasin.'
        : 'Aucune courbe encodée, ni pour ce magasin ni pour le réseau : les parts sont déduites des mois déjà budgétés.');
    let poidsTot = 0;
    // Le point, pas la virgule : ces cases sont des <input type="number">, et
    // une valeur « 9,0 » y est invalide — le navigateur affiche alors une case
    // VIDE alors que la valeur existe. C'est ce qui donnait douze cases vides
    // à la relecture d'une étude encodée.
    common.encSais = M.MOIS.map((nom, i) => { const v = val('sais' + i, poidsDef[i].toFixed(1));
      poidsTot += num(v);
      return { nom, valeur: v, set: set('sais' + i), montant: this.fE(proj.ca * num(v) / 100) }; });
    common.encSaisTot = String(poidsTot.toFixed(1)).replace('.', ',') + ' %';
    common.encSaisTotSt = 'font-size:13px;font-weight:500;color:' + (Math.abs(poidsTot - 100) < 0.6 ? '#2d7a3e' : '#8D1D2C');
    common.encLisser = () => { const upd = {};
      M.MOIS.forEach((_, i) => { const w = num(val('sais' + i, poidsDef[i])); upd['th' + i] = Math.round(theoExercice * w / (poidsTot || 100)); });
      this.setState(s2 => ({ encDraft: Object.assign({}, s2.encDraft, { [cleD]: Object.assign({}, s2.encDraft[cleD], upd) }) }));
      this.notify('CA théorique lissé sur les 12 mois — ' + st.nom); };

    const anxNom = val('anxNom', (em.annexe && em.annexe.nom) || '');
    const anxTaille = val('anxTaille', (em.annexe && em.annexe.taille) || '');
    common.encAnxNom = anxNom;
    common.encAnxMeta = anxNom ? [anxTaille, 'ajoutée le ' + this.fD(M.TODAY)].filter(Boolean).join(' · ') : 'Aucun document joint';
    common.encHasAnx = !!anxNom;
    common.encAnxUrl = val('anxUrl', (em.annexe && em.annexe.url) || ''); common.setEncAnxUrl = set('anxUrl');
    common.encAnxPick = e => { const f = e.target.files && e.target.files[0]; if (!f) return;
      const ko = f.size > 1048576 ? (f.size / 1048576).toFixed(1).replace('.', ',') + ' Mo' : Math.max(1, Math.round(f.size / 1024)) + ' Ko';
      this.setState(s2 => ({ encDraft: Object.assign({}, s2.encDraft, { [cleD]: Object.assign({}, s2.encDraft[cleD], { anxNom: f.name, anxTaille: ko }) }) }));
      this.log('Budget', st.nom, 'Annexe étude de marché ajoutée : ' + f.name + ' (' + ko + ')');
      this.notify('Annexe jointe — ' + f.name); };
    common.encAnxDel = () => { this.setState(s2 => ({ encDraft: Object.assign({}, s2.encDraft, { [cleD]: Object.assign({}, s2.encDraft[cleD], { anxNom: '', anxTaille: '' }) }) }));
      this.notify('Annexe retirée'); };

    common.encEtudeDate = val('etudeDate', em.date || ''); common.setEncEtudeDate = set('etudeDate');
    common.encEtudeSrc = val('etudeSrc', em.source || ''); common.setEncEtudeSrc = set('etudeSrc');
    common.encMenages = val('menages', em.potentielMenages || ''); common.setEncMenages = set('menages');

    let pctTot = 0, pctTotT = 0;
    const pc = v => String(num(v).toFixed(1)).replace('.', ',') + ' %';

    // --- charges : des catégories, des lignes, et un pourcentage qui se lit en
    // euros sur TROIS bases — le CA validé (le budget négocié), le CA théorique
    // (l'étude de marché) et le CA RÉEL déjà encaissé. Un taux de 7 % ne veut
    // pas dire la même somme selon celle qu'on regarde, et c'est l'écart entre
    // la troisième et la première qui dit si la charge tient.
    const reelMois = P.filter(r => r.ca != null && r.ca > 0);
    const caReel = reelMois.reduce((a, r) => a + (r.ca || 0), 0);
    common.encReelTot = caReel ? this.fE(caReel) : '—';
    common.encReelMois = reelMois.length;
    common.encReelNote = reelMois.length
      ? 'Réel encaissé sur ' + reelMois.length + ' mois — les euros de la colonne « réel » portent sur cette période, pas sur l’année.'
      : 'Aucun mois réel encaissé pour l’instant : la colonne « réel » reste vide plutôt que d’afficher zéro.';

    // La liste vit dans l'état pendant la saisie : ajouter une ligne ne doit
    // pas passer par le serveur, et le brouillon garde ce qui a été tapé.
    // Les taux valent pour TOUT le réseau : un loyer à 7 % est le même à
    // Corbais et à Halle, seul le chiffre d'affaires qui les traduit en euros
    // change. La grille éditée ici est donc le modèle réseau, pas une copie du
    // magasin regardé — c'est ce que dit le bandeau au-dessus du tableau.
    const modele = ((D.budgets || [])[0] || {}).modele || [];
    const depuisModele = () => {
      const l = [];
      modele.forEach(cat => (cat.lignes || []).forEach(x => l.push({
        id: x.id || '',
        categorie: cat.nom || '', poste: x.poste || '', description: x.description || '',
        gestion: x.gestion || '', pcmn: x.pcmn || '', levier: '',
        pctBudget: x.pct != null ? x.pct : 0,
        pctTheorique: x.pctTheo != null ? x.pctTheo : (x.pct != null ? x.pct : 0),
        champReel: null })));
      return l;
    };
    const depuisBase = () => (bud.charges || []).map(c2 => ({
      categorie: c2.categorie || 'Charges d’exploitation', poste: c2.poste || '',
      description: c2.description || '', gestion: c2.gestion || '', pcmn: c2.pcmn || '',
      levier: c2.levier || '', pctBudget: c2.pctBudget, pctTheorique: c2.pctTheorique,
      champReel: c2.champReel || null }));
    const lignes = d.lignes ? d.lignes : (modele.length ? depuisModele() : depuisBase());
    const poseLignes = l => this.setState(s2 => ({ encDraft: Object.assign({}, s2.encDraft,
      { [cleD]: Object.assign({}, s2.encDraft[cleD], { lignes: l }) }) }));
    // Un taux du MODÈLE réseau : même pause de frappe, mais il part dans le
    // réglage réseau, pas dans le budget du magasin affiché.
    const setR = k => e => { const v = e.target.value;
      this.setState(s2 => ({ encDraft: Object.assign({}, s2.encDraft,
        { [cleD]: Object.assign({}, s2.encDraft[cleD], { [k]: v }) }) }));
      this.autoEnreg('chargesReseau', () => (this._chrAuto ? this._chrAuto() : true)); };
    const setL = (i, k) => e => { const v = e.target.value; const l = lignes.slice();
      l[i] = Object.assign({}, l[i], { [k]: v }); poseLignes(l);
      this.autoEnreg('chargesReseau', () => (this._chrAuto ? this._chrAuto() : true)); };

    common.encModeleDispo = false;
    common.encReseauNote = 'Ces postes et ces taux valent pour TOUT le réseau : les modifier ici les change '
      + 'pour les ' + this.open().length + ' magasins. Ce qui diffère d’un magasin à l’autre, ce sont les '
      + 'euros — le même taux appliqué à son chiffre d’affaires.';
    common.encChargesModifie = !!d.lignes || !!d.paliers;
    // Enregistrement du MODÈLE, distinct du budget du magasin : on ne veut pas
    // qu'ajuster un taux réseau oblige à réenregistrer douze mois de CA.
    // Le modèle vaut pour TOUT le réseau : il s'écrit à la frappe comme le
    // reste, mais sans relâcher le brouillon — reprendre la réponse du serveur
    // au milieu d'une saisie ferait sauter le curseur d'une ligne à l'autre.
    // Le bouton, lui, relit la base et journalise.
    this._chrAuto = () => {
      const cats = [];
      lignes.forEach((c3, i) => {
        const nom = c3.categorie || 'Sans catégorie';
        let cat = cats.find(z => z.nom === nom);
        if (!cat) { cat = { nom, lignes: [] }; cats.push(cat); }
        cat.lignes.push({ id: c3.id || '', poste: c3.poste || '', description: c3.description || '',
          gestion: c3.gestion || '', pcmn: c3.pcmn || '',
          pct: num(val('ch' + i, c3.pctBudget)),
          pctTheo: num(val('cht' + i, c3.pctTheorique != null ? c3.pctTheorique : c3.pctBudget)) });
      });
      return this.api('PUT', '/parametres/budgetCharges', { valeur: { categories: cats, paliers: paliersSrc } })
        .then(r => !(!r || r.ok === false));
    };
    common.encChargesAuto = this.autoTxt('chargesReseau');
    common.encChargesSave = () => {
      const cats = [];
      lignes.forEach((c3, i) => {
        const nom = c3.categorie || 'Sans catégorie';
        let cat = cats.find(z => z.nom === nom);
        if (!cat) { cat = { nom, lignes: [] }; cats.push(cat); }
        cat.lignes.push({ id: c3.id || '', poste: c3.poste || '', description: c3.description || '',
          gestion: c3.gestion || '', pcmn: c3.pcmn || '',
          pct: num(val('ch' + i, c3.pctBudget)),
          pctTheo: num(val('cht' + i, c3.pctTheorique != null ? c3.pctTheorique : c3.pctBudget)) });
      });
      const vides = lignes.filter(c3 => !String(c3.poste || '').trim()).length;
      if (vides) { this.notify(vides + ' poste(s) sans libellé ne seront pas enregistrés.'); }
      this.api('PUT', '/parametres/budgetCharges', { valeur: { categories: cats, paliers: paliersSrc } }).then(r => {
        if (!r || r.ok === false) { return; }
        this.notify('Modèle de charges du réseau enregistré — ' + (r.postes != null ? r.postes + ' poste(s)' : ''));
        this.log('Budget', null, 'Modèle de charges du réseau modifié depuis l’encodage');
        return readOne('/stores/budgets?exercice=' + anEnc).then(bs => {
          if (bs) { poseBudgets(bs); }
          // Le brouillon est relâché : l'écran repart de ce que le serveur a
          // gardé, sinon on continuerait à regarder sa propre saisie.
          this.setState(s2 => ({ encDraft: Object.assign({}, s2.encDraft,
            { [cleD]: Object.assign({}, s2.encDraft[cleD], { lignes: null, paliers: null }) }) }));
        });
      });
    };
    common.encLigneAdd = cat => () => { const l = lignes.slice();
      l.push({ categorie: cat || 'Nouvelle catégorie', poste: '', description: '', gestion: '',
        pcmn: '', levier: '', pctBudget: 0, pctTheorique: 0, champReel: null });
      poseLignes(l); };
    common.encCatAdd = () => { const l = lignes.slice();
      l.push({ categorie: 'Nouvelle catégorie', poste: '', description: '', gestion: '',
        pcmn: '', levier: '', pctBudget: 0, pctTheorique: 0, champReel: null });
      poseLignes(l); };

    // Groupement par catégorie, dans l'ordre d'apparition : la première ligne
    // d'une catégorie décide de sa place, ce qui suit s'y range.
    const ordre = [], parCat = {};
    lignes.forEach((c2, i) => { const cat = c2.categorie || 'Sans catégorie';
      if (!parCat[cat]) { parCat[cat] = []; ordre.push(cat); }
      parCat[cat].push({ c: c2, i }); });

    const ligneVals = ({ c: c2, i }) => {
      const v = val('ch' + i, c2.pctBudget), vt = val('cht' + i, c2.pctTheorique != null ? c2.pctTheorique : c2.pctBudget);
      pctTot += num(v); pctTotT += num(vt);
      const ec = num(v) - num(vt);
      return { i, nom: c2.poste, lev: c2.levier,
        description: c2.description || '', gestion: c2.gestion || '', pcmn: c2.pcmn || '',
        setNom: setL(i, 'poste'), setDesc: setL(i, 'description'),
        setGestion: setL(i, 'gestion'), setPcmn: setL(i, 'pcmn'),
        valeur: v, set: setR('ch' + i), valeurT: vt, setT: setR('cht' + i),
        montant: this.fE(caTot * num(v) / 100), montantT: this.fE(theoTot * num(vt) / 100),
        // Le réel ne s'invente pas : sans mois encaissé, la case reste vide.
        montantR: caReel ? this.fE(caReel * num(v) / 100) : '—',
        ecart: (ec >= 0 ? '+' : '−') + pc(Math.abs(ec)).replace(' %', ' pt'),
        ecartSt: 'padding:9px 0 9px 6px;text-align:right;white-space:nowrap;font-weight:500;color:' + (Math.abs(ec) < 0.05 ? 'var(--color-text-muted)' : ec > 0 ? '#8D1D2C' : '#2d7a3e'),
        retirer: () => { const l = lignes.slice(); l.splice(i, 1); poseLignes(l); },
        monter: bougePoste(i, -1), descendre: bougePoste(i, 1),
      };
    };
    // --- remonter, descendre, déplacer.
    //
    // L'ordre des catégories EST la lecture du compte de résultat : le coût
    // matière avant la marge brute, l'occupation après. On le change donc dans
    // l'écran, par blocs — une catégorie emporte ses postes, et les étapes
    // ancrées sur elle suivent puisqu'elles se rattachent par son nom.
    const blocs = () => ordre.map(cat => ({ cat, items: lignes.filter(c3 => (c3.categorie || 'Sans catégorie') === cat) }));
    const reposeBlocs = bs => poseLignes(bs.reduce((a, b2) => a.concat(b2.items), []));
    const bougeCat = (cat, dir) => () => {
      const bs = blocs();
      const i = bs.findIndex(b2 => b2.cat === cat);
      const j = i + dir;
      if (i < 0 || j < 0 || j >= bs.length) { return; }
      const t = bs[i]; bs[i] = bs[j]; bs[j] = t;
      reposeBlocs(bs);
    };
    const deposeCat = cat => e => {
      e.preventDefault();
      const pris = e.dataTransfer ? e.dataTransfer.getData('text/plain') : '';
      if (!pris || pris === cat) { return; }
      const bs = blocs();
      const i = bs.findIndex(b2 => b2.cat === pris);
      const j = bs.findIndex(b2 => b2.cat === cat);
      if (i < 0 || j < 0) { return; }
      const [b3] = bs.splice(i, 1);
      bs.splice(j, 0, b3);
      reposeBlocs(bs);
      this.notify('« ' + pris + ' » déplacée avant « ' + cat + ' »');
    };
    // Un poste se déplace DANS sa catégorie : le sortir de son groupe par une
    // flèche serait un geste qu'on ferait sans le vouloir.
    const bougePoste = (i, dir) => () => {
      const cat = (lignes[i] || {}).categorie || 'Sans catégorie';
      const idx = lignes.map((c3, k) => ({ c3, k })).filter(o => (o.c3.categorie || 'Sans catégorie') === cat).map(o => o.k);
      const pos = idx.indexOf(i);
      const cible = idx[pos + dir];
      if (pos < 0 || cible == null) { return; }
      const l = lignes.slice();
      const t = l[i]; l[i] = l[cible]; l[cible] = t;
      poseLignes(l);
    };

    const sommeCat = {};
    const catsBrut = ordre.map((cat, iCat) => {
      const rows = parCat[cat].map(ligneVals);
      const somme = rows.reduce((a, r) => a + num(r.valeur), 0);
      const sommeT = rows.reduce((a, r) => a + num(r.valeurT), 0);
      sommeCat[cat] = { pct: somme, pctT: sommeT };
      return { nom: cat, lignes: rows, total: pc(somme), totalE: this.fE(caTot * somme / 100),
        renommer: e => { const nv = e.target.value; const l = lignes.map(c3 =>
            (c3.categorie || 'Sans catégorie') === cat ? Object.assign({}, c3, { categorie: nv }) : c3);
          poseLignes(l); },
        monter: iCat > 0 ? bougeCat(cat, -1) : null,
        descendre: iCat < ordre.length - 1 ? bougeCat(cat, 1) : null,
        prendre: e => { try { e.dataTransfer.setData('text/plain', cat); e.dataTransfer.effectAllowed = 'move'; } catch (e2) { /* navigateur sans DnD */ } },
        deposer: deposeCat(cat),
        ajouter: common.encLigneAdd(cat) };
    });

    // --- étapes intermédiaires : « marge brute = CA − coût matière ».
    //
    // Une étape ne porte aucun montant propre : elle NOMME une soustraction
    // entre deux valeurs déjà là (le CA, le total d'une catégorie, une étape
    // précédente). Elle se recalcule donc toute seule quand un taux bouge —
    // c'est ce qui la distingue d'une ligne qu'on saisirait à la main et qui
    // se contredirait au premier ajustement.
    const paliersSrc = d.paliers ? d.paliers
      : (((D.budgets || [])[0] || {}).paliers || []).map(z => Object.assign({}, z));
    const posePaliers = pl => this.setState(s2 => ({ encDraft: Object.assign({}, s2.encDraft,
      { [cleD]: Object.assign({}, s2.encDraft[cleD], { paliers: pl }) }) }));

    // Les valeurs auxquelles une étape peut se référer, dans l'ordre où elles
    // apparaissent : on ne propose pas une étape qui n'existe pas encore.
    const valeursAvant = (iPal) => {
      const opts = [{ v: 'ca', nom: 'Chiffre d’affaires (100 %)' }];
      ordre.forEach(cat => opts.push({ v: 'cat:' + cat, nom: 'Total ' + cat }));
      paliersSrc.slice(0, iPal).forEach(z => { if (z.nom) { opts.push({ v: 'pal:' + z.nom, nom: z.nom }); } });
      return opts;
    };
    const valeurPct = (cle, jusqu) => {
      if (!cle) { return 0; }
      if (cle === 'ca') { return 100; }
      if (cle.indexOf('cat:') === 0) { return (sommeCat[cle.slice(4)] || {}).pct || 0; }
      if (cle.indexOf('pal:') === 0) {
        const k = paliersSrc.findIndex(z => z.nom === cle.slice(4));
        return (k >= 0 && k < jusqu) ? palPct(k) : 0;
      }
      return 0;
    };
    const valeurPctT = (cle, jusqu) => {
      if (!cle) { return 0; }
      if (cle === 'ca') { return 100; }
      if (cle.indexOf('cat:') === 0) { return (sommeCat[cle.slice(4)] || {}).pctT || 0; }
      if (cle.indexOf('pal:') === 0) {
        const k = paliersSrc.findIndex(z => z.nom === cle.slice(4));
        return (k >= 0 && k < jusqu) ? palPctT(k) : 0;
      }
      return 0;
    };
    function palPct(i2){ const z = paliersSrc[i2] || {}; return valeurPct(z.gauche, i2) - valeurPct(z.droite, i2); }
    function palPctT(i2){ const z = paliersSrc[i2] || {}; return valeurPctT(z.gauche, i2) - valeurPctT(z.droite, i2); }

    const setPal = (i2, k) => e => { const v = e.target.value; const pl = paliersSrc.slice();
      pl[i2] = Object.assign({}, pl[i2], { [k]: v }); posePaliers(pl); };
    const palVals = (z, i2) => {
      const p2 = palPct(i2), pT = palPctT(i2);
      const opts = valeursAvant(i2);
      const marque = v => opts.map(o => Object.assign({}, o, { on: o.v === v }));
      return { nom: z.nom || '', setNom: setPal(i2, 'nom'),
        gauche: marque(z.gauche || 'ca'), droite: marque(z.droite || ''),
        setGauche: setPal(i2, 'gauche'), setDroite: setPal(i2, 'droite'),
        formule: (opts.find(o => o.v === (z.gauche || 'ca')) || {}).nom + ' − '
          + ((opts.find(o => o.v === z.droite) || {}).nom || '(rien)'),
        pct: pc(p2), pctT: pc(pT),
        montant: this.fE(caTot * p2 / 100), montantT: this.fE(theoTot * pT / 100),
        montantR: caReel ? this.fE(caReel * p2 / 100) : '—',
        col: p2 < 0 ? '#8D1D2C' : '#2d7a3e',
        retirer: () => { const pl = paliersSrc.slice(); pl.splice(i2, 1); posePaliers(pl); } };
    };
    common.encCats = catsBrut.map(cat => Object.assign({}, cat, {
      // Les étapes se posent APRÈS une catégorie : c'est là qu'on trace le
      // trait dans un compte de résultat.
      paliers: paliersSrc.map((z, i2) => ({ z, i2 })).filter(o => (o.z.apres || '') === cat.nom)
        .map(o => palVals(o.z, o.i2)),
      ajouterPalier: () => { const pl = paliersSrc.slice();
        pl.push({ nom: 'Nouvelle étape', gauche: 'ca', droite: 'cat:' + cat.nom, apres: cat.nom });
        posePaliers(pl); },
    }));
    // Une étape dont la catégorie d'ancrage a été renommée ou retirée n'est pas
    // perdue : elle se range à la fin, visible, pour être replacée.
    const ancres = ordre.slice();
    common.encPaliersOrphelins = paliersSrc.map((z, i2) => ({ z, i2 }))
      .filter(o => ancres.indexOf(o.z.apres || '') < 0).map(o => palVals(o.z, o.i2));
    // Compatibilité : l'enregistrement et les totaux lisent encore une liste à
    // plat, c'est la même chose vue autrement.
    common.encCharges = common.encCats.reduce((a, c3) => a.concat(c3.lignes), []);
    common.encChargesVide = !common.encCharges.length;
    common.encPctTot = pc(pctTot); common.encPctTotT = pc(pctTotT);
    common.encChTot = this.fE(caTot * pctTot / 100); common.encChTotT = this.fE(theoTot * pctTotT / 100);
    // Ce que les charges pèsent sur ce qui est RÉELLEMENT rentré : sans mois
    // encaissé, la case reste vide — un zéro se lirait comme « rien à payer ».
    common.encChTotR = caReel ? this.fE(caReel * pctTot / 100) : '—';
    const mgPct = 100 - pctTot, mgPctT = 100 - pctTotT;
    common.encMarge = this.fE(caTot * mgPct / 100);
    common.encMargePct = pc(mgPct) + ' du CA budgété';
    common.encMargeSt = 'font-size:24px;font-weight:500;line-height:1.1;color:' + (mgPct <= 0 ? '#8D1D2C' : mgPct < 12 ? '#8a5a13' : '#2d7a3e');
    common.encMargeT = theoTot ? this.fE(theoTot * mgPctT / 100) : '—';
    common.encMargePctT = theoTot ? pc(mgPctT) + ' du CA théorique' : 'CA théorique non renseigné';
    common.encAlerte = pctTot > 100 ? 'La somme des charges validées dépasse 100 % du CA : le budget est déficitaire.' : false;

    // --- les charges du MOIS : ce qui est réellement sorti, poste par poste.
    //
    // Le modèle donne le taux attendu ; il ne dit pas ce qui a été dépensé. Les
    // charges s'encodent donc chaque mois, magasin par magasin, et c'est cette
    // saisie que le suivi compare au budget — un pourcentage appliqué au CA
    // n'est pas un relevé, c'est une projection.
    const moisIdx = (() => { const m2 = +String(val('chMois', '')) || 0;
      if (m2 >= 1 && m2 <= 12) { return m2; }
      // Par défaut le dernier mois qui a du réel : c'est celui qu'on encode.
      let d2 = 1; P.forEach((r, i) => { if (r.ca != null && r.ca > 0) { d2 = i + 1; } });
      return d2; })();
    common.encChMois = String(moisIdx);
    // Le mois porte l'exercice REGARDÉ : « Jan 2026 » sur un écran ouvert en
    // 2027 laissait croire qu'on encodait l'année passée.
    common.encChMoisOpts = M.MOIS.map((nom, i) => ({ v: String(i + 1), nom: nom + ' ' + anEnc,
      on: i + 1 === moisIdx }));
    common.setEncChMois = set('chMois');
    // Les charges se saisissent pour TOUS les magasins d'un coup : c'est un
    // geste mensuel, et ouvrir quatre fois le même écran pour taper quatre
    // colonnes est le meilleur moyen d'en oublier une. Une colonne par
    // boutique, une ligne par poste.
    const magasins = this.open();
    const caMoisDe = (x2) => { const r = (x2.perf[this.meta.exercice] || [])[moisIdx - 1] || {};
      return { reel: r.ca, budget: r.caT }; };
    common.encChMagasins = magasins.map(x2 => { const ca = caMoisDe(x2);
      return { id: x2.id, nom: x2.nom,
        ca: ca.reel != null ? this.fE(ca.reel) : (ca.budget ? this.fE(ca.budget) + ' (budget)' : '—'),
        sansCa: ca.reel == null && !ca.budget }; });

    const budDe = id2 => (D.budgets || []).find(z => z.storeId === id2) || {};
    const encDe = (id2, poste) => (((budDe(id2).chargesMois || {})[String(moisIdx)]) || {})[poste];
    // Le brouillon est indexé magasin + mois + poste : on peut saisir les
    // quatre colonnes avant d'enregistrer, sans que l'une écrase l'autre.
    const cleCh = (idMag, poste) => 'chm' + moisIdx + ':' + idMag + ':' + poste;

    // Une case de charge écrit les charges du mois, pas le budget : même
    // pause de frappe, autre destination.
    const setCh = k => e => { const v = e.target.value;
      this.setState(s2 => ({ encDraft: Object.assign({}, s2.encDraft,
        { [cleD]: Object.assign({}, s2.encDraft[cleD], { [k]: v }) }) }));
      this.autoEnreg('charges', () => (this._chAuto ? this._chAuto() : true)); };

    let totAttendu = 0, nSaisis = 0, nCases = 0;
    const totMag = {}, nMag = {};
    magasins.forEach(x2 => { totMag[x2.id] = 0; nMag[x2.id] = 0; });
    common.encChLignes = common.encCharges.map(c3 => {
      const src = lignes[c3.i] || {};
      const id = src.id || '';
      // Le taux vient de « Répartition des charges » : celui du budget quand il
      // est renseigné, sinon celui de l'étude. Sans ce repli, un poste dont
      // seul le taux théorique est posé (la matière première, à 38 %)
      // affichait « 0 % » et « attendu — » : le modèle disait quelque chose,
      // la saisie du mois n'en tenait pas compte. La provenance est dite.
      const tauxV = num(c3.valeur), tauxT = num(c3.valeurT);
      const taux = tauxV > 0 ? tauxV : tauxT;
      const cells = magasins.map(x2 => {
        const ca = caMoisDe(x2);
        const base = ca.reel != null ? ca.reel : (ca.budget || 0);
        const attendu = base * taux / 100;
        const brut = d[cleCh(x2.id, id)];
        const stock = encDe(x2.id, id);
        const enc = brut != null ? brut : (stock != null ? String(stock) : '');
        nCases++;
        if (String(enc).trim() !== '') { nSaisis++; totMag[x2.id] += num(enc); nMag[x2.id]++; }
        totAttendu += attendu;
        const ec = String(enc).trim() === '' ? null : num(enc) - attendu;
        return { magasin: x2.id, valeur: enc, set: setCh(cleCh(x2.id, id)),
          attendu: attendu ? this.fE(attendu) : '—',
          ecart: ec == null ? '' : (ec >= 0 ? '+' : '−') + this.fE(Math.abs(ec)),
          ecartCol: ec == null ? 'var(--color-text-muted)' : (ec > 0 ? '#8D1D2C' : '#2d7a3e') };
      });
      return { id, nom: c3.nom, categorie: src.categorie || '',
        pct: String(taux).replace('.', ','),
        pctSrc: tauxV > 0 ? '' : (tauxT > 0 ? 'théorique' : 'non défini'), cells };
    });
    common.encChSansId = common.encChLignes.some(l => !l.id);
    // Une colonne sans aucune saisie rend « — », pas « 0 € » : un zéro se
    // lirait comme « ce magasin n'a rien dépensé ce mois-ci ».
    common.encChTotaux = magasins.map(x2 => { const ca = caMoisDe(x2);
      const base = ca.reel != null ? ca.reel : (ca.budget || 0);
      const vide = nMag[x2.id] === 0;
      return { nom: x2.nom, total: vide ? '—' : this.fE(totMag[x2.id]),
        pct: vide ? 'aucune saisie' : (base ? pc(100 * totMag[x2.id] / base) + ' du CA' : '—'),
        partiel: !vide && nMag[x2.id] < common.encCharges.length
          ? nMag[x2.id] + ' / ' + common.encCharges.length + ' poste(s)' : '' }; });
    common.encChTotAttendu = this.fE(totAttendu);
    common.encChNSaisis = nSaisis + ' / ' + nCases + ' case(s) encodée(s)';
    // L'écriture des charges SANS le clic : les mêmes PUT, mais ni journal ni
    // rechargement — la frappe suivante n'a pas à attendre une relecture de la
    // base. Le bouton garde les deux, et sa ligne de journal.
    this._chAuto = () => {
      if (common.encChSansId) { return false; }
      const aEcrire = magasins.filter(x2 => common.encChLignes.some(l => d[cleCh(x2.id, l.id)] !== undefined));
      if (!aEcrire.length) { return true; }
      return Promise.all(aEcrire.map(x2 => {
        const postes = {};
        common.encChLignes.forEach(l => { const cell = l.cells.find(z => z.magasin === x2.id) || {};
          postes[l.id] = String(cell.valeur).trim() === '' ? null : num(cell.valeur); });
        return this.api('PUT', '/stores/' + x2.id + '/charges?exercice=' + anEnc
          + '&mois=' + moisIdx + '&journal=0', { postes });
      })).then(rs => !rs.some(r => !r || r.ok === false));
    };
    common.encChAuto = this.autoTxt('charges');
    common.encChSave = () => {
      if (common.encChSansId) {
        this.notify('Enregistrez d’abord le modèle réseau : les postes ont besoin de leur identifiant.');
        return;
      }
      // Une écriture par magasin, mais seulement pour ceux dont une case a
      // bougé : réenvoyer les quatre à chaque fois écrirait des lignes que
      // personne n'a touchées.
      const aEcrire = magasins.filter(x2 => common.encChLignes.some(l => d[cleCh(x2.id, l.id)] !== undefined));
      if (!aEcrire.length) { this.notify('Aucune modification à enregistrer.'); return; }
      const envois = aEcrire.map(x2 => {
        const postes = {};
        common.encChLignes.forEach(l => { const cell = l.cells.find(z => z.magasin === x2.id) || {};
          postes[l.id] = String(cell.valeur).trim() === '' ? null : num(cell.valeur); });
        return this.api('PUT', '/stores/' + x2.id + '/charges?exercice=' + anEnc + '&mois=' + moisIdx, { postes });
      });
      Promise.all(envois).then(rs => {
        const rates = rs.filter(r => !r || r.ok === false).length;
        if (rates) { this.notify(rates + ' magasin(s) refusé(s) — rien n’a été perdu à l’écran.'); }
        else { this.notify('Charges de ' + M.MOIS[moisIdx - 1] + ' enregistrées — ' + aEcrire.length + ' magasin(s)'); }
        this.log('Budget', null, 'Charges encodées pour ' + M.MOIS[moisIdx - 1] + ' ' + anEnc
          + ' — ' + aEcrire.map(x2 => x2.nom).join(', '));
        return readOne('/stores/budgets?exercice=' + anEnc).then(bs => {
          if (bs) { poseBudgets(bs); }
          this.setState(s2 => { const dd = Object.assign({}, (s2.encDraft || {})[cleD] || {});
            Object.keys(dd).forEach(k => { if (k.indexOf('chm' + moisIdx + ':') === 0) { delete dd[k]; } });
            return { encDraft: Object.assign({}, s2.encDraft, { [cleD]: dd }) }; });
        });
      });
    };

    common.encReset = () => this.setState(s2 => ({ encDraft: Object.assign({}, s2.encDraft, { [st.id]: {} }) }));
    // Le corps de la requête, recalculé à chaque rendu : l'enregistrement
    // automatique le lit au moment où il part, jamais une copie périmée.
    this._encCorps = (journal) => ({
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
      journal
    });
    this._encTotal = caTot;
    common.encAutoEtat = { 'en-cours': 'Enregistrement…', ok: 'Enregistré ✓', echec: 'Échec — réessayez' }[S.encAuto] || '';
    common.encAutoSt = 'font-size:11.5px;color:' + (S.encAuto === 'echec' ? '#8D1D2C' : 'var(--color-text-muted)');
    // ── Valider l'ÉTUDE, sans exiger un CA.
    //    Un magasin qui ouvre a son étude bien avant son premier budget
    //    mensuel : refuser l'enregistrement faute de CA (ce que fait le bouton
    //    du budget) bloquait précisément le cas qui compte. Ce bouton écrit
    //    l'étude, journalise, et dit quels exercices ont reçu leur théorique.
    common.encEtudeSave = () => {
      if (!num(baseTheo)) { this.notify('Renseignez le potentiel à maturité avant d’enregistrer l’étude.'); return; }
      if (Math.abs(num(common.encSaisTot) - 100) > 0.6 && num(common.encSaisTot) > 0) {
        this.notify('La variation par mois doit totaliser 100 % — total actuel : ' + common.encSaisTot);
        return;
      }
      clearTimeout(this._encT);
      this.setState({ encAuto: 'en-cours' });
      this.api('PUT', '/stores/' + st.id + '/budget?exercice=' + anEnc,
        this._encCorps('Étude de marché ' + anEnc + ' enregistrée — potentiel ' + this.fE(num(baseTheo))
          + ', ' + String(coef).replace('.', ',') + ' % la première année'))
        .then(r => {
          if (!r || r.ok === false) {
            this.setState({ encAuto: 'echec' });
            this.notify('Échec de l’enregistrement : ' + ((r && r.error) || 'refusé par le serveur'));
            return;
          }
          const faits = Object.keys(r.projection || {});
          this.setState({ encAuto: 'ok', encProjFait: r.projection || {} });
          this.notify(faits.length
            ? 'Étude enregistrée — théorique écrit pour ' + faits.join(', ')
            : 'Étude enregistrée — aucune projection (potentiel ou variation par mois manquants)');
          this.rafraichirBudget();
        });
    };
    common.encEtudeEtat = common.encAutoEtat;

    // ── Pousser la courbe de ce magasin AILLEURS.
    //    Deux gestes distincts : en faire la référence du réseau (elle servira
    //    aux magasins sans courbe), ou l'écrire chez les autres magasins (leur
    //    théorique est alors recalculé, sinon la courbe changerait à l'écran
    //    sans rien changer aux chiffres).
    const courbe = () => common.encSais.map(z => num(z.valeur));
    const courbeOk = () => {
      const c2 = courbe();
      if (c2.length !== 12 || Math.abs(c2.reduce((a2, w) => a2 + w, 0) - 100) > 0.6) {
        this.notify('La variation par mois doit totaliser 100 % — total actuel : ' + common.encSaisTot);
        return null;
      }
      return c2;
    };
    common.encSaisReseauSave = () => {
      const c2 = courbeOk(); if (!c2) { return; }
      this.autoEnreg('saisReseau', () => this.api('PUT', '/parametres/saisonnaliteReseau', { valeur: c2 })
        .then(r => {
          if (!r || r.ok === false) { this.notify((r && r.error) || 'Refusé'); return false; }
          this.notify('Courbe de référence du réseau enregistrée — elle servira aux magasins sans courbe propre');
          this.rafraichirBudget();
          return true;
        }));
    };
    common.encSaisAutres = this.open().filter(x2 => x2.id !== st.id).map(x2 => x2.nom).join(', ');
    common.encSaisPousser = () => {
      const c2 = courbeOk(); if (!c2) { return; }
      const cibles = this.open().filter(x2 => x2.id !== st.id);
      if (!cibles.length) { this.notify('Aucun autre magasin ouvert.'); return; }
      if (!window.confirm('Écrire cette variation par mois sur ' + cibles.length + ' magasin(s) — '
        + cibles.map(x2 => x2.nom).join(', ') + ' ? Leur CA théorique sera recalculé ; leur budget validé n’est pas touché.')) { return; }
      this.setState({ encAuto: 'en-cours' });
      Promise.all(cibles.map(x2 => this.api('PUT', '/stores/' + x2.id + '/saisonnalite?exercice=' + anEnc,
        { saisonnalite: c2 }))).then(rs => {
        const ko = rs.filter(r => !r || r.ok === false).length;
        this.setState({ encAuto: ko ? 'echec' : 'ok' });
        this.notify(ko ? ko + ' magasin(s) refusé(s) — les autres sont écrits'
          : 'Variation écrite sur ' + cibles.length + ' magasin(s), théorique recalculé');
        this.rafraichirBudget();
      });
    };
    common.encProjFait = Object.entries(S.encProjFait || {})
      .map(([an, v]) => an + ' · ' + this.fE(v.ca))
      .join('  ·  ');

    common.encSave = () => {
      const jr = 'Budget ' + this.meta.exercice + ' encodé — CA validé ' + this.fE(caTot) + ', CA théorique ' + this.fE(theoTot) + ', charges validées ' + common.encPctTot + ' (théoriques ' + common.encPctTotT + '), marge ' + common.encMargePct;
      if (!caTot) { this.notify('Renseignez au moins un mois de CA avant d’enregistrer.'); return; }
      // Un enregistrement automatique encore en attente n'a plus lieu d'être :
      // celui-ci écrit la même chose, et journalise.
      clearTimeout(this._encT);
      this.api('PUT', '/stores/' + st.id + '/budget?exercice=' + anEnc,
        this._encCorps(jr)).then(r => {
        // Ne jamais annoncer « enregistré » sans la réponse du serveur : un
        // 404/422 se résout normalement côté fetch, et l'écran mentait.
        if (r && r.ok === false) { this.notify('Échec de l’enregistrement : ' + (r.error || 'refusé par le serveur')); return; }
        this.log('Budget', st.nom, jr);
        this.notify('Budget enregistré — ' + st.nom + ' · ' + this.fE(caTot));
        return readOne('/stores/perf?granularite=mois&annees=' + (this.exo() - 1) + ',' + this.exo())
          .then(p => { if (p) { this.poserPerf(p); } this.setState({}); });
      });
    };
    common.encNote = 'À l’enregistrement, la série validée devient le budget de référence du magasin et la série théorique alimente le CA d’étude de marché : elles servent de référence au suivi mensuel et au calcul des écarts. Le CA théorique et l’étude de marché restent indépendants du budget négocié avec ' + st.fr + '.';
  }

  /**
   * Enregistrement automatique du budget, après une pause de frappe.
   *
   * `journal: ''` dit au serveur de ne PAS journaliser : douze champs remplis
   * écriraient douze lignes et le journal ne raconterait plus rien. Le bouton
   * garde sa ligne, avec son résumé.
   */
  /**
   * Enregistrement automatique, après une pause de frappe.
   *
   * Une clé par formulaire : deux écrans qui s'écrivent en parallèle ne se
   * volent pas leur minuterie. `fn` rend une promesse (ou un booléen) ; l'état
   * s'affiche à côté du formulaire, y compris l'échec — une saisie qui n'est
   * pas partie doit se voir, sinon l'écran ment.
   */
  /**
   * Après une écriture au fil de l'eau : relire ce que la base porte vraiment.
   *
   * Sans cela, l'écran de saisie est à jour et TOUS LES AUTRES restent sur les
   * chiffres du chargement — un budget encodé n'apparaissait pas dans « Suivi
   * budget » tant qu'on ne rechargeait pas la page. Mesuré sur Halle : douze
   * mois écrits en base, zéro à l'écran. Groupé : une seule relecture après
   * la rafale de frappe, pas une par touche.
   */
  /**
   * Range une relecture de `/stores/perf` là où les écrans la lisent.
   *
   * Les écrans ne lisent pas les lignes plates : ils lisent
   * `store.perf[annee][mois]`, un tableau dense assemblé UNE FOIS au
   * chargement. Poser la relecture dans `D.perfRaw` sans réassembler ne
   * changeait donc rien à l'affichage — la relecture partait bien, sa réponse
   * n'allait nulle part. C'est ce qui laissait « Suivi budget » montrer un
   * janvier et un février encore budgétés alors que l'encodage venait de les
   * vider : le tableau montrait l'instantané du chargement de la page.
   *
   * L'assemblage est le même qu'au démarrage — une seule fonction, pour que la
   * relecture et le premier chargement ne puissent pas diverger.
   */
  poserPerf(perf){
    if (!perf) { return; }
    this.D.perfRaw = perf;
    this.D.stores = joinPerf(this.D.stores, perf, this.exo(), this.D.etpRaw || []);
  }

  /**
   * Relit la perf en entrant sur un écran — une fois par entrée, pas à chaque
   * rendu : le rendu se rejoue à chaque frappe et à chaque survol.
   */
  relirePerf(ecran){
    // Une fois par ENTRÉE : le drapeau est remis à zéro en quittant l'écran,
    // parce que sortir vers l'encodage puis revenir est précisément le moment
    // où le tableau doit relire.
    if (this._perfEcran === ecran) { return; }
    this._perfEcran = ecran;
    this.rafraichirBudget();
  }

  rafraichirBudget(){
    clearTimeout(this._majBudT);
    this._majBudT = setTimeout(() => {
      Promise.all([
        readOne('/stores/perf?granularite=mois&annees=' + (this.exo() - 1) + ',' + this.exo()),
        readOne('/stores/budgets?exercice=' + this.exo()),
      ]).then(([p, bs]) => {
        if (p) { this.poserPerf(p); }
        if (bs) { this.D.budgets = bs; }
        this.setState({});
      }).catch(() => {});
    }, 1500);
  }

  autoEnreg(cle, fn){
    this._autoT = this._autoT || {};
    clearTimeout(this._autoT[cle]);
    this._autoT[cle] = setTimeout(() => {
      this.setState(s2 => ({ autoEtat: Object.assign({}, s2.autoEtat, { [cle]: 'en-cours' }) }));
      const fini = ok => {
        this.setState(s2 => ({ autoEtat: Object.assign({}, s2.autoEtat,
          { [cle]: ok === false ? 'echec' : 'ok' }) }));
        // Les écritures du budget touchent des chiffres que d'autres écrans
        // affichent : on relit, une fois la rafale finie.
        if (ok !== false && (cle === 'charges' || cle === 'chargesReseau')) { this.rafraichirBudget(); }
      };
      try { Promise.resolve(fn()).then(fini).catch(() => fini(false)); }
      catch (e2) { fini(false); }
    }, 900);
  }

  /** L'état d'un enregistrement automatique, en clair. */
  autoTxt(cle){
    return { 'en-cours': 'Enregistrement…', ok: 'Enregistré ✓', echec: 'Échec — réessayez' }[(this.state.autoEtat || {})[cle]] || '';
  }

  encAuto(shopId, exercice){
    clearTimeout(this._encT);
    this._encT = setTimeout(() => {
      if (!this._encCorps || !this._encTotal) { return; }   // rien de saisi : rien à écrire
      this.setState({ encAuto: 'en-cours' });
      this.api('PUT', '/stores/' + shopId + '/budget?exercice=' + (exercice || this.meta.exercice), this._encCorps(''))
        .then(r => {
          this.setState({ encAuto: (r && r.ok === false) ? 'echec' : 'ok' });
          if (!r || r.ok !== false) { this.rafraichirBudget(); }
        })
        .catch(() => this.setState({ encAuto: 'echec' }));
    }, 900);
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
   * Ouvre la fiche d'une référence et charge son CA et sa marge par période
   * (mois affiché, trimestre, année dernière). Chargés À L'OUVERTURE, pas
   * avant : trois sommes SQL par référence n'ont rien à faire dans le
   * tableau. Mis en cache par référence — rouvrir la même fiche ne relit pas.
   */
  pdOpenDetail(id){
    this.setState({ pdDet: { id } });
    if (!this._pdPer) { this._pdPer = {}; }
    if (this._pdPer[id]) { return; }
    this._pdPer[id] = { chargement: true, d: null };
    readOne('/products/periodes?produit=' + encodeURIComponent(id)).then(d => {
      this._pdPer[id] = { chargement: false, d: d && !d.error && Array.isArray(d.fenetres) ? d : null };
      this.setState({});
    });
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
  /** Rapports réels (table ceo_rapport) : définitions + dernières générations. */
  /** Budget × Campagnes : lu à l'ouverture de l'écran, et à chaque changement
   *  de campagne ou d'exercice — le réalisé vient du panel, il ne se devine pas. */
  /* --- mesure d'impact d'une campagne -----------------------------------------
     Trois vues sur un même écran : le PARAMÉTRAGE (ce qu'on mesure et contre
     quoi), les RÉSULTATS (avant / après, net du témoin) et les PRODUITS promus.
     Tout ce qui se saisit s'écrit à la frappe, comme le reste du cockpit. */
  mesCharge(force){
    const cle = String(this.state.mesCamp || 0);
    if (this._mesEnCours === cle) { return; }
    if (!force && this.state.mes && this.state.mes.cle === cle) { return; }
    this._mesEnCours = cle;
    this.setState({ mes: { cle, chargement: true, d: (this.state.mes || {}).d || null } });
    readOne('/marketing/mesure' + (this.state.mesCamp ? '?campagne=' + this.state.mesCamp : ''))
      .then(d => { this._mesEnCours = null; this.setState({ mes: { cle, chargement: false, d: d || null } }); })
      .catch(() => { this._mesEnCours = null; this.setState({ mes: { cle, chargement: false, d: null } }); });
  }
  /**
   * La LECTURE SIMPLE d'une campagne : une requête, un indicateur.
   *
   * Elle vit à côté de l'ancienne : le paramétrage (témoin, placebo, relevés
   * Facebook) reste joignable, mais l'écran s'ouvre sur la question qu'on se
   * pose vraiment — ce que la campagne a changé, magasin par magasin.
   */
  mesCompCharge(force){
    const cle = String(this.state.mesCamp || 0) + '|' + (this.state.mesMesure || 'trafic')
      + '|' + (this.state.mesDu || '') + '|' + (this.state.mesAu || '');
    if (this._mesCompEnCours === cle) { return; }
    if (!force && this.state.mesComp && this.state.mesComp.cle === cle) { return; }
    this._mesCompEnCours = cle;
    this.setState({ mesComp: { cle, chargement: true, d: (this.state.mesComp || {}).d || null } });
    const q = [];
    if (this.state.mesCamp) { q.push('campagne=' + this.state.mesCamp); }
    if (this.state.mesMesure) { q.push('mesure=' + this.state.mesMesure); }
    if (this.state.mesDu && this.state.mesAu) {
      q.push('du=' + this.state.mesDu); q.push('au=' + this.state.mesAu);
    }
    readOne('/marketing/mesure/comparaison' + (q.length ? '?' + q.join('&') : ''))
      .then(d => { this._mesCompEnCours = null;
        this.setState({ mesComp: { cle, chargement: false, d: d || null } }); })
      .catch(() => { this._mesCompEnCours = null;
        this.setState({ mesComp: { cle, chargement: false, d: null } }); });
  }
  /**
   * Une courbe hebdomadaire en SVG : cette année pleine, le N-1 en pointillé,
   * la campagne en bande. Les deux séries partagent la MÊME échelle — deux
   * échelles feraient croire à un écart qui n'existe pas.
   */
  mesCourbe(courbe, courbeN1, iDeb, iFin){
    const L = 236, H = 46;
    const vals = courbe.concat(courbeN1).map(p => p.v).filter(v => v != null);
    if (vals.length < 2) { return ''; }
    const max = Math.max(...vals) * 1.06, min = Math.min(...vals) * 0.94;
    const n = Math.max(courbe.length, courbeN1.length, 2);
    const X = i => 2 + i * (L - 4) / (n - 1);
    const Y = v => 3 + (H - 6) * (1 - (v - min) / (max - min || 1));
    const trace = serie => serie.map((p, i) => (p.v == null ? '' : (i ? 'L' : 'M') + X(i).toFixed(1) + ' ' + Y(p.v).toFixed(1)))
      .filter(Boolean).join(' ').replace(/^L/, 'M');
    const bande = (iDeb >= 0 && iFin >= iDeb)
      ? `<rect x="${X(iDeb).toFixed(1)}" y="1" width="${(X(iFin) - X(iDeb)).toFixed(1)}" height="${H - 2}" fill="rgba(141,29,44,0.10)" stroke="rgba(141,29,44,0.30)"/>` : '';
    return `<svg viewBox="0 0 ${L} ${H}" style="width:${L}px;height:${H}px;display:block">${bande}
      <path d="${trace(courbeN1)}" fill="none" stroke="#c9b8a8" stroke-width="1.6" stroke-dasharray="3 2"/>
      <path d="${trace(courbe)}" fill="none" stroke="var(--color-primary)" stroke-width="2"/></svg>`;
  }
  /** Le paramétrage part champ par champ : ce qui n'est pas envoyé n'est pas touché. */
  mesEcrire(id, champ, valeur){
    return this.api('PUT', '/marketing/mesure/' + id + '?journal=0', { [champ]: valeur })
      .then(r => !(!r || r.ok === false));
  }
  mesReleve(id, shopId, phase, valeur){
    return this.api('PUT', '/marketing/mesure/' + id + '/releve?journal=0',
      { shopId: String(shopId), phase, abonnes: valeur })
      .then(r => !(!r || r.ok === false));
  }

  /**
   * L'écran simple : une rangée par magasin, deux périodes contre leur N-1.
   *
   * Rien n'est recalculé ici — les écarts et l'effet net viennent du serveur,
   * qui les tient sur les jours OUVERTS. Refaire le calcul à l'écran finirait
   * par donner deux chiffres pour la même campagne.
   */
  valsMesureComp(common){
    const S = this.state;
    this.mesCompCharge(false);
    const b = S.mesComp || {};
    const d = b.d || {};
    common.mcChargement = !!b.chargement && !b.d;
    common.mcRecalcul = !!b.chargement && !!b.d;
    common.mcIndispo = d.indispo ? (d.raison || 'Module marketing absent') : (d.vide || '');
    const camp = d.campagne || null;
    common.mcCampOpts = (d.campagnes || []).map(c => ({ v: String(c.id),
      nom: c.nom + ' · ' + this.fD(c.debut) + ' → ' + this.fD(c.fin) }));
    common.mcCampSel = String((camp || {}).id || '');
    common.mcCampSet = e => this.setState({ mesCamp: parseInt(e.target.value, 10) || 0 });
    common.mcAvance = () => this.setState({ mesSimple: false });
    if (!camp) { common.mcVide = true; return; }
    common.mcVide = false;
    common.mcNom = camp.nom;
    common.mcType = camp.type || '';

    // L'indicateur. Les volumes promus sont proposés — et l'écran dit
    // pourquoi ils retombent sur le trafic plutôt que de les cacher.
    const mesure = d.mesure || S.mesMesure || 'trafic';
    common.mcMesures = [['trafic', 'Trafic — clients'], ['panier', 'Panier moyen'],
      ['ca', 'Chiffre d’affaires'], ['produits', 'Produits promus']]
      .map(([v, nom]) => ({ v, nom, on: v === (S.mesMesure || 'trafic'),
        go: () => this.setState({ mesMesure: v }) }));
    const unite = S.mesUnite === 'periode' ? 'periode' : 'jour';
    common.mcUnites = [['jour', 'Par jour'], ['periode', 'Sur la période']]
      .map(([v, nom]) => ({ v, nom, on: v === unite, go: () => this.setState({ mesUnite: v }) }));
    common.mcParJour = unite === 'jour';

    const f = d.fenetres || {};
    common.mcFen = {
      avant: this.fD(f.avantDu) + ' → ' + this.fD(f.avantAu),
      pendant: this.fD(f.pendantDu) + ' → ' + this.fD(f.pendantAu),
      avantN1: this.fD(f.avantN1Du) + ' → ' + this.fD(f.avantN1Au),
      pendantN1: this.fD(f.pendantN1Du) + ' → ' + this.fD(f.pendantN1Au),
      jours: f.jours || 0,
      aVenir: (f.aVenir || 0) > 0 ? (f.aVenir + ' jour(s) de campagne encore à venir — non comptés') : '',
    };
    // La période mesurée se choisit : celle de la campagne, ou la sienne. Une
    // date incomplète n'est pas envoyée — la moitié d'une borne ne veut rien
    // dire, et relancer la lecture à chaque frappe coûterait une lecture du
    // panel par caractère.
    common.mcPasCommencee = !!d.pasCommencee;
    common.mcLibre = !!d.libre;
    common.mcDu = S.mesDu || (f.pendantDu || '');
    common.mcAu = S.mesAu || (f.pendantAu || '');
    common.mcDuSet = e => this.setState({ mesDu: e.target.value });
    common.mcAuSet = e => this.setState({ mesAu: e.target.value });
    common.mcLibreRaz = (S.mesDu || S.mesAu) ? () => this.setState({ mesDu: '', mesAu: '' }) : null;
    common.mcSource = d.source || '';
    common.mcMotifs = d.motifs || [];
    common.mcPerimetre = (d.perimetre || []).join(' · ');
    // Réseau ou local : c'est ce qui décide s'il y a un témoin. Le dire évite
    // de chercher une ligne « hors campagne » qui n'a pas lieu d'être.
    common.mcScope = d.scope === 'reseau' ? 'Campagne réseau — tous les magasins'
      : (d.scope === 'locale' ? 'Campagne locale' : '');

    // Le format d'une valeur : un panier et un chiffre d'affaires sont des
    // euros, le trafic des clients — et « par jour » ne veut pas dire la même
    // chose qu'« au total ».
    const fmt = (v, total) => {
      if (v == null) { return '—'; }
      if (mesure === 'panier') { return this.fU(v); }
      if (mesure === 'ca') { return total ? this.fE(v) : this.fU(v); }
      return total ? Math.round(v).toLocaleString('fr-BE') : (Math.round(v * 10) / 10).toString().replace('.', ',');
    };
    common.mcUniteTxt = mesure === 'panier' ? '' : (mesure === 'ca' ? '' : (unite === 'jour' ? 'cl./j' : 'clients'));
    // Le panier ne se cumule pas : c'est déjà une moyenne. La bascule
    // « sur la période » n'a donc pas de sens pour lui, et on le dit.
    common.mcUniteInutile = mesure === 'panier';

    // Où commence la campagne sur la courbe : par la DATE de chaque semaine.
    // À défaut (dates absentes), par le compte des semaines de la fenêtre
    // « avant » — la bande doit tomber juste, sinon elle désigne la mauvaise
    // période et se lit comme un effet qui n'a pas eu lieu.
    const prem = (d.magasins || [])[0] || d.temoin || null;
    let iDeb = prem ? (prem.courbe || []).findIndex(p2 => p2.du && p2.du >= f.pendantDu) : -1;
    if (iDeb < 0 && f.avantDu && f.pendantDu) {
      const j = Math.round((new Date(f.pendantDu) - new Date(f.avantDu)) / 86400000);
      iDeb = j > 0 ? Math.round(j / 7) : -1;
    }
    const iFin = prem ? (prem.courbe || []).length - 1 : -1;
    const pc = v => v == null ? '—' : (v >= 0 ? '+' : '−') + Math.abs(v).toFixed(1).replace('.', ',') + ' %';
    const col = v => v == null ? 'var(--color-text-muted)' : (v >= 0 ? '#2d7a3e' : 'var(--color-primary)');
    const tem = d.temoin || null;
    const bruit = tem && tem.net != null ? Math.abs(tem.net) : null;

    const ligne = m => {
      const val = (bloc) => common.mcParJour && !common.mcUniteInutile ? bloc.n : (bloc.totalN != null ? bloc.totalN : bloc.n);
      const valN1 = (bloc) => common.mcParJour && !common.mcUniteInutile ? bloc.n1 : (bloc.totalN1 != null ? bloc.totalN1 : bloc.n1);
      const total = !common.mcParJour && !common.mcUniteInutile;
      return {
        id: m.id, nom: m.nom, netN: m.net,
        courbe: this.mesCourbe(m.courbe || [], m.courbeN1 || [], iDeb, iFin),
        avant: { v: fmt(val(m.avant), total), n1: fmt(valN1(m.avant), total),
          ecart: pc(m.avant.ecart), col: col(m.avant.ecart) },
        pendant: { v: fmt(val(m.pendant), total), n1: fmt(valN1(m.pendant), total),
          ecart: pc(m.pendant.ecart), col: col(m.pendant.ecart) },
        periode: { v: fmt(m.pendant.totalN, true), n1: fmt(m.pendant.totalN1, true) },
        net: pc(m.net), netCol: col(m.net),
        // Un effet plus petit que le bruit du réseau n'est pas une preuve :
        // l'écran le marque au lieu de laisser lire une victoire.
        faible: m.net != null && bruit != null && Math.abs(m.net) < bruit * 3 && m.role !== 'hors',
        hors: m.role === 'hors',
        manque: m.pendant.n == null ? 'pas encore de ventes sur la période'
          : (m.avant.ecart == null || m.pendant.ecart == null ? 'N-1 absent : l’écart ne peut pas se calculer' : ''),
      };
    };
    const tousMag = (d.magasins || []).map(ligne);
    common.mcMagasins = tousMag.filter(m => !m.hors);
    // Les magasins hors campagne gardent leur rangée, sous un intertitre : ils
    // ne sont pas la campagne, mais ils sont le réseau.
    common.mcHors = tousMag.filter(m => m.hors);
    common.mcTemoin = tem ? Object.assign(ligne(tem), { magasins: tem.magasins }) : null;
    common.mcBruitTxt = tem && tem.net != null
      ? 'Effet net du témoin : ' + pc(tem.net) + ' — tout ce qui reste dans cette marge n’est pas un effet de campagne.'
      : 'Aucun magasin hors campagne : sans témoin, la saison et la météo restent dans la mesure.';
  }
  valsMesure(common){
    const S = this.state;
    this.mesCharge(false);
    const b = S.mes || {};
    const d = b.d || {};
    common.mesChargement = !!b.chargement && !b.d;
    // Un recalcul prend plusieurs secondes (une lecture du panel par magasin et
    // par mois). Sans ce mot, changer de témoin donne l'impression que le clic
    // n'a rien fait — et on reclique.
    common.mesRecalcul = !!b.chargement && !!b.d;
    common.mesIndispo = d.indispo ? (d.raison || 'Module marketing absent') : (d.vide || '');
    const camp = d.campagne || null;
    common.mesCampOpts = (d.campagnes || []).map(c => ({ v: String(c.id),
      nom: c.nom + ' · ' + (c.debut || '').split('-').reverse().join('/') + ' → ' + (c.fin || '').split('-').reverse().join('/') }));
    common.mesCampSel = String((camp || {}).id || '');
    common.setMesCamp = e => this.setState({ mesCamp: parseInt(e.target.value, 10) || 0 });
    if (!camp) { common.mesVide = true; return; }
    common.mesVide = false;
    common.mesNom = camp.nom;
    common.mesType = camp.type;
    common.mesStatut = camp.statut;
    const jf = s => (s || '').split('-').reverse().join('/');
    common.mesPeriode = 'du ' + jf(camp.debut) + ' au ' + jf(camp.fin);
    common.mesSource = d.source || '';
    common.mesMotifs = d.motifs || [];

    // Une campagne pas encore commencée s'ouvre sur son paramétrage ; une
    // campagne en cours ou finie, sur ses résultats.
    common.mesVue = S.mesVue || (((d.fenetres || {}).camp || {}).commencee ? 'resultats' : 'param');
    common.mesVues = [['param', 'Paramétrage'], ['resultats', 'Résultats'], ['produits', 'Produits promus']]
      .map(([v, nom]) => ({ v, nom, on: common.mesVue === v, choisir: () => this.setState({ mesVue: v }) }));

    const P = d.param || {}, R = d.reseau || {};
    // Les fenêtres manquent quand la lecture n'a pas abouti : l'écran de
    // paramétrage doit rester ouvrable, sinon un panel muet emporte tout
    // l'écran au lieu de dire ce qu'il ne sait pas.
    const vide = { du: '', au: '', jours: 0 };
    const F = Object.assign({ ref: vide, camp: vide, rem: vide, temoin: vide, pre: vide }, d.fenetres || {});
    ['ref', 'camp', 'rem', 'temoin', 'pre'].forEach(k => { F[k] = Object.assign({}, vide, F[k] || {}); });
    if (!d.fenetres) { common.mesFenetresVides = true; }
    // ── vue A : paramétrage
    // `relire` : les champs qui changent les FENÊTRES rechargent l'écran une
    // fois écrits — les autres non, pour ne pas déplacer le curseur en cours
    // de frappe.
    const champ = (cle, val, relire) => ({ val: val == null ? '' : String(val),
      set: e => { const v = e.target.value;
        this.autoEnreg('mes' + cle + camp.id, () => this.mesEcrire(camp.id, cle, v)
          .then(ok => { if (ok && relire) { this.mesCharge(true); } return ok; })); } });
    common.mesRef = { du: F.ref.du, au: F.ref.au, jours: F.ref.jours,
      duCh: champ('refDebut', F.ref.du, true), auCh: champ('refFin', F.ref.au, true) };
    common.mesCamp2 = { du: F.camp.du, au: F.camp.au, jours: F.camp.jours,
      ecoulee: F.camp.ecoulee, encours: !!F.camp.encours, commencee: !!F.camp.commencee };
    common.mesRem = { jours: F.rem.jours, du: F.rem.du, au: F.rem.au, dispo: !!F.rem.dispo,
      ch: champ('remanenceJours', F.rem.jours, true) };
    common.mesPre = { du: F.pre.du, au: F.pre.au };
    common.mesN1 = { on: !!P.n1, bascule: () => this.mesEcrire(camp.id, 'n1', !P.n1).then(() => this.mesCharge(true)) };

    const enCamp = (d.magasins || []).filter(m => m.role === 'campagne');
    const temSel = P.temoins || [];
    const mode = P.temoinMode || 'auto';
    common.mesTemoinAuto = mode === 'auto';
    common.mesTemoinMode = mode;
    // Trois régimes de témoin, dont le réseau complet — utile quand presque
    // tout le réseau est en campagne : la référence devient « ce qu'a fait le
    // réseau », au prix d'une dilution qu'on annonce.
    const poser = v => this.mesEcrire(camp.id, 'temoins', v).then(() => this.mesCharge(true));
    common.mesTemoinModes = [
      { v: 'auto', nom: 'Magasins hors campagne', on: mode === 'auto', choisir: () => poser([]) },
      { v: 'reseau', nom: 'Réseau complet', on: mode === 'reseau', choisir: () => poser('reseau') },
      { v: 'choix', nom: 'Sélection', on: mode === 'choix', choisir: () => poser(
          (d.magasins || []).filter(m => m.role !== 'campagne').map(m => m.id)) }
    ];
    common.mesTemoinDilue = P.temoinDilue || 0;
    common.mesTemoinNote = mode === 'reseau'
      ? (P.temoinDilue
        ? 'Témoin dilué : il contient ' + P.temoinDilue + ' magasin' + (P.temoinDilue > 1 ? 's' : '') + ' en campagne. L’écart net est MINORÉ — l’effet réel est au moins celui affiché.'
        : 'Le réseau complet sert de référence.')
      : (mode === 'auto' ? 'Choix automatique : tous les magasins hors campagne.' : 'Sélection manuelle.');
    common.mesMagasins = (d.magasins || []).map(m => ({ id: m.id, nom: m.nom, role: m.role,
      enCampagne: m.role === 'campagne', temoin: !!m.dansTemoin,
      fige: mode === 'reseau',
      bascule: (mode === 'reseau' || m.role === 'campagne') ? null : () => {
        const cur = temSel.filter(i => i !== m.id);
        if (cur.length === temSel.length) { cur.push(m.id); }
        poser(cur);
      } }));
    // Le bandeau des résultats dit lequel des trois témoins a servi : lire un
    // écart net sans savoir contre quoi il est mesuré n'apprend rien.
    common.mesTemoinNoms = mode === 'reseau'
      ? ('réseau complet' + (P.temoinDilue ? ' (dilué par ' + P.temoinDilue + ' magasin' + (P.temoinDilue > 1 ? 's' : '') + ' en campagne — écart minoré)' : ''))
      : ((d.temoinLignes || []).map(l => l.nom).join(', ') || 'aucun');
    common.mesPerimNoms = enCamp.map(m => m.nom).join(', ');

    const pct = v => v == null ? '—' : (v >= 0 ? '+' : '−') + Math.abs(v).toFixed(1).replace('.', ',') + ' %';
    const pt = v => v == null ? '—' : (v >= 0 ? '+' : '−') + Math.abs(v).toFixed(1).replace('.', ',') + ' pt';
    const col = v => v == null ? 'var(--color-text-muted)' : (v > 0.05 ? '#2d7a3e' : (v < -0.05 ? 'var(--color-primary)' : 'var(--color-text)'));
    const gC = (R.campagne || {}), gT = (R.temoin || {});
    const base = (gC.ref || {});
    common.mesIndics = [
      { nom: 'Trafic', detail: 'tickets / jour', src: 'caisse — par magasin, par jour',
        base: base.ticketsJour == null ? '—' : base.ticketsJour.toLocaleString('fr-BE'),
        gran: 'par magasin', cible: champ('cibleTrafic', P.cibleTrafic), unite: '%' },
      { nom: 'Panier moyen', detail: 'CA / ticket', src: 'caisse — par magasin, par jour',
        base: base.panier == null ? '—' : this.fEd(base.panier),
        gran: 'par magasin', cible: champ('ciblePanier', P.ciblePanier), unite: '%' },
      { nom: 'CA / jour', detail: 'trafic × panier', src: 'caisse — par magasin, par jour',
        base: this.fE(base.caJour), gran: 'par magasin', cible: champ('cibleCa', P.cibleCa), unite: '%' },
      { nom: 'Produits promus', detail: 'volume vendu', src: 'panel — références',
        base: (d.produits || []).length ? (d.produits.reduce((a, p) => a + (p.ref || 0), 0)).toLocaleString('fr-BE') + ' u' : '—',
        gran: 'réseau seulement', alerte: true, cible: champ('ciblePromo', P.ciblePromo), unite: '%' },
      { nom: 'Abonnés Facebook', detail: 'relevé manuel', src: 'saisie — 2 champs',
        base: (d.fb || {}).avant == null ? '—' : d.fb.avant.toLocaleString('fr-BE'),
        gran: 'par page', cible: champ('cibleFb', P.cibleFb), unite: 'abonnés' }
    ];
    common.mesCout = champ('cout', P.cout, true);
    common.mesMarge = champ('margePct', P.margePct == null ? 63 : P.margePct, true);
    common.mesProduitsTxt = champ('produits', P.produits, true);
    common.mesGele = P.geleLe ? ('gelée le ' + String(P.geleLe).slice(0, 16).replace('T', ' ')) : '';
    common.mesGeler = () => this.api('POST', '/marketing/mesure/' + camp.id + '/gel', {})
      .then(() => this.mesCharge(true));
    common.mesDegeler = () => this.api('POST', '/marketing/mesure/' + camp.id + '/gel', { annuler: 1 })
      .then(() => this.mesCharge(true));

    // Relevé Facebook — deux nombres par page, saisis à la main.
    common.mesFbLignes = ((d.fb || {}).lignes || []).map(l => ({
      nom: l.nom, shopId: l.shopId,
      avant: { val: l.avant == null ? '' : String(l.avant),
        set: e => { const v = e.target.value;
          this.autoEnreg('fbA' + camp.id + l.shopId, () => this.mesReleve(camp.id, l.shopId, 'avant', v).then(ok => { if (ok) this.mesCharge(true); return ok; })); } },
      apres: { val: l.apres == null ? '' : String(l.apres),
        set: e => { const v = e.target.value;
          this.autoEnreg('fbP' + camp.id + l.shopId, () => this.mesReleve(camp.id, l.shopId, 'apres', v).then(ok => { if (ok) this.mesCharge(true); return ok; })); } },
      delta: l.delta == null ? '' : (l.delta >= 0 ? '+' : '−') + Math.abs(l.delta).toLocaleString('fr-BE'),
      deltaCol: col(l.delta)
    }));

    // ── vue B : résultats
    const V = R.verdict || {};
    common.mesVerdict = { niveau: V.niveau || 'insuffisant', txt: V.txt || '',
      libelle: { probant: 'Effet probant', indicatif: 'Indicatif', negatif: 'Effet négatif', insuffisant: 'Pas encore mesurable' }[V.niveau || 'insuffisant'],
      valeur: R.netCa == null ? '' : pt(R.netCa),
      st: V.niveau === 'probant' ? 'background:#E6F2E9;color:#2d7a3e'
        : V.niveau === 'negatif' ? 'background:#F7E4E6;color:var(--color-primary)'
        : V.niveau === 'indicatif' ? 'background:#FBEFE0;color:#C17A2A' : 'background:#EDEAE5;color:var(--color-text-muted)' };
    const tuile = (nom, val, avant, dv, tv, nv, note) => ({ nom, val,
      avant: avant, d: pct(dv), dCol: col(dv),
      temoin: tv == null ? '' : 'témoin ' + pct(tv), net: nv == null ? '' : 'net ' + pt(nv),
      netCol: col(nv), note: note || '' });
    const camp2 = gC.camp || {}, ref2 = gC.ref || {};
    common.mesTuiles = [
      tuile('Trafic — tickets / jour', camp2.ticketsJour == null ? '—' : camp2.ticketsJour.toLocaleString('fr-BE'),
        ref2.ticketsJour == null ? '—' : 'avant ' + ref2.ticketsJour.toLocaleString('fr-BE'),
        R.dTrafic, R.tTrafic, R.netTrafic),
      tuile('Panier moyen', this.fEd(camp2.panier), 'avant ' + this.fEd(ref2.panier), R.dPanier, R.tPanier, R.netPanier,
        (R.netTrafic > 0 && R.netPanier < 0) ? 'la promo attire, mais dilue le ticket' : ''),
      tuile('CA / jour', this.fE(camp2.caJour), 'avant ' + this.fE(ref2.caJour), R.dCa, R.tCa, R.netCa,
        R.euros == null ? '' : 'soit ' + (R.euros >= 0 ? '+' : '−') + this.fE(Math.abs(R.euros)) + ' sur la période'),
      tuile('Produits promus — volume',
        (d.produits || []).length ? (d.produits.reduce((a, p) => a + (p.camp || 0), 0)).toLocaleString('fr-BE') + ' u' : '—',
        (d.produits || []).length ? 'avant ' + (d.produits.reduce((a, p) => a + (p.ref || 0), 0)).toLocaleString('fr-BE') + ' u' : '—',
        null, null, null, 'réseau — pas de détail magasin'),
      tuile('Abonnés Facebook', (d.fb || {}).apres == null ? '—' : d.fb.apres.toLocaleString('fr-BE'),
        (d.fb || {}).avant == null ? 'relevé « avant » manquant' : 'avant ' + d.fb.avant.toLocaleString('fr-BE'),
        null, null, null,
        (d.fb || {}).delta == null ? '' : ((d.fb.delta >= 0 ? '+' : '−') + Math.abs(d.fb.delta) + ' abonnés'))
    ];

    // La courbe : indice 100 = moyenne de la fenêtre de référence, pour que
    // deux groupes de tailles différentes se lisent sur la même échelle.
    const S2 = d.serie || [];
    const W = 1120, H = 190, PB = 26, PT = 8;
    const vals = S2.map(p => p.campIdx).concat(S2.map(p => p.temoinIdx)).filter(v => v != null);
    const hi = vals.length ? Math.max.apply(null, vals) : 0;
    const lo = vals.length ? Math.min.apply(null, vals) : 0;
    const max = hi * 1.06, min = Math.max(0, lo * 0.94);
    const px = i => (S2.length < 2 ? 0 : (i * (W - 12) / (S2.length - 1)) + 6).toFixed(1);
    const py = v => (PT + (H - PB - PT) * (1 - (v - min) / ((max - min) || 1))).toFixed(1);
    const trace = cle => {
      let dd = '', ouvert = false;
      S2.forEach((p, i) => {
        if (p[cle] == null) { ouvert = false; return; }
        dd += (ouvert ? ' L' : ' M') + px(i) + ' ' + py(p[cle]); ouvert = true;
      });
      return dd.trim();
    };
    const bande = phase => {
      const idx = S2.map((p, i) => p.phase === phase ? i : -1).filter(i => i >= 0);
      if (!idx.length) return null;
      const a = +px(idx[0]), b2 = +px(idx[idx.length - 1]);
      return { x: a.toFixed(1), w: Math.max(1, b2 - a).toFixed(1), milieu: ((a + b2) / 2).toFixed(0) };
    };
    common.mesCourbe = { W, H, base: (H - PB).toFixed(1),
      vide: !vals.length,
      camp: trace('campIdx'), temoin: trace('temoinIdx'),
      hautBande: (H - PB - PT).toFixed(1), yBande: PT,
      bandes: [
        Object.assign({ nom: 'AVANT-RÉFÉRENCE', fill: 'rgba(34,34,34,0.03)', col: 'var(--color-text-muted)' }, bande('pre') || {}),
        Object.assign({ nom: 'RÉFÉRENCE', fill: 'rgba(34,34,34,0.06)', col: 'var(--color-text-muted)' }, bande('ref') || {}),
        Object.assign({ nom: 'CAMPAGNE', fill: 'rgba(141,29,44,0.07)', col: 'var(--color-primary)' }, bande('camp') || {}),
        Object.assign({ nom: 'RÉMANENCE', fill: 'rgba(201,162,39,0.13)', col: '#8a6d12' }, bande('rem') || {})
      ].filter(b3 => b3.x != null),
      cent: py(100) };

    common.mesLignes = (d.lignes || []).map(l => ({
      nom: l.nom,
      trafAv: l.ref.ticketsJour == null ? '—' : l.ref.ticketsJour.toLocaleString('fr-BE') + ' /j',
      trafPd: l.camp.ticketsJour == null ? '—' : l.camp.ticketsJour.toLocaleString('fr-BE') + ' /j',
      dTraf: pct(l.dTrafic), dTrafCol: col(l.dTrafic),
      dPan: pct(l.dPanier), dPanCol: col(l.dPanier),
      net: pct(l.netCa == null ? l.dCa : l.netCa), netCol: col(l.netCa == null ? l.dCa : l.netCa),
      euros: l.euros == null ? '—' : (l.euros >= 0 ? '+' : '−') + this.fE(Math.abs(l.euros)),
      eurosCol: col(l.euros)
    }));
    common.mesTemLignes = (d.temoinLignes || []).map(l => ({
      nom: l.nom,
      trafAv: l.ref.ticketsJour == null ? '—' : l.ref.ticketsJour.toLocaleString('fr-BE') + ' /j',
      trafPd: l.camp.ticketsJour == null ? '—' : l.camp.ticketsJour.toLocaleString('fr-BE') + ' /j',
      dTraf: pct(l.dTrafic), dPan: pct(l.dPanier), dCa: pct(l.dCa)
    }));
    common.mesRenta = {
      euros: R.euros == null ? '—' : (R.euros >= 0 ? '+' : '−') + this.fE(Math.abs(R.euros)),
      marge: R.marge == null ? '—' : this.fE(R.marge),
      margePct: (P.margePct == null ? 63 : P.margePct).toString().replace('.', ',') + ' %',
      cout: R.cout == null ? '—' : this.fE(R.cout),
      gain: R.gain == null ? '—' : (R.gain >= 0 ? '+' : '−') + this.fE(Math.abs(R.gain)),
      gainCol: col(R.gain),
      retour: R.retour == null ? '—' : R.retour.toFixed(2).replace('.', ',') + ' ×',
      manque: R.cout == null ? 'coût de la campagne à saisir dans le paramétrage' : ''
    };
    common.mesRemanence = { pct: pct(R.remanence), col: col(R.remanence),
      dispo: !!F.rem.dispo,
      txt: !F.rem.dispo ? ('à lire à partir du ' + jf(F.rem.du))
        : (R.remanence == null ? 'pas encore de données'
          : (R.remanence < -0.5 ? 'le trafic est retombé sous la base : une partie des achats a été avancée, pas créée'
            : 'le niveau tient après la campagne')) };
    common.mesBruit = R.bruit == null ? '' : ('variation habituelle mesurée sur la période précédente : '
      + Math.abs(R.bruit).toFixed(1).replace('.', ',') + ' pt');

    // ── vue C : produits promus
    common.mesProduitsNote = d.produitsNote || '';
    const pj = v => v == null ? '—' : v.toLocaleString('fr-BE');
    const maxQ = Math.max.apply(null, [1].concat((d.produits || []).map(p => Math.max(p.refJour || 0, p.campJour || 0))));
    common.mesProduits = (d.produits || []).map(p => {
      const rep = p.reponse;
      const score = rep == null ? { t: '—', st: 'background:#EDEAE5;color:var(--color-text-muted)' }
        : rep >= 25 ? { t: 'A — répond fort', st: 'background:#E6F2E9;color:#2d7a3e' }
        : rep >= 10 ? { t: 'B — répond', st: 'background:#E6F2E9;color:#2d7a3e' }
        : rep >= 2 ? { t: 'C — peu sensible', st: 'background:#FBEFE0;color:#C17A2A' }
        : { t: 'D — insensible', st: 'background:#F7E4E6;color:var(--color-primary)' };
      if (rep != null && p.remanence != null && p.remanence < -8 && rep > 0) {
        score.t = 'C — vend d’avance'; score.st = 'background:#FBEFE0;color:#C17A2A';
      }
      return { sku: p.sku, nom: p.nom, categorie: p.categorie, source: p.source,
        ref: pj(p.ref), camp: pj(p.camp), rem: pj(p.rem), n1: pj(p.n1),
        refJour: p.refJour == null ? '—' : p.refJour.toLocaleString('fr-BE'),
        campJour: p.campJour == null ? '—' : p.campJour.toLocaleString('fr-BE'),
        reponse: pct(rep), reponseCol: col(rep),
        n1Var: pct(p.n1Var), remanence: pct(p.remanence), remanenceCol: col(p.remanence),
        score,
        hRef: Math.round(100 * (p.refJour || 0) / maxQ),
        hCamp: Math.round(100 * (p.campJour || 0) / maxQ),
        connu: !!p.connu };
    });
  }

  bxcCharge(force){
    const cle = (this.state.bxcExo || this.exo()) + ':' + (this.state.bxcCamp || 0);
    if (this._bxcEnCours === cle) { return; }
    if (!force && this.state.bxc && this.state.bxc.cle === cle) { return; }
    this._bxcEnCours = cle;
    this.setState({ bxc: { cle, chargement: true, d: (this.state.bxc || {}).d || null } });
    readOne('/marketing/budget-campagnes?exercice=' + (this.state.bxcExo || this.exo())
      + (this.state.bxcCamp ? '&campagne=' + this.state.bxcCamp : ''))
      .then(d => { this._bxcEnCours = null; this.setState({ bxc: { cle, chargement: false, d: d || null } }); })
      .catch(() => { this._bxcEnCours = null; this.setState({ bxc: { cle, chargement: false, d: null } }); });
  }

  rapCharge(force){
    if (this._rapEnCours) return;
    if (!force && this.state.rapGen) return;
    this._rapEnCours = true;
    if (!this.state.rapGen) this.setState({ rapGen: { chargement: true, d: null } });
    readOne('/rapports').then(d => { this._rapEnCours = false;
      this.setState({ rapGen: { chargement: false, d: d || null } }); });
  }
  /** KPIs mensuels de l'année (clients/jour, ticket moyen, articles/ticket). */
  mgAnCharge(){
    if (this._mgAnEnCours) return;
    this._mgAnEnCours = true;
    this.setState({ mgAn: { chargement: true, d: null } });
    readOne('/stores/kpis-annuels')
      .then(d => { this._mgAnEnCours = false;
        this.setState({ mgAn: { chargement: false, d: d || null } }); });
  }
  /** Rentabilité par jour (heatmap PWA) : semaine pleine ou mois courant. */
  exRentCharge(per){
    if (this._exRentEnCours === per) return;
    this._exRentEnCours = per;
    this.setState({ exRent: { per, chargement: true, d: null } });
    readOne('/exploitation/rentabilite?periode=' + per)
      .then(d => { this._exRentEnCours = null;
        this.setState(s => (s.exRent && s.exRent.per === per)
          ? { exRent: { per, chargement: false, d: d || null } } : {}); });
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
    // Fins de gamme annoncées : le réseau doit les voir là où il regarde les
    // références — au catalogue et à l'assortiment, pas dans un écran à part.
    common.refFins = cat.filter(p => p.finLe)
      .sort((a, b) => (a.finLe < b.finLe ? -1 : 1))
      .map(p => ({ ref: String(p.ref), nom: p.nom,
        date: this.fD(p.finLe) + '/' + String(p.finLe).slice(0, 4),
        note: p.finNote || '' }));

    const ed = S.refEdit;
    common.refEdit = ed ? {
      ref: ed.ref, nom: ed.nom, mode: ed.mode, busy: !!ed.busy, err: ed.err || '',
      champs: ed.champs,
      set: k => e => this.setState(s2 => ({ refEdit: Object.assign({}, s2.refEdit,
        { champs: Object.assign({}, s2.refEdit.champs, { [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value }) }) })),
      close: () => this.setState({ refEdit: null }),
      save: () => this.refSave()
    } : null;

    // Score de la référence, sur le catalogue. Il vient du MÊME calcul que
    // l'écran de scoring — jamais d'un second, qui finirait par en diverger.
    // Le rapprochement se fait par la référence : /products/scoring rend un
    // `id` qui est la référence catalogue (mesuré : « 1610004 » des deux côtés).
    let scores = null, nScores = 0;
    if (common.isCat) {
      const c2 = this.pdCalcule();
      scores = {};
      (c2.base || []).forEach(s => { scores[String(s.id)] = s; });
      nScores = Object.keys(scores).length;
      common.refVerdict = c2.verdict;
      common.refScorePond = c2.pond;
      common.refScorePer = c2.periode;
    }
    common.refScoresN = nScores;
    // Le scoring ne couvre que les références VENDUES sur la période : le dire
    // évite de lire un tiret comme un mauvais score.
    common.refScoresTxt = common.isCat
      ? nScores + ' référence(s) notée(s) sur ' + cat.length + ' — le score porte sur ce qui s’est vendu ' + (common.refScorePer || '')
      : '';

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
      // Score : la note, son verdict et sa couleur — les trois ensemble, sinon
      // un 62 ne dit rien sans le barème qui le juge.
      score: (() => { const s = scores && scores[String(p.ref)];
        return s ? Math.round(s.score) : null; })(),
      scoreTxt: (() => { const s = scores && scores[String(p.ref)];
        return s ? String(Math.round(s.score)) : '—'; })(),
      scoreVerdict: (() => { const s = scores && scores[String(p.ref)];
        return s && common.refVerdict ? common.refVerdict(s.score)[0] : ''; })(),
      scoreSt: (() => { const s = scores && scores[String(p.ref)];
        if (!s || !common.refVerdict) { return 'color:var(--color-text-muted)'; }
        const v = common.refVerdict(s.score);
        return 'display:inline-block;padding:2px 9px;border-radius:999px;font-size:11.5px;font-weight:500;'
          + 'background:' + v[2] + ';color:' + v[1];
      })(),
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
      finLe: p.finLe ? this.fD(p.finLe) + '/' + String(p.finLe).slice(0, 4) : '',
      zone: p.zone || '', meuble: p.meuble || '', niveau: p.niveau || '',
      slot: p.slot == null ? '' : String(p.slot),
      place: !!p.zone,
      // Emplacement au comptoir, lisible d'un trait : une référence
      // obligatoire sans place au comptoir est une consigne que la boutique ne
      // peut pas tenir — c'est ce que cette colonne rend visible.
      emplacement: p.zone
        ? [p.zone, p.meuble, p.niveau].filter(Boolean).join(' · ')
          + (p.slot == null ? '' : ' · emp. ' + p.slot)
        : '',
      // Le même geste depuis les deux écrans : la fiche de présentation porte
      // le plan du comptoir, on y choisit l'emplacement sur le plan.
      planoGo: () => this.plFicheOuvrir(String(p.ref)),
      planoBtn: p.zone ? 'Modifier' : 'Attribuer',
      dlv: p.dlv ? p.dlv + ' h' : '—',
      parametre: !!p.parametre,
      // Au planogramme, la ligne ouvre la FICHE de présentation : c'est là que
      // se choisit l'emplacement, sur le plan, et non dans un formulaire où il
      // fallait retaper « Vitrine 1 » sans savoir ce qui était libre.
      ouvrir: common.isPlano
        ? () => this.plFicheOuvrir(String(p.ref))
        : () => this.refOpen(p, common.isAsso ? 'asso' : 'fiche'),
      // Au planogramme, la ligne se PREND : on la glisse sur un emplacement du
      // plan plutôt que d'ouvrir une fiche pour y choisir une case.
      prendre: common.isPlano ? this.plPrendre(String(p.ref)) : null
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
          // Chaque période s'ouvre : la ligne du tableau ne dit que le réseau,
          // et c'est le détail par magasin qu'on vient chercher — qui décroche,
          // qui porte la hausse.
          ouvrir: () => this.setState({ anDetail: { i: pts.indexOf(p), shop: null } }),
          enCours: p.enCours })) };
    }
    common.anDetail = S.anDetail && d && (d.points || []).length
      ? this.valsAnDetail(S.anDetail, d, common) : false;
    return common;
  }
  /**
   * Le détail d'une période : ce que chaque magasin y a fait.
   *
   * Deux lectures dans la même modale. Par PÉRIODE, tous les magasins d'un
   * coup : qui décroche, qui porte la hausse, et quelle part chacun pèse.
   * Puis, un magasin choisi, sa trajectoire sur TOUTES les périodes — la
   * question « est-ce un accident ou une tendance » ne se répond pas sur un
   * point isolé.
   *
   * Aucun appel de plus : la ventilation par magasin voyage déjà avec la série.
   */
  valsAnDetail(det, d, common){
    const pts = d.points || [];
    const i = Math.max(0, Math.min(pts.length - 1, det.i || 0));
    const p = pts[i];
    if (!p) { return false; }
    const euro = (d.unite || '') === '€';
    const fmt = v => v == null ? '—' : (euro ? this.fE(v) : Math.round(v).toLocaleString('fr-BE') + ' u');
    const noms = {};
    (this.D.stores || []).forEach(s => { noms[String(s.id)] = s.nom; });
    const parM = p.parMagasin || {};
    const parN1 = p.parMagasinN1 || {};
    const total = Object.keys(parM).reduce((a, k) => a + (+parM[k] || 0), 0);

    const mags = Object.keys(parM).map(id => {
      const v = +parM[id] || 0;
      const n1 = parN1[id] == null ? null : +parN1[id];
      const delta = (n1 == null || n1 === 0) ? null : (v - n1) / n1;
      return { id, nom: noms[id] || ('Magasin ' + id), valeur: fmt(v),
        part: total > 0 ? this.fP(v / total, 1) : '—',
        partPct: total > 0 ? (100 * v / total) : 0,
        n1: n1 == null ? '—' : fmt(n1),
        delta: delta == null ? '—' : (delta >= 0 ? '+' : '') + this.fP(delta, 1),
        deltaCol: delta == null ? 'var(--color-text-muted)' : (delta >= 0 ? '#2d7a3e' : 'var(--color-primary)'),
        // Sans N-1, pas de comparaison : le dire plutôt que d'afficher un tiret
        // muet qu'on lirait comme une stabilité.
        sansN1: n1 == null,
        on: det.shop === id,
        choisir: () => this.setState({ anDetail: { i, shop: det.shop === id ? null : id } }) };
    }).sort((a, b) => b.partPct - a.partPct);

    // Un magasin choisi : sa trajectoire sur toutes les périodes.
    let serie = null;
    if (det.shop) {
      const lignes = pts.map(q => {
        const v = (q.parMagasin || {})[det.shop];
        const n1 = (q.parMagasinN1 || {})[det.shop];
        const dl = (v == null || n1 == null || +n1 === 0) ? null : (+v - +n1) / +n1;
        return { libelle: q.libelle, enCours: !!q.enCours,
          valeur: v == null ? '—' : fmt(+v), n1: n1 == null ? '—' : fmt(+n1),
          delta: dl == null ? '—' : (dl >= 0 ? '+' : '') + this.fP(dl, 1),
          deltaCol: dl == null ? 'var(--color-text-muted)' : (dl >= 0 ? '#2d7a3e' : 'var(--color-primary)'),
          courant: q === p };
      });
      const clos = lignes.filter(l => !l.enCours);
      serie = { nom: noms[det.shop] || ('Magasin ' + det.shop), lignes,
        note: clos.length < 2 ? 'Trop peu de périodes closes pour lire une tendance.' : '' };
    }

    return {
      titre: d.libelle || '', periode: p.libelle,
      bornes: this.fD(p.du) + ' → ' + this.fD(p.au),
      enCours: !!p.enCours,
      mesure: d.mesure || '', unite: d.unite || '',
      reseau: fmt(p.valeur), reseauN1: p.n1 == null ? '—' : fmt(p.n1),
      delta: p.delta == null ? '—' : (p.delta >= 0 ? '+' : '') + this.fP(p.delta, 1),
      deltaCol: p.delta == null ? 'var(--color-text-muted)'
        : (p.delta >= 0 ? '#2d7a3e' : 'var(--color-primary)'),
      mags, magsVide: !mags.length,
      // La somme des magasins peut différer du total réseau : le dire plutôt
      // que de laisser chercher l'erreur d'addition.
      ecartTotal: (p.valeur != null && total > 0 && Math.abs(total - p.valeur) / p.valeur > 0.01)
        ? 'La somme des magasins (' + fmt(total) + ') diffère du total réseau — la caisse ne ventile pas tout.'
        : '',
      serie,
      prec: i > 0 ? () => this.setState({ anDetail: { i: i - 1, shop: det.shop } }) : null,
      suiv: i < pts.length - 1 ? () => this.setState({ anDetail: { i: i + 1, shop: det.shop } }) : null,
      close: () => this.setState({ anDetail: null }),
    };
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
  /* --- les connecteurs : état et dernier passage ------------------------------
   * Lecture paresseuse, comme la réputation : l'écran Diagnostic n'est pas
   * ouvert à chaque visite, et la table ne bouge qu'aux gestes.
   */
  coCharge(force){
    if (this._coEnCours) { return; }
    if (this.D.connecteurs && !force) { return; }
    this._coEnCours = true;
    readOne('/connecteurs').then(d => { this._coEnCours = false;
      this.D.connecteurs = (d && d.connecteurs) || []; this.setState({}); });
  }
  valsConnecteurs(common){
    const liste = this.D.connecteurs;
    common.coChargement = !liste;
    // Un état, une couleur, une phrase : ce qu'il faut faire, pas ce qui s'est
    // passé. « Jamais appelé » n'est pas une anomalie — le connecteur attend
    // son premier geste.
    const ETATS = {
      'ok': ['À jour', '#2d7a3e'],
      'en-echec': ['En échec', '#8D1D2C'],
      'a-configurer': ['À configurer', '#8a5a13'],
      'jamais': ['Jamais appelé', '#666666'],
    };
    common.coLignes = (liste || []).map(c2 => {
      const [txt, coul] = ETATS[c2.etat] || ETATS.jamais;
      return {
        nom: c2.nom, quoi: c2.quoi,
        etat: txt,
        etatSt: 'font-size:10.5px;font-weight:600;border-radius:999px;padding:2px 9px;white-space:nowrap;background:'
          + coul + '1f;color:' + coul,
        succes: c2.dernierSucces ? this.fD(c2.dernierSucces.slice(0, 10)) + ' à ' + c2.dernierSucces.slice(11, 16) : '—',
        appel: c2.dernierAppel ? this.fD(c2.dernierAppel.slice(0, 10)) + ' à ' + c2.dernierAppel.slice(11, 16) : '—',
        passages: c2.passages ? String(c2.passages) : '—',
        detail: c2.erreur || c2.detail || '',
        detailSt: 'font-size:11px;line-height:1.4;text-wrap:pretty;color:' + (c2.erreur ? '#8D1D2C' : 'var(--color-text-muted)'),
      };
    });
  }
  valsDiag(common){
    this.valsConnecteurs(common);
    // --- nettoyage du module marketing : montrer la liste AVANT d'exécuter.
    // La suppression est une action explicite de cet écran, jamais un effet
    // de bord du déploiement.
    const S0 = this.state;
    if (!this.D.marNet && !this._marNetEnCours) {
      this._marNetEnCours = true;
      readOne('/admin/marketing-nettoyage').then(n2 => { this._marNetEnCours = false;
        this.D.marNet = n2 || { indispo: true }; this.setState({}); });
    }
    const mn = this.D.marNet;
    common.marNet = !mn ? { chargement: true } : {
      chargement: false,
      indispo: !!mn.indispo,
      garde: mn.garde || [], tombe: mn.tombe || [],
      dejaFait: mn.dejaFait ? (mn.dejaFait.supprimees || []).length + ' objet(s) supprimés le '
        + String(mn.dejaFait.fait || '').slice(0, 10) : '',
      busy: !!S0.marNetBusy,
      executer: (mn.tombe || []).length ? () => {
        const n3 = mn.tombe.length;
        if (!window.confirm('Supprimer DÉFINITIVEMENT ' + n3 + ' table(s)/vue(s) du module marketing ?\n\n'
          + mn.tombe.map(t => t.nom).join(', ') + '\n\nLes données qu’elles portent seront perdues.')) { return; }
        this.setState({ marNetBusy: true });
        this.api('POST', '/admin/marketing-nettoyage', { confirmer: true }).then(r => {
          this.setState({ marNetBusy: false });
          if (!r || r.ok === false) { return; }
          this.notify((r.supprimees || []).length + ' objet(s) supprimé(s) — journalisé');
          this.D.marNet = null; this.setState({});
        });
      } : null,
    };

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
      caFacturation: '/centrale/facturation',
      caReglages: '/centrale/reglages' }[this.state.screen];
  }
  /* --- fonds marketing & redevances ------------------------------------------ */

  /**
   * Le grand livre du fonds, remis à plat.
   *
   * Le module marketing rend DEUX tableaux par période : `entries` pour les
   * entrées, `exits` pour les sorties. Ne lire que `entries` laissait les
   * sorties invisibles et affichait un solde faux — 3 000 € « alimenté » là
   * où le module annonce 2 335 € de solde. Les totaux de période viennent du
   * module quand il les donne : c'est lui qui tient le fonds.
   *
   * Sert à l'écran Fonds ET au rapprochement des projets : un seul calcul,
   * donc un seul solde.
   */
  fondsMouvements(F){
    const per = ((F && F.ledger && F.ledger.periods) || []);
    const lignes = [], parPeriode = [];
    let entrees = 0, sorties = 0;
    per.forEach(p => {
      const cle = p.period_key || '';
      let e = 0, s = 0;
      [].concat(p.entries || [], p.exits || []).forEach(m => {
        const mt = +m.amount || 0;
        const sort = (m.direction || '') === 'OUT';
        if (sort) { s += mt; } else { e += mt; }
        lignes.push({
          id: m.id != null ? +m.id : null,
          date: this.fD(m.movement_date), jour: m.movement_date || '', periode: cle,
          libelle: m.label || '—',
          sens: sort ? 'sortie' : 'entrée',
          montant: (sort ? '− ' : '+ ') + this.fU(mt),
          col: sort ? 'var(--color-primary)' : '#2d7a3e',
          source: m.source || '', magasin: m.shop_name || '', campagne: m.campaign_name || '',
          levier: m.lever_label || '', levierCol: m.lever_color_hex || '#666666',
          // Une écriture sans magasin porte sur TOUT le réseau : le dire en
          // toutes lettres vaut mieux qu'une case vide qu'on lirait comme un
          // oubli de saisie.
          reseau: !m.shop_name,
          invest: !!m.is_investment, brut2: mt,
          fournisseur: m.supplier_name || '',
          // Une écriture née d'un frais récurrent se corrige sur son modèle,
          // pas ligne à ligne : la corriger ici la ferait réapparaître au
          // prochain passage, à l'ancienne valeur.
          recurrente: m.recurrence_id != null,
          brut: m,
        });
      });
      if (p.entries_total != null) { e = +p.entries_total || 0; }
      if (p.exits_total != null) { s = +p.exits_total || 0; }
      entrees += e; sorties += s;
      parPeriode.push({ cle, entrees: e, sorties: s,
        cloture: p.closing_balance == null ? null : +p.closing_balance });
    });
    return { lignes, parPeriode, entrees, sorties };
  }
  /** « 2026-08-01 » → « Août 2026 ». */
  fPeriode(cle){
    if (!cle) { return '—'; }
    const mo = ((this.M && this.M.MOIS) || [])[+cle.slice(5, 7) - 1];
    return mo ? mo + ' ' + cle.slice(0, 4) : cle;
  }
  /**
   * Le fonds du réseau, lu chez celui qui le tient.
   *
   * Le module marketing est déployé sur le même serveur et porte déjà le grand
   * livre, les leviers et les redevances. Le cockpit les LIT — recopier un
   * grand livre donnerait deux soldes pour le même fonds, et c'est celui qui a
   * tort qu'on regarderait.
   */
  valsFonds(common){
    const S = this.state, D = this.D;
    if (!D.fonds && !this._foEnCours) {
      this._foEnCours = true;
      // Le mois choisi voyage avec la lecture : l'API recalcule CA, taux et
      // dû des redevances pour cette période-là.
      const q = S.foRoyMois ? '?mois=' + encodeURIComponent(S.foRoyMois) : '';
      readOne('/fonds' + q).then(f => { this._foEnCours = false;
        this.D.fonds = f || { erreurs: ['module injoignable'] }; this.setState({}); });
    }
    const f = D.fonds;
    common.foChargement = !f;
    if (!f) { return; }
    common.foErreurs = f.erreurs || [];
    common.foSource = f.source || '';
    common.foBase = f.base || '';

    // --- grand livre : entrées et sorties, MOIS par mois.
    //
    // Soixante lignes à plat ne disaient ni ce qu'un mois a encaissé, ni ce
    // qu'il a dépensé, ni où en était la caisse à sa clôture. Chaque mois
    // devient une section — entrées d'abord, sorties ensuite — coiffée de ses
    // trois chiffres : entrées, sorties, solde en fin de mois.
    const L = f.ledger || {};
    const periodes = Array.isArray(L.periods) ? L.periods : [];
    const mv = this.fondsMouvements(f);
    const entrees = mv.entrees, sorties = mv.sorties;
    const lignes = mv.lignes;
    const parMois = {};
    lignes.forEach(l => { (parMois[l.periode] = parMois[l.periode] || []).push(l); });
    const totaux = {};
    mv.parPeriode.forEach(p => { totaux[p.cle] = p; });
    // L'ordre des mois SE CHOISIT : récent d'abord pour lire la caisse,
    // ancien d'abord pour suivre l'histoire. Et chaque mois est un BADGE :
    // cliqué, le grand livre ne montre plus que lui — recliqué, tout revient.
    const ordreMois = this.state.foOrdreMois === 'ancien' ? 'ancien' : 'recent';
    common.foOrdreBtns = [['recent', 'Récent ↓'], ['ancien', 'Ancien ↑']].map(([k, nom]) => ({
      k, nom, on: ordreMois === k, go: () => this.setState({ foOrdreMois: k }) }));
    let cles = Object.keys(parMois).sort();
    if (ordreMois === 'recent') { cles = cles.reverse(); }
    const moisSel = this.state.foMoisSel && cles.indexOf(this.state.foMoisSel) >= 0 ? this.state.foMoisSel : '';
    common.foMoisBadges = cles.map(cle => ({ cle, nom: this.fPeriode(cle), on: cle === moisSel,
      go: () => this.setState({ foMoisSel: cle === moisSel ? '' : cle }) }));
    const clesVisibles = moisSel ? [moisSel] : cles;
    const recentes = (a, b) => (a.jour < b.jour ? 1 : a.jour > b.jour ? -1 : 0);
    let visibles = 0;
    common.foMoisGroupes = [];
    for (const cle of clesVisibles) {
      const duMois = parMois[cle];
      // La borne d'affichage coupe ENTRE deux mois, jamais au milieu d'un :
      // un mois amputé de la moitié de ses lignes montrerait un solde qu'on
      // ne peut pas recompter depuis ce qui est affiché. Un mois choisi au
      // badge s'affiche entier, quelle que soit sa taille.
      if (!moisSel && common.foMoisGroupes.length && visibles + duMois.length > 60) { break; }
      visibles += duMois.length;
      const t = totaux[cle] || { entrees: 0, sorties: 0, cloture: null };
      // Le sous-total d'investissement du mois : la part des sorties qui
      // équipe plutôt qu'elle ne consomme. Tu n'apparais que si tu existes.
      const invest = duMois.filter(l => l.sens === 'sortie' && l.invest)
        .reduce((a, l) => a + (l.brut2 || 0), 0);
      common.foMoisGroupes.push({
        cle, nom: this.fPeriode(cle),
        entrees: duMois.filter(l => l.sens === 'entrée').sort(recentes),
        sorties: duMois.filter(l => l.sens === 'sortie').sort(recentes),
        totEntrees: '+ ' + this.fU(t.entrees), totSorties: '− ' + this.fU(t.sorties),
        totInvest: invest > 0 ? 'dont investissements ' + this.fU(invest) : '',
        // Le solde de clôture vient du calcul séquentiel de fondsMouvements :
        // c'est le même cumul que la tuile « Solde », arrêté à ce mois-là.
        solde: t.cloture == null ? '—' : this.fU(t.cloture),
        soldeCol: (t.cloture == null ? 0 : t.cloture) >= 0 ? '#2d7a3e' : 'var(--color-primary)',
      });
    }
    common.foTronque = moisSel ? 0 : lignes.length - visibles;
    common.foVide = !lignes.length;

    // --- le sous-total PAR AN : entrées, sorties, dont investissements.
    // Toutes lignes confondues, pas seulement celles affichées — un sous-total
    // qui dépendrait de la borne d'affichage mentirait.
    const parAn = {};
    lignes.forEach(l => { const an = (l.periode || '').slice(0, 4);
      if (!an) { return; }
      const b2 = parAn[an] = parAn[an] || { entrees: 0, sorties: 0, invest: 0 };
      if (l.sens === 'sortie') { b2.sorties += l.brut2 || 0; if (l.invest) { b2.invest += l.brut2 || 0; } }
      else { b2.entrees += l.brut2 || 0; } });
    let ans = Object.keys(parAn).sort();
    if (ordreMois === 'recent') { ans = ans.reverse(); }
    common.foAnnees = ans.map(an => ({ an,
      entrees: '+ ' + this.fU(parAn[an].entrees), sorties: '− ' + this.fU(parAn[an].sorties),
      invest: parAn[an].invest > 0 ? 'dont investissements ' + this.fU(parAn[an].invest) : '' }));
    common.foAnneesN = ans.length;
    // Le solde vient du module quand il le donne : c'est LUI qui arrête le
    // fonds. Le recalculer d'un côté et l'afficher de l'autre finirait par
    // donner deux chiffres pour la même caisse.
    const solde = L.closing_balance != null ? +L.closing_balance : entrees - sorties;
    common.foTuiles = [
      { k: 'Alimenté', v: this.fE(entrees), aide: 'ce que le réseau verse au fonds' },
      { k: 'Dépensé', v: this.fE(sorties), aide: 'ce que le fonds a financé' },
      { k: 'Solde', v: this.fE(solde),
        aide: solde >= 0 ? 'disponible' : 'engagé au-delà de l’alimenté',
        col: solde >= 0 ? '#2d7a3e' : 'var(--color-primary)' },
      { k: 'Mouvements', v: String(lignes.length), aide: periodes.length + ' période(s)' },
    ];

    // --- leviers : où l'argent est allé, et ce qu'il a rendu.
    const depMax = (f.leviers || []).reduce((a, l) => Math.max(a, +l.spent_amount || 0), 0);
    common.foLeviers = (f.leviers || []).map(l => {
      const dep = +l.spent_amount || 0;
      return {
        nom: l.lever_label || l.lever_code || '—', couleur: l.color_hex || '#666666',
        depense: this.fU(dep),
        part: sorties > 0 ? Math.round(100 * dep / sorties) + ' % des sorties' : '',
        barre: depMax > 0 ? Math.max(0, Math.min(100, 100 * dep / depMax)) : 0,
        roi: l.roi_value == null ? '—' : '×' + (+l.roi_value).toFixed(1).replace('.', ','),
        // Un levier sans dépense n'a pas de ROI à montrer : c'est une absence,
        // pas un zéro.
        inactif: dep === 0,
      };
    });
    common.foLevActifs = common.foLeviers.filter(l => !l.inactif).length;
    // Une seule rangée : les sept leviers se comparent d'un coup d'œil, ce
    // qu'une colonne empilée ne permet pas.
    common.foLevNb = common.foLeviers.length;
    // Les sorties non rattachées : un levier sans mouvement n'est pas la même
    // chose qu'une dépense sans levier, et c'est la seconde qui pose problème.
    const sansLev = lignes.filter(l => l.sens === 'sortie' && !l.levier).length;
    common.foLevOrphelines = sansLev
      ? sansLev + ' sortie(s) ne portent aucun levier : elles pèsent sur le solde sans dire ce qu’elles développent.'
      : '';

    // --- le total PAR FOURNISSEUR : ce que le fonds a payé à chacun —
    // Collectif MKTG, Dream Big SRL… — toutes lignes confondues, avec la part
    // investissement et, s'il y en a, ce qui est revenu (avoirs). Une ligne se
    // DÉPLIE : les écritures du fournisseur, dates et montants, sans quitter
    // l'écran. Les sorties sans fournisseur sont comptées à part — les taire
    // ferait croire que le tableau couvre toute la dépense.
    const parFourn = {};
    lignes.forEach(l => {
      if (!l.fournisseur) { return; }
      const b2 = parFourn[l.fournisseur] = parFourn[l.fournisseur]
        || { paye: 0, revenu: 0, invest: 0, n: 0, mvts: [] };
      b2.n++;
      if (l.sens === 'sortie') { b2.paye += l.brut2 || 0; if (l.invest) { b2.invest += l.brut2 || 0; } }
      else { b2.revenu += l.brut2 || 0; }
      b2.mvts.push(l);
    });
    const fournSel = this.state.foFournSel || '';
    common.foFournTotaux = Object.keys(parFourn)
      .sort((a, b) => parFourn[b].paye - parFourn[a].paye)
      .map(nom => { const b2 = parFourn[nom];
        return { nom, n: b2.n,
          paye: this.fU(b2.paye),
          invest: b2.invest > 0 ? 'dont investissements ' + this.fU(b2.invest) : '',
          revenu: b2.revenu > 0 ? 'reçu en retour ' + this.fU(b2.revenu) : '',
          ouvert: fournSel === nom,
          ouvrir: () => this.setState({ foFournSel: fournSel === nom ? '' : nom }),
          mvts: fournSel === nom ? b2.mvts.slice().sort((a2, c2) => (a2.jour < c2.jour ? 1 : -1))
            .map(m2 => ({ date: m2.date, libelle: m2.libelle, montant: m2.montant, col: m2.col,
              invest: m2.invest })) : [] }; });
    const sortiesSansFourn = lignes.filter(l => l.sens === 'sortie' && !l.fournisseur)
      .reduce((a, l) => a + (l.brut2 || 0), 0);
    common.foFournSans = (common.foFournTotaux.length && sortiesSansFourn > 0)
      ? this.fU(sortiesSansFourn) + ' de sorties ne nomment aucun fournisseur — elles n’apparaissent dans aucun total ci-dessus.'
      : '';

    // --- redevances : un tableau CLIENTS × SORTES, sur le mois choisi.
    //
    //     Une ligne par client (magasin), une colonne par sorte de redevance
    //     avec son taux et le montant du mois, et le bouton « Insérer au
    //     grand livre » au bout de la ligne. Le CA vient de l'API de ventes
    //     (borné au jour même si le mois est en cours), les taux de la fiche
    //     boutique. Au fonds ne part que la sorte MARKETING : les autres
    //     sortes sont des revenus de la marque, les colonnes le disent.
    const R = f.royalties || {};
    common.foMois = R.month || '';
    // La période se choisit : changer de mois vide le cache et relit /fonds
    // avec ?mois=… — l'API recalcule CA, taux et dû pour cette période-là.
    common.foMoisChoisi = S.foRoyMois || common.foMois;
    common.foMoisMax = String((this.M && this.M.TODAY) || new Date().toISOString()).slice(0, 7);
    common.foMoisSet = e => { const v = e.target.value;
      if (!v || v === (this.state.foRoyMois || common.foMois)) { return; }
      this.D.fonds = null;
      this.setState({ foRoyMois: v, foRoyPlan: null });
    };
    // Les colonnes : les sortes qu'au moins un client porte, dans l'ordre
    // canonique de la fiche boutique. La sorte Marketing est marquée — c'est
    // la seule qui entre au grand livre du fonds. « Générale » n'existe pas :
    // le champ global de la fiche n'est pas un type de redevance.
    const ORDRE_SORTES = ['Marketing', 'Marque', 'Assistance'];
    const presentes = {};
    (R.shops || []).forEach(s => {
      (s.rates || []).forEach(t => { if (t.label) { presentes[t.label] = true; } });
      (s.dues || []).forEach(d2 => { if (d2.label) { presentes[d2.label] = true; } });
    });
    common.foRoyTypes = ORDRE_SORTES.filter(nom => presentes[nom])
      .map(nom => ({ nom, auFonds: nom === 'Marketing' }));
    const pct = v => v == null ? '' : String(v).replace('.', ',') + ' %';
    common.foRoyalties = (R.shops || []).map(s => {
      const ca = s.revenue_amount == null ? null : +s.revenue_amount;
      const ecrit = (s.movements || []).reduce((a, m) => a + (+m.amount || 0), 0);
      const du = s.due_theorique != null ? +s.due_theorique : (ecrit > 0 ? ecrit : null);
      const duMkt = s.due_marketing == null ? null : +s.due_marketing;
      const nom = s.shop_name || ('Magasin ' + s.shop_id);
      // Une cellule par sorte : le taux, et le montant du mois s'il se
      // calcule. Taux sans montant = CA manquant ou redevances désactivées.
      const parSorte = {};
      (s.rates || []).forEach(t => { if (t.label) { parSorte[t.label] = { taux: pct(t.rate_pct), montant: '' }; } });
      (s.dues || []).forEach(d2 => { if (d2.label) {
        const cell = parSorte[d2.label] || (parSorte[d2.label] = { taux: pct(d2.rate_pct), montant: '' });
        cell.montant = this.fU(+d2.amount || 0);
      } });
      return { nom, ville: s.city || '',
        ca: ca == null ? '—' : this.fE(ca),
        cellules: common.foRoyTypes.map(t => parSorte[t.nom] || { taux: '—', montant: '' }),
        du: du != null ? this.fU(du) : '—',
        // Le bouton de la ligne : la redevance MARKETING de ce client, pour
        // le mois affiché — le libellé de l'écriture porte la sorte et le
        // montant. Déjà écrite = un rappel, pas un second bouton : le
        // serveur est idempotent, mais un bouton qui ne fera rien ment.
        inserer: s.royalties_enabled !== false && duMkt != null && duMkt > 0 && !ecrit
          ? () => this.foRoyInserer(R.month, s.shop_id, nom, duMkt) : null,
        ecrit: ecrit > 0 ? 'au grand livre : ' + this.fU(ecrit) : '',
        // Sans chiffre d'affaires, la redevance ne peut pas être calculée :
        // le dire vaut mieux qu'un tiret qu'on lirait comme un zéro. Et un
        // client sans taux marketing ne versera RIEN au fonds — autant le
        // dire avant qu'on cherche sa ligne dans les entrées du mois.
        manque: ca == null
          ? 'chiffre d’affaires du mois non repris'
          : (s.royalties_enabled === false ? 'redevances désactivées pour ce magasin'
            : (du != null && duMkt == null ? 'pas de taux marketing — rien ne part au fonds' : '')) };
    });
    common.foRoyaltiesVide = !common.foRoyalties.length;
    // « Écrire au fonds » : l'APERÇU d'abord — les lignes exactes, une par
    // magasin, redevance MARKETING seule —, l'écriture après confirmation. Le
    // serveur est idempotent : les lignes déjà passées restent en place,
    // jamais doublées.
    const rp = S.foRoyPlan || null;
    common.foRoyEcrire = R.month && !rp && !common.foRoyaltiesVide ? () => this.foRoyPlanifier(R.month) : null;
    common.foRoyPlan = !rp ? null : {
      busy: !!rp.busy, err: rp.err || '', mois: rp.month || '',
      lignes: (rp.lignes || []).map(l => ({
        magasin: l.magasin || '', sorte: l.sorte || '',
        taux: l.taux_pct != null ? l.taux_pct + ' %' : '',
        montant: this.fU(+l.montant || 0), deja: !!l.deja,
      })),
      total: this.fU(+rp.aEcrire || 0), nDeja: +rp.dejaPassees || 0,
      vide: !(rp.lignes || []).some(l => !l.deja),
      confirmer: () => this.foRoyConfirmer(),
      fermer: () => this.setState({ foRoyPlan: null }),
    };
    common.foRoySource = R.source || '';
    common.foRoyNote = R.facturesNote || '';
    common.foErp = (R.erp && R.erp.available === false) ? (R.erp.reason || 'reprise ERP indisponible') : '';

    // --- saisie : tout se tient depuis le pilotage réseau.
    //
    // Le module marketing reste le SEUL à tenir le grand livre — le cockpit ne
    // recopie rien, il adresse ses routes. Ce qui change, c'est qu'on n'a plus
    // à ouvrir l'autre application pour écrire une ligne.
    const S2 = this.state;
    const M2 = ((this.M || {}).MOIS) || [];
    common.foSources = ['ROYALTY', 'REMISE_FOURNISSEUR', 'AGENCE', 'PRODUCTION', 'MEDIA', 'AUTRE'];
    common.foMagasinsOpts = (f.magasins || []).map(m => ({ id: String(m.id), nom: m.name || ('Magasin ' + m.id) }));
    common.foCampagnesOpts = (f.campagnes || []).map(c2 => ({ id: String(c2.id), nom: c2.name || ('Campagne ' + c2.id) }));
    common.foLeviersOpts = (f.leviers || []).filter(l => l.lever_id != null)
      .map(l => ({ id: String(l.lever_id), nom: l.lever_label || l.lever_code || ('Levier ' + l.lever_id) }));
    // Fournisseurs : ceux de la centrale d'achat. Le champ reste libre — un
    // nouveau nom s'y tape et rejoint le référentiel à l'enregistrement.
    common.foFournisseurs = (f.fournisseurs || []).map(x2 => x2.nom).filter(Boolean);

    const vide = (sens) => ({ sens, id: null, date: (this.M && this.M.TODAY) || '',
      libelle: '', montant: '', source: 'AUTRE', magasin: '', campagne: '', levier: '',
      fournisseur: '', piece: '', invest: false, busy: false, err: '' });
    const fm = S2.foForm || null;
    common.foNouveau = sens => this.setState({ foForm: vide(sens) });
    common.foForm = !fm ? null : {
      titre: fm.id ? 'Corriger l’écriture' : (fm.sens === 'IN' ? 'Alimenter le fonds' : 'Enregistrer une dépense'),
      sens: fm.sens, id: fm.id, busy: !!fm.busy, err: fm.err || '',
      champs: { date: fm.date, libelle: fm.libelle, montant: fm.montant, source: fm.source,
        magasin: fm.magasin, campagne: fm.campagne, levier: fm.levier,
        fournisseur: fm.fournisseur, piece: fm.piece },
      sensBtns: [['IN', 'Entrée — le réseau alimente'], ['OUT', 'Sortie — le fonds finance']]
        .map(([v, nom]) => ({ v, nom, on: fm.sens === v, pick: () => this.foPatch({ sens: v }) })),
      // Un four n'est pas une campagne : la dépense se qualifie au moment où
      // on l'écrit, et le grand livre la sous-totalise à part.
      invest: !!fm.invest,
      investBascule: fm.sens === 'OUT' ? () => this.foPatch({ invest: !fm.invest }) : null,
      set: k => e => this.foPatch({ [k]: e.target.value }),
      envoyer: () => this.foEnvoyer(),
      fermer: () => this.setState({ foForm: null }),
      // Une dépense sans levier ne dit pas ce qu'elle développe : on le
      // signale sans l'interdire, c'est une saisie, pas un dogme.
      avert: fm.sens === 'OUT' && !fm.levier
        ? 'Sans levier, cette sortie pèsera sur le solde sans dire ce qu’elle développe.' : '',
    };
    // Corriger / supprimer : posés sur TOUTES les lignes — les groupes par
    // mois référencent ces mêmes objets, les boutons suivent.
    lignes.forEach(l => {
      l.editer = l.id && !l.recurrente ? () => this.setState({ foForm: {
        sens: (l.brut.direction || 'OUT') === 'IN' ? 'IN' : 'OUT', id: l.id,
        date: l.brut.movement_date || '', libelle: l.brut.label || '',
        montant: l.brut.amount != null ? String(l.brut.amount) : '',
        source: l.brut.source || 'AUTRE',
        magasin: l.brut.shop_id != null ? String(l.brut.shop_id) : '',
        campagne: l.brut.campaign_id != null ? String(l.brut.campaign_id) : '',
        levier: l.brut.lever_id != null ? String(l.brut.lever_id) : '',
        fournisseur: l.brut.supplier_name || '', piece: l.brut.document_ref || '',
        invest: !!l.brut.is_investment,
        busy: false, err: '' } }) : null;
      l.supprimer = l.id && !l.recurrente ? () => this.foSupprimer(l.id, l.libelle) : null;
    });

    // --- frais récurrents : un modèle, autant d'échéances écrites d'un coup.
    const fr = S2.foRec || null;
    common.foRecurrences = (f.recurrences || []).map(r => ({
      id: r.id, libelle: r.label || '—',
      sens: (r.direction || '') === 'IN' ? 'entrée' : 'sortie',
      col: (r.direction || '') === 'IN' ? '#2d7a3e' : 'var(--color-primary)',
      montant: this.fU(+r.amount || 0),
      rythme: ({ month: 'chaque mois', quarter: 'chaque trimestre', year: 'chaque année' })[r.frequency] || (r.frequency || ''),
      periode: this.fD(r.starts_on) + ' → ' + this.fD(r.ends_on),
      magasin: r.shop_name || 'tout le réseau',
      supprimer: () => this.foRecSupprimer(r.id, r.label || ''),
    }));
    common.foRecVide = !common.foRecurrences.length;
    common.foRecNouveau = () => this.setState({ foRec: { sens: 'OUT', frequence: 'month',
      semaines: '6', libelle: '', montant: '', debut: (this.M && this.M.TODAY) || '', fin: '',
      source: 'AUTRE', magasin: '', levier: '', busy: false, err: '' } });
    common.foRecForm = !fr ? null : {
      busy: !!fr.busy, err: fr.err || '',
      champs: { libelle: fr.libelle, montant: fr.montant, debut: fr.debut, fin: fr.fin,
        source: fr.source, magasin: fr.magasin, levier: fr.levier },
      sensBtns: [['IN', 'Entrée'], ['OUT', 'Sortie']].map(([v, nom]) => ({ v, nom, on: fr.sens === v,
        pick: () => this.foRecPatch({ sens: v }) })),
      rythmes: [['month', 'Chaque mois'], ['weeks', 'Toutes les N semaines'], ['quarter', 'Chaque trimestre'], ['year', 'Chaque année']]
        .map(([v, nom]) => ({ v, nom, on: fr.frequence === v, pick: () => this.foRecPatch({ frequence: v }) })),
      semaines: fr.semaines || '6',
      setSemaines: e => this.foRecPatch({ semaines: e.target.value }),
      estSemaines: fr.frequence === 'weeks',
      // L'aperçu : on VOIT les dates avant d'écrire. Calculé pour tous les
      // rythmes — pour ceux du module c'est une prévision, il reste le maître.
      apercu: this.foRecApercu(fr),
      set: k => e => this.foRecPatch({ [k]: e.target.value }),
      envoyer: () => this.foRecEnvoyer(),
      fermer: () => this.setState({ foRec: null }),
    };

    // --- redevances : ce que la version déployée du module ne sert pas encore.
    common.foManque = (f.manque || []).map(m => ({ champ: m.champ, quoi: m.quoi, source: m.source }));
  }

  /* --- campagnes marketing : calendrier, liste, types ------------------------ */

  /**
   * Les trois écrans repris du module marketing.
   *
   * Un seul chargement (`/marketing`) sert le calendrier, la liste et le
   * référentiel des types : ce sont trois lectures des mêmes campagnes.
   */
  valsMkt(common){
    const S = this.state, D = this.D;
    if (!D.mkt && !this._mktEnCours) {
      this._mktEnCours = true;
      readOne('/marketing').then(m2 => { this._mktEnCours = false;
        this.D.mkt = m2 || { indispo: true, raison: 'lecture impossible' }; this.setState({}); });
    }
    const mk = D.mkt;
    common.mkChargement = !mk;
    if (!mk) { return; }
    common.mkIndispo = !!mk.indispo;
    common.mkRaison = mk.raison || '';
    if (mk.indispo) { return; }
    const camps = mk.campagnes || [];
    const recharge = () => { this.D.mkt = null; this.setState({}); };

    // --- calendrier : une ligne par campagne, une barre sur ses mois.
    const annee = S.mkAnnee || new Date((camps[0] || {}).debut || this.M.TODAY).getFullYear();
    common.mkAnnee = String(annee);
    common.mkAnneePrec = () => this.setState({ mkAnnee: annee - 1 });
    common.mkAnneeSuiv = () => this.setState({ mkAnnee: annee + 1 });
    common.mkMois = (this.M.MOIS || []);
    common.mkCalLignes = camps
      .filter(c2 => c2.debut && c2.fin)
      .filter(c2 => +String(c2.debut).slice(0, 4) <= annee && +String(c2.fin).slice(0, 4) >= annee)
      .map(c2 => {
        const d0 = new Date(c2.debut + 'T00:00:00'), f0 = new Date(c2.fin + 'T00:00:00');
        const m1 = d0.getFullYear() < annee ? 0 : d0.getMonth();
        const m2 = f0.getFullYear() > annee ? 11 : f0.getMonth();
        return { id: c2.id, nom: c2.nom, type: c2.type || '—',
          couleur: c2.typeCouleur || '#8D1D2C',
          statutNom: c2.statutNom, statutTexte: c2.statutTexte, statutFond: c2.statutFond,
          debut: this.fD(c2.debut), fin: this.fD(c2.fin),
          col1: m1 + 1, col2: m2 + 2,
          ouvrir: () => this.setState({ mkEdit: this.mkVersForm(c2) }) };
      });
    common.mkCalVide = !common.mkCalLignes.length;

    // --- liste des campagnes.
    common.mkCampagnes = camps.map(c2 => ({
      id: c2.id, nom: c2.nom, type: c2.type || '—', typeCouleur: c2.typeCouleur || '#666',
      scope: c2.scope === 'LOCALE' ? 'Locale' : 'Réseau',
      statutNom: c2.statutNom, statutTexte: c2.statutTexte, statutFond: c2.statutFond,
      periode: (c2.debut ? this.fD(c2.debut) : '—') + ' → ' + (c2.fin ? this.fD(c2.fin) : '—'),
      budget: this.fE(c2.budget), depense: c2.depense ? this.fE(c2.depense) : '—',
      nBoutiques: c2.nBoutiques,
      editer: () => this.setState({ mkEdit: this.mkVersForm(c2) }),
      note: () => this.mkNoteOuvrir(c2.id),
      // L'assistant s'ouvre pour TOUTE campagne, pas seulement les brouillons.
      // Il était réservé aux brouillons — « une planifiée se corrige dans la
      // carte » —, mais la carte ne porte ni l'offre, ni les canaux, ni les
      // documents joints : une campagne lancée n'avait plus aucun moyen d'être
      // modifiée. L'assistant réécrit désormais le statut qu'elle a.
      reprendre: () => {
        window.location.assign(new URL('assistant/?id=' + c2.id, window.location.href).href);
      },
      reprendreNom: c2.statut === 'draft' ? 'Reprendre' : 'Assistant',
      supprimer: () => {
        if (!window.confirm('Supprimer définitivement la campagne « ' + c2.nom + ' » ?')) { return; }
        this.api('DELETE', '/marketing/campagne/' + c2.id).then(r => {
          if (r && r.ok === false) { return; }
          this.notify('Campagne supprimée'); recharge();
        });
      },
    }));
    common.mkVide = !camps.length;
    // La création rapide est retirée : toute campagne naît COMPLÈTE, dans
    // l'assistant. La carte d'édition ne sert plus qu'à corriger l'existant.

    // --- vignettes « en attente » : brouillons et planifiées, à finir ou à
    // lancer. Illustration, nom complet, levier du type — trois par rangée.
    const parTypeId = {};
    (mk.types || []).forEach(t => { parTypeId[String(t.id)] = t; });
    const imageSrc = im => !im ? null
      : (im.startsWith('http') || im.startsWith('/')) ? im : 'assistant/' + im;
    common.mkAttente = camps
      .filter(c2 => c2.statut === 'draft' || c2.statut === 'planned')
      .map(c2 => {
        const t = parTypeId[String(c2.typeId)] || {};
        return { id: c2.id, nom: c2.nom, image: imageSrc(c2.image),
          couleur: c2.typeCouleur || '#8D1D2C', type: c2.type || '—',
          levier: t.levier || '—',
          statutNom: c2.statutNom, statutTexte: c2.statutTexte, statutFond: c2.statutFond,
          periode: (c2.debut ? this.fD(c2.debut) : '—') + ' → ' + (c2.fin ? this.fD(c2.fin) : '—'),
          // La vignette ouvre l'assistant : c'est là que tout se règle, y
          // compris pour une campagne déjà planifiée.
          ouvrir: () => { window.location.assign(new URL('assistant/?id=' + c2.id, window.location.href).href); },
          corriger: () => this.setState({ mkEdit: this.mkVersForm(c2) }),
          // La note pour les franchisés : une page A4, imprimable, et le
          // courrier qui la porte. Bouton à part sur la vignette — ouvrir la
          // campagne et l'expliquer au réseau ne sont pas le même geste.
          note: () => this.mkNoteOuvrir(c2.id) };
      });
    // --- la note de campagne : une page A4 pour le réseau, et le courrier
    // qui la porte. Chargée à l'ouverture, jamais avant : elle interroge le
    // panel pour la référence de l'an dernier.
    const nt = S.mkNote;
    const ntPatch = pl => this.setState(s2 => ({ mkNote: Object.assign({}, s2.mkNote, pl) }));
    common.mkNote = !nt ? null : {
      chargement: !!nt.chargement,
      err: nt.err || '',
      fermer: () => this.setState({ mkNote: null }),
      titre: (nt.d && nt.d.campagne ? nt.d.campagne.nom : 'Note de campagne'),
      onglet: nt.onglet || 'note',
      versNote: () => ntPatch({ onglet: 'note' }),
      versMail: () => ntPatch({ onglet: 'mail' }),
      versJournal: () => ntPatch({ onglet: 'journal' }),
      stNote: this.tabBtn((nt.onglet || 'note') === 'note'),
      stMail: this.tabBtn(nt.onglet === 'mail'),
      stJournal: this.tabBtn(nt.onglet === 'journal'),
    };
    if (nt && nt.d && !nt.chargement) {
      const D2 = nt.d, camp = D2.campagne || {};
      const dest = nt.dest || (D2.destinataires || []).map(x2 => Object.assign({}, x2, { on: !!x2.adresse }));
      const cfg = nt.cfg || Object.assign({}, D2.config || {});
      const setDest = (i, pl) => ntPatch({ dest: dest.map((x2, j) => j === i ? Object.assign({}, x2, pl) : x2) });
      const setCfg = k => e => ntPatch({ cfg: Object.assign({}, cfg, { [k]: e.target.value }) });
      const prets = dest.filter(x2 => x2.on && x2.adresse);

      Object.assign(common.mkNote, {
        // L'aperçu EST le fichier : la page imprimée et le PDF joint sortent du
        // même HTML, produit par le serveur. Deux rendus auraient fini par
        // montrer deux choses.
        apercu: D2.apercuPdf || '',
        // Le mot du responsable : la seule partie de la page écrite par
        // quelqu'un. Signé du consultant connecté — nom et fonction —, et la
        // signature reste modifiable : le mot peut être celui du franchiseur.
        mot: (nt.mot != null ? nt.mot : ((D2.mot || {}).texte || '')),
        setMot: e => ntPatch({ mot: e.target.value }),
        motNom: (nt.motNom != null ? nt.motNom : ((D2.mot || {}).nom || '')),
        setMotNom: e => ntPatch({ motNom: e.target.value }),
        motFonction: (nt.motFonction != null ? nt.motFonction : ((D2.mot || {}).fonction || '')),
        setMotFonction: e => ntPatch({ motFonction: e.target.value }),
        motEtat: nt.motEtat || '',
        enregistrerMot: () => {
          ntPatch({ motEtat: 'en-cours' });
          this.api('PUT', '/marketing/campagne/' + camp.id + '/note-mot', {
            texte: (nt.mot != null ? nt.mot : ((D2.mot || {}).texte || '')),
            nom: (nt.motNom != null ? nt.motNom : ((D2.mot || {}).nom || '')),
            fonction: (nt.motFonction != null ? nt.motFonction : ((D2.mot || {}).fonction || '')),
          }).then(r => {
            if (!r || r.error) { ntPatch({ motEtat: '', err: (r && r.error) || 'Mot non enregistré.' }); return; }
            // La page se recharge : l'aperçu EST le fichier, il doit porter le
            // mot qu'on vient d'écrire.
            this.mkNoteOuvrir(camp.id, 'note');
          });
        },
        apercuMail: D2.apercuMail || '',
        sousTitre: (camp.type || '') + ' · ' + (camp.portee || '') + ' · '
          + (camp.du ? this.fD(camp.du) : '—') + ' → ' + (camp.fin || camp.au ? this.fD(camp.au) : '—'),
        moteurPdf: !!D2.moteurPdf,
        // Sans moteur sur le serveur, on le DIT : le navigateur imprime le même
        // document, et personne n'attend un fichier qui ne viendra pas.
        moteurNote: D2.moteurPdf ? ''
          : 'Aucun moteur PDF sur ce serveur : « Imprimer » produit le même document depuis le navigateur (choisissez « Enregistrer en PDF »). Le courrier partira sans pièce jointe, et le dira.',
        imprimer: () => {
          const f = document.getElementById('note-apercu');
          if (f && f.contentWindow) { f.contentWindow.focus(); f.contentWindow.print(); }
        },
        telecharger: !D2.moteurPdf ? null : () => {
          try { window.open(API_BASE + '/marketing/campagne/' + camp.id + '/note.pdf', '_blank'); } catch (e2) {}
        },
        fichier: D2.fichier || '',
        // Ce qui partira EN PLUS de la note : les documents cochés dans
        // l'assistant. En lecture seule ici — ils se gèrent là où on les
        // dépose, sinon la même case se règlerait à deux endroits.
        annexes: (camp.annexes || []).filter(a => a.enMail).map(a => ({
          nom: a.nom, type: a.type || '—', taille: a.tailleTxt,
          perdu: !a.existe,
        })),
        annexesNon: (camp.annexes || []).filter(a => !a.enMail).length,
        dest: dest.map((x2, i) => ({
          magasin: x2.magasin, franchise: x2.franchise || '—',
          adresse: x2.adresse, on: !!x2.on,
          manque: !x2.adresse,
          setAdresse: e => setDest(i, { adresse: e.target.value, on: !!e.target.value }),
          basculer: () => setDest(i, { on: !x2.on }),
        })),
        // Les copies : l'agence de la campagne et les consultants. Une liste à
        // cocher, pas un champ libre — une adresse retapée à chaque envoi finit
        // par partir chez quelqu'un d'autre.
        // L'agence d'abord, les consultants ensuite : ce ne sont pas les mêmes
        // destinataires — l'une produit la campagne, les autres la suivent.
        copiesAgence: (nt.copies || (D2.copies || [])).map((x2, i) => Object.assign({}, x2, { i }))
          .filter(x2 => x2.groupe === 'agence').map(x2 => ({
            nom: x2.nom, adresse: x2.adresse, role: x2.role, on: !!x2.on,
            sansAdresse: !!x2.sansAdresse,
            basculer: x2.sansAdresse ? null : () => ntPatch({ copies: (nt.copies || (D2.copies || []))
              .map((y, j) => j === x2.i ? Object.assign({}, y, { on: !y.on }) : y) }),
          })),
        copiesConsultants: (nt.copies || (D2.copies || [])).map((x2, i) => Object.assign({}, x2, { i }))
          .filter(x2 => x2.groupe !== 'agence').map(x2 => ({
            nom: x2.nom, adresse: x2.adresse, role: x2.role, on: !!x2.on, sansAdresse: false,
            basculer: () => ntPatch({ copies: (nt.copies || (D2.copies || []))
              .map((y, j) => j === x2.i ? Object.assign({}, y, { on: !y.on }) : y) }),
          })),
        copiesVide: !(D2.copies || []).length,
        nPrets: prets.length,
        sansAdresse: dest.filter(x2 => !x2.adresse).map(x2 => x2.magasin),
        // L'agence : nom, site et logo. Le logo est LU dans le navigateur et
        // envoyé en data-URI — le serveur ne reçoit pas de fichier à ranger,
        // et l'image part avec la lettre au lieu d'être un lien vers un
        // cockpit auquel le destinataire n'a pas accès.
        agenceNom: (cfg.agence || {}).nom || '',
        setAgenceNom: e => ntPatch({ cfg: Object.assign({}, cfg, { agence: Object.assign({}, cfg.agence, { nom: e.target.value }) }) }),
        agenceSite: (cfg.agence || {}).site || '',
        setAgenceSite: e => ntPatch({ cfg: Object.assign({}, cfg, { agence: Object.assign({}, cfg.agence, { site: e.target.value }) }) }),
        agenceEmail: (cfg.agence || {}).email || '',
        setAgenceEmail: e => ntPatch({ cfg: Object.assign({}, cfg, { agence: Object.assign({}, cfg.agence, { email: e.target.value }) }) }),
        agenceLogo: (cfg.agence || {}).logo || '',
        agenceLogoErr: nt.logoErr || '',
        setAgenceLogo: e => {
          const f = e.target.files && e.target.files[0];
          if (!f) { return; }
          if (f.size > 500000) { ntPatch({ logoErr: 'Logo trop lourd (' + Math.round(f.size / 1024) + ' Ko) : 500 Ko au plus.' }); return; }
          const r = new FileReader();
          r.onload = () => ntPatch({ logoErr: '', cfg: Object.assign({}, cfg, { agence: Object.assign({}, cfg.agence, { logo: String(r.result) }) }) });
          r.onerror = () => ntPatch({ logoErr: 'Fichier illisible.' });
          r.readAsDataURL(f);
        },
        retirerLogo: !((cfg.agence || {}).logo) ? null
          : () => ntPatch({ cfg: Object.assign({}, cfg, { agence: Object.assign({}, cfg.agence, { logo: '' }) }) }),
        visuelNote: (D2.visuel && D2.visuel.present)
          ? 'Le visuel de la campagne est repris en tête de note et en annexe, page 2 du PDF.'
          : ((D2.visuel && D2.visuel.motif)
            ? 'Pas d’annexe : ' + D2.visuel.motif + '.'
            : 'Cette campagne n’a pas de visuel : la note sort sans annexe. Ajoutez-en un dans l’assistant, étape « Photos produits ».'),
        expediteur: cfg.expediteur || '', setExpediteur: setCfg('expediteur'),
        sujet: cfg.sujet || '', setSujet: setCfg('sujet'),
        intro: cfg.intro || '', setIntro: setCfg('intro'),
        pied: cfg.pied || '', setPied: setCfg('pied'),
        html: cfg.html || '', setHtml: setCfg('html'),
        gabaritOuvert: !!nt.gabarit,
        basculerGabarit: () => ntPatch({ gabarit: !nt.gabarit }),
        variables: '{{campagne}} {{type}} {{du}} {{au}} {{magasin}} {{franchise}} {{objectif}} {{cible}} {{budget}}',
        enregistrerGabarit: () => {
          this.api('PUT', '/marketing/note-config', {
            expediteur: cfg.expediteur, sujet: cfg.sujet, intro: cfg.intro,
            pied: cfg.pied, html: cfg.html, agence: cfg.agence || {},
            copies: (nt.copies || (D2.copies || [])).filter(x2 => x2.on).map(x2 => x2.adresse),
          }).then(r => {
            if (r && r.error) { ntPatch({ err: r.error }); return; }
            this.notify('Gabarit du courrier enregistré');
            this.mkNoteOuvrir(camp.id, nt.onglet);
          });
        },
        envoi: nt.envoi || '',
        envoyer: prets.length === 0 || nt.envoi === 'en-cours' ? null : () => {
            const cc = (nt.copies || (D2.copies || [])).filter(x2 => x2.on);
          if (!window.confirm('Envoyer la note « ' + camp.nom + ' » à ' + prets.length
            + ' magasin(s) depuis ' + (cfg.expediteur || 'l’adresse marketing')
            + (cc.length ? ', en copie à ' + cc.map(x2 => x2.nom).join(', ') : '') + ' ?')) { return; }
          ntPatch({ envoi: 'en-cours', err: '' });
          this.api('POST', '/marketing/campagne/' + camp.id + '/note',
            { destinataires: prets.map(x2 => ({ id: x2.id, adresse: x2.adresse, magasin: x2.magasin, franchise: x2.franchise })),
              copies: (nt.copies || (D2.copies || [])).filter(x2 => x2.on).map(x2 => x2.adresse) })
            .then(r => {
              if (!r || r.error) { ntPatch({ envoi: '', err: (r && r.error) || 'Envoi refusé.' }); return; }
              this.notify(r.message || 'Note envoyée');
              this.mkNoteOuvrir(camp.id, 'journal');
            });
        },
        journal: (D2.journal || []).map(e2 => ({
          quand: e2.quand, type: e2.type,
          etat: e2.type === 'envoye' ? 'Envoyée' : 'Échec',
          etatSt: 'font-size:10.5px;font-weight:600;padding:2px 9px;border-radius:999px;'
            + (e2.type === 'envoye' ? 'background:rgba(45,122,62,.12);color:#2d7a3e' : 'background:rgba(141,29,44,.12);color:#8D1D2C'),
          destinataire: e2.destinataire || '—', detail: e2.detail || '', sujet: e2.sujet || '',
        })),
        journalVide: !(D2.journal || []).length,
      });
    }

    // L'assistant COMPLET (cadrage, offre, objectifs, prix, photos, budget,
    // communication, planning, récap, leads) est désormais HÉBERGÉ PAR LE
    // COCKPIT (page /assistant/, API /api/marketing/) : il travaille sur les
    // mêmes tables, et survivra à la suppression du module marketing.
    // Navigation directe, PAS de nouvel onglet : l'assistant ramène ici par
    // « ← Retour au cockpit », par « Annuler » et à la publication.
    common.mkAssistant = () => {
      window.location.assign(new URL('assistant/', window.location.href).href);
    };

    // --- formulaire (création et correction, même carte).
    const ed = S.mkEdit;
    const patch = pl => this.setState(s2 => ({ mkEdit: Object.assign({}, s2.mkEdit, pl) }));
    common.mkEdit = !ed ? null : {
      id: ed.id, titre: ed.id ? 'Corriger la campagne' : 'Nouvelle campagne',
      nom: ed.nom, setNom: e => patch({ nom: e.target.value }),
      types: (mk.types || []).filter(t => t.actif || String(t.id) === String(ed.typeId))
        .map(t => ({ id: String(t.id), nom: t.nom, on: String(t.id) === String(ed.typeId) })),
      setType: e => patch({ typeId: e.target.value }),
      statuts: (mk.statuts || []).map(st => ({ code: st.code, nom: st.nom, on: st.code === ed.statut,
        pick: () => patch({ statut: st.code }) })),
      scopes: [['RESEAU', 'Réseau'], ['LOCALE', 'Locale']].map(([v, nom]) => ({ v, nom, on: ed.scope === v,
        pick: () => patch({ scope: v }) })),
      debut: ed.debut || '', setDebut: e => patch({ debut: e.target.value }),
      fin: ed.fin || '', setFin: e => patch({ fin: e.target.value }),
      budget: ed.budget, setBudget: e => patch({ budget: e.target.value }),
      busy: !!ed.busy, err: ed.err || '',
      fermer: () => this.setState({ mkEdit: null }),
      envoyer: () => {
        const f2 = this.state.mkEdit || {};
        if (!String(f2.nom || '').trim()) { patch({ err: 'Le nom de la campagne est requis.' }); return; }
        patch({ busy: true, err: '' });
        const corps = { nom: String(f2.nom).trim(), typeId: f2.typeId || null, scope: f2.scope,
          statut: f2.statut, debut: f2.debut || '', fin: f2.fin || '',
          budget: parseFloat(String(f2.budget || '0').replace(',', '.')) || 0 };
        this.api(f2.id ? 'PATCH' : 'POST', '/marketing/campagne' + (f2.id ? '/' + f2.id : ''), corps)
          .then(r => {
            if (!r || r.ok === false) { patch({ busy: false, err: (r && r.error) || 'refusé' }); return; }
            this.setState({ mkEdit: null });
            this.notify(f2.id ? 'Campagne corrigée' : 'Campagne créée');
            this.log('Campagne', corps.nom, f2.id ? 'Corrigée depuis le pilotage' : 'Créée depuis le pilotage');
            recharge();
          });
      },
    };

    // --- référentiel des types.
    //
    // Le formulaire porte les mêmes champs et les mêmes règles que celui du
    // module marketing : description, couleur, icône prise dans la bibliothèque
    // servie par l'API, levier LIÉ (mar_lever) et son badge, KPI attendu. Un
    // type créé ici doit se dessiner exactement comme un type créé là-bas —
    // même carte dans l'assistant, même couleur au calendrier, même tracé à
    // l'impression.
    const leviers = mk.leviers || [];
    const icones = mk.icones || [];
    const parIcone = {};
    icones.forEach(i => { parIcone[i.cle] = i; });

    const ordreIds = (mk.types || []).map(t => t.id);
    const reordonner = (id, sens) => {
      const i = ordreIds.indexOf(id), j = i + sens;
      if (i < 0 || j < 0 || j >= ordreIds.length) { return; }
      const ids = ordreIds.slice();
      ids[i] = ids[j]; ids[j] = id;
      this.api('PUT', '/marketing/types/ordre', { ids }).then(r => {
        if (!r || r.ok === false) { return; }
        this.notify('Ordre des types enregistré'); recharge();
      });
    };

    common.mkTypes = (mk.types || []).map((t, i) => ({
      id: t.id, nom: t.nom, code: t.code, kpi: t.kpi || '—',
      description: t.description || '',
      couleur: t.couleur || '#8D1D2C',
      // Le tracé vient du serveur (colonne icon_path) ; la bibliothèque sert de
      // repli pour les types dont la clé est connue mais le tracé pas encore
      // recopié.
      iconePath: t.iconePath || (t.icone && parIcone[t.icone] ? parIcone[t.icone].path : null),
      levier: t.levier || '', levierCouleur: t.levierCouleur || null,
      levierSt: 'display:inline-flex;align-items:center;gap:5px;font-size:10.5px;font-weight:600;border-radius:999px;padding:2px 9px;white-space:nowrap;background:'
        + (t.levierCouleur || '#78746e') + '1f;color:' + (t.levierCouleur || '#666666'),
      actif: t.actif, nCampagnes: t.nCampagnes,
      premier: i === 0, dernier: i === (mk.types || []).length - 1,
      monter: () => reordonner(t.id, -1),
      descendre: () => reordonner(t.id, 1),
      editer: () => this.setState({ mkTypeForm: {
        id: t.id, code: t.code, nom: t.nom, description: t.description || '',
        couleur: t.couleur || '#8D1D2C', icone: t.icone || '',
        levierId: t.levierId ? String(t.levierId) : '', badge: t.levierBadge || '', kpi: t.kpi || '', err: '' } }),
      basculer: () => this.api('PATCH', '/marketing/type/' + t.id, { actif: !t.actif }).then(r => {
        if (r && r.ok === false) { return; }
        this.notify(t.actif ? '« ' + t.nom + ' » désactivé' : '« ' + t.nom + ' » réactivé'); recharge(); }),
      // Le serveur refuse de supprimer un type porté par des campagnes (409) —
      // il ne désactive pas d'office. L'écran propose alors la désactivation,
      // qui est le geste que l'on voulait.
      supprimer: () => {
        if (!window.confirm('Supprimer le type « ' + t.nom + ' » ?')) { return; }
        this.api('DELETE', '/marketing/type/' + t.id).then(r => {
          if (r && r.error) {
            if (r.campagnes && window.confirm(r.error + '\n\nLe désactiver maintenant ?')) {
              this.api('PATCH', '/marketing/type/' + t.id, { actif: false })
                .then(() => { this.notify('« ' + t.nom + ' » désactivé'); recharge(); });
            } else { this.notify(r.error); }
            return;
          }
          this.notify('Type supprimé'); recharge(); });
      },
    }));

    // --- le formulaire, un seul pour la création et la reprise
    const tf = S.mkTypeForm || null;
    common.mkTypeNouveau = () => this.setState({ mkTypeForm: { id: null, nom: '', description: '',
      couleur: '#8D1D2C', icone: '', levierId: '', badge: '', kpi: '', err: '' } });
    const majF = f2 => this.setState(s2 => ({ mkTypeForm: Object.assign({}, s2.mkTypeForm, f2) }));
    common.mkTypeForm = !tf ? null : {
      edition: tf.id != null, code: tf.code || '',
      titre: tf.id != null ? 'Modifier le type' : 'Nouveau type de campagne',
      err: tf.err || '',
      nom: tf.nom, setNom: e => majF({ nom: e.target.value }),
      description: tf.description, setDescription: e => majF({ description: e.target.value }),
      nbCar: (tf.description || '').length + '/300',
      couleur: tf.couleur, setCouleur: e => majF({ couleur: e.target.value }),
      kpi: tf.kpi, setKpi: e => majF({ kpi: e.target.value }),
      badge: tf.badge, setBadge: e => majF({ badge: e.target.value }),
      levierId: tf.levierId, setLevier: e => majF({ levierId: e.target.value }),
      leviers: [{ val: '', nom: 'Aucun levier' }].concat(leviers.map(l => ({ val: String(l.id), nom: l.nom }))),
      // La bibliothèque est fermée et vient du serveur : le même tracé sera
      // redessiné dans l'assistant, au calendrier et à l'impression.
      icones: icones.map(ic => ({ cle: ic.cle, nom: ic.nom, path: ic.path,
        choisi: tf.icone === ic.cle,
        choisir: () => majF({ icone: tf.icone === ic.cle ? '' : ic.cle }),
        st: 'display:flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:9px;cursor:pointer;background:transparent;border:1px solid '
          + (tf.icone === ic.cle ? 'var(--color-primary)' : 'var(--color-border-tertiary)') })),
      iconeNom: tf.icone && parIcone[tf.icone] ? parIcone[tf.icone].nom : 'Aucune',
      fermer: () => this.setState({ mkTypeForm: null }),
      envoyer: () => {
        const f3 = this.state.mkTypeForm || {};
        if (!String(f3.nom || '').trim()) { majF({ err: 'Le nom du type est obligatoire.' }); return; }
        const corps = { nom: f3.nom, description: f3.description, couleur: f3.couleur,
          icone: f3.icone, levierId: f3.levierId ? +f3.levierId : null, badge: f3.badge, kpi: f3.kpi };
        this.api(f3.id != null ? 'PATCH' : 'POST', '/marketing/type' + (f3.id != null ? '/' + f3.id : ''), corps)
          .then(r => {
            // Le serveur porte les mêmes contrôles que le module (couleur,
            // icône, levier, longueurs) : son message est affiché tel quel
            // plutôt que reformulé ici, sinon les deux se mettent à diverger.
            if (!r || r.error) { majF({ err: (r && r.error) || 'Enregistrement refusé.' }); return; }
            this.setState({ mkTypeForm: null });
            this.notify(f3.id != null ? 'Type modifié' : 'Type créé');
            recharge();
          });
      },
    };
  }
  /* --- usage du catalogue ------------------------------------------------ */

  /**
   * Lit l'usage du catalogue — et le détail d'un magasin quand il est ouvert.
   *
   * Deux routes, deux chargements : le tableau tient en une requête, le détail
   * pèse sept cents lignes par magasin et n'est demandé qu'au clic. Les
   * réponses sont mises en cache par clé de filtre — rouvrir le même magasin
   * ne relit pas.
   */
  usageCharge(){
    const S = this.state;
    const cle = (S.usMois || 6) + '|' + (S.usGroupe || '');
    if (!this._usage) { this._usage = {}; }
    if (this._usage[cle] === undefined && !this._usageEnCours) {
      this._usageEnCours = true;
      readOne('/produits/utilisation?mois=' + (S.usMois || 6)
        + (S.usGroupe ? '&groupe=' + encodeURIComponent(S.usGroupe) : ''))
        .then(d => { this._usageEnCours = false; this._usage[cle] = (d && !d.error) ? d : null; this.setState({}); })
        .catch(() => { this._usageEnCours = false; this._usage[cle] = null; this.setState({}); });
    }
    const sid = S.usShop;
    if (!sid) { return; }
    const cleD = sid + '|' + cle;
    if (!this._usageDet) { this._usageDet = {}; }
    if (this._usageDet[cleD] === undefined && !this._usageDetEnCours) {
      this._usageDetEnCours = true;
      readOne('/produits/utilisation/magasin?shop=' + encodeURIComponent(sid)
        + '&mois=' + (S.usMois || 6)
        + (S.usGroupe ? '&groupe=' + encodeURIComponent(S.usGroupe) : ''))
        .then(d => { this._usageDetEnCours = false; this._usageDet[cleD] = (d && !d.error) ? d : null; this.setState({}); })
        .catch(() => { this._usageDetEnCours = false; this._usageDet[cleD] = null; this.setState({}); });
    }
  }

  valsUsage(common){
    const S = this.state;
    const mois = S.usMois || 6;
    const cle = mois + '|' + (S.usGroupe || '');
    const d = (this._usage || {})[cle];
    const court = nom => String(nom || '').split(' - ').pop();
    const nb = v => (v == null ? '—' : Math.round(v).toLocaleString('fr-BE'));
    const pc = v => (v == null ? '—' : v.toFixed(1).replace('.', ',') + ' %');
    // Vert au-dessus de 60 %, ambre au-dessus de 40, bordeaux en dessous : les
    // mêmes paliers que le reste du cockpit.
    const teinte = p => p == null ? 'var(--color-text-muted)' : (p >= 60 ? '#2d7a3e' : (p >= 40 ? '#C17A2A' : '#8D1D2C'));

    common.usChargement = d === undefined;
    common.usMotif = d === null ? 'L’usage du catalogue n’a pas pu être lu.' : ((d && d.motif) || '');
    common.usDurees = [3, 6, 12].map(v => ({ v, nom: v + ' mois', on: v === mois,
      choisir: () => this.setState({ usMois: v }) }));
    common.usGroupes = [{ nom: 'Tous groupes', v: '', on: !S.usGroupe, choisir: () => this.setState({ usGroupe: '' }) }]
      .concat(((d && d.groupes) || []).map(g => ({ nom: g, v: g, on: S.usGroupe === g,
        choisir: () => this.setState({ usGroupe: g }) })));

    if (!d) { common.usMois = []; common.usLignes = []; return; }

    common.usSource = d.source || '';
    common.usKpis = [
      { lbl: 'Catalogue', v: nb(d.catalogue), sub: 'références actives'
        + (d.groupe ? ' — groupe ' + d.groupe : '') },
      { lbl: 'Vendues par le réseau', v: nb((d.reseau || {}).refs), sub: pc((d.reseau || {}).taux) + ' du catalogue' },
      { lbl: 'Jamais vendues', v: nb((d.reseau || {}).jamais), sub: 'par aucun magasin, sur ' + mois + ' mois', accent: true,
        // La tuile donne le compte ; la liste, elle, se traite. Elle part en
        // réunion catalogue : chaque référence est à relancer ou à sortir.
        lien: API_BASE + '/produits/utilisation/jamais.pdf?mois=' + mois
          + (S.usGroupe ? '&groupe=' + encodeURIComponent(S.usGroupe) : ''),
        lienNom: 'Liste PDF' },
      { lbl: 'Meilleur magasin', v: pc(Math.max.apply(null, (d.magasins || []).map(m => m.tauxPeriode || 0))),
        sub: court((d.magasins || []).reduce((a, b) => (b.tauxPeriode || 0) > (a.tauxPeriode || 0) ? b : a, {}).nom) },
    ];
    common.usEntetes = (d.mois || []).map(m => m.lib);
    common.usLignes = (d.magasins || []).map(m => ({
      id: m.id, nom: court(m.nom), complet: m.nom,
      ouvert: S.usShop === m.id,
      basculer: () => this.setState({ usShop: S.usShop === m.id ? null : m.id }),
      mois: (m.mois || []).map(x => ({
        // Un mois en cours SANS ligne de ticket n'est pas un mois à zéro : la
        // caisse ne l'a pas encore chargé, et l'écran le dit.
        v: (x.encours && !x.refs) ? '—' : nb(x.refs),
        sub: (x.encours && !x.refs) ? 'non chargé' : pc(x.taux),
        st: (x.encours && !x.refs) ? 'color:#b8b2a8' : '',
      })),
      refs: nb(m.refsPeriode), taux: pc(m.tauxPeriode),
      tauxCol: teinte(m.tauxPeriode), barre: Math.max(2, Math.min(100, m.tauxPeriode || 0)),
      manquantes: m.manquantes,
    }));

    // --- le détail du magasin ouvert.
    const sid = S.usShop;
    const det = sid ? (this._usageDet || {})[sid + '|' + cle] : undefined;
    // Le même tri aux deux niveaux : trier les groupes par « à rattraper » et
    // leurs catégories par ordre alphabétique ne se lirait pas.
    const t = S.usTri || 'rattraper';
    const ordre = (a, b) => t === 'catalogue' ? b.catalogue - a.catalogue
      : t === 'nom' ? a.nom.localeCompare(b.nom, 'fr')
      : (b.aRattraper - a.aRattraper || b.catalogue - a.catalogue);
    // Le filtre par taux : on cherche les sous-catégories qui décrochent, pas
    // celles qui tournent. Il porte sur la SOUS-CATÉGORIE — un groupe à 69 %
    // peut contenir une catégorie à 0 %, et c'est elle qu'on veut trouver.
    const seuil = S.usSeuil || null;
    const passe = c => seuil === null || (c.taux || 0) < seuil;
    common.usDetail = !sid ? null : {
      chargement: det === undefined,
      err: det === null ? 'Le détail de ce magasin n’a pas pu être lu.' : ((det && det.motif) || ''),
      nom: det ? det.nom : '',
      resume: det ? (nb(det.vendues) + ' vendues · ' + nb(det.aRattraper) + ' à rattraper · '
        + nb(det.orphelines) + ' que personne ne vend') : '',
      tri: S.usTri || 'rattraper',
      tris: [['rattraper', 'À rattraper'], ['catalogue', 'Catalogue'], ['nom', 'A → Z']].map(([v, nom]) => ({
        v, nom, on: (S.usTri || 'rattraper') === v, choisir: () => this.setState({ usTri: v }) })),
      seuils: [[null, 'Toutes'], [25, 'moins de 25 %'], [50, 'moins de 50 %'], [75, 'moins de 75 %']]
        .map(([v, nom]) => ({ v, nom, on: (S.usSeuil || null) === v,
          choisir: () => this.setState({ usSeuil: v, usCat: null }) })),
      seuil,
      vide: !det ? '' : (det.groupes || []).every(g => !(g.categories || []).some(passe))
        ? 'Aucune sous-catégorie sous ' + seuil + ' % dans ce magasin.' : '',
      groupes: !det ? [] : (det.groupes || []).slice()
        .filter(g => (g.categories || []).some(passe)).sort(ordre).map(g => ({
        nom: g.nom,
        catalogue: nb(g.catalogue), vendues: nb(g.nVendues), taux: pc(g.taux),
        col: teinte(g.taux), barre: Math.max(2, Math.min(100, g.taux || 0)),
        aRattraper: g.aRattraper,
        sous: (seuil === null ? g.nCategories : (g.categories || []).filter(passe).length)
          + ' sous-catégorie' + ((seuil === null ? g.nCategories : (g.categories || []).filter(passe).length) > 1 ? 's' : '')
          + (seuil === null ? '' : ' sous ' + seuil + ' %'),
        ouvert: S.usGrp === g.nom,
        basculer: () => this.setState({ usGrp: S.usGrp === g.nom ? null : g.nom, usCat: null }),
        categories: (g.categories || []).filter(passe).slice().sort(ordre).map(c => this.usageCategorie(c, g.nom, nb, pc, teinte)),
      })),
    };
  }

  /**
   * Une sous-catégorie du détail magasin : la ligne, et ses deux colonnes.
   *
   * La clé d'ouverture porte le groupe : deux groupes peuvent contenir une
   * catégorie du même nom, et l'ouvrir dans l'un déplierait l'autre.
   */
  usageCategorie(c, groupe, nb, pc, teinte){
    const S = this.state;
    const cle = groupe + ' ¦ ' + c.nom;
    return {
      nom: c.nom, groupe,
      catalogue: nb(c.catalogue), vendues: nb(c.nVendues), taux: pc(c.taux),
      col: teinte(c.taux), barre: Math.max(2, Math.min(100, c.taux || 0)),
      aRattraper: c.aRattraper,
      ouverte: S.usCat === cle,
      basculer: () => this.setState({ usCat: S.usCat === cle ? null : cle }),
      nVendues: nb(c.nVendues), nAbsentes: nb(c.nNonVendues), orphelines: nb(c.orphelines),
      // Douze lignes de chaque côté : au-delà, on ne lit plus, on fait
      // défiler. Le compte complet reste dans l'en-tête de la colonne.
      listeVendues: (c.vendues || []).slice(0, 12).map(x => ({ nom: x.nom, droite: nb(x.quantite) + ' u' })),
      listeAbsentes: (c.nonVendues || []).slice(0, 12).map(x => ({
        nom: x.nom,
        droite: x.ailleurs ? 'vendue par ' + x.ailleurs : 'personne',
        st: x.ailleurs ? 'color:var(--color-primary)' : 'color:var(--color-text-muted)' })),
      resteVendues: Math.max(0, c.nVendues - 12),
      resteAbsentes: Math.max(0, c.nNonVendues - 12),
    };
  }

  /**
   * Ouvre la note d'une campagne — la page A4 et le courrier qui la porte.
   *
   * Chargée À L'OUVERTURE et pas avant : la note lit la référence de l'an
   * dernier sur le panel, et la calculer pour toutes les campagnes de l'écran
   * coûterait quarante appels pour une seule qu'on ouvrira.
   */
  mkNoteOuvrir(id, onglet){
    this.setState({ mkNote: { id, chargement: true, onglet: onglet || 'note' } });
    readOne('/marketing/campagne/' + id + '/note').then(d => {
      this.setState(s2 => (s2.mkNote && s2.mkNote.id === id)
        ? { mkNote: { id, chargement: false, onglet: onglet || 'note',
            d: (d && !d.error) ? d : null,
            err: (d && d.error) ? d.error : (d ? '' : 'Note indisponible.') } }
        : {});
    }).catch(() => this.setState(s2 => (s2.mkNote && s2.mkNote.id === id)
      ? { mkNote: { id, chargement: false, onglet: onglet || 'note', d: null, err: 'Note indisponible.' } } : {}));
  }

  /** La campagne telle que le formulaire la mange. */
  mkVersForm(c2){
    return { id: c2.id, nom: c2.nom, typeId: c2.typeId ? String(c2.typeId) : '',
      scope: c2.scope, statut: c2.statut, debut: c2.debut || '', fin: c2.fin || '',
      budget: c2.budget ? String(c2.budget) : '' };
  }

  /* --- fonds : écrire depuis le pilotage réseau ------------------------------ */

  foPatch(patch){ this.setState(s => ({ foForm: Object.assign({}, s.foForm, patch) })); }
  foRecPatch(patch){ this.setState(s => ({ foRec: Object.assign({}, s.foRec, patch) })); }
  /** Recharge le fonds après écriture : c'est le module qui fait foi. */
  foRecharge(){
    const mois = this.state.foRoyMois;
    return readOne('/fonds' + (mois ? '?mois=' + encodeURIComponent(mois) : ''))
      .then(f => { if (f) { this.D.fonds = f; } this.setState({}); return f; });
  }
  /**
   * Enregistre une écriture — création ou correction.
   *
   * Les règles du fonds (sens, montant, boutique du périmètre) appartiennent au
   * module : on ne les rejoue pas ici. Seul le strict nécessaire est vérifié
   * avant l'aller-retour, pour ne pas faire voyager une saisie visiblement
   * incomplète ; le refus du module, lui, s'affiche tel quel.
   */
  foEnvoyer(){
    const f = this.state.foForm;
    if (!f || f.busy) { return; }
    const montant = parseFloat(String(f.montant).replace(',', '.'));
    if (!String(f.libelle || '').trim()) { this.foPatch({ err: 'Un libellé est nécessaire.' }); return; }
    if (!isFinite(montant) || montant <= 0) { this.foPatch({ err: 'Le montant doit être un nombre supérieur à zéro.' }); return; }
    if (!f.date) { this.foPatch({ err: 'La date du mouvement est nécessaire.' }); return; }
    this.foPatch({ busy: true, err: '' });
    const corps = {
      direction: f.sens, movement_date: f.date, label: String(f.libelle).trim(), amount: montant,
      source: f.source || 'AUTRE',
      shop_id: f.magasin || null, campaign_id: f.campagne || null, lever_id: f.levier || null,
      supplier_name: f.fournisseur || null, document_ref: f.piece || null,
      is_investment: f.sens === 'OUT' && !!f.invest,
    };
    const chemin = f.id ? '/fonds/mouvement/' + f.id : '/fonds/mouvement';
    write(this.source, f.id ? 'PATCH' : 'POST', chemin, corps).then(r => {
      if (!r || r.ok === false) { this.foPatch({ busy: false, err: (r && r.error) || 'refusé par le module' }); return; }
      this.setState({ foForm: null });
      this.notify(f.id ? 'Écriture corrigée' : (f.sens === 'IN' ? 'Alimentation enregistrée' : 'Dépense enregistrée'));
      this.foRecharge();
    });
  }
  /** L'aperçu des redevances du mois : les lignes exactes, rien d'écrit. */
  foRoyPlanifier(mois){
    this.setState({ foRoyPlan: { busy: true, month: mois, lignes: [] } });
    write(this.source, 'POST', '/fonds/royalties/generer', { month: mois, apercu: true }).then(r => {
      if (!r || r.ok === false) {
        this.setState({ foRoyPlan: { busy: false, month: mois, lignes: [], err: (r && r.error) || 'aperçu impossible' } });
        return;
      }
      this.setState({ foRoyPlan: Object.assign({ busy: false }, r) });
    });
  }
  /**
   * Insère au grand livre la redevance MARKETING d'UN client, pour le mois
   * affiché. Le libellé de l'écriture porte la sorte et le montant
   * (« Redevance Marketing AAAA-MM — Client ») ; la pièce canonique du
   * serveur rend l'insertion idempotente — jamais deux fois la même.
   */
  foRoyInserer(mois, shopId, nom, montant){
    if (!window.confirm('Insérer au grand livre : redevance Marketing ' + mois + ' — ' + nom
      + ', ' + this.fU(montant) + ' ?')) { return; }
    write(this.source, 'POST', '/fonds/royalties/generer', { month: mois, shop_id: shopId, apercu: false }).then(r => {
      if (!r || r.ok === false) { this.notify('Non insérée — ' + ((r && r.error) || 'refusé')); return; }
      this.notify(r.ecrites ? 'Redevance marketing de ' + nom + ' insérée au grand livre'
        : 'Déjà au grand livre — rien à réécrire');
      this.foRecharge();
    });
  }
  /** Écrit les redevances prévisualisées — la marketing de chaque magasin. */
  foRoyConfirmer(){
    const p = this.state.foRoyPlan;
    if (!p || p.busy) { return; }
    this.setState({ foRoyPlan: Object.assign({}, p, { busy: true, err: '' }) });
    write(this.source, 'POST', '/fonds/royalties/generer', { month: p.month, apercu: false }).then(r => {
      if (!r || r.ok === false) {
        this.setState({ foRoyPlan: Object.assign({}, p, { busy: false, err: (r && r.error) || 'refusé' }) });
        return;
      }
      this.setState({ foRoyPlan: null });
      this.notify((r.ecrites || 0) + ' redevance(s) écrite(s) au fonds');
      this.foRecharge();
    });
  }
  /**
   * Supprime une écriture, après confirmation.
   *
   * Une ligne du grand livre est une pièce comptable : on nomme ce qui va
   * disparaître, plutôt que de demander « confirmer ? » sur un identifiant.
   */
  foSupprimer(id, libelle){
    if (!window.confirm('Supprimer définitivement « ' + libelle + ' » du grand livre ?')) { return; }
    write(this.source, 'DELETE', '/fonds/mouvement/' + id).then(r => {
      if (!r || r.ok === false) { this.notify('Non supprimé — ' + ((r && r.error) || 'refusé')); return; }
      this.notify('Écriture supprimée');
      this.foRecharge();
    });
  }
  /**
   * Les échéances qu'un frais va écrire, datées avant l'enregistrement.
   *
   * « Toutes les 6 semaines » ne se devine pas de tête : l'aperçu montre les
   * dates et le total, et l'utilisateur valide ce qu'il VOIT. Pour les rythmes
   * du module (mois, trimestre, année) c'est une prévision — le module reste
   * le maître de ses écritures.
   */
  foRecApercu(fr){
    if (!fr || !fr.debut || !fr.fin) { return null; }
    const montant = parseFloat(String(fr.montant || '').replace(',', '.'));
    const nSem = Math.max(1, Math.min(52, parseInt(fr.semaines, 10) || 6));
    const dates = [];
    let d2 = new Date(fr.debut + 'T00:00:00');
    const fin = new Date(fr.fin + 'T00:00:00');
    if (isNaN(d2.getTime()) || isNaN(fin.getTime()) || d2 > fin) { return null; }
    for (let k = 0; k < 60 && d2 <= fin; k++) {
      dates.push(d2.toISOString().slice(0, 10));
      if (fr.frequence === 'weeks') { d2 = new Date(d2.getTime() + nSem * 7 * 86400000); }
      else if (fr.frequence === 'quarter') { d2.setMonth(d2.getMonth() + 3); }
      else if (fr.frequence === 'year') { d2.setFullYear(d2.getFullYear() + 1); }
      else { d2.setMonth(d2.getMonth() + 1); }
    }
    if (!dates.length) { return null; }
    return { n: dates.length,
      dates: dates.slice(0, 10).map(x2 => this.fD(x2)),
      tronque: dates.length > 10 ? dates.length - 10 : 0,
      brutes: dates,
      total: isFinite(montant) && montant > 0 ? this.fU(montant * dates.length) : null,
      previsionModule: fr.frequence !== 'weeks' };
  }
  /** Un frais qui revient : le module écrit toutes ses échéances d'un coup. */
  foRecEnvoyer(){
    const f = this.state.foRec;
    if (!f || f.busy) { return; }
    const montant = parseFloat(String(f.montant).replace(',', '.'));
    if (!String(f.libelle || '').trim()) { this.foRecPatch({ err: 'Un libellé est nécessaire.' }); return; }
    if (!isFinite(montant) || montant <= 0) { this.foRecPatch({ err: 'Le montant d’une échéance doit être supérieur à zéro.' }); return; }
    if (!f.debut || !f.fin) { this.foRecPatch({ err: 'Un frais récurrent est borné : donnez le début ET la fin.' }); return; }
    this.foRecPatch({ busy: true, err: '' });
    if (f.frequence === 'weeks') {
      // Le module ne connaît pas « toutes les N semaines » : le cockpit écrit
      // les échéances une à une, comme des mouvements datés. Elles se lisent
      // et se corrigent ensuite ligne à ligne au grand livre.
      const ap = this.foRecApercu(f);
      if (!ap) { this.foRecPatch({ busy: false, err: 'Les dates ne donnent aucune échéance.' }); return; }
      const suite = (i) => {
        if (i >= ap.brutes.length) {
          this.setState({ foRec: null });
          this.notify(ap.brutes.length + ' échéance(s) écrite(s) au fonds — ' + String(f.libelle).trim());
          this.foRecharge();
          return;
        }
        write(this.source, 'POST', '/fonds/mouvement', {
          direction: f.sens, movement_date: ap.brutes[i], label: String(f.libelle).trim(),
          amount: montant, source: f.source || 'AUTRE',
          shop_id: f.magasin || null, lever_id: f.levier || null,
        }).then(r2 => {
          if (!r2 || r2.ok === false) {
            this.foRecPatch({ busy: false, err: 'Échéance ' + (i + 1) + '/' + ap.brutes.length
              + ' refusée — ' + ((r2 && r2.error) || 'refusé') + '. Les ' + i + ' précédentes sont écrites.' });
            this.foRecharge();
            return;
          }
          suite(i + 1);
        });
      };
      suite(0);
      return;
    }
    write(this.source, 'POST', '/fonds/recurrence', {
      direction: f.sens, frequency: f.frequence, label: String(f.libelle).trim(), amount: montant,
      starts_on: f.debut, ends_on: f.fin, source: f.source || 'AUTRE',
      shop_id: f.magasin || null, lever_id: f.levier || null,
    }).then(r => {
      if (!r || r.ok === false) { this.foRecPatch({ busy: false, err: (r && r.error) || 'refusé par le module' }); return; }
      const rep = (r.reponse || {});
      this.setState({ foRec: null });
      // Le nombre d'échéances écrites fait partie de la nouvelle : « 12
      // écritures » n'est pas la même chose que « 1 ».
      this.notify('Frais récurrent enregistré'
        + (rep.occurrences ? ' — ' + rep.occurrences + ' échéance(s)' : ''));
      this.foRecharge();
    });
  }
  foRecSupprimer(id, libelle){
    if (!window.confirm('Supprimer « ' + libelle + ' » et les échéances qu’il a écrites ?')) { return; }
    write(this.source, 'DELETE', '/fonds/recurrence/' + id).then(r => {
      if (!r || r.ok === false) { this.notify('Non supprimé — ' + ((r && r.error) || 'refusé')); return; }
      this.notify('Frais récurrent supprimé');
      this.foRecharge();
    });
  }

  /* --- recherche globale : retrouver n'importe quoi depuis le rail ----------- */

  /**
   * Une seule barre pour tout ce que l'application détient.
   *
   * L'application compte une trentaine d'écrans et plusieurs milliers de
   * lignes ; savoir QUEL écran porte « Citron Meringué » ou « Vitrine 1 »
   * suppose de connaître le rangement. La recherche cherche donc dans les
   * données elles-mêmes — références, emplacements du comptoir, magasins,
   * projets, tâches, consultants, fournisseurs, rapports — et pas seulement
   * dans le nom des écrans.
   *
   * Chaque résultat sait où il habite : il ouvre l'écran ET y pose le filtre
   * qui isole la ligne. Sans cela on retombe sur une liste de 710 références.
   */
  valsRecherche(common, navDef, goTo, titles){
    const S = this.state, D = this.D, M = this.M;
    const q = (S.gq || '').trim().toLowerCase();
    common.gq = S.gq || '';
    common.gSet = e => this.setState({ gq: e.target.value });
    common.gVider = q ? () => this.setState({ gq: '' }) : null;
    common.gOuvert = q.length >= 2;
    if (!common.gOuvert) { common.gGroupes = []; common.gTotal = 0; common.gTrop = 0; return; }

    // Le catalogue n'est chargé que par les écrans du référentiel : sans lui,
    // chercher une référence depuis le rail ne rendrait rien. On le demande une
    // fois, à la première recherche.
    if (!D.prodCatalogue && !this._gCatEnCours) {
      this._gCatEnCours = true;
      readOne('/production/catalogue').then(c => { this._gCatEnCours = false;
        if (c) { this.D.prodCatalogue = c; this.setState({}); } });
    }
    if (!D.plano && !this._plEnCours) { this.plCharge(); }

    const colle = (...v) => v.filter(Boolean).join(' ').toLowerCase();
    const trouve = t => t.indexOf(q) >= 0;
    const groupes = [];
    const ajoute = (nom, lignes) => { if (lignes.length) { groupes.push({ nom, lignes }); } };
    const MAX = 8;   // par groupe : au-delà, la liste cesse d'aider
    let total = 0, tronque = 0;
    const coupe = arr => { total += arr.length;
      if (arr.length > MAX) { tronque += arr.length - MAX; }
      return arr.slice(0, MAX); };

    // 1. Les écrans eux-mêmes, avec leur description.
    const ecrans = [];
    navDef.forEach(g => (g[1] || []).forEach(it => {
      const push = (id, label, section) => {
        const aide = ((titles || {})[id] || [])[1] || '';
        if (trouve(colle(label, section, aide))) {
          ecrans.push({ titre: label, detail: section, aller: goTo(id) });
        }
      };
      if (Array.isArray(it)) { push(it[0], it[1], g[0]); }
      else { (it.children || []).forEach(c => push(c[0], c[1], g[0] + ' · ' + it.sub)); }
    }));
    ajoute('Écrans', coupe(ecrans));

    // 2. Références du catalogue produit.
    const refs = (D.prodCatalogue || []).filter(p =>
      trouve(colle(p.nom, p.ref, p.categorie, p.groupe))).map(p => ({
        titre: p.nom, detail: [p.ref, p.categorie, p.groupe].filter(Boolean).join(' · '),
        marque: p.zone ? 'au comptoir' : (p.must ? 'obligatoire' : ''),
        aller: () => this.setState({ screen: 'catalogue', refQ: String(p.ref), gq: '' }) }));
    ajoute('Références produit', coupe(refs));

    // 3. Emplacements du comptoir.
    const slots = ((D.plano || {}).slots || []).filter(s =>
      trouve(colle(s.zone, s.meuble, s.niveau, String(s.position),
        (s.occupants || []).map(o => o.nom + ' ' + o.ref).join(' ')))).map(s => ({
        titre: s.meuble + ' · ' + s.niveau + ' · ' + s.position,
        detail: s.zone + ((s.occupants || [])[0] ? ' — ' + s.occupants[0].nom : ''),
        marque: (s.occupants || []).length ? '' : 'libre',
        aller: () => this.setState({ screen: 'planogramme', plVue: 'tableau',
          plQ: s.meuble + ' ' + s.niveau, plCible: s.id, gq: '' }) }));
    ajoute('Comptoir', coupe(slots));

    // 4. Magasins.
    const mags = (D.stores || []).filter(s => trouve(colle(s.nom, s.ville, s.zone, s.id))).map(s => ({
      titre: s.nom, detail: [s.ville, s.zone].filter(Boolean).join(' · '),
      aller: () => this.setState({ screen: 'magasins', gq: '' }) }));
    ajoute('Magasins', coupe(mags));

    // 5. Projets et leurs tâches.
    const projets = [];
    (D.projects || []).forEach(p => {
      if (trouve(colle(p.nom, p.famille, (p.leviers || []).join(' ')))) {
        projets.push({ titre: p.nom, detail: this.pFamille(p) + ' · ' + this.pStatut(p),
          aller: () => this.setState({ screen: 'projets', openProjId: p.id, gq: '' }) });
      }
      (p.taches || []).forEach(t => {
        if (trouve(colle(t.nom, t.desc))) {
          projets.push({ titre: t.nom, detail: 'tâche · ' + p.nom,
            aller: () => this.setState({ screen: 'projets', openProjId: p.id, gq: '' }) });
        }
      });
    });
    ajoute('Projets & tâches', coupe(projets));

    // 6. Intervenants : consultants et fournisseurs.
    const gens = [];
    (D.consultants || []).forEach(c => { if (trouve(colle(c.nom, c.email, c.role))) {
      gens.push({ titre: c.nom, detail: 'consultant' + (c.email ? ' · ' + c.email : ''),
        aller: () => this.setState({ screen: 'parametres', gq: '' }) }); } });
    (D.suppliers || []).forEach(f => { if (trouve(colle(f.nom, f.email, f.metier))) {
      gens.push({ titre: f.nom, detail: 'fournisseur' + (f.metier ? ' · ' + f.metier : ''),
        aller: () => this.setState({ screen: 'parametres', gq: '' }) }); } });
    ajoute('Intervenants', coupe(gens));

    // 7. Rapports du reporting.
    const rapports = ((D.reporting || {}).reports || []).filter(r =>
      trouve(colle(r.nom, r.type, r.frequence))).map(r => ({
        titre: r.nom, detail: [r.type, r.frequence].filter(Boolean).join(' · '),
        aller: () => this.setState({ screen: 'reporting', gq: '' }) }));
    ajoute('Rapports', coupe(rapports));

    common.gGroupes = groupes;
    common.gTotal = total;
    common.gTrop = tronque;
    common.gRien = total === 0;
    // Dire ce qui n'est pas encore chargé vaut mieux que laisser croire à une
    // absence : un catalogue en cours de lecture ne veut pas dire « rien ».
    common.gAttente = [!D.prodCatalogue ? 'catalogue produit' : '', !D.plano ? 'comptoir' : '']
      .filter(Boolean).join(' et ');
  }

  /* --- planogramme : le comptoir, ses emplacements, ses consignes ------------ */

  /* --- résultat du jour ------------------------------------------------------
   * Une journée, tous les magasins, un compte de résultat par ligne. Ouvrir une
   * ligne déplie le détail du magasin : cascade du résultat, ventilation par
   * catégorie, indicateurs de vente et place du jour dans le mois.
   *
   * Lecture paresseuse et cache PAR DATE : reculer d'un jour puis revenir ne
   * redemande rien au panel — chaque date coûte une volée d'appels.
   */
  rjCle(){ return this.state.rjDate || ''; }
  /**
   * Le résultat d'un jour, lu une fois puis gardé.
   *
   * `force` : la journée en cours BOUGE — les ventes de 16 h ne sont pas
   * celles de 11 h. Le cache évite de rappeler le panel à chaque redessin ;
   * le bouton de rafraîchissement le vide pour cette date-là, et lui seul.
   */
  /* Les référentiels d'une réclamation : motifs, matières avec leur SKU,
     livraisons du magasin. Chargés seulement quand on ouvre le formulaire. */
  reclRefsCharge(shopId){
    const cle = String(shopId || '');
    if (!this.D.reclRefs) { this.D.reclRefs = {}; }
    if (this.D.reclRefs[cle] !== undefined) { return this.D.reclRefs[cle]; }
    this.D.reclRefs[cle] = null;
    readOne('/fournisseurs/reclamation-refs?shop=' + encodeURIComponent(cle))
      .then(d => { this.D.reclRefs[cle] = d || { indispo: true }; this.setState({ reclRefsMaj: cle }); })
      .catch(() => { this.D.reclRefs[cle] = { indispo: true }; this.setState({ reclRefsMaj: cle }); });
    return null;
  }

  /* Les réclamations matière : lues chez le panel, jamais recalculées. Une
     seule lecture, gardée — l'écran des fournisseurs y revient souvent. */
  reclCharge(force){
    const mois = this.state.reclMois == null ? 3 : this.state.reclMois;
    const cle = String(mois);
    if (this._reclEnCours === cle) { return; }
    if (this.D.recl && this.D.recl.cle === cle && !force) { return; }
    this._reclEnCours = cle;
    if (!this.D.recl) { this.setState({ reclChargement: true }); }
    readOne('/fournisseurs/reclamations?mois=' + mois).then(d => {
      this._reclEnCours = null; this.D.recl = Object.assign({ cle }, d || { indispo: true });
      this.setState({ reclChargement: false });
    }).catch(() => { this._reclEnCours = null; this.D.recl = { cle, indispo: true };
      this.setState({ reclChargement: false }); });
  }

  /* La photo de contrôle d'une tâche née d'une note : cliché et repères, lus
     une fois puis gardés. Une tâche sans origine ne déclenche aucun appel. */
  srcCharge(src){
    if (!src || !src.taskId || !src.date) { return null; }
    const cle = src.shopId + '/' + src.taskId + '/' + src.date;
    if (!this.D.srcPhoto) { this.D.srcPhoto = {}; }
    if (this.D.srcPhoto[cle] !== undefined) { return this.D.srcPhoto[cle]; }
    this.D.srcPhoto[cle] = null;
    readOne('/pwa/tasks/detail?shop=' + encodeURIComponent(src.shopId)
      + '&task=' + encodeURIComponent(src.taskId) + '&date=' + encodeURIComponent(src.date))
      .then(d => { this.D.srcPhoto[cle] = d || { erreur: true }; this.setState({ srcMaj: cle }); })
      .catch(() => { this.D.srcPhoto[cle] = { erreur: true }; this.setState({ srcMaj: cle }); });
    return null;
  }

  /* Le lien de la photo est SIGNÉ et expire. Le navigateur ne l'apprend qu'à la
     fin de la requête : on écoute son échec plutôt que d'afficher une icône
     cassée surmontée de cadres flottants. */
  srcSurveille(cle){
    setTimeout(() => {
      const img = document.querySelector('[data-tphoto="' + cle + '"]');
      if (!img) { return; }
      if (!this.D.srcPhotoErr) { this.D.srcPhotoErr = {}; }
      const rate = () => { if (!this.D.srcPhotoErr[cle]) {
        this.D.srcPhotoErr[cle] = true; this.setState({ srcErr: cle }); } };
      if (img.complete) { if (!img.naturalWidth) { rate(); } return; }
      img.addEventListener('error', rate, { once: true });
    }, 0);
  }

  /* Le classement des tâches du jour — lu chez le panel, pas recalculé.
     Chargé à part du résultat du jour : il vit sur la même date mais sur une
     autre route, et une route lente ne doit pas retenir l'autre. */
  clCharge(force){
    const cle = this.rjCle() || 'auj';
    if (!this.D.classement) { this.D.classement = {}; }
    if (this._clEnCours === cle) { return; }
    if (this.D.classement[cle] && !force) { return; }
    this._clEnCours = cle;
    readOne('/taches/classement' + (this.rjCle() ? '?date=' + encodeURIComponent(this.rjCle()) : ''))
      .then(d => { this._clEnCours = null; this.D.classement[cle] = d || { indispo: true }; this.setState({ clMaj: cle }); })
      .catch(() => { this._clEnCours = null; this.D.classement[cle] = { indispo: true }; this.setState({ clMaj: cle }); });
  }

  rjCharge(force){
    const cle = this.rjCle();
    if (!this.D.rjour) { this.D.rjour = {}; }
    // On ne VIDE pas le cache avant d'avoir la réponse : l'écran se serait
    // remplacé par « Lecture… » pendant les quinze secondes du panel, et on
    // perdrait de vue les chiffres qu'on était en train de lire. L'ancienne
    // journée reste affichée, la nouvelle prend sa place à l'arrivée.
    if (this._rjEnCours === cle) { return; }
    if (this.D.rjour[cle] && !force) { return; }
    this._rjEnCours = cle;
    if (force) { this.setState({ rjMaj: 'en-cours' }); }
    readOne('/exploitation/jour' + (cle ? '?date=' + encodeURIComponent(cle) : '')).then(d => {
      this._rjEnCours = null;
      const v = d || { erreur: true };
      this.D.rjour[cle] = v;
      // La réponse porte la date RETENUE : elle sert aussi de clé, sinon
      // revenir sur « aujourd'hui » par la flèche rechargerait tout.
      if (v.date) { this.D.rjour[v.date] = v; }
      this.setState({ rjMaj: new Date().toLocaleTimeString('fr-BE', { hour: '2-digit', minute: '2-digit' }) });
    });
  }

  /** L'évolution vs N-1 d'une catégorie, en couleur : du rouge au vert, gris
   *  quand l'API ne rend pas de comparaison (une date passée n'en a pas). */
  rjCoulDelta(d){
    if (d == null) { return '#B9B2A8'; }
    if (d >= 15) { return '#2d7a3e'; }
    if (d >= 3) { return '#5f9e5f'; }
    if (d > -3) { return '#C9A227'; }
    if (d > -15) { return '#D97706'; }
    return '#C0182B';
  }

  /** Découpe « squarified » : des tuiles proportionnelles au CA, aussi carrées
   *  que possible. Les positions sortent en POURCENTAGE — la grille suit la
   *  largeur de la carte au lieu d'être figée en pixels ; les dimensions en
   *  pixels ne servent qu'à décider ce qui tient comme étiquette. */
  rjTuiles(cats, largeurPx, hauteurPx){
    const vals = (cats || []).filter(c => (c.ca || 0) > 0).sort((a, b) => b.ca - a.ca);
    if (!vals.length) { return []; }
    const W = 1000, H = Math.max(1, Math.round(1000 * hauteurPx / largeurPx));
    const tot = vals.reduce((s, c) => s + c.ca, 0);
    const ech = (W * H) / tot;
    const items = vals.map(c => ({ c, a: c.ca * ech }));
    const out = [];
    let x = 0, y = 0, w = W, h = H, row = [];
    const pire = (rw, l) => {
      const s = rw.reduce((t, r) => t + r.a, 0);
      if (s <= 0 || l <= 0) { return Infinity; }
      const mx = Math.max.apply(null, rw.map(r => r.a)), mn = Math.min.apply(null, rw.map(r => r.a));
      return Math.max((l * l * mx) / (s * s), (s * s) / (l * l * mn));
    };
    const poser = (rw, horiz) => {
      const s = rw.reduce((t, r) => t + r.a, 0);
      if (s <= 0) { return; }
      if (horiz) {
        const rh = s / w; let cx = x;
        rw.forEach(r => { const rl = r.a / rh; out.push({ c: r.c, x: cx, y, w: rl, h: rh }); cx += rl; });
        y += rh; h -= rh;
      } else {
        const rl = s / h; let cy = y;
        rw.forEach(r => { const rh = r.a / rl; out.push({ c: r.c, x, y: cy, w: rl, h: rh }); cy += rh; });
        x += rl; w -= rl;
      }
    };
    let garde = 0;
    while (items.length && garde++ < 400) {
      const horiz = w <= h, l = horiz ? w : h, it = items[0];
      if (!row.length || pire(row, l) >= pire(row.concat([it]), l)) { row.push(items.shift()); }
      else { poser(row, horiz); row = []; }
    }
    if (row.length) { poser(row, w <= h); }

    const fE = n => this.fE(n);
    return out.map(t => {
      const wpx = t.w / W * largeurPx, hpx = t.h / H * hauteurPx;
      const c = t.c;
      const part = c.part != null ? c.part : null;
      return {
        nom: c.categorie,
        ca: fE(c.ca),
        part: part == null ? '' : (part * 100).toFixed(0).replace('.', ',') + ' %',
        delta: c.delta == null ? '' : (c.delta > 0 ? '+' : '') + c.delta.toFixed(1).replace('.', ',') + ' %',
        gros: wpx > 92 && hpx > 52,
        moyen: wpx > 62 && hpx > 32,
        minuscule: !(wpx > 34 && hpx > 15),
        st: 'position:absolute;left:' + (t.x / W * 100).toFixed(3) + '%;top:' + (t.y / H * 100).toFixed(3)
          + '%;width:' + Math.max(t.w / W * 100 - 0.35, 0).toFixed(3) + '%;height:' + Math.max(t.h / H * 100 - 0.7, 0).toFixed(3)
          + '%;background:' + this.rjCoulDelta(c.delta) + ';color:#fff;border-radius:5px;box-sizing:border-box;'
          + 'padding:' + (wpx > 92 && hpx > 52 ? '7px 9px' : '4px 6px') + ';overflow:hidden',
      };
    });
  }

  valsResultatJour(common){
    const S = this.state, D = this.D;
    const r = (D.rjour || {})[this.rjCle()];
    common.rjChargement = !r;
    common.rjErreur = !!(r && (r.erreur || r.indispo));
    common.rjErreurTxt = r && r.indispo ? r.motif
      : 'La lecture de /exploitation/jour a échoué — voir Diagnostic API.';
    common.rjLignes = []; common.rjDetail = null; common.rjInvite = '';
    if (!r || common.rjErreur) { return; }

    const seuils = r.seuils || { food: 32, labour: 33, overhead: 13.5 };
    const fE = n => this.fE(n);
    const fPct = (n, d) => (n == null) ? '—' : n.toFixed(d == null ? 1 : d).replace('.', ',') + ' %';
    const fInt = n => (n == null) ? '—' : Math.round(n).toLocaleString('fr-BE');
    const fU = n => (n == null) ? '—' : n.toFixed(2).replace('.', ',') + ' €';
    const fDelta = n => (n == null) ? '' : (n > 0 ? '+' : '') + n.toFixed(1).replace('.', ',') + ' %';
    // Un écart de moins d'un point ne mérite pas de couleur : au jour le jour,
    // ±0,4 % est du bruit, et le colorier ferait lire une tendance.
    const coulDelta = (n, inverse) => (n == null || Math.abs(n) < 1) ? 'var(--color-text-muted)'
      : (((n > 0) !== !!inverse) ? '#2d7a3e' : '#C0182B');
    // Un coût se lit contre son seuil : tenu, débordé, débordé d'un tiers.
    const feu = (v, s) => (v == null) ? 'var(--color-text-muted)'
      : (v <= s ? '#2d7a3e' : (v <= s * 1.3 ? '#D97706' : '#C0182B'));
    const feuRes = p => (p == null) ? 'var(--color-text-muted)'
      : (p >= 15 ? '#2d7a3e' : (p >= 5 ? '#D97706' : '#C0182B'));

    // --- la date affichée et sa navigation
    const jour = r.date, auj = r.aujourdhui;
    const decale = n => { const d2 = new Date(jour + 'T12:00:00'); d2.setDate(d2.getDate() + n); return d2.toISOString().slice(0, 10); };
    // « Vendredi 21 août 2026 » : capitaliser en CSS mettait aussi « Août »
    // en majuscule, ce qui ne s'écrit pas ainsi en français.
    const dTxt = new Date(jour + 'T12:00:00')
      .toLocaleDateString('fr-BE', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    common.rjDateTxt = dTxt.charAt(0).toUpperCase() + dTxt.slice(1);
    common.rjPrec = () => this.setState({ rjDate: decale(-1) });
    common.rjSuiv = jour < auj ? () => this.setState({ rjDate: decale(1) }) : null;
    // Relire la journée : elle bouge pendant qu'on la regarde. Discret, à
    // côté des flèches — et il dit l'heure de la dernière lecture, sinon on
    // ne sait pas si l'on regarde 11 h ou 16 h.
    common.rjRefresh = () => this.rjCharge(true);
    common.rjMaj = S.rjMaj === 'en-cours' ? 'lecture…' : (S.rjMaj ? 'lu à ' + S.rjMaj : '');
    common.rjAuj = jour < auj ? () => this.setState({ rjDate: null }) : null;
    common.rjEstAuj = !!r.estAujourdhui;
    // ── Le classement des tâches : demandées, faites, sautées, en échec,
    //    obligatoires manquées, journée close. Tout vient du panel.
    this.clCharge(false);
    const cl = (this.D.classement || {})[this.rjCle() || 'auj'] || null;
    common.rjClChargement = !cl;
    common.rjClIndispo = cl && cl.indispo ? (cl.motif || 'classement indisponible') : '';
    if (cl && !cl.indispo) {
      const R = cl.reseau || {};
      const pct = v => v == null ? '—' : String(v).replace('.', ',') + ' %';
      common.rjClReseau = {
        taux: pct(R.taux),
        detail: (R.faites == null ? '—' : R.faites) + ' faites sur ' + (R.total == null ? '—' : R.total)
          + (R.sautees ? ' · ' + R.sautees + ' sautée(s)' : '')
          + (R.ratees ? ' · ' + R.ratees + ' en échec' : ''),
        clos: (R.magasinsClos == null ? 0 : R.magasinsClos) + ' / ' + (R.magasins == null ? 0 : R.magasins) + ' journées closes',
        col: R.taux == null ? 'var(--color-text-muted)' : (R.taux >= 80 ? '#2d7a3e' : (R.taux >= 50 ? '#C17A2A' : 'var(--color-primary)')) };
      common.rjClLignes = (cl.magasins || []).map((m, i) => ({
        rang: i + 1, nom: m.nom, ville: m.ville,
        taux: pct(m.taux),
        tauxCol: m.taux == null ? 'var(--color-text-muted)' : (m.taux >= 80 ? '#2d7a3e' : (m.taux >= 50 ? '#C17A2A' : 'var(--color-primary)')),
        barre: Math.max(0, Math.min(100, m.taux == null ? 0 : m.taux)),
        detail: (m.faites == null ? '—' : m.faites) + ' / ' + (m.total == null ? '—' : m.total)
          + (m.sautees ? ' · ' + m.sautees + ' sautée(s)' : '')
          + (m.ratees ? ' · ' + m.ratees + ' en échec' : ''),
        // Une obligatoire manquée n'est pas un retard : elle se voit.
        manque: m.obligatoiresManquees ? m.obligatoiresManquees + ' obligatoire(s) manquée(s)' : '',
        clos: m.jourClos ? 'journée close' : '' }));
    } else { common.rjClReseau = null; common.rjClLignes = []; }
    // La comparaison : la moyenne des six mêmes jours de semaine, pas N-1.
    const ref = r.reference || {};
    common.rjRefEntete = 'vs 6 ' + (ref.nom || 'jours');
    common.rjRefAide = ref.explication || '';
    common.rjRefLibelle = ref.libelle || 'moyenne des 6 mêmes jours';
    common.rjSeuilsTxt = 'Seuils du réseau : matière ' + fPct(seuils.food, 0)
      + ' · main-d’œuvre ' + fPct(seuils.labour, 0) + ' · frais généraux ' + fPct(seuils.overhead);
    // Le même (i) que sur la comparaison : d'où viennent les seuils, et ce que
    // les couleurs veulent dire. Une cellule rouge sans règle affichée se lit
    // comme une opinion de l'écran.
    common.rjSeuilsAide = 'Seuils du réseau, réglés dans Paramètres — ils valent pour tous les magasins. '
      + 'Un coût est vert tant qu’il tient son seuil (matière ' + fPct(seuils.food, 0)
      + ', main-d’œuvre ' + fPct(seuils.labour, 0) + ', frais généraux ' + fPct(seuils.overhead)
      + ' du CA), orange au-dessus, rouge au-delà d’un tiers de dépassement. '
      + 'Le résultat, lui, se colore sur sa part du CA : vert à partir de 15 %, orange à partir de 5 %, rouge en dessous. '
      + 'Frais généraux et main-d’œuvre répartis se comparent au même seuil que s’ils étaient mesurés : le ratio d’une journée seule bouge donc plus qu’un ratio mensuel.';
    // Ce que la journée doit à une répartition plutôt qu'à une mesure. La
    // phrase change avec la date : aujourd'hui la masse salariale est mesurée,
    // hier elle ne l'est plus — le panel ne rend le P&L quotidien que du jour.
    common.rjNote = (r.estAujourdhui
      ? 'Frais généraux : montant mensuel réparti sur les jours d’ouverture — le panel ne les mesure pas au jour le jour. Main-d’œuvre : mesurée du jour. '
      : 'Frais généraux ET main-d’œuvre : montants mensuels répartis sur les jours d’ouverture — le panel ne rend le compte de résultat quotidien que pour aujourd’hui. ')
      + 'Comparaison : ' + common.rjRefLibelle + ', jours de fermeture écartés.';

    const res = r.reseau || {};
    common.rjReseau = {
      ca: fE(res.ca), tickets: fInt(res.tickets), panier: fU(res.panier),
      ppc: res.produitsParClient != null ? res.produitsParClient.toFixed(2).replace('.', ',') : '—',
      delta: fDelta(res.caDelta), deltaCoul: coulDelta(res.caDelta),
      deltaTitre: res.refCa == null ? 'aucune référence complète'
        : fE(res.refCa) + ' en moyenne sur les mêmes jours',
      net: fE(res.net), netPct: fPct(res.netPct), netCoul: feuRes(res.netPct),
      // Réseau : la somme des objectifs CONNUS. Un magasin sans budget ne
      // compte pas pour zéro — la case le dit plutôt que de fausser le total.
      fc: (() => { const l = (r.magasins || []).filter(m2 => m2.ouvert && m2.objectifJour != null);
        return l.length ? fE(l.reduce((a, m2) => a + m2.objectifJour, 0)) : '—'; })(),
      fcPct: (() => { const l = (r.magasins || []).filter(m2 => m2.ouvert && m2.objectifJour != null);
        if (!l.length) { return ''; }
        const o = l.reduce((a, m2) => a + m2.objectifJour, 0), c = l.reduce((a, m2) => a + (m2.ca || 0), 0);
        return o > 0 ? this.fP(c / o, 0) : ''; })(),
      fcCoul: (() => { const l = (r.magasins || []).filter(m2 => m2.ouvert && m2.objectifJour != null);
        if (!l.length) { return 'var(--color-text-muted)'; }
        const o = l.reduce((a, m2) => a + m2.objectifJour, 0), c = l.reduce((a, m2) => a + (m2.ca || 0), 0);
        const t = o > 0 ? c / o : null;
        return t == null ? 'var(--color-text-muted)' : (t >= 1 ? '#2d7a3e' : (t >= 0.9 ? '#C17A2A' : 'var(--color-primary)')); })(),
      fcTitre: (() => { const n = (r.magasins || []).filter(m2 => m2.ouvert && m2.objectifJour == null).length;
        return n ? n + ' magasin(s) sans objectif encodé, exclu(s) du total' : 'somme des objectifs du jour'; })(),
      mb: fE(res.margeBrute), mbPct: fPct(res.margeBrutePct),
      labour: fE(res.labour), labourPct: fPct(res.labourPct), labourCoul: feu(res.labourPct, seuils.labour),
      oh: fE(res.overhead), ohPct: fPct(res.overheadPct), ohCoul: feu(res.overheadPct, seuils.overhead),
      magasins: fInt(res.magasins),
    };

    const sel = S.rjSel;
    common.rjLignes = (r.magasins || []).map(m => {
      const actif = m.shopId === sel;
      return {
        id: m.shopId, nom: m.magasin, ouvert: !!m.ouvert, actif,
        // Une ligne fermée reste cliquable : le détail dira pourquoi elle l'est.
        ouvrir: () => this.setState({ rjSel: actif ? null : m.shopId }),
        // Le « produits par client » a désormais SA colonne : le sous-titre
        // ne sert plus qu'à dire pourquoi une ligne fermée l'est.
        sousTitre: m.ouvert ? '' : (m.motif || 'sans réponse'),
        ppc: m.produitsParClient != null ? m.produitsParClient.toFixed(2).replace('.', ',') : '—',
        ca: fE(m.ca), delta: fDelta(m.caDelta), deltaCoul: coulDelta(m.caDelta),
        // L'infobulle donne la référence en clair : un écart sans son point de
        // comparaison ne se vérifie pas.
        deltaTitre: m.refCa == null ? 'aucun jour de référence ouvert'
          : fE(m.refCa) + ' en moyenne sur ' + m.refJours + ' ' + (ref.nom || 'jours'),
        tickets: fInt(m.tickets),
        ticketsDelta: fDelta(m.ticketsDelta), ticketsCoul: coulDelta(m.ticketsDelta),
        panier: fU(m.panier),
        // La colonne dit désormais l'OBJECTIF du jour et son atteinte : le coût
        // matière se lit dans le détail, l'objectif est ce qui se pilote.
        fc: m.objectifJour == null ? '—' : fE(m.objectifJour),
        fcPct: m.objectifAtteinte == null ? '' : this.fP(m.objectifAtteinte, 0),
        fcCoul: m.objectifAtteinte == null ? 'var(--color-text-muted)'
          : (m.objectifAtteinte >= 1 ? '#2d7a3e' : (m.objectifAtteinte >= 0.9 ? '#C17A2A' : 'var(--color-primary)')),
        fcTitre: m.objectifJour == null ? 'aucun budget ni théorique pour ce mois'
          : ((m.objectifSource === 'budget' ? 'budget validé' : 'CA théorique de l’étude')
             + (m.objectifBase === 'profil' ? ' · réparti au poids du ' + (m.objectifJourNom || 'jour') : ' · réparti à parts égales')
             + (m.projection != null ? ' · projection ' + fE(m.projection) : '')),
        mb: fE(m.margeBrute), mbPct: fPct(m.margeBrutePct),
        labour: fE(m.labour), labourPct: fPct(m.labourPct), labourCoul: feu(m.labourPct, seuils.labour),
        labourTitre: m.labourSource === 'reparti' ? 'masse salariale du mois répartie sur les jours d’ouverture' : 'mesurée sur la journée',
        labourReparti: m.labourSource === 'reparti',
        oh: fE(m.overhead), ohPct: fPct(m.overheadPct), ohCoul: feu(m.overheadPct, seuils.overhead),
        net: fE(m.net), netPct: fPct(m.netPct), netCoul: feuRes(m.netPct),
        chevron: actif ? '▾' : '▸',
        st: 'cursor:pointer;background:' + (actif ? 'var(--color-background-secondary)' : 'transparent'),
      };
    });

    const ouverts = (r.magasins || []).filter(m => m.ouvert);
    const magSel = ouverts.filter(m => m.shopId === sel)[0] || null;

    // Aucune ventilation sur la page elle-même : les treemaps ne vivent que
    // dans le détail qu'on ouvre. La page reste une table, lisible d'un trait.
    common.rjInvite = (!magSel && ouverts.length)
      ? 'Ouvrez une ligne du tableau pour le compte de résultat du magasin, sa ventilation par catégorie et la place du jour dans son mois.'
      : '';

    common.rjEchelle = [['#C0182B', '≤ -15 %'], ['#D97706', '-15 à -3 %'], ['#C9A227', 'stable'],
      ['#5f9e5f', '+3 à +15 %'], ['#2d7a3e', '≥ +15 %'], ['#B9B2A8', 'sans référence']]
      .map(e => ({ coul: e[0], label: e[1] }));

    if (!magSel) { return; }

    // --- le détail du magasin ouvert
    const m = magSel;
    const barre = (p) => Math.min(Math.abs(p || 0), 100);
    const casc = [
      { l: 'Chiffre d’affaires', v: fE(m.ca), p: fPct(100, 1), w: 100, coul: 'var(--color-text)', fort: true,
        d: fDelta(m.caDelta), dCoul: coulDelta(m.caDelta), seuil: '' },
      { l: 'Coût matière', v: fE(m.coutMatiere == null ? null : -m.coutMatiere), p: fPct(m.coutMatierePct),
        w: barre(m.coutMatierePct), coul: feu(m.coutMatierePct, seuils.food), fort: false, d: '', dCoul: '',
        seuil: 'seuil ' + fPct(seuils.food, 0) },
      { l: 'Marge brute', v: fE(m.margeBrute), p: fPct(m.margeBrutePct), w: barre(m.margeBrutePct),
        coul: 'var(--color-text)', fort: true, d: '', dCoul: '', seuil: '' },
      { l: 'Main-d’œuvre', v: fE(m.labour == null ? null : -m.labour), p: fPct(m.labourPct), w: barre(m.labourPct),
        coul: feu(m.labourPct, seuils.labour), fort: false, d: '', dCoul: '',
        seuil: 'seuil ' + fPct(seuils.labour, 0) + (m.labourSource === 'reparti' ? ' · réparti' : '') },
      { l: 'Frais généraux', v: fE(m.overhead == null ? null : -m.overhead), p: fPct(m.overheadPct),
        w: barre(m.overheadPct), coul: feu(m.overheadPct, seuils.overhead), fort: false, d: '', dCoul: '',
        seuil: 'seuil ' + fPct(seuils.overhead) + ' · réparti' },
      { l: 'Résultat du jour', v: fE(m.net), p: fPct(m.netPct), w: barre(m.netPct), coul: feuRes(m.netPct),
        fort: true, d: '', dCoul: '', seuil: '' },
    ];

    const serie = (m.serie || []).filter(j => j.ouvert);
    const maxNet = serie.reduce((t, j) => Math.max(t, Math.abs(j.net || 0)), 0) || 1;
    const moisNet = serie.reduce((t, j) => t + (j.net || 0), 0);
    const moisCa = serie.reduce((t, j) => t + (j.ca || 0), 0);

    common.rjDetail = {
      id: m.shopId, nom: m.magasin,
      fermer: () => this.setState({ rjSel: null }),
      ca: fE(m.ca), delta: fDelta(m.caDelta), deltaCoul: coulDelta(m.caDelta),
      deltaTxt: m.caDelta == null
        ? 'aucun ' + (ref.jourSemaine || 'jour') + ' de référence ouvert'
        : 'vs ' + fE(m.refCa) + ' — ' + common.rjRefLibelle
          + (m.refJours < 6 ? ' (' + m.refJours + ' jours retenus)' : ''),
      refAide: common.rjRefAide,
      // ── La jauge de l'objectif : le budget VALIDÉ du mois ramené au jour
      //    d'ouverture, le CA théorique de l'étude à défaut. Un mois sans
      //    budget n'a pas d'objectif — la jauge disparaît, elle ne montre pas
      //    zéro.
      objectif: m.objectifJour == null ? null : {
        montant: fE(m.objectifJour),
        source: m.objectifSource === 'budget' ? 'budget validé' : 'CA théorique de l’étude',
        // Dire COMMENT la cible du jour est tirée du mois : c'est là que se
        // joue l'honnêteté du chiffre.
        base: m.objectifBase === 'profil'
          ? (fE(m.objectifMois) + ' / mois × ' + String(m.objectifPart).replace('.', ',') + ' % — poids du '
             + (m.objectifJourNom || 'jour') + ' mesuré sur ' + fInt(m.objectifJoursVus) + ' '
             + (m.objectifJourNom || 'jour') + (m.objectifJoursVus > 1 ? 's' : '') + ' de ce magasin'
             + (m.objectifProfil === 'memoire' ? ' (profil mémorisé)' : ''))
          : (fE(m.objectifMois) + ' / mois ÷ ' + fInt(m.joursOuverts) + ' j d’ouverture — historique trop court pour peser les jours'),
        pct: m.objectifAtteinte == null ? '—' : this.fP(m.objectifAtteinte, 0),
        // La barre peut dépasser : on la borne à 100 % de largeur et l'excédent
        // se lit sur le chiffre, pas sur une barre qui sortirait de la carte.
        w: Math.max(0, Math.min(100, (m.objectifAtteinte || 0) * 100)).toFixed(1),
        coul: m.objectifAtteinte == null ? 'var(--color-text-muted)'
          : (m.objectifAtteinte >= 1 ? '#2d7a3e' : (m.objectifAtteinte >= 0.9 ? '#C17A2A' : 'var(--color-primary)')),
        ecart: m.objectifAtteinte == null ? ''
          : ((m.ca - m.objectifJour >= 0 ? '+' : '−') + fE(Math.abs(m.ca - m.objectifJour))),
        // ── La projection de fin de journée, en doré : où l'on VA, à côté de
        //    où l'on EN EST. Elle ne s'affiche qu'à partir de 30 % de journée
        //    écoulée — en dessous, ce serait une devinette.
        proj: m.projection == null ? null : {
          montant: fE(m.projection),
          pct: m.projectionAtteinte == null ? '' : this.fP(m.projectionAtteinte, 0),
          // Le doré va jusqu'à la projection, borné comme la barre du réalisé.
          w: Math.max(0, Math.min(100, (m.projectionAtteinte || 0) * 100)).toFixed(1),
          // Dire de quoi la projection est faite : le réalisé, PLUS les euros
          // que rapportent d'habitude les heures qui restent.
          detail: (m.projectionReste ? fE(m.ca) + ' + ' + fE(m.projectionReste) + ' attendus après '
              + (m.projectionHeure == null ? '?' : m.projectionHeure + ' h') : '')
            + (m.projectionJours ? ' · moyenne de ' + m.projectionJours + ' ' + (m.objectifJourNom || 'jour')
               + (m.projectionJours > 1 ? 's' : '') + ', heure par heure' : ''),
          // Le second regard : la journée prolongée au rythme de la matinée.
          rythme: (m.projectionRythme == null || Math.abs(m.projectionRythme - m.projection) < Math.max(20, m.projection * 0.03))
            ? '' : ('au rythme du jour : ' + fE(m.projectionRythme)),
        },
        projMotif: m.projection == null ? (m.projectionMotif || '') : '',
      },
      cascade: casc,
      note: (m.overheadMois != null
        ? 'Frais généraux : ' + fE(m.overheadMois) + ' par mois ramenés au jour d’ouverture (' + fInt(m.joursOuverts) + ' j).'
        : 'Frais généraux mensuels indisponibles pour ce magasin.')
        + (m.labourSource === 'reparti' && m.labourMois != null
          ? ' Main-d’œuvre : ' + fE(m.labourMois) + ' sur le mois, répartie de la même façon.' : ''),
      motifNet: m.motifNet || '',
      tuiles: this.rjTuiles(m.categories, 620, 300),
      // Le libellé complet de la référence tient dans le (i) : en clair, il
      // pousse le titre de la carte sur deux lignes.
      catsLegende: 'surface : poids dans le CA · couleur : écart à la référence',
      sansCat: !(m.categories || []).length,
      kpis: [
        { l: 'Tickets', v: fInt(m.tickets), s: 'clients servis' },
        { l: 'Ticket moyen', v: fU(m.panier), s: 'réseau ' + fU(res.panier) },
        { l: 'Produits vendus', v: fInt(m.produits), s: (m.produitsParClient != null ? m.produitsParClient.toFixed(2).replace('.', ',') : '—') + ' par client' },
        { l: 'Marge brute', v: fE(m.margeBrute), s: fPct(m.margeBrutePct) },
        { l: 'Résultat', v: fE(m.net), s: fPct(m.netPct) },
      ],
      serie: serie.map(j => {
        const v = j.net || 0, haut = Math.abs(v) / maxNet * 29;
        const dern = j.date === r.date;
        return {
          hautHaut: v >= 0 ? haut : 0, hautBas: v < 0 ? haut : 0,
          coul: v >= 0 ? '#2d7a3e' : '#C0182B',
          contour: dern ? 'outline:2px solid var(--color-text);outline-offset:1px' : '',
          titre: this.fD(j.date) + ' · ' + fE(j.ca) + ' · résultat ' + fE(j.net),
        };
      }),
      serieDebut: this.fD((serie[0] || {}).date || ''),
      serieFin: this.fD((serie[serie.length - 1] || {}).date || ''),
      serieTxt: 'résultat net par jour — ' + fE(moisNet) + ' cumulés sur '
        + fE(moisCa) + ' de ventes, main-d’œuvre et frais généraux répartis',
      serieVide: !serie.length,
    };
  }

  /** Charge l'arbre du comptoir. Un seul appel : structure ET occupation. */
  /* --- réputation digitale ---------------------------------------------------
   * La note et le nombre d'avis viennent de Google (via /reputation) ; les cinq
   * avis affichés sont un échantillon de lecture. Aucun calcul de moyenne n'est
   * refait ici : l'écran montre ce que le serveur a compté.
   */
  repCharge(force){
    if (this._repEnCours) { return; }
    if (this.D.reput && !force) { return; }
    this._repEnCours = true;
    readOne('/reputation').then(d => { this._repEnCours = false;
      this.D.reput = d || { erreur: true }; this.setState({}); });
  }
  /** L'or des étoiles pour 5, puis vert, orange et les deux rouges. Une seule
   *  palette pour les jauges du réseau et celles des magasins. */
  get COUL_ETOILE(){ return { 5: '#C9A227', 4: '#2d7a3e', 3: '#D97706', 2: '#C0182B', 1: '#8D1D2C' }; }

  /** Cinq glyphes, pleins jusqu'à la note arrondie. La valeur chiffrée est
   *  toujours affichée à côté : les étoiles donnent l'allure, pas la mesure. */
  repEtoiles(note, taille){
    const pleines = note == null ? 0 : Math.round(note);
    return [1, 2, 3, 4, 5].map(i => ({
      st: 'font-size:' + (taille || 13) + 'px;line-height:1;color:' + (i <= pleines ? '#C9A227' : '#ddd7cd') }));
  }
  valsReputation(common){
    const S = this.state, D = this.D;
    const r = D.reput;
    common.repChargement = !r;
    common.repErreur = !!(r && (r.erreur || r.indispo));
    common.repErreurTxt = r && r.indispo ? r.raison : 'La lecture de /reputation a échoué — voir Diagnostic API.';
    if (!r || common.repErreur) { common.repMagasins = []; return; }

    const cible = r.cible, res = r.reseau || {};
    const fN = v => v == null ? '—' : v.toFixed(2).replace('.', ',');
    const fInt = v => (v == null ? 0 : v).toLocaleString('fr-BE');

    // --- le connecteur : son état voyage avec les données, l'écran ne devine rien
    const co = r.connecteur || {};
    common.repConfigure = !!co.configure;
    common.repEmpreinte = co.empreinte || '';
    common.repRaccordes = co.raccordes || 0;
    common.repSynchroTxt = co.derniereSynchro
      ? 'Dernière synchronisation le ' + this.fD(co.derniereSynchro.slice(0, 10)) + ' à ' + co.derniereSynchro.slice(11, 16)
      : 'Jamais synchronisé';
    common.repBusy = S.repBusy;
    // La synchronisation est un geste : elle appelle Google une fois par fiche,
    // et ces appels sont facturés. La lancer à chaque affichage coûterait cher
    // pour des notes qui bougent de quelques centièmes par semaine.
    common.repSync = () => {
      if (S.repBusy) { return; }
      this.setState({ repBusy: true });
      this.api('POST', '/reputation/sync', {}).then(async r2 => {
        const j = r2 && r2.json ? await r2.json().catch(() => null) : null;
        this.setState({ repBusy: false });
        if (!j || j.error) { this.notify((j && j.error) || 'Synchronisation impossible.'); return; }
        this.D.reput = null; this.repCharge(true);
        this.notify(j.magasins + ' magasin(s) synchronisé(s), ' + j.nouveaux + ' nouvel(s) avis'
          + (j.erreurs && j.erreurs.length ? ' — ' + j.erreurs.length + ' en échec' : ''));
        if (j.erreurs && j.erreurs.length) { console.warn('[cockpit] réputation :', j.erreurs); }
      });
    };

    common.repCible = fN(cible);
    common.repMoyenne = fN(res.moyenne);
    common.repMoyenneSt = 'font-family:var(--font-display);font-size:38px;line-height:1;color:'
      + (res.moyenne == null ? 'var(--color-text-muted)' : (res.moyenne >= cible ? '#2d7a3e' : '#8D1D2C'));
    common.repEtoilesReseau = this.repEtoiles(res.moyenne, 17);
    common.repAvis = fInt(res.avis) + ' avis Google · ' + fInt(res.notes) + ' magasin' + (res.notes > 1 ? 's' : '') + ' noté' + (res.notes > 1 ? 's' : '')
      + (res.magasins > res.notes ? ' sur ' + fInt(res.magasins) : '');
    // --- la répartition par étoiles, en jauges linéaires
    //
    // Les pourcentages sont ceux des avis LUS, jamais des `avis` de Google :
    // l'API ne publie pas l'histogramme d'une fiche. L'écran affiche donc les
    // deux nombres — sinon on lirait « 62 % de 5★ » en croyant que c'est vrai
    // des 989 avis de la fiche.
    //
    // Une seule fabrique pour le réseau et pour les magasins : les jauges
    // doivent se lire de la même façon aux deux endroits, et deux copies se
    // seraient mises à diverger sur un arrondi.
    const jauges = (rp, avisGoogle) => {
      const lus = (rp && rp.lus) || 0;
      return {
        lus,
        vide: lus === 0,
        echantillon: !lus
          ? 'Aucun avis rapatrié pour l’instant.'
          : fInt(lus) + ' avis lus sur ' + fInt(avisGoogle) + ' — Google ne publie pas la répartition complète d’une fiche.',
        barres: ((rp && rp.niveaux) || []).map(l => {
          const part = lus ? l.n / lus : 0;
          return {
            note: String(l.note), n: fInt(l.n),
            pct: lus ? (part * 100).toFixed(0) + ' %' : '—',
            // Une part non nulle garde au moins un filet visible : à 1 % sur
            // 200 avis, une barre à 1 px se confond avec l'absence de barre.
            jaugeSt: 'height:7px;border-radius:999px;background:' + this.COUL_ETOILE[l.note]
              + ';width:' + (l.n === 0 ? 0 : Math.max(2, part * 100)) + '%',
            etoileSt: 'font-size:11px;color:' + this.COUL_ETOILE[l.note] + ';line-height:1',
          };
        }),
      };
    };
    const jReseau = jauges(res.repartition, res.avis);
    common.repLus = jReseau.lus;
    common.repEchantillon = jReseau.echantillon;
    common.repBarres = jReseau.barres;
    common.repSousCible = res.sousCible || 0;
    common.repSousCibleTxt = (res.sousCible || 0) === 0
      ? 'Tous les magasins notés sont à la cible.'
      : res.sousCible + ' magasin' + (res.sousCible > 1 ? 's' : '') + ' sous ' + fN(cible);
    // Le chiffre qui donne un ordre de grandeur : l'effort réseau, pas un objectif
    // à distribuer tel quel — chaque magasin a le sien, plus bas.
    // Une moyenne pondérée à la cible ne dit pas que tout va bien : les gros
    // magasins portent les petits. Quand c'est le cas, la phrase renvoie à
    // l'endroit où l'effort se fait vraiment, magasin par magasin.
    common.repEffort = res.avis5Requis == null
      ? (res.moyenne == null ? 'Aucune note connue.' : 'Cible inatteignable par ajout d’avis.')
      : (res.avis5Requis > 0 ? fInt(res.avis5Requis) + ' avis 5★ pour ramener le réseau à ' + fN(cible)
        : ((res.sousCible || 0) > 0
          ? 'Réseau à la cible en moyenne pondérée — l’effort porte sur les ' + res.sousCible + ' magasins en dessous.'
          : 'Aucun avis à obtenir : tous les magasins sont à la cible.'));
    common.repEffortFort = (res.avis5Requis || 0) > 0;
    common.repVide = (r.magasins || []).length === 0 || res.avis === 0;

    // Les plus loin de la cible en premier : c'est là que l'action se décide.
    // Les magasins sans note ferment la marche — ils demandent un raccordement
    // de fiche, pas un plan d'avis.
    const tri = (r.magasins || []).slice().sort((a, b) => {
      if (a.note == null && b.note == null) { return a.nom < b.nom ? -1 : 1; }
      if (a.note == null) { return 1; }
      if (b.note == null) { return -1; }
      return a.note - b.note;
    });

    common.repMagasins = tri.map(m => {
      const ok = m.note != null && m.note >= cible;
      const sans = m.note == null || m.avis === 0;
      return {
        nom: m.nom, ville: m.ville || '',
        note: fN(m.note), etoiles: this.repEtoiles(m.note, 14),
        noteSt: 'font-family:var(--font-display);font-size:21px;line-height:1;color:'
          + (sans ? 'var(--color-text-muted)' : (ok ? '#2d7a3e' : '#8D1D2C')),
        avis: sans ? 'Fiche Google non raccordée' : fInt(m.avis) + ' avis',
        synchro: m.synchro ? 'maj ' + this.fD(m.synchro.slice(0, 10)) : '',
        hasUrl: !!m.url, url: m.url || '#',
        badge: sans ? 'Sans note' : (ok ? 'À la cible' : (m.ecart > 0 ? '+' : '') + fN(m.ecart)),
        badgeSt: 'font-size:10.5px;font-weight:600;border-radius:999px;padding:2px 9px;white-space:nowrap;background:'
          + (sans ? 'var(--color-background-secondary);color:var(--color-text-muted)'
            : (ok ? 'rgba(45,122,62,.12);color:#2d7a3e' : 'rgba(141,29,44,.10);color:#8D1D2C')),
        // La phrase que le CEO cherche : combien d'avis parfaits pour revenir à
        // la cible, sur CE magasin.
        effort: m.avis5Requis == null
          ? (sans ? 'Raccordez la fiche pour suivre la note.' : 'Cible inatteignable par ajout d’avis.')
          : (m.avis5Requis === 0 ? 'À la cible — rien à rattraper.'
            : m.avis5Requis + ' avis 5★ pour atteindre ' + fN(cible)),
        effortSt: 'font-size:12px;font-weight:500;border-radius:8px;padding:7px 10px;margin-top:9px;background:'
          + (sans ? 'var(--color-background-secondary);color:var(--color-text-muted)'
            : (ok ? 'rgba(45,122,62,.08);color:#2d7a3e' : 'rgba(141,29,44,.05);color:#8D1D2C')),
        // Le raccordement se fait à la main : deux boulangeries de la même
        // enseigne dans la même ville ne se distinguent que par l'adresse.
        raccorde: !!m.placeId,
        rechOuverte: !!(S.repRech && S.repRech.magasinId === m.id),
        ouvrirRech: () => this.setState({ repRech: { magasinId: m.id,
          q: (m.nom || '').replace(/\s+—\s+/g, ' ') + (m.ville ? ' ' + m.ville : ''), candidats: null, busy: false } }),
        detacher: () => {
          if (!window.confirm('Détacher la fiche Google de « ' + m.nom + ' » ? Les avis déjà rapatriés sont conservés.')) { return; }
          this.api('PUT', '/reputation/' + m.id + '/fiche', { placeId: '' }).then(() => {
            this.D.reput = null; this.repCharge(true); this.notify('Fiche détachée'); });
        },
        jauges: jauges(m.repartition, m.avis),
        vide: (m.derniers || []).length === 0,
        derniers: (m.derniers || []).map(a => ({
          auteur: a.auteur, le: this.fD(a.le), note: a.note,
          etoiles: this.repEtoiles(a.note, 11),
          texte: a.texte ? (a.texte.length > 190 ? a.texte.slice(0, 190) + '…' : a.texte) : 'Note sans commentaire.',
          texteSt: 'font-size:11.5px;line-height:1.45;color:' + (a.texte ? 'var(--color-text)' : 'var(--color-text-muted)') + ';text-wrap:pretty',
          // Tri-état : la pastille n'apparaît que si la source SAIT. L'API Places
          // ne rend pas les réponses du magasin — écrire « Sans réponse »
          // partout serait une affirmation fausse, pas une information.
          repondu: a.repondu === true,
          reponduSt: 'font-size:9.5px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;border-radius:999px;padding:1px 7px;white-space:nowrap;background:rgba(45,122,62,.10);color:#2d7a3e',
          reponduTxt: 'Répondu',
        })),
      };
    });

    // --- le panneau de recherche de fiche (un seul ouvert à la fois)
    const rr = S.repRech;
    common.repRech = !rr ? null : {
      magasinId: rr.magasinId, q: rr.q, busy: !!rr.busy, err: rr.err || '',
      setQ: e => this.setState(s2 => ({ repRech: Object.assign({}, s2.repRech, { q: e.target.value }) })),
      fermer: () => this.setState({ repRech: null }),
      chercher: () => {
        const q = (this.state.repRech || {}).q || '';
        if (!q.trim()) { return; }
        this.setState(s2 => ({ repRech: Object.assign({}, s2.repRech, { busy: true, err: '' }) }));
        readOne('/reputation/recherche?q=' + encodeURIComponent(q)).then(d => {
          this.setState(s2 => ({ repRech: Object.assign({}, s2.repRech, { busy: false,
            candidats: (d && d.candidats) || [],
            err: d ? '' : 'Recherche impossible — vérifiez la clé Google dans Paramètres.' }) }));
        });
      },
      jamais: rr.candidats === null,
      aucun: Array.isArray(rr.candidats) && rr.candidats.length === 0,
      candidats: (rr.candidats || []).map(c => ({
        nom: c.nom, adresse: c.adresse || '',
        note: c.note == null ? 'sans note' : c.note.toFixed(1).replace('.', ',') + '★ · ' + (c.avis || 0) + ' avis',
        choisir: () => {
          this.setState(s2 => ({ repRech: Object.assign({}, s2.repRech, { busy: true }) }));
          this.api('PUT', '/reputation/' + rr.magasinId + '/fiche', { placeId: c.placeId }).then(async r2 => {
            const j = r2 && r2.json ? await r2.json().catch(() => null) : null;
            if (!j || j.error) {
              this.setState(s2 => ({ repRech: Object.assign({}, s2.repRech, { busy: false, err: (j && j.error) || 'Raccordement refusé.' }) }));
              return;
            }
            this.setState({ repRech: null });
            this.D.reput = null; this.repCharge(true);
            this.notify('Fiche raccordée — ' + (j.nouveaux || 0) + ' avis rapatriés');
          });
        },
      })),
    };
  }

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
    // La photo d'une cible — référence, meuble, niveau, zone. Elle est lue ici,
    // avant le plan : c'est le plan qui en a le plus besoin.
    const photoDe = (cible, id) => ((pl.notes || {})[cible + ':' + id] || {}).photo || null;
    // Pour une RÉFÉRENCE, le visuel du panel complète : la même chaîne que le
    // contrôle qualité (recette de la référence), mémorisée côté serveur. La
    // photo annexée dans le cockpit prime — c'est celle qu'on a choisie.
    if (!this.D.planoPhotos && !this._plPhEnCours) {
      this._plPhEnCours = true;
      readOne('/planogramme/photos').then(d => { this._plPhEnCours = false;
        this.D.planoPhotos = d || { photos: {} }; this.setState({ plPhMaj: Date.now() }); });
    }
    const phPanel = (this.D.planoPhotos || {}).photos || {};
    const photoProduit = ref => photoDe('ref', ref)
      || ((phPanel[String(ref)] || {}).url || null);
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
    // Moment de la journée : le comptoir se regarde à une heure donnée. Un
    // meuble sans période déclarée est monté toute la journée — il apparaît
    // donc à tous les moments, jamais à aucun.
    const refPer = ((pl.referentiels || {}).periodes || []);
    const perSel = S.plPeriode && refPer.some(p2 => p2.slug === S.plPeriode) ? S.plPeriode : '';
    common.plPeriodesOpts = [{ slug: '', nom: 'Toute la journée', aide: '', on: !perSel,
      go: () => this.setState({ plPeriode: '' }) }].concat(refPer.map(p2 => ({
      slug: p2.slug, nom: p2.nom || p2.slug, aide: p2.aide || '', on: p2.slug === perSel,
      go: () => this.setState({ plPeriode: p2.slug }) })));
    common.plPeriodeSel = perSel;
    const montE = m => !perSel || !(m.periodes || []).length || (m.periodes || []).indexOf(perSel) >= 0;
    const tousMeubles = zone ? (zone.meubles || []) : [];
    const meubles = tousMeubles.filter(montE);
    common.plPeriodeMasques = tousMeubles.length - meubles.length;
    common.plPeriodeTxt = perSel
      ? ((refPer.find(p2 => p2.slug === perSel) || {}).nom || perSel)
      : '';
    const nMax = meubles.reduce((a, m) => Math.max(a, (m.niveaux || []).length), 0);
    const cible = S.plCible || null;
    const perNoms = l => refPer.filter(p2 => (l || []).indexOf(p2.slug) >= 0)
      .map(p2 => p2.nom || p2.slug).join(' · ');
    common.plMeubles = meubles.map(m => ({ id: m.id, nom: m.nom,
      // « toute la journée » ne s'affiche pas : c'est le cas courant, l'écrire
      // sur chaque meuble noierait ceux qui ont vraiment une contrainte.
      periodes: (m.periodes || []).length ? perNoms(m.periodes) : '',
      renommer: () => this.plRenommer('meuble', m.id, m.nom),
      assistant: () => this.plMwOuvrir(zid, m),
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
              // Au moment regardé, l'occupant est celui qui y EST : les
              // croissants du matin ne se dessinent pas sur le comptoir de
              // midi. Sans moment choisi, le premier — et les autres comptés.
              const auMoment = o => !perSel || !(o.periodes || []).length
                || (o.periodes || []).indexOf(perSel) >= 0;
              const occs = (s.occupants || []).filter(auMoment);
              const occ = occs[0] || null;
              const autres = Math.max(0, (s.occupants || []).length - (occ ? 1 : 0));
              const vise = cible === s.id;
              return { id: s.id, position: s.position, libre: !occ, vise,
                nom: occ ? occ.nom : '', ref: occ ? occ.ref : '',
                // Une case occupée se prend ; toute case se reçoit. Déposer sur
                // une case occupée depuis le plan ÉCHANGE les deux références —
                // c'est le geste du comptoir, et il ne laisse personne nulle part.
                prendre: occ ? this.plPrendre(occ.ref) : null,
                deposer: this.plDeposerSur(s.id),
                // La photo, répétée autant de fois que la grille le dit : c'est
                // ce qui fait la différence entre une case étiquetée et un
                // planogramme. Sans photo, la case reste en texte et le dit.
                photo: occ ? photoProduit(occ.ref) : null,
                photoN: occ ? Math.min(36, Math.max(1, (occ.cols || 1) * (occ.rangs || 1))) : 0,
                photoCols: occ ? Math.max(1, occ.cols || 1) : 1,
                photoRangs: occ ? Math.max(1, occ.rangs || 1) : 1,
                photoTxt: occ && occ.cols && occ.rangs ? (occ.cols + ' × ' + occ.rangs) : '',
                // Vignette 1:1, texte DESSOUS : la photo n'est plus recadrée à
                // la forme du meuble et le nom ne recouvre plus l'image. La
                // forme réelle du slot reste lisible dans la ligne de texte
                // (grille, format) — c'est elle qui dit la vitrine, pas le
                // cadre de la vignette.
                stPhoto: 'overflow:hidden;border-radius:7px;cursor:pointer;background:var(--color-surface);'
                  + (vise ? 'border:1.5px solid var(--color-primary)'
                    : 'border:0.5px solid var(--color-border-tertiary)'),
                // La grille quand elle est connue — elle dit ce que les fronts
                // seuls ne disent pas : 6 × 1 et 3 × 2 font six produits, pas
                // la même vitrine. Sinon, les fronts, comme avant.
                detail: occ
                  ? ((occ.cols && occ.rangs
                      ? (occ.cols + ' × ' + occ.rangs + (occ.parSlot ? ' · ' + occ.parSlot : ''))
                      : (occ.fronts + ' front' + (occ.fronts > 1 ? 's' : '')))
                      + ((occ.periodes || []).length ? ' · ' + perNoms(occ.periodes) : '')
                      + (autres ? ' · +' + autres + ' autre(s) moment(s)' : ''))
                  : ([s.format || (s.largeurMm ? s.largeurMm + ' mm' : ''), s.contenant,
                      s.capacite ? String(s.capacite) : ''].filter(Boolean).join(' · ')),
                st: 'border-radius:7px;padding:6px 7px;min-height:50px;display:flex;flex-direction:column;'
                  + 'justify-content:space-between;gap:3px;font-size:10.5px;line-height:1.3;cursor:pointer;'
                  + (vise ? 'border:1.5px solid var(--color-primary);background:var(--color-primary);color:#fff;font-weight:600'
                    : occ ? 'border:0.5px solid var(--color-border-tertiary);background:var(--color-background-secondary);color:var(--color-text)'
                          : 'border:1.5px dashed var(--color-primary);background:rgba(141,29,44,0.04);color:var(--color-primary);font-weight:500'),
                clic: occ ? () => this.plFicheOuvrir(occ.ref) : () => this.setState({ plCible: vise ? null : s.id }) };
            }) };
        }) });
    }
    // --- impression : TOUT le comptoir, pas seulement la zone regardée.
    //
    // Une feuille qu'on emporte en boutique doit porter l'ensemble : à quoi bon
    // imprimer la vitrine qu'on a sous les yeux. La feuille est construite ici,
    // en HTML simple, et le navigateur s'occupe de la pagination.
    common.plImprimer = (pl.zones || []).length ? () => this.plImprimer() : null;
    common.plExporter = (pl.slots || []).length ? () => this.plExporter() : null;

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
    // Le plan avec les photos : c'est le planogramme tel qu'il se monte. Il
    // reste débrayable — une case sans photo se lit mieux en texte, et on veut
    // parfois voir la structure sans le décor.
    const photosOn = S.plPhotos !== false;
    common.plPhotosOn = photosOn;
    common.plPhotosGo = () => this.setState({ plPhotos: !photosOn });
    const placees = (pl.placements || []).filter(p2 => p2.slotId !== null);
    const avecPhoto = placees.filter(p2 => photoProduit(p2.ref)).length;
    common.plPhotosN = avecPhoto;
    common.plPhotosManque = placees.length - avecPhoto;

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

    // --- Les deux listes de choix du comptoir : format d'emplacement, contenant.
    // Elles viennent des tables et s'éditent d'ici : on tape pour filtrer, on
    // ajoute ce qui manque, la croix retire une position de la LISTE — les
    // emplacements qui la portaient gardent leur valeur.
    const refFmt = ((pl.referentiels || {}).formats || []);
    const refCont = ((pl.referentiels || {}).contenants || []);
    const cbx = S.plCbx || null;
    const cbxQ = e => { const v = e.target.value;
      this.setState(s2 => ({ plCbx: Object.assign({}, s2.plCbx, { q: v }) })); };
    const combo = (sl, quoi) => {
      const liste = quoi === 'format' ? refFmt : refCont;
      const type = quoi === 'format' ? 'formats' : 'contenants';
      const val = String((quoi === 'format' ? sl.format : sl.contenant) || '');
      const ouvert = !!(cbx && cbx.slot === sl.id && cbx.quoi === quoi);
      const saisie = ouvert ? String(cbx.q || '') : '';
      const q = saisie.trim().toLowerCase();
      const items = q ? liste.filter(o => o.nom.toLowerCase().indexOf(q) >= 0) : liste;
      const exact = liste.some(o => o.nom.toLowerCase() === q);
      return {
        val: val || '—', vide: !val, ouvert, q: saisie, quoi,
        ouvrir: () => this.setState({ plCbx: ouvert ? null : { slot: sl.id, quoi, q: '' } }),
        setQ: cbxQ,
        items: items.map(o => ({ id: o.id, nom: o.nom, on: o.nom === val,
          choisir: () => this.plSlotMaj(sl.id, quoi, o.nom),
          supprimer: () => this.plRefSupprimer(type, o.id, o.nom, quoi) })),
        vider: val ? () => this.plSlotMaj(sl.id, quoi, '') : null,
        // Ce qui n'est pas dans la liste s'y ajoute : on finit d'écrire, on
        // clique, et la position sert aussitôt pour cet emplacement.
        ajouter: (q && !exact) ? () => this.plRefAjouter(type, saisie.trim(), sl.id, quoi) : null,
        ajoutTxt: saisie.trim(),
      };
    };

    // La grille en cours de saisie vit dans l'état tant qu'elle n'est pas
    // écrite : sans cela, le nombre tapé repartirait à chaque rendu.
    const brouillons = S.plGr || {};

    // Les moments du référentiel, pour les pastilles du tableau.
    const refPerT = ((pl.referentiels || {}).periodes || []);
    let rangs = (pl.slots || []).map(s => {
      const occ = (s.occupants || [])[0] || null;
      const autresOcc = Math.max(0, (s.occupants || []).length - 1);
      const br = occ ? (brouillons[occ.ref] || {}) : {};
      const nSaisi = br.n != null ? br.n : (occ && occ.parSlot != null ? String(occ.parSlot) : '');
      const colsSaisi = br.cols != null ? br.cols : (occ && occ.cols != null ? occ.cols : null);
      const g = occ && String(nSaisi).trim() !== ''
        ? this.plGrilleCalc(+nSaisi, s.largeurMm, s.hauteurMm, colsSaisi) : null;
      // La ligne juste : celle qui divise exactement. Elle est proposée
      // d'office ; quand une ligne imposée laisse un reste, il est écrit.
      const juste = g && g.reste > 0
        ? this.plGrilleCalc(+nSaisi, s.largeurMm, s.hauteurMm, null) : null;
      return { id: s.id, zone: s.zone, meuble: s.meuble, niveau: s.niveau, position: s.position,
        taille: [s.largeurMm ? s.largeurMm + ' mm' : '', s.capacite ? 'cap. ' + s.capacite : ''].filter(Boolean).join(' · ') || '—',
        format: combo(s, 'format'), contenant: combo(s, 'contenant'),
        photo: occ ? photoProduit(occ.ref) : null,
        photoSet: occ ? e => this.plPhoto('ref', occ.ref, (e.target.files || [])[0]) : null,
        photoDel: occ && photoDe('ref', occ.ref) ? () => this.plPhotoRetirer('ref', occ.ref) : null,
        formatTxt: s.format || '',
        // Les dimensions ne sont dites que si le format ne les dit pas déjà :
        // « 60 × 15 cm » écrit deux fois de suite n'apprend rien.
        dims: (!s.format && s.largeurMm && s.hauteurMm)
          ? (s.largeurMm / 10) + ' × ' + (s.hauteurMm / 10) + ' cm' : '',
        parSlot: nSaisi,
        parSlotSet: occ ? e => { const v = e.target.value;
          this.setState(s2 => ({ plGr: Object.assign({}, s2.plGr,
            { [occ.ref]: Object.assign({}, (s2.plGr || {})[occ.ref], { n: v, cols: null }) }) })); } : null,
        parSlotEcrire: occ ? e => this.plGrilleEcrire(occ.ref, s.id, e.target.value, null) : null,
        grille: g ? { cols: g.cols, rangs: g.rangs, poses: g.poses, reste: g.reste,
          txt: g.cols + ' × ' + g.rangs,
          // Taille d'un produit : le garde-fou. Quand elle devient absurde,
          // c'est la grille qui est fausse, pas la vitrine.
          taille: (s.largeurMm && s.hauteurMm)
            ? this.plCm(s.largeurMm / g.cols) + ' × ' + this.plCm(s.hauteurMm / g.rangs) + ' cm' : '',
          justeTxt: juste ? ('ligne de ' + juste.cols + ' : ' + juste.cols + ' × ' + juste.rangs + ' les prend tous') : '',
          justeGo: juste ? () => this.plGrilleEcrire(occ.ref, s.id, nSaisi, juste.cols) : null,
          opts: [1, 2, 3, 4, 5, 6].map(c => ({ c, on: c === g.cols,
            go: () => this.plGrilleEcrire(occ.ref, s.id, nSaisi, c) })) } : null,
        ref: occ ? occ.ref : '', nom: occ ? occ.nom : '',
        prendre: occ ? this.plPrendre(occ.ref) : null,
        deposer: this.plDeposerSur(s.id),
        autresOcc,
        // Les moments où CETTE référence est présentée ici. Tout coché =
        // toute la journée ; décocher le dernier y revient aussi — une
        // référence présentée à aucun moment ne serait nulle part.
        periodesRef: occ ? refPerT.map(p2 => ({ slug: p2.slug, nom: p2.nom || p2.slug,
          on: !(occ.periodes || []).length || (occ.periodes || []).indexOf(p2.slug) >= 0,
          bascule: () => { const dej = (occ.periodes || []).length ? occ.periodes.slice()
              : refPerT.map(p3 => p3.slug);
            const i = dej.indexOf(p2.slug);
            if (i >= 0) { dej.splice(i, 1); } else { dej.push(p2.slug); }
            write(this.source, 'PUT', '/planogramme/placement/' + encodeURIComponent(occ.ref),
              { slotId: s.id, periodes: dej.length ? dej : refPerT.map(p3 => p3.slug) })
              .then(r => { if (r && r.ok !== false) { this.plCharge(true); }
                else { this.notify('Moment refusé — ' + ((r && r.error) || 'écriture impossible')); } }); } })) : [],
        fronts: occ ? String(occ.fronts) : '—', libre: !occ,
        etat: occ ? 'occupé' : 'libre',
        vise: cible === s.id,
        ouvrir: occ ? () => this.plFicheOuvrir(occ.ref) : () => this.setState({ plCible: cible === s.id ? null : s.id }) };
    });
    if (libresSeules) { rangs = rangs.filter(r => r.libre); }
    if (q) {
      rangs = rangs.filter(r => (r.nom + ' ' + r.ref + ' ' + r.zone + ' ' + r.meuble + ' ' + r.niveau
        + ' ' + (r.formatTxt || '') + ' ' + ((r.contenant || {}).vide ? '' : (r.contenant || {}).val || ''))
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

    common.plZoneAdd = () => this.plAjouter('zone', null, S.plNZone, 'plNZone');
    // Le meuble passe par un ASSISTANT : type, température, présentation et
    // dimensions d'emplacement décident de ce qu'on peut y poser. Les demander
    // sur une ligne de saisie unique revenait à ne jamais les demander.
    common.plMeubleAdd = zid ? () => this.plMwOuvrir(zid) : null;
    common.plMw = S.plMw ? this.valsPlMw(S.plMw, pl, zone) : false;

    // Liste de ce qui existe, éditable sur place : c'est aussi la seule façon de
    // voir — et de corriger — un doublon créé par mégarde.
    common.plZonesListe = zones.map(z => ({ id: z.id, nom: z.nom,
      nMeubles: (z.meubles || []).length,
      on: z.id === zid,
      choisir: () => this.setState({ plZone: z.id, plMeubleSel: null }),
      renommer: e => this.plRenommer('zone', z.id, e.target.value),
      supprimer: () => this.plSupprimer('zone', z.id, z.nom) }));

    // Une photo peut être posée, remplacée ou retirée à tout moment, sur un
    // meuble comme sur un niveau : l'assistant n'est pas le seul moment où
    // l'on en dispose d'une.
    const photoCtrl = (cible, id) => ({
      photo: photoDe(cible, id),
      photoSet: e => this.plPhoto(cible, id, (e.target.files || [])[0]),
      photoDel: photoDe(cible, id) ? () => this.plPhotoRetirer(cible, id) : null,
    });

    common.plMeublesListe = meubles.map(m => Object.assign({ id: m.id, nom: m.nom,
      nNiveaux: (m.niveaux || []).length,
      nSlots: (m.niveaux || []).reduce((a, n) => a + (n.slots || []).length, 0),
      detail: [m.type, m.temperature, m.presentation,
        (m.periodes || []).length ? perNoms(m.periodes) : 'toute la journée'].filter(Boolean).join(' · '),
      periodesRef: refPer.map(p2 => ({ slug: p2.slug, nom: p2.nom || p2.slug,
        on: !(m.periodes || []).length || (m.periodes || []).indexOf(p2.slug) >= 0,
        // Décocher le dernier moment laisserait un meuble monté nulle part :
        // on repasse alors à « toute la journée », qui est l'état neutre.
        bascule: () => { const dej = (m.periodes || []).length ? m.periodes.slice()
            : refPer.map(p3 => p3.slug);
          const i = dej.indexOf(p2.slug);
          if (i >= 0) { dej.splice(i, 1); } else { dej.push(p2.slug); }
          this.plMeublePeriodes(m.id, dej); } })),
      renommer: e => this.plRenommer('meuble', m.id, e.target.value),
      assistant: () => this.plMwOuvrir(zid, m),
      supprimer: () => this.plSupprimer('meuble', m.id, m.nom) }, photoCtrl('meuble', m.id)));

    const msel = S.plMeubleSel && meubles.some(m => m.id === S.plMeubleSel)
      ? S.plMeubleSel : (meubles[0] ? meubles[0].id : null);
    common.plMeubleSel = msel;
    common.plMeubleOpts = meubles.map(m => ({ id: m.id, nom: m.nom, on: m.id === msel }));
    common.plMeubleSetSel = e => this.setState({ plMeubleSel: +e.target.value });
    common.plNiveauAdd = msel ? () => this.plAjouterNiveau(msel) : null;

    const mSel = meubles.find(m => m.id === msel) || null;
    common.plNiveauxListe = mSel ? (mSel.niveaux || []).map(n => Object.assign({ id: n.id, nom: n.nom,
      nSlots: (n.slots || []).length,
      renommer: e => this.plRenommer('niveau', n.id, e.target.value),
      ajouter: () => this.plAjouterSlots(n.id),
      supprimer: () => this.plSupprimer('niveau', n.id, n.nom) }, photoCtrl('niveau', n.id))) : [];

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
  /**
   * Imprime le planogramme entier.
   *
   * La feuille est écrite dans une fenêtre à part plutôt que dans l'écran :
   * imprimer l'application obligerait à masquer le rail, les modales et chaque
   * bouton en `@media print`, et le moindre écran ajouté ensuite ressortirait
   * sur le papier. Une page à part ne dit que ce qu'elle doit dire.
   *
   * Les photos annexées y figurent : c'est ce qui rend la feuille utilisable
   * au comptoir, où l'on compare ce qu'on voit à ce qui est attendu.
   */
  plImprimer(){
    const pl = this.D.plano || {};
    const esc = s => String(s == null ? '' : s).replace(/[&<>"]/g,
      c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    const notes = pl.notes || {};
    // La feuille porte la MÊME photo que l'écran : celle du cockpit si on en a
    // annexé une, sinon celle de la recette du panel. Celui qui monte le
    // comptoir compare ce qu'il a en main à ce qui est attendu.
    const phPanel = (this.D.planoPhotos || {}).photos || {};
    const photoRef = ref => ((notes['ref:' + ref] || {}).photo)
      || ((phPanel[String(ref)] || {}).url || null);
    const refPerI = ((pl.referentiels || {}).periodes || []);
    const perTxt = l => (l || []).length
      ? refPerI.filter(p2 => l.indexOf(p2.slug) >= 0).map(p2 => p2.nom || p2.slug).join(', ')
      : '';
    const jour = new Date().toLocaleDateString('fr-BE');
    const bloc = [];
    (pl.zones || []).forEach(z => {
      const nz = notes['zone:' + z.id];
      bloc.push('<section><h2>' + esc(z.nom) + '</h2>'
        + (nz && nz.texte ? '<p class="note">' + esc(nz.texte) + '</p>' : ''));
      (z.meubles || []).forEach(m => {
        const nm = notes['meuble:' + m.id];
        // Sur la feuille, le moment de la journée compte autant que la
        // température : c'est elle qui dit à quelle heure ce meuble est monté.
        const perM = ((pl.referentiels || {}).periodes || [])
          .filter(p2 => (m.periodes || []).indexOf(p2.slug) >= 0)
          .map(p2 => p2.nom || p2.slug).join(' · ');
        const meta = [m.type, m.temperature, m.presentation,
          (m.periodes || []).length ? perM : 'toute la journée'].filter(Boolean).join(' · ');
        bloc.push('<h3>' + esc(m.nom) + (meta ? ' <span class="meta">' + esc(meta) + '</span>' : '') + '</h3>');
        if (nm && nm.texte) { bloc.push('<p class="note">' + esc(nm.texte) + '</p>'); }
        if (nm && nm.photo) { bloc.push('<img class="ph" src="' + esc(nm.photo) + '" alt="">'); }
        (m.niveaux || []).forEach(n => {
          const nn = notes['niveau:' + n.id];
          // Une CARTE par emplacement, photo d'abord — la feuille se lit comme
          // le comptoir se monte : on regarde la vitrine, pas un tableau.
          const nCol = Math.max(1, Math.min(6, (n.slots || []).length));
          const cases = (n.slots || []).map(s => {
            // TOUS les occupants, pas seulement le premier : un emplacement
            // qui en porte deux n'en montrait qu'un, sans le dire.
            const occ = (s.occupants || []);
            const dim = [s.longueurMm, s.largeurMm].filter(Boolean).join('×');
            const corps = occ.length
              ? occ.map(o => { const nr = notes['ref:' + o.ref];
                // La photo du produit sur la feuille : celui qui monte le
                // comptoir compare ce qu'il a en main à ce qui est attendu —
                // celle du cockpit si elle existe, sinon celle de la recette du
                // panel, comme à l'écran. Répétée selon la grille : la feuille
                // montre neuf croissants en 3 × 3, pas un croissant et un chiffre.
                const photo = photoRef(o.ref);
                const cols = Math.max(1, o.cols || 1), rangs = Math.max(1, o.rangs || 1);
                const nTuiles = Math.min(36, cols * rangs);
                const pave = photo
                  ? '<span class="mosaique" style="grid-template-columns:repeat(' + cols + ',1fr);'
                    + 'grid-template-rows:repeat(' + rangs + ',1fr)">'
                    + new Array(nTuiles).fill('<img class="pr" src="' + esc(photo) + '" alt="">').join('')
                    + '</span>'
                  : '<span class="sansphoto">sans photo</span>';
                const per = perTxt(o.periodes);
                return pave
                  + '<span class="nom">' + esc(o.nom) + '</span>'
                  + '<span class="f">' + (o.parSlot
                      ? o.parSlot + ' par emplacement · ' + cols + ' × ' + rangs
                      : o.fronts + ' front(s)') + '</span>'
                  + (per ? '<span class="f per">' + esc(per) + '</span>' : '')
                  + (nr && nr.texte ? '<span class="f n">' + esc(nr.texte) + '</span>' : ''); }).join('<span class="et"></span>')
              : '<span class="libre">libre</span>';
            const pied = [s.format || (dim ? dim + ' mm' : ''), s.contenant].filter(Boolean).join(' · ');
            return '<div class="case' + (occ.length ? '' : ' vide') + '">'
              + '<span class="pos">' + s.position + '</span>' + corps
              + (pied ? '<span class="f pied">' + esc(pied) + '</span>' : '')
              + '</div>';
          }).join('');
          // Le niveau est la SECTION du meuble : sa photo de présentation et
          // sa consigne se lisent avec sa rangée, pas trois pages plus loin.
          bloc.push('<div class="niveau">'
            + '<div class="nivnom">' + esc(n.nom) + '</div>'
            + (nn && nn.texte ? '<p class="note">' + esc(nn.texte) + '</p>' : '')
            + (nn && nn.photo ? '<img class="phn" src="' + esc(nn.photo) + '" alt="">' : '')
            + (cases
              ? '<div class="rangee" style="grid-template-columns:repeat(' + nCol + ',1fr)">' + cases + '</div>'
              : '<p class="libre">aucun emplacement</p>')
            + '</div>');
        });
      });
      bloc.push('</section>');
    });
    const t = pl.totaux || {};
    // La fenêtre part d'une page vierge : sans `base`, aucun chemin relatif n'y
    // résout — ni la feuille de style de la marque, ni ses polices, ni les
    // photos annexées, qui sortaient donc en cadres vides.
    const base = location.origin + location.pathname.replace(/[^/]*$/, '');
    const marque = (this.meta && this.meta.reseau) || {};
    const html = '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
      + '<base href="' + esc(base) + '">'
      + '<title>Planogramme comptoir — ' + esc(jour) + '</title>'
      + '<link rel="stylesheet" href="assets/ds/global.css">'
      + '<style>'
      // Les couleurs et les aplats doivent SORTIR à l'impression : sans cette
      // règle le navigateur les retire, et la feuille perd ses repères.
      + '*{-webkit-print-color-adjust:exact;print-color-adjust:exact}'
      + 'body{font-family:var(--font-ui),Helvetica,Arial,sans-serif;color:var(--color-text);'
      + 'background:#fff;margin:0;font-size:11px;line-height:1.5;font-weight:var(--weight-regular,400)}'
      + '.tete{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;'
      + 'border-bottom:2px solid var(--color-primary);padding-bottom:8px;margin-bottom:14px}'
      + '.tete img{height:13mm;width:auto;display:block}'
      + 'h1{font-family:var(--font-display),var(--font-ui),serif;font-size:21px;font-weight:400;margin:0;'
      + 'color:var(--color-primary);line-height:1.15}'
      + '.sous{color:var(--color-text-muted);margin:3px 0 0;font-size:10.5px}'
      + 'h2{font-family:var(--font-ui);font-size:13px;font-weight:600;margin:14px 0 5px;padding:4px 8px;'
      + 'background:var(--color-secondary);color:var(--color-on-abricot);border-radius:4px;'
      + 'text-transform:uppercase;letter-spacing:.07em}'
      + 'h3{font-size:12px;font-weight:600;margin:9px 0 3px}'
      + '.meta{font-weight:400;color:var(--color-text-muted)}'
      + '.note{margin:2px 0 6px;color:var(--color-text);font-style:italic;'
      + 'border-left:2px solid var(--color-secondary);padding-left:7px}'
      + 'table{width:100%;border-collapse:collapse;margin:0 0 5px;table-layout:fixed}'
      + 'th{width:62px;text-align:left;font-size:8.5px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;'
      + 'color:var(--color-text-muted);background:var(--color-background-secondary);'
      + 'border:0.5px solid var(--color-border-secondary);padding:5px 6px;vertical-align:top}'
      + 'td{border:0.5px solid var(--color-border-secondary);padding:5px 6px;vertical-align:top;font-size:10px}'
      + 'td b{font-weight:600;color:var(--color-primary)}'
      + '.f{display:block;color:var(--color-text-muted);font-size:8.5px;font-weight:400}'
      + '.n{font-style:italic}.libre{color:#9a938c;font-weight:400}'
      + '.ph{max-width:74mm;max-height:52mm;border:0.5px solid var(--color-border-secondary);'
      + 'border-radius:3px;margin:3px 0 7px;display:block}'
      // Photo de la SECTION (le niveau) : plus petite que celle du meuble, elle
      // accompagne sa rangée sans la repousser sur la page suivante.
      + '.phn{max-width:58mm;max-height:36mm;border:0.5px solid var(--color-border-secondary);'
      + 'border-radius:3px;margin:2px 0 4px;display:block}'
      // Photo du PRODUIT, dans sa case : assez grande pour reconnaître le
      // produit, assez petite pour qu'une rangée de six tienne en largeur.
      + '.nivnom{font-size:8.5px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;'
      + 'color:var(--color-text-muted);margin:5px 0 2px}'
      + '.rangee{display:grid;gap:2.5mm;align-items:start}'
      + '.case{border:0.5px solid var(--color-border-secondary);border-radius:3px;padding:2mm;position:relative}'
      + '.case.vide{border-style:dashed;color:#9a938c;min-height:14mm}'
      + '.pos{position:absolute;top:1mm;right:1.6mm;font-weight:600;font-size:9px;color:var(--color-primary)}'
      + '.mosaique{display:grid;gap:0.4mm;aspect-ratio:1;margin-bottom:1.6mm;background:var(--color-border-secondary)}'
      + '.pr{width:100%;height:100%;object-fit:cover;display:block;background:#fff}'
      // « Sans photo » n'occupe pas la place d'une photo : un bandeau suffit —
      // un grand carré vide ferait chercher une image qui n'existe pas.
      + '.sansphoto{height:9mm;border:0.5px dashed var(--color-border-secondary);'
      + 'border-radius:2px;margin-bottom:1.6mm;color:#9a938c;font-size:8.5px;'
      + 'display:flex;align-items:center;justify-content:center}'
      + '.et{display:block;height:1.4mm;border-top:0.5px dashed var(--color-border-secondary);margin:1.4mm 0 0}'
      + '.per{color:var(--color-primary);font-weight:500}'
      + '.pied{margin-top:1mm;border-top:0.5px solid var(--color-border-tertiary,#e5e0da);padding-top:0.8mm}'
      + '.nom{display:block;font-weight:500}'
      + 'section{break-inside:auto}h3,.niveau{break-inside:avoid}'
      + '@page{size:A4;margin:14mm}</style></head><body>'
      + '<div class="tete"><div>'
      + '<h1>Planogramme comptoir</h1>'
      + '<p class="sous">' + esc(marque.nom || '') + (marque.nom ? ' · ' : '') + esc(jour) + ' · '
      + (t.slots || 0) + ' emplacement(s), ' + (t.places || 0) + ' référence(s) placée(s), '
      + (t.libres || 0) + ' libre(s)</p></div>'
      + '<img src="assets/img/logo.png" alt="' + esc(marque.nom || '') + '"></div>'
      + bloc.join('') + '</body></html>';

    const w = window.open('', '_blank');
    if (!w) { this.notify('Le navigateur a bloqué la fenêtre d’impression.'); return; }
    w.document.write(html);
    w.document.close();
    // Attendre les images ET les polices : imprimer trop tôt sortirait des
    // cadres vides à la place des photos, et le texte dans la police de repli.
    let lance = false;
    const lancer = () => { if (lance) { return; } lance = true;
      try { w.focus(); w.print(); } catch (e) { /* fenêtre fermée */ } };
    const pret = () => {
      const polices = w.document.fonts && w.document.fonts.ready;
      if (polices && typeof polices.then === 'function') { polices.then(lancer); setTimeout(lancer, 3000); }
      else { setTimeout(lancer, 400); }
    };
    // Le filet de sécurité ne doit pas COUPER les images : avec une photo par
    // produit et par section, quatre secondes ne suffisent plus, et imprimer
    // trop tôt sort des cadres vides. On ne force qu'une fois les images
    // chargées — ou au bout de quinze secondes, quoi qu'il arrive.
    const filet = () => { try {
      if (Array.prototype.every.call(w.document.images, i => i.complete)) { pret(); }
    } catch (e) { lancer(); } };
    if (w.document.readyState === 'complete') { pret(); }
    else { w.onload = pret; setTimeout(filet, 4000); setTimeout(lancer, 15000); }
  }

  /* --- assistant de création d'un meuble ------------------------------------- */

  /**
   * Lit un fichier image et rend une data-URL réduite, sans l'envoyer.
   *
   * L'assistant collecte des photos AVANT que le meuble et ses niveaux
   * existent : il n'a pas encore d'identifiant à leur donner. On garde donc
   * l'image en main et on l'envoie une fois la création faite.
   */
  plImageLire(file){
    return new Promise((ok, non) => {
      if (!file) { non(new Error('aucun fichier')); return; }
      if (!/^image\/(jpeg|png|webp)$/.test(file.type)) { non(new Error('format non accepté')); return; }
      const fr = new FileReader();
      fr.onerror = () => non(new Error('lecture impossible'));
      fr.onload = () => {
        const img = new Image();
        img.onerror = () => non(new Error('image illisible'));
        img.onload = () => {
          const max = 1600;
          const ech = Math.min(1, max / Math.max(img.width, img.height));
          if (ech >= 1 && file.size < 1.5 * 1024 * 1024) { ok(fr.result); return; }
          const cv = document.createElement('canvas');
          cv.width = Math.round(img.width * ech); cv.height = Math.round(img.height * ech);
          cv.getContext('2d').drawImage(img, 0, 0, cv.width, cv.height);
          ok(cv.toDataURL('image/jpeg', 0.85));
        };
        img.src = fr.result;
      };
      fr.readAsDataURL(file);
    });
  }
  /** Envoie une data-URL déjà préparée. */
  plPhotoEnvoyer(cible, cibleId, data){
    return write(this.source, 'POST', '/planogramme/photo', { cible, cibleId: String(cibleId), data });
  }
  /**
   * L'assistant : neuf sans `meuble`, ÉDITION préremplie avec. En édition, la
   * structure (niveaux, emplacements) ne bouge pas d'ici — la refaire à
   * l'aveugle déplacerait ce qui est posé ; elle se retouche dans le panneau
   * d'organisation, et les dimensions passent par le format, au tableau.
   */
  plMwOuvrir(zoneId, meuble){
    const r = ((this.D.plano || {}).referentiels) || {};
    const d = r.slotDefaut || {};
    const m = meuble || null;
    const s1 = m ? (((m.niveaux || [])[0] || {}).slots || [])[0] || {} : {};
    this.setState({ plMw: { zoneId, etape: 1, busy: false, err: '',
      meubleId: m ? m.id : null,
      nom: m ? m.nom : '',
      type: m ? (m.type || '') : ((r.types || [])[0] || ''),
      temperature: m ? (m.temperature || '') : ((r.temperatures || [])[0] || ''),
      presentation: m ? (m.presentation || '') : ((r.presentations || [])[0] || ''),
      longueur: String((m ? s1.longueurMm : 0) || d.longueur || 300),
      largeur: String((m ? s1.largeurMm : 0) || d.largeur || 300),
      hauteur: String((m ? s1.hauteurMm : 0) || d.hauteur || 250), capacite: '',
      nNiveaux: m ? String((m.niveaux || []).length || 1) : '3',
      nSlots: m ? String((((m.niveaux || [])[0] || {}).slots || []).length || 0) : '4',
      // Toute la journée par défaut : c'est le cas courant, et un meuble
      // décoché partout ne serait monté à aucun moment — un plan vide.
      periodes: m
        ? ((m.periodes || []).length ? m.periodes.slice() : (r.periodes || []).map(p => p.slug))
        : ((r.periodes || []).map(p => p.slug)),
      // Photos collectées avant la création : une pour le meuble, une par
      // niveau, indexées par le rang du niveau. Elles partent une fois les
      // identifiants connus.
      photoMeuble: null, photosNiveau: {} } });
  }
  plMwPatch(patch){ this.setState(s => ({ plMw: Object.assign({}, s.plMw, patch) })); }
  /**
   * Change les moments de la journée d'un meuble déjà déclaré.
   *
   * Le PATCH ne touche que cette colonne : refaire passer le meuble par
   * l'assistant pour corriger une case obligerait à resaisir ses niveaux.
   */
  plMeublePeriodes(id, liste){
    write(this.source, 'PATCH', '/planogramme/meuble/' + id, { periodes: liste })
      .then(r => {
        if (!r || r.ok === false) { this.notify('Non enregistré : ' + ((r && r.error) || 'refusé')); return; }
        this.plCharge(true);
      });
  }
  /**
   * Les niveaux proposés portent des noms parlants quand ils sont peu nombreux.
   * « Haut / Médian / Bas » se lit sur un plan ; « Niveau 2 » demande de
   * compter. Au-delà de trois, la numérotation redevient la plus claire.
   */
  plMwNiveaux(n){
    const noms = { 1: ['Unique'], 2: ['Haut', 'Bas'], 3: ['Haut', 'Médian', 'Bas'] };
    if (noms[n]) { return noms[n]; }
    return Array.from({ length: n }, (_, i) => 'Niveau ' + (i + 1));
  }
  valsPlMw(w, pl, zone){
    const r = (pl.referentiels) || {};
    const nb = k => Math.max(0, Math.min(40, parseInt(w[k], 10) || 0));
    const nNiveaux = Math.max(1, nb('nNiveaux'));
    const nSlots = nb('nSlots');
    const dims = [w.longueur, w.largeur, w.hauteur].map(v => parseInt(v, 10) || 0);
    const listeNiv = this.plMwNiveaux(nNiveaux);
    const opt = (liste, val, k) => (liste || []).map(v => ({ v, on: v === val,
      pick: () => this.plMwPatch({ [k]: v }) }));
    return {
      etape: w.etape, busy: !!w.busy, err: w.err || '',
      // En édition, l'assistant corrige l'identité du meuble ; la structure se
      // retouche ailleurs, et l'écran le dit plutôt que de la refaire en douce.
      edition: !!w.meubleId,
      structTxt: (() => { if (!w.meubleId) { return ''; }
        const m = ((pl.zones || []).flatMap(z => z.meubles || [])).find(m2 => m2.id === w.meubleId);
        if (!m) { return ''; }
        return (m.niveaux || []).length + ' niveau(x), '
          + (m.niveaux || []).reduce((a, n) => a + (n.slots || []).length, 0) + ' emplacement(s)'; })(),
      zone: zone ? zone.nom : '',
      nom: w.nom, type: w.type, temperature: w.temperature, presentation: w.presentation,
      longueur: w.longueur, largeur: w.largeur, hauteur: w.hauteur, capacite: w.capacite,
      nNiveaux: w.nNiveaux, nSlots: w.nSlots,
      types: opt(r.types, w.type, 'type'),
      temperatures: opt(r.temperatures, w.temperature, 'temperature'),
      presentations: opt(r.presentations, w.presentation, 'presentation'),
      // Moments de la journée : plusieurs à la fois, donc des cases, pas un
      // choix unique. Le comptoir chaud n'est monté qu'à midi ; la vitrine à
      // viennoiseries, le matin.
      periodes: (r.periodes || []).map(pr => ({
        slug: pr.slug, nom: pr.nom || pr.slug, aide: pr.aide || '',
        on: (w.periodes || []).indexOf(pr.slug) >= 0,
        bascule: () => { const l = (this.state.plMw.periodes || []).slice();
          const i = l.indexOf(pr.slug);
          if (i >= 0) { l.splice(i, 1); } else { l.push(pr.slug); }
          this.plMwPatch({ periodes: l }); },
      })),
      // Aucun moment coché : le meuble n'existerait à aucune heure. On le dit
      // à l'étape plutôt que de créer un meuble invisible.
      periodesVide: (r.periodes || []).length > 0 && (w.periodes || []).length === 0,
      periodesTxt: (() => { const ref = r.periodes || [];
        const l = (w.periodes || []);
        if (!ref.length || l.length === ref.length) { return 'toute la journée'; }
        if (!l.length) { return 'aucun moment'; }
        return ref.filter(pr => l.indexOf(pr.slug) >= 0).map(pr => pr.nom || pr.slug).join(' · '); })(),
      set: k => e => this.plMwPatch({ [k]: e.target.value }),
      // Ce que l'assistant s'apprête à créer, en toutes lettres : on valide ce
      // qu'on a compris, pas un formulaire qu'on a rempli de mémoire.
      recap: [
        { k: 'Meuble', v: (w.nom || '(sans nom)') + (w.type ? ' — ' + w.type : '') },
        { k: 'Zone', v: zone ? zone.nom : '—' },
        { k: 'Température', v: w.temperature || '—' },
        { k: 'Présentation', v: w.presentation || '—' },
        { k: 'Moments de la journée', v: (() => { const ref = r.periodes || [];
          const l = (w.periodes || []);
          if (!ref.length || l.length === ref.length) { return 'toute la journée'; }
          if (!l.length) { return 'aucun — le meuble ne serait monté à aucune heure'; }
          return ref.filter(pr => l.indexOf(pr.slug) >= 0).map(pr => pr.nom || pr.slug).join(', '); })() },
        { k: 'Niveaux', v: nNiveaux + ' (' + listeNiv.join(', ') + ')' },
        { k: 'Emplacements', v: nSlots ? (nSlots + ' par niveau, soit ' + (nSlots * nNiveaux) + ' au total')
          : 'aucun pour l’instant' },
        { k: 'Un emplacement', v: dims[0] && dims[1]
          ? dims[0] + ' × ' + dims[1] + (dims[2] ? ' × ' + dims[2] : '') + ' mm'
          : 'dimensions non renseignées' },
        { k: 'Photos', v: (() => { const n = (w.photoMeuble ? 1 : 0)
            + Object.keys(w.photosNiveau || {}).filter(k2 => (w.photosNiveau || {})[k2]).length;
          return n ? n + ' à joindre' : 'aucune'; })() },
      ],
      niveauxTxt: listeNiv.join(' · '),
      total: nSlots * nNiveaux,
      // Photos : celle du meuble, puis une par niveau. Facultatives, ajoutables
      // aussi APRÈS coup depuis « Retoucher un meuble » — l'assistant ne doit
      // pas être le seul moment où l'on peut en poser une.
      photoMeuble: w.photoMeuble || null,
      photoMeubleSet: e => this.plMwPhoto('meuble', null, (e.target.files || [])[0]),
      photoMeubleDel: w.photoMeuble ? () => this.plMwPatch({ photoMeuble: null }) : null,
      photosNiveau: listeNiv.map((nom, i) => ({ nom, rang: i + 1,
        data: (w.photosNiveau || {})[i + 1] || null,
        set: e => this.plMwPhoto('niveau', i + 1, (e.target.files || [])[0]),
        del: (w.photosNiveau || {})[i + 1]
          ? () => this.plMwPatch({ photosNiveau: Object.assign({}, w.photosNiveau, { [i + 1]: null }) })
          : null })),
      nPhotos: (w.photoMeuble ? 1 : 0) + Object.keys(w.photosNiveau || {})
        .filter(k => (w.photosNiveau || {})[k]).length,
      precedent: w.etape > 1 ? () => this.plMwPatch({ etape: w.etape - 1, err: '' }) : null,
      suivant: w.etape < 5 ? () => {
        if (w.etape === 1 && !String(this.plLire('plmw-nom', w.nom) || '').trim()) {
          this.plMwPatch({ err: 'Donnez un nom à ce meuble — « Vitrine 1 », « Gondole A ».' });
          return;
        }
        this.plMwPatch({ nom: this.plLire('plmw-nom', w.nom), etape: w.etape + 1, err: '' });
      } : null,
      creer: w.etape === 5 ? () => this.plMwCreer() : null,
      fermer: () => this.setState({ plMw: null }),
    };
  }
  /** Retient une photo de l'assistant, sans l'envoyer : rien n'existe encore. */
  plMwPhoto(quoi, rang, file){
    if (!file) { return; }
    this.plImageLire(file).then(data => {
      const w = this.state.plMw;
      if (!w) { return; }
      if (quoi === 'meuble') { this.plMwPatch({ photoMeuble: data }); }
      else { this.plMwPatch({ photosNiveau: Object.assign({}, w.photosNiveau, { [rang]: data }) }); }
    }).catch(e => this.notify('Photo non retenue : ' + e.message));
  }
  plMwCreer(){
    const w = this.state.plMw;
    if (!w || w.busy) { return; }
    // Les champs sont relus DANS L'ÉCRAN au moment de créer. Mesuré : la
    // capacité saisie n'arrivait pas au serveur — l'état ne l'avait pas gardée,
    // alors qu'elle était bien affichée. Ce que l'utilisateur voit est ce qui
    // part ; c'est la seule règle qui ne dépend d'aucune plomberie.
    const lu = (id, val) => this.plLire(id, val);
    const nb = (v, min, max) => Math.max(min, Math.min(max, parseInt(v, 10) || 0));
    const ent = v => { const n = parseInt(v, 10); return (isFinite(n) && n > 0) ? n : null; };
    const nNiveaux = nb(lu('plmw-nniv', w.nNiveaux), 1, 40);
    const nSlots = nb(lu('plmw-nslot', w.nSlots), 0, 40);
    const nom = String(lu('plmw-nom', w.nom) || '').trim();
    if (!nom) { this.plMwPatch({ etape: 1, err: 'Donnez un nom à ce meuble.' }); return; }
    this.plMwPatch({ busy: true, err: '' });

    // ÉDITION : l'identité change, la structure reste. Les photos collectées
    // partent sur les identifiants déjà connus — meuble et niveaux existants.
    if (w.meubleId) {
      write(this.source, 'PATCH', '/planogramme/meuble/' + w.meubleId, {
        nom, type: w.type, temperature: w.temperature, presentation: w.presentation,
        periodes: w.periodes || [],
      }).then(res => {
        if (!res || res.ok === false) {
          this.plMwPatch({ busy: false, err: (res && res.error) || 'modification refusée' });
          return;
        }
        const meuble = ((this.D.plano || {}).zones || [])
          .flatMap(z => z.meubles || []).find(m => m.id === w.meubleId) || {};

        // La structure s'ÉTEND depuis l'assistant : les niveaux existants sont
        // complétés jusqu'au nombre demandé, les niveaux manquants créés avec
        // leurs emplacements. Jamais l'inverse — demander moins ne retire
        // rien, et la notification le dit plutôt que de laisser croire.
        const dims = { largeurMm: ent(lu('plmw-lar', w.largeur)),
          longueurMm: ent(lu('plmw-lon', w.longueur)), hauteurMm: ent(lu('plmw-hau', w.hauteur)),
          capacite: ent(lu('plmw-cap', w.capacite)) };
        const niveaux = meuble.niveaux || [];
        const chaine = [];
        let plusSlots = 0, plusNiveaux = 0, enMoins = 0;
        niveaux.forEach(n => { const dej = (n.slots || []).length;
          if (nSlots > dej) { plusSlots += nSlots - dej;
            chaine.push(() => write(this.source, 'POST', '/planogramme/emplacement',
              Object.assign({ niveauId: n.id, nombre: nSlots - dej }, dims))); }
          if (nSlots < dej) { enMoins++; } });
        for (let r2 = niveaux.length + 1; r2 <= nNiveaux; r2++) {
          plusNiveaux++; plusSlots += nSlots;
          const nomNiv = this.plMwNiveaux(nNiveaux)[r2 - 1] || ('Niveau ' + r2);
          chaine.push(() => write(this.source, 'POST', '/planogramme/niveau',
            Object.assign({ parentId: w.meubleId, nom: nomNiv, rang: r2, slots: nSlots }, dims)));
        }
        if (nNiveaux < niveaux.length) { enMoins++; }

        const envois = [];
        if (w.photoMeuble) { envois.push(this.plPhotoEnvoyer('meuble', w.meubleId, w.photoMeuble)); }
        niveaux.forEach((n, i) => {
          const d2 = (w.photosNiveau || {})[i + 1];
          if (d2) { envois.push(this.plPhotoEnvoyer('niveau', n.id, d2)); }
        });
        // Les créations partent l'une APRÈS l'autre : les positions d'un même
        // niveau se numérotent au fil de l'eau, deux envois croisés se
        // marcheraient dessus.
        const suite = i2 => i2 < chaine.length ? chaine[i2]().then(() => suite(i2 + 1)) : Promise.resolve();
        suite(0).then(() => Promise.all(envois)).then(rs => {
          const rates = (rs || []).filter(r2 => !r2 || r2.ok === false).length;
          this.setState({ plMw: null });
          this.plCharge(true);
          this.notify('« ' + nom + ' » modifié'
            + (plusNiveaux ? ' · +' + plusNiveaux + ' niveau(x)' : '')
            + (plusSlots ? ' · +' + plusSlots + ' emplacement(s)' : '')
            + (enMoins ? ' — rien n’est retiré d’ici : la suppression se fait dans « Organiser le comptoir »' : '')
            + (envois.length ? ' · ' + (envois.length - rates) + '/' + envois.length + ' photo(s)' : '')
            + (rates ? ' — ' + rates + ' photo(s) non enregistrée(s)' : ''));
        });
      });
      return;
    }
    write(this.source, 'POST', '/planogramme/meuble', {
      nom, parentId: w.zoneId, type: w.type, temperature: w.temperature, presentation: w.presentation,
      periodes: w.periodes || [],
      longueurMm: ent(lu('plmw-lon', w.longueur)), largeurMm: ent(lu('plmw-lar', w.largeur)),
      hauteurMm: ent(lu('plmw-hau', w.hauteur)),
      capacite: ent(lu('plmw-cap', w.capacite)),
      niveaux: this.plMwNiveaux(nNiveaux).map(n => ({ nom: n, slots: nSlots })),
    }).then(res => {
      if (!res || res.ok === false) {
        this.plMwPatch({ busy: false, err: (res && res.error) || 'création refusée' });
        return;
      }
      // Les photos partent APRÈS, une fois les identifiants connus. Le meuble
      // est déjà créé : un envoi de photo qui échoue ne doit pas défaire ce qui
      // a réussi — on le dit, on ne revient pas en arrière.
      const envois = [];
      if (w.photoMeuble && res.id) { envois.push(this.plPhotoEnvoyer('meuble', res.id, w.photoMeuble)); }
      (res.niveaux || []).forEach(n => {
        const d2 = (w.photosNiveau || {})[n.rang];
        if (d2) { envois.push(this.plPhotoEnvoyer('niveau', n.id, d2)); }
      });
      Promise.all(envois).then(rs => {
        const rates = rs.filter(r => !r || r.ok === false).length;
        this.setState({ plMw: null });
        this.plCharge(true);
        this.notify('« ' + nom + ' » créé — ' + (res.slots || 0) + ' emplacement(s)'
          + (envois.length ? ' · ' + (envois.length - rates) + '/' + envois.length + ' photo(s)' : '')
          + (rates ? ' — ' + rates + ' photo(s) non enregistrée(s)' : ''));
      });
    });
  }

  /** La fiche de présentation d'une référence : sa photo, sa consigne, sa place. */
  valsPlFiche(f, pl){
    const d = f.d || {};
    const cat = d.catalogue || {};
    const n = f.note || {};
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
      technique: (d.technique || []).map(t => ({ k: t.champ, v: t.valeur })),
      techniqueVide: !(d.technique || []).length,
      manque: (d.manque || []).map(m => ({ champ: m.champ, quoi: m.quoi, source: m.source })),
      // Photo annexée dans le cockpit. Elle ne remplace pas celle du panel — on
      // dit l'un ET l'autre : ce visuel-ci ne partira pas en boutique tant que
      // la route de dépôt n'existe pas côté panel.
      // Le chemin est relatif au dossier de l'application : il se résout tout
      // seul, quelle que soit la base d'URL du déploiement.
      photo: (d.note || {}).photo || null,
      photoDepose: ev => this.plPhoto('ref', f.ref, (ev.target.files || [])[0]),
      photoRetirer: (d.note || {}).photo ? () => this.plPhotoRetirer('ref', f.ref) : null,
      // Consigne de présentation : un texte, rien d'autre. La gravité,
      // l'épinglage et la période ont été retirés de la fiche ; ce que le
      // serveur porte est relu et RENVOYÉ tel quel, pour qu'enregistrer un
      // texte n'efface pas ce qui a été posé ailleurs.
      noteTxt: n.texte != null ? n.texte : ((d.note || {}).texte || ''),
      notePin: n.epinglee != null ? !!n.epinglee : !!(d.note || {}).epinglee,
      noteGrav: n.gravite != null ? n.gravite : ((d.note || {}).gravite || 3),
      noteDu: n.du != null ? n.du : ((d.note || {}).du || ''),
      noteAu: n.au != null ? n.au : ((d.note || {}).au || ''),
      noteMaj: (d.note || {}).majLe ? ('modifiée par ' + ((d.note || {}).auteur || '—') + ', ' + (d.note || {}).majLe) : '',
      noteSet: k => e => { const v = e.target.type === 'checkbox' ? e.target.checked : e.target.value;
        this.setState(s2 => ({ plFiche: Object.assign({}, s2.plFiche,
          { note: Object.assign({}, s2.plFiche.note, { [k]: v }) }) }));
        // La consigne part d'elle-même après la frappe ; le bouton reste, il
        // confirme et relit la fiche.
        this.autoEnreg('consigne', () => this.plNoteAuto()); },
      noteAuto: this.autoTxt('consigne'),
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
  /**
   * Prendre une référence — depuis le catalogue, le plan ou le tableau.
   *
   * Rien n'est mis en état : un `setState` au départ du glisser redessine le
   * nœud qu'on tient, et le navigateur abandonne le geste. Ce qui voyage est
   * la référence, dans le presse-papier du glisser.
   */
  plPrendre(ref){
    return e => {
      try {
        e.dataTransfer.setData('text/plain', 'ref:' + ref);
        e.dataTransfer.effectAllowed = 'move';
      } catch (e2) { /* navigateur sans glisser-déposer : le clic reste */ }
    };
  }
  plDeposerSur(slotId){
    return e => {
      e.preventDefault();
      const t = e.dataTransfer ? e.dataTransfer.getData('text/plain') : '';
      if (!t || t.indexOf('ref:') !== 0) { return; }
      this.plDeposer(t.slice(4), slotId);
    };
  }
  /**
   * Déposer une référence sur un emplacement.
   *
   * Trois cas, et un seul est refusé : l'emplacement libre reçoit ; deux
   * références déjà placées permutent ; une référence qui n'est nulle part et
   * qu'on lâche sur une case occupée ne délogerait personne sans le dire — on
   * nomme l'occupant et on ne touche à rien.
   */
  plDeposer(ref, slotId){
    const pl = this.D.plano || {};
    const place = (pl.placements || []).find(p => p.ref === ref) || null;
    if (place && place.slotId === slotId) { return; }
    const cible = (pl.slots || []).find(s2 => s2.id === slotId) || null;
    if (!cible) { return; }
    // Le conflit se juge par chevauchement de moments : les croissants du
    // matin et le traiteur de midi partagent la même case sans s'y croiser.
    // Une liste vide vaut « toute la journée » et croise tout.
    const mesPer = (place ? place.periodes : []) || [];
    const croise = o => { const lo = o.periodes || [];
      return !mesPer.length || !lo.length || lo.some(x => mesPer.indexOf(x) >= 0); };
    const occ = (cible.occupants || []).filter(o => o.ref !== ref && croise(o));
    const venaitDe = place ? place.slotId : null;
    if (occ.length && !venaitDe) {
      this.notify('Emplacement occupé par ' + occ[0].nom + ' — déposez sur un emplacement libre, ou échangez depuis le plan.');
      return;
    }
    if (occ.length > 1) {
      this.notify('Emplacement partagé — l’échange ne saurait pas laquelle déplacer.');
      return;
    }
    // Le nom, pour le dire à l'écran : celui du comptoir s'il y est déjà, sinon
    // celui du catalogue. La référence nue ne reste qu'en dernier recours.
    const nom = ((pl.slots || []).flatMap(s2 => s2.occupants || []).find(o => o.ref === ref)
      || (this.D.prodCatalogue || []).find(c2 => String(c2.ref) === String(ref)) || {}).nom || ref;
    write(this.source, 'PUT', '/planogramme/placement/' + encodeURIComponent(ref),
      { slotId, echange: occ.length > 0, nom })
      .then(r => {
        if (!r || r.ok === false) {
          this.notify('Déplacement refusé — ' + ((r && r.error) || 'écriture impossible'));
          return;
        }
        const ou = cible.meuble + ' · ' + cible.niveau + ' · ' + cible.position;
        // Ce qui a changé est DIT : un échange déplace deux références, et une
        // grille refaite n'est pas celle qu'on avait posée.
        this.notify((r.echange ? 'Échangées : ' + nom + ' ↔ ' + occ[0].nom + ' — ' + nom + ' en ' + ou
          : nom + ' placée en ' + ou)
          + (r.regrille ? ' · grille refaite : ' + r.regrille.cols + ' × ' + r.regrille.rangs : ''));
        this.plCharge(true);
        this.D.prodCatalogue = null; this.plRechargeCatalogue();
      });
  }
  /**
   * Export CSV du comptoir : une ligne par occupant, une par emplacement
   * libre. Point-virgule et BOM — c'est ce qu'Excel en Belgique ouvre sans
   * assistant d'import. Ce fichier sert à la centrale et aux boutiques : il
   * dit QUOI, OÙ, COMBIEN et À QUEL MOMENT, pas comment l'écran le dessine.
   */
  plExporter(){
    const pl = this.D.plano || {};
    const refPer = ((pl.referentiels || {}).periodes || []);
    const nomsPer = l => (l || []).length
      ? refPer.filter(p => l.indexOf(p.slug) >= 0).map(p => p.nom || p.slug).join(', ')
      : 'toute la journée';
    const c = v => { const t = String(v == null ? '' : v);
      return /[;"\n]/.test(t) ? '"' + t.replace(/"/g, '""') + '"' : t; };
    const lignes = [['Zone', 'Meuble', 'Niveau', 'Position', 'Format', 'Contenant',
      'Dimensions (mm)', 'Référence', 'Nom', 'Par emplacement', 'Grille', 'Moments', 'État'].join(';')];
    (pl.slots || []).forEach(s2 => {
      const dims = (s2.largeurMm && s2.hauteurMm) ? s2.largeurMm + ' × ' + s2.hauteurMm : '';
      const socle = [s2.zone, s2.meuble, s2.niveau, s2.position,
        s2.format || '', s2.contenant || '', dims];
      const occs = s2.occupants || [];
      if (!occs.length) { lignes.push(socle.concat(['', '', '', '', '', 'libre']).map(c).join(';')); return; }
      occs.forEach(o => lignes.push(socle.concat([o.ref, o.nom,
        o.parSlot != null ? o.parSlot : '',
        (o.cols && o.rangs) ? o.cols + ' × ' + o.rangs : '',
        nomsPer(o.periodes), 'occupé']).map(c).join(';')));
    });
    // BOM : sans lui, Excel lit l'UTF-8 comme du latin et casse les accents.
    const blob = new Blob(['\ufeff' + lignes.join('\r\n')], { type: 'text/csv;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'planogramme-' + new Date().toISOString().slice(0, 10) + '.csv';
    document.body.appendChild(a); a.click();
    setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 400);
    this.notify(((pl.slots || []).length) + ' emplacement(s) exportés — ' + a.download);
  }
  /** Une longueur en millimètres, dite en centimètres, sans décimale inutile. */
  plCm(mm){ const v = Math.round(mm / 10 * 10) / 10; return String(v).replace('.', ','); }

  /**
   * La grille d'un produit dans son emplacement — la même règle qu'au serveur.
   *
   * Une ligne va jusqu'à SIX ; les rangées se déduisent par division arrondie
   * vers le bas. Sans ligne imposée, on propose la LIGNE JUSTE : celle qui
   * divise exactement, et parmi celles-là celle qui donne la case la plus
   * proche du carré dans les dimensions réelles de l'emplacement.
   */
  plGrilleCalc(n, largeurMm, hauteurMm, colsVoulues){
    n = Math.max(0, Math.min(400, Math.round(+n || 0)));
    if (!n) { return { n: 0, cols: 0, rangs: 0, poses: 0, reste: 0 }; }
    const lar = largeurMm > 0 ? largeurMm : 1, hau = hauteurMm > 0 ? hauteurMm : 1;
    if (colsVoulues > 0) {
      const c = Math.min(6, n, Math.round(colsVoulues));
      const r = Math.max(1, Math.floor(n / c));
      return { n, cols: c, rangs: r, poses: c * r, reste: Math.max(0, n - c * r) };
    }
    let best = [1, n], bs = null;
    for (let c = 1; c <= 6 && c <= n; c++) {
      if (n % c) { continue; }
      const r = n / c, l = lar / c, h = hau / r;
      const sc = Math.max(l / h, h / l);
      if (bs === null || sc < bs - 1e-9 || (Math.abs(sc - bs) <= 1e-9 && c > best[0])) { best = [c, r]; bs = sc; }
    }
    return { n, cols: best[0], rangs: best[1], poses: n, reste: 0 };
  }
  /** Format ou contenant d'un emplacement. Une valeur vide EFFACE le choix. */
  plSlotMaj(id, quoi, nom){
    write(this.source, 'PATCH', '/planogramme/emplacement/' + id, { [quoi]: nom })
      .then(r => { this.setState({ plCbx: null });
        if (r && r.ok !== false) { this.plCharge(true); } });
  }
  /** Ajouter une position à la liste, et l'utiliser aussitôt. */
  plRefAjouter(type, nom, slotId, quoi){
    if (!nom) { return; }
    write(this.source, 'POST', '/planogramme/referentiel/' + type, { nom })
      .then(r => { if (!r || r.ok === false) { this.setState({ plCbx: null }); return; }
        this.plSlotMaj(slotId, quoi, nom); });
  }
  /**
   * Retirer une position de la liste. Ce qui disparaît est la PROPOSITION :
   * les emplacements qui la portent gardent leur valeur — on le dit avant.
   */
  plRefSupprimer(type, id, nom, quoi){
    const pl = this.D.plano || {};
    const cle = quoi === 'format' ? 'format' : 'contenant';
    const n = (pl.slots || []).filter(s => (s[cle] || '') === nom).length;
    if (n > 0 && !window.confirm(nom + ' est utilisé par ' + n + ' emplacement(s).\n'
      + 'Les retirer de la liste ne les change pas : ils gardent cette valeur. Continuer ?')) { return; }
    write(this.source, 'DELETE', '/planogramme/referentiel/' + type + '/' + id)
      .then(r => { this.setState({ plCbx: null }); if (r && r.ok !== false) { this.plCharge(true); } });
  }
  /**
   * Le nombre par emplacement, et la ligne. Un nombre vide n'écrit rien : il
   * n'y a pas de grille par défaut, et zéro ne veut pas dire « un ».
   */
  plGrilleEcrire(ref, slotId, n, cols){
    const v = String(n == null ? '' : n).trim();
    if (v === '') { return; }
    this.setState(s2 => ({ plGr: Object.assign({}, s2.plGr,
      { [ref]: { n: v, cols: cols || null } }) }));
    write(this.source, 'PUT', '/planogramme/placement/' + encodeURIComponent(ref),
      { slotId, parSlot: Math.max(0, Math.round(+v || 0)), cols: cols || 0 })
      .then(r => { if (r && r.ok !== false) {
        // Le brouillon a fait son temps : la vérité revient du serveur.
        this.setState(s2 => { const g = Object.assign({}, s2.plGr); delete g[ref]; return { plGr: g }; });
        this.plCharge(true);
      } });
  }
  /** Le référentiel lit le placement : il doit être relu après une écriture. */
  plRechargeCatalogue(){
    readOne('/production/catalogue').then(c => { if (c) { this.D.prodCatalogue = c; } this.setState({}); });
  }
  /**
   * Annexe une photo. Le fichier est lu dans le navigateur et envoyé en
   * data-URL : toute l'application parle JSON, et basculer une seule route en
   * multipart pour cette occasion aurait coûté un chemin de plus à maintenir.
   *
   * L'image est réduite avant l'envoi — une photo de téléphone pèse 4 à 8 Mo et
   * n'apporte rien au-delà de 1600 px sur un plan de comptoir. Réduire ici
   * évite un refus du serveur que l'utilisateur ne saurait pas corriger.
   */
  plPhoto(cible, cibleId, file, apres){
    if (!file) { return; }
    if (!/^image\/(jpeg|png|webp)$/.test(file.type)) {
      this.notify('Format non accepté — JPEG, PNG ou WebP.'); return;
    }
    const envoi = data => write(this.source, 'POST', '/planogramme/photo', { cible, cibleId, data })
      .then(r => {
        if (!r || r.ok === false) { this.notify('Photo non enregistrée : ' + ((r && r.error) || 'échec')); return; }
        this.plCharge(true);
        if (typeof apres === 'function') { apres(r.photo || null); }
        this.notify(r.photo ? 'Photo annexée' : 'Photo retirée');
      });
    const fr = new FileReader();
    fr.onload = () => {
      const img = new Image();
      img.onload = () => {
        const max = 1600;
        const ech = Math.min(1, max / Math.max(img.width, img.height));
        if (ech >= 1 && file.size < 1.5 * 1024 * 1024) { envoi(fr.result); return; }
        const cv = document.createElement('canvas');
        cv.width = Math.round(img.width * ech); cv.height = Math.round(img.height * ech);
        cv.getContext('2d').drawImage(img, 0, 0, cv.width, cv.height);
        envoi(cv.toDataURL('image/jpeg', 0.85));
      };
      img.onerror = () => this.notify('Image illisible.');
      img.src = fr.result;
    };
    fr.onerror = () => this.notify('Lecture du fichier impossible.');
    fr.readAsDataURL(file);
  }
  plPhotoRetirer(cible, cibleId, apres){
    write(this.source, 'POST', '/planogramme/photo', { cible, cibleId, data: '' })
      .then(r => { if (!r || r.ok === false) { this.notify('Non retirée : ' + ((r && r.error) || 'échec')); return; }
        this.plCharge(true);
        if (typeof apres === 'function') { apres(null); }
        this.notify('Photo retirée'); });
  }
  /** La consigne, écrite sans le clic : même PUT, sans relire la fiche. */
  plNoteAuto(){
    const f = this.state.plFiche;
    if (!f) { return false; }
    const v = this.valsPlFiche(f, this.D.plano || {});
    return write(this.source, 'PUT', '/planogramme/note', { cible: 'ref', cibleId: f.ref,
      texte: v.noteTxt, epinglee: v.notePin ? 1 : 0, gravite: v.noteGrav,
      du: v.noteDu || '', au: v.noteAu || '' }).then(r => !(!r || r.ok === false));
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
  /**
   * Ce que le champ AFFICHE fait foi, pas ce que l'état croit savoir.
   *
   * L'état se met à jour sur un événement du navigateur, et cette plomberie a
   * suffi à perdre une saisie : après avoir touché le nombre d'emplacements, le
   * bouton « Ajouter » du niveau ne voyait plus le nom pourtant visible à
   * l'écran, et ne faisait plus rien — sans message. On lit donc le champ.
   */
  plLire(id, defaut){
    const el = document.getElementById(id);
    return el ? String(el.value) : String(defaut == null ? '' : defaut);
  }
  plAjouter(type, parentId, nom, champ){
    const ids = { plNZone: 'pl-nzone', plNMeuble: 'pl-nmeuble' };
    const v = String(this.plLire(ids[champ] || '', nom) || '').trim();
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
  /**
   * Un niveau de plus sur un meuble EXISTANT.
   *
   * Ni nom ni nombre à saisir : le niveau suit ce que le meuble fait déjà —
   * même nombre d'emplacements que son dernier niveau, nom numéroté à la
   * suite. Redemander ces deux valeurs à chaque ajout dupliquait l'assistant,
   * qui les a posées une fois pour toutes.
   */
  plAjouterNiveau(meubleId){
    const pl = this.D.plano || {};
    let meuble = null;
    (pl.zones || []).forEach(z => (z.meubles || []).forEach(m => { if (m.id === meubleId) { meuble = m; } }));
    const niveaux = (meuble && meuble.niveaux) || [];
    const dernier = niveaux[niveaux.length - 1];
    const slots = dernier ? (dernier.slots || []).length : 4;
    const nom = 'Niveau ' + (niveaux.length + 1);
    write(this.source, 'POST', '/planogramme/niveau', { nom, parentId: meubleId, slots })
      .then(r => {
        if (!r || r.ok === false) { this.notify('Non créé : ' + ((r && r.error) || 'échec')); return; }
        this.plCharge(true);
        this.notify('« ' + nom + ' » ajouté' + (slots ? ' avec ' + slots + ' emplacement(s)' : ''));
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
    this._caEnCours = this._caEnCours || {};
    const lire = (cle, chemin) => {
      if ((this.state.caData || {})[cle] || this._caEnCours[cle]) { return; }
      this._caEnCours[cle] = true;
      readOne(chemin).then(d => { this._caEnCours[cle] = false;
        this.setState(s2 => ({ caData: Object.assign({}, s2.caData,
          { [cle]: d || { etat: 'erreur', motif: 'API injoignable' } }) })); });
    };
    lire(ecr + '|' + per, r);
    // L'écran des commandes tient les DEUX bouts : ce que le franchisé demande
    // (réquisitions) et ce que le fournisseur doit servir (commandes). Deux
    // routes, un seul écran — les séparer obligeait à naviguer pour suivre une
    // même commande.
    if (ecr === 'caAchats') { lire('caCommandes|' + per, '/centrale/commandes'); }
  }
  valsCentrale(common){
    const S = this.state;
    // « Commandes franchisés » a rejoint « Commandes » : un
    // ancien lien ou un signet doit continuer à mener quelque part.
    // « Commandes franchisés » et « Stock » ont été retirés : un ancien lien ou
    // un signet doit continuer à mener quelque part.
    if (S.screen === 'caCommandes' || S.screen === 'caStock') {
      this.setState({ screen: 'caAchats' }); return;
    }
    const ecr = S.screen;
    const per = S.caPeriode || '30j';
    this.caCharge();
    const d = (S.caData || {})[ecr + '|' + per];
    common.caEcran = ecr;
    common.caChargement = !d;
    // Ce que l'écran est en train de lire, dit en clair pendant l'attente.
    common.caTitre = { caAchats: 'Commandes',
      caFacturation: 'Facturation magasins', caReglages: 'Réglages de la centrale',
      caDemande: 'Demandes', caCommandes: 'Commandes' }[ecr] || 'Lecture en cours';
    common.caChargeTxt = ecr === 'caAchats'
      ? 'Lecture des commandes chez le panel, magasin par magasin — quelques secondes.'
      : 'Lecture des données de la centrale…';
    common.caEtat = d ? (d.etat || 'ok') : '';
    common.caMotif = d && d.motif ? d.motif : '';
    common.caSource = d && d.source ? d.source : '';
    common.caTitreSrc = d && d.titre ? d.titre : '';
    // Le bandeau « Reste à brancher » affiche des phrases ; un endpoint peut
    // aussi livrer des lacunes structurées {champ, quoi, source} — aplaties ici.
    common.caManquants = ((d && d.manquants) || []).map(m2 => typeof m2 === 'string' ? m2
      : ((m2.champ || '') + ' — ' + (m2.quoi || '') + (m2.source ? ' (' + m2.source + ')' : '')));
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
    } else if (ecr === 'caCommandes') {
      // Filtre à bascule sur le statut : cliquer un badge le sélectionne,
      // re-cliquer revient à « toutes ». Les compteurs se calculent sur le
      // jeu complet, pas sur le filtre — sinon ils mentent dès qu'on filtre.
      const lgs = d.lignes || [];
      const fSt = S.caCmdStatut || '';
      const nSt = code => lgs.filter(x => x.statut === code).length;
      common.caChips = [['PENDING', 'En attente', '#8a5a13', 'rgba(193,122,42,0.16)'],
                        ['REALISED', 'Réalisée', '#2d7a3e', 'rgba(45,122,62,0.12)']]
        .map(([code, nom, texte, fond]) => ({ nom: nom + ' · ' + nSt(code), texte, fond,
          on: fSt === code,
          pick: () => this.setState({ caCmdStatut: fSt === code ? '' : code }) }));
      // L'écran se lit PAR FOURNISSEUR : une carte par fournisseur, ses 5
      // dernières commandes, l'attente en évidence. Le filtre de statut
      // s'applique aux lignes de chaque carte.
      common.caFournGroupes = (d.parFournisseur || []).map(g => ({
        nom: g.fournisseur,
        special: g.fournisseur === 'À répartir' || g.fournisseur === 'Sans fournisseur',
        resume: g.enAttente
          ? g.enAttente + ' en attente · ' + this.fE(g.valeurAttente)
          : 'rien en attente',
        resumeCol: g.enAttente ? '#8a5a13' : '#2d7a3e',
        resumeFond: g.enAttente ? 'rgba(193,122,42,0.16)' : 'rgba(45,122,62,0.12)',
        note: g.total > 5 ? 'les 5 dernières sur ' + g.total : g.total + ' commande' + (g.total > 1 ? 's' : ''),
        commandes: (g.commandes || []).filter(x => !fSt || x.statut === fSt).map(x => ({
          id: '#' + x.id, magasin: x.magasin, debut: x.debut || '—',
          statut: x.statut === 'PENDING' ? 'En attente' : (x.statut === 'REALISED' ? 'Réalisée' : (x.statut || '—')),
          col: x.statut === 'PENDING' ? '#8a5a13' : '#2d7a3e',
          valeur: this.fE(x.valeur), par: x.par || '—' })),
      })).filter(g => g.commandes.length);
      common.caRien = fSt ? 'Aucune réquisition « ' + (fSt === 'PENDING' ? 'en attente' : 'réalisée') + ' ».'
        : 'Aucune réquisition matière remontée par le panel.';
      // La ventilation actionnable : une ligne = UNE commande à passer chez UN
      // fournisseur, avec ses références et son montant estimé.
      common.caTable2 = (d.aCommander && d.aCommander.length) ? {
        titre: 'À commander maintenant — une commande par fournisseur',
        cols: ['Magasin', 'Fournisseur', 'Références à commander', 'Montant estimé (HT)'],
        rows: d.aCommander.map(a => ({ cells: [
          { t: a.magasin, mut: true }, { t: a.fournisseur },
          { t: String(a.nbRefs), num: true }, { t: this.fU(a.montant), num: true } ] })),
      } : null;
    } else if (ecr === 'caAchats') {
      // ── Ce que les franchisés ont demandé, et qui attend leur validation.
      //    Même écran que le suivi fournisseur : une commande se suit d'un
      //    bout à l'autre, de la demande du magasin à la livraison.
      const dc = (S.caData || {})['caCommandes|' + per] || null;
      const relF = (dc || {}).relancesFranchise || {};
      const lgsF = ((dc || {}).lignes || []).filter(l => l.statut === 'PENDING');
      common.caFrChargement = !dc;
      common.caFrN = lgsF.length;
      common.caFrValeur = this.fE(lgsF.reduce((a, l) => a + (+l.valeur || 0), 0));
      common.caFrLignes = lgsF.slice(0, 40).map(l => ({
        id: l.id, magasin: l.magasin || '—',
        fournisseur: (l.fournisseurs || []).join(' + ') || '—',
        debut: l.debut ? this.fD(l.debut) : '—',
        // Depuis quand ça attend : c'est ce chiffre qui fait agir, pas la date.
        attente: l.debut ? Math.max(0, Math.round((Date.now() - new Date(l.debut)) / 86400000)) : null,
        valeur: this.fE(l.valeur || 0), par: l.par || '—',
        relanceLe: (relF[String(l.id)] || {}).quand || '',
        relancer: () => {
          if (this.state.caFrRel === l.id) { return; }
          this.setState({ caFrRel: l.id });
          this.api('POST', '/centrale/commandes/relance-franchise', { id: l.id }).then(r2 => {
            this.setState({ caFrRel: null });
            this.notify(!r2 || r2.ok === false
              ? ('Rappel refusé — ' + ((r2 && (r2.erreur || r2.error)) || 'notification impossible'))
              : ('Notification envoyée à ' + (r2.magasin || 'ce magasin')
                 + (r2.notification ? ' (#' + r2.notification + ')' : '')));
            if (r2 && r2.ok) {
              this.setState(s2 => { const cd = Object.assign({}, s2.caData);
                Object.keys(cd).filter(k => k.startsWith('caCommandes|')).forEach(k => { delete cd[k]; });
                return { caData: cd }; });
            }
          });
        },
        enCours: this.state.caFrRel === l.id,
      }));
      common.caFrTronque = lgsF.length > 40 ? lgsF.length - 40 : 0;

      // ── Le suivi des commandes : 2 dernières par magasin chez chaque
      //    fournisseur, avec leur avancement. C'est ce qui traîne qui compte,
      //    donc les fournisseurs en retard remontent (tri fait côté serveur).
      const sv = d.suivi || {};
      const svK = sv.kpis || null;
      const fSv = S.caSvFiltre || '';
      const jf = z => !z ? '—' : String(z).slice(5).split('-').reverse().join('.');
      const garde = o => !fSv
        || (fSv === 'cours' && o.etape < 4 && !o.bloque)
        || (fSv === 'retard' && o.retardJours != null)
        || (fSv === 'livre' && o.etape === 4);
      // L'horodatage vient en SECONDES ; une valeur illisible ne doit pas
      // s'afficher « Invalid Date » — mieux vaut ne rien dire.
      const majD = sv.quand ? new Date((+sv.quand || 0) * 1000) : null;
      const maj = (majD && isFinite(majD.getTime()) && majD.getTime() > 0) ? majD : null;
      // Le nombre de fournisseurs peut manquer : on le compte alors sur les
      // groupes affichés plutôt que d'écrire « undefined fournisseur(s) ».
      const nFourn = svK && svK.fournisseurs != null ? svK.fournisseurs : (sv.groupes || []).length;
      common.caSvKpis = svK ? [
        ['Commandes en cours', String(svK.enCours), nFourn + ' fournisseur(s) · ' + svK.total + ' commandes suivies', ''],
        ['En retard', String(svK.retard), 'livraison prévue dépassée', svK.retard ? '#8D1D2C' : ''],
        ['Sans réponse fournisseur', String(svK.aAccepter), 'envoyée, pas encore acceptée', svK.aAccepter ? '#8a5a13' : ''],
        ['Lecture', sv.lues ? String(sv.lues) : '—', 'commandes lues' + (maj ? ' · ' + maj.toLocaleTimeString('fr-BE', { hour: '2-digit', minute: '2-digit' }) : ''), ''],
      ] : null;
      // La fenêtre des courriers : ce qui est parti, à qui, et ce qu'il citait.
      const sm = S.svMails || null;
      common.caSvMails = !sm ? null : {
        fournisseur: sm.fournisseur, commande: sm.cle,
        chargement: !!sm.chargement,
        note: (sm.d || {}).note || '',
        resume: (sm.d && sm.d.envois)
          ? sm.d.envois + ' rappel(s) · depuis le ' + this.fD(sm.d.depuis)
            + (sm.d.dernier ? ' · dernier le ' + this.fD(sm.d.dernier) : '')
          : 'Aucun rappel en cours pour ce fournisseur.',
        lignes: ((sm.d || {}).courriers || []).map(c2 => ({
          quand: c2.quand, sujet: c2.sujet || c2.detail || '—',
          vers: c2.destinataire || '—', copie: c2.copie || '',
          reqs: (c2.reqs || []).length ? 'réquisitions ' + c2.reqs.join(', ') : '',
          echec: c2.type === 'echec', clos: c2.type === 'clos',
          detail: c2.detail || '',
        })),
        vide: !((sm.d || {}).courriers || []).length,
        fermer: () => this.setState({ svMails: null }),
      };
      common.caSvChips = svK ? [['', 'Toutes', svK.total], ['cours', 'En cours', svK.enCours],
          ['retard', 'En retard', svK.retard], ['livre', 'Livrées', svK.total - svK.enCours]]
        .map(([v, nom, n]) => ({ nom: nom + ' · ' + n, on: fSv === v,
          pick: () => this.setState({ caSvFiltre: fSv === v ? '' : v }) })) : null;
      // Le clic sur une commande ouvre les COURRIERS envoyés à son fournisseur.
      const svCourriers = (fournisseur, cle) => () => {
        this.setState({ svMails: { fournisseur, cle, chargement: true, d: null } });
        readOne('/centrale/commandes/mail/courriers?fournisseur=' + encodeURIComponent(fournisseur))
          .then(d2 => this.setState(s2 => (s2.svMails && s2.svMails.cle === cle)
            ? { svMails: Object.assign({}, s2.svMails, { chargement: false, d: d2 || null }) } : {}));
      };
      common.caSvGroupes = (sv.groupes || []).map(g => ({
        nom: g.fournisseur,
        // Le rappel au fournisseur part tout seul chaque jour ; ce bouton sert
        // à le faire partir MAINTENANT, pour ce fournisseur-là.
        mailer: () => {
          if (this.state.caSvMail === g.fournisseur) { return; }
          this.setState({ caSvMail: g.fournisseur });
          this.api('POST', '/centrale/commandes/mail/envoyer', { fournisseur: g.fournisseur }).then(r2 => {
            this.setState({ caSvMail: null });
            this.notify(!r2 || r2.ok === false
              ? ('Courrier non envoyé — ' + ((r2 && (r2.erreur || r2.error)) || 'échec'))
              : ('Rappel envoyé à ' + g.fournisseur + (r2.vers ? ' · ' + r2.vers : '')));
          });
        },
        mailEnCours: this.state.caSvMail === g.fournisseur,
        meta: g.nbMagasins + ' magasin' + (g.nbMagasins > 1 ? 's' : '') + ' · ' + g.nbCommandes + ' commande' + (g.nbCommandes > 1 ? 's' : ''),
        alerte: g.retard ? g.retard + ' en retard' : (g.sansReponse ? g.sansReponse + ' sans réponse' : 'à jour'),
        alerteCol: g.retard ? '#8D1D2C' : (g.sansReponse ? '#8a5a13' : '#2d7a3e'),
        commandes: (g.commandes || []).filter(garde).map(o => ({
          magasin: o.magasin, cle: o.cle, date: jf(o.date),
          livraison: jf(o.livraisonPrevue), livraisonCol: o.retardJours != null ? '#8D1D2C' : '',
          segs: [1, 2, 3, 4].map(n => o.bloque && n === o.etape ? 'ko'
            : (o.etape === 4 || n < o.etape ? 'on' : (n === o.etape ? 'cur' : ''))),
          libelle: o.libelle,
          libelleCol: o.bloque || o.retardJours != null ? '#8D1D2C' : (o.etape === 4 ? '#2d7a3e' : '#8a5a13'),
          badge: o.retardJours != null ? 'retard ' + o.retardJours + ' j' : '',
          geste: o.geste || '—', source: o.source || '—',
          courriers: svCourriers(g.fournisseur, o.cle),
          // Relance : la cloche n'apparaît que si la commande attend encore
          // le fournisseur. Elle crée une NOTIFICATION dans l'ERP, pas un mail.
          relancable: o.etape < 4 && !o.bloque,
          relanceLe: o.relanceLe || '',
          relanceTitre: o.relanceLe ? 'Relancé le ' + o.relanceLe + ' — relancer à nouveau'
            : 'Relancer : créer une notification « commande à valider »',
          relancer: () => {
            if (this.state.svRelance === o.cle) { return; }
            this.setState({ svRelance: o.cle });
            this.api('POST', '/centrale/achats/relance', { id: o.id }).then(r => {
              this.setState({ svRelance: null });
              if (r && r.ok) {
                this.notify('Notification envoyée — ' + (r.fournisseur || '') + (r.notification ? ' (#' + r.notification + ')' : ''));
                this.log('Centrale', o.cle, 'Relance fournisseur envoyée');
                // Le cache d'écran est vidé pour cette clé : la ligne
                // réaffiche « relancé le … » sans recharger la page.
                this.setState(s2 => {
                  const cd = Object.assign({}, s2.caData);
                  Object.keys(cd).filter(k => k.startsWith('caAchats|')).forEach(k => { delete cd[k]; });
                  return { caData: cd };
                });
              } else { this.notify((r && (r.erreur || r.error)) || 'Relance impossible.'); }
            });
          },
          relanceEnCours: S.svRelance === o.cle,
        })),
      })).filter(g => g.commandes.length);
      // Le suivi est mémorisé 5 min ; ce bouton relit l'API sans attendre —
      // un statut qui vient de changer se voit tout de suite.
      common.caSvBusy = !!S.caSvBusy;
      common.caSvMaj = maj ? maj.toLocaleTimeString('fr-BE', { hour: '2-digit', minute: '2-digit' }) : '';
      common.caSvRafraichir = () => {
        if (this.state.caSvBusy) { return; }
        this.setState({ caSvBusy: true });
        readOne('/centrale/achats?refresh=1').then(d2 => {
          this.setState(s2 => {
            const cle = 'caAchats|' + (s2.caPeriode || '30j');
            return { caSvBusy: false,
              caData: d2 ? Object.assign({}, s2.caData, { [cle]: d2 }) : s2.caData };
          });
          this.notify(d2 ? 'Suivi actualisé' : 'Actualisation impossible — API injoignable');
        });
      };
      common.caSvRien = sv.indispo || (fSv ? 'Aucune commande dans ce filtre.'
        : 'Aucune commande lisible sur l’API.');
    } else if (ecr === 'caFacturation') {
      // Factures de redevances émises aux magasins, l'impayé en tête et en
      // couleur ; les abonnements TFBuddy en second tableau.
      const fmtD = v => v ? this.fD(v) : '—';
      common.caCols = ['Facture', 'Magasin', 'Émise', 'Échéance', 'Montant', 'Paiement', 'Relance'];
      common.caRows = (d.redevances || []).map(x => {
        const enRetard = x.paiement !== 'paid' && x.echeance && x.echeance < (this.M && this.M.TODAY || '');
        return { cells: [
          { t: x.numero }, { t: x.magasin, mut: true },
          { t: fmtD(x.emise), mut: true },
          { t: fmtD(x.echeance), col: enRetard ? '#8D1D2C' : '' },
          { t: this.fU(x.montant), num: true },
          { t: x.paiement === 'paid' ? 'payée' + (x.payeLe ? ' le ' + this.fD(x.payeLe) : '')
              : (enRetard ? 'impayée — en retard' : 'impayée'),
            col: x.paiement === 'paid' ? '#2d7a3e' : (enRetard ? '#8D1D2C' : '#8a5a13') },
          { t: x.relanceLe ? this.fD(x.relanceLe) : '—', mut: true } ] };
      });
      common.caRien = 'Aucune facture de redevances émise.';
      common.caTable2 = (d.abonnements && d.abonnements.length) ? {
        titre: 'Abonnements TFBuddy par magasin (Stripe)',
        cols: ['Magasin', 'Offre', 'Montant', 'Payé', 'Statut'],
        rows: d.abonnements.map(a => ({ cells: [
          { t: a.magasin || '—' }, { t: a.offre || '—', mut: true },
          { t: this.fU(a.montant), num: true }, { t: this.fU(a.paye), num: true },
          { t: a.statut || '—', mut: a.statut === 'paid' } ] })),
      } : null;
      // --- CA du réseau, mois par mois avec cumul : l'assiette des redevances.
      const M3 = (this.M && this.M.MOIS) || [];
      common.caTable2 = (d.caMensuel && d.caMensuel.length) ? {
        titre: 'Chiffre d’affaires réseau ' + (d.exercice || '') + ' — cumul par mois',
        cols: ['Mois', 'CA du mois', 'Cumul année'],
        rows: d.caMensuel.map(m2 => ({ cells: [
          { t: (M3[m2.mois - 1] || String(m2.mois)) + (m2.enCours ? ' (en cours)' : ''), mut: !!m2.enCours },
          { t: m2.ca != null ? this.fE(m2.ca) : '—', num: true },
          { t: this.fE(m2.cumul), num: true } ] })),
      } : null;
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
    common.exVueNom = cumule ? 'Cumulé' : 'Par mois';
    common.exStMois = ongl(!cumule);
    common.exStCumul = ongl(cumule);
    common.exLegendeReel = cumule ? 'réel cumulé' : 'réel du mois';
    common.exLegendeCible = cumule ? 'cible cumulée' : 'budget du mois (le contenant)';
    common.exLegendeOr = cumule ? '' : 'au-dessus du budget';
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
    // --- rentabilité par jour, comme l'« Analyse rentabilité » de la PWA :
    // résultat net = marge brute − labour/j − overhead/j (mois ÷ jours ouverts)
    const hp = this.state.exRentPer || 'semaine';
    const hz = this.state.exRent;
    if (!hz || hz.per !== hp) { setTimeout(() => this.exRentCharge(hp), 0); }
    const JR = ['LUN', 'MAR', 'MER', 'JEU', 'VEN', 'SAM', 'DIM'];
    const JRL = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
    // Les paliers de la PWA : rouge sous zéro, orange 0–5, verts 5–25, doré au-delà.
    const teinte = p => p == null ? 'background:var(--color-background-secondary);color:var(--color-text-muted)'
      : p < 0 ? 'background:#8D1D2C;color:#fff' : p < 5 ? 'background:#C17A2A;color:#fff'
      : p < 10 ? 'background:#A8B545;color:#fff' : p < 15 ? 'background:#7CB342;color:#fff'
      : p < 25 ? 'background:#3D8B44;color:#fff' : 'background:#C9A227;color:#fff';
    const fp1 = v => v == null ? '—' : v.toFixed(1).replace('.', ',') + ' %';
    const hd = hz && hz.d;
    const ongR = on => 'border:none;cursor:pointer;font-family:var(--font-ui);font-size:11.5px;'
      + 'padding:4px 11px;border-radius:7px;'
      + (on ? 'background:var(--color-primary);color:#fff;font-weight:500'
            : 'background:transparent;color:var(--color-text-muted)');
    common.exRent = {
      chargement: !hz || hz.chargement,
      mode: hp,
      indispo: hd && hd.indispo ? (hd.motif || 'API indisponible') : (hz && !hz.chargement && !hd ? 'La lecture de /exploitation/rentabilite a échoué — voir Diagnostic API.' : ''),
      periode: hd && hd.du ? (hp === 'mois' ? 'mois en cours — ' : 'semaine ') + this.fDA(hd.du) + ' → ' + this.fDA(hd.au) : '',
      btns: [['semaine', 'Semaine'], ['mois', 'Mois']].map(b => ({ label: b[1], st: ongR(hp === b[0]),
        go: () => { this.setState({ exRentPer: b[0], exRentDet: null }); this.exRentCharge(b[0]); } })),
      lignes: ((hd && hd.magasins) || []).map(m => ({
        nom: m.nom,
        motif: m.indispo ? (m.motif || 'sans réponse') : '',
        total: m.total && m.total.netPct != null ? fp1(m.total.netPct) + ' · ' + this.fE(m.total.net) : '',
        chips: (m.jours || []).map(j => ({
          semaine: hp === 'semaine',
          lib: hp === 'semaine' ? (JR[j.wd - 1] || '') : String(+j.date.slice(8, 10)),
          pct: !j.ouvert ? (hp === 'semaine' ? 'fermé' : '·')
            : (j.netPct == null ? '—' : (hp === 'semaine' ? fp1(j.netPct) : Math.round(j.netPct) + '%')),
          title: j.date + (j.ouvert && j.netPct != null ? ' — résultat net ' + fp1(j.netPct) : ''),
          st: teinte(j.ouvert ? j.netPct : null),
          go: j.ouvert ? () => this.setState({ exRentDet: { id: m.id, date: j.date } }) : null,
        })),
      })),
      legende: [['< 0 %', teinte(-1)], ['0–5', teinte(2)], ['5–10', teinte(7)], ['10–15', teinte(12)], ['15–25', teinte(20)], ['> 25 %', teinte(30)]]
        .map(l => ({ lib: l[0], st: l[1] })),
      source: hd ? (hd.source || '') : '',
    };
    // La modale d'un jour — les lignes de l'addition, comme la PWA.
    common.exRentDet = null;
    const rd = this.state.exRentDet;
    if (rd && hd && hd.magasins) {
      const mg = hd.magasins.find(m => m.id === rd.id);
      const j = mg && (mg.jours || []).find(x => x.date === rd.date);
      if (mg && j) {
        const pc = v => (v != null && j.ca > 0) ? (v / j.ca * 100).toFixed(1).replace('.', ',') + ' %' : null;
        const jourExact = /quotidien/.test(mg.sourceJour || '');
        common.exRentDet = {
          titre: (JRL[j.wd - 1] || '') + ' ' + this.fDA(j.date),
          magasin: mg.nom,
          close: () => this.setState({ exRentDet: null }),
          // Montants au centime, comme la PWA : l'addition doit tomber juste.
          lignes: [
            { op: '', lib: 'Chiffre d’affaires', pct: null, v: this.fU(j.ca), fort: false },
            { op: '−', lib: 'Coût matière', pct: null, v: this.fU(j.coutMatiere), fort: false },
            { op: '=', lib: 'Marge brute', pct: fp1(j.margePct), v: this.fU(j.margeBrute), fort: true },
            // Le libellé dit d'où vient le montant : le jour même quand le
            // panel sert son P&L quotidien, une moyenne du mois sinon. Lire
            // « mois ÷ jours ouverts » sur un chiffre réellement journalier
            // ferait douter du bon chiffre.
            { op: '−', lib: 'Labour' + (jourExact ? ' (du jour)' : ' (mois ÷ jours ouverts)'), pct: pc(j.labourJour), v: j.labourJour != null ? this.fU(j.labourJour) : 'manque API', fort: false },
            { op: '−', lib: 'Overhead' + (jourExact ? ' (du jour)' : ' (mois ÷ jours ouverts)'), pct: pc(j.overheadJour), v: j.overheadJour != null ? this.fU(j.overheadJour) : 'manque API', fort: false },
            { op: '=', lib: 'Résultat net', pct: fp1(j.netPct), v: j.net != null ? this.fU(j.net) : '—', fort: true,
              col: j.net == null ? 'var(--color-text-muted)' : (j.net >= 0 ? '#2d7a3e' : '#8D1D2C') },
          ],
          sous: j.tickets != null ? (j.tickets.toLocaleString('fr-BE') + ' tickets · panier ' + this.fU(j.panier)) : '',
          motif: j.motifNet || '',
          note: jourExact
            ? 'Chiffres du jour, servis par le P&L quotidien du panel — rien n’est réparti.'
            : 'Labour et overhead du mois répartis par jour d’ouverture'
              + (mg.joursOuverts ? ' (' + mg.joursOuverts + ' jours ce mois-ci)' : ''),
        };
      }
    }
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
        // Et à défaut de budget négocié, le CA THÉORIQUE de l'étude fait
        // contenant — sinon Corbais, qui n'a que deux mois validés mais douze
        // mois de théorique, n'avait aucun repère à l'écran.
        buds.push(c.caT != null && c.caT > 0 ? c.caT
          : (c.theo != null && c.theo > 0 ? c.theo : null));
      }
      const nb = buds.filter(b => b != null).length;
      const nbValide = ligne.filter(c => c && c.caT).length;
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
          const cx = i * sw, bx = (cx + sw * 0.18).toFixed(2), bw = (sw * 0.64).toFixed(2);
          // Tout mois à partir du dernier mois de caisse est incomplet — pas
          // seulement celui-ci. Une barre pleine sur un mois tronqué se lit
          // comme un effondrement, et c'est le contraire de ce qu'elle dit.
          const partiel = (i + 1) >= mc;
          const bud = bs[i], reel = rs[i];
          // Le BUDGET est le contenant, le réel le remplit. Un trait noir
          // au-dessus d'une barre demandait de comparer deux hauteurs ; un
          // verre à moitié plein se lit sans y penser — et les mois à venir
          // gardent leur contenant vide, qui attend son réel.
          if (hi > 0 && bud != null && bud > 0){
            barres.push({ x: bx, y: y(bud).toFixed(2), w: bw,
              h: Math.max((H - PB) - y(bud), 1).toFixed(2), fill: 'rgba(34,34,34,0.07)' });
          }
          if (hi > 0 && reel != null && reel > 0){
            const dedans = (bud != null && bud > 0) ? Math.min(reel, bud) : reel;
            barres.push({ x: bx, y: y(dedans).toFixed(2), w: bw,
              h: Math.max((H - PB) - y(dedans), 1).toFixed(2),
              fill: partiel ? 'url(#exhach)' : 'var(--color-primary)' });
            // Ce qui dépasse le budget sort en or, au-dessus du contenant.
            if (bud != null && bud > 0 && reel > bud){
              barres.push({ x: bx, y: y(reel).toFixed(2), w: bw,
                h: Math.max(y(bud) - y(reel), 1).toFixed(2), fill: '#C9A227' });
            }
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
        // Dire d'où vient la cible : un objectif repris du théorique n'est pas
        // un budget négocié, et la carte ne doit pas laisser croire l'inverse.
        objMois: this.fE(m.moisPlein) + (m.budgetSource === 'theorique' ? ' (théorique)' : ''),
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
             : (nbValide === nb ? 'budget encodé sur ' + nb + ' mois'
               : (nbValide ? nbValide + ' mois validés, ' + (nb - nbValide) + ' repris du théorique'
                 : 'contenant : CA théorique de l’étude')),
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
    // Position GÉNÉRALE : le rang par chiffre d'affaires sur TOUTES les
    // références — le même critère que le rang de catégorie, à l'échelle de
    // la gamme entière. Deux critères différents pour deux rangs voisins
    // rendraient le tableau illisible.
    base.slice().sort((a, b) => b.ca - a.ca).forEach((p, i) => { p.rangGlobal = i + 1; });
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
    // Le tri vit sur les EN-TÊTES : cliquer une colonne trie, recliquer
    // inverse — comme au Tableau des magasins. Le menu déroulant de tri
    // disparaît : dix colonnes triables n'ont pas besoin d'un second organe.
    const sk = S.pdSortKey || 'score', sdir = S.pdSortDir || -1;
    const colDefs = [
      ['cat',   'Catégorie',      'left',  1],
      ['nom',   'Produit',        'left',  1],
      ['vol',   'Volume',         'right', -1],
      ['pv',    'PV',             'right', -1],
      ['achat', 'Achat',          'right', -1],
      ['marge', 'Marge',          'right', -1],
      ['taux',  'Taux',           'right', -1],
      ['perte', 'Perte',          'right', -1],
      // Une position se lit du meilleur au moins bon : premier clic
      // ASCENDANT, contrairement aux montants.
      ['posG',  'Pos. générale',  'right', 1, 'Rang par CA sur toutes les références'],
      ['posC',  'Pos. catégorie', 'right', 1, 'Rang par CA dans la catégorie'],
      ['score', 'Score',          'right', -1],
    ];
    common.pdCols = colDefs.map(c2 => ({
      label: c2[1], align: c2[2], titre: c2[4] || 'Trier par ' + c2[1].toLowerCase(),
      arrow: sk === c2[0] ? (sdir > 0 ? ' ↑' : ' ↓') : '',
      sort: () => this.setState({ pdSortKey: c2[0], pdSortDir: sk === c2[0] ? -sdir : c2[3] }),
    }));
    // Recherche : retrouver UNE référence dans 200 lignes sans dérouler.
    // Insensible à la casse et aux accents — « eclair » trouve « Éclair » —
    // et l'identifiant de caisse marche aussi. Elle se cumule au filtre de
    // catégorie, elle ne le remplace pas.
    const norm = t => String(t || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    common.pdQ = S.pdQ || '';
    common.setPdQ = e => this.setState({ pdQ: e.target.value });
    const q = norm(S.pdQ).trim();
    const rows = base.filter(p => (S.pdCat === 'Toutes les catégories' || p.cat === S.pdCat)
      && (!q || norm(p.nom).includes(q) || norm(p.cat).includes(q) || String(p.id).includes(q)));
    // La valeur de tri de chaque colonne, prise sur la donnée BRUTE — jamais
    // sur le texte affiché, qui trierait « 12 » avant « 3 ». Une valeur
    // absente va toujours en FIN de liste, quel que soit le sens : une
    // donnée manquante n'est ni la meilleure ni la pire, elle est absente.
    const valTri = {
      cat: p => p.cat, nom: p => p.nom, vol: p => p.vol, pv: p => p.prix,
      achat: p => (p.mu == null ? null : p.prix - p.mu),
      marge: p => p.mu, taux: p => p.mp, perte: p => p.perte,
      posG: p => p.rangGlobal, posC: p => p.rang, score: p => p.score,
    }[sk] || (p => p.score);
    rows.sort((a, b) => {
      const va = valTri(a), vb = valTri(b);
      if (va == null && vb == null) { return b.score - a.score; }
      if (va == null) { return 1; }
      if (vb == null) { return -1; }
      const c2 = typeof va === 'string' ? va.localeCompare(vb, 'fr') : va - vb;
      return c2 !== 0 ? c2 * sdir : b.score - a.score;
    });
    const caProd = base.reduce((a, p) => a + p.ca, 0) || 1;
    const bar = (v, col) => 'display:block;height:5px;border-radius:999px;background:' + col + ';width:' + Math.max(3, Math.min(100, Math.round(v))) + '%';
    const eur = v => v == null ? '—' : v.toFixed(2).replace('.', ',') + ' €';
    // La ligne, à plat : une seule ligne par référence, aucun graphique — la
    // couleur ne reste que sur trois signaux (taux de marge, perte, score).
    // Ce qui a quitté la ligne (tendance, pénétration, CA, marge brute,
    // profil V·M·P·C, verdict en toutes lettres) vit dans la fiche au clic.
    common.pdRows = rows.map(p => { const vd = verdict(p.score);
      return { nom: p.nom, cat: p.cat,
        ouvrirDetail: () => this.pdOpenDetail(p.id),
        vol: Math.round(p.vol).toLocaleString('fr-BE'),
        prix: eur(p.prix), mu: eur(p.mu),
        // Le prix d'achat (coût matière) se déduit : la marge unitaire est
        // prix − coût, donc coût = prix − marge. Sans coût, un tiret.
        achat: p.mu == null ? '—' : eur(p.prix - p.mu),
        // Taux de marge : une puce et un pourcentage colorés au palier de
        // l'échelle de marge du réseau — la même couleur que partout.
        margeTxt: p.mp == null ? '—' : (p.mp < 0 ? 'Perte' : Math.round(p.mp * 100) + ' %'),
        margeCol: this.echelleMarge(p.mp == null ? null : p.mp * 100),
        perteTxt: p.perte == null ? '—' : this.fP(p.perte, 1),
        perteSt: p.perte == null ? 'color:var(--color-text-muted)'
          : (p.perte >= 0.10 ? 'color:#8D1D2C;font-weight:600' : p.perte >= 0.05 ? 'color:#8a5a13;font-weight:500' : 'color:#2d7a3e'),
        perteDetail: p.jete != null ? (Math.round(p.jete).toLocaleString('fr-BE') + ' jeté(s)' + (p.motifPerte ? ' · ' + p.motifPerte : '')) : '',
        openWaste: () => this.pdOpenWaste(p.id, p.nom),
        // Les deux positions, même critère (le CA) : sur la gamme entière,
        // et dans la catégorie de la référence.
        rangGlobal: p.rangGlobal + ' / ' + base.length,
        rang: p.rang + ' / ' + p.nbCat, part: this.fP(p.partCat, 0),
        score: String(Math.round(p.score)), scoreCol: vd[1], verdict: vd[0] }; });
    // --- détail d'une référence : le score décomposé, et les deux suites
    // possibles — l'envoyer aux projets, ou programmer son arrêt.
    const det = S.pdDet ? base.find(p2 => String(p2.id) === String(S.pdDet.id)) : null;
    if (S.pdDet && det) {
      const dd = S.pdDet;
      const vd2 = verdict(det.score);
      const patchDet = pl => this.setState(s2 => ({ pdDet: Object.assign({}, s2.pdDet, pl) }));
      const wt = k => Math.round(100 * (W[k] || 0) / ((W.v + W.m + W.perte + W.comptoir) || 1));
      const cat2 = (D.prodCatalogue || []).find(p2 => String(p2.ref) === String(det.id)) || {};
      const per2 = (this._pdPer || {})[det.id] || null;
      common.pdDet = {
        nom: det.nom, ref: String(det.id), cat: det.cat,
        score: String(Math.round(det.score)), verdict: vd2[0], col: vd2[1], fond: vd2[2],
        periode: _c.periode,
        // CA réseau et marge brute par fenêtre — mois affiché, trimestre,
        // année dernière. Sortis de la ligne du tableau, ils vivent ici.
        perChargement: !per2 || !!per2.chargement,
        perIndispo: !!(per2 && !per2.chargement && !per2.d),
        periodes: (per2 && per2.d ? per2.d.fenetres : []).map(f => ({
          label: f.label || f.cle || '—',
          volume: f.volume == null ? '—' : Math.round(+f.volume).toLocaleString('fr-BE'),
          ca: this.fE(f.ca),
          marge: f.marge == null ? '—' : this.fE(f.marge),
        })),
        criteres: [
          { nom: 'Volume vendu', poids: wt('v') + ' %', note: det.sVol, brut: Math.round(det.vol).toLocaleString('fr-BE') + ' pièces', col: '#8D1D2C' },
          { nom: 'Marge nette', poids: wt('m') + ' %', note: det.sMg, brut: det.mp == null ? 'marge indisponible' : this.fP(det.mp, 0) + ' de marge', col: '#2d7a3e' },
          { nom: 'Taux de perte', poids: wt('perte') + ' %', note: det.sPerte, brut: det.perte == null ? 'perte non mesurée' : this.fP(det.perte, 1) + ' jeté', col: '#C17A2A' },
          { nom: 'Présence comptoir', poids: wt('comptoir') + ' %', note: det.sComptoir, brut: det.mags + ' / ' + nbOuv + ' magasins', col: '#6b7fa8' },
        ].map(cr => ({ nom: cr.nom, poids: cr.poids, col: cr.col,
          note: cr.note == null ? '—' : String(Math.round(cr.note)) + ' / 100',
          barre: cr.note == null ? 0 : Math.max(2, Math.min(100, cr.note)),
          brut: cr.brut, absent: cr.note == null })),
        // Fin de gamme déjà programmée ? Elle s'affiche, et s'annule d'ici.
        finLe: cat2.finLe ? this.fD(cat2.finLe) + '/' + String(cat2.finLe).slice(0, 4) : '',
        finNote: cat2.finNote || '',
        adaptations: ['Adapter la recette', 'Revoir le prix', 'Changer la présentation / packaging',
          'Repositionner au comptoir', 'Campagne de relance'],
        adaptation: dd.adaptation || 'Adapter la recette',
        setAdaptation: e => patchDet({ adaptation: e.target.value }),
        precision: dd.precision || '',
        setPrecision: e => patchDet({ precision: e.target.value }),
        envoyerProjet: () => {
          const d2 = this.state.pdDet || {};
          const adaptation = d2.adaptation || 'Adapter la recette';
          const id2 = 'px' + Date.now();
          const nom2 = adaptation + ' — ' + det.nom;
          const valeurTxt = (d2.precision || '').trim() || ('Issu du scoring produit : score ' + Math.round(det.score) + ' (' + vd2[0] + '), ' + _c.periode);
          const corps = { id: id2, nom: nom2, famille: 'Produits', statut: 'À lancer', prio: 'Moyenne',
            debut: (M2t => M2t)(this.M.TODAY), fin: this.dansNJours(90), axes: ['Produit — Interne (production)'],
            leviers: [], budget: 0, valeurEst: null, valeurTxt, kpis: [], jalons: [], couts: [], taches: [],
            journal: 'Ligne créée depuis le scoring — ' + det.nom + ' (' + det.id + '), ' + adaptation };
          this.api('POST', '/projects', corps).then(r => {
            if (r && r.ok === false) { return; }
            D.projects.push({ id: id2, nom: nom2, famille: 'Produits', statut: 'À lancer', prio: 'Moyenne',
              debut: this.M.TODAY, fin: this.dansNJours(90), axes: ['Produit — Interne (production)'], leviers: [],
              budget: 0, valeurEst: null, valeurReal: null, valeurTxt, kpis: [], jalons: [], taches: [], couts: [] });
            this.log('Projet', nom2, 'Créé depuis le scoring produit');
            this.setState({ pdDet: null });
            this.notify('« ' + nom2 + ' » créé dans les projets');
          });
        },
        finDate: dd.finDate || '',
        setFinDate: e => patchDet({ finDate: e.target.value }),
        finTexte: dd.finTexte || '',
        setFinTexte: e => patchDet({ finTexte: e.target.value }),
        arreter: () => {
          const d2 = this.state.pdDet || {};
          if (!d2.finDate) { this.notify('Choisissez la date de fin.'); return; }
          this.api('PUT', '/production/fin/' + encodeURIComponent(det.id), { date: d2.finDate, note: (d2.finTexte || '').trim() })
            .then(r => { if (r && r.ok === false) { return; }
              this.log('Produit', det.nom, 'Arrêt programmé au ' + this.fD(d2.finDate));
              this.setState({ pdDet: null });
              this.notify('Arrêt de « ' + det.nom + ' » programmé — le réseau le voit au catalogue');
              this.D.prodCatalogue = null; });
        },
        annulerFin: cat2.finLe ? () => {
          this.api('PUT', '/production/fin/' + encodeURIComponent(det.id), { date: '' }).then(r => {
            if (r && r.ok === false) { return; }
            this.setState({ pdDet: null }); this.notify('Arrêt annulé — la référence redevient ordinaire');
            this.D.prodCatalogue = null; });
        } : null,
        fermer: () => this.setState({ pdDet: null }),
      };
    } else { common.pdDet = false; }

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
    common.pdNote = 'Score sur 100 = moyenne pondérée de quatre notes : volume vendu, marge nette, taux de perte et présence au comptoir (pondération dans Paramètres — ' + common.pdPond + '). '
      + 'Positions par CA — sur la gamme entière et dans la catégorie. Le reste (score décomposé, CA et marge du mois, du trimestre et de l’année dernière, profil, tendance) : cliquez la référence.'
      + (manque.length ? ' Critère(s) sans donnée aujourd’hui, donc EXCLUS du calcul (le score est repondéré sur les critères disponibles, il n’est pas pénalisé) : ' + manque.join(' ; ') + '.' : '');
  }

  /* --- marge & coûts ------------------------------------------------------------ */
  valsMarge(common){
    // Les tuiles « Marge nette réseau » et « Alertes actives » ont été retirées
    // à la demande, comme le badge du rail et la tuile d'accueil qui les
    // comptaient : l'écran ne garde que les ratios par magasin, et la colonne
    // Statut suffit à dire combien de leviers dépassent leur seuil.
    const s = this.seuils();
    const E = this.exo(), MI = this.moisIdxComplet();
    const moisNom = (this.M.MOIS && this.M.MOIS[MI]) || '';
    common.mgHdrPeriode = moisNom + ' ' + E + (this.moisPartiel() ? ' — dernier mois complet' : '');
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
    // Changer de journée remet le filtre boutique à « toutes ». Le sentinelle
    // est le LIBELLÉ, pas « tous » : le filtre compare `s.shop === ctrlShop`,
    // et poser « tous » ne correspondait à aucune boutique — la liste se
    // vidait à chaque changement de date, avec un menu qui affichait pourtant
    // « Toutes les boutiques ».
    const TOUTES = 'Toutes les boutiques';
    if (this.source !== 'api'){ this.setState({ ctrlShop: TOUTES }); return; }
    readOne('/pwa/tasks?date=' + encodeURIComponent(d)).then(pt => {
      if (pt) this.D.pwaTasks = pt;
      this.setState({ ctrlShop: TOUTES });
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
  /**
   * Ramène un contrôle masqué dans la liste du jour.
   *
   * Le calcul propose, l'humain dispose : un nouveau gérant, des travaux, une
   * réclamation — aucune moyenne ne les voit venir. La réouverture tient une
   * cadence complète, sinon le prochain rendu l'effacerait.
   */
  ctrlRouvrir(shopId, taskId, nom){
    if (!window.confirm('Remettre « ' + nom + ' » dans les contrôles du jour ?')) { return; }
    write(this.source, 'PUT', '/taches/maitrise', { shopId: +shopId, taskId: +taskId, action: 'rouvrir' })
      .then(r => {
        if (!r || r.ok === false) { this.notify('Non rouvert — ' + ((r && r.error) || 'refusé')); return; }
        this.notify('Contrôle rouvert — « ' + nom + ' »');
        // Relecture de la MÊME journée : la liste doit montrer la tâche
        // revenue, pas attendre un changement de date.
        const d = (this.D.pwaTasks || {}).date || '';
        readOne('/pwa/tasks' + (d ? '?date=' + encodeURIComponent(d) : '')).then(pt => {
          if (pt) { this.D.pwaTasks = pt; }
          this.setState({});
        });
      });
  }
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
  /**
   * Le texte des repères, reporté dans le commentaire de l'avis.
   *
   * Report AUTOMATIQUE : tant qu'il fallait cliquer « Reporter », un avis
   * partait régulièrement avec des repères que le franchisé ne recevait pas —
   * la photo annotée reste dans le cockpit, le commentaire est le seul canal.
   *
   * L'opération est IDEMPOTENTE : les lignes déjà écrites par une passe
   * précédente sont retirées avant d'écrire les nouvelles. Sans cela,
   * enregistrer deux fois empilait deux listes, et corriger un repère laissait
   * l'ancienne version juste au-dessus de la nouvelle. Ce qui a été écrit à la
   * main, lui, est conservé.
   */
  zReporte(rep, commentaire){
    const court = nom => String(nom || '').replace(/^Non conforme\s*[—-]\s*/i, '').toLowerCase();
    const lignes = (rep || []).filter(r => String(r.txt || '').trim())
      .map(r => r.n + '. [' + court((this.zNiveau(r.niveau) || {}).nom) + '] ' + String(r.txt).trim());
    const garde = String(commentaire || '').split('\n')
      .filter(l => !/^\s*\d+\.\s*\[[^\]]*\]/.test(l));
    const propre = garde.join('\n').trim();
    if (!lignes.length) { return propre; }
    return (propre ? propre + '\n' : '') + lignes.join('\n');
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
        // Les repères sont reportés dans le commentaire, puis la modale se
        // referme : l'annotation est finie, ce qui reste à faire est de poser
        // la note — et cela se passe dans l'écran de dessous.
        const commentaire = this.zReporte(dt.rep || [], dt.comment);
        const nCom = (dt.rep || []).filter(r2 => String(r2.txt || '').trim()).length;
        this.zPatch({ zBusy: false, zSaved: true, zoom: false, zSel: null, comment: commentaire,
          zSavedTxt: n ? (n > 1 ? n + ' repères enregistrés' : '1 repère enregistré') : 'Repères effacés' });
        this.notify((n ? (n > 1 ? n + ' repères enregistrés' : '1 repère enregistré') : 'Repères effacés')
          + (nCom ? ' · ' + nCom + ' reporté(s) dans le commentaire' : ''));
      });
  }
  /**
   * Le niveau déduit des repères posés sur la photo.
   *
   * Le plus SÉVÈRE l'emporte : un repère critique et trois mineurs font une
   * tâche critique, jamais l'inverse. C'est la seule agrégation défendable —
   * une moyenne dirait « conforme » d'une photo qui porte un manquement grave.
   * Rend `null` s'il n'y a aucun repère : on ne devine pas une note sans
   * matière.
   */
  ctrlNoteDeduite(rep){
    const l = (rep || []).map(r => +r.niveau).filter(n => isFinite(n) && n > 0);
    return l.length ? Math.min.apply(null, l) : null;
  }
  ctrlSendNote(){
    const dt = this.state.ctrlDet;
    if (!dt) { return; }
    // La note posée à la main prime ; à défaut, celle que disent les repères.
    const deduite = this.ctrlNoteDeduite(dt.rep);
    const note = dt.note || deduite;
    if (!note) { this.notify('Choisissez un niveau de conformité, ou posez des repères sur la photo.'); return; }
    // Sous le seuil, le commentaire est obligatoire : une non-conformité sans
    // motif est ininterprétable un mois plus tard.
    const seuil = (this.M.SIGNAL && this.M.SIGNAL.seuil) || 4;
    if (note < seuil && !String(dt.comment || '').trim()) {
      this.notify('Commentaire obligatoire pour une non-conformité.'); return;
    }
    const d = dt.d || {};
    this.setState(s => ({ ctrlDet: Object.assign({}, s.ctrlDet, { envoi: true }) }));
    write(this.source, 'POST', '/pwa/tasks/review', { shopId: dt.shopId, taskId: dt.taskId, date: dt.date,
      note, comment: dt.comment, checklistId: d.checklistId || null, completionId: d.completionId || null })
      .then(() => {
        // La modale RESTE ouverte : on la fermait d'office, et comme la
        // fermeture attendait le rechargement complet de la journée (4 à 5 s
        // sur /pwa/tasks), l'écran semblait se fermer tout seul, longtemps
        // après le clic. La note est posée, on le dit, et c'est l'utilisateur
        // qui referme.
        // La fenêtre se referme une fois la note partie, sans attendre le
        // rechargement de la journée : c'est ce qui la faisait disparaître
        // quatre secondes trop tard, comme d'elle-même. La ligne prend la note
        // tout de suite, la journée se recharge derrière.
        this.ctrlPatchLigne(Object.assign({}, dt, { note }));
        this.setState({ ctrlDet: null });
        this.notify('Note ' + note + '/5 enregistrée' + (dt.note ? '' : ' — niveau déduit des repères'));
        readOne('/pwa/tasks?date=' + encodeURIComponent(dt.date))
          .then(pt => { if (pt) { this.D.pwaTasks = pt; this.setState({}); } })
          .catch(() => {});
      })
      .catch(() => { this.setState(s => ({ ctrlDet: Object.assign({}, s.ctrlDet, { envoi: false }) }));
        this.notify('Échec de l’envoi de la note.'); });
  }
  /**
   * La ligne de la liste prend la note tout de suite.
   *
   * Sans cela, le tableau dessous garde « pas encore noté » jusqu'à ce que le
   * rechargement de fond réponde — et l'écran se contredit lui-même pendant
   * plusieurs secondes.
   */
  ctrlPatchLigne(dt){
    const pt = this.D.pwaTasks || {};
    const qui = ((this.meta || {}).utilisateur || {}).nom || 'CEO';
    const seuil = (this.M.SIGNAL && this.M.SIGNAL.seuil) || 4;
    (pt.shops || []).forEach(sh => { if (String(sh.shopId) !== String(dt.shopId)) { return; }
      (sh.taches || []).forEach(t => {
        if (String(t.taskId) !== String(dt.taskId) || t.date !== dt.date) { return; }
        t.note = dt.note; t.comment = dt.comment || ''; t.accepte = dt.note >= seuil;
        t.valide = true; t.valideePar = qui; t.valideeLe = 'à l’instant'; t.statut = 'notee';
      });
    });
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
            // Contrôle par exception : maîtrisée, la tâche sort de la liste du
            // jour — sans disparaître. Le motif et la date de re-contrôle
            // restent lisibles, et un bouton la ramène tout de suite.
            maitrisee: !!(t.maitrise && t.maitrise.masquee),
            maitriseMotif: (t.maitrise && t.maitrise.motif) || '',
            maitriseMoy: (t.maitrise && t.maitrise.moyenne != null)
              ? String(t.maitrise.moyenne).replace('.', ',') + ' / 5 sur ' + t.maitrise.nb + ' contrôles' : '',
            recontrole: (t.maitrise && t.maitrise.recontrole) ? 'Re-contrôle le ' + this.fD(t.maitrise.recontrole) : '',
            rouvrir: () => this.ctrlRouvrir(s.shopId, t.taskId, t.tache),
          }));
        const aFaire = taches.filter(t => !t.maitrisee);
        const masquees = taches.filter(t => t.maitrisee);
        return { shop: s.shop, shopId: s.shopId, nTaches: (s.taches || []).length,
          nValid: (s.taches || []).filter(x => x.valide).length,
          taches: aFaire, masquees, nMasquees: masquees.length,
          // Une boutique dont TOUT est maîtrisé n'est pas vide : elle est au
          // vert. Le dire vaut mieux que de la faire disparaître de l'écran.
          vide: aFaire.length === 0 && masquees.length === 0 };
      }).filter(s => !(common.ctrlOnly !== 'tous' && s.vide));
    common.ctrlShops = shops;
    common.ctrlEmpty = shops.length === 0;
    common.ctrlMasqTout = !!S.ctrlMasqTout;
    common.ctrlMasqPlier = () => this.setState({ ctrlMasqTout: !S.ctrlMasqTout });
    common.ctrlMasqTotal = shops.reduce((a, s2) => a + s2.nMasquees, 0);

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
        // Le niveau déduit des repères : proposé, montré, jamais imposé en
        // silence. Le plus sévère l'emporte — un repère critique et trois
        // mineurs font une tâche critique.
        niveauDeduit: (() => { const n2 = this.ctrlNoteDeduite(dt.rep);
          if (dt.note || n2 == null) { return ''; }
          const lv = ((M.SIGNAL || {}).niveaux || []).find(z => z.n === n2) || {};
          return lv.nom || (n2 + '/5'); })(),
        niveauDeduitCol: (() => { const n2 = this.ctrlNoteDeduite(dt.rep);
          const lv = ((M.SIGNAL || {}).niveaux || []).find(z => z.n === n2) || {};
          return lv.couleur || 'var(--color-text-muted)'; })(),
        niveaux: ((M.SIGNAL || {}).niveaux || []).map(lv => {
          const deduit = !dt.note && this.ctrlNoteDeduite(dt.rep) === lv.n;
          const on = dt.note === lv.n || deduit;
          return { n: lv.n, nom: lv.nom, aide: lv.aide || '',
            conforme: lv.n >= ((M.SIGNAL || {}).seuil || 4),
            deduit,
            st: 'display:flex;align-items:center;gap:10px;width:100%;text-align:left;cursor:pointer;'
              + 'font-family:var(--font-ui);font-size:12.5px;padding:9px 12px;border-radius:9px;margin-bottom:6px;'
              + (on ? 'border:' + (deduit ? '1px dashed ' : '1px solid ') + lv.couleur + ';background:' + lv.couleur + '14;font-weight:600;color:var(--color-text)'
                    : 'border:0.5px solid var(--color-border-secondary);background:transparent;color:var(--color-text)'),
            dotSt: 'width:10px;height:10px;border-radius:50%;flex:0 0 auto;background:' + lv.couleur,
            pick: () => this.setState(s2 => ({ ctrlDet: Object.assign({}, s2.ctrlDet, { note: lv.n }) })) }; }),
        verdict: (() => { const n2 = dt.note || this.ctrlNoteDeduite(dt.rep);
          return n2 == null ? '' : (n2 >= ((M.SIGNAL || {}).seuil || 4) ? 'Conforme' : 'Non conforme'); })(),
        verdictSt: dt.note == null ? '' : 'display:inline-block;padding:3px 10px;border-radius:999px;font-size:11.5px;font-weight:500;'
          + (dt.note >= ((M.SIGNAL || {}).seuil || 4) ? 'background:rgba(45,122,62,0.12);color:#2d7a3e' : 'background:rgba(141,29,44,0.12);color:#8D1D2C'),
        commentRequis: (dt.note || this.ctrlNoteDeduite(dt.rep) || 99) < ((M.SIGNAL || {}).seuil || 4),
        produit: d.produit || '', photoRef: d.photoRef || null,
        photoRefTxt: d.produitId ? (d.photoRef ? '' : 'Pas de photo.')
          : 'Cette tâche ne porte pas sur un produit précis — pas de visuel de référence.',
        setComment: e => { const v = e.target.value; this.setState(s2 => ({ ctrlDet: Object.assign({}, s2.ctrlDet, { comment: v }) })); },
        send: () => this.ctrlSendNote(),
        // --- note au consultant : depuis le contrôle, sans changer d'écran.
        //
        // Replié par défaut : c'est un geste occasionnel, pas une étape de la
        // notation. Envoyée « comme tâche », la note vit dans le projet
        // « Suivi consultants » et suit le circuit normal des tâches —
        // échéance, relance, validation.
        cn: (() => {
          const c2 = dt.cn || {};
          const consultants = (D.consultants || []).map(x2 => ({ id: String(x2.id), nom: x2.nom }));
          const patch = pl => this.setState(s2 => ({ ctrlDet: Object.assign({}, s2.ctrlDet,
            { cn: Object.assign({}, (s2.ctrlDet || {}).cn, pl) }) }));
          return {
            ouvert: !!c2.ouvert,
            basculer: () => patch({ ouvert: !c2.ouvert }),
            consultants,
            qui: c2.qui || (consultants[0] || {}).id || '',
            setQui: e => patch({ qui: e.target.value }),
            types: ['À corriger sur place', 'Rappel de procédure', 'Point de formation', 'Félicitations'],
            type: c2.type || 'À corriger sur place',
            setType: e => patch({ type: e.target.value }),
            texte: c2.texte || '',
            setTexte: e => patch({ texte: e.target.value }),
            comme: c2.comme || 'tache',
            commeBtns: [['tache', 'Tâche à échéance'], ['note', 'Simple note (journal)']]
              .map(([v, nom]) => ({ v, nom, on: (c2.comme || 'tache') === v, pick: () => patch({ comme: v }) })),
            due: c2.due || this.dansNJours(7),
            setDue: e => patch({ due: e.target.value }),
            busy: !!c2.busy,
            envoyer: () => {
              const cc = this.state.ctrlDet && this.state.ctrlDet.cn || {};
              const qui = cc.qui || (consultants[0] || {}).id || '';
              const texte = String(cc.texte || '').trim();
              if (!texte) { this.notify('Écrivez la note avant de l’envoyer.'); return; }
              patch({ busy: true });
              write(this.source, 'POST', '/consultants/note', {
                consultantId: qui, type: cc.type || 'À corriger sur place', note: texte,
                comme: cc.comme || 'tache', due: cc.due || this.dansNJours(7),
                shopId: dt.shopId || null,
                // L'origine voyage avec la note : la tâche pourra ensuite
                // rouvrir la photo et ses repères, au lieu d'une phrase seule.
                srcTaskId: dt.taskId || null, srcDate: dt.date || null,
                contexte: (d.tache || dt.nom || '') + ' — ' + (this.fDA ? this.fDA(dt.date) : dt.date),
              }).then(r => {
                if (!r || r.ok === false) { patch({ busy: false }); this.notify('Note non envoyée — ' + ((r && r.error) || 'refusé')); return; }
                const nomC = (consultants.find(x2 => x2.id === qui) || {}).nom || '';
                patch({ busy: false, ouvert: false, texte: '' });
                // Dire si la note est VRAIMENT partie dans le panel : une note
                // qui n'y arrive pas ne sera jamais lue par le consultant, et
                // le silence laisserait croire le contraire.
                const pn = r.panel || {};
                const dep = pn.id ? ' · déposée dans le panel (note #' + pn.id + ')'
                  : (pn.motif ? ' · pas déposée dans le panel : ' + pn.motif : '');
                this.notify((r.comme === 'tache'
                  ? 'Tâche envoyée à ' + nomC + ' — échéance ' + this.fD(r.due)
                  : 'Note consignée au journal pour ' + nomC) + dep);
                if (r.comme === 'tache') { readOne('/projects').then(pj => { if (pj) { this.D.projects = pj; this.setState({}); } }); }
              });
            },
          };
        })(),
        // ── Réclamation fournisseur : quand le défaut vient du PRODUIT, ce
        //    n'est pas le consultant qu'il faut reprendre.
        rc: (() => {
          const st = dt.rc || {};
          const patch = pl => this.setState(s2 => ({ ctrlDet: Object.assign({}, s2.ctrlDet,
            { rc: Object.assign({}, (s2.ctrlDet || {}).rc, pl) }) }));
          const refs = st.ouvert ? this.reclRefsCharge(dt.shopId) : null;
          const matieres = (refs && refs.matieres) || [];
          const q = String(st.q || '').trim().toLowerCase();
          const trouvees = q.length < 2 ? [] : matieres.filter(m =>
            (m.nom || '').toLowerCase().indexOf(q) >= 0 || (m.sku || '').indexOf(q) >= 0).slice(0, 8);
          const choisie = st.matiere || null;
          const livraisons = ((refs && refs.livraisons) || [])
            .filter(l => !choisie || !l.fournisseur || l.fournisseur === choisie.fournisseur);
          return {
            ouvert: !!st.ouvert,
            basculer: () => patch({ ouvert: !st.ouvert, q: '', matiere: null, err: '' }),
            chargement: !!st.ouvert && !refs,
            indispo: refs && refs.indispo ? (refs.motif || 'référentiels indisponibles') : '',
            note: (refs && refs.note) || '',
            q: st.q || '', setQ: e => patch({ q: e.target.value }),
            trouvees: trouvees.map(m => ({ nom: m.nom, sku: m.sku,
              choisir: () => patch({ matiere: m, q: m.nom }) })),
            matiere: choisie ? (choisie.nom + ' · SKU ' + choisie.sku + (choisie.unite ? ' · ' + choisie.unite : '')) : '',
            livraisons: [{ v: '', nom: 'Choisir la livraison…' }].concat(livraisons.map(l => ({ v: l.id,
              nom: (l.cle || ('Livraison ' + l.id)) + (l.le ? ' · ' + l.le.split('-').reverse().join('/') : '')
                + ' · ' + l.source }))),
            livraison: st.livraison || '', setLivraison: e => patch({ livraison: e.target.value }),
            motifs: [{ v: '', nom: 'Choisir le motif…' }].concat(((refs && refs.motifs) || []).map(m => ({ v: m.code, nom: m.nom }))),
            motif: st.motif || '', setMotif: e => patch({ motif: e.target.value }),
            quantite: st.quantite || '', setQuantite: e => patch({ quantite: e.target.value }),
            texte: st.texte !== undefined ? st.texte : (dt.comment || ''),
            setTexte: e => patch({ texte: e.target.value }),
            actions: [['REPLACEMENT', 'Remplacement'], ['REFUND', 'Remboursement'], ['CREDIT_NOTE', 'Avoir']]
              .map(([v, nom]) => ({ v, nom, on: (st.action || 'REPLACEMENT') === v, choisir: () => patch({ action: v }) })),
            valeur: (choisie && choisie.prix && st.quantite) ? this.fE(choisie.prix * (+st.quantite || 0)) : '',
            err: st.err || '', busy: !!st.busy, fait: st.fait || null,
            peut: !!(choisie && st.livraison && st.motif && +st.quantite > 0),
            envoyer: () => {
              const c2 = (this.state.ctrlDet || {}).rc || {};
              const m = c2.matiere;
              if (!m || !c2.livraison || !c2.motif || !(+c2.quantite > 0)) { this.notify('Référence, livraison, motif et quantité sont requis.'); return; }
              patch({ busy: true, err: '' });
              this.api('POST', '/fournisseurs/reclamation', {
                shopId: dt.shopId, idMatiere: m.id, sku: m.sku, nomMatiere: m.nom,
                idFournisseur: m.fournisseur, idUnite: m.idUnite,
                quantite: +c2.quantite, idLivraison: c2.livraison, motif: c2.motif,
                action: c2.action || 'REPLACEMENT',
                texte: (c2.texte !== undefined ? c2.texte : (dt.comment || '')) +
                  ((dt.rep || []).length ? '\n\nRepères posés au contrôle : '
                    + (dt.rep || []).map(r2 => (r2.n + '. ' + (r2.txt || ''))).join(' · ') : ''),
              }).then(r => {
                if (!r || r.ok === false) {
                  patch({ busy: false, err: (r && r.error) || 'refus du panel' });
                  this.notify((r && r.error) || 'Réclamation refusée'); return;
                }
                patch({ busy: false, fait: r.id || true, ouvert: false });
                this.D.recl = null;   // l'écran fournisseurs se relira
                this.notify('Réclamation déposée' + (r.id ? ' (#' + r.id + ')' : ''));
              });
            },
          };
        })(),
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
      // Le report n'est plus un geste : il se fait à l'enregistrement. Le
      // panneau annonce ce qui partira, plutôt que d'offrir un bouton de plus.
      reporte: rep.filter(r => String(r.txt || '').trim()).length,
      // Option « comparer » : la photo de référence en face — la chaîne
      // products/available → recipes/{id_recipe} (le même API que le
      // webshop) ; sans chemin, l'écran le dit.
      compare: !!dt.zCompare,
      compareGo: () => this.zPatch({ zCompare: !dt.zCompare }),
      photoRef: d.photoRef || null,
      produit: d.produit || '',
      refManque: !d.photoRef,
      refTxt: d.produitId
        ? 'Pas de photo.'
        : 'Cette tâche ne porte pas sur un produit précis — pas de visuel de référence.',
      refBesoin: 'La référence suit la chaîne du webshop : products/available (id → id_recipe) puis '
        + 'recipes/{id_recipe} (shop_photo_path, sinon main_photo_path, photo_1..3). Un maillon absent : pas de photo.',
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
  /**
   * Trois lectures d'un même portefeuille de projets.
   *
   * Le kanban dit OÙ en est chaque projet. La vue budgets dit ce qu'il coûte et
   * ce qu'il rapporte — la question de la direction. La fiche franchisé dit ce
   * que le projet demande et apporte À UNE BOUTIQUE, ce qui n'est pas la même
   * question et ne se lit pas dans un kanban.
   *
   * Les trois lisent la MÊME donnée : rien n'est recalculé d'un onglet à
   * l'autre, sinon deux chiffres finiraient par se contredire à l'écran.
   */
  valsProjetsVues(common, projEff){
    const S = this.state, D = this.D, M = this.M;
    const vue = ['budgets', 'franchise'].indexOf(S.pjVue) >= 0 ? S.pjVue : 'kanban';
    common.pjVue = vue;
    common.pjVueBtns = [['kanban', 'Kanban'], ['budgets', 'Budgets & ROI'], ['franchise', 'Vue franchisé']]
      .map(([v, nom]) => ({ nom, on: vue === v, go: () => this.setState({ pjVue: v }) }));
    if (vue === 'kanban') { common.pjBudgets = false; common.pjFranchise = false; common.pjPeriodes = false; return; }

    const nbMag = (D.stores || []).filter(s => s.status === 'Ouvert').length || 1;
    const som = (arr, k) => (arr || []).reduce((a, c) => a + (+c[k] || 0), 0);
    const mois = (a, b) => {
      if (!a || !b) { return 0; }
      const d1 = new Date(a), d2 = new Date(b);
      return Math.max(0, (d2.getFullYear() - d1.getFullYear()) * 12 + (d2.getMonth() - d1.getMonth()));
    };
    const aujourdhui = (M && M.TODAY) || new Date().toISOString().slice(0, 10);

    // Un projet, ses chiffres. Le prévu des postes de coût EST l'engagement ;
    // le réel est ce qui est sorti. On ne déduit pas l'engagé des tâches
    // livrées : une tâche livrée sans budget saisi vaudrait alors zéro.
    const lignes = projEff.map(p => {
      const vote = +p.budget || 0;
      const engage = som(p.couts, 'prevu');
      const conso = som(p.couts, 'reel');
      const est = p.valeurEst == null ? null : +p.valeurEst;
      const real = p.valeurReal == null ? null : +p.valeurReal;
      const roi = (real != null && conso > 0) ? real / conso : null;
      const roiEst = (est != null && (engage > 0 || vote > 0)) ? est / (engage || vote) : null;
      const ecoules = mois(p.debut, aujourdhui);
      // Le retour ne se calcule QUE sur du réalisé, et seulement si assez de
      // temps a passé : extrapoler un mois de valeur sur douze annoncerait un
      // retour qui n'a pas été observé.
      const retour = (real != null && real > 0 && conso > 0 && ecoules >= 1)
        ? Math.round(conso / (real / ecoules)) : null;
      return { id: p.id, nom: p.nom, famille: this.pFamille(p), statut: this.pStatut(p),
        leviers: p.leviers || [], kpis: p.kpis || [], valeurTxt: p.valeurTxt || '',
        debut: p.debut, fin: p.fin, jalons: p.jalons || [], taches: p.taches || [],
        vote, engage, conso, est, real, roi, roiEst, retour, ecoules, p };
    });

    if (vue === 'budgets') {
      const T = { vote: 0, engage: 0, conso: 0, real: 0 };
      lignes.forEach(l => { T.vote += l.vote; T.engage += l.engage; T.conso += l.conso; T.real += (l.real || 0); });

      // Ce que la marque a dans la caisse face à ce que les projets réclament.
      // Le fonds est tenu par le module marketing : on le lit, on n'en fait pas
      // une seconde comptabilité.
      if (!D.fonds && !this._foEnCours) {
        this._foEnCours = true;
        readOne('/fonds').then(f2 => { this._foEnCours = false;
          this.D.fonds = f2 || { erreurs: ['module injoignable'] }; this.setState({}); });
      }
      const F = D.fonds;
      const mv = this.fondsMouvements(F);
      const alim = mv.entrees, dep = mv.sorties;
      const solde = (F && F.ledger && F.ledger.closing_balance != null)
        ? +F.ledger.closing_balance : alim - dep;
      common.pjFonds = !F ? { chargement: true } : {
        chargement: false,
        erreur: (F.erreurs || []).length ? F.erreurs.join(' · ') : '',
        alim: this.fE(alim), dep: this.fE(dep), solde: this.fE(solde),
        engage: this.fE(T.engage),
        couvre: solde > 0 ? Math.max(0, Math.min(100, 100 * T.engage / solde)) : 0,
        // Le verdict tient en une phrase : le fonds suffit-il à ce qui est
        // engagé ? C'est la seule question que pose ce rapprochement.
        verdict: T.engage === 0 ? 'Aucun engagement de projet à financer pour l’instant.'
          : (solde <= 0 ? 'Le fonds n’a plus de solde : les projets engagés ne sont pas couverts.'
            : (T.engage <= solde ? 'Le solde du fonds couvre ce que les projets ont engagé.'
              : 'Les projets engagent ' + this.fE(T.engage - solde) + ' de plus que le solde du fonds.')),
        verdictCol: (T.engage === 0 || (solde > 0 && T.engage <= solde)) ? '#2d7a3e' : 'var(--color-primary)',
        // Le rattachement projet → mouvement du fonds n'existe pas : le
        // rapprochement est GLOBAL, et le dire évite de le prendre pour un
        // suivi ligne à ligne.
        note: 'Rapprochement global : aucun champ ne rattache encore un projet à un mouvement du fonds. '
          + 'Les écritures se font dans le module marketing.',
        ouvrir: () => this.setState({ screen: 'fonds' }),
      };

      // --- les sorties, mois par mois : la caisse face au budget.
      //
      // Trois séries sur le même calendrier : ce qui SORT réellement du fonds
      // (le grand livre du module marketing), et ce que les projets font
      // sortir — prévu puis réel.
      //
      // Seul ce qui porte une DATE peut se placer sur un mois : le budget des
      // tâches, à l'échéance pour le prévisionnel, à la date de réalisation
      // pour le réel. Les postes de coût du projet n'ont pas de date en base :
      // ils comptent dans les totaux, jamais sur ce calendrier — les étaler
      // sur la durée du projet inventerait un échéancier que personne n'a
      // posé.
      const parMois = new Map();
      const clef = d => (d || '').slice(0, 7);
      const seau = m => { if (!parMois.has(m)) { parMois.set(m, { m, fonds: 0, prev: 0, reel: 0, nbP: 0, nbR: 0 }); }
        return parMois.get(m); };
      mv.parPeriode.forEach(p => { if (p.cle) { seau(clef(p.cle)).fonds += p.sorties; } });
      let datees = 0, sansDate = 0;
      lignes.forEach(l => (l.taches || []).forEach(t => {
        const b = +t.budget || 0;
        if (!b) { return; }
        if (!t.due && !t.done) { sansDate += b; return; }
        datees += b;
        if (t.due) { const s = seau(clef(t.due)); s.prev += b; s.nbP++; }
        if (t.done) { const s = seau(clef(t.done)); s.reel += b; s.nbR++; }
      }));
      const moisTri = Array.from(parMois.values()).filter(x => x.m).sort((a, b) => a.m < b.m ? -1 : 1);
      const maxi = moisTri.reduce((a, x) => Math.max(a, x.fonds, x.prev, x.reel), 0);
      const totF = moisTri.reduce((a, x) => a + x.fonds, 0);
      const totP = moisTri.reduce((a, x) => a + x.prev, 0);
      const totR = moisTri.reduce((a, x) => a + x.reel, 0);
      common.pjPeriodes = {
        vide: !moisTri.length,
        lignes: moisTri.map(x => ({
          mois: this.fPeriode(x.m + '-01'),
          fonds: x.fonds ? this.fE(x.fonds) : '—',
          prev: x.prev ? this.fE(x.prev) : '—',
          reel: x.reel ? this.fE(x.reel) : '—',
          // L'écart ne se lit qu'une fois le mois joué : avant, un réel à zéro
          // n'est pas un écart, c'est un mois qui n'a pas encore eu lieu.
          ecart: (x.nbR ? this.fE(x.reel - x.prev) : '—'),
          ecartCol: !x.nbR ? 'var(--color-text-muted)'
            : (x.reel > x.prev ? 'var(--color-primary)' : '#2d7a3e'),
          detail: [x.nbP ? x.nbP + ' tâche(s) à échéance' : '', x.nbR ? x.nbR + ' réalisée(s)' : '']
            .filter(Boolean).join(' · '),
          bFonds: maxi > 0 ? 100 * x.fonds / maxi : 0,
          bPrev: maxi > 0 ? 100 * x.prev / maxi : 0,
          bReel: maxi > 0 ? 100 * x.reel / maxi : 0,
        })),
        totFonds: totF ? this.fE(totF) : '—',
        totPrev: totP ? this.fE(totP) : '—',
        totReel: totR ? this.fE(totR) : '—',
        // Ce que le calendrier NE PORTE PAS, dit chiffre en main. Un poste de
        // coût à zéro n'est pas un poste absent : la phrase distingue les deux,
        // sinon on chercherait une saisie qui existe déjà.
        horsCal: (T.engage > 0 || T.conso > 0)
          ? 'Hors calendrier : ' + this.fE(T.engage) + ' engagés et ' + this.fE(T.conso)
            + ' consommés sur les postes de coût, qui ne portent pas de date.'
          : (lignes.some(l => ((l.p.couts || []).length))
            ? 'Les postes de coût des projets sont tous à zéro : rien à placer sur le calendrier.'
            : 'Aucun poste de coût saisi sur les projets.'),
        sansDate: sansDate > 0 ? this.fE(sansDate) + ' de budget de tâches sans échéance ni date de réalisation.' : '',
        vidTaches: datees === 0,
        note: 'Aucun champ ne rattache un mouvement du fonds à un projet : les deux séries se lisent '
          + 'côte à côte, mois par mois — elles ne se soldent pas l’une l’autre.',
      };

      const pct = (a, b) => b > 0 ? Math.max(0, Math.min(100, 100 * a / b)) : 0;
      common.pjBudgets = {
        tuiles: [
          { k: 'Budget voté', v: this.fE(T.vote), aide: lignes.length + ' projet(s)' },
          { k: 'Engagé', v: this.fE(T.engage), aide: T.vote > 0 ? Math.round(pct(T.engage, T.vote)) + ' % du voté' : 'aucun budget voté',
            barre: pct(T.engage, T.vote), col: 'var(--color-primary)' },
          { k: 'Consommé', v: this.fE(T.conso), aide: T.vote > 0 ? 'reste ' + this.fE(Math.max(0, T.vote - T.conso)) : '—',
            barre: pct(T.conso, T.vote), col: '#c9a06a' },
          { k: 'Valeur réalisée', v: T.real > 0 ? this.fE(T.real) : '—',
            aide: (T.real > 0 && T.conso > 0) ? '× ' + (T.real / T.conso).toFixed(1).replace('.', ',') + ' le consommé'
              : 'aucune valeur réalisée saisie' },
        ],
        lignes: lignes.map(l => ({
          nom: l.nom, famille: l.famille, statut: l.statut,
          leviers: this.pjLeviers(l.leviers),
          vote: this.fE(l.vote), engage: this.fE(l.engage), conso: this.fE(l.conso),
          est: l.est == null ? '—' : this.fE(l.est),
          real: l.real == null ? '—' : this.fE(l.real),
          roi: l.roi != null ? '×' + l.roi.toFixed(1).replace('.', ',')
            : (l.roiEst != null ? '×' + l.roiEst.toFixed(1).replace('.', ',') : '—'),
          roiEstime: l.roi == null && l.roiEst != null,
          roiCol: l.roi == null ? 'var(--color-text-muted)'
            : (l.roi >= 1 ? '#2d7a3e' : 'var(--color-primary)'),
          retour: l.retour != null ? l.retour + ' mois' : '—',
          ouvrir: () => this.setState({ pjVue: 'franchise', pjSel: l.id }),
        })),
        vide: !lignes.length,
        // Sans coûts saisis, la colonne ne peut rien dire : mieux vaut
        // l'annoncer que laisser lire des zéros comme une gratuité.
        note: lignes.every(l => l.engage === 0 && l.conso === 0)
          ? 'Aucun coût saisi sur les projets — « engagé » et « consommé » restent à zéro tant que les postes de coût ne sont pas remplis dans la fiche projet.'
          : '',
      };
      common.pjFranchise = false;
      return;
    }

    // --- vue franchisé : un projet à la fois, celui qu'on regarde.
    const sel = lignes.find(l => l.id === S.pjSel) || lignes[0] || null;
    common.pjBudgets = false; common.pjPeriodes = false;
    if (!sel) { common.pjFranchise = { vide: true }; return; }
    const jal = (sel.jalons || []).map((j, i) => ({ nom: j.nom, date: this.fD(j.cible),
      fait: !!j.reel, courant: !j.reel, i }));
    const faits = jal.filter(j => j.fait).length;
    const etapes = jal.concat((sel.taches || []).map(t => ({ nom: t.nom, date: this.fD(t.due),
      fait: !!t.done, tache: true })))
      .sort((a, b) => (a.date || '').split('/').reverse().join('') < (b.date || '').split('/').reverse().join('') ? -1 : 1);

    common.pjFranchise = {
      vide: false,
      choix: lignes.map(l => ({ id: l.id, nom: l.nom, on: l.id === sel.id,
        go: () => this.setState({ pjSel: l.id }) })),
      nom: sel.nom, famille: sel.famille, statut: sel.statut,
      periode: (sel.debut ? this.fD(sel.debut) : '—') + ' → ' + (sel.fin ? this.fD(sel.fin) : '—'),
      leviers: this.pjLeviers(sel.leviers),
      // « Ce qui est recherché » : les KPI visés, tels qu'ils ont été choisis.
      // Aucune cible chiffrée n'existe en base — l'inventer donnerait un
      // objectif que personne n'a fixé.
      kpis: sel.kpis,
      kpisVide: !(sel.kpis || []).length,
      pourquoi: sel.valeurTxt || '',
      tuiles: [
        { k: 'Ce que ça coûte à une boutique', v: sel.vote > 0 ? this.fE(sel.vote / nbMag) : '—',
          aide: sel.vote > 0 ? this.fE(sel.vote) + ' réseau ÷ ' + nbMag + ' boutiques' : 'budget non voté' },
        { k: 'Ce que ça rapporte', v: sel.est != null ? this.fE(sel.est / nbMag) : '—',
          aide: sel.est != null ? 'estimation réseau répartie' : 'valeur estimée non saisie',
          vert: sel.est != null },
        { k: 'Retour', v: sel.retour != null ? sel.retour + ' mois' : '—',
          aide: sel.retour != null ? 'sur la valeur réellement constatée' : 'pas encore de valeur réalisée' },
        { k: 'Où en est le projet', v: jal.length ? faits + ' / ' + jal.length : '—',
          aide: jal.length ? 'jalons livrés' : 'aucun jalon posé' },
      ],
      etapes: etapes.slice(0, 8).map(e => ({ nom: e.nom, date: e.date, fait: e.fait, tache: !!e.tache,
        col: e.fait ? '#2d7a3e' : 'var(--color-primary)' })),
      etapesVide: !etapes.length,
      // Ce que la fiche ne peut pas encore porter, et pourquoi.
      manque: [
        { champ: 'Ce que ça rapporte À MA boutique',
          source: 'API panel — le volume vendu par référence ET par magasin n’est pas exposé '
            + '(sold_qty est une valeur réseau, identique dans les quatre boutiques). '
            + 'La valeur est donc répartie à parts égales, pas mesurée.' },
        { champ: 'Les références portées par le projet',
          source: 'Aucun lien projet → référence n’existe dans le cockpit : il reste à créer '
            + 'pour afficher ici le minimum à tenir et l’emplacement au comptoir.' },
      ],
    };
  }
  /** Les leviers d'un projet, avec la couleur du référentiel. */
  pjLeviers(slugs){
    const ref = (this.M && this.M.LEVIERS) || [];
    return (slugs || []).map(s => {
      const l = ref.find(r => r.slug === s) || {};
      return { slug: s, nom: l.nom || s, couleur: l.color || '#666666', desc: l.desc || '' };
    });
  }
  valsProjets(common, projEff){
    const S = this.state, D = this.D, M = this.M;
    this.valsProjetsVues(common, projEff);
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
        'au moins un jalon dépassé', 'projets', true)
    ];

    // Le compte connecté EST un consultant : ses tâches sont les siennes, et
    // c'est ce qu'il vient voir. L'identifiant vient du panel (la position du
    // compte qui interroge), pas d'un rapprochement de noms — « Sam V. » et
    // « Sam Verheyden » ne se ressemblent que pour un humain.
    const moiId = ((this.meta || {}).utilisateur || {}).consultantId || null;
    const moi = moiId ? (D.consultants || []).find(c => String(c.id) === String(moiId)) : null;
    common.tkMoi = moi ? moi.nom : '';
    common.tkMoiVal = moi ? 'c:' + moi.id : '';
    common.tkMoiOn = !!moi && S.tkWho === 'c:' + moi.id;
    common.tkMoiGo = moi ? () => this.setState({ tkWho: common.tkMoiOn ? 'all' : 'c:' + moi.id }) : null;
    common.tkMoiN = moi ? flat.filter(x => (x.t.owner.t + ':' + x.t.owner.id) === 'c:' + moi.id && !x.t.done).length : 0;
    common.tkWho = S.tkWho;
    common.setTkWho = e => this.setState({ tkWho: e.target.value });
    common.tkPeople = [{ val: 'all', nom: 'Tous les intervenants' }]
      .concat(D.consultants.map(c => ({ val: 'c:' + c.id,
        nom: c.nom + ' — ' + c.role + (String(c.id) === String(moiId) ? ' (moi)' : '') })))
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
          { k: 'Projet', v: x.p.nom }, { k: 'Magasin', v: mag || 'Réseau — aucun magasin' }]
          .concat(x.t.panelNote ? [{ k: 'Panel', v: 'Note #' + x.t.panelNote + ' déposée sur le magasin — visible par le consultant' }] : []),
        // La photo qui a motivé la demande, avec ses repères. Chargée seulement
        // quand la ligne est dépliée : une liste de trente tâches ne doit pas
        // déclencher trente lectures d'API.
        photo: (() => {
          const src = x.t.source;
          if (!src) { return null; }
          const d2 = ouvert ? this.srcCharge(src) : null;
          const jf = s2 => String(s2 || '').split('-').reverse().join('/');
          if (!ouvert) { return { attente: true, titre: 'Photo de contrôle du ' + jf(src.date) }; }
          if (!d2) { return { chargement: true, titre: 'Photo de contrôle du ' + jf(src.date) }; }
          if (d2.erreur || !d2.photo) {
            return { vide: true, titre: 'Photo de contrôle du ' + jf(src.date),
              motif: d2.erreur ? 'lecture impossible' : 'aucune photo sur cette tâche de contrôle' };
          }
          const NIV = { 1: '#8D1D2C', 2: '#C17A2A', 3: '#C9A227', 4: '#6b8f4e', 5: '#2d7a3e' };
          // `reperes` est un OBJET {liste, maj, auteur}, pas un tableau : appeler
          // `.map` dessus jetait dans le calcul des valeurs, et l'écran restait
          // figé sur « lecture de la photo… » sans rien dire.
          const brut = (d2.reperes && Array.isArray(d2.reperes.liste)) ? d2.reperes.liste
            : (Array.isArray(d2.reperes) ? d2.reperes : []);
          const rep = brut.map(r => ({
            n: r.n, txt: r.txt || '',
            coul: NIV[r.niveau] || '#8D1D2C',
            boxSt: 'position:absolute;left:' + (r.x * 100).toFixed(2) + '%;top:' + (r.y * 100).toFixed(2)
              + '%;width:' + (r.l * 100).toFixed(2) + '%;height:' + (r.h * 100).toFixed(2)
              + '%;border:2px solid ' + (NIV[r.niveau] || '#8D1D2C') + ';border-radius:4px;box-shadow:0 0 0 1px rgba(0,0,0,.25)',
            numSt: 'position:absolute;left:-1px;top:-1px;transform:translate(-40%,-40%);width:17px;height:17px;border-radius:50%;'
              + 'display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff;background:'
              + (NIV[r.niveau] || '#8D1D2C') }));
          const cle = src.shopId + '/' + src.taskId + '/' + src.date;
          const perdue = !!(this.D.srcPhotoErr || {})[cle];
          if (!perdue) { this.srcSurveille(cle); }
          return { titre: 'Photo de contrôle du ' + jf(src.date),
            url: d2.photo, cle, imgErr: perdue,
            imgErrTxt: 'Le lien signé de la photo a expiré ou le stockage du panel est injoignable. Rouvrez la tâche dans Contrôle des tâches pour en obtenir un neuf.',
            tache: d2.tache || ('Tâche #' + src.taskId),
            // Les cadres disparaissent avec la photo, PAS les remarques : ce que
            // le consultant doit reprendre se lit même sans le cliché.
            reperes: rep,
            nRep: rep.length,
            legende: rep.length
              ? rep.length + ' repère' + (rep.length > 1 ? 's' : '') + ' posé' + (rep.length > 1 ? 's' : '') + ' au contrôle'
                + ((d2.reperes || {}).auteur ? ' par ' + d2.reperes.auteur : '')
              : 'aucun repère posé sur cette photo',
            avis: d2.avis && d2.avis.note != null
              ? ('notée ' + d2.avis.note + '/5' + (d2.avis.comment ? ' — ' + d2.avis.comment : ''))
              : '' };
        })(),
        // Supprimer une tâche : une note envoyée par erreur, un essai, un
        // doublon. Le journal en garde la trace — c'est lui la mémoire.
        supprimer: () => {
          if (!window.confirm('Supprimer la tâche « ' + x.t.nom + ' » ?\n\nSes signalements éventuels partent avec elle. Le journal en garde la trace.')) { return; }
          this.api('DELETE', '/projects/' + x.p.id + '/tasks/' + x.t.id, {}).then(r => {
            if (!r || r.ok === false) { this.notify((r && r.error) || 'Suppression refusée'); return; }
            readOne('/projects').then(pj => { if (pj) { this.D.projects = pj; } this.setState({ tkSuppr: x.t.id }); });
            this.notify('Tâche supprimée' + (r.signalements ? ' — ' + r.signalements + ' signalement(s) fermé(s)' : ''));
          });
        },
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
  /** « Dernières générations » : une liste DÉROULANTE, pas une pile de lignes.
   *  Douze runs prenaient une demi-page pour un seul qu'on ouvre. On choisit
   *  dans la liste, et le détail du choix — état, résumé, lien — s'affiche à
   *  côté. Le plus récent est proposé d'office ; une génération nouvelle passe
   *  donc devant sans qu'on ait à la chercher. */
  valsRapportsRuns(runs, stRun, motRun, dateRun){
    const S = this.state;
    const liste = runs.map(u => ({
      id: String(u.runId), le: dateRun(u.le), rapport: u.rapport,
      statut: motRun(u.statut), st: stRun(u.statut),
      resume: u.resume || '', url: API_BASE + '/rapports/run/' + u.runId }));
    // Une sélection qui n'existe plus — le run a quitté les douze derniers —
    // retombe sur le plus récent plutôt que de vider le bloc.
    const choisi = liste.filter(u => u.id === S.rapRunSel)[0] || liste[0] || null;
    return {
      runs: liste,
      runOptions: liste.map(u => ({ v: u.id, sel: !!choisi && u.id === choisi.id,
        label: u.le + ' · ' + u.rapport + ' · ' + u.statut })),
      runSel: choisi,
      setRun: e => this.setState({ rapRunSel: e.target.value }),
    };
  }

  valsReporting(common, navDef, titles){
    const S = this.state, D = this.D, M = this.M;
    // --- générateur de rapports RÉEL (table ceo_rapport, HTML par levier).
    // L'ancienne liste de démonstration a cédé la place : ces rapports-ci se
    // génèrent, se relisent (HTML du run) et s'envoient vraiment.
    const rz = S.rapGen;
    if (!rz) { setTimeout(() => this.rapCharge(), 0); }
    const rd = rz && rz.d;
    const JSEM = ['', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
    const freqLabel = r => r.frequence === 'quotidien' ? 'Quotidien · ' + r.heure + ' h'
      : r.frequence === 'hebdo' ? 'Hebdo · ' + (JSEM[r.jour] || 'lundi') + ' ' + r.heure + ' h'
      : 'Mensuel · le ' + r.jour + ' à ' + r.heure + ' h';
    // Le même mot partout : la ligne du rapport disait « envoye » là où la
    // liste des générations dit « envoyé ».
    const motRun = s3 => ({ envoye: 'envoyé', vide: 'sans matière', erreur: 'erreur' })[s3] || (s3 ? 'généré' : '');
    // « 2026-08-21 17:28 » → « 21/08 à 17:28 », l'écriture du reste du cockpit.
    const dateRun = t => t ? (this.fD(String(t).slice(0, 10)) + ' à ' + String(t).slice(11, 16)) : '';
    const stRun = s3 => 'display:inline-block;padding:1px 8px;border-radius:999px;font-size:10.5px;font-weight:600;'
      + (s3 === 'envoye' ? 'background:rgba(45,122,62,0.10);color:#2d7a3e'
        : s3 === 'vide' ? 'background:var(--color-background-secondary);color:var(--color-text-muted)'
        : s3 === 'erreur' ? 'background:rgba(141,29,44,0.12);color:#8D1D2C'
        : 'background:#FBEFE0;color:#8a5a13');
    common.rapGen = {
      chargement: !rz || rz.chargement,
      cronUrl: (rd && rd.cronUrl) || '',
      indispo: rz && !rz.chargement && !rd ? 'La lecture de /rapports a échoué — voir Diagnostic API.' : '',
      lignes: ((rd && rd.rapports) || []).map(r => {
        const defs = (rd && rd.blocs) || {}, levs = (rd && rd.leviers) || {};
        const destTxt = (S.rapDestTxt && S.rapDestTxt[r.id] != null) ? S.rapDestTxt[r.id] : (r.destinataires || []).join(', ');
        const busy = !!(S.rapBusy && S.rapBusy[r.id]);
        const setBusy = v => this.setState(s2 => ({ rapBusy: Object.assign({}, s2.rapBusy, { [r.id]: v }) }));
        return {
          nom: r.nom, poste: r.poste,
          freq: freqLabel(r) + (r.parMagasin ? ' · un chapitre par magasin' : '')
            + (r.envoiMode === 'par-magasin' ? ' · un email par magasin' : ''),
          actifTxt: r.actif ? 'Actif' : 'Inactif',
          actifSt: 'display:inline-block;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:500;cursor:pointer;'
            + (r.actif ? 'background:rgba(45,122,62,0.10);color:#2d7a3e' : 'background:var(--color-background-secondary);color:var(--color-text-muted)'),
          toggleActif: () => this.api('PUT', '/rapports/' + r.id, { actif: !r.actif }).then(() => this.rapCharge(true)),
          dernier: r.dernier ? ('Dernier : ' + dateRun(r.dernier.le)) : 'Jamais généré',
          dernierStatut: r.dernier ? motRun(r.dernier.statut) : '',
          dernierSt: stRun(r.dernier ? r.dernier.statut : ''),
          resume: r.dernier ? (r.dernier.resume || '') : '',
          ouvrirUrl: r.dernier ? (API_BASE + '/rapports/run/' + r.dernier.runId) : '',
          genTxt: busy ? 'Génération…' : 'Générer',
          gen: () => { if (busy) return; setBusy(true);
            this.api('POST', '/rapports/' + r.id + '/generer', {}).then(g => { setBusy(false);
              this.notify(g && g.resume ? 'Généré — ' + g.resume : 'Génération échouée — voir Diagnostic API');
              this.rapCharge(true);
              if (g && g.runId) { try { window.open(API_BASE + '/rapports/run/' + g.runId, '_blank'); } catch (e2) {} } }); },
          env: () => { if (busy) return; setBusy(true);
            this.api('POST', '/rapports/' + r.id + '/envoyer', {}).then(g => { setBusy(false);
              if (g && g.mode === 'par-magasin') {
                const ms = g.magasins || [];
                this.notify('Distribué — ' + ms.filter(m5 => m5.statut === 'envoye').length + ' magasin(s) servi(s), '
                  + ms.filter(m5 => m5.statut === 'vide').length + ' sans matière, '
                  + ms.filter(m5 => m5.statut === 'sans-adresse').length + ' sans adresse'
                  + (g.reseau && g.reseau.ok ? ' · version complète envoyée' : ''));
              } else {
                this.notify(g && g.ok ? 'Envoyé à ' + (g.envoyes || []).join(', ')
                  : 'Non envoyé — ' + ((g && (g.error || g.note)) || 'échec'));
              }
              this.rapCharge(true); }); },
          blocs: (r.blocs || []).map(sl => { const d2 = defs[sl] || { levier: 'transverse', nom: sl };
            const lv = levs[d2.levier] || { couleur: '#999999' };
            return { nom: d2.nom, c: lv.couleur }; }),
          modifier: () => {
            const bl = {}; (r.blocs || []).forEach(sl => { bl[sl] = true; });
            const mg2 = {}; (r.magasins || []).forEach(n3 => { mg2[n3] = true; });
            const dw = {}; const dm = {};
            if (r.jours) {
              ((r.jours.dows) || []).forEach(d7 => { dw[d7] = true; });
              ((r.jours.doms) || []).forEach(d7 => { dm[d7] = true; });
            } else if (r.frequence === 'quotidien') { [1, 2, 3, 4, 5, 6, 7].forEach(d7 => { dw[d7] = true; }); }
            else if (r.frequence === 'mensuel') { dm[r.jour || 1] = true; }
            else { dw[r.jour || 1] = true; }
            const dpm0 = {};
            Object.entries(r.destParMagasin || {}).forEach(([nomM, em]) => { dpm0[nomM] = (em || []).join(', '); });
            this.setState({ rapComposeOn: true, rapEditId: r.id, rapCompo: {
              blocs: bl, modes: r.modes || {}, mags: mg2, nom: r.nom, poste: r.poste || '',
              heure: String(r.heure), dows: dw, doms: dm, dest: (r.destinataires || []).join(', '),
              envoiMode: r.envoiMode || 'groupe', dpm: dpm0, comparaison: r.comparaison || 'A-1',
              periode: r.periode || '', perDu: r.periodeDu || '', perAu: r.periodeAu || '',
              ordre: r.ordre || [], sauts: (r.sauts || []).reduce((o, sl) => { o[sl] = true; return o; }, {}) } });
          },
          suppr: () => {
            if (!window.confirm('Supprimer le rapport « ' + r.nom + ' » ? Ses générations passées restent lisibles dans l’historique.')) { return; }
            this.api('DELETE', '/rapports/' + r.id, {}).then(() => { this.notify('Rapport supprimé'); this.rapCharge(true); });
          },
          destTxt,
          setDest: e => { const v = e.target.value;
            this.setState(s2 => ({ rapDestTxt: Object.assign({}, s2.rapDestTxt, { [r.id]: v }) }));
            // Une adresse à demi tapée n'est pas une adresse : on n'écrit que
            // ce qui est valide, et le reste attend la suite de la frappe.
            this.autoEnreg('dest' + r.id, () => {
              const em = v.split(/[,;]/).map(s4 => s4.trim()).filter(Boolean);
              if (em.some(a4 => !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(a4))) { return false; }
              return this.api('PUT', '/rapports/' + r.id, { destinataires: em })
                .then(g => !(g && g.ok === false));
            }); },
          destAuto: this.autoTxt('dest' + r.id),
          saveDest: () => this.api('PUT', '/rapports/' + r.id,
            { destinataires: destTxt.split(/[,;]/).map(s4 => s4.trim()).filter(Boolean) })
            .then(() => { this.notify('Destinataires enregistrés'); this.rapCharge(true); }),
        };
      }),
      ...this.valsRapportsRuns((rd && rd.runs) || [], stRun, motRun, dateRun),
    };

    // --- COMPOSITEUR : cocher les KPI, le périmètre, la période, puis générer
    // à la demande, envoyer, ou enregistrer comme rapport récurrent (poste +
    // jours cochés : semaine et calendrier du mois).
    const rc = S.rapCompo || {};
    const rcSet = patch => this.setState(s2 => ({ rapCompo: Object.assign({}, s2.rapCompo, patch) }));
    common.rapComposeOn = !!S.rapComposeOn;
    common.rapComposeOuvrir = () => this.setState({ rapComposeOn: true, rapEditId: null, rapCompo: {} });
    common.rapComposeFermer = () => this.setState({ rapComposeOn: false, rapEditId: null });
    const rcBlocs = rc.blocs || {};
    const rcModes = rc.modes || {};
    const rcMags = rc.mags || {};
    const slugsOn = Object.keys(rcBlocs).filter(sl => rcBlocs[sl]);
    // Le rang PAR DÉFAUT d'un bloc : son levier, et les blocs de tâches en
    // fin de levier. C'est l'ordre qu'applique le générateur quand personne
    // n'a rien demandé — l'écran doit montrer le même.
    const rangBloc = sl => {
      const d5 = ((rd && rd.blocs) || {})[sl] || {};
      const i4 = Object.keys((rd && rd.leviers) || {}).indexOf(d5.levier);
      return (i4 < 0 ? 99 : i4) * 10 + (d5.famille === 'taches' ? 1 : 0);
    };
    // L'ordre VOULU d'abord (celui de la liste de mise en page), puis les
    // blocs cochés depuis, rangés par levier. Un bloc décoché disparaît de
    // l'ordre sans le trouer.
    const planSlugs = () => {
      const voulu = (rc.ordre || []).filter(sl => rcBlocs[sl]);
      const reste = slugsOn.filter(sl => voulu.indexOf(sl) < 0)
        .slice().sort((a3, b3) => rangBloc(a3) - rangBloc(b3));
      return voulu.concat(reste);
    };
    const magsOn = Object.keys(rcMags).filter(n2 => rcMags[n2]);
    // La fenêtre de données n'est plus choisie à l'écran : elle suit la
    // cadence (quotidien → la veille, hebdo → semaine passée, mensuel → mois
    // passé). L'aperçu sans planification lit la semaine passée.
    // Le repère de comparaison des KPI chiffrés (passage clients, ticket
    // moyen) : A-1 par défaut — le seul qui neutralise la saison.
    const rcComp = rc.comparaison || 'A-1';
    // Le rapport auquel la composition se rattache : celui qu'on édite, sinon
    // celui qu'on a chargé comme modèle. L'historique range alors la
    // génération sous son nom plutôt que sous « Aperçu à la demande ».
    // La fenêtre OBSERVÉE : celle sur laquelle les seuils se calculent. Vide =
    // « selon la cadence », le comportement d'avant — un rapport déjà réglé ne
    // change pas de fenêtre parce qu'on a ajouté un bouton.
    const rcPer = rc.periode == null ? '' : String(rc.periode);
    const rcPerDu = rc.perDu || '';
    const rcPerAu = rc.perAu || '';
    const composition = () => ({ blocs: planSlugs(), modes: rcModes, magasins: magsOn, comparaison: rcComp,
      periode: rcPer, du: rcPerDu, au: rcPerAu,
      ordre: planSlugs(), sauts: planSlugs().filter(sl => (rc.sauts || {})[sl]),
      rapportId: S.rapEditId || rc.modeleId || 0 });
    const ordreLev = Object.keys((rd && rd.leviers) || {});
    const groupes = [];
    ordreLev.forEach(lev => {
      const items = Object.entries((rd && rd.blocs) || {}).filter(([, d4]) => d4.levier === lev)
        .map(([sl, d4]) => ({ slug: sl, nom: d4.nom, on: !!rcBlocs[sl],
          toggle: () => rcSet({ blocs: Object.assign({}, rcBlocs, { [sl]: !rcBlocs[sl] }) }),
          mode: rcModes[sl] || 'complet',
          setModeComplet: () => rcSet({ modes: Object.assign({}, rcModes, { [sl]: 'complet' }) }),
          setModeDep: () => rcSet({ modes: Object.assign({}, rcModes, { [sl]: 'depassements' }) }) }));
      if (items.length) { const lv = (rd.leviers || {})[lev] || {}; groupes.push({ nom: lv.nom || lev, couleur: lv.couleur || '#999', items }); }
    });
    const JSEM2 = ['', 'LUN', 'MAR', 'MER', 'JEU', 'VEN', 'SAM', 'DIM'];
    const dows = rc.dows || {};
    const doms = rc.doms || {};
    // La cadence déduite des jours cochés — la même règle qu'à l'enregistrement.
    const nbDows = Object.keys(dows).filter(d5 => dows[d5]).length;
    const nbDoms = Object.keys(doms).filter(d5 => doms[d5]).length;
    const freqCompo = (nbDoms && !nbDows) ? 'mensuel' : (nbDows >= 7 ? 'quotidien' : 'hebdo');
    // Les fenêtres résolues viennent du SERVEUR : les recalculer ici ferait
    // deux vérités pour une même question, et c'est l'écran qui aurait tort.
    const fenS = (rd && rd.fenetres) || {};
    const fenCle = rcPer === '' ? 'cadence:' + freqCompo : rcPer;
    const fenSel = fenS[fenCle] || null;
    const jjmm = d5 => String(d5 || '').split('-').reverse().join('/');
    const nJours = (a3, b3) => (!a3 || !b3) ? null
      : Math.round((new Date(b3) - new Date(a3)) / 86400000) + 1;
    let fenTxt;
    if (rcPer === 'libre') {
      const n5 = nJours(rcPerDu, rcPerAu);
      fenTxt = (rcPerDu && rcPerAu && n5 > 0)
        ? 'du ' + jjmm(rcPerDu) + ' au ' + jjmm(rcPerAu) + ' (' + n5 + ' jour' + (n5 > 1 ? 's' : '') + ')'
          + ' · comparée à la même durée, ' + rcComp
        : 'choisissez les deux dates';
    } else if (fenSel) {
      const c5 = (fenSel.cmp || {})[rcComp];
      fenTxt = 'du ' + jjmm(fenSel.du) + ' au ' + jjmm(fenSel.au)
        + ' (' + fenSel.jours + ' jour' + (fenSel.jours > 1 ? 's' : '') + ')'
        + (c5 ? ' · comparée au ' + jjmm(c5.du) + ' → ' + jjmm(c5.au) + ' (' + rcComp + ')' : '')
        + (rcPer === '' ? ' · cadence ' + freqCompo : '');
    } else { fenTxt = 'fenêtre indisponible'; }
    common.rapCompo = {
      groupes,
      periode: rcPer,
      periodes: Object.entries((rd && rd.periodes) || { '': 'selon la cadence d’envoi' })
        .map(([code, aide]) => ({ code, aide,
          nom: { '': 'Cadence', 'hier': 'Hier', 'semaine-passee': 'Semaine passée',
                 'mois-en-cours': 'Mois en cours', 'mois-passe': 'Mois passé', 'libre': 'Libre' }[code] || code,
          on: code === rcPer, choisir: () => rcSet({ periode: code }) })),
      perLibre: rcPer === 'libre',
      perDu: { val: rcPerDu, set: e => rcSet({ perDu: e.target.value }) },
      perAu: { val: rcPerAu, set: e => rcSet({ perAu: e.target.value }) },
      fenetre: fenTxt,
      fenetreAide: (((rd && rd.periodes) || {})[rcPer]) || '',
      recapMags: (magsOn.length === 0 ? 'tout le réseau' : magsOn.length + ' magasin' + (magsOn.length > 1 ? 's' : ''))
        + ' · ' + slugsOn.length + ' bloc' + (slugsOn.length > 1 ? 's' : '')
        + (Object.values(rcModes).filter(m5 => m5 === 'complet').length
          ? ', dont ' + Object.values(rcModes).filter(m5 => m5 === 'complet').length + ' en « complet »' : ''),
      comparaison: rcComp,
      comparaisons: Object.entries((rd && rd.comparaisons) || { 'A-1': 'même période l’an dernier' })
        .map(([code, aide]) => ({ code, aide, on: code === rcComp, choisir: () => rcSet({ comparaison: code }) })),
      modeles: [{ id: 0, nom: '— Vide —' }].concat(((rd && rd.rapports) || []).map(r => ({ id: r.id, nom: r.nom }))),
      chargerModele: e => {
        const r = ((rd && rd.rapports) || []).find(x => String(x.id) === String(e.target.value));
        if (!r) { rcSet({ blocs: {}, modes: {}, modeleId: 0 }); return; }
        const bl = {}; (r.blocs || []).forEach(sl => { bl[sl] = true; });
        rcSet({ blocs: bl, modes: r.modes || {}, nom: '', poste: r.poste || '', comparaison: r.comparaison || 'A-1',
          periode: r.periode || '', perDu: r.periodeDu || '', perAu: r.periodeAu || '',
          modeleId: r.id, ordre: r.ordre || [],
          sauts: (r.sauts || []).reduce((o, sl) => { o[sl] = true; return o; }, {}) });
        this.notify('Modèle « ' + r.nom + ' » chargé — ajustez puis générez');
      },
      magasins: this.open().map(st2 => ({ nom: st2.nom, on: !!rcMags[st2.nom],
        toggle: () => rcSet({ mags: Object.assign({}, rcMags, { [st2.nom]: !rcMags[st2.nom] }) }) })),
      tous: magsOn.length === 0,
      toutLeReseau: () => rcSet({ mags: {} }),
      recap: slugsOn.length + ' KPI · ' + (magsOn.length === 0 ? 'tout le réseau' : magsOn.length + ' magasin(s)'),
      nom: rc.nom || '', setNom: e => rcSet({ nom: e.target.value }),
      poste: rc.poste || '', setPoste: e => rcSet({ poste: e.target.value }),
      postes: (rd && rd.postes) || [],
      heure: rc.heure || '7', setHeure: e => rcSet({ heure: e.target.value }),
      dest: rc.dest || '', setDest: e => rcSet({ dest: e.target.value }),
      envoiMode: rc.envoiMode || 'groupe',
      envoiGroupe: () => rcSet({ envoiMode: 'groupe' }),
      envoiParMagasin: () => rcSet({ envoiMode: 'par-magasin' }),
      carnet: (magsOn.length ? magsOn : this.open().map(st3 => st3.nom)).map(nomM => ({
        nom: nomM, val: (rc.dpm || {})[nomM] || '',
        set: e => rcSet({ dpm: Object.assign({}, rc.dpm, { [nomM]: e.target.value }) }) })),
      annuaire: ((rd && rd.annuaire) || []).map(a2 => {
        const dests = String(rc.dest || '').split(/[,;]/).map(s5 => s5.trim()).filter(Boolean);
        const on = dests.indexOf(a2.email) >= 0;
        return { nom: a2.nom, poste: a2.poste, email: a2.email, on,
          toggle: () => rcSet({ dest: (on ? dests.filter(d7 => d7 !== a2.email) : dests.concat([a2.email])).join(', ') }) };
      }),
      dows: [1, 2, 3, 4, 5, 6, 7].map(d5 => ({ nom: JSEM2[d5], on: !!dows[d5],
        toggle: () => rcSet({ dows: Object.assign({}, dows, { [d5]: !dows[d5] }) }) })),
      doms: Array.from({ length: 31 }, (_, i2) => i2 + 1).map(d5 => ({ nom: String(d5), on: !!doms[d5],
        toggle: () => rcSet({ doms: Object.assign({}, doms, { [d5]: !doms[d5] }) }) })),
      // ── La mise en page : l'ordre des blocs, et qui ouvre une page.
      //    Deux sauts sont IMPOSÉS par le rapport (le bilan des tâches et les
      //    photos) : ils s'affichent verrouillés plutôt que d'être proposés
      //    puis ignorés à la génération.
      plan: (() => {
        const ordre = planSlugs();
        const defs = (rd && rd.blocs) || {};
        const forces = { 'xp-bilan': 1, 'xp-taches': 1 };
        let page = 1;
        return ordre.map((sl, i3) => {
          const saut = i3 > 0 && (!!(rc.sauts || {})[sl] || !!forces[sl]);
          if (saut) { page += 1; }
          const lev = ((rd && rd.leviers) || {})[(defs[sl] || {}).levier] || {};
          return {
            slug: sl, nom: (defs[sl] || {}).nom || sl, couleur: lev.couleur || '#999',
            levier: lev.nom || '', page,
            mode: rcModes[sl] === 'complet' ? 'Complet' : 'Dépass.',
            saut, force: !!forces[sl], premier: i3 === 0,
            monter: i3 === 0 ? null : () => {
              const o = ordre.slice(); o.splice(i3 - 1, 0, o.splice(i3, 1)[0]); rcSet({ ordre: o });
            },
            descendre: i3 === ordre.length - 1 ? null : () => {
              const o = ordre.slice(); o.splice(i3 + 1, 0, o.splice(i3, 1)[0]); rcSet({ ordre: o });
            },
            basculerSaut: (i3 === 0 || forces[sl]) ? null
              : () => rcSet({ sauts: Object.assign({}, rc.sauts, { [sl]: !(rc.sauts || {})[sl] }) }),
          };
        });
      })(),
      // Un bouton, une intention : chaque levier commence sa page. Les blocs
      // gardent leur ordre, seuls les sauts changent.
      pageParLevier: () => {
        const defs = (rd && rd.blocs) || {};
        const st4 = {}; let levPrec = null;
        planSlugs().forEach((sl, i3) => {
          const lev = (defs[sl] || {}).levier || '';
          if (i3 > 0 && lev !== levPrec) { st4[sl] = true; }
          levPrec = lev;
        });
        rcSet({ sauts: st4 });
        this.notify('Un saut de page à chaque changement de levier');
      },
      sansSaut: () => { rcSet({ sauts: {} }); this.notify('Sauts retirés — le rapport s’enchaîne'); },
      ordreParLevier: () => {
        rcSet({ ordre: planSlugs().slice().sort((a3, b3) => rangBloc(a3) - rangBloc(b3)) });
        this.notify('Ordre du cockpit rétabli — par levier');
      },
      busy: !!rc.busy,
      apercu: () => {
        if (!slugsOn.length) { this.notify('Cochez au moins un KPI.'); return; }
        rcSet({ busy: true });
        this.api('POST', '/rapports/apercu', composition()).then(g => { rcSet({ busy: false });
          if (g && g.runId) { this.notify('Généré — ' + (g.resume || '')); this.rapCharge(true);
            try { window.open(API_BASE + '/rapports/run/' + g.runId, '_blank'); } catch (e3) {} }
          else { this.notify((g && g.error) || 'Génération échouée'); } });
      },
      envoyer: () => {
        const dests = String(rc.dest || '').split(/[,;]/).map(s5 => s5.trim()).filter(Boolean);
        if (!dests.length) { this.notify('Renseignez les destinataires (pavé Enregistrer).'); return; }
        rcSet({ busy: true });
        this.api('POST', '/rapports/apercu', Object.assign(composition(), { envoyer: true, destinataires: dests }))
          .then(g => { rcSet({ busy: false });
            this.notify(g && g.ok ? 'Envoyé à ' + (g.envoyes || []).join(', ') : ((g && (g.error || g.note)) || 'échec'));
            this.rapCharge(true); });
      },
      edit: !!S.rapEditId,
      editNom: S.rapEditId ? (rc.nom || '') : '',
      enregistrer: () => {
        if (!String(rc.nom || '').trim()) { this.notify('Donnez un nom au rapport.'); return; }
        const jrs = { dows: Object.keys(dows).filter(d5 => dows[d5]).map(Number),
          doms: Object.keys(doms).filter(d5 => doms[d5]).map(Number) };
        const dpm = {};
        Object.entries(rc.dpm || {}).forEach(([nomM, v5]) => {
          const em = String(v5 || '').split(/[,;]/).map(s5 => s5.trim()).filter(Boolean);
          if (em.length) { dpm[nomM] = em; }
        });
        const corps = Object.assign(composition(), { nom: rc.nom, poste: rc.poste,
          heure: rc.heure, jours: jrs,
          envoiMode: rc.envoiMode || 'groupe', destParMagasin: dpm,
          destinataires: String(rc.dest || '').split(/[,;]/).map(s5 => s5.trim()).filter(Boolean) });
        const editId = S.rapEditId;
        rcSet({ busy: true });
        (editId ? this.api('PUT', '/rapports/' + editId, corps) : this.api('POST', '/rapports', corps))
          .then(g => { rcSet({ busy: false });
            if (g && g.ok) {
              this.notify(editId ? 'Rapport mis à jour' : ('Rapport enregistré' + (g.note ? ' — ' + g.note : '')));
              this.rapCharge(true);
              this.setState({ rapComposeOn: false, rapCompo: {}, rapEditId: null }); }
            else { this.notify((g && g.error) || 'Enregistrement refusé'); } });
      },
    };
    // Les cartes héritées (liste démo, alertes, panels PWA, district, Direct
    // Link) ont quitté l'écran à la demande — leurs valeurs avec elles.

  }

  /* --- journal ------------------------------------------------------------------------ */
  valsJournal(common){
    const S = this.state, D = this.D;
    this.vuesCharge(false);
    // ── La heatmap des écrans : ce qui est ouvert, et à quelle fréquence.
    const v = (S.vues || {}).d || {};
    const noms = {};
    (this._navDef || []).forEach(g => (g[1] || []).forEach(it => {
      if (it && it.sub) { (it.children || []).forEach(ch => { noms[ch[0]] = ch[1]; }); }
      else if (it) { noms[it[0]] = it[1]; }
    }));
    common.vuesChargement = !!(S.vues || {}).chargement;
    const jours = v.joursListe || [];
    const maxN = Math.max(1, ...(v.ecrans || []).flatMap(e => e.jours || []));
    common.vuesJours = jours.map(j => ({ court: j.slice(8) + '/' + j.slice(5, 7) }));
    // Le tableau ne montre que les CINQ premiers : au-delà, trente colonnes de
    // cases sur vingt lignes ne se lisent plus, et ce qui compte — ce qui sert
    // le plus — se noie. Le reste s'ouvre à la demande, un écran à la fois.
    const tousEcrans = (v.ecrans || []);
    const TOP = 5;
    const choisi = S.vuesSel || '';
    const retenus = tousEcrans.slice(0, TOP)
      .concat(tousEcrans.filter(e => e.ecran === choisi && tousEcrans.indexOf(e) >= TOP));
    common.vuesAutres = [{ v: '', nom: 'Voir un autre écran…' }].concat(
      tousEcrans.slice(TOP).map(e => ({ v: e.ecran,
        nom: (noms[e.ecran] || e.ecran) + ' · ' + e.total + ' ouverture' + (e.total > 1 ? 's' : '') })));
    common.vuesAutreSel = choisi;
    common.setVuesAutre = e2 => this.setState({ vuesSel: e2.target.value });
    common.vuesReste = Math.max(0, tousEcrans.length - TOP);
    common.vuesLignes = retenus.map(e => ({
      horsTop: tousEcrans.indexOf(e) >= TOP,
      ecran: e.ecran, nom: noms[e.ecran] || e.ecran, total: e.total,
      // Les personnes qui l'ouvrent : un écran utilisé par une seule d'entre
      // elles ne se juge pas comme un écran ouvert par tout le monde.
      qui: Object.keys((v.acteurs || {})[e.ecran] || {}).filter(Boolean).join(', '),
      cases: (e.jours || []).map((n, i) => ({
        n, jour: (jours[i] || ''),
        st: 'display:block;width:100%;height:15px;border-radius:3px;background:' + (n === 0
          ? 'var(--color-background-secondary)'
          : this.mix('#F1E7D6', '#8D1D2C', Math.min(1, n / maxN))),
      })),
    }));
    common.vuesTotal = v.total ? v.total + ' ouverture(s) sur 30 jours · ' + (v.ecrans || []).length + ' écran(s) touché(s)' : '';
    common.vuesJamais = Object.keys(noms).filter(k => !(v.ecrans || []).some(e => e.ecran === k))
      .map(k => noms[k]).join(' · ');
    common.vuesVide = !common.vuesChargement && !(v.ecrans || []).length;
    const all = S.logsExtra.concat(D.logs);
    // Chaque colonne du tableau a sa liste : période, auteur, type, projet.
    // Les valeurs sortent des lignes elles-mêmes — une liste qui propose ce
    // qui n'existe pas ferait chercher pour rien.
    common.logTypes = ['Tous les types'].concat([...new Set(all.map(l => l.type))]);
    common.logQuis = ['Tous les auteurs'].concat([...new Set(all.map(l => l.qui))]);
    common.logProjets = ['Tous les projets'].concat([...new Set(all.map(l => l.projet).filter(p2 => p2 && p2 !== '—'))].sort());
    common.logPeriodes = ['Toute la période', 'Aujourd’hui', '7 derniers jours', '30 derniers jours'];
    const logProjet = S.logProjet || 'Tous les projets';
    const logPeriode = S.logPeriode || 'Toute la période';
    common.logType = S.logType; common.logQui = S.logQui; common.logQ = S.logQ;
    common.logProjet = logProjet; common.logPeriode = logPeriode;
    common.setLogType = e => this.setState({ logType: e.target.value });
    common.setLogQui = e => this.setState({ logQui: e.target.value });
    common.setLogProjet = e => this.setState({ logProjet: e.target.value });
    common.setLogPeriode = e => this.setState({ logPeriode: e.target.value });
    common.setLogQ = e => this.setState({ logQ: e.target.value });
    const q = S.logQ.toLowerCase();
    // `joursFiltre`, pas `jours` : la heatmap des écrans, au-dessus, tient déjà
    // ses jours sous ce nom — deux `const` de même nom dans la même fonction,
    // et le module entier cesse de se charger.
    const joursFiltre = { 'Aujourd’hui': 0, '7 derniers jours': 6, '30 derniers jours': 29 }[logPeriode];
    const borne = joursFiltre === undefined ? null
      : new Date(Date.now() - joursFiltre * 86400000).toISOString().slice(0, 10);
    const rows = all.filter(l => (S.logType === 'Tous les types' || l.type === S.logType)
      && (S.logQui === 'Tous les auteurs' || l.qui === S.logQui)
      && (logProjet === 'Tous les projets' || l.projet === logProjet)
      && (borne === null || String(l.ts).slice(0, 10) >= borne)
      && (!q || (l.msg + ' ' + l.projet).toLowerCase().includes(q)));
    common.logCompte = rows.length + ' événement(s)' + (all.length > rows.length ? ' sur ' + all.length : '');
    const tCl = { 'Alerte': ['rgba(141,29,44,0.12)', '#8D1D2C'], 'Relance': ['rgba(99,102,241,0.12)', '#4649c4'], 'Statut': ['rgba(193,122,42,0.16)', '#8a5a13'], 'Rapport': ['rgba(45,122,62,0.10)', '#2d7a3e'] };
    // Les CINQ derniers événements, le reste dépliable : un journal complet
    // pousse tout le reste de l'écran hors de vue, alors qu'on vient d'abord
    // vérifier « ce qui vient de se passer ». Un filtre ou une recherche ne
    // change pas la règle — c'est le bouton qui ouvre.
    const LOG_N = 5;
    const logTout = !!S.logTout;
    const visibles = logTout ? rows : rows.slice(0, LOG_N);
    common.logReste = Math.max(0, rows.length - visibles.length);
    common.logTout = logTout;
    common.logPlier = rows.length > LOG_N ? () => this.setState({ logTout: !logTout }) : null;
    common.logRows = visibles.map(l => { const c = tCl[l.type] || ['var(--color-background-secondary)', '#666666'];
      return { ts: l.ts, qui: l.qui, type: l.type, projet: l.projet, msg: l.msg, typeSt: 'display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:500;background:' + c[0] + ';color:' + c[1] }; });

    // --- e-mails partis du cockpit : rapports et commandes fournisseur,
    //     réunis par /journal/mails. Chargé à l'ouverture de l'écran.
    if (!D.mails && !this._mailsEnCours) {
      this._mailsEnCours = true;
      // Lecture impossible ≠ aucun envoi : on garde la différence, sinon une
      // panne se lit comme « rien n'est jamais parti ».
      readOne('/journal/mails').then(m2 => { this._mailsEnCours = false;
        this.D.mails = Array.isArray(m2) ? m2 : { indispo: true }; this.setState({}); });
    }
    const mails = Array.isArray(D.mails) ? D.mails : [];
    common.mailsIndispo = !!(D.mails && !Array.isArray(D.mails));
    const mailsTout = !!S.mailsTout;
    const mVisibles = mailsTout ? mails : mails.slice(0, LOG_N);
    common.mailsChargement = !D.mails;
    common.mailsVide = Array.isArray(D.mails) && !mails.length;
    common.mailsCompte = mails.length ? mails.length + ' envoi(s) tracé(s)' : '';
    common.mailsReste = Math.max(0, mails.length - mVisibles.length);
    common.mailsTout = mailsTout;
    common.mailsPlier = mails.length > LOG_N ? () => this.setState({ mailsTout: !mailsTout }) : null;
    common.mailsRows = mVisibles.map(m2 => ({
      ts: m2.ts || '—', source: m2.source || '', objet: m2.objet || '—',
      dest: m2.dest || '—', detail: m2.detail || '',
      // Le sort de l'envoi d'abord : un e-mail en échec se voit sans lire.
      etat: m2.ok ? 'Envoyé' : 'Échec',
      etatSt: 'display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:500;'
        + (m2.ok ? 'background:rgba(45,122,62,0.10);color:#2d7a3e' : 'background:rgba(141,29,44,0.12);color:#8D1D2C'),
      srcSt: 'display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:500;'
        + (m2.source === 'Achat' ? 'background:rgba(193,122,42,0.16);color:#8a5a13' : 'background:rgba(99,102,241,0.12);color:#4649c4'),
    }));
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

    // --- Compte ADMIN de l'API ERP (TFBuddy) : la reprise de l'assistant de
    //     campagne (gammes, alias, liens produit ↔ gamme) passe par l'API dès
    //     que ce compte est là. Même mécanique que le compte panel ci-dessus.
    const er = S.erCompte || {};
    const erSt = D.erpCompte || { base: '', phone: '', motDePasseDefini: false, configure: false };
    common.erBase = er.base != null ? er.base : (erSt.base || '');
    common.erPhone = er.phone != null ? er.phone : (erSt.phone || '');
    common.erPass = er.password || '';
    common.erPassPlaceholder = erSt.motDePasseDefini ? '•••••••• (inchangé)' : 'Mot de passe du compte admin';
    common.erEtat = erSt.configure ? 'Compte configuré' : 'Compte non configuré';
    common.erEtatSt = 'display:inline-block;padding:3px 10px;border-radius:999px;font-size:11.5px;font-weight:500;'
      + (erSt.configure ? 'background:rgba(45,122,62,0.12);color:#2d7a3e' : 'background:rgba(193,122,42,0.16);color:#8a5a13');
    common.erMsg = er.msg || '';
    common.erMsgSt = 'margin-top:10px;font-size:12px;font-weight:500;color:' + (er.ok ? '#2d7a3e' : '#8D1D2C');
    common.erBusy = !!er.busy;
    const erSet = k => e => { const v = e.target.value; this.setState(s2 => ({ erCompte: Object.assign({}, s2.erCompte, { [k]: v }) })); };
    common.setErBase = erSet('base'); common.setErPhone = erSet('phone'); common.setErPass = erSet('password');
    const erRefresh = () => readOne('/erp/compte').then(st => { if (st) this.D.erpCompte = st; this.setState({}); });
    common.erSave = () => {
      const p = this.state.erCompte || {};
      this.setState(s2 => ({ erCompte: Object.assign({}, s2.erCompte, { busy: true, msg: '' }) }));
      fetch(this.apiBase() + '/erp/compte', { method: 'PUT', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ base: p.base || '', phone: p.phone || '', password: p.password || '' }) })
        .then(r => r.json())
        .then(r => { this.setState(s2 => ({ erCompte: Object.assign({}, s2.erCompte, { busy: false, password: '',
            ok: !!r.testOk, msg: r.message || (r.testOk ? 'Connexion réussie.' : 'Enregistré.') }) }));
          this.log('Paramètre', null, 'Compte admin de l’API ERP mis à jour');
          return erRefresh(); })
        .catch(() => this.setState(s2 => ({ erCompte: Object.assign({}, s2.erCompte, { busy: false, ok: false, msg: 'Échec de l’enregistrement.' }) })));
    };
    common.erTest = () => {
      this.setState(s2 => ({ erCompte: Object.assign({}, s2.erCompte, { busy: true, msg: '' }) }));
      fetch(this.apiBase() + '/erp/compte/test', { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(r => r.json())
        .then(r => this.setState(s2 => ({ erCompte: Object.assign({}, s2.erCompte, { busy: false, ok: !!r.ok, msg: r.message || '' }) })))
        .catch(() => this.setState(s2 => ({ erCompte: Object.assign({}, s2.erCompte, { busy: false, ok: false, msg: 'Test impossible.' }) })));
    };
  }

  /* --- paramètres ---------------------------------------------------------------------- */
  valsParams(common){
    const S = this.state, D = this.D, M = this.M;
    common.paramExo = String(this.meta.exercice);

    // --- L'agence de création, reprise sur la note de campagne et dans le
    //     courriel qui la porte. Lue à l'ouverture de l'écran, pas au
    //     chargement du cockpit : c'est un réglage, pas une donnée de pilotage.
    if (!this._noteCfg && !this._noteCfgEnCours) {
      this._noteCfgEnCours = true;
      readOne('/marketing/note-config').then(d => {
        this._noteCfgEnCours = false;
        this._noteCfg = (d && !d.error) ? d : { agence: {} };
        this.setState({});
      }).catch(() => { this._noteCfgEnCours = false; this._noteCfg = { agence: {} }; });
    }
    // Le référentiel des agences : le réseau travaille avec plusieurs d'entre
    // elles, et la note signe celle de la campagne.
    if (!this._agences && !this._agencesEnCours) {
      this._agencesEnCours = true;
      readOne('/marketing/agences').then(d => {
        this._agencesEnCours = false;
        this._agences = (d && d.agences) ? d.agences : [];
        this.setState({});
      }).catch(() => { this._agencesEnCours = false; this._agences = []; });
    }
    const nc = this._noteCfg || null;
    const ag = Object.assign({}, (nc && nc.agence) || {}, S.prmAgence || {});
    const setAg = k => e => this.setState(s2 => ({ prmAgence: Object.assign({}, s2.prmAgence, { [k]: e.target.value }) }));
    const majAgences = r => {
      if (!r || r.error) { this.setState({ prmAgenceErr: (r && r.error) || 'Refusé.' }); return; }
      this._agences = r.agences || [];
      this.setState({ prmAgenceErr: '', prmAgNouv: null });
    };
    const nouv = S.prmAgNouv || {};
    common.prmAgences = {
      chargement: !this._agences,
      lignes: (this._agences || []).map(a => ({
        id: a.id, nom: a.nom, email: a.email || '', site: a.site || '', logo: a.logo || '',
        defaut: !!a.defaut, campagnes: a.campagnes,
        setNom: e => this.api('PATCH', '/marketing/agence/' + a.id, { nom: e.target.value }).then(majAgences),
        setEmail: e => this.api('PATCH', '/marketing/agence/' + a.id, { email: e.target.value }).then(majAgences),
        setSite: e => this.api('PATCH', '/marketing/agence/' + a.id, { site: e.target.value }).then(majAgences),
        parDefaut: () => this.api('PATCH', '/marketing/agence/' + a.id, { defaut: true }).then(majAgences),
        setLogo: e => {
          const f = e.target.files && e.target.files[0];
          if (!f) { return; }
          if (f.size > 500000) { this.setState({ prmAgenceErr: 'Logo trop lourd (' + Math.round(f.size / 1024) + ' Ko) : 500 Ko au plus.' }); return; }
          const r2 = new FileReader();
          r2.onload = () => this.api('PATCH', '/marketing/agence/' + a.id, { logo: String(r2.result) }).then(majAgences);
          r2.readAsDataURL(f);
        },
        // Une agence qui porte des canaux ne s'efface pas : le serveur le
        // refuse et le dit — le bouton reste, la raison arrive.
        retirer: () => {
          if (!window.confirm('Retirer « ' + a.nom + ' » du référentiel ?')) { return; }
          this.api('DELETE', '/marketing/agence/' + a.id).then(majAgences);
        },
      })),
      nom: nouv.nom || '', setNom: e => this.setState(s2 => ({ prmAgNouv: Object.assign({}, s2.prmAgNouv, { nom: e.target.value }) })),
      email: nouv.email || '', setEmail: e => this.setState(s2 => ({ prmAgNouv: Object.assign({}, s2.prmAgNouv, { email: e.target.value }) })),
      ajouter: !String(nouv.nom || '').trim() ? null
        : () => this.api('POST', '/marketing/agence', { nom: nouv.nom, email: nouv.email || '' }).then(majAgences),
      err: S.prmAgenceErr || '',
    };
    common.prmNote = {
      chargement: !nc,
      nom: ag.nom || '', setNom: setAg('nom'),
      site: ag.site || '', setSite: setAg('site'),
      logo: ag.logo || '',
      err: S.prmAgenceErr || '',
      expediteur: (S.prmAgence && S.prmAgence.expediteur != null) ? S.prmAgence.expediteur : ((nc && nc.expediteur) || ''),
      setExpediteur: setAg('expediteur'),
      nCarnet: (nc && nc.nCarnet) || 0,
      setLogo: e => {
        const f = e.target.files && e.target.files[0];
        if (!f) { return; }
        // Un demi-mégaoctet : le logo s'affiche à 26 px de haut, et il voyage
        // dans CHAQUE courriel envoyé au réseau.
        if (f.size > 500000) { this.setState({ prmAgenceErr: 'Logo trop lourd (' + Math.round(f.size / 1024) + ' Ko) : 500 Ko au plus.' }); return; }
        const r = new FileReader();
        r.onload = () => this.setState(s2 => ({ prmAgenceErr: '', prmAgence: Object.assign({}, s2.prmAgence, { logo: String(r.result) }) }));
        r.onerror = () => this.setState({ prmAgenceErr: 'Fichier illisible.' });
        r.readAsDataURL(f);
      },
      retirerLogo: !ag.logo ? null : () => this.setState(s2 => ({ prmAgence: Object.assign({}, s2.prmAgence, { logo: '' }) })),
      enregistrer: () => {
        this.api('PUT', '/marketing/note-config', {
          expediteur: ag.expediteur != null ? ag.expediteur : ((nc && nc.expediteur) || ''),
          agence: { nom: ag.nom || '', site: ag.site || '', logo: ag.logo || '' },
        }).then(r => {
          if (!r || r.error) { this.setState({ prmAgenceErr: (r && r.error) || 'Enregistrement refusé.' }); return; }
          this._noteCfg = null;
          this.setState({ prmAgence: null, prmAgenceErr: '' });
          this.notify('Agence enregistrée');
        });
      },
    };
    // --- Scoring produits : pondération des 4 critères, seuils de verdict et
    //     échelle absolue de la marge nette. Écran dédié (sous-menu Paramètres).
    const scd = S.scDraft || {};
    const SCc = this.scoringCfg();
    const scv = (k, def) => scd[k] != null ? scd[k] : String(def);
    const scSet = k => e => { const v = e.target.value;
      this.setState(s2 => ({ scDraft: Object.assign({}, s2.scDraft, { [k]: v }) }));
      // Une pondération incohérente (somme nulle, seuils croisés) ne part pas :
      // l'alerte à l'écran dit pourquoi, et la frappe suivante réessaie.
      this.autoEnreg('scoring', () => (this._scAuto ? this._scAuto() : false)); };
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
    this._scAuto = () => {
      if (common.scAlerte) { return false; }
      const val = { poids: { volume: pv, marge: pm, perte: pp, comptoir: pc },
        seuils: { moteur: sMot, conforter: sCon },
        marge: { bas: mb, basNote: mbn, haut: mh, hautNote: mhn } };
      return this.api('PUT', '/parametres/scoring', { valeur: val }).then(r => {
        if (r && r.ok === false) { return false; }
        this.meta.scoring = val;
        return true;
      });
    };
    common.scAuto = this.autoTxt('scoring');
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
    // Cible de note Google. Elle vit dans ceo_app_setting, comme les autres
    // réglages : l'écran Réputation la relit à chaque lecture, rien n'est figé
    // dans le JavaScript.
    common.repCibleVal = S.repCible != null ? S.repCible
      : (((this.D.reput || {}).cible) != null ? this.D.reput.cible : 4.5);
    // La clé Google : saisie ici, jamais relue. L'écran n'en connaît que
    // l'empreinte, servie par /reputation avec l'état du connecteur.
    const gco = ((this.D.reput || {}).connecteur) || {};
    common.gCleDefinie = !!gco.configure;
    common.gEmpreinte = gco.empreinte || '';
    common.setGCle = e => {
      const v = String(e.target.value || '').trim();
      if (v === '') { return; }
      e.target.value = '';
      this.api('PUT', '/parametres/google-cle', { cle: v }).then(() => {
        this.D.reput = null; this.repCharge(true);
        this.notify('Clé Google enregistrée'); });
      this.log('Paramètre', '—', 'Connecteur Google — clé enregistrée');
    };
    common.gEffacer = () => {
      if (!window.confirm('Effacer la clé Google ? La synchronisation des avis s’arrêtera.')) { return; }
      this.api('PUT', '/parametres/google-cle', { effacer: true }).then(() => {
        this.D.reput = null; this.repCharge(true);
        this.notify('Clé Google effacée'); });
      this.log('Paramètre', '—', 'Connecteur Google — clé effacée');
    };
    common.setRepCible = e => {
      const v = Math.min(5, Math.max(1, parseFloat(String(e.target.value).replace(',', '.')) || 4.5));
      this.setState({ repCible: v });
      this.api('PUT', '/parametres/reputationCible', { valeur: v })
        .then(() => { this.D.reput = null; this.repCharge(true); });
      this.log('Paramètre', '—', 'Cible de note Google portée à ' + String(v).replace('.', ','));
    };
    common.paramTpls = D.emailTemplates.map(t => ({ nom: t.nom, sujet: t.sujet, corps: S.tpl[t.id] != null ? S.tpl[t.id] : t.corps,
      set: e => { this.setState(s2 => ({ tpl: Object.assign({}, s2.tpl, { [t.id]: e.target.value }) })); this.api('PUT', '/parametres/email-' + t.id, { corps: e.target.value }); } }));

    // --- machine d'envoi SMTP (rapports) : les identifiants vivent côté
    // serveur ; l'écran affiche l'hôte et l'utilisateur, jamais le mot de passe.
    if (!this._smtpLu) { this._smtpLu = true; readOne('/parametres/smtp').then(st => { this.D.smtpStatut = st || null; this.setState({}); }); }
    const smSt = this.D.smtpStatut || {};
    const smD = S.smDraft || {};
    const smVal = (k, def) => smD[k] != null ? smD[k] : (smSt[k] != null && smSt[k] !== 0 ? String(smSt[k]) : def);
    const smSet = k => e => { const v = e.target.value; this.setState(s2 => ({ smDraft: Object.assign({}, s2.smDraft, { [k]: v }) })); };
    common.sm = {
      configure: !!smSt.configure, mdpDefini: !!smSt.motDePasseDefini,
      hote: smVal('hote', ''), port: smVal('port', '587'), securite: smVal('securite', 'tls'),
      utilisateur: smVal('utilisateur', ''), expediteur: smVal('expediteur', ''), testA: smVal('testA', ''),
      busy: !!smD.busy, msg: smD.msg || '',
      msgSt: 'margin-top:10px;font-size:12px;font-weight:500;color:' + (smD.ok ? '#2d7a3e' : '#8D1D2C'),
      etatTxt: smSt.configure
        ? (smSt.source === 'crm' ? 'Réglages repris du CRM candidat · ' : 'Configuré · ') + (smSt.hote || '')
          + (smSt.motDePasseDefini ? ' · mot de passe en place' : ' · sans authentification')
        : 'Non configuré',
      etatSt: 'display:inline-block;padding:3px 10px;border-radius:999px;font-size:11.5px;font-weight:500;'
        + (smSt.configure ? 'background:rgba(45,122,62,0.12);color:#2d7a3e' : 'background:rgba(193,122,42,0.16);color:#8a5a13'),
      setHote: smSet('hote'), setPort: smSet('port'), setSecurite: smSet('securite'),
      setUtilisateur: smSet('utilisateur'), setMdp: smSet('motDePasse'),
      setExpediteur: smSet('expediteur'), setTestA: smSet('testA'),
      save: () => {
        const d2 = this.state.smDraft || {};
        this.setState(s2 => ({ smDraft: Object.assign({}, s2.smDraft, { busy: true, msg: '' }) }));
        fetch(this.apiBase() + '/parametres/smtp', { method: 'PUT', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify({ hote: smVal('hote', ''), port: smVal('port', '587'), securite: smVal('securite', 'tls'),
            utilisateur: smVal('utilisateur', ''), expediteur: smVal('expediteur', ''), motDePasse: d2.motDePasse || '' }) })
          .then(r => r.json())
          .then(r => { this.D.smtpStatut = r;
            this.setState(s2 => ({ smDraft: Object.assign({}, s2.smDraft, { busy: false, motDePasse: '', ok: true, msg: 'Enregistré.' }) }));
            this.log('Paramètre', '—', 'Machine d’envoi SMTP mise à jour'); })
          .catch(() => this.setState(s2 => ({ smDraft: Object.assign({}, s2.smDraft, { busy: false, ok: false, msg: 'Échec de l’enregistrement.' }) })));
      },
      test: () => {
        this.setState(s2 => ({ smDraft: Object.assign({}, s2.smDraft, { busy: true, msg: '' }) }));
        this.api('POST', '/parametres/smtp/test', { a: smVal('testA', '') }).then(r =>
          this.setState(s2 => ({ smDraft: Object.assign({}, s2.smDraft, { busy: false, ok: !!(r && r.ok),
            msg: (r && (r.message || r.error)) || 'Test impossible.' }) })));
      },
    };

    // --- relance « commande à valider » : le texte de la notification créée
    // par la cloche du Suivi fournisseurs (POST /notifications côté ERP).
    if (!this._rlLu) { this._rlLu = true; readOne('/centrale/achats/relance').then(st => { this.D.rlEtat = st || null; this.setState({}); }); }
    const rlEt = this.D.rlEtat || {};
    const rlCf = rlEt.config || {};
    const rlD = S.rlDraft || {};
    const rlVal = (k, def) => rlD[k] != null ? rlD[k] : (rlCf[k] != null ? String(rlCf[k]) : def);
    const rlSet = k => e => { const v = e.target.value; this.setState(s2 => ({ rlDraft: Object.assign({}, s2.rlDraft, { [k]: v }) })); };
    common.rl = {
      titre: rlVal('titre', ''), message: rlVal('message', ''),
      priorite: rlVal('priorite', 'warning'), actionLabel: rlVal('actionLabel', ''),
      actionUrl: rlVal('actionUrl', ''), jours: rlVal('jours', '7'),
      variables: (rlEt.variables || []).map(v => '{{' + v + '}}').join(' · '),
      envoyees: rlEt.envoyees || 0,
      busy: !!rlD.busy, msg: rlD.msg || '',
      msgSt: 'margin-top:10px;font-size:12px;font-weight:500;color:' + (rlD.ok ? '#2d7a3e' : '#8D1D2C'),
      setTitre: rlSet('titre'), setMessage: rlSet('message'), setPriorite: rlSet('priorite'),
      setActionLabel: rlSet('actionLabel'), setActionUrl: rlSet('actionUrl'), setJours: rlSet('jours'),
      save: () => {
        this.setState(s2 => ({ rlDraft: Object.assign({}, s2.rlDraft, { busy: true, msg: '' }) }));
        this.api('PUT', '/parametres/caRelanceCommande', { valeur: {
          titre: rlVal('titre', ''), message: rlVal('message', ''), priorite: rlVal('priorite', 'warning'),
          actionLabel: rlVal('actionLabel', ''), actionUrl: rlVal('actionUrl', ''),
          jours: parseInt(rlVal('jours', '7'), 10) || 7 } }).then(r => {
          const ok = !(r && r.ok === false); this._rlLu = false;
          this.setState(s2 => ({ rlDraft: ok ? { msg: 'Enregistré.', ok: true } : Object.assign({}, s2.rlDraft, { busy: false, ok: false, msg: 'Échec de l’enregistrement.' }) }));
          if (ok) { this.log('Paramètre', '—', 'Template de relance commande mis à jour'); }
        });
      },
    };

    // --- e-mail « commande fournisseur » (centrale d'achat) : template + journal.
    // Groupé ici avec la machine SMTP — tout ce qui touche au courrier vit dans
    // Paramètres. L'envoi automatique tourne avec le cron des rapports.
    if (!this._caMailLu) { this._caMailLu = true; readOne('/centrale/commandes/mail').then(st => { this.D.caMailEtat = st || null; this.setState({}); }); }
    const cmEt = this.D.caMailEtat || {};
    const cmCf = cmEt.config || {};
    const cmD = S.cmDraft || {};
    const cmVal = (k, def) => cmD[k] != null ? cmD[k] : (cmCf[k] != null ? String(cmCf[k]) : def);
    const cmSet = k => e => { const v = e.target.value; this.setState(s2 => ({ cmDraft: Object.assign({}, s2.cmDraft, { [k]: v }) })); };
    const cmActif = cmD.actif != null ? cmD.actif : !!cmCf.actif;
    common.cm = {
      actif: cmActif,
      expediteur: cmVal('expediteur', 'Centrale d’achat <achats@atelierby.be>'),
      setExpediteur: cmSet('expediteur'),
      // Le SQUELETTE HTML, éditable comme le reste. Vide = celui d'origine.
      html: cmVal('html', ''),
      setHtml: cmSet('html'),
      htmlOuvert: !!cmD.htmlOuvert,
      htmlBascule: () => this.setState(s2 => ({ cmDraft: Object.assign({}, s2.cmDraft, { htmlOuvert: !(s2.cmDraft || {}).htmlOuvert }) })),
      htmlDefaut: cmEt.squelette ? () => this.setState(s2 => ({ cmDraft: Object.assign({}, s2.cmDraft,
        { html: cmEt.squelette, msg: 'Squelette d’origine repris — enregistrez pour le garder.', ok: true }) })) : null,
      variablesHtml: (cmEt.variablesHtml || []).map(v => '{{' + v + '}}').join(' · '),
      // L'aperçu montre le gabarit EN COURS D'ÉDITION, pas celui enregistré :
      // on regarde ce qu'on vient d'écrire, avant de l'enregistrer.
      apercuUrl: this.apiBase() + '/centrale/commandes/mail/apercu'
        + '?sujet=' + encodeURIComponent(cmVal('sujet', ''))
        + '&corps=' + encodeURIComponent(cmVal('corps', ''))
        + '&html=' + encodeURIComponent(cmVal('html', '')),
      apercuOuvert: !!cmD.apercuOuvert,
      apercuBascule: () => this.setState(s2 => ({ cmDraft: Object.assign({}, s2.cmDraft, { apercuOuvert: !(s2.cmDraft || {}).apercuOuvert }) })),
      destinataire: cmVal('destinataire', 'achats@atelierby.be'),
      copie: cmVal('copie', ''), sujet: cmVal('sujet', ''), corps: cmVal('corps', ''),
      variables: (cmEt.variables || []).map(v => '{{' + v + '}}').join(' · '),
      smtpPret: !!cmEt.smtpPret,
      dernier: cmEt.dernier ? ('Dernier passage : ' + (cmEt.dernier.quand || '—').replace('T', ' ').slice(0, 16)
        + ' · ' + (cmEt.dernier.envoyes || 0) + ' envoyé(s)'
        + (cmEt.dernier.echecs ? ' · ' + cmEt.dernier.echecs + ' échec(s)' : '')) : 'Jamais passé — le cron horaire des rapports le déclenche.',
      journal: (cmEt.journal || []).map(j => ({ quand: j.quand || '—',
        type: { recu: 'Commande reçue', envoye: 'E-mail envoyé', relance: 'Relance envoyée',
          essai: 'Essai', echec: 'Échec' }[j.type] || (j.type || '—'),
        col: j.type === 'echec' ? '#8D1D2C'
          : (j.type === 'envoye' || j.type === 'relance') ? '#2d7a3e' : 'var(--color-text)',
        detail: j.detail || '', destinataire: j.destinataire || '' })),
      busy: !!cmD.busy, msg: cmD.msg || '',
      msgSt: 'margin-top:10px;font-size:12px;font-weight:500;color:' + (cmD.ok ? '#2d7a3e' : '#8D1D2C'),
      etatTxt: cmActif ? (cmEt.smtpPret ? 'Actif' : 'Actif — SMTP à configurer') : 'Inactif',
      etatSt: 'display:inline-block;padding:3px 10px;border-radius:999px;font-size:11.5px;font-weight:500;'
        + (cmActif && cmEt.smtpPret ? 'background:rgba(45,122,62,0.12);color:#2d7a3e' : 'background:rgba(193,122,42,0.16);color:#8a5a13'),
      toggle: () => this.setState(s2 => ({ cmDraft: Object.assign({}, s2.cmDraft, { actif: !cmActif }) })),
      setDestinataire: cmSet('destinataire'), setCopie: cmSet('copie'), setSujet: cmSet('sujet'), setCorps: cmSet('corps'),
      save: () => {
        this.setState(s2 => ({ cmDraft: Object.assign({}, s2.cmDraft, { busy: true, msg: '' }) }));
        this.api('PUT', '/parametres/caMailCommande', { valeur: {
          actif: cmActif, expediteur: cmVal('expediteur', ''),
          destinataire: cmVal('destinataire', ''), copie: cmVal('copie', ''),
          sujet: cmVal('sujet', ''), corps: cmVal('corps', ''), html: cmVal('html', ''),
          // Ce qui ne se règle pas à l'écran doit SURVIVRE à un enregistrement :
          // la fenêtre de relance et la borne de lignes vivent dans le même
          // réglage, les omettre les remettrait à zéro.
          fenetreJours: (cmEt.config || {}).fenetreJours, maxLignes: (cmEt.config || {}).maxLignes } }).then(r => {
          const ok = !(r && r.ok === false);
          this._caMailLu = false;
          this.setState(s2 => ({ cmDraft: ok ? { msg: 'Enregistré.', ok: true } : Object.assign({}, s2.cmDraft, { busy: false, ok: false, msg: 'Échec de l’enregistrement.' }) }));
          if (ok) { this.log('Paramètre', '—', 'Template e-mail commande fournisseur mis à jour'); }
        });
      },
      // L'essai part où on veut : l'envoyer à la centrale depuis la centrale
      // ne prouve pas qu'un fournisseur recevra quoi que ce soit.
      testVers: cmD.testVers != null ? cmD.testVers : '',
      setTestVers: cmSet('testVers'),
      test: () => {
        this.setState(s2 => ({ cmDraft: Object.assign({}, s2.cmDraft, { busy: true, msg: '' }) }));
        this.api('POST', '/centrale/commandes/mail/test', { vers: (cmD.testVers || '').trim() }).then(r => {
          this._caMailLu = false;
          this.setState(s2 => ({ cmDraft: Object.assign({}, s2.cmDraft, { busy: false, ok: !!(r && r.ok),
            msg: r && r.ok ? 'Essai envoyé à ' + (r.destinataire || '') + '.' : (r && (r.erreur || r.error)) || 'Essai impossible.' }) }));
        });
      },
      // Le carnet d'adresses : les fournisseurs qui ont une commande en
      // attente, et à qui l'on doit donc écrire. Ni le panel ni le référentiel
      // local ne portent leur adresse — elle se saisit ici.
      fenetre: (cmEt.fenetreJours || 30) + ' jours',
      // Classer : dire ICI que ces réquisitions sont traitées, pour que le
      // rappel automatique cesse de les compter. L'ERP n'est pas touché — le
      // dire, sinon on croirait la commande acceptée côté fournisseur.
      classeesN: (cmEt.classees || {}).n || 0,
      classeesTxt: ((cmEt.classees || {}).n || 0)
        ? ((cmEt.classees || {}).n + ' réquisition(s) classées'
           + ((cmEt.classees || {}).quand ? ' le ' + this.fD(String((cmEt.classees || {}).quand).slice(0, 10)) : '')
           + ((cmEt.classees || {}).par ? ' par ' + (cmEt.classees || {}).par : ''))
        : '',
      enAttenteN: (cmEt.fournisseurs || []).reduce((a, f) => a + (f.commandes || 0) + (f.anciennes || 0), 0),
      classer: () => {
        const n = (cmEt.fournisseurs || []).reduce((a, f) => a + (f.commandes || 0) + (f.anciennes || 0), 0);
        if (!window.confirm('Classer toutes les réquisitions en attente ?\n\n'
          + 'Elles ne seront plus relancées automatiquement. Rien n’est modifié dans l’ERP : '
          + 'le fournisseur n’est pas averti, et la cloche du Suivi fournisseurs reste disponible '
          + 'pour relancer à la main. Ce classement se défait.')) { return; }
        this.api('POST', '/centrale/commandes/mail/classer', {}).then(r => {
          this._caMailLu = false;
          this.notify(!r || r.ok === false
            ? ('Classement refusé — ' + ((r && (r.error || r.erreur)) || 'écriture impossible'))
            : ((r.classees || 0) + ' réquisition(s) classées — plus de rappel automatique'));
          this.setState({});
        });
      },
      rouvrir: ((cmEt.classees || {}).n || 0) ? () => {
        if (!window.confirm('Rouvrir les réquisitions classées ? Elles redeviendront relançables.')) { return; }
        this.api('POST', '/centrale/commandes/mail/classer', { rouvrir: true }).then(r => {
          this._caMailLu = false;
          this.notify(r && r.ok !== false ? 'Classement annulé' : 'Annulation refusée');
          this.setState({});
        });
      } : null,
      fournisseurs: (cmEt.fournisseurs || []).map(f => ({
        nom: f.nom, commandes: f.commandes, total: this.fU(f.total || 0),
        // Ce qui dort dans l'ERP : compté, jamais relancé. Le taire ferait
        // croire que le fournisseur n'a rien en souffrance.
        anciennes: f.anciennes
          ? f.anciennes + ' ancienne(s) · ' + this.fU(f.anciennesTotal || 0)
            + (f.plusRecenteAncienne ? ' · la plus récente ' + this.fD(f.plusRecenteAncienne) : '')
          : '',
        email: (cmD['mail:' + f.cle] != null) ? cmD['mail:' + f.cle] : (f.email || ''),
        source: f.source === 'panel' ? 'du panel' : '',
        sans: !f.email,
        relance: f.dernier ? ('relancé le ' + this.fD(f.dernier) + ' · ' + f.envois + ' rappel(s)') : 'jamais relancé',
        set: e => { const v = e.target.value;
          this.setState(s2 => ({ cmDraft: Object.assign({}, s2.cmDraft, { ['mail:' + f.cle]: v }) })); },
        enregistrer: () => {
          const v = String((this.state.cmDraft || {})['mail:' + f.cle] != null
            ? (this.state.cmDraft || {})['mail:' + f.cle] : (f.email || '')).trim();
          this.api('PUT', '/centrale/fournisseurs/mail', { nom: f.nom, email: v }).then(r => {
            this._caMailLu = false;
            this.notify(!r || r.ok === false
              ? ('Adresse refusée — ' + ((r && (r.error || r.erreur)) || 'écriture impossible'))
              : (v ? f.nom + ' → ' + v : 'Adresse retirée pour ' + f.nom));
            this.setState({});
          });
        },
      })),
      sansAdresse: cmEt.sansAdresse || 0,
      // Un gabarit enregistré avant le regroupement parle de « la » commande
      // et de « le » magasin : il tient encore, mais il ne dit plus ce que le
      // courrier fait. On le signale, et on propose de reprendre le nôtre.
      vieux: !!cmEt.gabaritVieux,
      restaurer: cmEt.defauts ? () => {
        const d2 = cmEt.defauts;
        this.setState(s2 => ({ cmDraft: Object.assign({}, s2.cmDraft,
          { sujet: d2.sujet, corps: d2.corps, msg: 'Gabarit d’origine repris — enregistrez pour le garder.', ok: true }) }));
      } : null,
    };

    // --- catalogue des KPI : le référentiel (ceo_kpi_def) qui pilote les
    // seuils des écrans et des rapports, et où se créent les KPI dérivés.
    if (!this._kpisLus) { this._kpisLus = true; readOne('/kpi-defs').then(d2 => { this.D.kpiDefs = d2 || null; this.setState({}); }); }
    const kd = this.D.kpiDefs;
    const kDraft = S.kpiDraft || {};
    const kSet = k => e => { const v = e.target.value; this.setState(s2 => ({ kpiDraft: Object.assign({}, s2.kpiDraft, { [k]: v }) })); };
    const kMaj = (id, patch, msg) => this.api('PUT', '/kpi-defs/' + id, patch)
      .then(() => { this._kpisLus = false; this.notify(msg || 'KPI mis à jour'); this.setState({}); });
    const kLev = sl => ((kd && kd.leviers && kd.leviers[sl]) || {});
    const kMes = m => ((kd && kd.mesures) || {})[m] || m;
    common.kpiCat = {
      chargement: !kd,
      lignes: ((kd && kd.kpis) || []).map(k => ({
        nom: k.nom, desc: k.description || '',
        levNom: kLev(k.levier).nom || k.levier, levCoul: kLev(k.levier).couleur || '#999999',
        type: k.calcul && k.calcul.type === 'derive'
          ? 'Formule : ' + kMes(k.calcul.num) + ' ÷ ' + kMes(k.calcul.den) + (k.calcul.echelle === 'pct' ? ' × 100' : '')
          : (k.calcul && k.calcul.type === 'ecran' ? 'Seuil d’écran (' + (k.calcul.ecran || '') + ')' : 'Câblé sur l’API'),
        alerte: k.seuilAlerte != null ? String(k.seuilAlerte) : '',
        critique: k.seuilCritique != null ? String(k.seuilCritique) : '',
        sens: k.sens,
        setAlerte: e => kMaj(k.id, { seuilAlerte: e.target.value }, 'Seuil d’alerte de « ' + k.nom + ' » : ' + e.target.value),
        setCritique: e => kMaj(k.id, { seuilCritique: e.target.value }, 'Seuil critique de « ' + k.nom + ' » : ' + e.target.value),
        setSens: e => kMaj(k.id, { sens: e.target.value }),
        sortie: k.sortie || 'tableau',
        setSortie: e => kMaj(k.id, { sortie: e.target.value }, 'Sortie de « ' + k.nom + ' » : ' + e.target.value),
        actif: !!k.actif, actifTxt: k.actif ? 'Actif' : 'Inactif',
        actifSt: 'display:inline-block;padding:2px 9px;border-radius:999px;font-size:10.5px;font-weight:500;cursor:pointer;white-space:nowrap;'
          + (k.actif ? 'background:rgba(45,122,62,0.10);color:#2d7a3e' : 'background:var(--color-background-secondary);color:var(--color-text-muted)'),
        toggle: () => kMaj(k.id, { actif: !k.actif }, '« ' + k.nom + ' » ' + (k.actif ? 'désactivé' : 'activé')),
        supprimable: !!k.supprimable,
        suppr: () => { if (!window.confirm('Supprimer le KPI « ' + k.nom + ' » ?')) { return; }
          this.api('DELETE', '/kpi-defs/' + k.id, {}).then(() => { this._kpisLus = false; this.notify('KPI supprimé'); this.setState({}); }); },
      })),
      mesures: Object.entries((kd && kd.mesures) || {}).map(([val, nomM]) => ({ val, nom: nomM })),
      leviers: Object.entries((kd && kd.leviers) || {}).map(([val, l]) => ({ val, nom: l.nom })),
      sorties: Object.entries((kd && kd.sorties) || { tableau: 'Tableau' }).map(([val, nomS]) => ({ val, nom: nomS })),
      d: { nom: kDraft.nom || '', num: kDraft.num || 'ca', den: kDraft.den || 'tickets',
        echelle: kDraft.echelle || 'unite', levier: kDraft.levier || 'transverse',
        alerte: kDraft.alerte || '', critique: kDraft.critique || '', sens: kDraft.sens || 'bas',
        sortie: kDraft.sortie || 'tableau' },
      set: { nom: kSet('nom'), num: kSet('num'), den: kSet('den'), echelle: kSet('echelle'),
        levier: kSet('levier'), alerte: kSet('alerte'), critique: kSet('critique'), sens: kSet('sens'),
        sortie: kSet('sortie') },
      creer: () => {
        const d3 = this.state.kpiDraft || {};
        if (!String(d3.nom || '').trim()) { this.notify('Donnez un nom au KPI.'); return; }
        this.api('POST', '/kpi-defs', { nom: d3.nom, num: d3.num || 'ca', den: d3.den || 'tickets',
          echelle: d3.echelle || 'unite', levier: d3.levier || 'transverse',
          seuilAlerte: d3.alerte, seuilCritique: d3.critique, sens: d3.sens || 'bas',
          sortie: d3.sortie || 'tableau' })
          .then(r2 => { if (r2 && r2.ok) { this._kpisLus = false;
              this.setState({ kpiDraft: {} }); this.notify('KPI créé — évalué dès le prochain rapport'); }
            else { this.notify((r2 && r2.error) || 'Création refusée'); } });
      },
    };

    // --- cadence dynamique des contrôles : règles + plan calculé (maquette
    // validée). Les notes viennent du panel ; les règles et le plan vivent
    // côté serveur (ceo_app_setting) — l'écran ne fige rien.
    if (!this._cadenceLue) { this._cadenceLue = true; readOne('/cadence').then(d2 => { this.D.cadence = d2 || null; this.setState({}); }); }
    const cdD = this.D.cadence;
    const cdReg = (cdD && cdD.regles) || {};
    const cdPlan = (cdD && cdD.plan) || null;
    const cdMaj = (patch, msg) => this.api('PUT', '/cadence', patch)
      .then(r2 => { if (r2 && r2.regles && this.D.cadence) { this.D.cadence.regles = r2.regles; }
        this.notify(msg || 'Règle de cadence enregistrée'); this.setState({}); });
    const cdDate = d3 => d3 ? d3.slice(8, 10) + '/' + d3.slice(5, 7) : '—';
    const cdCoul = { 5: '#1F7A3D', 4: '#6FA84C', 3: '#D9A226', 2: '#C64B22', 1: '#A31220' };
    const cdPaliers = (cdReg.paliers && cdReg.paliers.length ? cdReg.paliers : [1, 2, 3, 5, 7]);
    const cdMouv = m => m === 'espacee' ? ['↗ espacée', 'background:rgba(45,122,62,0.12);color:#2d7a3e']
      : m === 'resserree' ? ['↘ resserrée', 'background:rgba(193,75,34,0.14);color:#a34012']
      : m === 'rechute' ? ['↘ rechute', 'background:rgba(163,18,32,0.13);color:#A31220']
      : ['→ stable', 'background:var(--color-background-secondary);color:var(--color-text-muted)'];
    common.cad = {
      chargement: !cdD,
      actifTxt: cdReg.actif === false ? 'Désactivée' : 'Activée',
      actifSt: 'display:inline-block;padding:3px 11px;border-radius:999px;font-size:11.5px;font-weight:500;cursor:pointer;'
        + (cdReg.actif === false ? 'background:var(--color-background-secondary);color:var(--color-text-muted)' : 'background:rgba(45,122,62,0.12);color:#2d7a3e'),
      toggle: () => cdMaj({ actif: cdReg.actif === false }, 'Cadence dynamique ' + (cdReg.actif === false ? 'activée' : 'désactivée')),
      serie: cdReg.serie != null ? String(cdReg.serie) : '5',
      moyenneMin: cdReg.moyenneMin != null ? String(cdReg.moyenneMin).replace('.', ',') : '4,5',
      noteCasse: cdReg.noteCasse != null ? String(cdReg.noteCasse) : '4',
      noteRechute: cdReg.noteRechute != null ? String(cdReg.noteRechute) : '3',
      setSerie: e => cdMaj({ serie: e.target.value }, 'Série requise : ' + e.target.value + ' contrôles'),
      setMoyenneMin: e => cdMaj({ moyenneMin: e.target.value }, 'Moyenne exigée : ' + e.target.value),
      setNoteCasse: e => cdMaj({ noteCasse: e.target.value }, 'Une note < ' + e.target.value + ' casse la série'),
      setNoteRechute: e => cdMaj({ noteRechute: e.target.value }, 'Rechute : note ≤ ' + e.target.value + ' → quotidien'),
      paliersTxt: cdPaliers.join(', '),
      setPaliers: e => cdMaj({ paliers: e.target.value }, 'Paliers : ' + e.target.value + ' jours'),
      paliersVis: cdPaliers,
      palierMax: cdPaliers[cdPaliers.length - 1],
      perimetre: cdReg.perimetre || 'par-magasin',
      setPerimetre: e => cdMaj({ perimetre: e.target.value },
        e.target.value === 'reseau' ? 'Cadence calculée sur le réseau entier' : 'Cadence calculée par magasin'),
      semaines: 4,
      busy: !!S.cadBusy,
      planInfo: cdPlan ? 'calculé le ' + cdPlan.calculeLe + ' — ' + cdPlan.controlesLus + ' contrôle(s) lus sur '
        + cdPlan.semaines + ' semaine(s)' + (cdPlan.partiel ? ' · PARTIEL (budget API atteint)' : '') : '',
      recalc: () => { if (this.state.cadBusy) { return; }
        this.setState({ cadBusy: true });
        this.api('POST', '/cadence/plan', { semaines: 4 }).then(r2 => {
          if (r2 && r2.plan && this.D.cadence) { this.D.cadence.plan = r2.plan; }
          this.setState({ cadBusy: false });
          this.notify(r2 && r2.plan ? 'Plan recalculé — ' + r2.plan.lignes.length + ' tâche(s) suivie(s)' : 'Recalcul impossible');
          this.log('Paramètre', '—', 'Cadence dynamique — plan de contrôle recalculé'); }); },
      planLignes: ((cdPlan && cdPlan.lignes) || []).map(l => {
        const [mTxt, mSt] = cdMouv(l.mouvement);
        return { tache: l.tache, magasin: l.magasin,
          notes: (l.notes || []).map(nn => ({ n: nn.n, coul: cdCoul[nn.n] || '#999999' })),
          moyenne: String(l.moyenne).replace('.', ','),
          cadence: l.palierJours + ' j' + (l.palierIdx === 0 ? ' (quotidien)'
            : l.serieEnCours ? ' · série ' + l.serieEnCours + '/' + (cdReg.serie || 5) : ''),
          prochain: cdDate(l.prochain) + (l.du ? ' — dû' : ''), du: !!l.du,
          mouvTxt: mTxt + (l.mouvementDate ? ' (' + cdDate(l.mouvementDate) + ')' : ''),
          mouvSt: 'display:inline-block;padding:3px 10px;border-radius:999px;font-size:10.5px;font-weight:600;white-space:nowrap;' + mSt };
      }),
      apiNote: (cdD && cdD.apiPost && cdD.apiPost.note) || '',
      apiPre: cdD && cdD.apiPost
        ? cdD.apiPost.endpoint + '\n\n' + JSON.stringify(cdD.apiPost.corps, null, 2)
          + '\n\n→ réponse attendue : ' + JSON.stringify(cdD.apiPost.reponse)
        : '',
    };
  }
}

const app = new App(document.getElementById('app'));
app.start();
