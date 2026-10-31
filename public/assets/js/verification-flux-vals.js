/* Cockpit CEO — Vérification › Audit des flux (#/verification/flux) et Fuites par magasin (#/verification/fuites),
 * lot 2 (31/10/2026). Plan : docs/plan-verification.md § 3.1-3.2 ; maquettes docs/maquettes/verification (a, a-jour, b, g).
 *
 * GET /verification/flux?vue=&date=&shop= : l'audit (le réseau, ou un magasin : jour par jour, produit par produit) ;
 * GET /verification/fuites?vue=&date= : un tableau par magasin, de même structure, catégories dépliables en produits.
 * Une journée est MESURÉE quand ses cuissons sont validées ; sinon le produit est le plan et l'écart n'est qu'un écart
 * au plan — l'écran le dit au lieu d'annoncer une fuite.
 *
 * Remplit `common.vx` (audit) ou `common.vfu` (fuites) ; gabarits : verification-flux-tpl.js ; style : verification.css. */
import { readOne } from './api.js';

const nf = (n, d) => n == null ? '—' : Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d || 0, maximumFractionDigits: d || 0 }).replace(/[  ]/g, ' ');
const fE = (n, d) => n == null ? '—' : nf(n, d) + ' €';
const pc = (n, d) => n == null ? '—' : nf(n, d == null ? 1 : d) + ' %';
const JOURS = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
const iso = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
const jourDe = s => new Date(String(s).slice(0, 10) + 'T12:00:00');
const jjmm = s => { const d = jourDe(s); return String(d.getDate()).padStart(2, '0') + '/' + String(d.getMonth() + 1).padStart(2, '0'); };
const hier = () => { const d = new Date(); d.setDate(d.getDate() - 1); return iso(d); };

/** Le libellé d'une période et le décalage d'un cran. */
function periode(vue, d){
  if (!d) { return ''; }
  if (vue === 'jour') { const x = jourDe(d.date); return JOURS[x.getDay()] + ' ' + x.getDate() + ' ' + MOIS[x.getMonth()] + ' ' + x.getFullYear(); }
  if (vue === 'mois') { const x = jourDe(d.du); return MOIS[x.getMonth()] + ' ' + x.getFullYear() + ' · ' + jjmm(d.du) + ' → ' + jjmm(d.au); }
  return 'semaine du ' + jjmm(d.du) + ' au ' + jjmm(d.au);
}
function decale(vue, date, sens){
  const d = jourDe(date);
  if (vue === 'jour') { d.setDate(d.getDate() + sens); }
  else if (vue === 'mois') { d.setDate(1); d.setMonth(d.getMonth() + sens); }
  else { d.setDate(d.getDate() + 7 * sens); }
  const auj = iso(new Date());
  return iso(d) > auj ? auj : iso(d);
}

/** Une lecture (cache par clé) ; l'écran se relit au changement de période ou de magasin. */
function charge(app, cleEtat, chemin, force){
  const b = app.state[cleEtat];
  if (!force && b && b.cle === chemin && (b.d || b.chargement)) { return; }
  if (app['_' + cleEtat] === chemin && !force) { return; }
  app['_' + cleEtat] = chemin;
  app.setState({ [cleEtat]: { cle: chemin, chargement: true, d: b && b.cle === chemin ? b.d : null } });
  readOne(chemin).then(d => {
    app['_' + cleEtat] = null;
    if ((app.state[cleEtat] || {}).cle !== chemin) { return; }
    app.setState({ [cleEtat]: { cle: chemin, chargement: false, d: d || { error: 'injoignable' } } });
  });
}

