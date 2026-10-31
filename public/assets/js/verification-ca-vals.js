/* Cockpit CEO — Vérification › Chiffre d'affaires (#/verification/ca), lot 5 (31/10/2026).
 * Plan : docs/plan-verification.md § 3.4 ; maquette docs/maquettes/verification/d.png.
 *
 * GET /verification/ca?mois=&shop= : la caisse (les tickets) fait référence ; le P&L du panel, la base des redevances
 * et le CA que la matière consommée laisse attendre se lisent en écart ; à part, les lignes sans produit, les remises
 * sur le ticket entier, les corrections et anomalies du panel ; les jours à regarder d'un magasin ; le réseau mois par
 * mois. Remplit `common.vca` ; gabarit : verification-ca-tpl.js ; style : verification.css. */
import { readOne } from './api.js';

const nf = (n, d) => n == null ? '—' : Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d || 0, maximumFractionDigits: d || 0 }).replace(/[  ]/g, ' ');
const fE = (n, d) => n == null ? '—' : nf(n, d || 0) + ' €';
const fEs = n => n == null ? '—' : (n > 0.5 ? '+' : (n < -0.5 ? '−' : '')) + nf(Math.abs(n), 0) + ' €';
const pcs = n => n == null ? '—' : (n > 0.05 ? '+' : (n < -0.05 ? '−' : '')) + nf(Math.abs(n), 1) + ' %';
const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
const JOURS = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
const jourDe = s => new Date(String(s).slice(0, 10) + 'T12:00:00');
const jjmm = s => { const d = jourDe(s); return String(d.getDate()).padStart(2, '0') + '/' + String(d.getMonth() + 1).padStart(2, '0'); };
const libMois = ym => { const [a, m] = String(ym).split('-').map(Number); return MOIS[m - 1] + ' ' + a; };
const Maj = s => s.charAt(0).toUpperCase() + s.slice(1);
const court = nom => String(nom || '').replace(/^Atelier by\s*(?:[^-]*-\s*)?/i, '');
const decale = (ym, k) => { const [a, m] = ym.split('-').map(Number); const d = new Date(a, m - 1 + k, 1); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0'); };

function charge(app, chemin, force){
  const b = app.state.vcaData;
  if (!force && b && b.cle === chemin && (b.d || b.chargement)) { return; }
  if (app._vcaData === chemin && !force) { return; }
  app._vcaData = chemin;
  app.setState({ vcaData: { cle: chemin, chargement: true, d: b && b.cle === chemin ? b.d : null } });
  readOne(chemin).then(d => {
    app._vcaData = null;
    if ((app.state.vcaData || {}).cle !== chemin) { return; }
    app.setState({ vcaData: { cle: chemin, chargement: false, d: d || { error: 'injoignable' } } });
  });
}

/** Un écart à la caisse prêt à afficher. */
const ecart = (e, libelle) => e ? { pct: pcs(e.pct), ton: e.ton || 'mu', valeur: fE(e.valeur), ecart: Math.abs(e.ecart) < 0.5 ? 'identique' : fEs(e.ecart), libelle } : null;
/** Le rapport « autre ÷ caisse » des cartes du haut : 100 % + l'écart. */
const rapport = e => e ? nf(100 + e.pct, 1) + ' %' : '—';

export function valsVerifCa(app, common){
  const S = app.state;
  const hier = (() => { const d = new Date(); d.setDate(d.getDate() - 1); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0'); })();
  const mois = S.vcaMois || hier, shop = S.vcaShop || '';
  const chemin = '/verification/ca?mois=' + mois + (shop ? '&shop=' + encodeURIComponent(shop) : '');
  charge(app, chemin, false);
  const b = S.vcaData || {};
  const d = b.cle === chemin && b.d && !b.d.error ? b.d : null;
  const v = {
    chargement: !!b.chargement && !d, relecture: !!b.chargement && !!d,
    erreur: b.d && b.d.error && b.cle === chemin ? 'Le serveur du cockpit n’a pas répondu : réessayez dans un instant.' : null,
    rafraichir: () => charge(app, chemin, true),
    periode: Maj(libMois(mois)), avant: () => app.setState({ vcaMois: decale(mois, -1) }), apres: () => app.setState({ vcaMois: decale(mois, 1) }), apresOff: mois >= hier,
  };
  common.vca = v;
  if (!d) { return; }
  v.sousPeriode = d.enCours ? 'en cours · au ' + jjmm(d.au) : 'mois clos · ' + jjmm(d.du) + ' → ' + jjmm(d.au);
  v.aLire = d.aLire > 0 ? d.aLire : 0;
  const R = d.reseau;
  v.kpis = {
    caisse: fE(R.caisse), pnl: rapport(R.pnl), pnlTon: R.pnl ? R.pnl.ton : 'mu', pnlS: R.pnl ? (Math.abs(R.pnl.ecart) < 0.5 ? 'identique à la caisse' : fEs(R.pnl.ecart) + ' face à la caisse') : 'P&L non lu',
    red: rapport(R.redevances), redTon: R.redevances ? R.redevances.ton : 'mu', redS: R.redevances ? (Math.abs(R.redevances.ecart) < 0.5 ? 'identique à la caisse' : fEs(R.redevances.ecart) + ' de base ' + (R.redevances.ecart < 0 ? 'en moins' : 'en plus')) : (d.enCours ? 'le mois n’est pas clos' : 'base non lue'),
    att: R.attendu ? nf(100 + R.attendu.pct, 1) + ' %' : '—', attTon: R.attendu ? R.attendu.ton : 'mu',
    attS: R.attendu ? fEs(R.attendu.ecart) + ' de ventes que la matière laisse attendre · ' + R.attendu.part + ' % du CA couvert' : 'pas de semaine d’inventaire lisible ce mois',
    sp: fE(R.sansProduit.eur), spS: R.sansProduit.n + ' ligne' + (R.sansProduit.n > 1 ? 's' : '') + ' · ' + fE(R.remises) + ' de remises sur le ticket entier',
  };
  v.lignes = (d.magasins || []).map(m => {
    const C = m.caisse, mat = m.matiere;
    return { id: m.id, nom: court(m.nom), on: m.id === d.shopJours, choisir: () => app.setState({ vcaShop: m.id }),
      caisse: fE(C.ttc), ht: fE(C.ht) + ' HT · ' + nf(C.tickets) + ' tickets', incomplet: !C.complet ? C.joursLus + ' / ' + C.jours + ' jours lus' : '',
      pnl: ecart(m.pnl), red: ecart(m.redevances), redDu: m.redevances && m.redevances.active ? 'redevances ' + fE(m.redevances.du) : (m.redevances ? 'redevances désactivées' : ''),
      att: ecart(m.attendu), attPourquoi: m.attendu ? m.attendu.semaines + ' semaine' + (m.attendu.semaines > 1 ? 's' : '') + ' d’inventaire' : (mat && mat.exclues ? 'entrées non validées' : 'pas de semaine d’inventaire'),
      recettes: m.recettesFiables == null ? null : nf(m.recettesFiables, 1) + ' % de recettes fiables',
      sp: fE(m.sansProduit.eur), spN: m.sansProduit.n + ' ligne' + (m.sansProduit.n > 1 ? 's' : ''), rem: m.remises.eur > 0 ? fE(m.remises.eur) + ' de remises' : '',
      corr: m.corrections ? (m.corrections[0] ? m.corrections[0] + ' correction' + (m.corrections[0] > 1 ? 's' : '') + (m.corrections[1] != null ? ' · ' + fE(m.corrections[1], 2) : '') : 'aucune') : 'non lues',
      ano: m.anomalies ? m.anomalies[0] + ' anomalie' + (m.anomalies[0] > 1 ? 's' : '') : '',
      verdict: m.verdict, n: m.nJoursARegarder };
  });
  v.avecCorr = (d.magasins || []).some(m => m.corrections || m.anomalies);
  v.reseau = { caisse: fE(R.caisse), pnl: ecart(R.pnl), red: ecart(R.redevances), att: ecart(R.attendu), sp: fE(R.sansProduit.eur), spN: R.sansProduit.n + ' lignes',
    corr: R.corrections && R.corrections[0] ? R.corrections[0] + ' correction' + (R.corrections[0] > 1 ? 's' : '') + (R.corrections[1] != null ? ' · ' + fE(R.corrections[1], 2) : '') : 'aucune' };
  // La phrase : où la base des redevances perd sur la caisse, et ce que la matière appelle.
  const sous = (d.magasins || []).filter(m => m.redevances && m.redevances.ecart <= -20).map(m => court(m.nom) + ' (' + fEs(m.redevances.ecart) + ')');
  const egaux = (d.magasins || []).filter(m => m.pnl && Math.abs(m.pnl.ecart) < 20).map(m => court(m.nom));
  const att = (d.magasins || []).filter(m => m.attendu && m.attendu.ton === 'ko').map(m => court(m.nom) + ' (' + fE(m.attendu.ecart) + ' de ventes de plus, ' + pcs(m.attendu.pct) + ')');
  v.phrase = Maj(libMois(mois)) + ' : ' + (egaux.length ? 'le P&L reprend la caisse ' + (egaux.length === (d.magasins || []).length ? 'partout' : 'à ' + egaux.join(', ')) : 'le P&L ne reprend la caisse nulle part')
    + (sous.length ? ' ; la base des redevances est sous la caisse à ' + sous.join(', ') : '') + (att.length ? ' ; la matière consommée appelle plus de ventes à ' + att.join(', ') : '') + '.';
  // Les jours à regarder du magasin choisi.
  const M = (d.magasins || []).find(m => m.id === d.shopJours);
  v.jours = M ? { nom: court(M.nom), liste: (M.joursARegarder || []).map(j => { const x = jourDe(j.date); return { date: JOURS[x.getDay()] + ' ' + jjmm(j.date), ton: j.ton, texte: j.texte, type: j.type }; }), n: M.nJoursARegarder || 0 } : null;
  v.magasins = (d.magasins || []).map(m => ({ id: m.id, nom: court(m.nom), on: m.id === d.shopJours }));
  v.choisirShop = id => app.setState({ vcaShop: id });
  v.historique = (d.historique || []).map(h => ({ mois: Maj(libMois(h.mois)), enCours: h.mois === hier && d.enCours, caisse: h.joursLus ? fE(h.caisse) : 'non lu',
    lu: h.joursLus ? (h.complet ? '' : h.joursLus + ' / ' + h.jours + ' jours') : '', pnl: ecart(h.pnl), att: h.attendu ? { pct: pcs(h.attendu.pct), ton: h.attendu.ton, valeur: fE(h.attendu.valeur) } : null,
    recettes: h.recettes == null ? '—' : nf(h.recettes, 1) + ' %', tonR: h.recettes == null ? 'mu' : (h.recettes >= 97 ? 'ok' : (h.recettes >= 90 ? 'att' : 'ko')) }));
}
