/* Cockpit CEO — Vérification › Recettes à corriger (#/verification/recettes, lot 1, 31/10/2026).
 * Plan : docs/plan-verification.md § 3.5 ; maquette : docs/maquettes/verification/e.png.
 *
 * GET /verification/recettes rend la liste des recettes douteuses ou manquantes (neuf règles), classée par CA
 * concerné sur trente jours, la couverture (la part du CA qui passe par une recette fiable) et l'état de la lecture
 * des recettes (le cron horaire en lit un lot à chaque passage). Le serveur garde l'analyse une heure ; « Relire »
 * la recalcule et lit un petit lot de recettes de plus.
 *
 * Remplit `common.vr` ; le gabarit est tplVerification (verification-tpl.js), le style verification.css (vr-).
 * La liste est pour le CEO seul (décision du 31/10/2026) : pas d'envoi, un export CSV. */
import { readOne } from './api.js';

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
  const lien = rid => d.lienRecette && rid ? d.lienRecette.replace('{rid}', rid).replace('{id}', rid) : null;
  const mags = d.magasins || [];
  v.lignes = L.map((l, i) => {
    const dj = jour(l.depuis);
    const jours = dj && auj ? Math.max(0, Math.round((auj - dj) / 864e5)) : null;
    return { rang: i + 1, haut: i < 5 && l.regle !== 'orph', nom: l.nom || ('Produit ' + (l.pid || '')), cat: l.regle === 'orph' ? 'matière première' + (l.cat ? ' · ' + l.cat : '') : (l.cat || ''),
      regle: l.regle, nomRegle: l.nomRegle, classe: CLASSES[l.regle] || 'pb-orph', detail: l.detail,
      ca: l.ca > 0 ? fE(l.ca) : null, caBrut: l.ca > 0 ? l.ca : null, valeur: l.valeur ? fE(l.valeur) + ' en stock' : null, valeurBrute: l.valeur || null,
      magasinsTxt: (l.magasins || []).length === mags.length && mags.length > 2 ? 'les ' + mags.length + ' magasins' : (l.magasins || []).join(' · '),
      depuis: jours == null ? '' : (jours === 0 ? 'aujourd’hui' : jours + ' j'), depuisDate: l.depuis || '', depuisTitre: l.depuis ? 'repérée le ' + jjmm(l.depuis) : '',
      rid: l.rid || null, lien: lien(l.rid), formule: !!l.formule };
  });
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