/** Une lecture servie à l'écran : taux, tons, verdict, mesure (mêmes champs pour un magasin, un jour, une catégorie). */
function lecture(L){
  if (!L || !L.eur) { return null; }
  const t = L.taux || {}, tons = L.tons || {}, mesure = L.mesure;
  const mesure100 = mesure == null ? 0 : mesure;
  const estime = mesure100 < 50;
  // Rien jeté en plusieurs jours, ce n'est pas une bonne nouvelle : la poubelle n'est pas déclarée.
  const poubNulle = !(L.eur.poubelle > 0) && L.eur.dispo > 0 && (L.jours || 0) >= 2;
  return {
    dispo: fE(L.eur.dispo, 0), dispoPcs: nf(L.pcs.dispo), vendu: pc(t.vendu), poubelle: pc(t.poubelle), invendu: pc(t.invendu), nonExpl: pc(t.nonExpl),
    venduE: fE(L.eur.vendu, 0), poubelleE: poubNulle ? 'rien déclaré' : fE(L.eur.poubelle, 0), invenduE: fE(L.eur.invendu, 0), nonExplE: fE(L.eur.nonExpl, 0), nonExplPcs: nf(L.pcs.nonExpl),
    tonP: poubNulle ? 'att' : (tons.poubelle || 'mu'), tonI: tons.invendu || 'mu', tonN: tons.nonExpl || 'mu',
    barre: [t.vendu, t.poubelle, t.invendu, t.nonExpl].map(v => Math.max(0, v || 0)),
    mesure: mesure == null ? '—' : nf(mesure) + ' %', estime,
    cuissons: (L.cuissonsValidees || 0) + ' / ' + (L.cuissons || 0), clotures: L.clotures || 0, jours: L.jours || 0,
    verdict: L.verdict || { ton: 'mu', nom: '', pourquoi: '' },
    // Pour les fuites : le sorti (disponible − gardé pour demain), en pièces et au prix de vente, et le delta en % du sorti.
    garde0: nf(L.pcs.garde0), prod: nf(L.pcs.prod), invPcs: nf(L.pcs.invendu), sortiPcs: nf(L.pcs.dispo - L.pcs.invendu), venduPcs: nf(L.pcs.vendu), poubPcs: nf(L.pcs.poubelle),
    deltaPcs: nf(L.pcs.nonExpl), deltaE: fE(L.eur.nonExpl, 0),
    deltaPct: (L.eur.dispo - L.eur.invendu) > 0 ? pc(100 * L.eur.nonExpl / (L.eur.dispo - L.eur.invendu)) : '—',
    deltaTon: estime ? 'mu' : (() => { const s = L.eur.dispo - L.eur.invendu; const v = s > 0 ? 100 * L.eur.nonExpl / s : 0; return v <= 3 ? 'ok' : (v <= 6 ? 'att' : 'ko'); })(),
    vide: !(L.pcs.dispo > 0), nE: L.eur.nonExpl || 0,
  };
}

/** La barre de période, commune aux deux écrans. */
function barre(app, vue, date, cleVue, cleDate, d){
  return {
    vues: ['jour', 'semaine', 'mois'].map(v => ({ id: v, nom: v[0].toUpperCase() + v.slice(1), on: v === vue, choisir: () => app.setState({ [cleVue]: v }) })),
    periode: periode(vue, d || { date, du: date, au: date }),
    avant: () => app.setState({ [cleDate]: decale(vue, (d && d.du) || date, -1) }),
    apres: () => app.setState({ [cleDate]: decale(vue, (d && d.au) || date, 1) }),
    apresOff: (d && d.au ? d.au : date) >= (vue === 'jour' ? iso(new Date()) : hier()),
  };
}

