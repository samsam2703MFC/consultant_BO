/* Cockpit CEO — Vérification › Mouvements (#/verification/mouvements) : un produit ou une matière, tous ses
 * mouvements sur une période (demande du 31/10/2026).
 *
 * GET /verification/mouvements/liste?shop= : les produits et matières du magasin (la recherche) ;
 * GET /verification/mouvements?shop=&p=|m=&du=&au= : jour par jour et le journal, dans l'ordre de l'heure.
 * Remplit `common.vmo` ; gabarit : verification-mouvements-tpl.js ; style : verification.css (vmo-). */
import { readOne } from './api.js';

const nf = (n, d) => n == null ? '—' : Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d || 0, maximumFractionDigits: d || 0 }).replace(/[  ]/g, ' ');
const pcs = q => q == null ? '—' : nf(q, Math.abs(q - Math.round(q)) > 0.05 ? 1 : 0);
const JOURS = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
const iso = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
const jourDe = s => new Date(String(s).slice(0, 10) + 'T12:00:00');
const jjmm = s => { const d = jourDe(s); return String(d.getDate()).padStart(2, '0') + '/' + String(d.getMonth() + 1).padStart(2, '0'); };
const jour = s => JOURS[jourDe(s).getDay()] + ' ' + jjmm(s);
const ilYa = n => { const d = new Date(); d.setDate(d.getDate() - n); return iso(d); };
const sansAccent = s => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

/** Une quantité de matière dans son unité. */
function qte(q, u){
  if (q == null) { return '—'; }
  if (u === 'g') { return Math.abs(q) >= 1000 ? nf(q / 1000, 2) + ' kg' : nf(q, 0) + ' g'; }
  if (u === 'ml') { return Math.abs(q) >= 1000 ? nf(q / 1000, 2) + ' l' : nf(q, 0) + ' ml'; }
  return pcs(q) + ' pcs';
}
const signe = (q, u) => q == null ? '—' : (q > 0 ? '+' : (q < 0 ? '−' : '')) + qte(Math.abs(q), u);

function lire(app, cle, chemin, force){
  const b = app.state[cle];
  if (!force && b && b.cle === chemin && (b.d || b.chargement)) { return; }
  if (app['_' + cle] === chemin && !force) { return; }
  app['_' + cle] = chemin;
  app.setState({ [cle]: { cle: chemin, chargement: true, d: b && b.cle === chemin ? b.d : null } });
  readOne(chemin).then(d => {
    app['_' + cle] = null;
    if ((app.state[cle] || {}).cle !== chemin) { return; }
    app.setState({ [cle]: { cle: chemin, chargement: false, d: d || { error: 'injoignable' } } });
  });
}

const TYPES = { garde0: ['matin', 'mu'], prod: ['production', 'ok'], vente: ['vente', 'ok'], poubelle: ['poubelle', 'ko'], garde: ['gardé', 'mu'], nonExpl: ['non expliqué', 'ko'],
  'stock:livraison': ['livraison', 'ok'], 'stock:entree': ['entrée à la main', 'att'], 'stock:inventaire': ['inventaire', 'mu'], 'stock:effacement': ['remise à zéro', 'mu'], 'stock:correction': ['correction', 'att'] };

