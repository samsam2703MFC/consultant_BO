/* Cockpit CEO — Vérification › Matières ↔ recettes (#/verification/matieres), lot 4 (31/10/2026).
 * Plan : docs/plan-verification.md § 3.3 ; maquettes docs/maquettes/verification (c, g-matieres).
 *
 * GET /verification/matieres : sans magasin, le réseau (la dernière semaine d'inventaire à inventaire de chaque
 * magasin, la matrice catégories × magasins) ; avec `shop`, ses semaines, la semaine choisie ou le cumul des quatre
 * dernières, matière par matière avec la cause probable de chaque écart.
 *
 * Remplit `common.vm` ; gabarit : verification-matieres-tpl.js ; style : verification.css (vm-). */
import { readOne } from './api.js';

const nf = (n, d) => n == null ? '—' : Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d || 0, maximumFractionDigits: d || 0 }).replace(/[  ]/g, ' ');
const fE = n => n == null ? '—' : nf(n, 0) + ' €';
const fEs = n => n == null ? '—' : (n > 0.5 ? '+' : (n < -0.5 ? '−' : '')) + nf(Math.abs(n), 0) + ' €';
const pc = (n, d) => n == null ? '—' : nf(n, d == null ? 1 : d) + ' %';
const JOURS = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
const jourDe = s => new Date(String(s).slice(0, 10) + 'T12:00:00');
const jjmm = s => { const d = jourDe(s); return String(d.getDate()).padStart(2, '0') + '/' + String(d.getMonth() + 1).padStart(2, '0'); };
const jour = s => JOURS[jourDe(s).getDay()] + ' ' + jjmm(s);
const court = nom => String(nom || '').replace(/^Atelier by\s*(?:[^-]*-\s*)?/i, '');

/** Une quantité dans son unité : grammes en kg, millilitres en litres au-delà de mille, pièces entières. */
function qte(q, u){
  if (q == null) { return '—'; }
  if (u === 'g') { return Math.abs(q) >= 1000 ? nf(q / 1000, 1) + ' kg' : nf(q, 0) + ' g'; }
  if (u === 'ml') { return Math.abs(q) >= 1000 ? nf(q / 1000, 1) + ' l' : nf(q, 0) + ' ml'; }
  return nf(q, Math.abs(q - Math.round(q)) > 0.05 ? 1 : 0) + ' pcs';
}
const qteS = (q, u) => q == null ? '—' : (q > 0 ? '+' : (q < 0 ? '−' : '')) + qte(Math.abs(q), u);
/** Le prix d'achat à l'unité lisible : au kilo, au litre, à la pièce. */
const prixU = (p, u) => p == null ? 'sans prix' : (u === 'g' ? nf(p * 1000, 2) + ' € / kg' : (u === 'ml' ? nf(p * 1000, 2) + ' € / l' : nf(p, p < 1 ? 3 : 2) + ' € / pce'));

const TONS_CAUSE = { entree: 'att', recette: 'ko', poubelle: 'ko', horsCaisse: 'ko', tolerance: 'ok' };
const COULEUR_CAUSE = { entree: '#D97706', recette: '#8D1D2C', poubelle: '#C0182B', horsCaisse: '#5b3f86', tolerance: '#2d7a3e' };

function charge(app, chemin, force){
  const b = app.state.vmData;
  if (!force && b && b.cle === chemin && (b.d || b.chargement)) { return; }
  if (app._vmData === chemin && !force) { return; }
  app._vmData = chemin;
  app.setState({ vmData: { cle: chemin, chargement: true, d: b && b.cle === chemin ? b.d : null } });
  readOne(chemin).then(d => {
    app._vmData = null;
    if ((app.state.vmData || {}).cle !== chemin) { return; }
    app.setState({ vmData: { cle: chemin, chargement: false, d: d || { error: 'injoignable' } } });
  });
}

/** Les totaux d'une lecture, prêts à afficher. */
function totaux(t){
  if (!t) { return null; }
  return { debut: fE(t.debut), entrees: fE(t.entrees), fin: fE(t.fin), sorties: fE(t.sorties), ventes: fE(t.ventes), poubelle: fE(t.poubelle), explique: fE(t.explique),
    ecart: fEs(t.ecart), manquant: fE(t.manquant), excedent: fE(t.excedent), explication: pc(t.explication), ecartPct: pc(t.ecartPct), ton: t.ton || 'mu',
    n: t.n || 0, sansPrix: t.sansPrix || 0, entreesNulles: !(t.entrees > 0) };
}