export function valsVerifFlux(app, common){
  const S = app.state;
  const vue = S.vxVue || 'semaine', shop = S.vxShop || '';
  const date = S.vxDate || (vue === 'jour' ? iso(new Date()) : hier());
  const chemin = '/verification/flux?vue=' + vue + '&date=' + date + (shop ? '&shop=' + encodeURIComponent(shop) : '');
  charge(app, 'vxData', chemin, false);
  const b = S.vxData || {};
  const d = b.cle === chemin && b.d && !b.d.error ? b.d : null;
  if (d && !shop) { app._vxMags = (d.magasins || []).map(m => ({ id: m.id, nom: m.nom })); }
  const mags = app._vxMags || [];
  const v = {
    chargement: !!b.chargement && !d, relecture: !!b.chargement && !!d,
    erreur: b.d && b.d.error && b.cle === chemin ? 'Le serveur du cockpit n’a pas répondu : réessayez dans un instant.' : null,
    rafraichir: () => charge(app, 'vxData', chemin, true),
    barre: barre(app, vue, date, 'vxVue', 'vxDate', d), vue, shop,
    magasins: [{ id: '', nom: 'Tout le réseau', on: !shop }].concat(mags.map(m => ({ id: m.id, nom: m.nom, on: m.id === shop }))),
    choisirShop: id => app.setState({ vxShop: id }),
  };
  common.vx = v;
  if (!d) { return; }
  v.manquants = d.manquants || 0;
  v.reseau = lecture(d.reseau);
  v.lignesMag = (d.magasins || []).map(m => Object.assign({ id: m.id, nom: m.nom, ouvrir: () => app.setState({ vxShop: m.id }) }, lecture(m)));
  // Le non expliqué jour par jour (réseau, semaine ou mois) : une case par magasin et par jour.
  v.jours = (d.jours || []).map(j => { const x = jourDe(j); return { date: j, nom: JOURS[x.getDay()], jj: jjmm(j) }; });
  v.cases = (d.magasins || []).map(m => ({ nom: m.nom, total: lecture(m), cases: (m.parJour || []).map(pj => {
    const L = pj.calcule ? lecture(pj) : null;
    return L ? { txt: L.nonExpl, ton: L.estime ? 'mu' : L.tonN, estime: L.estime, ouvrir: () => app.setState({ vxShop: m.id, vxVue: 'jour', vxDate: pj.date }) } : { txt: '…', ton: 'mu', estime: true, ouvrir: null };
  }) }));
  // Un magasin : jour par jour (semaine, mois), produit par produit (jour), par catégorie.
  if (shop) {
    const m = (d.magasins || [])[0];
    v.mag = m ? Object.assign({ nom: m.nom }, lecture(m)) : null;
    v.parJour = m ? (m.parJour || []).map(pj => { const x = jourDe(pj.date); const L = pj.calcule ? lecture(pj) : null;
      return Object.assign({ nom: JOURS[x.getDay()] + ' ' + jjmm(pj.date), dimanche: x.getDay() === 0, calcule: !!pj.calcule, ouvrir: () => app.setState({ vxVue: 'jour', vxDate: pj.date }) }, L || {}); }) : [];
    v.categories = (d.categories || []).map(c => Object.assign({ nom: c.cat, groupe: c.groupe }, lecture(c)));
    if (vue === 'jour') {
      v.journee = d.journee;
      v.produits = (d.produits || []).map(p => Object.assign({ nom: p.nom, cat: p.cat, prix: p.prix == null ? '' : nf(p.prix, 2) + ' €', mesureP: p.mesure, compte: p.compte }, lecture(p)));
    }
  }
}

export function valsVerifFuites(app, common){
  const S = app.state;
  const vue = S.vfuVue || 'semaine';
  const date = S.vfuDate || (vue === 'jour' ? iso(new Date()) : hier());
  const chemin = '/verification/fuites?vue=' + vue + '&date=' + date;
  charge(app, 'vfuData', chemin, false);
  const b = S.vfuData || {};
  const d = b.cle === chemin && b.d && !b.d.error ? b.d : null;
  const ouverts = S.vfuOuverts || {};
  const v = {
    chargement: !!b.chargement && !d, relecture: !!b.chargement && !!d,
    erreur: b.d && b.d.error && b.cle === chemin ? 'Le serveur du cockpit n’a pas répondu : réessayez dans un instant.' : null,
    rafraichir: () => charge(app, 'vfuData', chemin, true),
    barre: barre(app, vue, date, 'vfuVue', 'vfuDate', d), vue,
  };
  common.vfu = v;
  if (!d) { return; }
  v.manquants = d.manquants || 0;
  v.reseau = lecture(d.reseau);
  const mags = d.magasins || [];
  v.entetes = mags.map(m => m.nom);
  v.kpis = mags.map(m => Object.assign({ nom: m.nom }, lecture(m)));
  v.matrice = (d.matrice || []).map(r => ({ cat: r.cat, cases: mags.map(m => lecture((r.magasins || {})[m.id]) || { vide: true }), reseau: lecture(r.reseau) }));
  v.tables = mags.map(m => {
    const L = lecture(m);
    const cats = (m.categories || []).map(c => {
      const k = m.id + '|' + c.cat, ouvert = !!ouverts[k];
      return Object.assign({ nom: c.cat, n: (c.produits || []).length, ouvert, basculer: () => app.setState({ vfuOuverts: Object.assign({}, ouverts, { [k]: !ouvert }) }),
        produits: ouvert ? (c.produits || []).map(p => Object.assign({ nom: p.nom, prix: p.prix == null ? '' : nf(p.prix, 2) + ' €' }, lecture(p))) : [] }, lecture(c));
    });
    const pire = cats.filter(c => !c.vide && c.nE > 0).slice().sort((a, b2) => b2.nE - a.nE);
    return Object.assign({ id: m.id, nom: m.nom, categories: cats, pire: pire.slice(0, 3).map(c => c.nom + ' (' + c.deltaE + ')') }, L);
  });
}
