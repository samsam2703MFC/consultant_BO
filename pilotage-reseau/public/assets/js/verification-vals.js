/* Cockpit CEO — Vérification › Recettes à corriger (#/verification/recettes, lot 1, 31/10/2026).
 * Plan : docs/plan-verification.md § 3.5 ; maquette : docs/maquettes/verification/e.png.
 *
 * GET /verification/recettes rend la liste des recettes douteuses ou manquantes (neuf règles), classée par CA
 * concerné sur trente jours, la couverture (la part du CA qui passe par une recette fiable) et l'état de la lecture
 * des recettes (le cron horaire en lit un lot à chaque passage). Le serveur garde l'analyse une heure ; « Relire »
 * la recalcule et lit un petit lot de recettes de plus.
 *
 * Remplit `common.vr` ; le gabarit est tplVerification (verification-tpl.js), le style verification.css (vr-).
 * La liste est pour le CEO seul (décision du 31/10/2026) : pas d'envoi, un export CSV.
 * Corriger d'ici (13/11/2026, « tout, CEO seul ») : chaque ligne porte son geste — corrigé en place, dans le volet de la
 * recette, ou au panel par un lien direct — et le registre garde ce qui a été écrit (verification-correction-vals.js). */
import { readOne } from './api.js';
import { gesteDe, valsLigneOuverte, valsRecentes, valsRegistre, valsEdition, ouvrirEnCorrection, chargerEdition, lienPanel } from './verification-correction-vals.js';

