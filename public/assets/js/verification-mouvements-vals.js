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
/** Le lundi de la semaine d'hier, n semaines plus tôt. */
const lundiIlYa = n => { const d = new Date(); d.setDate(d.getDate() - 1); d.setDate(d.getDate() - ((d.getDay() + 6) % 7) - 7 * n); return iso(d); };
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

/** Les semaines (lundi → dimanche) d'une liste de jours : [{num, du, au, jours: [7 dates, null hors période]}]. */
function semaines(dates){
  const set = new Set(dates); const out = []; const vus = {};
  dates.forEach(d => {
    const x = jourDe(d); const lun = new Date(x); lun.setDate(x.getDate() - ((x.getDay() + 6) % 7));
    const k = iso(lun); if (vus[k]) { return; } vus[k] = true;
    const js = []; for (let i = 0; i < 7; i++) { const y = new Date(lun); y.setDate(lun.getDate() + i); const s = iso(y); js.push(set.has(s) ? s : null); }
    // Le numéro ISO : le jeudi de la semaine décide de l'année.
    const jeu = new Date(lun); jeu.setDate(lun.getDate() + 3); const an = new Date(jeu.getFullYear(), 0, 4);
    const num = 1 + Math.round(((jeu - an) / 864e5 - 3 + ((an.getDay() + 6) % 7)) / 7);
    const dim = new Date(lun); dim.setDate(lun.getDate() + 6);
    out.push({ num, du: iso(lun), au: iso(dim), jours: js });
  });
  return out;
}
const vide = v => v == null || Math.abs(v) < 0.0005;
/** Une cellule de flux : '+40' en vert, '−12' en rouge, rien pour zéro. */
const flux = (v, sens, fmt) => vide(v) ? { txt: '', cls: '' } : { txt: (sens === '+' ? '+' : (sens === '−' ? '−' : (v > 0 ? '+' : '−'))) + fmt(Math.abs(v)), cls: (sens === '+' || (sens === '±' && v > 0)) ? 'vmo-plus' : 'vmo-moins' };
/**
 * Les lignes d'une fiche hebdomadaire. def : [libellé, sens ('+', '−', '±', 'matin', 'soir', '='), lecture du jour, format, détail du jour (petit), info-bulle].
 * La colonne Semaine : la somme des flux, le stock du premier matin et du dernier soir.
 */
function fiche(sem, parDate, defs){
  return defs.filter(d => !d[6] || sem.jours.some(j => j && !vide(d[2](parDate[j] || {})))).map(([lib, sens, lire, fmt, detail, bulle]) => {
    const vals = sem.jours.map(j => j && parDate[j] ? lire(parDate[j]) : null);
    const cells = sem.jours.map((j, i) => {
      if (!j) { return { txt: '', cls: 'vmo-hors' }; }
      const v = vals[i], o = parDate[j] || {};
      const c = sens === 'matin' || sens === 'soir' ? { txt: v == null ? '—' : fmt(v), cls: 'vmo-stock' } : (sens === '=' ? { txt: v == null ? '—' : (v > 0 ? '' : (v < 0 ? '−' : '')) + fmt(Math.abs(v)), cls: v == null ? '' : (Math.abs(v) < 0.5 ? 'vmo-zero' : 'vmo-ne') } : flux(v, sens === '−fin' ? '−' : sens, fmt));
      return Object.assign(c, { small: detail ? detail(o) : '', title: bulle ? bulle(o) : '' });
    });
    const dedans = vals.map((v, i) => sem.jours[i] ? v : undefined).filter(v => v !== undefined);
    let tot;
    if (sens === 'matin') { const v = dedans.find(x => x != null); tot = { txt: v == null ? '—' : fmt(v), cls: 'vmo-stock' }; }
    else if (sens === 'soir') { const v = [...dedans].reverse().find(x => x != null); tot = { txt: v == null ? '—' : fmt(v), cls: 'vmo-stock' }; }
    else if (sens === '−fin') { const v = [...dedans].reverse().find(x => x != null); tot = flux(v, '−', fmt); }
    else { const v = dedans.reduce((a, x) => a + (x || 0), 0); tot = sens === '=' ? { txt: (v < 0 ? '−' : '') + fmt(Math.abs(v)), cls: Math.abs(v) < 0.5 ? 'vmo-zero' : 'vmo-ne' } : flux(v, sens, fmt); }
    return { lib, sens, cells, tot };
  });
}

const TYPES = { garde0: ['matin', 'mu'], prod: ['production', 'ok'], vente: ['vente', 'ok'], poubelle: ['poubelle', 'ko'], garde: ['gardé', 'mu'], nonExpl: ['non expliqué', 'ko'],
  'stock:livraison': ['livraison', 'ok'], 'stock:entree': ['entrée à la main', 'att'], 'stock:inventaire': ['inventaire', 'mu'], 'stock:effacement': ['remise à zéro', 'mu'], 'stock:correction': ['correction', 'att'] };