/** L'attribution : une barre par cause, en part de son côté (ce qui manque, ce qui dépasse). */
function attribution(A){
  const cote = l => { const tot = (l || []).reduce((s, x) => s + x.eur, 0);
    return { total: fE(tot), lignes: (l || []).map(x => ({ code: x.code, nom: x.nom, eur: fE(x.eur), part: tot > 0 ? Math.round(100 * x.eur / tot) : 0, n: x.n,
      exemples: (x.exemples || []).join(', '), couleur: COULEUR_CAUSE[x.code] || '#8a8177' })) }; };
  return { manque: cote((A || {}).manque), depasse: cote((A || {}).depasse) };
}

/** Une ligne de matière. */
function matiere(m){
  return { nom: m.nom, prix: prixU(m.prix, m.unite), cat: m.cat, debut: qte(m.debut, m.unite), entrees: qte(m.entrees, m.unite), fin: qte(m.fin, m.unite),
    sorties: qte(m.sorties, m.unite), explique: qte(m.explique, m.unite), ventes: qte(m.ventes, m.unite), poubelle: qte(m.poubelle, m.unite),
    ecart: qteS(m.ecart, m.unite), ecartPct: m.ecartPct == null ? (m.sorties <= 0 && m.explique > 0 ? 'sans sortie' : '—') : (m.ecartPct > 0 ? '+' : '') + pc(m.ecartPct),
    ecartEur: m.ecartEur == null ? '—' : fEs(m.ecartEur), ton: m.ton || 'mu', cause: m.cause, nomCause: m.cause ? ({ entree: 'entrée non validée', recette: 'recette', poubelle: 'poubelle non déclarée', horsCaisse: 'ni vendu ni jeté', tolerance: 'tolérance' })[m.cause] : '',
    tonCause: TONS_CAUSE[m.cause] || 'mu', pourquoi: m.pourquoi || '', produits: (m.produits || []).join(', ') };
}

/** Une catégorie (le tableau de même structure pour chaque magasin), ses matières dépliables. */
function categorie(c, ouvert, basculer){
  return Object.assign({ nom: c.nom, n: (c.matieres || []).length || c.n || 0, ouvert, basculer }, totaux(c), { matieres: ouvert ? (c.matieres || []).map(matiere) : [] });
}