const nf = (n, d) => n == null ? '—' : Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d || 0, maximumFractionDigits: d || 0 }).replace(/[\u202f\u00a0]/g, '\u00a0');
const fE = (n, d) => n == null ? '—' : nf(n, d) + ' €';
const pc = (n, d) => n == null ? '—' : nf(n, d == null ? 1 : d) + ' %';
const CLASSES = { sans: 'pb-sans', form: 'pb-form', vide: 'pb-vide', prix: 'pb-prix', inv: 'pb-inv', reseau: 'pb-reseau', unite: 'pb-unite', rend: 'pb-rend', port: 'pb-port', orph: 'pb-orph' };
const jour = s => { const d = new Date(String(s || '').slice(0, 10) + 'T12:00:00'); return isNaN(d) ? null : d; };
const jjmm = s => { const d = jour(s); return d ? String(d.getDate()).padStart(2, '0') + '/' + String(d.getMonth() + 1).padStart(2, '0') : ''; };
const heure = s => { const d = new Date(s || ''); return isNaN(d) ? '' : String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0'); };

function vrCharge(app, force){
  const S = app.state, b = S.vrData;
  if (!force && b && (b.d || b.chargement)) { return; }
  if (app._vrEnCours && !force) { return; }
  app._vrEnCours = true;
  app.setState({ vrData: { chargement: true, d: b ? b.d : null } });
  readOne('/verification/recettes' + (force ? '?rafraichir=1' : '')).then(d => {
    app._vrEnCours = false;
    app.setState({ vrData: { chargement: false, d: d || { error: 'injoignable' } } });
  });
}

/** Le CSV de la liste filtrée (séparateur « ; », BOM pour Excel). */
function exporter(lignes, d){
  const q = v => '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"';
  const t = [['Rang', 'Produit ou matière', 'Catégorie', 'Problème', 'Ce qui cloche', 'CA concerné (30 j)', 'Valeur en stock', 'Magasins', 'Repérée depuis', 'Recette'].map(q).join(';')];
  lignes.forEach(l => t.push([l.rang, l.nom, l.cat, l.nomRegle, l.detail, l.caBrut == null ? '' : String(l.caBrut).replace('.', ','), l.valeurBrute == null ? '' : String(l.valeurBrute).replace('.', ','),
    l.magasinsTxt, l.depuisDate, l.rid || ''].map(q).join(';')));
  const blob = new Blob(['﻿' + t.join('\r\n')], { type: 'text/csv;charset=utf-8' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'recettes-a-corriger-' + String((d && d.lu) || new Date().toISOString()).slice(0, 10) + '.csv';
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 2000);
}

/** La fiche d'un produit (sa recette, ses prix, ses signalements) : ouverte au clic dans la liste. */
function vrFiche(app, pid){
  const avant = app.state.vrFiche;
  app.setState({ vrFiche: { pid, chargement: !(avant && avant.pid === pid && avant.d), d: avant && avant.pid === pid ? avant.d : null } });
  readOne('/verification/recettes/fiche?pid=' + encodeURIComponent(pid)).then(d => {
    if (((app.state.vrFiche || {}).pid) !== pid) { return; }
    app.setState({ vrFiche: { pid, chargement: false, d: d || { error: 'injoignable' } } });
  });
}

/** Les valeurs de la fiche ouverte. */
function valsFiche(app){
  const F = app.state.vrFiche;
  if (!F) { return null; }
  const fermer = () => app.setState({ vrFiche: null, vrEd: null, vrFicheMode: 'lire' });
  if (F.chargement || !F.d) { return { chargement: true, fermer }; }
  const d = F.d;
  if (d.error || d.indispo) { return { erreur: d.motif || 'La fiche n’a pas pu être lue.', fermer }; }
  const mags = d.magasins || [];
  const r = d.recette;
  const mode = d.rid && app.state.vrFicheMode === 'corriger' ? 'corriger' : 'lire';
  const E = app.state.vrEd;
  if (mode === 'corriger' && (!E || E.rid !== d.rid)) { setTimeout(() => chargerEdition(app, d.pid, d.rid), 0); }
  const A = ((app.state.vrData || {}).d || {}).liens || {};
  const out = {
    fermer, nom: d.nom || ('Produit ' + d.pid), cat: d.cat || '', rid: d.rid, lien: d.lienPanel || null,
    mode, lire: () => app.setState({ vrFicheMode: 'lire' }), corriger: d.rid ? () => app.setState({ vrFicheMode: 'corriger' }) : null,
    liens: [['Produit', lienPanel(A.produit, { pid: d.pid })], ['Recette ' + (d.rid || ''), d.rid ? lienPanel(A.recette, { rid: d.rid, pid: d.pid }) : null]].filter(z => z[1]).map(([txt, url]) => ({ txt, url })),
    magasinsBruts: mags, relire: () => vrFiche(app, d.pid),
    signalements: (d.signalements || []).map(l => ({ nom: l.nomRegle, classe: CLASSES[l.regle] || 'pb-orph', detail: l.detail, magasins: (l.magasins || []).join(' · ') })),
    magasins: mags.map(m => ({ nom: m.nom, present: m.present, cout: m.cout == null ? '—' : fE(m.cout, 2), prix: m.prixHt == null ? '—' : fE(m.prixHt, 2), coef: m.coef == null ? '—' : '×' + nf(m.coef, 2),
      food: m.food == null ? '—' : pc(m.food), ton: m.food == null ? 'mu' : (m.food >= 100 || m.food < 5 ? 'ko' : (m.food > 40 ? 'att' : 'ok')),
      portion: m.divisible ? (m.part > 0 && m.part < 1 ? 'portion ' + nf(m.part * 100) + ' %' : 'portion sans part') : '' })),
    entetes: mags.map(m => m.nom),
    recette: r ? { nom: r.nom, rendement: r.rendement == null ? '1' : nf(r.rendement, 2), total: fE(r.total, 2), luDans: r.luDans || '', complet: r.complet,
      lignes: (r.lignes || []).map(l => ({ nom: l.nom || ('matière ' + l.mid), sous: l.sous || '', qte: l.q == null ? '—' : nf(l.q, l.q < 1 ? 3 : 2) + ' ' + (l.u || ''),
        cout: l.cout == null ? '—' : fE(l.cout, 3), part: l.part == null ? '' : pc(l.part, 0), lourde: (l.part || 0) > 50,
        prix: mags.map(m => { const p = (l.prixMagasins || {})[m.id]; return p === 'inconnu' ? { txt: '?', ton: 'mu' } : (p == null ? { txt: 'sans prix', ton: 'ko' } : { txt: nf(p, p < 1 ? 4 : 2), ton: '' }); }) })) } : null,
  };
  out.edition = mode === 'corriger' ? valsEdition(app, out) : null;
  return out;
}

/** Les liens directs d'une ligne (produit, recette, matière en cause), par les modèles d'adresse du panel. */
function liensLigne(l, L){
  const out = [];
  const add = (txt, modele, ids) => { const u = lienPanel(modele, ids); if (u && !out.some(o => o.url === u)) { out.push({ txt, url: u }); } };
  if (l.pid) { add('Produit', L.produit, { pid: l.pid }); }
  if (l.rid) { add('Recette ' + l.rid, L.recette, { rid: l.rid, pid: l.pid }); }
  const c = l.corr || {};
  if (c.mid) { add(c.matiere || 'Matière', L.matiere, { mid: c.mid }); }
  (c.matieres || []).slice(0, 2).forEach(m => add(m.nom, L.matiere, { mid: m.mid }));
  if (l.regle === 'orph' && l.mid) { add('Matière', L.matiere, { mid: l.mid }); }
  if (l.regle === 'port' && l.pid) { add('Portion', L.portion, { pid: l.pid }); }
  return out;
}

export function valsVerification(app, common){
  const S = app.state;
  vrCharge(app, false);
  const b = S.vrData || {};
  const brut = b.d || null;
  const d = brut && !brut.error && !brut.indispo ? brut : null;
  const regle = S.vrRegle || '', shop = S.vrShop || '', tri = S.vrTri || 'ca';
  const auj = jour(new Date().toISOString());
  const v = {
    chargement: !!b.chargement && !d, relecture: !!b.chargement && !!d,
    erreur: brut && brut.error ? 'Le serveur du cockpit n’a pas répondu : réessayez dans un instant.' : null,
    indispo: brut && brut.indispo ? brut.motif : null,
    rafraichir: () => vrCharge(app, true),
    fiche: valsFiche(app),
  };
  common.vr = v;
  if (!d) { return; }

  // Les formules et promotions (décision du 31/10/2026) : gardées, un interrupteur devant la liste les masque ;
  // éteint, la couverture se lit sans elles.
  const avecF = S.vrFormules !== false;
  const toutes = d.lignes || [];
  const tous = avecF ? toutes : toutes.filter(l => !l.formule);
  const dansShop = shop ? tous.filter(l => (l.shops || []).indexOf(shop) >= 0) : tous;
  const n = {}; dansShop.forEach(l => { n[l.regle] = (n[l.regle] || 0) + 1; });
  let L = regle ? dansShop.filter(l => l.regle === regle) : dansShop.slice();
  if (tri === 'depuis') { L.sort((a, b2) => String(a.depuis || '').localeCompare(String(b2.depuis || '')) || (b2.ca || 0) - (a.ca || 0)); }
  else if (tri === 'nom') { L.sort((a, b2) => String(a.nom).localeCompare(String(b2.nom), 'fr')); }
  const lien = (pid, rid) => d.lienRecette && pid ? d.lienRecette.replace('{pid}', pid).replace('{id}', pid).replace('{rid}', rid || '') : null;
  const mags = d.magasins || [];
  const LI = d.liens || { produit: d.lienRecette };
  const rafraichirFiche = () => { const F = app.state.vrFiche; if (F && F.pid) { vrFiche(app, F.pid); } };
  v.lignes = L.map((l, i) => {
    const dj = jour(l.depuis);
    const jours = dj && auj ? Math.max(0, Math.round((auj - dj) / 864e5)) : null;
    return { rang: i + 1, haut: i < 5 && l.regle !== 'orph', nom: l.nom || ('Produit ' + (l.pid || '')), cat: l.regle === 'orph' ? 'matière première' + (l.cat ? ' · ' + l.cat : '') : (l.cat || ''),
      regle: l.regle, nomRegle: l.nomRegle, classe: CLASSES[l.regle] || 'pb-orph', detail: l.detail,
      ca: l.ca > 0 ? fE(l.ca) : null, caBrut: l.ca > 0 ? l.ca : null, valeur: l.valeur ? fE(l.valeur) + ' en stock' : null, valeurBrute: l.valeur || null,
      magasinsTxt: (l.magasins || []).length === mags.length && mags.length > 2 ? 'les ' + mags.length + ' magasins' : (l.magasins || []).join(' · '),
      depuis: jours == null ? '' : (jours === 0 ? 'aujourd’hui' : jours + ' j'), depuisDate: l.depuis || '', depuisTitre: l.depuis ? 'repérée le ' + jjmm(l.depuis) : '',
      rid: l.rid || null, lien: lien(l.pid, l.rid), formule: !!l.formule, pid: l.pid || null,
      ouvrir: l.pid ? () => { app.setState({ vrFicheMode: 'lire', vrEd: null }); vrFiche(app, l.pid); } : null };
  }).map((x, i) => {
    // Le geste de la ligne (13/11/2026) : corriger en place, dans le volet, ou au panel.
    const l = L[i];
    const brut = Object.assign({}, l, { cle: l.cle, nom: x.nom });
    const g = gesteDe(brut);
    const ouvert = S.vrOuvert === l.cle;
    const liens = liensLigne(brut, LI);
    return Object.assign(x, { cle: l.cle, geste: g, liens, ouvert,
      agir: !g ? null : (g.type === 'volet' ? () => ouvrirEnCorrection(app, brut, pid => vrFiche(app, pid)) : () => app.setState({ vrOuvert: ouvert ? null : l.cle })),
      corrigerVolet: g && g.volet && l.rid ? () => ouvrirEnCorrection(app, brut, pid => vrFiche(app, pid)) : null,
      ouverte: ouvert && g && g.type !== 'volet' && g.type !== 'panel' ? valsLigneOuverte(app, brut, d, rafraichirFiche) : null });
  });
  v.recentes = valsRecentes(app, LI);
  v.registre = valsRegistre(app, LI);
  v.formules = { on: avecF, n: (shop ? toutes.filter(l => (l.shops || []).indexOf(shop) >= 0) : toutes).filter(l => l.formule).length,
    ca: fE(((d.couverture || {}).formules || {}).ca), basculer: () => app.setState({ vrFormules: !avecF }) };
  v.vide = tous.length === 0;
  v.videFiltre = !v.vide && v.lignes.length === 0;
  v.total = tous.length;
  v.chips = [{ code: '', nom: 'Toutes', n: dansShop.length, on: !regle, classe: '' }]
    .concat((d.regles || []).map(r => ({ code: r.code, nom: r.nom, n: n[r.code] || 0, on: regle === r.code, classe: CLASSES[r.code] || '', titre: r.explication })))
    .map(c => Object.assign(c, { choisir: () => app.setState({ vrRegle: c.code === regle && c.code ? '' : c.code }) }));
  v.magasins = [{ id: '', nom: 'Tous les magasins', on: !shop }].concat(mags.map(m => ({ id: String(m.id), nom: m.nom, on: shop === String(m.id) })));
  v.choisirShop = id => app.setState({ vrShop: id });
  v.tris = [['ca', 'Classées par CA concerné (30 jours)'], ['depuis', 'Les plus anciennes d’abord'], ['nom', 'Par nom']].map(([id, nom]) => ({ id, nom, on: tri === id }));
  v.choisirTri = id => app.setState({ vrTri: id });
  v.exporter = () => exporter(v.lignes, d);

  // La couverture : la part du CA qui passe par une recette fiable, vers 100 %.
  const c = avecF ? (d.couverture || {}) : Object.assign({}, (d.couverture || {}).horsFormules || d.couverture || {});
  const pct = c.pct;
  v.cov = { pct: pct == null ? '—' : pc(pct), largeur: pct == null ? 0 : Math.max(0, Math.min(100, pct)),
    ton: pct == null ? 'mu' : (pct >= 97 ? 'ok' : (pct >= 90 ? 'att' : 'ko')),
    manque: pc(pct == null ? null : 100 - pct), caManquant: fE(c.caManquant), caTotal: fE(c.caTotal), jours: (d.periode || {}).jours || 30,
    partTop10: c.partTop10 == null ? null : nf(c.partTop10) + ' %', aRelire: c.caARelire > 0 ? fE(c.caARelire) : null, produits: c.produits || 0,
    horsFormules: !avecF };
  const r = d.recettes || {};
  v.lecture = { vendues: r.vendues || 0, lues: r.lues || 0, aLire: r.aLire || 0, complet: !!r.complet, orph: !!r.orphJugees };
  v.corrigees = d.corrigees && d.corrigees.n ? { n: d.corrigees.n, depuis: jjmm(d.corrigees.depuis) } : null;
  const p = d.periode || {};
  v.periode = p.du ? 'du ' + jjmm(p.du) + ' au ' + jjmm(p.au) : '';
  v.lu = d.cache && d.cache.le ? 'calculée à ' + heure(d.cache.le) : (d.lu ? 'calculée à ' + heure(d.lu) : '');
  v.joursManquants = mags.filter(m => m.joursLus < ((d.periode || {}).jours || 30) - 1).map(m => m.nom + ' (' + m.joursLus + ' j lus)');
  v.seuilMin = nf(100 * ((d.seuils || {}).coutRatioMin || 0.05)) + ' %';
  v.source = d.source || '';
}
