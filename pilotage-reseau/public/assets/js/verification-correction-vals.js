/* Cockpit CEO — Vérification › Recettes à corriger : corriger d'ici, ou ouvrir le bon écran du panel (13/11/2026).
 * Maquette : docs/maquettes/recettes-correction (a : un geste par ligne ; b : le volet en correction ; c : le registre).
 * Serveur : src/verification_correction.php — chaque geste se montre d'abord (« Vérifier », apercu), puis s'écrit au panel
 * (« Appliquer au panel »), se relit et se constate ; le registre garde l'avant pour « Annuler ».
 *
 * Exporte : gesteDe (le geste d'une ligne de la liste), valsLigneOuverte (la correction en place), valsRecentes et
 * valsRegistre (ce qui a été fait), valsEdition (le volet en correction), lienPanel. Le gabarit :
 * verification-correction-tpl.js ; le style : verification.css (rc-). */
import { readOne, write } from './api.js';

const nf = (n, d) => n == null || isNaN(n) ? '—' : Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d || 0, maximumFractionDigits: d || 0 }).replace(/[  ]/g, ' ');
const fE = (n, d) => n == null || isNaN(n) ? '—' : nf(n, d == null ? 2 : d) + ' €';
/** Une quantité lisible même petite : « 0,002 kg », « 12 », « 2,5 kg » (même règle que vrcQte). */
const qte = (q, u) => {
  if (q == null || isNaN(q)) { return '—'; }
  const a = Math.abs(q), entier = Math.abs(q - Math.round(q)) < 1e-9;
  const d = entier ? 0 : (a >= 10 ? 1 : (a >= 1 ? 2 : (a >= 0.01 ? 3 : 6)));
  return Number(q).toLocaleString('fr-BE', { minimumFractionDigits: 0, maximumFractionDigits: d }).replace(/[\u202f\u00a0]/g, '\u00a0') + (u ? ' ' + u : '');
};
const num = s => { const t = String(s == null ? '' : s).trim().replace(/\s/g, '').replace(',', '.'); if (t === '') { return null; } const n = Number(t); return isFinite(n) ? n : NaN; };
const heure = s => { const d = new Date(String(s || '').replace(' ', 'T')); return isNaN(d) ? '' : String(d.getDate()).padStart(2, '0') + '/' + String(d.getMonth() + 1).padStart(2, '0') + ' ' + String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0'); };
const ecrire = (app, chemin, corps, methode) => write(app.source || 'api', methode || 'POST', chemin, corps);
const ROUTE = '/verification/recettes/';

/** Une adresse du panel depuis son modèle ({pid}, {rid}, {mid}, {sid}, {sous}) ; null si un identifiant attendu manque. */
export function lienPanel(modele, ids){
  if (!modele) { return null; }
  let manque = false;
  const u = String(modele).replace(/\{(\w+)\}/g, (m, k) => { const v = ids[k === 'id' ? 'pid' : k]; if (v == null || v === '') { manque = true; return ''; } return encodeURIComponent(v); });
  return manque ? null : u;
}

/**
 * Le geste d'une ligne : ce que fait son bouton. `type` : ligne (la correction s'ouvre sous la ligne), volet (la recette
 * s'ouvre en correction), panel (seul le panel le fait : le bouton y mène), local (noté au cockpit, rien au panel).
 */
export function gesteDe(l){
  const c = l.corr || {};
  switch (l.regle) {
    case 'unite': return c.mid && l.rid && !c.sous ? { type: 'ligne', bouton: c.facteur < 1 ? 'Appliquer ÷1000' : 'Appliquer ×1000',
      prop: '« ' + c.matiere + ' » : ' + qte(c.q, c.u) + ' par pièce → ' + qte(c.q * c.facteur, c.u) + ' ; la pièce passe de ' + fE(c.coutAvant) + ' à ' + fE(c.coutApres) + '.' }
      // Une ligne lourde dans une sous-recette, qui peut servir à d'autres produits : le volet, jamais un clic.
      : (l.rid ? { type: 'volet', bouton: 'Corriger', prop: c.sous ? '« ' + c.matiere + ' » est dans la sous-recette « ' + c.sous + ' », qui peut servir à d’autres produits : la recette s’ouvre en correction (la quantité de sous-recette, ou sa ligne).' : '' } : null);
    case 'rend': return l.rid ? { type: 'ligne', bouton: 'Poser le rendement', prop: 'Le nombre de pièces que donne la recette entière.' } : null;
    case 'port': return { type: 'ligne', bouton: 'Poser la fraction', prop: c.fraction ? 'Lue dans le nom : ' + nf(c.fraction, 2) + ' de la pièce.' : 'La part de la pièce que vend la portion (½ = 0,5, ¼ = 0,25).' };
    case 'prix': return (c.matieres || []).length ? { type: 'ligne', bouton: 'Saisir ' + ((c.matieres || []).length > 1 ? 'les prix' : 'le prix'),
      prop: (c.matieres || []).filter(m => m.propose != null).slice(0, 2).map(m => m.nom + ' : ' + fE(m.propose, m.propose < 1 ? 4 : 2) + '/' + (m.u || 'unité') + ' ailleurs').join(' · ') } : null;
    case 'sans': return { type: 'ligne', bouton: 'Rattacher une recette', prop: 'Une recette du panel de nom proche, à rattacher ; sans recette proche, à créer au panel.' };
    case 'reseau': return { type: 'ligne', bouton: 'Recalculer à ' + (c.magasin || 'ce magasin'), ico: 'roue', prop: 'Recalculer le coût des recettes de ' + (c.magasin || 'ce magasin') + ' (médiane des autres : ' + fE(c.mediane) + ') ; s’il ne bouge pas, le coût est saisi à la main.', volet: !!l.rid };
    case 'vide': return l.rid ? { type: 'volet', bouton: 'Compléter la recette', prop: 'Copier les lignes d’une recette proche, puis ajuster.' } : null;
    case 'inv': return l.rid ? { type: 'volet', bouton: 'Corriger', prop: 'La recette s’ouvre en correction : la ligne qui pèse le plus en tête.' } : null;
    case 'orph': return { type: 'local', bouton: 'Hors recette', prop: 'Emballage, hygiène… : marquée « hors recette », elle ne remonte plus. Sinon, l’ajouter à la recette qui l’oublie.' };
    case 'form': return { type: 'panel', bouton: 'Ouvrir au panel ↗', prop: 'La promotion « bundle » se crée au panel.' };
    default: return null;
  }
}

/** La correction en place d'une ligne ouverte : ses champs, l'aperçu, le résultat. */
export function valsLigneOuverte(app, l, d, rafraichir){
  const S = app.state, cle = l.cle, c = l.corr || {};
  const sais = (S.vrSaisie || {})[cle] || {};
  const prev = (S.vrPrev || {})[cle] || null, res = (S.vrRes || {})[cle] || null;
  const poser = (k, val) => app.setState({ vrSaisie: Object.assign({}, S.vrSaisie, { [cle]: Object.assign({}, (app.state.vrSaisie || {})[cle], { [k]: val }) }), vrPrev: Object.assign({}, app.state.vrPrev, { [cle]: null }) });
  const base = { cle, pid: l.pid || null, nom: l.nom, rid: l.rid || null };
  let corps = null, champs = [], erreur = null;
  if (l.regle === 'unite') {
    corps = Object.assign(base, { geste: 'quantite', mid: c.mid, sous: c.sous || null, facteur: c.facteur, coutAvant: c.coutAvant, coutApres: c.coutApres });
  } else if (l.regle === 'rend') {
    const n = num(sais.n);
    champs.push({ lib: 'Rendement', val: sais.n == null ? '' : sais.n, suf: 'pièces', poser: e => poser('n', e.target.value), id: 'vr-n-' + cle });
    if (n == null) { erreur = 'Le nombre de pièces que donne la recette.'; } else if (!(n > 0)) { erreur = 'Un nombre positif.'; }
    else { corps = Object.assign(base, { geste: 'rendement', n }); }
  } else if (l.regle === 'port') {
    const v = sais.f == null ? (c.fraction != null ? String(c.fraction).replace('.', ',') : '') : sais.f;
    const f = num(v);
    champs.push({ lib: 'Fraction', val: v, suf: 'de la pièce' + (c.cout ? ' → coût ' + (f > 0 && f < 1 ? fE(c.cout * f) : '—') : ''), poser: e => poser('f', e.target.value), id: 'vr-f-' + cle });
    if (!(f > 0 && f < 1)) { erreur = 'Une fraction entre 0 et 1 (¼ = 0,25).'; } else { corps = Object.assign(base, { geste: 'portion', shop: c.sid, fraction: f }); }
  } else if (l.regle === 'prix') {
    const ms = c.matieres || [];
    const mid = sais.mid || (ms[0] || {}).mid;
    const m = ms.find(x => x.mid === mid) || ms[0] || {};
    const v = sais['p' + m.mid] == null ? (m.propose != null ? String(m.propose).replace('.', ',') : '') : sais['p' + m.mid];
    const p = num(v);
    if (ms.length > 1) { champs.push({ lib: 'Matière', choix: ms.map(x => ({ id: x.mid, nom: x.nom, on: x.mid === m.mid })), poser: e => poser('mid', Number(e.target.value)) }); }
    champs.push({ lib: (ms.length > 1 ? '' : m.nom + ' ') + 'à ' + (c.magasin || ''), val: v, suf: '€ / ' + (m.u || 'unité') + (m.min != null ? ' (ailleurs : ' + fE(m.min, m.min < 1 ? 4 : 2) + (m.max !== m.min ? ' à ' + fE(m.max, m.max < 1 ? 4 : 2) : '') + ')' : ' (aucun autre magasin ne la chiffre)'),
      poser: e => poser('p' + m.mid, e.target.value), id: 'vr-p-' + cle + '-' + m.mid, large: true });
    if (!(p > 0)) { erreur = 'Le prix de l’unité, hors TVA.'; } else { corps = Object.assign(base, { geste: 'prix', shop: c.sid, mid: m.mid, matiere: m.nom, prix: p }); }
  } else if (l.regle === 'sans') {
    const cand = (S.vrCand || {})[cle];
    if (!cand) { setTimeout(() => chargerCandidats(app, l), 0); }
    const rid = num(sais.rid);
    champs.push({ lib: 'Recette n°', val: sais.rid == null ? '' : sais.rid, poser: e => poser('rid', e.target.value), id: 'vr-r-' + cle });
    champs.candidats = cand && cand.d ? (cand.d.candidats || []).map(x => ({ rid: x.rid, nom: x.nom, libre: x.libre, pareil: x.pareil, on: rid === x.rid, choisir: () => poser('rid', String(x.rid)) })) : null;
    champs.candMotif = cand && cand.d ? (cand.d.motif || ((cand.d.candidats || []).length ? '' : 'Aucune recette de nom proche au panel : à créer au panel.')) : (cand ? 'Recherche des recettes de nom proche…' : '');
    if (!(rid > 0)) { erreur = 'Choisissez une recette, ou saisissez son numéro.'; } else { corps = Object.assign(base, { geste: 'rattacher', rid }); }
  } else if (l.regle === 'reseau') {
    corps = Object.assign(base, { geste: 'recalculer', shop: c.sid, coutAvant: c.cout });
  } else if (l.regle === 'orph') {
    corps = Object.assign(base, { geste: 'horsRecette', pid: null });
  }
  const verifier = corps ? () => {
    app.setState({ vrPrev: Object.assign({}, app.state.vrPrev, { [cle]: { enCours: true } }) });
    ecrire(app, ROUTE + 'corriger', Object.assign({}, corps, { apercu: true })).then(r => app.setState({ vrPrev: Object.assign({}, app.state.vrPrev, { [cle]: { d: r } }) }));
  } : null;
  const pret = prev && prev.d && prev.d.apercu;
  const appliquer = corps && pret ? () => {
    app.setState({ vrRes: Object.assign({}, app.state.vrRes, { [cle]: { enCours: true } }) });
    ecrire(app, ROUTE + 'corriger', corps).then(r => apresEcriture(app, cle, r, rafraichir));
  } : null;
  const fermer = () => app.setState({ vrOuvert: null });
  return {
    champs, erreur: corps ? null : erreur, verifier, appliquer, fermer, enCours: !!(prev && prev.enCours) || !!(res && res.enCours),
    apercu: pret ? { resume: prev.d.resume, ecritures: (prev.d.ecritures || []).map(e => ({ route: e.methode + ' ' + e.route, corps: JSON.stringify(e.corps, null, 1).slice(0, 4000), annulable: e.annulable, resume: e.resume || '' })) } : null,
    refus: prev && prev.d && !prev.d.apercu ? (prev.d.error || 'Le cockpit n’a pas pu préparer cette correction.') : null,
    resultat: res && res.d ? resultatDe(res.d) : null,
  };
}

/** Ce qu'une écriture a donné, en une phrase. */
function resultatDe(r){
  const e = (r.ecritures || [])[0];
  if (!e) { return { ok: false, txt: r.error || 'Pas de réponse du cockpit.' }; }
  return { ok: e.resultat === 'ok' || e.resultat === 'local', txt: e.resume + ' — ' + e.resultatNom + (e.message ? ' : ' + e.message : '') };
}

function chargerCandidats(app, l){
  const S = app.state;
  if ((S.vrCand || {})[l.cle]) { return; }
  app.setState({ vrCand: Object.assign({}, S.vrCand, { [l.cle]: { chargement: true } }) });
  readOne(ROUTE + 'candidats?nom=' + encodeURIComponent(l.nom || '') + '&pid=' + encodeURIComponent(l.pid || '')).then(d => {
    app.setState({ vrCand: Object.assign({}, app.state.vrCand, { [l.cle]: { d: d || { candidats: [], motif: 'Le cockpit n’a pas répondu.' } } }) });
  });
}

/** Après une écriture : la liste à jour, la correction au fil des corrections récentes. */
function apresEcriture(app, cle, r, rafraichir){
  const S = app.state;
  const maj = { vrRes: Object.assign({}, S.vrRes, { [cle]: { d: r } }), vrPrev: Object.assign({}, S.vrPrev, { [cle]: null }),
    vrRecentes: (r.ecritures || []).concat(S.vrRecentes || []).slice(0, 12), vrReg: null };
  if (r.liste) { maj.vrData = { chargement: false, d: r.liste }; }
  if (r.ok) { maj.vrOuvert = null; }
  app.setState(maj);
  if (r.ok && rafraichir) { rafraichir(); }
}

/** Annuler une correction : l'avant repart au panel par la même route. */
function annuler(app, id){
  const S = app.state;
  app.setState({ vrAnnul: Object.assign({}, S.vrAnnul, { [id]: { enCours: true } }) });
  ecrire(app, ROUTE + 'annuler', { id }).then(r => {
    const maj = { vrAnnul: Object.assign({}, app.state.vrAnnul, { [id]: { d: r } }), vrReg: null };
    if (r.ok) {
      maj.vrRecentes = (r.ecritures || []).concat((app.state.vrRecentes || []).map(e => e.id === id ? Object.assign({}, e, { annulable: false, annulePar: (r.ecritures || [])[0] && r.ecritures[0].id }) : e)).slice(0, 12);
      if (r.liste) { maj.vrData = { chargement: false, d: r.liste }; }
    }
    app.setState(maj);
  });
}

/** Une ligne du registre telle que l'écran la montre. */
function ligneReg(app, e, L){
  const A = (app.state.vrAnnul || {})[e.id];
  const lien = e.pid ? lienPanel(L.produit, { pid: e.pid }) : null;
  return { id: e.id, quand: heure(e.le), nom: e.nom, resume: e.resume, route: e.methode ? e.methode + ' ' + e.route : 'cockpit seulement',
    ok: e.resultat === 'ok' || e.resultat === 'local', annule: e.resultat === 'annule' || !!e.annuleDe || !!e.annulePar, resultat: e.resultatNom, message: e.message,
    cout: e.coutAvant != null || e.coutApres != null ? fE(e.coutAvant) + ' → ' + fE(e.coutApres) : '',
    annuler: e.annulable && !(A && A.d && A.d.ok) ? () => annuler(app, e.id) : null, annulEnCours: !!(A && A.enCours), annulErreur: A && A.d && !A.d.ok ? (A.d.error || 'L’annulation n’a pas pris.') : null,
    lien: e.resultat === 'refuse' || e.resultat === 'sans_effet' ? lien : null };
}

/** Les corrections de cette visite, en tête de la liste. */
export function valsRecentes(app, L){
  return (app.state.vrRecentes || []).map(e => ligneReg(app, e, L));
}

/** Le registre complet, replié sous la liste ; les adresses du panel s'y règlent. */
export function valsRegistre(app, L){
  const S = app.state, R = S.vrReg;
  const ouvert = !!S.vrRegOuvert;
  const basculer = () => {
    app.setState({ vrRegOuvert: !ouvert });
    if (!ouvert && !(R && (R.d || R.chargement))) { chargerRegistre(app); }
  };
  if (ouvert && !R) { setTimeout(() => chargerRegistre(app), 0); }
  const d = R && R.d;
  const E = S.vrLiensEd;
  const cles = [['produit', 'Fiche produit', '{pid}'], ['recette', 'Recette', '{rid}'], ['sousRecette', 'Sous-recette', '{sous}'], ['matiere', 'Matière', '{mid}'], ['portion', 'Portion', '{pid}']];
  const liens = d ? {
    ouvert: !!E, ouvrir: () => app.setState({ vrLiensEd: Object.assign({}, d.liens || {}, { prixRoute: (d.formatPrix || {}).route, prixMethode: (d.formatPrix || {}).methode, prixCorps: JSON.stringify((d.formatPrix || {}).corps || {}) }) }),
    fermer: () => app.setState({ vrLiensEd: null }),
    champs: E ? cles.map(([k, lib, ph]) => ({ k, lib, ph, val: E[k] || '', poser: e => app.setState({ vrLiensEd: Object.assign({}, app.state.vrLiensEd, { [k]: e.target.value }) }) })) : [],
    prix: E ? { route: E.prixRoute || '', methode: E.prixMethode || 'POST', corps: E.prixCorps || '{}',
      poser: k => e => app.setState({ vrLiensEd: Object.assign({}, app.state.vrLiensEd, { [k]: e.target.value }) }) } : null,
    erreur: (S.vrLiensRes || {}).error || null,
    enregistrer: E ? () => {
      let corpsPrix = null; try { corpsPrix = JSON.parse(E.prixCorps || '{}'); } catch (x) { app.setState({ vrLiensRes: { error: 'Le corps du prix n’est pas un JSON valable.' } }); return; }
      const corps = {}; cles.forEach(([k]) => { corps[k] = E[k] || ''; });
      corps.formatPrix = { route: E.prixRoute || '', methode: E.prixMethode || 'POST', corps: corpsPrix };
      ecrire(app, ROUTE + 'liens', corps, 'PUT').then(r => {
        if (r.ok) { app.setState({ vrLiensEd: null, vrLiensRes: null, vrReg: null }); chargerRegistre(app); app.setState({ vrData: Object.assign({}, app.state.vrData, { d: Object.assign({}, (app.state.vrData || {}).d, { liens: r.liens }) }) }); }
        else { app.setState({ vrLiensRes: { error: r.error || 'Non enregistré.' } }); }
      });
    } : null,
  } : null;
  return { ouvert, basculer, chargement: !!(R && R.chargement), lignes: d ? (d.lignes || []).map(e => ligneReg(app, e, L)) : [], n: d ? (d.lignes || []).length : null, liens };
}

function chargerRegistre(app){
  app.setState({ vrReg: { chargement: true } });
  readOne(ROUTE + 'registre').then(d => app.setState({ vrReg: { d: d || { lignes: [] } } }));
}

/* ── Le volet en correction ──────────────────────────────────────────────── */

/** Ouvre le volet d'un produit en correction. */
export function ouvrirEnCorrection(app, l, ouvrirFiche){
  app.setState({ vrFicheMode: 'corriger', vrEd: null });
  ouvrirFiche(l.pid);
  chargerEdition(app, l.pid, l.rid);
}

export function chargerEdition(app, pid, rid){
  if (!rid) { return; }
  app.setState({ vrEd: { pid, rid, chargement: true } });
  readOne(ROUTE + 'edition?rid=' + encodeURIComponent(rid) + '&pid=' + encodeURIComponent(pid || '')).then(d => {
    const E = app.state.vrEd;
    if (!E || E.rid !== rid) { return; }
    app.setState({ vrEd: { pid, rid, d: d || { ok: false, motif: 'Le cockpit n’a pas répondu.' }, q: {}, ret: {}, aj: [], rend: null, copie: null, ajout: { mid: '', q: '' } } });
  });
}

/** Le coût d'un objet de l'arbre pour une pièce, avant et après les changements saisis. */
function couts(E, objets){
  const parCle = {}; objets.forEach(o => { parCle[o.type + ':' + o.id] = o; });
  const memo = {};
  const cout = (o, apres, prof) => {
    const k = o.type + ':' + o.id + (apres ? ':a' : ':b');
    if (memo[k] != null) { return memo[k]; }
    let t = 0, connu = true;
    (o.lignes || []).forEach(l => {
      if (l.coutPiece == null) { connu = false; return; }
      if (!apres) { t += l.coutPiece; return; }
      const kk = o.type + ':' + o.id + '|' + l.cle;
      if (E.ret[kk]) { return; }
      const q = E.q[kk] != null ? num(E.q[kk]) : l.q;
      t += l.q > 0 && q != null && !isNaN(q) ? l.coutPiece * q / l.q : l.coutPiece;
    });
    (o.sous || []).forEach(s => {
      const so = parCle['sousRecette:' + s.id];
      if (!so || prof > 4) { return; }
      const kk = o.type + ':' + o.id + '|' + s.cle;
      if (apres && E.ret[kk]) { return; }
      const base = cout(so, apres, prof + 1);
      const q = apres && E.q[kk] != null ? num(E.q[kk]) : s.q;
      t += apres && s.q > 0 && q != null && !isNaN(q) ? base * q / s.q : base;
    });
    memo[k] = connu ? t : t;   // une ligne sans coût compte pour zéro : l'estimation le dit
    return memo[k];
  };
  const R = objets[0];
  const avant = cout(R, false, 0);
  let apres = cout(R, true, 0);
  const r0 = R.rendement > 0 ? R.rendement : 1;
  const r1 = E.rend != null && num(E.rend) > 0 ? num(E.rend) : r0;
  apres = apres * r0 / r1;
  return { avant, apres };
}

/** Les valeurs du volet en correction. `F` : la fiche déjà lue (magasins, coûts, prix). */
export function valsEdition(app, F){
  const S = app.state, E = S.vrEd;
  if (!E) { return null; }
  if (E.chargement) { return { chargement: true }; }
  const d = E.d || {};
  if (!d.ok) { return { erreur: d.motif || d.error || 'La recette n’a pas pu être lue au panel.' }; }
  const maj = patch => app.setState({ vrEd: Object.assign({}, app.state.vrEd, patch, { prev: null }) });
  const L = d.liens || {};
  const objets = d.objets || [];
  const R = objets[0] || {};
  const { avant, apres } = couts(E, objets);
  // Le coût d'une sous-recette pour une pièce : la somme de ses lignes (et de ses propres sous-recettes).
  const coutSous = (id, prof) => {
    const so = objets.find(x => x.type === 'sousRecette' && x.id === id);
    if (!so || prof > 4) { return null; }
    let t = 0; (so.lignes || []).forEach(l => { t += l.coutPiece || 0; }); (so.sous || []).forEach(z => { t += coutSous(z.id, prof + 1) || 0; });
    return t;
  };
  const lignesDe = o => (o.lignes || []).map(l => ({ cle: l.cle, nom: l.nom, u: l.u, sous: false, coutPiece: l.coutPiece, q: l.q, o })).concat((o.sous || []).map(s => ({ cle: s.cle, nom: s.nom, u: s.u, sous: true, coutPiece: coutSous(s.id, 0), q: s.q, o })));
  const blocs = objets.map(o => ({
    titre: (o.type === 'recette' ? 'La recette « ' : 'Sous-recette « ') + (o.nom || o.id) + ' »', modifiable: o.modifiable, cles: o.modifiable ? '' : (o.cles || []).join(', '),
    lien: o.type === 'recette' ? lienPanel(L.recette, { rid: o.id, pid: d.pid }) || L.recette : null,
    lignes: lignesDe(o).map(l => {
      const kk = o.type + ':' + o.id + '|' + l.cle;
      const v = E.q[kk] != null ? E.q[kk] : (l.q == null ? '' : String(+Number(l.q).toFixed(6)).replace('.', ','));
      const qn = num(v), retire = !!E.ret[kk];
      const change = !retire && E.q[kk] != null && qn != null && !isNaN(qn) && Math.abs(qn - (l.q || 0)) > 1e-9;
      const coutApres = l.coutPiece == null ? null : (retire ? 0 : (l.q > 0 && qn > 0 ? l.coutPiece * qn / l.q : l.coutPiece));
      return { nom: l.nom, sous: l.sous, u: l.u, val: v, id: 'vr-ed-' + kk.replace(/[^\w]/g, '-'), change, retire, invalide: qn == null || isNaN(qn) || qn <= 0,
        qAvant: qte(l.q, l.u), coutAvant: l.coutPiece == null ? '—' : fE(l.coutPiece, 3), coutApres: coutApres == null ? '—' : fE(coutApres, 3),
        lourde: avant > 0 && (l.coutPiece || 0) > 0.5 * avant,
        poser: o.modifiable ? e => maj({ q: Object.assign({}, app.state.vrEd.q, { [kk]: e.target.value }) }) : null,
        retirer: o.modifiable ? () => maj({ ret: Object.assign({}, app.state.vrEd.ret, { [kk]: !app.state.vrEd.ret[kk] }) }) : null };
    }),
  }));
  // Les changements à envoyer.
  const changements = [];
  Object.keys(E.q).forEach(kk => {
    if (E.ret[kk]) { return; }
    const [ok, cle] = kk.split('|'); const [objet, id] = ok.split(':');
    const o = objets.find(x => x.type === objet && String(x.id) === id);
    const ligne = o && (o.lignes || []).concat(o.sous || []).find(x => x.cle === cle);
    const q = num(E.q[kk]);
    if (ligne && q > 0 && Math.abs(q - (ligne.q || 0)) > 1e-9) { changements.push({ type: 'qte', objet, id: Number(id), cle, q }); }
  });
  Object.keys(E.ret).forEach(kk => { if (E.ret[kk]) { const [ok, cle] = kk.split('|'); const [objet, id] = ok.split(':'); changements.push({ type: 'retirer', objet, id: Number(id), cle }); } });
  (E.aj || []).forEach(a => changements.push({ type: 'ajouter', objet: 'recette', id: d.rid, mid: a.mid, source: a.source, q: a.q }));
  const rendN = E.rend != null ? num(E.rend) : null;
  if (rendN > 0 && Math.abs(rendN - (R.rendement || 0)) > 1e-9) { changements.push({ type: 'rendement', objet: 'recette', id: d.rid, n: rendN }); }
  const invalides = blocs.some(b => b.lignes.some(l => l.invalide && !l.retire)) || (E.rend != null && !(rendN > 0));
  const copie = E.copie ? (d.modeles || []).find(m => m.rid === E.copie) : null;
  const corps = copie ? { geste: 'copier', rid: d.rid, pid: d.pid, source: copie.rid, nom: F && F.nom }
    : (changements.length ? { geste: 'recette', rid: d.rid, pid: d.pid, nom: F && F.nom, changements, coutAvant: avant, coutApres: apres } : null);
  const prev = E.prev, res = E.res;
  const pret = prev && prev.d && prev.d.apercu;
  // L'ajout d'une matière : choisie dans les matières des recettes déjà lues.
  const A = E.ajout || { mid: '', q: '' };
  const mAj = (d.matieres || []).find(m => String(m.mid) === String(A.mid));
  const qAj = num(A.q);
  const ratio = avant > 0 ? apres / avant : 1;
  return {
    chargement: false, blocs, lienRecette: lienPanel(L.recette, { rid: d.rid, pid: d.pid }), liens: Object.keys(L).map(k => ({ txt: { produit: 'Produit', recette: 'Recette ' + d.rid, sousRecette: 'Sous-recettes', matiere: 'Matières', portion: 'Portions' }[k] || k, url: L[k] })),
    rendement: { val: E.rend != null ? E.rend : (R.rendement == null ? '' : String(R.rendement).replace('.', ',')), avant: R.rendement, change: rendN > 0 && Math.abs(rendN - (R.rendement || 0)) > 1e-9,
      poser: R.modifiable ? e => maj({ rend: e.target.value }) : null },
    total: { avant: fE(avant, 3), apres: fE(apres, 3), change: Math.abs(apres - avant) > 1e-6 || changements.length > 0, estime: changements.some(c => c.type === 'ajouter') },
    magasins: F && F.magasinsBruts ? F.magasinsBruts.filter(m => m.present).map(m => ({ nom: m.nom, avant: fE(m.cout), apres: m.cout == null ? '—' : fE(m.cout * ratio), prix: fE(m.prixHt),
      food: m.cout != null && m.prixHt ? nf(100 * m.cout * ratio / m.prixHt, 0) + ' %' : '—', ton: m.cout != null && m.prixHt ? ((100 * m.cout * ratio / m.prixHt) >= 100 || (100 * m.cout * ratio / m.prixHt) < 5 ? 'ko' : ((100 * m.cout * ratio / m.prixHt) > 40 ? 'att' : 'ok')) : 'mu' })) : [],
    ajout: (d.matieres || []).length && R.modifiable ? { matieres: d.matieres.map(m => ({ id: m.mid, nom: m.nom + (m.u ? ' (' + m.u + ')' : ''), on: String(m.mid) === String(A.mid) })), q: A.q, u: mMaj(mAj),
      poserM: e => maj({ ajout: Object.assign({}, A, { mid: e.target.value }) }), poserQ: e => maj({ ajout: Object.assign({}, app.state.vrEd.ajout, { q: e.target.value }) }),
      ajouter: mAj && qAj > 0 ? () => maj({ aj: (app.state.vrEd.aj || []).concat([{ mid: mAj.mid, nom: mAj.nom, u: mAj.u, source: mAj.source, q: qAj }]), ajout: { mid: '', q: '' } }) : null } : null,
    ajouts: (E.aj || []).map((a, i) => ({ nom: a.nom, q: qte(a.q, a.u), retirer: () => maj({ aj: app.state.vrEd.aj.filter((x, j) => j !== i) }) })),
    modeles: (d.modeles || []).length ? (d.modeles || []).map(m => ({ rid: m.rid, nom: m.nom + ' — ' + m.lignes + ' ligne' + (m.lignes > 1 ? 's' : ''), on: E.copie === m.rid, choisir: () => maj({ copie: app.state.vrEd.copie === m.rid ? null : m.rid }) })) : null,
    nChangements: copie ? 1 : changements.length, copie: copie ? copie.nom : null, invalide: invalides,
    annulerTout: () => maj({ q: {}, ret: {}, aj: [], rend: null, copie: null, res: null }),
    verifier: corps && !invalides ? () => {
      app.setState({ vrEd: Object.assign({}, app.state.vrEd, { prev: { enCours: true } }) });
      ecrire(app, ROUTE + 'corriger', Object.assign({}, corps, { apercu: true })).then(r => app.setState({ vrEd: Object.assign({}, app.state.vrEd, { prev: { d: r } }) }));
    } : null,
    apercu: pret ? { resume: prev.d.resume, ecritures: (prev.d.ecritures || []).map(e => ({ route: e.methode + ' ' + e.route, corps: JSON.stringify(e.corps, null, 1).slice(0, 6000), resume: e.resume || '' })) } : null,
    refus: prev && prev.d && !prev.d.apercu ? (prev.d.error || 'Le cockpit n’a pas pu préparer cette correction.') : null,
    enCours: !!(prev && prev.enCours) || !!(res && res.enCours),
    enregistrer: corps && pret ? () => {
      app.setState({ vrEd: Object.assign({}, app.state.vrEd, { res: { enCours: true } }) });
      ecrire(app, ROUTE + 'corriger', corps).then(r => {
        const maj2 = { vrEd: Object.assign({}, app.state.vrEd, { res: { d: r }, prev: null }), vrRecentes: (r.ecritures || []).concat(app.state.vrRecentes || []).slice(0, 12), vrReg: null };
        if (r.liste) { maj2.vrData = { chargement: false, d: r.liste }; }
        app.setState(maj2);
        if (r.ok) { chargerEdition(app, d.pid, d.rid); if (F && F.relire) { F.relire(); } }
      });
    } : null,
    resultat: res && res.d ? { ok: !!res.d.ok, lignes: (res.d.ecritures || []).map(e => e.resume + ' — ' + e.resultatNom + (e.message ? ' : ' + e.message : '')), erreur: res.d.ecritures && res.d.ecritures.length ? null : (res.d.error || null) } : null,
  };
}
const mMaj = m => m ? m.u || '' : '';