export function valsVerifMouvements(app, common){
  const S = app.state;
  const shop = S.vmoShop || '';
  const cheminListe = '/verification/mouvements/liste' + (shop ? '?shop=' + encodeURIComponent(shop) : '');
  lire(app, 'vmoListe', cheminListe, false);
  const bl = S.vmoListe || {};
  const L = bl.cle === cheminListe && bl.d && !bl.d.error ? bl.d : null;
  const sid = shop || (L ? L.shop : '');
  const sel = S.vmoSel || '';
  const du = S.vmoDu || ilYa(14), au = S.vmoAu || ilYa(1);
  const v = {
    chargementListe: !!bl.chargement && !L, shop: sid, sel, du, au, q: S.vmoQ || '',
    magasins: (L ? L.magasins || [] : []).map(m => ({ id: m.id, nom: m.nom, on: m.id === sid })),
    choisirShop: id => app.setState({ vmoShop: id, vmoSel: '', vmoQ: '' }),
    chercher: e => app.setState({ vmoQ: e.target.value }),
    choisirDu: e => app.setState({ vmoDu: e.target.value }), choisirAu: e => app.setState({ vmoAu: e.target.value }),
    presets: [7, 14, 30, 62].map(n => ({ nom: n + ' j', on: du === ilYa(n) && au === ilYa(1), choisir: () => app.setState({ vmoDu: ilYa(n), vmoAu: ilYa(1) }) })),
  };
  common.vmo = v;
  // La recherche : produits et matières dont le nom contient le texte (sans accents).
  if (L) {
    const q = sansAccent(v.q).trim();
    const tous = (L.produits || []).map(p => ({ cle: 'p:' + p.id, nom: p.nom, type: 'produit', detail: (p.cat ? p.cat + ' · ' : '') + (p.q ? pcs(p.q) + ' vendus en 30 jours' : 'au plan'), poids: p.q || 0 }))
      .concat((L.matieres || []).map(m => ({ cle: 'm:' + m.id, nom: m.nom, type: 'matière', detail: (m.cat ? m.cat + ' · ' : '') + 'stock du panel · ' + m.n + ' relevé' + (m.n > 1 ? 's' : ''), poids: 0 })));
    const trouves = q ? tous.filter(x => sansAccent(x.nom).includes(q)) : tous.filter(x => x.type === 'produit').sort((a, b) => b.poids - a.poids);
    v.resultats = trouves.slice(0, q ? 20 : 12).map(x => Object.assign({ on: x.cle === sel, choisir: () => app.setState({ vmoSel: x.cle, vmoQ: '' }) }, x));
    v.nResultats = trouves.length;
    v.nProduits = (L.produits || []).length; v.nMatieres = (L.matieres || []).length;
    const choisi = tous.find(x => x.cle === sel);
    v.choisi = choisi ? { nom: choisi.nom, type: choisi.type } : null;
  }
  if (!sel || !sid) { return; }
  const [t, id] = sel.split(':');
  const chemin = '/verification/mouvements?shop=' + encodeURIComponent(sid) + '&' + t + '=' + id + '&du=' + du + '&au=' + au;
  lire(app, 'vmoData', chemin, false);
  const b = S.vmoData || {};
  const d = b.cle === chemin && b.d && !b.d.error ? b.d : null;
  v.chargement = !!b.chargement && !d; v.relecture = !!b.chargement && !!d;
  v.erreur = b.d && b.d.error && b.cle === chemin ? 'Le serveur du cockpit n’a pas répondu : réessayez dans un instant.' : null;
  v.rafraichir = () => lire(app, 'vmoData', chemin, true);
  if (!d) { return; }
  v.type = d.type;
  v.periode = jour(d.du) + ' → ' + jour(d.au);
  const ordre = ['matin', 'soir'];
  v.journal = [];
  let dernier = null;
  (d.journal || []).forEach(e => {
    const T = TYPES[e.type] || [e.type, 'mu'];
    if (e.date !== dernier) { v.journal.push({ jour: jour(e.date) }); dernier = e.date; }
    v.journal.push({ h: e.h === 'matin' ? 'matin' : (e.h === 'soir' ? 'soir' : e.h), type: T[0], ton: T[1], texte: e.texte });
  });
  void ordre;
  if (d.type === 'produit') {
    const P = d.produit, T = d.totaux;
    v.titre = P.nom; v.sous = (P.cat ? P.cat + ' · ' : '') + (P.prix != null ? nf(P.prix, 2) + ' € la pièce' : '');
    v.kpis = [['Gardé au début', pcs(T.garde0), 'le matin du premier jour'], ['+ Produit', pcs(T.prod), T.mesure + ' jour' + (T.mesure > 1 ? 's' : '') + ' sur ' + T.jours + ' en cuissons validées'],
      ['Vendu', pcs(T.vendu), nf(T.ca, 2) + ' € en caisse'], ['Poubelle', pcs(T.poubelle), 'déclarée en caisse'], ['Gardé à la fin', pcs(T.garde), 'le soir du dernier jour'],
      ['Non expliqué', pcs(T.nonExpl), T.mesure < T.jours ? 'produit au plan certains jours' : 'ni vendu, ni jeté, ni gardé', Math.abs(T.nonExpl) >= 1 ? (T.mesure < T.jours ? 'mu' : 'ko') : 'ok']];
    v.jours = (d.jours || []).map(j => ({ date: jour(j.date), dim: jourDe(j.date).getDay() === 0, auPlan: j.auPlan, calcule: j.calcule,
      garde0: pcs(j.garde0), prod: pcs(j.prod), mesure: j.mesure, dispo: pcs(j.dispo), vendu: pcs(j.vendu), ca: j.ca ? nf(j.ca, 2) + ' €' : '', poubelle: pcs(j.poubelle), garde: pcs(j.garde),
      compte: j.compte == null ? '' : pcs(j.compte), nonExpl: j.nonExpl == null ? '—' : pcs(j.nonExpl), tonNE: j.nonExpl == null ? 'mu' : (Math.abs(j.nonExpl) < 0.5 ? 'ok' : (j.mesure ? 'ko' : 'mu')),
      heures: Object.entries(j.heures || {}).map(([h, q]) => h + 'h ' + pcs(q)).join(' · ') }));
    v.matieres = (d.matieres || []).map(m => ({ nom: m.nom, cat: m.cat, parPiece: qte(m.parPiece, m.unite) + ' par pièce', theorique: qte(m.theorique, m.unite),
      n: m.mouvements.length, ouvrir: () => app.setState({ vmoSel: 'm:' + m.id, vmoQ: '' }),
      mouvements: m.mouvements.map(x => ({ quand: jour(x.at) + ' ' + x.at.slice(11, 16), type: (TYPES['stock:' + x.type] || [x.type])[0], ton: (TYPES['stock:' + x.type] || [0, 'mu'])[1], avant: qte(x.avant, m.unite), apres: qte(x.apres, m.unite), delta: signe(x.delta, m.unite) })) }));
    v.aCalculer = d.aCalculer || 0;
  } else {
    const M = d.matiere, T = d.totaux, u = M.unite;
    v.titre = M.nom; v.sous = (M.cat ? M.cat + ' · ' : '') + 'matière du stock du panel';
    v.kpis = [['Stock au début', qte(T.stockDebut, u), 'le premier mouvement relevé'], ['+ Livré', qte(T.livre, u), 'commandes validées à la réception'],
      ['Corrections', signe(T.corrige, u), 'stock modifié à la main', Math.abs(T.corrige) > 0 ? 'att' : ''], ['Inventaires', String(T.inventaires), 'écart total ' + signe(T.ecartInventaires, u), Math.abs(T.ecartInventaires) > 0 ? 'att' : ''],
      ['Consommé (théorique)', qte(T.theorique, u), 'ventes et poubelle × recettes'], ['Stock à la fin', qte(T.stockFin, u), 'le dernier mouvement relevé']];
    v.jours = (d.jours || []).map(j => ({ date: jour(j.date), dim: jourDe(j.date).getDay() === 0, livre: j.livre ? signe(j.livre, u) : '', corrige: j.corrige ? signe(j.corrige, u) : '',
      inventaire: j.inventaire ? 'compté ' + qte(j.inventaire.compte, u) + (j.inventaire.ecart != null ? ' (' + signe(j.inventaire.ecart, u) + ')' : '') : '', tonInv: j.inventaire && j.inventaire.ecart ? (j.inventaire.ecart < 0 ? 'ko' : 'att') : 'mu',
      theorique: j.theorique ? qte(j.theorique, u) : '', stock: qte(j.stockSoir, u), n: j.mouvements.length }));
    v.produits = (d.produits || []).map(p => ({ nom: p.nom, parPiece: qte(p.parPiece, u), vendu: pcs(p.vendu), poubelle: pcs(p.poubelle), consomme: qte(p.consomme, u), ouvrir: () => app.setState({ vmoSel: 'p:' + p.id, vmoQ: '' }) }));
    v.nProduitsRecette = d.nProduitsRecette || 0;
  }
}