/** Les lignes d'une matière : le stock du matin, les entrées, les sorties, le stock du soir. */
const defsMatiere = q => [['Stock du matin', 'matin', o => o.stockMatin, q],
  ['+ Livraison validée', '+', o => o.livre, q],
  ['+ Entrée à la main', '+', o => o.entree, q, null, null, true],
  ['+/− Correction à la main', '±', o => o.corrige, q, null, null, true],
  ['+/− Inventaire (écart au stock attendu)', '±', o => o.inventaire ? o.inventaire.ecart : null, q, o => o.inventaire ? 'compté ' + q(o.inventaire.compte) : ''],
  ['− Ventes × recette', '−', o => o.ventes, q],
  ['− Poubelle × recette', '−', o => o.poubelle, q],
  ['= Stock du soir', 'soir', o => o.stockSoir, q]];

export function valsVerifMouvements(app, common){
  const S = app.state;
  const shop = S.vmoShop || '';
  const cheminListe = '/verification/mouvements/liste' + (shop ? '?shop=' + encodeURIComponent(shop) : '');
  lire(app, 'vmoListe', cheminListe, false);
  const bl = S.vmoListe || {};
  const L = bl.cle === cheminListe && bl.d && !bl.d.error ? bl.d : null;
  const sid = shop || (L ? L.shop : '');
  const sel = S.vmoSel || '';
  const du = S.vmoDu || lundiIlYa(1), au = S.vmoAu || ilYa(1);
  const v = {
    chargementListe: !!bl.chargement && !L, shop: sid, sel, du, au, q: S.vmoQ || '',
    magasins: (L ? L.magasins || [] : []).map(m => ({ id: m.id, nom: m.nom, on: m.id === sid })),
    choisirShop: id => app.setState({ vmoShop: id, vmoSel: '', vmoQ: '' }),
    chercher: e => app.setState({ vmoQ: e.target.value }),
    choisirDu: e => app.setState({ vmoDu: e.target.value }), choisirAu: e => app.setState({ vmoAu: e.target.value }),
    presets: [1, 2, 4, 8].map(n => { const d0 = lundiIlYa(n - 1); return { nom: n + ' sem.', on: du === d0 && au === ilYa(1), choisir: () => app.setState({ vmoDu: d0, vmoAu: ilYa(1) }) }; }),
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
    // Un tableau par semaine : la vitrine (pièces), puis le stock du panel de chaque matière de la recette.
    const parDate = {}; (d.jours || []).forEach(j => { parDate[j.date] = j; });
    const mats = (d.matieres || []).filter(m => (m.mouvements || []).length || (m.totaux && m.totaux.theorique > 0)).slice(0, 3);
    const p = q => pcs(q);
    const defsVitrine = [['Gardé d’hier (le matin)', 'matin', o => o.garde0, p],
      ['+ Produit', '+', o => o.prod, p, o => o.auPlan && !o.mesure ? 'plan' : ''],
      ['− Vendu', '−', o => o.vendu, p, o => o.ca ? nf(o.ca, 0) + ' €' : '', o => Object.entries(o.heures || {}).map(([h, q]) => h + 'h : ' + pcs(q)).join(' · ')],
      ['− Poubelle déclarée', '−', o => o.poubelle, p],
      ['− Gardé pour demain', '−fin', o => o.garde, p],
      ['= Non expliqué', '=', o => o.nonExpl, p]];
    v.semaines = semaines((d.jours || []).map(j => j.date)).map(sm => ({ titre: 'Semaine ' + sm.num, du: jour(sm.du), au: jour(sm.au), jours: sm.jours.map(j => j ? jour(j) : jour(sm.du)).map((t, i) => sm.jours[i] ? t : ''), entetes: sm.jours.map((j, i) => { const y = jourDe(sm.du); y.setDate(y.getDate() + i); return { j: JOURS[y.getDay()], d: jjmm(iso(y)), hors: !j }; }),
      sections: [{ titre: 'Vitrine — ' + d.produit.nom + ' (pièces)', lignes: fiche(sm, parDate, defsVitrine) }].concat(mats.map(m => {
        const pd = {}; (m.jours || []).forEach(j => { pd[j.date] = j; });
        const q = x => qte(x, m.unite);
        return { titre: 'Stock du panel — ' + m.nom + ' (' + (m.unite === 'pce' ? 'pièces' : m.unite) + ')' + (m.nProduits > 1 ? ' · employée par ' + m.nProduits + ' produits' : ''), lignes: fiche(sm, pd, defsMatiere(q)) };
      })) }));
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
    const pdM = {}; (d.jours || []).forEach(j => { pdM[j.date] = j; });
    v.semaines = semaines((d.jours || []).map(j => j.date)).map(sm => ({ titre: 'Semaine ' + sm.num, du: jour(sm.du), au: jour(sm.au), entetes: sm.jours.map((j, i) => { const y = jourDe(sm.du); y.setDate(y.getDate() + i); return { j: JOURS[y.getDay()], d: jjmm(iso(y)), hors: !j }; }),
      sections: [{ titre: 'Stock du panel — ' + M.nom + ' (' + (u === 'pce' ? 'pièces' : u) + ')', lignes: fiche(sm, pdM, defsMatiere(x => qte(x, u))) }] }));
    v.produits = (d.produits || []).map(p => ({ nom: p.nom, parPiece: qte(p.parPiece, u), vendu: pcs(p.vendu), poubelle: pcs(p.poubelle), consomme: qte(p.consomme, u), ouvrir: () => app.setState({ vmoSel: 'p:' + p.id, vmoQ: '' }) }));
    v.nProduitsRecette = d.nProduitsRecette || 0;
  }
}