export function valsVerifMatieres(app, common){
  const S = app.state;
  const shop = S.vmShop || '', n = S.vmN || 1, sem = S.vmSem || '';
  const [de, a] = sem ? sem.split(':') : ['', ''];
  const chemin = '/verification/matieres' + (shop ? '?shop=' + encodeURIComponent(shop) + '&semaines=' + n + (de ? '&de=' + de + '&a=' + a : '') : '');
  charge(app, chemin, false);
  const b = S.vmData || {};
  const d = b.cle === chemin && b.d && !b.d.error ? b.d : null;
  if (d && !shop) { app._vmMags = (d.magasins || []).map(m => ({ id: m.id, nom: m.nom })); }
  const mags = app._vmMags || [];
  const v = {
    chargement: !!b.chargement && !d, relecture: !!b.chargement && !!d, shop,
    erreur: b.d && b.d.error && b.cle === chemin ? 'Le serveur du cockpit n’a pas répondu : réessayez dans un instant.' : null,
    rafraichir: () => charge(app, chemin, true),
    magasins: [{ id: '', nom: 'Tout le réseau', on: !shop }].concat(mags.map(m => ({ id: m.id, nom: m.nom, on: m.id === shop }))),
    choisirShop: id => app.setState({ vmShop: id, vmSem: '', vmOuverts: {} }),
  };
  common.vm = v;
  if (!d) { return; }
  v.aCalculer = d.aCalculer || 0;
  v.seuils = d.seuils || [3, 6];
  const tol = (d.tolerances || {}).unite || {};
  v.tolerances = 'pièces ' + nf(tol.pce, 0) + ' %, poids et volumes ' + nf(tol.g, 0) + ' %' + (Object.keys((d.tolerances || {}).categorie || {}).length ? ', et des tolérances par catégorie' : '');
  if (!shop) {
    const lignes = (d.magasins || []).map(m => {
      const L = m.lecture, t = L ? totaux(L.totaux) : null;
      return { id: m.id, nom: court(m.nom), ouvrir: () => app.setState({ vmShop: m.id, vmSem: '', vmOuverts: {} }),
        periode: m.de ? jjmm(m.de.at) + ' → ' + jjmm(m.a.at) : '—', jours: m.jours, t, verdict: L ? L.verdict : { ton: 'mu', nom: m.semaines ? 'À calculer' : 'Pas de semaine', pourquoi: m.semaines ? 'le cron la calcule dans l’heure' : 'il faut deux inventaires relevés consécutifs' },
        retard: m.inventaireEnRetard, dernier: m.dernierInventaire ? jour(m.dernierInventaire) : 'aucun', age: m.ageInventaire, aCalculer: m.aCalculer };
    });
    v.lignes = lignes;
    v.reseau = totaux(d.reseau);
    v.reseauSans = (d.reseauSans || []).map(court);
    // L'attribution du réseau : la somme des magasins, cause par cause.
    const somme = cote => { const acc = {};
      (d.magasins || []).forEach(m => ((m.lecture || {}).attribution || {})[cote] && m.lecture.attribution[cote].forEach(x => {
        acc[x.code] = acc[x.code] || { code: x.code, nom: x.nom, eur: 0, n: 0, exemples: [] };
        acc[x.code].eur += x.eur; acc[x.code].n += x.n; if (acc[x.code].exemples.length < 4) { acc[x.code].exemples.push(court(m.nom) + ' : ' + (x.exemples || [])[0]); }
      }));
      return Object.values(acc).sort((p, q) => q.eur - p.eur); };
    v.attribution = attribution({ manque: somme('manque'), depasse: somme('depasse') });
    v.entetes = (d.magasins || []).map(m => court(m.nom));
    v.matrice = (d.matrice || []).map(r => ({ cat: r.cat, cases: r.cases.map(c => c ? { explication: pc(c.explication), ton: c.ton || 'mu', ecart: fEs(c.ecart), vide: c.explication == null } : null) }));
    v.retards = lignes.filter(l => l.retard).map(l => l.nom + ' (' + l.dernier + (l.age != null ? ', il y a ' + l.age + ' jours' : '') + ')');
    return;
  }
  // Un magasin
  v.nom = court(d.nom);
  v.vues = [1, 4].map(k => ({ id: k, nom: k === 1 ? 'Une semaine' : 'Quatre semaines', on: k === n, choisir: () => app.setState({ vmN: k }) }));
  v.semaines = (d.semaines || []).map(s => ({ cle: s.de + ':' + s.a, nom: 'inventaire du ' + jour(s.deAt) + ' → ' + jour(s.aAt) + ' (' + nf(s.jours, 0) + ' j)', on: s.de + ':' + s.a === sem }));
  v.choisirSemaine = cle => app.setState({ vmSem: cle });
  v.retard = d.inventaireEnRetard ? { dernier: d.dernierInventaire ? jour(d.dernierInventaire) : null, age: d.ageInventaire } : null;
  const P = d.periode;
  v.periode = P ? { de: jour(P.de.at), a: jour(P.a.at), jours: nf(P.jours, 0), semaines: P.semaines } : null;
  v.notes = d.notes || [];
  const L = d.lecture;
  if (!L) { v.vide = true; return; }
  v.t = totaux(L.totaux);
  v.verdict = L.verdict;
  v.sansEntree = L.sansEntree;
  v.nEntree = L.nEntreeManquante || 0;
  v.attribution = attribution(L.attribution);
  const ouverts = S.vmOuverts || {};
  v.categories = (L.categories || []).map(c => categorie(c, !!ouverts[c.nom], () => app.setState({ vmOuverts: Object.assign({}, ouverts, { [c.nom]: !ouverts[c.nom] }) })));
  const toutes = (L.matieres || []).filter(m => m.cause !== null || Math.abs(m.ecartEur || 0) > 0.5);
  v.nMatieres = (L.matieres || []).length;
  v.tout = !!S.vmTout;
  v.basculerTout = () => app.setState({ vmTout: !S.vmTout });
  v.matieres = (v.tout ? toutes : toutes.slice(0, 15)).map(matiere);
  v.nAvecEcart = toutes.length;
  v.sansRecette = (d.sansRecette || []).map(p => ({ nom: p.nom, q: nf(p.q, p.q % 1 ? 1 : 0), ca: fE(p.ca) }));
  v.caSansRecette = fE(d.caSansRecette);
  v.horsInventaire = fE(d.horsInventaire);
  // La phrase de lecture : ce que le magasin a consommé, ce que les ventes en expliquent, les premières causes.
  const m0 = (v.attribution.manque.lignes || [])[0];
  v.phrase = P ? 'Du ' + jjmm(P.de.at) + ' au ' + jjmm(P.a.at) + ', ' + v.nom + ' a consommé ' + v.t.sorties + ' de matière au coût ; ventes et poubelle déclarée en expliquent ' + v.t.explication
    + (m0 ? '. Le premier poste de ce qui manque : ' + m0.nom.toLowerCase() + ' (' + m0.eur + ', ' + m0.exemples + ')' : '') + '.' : '';
}
